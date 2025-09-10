<?php
// 批量上传 ./storage/textures 下的 PNG 到本地接口，实时输出进度
error_reporting(E_ALL);
ini_set('display_errors', '1');
@ob_end_flush();
ob_implicit_flush(true);

$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'textures';

// 远程接口配置 - 请根据实际情况修改
$url = 'https://skin.ustb.world/skinuploader11451.php';  // 远程服务器地址
// 或者本地测试：
//$url = 'http://127.0.0.1:8765/skinuploader11451.php';

if (!is_dir($baseDir)) {
    fwrite(STDERR, "目录不存在: {$baseDir}\n");
    exit(1);
}

// 收集所有 .png（大小写不敏感）
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
$files = [];
foreach ($rii as $file) {
    if ($file->isDir()) continue;
    $ext = strtolower(pathinfo($file->getPathname(), PATHINFO_EXTENSION));
    if ($ext === 'png') $files[] = $file->getPathname();
}

if (!$files) {
    echo "未找到 PNG 文件\n";
    exit(0);
}

$summary = [
    'total' => count($files),
    'success' => 0,
    'failed' => 0,
    'results' => []
];

$total = $summary['total'];
echo "发现 PNG 文件数量: {$total}\n";
$index = 0;

foreach ($files as $path) {
    $index++;
    $ch = curl_init();
    $cfile = new CURLFile($path, 'image/png', basename($path));
    $post = ['images' => $cfile];
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 30,  // 远程连接超时30秒
        CURLOPT_TIMEOUT => 120,        // 远程请求超时2分钟
        CURLOPT_FOLLOWLOCATION => true, // 跟随重定向
        CURLOPT_SSL_VERIFYPEER => false, // 如需要HTTPS但证书有问题
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $record = [
        'file' => $path,
        'http' => $status,
    ];

    if ($err) {
        $record['success'] = false;
        $record['error'] = $err;
        $summary['failed']++;
    } else {
        $record['raw'] = $resp;
        $json = json_decode($resp, true);
        if (is_array($json)) {
            $record['success'] = !empty($json['success']);
            // 检查是否是"文件已存在"的情况，如果是则视为成功
            if (!$record['success'] && isset($json['data']['results'][0]['error'])) {
                $error = $json['data']['results'][0]['error'];
                if (strpos($error, '相同的文件已经存在') !== false) {
                    $record['success'] = true;
                    $record['skipped'] = true; // 标记为跳过
                }
            }
            if ($record['success']) $summary['success']++; else $summary['failed']++;
            $record['message'] = $json['message'] ?? null;
            $record['data'] = $json['data'] ?? null;
        } else {
            // 非 JSON 响应
            $record['success'] = false;
            $record['error'] = '非 JSON 响应';
            $summary['failed']++;
        }
    }

    $summary['results'][] = $record;

    // 进度输出与中间结果落盘
    if ($index === 1 || $index % 100 === 0 || $index === $total) {
        echo "进度: {$index}/{$total} (成功: {$summary['success']}, 失败: {$summary['failed']})\n";
        $partial = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'upload_summary.json', $partial);
    }
}

header('Content-Type: application/json; charset=utf-8');
$out = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'upload_summary.json', $out);
echo $out;



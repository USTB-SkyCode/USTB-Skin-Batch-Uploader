<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

const UPLOADER_ID = 1;
const UPLOAD_DIR = '../storage/textures/';
const MAX_FILE_SIZE = 5 * 1024 * 1024;

// 远程数据库配置 - 请根据实际情况修改
//const DB_HOST = '49.232.143.161';  // 远程数据库主机地址
//const DB_USER = 'm1kk';     // 远程数据库用户名
//const DB_PASS = 'm1kk';     // 远程数据库密码
//const DB_NAME = 'blessingskin';              // 数据库名
//const DB_PORT = 3306;               // 数据库端口（默认3306）

//本地测试
const DB_HOST = 'localhost';  // 远程数据库主机地址
const DB_USER = 'root';     // 远程数据库用户名
const DB_PASS = 'Angie0317';     // 远程数据库密码
const DB_NAME = 'test';              // 数据库名
const DB_PORT = 3306;               // 数据库端口（默认3306）



// 诊断开关：GET /skinapi1.php?diag=1 返回运行环境信息
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['diag'])) {
    echo json_encode([
        'php_version' => PHP_VERSION,
        'extensions' => [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mysqli' => extension_loaded('mysqli'),
            'curl' => extension_loaded('curl'),
            'fileinfo' => extension_loaded('fileinfo'),
            'gd' => extension_loaded('gd')
        ],
        'pdo_drivers' => class_exists('PDO') ? PDO::getAvailableDrivers() : [],
        'mysqli_client_info' => function_exists('mysqli_get_client_info') ? mysqli_get_client_info() : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


// Alex模型检测算法
function isAlex($filePath){
	// 检查右臂外侧特定区域(46,52)-(48,64)是否透明
	$image = imagecreatefrompng($filePath);
	if (!$image) {
		return 'steve';
	}
	$ratio = calculateSkinRatio($filePath);
	$xStart = (int) floor(46 * $ratio);
	$xEnd = (int) ceil(48 * $ratio);
	$yStart = (int) floor(52 * $ratio);
	$yEnd = (int) ceil(64 * $ratio);
	for ($x = $xStart; $x < $xEnd; $x++) {
		for ($y = $yStart; $y < $yEnd; $y++) {
			if (!isPixelTransparent($image, $x, $y)) {
				imagedestroy($image);
				return 'steve'; // 非透明，认为是 Steve
			}
		}
	}
	imagedestroy($image);
	return 'alex'; // 全透明，认为是 Alex
}

// 判断单个像素是否透明（GD alpha 0=不透明，127=全透明）
function isPixelTransparent($image, $x, $y){
	$colorIndex = imagecolorat($image, $x, $y);
	$rgba = imagecolorsforindex($image, $colorIndex);
	return isset($rgba['alpha']) && $rgba['alpha'] >= 127;
}

function calculateSkinRatio($filePath){
    $imageInfo = getimagesize($filePath);
    if (!$imageInfo) return 1.0;
    $width = $imageInfo[0];
    $height = $imageInfo[1];
    // 默认参考原始 Minecraft 皮肤尺寸 64x64
    $baseSize = 64;
    // 取最小比例，保证坐标不越界
    $ratio = min($width / $baseSize, $height / $baseSize);
    return $ratio;
}

function connectDatabase() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 30,  // 连接超时30秒
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        jsonResponse(false, '数据库连接失败: ' . $e->getMessage());
    }
}

function generateHash($filePath) {
    return hash('sha256', file_get_contents($filePath));
}

function parseFileName($fileName) {
    $nameWithoutExt = pathinfo($fileName, PATHINFO_FILENAME);
    if (preg_match('/^(.+)_(alex|steve)$/i', $nameWithoutExt, $matches)) {
        return [
            'name' => $matches[1],
            'type' => strtolower($matches[2])
        ];
    }
    return false;
}

function validateFile($file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return '文件上传失败，错误码: ' . $file['error'];
    }
    
    if ($file['size'] > MAX_FILE_SIZE) {
        return '文件大小超出限制(5MB)';
    }
    
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($fileExt !== 'png') {
        return '只支持PNG格式的图片文件';
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($mimeType !== 'image/png') {
        return '文件不是有效的PNG图片';
    }
    

    $parsed = parseFileName($file['name']);
    if (!$parsed) {
        return '文件名格式不正确，应为: name_alex.png 或 name_steve.png';
    }
    
    return true;
}

function processFile($file, $pdo) {
    $filePath = $file['tmp_name'];
    // 将模型后缀插入到扩展名前，保留原始扩展名
    $suffix = isAlex($filePath);
    $pi = pathinfo($file['name']);
    $base = isset($pi['filename']) ? $pi['filename'] : $file['name'];
    $ext = isset($pi['extension']) && $pi['extension'] !== '' ? ('.'.$pi['extension']) : '';
    $file['name'] = $base.'_'.$suffix.$ext;
    $validation = validateFile($file);
    if ($validation !== true) {
        return [
            'success' => false,
            'filename' => $file['name'],
            'error' => $validation
        ];
    }
    
    $parsed = parseFileName($file['name']);
    $skinName = $parsed['name'];
    $skinType = $parsed['type'];
    
    $hash = generateHash($file['tmp_name']);
    
    if (!is_dir(UPLOAD_DIR)) {
        $created = mkdir(UPLOAD_DIR, 0755, true);
        if (!$created) {
            $error = error_get_last();
            return [
                'success' => false,
                'filename' => $file['name'],
                'error' => '无法创建上传目录: ' . UPLOAD_DIR . ' - ' . ($error ? $error['message'] : '权限不足')
            ];
        }
    }
    
    if (!is_writable(UPLOAD_DIR)) {
        return [
            'success' => false,
            'filename' => $file['name'],
            'error' => '上传目录不可写: ' . UPLOAD_DIR . ' - 当前权限: ' . substr(sprintf('%o', fileperms(UPLOAD_DIR)), -4)
        ];
    }
    
    $targetPath = UPLOAD_DIR . $hash;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [
            'success' => false,
            'filename' => $file['name'],
            'error' => '文件保存失败'
        ];
    }
    
    $sizeKB = intval($file['size'] / 1024);
    if ($sizeKB === 0) $sizeKB = 1;
    
    try {
        $checkStmt = $pdo->prepare("SELECT tid FROM textures WHERE hash = ?");
        $checkStmt->execute([$hash]);
        
        if ($checkStmt->rowCount() > 0) {
            unlink($targetPath);
            return [
                'success' => false,
                'filename' => $file['name'],
                'error' => '相同的文件已经存在'
            ];
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO textures (name, type, hash, size, uploader, public, upload_at, likes) 
            VALUES (?, ?, ?, ?, ?, 1, NOW(), 0)
        ");
        
        $stmt->execute([
            $skinName,
            $skinType,
            $hash,
            $sizeKB,
            UPLOADER_ID
        ]);
        
        return [
            'success' => true,
            'filename' => $file['name'],
            'tid' => $pdo->lastInsertId(),
            'hash' => $hash,
            'name' => $skinName,
            'type' => $skinType,
            'size' => $sizeKB
        ];
        
    } catch (PDOException $e) {
        if (file_exists($targetPath)) {
            unlink($targetPath);
        }
        
        return [
            'success' => false,
            'filename' => $file['name'],
            'error' => '数据库错误: ' . $e->getMessage()
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, '只支持POST请求');
}

if (empty($_FILES) || !isset($_FILES['images'])) {
    jsonResponse(false, '没有上传任何文件，请使用字段名 "images"');
}

$pdo = connectDatabase();

$results = [];
$successCount = 0;

$files = $_FILES['images'];

if (is_array($files['name'])) {
    $fileCount = count($files['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];
        
        $result = processFile($file, $pdo);
        $results[] = $result;
        
        if ($result['success']) {
            $successCount++;
        }
    }
} else {
    $result = processFile($files, $pdo);
    $results[] = $result;
    
    if ($result['success']) {
        $successCount++;
    }
}

$totalFiles = count($results);
$message = "处理完成：成功 {$successCount}/{$totalFiles} 个文件";

jsonResponse(
    $successCount > 0,
    $message,
    [
        'total' => $totalFiles,
        'success' => $successCount,
        'failed' => $totalFiles - $successCount,
        'results' => $results
    ]
);
?>

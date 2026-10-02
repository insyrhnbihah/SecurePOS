<?php
// CLI only; no database writes, secret output or production file required.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
putenv('SECUREPOS_ENV=local');
putenv('SECUREPOS_BASE_URL');
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/face_biometrics.php';
require_once dirname(__DIR__) . '/config/leave.php';

$checks = 0;
function checkDeployment(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
    $checks++;
}

$_SERVER = ['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/SecurePOS/attendance_kiosk_qr.php', 'SERVER_PORT' => '80'];
checkDeployment(secureposBaseUrl() === 'http://localhost/SecurePOS', 'local subfolder URL');
$_SERVER['HTTP_HOST'] = '192.168.1.20:8080';
checkDeployment(secureposBaseUrl() === 'http://192.168.1.20:8080/SecurePOS', 'LAN and port URL');
$_SERVER = ['HTTP_HOST' => 'shop.example.test', 'SCRIPT_NAME' => '/attendance_kiosk_qr.php', 'HTTPS' => 'on'];
checkDeployment(secureposBaseUrl() === 'https://shop.example.test', 'HTTPS domain root URL');
$_SERVER['SCRIPT_NAME'] = '/SecurePOS/attendance_scan.php';
checkDeployment(secureposBaseUrl() === 'https://shop.example.test/SecurePOS', 'HTTPS subfolder URL');
$_SERVER = ['HTTP_HOST' => 'localhost', 'SCRIPT_NAME' => '/SecurePOS/index.php',
    'HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '203.0.113.10'];
checkDeployment(!secureposRequestIsHttps(), 'untrusted forwarded header rejected');
$_SERVER['REMOTE_ADDR'] = $_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_CF_VISITOR'] = '{"scheme":"https"}';
checkDeployment(secureposRequestIsHttps(), 'existing loopback tunnel preserved');
putenv('SECUREPOS_BASE_URL=https://canonical.example.test/store/');
checkDeployment(secureposBaseUrl() === 'https://canonical.example.test/store', 'canonical QR URL override');
$production = require dirname(__DIR__) . '/config/production.example.php';
checkDeployment($production['db_host'] === 'sql301.infinityfree.com'
    && $production['db_port'] === 3306
    && $production['db_name'] === 'if0_43067047_securepos'
    && $production['db_user'] === 'if0_43067047', 'InfinityFree database template');
checkDeployment($production['db_password'] === 'REPLACE_WITH_MYSQL_PASSWORD', 'password remains a placeholder');
checkDeployment($production['base_url'] === 'https://securepos.xo.je', 'InfinityFree root URL template');
putenv('SECUREPOS_BASE_URL=' . $production['base_url']);
checkDeployment(secureposBaseUrl() . '/attendance_scan.php?token=test'
    === 'https://securepos.xo.je/attendance_scan.php?token=test', 'InfinityFree QR destination');
checkDeployment($production['leave_storage'] === dirname(__DIR__) . '/storage/private/leave_documents',
    'production document path is relative to application directory');
putenv('SECUREPOS_BASE_URL');
checkDeployment(date_default_timezone_get() === 'Asia/Kuala_Lumpur', 'Malaysia timezone');
checkDeployment(is_dir(SECUREPOS_LEAVE_STORAGE), 'existing local document storage retained');
try {
    leaveStoragePath('../invalid.pdf');
    checkDeployment(false, 'unsafe filename rejected');
} catch (RuntimeException $exception) {
    checkDeployment(true, 'unsafe filename rejected');
}
$key = random_bytes(32);
$descriptor = array_fill(0, 128, 0.125);
$encrypted = encryptFaceDescriptor($descriptor, $key);
checkDeployment(decryptFaceDescriptor($encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'], $key) === $descriptor,
    'face encryption round trip');
putenv('SECUREPOS_FACE_TEMPLATE_KEY=' . base64_encode($key));
checkDeployment(secureposFaceEncryptionKey() === $key, 'existing environment key supported');
putenv('SECUREPOS_FACE_TEMPLATE_KEY=invalid');
checkDeployment(secureposFaceEncryptionKey() === null, 'invalid encryption key rejected');
putenv('SECUREPOS_FACE_TEMPLATE_KEY');
echo 'PASS: ' . $checks . ' configuration checks; no database writes.' . PHP_EOL;

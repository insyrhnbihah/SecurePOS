<?php
// CLI-only read-only page rendering harness, used by smoke_test.py.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$allowed = ['login.php' => '', 'forgot_password.php' => '', 'dashboard.php' => 'Manager',
    'pos.php' => 'Cashier', 'inventory.php' => 'Inventory Staff',
    'inventory_availability.php' => 'Cashier', 'product_expiry.php' => 'Inventory Staff',
    'attendance.php' => 'Manager', 'users.php' => 'Manager', 'reports.php' => 'Manager',
    'audit_logs.php' => 'Manager', 'transaction_history.php' => 'Cashier',
    'leave.php' => 'Cashier', 'change_password.php' => 'Cashier'];
$page = $argv[1] ?? '';
if (!array_key_exists($page, $allowed)) {
    exit(2);
}
putenv('SECUREPOS_ENV=local');
putenv('SECUREPOS_BASE_URL');
$_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost',
    'SCRIPT_NAME' => '/SecurePOS/' . $page, 'SERVER_ADDR' => '127.0.0.1',
    'REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => '80'];
$_GET = $_POST = [];
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/auth.php';
$sessionDirectory = dirname(__DIR__) . '/tmp/deployment_sessions';
if (!is_dir($sessionDirectory)) {
    mkdir($sessionDirectory, 0700, true);
}
session_save_path($sessionDirectory);
startSecureSession();
register_shutdown_function(static function () {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
});
if ($allowed[$page] !== '') {
    $stmt = $mysqli->prepare("SELECT id, full_name, role FROM users WHERE role = ? AND account_status = 'Active' AND force_password_change = 0 LIMIT 1");
    $role = $allowed[$page];
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        fwrite(STDERR, 'No eligible local test account for role.' . PHP_EOL);
        exit(3);
    }
    $_SESSION = ['authenticated' => true, 'user_id' => (int)$user['id'],
        'full_name' => $user['full_name'], 'role' => $user['role']];
}
require dirname(__DIR__) . '/' . $page;

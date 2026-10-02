<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';

startSecureSession();

if (isAuthenticated()) {
    auditLog(
        $mysqli,
        (int)$_SESSION['user_id'],
        currentUserName(),
        currentUserRole(),
        'Authentication',
        'Logout',
        'Success',
        'User logged out.'
    );
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header('Location: login.php');
exit;

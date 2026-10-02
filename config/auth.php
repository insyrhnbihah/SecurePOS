<?php
require_once __DIR__ . '/bootstrap.php';

function startSecureSession()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = secureposRequestIsHttps();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isAuthenticated()
{
    if (!isset($_SESSION['authenticated'], $_SESSION['user_id'], $_SESSION['role'])
        || $_SESSION['authenticated'] !== true) {
        return false;
    }

    $userId = (int)$_SESSION['user_id'];
    $sessionRole = (string)$_SESSION['role'];
    if ($userId < 1 || $sessionRole === '') {
        invalidateAuthenticatedSession();
        return false;
    }

    static $validatedIdentity = null;
    $identityKey = $userId . '|' . $sessionRole;
    if ($validatedIdentity === $identityKey) {
        return true;
    }

    try {
        global $mysqli;
        if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
            require_once __DIR__ . '/database.php';
        }

        $stmt = $mysqli->prepare('SELECT role, account_status, force_password_change FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            invalidateAuthenticatedSession();
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user
            || $user['account_status'] !== 'Active'
            || !hash_equals((string)$user['role'], $sessionRole)) {
            invalidateAuthenticatedSession();
            return false;
        }

        $_SESSION['force_password_change'] = (int)$user['force_password_change'] === 1;
    } catch (Throwable $exception) {
        invalidateAuthenticatedSession();
        return false;
    }

    $validatedIdentity = $identityKey;
    enforcePasswordChangeRestriction();
    return true;
}

function enforcePasswordChangeRestriction()
{
    if (empty($_SESSION['force_password_change'])) {
        return;
    }

    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['login.php', 'face_login.php', 'change_password.php', 'logout.php'], true)) {
        return;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($accept, 'application/json') !== false || strpos($contentType, 'application/json') !== false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Password change required.',
            'redirect' => 'change_password.php',
        ]);
        exit;
    }

    header('Location: change_password.php');
    exit;
}

function invalidateAuthenticatedSession()
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function requireManager()
{
    startSecureSession();

    if (!isAuthenticated() || ($_SESSION['role'] ?? '') !== 'Manager') {
        header('Location: login.php');
        exit;
    }
}

function requireInventoryAccess()
{
    startSecureSession();

    $role = strtolower(trim((string)($_SESSION['role'] ?? '')));
    if (!isAuthenticated() || !in_array($role, ['manager', 'inventory staff'], true)) {
        header('Location: login.php');
        exit;
    }
}

function requireCashier()
{
    startSecureSession();

    if (!isAuthenticated() || ($_SESSION['role'] ?? '') !== 'Cashier') {
        header('Location: login.php');
        exit;
    }
}

function currentUserName()
{
    return (string)($_SESSION['full_name'] ?? '');
}

function currentUserRole()
{
    return (string)($_SESSION['role'] ?? '');
}

function currentUserInitials()
{
    $name = trim(currentUserName());
    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $last, 'UTF-8');
}

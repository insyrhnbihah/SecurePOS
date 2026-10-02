<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/face_biometrics.php';

startSecureSession();
header('Content-Type: application/json');

function faceLoginResponse(bool $success, string $message, ?string $redirect = null): void
{
    $response = ['success' => $success, 'message' => $message];
    if ($redirect !== null) {
        $response['redirect'] = $redirect;
    }
    echo json_encode($response);
    exit;
}

function rejectFaceLogin(mysqli $mysqli): void
{
    auditLog(
        $mysqli,
        null,
        null,
        null,
        'Authentication',
        'Face Login',
        'Rejected',
        'Face verification login rejected.'
    );
    http_response_code(401);
    faceLoginResponse(false, 'Face verification failed. Please try again or use your password.');
}

function faceLoginRedirectForRole(string $role): ?string
{
    $normalized = strtolower(trim($role));
    if ($normalized === 'manager') {
        return 'dashboard.php';
    }
    if ($normalized === 'cashier') {
        return 'pos.php';
    }
    if ($normalized === 'inventory staff') {
        return 'inventory.php';
    }
    return null;
}

function consumeFaceAttendanceRedirect(): string
{
    $destination = (string)($_SESSION['post_login_redirect'] ?? '');
    unset($_SESSION['post_login_redirect']);
    return preg_match('/\Aattendance_scan\.php\?token=[a-f0-9]{64}\z/D', $destination) ? $destination : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    faceLoginResponse(false, 'Face verification failed. Please try again or use your password.');
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    rejectFaceLogin($mysqli);
}

$sessionCsrf = $_SESSION['face_login_csrf'] ?? null;
$submittedCsrf = $payload['csrfToken'] ?? null;
if (!is_string($sessionCsrf) || !is_string($submittedCsrf) || !hash_equals($sessionCsrf, $submittedCsrf)) {
    rejectFaceLogin($mysqli);
}

$email = strtolower(trim((string)($payload['email'] ?? '')));
$submittedDescriptor = validateFaceDescriptor($payload['descriptor'] ?? null);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100 || $submittedDescriptor === null) {
    rejectFaceLogin($mysqli);
}

try {
    $stmt = $mysqli->prepare(
        'SELECT u.id, u.full_name, u.role, u.account_status, u.force_password_change,
                uft.descriptor_ciphertext, uft.encryption_iv, uft.authentication_tag, uft.model_version
         FROM users u
         LEFT JOIN user_face_templates uft ON uft.user_id = u.id
         WHERE u.email = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare face login.');
    }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || $user['account_status'] !== 'Active'
        || !in_array($user['role'], ['Manager', 'Cashier', 'Inventory Staff'], true)
        || $user['descriptor_ciphertext'] === null
        || $user['model_version'] !== SECUREPOS_FACE_MODEL_VERSION) {
        rejectFaceLogin($mysqli);
    }

    $key = secureposFaceEncryptionKey();
    if ($key === null) {
        throw new RuntimeException('Face template encryption is not configured.');
    }
    $storedDescriptor = validateFaceDescriptor(decryptFaceDescriptor(
        $user['descriptor_ciphertext'],
        $user['encryption_iv'],
        $user['authentication_tag'],
        $key
    ));
    if ($storedDescriptor === null) {
        throw new RuntimeException('Stored face template is invalid.');
    }

    $distance = faceDescriptorDistance($submittedDescriptor, $storedDescriptor);
    if ($distance > SECUREPOS_FACE_DISTANCE_THRESHOLD) {
        rejectFaceLogin($mysqli);
    }

    $attendanceRedirect = consumeFaceAttendanceRedirect();
    $roleRedirect = faceLoginRedirectForRole($user['role']);
    if ($roleRedirect === null) {
        rejectFaceLogin($mysqli);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['authenticated'] = true;
    $_SESSION['force_password_change'] = (int)$user['force_password_change'] === 1;
    unset($_SESSION['face_login_csrf']);

    auditLog(
        $mysqli,
        (int)$user['id'],
        $user['full_name'],
        $user['role'],
        'Authentication',
        'Face Login',
        'Success',
        'User logged in successfully using face verification.'
    );
    $redirect = !empty($_SESSION['force_password_change'])
        ? 'change_password.php'
        : ($attendanceRedirect !== '' ? $attendanceRedirect : $roleRedirect);
    faceLoginResponse(true, 'Face verification successful.', $redirect);
} catch (Throwable $exception) {
    error_log('Face login failed: ' . $exception->getMessage());
    rejectFaceLogin($mysqli);
}

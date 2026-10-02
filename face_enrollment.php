<?php
require_once __DIR__ . '/config/auth.php';
requireManager();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/face_biometrics.php';

header('Content-Type: application/json');

function enrollmentResponse(bool $success, string $message): void
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    enrollmentResponse(false, 'Unable to process face enrollment.');
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    enrollmentResponse(false, 'Unable to process face enrollment.');
}

$sessionCsrf = $_SESSION['users_csrf'] ?? null;
$submittedCsrf = $payload['csrfToken'] ?? null;
if (!is_string($sessionCsrf) || !is_string($submittedCsrf) || !hash_equals($sessionCsrf, $submittedCsrf)) {
    enrollmentResponse(false, 'Unable to process face enrollment.');
}

$userId = filter_var($payload['userId'] ?? null, FILTER_VALIDATE_INT);
$descriptor = $payload['descriptor'] ?? null;
if ($userId === false || $userId < 1 || !is_array($descriptor) || count($descriptor) !== 128) {
    enrollmentResponse(false, 'Unable to process face enrollment.');
}

$validatedDescriptor = [];
foreach ($descriptor as $value) {
    if (!is_int($value) && !is_float($value)) {
        enrollmentResponse(false, 'Unable to process face enrollment.');
    }
    $number = (float)$value;
    if (!is_finite($number) || abs($number) > 10) {
        enrollmentResponse(false, 'Unable to process face enrollment.');
    }
    $validatedDescriptor[] = $number;
}

$encryptionKey = secureposFaceEncryptionKey();
if ($encryptionKey === null) {
    enrollmentResponse(false, 'Face enrollment is not configured.');
}

try {
    $mysqli->begin_transaction();
    $targetStmt = $mysqli->prepare(
        'SELECT u.full_name, u.role, u.account_status, e.employee_code
         FROM users u
         LEFT JOIN employees e ON e.user_id = u.id
         WHERE u.id = ? LIMIT 1 FOR UPDATE'
    );
    if (!$targetStmt) {
        throw new RuntimeException('Unable to load user.');
    }
    $targetStmt->bind_param('i', $userId);
    $targetStmt->execute();
    $target = $targetStmt->get_result()->fetch_assoc();
    $targetStmt->close();
    if (!$target || $target['account_status'] !== 'Active'
        || !in_array($target['role'], ['Manager', 'Cashier', 'Inventory Staff'], true)) {
        throw new DomainException('The selected active user is unavailable.');
    }

    $encrypted = encryptFaceDescriptor($validatedDescriptor, $encryptionKey);
    $managerUserId = (int)$_SESSION['user_id'];
    $modelVersion = SECUREPOS_FACE_MODEL_VERSION;
    $saveStmt = $mysqli->prepare(
        'INSERT INTO user_face_templates
            (user_id, descriptor_ciphertext, encryption_iv, authentication_tag, model_version, enrolled_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            descriptor_ciphertext = VALUES(descriptor_ciphertext),
            encryption_iv = VALUES(encryption_iv),
            authentication_tag = VALUES(authentication_tag),
            model_version = VALUES(model_version),
            enrolled_by_user_id = VALUES(enrolled_by_user_id),
            updated_at = CURRENT_TIMESTAMP'
    );
    if (!$saveStmt) {
        throw new RuntimeException('Unable to prepare face enrollment.');
    }
    $saveStmt->bind_param(
        'issssi',
        $userId,
        $encrypted['ciphertext'],
        $encrypted['iv'],
        $encrypted['tag'],
        $modelVersion,
        $managerUserId
    );
    if (!$saveStmt->execute()) {
        throw new RuntimeException('Unable to save face enrollment.');
    }
    $saveStmt->close();
    $mysqli->commit();

    $label = $target['full_name'];
    if (!empty($target['employee_code'])) {
        $label .= ' (' . $target['employee_code'] . ')';
    }
    auditLog(
        $mysqli,
        $managerUserId,
        currentUserName(),
        currentUserRole(),
        'Users',
        'Enroll Face',
        'Success',
        'Face verification enrolled for ' . $label . '.'
    );
    enrollmentResponse(true, 'Face verification enrolled successfully.');
} catch (DomainException $exception) {
    @$mysqli->rollback();
    enrollmentResponse(false, 'The selected active user is unavailable.');
} catch (Throwable $exception) {
    @$mysqli->rollback();
    error_log('Face enrollment failed: ' . $exception->getMessage());
    enrollmentResponse(false, 'Unable to save face enrollment. Please try again.');
}

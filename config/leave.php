<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

define('SECUREPOS_LEAVE_STORAGE', (string)secureposSetting('leave_storage', dirname(__DIR__) . '/storage/private/leave_documents'));
const SECUREPOS_LEAVE_MAX_FILE_SIZE = 5242880;

function leaveTimezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Kuala_Lumpur');
}

function requireLeaveEmployee(mysqli $mysqli): array
{
    startSecureSession();
    if (!isAuthenticated() || !in_array(currentUserRole(), ['Cashier', 'Inventory Staff'], true)) {
        header('Location: login.php');
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $stmt = $mysqli->prepare("SELECT e.id, e.employee_code, e.full_name, e.role FROM employees e INNER JOIN users u ON u.id = e.user_id WHERE u.id = ? AND u.account_status = 'Active' AND e.account_status = 'Active' AND u.role = e.role AND e.role IN ('Cashier', 'Inventory Staff') LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$employee) {
        http_response_code(403);
        exit('An active employee profile is required.');
    }
    return $employee;
}

function parseLeaveDate(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, leaveTimezone());
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
        throw new DomainException($label . ' is invalid.');
    }
    return $value;
}

function validateLeaveUpload(array $file, bool $required): ?array
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new DomainException('A supporting document is required for Medical Leave.');
        }
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE ? 'The supporting document exceeds the 5 MB limit.' : 'The supporting document upload failed.');
    }
    $temporaryPath = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || $size < 1 || $size > SECUREPOS_LEAVE_MAX_FILE_SIZE) {
        throw new DomainException('The supporting document is invalid or exceeds the 5 MB limit.');
    }

    $original = basename(str_replace('\\', '/', (string)($file['name'] ?? '')));
    if ($original === '' || strpos($original, "\0") !== false || strlen($original) > 255) {
        throw new DomainException('The supporting document filename is invalid.');
    }
    $extension = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    $stem = (string)pathinfo($original, PATHINFO_FILENAME);
    if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true) || $stem === '' || strpos($stem, '.') !== false) {
        throw new DomainException('Use a single-extension PDF, JPG, JPEG, or PNG file.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($temporaryPath);
    $allowed = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];
    if (!in_array($mime, $allowed[$extension], true)) {
        throw new DomainException('The document extension does not match its verified file type.');
    }
    $safeOriginal = preg_replace('/[^A-Za-z0-9 _.-]/', '_', $original);
    $stored = bin2hex(random_bytes(32)) . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
    return ['temporary_path' => $temporaryPath, 'stored_name' => $stored, 'original_name' => $safeOriginal, 'mime_type' => $mime, 'size' => $size];
}

function leaveStoragePath(string $storedName): string
{
    if (!preg_match('/\A[a-f0-9]{64}\.(pdf|jpg|png)\z/D', $storedName)) {
        throw new RuntimeException('Invalid stored document name.');
    }
    return rtrim(SECUREPOS_LEAVE_STORAGE, '/\\') . DIRECTORY_SEPARATOR . $storedName;
}

function leaveStatusClass(string $status): string
{
    return strtolower($status);
}

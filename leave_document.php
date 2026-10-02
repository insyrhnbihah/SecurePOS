<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/leave.php';

startSecureSession();
if (!isAuthenticated()) {
    header('Location: login.php');
    exit;
}
$requestId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($requestId === false || $requestId < 1) {
    http_response_code(404);
    exit('Document not found.');
}
$stmt = $mysqli->prepare('SELECT lr.id, lr.employee_id, lr.leave_type, lr.start_date, lr.end_date, lr.attachment_stored_name, lr.attachment_original_name, lr.attachment_mime_type, lr.attachment_size, e.user_id, e.employee_code, e.role AS employee_role, e.account_status AS employee_status FROM leave_requests lr INNER JOIN employees e ON e.id = lr.employee_id WHERE lr.id = ? LIMIT 1');
$stmt->bind_param('i', $requestId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc();
$stmt->close();
$authorized = $document && $document['attachment_stored_name'] && (currentUserRole() === 'Manager' || ((int)$document['user_id'] === (int)$_SESSION['user_id'] && $document['employee_status'] === 'Active' && $document['employee_role'] === currentUserRole() && in_array(currentUserRole(), ['Cashier', 'Inventory Staff'], true)));
if (!$authorized) {
    auditLog($mysqli, (int)$_SESSION['user_id'], currentUserName(), currentUserRole(), 'Attendance', 'Leave Document Access Denied', 'Rejected', 'Unauthorized or unavailable leave document request #' . (int)$requestId . '.');
    http_response_code(403);
    exit('Document access denied.');
}
try {
    $path = leaveStoragePath($document['attachment_stored_name']);
} catch (Throwable $exception) {
    http_response_code(404);
    exit('Document not found.');
}
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Document not found.');
}
$downloadName = str_replace(["\r", "\n", '"'], '_', basename($document['attachment_original_name']));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $document['attachment_mime_type']);
header('Content-Length: ' . (string)filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
readfile($path);
exit;

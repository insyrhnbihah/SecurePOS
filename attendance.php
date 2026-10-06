<?php
require_once __DIR__ . '/config/auth.php';
requireManager();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/attendance_qr.php';
require_once __DIR__ . '/config/leave.php';
require_once __DIR__ . '/config/expiry_notifications.php';

$notifications = getExpiryNotifications($mysqli);
$timezone = new DateTimeZone('Asia/Kuala_Lumpur');
$today = new DateTimeImmutable('today', $timezone);
$todayString = $today->format('Y-m-d');

if (empty($_SESSION['attendance_admin_csrf'])) {
    $_SESSION['attendance_admin_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['attendance_admin_csrf'], $submittedCsrf)) {
        http_response_code(403);
        die('Invalid request token.');
    }
    $action = (string)($_POST['action'] ?? '');
    $now = new DateTimeImmutable('now', $timezone);
    $nowSql = $now->format('Y-m-d H:i:s');
    if ($action === 'create_kiosk_pairing') {
        $code = bin2hex(random_bytes(16));
        $hash = hash('sha256', $code);
        $expires = $now->modify('+' . SECUREPOS_KIOSK_PAIRING_LIFETIME_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        $managerId = (int)$_SESSION['user_id'];
        $stmt = $mysqli->prepare('INSERT INTO attendance_kiosk_pairings (code_hash, expires_at, created_by_user_id) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $hash, $expires, $managerId);
        $stmt->execute();
        $stmt->close();
        attendanceAudit($mysqli, null, null, 'KIOSK_PAIRING', 'Success', 'Manager created one-time pairing code');
        $_SESSION['attendance_pairing_code'] = ['code' => $code, 'expires' => $expires];
    } elseif ($action === 'revoke_kiosk') {
        $kioskId = filter_var($_POST['kiosk_id'] ?? null, FILTER_VALIDATE_INT);
        if ($kioskId !== false && $kioskId > 0) {
            $stmt = $mysqli->prepare('UPDATE attendance_kiosk_sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL');
            $stmt->bind_param('si', $nowSql, $kioskId);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                attendanceAudit($mysqli, null, null, 'KIOSK_REVOKE', 'Success', 'Manager revoked kiosk session');
            }
            $stmt->close();
        }
    } elseif (in_array($action, ['approve_leave', 'reject_leave'], true)) {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));
        if ($requestId === false || $requestId < 1 || ($action === 'reject_leave' && ($rejectionReason === '' || mb_strlen($rejectionReason, 'UTF-8') > 1000))) {
            $_SESSION['attendance_error'] = $action === 'reject_leave' ? 'A rejection reason of no more than 1000 characters is required.' : 'Invalid leave request.';
        } else {
            $mysqli->begin_transaction();
            try {
                $requestStmt = $mysqli->prepare("SELECT lr.status, lr.leave_type, lr.start_date, lr.end_date, e.employee_code FROM leave_requests lr INNER JOIN employees e ON e.id = lr.employee_id WHERE lr.id = ? LIMIT 1 FOR UPDATE");
                $requestStmt->bind_param('i', $requestId);
                $requestStmt->execute();
                $leaveRequest = $requestStmt->get_result()->fetch_assoc();
                $requestStmt->close();
                if (!$leaveRequest || $leaveRequest['status'] !== 'Pending') {
                    throw new DomainException('The leave request is unavailable or has already been decided.');
                }
                $newStatus = $action === 'approve_leave' ? 'Approved' : 'Rejected';
                $reviewerId = (int)$_SESSION['user_id'];
                $decisionReason = $action === 'reject_leave' ? $rejectionReason : null;
                $update = $mysqli->prepare("UPDATE leave_requests SET status = ?, reviewed_by_user_id = ?, rejection_reason = ?, reviewed_at = ? WHERE id = ? AND status = 'Pending'");
                $update->bind_param('sissi', $newStatus, $reviewerId, $decisionReason, $nowSql, $requestId);
                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new RuntimeException('The leave request was changed by another manager.');
                }
                $update->close();
                $auditAction = $newStatus === 'Approved' ? 'Leave Request Approved' : 'Leave Request Rejected';
                $description = sprintf('Request #%d; %s; %s to %s; employee %s.', $requestId, $leaveRequest['leave_type'], $leaveRequest['start_date'], $leaveRequest['end_date'], $leaveRequest['employee_code']);
                if (!auditLog($mysqli, $reviewerId, currentUserName(), currentUserRole(), 'Attendance', $auditAction, 'Success', $description)) {
                    throw new RuntimeException('Audit logging failed.');
                }
                $mysqli->commit();
                $_SESSION['attendance_notice'] = 'Leave request ' . strtolower($newStatus) . ' successfully.';
            } catch (Throwable $exception) {
                $mysqli->rollback();
                $_SESSION['attendance_error'] = $exception instanceof DomainException ? $exception->getMessage() : 'The leave decision could not be completed.';
                error_log('Leave decision failed: ' . $exception->getMessage());
            }
        }
    }
    header('Location: attendance.php');
    exit;
}

$pairingCode = $_SESSION['attendance_pairing_code'] ?? null;
unset($_SESSION['attendance_pairing_code']);
$kioskSessions = [];
$kioskResult = $mysqli->query('SELECT id, label, expires_at, last_used_at, revoked_at FROM attendance_kiosk_sessions ORDER BY created_at DESC');
while ($row = $kioskResult->fetch_assoc()) {
    $kioskSessions[] = $row;
}

$searchTerm = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$dateFilter = trim($_GET['date'] ?? $todayString);
$allowedStatuses = ['all', 'Present', 'Absent', 'Late', 'On Leave'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFilter, $timezone);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateFilter) {
    $dateFilter = $todayString;
}

$summary = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'on_leave' => 0,
    'total' => 0,
];

$summarySql = "SELECT
        COUNT(e.id) AS total_employees,
        COALESCE(SUM(CASE WHEN ar.status = 'Present' THEN 1 ELSE 0 END), 0) AS present_today,
        COALESCE(SUM(CASE WHEN ar.status = 'Late' THEN 1 ELSE 0 END), 0) AS late_today,
        COALESCE(SUM(CASE WHEN ar.id IS NULL AND lr.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS on_leave_today,
        COALESCE(SUM(CASE WHEN ar.id IS NULL AND lr.id IS NULL THEN 1 ELSE 0 END), 0) AS absent_today
    FROM employees e
    LEFT JOIN attendance_records ar
        ON ar.employee_id = e.id AND ar.attendance_date = ?
    LEFT JOIN leave_requests lr
        ON lr.employee_id = e.id AND lr.status = 'Approved' AND ? BETWEEN lr.start_date AND lr.end_date
    WHERE e.account_status = 'Active'";
$summaryStmt = $mysqli->prepare($summarySql);
if (!$summaryStmt) {
    error_log('Attendance summary prepare failed: ' . $mysqli->error);
    die('Database operation failed. Please try again.');
}
$summaryStmt->bind_param('ss', $dateFilter, $dateFilter);
$summaryStmt->execute();
$summaryResult = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

if ($summaryResult) {
    $summary['present'] = (int)$summaryResult['present_today'];
    $summary['absent'] = (int)$summaryResult['absent_today'];
    $summary['late'] = (int)$summaryResult['late_today'];
    $summary['on_leave'] = (int)$summaryResult['on_leave_today'];
    $summary['total'] = (int)$summaryResult['total_employees'];
}

$attendanceSql = "SELECT employee_code, full_name, check_in, check_out, calculated_status AS status
    FROM (
        SELECT e.employee_code, e.full_name, ar.check_in, ar.check_out,
            CASE WHEN ar.id IS NOT NULL THEN ar.status WHEN lr.id IS NOT NULL THEN 'On Leave' ELSE 'Absent' END AS calculated_status,
            ar.created_at
        FROM employees e
        LEFT JOIN attendance_records ar ON ar.employee_id = e.id AND ar.attendance_date = ?
        LEFT JOIN leave_requests lr ON lr.employee_id = e.id AND lr.status = 'Approved' AND ? BETWEEN lr.start_date AND lr.end_date
        WHERE e.account_status = 'Active'
    ) daily
    WHERE (? = '' OR employee_code LIKE CONCAT('%', ?, '%') OR full_name LIKE CONCAT('%', ?, '%'))
      AND (? = 'all' OR calculated_status = ?)
    ORDER BY COALESCE(check_in, created_at) DESC, full_name ASC";
$attendanceStmt = $mysqli->prepare($attendanceSql);
if (!$attendanceStmt) {
    error_log('Attendance records prepare failed: ' . $mysqli->error);
    die('Database operation failed. Please try again.');
}
$attendanceStmt->bind_param('sssssss', $dateFilter, $dateFilter, $searchTerm, $searchTerm, $searchTerm, $statusFilter, $statusFilter);
$attendanceStmt->execute();
$attendanceResult = $attendanceStmt->get_result();
$attendanceRows = [];
while ($row = $attendanceResult->fetch_assoc()) {
    $attendanceRows[] = $row;
}
$attendanceStmt->close();

function formatAttendanceTime($value)
{
    return $value ? (new DateTimeImmutable($value))->format('h:i A') : '—';
}

function calculateWorkingHours($checkIn, $checkOut)
{
    if (!$checkIn || !$checkOut) {
        return '—';
    }

    $start = new DateTimeImmutable($checkIn);
    $end = new DateTimeImmutable($checkOut);
    if ($end < $start) {
        return '—';
    }

    $seconds = $end->getTimestamp() - $start->getTimestamp();
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return sprintf('%dh %02dm', $hours, $minutes);
}

function attendanceBadgeClass($status)
{
    if ($status === 'Late') {
        return 'late';
    }
    if ($status === 'Absent') {
        return 'absent';
    }
    if ($status === 'On Leave') {
        return 'on-leave';
    }
    return 'present';
}

$leaveSearch = trim((string)($_GET['leave_search'] ?? ''));
$leaveStatusFilter = trim((string)($_GET['leave_status'] ?? 'all'));
$leaveTypeFilter = trim((string)($_GET['leave_type'] ?? 'all'));
if (!in_array($leaveStatusFilter, ['all', 'Pending', 'Approved', 'Rejected'], true)) { $leaveStatusFilter = 'all'; }
if (!in_array($leaveTypeFilter, ['all', 'Annual Leave', 'Medical Leave', 'Emergency Leave'], true)) { $leaveTypeFilter = 'all'; }
$leaveSql = "SELECT lr.id, e.employee_code, e.full_name, lr.leave_type, lr.start_date, lr.end_date, lr.reason, lr.attachment_stored_name, lr.status, lr.submitted_at, lr.rejection_reason
    FROM leave_requests lr INNER JOIN employees e ON e.id = lr.employee_id
    WHERE (? = '' OR e.employee_code LIKE CONCAT('%', ?, '%') OR e.full_name LIKE CONCAT('%', ?, '%'))
      AND (? = 'all' OR lr.status = ?)
      AND (? = 'all' OR lr.leave_type = ?)
    ORDER BY CASE lr.status WHEN 'Pending' THEN 0 ELSE 1 END, lr.submitted_at DESC, lr.id DESC";
$leaveStmt = $mysqli->prepare($leaveSql);
$leaveStmt->bind_param('sssssss', $leaveSearch, $leaveSearch, $leaveSearch, $leaveStatusFilter, $leaveStatusFilter, $leaveTypeFilter, $leaveTypeFilter);
$leaveStmt->execute();
$leaveRows = $leaveStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$leaveStmt->close();
$attendanceNotice = (string)($_SESSION['attendance_notice'] ?? '');
$attendanceError = (string)($_SESSION['attendance_error'] ?? '');
unset($_SESSION['attendance_notice'], $_SESSION['attendance_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecurePOS Employee Attendance | Restoran Kencana Sari</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260829-filters">
    <style>
        .attendance-summary { grid-template-columns: repeat(5, minmax(160px, 1fr)); }
        .attendance-layout { display: grid; gap: 22px; }
        .attendance-toolbar { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(170px, 210px) minmax(165px, 195px) auto auto; gap: 10px; align-items: center; margin-bottom: 18px; }
        .attendance-toolbar .form-control, .attendance-toolbar .form-select { width: 100%; height: 40px; min-width: 0; padding: 8px 12px; border: 1px solid var(--theme-89, rgba(255,255,255,.09)); border-radius: 9px; background: var(--theme-79, rgba(255,255,255,.04)); color: var(--text); }
        .attendance-toolbar .form-control::placeholder { color: var(--muted); }
        .attendance-toolbar .form-select option { background: var(--theme-90, #101d2d); color: var(--text); }
        .attendance-toolbar .form-control:focus, .attendance-toolbar .form-select:focus { border-color: rgba(85,214,209,.7); box-shadow: 0 0 0 3px rgba(85,214,209,.12); outline: 0; }
        .attendance-toolbar .btn { display: inline-flex; align-items: center; justify-content: center; justify-self: start; width: auto; height: 40px; padding: 8px 18px; border-radius: 9px; white-space: nowrap; }
        .attendance-toolbar .btn-primary { border-color: #55d6d1; background: linear-gradient(135deg, #55d6d1, #43b6ff); color: #07101c; font-weight: 700; }
        .attendance-toolbar .btn-primary:hover, .attendance-toolbar .btn-primary:focus { border-color: #72e2de; background: linear-gradient(135deg, #72e2de, #59c3ff); color: #07101c; }
        .attendance-toolbar .btn-outline-light { border-color: var(--theme-91, rgba(255,255,255,.16)); background: var(--theme-92, rgba(255,255,255,.035)); color: var(--text); }
        .attendance-toolbar .btn-outline-light:hover, .attendance-toolbar .btn-outline-light:focus { border-color: var(--theme-93, rgba(255,255,255,.28)); background: var(--theme-94, rgba(255,255,255,.08)); color: var(--text); }
        .attendance-qr { display: grid; grid-template-columns: auto minmax(240px, 1fr); gap: 28px; align-items: center; padding: 28px; border: 1px solid rgba(85,214,209,0.24); border-radius: 14px; background: rgba(85,214,209,0.04); }
        .attendance-qr-code { width: 220px; height: 220px; display: grid; place-items: center; padding: 12px; border-radius: 12px; background: #fff; }
        .attendance-qr-code img, .attendance-qr-code canvas { display: block; max-width: 100%; height: auto; }
        .attendance-qr-details h4 { margin: 0 0 8px; color: var(--text); font-size: 1.15rem; }
        .attendance-qr-details p { margin: 0 0 8px; color: var(--muted); }
        .attendance-qr-expiry { color: var(--theme-112, #9feee9) !important; font-weight: 600; }
        .attendance-qr-countdown { margin: 18px 0; color: var(--text); font-size: 1.65rem; font-weight: 800; font-variant-numeric: tabular-nums; }
        .attendance-table { width: 100%; table-layout: fixed; border-collapse: collapse; }
        .attendance-table th, .attendance-table td { vertical-align: middle; padding-top: 12px; padding-bottom: 12px; }
        .attendance-table .col-employee-id { width: 15%; text-align: center; }
        .attendance-table .col-employee-name { width: 25%; text-align: left; }
        .attendance-table .col-check-time { width: 15%; text-align: center; }
        .attendance-table .col-hours { width: 15%; text-align: center; }
        .attendance-table .col-attendance-status { width: 15%; text-align: center; }
        .attendance-status { display: inline-flex; align-items: center; justify-content: center; min-height: 26px; padding: 4px 9px; border-radius: 999px; font-size: .76rem; font-weight: 700; line-height: 1; white-space: nowrap; }
        .attendance-status.present { color: var(--theme-112, #9feee9); background: rgba(85,214,209,.16); border: 1px solid rgba(85,214,209,.24); }
        .attendance-status.late { color: var(--theme-113, #ffd097); background: rgba(255,159,67,.16); border: 1px solid rgba(255,159,67,.24); }
        .attendance-status.absent { color: var(--theme-114, #ffb3c1); background: rgba(252,92,125,.16); border: 1px solid rgba(252,92,125,.24); }
        .attendance-status.on-leave { color: var(--theme-115, #c8bdff); background: rgba(138,107,255,.18); border: 1px solid rgba(138,107,255,.28); }
        .attendance-tabs { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px; }
        .attendance-tabs a { padding:9px 13px; border:1px solid var(--border); border-radius:9px; color:var(--muted); }
        .attendance-tabs a:hover { color:var(--text); border-color:rgba(85,214,209,.45); }
        .attendance-alert { padding:12px 14px; margin-bottom:16px; border-radius:10px; }
        .attendance-alert.success { background:rgba(85,214,209,.13); color:var(--theme-112, #9feee9); }
        .attendance-alert.error { background:rgba(252,92,125,.13); color:var(--theme-114, #ffb3c1); }
        .leave-toolbar { grid-template-columns:minmax(220px,1.4fr) minmax(150px,.7fr) minmax(180px,.8fr) auto auto; margin-bottom:18px; }
        #leave-requests { min-width:0; overflow:hidden; }
        .leave-table-wrap { display:block; width:100%; max-width:100%; overflow-x:auto; overflow-y:hidden; padding:0 2px 6px 0; scrollbar-gutter:stable; }
        .leave-reason-cell { min-width:180px; max-width:245px; white-space:normal; overflow-wrap:anywhere; }
        .manager-leave-table { width:100%; min-width:1050px; margin-bottom:0; table-layout:auto; }
        .manager-leave-table th { padding:11px 9px; vertical-align:middle; white-space:nowrap; }
        .manager-leave-table tbody tr { border-bottom:0; }
        .manager-leave-table tbody td { padding:11px 9px; vertical-align:middle; line-height:1.4; border-top:0; border-right:0; border-left:0; border-bottom:1px solid var(--theme-116, rgba(255,255,255,.06)); background-clip:padding-box; }
        .manager-leave-table tbody tr:last-child td { border-bottom:0; }
        .manager-leave-table th:nth-child(1), .manager-leave-table td:nth-child(1) { min-width:108px; }
        .manager-leave-table th:nth-child(2), .manager-leave-table td:nth-child(2) { min-width:125px; }
        .manager-leave-table th:nth-child(3), .manager-leave-table td:nth-child(3) { min-width:132px; }
        .manager-leave-table th:nth-child(4), .manager-leave-table td:nth-child(4), .manager-leave-table th:nth-child(5), .manager-leave-table td:nth-child(5) { min-width:96px; white-space:nowrap; }
        .manager-leave-table th:nth-child(7), .manager-leave-table td:nth-child(7) { min-width:82px; text-align:center; }
        .manager-leave-table th:nth-child(8), .manager-leave-table td:nth-child(8) { min-width:86px; text-align:center; }
        .manager-leave-table th:nth-child(9), .manager-leave-table td:nth-child(9) { width:128px; min-width:128px; white-space:normal; }
        .manager-leave-table th:nth-child(10), .manager-leave-table td:nth-child(10) { min-width:145px; padding-right:18px; text-align:center; }
        .leave-actions { display:flex; align-items:center; justify-content:center; gap:6px; flex-wrap:nowrap; width:max-content; max-width:100%; margin:0 auto; }
        .leave-actions form { margin:0; }
        .leave-action-btn { display:inline-flex; align-items:center; justify-content:center; min-width:62px; height:34px; min-height:34px; padding:7px 9px; border-radius:8px; font-size:.78rem; font-weight:800; line-height:1; box-shadow:none; transition:background .2s,border-color .2s,color .2s,transform .2s,box-shadow .2s; }
        .leave-approve-btn { border:1px solid rgba(85,214,209,.48); background:rgba(85,214,209,.16); color:var(--theme-117, #aef5f1); }
        .leave-approve-btn:hover, .leave-approve-btn:focus-visible { border-color:#55d6d1; background:#55d6d1; color:#071718; box-shadow:0 7px 18px rgba(85,214,209,.16); transform:translateY(-1px); outline:none; }
        .leave-reject-btn { border:1px solid rgba(252,92,125,.48); background:rgba(252,92,125,.13); color:var(--theme-114, #ffb3c1); }
        .leave-reject-btn:hover, .leave-reject-btn:focus-visible { border-color:#fc5c7d; background:#fc5c7d; color:#19070d; box-shadow:0 7px 18px rgba(252,92,125,.16); transform:translateY(-1px); outline:none; }
        .leave-action-btn:active { transform:translateY(0); }
        .leave-status { display:inline-flex; padding:5px 9px; border-radius:999px; font-size:.75rem; font-weight:700; }
        .leave-status.pending { background:rgba(255,159,67,.15); color:var(--theme-113, #ffd097); }
        .leave-status.approved { background:rgba(85,214,209,.15); color:var(--theme-112, #9feee9); }
        .leave-status.rejected { background:rgba(252,92,125,.15); color:var(--theme-114, #ffb3c1); }
        .leave-dialog { width:min(92vw,520px); border:1px solid var(--border); border-radius:16px; background:var(--theme-90, #101d2d); color:var(--text); padding:22px; }
        .leave-dialog::backdrop { background:var(--theme-118, rgba(2,8,18,.78)); }
        .leave-dialog textarea { width:100%; min-height:120px; padding:10px; border:1px solid var(--border); border-radius:9px; background:var(--theme-119, #081525); color:var(--text); margin:12px 0; }
        .dialog-actions { display:flex; justify-content:flex-end; gap:9px; }
        .attendance-empty { color: var(--muted) !important; text-align: center; padding: 30px 16px !important; }
        .kiosk-panel .panel-header { margin-bottom: 6px; }
        .kiosk-intro { margin: 0; color: var(--muted); font-size: .9rem; line-height: 1.55; }
        .attendance-display-launch { display: flex; gap: 22px; align-items: center; justify-content: space-between; padding: 22px; border: 1px solid rgba(85,214,209,.24); border-radius: 14px; background: linear-gradient(135deg, rgba(85,214,209,.08), rgba(67,182,255,.035)); }
        .attendance-display-launch h3 { margin: 0 0 6px; color: var(--text); font-size: 1.08rem; }
        .attendance-display-launch p { max-width: 660px; margin: 0; color: var(--muted); font-size: .9rem; line-height: 1.5; }
        .attendance-display-launch .btn { flex: 0 0 auto; min-width: 190px; padding: 11px 18px; border-color: #55d6d1; background: #55d6d1; color: #071718; font-weight: 800; }
        .attendance-display-launch .btn:hover, .attendance-display-launch .btn:focus { border-color: #72e2de; background: #72e2de; color: #071718; }
        .kiosk-management { margin-top: 22px; padding-top: 20px; border-top: 1px solid var(--theme-94, rgba(255,255,255,.08)); }
        .kiosk-management-heading h3 { margin: 0 0 4px; color: var(--text); font-size: 1rem; }
        .kiosk-management-heading p { margin: 0; color: var(--muted); font-size: .82rem; }
        .kiosk-controls { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(270px, .65fr); gap: 14px; align-items: stretch; margin: 16px 0 18px; }
        .kiosk-control-card { min-width: 0; padding: 15px 16px; border: 1px solid var(--theme-94, rgba(255,255,255,.08)); border-radius: 12px; background: var(--theme-64, rgba(255,255,255,.025)); }
        .kiosk-control-card h4 { margin: 0 0 8px; color: var(--text); font-size: .92rem; font-weight: 700; }
        .kiosk-pairing-code { display: block; width: fit-content; max-width: 100%; padding: 8px 11px; border: 1px solid rgba(85,214,209,.3); border-radius: 8px; background: rgba(85,214,209,.09); color: var(--theme-120, #b9f5f1); font-family: Consolas, "SFMono-Regular", Menlo, Monaco, monospace; font-size: .96rem; font-weight: 700; line-height: 1.45; letter-spacing: .035em; overflow-wrap: anywhere; word-break: break-word; }
        .kiosk-code-note, .kiosk-code-expiry { margin: 7px 0 0; color: var(--muted); font-size: .78rem; line-height: 1.45; }
        .kiosk-code-empty { color: var(--muted); font-size: .85rem; }
        .kiosk-actions-card { display: flex; flex-direction: column; justify-content: center; }
        .kiosk-actions { display: flex; flex-wrap: wrap; gap: 9px; align-items: center; }
        .kiosk-actions form, .kiosk-table form { margin: 0; }
        .kiosk-actions .btn, .kiosk-table .btn { min-height: 34px; padding: 7px 12px; border-radius: 8px; font-size: .8rem; font-weight: 700; line-height: 1.2; box-shadow: none; }
        .kiosk-actions .btn-primary { border-color: #55d6d1; background: #55d6d1; color: #071718; }
        .kiosk-actions .btn-primary:hover, .kiosk-actions .btn-primary:focus { border-color: #72e2de; background: #72e2de; color: #071718; }
        .kiosk-table { margin-bottom: 0; }
        .kiosk-table th, .kiosk-table td { padding: 10px 12px; vertical-align: middle; }
        .kiosk-table th:first-child, .kiosk-table td:first-child { text-align: left; }
        .kiosk-table th:not(:first-child), .kiosk-table td:not(:first-child) { text-align: center; }
        .kiosk-table .attendance-empty { padding: 18px 12px !important; font-size: .86rem; }
        @media (max-width: 1100px) { .attendance-toolbar, .leave-toolbar { grid-template-columns: repeat(2, minmax(180px, 1fr)); } .attendance-summary { grid-template-columns: repeat(3, minmax(160px, 1fr)); } }
        @media (max-width: 900px) { .attendance-summary { grid-template-columns: repeat(2, minmax(180px, 1fr)); } }
        @media (max-width: 720px) { .kiosk-controls { grid-template-columns: 1fr; } .attendance-display-launch { align-items: stretch; flex-direction: column; } .attendance-display-launch .btn { width: 100%; } }
        @media (max-width: 620px) { .attendance-summary, .attendance-toolbar, .attendance-qr { grid-template-columns: 1fr; } .attendance-qr-code { margin: 0 auto; } .attendance-qr-details { text-align: center; } .kiosk-panel .panel-header { display: block; } .kiosk-panel .panel-header span { display: block; margin-top: 4px; } .kiosk-controls { margin-top: 14px; } .kiosk-control-card { padding: 14px; } .kiosk-actions { align-items: stretch; } .kiosk-actions form, .kiosk-actions .btn { width: 100%; } .kiosk-table { min-width: 620px; } }
    </style>
    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head>
<body>
    <div class="dashboard-shell">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-icon">S</div>
                    <div><h1>SecurePOS</h1><p>Restoran Kencana Sari</p></div>
                </div>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="pos.php" class="nav-link">Point of Sale</a>
                <a href="inventory.php" class="nav-link">Inventory</a>
                <a href="product_expiry.php" class="nav-link">Product Expiry</a>
                <a href="attendance.php" class="nav-link active">Employee Attendance</a>
                <a href="users.php" class="nav-link">Users</a>
                <a href="reports.php" class="nav-link">Reports</a>
                <a href="audit_logs.php" class="nav-link">Audit Logs</a>
            </nav>
            <div class="sidebar-footer">
                <div class="profile-avatar"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></div>
                <div><p class="profile-name"><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p><p class="profile-role"><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></p><a class="profile-logout" href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title"><p class="breadcrumb">SecurePOS / Employee Attendance</p><h2>Employee Attendance</h2></div>
                <div class="topbar-actions">
                    <div class="topbar-chip secondary"><span id="current-datetime">Loading...</span></div>
                    <?php echo renderExpiryNotificationBell($notifications, 'product_expiry.php'); ?>
                    <div class="topbar-profile"><span class="profile-initials"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></span><div><p><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p><small><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></small></div></div>
                </div>
            </header>

            <section class="dashboard-header">
                <div><h1 class="greeting">Attendance monitoring</h1><p class="intro">Monitor daily employee check-ins, check-outs, and attendance status.</p></div>
            </section>

            <?php if ($attendanceNotice !== '') : ?><div class="attendance-alert success" role="status"><?php echo htmlspecialchars($attendanceNotice, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($attendanceError !== '') : ?><div class="attendance-alert error" role="alert"><?php echo htmlspecialchars($attendanceError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <nav class="attendance-tabs" aria-label="Attendance sections"><a href="#daily-attendance">Daily Attendance</a><a href="#leave-requests">Leave Requests</a><a href="#kiosk-management">Kiosk Management</a></nav>

            <section class="summary-cards attendance-summary">
                <article class="summary-card accent-cyan"><div class="card-top"><span class="card-icon">✓</span><div class="card-title">Present</div></div><div class="card-value"><?php echo $summary['present']; ?></div><p class="card-note">Recorded for <?php echo htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8'); ?>.</p></article>
                <article class="summary-card accent-red"><div class="card-top"><span class="card-icon">×</span><div class="card-title">Absent</div></div><div class="card-value"><?php echo $summary['absent']; ?></div><p class="card-note">Active employees without attendance or approved leave.</p></article>
                <article class="summary-card accent-orange"><div class="card-top"><span class="card-icon">!</span><div class="card-title">Late</div></div><div class="card-value"><?php echo $summary['late']; ?></div><p class="card-note">Recorded for the selected date.</p></article>
                <article class="summary-card accent-purple"><div class="card-top"><span class="card-icon">O</span><div class="card-title">On Leave</div></div><div class="card-value"><?php echo $summary['on_leave']; ?></div><p class="card-note">Approved leave without QR attendance.</p></article>
                <article class="summary-card accent-blue"><div class="card-top"><span class="card-icon">#</span><div class="card-title">Total Employees</div></div><div class="card-value"><?php echo $summary['total']; ?></div><p class="card-note">Active employees in SecurePOS.</p></article>
            </section>

            <section class="panel" id="leave-requests" style="margin-bottom:20px">
                <div class="panel-header"><h3>Leave Requests</h3><span>Review employee leave applications</span></div>
                <form method="get" class="attendance-toolbar leave-toolbar securepos-filter">
                    <input type="hidden" name="date" value="<?php echo htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="search" class="form-control" name="leave_search" value="<?php echo htmlspecialchars($leaveSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Employee code or name">
                    <select class="form-select" name="leave_status"><option value="all">All Status</option><?php foreach (['Pending','Approved','Rejected'] as $value) : ?><option value="<?php echo $value; ?>" <?php echo $leaveStatusFilter === $value ? 'selected' : ''; ?>><?php echo $value; ?></option><?php endforeach; ?></select>
                    <select class="form-select" name="leave_type"><option value="all">All Leave Types</option><?php foreach (['Annual Leave','Medical Leave','Emergency Leave'] as $value) : ?><option value="<?php echo $value; ?>" <?php echo $leaveTypeFilter === $value ? 'selected' : ''; ?>><?php echo $value; ?></option><?php endforeach; ?></select>
                    <button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-outline-light" href="attendance.php#leave-requests">Reset</a>
                </form>
                <div class="table-responsive leave-table-wrap"><table class="table transactions-table manager-leave-table"><thead><tr><th>Employee Code</th><th>Employee Name</th><th>Leave Type</th><th>Start Date</th><th>End Date</th><th>Reason</th><th>Document</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
                <?php if (!$leaveRows) : ?><tr><td colspan="10" class="attendance-empty">No leave requests found.</td></tr><?php endif; ?>
                <?php foreach ($leaveRows as $leave) : ?><tr>
                    <td><?php echo htmlspecialchars($leave['employee_code'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($leave['full_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($leave['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($leave['start_date'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($leave['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="leave-reason-cell"><?php echo nl2br(htmlspecialchars($leave['reason'], ENT_QUOTES, 'UTF-8')); ?><?php if ($leave['rejection_reason']) : ?><br><small>Rejection: <?php echo nl2br(htmlspecialchars($leave['rejection_reason'], ENT_QUOTES, 'UTF-8')); ?></small><?php endif; ?></td>
                    <td><?php if ($leave['attachment_stored_name']) : ?><a class="btn btn-outline-light" href="leave_document.php?id=<?php echo (int)$leave['id']; ?>">View</a><?php else : ?>&mdash;<?php endif; ?></td><td><span class="leave-status <?php echo leaveStatusClass($leave['status']); ?>"><?php echo htmlspecialchars($leave['status'], ENT_QUOTES, 'UTF-8'); ?></span></td><td><?php echo htmlspecialchars($leave['submitted_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php if ($leave['status'] === 'Pending') : ?><div class="leave-actions"><form method="post" onsubmit="return confirm('Approve this leave request?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['attendance_admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="approve_leave"><input type="hidden" name="request_id" value="<?php echo (int)$leave['id']; ?>"><button class="btn leave-action-btn leave-approve-btn" type="submit">Approve</button></form><button class="btn leave-action-btn leave-reject-btn reject-leave-button" type="button" data-request-id="<?php echo (int)$leave['id']; ?>">Reject</button></div><?php else : ?>&mdash;<?php endif; ?></td>
                </tr><?php endforeach; ?></tbody></table></div>
            </section>

            <div class="attendance-layout">
                <section class="panel kiosk-panel" id="kiosk-management">
                    <div class="attendance-display-launch">
                        <div><h3>Attendance QR Display</h3><p>Employees scan this rotating QR code using their phones to securely check in and check out.</p></div>
                        <a class="btn btn-primary" href="<?php echo htmlspecialchars(rtrim(SECUREPOS_BASE_URL, '/') . '/attendance_kiosk.php', ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open Attendance QR</a>
                    </div>
                    <div class="kiosk-management">
                        <div class="kiosk-management-heading"><h3>Kiosk Device Management</h3><p>Pair and manage authorized attendance display devices.</p></div>
                        <p class="kiosk-intro">QRs rotate automatically from 8:00 AM until 9:00 PM Malaysia time. Pairing codes expire after 10 minutes and can be used once.</p>
                        <div class="kiosk-controls">
                        <div class="kiosk-control-card">
                            <h4>One-time pairing code</h4>
                            <?php if (is_array($pairingCode)) : ?>
                                <code class="kiosk-pairing-code"><?php echo htmlspecialchars($pairingCode['code'], ENT_QUOTES, 'UTF-8'); ?></code>
                                <p class="kiosk-code-expiry">Expires: <?php echo htmlspecialchars($pairingCode['expires'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <p class="kiosk-code-note">Enter this on the HTTPS kiosk activation page. It will not be shown again.</p>
                            <?php else : ?>
                                <span class="kiosk-code-empty">Create a code to pair a new attendance display.</span>
                            <?php endif; ?>
                        </div>
                        <div class="kiosk-control-card kiosk-actions-card">
                            <h4>Kiosk controls</h4>
                            <div class="kiosk-actions">
                                <form method="post"><input type="hidden" name="action" value="create_kiosk_pairing"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['attendance_admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="btn btn-primary">Pair New Display</button></form>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive"><table class="table transactions-table kiosk-table"><thead><tr><th>Display</th><th>Expires</th><th>Last Used</th><th>Status</th><th>Action</th></tr></thead><tbody>
                    <?php if (!$kioskSessions) : ?><tr><td colspan="5" class="attendance-empty">No kiosk has been paired yet.</td></tr><?php endif; ?>
                    <?php foreach ($kioskSessions as $kioskSession) : ?><tr><td><?php echo htmlspecialchars($kioskSession['label'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($kioskSession['expires_at'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($kioskSession['last_used_at'] ?? 'Never', ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo $kioskSession['revoked_at'] === null ? 'Active' : 'Revoked'; ?></td><td><?php if ($kioskSession['revoked_at'] === null) : ?><form method="post"><input type="hidden" name="action" value="revoke_kiosk"><input type="hidden" name="kiosk_id" value="<?php echo (int)$kioskSession['id']; ?>"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['attendance_admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="btn btn-outline-light">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                    </div>
                </section>

                <section class="panel" id="daily-attendance">
                    <div class="panel-header"><h3>Daily Attendance</h3><span>Attendance status for <?php echo htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8'); ?></span></div>
                    <form method="get" class="attendance-toolbar securepos-filter">
                        <input type="search" class="form-control" name="search" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search employee ID or name" aria-label="Search employee ID or name">
                        <select class="form-select" name="status" aria-label="Filter by status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="Present" <?php echo $statusFilter === 'Present' ? 'selected' : ''; ?>>Present</option>
                            <option value="Absent" <?php echo $statusFilter === 'Absent' ? 'selected' : ''; ?>>Absent</option>
                            <option value="Late" <?php echo $statusFilter === 'Late' ? 'selected' : ''; ?>>Late</option>
                            <option value="On Leave" <?php echo $statusFilter === 'On Leave' ? 'selected' : ''; ?>>On Leave</option>
                        </select>
                        <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Filter by date">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="attendance.php" class="btn btn-outline-light">Reset</a>
                    </form>
                    <div class="table-responsive">
                        <table class="table transactions-table attendance-table">
                            <thead><tr><th class="col-employee-id">Employee ID</th><th class="col-employee-name">Employee Name</th><th class="col-check-time">Check In</th><th class="col-check-time">Check Out</th><th class="col-hours">Working Hours</th><th class="col-attendance-status">Status</th></tr></thead>
                            <tbody>
                            <?php if (empty($attendanceRows)) : ?>
                                <tr><td colspan="6" class="attendance-empty">No attendance records found.</td></tr>
                            <?php else : ?>
                                <?php foreach ($attendanceRows as $row) : ?>
                                    <tr>
                                        <td class="col-employee-id"><?php echo htmlspecialchars($row['employee_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="col-employee-name"><?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="col-check-time"><?php echo formatAttendanceTime($row['check_in']); ?></td>
                                        <td class="col-check-time"><?php echo formatAttendanceTime($row['check_out']); ?></td>
                                        <td class="col-hours"><?php echo calculateWorkingHours($row['check_in'], $row['check_out']); ?></td>
                                        <td class="col-attendance-status"><span class="attendance-status <?php echo attendanceBadgeClass($row['status']); ?>"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>
    <dialog class="leave-dialog" id="reject-leave-dialog"><form method="post"><h3>Reject Leave Request</h3><p class="intro">A rejection reason is required and will be visible to the employee.</p><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['attendance_admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="reject_leave"><input type="hidden" name="request_id" id="reject-request-id"><textarea name="rejection_reason" maxlength="1000" required placeholder="Enter rejection reason"></textarea><div class="dialog-actions"><button type="button" class="btn btn-outline-light" id="cancel-reject">Cancel</button><button type="submit" class="btn btn-primary">Confirm Reject</button></div></form></dialog>
    <script src="assets/js/dashboard.js"></script>
    <script>const rejectDialog=document.getElementById('reject-leave-dialog');document.querySelectorAll('.reject-leave-button').forEach(button=>button.addEventListener('click',()=>{document.getElementById('reject-request-id').value=button.dataset.requestId;rejectDialog.showModal();}));document.getElementById('cancel-reject').addEventListener('click',()=>rejectDialog.close());</script>
</body>
</html>

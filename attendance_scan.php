<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/attendance_qr.php';

startSecureSession();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

$timezone = new DateTimeZone('Asia/Kuala_Lumpur');
$eligibleRoles = ['Cashier', 'Inventory Staff', 'Manager'];
$scanResult = null;
$attendanceRequest = false;

function finishAttendanceScan($result)
{
    unset($_SESSION['attendance_scan_challenge']);
    $_SESSION['attendance_scan_result'] = $result;
    header('Location: attendance_scan.php?result=1');
    exit;
}

function loadQrToken($mysqli, $rawToken, $forUpdate = false)
{
    $tokenHash = hash('sha256', $rawToken);
    $sql = 'SELECT id, valid_from, valid_until, is_active FROM attendance_qr_tokens WHERE token_hash = ? LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $token = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $token ?: null;
}

function qrRejection($qrToken, $nowSql)
{
    if (!$qrToken) {
        return ['reason' => 'Invalid QR token', 'message' => 'This attendance QR code is invalid.']; 
    }
    if ((int)$qrToken['is_active'] !== 1) {
        return ['reason' => 'Inactive QR token', 'message' => 'QR code is no longer active.'];
    }
    if ($nowSql < $qrToken['valid_from'] || $nowSql >= $qrToken['valid_until']) {
        return ['reason' => 'Expired QR token', 'message' => 'QR code has expired.'];
    }
    return null;
}

function loadEligibleEmployee($mysqli, $userId, $eligibleRoles)
{
    $stmt = $mysqli->prepare("SELECT e.id, e.full_name, e.role, e.account_status, u.role AS user_role, u.account_status AS user_status
        FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $eligible = $employee && $employee['id'] !== null
        && $employee['account_status'] === 'Active' && $employee['user_status'] === 'Active'
        && in_array($employee['role'], $eligibleRoles, true) && $employee['user_role'] === $employee['role'];
    return $eligible ? $employee : null;
}

function employeeAlreadyUsedQr(mysqli $mysqli, int $employeeId, int $qrTokenId): bool
{
    $stmt = $mysqli->prepare("SELECT id FROM attendance_attempt_logs WHERE employee_id = ? AND qr_token_id = ? AND result = 'Success' AND attempt_type IN ('CHECK_IN', 'CHECK_OUT') LIMIT 1");
    $stmt->bind_param('ii', $employeeId, $qrTokenId);
    $stmt->execute();
    $used = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $used;
}

if (isset($_GET['result'])) {
    $scanResult = $_SESSION['attendance_scan_result'] ?? null;
    unset($_SESSION['attendance_scan_result']);
    if (!is_array($scanResult)) {
        $scanResult = ['success' => false, 'title' => 'Scan Result Unavailable', 'message' => 'Please scan the active attendance QR code again.'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_attendance') {
    $challenge = $_SESSION['attendance_scan_challenge'] ?? null;
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!is_array($challenge) || empty($challenge['csrf']) || !hash_equals($challenge['csrf'], $csrf)) {
        finishAttendanceScan(['success' => false, 'title' => 'Attendance Not Recorded', 'message' => 'The attendance request is no longer valid. Please scan the QR code again.']);
    }

    $rawToken = (string)($challenge['raw_token'] ?? '');
    if (!isAuthenticated()) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $rawToken)) {
            $_SESSION['post_login_redirect'] = 'attendance_scan.php?token=' . $rawToken;
        }
        unset($_SESSION['attendance_scan_challenge']);
        header('Location: login.php');
        exit;
    }
    $mysqli->begin_transaction();
    try {
        $now = new DateTimeImmutable('now', $timezone);
        $nowSql = $now->format('Y-m-d H:i:s');
        $qrToken = preg_match('/\A[a-f0-9]{64}\z/D', $rawToken) ? loadQrToken($mysqli, $rawToken, true) : null;
        $qrTokenId = $qrToken ? (int)$qrToken['id'] : null;
        $employee = loadEligibleEmployee($mysqli, (int)$_SESSION['user_id'], $eligibleRoles);
        $employeeId = $employee ? (int)$employee['id'] : null;
        $qrFailure = qrRejection($qrToken, $nowSql);
        $outsideHours = !attendanceWindow($now)['is_open'];

        if ($outsideHours) {
            attendanceAudit($mysqli, $employeeId, $qrTokenId, 'SCAN', 'Rejected', 'Outside attendance operating hours');
            $scanResult = ['success' => false, 'title' => 'Attendance Closed', 'message' => 'Attendance is available from 8:00 AM until 9:00 PM Malaysia time.'];
        } elseif ($qrFailure) {
            attendanceAudit($mysqli, $employeeId, $qrTokenId, 'SCAN', 'Rejected', $qrFailure['reason']);
            $scanResult = ['success' => false, 'title' => 'Attendance Not Recorded', 'message' => $qrFailure['message']];
        } elseif (!$employee) {
            attendanceAudit($mysqli, null, $qrTokenId, 'SCAN', 'Rejected', 'Employee not eligible');
            $scanResult = ['success' => false, 'title' => 'Attendance Not Recorded', 'message' => 'Employee account is not eligible for attendance.'];
        } elseif (employeeAlreadyUsedQr($mysqli, $employeeId, $qrTokenId)) {
            attendanceAudit($mysqli, $employeeId, $qrTokenId, 'SCAN', 'Rejected', 'QR token already used by employee');
            $scanResult = ['success' => false, 'title' => 'QR Already Used', 'message' => 'Scan the current rotating QR for your next attendance action.'];
        } else {
                $todaySql = $now->format('Y-m-d');
                $stmt = $mysqli->prepare('SELECT id, check_in, check_out, status FROM attendance_records WHERE employee_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE');
                if (!$stmt) { throw new RuntimeException($mysqli->error); }
                $stmt->bind_param('is', $employeeId, $todaySql);
                $stmt->execute();
                $attendance = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$attendance) {
                    $status = 'Present';
                    $stmt = $mysqli->prepare('INSERT INTO attendance_records (employee_id, attendance_date, check_in, status, qr_token_id) VALUES (?, ?, ?, ?, ?)');
                    if (!$stmt) { throw new RuntimeException($mysqli->error); }
                    $stmt->bind_param('isssi', $employeeId, $todaySql, $nowSql, $status, $qrTokenId);
                    if (!$stmt->execute()) { throw new RuntimeException($stmt->error); }
                    $stmt->close();
                    attendanceAudit($mysqli, $employeeId, $qrTokenId, 'CHECK_IN', 'Success', 'Attendance check-in recorded');
                    $scanResult = ['success' => true, 'title' => 'Attendance Recorded', 'message' => 'Your check-in has been recorded successfully.', 'employee_name' => $employee['full_name'], 'employee_role' => $employee['role'], 'date' => $now->format('d M Y'), 'check_in' => $now->format('h:i:s A'), 'status' => $status];
                } elseif (empty($attendance['check_out'])) {
                    $attendanceId = (int)$attendance['id'];
                    $stmt = $mysqli->prepare('UPDATE attendance_records SET check_out = ?, qr_token_id = ? WHERE id = ? AND check_out IS NULL');
                    if (!$stmt) { throw new RuntimeException($mysqli->error); }
                    $stmt->bind_param('sii', $nowSql, $qrTokenId, $attendanceId);
                    if (!$stmt->execute() || $stmt->affected_rows !== 1) { throw new RuntimeException('Check-out update failed'); }
                    $stmt->close();
                    attendanceAudit($mysqli, $employeeId, $qrTokenId, 'CHECK_OUT', 'Success', 'Attendance check-out recorded');
                    $checkIn = new DateTimeImmutable($attendance['check_in'], $timezone);
                    $scanResult = ['success' => true, 'title' => 'Check-Out Recorded', 'message' => 'Your check-out has been recorded successfully.', 'employee_name' => $employee['full_name'], 'date' => $now->format('d M Y'), 'check_in' => $checkIn->format('h:i:s A'), 'check_out' => $now->format('h:i:s A')];
                } else {
                    attendanceAudit($mysqli, $employeeId, $qrTokenId, 'SCAN', 'Rejected', 'Attendance already completed');
                    $scanResult = ['success' => false, 'title' => 'Attendance Already Completed', 'message' => 'Attendance for today has already been completed.'];
                }
        }
        $mysqli->commit();
    } catch (Throwable $exception) {
        $mysqli->rollback();
        error_log('Attendance scan failed: ' . $exception->getMessage());
        $scanResult = ['success' => false, 'title' => 'Attendance Not Recorded', 'message' => 'SecurePOS could not process attendance. Please try again.'];
    }
    finishAttendanceScan($scanResult);
} else {
    $rawToken = (string)($_GET['token'] ?? '');
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $rawToken)) {
        finishAttendanceScan(['success' => false, 'title' => 'Invalid Attendance QR', 'message' => 'This attendance QR code is invalid.']);
    }
    if (!isAuthenticated()) {
        $_SESSION['post_login_redirect'] = 'attendance_scan.php?token=' . $rawToken;
        header('Location: login.php');
        exit;
    }
    try {
        $now = new DateTimeImmutable('now', $timezone);
        $nowSql = $now->format('Y-m-d H:i:s');
        $qrToken = loadQrToken($mysqli, $rawToken);
        $qrFailure = qrRejection($qrToken, $nowSql);
        $employee = loadEligibleEmployee($mysqli, (int)$_SESSION['user_id'], $eligibleRoles);
        if (!attendanceWindow($now)['is_open']) {
            attendanceAudit($mysqli, $employee ? (int)$employee['id'] : null, $qrToken ? (int)$qrToken['id'] : null, 'SCAN', 'Rejected', 'Outside attendance operating hours');
            finishAttendanceScan(['success' => false, 'title' => 'Attendance Closed', 'message' => 'Attendance is available from 8:00 AM until 9:00 PM Malaysia time.']);
        }
        if ($qrFailure) {
            attendanceAudit($mysqli, $employee ? (int)$employee['id'] : null, $qrToken ? (int)$qrToken['id'] : null, 'SCAN', 'Rejected', $qrFailure['reason']);
            finishAttendanceScan(['success' => false, 'title' => 'Attendance Not Recorded', 'message' => $qrFailure['message']]);
        }
        if (!$employee) {
            attendanceAudit($mysqli, null, (int)$qrToken['id'], 'SCAN', 'Rejected', 'Employee not eligible');
            finishAttendanceScan(['success' => false, 'title' => 'Attendance Not Recorded', 'message' => 'Employee account is not eligible for attendance.']);
        }
        $_SESSION['attendance_scan_challenge'] = ['raw_token' => $rawToken, 'csrf' => bin2hex(random_bytes(32))];
        $attendanceRequest = true;
    } catch (Throwable $exception) {
        error_log('Attendance scan request failed: ' . $exception->getMessage());
        finishAttendanceScan(['success' => false, 'title' => 'Attendance Not Recorded', 'message' => 'SecurePOS could not verify this scan. Please try again.']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecurePOS Attendance Scan</title><link rel="stylesheet" href="assets/css/style.css">
    <style>
        body{min-height:100vh;display:grid;place-items:center;padding:24px;background:var(--theme-122, #07111f);color:var(--text)}.scan-card{width:min(100%,520px);padding:32px;border:1px solid var(--theme-89, rgba(255,255,255,.09));border-radius:18px;background:var(--theme-90, #101d2d);box-shadow:0 24px 70px var(--theme-133, rgba(0,0,0,.35))}.scan-brand{display:flex;align-items:center;gap:12px;margin-bottom:28px}.scan-brand h1{margin:0;font-size:1.25rem}.scan-result-icon{width:52px;height:52px;display:grid;place-items:center;margin-bottom:18px;border-radius:50%;font-size:1.5rem;font-weight:800}.scan-result-icon.success{color:var(--theme-112, #9feee9);background:rgba(85,214,209,.16)}.scan-result-icon.error{color:var(--theme-114, #ffb3c1);background:rgba(252,92,125,.16)}.scan-card h2{margin:0 0 10px;font-size:1.55rem}.scan-message{color:var(--muted);margin-bottom:22px}.scan-details{display:grid;gap:10px;margin:0 0 24px;padding:18px;border-radius:12px;background:var(--theme-92, rgba(255,255,255,.035))}.scan-detail{display:flex;justify-content:space-between;gap:18px}.scan-detail dt{color:var(--muted);font-weight:500}.scan-detail dd{margin:0;text-align:right;font-weight:700}.scan-actions{display:flex;gap:12px;flex-wrap:wrap}.attendance-processing{color:var(--muted);margin:14px 0 0}
    </style>
    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head>
<body><main class="scan-card">
    <div class="scan-brand"><div class="brand-icon">S</div><h1>SecurePOS Attendance</h1></div>
    <?php if ($attendanceRequest) : ?>
        <div class="scan-result-icon success">&#9679;</div><h2>Recording Attendance</h2>
        <p class="scan-message">SecurePOS is validating your employee account and secure QR code.</p>
        <form id="attendance-form" method="post">
            <input type="hidden" name="action" value="record_attendance"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['attendance_scan_challenge']['csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <noscript><button type="submit" class="btn btn-primary">Record Attendance</button></noscript>
            <p class="attendance-processing" aria-live="polite">Please wait&hellip;</p>
        </form>
    <?php else : ?>
        <div class="scan-result-icon <?php echo $scanResult['success'] ? 'success' : 'error'; ?>"><?php echo $scanResult['success'] ? '&#10003;' : '!'; ?></div>
        <h2><?php echo htmlspecialchars($scanResult['title'], ENT_QUOTES, 'UTF-8'); ?></h2><p class="scan-message"><?php echo htmlspecialchars($scanResult['message'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if (!empty($scanResult['employee_name'])) : ?><dl class="scan-details">
            <div class="scan-detail"><dt>Employee</dt><dd><?php echo htmlspecialchars($scanResult['employee_name'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <?php if (!empty($scanResult['employee_role'])) : ?><div class="scan-detail"><dt>Role</dt><dd><?php echo htmlspecialchars($scanResult['employee_role'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
            <div class="scan-detail"><dt>Date</dt><dd><?php echo htmlspecialchars($scanResult['date'], ENT_QUOTES, 'UTF-8'); ?></dd></div><div class="scan-detail"><dt>Check-In</dt><dd><?php echo htmlspecialchars($scanResult['check_in'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <?php if (!empty($scanResult['check_out'])) : ?><div class="scan-detail"><dt>Check-Out</dt><dd><?php echo htmlspecialchars($scanResult['check_out'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?><?php if (!empty($scanResult['status'])) : ?><div class="scan-detail"><dt>Status</dt><dd><?php echo htmlspecialchars($scanResult['status'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
        </dl><?php endif; ?><div class="scan-actions"><a class="btn btn-primary" href="login.php">Continue</a><a class="btn btn-outline-light" href="logout.php">Logout</a></div>
    <?php endif; ?>
</main>
<?php if ($attendanceRequest) : ?><script>
(function(){var form=document.getElementById('attendance-form');if(form){form.submit()}}());
</script><?php endif; ?></body></html>

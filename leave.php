<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/leave.php';

$employee = requireLeaveEmployee($mysqli);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (empty($_SESSION['leave_employee_csrf'])) {
    $_SESSION['leave_employee_csrf'] = bin2hex(random_bytes(32));
}
$error = '';
$success = (string)($_SESSION['leave_success'] ?? '');
unset($_SESSION['leave_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['leave_employee_csrf'], $csrf)) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    $movedPath = null;
    try {
        $leaveType = trim((string)($_POST['leave_type'] ?? ''));
        if (!in_array($leaveType, ['Annual Leave', 'Medical Leave', 'Emergency Leave'], true)) {
            throw new DomainException('Select a valid leave type.');
        }
        $startDate = parseLeaveDate((string)($_POST['start_date'] ?? ''), 'Start date');
        $endDate = parseLeaveDate((string)($_POST['end_date'] ?? ''), 'End date');
        if ($endDate < $startDate) {
            throw new DomainException('End date cannot be earlier than start date.');
        }
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 1000) {
            throw new DomainException('Reason is required and must not exceed 1000 characters.');
        }
        $upload = validateLeaveUpload($_FILES['supporting_document'] ?? [], $leaveType === 'Medical Leave');
        $employeeId = (int)$employee['id'];
        $mysqli->begin_transaction();
        $lock = $mysqli->prepare('SELECT id FROM employees WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $employeeId);
        $lock->execute();
        $lock->close();
        $overlap = $mysqli->prepare("SELECT id FROM leave_requests WHERE employee_id = ? AND status IN ('Pending', 'Approved') AND start_date <= ? AND end_date >= ? LIMIT 1");
        $overlap->bind_param('iss', $employeeId, $endDate, $startDate);
        $overlap->execute();
        $existing = $overlap->get_result()->fetch_assoc();
        $overlap->close();
        if ($existing) {
            throw new DomainException('This application overlaps an existing Pending or Approved leave request.');
        }
        if ($upload) {
            $movedPath = leaveStoragePath($upload['stored_name']);
            if (!is_dir(SECUREPOS_LEAVE_STORAGE) || !move_uploaded_file($upload['temporary_path'], $movedPath)) {
                throw new RuntimeException('Secure document storage is unavailable.');
            }
        }
        $stored = $upload['stored_name'] ?? null;
        $original = $upload['original_name'] ?? null;
        $mime = $upload['mime_type'] ?? null;
        $size = $upload['size'] ?? null;
        $submittedAt = (new DateTimeImmutable('now', leaveTimezone()))->format('Y-m-d H:i:s');
        $insert = $mysqli->prepare('INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, reason, attachment_stored_name, attachment_original_name, attachment_mime_type, attachment_size, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->bind_param('isssssssis', $employeeId, $leaveType, $startDate, $endDate, $reason, $stored, $original, $mime, $size, $submittedAt);
        if (!$insert->execute()) {
            throw new RuntimeException($insert->error);
        }
        $requestId = (int)$insert->insert_id;
        $insert->close();
        $description = sprintf('Request #%d; %s; %s to %s; employee %s.', $requestId, $leaveType, $startDate, $endDate, $employee['employee_code']);
        if (!auditLog($mysqli, (int)$_SESSION['user_id'], currentUserName(), currentUserRole(), 'Attendance', 'Leave Application Submitted', 'Success', $description)) {
            throw new RuntimeException('Audit logging failed.');
        }
        $mysqli->commit();
        $_SESSION['leave_success'] = 'Leave application submitted successfully.';
        header('Location: leave.php');
        exit;
    } catch (DomainException $exception) {
        $mysqli->rollback();
        if ($movedPath && is_file($movedPath)) { @unlink($movedPath); }
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $mysqli->rollback();
        if ($movedPath && is_file($movedPath)) { @unlink($movedPath); }
        error_log('Leave submission failed: ' . $exception->getMessage());
        $error = 'SecurePOS could not submit the leave application. Please try again.';
    }
}

$historyStmt = $mysqli->prepare('SELECT id, leave_type, start_date, end_date, reason, status, attachment_stored_name, attachment_original_name, rejection_reason, submitted_at FROM leave_requests WHERE employee_id = ? ORDER BY submitted_at DESC, id DESC');
$employeeId = (int)$employee['id'];
$historyStmt->bind_param('i', $employeeId);
$historyStmt->execute();
$history = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Employee Attendance / Leave | SecurePOS</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css?v=20260820-sidebar">
<style>
.leave-grid{display:grid;grid-template-columns:minmax(300px,.8fr) minmax(520px,1.4fr);gap:20px;align-items:start}
.leave-form{display:grid;gap:17px}.leave-form label{display:grid;gap:8px;color:var(--muted);font-size:.88rem;font-weight:600}
.leave-form input,.leave-form select,.leave-form textarea{width:100%;padding:11px 12px;border:1px solid var(--border);border-radius:10px;background:#0b1526;color:var(--text);outline:none;transition:border-color .2s,box-shadow .2s,background .2s}
.leave-form input:focus,.leave-form select:focus,.leave-form textarea:focus{border-color:rgba(85,214,209,.72);box-shadow:0 0 0 3px rgba(85,214,209,.12);background:#0d192b}
.leave-form select option{background:#101d2d}.leave-form textarea{min-height:112px;resize:vertical;line-height:1.5}
.leave-form input[type=file]{padding:7px;color:var(--muted);cursor:pointer;background:rgba(255,255,255,.025)}
.leave-form input[type=file]::file-selector-button{margin-right:12px;padding:8px 12px;border:1px solid rgba(85,214,209,.3);border-radius:8px;background:rgba(85,214,209,.12);color:#b9f5f1;font-weight:700;cursor:pointer;transition:background .2s,border-color .2s,color .2s}
.leave-form input[type=file]:hover::file-selector-button,.leave-form input[type=file]:focus::file-selector-button{border-color:rgba(85,214,209,.62);background:rgba(85,214,209,.2);color:#e8fffd}
.leave-submit{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:10px 18px;border:1px solid #55d6d1;border-radius:10px;background:linear-gradient(135deg,#55d6d1,#43b6ff);color:#07101c;font-weight:800;box-shadow:0 10px 24px rgba(67,182,255,.13);transition:transform .2s,box-shadow .2s,filter .2s}
.leave-submit:hover,.leave-submit:focus-visible{color:#07101c;filter:brightness(1.08);box-shadow:0 12px 28px rgba(67,182,255,.22);transform:translateY(-1px);outline:none}.leave-submit:active{transform:translateY(0)}
.leave-note{font-size:.78rem;font-weight:500;line-height:1.45;color:var(--muted)}.alert{padding:12px 14px;margin-bottom:18px;border-radius:10px}.alert.error{background:rgba(252,92,125,.13);color:#ffb3c1}.alert.success{background:rgba(85,214,209,.13);color:#9feee9}
.leave-status{display:inline-flex;align-items:center;justify-content:center;min-height:26px;padding:5px 10px;border-radius:999px;font-size:.75rem;font-weight:700;white-space:nowrap}.leave-status.pending{background:rgba(255,159,67,.15);color:#ffd097;border:1px solid rgba(255,159,67,.22)}.leave-status.approved{background:rgba(85,214,209,.15);color:#9feee9;border:1px solid rgba(85,214,209,.22)}.leave-status.rejected{background:rgba(252,92,125,.15);color:#ffb3c1;border:1px solid rgba(252,92,125,.22)}
.leave-history-wrap{width:100%;max-width:100%;overflow:visible}.leave-history-table{width:100%;min-width:0;margin-bottom:0;table-layout:fixed}.leave-history-table th,.leave-history-table td{box-sizing:border-box;padding:13px 9px;line-height:1.45;vertical-align:middle}.leave-history-table th{text-align:left;white-space:normal}.leave-history-table tbody td{overflow-wrap:break-word}.leave-history-table .leave-type,.leave-history-table .leave-reason{white-space:normal}.leave-history-table .leave-dates{padding-left:6px;padding-right:6px;white-space:nowrap;font-size:.78rem}.leave-history-table .leave-document,.leave-history-table .leave-status-cell{padding-left:4px;padding-right:4px;text-align:center}.leave-history-table .leave-submitted{padding-left:6px;padding-right:6px;white-space:normal;font-variant-numeric:tabular-nums}.leave-history-table .leave-submitted span{display:block;white-space:nowrap}.leave-history-table .leave-remark{padding-left:7px;padding-right:14px;white-space:normal}.leave-history-table th.leave-remark{text-align:center}.leave-history-table .leave-empty{display:flex;width:100%;align-items:center;justify-content:center;text-align:center}.leave-history-table .btn{min-height:34px;max-width:100%;padding:7px 6px;border-radius:8px;font-size:.76rem}
@media(max-width:1050px){.leave-grid{grid-template-columns:1fr}}@media(max-width:767px){.dashboard-shell{display:block}.sidebar{position:relative;min-height:auto}.content-area{padding:18px}.leave-form{gap:15px}.leave-history-wrap{overflow-x:auto}.leave-history-table{min-width:760px}}
</style></head><body><div class="dashboard-shell">
<aside class="sidebar"><div class="sidebar-header"><div class="brand"><div class="brand-icon">S</div><div><h1>SecurePOS</h1><p>Restoran Kencana Sari</p></div></div></div><nav class="sidebar-nav">
<?php if (currentUserRole() === 'Cashier') : ?><a href="pos.php" class="nav-link">Point of Sale</a><a href="inventory_availability.php" class="nav-link">Inventory Availability</a><a href="transaction_history.php" class="nav-link">Transaction History</a><?php else : ?><a href="inventory.php" class="nav-link">Inventory</a><a href="product_expiry.php" class="nav-link">Product Expiry</a><?php endif; ?><a href="leave.php" class="nav-link active">Employee Attendance / Leave</a></nav>
<div class="sidebar-footer"><div class="profile-avatar"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></div><div><p class="profile-name"><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p><p class="profile-role"><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></p><a class="profile-logout" href="change_password.php">Change Password</a> &middot; <a class="profile-logout" href="logout.php">Logout</a></div></div></aside>
<main class="content-area"><header class="topbar"><div class="page-title"><p class="breadcrumb">SecurePOS / Employee Attendance / Leave</p><h2>Employee Attendance / Leave</h2></div><div class="topbar-actions"><div class="topbar-chip secondary"><span id="current-datetime">Loading...</span></div></div></header>
<section class="dashboard-header"><div><h1 class="greeting">Leave applications</h1><p class="intro">Submit a leave request and follow its approval status.</p></div></section>
<?php if ($error !== '') : ?><div class="alert error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?><?php if ($success !== '') : ?><div class="alert success" role="status"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<div class="leave-grid"><section class="panel"><div class="panel-header"><h3>Apply Leave</h3></div><form method="post" enctype="multipart/form-data" class="leave-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['leave_employee_csrf'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="MAX_FILE_SIZE" value="5242880">
<label>Leave Type<select name="leave_type" required><option value="">Select leave type</option><?php foreach (['Annual Leave','Medical Leave','Emergency Leave'] as $type) : ?><option value="<?php echo $type; ?>" <?php echo (($_POST['leave_type'] ?? '') === $type) ? 'selected' : ''; ?>><?php echo $type; ?></option><?php endforeach; ?></select></label>
<label>Start Date<input type="date" name="start_date" value="<?php echo htmlspecialchars((string)($_POST['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></label><label>End Date<input type="date" name="end_date" value="<?php echo htmlspecialchars((string)($_POST['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required></label>
<label>Reason<textarea name="reason" maxlength="1000" required><?php echo htmlspecialchars((string)($_POST['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea></label><label>Supporting Document<input type="file" name="supporting_document" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"><span class="leave-note">Required for Medical Leave. PDF, JPG, JPEG or PNG; maximum 5 MB.</span></label><button type="submit" class="btn btn-primary leave-submit">Submit Leave Application</button></form></section>
<section class="panel"><div class="panel-header"><h3>My Leave History</h3><span><?php echo count($history); ?> request(s)</span></div><div class="table-responsive leave-history-wrap"><table class="table transactions-table leave-history-table"><colgroup><col style="width:14%"><col style="width:25%"><col style="width:14%"><col style="width:12%"><col style="width:11%"><col style="width:14%"><col style="width:10%"></colgroup><thead><tr><th class="leave-type">Leave</th><th class="leave-dates">Dates</th><th class="leave-reason">Reason</th><th class="leave-document">Document</th><th class="leave-status-cell">Status</th><th class="leave-submitted">Submitted</th><th class="leave-remark">Manager Remark</th></tr></thead><tbody><?php if (!$history) : ?><tr><td colspan="7">No leave applications submitted.</td></tr><?php endif; ?><?php foreach ($history as $request) : ?><tr><td class="leave-type"><?php echo htmlspecialchars($request['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td><td class="leave-dates"><?php echo htmlspecialchars($request['start_date'] . ' to ' . $request['end_date'], ENT_QUOTES, 'UTF-8'); ?></td><td class="leave-reason"><?php echo nl2br(htmlspecialchars($request['reason'], ENT_QUOTES, 'UTF-8')); ?></td><td class="leave-document"><?php if ($request['attachment_stored_name']) : ?><a class="btn btn-outline-light" href="leave_document.php?id=<?php echo (int)$request['id']; ?>">Download</a><?php else : ?><span class="leave-empty">&mdash;</span><?php endif; ?></td><td class="leave-status-cell"><span class="leave-status <?php echo leaveStatusClass($request['status']); ?>"><?php echo htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8'); ?></span></td><td class="leave-submitted"><span><?php echo htmlspecialchars(substr($request['submitted_at'], 0, 10), ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo htmlspecialchars(substr($request['submitted_at'], 11), ENT_QUOTES, 'UTF-8'); ?></span></td><td class="leave-remark"><?php echo $request['rejection_reason'] ? nl2br(htmlspecialchars($request['rejection_reason'], ENT_QUOTES, 'UTF-8')) : '<span class="leave-empty">&mdash;</span>'; ?></td></tr><?php endforeach; ?></tbody></table></div></section></div></main></div><script src="assets/js/dashboard.js"></script></body></html>

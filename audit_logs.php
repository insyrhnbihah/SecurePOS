<?php
require_once __DIR__ . '/config/auth.php';
requireManager();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/expiry_notifications.php';

$notifications = getExpiryNotifications($mysqli);
$timezone = new DateTimeZone('Asia/Kuala_Lumpur');
$mysqli->query("SET time_zone = '+08:00'");

$moduleOptions = [
    'all' => 'All Modules',
    'Authentication' => 'Authentication',
    'Users' => 'Users',
    'Inventory' => 'Inventory',
    'POS' => 'POS',
    'Product Expiry' => 'Product Expiry',
    'Attendance' => 'Attendance',
    'Reports' => 'Reports',
];
$resultOptions = [
    'all' => 'All Results',
    'Success' => 'Success',
    'Failed' => 'Failed',
    'Rejected' => 'Rejected',
];
$riskOptions = [
    'all' => 'All Risk Levels',
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
];

function validAuditDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function auditEscape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function auditRiskLevel(string $module, string $action, string $result, ?string $description): string
{
    $normalizedResult = $result === 'Failure' ? 'Failed' : $result;
    $description = $description ?? '';

    if (
        $module === 'Authentication'
        && $action === 'Login'
        && $normalizedResult === 'Rejected'
        && in_array($description, [
            'Password login temporarily locked after repeated failed attempts.',
            'Password login rejected during temporary lockout.',
        ], true)
    ) {
        return 'HIGH';
    }
    if ($module === 'Users' && $action === 'Reset Password' && $normalizedResult === 'Success') {
        return 'HIGH';
    }
    if ($module === 'Users' && $action === 'Update User' && $normalizedResult === 'Rejected') {
        return 'HIGH';
    }

    $mediumEvents = [
        ['Authentication', 'Login', 'Failed'],
        ['Authentication', 'Login', 'Rejected'],
        ['Authentication', 'Face Login', 'Rejected'],
        ['Authentication', 'Change Password', 'Success'],
        ['Users', 'Create User', 'Success'],
        ['Users', 'Update User', 'Success'],
        ['Users', 'Enroll Face', 'Success'],
        ['Inventory', 'Delete Product', 'Success'],
        ['POS', 'Complete Sale', 'Failed'],
    ];
    if (in_array([$module, $action, $normalizedResult], $mediumEvents, true)) {
        return 'MEDIUM';
    }

    $lowEvents = [
        ['Authentication', 'Login', 'Success'],
        ['Authentication', 'Face Login', 'Success'],
        ['Authentication', 'Logout', 'Success'],
        ['Inventory', 'Create Product', 'Success'],
        ['Inventory', 'Update Product', 'Success'],
        ['POS', 'Complete Sale', 'Success'],
    ];
    if (in_array([$module, $action, $normalizedResult], $lowEvents, true)) {
        return 'LOW';
    }

    return 'MEDIUM';
}

$startInput = trim((string)($_GET['start_date'] ?? ''));
$endInput = trim((string)($_GET['end_date'] ?? ''));
$actorSearch = mb_substr(trim((string)($_GET['actor'] ?? '')), 0, 100, 'UTF-8');
$moduleFilter = trim((string)($_GET['module'] ?? 'all'));
$resultFilter = trim((string)($_GET['result'] ?? 'all'));
$riskFilter = strtolower(trim((string)($_GET['risk'] ?? 'all')));
$filterNotice = '';

$startDate = $startInput === '' ? null : validAuditDate($startInput, $timezone);
$endDate = $endInput === '' ? null : validAuditDate($endInput, $timezone);
if (($startInput !== '' && !$startDate) || ($endInput !== '' && !$endDate)) {
    $startDate = null;
    $endDate = null;
    $startInput = '';
    $endInput = '';
    $filterNotice = 'Invalid dates were cleared. Use dates in YYYY-MM-DD format.';
} elseif ($startDate && $endDate && $startDate > $endDate) {
    $startDate = null;
    $endDate = null;
    $startInput = '';
    $endInput = '';
    $filterNotice = 'Start Date cannot be later than End Date. The date filters were cleared.';
}
if (!array_key_exists($moduleFilter, $moduleOptions)) {
    $moduleFilter = 'all';
    $filterNotice = $filterNotice ?: 'Invalid module filter was reset to All Modules.';
}
if (!array_key_exists($resultFilter, $resultOptions)) {
    $resultFilter = 'all';
    $filterNotice = $filterNotice ?: 'Invalid result filter was reset to All Results.';
}
if (!array_key_exists($riskFilter, $riskOptions)) {
    $riskFilter = 'all';
    $filterNotice = $filterNotice ?: 'Invalid risk filter was reset to All Risk Levels.';
}

$startSql = $startDate ? $startDate->format('Y-m-d 00:00:00') : '';
$endSql = $endDate ? $endDate->modify('+1 day')->format('Y-m-d 00:00:00') : '';
$actorPattern = '%' . $actorSearch . '%';

$sql = "SELECT created_at, actor_name, actor_role, module, action, result, description
    FROM audit_logs
    WHERE (? = '' OR created_at >= ?)
      AND (? = '' OR created_at < ?)
      AND (? = '' OR actor_name LIKE ?)
      AND (? = 'all' OR module = ?)
      AND (? = 'all' OR result = ? OR (? = 'Failed' AND result = 'Failure'))
    ORDER BY created_at DESC, id DESC";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param(
    'sssssssssss',
    $startSql,
    $startSql,
    $endSql,
    $endSql,
    $actorSearch,
    $actorPattern,
    $moduleFilter,
    $moduleFilter,
    $resultFilter,
    $resultFilter,
    $resultFilter
);
$stmt->execute();
$result = $stmt->get_result();
$auditRows = [];
while ($row = $result->fetch_assoc()) {
    $row['risk_level'] = auditRiskLevel($row['module'], $row['action'], $row['result'], $row['description']);
    if ($riskFilter === 'all' || strtolower($row['risk_level']) === $riskFilter) {
        $auditRows[] = $row;
    }
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | SecurePOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260829-filters">
    <style>
        .audit-heading { margin-bottom: 18px; }
        .audit-heading h1 { margin: 0 0 7px; font-size: 1.55rem; }
        .audit-heading p { margin: 0; color: var(--muted); }
        .audit-filter { display: grid; grid-template-columns: repeat(2, minmax(145px, 180px)) minmax(180px, 1fr) minmax(145px, 185px) repeat(2, minmax(130px, 155px)) auto auto; gap: 11px; align-items: end; }
        .audit-field { display: grid; gap: 7px; }
        .audit-field label { color: var(--muted); font-size: .82rem; font-weight: 600; }
        .audit-field .form-control, .audit-field .form-select { min-height: 40px; border-color: rgba(255,255,255,.09); background: rgba(255,255,255,.04); color: var(--text); }
        .audit-field .form-select option { background: #101d2d; color: var(--text); }
        .audit-notice { margin: 12px 0 0; color: #ffd097; font-size: .84rem; }
        .audit-table { width: 100%; margin-bottom: 0; table-layout: auto; border-collapse: collapse; }
        .audit-table th, .audit-table td { padding: 13px 14px; vertical-align: middle; text-align: left; border: 0; }
        .audit-table thead th { color: var(--muted); white-space: nowrap; background: rgba(255,255,255,.018); border-bottom: 1px solid rgba(120,167,192,.18); }
        .audit-table tbody td { border-bottom: 1px solid rgba(120,167,192,.1); }
        .audit-table tbody tr:last-child td { border-bottom: 0; }
        .audit-table tbody tr:hover td { background: rgba(85,214,209,.025); }
        .audit-table th:first-child, .audit-table td:first-child,
        .audit-table th:nth-child(6), .audit-table td:nth-child(6),
        .audit-table th:nth-child(7), .audit-table td:nth-child(7) { width: 1%; white-space: nowrap; }
        .audit-table th:nth-child(2), .audit-table td:nth-child(2),
        .audit-table th:nth-child(3), .audit-table td:nth-child(3),
        .audit-table th:nth-child(4), .audit-table td:nth-child(4),
        .audit-table th:nth-child(5), .audit-table td:nth-child(5) { white-space: nowrap; }
        .audit-table th:nth-child(6), .audit-table td:nth-child(6),
        .audit-table th:nth-child(7), .audit-table td:nth-child(7) { text-align: center; }
        .audit-table th:last-child, .audit-table td:last-child { width: 100%; }
        .audit-datetime { display: inline-flex; flex-direction: column; line-height: 1.3; white-space: nowrap; }
        .audit-datetime-time { color: var(--muted); font-size: .79rem; }
        .audit-description { overflow-wrap: anywhere; line-height: 1.4; }
        .result-badge { display: inline-flex; min-width: 76px; justify-content: center; padding: 5px 10px; border-radius: 999px; font-size: .76rem; font-weight: 700; }
        .result-success { background: rgba(45,247,181,.14); color: #78f7cb; }
        .result-failed { background: rgba(252,92,125,.16); color: #ff9cb0; }
        .result-rejected { background: rgba(255,159,67,.16); color: #ffc083; }
        .risk-badge { display: inline-flex; min-width: 72px; justify-content: center; padding: 5px 10px; border-radius: 999px; font-size: .73rem; font-weight: 800; letter-spacing: .03em; }
        .risk-low { background: rgba(45,247,181,.12); color: #78f7cb; }
        .risk-medium { background: rgba(255,190,71,.15); color: #ffd07a; }
        .risk-high { background: rgba(252,92,125,.16); color: #ff9cb0; }
        .audit-empty { padding: 28px 12px !important; color: var(--muted) !important; text-align: center; }
        .audit-page .sidebar-nav .nav-link:not(.active) { background: transparent; color: var(--muted); }
        .audit-page .sidebar-nav .nav-link:not(.active):hover { background: rgba(85,214,209,.14); color: var(--text); }
        @media (max-width: 1250px) { .audit-filter { grid-template-columns: repeat(3, minmax(180px, 1fr)); } }
        @media (max-width: 760px) { .audit-filter { grid-template-columns: 1fr; } .audit-filter .btn { width: 100%; } }
    </style>
</head>
<body class="audit-page">
    <div class="dashboard-shell">
        <aside class="sidebar">
            <div class="sidebar-header"><div class="brand"><div class="brand-icon">S</div><div><h1>SecurePOS</h1><p>Restoran Kencana Sari</p></div></div></div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="pos.php" class="nav-link">Point of Sale</a>
                <a href="inventory.php" class="nav-link">Inventory</a>
                <a href="product_expiry.php" class="nav-link">Product Expiry</a>
                <a href="attendance.php" class="nav-link">Employee Attendance</a>
                <a href="users.php" class="nav-link">Users</a>
                <a href="reports.php" class="nav-link">Reports</a>
                <a href="audit_logs.php" class="nav-link active" aria-current="page">Audit Logs</a>
            </nav>
            <div class="sidebar-footer"><div class="profile-avatar"><?php echo auditEscape(currentUserInitials()); ?></div><div><p class="profile-name"><?php echo auditEscape(currentUserName()); ?></p><p class="profile-role"><?php echo auditEscape(currentUserRole()); ?></p><a class="profile-logout" href="logout.php">Logout</a></div></div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title"><p class="breadcrumb">SecurePOS / Audit Logs</p><h2>Audit Logs</h2></div>
                <div class="topbar-actions"><div class="topbar-chip secondary"><span id="current-datetime">Loading...</span></div><?php echo renderExpiryNotificationBell($notifications, 'product_expiry.php'); ?><div class="topbar-profile"><span class="profile-initials"><?php echo auditEscape(currentUserInitials()); ?></span><div><p><?php echo auditEscape(currentUserName()); ?></p><small><?php echo auditEscape(currentUserRole()); ?></small></div></div></div>
            </header>

            <section class="audit-heading"><h1>General Audit Logs</h1><p>Review read-only operational events recorded by SecurePOS.</p></section>

            <section class="panel">
                <div class="panel-header"><h3>Audit Filters</h3></div>
                <form method="get" class="audit-filter securepos-filter">
                    <div class="audit-field"><label for="start-date">Start Date</label><input type="date" class="form-control" id="start-date" name="start_date" value="<?php echo auditEscape($startInput); ?>"></div>
                    <div class="audit-field"><label for="end-date">End Date</label><input type="date" class="form-control" id="end-date" name="end_date" value="<?php echo auditEscape($endInput); ?>"></div>
                    <div class="audit-field"><label for="actor">Actor Search</label><input type="search" class="form-control" id="actor" name="actor" maxlength="100" value="<?php echo auditEscape($actorSearch); ?>" placeholder="Actor name"></div>
                    <div class="audit-field"><label for="module">Module</label><select class="form-select" id="module" name="module"><?php foreach ($moduleOptions as $value => $label) : ?><option value="<?php echo auditEscape($value); ?>" <?php echo $moduleFilter === $value ? 'selected' : ''; ?>><?php echo auditEscape($label); ?></option><?php endforeach; ?></select></div>
                    <div class="audit-field"><label for="result">Result</label><select class="form-select" id="result" name="result"><?php foreach ($resultOptions as $value => $label) : ?><option value="<?php echo auditEscape($value); ?>" <?php echo $resultFilter === $value ? 'selected' : ''; ?>><?php echo auditEscape($label); ?></option><?php endforeach; ?></select></div>
                    <div class="audit-field"><label for="risk">Risk Level</label><select class="form-select" id="risk" name="risk"><?php foreach ($riskOptions as $value => $label) : ?><option value="<?php echo auditEscape($value); ?>" <?php echo $riskFilter === $value ? 'selected' : ''; ?>><?php echo auditEscape($label); ?></option><?php endforeach; ?></select></div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="audit_logs.php" class="btn btn-outline-light">Reset</a>
                </form>
                <?php if ($filterNotice !== '') : ?><p class="audit-notice" role="status"><?php echo auditEscape($filterNotice); ?></p><?php endif; ?>
            </section>

            <section class="panel">
                <div class="panel-header"><h3>Recorded Events</h3><span><?php echo count($auditRows); ?> result<?php echo count($auditRows) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive">
                    <table class="table audit-table">
                        <thead><tr><th>Date &amp; Time</th><th>Actor</th><th>Role</th><th>Module</th><th>Action</th><th>Result</th><th>Risk Level</th><th>Description</th></tr></thead>
                        <tbody>
                        <?php if (!$auditRows) : ?>
                            <tr><td colspan="8" class="audit-empty">No audit records found for the selected filters.</td></tr>
                        <?php else : ?>
                            <?php foreach ($auditRows as $row) : ?>
                                <?php
                                $displayResult = $row['result'] === 'Failure' ? 'Failed' : $row['result'];
                                $badgeClass = $displayResult === 'Success' ? 'result-success' : ($displayResult === 'Rejected' ? 'result-rejected' : 'result-failed');
                                $riskBadgeClass = 'risk-' . strtolower($row['risk_level']);
                                ?>
                                <tr>
                                    <?php $eventDateTime = new DateTimeImmutable($row['created_at'], $timezone); ?>
                                    <td><span class="audit-datetime"><span><?php echo auditEscape($eventDateTime->format('d M Y')); ?></span><span class="audit-datetime-time"><?php echo auditEscape($eventDateTime->format('h:i A')); ?></span></span></td>
                                    <td><?php echo auditEscape($row['actor_name'] ?: 'System'); ?></td>
                                    <td><?php echo auditEscape($row['actor_role'] ?: 'System'); ?></td>
                                    <td><?php echo auditEscape($row['module']); ?></td>
                                    <td><?php echo auditEscape($row['action']); ?></td>
                                    <td><span class="result-badge <?php echo $badgeClass; ?>"><?php echo auditEscape($displayResult); ?></span></td>
                                    <td><span class="risk-badge <?php echo auditEscape($riskBadgeClass); ?>"><?php echo auditEscape($row['risk_level']); ?></span></td>
                                    <td class="audit-description"><?php echo auditEscape($row['description'] ?: 'No description provided.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>

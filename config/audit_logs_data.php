<?php
// Shared existing Audit Logs filters, risk classification and read-only query.
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

<?php
// Shared read-only report queries and filters for the page and PDF export.
$timezone = new DateTimeZone('Asia/Kuala_Lumpur');
$mysqli->query("SET time_zone = '+08:00'");
$reportOptions = ['sales', 'inventory', 'expiry', 'attendance'];
$activeReport = trim((string)($_GET['report'] ?? 'sales'));
if (!in_array($activeReport, $reportOptions, true)) {
    $activeReport = 'sales';
}

function validReportDate($value, DateTimeZone $timezone)
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value, $timezone);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function reportMoney($value)
{
    return 'RM ' . number_format((float)$value, 2);
}

function reportWorkingMinutes($minutes)
{
    if ($minutes === null) {
        return '—';
    }

    $minutes = (int)round((float)$minutes);
    return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
}

$latestStmt = $mysqli->prepare('SELECT MAX(DATE(created_at)) AS latest_date FROM sales');
$latestStmt->execute();
$latestRow = $latestStmt->get_result()->fetch_assoc();
$latestStmt->close();
$latestDate = validReportDate($latestRow['latest_date'] ?? '', $timezone) ?: new DateTimeImmutable('today', $timezone);
$defaultEndDate = $latestDate;
$defaultStartDate = $latestDate->modify('-6 days');

$startInput = trim((string)($_GET['start_date'] ?? ''));
$endInput = trim((string)($_GET['end_date'] ?? ''));
$paymentFilter = trim((string)($_GET['payment'] ?? 'all'));
$paymentOptions = ['all' => 'All', 'cash' => 'Cash', 'qr' => 'QR'];
$paymentDatabaseValues = ['all' => 'all', 'cash' => 'Cash', 'qr' => 'QR Payment'];
$filterNotice = '';

$startDate = $startInput === '' ? $defaultStartDate : validReportDate($startInput, $timezone);
$endDate = $endInput === '' ? $defaultEndDate : validReportDate($endInput, $timezone);
if (!$startDate || !$endDate || $startDate > $endDate) {
    $startDate = $defaultStartDate;
    $endDate = $defaultEndDate;
    $filterNotice = 'Invalid date range was replaced with the latest available seven-day period.';
}
if (!array_key_exists($paymentFilter, $paymentOptions)) {
    $paymentFilter = 'all';
    $filterNotice = $filterNotice ?: 'Invalid payment filter was reset to All.';
}

$startSql = $startDate->format('Y-m-d 00:00:00');
$endExclusiveSql = $endDate->modify('+1 day')->format('Y-m-d 00:00:00');
$paymentValue = $paymentDatabaseValues[$paymentFilter];

$summarySql = "SELECT
        COALESCE(SUM(total_amount), 0) AS total_revenue,
        COUNT(*) AS total_transactions,
        COALESCE(AVG(total_amount), 0) AS average_transaction,
        COALESCE(SUM(CASE WHEN payment_method = 'Cash' THEN total_amount ELSE 0 END), 0) AS cash_sales,
        COALESCE(SUM(CASE WHEN payment_method = 'QR Payment' THEN total_amount ELSE 0 END), 0) AS qr_sales
    FROM sales
    WHERE created_at >= ? AND created_at < ?
      AND (? = 'all' OR payment_method = ?)";
$summaryStmt = $mysqli->prepare($summarySql);
$summaryStmt->bind_param('ssss', $startSql, $endExclusiveSql, $paymentValue, $paymentValue);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$transactionsSql = "SELECT receipt_no, created_at, payment_method, order_type, table_number, total_amount
    FROM sales
    WHERE created_at >= ? AND created_at < ?
      AND (? = 'all' OR payment_method = ?)
    ORDER BY created_at DESC, id DESC";
$transactionsStmt = $mysqli->prepare($transactionsSql);
$transactionsStmt->bind_param('ssss', $startSql, $endExclusiveSql, $paymentValue, $paymentValue);
$transactionsStmt->execute();
$transactionsResult = $transactionsStmt->get_result();
$transactions = [];
while ($row = $transactionsResult->fetch_assoc()) {
    $transactions[] = $row;
}
$transactionsStmt->close();

$itemsSql = "SELECT si.item_name, SUM(si.quantity) AS quantity_sold, SUM(si.subtotal) AS revenue
    FROM sale_items si
    INNER JOIN sales s ON s.id = si.sale_id
    INNER JOIN menu_items mi ON mi.id = si.menu_item_id
    WHERE s.created_at >= ? AND s.created_at < ?
      AND (? = 'all' OR s.payment_method = ?)
    GROUP BY si.menu_item_id, si.item_name
    ORDER BY quantity_sold DESC, revenue DESC, si.item_name ASC";
$itemsStmt = $mysqli->prepare($itemsSql);
$itemsStmt->bind_param('ssss', $startSql, $endExclusiveSql, $paymentValue, $paymentValue);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$topItems = [];
while ($row = $itemsResult->fetch_assoc()) {
    $topItems[] = $row;
}
$itemsStmt->close();

$inventorySearch = '';
$inventoryCategory = '';
$inventoryStatusFilter = 'all';
$inventoryStatusOptions = [
    'all' => 'All',
    'in_stock' => 'In Stock',
    'low_stock' => 'Low Stock',
    'out_of_stock' => 'Out of Stock',
];
$inventoryStatusValues = [
    'all' => '',
    'in_stock' => 'In Stock',
    'low_stock' => 'Low Stock',
    'out_of_stock' => 'Out of Stock',
];
$inventoryCategories = [];
$inventorySummary = ['total_products' => 0, 'in_stock' => 0, 'low_stock' => 0, 'out_of_stock' => 0];
$inventoryRows = [];
$inventoryNotice = '';

if ($activeReport === 'inventory') {
    $categoryStmt = $mysqli->prepare('SELECT DISTINCT category FROM inventory ORDER BY category ASC');
    $categoryStmt->execute();
    $categoryResult = $categoryStmt->get_result();
    while ($categoryRow = $categoryResult->fetch_assoc()) {
        $inventoryCategories[] = $categoryRow['category'];
    }
    $categoryStmt->close();

    $inventorySearch = trim((string)($_GET['search'] ?? ''));
    $inventoryCategory = trim((string)($_GET['category'] ?? ''));
    $inventoryStatusFilter = trim((string)($_GET['stock_status'] ?? 'all'));

    if ($inventoryCategory !== '' && !in_array($inventoryCategory, $inventoryCategories, true)) {
        $inventoryCategory = '';
        $inventoryNotice = 'Invalid category filter was reset to All Categories.';
    }
    if (!array_key_exists($inventoryStatusFilter, $inventoryStatusOptions)) {
        $inventoryStatusFilter = 'all';
        $inventoryNotice = $inventoryNotice ?: 'Invalid stock status filter was reset to All.';
    }

    $searchPattern = '%' . $inventorySearch . '%';
    $statusValue = $inventoryStatusValues[$inventoryStatusFilter];
    $inventoryWhere = " FROM inventory
        WHERE (? = '' OR product_id LIKE ? OR product_name LIKE ?)
          AND (? = '' OR category = ?)
          AND (? = '' OR stock_status = ?)";

    $inventorySummarySql = "SELECT
            COUNT(*) AS total_products,
            COALESCE(SUM(stock_status = 'In Stock'), 0) AS in_stock,
            COALESCE(SUM(stock_status = 'Low Stock'), 0) AS low_stock,
            COALESCE(SUM(stock_status = 'Out of Stock'), 0) AS out_of_stock" . $inventoryWhere;
    $inventorySummaryStmt = $mysqli->prepare($inventorySummarySql);
    $inventorySummaryStmt->bind_param('sssssss', $inventorySearch, $searchPattern, $searchPattern, $inventoryCategory, $inventoryCategory, $statusValue, $statusValue);
    $inventorySummaryStmt->execute();
    $inventorySummary = $inventorySummaryStmt->get_result()->fetch_assoc();
    $inventorySummaryStmt->close();

    $inventorySql = "SELECT product_id, product_name, category, current_stock, unit, stock_status" . $inventoryWhere . "
        ORDER BY product_name ASC, product_id ASC";
    $inventoryStmt = $mysqli->prepare($inventorySql);
    $inventoryStmt->bind_param('sssssss', $inventorySearch, $searchPattern, $searchPattern, $inventoryCategory, $inventoryCategory, $statusValue, $statusValue);
    $inventoryStmt->execute();
    $inventoryResult = $inventoryStmt->get_result();
    while ($inventoryRow = $inventoryResult->fetch_assoc()) {
        $inventoryRows[] = $inventoryRow;
    }
    $inventoryStmt->close();
}

$expirySearch = '';
$expiryCategory = '';
$expiryStatusFilter = 'all';
$expiryStatusOptions = [
    'all' => 'All',
    'expired' => 'Expired',
    'within_7' => 'Expiring Within 7 Days',
    'within_30' => 'Expiring Within 30 Days',
    'valid' => 'Valid',
];
$expiryStatusValues = [
    'all' => 'all',
    'expired' => 'Expired',
    'within_7' => 'Within 7 Days',
    'within_30' => 'Within 30 Days',
    'valid' => 'Valid',
];
$expiryCategories = [];
$expirySummary = ['total_batches' => 0, 'expired' => 0, 'within_7' => 0, 'within_30' => 0, 'valid' => 0];
$expiryRows = [];
$expiryNotice = '';

if ($activeReport === 'expiry') {
    $expiryCategoryStmt = $mysqli->prepare('SELECT DISTINCT i.category FROM product_expiry pe INNER JOIN inventory i ON i.id = pe.inventory_id ORDER BY i.category ASC');
    $expiryCategoryStmt->execute();
    $expiryCategoryResult = $expiryCategoryStmt->get_result();
    while ($expiryCategoryRow = $expiryCategoryResult->fetch_assoc()) {
        $expiryCategories[] = $expiryCategoryRow['category'];
    }
    $expiryCategoryStmt->close();

    $expirySearch = trim((string)($_GET['search'] ?? ''));
    $expiryCategory = trim((string)($_GET['category'] ?? ''));
    $expiryStatusFilter = trim((string)($_GET['expiry_status'] ?? 'all'));

    if ($expiryCategory !== '' && !in_array($expiryCategory, $expiryCategories, true)) {
        $expiryCategory = '';
        $expiryNotice = 'Invalid category filter was reset to All Categories.';
    }
    if (!array_key_exists($expiryStatusFilter, $expiryStatusOptions)) {
        $expiryStatusFilter = 'all';
        $expiryNotice = $expiryNotice ?: 'Invalid expiry status filter was reset to All.';
    }

    $today = new DateTimeImmutable('today', $timezone);
    $todaySql = $today->format('Y-m-d');
    $withinSevenSql = $today->modify('+7 days')->format('Y-m-d');
    $withinThirtySql = $today->modify('+30 days')->format('Y-m-d');
    $expirySearchPattern = '%' . $expirySearch . '%';
    $expiryStatusValue = $expiryStatusValues[$expiryStatusFilter];

    $expiryBaseSql = "SELECT i.product_id, i.product_name, i.category, pe.batch_no, pe.quantity, i.unit, pe.expiry_date,
            CASE
                WHEN pe.expiry_date < ? THEN 'Expired'
                WHEN pe.expiry_date <= ? THEN 'Within 7 Days'
                WHEN pe.expiry_date <= ? THEN 'Within 30 Days'
                ELSE 'Valid'
            END AS expiry_status
        FROM product_expiry pe
        INNER JOIN inventory i ON i.id = pe.inventory_id
        WHERE (? = '' OR i.product_id LIKE ? OR i.product_name LIKE ?)
          AND (? = '' OR i.category = ?)";
    $expiryFilteredSql = " FROM (" . $expiryBaseSql . ") AS batches
        WHERE (? = 'all' OR expiry_status = ?)";

    $expirySummarySql = "SELECT COUNT(*) AS total_batches,
            COALESCE(SUM(expiry_status = 'Expired'), 0) AS expired,
            COALESCE(SUM(expiry_status = 'Within 7 Days'), 0) AS within_7,
            COALESCE(SUM(expiry_status = 'Within 30 Days'), 0) AS within_30,
            COALESCE(SUM(expiry_status = 'Valid'), 0) AS valid" . $expiryFilteredSql;
    $expirySummaryStmt = $mysqli->prepare($expirySummarySql);
    $expirySummaryStmt->bind_param('ssssssssss', $todaySql, $withinSevenSql, $withinThirtySql, $expirySearch, $expirySearchPattern, $expirySearchPattern, $expiryCategory, $expiryCategory, $expiryStatusValue, $expiryStatusValue);
    $expirySummaryStmt->execute();
    $expirySummary = $expirySummaryStmt->get_result()->fetch_assoc();
    $expirySummaryStmt->close();

    $expirySql = "SELECT product_id, product_name, category, batch_no, quantity, unit, expiry_date, expiry_status" . $expiryFilteredSql . "
        ORDER BY expiry_date ASC, product_name ASC, batch_no ASC";
    $expiryStmt = $mysqli->prepare($expirySql);
    $expiryStmt->bind_param('ssssssssss', $todaySql, $withinSevenSql, $withinThirtySql, $expirySearch, $expirySearchPattern, $expirySearchPattern, $expiryCategory, $expiryCategory, $expiryStatusValue, $expiryStatusValue);
    $expiryStmt->execute();
    $expiryResult = $expiryStmt->get_result();
    while ($expiryRow = $expiryResult->fetch_assoc()) {
        $expiryRows[] = $expiryRow;
    }
    $expiryStmt->close();
}

$attendanceRoleOptions = ['all' => 'All Roles', 'cashier' => 'Cashier', 'inventory_staff' => 'Inventory Staff'];
$attendanceRoleValues = ['all' => 'all', 'cashier' => 'Cashier', 'inventory_staff' => 'Inventory Staff'];
$attendanceStatusOptions = ['all' => 'All', 'present' => 'Present'];
$attendanceStatusValues = ['all' => 'all', 'present' => 'Present'];
$attendanceSearch = '';
$attendanceRoleFilter = 'all';
$attendanceStatusFilter = 'all';
$attendanceSummary = ['attendance_records' => 0, 'completed_shifts' => 0, 'missing_checkout' => 0, 'present_employees' => 0];
$attendanceRows = [];
$monthlyAttendanceRows = [];
$absentEmployees = 0;
$attendanceNotice = '';
$attendanceSingleDate = false;

if ($activeReport === 'attendance') {
    $latestAttendanceStmt = $mysqli->prepare('SELECT MAX(attendance_date) AS latest_date FROM attendance_records');
    $latestAttendanceStmt->execute();
    $latestAttendanceRow = $latestAttendanceStmt->get_result()->fetch_assoc();
    $latestAttendanceStmt->close();
    $latestAttendanceDate = validReportDate($latestAttendanceRow['latest_date'] ?? '', $timezone) ?: new DateTimeImmutable('today', $timezone);
    $defaultAttendanceEndDate = $latestAttendanceDate;
    $defaultAttendanceStartDate = $latestAttendanceDate->modify('-6 days');

    $attendanceStartInput = trim((string)($_GET['start_date'] ?? ''));
    $attendanceEndInput = trim((string)($_GET['end_date'] ?? ''));
    $attendanceSearch = trim((string)($_GET['employee_search'] ?? ''));
    $attendanceRoleFilter = trim((string)($_GET['role'] ?? 'all'));
    $attendanceStatusFilter = trim((string)($_GET['attendance_status'] ?? 'all'));

    $attendanceStartDate = $attendanceStartInput === '' ? $defaultAttendanceStartDate : validReportDate($attendanceStartInput, $timezone);
    $attendanceEndDate = $attendanceEndInput === '' ? $defaultAttendanceEndDate : validReportDate($attendanceEndInput, $timezone);
    if (!$attendanceStartDate || !$attendanceEndDate || $attendanceStartDate > $attendanceEndDate) {
        $attendanceStartDate = $defaultAttendanceStartDate;
        $attendanceEndDate = $defaultAttendanceEndDate;
        $attendanceNotice = 'Invalid date range was replaced with the latest available seven-day period.';
    }
    if (!array_key_exists($attendanceRoleFilter, $attendanceRoleOptions)) {
        $attendanceRoleFilter = 'all';
        $attendanceNotice = $attendanceNotice ?: 'Invalid role filter was reset to All Roles.';
    }
    if (!array_key_exists($attendanceStatusFilter, $attendanceStatusOptions)) {
        $attendanceStatusFilter = 'all';
        $attendanceNotice = $attendanceNotice ?: 'Invalid attendance status filter was reset to All.';
    }

    $attendanceStartSql = $attendanceStartDate->format('Y-m-d');
    $attendanceEndSql = $attendanceEndDate->format('Y-m-d');
    $attendanceSingleDate = $attendanceStartSql === $attendanceEndSql;
    $attendanceSearchPattern = '%' . $attendanceSearch . '%';
    $attendanceRoleValue = $attendanceRoleValues[$attendanceRoleFilter];
    $attendanceStatusValue = $attendanceStatusValues[$attendanceStatusFilter];
    $attendanceWhereSql = " FROM attendance_records ar
        INNER JOIN employees e ON e.id = ar.employee_id
        WHERE ar.attendance_date >= ? AND ar.attendance_date <= ?
          AND (? = '' OR e.employee_code LIKE ? OR e.full_name LIKE ?)
          AND (? = 'all' OR e.role = ?)
          AND (? = 'all' OR ar.status = ?)";

    $attendanceSummarySql = "SELECT COUNT(*) AS attendance_records,
            COALESCE(SUM(ar.check_in IS NOT NULL AND ar.check_out IS NOT NULL), 0) AS completed_shifts,
            COALESCE(SUM(ar.check_in IS NOT NULL AND ar.check_out IS NULL), 0) AS missing_checkout,
            COUNT(DISTINCT ar.employee_id) AS present_employees" . $attendanceWhereSql;
    $attendanceSummaryStmt = $mysqli->prepare($attendanceSummarySql);
    $attendanceSummaryStmt->bind_param('sssssssss', $attendanceStartSql, $attendanceEndSql, $attendanceSearch, $attendanceSearchPattern, $attendanceSearchPattern, $attendanceRoleValue, $attendanceRoleValue, $attendanceStatusValue, $attendanceStatusValue);
    $attendanceSummaryStmt->execute();
    $attendanceSummary = $attendanceSummaryStmt->get_result()->fetch_assoc();
    $attendanceSummaryStmt->close();

    $monthlyAttendanceSql = "SELECT e.employee_code, e.full_name,
            DATE_FORMAT(ar.attendance_date, '%Y-%m') AS attendance_month,
            COUNT(DISTINCT CASE WHEN ar.check_in IS NOT NULL THEN ar.attendance_date END) AS days_present,
            SUM(CASE
                WHEN ar.check_in IS NOT NULL AND ar.check_out IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, ar.check_in, ar.check_out)
                ELSE NULL
            END) AS total_working_minutes" . $attendanceWhereSql . "
        GROUP BY e.id, e.employee_code, e.full_name, DATE_FORMAT(ar.attendance_date, '%Y-%m')
        ORDER BY attendance_month DESC, e.full_name ASC, e.employee_code ASC";
    $monthlyAttendanceStmt = $mysqli->prepare($monthlyAttendanceSql);
    $monthlyAttendanceStmt->bind_param('sssssssss', $attendanceStartSql, $attendanceEndSql, $attendanceSearch, $attendanceSearchPattern, $attendanceSearchPattern, $attendanceRoleValue, $attendanceRoleValue, $attendanceStatusValue, $attendanceStatusValue);
    $monthlyAttendanceStmt->execute();
    $monthlyAttendanceResult = $monthlyAttendanceStmt->get_result();
    while ($monthlyAttendanceRow = $monthlyAttendanceResult->fetch_assoc()) {
        $monthlyAttendanceRows[] = $monthlyAttendanceRow;
    }
    $monthlyAttendanceStmt->close();

    $attendanceSql = "SELECT e.employee_code, e.full_name, e.role, ar.attendance_date, ar.check_in, ar.check_out,
            TIMESTAMPDIFF(MINUTE, ar.check_in, ar.check_out) AS working_minutes, ar.status" . $attendanceWhereSql . "
        ORDER BY ar.attendance_date DESC, COALESCE(ar.check_in, ar.created_at) DESC, e.full_name ASC";
    $attendanceStmt = $mysqli->prepare($attendanceSql);
    $attendanceStmt->bind_param('sssssssss', $attendanceStartSql, $attendanceEndSql, $attendanceSearch, $attendanceSearchPattern, $attendanceSearchPattern, $attendanceRoleValue, $attendanceRoleValue, $attendanceStatusValue, $attendanceStatusValue);
    $attendanceStmt->execute();
    $attendanceResult = $attendanceStmt->get_result();
    while ($attendanceRow = $attendanceResult->fetch_assoc()) {
        $attendanceRows[] = $attendanceRow;
    }
    $attendanceStmt->close();

    if ($attendanceSingleDate) {
        $absentSql = "SELECT COUNT(*) AS absent_employees
            FROM employees e
            INNER JOIN users u ON u.id = e.user_id
            WHERE e.account_status = 'Active' AND u.account_status = 'Active'
              AND e.role IN ('Cashier', 'Inventory Staff') AND u.role = e.role
              AND (? = '' OR e.employee_code LIKE ? OR e.full_name LIKE ?)
              AND (? = 'all' OR e.role = ?)
              AND NOT EXISTS (
                  SELECT 1 FROM attendance_records ar
                  WHERE ar.employee_id = e.id AND ar.attendance_date = ?
              )";
        $absentStmt = $mysqli->prepare($absentSql);
        $absentStmt->bind_param('ssssss', $attendanceSearch, $attendanceSearchPattern, $attendanceSearchPattern, $attendanceRoleValue, $attendanceRoleValue, $attendanceStartSql);
        $absentStmt->execute();
        $absentEmployees = (int)$absentStmt->get_result()->fetch_assoc()['absent_employees'];
        $absentStmt->close();
    }
}

<?php
// Read-only system transaction history for authenticated Cashiers.
require_once __DIR__ . '/config/auth.php';
requireCashier();
require_once __DIR__ . '/config/database.php';

function historyEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function historyValidDate($value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', (string)$value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$startDate = trim((string)($_GET['start_date'] ?? ''));
$endDate = trim((string)($_GET['end_date'] ?? ''));
$paymentMethod = trim((string)($_GET['payment_method'] ?? ''));
$orderType = trim((string)($_GET['order_type'] ?? ''));
$receiptSearch = mb_substr(trim((string)($_GET['receipt_search'] ?? '')), 0, 50, 'UTF-8');
$selectedReceipt = mb_substr(trim((string)($_GET['receipt'] ?? '')), 0, 50, 'UTF-8');
$allowedPaymentMethods = ['Cash', 'QR Payment'];
$allowedOrderTypes = ['Dine In', 'Take Away'];

if ($startDate !== '' && !historyValidDate($startDate)) {
    $startDate = '';
}
if ($endDate !== '' && !historyValidDate($endDate)) {
    $endDate = '';
}
if ($paymentMethod !== '' && !in_array($paymentMethod, $allowedPaymentMethods, true)) {
    $paymentMethod = '';
}
if ($orderType !== '' && !in_array($orderType, $allowedOrderTypes, true)) {
    $orderType = '';
}

$sql = 'SELECT receipt_no, created_at, payment_method, order_type, table_number, total_amount FROM sales';
$conditions = [];
$params = [];
$types = '';

if ($startDate !== '') {
    $conditions[] = 'created_at >= ?';
    $params[] = $startDate . ' 00:00:00';
    $types .= 's';
}
if ($endDate !== '') {
    $endBoundary = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $conditions[] = 'created_at < ?';
    $params[] = $endBoundary;
    $types .= 's';
}
if ($paymentMethod !== '') {
    $conditions[] = 'payment_method = ?';
    $params[] = $paymentMethod;
    $types .= 's';
}
if ($orderType !== '') {
    $conditions[] = 'order_type = ?';
    $params[] = $orderType;
    $types .= 's';
}
if ($receiptSearch !== '') {
    $conditions[] = 'receipt_no LIKE ?';
    $params[] = '%' . $receiptSearch . '%';
    $types .= 's';
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY created_at DESC, id DESC';

$salesStmt = $mysqli->prepare($sql);
if (!$salesStmt) {
    error_log('Transaction History query prepare failed: ' . $mysqli->error);
    die('Database operation failed. Please try again.');
}
if ($params) {
    $salesStmt->bind_param($types, ...$params);
}
$salesStmt->execute();
$salesResult = $salesStmt->get_result();
$salesRows = [];
while ($row = $salesResult->fetch_assoc()) {
    $salesRows[] = $row;
}
$salesStmt->close();

$selectedSale = null;
$selectedItems = [];
if ($selectedReceipt !== '') {
    $detailStmt = $mysqli->prepare(
        'SELECT s.receipt_no, s.created_at, s.payment_method, s.order_type, s.table_number, s.total_amount,
                COALESCE(NULLIF(si.item_name, \'\'), mi.item_name) AS item_name,
                si.quantity, si.unit_price, si.subtotal
         FROM sales s
         LEFT JOIN sale_items si ON si.sale_id = s.id
         LEFT JOIN menu_items mi ON mi.id = si.menu_item_id
         WHERE s.receipt_no = ?
         ORDER BY si.id ASC'
    );
    if (!$detailStmt) {
        error_log('Transaction detail query prepare failed: ' . $mysqli->error);
        die('Database operation failed. Please try again.');
    }
    $detailStmt->bind_param('s', $selectedReceipt);
    $detailStmt->execute();
    $detailResult = $detailStmt->get_result();
    while ($row = $detailResult->fetch_assoc()) {
        if ($selectedSale === null) {
            $selectedSale = [
                'receipt_no' => $row['receipt_no'],
                'created_at' => $row['created_at'],
                'payment_method' => $row['payment_method'],
                'order_type' => $row['order_type'],
                'table_number' => $row['table_number'],
                'total_amount' => $row['total_amount'],
            ];
        }
        if ($row['quantity'] !== null) {
            $selectedItems[] = $row;
        }
    }
    $detailStmt->close();
}

$filterQuery = http_build_query(array_filter([
    'start_date' => $startDate,
    'end_date' => $endDate,
    'payment_method' => $paymentMethod,
    'order_type' => $orderType,
    'receipt_search' => $receiptSearch,
], static function ($value) {
    return $value !== '';
}));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction History | SecurePOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260829-filters">
    <style>
        .history-heading { margin-bottom: 18px; }
        .history-heading h1 { margin: 0 0 7px; color: var(--accent); font-size: 1.65rem; }
        .history-heading p { margin: 0; color: var(--muted); }
        .history-filter { display: grid; grid-template-columns: repeat(5, minmax(140px, 1fr)) auto auto; gap: 12px; align-items: end; }
        .history-field { display: grid; gap: 7px; }
        .history-field label { color: var(--muted); font-size: .82rem; font-weight: 600; }
        .history-field .form-control, .history-field .form-select { min-height: 42px; border-color: rgba(255,255,255,.1); background: rgba(255,255,255,.045); color: var(--text); }
        .history-field .form-select option { background: #101d2d; color: var(--text); }
        .history-table { min-width: 760px; margin-bottom: 0; }
        .history-table th, .history-table td { padding: 11px 12px; vertical-align: middle; text-align: center; }
        .history-empty { padding: 28px 12px !important; color: var(--muted) !important; text-align: center !important; }
        .receipt-link { color: var(--accent); font-weight: 700; text-decoration: none; }
        .receipt-link:hover { text-decoration: underline; }
        .detail-summary { display: flex; flex-wrap: wrap; gap: 10px 24px; color: var(--muted); font-size: .9rem; }
        .detail-summary strong { color: var(--text); }
        @media (max-width: 1100px) { .history-filter { grid-template-columns: repeat(2, minmax(180px, 1fr)); } }
        @media (max-width: 620px) { .history-filter { grid-template-columns: 1fr; } .history-filter .btn { width: 100%; } }
    </style>
</head>
<body>
    <div class="dashboard-shell">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="brand"><div class="brand-icon">S</div><div><h1>SecurePOS</h1><p>Restoran Kencana Sari</p></div></div>
            </div>
            <nav class="sidebar-nav">
                <a href="pos.php" class="nav-link">Point of Sale</a>
                <a href="inventory_availability.php" class="nav-link">Inventory Availability</a>
                <a href="transaction_history.php" class="nav-link active" aria-current="page">Transaction History</a>
            </nav>
            <div class="sidebar-footer">
                <div class="profile-avatar"><?php echo historyEscape(currentUserInitials()); ?></div>
                <div><p class="profile-name"><?php echo historyEscape(currentUserName()); ?></p><p class="profile-role"><?php echo historyEscape(currentUserRole()); ?></p><a class="profile-logout" href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title"><p class="breadcrumb">SecurePOS / Transaction History</p><h2>Transaction History</h2></div>
                <div class="topbar-actions">
                    <div class="topbar-chip secondary"><span id="current-datetime">Loading...</span></div>
                    <div class="topbar-profile"><span class="profile-initials"><?php echo historyEscape(currentUserInitials()); ?></span><div><p><?php echo historyEscape(currentUserName()); ?></p><small><?php echo historyEscape(currentUserRole()); ?></small></div></div>
                </div>
            </header>

            <section class="history-heading">
                <h1>Transaction History</h1>
                <p>View completed SecurePOS sales and receipt item details.</p>
            </section>

            <section class="panel">
                <div class="panel-header"><h3>Filter Transactions</h3></div>
                <form method="get" class="history-filter securepos-filter">
                    <div class="history-field"><label for="start-date">Start Date</label><input type="date" class="form-control" id="start-date" name="start_date" value="<?php echo historyEscape($startDate); ?>"></div>
                    <div class="history-field"><label for="end-date">End Date</label><input type="date" class="form-control" id="end-date" name="end_date" value="<?php echo historyEscape($endDate); ?>"></div>
                    <div class="history-field"><label for="payment-method">Payment Method</label><select class="form-select" id="payment-method" name="payment_method"><option value="">All Payment Methods</option><?php foreach ($allowedPaymentMethods as $method) : ?><option value="<?php echo historyEscape($method); ?>" <?php echo $paymentMethod === $method ? 'selected' : ''; ?>><?php echo historyEscape($method); ?></option><?php endforeach; ?></select></div>
                    <div class="history-field"><label for="order-type">Order Type</label><select class="form-select" id="order-type" name="order_type"><option value="">All Order Types</option><?php foreach ($allowedOrderTypes as $type) : ?><option value="<?php echo historyEscape($type); ?>" <?php echo $orderType === $type ? 'selected' : ''; ?>><?php echo historyEscape($type); ?></option><?php endforeach; ?></select></div>
                    <div class="history-field"><label for="receipt-search">Receipt Search</label><input type="search" class="form-control" id="receipt-search" name="receipt_search" maxlength="50" value="<?php echo historyEscape($receiptSearch); ?>" placeholder="Receipt number"></div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="transaction_history.php" class="btn btn-outline-light">Reset</a>
                </form>
            </section>

            <?php if ($selectedReceipt !== '') : ?>
                <section class="panel" id="receipt-details">
                    <div class="panel-header"><h3>Receipt Details</h3><a href="transaction_history.php<?php echo $filterQuery !== '' ? '?' . historyEscape($filterQuery) : ''; ?>" class="btn btn-outline-light btn-sm">Close</a></div>
                    <?php if ($selectedSale === null) : ?>
                        <p class="history-empty">The selected receipt could not be found.</p>
                    <?php else : ?>
                        <div class="detail-summary">
                            <span>Receipt: <strong><?php echo historyEscape($selectedSale['receipt_no']); ?></strong></span>
                            <span>Date &amp; Time: <strong><?php echo historyEscape(date('d M Y, h:i A', strtotime($selectedSale['created_at']))); ?></strong></span>
                            <span>Payment: <strong><?php echo historyEscape($selectedSale['payment_method']); ?></strong></span>
                            <span>Order Type: <strong><?php echo historyEscape($selectedSale['order_type'] ?: 'Not Recorded'); ?></strong></span>
                            <span>Table: <strong><?php echo historyEscape($selectedSale['table_number'] ?: '—'); ?></strong></span>
                            <span>Total: <strong>RM <?php echo historyEscape(number_format((float)$selectedSale['total_amount'], 2)); ?></strong></span>
                        </div>
                        <div class="table-responsive mt-3">
                            <table class="table history-table">
                                <thead><tr><th>Item Name</th><th>Quantity</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
                                <tbody>
                                <?php if (!$selectedItems) : ?>
                                    <tr><td colspan="4" class="history-empty">No item details are available for this receipt.</td></tr>
                                <?php else : ?>
                                    <?php foreach ($selectedItems as $item) : ?>
                                        <tr><td><?php echo historyEscape($item['item_name']); ?></td><td><?php echo historyEscape($item['quantity']); ?></td><td>RM <?php echo historyEscape(number_format((float)$item['unit_price'], 2)); ?></td><td>RM <?php echo historyEscape(number_format((float)$item['subtotal'], 2)); ?></td></tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="panel">
                <div class="panel-header"><h3>Sales Transactions</h3><span><?php echo count($salesRows); ?> result<?php echo count($salesRows) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive">
                    <table class="table history-table">
                        <thead><tr><th>Receipt No.</th><th>Date &amp; Time</th><th>Payment Method</th><th>Order Type</th><th>Table</th><th>Total Amount</th></tr></thead>
                        <tbody>
                        <?php if (!$salesRows) : ?>
                            <tr><td colspan="6" class="history-empty">No transactions match the selected filters.</td></tr>
                        <?php else : ?>
                            <?php foreach ($salesRows as $sale) : ?>
                                <?php $detailParameters = array_filter(['start_date' => $startDate, 'end_date' => $endDate, 'payment_method' => $paymentMethod, 'order_type' => $orderType, 'receipt_search' => $receiptSearch, 'receipt' => $sale['receipt_no']], static function ($value) { return $value !== ''; }); ?>
                                <tr>
                                    <td><a class="receipt-link" href="transaction_history.php?<?php echo historyEscape(http_build_query($detailParameters)); ?>#receipt-details"><?php echo historyEscape($sale['receipt_no']); ?></a></td>
                                    <td><?php echo historyEscape(date('d M Y, h:i A', strtotime($sale['created_at']))); ?></td>
                                    <td><?php echo historyEscape($sale['payment_method']); ?></td>
                                    <td><?php echo historyEscape($sale['order_type'] ?: '—'); ?></td>
                                    <td><?php echo historyEscape($sale['table_number'] ?: '—'); ?></td>
                                    <td>RM <?php echo historyEscape(number_format((float)$sale['total_amount'], 2)); ?></td>
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

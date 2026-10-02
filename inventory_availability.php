<?php
// Read-only inventory availability for authenticated Cashiers.
require_once __DIR__ . '/config/auth.php';
requireCashier();
require_once __DIR__ . '/config/database.php';

$searchQuery = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 100, 'UTF-8');
$categoryFilter = trim((string)($_GET['category'] ?? ''));
$stockStatusFilter = trim((string)($_GET['stock_status'] ?? ''));
$allowedStatuses = ['In Stock', 'Low Stock', 'Out of Stock'];

$categoryStmt = $mysqli->prepare('SELECT DISTINCT category FROM inventory ORDER BY category ASC');
$categoryStmt->execute();
$categoryResult = $categoryStmt->get_result();
$categories = [];
while ($row = $categoryResult->fetch_assoc()) {
    $categories[] = $row['category'];
}
$categoryStmt->close();

if ($categoryFilter !== '' && !in_array($categoryFilter, $categories, true)) {
    $categoryFilter = '';
}
if ($stockStatusFilter !== '' && !in_array($stockStatusFilter, $allowedStatuses, true)) {
    $stockStatusFilter = '';
}

$sql = 'SELECT product_id, product_name, category, current_stock, unit, stock_status FROM inventory';
$conditions = [];
$params = [];
$types = '';

if ($searchQuery !== '') {
    $conditions[] = '(LOWER(product_id) LIKE ? OR LOWER(product_name) LIKE ?)';
    $searchPattern = '%' . strtolower($searchQuery) . '%';
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $types .= 'ss';
}
if ($categoryFilter !== '') {
    $conditions[] = 'category = ?';
    $params[] = $categoryFilter;
    $types .= 's';
}
if ($stockStatusFilter !== '') {
    $conditions[] = 'stock_status = ?';
    $params[] = $stockStatusFilter;
    $types .= 's';
}
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY product_name ASC, product_id ASC';

$inventoryStmt = $mysqli->prepare($sql);
if (!$inventoryStmt) {
    error_log('Inventory Availability query prepare failed: ' . $mysqli->error);
    die('Database operation failed. Please try again.');
}
if ($params) {
    $inventoryStmt->bind_param($types, ...$params);
}
$inventoryStmt->execute();
$inventoryResult = $inventoryStmt->get_result();
$inventoryRows = [];
while ($row = $inventoryResult->fetch_assoc()) {
    $inventoryRows[] = $row;
}
$inventoryStmt->close();

function availabilityEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function availabilityStock($value): string
{
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Availability | SecurePOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260829-filters">
    <style>
        .availability-heading { margin-bottom: 18px; }
        .availability-heading h1 { margin: 0 0 7px; color: var(--accent); font-size: 1.65rem; }
        .availability-heading p { margin: 0; color: var(--muted); }
        .availability-filter { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(180px, 230px) minmax(180px, 230px) auto auto; gap: 12px; align-items: end; }
        .availability-field { display: grid; gap: 7px; }
        .availability-field label { color: var(--muted); font-size: .82rem; font-weight: 600; }
        .availability-field .form-control, .availability-field .form-select { min-height: 42px; border-color: rgba(255,255,255,.1); background: rgba(255,255,255,.045); color: var(--text); }
        .availability-field .form-select option { background: #101d2d; color: var(--text); }
        .availability-table { min-width: 820px; margin-bottom: 0; }
        .availability-table th, .availability-table td { padding: 11px 12px; vertical-align: middle; }
        .availability-table th:not(:nth-child(2)), .availability-table td:not(:nth-child(2)) { text-align: center; }
        .availability-empty { padding: 28px 12px !important; color: var(--muted) !important; text-align: center !important; }
        @media (max-width: 980px) { .availability-filter { grid-template-columns: repeat(2, minmax(180px, 1fr)); } }
        @media (max-width: 620px) { .availability-filter { grid-template-columns: 1fr; } .availability-filter .btn { width: 100%; } }
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
                <a href="inventory_availability.php" class="nav-link active" aria-current="page">Inventory Availability</a>
                <a href="transaction_history.php" class="nav-link">Transaction History</a>
            </nav>
            <div class="sidebar-footer">
                <div class="profile-avatar"><?php echo availabilityEscape(currentUserInitials()); ?></div>
                <div><p class="profile-name"><?php echo availabilityEscape(currentUserName()); ?></p><p class="profile-role"><?php echo availabilityEscape(currentUserRole()); ?></p><a class="profile-logout" href="logout.php">Logout</a></div>
            </div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title"><p class="breadcrumb">SecurePOS / Inventory Availability</p><h2>Inventory Availability</h2></div>
                <div class="topbar-actions">
                    <div class="topbar-chip secondary"><span id="current-datetime">Loading...</span></div>
                    <div class="topbar-profile"><span class="profile-initials"><?php echo availabilityEscape(currentUserInitials()); ?></span><div><p><?php echo availabilityEscape(currentUserName()); ?></p><small><?php echo availabilityEscape(currentUserRole()); ?></small></div></div>
                </div>
            </header>

            <section class="availability-heading">
                <h1>Inventory Availability</h1>
                <p>Check current product stock and availability. Inventory changes are managed by authorized staff.</p>
            </section>

            <section class="panel">
                <div class="panel-header"><h3>Find Products</h3></div>
                <form method="get" class="availability-filter securepos-filter">
                    <div class="availability-field"><label for="availability-search">Product Search</label><input type="search" class="form-control" id="availability-search" name="search" maxlength="100" value="<?php echo availabilityEscape($searchQuery); ?>" placeholder="Product code or name"></div>
                    <div class="availability-field"><label for="availability-category">Category</label><select class="form-select" id="availability-category" name="category"><option value="">All Categories</option><?php foreach ($categories as $category) : ?><option value="<?php echo availabilityEscape($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo availabilityEscape($category); ?></option><?php endforeach; ?></select></div>
                    <div class="availability-field"><label for="availability-status">Stock Status</label><select class="form-select" id="availability-status" name="stock_status"><option value="">All Stock Status</option><?php foreach ($allowedStatuses as $status) : ?><option value="<?php echo availabilityEscape($status); ?>" <?php echo $stockStatusFilter === $status ? 'selected' : ''; ?>><?php echo availabilityEscape($status); ?></option><?php endforeach; ?></select></div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="inventory_availability.php" class="btn btn-outline-light">Reset</a>
                </form>
            </section>

            <section class="panel">
                <div class="panel-header"><h3>Available Inventory</h3><span><?php echo count($inventoryRows); ?> result<?php echo count($inventoryRows) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive">
                    <table class="table availability-table">
                        <thead><tr><th>Product Code</th><th>Product Name</th><th>Category</th><th>Current Stock</th><th>Unit</th><th>Stock Status</th></tr></thead>
                        <tbody>
                        <?php if (!$inventoryRows) : ?>
                            <tr><td colspan="6" class="availability-empty">No inventory products match the selected filters.</td></tr>
                        <?php else : ?>
                            <?php foreach ($inventoryRows as $product) : ?>
                                <?php $badgeClass = $product['stock_status'] === 'Out of Stock' ? 'badge-danger' : ($product['stock_status'] === 'Low Stock' ? 'badge-warning' : 'badge-success'); ?>
                                <tr>
                                    <td><?php echo availabilityEscape($product['product_id']); ?></td>
                                    <td><?php echo availabilityEscape($product['product_name']); ?></td>
                                    <td><?php echo availabilityEscape($product['category']); ?></td>
                                    <td><?php echo availabilityEscape(availabilityStock($product['current_stock'])); ?></td>
                                    <td><?php echo availabilityEscape($product['unit']); ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo availabilityEscape($product['stock_status']); ?></span></td>
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

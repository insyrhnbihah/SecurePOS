<?php
require_once __DIR__ . '/config/auth.php';
requireManager();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/expiry_notifications.php';

$notifications = getExpiryNotifications($mysqli);
require_once __DIR__ . '/config/reports_data.php';
require_once __DIR__ . '/config/report_export_filters.php';
$pdfExportUrl = 'reports_export.php?' . http_build_query(reportExportFilters(get_defined_vars()), '', '&', PHP_QUERY_RFC3986);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | SecurePOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260829-filter-actions">
    <style>
        .reports-header { margin-bottom: 18px; }
        .reports-header h1 { margin: 0 0 7px; font-size: 1.55rem; }
        .reports-header p { margin: 0; color: var(--muted); }
        .report-selector { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
        .report-tab { min-height: 38px; padding: 8px 14px; border: 1px solid var(--theme-89, rgba(255,255,255,.09)); border-radius: 9px; background: var(--theme-92, rgba(255,255,255,.035)); color: var(--muted); font-size: .86rem; font-weight: 700; }
        .report-tab.active { border-color: rgba(85,214,209,.32); background: rgba(85,214,209,.13); color: var(--theme-120, #b9f5f1); }
        .report-tab[disabled] { cursor: not-allowed; opacity: .52; }
        .reports-filter { display: grid; grid-template-columns: repeat(2, minmax(170px, 220px)) minmax(160px, 200px) auto auto; gap: 11px; align-items: end; }
        .report-field { display: grid; gap: 7px; }
        .report-field label { color: var(--muted); font-size: .82rem; font-weight: 600; }
        .report-field .form-control, .report-field .form-select { min-height: 40px; border-color: var(--theme-89, rgba(255,255,255,.09)); background: var(--theme-79, rgba(255,255,255,.04)); color: var(--text); }
        .report-field .form-select option { background: var(--theme-90, #101d2d); color: var(--text); }
        .attendance-reports-filter { grid-template-columns: 150px 150px minmax(240px, 1fr) minmax(160px, 190px) minmax(170px, 200px) auto; gap: 10px 12px; }
        .attendance-reports-filter .form-control:focus, .attendance-reports-filter .form-select:focus { border-color: rgba(85,214,209,.7); box-shadow: 0 0 0 3px rgba(85,214,209,.12); outline: 0; }
        .report-notice { margin: 12px 0 0; color: var(--theme-113, #ffd097); font-size: .84rem; }
        .reports-summary { grid-template-columns: repeat(5, minmax(155px, 1fr)); margin-top: 18px; }
        .reports-summary.inventory-summary { grid-template-columns: repeat(4, minmax(155px, 1fr)); }
        .reports-summary.expiry-summary { grid-template-columns: repeat(5, minmax(155px, 1fr)); }
        .reports-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr); gap: 18px; }
        .report-table { width: 100%; margin-bottom: 0; }
        .report-table th, .report-table td { padding: 10px 12px; vertical-align: middle; }
        .report-table .center { text-align: center; }
        .report-table .amount { text-align: right; white-space: nowrap; }
        .inventory-report-table { min-width: 760px; }
        .inventory-report-table th:not(:nth-child(2)), .inventory-report-table td:not(:nth-child(2)) { text-align: center; }
        .inventory-report-table th, .inventory-report-table td { white-space: nowrap; }
        .expiry-report-table { min-width: 980px; }
        .expiry-report-table th:not(:nth-child(2)), .expiry-report-table td:not(:nth-child(2)) { text-align: center; }
        .expiry-report-table th, .expiry-report-table td { white-space: nowrap; }
        .attendance-report-table { min-width: 1050px; table-layout: fixed; }
        .monthly-attendance-table { min-width: 720px; table-layout: fixed; }
        .monthly-attendance-table th, .monthly-attendance-table td, .attendance-report-table th, .attendance-report-table td { padding: 9px 12px; vertical-align: middle; white-space: nowrap; }
        .monthly-attendance-table th:not(:nth-child(2)), .monthly-attendance-table td:not(:nth-child(2)), .attendance-report-table th:not(:nth-child(2)), .attendance-report-table td:not(:nth-child(2)) { text-align: center; }
        .monthly-attendance-table th:nth-child(2), .monthly-attendance-table td:nth-child(2), .attendance-report-table th:nth-child(2), .attendance-report-table td:nth-child(2) { text-align: left; }
        .monthly-attendance-table th:nth-child(2), .monthly-attendance-table td:nth-child(2) { text-align: center; }
        .attendance-report-table th:nth-child(2), .attendance-report-table td:nth-child(2) { text-align: center; }
        .monthly-attendance-table thead tr, .attendance-report-table thead tr { border-bottom: 1px solid var(--theme-89, rgba(255,255,255,.09)); }
        .monthly-attendance-table tbody tr, .attendance-report-table tbody tr { border-bottom: 1px solid var(--theme-157, rgba(255,255,255,.055)); }
        .monthly-attendance-note { margin: 6px 10px 2px; color: var(--muted); font-size: .78rem; }
        .monthly-attendance-panel { margin-bottom: 18px; }
        .report-empty { padding: 20px 12px !important; color: var(--muted) !important; text-align: center; }
        .reports-page .sidebar-nav .nav-link:not(.active) { background: transparent; color: var(--muted); }
        .reports-page .sidebar-nav .nav-link:not(.active):hover { background: rgba(85, 214, 209, 0.14); color: var(--text); }
        @media (max-width: 1100px) { .reports-filter { grid-template-columns: repeat(2, minmax(180px, 1fr)); } .reports-summary { grid-template-columns: repeat(2, minmax(170px, 1fr)); } .reports-grid { grid-template-columns: 1fr; } }
        @media (max-width: 620px) { .reports-filter, .reports-summary { grid-template-columns: 1fr; } .reports-filter .btn { width: 100%; } }
    </style>
    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head>
<body class="reports-page">
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
                <a href="reports.php" class="nav-link active" aria-current="page">Reports</a>
                <a href="audit_logs.php" class="nav-link">Audit Logs</a>
            </nav>
            <div class="sidebar-footer"><div class="profile-avatar"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></div><div><p class="profile-name"><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p><p class="profile-role"><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></p><a class="profile-logout" href="logout.php">Logout</a></div></div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title"><p class="breadcrumb">SecurePOS / Reports</p><h2>Reports</h2></div>
                <div class="topbar-actions"><div class="topbar-chip secondary"><span id="current-datetime">Loading...</span></div><?php echo renderExpiryNotificationBell($notifications, 'product_expiry.php'); ?><div class="topbar-profile"><span class="profile-initials"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></span><div><p><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p><small><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></small></div></div></div>
            </header>

            <section class="reports-header"><h1>Operational Reports</h1><p>Review SecurePOS performance using existing system records.</p></section>
            <nav class="report-selector" aria-label="Report sections"><a href="reports.php?report=sales" class="report-tab<?php echo $activeReport === 'sales' ? ' active' : ''; ?>">Sales</a><a href="reports.php?report=inventory" class="report-tab<?php echo $activeReport === 'inventory' ? ' active' : ''; ?>">Inventory</a><a href="reports.php?report=expiry" class="report-tab<?php echo $activeReport === 'expiry' ? ' active' : ''; ?>">Product Expiry</a><a href="reports.php?report=attendance" class="report-tab<?php echo $activeReport === 'attendance' ? ' active' : ''; ?>">Attendance</a></nav>

            <?php if ($activeReport === 'sales') : ?>
            <section class="panel">
                <div class="panel-header"><h3>Sales Filters</h3><a class="btn btn-outline-light" href="<?php echo htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Export PDF</a></div>
                <form method="get" class="reports-filter securepos-filter">
                    <div class="report-field"><label for="start-date">Start Date</label><input type="date" class="form-control" id="start-date" name="start_date" value="<?php echo htmlspecialchars($startDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div class="report-field"><label for="end-date">End Date</label><input type="date" class="form-control" id="end-date" name="end_date" value="<?php echo htmlspecialchars($endDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div class="report-field"><label for="payment">Payment Method</label><select class="form-select" id="payment" name="payment"><?php foreach ($paymentOptions as $value => $label) : ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $paymentFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                    <div class="filter-actions"><button type="submit" class="btn btn-primary">Filter</button><a href="reports.php" class="btn btn-outline-light">Reset</a></div>
                </form>
                <?php if ($filterNotice !== '') : ?><p class="report-notice" role="status"><?php echo htmlspecialchars($filterNotice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            </section>

            <section class="summary-cards reports-summary">
                <article class="summary-card accent-cyan"><div class="card-title">Total Revenue</div><div class="card-value"><?php echo reportMoney($summary['total_revenue']); ?></div><p class="card-note">Revenue in the selected period.</p></article>
                <article class="summary-card accent-blue"><div class="card-title">Total Transactions</div><div class="card-value"><?php echo (int)$summary['total_transactions']; ?></div><p class="card-note">Completed sales transactions.</p></article>
                <article class="summary-card accent-purple"><div class="card-title">Average Transaction</div><div class="card-value"><?php echo reportMoney($summary['average_transaction']); ?></div><p class="card-note">Average sale value.</p></article>
                <article class="summary-card accent-orange"><div class="card-title">Cash Sales</div><div class="card-value"><?php echo reportMoney($summary['cash_sales']); ?></div><p class="card-note">Revenue paid in cash.</p></article>
                <article class="summary-card accent-cyan"><div class="card-title">QR Sales</div><div class="card-value"><?php echo reportMoney($summary['qr_sales']); ?></div><p class="card-note">Revenue paid by QR.</p></article>
            </section>

            <div class="reports-grid">
                <section class="panel"><div class="panel-header"><h3>Sales Transactions</h3><span><?php echo count($transactions); ?> result<?php echo count($transactions) === 1 ? '' : 's'; ?></span></div><div class="table-responsive"><table class="table report-table"><thead><tr><th>Receipt No.</th><th class="center">Date &amp; Time</th><th class="center">Payment Method</th><th class="center">Order Type</th><th class="center">Table</th><th class="amount">Total Amount</th></tr></thead><tbody>
                <?php if (!$transactions) : ?><tr><td colspan="6" class="report-empty">No sales found for the selected filters.</td></tr><?php else : ?><?php foreach ($transactions as $transaction) : ?><tr><td><?php echo htmlspecialchars($transaction['receipt_no'], ENT_QUOTES, 'UTF-8'); ?></td><td class="center"><?php echo htmlspecialchars((new DateTimeImmutable($transaction['created_at'], $timezone))->format('d M Y, h:i A'), ENT_QUOTES, 'UTF-8'); ?></td><td class="center"><?php echo htmlspecialchars($transaction['payment_method'], ENT_QUOTES, 'UTF-8'); ?></td><td class="center"><?php echo htmlspecialchars($transaction['order_type'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td><td class="center"><?php echo htmlspecialchars($transaction['table_number'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td><td class="amount"><?php echo reportMoney($transaction['total_amount']); ?></td></tr><?php endforeach; ?><?php endif; ?>
                </tbody></table></div></section>

                <section class="panel"><div class="panel-header"><h3>Top Selling Items</h3><span>By quantity sold</span></div><div class="table-responsive"><table class="table report-table"><thead><tr><th>Item Name</th><th class="center">Quantity Sold</th><th class="amount">Revenue</th></tr></thead><tbody>
                <?php if (!$topItems) : ?><tr><td colspan="3" class="report-empty">No item sales found for the selected filters.</td></tr><?php else : ?><?php foreach ($topItems as $item) : ?><tr><td><?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?></td><td class="center"><?php echo (int)$item['quantity_sold']; ?></td><td class="amount"><?php echo reportMoney($item['revenue']); ?></td></tr><?php endforeach; ?><?php endif; ?>
                </tbody></table></div></section>
            </div>
            <?php elseif ($activeReport === 'inventory') : ?>
            <section class="panel">
                <div class="panel-header"><h3>Inventory Filters</h3><a class="btn btn-outline-light" href="<?php echo htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Export PDF</a></div>
                <form method="get" class="reports-filter securepos-filter">
                    <input type="hidden" name="report" value="inventory">
                    <div class="report-field"><label for="inventory-search">Product Search</label><input type="search" class="form-control" id="inventory-search" name="search" value="<?php echo htmlspecialchars($inventorySearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Code or product name"></div>
                    <div class="report-field"><label for="inventory-category">Category</label><select class="form-select" id="inventory-category" name="category"><option value="">All Categories</option><?php foreach ($inventoryCategories as $category) : ?><option value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $inventoryCategory === $category ? 'selected' : ''; ?>><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                    <div class="report-field"><label for="inventory-status">Stock Status</label><select class="form-select" id="inventory-status" name="stock_status"><?php foreach ($inventoryStatusOptions as $value => $label) : ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $inventoryStatusFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                    <div class="filter-actions"><button type="submit" class="btn btn-primary">Filter</button><a href="reports.php?report=inventory" class="btn btn-outline-light">Reset</a></div>
                </form>
                <?php if ($inventoryNotice !== '') : ?><p class="report-notice" role="status"><?php echo htmlspecialchars($inventoryNotice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            </section>

            <section class="summary-cards reports-summary inventory-summary">
                <article class="summary-card accent-cyan"><div class="card-title">Total Products</div><div class="card-value"><?php echo (int)$inventorySummary['total_products']; ?></div><p class="card-note">Products matching the selected filters.</p></article>
                <article class="summary-card accent-blue"><div class="card-title">In Stock</div><div class="card-value"><?php echo (int)$inventorySummary['in_stock']; ?></div><p class="card-note">Products currently marked in stock.</p></article>
                <article class="summary-card accent-orange"><div class="card-title">Low Stock</div><div class="card-value"><?php echo (int)$inventorySummary['low_stock']; ?></div><p class="card-note">Products currently marked low stock.</p></article>
                <article class="summary-card accent-purple"><div class="card-title">Out of Stock</div><div class="card-value"><?php echo (int)$inventorySummary['out_of_stock']; ?></div><p class="card-note">Products currently marked out of stock.</p></article>
            </section>

            <section class="panel">
                <div class="panel-header"><h3>Inventory Products</h3><span><?php echo count($inventoryRows); ?> result<?php echo count($inventoryRows) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive"><table class="table report-table inventory-report-table"><thead><tr><th>Product Code</th><th>Product Name</th><th>Category</th><th>Current Stock</th><th>Unit</th><th>Stock Status</th></tr></thead><tbody>
                <?php if (!$inventoryRows) : ?><tr><td colspan="6" class="report-empty">No inventory products found for the selected filters.</td></tr><?php else : ?><?php foreach ($inventoryRows as $item) : ?>
                    <?php $stockBadgeClass = stripos($item['stock_status'], 'out') !== false ? 'badge-danger' : (stripos($item['stock_status'], 'low') !== false ? 'badge-warning' : 'badge-success'); ?>
                    <tr><td><?php echo htmlspecialchars($item['product_id'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$item['current_stock'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($item['unit'], ENT_QUOTES, 'UTF-8'); ?></td><td><span class="badge <?php echo $stockBadgeClass; ?>"><?php echo htmlspecialchars($item['stock_status'], ENT_QUOTES, 'UTF-8'); ?></span></td></tr>
                <?php endforeach; ?><?php endif; ?>
                </tbody></table></div>
            </section>
            <?php elseif ($activeReport === 'expiry') : ?>
            <section class="panel">
                <div class="panel-header"><h3>Product Expiry Filters</h3><a class="btn btn-outline-light" href="<?php echo htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Export PDF</a></div>
                <form method="get" class="reports-filter securepos-filter">
                    <input type="hidden" name="report" value="expiry">
                    <div class="report-field"><label for="expiry-search">Product Search</label><input type="search" class="form-control" id="expiry-search" name="search" value="<?php echo htmlspecialchars($expirySearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Code or product name"></div>
                    <div class="report-field"><label for="expiry-category">Category</label><select class="form-select" id="expiry-category" name="category"><option value="">All Categories</option><?php foreach ($expiryCategories as $category) : ?><option value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $expiryCategory === $category ? 'selected' : ''; ?>><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                    <div class="report-field"><label for="expiry-status">Expiry Status</label><select class="form-select" id="expiry-status" name="expiry_status"><?php foreach ($expiryStatusOptions as $value => $label) : ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $expiryStatusFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                    <div class="filter-actions"><button type="submit" class="btn btn-primary">Filter</button><a href="reports.php?report=expiry" class="btn btn-outline-light">Reset</a></div>
                </form>
                <?php if ($expiryNotice !== '') : ?><p class="report-notice" role="status"><?php echo htmlspecialchars($expiryNotice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            </section>

            <section class="summary-cards reports-summary expiry-summary">
                <article class="summary-card accent-cyan"><div class="card-title">Total Batches</div><div class="card-value"><?php echo (int)$expirySummary['total_batches']; ?></div><p class="card-note">Batches matching the selected filters.</p></article>
                <article class="summary-card accent-purple"><div class="card-title">Expired</div><div class="card-value"><?php echo (int)$expirySummary['expired']; ?></div><p class="card-note">Expiry date has passed.</p></article>
                <article class="summary-card accent-orange"><div class="card-title">Expiring Within 7 Days</div><div class="card-value"><?php echo (int)$expirySummary['within_7']; ?></div><p class="card-note">Expires within the next 7 days.</p></article>
                <article class="summary-card accent-blue"><div class="card-title">Expiring Within 30 Days</div><div class="card-value"><?php echo (int)$expirySummary['within_30']; ?></div><p class="card-note">Expires in 8–30 days.</p></article>
                <article class="summary-card accent-cyan"><div class="card-title">Valid</div><div class="card-value"><?php echo (int)$expirySummary['valid']; ?></div><p class="card-note">More than 30 days remaining.</p></article>
            </section>

            <section class="panel">
                <div class="panel-header"><h3>Product Expiry Batches</h3><span><?php echo count($expiryRows); ?> result<?php echo count($expiryRows) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive"><table class="table report-table expiry-report-table"><thead><tr><th>Product Code</th><th>Product Name</th><th>Category</th><th>Batch Number</th><th>Quantity</th><th>Unit</th><th>Expiry Date</th><th>Expiry Status</th></tr></thead><tbody>
                <?php if (!$expiryRows) : ?><tr><td colspan="8" class="report-empty">No product expiry batches found for the selected filters.</td></tr><?php else : ?><?php foreach ($expiryRows as $batch) : ?>
                    <?php $expiryBadgeClass = $batch['expiry_status'] === 'Expired' ? 'badge-danger' : ($batch['expiry_status'] === 'Within 7 Days' ? 'badge-warning' : ($batch['expiry_status'] === 'Within 30 Days' ? 'badge-warning' : 'badge-success')); ?>
                    <tr><td><?php echo htmlspecialchars($batch['product_id'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($batch['product_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($batch['category'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($batch['batch_no'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars(rtrim(rtrim(number_format((float)$batch['quantity'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($batch['unit'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((new DateTimeImmutable($batch['expiry_date'], $timezone))->format('d M Y'), ENT_QUOTES, 'UTF-8'); ?></td><td><span class="badge <?php echo $expiryBadgeClass; ?>"><?php echo htmlspecialchars($expiryStatusOptions[array_search($batch['expiry_status'], $expiryStatusValues, true)] ?? $batch['expiry_status'], ENT_QUOTES, 'UTF-8'); ?></span></td></tr>
                <?php endforeach; ?><?php endif; ?>
                </tbody></table></div>
            </section>
            <?php else : ?>
            <section class="panel">
                <div class="panel-header"><h3>Attendance Filters</h3><a class="btn btn-outline-light" href="<?php echo htmlspecialchars($pdfExportUrl, ENT_QUOTES, 'UTF-8'); ?>">Export PDF</a></div>
                <form method="get" class="reports-filter attendance-reports-filter securepos-filter">
                    <input type="hidden" name="report" value="attendance">
                    <div class="report-field"><label for="attendance-start-date">Start Date</label><input type="date" class="form-control" id="attendance-start-date" name="start_date" value="<?php echo htmlspecialchars($attendanceStartDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div class="report-field"><label for="attendance-end-date">End Date</label><input type="date" class="form-control" id="attendance-end-date" name="end_date" value="<?php echo htmlspecialchars($attendanceEndDate->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required></div>
                    <div class="report-field"><label for="attendance-search">Employee Search</label><input type="search" class="form-control" id="attendance-search" name="employee_search" value="<?php echo htmlspecialchars($attendanceSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Code or employee name"></div>
                    <div class="report-field"><label for="attendance-role">Role</label><select class="form-select" id="attendance-role" name="role"><?php foreach ($attendanceRoleOptions as $value => $label) : ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $attendanceRoleFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                    <div class="report-field"><label for="attendance-status">Attendance Status</label><select class="form-select" id="attendance-status" name="attendance_status"><?php foreach ($attendanceStatusOptions as $value => $label) : ?><option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $attendanceStatusFilter === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                    <div class="filter-actions"><button type="submit" class="btn btn-primary">Filter</button><a href="reports.php?report=attendance" class="btn btn-outline-light">Reset</a></div>
                </form>
                <?php if ($attendanceNotice !== '') : ?><p class="report-notice" role="status"><?php echo htmlspecialchars($attendanceNotice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
            </section>

            <section class="summary-cards reports-summary <?php echo $attendanceSingleDate ? 'expiry-summary' : 'inventory-summary'; ?>">
                <article class="summary-card accent-cyan"><div class="card-title">Attendance Records</div><div class="card-value"><?php echo (int)$attendanceSummary['attendance_records']; ?></div><p class="card-note">Records matching the selected filters.</p></article>
                <article class="summary-card accent-blue"><div class="card-title">Completed Shifts</div><div class="card-value"><?php echo (int)$attendanceSummary['completed_shifts']; ?></div><p class="card-note">Records with check-in and check-out.</p></article>
                <article class="summary-card accent-orange"><div class="card-title">Missing Check-Out</div><div class="card-value"><?php echo (int)$attendanceSummary['missing_checkout']; ?></div><p class="card-note">Checked in without checking out.</p></article>
                <article class="summary-card accent-purple"><div class="card-title">Present Employees</div><div class="card-value"><?php echo (int)$attendanceSummary['present_employees']; ?></div><p class="card-note">Unique employees with attendance records.</p></article>
                <?php if ($attendanceSingleDate) : ?><article class="summary-card accent-cyan"><div class="card-title">Absent Employees</div><div class="card-value"><?php echo $absentEmployees; ?></div><p class="card-note">Eligible active employees without a record.</p></article><?php endif; ?>
            </section>

            <section class="panel monthly-attendance-panel">
                <div class="panel-header"><h3>Monthly Attendance Summary</h3><span><?php echo count($monthlyAttendanceRows); ?> result<?php echo count($monthlyAttendanceRows) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive"><table class="table report-table monthly-attendance-table"><colgroup><col style="width:16%"><col style="width:28%"><col style="width:20%"><col style="width:16%"><col style="width:20%"></colgroup><thead><tr><th>Employee Code</th><th>Employee Name</th><th>Month</th><th>Days Present</th><th>Total Working Hours</th></tr></thead><tbody>
                <?php if (!$monthlyAttendanceRows) : ?><tr><td colspan="5" class="report-empty">No monthly attendance records found for the selected filters.</td></tr><?php else : ?><?php foreach ($monthlyAttendanceRows as $monthlyRecord) : ?>
                    <?php
                    $totalWorkingMinutes = $monthlyRecord['total_working_minutes'] === null ? null : (int)$monthlyRecord['total_working_minutes'];
                    $monthLabel = DateTimeImmutable::createFromFormat('!Y-m', $monthlyRecord['attendance_month'], $timezone);
                    ?>
                    <tr><td><?php echo htmlspecialchars($monthlyRecord['employee_code'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($monthlyRecord['full_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($monthLabel ? $monthLabel->format('F Y') : $monthlyRecord['attendance_month'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$monthlyRecord['days_present']; ?></td><td><?php echo htmlspecialchars(reportWorkingMinutes($totalWorkingMinutes), ENT_QUOTES, 'UTF-8'); ?></td></tr>
                <?php endforeach; ?><?php endif; ?>
                </tbody></table></div>
                <p class="monthly-attendance-note">Working-hour calculations include completed attendance records only.</p>
            </section>

            <section class="panel">
                <div class="panel-header"><h3>Employee Attendance</h3><span><?php echo count($attendanceRows); ?> result<?php echo count($attendanceRows) === 1 ? '' : 's'; ?></span></div>
                <div class="table-responsive"><table class="table report-table attendance-report-table"><colgroup><col style="width:13%"><col style="width:18%"><col style="width:10%"><col style="width:15%"><col style="width:11%"><col style="width:11%"><col style="width:13%"><col style="width:9%"></colgroup><thead><tr><th>Employee Code</th><th>Employee Name</th><th>Role</th><th>Attendance Date</th><th>Check In</th><th>Check Out</th><th>Working Hours</th><th>Status</th></tr></thead><tbody>
                <?php if (!$attendanceRows) : ?><tr><td colspan="8" class="report-empty">No attendance records found for the selected filters.</td></tr><?php else : ?><?php foreach ($attendanceRows as $record) : ?>
                    <?php $workingHours = $record['working_minutes'] === null ? '—' : sprintf('%dh %02dm', intdiv((int)$record['working_minutes'], 60), (int)$record['working_minutes'] % 60); ?>
                    <tr><td><?php echo htmlspecialchars($record['employee_code'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($record['full_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($record['role'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((new DateTimeImmutable($record['attendance_date'], $timezone))->format('d M Y'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo $record['check_in'] ? htmlspecialchars((new DateTimeImmutable($record['check_in'], $timezone))->format('h:i A'), ENT_QUOTES, 'UTF-8') : '—'; ?></td><td><?php echo $record['check_out'] ? htmlspecialchars((new DateTimeImmutable($record['check_out'], $timezone))->format('h:i A'), ENT_QUOTES, 'UTF-8') : '—'; ?></td><td><?php echo htmlspecialchars($workingHours, ENT_QUOTES, 'UTF-8'); ?></td><td><span class="badge badge-success"><?php echo htmlspecialchars($record['status'], ENT_QUOTES, 'UTF-8'); ?></span></td></tr>
                <?php endforeach; ?><?php endif; ?>
                </tbody></table></div>
            </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="assets/js/dashboard.js"></script>
</body>
</html>

<?php
// SecurePOS Inventory Management
// Inventory page for Restoran Kencana Sari
require_once __DIR__ . '/config/auth.php';
requireInventoryAccess();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/expiry_notifications.php';

$notifications = getExpiryNotifications($mysqli);

$formError = '';
$showAddForm = isset($_GET['add']) && $_GET['add'] === '1';
$showEditForm = false;
$showDeleteModal = false;
$editProduct = [
    'id' => '',
    'product_id' => '',
    'product_name' => '',
    'category' => '',
    'current_stock' => '',
    'unit' => '',
    'stock_status' => ''
];
$deleteProduct = [
    'id' => '',
    'product_id' => '',
    'product_name' => ''
];

$searchQuery = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$stockStatusFilter = trim($_GET['stock_status'] ?? '');
$allowedStatuses = ['In Stock', 'Low Stock', 'Out of Stock'];

if (empty($_SESSION['inventory_csrf'])) {
    $_SESSION['inventory_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_product'])) {
    $productId = trim($_POST['product_id'] ?? '');
    $productName = trim($_POST['product_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $currentStock = trim($_POST['current_stock'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $stockStatus = trim($_POST['stock_status'] ?? '');
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['inventory_csrf'], $submittedCsrf)) {
        $formError = 'The form session has expired. Please try again.';
        $showAddForm = true;
    } elseif ($productId === '' || $productName === '' || $category === '' || $currentStock === '' || $unit === '' || $stockStatus === '') {
        $formError = 'All fields are required.';
        $showAddForm = true;
    } elseif (!is_numeric($currentStock) || $currentStock < 0) {
        $formError = 'Current Stock must be a non-negative number.';
        $showAddForm = true;
    } elseif (!in_array($stockStatus, ['In Stock', 'Low Stock', 'Out of Stock'], true)) {
        $formError = 'Please select a valid stock status.';
        $showAddForm = true;
    } else {
        $checkStmt = $mysqli->prepare('SELECT product_id FROM inventory WHERE product_id = ?');
        $checkStmt->bind_param('s', $productId);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $formError = 'The Product ID already exists. Please use a unique Product ID.';
            $showAddForm = true;
        } else {
            $insertStmt = $mysqli->prepare('INSERT INTO inventory (product_id, product_name, category, current_stock, unit, stock_status) VALUES (?, ?, ?, ?, ?, ?)');
            $insertStmt->bind_param('sssdss', $productId, $productName, $category, $currentStock, $unit, $stockStatus);
            if ($insertStmt->execute()) {
                auditLog(
                    $mysqli,
                    (int)$_SESSION['user_id'],
                    currentUserName(),
                    currentUserRole(),
                    'Inventory',
                    'Create Product',
                    'Success',
                    'Inventory product ' . $productId . ' created.'
                );
                header('Location: inventory.php?added=1');
                exit;
            }
            $formError = 'Unable to save the product. Please try again.';
            $showAddForm = true;
        }

        $checkStmt->close();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_product'])) {
    $editProduct['id'] = trim($_POST['id'] ?? '');
    $editProduct['product_id'] = trim($_POST['product_id'] ?? '');
    $editProduct['product_name'] = trim($_POST['product_name'] ?? '');
    $editProduct['category'] = trim($_POST['category'] ?? '');
    $editProduct['current_stock'] = trim($_POST['current_stock'] ?? '');
    $editProduct['unit'] = trim($_POST['unit'] ?? '');
    $editProduct['stock_status'] = trim($_POST['stock_status'] ?? '');
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');

    $showEditForm = true;

    if (!hash_equals($_SESSION['inventory_csrf'], $submittedCsrf)) {
        $formError = 'The form session has expired. Please try again.';
    } elseif ($editProduct['id'] === '' || !ctype_digit($editProduct['id'])) {
        $formError = 'Invalid product selected for editing.';
    } elseif ($editProduct['product_id'] === '' || $editProduct['product_name'] === '' || $editProduct['category'] === '' || $editProduct['current_stock'] === '' || $editProduct['unit'] === '' || $editProduct['stock_status'] === '') {
        $formError = 'All fields are required.';
    } elseif (!is_numeric($editProduct['current_stock']) || $editProduct['current_stock'] < 0) {
        $formError = 'Current Stock must be a non-negative number.';
    } elseif (!in_array($editProduct['stock_status'], ['In Stock', 'Low Stock', 'Out of Stock'], true)) {
        $formError = 'Please select a valid stock status.';
    } else {
        $checkStmt = $mysqli->prepare('SELECT product_id FROM inventory WHERE product_id = ? AND id != ?');
        $checkStmt->bind_param('si', $editProduct['product_id'], $editProduct['id']);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $formError = 'The Product ID already exists. Please use a unique Product ID.';
        } else {
            $originalStmt = $mysqli->prepare('SELECT product_id, current_stock, unit, stock_status FROM inventory WHERE id = ? LIMIT 1');
            $originalStmt->bind_param('i', $editProduct['id']);
            $originalStmt->execute();
            $originalProduct = $originalStmt->get_result()->fetch_assoc();
            $originalStmt->close();

            $updateStmt = $mysqli->prepare('UPDATE inventory SET product_id = ?, product_name = ?, category = ?, current_stock = ?, unit = ?, stock_status = ? WHERE id = ?');
            $updateStmt->bind_param('sssdssi', $editProduct['product_id'], $editProduct['product_name'], $editProduct['category'], $editProduct['current_stock'], $editProduct['unit'], $editProduct['stock_status'], $editProduct['id']);
            if ($updateStmt->execute()) {
                if ($originalProduct !== null) {
                    if ((float)$originalProduct['current_stock'] !== (float)$editProduct['current_stock']
                        || $originalProduct['unit'] !== $editProduct['unit']) {
                        $oldStock = rtrim(rtrim(number_format((float)$originalProduct['current_stock'], 2, '.', ''), '0'), '.');
                        $newStock = rtrim(rtrim(number_format((float)$editProduct['current_stock'], 2, '.', ''), '0'), '.');
                        $auditDescription = 'Stock for ' . $editProduct['product_id'] . ' changed from '
                            . $oldStock . ' ' . $originalProduct['unit'] . ' to '
                            . $newStock . ' ' . $editProduct['unit'] . '.';
                    } elseif ($originalProduct['stock_status'] !== $editProduct['stock_status']) {
                        $auditDescription = 'Inventory status for ' . $editProduct['product_id'] . ' changed from '
                            . $originalProduct['stock_status'] . ' to ' . $editProduct['stock_status'] . '.';
                    } else {
                        $auditDescription = 'Inventory product ' . $editProduct['product_id'] . ' updated.';
                    }
                    auditLog(
                        $mysqli,
                        (int)$_SESSION['user_id'],
                        currentUserName(),
                        currentUserRole(),
                        'Inventory',
                        'Update Product',
                        'Success',
                        $auditDescription
                    );
                }
                header('Location: inventory.php?updated=1');
                exit;
            }
            $formError = 'Unable to update the product. Please try again.';
        }

        $checkStmt->close();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    $deleteProduct['id'] = trim($_POST['id'] ?? '');
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['inventory_csrf'], $submittedCsrf)) {
        $formError = 'The form session has expired. Please try again.';
        $showDeleteModal = true;
    } elseif ($deleteProduct['id'] === '' || !ctype_digit($deleteProduct['id'])) {
        $formError = 'Invalid product selected for deletion.';
        $showDeleteModal = true;
    } else {
        $deletedProductStmt = $mysqli->prepare('SELECT product_id FROM inventory WHERE id = ? LIMIT 1');
        $deletedProductStmt->bind_param('i', $deleteProduct['id']);
        $deletedProductStmt->execute();
        $deletedProductRow = $deletedProductStmt->get_result()->fetch_assoc();
        $deletedProductStmt->close();

        $deleteStmt = $mysqli->prepare('DELETE FROM inventory WHERE id = ?');
        $deleteStmt->bind_param('i', $deleteProduct['id']);
        if ($deleteStmt->execute()) {
            if ($deleteStmt->affected_rows === 1 && $deletedProductRow !== null) {
                auditLog(
                    $mysqli,
                    (int)$_SESSION['user_id'],
                    currentUserName(),
                    currentUserRole(),
                    'Inventory',
                    'Delete Product',
                    'Success',
                    'Inventory product ' . $deletedProductRow['product_id'] . ' deleted.'
                );
            }
            header('Location: inventory.php?deleted=1');
            exit;
        }
        $formError = 'Unable to delete the product. Please try again.';
        $showDeleteModal = true;
        $deleteStmt->close();
    }
} elseif (isset($_GET['edit']) && ctype_digit($_GET['edit'])) {
    $editId = $_GET['edit'];
    $productStmt = $mysqli->prepare(
        'SELECT id, product_id, product_name, category, current_stock, unit, stock_status
         FROM inventory WHERE id = ? LIMIT 1'
    );
    $productStmt->bind_param('i', $editId);
    $productStmt->execute();
    $productResult = $productStmt->get_result();

    if ($productRow = $productResult->fetch_assoc()) {
        $showEditForm = true;
        $editProduct = $productRow;
    }

    $productStmt->close();
} elseif (isset($_GET['delete']) && ctype_digit($_GET['delete'])) {
    $deleteId = $_GET['delete'];
    $productStmt = $mysqli->prepare('SELECT id, product_id, product_name FROM inventory WHERE id = ?');
    $productStmt->bind_param('i', $deleteId);
    $productStmt->execute();
    $productResult = $productStmt->get_result();

    if ($productRow = $productResult->fetch_assoc()) {
        $showDeleteModal = true;
        $deleteProduct = [
            'id' => $productRow['id'],
            'product_id' => $productRow['product_id'],
            'product_name' => $productRow['product_name']
        ];
    }

    $productStmt->close();
    }

    $modalTitle = $showEditForm ? 'Edit Product' : 'Add Product';
    $modalSubtitle = $showEditForm ? 'Update the inventory information for this product.' : 'Enter inventory information for the new product.';
    $primaryButtonLabel = $showEditForm ? 'Save Changes' : 'Save Product';

    $summaryQuery = "SELECT COUNT(*) AS total_products, SUM(stock_status = 'In Stock') AS in_stock, SUM(stock_status = 'Low Stock') AS low_stock, SUM(stock_status = 'Out of Stock') AS out_of_stock FROM inventory";
    $summaryResult = $mysqli->query($summaryQuery);
    if (!$summaryResult) {
        error_log('Inventory summary query failed: ' . $mysqli->error);
        die('Database operation failed. Please try again.');
    }
    $summary = $summaryResult->fetch_assoc();

    $categoryQuery = 'SELECT DISTINCT category FROM inventory ORDER BY category ASC';
    $categoryResult = $mysqli->query($categoryQuery);
    if (!$categoryResult) {
        error_log('Inventory category query failed: ' . $mysqli->error);
        die('Database operation failed. Please try again.');
    }
    $categories = [];
    while ($catRow = $categoryResult->fetch_assoc()) {
        $categories[] = $catRow['category'];
    }

    $inventoryQuery = 'SELECT id, product_id, product_name, category, current_stock, unit, stock_status FROM inventory';
    $inventoryParams = [];
    $inventoryTypes = '';
    $inventoryConditions = [];

    if ($searchQuery !== '') {
        $inventoryConditions[] = '(LOWER(product_id) LIKE ? OR LOWER(product_name) LIKE ?)';
        $searchTerm = '%' . strtolower($searchQuery) . '%';
        $inventoryParams[] = $searchTerm;
        $inventoryParams[] = $searchTerm;
        $inventoryTypes .= 'ss';
    }

    if ($categoryFilter !== '') {
        $inventoryConditions[] = 'category = ?';
        $inventoryParams[] = $categoryFilter;
        $inventoryTypes .= 's';
    }

    if (in_array($stockStatusFilter, $allowedStatuses, true)) {
        $inventoryConditions[] = 'stock_status = ?';
        $inventoryParams[] = $stockStatusFilter;
        $inventoryTypes .= 's';
    }

    if (!empty($inventoryConditions)) {
        $inventoryQuery .= ' WHERE ' . implode(' AND ', $inventoryConditions);
    }

    $inventoryQuery .= ' ORDER BY id ASC';
    $inventoryStmt = $mysqli->prepare($inventoryQuery);
    if (!$inventoryStmt) {
        error_log('Inventory list query prepare failed: ' . $mysqli->error);
        die('Database operation failed. Please try again.');
    }

    if (!empty($inventoryParams)) {
        $inventoryStmt->bind_param($inventoryTypes, ...$inventoryParams);
    }

    $inventoryStmt->execute();
    $inventoryResult = $inventoryStmt->get_result();
    if (!$inventoryResult) {
        error_log('Inventory list query failed: ' . $mysqli->error);
        die('Database operation failed. Please try again.');
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecurePOS Inventory Management | Restoran Kencana Sari</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260820-sidebar">
    <link rel="stylesheet" href="assets/css/inventory.css">
</head>
<body class="<?= ($showAddForm || $showEditForm) ? 'modal-open' : ''; ?>">
    <div class="dashboard-shell">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="brand">
                    <div class="brand-icon">S</div>
                    <div>
                        <h1>SecurePOS</h1>
                        <p>Restoran Kencana Sari</p>
                    </div>
                </div>
            </div>

            <nav class="sidebar-nav">
                <?php if (currentUserRole() === 'Manager') : ?>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="pos.php" class="nav-link">Point of Sale</a>
                <?php endif; ?>
                <a href="inventory.php" class="nav-link active">Inventory</a>
                <a href="product_expiry.php" class="nav-link">Product Expiry</a>
                <?php if (currentUserRole() === 'Manager') : ?>
                <a href="attendance.php" class="nav-link">Employee Attendance</a>
                <a href="users.php" class="nav-link">Users</a>
                <a href="reports.php" class="nav-link">Reports</a>
                <a href="audit_logs.php" class="nav-link">Audit Logs</a>
                <?php else : ?>
                <a href="leave.php" class="nav-link">Employee Attendance / Leave</a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="profile-avatar"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></div>
                <div>
                    <p class="profile-name"><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="profile-role"><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></p>
                    <a class="profile-logout" href="change_password.php">Change Password</a> &middot; <a class="profile-logout" href="logout.php">Logout</a>
                </div>
            </div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title">
                    <p class="breadcrumb">SecurePOS / Inventory</p>
                    <h2>Inventory Management</h2>
                </div>
                <div class="topbar-actions">
                    <div class="topbar-chip secondary">
                        <span id="current-datetime">Loading...</span>
                    </div>
                    <?php echo renderExpiryNotificationBell($notifications, 'product_expiry.php'); ?>
                    <div class="topbar-profile">
                        <span class="profile-initials"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></span>
                        <div>
                            <p><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p>
                            <small><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                    </div>
                </div>
            </header>

            <section class="dashboard-header inventory-header">
                <div>
                    <h1 class="greeting">Inventory Management</h1>
                    <p class="intro">Manage and monitor restaurant inventory.</p>
                </div>
            </section>

<?php if (isset($_GET['deleted'])): ?>
            <section class="panel" style="margin-bottom: 20px;">
                <div class="alert alert-success" role="alert">
                    Product deleted successfully.
                </div>
            </section>
<?php endif; ?>

            <section class="summary-cards inventory-summary">
                <article class="summary-card accent-cyan">
                    <div class="card-top">
                        <span class="card-icon">📦</span>
                        <div class="card-title">Total Products</div>
                    </div>
                    <div class="card-value"><?= htmlspecialchars($summary['total_products']); ?></div>
                    <p class="card-note">Total active inventory items.</p>
                </article>
                <article class="summary-card accent-blue">
                    <div class="card-top">
                        <span class="card-icon">✅</span>
                        <div class="card-title">In Stock</div>
                    </div>
                    <div class="card-value"><?= htmlspecialchars($summary['in_stock']); ?></div>
                    <p class="card-note">Items currently available.</p>
                </article>
                <article class="summary-card accent-orange">
                    <div class="card-top">
                        <span class="card-icon">⚠️</span>
                        <div class="card-title">Low Stock</div>
                    </div>
                    <div class="card-value"><?= htmlspecialchars($summary['low_stock']); ?></div>
                    <p class="card-note">Items needing restock soon.</p>
                </article>
                <article class="summary-card accent-red">
                    <div class="card-top">
                        <span class="card-icon">⛔</span>
                        <div class="card-title">Out of Stock</div>
                    </div>
                    <div class="card-value"><?= htmlspecialchars($summary['out_of_stock']); ?></div>
                    <p class="card-note">Items currently unavailable.</p>
                </article>
            </section>

            <section class="panel control-panel">
                <form method="get" action="inventory.php" id="inventoryFilterForm">
                    <div class="toolbar-row">
                        <div class="toolbar-search">
                            <input type="text" class="toolbar-input" id="inventorySearch" name="search" placeholder="Search by Product ID or Name" value="<?= htmlspecialchars($searchQuery); ?>" autocomplete="off">
                            <div class="search-suggestions" id="searchSuggestions" hidden></div>
                        </div>
                        <div class="toolbar-select">
                            <select class="toolbar-dropdown" name="category" id="inventoryCategory" aria-label="Category filter">
                                <option value="" <?= $categoryFilter === '' ? 'selected' : ''; ?>>All Categories</option>
                                <?php foreach ($categories as $categoryOption): ?>
                                    <option value="<?= htmlspecialchars($categoryOption); ?>" <?= $categoryFilter === $categoryOption ? 'selected' : ''; ?>><?= htmlspecialchars($categoryOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="toolbar-select">
                            <select class="toolbar-dropdown" name="stock_status" id="inventoryStockStatus" aria-label="Stock status filter">
                                <option value="" <?= $stockStatusFilter === '' ? 'selected' : ''; ?>>All Stock Status</option>
                                <option value="In Stock" <?= $stockStatusFilter === 'In Stock' ? 'selected' : ''; ?>>In Stock</option>
                                <option value="Low Stock" <?= $stockStatusFilter === 'Low Stock' ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="Out of Stock" <?= $stockStatusFilter === 'Out of Stock' ? 'selected' : ''; ?>>Out of Stock</option>
                            </select>
                        </div>
                        <div class="toolbar-action">
                            <a href="inventory.php?add=1" class="btn btn-primary btn-add">+ Add Product</a>
                        </div>
                    </div>
                </form>
            </section>

            <div class="modal-backdrop <?= ($showAddForm || $showEditForm || $showDeleteModal) ? 'visible' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <div class="add-product-modal">
                    <div class="modal-header">
                        <div>
                            <h3 id="modalTitle"><?= htmlspecialchars($showDeleteModal ? 'Delete Product?' : $modalTitle); ?></h3>
                            <p class="modal-subtitle"><?= htmlspecialchars($showDeleteModal ? "Are you sure you want to delete {$deleteProduct['product_name']} ({$deleteProduct['product_id']})? This action cannot be undone." : $modalSubtitle); ?></p>
                        </div>
                        <a href="inventory.php" class="modal-close" aria-label="Close modal">×</a>
                    </div>
<?php if ($formError): ?>
                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError); ?></div>
<?php endif; ?>
                    <?php if ($showDeleteModal): ?>
                    <form method="post" action="inventory.php" class="add-product-grid">
                        <input type="hidden" name="delete_product" value="1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['inventory_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($deleteProduct['id']); ?>">
                        <div class="modal-actions" style="grid-column: 1 / -1;">
                            <a href="inventory.php" class="btn btn-cancel">Cancel</a>
                            <button type="submit" class="btn btn-delete">Delete Product</button>
                        </div>
                    </form>
                    <?php else: ?>
                    <form method="post" action="inventory.php" class="add-product-grid">
                        <input type="hidden" name="<?= $showEditForm ? 'edit_product' : 'add_product'; ?>" value="1">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['inventory_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($showEditForm): ?>
                            <input type="hidden" name="id" value="<?= htmlspecialchars($editProduct['id']); ?>">
                        <?php endif; ?>
                        <div class="modal-field">
                            <label for="productId">Product ID</label>
                            <input type="text" class="modal-input" id="productId" name="product_id" placeholder="Product ID" value="<?= htmlspecialchars($showEditForm ? $editProduct['product_id'] : ($_POST['product_id'] ?? '')); ?>" required>
                        </div>
                        <div class="modal-field">
                            <label for="productName">Product Name</label>
                            <input type="text" class="modal-input" id="productName" name="product_name" placeholder="Product Name" value="<?= htmlspecialchars($showEditForm ? $editProduct['product_name'] : ($_POST['product_name'] ?? '')); ?>" required>
                        </div>
                        <div class="modal-field">
                            <label for="category">Category</label>
                            <input type="text" class="modal-input" id="category" name="category" placeholder="Category" value="<?= htmlspecialchars($showEditForm ? $editProduct['category'] : ($_POST['category'] ?? '')); ?>" required>
                        </div>
                        <div class="modal-field">
                            <label for="currentStock">Current Stock</label>
                            <input type="number" step="0.01" min="0" class="modal-input" id="currentStock" name="current_stock" placeholder="Current Stock" value="<?= htmlspecialchars($showEditForm ? rtrim(rtrim(number_format((float)$editProduct['current_stock'], 2, '.', ''), '0'), '.') : ($_POST['current_stock'] ?? '')); ?>" required>
                        </div>
                        <div class="modal-field">
                            <label for="unit">Unit</label>
                            <input type="text" class="modal-input" id="unit" name="unit" placeholder="Unit" value="<?= htmlspecialchars($showEditForm ? $editProduct['unit'] : ($_POST['unit'] ?? '')); ?>" required>
                        </div>
                        <div class="modal-field">
                            <label for="stockStatus">Stock Status</label>
                            <select class="modal-select" id="stockStatus" name="stock_status" required>
                                <?php $selectedStatus = $showEditForm ? $editProduct['stock_status'] : ($_POST['stock_status'] ?? ''); ?>
                                <option value="" <?= $selectedStatus === '' ? 'selected' : ''; ?>>Choose status</option>
                                <option value="In Stock" <?= $selectedStatus === 'In Stock' ? 'selected' : ''; ?>>In Stock</option>
                                <option value="Low Stock" <?= $selectedStatus === 'Low Stock' ? 'selected' : ''; ?>>Low Stock</option>
                                <option value="Out of Stock" <?= $selectedStatus === 'Out of Stock' ? 'selected' : ''; ?>>Out of Stock</option>
                            </select>
                        </div>
                        <div class="modal-actions" style="grid-column: 1 / -1;">
                            <a href="inventory.php" class="btn btn-cancel">Cancel</a>
                            <button type="submit" class="btn btn-save"><?= htmlspecialchars($primaryButtonLabel); ?></button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <section class="panel inventory-table-panel">
                <div class="panel-header">
                    <h3>Inventory Records</h3>
                    <span>Review and monitor current stock levels.</span>
                </div>
                <div class="table-responsive">
                    <table class="table inventory-table">
                        <thead>
                            <tr>
                                <th class="text-left-col col-id">Product ID</th>
                                <th class="text-left-col col-product">Product Name</th>
                                <th class="text-left-col col-category">Category</th>
                                <th class="text-center-col col-quantity">Current Stock</th>
                                <th class="text-center-col col-unit">Unit</th>
                                <th class="text-center-col col-status">Stock Status</th>
                                <th class="text-center-col col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
<?php if ($inventoryResult->num_rows > 0): ?>
<?php while ($row = $inventoryResult->fetch_assoc()): ?>
                            <tr>
                                <td class="text-left-col col-id"><?= htmlspecialchars($row['product_id']); ?></td>
                                <td class="text-left-col col-product"><?= htmlspecialchars($row['product_name']); ?></td>
                                <td class="text-left-col col-category"><?= htmlspecialchars($row['category']); ?></td>
                                <td class="text-center-col col-quantity"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $row['current_stock'], 2, '.', ''), '0'), '.')); ?></td>
                                <td class="text-center-col col-unit"><?= htmlspecialchars($row['unit']); ?></td>
                                <td class="text-center-col col-status">
<?php
    $statusText = htmlspecialchars($row['stock_status']);
    $badgeClass = 'badge-success';
    if (stripos($row['stock_status'], 'low') !== false) {
        $badgeClass = 'badge-warning';
    } elseif (stripos($row['stock_status'], 'out') !== false) {
        $badgeClass = 'badge-danger';
    }
?>
                                    <span class="badge <?= $badgeClass; ?>"><?= $statusText; ?></span>
                                </td>
                                <td class="text-center-col col-actions">
                                    <div class="action-button-group">
                                        <a href="inventory.php?edit=<?= htmlspecialchars($row['id']); ?>" class="btn btn-sm action-btn action-btn-edit">
                                            <span class="action-icon">✎</span>
                                            <span>Edit</span>
                                        </a>
                                        <a href="inventory.php?delete=<?= htmlspecialchars($row['id']); ?>" class="btn btn-sm action-btn action-btn-delete">
                                            <span class="action-icon">🗑</span>
                                            <span>Delete</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
<?php endwhile; ?>
<?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">No inventory records found.</td>
                            </tr>
<?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-GQF1T9hLUo4LM6De3askwAdnGFfdvK98zhkYJ2vQVq7Z2uRjrkG4RwF9g+0kI2PW" crossorigin="anonymous"></script>
    <script src="assets/js/dashboard.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('inventorySearch');
        const categorySelect = document.getElementById('inventoryCategory');
        const statusSelect = document.getElementById('inventoryStockStatus');
        const tbody = document.querySelector('.inventory-table tbody');

        function normalize(s) {
            return (s || '').toString().toLowerCase().trim();
        }

        function filterRows() {
            if (!tbody) return;
            const q = normalize(searchInput ? searchInput.value : '');
            const cat = normalize(categorySelect ? categorySelect.value : '');
            const status = normalize(statusSelect ? statusSelect.value : '');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            let anyVisible = false;
            rows.forEach(row => {
                const tds = row.querySelectorAll('td');
                if (!tds || tds.length === 0) return;

                // placeholder row (no records) has single td with colspan
                if (tds.length === 1 && tds[0].hasAttribute('colspan')) {
                    // decide visibility later
                    return;
                }

                const pid = normalize(tds[0] ? tds[0].textContent : '');
                const pname = normalize(tds[1] ? tds[1].textContent : '');
                const pcat = normalize(tds[2] ? tds[2].textContent : '');
                const pstatus = normalize(tds[5] ? tds[5].textContent : '');

                let match = true;
                if (q) {
                    match = pid.includes(q) || pname.includes(q);
                }
                if (match && cat) {
                    match = pcat === cat;
                }
                if (match && status) {
                    match = pstatus === status;
                }

                row.style.display = match ? '' : 'none';
                if (match) anyVisible = true;
            });

            // show/hide placeholder row
            const placeholder = tbody.querySelector('tr td[colspan]');
            if (placeholder) {
                placeholder.parentElement.style.display = anyVisible ? 'none' : '';
            }
        }

        if (searchInput) searchInput.addEventListener('input', filterRows);
        if (categorySelect) categorySelect.addEventListener('change', filterRows);
        if (statusSelect) statusSelect.addEventListener('change', filterRows);

        // initial filter pass if inputs have values
        filterRows();
    });
    </script>
</body>
</html>

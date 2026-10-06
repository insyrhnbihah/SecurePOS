<?php
require_once __DIR__ . '/config/auth.php';
requireInventoryAccess();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/expiry_notifications.php';

$notifications = getExpiryNotifications($mysqli);

$searchTerm = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$allowedStatusFilters = ['all', 'expired', 'expiring_soon', 'safe', 'expires_today'];

if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}

$timezone = new DateTimeZone('Asia/Kuala_Lumpur');
$today = new DateTime('today', $timezone);

$allRows = [];
$expiryQuery = 'SELECT pe.id, pe.inventory_id, pe.batch_no, pe.expiry_date, pe.quantity, i.product_name, i.unit
    FROM product_expiry pe
    JOIN inventory i ON pe.inventory_id = i.id
    ORDER BY pe.expiry_date ASC, pe.batch_no ASC';
$expiryResult = $mysqli->query($expiryQuery);
if (!$expiryResult) {
    error_log('Product Expiry query failed: ' . $mysqli->error);
    die('Database operation failed. Please try again.');
}

while ($row = $expiryResult->fetch_assoc()) {
    $expiryDate = new DateTime($row['expiry_date'], $timezone);
    $daysRemaining = (int)floor(($expiryDate->getTimestamp() - $today->getTimestamp()) / 86400);

    if ($daysRemaining < 0) {
        $status = 'Expired';
    } elseif ($daysRemaining === 0) {
        $status = 'Expires Today';
    } elseif ($daysRemaining <= 7) {
        $status = 'Expiring Soon';
    } else {
        $status = 'Safe';
    }

    $allRows[] = [
        'id' => (int)$row['id'],
        'inventory_id' => (int)$row['inventory_id'],
        'product_name' => $row['product_name'],
        'batch_no' => $row['batch_no'],
        'expiry_date' => $row['expiry_date'],
        'quantity' => (float)$row['quantity'],
        'unit' => $row['unit'],
        'days_remaining' => $daysRemaining,
        'status' => $status,
    ];
}

$summary = [
    'total' => count($allRows),
    'expired' => 0,
    'expiring_soon' => 0,
    'safe' => 0,
];

foreach ($allRows as $row) {
    if ($row['status'] === 'Expired') {
        $summary['expired']++;
    } elseif ($row['status'] === 'Expiring Soon' || $row['status'] === 'Expires Today') {
        $summary['expiring_soon']++;
    } else {
        $summary['safe']++;
    }
}

$filteredRows = [];
foreach ($allRows as $row) {
    $matchesSearch = $searchTerm === ''
        || stripos($row['product_name'], $searchTerm) !== false
        || stripos($row['batch_no'], $searchTerm) !== false;

    $matchesStatus = true;
    if ($statusFilter !== 'all') {
        $statusKey = $row['status'];
        if ($statusFilter === 'expired') {
            $matchesStatus = $statusKey === 'Expired';
        } elseif ($statusFilter === 'expiring_soon') {
            $matchesStatus = $statusKey === 'Expiring Soon' || $statusKey === 'Expires Today';
        } elseif ($statusFilter === 'safe') {
            $matchesStatus = $statusKey === 'Safe';
        } elseif ($statusFilter === 'expires_today') {
            $matchesStatus = $statusKey === 'Expires Today';
        }
    }

    if ($matchesSearch && $matchesStatus) {
        $filteredRows[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecurePOS Product Expiry | Restoran Kencana Sari</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260829-filters">
    <style>
        .expiry-toolbar {
            display: grid;
            grid-template-columns: minmax(280px, 1fr) minmax(180px, 220px) auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 18px;
        }

        .expiry-toolbar .form-control,
        .expiry-toolbar .form-select {
            width: 100%;
            height: 42px;
            min-width: 0;
            padding: 8px 12px;
            border-radius: 9px;
            background: var(--theme-105, rgba(255,255,255,0.04));
            border: 1px solid var(--theme-155, rgba(255,255,255,0.09));
            color: var(--text);
        }

        .expiry-toolbar .form-control::placeholder {
            color: var(--muted);
        }

        .expiry-toolbar .form-select option {
            background: var(--theme-90, #101d2d);
            color: var(--text);
        }

        .expiry-toolbar .form-control:focus,
        .expiry-toolbar .form-select:focus {
            border-color: rgba(85,214,209,.7);
            box-shadow: 0 0 0 3px rgba(85,214,209,.12);
            outline: 0;
        }

        .expiry-toolbar .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: auto;
            height: 42px;
            padding: 8px 18px;
            border-radius: 9px;
            white-space: nowrap;
        }

        .expiry-toolbar .btn-primary {
            border-color: #55d6d1;
            background: linear-gradient(135deg, #55d6d1, #43b6ff);
            color: #07101c;
            font-weight: 700;
        }

        .expiry-toolbar .btn-primary:hover,
        .expiry-toolbar .btn-primary:focus {
            border-color: #72e2de;
            background: linear-gradient(135deg, #72e2de, #59c3ff);
            color: #07101c;
        }

        .expiry-toolbar .btn-outline-light {
            border-color: var(--theme-91, rgba(255,255,255,.16));
            background: var(--theme-92, rgba(255,255,255,.035));
            color: var(--text);
        }

        .expiry-toolbar .btn-outline-light:hover,
        .expiry-toolbar .btn-outline-light:focus {
            border-color: var(--theme-93, rgba(255,255,255,.28));
            background: var(--theme-94, rgba(255,255,255,.08));
            color: var(--text);
        }

        @media (max-width: 900px) {
            .expiry-toolbar { grid-template-columns: minmax(220px, 1fr) minmax(180px, .65fr); }
            .expiry-toolbar .btn { justify-self: start; }
        }

        @media (max-width: 600px) {
            .expiry-toolbar { grid-template-columns: 1fr; }
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 28px;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }

        .status-badge.expired {
            background: rgba(252, 92, 125, 0.16);
            color: var(--theme-114, #ffb3c1);
            border: 1px solid rgba(252, 92, 125, 0.24);
        }

        .status-badge.expiring-soon {
            background: rgba(255, 159, 67, 0.16);
            color: var(--theme-113, #ffd097);
            border: 1px solid rgba(255, 159, 67, 0.24);
        }

        .status-badge.safe {
            background: rgba(85, 214, 209, 0.16);
            color: var(--theme-112, #9feee9);
            border: 1px solid rgba(85, 214, 209, 0.24);
        }

        .status-badge.expires-today {
            background: rgba(138, 107, 255, 0.16);
            color: var(--theme-156, #d5cbff);
            border: 1px solid rgba(138, 107, 255, 0.24);
        }

        .expiry-table td,
        .expiry-table th {
            vertical-align: middle;
        }
    </style>
    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head>
<body>
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
                <a href="inventory.php" class="nav-link">Inventory</a>
                <a href="product_expiry.php" class="nav-link active">Product Expiry</a>
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
                    <a class="profile-logout" href="logout.php">Logout</a>
                </div>
            </div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title">
                    <p class="breadcrumb">SecurePOS / Product Expiry</p>
                    <h2>Product Expiry</h2>
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

            <section class="dashboard-header">
                <div>
                    <h1 class="greeting">Expiry monitoring</h1>
                    <p class="intro">Track batches that are expired, expiring soon, or still safe.</p>
                </div>
            </section>

            <section class="summary-cards">
                <article class="summary-card accent-cyan">
                    <div class="card-top">
                        <span class="card-icon">📦</span>
                        <div class="card-title">Total Batches</div>
                    </div>
                    <div class="card-value"><?php echo (int)$summary['total']; ?></div>
                    <p class="card-note">All tracked product batches.</p>
                </article>
                <article class="summary-card accent-red">
                    <div class="card-top">
                        <span class="card-icon">⚠️</span>
                        <div class="card-title">Expired</div>
                    </div>
                    <div class="card-value"><?php echo (int)$summary['expired']; ?></div>
                    <p class="card-note">Batches already past their expiry date.</p>
                </article>
                <article class="summary-card accent-orange">
                    <div class="card-top">
                        <span class="card-icon">⏳</span>
                        <div class="card-title">Expiring Soon</div>
                    </div>
                    <div class="card-value"><?php echo (int)$summary['expiring_soon']; ?></div>
                    <p class="card-note">Batches with 1–7 days remaining or expiring today.</p>
                </article>
                <article class="summary-card accent-blue">
                    <div class="card-top">
                        <span class="card-icon">✅</span>
                        <div class="card-title">Safe</div>
                    </div>
                    <div class="card-value"><?php echo (int)$summary['safe']; ?></div>
                    <p class="card-note">Batches with more than 7 days remaining.</p>
                </article>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h3>Expiry Records</h3>
                    <span>Search by product name or batch number</span>
                </div>

                <form method="get" class="expiry-toolbar securepos-filter">
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search product or batch">
                    <select class="form-select" name="status">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        <option value="expiring_soon" <?php echo $statusFilter === 'expiring_soon' ? 'selected' : ''; ?>>Expiring Soon</option>
                        <option value="expires_today" <?php echo $statusFilter === 'expires_today' ? 'selected' : ''; ?>>Expires Today</option>
                        <option value="safe" <?php echo $statusFilter === 'safe' ? 'selected' : ''; ?>>Safe</option>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="product_expiry.php" class="btn btn-outline-light">Reset</a>
                </form>

                <div class="table-responsive">
                    <table class="table transactions-table expiry-table">
                        <thead>
                            <tr>
                                <th class="text-left-col col-product">Product Name</th>
                                <th class="text-left-col col-batch">Batch No.</th>
                                <th class="text-center-col col-quantity">Quantity</th>
                                <th class="text-center-col col-date">Expiry Date</th>
                                <th class="text-center-col col-days">Days Remaining</th>
                                <th class="text-center-col col-status">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filteredRows)) : ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4" style="color: var(--muted);">No matching expiry records found.</td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($filteredRows as $row) : ?>
                                    <?php
                                    $badgeClass = 'safe';
                                    $badgeText = $row['status'];
                                    if ($row['status'] === 'Expired') {
                                        $badgeClass = 'expired';
                                    } elseif ($row['status'] === 'Expires Today') {
                                        $badgeClass = 'expires-today';
                                    } elseif ($row['status'] === 'Expiring Soon') {
                                        $badgeClass = 'expiring-soon';
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-left-col col-product"><?php echo htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-left-col col-batch"><?php echo htmlspecialchars($row['batch_no'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-center-col col-quantity"><?php echo htmlspecialchars((string)$row['quantity'], ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars($row['unit'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-center-col col-date"><?php echo htmlspecialchars($row['expiry_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="text-center-col col-days"><?php echo $row['days_remaining'] < 0 ? abs($row['days_remaining']) . ' days overdue' : ($row['days_remaining'] === 0 ? '0 days' : $row['days_remaining'] . ' days'); ?></td>
                                        <td class="text-center-col col-status"><span class="status-badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8'); ?></span></td>
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

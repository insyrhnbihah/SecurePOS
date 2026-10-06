<?php
// SecurePOS Manager Dashboard
// Manager operational dashboard for Restoran Kencana Sari
require_once __DIR__ . '/config/auth.php';
requireManager();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/expiry_notifications.php';

$timezone = new DateTimeZone('Asia/Kuala_Lumpur');
$today = new DateTimeImmutable('today', $timezone);
$todaySql = $today->format('Y-m-d');
$todayStartSql = $today->format('Y-m-d 00:00:00');
$tomorrowStartSql = $today->modify('+1 day')->format('Y-m-d 00:00:00');
$mysqli->query("SET time_zone = '+08:00'");
$notifications = getExpiryNotifications($mysqli);

$securityAccountStmt = $mysqli->prepare(
    "SELECT COUNT(*) AS active_users,
            COALESCE(SUM(u.locked_until IS NOT NULL AND u.locked_until > NOW()), 0) AS locked_accounts,
            COALESCE(SUM(uft.user_id IS NOT NULL), 0) AS face_enrolled_users
     FROM users u
     LEFT JOIN user_face_templates uft ON uft.user_id = u.id
     WHERE u.account_status = 'Active'"
);
$securityAccountStmt->execute();
$securityAccountSummary = $securityAccountStmt->get_result()->fetch_assoc();
$securityAccountStmt->close();

$securityAuditStmt = $mysqli->prepare(
    "SELECT COUNT(*) AS authentication_events,
            COALESCE(SUM(action = 'Login' AND result IN ('Failed', 'Failure', 'Rejected')), 0) AS unsuccessful_password_logins
     FROM audit_logs
     WHERE module = 'Authentication' AND created_at >= ? AND created_at < ?"
);
$securityAuditStmt->bind_param('ss', $todayStartSql, $tomorrowStartSql);
$securityAuditStmt->execute();
$securityAuditSummary = $securityAuditStmt->get_result()->fetch_assoc();
$securityAuditStmt->close();

function dashboardEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dashboardStock($value): string
{
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}

$salesSummaryStmt = $mysqli->prepare('SELECT COALESCE(SUM(total_amount), 0) AS today_sales FROM sales WHERE created_at >= ? AND created_at < ?');
$salesSummaryStmt->bind_param('ss', $todayStartSql, $tomorrowStartSql);
$salesSummaryStmt->execute();
$todaySales = (float)$salesSummaryStmt->get_result()->fetch_assoc()['today_sales'];
$salesSummaryStmt->close();

$salesTrendStart = $today->modify('-6 days');
$salesTrendStartSql = $salesTrendStart->format('Y-m-d 00:00:00');
$salesTrendStmt = $mysqli->prepare(
    'SELECT DATE(created_at) AS sale_date, COALESCE(SUM(total_amount), 0) AS total_sales
     FROM sales
     WHERE created_at >= ? AND created_at < ?
     GROUP BY DATE(created_at)
     ORDER BY sale_date ASC'
);
$salesTrendStmt->bind_param('ss', $salesTrendStartSql, $tomorrowStartSql);
$salesTrendStmt->execute();
$salesTrendResult = $salesTrendStmt->get_result();
$salesTrendByDate = [];
while ($row = $salesTrendResult->fetch_assoc()) {
    $salesTrendByDate[$row['sale_date']] = (float)$row['total_sales'];
}
$salesTrendStmt->close();

$salesTrend = [];
for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
    $trendDate = $salesTrendStart->modify('+' . $dayOffset . ' days');
    $trendDateKey = $trendDate->format('Y-m-d');
    $salesTrend[] = [
        'date' => $trendDateKey,
        'label' => $trendDate->format('d M'),
        'display_date' => $trendDate->format('d M Y'),
        'total' => $salesTrendByDate[$trendDateKey] ?? 0.0,
    ];
}

$inventorySummaryStmt = $mysqli->prepare("SELECT COUNT(*) AS total_products, COALESCE(SUM(stock_status = 'Low Stock'), 0) AS low_stock_items FROM inventory");
$inventorySummaryStmt->execute();
$inventorySummary = $inventorySummaryStmt->get_result()->fetch_assoc();
$inventorySummaryStmt->close();

$attendanceSummarySql = "SELECT COUNT(DISTINCT e.id) AS eligible_employees,
        COUNT(DISTINCT CASE WHEN ar.check_in IS NOT NULL THEN e.id END) AS present_employees
    FROM employees e
    INNER JOIN users u ON u.id = e.user_id
    LEFT JOIN attendance_records ar ON ar.employee_id = e.id AND ar.attendance_date = ?
    WHERE e.account_status = 'Active' AND u.account_status = 'Active'
      AND e.role IN ('Cashier', 'Inventory Staff') AND u.role = e.role";
$attendanceSummaryStmt = $mysqli->prepare($attendanceSummarySql);
$attendanceSummaryStmt->bind_param('s', $todaySql);
$attendanceSummaryStmt->execute();
$attendanceSummary = $attendanceSummaryStmt->get_result()->fetch_assoc();
$attendanceSummaryStmt->close();
$presentEmployees = (int)$attendanceSummary['present_employees'];
$eligibleEmployees = (int)$attendanceSummary['eligible_employees'];
$attendanceRate = $eligibleEmployees > 0 ? ($presentEmployees / $eligibleEmployees) * 100 : 0.0;
$attendanceRateDisplay = rtrim(rtrim(number_format($attendanceRate, 1, '.', ''), '0'), '.');

$recentSalesStmt = $mysqli->prepare('SELECT receipt_no, total_amount, payment_method, created_at FROM sales ORDER BY created_at DESC, id DESC LIMIT 5');
$recentSalesStmt->execute();
$recentSalesResult = $recentSalesStmt->get_result();
$recentSales = [];
while ($row = $recentSalesResult->fetch_assoc()) {
    $recentSales[] = $row;
}
$recentSalesStmt->close();

$lowStockStmt = $mysqli->prepare("SELECT product_id, product_name, current_stock, unit FROM inventory WHERE stock_status = 'Low Stock' ORDER BY current_stock ASC, product_name ASC LIMIT 5");
$lowStockStmt->execute();
$lowStockResult = $lowStockStmt->get_result();
$lowStockAlerts = [];
while ($row = $lowStockResult->fetch_assoc()) {
    $lowStockAlerts[] = $row;
}
$lowStockStmt->close();

$activityStmt = $mysqli->prepare('SELECT actor_name, actor_role, module, action, result, description, created_at FROM audit_logs ORDER BY created_at DESC, id DESC LIMIT 5');
$activityStmt->execute();
$activityResult = $activityStmt->get_result();
$recentActivities = [];
while ($row = $activityResult->fetch_assoc()) {
    $recentActivities[] = $row;
}
$activityStmt->close();

$currentUserId = (int)$_SESSION['user_id'];
$accountStmt = $mysqli->prepare('SELECT account_status FROM users WHERE id = ? LIMIT 1');
$accountStmt->bind_param('i', $currentUserId);
$accountStmt->execute();
$accountRow = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();
$accountStatus = $accountRow['account_status'] ?? 'Unknown';

$lastLoginStmt = $mysqli->prepare("SELECT created_at FROM audit_logs WHERE user_id = ? AND module = 'Authentication' AND action = 'Login' AND result = 'Success' ORDER BY created_at DESC, id DESC LIMIT 1");
$lastLoginStmt->bind_param('i', $currentUserId);
$lastLoginStmt->execute();
$lastLoginRow = $lastLoginStmt->get_result()->fetch_assoc();
$lastLoginStmt->close();
$lastLoginDisplay = $lastLoginRow
    ? (new DateTimeImmutable($lastLoginRow['created_at'], $timezone))->format('d M Y, h:i A')
    : 'Not available';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecurePOS Manager Dashboard | Restoran Kencana Sari</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260902-security-overview">
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
                <a href="dashboard.php" class="nav-link active">Dashboard</a>
                <a href="pos.php" class="nav-link">Point of Sale</a>
                <a href="inventory.php" class="nav-link">Inventory</a>
                <a href="product_expiry.php" class="nav-link">Product Expiry</a>
                <a href="attendance.php" class="nav-link">Employee Attendance</a>
                <a href="users.php" class="nav-link">Users</a>
                <a href="reports.php" class="nav-link">Reports</a>
                <a href="audit_logs.php" class="nav-link">Audit Logs</a>
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
                    <p class="breadcrumb">SecurePOS / Dashboard</p>
                    <h2>Manager Dashboard</h2>
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
                    <h1 class="greeting">Welcome, <?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="intro">Here’s today’s operational overview.</p>
                </div>
                <div class="header-status-cards compact-cards two-cards">
                    <div class="status-card small-card">
                        <p>Last Login</p>
                        <strong><?php echo dashboardEscape($lastLoginDisplay); ?></strong>
                    </div>
                    <div class="status-card small-card verified">
                        <p>Account Status</p>
                        <strong><?php echo dashboardEscape($accountStatus); ?></strong>
                    </div>
                </div>
            </section>

            <section class="summary-cards">
                <article class="summary-card accent-cyan">
                    <div class="card-top">
                        <span class="card-icon">💰</span>
                        <div class="card-title">Today's Sales</div>
                    </div>
                    <div class="card-value">RM <?php echo number_format($todaySales, 2); ?></div>
                    <p class="card-note">Completed sales recorded today.</p>
                </article>
                <article class="summary-card accent-blue">
                    <div class="card-top">
                        <span class="card-icon">📦</span>
                        <div class="card-title">Total Products</div>
                    </div>
                    <div class="card-value"><?php echo (int)$inventorySummary['total_products']; ?></div>
                    <p class="card-note">Current inventory products in the system.</p>
                </article>
                <article class="summary-card accent-orange">
                    <div class="card-top">
                        <span class="card-icon">⚠️</span>
                        <div class="card-title">Low Stock Items</div>
                    </div>
                    <div class="card-value"><?php echo (int)$inventorySummary['low_stock_items']; ?></div>
                    <p class="card-note">Items needing restock soon.</p>
                </article>
                <article class="summary-card accent-red">
                    <div class="card-top">
                        <span class="card-icon">⏳</span>
                        <div class="card-title">Expiring Products</div>
                    </div>
                    <div class="card-value"><?php echo count($notifications); ?></div>
                    <p class="card-note">Expired or due within the next 7 days.</p>
                </article>
                <article class="summary-card accent-purple">
                    <div class="card-top">
                        <span class="card-icon">👥</span>
                        <div class="card-title">Employees Present</div>
                    </div>
                    <div class="card-value"><?php echo (int)$attendanceSummary['present_employees']; ?> / <?php echo (int)$attendanceSummary['eligible_employees']; ?></div>
                    <p class="card-note">Eligible active employees present today.</p>
                </article>
            </section>

            <?php
            $chartWidth = 700;
            $chartHeight = 270;
            $chartLeft = 78;
            $chartRight = 26;
            $chartTop = 18;
            $chartBottom = 52;
            $chartPlotWidth = $chartWidth - $chartLeft - $chartRight;
            $chartPlotHeight = $chartHeight - $chartTop - $chartBottom;
            $maximumTrendSale = max(array_column($salesTrend, 'total'));
            $chartMaximum = max(1.0, ceil($maximumTrendSale / 10) * 10);
            $chartBarSlotWidth = $chartPlotWidth / 7;
            $chartBarWidth = min(38, $chartBarSlotWidth * 0.46);
            foreach ($salesTrend as $index => $trendDay) {
                $barCenterX = $chartLeft + ($chartBarSlotWidth * ($index + 0.5));
                $calculatedBarHeight = ($trendDay['total'] / $chartMaximum) * $chartPlotHeight;
                $barHeight = $trendDay['total'] > 0 ? $calculatedBarHeight : 2;
                $salesTrend[$index]['bar_x'] = $barCenterX - ($chartBarWidth / 2);
                $salesTrend[$index]['bar_center_x'] = $barCenterX;
                $salesTrend[$index]['bar_y'] = $chartTop + $chartPlotHeight - $barHeight;
                $salesTrend[$index]['bar_height'] = $barHeight;
                $salesTrend[$index]['hit_x'] = $chartLeft + ($chartBarSlotWidth * $index);
            }
            ?>
            <section class="dashboard-analytics-row">
            <section class="panel sales-overview-panel" aria-labelledby="sales-overview-title">
                <div class="panel-header">
                    <div>
                        <h3 id="sales-overview-title">Sales Overview</h3>
                        <span>Sales performance for the last 7 days</span>
                    </div>
                </div>
                <div class="sales-chart-wrap">
                    <svg class="sales-chart" viewBox="0 0 <?= $chartWidth; ?> <?= $chartHeight; ?>" role="img" aria-labelledby="sales-chart-title sales-chart-description">
                        <title id="sales-chart-title">Sales Overview for the last 7 days</title>
                        <desc id="sales-chart-description">Daily sales totals in Malaysian Ringgit from <?= dashboardEscape($salesTrend[0]['display_date']); ?> to <?= dashboardEscape($salesTrend[6]['display_date']); ?>.</desc>
                        <?php for ($gridIndex = 0; $gridIndex <= 4; $gridIndex++): ?>
                            <?php
                            $gridY = $chartTop + (($chartPlotHeight / 4) * $gridIndex);
                            $gridValue = $chartMaximum * (1 - ($gridIndex / 4));
                            ?>
                            <line class="sales-chart-grid" x1="<?= $chartLeft; ?>" y1="<?= number_format($gridY, 2, '.', ''); ?>" x2="<?= $chartWidth - $chartRight; ?>" y2="<?= number_format($gridY, 2, '.', ''); ?>"></line>
                            <text class="sales-chart-y-label" x="<?= $chartLeft - 12; ?>" y="<?= number_format($gridY + 4, 2, '.', ''); ?>">RM <?= number_format($gridValue, 0); ?></text>
                        <?php endfor; ?>
                        <text class="sales-chart-axis-title sales-chart-y-title" x="18" y="<?= $chartTop + ($chartPlotHeight / 2); ?>">Total Sales (RM)</text>
                        <text class="sales-chart-axis-title" x="<?= $chartLeft + ($chartPlotWidth / 2); ?>" y="<?= $chartHeight - 5; ?>">Date</text>
                        <?php foreach ($salesTrend as $trendDay): ?>
                            <g class="sales-chart-bar-group" tabindex="0" role="img" aria-label="<?= dashboardEscape($trendDay['display_date']); ?>, Sales RM <?= number_format($trendDay['total'], 2); ?>" data-date="<?= dashboardEscape($trendDay['display_date']); ?>" data-sales="<?= number_format($trendDay['total'], 2, '.', ''); ?>">
                                <rect class="sales-chart-bar-hit-area" x="<?= number_format($trendDay['hit_x'], 2, '.', ''); ?>" y="<?= $chartTop; ?>" width="<?= number_format($chartBarSlotWidth, 2, '.', ''); ?>" height="<?= $chartPlotHeight; ?>"></rect>
                                <rect class="sales-chart-bar" x="<?= number_format($trendDay['bar_x'], 2, '.', ''); ?>" y="<?= number_format($trendDay['bar_y'], 2, '.', ''); ?>" width="<?= number_format($chartBarWidth, 2, '.', ''); ?>" height="<?= number_format($trendDay['bar_height'], 2, '.', ''); ?>" rx="6" ry="6"></rect>
                                <text class="sales-chart-x-label" x="<?= number_format($trendDay['bar_center_x'], 2, '.', ''); ?>" y="<?= $chartHeight - 25; ?>"><?= dashboardEscape($trendDay['label']); ?></text>
                            </g>
                        <?php endforeach; ?>
                    </svg>
                    <div class="sales-chart-tooltip" id="sales-chart-tooltip" role="status" aria-live="polite"></div>
                </div>
            </section>

            <section class="panel attendance-overview-panel" aria-labelledby="attendance-overview-title">
                <div class="panel-header">
                    <div>
                        <h3 id="attendance-overview-title">Attendance Overview</h3>
                        <span>Today's employee attendance</span>
                    </div>
                </div>
                <div class="attendance-overview-content">
                    <dl class="attendance-metrics">
                        <div>
                            <dt>Present Today</dt>
                            <dd><?= $presentEmployees; ?></dd>
                        </div>
                        <div>
                            <dt>Total Employees</dt>
                            <dd><?= $eligibleEmployees; ?></dd>
                        </div>
                    </dl>
                    <div class="attendance-rate-block">
                        <div class="attendance-rate-heading">
                            <span>Attendance Rate</span>
                            <strong><?= dashboardEscape($attendanceRateDisplay); ?>%</strong>
                        </div>
                        <div class="attendance-progress" role="progressbar" aria-label="Today's employee attendance rate" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= number_format($attendanceRate, 1, '.', ''); ?>">
                            <span style="width: <?= number_format(min(100, max(0, $attendanceRate)), 1, '.', ''); ?>%;"></span>
                        </div>
                    </div>
                </div>
            </section>
            </section>

            <section class="security-overview panel" aria-labelledby="security-overview-title">
                <div class="section-header">
                    <div>
                        <h3 id="security-overview-title">Security Overview</h3>
                        <p>Authentication and account security status</p>
                    </div>
                </div>
                <div class="security-cards">
                    <article class="security-card">
                        <p>Unsuccessful Logins</p>
                        <strong><?php echo (int)$securityAuditSummary['unsuccessful_password_logins']; ?></strong>
                        <span>Password login events that were unsuccessful today.</span>
                    </article>
                    <article class="security-card">
                        <p>Locked Accounts</p>
                        <strong><?php echo (int)$securityAccountSummary['locked_accounts']; ?></strong>
                        <span>Active accounts currently under password lockout.</span>
                    </article>
                    <article class="security-card">
                        <p>Face Enrollment</p>
                        <strong><?php echo (int)$securityAccountSummary['face_enrolled_users']; ?> / <?php echo (int)$securityAccountSummary['active_users']; ?></strong>
                        <span>Active users enrolled.</span>
                    </article>
                    <article class="security-card">
                        <p>Auth Events Today</p>
                        <strong><?php echo (int)$securityAuditSummary['authentication_events']; ?></strong>
                        <span>Authentication activity recorded today.</span>
                    </article>
                </div>
            </section>

            <section class="dashboard-grid">
                <div class="panel panel-wide">
                    <div class="panel-header">
                        <h3>Recent Sales Transactions</h3>
                        <span>Latest completed POS sales</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table transactions-table">
                            <thead>
                                <tr>
                                    <th class="text-center-col col-id">Receipt No.</th>
                                    <th class="text-center-col col-type">Payment Method</th>
                                    <th class="text-right-col col-amount">Amount</th>
                                    <th class="text-center-col col-time">Date &amp; Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recentSales) : ?>
                                    <tr><td colspan="4" class="text-center-col">No sales transactions have been recorded.</td></tr>
                                <?php else : ?>
                                    <?php foreach ($recentSales as $sale) : ?>
                                        <tr>
                                            <td class="text-center-col col-id"><?php echo dashboardEscape($sale['receipt_no']); ?></td>
                                            <td class="text-center-col col-type"><?php echo dashboardEscape($sale['payment_method']); ?></td>
                                            <td class="text-right-col col-amount">RM <?php echo number_format((float)$sale['total_amount'], 2); ?></td>
                                            <td class="text-center-col col-time"><?php echo dashboardEscape((new DateTimeImmutable($sale['created_at'], $timezone))->format('d M Y, h:i A')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel panel-column">
                    <div class="panel-header">
                        <h3>Low Stock Alerts</h3>
                        <span>Ingredients that need ordering soon</span>
                    </div>
                    <ul class="alert-list">
                        <?php if (!$lowStockAlerts) : ?>
                            <li><div><strong>No low stock alerts</strong><small>All products are outside the Low Stock status.</small></div></li>
                        <?php else : ?>
                            <?php foreach ($lowStockAlerts as $product) : ?>
                                <li>
                                    <div>
                                        <strong><?php echo dashboardEscape($product['product_name']); ?></strong>
                                        <small><?php echo dashboardEscape($product['product_id']); ?> &bull; Stock: <?php echo dashboardEscape(dashboardStock($product['current_stock'])); ?> <?php echo dashboardEscape($product['unit']); ?></small>
                                    </div>
                                    <span class="badge badge-warning">Low</span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="panel panel-column">
                    <div class="panel-header">
                        <h3>Product Expiry Alerts</h3>
                        <span>Items nearing expiry date</span>
                    </div>
                    <ul class="alert-list">
                        <?php if (!$notifications) : ?>
                            <li><div><strong>No expiry alerts</strong><small>No expired or soon-to-expire batches were found.</small></div></li>
                        <?php else : ?>
                            <?php foreach (array_slice($notifications, 0, 5) as $notification) : ?>
                                <li>
                                    <div>
                                        <strong><?php echo dashboardEscape($notification['product_name']); ?></strong>
                                        <small>Batch <?php echo dashboardEscape($notification['batch_no']); ?> &bull; <?php echo dashboardEscape((new DateTimeImmutable($notification['expiry_date'], $timezone))->format('d M Y')); ?></small>
                                    </div>
                                    <span class="badge <?php echo $notification['days_remaining'] < 0 ? 'badge-danger' : 'badge-warning'; ?>"><?php echo dashboardEscape($notification['message']); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="panel panel-column">
                    <div class="panel-header">
                        <h3>Recent System Activities</h3>
                        <span>Audit events from the admin system</span>
                    </div>
                    <ul class="activity-list">
                        <?php if (!$recentActivities) : ?>
                            <li>
                                <div>
                                    <strong>No system activities recorded</strong>
                                    <small>General audit events will appear here when available.</small>
                                </div>
                            </li>
                        <?php else : ?>
                            <?php foreach ($recentActivities as $activity) : ?>
                                <li>
                                    <div>
                                        <strong><?php echo dashboardEscape($activity['module'] . ' / ' . $activity['action'] . ' / ' . $activity['result']); ?></strong>
                                        <small>
                                            <?php echo dashboardEscape((new DateTimeImmutable($activity['created_at'], $timezone))->format('d M Y, h:i A')); ?>
                                            &bull; <?php echo dashboardEscape($activity['actor_name'] ?: 'System'); ?>
                                            <?php if (!empty($activity['description'])) : ?>
                                                &bull; <?php echo dashboardEscape($activity['description']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-GQF1T9hLUo4LM6De3askwAdnGFfdvK98zhkYJ2vQVq7Z2uRjrkG4RwF9g+0kI2PW" crossorigin="anonymous"></script>
    <script src="assets/js/dashboard.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const chartWrap = document.querySelector('.sales-chart-wrap');
        const chart = document.querySelector('.sales-chart');
        const tooltip = document.getElementById('sales-chart-tooltip');
        if (!chartWrap || !chart || !tooltip) return;

        function showSalesTooltip(point, clientX, clientY) {
            tooltip.innerHTML = '<strong>' + point.dataset.date + '</strong><span>Sales: RM ' + Number(point.dataset.sales).toFixed(2) + '</span>';
            const wrapRect = chartWrap.getBoundingClientRect();
            tooltip.style.left = Math.min(Math.max(clientX - wrapRect.left, 86), wrapRect.width - 86) + 'px';
            tooltip.style.top = Math.max(clientY - wrapRect.top - 12, 18) + 'px';
            tooltip.classList.add('visible');
        }

        chart.querySelectorAll('.sales-chart-bar-group').forEach(function (point) {
            point.addEventListener('mouseenter', function (event) { showSalesTooltip(point, event.clientX, event.clientY); });
            point.addEventListener('mousemove', function (event) { showSalesTooltip(point, event.clientX, event.clientY); });
            point.addEventListener('mouseleave', function () { tooltip.classList.remove('visible'); });
            point.addEventListener('focus', function () {
                const pointRect = point.getBoundingClientRect();
                showSalesTooltip(point, pointRect.left + (pointRect.width / 2), pointRect.top);
            });
            point.addEventListener('blur', function () { tooltip.classList.remove('visible'); });
        });
    });
    </script>
</body>
</html>

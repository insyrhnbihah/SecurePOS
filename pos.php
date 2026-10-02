<?php
// SecurePOS Point of Sale
// Point of Sale interface for Restoran Kencana Sari
require_once __DIR__ . '/config/auth.php';
startSecureSession();
$posRole = strtolower(trim(currentUserRole()));
if (!isAuthenticated() || !in_array($posRole, ['cashier', 'manager'], true)) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';

if (empty($_SESSION['pos_csrf_token']) || !is_string($_SESSION['pos_csrf_token'])) {
    $_SESSION['pos_csrf_token'] = bin2hex(random_bytes(32));
}
$posCsrfToken = $_SESSION['pos_csrf_token'];

$notifications = [];
if ($posRole === 'manager') {
    require_once __DIR__ . '/config/expiry_notifications.php';
    $notifications = getExpiryNotifications($mysqli);
}

$searchQuery = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$drinkFilter = trim($_GET['drink'] ?? 'all');
$allowedCategories = ['Food', 'Bread', 'Beverages'];
$allowedDrinkFilters = ['all', 'hot', 'iced', 'canned'];
if (!in_array($drinkFilter, $allowedDrinkFilters, true)) {
    $drinkFilter = 'all';
}
$allowedTableNumbers = array_map(static function ($number) {
    return 'Table ' . $number;
}, range(1, 10));

$query = "SELECT id, item_code, item_name, category, price, availability_status FROM menu_items WHERE availability_status = 'Available'";
$params = [];
$types = '';
$conditions = [];

if ($searchQuery !== '') {
    $conditions[] = '(LOWER(item_name) LIKE ? OR LOWER(item_code) LIKE ? OR LOWER(category) LIKE ?)';
    $searchTerm = '%' . strtolower($searchQuery) . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= 'sss';
}

if ($categoryFilter !== '' && in_array($categoryFilter, $allowedCategories, true)) {
    $conditions[] = 'category = ?';
    $params[] = $categoryFilter;
    $types .= 's';
}

if ($categoryFilter === 'Beverages') {
    if ($drinkFilter === 'hot') {
        $conditions[] = 'LOWER(item_name) LIKE ?';
        $params[] = '%panas%';
        $types .= 's';
    } elseif ($drinkFilter === 'iced') {
        $conditions[] = 'LOWER(item_name) LIKE ?';
        $params[] = '%sejuk%';
        $types .= 's';
    } elseif ($drinkFilter === 'canned') {
        $conditions[] = 'item_name = ?';
        $params[] = 'Air Tin';
        $types .= 's';
    }
}

if (!empty($conditions)) {
    $query .= ' AND ' . implode(' AND ', $conditions);
}

$query .= ' ORDER BY item_name ASC';

$stmt = $mysqli->prepare($query);
if (!$stmt) {
    error_log('POS menu query prepare failed: ' . $mysqli->error);
    die('Database operation failed. Please try again.');
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$menuItems = [];
while ($row = $result->fetch_assoc()) {
    $menuItems[] = $row;
}
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request payload.']);
        exit;
    }

    $submittedCsrfToken = $payload['csrfToken'] ?? null;
    if (!is_string($submittedCsrfToken) || !hash_equals($posCsrfToken, $submittedCsrfToken)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unable to process payment. Please refresh and try again.']);
        exit;
    }

    $paymentMethod = trim((string)($payload['paymentMethod'] ?? ''));
    $orderType = trim((string)($payload['orderType'] ?? ''));
    $submittedTableNumber = trim((string)($payload['tableNumber'] ?? ''));
    $amountPaid = isset($payload['amountPaid']) ? (float)$payload['amountPaid'] : 0.0;
    $cartItems = $payload['items'] ?? [];

    if (!in_array($paymentMethod, ['Cash', 'QR Payment'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid payment method.']);
        exit;
    }

    if (!in_array($orderType, ['Dine In', 'Take Away'], true)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please select a valid order type.']);
        exit;
    }

    if ($orderType === 'Dine In') {
        if ($submittedTableNumber === '') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please select a table number first.']);
            exit;
        }
        if (!in_array($submittedTableNumber, $allowedTableNumbers, true)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Please select a valid table number.']);
            exit;
        }
        $tableNumber = $submittedTableNumber;
    } else {
        // Take Away never retains a submitted table value, including manipulated requests.
        $tableNumber = null;
    }

    if (!is_array($cartItems) || count($cartItems) === 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'The order is empty.']);
        exit;
    }

    $validatedItems = [];
    $serverTotal = 0.0;

    foreach ($cartItems as $cartItem) {
        if (!is_array($cartItem) || !isset($cartItem['code'], $cartItem['quantity'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid cart item data.']);
            exit;
        }

        $itemCode = trim((string)$cartItem['code']);
        $quantity = (int)$cartItem['quantity'];

        if ($itemCode === '' || $quantity <= 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid cart item quantities.']);
            exit;
        }

        $menuStmt = $mysqli->prepare('SELECT id, item_code, item_name, price, availability_status FROM menu_items WHERE item_code = ? LIMIT 1');
        if (!$menuStmt) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unable to fetch menu data.']);
            exit;
        }

        $menuStmt->bind_param('s', $itemCode);
        $menuStmt->execute();
        $menuResult = $menuStmt->get_result();
        $menuRow = $menuResult->fetch_assoc();
        $menuStmt->close();

        if (!$menuRow) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'One or more menu items could not be found.']);
            exit;
        }

        if ($menuRow['availability_status'] !== 'Available') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'One or more menu items are unavailable.']);
            exit;
        }

        $unitPrice = (float)$menuRow['price'];
        $subtotal = $unitPrice * $quantity;
        $serverTotal += $subtotal;

        $validatedItems[] = [
            'menu_item_id' => (int)$menuRow['id'],
            'item_code' => $menuRow['item_code'],
            'item_name' => $menuRow['item_name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $subtotal,
        ];
    }

    if ($paymentMethod === 'Cash') {
        if ($amountPaid < $serverTotal) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Insufficient amount.']);
            exit;
        }
        $changeAmount = $amountPaid - $serverTotal;
    } else {
        if (abs($amountPaid - $serverTotal) > 0.000001) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'QR payment amount must match the total.']);
            exit;
        }
        $changeAmount = 0.0;
    }

    $maxIdResult = $mysqli->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM sales');
    if (!$maxIdResult) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unable to prepare receipt number.']);
        exit;
    }
    $maxIdRow = $maxIdResult->fetch_assoc();
    $nextId = (int)$maxIdRow['max_id'] + 1;
    $receiptNo = 'RCP-' . date('Ymd') . '-' . str_pad((string)$nextId, 4, '0', STR_PAD_LEFT);

    $mysqli->begin_transaction();

    try {
        $saleStmt = $mysqli->prepare('INSERT INTO sales (receipt_no, total_amount, payment_method, order_type, table_number, amount_paid, change_amount) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$saleStmt) {
            throw new Exception('Unable to create sales record.');
        }

        $saleStmt->bind_param('sdsssdd', $receiptNo, $serverTotal, $paymentMethod, $orderType, $tableNumber, $amountPaid, $changeAmount);
        if (!$saleStmt->execute()) {
            throw new Exception('Unable to save sales record.');
        }

        $saleId = $mysqli->insert_id;

        $saleItemStmt = $mysqli->prepare('INSERT INTO sale_items (sale_id, menu_item_id, item_code, item_name, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$saleItemStmt) {
            throw new Exception('Unable to prepare sale item insert.');
        }

        foreach ($validatedItems as $validatedItem) {
            $saleItemStmt->bind_param('iissddd', $saleId, $validatedItem['menu_item_id'], $validatedItem['item_code'], $validatedItem['item_name'], $validatedItem['quantity'], $validatedItem['unit_price'], $validatedItem['subtotal']);
            if (!$saleItemStmt->execute()) {
                throw new Exception('Unable to save sale item.');
            }
        }

        $saleItemStmt->close();
        $saleStmt->close();
        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        error_log('POS sale transaction failed: ' . $e->getMessage());
        auditLog(
            $mysqli,
            (int)$_SESSION['user_id'],
            currentUserName(),
            currentUserRole(),
            'POS',
            'Complete Sale',
            'Failed',
            'Sale transaction failed.'
        );
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Payment failed. Please try again.']);
        exit;
    }

    $auditPaymentMethod = $paymentMethod === 'QR Payment' ? 'QR' : 'Cash';
    auditLog(
        $mysqli,
        (int)$_SESSION['user_id'],
        currentUserName(),
        currentUserRole(),
        'POS',
        'Complete Sale',
        'Success',
        'Sale ' . $receiptNo . ' completed for RM ' . number_format($serverTotal, 2)
            . ' using ' . $auditPaymentMethod . ' (' . $orderType
            . ($tableNumber !== null ? ', ' . $tableNumber : '') . ').'
    );

    try {
        $receiptStmt = $mysqli->prepare('SELECT receipt_no, total_amount, payment_method, order_type, table_number, amount_paid, change_amount, created_at FROM sales WHERE id = ?');
        if (!$receiptStmt) {
            throw new RuntimeException('Unable to prepare receipt retrieval.');
        }
        $receiptStmt->bind_param('i', $saleId);
        if (!$receiptStmt->execute()) {
            throw new RuntimeException('Unable to retrieve receipt.');
        }
        $receiptResult = $receiptStmt->get_result();
        $receiptRow = $receiptResult->fetch_assoc();
        $receiptStmt->close();
        if (!$receiptRow) {
            throw new RuntimeException('Receipt was not found.');
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Payment completed successfully.',
            'receipt' => [
                'receipt_no' => $receiptRow['receipt_no'],
                'total_amount' => (float)$receiptRow['total_amount'],
                'payment_method' => $receiptRow['payment_method'],
                'order_type' => $receiptRow['order_type'],
                'table_number' => $receiptRow['table_number'],
                'amount_paid' => (float)$receiptRow['amount_paid'],
                'change_amount' => (float)$receiptRow['change_amount'],
                'created_at' => $receiptRow['created_at'],
                'items' => $validatedItems,
            ],
        ]);
        exit;
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Payment completed, but the receipt could not be displayed. Receipt: ' . $receiptNo . '.',
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecurePOS Point of Sale | Restoran Kencana Sari</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260820-sidebar">
    <style>
        .pos-page-header {
            margin-bottom: 24px;
        }

        .pos-page-header h1 {
            margin: 0;
            color: var(--accent);
            font-size: 1.85rem;
        }

        .pos-page-header p {
            margin: 8px 0 0;
            color: var(--muted);
        }

        .pos-workspace {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(320px, 0.8fr);
            gap: 20px;
            align-items: start;
        }

        .pos-panel {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 20px 40px rgba(2, 8, 23, 0.3);
        }

        .pos-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .pos-panel-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0;
        }

        .pos-search-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }

        .pos-search-input {
            flex: 1;
            min-width: 220px;
            height: 48px;
            padding: 0 16px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
        }

        .pos-search-input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .filter-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 18px;
        }

        .category-filter,
        .category-filter:visited,
        .beverage-subfilter,
        .beverage-subfilter:visited {
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
            color: var(--muted);
            border-radius: 999px;
            padding: 8px 14px;
            transition: all 0.2s ease;
        }

        .category-filter:hover,
        .category-filter:focus-visible,
        .beverage-subfilter:hover,
        .beverage-subfilter:focus-visible {
            background: rgba(85, 214, 209, 0.08);
            color: var(--text);
            border-color: rgba(85, 214, 209, 0.2);
        }

        .category-filter.is-selected,
        .category-filter.is-selected:visited,
        .category-filter.is-selected:hover,
        .category-filter.is-selected:focus-visible,
        .beverage-subfilter.is-selected,
        .beverage-subfilter.is-selected:visited,
        .beverage-subfilter.is-selected:hover,
        .beverage-subfilter.is-selected:focus-visible {
            border: 1px solid rgba(85, 214, 209, 0.28);
            background: rgba(85, 214, 209, 0.14);
            color: var(--text);
            border-radius: 999px;
        }

        .beverage-filter-group {
            margin-top: -8px;
        }

        .beverage-subfilter {
            padding: 6px 12px;
            font-size: 0.84rem;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .menu-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .menu-card h3 {
            margin: 0;
            font-size: 1rem;
            color: var(--text);
        }

        .menu-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .menu-price {
            margin-top: auto;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--accent);
        }

        .btn-add-item {
            border: none;
            background: linear-gradient(135deg, #55d6d1, #3db5ff);
            color: #07101c;
            border-radius: 12px;
            padding: 10px 14px;
            font-weight: 700;
            width: 100%;
        }

        .order-summary {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .order-type-section {
            display: grid;
            gap: 8px;
            padding: 12px;
            border: 1px solid rgba(85, 214, 209, 0.16);
            border-radius: 14px;
            background: rgba(85, 214, 209, 0.045);
        }

        .order-type-label {
            color: var(--muted);
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.12em;
        }

        .order-type-options {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
        }

        .order-type-option-btn {
            min-height: 52px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.045);
            color: var(--text);
            font-weight: 700;
            transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .order-type-option-btn:hover {
            border-color: rgba(85, 214, 209, 0.4);
            transform: translateY(-1px);
        }

        .order-type-option-btn.active {
            border-color: #55d6d1;
            background: linear-gradient(135deg, rgba(85, 214, 209, 0.24), rgba(61, 181, 255, 0.16));
            box-shadow: inset 0 0 0 1px rgba(85, 214, 209, 0.35), 0 0 18px rgba(85, 214, 209, 0.1);
        }

        .order-type-warning {
            min-height: 1.1em;
            color: var(--red);
            font-size: 0.82rem;
        }

        .table-number-field { display: none; gap: 7px; }
        .table-number-field.visible { display: grid; }
        .table-number-select {
            width: 100%; min-height: 42px; padding: 9px 12px;
            border: 1px solid rgba(255, 255, 255, 0.12); border-radius: 10px;
            background: #101d2d; color: var(--text);
        }

        .order-empty {
            padding: 24px 10px;
            text-align: center;
            color: var(--muted);
            border: 1px dashed rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.02);
        }

        .order-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .order-item {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .order-item-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
        }

        .order-item-name {
            font-weight: 600;
            color: var(--text);
        }

        .order-item-price {
            color: var(--accent);
            font-size: 0.95rem;
            white-space: nowrap;
        }

        .order-item-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .qty-control {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 999px;
            padding: 4px;
        }

        .qty-btn {
            width: 28px;
            height: 28px;
            border: none;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
            font-size: 1rem;
            line-height: 1;
        }

        .qty-btn:hover {
            background: rgba(255, 255, 255, 0.16);
        }

        .qty-value {
            min-width: 18px;
            text-align: center;
            font-weight: 700;
            color: var(--text);
        }

        .remove-item {
            background: transparent;
            border: none;
            color: var(--red);
            font-size: 1.05rem;
            padding: 2px 4px;
        }

        .order-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--muted);
            font-size: 0.95rem;
        }

        .order-total-row strong {
            color: var(--text);
            font-size: 1.05rem;
        }

        .btn-payment {
            border: none;
            background: linear-gradient(135deg, #55d6d1, #3db5ff);
            color: #07101c;
            border-radius: 12px;
            padding: 12px 16px;
            font-weight: 700;
            width: 100%;
            margin-top: 6px;
        }

        .btn-payment:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        .payment-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(4, 8, 18, 0.78);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 2000;
        }

        .payment-modal-backdrop.visible {
            display: flex;
        }

        .payment-modal {
            width: min(520px, 100%);
            background: rgba(15, 23, 41, 0.98);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        }

        .payment-modal h3 {
            margin: 0 0 8px;
            font-size: 1.25rem;
            color: var(--text);
        }

        .payment-modal p {
            margin: 0 0 18px;
            color: var(--muted);
        }

        .payment-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 16px;
        }

        .payment-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }

        .payment-field label {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .payment-options {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .payment-option-btn {
            width: 100%;
            height: 46px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .payment-option-btn.active {
            background: rgba(85, 214, 209, 0.14);
            border-color: rgba(85, 214, 209, 0.35);
            color: var(--text);
            box-shadow: inset 0 0 0 1px rgba(85, 214, 209, 0.2);
        }

        .payment-input {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
        }

        .payment-input:focus {
            outline: none;
            border-color: rgba(85, 214, 209, 0.35);
            box-shadow: 0 0 0 3px rgba(85, 214, 209, 0.12);
        }

        .payment-input::-webkit-outer-spin-button,
        .payment-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .payment-input[type=number] {
            -moz-appearance: textfield;
        }

        .payment-results {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            color: var(--muted);
        }

        .payment-row strong {
            color: var(--text);
        }

        .payment-warning {
            margin-top: 10px;
            color: var(--red);
            font-size: 0.9rem;
            min-height: 1.2em;
        }

        .pos-feedback {
            display: none;
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text);
        }

        .pos-feedback.success {
            color: var(--accent);
            border-color: rgba(85, 214, 209, 0.24);
        }

        .pos-feedback.error {
            color: var(--red);
            border-color: rgba(252, 92, 125, 0.24);
        }

        .payment-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }

        .receipt-modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(4, 8, 18, 0.82);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 2100;
        }

        .receipt-modal-backdrop.visible {
            display: flex;
        }

        .receipt-modal {
            width: min(560px, 100%);
            max-height: 90vh;
            overflow: auto;
            background: #ffffff;
            color: #111827;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.35);
        }

        .receipt-header {
            text-align: center;
            border-bottom: 1px dashed #cbd5e1;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .receipt-header h3 {
            margin: 0 0 6px;
            font-size: 1.25rem;
            color: #111827;
        }

        .receipt-status {
            margin: 0 0 10px;
            font-weight: 700;
            color: #0f766e;
        }

        .receipt-info {
            display: grid;
            gap: 6px;
            margin-bottom: 12px;
            font-size: 0.95rem;
        }

        .receipt-info-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
        }

        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 12px;
        }

        .receipt-table th,
        .receipt-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 0.92rem;
        }

        .receipt-table th {
            font-weight: 700;
            background: #f8fafc;
        }

        .receipt-summary {
            border-top: 1px dashed #cbd5e1;
            padding-top: 10px;
            display: grid;
            gap: 6px;
        }

        .receipt-summary-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.95rem;
        }

        .receipt-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 16px;
        }

        .btn-print,
        .btn-done,
        .btn-cancel,
        .btn-complete {
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 700;
        }

        .btn-cancel {
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
        }

        .btn-complete {
            background: linear-gradient(135deg, #55d6d1, #3db5ff);
            color: #07101c;
        }

        .btn-print,
        .btn-done {
            border: none;
            border-radius: 12px;
            padding: 10px 16px;
            font-weight: 700;
        }

        .btn-print {
            background: #111827;
            color: #ffffff;
        }

        .btn-done {
            background: linear-gradient(135deg, #55d6d1, #3db5ff);
            color: #07101c;
        }

        .btn-complete:disabled {
            cursor: not-allowed;
            opacity: 0.6;
        }

        @page {
            margin: 8mm;
        }

        @media print {
            html,
            body {
                width: 100%;
                margin: 0;
                padding: 0;
                background: #ffffff !important;
                color: #000000 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            body > *:not(.receipt-modal-backdrop) {
                display: none !important;
            }

            .receipt-modal-backdrop {
                position: static !important;
                display: block !important;
                inset: auto !important;
                background: #ffffff !important;
                padding: 0 !important;
                backdrop-filter: none !important;
            }

            .receipt-modal {
                width: 78vw !important;
                max-width: 78vw !important;
                min-width: 320px !important;
                max-height: none !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                padding: 10px 14px !important;
                margin: 0 auto 0 0 !important;
                overflow: visible !important;
                background: #ffffff !important;
                color: #000000 !important;
            }

            .receipt-content {
                width: 100%;
                box-sizing: border-box;
                padding: 0;
                margin: 0;
                font-family: "Courier New", Courier, monospace;
                font-size: 13px;
                line-height: 1.35;
                color: #000000 !important;
            }

            .receipt-header {
                text-align: center;
                border-bottom: 1px dashed #000000;
                padding-bottom: 6px;
                margin: 0 0 7px;
            }

            .receipt-header h3 {
                font-size: 14px;
                margin: 0 0 2px;
            }

            .receipt-status {
                margin: 0;
                font-size: 12px;
            }

            .receipt-info {
                gap: 3px;
                margin: 0 0 7px;
                font-size: 12px;
            }

            .receipt-info-row {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                align-items: flex-start;
            }

            .receipt-table {
                width: 100%;
                margin: 4px 0 7px;
                border-collapse: collapse;
            }

            .receipt-table th,
            .receipt-table td {
                padding: 3px 0;
                border-bottom: 1px dashed #000000;
                text-align: left;
                font-size: 12px;
                vertical-align: top;
                line-height: 1.3;
            }

            .receipt-table th {
                font-weight: 700;
                background: transparent;
            }

            .receipt-table td:first-child {
                width: 52%;
                white-space: normal;
                word-break: break-word;
                overflow-wrap: anywhere;
            }

            .receipt-table td:nth-child(2),
            .receipt-table td:nth-child(3),
            .receipt-table td:nth-child(4) {
                white-space: nowrap;
                text-align: right;
                padding-left: 6px;
            }

            .receipt-summary {
                border-top: 1px dashed #000000;
                padding-top: 5px;
                margin-top: 5px;
                gap: 3px;
            }

            .receipt-summary-row {
                display: flex;
                justify-content: space-between;
                gap: 10px;
                font-size: 12px;
            }

            .receipt-actions {
                display: none !important;
            }
        }

        @media (max-width: 1100px) {
            .pos-workspace {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .menu-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
                <?php if ($posRole === 'manager') : ?>
                    <a href="dashboard.php" class="nav-link">Dashboard</a>
                    <a href="pos.php" class="nav-link active">Point of Sale</a>
                    <a href="inventory.php" class="nav-link">Inventory</a>
                    <a href="product_expiry.php" class="nav-link">Product Expiry</a>
                    <a href="attendance.php" class="nav-link">Employee Attendance</a>
                    <a href="users.php" class="nav-link">Users</a>
                    <a href="reports.php" class="nav-link">Reports</a>
                    <a href="audit_logs.php" class="nav-link">Audit Logs</a>
                <?php else : ?>
                    <a href="pos.php" class="nav-link active">Point of Sale</a>
                    <a href="inventory_availability.php" class="nav-link">Inventory Availability</a>
                    <a href="transaction_history.php" class="nav-link">Transaction History</a>
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
                    <p class="breadcrumb">SecurePOS / Point of Sale</p>
                    <h2>Point of Sale</h2>
                </div>
                <div class="topbar-actions">
                    <div class="topbar-chip secondary">
                        <span id="current-datetime">Loading...</span>
                    </div>
                    <?php if ($posRole === 'manager') : ?>
                        <?php echo renderExpiryNotificationBell($notifications, 'product_expiry.php'); ?>
                    <?php endif; ?>
                    <div class="topbar-profile">
                        <span class="profile-initials"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></span>
                        <div>
                            <p><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p>
                            <small><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                    </div>
                </div>
            </header>

            <section class="pos-page-header">
                <h1>Point of Sale</h1>
                <p>Process customer orders and payments.</p>
            </section>

            <div id="pos-feedback" class="pos-feedback"></div>

            <section class="pos-workspace">
                <div class="pos-panel">
                    <div class="pos-panel-header">
                        <h3 class="pos-panel-title">Menu</h3>
                    </div>

                    <form method="get" action="pos.php" class="pos-search-row">
                        <input type="text" name="search" class="pos-search-input" placeholder="Search menu..." value="<?= htmlspecialchars($searchQuery); ?>">
                        <?php if ($categoryFilter !== ''): ?>
                            <input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter, ENT_QUOTES); ?>">
                        <?php endif; ?>
                        <?php if ($categoryFilter === 'Beverages' && $drinkFilter !== 'all'): ?>
                            <input type="hidden" name="drink" value="<?= htmlspecialchars($drinkFilter, ENT_QUOTES); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn-add-item" style="width:auto; min-width:120px;">Search</button>
                    </form>

                    <div class="filter-group">
                        <a href="pos.php" class="category-filter <?= $categoryFilter === '' ? 'is-selected' : '' ?>">All</a>
                        <?php foreach ($allowedCategories as $filterCategory): ?>
                            <a href="pos.php?category=<?= urlencode($filterCategory) ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="category-filter <?= $categoryFilter === $filterCategory ? 'is-selected' : '' ?>">
                                <?= htmlspecialchars($filterCategory) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($categoryFilter === 'Beverages'): ?>
                        <?php
                        $drinkFilterLabels = [
                            'all' => 'All Drinks',
                            'hot' => 'Hot',
                            'iced' => 'Iced',
                            'canned' => 'Canned',
                        ];
                        ?>
                        <div class="filter-group beverage-filter-group" aria-label="Beverage filters">
                            <?php foreach ($drinkFilterLabels as $filterValue => $filterLabel): ?>
                                <a href="pos.php?category=Beverages<?= $filterValue !== 'all' ? '&drink=' . urlencode($filterValue) : '' ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="beverage-subfilter <?= $drinkFilter === $filterValue ? 'is-selected' : '' ?>">
                                    <?= htmlspecialchars($filterLabel) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($menuItems)): ?>
                        <div class="menu-grid">
                            <?php foreach ($menuItems as $item): ?>
                                <?php
                                $displayItemName = $item['item_name'];
                                if ($categoryFilter === 'Beverages' && $drinkFilter === 'hot') {
                                    $displayItemName = preg_replace('/\s+Panas$/iu', '', $displayItemName);
                                } elseif ($categoryFilter === 'Beverages' && $drinkFilter === 'iced') {
                                    $displayItemName = preg_replace('/\s+Sejuk$/iu', '', $displayItemName);
                                } elseif ($item['category'] === 'Beverages') {
                                    $displayItemName = preg_replace('/\s+Panas$/iu', ' (Hot)', $displayItemName);
                                    $displayItemName = preg_replace('/\s+Sejuk$/iu', ' (Iced)', $displayItemName);
                                }
                                ?>
                                <article class="menu-card">
                                    <h3><?= htmlspecialchars($displayItemName); ?></h3>
                                    <div class="menu-meta">
                                        <span><?= htmlspecialchars($item['item_code']); ?></span>
                                        <span>•</span>
                                        <span><?= htmlspecialchars($item['category']); ?></span>
                                    </div>
                                    <div class="menu-price">RM <?= number_format((float) $item['price'], 2); ?></div>
                                    <button type="button" class="btn-add-item add-to-order"
                                        data-item-code="<?= htmlspecialchars($item['item_code'], ENT_QUOTES); ?>"
                                        data-item-name="<?= htmlspecialchars($item['item_name'], ENT_QUOTES); ?>"
                                        data-price="<?= (float) $item['price']; ?>">
                                        Add
                                    </button>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="order-empty">No available menu items match the current search.</div>
                    <?php endif; ?>
                </div>

                <aside class="pos-panel">
                    <div class="pos-panel-header">
                        <h3 class="pos-panel-title">Current Order</h3>
                    </div>
                    <div class="order-summary">
                        <div id="order-empty" class="order-empty">No items added yet.</div>
                        <div id="order-items" class="order-list"></div>
                        <div class="order-type-section">
                            <span class="order-type-label">ORDER TYPE</span>
                            <div class="order-type-options" role="group" aria-label="Order Type">
                                <button type="button" class="order-type-option-btn" data-order-type="Dine In" aria-pressed="false">🍽️ Dine In</button>
                                <button type="button" class="order-type-option-btn" data-order-type="Take Away" aria-pressed="false">🥡 Take Away</button>
                            </div>
                            <div class="table-number-field" id="table-number-field">
                                <label class="order-type-label" for="table-number">TABLE NUMBER</label>
                                <select class="table-number-select" id="table-number">
                                    <option value="">Select a table</option>
                                    <?php foreach ($allowedTableNumbers as $availableTableNumber) : ?>
                                        <option value="<?php echo htmlspecialchars($availableTableNumber, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($availableTableNumber, ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="order-type-warning" id="order-type-warning" role="alert"></div>
                        </div>
                        <div class="order-total-row">
                            <span>Subtotal</span>
                            <strong id="subtotal-display">RM 0.00</strong>
                        </div>
                        <div class="order-total-row">
                            <span>Total</span>
                            <strong id="total-display">RM 0.00</strong>
                        </div>
                        <button type="button" class="btn-payment" id="proceed-payment" disabled>Proceed to Payment</button>
                    </div>
                </aside>
            </section>
        </main>
    </div>

    <div class="payment-modal-backdrop" id="payment-modal-backdrop">
        <div class="payment-modal" role="dialog" aria-modal="true" aria-labelledby="payment-modal-title">
            <h3 id="payment-modal-title">Payment</h3>
            <p>Complete the order using the available payment methods.</p>
            <div class="payment-summary">
                <span>Order Total</span>
                <strong id="modal-total-display">RM 0.00</strong>
            </div>
            <div class="payment-summary">
                <span id="payment-order-type-summary">Order Type: —</span>
                <strong id="payment-table-summary"></strong>
            </div>

            <div class="payment-field">
                <label>Payment Method</label>
                <div class="payment-options" role="group" aria-label="Payment Method">
                    <button type="button" class="payment-option-btn" data-method="Cash">Cash</button>
                    <button type="button" class="payment-option-btn" data-method="QR Payment">QR Payment</button>
                </div>
            </div>

            <div class="payment-field" id="amount-paid-field">
                <label for="amount-paid">Amount Paid</label>
                <input type="number" id="amount-paid" class="payment-input" min="0" step="0.01" placeholder="0.00" disabled>
            </div>

            <div class="payment-results">
                <div class="payment-row">
                    <span>Amount Paid</span>
                    <strong id="payment-amount-display">RM 0.00</strong>
                </div>
                <div class="payment-row">
                    <span>Change</span>
                    <strong id="change-display">RM 0.00</strong>
                </div>
            </div>
            <div class="payment-warning" id="payment-warning"></div>

            <div class="payment-actions">
                <button type="button" class="btn-cancel" id="cancel-payment">Cancel</button>
                <button type="button" class="btn-complete" id="complete-payment" disabled>Complete Payment</button>
            </div>
        </div>
    </div>

    <div class="receipt-modal-backdrop" id="receipt-modal-backdrop">
        <div class="receipt-modal" role="dialog" aria-modal="true" aria-labelledby="receipt-modal-title">
            <div class="receipt-content" id="receipt-content"></div>
            <div class="receipt-actions">
                <button type="button" class="btn-print" id="print-receipt">Print Receipt</button>
                <button type="button" class="btn-done" id="done-receipt">Done</button>
            </div>
        </div>
    </div>

    <script>
        const posCsrfToken = <?php echo json_encode($posCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const orderItems = [];
        const orderItemsContainer = document.getElementById('order-items');
        const orderEmptyState = document.getElementById('order-empty');
        const subtotalDisplay = document.getElementById('subtotal-display');
        const totalDisplay = document.getElementById('total-display');
        const proceedPaymentButton = document.getElementById('proceed-payment');
        const paymentModal = document.getElementById('payment-modal-backdrop');
        const paymentOptionButtons = Array.from(document.querySelectorAll('.payment-option-btn[data-method]'));
        let selectedPaymentMethod = '';
        const orderTypeOptionButtons = Array.from(document.querySelectorAll('.order-type-option-btn'));
        const orderTypeWarning = document.getElementById('order-type-warning');
        let selectedOrderType = '';
        const tableNumberField = document.getElementById('table-number-field');
        const tableNumberSelect = document.getElementById('table-number');
        const paymentOrderTypeSummary = document.getElementById('payment-order-type-summary');
        const paymentTableSummary = document.getElementById('payment-table-summary');
        const amountPaidInput = document.getElementById('amount-paid');
        const amountPaidDisplay = document.getElementById('payment-amount-display');
        const changeDisplay = document.getElementById('change-display');
        const modalTotalDisplay = document.getElementById('modal-total-display');
        const paymentWarning = document.getElementById('payment-warning');
        const cancelPaymentButton = document.getElementById('cancel-payment');
        const completePaymentButton = document.getElementById('complete-payment');
        const posFeedback = document.getElementById('pos-feedback');
        const receiptModal = document.getElementById('receipt-modal-backdrop');
        const liveDateTime = document.getElementById('current-datetime');
        const receiptContent = document.getElementById('receipt-content');
        const printReceiptButton = document.getElementById('print-receipt');
        const doneReceiptButton = document.getElementById('done-receipt');
        let currentOrderTotal = 0;
        let receiptData = null;

        function formatCurrency(value) {
            return 'RM ' + value.toFixed(2);
        }

        function renderOrder() {
            if (orderItems.length === 0) {
                orderItemsContainer.innerHTML = '';
                orderEmptyState.style.display = 'block';
                subtotalDisplay.textContent = formatCurrency(0);
                totalDisplay.textContent = formatCurrency(0);
                proceedPaymentButton.disabled = true;
                return;
            }

            orderEmptyState.style.display = 'none';
            orderItemsContainer.innerHTML = orderItems.map((item) => `
                <div class="order-item">
                    <div class="order-item-top">
                        <div>
                            <div class="order-item-name">${item.name}</div>
                            <div class="order-item-price">${formatCurrency(item.price)}</div>
                        </div>
                        <button class="remove-item" type="button" data-item-code="${item.code}" aria-label="Remove ${item.name}">🗑</button>
                    </div>
                    <div class="order-item-controls">
                        <div class="qty-control">
                            <button class="qty-btn" type="button" data-action="decrease" data-item-code="${item.code}">−</button>
                            <span class="qty-value">${item.quantity}</span>
                            <button class="qty-btn" type="button" data-action="increase" data-item-code="${item.code}">+</button>
                        </div>
                        <div class="order-item-price">${formatCurrency(item.price * item.quantity)}</div>
                    </div>
                </div>
            `).join('');

            const subtotal = orderItems.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            currentOrderTotal = subtotal;
            subtotalDisplay.textContent = formatCurrency(subtotal);
            totalDisplay.textContent = formatCurrency(subtotal);
            modalTotalDisplay.textContent = formatCurrency(subtotal);
            proceedPaymentButton.disabled = false;
            updatePaymentSummary();
        }

        function addToOrder(item) {
            const existingItem = orderItems.find((entry) => entry.code === item.code);
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                orderItems.push({
                    code: item.code,
                    name: item.name,
                    price: item.price,
                    quantity: 1
                });
            }
            renderOrder();
        }

        function updatePaymentSummary() {
            const method = selectedPaymentMethod;
            let amountPaid = parseFloat(amountPaidInput.value || '0');
            let change = 0;
            let warningMessage = '';

            if (method === 'QR Payment') {
                amountPaid = currentOrderTotal;
                amountPaidInput.value = currentOrderTotal.toFixed(2);
                amountPaidInput.disabled = true;
                warningMessage = '';
            } else if (method === 'Cash') {
                amountPaidInput.disabled = false;
                if (amountPaid <= 0) {
                    warningMessage = '';
                } else if (amountPaid < currentOrderTotal) {
                    warningMessage = 'Insufficient amount';
                    change = 0;
                } else {
                    change = amountPaid - currentOrderTotal;
                    warningMessage = '';
                }
            } else {
                amountPaidInput.disabled = true;
                warningMessage = '';
            }

            if (method === 'QR Payment') {
                change = 0;
            }

            amountPaidDisplay.textContent = formatCurrency(amountPaid);
            changeDisplay.textContent = formatCurrency(Math.max(change, 0));
            paymentWarning.textContent = warningMessage;

            const isValidMethod = method === 'Cash' || method === 'QR Payment';
            const isValidOrderType = selectedOrderType === 'Dine In' || selectedOrderType === 'Take Away';
            const isValidAmount = method === 'QR Payment' || (amountPaid >= currentOrderTotal && amountPaid > 0);
            completePaymentButton.disabled = !isValidMethod || !isValidOrderType || !isValidAmount || currentOrderTotal <= 0;
        }

        function setPaymentMethod(method) {
            selectedPaymentMethod = method;
            paymentOptionButtons.forEach((button) => {
                button.classList.toggle('active', button.dataset.method === method);
            });
            updatePaymentSummary();
        }

        function setOrderType(orderType) {
            selectedOrderType = orderType;
            if (orderType === 'Dine In') {
                tableNumberField.classList.add('visible');
            } else {
                tableNumberField.classList.remove('visible');
                tableNumberSelect.value = '';
            }
            orderTypeOptionButtons.forEach((button) => {
                const isSelected = button.dataset.orderType === orderType;
                button.classList.toggle('active', isSelected);
                button.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
            });
            orderTypeWarning.textContent = '';
            updatePaymentSummary();
        }

        function resetPaymentModal() {
            selectedPaymentMethod = '';
            paymentOptionButtons.forEach((button) => button.classList.remove('active'));
            amountPaidInput.value = '';
            amountPaidInput.disabled = true;
            paymentWarning.textContent = '';
            completePaymentButton.disabled = true;
        }

        function resetOrderType() {
            selectedOrderType = '';
            tableNumberSelect.value = '';
            tableNumberField.classList.remove('visible');
            orderTypeOptionButtons.forEach((button) => {
                button.classList.remove('active');
                button.setAttribute('aria-pressed', 'false');
            });
            orderTypeWarning.textContent = '';
        }

        function showFeedback(message, type) {
            posFeedback.textContent = message;
            posFeedback.className = 'pos-feedback ' + type;
            posFeedback.style.display = message ? 'block' : 'none';
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function updateLiveDateTime() {
            if (!liveDateTime) {
                return;
            }

            const now = new Date();
            const formatter = new Intl.DateTimeFormat('en-GB', {
                timeZone: 'Asia/Kuala_Lumpur',
                year: 'numeric',
                month: 'long',
                day: '2-digit',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            liveDateTime.textContent = formatter.format(now);
        }

        function renderReceipt() {
            if (!receiptData) {
                receiptContent.innerHTML = '';
                return;
            }

            const createdAt = receiptData.created_at ? new Date(receiptData.created_at.replace(' ', 'T')) : new Date();
            const items = receiptData.items || [];
            const formatter = new Intl.DateTimeFormat('en-GB', {
                timeZone: 'Asia/Kuala_Lumpur',
                day: '2-digit',
                month: 'long',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
            const formattedCreatedAt = formatter.format(createdAt);
            const itemsMarkup = items.map((item) => `
                <tr>
                    <td>${escapeHtml(item.item_name || item.name || '')}</td>
                    <td>${item.quantity}</td>
                    <td>${formatCurrency(item.unit_price || 0)}</td>
                    <td>${formatCurrency(item.subtotal || 0)}</td>
                </tr>
            `).join('');

            receiptContent.innerHTML = `
                <div class="receipt-header">
                    <h3>Restoran Kencana Sari</h3>
                    <p class="receipt-status">Payment Successful</p>
                </div>
                <div class="receipt-info">
                    <div class="receipt-info-row"><span>Receipt No.</span><strong>${escapeHtml(receiptData.receipt_no || '')}</strong></div>
                    <div class="receipt-info-row"><span>Date &amp; Time</span><strong>${escapeHtml(formattedCreatedAt)}</strong></div>
                    <div class="receipt-info-row"><span>Payment Method</span><strong>${escapeHtml(receiptData.payment_method || '')}</strong></div>
                    <div class="receipt-info-row"><span>Order Type</span><strong>${escapeHtml(receiptData.order_type || 'Not Recorded')}</strong></div>
                    ${receiptData.order_type === 'Dine In' && receiptData.table_number ? `<div class="receipt-info-row"><span>Table</span><strong>${escapeHtml(receiptData.table_number)}</strong></div>` : ''}
                </div>
                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>${itemsMarkup}</tbody>
                </table>
                <div class="receipt-summary">
                    <div class="receipt-summary-row"><span>Total</span><strong>${formatCurrency(receiptData.total_amount || 0)}</strong></div>
                    <div class="receipt-summary-row"><span>Amount Paid</span><strong>${formatCurrency(receiptData.amount_paid || 0)}</strong></div>
                    <div class="receipt-summary-row"><span>Change</span><strong>${formatCurrency(receiptData.change_amount || 0)}</strong></div>
                </div>
            `;
        }

        function openReceiptModal(data) {
            receiptData = data;
            renderReceipt();
            receiptModal.classList.add('visible');
        }

        function closeReceiptModal() {
            receiptModal.classList.remove('visible');
            receiptData = null;
            receiptContent.innerHTML = '';
        }

        proceedPaymentButton.addEventListener('click', () => {
            if (orderItems.length === 0) {
                return;
            }
            if (!selectedOrderType) {
                orderTypeWarning.textContent = 'Please select an order type first.';
                return;
            }
            if (selectedOrderType === 'Dine In' && !tableNumberSelect.value) {
                orderTypeWarning.textContent = 'Please select a table number first.';
                tableNumberSelect.focus();
                return;
            }
            paymentOrderTypeSummary.textContent = 'Order Type: ' + selectedOrderType;
            paymentTableSummary.textContent = selectedOrderType === 'Dine In' ? 'Table: ' + tableNumberSelect.value : '';
            paymentModal.classList.add('visible');
            resetPaymentModal();
            showFeedback('', 'success');
            updatePaymentSummary();
        });

        cancelPaymentButton.addEventListener('click', () => {
            paymentModal.classList.remove('visible');
            paymentWarning.textContent = '';
            showFeedback('', 'success');
        });

        paymentOptionButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const method = button.dataset.method;
                if (method === 'QR Payment') {
                    amountPaidInput.value = currentOrderTotal.toFixed(2);
                } else {
                    amountPaidInput.value = '';
                }
                setPaymentMethod(method);
            });
        });

        orderTypeOptionButtons.forEach((button) => {
            button.addEventListener('click', () => setOrderType(button.dataset.orderType));
        });

        tableNumberSelect.addEventListener('change', () => {
            orderTypeWarning.textContent = '';
            updatePaymentSummary();
        });

        amountPaidInput.addEventListener('input', updatePaymentSummary);

        completePaymentButton.addEventListener('click', () => {
            if (!selectedOrderType) {
                orderTypeWarning.textContent = 'Please select an order type.';
                return;
            }
            if (selectedOrderType === 'Dine In' && !tableNumberSelect.value) {
                orderTypeWarning.textContent = 'Please select a table number first.';
                paymentModal.classList.remove('visible');
                tableNumberSelect.focus();
                return;
            }
            if (completePaymentButton.disabled || !selectedPaymentMethod) {
                return;
            }

            const payload = {
                csrfToken: posCsrfToken,
                paymentMethod: selectedPaymentMethod,
                orderType: selectedOrderType,
                tableNumber: selectedOrderType === 'Dine In' ? tableNumberSelect.value : '',
                amountPaid: parseFloat(amountPaidInput.value || '0'),
                items: orderItems.map((item) => ({ code: item.code, quantity: item.quantity }))
            };

            completePaymentButton.disabled = true;
            completePaymentButton.textContent = 'Processing...';

            fetch('pos.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(async (response) => {
                const data = await response.json();
                if (!data || !data.success) {
                    throw new Error(data && data.message ? data.message : 'Payment failed.');
                }

                orderItems.splice(0, orderItems.length);
                renderOrder();
                paymentModal.classList.remove('visible');
                resetPaymentModal();
                resetOrderType();
                openReceiptModal(data.receipt);
            })
            .catch((error) => {
                showFeedback(error.message || 'Payment failed.', 'error');
            })
            .finally(() => {
                completePaymentButton.disabled = false;
                completePaymentButton.textContent = 'Complete Payment';
            });
        });

        printReceiptButton.addEventListener('click', () => {
            if (!receiptData) {
                return;
            }

            document.body.classList.add('printing');
            window.print();
            setTimeout(() => {
                document.body.classList.remove('printing');
            }, 1000);
        });

        doneReceiptButton.addEventListener('click', () => {
            closeReceiptModal();
        });

        document.querySelectorAll('.add-to-order').forEach((button) => {
            button.addEventListener('click', () => {
                addToOrder({
                    code: button.dataset.itemCode,
                    name: button.dataset.itemName,
                    price: parseFloat(button.dataset.price)
                });
            });
        });

        orderItemsContainer.addEventListener('click', (event) => {
            const target = event.target;
            const button = target.closest('button');
            if (!button) {
                return;
            }

            const itemCode = button.dataset.itemCode;
            if (!itemCode) {
                return;
            }

            const existingItem = orderItems.find((entry) => entry.code === itemCode);
            if (!existingItem) {
                return;
            }

            if (button.dataset.action === 'increase') {
                existingItem.quantity += 1;
            } else if (button.dataset.action === 'decrease') {
                existingItem.quantity -= 1;
                if (existingItem.quantity <= 0) {
                    const index = orderItems.findIndex((entry) => entry.code === itemCode);
                    if (index >= 0) {
                        orderItems.splice(index, 1);
                    }
                }
            } else if (button.classList.contains('remove-item')) {
                const index = orderItems.findIndex((entry) => entry.code === itemCode);
                if (index >= 0) {
                    orderItems.splice(index, 1);
                }
            }

            renderOrder();
        });

        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000);
        renderOrder();
        updatePaymentSummary();
    </script>
</body>
</html>

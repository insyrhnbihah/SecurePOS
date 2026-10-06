<?php
/** Serialize the normalized filters actually used by the Reports page. */
function reportExportFilters(array $data): array
{
    switch ($data['activeReport']) {
        case 'inventory':
            return ['report' => 'inventory', 'search' => $data['inventorySearch'], 'category' => $data['inventoryCategory'], 'stock_status' => $data['inventoryStatusFilter']];
        case 'expiry':
            return ['report' => 'expiry', 'search' => $data['expirySearch'], 'category' => $data['expiryCategory'], 'expiry_status' => $data['expiryStatusFilter']];
        case 'attendance':
            return ['report' => 'attendance', 'start_date' => $data['attendanceStartSql'], 'end_date' => $data['attendanceEndSql'], 'employee_search' => $data['attendanceSearch'], 'role' => $data['attendanceRoleFilter'], 'attendance_status' => $data['attendanceStatusFilter']];
        default:
            return ['report' => 'sales', 'start_date' => $data['startDate']->format('Y-m-d'), 'end_date' => $data['endDate']->format('Y-m-d'), 'payment' => $data['paymentFilter']];
    }
}

function validateReportExportInput(array $input): bool
{
    $lengths = ['report'=>10, 'start_date'=>10, 'end_date'=>10, 'payment'=>10, 'search'=>500, 'category'=>100, 'stock_status'=>20, 'expiry_status'=>20, 'employee_search'=>500, 'role'=>20, 'attendance_status'=>20];
    foreach ($input as $key => $value) {
        if (!isset($lengths[$key]) || !is_string($value) || strlen($value) > $lengths[$key] || preg_match('/[\x00-\x1f\x7f]/', $value) || !mb_check_encoding($value, 'UTF-8')) return false;
    }
    $options = ['report'=>['sales','inventory','expiry','attendance'], 'payment'=>['all','cash','qr'], 'stock_status'=>['all','in_stock','low_stock','out_of_stock'], 'expiry_status'=>['all','expired','within_7','within_30','valid'], 'role'=>['all','cashier','inventory_staff'], 'attendance_status'=>['all','present']];
    foreach ($options as $key => $values) {
        if (isset($input[$key]) && !in_array(trim($input[$key]), $values, true)) return false;
    }
    foreach (['start_date', 'end_date'] as $key) {
        if (!empty($input[$key])) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $input[$key], new DateTimeZone('Asia/Kuala_Lumpur'));
            if (!$date || $date->format('Y-m-d') !== $input[$key] || $date->format('Y') < '1000') return false;
        }
    }
    return empty($input['start_date']) || empty($input['end_date']) || $input['start_date'] <= $input['end_date'];
}

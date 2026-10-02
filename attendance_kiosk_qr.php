<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/attendance_qr.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
$now = new DateTimeImmutable('now', attendanceTimezone());
startKioskStateSession();

try {
    requireKiosk($mysqli, $now, false);
    $qr = currentOrRotatedKioskQr($mysqli, $now);
    if (!$qr['available']) {
        echo json_encode(['available' => false, 'message' => 'Attendance QR is available from 8:00 AM until 9:00 PM Malaysia time.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $until = new DateTimeImmutable($qr['valid_until'], attendanceTimezone());
    echo json_encode([
        'available' => true,
        'url' => rtrim(SECUREPOS_BASE_URL, '/') . '/attendance_scan.php?token=' . rawurlencode($qr['raw_token']),
        'valid_until_display' => $until->format('h:i:s A'),
        'remaining_seconds' => max(0, $until->getTimestamp() - $now->getTimestamp()),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Attendance kiosk QR failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['available' => false, 'message' => 'Attendance QR is temporarily unavailable.']);
}

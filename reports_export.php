<?php
require_once __DIR__ . '/config/auth.php';
// Preserve the Reports module's server-side Manager and active-account checks.
requireManager();
require_once __DIR__ . '/config/report_export_filters.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
if (!validateReportExportInput($_GET)) {
    http_response_code(400);
    exit('Invalid report filters.');
}
require_once __DIR__ . '/config/database.php';
try {
    require_once __DIR__ . '/config/reports_data.php';
    // Reject unsupported categories instead of silently exporting different filters.
    if ($inventoryNotice !== '' || $expiryNotice !== '' || $attendanceNotice !== '' || ($activeReport === 'sales' && $filterNotice !== '')) {
        http_response_code(400);
        exit('Invalid report filters. Apply valid filters on Reports before exporting.');
    }
    require_once __DIR__ . '/config/reports_pdf.php';
    $pdf = buildSecureposReportPdf(get_defined_vars(), currentUserName());
    $content = $pdf->Output('S');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="SecurePOS-' . $activeReport . '-' . (new DateTimeImmutable('now', $timezone))->format('Ymd-His') . '.pdf"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($content));
    echo $content;
} catch (Throwable $exception) {
    error_log('SecurePOS report PDF export failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('PDF export failed. Please try again or contact the administrator.');
}

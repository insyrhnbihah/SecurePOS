<?php
require_once __DIR__ . '/config/auth.php';
// Audit Logs currently uses the Manager guard; retain its active-account and password checks.
requireManager();
require_once __DIR__ . '/config/audit_export_filters.php';
if ($_SERVER['REQUEST_METHOD']!=='GET') {
    http_response_code(405); header('Allow: GET'); exit('Method not allowed.');
}
if (!validateAuditExportInput($_GET)) {
    http_response_code(400); exit('Invalid audit log filters.');
}
require_once __DIR__ . '/config/database.php';
try {
    require_once __DIR__ . '/config/audit_logs_data.php';
    if ($filterNotice!=='') {
        http_response_code(400); exit('Invalid audit log filters. Apply valid filters before exporting.');
    }
    require_once __DIR__ . '/config/audit_logs_pdf.php';
    $pdf=buildSecureposAuditPdf(get_defined_vars(),currentUserName());
    $content=$pdf->Output('S');
    // Query/build first: the new export event is excluded from its own report snapshot.
    // Use concise operational text, never raw filters or sensitive request payloads.
    require_once __DIR__ . '/config/audit.php';
    if (!auditLog($mysqli,(int)$_SESSION['user_id'],currentUserName(),currentUserRole(),'Reports','Export Audit Log PDF','Success','Manager generated an Audit Log PDF containing '.count($auditRows).' matching records.')) {
        throw new RuntimeException('Could not record audit log export event.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="SecurePOS-audit-logs-'.(new DateTimeImmutable('now',$timezone))->format('Ymd-His').'.pdf"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.strlen($content));
    echo $content;
} catch (Throwable $exception) {
    error_log('SecurePOS audit PDF export failed: '.$exception->getMessage());
    http_response_code(500); exit('Audit PDF export failed. Please try again or contact the administrator.');
}

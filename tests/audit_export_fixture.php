<?php
// CLI-only authenticated, read-only fixture for report export integration tests.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('SECUREPOS_ENV=local');
$_SERVER=['REQUEST_METHOD'=>'GET','HTTP_HOST'=>'localhost','SCRIPT_NAME'=>'/reports_export.php','SERVER_ADDR'=>'127.0.0.1','REMOTE_ADDR'=>'127.0.0.1','SERVER_PORT'=>'80'];
$_GET=json_decode($argv[2] ?? '{}',true) ?: [];
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/auth.php';
$sessionPath=dirname(__DIR__) . '/tmp/pdfs/sessions';
if (!is_dir($sessionPath)) mkdir($sessionPath,0700,true);
session_save_path($sessionPath);
startSecureSession();
$role=$argv[3] ?? 'Manager';
$stmt=$mysqli->prepare("SELECT id,full_name,role FROM users WHERE role=? AND account_status='Active' AND force_password_change=0 LIMIT 1");
$stmt->bind_param('s',$role); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc();
if (!$user) { fwrite(STDERR,'No eligible role fixture.'); exit(3); }
$_SESSION=['authenticated'=>true,'user_id'=>(int)$user['id'],'full_name'=>$user['full_name'],'role'=>$user['role']];
if (($argv[1] ?? '') === 'session') { echo session_id(); session_write_close(); exit; }
requireManager();
if (($argv[1] ?? '') === 'snapshot') {
    echo json_encode($mysqli->query('SELECT * FROM audit_logs ORDER BY id')->fetch_all(MYSQLI_ASSOC),JSON_THROW_ON_ERROR);
} else {
    require_once dirname(__DIR__) . '/config/audit_export_filters.php';
    if (!validateAuditExportInput($_GET)) exit(4);
    require_once dirname(__DIR__) . '/config/audit_logs_data.php';
    echo json_encode(['rows'=>$auditRows,'filters'=>auditExportFilters(get_defined_vars())],JSON_THROW_ON_ERROR);
}
$_SESSION=[]; session_destroy();

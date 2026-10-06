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
require_once dirname(__DIR__) . '/config/report_export_filters.php';
if (!validateReportExportInput($_GET)) exit(4);
require_once dirname(__DIR__) . '/config/reports_data.php';
$keys=['activeReport','summary','transactions','topItems','inventorySummary','inventoryRows','expirySummary','expiryRows','attendanceSummary','attendanceRows','monthlyAttendanceRows','absentEmployees','attendanceSingleDate'];
$out=[]; foreach($keys as $key) $out[$key]=$$key;
$out['filters']=reportExportFilters(get_defined_vars());
echo json_encode($out,JSON_THROW_ON_ERROR);
$_SESSION=[]; session_destroy();

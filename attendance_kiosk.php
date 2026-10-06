<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/attendance_qr.php';

$now = new DateTimeImmutable('now', attendanceTimezone());
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:");

if (!attendanceKioskTransportAllowed()) {
    http_response_code(400);
    exit('Attendance display requires HTTPS.');
}

$pairingError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pair') {
    startSecureSession();
    $attempts = $_SESSION['kiosk_pairing_attempts'] ?? [];
    $cutoff = time() - 600;
    $attempts = array_values(array_filter($attempts, static fn($value) => is_int($value) && $value >= $cutoff));
    if (count($attempts) >= 5) {
        http_response_code(429);
        $pairingError = 'Too many pairing attempts. Try again later.';
    } else {
        $attempts[] = time();
        $_SESSION['kiosk_pairing_attempts'] = $attempts;
        $code = strtolower(trim((string)($_POST['pairing_code'] ?? '')));
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $code)) {
            attendanceAudit($mysqli, null, null, 'KIOSK_PAIRING', 'Rejected', 'Invalid pairing code format');
            $pairingError = 'Invalid or expired pairing code.';
        } else {
            $hash = hash('sha256', $code);
            $nowSql = $now->format('Y-m-d H:i:s');
            $mysqli->begin_transaction();
            try {
                $stmt = $mysqli->prepare('SELECT id, created_by_user_id, expires_at, used_at FROM attendance_kiosk_pairings WHERE code_hash = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('s', $hash);
                $stmt->execute();
                $pairing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$pairing || $pairing['used_at'] !== null || $pairing['expires_at'] <= $nowSql) {
                    throw new DomainException('Invalid, expired, or already used pairing code.');
                }
                $pairingId = (int)$pairing['id'];
                $used = $mysqli->prepare('UPDATE attendance_kiosk_pairings SET used_at = ? WHERE id = ? AND used_at IS NULL');
                $used->bind_param('si', $nowSql, $pairingId);
                $used->execute();
                if ($used->affected_rows !== 1) {
                    throw new DomainException('Pairing code was already used.');
                }
                $used->close();
                issueKioskCredential($mysqli, (int)$pairing['created_by_user_id'], 'On-site attendance display', $now);
                attendanceAudit($mysqli, null, null, 'KIOSK_PAIRING', 'Success', 'Kiosk paired');
                $mysqli->commit();
                unset($_SESSION['kiosk_pairing_attempts']);
                session_write_close();
                header('Location: attendance_kiosk.php');
                exit;
            } catch (Throwable $exception) {
                $mysqli->rollback();
                attendanceAudit($mysqli, null, null, 'KIOSK_PAIRING', 'Rejected', 'Invalid, expired, or reused pairing code');
                $pairingError = 'Invalid or expired pairing code.';
            }
        }
    }
} elseif (isset($_GET['pair'])) {
    startSecureSession();
} else {
    startKioskStateSession();
    $kiosk = requireKiosk($mysqli, $now);
}
?>
<!DOCTYPE html>
<html lang="en"><head>
    <script src="assets/js/theme.js?v=20261007"></script><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SecurePOS Attendance Display</title><link rel="stylesheet" href="assets/css/style.css">
<style>
body{min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 50% 8%,rgba(85,214,209,.12),transparent 32%),var(--theme-122, #07111f);color:var(--theme-13, #fff)}
.kiosk{width:min(92vw,620px);text-align:center;padding:36px;background:var(--theme-90, #101d2d);border:1px solid var(--theme-94, rgba(255,255,255,.08));border-radius:18px;box-shadow:0 28px 70px var(--theme-123, rgba(0,0,0,.38))}
.qr{width:260px;height:260px;margin:24px auto;padding:12px;background:#fff}.qr img,.qr canvas{max-width:100%;height:auto}.muted{color:var(--theme-124, #9aabba)}
.pairing-view{max-width:460px;margin:0 auto}.pairing-icon{display:grid;width:66px;height:66px;margin:0 auto 20px;place-items:center;border:1px solid rgba(85,214,209,.3);border-radius:20px;background:rgba(85,214,209,.1);color:var(--theme-125, #7ce5df);box-shadow:0 0 28px rgba(85,214,209,.08)}
.pairing-icon svg{width:34px;height:34px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.pairing-view h1{margin:0 0 10px;font-size:1.55rem;letter-spacing:-.015em}.pairing-intro{max-width:410px;margin:0 auto;color:var(--theme-124, #9aabba);font-size:.92rem;line-height:1.55}
.pair{margin-top:24px}.pair-code-label{display:block;margin-bottom:9px;color:var(--theme-126, #dce6f7);font-size:.8rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.pair-code-input{display:block;width:min(100%,390px);min-height:52px;margin:0 auto;padding:11px 15px;border:1px solid var(--theme-127, rgba(255,255,255,.13));border-radius:12px;outline:none;background:var(--theme-128, #081321);color:var(--theme-6, #edf2ff);text-align:center;font-family:Consolas,"SFMono-Regular",Menlo,Monaco,monospace;font-size:1.08rem;font-weight:700;letter-spacing:.105em;transition:border-color .18s,box-shadow .18s,background .18s}
.pair-code-input:hover{border-color:rgba(85,214,209,.35)}.pair-code-input:focus{border-color:#55d6d1;background:var(--theme-129, #091827);box-shadow:0 0 0 4px rgba(85,214,209,.12)}
.pair-submit{position:relative;width:min(100%,390px);min-height:50px;margin-top:14px;border:1px solid #55d6d1;border-radius:12px;background:linear-gradient(135deg,#55d6d1,#43b6ff);color:#071718;font-weight:800;cursor:pointer;transition:transform .18s,filter .18s,opacity .18s}
.pair-submit:hover:not(:disabled){filter:brightness(1.08);transform:translateY(-1px)}.pair-submit:focus-visible{outline:3px solid rgba(85,214,209,.28);outline-offset:3px}.pair-submit:disabled{cursor:wait;opacity:.68}
.pair-submit.is-loading{color:transparent}.pair-submit.is-loading::after{content:"";position:absolute;left:50%;top:50%;width:19px;height:19px;margin:-9.5px;border:2px solid var(--theme-130, rgba(7,23,24,.25));border-top-color:var(--theme-131, #071718);border-radius:50%;animation:pair-spin .7s linear infinite}
.pair-helper{margin:13px 0 0;color:var(--theme-132, #74869e);font-size:.76rem;line-height:1.45}.error{max-width:390px;margin:18px auto 0;padding:10px 12px;border:1px solid rgba(252,92,125,.25);border-radius:10px;background:rgba(252,92,125,.1);color:var(--theme-114, #ffb3c1);font-size:.84rem}
@keyframes pair-spin{to{transform:rotate(360deg)}}
@media(max-width:520px){body{padding:14px}.kiosk{width:100%;padding:28px 20px;border-radius:16px}.pairing-view h1{font-size:1.35rem}.pair-code-input{font-size:.95rem;letter-spacing:.075em}}
@media(prefers-reduced-motion:reduce){.pair-code-input,.pair-submit{transition:none}.pair-submit:hover:not(:disabled){transform:none}.pair-submit.is-loading::after{animation-duration:1.4s}}
</style>    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head><body><main class="kiosk">
<?php if (isset($_GET['pair']) || $pairingError !== '') : ?>
<section class="pairing-view" aria-labelledby="pairing-title">
<div class="pairing-icon" aria-hidden="true"><svg viewBox="0 0 32 32"><rect x="4" y="5" width="24" height="17" rx="3"/><path d="M11 27h10M16 22v5M16 9l6 2.5v4.2c0 3.2-2.4 5.3-6 6.3-3.6-1-6-3.1-6-6.3v-4.2L16 9Z"/></svg></div>
<h1 id="pairing-title">Activate Attendance Display</h1><p class="pairing-intro">Enter the one-time pairing code generated by a Manager to authorize this display.</p>
<?php if ($pairingError !== '') : ?><p class="error" role="alert"><?php echo htmlspecialchars($pairingError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<form class="pair" id="pair-display-form" method="post"><input type="hidden" name="action" value="pair"><label class="pair-code-label" for="pairing-code">Pairing code</label><input class="pair-code-input" id="pairing-code" name="pairing_code" type="password" inputmode="text" autocomplete="one-time-code" maxlength="32" size="32" required autofocus aria-describedby="pairing-help"><button class="pair-submit" id="pair-display-submit" type="submit">Activate Display</button><p class="pair-helper" id="pairing-help">Pairing codes expire after 10 minutes and can only be used once.</p></form>
</section>
<script>(function(){var form=document.getElementById('pair-display-form'),button=document.getElementById('pair-display-submit');if(!form||!button)return;form.addEventListener('submit',function(){if(!form.checkValidity())return;button.disabled=true;button.classList.add('is-loading');button.setAttribute('aria-label','Activating display');});}());</script>
<?php else : ?>
<h1>Attendance QR</h1><p id="status" class="muted">Loading secure rotating QR…</p><div id="qr" class="qr"></div><p id="countdown"></p>
<script src="assets/js/qrcode.min.js"></script><script>
(function(){var qr=document.getElementById('qr'),status=document.getElementById('status'),countdown=document.getElementById('countdown'),last='';
function poll(){fetch('attendance_kiosk_qr.php',{credentials:'same-origin',cache:'no-store'}).then(function(r){if(r.status===401){location.href='attendance_kiosk.php?pair=1';return null}return r.json()}).then(function(data){if(!data)return;if(!data.available){qr.innerHTML='';status.textContent=data.message;countdown.textContent='';return}status.textContent='Valid until '+data.valid_until_display;if(data.url!==last){last=data.url;qr.innerHTML='';new QRCode(qr,{text:data.url,width:236,height:236,colorDark:'#07111f',colorLight:'#fff',correctLevel:QRCode.CorrectLevel.H})}countdown.textContent=data.remaining_seconds+' seconds remaining'}).catch(function(){status.textContent='Display temporarily unavailable.'})}
poll();setInterval(poll,5000)}());</script>
<?php endif; ?>
</main></body></html>

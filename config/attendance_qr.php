<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

const SECUREPOS_KIOSK_COOKIE = 'SECUREPOS_KIOSK';

function attendanceTimezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Kuala_Lumpur');
}

function attendanceWindow(DateTimeImmutable $now): array
{
    $open = new DateTimeImmutable($now->format('Y-m-d') . ' ' . SECUREPOS_ATTENDANCE_OPEN_TIME, $now->getTimezone());
    $closeTime = SECUREPOS_ATTENDANCE_CLOSE_TIME;
    if (defined('SECUREPOS_DEV_ATTENDANCE_WINDOW_ENABLED')
        && SECUREPOS_DEV_ATTENDANCE_WINDOW_ENABLED
        && defined('SECUREPOS_DEV_ATTENDANCE_CLOSE_TIME')) {
        $closeTime = SECUREPOS_DEV_ATTENDANCE_CLOSE_TIME;
    }
    $close = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $closeTime, $now->getTimezone());
    return ['open' => $open, 'close' => $close, 'is_open' => $now >= $open && $now < $close];
}

function attendanceAudit(mysqli $mysqli, ?int $employeeId, ?int $qrTokenId, string $type, string $result, string $reason): void
{
    $stmt = $mysqli->prepare('INSERT INTO attendance_attempt_logs (employee_id, qr_token_id, attempt_type, result, reason) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('iisss', $employeeId, $qrTokenId, $type, $result, $reason);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
}

function requestIsHttps(): bool
{
    return secureposRequestIsHttps();
}

function startKioskStateSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('SECUREPOS_KIOSK_STATE');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function issueKioskCredential(mysqli $mysqli, int $managerUserId, string $label, DateTimeImmutable $now): void
{
    $credential = bin2hex(random_bytes(32));
    $hash = hash('sha256', $credential);
    $expires = $now->modify('+' . SECUREPOS_KIOSK_SESSION_LIFETIME_SECONDS . ' seconds');
    $expiresSql = $expires->format('Y-m-d H:i:s');
    $stmt = $mysqli->prepare('INSERT INTO attendance_kiosk_sessions (credential_hash, label, expires_at, created_by_user_id) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException($mysqli->error);
    }
    $stmt->bind_param('sssi', $hash, $label, $expiresSql, $managerUserId);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    setcookie(SECUREPOS_KIOSK_COOKIE, $credential, [
        'expires' => $expires->getTimestamp(),
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function requireKiosk(mysqli $mysqli, DateTimeImmutable $now, bool $redirectToPairing = true): array
{
    if (!requestIsHttps()) {
        http_response_code(400);
        exit('Attendance display requires HTTPS.');
    }
    $credential = (string)($_COOKIE[SECUREPOS_KIOSK_COOKIE] ?? '');
    if (!preg_match('/\A[a-f0-9]{64}\z/D', $credential)) {
        if (!$redirectToPairing) {
            http_response_code(401);
            exit;
        }
        header('Location: attendance_kiosk.php?pair=1');
        exit;
    }
    $hash = hash('sha256', $credential);
    $nowSql = $now->format('Y-m-d H:i:s');
    $stmt = $mysqli->prepare('SELECT id, label FROM attendance_kiosk_sessions WHERE credential_hash = ? AND revoked_at IS NULL AND expires_at > ? LIMIT 1');
    $stmt->bind_param('ss', $hash, $nowSql);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        setcookie(SECUREPOS_KIOSK_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        if (!$redirectToPairing) {
            http_response_code(401);
            exit;
        }
        header('Location: attendance_kiosk.php?pair=1');
        exit;
    }
    $id = (int)$session['id'];
    $update = $mysqli->prepare('UPDATE attendance_kiosk_sessions SET last_used_at = ? WHERE id = ?');
    $update->bind_param('si', $nowSql, $id);
    $update->execute();
    $update->close();
    return ['id' => $id, 'label' => $session['label']];
}

function rotateAttendanceQr(mysqli $mysqli, DateTimeImmutable $now, string $reason): array
{
    $window = attendanceWindow($now);
    if (!$window['is_open']) {
        throw new DomainException('Attendance QR is unavailable outside operating hours.');
    }
    $lock = $mysqli->query("SELECT GET_LOCK('securepos_attendance_qr_rotation', 5) AS acquired")->fetch_assoc();
    if ((int)($lock['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Could not acquire QR rotation lock.');
    }
    try {
        $raw = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $until = $now->modify('+' . SECUREPOS_ATTENDANCE_QR_LIFETIME_SECONDS . ' seconds');
        if ($until > $window['close']) {
            $until = $window['close'];
        }
        $fromSql = $now->format('Y-m-d H:i:s');
        $untilSql = $until->format('Y-m-d H:i:s');
        $mysqli->begin_transaction();
        $mysqli->query('UPDATE attendance_qr_tokens SET is_active = 0 WHERE is_active = 1');
        $stmt = $mysqli->prepare('INSERT INTO attendance_qr_tokens (token_hash, valid_from, valid_until, is_active) VALUES (?, ?, ?, 1)');
        $stmt->bind_param('sss', $hash, $fromSql, $untilSql);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        attendanceAudit($mysqli, null, $id, 'QR_GENERATION', 'Success', $reason);
        $mysqli->commit();
        return ['id' => $id, 'raw_token' => $raw, 'valid_until' => $untilSql];
    } catch (Throwable $exception) {
        @$mysqli->rollback();
        throw $exception;
    } finally {
        $mysqli->query("SELECT RELEASE_LOCK('securepos_attendance_qr_rotation')");
    }
}

function currentOrRotatedKioskQr(mysqli $mysqli, DateTimeImmutable $now): array
{
    $window = attendanceWindow($now);
    if (!$window['is_open']) {
        unset($_SESSION['attendance_kiosk_qr']);
        return ['available' => false, 'opens_at' => $window['open']->format('h:i A')];
    }
    $qr = $_SESSION['attendance_kiosk_qr'] ?? null;
    if (is_array($qr) && isset($qr['id'], $qr['raw_token'], $qr['valid_until'])) {
        $hash = hash('sha256', (string)$qr['raw_token']);
        $nowSql = $now->format('Y-m-d H:i:s');
        $stmt = $mysqli->prepare('SELECT id FROM attendance_qr_tokens WHERE id = ? AND token_hash = ? AND is_active = 1 AND valid_from <= ? AND valid_until > ? LIMIT 1');
        $id = (int)$qr['id'];
        $stmt->bind_param('isss', $id, $hash, $nowSql, $nowSql);
        $stmt->execute();
        $valid = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($valid) {
            return ['available' => true] + $qr;
        }
    }
    $qr = rotateAttendanceQr($mysqli, $now, 'Automatic rotation');
    $_SESSION['attendance_kiosk_qr'] = $qr;
    return ['available' => true] + $qr;
}

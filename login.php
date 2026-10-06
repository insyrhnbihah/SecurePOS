<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/audit.php';

startSecureSession();

function consumeAttendanceLoginRedirect()
{
    $destination = (string)($_SESSION['post_login_redirect'] ?? '');
    if ($destination === '') {
        return '';
    }

    unset($_SESSION['post_login_redirect']);
    return preg_match('/\Aattendance_scan\.php\?token=[a-f0-9]{64}\z/D', $destination) ? $destination : '';
}

if (isAuthenticated()) {
    if (!empty($_SESSION['force_password_change'])) {
        header('Location: change_password.php');
        exit;
    }
    $attendanceRedirect = consumeAttendanceLoginRedirect();
    if ($attendanceRedirect !== '') {
        header('Location: ' . $attendanceRedirect);
        exit;
    }

    $currentRole = strtolower(trim(currentUserRole()));
    if ($currentRole === 'manager') {
        header('Location: dashboard.php');
        exit;
    }
    if ($currentRole === 'cashier') {
        header('Location: pos.php');
        exit;
    }
    if ($currentRole === 'inventory staff') {
        header('Location: inventory.php');
        exit;
    }
}

$error = '';
$notice = '';
if (empty($_SESSION['face_login_csrf']) || !is_string($_SESSION['face_login_csrf'])) {
    $_SESSION['face_login_csrf'] = bin2hex(random_bytes(32));
}
$faceLoginCsrf = $_SESSION['face_login_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';

    if ($action === 'login') {
        $submittedEmail = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $stmt = null;
        $user = null;
        $passwordMatches = false;
        $passwordLoginLocked = false;
        $lockTriggered = false;

        try {
            $mysqli->begin_transaction();
            $stmt = $mysqli->prepare(
                'SELECT id, full_name, password_hash, role, account_status, failed_login_attempts, locked_until, force_password_change
                 FROM users WHERE email = ? LIMIT 1 FOR UPDATE'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare password login lookup.');
            }
            $stmt->bind_param('s', $submittedEmail);
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to execute password login lookup.');
            }
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = null;

            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kuala_Lumpur'));
            $lockedUntil = $user && $user['locked_until'] !== null
                ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $user['locked_until'], new DateTimeZone('Asia/Kuala_Lumpur'))
                : null;
            $passwordLoginLocked = $user
                && $user['account_status'] === 'Active'
                && $lockedUntil instanceof DateTimeImmutable
                && $lockedUntil > $now;

            if (!$passwordLoginLocked) {
                $passwordMatches = $user && password_verify($password, $user['password_hash']);

                if ($user && $user['account_status'] === 'Active' && $passwordMatches) {
                    $resetStmt = $mysqli->prepare(
                        'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?'
                    );
                    if (!$resetStmt) {
                        throw new RuntimeException('Unable to prepare password login reset.');
                    }
                    $userId = (int)$user['id'];
                    $resetStmt->bind_param('i', $userId);
                    if (!$resetStmt->execute()) {
                        $resetStmt->close();
                        throw new RuntimeException('Unable to reset password login state.');
                    }
                    $resetStmt->close();
                } elseif ($user && $user['account_status'] === 'Active' && !$passwordMatches) {
                    $attempts = $lockedUntil instanceof DateTimeImmutable && $lockedUntil <= $now
                        ? 1
                        : min(5, (int)$user['failed_login_attempts'] + 1);
                    $newLockedUntil = null;
                    if ($attempts >= 5) {
                        $lockTriggered = true;
                        $newLockedUntil = $now->modify('+10 minutes')->format('Y-m-d H:i:s');
                    }

                    $failureStmt = $mysqli->prepare(
                        'UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?'
                    );
                    if (!$failureStmt) {
                        throw new RuntimeException('Unable to prepare password login failure update.');
                    }
                    $userId = (int)$user['id'];
                    $failureStmt->bind_param('isi', $attempts, $newLockedUntil, $userId);
                    if (!$failureStmt->execute()) {
                        $failureStmt->close();
                        throw new RuntimeException('Unable to update password login failure state.');
                    }
                    $failureStmt->close();
                }
            }

            $mysqli->commit();

            if ($passwordLoginLocked) {
                auditLog(
                    $mysqli,
                    null,
                    null,
                    null,
                    'Authentication',
                    'Login',
                    'Rejected',
                    'Password login rejected during temporary lockout.'
                );
                $error = 'Too many failed login attempts. Please try again later.';
            } elseif ($passwordMatches && $user['account_status'] === 'Active') {
                // Consume the validated server-side return path before rotating the
                // session ID, then give it priority over normal role routing.
                $attendanceRedirect = consumeAttendanceLoginRedirect();
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['authenticated'] = true;
                $_SESSION['force_password_change'] = (int)$user['force_password_change'] === 1;

                auditLog(
                    $mysqli,
                    (int)$user['id'],
                    $user['full_name'],
                    $user['role'],
                    'Authentication',
                    'Login',
                    'Success',
                    'User logged in successfully.'
                );

                if (!empty($_SESSION['force_password_change'])) {
                    header('Location: change_password.php');
                    exit;
                }

                if ($attendanceRedirect !== '') {
                    header('Location: ' . $attendanceRedirect);
                    exit;
                }

                $userRole = strtolower(trim((string)$user['role']));
                if ($userRole === 'manager') {
                    header('Location: dashboard.php');
                    exit;
                }
                if ($userRole === 'cashier') {
                    header('Location: pos.php');
                    exit;
                }
                if ($userRole === 'inventory staff') {
                    header('Location: inventory.php');
                    exit;
                }
                $notice = 'No dashboard is available for this role yet.';
            } elseif ($passwordMatches && $user['account_status'] === 'Inactive') {
                auditLog(
                    $mysqli,
                    (int)$user['id'],
                    $user['full_name'],
                    $user['role'],
                    'Authentication',
                    'Login',
                    'Rejected',
                    'Inactive account login rejected.'
                );
                $error = 'Invalid email or password.';
            } elseif ($lockTriggered) {
                auditLog(
                    $mysqli,
                    (int)$user['id'],
                    $user['full_name'],
                    $user['role'],
                    'Authentication',
                    'Login',
                    'Rejected',
                    'Password login temporarily locked after repeated failed attempts.'
                );
                $error = 'Too many failed login attempts. Please try again later.';
            } else {
                auditLog(
                    $mysqli,
                    null,
                    null,
                    null,
                    'Authentication',
                    'Login',
                    'Failed',
                    'Login attempt failed.'
                );
                $error = 'Invalid email or password.';
            }
        } catch (Throwable $exception) {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            try {
                $mysqli->rollback();
            } catch (Throwable $rollbackException) {
                // The normal generic response below intentionally hides database details.
            }
            error_log('Password login processing failed: ' . $exception->getMessage());
            auditLog(
                $mysqli,
                null,
                null,
                null,
                'Authentication',
                'Login',
                'Failed',
                'Login attempt failed.'
            );
            $error = 'Invalid email or password.';
        }
    }
    unset($password, $submittedEmail);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecurePOS Login | Restoran Kencana Sari</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css?v=20260902-left-position-final">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head>
<body class="login-page login-page-redesign">
    <main class="login-shell">
        <section class="login-brand-panel" aria-labelledby="login-title">
            <div class="login-brand">
                <div class="brand-icon" aria-hidden="true"><img src="assets/images/securepos-shield-icon.png?v=20260830" alt=""></div>
                <div><h1 id="login-title">SecurePOS</h1><p>Restoran Kencana Sari</p></div>
            </div>
            <p class="login-brand-subtitle">Retail<br>Management System</p>
            <div class="login-cyber-decor" aria-hidden="true">
                <svg class="cyber-arcs" viewBox="0 0 520 330" preserveAspectRatio="none">
                    <path d="M-65 330C30 145 208 64 520 72"/><path d="M-82 348C55 194 235 128 530 145"/><path d="M-95 365C80 250 277 214 535 228"/>
                    <circle cx="87" cy="187" r="4"/><circle cx="224" cy="100" r="3.5"/><circle cx="374" cy="72" r="4"/><circle cx="153" cy="248" r="3.5"/><circle cx="344" cy="151" r="3"/><circle cx="282" cy="229" r="3.5"/>
                </svg>
                <span class="cyber-dot-grid"></span>
            </div>
        </section>

        <section class="login-card" aria-labelledby="login-welcome-title">
            <div class="login-heading"><h2 id="login-welcome-title">Welcome back</h2><p>Sign in to continue to SecurePOS.</p></div>

            <?php if ($error !== '') : ?><div class="login-alert error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <?php if ($notice !== '') : ?><div class="login-alert notice" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <form method="post" class="login-form" autocomplete="off">
                <input type="hidden" name="action" value="login">
                <label for="email">Email</label>
                <div class="login-input-wrap">
                    <svg class="login-field-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>
                    <input id="email" name="email" type="email" autocomplete="off" required autofocus>
                </div>
                <div class="login-password-header"><label for="password">Password</label><a href="forgot_password.php">Forgot Password?</a></div>
                <div class="login-input-wrap">
                    <svg class="login-field-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                    <input id="password" name="password" type="password" autocomplete="off" required>
                    <button class="password-visibility-toggle" type="button" id="toggle-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="2.6"/></svg></button>
                </div>
                <button type="submit" class="login-button primary">Sign In</button>
            </form>
            <div class="login-divider" aria-hidden="true"><span>or</span></div>
            <button type="button" class="login-button secondary" id="open-face-login"><svg class="face-button-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/><circle cx="9" cy="10" r=".8"/><circle cx="15" cy="10" r=".8"/><path d="M8.5 15c1.8 1.5 5.2 1.5 7 0"/></svg><span>Login with Face Verification</span></button>
            <p class="login-security-note">Secure access <span aria-hidden="true">&bull;</span> Role-based authentication</p>
        </section>
    </main>
    <div class="face-login-backdrop" id="face-login-modal" role="dialog" aria-modal="true" aria-labelledby="face-login-title">
        <section class="face-login-modal">
            <div class="face-login-header"><div><h3 id="face-login-title">Face Verification</h3><span class="face-login-kicker">Secure biometric sign in</span></div><button type="button" id="close-face-login" aria-label="Close Face Verification">&times;</button></div>
            <div class="face-login-video-wrap" id="face-login-frame">
                <video id="face-login-video" autoplay muted playsinline></video>
                <canvas id="face-landmarks" aria-hidden="true"></canvas>
                <div class="face-focus-mask" aria-hidden="true"></div>
                <div class="face-scanner" aria-hidden="true"><span></span><span></span><span></span><span></span><div class="face-scan-line"></div><div class="face-result-icon"><svg viewBox="0 0 52 52"><path d="M14 27.5 22.5 36 39 18.5"/></svg></div></div>
            </div>
            <div class="face-login-status" id="face-login-message" role="status" aria-live="polite">
                <strong id="face-login-status-title">Preparing camera...</strong>
                <span id="face-login-status-detail">Please wait a moment</span>
            </div>
            <div class="face-login-actions"><button type="button" class="face-login-cancel" id="cancel-face-login">Cancel</button></div>
        </section>
    </div>
    <script src="assets/vendor/face-api/face-api.min.js"></script>
    <script>window.securePosFaceLogin = <?php echo json_encode(['csrfToken' => $faceLoginCsrf, 'endpoint' => 'face_login.php', 'modelPath' => 'assets/vendor/face-api/models'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="assets/js/face_login.js?v=20260828-face-responsive"></script>
    <script src="assets/js/login_ui.js?v=20260902-password-toggle"></script>
</body>
</html>

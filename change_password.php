<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';

startSecureSession();
if (!isAuthenticated()) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['change_password_csrf']) || !is_string($_SESSION['change_password_csrf'])) {
    $_SESSION['change_password_csrf'] = bin2hex(random_bytes(32));
}

function normalRoleDestination(string $role): string
{
    $normalizedRole = strtolower(trim($role));
    if ($normalizedRole === 'manager') {
        return 'dashboard.php';
    }
    if ($normalizedRole === 'cashier') {
        return 'pos.php';
    }
    if ($normalizedRole === 'inventory staff') {
        return 'inventory.php';
    }
    return 'logout.php';
}

function passwordChangeDestination(string $role): string
{
    $attendanceDestination = (string)($_SESSION['post_login_redirect'] ?? '');
    unset($_SESSION['post_login_redirect']);
    if (preg_match('/\Aattendance_scan\.php\?token=[a-f0-9]{64}\z/D', $attendanceDestination)) {
        return $attendanceDestination;
    }
    return normalRoleDestination($role);
}

$error = '';
$forcedChange = !empty($_SESSION['force_password_change']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if (!hash_equals($_SESSION['change_password_csrf'], $submittedCsrf)) {
        $error = 'The form session has expired. Please try again.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must contain at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } elseif (hash_equals($currentPassword, $newPassword)) {
        $error = 'New password must be different from the current password.';
    }

    if ($error === '') {
        $stmt = null;
        $mysqli->begin_transaction();
        try {
            $userId = (int)$_SESSION['user_id'];
            $stmt = $mysqli->prepare('SELECT password_hash, account_status, force_password_change FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) {
                throw new RuntimeException('Unable to load password state.');
            }
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $stmt = null;

            if (!$user || $user['account_status'] !== 'Active' || !password_verify($currentPassword, $user['password_hash'])) {
                throw new DomainException('Current password is incorrect.');
            }

            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($newPasswordHash === false) {
                throw new RuntimeException('Unable to secure the new password.');
            }
            $update = $mysqli->prepare('UPDATE users SET password_hash = ?, force_password_change = 0, failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
            if (!$update) {
                throw new RuntimeException('Unable to update password.');
            }
            $update->bind_param('si', $newPasswordHash, $userId);
            if (!$update->execute()) {
                $update->close();
                throw new RuntimeException('Unable to update password.');
            }
            $update->close();
            $mysqli->commit();

            $_SESSION['force_password_change'] = false;
            unset($_SESSION['change_password_csrf']);
            session_regenerate_id(true);
            auditLog($mysqli, $userId, currentUserName(), currentUserRole(), 'Authentication', 'Change Password', 'Success', 'User changed their account password.');
            $destination = passwordChangeDestination(currentUserRole());
            unset($currentPassword, $newPassword, $confirmPassword, $newPasswordHash);
            header('Location: ' . $destination);
            exit;
        } catch (DomainException $exception) {
            $mysqli->rollback();
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($stmt instanceof mysqli_stmt) {
                $stmt->close();
            }
            $mysqli->rollback();
            error_log('Change password failed: ' . $exception->getMessage());
            $error = 'SecurePOS could not change the password. Please try again.';
        }
    }
    unset($currentPassword, $newPassword, $confirmPassword, $newPasswordHash);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | SecurePOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css?v=20260901-password-recovery">
    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-brand-panel" aria-labelledby="password-brand-title">
            <div class="login-brand"><div class="brand-icon" aria-hidden="true"><img src="assets/images/securepos-shield-icon.png?v=20260830" alt=""></div><div><h1 id="password-brand-title">SecurePOS</h1><p>Restoran Kencana Sari</p></div></div>
            <p class="login-brand-subtitle">Secure Retail Management System</p>
        </section>
        <section class="login-card" aria-labelledby="change-password-title">
            <div class="login-heading"><h2 id="change-password-title">Change Password</h2><p><?php echo $forcedChange ? 'You must replace the temporary password before continuing.' : 'Update the password for your SecurePOS account.'; ?></p></div>
            <?php if ($error !== '') : ?><div class="login-alert error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
            <form method="post" class="login-form" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['change_password_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <label for="current-password">Current <?php echo $forcedChange ? 'Temporary ' : ''; ?>Password</label>
                <input id="current-password" name="current_password" type="password" autocomplete="current-password" required autofocus>
                <label for="new-password">New Password</label>
                <input id="new-password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
                <label for="confirm-password">Confirm New Password</label>
                <input id="confirm-password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                <p class="password-form-note">Use at least 8 characters. The new password must differ from the current password.</p>
                <button type="submit" class="login-button primary">Change Password</button>
            </form>
            <?php if (!$forcedChange) : ?><a class="login-help-link" href="<?php echo htmlspecialchars(normalRoleDestination(currentUserRole()), ENT_QUOTES, 'UTF-8'); ?>">Cancel</a><?php endif; ?>
            <?php if ($forcedChange) : ?><a class="login-help-link" href="logout.php">Logout</a><?php endif; ?>
        </section>
    </main>
</body>
</html>

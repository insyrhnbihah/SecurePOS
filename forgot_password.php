<?php require_once __DIR__ . '/config/bootstrap.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | SecurePOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/login.css?v=20260901-password-recovery">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-brand-panel" aria-labelledby="forgot-brand-title">
            <div class="login-brand"><div class="brand-icon" aria-hidden="true"><img src="assets/images/securepos-shield-icon.png?v=20260830" alt=""></div><div><h1 id="forgot-brand-title">SecurePOS</h1><p>Restoran Kencana Sari</p></div></div>
            <p class="login-brand-subtitle">Secure Retail Management System</p>
        </section>
        <section class="login-card" aria-labelledby="forgot-title">
            <div class="login-heading"><h2 id="forgot-title">Forgot your password?</h2></div>
            <div class="login-info-copy">
                <p>If you have enrolled Face Verification, return to the login page and select &ldquo;Login with Face Verification&rdquo; to access your account.</p>
                <p>If Face Verification is unavailable or has not been enrolled, please contact your Manager for a password reset.</p>
            </div>
            <a class="login-button primary login-button-link" href="login.php">Back to Login</a>
        </section>
    </main>
</body>
</html>

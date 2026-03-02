<?php
session_start();
$loginError = $_SESSION['login_error'] ?? '';
$registerSuccess = $_SESSION['register_success'] ?? '';
$logoutMessage = $_SESSION['logout_message'] ?? '';
unset($_SESSION['login_error'], $_SESSION['register_success'], $_SESSION['logout_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>AutoBert · Login</title>
    <link rel="stylesheet" href="style.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

</head>
<body class="login">
    <div class="transparentbox">
        <!-- Brand Header -->
        <div class="brand-header">
            <div class="brand-icon">
                <i class="bi bi-gear-wide-connected"></i>
            </div>
            <div class="brand-text">
                <h2>AutoBert</h2>
                <p>Repair Shop & Batteries</p>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if (!empty($loginError)): ?>
            <div class="alert error">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($loginError) ?>
            </div>
        <?php elseif (!empty($registerSuccess)): ?>
            <div class="alert success">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($registerSuccess) ?>
            </div>
        <?php elseif (!empty($logoutMessage)): ?>
            <div class="alert success">
                <i class="bi bi-box-arrow-right"></i>
                <?= htmlspecialchars($logoutMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" action="login.php">
            <div class="input-group">
                <span class="input-icon"><i class="bi bi-envelope"></i></span>
                <input 
                    type="email" 
                    name="email" 
                    placeholder="Email address" 
                    required 
                    autocomplete="email"
                    autofocus
                />
            </div>

            <div class="input-group">
                <span class="input-icon"><i class="bi bi-lock"></i></span>
                <input 
                    type="password" 
                    name="password" 
                    placeholder="Password" 
                    required 
                    autocomplete="current-password"
                />
            </div>

            <div class="forgot-password">
                <a href="forgot_password.php">Forgot password?</a>
            </div>

            <input type="hidden" name="login" value="1" />
            
            <button type="submit" class="login-btn">
                <i class="bi bi-box-arrow-in-right"></i>
                Sign In
            </button>
        </form>

        <div class="divider">
            <span style="color: black">New here ?</span>
        </div>

        <div class="register-link">
            <a href="register.php">
                <i class="bi bi-person-plus"></i> Create an account
            </a>
        </div>

    <!-- Footer -->
    <div style="position: fixed; bottom: 16px; text-align: center; width: 100%; color: rgba(255,255,255,0.6); font-size: 12px;">
        © <?= date('Y') ?> AutoBert Repair Shop. All rights reserved.
    </div>
</body>
</html>
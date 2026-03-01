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
    <style>
        /* Additional styles specific to login page */
        .brand-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .brand-icon {
            width: 48px;
            height: 48px;
            background: var(--accent);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
        }
        
        .brand-text h2 {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 24px;
            color: #232020;
            margin: 0;
            line-height: 1.2;
        }
        
        .brand-text p {
            font-size: 12px;
            color: #666;
            margin: 2px 0 0 0;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 16px;
        }
        
        .input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 16px;
        }
        
        .input-group input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        .input-group input:focus {
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }
        
        .input-group input::placeholder {
            color: #999;
            font-size: 13px;
        }
        
        .login-btn {
            width: 100%;
            padding: 12px;
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }
        
        .login-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .login-btn i {
            font-size: 18px;
        }
        
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0 16px;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .divider span {
            padding: 0 10px;
            color: #999;
            font-size: 12px;
            text-transform: uppercase;
        }
        
        .register-link {
            text-align: center;
            margin-top: 16px;
        }
        
        .register-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        
        .register-link a:hover {
            color: var(--accent-hover);
            text-decoration: underline;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert.error {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        
        .alert.success {
            background: #dcfce7;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }
        
        .alert i {
            font-size: 18px;
        }
        
        .demo-credentials {
            margin-top: 24px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px dashed #ccc;
        }
        
        .demo-credentials p {
            font-size: 11px;
            color: #666;
            margin: 0 0 8px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .cred-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            padding: 4px 0;
            color: #444;
        }
        
        .cred-row strong {
            color: var(--accent);
            font-family: monospace;
        }
        
        .forgot-password {
            text-align: right;
            margin-top: 8px;
        }
        
        .forgot-password a {
            color: #999;
            font-size: 12px;
            text-decoration: none;
        }
        
        .forgot-password a:hover {
            color: var(--accent);
        }
    </style>
</head>
<body class="login">
    <div class="transparentbox" style="width: 400px; padding: 32px;">
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
                <i class="bi bi-envelope input-icon"></i>
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
                <i class="bi bi-lock input-icon"></i>
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
            <span>New here?</span>
        </div>

        <div class="register-link">
            <a href="register.php">
                <i class="bi bi-person-plus"></i> Create an account
            </a>
        </div>

        <!-- Demo Credentials (Remove in production) 
        <div class="demo-credentials">
            <p><i class="bi bi-info-circle"></i> Demo Credentials</p>
            <div class="cred-row">
                <span>Owner:</span>
                <strong>owner@autobert.com / owner123</strong>
            </div>
            <div class="cred-row">
                <span>Partner:</span>
                <strong>partner@autobert.com / partner123</strong>
            </div>
            <div class="cred-row">
                <span>Employee:</span>
                <strong>mechanic@autobert.com / mechanic123</strong>
            </div>
            <p style="font-size: 10px; color: #999; margin-top: 8px; text-align: center;">
                <i class="bi bi-shield-check"></i> Owner accounts are auto-approved
            </p>
        </div>
    </div> -->

    <!-- Footer -->
    <div style="position: fixed; bottom: 16px; text-align: center; width: 100%; color: rgba(255,255,255,0.6); font-size: 12px;">
        © <?= date('Y') ?> AutoBert Repair Shop. All rights reserved.
    </div>
</body>
</html>
<?php
session_start();
$loginError = $_SESSION['login_error'] ?? '';
$registerSuccess = $_SESSION['register_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['register_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Login</title>
    <link rel="stylesheet" href="style.css" />
</head>
<body class="login">
<div class="transparentbox">
    <form method="POST" action="login.php">
        <h1>Login</h1>

        <?php if (!empty($loginError)): ?>
            <p class="error-message"><?= htmlspecialchars($loginError) ?></p>
        <?php elseif (!empty($registerSuccess)): ?>
            <p class="success-message"><?= htmlspecialchars($registerSuccess) ?></p>
        <?php endif; ?>

        <input type="email" name="email" placeholder="Email" required />
        <input type="password" name="password" placeholder="Password" required />
        <input type="hidden" name="login" value="1" />
        <button type="submit">Login</button>
    </form>

    <div class="form-toggle">
        <p><a href="register.php">Register here</a></p>
    </div>
</div>
</body>
</html>
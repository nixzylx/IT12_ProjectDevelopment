<?php
session_start();
$registerError = $_SESSION['register_error'] ?? '';
unset($_SESSION['register_error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register</title>
  <link rel="stylesheet" href="style.css">
</head>

<body class="login">
<div class="transparentbox">
  <form method="POST" action="login_register.php">
    <h1>Create Account</h1>

    <?php if (!empty($registerError)): ?>
      <p class="error-message"><?= htmlspecialchars($registerError) ?></p>
    <?php endif; ?>

    <input type="hidden" name="employeeID" value="<?php echo rand(10000, 99999); ?>">
    <input type="text" name="firstname" placeholder="First Name" required>
    <input type="text" name="lastname" placeholder="Last Name" required>

    <select name="role" required>
      <option value="">Select Role</option>
      <option value="Owner">Owner</option>
      <option value="Business Partner">Business Partner</option>
      <option value="Employee">Mechanic</option>
    </select>

    <input type="email" name="email" placeholder="Email" required>
    <input type="password" name="password" placeholder="Password" required>

    <button type="submit">Register</button>
  </form>

  <div class="form-toggle">
    <p>Already have an account? <a href="index.php">Login here</a></p>
  </div>
  
</div>
</body>
</html>

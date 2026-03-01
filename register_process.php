<?php
session_start();
require_once 'dbconnection.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: register.php");
    exit();
}

$firstname = trim($_POST['firstname'] ?? '');
$lastname = trim($_POST['lastname'] ?? '');
$role = trim($_POST['role'] ?? '');
$email = trim($_POST['email'] ?? '');
$password_raw = $_POST['password'] ?? '';

// Validation
if (empty($firstname) || empty($lastname) || empty($role) || empty($email) || empty($password_raw)) {
    $_SESSION['register_error'] = 'Please fill in all required fields.';
    header("Location: register.php");
    exit();
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['register_error'] = 'Please enter a valid email address.';
    header("Location: register.php");
    exit();
}

// Check if email already exists
$check_stmt = $conn->prepare("SELECT email FROM employee WHERE email = ?");
$check_stmt->bind_param('s', $email);
$check_stmt->execute();
$check_stmt->store_result();

if ($check_stmt->num_rows > 0) {
    $_SESSION['register_error'] = 'Email is already registered!';
    $check_stmt->close();
    header("Location: register.php");
    exit();
}
$check_stmt->close();

// Get owner email from settings
$owner_email = '';
$settings_query = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'owner_email'");
if ($settings_query && $settings_row = $settings_query->fetch_assoc()) {
    $owner_email = $settings_row['setting_value'];
}

// Determine if user should be auto-approved
$is_approved = 0; // Default: not approved
$approval_message = '';

// If this is the owner email, auto-approve
if ($email === $owner_email) {
    $is_approved = 1;
    $role = 'Owner'; // Force role to Owner for owner email
    $approval_message = 'Owner account created successfully!';
} 
// For Business Partner and Employee roles, require approval
else if ($role === 'Owner') {
    // Prevent anyone from registering as Owner unless it's the owner email
    $_SESSION['register_error'] = 'Owner accounts can only be created by the system administrator.';
    header("Location: register.php");
    exit();
} else {
    // Business Partner or Employee - needs approval
    $is_approved = 0;
    $approval_message = 'Registration successful! Your account is pending approval.';
}

// Hash password
$password = password_hash($password_raw, PASSWORD_DEFAULT);

// Insert user
$insert_stmt = $conn->prepare("INSERT INTO employee (first_name, last_name, email, role, password, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
$insert_stmt->bind_param('sssssi', $firstname, $lastname, $email, $role, $password, $is_approved);

if ($insert_stmt->execute()) {
    $insert_stmt->close();
    
    if ($is_approved) {
        $_SESSION['register_success'] = $approval_message . ' You can now log in.';
    } else {
        $_SESSION['register_success'] = $approval_message . ' An administrator will review your request.';
        
        // Optional: Send notification to admin
        // You could implement email notification here
    }
    
    header("Location: index.php");
    exit();
} else {
    $_SESSION['register_error'] = 'Registration failed. Please try again.';
    $insert_stmt->close();
    header("Location: register.php");
    exit();
}
?>
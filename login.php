<?php
session_start();
require_once 'dbconnection.php';

// Simple login handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $sql = "SELECT * FROM employee WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        if (password_verify($password, $user['password'])) {
            $_SESSION['employeeID'] = $user['employeeID'];
            $_SESSION['firstname'] = $user['first_name'];
            $_SESSION['lastname'] = $user['last_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_role'] = $user['role'];
            
            header("Location: admin_dashboard.php");
            exit();
        }
    }

    $_SESSION['login_error'] = 'Invalid email or password';
    header("Location: index.php");
    exit();
}
?>
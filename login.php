<?php
session_start();
require_once 'dbconnection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['login']) && $_POST['login'] !== '') {
        
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $conn->prepare("SELECT * FROM employee WHERE LOWER(email) = LOWER(?)");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();

            $hashedPassword = $user['password'] ?? $user['Password'] ?? null;
            if ($hashedPassword && (password_verify($password, $hashedPassword) || $password === $hashedPassword)) {
                $_SESSION['employeeID']      = $user['employeeID'];
                $_SESSION['firstname']  = $user['first_name'];
                $_SESSION['lastname']   = $user['last_name'];
                $_SESSION['role']     = $user['role'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['email']        = $user['email'];
                $stmt->close();
                
                error_log("Login successful - Role: " . $user['role']);
                header("Location: admin_dashboard.php");
                exit();
            } else {
                error_log("Password verification failed for: " . $email);
            }
        }

        $_SESSION['login_error'] = 'Incorrect Email or Password (Email found: ' . ($result && $result->num_rows > 0 ? 'Yes' : 'No') . ')';
        header("Location: index.php");
        exit();
    } else {

        $employeeID = rand(01, 50); 
        $firstname    = trim($_POST['firstname'] ?? '');
        $lastname     = trim($_POST['lastname'] ?? '');
        $role       = trim($_POST['role'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $password_raw = $_POST['password'] ?? '';

        if (
            empty($firstname) || empty($lastname) ||
            empty($role) || empty($email) || empty($password_raw)
        ) {
            $_SESSION['register_error'] = 'Please fill in all required fields.';
            header("Location: register.php");
            exit();
        }

        $stmt = $conn->prepare("SELECT email FROM employee WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $_SESSION['register_error'] = 'Email is already registered!';
            $stmt->close();
            header("Location: register.php");
            exit();
        }
        $stmt->close();

        $password = password_hash($password_raw, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("INSERT INTO employee (first_name, last_name, email, role, password)
                                VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sssss', $firstname, $lastname, $email, $role, $password);

        if ($stmt->execute()) {
            $_SESSION['register_success'] = 'Registration successful! Please log in.';
            $stmt->close();
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['register_error'] = 'Registration failed. Please try again.';
            $stmt->close();
            header("Location: register.php");
            exit();
        }
    }
}

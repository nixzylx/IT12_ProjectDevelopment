<?php
// install_now.php - Run this FIRST before anything else

echo "<!DOCTYPE html>
<html>
<head>
    <title>AutoBert Installation</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 30px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2563eb; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .btn { background: #2563eb; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
        code { background: #f8f9fa; padding: 2px 5px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>🔧 AutoBert Installation</h1>";

// Try to connect to MySQL without selecting a database
$conn = @new mysqli("localhost", "root", "");

if ($conn->connect_error) {
    echo "<div class='error'>";
    echo "<strong>❌ MySQL Connection Failed!</strong><br>";
    echo "Error: " . $conn->connect_error . "<br>";
    echo "<br>Please check:";
    echo "<ul>";
    echo "<li>Is XAMPP running?</li>";
    echo "<li>Is MySQL started in XAMPP Control Panel?</li>";
    echo "<li>Click 'Start' next to MySQL in XAMPP</li>";
    echo "</ul>";
    echo "</div>";
} else {
    echo "<div class='success'>✅ Connected to MySQL successfully!</div>";
    
    // Create database
    if ($conn->query("CREATE DATABASE IF NOT EXISTS autobert")) {
        echo "<div class='success'>✅ Database 'autobert' created or already exists</div>";
    } else {
        echo "<div class='error'>❌ Failed to create database: " . $conn->error . "</div>";
    }
    
    // Select the database
    $conn->select_db("autobert");
    
    // Create employee table
    $sql = "CREATE TABLE IF NOT EXISTS employee (
        employeeID INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(50) NOT NULL,
        last_name VARCHAR(50) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        role VARCHAR(50) NOT NULL,
        password VARCHAR(255) NOT NULL,
        is_approved TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($sql)) {
        echo "<div class='success'>✅ Table 'employee' created</div>";
    } else {
        echo "<div class='error'>❌ Failed to create table: " . $conn->error . "</div>";
    }
    
    // Create owner account
    $owner_email = 'owner@autobert.com';
    $owner_password = password_hash('owner123', PASSWORD_DEFAULT);
    
    $check = $conn->query("SELECT * FROM employee WHERE email = '$owner_email'");
    if ($check->num_rows == 0) {
        $sql = "INSERT INTO employee (first_name, last_name, email, role, password, is_approved) 
                VALUES ('System', 'Owner', '$owner_email', 'Owner', '$owner_password', 1)";
        
        if ($conn->query($sql)) {
            echo "<div class='success'>✅ Owner account created</div>";
        } else {
            echo "<div class='error'>❌ Failed to create owner: " . $conn->error . "</div>";
        }
    } else {
        echo "<div class='success'>✅ Owner account already exists</div>";
    }
    
    echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 5px; margin-top: 20px;'>";
    echo "<h3>📋 Login Credentials</h3>";
    echo "<p><strong>Email:</strong> owner@autobert.com<br>";
    echo "<strong>Password:</strong> owner123</p>";
    echo "</div>";
    
    echo "<a href='index.php' class='btn'>Go to Login Page →</a>";
    
    $conn->close();
}

echo "</div></body></html>";
?>
<?php
// createdb.php - Run this first!

$servername = "localhost";
$username = "root";
$password = ""; // XAMPP default is empty

echo "<h2>🔧 AutoBert Database Setup</h2>";

// Connect to MySQL server without selecting a database
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("<span style='color:red'>❌ Connection failed: " . $conn->connect_error . "</span>");
}

echo "<span style='color:green'>✅ Connected to MySQL successfully</span><br>";

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS autobert";
if ($conn->query($sql) === TRUE) {
    echo "<span style='color:green'>✅ Database 'autobert' created or already exists</span><br>";
} else {
    echo "<span style='color:red'>❌ Error creating database: " . $conn->error . "</span><br>";
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

if ($conn->query($sql) === TRUE) {
    echo "<span style='color:green'>✅ Table 'employee' created</span><br>";
} else {
    echo "<span style='color:red'>❌ Error creating table: " . $conn->error . "</span><br>";
}

// Create owner account
$owner_email = 'owner@autobert.com';
$owner_password = password_hash('owner123', PASSWORD_DEFAULT);

$check = $conn->query("SELECT * FROM employee WHERE email = '$owner_email'");
if ($check->num_rows == 0) {
    $sql = "INSERT INTO employee (first_name, last_name, email, role, password, is_approved) 
            VALUES ('System', 'Owner', '$owner_email', 'Owner', '$owner_password', 1)";
    
    if ($conn->query($sql) === TRUE) {
        echo "<span style='color:green'>✅ Owner account created</span><br>";
    } else {
        echo "<span style='color:red'>❌ Error creating owner: " . $conn->error . "</span><br>";
    }
} else {
    echo "<span style='color:green'>✅ Owner account already exists</span><br>";
}

echo "<br><strong>Setup complete!</strong><br>";
echo "<a href='index.php'>Go to Login Page</a><br>";
echo "<a href='test_connection.php'>Test Database Connection</a>";

$conn->close();
?>
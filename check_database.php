<?php
// check_database.php - Run this first to check and create database

$servername = "localhost";
$username = "root";
$password = ""; // Leave empty for XAMPP

// Connect without database
$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Database Connection Test</h2>";

// Check if database exists
$db_check = $conn->query("SHOW DATABASES LIKE 'autobert'");
if ($db_check->num_rows > 0) {
    echo "✅ Database 'autobert' already exists.<br>";
} else {
    echo "❌ Database 'autobert' does not exist.<br>";
    echo "Creating database 'autobert'...<br>";
    
    // Create database
    if ($conn->query("CREATE DATABASE autobert")) {
        echo "✅ Database 'autobert' created successfully!<br>";
    } else {
        echo "❌ Failed to create database: " . $conn->error . "<br>";
    }
}

// List all databases
echo "<h3>Available Databases:</h3>";
$databases = $conn->query("SHOW DATABASES");
echo "<ul>";
while ($db = $databases->fetch_array()) {
    echo "<li>" . $db[0] . "</li>";
}
echo "</ul>";

$conn->close();

echo "<br><a href='setup_tables.php'>Click here to create tables →</a>";
?>
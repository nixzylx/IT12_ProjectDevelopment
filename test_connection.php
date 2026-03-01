<?php
// test_connection.php - Test if database connection works

echo "<h2>🔍 Database Connection Test</h2>";

// Test connection without database
try {
    $conn = new mysqli("localhost", "root", "");
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    echo "<span style='color:green'>✅ MySQL Connection: OK</span><br>";
    
    // Check if database exists
    $result = $conn->query("SHOW DATABASES LIKE 'autobert'");
    if ($result->num_rows > 0) {
        echo "<span style='color:green'>✅ Database 'autobert' exists</span><br>";
    } else {
        echo "<span style='color:red'>❌ Database 'autobert' does not exist</span><br>";
        echo "Please run <a href='createdb.php'>createdb.php</a> first<br>";
    }
    
    $conn->close();
} catch (Exception $e) {
    echo "<span style='color:red'>❌ " . $e->getMessage() . "</span><br>";
    echo "Make sure MySQL is running in XAMPP Control Panel";
}

echo "<br><a href='createdb.php'>Create Database</a> | ";
echo "<a href='index.php'>Go to Login</a>";
?>
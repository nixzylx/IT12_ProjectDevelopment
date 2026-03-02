<?php

$servername = "localhost";
$username = "root";
$password = ""; 
$dbname = "autobert_db";

$temp_conn = @new mysqli($servername, $username, $password);

if ($temp_conn->connect_error) {
    die("<div style='background: #fee; padding: 20px; border: 2px solid #f00; margin: 20px; font-family: Arial;'>
         <h2 style='color: #c00;'>❌ MySQL Connection Error</h2>
         <p>Could not connect to MySQL. Please check:</p>
         <ul>
            <li>Open XAMPP Control Panel</li>
            <li>Make sure MySQL is running (green indicator)</li>
            <li>If not, click 'Start' next to MySQL</li>
         </ul>
         <p><strong>Error details:</strong> " . $temp_conn->connect_error . "</p>
         <p><a href='install_now.php' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Run Installer</a></p>
         </div>");
}

// Check if database exists
$db_check = $temp_conn->query("SHOW DATABASES LIKE '$dbname'");
if ($db_check->num_rows == 0) {
    $temp_conn->close();
    die("<div style='background: #fee; padding: 20px; border: 2px solid #f00; margin: 20px; font-family: Arial;'>
         <h2 style='color: #c00;'>❌ Database Not Found</h2>
         <p>The database '<strong>$dbname</strong>' does not exist.</p>
         <p>Please run the installer to create the database.</p>
         <p><a href='install_now.php' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Run Installer Now</a></p>
         </div>");
}

$temp_conn->close();

// Now connect with database
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8");
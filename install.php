<?php
// install.php - One-click installer

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    
    $servername = "localhost";
    $username = "root";
    $password = ""; // Change if you have MySQL password
    
    // Connect without database
    $conn = new mysqli($servername, $username, $password);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Create database
    if (!$conn->query("CREATE DATABASE IF NOT EXISTS autobert")) {
        die("Error creating database: " . $conn->error);
    }
    
    // Select database
    $conn->select_db("autobert");
    
    // Create tables
    $tables = [
        "employee" => "CREATE TABLE IF NOT EXISTS employee (
            employeeID INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            role ENUM('Owner', 'Business Partner', 'Employee') NOT NULL,
            password VARCHAR(255) NOT NULL,
            is_approved TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL
        )",
        
        "settings" => "CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    ];
    
    foreach ($tables as $name => $sql) {
        if (!$conn->query($sql)) {
            die("Error creating $name table: " . $conn->error);
        }
    }
    
    // Add foreign key
    $conn->query("ALTER TABLE employee ADD FOREIGN KEY (approved_by) REFERENCES employee(employeeID)");
    
    // Create owner account
    $owner_email = 'owner@autobert.com';
    $owner_password = password_hash('owner123', PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO employee (first_name, last_name, email, role, password, is_approved) 
                            VALUES (?, ?, ?, ?, ?, ?)");
    $firstname = 'System';
    $lastname = 'Owner';
    $role = 'Owner';
    $is_approved = 1;
    
    $stmt->bind_param('sssssi', $firstname, $lastname, $owner_email, $role, $owner_password, $is_approved);
    $stmt->execute();
    $stmt->close();
    
    // Add settings
    $conn->query("INSERT INTO settings (setting_key, setting_value) 
                  VALUES ('owner_email', '$owner_email')");
    
    $install_success = true;
    $conn->close();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>AutoBert Installer</title>
    <style>
        body { font-family: Arial; background: #f0f0f0; padding: 50px; }
        .installer { max-width: 500px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2563eb; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .btn { background: #2563eb; color: white; border: none; padding: 12px 30px; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="installer">
        <h1>🔧 AutoBert Installation</h1>
        
        <?php if (isset($install_success)): ?>
            <div class="success">
                <h3>✅ Installation Successful!</h3>
                <p><strong>Owner Login:</strong><br>
                Email: owner@autobert.com<br>
                Password: owner123</p>
                <a href="index.php" class="btn">Go to Login</a>
            </div>
        <?php else: ?>
            <p>This will create the database and initial setup for AutoBert system.</p>
            
            <form method="POST">
                <input type="hidden" name="install" value="1">
                <button type="submit" class="btn">Install Now</button>
            </form>
            
            <p style="color: #666; font-size: 14px; margin-top: 20px;">
                ⚠️ Make sure your MySQL server is running and credentials are correct.
            </p>
        <?php endif; ?>
    </div>
</body>
</html>
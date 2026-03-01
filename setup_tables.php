<?php
// setup_tables.php - Run this after database is created

$servername = "localhost";
$username = "root";
$password = ""; // Leave empty for XAMPP
$dbname = "autobert";

// Create connection with database
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Setting up tables for AutoBert</h2>";

// Create employee table
$sql = "CREATE TABLE IF NOT EXISTS employee (
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
)";

if ($conn->query($sql) === TRUE) {
    echo "✅ Table 'employee' created successfully<br>";
} else {
    echo "❌ Error creating employee table: " . $conn->error . "<br>";
}

// Add foreign key constraint separately
$conn->query("ALTER TABLE employee ADD FOREIGN KEY (approved_by) REFERENCES employee(employeeID)");

// Create settings table
$sql = "CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "✅ Table 'settings' created successfully<br>";
} else {
    echo "❌ Error creating settings table: " . $conn->error . "<br>";
}

// Create customers table
$sql = "CREATE TABLE IF NOT EXISTS customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "✅ Table 'customers' created successfully<br>";
} else {
    echo "❌ Error creating customers table: " . $conn->error . "<br>";
}

// Insert default owner
$owner_email = 'owner@autobert.com';
$owner_password = password_hash('owner123', PASSWORD_DEFAULT);

// Check if owner exists
$check = $conn->query("SELECT employeeID FROM employee WHERE email = '$owner_email'");
if ($check->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO employee (first_name, last_name, email, role, password, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
    $firstname = 'System';
    $lastname = 'Owner';
    $role = 'Owner';
    $is_approved = 1;
    
    $stmt->bind_param('sssssi', $firstname, $lastname, $owner_email, $role, $owner_password, $is_approved);
    
    if ($stmt->execute()) {
        echo "✅ Default owner account created<br>";
        echo "   Email: owner@autobert.com<br>";
        echo "   Password: owner123<br>";
    } else {
        echo "❌ Failed to create owner account: " . $stmt->error . "<br>";
    }
    $stmt->close();
} else {
    echo "✅ Owner account already exists<br>";
}

// Insert owner email setting
$conn->query("INSERT INTO settings (setting_key, setting_value) 
              VALUES ('owner_email', '$owner_email')
              ON DUPLICATE KEY UPDATE setting_value = '$owner_email'");

echo "<br><strong>Setup completed!</strong><br>";
echo "<a href='index.php'>Go to Login Page</a>";

$conn->close();
?>
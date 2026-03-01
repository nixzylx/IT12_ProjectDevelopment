<?php
// setup_database.php - Run this file once to create your database and tables

// Database configuration
$servername = "localhost";
$username = "root";
$password = ""; // Leave empty for XAMPP default

// Create connection without database
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database
$sql = "CREATE DATABASE IF NOT EXISTS autobert";
if ($conn->query($sql) === TRUE) {
    echo "Database 'autobert' created successfully or already exists.<br>";
} else {
    echo "Error creating database: " . $conn->error . "<br>";
}

// Select the database
$conn->select_db("autobert");

// Create tables
$tables = [
    "employee" => "
        CREATE TABLE IF NOT EXISTS employee (
            employeeID INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            role ENUM('Owner', 'Business Partner', 'Employee') NOT NULL,
            password VARCHAR(255) NOT NULL,
            is_approved TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            FOREIGN KEY (approved_by) REFERENCES employee(employeeID)
        )
    ",
    
    "settings" => "
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ",
    
    "customers" => "
        CREATE TABLE IF NOT EXISTS customers (
            customer_id INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            email VARCHAR(100),
            phone VARCHAR(20),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ",
    
    "vehicles" => "
        CREATE TABLE IF NOT EXISTS vehicles (
            vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            brand VARCHAR(50) NOT NULL,
            model VARCHAR(50) NOT NULL,
            year INT,
            plate_number VARCHAR(20) UNIQUE,
            vin VARCHAR(50),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE
        )
    ",
    
    "job_orders" => "
        CREATE TABLE IF NOT EXISTS job_orders (
            job_order_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            mechanic_id INT,
            job_description TEXT NOT NULL,
            status ENUM('Pending', 'In Progress', 'Completed', 'Cancelled') DEFAULT 'Pending',
            date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_completed TIMESTAMP NULL,
            estimated_cost DECIMAL(10,2),
            actual_cost DECIMAL(10,2),
            notes TEXT,
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id),
            FOREIGN KEY (mechanic_id) REFERENCES employee(employeeID)
        )
    ",
    
    "products" => "
        CREATE TABLE IF NOT EXISTS products (
            product_id INT AUTO_INCREMENT PRIMARY KEY,
            product_name VARCHAR(100) NOT NULL,
            description TEXT,
            sku VARCHAR(50) UNIQUE,
            category VARCHAR(50),
            unit_price DECIMAL(10,2) NOT NULL,
            cost_price DECIMAL(10,2) NOT NULL,
            stock_quantity INT DEFAULT 0,
            reorder_level INT DEFAULT 5,
            supplier VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ",
    
    "sales" => "
        CREATE TABLE IF NOT EXISTS sales (
            sale_id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(20) UNIQUE NOT NULL,
            customer_id INT,
            sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            subtotal DECIMAL(10,2) NOT NULL,
            tax DECIMAL(10,2) DEFAULT 0,
            discount DECIMAL(10,2) DEFAULT 0,
            total_amount DECIMAL(10,2) NOT NULL,
            status ENUM('Paid', 'Unpaid', 'Partially Paid') DEFAULT 'Unpaid',
            payment_method VARCHAR(50),
            notes TEXT,
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        )
    ",
    
    "sale_items" => "
        CREATE TABLE IF NOT EXISTS sale_items (
            sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT NOT NULL,
            product_id INT,
            description VARCHAR(255),
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL,
            FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(product_id)
        )
    ",
    
    "payments" => "
        CREATE TABLE IF NOT EXISTS payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT,
            customer_id INT,
            amount_paid DECIMAL(10,2) NOT NULL,
            payment_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            payment_method VARCHAR(50),
            reference_number VARCHAR(100),
            notes TEXT,
            FOREIGN KEY (sale_id) REFERENCES sales(sale_id),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        )
    ",
    
    "credit_accounts" => "
        CREATE TABLE IF NOT EXISTS credit_accounts (
            credit_account_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNIQUE NOT NULL,
            credit_limit DECIMAL(10,2) DEFAULT 0,
            current_balance DECIMAL(10,2) DEFAULT 0,
            due_date DATE,
            status ENUM('Active', 'Suspended', 'Closed') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id)
        )
    ",
    
    "warranties" => "
        CREATE TABLE IF NOT EXISTS warranties (
            warranty_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT,
            customer_id INT,
            sale_id INT,
            warranty_start DATE NOT NULL,
            warranty_end DATE NOT NULL,
            warranty_status ENUM('Active', 'Expired', 'Claimed') DEFAULT 'Active',
            terms TEXT,
            FOREIGN KEY (product_id) REFERENCES products(product_id),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
            FOREIGN KEY (sale_id) REFERENCES sales(sale_id)
        )
    "
];

// Execute table creation
foreach ($tables as $table_name => $sql) {
    if ($conn->query($sql) === TRUE) {
        echo "Table '$table_name' created successfully or already exists.<br>";
    } else {
        echo "Error creating table '$table_name': " . $conn->error . "<br>";
    }
}

// Insert default owner
$owner_email = 'owner@autobert.com';
$owner_password = password_hash('owner123', PASSWORD_DEFAULT);

$check_owner = $conn->query("SELECT employeeID FROM employee WHERE email = '$owner_email'");
if ($check_owner->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO employee (first_name, last_name, email, role, password, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
    $firstname = 'System';
    $lastname = 'Owner';
    $role = 'Owner';
    $is_approved = 1;
    
    $stmt->bind_param('sssssi', $firstname, $lastname, $owner_email, $role, $owner_password, $is_approved);
    
    if ($stmt->execute()) {
        echo "✓ Default owner account created.<br>";
        echo "  Email: owner@autobert.com<br>";
        echo "  Password: owner123<br>";
    } else {
        echo "✗ Failed to create owner account: " . $stmt->error . "<br>";
    }
    $stmt->close();
} else {
    echo "✓ Owner account already exists.<br>";
}

// Insert owner email in settings
$conn->query("INSERT INTO settings (setting_key, setting_value) 
              VALUES ('owner_email', '$owner_email')
              ON DUPLICATE KEY UPDATE setting_value = '$owner_email'");

// Insert sample data
echo "<br>Inserting sample data...<br>";

// Sample customers
$sample_customers = [
    ['Juan', 'Dela Cruz', 'juan@gmail.com', '09171234567', '123 Mabini St., Manila'],
    ['Maria', 'Santos', 'maria@gmail.com', '09181234567', '456 Rizal Ave., Quezon City'],
    ['Jose', 'Gonzales', 'jose@gmail.com', '09191234567', '789 Bonifacio St., Makati']
];

foreach ($sample_customers as $customer) {
    $stmt = $conn->prepare("INSERT IGNORE INTO customers (first_name, last_name, email, phone, address) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $customer[0], $customer[1], $customer[2], $customer[3], $customer[4]);
    $stmt->execute();
    $stmt->close();
}
echo "✓ Sample customers added.<br>";

// Sample products
$sample_products = [
    ['Engine Oil', 'Synthetic 5W-30', 'OIL-001', 'Oils', 850.00, 500.00, 50, 10, 'Shell Philippines'],
    ['Oil Filter', 'Compatible with most cars', 'FIL-001', 'Filters', 350.00, 200.00, 100, 20, 'Vic Filter'],
    ['Brake Pads', 'Front brake pads', 'BRK-001', 'Brakes', 2500.00, 1500.00, 30, 5, 'Bendix'],
    ['Battery', 'NS40ZL Maintenance Free', 'BAT-001', 'Batteries', 4500.00, 3000.00, 20, 5, 'Motolite'],
    ['Spark Plugs', 'Iridium (Set of 4)', 'SPK-001', 'Ignition', 1200.00, 800.00, 40, 10, 'NGK']
];

foreach ($sample_products as $product) {
    $stmt = $conn->prepare("INSERT IGNORE INTO products (product_name, description, sku, category, unit_price, cost_price, stock_quantity, reorder_level, supplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssddiis', $product[0], $product[1], $product[2], $product[3], $product[4], $product[5], $product[6], $product[7], $product[8]);
    $stmt->execute();
    $stmt->close();
}
echo "✓ Sample products added.<br>";

echo "<br><strong>Setup completed successfully!</strong><br>";
echo "<a href='index.php'>Go to Login Page</a>";

$conn->close();
?>
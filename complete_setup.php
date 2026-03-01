<?php
// complete_setup.php - Run this to create ALL tables

// Display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>AutoBert Complete Setup</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 30px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2563eb; }
        .success { background: #d4edda; color: #155724; padding: 10px 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 10px 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .warning { background: #fff3cd; color: #856404; padding: 10px 15px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #ffc107; }
        code { background: #f8f9fa; padding: 2px 5px; border-radius: 3px; }
        .btn { background: #2563eb; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>🔧 AutoBert Complete Database Setup</h1>";

// Database configuration
$servername = "localhost";
$username = "root";
$password = ""; // XAMPP default
$dbname = "autobert";

// Connect to MySQL
$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die("<div class='error'>❌ Connection failed: " . $conn->connect_error . "</div>");
}

echo "<div class='success'>✅ Connected to MySQL successfully</div>";

// Create database if not exists
if ($conn->query("CREATE DATABASE IF NOT EXISTS $dbname")) {
    echo "<div class='success'>✅ Database '$dbname' ready</div>";
} else {
    echo "<div class='error'>❌ Failed to create database: " . $conn->error . "</div>";
}

// Select the database
$conn->select_db($dbname);

// Array of tables to create
$tables = [
    "employee" => "
        CREATE TABLE IF NOT EXISTS employee (
            employeeID INT AUTO_INCREMENT PRIMARY KEY,
            first_name VARCHAR(50) NOT NULL,
            last_name VARCHAR(50) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            role VARCHAR(50) NOT NULL,
            password VARCHAR(255) NOT NULL,
            is_approved TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
            plate_number VARCHAR(20),
            vin VARCHAR(50),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE
        )
    ",
    
    "products" => "
        CREATE TABLE IF NOT EXISTS products (
            product_id INT AUTO_INCREMENT PRIMARY KEY,
            product_name VARCHAR(100) NOT NULL,
            description TEXT,
            sku VARCHAR(50) UNIQUE,
            category VARCHAR(50),
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            stock_quantity INT DEFAULT 0,
            reorder_level INT DEFAULT 5,
            supplier VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ",
    
    "job_orders" => "
        CREATE TABLE IF NOT EXISTS job_orders (
            job_order_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            vehicle_id INT NOT NULL,
            mechanic_id INT,
            job_description TEXT NOT NULL,
            status VARCHAR(50) DEFAULT 'Pending',
            date_received TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_completed TIMESTAMP NULL,
            estimated_cost DECIMAL(10,2) DEFAULT 0,
            actual_cost DECIMAL(10,2) DEFAULT 0,
            notes TEXT,
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id),
            FOREIGN KEY (mechanic_id) REFERENCES employee(employeeID)
        )
    ",
    
    "sales" => "
        CREATE TABLE IF NOT EXISTS sales (
            sale_id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(20) UNIQUE,
            customer_id INT,
            sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            subtotal DECIMAL(10,2) DEFAULT 0,
            tax DECIMAL(10,2) DEFAULT 0,
            discount DECIMAL(10,2) DEFAULT 0,
            total_amount DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(50) DEFAULT 'Unpaid',
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
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
            FOREIGN KEY (sale_id) REFERENCES sales(sale_id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(product_id)
        )
    ",
    
    "payments" => "
        CREATE TABLE IF NOT EXISTS payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            sale_id INT,
            customer_id INT,
            amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
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
            status VARCHAR(50) DEFAULT 'Active',
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
            warranty_status VARCHAR(50) DEFAULT 'Active',
            terms TEXT,
            FOREIGN KEY (product_id) REFERENCES products(product_id),
            FOREIGN KEY (customer_id) REFERENCES customers(customer_id),
            FOREIGN KEY (sale_id) REFERENCES sales(sale_id)
        )
    ",
    
    "stock_movements" => "
        CREATE TABLE IF NOT EXISTS stock_movements (
            movement_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            adjustment INT NOT NULL,
            reason VARCHAR(255),
            user_id INT,
            movement_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES products(product_id),
            FOREIGN KEY (user_id) REFERENCES employee(employeeID)
        )
    ",
    
    "settings" => "
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    "
];

// Create tables
echo "<h2>Creating Tables...</h2>";

foreach ($tables as $table_name => $sql) {
    if ($conn->query($sql)) {
        echo "<div class='success'>✅ Table '$table_name' created successfully</div>";
    } else {
        echo "<div class='error'>❌ Error creating '$table_name': " . $conn->error . "</div>";
    }
}

// Check if owner exists
$check_owner = $conn->query("SELECT * FROM employee WHERE email = 'owner@autobert.com'");
if ($check_owner->num_rows == 0) {
    // Create owner account
    $password = password_hash('owner123', PASSWORD_DEFAULT);
    $sql = "INSERT INTO employee (first_name, last_name, email, role, password, is_approved) 
            VALUES ('System', 'Owner', 'owner@autobert.com', 'Owner', '$password', 1)";
    
    if ($conn->query($sql)) {
        echo "<div class='success'>✅ Owner account created</div>";
    } else {
        echo "<div class='error'>❌ Failed to create owner: " . $conn->error . "</div>";
    }
} else {
    echo "<div class='success'>✅ Owner account already exists</div>";
}

// Insert sample data
echo "<h2>Adding Sample Data...</h2>";

// Sample customers
$sample_customers = [
    ['Juan', 'Dela Cruz', 'juan@gmail.com', '09171234567', '123 Mabini St., Manila'],
    ['Maria', 'Santos', 'maria@gmail.com', '09181234567', '456 Rizal Ave., Quezon City'],
    ['Jose', 'Gonzales', 'jose@gmail.com', '09191234567', '789 Bonifacio St., Makati']
];

foreach ($sample_customers as $customer) {
    $check = $conn->query("SELECT * FROM customers WHERE email = '$customer[2]'");
    if ($check->num_rows == 0) {
        $sql = "INSERT INTO customers (first_name, last_name, email, phone, address) 
                VALUES ('$customer[0]', '$customer[1]', '$customer[2]', '$customer[3]', '$customer[4]')";
        if ($conn->query($sql)) {
            echo "<div class='success'>✅ Customer added: $customer[0] $customer[1]</div>";
        }
    }
}

// Sample products
$sample_products = [
    ['Engine Oil', 'Synthetic 5W-30', 'OIL-001', 'Oils', 850.00, 500.00, 50, 10, 'Shell'],
    ['Oil Filter', 'Compatible with most cars', 'FIL-001', 'Filters', 350.00, 200.00, 100, 20, 'Vic'],
    ['Brake Pads', 'Front brake pads', 'BRK-001', 'Brakes', 2500.00, 1500.00, 30, 5, 'Bendix'],
    ['Battery', 'NS40ZL Maintenance Free', 'BAT-001', 'Batteries', 4500.00, 3000.00, 20, 5, 'Motolite'],
    ['Spark Plugs', 'Iridium (Set of 4)', 'SPK-001', 'Ignition', 1200.00, 800.00, 40, 10, 'NGK']
];

foreach ($sample_products as $product) {
    $check = $conn->query("SELECT * FROM products WHERE sku = '$product[2]'");
    if ($check->num_rows == 0) {
        $sql = "INSERT INTO products (product_name, description, sku, category, unit_price, cost_price, stock_quantity, reorder_level, supplier) 
                VALUES ('$product[0]', '$product[1]', '$product[2]', '$product[3]', $product[4], $product[5], $product[6], $product[7], '$product[8]')";
        if ($conn->query($sql)) {
            echo "<div class='success'>✅ Product added: $product[0]</div>";
        }
    }
}

// Sample payment (for testing dashboard)
$payment_check = $conn->query("SELECT * FROM payments LIMIT 1");
if ($payment_check->num_rows == 0) {
    $sql = "INSERT INTO payments (amount_paid, payment_method) VALUES (15000.00, 'Cash')";
    $conn->query($sql);
    echo "<div class='success'>✅ Sample payment added</div>";
}

// Sample job order
$job_check = $conn->query("SELECT * FROM job_orders LIMIT 1");
if ($job_check->num_rows == 0) {
    // Get a customer ID
    $cust = $conn->query("SELECT customer_id FROM customers LIMIT 1")->fetch_assoc();
    if ($cust) {
        $sql = "INSERT INTO job_orders (customer_id, vehicle_id, job_description, status) 
                VALUES ({$cust['customer_id']}, 1, 'Oil Change and General Checkup', 'In Progress')";
        $conn->query($sql);
        echo "<div class='success'>✅ Sample job order added</div>";
    }
}

echo "<div class='success' style='margin-top: 20px;'><strong>✅ SETUP COMPLETE!</strong></div>";

echo "<div style='background: #e7f3ff; padding: 20px; border-radius: 5px; margin-top: 20px;'>";
echo "<h3>📋 Login Credentials</h3>";
echo "<p><strong>Email:</strong> owner@autobert.com<br>";
echo "<strong>Password:</strong> owner123</p>";
echo "</div>";

echo "<a href='index.php' class='btn'>Go to Login Page →</a>";

$conn->close();

echo "</div></body></html>";
?>
<?php
// seeder.php - Run this once to set up your system
require_once 'dbconnection.php';

echo "AutoBert System Seeder\n";
echo "=====================\n\n";

// Clear existing data (optional - comment out if you want to keep existing data)
// $conn->query("TRUNCATE TABLE employee");
// $conn->query("TRUNCATE TABLE settings");

// 1. Create the owner account
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
        echo "✓ Owner account created\n";
        echo "  Email: owner@autobert.com\n";
        echo "  Password: owner123\n\n";
    } else {
        echo "✗ Failed to create owner account: " . $stmt->error . "\n\n";
    }
    $stmt->close();
} else {
    echo "✓ Owner account already exists\n\n";
}

// 2. Set owner email in settings
$conn->query("INSERT INTO settings (setting_key, setting_value) 
              VALUES ('owner_email', '$owner_email')
              ON DUPLICATE KEY UPDATE setting_value = '$owner_email'");
echo "✓ Owner email configured in settings\n\n";

// 3. Create a sample business partner (pending approval)
$partner_email = 'partner@autobert.com';
$check_partner = $conn->query("SELECT employeeID FROM employee WHERE email = '$partner_email'");
if ($check_partner->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO employee (first_name, last_name, email, role, password, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
    $firstname = 'Sample';
    $lastname = 'Partner';
    $role = 'Business Partner';
    $is_approved = 0;
    $password = password_hash('partner123', PASSWORD_DEFAULT);
    
    $stmt->bind_param('sssssi', $firstname, $lastname, $partner_email, $role, $password, $is_approved);
    
    if ($stmt->execute()) {
        echo "✓ Sample business partner created (pending approval)\n";
        echo "  Email: partner@autobert.com\n";
        echo "  Password: partner123\n\n";
    }
    $stmt->close();
}

// 4. Create a sample employee (pending approval)
$employee_email = 'mechanic@autobert.com';
$check_employee = $conn->query("SELECT employeeID FROM employee WHERE email = '$employee_email'");
if ($check_employee->num_rows == 0) {
    $stmt = $conn->prepare("INSERT INTO employee (first_name, last_name, email, role, password, is_approved) VALUES (?, ?, ?, ?, ?, ?)");
    $firstname = 'Sample';
    $lastname = 'Mechanic';
    $role = 'Employee';
    $is_approved = 0;
    $password = password_hash('mechanic123', PASSWORD_DEFAULT);
    
    $stmt->bind_param('sssssi', $firstname, $lastname, $employee_email, $role, $password, $is_approved);
    
    if ($stmt->execute()) {
        echo "✓ Sample employee created (pending approval)\n";
        echo "  Email: mechanic@autobert.com\n";
        echo "  Password: mechanic123\n\n";
    }
    $stmt->close();
}

echo "=====================\n";
echo "Seeder completed!\n";
echo "\nNext steps:\n";
echo "1. Delete or secure this seeder file after running\n";
echo "2. Log in as owner (owner@autobert.com / owner123)\n";
echo "3. Go to admin_approvals.php to approve other users\n";
?>
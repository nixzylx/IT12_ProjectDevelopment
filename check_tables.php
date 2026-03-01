<?php
// check_tables.php - Check if all tables exist

require_once 'dbconnection.php';

echo "<h2>Database Tables Check</h2>";

$tables_to_check = ['employee', 'customers', 'vehicles', 'products', 'job_orders', 
                    'sales', 'sale_items', 'payments', 'credit_accounts', 'warranties', 
                    'stock_movements', 'settings'];

echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
echo "<tr><th>Table Name</th><th>Status</th></tr>";

foreach ($tables_to_check as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "<tr><td>$table</td><td style='color:green'>✅ Exists</td></tr>";
    } else {
        echo "<tr><td>$table</td><td style='color:red'>❌ Missing</td></tr>";
    }
}

echo "</table>";

echo "<br><a href='complete_setup.php'>Run Complete Setup</a>";
?>
<?php
session_start();
require_once 'dbconnection.php';

if (!isset($_SESSION['employeeID'])) {
    http_response_code(403);
    echo json_encode([]);
    exit();
}

$customer_id = intval($_GET['id'] ?? 0);
if (!$customer_id) {
    echo json_encode([]);
    exit();
}

$res = $conn->query("
    SELECT sales_id, final_amount, status,
           DATE_FORMAT(sales_date, '%b %d, %Y') AS sales_date
    FROM sales
    WHERE customer_id = $customer_id
    ORDER BY sales_date DESC
    LIMIT 8
");

$rows = [];
while ($res && $row = $res->fetch_assoc()) {
    $rows[] = $row;
}

header('Content-Type: application/json');
echo json_encode($rows);


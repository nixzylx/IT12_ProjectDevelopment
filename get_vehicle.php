<?php
session_start();
require_once 'dbconnection.php';

if (!isset($_SESSION['employeeID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$vehicle_id = intval($_GET['id'] ?? 0);

if ($vehicle_id) {
    $res = $conn->query("
        SELECT v.*, CONCAT(c.first_name, ' ', c.last_name) AS owner_name
        FROM vehicles v
        LEFT JOIN customers c ON v.customer_id = c.customer_id
        WHERE v.vehicle_id = $vehicle_id
    ");
    
    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode($row);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Vehicle not found']);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid vehicle ID']);
}
?>
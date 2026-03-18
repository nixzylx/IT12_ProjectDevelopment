<?php
session_start();
require_once 'dbconnection.php';

if (!isset($_SESSION['employeeID'])) {
    header("Location: index.php?error=Please log in first");
    exit();
}

$stmt = $conn->prepare("SELECT first_name, last_name, role, is_approved FROM employee WHERE employeeID = ?");
$stmt->bind_param("i", $_SESSION['employeeID']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['is_approved'] == 0) {
    session_destroy();
    header("Location: index.php?error=Access denied");
    exit();
}

$role = $user['role'];
$firstname = htmlspecialchars($user['first_name']);
$isOwner = strtolower($role) === 'owner' || strtolower($role) === 'business partner';
$userInitials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

$successMsg = '';
$errorMsg = '';

// Get all customers for dropdown
$customers = [];
$c_res = $conn->query("SELECT customer_id, first_name, last_name FROM customers ORDER BY first_name");
while ($c_res && $row = $c_res->fetch_assoc()) {
    $customers[] = $row;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new vehicle
    if (isset($_POST['action']) && $_POST['action'] === 'add_vehicle') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $plate_number = strtoupper($conn->real_escape_string(trim($_POST['plate_number'] ?? '')));
        $brand = ucwords($conn->real_escape_string(trim($_POST['brand'] ?? '')));
        $model = ucwords($conn->real_escape_string(trim($_POST['model'] ?? '')));
        $year_model = intval($_POST['year_model'] ?? 0);
        
        if ($customer_id && $plate_number && $brand && $model && $year_model) {
            // Check if plate number already exists
            $check = $conn->query("SELECT vehicle_id FROM vehicles WHERE plate_number = '$plate_number'");
            if ($check && $check->num_rows > 0) {
                $errorMsg = "Vehicle with plate number '$plate_number' already exists.";
            } else {
                $sql = "INSERT INTO vehicles (customer_id, plate_number, brand, model, year_model) 
                        VALUES (?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssi", $customer_id, $plate_number, $brand, $model, $year_model);
                
                if ($stmt->execute()) {
                    $successMsg = "Vehicle registered successfully!";
                } else {
                    $errorMsg = "Failed to register vehicle: " . $conn->error;
                }
                $stmt->close();
            }
        } else {
            $errorMsg = "Please fill in all required fields.";
        }
    }
    
    // Update vehicle
    if (isset($_POST['action']) && $_POST['action'] === 'update_vehicle') {
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        $plate_number = strtoupper($conn->real_escape_string(trim($_POST['plate_number'] ?? '')));
        $brand = ucwords($conn->real_escape_string(trim($_POST['brand'] ?? '')));
        $model = ucwords($conn->real_escape_string(trim($_POST['model'] ?? '')));
        $year_model = intval($_POST['year_model'] ?? 0);
        
        // Check if plate number already exists for another vehicle
        $check = $conn->query("SELECT vehicle_id FROM vehicles WHERE plate_number = '$plate_number' AND vehicle_id != $vehicle_id");
        if ($check && $check->num_rows > 0) {
            $errorMsg = "Vehicle with plate number '$plate_number' already exists.";
        } else {
            $sql = "UPDATE vehicles SET plate_number = ?, brand = ?, model = ?, year_model = ? WHERE vehicle_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssii", $plate_number, $brand, $model, $year_model, $vehicle_id);
            
            if ($stmt->execute()) {
                $successMsg = "Vehicle updated successfully!";
            } else {
                $errorMsg = "Failed to update vehicle: " . $conn->error;
            }
            $stmt->close();
        }
    }
    
    // Delete vehicle
    if (isset($_POST['action']) && $_POST['action'] === 'delete_vehicle') {
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        
        // Check if vehicle has any job orders
        $check = $conn->query("SELECT COUNT(*) as count FROM job_orders WHERE vehicle_id = $vehicle_id");
        if ($check && $row = $check->fetch_assoc()) {
            if ($row['count'] > 0) {
                $errorMsg = "Cannot delete vehicle because it has existing job orders.";
            } else {
                $conn->query("DELETE FROM vehicles WHERE vehicle_id = $vehicle_id");
                $successMsg = "Vehicle deleted successfully!";
            }
        }
    }
}

// Get filter parameters
$filter_customer = intval($_GET['customer'] ?? 0);
$filter_search = $_GET['search'] ?? '';

// Build WHERE clause
$where = ["1=1"];
if ($filter_customer > 0) {
    $where[] = "v.customer_id = $filter_customer";
}
if (!empty($filter_search)) {
    $search = $conn->real_escape_string($filter_search);
    $where[] = "(v.plate_number LIKE '%$search%' OR v.brand LIKE '%$search%' 
                OR v.model LIKE '%$search%' OR c.first_name LIKE '%$search%' 
                OR c.last_name LIKE '%$search%')";
}
$where_sql = implode(' AND ', $where);

// Get vehicles with customer info
$vehicles = [];
$v_res = $conn->query("
    SELECT v.*, 
           CONCAT(c.first_name, ' ', c.last_name) AS owner_name,
           c.contact_number,
           (SELECT COUNT(*) FROM job_orders WHERE vehicle_id = v.vehicle_id) AS job_count
    FROM vehicles v
    LEFT JOIN customers c ON v.customer_id = c.customer_id
    WHERE $where_sql
    ORDER BY v.vehicle_id DESC
");
while ($v_res && $row = $v_res->fetch_assoc()) {
    $vehicles[] = $row;
}

// Get single vehicle for detail view
$selected_vehicle = null;
if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    $v_res = $conn->query("
        SELECT v.*, 
               CONCAT(c.first_name, ' ', c.last_name) AS owner_name,
               c.contact_number, c.email, c.address,
               (SELECT COUNT(*) FROM job_orders WHERE vehicle_id = v.vehicle_id) AS total_jobs,
               (SELECT MAX(date_received) FROM job_orders WHERE vehicle_id = v.vehicle_id) AS last_visit
        FROM vehicles v
        LEFT JOIN customers c ON v.customer_id = c.customer_id
        WHERE v.vehicle_id = $view_id
    ");
    if ($v_res && $v_res->num_rows > 0) {
        $selected_vehicle = $v_res->fetch_assoc();
        
        // Get service history for this vehicle
        $history_res = $conn->query("
            SELECT jo.*, CONCAT(e.first_name, ' ', e.last_name) AS mechanic_name
            FROM job_orders jo
            LEFT JOIN employee e ON jo.assigned_mechanic = e.employeeID
            WHERE jo.vehicle_id = $view_id
            ORDER BY jo.date_received DESC
            LIMIT 10
        ");
        $selected_vehicle['service_history'] = [];
        while ($history_res && $row = $history_res->fetch_assoc()) {
            $selected_vehicle['service_history'][] = $row;
        }
    }
}

// Summary stats
$total_vehicles = count($vehicles);

// Get pending approvals count for sidebar
$pa_res = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved=0");
$pendingApprovals = ($pa_res && $r = $pa_res->fetch_assoc()) ? $r['cnt'] : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Management — AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --border: #e5e7eb;
            --muted: #6b7280;
            --card-radius: 12px;
        }

        .content { padding: 24px 28px; }
        
        /* Vehicle Grid - Simplified */
        .vehicle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .vehicle-card {
            background: #fff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .vehicle-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
            border-color: var(--accent);
        }
        
        .vehicle-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 16px;
            color: white;
        }
        
        .vehicle-header h3 {
            font-family: "Syne", sans-serif;
            font-size: 18px;
            margin: 0 0 4px 0;
        }
        
        .vehicle-plate {
            font-size: 14px;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .vehicle-info {
            padding: 16px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: var(--muted);
            font-size: 13px;
        }
        
        .info-value {
            font-weight: 600;
            font-size: 13px;
            color: var(--text);
        }
        
        .vehicle-footer {
            padding: 12px 16px;
            background: #f9fafb;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--muted);
        }
        
        .job-badge {
            background: var(--accent);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        
        /* Stats Card */
        .stats-card {
            background: #fff;
            border-radius: var(--card-radius);
            padding: 20px 18px;
            border: 1px solid var(--border);
            margin-bottom: 28px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            display: inline-block;
            min-width: 200px;
        }
        
        .stats-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 4px;
            opacity: 0.9;
        }
        
        .stats-value {
            font-family: "Syne", sans-serif;
            font-size: 32px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            background: #fff;
            padding: 16px 20px;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
        }
        
        .search-box {
            flex: 2;
            min-width: 250px;
            position: relative;
        }
        
        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }
        
        .search-box input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
        }
        
        .filter-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-group select {
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            min-width: 140px;
        }
        
        .btn-primary {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-primary:hover {
            background: var(--accent-hover);
        }
        
        .btn-outline {
            background: #fff;
            color: var(--text);
            border: 1px solid var(--border);
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
        }
        
        .btn-outline:hover {
            background: #f9fafb;
        }
        
        .btn-danger {
            background: #ef4444;
            color: #fff;
            border: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.open { display: flex; }
        
        .modal {
            background: #fff;
            border-radius: 16px;
            width: 500px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
        }
        
        .modal-header h2 {
            font-family: "Syne", sans-serif;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #888;
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 16px;
        }
        
        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #444;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        
        .form-group input,
        .form-group select {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        /* Detail View */
        .detail-card {
            background: #fff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            padding: 24px;
        }
        
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--border);
        }
        
        .detail-title {
            font-family: "Syne", sans-serif;
            font-size: 24px;
            color: var(--text);
        }
        
        .detail-subtitle {
            color: var(--accent);
            font-size: 16px;
            font-weight: 600;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .detail-item {
            padding: 12px;
            background: #f9fafb;
            border-radius: 8px;
        }
        
        .detail-item .label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .detail-item .value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .history-table th {
            text-align: left;
            padding: 12px;
            background: #f9fafb;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }
        
        .history-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }
        
        .history-table tr:hover {
            background: #f9fafb;
            cursor: pointer;
        }
        
        .page-alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .page-alert.success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .page-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            background: #fff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
        }
        
        .empty-state i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 16px;
        }
        
        .empty-state p {
            color: #666;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .vehicle-grid {
                grid-template-columns: 1fr;
            }
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo">
            <a href="admin_dashboard.php" class="logo-container">
                <div class="logo-mark">
                    <img src="AB logo.png" alt="AutoBert Logo" class="logo-img">
                </div>
                <div class="logo-text-wrapper">
                    <div class="logo-name">AutoBert</div>
                    <div class="logo-sub">Repair Shop &amp; Batteries</div>
                </div>
            </a>
        </div>

        <nav class="nav-section">
            <div class="nav-label">Main</div>
            <a class="nav-item" href="admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-item" href="new_job_order.php"><i class="bi bi-clipboard-data"></i> Job Orders</a>
            <a class="nav-item" href="sales.php"><i class="bi bi-currency-dollar"></i> Sales</a>
            <a class="nav-item" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a>
            <a class="nav-item" href="products.php"><i class="bi bi-box-seam"></i> Products</a>
        </nav>

        <nav class="nav-section">
            <div class="nav-label">Management</div>
            <a class="nav-item" href="customers.php"><i class="bi bi-people"></i> Customers</a>
            <a class="nav-item active" href="vehicles.php"><i class="bi bi-truck"></i> Vehicles</a>
            <?php if ($isOwner): ?>
                <a class="nav-item" href="employees.php"><i class="bi bi-person-badge"></i> Employees</a>
                <a class="nav-item" href="admin_approvals.php"><i class="bi bi-check-circle"></i> Approvals</a>
            <?php endif; ?>
            <a class="nav-item" href="warranties.php"><i class="bi bi-shield-check"></i> Warranties</a>
            <a class="nav-item" href="credit_accounts.php"><i class="bi bi-wallet2"></i> Credit Accounts</a>
        </nav>

        <?php if ($isOwner): ?>
        <nav class="nav-section">
            <div class="nav-label">Owner</div>
            <a class="nav-item" href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
        </nav>
        <?php endif; ?>

        <div class="sidebar-footer">
            <div class="user-row">
                <div class="avatar"><?= $userInitials ?></div>
                <div>
                    <div class="user-name"><?= $firstname ?></div>
                    <div class="user-role"><?= htmlspecialchars($role) ?></div>
                </div>
            </div>
            <div style="margin-top:10px; text-align:center;">
                <a href="logout.php" style="color:var(--sidebar-text); text-decoration:none; font-size:12px;">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <span class="page-title">Vehicle Management</span>
                <span class="breadcrumb">Register & manage customer vehicles</span>
            </div>
            <div class="topbar-right">
                <button class="btn-primary" onclick="openModal('addVehicleModal')">
                    <i class="bi bi-plus-lg"></i> Register Vehicle
                </button>
                <button class="logout-btn" onclick="window.location.href='logout.php'">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </header>

        <div class="content">
            <?php if ($successMsg): ?>
                <div class="page-alert success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($successMsg) ?></div>
            <?php elseif ($errorMsg): ?>
                <div class="page-alert error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <?php if ($selected_vehicle): ?>
                <!-- Vehicle Detail View -->
                <div style="margin-bottom: 20px;">
                    <button class="btn-outline" onclick="window.location.href='vehicles.php'">
                        <i class="bi bi-arrow-left"></i> Back to Vehicles
                    </button>
                </div>
                
                <div class="detail-card">
                    <div class="detail-header">
                        <div>
                            <div class="detail-title"><?= htmlspecialchars($selected_vehicle['brand'] . ' ' . $selected_vehicle['model']) ?></div>
                            <div class="detail-subtitle"><?= htmlspecialchars($selected_vehicle['plate_number']) ?></div>
                        </div>
                        <div>
                            <button class="btn-outline" onclick="editVehicle(<?= $selected_vehicle['vehicle_id'] ?>)">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </div>
                    </div>
                    
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="label">Owner</div>
                            <div class="value"><?= htmlspecialchars($selected_vehicle['owner_name']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Contact Number</div>
                            <div class="value"><?= htmlspecialchars($selected_vehicle['contact_number'] ?? 'N/A') ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Brand</div>
                            <div class="value"><?= htmlspecialchars($selected_vehicle['brand']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Model</div>
                            <div class="value"><?= htmlspecialchars($selected_vehicle['model']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Year</div>
                            <div class="value"><?= $selected_vehicle['year_model'] ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="label">Total Jobs</div>
                            <div class="value"><?= $selected_vehicle['total_jobs'] ?? 0 ?></div>
                        </div>
                        <?php if ($selected_vehicle['last_visit']): ?>
                        <div class="detail-item">
                            <div class="label">Last Visit</div>
                            <div class="value"><?= date('M d, Y', strtotime($selected_vehicle['last_visit'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Service History -->
                    <h3 style="font-family: 'Syne', sans-serif; margin: 24px 0 16px;">Service History</h3>
                    <?php if (empty($selected_vehicle['service_history'])): ?>
                        <div class="empty-state" style="padding: 40px;">
                            <i class="bi bi-clock-history"></i>
                            <p>No service history found for this vehicle</p>
                        </div>
                    <?php else: ?>
                        <table class="history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Job #</th>
                                    <th>Service Description</th>
                                    <th>Mechanic</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($selected_vehicle['service_history'] as $job): ?>
                                <tr onclick="window.location.href='job_orders.php?view=<?= $job['job_order_id'] ?>'">
                                    <td><?= date('M d, Y', strtotime($job['date_received'])) ?></td>
                                    <td>#<?= str_pad($job['job_order_id'], 5, '0', STR_PAD_LEFT) ?></td>
                                    <td><?= htmlspecialchars(substr($job['job_description'], 0, 40)) ?>...</td>
                                    <td><?= htmlspecialchars($job['mechanic_name'] ?? 'N/A') ?></td>
                                    <td><span class="status-badge status-<?= strtolower($job['status']) ?>"><?= $job['status'] ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
            <?php else: ?>
                <!-- Stats Summary -->
                <div class="stats-card">
                    <div class="stats-label">Total Registered Vehicles</div>
                    <div class="stats-value"><?= $total_vehicles ?></div>
                </div>

                <!-- Filter Toolbar -->
                <div class="toolbar">
                    <form method="GET" style="display: flex; gap: 12px; width: 100%; flex-wrap: wrap;">
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" placeholder="Search by plate number, brand, model, or owner..." 
                                   value="<?= htmlspecialchars($filter_search) ?>">
                        </div>
                        <div class="filter-group">
                            <select name="customer">
                                <option value="0">All Customers</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>" <?= $filter_customer == $c['customer_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                            <?php if (!empty($filter_search) || $filter_customer > 0): ?>
                                <a href="vehicles.php" class="btn-outline">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Vehicle Grid -->
                <div class="vehicle-grid">
                    <?php if (empty($vehicles)): ?>
                        <div style="grid-column: 1/-1;">
                            <div class="empty-state">
                                <i class="bi bi-truck"></i>
                                <p>No vehicles found</p>
                                <button class="btn-primary" onclick="openModal('addVehicleModal')">
                                    Register First Vehicle
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <div class="vehicle-card" onclick="window.location.href='vehicles.php?view=<?= $vehicle['vehicle_id'] ?>'">
                                <div class="vehicle-header">
                                    <h3><?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?></h3>
                                    <div class="vehicle-plate">
                                        <i class="bi bi-upc-scan"></i> <?= htmlspecialchars($vehicle['plate_number']) ?>
                                    </div>
                                </div>
                                
                                <div class="vehicle-info">
                                    <div class="info-row">
                                        <span class="info-label">Owner</span>
                                        <span class="info-value"><?= htmlspecialchars($vehicle['owner_name'] ?? 'Unknown') ?></span>
                                    </div>
                                    <div class="info-row">
                                        <span class="info-label">Year</span>
                                        <span class="info-value"><?= $vehicle['year_model'] ?></span>
                                    </div>
                                    <?php if (!empty($vehicle['contact_number'])): ?>
                                    <div class="info-row">
                                        <span class="info-label">Contact</span>
                                        <span class="info-value"><?= htmlspecialchars($vehicle['contact_number']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="vehicle-footer">
                                    <span><i class="bi bi-wrench"></i> <?= $vehicle['job_count'] ?> job(s)</span>
                                    <?php if ($vehicle['job_count'] > 0): ?>
                                        <span class="job-badge">Active</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Add Vehicle Modal -->
    <div class="modal-overlay" id="addVehicleModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-truck" style="color:var(--accent);"></i> Register New Vehicle</h2>
                <button class="modal-close" onclick="closeModal('addVehicleModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_vehicle">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Owner *</label>
                        <select name="customer_id" required>
                            <option value="">Select Customer</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Plate Number *</label>
                        <input type="text" name="plate_number" required placeholder="ABC-1234" 
                               value="<?= htmlspecialchars($_POST['plate_number'] ?? '') ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Brand *</label>
                            <input type="text" name="brand" required placeholder="e.g., Toyota"
                                   value="<?= htmlspecialchars($_POST['brand'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Model *</label>
                            <input type="text" name="model" required placeholder="e.g., Vios"
                                   value="<?= htmlspecialchars($_POST['model'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Year *</label>
                        <input type="number" name="year_model" required min="1900" max="<?= date('Y')+1 ?>" 
                               placeholder="2024" value="<?= htmlspecialchars($_POST['year_model'] ?? date('Y')) ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('addVehicleModal')">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Register Vehicle</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Vehicle Modal -->
    <div class="modal-overlay" id="editVehicleModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-pencil-square" style="color:var(--accent);"></i> Edit Vehicle</h2>
                <button class="modal-close" onclick="closeModal('editVehicleModal')">&times;</button>
            </div>
            <form method="POST" id="editVehicleForm">
                <input type="hidden" name="action" value="update_vehicle">
                <input type="hidden" name="vehicle_id" id="edit_vehicle_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Owner</label>
                        <select name="customer_id" id="edit_customer_id" disabled>
                            <!-- Populated via JavaScript -->
                        </select>
                        <small style="color: var(--muted);">Owner cannot be changed. Create a new vehicle if needed.</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Plate Number *</label>
                        <input type="text" name="plate_number" id="edit_plate_number" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Brand *</label>
                            <input type="text" name="brand" id="edit_brand" required>
                        </div>
                        <div class="form-group">
                            <label>Model *</label>
                            <input type="text" name="model" id="edit_model" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Year *</label>
                        <input type="number" name="year_model" id="edit_year_model" required min="1900" max="<?= date('Y')+1 ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('editVehicleModal')">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="bi bi-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteVehicleModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-exclamation-triangle" style="color: #ef4444;"></i> Delete Vehicle</h2>
                <button class="modal-close" onclick="closeModal('deleteVehicleModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_vehicle">
                <input type="hidden" name="vehicle_id" id="delete_vehicle_id">
                <div class="modal-body">
                    <p style="margin-bottom: 16px;">Are you sure you want to delete this vehicle?</p>
                    <p style="color: #ef4444; font-size: 13px;"><i class="bi bi-exclamation-circle"></i> This action cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('deleteVehicleModal')">Cancel</button>
                    <button type="submit" class="btn-danger"><i class="bi bi-trash"></i> Delete Vehicle</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) m.classList.remove('open');
            });
        });

        function editVehicle(vehicleId) {
            // Fetch vehicle data via AJAX
            fetch(`get_vehicle.php?id=${vehicleId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('edit_vehicle_id').value = data.vehicle_id;
                    
                    // Create owner option
                    const ownerSelect = document.getElementById('edit_customer_id');
                    ownerSelect.innerHTML = `<option value="${data.customer_id}">${data.owner_name}</option>`;
                    
                    document.getElementById('edit_plate_number').value = data.plate_number;
                    document.getElementById('edit_brand').value = data.brand;
                    document.getElementById('edit_model').value = data.model;
                    document.getElementById('edit_year_model').value = data.year_model;
                    
                    openModal('editVehicleModal');
                })
                .catch(error => {
                    alert('Error loading vehicle data');
                });
        }

        function deleteVehicle(vehicleId) {
            document.getElementById('delete_vehicle_id').value = vehicleId;
            openModal('deleteVehicleModal');
        }
    </script>
</body>
</html>
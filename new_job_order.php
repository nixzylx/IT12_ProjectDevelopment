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

// Get customers for dropdown
$customers = [];
$c_res = $conn->query("SELECT customer_id, first_name, last_name, contact_number FROM customers ORDER BY first_name");
while ($c_res && $row = $c_res->fetch_assoc()) {
    $customers[] = $row;
}

// Get vehicles for dropdown (will be filtered by customer via AJAX)
$vehicles = [];
$v_res = $conn->query("SELECT v.*, CONCAT(c.first_name, ' ', c.last_name) AS owner_name 
                       FROM vehicles v 
                       JOIN customers c ON v.customer_id = c.customer_id 
                       ORDER BY v.brand, v.model");
while ($v_res && $row = $v_res->fetch_assoc()) {
    $vehicles[] = $row;
}

// Get mechanics (employees with role 'Employee' or 'Mechanic')
$mechanics = [];
$m_res = $conn->query("SELECT employeeID, first_name, last_name FROM employee 
                       WHERE role IN ('Employee', 'Mechanic') AND is_approved = 1 
                       ORDER BY first_name");
while ($m_res && $row = $m_res->fetch_assoc()) {
    $mechanics[] = $row;
}

// Service types offered
$service_types = [
    'Mechanical Job',
    'Auto Electrical Job',
    'Alternator and Starter Repair',
    'Body Alignment and Painting',
    'Calibration',
    'Battery Charging',
    'Radiator Overhaul',
    'Change Oil',
    'Welding Job',
    'OBD II Scanning',
    'General Checkup',
    'Engine Repair',
    'Transmission Repair',
    'Aircon Service',
    'Suspension Repair',
    'Brake System Repair',
    'Tire Service',
    'Diagnostic'
];

// Process new job order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'create_job_order') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $vehicle_id = intval($_POST['vehicle_id'] ?? 0);
        $assigned_mechanic = intval($_POST['assigned_mechanic'] ?? 0);
        $job_description = $conn->real_escape_string($_POST['job_description'] ?? '');
        $service_type = $conn->real_escape_string($_POST['service_type'] ?? '');
        $labor_fee = floatval($_POST['labor_fee'] ?? 0);
        $estimated_cost = floatval($_POST['estimated_cost'] ?? 0);
        $notes = $conn->real_escape_string($_POST['notes'] ?? '');
        $priority = $conn->real_escape_string($_POST['priority'] ?? 'Normal');
        
        // Get odometer reading if provided
        $odometer_reading = intval($_POST['odometer_reading'] ?? 0);
        $fuel_level = $conn->real_escape_string($_POST['fuel_level'] ?? '');
        
        // Customer complaints
        $customer_complaint = $conn->real_escape_string($_POST['customer_complaint'] ?? '');
        
        if ($customer_id && $vehicle_id && $assigned_mechanic && !empty($job_description)) {
            
            $sql = "INSERT INTO job_orders (
                customer_id, vehicle_id, assigned_mechanic, job_description, service_type, 
                labor_fee, estimated_cost, notes, priority, odometer_reading, fuel_level, 
                customer_complaint, status, date_received
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiissddsisss", 
                $customer_id, $vehicle_id, $assigned_mechanic, $job_description, $service_type,
                $labor_fee, $estimated_cost, $notes, $priority, $odometer_reading, $fuel_level,
                $customer_complaint
            );
            
            if ($stmt->execute()) {
                $job_order_id = $conn->insert_id;
                $successMsg = "Job Order #" . str_pad($job_order_id, 5, '0', STR_PAD_LEFT) . " created successfully!";
            } else {
                $errorMsg = "Failed to create job order: " . $conn->error;
            }
            $stmt->close();
        } else {
            $errorMsg = "Please fill in all required fields.";
        }
    }
    
    // Update job status
    if ($_POST['action'] === 'update_status') {
        $job_order_id = intval($_POST['job_order_id'] ?? 0);
        $new_status = $conn->real_escape_string($_POST['status'] ?? '');
        $repair_notes = $conn->real_escape_string($_POST['repair_notes'] ?? '');
        $parts_used = $conn->real_escape_string($_POST['parts_used'] ?? '');
        $actual_cost = floatval($_POST['actual_cost'] ?? 0);
        
        if ($job_order_id && $new_status) {
            
            $update_sql = "UPDATE job_orders SET status = '$new_status'";
            
            // If completed, set date_completed
            if ($new_status === 'Completed') {
                $update_sql = "UPDATE job_orders SET status = '$new_status', 
                               date_completed = NOW(), actual_cost = '$actual_cost',
                               repair_notes = CONCAT(IFNULL(repair_notes, ''), '\\n', '$repair_notes'),
                               parts_used = CONCAT(IFNULL(parts_used, ''), '\\n', '$parts_used')
                               WHERE job_order_id = $job_order_id";
            } elseif ($new_status === 'Ongoing') {
                $update_sql = "UPDATE job_orders SET status = '$new_status',
                               repair_notes = CONCAT(IFNULL(repair_notes, ''), '\\nStarted: ', NOW(), ' - ', '$repair_notes')
                               WHERE job_order_id = $job_order_id";
            } else {
                $update_sql .= " WHERE job_order_id = $job_order_id";
            }
            
            if ($conn->query($update_sql)) {
                $successMsg = "Job Order #" . str_pad($job_order_id, 5, '0', STR_PAD_LEFT) . " status updated to $new_status.";
            } else {
                $errorMsg = "Failed to update status: " . $conn->error;
            }
        }
    }
    
    // Add repair detail
    if ($_POST['action'] === 'add_repair_detail') {
        $job_order_id = intval($_POST['job_order_id'] ?? 0);
        $detail_description = $conn->real_escape_string($_POST['detail_description'] ?? '');
        $detail_type = $conn->real_escape_string($_POST['detail_type'] ?? 'Repair');
        $detail_notes = $conn->real_escape_string($_POST['detail_notes'] ?? '');
        
        if ($job_order_id && !empty($detail_description)) {
            
            // You might want to create a repair_details table for this
            // For now, we'll append to repair_notes
            $detail_entry = "\n[" . date('Y-m-d H:i') . "] $detail_type: $detail_description";
            if (!empty($detail_notes)) {
                $detail_entry .= " - Notes: $detail_notes";
            }
            
            $conn->query("UPDATE job_orders SET repair_notes = CONCAT(IFNULL(repair_notes, ''), '$detail_entry') 
                         WHERE job_order_id = $job_order_id");
            
            $successMsg = "Repair detail added successfully.";
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'all';
$filter_search = $_GET['search'] ?? '';
$filter_customer = intval($_GET['customer'] ?? 0);
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');

// Build WHERE clause
$where = ["1=1"];
if ($filter_status !== 'all') {
    $where[] = "jo.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if (!empty($filter_search)) {
    $search = $conn->real_escape_string($filter_search);
    $where[] = "(c.first_name LIKE '%$search%' OR c.last_name LIKE '%$search%' 
                OR v.plate_number LIKE '%$search%' OR jo.job_description LIKE '%$search%')";
}
if ($filter_customer > 0) {
    $where[] = "jo.customer_id = $filter_customer";
}
if ($filter_date_from && $filter_date_to) {
    $where[] = "DATE(jo.date_received) BETWEEN '$filter_date_from' AND '$filter_date_to'";
}
$where_sql = implode(' AND ', $where);

// Get job orders
$job_orders = [];
$jo_res = $conn->query("
    SELECT jo.*, 
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           c.contact_number,
           CONCAT(v.brand, ' ', v.model, ' (', v.plate_number, ')') AS vehicle_info,
           CONCAT(e.first_name, ' ', e.last_name) AS mechanic_name
    FROM job_orders jo
    LEFT JOIN customers c ON jo.customer_id = c.customer_id
    LEFT JOIN vehicles v ON jo.vehicle_id = v.vehicle_id
    LEFT JOIN employee e ON jo.assigned_mechanic = e.employeeID
    WHERE $where_sql
    ORDER BY 
        CASE jo.priority 
            WHEN 'Emergency' THEN 1
            WHEN 'High' THEN 2
            WHEN 'Normal' THEN 3
            WHEN 'Low' THEN 4
            ELSE 5
        END,
        jo.date_received DESC
    LIMIT 100
");
while ($jo_res && $row = $jo_res->fetch_assoc()) {
    $job_orders[] = $row;
}

// Summary stats
$stats = [
    'pending' => 0,
    'ongoing' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'total' => 0
];

$s_res = $conn->query("SELECT status, COUNT(*) as count FROM job_orders GROUP BY status");
while ($s_res && $row = $s_res->fetch_assoc()) {
    $status_key = strtolower(str_replace(' ', '_', $row['status']));
    $stats[$status_key] = $row['count'];
    $stats['total'] += $row['count'];
}

// Get pending approvals count for sidebar
$pa_res = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved=0");
$pendingApprovals = ($pa_res && $r = $pa_res->fetch_assoc()) ? $r['cnt'] : 0;
$activeJobs = $stats['pending'] + $stats['ongoing'];

// Get single job for detail view if ID is provided
$selected_job = null;
if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    $v_res = $conn->query("
        SELECT jo.*, 
               CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
               c.contact_number, c.email, c.address,
               v.*,
               CONCAT(e.first_name, ' ', e.last_name) AS mechanic_name,
               e.role AS mechanic_role
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.customer_id
        LEFT JOIN vehicles v ON jo.vehicle_id = v.vehicle_id
        LEFT JOIN employee e ON jo.assigned_mechanic = e.employeeID
        WHERE jo.job_order_id = $view_id
    ");
    if ($v_res && $v_res->num_rows > 0) {
        $selected_job = $v_res->fetch_assoc();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Orders — AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .content { padding: 24px 28px; }
        
        .job-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: var(--card-radius);
            padding: 20px 18px;
            border: 1px solid var(--border);
        }
        
        .stat-card.pending { border-left: 4px solid #f97316; }
        .stat-card.ongoing { border-left: 4px solid #3b82f6; }
        .stat-card.completed { border-left: 4px solid #10b981; }
        .stat-card.cancelled { border-left: 4px solid #ef4444; }
        .stat-card.total { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; }
        
        .stat-label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-family: "Syne", sans-serif;
            font-size: 26px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .stat-card.total .stat-label,
        .stat-card.total .stat-value { color: #fff; }
        
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
        
        .filter-group select,
        .filter-group input[type=date] {
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
        
        .btn-success {
            background: #10b981;
            color: #fff;
        }
        
        .btn-warning {
            background: #f97316;
            color: #fff;
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
        
        .job-table {
            background: #fff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .job-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .job-table thead th {
            background: #f9fafb;
            padding: 14px 16px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
            border-bottom: 1px solid var(--border);
        }
        
        .job-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background .15s;
            cursor: pointer;
        }
        
        .job-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .job-table td {
            padding: 14px 16px;
            font-size: 13px;
        }
        
        .job-id {
            font-family: "Syne", sans-serif;
            font-weight: 700;
            color: var(--accent);
        }
        
        .priority-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .priority-emergency { background: #fee2e2; color: #991b1b; }
        .priority-high { background: #ffedd5; color: #9a3412; }
        .priority-normal { background: #e0f2fe; color: #0369a1; }
        .priority-low { background: #f3e8ff; color: #6b21a8; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-ongoing { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
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
            width: 700px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }
        
        .modal.large { width: 900px; }
        
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
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .form-row.three-col {
            grid-template-columns: 1fr 1fr 1fr;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: #444;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .detail-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 14px;
            font-weight: 500;
        }
        
        .info-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid var(--border);
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item:before {
            content: '';
            position: absolute;
            left: -22px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--accent);
        }
        
        .timeline-item:after {
            content: '';
            position: absolute;
            left: -17px;
            top: 12px;
            width: 2px;
            height: calc(100% - 12px);
            background: #e2e8f0;
        }
        
        .timeline-item:last-child:after { display: none; }
        
        .timeline-date {
            font-size: 11px;
            color: var(--muted);
        }
        
        .timeline-title {
            font-weight: 600;
            font-size: 13px;
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
        
        .tabs {
            display: flex;
            gap: 2px;
            background: #f3f4f6;
            padding: 4px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .tab {
            flex: 1;
            padding: 10px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s;
        }
        
        .tab.active {
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,.05);
        }
        
        @media (max-width: 1024px) {
            .job-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            .form-row {
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
            <a class="nav-item active" href="new_job_order.php"><i class="bi bi-clipboard-data"></i> Job Orders</a>
            <a class="nav-item" href="sales.php"><i class="bi bi-currency-dollar"></i> Sales</a>
            <a class="nav-item" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a>
            <a class="nav-item" href="products.php"><i class="bi bi-box-seam"></i> Products</a>
        </nav>

        <nav class="nav-section">
            <div class="nav-label">Management</div>
            <a class="nav-item" href="customers.php"><i class="bi bi-people"></i> Customers</a>
            <a class="nav-item" href="vehicles.php"><i class="bi bi-truck"></i> Vehicles</a>
            <?php if ($isOwner): ?>
                <a class="nav-item" href="employees.php"><i class="bi bi-person-badge"></i> Employees</a>
                <a class="nav-item" href="admin_approvals.php"><i class="bi bi-check-circle"></i> Approvals</a>
            <?php endif; ?>
            <a class="nav-item" href="warranties.php"><i class="bi bi-shield-check"></i> Warranties</a>
            <a class="nav-item" href="credit_accounts.php"><i class="bi bi-wallet2"></i> Credit Accounts</a>
        </nav>

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
                <span class="page-title">Job Orders</span>
                <span class="breadcrumb">Manage repair jobs</span>
            </div>
            <div class="topbar-right">
                <button class="btn-primary" onclick="openModal('newJobModal')">
                    <i class="bi bi-plus-lg"></i> New Job Order
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

            <!-- Stats Cards -->
            <div class="job-stats">
                <div class="stat-card pending">
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?= $stats['pending'] ?></div>
                </div>
                <div class="stat-card ongoing">
                    <div class="stat-label">Ongoing</div>
                    <div class="stat-value"><?= $stats['ongoing'] ?></div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?= $stats['completed'] ?></div>
                </div>
                <div class="stat-card cancelled">
                    <div class="stat-label">Cancelled</div>
                    <div class="stat-value"><?= $stats['cancelled'] ?></div>
                </div>
                <div class="stat-card total">
                    <div class="stat-label">Total Jobs</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                </div>
            </div>

            <!-- Filter Toolbar -->
            <div class="toolbar">
                <form method="GET" style="display: flex; gap: 12px; width: 100%; flex-wrap: wrap;">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" placeholder="Search customer, plate, description..." 
                               value="<?= htmlspecialchars($filter_search) ?>">
                    </div>
                    <div class="filter-group">
                        <select name="status">
                            <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="Pending" <?= $filter_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Ongoing" <?= $filter_status === 'Ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="Completed" <?= $filter_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Cancelled" <?= $filter_status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                        <input type="date" name="date_from" value="<?= $filter_date_from ?>">
                        <span>to</span>
                        <input type="date" name="date_to" value="<?= $filter_date_to ?>">
                        <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                        <?php if ($filter_status !== 'all' || !empty($filter_search) || $filter_customer > 0): ?>
                            <a href="new_job_order.php" class="btn-outline">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Job Orders Table -->
            <div class="job-table">
                <table>
                    <thead>
                        <tr>
                            <th>Job #</th>
                            <th>Customer / Vehicle</th>
                            <th>Service</th>
                            <th>Mechanic</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date Received</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($job_orders)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 48px;">
                                    <i class="bi bi-clipboard-x" style="font-size: 48px; color: #ccc;"></i>
                                    <p style="margin-top: 16px; color: #666;">No job orders found</p>
                                    <button class="btn-primary" onclick="openModal('newJobModal')">Create First Job Order</button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($job_orders as $job): 
                                $priority_class = match(strtolower($job['priority'] ?? 'Normal')) {
                                    'emergency' => 'priority-emergency',
                                    'high' => 'priority-high',
                                    'normal' => 'priority-normal',
                                    'low' => 'priority-low',
                                    default => 'priority-normal'
                                };
                                $status_class = 'status-' . strtolower($job['status']);
                            ?>
                                <tr onclick="viewJobDetails(<?= $job['job_order_id'] ?>)">
                                    <td><span class="job-id">#<?= str_pad($job['job_order_id'], 5, '0', STR_PAD_LEFT) ?></span></td>
                                    <td>
                                        <div><?= htmlspecialchars($job['customer_name'] ?? '—') ?></div>
                                        <div style="font-size: 11px; color: #666;"><?= htmlspecialchars($job['vehicle_info'] ?? '') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(substr($job['job_description'], 0, 30)) ?>...</td>
                                    <td><?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?></td>
                                    <td><span class="priority-badge <?= $priority_class ?>"><?= $job['priority'] ?? 'Normal' ?></span></td>
                                    <td><span class="status-badge <?= $status_class ?>"><?= $job['status'] ?></span></td>
                                    <td><?= date('M d, Y', strtotime($job['date_received'])) ?></td>
                                    <td onclick="event.stopPropagation()">
                                        <?php if ($job['status'] === 'Pending'): ?>
                                            <button class="btn-primary" style="padding: 5px 10px;" onclick="updateStatus(<?= $job['job_order_id'] ?>, 'Ongoing')">
                                                <i class="bi bi-play"></i> Start
                                            </button>
                                        <?php elseif ($job['status'] === 'Ongoing'): ?>
                                            <button class="btn-success" style="padding: 5px 10px;" onclick="completeJob(<?= $job['job_order_id'] ?>)">
                                                <i class="bi bi-check"></i> Complete
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- New Job Order Modal -->
    <div class="modal-overlay" id="newJobModal">
        <div class="modal large">
            <div class="modal-header">
                <h2><i class="bi bi-clipboard-plus" style="color:var(--accent);"></i> Create New Job Order</h2>
                <button class="modal-close" onclick="closeModal('newJobModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_job_order">
                <div class="modal-body">
                    <!-- Customer & Vehicle Selection -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Customer *</label>
                            <select name="customer_id" id="customer_select" required onchange="loadCustomerVehicles()">
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Vehicle *</label>
                            <select name="vehicle_id" id="vehicle_select" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v['vehicle_id'] ?>" data-customer="<?= $v['customer_id'] ?>">
                                        <?= htmlspecialchars($v['brand'] . ' ' . $v['model'] . ' - ' . $v['plate_number']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Job Details -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Service Type</label>
                            <select name="service_type">
                                <option value="">Select Service</option>
                                <?php foreach ($service_types as $service): ?>
                                    <option value="<?= $service ?>"><?= $service ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority">
                                <option value="Normal">Normal</option>
                                <option value="High">High</option>
                                <option value="Emergency">Emergency</option>
                                <option value="Low">Low</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Job Description *</label>
                        <textarea name="job_description" rows="3" required placeholder="Describe the work needed..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Assigned Mechanic *</label>
                            <select name="assigned_mechanic" required>
                                <option value="">Select Mechanic</option>
                                <?php foreach ($mechanics as $m): ?>
                                    <option value="<?= $m['employeeID'] ?>"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Labor Fee (₱)</label>
                            <input type="number" name="labor_fee" step="0.01" min="0" value="0">
                        </div>
                    </div>

                    <div class="form-row three-col">
                        <div class="form-group">
                            <label>Estimated Cost (₱)</label>
                            <input type="number" name="estimated_cost" step="0.01" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Odometer Reading</label>
                            <input type="number" name="odometer_reading" min="0" placeholder="km">
                        </div>
                        <div class="form-group">
                            <label>Fuel Level</label>
                            <select name="fuel_level">
                                <option value="">Select</option>
                                <option value="Full">Full</option>
                                <option value="3/4">3/4</option>
                                <option value="1/2">1/2</option>
                                <option value="1/4">1/4</option>
                                <option value="Empty">Empty</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Customer Complaint</label>
                        <textarea name="customer_complaint" rows="2" placeholder="What did the customer report?"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Additional Notes</label>
                        <textarea name="notes" rows="2" placeholder="Any special instructions..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('newJobModal')">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Create Job Order</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Status Modal (for Ongoing → Completed) -->
    <div class="modal-overlay" id="completeJobModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-check-circle" style="color:#10b981;"></i> Complete Job Order</h2>
                <button class="modal-close" onclick="closeModal('completeJobModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="job_order_id" id="complete_job_id">
                <input type="hidden" name="status" value="Completed">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Repair Notes / Work Done</label>
                        <textarea name="repair_notes" rows="3" required placeholder="Describe the repairs performed..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Parts Used</label>
                        <textarea name="parts_used" rows="2" placeholder="List parts replaced or used..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Actual Cost (₱)</label>
                        <input type="number" name="actual_cost" step="0.01" min="0" value="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" onclick="closeModal('completeJobModal')">Cancel</button>
                    <button type="submit" class="btn-success"><i class="bi bi-check-lg"></i> Complete Job</button>
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

        // Filter vehicles by customer
        function loadCustomerVehicles() {
            const customerId = document.getElementById('customer_select').value;
            const vehicleSelect = document.getElementById('vehicle_select');
            const options = vehicleSelect.querySelectorAll('option');
            
            options.forEach(opt => {
                if (opt.value === '') return;
                if (opt.dataset.customer == customerId || customerId === '') {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });
            
            // Reset selection
            vehicleSelect.value = '';
        }

        // Update job status
        function updateStatus(jobId, newStatus) {
            if (confirm('Change job status to ' + newStatus + '?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="job_order_id" value="${jobId}">
                    <input type="hidden" name="status" value="${newStatus}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Complete job modal
        function completeJob(jobId) {
            document.getElementById('complete_job_id').value = jobId;
            openModal('completeJobModal');
        }

        // View job details
        function viewJobDetails(jobId) {
            window.location.href = 'new_job_order.php?view=' + jobId;
        }

        // Initialize vehicle filtering on page load
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('view')) {
                // Could show detail modal here
            }
        };
    </script>
</body>
</html>
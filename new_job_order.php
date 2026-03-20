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

$role          = $user['role'];
$firstname     = htmlspecialchars($user['first_name']);
$isOwner       = strtolower($role) === 'owner' || strtolower($role) === 'business partner';
$userInitials  = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

$successMsg = '';
$errorMsg   = '';

// Get customers for dropdown
$customers = [];
$c_res = $conn->query("SELECT customer_id, first_name, last_name, contact_number FROM customers ORDER BY first_name");
while ($c_res && $row = $c_res->fetch_assoc()) {
    $customers[] = $row;
}

// Get vehicles for dropdown
$vehicles = [];
$v_res = $conn->query("SELECT v.*, CONCAT(c.first_name, ' ', c.last_name) AS owner_name 
                       FROM vehicles v 
                       JOIN customers c ON v.customer_id = c.customer_id 
                       ORDER BY v.brand, v.model");
while ($v_res && $row = $v_res->fetch_assoc()) {
    $vehicles[] = $row;
}

// Get mechanics
$mechanics = [];
$m_res = $conn->query("SELECT employeeID, first_name, last_name FROM employee 
                       WHERE role IN ('Employee', 'Mechanic') AND is_approved = 1 
                       ORDER BY first_name");
while ($m_res && $row = $m_res->fetch_assoc()) {
    $mechanics[] = $row;
}

// Service types with fixed prices
$service_types = [
    'Mechanical Job' => 2000,
    'Auto Electrical Job' => 1500,
    'Alternator and Starter Repair' => 3000,
    'Body Alignment and Painting' => 8000,
    'Calibration' => 1000,
    'Battery Charging and Radiator Overhaul' => 2500,
    'Change Oil' => 1500,
    'Welding Job' => 1200,
    'OBD II Scanning' => 500
];

// Process POST — Admin can ONLY create job orders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create_job_order') {
        $customer_id        = intval($_POST['customer_id'] ?? 0);
        $vehicle_id         = intval($_POST['vehicle_id'] ?? 0);
        $assigned_mechanic  = intval($_POST['assigned_mechanic'] ?? 0);
        $job_description    = $conn->real_escape_string($_POST['job_description'] ?? '');
        $service_type       = $conn->real_escape_string($_POST['service_type'] ?? '');
        $notes              = $conn->real_escape_string($_POST['notes'] ?? '');
        $customer_complaint = $conn->real_escape_string($_POST['customer_complaint'] ?? '');
        $price              = floatval($_POST['price'] ?? 0);

        if ($customer_id && $vehicle_id && $assigned_mechanic && !empty($job_description) && !empty($service_type) && $price > 0) {

            $sql = "INSERT INTO job_orders (
                customer_id, vehicle_id, assigned_mechanic, job_description,
                service_type, status, date_received, price, customer_complaint, notes
            ) VALUES (?, ?, ?, ?, ?, 'Pending', NOW(), ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "iiissdss",
                $customer_id,
                $vehicle_id,
                $assigned_mechanic,
                $job_description,
                $service_type,
                $price,
                $customer_complaint,
                $notes
            );

            if ($stmt->execute()) {
                $job_order_id = $conn->insert_id;
                $successMsg = "Job Order #" . str_pad($job_order_id, 5, '0', STR_PAD_LEFT) . " created successfully!";
                // Clear form data by redirecting to prevent resubmission
                header("Location: new_job_order.php?success=" . urlencode($successMsg));
                exit();
            } else {
                $errorMsg = "Failed to create job order: " . $conn->error;
            }
            $stmt->close();
        } else {
            $errorMsg = "Please fill in all required fields.";
        }
    }
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    $successMsg = htmlspecialchars($_GET['success']);
}

// Filters
$filter_status    = $_GET['status'] ?? 'all';
$filter_search    = $_GET['search'] ?? '';
$filter_customer  = intval($_GET['customer'] ?? 0);
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to   = $_GET['date_to']   ?? '';

$where = ["1=1"];
if ($filter_status !== 'all') {
    $where[] = "jo.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if (!empty($filter_search)) {
    $search  = $conn->real_escape_string($filter_search);
    $where[] = "(c.first_name LIKE '%$search%' OR c.last_name LIKE '%$search%' 
                OR v.plate_number LIKE '%$search%' OR jo.job_description LIKE '%$search%')";
}
if ($filter_customer > 0) {
    $where[] = "jo.customer_id = $filter_customer";
}
if (!empty($_GET['date_from']) && !empty($_GET['date_to'])) {
    $where[] = "DATE(jo.date_received) BETWEEN '$filter_date_from' AND '$filter_date_to'";
}
$where_sql = implode(' AND ', $where);

// Job Orders List
$job_orders = [];
$jo_res = $conn->query("
    SELECT jo.*, 
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           c.contact_number,
           CONCAT(v.brand, ' ', v.model, ' (', v.plate_number, ')') AS vehicle_info,
           CONCAT(e.first_name, ' ', e.last_name) AS mechanic_name
    FROM job_orders jo
    LEFT JOIN customers c ON jo.customer_id      = c.customer_id
    LEFT JOIN vehicles v  ON jo.vehicle_id        = v.vehicle_id
    LEFT JOIN employee e  ON jo.assigned_mechanic = e.employeeID
    ORDER BY jo.job_order_id DESC
    LIMIT 100
");
while ($jo_res && $row = $jo_res->fetch_assoc()) {
    $job_orders[] = $row;
}

// Summary Stats
$stats = ['pending' => 0, 'ongoing' => 0, 'completed' => 0, 'cancelled' => 0, 'total' => 0];
$s_res = $conn->query("SELECT status, COUNT(*) as count FROM job_orders GROUP BY status");
while ($s_res && $row = $s_res->fetch_assoc()) {
    $key = strtolower(str_replace(' ', '_', $row['status']));
    if (isset($stats[$key])) $stats[$key] = $row['count'];
    $stats['total'] += $row['count'];
}

// Sidebar helpers
$pa_res = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved=0");
$pendingApprovals = ($pa_res && $r = $pa_res->fetch_assoc()) ? $r['cnt'] : 0;
$activeJobs = $stats['pending'] + $stats['ongoing'];

// Single Job Detail
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
        LEFT JOIN customers c ON jo.customer_id      = c.customer_id
        LEFT JOIN vehicles v  ON jo.vehicle_id        = v.vehicle_id
        LEFT JOIN employee e  ON jo.assigned_mechanic = e.employeeID
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
        .stat-card.pending   { border-left: 4px solid #f97316; }
        .stat-card.ongoing   { border-left: 4px solid #3b82f6; }
        .stat-card.completed { border-left: 4px solid #10b981; }
        .stat-card.cancelled { border-left: 4px solid #ef4444; }
        .stat-card.total {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
        }
        .stat-label {
            font-size: 12px; color: var(--muted);
            text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px;
        }
        .stat-value {
            font-family: "Syne", sans-serif;
            font-size: 26px; font-weight: 700; line-height: 1.2;
        }
        .stat-card.total .stat-label,
        .stat-card.total .stat-value { color: #fff; }

        .toolbar {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 20px; flex-wrap: wrap;
            background: #fff; padding: 16px 20px;
            border-radius: var(--card-radius); border: 1px solid var(--border);
        }
        .search-box { flex: 2; min-width: 250px; position: relative; }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #aaa; }
        .search-box input {
            width: 100%; padding: 9px 12px 9px 36px;
            border: 1px solid var(--border); border-radius: 8px; font-size: 13px;
        }
        .filter-group { display: flex; gap: 8px; flex-wrap: wrap; }
        .filter-group select {
            padding: 9px 12px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 13px; background: #fff; cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            background-size: 12px; padding-right: 36px;
        }
        .filter-group input[type=date] {
            padding: 9px 12px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 13px; background: #fff;
        }

        .btn-primary {
            background: var(--accent); color: #fff; border: none;
            padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-outline {
            background: #fff; color: var(--text); border: 1px solid var(--border);
            padding: 9px 18px; border-radius: 8px; font-size: 13px; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
        }

        .job-table {
            background: #fff; border-radius: var(--card-radius);
            border: 1px solid var(--border); overflow: hidden; margin-bottom: 24px;
        }
        .job-table table { width: 100%; border-collapse: collapse; }
        .job-table thead th {
            background: #f9fafb; padding: 14px 16px; text-align: left;
            font-size: 11px; text-transform: uppercase;
            color: var(--muted); font-weight: 600; border-bottom: 1px solid var(--border);
        }
        .job-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background .15s; cursor: pointer;
        }
        .job-table tbody tr:hover { background: #f9fafb; }
        .job-table td { padding: 14px 16px; font-size: 13px; }

        .job-id { font-family: "Syne", sans-serif; font-weight: 700; color: var(--accent); }

        .status-badge {
            display: inline-block; padding: 4px 12px;
            border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .status-pending   { background: #fef3c7; color: #92400e; }
        .status-ongoing   { background: #dbeafe; color: #1e40af; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }

        /* Read-only badge shown to admin instead of action buttons */
        .view-only-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
            background: #f3f4f6; color: #6b7280;
            border: 1px solid #e5e7eb;
        }

        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff; border-radius: 16px;
            width: 700px; max-width: 95vw; max-height: 90vh;
            display: flex; flex-direction: column;
            overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }
        .modal.large { width: 900px; }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }
        .modal-header h2 { font-family: "Syne", sans-serif; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: #888; }
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
            flex-shrink: 0;
            background: #fff;
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 12px; font-weight: 600; color: #444; text-transform: uppercase; letter-spacing: .4px; }
        .form-group input,
        .form-group textarea,
        .form-group select {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
        }
        .form-group select {
            padding: 10px 36px 10px 12px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            background-color: #fff;
            cursor: pointer;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .detail-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 20px; margin-bottom: 20px; }
        .detail-label { font-size: 11px; color: var(--muted); text-transform: uppercase; margin-bottom: 4px; }
        .detail-value { font-size: 14px; font-weight: 500; }
        .info-box {
            background: #f8fafc; border-radius: 10px;
            padding: 16px; margin-bottom: 16px; border: 1px solid var(--border);
        }

        .page-alert {
            padding: 12px 16px; border-radius: 8px;
            margin-bottom: 20px; font-size: 13px;
            display: flex; align-items: center; gap: 8px;
        }
        .page-alert.success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .page-alert.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .job-detail-view {
            background: #fff; border-radius: var(--card-radius);
            border: 1px solid var(--border); padding: 24px; margin-bottom: 24px;
        }
        .detail-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--border);
        }
        .detail-title { font-family: "Syne", sans-serif; font-size: 20px; font-weight: 700; }
        .detail-subtitle { color: var(--muted); font-size: 13px; }
        .back-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 8px 16px; background: #f3f4f6;
            border-radius: 8px; text-decoration: none;
            color: var(--text); font-size: 13px; font-weight: 500;
        }
        .back-btn:hover { background: #e5e7eb; }

        /* Info notice shown in detail view */
        .mechanic-notice {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 16px; background: #eff6ff;
            border: 1px solid #bfdbfe; border-radius: 8px;
            font-size: 13px; color: #1e40af;
            margin-top: 16px;
        }
        
        @media (max-width: 1024px) {
            .job-stats { grid-template-columns: repeat(2,1fr); }
            .form-row  { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php
    $currentPage   = 'new_job_order.php';
    $userRoleLabel = htmlspecialchars($role);
    include 'sidebar.php';
    ?>

    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <span class="page-title">Job Orders</span>
                <span class="breadcrumb">Manage Job Orders</span>
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

            <?php if ($selected_job): ?>
            <!-- Job Detail View — Read Only for Admin -->
            <div class="job-detail-view">
                <div class="detail-header">
                    <div>
                        <div class="detail-title">Job Order #<?= str_pad($selected_job['job_order_id'], 5, '0', STR_PAD_LEFT) ?></div>
                        <div class="detail-subtitle">Created on <?= date('F d, Y \a\t h:i A', strtotime($selected_job['date_received'])) ?></div>
                    </div>
                    <a href="new_job_order.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back to List</a>
                </div>

                <div class="detail-grid">
                    <div class="info-box">
                        <div class="detail-label">Customer Information</div>
                        <div class="detail-value"><?= htmlspecialchars($selected_job['customer_name']) ?></div>
                        <div style="font-size:12px; margin-top:8px;">
                            <div><i class="bi bi-telephone"></i> <?= htmlspecialchars($selected_job['contact_number']) ?></div>
                            <div><i class="bi bi-envelope"></i> <?= htmlspecialchars($selected_job['email'] ?? 'N/A') ?></div>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="detail-label">Vehicle Information</div>
                        <div class="detail-value"><?= htmlspecialchars($selected_job['brand'] . ' ' . $selected_job['model']) ?></div>
                        <div style="font-size:12px; margin-top:8px;">
                            <div>Plate: <?= htmlspecialchars($selected_job['plate_number']) ?></div>
                            <div>Year: <?= htmlspecialchars($selected_job['year_model']) ?></div>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="detail-label">Job Details</div>
                        <div><strong>Service:</strong> <?= htmlspecialchars($selected_job['service_type'] ?? 'N/A') ?></div>
                        <div><strong>Price:</strong> ₱<?= number_format($selected_job['price'] ?? 0, 2) ?></div>
                        <div><strong>Mechanic:</strong> <?= htmlspecialchars($selected_job['mechanic_name'] ?? 'Unassigned') ?></div>
                        <div><strong>Status:</strong>
                            <span class="status-badge status-<?= strtolower($selected_job['status']) ?>"><?= $selected_job['status'] ?></span>
                        </div>
                    </div>

                    <div class="info-box">
                        <div class="detail-label">Dates</div>
                        <div><strong>Received:</strong> <?= date('M d, Y h:i A', strtotime($selected_job['date_received'])) ?></div>
                        <?php if ($selected_job['date_completed']): ?>
                            <div><strong>Completed:</strong> <?= date('M d, Y h:i A', strtotime($selected_job['date_completed'])) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($selected_job['customer_complaint'])): ?>
                    <div class="info-box" style="grid-column: span 2;">
                        <div class="detail-label">Customer Complaint</div>
                        <div><?= nl2br(htmlspecialchars($selected_job['customer_complaint'])) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="info-box" style="grid-column: span 2;">
                        <div class="detail-label">Job Description</div>
                        <div><?= nl2br(htmlspecialchars($selected_job['job_description'])) ?></div>
                    </div>

                    <?php if (!empty($selected_job['repair_notes'])): ?>
                    <div class="info-box" style="grid-column: span 2;">
                        <div class="detail-label">Repair Notes</div>
                        <div><?= nl2br(htmlspecialchars($selected_job['repair_notes'])) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($selected_job['notes'])): ?>
                    <div class="info-box" style="grid-column: span 2;">
                        <div class="detail-label">Additional Notes</div>
                        <div><?= nl2br(htmlspecialchars($selected_job['notes'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Admin sees notice instead of action buttons -->
                <?php if (in_array($selected_job['status'], ['Pending', 'Ongoing'])): ?>
                <div class="mechanic-notice">
                    <i class="bi bi-info-circle-fill"></i>
                    Job status can only be updated by the assigned mechanic via the Mechanic Portal.
                </div>
                <?php endif; ?>
            </div>
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
                            <option value="all"       <?= $filter_status==='all'?'selected':'' ?>>All Status</option>
                            <option value="Pending"   <?= $filter_status==='Pending'?'selected':'' ?>>Pending</option>
                            <option value="Ongoing"   <?= $filter_status==='Ongoing'?'selected':'' ?>>Ongoing</option>
                            <option value="Completed" <?= $filter_status==='Completed'?'selected':'' ?>>Completed</option>
                            <option value="Cancelled" <?= $filter_status==='Cancelled'?'selected':'' ?>>Cancelled</option>
                        </select>
                        <input type="date" name="date_from" value="<?= $filter_date_from ?>">
                        <span style="font-size:13px; color:var(--muted); white-space:nowrap; align-self:center;">to</span>
                        <input type="date" name="date_to" value="<?= $filter_date_to ?>">
                        <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                        <?php if ($filter_status !== 'all' || !empty($filter_search) || $filter_customer > 0): ?>
                            <a href="new_job_order.php" class="btn-outline">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Job Orders Table — View Only for Admin -->
            <div class="job-table">
                <table>
                    <thead>
                        <tr>
                            <th>Job #</th>
                            <th>Customer / Vehicle</th>
                            <th>Service</th>
                            <th>Price</th>
                            <th>Mechanic</th>
                            <th>Status</th>
                            <th>Date Received</th>
                            <th></th>
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
                                $status_class = 'status-' . strtolower($job['status']);
                            ?>
                            <tr onclick="viewJobDetails(<?= $job['job_order_id'] ?>)">
                                <td><span class="job-id">#<?= str_pad($job['job_order_id'], 5, '0', STR_PAD_LEFT) ?></span></td>
                                <td>
                                    <div><?= htmlspecialchars($job['customer_name'] ?? '—') ?></div>
                                    <div style="font-size:11px; color:#666;"><?= htmlspecialchars($job['vehicle_info'] ?? '') ?></div>
                                </td>
                                <td><?= htmlspecialchars($job['service_type'] ?? '—') ?></td>
                                <td>₱<?= number_format($job['price'] ?? 0, 2) ?></td>
                                <td><?= htmlspecialchars($job['mechanic_name'] ?? 'Unassigned') ?></td>
                                <td><span class="status-badge <?= $status_class ?>"><?= $job['status'] ?></span></td>
                                <td><?= date('M d, Y', strtotime($job['date_received'])) ?></td>
                                <td onclick="event.stopPropagation()">
                                    <!-- Admin can only VIEW — no Start/Complete/Cancel buttons -->
                                    <span class="view-only-badge">
                                        <i class="bi bi-eye"></i> View Only
                                    </span>
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
            <form method="POST" id="jobOrderForm">
                <input type="hidden" name="action" value="create_job_order">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Customer *</label>
                            <select name="customer_id" id="customer_select" required onchange="loadCustomerVehicles()">
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>">
                                        <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?>
                                    </option>
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

                    <div class="form-row">
                        <div class="form-group">
                            <label>Service Type *</label>
                            <select name="service_type" id="service_type_select" required onchange="updatePrice()">
                                <option value="">Select Service</option>
                                <?php foreach ($service_types as $service => $price): ?>
                                    <option value="<?= $service ?>" data-price="<?= $price ?>">
                                        <?= htmlspecialchars($service) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Price (₱) *</label>
                            <input type="number" name="price" id="price_input" step="0.01" required readonly style="background-color: #f3f4f6;">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Assigned Mechanic *</label>
                            <select name="assigned_mechanic" required>
                                <option value="">Select Mechanic</option>
                                <?php foreach ($mechanics as $m): ?>
                                    <option value="<?= $m['employeeID'] ?>">
                                        <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <!-- placeholder for alignment -->
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Job Description *</label>
                        <textarea name="job_description" rows="3" required placeholder="Describe the work needed..."></textarea>
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
                    <button type="submit" class="btn-primary">
                        <i class="bi bi-check-lg"></i> Create Job Order
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id)  { 
            document.getElementById(id).classList.add('open');
            // Reset form when opening modal
            if (id === 'newJobModal') {
                document.getElementById('jobOrderForm').reset();
                document.getElementById('price_input').value = '';
            }
        }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }

        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
        });

        function loadCustomerVehicles() {
            const customerId    = document.getElementById('customer_select').value;
            const vehicleSelect = document.getElementById('vehicle_select');
            vehicleSelect.querySelectorAll('option').forEach(opt => {
                if (opt.value === '') return;
                opt.style.display = (opt.dataset.customer == customerId || customerId === '') ? '' : 'none';
            });
            vehicleSelect.value = '';
        }

        function updatePrice() {
            const select = document.getElementById('service_type_select');
            const selectedOption = select.options[select.selectedIndex];
            const price = selectedOption.getAttribute('data-price');
            const priceInput = document.getElementById('price_input');
            if (price) {
                priceInput.value = parseFloat(price).toFixed(2);
            } else {
                priceInput.value = '';
            }
        }

        function viewJobDetails(jobId) {
            window.location.href = 'new_job_order.php?view=' + jobId;
        }

        window.onload = function () {
            if (new URLSearchParams(window.location.search).has('view')) window.scrollTo(0, 0);
        };
    </script>
</body>
</html>
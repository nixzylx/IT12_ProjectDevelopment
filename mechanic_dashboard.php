<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/dbconnection.php';

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

// Only mechanics/employees can access this page
if (!in_array(strtolower($user['role']), ['mechanic', 'employee'])) {
    header("Location: admin_dashboard.php");
    exit();
}

$mechanic_id   = $_SESSION['employeeID'];
$firstname     = htmlspecialchars($user['first_name']);
$lastname      = htmlspecialchars($user['last_name']);
$role          = $user['role'];
$userInitials  = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
$userRoleLabel = htmlspecialchars($role);

$todayLabel = date('l, F j, Y');
$hourNow    = (int) date('G');
$greeting   = ($hourNow < 12) ? 'Good morning' : (($hourNow < 17) ? 'Good afternoon' : 'Good evening');

$successMsg = '';
$errorMsg   = '';

// ── Handle POST Actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Pending → Ongoing
    if ($_POST['action'] === 'start_job') {
        $job_order_id = intval($_POST['job_order_id'] ?? 0);
        $repair_notes = $conn->real_escape_string($_POST['repair_notes'] ?? '');

        $check = $conn->query("
            SELECT job_order_id FROM job_orders
            WHERE job_order_id = $job_order_id
            AND assigned_mechanic = $mechanic_id
            AND status = 'Pending'
        ");

        if ($check && $check->num_rows > 0) {
            $conn->query("
                UPDATE job_orders
                SET status = 'Ongoing', repair_notes = '$repair_notes'
                WHERE job_order_id = $job_order_id
            ");
            $successMsg = "Job #" . str_pad($job_order_id, 5, '0', STR_PAD_LEFT) . " is now Ongoing!";
        } else {
            $errorMsg = "Unable to start this job. It may no longer be Pending.";
        }
    }

    // Ongoing → Completed (no redirect, show popup instead)
    if ($_POST['action'] === 'complete_job') {
        $job_order_id = intval($_POST['job_order_id'] ?? 0);

        $check = $conn->query("
            SELECT job_order_id FROM job_orders
            WHERE job_order_id = $job_order_id
            AND assigned_mechanic = $mechanic_id
            AND status = 'Ongoing'
        ");

        if ($check && $check->num_rows > 0) {
            $conn->query("
                UPDATE job_orders
                SET status = 'Completed', date_completed = NOW()
                WHERE job_order_id = $job_order_id
            ");
            $successMsg = "Job #" . str_pad($job_order_id, 5, '0', STR_PAD_LEFT) . " has been marked as Completed!";
        } else {
            $errorMsg = "Unable to complete this job. It may no longer be Ongoing.";
        }
    }

    // Cancel Job
    if ($_POST['action'] === 'cancel_job') {
        $job_order_id = intval($_POST['job_order_id'] ?? 0);

        $check = $conn->query("
            SELECT job_order_id FROM job_orders
            WHERE job_order_id = $job_order_id
            AND assigned_mechanic = $mechanic_id
            AND status IN ('Pending', 'Ongoing')
        ");

        if ($check && $check->num_rows > 0) {
            $conn->query("UPDATE job_orders SET status = 'Cancelled' WHERE job_order_id = $job_order_id");
            $successMsg = "Job #" . str_pad($job_order_id, 5, '0', STR_PAD_LEFT) . " has been cancelled.";
        } else {
            $errorMsg = "Unable to cancel this job.";
        }
    }
}

// ── Stats ──
$stats = ['pending' => 0, 'ongoing' => 0, 'completed' => 0, 'total' => 0, 'completed_today' => 0];
try {
    $res = $conn->query("SELECT status, COUNT(*) as cnt FROM job_orders WHERE assigned_mechanic = $mechanic_id GROUP BY status");
    while ($res && $row = $res->fetch_assoc()) {
        $key = strtolower($row['status']);
        if (isset($stats[$key])) $stats[$key] = $row['cnt'];
        $stats['total'] += $row['cnt'];
    }
    $res2 = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE assigned_mechanic = $mechanic_id AND status = 'Completed' AND DATE(date_completed) = CURDATE()");
    if ($res2 && $r = $res2->fetch_assoc()) $stats['completed_today'] = $r['cnt'];
} catch (Exception $e) {}

// ── Filters ──
$filter_status = $_GET['status'] ?? 'active';
$filter_search = trim($_GET['search'] ?? '');

$where = ["jo.assigned_mechanic = $mechanic_id"];
if ($filter_status === 'active') {
    $where[] = "jo.status IN ('Pending','Ongoing')";
} elseif ($filter_status !== 'all') {
    $safe    = $conn->real_escape_string($filter_status);
    $where[] = "jo.status = '$safe'";
}
if (!empty($filter_search)) {
    $s       = $conn->real_escape_string($filter_search);
    $where[] = "(c.first_name LIKE '%$s%' OR c.last_name LIKE '%$s%' OR v.plate_number LIKE '%$s%' OR jo.job_description LIKE '%$s%')";
}
$where_sql = implode(' AND ', $where);

// ── Job Orders List ──
$job_orders = [];
try {
    $jo_res = $conn->query("
        SELECT jo.*,
               CONCAT(c.first_name,' ',c.last_name) AS customer_name,
               c.contact_number,
               CONCAT(v.brand,' ',v.model,' (',v.plate_number,')') AS vehicle_info,
               v.brand, v.model, v.plate_number, v.year_model
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.customer_id
        LEFT JOIN vehicles v  ON jo.vehicle_id  = v.vehicle_id
        WHERE $where_sql
        ORDER BY CASE jo.status WHEN 'Ongoing' THEN 1 WHEN 'Pending' THEN 2 ELSE 3 END,
                 jo.date_received DESC
        LIMIT 100
    ");
    while ($jo_res && $row = $jo_res->fetch_assoc()) $job_orders[] = $row;
} catch (Exception $e) {}

// ── Recently Completed ──
$recentCompleted = [];
try {
    $rc = $conn->query("
        SELECT jo.job_order_id,
               CONCAT(c.first_name,' ',c.last_name) AS customer_name,
               CONCAT(v.brand,' ',v.model) AS vehicle_info,
               jo.date_completed
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.customer_id
        LEFT JOIN vehicles v  ON jo.vehicle_id  = v.vehicle_id
        WHERE jo.assigned_mechanic = $mechanic_id AND jo.status = 'Completed'
        ORDER BY jo.date_completed DESC LIMIT 5
    ");
    while ($rc && $row = $rc->fetch_assoc()) $recentCompleted[] = $row;
} catch (Exception $e) {}

// ── Single Detail View ──
$selected_job = null;
if (isset($_GET['view'])) {
    $view_id = intval($_GET['view']);
    try {
        $vr = $conn->query("
            SELECT jo.*,
                   CONCAT(c.first_name,' ',c.last_name) AS customer_name,
                   c.contact_number, c.email,
                   v.brand, v.model, v.plate_number, v.year_model
            FROM job_orders jo
            LEFT JOIN customers c ON jo.customer_id = c.customer_id
            LEFT JOIN vehicles v  ON jo.vehicle_id  = v.vehicle_id
            WHERE jo.job_order_id = $view_id AND jo.assigned_mechanic = $mechanic_id
        ");
        if ($vr && $vr->num_rows > 0) $selected_job = $vr->fetch_assoc();
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AutoBert — Mechanic Portal</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<link rel="stylesheet" href="style.css">
<style>
.stats-grid { grid-template-columns: repeat(4, minmax(0,1fr)); }

/* ── Locked nav item (not accessible) ── */
.nav-item-locked {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 8px; border-radius: 10px;
    font-size: 13.5px; font-weight: 400;
    color: rgba(255,255,255,0.2);
    cursor: not-allowed;
    user-select: none;
    position: relative;
}
.nav-item-locked i { opacity: .4; flex-shrink: 0; font-size: 15px; }
.lock-icon {
    margin-left: auto;
    font-size: 11px;
    opacity: .35;
}

.job-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px,1fr));
    gap: 14px;
}

.job-card {
    background: var(--surface);
    border-radius: var(--card-radius);
    border: 1px solid var(--border);
    overflow: hidden;
    transition: transform .2s ease, box-shadow .2s ease;
    cursor: pointer;
    animation: fadeUp .4s ease both;
}
.job-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0,0,0,.06); }

.job-card-top {
    padding: 14px 16px;
    display: flex; justify-content: space-between; align-items: center;
    border-bottom: 1px solid var(--border);
    background: #fafaf8;
}
.job-card-body { padding: 16px; }
.job-card-row {
    display: flex; align-items: flex-start; gap: 10px;
    margin-bottom: 10px;
}
.job-card-row:last-child { margin-bottom: 0; }
.jcr-icon {
    width: 28px; height: 28px; border-radius: 8px;
    background: var(--bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; color: var(--accent);
    flex-shrink: 0; margin-top: 2px;
}
.jcr-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
.jcr-value { font-size: 13px; font-weight: 600; margin-top: 1px; line-height: 1.3; }
.service-chip {
    display: inline-block; padding: 3px 10px;
    background: #eff6ff; color: var(--accent);
    border-radius: 20px; font-size: 11px; font-weight: 600;
    margin-top: 2px;
}
.job-card-footer {
    padding: 12px 16px; border-top: 1px solid var(--border);
    display: flex; gap: 8px; justify-content: flex-end;
}

.toolbar {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--card-radius);
    padding: 14px 18px; margin-bottom: 20px;
    display: flex; gap: 12px; flex-wrap: wrap; align-items: center;
}
.search-wrap { flex: 2; min-width: 220px; position: relative; }
.search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #aaa; font-size: 13px; }
.search-wrap input {
    width: 100%; padding: 8px 12px 8px 34px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 8px; font-size: 13px; color: var(--text);
    font-family: "DM Sans", sans-serif; outline: none;
}
.search-wrap input:focus { border-color: var(--accent); }
.search-wrap input::placeholder { color: var(--muted); }

.filter-tabs { display: flex; gap: 6px; }
.tab-btn {
    padding: 7px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 600; cursor: pointer;
    border: 1px solid var(--border);
    background: var(--bg); color: var(--muted);
    font-family: "DM Sans", sans-serif; transition: all .15s;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

.detail-card {
    background: var(--surface); border-radius: var(--card-radius);
    border: 1px solid var(--border); overflow: hidden;
    margin-bottom: 20px; animation: fadeUp .4s ease both;
}
.detail-card-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 20px 24px; border-bottom: 1px solid var(--border); background: #fafaf8;
}
.detail-title { font-weight: 700; font-size: 18px; }
.detail-sub   { font-size: 12px; color: var(--muted); margin-top: 3px; }
.back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; background: var(--bg);
    border: 1px solid var(--border); border-radius: 8px;
    text-decoration: none; color: var(--muted);
    font-size: 12px; font-weight: 600; transition: all .15s;
}
.back-btn:hover { border-color: var(--accent); color: var(--accent); }

.detail-body { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.detail-section {
    padding: 20px 24px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}
.detail-section:nth-child(even) { border-right: none; }
.detail-section.full { grid-column: 1/-1; border-right: none; }
.ds-title {
    font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px;
    color: var(--accent); font-weight: 700; margin-bottom: 14px;
    display: flex; align-items: center; gap: 6px;
}
.ds-row { margin-bottom: 10px; }
.ds-label { font-size: 11px; color: var(--muted); margin-bottom: 2px; }
.ds-value { font-size: 13px; font-weight: 600; }
.desc-box {
    background: var(--bg); border-radius: 8px;
    padding: 12px 14px; font-size: 13px; line-height: 1.6;
    border: 1px solid var(--border);
}
.detail-actions {
    padding: 16px 24px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 10px;
}

/* ── Success Popup Modal ── */
.popup-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.4); z-index: 2000;
    align-items: center; justify-content: center;
}
.popup-overlay.open { display: flex; }
.popup-box {
    background: #fff; border-radius: 16px;
    padding: 36px 40px; text-align: center;
    box-shadow: 0 24px 60px rgba(0,0,0,.18);
    animation: fadeUp .25s ease both;
    max-width: 380px; width: 90%;
}
.popup-icon {
    width: 64px; height: 64px; border-radius: 50%;
    background: #dcfce7;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    font-size: 28px; color: #16a34a;
}
.popup-title {
    font-size: 20px; font-weight: 700;
    margin-bottom: 8px; color: var(--text);
}
.popup-msg {
    font-size: 14px; color: var(--muted);
    margin-bottom: 24px; line-height: 1.5;
}
.popup-btn {
    background: #16a34a; color: #fff; border: none;
    border-radius: 10px; padding: 10px 28px;
    font-size: 14px; font-weight: 600;
    font-family: "DM Sans", sans-serif; cursor: pointer;
    transition: background .15s;
}
.popup-btn:hover { background: #15803d; }

.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.4); z-index: 1000;
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--card-radius); width: 460px; max-width: 95vw;
    box-shadow: 0 20px 50px rgba(0,0,0,.15);
    overflow: hidden; animation: fadeUp .2s ease;
}
.modal-header {
    padding: 18px 22px; border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center;
}
.modal-header h2 { font-weight: 700; font-size: 16px; display: flex; align-items: center; gap: 8px; }
.modal-close { background: none; border: none; font-size: 20px; color: var(--muted); cursor: pointer; }
.modal-close:hover { color: var(--text); }
.modal-body { padding: 20px 22px; }
.form-group { margin-bottom: 14px; }
.form-group label {
    display: block; font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    color: var(--muted); margin-bottom: 6px;
}
.form-group textarea {
    width: 100%; padding: 10px 12px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 8px; font-size: 13px; color: var(--text);
    font-family: "DM Sans", sans-serif; resize: vertical;
}
.form-group textarea:focus { outline: none; border-color: var(--accent); }
.form-group textarea::placeholder { color: var(--muted); }
.modal-footer {
    padding: 14px 22px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; gap: 8px;
}

.page-alert {
    padding: 12px 16px; border-radius: 10px;
    margin-bottom: 20px; font-size: 13px; font-weight: 500;
    display: flex; align-items: center; gap: 8px;
}
.page-alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.page-alert.error   { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }

.btn-success {
    background: #16a34a; color: #fff; border: none;
    border-radius: 10px; padding: 8px 16px;
    font-size: 13px; font-weight: 600;
    font-family: "DM Sans", sans-serif; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background .15s, transform .1s;
}
.btn-success:hover { background: #15803d; transform: translateY(-1px); }

.btn-danger {
    background: #ef4444; color: #fff; border: none;
    border-radius: 10px; padding: 8px 16px;
    font-size: 13px; font-weight: 600;
    font-family: "DM Sans", sans-serif; cursor: pointer;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background .15s, transform .1s;
}
.btn-danger:hover { background: #dc2626; transform: translateY(-1px); }

.btn-outline-sm {
    background: var(--bg); color: var(--text);
    border: 1px solid var(--border); border-radius: 8px;
    padding: 6px 12px; font-size: 12px; font-weight: 600;
    font-family: "DM Sans", sans-serif; cursor: pointer;
    transition: all .15s;
}
.btn-outline-sm:hover { border-color: var(--accent); color: var(--accent); }

.empty-jobs { text-align: center; padding: 60px 20px; grid-column: 1/-1; }
.empty-jobs .empty-icon { font-size: 48px; opacity: .2; margin-bottom: 12px; }
.empty-jobs .empty-text { color: var(--muted); font-size: 14px; }

.vehicle-mini-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 20px; border-bottom: 1px solid var(--bg);
    text-decoration: none; color: inherit; transition: background .15s;
}
.vehicle-mini-item:hover { background: #fafaf8; }
.vehicle-mini-name  { font-weight: 600; font-size: 13px; }
.vehicle-mini-plate { font-size: 11px; color: var(--accent); margin-top: 2px; }
.vehicle-mini-owner { font-size: 11px; color: var(--muted); }
.vehicle-mini-link  { color: var(--accent); font-size: 12px; }

@media (max-width: 1024px) {
    .stats-grid { grid-template-columns: repeat(2,1fr); }
    .detail-body { grid-template-columns: 1fr; }
    .detail-section { border-right: none; }
    .detail-section.full { grid-column: 1; }
}
</style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside class="sidebar">
    <div class="logo">
        <a href="mechanic_dashboard.php" class="logo-container">
            <div class="logo-mark">
                <img src="AB logo.png" alt="AutoBert Logo" class="logo-img">
            </div>
            <div class="logo-text-wrapper">
                <div class="logo-name">AutoBert</div>
                <div class="logo-sub">Mechanic Portal</div>
            </div>
        </a>
    </div>

    <nav class="nav-section">
        <div class="nav-label">Main</div>
        <a class="nav-item <?= basename($_SERVER['PHP_SELF']) === 'mechanic_dashboard.php' && !isset($_GET['status']) ? 'active' : '' ?>" href="mechanic_dashboard.php">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="nav-item <?= isset($_GET['status']) && $_GET['status'] === 'active' ? 'active' : '' ?>" href="mechanic_dashboard.php?status=active">
            <i class="bi bi-clipboard-check"></i> My Job Orders
            <?php if ($stats['pending'] + $stats['ongoing'] > 0): ?>
                <span class="pending-approvals-badge"><?= $stats['pending'] + $stats['ongoing'] ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <nav class="nav-section">
        <div class="nav-label">Filters</div>
        <a class="nav-item <?= ($filter_status === 'Pending') ? 'active' : '' ?>" href="mechanic_dashboard.php?status=Pending">
            <i class="bi bi-hourglass-split"></i> Pending
            <?php if ($stats['pending'] > 0): ?>
                <span class="pending-approvals-badge" style="background:#f97316;"><?= $stats['pending'] ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-item <?= ($filter_status === 'Ongoing') ? 'active' : '' ?>" href="mechanic_dashboard.php?status=Ongoing">
            <i class="bi bi-wrench-adjustable"></i> Ongoing
            <?php if ($stats['ongoing'] > 0): ?>
                <span class="pending-approvals-badge"><?= $stats['ongoing'] ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-item <?= ($filter_status === 'Completed') ? 'active' : '' ?>" href="mechanic_dashboard.php?status=Completed">
            <i class="bi bi-check2-circle"></i> Completed
        </a>
    </nav>

    <nav class="nav-section">
        <div class="nav-label" style="opacity:.4;">Restricted</div>
        <div class="nav-item-locked"><i class="bi bi-people"></i> Customers<i class="bi bi-lock lock-icon"></i></div>
        <div class="nav-item-locked"><i class="bi bi-truck"></i> Vehicles<i class="bi bi-lock lock-icon"></i></div>
        <div class="nav-item-locked"><i class="bi bi-credit-card"></i> Payments<i class="bi bi-lock lock-icon"></i></div>
        <div class="nav-item-locked"><i class="bi bi-shield-check"></i> Warranties<i class="bi bi-lock lock-icon"></i></div>
        <div class="nav-item-locked"><i class="bi bi-person-badge"></i> Employees<i class="bi bi-lock lock-icon"></i></div>
        <div class="nav-item-locked"><i class="bi bi-bar-chart-line"></i> Reports<i class="bi bi-lock lock-icon"></i></div>
    </nav>

    <div class="sidebar-footer">
        <div class="user-row">
            <div class="avatar"><?= $userInitials ?></div>
            <div>
                <div class="user-name"><?= $firstname ?></div>
                <div class="user-role"><?= $userRoleLabel ?></div>
            </div>
        </div>
        <div style="margin-top: 10px; text-align: center;">
            <a href="logout.php" style="color: var(--sidebar-text); text-decoration: none; font-size: 12px;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>
</aside>

<!-- ── Main ── -->
<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <span class="page-title">My Job Orders</span>
            <span class="breadcrumb">Mechanic Portal</span>
        </div>
        <div class="topbar-right">
            <div class="avatar" style="width:34px;height:34px;font-size:12px;"><?= $userInitials ?></div>
            <button class="logout-btn" onclick="window.location.href='logout.php'">
                <i class="bi bi-box-arrow-right"></i>
            </button>
        </div>
    </header>

    <div class="content">

        <?php if ($errorMsg): ?>
            <div class="page-alert error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Greeting -->
        <div class="greeting">
            <h1><?= $greeting ?>, <?= $firstname ?>!</h1>
            <p><?= $todayLabel ?></p>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <a href="mechanic_dashboard.php?status=Pending" class="stat-link">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fff7ed; border-radius:10px; width:40px; height:40px; display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-hourglass-split" style="color:#f97316;"></i>
                    </div>
                    <div class="stat-label">Pending</div>
                    <div class="stat-value"><?= $stats['pending'] ?></div>
                    <div class="stat-change"><?= $stats['pending'] ?> waiting to start</div>
                </div>
            </a>
            <a href="mechanic_dashboard.php?status=Ongoing" class="stat-link">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#eff6ff; border-radius:10px; width:40px; height:40px; display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-wrench-adjustable" style="color:#2563eb;"></i>
                    </div>
                    <div class="stat-label">Ongoing</div>
                    <div class="stat-value"><?= $stats['ongoing'] ?></div>
                    <div class="stat-change"><?= $stats['ongoing'] ?> in progress</div>
                </div>
            </a>
            <a href="mechanic_dashboard.php?status=Completed" class="stat-link">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#dcfce7; border-radius:10px; width:40px; height:40px; display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-calendar2-check" style="color:#16a34a;"></i>
                    </div>
                    <div class="stat-label">Completed Today</div>
                    <div class="stat-value"><?= $stats['completed_today'] ?></div>
                    <div class="stat-change <?= $stats['completed_today'] > 0 ? 'up' : '' ?>">
                        <?= $stats['completed_today'] > 0 ? 'Great work!' : 'No completions yet' ?>
                    </div>
                </div>
            </a>
            <a href="mechanic_dashboard.php?status=active" class="stat-link">
                <div class="stat-card featured">
                    <div class="stat-icon" style="background:rgba(255,255,255,.2); border-radius:10px; width:40px; height:40px; display:flex; align-items:center; justify-content:center;">
                        <i class="bi bi-clipboard-data" style="color:#fff;"></i>
                    </div>
                    <div class="stat-label">Total Assigned</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-change">All time</div>
                </div>
            </a>
        </div>

        <!-- Job Detail View -->
        <?php if ($selected_job): ?>
        <div class="detail-card">
            <div class="detail-card-header">
                <div>
                    <div class="detail-title">Job Order #<?= str_pad($selected_job['job_order_id'],5,'0',STR_PAD_LEFT) ?></div>
                    <div class="detail-sub">Received <?= date('F d, Y \a\t h:i A', strtotime($selected_job['date_received'])) ?></div>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="status-badge status-<?= strtolower($selected_job['status']) ?>"><?= $selected_job['status'] ?></span>
                    <a href="mechanic_dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back</a>
                </div>
            </div>

            <div class="detail-body">
                <div class="detail-section">
                    <div class="ds-title"><i class="bi bi-person"></i> Customer</div>
                    <div class="ds-row"><div class="ds-label">Name</div><div class="ds-value"><?= htmlspecialchars($selected_job['customer_name']) ?></div></div>
                    <div class="ds-row"><div class="ds-label">Contact</div><div class="ds-value"><?= htmlspecialchars($selected_job['contact_number'] ?? '—') ?></div></div>
                    <div class="ds-row"><div class="ds-label">Email</div><div class="ds-value"><?= htmlspecialchars($selected_job['email'] ?? '—') ?></div></div>
                </div>
                <div class="detail-section">
                    <div class="ds-title"><i class="bi bi-car-front"></i> Vehicle</div>
                    <div class="ds-row"><div class="ds-label">Car</div><div class="ds-value"><?= htmlspecialchars($selected_job['brand'].' '.$selected_job['model']) ?></div></div>
                    <div class="ds-row"><div class="ds-label">Plate Number</div><div class="ds-value"><?= htmlspecialchars($selected_job['plate_number']) ?></div></div>
                    <div class="ds-row"><div class="ds-label">Year Model</div><div class="ds-value"><?= htmlspecialchars($selected_job['year_model']) ?></div></div>
                </div>
                <div class="detail-section">
                    <div class="ds-title"><i class="bi bi-gear"></i> Job Info</div>
                    <div class="ds-row">
                        <div class="ds-label">Service Type</div>
                        <div class="ds-value">
                            <?php if (!empty($selected_job['service_type'])): ?>
                                <span class="service-chip"><?= htmlspecialchars($selected_job['service_type']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="ds-row" style="margin-top:8px;">
                        <div class="ds-label">Date Received</div>
                        <div class="ds-value"><?= date('M d, Y h:i A', strtotime($selected_job['date_received'])) ?></div>
                    </div>
                    <?php if ($selected_job['date_completed']): ?>
                    <div class="ds-row">
                        <div class="ds-label">Date Completed</div>
                        <div class="ds-value"><?= date('M d, Y h:i A', strtotime($selected_job['date_completed'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="detail-section">
                    <div class="ds-title"><i class="bi bi-chat-left-text"></i> Customer Complaint</div>
                    <div class="desc-box">
                        <?= !empty($selected_job['customer_complaint'])
                            ? nl2br(htmlspecialchars($selected_job['customer_complaint']))
                            : '<span style="color:var(--muted)">No complaint recorded.</span>' ?>
                    </div>
                </div>
                <div class="detail-section full">
                    <div class="ds-title"><i class="bi bi-file-text"></i> Job Description</div>
                    <div class="desc-box"><?= nl2br(htmlspecialchars($selected_job['job_description'])) ?></div>
                </div>
                <?php if (!empty($selected_job['repair_notes'])): ?>
                <div class="detail-section full">
                    <div class="ds-title"><i class="bi bi-pencil-square"></i> Repair Notes</div>
                    <div class="desc-box"><?= nl2br(htmlspecialchars($selected_job['repair_notes'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($selected_job['status'] === 'Pending'): ?>
            <div class="detail-actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="cancel_job">
                    <input type="hidden" name="job_order_id" value="<?= $selected_job['job_order_id'] ?>">
                    <button type="submit" class="btn-danger" onclick="return confirm('Cancel this job order?')">
                        <i class="bi bi-x-circle"></i> Cancel Job
                    </button>
                </form>
                <button class="btn-primary" onclick="openStartModal(<?= $selected_job['job_order_id'] ?>)">
                    <i class="bi bi-play-fill"></i> Start This Job
                </button>
            </div>
            <?php elseif ($selected_job['status'] === 'Ongoing'): ?>
            <div class="detail-actions">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="cancel_job">
                    <input type="hidden" name="job_order_id" value="<?= $selected_job['job_order_id'] ?>">
                    <button type="submit" class="btn-danger" onclick="return confirm('Cancel this job order?')">
                        <i class="bi bi-x-circle"></i> Cancel Job
                    </button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="complete_job">
                    <input type="hidden" name="job_order_id" value="<?= $selected_job['job_order_id'] ?>">
                    <button type="submit" class="btn-success" onclick="return confirm('Mark this job as completed?')">
                        <i class="bi bi-check-lg"></i> Mark as Completed
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Bottom Grid -->
        <div class="bottom-grid">
            <!-- Left: Job List -->
            <div>
                <form method="GET">
                    <div class="toolbar">
                        <div class="search-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search"
                                placeholder="Search customer, plate, description..."
                                value="<?= htmlspecialchars($filter_search) ?>">
                        </div>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                        <div class="filter-tabs">
                            <button type="submit" name="status" value="active"    class="tab-btn <?= $filter_status==='active'?'active':'' ?>">Active</button>
                            <button type="submit" name="status" value="Pending"   class="tab-btn <?= $filter_status==='Pending'?'active':'' ?>">Pending</button>
                            <button type="submit" name="status" value="Ongoing"   class="tab-btn <?= $filter_status==='Ongoing'?'active':'' ?>">Ongoing</button>
                            <button type="submit" name="status" value="Completed" class="tab-btn <?= $filter_status==='Completed'?'active':'' ?>">Completed</button>
                        </div>
                    </div>
                </form>

                <div class="job-cards-grid">
                    <?php if (empty($job_orders)): ?>
                        <div class="empty-jobs">
                            <div class="empty-icon"><i class="bi bi-clipboard-x"></i></div>
                            <div class="empty-text">No job orders found for this filter.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($job_orders as $job): ?>
                        <div class="job-card" onclick="window.location.href='mechanic_dashboard.php?view=<?= $job['job_order_id'] ?>'">
                            <div class="job-card-top">
                                <span class="job-id">#<?= str_pad($job['job_order_id'],5,'0',STR_PAD_LEFT) ?></span>
                                <span class="status-badge status-<?= strtolower($job['status']) ?>"><?= $job['status'] ?></span>
                            </div>
                            <div class="job-card-body">
                                <div class="job-card-row">
                                    <div class="jcr-icon"><i class="bi bi-person"></i></div>
                                    <div>
                                        <div class="jcr-label">Customer</div>
                                        <div class="jcr-value"><?= htmlspecialchars($job['customer_name'] ?? '—') ?></div>
                                    </div>
                                </div>
                                <div class="job-card-row">
                                    <div class="jcr-icon"><i class="bi bi-car-front"></i></div>
                                    <div>
                                        <div class="jcr-label">Vehicle</div>
                                        <div class="jcr-value"><?= htmlspecialchars($job['vehicle_info'] ?? '—') ?></div>
                                    </div>
                                </div>
                                <div class="job-card-row">
                                    <div class="jcr-icon"><i class="bi bi-tools"></i></div>
                                    <div>
                                        <div class="jcr-label">Service</div>
                                        <?php if (!empty($job['service_type'])): ?>
                                            <span class="service-chip"><?= htmlspecialchars($job['service_type']) ?></span>
                                        <?php else: ?>
                                            <div class="jcr-value">—</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="job-card-row">
                                    <div class="jcr-icon"><i class="bi bi-calendar3"></i></div>
                                    <div>
                                        <div class="jcr-label">Date Received</div>
                                        <div class="jcr-value"><?= date('M d, Y', strtotime($job['date_received'])) ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="job-card-footer" onclick="event.stopPropagation()">
                                <?php if ($job['status'] === 'Pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="cancel_job">
                                        <input type="hidden" name="job_order_id" value="<?= $job['job_order_id'] ?>">
                                        <button type="submit" class="btn-danger" style="padding:6px 12px; font-size:12px;"
                                            onclick="return confirm('Cancel this job?')">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </button>
                                    </form>
                                    <button class="btn-primary" style="padding:6px 12px; font-size:12px;"
                                        onclick="openStartModal(<?= $job['job_order_id'] ?>)">
                                        <i class="bi bi-play-fill"></i> Start
                                    </button>
                                <?php elseif ($job['status'] === 'Ongoing'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="cancel_job">
                                        <input type="hidden" name="job_order_id" value="<?= $job['job_order_id'] ?>">
                                        <button type="submit" class="btn-danger" style="padding:6px 12px; font-size:12px;"
                                            onclick="return confirm('Cancel this job?')">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="complete_job">
                                        <input type="hidden" name="job_order_id" value="<?= $job['job_order_id'] ?>">
                                        <button type="submit" class="btn-success" style="padding:6px 12px; font-size:12px;"
                                            onclick="return confirm('Mark as completed?')">
                                            <i class="bi bi-check-lg"></i> Complete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn-outline-sm"
                                        onclick="window.location.href='mechanic_dashboard.php?view=<?= $job['job_order_id'] ?>'">
                                        <i class="bi bi-eye"></i> View
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: Recently Completed -->
            <div class="card">
                <div class="card-header">
                    <div>
                        <div class="card-title">Recently Completed</div>
                        <div class="card-sub">Latest finished jobs</div>
                    </div>
                    <a href="mechanic_dashboard.php?status=Completed" class="card-link">View all →</a>
                </div>
                <div>
                    <?php if (empty($recentCompleted)): ?>
                        <div class="empty-state" style="padding:40px 20px;">
                            <div class="empty-icon"><i class="bi bi-check-circle-fill" style="color:#16a34a;"></i></div>
                            <div class="empty-text">No completed jobs yet</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentCompleted as $job): ?>
                        <a href="mechanic_dashboard.php?view=<?= $job['job_order_id'] ?>" class="vehicle-mini-item">
                            <div>
                                <div class="vehicle-mini-name">#<?= str_pad($job['job_order_id'],5,'0',STR_PAD_LEFT) ?> — <?= htmlspecialchars($job['customer_name']) ?></div>
                                <div class="vehicle-mini-plate"><i class="bi bi-car-front"></i> <?= htmlspecialchars($job['vehicle_info']) ?></div>
                                <div class="vehicle-mini-owner"><i class="bi bi-clock"></i> <?= date('M d, Y', strtotime($job['date_completed'])) ?></div>
                            </div>
                            <div class="vehicle-mini-link"><i class="bi bi-arrow-right"></i></div>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</main>

<!-- ── Job Completed Popup ── -->
<div class="popup-overlay" id="completedPopup">
    <div class="popup-box">
        <div class="popup-icon">
            <i class="bi bi-check-lg"></i>
        </div>
        <div class="popup-title">Job Completed!</div>
        <div class="popup-msg"><?= htmlspecialchars($successMsg) ?></div>
        <button class="popup-btn" onclick="closePopup()">Done</button>
    </div>
</div>

<!-- ── Start Job Modal ── -->
<div class="modal-overlay" id="startModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i class="bi bi-play-circle" style="color:var(--accent);"></i> Start Job</h2>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="start_job">
            <input type="hidden" name="job_order_id" id="modal_job_id">
            <div class="modal-body">
                <div class="form-group">
                    <label>Initial Notes (optional)</label>
                    <textarea name="repair_notes" rows="4" placeholder="Write any initial notes before starting..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-sm" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-primary"><i class="bi bi-play-fill"></i> Start Job</button>
            </div>
        </form>
    </div>
</div>

<script>
// Show completed popup if a complete_job was just processed
<?php if (!empty($successMsg) && strpos($successMsg, 'Completed') !== false): ?>
window.addEventListener('DOMContentLoaded', function() {
    document.getElementById('completedPopup').classList.add('open');
});
<?php endif; ?>

function closePopup() {
    document.getElementById('completedPopup').classList.remove('open');
}

function openStartModal(jobId) {
    document.getElementById('modal_job_id').value = jobId;
    document.getElementById('startModal').classList.add('open');
}
function closeModal() {
    document.getElementById('startModal').classList.remove('open');
}
document.getElementById('startModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.getElementById('completedPopup').addEventListener('click', function(e) {
    if (e.target === this) closePopup();
});
</script>
</body>
</html>
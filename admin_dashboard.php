<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/dbconnection.php';

if (!isset($_SESSION['employeeID'])) {
    header("Location: index.php?error=Please log in first");
    exit();
}

$stmt = $conn->prepare("SELECT first_name, last_name, role, is_approved, email FROM employee WHERE employeeID = ?");
$stmt->bind_param("i", $_SESSION['employeeID']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: index.php?error=Account not found");
    exit();
}

// Check if account is approved
if ($user['is_approved'] == 0) {
    session_destroy();
    header("Location: index.php?error=Your account is pending approval");
    exit();
}

// SECURITY CHECK: Verify user has owner or business partner role
$role = $user['role'];
if (!in_array(strtolower($role), ['owner', 'business partner'])) {
    die('Access Denied. You do not have permission to view this page. 
         <br><a href="index.php">Return to Login</a>');
}

// Update session with latest data
$_SESSION['firstname'] = $user['first_name'];
$_SESSION['lastname'] = $user['last_name'];
$_SESSION['role'] = $user['role'];
$_SESSION['user_role'] = $user['role'];
$_SESSION['email'] = $user['email'];

// Get current date and greeting
$todayLabel = date('l, F j, Y');
$hourNow = (int) date('G');
$greeting = ($hourNow < 12) ? 'Good morning' : (($hourNow < 17) ? 'Good afternoon' : 'Good evening');

// Initialize variables
$totalRevenue = 0;
$activeJobs = 0;
$pendingJobs = 0;
$ongoingJobs = 0;
$completedToday = 0;
$completedThisMonth = 0;
$activeWarranties = 0;
$unpaidInvoices = 0;
$activeJobRows = [];
$recentCompletedJobs = [];
$monthlyRevenue = [];
$creditAccounts = [];
$pendingApprovals = 0;

// Vehicle statistics
$totalVehicles = 0;
$recentVehicles = [];

// Notifications data 
$notifications = [];

// Unpaid invoices
try {
    $res = $conn->query("SELECT s.sales_id, CONCAT(c.first_name,' ',c.last_name) AS customer, s.final_amount, s.sales_date
                         FROM sales s JOIN customers c ON s.customer_id = c.customer_id
                         WHERE s.status = 'Unpaid' ORDER BY s.sales_date DESC LIMIT 5");
    while ($res && $row = $res->fetch_assoc()) {
        $notifications[] = [
            'type'    => 'unpaid',
            'icon'    => 'bi-exclamation-circle-fill',
            'color'   => '#f97316',
            'title'   => 'Unpaid Invoice',
            'message' => htmlspecialchars($row['customer']) . ' — ₱' . number_format($row['final_amount'], 2),
            'time'    => $row['sales_date'],
            'link'    => 'sales.php?status=Unpaid',
        ];
    }
} catch (Exception $e) {}

// Pending approvals
try {
    $res = $conn->query("SELECT first_name, last_name, role, created_at FROM employee WHERE is_approved = 0 ORDER BY created_at DESC LIMIT 5");
    while ($res && $row = $res->fetch_assoc()) {
        $notifications[] = [
            'type'    => 'approval',
            'icon'    => 'bi-person-fill-exclamation',
            'color'   => '#2563eb',
            'title'   => 'Pending Approval',
            'message' => htmlspecialchars($row['first_name'].' '.$row['last_name']) . ' (' . htmlspecialchars($row['role']) . ')',
            'time'    => $row['created_at'],
            'link'    => 'admin_approvals.php',
        ];
    }
} catch (Exception $e) {}

// Active job orders notifications
try {
    $res = $conn->query("SELECT jo.job_order_id, CONCAT(c.first_name,' ',c.last_name) AS customer, jo.status, jo.date_received
                         FROM job_orders jo JOIN customers c ON jo.customer_id = c.customer_id
                         WHERE jo.status IN ('Pending','Ongoing') ORDER BY jo.date_received DESC LIMIT 3");
    while ($res && $row = $res->fetch_assoc()) {
        $notifications[] = [
            'type'    => 'job',
            'icon'    => 'bi-wrench-adjustable-circle-fill',
            'color'   => '#16a34a',
            'title'   => 'Active Job #' . str_pad($row['job_order_id'], 5, '0', STR_PAD_LEFT),
            'message' => htmlspecialchars($row['customer']) . ' — ' . htmlspecialchars($row['status']),
            'time'    => $row['date_received'],
            'link'    => 'job_orders.php?view=' . $row['job_order_id'],
        ];
    }
} catch (Exception $e) {}

// Completed jobs today notifications
try {
    $res = $conn->query("SELECT jo.job_order_id, CONCAT(c.first_name,' ',c.last_name) AS customer, jo.date_completed
                         FROM job_orders jo JOIN customers c ON jo.customer_id = c.customer_id
                         WHERE jo.status = 'Completed' AND DATE(jo.date_completed) = CURDATE()
                         ORDER BY jo.date_completed DESC LIMIT 2");
    while ($res && $row = $res->fetch_assoc()) {
        $notifications[] = [
            'type'    => 'completed',
            'icon'    => 'bi-check-circle-fill',
            'color'   => '#10b981',
            'title'   => 'Job Completed Today',
            'message' => 'Job #' . str_pad($row['job_order_id'], 5, '0', STR_PAD_LEFT) . ' - ' . htmlspecialchars($row['customer']),
            'time'    => $row['date_completed'],
            'link'    => 'job_orders.php?view=' . $row['job_order_id'],
        ];
    }
} catch (Exception $e) {}

// Sort by time descending
usort($notifications, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
$notifCount = count($notifications);


// Fetch dashboard data with error handling
if (isset($conn) && $conn) {

    // Total Revenue
    try {
        $res = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS total FROM payments");
        if ($res && $row = $res->fetch_assoc()) {
            $totalRevenue = $row['total'] ?? 0;
        }
    } catch (Exception $e) {
        $totalRevenue = 0;
        error_log("Revenue query failed: " . $e->getMessage());
    }

    // Active Jobs (Pending + Ongoing)
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status IN ('Pending', 'Ongoing')");
        if ($res && $row = $res->fetch_assoc()) {
            $activeJobs = $row['cnt'];
        }
    } catch (Exception $e) {
        $activeJobs = 0;
    }

    // Pending Jobs
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status = 'Pending'");
        if ($res && $row = $res->fetch_assoc()) {
            $pendingJobs = $row['cnt'];
        }
    } catch (Exception $e) {
        $pendingJobs = 0;
    }

    // Ongoing Jobs
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status = 'Ongoing'");
        if ($res && $row = $res->fetch_assoc()) {
            $ongoingJobs = $row['cnt'];
        }
    } catch (Exception $e) {
        $ongoingJobs = 0;
    }

    // Completed Today
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status = 'Completed' AND DATE(date_completed) = CURDATE()");
        if ($res && $row = $res->fetch_assoc()) {
            $completedToday = $row['cnt'];
        }
    } catch (Exception $e) {
        $completedToday = 0;
    }

    // Completed This Month
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status = 'Completed' AND MONTH(date_completed) = MONTH(CURDATE()) AND YEAR(date_completed) = YEAR(CURDATE())");
        if ($res && $row = $res->fetch_assoc()) {
            $completedThisMonth = $row['cnt'];
        }
    } catch (Exception $e) {
        $completedThisMonth = 0;
    }

    // Active Warranties
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM warranties WHERE warranty_end >= CURDATE() AND warranty_status = 'Active'");
        if ($res && $row = $res->fetch_assoc()) {
            $activeWarranties = $row['cnt'];
        }
    } catch (Exception $e) {
        $activeWarranties = 0;
    }

    // Unpaid Invoices
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM sales WHERE status = 'Unpaid'");
        if ($res && $row = $res->fetch_assoc()) {
            $unpaidInvoices = $row['cnt'];
        }
    } catch (Exception $e) {
        $unpaidInvoices = 0;
    }

    // Total Vehicles
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM vehicles");
        if ($res && $row = $res->fetch_assoc()) {
            $totalVehicles = $row['cnt'];
        }
    } catch (Exception $e) {
        $totalVehicles = 0;
    }

    // Recent Vehicles (for quick view)
    try {
        $res = $conn->query("
            SELECT v.*, CONCAT(c.first_name, ' ', c.last_name) AS owner_name
            FROM vehicles v
            LEFT JOIN customers c ON v.customer_id = c.customer_id
            ORDER BY v.vehicle_id DESC
            LIMIT 5
        ");
        while ($res && $row = $res->fetch_assoc()) {
            $recentVehicles[] = $row;
        }
    } catch (Exception $e) {
        $recentVehicles = [];
    }

    // Active jobs table (Pending and Ongoing) - REMOVED PRIORITY
    try {
        $res = $conn->query("
            SELECT jo.job_order_id, 
                   CONCAT(c.first_name, ' ', c.last_name) AS customer,
                   CONCAT(v.brand, ' ', v.model, ' (', v.plate_number, ')') AS vehicle,
                   jo.job_description AS service,
                   jo.status,
                   jo.date_received,
                   CONCAT(e.first_name, ' ', e.last_name) AS mechanic
            FROM job_orders jo
            LEFT JOIN customers c ON jo.customer_id = c.customer_id
            LEFT JOIN vehicles v ON jo.vehicle_id = v.vehicle_id
            LEFT JOIN employee e ON jo.assigned_mechanic = e.employeeID
            WHERE jo.status IN ('Pending', 'Ongoing')
            ORDER BY 
                CASE jo.status
                    WHEN 'Pending' THEN 1
                    WHEN 'Ongoing' THEN 2
                END,
                jo.date_received DESC 
            LIMIT 10
        ");
        while ($res && $row = $res->fetch_assoc()) {
            $activeJobRows[] = $row;
        }
    } catch (Exception $e) {
        $activeJobRows = [];
    }

    // Recent completed jobs
    try {
        $res = $conn->query("
            SELECT jo.job_order_id, 
                   CONCAT(c.first_name, ' ', c.last_name) AS customer,
                   CONCAT(v.brand, ' ', v.model) AS vehicle,
                   jo.job_description AS service,
                   jo.date_completed
            FROM job_orders jo
            LEFT JOIN customers c ON jo.customer_id = c.customer_id
            LEFT JOIN vehicles v ON jo.vehicle_id = v.vehicle_id
            WHERE jo.status = 'Completed'
            ORDER BY jo.date_completed DESC 
            LIMIT 5
        ");
        while ($res && $row = $res->fetch_assoc()) {
            $recentCompletedJobs[] = $row;
        }
    } catch (Exception $e) {
        $recentCompletedJobs = [];
    }

    // Monthly revenue chart
    try {
        $res = $conn->query("
            SELECT DATE_FORMAT(payment_date, '%b') AS month, 
                   COALESCE(SUM(amount_paid), 0) AS total
            FROM payments
            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
            ORDER BY payment_date ASC
        ");
        while ($res && $row = $res->fetch_assoc()) {
            $monthlyRevenue[] = $row;
        }
    } catch (Exception $e) {
        $monthlyRevenue = [];
    }

    // Credit Accounts
    try {
        $res = $conn->query("
            SELECT c.first_name, c.last_name, 
                   ca.current_balance as balance, 
                   ca.credit_limit,
                   ca.due_date
            FROM credit_accounts ca
            JOIN customers c ON ca.customer_id = c.customer_id
            WHERE ca.current_balance > 0
            ORDER BY ca.current_balance DESC LIMIT 5
        ");
        while ($res && $row = $res->fetch_assoc()) {
            if (isset($row['due_date'])) {
                $due_date = new DateTime($row['due_date']);
                $today = new DateTime();
                $interval = $today->diff($due_date);
                $row['days_until_due'] = $due_date > $today ? $interval->days : -$interval->days;
            }
            $creditAccounts[] = $row;
        }
    } catch (Exception $e) {
        $creditAccounts = [];
    }
    
    // Pending approvals count
    try {
        $pa_res = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved = 0");
        if ($pa_res && $r = $pa_res->fetch_assoc()) {
            $pendingApprovals = $r['cnt'];
        }
    } catch (Exception $e) {
        $pendingApprovals = 0;
    }
}

// Vehicles added this month
$newVehiclesThisMonth = 0;
try {
    $nm_res = $conn->query("SELECT COUNT(*) AS cnt FROM vehicles WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    if ($nm_res && $nm_row = $nm_res->fetch_assoc()) {
        $newVehiclesThisMonth = $nm_row['cnt'];
    }
} catch (Exception $e) {
    $newVehiclesThisMonth = 0;
}

// Prepare user data for display
$firstname = htmlspecialchars($user['first_name'] ?? 'Admin');
$userInitials = strtoupper(substr($firstname, 0, 1) . substr($user['last_name'] ?? '', 0, 1));
$userRoleLabel = htmlspecialchars($role ?? 'Staff');
$maxRev = !empty($monthlyRevenue) ? (max(array_column($monthlyRevenue, 'total')) ?: 1) : 1;
$isOwner = strtolower($role) === 'owner';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoBert — Admin Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .notif-count {
            position: absolute;
            top: -6px; right: -6px;
            background: #ef4444;
            color: #fff;
            border-radius: 50%;
            width: 18px; height: 18px;
            font-size: 10px; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #fff;
        }

        .notif-panel {
            display: none;
            position: absolute;
            top: 54px; right: 16px;
            width: 360px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.14);
            z-index: 200;
            overflow: hidden;
            animation: fadeUp 0.2s ease;
        }
        .notif-panel.open { display: block; }

        .notif-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }
        .notif-panel-title {
            font-weight: 700;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text);
        }

        .notif-panel-title i { color: var(--accent); }

        .notif-panel-badge {
            background: var(--accent);
            color: #fff;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 600;
        }

        .notif-panel-body { max-height: 360px;overflow-y: auto; }
        .notif-panel-body::-webkit-scrollbar { width: 4px; }
        .notif-panel-body::-webkit-scrollbar-thumb { background: #e0e0e0; border-radius: 4px; }

        .notif-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 20px;
            border-bottom: 1px solid #f3f4f6;
            text-decoration: none;
            color: inherit;
            transition: background 0.15s;
        }

        .notif-item:hover { background: #f9fafb; }
        .notif-item:last-child { border-bottom: none; }

        .notif-item-icon {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .notif-item-content { flex: 1; min-width: 0; }
        .notif-item-title   { font-size: 13px; font-weight: 600; margin-bottom: 2px; }
        .notif-item-message { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .notif-item-time    { font-size: 11px; color: #bbb; margin-top: 4px; display: flex; align-items: center; gap: 4px; }

        .notif-empty {
            padding: 40px 20px;
            text-align: center;
            color: var(--muted);
        }

        .notif-empty i { font-size: 36px; display: block; margin-bottom: 10px; opacity: .3; }
        .notif-empty p { font-size: 13px; }

        .notif-panel-footer {
            display: flex;
            justify-content: space-between;
            padding: 12px 20px;
            border-top: 1px solid var(--border);
            background: #f9fafb;
        }

        .notif-panel-footer a {
            font-size: 12px;
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }
        
        .notif-panel-footer a:hover { text-decoration: underline; }
        .topbar { position: relative; }
        
        .stat-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }

        /* Vehicle Mini List */
        .vehicle-mini-list {
            margin-top: 16px;
        }
        
        .vehicle-mini-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
        }
        
        .vehicle-mini-item:hover {
            background: #f9fafb;
        }
        
        .vehicle-mini-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .vehicle-mini-name {
            font-weight: 600;
            font-size: 13px;
        }
        
        .vehicle-mini-plate {
            font-size: 11px;
            color: var(--accent);
        }
        
        .vehicle-mini-owner {
            font-size: 11px;
            color: var(--muted);
        }
        
        .vehicle-mini-link {
            color: var(--accent);
            font-size: 12px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: var(--card-radius);
            padding: 20px;
            border: 1px solid var(--border);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card.featured {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
        }
        
        .stat-card.featured .stat-label,
        .stat-card.featured .stat-change {
            color: rgba(255,255,255,0.8);
        }
        
        .stat-icon {
            font-size: 32px;
            margin-bottom: 12px;
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-family: 'Syne', sans-serif;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-change {
            font-size: 12px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .stat-change.up { color: #10b981; }
        .stat-change.down { color: #ef4444; }
        
        /* Stats Subgrid */
        .stats-subgrid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        
        .stat-card.small {
            padding: 16px;
        }
        
        .stat-card.small .stat-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }
        
        .stat-card.small .stat-label {
            font-size: 11px;
        }
        
        .stat-card.small .stat-value {
            font-size: 22px;
            margin-bottom: 4px;
        }
        
        /* Bottom Grid */
        .bottom-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .row-bottom {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        .card {
            background: #fff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }
        
        .card-title {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 16px;
        }
        
        .card-sub {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }
        
        .card-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }
        
        .job-table {
            width: 100%;
        }
        
        .job-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 600;
            background: #f9fafb;
            border-bottom: 1px solid var(--border);
        }
        
        .job-table td {
            padding: 12px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .job-table tr:last-child td {
            border-bottom: none;
        }
        
        .job-table tbody tr {
            cursor: pointer;
            transition: background 0.15s;
        }
        
        .job-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .job-id {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            color: var(--accent);
        }
        
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
        
        .vehicle-info {
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        
        .mechanic-info {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .mechanic-avatar {
            width: 22px;
            height: 22px;
            background: var(--accent);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 10px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        
        .empty-text {
            color: var(--muted);
            font-size: 14px;
        }
        
        /* Credit List */
        .credit-list {
            padding: 8px 0;
        }
        
        .credit-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .credit-row:last-child {
            border-bottom: none;
        }
        
        .credit-name {
            font-weight: 600;
            font-size: 13px;
        }
        
        .credit-limit {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }
        
        .credit-amount {
            font-weight: 700;
            font-size: 14px;
        }
        
        .credit-amount.owed { color: #dc2626; }
        
        .overdue { color: #dc2626; }
        .due-soon { color: #f97316; }
        
        /* Chart */
        .mini-chart {
            padding: 20px;
        }
        
        .chart-bars {
            display: flex;
            justify-content: space-around;
            align-items: flex-end;
            height: 120px;
            margin-top: 20px;
        }
        
        .bar-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 40px;
        }
        
        .bar {
            width: 30px;
            background: #e2e8f0;
            border-radius: 6px 6px 0 0;
            transition: height 0.3s;
        }
        
        .bar.active {
            background: var(--accent);
        }
        
        .bar-label {
            font-size: 11px;
            color: var(--muted);
            margin-top: 8px;
        }
        
        /* Quick Actions */
        .qa-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding: 20px;
        }
        
        .qa-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px 8px;
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }
        
        .qa-btn:hover {
            background: #fff;
            border-color: var(--accent);
            transform: translateY(-2px);
        }
        
        .qa-icon {
            font-size: 24px;
        }
        
        .qa-btn span {
            font-size: 11px;
            font-weight: 600;
            color: var(--text);
        }
        
        /* Greeting */
        .greeting {
            margin-bottom: 24px;
        }
        
        .greeting h1 {
            font-family: 'Syne', sans-serif;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .greeting p {
            color: var(--muted);
            font-size: 14px;
        }
        
        @media (max-width: 1200px) {
            .bottom-grid {
                grid-template-columns: 1fr;
            }
            
            .row-bottom {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-subgrid {
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
            <a class="nav-item active" href="admin_dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a class="nav-item" href="job_orders.php">
                <i class="bi bi-clipboard-data"></i> Job Orders
                <?php if ($activeJobs > 0): ?>
                    <span class="pending-approvals-badge" style="background: var(--accent);"><?= $activeJobs ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-item" href="sales.php">
                <i class="bi bi-currency-dollar"></i> Sales
            </a>
            <a class="nav-item" href="payments.php">
                <i class="bi bi-credit-card"></i> Payments
            </a>
            <a class="nav-item" href="products.php">
                <i class="bi bi-box-seam"></i> Products
            </a>
        </nav>

        <nav class="nav-section">
            <div class="nav-label">Management</div>
            <a class="nav-item" href="customers.php">
                <i class="bi bi-people"></i> Customers
            </a>
            <a class="nav-item" href="vehicles.php">
                <i class="bi bi-truck"></i> Vehicles
                <?php if ($totalVehicles > 0): ?>
                    <span class="pending-approvals-badge" style="background: #10b981;"><?= $totalVehicles ?></span>
                <?php endif; ?>
            </a>
            <?php if ($isOwner): ?>
                <a class="nav-item" href="employees.php">
                    <i class="bi bi-person-badge"></i> Employees
                </a>
                <a class="nav-item" href="admin_approvals.php">
                    <i class="bi bi-check-circle"></i> Approvals
                    <?php if ($pendingApprovals > 0): ?>
                        <span class="pending-approvals-badge"><?= $pendingApprovals ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <a class="nav-item" href="warranties.php">
                <i class="bi bi-shield-check"></i> Warranties
            </a>
            <a class="nav-item" href="credit_accounts.php">
                <i class="bi bi-wallet2"></i> Credit Accounts
            </a>
        </nav>

        <?php if ($isOwner): ?>
        <nav class="nav-section">
            <div class="nav-label">Owner</div>
            <a class="nav-item" href="reports.php">
                <i class="bi bi-bar-chart-line"></i> Reports
            </a>
        </nav>
        <?php endif; ?>

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

    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <span class="page-title">Dashboard</span>
                <span class="breadcrumb">Overview & Analytics</span>
            </div>

            <div class="search-bar">
                <i class="bi bi-search"></i>
                <input type="text" placeholder="Search customers, jobs, vehicles...">
            </div>

            <div class="topbar-right">
                <!-- Notification Bell -->
                <div class="icon-btn notif-trigger" onclick="toggleNotifPanel()" id="notifBtn" style="position:relative;">
                    <i class="bi bi-bell"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-count"><?= $notifCount ?></span>
                    <?php endif; ?>
                </div>

                <!-- Notification Panel -->
                <div class="notif-panel" id="notifPanel">
                    <div class="notif-panel-header">
                        <span class="notif-panel-title"><i class="bi bi-bell-fill"></i> Notifications</span>
                        <?php if ($notifCount > 0): ?>
                            <span class="notif-panel-badge"><?= $notifCount ?> new</span>
                        <?php endif; ?>
                    </div>
                    <div class="notif-panel-body">
                        <?php if (empty($notifications)): ?>
                            <div class="notif-empty">
                                <i class="bi bi-bell-slash"></i>
                                <p>You're all caught up!</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                            <a href="<?= $n['link'] ?>" class="notif-item">
                                <div class="notif-item-icon" style="background: <?= $n['color'] ?>22; color: <?= $n['color'] ?>;">
                                    <i class="bi <?= $n['icon'] ?>"></i>
                                </div>
                                <div class="notif-item-content">
                                    <div class="notif-item-title"><?= $n['title'] ?></div>
                                    <div class="notif-item-message"><?= $n['message'] ?></div>
                                    <div class="notif-item-time">
                                        <i class="bi bi-clock"></i>
                                        <?php
                                        $diff = time() - strtotime($n['time']);
                                        if ($diff < 60)          echo 'Just now';
                                        elseif ($diff < 3600)    echo floor($diff/60) . 'm ago';
                                        elseif ($diff < 86400)   echo floor($diff/3600) . 'h ago';
                                        else                     echo date('M d, Y', strtotime($n['time']));
                                        ?>
                                    </div>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($notifCount > 0): ?>
                    <div class="notif-panel-footer">
                        <a href="admin_approvals.php">View all approvals →</a>
                        <a href="sales.php?status=Unpaid">View unpaid →</a>
                    </div>
                    <?php endif; ?>
                </div>
                <button class="btn-primary" onclick="window.location.href='job_orders.php'">
                    <i class="bi bi-plus-lg"></i> New Job Order
                </button>
                <button class="logout-btn" onclick="window.location.href='logout.php'">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </header>

        <div class="content">
            <div class="greeting">
                <h1><?= $greeting ?>, <?= $firstname ?>!</h1>
                <p><?= $todayLabel ?></p>
            </div>

            <!-- Main Stats Grid -->
            <div class="stats-grid">
                <a href="reports.php?report=revenue" class="stat-link">
                    <div class="stat-card featured">
                        <div class="stat-icon">💰</div>
                        <div class="stat-label">Total Revenue</div>
                        <div class="stat-value">₱<?= number_format($totalRevenue, 2) ?></div>
                        <div class="stat-change">
                            <i class="bi bi-arrow-up"></i> Lifetime earnings
                        </div>
                    </div>
                </a>

                <a href="job_orders.php?status=pending" class="stat-link">
                    <div class="stat-card">
                        <div class="stat-icon">⏳</div>
                        <div class="stat-label">Pending Jobs</div>
                        <div class="stat-value"><?= $pendingJobs ?></div>
                        <div class="stat-change <?= $pendingJobs > 0 ? 'up' : 'neutral' ?>">
                            <?= $pendingJobs ?> waiting to start
                        </div>
                    </div>
                </a>

                <a href="job_orders.php?status=ongoing" class="stat-link">
                    <div class="stat-card">
                        <div class="stat-icon">🔧</div>
                        <div class="stat-label">Ongoing Jobs</div>
                        <div class="stat-value"><?= $ongoingJobs ?></div>
                        <div class="stat-change <?= $ongoingJobs > 0 ? 'up' : 'neutral' ?>">
                            <?= $ongoingJobs ?> in progress
                        </div>
                    </div>
                </a>

                <a href="job_orders.php?status=completed&date=today" class="stat-link">
                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-label">Completed Today</div>
                        <div class="stat-value"><?= $completedToday ?></div>
                        <div class="stat-change <?= $completedToday > 0 ? 'up' : 'neutral' ?>">
                            <?= $completedToday > 0 ? 'Great progress!' : 'No completions yet' ?>
                        </div>
                    </div>
                </a>

                <a href="job_orders.php?status=completed&month=current" class="stat-link">
                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-label">Completed This Month</div>
                        <div class="stat-value"><?= $completedThisMonth ?></div>
                        <div class="stat-change">
                            <i class="bi bi-calendar"></i> This month
                        </div>
                    </div>
                </a>
            </div>

            <!-- Vehicle Statistics Section -->
            <div class="stats-subgrid">
                <a href="vehicles.php" class="stat-link">
                    <div class="stat-card small">
                        <div class="stat-icon">🚗</div>
                        <div class="stat-label">Total Vehicles</div>
                        <div class="stat-value"><?= $totalVehicles ?></div>
                        <div class="stat-change">
                            <i class="bi bi-truck"></i> Registered in system
                        </div>
                    </div>
                </a>
                
                <a href="vehicles.php?new=this-month" class="stat-link">
                    <div class="stat-card small">
                        <div class="stat-icon">📝</div>
                        <div class="stat-label">New Vehicles</div>
                        <div class="stat-value"><?= $newVehiclesThisMonth ?></div>
                        <div class="stat-change">
                            <i class="bi bi-calendar"></i> Added this month
                        </div>
                    </div>
                </a>

                <a href="warranties.php" class="stat-link">
                    <div class="stat-card small">
                        <div class="stat-icon">🛡️</div>
                        <div class="stat-label">Active Warranties</div>
                        <div class="stat-value"><?= $activeWarranties ?></div>
                        <div class="stat-change">
                            <i class="bi bi-shield-check"></i> Active warranties
                        </div>
                    </div>
                </a>

                <a href="sales.php?status=unpaid" class="stat-link">
                    <div class="stat-card small">
                        <div class="stat-icon">⚠️</div>
                        <div class="stat-label">Unpaid Invoices</div>
                        <div class="stat-value"><?= $unpaidInvoices ?></div>
                        <div class="stat-change <?= $unpaidInvoices > 0 ? 'down' : 'neutral' ?>">
                            <?= $unpaidInvoices > 0 ? 'Requires attention' : 'All paid' ?>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Bottom Grid -->
            <div class="bottom-grid">
                <!-- Active Jobs Table (Pending and Ongoing) -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Active Job Orders</div>
                            <div class="card-sub">Pending and ongoing jobs</div>
                        </div>
                        <a class="card-link" href="job_orders.php">View all →</a>
                    </div>

                    <table class="job-table">
                        <thead>
                            <tr>
                                <th>Job #</th>
                                <th>Customer / Vehicle</th>
                                <th>Service</th>
                                <th>Mechanic</th>
                                <th>Status</th>
                                <th>Date Received</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activeJobRows)): ?>
                                <tr>
                                    <td colspan="6">
                                        <div class="empty-state">
                                            <div class="empty-icon">🔧</div>
                                            <div class="empty-text">No active job orders</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activeJobRows as $job): ?>
                                    <tr onclick="window.location.href='job_orders.php?view=<?= $job['job_order_id'] ?>'">
                                        <td>
                                            <span class="job-id">#<?= str_pad($job['job_order_id'], 5, '0', STR_PAD_LEFT) ?></span>
                                        </td>
                                        <td>
                                            <div><?= htmlspecialchars($job['customer']) ?></div>
                                            <div class="vehicle-info"><?= htmlspecialchars($job['vehicle']) ?></div>
                                        </td>
                                        <td><?= htmlspecialchars(substr($job['service'], 0, 30)) ?><?= strlen($job['service']) > 30 ? '...' : '' ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($job['mechanic'])): ?>
                                                <div class="mechanic-info">
                                                    <div class="mechanic-avatar">
                                                        <?= strtoupper(substr($job['mechanic'], 0, 1)) ?>
                                                    </div>
                                                    <?= htmlspecialchars($job['mechanic']) ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #9ca3af;">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($job['status']) ?>">
                                                <?= htmlspecialchars($job['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($job['date_received'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Recent Vehicles Card -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Recent Vehicles</div>
                            <div class="card-sub">Latest registered vehicles</div>
                        </div>
                        <a class="card-link" href="vehicles.php">View all →</a>
                    </div>
                    
                    <div class="vehicle-mini-list">
                        <?php if (empty($recentVehicles)): ?>
                            <div class="empty-state" style="padding: 32px;">
                                <div class="empty-icon">🚗</div>
                                <div class="empty-text">No vehicles registered yet</div>
                                <button class="btn-primary" style="margin-top: 16px;" onclick="window.location.href='vehicles.php'">
                                    Register Vehicle
                                </button>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentVehicles as $vehicle): ?>
                                <a href="vehicles.php?view=<?= $vehicle['vehicle_id'] ?>" class="vehicle-mini-item">
                                    <div class="vehicle-mini-info">
                                        <span class="vehicle-mini-name">
                                            <?= htmlspecialchars($vehicle['brand'] . ' ' . $vehicle['model']) ?>
                                        </span>
                                        <span class="vehicle-mini-plate">
                                            <i class="bi bi-upc-scan"></i> <?= htmlspecialchars($vehicle['plate_number']) ?>
                                        </span>
                                        <span class="vehicle-mini-owner">
                                            <i class="bi bi-person-circle"></i> <?= htmlspecialchars($vehicle['owner_name'] ?? 'Unknown') ?>
                                        </span>
                                    </div>
                                    <div class="vehicle-mini-link">
                                        <i class="bi bi-arrow-right"></i>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                            
                            <div style="padding: 12px; text-align: center; border-top: 1px solid var(--border); margin-top: 8px;">
                                <a href="vehicles.php" style="color: var(--accent); text-decoration: none; font-size: 12px; font-weight: 600;">
                                    Manage All Vehicles <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Bottom Row -->
            <div class="row-bottom">
                <!-- Monthly Revenue Chart -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Revenue Overview</div>
                            <div class="card-sub">Last 6 months</div>
                        </div>
                        <a class="card-link" href="reports.php">Full Report →</a>
                    </div>
                    <div class="mini-chart">
                        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 16px;">
                            <div>
                                <div style="font-family:'Syne',sans-serif; font-size: 24px; font-weight: 700;">
                                    ₱<?= number_format($totalRevenue, 2) ?>
                                </div>
                                <div style="font-size: 12px; color: var(--muted);">Total revenue</div>
                            </div>
                        </div>
                        <div class="chart-bars">
                            <?php if (empty($monthlyRevenue)): ?>
                                <?php
                                $last6Months = [];
                                for ($i = 5; $i >= 0; $i--) {
                                    $last6Months[] = date('M', strtotime("-$i months"));
                                }
                                foreach ($last6Months as $month): ?>
                                    <div class="bar-wrap">
                                        <div class="bar" style="height: 20px;"></div>
                                        <span class="bar-label"><?= $month ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php foreach ($monthlyRevenue as $m):
                                    $pct = max(20, min(80, round(($m['total'] / $maxRev) * 80)));
                                    ?>
                                    <div class="bar-wrap">
                                        <div class="bar active" style="height: <?= $pct ?>px;"
                                            title="₱<?= number_format($m['total'], 2) ?>">
                                        </div>
                                        <span class="bar-label"><?= htmlspecialchars($m['month']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Completed Jobs -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Recently Completed</div>
                            <div class="card-sub">Latest finished jobs</div>
                        </div>
                        <a class="card-link" href="job_orders.php?status=completed">View all →</a>
                    </div>
                    
                    <div class="vehicle-mini-list">
                        <?php if (empty($recentCompletedJobs)): ?>
                            <div class="empty-state" style="padding: 32px;">
                                <div class="empty-icon">✅</div>
                                <div class="empty-text">No completed jobs yet</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentCompletedJobs as $job): ?>
                                <a href="job_orders.php?view=<?= $job['job_order_id'] ?>" class="vehicle-mini-item">
                                    <div class="vehicle-mini-info">
                                        <span class="vehicle-mini-name">
                                            #<?= str_pad($job['job_order_id'], 5, '0', STR_PAD_LEFT) ?> - <?= htmlspecialchars($job['customer']) ?>
                                        </span>
                                        <span class="vehicle-mini-plate">
                                            <i class="bi bi-truck"></i> <?= htmlspecialchars($job['vehicle']) ?>
                                        </span>
                                        <span class="vehicle-mini-owner">
                                            <i class="bi bi-clock"></i> <?= date('M d, Y', strtotime($job['date_completed'])) ?>
                                        </span>
                                    </div>
                                    <div class="vehicle-mini-link">
                                        <i class="bi bi-arrow-right"></i>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card" style="margin-top: 24px;">
                <div class="card-header">
                    <div class="card-title">Quick Actions</div>
                </div>
                <div class="qa-grid">
                    <button class="qa-btn" onclick="window.location.href='job_orders.php'">
                        <div class="qa-icon">📋</div>
                        <span>New Job Order</span>
                    </button>
                    <button class="qa-btn" onclick="window.location.href='payments.php'">
                        <div class="qa-icon">💳</div>
                        <span>Record Payment</span>
                    </button>
                    <button class="qa-btn" onclick="window.location.href='customers.php?action=add'">
                        <div class="qa-icon">👤</div>
                        <span>Add Customer</span>
                    </button>
                    <button class="qa-btn" onclick="window.location.href='vehicles.php?action=add'">
                        <div class="qa-icon">🚗</div>
                        <span>Add Vehicle</span>
                    </button>
                    <button class="qa-btn" onclick="window.location.href='products.php?action=add'">
                        <div class="qa-icon">📦</div>
                        <span>Add Product</span>
                    </button>
                    <button class="qa-btn" onclick="window.location.href='sales.php?action=create'">
                        <div class="qa-icon">📄</div>
                        <span>Create Invoice</span>
                    </button>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleNotifPanel() {
            const panel = document.getElementById('notifPanel');
            panel.classList.toggle('open');
        }

        // Close panel when clicking outside
        document.addEventListener('click', function(e) {
            const panel  = document.getElementById('notifPanel');
            const btn    = document.getElementById('notifBtn');
            if (panel && !panel.contains(e.target) && !btn.contains(e.target)) {
                panel.classList.remove('open');
            }
        });

        // Auto-refresh data every 5 minutes (300000 ms)
        setTimeout(function () {
            location.reload();
        }, 300000);
    </script>
</body>

</html>
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

// SECURITY CHECK 4: Verify user has owner or business partner role
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
$completedToday = 0;
$activeWarranties = 0;
$unpaidInvoices = 0;
$activeJobRows = [];
$monthlyRevenue = [];
$creditAccounts = [];
$pendingApprovals = 0;

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

// Active job orders
try {
    $res = $conn->query("SELECT jo.new_job_order_id, CONCAT(c.first_name,' ',c.last_name) AS customer, jo.status, jo.date_received
                         FROM new_job_order jo JOIN customers c ON jo.customer_id = c.customer_id
                         WHERE jo.status IN ('Pending','Ongoing') ORDER BY jo.date_received DESC LIMIT 3");
    while ($res && $row = $res->fetch_assoc()) {
        $notifications[] = [
            'type'    => 'job',
            'icon'    => 'bi-wrench-adjustable-circle-fill',
            'color'   => '#16a34a',
            'title'   => 'Active Job #' . str_pad($row['job_order_id'], 5, '0', STR_PAD_LEFT),
            'message' => htmlspecialchars($row['customer']) . ' — ' . htmlspecialchars($row['status']),
            'time'    => $row['date_received'],
            'link'    => 'new_job_order.php',
        ];
    }
} catch (Exception $e) {}

// Sort by time descending
usort($notifications, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
$notifCount = count($notifications);


// Fetch dashboard data with error handling
if (isset($conn) && $conn) {

    // Total Revenue - with error handling
    try {
        $res = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS total FROM payments");
        if ($res && $row = $res->fetch_assoc()) {
            $totalRevenue = $row['total'] ?? 0;
        }
    } catch (Exception $e) {
        $totalRevenue = 0;
        error_log("Revenue query failed: " . $e->getMessage());
    }

    // Active Jobs
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM new_job_order WHERE status != 'Completed' AND status != 'Cancelled'");
        if ($res && $row = $res->fetch_assoc()) {
            $activeJobs = $row['cnt'];
        }
    } catch (Exception $e) {
        $activeJobs = 0;
    }

    // Completed Today
    try {
        $res = $conn->query("SELECT COUNT(*) AS cnt FROM new_job_order WHERE status = 'Completed' AND DATE(date_completed) = CURDATE()");
        if ($res && $row = $res->fetch_assoc()) {
            $completedToday = $row['cnt'];
        }
    } catch (Exception $e) {
        $completedToday = 0;
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

    // Active jobs table
    try {
        $res = $conn->query("
            SELECT jo.job_order_id, 
                   CONCAT(c.first_name, ' ', c.last_name) AS customer,
                   CONCAT(v.brand, ' ', v.model) AS vehicle,
                   jo.job_description AS service,
                   jo.status
            FROM new_job_order jo
            LEFT JOIN customers c ON jo.customer_id = c.customer_id
            LEFT JOIN vehicles v ON jo.vehicle_id = v.vehicle_id
            WHERE jo.status != 'Completed' AND jo.status != 'Cancelled'
            ORDER BY jo.date_received DESC LIMIT 10
        ");
        while ($res && $row = $res->fetch_assoc()) {
            $activeJobRows[] = $row;
        }
    } catch (Exception $e) {
        $activeJobRows = [];
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
                   ca.credit_limit
            FROM credit_accounts ca
            JOIN customers c ON ca.customer_id = c.customer_id
            WHERE ca.current_balance > 0
            ORDER BY ca.current_balance DESC LIMIT 5
        ");
        while ($res && $row = $res->fetch_assoc()) {
            $creditAccounts[] = $row;
        }
    } catch (Exception $e) {
        $creditAccounts = [];
    }
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
            background: var(--red);
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
            <a class="nav-item" href="new_job_order.php">
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

        <nav class="nav-section">
            <div class="nav-label">Owner</div>
            <a class="nav-item" href="reports.php">
                <i class="bi bi-bar-chart-line"></i> Reports
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-row" onclick="toggleUserMenu()">
                <div class="avatar"><?= $userInitials ?></div>
                <div>
                    <div class="user-name"><?= $firstname ?></div>
                    <div class="user-role"><?= $userRoleLabel ?></div>
                </div>
                <div class="user-more">
                    <i class="bi bi-three-dots"></i>
                </div>
            </div>
            <!-- Simple logout option -->
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
                        <div class="notif-dot"></div>
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
                <button class="btn-primary" onclick="window.location.href='new_job_order.php'">
                    <i class="bi bi-plus-lg"></i> New Job Order
                </button>
                <button class="logout-btn" onclick="window.location.href='logout.php'">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </header>

        <div class="content">
            <div class="greeting">
                <h1><?= $greeting ?>, <?= $firstname ?>! 👋</h1>
                <p><?= $todayLabel ?></p>
            </div>

            <!-- Stats Grid -->
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

                <a href="new_job_order.php?status=active" class="stat-link">
                    <div class="stat-card">
                        <div class="stat-icon">🔧</div>
                        <div class="stat-label">Active Jobs</div>
                        <div class="stat-value"><?= $activeJobs ?></div>
                        <div class="stat-change <?= $activeJobs > 0 ? 'up' : 'neutral' ?>">
                            <?= $activeJobs ?> jobs in progress
                        </div>
                    </div>
                </a>

                <a href="new_job_order.php?status=completed&date=today" class="stat-link">
                    <div class="stat-card">
                        <div class="stat-icon">✅</div>
                        <div class="stat-label">Completed Today</div>
                        <div class="stat-value"><?= $completedToday ?></div>
                        <div class="stat-change <?= $completedToday > 0 ? 'up' : 'neutral' ?>">
                            <?= $completedToday > 0 ? 'Great progress!' : 'No completions yet' ?>
                        </div>
                    </div>
                </a>

                <a href="warranties.php" class="stat-link">
                    <div class="stat-card">
                        <div class="stat-icon">🛡️</div>
                        <div class="stat-label">Active Warranties</div>
                        <div class="stat-value"><?= $activeWarranties ?></div>
                        <div class="stat-change">
                            <?= $activeWarranties ?> active warranties
                        </div>
                    </div>
                </a>

                <a href="sales.php?status=unpaid" class="stat-link">
                    <div class="stat-card">
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
                <!-- Active Jobs Table -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Active Job Orders</div>
                            <div class="card-sub">Currently in progress</div>
                        </div>
                        <a class="card-link" href="new_job_order.php">View all →</a>
                    </div>

                    <table class="job-table">
                        <thead>
                            <tr>
                                <th>Job #</th>
                                <th>Customer / Vehicle</th>
                                <th>Service</th>
                                <th>Mechanic</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activeJobRows)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="empty-state">
                                            <div class="empty-icon">🔧</div>
                                            <div class="empty-text">No active job orders</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activeJobRows as $job): ?>
                                    <tr onclick="window.location.href='job_details.php?id=<?= $job['job_order_id'] ?>'"
                                        style="cursor: pointer;">
                                        <td>
                                            <span
                                                class="job-id">#<?= str_pad($job['job_order_id'], 5, '0', STR_PAD_LEFT) ?></span>
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
                                            <span
                                                class="status-badge status-<?= strtolower(str_replace(' ', '-', $job['status'])) ?>">
                                                <?= htmlspecialchars($job['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">Quick Actions</div>
                    </div>
                    <div class="qa-grid">
                        <button class="qa-btn" onclick="window.location.href='new_job_order.php'">
                            <div class="qa-icon">📋</div>
                            New Job Order
                        </button>
                        <button class="qa-btn" onclick="window.location.href='payments.php'">
                            <div class="qa-icon">💳</div>
                            Record Payment
                        </button>
                        <button class="qa-btn" onclick="window.location.href='add_customer.php'">
                            <div class="qa-icon">👤</div>
                            Add Customer
                        </button>
                        <button class="qa-btn" onclick="window.location.href='add_vehicle.php'">
                            <div class="qa-icon">🚗</div>
                            Add Vehicle
                        </button>
                        <button class="qa-btn" onclick="window.location.href='products.php?action=add'">
                            <div class="qa-icon">📦</div>
                            Add Product
                        </button>
                        <button class="qa-btn" onclick="window.location.href='create_invoice.php'">
                            <div class="qa-icon">📄</div>
                            Create Invoice
                        </button>
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
                        <div
                            style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 16px;">
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

                <!-- Credit Accounts -->
                <div class="card">
                    <div class="card-header">
                        <div>
                            <div class="card-title">Credit Accounts</div>
                            <div class="card-sub">Outstanding balances</div>
                        </div>
                        <a class="card-link" href="credit_accounts.php">Manage →</a>
                    </div>
                    <div class="credit-list">
                        <?php if (empty($creditAccounts)): ?>
                            <div class="empty-state" style="padding: 32px 0;">
                                <div class="empty-icon">💳</div>
                                <div class="empty-text">No credit accounts with balance</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($creditAccounts as $ca):
                                $balance = floatval($ca['current_balance']);
                                $limit = floatval($ca['credit_limit']);
                                $usage_percent = $limit > 0 ? ($balance / $limit) * 100 : 0;
                                $days_until_due = $ca['days_until_due'] ?? 30;
                                $due_class = $days_until_due < 0 ? 'overdue' : ($days_until_due < 7 ? 'due-soon' : '');
                                ?>
                                <div class="credit-row">
                                    <div>
                                        <div class="credit-name">
                                            <?= htmlspecialchars($ca['first_name'] . ' ' . $ca['last_name']) ?>
                                        </div>
                                        <div class="credit-limit">
                                            Limit: ₱<?= number_format($limit, 2) ?>
                                            <span style="margin-left: 8px; font-size: 10px;">
                                                <?= number_format($usage_percent, 1) ?>% used
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="credit-amount <?= $balance > 0 ? 'owed' : 'credit' ?>">
                                            ₱<?= number_format($balance, 2) ?>
                                        </div>
                                        <?php if (isset($ca['due_date'])): ?>
                                            <div class="<?= $due_class ?>" style="font-size: 10px; text-align: right;">
                                                <?php if ($days_until_due < 0): ?>
                                                    Overdue by <?= abs($days_until_due) ?> days
                                                <?php elseif ($days_until_due == 0): ?>
                                                    Due today
                                                <?php else: ?>
                                                    <?= $days_until_due ?> days left
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        function toggleUserMenu() {
            // You can implement a dropdown menu here
            console.log('User menu clicked');
        }

        // Auto-refresh data every 5 minutes (300000 ms)
        setTimeout(function () {
            location.reload();
        }, 300000);
    </script>
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
    </script>
</body>

</html>
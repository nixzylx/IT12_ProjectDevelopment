<?php
// warranties.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/dbconnection.php';

if (!isset($_SESSION['employeeID'])) {
    header("Location: index.php?error=Please log in first");
    exit();
}

$stmt = $conn->prepare("SELECT first_name, last_name, role, is_approved FROM employee WHERE employeeID = ?");
$stmt->bind_param("i", $_SESSION['employeeID']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || $user['is_approved'] == 0) {
    session_destroy();
    header("Location: index.php?error=Unauthorized access");
    exit();
}

$role = $user['role'];
$firstname = htmlspecialchars($user['first_name'] ?? 'User');
$userInitials = strtoupper(substr($firstname, 0, 1) . substr($user['last_name'] ?? '', 0, 1));
$userRoleLabel = htmlspecialchars($role ?? 'Staff');
$isOwner = strtolower($role) === 'owner';
$isBusinessPartner = strtolower($role) === 'business partner';

// Auto-update expired warranties
$conn->query("UPDATE warranties SET warranty_status = 'Expired' WHERE warranty_end < CURDATE() AND warranty_status = 'Active'");

// Handle warranty status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status' && isset($_POST['warranty_id'])) {
        $warranty_id = $_POST['warranty_id'];
        $new_status = $_POST['status'];
        
        $stmt = $conn->prepare("UPDATE warranties SET warranty_status = ? WHERE warranty_id = ?");
        $stmt->bind_param("si", $new_status, $warranty_id);
        
        if ($stmt->execute()) {
            $message = "Warranty status updated successfully!";
        } else {
            $error = "Error updating warranty status: " . $conn->error;
        }
        $stmt->close();
    }
    
    // Handle warranty claim
    if ($_POST['action'] === 'claim' && isset($_POST['warranty_id'])) {
        $warranty_id = $_POST['warranty_id'];
        $claim_notes = trim($_POST['claim_notes']);
        
        $stmt = $conn->prepare("UPDATE warranties SET warranty_status = 'Claimed', claim_date = CURDATE(), claim_notes = ? WHERE warranty_id = ?");
        $stmt->bind_param("si", $claim_notes, $warranty_id);
        
        if ($stmt->execute()) {
            $message = "Warranty claimed successfully!";
        } else {
            $error = "Error claiming warranty: " . $conn->error;
        }
        $stmt->close();
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$customer_filter = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build WHERE clause
$where = [];
$params = [];
$types = '';

if (!empty($status_filter)) {
    $where[] = "w.warranty_status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($customer_filter > 0) {
    $where[] = "w.customer_id = ?";
    $params[] = $customer_filter;
    $types .= "i";
}

if (!empty($date_from)) {
    $where[] = "w.warranty_start >= ?";
    $params[] = $date_from;
    $types .= "s";
}

if (!empty($date_to)) {
    $where[] = "w.warranty_end <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM warranties w $where_clause";
$countStmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalWarranties = $countStmt->get_result()->fetch_assoc()['total'];
$totalPages = ceil($totalWarranties / $limit);
$countStmt->close();

// Get warranties with details
$query = "
    SELECT 
        w.*,
        CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
        c.contact_number,
        p.product_name,
        p.serial_number,
        s.sales_date,
        DATEDIFF(w.warranty_end, CURDATE()) AS days_remaining
    FROM warranties w
    JOIN customers c ON w.customer_id = c.customer_id
    JOIN products p ON w.product_id = p.product_id
    JOIN sales s ON w.sales_id = s.sales_id
    $where_clause
    ORDER BY 
        CASE 
            WHEN w.warranty_status = 'Active' AND w.warranty_end < CURDATE() THEN 0
            WHEN w.warranty_status = 'Active' THEN 1
            WHEN w.warranty_status = 'Expired' THEN 2
            WHEN w.warranty_status = 'Claimed' THEN 3
        END,
        w.warranty_end ASC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$warranties = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get customers for filter dropdown
$customers = $conn->query("SELECT customer_id, first_name, last_name FROM customers ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats = [
    'active' => 0,
    'expiring_soon' => 0,
    'expired' => 0,
    'claimed' => 0
];

$statsQuery = "
    SELECT 
        SUM(CASE WHEN warranty_status = 'Active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN warranty_status = 'Active' AND warranty_end BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon,
        SUM(CASE WHEN warranty_status = 'Expired' THEN 1 ELSE 0 END) as expired,
        SUM(CASE WHEN warranty_status = 'Claimed' THEN 1 ELSE 0 END) as claimed
    FROM warranties
";
$statsResult = $conn->query($statsQuery)->fetch_assoc();
$stats = array_merge($stats, $statsResult);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoBert — Warranty Management</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .warranty-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }
        
        .stat-card.active { border-left: 4px solid #10b981; }
        .stat-card.expiring { border-left: 4px solid #f59e0b; }
        .stat-card.expired { border-left: 4px solid #ef4444; }
        .stat-card.claimed { border-left: 4px solid #6b7280; }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label {
            color: var(--muted);
            font-size: 13px;
        }
        
        .filter-section {
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filter-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
        }
        
        .filter-actions {
            display: flex;
            gap: 8px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-active {
            background: #10b98120;
            color: #10b981;
        }
        
        .status-expired {
            background: #ef444420;
            color: #ef4444;
        }
        
        .status-claimed {
            background: #6b728020;
            color: #6b7280;
        }
        
        .expiring-soon {
            background: #f59e0b20;
            color: #f59e0b;
            font-weight: 600;
        }
        
        .warranty-days {
            font-size: 12px;
            font-weight: 500;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: #fff;
            margin: 50px auto;
            padding: 0;
            width: 500px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--muted);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            text-align: right;
            background: #f9fafb;
        }
        
        .warranty-detail {
            background: #f9fafb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .detail-label {
            width: 120px;
            font-size: 13px;
            color: var(--muted);
        }
        
        .detail-value {
            font-size: 13px;
            font-weight: 500;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }
        
        .btn-primary {
            background: var(--accent);
            color: #fff;
        }
        
        .btn-primary:hover {
            background: var(--accent-dark);
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: var(--text);
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .btn-danger {
            background: #ef4444;
            color: #fff;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-dashboard {
            background: #4f46e5;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
        }
        
        .btn-dashboard:hover {
            background: #4338ca;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .icon-btn-sm {
            padding: 6px 10px;
            border: 1px solid var(--border);
            background: #fff;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            color: var(--text);
        }
        
        .icon-btn-sm:hover {
            background: #f9fafb;
            border-color: var(--accent);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert-success {
            background: #10b98120;
            color: #10b981;
        }
        
        .alert-error {
            background: #ef444420;
            color: #ef4444;
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
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            resize: vertical;
            min-height: 80px;
        }
        
        textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
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
            <a class="nav-item" href="admin_dashboard.php">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a class="nav-item" href="new_job_order.php">
                <i class="bi bi-clipboard-data"></i> Job Orders
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
                </a>
            <?php endif; ?>
            <a class="nav-item active" href="warranties.php">
                <i class="bi bi-shield-check"></i> Warranties
            </a>
            <a class="nav-item" href="credit_accounts.php">
                <i class="bi bi-wallet2"></i> Credit Accounts
            </a>
        </nav>

        <?php if ($isOwner || $isBusinessPartner): ?>
        <nav class="nav-section">
            <div class="nav-label">Reports</div>
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
                <span class="page-title">Warranty Management</span>
                <span class="breadcrumb">Track and manage product warranties</span>
            </div>

            <div class="topbar-right">
                <button class="btn-dashboard" onclick="window.location.href='admin_dashboard.php'">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </button>
                <button class="logout-btn" onclick="window.location.href='logout.php'">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </header>

        <div class="content">
            <?php if (isset($message)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="warranty-stats">
                <div class="stat-card active">
                    <div class="stat-value"><?= $stats['active'] ?></div>
                    <div class="stat-label">Active Warranties</div>
                </div>
                <div class="stat-card expiring">
                    <div class="stat-value"><?= $stats['expiring_soon'] ?></div>
                    <div class="stat-label">Expiring in 30 Days</div>
                </div>
                <div class="stat-card expired">
                    <div class="stat-value"><?= $stats['expired'] ?></div>
                    <div class="stat-label">Expired</div>
                </div>
                <div class="stat-card claimed">
                    <div class="stat-value"><?= $stats['claimed'] ?></div>
                    <div class="stat-label">Claimed</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" style="display: flex; gap: 16px; width: 100%; flex-wrap: wrap;">
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Expired" <?= $status_filter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                            <option value="Claimed" <?= $status_filter === 'Claimed' ? 'selected' : '' ?>>Claimed</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Customer</label>
                        <select name="customer_id">
                            <option value="">All Customers</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= $customer['customer_id'] ?>" <?= $customer_filter == $customer['customer_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="filter-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-primary">Apply Filters</button>
                        <a href="warranties.php" class="btn-secondary" style="text-decoration: none; display: inline-block; padding: 8px 16px;">Clear</a>
                    </div>
                </form>
            </div>

            <!-- Warranties Table -->
            <div class="card">
                <table class="job-table">
                    <thead>
                        <tr>
                            <th>Warranty ID</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Serial #</th>
                            <th>Purchase Date</th>
                            <th>Warranty Period</th>
                            <th>Status</th>
                            <th>Days Left</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($warranties)): ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <div class="empty-icon">🛡️</div>
                                        <div class="empty-text">No warranties found</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($warranties as $warranty): 
                                $days_remaining = $warranty['days_remaining'];
                                $is_expiring_soon = $days_remaining > 0 && $days_remaining <= 30;
                            ?>
                                <tr>
                                    <td>#<?= str_pad($warranty['warranty_id'], 5, '0', STR_PAD_LEFT) ?></td>
                                    <td>
                                        <div style="font-weight: 500;"><?= htmlspecialchars($warranty['customer_name']) ?></div>
                                        <div style="font-size: 11px; color: var(--muted);"><?= htmlspecialchars($warranty['contact_number']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($warranty['product_name']) ?></td>
                                    <td><?= htmlspecialchars($warranty['serial_number'] ?: 'N/A') ?></td>
                                    <td><?= date('M d, Y', strtotime($warranty['sales_date'])) ?></td>
                                    <td>
                                        <?= date('M d, Y', strtotime($warranty['warranty_start'])) ?><br>
                                        <small>to <?= date('M d, Y', strtotime($warranty['warranty_end'])) ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($warranty['warranty_status']) ?>">
                                            <?= $warranty['warranty_status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($warranty['warranty_status'] === 'Active'): ?>
                                            <?php if ($days_remaining < 0): ?>
                                                <span class="status-expired">Expired</span>
                                            <?php elseif ($days_remaining == 0): ?>
                                                <span class="expiring-soon">Last day</span>
                                            <?php else: ?>
                                                <span class="warranty-days <?= $is_expiring_soon ? 'expiring-soon' : '' ?>">
                                                    <?= $days_remaining ?> days
                                                </span>
                                            <?php endif; ?>
                                        <?php elseif ($warranty['warranty_status'] === 'Claimed' && $warranty['claim_date']): ?>
                                            <span>Claimed: <?= date('M d, Y', strtotime($warranty['claim_date'])) ?></span>
                                        <?php else: ?>
                                            <span>—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="icon-btn-sm" onclick="viewWarranty(<?= htmlspecialchars(json_encode($warranty)) ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if ($warranty['warranty_status'] === 'Active'): ?>
                                                <?php if ($days_remaining >= 0): ?>
                                                    <button class="icon-btn-sm" onclick="claimWarranty(<?= $warranty['warranty_id'] ?>, '<?= htmlspecialchars($warranty['customer_name']) ?>', '<?= htmlspecialchars($warranty['product_name']) ?>')">
                                                        <i class="bi bi-check-circle"></i> Claim
                                                    </button>
                                                <?php endif; ?>
                                                <button class="icon-btn-sm" onclick="updateStatus(<?= $warranty['warranty_id'] ?>, '<?= $warranty['warranty_status'] ?>')">
                                                    <i class="bi bi-arrow-repeat"></i> Status
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div style="display: flex; justify-content: center; gap: 8px; padding: 20px; border-top: 1px solid var(--border);">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page-1 ?><?= !empty($status_filter) ? '&status='.urlencode($status_filter) : '' ?><?= $customer_filter ? '&customer_id='.$customer_filter : '' ?><?= !empty($date_from) ? '&date_from='.urlencode($date_from) : '' ?><?= !empty($date_to) ? '&date_to='.urlencode($date_to) : '' ?>" class="icon-btn-sm">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page-2); $i <= min($totalPages, $page+2); $i++): ?>
                        <a href="?page=<?= $i ?><?= !empty($status_filter) ? '&status='.urlencode($status_filter) : '' ?><?= $customer_filter ? '&customer_id='.$customer_filter : '' ?><?= !empty($date_from) ? '&date_from='.urlencode($date_from) : '' ?><?= !empty($date_to) ? '&date_to='.urlencode($date_to) : '' ?>" 
                           class="icon-btn-sm <?= $i === $page ? 'btn-primary' : '' ?>"
                           style="<?= $i === $page ? 'background: var(--accent); color: #fff; border-color: var(--accent);' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page+1 ?><?= !empty($status_filter) ? '&status='.urlencode($status_filter) : '' ?><?= $customer_filter ? '&customer_id='.$customer_filter : '' ?><?= !empty($date_from) ? '&date_from='.urlencode($date_from) : '' ?><?= !empty($date_to) ? '&date_to='.urlencode($date_to) : '' ?>" class="icon-btn-sm">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- View Warranty Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Warranty Details</h3>
                <button class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="warrantyDetails"></div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Claim Warranty Modal -->
    <div id="claimModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Claim Warranty</h3>
                <button class="modal-close" onclick="closeClaimModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="claim">
                <input type="hidden" name="warranty_id" id="claim_warranty_id">
                <div class="modal-body">
                    <p>Claiming warranty for: <strong id="claim_customer"></strong></p>
                    <p>Product: <strong id="claim_product"></strong></p>
                    
                    <div class="form-group">
                        <label>Claim Notes</label>
                        <textarea name="claim_notes" placeholder="Enter details about the warranty claim..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeClaimModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Submit Claim</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Warranty Status</h3>
                <button class="modal-close" onclick="closeStatusModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="warranty_id" id="status_warranty_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>New Status</label>
                        <select name="status" id="status_select" required>
                            <option value="Active">Active</option>
                            <option value="Expired">Expired</option>
                            <option value="Claimed">Claimed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeStatusModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // View Warranty
        function viewWarranty(warranty) {
            const details = `
                <div class="warranty-detail">
                    <div class="detail-row">
                        <div class="detail-label">Warranty ID:</div>
                        <div class="detail-value">#${String(warranty.warranty_id).padStart(5, '0')}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Customer:</div>
                        <div class="detail-value">${warranty.customer_name}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Contact:</div>
                        <div class="detail-value">${warranty.contact_number}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Product:</div>
                        <div class="detail-value">${warranty.product_name}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Serial #:</div>
                        <div class="detail-value">${warranty.serial_number || 'N/A'}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Purchase Date:</div>
                        <div class="detail-value">${new Date(warranty.sales_date).toLocaleDateString()}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Warranty Start:</div>
                        <div class="detail-value">${new Date(warranty.warranty_start).toLocaleDateString()}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Warranty End:</div>
                        <div class="detail-value">${new Date(warranty.warranty_end).toLocaleDateString()}</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">
                            <span class="status-badge status-${warranty.warranty_status.toLowerCase()}">
                                ${warranty.warranty_status}
                            </span>
                        </div>
                    </div>
                    ${warranty.claim_date ? `
                    <div class="detail-row">
                        <div class="detail-label">Claim Date:</div>
                        <div class="detail-value">${new Date(warranty.claim_date).toLocaleDateString()}</div>
                    </div>
                    ` : ''}
                    ${warranty.claim_notes ? `
                    <div class="detail-row">
                        <div class="detail-label">Claim Notes:</div>
                        <div class="detail-value">${warranty.claim_notes}</div>
                    </div>
                    ` : ''}
                </div>
            `;
            
            document.getElementById('warrantyDetails').innerHTML = details;
            document.getElementById('viewModal').style.display = 'block';
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        // Claim Warranty
        function claimWarranty(id, customer, product) {
            document.getElementById('claim_warranty_id').value = id;
            document.getElementById('claim_customer').textContent = customer;
            document.getElementById('claim_product').textContent = product;
            document.getElementById('claimModal').style.display = 'block';
        }
        
        function closeClaimModal() {
            document.getElementById('claimModal').style.display = 'none';
        }
        
        // Update Status
        function updateStatus(id, currentStatus) {
            document.getElementById('status_warranty_id').value = id;
            document.getElementById('status_select').value = currentStatus;
            document.getElementById('statusModal').style.display = 'block';
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
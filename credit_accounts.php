<?php

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

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save' && isset($_POST['customer_id'])) {
        $customer_id = $_POST['customer_id'];
        $credit_limit = floatval($_POST['credit_limit']);

        $check = $conn->prepare("SELECT credit_id FROM credit_accounts WHERE customer_id = ?");
        $check->bind_param("i", $customer_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE credit_accounts SET credit_limit = ? WHERE customer_id = ?");
            $stmt->bind_param("di", $credit_limit, $customer_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO credit_accounts (customer_id, credit_limit, current_balance) VALUES (?, ?, 0.00)");
            $stmt->bind_param("id", $customer_id, $credit_limit);
        }
        $check->close();

        if ($stmt->execute()) {
            $message = "Credit account saved successfully!";
        } else {
            $error = "Error saving credit account: " . $conn->error;
        }
        $stmt->close();
    }

    if ($_POST['action'] === 'payment' && isset($_POST['credit_id'])) {
        $credit_id = $_POST['credit_id'];
        $payment_amount = floatval($_POST['payment_amount']);

        $stmt = $conn->prepare("UPDATE credit_accounts SET current_balance = current_balance - ?, last_payment_date = CURDATE() WHERE credit_id = ? AND current_balance >= ?");
        $stmt->bind_param("dii", $payment_amount, $credit_id, $payment_amount);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $message = "Payment recorded successfully!";
        } else {
            $error = "Error recording payment or insufficient balance.";
        }
        $stmt->close();
    }

    if ($_POST['action'] === 'adjust' && isset($_POST['credit_id']) && $isOwner) {
        $credit_id = $_POST['credit_id'];
        $new_balance = floatval($_POST['new_balance']);

        $stmt = $conn->prepare("UPDATE credit_accounts SET current_balance = ? WHERE credit_id = ?");
        $stmt->bind_param("di", $new_balance, $credit_id);

        if ($stmt->execute()) {
            $message = "Balance adjusted successfully!";
        } else {
            $error = "Error adjusting balance: " . $conn->error;
        }
        $stmt->close();
    }
}

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.contact_number LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $types .= "ssss";
}

if ($status_filter === 'has_balance') {
    $where[] = "ca.current_balance > 0";
} elseif ($status_filter === 'zero_balance') {
    $where[] = "ca.current_balance = 0";
} elseif ($status_filter === 'near_limit') {
    $where[] = "ca.current_balance > (ca.credit_limit * 0.8)";
} elseif ($status_filter === 'over_limit') {
    $where[] = "ca.current_balance > ca.credit_limit";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "
    SELECT 
        ca.*,
        c.first_name,
        c.last_name,
        c.email,
        c.contact_number,
        c.address,
        (ca.current_balance / ca.credit_limit * 100) as usage_percent,
        CASE 
            WHEN ca.current_balance > ca.credit_limit THEN 'Over Limit'
            WHEN ca.current_balance > (ca.credit_limit * 0.8) THEN 'Near Limit'
            WHEN ca.current_balance > 0 THEN 'Active'
            ELSE 'Zero Balance'
        END as account_status,
        DATEDIFF(CURDATE(), ca.last_payment_date) as days_since_payment
    FROM credit_accounts ca
    JOIN customers c ON ca.customer_id = c.customer_id
    $where_clause
    ORDER BY 
        CASE 
            WHEN ca.current_balance > ca.credit_limit THEN 1
            WHEN ca.current_balance > (ca.credit_limit * 0.8) THEN 2
            WHEN ca.current_balance > 0 THEN 3
            ELSE 4
        END,
        ca.current_balance DESC
";

$creditAccounts = $conn->query($query)->fetch_all(MYSQLI_ASSOC);

$customersWithoutAccount = $conn->query("
    SELECT c.customer_id, c.first_name, c.last_name 
    FROM customers c 
    LEFT JOIN credit_accounts ca ON c.customer_id = ca.customer_id 
    WHERE ca.credit_id IS NULL
    ORDER BY c.first_name
")->fetch_all(MYSQLI_ASSOC);

$totalOutstanding = array_sum(array_column($creditAccounts, 'current_balance'));
$totalCreditLimit = array_sum(array_column($creditAccounts, 'credit_limit'));
$averageUsage = $totalCreditLimit > 0 ? ($totalOutstanding / $totalCreditLimit * 100) : 0;
$accountsOverLimit = count(array_filter($creditAccounts, function ($acc) {
    return $acc['current_balance'] > $acc['credit_limit'];
}));
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoBert — Credit Account Management</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .credit-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid var(--border);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--text);
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
        }

        .stat-sub {
            font-size: 12px;
            color: var(--muted);
            margin-top: 8px;
        }

        .filter-section {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
        }

        .filter-section form {
            display: flex;
            gap: 12px;
            width: 100%;
            align-items: flex-end;
            justify-content: space-between;
        }

        .filter-group {
            flex: 1;
            min-width: 0;
        }

        .filter-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }

        .filter-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 36px;
            box-sizing: border-box;
        }

        .filter-group input {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            box-sizing: border-box;
            appearance: none;
        }

        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-shrink: 0;
        }

        .filter-actions .btn-primary {
            padding: 8px 16px;
            font-size: 13px;
            border-radius: 20px; 
            background: var(--accent) !important;
            color: #fff !important;
            border: none;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            font-weight: 500;
            transform: none !important;
            transition: background 0.15s;
        }

        .filter-actions .btn-primary:hover,
        .filter-actions .btn-primary:active,
        .filter-actions .btn-primary:focus {
            background: var(--accent-hover) !important;
            color: #fff !important;
            transform: none !important;
            opacity: 1 !important;
        }

        .btn-clear {
            padding: 8px 16px;
            border: 1.5px solid #d1d5db;
            border-radius: 15px;
            font-size: 13px;
            font-weight: 500;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.15s;
        }

        .account-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-normal {
            background: #10b98120;
            color: #10b981;
        }

        .status-near-limit {
            background: #f59e0b20;
            color: #f59e0b;
        }

        .status-over-limit {
            background: #ef444420;
            color: #ef4444;
        }

        .status-zero {
            background: #6b728020;
            color: #6b7280;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            margin: 8px 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s;
        }

        .progress-fill.normal {
            background: #10b981;
        }

        .progress-fill.warning {
            background: #f59e0b;
        }

        .progress-fill.danger {
            background: #ef4444;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fff;
            margin: 50px auto;
            padding: 0;
            width: 500px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
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

        .btn-success {
            background: #10b981;
            color: #fff;
        }

        .btn-success:hover {
            background: #059669;
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
            gap: 6px;
            flex-wrap: wrap;
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
            white-space: nowrap;
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

        .amount {
            font-weight: 600;
            font-size: 15px;
        }

        .amount-positive {
            color: #ef4444;
        }

        .amount-negative {
            color: #10b981;
        }

        .payment-info {
            font-size: 11px;
            color: var(--muted);
        }
    </style>
</head>

<body>

    <?php
    $currentPage = 'credit_accounts.php';
    include 'approval_page.php';
    ?>

    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <span class="page-title">Credit Account Management</span>
                <span class="breadcrumb">Manage customer credit limits and balances</span>
            </div>
            <div class="topbar-right">
                <button class="btn-dashboard" onclick="window.location.href='admin_dashboard.php'">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </button>
                <?php if (!empty($customersWithoutAccount)): ?>
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <i class="bi bi-plus-lg"></i> New Credit Account
                    </button>
                <?php endif; ?>
                <button class="logout-btn" onclick="window.location.href='logout.php'">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </header>

        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="credit-stats">
                <div class="stat-card">
                    <div class="stat-value">₱<?= number_format($totalOutstanding, 2) ?></div>
                    <div class="stat-label">Total Outstanding</div>
                    <div class="stat-sub">Across <?= count($creditAccounts) ?> accounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">₱<?= number_format($totalCreditLimit, 2) ?></div>
                    <div class="stat-label">Total Credit Limit</div>
                    <div class="stat-sub">Average <?= number_format($averageUsage, 1) ?>% used</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $accountsOverLimit ?></div>
                    <div class="stat-label">Accounts Over Limit</div>
                    <div class="stat-sub">Requires attention</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= count($customersWithoutAccount) ?></div>
                    <div class="stat-label">Eligible Customers</div>
                    <div class="stat-sub">Without credit account</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET">
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Accounts</option>
                            <option value="has_balance" <?= $status_filter === 'has_balance' ? 'selected' : '' ?>>With
                                Balance</option>
                            <option value="zero_balance" <?= $status_filter === 'zero_balance' ? 'selected' : '' ?>>Zero
                                Balance</option>
                            <option value="near_limit" <?= $status_filter === 'near_limit' ? 'selected' : '' ?>>Near Limit
                                (&gt;80%)</option>
                            <option value="over_limit" <?= $status_filter === 'over_limit' ? 'selected' : '' ?>>Over Limit
                            </option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Customer name, email, phone..."
                            value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-primary">Apply Filters</button>
                        <a href="credit_accounts.php" class="btn-clear">Clear</a>
                    </div>
                </form>
            </div>

            <!-- Credit Accounts Table -->
            <div class="card">
                <table class="job-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Credit Limit</th>
                            <th>Current Balance</th>
                            <th>Usage</th>
                            <th>Status</th>
                            <th>Last Payment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($creditAccounts)): ?>
                            <tr>
                                <td colspan="8">
                                    <div class="empty-state"
                                        style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px;">
                                        <div class="empty-icon">💳</div>
                                        <div class="empty-text" style="margin-bottom:16px;">No credit accounts found</div>
                                        <?php if (!empty($customersWithoutAccount)): ?>
                                            <button class="btn btn-primary" onclick="openCreateModal()">Create First Credit
                                                Account</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($creditAccounts as $account):
                                $usage_percent = $account['usage_percent'];
                                $progress_class = 'normal';
                                if ($usage_percent > 100)
                                    $progress_class = 'danger';
                                elseif ($usage_percent > 80)
                                    $progress_class = 'warning';

                                $status_class = 'status-normal';
                                if ($account['account_status'] === 'Over Limit')
                                    $status_class = 'status-over-limit';
                                elseif ($account['account_status'] === 'Near Limit')
                                    $status_class = 'status-near-limit';
                                elseif ($account['account_status'] === 'Zero Balance')
                                    $status_class = 'status-zero';
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight: 600;">
                                            <?= htmlspecialchars($account['first_name'] . ' ' . $account['last_name']) ?></div>
                                        <div style="font-size: 11px; color: var(--muted);">
                                            <?= htmlspecialchars($account['email'] ?: 'No email') ?></div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($account['contact_number'] ?: 'No phone') ?></div>
                                        <div style="font-size: 11px;">
                                            <?= htmlspecialchars(substr($account['address'] ?? '', 0, 30)) ?>...</div>
                                    </td>
                                    <td class="amount">₱<?= number_format($account['credit_limit'], 2) ?></td>
                                    <td>
                                        <span
                                            class="amount <?= $account['current_balance'] > 0 ? 'amount-positive' : 'amount-negative' ?>">
                                            ₱<?= number_format($account['current_balance'], 2) ?>
                                        </span>
                                    </td>
                                    <td style="min-width: 120px;">
                                        <div class="progress-bar">
                                            <div class="progress-fill <?= $progress_class ?>"
                                                style="width: <?= min($usage_percent, 100) ?>%;"></div>
                                        </div>
                                        <div style="font-size: 11px; text-align: right;">
                                            <?= number_format($usage_percent, 1) ?>%</div>
                                    </td>
                                    <td><span
                                            class="account-status <?= $status_class ?>"><?= $account['account_status'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($account['last_payment_date']): ?>
                                            <div><?= date('M d, Y', strtotime($account['last_payment_date'])) ?></div>
                                            <div class="payment-info"><?= $account['days_since_payment'] ?> days ago</div>
                                        <?php else: ?>
                                            <span class="payment-info">No payments</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="icon-btn-sm"
                                                onclick="viewAccount(<?= htmlspecialchars(json_encode($account)) ?>)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="icon-btn-sm"
                                                onclick="recordPayment(<?= $account['credit_id'] ?>, '<?= htmlspecialchars($account['first_name'] . ' ' . $account['last_name']) ?>', <?= $account['current_balance'] ?>)">
                                                <i class="bi bi-cash"></i> Pay
                                            </button>
                                            <button class="icon-btn-sm"
                                                onclick="editLimit(<?= $account['credit_id'] ?>, '<?= htmlspecialchars($account['first_name'] . ' ' . $account['last_name']) ?>', <?= $account['credit_limit'] ?>)">
                                                <i class="bi bi-pencil"></i> Limit
                                            </button>
                                            <?php if ($isOwner): ?>
                                                <button class="icon-btn-sm"
                                                    onclick="adjustBalance(<?= $account['credit_id'] ?>, '<?= htmlspecialchars($account['first_name'] . ' ' . $account['last_name']) ?>', <?= $account['current_balance'] ?>)">
                                                    <i class="bi bi-sliders"></i> Adjust
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Create Credit Account Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Credit Account</h3>
                <button class="modal-close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="save">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Select Customer</label>
                        <select name="customer_id" required>
                            <option value="">Choose a customer...</option>
                            <?php foreach ($customersWithoutAccount as $customer): ?>
                                <option value="<?= $customer['customer_id'] ?>">
                                    <?= htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Credit Limit (₱)</label>
                        <input type="number" name="credit_limit" step="0.01" min="0" required
                            placeholder="Enter credit limit">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Record Payment Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Record Payment</h3>
                <button class="modal-close" onclick="closePaymentModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="credit_id" id="payment_credit_id">
                <div class="modal-body">
                    <p>Customer: <strong id="payment_customer"></strong></p>
                    <p>Current Balance: <strong id="payment_balance"></strong></p>
                    <div class="form-group">
                        <label>Payment Amount (₱)</label>
                        <input type="number" name="payment_amount" step="0.01" min="0.01" required
                            placeholder="Enter payment amount">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Record Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Credit Limit Modal -->
    <div id="limitModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Credit Limit</h3>
                <button class="modal-close" onclick="closeLimitModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="customer_id" id="limit_customer_id">
                <div class="modal-body">
                    <p>Customer: <strong id="limit_customer"></strong></p>
                    <div class="form-group">
                        <label>New Credit Limit (₱)</label>
                        <input type="number" name="credit_limit" id="limit_amount" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeLimitModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Limit</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Adjust Balance Modal -->
    <div id="adjustModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Adjust Balance</h3>
                <button class="modal-close" onclick="closeAdjustModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="credit_id" id="adjust_credit_id">
                <div class="modal-body">
                    <p>Customer: <strong id="adjust_customer"></strong></p>
                    <p>Current Balance: <strong id="adjust_current"></strong></p>
                    <div class="form-group">
                        <label>New Balance (₱)</label>
                        <input type="number" name="new_balance" id="adjust_amount" step="0.01" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeAdjustModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Adjust Balance</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Account Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Account Details</h3>
                <button class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="accountDetails"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        function openCreateModal() { document.getElementById('createModal').style.display = 'block'; }
        function closeCreateModal() { document.getElementById('createModal').style.display = 'none'; }

        function recordPayment(id, customer, balance) {
            document.getElementById('payment_credit_id').value = id;
            document.getElementById('payment_customer').textContent = customer;
            document.getElementById('payment_balance').textContent = '₱' + balance.toFixed(2);
            document.getElementById('paymentModal').style.display = 'block';
        }
        function closePaymentModal() { document.getElementById('paymentModal').style.display = 'none'; }

        function editLimit(id, customer, currentLimit) {
            document.getElementById('limit_customer_id').value = id;
            document.getElementById('limit_customer').textContent = customer;
            document.getElementById('limit_amount').value = currentLimit;
            document.getElementById('limitModal').style.display = 'block';
        }
        function closeLimitModal() { document.getElementById('limitModal').style.display = 'none'; }

        function adjustBalance(id, customer, currentBalance) {
            document.getElementById('adjust_credit_id').value = id;
            document.getElementById('adjust_customer').textContent = customer;
            document.getElementById('adjust_current').textContent = '₱' + currentBalance.toFixed(2);
            document.getElementById('adjust_amount').value = currentBalance;
            document.getElementById('adjustModal').style.display = 'block';
        }
        function closeAdjustModal() { document.getElementById('adjustModal').style.display = 'none'; }

        function viewAccount(account) {
            const details = `
                <div style="background: #f9fafb; border-radius: 8px; padding: 16px;">
                    <div style="display: grid; gap: 12px;">
                        <div><strong>Customer:</strong> ${account.first_name} ${account.last_name}</div>
                        <div><strong>Email:</strong> ${account.email || 'N/A'}</div>
                        <div><strong>Phone:</strong> ${account.contact_number || 'N/A'}</div>
                        <div><strong>Address:</strong> ${account.address || 'N/A'}</div>
                        <div><strong>Credit Limit:</strong> ₱${parseFloat(account.credit_limit).toFixed(2)}</div>
                        <div><strong>Current Balance:</strong> ₱${parseFloat(account.current_balance).toFixed(2)}</div>
                        <div><strong>Usage:</strong> ${parseFloat(account.usage_percent).toFixed(1)}%</div>
                        <div><strong>Status:</strong>
                            <span class="account-status status-${account.account_status === 'Over Limit' ? 'over-limit' :
                    (account.account_status === 'Near Limit' ? 'near-limit' :
                        (account.account_status === 'Zero Balance' ? 'zero' : 'normal'))}">
                                ${account.account_status}
                            </span>
                        </div>
                        <div><strong>Last Payment:</strong> ${account.last_payment_date ? new Date(account.last_payment_date).toLocaleDateString() : 'No payments'}</div>
                        ${account.last_payment_date ? `<div><strong>Days Since Payment:</strong> ${account.days_since_payment} days</div>` : ''}
                    </div>
                </div>
            `;
            document.getElementById('accountDetails').innerHTML = details;
            document.getElementById('viewModal').style.display = 'block';
        }
        function closeViewModal() { document.getElementById('viewModal').style.display = 'none'; }

        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>

</html>
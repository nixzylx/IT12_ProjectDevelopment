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
$isOwner = strtolower($role) === 'owner';
$userInitials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'create_sale') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $job_order_id = intval($_POST['job_order_id'] ?? 0) ?: null;
        $discount = floatval($_POST['discount'] ?? 0);
        $processed_by = $_SESSION['employeeID'];

        $items = $_POST['items'] ?? [];   // array of {type, product_id, description, qty, unit_price}

        if ($customer_id && !empty($items)) {
            $total = 0;
            foreach ($items as $item) {
                $total += floatval($item['unit_price']) * intval($item['qty']);
            }
            $final_amount = max(0, $total - $discount);

            $conn->begin_transaction();
            try {
                // insert sale
                $s = $conn->prepare("INSERT INTO sales (job_order_id, customer_id, processed_by, total_amount, discount, final_amount, status) VALUES (?,?,?,?,?,?,'Unpaid')");
                $s->bind_param("iiiddd", $job_order_id, $customer_id, $processed_by, $total, $discount, $final_amount);
                $s->execute();
                $sales_id = $conn->insert_id;
                $s->close();

                // insert items
                foreach ($items as $item) {
                    $itype = $item['type'];
                    $prod_id = intval($item['product_id'] ?? 0) ?: null;
                    $desc = trim($item['description']);
                    $qty = intval($item['qty']);
                    $uprice = floatval($item['unit_price']);
                    $subtotal = $uprice * $qty;

                    $si = $conn->prepare("INSERT INTO sales_items (sales_id, product_id, item_type, description, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?,?)");
                    $si->bind_param("iissid d", $sales_id, $prod_id, $itype, $desc, $qty, $uprice, $subtotal);
                    $si->close();

                    $si2 = $conn->prepare("INSERT INTO sales_items (sales_id, product_id, item_type, description, quantity, unit_price, subtotal) VALUES (?,?,?,?,?,?,?)");
                    $si2->bind_param("iissids", $sales_id, $prod_id, $itype, $desc, $qty, $uprice, $subtotal);
                    $si2->close();

                    // direct query with proper escaping and null handling
                    $prod_val = $prod_id === null ? "NULL" : intval($prod_id);
                    $conn->query("INSERT INTO sales_items (sales_id, product_id, item_type, description, quantity, unit_price, subtotal)
                                  VALUES ($sales_id, $prod_val, '" . $conn->real_escape_string($itype) . "',
                                         '" . $conn->real_escape_string($desc) . "', $qty, $uprice, $subtotal)");

                    // update stock 
                    if ($prod_id && $itype === 'Product') {
                        $conn->query("UPDATE products SET stock_quantity = stock_quantity - $qty WHERE product_id = $prod_id");
                    }
                }

                $conn->commit();
                $successMsg = "Sale #" . str_pad($sales_id, 5, '0', STR_PAD_LEFT) . " created successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $errorMsg = "Failed to create sale: " . $e->getMessage();
            }
        } else {
            $errorMsg = "Please select a customer and add at least one item.";
        }
    }

    if ($_POST['action'] === 'record_payment') {
        $sales_id = intval($_POST['sales_id'] ?? 0);
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $reference = $conn->real_escape_string($_POST['reference_number'] ?? '');

        if ($sales_id && $amount_paid > 0) {
            $conn->query("INSERT INTO payments (sales_id, payment_method, amount_paid, reference_number)
                          VALUES ($sales_id, '$payment_method', $amount_paid, '$reference')");

            // update sale status
            $total_paid_res = $conn->query("SELECT SUM(amount_paid) AS paid FROM payments WHERE sales_id = $sales_id");
            $total_paid = $total_paid_res->fetch_assoc()['paid'] ?? 0;

            $sale_res = $conn->query("SELECT final_amount FROM sales WHERE sales_id = $sales_id");
            $final_amt = $sale_res->fetch_assoc()['final_amount'] ?? 0;

            if ($total_paid >= $final_amt) {
                $new_status = 'Paid';
            } elseif ($total_paid > 0) {
                $new_status = 'Partially Paid';
            } else {
                $new_status = 'Unpaid';
            }

            $conn->query("UPDATE sales SET status = '$new_status' WHERE sales_id = $sales_id");
            $successMsg = "Payment recorded. Sale status: $new_status.";
        } else {
            $errorMsg = "Invalid payment data.";
        }
    }
}

// filters 
$filter_status = $_GET['status'] ?? 'all';
$filter_search = trim($_GET['search'] ?? '');
$filter_date = $_GET['date'] ?? '';

$where_clauses = [];
if ($filter_status !== 'all') {
    $safe_status = $conn->real_escape_string($filter_status);
    $where_clauses[] = "s.status = '$safe_status'";
}
if ($filter_search !== '') {
    $safe_search = $conn->real_escape_string($filter_search);
    $where_clauses[] = "(c.first_name LIKE '%$safe_search%' OR c.last_name LIKE '%$safe_search%' OR s.sales_id LIKE '%$safe_search%')";
}
if ($filter_date !== '') {
    $safe_date = $conn->real_escape_string($filter_date);
    $where_clauses[] = "DATE(s.sales_date) = '$safe_date'";
}
$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// sales list with customer and employee names, and total paid amount
$sales_rows = [];
$res = $conn->query("
    SELECT s.*, 
           CONCAT(c.first_name,' ',c.last_name) AS customer_name,
           CONCAT(e.first_name,' ',e.last_name) AS processed_by_name,
           (SELECT SUM(amount_paid) FROM payments WHERE sales_id = s.sales_id) AS total_paid
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    LEFT JOIN employee e ON s.processed_by = e.employeeID
    $where_sql
    ORDER BY s.sales_date DESC
    LIMIT 100
");
while ($res && $row = $res->fetch_assoc()) {
    $sales_rows[] = $row;
}

// summary stats
$stats = ['total_sales' => 0, 'total_revenue' => 0, 'unpaid' => 0, 'paid_today' => 0];
$s_res = $conn->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(final_amount),0) AS rev FROM sales");
if ($s_res && $r = $s_res->fetch_assoc()) {
    $stats['total_sales'] = $r['cnt'];
    $stats['total_revenue'] = $r['rev'];
}
$u_res = $conn->query("SELECT COUNT(*) AS cnt FROM sales WHERE status='Unpaid'");
if ($u_res && $r = $u_res->fetch_assoc())
    $stats['unpaid'] = $r['cnt'];

$stat_date_raw = ($filter_date !== '') ? $filter_date : date('Y-m-d');
$stat_date_ts = strtotime($stat_date_raw);
$stat_date = $stat_date_ts ? date('Y-m-d', $stat_date_ts) : date('Y-m-d');
$stat_label = ($filter_date !== '') ? date('M d, Y', $stat_date_ts) : 'today';
$t_res = $conn->query("SELECT COALESCE(SUM(amount_paid),0) AS paid FROM payments WHERE DATE(payment_date)='$stat_date'");
if ($t_res && $r = $t_res->fetch_assoc())
    $stats['paid_today'] = $r['paid'];

// data for modals (dropdowns)
$customers = [];
$c_res = $conn->query("SELECT customer_id, CONCAT(first_name,' ',last_name) AS name FROM customers ORDER BY first_name");
while ($c_res && $r = $c_res->fetch_assoc())
    $customers[] = $r;

$products = [];
$p_res = $conn->query("SELECT product_id, product_name, unit_price, stock_quantity FROM products ORDER BY product_name");
while ($p_res && $r = $p_res->fetch_assoc())
    $products[] = $r;

$job_orders = [];
$j_res = $conn->query("SELECT jo.job_order_id, CONCAT('#',LPAD(jo.job_order_id,5,'0'),' - ',c.first_name,' ',c.last_name) AS label
                        FROM job_orders jo JOIN customers c ON jo.customer_id=c.customer_id
                        WHERE jo.status='Completed' ORDER BY jo.date_completed DESC LIMIT 50");
while ($j_res && $r = $j_res->fetch_assoc())
    $job_orders[] = $r;

// pending approvals and active jobs for sidebar badges
$pa_res = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved=0");
$pendingApprovals = ($pa_res && $r = $pa_res->fetch_assoc()) ? $r['cnt'] : 0;
$activeJobs_res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status NOT IN ('Completed','Cancelled')");
$activeJobs = ($activeJobs_res && $r = $activeJobs_res->fetch_assoc()) ? $r['cnt'] : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales — AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        .content {
            padding: 24px 28px;
        }

        .sales-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #fff;
            border-radius: var(--card-radius);
            padding: 20px 22px;
            border: 1px solid var(--border);
        }

        .stat-card.featured {
            background: var(--accent);
            color: #fff;
        }

        .stat-card.featured .stat-label,
        .stat-card.featured .stat-change {
            color: rgba(255, 255, 255, .75);
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .stat-value {
            font-family: "Syne", sans-serif;
            font-size: 26px;
            font-weight: 700;
            line-height: 1.1;
        }

        .stat-change {
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .toolbar .search-box {
            flex: 1;
            min-width: 200px;
            position: relative;
        }

        .toolbar .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }

        .toolbar .search-box input {
            width: 100%;
            padding: 9px 12px 9px 36px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
        }

        .toolbar select,
        .toolbar input[type=date] {
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
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary:hover {
            background: var(--accent-hover);
        }

        .table-card {
            background: #fff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .table-card table {
            width: 100%;
            border-collapse: collapse;
        }

        .table-card thead th {
            background: #f9fafb;
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
            font-weight: 600;
            border-bottom: 1px solid var(--border);
        }

        .table-card tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background .15s;
            cursor: pointer;
        }

        .table-card tbody tr:hover {
            background: #f9fafb;
        }

        .table-card td {
            padding: 13px 16px;
            font-size: 13px;
        }

        .sale-id {
            font-family: "Syne", sans-serif;
            font-weight: 700;
            color: var(--accent);
        }

        .customer-name {
            font-weight: 500;
        }

        .amount {
            font-weight: 600;
            font-size: 14px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-paid {
            background: #dcfce7;
            color: #166534;
        }

        .badge-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-partial {
            background: #fef9c3;
            color: #854d0e;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal {
            background: #fff;
            border-radius: 16px;
            width: 660px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
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
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 20px;
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

        .form-row.single {
            grid-template-columns: 1fr;
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
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #fff;
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            padding-right: 36px;
            cursor: pointer;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .1);
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 13px;
        }

        .items-table th {
            background: #f9fafb;
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }

        .items-table td {
            padding: 6px 6px;
            border-bottom: 1px solid #f3f4f6;
        }

        .items-table input,
        .items-table select {
            width: 100%;
            padding: 7px 8px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 12px;
        }

        .btn-add-item {
            background: none;
            border: 1px dashed var(--accent);
            color: var(--accent);
            padding: 7px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            width: 100%;
            margin-top: 8px;
        }

        .btn-add-item:hover {
            background: #eff6ff;
        }

        .btn-remove-item {
            background: none;
            border: none;
            color: var(--red);
            font-size: 16px;
            cursor: pointer;
            padding: 4px;
        }

        .total-row {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 12px;
            font-size: 13px;
            align-items: center;
        }

        .total-row strong {
            font-size: 16px;
            color: var(--accent);
        }

        .btn-pay {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            line-height: 1;
            white-space: nowrap;
        }

        .btn-pay:hover {
            background: var(--accent-hover);
        }

        .btn-pay i {
            font-size: 13px;
            position: relative;
            top: 2px;
        }

        /* sales detail view */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .detail-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .detail-value {
            font-size: 14px;
            font-weight: 500;
        }

        .items-view-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 8px;
        }

        .items-view-table th {
            background: #f9fafb;
            padding: 9px 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }

        .items-view-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f3f4f6;
        }

        .payment-history {
            margin-top: 16px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
        }

        .empty-state {
            text-align: center;
            padding: 56px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
            opacity: .35;
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
        }

        .page-alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 768px) {
            .sales-stats {
                grid-template-columns: 1fr 1fr;
            }

            .form-row {
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
            <a class="nav-item" href="job_orders.php">
                <i class="bi bi-clipboard-data"></i> Job Orders
                <?php if ($activeJobs > 0): ?>
                    <span class="pending-approvals-badge" style="background:var(--accent);"><?= $activeJobs ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-item active" href="sales.php"><i class="bi bi-currency-dollar"></i> Sales</a>
            <a class="nav-item" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a>
            <a class="nav-item" href="products.php"><i class="bi bi-box-seam"></i> Products</a>
        </nav>

        <nav class="nav-section">
            <div class="nav-label">Management</div>
            <a class="nav-item" href="customers.php"><i class="bi bi-people"></i> Customers</a>
            <a class="nav-item" href="vehicles.php"><i class="bi bi-truck"></i> Vehicles</a>
            <?php if ($isOwner): ?>
                <a class="nav-item" href="employees.php"><i class="bi bi-person-badge"></i> Employees</a>
                <a class="nav-item" href="admin_approvals.php">
                    <i class="bi bi-check-circle"></i> Approvals
                    <?php if ($pendingApprovals > 0): ?>
                        <span class="pending-approvals-badge"><?= $pendingApprovals ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            <a class="nav-item" href="warranties.php"><i class="bi bi-shield-check"></i> Warranties</a>
            <a class="nav-item" href="credit_accounts.php"><i class="bi bi-wallet2"></i> Credit Accounts</a>
        </nav>

        <nav class="nav-section">
            <div class="nav-label">Owner</div>
            <a class="nav-item" href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
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
                <span class="page-title">Sales</span>
                <span class="breadcrumb">Sales &amp; Invoices</span>
            </div>
            <div class="topbar-right">
                <button class="btn-primary" onclick="openNewSaleModal()">
                    <i class="bi bi-plus-lg"></i> New Sale
                </button>
                <button class="logout-btn" onclick="window.location.href='logout.php'">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </header>

        <div class="content">

            <?php if ($successMsg): ?>
                <div class="page-alert success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($successMsg) ?>
                </div>
            <?php elseif ($errorMsg): ?>
                <div class="page-alert error"><i class="bi bi-exclamation-triangle-fill"></i>
                    <?= htmlspecialchars($errorMsg) ?></div>
            <?php endif; ?>

            <div class="sales-stats">
                <div class="stat-card featured">
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value">₱<?= number_format($stats['total_revenue'], 2) ?></div>
                    <div class="stat-change">All-time sales</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Invoices</div>
                    <div class="stat-value"><?= number_format($stats['total_sales']) ?></div>
                    <div class="stat-change">Transactions recorded</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Unpaid Invoices</div>
                    <div class="stat-value" style="color:var(--red)"><?= $stats['unpaid'] ?></div>
                    <div class="stat-change"><?= $stats['unpaid'] > 0 ? 'Requires attention' : 'All cleared!' ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Collected Today</div>
                    <div class="stat-value" style="color:var(--green)">₱<?= number_format($stats['paid_today'], 2) ?>
                    </div>
                    <div class="stat-change">Payments received today</div>
                </div>
            </div>

            <div class="toolbar">
                <form method="GET" style="display:contents;">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" placeholder="Search customer or sale ID…"
                            value="<?= htmlspecialchars($filter_search) ?>">
                    </div>
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All Status</option>
                        <option value="Unpaid" <?= $filter_status === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="Partially Paid" <?= $filter_status === 'Partially Paid' ? 'selected' : '' ?>>
                            Partially
                            Paid</option>
                        <option value="Paid" <?= $filter_status === 'Paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                    <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
                        onchange="this.form.submit()">
                    <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                    <?php if ($filter_status !== 'all' || $filter_search !== '' || $filter_date !== ''): ?>
                        <a href="sales.php" style="font-size:13px; color:var(--muted);">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- sales table -->
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Sale #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Job Order</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales_rows)): ?>
                            <tr>
                                <td colspan="10">
                                    <div class="empty-state">
                                        <i class="bi bi-receipt"></i>
                                        <div>No sales records found.</div>
                                        <button class="btn-primary"
                                            style="margin:20px auto 0; padding:3px 8px; font-size:13px; align-items:center; gap: 1px; line-height: normal;">
                                            <i class="bi bi-plus" style="position:relative; top:7px;"></i> Create First Sale
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales_rows as $sale):
                                $paid = floatval($sale['total_paid'] ?? 0);
                                $balance = floatval($sale['final_amount']) - $paid;
                                $badge_class = match ($sale['status']) {
                                    'Paid' => 'badge-paid',
                                    'Partially Paid' => 'badge-partial',
                                    default => 'badge-unpaid',
                                };
                                // count items in this sale
                                $ic_res = $conn->query("SELECT COUNT(*) AS cnt FROM sales_items WHERE sales_id=" . $sale['sales_id']);
                                $item_count = $ic_res ? $ic_res->fetch_assoc()['cnt'] : 0;
                                ?>
                                <tr onclick="viewSale(<?= $sale['sales_id'] ?>)">
                                    <td><span class="sale-id">#<?= str_pad($sale['sales_id'], 5, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($sale['sales_date'])) ?><br>
                                        <span
                                            style="font-size:11px; color:var(--muted)"><?= date('h:i A', strtotime($sale['sales_date'])) ?></span>
                                    </td>
                                    <td><span
                                            class="customer-name"><?= htmlspecialchars($sale['customer_name'] ?? '—') ?></span>
                                    </td>
                                    <td><?= $sale['job_order_id'] ? '#' . str_pad($sale['job_order_id'], 5, '0', STR_PAD_LEFT) : '<span style="color:var(--muted)">—</span>' ?>
                                    </td>
                                    <td style="text-align:center"><?= $item_count ?></td>
                                    <td class="amount">₱<?= number_format($sale['final_amount'], 2) ?></td>
                                    <td style="color:var(--green); font-weight:600;">₱<?= number_format($paid, 2) ?></td>
                                    <td style="color:<?= $balance > 0 ? 'var(--red)' : 'var(--green)' ?>; font-weight:600;">
                                        ₱<?= number_format(max(0, $balance), 2) ?>
                                    </td>
                                    <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($sale['status']) ?></span>
                                    </td>
                                    <td onclick="event.stopPropagation()">
                                        <button class="btn-pay"
                                            onclick="openPaymentModal(<?= $sale['sales_id'] ?>, <?= $sale['final_amount'] ?>, <?= $paid ?>)"><i
                                                class="bi bi-cash-coin"></i> Pay</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>


    <!-- new sale modal -->
    <div class="modal-overlay" id="newSaleModal">
        <div class="modal" style="width:760px;">
            <div class="modal-header">
                <h2><i class="bi bi-receipt" style="color:var(--accent)"></i>&nbsp; New Sale</h2>
                <button class="modal-close" onclick="closeModal('newSaleModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_sale">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Customer</label>
                            <select name="customer_id" required>
                                <option value="">Select Customer</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Linked Job Order (optional)</label>
                            <select name="job_order_id">
                                <option value="">None</option>
                                <?php foreach ($job_orders as $j): ?>
                                    <option value="<?= $j['job_order_id'] ?>"><?= htmlspecialchars($j['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div
                        style="font-size:12px; font-weight:600; color:#444; text-transform:uppercase; letter-spacing:.4px; margin-bottom:8px;">
                        Line Items
                    </div>
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th style="width:90px">Type</th>
                                <th>Description / Product</th>
                                <th style="width:60px">Qty</th>
                                <th style="width:110px">Unit Price</th>
                                <th style="width:100px">Subtotal</th>
                                <th style="width:36px"></th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">

                        </tbody>
                    </table>
                    <button type="button" class="btn-add-item" onclick="addItemRow()">
                        <i class="bi bi-plus-circle"></i> Add Item
                    </button>

                    <div class="total-row">
                        <span>Discount (₱):</span>
                        <input type="number" name="discount" id="discountInput" value="0" min="0" step="0.01"
                            style="width:110px; padding:6px 8px; border:1px solid var(--border); border-radius:6px; font-size:13px;"
                            oninput="recalcTotal()">
                        <span>Total: <strong id="grandTotal">₱0.00</strong></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('newSaleModal')"
                        style="padding:9px 18px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; font-size:13px;">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Create Sale</button>
                </div>
            </form>
        </div>
    </div>


    <!-- payment modal -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal" style="width:460px;">
            <div class="modal-header">
                <h2 style="display:flex; align-items:center; gap:8px;"><i class="bi bi-cash-coin"
                        style="color:var(--green);"></i> Record Payment</h2>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="sales_id" id="pay_sales_id">
                <div class="modal-body">
                    <div class="form-row single">
                        <div class="form-group">
                            <label>Sale Balance</label>
                            <input type="text" id="pay_balance_display" readonly style="background:#f3f4f6;">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Amount Paid *</label>
                            <input type="number" name="amount_paid" id="pay_amount" step="0.01" min="0.01" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select name="payment_method">
                                <option>Cash</option>
                                <option>GCash</option>
                                <option>Bank</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row single">
                        <div class="form-group">
                            <label>Reference Number (optional)</label>
                            <input type="text" name="reference_number" placeholder="e.g. GCash ref, bank slip #">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('paymentModal')"
                        style="padding:9px 18px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; font-size:13px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="background:var(--green);"><i
                            class="bi bi-check-lg"></i> Record Payment</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="detailModal">
        <div class="modal" style="width:700px;">
            <div class="modal-header">
                <h2 id="detailTitle"><i class="bi bi-receipt"></i>&nbsp; Sale Detail</h2>
                <button class="modal-close" onclick="closeModal('detailModal')">&times;</button>
            </div>
            <div class="modal-body" id="detailBody">
                <div style="text-align:center; padding:40px; color:var(--muted);">Loading…</div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('detailModal')"
                    style="padding:9px 18px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; font-size:13px;">Close</button>
                <button class="btn-primary" id="detailPayBtn" onclick=""><i class="bi bi-cash-coin"></i> Record
                    Payment</button>
            </div>
        </div>
    </div>

    <script>
        // products data for item selection in new sale modal
        const products = <?= json_encode($products) ?>;

        // modal controls
        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
        });

        // new sale modal 
        let itemIdx = 0;
        function openNewSaleModal() {
            document.getElementById('itemsBody').innerHTML = '';
            itemIdx = 0;
            addItemRow();
            recalcTotal();
            openModal('newSaleModal');
        }

        function addItemRow() {
            const tbody = document.getElementById('itemsBody');
            const idx = itemIdx++;
            const tr = document.createElement('tr');
            tr.id = 'item_row_' + idx;

            const productOptions = products.map(p =>
                `<option value="${p.product_id}" data-price="${p.unit_price}">${p.product_name} (₱${parseFloat(p.unit_price).toFixed(2)})</option>`
            ).join('');

            tr.innerHTML = `
        <td>
            <select name="items[${idx}][type]" onchange="toggleItemType(${idx})">
                <option value="Product">Product</option>
                <option value="Service">Service</option>
            </select>
        </td>
        <td id="item_desc_cell_${idx}">
            <select name="items[${idx}][product_id]" id="item_prod_${idx}" onchange="fillPrice(${idx})">
                <option value="">Select Product</option>
                ${productOptions}
            </select>
            <input type="hidden" name="items[${idx}][description]" id="item_desc_${idx}" value="">
        </td>
        <td><input type="number" name="items[${idx}][qty]" value="1" min="1" oninput="recalcTotal()" style="width:50px;"></td>
        <td><input type="number" name="items[${idx}][unit_price]" id="item_price_${idx}" value="0" min="0" step="0.01" oninput="recalcTotal()"></td>
        <td id="item_sub_${idx}" style="font-weight:600; padding:6px 10px;">₱0.00</td>
        <td><button type="button" class="btn-remove-item" onclick="removeItemRow(${idx})"><i class="bi bi-trash"></i></button></td>
    `;
            tbody.appendChild(tr);
        }

        function toggleItemType(idx) {
            const typeEl = document.querySelector(`[name="items[${idx}][type]"]`);
            const cell = document.getElementById('item_desc_cell_' + idx);
            if (typeEl.value === 'Service') {
                cell.innerHTML = `<input type="text" name="items[${idx}][description]" placeholder="Service description" required>
                          <input type="hidden" name="items[${idx}][product_id]" value="">`;
            } else {
                const productOptions = products.map(p =>
                    `<option value="${p.product_id}" data-price="${p.unit_price}">${p.product_name} (₱${parseFloat(p.unit_price).toFixed(2)})</option>`
                ).join('');
                cell.innerHTML = `<select name="items[${idx}][product_id]" id="item_prod_${idx}" onchange="fillPrice(${idx})">
                            <option value="">Select Product</option>${productOptions}
                          </select>
                          <input type="hidden" name="items[${idx}][description]" id="item_desc_${idx}" value="">`;
            }
        }

        function fillPrice(idx) {
            const sel = document.getElementById('item_prod_' + idx);
            const price = sel.options[sel.selectedIndex]?.dataset.price ?? 0;
            document.getElementById('item_price_' + idx).value = parseFloat(price).toFixed(2);
            const descEl = document.getElementById('item_desc_' + idx);
            if (descEl) descEl.value = sel.options[sel.selectedIndex]?.text.split(' (₱')[0] ?? '';
            recalcTotal();
        }

        function removeItemRow(idx) {
            const row = document.getElementById('item_row_' + idx);
            if (row) row.remove();
            recalcTotal();
        }

        function recalcTotal() {
            let total = 0;
            document.querySelectorAll('#itemsBody tr').forEach(tr => {
                const qty = parseFloat(tr.querySelector('[name*="[qty]"]')?.value ?? 0);
                const price = parseFloat(tr.querySelector('[name*="[unit_price]"]')?.value ?? 0);
                const sub = qty * price;
                const subEl = tr.querySelector('[id^="item_sub_"]');
                if (subEl) subEl.textContent = '₱' + sub.toFixed(2);
                total += sub;
            });
            const discount = parseFloat(document.getElementById('discountInput').value ?? 0);
            const grand = Math.max(0, total - discount);
            document.getElementById('grandTotal').textContent = '₱' + grand.toFixed(2);
        }

        // payment Modal 
        function openPaymentModal(salesId, finalAmt, paid) {
            const balance = Math.max(0, finalAmt - paid);
            document.getElementById('pay_sales_id').value = salesId;
            document.getElementById('pay_balance_display').value = '₱' + balance.toFixed(2) + ' remaining';
            document.getElementById('pay_amount').value = balance.toFixed(2);
            openModal('paymentModal');
        }

        // view sale details
        function viewSale(salesId) {
            openModal('detailModal');
            document.getElementById('detailTitle').innerHTML = '<i class="bi bi-receipt"></i>&nbsp; Sale #' + String(salesId).padStart(5, '0');
            document.getElementById('detailBody').innerHTML = '<div style="text-align:center;padding:40px;color:var(--muted);">Loading…</div>';

            fetch('sales_detail.php?id=' + salesId)
                .then(r => r.text())
                .then(html => {
                    document.getElementById('detailBody').innerHTML = html;
                })
                .catch(() => {
                    document.getElementById('detailBody').innerHTML = '<p style="color:var(--muted);text-align:center;padding:32px">Could not load details.</p>';
                });

            document.getElementById('detailPayBtn').onclick = function () {
                closeModal('detailModal');
                // fetch the latest amounts from the table row to ensure we have the most up-to-date balance before opening payment modal
                const row = document.querySelector(`[onclick="viewSale(${salesId})"]`);
                if (row) {
                    const cells = row.querySelectorAll('td');
                    const finalAmt = parseFloat(cells[5].textContent.replace('₱', '').replace(/,/g, ''));
                    const paid = parseFloat(cells[6].textContent.replace('₱', '').replace(/,/g, ''));
                    openPaymentModal(salesId, finalAmt, paid);
                } else {
                    openPaymentModal(salesId, 0, 0);
                }
            };
        }
    </script>

</body>

</html>
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

// Generate unique reference number
function generateReferenceNumber($method) {
    $prefix = match($method) {
        'GCash' => 'GC',
        'Bank' => 'BNK',
        'Credit' => 'CR',
        default => 'CS'
    };
    return $prefix . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// Process 3.0: Payment with warranty generation and credit account update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'process_payment') {
        $sales_id = intval($_POST['sales_id'] ?? 0);
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? 'Cash');
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $reference_number = $conn->real_escape_string($_POST['reference_number'] ?? '');
        $payment_notes = $conn->real_escape_string($_POST['payment_notes'] ?? '');
        
        // Auto-generate reference if empty and method is not Cash
        if (empty($reference_number) && in_array($payment_method, ['GCash', 'Bank'])) {
            $reference_number = generateReferenceNumber($payment_method);
        }
        
        $processed_by = $_SESSION['employeeID'];
        
        if ($sales_id && $amount_paid > 0) {
            $conn->begin_transaction();
            
            try {
                // Get sale details
                $sale_res = $conn->query("SELECT s.*, c.first_name, c.last_name, c.customer_id 
                                         FROM sales s 
                                         LEFT JOIN customers c ON s.customer_id = c.customer_id 
                                         WHERE s.sales_id = $sales_id");
                $sale = $sale_res->fetch_assoc();
                
                if (!$sale) {
                    throw new Exception("Sale not found");
                }
                
                $customer_id = $sale['customer_id'];
                
                // Insert payment record - using correct column names
                $stmt = $conn->prepare("INSERT INTO payments 
                    (sales_id, customer_id, payment_method, amount_paid, reference_number, notes, processed_by, payment_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iisdssi", $sales_id, $customer_id, $payment_method, $amount_paid, 
                                $reference_number, $payment_notes, $processed_by);
                $stmt->execute();
                $payment_id = $conn->insert_id;
                $stmt->close();
                
                // Update credit account if payment method is Credit
                if ($payment_method === 'Credit' && $customer_id) {
                    // Check if customer has credit account - using credit_id as primary key
                    $credit_check = $conn->query("SELECT credit_id, current_balance, credit_limit 
                                                 FROM credit_accounts WHERE customer_id = $customer_id");
                    
                    if ($credit_check->num_rows > 0) {
                        $credit = $credit_check->fetch_assoc();
                        $new_balance = $credit['current_balance'] - $amount_paid;
                        
                        $conn->query("UPDATE credit_accounts 
                                     SET current_balance = $new_balance,
                                         last_payment_date = NOW(),
                                         last_payment_amount = $amount_paid
                                     WHERE customer_id = $customer_id");
                        
                        // Log credit transaction
                        $conn->query("INSERT INTO credit_transactions 
                            (credit_account_id, transaction_type, amount, reference_id, notes) 
                            VALUES ({$credit['credit_id']}, 'Payment', $amount_paid, $payment_id, 
                                    'Payment via $payment_method')");
                    }
                }
                
                // Update sale status
                $total_paid_res = $conn->query("SELECT SUM(amount_paid) AS paid FROM payments WHERE sales_id = $sales_id");
                $total_paid = $total_paid_res->fetch_assoc()['paid'] ?? 0;
                $final_amt = floatval($sale['final_amount']);
                
                if ($total_paid >= $final_amt) {
                    $new_status = 'Paid';
                    
                    // Generate warranty for products in this sale
                    if ($new_status === 'Paid') {
                        // Get products from sale items
                        $items_res = $conn->query("SELECT si.*, p.product_name, p.category 
                                                  FROM sales_items si 
                                                  LEFT JOIN products p ON si.product_id = p.product_id 
                                                  WHERE si.sales_id = $sales_id AND si.item_type = 'Product'");
                        
                        while ($item = $items_res->fetch_assoc()) {
                            // Check if product has warranty (e.g., batteries, parts)
                            $warranty_months = 0;
                            $warranty_terms = '';
                            
                            // Define warranty periods based on product category or name
                            if (stripos($item['product_name'] ?? $item['description'], 'battery') !== false) {
                                $warranty_months = 12; // 1 year for batteries
                                $warranty_terms = '12 months warranty on manufacturing defects';
                            } elseif (stripos($item['product_name'] ?? $item['description'], 'engine') !== false) {
                                $warranty_months = 6; // 6 months for engine parts
                                $warranty_terms = '6 months warranty on parts';
                            } elseif (stripos($item['category'] ?? '', 'Electrical') !== false) {
                                $warranty_months = 3; // 3 months for electrical parts
                                $warranty_terms = '3 months warranty on electrical components';
                            }
                            
                            if ($warranty_months > 0) {
                                $warranty_start = date('Y-m-d');
                                $warranty_end = date('Y-m-d', strtotime("+$warranty_months months"));
                                $warranty_number = 'WRN-' . date('Y') . str_pad($sales_id, 5, '0', STR_PAD_LEFT) . '-' . $item['sales_item_id'];
                                
                                $stmt = $conn->prepare("INSERT INTO warranties 
                                    (warranty_number, sales_id, sales_item_id, product_id, customer_id, 
                                     warranty_start, warranty_end, warranty_terms, warranty_status, created_by) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)");
                                $stmt->bind_param("siiiisssi", 
                                    $warranty_number, $sales_id, $item['sales_item_id'], 
                                    $item['product_id'], $customer_id, $warranty_start, $warranty_end, 
                                    $warranty_terms, $_SESSION['employeeID']);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }
                    }
                } elseif ($total_paid > 0) {
                    $new_status = 'Partially Paid';
                } else {
                    $new_status = 'Unpaid';
                }
                
                $conn->query("UPDATE sales SET status = '$new_status' WHERE sales_id = $sales_id");
                
                $conn->commit();
                
                $payment_method_display = $payment_method;
                if ($payment_method === 'Credit') {
                    $successMsg = "Payment recorded using Credit. New balance updated. Warranty generated for eligible items.";
                } else {
                    $successMsg = "Payment of ₱" . number_format($amount_paid, 2) . " via $payment_method_display recorded. ";
                    $successMsg .= ($reference_number ? "Ref #: $reference_number. " : "");
                    $successMsg .= "Sale status: $new_status.";
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $errorMsg = "Failed to process payment: " . $e->getMessage();
            }
        } else {
            $errorMsg = "Invalid payment data.";
        }
    }
    
    // Handle credit account creation
    if ($_POST['action'] === 'create_credit_account') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        $credit_limit = floatval($_POST['credit_limit'] ?? 0);
        $due_terms = intval($_POST['due_terms'] ?? 30); // days until due
        
        if ($customer_id && $credit_limit > 0) {
            $due_date = date('Y-m-d', strtotime("+$due_terms days"));
            
            $check = $conn->query("SELECT credit_id FROM credit_accounts WHERE customer_id = $customer_id");
            if ($check->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO credit_accounts 
                    (customer_id, credit_limit, current_balance, due_date, status, created_by) 
                    VALUES (?, ?, 0, ?, 'Active', ?)");
                $stmt->bind_param("iisi", $customer_id, $credit_limit, $due_date, $_SESSION['employeeID']);
                
                if ($stmt->execute()) {
                    $successMsg = "Credit account created successfully for customer.";
                } else {
                    $errorMsg = "Failed to create credit account.";
                }
                $stmt->close();
            } else {
                $errorMsg = "Customer already has a credit account.";
            }
        }
    }
}

// Filters
$filter_method = $_GET['method'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? date('Y-m-01');
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');
$filter_search = trim($_GET['search'] ?? '');

$where_clauses = ["1=1"];
if ($filter_method !== 'all') {
    $safe_method = $conn->real_escape_string($filter_method);
    $where_clauses[] = "p.payment_method = '$safe_method'";
}
if ($filter_date_from && $filter_date_to) {
    $where_clauses[] = "DATE(p.payment_date) BETWEEN '$filter_date_from' AND '$filter_date_to'";
}
if ($filter_search !== '') {
    $safe_search = $conn->real_escape_string($filter_search);
    // Using contact_number instead of phone to match your database
    $where_clauses[] = "(c.first_name LIKE '%$safe_search%' OR c.last_name LIKE '%$safe_search%' 
                        OR c.contact_number LIKE '%$safe_search%'
                        OR p.reference_number LIKE '%$safe_search%' 
                        OR COALESCE(s.invoice_number, '') LIKE '%$safe_search%')";
}
$where_sql = implode(' AND ', $where_clauses);

// Fetch payments with details - FIXED: using contact_number instead of phone
$payments = [];
$res = $conn->query("
    SELECT p.*, 
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           c.contact_number,
           s.invoice_number,
           s.final_amount AS sale_total,
           CONCAT(e.first_name, ' ', e.last_name) AS processed_by_name
    FROM payments p
    LEFT JOIN sales s ON p.sales_id = s.sales_id
    LEFT JOIN customers c ON p.customer_id = c.customer_id
    LEFT JOIN employee e ON p.processed_by = e.employeeID
    WHERE $where_sql
    ORDER BY p.payment_date DESC
    LIMIT 200
");
while ($res && $row = $res->fetch_assoc()) {
    $payments[] = $row;
}

// Summary stats
$stats = [
    'total_cash' => 0,
    'total_gcash' => 0,
    'total_bank' => 0,
    'total_credit' => 0,
    'total_all' => 0,
    'count' => 0
];

$stats_res = $conn->query("
    SELECT 
        SUM(CASE WHEN payment_method = 'Cash' THEN amount_paid ELSE 0 END) AS total_cash,
        SUM(CASE WHEN payment_method = 'GCash' THEN amount_paid ELSE 0 END) AS total_gcash,
        SUM(CASE WHEN payment_method = 'Bank' THEN amount_paid ELSE 0 END) AS total_bank,
        SUM(CASE WHEN payment_method = 'Credit' THEN amount_paid ELSE 0 END) AS total_credit,
        SUM(amount_paid) AS total_all,
        COUNT(*) AS count
    FROM payments p
    WHERE $where_sql
");
if ($stats_res && $row = $stats_res->fetch_assoc()) {
    $stats = $row;
}

// Get unpaid sales for payment modal
$unpaid_sales = [];
$u_res = $conn->query("
    SELECT s.sales_id, s.invoice_number, 
           CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
           c.customer_id,
           s.final_amount,
           COALESCE((SELECT SUM(amount_paid) FROM payments WHERE sales_id = s.sales_id), 0) AS total_paid,
           (s.final_amount - COALESCE((SELECT SUM(amount_paid) FROM payments WHERE sales_id = s.sales_id), 0)) AS balance
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.customer_id
    WHERE s.status IN ('Unpaid', 'Partially Paid')
    ORDER BY s.sales_date DESC
    LIMIT 50
");
while ($u_res && $row = $u_res->fetch_assoc()) {
    $unpaid_sales[] = $row;
}

// Get customers with credit accounts - FIXED: using credit_id and contact_number
$credit_customers = [];
$c_res = $conn->query("
    SELECT ca.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, 
           c.email, c.contact_number
    FROM credit_accounts ca
    JOIN customers c ON ca.customer_id = c.customer_id
    WHERE ca.status = 'Active' OR ca.status IS NULL
    ORDER BY ca.current_balance DESC
");
while ($c_res && $row = $c_res->fetch_assoc()) {
    $credit_customers[] = $row;
}

// Get all customers for credit account creation
$all_customers = [];
$ac_res = $conn->query("SELECT customer_id, CONCAT(first_name, ' ', last_name) AS name FROM customers ORDER BY first_name");
while ($ac_res && $row = $ac_res->fetch_assoc()) {
    $all_customers[] = $row;
}

// Get pending counts for sidebar
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
    <title>Payments — AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .content { padding: 24px 28px; }
        
        .payments-stats {
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
        
        .stat-card.featured {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
        }
        
        .stat-card .stat-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-family: "Syne", sans-serif;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .stat-sub {
            font-size: 11px;
            color: #aaa;
            margin-top: 4px;
        }
        
        .featured .stat-label,
        .featured .stat-sub {
            color: rgba(255,255,255,0.7);
        }
        
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
        
        .toolbar .search-box {
            flex: 2;
            min-width: 250px;
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
        }
        
        .toolbar select,
        .toolbar input[type=date] {
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
        }
        
        .btn-group {
            display: flex;
            gap: 8px;
            margin-left: auto;
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
        
        .btn-outline {
            background: #fff;
            color: var(--text);
            border: 1px solid var(--border);
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-outline:hover {
            background: #f9fafb;
        }
        
        .table-card {
            background: #fff;
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .table-card table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-card thead th {
            background: #f9fafb;
            padding: 14px 16px;
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
        }
        
        .table-card tbody tr:hover {
            background: #f9fafb;
        }
        
        .table-card td {
            padding: 14px 16px;
            font-size: 13px;
        }
        
        .payment-method-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .method-cash {
            background: #dcfce7;
            color: #166534;
        }
        
        .method-gcash {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .method-bank {
            background: #fef9c3;
            color: #854d0e;
        }
        
        .method-credit {
            background: #f3e8ff;
            color: #6b21a8;
        }
        
        .amount-positive {
            font-weight: 600;
            color: #059669;
        }
        
        .reference-number {
            font-family: monospace;
            font-size: 12px;
            background: #f3f4f6;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
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
            width: 600px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
        }
        
        .modal.large {
            width: 800px;
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
        
        .form-group input[readonly] {
            background: #f9fafb;
        }
        
        .payment-summary {
            background: #f0f9ff;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #cbd5e1;
        }
        
        .summary-item:last-child {
            border-bottom: none;
            font-weight: 700;
            font-size: 15px;
        }
        
        .credit-info {
            background: #fef3c7;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
            font-size: 13px;
        }
        
        .credit-info strong {
            color: #92400e;
        }
        
        .warranty-note {
            background: #dcfce7;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 12px;
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
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 6px;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        @media (max-width: 1024px) {
            .payments-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
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
                    <span class="pending-approvals-badge"><?= $activeJobs ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-item" href="sales.php"><i class="bi bi-currency-dollar"></i> Sales</a>
            <a class="nav-item active" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a>
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
                <span class="page-title">Payments</span>
                <span class="breadcrumb">Payment Processing & History</span>
            </div>
            <div class="topbar-right">
                <button class="btn-primary" onclick="openPaymentModal()">
                    <i class="bi bi-cash-stack"></i> New Payment
                </button>
                <button class="btn-outline" onclick="openCreditAccountModal()">
                    <i class="bi bi-wallet2"></i> Create Credit
                </button>
                <button class="logout-btn" onclick="window.location.href='logout.php'">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </div>
        </header>

        <div class="content">
            <?php if ($successMsg): ?>
                <div class="page-alert success">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($successMsg) ?>
                </div>
            <?php elseif ($errorMsg): ?>
                <div class="page-alert error">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="payments-stats">
                <div class="stat-card featured">
                    <div class="stat-icon">💰</div>
                    <div class="stat-label">Total Payments</div>
                    <div class="stat-value">₱<?= number_format($stats['total_all'] ?? 0, 2) ?></div>
                    <div class="stat-sub"><?= number_format($stats['count'] ?? 0) ?> transactions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💵</div>
                    <div class="stat-label">Cash</div>
                    <div class="stat-value">₱<?= number_format($stats['total_cash'] ?? 0, 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📱</div>
                    <div class="stat-label">GCash</div>
                    <div class="stat-value">₱<?= number_format($stats['total_gcash'] ?? 0, 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🏦</div>
                    <div class="stat-label">Bank Transfer</div>
                    <div class="stat-value">₱<?= number_format($stats['total_bank'] ?? 0, 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💳</div>
                    <div class="stat-label">Credit</div>
                    <div class="stat-value">₱<?= number_format($stats['total_credit'] ?? 0, 2) ?></div>
                </div>
            </div>

            <!-- Filter Toolbar -->
            <div class="toolbar">
                <form method="GET" style="display:contents;">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" placeholder="Search customer, reference, invoice..." 
                               value="<?= htmlspecialchars($filter_search) ?>">
                    </div>
                    <select name="method" onchange="this.form.submit()">
                        <option value="all" <?= $filter_method === 'all' ? 'selected' : '' ?>>All Methods</option>
                        <option value="Cash" <?= $filter_method === 'Cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="GCash" <?= $filter_method === 'GCash' ? 'selected' : '' ?>>GCash</option>
                        <option value="Bank" <?= $filter_method === 'Bank' ? 'selected' : '' ?>>Bank</option>
                        <option value="Credit" <?= $filter_method === 'Credit' ? 'selected' : '' ?>>Credit</option>
                    </select>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>" onchange="this.form.submit()">
                    <span style="color:var(--muted);">to</span>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>" onchange="this.form.submit()">
                    <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Apply</button>
                    <?php if ($filter_method !== 'all' || $filter_search !== '' || $filter_date_from !== date('Y-m-01') || $filter_date_to !== date('Y-m-d')): ?>
                        <a href="payments.php" style="font-size:13px; color:var(--muted);">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Payments Table -->
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Sale Total</th>
                            <th>Processed By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="9">
                                    <div style="text-align:center; padding:48px 20px; color:var(--muted);">
                                        <i class="bi bi-cash-stack" style="font-size:48px; opacity:0.3; display:block; margin-bottom:12px;"></i>
                                        <div>No payment records found.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $p): 
                                $method_class = match($p['payment_method']) {
                                    'Cash' => 'method-cash',
                                    'GCash' => 'method-gcash',
                                    'Bank' => 'method-bank',
                                    'Credit' => 'method-credit',
                                    default => ''
                                };
                                $method_icon = match($p['payment_method']) {
                                    'Cash' => 'bi-cash',
                                    'GCash' => 'bi-phone',
                                    'Bank' => 'bi-bank',
                                    'Credit' => 'bi-credit-card',
                                    default => 'bi-cash'
                                };
                            ?>
                                <tr>
                                    <td>
                                        <?= date('M d, Y', strtotime($p['payment_date'])) ?><br>
                                        <span style="font-size:11px; color:var(--muted);"><?= date('h:i A', strtotime($p['payment_date'])) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($p['invoice_number']): ?>
                                            <span class="sale-id"><?= htmlspecialchars($p['invoice_number']) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($p['customer_name'] ?? '—') ?></td>
                                    <td>
                                        <span class="payment-method-badge <?= $method_class ?>">
                                            <i class="bi <?= $method_icon ?>"></i>
                                            <?= htmlspecialchars($p['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($p['reference_number']): ?>
                                            <span class="reference-number"><?= htmlspecialchars($p['reference_number']) ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--muted);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="amount-positive">₱<?= number_format($p['amount_paid'], 2) ?></td>
                                    <td>₱<?= number_format($p['sale_total'] ?? 0, 2) ?></td>
                                    <td><?= htmlspecialchars($p['processed_by_name'] ?? '—') ?></td>
                                    <td>
                                        <?php if ($p['notes']): ?>
                                            <span title="<?= htmlspecialchars($p['notes']) ?>">
                                                <i class="bi bi-chat-dots"></i>
                                            </span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Credit Accounts Summary -->
            <?php if (!empty($credit_customers)): ?>
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div>
                        <div class="card-title">Active Credit Accounts</div>
                        <div class="card-sub">Customers with running credit</div>
                    </div>
                    <a class="card-link" href="credit_accounts.php">View All →</a>
                </div>
                <div style="padding: 16px 20px;">
                    <?php foreach ($credit_customers as $credit): 
                        $usage_percent = ($credit['current_balance'] / $credit['credit_limit']) * 100;
                        $days_until_due = ceil((strtotime($credit['due_date']) - time()) / (60 * 60 * 24));
                    ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--border);">
                            <div>
                                <strong><?= htmlspecialchars($credit['customer_name']) ?></strong><br>
                                <span style="font-size:12px; color:var(--muted);">
                                    Limit: ₱<?= number_format($credit['credit_limit'], 2) ?>
                                </span>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight:600; color:<?= $usage_percent > 80 ? '#dc2626' : '#059669' ?>;">
                                    ₱<?= number_format($credit['current_balance'], 2) ?>
                                </div>
                                <span style="font-size:11px; color:<?= $days_until_due < 7 ? '#f97316' : 'var(--muted)' ?>;">
                                    Due: <?= date('M d, Y', strtotime($credit['due_date'])) ?>
                                    <?php if ($days_until_due < 0): ?>
                                        (Overdue)
                                    <?php elseif ($days_until_due < 7): ?>
                                        (<?= $days_until_due ?> days left)
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Payment Modal -->
    <div class="modal-overlay" id="paymentModal">
        <div class="modal large">
            <div class="modal-header">
                <h2><i class="bi bi-cash-coin" style="color:var(--green);"></i> Process Payment</h2>
                <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="action" value="process_payment">
                <div class="modal-body">
                    <div class="tabs" id="paymentTabs">
                        <div class="tab active" data-method="Cash" onclick="switchPaymentMethod('Cash')">
                            <i class="bi bi-cash"></i> Cash
                        </div>
                        <div class="tab" data-method="GCash" onclick="switchPaymentMethod('GCash')">
                            <i class="bi bi-phone"></i> GCash
                        </div>
                        <div class="tab" data-method="Bank" onclick="switchPaymentMethod('Bank')">
                            <i class="bi bi-bank"></i> Bank
                        </div>
                        <div class="tab" data-method="Credit" onclick="switchPaymentMethod('Credit')">
                            <i class="bi bi-credit-card"></i> Credit
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Sale</label>
                            <select name="sales_id" id="payment_sales_id" required onchange="loadSaleDetails()">
                                <option value="">Choose Sale</option>
                                <?php foreach ($unpaid_sales as $sale): ?>
                                    <option value="<?= $sale['sales_id'] ?>" 
                                            data-customer="<?= htmlspecialchars($sale['customer_name']) ?>"
                                            data-customer-id="<?= $sale['customer_id'] ?>"
                                            data-total="<?= $sale['final_amount'] ?>"
                                            data-paid="<?= $sale['total_paid'] ?>"
                                            data-balance="<?= $sale['balance'] ?>">
                                        #<?= str_pad($sale['sales_id'], 5, '0', STR_PAD_LEFT) ?> - 
                                        <?= htmlspecialchars($sale['customer_name']) ?> - 
                                        ₱<?= number_format($sale['balance'], 2) ?> balance
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Payment Method</label>
                            <select name="payment_method" id="payment_method" required onchange="updateReferenceField()">
                                <option value="Cash">Cash</option>
                                <option value="GCash">GCash</option>
                                <option value="Bank">Bank Transfer</option>
                                <option value="Credit">Credit Account</option>
                            </select>
                        </div>
                    </div>

                    <div id="saleSummary" class="payment-summary" style="display:none;">
                        <div class="summary-item">
                            <span>Customer:</span>
                            <strong id="summary_customer">—</strong>
                        </div>
                        <div class="summary-item">
                            <span>Sale Total:</span>
                            <span id="summary_total">₱0.00</span>
                        </div>
                        <div class="summary-item">
                            <span>Already Paid:</span>
                            <span id="summary_paid">₱0.00</span>
                        </div>
                        <div class="summary-item">
                            <span>Balance Due:</span>
                            <strong id="summary_balance">₱0.00</strong>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Amount Paid *</label>
                            <input type="number" name="amount_paid" id="amount_paid" step="0.01" min="0.01" required oninput="validateAmount()">
                        </div>
                        <div class="form-group">
                            <label>Reference Number</label>
                            <input type="text" name="reference_number" id="reference_number" placeholder="Auto-generated if empty">
                        </div>
                    </div>

                    <div id="creditInfo" class="credit-info" style="display:none;">
                        <i class="bi bi-info-circle"></i>
                        <strong>Credit Payment:</strong> This will deduct from the customer's credit balance.
                        <span id="creditBalance"></span>
                    </div>

                    <div id="warrantyNote" class="warranty-note" style="display:none;">
                        <i class="bi bi-shield-check"></i>
                        <strong>Warranty Generation:</strong> Upon full payment, warranties will be automatically generated for eligible items.
                    </div>

                    <div class="form-row single">
                        <div class="form-group">
                            <label>Notes (Optional)</label>
                            <textarea name="payment_notes" rows="2" placeholder="Add payment notes..."></textarea>
                        </div>
                    </div>

                    <input type="hidden" name="customer_id" id="payment_customer_id">
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('paymentModal')" class="btn-outline">Cancel</button>
                    <button type="submit" class="btn-success" id="submitPayment">
                        <i class="bi bi-check-lg"></i> Process Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Create Credit Account Modal -->
    <div class="modal-overlay" id="creditAccountModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-wallet2" style="color:var(--accent);"></i> Create Credit Account</h2>
                <button class="modal-close" onclick="closeModal('creditAccountModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_credit_account">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Select Customer</label>
                            <select name="customer_id" required>
                                <option value="">Choose Customer</option>
                                <?php foreach ($all_customers as $c): ?>
                                    <option value="<?= $c['customer_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Credit Limit (₱)</label>
                            <input type="number" name="credit_limit" step="0.01" min="500" value="5000" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Terms (days)</label>
                            <select name="due_terms">
                                <option value="15">15 days</option>
                                <option value="30" selected>30 days</option>
                                <option value="45">45 days</option>
                                <option value="60">60 days</option>
                            </select>
                        </div>
                    </div>
                    <div class="credit-info">
                        <i class="bi bi-info-circle"></i>
                        Creating a credit account allows the customer to pay later. 
                        Credit payments will automatically update their balance.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('creditAccountModal')" class="btn-outline">Cancel</button>
                    <button type="submit" class="btn-primary">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('open');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => {
                if (e.target === m) m.classList.remove('open');
            });
        });

        // Open payment modal with optional pre-selected sale
        function openPaymentModal(salesId = null) {
            openModal('paymentModal');
            if (salesId) {
                document.getElementById('payment_sales_id').value = salesId;
                loadSaleDetails();
            }
        }

        // Open credit account modal
        function openCreditAccountModal() {
            openModal('creditAccountModal');
        }

        // Switch payment method tabs
        function switchPaymentMethod(method) {
            // Update tabs
            document.querySelectorAll('#paymentTabs .tab').forEach(tab => {
                if (tab.dataset.method === method) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });
            
            // Update select
            document.getElementById('payment_method').value = method;
            updateReferenceField();
            updateCreditInfo();
        }

        // Load sale details when selected
        function loadSaleDetails() {
            const select = document.getElementById('payment_sales_id');
            const selected = select.options[select.selectedIndex];
            
            if (selected.value) {
                const customer = selected.dataset.customer;
                const customerId = selected.dataset.customerId;
                const total = parseFloat(selected.dataset.total);
                const paid = parseFloat(selected.dataset.paid);
                const balance = parseFloat(selected.dataset.balance);
                
                document.getElementById('summary_customer').textContent = customer;
                document.getElementById('summary_total').textContent = '₱' + total.toFixed(2);
                document.getElementById('summary_paid').textContent = '₱' + paid.toFixed(2);
                document.getElementById('summary_balance').textContent = '₱' + balance.toFixed(2);
                document.getElementById('payment_customer_id').value = customerId;
                
                document.getElementById('saleSummary').style.display = 'block';
                
                // Set max amount to balance
                const amountInput = document.getElementById('amount_paid');
                amountInput.max = balance;
                amountInput.value = balance.toFixed(2);
                
                // Check if this will complete the payment
                const newPaid = paid + balance;
                if (newPaid >= total) {
                    document.getElementById('warrantyNote').style.display = 'block';
                } else {
                    document.getElementById('warrantyNote').style.display = 'none';
                }
                
                updateCreditInfo();
            } else {
                document.getElementById('saleSummary').style.display = 'none';
                document.getElementById('warrantyNote').style.display = 'none';
            }
        }

        // Validate amount doesn't exceed balance
        function validateAmount() {
            const select = document.getElementById('payment_sales_id');
            const selected = select.options[select.selectedIndex];
            
            if (selected.value) {
                const balance = parseFloat(selected.dataset.balance);
                const amount = parseFloat(document.getElementById('amount_paid').value);
                
                if (amount > balance) {
                    alert('Amount paid cannot exceed balance due!');
                    document.getElementById('amount_paid').value = balance.toFixed(2);
                }
            }
        }

        // Update reference field based on payment method
        function updateReferenceField() {
            const method = document.getElementById('payment_method').value;
            const refInput = document.getElementById('reference_number');
            
            if (method === 'Cash') {
                refInput.placeholder = 'Not required for cash';
                refInput.value = '';
            } else {
                refInput.placeholder = 'Enter or leave blank to auto-generate';
            }
            
            updateCreditInfo();
        }

        // Show/hide credit info
        function updateCreditInfo() {
            const method = document.getElementById('payment_method').value;
            const creditInfo = document.getElementById('creditInfo');
            const customerId = document.getElementById('payment_customer_id').value;
            
            if (method === 'Credit') {
                // Check if customer has credit account (simplified - in real app, you'd fetch this)
                <?php foreach ($credit_customers as $credit): ?>
                if (customerId == <?= $credit['customer_id'] ?>) {
                    document.getElementById('creditBalance').innerHTML = 
                        '<br>Current balance: ₱<?= number_format($credit['current_balance'], 2) ?> | ' +
                        'Limit: ₱<?= number_format($credit['credit_limit'], 2) ?>';
                    creditInfo.style.display = 'block';
                    return;
                }
                <?php endforeach; ?>
                
                // If no credit account found
                alert('This customer does not have a credit account. Please create one first.');
                document.getElementById('payment_method').value = 'Cash';
                switchPaymentMethod('Cash');
                creditInfo.style.display = 'none';
            } else {
                creditInfo.style.display = 'none';
            }
        }
    </script>
</body>
</html>
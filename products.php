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

// CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Add Product
    if ($_POST['action'] === 'add_product') {
        $product_name = trim($conn->real_escape_string($_POST['product_name'] ?? ''));
        $description = trim($conn->real_escape_string($_POST['description'] ?? ''));
        $category = trim($conn->real_escape_string($_POST['category'] ?? ''));
        $subcategory = trim($conn->real_escape_string($_POST['subcategory'] ?? ''));
        $vehicle_type = !empty($_POST['vehicle_type']) ? trim($conn->real_escape_string($_POST['vehicle_type'])) : null;
        $unit_price = floatval($_POST['unit_price'] ?? 0);
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
        $reorder_level = intval($_POST['reorder_level'] ?? 5);
        $supplier = trim($conn->real_escape_string($_POST['supplier'] ?? ''));
        $sku = strtoupper(uniqid('PRD-'));
        $status = 'Active';

        if ($product_name && $category && $unit_price > 0) {
            $stmt = $conn->prepare("INSERT INTO products (product_name, description, category, subcategory, vehicle_type, unit_price, cost_price, stock_quantity, reorder_level, supplier, sku, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssdddssss", $product_name, $description, $category, $subcategory, $vehicle_type, $unit_price, $cost_price, $stock_quantity, $reorder_level, $supplier, $sku, $status);
            
            if ($stmt->execute()) {
                $successMsg = "Product \"$product_name\" added successfully!";
            } else {
                $errorMsg = "Failed to add product: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errorMsg = "Product name, category, and unit price are required.";
        }
    }

    // Edit Product
    if ($_POST['action'] === 'edit_product') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $product_name = trim($conn->real_escape_string($_POST['product_name'] ?? ''));
        $description = trim($conn->real_escape_string($_POST['description'] ?? ''));
        $category = trim($conn->real_escape_string($_POST['category'] ?? ''));
        $subcategory = trim($conn->real_escape_string($_POST['subcategory'] ?? ''));
        $vehicle_type = !empty($_POST['vehicle_type']) ? trim($conn->real_escape_string($_POST['vehicle_type'])) : null;
        $unit_price = floatval($_POST['unit_price'] ?? 0);
        $cost_price = floatval($_POST['cost_price'] ?? 0);
        $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
        $reorder_level = intval($_POST['reorder_level'] ?? 5);
        $supplier = trim($conn->real_escape_string($_POST['supplier'] ?? ''));
        $status = trim($conn->real_escape_string($_POST['status'] ?? 'Active'));

        if ($product_id && $product_name && $unit_price > 0) {
            $stmt = $conn->prepare("UPDATE products SET product_name=?, description=?, category=?, subcategory=?, vehicle_type=?, unit_price=?, cost_price=?, stock_quantity=?, reorder_level=?, supplier=?, status=? WHERE product_id=?");
            $stmt->bind_param("sssssdddisssi", $product_name, $description, $category, $subcategory, $vehicle_type, $unit_price, $cost_price, $stock_quantity, $reorder_level, $supplier, $status, $product_id);
            
            if ($stmt->execute()) {
                $successMsg = "Product updated successfully!";
            } else {
                $errorMsg = "Failed to update product: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $errorMsg = "Invalid product data.";
        }
    }

    // Delete Product
    if ($_POST['action'] === 'delete_product') {
        $product_id = intval($_POST['product_id'] ?? 0);
        if ($product_id) {
            $check = $conn->query("SELECT COUNT(*) as cnt FROM sales_items WHERE product_id = $product_id");
            $used = $check->fetch_assoc()['cnt'] > 0;
            
            if ($used) {
                $errorMsg = "Cannot delete product - it has been used in sales.";
            } else {
                $conn->query("DELETE FROM products WHERE product_id = $product_id");
                $successMsg = "Product deleted successfully.";
            }
        }
    }

    // Update Stock
    if ($_POST['action'] === 'update_stock') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $new_quantity = intval($_POST['stock_quantity'] ?? 0);
        
        if ($product_id) {
            $conn->query("UPDATE products SET stock_quantity = $new_quantity WHERE product_id = $product_id");
            $successMsg = "Stock updated successfully.";
        }
    }
}

// Filters
$filter_category = $_GET['category'] ?? 'all';
$filter_subcategory = $_GET['subcategory'] ?? 'all';
$filter_vehicle = $_GET['vehicle_type'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_search = trim($_GET['search'] ?? '');
$filter_sort = $_GET['sort'] ?? 'name_asc';

$where_clauses = ["1=1"];

if ($filter_category !== 'all') {
    $safe_category = $conn->real_escape_string($filter_category);
    $where_clauses[] = "category = '$safe_category'";
}

if ($filter_subcategory !== 'all') {
    $safe_subcategory = $conn->real_escape_string($filter_subcategory);
    $where_clauses[] = "subcategory = '$safe_subcategory'";
}

if ($filter_vehicle !== 'all') {
    $safe_vehicle = $conn->real_escape_string($filter_vehicle);
    $where_clauses[] = "vehicle_type = '$safe_vehicle'";
}

if ($filter_status !== 'all') {
    $safe_status = $conn->real_escape_string($filter_status);
    $where_clauses[] = "status = '$safe_status'";
}

if ($filter_search !== '') {
    $safe_search = $conn->real_escape_string($filter_search);
    $where_clauses[] = "(product_name LIKE '%$safe_search%' OR description LIKE '%$safe_search%' OR sku LIKE '%$safe_search%')";
}

switch ($filter_sort) {
    case 'name_desc':
        $order_sql = 'product_name DESC';
        break;
    case 'price_asc':
        $order_sql = 'unit_price ASC';
        break;
    case 'price_desc':
        $order_sql = 'unit_price DESC';
        break;
    case 'stock_asc':
        $order_sql = 'stock_quantity ASC';
        break;
    case 'stock_desc':
        $order_sql = 'stock_quantity DESC';
        break;
    default:
        $order_sql = 'product_name ASC';
}

$where_sql = implode(' AND ', $where_clauses);

// Fetch products
$products = [];
$res = $conn->query("SELECT * FROM products WHERE $where_sql ORDER BY $order_sql LIMIT 200");
while ($res && $row = $res->fetch_assoc()) {
    $products[] = $row;
}

// Get distinct categories for filters
$categories = [];
$cat_res = $conn->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category");
while ($cat_res && $row = $cat_res->fetch_assoc()) {
    $categories[] = $row['category'];
}

// Get distinct subcategories
$subcategories = [];
$sub_res = $conn->query("SELECT DISTINCT subcategory FROM products WHERE subcategory IS NOT NULL ORDER BY subcategory");
while ($sub_res && $row = $sub_res->fetch_assoc()) {
    $subcategories[] = $row['subcategory'];
}

// Get distinct vehicle types
$vehicle_types = ['Sedan', 'SUV', 'Pickup', 'Truck'];

// Summary stats
$stats = [
    'total_products' => 0,
    'low_stock' => 0,
    'total_value' => 0,
    'total_cost' => 0
];

$stats_res = $conn->query("SELECT COUNT(*) as total, SUM(stock_quantity * unit_price) as value, SUM(stock_quantity * cost_price) as cost FROM products WHERE status = 'Active'");
if ($stats_res && $row = $stats_res->fetch_assoc()) {
    $stats['total_products'] = $row['total'];
    $stats['total_value'] = $row['value'] ?? 0;
    $stats['total_cost'] = $row['cost'] ?? 0;
}

$low_stock_res = $conn->query("SELECT COUNT(*) as cnt FROM products WHERE stock_quantity <= reorder_level AND status = 'Active'");
if ($low_stock_res && $row = $low_stock_res->fetch_assoc()) {
    $stats['low_stock'] = $row['cnt'];
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
    <title>Products — AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .content {
            padding: 24px 28px;
        }

        .products-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #fff;
            border-radius: var(--card-radius);
            padding: 20px;
            border: 1px solid var(--border);
        }

        .stat-card.featured {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
        }

        .stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
            color: var(--muted);
        }

        .featured .stat-label {
            color: rgba(255, 255, 255, .7);
        }

        .stat-value {
            font-family: "Syne", sans-serif;
            font-size: 26px;
            font-weight: 700;
            line-height: 1.1;
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
        }

        .toolbar select {
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            cursor: pointer;
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

        .btn-secondary {
            background: #6b7280;
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

        .btn-danger {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
        }

        .btn-warning {
            background: #f59e0b;
            color: #fff;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
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
        }

        .table-card tbody tr:hover {
            background: #f9fafb;
        }

        .table-card td {
            padding: 12px 16px;
            font-size: 13px;
        }

        .product-name {
            font-weight: 600;
        }

        .product-sku {
            font-size: 11px;
            color: var(--muted);
            font-family: monospace;
        }

        .price {
            font-weight: 600;
            color: var(--accent);
        }

        .stock-low {
            color: #dc2626;
            font-weight: 600;
        }

        .stock-normal {
            color: #10b981;
        }

        .badge {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-active {
            background: #dcfce7;
            color: #166534;
        }

        .badge-inactive {
            background: #fee2e2;
            color: #991b1b;
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
            width: 600px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
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
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
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

        .action-btns {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .action-btn.edit {
            background: #eff6ff;
            color: #2563eb;
        }

        .action-btn.edit:hover {
            background: #dbeafe;
            transform: translateY(-1px);
        }

        .action-btn.stock {
            background: #fef3c7;
            color: #d97706;
        }

        .action-btn.stock:hover {
            background: #fde68a;
            transform: translateY(-1px);
        }

        .action-btn.delete {
            background: #fee2e2;
            color: #dc2626;
        }

        .action-btn.delete:hover {
            background: #fecaca;
            transform: translateY(-1px);
        }

        .btn-cancel {
            padding: 9px 18px;
            background: #f3f4f6;
            border: 1px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }

        .btn-cancel:hover {
            background: #e5e7eb;
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
            <a class="nav-item" href="new_job_order.php">
                <i class="bi bi-clipboard-data"></i> Job Orders
                <?php if ($activeJobs > 0): ?>
                    <span class="pending-approvals-badge" style="background:var(--accent);"><?= $activeJobs ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-item" href="sales.php"><i class="bi bi-currency-dollar"></i> Sales</a>
            <a class="nav-item" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a>
            <a class="nav-item active" href="products.php"><i class="bi bi-box-seam"></i> Products</a>
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
                <span class="page-title">Products</span>
                <span class="breadcrumb">Inventory Management</span>
            </div>
            <div class="topbar-right">
                <button class="btn-primary" onclick="openAddModal()">
                    <i class="bi bi-plus-lg"></i> Add Product
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

            <div class="products-stats">
                <div class="stat-card featured">
                    <div class="stat-label">Total Products</div>
                    <div class="stat-value"><?= number_format($stats['total_products']) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Low Stock Items</div>
                    <div class="stat-value" style="color:<?= $stats['low_stock'] > 0 ? '#dc2626' : '#10b981' ?>">
                        <?= $stats['low_stock'] ?>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Inventory Value</div>
                    <div class="stat-value">₱<?= number_format($stats['total_value'], 2) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Cost</div>
                    <div class="stat-value">₱<?= number_format($stats['total_cost'], 2) ?></div>
                </div>
            </div>

            <div class="toolbar">
                <form method="GET" style="display:contents; width:100%;">
                    <div class="search-box">
                        <i class="bi bi-search"></i>
                        <input type="text" name="search" placeholder="Search products..." value="<?= htmlspecialchars($filter_search) ?>">
                    </div>
                    <select name="category" onchange="this.form.submit()">
                        <option value="all" <?= $filter_category === 'all' ? 'selected' : '' ?>>All Categories</option>
                        <option value="Battery & Electrical" <?= $filter_category === 'Battery & Electrical' ? 'selected' : '' ?>>Battery & Electrical</option>
                        <option value="Fluids & Lubricants" <?= $filter_category === 'Fluids & Lubricants' ? 'selected' : '' ?>>Fluids & Lubricants</option>
                        <option value="Reconditioned" <?= $filter_category === 'Reconditioned' ? 'selected' : '' ?>>Reconditioned</option>
                    </select>
                    <select name="vehicle_type" onchange="this.form.submit()">
                        <option value="all" <?= $filter_vehicle === 'all' ? 'selected' : '' ?>>All Vehicle Types</option>
                        <option value="Sedan" <?= $filter_vehicle === 'Sedan' ? 'selected' : '' ?>>Sedan</option>
                        <option value="SUV" <?= $filter_vehicle === 'SUV' ? 'selected' : '' ?>>SUV</option>
                        <option value="Pickup" <?= $filter_vehicle === 'Pickup' ? 'selected' : '' ?>>Pickup</option>
                        <option value="Truck" <?= $filter_vehicle === 'Truck' ? 'selected' : '' ?>>Truck</option>
                    </select>
                    <select name="sort" onchange="this.form.submit()">
                        <option value="name_asc" <?= $filter_sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $filter_sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="price_asc" <?= $filter_sort === 'price_asc' ? 'selected' : '' ?>>Price Low-High</option>
                        <option value="price_desc" <?= $filter_sort === 'price_desc' ? 'selected' : '' ?>>Price High-Low</option>
                        <option value="stock_asc" <?= $filter_sort === 'stock_asc' ? 'selected' : '' ?>>Stock Low-High</option>
                    </select>
                    <button type="submit" class="btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                    <?php if ($filter_category !== 'all' || $filter_search !== '' || $filter_vehicle !== 'all'): ?>
                        <a href="products.php" style="font-size:13px; color:var(--muted);">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="table-card">
                 <table>
                    <thead>
                         <tr>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Vehicle Type</th>
                            <th>Unit Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                         </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                             <tr>
                                <td colspan="8">
                                    <div class="empty-state">
                                        <i class="bi bi-box-seam"></i>
                                        <div>No products found.</div>
                                        <button class="btn-primary" style="margin-top:20px;" onclick="openAddModal()">
                                            <i class="bi bi-plus-lg"></i> Add First Product
                                        </button>
                                    </div>
                                 </td>
                             </tr>
                        <?php else: ?>
                            <?php foreach ($products as $p): 
                                $stock_class = $p['stock_quantity'] <= $p['reorder_level'] ? 'stock-low' : 'stock-normal';
                                ?>
                                 <tr>
                                    <td><span class="product-sku"><?= htmlspecialchars($p['sku']) ?></span></td>
                                    <td>
                                        <div class="product-name"><?= htmlspecialchars($p['product_name']) ?></div>
                                        <div style="font-size:11px; color:var(--muted);"><?= htmlspecialchars(substr($p['description'] ?? '', 0, 50)) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($p['category']) ?><br><small style="color:var(--muted);"><?= htmlspecialchars($p['subcategory'] ?? '') ?></small></td>
                                    <td><?= htmlspecialchars($p['vehicle_type'] ?? '—') ?></td>
                                    <td class="price">₱<?= number_format($p['unit_price'], 2) ?></td>
                                    <td>
                                        <span class="<?= $stock_class ?>"><?= number_format($p['stock_quantity']) ?></span>
                                        <?php if ($p['stock_quantity'] <= $p['reorder_level'] && $p['stock_quantity'] > 0): ?>
                                            <div style="font-size:10px; color:#f59e0b;">Low Stock</div>
                                        <?php elseif ($p['stock_quantity'] <= 0): ?>
                                            <div style="font-size:10px; color:#dc2626;">Out of Stock</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $p['status'] === 'Active' ? 'badge-active' : 'badge-inactive' ?>">
                                            <?= htmlspecialchars($p['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="action-btn edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($p)) ?>)" title="Edit Product">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <button class="action-btn stock" onclick="openStockModal(<?= $p['product_id'] ?>, '<?= htmlspecialchars(addslashes($p['product_name'])) ?>', <?= $p['stock_quantity'] ?>)" title="Update Stock">
                                                <i class="bi bi-box-seam"></i> Stock
                                            </button>
                                            <?php if ($isOwner): ?>
                                                <button class="action-btn delete" onclick="confirmDelete(<?= $p['product_id'] ?>, '<?= htmlspecialchars(addslashes($p['product_name'])) ?>')" title="Delete Product">
                                                    <i class="bi bi-trash"></i> Delete
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

    <!-- Add Product Modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal large">
            <div class="modal-header">
                <h2><i class="bi bi-plus-circle"></i> Add Product</h2>
                <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST" id="addProductForm">
                <input type="hidden" name="action" value="add_product">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" name="product_name" required>
                        </div>
                        <div class="form-group">
                            <label>Category *</label>
                            <select name="category" id="add_category" required onchange="updateSubcategoryOptions('add')">
                                <option value="">Select Category</option>
                                <option value="Battery & Electrical">Battery & Electrical</option>
                                <option value="Fluids & Lubricants">Fluids & Lubricants</option>
                                <option value="Reconditioned">Reconditioned</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Subcategory</label>
                            <select name="subcategory" id="add_subcategory">
                                <option value="">Select Subcategory</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Vehicle Type</label>
                            <select name="vehicle_type" id="add_vehicle_type">
                                <option value="">N/A</option>
                                <option value="Sedan">Sedan</option>
                                <option value="SUV">SUV</option>
                                <option value="Pickup">Pickup</option>
                                <option value="Truck">Truck</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Unit Price *</label>
                            <input type="number" name="unit_price" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Cost Price</label>
                            <input type="number" name="cost_price" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock Quantity</label>
                            <input type="number" name="stock_quantity" value="0" min="0">
                        </div>
                        <div class="form-group">
                            <label>Reorder Level</label>
                            <input type="number" name="reorder_level" value="5" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Supplier</label>
                            <input type="text" name="supplier" placeholder="Supplier name">
                        </div>
                    </div>
                    <div class="form-row single">
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" rows="3" placeholder="Product description..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addModal')" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Add Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal large">
            <div class="modal-header">
                <h2><i class="bi bi-pencil-square"></i> Edit Product</h2>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" id="edit_id">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" name="product_name" id="edit_name" required>
                        </div>
                        <div class="form-group">
                            <label>Category *</label>
                            <select name="category" id="edit_category" required onchange="updateSubcategoryOptions('edit')">
                                <option value="Battery & Electrical">Battery & Electrical</option>
                                <option value="Fluids & Lubricants">Fluids & Lubricants</option>
                                <option value="Reconditioned">Reconditioned</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Subcategory</label>
                            <select name="subcategory" id="edit_subcategory">
                                <option value="">Select Subcategory</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Vehicle Type</label>
                            <select name="vehicle_type" id="edit_vehicle_type">
                                <option value="">N/A</option>
                                <option value="Sedan">Sedan</option>
                                <option value="SUV">SUV</option>
                                <option value="Pickup">Pickup</option>
                                <option value="Truck">Truck</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Unit Price *</label>
                            <input type="number" name="unit_price" id="edit_price" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label>Cost Price</label>
                            <input type="number" name="cost_price" id="edit_cost" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Stock Quantity</label>
                            <input type="number" name="stock_quantity" id="edit_stock" min="0">
                        </div>
                        <div class="form-group">
                            <label>Reorder Level</label>
                            <input type="number" name="reorder_level" id="edit_reorder" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Supplier</label>
                            <input type="text" name="supplier" id="edit_supplier">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="edit_status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row single">
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" id="edit_description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('editModal')" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Update Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Stock Modal -->
    <div class="modal-overlay" id="stockModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-box-seam"></i> Update Stock</h2>
                <button class="modal-close" onclick="closeModal('stockModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_stock">
                <input type="hidden" name="product_id" id="stock_id">
                <div class="modal-body">
                    <p id="stock_product_name" style="margin-bottom:16px; font-weight:500;"></p>
                    <div class="form-row single">
                        <div class="form-group">
                            <label>New Stock Quantity</label>
                            <input type="number" name="stock_quantity" id="stock_quantity" min="0" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('stockModal')" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Update Stock</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-trash"></i> Delete Product</h2>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="delete_product">
                <input type="hidden" name="product_id" id="delete_id">
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="delete_product_name"></strong>?</p>
                    <p style="color:#dc2626; font-size:12px; margin-top:8px;">This action cannot be undone if the product hasn't been used in sales.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('deleteModal')" class="btn-cancel">Cancel</button>
                    <button type="submit" class="btn-danger">Delete Product</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }

        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
        });

        function openAddModal() {
            openModal('addModal');
        }

        function openEditModal(product) {
            document.getElementById('edit_id').value = product.product_id;
            document.getElementById('edit_name').value = product.product_name;
            document.getElementById('edit_category').value = product.category;
            document.getElementById('edit_subcategory').value = product.subcategory || '';
            document.getElementById('edit_vehicle_type').value = product.vehicle_type || '';
            document.getElementById('edit_price').value = product.unit_price;
            document.getElementById('edit_cost').value = product.cost_price;
            document.getElementById('edit_stock').value = product.stock_quantity;
            document.getElementById('edit_reorder').value = product.reorder_level;
            document.getElementById('edit_supplier').value = product.supplier || '';
            document.getElementById('edit_status').value = product.status;
            document.getElementById('edit_description').value = product.description || '';
            
            updateSubcategoryOptions('edit', product.category, product.subcategory);
            openModal('editModal');
        }

        function openStockModal(id, name, currentStock) {
            document.getElementById('stock_id').value = id;
            document.getElementById('stock_product_name').innerHTML = '<strong>' + name + '</strong><br>Current Stock: ' + currentStock;
            document.getElementById('stock_quantity').value = currentStock;
            openModal('stockModal');
        }

        function confirmDelete(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_product_name').textContent = name;
            openModal('deleteModal');
        }

        function updateSubcategoryOptions(formType, category = null, selected = null) {
            const categorySelect = document.getElementById(formType + '_category');
            const subcategorySelect = document.getElementById(formType + '_subcategory');
            
            let selectedCategory = category;
            if (!selectedCategory && categorySelect) {
                selectedCategory = categorySelect.value;
            }
            
            let options = '<option value="">Select Subcategory</option>';
            
            if (selectedCategory === 'Battery & Electrical') {
                options += '<option value="Car Battery">Car Battery</option>';
                options += '<option value="Alternator">Alternator</option>';
                options += '<option value="Starter Motor">Starter Motor</option>';
                options += '<option value="Fuses">Fuses</option>';
                options += '<option value="Relay Switch">Relay Switch</option>';
            } else if (selectedCategory === 'Fluids & Lubricants') {
                options += '<option value="Coolant">Coolant</option>';
                options += '<option value="Engine Oil">Engine Oil</option>';
                options += '<option value="Brake Fluid">Brake Fluid</option>';
            } else if (selectedCategory === 'Reconditioned') {
                options += '<option value="Battery">Battery</option>';
                options += '<option value="Alternator">Alternator</option>';
                options += '<option value="Starter Motor">Starter Motor</option>';
            }
            
            if (subcategorySelect) {
                subcategorySelect.innerHTML = options;
                if (selected) {
                    subcategorySelect.value = selected;
                }
            }
        }

        // Close modals with Escape key
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
            }
        });
    </script>
</body>

</html>
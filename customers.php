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

/* CRUD */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_customer') {
        $fn = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $ln = trim($conn->real_escape_string($_POST['last_name'] ?? ''));
        $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
        $phone = trim($conn->real_escape_string($_POST['contact_number'] ?? ''));
        $addr = trim($conn->real_escape_string($_POST['address'] ?? ''));

        if ($fn && $ln) {
            $conn->query("INSERT INTO customers (first_name, last_name, email, contact_number, address)
                          VALUES ('$fn','$ln','$email','$phone','$addr')");
            $successMsg = "Customer \"$fn $ln\" added successfully.";
        } else {
            $errorMsg = "First name and last name are required.";
        }
    }

    if ($_POST['action'] === 'edit_customer') {
        $id = intval($_POST['customer_id'] ?? 0);
        $fn = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $ln = trim($conn->real_escape_string($_POST['last_name'] ?? ''));
        $email = trim($conn->real_escape_string($_POST['email'] ?? ''));
        $phone = trim($conn->real_escape_string($_POST['contact_number'] ?? ''));
        $addr = trim($conn->real_escape_string($_POST['address'] ?? ''));

        if ($id && $fn && $ln) {
            $conn->query("UPDATE customers SET first_name='$fn', last_name='$ln', email='$email',
                          contact_number='$phone', address='$addr' WHERE customer_id=$id");
            $successMsg = "Customer updated successfully.";
        } else {
            $errorMsg = "Invalid data.";
        }
    }

    if ($_POST['action'] === 'delete_customer') {
        $id = intval($_POST['customer_id'] ?? 0);
        if ($id) {
            $conn->query("DELETE FROM customers WHERE customer_id = $id");
            $successMsg = "Customer deleted.";
        }
    }
}

/* Filters & Search */
$search = trim($_GET['search'] ?? '');
$filter_sort = $_GET['sort'] ?? 'newest';

$where = '';
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where = "WHERE (c.first_name LIKE '%$s%' OR c.last_name LIKE '%$s%'
                     OR c.email LIKE '%$s%' OR c.contact_number LIKE '%$s%')";
}

$order = match ($filter_sort) {
    'name_az' => 'c.first_name ASC',
    'name_za' => 'c.first_name DESC',
    'oldest' => 'c.customer_id ASC',
    default => 'c.customer_id DESC',
};

$customers = [];
$res = $conn->query("
    SELECT c.*,
           COUNT(DISTINCT s.sales_id)     AS total_transactions,
           COALESCE(SUM(s.final_amount),0) AS total_spent,
           MAX(s.sales_date)               AS last_transaction
    FROM customers c
    LEFT JOIN sales s ON s.customer_id = c.customer_id
    $where
    GROUP BY c.customer_id
    ORDER BY $order
    LIMIT 200
");
while ($res && $row = $res->fetch_assoc()) {
    $customers[] = $row;
}

$total_customers = 0;

$sc = $conn->query("SELECT COUNT(*) AS cnt FROM customers");
if ($sc)
    $total_customers = $sc->fetch_assoc()['cnt'];

$pendingApprovals = 0;
$activeJobs = 0;
if ($isOwner) {
    $pa = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved=0");
    if ($pa)
        $pendingApprovals = $pa->fetch_assoc()['cnt'];
}
$aj = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status NOT IN ('Completed','Cancelled')");
if ($aj)
    $activeJobs = $aj->fetch_assoc()['cnt'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Customers · AutoBert</title>
    <link rel="stylesheet" href="style.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@600;700&display=swap" rel="stylesheet" />

    <style>
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-bar .search-bar {width: 280px;}

        .filter-bar select {
            padding: 7px 32px 7px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            font-size: 13px;
            font-family: "DM Sans", sans-serif;
            color: var(--text);
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            outline: none;
        }

        .filter-bar select:focus {
            border-color: var(--accent);
        }

        .customer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .customer-table th {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--muted);
            font-weight: 500;
            padding: 12px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .customer-table td {
            padding: 13px 20px;
            font-size: 13px;
            border-bottom: 1px solid var(--bg);
            vertical-align: middle;
        }

        .customer-table tr:last-child td {
            border-bottom: none;
        }

        .customer-table tbody tr:hover td {
            background: #fafaf8;
            cursor: pointer;
        }

        .cust-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            color: #fff;
            flex-shrink: 0;
        }

        .cust-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cust-name {
            font-weight: 600;
            font-size: 13px;
        }

        .cust-email {
            font-size: 11px;
            color: var(--muted);
            margin-top: 1px;
        }

        .cust-id {
            font-family: "Syne", sans-serif;
            font-weight: 600;
            color: var(--accent);
            font-size: 12px;
        }

        .action-btns {
            display: flex;
            gap: 6px;
        }

        .action-btn {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            border: 1px solid var(--border);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            color: var(--muted);
            transition: all .15s;
            text-decoration: none;
        }

        .action-btn:hover {
            background: var(--bg);
            color: var(--accent);
            border-color: var(--accent);
        }

        .action-btn.danger:hover {
            background: #fee2e2;
            color: var(--red);
            border-color: var(--red);
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {display: flex;}

        .modal {
            background: var(--surface);
            border-radius: 16px;
            width: 520px;
            max-width: 95vw;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .18);
            animation: fadeUp .25s ease;
        }

        .modal-header {
            padding: 20px 24px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h2 {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-close {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            border: none;
            background: var(--bg);
            font-size: 16px;
            cursor: pointer;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background .15s;
        }

        .modal-close:hover {background: #eceae6;}
        .modal-body {padding: 24px;}

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .form-row.full {grid-template-columns: 1fr;}

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-group label {
            font-size: 12px;
            font-weight: 500;
            color: #555;
        }

        .form-group input,
        .form-group textarea {
            padding: 9px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            font-family: "DM Sans", sans-serif;
            color: var(--text);
            background: var(--bg);
            outline: none;
            transition: border-color .2s, background .2s;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .08);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 72px;
        }

        .drawer-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            z-index: 200;
        }

        .drawer-overlay.open {display: block;}

        .drawer {
            position: fixed;
            top: 0;
            right: -440px;
            width: 420px;
            height: 100vh;
            background: var(--surface);
            z-index: 201;
            box-shadow: -8px 0 32px rgba(0, 0, 0, .12);
            display: flex;
            flex-direction: column;
            transition: right .3s cubic-bezier(.4, 0, .2, 1);
            overflow-y: auto;
        }

        .drawer.open {right: 0;}

        .drawer-header {
            padding: 24px 24px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .drawer-body {padding: 24px; flex: 1;}

        .profile-hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            text-align: center;
        }

        .profile-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 700;
            color: #fff;
        }

        .profile-name {
            font-size: 17px;
            font-weight: 700;
        }

        .profile-since {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        .profile-stats {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 24px;
        }

        .pstat {
            background: var(--bg);
            border-radius: 10px;
            padding: 12px;
            text-align: center;
        }

        .pstat-value {
            font-size: 16px;
            font-weight: 700;
        }

        .pstat-label {
            font-size: 10px;
            color: var(--muted);
            margin-top: 3px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .info-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .info-item i {
            font-size: 14px;
            color: var(--accent);
            margin-top: 1px;
            flex-shrink: 0;
        }

        .info-label {
            font-size: 10px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        .info-value {
            font-size: 13px;
            font-weight: 500;
            margin-top: 1px;
        }

        .section-divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 20px 0;
        }

        .transactions-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .txn-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: var(--bg);
            border-radius: 10px;
            font-size: 12px;
        }

        .txn-id {
            font-family: "Syne", sans-serif;
            font-weight: 600;
            color: var(--accent);
            font-size: 11px;
        }

        .txn-amount {
            font-weight: 700;
            color: var(--text);
        }

        .txn-date {
            font-size: 10px;
            color: var(--muted);
            margin-top: 2px;
        }

        .ac-0 {background: #2563eb;}
        .ac-1 {background: #16a34a;}
        .ac-2 {background: #dc2626;}
        .ac-3 {background: #9333ea;}
        .ac-4 {background: #f97316;}
        .ac-5 {background: #0891b2;}
        .ac-6 {background: #be185d;}
        .ac-7 {background: #854d0e;}

        .confirm-modal {width: 400px;}

        .confirm-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #fee2e2;
            color: var(--red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin: 0 auto 12px;
        }

        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .drawer {
                width: 100vw;
            }
        }
    </style>
</head>

<body>

    <?php include 'approval_page.php'; ?>

    <div class="main">
        <div class="topbar">
            <div class="topbar-left">
                <span class="page-title">Customers</span>
                <span class="breadcrumb" style="margin-left:8px;">/ <?= count($customers) ?> records</span>
            </div>
            <div class="topbar-right">
                <div class="search-bar">
                    <i class="bi bi-search"></i>
                    <input type="text" id="liveSearch" placeholder="Search customers…"
                        value="<?= htmlspecialchars($search) ?>" oninput="liveFilter(this.value)" />
                </div>
                <button class="btn-primary" onclick="openModal('addModal')">
                    <i class="bi bi-person-plus"></i> Add Customer
                </button>
            </div>
        </div>

        <div class="content">

            <?php if ($successMsg): ?>
                <div class="alert success" style="margin-bottom:16px;">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($successMsg) ?>
                </div>
            <?php elseif ($errorMsg): ?>
                <div class="alert error" style="margin-bottom:16px;">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- stat cards -->
            <div style="margin-bottom:24px;">
                <div class="stat-card featured"
                    style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:20px;">
                    <div class="stat-icon"><i class="bi bi-people-fill" style="color:#fff;"></i></div>
                    <div class="stat-label">Total Customers</div>
                    <div class="stat-value"><?= number_format($total_customers) ?></div>
                    <div class="stat-change neutral">All registered</div>
                </div>
            </div>

            <!-- filter bar -->
            <div class="filter-bar">
                <div class="search-bar" style="display:none;"><!-- handled by topbar --></div>
                <select onchange="location.href='customers.php?sort='+this.value+'&search=<?= urlencode($search) ?>'">
                    <option value="newest" <?= $filter_sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="oldest" <?= $filter_sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                    <option value="name_az" <?= $filter_sort === 'name_az' ? 'selected' : '' ?>>Name A–Z</option>
                    <option value="name_za" <?= $filter_sort === 'name_za' ? 'selected' : '' ?>>Name Z–A</option>
                </select>
                <span style="font-size:12px; color:var(--muted); margin-left:auto;">
                    Showing <strong id="visibleCount"><?= count($customers) ?></strong> customers
                </span>
            </div>

            <!-- customers table -->
            <div class="card" style="animation: fadeUp .45s ease both;">
                <div class="card-header">
                    <div>
                        <div class="card-title">Customer Directory</div>
                        <div class="card-sub">Click any row to view profile</div>
                    </div>
                </div>
                <?php if (empty($customers)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-people"></i></div>
                        <div class="empty-text">No customers found.<br>Add your first customer to get started.</div>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="customer-table" id="customerTable">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Contact</th>
                                    <th>Transactions</th>
                                    <th>Total Spent</th>
                                    <th>Last Visit</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $i => $c):
                                    $initials = strtoupper(substr($c['first_name'], 0, 1) . substr($c['last_name'], 0, 1));
                                    $colorClass = 'ac-' . ($c['customer_id'] % 8);
                                    $fullName = htmlspecialchars($c['first_name'] . ' ' . $c['last_name']);
                                    ?>
                                    <tr onclick="openProfile(<?= $c['customer_id'] ?>)" data-name="<?= strtolower($fullName) ?>"
                                        data-email="<?= strtolower($c['email'] ?? '') ?>"
                                        data-phone="<?= $c['contact_number'] ?? '' ?>">
                                        <td>
                                            <div class="cust-info">
                                                <div class="cust-avatar <?= $colorClass ?>"><?= $initials ?></div>
                                                <div>
                                                    <div class="cust-name"><?= $fullName ?></div>
                                                    <div class="cust-id">
                                                        #<?= str_pad($c['customer_id'], 4, '0', STR_PAD_LEFT) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($c['email']): ?>
                                                <div style="font-size:12px;"><?= htmlspecialchars($c['email']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($c['contact_number']): ?>
                                                <div style="font-size:12px; color:var(--muted);">
                                                    <?= htmlspecialchars($c['contact_number']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!$c['email'] && !$c['contact_number']): ?>
                                                <span style="color:var(--muted); font-size:12px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight:600;"><?= $c['total_transactions'] ?></span>
                                            <span style="color:var(--muted); font-size:11px;">
                                                job<?= $c['total_transactions'] != 1 ? 's' : '' ?></span>
                                        </td>
                                        <td style="font-weight:600;">₱<?= number_format($c['total_spent'], 2) ?></td>
                                        <td style="font-size:12px; color:var(--muted);">
                                            <?= $c['last_transaction'] ? date('M d, Y', strtotime($c['last_transaction'])) : '—' ?>
                                        </td>
                                        <td>
                                            <div class="action-btns" style="justify-content:flex-end;"
                                                onclick="event.stopPropagation()">
                                                <button class="action-btn" title="Edit" onclick="openEdit(
                                                    <?= $c['customer_id'] ?>,
                                                    '<?= addslashes($c['first_name']) ?>',
                                                    '<?= addslashes($c['last_name']) ?>',
                                                    '<?= addslashes($c['email'] ?? '') ?>',
                                                    '<?= addslashes($c['contact_number'] ?? '') ?>',
                                                    '<?= addslashes($c['address'] ?? '') ?>'
                                                )">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="action-btn danger" title="Delete"
                                                    onclick="confirmDelete(<?= $c['customer_id'] ?>, '<?= addslashes($fullName) ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>


    <!-- add customer modal -->
    <div class="modal-overlay" id="addModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-person-plus"></i> Add Customer</h2>
                <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_customer" />
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name <span style="color:var(--red)">*</span></label>
                            <input type="text" name="first_name" placeholder="e.g. Juan" required />
                        </div>
                        <div class="form-group">
                            <label>Last Name <span style="color:var(--red)">*</span></label>
                            <input type="text" name="last_name" placeholder="e.g. dela Cruz" required />
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" placeholder="email@example.com" />
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="contact_number" placeholder="09XX-XXX-XXXX" />
                        </div>
                    </div>
                    <div class="form-row full">
                        <div class="form-group">
                            <label>Address</label>
                            <textarea name="address" placeholder="Street, Barangay, City…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addModal')"
                        style="padding:9px 18px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; font-size:13px;">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="bi bi-person-check"></i> Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>


    <!-- edit customer modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="bi bi-pencil-square"></i> Edit Customer</h2>
                <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_customer" />
                <input type="hidden" name="customer_id" id="edit_id" />
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>First Name <span style="color:var(--red)">*</span></label>
                            <input type="text" name="first_name" id="edit_fn" required />
                        </div>
                        <div class="form-group">
                            <label>Last Name <span style="color:var(--red)">*</span></label>
                            <input type="text" name="last_name" id="edit_ln" required />
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" id="edit_email" />
                        </div>
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="contact_number" id="edit_phone" />
                        </div>
                    </div>
                    <div class="form-row full">
                        <div class="form-group">
                            <label>Address</label>
                            <textarea name="address" id="edit_addr"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('editModal')"
                        style="padding:9px 18px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; font-size:13px;">
                        Cancel
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="bi bi-check-lg"></i> Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="deleteModal">
        <div class="modal confirm-modal">
            <div class="modal-body" style="text-align:center; padding:32px 24px;">
                <div class="confirm-icon"><i class="bi bi-trash3"></i></div>
                <div style="font-size:16px; font-weight:700; margin-bottom:8px;">Delete Customer?</div>
                <div style="font-size:13px; color:var(--muted); margin-bottom:4px;">
                    You are about to delete <strong id="deleteCustomerName"></strong>.
                </div>
                <div style="font-size:12px; color:var(--red);">This action cannot be undone.</div>
            </div>
            <div class="modal-footer" style="justify-content:center; gap:12px;">
                <button onclick="closeModal('deleteModal')"
                    style="padding:9px 20px; border:1px solid var(--border); border-radius:8px; background:#fff; cursor:pointer; font-size:13px;">
                    Cancel
                </button>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="delete_customer" />
                    <input type="hidden" name="customer_id" id="delete_id" />
                    <button type="submit"
                        style="padding:9px 20px; border:none; border-radius:8px; background:var(--red); color:#fff; cursor:pointer; font-size:13px; font-weight:600;">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </form>
            </div>
        </div>
    </div>


    <!-- customer profile drawer -->
    <div class="drawer-overlay" id="profileOverlay" onclick="closeProfile()"></div>
    <div class="drawer" id="profileDrawer">
        <div class="drawer-header">
            <h2 style="font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px;">
                <i class="bi bi-person-lines-fill" style="color:var(--accent);"></i> Customer Profile
            </h2>
            <button class="modal-close" onclick="closeProfile()">&times;</button>
        </div>
        <div class="drawer-body" id="drawerBody">
            <div style="text-align:center; padding:40px; color:var(--muted);">Loading…</div>
        </div>
    </div>


    <!-- Customer data for JS drawer -->
    <script>
        const CUSTOMERS = <?= json_encode(array_values($customers)) ?>;
        const COLORS = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#f97316', '#0891b2', '#be185d', '#854d0e'];

        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
        });

        function openEdit(id, fn, ln, email, phone, addr) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_fn').value = fn;
            document.getElementById('edit_ln').value = ln;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_addr').value = addr;
            openModal('editModal');
        }

        function confirmDelete(id, name) {
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteCustomerName').textContent = name;
            openModal('deleteModal');
        }

        function openProfile(customerId) {
            const c = CUSTOMERS.find(x => x.customer_id == customerId);
            if (!c) return;

            const initials = (c.first_name[0] + (c.last_name[0] || '')).toUpperCase();
            const color = COLORS[c.customer_id % 8];
            const fullName = c.first_name + ' ' + c.last_name;
            const since = c.created_at ? new Date(c.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'Unknown';
            const lastVisit = c.last_transaction
                ? new Date(c.last_transaction).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
                : 'No visits yet';

            document.getElementById('drawerBody').innerHTML = `
            <div class="profile-hero">
                <div class="profile-avatar" style="background:${color};">${initials}</div>
                <div>
                    <div class="profile-name">${escHtml(fullName)}</div>
                    <div class="profile-since">Customer since ${since}</div>
                </div>
            </div>

            <div class="profile-stats">
                <div class="pstat">
                    <div class="pstat-value">${c.total_transactions}</div>
                    <div class="pstat-label">Orders</div>
                </div>
                <div class="pstat">
                    <div class="pstat-value" style="font-size:13px;">₱${parseFloat(c.total_spent).toLocaleString('en-PH', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}</div>
                    <div class="pstat-label">Total Spent</div>
                </div>
                <div class="pstat">
                    <div class="pstat-value" style="font-size:11px;">${lastVisit}</div>
                    <div class="pstat-label">Last Visit</div>
                </div>
            </div>

            <div class="info-list">
                ${c.email ? `
                <div class="info-item">
                    <i class="bi bi-envelope"></i>
                    <div><div class="info-label">Email</div><div class="info-value">${escHtml(c.email)}</div></div>
                </div>` : ''}
                ${c.contact_number ? `
                <div class="info-item">
                    <i class="bi bi-telephone"></i>
                    <div><div class="info-label">Contact</div><div class="info-value">${escHtml(c.contact_number)}</div></div>
                </div>` : ''}
                ${c.address ? `
                <div class="info-item">
                    <i class="bi bi-geo-alt"></i>
                    <div><div class="info-label">Address</div><div class="info-value">${escHtml(c.address)}</div></div>
                </div>` : ''}
                ${!c.email && !c.contact_number && !c.address ? `
                <div style="color:var(--muted); font-size:12px; text-align:center; padding:12px 0;">No contact details on file.</div>
                ` : ''}
            </div>

            <hr class="section-divider"/>

            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#555;">
                    <i class="bi bi-receipt" style="margin-right:5px; color:var(--accent);"></i>Recent Transactions
                </div>
                <a href="sales.php?customer_id=${c.customer_id}" style="font-size:11px; color:var(--accent); text-decoration:none; font-weight:500;">
                    View All →
                </a>
            </div>

            <div id="txnList_${c.customer_id}" class="transactions-list">
                <div style="text-align:center;padding:20px;color:var(--muted);font-size:12px;">Loading transactions…</div>
            </div>

            <hr class="section-divider"/>
            <div style="display:flex; gap:8px;">
                <button class="btn-primary" style="flex:1; justify-content:center;"
                    onclick="openEdit(${c.customer_id},'${escJs(c.first_name)}','${escJs(c.last_name)}','${escJs(c.email || '')}','${escJs(c.contact_number || '')}','${escJs(c.address || '')}')">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button onclick="confirmDelete(${c.customer_id}, '${escJs(fullName)}')"
                    style="flex:1; padding:8px 16px; border:1px solid var(--red); border-radius:10px;
                           background:#fff; color:var(--red); cursor:pointer; font-size:13px; font-weight:500; display:flex; align-items:center; justify-content:center; gap:6px;">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>
        `;

            // Open drawer
            document.getElementById('profileOverlay').classList.add('open');
            document.getElementById('profileDrawer').classList.add('open');

            // Load transactions via AJAX
            fetch('customer_transactions.php?id=' + customerId)
                .then(r => r.json())
                .then(data => {
                    const el = document.getElementById('txnList_' + customerId);
                    if (!el) return;
                    if (!data || data.length === 0) {
                        el.innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">No transactions found.</div>';
                        return;
                    }
                    el.innerHTML = data.map(t => `
                    <div class="txn-row">
                        <div>
                            <div class="txn-id">Sale #${String(t.sales_id).padStart(5, '0')}</div>
                            <div class="txn-date">${t.sales_date}</div>
                        </div>
                        <div style="text-align:right;">
                            <div class="txn-amount">₱${parseFloat(t.final_amount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}</div>
                            <div style="font-size:10px; color:${t.status === 'Paid' ? 'var(--green)' : t.status === 'Partially Paid' ? '#854d0e' : 'var(--red)'};">${t.status}</div>
                        </div>
                    </div>
                `).join('');
                })
                .catch(() => {
                    const el = document.getElementById('txnList_' + customerId);
                    if (el) el.innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Could not load transactions.</div>';
                });
        }

        function closeProfile() {
            document.getElementById('profileOverlay').classList.remove('open');
            document.getElementById('profileDrawer').classList.remove('open');
        }

        function escHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function escJs(str) {
            return String(str || '').replace(/'/g, "\\'").replace(/\n/g, ' ');
        }

        // Live search filter for customers table
        function liveFilter(query) {
            const q = query.toLowerCase().trim();
            const rows = document.querySelectorAll('#customerTable tbody tr');
            let visible = 0;
            rows.forEach(row => {
                const name = row.dataset.name || '';
                const email = row.dataset.email || '';
                const phone = row.dataset.phone || '';
                const match = !q || name.includes(q) || email.includes(q) || phone.includes(q);
                row.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            const countEl = document.getElementById('visibleCount');
            if (countEl) countEl.textContent = visible;
        }

        // closes the profile drawer when Escape key is pressed
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeProfile();
                document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
            }
        });
    </script>

</body>

</html>
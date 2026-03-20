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

if (in_array(strtolower($user['role']), ['mechanic', 'employee'])) {
    header("Location: mechanic_dashboard.php");
    exit();
}

$role = $user['role'];
$isOwner = strtolower($role) === 'owner';
if (!$isOwner) {
    die('Access Denied. Only owners can manage employees. <br><a href="admin_dashboard.php">Go back</a>');
}

$firstname    = htmlspecialchars($user['first_name']);
$userInitials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));

$successMsg = '';
$errorMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'add_employee') {
        $fn = trim($conn->real_escape_string($_POST['first_name'] ?? ''));
        $ln = trim($conn->real_escape_string($_POST['last_name']  ?? ''));
        $em = trim($conn->real_escape_string($_POST['email']      ?? ''));
        $rl = trim($conn->real_escape_string($_POST['role']       ?? ''));
        $pw = trim($_POST['password'] ?? '');

        if ($fn && $ln && $em && $rl && strlen($pw) >= 6) {
            $check = $conn->prepare("SELECT employeeID FROM employee WHERE email = ?");
            $check->bind_param("s", $em);
            $check->execute();
            $check->store_result();
            if ($check->num_rows > 0) {
                $errorMsg = "An account with that email already exists.";
            } else {
                $hashed = password_hash($pw, PASSWORD_DEFAULT);
                $conn->query("INSERT INTO employee (first_name, last_name, email, role, password, is_approved)
                              VALUES ('$fn','$ln','$em','$rl','$hashed', 1)");
                $successMsg = "Employee \"$fn $ln\" added successfully.";
            }
            $check->close();
        } else {
            $errorMsg = "All fields are required and password must be at least 6 characters.";
        }
    }

    if ($_POST['action'] === 'edit_employee') {
        $id   = intval($_POST['employee_id'] ?? 0);
        $fn   = trim($conn->real_escape_string($_POST['first_name']  ?? ''));
        $ln   = trim($conn->real_escape_string($_POST['last_name']   ?? ''));
        $em   = trim($conn->real_escape_string($_POST['email']       ?? ''));
        $rl   = trim($conn->real_escape_string($_POST['role']        ?? ''));
        $appr = intval($_POST['is_approved'] ?? 1);
        if ($id && $fn && $ln) {
            $conn->query("UPDATE employee SET first_name='$fn', last_name='$ln', email='$em', role='$rl', is_approved=$appr WHERE employeeID=$id");
            $successMsg = "Employee updated successfully.";
        } else {
            $errorMsg = "Invalid data. First and last name are required.";
        }
    }

    if ($_POST['action'] === 'delete_employee') {
        $id = intval($_POST['employee_id'] ?? 0);
        if ($id && $id !== (int)$_SESSION['employeeID']) {
            $conn->query("DELETE FROM employee WHERE employeeID = $id");
            $successMsg = "Employee removed.";
        } else {
            $errorMsg = "You cannot delete your own account.";
        }
    }

    if ($_POST['action'] === 'toggle_approval') {
        $id      = intval($_POST['employee_id'] ?? 0);
        $current = intval($_POST['current_status'] ?? 0);
        $new     = $current ? 0 : 1;
        if ($id) {
            $conn->query("UPDATE employee SET is_approved=$new WHERE employeeID=$id");
            $successMsg = $new ? "Employee approved." : "Employee suspended.";
        }
    }

    if ($_POST['action'] === 'reset_password') {
        $id      = intval($_POST['employee_id'] ?? 0);
        $newpass = trim($_POST['new_password'] ?? '');
        if ($id && strlen($newpass) >= 6) {
            $hashed = password_hash($newpass, PASSWORD_DEFAULT);
            $stmt2  = $conn->prepare("UPDATE employee SET password=? WHERE employeeID=?");
            $stmt2->bind_param("si", $hashed, $id);
            $stmt2->execute();
            $stmt2->close();
            $successMsg = "Password reset successfully.";
        } else {
            $errorMsg = "Password must be at least 6 characters.";
        }
    }
}

$search      = trim($_GET['search'] ?? '');
$filter_role = $_GET['role_filter'] ?? 'all';
$filter_stat = $_GET['status'] ?? 'all';
$sort        = $_GET['sort'] ?? 'newest';

$conditions = ["1=1"];
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $conditions[] = "(first_name LIKE '%$s%' OR last_name LIKE '%$s%' OR email LIKE '%$s%')";
}
if ($filter_role !== 'all') {
    $r = $conn->real_escape_string($filter_role);
    $conditions[] = "role = '$r'";
}
if ($filter_stat === 'active')  $conditions[] = "is_approved = 1";
if ($filter_stat === 'pending') $conditions[] = "is_approved = 0";

$order_sql = match ($sort) {
    'name_az' => 'first_name ASC',
    'name_za' => 'first_name DESC',
    'oldest'  => 'employeeID ASC',
    default   => 'employeeID DESC',
};

$where_sql = implode(' AND ', $conditions);
$employees = [];
$res = $conn->query("SELECT * FROM employee WHERE $where_sql ORDER BY $order_sql");
while ($res && $row = $res->fetch_assoc()) {
    $employees[] = $row;
}

$stat_total = $stat_active = $stat_pending = $stat_owners = 0;
$stat_res = $conn->query("SELECT is_approved, role, COUNT(*) AS cnt FROM employee GROUP BY is_approved, role");
while ($stat_res && $r = $stat_res->fetch_assoc()) {
    $stat_total += $r['cnt'];
    if ($r['is_approved']) {
        $stat_active += $r['cnt'];
        if (strtolower($r['role']) === 'owner') $stat_owners += $r['cnt'];
    } else {
        $stat_pending += $r['cnt'];
    }
}

$pa_res = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved = 0");
$pendingApprovals = ($pa_res && $pr = $pa_res->fetch_assoc()) ? $pr['cnt'] : 0;
$aj_res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status NOT IN ('Completed','Cancelled')");
$activeJobs = ($aj_res && $ar = $aj_res->fetch_assoc()) ? $ar['cnt'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees — AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@600;700&display=swap" rel="stylesheet">

    <style>
        .emp-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .emp-stat-card {
            background: var(--surface);
            border-radius: var(--card-radius);
            padding: 20px;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 14px;
            animation: fadeUp 0.4s ease both;
        }
        .emp-stat-card:nth-child(1) { animation-delay:.05s; }
        .emp-stat-card:nth-child(2) { animation-delay:.10s; }
        .emp-stat-card:nth-child(3) { animation-delay:.15s; }
        .emp-stat-card:nth-child(4) { animation-delay:.20s; }
        .stat-icon-box {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .stat-num {
            font-family: 'DM Sans', sans-serif;
            font-size: 24px; font-weight: 700; line-height: 1;
        }
        .stat-lbl { font-size: 12px; color: var(--muted); margin-top: 3px; }

        /* Toolbar */
        .toolbar {
            display: flex; align-items: center; gap: 10px;
            flex-wrap: wrap; margin-bottom: 20px;
        }
        .toolbar input[type=search] {
            flex: 1; min-width: 200px;
            padding: 9px 14px 9px 38px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%23888' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.099zm-5.242 1.656a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z'/%3E%3C/svg%3E") no-repeat 12px center;
            font-size: 13px; font-family: 'DM Sans', sans-serif;
            outline: none; appearance: none;
        }
        .toolbar input[type=search]:focus { border-color: var(--accent); }
        .toolbar select {
            padding: 9px 36px 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px; font-family: 'DM Sans', sans-serif;
            background: var(--surface);
            cursor: pointer; appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            color: var(--text); outline: none;
        }
        .toolbar select:focus { border-color: var(--accent); }

        /* Table */
        .emp-table-wrap {
            background: var(--surface);
            border-radius: var(--card-radius);
            border: 1px solid var(--border);
            overflow: hidden;
            animation: fadeUp .45s ease both;
            animation-delay: .25s;
        }
        .emp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .emp-table thead th {
            background: #fafaf9; padding: 12px 16px;
            text-align: left; font-size: 10.5px;
            text-transform: uppercase; letter-spacing: .07em;
            color: var(--muted); border-bottom: 1px solid var(--border);
            font-weight: 500;
        }
        .emp-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        .emp-table tbody tr:last-child { border-bottom: none; }
        .emp-table tbody tr:hover td { background: #fafaf8; }
        .emp-table td { padding: 13px 16px; vertical-align: middle; }

        .emp-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0;
        }
        .emp-name-cell { display: flex; align-items: center; gap: 10px; }
        .emp-name-text { font-weight: 600; font-size: 13px; color: var(--text); }
        .emp-email-text { font-size: 11px; color: var(--muted); margin-top: 1px; }

        .role-badge {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .role-owner    { background: #fef3c7; color: #92400e; }
        .role-partner  { background: #dbeafe; color: #1e40af; }
        .role-mechanic { background: #dcfce7; color: #166634; }
        .role-default  { background: #f3f4f6; color: #374151; }

        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .status-active  { background: #dcfce7; color: #166534; }
        .status-pending { background: #fef9c3; color: #854d0e; }

        .action-btns { display: flex; gap: 6px; }
        .action-btn {
            width: 30px; height: 30px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: none; font-size: 13px;
            transition: background .15s, color .15s;
        }
        .action-btn.edit    { background: #eff6ff; color: #2563eb; }
        .action-btn.edit:hover    { background: #dbeafe; }
        .action-btn.suspend { background: #fef9c3; color: #854d0e; }
        .action-btn.suspend:hover { background: #fef08a; }
        .action-btn.approve { background: #dcfce7; color: #166534; }
        .action-btn.approve:hover { background: #bbf7d0; }
        .action-btn.delete  { background: #fee2e2; color: #dc2626; }
        .action-btn.delete:hover  { background: #fecaca; }
        .action-btn.key     { background: #f5f3ff; color: #7c3aed; }
        .action-btn.key:hover     { background: #ede9fe; }

        /* Modals */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 1000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: var(--surface); border-radius: 16px;
            padding: 28px; width: 100%; max-width: 440px;
            box-shadow: 0 20px 60px rgba(0,0,0,.18);
            animation: fadeUp .25s ease;
        }
        .modal-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 20px;
        }
        .modal-title {
            font-size: 16px; font-weight: 700;
            display: flex; align-items: center; gap: 8px;
        }
        .modal-close {
            width: 28px; height: 28px; border-radius: 8px;
            border: none; background: var(--bg); font-size: 16px;
            cursor: pointer; color: var(--muted);
            display: flex; align-items: center; justify-content: center;
        }
        .modal-close:hover { background: #eceae6; }

        .form-row { margin-bottom: 14px; }
        .form-row label {
            display: block; font-size: 12px; color: #555;
            margin-bottom: 5px; font-weight: 500;
        }
        .form-row input {
            width: 100%; padding: 9px 12px;
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 13px; font-family: 'DM Sans', sans-serif;
            color: var(--text); background: var(--bg);
            outline: none; transition: border-color .2s, background .2s;
        }
        .form-row select {
            width: 100%; padding: 9px 36px 9px 12px;
            border: 1.5px solid var(--border); border-radius: 8px;
            font-size: 13px; font-family: 'DM Sans', sans-serif;
            color: var(--text); background: var(--bg);
            outline: none; transition: border-color .2s, background .2s;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px;
            cursor: pointer;
        }
        .form-row input:focus,
        .form-row select:focus {
            border-color: var(--accent); background: #fff;
            box-shadow: 0 0 0 3px rgba(37,99,235,.08);
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }

        .btn-cancel {
            padding: 9px 18px; background: #f3f4f6; color: #374151;
            border-radius: 8px; border: none; font-size: 13px;
            font-weight: 500; cursor: pointer; font-family: 'DM Sans', sans-serif;
        }
        .btn-primary {
            padding: 9px 18px; background: var(--accent); color: #fff;
            border-radius: 10px; font-size: 13px; font-weight: 600;
            cursor: pointer; border: none;
            display: inline-flex; align-items: center; gap: 6px;
            transition: background .2s; text-decoration: none;
            font-family: 'DM Sans', sans-serif;
        }
        .btn-primary:hover { background: var(--accent-hover); }

        .page-alert {
            padding: 12px 18px; border-radius: 10px;
            margin-bottom: 20px; font-size: 13px;
            display: flex; align-items: center; gap: 8px;
            animation: fadeUp .3s ease;
        }
        .page-alert.success { background: #dcfce7; color: #166534; }
        .page-alert.error   { background: #fee2e2; color: #dc2626; }

        .empty-state { padding: 60px 20px; text-align: center; }
        .empty-icon { font-size: 36px; margin-bottom: 12px; color: var(--muted); opacity: .4; }
        .empty-text { color: var(--muted); font-size: 14px; }

        @media (max-width: 900px) {
            .emp-stats { grid-template-columns: repeat(2, 1fr); }
            .emp-table thead th:nth-child(4),
            .emp-table tbody td:nth-child(4) { display: none; }
        }
        @media (max-width: 600px) {
            .emp-stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>

<body>
    
<?php
$currentPage = 'employees.php';
include 'approval_page.php';
?>

<div class="main">
    <div class="topbar">
        <div class="topbar-left">
            <span class="page-title">Employees</span>
        </div>
        <div class="topbar-right">
            <button class="btn-primary" onclick="openModal('addModal')">
                <i class="bi bi-person-plus-fill"></i> Add Employee
            </button>
        </div>
    </div>

    <div class="content">

        <?php if ($successMsg): ?>
            <div class="page-alert success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($successMsg) ?></div>
        <?php elseif ($errorMsg): ?>
            <div class="page-alert error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="emp-stats">
            <div class="emp-stat-card">
                <div class="stat-icon-box" style="background:#eff6ff;">
                    <i class="bi bi-people-fill" style="color:#2563eb;"></i>
                </div>
                <div>
                    <div class="stat-num"><?= $stat_total ?></div>
                    <div class="stat-lbl">Total Accounts</div>
                </div>
            </div>
            <div class="emp-stat-card">
                <div class="stat-icon-box" style="background:#dcfce7;">
                    <i class="bi bi-person-check-fill" style="color:#16a34a;"></i>
                </div>
                <div>
                    <div class="stat-num"><?= $stat_active ?></div>
                    <div class="stat-lbl">Active</div>
                </div>
            </div>
            <div class="emp-stat-card">
                <div class="stat-icon-box" style="background:#fef9c3;">
                    <i class="bi bi-hourglass-split" style="color:#854d0e;"></i>
                </div>
                <div>
                    <div class="stat-num"><?= $stat_pending ?></div>
                    <div class="stat-lbl">Pending Approval</div>
                </div>
            </div>
            <div class="emp-stat-card">
                <div class="stat-icon-box" style="background:#fef3c7;">
                    <i class="bi bi-person-badge-fill" style="color:#92400e;"></i>
                </div>
                <div>
                    <div class="stat-num"><?= $stat_owners ?></div>
                    <div class="stat-lbl">Owners</div>
                </div>
            </div>
        </div>

        <!-- Toolbar -->
        <form method="GET" class="toolbar">
            <input type="search" name="search" placeholder="Search by name or email…"
                   value="<?= htmlspecialchars($search) ?>">
            <select name="role_filter">
                <option value="all"              <?= $filter_role === 'all'               ? 'selected' : '' ?>>All Roles</option>
                <option value="Owner"            <?= $filter_role === 'Owner'             ? 'selected' : '' ?>>Owner</option>
                <option value="Business Partner" <?= $filter_role === 'Business Partner'  ? 'selected' : '' ?>>Business Partner</option>
                <option value="Employee"         <?= $filter_role === 'Employee'          ? 'selected' : '' ?>>Mechanic</option>
            </select>
            <select name="status">
                <option value="all"     <?= $filter_stat === 'all'     ? 'selected' : '' ?>>All Status</option>
                <option value="active"  <?= $filter_stat === 'active'  ? 'selected' : '' ?>>Active</option>
                <option value="pending" <?= $filter_stat === 'pending' ? 'selected' : '' ?>>Pending</option>
            </select>
            <select name="sort">
                <option value="newest"  <?= $sort === 'newest'  ? 'selected' : '' ?>>Newest First</option>
                <option value="oldest"  <?= $sort === 'oldest'  ? 'selected' : '' ?>>Oldest First</option>
                <option value="name_az" <?= $sort === 'name_az' ? 'selected' : '' ?>>Name A–Z</option>
                <option value="name_za" <?= $sort === 'name_za' ? 'selected' : '' ?>>Name Z–A</option>
            </select>
            <button type="submit" class="btn-primary"><i class="bi bi-search"></i> Search</button>
            <?php if ($search || $filter_role !== 'all' || $filter_stat !== 'all'): ?>
                <a href="employees.php" class="btn-cancel"
                   style="border-radius:8px; display:inline-flex; align-items:center; gap:5px; text-decoration:none;">
                    <i class="bi bi-x"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="emp-table-wrap">
            <div style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between;">
                <div style="font-weight:700; font-size:15px;">Employee Directory</div>
                <span style="font-size:12px; color:var(--muted);">
                    <?= count($employees) ?> employee<?= count($employees) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($employees)): ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                    <div class="empty-text">No employees found<?= $search ? ' for "' . htmlspecialchars($search) . '"' : '' ?>.</div>
                </div>
            <?php else: ?>
                <table class="emp-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $avatarColors = [
                            'owner'            => '#f59e0b',
                            'business partner' => '#2563eb',
                            'employee'         => '#16a34a',
                        ];
                        foreach ($employees as $emp):
                            $roleKey   = strtolower($emp['role']);
                            $avatarBg  = $avatarColors[$roleKey] ?? '#6b7280';
                            $initials  = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1));
                            $roleBadge = match($roleKey) {
                                'owner'            => 'role-owner',
                                'business partner' => 'role-partner',
                                'employee'         => 'role-mechanic',
                                default            => 'role-default',
                            };
                            $isSelf = (int)$emp['employeeID'] === (int)$_SESSION['employeeID'];
                        ?>
                        <tr>
                            <td>
                                <div class="emp-name-cell">
                                    <div class="emp-avatar" style="background:<?= $avatarBg ?>;"><?= $initials ?></div>
                                    <div>
                                        <div class="emp-name-text">
                                            <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                            <?php if ($isSelf): ?>
                                                <span style="font-size:10px; color:var(--muted); font-weight:400;">(you)</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="emp-email-text"><?= htmlspecialchars($emp['email'] ?? '—') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge <?= $roleBadge ?>">
                                    <?= htmlspecialchars($emp['role']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($emp['is_approved']): ?>
                                    <span class="status-badge status-active">
                                        <i class="bi bi-circle-fill" style="font-size:6px;"></i> Active
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">
                                        <i class="bi bi-hourglass-split" style="font-size:10px;"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--muted); font-size:12px;">
                                <?= $emp['created_at'] ? date('M d, Y', strtotime($emp['created_at'])) : '—' ?>
                            </td>
                            <td>
                                <div class="action-btns" style="justify-content:flex-end;">
                                    <button class="action-btn edit" title="Edit"
                                        onclick="openEditModal(<?= htmlspecialchars(json_encode($emp)) ?>)">
                                        <i class="bi bi-pencil-fill"></i>
                                    </button>
                                    <button class="action-btn key" title="Reset Password"
                                        onclick="openResetModal(<?= $emp['employeeID'] ?>, '<?= htmlspecialchars(addslashes($emp['first_name'] . ' ' . $emp['last_name'])) ?>')">
                                        <i class="bi bi-key-fill"></i>
                                    </button>
                                    <?php if (!$isSelf): ?>
                                        <?php if ($emp['is_approved']): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action"         value="toggle_approval">
                                                <input type="hidden" name="employee_id"    value="<?= $emp['employeeID'] ?>">
                                                <input type="hidden" name="current_status" value="1">
                                                <button type="submit" class="action-btn suspend" title="Suspend"
                                                    onclick="return confirm('Suspend this employee?')">
                                                    <i class="bi bi-slash-circle"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action"         value="toggle_approval">
                                                <input type="hidden" name="employee_id"    value="<?= $emp['employeeID'] ?>">
                                                <input type="hidden" name="current_status" value="0">
                                                <button type="submit" class="action-btn approve" title="Approve">
                                                    <i class="bi bi-check-lg"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action"      value="delete_employee">
                                            <input type="hidden" name="employee_id" value="<?= $emp['employeeID'] ?>">
                                            <button type="submit" class="action-btn delete" title="Delete"
                                                onclick="return confirm('Permanently delete <?= htmlspecialchars(addslashes($emp['first_name'] . ' ' . $emp['last_name'])) ?>? This cannot be undone.')">
                                                <i class="bi bi-trash-fill"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Add Employee Modal -->
<div class="modal-overlay" id="addModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <div class="modal-title">
                <i class="bi bi-person-plus-fill" style="color:var(--accent);"></i> Add Employee
            </div>
            <button class="modal-close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_employee">
            <div class="form-grid">
                <div class="form-row">
                    <label>First Name</label>
                    <input type="text" name="first_name" placeholder="e.g. Juan" required>
                </div>
                <div class="form-row">
                    <label>Last Name</label>
                    <input type="text" name="last_name" placeholder="e.g. dela Cruz" required>
                </div>
            </div>
            <div class="form-row">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="email@example.com" required>
            </div>
            <div class="form-grid">
                <div class="form-row">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="">Select Role</option>
                        <option value="Owner">Owner</option>
                        <option value="Business Partner">Business Partner</option>
                        <option value="Employee">Mechanic</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="Min. 6 characters" required minlength="6">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-primary">
                    <i class="bi bi-person-check-fill"></i> Add Employee
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-pencil-square" style="color:var(--accent);"></i> Edit Employee</div>
            <button class="modal-close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action"      value="edit_employee">
            <input type="hidden" name="employee_id" id="edit_id">
            <div class="form-grid">
                <div class="form-row">
                    <label>First Name</label>
                    <input type="text" name="first_name" id="edit_fn" required>
                </div>
                <div class="form-row">
                    <label>Last Name</label>
                    <input type="text" name="last_name" id="edit_ln" required>
                </div>
            </div>
            <div class="form-row">
                <label>Email</label>
                <input type="email" name="email" id="edit_email">
            </div>
            <div class="form-grid">
                <div class="form-row">
                    <label>Role</label>
                    <select name="role" id="edit_role">
                        <option value="Owner">Owner</option>
                        <option value="Business Partner">Business Partner</option>
                        <option value="Employee">Mechanic</option>
                    </select>
                </div>
                <div class="form-row">
                    <label>Status</label>
                    <select name="is_approved" id="edit_approved">
                        <option value="1">Active</option>
                        <option value="0">Suspended</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-primary"><i class="bi bi-check-lg"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetModal">
    <div class="modal" style="max-width:380px;">
        <div class="modal-header">
            <div class="modal-title"><i class="bi bi-key-fill" style="color:#7c3aed;"></i> Reset Password</div>
            <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
        </div>
        <p id="resetName" style="font-size:13px; color:var(--muted); margin-bottom:18px;"></p>
        <form method="POST">
            <input type="hidden" name="action"      value="reset_password">
            <input type="hidden" name="employee_id" id="reset_id">
            <div class="form-row">
                <label>New Password</label>
                <input type="password" name="new_password" id="new_password"
                       placeholder="Min. 6 characters" required minlength="6">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('resetModal')">Cancel</button>
                <button type="submit" class="btn-primary" style="background:#7c3aed;">
                    <i class="bi bi-key-fill"></i> Reset
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(emp) {
    document.getElementById('edit_id').value       = emp.employeeID;
    document.getElementById('edit_fn').value       = emp.first_name;
    document.getElementById('edit_ln').value       = emp.last_name;
    document.getElementById('edit_email').value    = emp.email || '';
    document.getElementById('edit_role').value     = emp.role;
    document.getElementById('edit_approved').value = emp.is_approved;
    document.getElementById('editModal').classList.add('open');
}
function openResetModal(id, name) {
    document.getElementById('reset_id').value = id;
    document.getElementById('resetName').textContent = 'Resetting password for: ' + name;
    document.getElementById('new_password').value = '';
    document.getElementById('resetModal').classList.add('open');
}
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
});
</script>
</body>
</html>
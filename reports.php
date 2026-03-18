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
if (strtolower($role) !== 'owner') {
    header("Location: admin_dashboard.php");
    exit();
}

$firstname = htmlspecialchars($user['first_name'] ?? 'User');
$userInitials = strtoupper(substr($firstname, 0, 1) . substr($user['last_name'] ?? '', 0, 1));
$userRoleLabel = htmlspecialchars($role ?? 'Staff');
$isOwner = true;

// Date range filter
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : date('Y-m-d');
$report_type = isset($_GET['report']) ? $_GET['report'] : 'overview';

// ── Summary Stats ──────────────────────────────────────────
// Total Revenue (all time)
$totalRevenue = 0;
$res = $conn->query("SELECT COALESCE(SUM(amount_paid),0) AS t FROM payments");
if ($res && $r = $res->fetch_assoc()) $totalRevenue = $r['t'];

// Revenue in range
$revenueInRange = 0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid),0) AS t FROM payments WHERE DATE(payment_date) BETWEEN ? AND ?");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$revenueInRange = $stmt->get_result()->fetch_assoc()['t'];
$stmt->close();

// Total Sales count in range
$salesInRange = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS t FROM sales WHERE DATE(sales_date) BETWEEN ? AND ?");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$salesInRange = $stmt->get_result()->fetch_assoc()['t'];
$stmt->close();

// Completed Jobs in range
$completedJobs = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS t FROM job_orders WHERE status='Completed' AND DATE(date_completed) BETWEEN ? AND ?");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$completedJobs = $stmt->get_result()->fetch_assoc()['t'];
$stmt->close();

// Unpaid invoices total
$unpaidTotal = 0;
$res = $conn->query("SELECT COALESCE(SUM(final_amount),0) AS t FROM sales WHERE status='Unpaid'");
if ($res && $r = $res->fetch_assoc()) $unpaidTotal = $r['t'];

// ── Monthly Revenue (last 12 months) ───────────────────────
$monthlyRevenue = [];
$res = $conn->query("
    SELECT DATE_FORMAT(payment_date,'%b %Y') AS label,
           DATE_FORMAT(payment_date,'%Y-%m') AS ym,
           COALESCE(SUM(amount_paid),0) AS total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym ORDER BY ym ASC
");
while ($res && $row = $res->fetch_assoc()) $monthlyRevenue[] = $row;
$maxRev = !empty($monthlyRevenue) ? max(array_column($monthlyRevenue,'total')) ?: 1 : 1;

// ── Payment Method Breakdown ───────────────────────────────
$paymentMethods = [];
$stmt = $conn->prepare("SELECT payment_method, COALESCE(SUM(amount_paid),0) AS total, COUNT(*) AS cnt FROM payments WHERE DATE(payment_date) BETWEEN ? AND ? GROUP BY payment_method");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $paymentMethods[] = $row;
$stmt->close();

// ── Top Customers by Revenue ───────────────────────────────
$topCustomers = [];
$stmt = $conn->prepare("
    SELECT CONCAT(c.first_name,' ',c.last_name) AS customer,
           COALESCE(SUM(p.amount_paid),0) AS total,
           COUNT(DISTINCT p.sales_id) AS txn_count
    FROM payments p
    JOIN sales s ON p.sales_id = s.sales_id
    JOIN customers c ON s.customer_id = c.customer_id
    WHERE DATE(p.payment_date) BETWEEN ? AND ?
    GROUP BY c.customer_id ORDER BY total DESC LIMIT 5
");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $topCustomers[] = $row;
$stmt->close();

// ── Job Order Summary ──────────────────────────────────────
$jobStats = ['Pending'=>0,'Ongoing'=>0,'Completed'=>0,'Cancelled'=>0];
$stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM job_orders WHERE DATE(date_received) BETWEEN ? AND ? GROUP BY status");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $jobStats[$row['status']] = $row['cnt'];
$stmt->close();

// ── Recent Transactions ────────────────────────────────────
$recentTransactions = [];
$stmt = $conn->prepare("
    SELECT p.payment_id, p.payment_date, p.amount_paid, p.payment_method,
           CONCAT(c.first_name,' ',c.last_name) AS customer,
           s.invoice_number, s.final_amount
    FROM payments p
    JOIN sales s ON p.sales_id = s.sales_id
    JOIN customers c ON s.customer_id = c.customer_id
    WHERE DATE(p.payment_date) BETWEEN ? AND ?
    ORDER BY p.payment_date DESC LIMIT 10
");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $recentTransactions[] = $row;
$stmt->close();

// ── Sales by Status ────────────────────────────────────────
$salesByStatus = ['Paid'=>0,'Partially Paid'=>0,'Unpaid'=>0];
$stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt, COALESCE(SUM(final_amount),0) AS total FROM sales WHERE DATE(sales_date) BETWEEN ? AND ? GROUP BY status");
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $salesByStatus[$row['status']] = ['cnt'=>$row['cnt'], 'total'=>$row['total']];
}
$stmt->close();

// Sidebar counts
$pendingApprovals = 0;
$pa = $conn->query("SELECT COUNT(*) AS cnt FROM employee WHERE is_approved=0");
if ($pa && $r = $pa->fetch_assoc()) $pendingApprovals = $r['cnt'];
$activeJobs = 0;
$aj = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status NOT IN ('Completed','Cancelled')");
if ($aj && $r = $aj->fetch_assoc()) $activeJobs = $r['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AutoBert — Reports</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .report-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .report-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .report-card.featured {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
        }

        .report-card.featured .rc-label,
        .report-card.featured .rc-sub { color: rgba(255,255,255,0.75); }
        .report-card.featured .rc-value { color: #fff; }

        .rc-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            margin-bottom: 14px;
        }

        .rc-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted);
            margin-bottom: 4px;
            font-weight: 600;
        }

        .rc-value {
            font-size: 26px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .rc-sub {
            font-size: 12px;
            color: var(--muted);
        }

        /* Filter Bar */
        .report-filter {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .report-filter .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .report-filter label {
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .report-filter select {
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

        .report-filter input[type=date] {
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 13px;
            background: #fff;
            cursor: pointer;
            appearance: none;
        }

        .btn-report-apply {
            padding: 9px 20px;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
            transition: background 0.15s;
        }
        .btn-report-apply:hover { background: #1d4ed8; }

        .btn-report-clear {
            padding: 9px 20px;
            background: #fff;
            color: var(--text);
            border: 1.5px solid #d1d5db;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            font-family: inherit;
            transition: all 0.15s;
        }
        .btn-report-clear:hover { background: #f3f4f6; }

        /* Two column grid */
        .report-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .report-grid.full { grid-template-columns: 1fr; }

        .section-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .section-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
        }

        .section-sub {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .section-body { padding: 20px; }

        /* Revenue Chart */
        .chart-container {
            padding: 20px;
        }

        .chart-bars {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            height: 140px;
            margin-top: 12px;
        }

        .bar-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .bar {
            width: 100%;
            border-radius: 6px 6px 0 0;
            background: #e8e7e3;
            min-height: 4px;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .bar.active { background: var(--accent); }
        .bar:hover { opacity: 0.8; }

        .bar-label {
            font-size: 10px;
            color: var(--muted);
            white-space: nowrap;
        }

        /* Payment Method Cards */
        .method-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding: 20px;
        }

        .method-card {
            background: #f9fafb;
            border-radius: 10px;
            padding: 14px;
            text-align: center;
            border: 1px solid var(--border);
        }

        .method-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .method-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }

        .method-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }

        .method-count {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        /* Job Stats */
        .job-stat-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .job-stat-row:last-child { border-bottom: none; }

        .job-stat-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .job-dot {
            width: 10px; height: 10px;
            border-radius: 50%;
        }

        .job-stat-label { font-size: 13px; font-weight: 500; }
        .job-stat-value { font-size: 16px; font-weight: 700; }

        /* Top Customers */
        .customer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .customer-row:last-child { border-bottom: none; }

        .customer-rank {
            width: 24px; height: 24px;
            border-radius: 50%;
            background: var(--accent);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }

        .customer-rank.gold { background: #f59e0b; }
        .customer-rank.silver { background: #9ca3af; }
        .customer-rank.bronze { background: #cd7c2f; }

        .customer-name { font-size: 13px; font-weight: 600; }
        .customer-txn { font-size: 11px; color: var(--muted); }
        .customer-amount { font-size: 14px; font-weight: 700; color: var(--accent); }

        /* Transactions Table */
        .txn-table {
            width: 100%;
            border-collapse: collapse;
        }
        .txn-table th {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--muted);
            font-weight: 600;
            padding: 10px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            background: #f9fafb;
        }
        .txn-table td {
            padding: 12px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f3f4f6;
        }
        .txn-table tr:last-child td { border-bottom: none; }
        .txn-table tbody tr:hover td { background: #fafaf8; }

        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .method-cash { background: #dcfce7; color: #16a34a; }
        .method-gcash { background: #dbeafe; color: #2563eb; }
        .method-bank { background: #f3e8ff; color: #7c3aed; }

        .sales-status-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .sales-status-row:last-child { border-bottom: none; }

        .status-pill {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .pill-paid { background: #dcfce7; color: #16a34a; }
        .pill-partial { background: #fef3c7; color: #d97706; }
        .pill-unpaid { background: #fee2e2; color: #dc2626; }

        .topbar-right { display: flex; align-items: center; gap: 12px; }

        .btn-dashboard {
            background: #4f46e5; color: #fff;
            display: flex; align-items: center; gap: 8px;
            padding: 9px 16px; border-radius: 8px;
            font-size: 13px; font-weight: 500;
            border: none; cursor: pointer; font-family: inherit;
        }
        .btn-dashboard:hover { background: #4338ca; }

        .empty-state { text-align: center; padding: 40px 20px; }
        .empty-icon { font-size: 40px; margin-bottom: 12px; opacity: 0.3; }
        .empty-text { color: var(--muted); font-size: 13px; }

        .date-range-label {
            font-size: 12px;
            color: var(--muted);
            background: #f3f4f6;
            padding: 4px 12px;
            border-radius: 20px;
        }
    </style>
</head>
<body>

<?php
$currentPage = 'reports.php';
include 'approval_page.php';
?>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <span class="page-title">Reports</span>
            <span class="breadcrumb">Business insights & analytics</span>
        </div>
        <div class="topbar-right">
            <span class="date-range-label">
                <i class="bi bi-calendar3"></i>
                <?= date('M d, Y', strtotime($date_from)) ?> — <?= date('M d, Y', strtotime($date_to)) ?>
            </span>
            <button class="btn-dashboard" onclick="window.location.href='admin_dashboard.php'">
                <i class="bi bi-speedometer2"></i> Dashboard
            </button>
            <button class="logout-btn" onclick="window.location.href='logout.php'">
                <i class="bi bi-box-arrow-right"></i>
            </button>
        </div>
    </header>

    <div class="content">

        <!-- Filter Bar -->
        <div class="report-filter">
            <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; width:100%;">
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="filter-group">
                    <label>Quick Range</label>
                    <select name="quick_range" onchange="applyQuickRange(this.value)">
                        <option value="">Custom</option>
                        <option value="this_month">This Month</option>
                        <option value="last_month">Last Month</option>
                        <option value="this_year">This Year</option>
                        <option value="last_30">Last 30 Days</option>
                        <option value="last_90">Last 90 Days</option>
                    </select>
                </div>
                <button type="submit" class="btn-report-apply">
                    <i class="bi bi-funnel-fill"></i> Apply
                </button>
                <a href="reports.php" class="btn-report-clear">Clear</a>
            </form>
        </div>

        <!-- Summary Stats -->
        <div class="report-stats">
            <div class="report-card featured">
                <div class="rc-icon" style="background:rgba(255,255,255,0.2);">
                    <i class="bi bi-currency-dollar" style="color:#fff; font-size:20px;"></i>
                </div>
                <div class="rc-label">Revenue (Period)</div>
                <div class="rc-value">₱<?= number_format($revenueInRange, 2) ?></div>
                <div class="rc-sub">All-time: ₱<?= number_format($totalRevenue, 2) ?></div>
            </div>
            <div class="report-card">
                <div class="rc-icon" style="background:#dbeafe;">
                    <i class="bi bi-receipt" style="color:#2563eb; font-size:18px;"></i>
                </div>
                <div class="rc-label">Sales (Period)</div>
                <div class="rc-value"><?= $salesInRange ?></div>
                <div class="rc-sub">Invoices created</div>
            </div>
            <div class="report-card">
                <div class="rc-icon" style="background:#dcfce7;">
                    <i class="bi bi-check2-circle" style="color:#16a34a; font-size:18px;"></i>
                </div>
                <div class="rc-label">Jobs Completed</div>
                <div class="rc-value"><?= $completedJobs ?></div>
                <div class="rc-sub">In selected period</div>
            </div>
            <div class="report-card">
                <div class="rc-icon" style="background:#fee2e2;">
                    <i class="bi bi-exclamation-circle" style="color:#dc2626; font-size:18px;"></i>
                </div>
                <div class="rc-label">Unpaid Balance</div>
                <div class="rc-value" style="color:#dc2626;">₱<?= number_format($unpaidTotal, 2) ?></div>
                <div class="rc-sub">Outstanding invoices</div>
            </div>
        </div>

        <!-- Revenue Chart + Payment Methods -->
        <div class="report-grid">
            <!-- Revenue Chart -->
            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title">Revenue Overview</div>
                        <div class="section-sub">Last 12 months</div>
                    </div>
                    <div style="font-size:20px; font-weight:700; color:var(--accent);">
                        ₱<?= number_format($totalRevenue, 2) ?>
                    </div>
                </div>
                <div class="chart-container">
                    <?php if (empty($monthlyRevenue)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">📊</div>
                            <div class="empty-text">No revenue data yet</div>
                        </div>
                    <?php else: ?>
                        <div class="chart-bars">
                            <?php foreach ($monthlyRevenue as $m):
                                $pct = max(8, round(($m['total'] / $maxRev) * 130));
                            ?>
                                <div class="bar-wrap">
                                    <div class="bar active" style="height:<?= $pct ?>px;"
                                         title="<?= $m['label'] ?>: ₱<?= number_format($m['total'],2) ?>"></div>
                                    <span class="bar-label"><?= substr($m['label'],0,3) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Methods -->
            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title">Payment Methods</div>
                        <div class="section-sub">Breakdown for selected period</div>
                    </div>
                </div>
                <div class="method-grid">
                    <?php
                    $methodConfig = [
                        'Cash'   => ['icon'=>'bi-cash-coin',      'color'=>'#dcfce7', 'icolor'=>'#16a34a'],
                        'GCash'  => ['icon'=>'bi-phone-fill',     'color'=>'#dbeafe', 'icolor'=>'#2563eb'],
                        'Bank'   => ['icon'=>'bi-bank',           'color'=>'#f3e8ff', 'icolor'=>'#7c3aed'],
                    ];
                    $methodTotals = [];
                    foreach ($paymentMethods as $pm) $methodTotals[$pm['payment_method']] = $pm;

                    foreach ($methodConfig as $name => $cfg):
                        $total = $methodTotals[$name]['total'] ?? 0;
                        $cnt   = $methodTotals[$name]['cnt']   ?? 0;
                    ?>
                        <div class="method-card">
                            <div class="method-icon">
                                <div style="background:<?= $cfg['color'] ?>; width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; margin:0 auto;">
                                    <i class="bi <?= $cfg['icon'] ?>" style="color:<?= $cfg['icolor'] ?>; font-size:20px;"></i>
                                </div>
                            </div>
                            <div class="method-label"><?= $name ?></div>
                            <div class="method-value">₱<?= number_format($total, 2) ?></div>
                            <div class="method-count"><?= $cnt ?> transaction<?= $cnt != 1 ? 's' : '' ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Job Stats + Top Customers + Sales Status -->
        <div class="report-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <!-- Job Order Summary -->
            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title">Job Orders</div>
                        <div class="section-sub">By status in period</div>
                    </div>
                </div>
                <div class="section-body">
                    <?php
                    $jobConfig = [
                        'Pending'   => ['color'=>'#f59e0b', 'bg'=>'#fef3c7'],
                        'Ongoing'   => ['color'=>'#2563eb', 'bg'=>'#dbeafe'],
                        'Completed' => ['color'=>'#16a34a', 'bg'=>'#dcfce7'],
                        'Cancelled' => ['color'=>'#dc2626', 'bg'=>'#fee2e2'],
                    ];
                    foreach ($jobConfig as $status => $cfg): ?>
                        <div class="job-stat-row">
                            <div class="job-stat-left">
                                <div class="job-dot" style="background:<?= $cfg['color'] ?>;"></div>
                                <span class="job-stat-label"><?= $status ?></span>
                            </div>
                            <span class="job-stat-value" style="color:<?= $cfg['color'] ?>;">
                                <?= $jobStats[$status] ?? 0 ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Top Customers -->
            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title">Top Customers</div>
                        <div class="section-sub">By revenue in period</div>
                    </div>
                </div>
                <div class="section-body">
                    <?php if (empty($topCustomers)): ?>
                        <div class="empty-state" style="padding:20px;">
                            <div class="empty-icon">👤</div>
                            <div class="empty-text">No data yet</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($topCustomers as $i => $c):
                            $rankClass = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
                        ?>
                            <div class="customer-row">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div class="customer-rank <?= $rankClass ?>"><?= $i+1 ?></div>
                                    <div>
                                        <div class="customer-name"><?= htmlspecialchars($c['customer']) ?></div>
                                        <div class="customer-txn"><?= $c['txn_count'] ?> transaction<?= $c['txn_count']!=1?'s':'' ?></div>
                                    </div>
                                </div>
                                <div class="customer-amount">₱<?= number_format($c['total'], 2) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sales by Status -->
            <div class="section-card">
                <div class="section-header">
                    <div>
                        <div class="section-title">Sales Status</div>
                        <div class="section-sub">Invoice breakdown</div>
                    </div>
                </div>
                <div class="section-body">
                    <?php
                    $statusConfig = [
                        'Paid'          => ['pill'=>'pill-paid',    'label'=>'Paid'],
                        'Partially Paid'=> ['pill'=>'pill-partial', 'label'=>'Partial'],
                        'Unpaid'        => ['pill'=>'pill-unpaid',  'label'=>'Unpaid'],
                    ];
                    foreach ($statusConfig as $s => $cfg):
                        $data = $salesByStatus[$s] ?? ['cnt'=>0,'total'=>0];
                        $cnt   = is_array($data) ? $data['cnt']   : 0;
                        $total = is_array($data) ? $data['total'] : 0;
                    ?>
                        <div class="sales-status-row">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span class="status-pill <?= $cfg['pill'] ?>"><?= $cfg['label'] ?></span>
                                <span style="font-size:12px; color:var(--muted);"><?= $cnt ?> invoice<?= $cnt!=1?'s':'' ?></span>
                            </div>
                            <span style="font-size:14px; font-weight:700;">₱<?= number_format($total,2) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="section-card" style="margin-bottom:20px;">
            <div class="section-header">
                <div>
                    <div class="section-title">Recent Transactions</div>
                    <div class="section-sub">Latest payments in selected period</div>
                </div>
                <a href="payments.php" style="font-size:12px; color:var(--accent); text-decoration:none; font-weight:600;">
                    View all →
                </a>
            </div>
            <?php if (empty($recentTransactions)): ?>
                <div class="empty-state">
                    <div class="empty-icon">💳</div>
                    <div class="empty-text">No transactions in this period</div>
                </div>
            <?php else: ?>
                <table class="txn-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Method</th>
                            <th>Amount Paid</th>
                            <th>Sale Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $txn):
                            $methodClass = strtolower($txn['payment_method']) === 'cash' ? 'method-cash' :
                                          (strtolower($txn['payment_method']) === 'gcash' ? 'method-gcash' : 'method-bank');
                        ?>
                            <tr>
                                <td>
                                    <div><?= date('M d, Y', strtotime($txn['payment_date'])) ?></div>
                                    <div style="font-size:11px; color:var(--muted);"><?= date('h:i A', strtotime($txn['payment_date'])) ?></div>
                                </td>
                                <td style="font-weight:600; color:var(--accent);">
                                    <?= $txn['invoice_number'] ? '#'.htmlspecialchars($txn['invoice_number']) : '—' ?>
                                </td>
                                <td><?= htmlspecialchars($txn['customer']) ?></td>
                                <td>
                                    <span class="method-badge <?= $methodClass ?>">
                                        <?= htmlspecialchars($txn['payment_method']) ?>
                                    </span>
                                </td>
                                <td style="font-weight:700; color:#16a34a;">₱<?= number_format($txn['amount_paid'], 2) ?></td>
                                <td>₱<?= number_format($txn['final_amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
    function applyQuickRange(val) {
        const today = new Date();
        let from, to;
        to = today.toISOString().split('T')[0];

        if (val === 'this_month') {
            from = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2,'0') + '-01';
        } else if (val === 'last_month') {
            const d = new Date(today.getFullYear(), today.getMonth()-1, 1);
            from = d.toISOString().split('T')[0];
            const last = new Date(today.getFullYear(), today.getMonth(), 0);
            to = last.toISOString().split('T')[0];
        } else if (val === 'this_year') {
            from = today.getFullYear() + '-01-01';
        } else if (val === 'last_30') {
            const d = new Date(today); d.setDate(d.getDate()-30);
            from = d.toISOString().split('T')[0];
        } else if (val === 'last_90') {
            const d = new Date(today); d.setDate(d.getDate()-90);
            from = d.toISOString().split('T')[0];
        } else { return; }

        document.querySelector('input[name=date_from]').value = from;
        document.querySelector('input[name=date_to]').value = to;
        document.querySelector('form').submit();
    }
</script>
</body>
</html>
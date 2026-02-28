<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/dbconnection.php';

$todayLabel = date('l, F j, Y');
$hourNow = (int)date('G');
$greeting = ($hourNow < 12) ? 'Good morning' : (($hourNow < 17) ? 'Good afternoon' : 'Good evening');

$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
if (!$role || !in_array(strtolower($role), ['owner', 'business_partner'])) {
    die('Access Denied. Please log in as an Owner.');
}

$totalRevenue = 0; $activeJobs = 0; $completedToday = 0;
$activeWarranties = 0; $unpaidInvoices = 0;
$activeJobRows = []; $monthlyRevenue = []; $creditAccounts = [];

if (isset($conn) && $conn) {
    $res = $conn->query("SELECT SUM(amount_paid) AS total FROM payments");
    if ($res && $row = $res->fetch_assoc()) { $totalRevenue = $row['total'] ?? 0; }

    $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status != 'Completed'");
    if ($res && $row = $res->fetch_assoc()) { $activeJobs = $row['cnt']; }

    $res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status = 'Completed' AND DATE(date_completed) = CURDATE()");
    if ($res && $row = $res->fetch_assoc()) { $completedToday = $row['cnt']; }

    $res = $conn->query("SELECT COUNT(*) AS cnt FROM warranties WHERE warranty_end >= CURDATE() AND warranty_status = 'Active'");
    if ($res && $row = $res->fetch_assoc()) { $activeWarranties = $row['cnt']; }

    $res = $conn->query("SELECT COUNT(*) AS cnt FROM sales WHERE status = 'Unpaid'");
    if ($res && $row = $res->fetch_assoc()) { $unpaidInvoices = $row['cnt']; }

    // active jobs table
    $res = $conn->query("
        SELECT jo.job_order_id, CONCAT(c.first_name, ' ', c.last_name) AS customer,
               CONCAT(v.brand, ' ', v.model) AS vehicle,
               jo.job_description AS service, jo.status
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.customer_id
        LEFT JOIN vehicles v ON jo.vehicle_id = v.vehicle_id
        WHERE jo.status != 'Completed'
        ORDER BY jo.date_received DESC LIMIT 20
    ");
    while ($res && $row = $res->fetch_assoc()) { $activeJobRows[] = $row; }

    // monthly chart
    $res = $conn->query("
        SELECT DATE_FORMAT(payment_date, '%b') AS month, SUM(amount_paid) AS total
        FROM payments
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY payment_date ASC
    ");
    while ($res && $row = $res->fetch_assoc()) { $monthlyRevenue[] = $row; }

    // credit List
    $res = $conn->query("
        SELECT c.first_name, c.last_name, ca.current_balance, ca.credit_limit
        FROM credit_accounts ca
        JOIN customers c ON ca.customer_id = c.customer_id
        ORDER BY ca.current_balance DESC LIMIT 5
    ");
    while ($res && $row = $res->fetch_assoc()) { $creditAccounts[] = $row; }
}

$firstname = htmlspecialchars($_SESSION['firstname'] ?? 'Admin');
$userInitials = strtoupper(substr($firstname, 0, 2));
$userRoleLabel = htmlspecialchars($_SESSION['user_role'] ?? 'Staff');
$maxRev = !empty($monthlyRevenue) ? (max(array_column($monthlyRevenue, 'total')) ?: 1) : 1;
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AutoBert — Admin Dashboard</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

  <aside class="sidebar">

    <div class="logo">
      <a href="/" class="logo-container">
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

      <a class="nav-item active" href="#">
        <i class="bi bi-speedometer2"></i> Dashboard
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-clipboard-data"></i> Job Orders
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-currency-dollar"></i> Sales
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-credit-card"></i> Payments
      </a>

    </nav>

    <nav class="nav-section">
      <div class="nav-label">Management</div>

      <a class="nav-item" href="#">
        <i class="bi bi-people"></i> Customers
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-truck"></i> Vehicles
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-person-badge"></i> Employees
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-box-seam"></i> Products
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-shield-check"></i> Warranties
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-wallet2"></i> Credit Accounts
      </a>

    </nav>

    <nav class="nav-section">
      <div class="nav-label">System</div>

      <a class="nav-item" href="#">
        <i class="bi bi-bar-chart-line"></i> Reports
      </a>

      <a class="nav-item" href="#">
        <i class="bi bi-bell"></i> Notifications
      </a>

    </nav>

    <div class="sidebar-footer">
      <div class="user-row">
        <div class="avatar"><?= $userInitials ?></div>
        <div>
          <div class="user-name"><?= $firstname ?></div>
          <div class="user-role"><?= $userRoleLabel ?></div>
        </div>
        <div class="user-more">
          <i class="bi bi-three-dots"></i>
        </div>
      </div>
    </div>
  </aside>

  <main class="main">

    <header class="topbar">
      <div class="topbar-left">
        <span class="page-title">Dashboard</span>
        <span class="breadcrumb" style="color:#ccc; margin:0 4px;">·</span>
      </div>

      <div class="search-bar">
        <i class="bi bi-search"></i>
        <input type="text" placeholder="Search customer, job…">
      </div>

      <div class="topbar-right">
        <div class="icon-btn">
          <i class="bi bi-bell"></i>
          <?php if ($unpaidInvoices > 0): ?>
            <div class="notif-dot"></div>
          <?php endif; ?>
        </div>
        <button class="btn-primary">
          <i class="bi bi-plus-lg"></i> New Job Order
        </button>
      </div>
    </header>

    <div class="content">

      <div class="greeting">
        <h1><?= $greeting ?>, <?= $firstname ?></h1>
        <p>Here's what's happening at your shop today — <?= $todayLabel ?></p>
      </div>

      <div class="stats-grid">

        <div class="stat-card featured">
          <div class="stat-icon">💰</div>
          <div class="stat-label">Total Revenue</div>
          <div class="stat-value">₱<?= number_format($totalRevenue, 2) ?></div>
          <div class="stat-change <?= $totalRevenue > 0 ? 'up' : 'neutral' ?>">
            <?= $totalRevenue > 0 ? 'From payments' : 'No payments yet' ?>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon">🔧</div>
          <div class="stat-label">Active Jobs</div>
          <div class="stat-value"><?= $activeJobs ?></div>
          <div class="stat-change <?= $activeJobs > 0 ? 'up' : 'neutral' ?>">
            <?= $activeJobs > 0 ? 'In progress' : 'No active jobs' ?>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon">✅</div>
          <div class="stat-label">Completed Today</div>
          <div class="stat-value"><?= $completedToday ?></div>
          <div class="stat-change <?= $completedToday > 0 ? 'up' : 'neutral' ?>">
            <?= $completedToday > 0 ? 'Great progress' : 'None yet today' ?>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon">🛡️</div>
          <div class="stat-label">Active Warranties</div>
          <div class="stat-value"><?= $activeWarranties ?></div>
          <div class="stat-change <?= $activeWarranties > 0 ? 'up' : 'neutral' ?>">
            <?= $activeWarranties > 0 ? 'Currently active' : 'No warranties' ?>
          </div>
        </div>

        <div class="stat-card">
          <div class="stat-icon">⚠️</div>
          <div class="stat-label">Unpaid Invoices</div>
          <div class="stat-value"><?= $unpaidInvoices ?></div>
          <div class="stat-change <?= $unpaidInvoices > 0 ? 'down' : 'neutral' ?>">
            <?= $unpaidInvoices > 0 ? 'Needs attention' : 'All clear' ?>
          </div>
        </div>

      </div>

      <div class="bottom-grid">

        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Active Job Orders</div>
            </div>
            <a class="card-link" href="#">View all →</a>
          </div>

          <table class="job-table">
            <thead>
              <tr>
                <th>Job #</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Service</th>
                <th>Mechanic</th>
                <th>Status</th>
              </tr>
            </thead>

            <tbody>

              <?php if (empty($activeJobRows)): ?>
                <tr>
                  <td colspan="6">
                    <div class="empty-state">
                      <div class="empty-icon">🔩</div>
                      <div class="empty-text">No active job orders yet.</div>
                    </div>
                  </td>
                </tr>
              <?php else: ?>

                <?php foreach ($activeJobRows as $job): ?>
                  <tr>
                    <td>#<?= htmlspecialchars($job['id']) ?></td>
                    <td><?= htmlspecialchars($job['customer']) ?></td>
                    <td><?= htmlspecialchars($job['vehicle']) ?></td>
                    <td><?= htmlspecialchars($job['service']) ?></td>
                    <td><?= htmlspecialchars($job['mechanic'] ?? '—') ?></td>
                    <td>
                      <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $job['status'])) ?>">
                        <?= htmlspecialchars($job['status']) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>

            </tbody>
          </table>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-title">Quick Actions</div>
          </div>
          <div class="qa-grid">
            <button class="qa-btn">
              <div class="qa-icon">📋</div>
              New Job Order
            </button>
            <button class="qa-btn">
              <div class="qa-icon">💳</div>
              Record Payment
            </button>
            <button class="qa-btn">
              <div class="qa-icon">👤</div>
              Add Customer
            </button>
            <button class="qa-btn">
              <div class="qa-icon">📦</div>
              Add Product
            </button>
          </div>
        </div>

      </div>

      <div class="row-bottom">

        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Monthly Revenue</div>
            </div>
            <a class="card-link" href="#">Full Report →</a>
          </div>
          <div class="mini-chart">
            <div style="font-family:'Syne',sans-serif; font-size:22px; font-weight:700; margin-bottom:4px;">
              ₱<?= number_format($totalRevenue, 2) ?>
            </div>
            <div style="font-size:12px; color:var(--muted); margin-bottom:16px;">
              <?= empty($monthlyRevenue) ? 'No revenue recorded yet' : 'Total across last 6 months' ?>
            </div>
            <div class="chart-bars">
              <?php if (empty($monthlyRevenue)): ?>
                <?php foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'] as $m): ?>
                  <div class="bar-wrap">
                    <div class="bar" style="height:4px;"></div>
                    <span class="bar-label"><?= $m ?></span>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <?php foreach ($monthlyRevenue as $m):
                  $pct = max(4, round(($m['total'] / $maxRev) * 80));
                  ?>
                  <div class="bar-wrap">
                    <div class="bar active" style="height:<?= $pct ?>px;" title="₱<?= number_format($m['total'], 2) ?>">
                    </div>
                    <span class="bar-label"><?= htmlspecialchars($m['month']) ?></span>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Credit Accounts</div>
              <div class="card-sub">Balance overview</div>
            </div>
            <a class="card-link" href="#">Manage →</a>
          </div>
          <div class="credit-list">
            <?php if (empty($creditAccounts)): ?>
              <div style="padding:32px 0; text-align:center; color:var(--muted);">
                <div style="font-size:28px; opacity:0.25; margin-bottom:8px;">💼</div>
                <div style="font-size:13px;">No credit accounts yet</div>
              </div>
            <?php else: ?>
              <?php foreach ($creditAccounts as $ca): ?>
                <div class="credit-row">
                  <div>
                    <div class="credit-name">
                      <?= htmlspecialchars($ca['first_name'] . ' ' . $ca['last_name']) ?>
                    </div>
                    <div class="credit-limit">
                      Limit: ₱<?= number_format($ca['credit_limit'], 2) ?>
                    </div>
                  </div>
                  <div class="credit-amount <?= $ca['balance'] < 0 ? 'owed' : 'credit' ?>">
                    ₱<?= number_format(abs($ca['balance']), 2) ?>
                    <?php if ($ca['balance'] < 0): ?>
                      <span class="balance-tag">Owed</span>
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

  </body>

</html>
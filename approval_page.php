<?php

$isOwner = $isOwner ?? (strtolower($_SESSION['role'] ?? '') === 'owner');
$pendingApprovals = $pendingApprovals ?? 0;
$activeJobs = $activeJobs ?? 0;
$firstname = $firstname ?? htmlspecialchars($_SESSION['firstname'] ?? 'User');
$userInitials = $userInitials ?? strtoupper(
    substr($_SESSION['firstname'] ?? 'U', 0, 1) .
    substr($_SESSION['lastname'] ?? '', 0, 1)
);
$role = $role ?? htmlspecialchars($_SESSION['role'] ?? '');

// determine the current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
?>

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
        <a class="nav-item <?= $currentPage === 'admin_dashboard.php' ? 'active' : '' ?>" href="admin_dashboard.php">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a class="nav-item <?= $currentPage === 'new_job_order.php' ? 'active' : '' ?>" href="new_job_order.php">
            <i class="bi bi-clipboard-data"></i> Job Orders
            <?php if ($activeJobs > 0): ?>
                <span class="pending-approvals-badge" style="background: var(--accent);"><?= $activeJobs ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-item <?= $currentPage === 'sales.php' ? 'active' : '' ?>" href="sales.php">
            <i class="bi bi-currency-dollar"></i> Sales
        </a>
        <a class="nav-item <?= $currentPage === 'payments.php' ? 'active' : '' ?>" href="payments.php">
            <i class="bi bi-credit-card"></i> Payments
        </a>
        <a class="nav-item <?= $currentPage === 'products.php' ? 'active' : '' ?>" href="products.php">
            <i class="bi bi-box-seam"></i> Products
        </a>
    </nav>

    <nav class="nav-section">
        <div class="nav-label">Management</div>
        <a class="nav-item <?= $currentPage === 'customers.php' ? 'active' : '' ?>" href="customers.php">
            <i class="bi bi-people"></i> Customers
        </a>
        <a class="nav-item <?= $currentPage === 'vehicles.php' ? 'active' : '' ?>" href="vehicles.php">
            <i class="bi bi-truck"></i> Vehicles
        </a>
        <?php if ($isOwner): ?>
            <a class="nav-item <?= $currentPage === 'employees.php' ? 'active' : '' ?>" href="employees.php">
                <i class="bi bi-person-badge"></i> Employees
            </a>
            <a class="nav-item <?= $currentPage === 'admin_approvals.php' ? 'active' : '' ?>" href="admin_approvals.php">
                <i class="bi bi-check-circle"></i> Approvals
                <?php if ($pendingApprovals > 0): ?>
                    <span class="pending-approvals-badge"><?= $pendingApprovals ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        
        <a class="nav-item <?= $currentPage === 'warranties.php' ? 'active' : '' ?>" href="warranties.php">
            <i class="bi bi-shield-check"></i> Warranties
        </a>
        <a class="nav-item <?= $currentPage === 'credit_accounts.php' ? 'active' : '' ?>" href="credit_accounts.php">
            <i class="bi bi-wallet2"></i> Credit Accounts
        </a>
    </nav>

    <nav class="nav-section">
        <div class="nav-label">Owner</div>
        <a class="nav-item <?= $currentPage === 'reports.php' ? 'active' : '' ?>" href="reports.php">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="user-row">
            <div class="avatar"><?= $userInitials ?></div>
            <div>
                <div class="user-name"><?= $firstname ?></div>
                <div class="user-role"><?= $role ?></div>
            </div>
            <div class="user-more">
                <i class="bi bi-three-dots"></i>
            </div>
        </div>
        <div style="margin-top: 10px; text-align: center;">
            <a href="logout.php" style="color: var(--sidebar-text); text-decoration: none; font-size: 12px;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>
</aside>
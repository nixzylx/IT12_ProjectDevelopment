<?php
session_start();
require_once 'dbconnection.php';

if (!isset($_SESSION['employeeID'])) {
    header("Location: index.php?error=Please log in first");
    exit();
}

$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
if (!$role || !in_array(strtolower($role), ['owner', 'business partner'])) {
    die('Access Denied. Only owners and business partners can access this page. <br><a href="index.php">Return to Login</a>');
}

// handle approve or reject
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $admin_id = $_SESSION['employeeID'] ?? 0;

    if ($_POST['action'] === 'approve') {
        $stmt = $conn->prepare("UPDATE employee SET is_approved = 1, approved_by = ?, approved_at = NOW() WHERE employeeID = ?");
        $stmt->bind_param("ii", $admin_id, $employee_id);
        $stmt->execute();
        $successMsg = "User approved successfully!";
    } elseif ($_POST['action'] === 'reject') {
        $stmt = $conn->prepare("DELETE FROM employee WHERE employeeID = ? AND is_approved = 0");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $successMsg = "User rejected and removed.";
    }
}

$firstname = htmlspecialchars($_SESSION['firstname'] ?? 'User');
$userInitials = strtoupper(substr($_SESSION['firstname'] ?? 'U', 0, 1) . substr($_SESSION['lastname'] ?? '', 0, 1));
$isOwner = strtolower($role) === 'owner';
$aj_res = $conn->query("SELECT COUNT(*) AS cnt FROM job_orders WHERE status NOT IN ('Completed','Cancelled')");
$activeJobs = ($aj_res && $r = $aj_res->fetch_assoc()) ? $r['cnt'] : 0;

// counts 
$pending_result = $conn->query("SELECT employeeID, first_name, last_name, email, role, created_at FROM employee WHERE is_approved = 0 ORDER BY created_at DESC");
$pendingApprovals = $pending_result ? $pending_result->num_rows : 0;

// stats
$total_employees = 0;
$total_partners = 0;
$res = $conn->query("SELECT role, COUNT(*) AS cnt FROM employee WHERE is_approved = 1 GROUP BY role");
while ($res && $r = $res->fetch_assoc()) {
    $total_employees += $r['cnt'];
    if (strtolower($r['role']) === 'business partner')
        $total_partners = $r['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Approvals — AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <style>
        .approval-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 32px 0 16px;
        }

        .section-header h2 {
            font-family: "Syne", sans-serif;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-header h2 i { color: var(--accent); }

        .count-badge {
            background: var(--accent);
            color: #fff;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 12px;
            font-weight: 600;
        }

        .count-badge.pending { background: var(--accent-2); }

        .user-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            padding: 20px 24px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: box-shadow 0.2s, transform 0.2s;
        }

        .user-card:hover { box-shadow: 0 4px 16px rgba(0, 0, 0, 0.07); transform: translateY(-1px); }
        .user-card.approved-card { background: #fafaf8; }

        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 15px;
            color: #fff;
            flex-shrink: 0;
            margin-right: 16px;
        }

        .user-avatar.partner { background: #2563eb; }
        .user-avatar.mechanic { background: #16a34a; }
        .user-avatar.owner { background: #dc2626; }

        .user-left {
            display: flex;
            align-items: center;
            flex: 1;
        }

        .user-info h3 {
            margin: 0 0 3px;
            font-size: 15px;
            font-weight: 600;
        }

        .user-info p {
            margin: 0 0 6px;
            color: var(--muted);
            font-size: 13px;
        }

        .user-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .user-meta span {
            font-size: 12px;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .role-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .role-badge.owner { background: #fee2e2; color: #991b1b; }
        .role-badge.business-partner { background: #dbeafe; color: #1e40af; }
        .role-badge.employee { background: #dcfce7; color: #166534; }
        .action-buttons { display: flex; gap: 8px; flex-shrink: 0; }

        .btn-approve {
            background: var(--green);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            font-family: inherit;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-approve:hover { background: #15803d; transform: translateY(-1px); }

        .btn-reject {
            background: #fff;
            color: var(--red);
            border: 1.5px solid var(--red);
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            font-family: inherit;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s;
        }

        .btn-reject:hover { background: #fee2e2; transform: translateY(-1px); }

        .approved-check {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #dcfce7;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--green);
            font-size: 16px;
            flex-shrink: 0;
        }

        .empty-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--card-radius);
            padding: 48px 24px;
            text-align: center;
            color: var(--muted);
        }

        .empty-card i {
            font-size: 40px;
            display: block;
            margin-bottom: 12px;
            opacity: .3;
        }

        .empty-card p { font-size: 13px; }

        .page-alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-alert.success { background: #d1fae5; color: #065f46; }
        .page-alert.error { background: #fee2e2; color: #991b1b; }
    </style>
</head>

<body>
    <?php include 'approval_page.php'; ?>

    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <span class="page-title">User Approvals</span>
                <span class="breadcrumb">Manage Account Requests</span>
            </div>
            <div class="topbar-right">
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

            <div class="approval-stats">
                <div class="stat-card featured">
                    <div class="stat-label">Total Approved</div>
                    <div class="stat-value"><?= $total_employees ?></div>
                    <div class="stat-change">Active accounts</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Pending Requests</div>
                    <div class="stat-value" style="color:var(--accent-2)"><?= $pendingApprovals ?></div>
                    <div class="stat-change"><?= $pendingApprovals > 0 ? 'Needs your approval' : 'All clear' ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Business Partners</div>
                    <div class="stat-value" style="color:var(--accent)"><?= $total_partners ?></div>
                    <div class="stat-change">Approved partners</div>
                </div>
            </div>

            <div class="section-header">
                <h2><i class="bi bi-hourglass-split"></i> Pending Approvals</h2>
                <?php if ($pendingApprovals > 0): ?>
                    <span class="count-badge pending"><?= $pendingApprovals ?> waiting</span>
                <?php endif; ?>
            </div>

            <?php
            $pending2 = $conn->query("SELECT employeeID, first_name, last_name, email, role, created_at FROM employee WHERE is_approved = 0 ORDER BY created_at DESC");
            if ($pending2 && $pending2->num_rows > 0):
                while ($user = $pending2->fetch_assoc()):
                    $initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
                    $role_class = strtolower(str_replace(' ', '-', $user['role']));
                    $avatar_class = $role_class === 'business-partner' ? 'partner' : ($role_class === 'owner' ? 'owner' : 'mechanic');
                    ?>
                    <div class="user-card">
                        <div class="user-left">
                            <div class="user-avatar <?= $avatar_class ?>"><?= $initials ?></div>
                            <div class="user-info">
                                <h3><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h3>
                                <p><i class="bi bi-envelope" style="font-size:11px"></i> <?= htmlspecialchars($user['email']) ?>
                                </p>
                                <div class="user-meta">
                                    <span class="role-badge <?= $role_class ?>"><?= htmlspecialchars($user['role']) ?></span>
                                    <span><i class="bi bi-calendar3"></i> Registered
                                        <?= date('M d, Y', strtotime($user['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="employee_id" value="<?= $user['employeeID'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn-approve"><i class="bi bi-check-lg"></i> Approve</button>
                            </form>
                            <form method="POST" style="display:inline;"
                                onsubmit="return confirm('Reject and permanently delete this user?');">
                                <input type="hidden" name="employee_id" value="<?= $user['employeeID'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn-reject"><i class="bi bi-x-lg"></i> Reject</button>
                            </form>
                        </div>
                    </div>
                    <?php
                endwhile;
            else: ?>
                <div class="empty-card">
                    <i class="bi bi-check-circle"></i>
                    <p>No pending approvals</p>
                </div>
            <?php endif; ?>

            <!-- approved users -->
            <?php
            $approved2 = $conn->query("
                SELECT e.*, a.first_name AS approver_first, a.last_name AS approver_last
                FROM employee e
                LEFT JOIN employee a ON e.approved_by = a.employeeID
                WHERE e.is_approved = 1
                ORDER BY e.approved_at DESC
            ");
            $approvedCount = $approved2 ? $approved2->num_rows : 0;
            ?>
            <div class="section-header" style="margin-top:40px;">
                <h2><i class="bi bi-people"></i> Approved Users</h2>
                <?php if ($approvedCount > 0): ?>
                    <span class="count-badge"><?= $approvedCount ?> users</span>
                <?php endif; ?>
            </div>

            <?php if ($approved2 && $approved2->num_rows > 0):
                while ($user = $approved2->fetch_assoc()):
                    $initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
                    $role_class = strtolower(str_replace(' ', '-', $user['role']));
                    $avatar_class = $role_class === 'business-partner' ? 'partner' : ($role_class === 'owner' ? 'owner' : 'mechanic');
                    $approver = trim(($user['approver_first'] ?? '') . ' ' . ($user['approver_last'] ?? ''));
                    ?>
                    <div class="user-card approved-card">
                        <div class="user-left">
                            <div class="user-avatar <?= $avatar_class ?>"><?= $initials ?></div>
                            <div class="user-info">
                                <h3><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h3>
                                <p><i class="bi bi-envelope" style="font-size:11px"></i> <?= htmlspecialchars($user['email']) ?>
                                </p>
                                <div class="user-meta">
                                    <span class="role-badge <?= $role_class ?>"><?= htmlspecialchars($user['role']) ?></span>
                                    <?php if ($user['approved_at']): ?>
                                        <span><i class="bi bi-calendar-check"></i> Approved
                                            <?= date('M d, Y', strtotime($user['approved_at'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($approver): ?>
                                        <span><i class="bi bi-person-check"></i> by <?= htmlspecialchars($approver) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="approved-check"><i class="bi bi-check-lg"></i></div>
                    </div>
                    <?php
                endwhile;
            else: ?>
                <div class="empty-card">
                    <i class="bi bi-people"></i>
                    <p>No approved users yet.</p>
                </div>
            <?php endif; ?>

        </div>
    </main>
</body>

</html>
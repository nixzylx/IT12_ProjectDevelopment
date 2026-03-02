<?php
session_start();
require_once 'dbconnection.php';

// check if user is logged in and is owner/business partner
$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
if (!$role || !in_array(strtolower($role), ['owner', 'business_partner'])) {
    die('Access Denied. Only owners and business partners can access this page.');
}

// handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $employee_id = intval($_POST['employee_id'] ?? 0);
    $admin_id = $_SESSION['employeeID'] ?? 0;
    
    if ($_POST['action'] === 'approve') {
        $stmt = $conn->prepare("UPDATE employee SET is_approved = 1, approved_by = ?, approved_at = NOW() WHERE employeeID = ?");
        $stmt->bind_param("ii", $admin_id, $employee_id);
        $stmt->execute();
        $message = "User approved successfully!";
    } elseif ($_POST['action'] === 'reject') {
        $stmt = $conn->prepare("DELETE FROM employee WHERE employeeID = ? AND is_approved = 0");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $message = "User rejected and removed.";
    }
}

// Get pending approvals
$pending_query = "SELECT employeeID, first_name, last_name, email, role, created_at 
                  FROM employee 
                  WHERE is_approved = 0 
                  ORDER BY created_at DESC";
$pending_result = $conn->query($pending_query);

// Get approved users
$approved_query = "SELECT e.*, a.first_name as approver_name 
                   FROM employee e
                   LEFT JOIN employee a ON e.approved_by = a.employeeID
                   WHERE e.is_approved = 1 
                   ORDER BY e.created_at DESC";
$approved_result = $conn->query($approved_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Approvals - AutoBert</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .approvals-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .section-title {
            font-family: "Syne", sans-serif;
            font-size: 20px;
            margin: 30px 0 20px;
        }
        
        .user-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-info h3 {
            margin: 0 0 5px;
            font-size: 16px;
        }
        
        .user-info p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        
        .user-meta {
            display: flex;
            gap: 20px;
            margin-top: 8px;
        }
        
        .role-badge {
            background: #e5e7eb;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .role-badge.owner { background: #fee2e2; color: #991b1b; }
        .role-badge.business-partner { background: #dbeafe; color: #1e40af; }
        .role-badge.employee { background: #dcfce7; color: #166534; }
        
        .action-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-approve {
            background: #10b981;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-reject {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            background: #f9fafb;
            border-radius: 12px;
            color: #6b7280;
        }
        
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .message.success {
            background: #d1fae5;
            color: #065f46;
        }
    </style>
    
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <main class="main">
        <header class="topbar">
            <div class="topbar-left">
                <span class="page-title">User Approvals</span>
            </div>
        </header>
        
        <div class="approvals-container">
            <?php if (isset($message)): ?>
                <div class="message success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <h2 class="section-title">Pending Approvals</h2>
            
            <?php if ($pending_result && $pending_result->num_rows > 0): ?>
                <?php while ($user = $pending_result->fetch_assoc()): ?>
                    <div class="user-card">
                        <div class="user-info">
                            <h3><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h3>
                            <p><?= htmlspecialchars($user['email']) ?></p>
                            <div class="user-meta">
                                <span class="role-badge <?= strtolower(str_replace(' ', '-', $user['role'])) ?>">
                                    <?= htmlspecialchars($user['role']) ?>
                                </span>
                                <span>Registered: <?= date('M d, Y', strtotime($user['created_at'])) ?></span>
                            </div>
                        </div>
                        
                        <div class="action-buttons">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="employee_id" value="<?= $user['employeeID'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn-approve">Approve</button>
                            </form>
                            
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reject this user?');">
                                <input type="hidden" name="employee_id" value="<?= $user['employeeID'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn-reject">Reject</button>
                            </form>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-check-circle" style="font-size: 48px;"></i>
                    <p>No pending approvals</p>
                </div>
            <?php endif; ?>
            
            <h2 class="section-title" style="margin-top: 40px;">Approved Users</h2>
            
            <?php if ($approved_result && $approved_result->num_rows > 0): ?>
                <?php while ($user = $approved_result->fetch_assoc()): ?>
                    <div class="user-card" style="background: #f9fafb;">
                        <div class="user-info">
                            <h3><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h3>
                            <p><?= htmlspecialchars($user['email']) ?></p>
                            <div class="user-meta">
                                <span class="role-badge <?= strtolower(str_replace(' ', '-', $user['role'])) ?>">
                                    <?= htmlspecialchars($user['role']) ?>
                                </span>
                                <?php if ($user['approved_at']): ?>
                                    <span>Approved: <?= date('M d, Y', strtotime($user['approved_at'])) ?></span>
                                    <?php if ($user['approver_name']): ?>
                                        <span>by: <?= htmlspecialchars($user['approver_name']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
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
$userRoleLabel = $userRoleLabel ?? $role;
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);

include 'sidebar.php';


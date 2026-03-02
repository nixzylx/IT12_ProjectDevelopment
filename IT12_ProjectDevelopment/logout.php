<?php
session_start();
$_SESSION['logout_message'] = 'You have been logged out successfully.';
session_destroy();
header("Location: index.php");
exit();
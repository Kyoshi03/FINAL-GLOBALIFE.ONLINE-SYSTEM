<?php
require_once 'includes/session.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit();
}

if (($_SESSION['user_role'] ?? '') === 'admin') {
    header('Location: admin_doctors.php');
    exit();
}

$_SESSION['error'] = 'Doctor schedules and provider accounts are managed by the administrator.';
header('Location: ' . dashboardForRole((string) ($_SESSION['user_role'] ?? '')));
exit();

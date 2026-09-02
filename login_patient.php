<?php
require_once 'includes/session.php';

if (isLoggedIn()) {
    redirectToDashboardForCurrentUser();
}

$query = [];
if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $query['registered'] = '1';
}
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    $query['reset'] = '1';
}

$target = 'index.php';
if ($query) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target . '#patient-login');
exit();

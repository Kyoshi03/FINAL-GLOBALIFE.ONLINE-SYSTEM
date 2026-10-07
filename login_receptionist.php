<?php
require_once 'includes/session.php';

if (isLoggedIn()) {
    redirectToDashboardForCurrentUser();
}

header('Location: index.php');
exit();

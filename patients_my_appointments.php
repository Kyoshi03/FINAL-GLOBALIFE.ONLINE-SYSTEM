<?php
require_once __DIR__ . '/includes/session.php';
checkRole('patient');

$highlight = (int) ($_GET['highlight'] ?? 0);
$target = 'patients_view_appointments.php';
if ($highlight > 0) {
    $target .= '?highlight=' . $highlight;
}

header('Location: ' . $target);
exit();

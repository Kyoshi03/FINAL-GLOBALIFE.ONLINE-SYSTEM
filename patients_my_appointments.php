<?php
require_once __DIR__ . '/includes/session.php';
checkRole('patient');

$params = ['view' => 'active'];
$highlight = (int) ($_GET['highlight'] ?? 0);
if ($highlight > 0) {
    $params['highlight'] = $highlight;
}

header('Location: patients_view_appointments.php?' . http_build_query($params));
exit();

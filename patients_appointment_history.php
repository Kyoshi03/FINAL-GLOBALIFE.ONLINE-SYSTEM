<?php
require_once __DIR__ . '/includes/session.php';
checkRole('patient');

header('Location: patients.php#recentHistoryPanel');
exit();

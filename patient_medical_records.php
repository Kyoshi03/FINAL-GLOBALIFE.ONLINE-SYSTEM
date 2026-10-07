<?php
require_once 'includes/session.php';
checkRole('patient');

header('Location: patients.php');
exit;

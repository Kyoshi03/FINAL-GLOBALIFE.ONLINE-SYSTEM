<?php
require_once 'includes/session.php';
checkRole('doctor');

header('Location: nurse_patients.php');
exit();

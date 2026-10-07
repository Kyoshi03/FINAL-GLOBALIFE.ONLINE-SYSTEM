<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'includes/session.php';
checkRole('patient');

unset($_SESSION['pending_appointment_verification_id']);

header('Location: book_appointment.php?start=1');
exit();

<?php
require_once 'includes/session.php';
checkRole('patient');

require __DIR__ . '/calendar.php';

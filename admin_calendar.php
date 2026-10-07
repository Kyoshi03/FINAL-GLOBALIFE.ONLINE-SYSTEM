<?php
require_once 'includes/session.php';
checkRole('admin');

require __DIR__ . '/calendar.php';

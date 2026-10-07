<?php
require_once 'includes/session.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_notifications.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit();
}

$currentUser = getCurrentUser();
if (($currentUser['role'] ?? '') !== 'patient') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Patient access required']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit();
}

$conn = getDBConnection();
$ok = mark_patient_notifications_read($conn, (int) ($currentUser['id'] ?? 0));
$conn->close();

echo json_encode(['ok' => $ok]);

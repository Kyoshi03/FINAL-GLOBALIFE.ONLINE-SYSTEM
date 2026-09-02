<?php
require_once 'includes/session.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/clinic_notifications.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$currentUser = getCurrentUser();
$role = (string) ($currentUser['role'] ?? '');
if ($role !== 'doctor') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Clinic staff access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

try {
    $conn = getDBConnection();
    $ok = mark_clinic_notifications_read($conn, $role, (int) ($currentUser['id'] ?? 0));
    $conn->close();
    echo json_encode(['ok' => $ok]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update notifications']);
}

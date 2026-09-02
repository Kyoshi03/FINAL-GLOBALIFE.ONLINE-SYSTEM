<?php
require_once 'includes/session.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/admin_notifications.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (getCurrentUser()['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $conn = getDBConnection();
    $ok = mark_admin_notifications_read($conn);
    $conn->close();
    echo json_encode(['ok' => $ok]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update notifications']);
}

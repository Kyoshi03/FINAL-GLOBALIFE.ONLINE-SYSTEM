<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$maintenanceToken = '7f4d9a2c1e6b8f03c5d7a9e1b4f6c8d0';
if (!hash_equals($maintenanceToken, (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

if (strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) !== 'globallife.online') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Live host required']);
    exit;
}

require_once __DIR__ . '/config/database.php';

$targets = [
    'ming',
    'salvador',
    'salvadorbillones321@gmail.com',
    'shinobillones@gmail.com',
];

$conn = getDBConnection();
$lookup = $conn->prepare(
    "SELECT id, username, email, role, first_name, middle_name, last_name
     FROM users
     WHERE LOWER(TRIM(username)) IN (?, ?)
        OR LOWER(TRIM(email)) IN (?, ?)
     ORDER BY id ASC"
);
$lookup->bind_param('ssss', $targets[0], $targets[1], $targets[2], $targets[3]);
$lookup->execute();
$result = $lookup->get_result();
$users = [];
$countQueries = [
    'appointments' => 'SELECT COUNT(*) AS total FROM appointments WHERE patient_id = ?',
    'reschedule_requests' => 'SELECT COUNT(*) AS total FROM appointment_reschedule_requests WHERE patient_id = ? OR requested_by = ?',
    'clinic_queue' => 'SELECT COUNT(*) AS total FROM clinic_queue WHERE patient_id = ?',
    'clinic_queue_services' => 'SELECT COUNT(*) AS total FROM clinic_queue_services WHERE queue_id IN (SELECT id FROM clinic_queue WHERE patient_id = ?)',
    'patient_notifications' => 'SELECT COUNT(*) AS total FROM patient_notifications WHERE patient_id = ?',
    'admin_notifications' => 'SELECT COUNT(*) AS total FROM admin_notifications WHERE related_user_id = ?',
    'clinic_notifications' => 'SELECT COUNT(*) AS total FROM clinic_notifications WHERE target_user_id = ?',
    'password_reset_codes' => 'SELECT COUNT(*) AS total FROM password_reset_codes WHERE user_id = ?',
    'medical_records' => 'SELECT COUNT(*) AS total FROM medical_records WHERE patient_id = ? OR author_id = ?',
    'lab_result_entries' => 'SELECT COUNT(*) AS total FROM lab_result_entries WHERE patient_id = ? OR author_id = ?',
];

while ($user = $result->fetch_assoc()) {
    $userId = (int) $user['id'];
    $counts = [];
    foreach ($countQueries as $name => $sql) {
        $count = $conn->prepare($sql);
        if (strpos($sql, 'OR ') !== false) {
            $count->bind_param('ii', $userId, $userId);
        } else {
            $count->bind_param('i', $userId);
        }
        $count->execute();
        $countRow = $count->get_result()->fetch_assoc();
        $counts[$name] = (int) ($countRow['total'] ?? 0);
        $count->close();
    }

    $users[] = [
        'id' => $userId,
        'username' => (string) $user['username'],
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) $user['role'],
        'name' => trim(implode(' ', array_filter([
            (string) ($user['first_name'] ?? ''),
            (string) ($user['middle_name'] ?? ''),
            (string) ($user['last_name'] ?? ''),
        ]))),
        'linked_counts' => $counts,
    ];
}
$lookup->close();

$action = strtolower((string) ($_GET['action'] ?? 'inspect'));
$deletedIds = [];
if ($action === 'delete') {
    $conn->begin_transaction();
    try {
        $deleteQueueServices = $conn->prepare(
            'DELETE FROM clinic_queue_services WHERE queue_id IN (SELECT id FROM clinic_queue WHERE patient_id = ?)'
        );
        $deleteQueue = $conn->prepare('DELETE FROM clinic_queue WHERE patient_id = ?');
        $deleteAdminNotifications = $conn->prepare('DELETE FROM admin_notifications WHERE related_user_id = ?');
        $delete = $conn->prepare('DELETE FROM users WHERE id = ? AND role = \'patient\'');
        foreach ($users as $user) {
            if ($user['role'] !== 'patient') {
                continue;
            }
            $userId = (int) $user['id'];
            $deleteQueueServices->bind_param('i', $userId);
            $deleteQueueServices->execute();
            $deleteQueue->bind_param('i', $userId);
            $deleteQueue->execute();
            $deleteAdminNotifications->bind_param('i', $userId);
            $deleteAdminNotifications->execute();
            $delete->bind_param('i', $userId);
            $delete->execute();
            if ($delete->affected_rows === 1) {
                $deletedIds[] = $userId;
            }
        }
        $deleteQueueServices->close();
        $deleteQueue->close();
        $deleteAdminNotifications->close();
        $delete->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $conn->close();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Delete transaction rolled back']);
        exit;
    }
}

$conn->close();
echo json_encode([
    'ok' => true,
    'action' => $action,
    'targets' => $targets,
    'matched_users' => $users,
    'deleted_ids' => $deletedIds,
], JSON_PRETTY_PRINT);

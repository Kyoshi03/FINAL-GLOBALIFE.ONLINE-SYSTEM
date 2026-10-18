<?php
declare(strict_types=1);

// One-time, exact-match cleanup for the test account. Remove this file after use.
const CLEANUP_TOKEN = '7de4ab53872d4bc88af2c14a60188f8d';
const TARGET_USERNAME = 'salvador';
const TARGET_EMAIL = 'shinobillones@gmail.com';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cleanup_response(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cleanup_response(['ok' => false, 'message' => 'POST required'], 405);
}

$providedToken = (string) ($_SERVER['HTTP_X_MAINTENANCE_TOKEN'] ?? '');
if (!hash_equals(CLEANUP_TOKEN, $providedToken)) {
    cleanup_response(['ok' => false, 'message' => 'Unauthorized'], 403);
}

if ((string) ($_POST['confirm'] ?? '') !== 'DELETE_EXAMPLE_ACCOUNT') {
    cleanup_response(['ok' => false, 'message' => 'Confirmation required'], 400);
}

require_once __DIR__ . '/config/database.php';

function cleanup_bind(mysqli_stmt $stmt, string $types, array &$values): void {
    if ($types === '') {
        return;
    }

    $params = [$types];
    foreach ($values as $key => &$value) {
        $params[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $params);
}

function cleanup_execute(mysqli $conn, string $sql, string $types = '', array $values = []): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    cleanup_bind($stmt, $types, $values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $error);
    }

    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function cleanup_rows(mysqli $conn, string $sql, string $types = '', array $values = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $conn->error);
    }

    cleanup_bind($stmt, $types, $values);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $error);
    }

    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $stmt->close();
    return $rows;
}

function cleanup_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    if (!$result) {
        throw new RuntimeException('Table check failed: ' . $conn->error);
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function cleanup_id_list(array $ids): string {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return implode(',', $ids);
}

function cleanup_delete_if_table(mysqli $conn, array &$counts, string $table, string $where): void {
    if (!cleanup_table_exists($conn, $table)) {
        return;
    }
    $counts[$table] = cleanup_execute($conn, "DELETE FROM `{$table}` WHERE {$where}");
}

try {
    $conn = getDBConnection();
    $conn->begin_transaction();

    $users = cleanup_rows(
        $conn,
        'SELECT id, username, email FROM users
         WHERE LOWER(username) = LOWER(?) AND LOWER(email) = LOWER(?)
         LIMIT 2 FOR UPDATE',
        'ss',
        [TARGET_USERNAME, TARGET_EMAIL]
    );

    if (count($users) !== 1) {
        $conn->rollback();
        $conn->close();
        cleanup_response([
            'ok' => true,
            'deleted' => false,
            'message' => count($users) === 0 ? 'No exact account found' : 'Multiple exact accounts found; nothing deleted',
            'matches' => count($users),
        ]);
    }

    $userId = (int) $users[0]['id'];
    $appointments = cleanup_rows(
        $conn,
        'SELECT id FROM appointments WHERE patient_id = ? OR doctor_id = ?',
        'ii',
        [$userId, $userId]
    );
    $appointmentIds = array_map(static fn(array $row): int => (int) $row['id'], $appointments);
    $appointmentList = cleanup_id_list($appointmentIds);

    $queueIds = [];
    if (cleanup_table_exists($conn, 'clinic_queue')) {
        $queueSql = 'SELECT id FROM clinic_queue WHERE patient_id = ?';
        $queueTypes = 'i';
        $queueValues = [$userId];
        if ($appointmentList !== '') {
            $queueSql .= " OR appointment_id IN ({$appointmentList})";
        }
        $queues = cleanup_rows($conn, $queueSql, $queueTypes, $queueValues);
        $queueIds = array_map(static fn(array $row): int => (int) $row['id'], $queues);
    }
    $queueList = cleanup_id_list($queueIds);

    $counts = [];
    if ($queueList !== '') {
        cleanup_delete_if_table($conn, $counts, 'clinic_queue_services', "queue_id IN ({$queueList})");
    }
    if ($queueList !== '' || $appointmentList !== '') {
        $queueWhere = "patient_id = {$userId}";
        if ($appointmentList !== '') {
            $queueWhere .= " OR appointment_id IN ({$appointmentList})";
        }
        cleanup_delete_if_table($conn, $counts, 'clinic_queue', $queueWhere);
    }

    cleanup_delete_if_table($conn, $counts, 'admin_notifications',
        "related_user_id = {$userId}" . ($appointmentList !== '' ? " OR related_appointment_id IN ({$appointmentList})" : ''));
    cleanup_delete_if_table($conn, $counts, 'clinic_notifications',
        "target_user_id = {$userId}" . ($appointmentList !== '' ? " OR related_appointment_id IN ({$appointmentList})" : ''));
    cleanup_delete_if_table($conn, $counts, 'patient_notifications',
        "patient_id = {$userId}" . ($appointmentList !== '' ? " OR related_appointment_id IN ({$appointmentList})" : ''));
    cleanup_delete_if_table($conn, $counts, 'medical_records', "patient_id = {$userId} OR author_id = {$userId}");
    cleanup_delete_if_table($conn, $counts, 'lab_result_entries', "patient_id = {$userId} OR author_id = {$userId}");
    cleanup_delete_if_table($conn, $counts, 'doctor_availability', "user_id = {$userId}");
    cleanup_delete_if_table($conn, $counts, 'password_reset_codes', "user_id = {$userId} OR LOWER(email) = LOWER('" . $conn->real_escape_string(TARGET_EMAIL) . "')");
    cleanup_delete_if_table($conn, $counts, 'appointment_reschedule_requests',
        "patient_id = {$userId} OR requested_by = {$userId} OR reviewed_by = {$userId}" . ($appointmentList !== '' ? " OR appointment_id IN ({$appointmentList})" : ''));

    if ($appointmentList !== '') {
        cleanup_delete_if_table($conn, $counts, 'appointment_email_reminders', "appointment_id IN ({$appointmentList})");
        cleanup_delete_if_table($conn, $counts, 'appointment_services', "appointment_id IN ({$appointmentList})");
        cleanup_delete_if_table($conn, $counts, 'appointments', "id IN ({$appointmentList})");
    }

    $counts['users'] = cleanup_execute(
        $conn,
        'DELETE FROM users WHERE id = ? AND LOWER(username) = LOWER(?) AND LOWER(email) = LOWER(?)',
        'iss',
        [$userId, TARGET_USERNAME, TARGET_EMAIL]
    );

    $remainingUser = cleanup_rows($conn,
        'SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND LOWER(email) = LOWER(?) LIMIT 1',
        'ss',
        [TARGET_USERNAME, TARGET_EMAIL]);
    $remainingAppointments = cleanup_rows($conn,
        'SELECT id FROM appointments WHERE patient_id = ? OR doctor_id = ?',
        'ii',
        [$userId, $userId]);
    if ($remainingUser || $remainingAppointments) {
        throw new RuntimeException('Verification failed; transaction rolled back');
    }

    $conn->commit();
    $conn->close();
    cleanup_response([
        'ok' => true,
        'deleted' => true,
        'user_id' => $userId,
        'appointments_found' => count($appointmentIds),
        'queues_found' => count($queueIds),
        'deleted_counts' => $counts,
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
        $conn->close();
    }
    cleanup_response(['ok' => false, 'message' => 'Cleanup failed; no changes committed'], 500);
}

<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once 'includes/doctor_schedule.php';

$pageTitle = 'Queue Management | Globalife Medical Laboratory & Polyclinic';
$today = date('Y-m-d');

function queue_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function queue_init_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS clinic_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        queue_date DATE NOT NULL,
        queue_type ENUM('walk_in','online') NOT NULL DEFAULT 'walk_in',
        queue_number VARCHAR(12) NOT NULL,
        patient_id INT NOT NULL,
        appointment_id INT DEFAULT NULL,
        service VARCHAR(255) NOT NULL DEFAULT 'General Consultation',
        service_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        priority_type VARCHAR(100) NOT NULL DEFAULT '',
        status ENUM('waiting','serving','completed','cancelled') NOT NULL DEFAULT 'waiting',
        time_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        time_called DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        UNIQUE KEY unique_daily_queue (queue_date, queue_number),
        KEY idx_queue_day_status (queue_date, status, queue_type, id),
        KEY idx_queue_patient (patient_id),
        KEY idx_queue_appointment (appointment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS clinic_queue_priority (
        queue_date DATE PRIMARY KEY,
        phase_type ENUM('walk_in','online') NOT NULL DEFAULT 'walk_in',
        phase_count INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS clinic_queue_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        queue_id INT NOT NULL,
        service_id INT DEFAULT NULL,
        service_name VARCHAR(255) NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        quantity INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_queue_services_queue (queue_id),
        KEY idx_queue_services_service (service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $statusResult = $conn->query("SHOW COLUMNS FROM clinic_queue LIKE 'status'");
    $statusColumn = $statusResult ? $statusResult->fetch_assoc() : null;
    if ($statusColumn && strpos((string) ($statusColumn['Type'] ?? ''), "'cancelled'") === false) {
        $conn->query("ALTER TABLE clinic_queue MODIFY COLUMN status ENUM('waiting','serving','completed','cancelled') NOT NULL DEFAULT 'waiting'");
    }
    $columnResult = $conn->query("SHOW COLUMNS FROM clinic_queue LIKE 'still_waiting_at'");
    if (!$columnResult || $columnResult->num_rows === 0) {
        $conn->query("ALTER TABLE clinic_queue ADD COLUMN still_waiting_at DATETIME DEFAULT NULL AFTER time_called");
    }
    $priorityResult = $conn->query("SHOW COLUMNS FROM clinic_queue LIKE 'priority_type'");
    if (!$priorityResult || $priorityResult->num_rows === 0) {
        $conn->query("ALTER TABLE clinic_queue ADD COLUMN priority_type VARCHAR(100) NOT NULL DEFAULT '' AFTER service");
    } else {
        $priorityColumn = $priorityResult->fetch_assoc();
        if (stripos((string) ($priorityColumn['Type'] ?? ''), 'varchar') === false) {
            $conn->query("ALTER TABLE clinic_queue MODIFY COLUMN priority_type VARCHAR(100) NOT NULL DEFAULT ''");
        }
    }
    $conn->query("UPDATE clinic_queue SET priority_type = '' WHERE LOWER(TRIM(priority_type)) IN ('standard', 'special')");
    $serviceTotalResult = $conn->query("SHOW COLUMNS FROM clinic_queue LIKE 'service_total'");
    if (!$serviceTotalResult || $serviceTotalResult->num_rows === 0) {
        $conn->query("ALTER TABLE clinic_queue ADD COLUMN service_total DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER service");
    }
    $serviceColumnResult = $conn->query("SHOW COLUMNS FROM clinic_queue LIKE 'service'");
    $serviceColumn = $serviceColumnResult ? $serviceColumnResult->fetch_assoc() : null;
    if ($serviceColumn && preg_match('/varchar\\((\\d+)\\)/i', (string) ($serviceColumn['Type'] ?? ''), $serviceLength) && (int) $serviceLength[1] < 255) {
        $conn->query("ALTER TABLE clinic_queue MODIFY COLUMN service VARCHAR(255) NOT NULL DEFAULT 'General Consultation'");
    }
    $conn->query("UPDATE clinic_queue SET still_waiting_at = time_called
                  WHERE status = 'waiting' AND time_called IS NOT NULL AND still_waiting_at IS NULL");
}

function queue_count_query(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if ($result && ($row = $result->fetch_assoc())) {
        return (int) ($row['total'] ?? 0);
    }
    return 0;
}

function queue_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('h:i A', $stamp) : '--';
}

function queue_booking_label(?string $type): string {
    return [
        'consultation' => 'Doctor consultation',
        'package' => 'Laboratory package',
        'individual' => 'Laboratory tests',
        'ultrasound' => 'Ultra sound',
    ][(string) $type] ?? 'General Consultation';
}

function queue_services_text(array $appointment): string {
    $notes = trim((string) ($appointment['notes'] ?? ''));
    $bookingType = (string) ($appointment['booking_type'] ?? '');
    if ($bookingType === 'consultation') {
        return 'General Consultation';
    }
    if ($bookingType === 'ultrasound') {
        return 'Ultra sound';
    }
    if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notes, $matches)) {
        $services = trim((string) ($matches[1] ?? ''));
        if ($services !== '') {
            return $services;
        }
    }
    return queue_booking_label($bookingType);
}

function queue_next_number(mysqli $conn, string $type, string $date): string {
    $prefix = $type === 'online' ? 'O' : 'W';
    $stmt = $conn->prepare("SELECT queue_number FROM clinic_queue WHERE queue_date = ? AND queue_type = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ss', $date, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if ($row && preg_match('/-(\d+)$/', (string) $row['queue_number'], $matches)) {
        $next = (int) $matches[1] + 1;
    }
    return $prefix . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

function queue_type_label(?string $type): string {
    return (string) $type === 'online' ? 'Online' : 'Walk-in';
}

function queue_priority_type_options(): array {
    return ['senior', 'pregnant', 'pwd'];
}

function queue_priority_types(?string $priority): array {
    $rawValues = preg_split('/[,|]+/', strtolower(trim((string) $priority))) ?: [];
    $values = array_map('trim', $rawValues);
    return array_values(array_filter(queue_priority_type_options(), static function (string $option) use ($values): bool {
        return in_array($option, $values, true);
    }));
}

function queue_priority_types_from_input($priorityInput): array {
    $values = is_array($priorityInput) ? $priorityInput : [$priorityInput];
    return queue_priority_types(implode(',', array_map('strval', $values)));
}

function queue_priority_storage(array $priorityTypes): string {
    return implode(',', queue_priority_types(implode(',', $priorityTypes)));
}

function queue_priority_type_value(?string $priority): string {
    return implode(',', queue_priority_types($priority));
}

function queue_priority_type_label(?string $priority): string {
    $types = queue_priority_types($priority);
    return $types === [] ? '—' : implode(', ', array_map('strtoupper', $types));
}

function queue_priority_is_priority(?string $priority): bool {
    return queue_priority_types($priority) !== [];
}

function queue_priority_badges_markup(?string $priority): string {
    $types = queue_priority_types($priority);
    if ($types === []) {
        return '<span class="queue-priority-empty">—</span>';
    }
    $badges = [];
    foreach ($types as $type) {
        $badges[] = '<span class="badge priority-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return implode(' ', $badges);
}

function queue_status_label(?string $status): string {
    return [
        'waiting' => 'Waiting',
        'serving' => 'Now Serving',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][(string) $status] ?? 'Waiting';
}

function queue_priority_limit(string $type): int {
    return $type === 'online' ? 10 : 20;
}

function queue_priority_other_type(string $type): string {
    return $type === 'online' ? 'walk_in' : 'online';
}

function queue_priority_ensure_day(mysqli $conn, string $date): void {
    $stmt = $conn->prepare("INSERT IGNORE INTO clinic_queue_priority (queue_date, phase_type, phase_count) VALUES (?, 'walk_in', 0)");
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->close();
}

function queue_priority_update(mysqli $conn, string $date, string $type, int $count): void {
    $stmt = $conn->prepare("UPDATE clinic_queue_priority SET phase_type = ?, phase_count = ? WHERE queue_date = ?");
    $stmt->bind_param('sis', $type, $count, $date);
    $stmt->execute();
    $stmt->close();
}

function queue_priority_find_candidate(mysqli $conn, string $date, string $type, bool $freshOnly): ?array {
    $freshClause = $freshOnly ? ' AND time_called IS NULL' : '';
    $stmt = $conn->prepare("SELECT id, queue_type, queue_number, priority_type
        FROM clinic_queue
        WHERE queue_date = ?
          AND queue_type = ?
          AND status = 'waiting'{$freshClause}
        ORDER BY CASE WHEN TRIM(COALESCE(priority_type, '')) = '' THEN 1 ELSE 0 END ASC,
                 time_added ASC,
                 id ASC
        LIMIT 1");
    $stmt->bind_param('ss', $date, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function queue_priority_call_next(mysqli $conn, string $date, bool $allowStillWaiting): array {
    queue_priority_ensure_day($conn, $date);

    for ($pass = 0; $pass < ($allowStillWaiting ? 2 : 1); $pass++) {
        $freshOnly = $pass === 0;
        $stateStmt = $conn->prepare("SELECT phase_type, phase_count FROM clinic_queue_priority WHERE queue_date = ? FOR UPDATE");
        $stateStmt->bind_param('s', $date);
        $stateStmt->execute();
        $state = $stateStmt->get_result()->fetch_assoc() ?: ['phase_type' => 'walk_in', 'phase_count' => 0];
        $stateStmt->close();
        $phaseType = (string) ($state['phase_type'] ?? 'walk_in');
        $phaseCount = (int) ($state['phase_count'] ?? 0);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $limit = queue_priority_limit($phaseType);
            if ($phaseCount >= $limit) {
                $phaseType = queue_priority_other_type($phaseType);
                $phaseCount = 0;
                queue_priority_update($conn, $date, $phaseType, $phaseCount);
            }

            $candidate = queue_priority_find_candidate($conn, $date, $phaseType, $freshOnly);
            if ($candidate) {
                $queueId = (int) ($candidate['id'] ?? 0);
                $callStmt = $conn->prepare("UPDATE clinic_queue
                    SET status = 'serving', time_called = NOW(), still_waiting_at = NULL
                    WHERE id = ? AND status = 'waiting'" . ($freshOnly ? " AND time_called IS NULL" : ""));
                $callStmt->bind_param('i', $queueId);
                $callStmt->execute();
                $called = $callStmt->affected_rows > 0;
                $callStmt->close();
                if ($called) {
                    queue_priority_update($conn, $date, $phaseType, $phaseCount + 1);
                    return [
                        'ok' => true,
                        'queue_id' => $queueId,
                        'queue_type' => $phaseType,
                        'queue_number' => (string) ($candidate['queue_number'] ?? ''),
                    ];
                }
            }

            $phaseType = queue_priority_other_type($phaseType);
            $phaseCount = 0;
            queue_priority_update($conn, $date, $phaseType, $phaseCount);
        }
    }

    return ['ok' => false, 'queue_id' => 0, 'queue_type' => '', 'queue_number' => ''];
}

function queue_call_specific_type(mysqli $conn, string $date, string $type, bool $allowStillWaiting): array {
    $type = $type === 'online' ? 'online' : 'walk_in';
    for ($pass = 0; $pass < ($allowStillWaiting ? 2 : 1); $pass++) {
        $freshOnly = $pass === 0;
        $candidate = queue_priority_find_candidate($conn, $date, $type, $freshOnly);
        if (!$candidate) {
            continue;
        }

        $queueId = (int) ($candidate['id'] ?? 0);
        $callStmt = $conn->prepare("UPDATE clinic_queue
            SET status = 'serving', time_called = NOW(), still_waiting_at = NULL
            WHERE id = ? AND queue_date = ? AND queue_type = ? AND status = 'waiting'" . ($freshOnly ? " AND time_called IS NULL" : " AND time_called IS NOT NULL"));
        $callStmt->bind_param('iss', $queueId, $date, $type);
        $callStmt->execute();
        $called = $callStmt->affected_rows > 0;
        $callStmt->close();
        if ($called) {
            return [
                'ok' => true,
                'queue_id' => $queueId,
                'queue_type' => $type,
                'queue_number' => (string) ($candidate['queue_number'] ?? ''),
            ];
        }
    }

    return ['ok' => false, 'queue_id' => 0, 'queue_type' => $type, 'queue_number' => ''];
}

function queue_ultrasound_available(?string $date = null, ?string $time = null): bool {
    $date = $date ?: date('Y-m-d');
    $time = $time ?: date('H:i:s');
    $dayNumber = (int) date('N', strtotime($date));
    return in_array($dayNumber, [3, 6], true) && $time >= '08:30:00' && $time <= '16:00:00';
}

function queue_selected_service_available(mysqli $conn, string $service, string $date): bool {
    $baseService = trim($service);
    if (strcasecmp($baseService, 'Ultra sound') === 0 || strcasecmp($baseService, 'Ultrasound') === 0) {
        return queue_ultrasound_available($date);
    }
    if (stripos($baseService, 'Doctor consultation - ') === 0) {
        $doctorName = trim(substr($baseService, strlen('Doctor consultation - ')));
        if ($doctorName === '') {
            return false;
        }
        $doctorNameSql = dbUsersNameExpression();
        $doctorStmt = $conn->prepare("SELECT id, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' AND {$doctorNameSql} = ? LIMIT 1");
        $doctorStmt->bind_param('s', $doctorName);
        $doctorStmt->execute();
        $doctorRow = $doctorStmt->get_result()->fetch_assoc();
        $doctorStmt->close();
        return $doctorRow
            && (int) ($doctorRow['is_active'] ?? 1) === 1
            && doctor_time_matches_clinic_slot($conn, (int) $doctorRow['id'], $date, date('H:i:s'));
    }
    return strcasecmp($baseService, 'Doctor consultation') !== 0;
}

function queue_selected_lab_services(mysqli $conn, array $serviceIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $serviceIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $idList = implode(',', $ids);
    $result = $conn->query("SELECT id, name, opd_price FROM lab_services WHERE is_active = 1 AND is_package = 0 AND id IN ({$idList})");
    if (!$result) {
        return [];
    }

    $rowsById = [];
    while ($row = $result->fetch_assoc()) {
        $rowsById[(int) ($row['id'] ?? 0)] = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => trim((string) ($row['name'] ?? 'Clinic service')),
            'price' => (float) ($row['opd_price'] ?? 0),
        ];
    }

    $selected = [];
    foreach ($ids as $id) {
        if (isset($rowsById[$id])) {
            $selected[] = $rowsById[$id];
        }
    }
    return $selected;
}

function queue_insert_service_lines(mysqli $conn, int $queueId, array $serviceLines): bool {
    if ($queueId <= 0 || $serviceLines === []) {
        return true;
    }

    $stmt = $conn->prepare(
        'INSERT INTO clinic_queue_services (queue_id, service_id, service_name, unit_price, quantity)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        return false;
    }

    foreach ($serviceLines as $line) {
        $serviceId = !empty($line['id']) ? (int) $line['id'] : null;
        $serviceName = trim((string) ($line['name'] ?? ''));
        $unitPrice = (float) ($line['price'] ?? 0);
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        if ($serviceName === '' || $unitPrice <= 0) {
            $stmt->close();
            return false;
        }
        $stmt->bind_param('iisdi', $queueId, $serviceId, $serviceName, $unitPrice, $quantity);
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }
    }

    $stmt->close();
    return true;
}

$conn = getDBConnection();
queue_init_schema($conn);
init_doctor_schema_and_accounts($conn);

$message = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
$isQueueAvailabilityError = $error !== '' && stripos($error, 'No waiting ') === 0;
$queueVoiceNumber = (string) ($_SESSION['queue_voice_number'] ?? '');
$queueVoiceType = (string) ($_SESSION['queue_voice_type'] ?? '');
$shouldPlayQueueVoice = $message !== ''
    && preg_match('/patient is now serving/i', $message) === 1
    && $queueVoiceNumber !== '';
unset($_SESSION['success'], $_SESSION['error'], $_SESSION['queue_voice_number'], $_SESSION['queue_voice_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['queue_action'])) {
    $queueAction = (string) $_POST['queue_action'];

    if ($queueAction === 'add') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
        $queueType = (string) ($_POST['queue_type'] ?? 'walk_in');
        $queueType = $queueType === 'online' ? 'online' : 'walk_in';
        $priorityType = queue_priority_storage(queue_priority_types_from_input($_POST['priority_types'] ?? []));
        $serviceCategory = trim((string) ($_POST['service_category'] ?? ''));
        $laboratoryServiceIds = is_array($_POST['laboratory_service_ids'] ?? null) ? $_POST['laboratory_service_ids'] : [];
        $selectedLabServices = $serviceCategory === 'Laboratory Tests'
            ? queue_selected_lab_services($conn, $laboratoryServiceIds)
            : [];
        $service = trim((string) ($_POST['service'] ?? ''));
        $service = $service !== '' ? $service : 'General Consultation';
        $serviceTotal = 0.0;
        $queueServiceLines = [];
        if ($serviceCategory === 'Laboratory Tests') {
            $requestedLabIds = array_values(array_unique(array_filter(array_map('intval', $laboratoryServiceIds), static fn(int $id): bool => $id > 0)));
            if ($requestedLabIds === [] || count($selectedLabServices) !== count($requestedLabIds)) {
                $_SESSION['error'] = 'Select at least one valid laboratory test.';
                header('Location: admin_queue.php');
                exit();
            }
            $service = implode(', ', array_column($selectedLabServices, 'name'));
            $serviceTotal = array_sum(array_map(static fn(array $item): float => (float) $item['price'], $selectedLabServices));
            $queueServiceLines = array_map(static fn(array $item): array => [
                'id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'price' => (float) $item['price'],
                'quantity' => 1,
            ], $selectedLabServices);
            $service .= ' - Total: PHP ' . number_format($serviceTotal, 2, '.', '');
        } elseif ($serviceCategory === 'Laboratory Package') {
            $packageStmt = $conn->prepare("SELECT id, name, opd_price FROM lab_services WHERE name = ? AND is_active = 1 AND is_package = 1 LIMIT 1");
            $packageStmt->bind_param('s', $service);
            $packageStmt->execute();
            $packageRow = $packageStmt->get_result()->fetch_assoc();
            $packageStmt->close();
            if ($packageRow) {
                $service = trim((string) ($packageRow['name'] ?? $service));
                $serviceTotal = (float) ($packageRow['opd_price'] ?? 0);
                $queueServiceLines = [[
                    'id' => (int) ($packageRow['id'] ?? 0),
                    'name' => $service,
                    'price' => $serviceTotal,
                    'quantity' => 1,
                ]];
                $service .= ' - Total: PHP ' . number_format($serviceTotal, 2, '.', '');
            } else {
                $_SESSION['error'] = 'Select a valid laboratory package.';
                header('Location: admin_queue.php');
                exit();
            }
        }
        $serviceIsAvailable = queue_selected_service_available($conn, $service, $today);
        $queueNotes = trim((string) ($_POST['queue_notes'] ?? ''));
        if ($queueNotes !== '') {
            $service .= ' - ' . $queueNotes;
        }
        if ($appointmentId > 0) {
            $queueType = 'online';
        }

        $patientStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
        $patientStmt->bind_param('i', $patientId);
        $patientStmt->execute();
        $patientExists = (bool) $patientStmt->get_result()->fetch_assoc();
        $patientStmt->close();

        if (!$serviceIsAvailable) {
            $_SESSION['error'] = 'Selected service is not available right now.';
        } elseif (!$patientExists) {
            $_SESSION['error'] = 'Select a valid patient before adding to queue.';
        } else {
            $duplicateStmt = $conn->prepare("SELECT id FROM clinic_queue WHERE queue_date = ? AND patient_id = ? AND status IN ('waiting','serving') LIMIT 1");
            $duplicateStmt->bind_param('si', $today, $patientId);
            $duplicateStmt->execute();
            $alreadyQueued = (bool) $duplicateStmt->get_result()->fetch_assoc();
            $duplicateStmt->close();

            if ($alreadyQueued) {
                $_SESSION['error'] = 'Patient is already in the active queue.';
            } else {
                $queueNumber = queue_next_number($conn, $queueType, $today);
                $appointmentValue = $appointmentId > 0 ? $appointmentId : null;
                $insertStmt = $conn->prepare("INSERT INTO clinic_queue (queue_date, queue_type, queue_number, patient_id, appointment_id, service, service_total, priority_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'waiting')");
                $insertStmt->bind_param('sssiisds', $today, $queueType, $queueNumber, $patientId, $appointmentValue, $service, $serviceTotal, $priorityType);
                $queueInserted = $insertStmt->execute() && $insertStmt->affected_rows > 0;
                $queueId = $queueInserted ? (int) $conn->insert_id : 0;
                $linesInserted = $queueInserted && queue_insert_service_lines($conn, $queueId, $queueServiceLines);
                if ($queueInserted && !$linesInserted) {
                    $deleteStmt = $conn->prepare('DELETE FROM clinic_queue WHERE id = ?');
                    if ($deleteStmt) {
                        $deleteStmt->bind_param('i', $queueId);
                        $deleteStmt->execute();
                        $deleteStmt->close();
                    }
                }
                $_SESSION[($queueInserted && $linesInserted) ? 'success' : 'error'] = ($queueInserted && $linesInserted)
                    ? 'Queue number ' . $queueNumber . ' added.'
                    : 'Could not add patient to queue.';
                $insertStmt->close();
            }
        }
        header('Location: admin_queue.php');
        exit();
    }

    if ($queueAction === 'call_next') {
        $safeToday = $conn->real_escape_string($today);
        $servingCount = queue_count_query($conn, "SELECT COUNT(*) AS total FROM clinic_queue WHERE queue_date = '{$safeToday}' AND status = 'serving'");
        if ($servingCount > 0) {
            $_SESSION['error'] = 'Finish the current patient before calling next.';
        } else {
            $conn->begin_transaction();
            $nextResult = queue_priority_call_next($conn, $today, true);
            if (!$nextResult['ok']) {
                $conn->rollback();
                $_SESSION['error'] = 'No other waiting patient is available. The Waiting queue remains below.';
            } else {
                $conn->commit();
                $_SESSION['queue_voice_number'] = (string) ($nextResult['queue_number'] ?? '');
                $_SESSION['queue_voice_type'] = (string) ($nextResult['queue_type'] ?? '');
                $_SESSION['success'] = 'Next ' . queue_type_label($nextResult['queue_type']) . ' patient is now serving.';
            }
        }
        header('Location: admin_queue.php');
        exit();
    }

    if ($queueAction === 'call_next_type') {
        $queueType = (string) ($_POST['queue_type'] ?? 'walk_in');
        $queueType = $queueType === 'online' ? 'online' : 'walk_in';
        $safeToday = $conn->real_escape_string($today);
        $safeType = $conn->real_escape_string($queueType);
        $servingCount = queue_count_query($conn, "SELECT COUNT(*) AS total FROM clinic_queue WHERE queue_date = '{$safeToday}' AND queue_type = '{$safeType}' AND status = 'serving'");
        if ($servingCount > 0) {
            $_SESSION['error'] = 'Finish the current ' . queue_type_label($queueType) . ' patient before calling next.';
        } else {
            $conn->begin_transaction();
            $nextResult = queue_call_specific_type($conn, $today, $queueType, true);
            if (!$nextResult['ok']) {
                $conn->rollback();
                $_SESSION['error'] = 'No waiting ' . queue_type_label($queueType) . ' patient is available.';
            } else {
                $conn->commit();
                $_SESSION['queue_voice_number'] = (string) ($nextResult['queue_number'] ?? '');
                $_SESSION['queue_voice_type'] = (string) ($nextResult['queue_type'] ?? '');
                $_SESSION['success'] = 'Next ' . queue_type_label($queueType) . ' patient is now serving.';
            }
        }
        header('Location: admin_queue.php');
        exit();
    }

    if ($queueAction === 'call_patient') {
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $conn->begin_transaction();

        $selectedStmt = $conn->prepare("SELECT id, queue_type, queue_number FROM clinic_queue WHERE id = ? AND queue_date = ? AND status = 'waiting' LIMIT 1 FOR UPDATE");
        $selectedStmt->bind_param('is', $queueId, $today);
        $selectedStmt->execute();
        $selectedRow = $selectedStmt->get_result()->fetch_assoc();
        $selectedStmt->close();

        if (!$selectedRow) {
            $conn->rollback();
            $_SESSION['error'] = 'That queue patient is no longer waiting.';
        } else {
            $queueType = (string) ($selectedRow['queue_type'] ?? 'walk_in');
            $activeStmt = $conn->prepare("UPDATE clinic_queue
                SET status = 'waiting', still_waiting_at = NOW()
                WHERE queue_date = ? AND queue_type = ? AND status = 'serving' AND id <> ?");
            $activeStmt->bind_param('ssi', $today, $queueType, $queueId);
            $activeStmt->execute();
            $activeStmt->close();

            $callStmt = $conn->prepare("UPDATE clinic_queue
                SET status = 'serving', time_called = NOW(), still_waiting_at = NULL
                WHERE id = ? AND queue_date = ? AND queue_type = ? AND status = 'waiting'");
            $callStmt->bind_param('iss', $queueId, $today, $queueType);
            $callStmt->execute();
            $called = $callStmt->affected_rows > 0;
            $callStmt->close();

            if (!$called) {
                $conn->rollback();
                $_SESSION['error'] = 'That queue patient could not be called.';
            } else {
                $conn->commit();
                $_SESSION['queue_voice_number'] = (string) ($selectedRow['queue_number'] ?? '');
                $_SESSION['queue_voice_type'] = $queueType;
                $_SESSION['success'] = 'Queue patient is now serving.';
            }
        }
        header('Location: admin_queue.php');
        exit();
    }

    if ($queueAction === 'cancel') {
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $cancelStmt = $conn->prepare("UPDATE clinic_queue SET status = 'cancelled' WHERE id = ? AND queue_date = ? AND status IN ('waiting','serving')");
        $cancelStmt->bind_param('is', $queueId, $today);
        $cancelStmt->execute();
        $_SESSION['success'] = $cancelStmt->affected_rows > 0
            ? 'Queue entry cancelled.'
            : 'That queue entry is no longer available to cancel.';
        $cancelStmt->close();
        header('Location: admin_queue.php');
        exit();
    }

    if ($queueAction === 'complete') {
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $conn->begin_transaction();

        $queueStmt = $conn->prepare("SELECT id, patient_id, appointment_id, queue_type
            FROM clinic_queue
            WHERE id = ? AND queue_date = ? AND status = 'serving'
            LIMIT 1 FOR UPDATE");
        $queueStmt->bind_param('is', $queueId, $today);
        $queueStmt->execute();
        $queueRow = $queueStmt->get_result()->fetch_assoc() ?: null;
        $queueStmt->close();

        $completionError = '';
        $appointmentWasUpdated = false;
        if (!$queueRow) {
            $completionError = 'No active queue item was completed.';
        } elseif ((string) ($queueRow['queue_type'] ?? '') === 'online') {
            $appointmentId = (int) ($queueRow['appointment_id'] ?? 0);
            $patientId = (int) ($queueRow['patient_id'] ?? 0);

            if ($appointmentId <= 0 || $patientId <= 0) {
                $completionError = 'The online queue item has no valid linked appointment.';
            } else {
                $appointmentStmt = $conn->prepare("SELECT id, patient_id, status
                    FROM appointments
                    WHERE id = ? AND patient_id = ?
                    LIMIT 1 FOR UPDATE");
                $appointmentStmt->bind_param('ii', $appointmentId, $patientId);
                $appointmentStmt->execute();
                $appointmentRow = $appointmentStmt->get_result()->fetch_assoc() ?: null;
                $appointmentStmt->close();

                if (!$appointmentRow) {
                    $completionError = 'The linked online appointment could not be found.';
                } elseif (!in_array(strtolower((string) ($appointmentRow['status'] ?? '')), ['pending', 'confirmed', 'completed'], true)) {
                    $completionError = 'The linked online appointment cannot be completed from its current status.';
                }
            }
        }

        if ($completionError === '') {
            $completeStmt = $conn->prepare("UPDATE clinic_queue
                SET status = 'completed', completed_at = NOW()
                WHERE id = ? AND queue_date = ? AND status = 'serving'");
            $completeStmt->bind_param('is', $queueId, $today);
            $completeStmt->execute();
            $queueCompleted = $completeStmt->affected_rows > 0;
            $completeStmt->close();

            if (!$queueCompleted) {
                $completionError = 'No active queue item was completed.';
            } elseif ((string) ($queueRow['queue_type'] ?? '') === 'online') {
                $appointmentId = (int) ($queueRow['appointment_id'] ?? 0);
                $patientId = (int) ($queueRow['patient_id'] ?? 0);
                $appointmentStatus = strtolower((string) ($appointmentRow['status'] ?? ''));
                if ($appointmentStatus !== 'completed') {
                    $appointmentUpdateStmt = $conn->prepare("UPDATE appointments
                        SET status = 'completed'
                        WHERE id = ? AND patient_id = ? AND status IN ('pending', 'confirmed')");
                    $appointmentUpdateStmt->bind_param('ii', $appointmentId, $patientId);
                    $appointmentUpdateStmt->execute();
                    $appointmentWasUpdated = $appointmentUpdateStmt->affected_rows > 0;
                    $appointmentUpdateStmt->close();
                    if (!$appointmentWasUpdated) {
                        $completionError = 'The linked online appointment could not be marked as completed.';
                    }
                }
            }
        }

        if ($completionError !== '') {
            $conn->rollback();
            $_SESSION['error'] = $completionError;
        } else {
            $conn->commit();
            $_SESSION['success'] = (string) ($queueRow['queue_type'] ?? '') === 'online'
                ? ($appointmentWasUpdated
                    ? 'Queue completed and online appointment marked as completed.'
                    : 'Queue completed. The online appointment was already completed.')
                : 'Queue completed.';
        }
        header('Location: admin_queue.php');
        exit();
    }

    if ($queueAction === 'call_again') {
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $typeStmt = $conn->prepare("SELECT queue_type FROM clinic_queue WHERE id = ? AND queue_date = ? AND status = 'serving' LIMIT 1");
        $typeStmt->bind_param('is', $queueId, $today);
        $typeStmt->execute();
        $servingRow = $typeStmt->get_result()->fetch_assoc();
        $typeStmt->close();
        $queueType = (string) ($servingRow['queue_type'] ?? '');
        $conn->begin_transaction();
        $callAgainStmt = $conn->prepare("UPDATE clinic_queue SET status = 'waiting', still_waiting_at = NOW() WHERE id = ? AND queue_date = ? AND status = 'serving'");
        $callAgainStmt->bind_param('is', $queueId, $today);
        $callAgainStmt->execute();
        $movedToWaiting = $callAgainStmt->affected_rows > 0;
        $callAgainStmt->close();

        if ($movedToWaiting && $queueType !== '') {
            $nextResult = queue_call_specific_type($conn, $today, $queueType, false);
            $nextWasCalled = $nextResult['ok'];
        } else {
            $nextWasCalled = false;
        }

        if ($movedToWaiting) {
            $conn->commit();
            if ($nextWasCalled) {
                $_SESSION['queue_voice_number'] = (string) ($nextResult['queue_number'] ?? '');
                $_SESSION['queue_voice_type'] = (string) ($nextResult['queue_type'] ?? '');
            }
            $_SESSION['success'] = $nextWasCalled
                ? 'Patient moved to still waiting. The next queue patient is now serving.'
                : 'Patient moved to still waiting. There is no other waiting patient to call next.';
        } else {
            $conn->rollback();
            $_SESSION['error'] = 'No active queue item was returned to the waiting queue.';
        }
        header('Location: admin_queue.php');
        exit();
    }
}

$queuePatients = [];
$patientNameSql = dbUsersNameExpression();
$patientResult = $conn->query("SELECT id, {$patientNameSql} AS full_name, phone, email FROM users WHERE role = 'patient' AND COALESCE(is_active, 1) = 1 ORDER BY {$patientNameSql} ASC LIMIT 300");
if ($patientResult) {
    $queuePatients = $patientResult->fetch_all(MYSQLI_ASSOC);
}

$queueServiceCatalog = [];

$doctorNameSql = dbUsersNameExpression();
$doctorResult = $conn->query("SELECT id, {$doctorNameSql} AS full_name, specialty, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY {$doctorNameSql} ASC");
if ($doctorResult) {
    while ($doctorRow = $doctorResult->fetch_assoc()) {
        $doctorId = (int) ($doctorRow['id'] ?? 0);
        $doctorName = trim((string) ($doctorRow['full_name'] ?? 'Doctor'));
        $doctorSpecialty = trim((string) ($doctorRow['specialty'] ?? ''));
        $isAvailable = (int) ($doctorRow['is_active'] ?? 1) === 1 && doctor_time_matches_clinic_slot($conn, $doctorId, $today, date('H:i:s'));
        if (!$isAvailable) {
            continue;
        }
        $doctorLabel = $doctorName . ($doctorSpecialty !== '' ? ' - ' . $doctorSpecialty : '');
        $queueServiceCatalog[] = [
            'category' => 'Consultation',
            'subcategory' => $doctorLabel,
            'service' => 'Doctor consultation - ' . $doctorName,
            'mode' => 'doctor',
        ];
    }
}

if (queue_ultrasound_available($today)) {
    $queueServiceCatalog[] = [
        'category' => 'Ultra sound',
        'subcategory' => '',
        'service' => 'Ultra sound',
        'mode' => 'category_only',
    ];
}
$serviceResult = $conn->query("SELECT id, name, category, opd_price, is_package FROM lab_services WHERE is_active = 1 ORDER BY is_package DESC, category ASC, name ASC");
if ($serviceResult) {
    while ($serviceRow = $serviceResult->fetch_assoc()) {
        $queueServiceCatalog[] = [
            'id' => (int) ($serviceRow['id'] ?? 0),
            'category' => !empty($serviceRow['is_package']) ? 'Laboratory Package' : 'Laboratory Tests',
            'subcategory' => trim((string) ($serviceRow['category'] ?? 'Other services')) ?: 'Other services',
            'service' => trim((string) ($serviceRow['name'] ?? 'Clinic service')) ?: 'Clinic service',
            'price' => (float) ($serviceRow['opd_price'] ?? 0),
            'mode' => 'full',
        ];
    }
}

$queueAppointments = [];
$patientNameSql = dbUsersNameExpression('p');
$queueAppointmentStmt = $conn->prepare("SELECT a.id, a.patient_id, a.appointment_time, a.booking_type, a.notes, {$patientNameSql} AS patient_name
    FROM appointments a
    JOIN users p ON p.id = a.patient_id
    LEFT JOIN clinic_queue q ON q.appointment_id = a.id AND q.queue_date = ?
    WHERE a.appointment_date = ?
      AND a.status = 'confirmed'
      AND q.id IS NULL
    ORDER BY a.appointment_time ASC, {$patientNameSql} ASC");
$queueAppointmentStmt->bind_param('ss', $today, $today);
$queueAppointmentStmt->execute();
$queueAppointments = $queueAppointmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$queueAppointmentStmt->close();

$queueRows = [];
$queueStmt = $conn->prepare("SELECT q.*, {$patientNameSql} AS patient_name, p.phone AS patient_phone, p.email AS patient_email
    FROM clinic_queue q
    JOIN users p ON p.id = q.patient_id
    WHERE q.queue_date = ?
    ORDER BY CASE q.status WHEN 'serving' THEN 0 WHEN 'waiting' THEN 1 ELSE 2 END,
             CASE WHEN q.status = 'waiting' AND q.time_called IS NULL THEN 0 ELSE 1 END,
             CASE WHEN q.queue_type = 'walk_in' THEN 0 ELSE 1 END,
             CASE WHEN TRIM(COALESCE(q.priority_type, '')) = '' THEN 1 ELSE 0 END,
             q.time_added ASC,
             q.id ASC");
$queueStmt->bind_param('s', $today);
$queueStmt->execute();
$queueRows = $queueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$queueStmt->close();

$walkInQueue = [];
$onlineQueue = [];
$stillWaitingQueue = [];
$completedQueue = [];
$nowServingWalkIn = null;
$nowServingOnline = null;
foreach ($queueRows as $queueRow) {
    if (($queueRow['status'] ?? '') === 'serving') {
        if (($queueRow['queue_type'] ?? '') === 'online') {
            $nowServingOnline = $queueRow;
        } else {
            $nowServingWalkIn = $queueRow;
        }
    }
    if (($queueRow['status'] ?? '') === 'cancelled') {
        continue;
    }
    if (($queueRow['status'] ?? '') === 'completed') {
        $completedQueue[] = $queueRow;
        continue;
    }
    if (($queueRow['status'] ?? '') === 'waiting' && !empty($queueRow['time_called'])) {
        $stillWaitingQueue[] = $queueRow;
        continue;
    }
    if (($queueRow['queue_type'] ?? '') === 'online') {
        $onlineQueue[] = $queueRow;
    } else {
        $walkInQueue[] = $queueRow;
    }
}
usort($stillWaitingQueue, static function (array $left, array $right): int {
    $leftPriority = queue_priority_is_priority($left['priority_type'] ?? '') ? 0 : 1;
    $rightPriority = queue_priority_is_priority($right['priority_type'] ?? '') ? 0 : 1;
    if ($leftPriority !== $rightPriority) {
        return $leftPriority <=> $rightPriority;
    }
    $leftTimeAdded = (string) ($left['time_added'] ?? '9999-12-31 23:59:59');
    $rightTimeAdded = (string) ($right['time_added'] ?? '9999-12-31 23:59:59');
    $leftStillWaiting = (string) (($left['still_waiting_at'] ?? '') ?: ($left['time_called'] ?? '9999-12-31 23:59:59'));
    $rightStillWaiting = (string) (($right['still_waiting_at'] ?? '') ?: ($right['time_called'] ?? '9999-12-31 23:59:59'));
    return [$leftTimeAdded, $leftStillWaiting, (int) ($left['id'] ?? 0)] <=> [$rightTimeAdded, $rightStillWaiting, (int) ($right['id'] ?? 0)];
});
$walkInWaitingCount = count(array_filter($walkInQueue, static fn($row) => ($row['status'] ?? '') === 'waiting'));
$onlineWaitingCount = count(array_filter($onlineQueue, static fn($row) => ($row['status'] ?? '') === 'waiting'));
$stillWaitingCount = count($stillWaitingQueue);

$conn->close();

$additionalStyles = '
body { background:#f4f9fd; color:#10233f; }
.queue-page { max-width:1280px; margin:0 auto; padding:26px 22px 44px; }
.queue-alert { border-radius:8px; padding:14px 16px; margin-bottom:14px; font-weight:700; }
.queue-alert.success { background:#e2f7e9; color:#08743e; border:1px solid #bfe9cc; }
.queue-alert.error { background:#fff0f0; color:#9d1c2c; border:1px solid #ffd0d5; }
.queue-shell { border:1px solid #e4edf2; border-radius:12px; background:#fff; box-shadow:0 10px 24px rgba(25,76,110,.05); padding:22px 24px 20px; }
.queue-tools { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; }
.queue-priority-note { margin:0 0 16px; color:#466779; font-size:.84rem; font-weight:700; line-height:1.45; }
.queue-add-button { display:flex; align-items:center; justify-content:center; gap:12px; min-height:56px; border:0; border-radius:8px; background:#0f7cc2; color:#fff; cursor:pointer; font-size:1rem; font-weight:700; box-shadow:0 10px 20px rgba(15,124,194,.16); }
.queue-add-button svg { width:25px; height:25px; fill:none; stroke:currentColor; stroke-width:2.4; stroke-linecap:round; stroke-linejoin:round; }
.queue-form-grid { display:grid; gap:10px; }
.queue-form-grid label { display:grid; gap:5px; color:#1a3342; font-size:.875rem; font-weight:700; }
.queue-priority-field { display:grid; gap:7px; color:#1a3342; font-size:.875rem; font-weight:700; }
.queue-priority-options { display:grid; grid-template-columns:1fr; gap:5px; }
.queue-form-grid .queue-priority-option { display:inline-flex; align-items:center; justify-content:flex-start; gap:7px; width:max-content; min-height:22px; border:0; border-radius:0; padding:0; background:transparent; color:#1a3342; cursor:pointer; font-size:.875rem; font-weight:500; }
.queue-form-grid .queue-priority-option input { width:16px; height:16px; min-height:16px; margin:0; padding:0; border:0; accent-color:#0f7cc2; }
.queue-form-grid input, .queue-form-grid select { width:100%; min-height:42px; border:1px solid #dce8ef; border-radius:8px; padding:9px 12px; color:#1a3342; background:#fff; font:inherit; font-size:.875rem; font-weight:500; }
.queue-form-grid textarea { width:100%; min-height:66px; resize:vertical; border:1px solid #cfe1ee; border-radius:8px; padding:10px 11px; color:#1f343d; background:#fff; font:inherit; }
.queue-service-cascade { display:grid; grid-template-columns:minmax(0,.9fr) minmax(0,1fr) minmax(0,1.25fr); gap:10px; align-items:end; }
.queue-service-cascade label[hidden] { display:none !important; }
.queue-service-price { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:8px; padding:8px 10px; border:1px solid #cfe1ee; border-radius:7px; background:#f8fcff; color:#60758a; font-size:.8rem; font-weight:700; }
.queue-service-price strong { color:#0b4f80; font-size:.9rem; white-space:nowrap; }
.queue-service-add { justify-self:start; min-height:34px; padding:6px 10px; font-size:.78rem; }
.queue-lab-selection { display:grid; gap:6px; padding:9px 10px; border:1px solid #cfe1ee; border-radius:7px; background:#fff; }
.queue-lab-selection-title { color:#60758a; font-size:.78rem; font-weight:800; }
.queue-lab-selected-list { display:grid; gap:5px; }
.queue-lab-selected-item { display:grid; grid-template-columns:minmax(0,1fr) auto auto; align-items:center; gap:8px; color:#1a3342; font-size:.8rem; }
.queue-lab-selected-item span { overflow-wrap:anywhere; }
.queue-lab-selected-item strong { color:#0b4f80; white-space:nowrap; }
.queue-lab-remove { border:0; padding:2px 0; background:transparent; color:#b4232d; cursor:pointer; font:inherit; font-size:.72rem; font-weight:800; }
.queue-lab-total { display:flex; justify-content:space-between; gap:10px; padding-top:6px; border-top:1px solid #e5eef4; color:#1a3342; font-size:.82rem; font-weight:900; }
.queue-lab-total strong { color:#0b4f80; white-space:nowrap; }
.queue-layout { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:14px; align-items:start; }
.queue-sections { display:grid; grid-template-columns:1fr; gap:14px; }
.queue-card, .now-serving-card { border:1px solid #dce8ef; border-radius:8px; overflow:hidden; background:#fff; }
.queue-card h2, .now-serving-card h2 { margin:0; padding:14px 16px; background:#eaf7ff; color:#073b4c; font-size:1.05rem; font-weight:700; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.queue-title-text { display:inline-flex; align-items:center; gap:9px; }
.queue-title-text svg { width:26px; height:26px; fill:none; stroke:#0d6bed; stroke-width:2.4; stroke-linecap:round; stroke-linejoin:round; }
.queue-count-pill { border-radius:8px; padding:7px 10px; background:#dcefff; color:#0066cc; font-size:.78rem; font-weight:700; }
.queue-table-wrap { overflow:auto; }
.queue-table { width:100%; min-width:520px; border-collapse:collapse; }
.queue-table th, .queue-table td { padding:14px; border-bottom:1px solid #eef3f6; text-align:left; color:#10233f; font-size:.875rem; font-weight:500; }
.queue-table th { background:#fff; color:#708792; font-size:.72rem; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
.queue-table tr:last-child td { border-bottom:0; }
.queue-number { color:#10233f; font-weight:700; }
.queue-sections .queue-table-wrap { overflow-x:hidden; }
.queue-sections .queue-table { min-width:0; table-layout:fixed; }
.queue-sections .queue-table th, .queue-sections .queue-table td { padding:14px; font-size:.875rem; white-space:normal; overflow-wrap:anywhere; }
.queue-sections .queue-table th:nth-child(1), .queue-sections .queue-table td:nth-child(1) { width:4%; }
.queue-sections .queue-table th:nth-child(2), .queue-sections .queue-table td:nth-child(2) { width:10%; }
.queue-sections .queue-table th:nth-child(3), .queue-sections .queue-table td:nth-child(3) { width:19%; }
.queue-sections .queue-table th:nth-child(4), .queue-sections .queue-table td:nth-child(4) { width:23%; }
.queue-sections .queue-table th:nth-child(5), .queue-sections .queue-table td:nth-child(5) { width:14%; }
.queue-sections .queue-table th:nth-child(6), .queue-sections .queue-table td:nth-child(6) { width:12%; }
.queue-sections .queue-table th:nth-child(7), .queue-sections .queue-table td:nth-child(7) { width:9%; }
.queue-sections .queue-table th:nth-child(8), .queue-sections .queue-table td:nth-child(8) { width:9%; }
.queue-sections .still-waiting-table th:nth-child(1), .queue-sections .still-waiting-table td:nth-child(1) { width:4%; }
.queue-sections .still-waiting-table th:nth-child(2), .queue-sections .still-waiting-table td:nth-child(2) { width:9%; }
.queue-sections .still-waiting-table th:nth-child(3), .queue-sections .still-waiting-table td:nth-child(3) { width:15%; }
.queue-sections .still-waiting-table th:nth-child(4), .queue-sections .still-waiting-table td:nth-child(4) { width:18%; }
.queue-sections .still-waiting-table th:nth-child(5), .queue-sections .still-waiting-table td:nth-child(5) { width:12%; }
.queue-sections .still-waiting-table th:nth-child(6), .queue-sections .still-waiting-table td:nth-child(6) { width:9%; }
.queue-sections .still-waiting-table th:nth-child(7), .queue-sections .still-waiting-table td:nth-child(7) { width:10%; }
.queue-sections .still-waiting-table th:nth-child(8), .queue-sections .still-waiting-table td:nth-child(8) { width:10%; }
.queue-sections .still-waiting-table th:nth-child(9), .queue-sections .still-waiting-table td:nth-child(9) { width:13%; }
.queue-sections .still-waiting-table-wrap { overflow:visible; padding-bottom:0; }
.queue-sections .still-waiting-table { width:100%; min-width:0; table-layout:fixed; }
.queue-sections .still-waiting-table th:nth-child(8), .queue-sections .still-waiting-table th:nth-child(9) { white-space:nowrap; }
.queue-sections .still-waiting-table td:nth-child(8), .queue-sections .still-waiting-table td:nth-child(9) { text-align:center; }
.queue-sections .still-waiting-table .badge { padding:3px 5px; font-size:.61rem; letter-spacing:.03em; white-space:nowrap; }
.queue-sections .still-waiting-table .queue-row-actions .btn { min-width:48px; padding:5px 7px; font-size:.7rem; }
.queue-sections .queue-table:not(.still-waiting-table) th:nth-child(7), .queue-sections .queue-table:not(.still-waiting-table) td:nth-child(7) { width:10%; min-width:74px; padding-right:6px; padding-left:6px; text-align:center; white-space:nowrap; }
.queue-sections .queue-table:not(.still-waiting-table) th:nth-child(8), .queue-sections .queue-table:not(.still-waiting-table) td:nth-child(8) { width:10%; min-width:74px; padding-right:6px; padding-left:6px; text-align:center; white-space:nowrap; }
.queue-sections .still-waiting-table th:nth-child(8), .queue-sections .still-waiting-table td:nth-child(8) { width:10%; min-width:74px; padding-right:6px; padding-left:6px; text-align:center; }
.queue-sections .still-waiting-table th:nth-child(9), .queue-sections .still-waiting-table td:nth-child(9) { width:11%; min-width:74px; padding-right:6px; padding-left:6px; text-align:center; white-space:nowrap; }
.queue-sections .queue-table:not(.still-waiting-table) td:nth-child(7) .badge, .queue-sections .still-waiting-table td:nth-child(8) .badge { padding:3px 7px; font-size:.64rem; white-space:nowrap; }
.queue-table th:last-child, .queue-table td:last-child { text-align:center; }
.queue-row-actions { display:flex; align-items:center; justify-content:center; width:100%; min-height:34px; gap:6px; }
.queue-row-actions form { display:flex; align-items:center; justify-content:center; width:100%; }
.queue-row-actions .btn { width:58px; min-width:58px; min-height:32px; padding:5px 8px; font-size:.74rem; white-space:nowrap; }
.queue-sections .still-waiting-table .queue-row-actions .btn { width:58px; min-width:58px; }
.queue-sections .still-waiting-table th:last-child, .queue-sections .still-waiting-table td:last-child { padding-right:20px; }
.queue-sections .still-waiting-table td:last-child .badge { max-width:100%; white-space:normal; justify-content:center; text-align:center; line-height:1.1; }
.queue-sections .badge { white-space:nowrap; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(3), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(3) { width:21%; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(4), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(4) { width:24%; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(5), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(5) { width:12%; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(8), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(8) { width:auto; min-width:0; padding-left:7px; padding-right:7px; text-align:center; vertical-align:middle; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(8) { white-space:nowrap; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(8) { white-space:nowrap; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(6) .badge, .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(7) .badge { padding:3px 5px; font-size:.59rem; letter-spacing:.025em; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) .queue-row-actions { min-height:28px; gap:4px; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) .queue-row-actions .btn { width:54px; min-width:54px; min-height:29px; padding:4px 5px; font-size:.68rem; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) .queue-row-current { min-height:28px; font-size:.61rem; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(6) { width:10%; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(7) { width:9%; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(8), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(8) { width:10%; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(8) { font-size:.66rem; letter-spacing:.025em; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(8), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(8) { border-left:1px solid #eef3f6; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th, .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td { width:12.5%; padding-left:8px; padding-right:8px; vertical-align:middle; }
.queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(1), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(1), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(2), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(2), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(3), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(3), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(4), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(4), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(5), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(5), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(6), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(7), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) th:nth-child(8), .queue-sections .queue-table:is(.walk-in-queue-table, .online-queue-table) td:nth-child(8) { width:12.5%; }
.queue-pagination { display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:7px; padding:12px 14px 14px; border-top:1px solid #e6eef4; background:#fbfdff; }
.queue-page-btn { min-width:36px; min-height:36px; border:1px solid #dce8ef; border-radius:7px; padding:6px 10px; background:#fff; color:#0b4f80; cursor:pointer; font:inherit; font-size:.875rem; font-weight:800; }
.queue-page-btn:hover:not(:disabled) { border-color:#0f7cc2; color:#0066cc; }
.queue-page-btn.is-active { background:#0066cc; border-color:#0066cc; color:#fff; box-shadow:0 10px 20px rgba(0,102,204,.18); }
.queue-page-btn:disabled { cursor:not-allowed; opacity:.48; background:#eef7ff; color:#7890a1; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 10px; font-size:.68rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.badge.waiting { background:#fff0c9; color:#9a6500; }
.badge.serving { background:#eaf8ff; color:#0077b6; }
.badge.completed, .badge.online { background:#dff8e8; color:#08743e; }
.badge.walk_in { background:#dcefff; color:#0066cc; }
.badge.priority-senior, .badge.priority-pregnant, .badge.priority-pwd { background:#eee7ff; color:#5b3a91; }
.queue-priority-empty { color:#7890a1; font-weight:800; }
.queue-empty { padding:14px 16px; color:#60727d; font-weight:800; }
.queue-empty[data-pagination-empty] { display:none; border-top:1px solid #e6eef4; }
.queue-row-current { display:inline-flex; align-items:center; justify-content:center; min-height:32px; color:#168a45; font-size:.68rem; font-weight:800; white-space:nowrap; }
.now-serving-card { position:sticky; top:98px; }
.now-serving-stack { position:sticky; top:98px; display:grid; gap:14px; align-self:start; }
.now-serving-stack .now-serving-card { position:static; }
.now-serving-card h2 { justify-content:flex-start; }
.now-serving-card h2 svg { width:28px; height:28px; fill:#0b3b95; }
.now-serving-number { display:block; margin:12px 12px 0; padding:22px 12px 4px; border-radius:8px 8px 0 0; background:linear-gradient(135deg,#eef9ff 0%,#dff1ff 100%); color:#061b57; text-align:center; font-size:2.55rem; line-height:1; font-weight:950; }
.now-serving-name { display:block; margin:0 12px; padding:0 12px 18px; border-radius:0 0 8px 8px; background:linear-gradient(135deg,#eef9ff 0%,#dff1ff 100%); color:#061b57; text-align:center; font-size:1rem; font-weight:950; }
.now-serving-details { display:grid; gap:10px; margin:16px; color:#061b57; font-size:.86rem; font-weight:850; }
.now-serving-details span { display:flex; justify-content:space-between; gap:12px; }
.now-serving-details strong { color:#1f343d; }
.queue-actions { display:grid; gap:10px; padding:0 16px 16px; }
.queue-actions .btn, .now-serving-card > form .btn { width:100%; min-height:46px; border-radius:6px; }
.now-serving-card > form { padding:0 16px 16px; }
.btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; border:1px solid transparent; border-radius:8px; padding:8px 13px; background:#0d6bed; color:#fff; cursor:pointer; font-weight:900; text-decoration:none; }
.btn.success { background:#11a35b; }
.btn.secondary { background:#eef7ff; border-color:#d4e6f5; color:#0b4f80; }
.btn.decline { background:#fff0f0; border-color:#ffd0d5; color:#b4232d; }
.btn:disabled { cursor:not-allowed; opacity:.62; }
.queue-modal { position:fixed; inset:0; z-index:3000; display:none; align-items:center; justify-content:center; padding:20px; background:rgba(8,18,34,.48); backdrop-filter:blur(3px); }
.queue-modal.is-open { display:flex; }
.queue-modal-card { width:min(760px,100%); max-height:calc(100vh - 40px); overflow:auto; border-radius:8px; background:#fff; box-shadow:0 28px 70px rgba(4,21,48,.32); }
.queue-success-modal { position:fixed; inset:0; z-index:3200; display:none; align-items:center; justify-content:center; padding:20px; background:rgba(8,18,34,.42); backdrop-filter:blur(3px); }
.queue-success-modal.is-open { display:flex; }
.queue-success-card { width:min(430px,100%); border:1px solid #dce8ef; border-radius:12px; background:#fff; box-shadow:0 24px 60px rgba(4,21,48,.24); padding:28px; text-align:center; }
.queue-success-icon { display:inline-grid; place-items:center; width:58px; height:58px; margin-bottom:14px; border-radius:50%; background:#e6f6ec; color:#168a45; font-size:1.8rem; font-weight:800; }
.queue-success-card h2 { margin:0 0 8px; color:#073b4c; font-size:1.28rem; font-weight:700; }
.queue-success-card p { margin:0 0 20px; color:#60758a; font-size:.95rem; font-weight:500; line-height:1.45; }
.queue-success-card .btn { min-width:120px; background:#0f7cc2; border-color:#0f7cc2; font-weight:700; }
.queue-error-card .btn { min-width:120px; background:#0f7cc2; border-color:#0f7cc2; font-weight:700; }
.queue-error-icon { display:inline-grid; place-items:center; width:58px; height:58px; margin-bottom:14px; border-radius:50%; background:#fff3d6; color:#a36600; font-size:1.8rem; font-weight:900; }
.queue-confirm-modal { position:fixed; inset:0; z-index:3300; display:none; align-items:center; justify-content:center; padding:20px; background:rgba(8,18,34,.48); backdrop-filter:blur(3px); }
.queue-confirm-modal.is-open { display:flex; }
.queue-confirm-card { width:min(440px,100%); border:1px solid #dce8ef; border-radius:12px; background:#fff; box-shadow:0 24px 60px rgba(4,21,48,.24); padding:28px; text-align:center; }
.queue-confirm-icon { display:inline-grid; place-items:center; width:58px; height:58px; margin-bottom:14px; border-radius:50%; background:#fff3d6; color:#a36600; font-size:1.8rem; font-weight:900; }
.queue-confirm-card h2 { margin:0 0 8px; color:#073b4c; font-size:1.28rem; font-weight:700; }
.queue-confirm-card p { margin:0 0 20px; color:#60758a; font-size:.95rem; font-weight:500; line-height:1.45; }
.queue-confirm-actions { display:flex; justify-content:center; gap:10px; flex-wrap:wrap; }
.queue-confirm-actions .btn { min-width:150px; }
@media (max-width:520px) { .queue-confirm-actions { display:grid; } .queue-confirm-actions .btn { width:100%; } }
.queue-modal-head { display:grid; grid-template-columns:54px minmax(0,1fr) 34px; gap:14px; align-items:start; padding:18px 24px 14px; border-bottom:1px solid #d8e7f1; }
.queue-modal-icon { width:46px; height:46px; color:#0d6bed; }
.queue-modal-icon svg { width:100%; height:100%; fill:currentColor; }
.queue-modal-title h2 { margin:0; color:#061b57; font-size:1.18rem; font-weight:950; }
.queue-modal-title p { margin:5px 0 0; color:#061b57; font-size:.86rem; font-weight:650; line-height:1.35; }
.queue-modal-close { width:34px; height:34px; border:0; background:transparent; color:#7785a7; cursor:pointer; font-size:1.8rem; line-height:1; }
.queue-tabs { display:grid; grid-template-columns:1fr 1fr; border-bottom:1px solid #d8e7f1; padding:0 26px; }
.queue-tab { min-height:48px; border:0; border-bottom:3px solid transparent; background:transparent; color:#061b57; cursor:pointer; font-weight:850; }
.queue-tab.is-active { border-color:#0d6bed; color:#0d6bed; }
.queue-modal-body { padding:18px 26px; display:grid; gap:18px; background:#fff; }
.queue-search-line { display:grid; grid-template-columns:minmax(0,1fr) 140px; gap:12px; }
.queue-modal-search { min-height:44px; border:1px solid #cfe1ee; border-radius:7px; display:flex; align-items:center; gap:10px; padding:0 12px; background:#fff; }
.queue-modal-search svg { width:21px; height:21px; fill:none; stroke:#0d6bed; stroke-width:2.4; }
.queue-modal-search input { width:100%; border:0; outline:0; color:#253a66; font:inherit; font-weight:750; }
.queue-patient-results { display:none; gap:8px; margin-top:10px; max-height:150px; overflow:auto; }
.queue-patient-results.is-open { display:grid; }
.queue-patient-option { width:100%; border:1px solid #cfe1ee; border-radius:7px; padding:10px 12px; background:#fff; color:#061b57; cursor:pointer; text-align:left; font:inherit; }
.queue-patient-option:hover, .queue-patient-option.is-selected { border-color:#0d6bed; background:#eef7ff; }
.queue-patient-option strong { display:block; font-size:.9rem; font-weight:950; }
.queue-patient-option span { display:block; margin-top:3px; color:#5d6d8f; font-size:.78rem; font-weight:750; }
.queue-modal-section { border-radius:8px; padding:14px 16px; background:#eef7ff; }
.queue-modal-section h3 { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:0 0 10px; color:#061b57; font-size:.93rem; font-weight:950; }
.queue-service-manage-link { color:#0d6bed; font-size:.75rem; font-weight:800; text-decoration:none; white-space:nowrap; }
.queue-service-manage-link:hover { text-decoration:underline; }
.queue-patient-grid { display:grid; grid-template-columns:1.4fr 1fr; gap:10px 14px; }
.queue-patient-grid .wide { grid-column:1 / -1; }
.queue-patient-grid label { display:grid; gap:5px; color:#061b57; font-size:.78rem; font-weight:850; }
.queue-patient-grid input { min-height:38px; border:1px solid #cfe1ee; border-radius:6px; padding:7px 10px; background:#fff; color:#1f343d; font:inherit; font-size:.86rem; }
.queue-modal-foot { display:grid; grid-template-columns:minmax(0,1fr) auto auto; gap:12px; align-items:center; padding:0 26px 24px; }
.queue-modal-note { display:flex; align-items:center; gap:10px; min-height:52px; border-radius:8px; padding:10px 14px; background:#e5f3ff; color:#0d6bed; font-size:.78rem; font-weight:800; }
.queue-modal-note strong { display:grid; place-items:center; width:24px; height:24px; border-radius:50%; background:#0d6bed; color:#fff; flex:0 0 auto; }
.queue-modal-cancel { min-width:92px; background:#eef7ff; color:#0b4f80; border-color:#cfe1ee; }
.queue-modal-submit { min-width:190px; gap:8px; }
.queue-tab-panel[hidden] { display:none; }
.queue-register-box { border:1px solid #dbeaf4; border-radius:8px; padding:22px; background:#f8fcff; color:#061b57; font-weight:800; line-height:1.5; }
.queue-register-box p { margin:0; color:#597082; font-size:.9rem; font-weight:750; }
.queue-tab-only[hidden] { display:none !important; }
@media (max-width:980px) { .queue-layout { grid-template-columns:1fr; } .queue-tools, .queue-search-line, .queue-patient-grid, .queue-modal-foot, .queue-service-cascade { grid-template-columns:1fr; } .queue-tools { display:grid; } .now-serving-card, .now-serving-stack { position:static; } .queue-sections .still-waiting-table th, .queue-sections .still-waiting-table td { padding:9px 5px; font-size:.74rem; } }
@media (max-width:760px) {
    .queue-sections .still-waiting-table-wrap { overflow:visible; }
    .queue-sections .still-waiting-table, .queue-sections .still-waiting-table thead, .queue-sections .still-waiting-table tbody, .queue-sections .still-waiting-table tr, .queue-sections .still-waiting-table td { display:block; width:100%; }
    .queue-sections .still-waiting-table thead { display:none; }
    .queue-sections .still-waiting-table tr { box-sizing:border-box; margin-bottom:10px; padding:8px 10px; border:1px solid #e4edf2; border-radius:8px; background:#fff; }
    .queue-sections .still-waiting-table tr:last-child { margin-bottom:0; }
    .queue-sections .still-waiting-table td { box-sizing:border-box; display:flex; align-items:center; justify-content:space-between; gap:12px; width:100% !important; min-height:34px; padding:7px 0; border-bottom:1px solid #eef3f6; text-align:right; overflow-wrap:anywhere; }
    .queue-sections .still-waiting-table td::before { flex:0 0 42%; color:#708792; font-size:.68rem; font-weight:800; letter-spacing:.04em; text-align:left; text-transform:uppercase; content:attr(data-label); }
    .queue-sections .still-waiting-table td:last-child { padding-right:0; border-bottom:0; }
    .queue-sections .still-waiting-table td:first-child { color:#708792; }
    .queue-sections .still-waiting-table .queue-row-actions { justify-content:flex-end; flex:1; }
    .queue-sections .still-waiting-table .queue-row-actions form { width:auto; }
}
';

$queueServiceCatalogJson = json_encode($queueServiceCatalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$queueVoiceNumberJson = json_encode($queueVoiceNumber, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$queueVoiceTypeJson = json_encode($queueVoiceType, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    var serviceCatalog = ' . ($queueServiceCatalogJson ?: '[]') . ';
    var paginationSections = Array.prototype.slice.call(document.querySelectorAll("[data-queue-pagination]"));
    function renderQueuePagination(section, resetPage) {
        var pageSize = parseInt(section.getAttribute("data-page-size") || "5", 10);
        var tableRows = Array.prototype.slice.call(section.querySelectorAll("[data-page-row]"));
        var controls = section.querySelector("[data-pagination-controls]");
        var emptyMessage = section.querySelector("[data-pagination-empty]");
        var query = "";
        var filteredRows = tableRows.filter(function(row) {
            return query === "" || (row.getAttribute("data-table-search") || "").indexOf(query) !== -1;
        });
        var totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        var currentPage = resetPage ? 1 : parseInt(section.getAttribute("data-current-page") || "1", 10);
        currentPage = Math.min(Math.max(currentPage, 1), totalPages);
        section.setAttribute("data-current-page", String(currentPage));

        tableRows.forEach(function(row) {
            row.style.display = "none";
        });
        filteredRows.forEach(function(row, index) {
            var start = (currentPage - 1) * pageSize;
            var end = start + pageSize;
            row.style.display = index >= start && index < end ? "" : "none";
        });
        if (emptyMessage) {
            emptyMessage.style.display = filteredRows.length ? "none" : "block";
        }
        if (!controls) {
            return;
        }
        controls.innerHTML = "";
        function addPageButton(label, page, disabled, active) {
            var button = document.createElement("button");
            button.type = "button";
            button.className = "queue-page-btn" + (active ? " is-active" : "");
            button.textContent = label;
            button.disabled = disabled;
            button.addEventListener("click", function() {
                section.setAttribute("data-current-page", String(page));
                renderQueuePagination(section, false);
            });
            controls.appendChild(button);
        }
        addPageButton("Previous", currentPage - 1, currentPage === 1 || filteredRows.length === 0, false);
        for (var page = 1; page <= totalPages; page += 1) {
            addPageButton(String(page), page, filteredRows.length === 0, page === currentPage);
        }
        addPageButton("Next", currentPage + 1, currentPage === totalPages || filteredRows.length === 0, false);
    }
    function renderAllQueuePagination(resetPage) {
        paginationSections.forEach(function(section) {
            renderQueuePagination(section, resetPage);
        });
    }
    renderAllQueuePagination(false);
    var modal = document.getElementById("queueAddModal");
    var successModal = document.getElementById("queueSuccessModal");
    var shouldPlayQueueVoice = successModal && successModal.hasAttribute("data-play-queue-voice");
    var queueVoicePlayed = false;
    var queueVoiceNumber = ' . ($queueVoiceNumberJson ?: '""') . ';
    var queueVoiceType = ' . ($queueVoiceTypeJson ?: '""') . ';
    var confirmModal = document.getElementById("queueActionConfirmModal");
    var confirmTitle = document.getElementById("queueActionConfirmTitle");
    var confirmMessage = document.getElementById("queueActionConfirmMessage");
    var confirmActionButton = document.getElementById("confirmQueueActionButton");
    var openButton = document.querySelector("[data-open-queue-modal]");
    var closeButtons = document.querySelectorAll("[data-close-queue-modal]");
    var successCloseButtons = document.querySelectorAll("[data-close-success-modal]");
    var errorModal = document.getElementById("queueErrorModal");
    var errorCloseButtons = document.querySelectorAll("[data-close-error-modal]");
    var openConfirmButtons = document.querySelectorAll("[data-open-queue-confirm]");
    var closeConfirmButtons = document.querySelectorAll("[data-close-queue-confirm]");
    var pendingConfirmForm = null;
    var patientSearch = document.getElementById("queuePatientSearch");
    var patientSearchButton = document.getElementById("queuePatientSearchButton");
    var patientId = document.getElementById("queuePatientId");
    var patientResults = document.getElementById("queuePatientResults");
    var patientOptions = Array.prototype.slice.call(document.querySelectorAll("[data-patient-option]"));
    var patientName = document.getElementById("queuePatientName");
    var patientCode = document.getElementById("queuePatientCode");
    var patientContact = document.getElementById("queuePatientContact");
    var queueAddForm = document.getElementById("queueAddForm");
    var serviceCategory = document.getElementById("queueServiceCategory");
    var serviceSubcategory = document.getElementById("queueServiceSubcategory");
    var specificService = document.getElementById("queueSpecificService");
    var serviceValue = document.getElementById("queueServiceValue");
    var serviceSubcategoryField = document.getElementById("queueServiceSubcategoryField");
    var specificServiceField = document.getElementById("queueSpecificServiceField");
    var servicePrice = document.getElementById("queueServicePrice");
    var servicePriceValue = document.getElementById("queueServicePriceValue");
    var serviceCategoryValue = document.getElementById("queueServiceCategoryValue");
    var laboratoryAddButton = document.getElementById("queueAddLaboratoryService");
    var selectedLabServicesPanel = document.getElementById("queueSelectedLabServices");
    var selectedLabServicesList = document.getElementById("queueSelectedLabServicesList");
    var selectedLabServicesTotal = document.getElementById("queueSelectedLabServicesTotal");
    var laboratoryServiceInputs = document.getElementById("queueLaboratoryServiceInputs");
    var selectedLabServices = [];
    var tabs = document.querySelectorAll("[data-queue-tab]");
    var tabPanels = document.querySelectorAll("[data-queue-panel]");
    var existingOnlyItems = document.querySelectorAll("[data-existing-only]");
    var registerOnlyItems = document.querySelectorAll("[data-register-only]");

    function openModal() {
        if (!modal) return;
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        if (patientSearch) patientSearch.focus();
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
    }

    function closeSuccessModal() {
        if (!successModal) return;
        successModal.classList.remove("is-open");
        successModal.setAttribute("aria-hidden", "true");
    }

    function speakQueueAnnouncement() {
        if (!shouldPlayQueueVoice || queueVoicePlayed || !queueVoiceNumber || !window.speechSynthesis || !window.SpeechSynthesisUtterance) return;
        queueVoicePlayed = true;
        var normalizedNumber = String(queueVoiceNumber).match(/^([A-Z])-([0-9]+)$/i);
        var spokenNumber = normalizedNumber
            ? normalizedNumber[1].toUpperCase() + ", " + normalizedNumber[2].split("").join(" ")
            : String(queueVoiceNumber);
        var queueTypeLabel = String(queueVoiceType).toLowerCase() === "online" ? "online " : "";
        var announcement = "Now serving " + queueTypeLabel + "queue number " + spokenNumber + ". Please proceed to the service counter.";
        var utterance = new SpeechSynthesisUtterance(announcement);
        utterance.lang = "en-US";
        utterance.rate = 0.9;
        utterance.pitch = 1;
        utterance.volume = 1;
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(utterance);
    }

    function closeErrorModal() {
        if (!errorModal) return;
        errorModal.classList.remove("is-open");
        errorModal.setAttribute("aria-hidden", "true");
    }

    function openQueueConfirm(button) {
        if (!confirmModal || !button) return;
        pendingConfirmForm = button.closest("form");
        if (!pendingConfirmForm) return;
        if (confirmTitle) confirmTitle.textContent = button.getAttribute("data-confirm-title") || "Are you sure?";
        if (confirmMessage) confirmMessage.textContent = button.getAttribute("data-confirm-message") || "Are you sure you want to continue?";
        if (confirmActionButton) confirmActionButton.textContent = button.getAttribute("data-confirm-label") || "Yes, continue";
        confirmModal.classList.add("is-open");
        confirmModal.setAttribute("aria-hidden", "false");
    }

    function closeQueueConfirm() {
        if (!confirmModal) return;
        confirmModal.classList.remove("is-open");
        confirmModal.setAttribute("aria-hidden", "true");
        if (confirmActionButton) confirmActionButton.textContent = "Yes, continue";
        pendingConfirmForm = null;
    }

    function selectPatient(option) {
        patientOptions.forEach(function(item) { item.classList.toggle("is-selected", item === option); });
        if (patientId) patientId.value = option ? (option.getAttribute("data-id") || "") : "";
        if (patientName) patientName.value = option ? (option.getAttribute("data-name") || "") : "";
        if (patientCode) patientCode.value = option ? (option.getAttribute("data-patient-code") || "") : "";
        if (patientContact) patientContact.value = option ? (option.getAttribute("data-contact") || "") : "";
    }

    function filterPatients() {
        if (!patientSearch || !patientResults) return;
        var query = patientSearch.value.trim().toLowerCase();
        patientResults.classList.toggle("is-open", query !== "");
        patientOptions.forEach(function(option) {
            option.style.display = query !== "" && (option.getAttribute("data-search") || "").indexOf(query) === -1 ? "none" : "";
        });
    }

    function uniqueValues(items, key) {
        return items.map(function(item) { return item[key] || ""; })
            .filter(function(value, index, list) { return value !== "" && list.indexOf(value) === index; });
    }

    function setOptions(select, values) {
        if (!select) return;
        select.innerHTML = "";
        values.forEach(function(value) {
            var option = document.createElement("option");
            var optionValue = value && typeof value === "object" ? value.value : value;
            var optionLabel = value && typeof value === "object" ? value.label : value;
            option.value = optionValue;
            option.textContent = optionLabel;
            select.appendChild(option);
        });
    }

    function serviceMode(category) {
        var found = serviceCatalog.find(function(item) { return item.category === category; });
        return found && found.mode ? found.mode : "full";
    }

    function setCascadeVisibility(mode) {
        if (serviceSubcategoryField) serviceSubcategoryField.hidden = mode === "category_only";
        if (specificServiceField) specificServiceField.hidden = mode !== "full";
        if (serviceSubcategory) serviceSubcategory.disabled = mode === "category_only";
        if (specificService) specificService.disabled = mode !== "full";
        if (laboratoryAddButton) laboratoryAddButton.hidden = !serviceCategory || serviceCategory.value !== "Laboratory Tests";
    }

    function formatServicePrice(price) {
        return "PHP " + Number(price || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function currentLaboratoryService() {
        if (!serviceCategory || serviceCategory.value !== "Laboratory Tests") return null;
        return serviceCatalog.find(function(item) {
            return item.category === serviceCategory.value
                && item.subcategory === (serviceSubcategory ? serviceSubcategory.value : "")
                && item.service === (specificService ? specificService.value : "");
        }) || null;
    }

    function renderSelectedLabServices() {
        var isLaboratoryTests = serviceCategory && serviceCategory.value === "Laboratory Tests";
        if (serviceCategoryValue && serviceCategory) serviceCategoryValue.value = serviceCategory.value || "";
        if (selectedLabServicesPanel) selectedLabServicesPanel.hidden = !isLaboratoryTests || selectedLabServices.length === 0;
        if (selectedLabServicesList) selectedLabServicesList.innerHTML = "";
        if (laboratoryServiceInputs) laboratoryServiceInputs.innerHTML = "";

        var total = 0;
        selectedLabServices.forEach(function(item, index) {
            total += Number(item.price || 0);
            if (selectedLabServicesList) {
                var row = document.createElement("div");
                row.className = "queue-lab-selected-item";
                var name = document.createElement("span");
                name.textContent = item.name;
                var price = document.createElement("strong");
                price.textContent = formatServicePrice(item.price);
                row.appendChild(name);
                row.appendChild(price);
                var removeButton = document.createElement("button");
                removeButton.type = "button";
                removeButton.className = "queue-lab-remove";
                removeButton.textContent = "Remove";
                removeButton.addEventListener("click", function() {
                    selectedLabServices.splice(index, 1);
                    renderSelectedLabServices();
                    updateServiceValue();
                });
                row.appendChild(removeButton);
                selectedLabServicesList.appendChild(row);
            }
            if (laboratoryServiceInputs) {
                var input = document.createElement("input");
                input.type = "hidden";
                input.name = "laboratory_service_ids[]";
                input.value = String(item.id);
                laboratoryServiceInputs.appendChild(input);
            }
        });
        if (selectedLabServicesTotal) selectedLabServicesTotal.textContent = formatServicePrice(total);
        if (serviceValue && isLaboratoryTests && selectedLabServices.length > 0) {
            serviceValue.value = selectedLabServices.map(function(item) { return item.name; }).join(", ");
        }
    }

    function addCurrentLaboratoryService() {
        var item = currentLaboratoryService();
        if (!item || !item.id) return false;
        var alreadySelected = selectedLabServices.some(function(selected) { return Number(selected.id) === Number(item.id); });
        if (alreadySelected) return true;
        selectedLabServices.push({ id: Number(item.id), name: item.service, price: Number(item.price || 0) });
        renderSelectedLabServices();
        updateServiceValue();
        return true;
    }

    function updateServicePrice() {
        if (!servicePrice || !servicePriceValue || !serviceCategory) return;
        var category = serviceCategory.value || "";
        var isLaboratoryService = category === "Laboratory Package" || category === "Laboratory Tests";
        var selectedService = serviceCatalog.find(function(item) {
            return item.category === category
                && item.subcategory === (serviceSubcategory ? serviceSubcategory.value : "")
                && item.service === (specificService ? specificService.value : "");
        });
        var price = selectedService && selectedService.price !== null && selectedService.price !== undefined
            ? Number(selectedService.price)
            : NaN;
        var hasPrice = isLaboratoryService && Number.isFinite(price) && price >= 0;
        servicePrice.hidden = !hasPrice;
        if (hasPrice) {
            servicePriceValue.textContent = formatServicePrice(price);
        }
    }

    function updateServiceValue() {
        if (!serviceValue || !serviceCategory) return;
        if (serviceCategoryValue) serviceCategoryValue.value = serviceCategory.value || "";
        var mode = serviceMode(serviceCategory.value);
        if (mode === "category_only") {
            serviceValue.value = serviceCategory.value || "Ultra sound";
            updateServicePrice();
            return;
        }
        if (mode === "doctor") {
            var doctorItem = serviceCatalog.find(function(item) {
                return item.category === serviceCategory.value && item.subcategory === (serviceSubcategory ? serviceSubcategory.value : "");
            });
            serviceValue.value = doctorItem ? doctorItem.service : "Doctor consultation";
            updateServicePrice();
            return;
        }
        serviceValue.value = specificService && specificService.value ? specificService.value : "General Consultation";
        if (serviceCategory.value === "Laboratory Tests" && selectedLabServices.length > 0) {
            serviceValue.value = selectedLabServices.map(function(item) { return item.name; }).join(", ");
        }
        updateServicePrice();
    }

    function renderSpecificServices() {
        if (!serviceCategory || !serviceSubcategory || !specificService) return;
        var mode = serviceMode(serviceCategory.value);
        setCascadeVisibility(mode);
        if (mode !== "full") {
            setOptions(specificService, []);
            updateServiceValue();
            return;
        }
        var category = serviceCategory.value;
        var subcategory = serviceSubcategory.value;
        var serviceItems = serviceCatalog
            .filter(function(item) { return item.category === category && item.subcategory === subcategory; })
            .map(function(item) {
                var price = Number(item.price);
                var isLaboratoryService = category === "Laboratory Package" || category === "Laboratory Tests";
                var label = item.service;
                if (isLaboratoryService && Number.isFinite(price) && price >= 0) {
                    label += " - " + formatServicePrice(price);
                }
                return { value: item.service, label: label };
            });
        setOptions(specificService, serviceItems.length ? serviceItems : ["General Consultation"]);
        updateServiceValue();
    }

    function renderSubcategories() {
        if (!serviceCategory || !serviceSubcategory) return;
        var category = serviceCategory.value;
        if (category !== "Laboratory Tests") {
            selectedLabServices = [];
            renderSelectedLabServices();
        }
        var mode = serviceMode(category);
        setCascadeVisibility(mode);
        if (mode === "category_only") {
            setOptions(serviceSubcategory, []);
            if (specificService) setOptions(specificService, []);
            updateServiceValue();
            return;
        }
        var subcategories = uniqueValues(serviceCatalog.filter(function(item) {
            return item.category === category;
        }), "subcategory");
        setOptions(serviceSubcategory, subcategories.length ? subcategories : ["No doctor available"]);
        renderSpecificServices();
    }

    function renderCategories() {
        if (!serviceCategory) return;
        setOptions(serviceCategory, uniqueValues(serviceCatalog, "category"));
        renderSubcategories();
    }

    if (openButton) openButton.addEventListener("click", openModal);
    closeButtons.forEach(function(button) {
        button.addEventListener("click", closeModal);
    });
    successCloseButtons.forEach(function(button) {
        button.addEventListener("click", closeSuccessModal);
    });
    errorCloseButtons.forEach(function(button) {
        button.addEventListener("click", closeErrorModal);
    });
    openConfirmButtons.forEach(function(button) {
        button.addEventListener("click", function() { openQueueConfirm(button); });
    });
    closeConfirmButtons.forEach(function(button) {
        button.addEventListener("click", closeQueueConfirm);
    });
    if (confirmActionButton) {
        confirmActionButton.addEventListener("click", function() {
            var form = pendingConfirmForm;
            closeQueueConfirm();
            if (form) form.submit();
        });
    }
    if (modal) {
        modal.addEventListener("click", function(event) {
            if (event.target === modal) closeModal();
        });
    }
    if (successModal) {
        successModal.addEventListener("click", function(event) {
            if (event.target === successModal) closeSuccessModal();
        });
        if (shouldPlayQueueVoice) window.setTimeout(speakQueueAnnouncement, 120);
    }
    if (errorModal) {
        errorModal.addEventListener("click", function(event) {
            if (event.target === errorModal) closeErrorModal();
        });
    }
    if (confirmModal) {
        confirmModal.addEventListener("click", function(event) {
            if (event.target === confirmModal) closeQueueConfirm();
        });
    }
    document.addEventListener("keydown", function(event) {
        if (event.key === "Escape") {
            closeModal();
            closeSuccessModal();
            closeErrorModal();
            closeQueueConfirm();
        }
    });

    tabs.forEach(function(tab) {
        tab.addEventListener("click", function() {
            var target = tab.getAttribute("data-queue-tab");
            tabs.forEach(function(item) { item.classList.toggle("is-active", item === tab); });
            tabPanels.forEach(function(item) {
                item.hidden = item.getAttribute("data-queue-panel") !== target;
            });
            existingOnlyItems.forEach(function(item) { item.hidden = target !== "existing"; });
            registerOnlyItems.forEach(function(item) { item.hidden = target !== "register"; });
        });
    });

    if (patientSearch) patientSearch.addEventListener("input", filterPatients);
    if (patientSearchButton) patientSearchButton.addEventListener("click", filterPatients);
    if (serviceCategory) serviceCategory.addEventListener("change", renderSubcategories);
    if (serviceSubcategory) serviceSubcategory.addEventListener("change", renderSpecificServices);
    if (specificService) specificService.addEventListener("change", updateServiceValue);
    if (laboratoryAddButton) laboratoryAddButton.addEventListener("click", addCurrentLaboratoryService);
    patientOptions.forEach(function(option) {
        option.addEventListener("click", function() {
            selectPatient(option);
            if (patientSearch) patientSearch.value = option.getAttribute("data-name") || "";
            if (patientResults) patientResults.classList.remove("is-open");
        });
    });
    if (queueAddForm) {
        queueAddForm.addEventListener("submit", function(event) {
            if (!patientId || patientId.value === "") {
                event.preventDefault();
                if (patientResults) patientResults.classList.add("is-open");
                if (patientSearch) patientSearch.focus();
                return;
            }
            if (serviceCategory && serviceCategory.value === "Laboratory Tests") {
                if (selectedLabServices.length === 0) addCurrentLaboratoryService();
                if (selectedLabServices.length === 0) {
                    event.preventDefault();
                    return;
                }
                renderSelectedLabServices();
            }
        });
    }
    renderCategories();
});
';

include 'includes/header.php';
?>
<main class="queue-page">
    <?php if ($error && !$isQueueAvailabilityError): ?>
        <div class="queue-alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <section class="queue-shell" aria-labelledby="queueManagementTitle">
        <div class="queue-tools">
            <button class="queue-add-button" type="button" data-open-queue-modal>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Add to Queue
            </button>
        </div>
        <p class="queue-priority-note">Calling order: priority patients are called before regular patients within each queue. Priority patients with the same status follow time added.</p>

        <div class="queue-layout">
            <div class="queue-sections">
                <section class="queue-card">
                    <h2>
                        <span class="queue-title-text">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 4a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/><path d="M8 22l2-7-3-2 2-5h4l2 5 3 2"/><path d="m14 15 2 7"/></svg>
                            Walk-in Queue
                        </span>
                        <span class="queue-count-pill"><?php echo (int) $walkInWaitingCount; ?> waiting</span>
                    </h2>
                    <?php if (empty($walkInQueue)): ?>
                        <div class="queue-empty">No walk-in patients waiting.</div>
                    <?php else: ?>
                        <div data-queue-pagination data-page-size="5">
                        <div class="queue-table-wrap">
                            <table class="queue-table walk-in-queue-table">
                                <thead><tr><th>#</th><th>Queue No.</th><th>Patient Name</th><th>Service</th><th>Time Added</th><th>Status</th><th>Priority</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($walkInQueue as $index => $queueRow): ?>
                                        <?php $queueSearchText = strtolower((string) $queueRow['queue_number'] . ' ' . (string) $queueRow['patient_name'] . ' ' . (string) ($queueRow['service'] ?? '') . ' ' . (string) ($queueRow['status'] ?? '')); ?>
                                        <tr data-page-row data-table-search="<?php echo htmlspecialchars($queueSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                            <td><?php echo $index + 1; ?></td>
                                            <td class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                            <td><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($queueRow['service'] ?? 'General Consultation')); ?></td>
                                            <td><?php echo htmlspecialchars(queue_time_label($queueRow['time_added'] ?? null)); ?></td>
                                            <td><span class="badge <?php echo htmlspecialchars((string) $queueRow['status']); ?>"><?php echo htmlspecialchars(queue_status_label($queueRow['status'] ?? '')); ?></span></td>
                                            <td><?php echo queue_priority_badges_markup($queueRow['priority_type'] ?? ''); ?></td>
                                            <td>
                                                <div class="queue-row-actions">
                                                    <?php if (($queueRow['status'] ?? '') === 'waiting'): ?>
                                                    <form method="post" action="admin_queue.php">
                                                        <input type="hidden" name="queue_action" value="call_patient">
                                                        <input type="hidden" name="queue_id" value="<?php echo (int) $queueRow['id']; ?>">
                                                        <button class="btn secondary" type="button" data-open-queue-confirm data-confirm-title="Call this patient?" data-confirm-message="Are you sure you want to call this patient?" data-confirm-label="Continue">Queue</button>
                                                    </form>
                                                    <?php else: ?>
                                                        <span class="queue-row-current">Now Serving</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="queue-empty" data-pagination-empty>No matching walk-in queue records.</div>
                        <div class="queue-pagination" data-pagination-controls aria-label="Walk-in queue pagination"></div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="queue-card">
                    <h2>
                        <span class="queue-title-text">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg>
                            Online Queue
                        </span>
                        <span class="queue-count-pill"><?php echo (int) $onlineWaitingCount; ?> waiting</span>
                    </h2>
                    <?php if (empty($onlineQueue)): ?>
                        <div class="queue-empty">No online appointments waiting.</div>
                    <?php else: ?>
                        <div data-queue-pagination data-page-size="5">
                        <div class="queue-table-wrap">
                            <table class="queue-table online-queue-table">
                                <thead><tr><th>#</th><th>Queue No.</th><th>Patient Name</th><th>Service</th><th>Time Added</th><th>Status</th><th>Priority</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($onlineQueue as $index => $queueRow): ?>
                                        <?php $queueSearchText = strtolower((string) $queueRow['queue_number'] . ' ' . (string) $queueRow['patient_name'] . ' ' . (string) ($queueRow['service'] ?? '') . ' ' . (string) ($queueRow['status'] ?? '')); ?>
                                        <tr data-page-row data-table-search="<?php echo htmlspecialchars($queueSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                            <td><?php echo $index + 1; ?></td>
                                            <td class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                            <td><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($queueRow['service'] ?? 'General Consultation')); ?></td>
                                            <td><?php echo htmlspecialchars(queue_time_label($queueRow['time_added'] ?? null)); ?></td>
                                            <td><span class="badge <?php echo htmlspecialchars((string) $queueRow['status']); ?>"><?php echo htmlspecialchars(queue_status_label($queueRow['status'] ?? '')); ?></span></td>
                                            <td><?php echo queue_priority_badges_markup($queueRow['priority_type'] ?? ''); ?></td>
                                            <td>
                                                <div class="queue-row-actions">
                                                    <?php if (($queueRow['status'] ?? '') === 'waiting'): ?>
                                                    <form method="post" action="admin_queue.php">
                                                        <input type="hidden" name="queue_action" value="call_patient">
                                                        <input type="hidden" name="queue_id" value="<?php echo (int) $queueRow['id']; ?>">
                                                        <button class="btn secondary" type="button" data-open-queue-confirm data-confirm-title="Call this patient?" data-confirm-message="Are you sure you want to call this patient?" data-confirm-label="Continue">Queue</button>
                                                    </form>
                                                    <?php else: ?>
                                                        <span class="queue-row-current">Now Serving</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="queue-empty" data-pagination-empty>No matching online queue records.</div>
                        <div class="queue-pagination" data-pagination-controls aria-label="Online queue pagination"></div>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="queue-card">
                    <h2>
                        <span class="queue-title-text">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            Waiting Queue
                        </span>
                        <span class="queue-count-pill"><?php echo (int) $stillWaitingCount; ?> waiting</span>
                    </h2>
                    <?php if (empty($stillWaitingQueue)): ?>
                            <div class="queue-empty">No patients are waiting.</div>
                    <?php else: ?>
                        <div data-queue-pagination data-page-size="5">
                        <div class="queue-table-wrap still-waiting-table-wrap">
                            <table class="queue-table still-waiting-table">
                                <thead><tr><th>#</th><th>Queue No.</th><th>Patient Name</th><th>Service</th><th>Waiting Since</th><th>Type</th><th>Status</th><th>Priority</th><th>Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($stillWaitingQueue as $index => $queueRow): ?>
                                        <?php $queueSearchText = strtolower((string) $queueRow['queue_number'] . ' ' . (string) $queueRow['patient_name'] . ' ' . (string) ($queueRow['service'] ?? '') . ' ' . queue_type_label($queueRow['queue_type'] ?? '')); ?>
                                        <tr data-page-row data-table-search="<?php echo htmlspecialchars($queueSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                            <td data-label="#"><?php echo $index + 1; ?></td>
                                            <td data-label="Queue No." class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                            <td data-label="Patient Name"><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                            <td data-label="Service"><?php echo htmlspecialchars((string) ($queueRow['service'] ?? 'General Consultation')); ?></td>
                                            <td data-label="Waiting Since"><?php echo htmlspecialchars(queue_time_label(($queueRow['still_waiting_at'] ?? '') ?: ($queueRow['time_called'] ?? null))); ?></td>
                                            <td data-label="Type"><span class="badge <?php echo htmlspecialchars((string) $queueRow['queue_type']); ?>"><?php echo htmlspecialchars(queue_type_label($queueRow['queue_type'] ?? '')); ?></span></td>
                                            <td data-label="Status"><span class="badge waiting">Waiting</span></td>
                                            <td data-label="Priority"><?php echo queue_priority_badges_markup($queueRow['priority_type'] ?? ''); ?></td>
                                            <td data-label="Actions">
                                                <div class="queue-row-actions">
                                                    <form method="post" action="admin_queue.php">
                                                        <input type="hidden" name="queue_action" value="call_patient">
                                                        <input type="hidden" name="queue_id" value="<?php echo (int) $queueRow['id']; ?>">
                                                        <button class="btn secondary" type="button" data-open-queue-confirm data-confirm-title="Call this patient?" data-confirm-message="Are you sure you want to call this patient?" data-confirm-label="Continue">Queue</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="queue-empty" data-pagination-empty>No matching still waiting records.</div>
                        <div class="queue-pagination" data-pagination-controls aria-label="Still waiting queue pagination"></div>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="now-serving-stack" aria-label="Now serving queues">
                <?php foreach ([
                    ['type' => 'walk_in', 'title' => 'Walk-in Now Serving', 'queue' => $nowServingWalkIn],
                    ['type' => 'online', 'title' => 'Online Now Serving', 'queue' => $nowServingOnline],
                ] as $nowServingCard): ?>
                    <?php $nowServing = $nowServingCard['queue']; ?>
                    <section class="now-serving-card" aria-label="<?php echo htmlspecialchars($nowServingCard['title']); ?>">
                        <h2>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>
                            <?php echo htmlspecialchars($nowServingCard['title']); ?>
                        </h2>
                        <?php if ($nowServing): ?>
                            <span class="now-serving-number"><?php echo htmlspecialchars((string) $nowServing['queue_number']); ?></span>
                            <span class="now-serving-name"><?php echo htmlspecialchars((string) $nowServing['patient_name']); ?></span>
                            <div class="now-serving-details">
                                <span>Time Called: <strong><?php echo htmlspecialchars(queue_time_label($nowServing['time_called'] ?? null)); ?></strong></span>
                                <span>Type: <strong><?php echo htmlspecialchars(queue_type_label($nowServing['queue_type'] ?? '')); ?></strong></span>
                                <span>Service: <strong><?php echo htmlspecialchars((string) ($nowServing['service'] ?? 'General Consultation')); ?></strong></span>
                            </div>
                            <div class="queue-actions">
                                <button class="btn secondary" type="button" disabled>Call Next</button>
                                <form method="post" action="admin_queue.php">
                                    <input type="hidden" name="queue_action" value="complete">
                                    <input type="hidden" name="queue_id" value="<?php echo (int) $nowServing['id']; ?>">
                                    <button class="btn success" type="button" data-open-queue-confirm data-confirm-title="Are you sure?" data-confirm-message="Are you sure you want to mark this patient as completed?">Mark as Completed</button>
                                </form>
                                <form method="post" action="admin_queue.php">
                                    <input type="hidden" name="queue_action" value="call_again">
                                    <input type="hidden" name="queue_id" value="<?php echo (int) $nowServing['id']; ?>">
                                    <button class="btn secondary" type="button" data-open-queue-confirm data-confirm-title="Are you sure?" data-confirm-message="Are you sure you want to call this patient again later?">Call Again Later</button>
                                </form>
                                <form method="post" action="admin_queue.php">
                                    <input type="hidden" name="queue_action" value="cancel">
                                    <input type="hidden" name="queue_id" value="<?php echo (int) $nowServing['id']; ?>">
                                    <button class="btn decline" type="button" data-open-queue-confirm data-confirm-title="Cancel this queue entry?" data-confirm-message="Are you sure you want to cancel this queue entry?">Cancel</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span class="now-serving-number">--</span>
                            <span class="now-serving-name">No patient serving</span>
                            <div class="now-serving-details">
                                <span>Type: <strong><?php echo htmlspecialchars(queue_type_label($nowServingCard['type'])); ?></strong></span>
                                <span>Status: <strong>Ready</strong></span>
                            </div>
                            <?php $sameTypeServing = $nowServingCard['type'] === 'online' ? $nowServingOnline !== null : $nowServingWalkIn !== null; ?>
                            <?php if ($sameTypeServing): ?>
                                <div class="queue-actions">
                                    <button class="btn secondary" type="button" disabled>Call Next</button>
                                </div>
                            <?php else: ?>
                                <form method="post" action="admin_queue.php">
                                    <input type="hidden" name="queue_action" value="call_next_type">
                                    <input type="hidden" name="queue_type" value="<?php echo htmlspecialchars((string) $nowServingCard['type']); ?>">
                                    <button class="btn" type="button" data-open-queue-confirm data-confirm-title="Are you sure?" data-confirm-message="Are you sure you want to call the next patient?">Call Next</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </aside>
        </div>

    </section>

    <?php if ($message): ?>
        <div class="queue-success-modal is-open" id="queueSuccessModal" aria-hidden="false"<?php echo $shouldPlayQueueVoice ? ' data-play-queue-voice' : ''; ?>>
            <div class="queue-success-card" role="dialog" aria-modal="true" aria-labelledby="queueSuccessTitle">
                <span class="queue-success-icon" aria-hidden="true">✓</span>
                <h2 id="queueSuccessTitle">Successfully updated</h2>
                <p><?php echo htmlspecialchars($message); ?></p>
                <button class="btn" type="button" data-close-success-modal>OK</button>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($isQueueAvailabilityError): ?>
        <div class="queue-success-modal is-open" id="queueErrorModal" aria-hidden="false">
            <div class="queue-success-card queue-error-card" role="dialog" aria-modal="true" aria-labelledby="queueErrorTitle">
                <span class="queue-error-icon" aria-hidden="true">!</span>
                <h2 id="queueErrorTitle">No waiting patient</h2>
                <p><?php echo htmlspecialchars($error); ?></p>
                <button class="btn" type="button" data-close-error-modal>OK</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="queue-confirm-modal" id="queueActionConfirmModal" aria-hidden="true">
        <div class="queue-confirm-card" role="dialog" aria-modal="true" aria-labelledby="queueActionConfirmTitle">
            <span class="queue-confirm-icon" aria-hidden="true">?</span>
            <h2 id="queueActionConfirmTitle">Are you sure?</h2>
            <p id="queueActionConfirmMessage">Are you sure you want to continue?</p>
            <div class="queue-confirm-actions">
                <button class="btn secondary" type="button" data-close-queue-confirm>Cancel</button>
                <button class="btn success" type="button" id="confirmQueueActionButton">Yes, continue</button>
            </div>
        </div>
    </div>

    <div class="queue-modal" id="queueAddModal" aria-hidden="true">
        <div class="queue-modal-card" role="dialog" aria-modal="true" aria-labelledby="queueAddTitle">
            <div class="queue-modal-head">
                <span class="queue-modal-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M15 14c2.76 0 5 2.24 5 5v1H2v-1c0-2.76 2.24-5 5-5h8zM11 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM20 7V4h-2v3h-3v2h3v3h2V9h3V7h-3z"/></svg>
                </span>
                <div class="queue-modal-title">
                    <h2 id="queueAddTitle">Add Walk-in Patient to Queue</h2>
                    <p>Search for an existing patient or register a new patient, then add them to the walk-in queue.</p>
                </div>
                <button class="queue-modal-close" type="button" data-close-queue-modal aria-label="Close">&times;</button>
            </div>

            <div class="queue-tabs" role="tablist" aria-label="Queue patient options">
                <button class="queue-tab is-active" type="button" data-queue-tab="existing">Search Existing Patient</button>
                <button class="queue-tab" type="button" data-queue-tab="register">Register New Patient</button>
            </div>

            <form method="post" action="admin_queue.php" id="queueAddForm">
                <input type="hidden" name="queue_action" value="add">
                <input type="hidden" name="queue_type" value="walk_in">
                <input type="hidden" name="appointment_id" value="0">
                <input type="hidden" name="patient_id" id="queuePatientId" value="">

                <div class="queue-modal-body">
                    <div class="queue-tab-panel" data-queue-panel="existing">
                        <div class="queue-search-line">
                            <div class="queue-modal-search">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35"/><circle cx="11" cy="11" r="7"/></svg>
                                <input type="search" id="queuePatientSearch" placeholder="Search by name, contact number, or patient ID...">
                            </div>
                            <button class="btn" type="button" id="queuePatientSearchButton">Search</button>
                        </div>
                        <div class="queue-patient-results" id="queuePatientResults">
                            <?php foreach ($queuePatients as $patient): ?>
                                <?php
                                $patientContact = trim((string) (($patient['phone'] ?? '') ?: ($patient['email'] ?? '')));
                                $patientCode = 'P-' . str_pad((string) (int) $patient['id'], 4, '0', STR_PAD_LEFT);
                                $patientName = (string) ($patient['full_name'] ?? 'Patient');
                                $patientSearchText = strtolower($patientName . ' ' . $patientContact . ' ' . $patientCode);
                                ?>
                                <button
                                    class="queue-patient-option"
                                    type="button"
                                    data-patient-option
                                    data-search="<?php echo htmlspecialchars($patientSearchText, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-id="<?php echo (int) $patient['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($patientName, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-contact="<?php echo htmlspecialchars($patientContact, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-patient-code="<?php echo htmlspecialchars($patientCode, ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <strong><?php echo htmlspecialchars($patientName); ?></strong>
                                    <span><?php echo htmlspecialchars($patientCode . ($patientContact !== '' ? ' | ' . $patientContact : '')); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="queue-tab-panel" data-queue-panel="register" hidden>
                        <div class="queue-register-box">
                            <strong>Register New Patient</strong>
                            <p>Create the patient record first. After registration, you can search the patient name here and add them to the queue.</p>
                        </div>
                    </div>

                    <section class="queue-modal-section queue-tab-only" data-existing-only>
                        <h3>Patient Information</h3>
                        <div class="queue-patient-grid">
                            <label>
                                Full Name
                                <input type="text" id="queuePatientName" value="" readonly>
                            </label>
                            <label>
                                Patient ID
                                <input type="text" id="queuePatientCode" value="" readonly>
                            </label>
                            <label>
                                Contact Number
                                <input type="text" id="queuePatientContact" value="" readonly>
                            </label>
                        </div>
                    </section>

                    <section class="queue-modal-section queue-tab-only" data-existing-only>
                        <h3>
                            <span>Select Service</span>
                            <a class="queue-service-manage-link" href="admin_lab_services.php">+ Add or manage services</a>
                        </h3>
                        <div class="queue-form-grid">
                            <input type="hidden" name="service" id="queueServiceValue" value="General Consultation">
                            <input type="hidden" name="service_category" id="queueServiceCategoryValue" value="">
                            <div id="queueLaboratoryServiceInputs"></div>
                            <div class="queue-service-cascade">
                                <label>
                                    Category <span style="color:#d11b2d;">*</span>
                                    <select id="queueServiceCategory" required></select>
                                </label>
                                <label id="queueServiceSubcategoryField">
                                    Subcategory <span style="color:#d11b2d;">*</span>
                                    <select id="queueServiceSubcategory" required></select>
                                </label>
                                <label id="queueSpecificServiceField">
                                    Specific Service <span style="color:#d11b2d;">*</span>
                                    <select id="queueSpecificService" required></select>
                                </label>
                            </div>
                            <div id="queueServicePrice" class="queue-service-price" hidden>
                                <span>Current service price</span>
                                <strong id="queueServicePriceValue">PHP 0.00</strong>
                            </div>
                            <button id="queueAddLaboratoryService" class="btn secondary queue-service-add" type="button" hidden>+ Add another test</button>
                            <div id="queueSelectedLabServices" class="queue-lab-selection" hidden>
                                <div class="queue-lab-selection-title">Selected laboratory tests</div>
                                <div id="queueSelectedLabServicesList" class="queue-lab-selected-list"></div>
                                <div class="queue-lab-total">
                                    <span>Total</span>
                                    <strong id="queueSelectedLabServicesTotal">PHP 0.00</strong>
                                </div>
                            </div>
                            <div class="queue-priority-field">
                                <span>Priority Type</span>
                                <div class="queue-priority-options">
                                    <label class="queue-priority-option"><input type="radio" name="priority_types[]" value="senior"> Senior</label>
                                    <label class="queue-priority-option"><input type="radio" name="priority_types[]" value="pregnant"> Pregnant</label>
                                    <label class="queue-priority-option"><input type="radio" name="priority_types[]" value="pwd"> PWD</label>
                                </div>
                            </div>
                            <label>
                                Notes (Optional)
                                <textarea name="queue_notes" placeholder="Add additional notes..."></textarea>
                            </label>
                        </div>
                    </section>
                </div>

                <div class="queue-modal-foot">
                    <div class="queue-modal-note queue-tab-only" data-existing-only><strong>i</strong><span>A walk-in queue number will be generated automatically with priority over online queue.</span></div>
                    <div class="queue-modal-note queue-tab-only" data-register-only hidden><strong>i</strong><span>Register the patient first before adding them to the walk-in queue.</span></div>
                    <button class="btn queue-modal-cancel" type="button" data-close-queue-modal>Cancel</button>
                    <button class="btn queue-modal-submit queue-tab-only" type="submit" data-existing-only>+ Add to Walk-in Queue</button>
                    <a class="btn queue-modal-submit queue-tab-only" href="register_patient_receptionist.php" data-register-only hidden>Register New Patient</a>
                </div>
            </form>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>

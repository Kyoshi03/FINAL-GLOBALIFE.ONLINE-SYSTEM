<?php
/**
 * Clinic doctor accounts + availability windows.
 * day_of_week: 1 = Monday â€¦ 7 = Sunday (ISO-8601, PHP date('N')).
 */

function doctor_sched_column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}

function init_doctor_schema_and_accounts(mysqli $conn): void {
    if (!doctor_sched_column_exists($conn, 'users', 'specialty')) {
        $conn->query('ALTER TABLE users ADD COLUMN specialty VARCHAR(120) DEFAULT NULL AFTER role');
    }
    if (!doctor_sched_column_exists($conn, 'users', 'is_active')) {
        $conn->query('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
    }

    $roleCol = $conn->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
    $roleRow = $roleCol ? $roleCol->fetch_assoc() : null;
    if ($roleRow && stripos((string) $roleRow['Type'], 'doctor') === false) {
        $conn->query("UPDATE users SET role = 'admin' WHERE role = 'receptionist'");
        $conn->query("UPDATE users SET role = 'doctor' WHERE role = 'nurse'");
        $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'patient', 'doctor') NOT NULL");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS doctor_availability (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Mon 7=Sun',
        time_start TIME NOT NULL,
        time_end TIME NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_day (user_id, day_of_week)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS system_seed_state (
        seed_key VARCHAR(100) PRIMARY KEY,
        completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function normalize_booking_time(string $t): string {
    $t = trim($t);
    if (strlen($t) === 5) {
        return $t . ':00';
    }
    return $t;
}

function doctor_user_is_active(mysqli $conn, int $userId): bool {
    $st = $conn->prepare("SELECT COALESCE(is_active, 1) AS a FROM users WHERE id = ? AND role = 'doctor' LIMIT 1");
    $st->bind_param('i', $userId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row && (int) $row['a'] === 1;
}

function doctor_time_matches_clinic_slot(mysqli $conn, int $userId, string $dateYmd, string $timeHi): bool {
    $n = (int) date('N', strtotime($dateYmd));
    if ($n < 1 || $n > 7) return false;
    $tm = normalize_booking_time($timeHi);
    $st = $conn->prepare('SELECT 1 FROM doctor_availability WHERE user_id = ? AND day_of_week = ? AND time_start <= ? AND time_end >= ? LIMIT 1');
    $st->bind_param('iiss', $userId, $n, $tm, $tm);
    $st->execute();
    $ok = $st->get_result()->num_rows > 0;
    $st->close();
    return $ok;
}

/**
 * @return array<int,array{day_of_week:int,time_start:string,time_end:string}>
 */
function doctor_fetch_availability_slots(mysqli $conn, int $userId): array {
    $st = $conn->prepare('SELECT day_of_week, time_start, time_end FROM doctor_availability WHERE user_id = ? ORDER BY day_of_week, time_start');
    $st->bind_param('i', $userId);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function doctor_format_time_hm(string $t): string {
    $t = substr($t, 0, 5);
    $ts = strtotime($t);
    return $ts ? date('g:i A', $ts) : $t;
}

function doctor_format_clinic_hours_lines(array $slots): string {
    if (empty($slots)) return 'Walang naka-set na schedule.';
    $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $lines = [];
    foreach ($slots as $sl) {
        $d = (int) $sl['day_of_week'];
        $name = $dayNames[$d] ?? ('Day ' . $d);
        $lines[] = $name . ': ' . doctor_format_time_hm((string) $sl['time_start']) . ' - ' . doctor_format_time_hm((string) $sl['time_end']);
    }
    return implode("\n", $lines);
}

/**
 * @return array{label:string,class:string}
 */
function doctor_specialty_theme(?string $specialty): array {
    $s = strtolower((string) $specialty);
    if (strpos($s, 'pediat') !== false) {
        return ['label' => strtoupper($specialty ?: 'PEDIATRICIAN'), 'class' => 'theme-peds'];
    }
    return ['label' => strtoupper($specialty ?: 'GENERAL DOCTOR'), 'class' => 'theme-intern'];
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_doctors_schedule_reference(mysqli $conn): array {
        $doctorNameSql = dbUsersNameExpression();
        $res = $conn->query("SELECT id, {$doctorNameSql} AS full_name, specialty, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY {$doctorNameSql} ASC");
    if (!$res) return [];
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $id = (int) $r['id'];
        $hours = doctor_format_clinic_hours_lines(doctor_fetch_availability_slots($conn, $id));
        $active = (int) $r['is_active'] === 1;
        $out[] = [
            'id' => $id,
            'full_name' => $r['full_name'],
            'specialty' => $r['specialty'],
            'is_active' => (int) $r['is_active'],
            'clinic_hours' => $hours,
            'can_book' => $active,
            'unavailable_reason' => $active ? '' : 'Hindi active ang doktor (tinanggal ng admin).',
            'theme' => doctor_specialty_theme($r['specialty'] ?? null),
        ];
    }
    return $out;
}

/**
 * @return array<int,array<string,mixed>>
 */
function fetch_doctors_for_booking_display(mysqli $conn, string $dateYmd, string $timeHi): array {
        $doctorNameSql = dbUsersNameExpression();
        $res = $conn->query("SELECT id, {$doctorNameSql} AS full_name, specialty, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY {$doctorNameSql} ASC");
    if (!$res) return [];
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $id = (int) $r['id'];
        $hours = doctor_format_clinic_hours_lines(doctor_fetch_availability_slots($conn, $id));
        $active = (int) $r['is_active'] === 1;
        $timeOk = $active && doctor_time_matches_clinic_slot($conn, $id, $dateYmd, $timeHi);
        $reason = '';
        if (!$active) {
            $reason = 'Hindi active ang doktor (tinanggal ng admin).';
        } elseif (!$timeOk) {
            $reason = 'Hindi tugma ang napiling petsa/oras sa clinic hours.';
        }
        $out[] = [
            'id' => $id,
            'full_name' => $r['full_name'],
            'specialty' => $r['specialty'],
            'is_active' => (int) $r['is_active'],
            'clinic_hours' => $hours,
            'can_book' => $timeOk,
            'unavailable_reason' => $reason,
            'theme' => doctor_specialty_theme($r['specialty'] ?? null),
        ];
    }
    return $out;
}

/**
 * @return array<int,array{id:int,full_name:string,specialty:?string}>
 */
function fetch_doctors_available_at(mysqli $conn, string $dateYmd, string $timeHi): array {
    $n = (int) date('N', strtotime($dateYmd));
    if ($n < 1 || $n > 7) return [];
    $tm = normalize_booking_time($timeHi);
    $doctorNameSql = dbUsersNameExpression('u');
    $sql = "SELECT DISTINCT u.id, {$doctorNameSql} AS full_name, u.specialty
            FROM users u
            INNER JOIN doctor_availability da ON da.user_id = u.id AND da.day_of_week = ?
            WHERE u.role = 'doctor' AND COALESCE(u.is_active, 1) = 1
              AND da.time_start <= ? AND da.time_end >= ?";
    $st = $conn->prepare($sql);
    $st->bind_param('iss', $n, $tm, $tm);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

function user_is_doctor_available_at(mysqli $conn, int $userId, string $dateYmd, string $timeHi): bool {
    if (!doctor_user_is_active($conn, $userId)) return false;
    return doctor_time_matches_clinic_slot($conn, $userId, $dateYmd, $timeHi);
}

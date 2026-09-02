<?php

function init_admin_notifications(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS admin_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        notification_type VARCHAR(60) NOT NULL,
        title VARCHAR(180) NOT NULL,
        message VARCHAR(500) NOT NULL,
        related_user_id INT NULL,
        related_appointment_id INT NULL,
        target_url VARCHAR(255) DEFAULT 'admin.php?notifications=1',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (related_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
        INDEX idx_admin_notifications_unread (read_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM admin_notifications");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
        $result->close();
    }
    if (!isset($columns['related_appointment_id'])) {
        $conn->query("ALTER TABLE admin_notifications ADD COLUMN related_appointment_id INT NULL AFTER related_user_id");
    }
    if (!isset($columns['target_url'])) {
        $conn->query("ALTER TABLE admin_notifications ADD COLUMN target_url VARCHAR(255) DEFAULT 'admin.php?notifications=1' AFTER related_appointment_id");
    }
}

function create_admin_notification(
    mysqli $conn,
    string $type,
    string $title,
    string $message,
    ?int $relatedUserId = null,
    ?int $relatedAppointmentId = null,
    string $targetUrl = 'admin.php?notifications=1'
): bool {
    init_admin_notifications($conn);
    $relatedUserId = $relatedUserId !== null && $relatedUserId > 0 ? $relatedUserId : null;
    $relatedAppointmentId = $relatedAppointmentId !== null && $relatedAppointmentId > 0 ? $relatedAppointmentId : null;
    $stmt = $conn->prepare(
        'INSERT INTO admin_notifications (notification_type, title, message, related_user_id, related_appointment_id, target_url)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssiis', $type, $title, $message, $relatedUserId, $relatedAppointmentId, $targetUrl);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function admin_notification_schedule_label(?string $date, ?string $time): string {
    $timestamp = strtotime(trim((string) $date . ' ' . (string) $time));
    return $timestamp ? date('M j, Y', $timestamp) . ' at ' . date('g:i A', $timestamp) : 'the selected schedule';
}

function create_admin_appointment_notification(mysqli $conn, int $appointmentId, string $eventType): bool {
    if ($appointmentId <= 0) {
        return false;
    }

    init_admin_notifications($conn);
    $stmt = $conn->prepare(
        "SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
                p.full_name AS patient_name,
                d.full_name AS doctor_name,
                COALESCE(
                    GROUP_CONCAT(DISTINCT ls.name ORDER BY ls.name SEPARATOR ', '),
                    CASE
                        WHEN a.booking_type = 'consultation' THEN 'Doctor consultation'
                        WHEN a.booking_type = 'ultrasound' THEN 'Ultra sound'
                        ELSE 'Clinic appointment'
                    END
                ) AS services
         FROM appointments a
         INNER JOIN users p ON p.id = a.patient_id
         LEFT JOIN users d ON d.id = a.doctor_id
         LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
         LEFT JOIN lab_services ls ON ls.id = aps.service_id
         WHERE a.id = ?
         GROUP BY a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time,
                  a.status, a.booking_type, p.full_name, d.full_name
         LIMIT 1"
    );
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$details) {
        return false;
    }

    $eventType = strtolower(trim($eventType));
    if ($eventType === 'rejected') {
        $eventType = 'cancelled';
    }

    $patient = trim((string) ($details['patient_name'] ?? 'Patient'));
    $doctor = trim((string) ($details['doctor_name'] ?? 'Clinic staff'));
    $services = trim((string) ($details['services'] ?? 'Clinic appointment'));
    $schedule = admin_notification_schedule_label((string) $details['appointment_date'], (string) $details['appointment_time']);
    $targetUrl = 'view_appointments.php?highlight=' . $appointmentId;

    $map = [
        'booked' => [
            'appointment_booked',
            'New appointment booked',
            "{$patient} booked {$services} for {$schedule}.",
        ],
        'confirmed' => [
            'appointment_confirmed',
            'Appointment confirmed',
            "{$patient}'s {$services} appointment for {$schedule} was confirmed.",
        ],
        'cancelled' => [
            'appointment_cancelled',
            'Appointment cancelled',
            "{$patient}'s {$services} appointment for {$schedule} was cancelled.",
        ],
        'completed' => [
            'appointment_completed',
            'Appointment completed',
            "{$patient}'s {$services} visit for {$schedule} was completed.",
        ],
        'rescheduled' => [
            'appointment_rescheduled',
            'Appointment rescheduled',
            "{$patient}'s {$services} appointment was moved to {$schedule}.",
        ],
    ];

    $payload = $map[$eventType] ?? [
        'appointment_update',
        'Appointment update',
        "{$patient}'s {$services} appointment with {$doctor} has a new update.",
    ];

    return create_admin_notification(
        $conn,
        $payload[0],
        $payload[1],
        $payload[2],
        (int) ($details['patient_id'] ?? 0),
        $appointmentId,
        $targetUrl
    );
}

function fetch_admin_notifications(mysqli $conn, int $limit = 8): array {
    init_admin_notifications($conn);
    $limit = max(1, min(50, $limit));
    $result = $conn->query(
        "SELECT id, notification_type, title, message, related_user_id, related_appointment_id, target_url, created_at, read_at
         FROM admin_notifications
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function count_unread_admin_notifications(mysqli $conn): int {
    init_admin_notifications($conn);
    $result = $conn->query('SELECT COUNT(*) AS total FROM admin_notifications WHERE read_at IS NULL');
    if ($result && ($row = $result->fetch_assoc())) {
        return (int) ($row['total'] ?? 0);
    }
    return 0;
}

function mark_admin_notifications_read(mysqli $conn): bool {
    init_admin_notifications($conn);
    return (bool) $conn->query('UPDATE admin_notifications SET read_at = NOW() WHERE read_at IS NULL');
}

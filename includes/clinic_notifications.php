<?php

function init_clinic_notifications(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS clinic_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        target_role VARCHAR(40) NULL,
        target_user_id INT NULL,
        notification_type VARCHAR(60) NOT NULL,
        title VARCHAR(180) NOT NULL,
        message VARCHAR(700) NOT NULL,
        related_appointment_id INT NULL,
        target_url VARCHAR(255) DEFAULT 'view_appointments.php',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (related_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
        INDEX idx_clinic_notifications_role (target_role, created_at),
        INDEX idx_clinic_notifications_user (target_user_id, created_at),
        INDEX idx_clinic_notifications_unread (target_role, target_user_id, read_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function create_clinic_notification(
    mysqli $conn,
    ?string $targetRole,
    ?int $targetUserId,
    string $type,
    string $title,
    string $message,
    ?int $appointmentId = null,
    string $targetUrl = 'view_appointments.php'
): bool {
    init_clinic_notifications($conn);
    $targetRole = $targetRole !== null && trim($targetRole) !== '' ? trim($targetRole) : null;
    $targetUserId = $targetUserId !== null && $targetUserId > 0 ? $targetUserId : null;
    $appointmentId = $appointmentId !== null && $appointmentId > 0 ? $appointmentId : null;

    $stmt = $conn->prepare(
        'INSERT INTO clinic_notifications
            (target_role, target_user_id, notification_type, title, message, related_appointment_id, target_url)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sisssis', $targetRole, $targetUserId, $type, $title, $message, $appointmentId, $targetUrl);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function clinic_notification_date_label(?string $date, ?string $time): string {
    $timestamp = strtotime(trim((string) $date . ' ' . (string) $time));
    return $timestamp ? date('M j, Y', $timestamp) . ' at ' . date('g:i A', $timestamp) : 'the selected schedule';
}

function create_clinic_appointment_notification(mysqli $conn, int $appointmentId, string $eventType): void {
    if ($appointmentId <= 0) {
        return;
    }

    init_clinic_notifications($conn);
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
        return;
    }

    $patient = trim((string) ($details['patient_name'] ?? 'Patient'));
    $doctor = trim((string) ($details['doctor_name'] ?? ''));
    $services = trim((string) ($details['services'] ?? 'Clinic appointment'));
    $schedule = clinic_notification_date_label((string) $details['appointment_date'], (string) $details['appointment_time']);
    $doctorId = (int) ($details['doctor_id'] ?? 0);
    $targetUrl = 'view_appointments.php?highlight=' . $appointmentId;

    $events = [
        'booked' => [],
        'confirmed' => [],
        'cancelled' => [],
        'completed' => [],
        'rescheduled' => [],
    ];

    $rows = $events[$eventType] ?? [];
    if ($eventType === 'booked' && (string) ($details['booking_type'] ?? '') === 'consultation' && $doctorId > 0) {
        $rows[] = [null, $doctorId, 'New consultation request', "{$patient} booked a consultation with you for {$schedule}. Please confirm or decline the request."];
    }
    if (in_array($eventType, ['confirmed', 'cancelled', 'rescheduled'], true) && $doctorId > 0) {
        $doctorContext = $doctor !== '' ? ' with you' : '';
        $rows[] = [null, $doctorId, $eventType === 'confirmed' ? 'Appointment confirmed' : 'Appointment update', "{$patient}'s {$services} appointment{$doctorContext} is set for {$schedule}."];
    }

    foreach ($rows as $row) {
        create_clinic_notification(
            $conn,
            $row[0],
            $row[1],
            'appointment_' . $eventType,
            $row[2],
            $row[3],
            $appointmentId,
            $targetUrl
        );
    }
}

function fetch_clinic_notifications(mysqli $conn, string $role, int $userId, int $limit = 8): array {
    init_clinic_notifications($conn);
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare(
        "SELECT n.id, n.notification_type, n.title, n.message, n.related_appointment_id, n.target_url, n.created_at, n.read_at,
                a.status AS appointment_status,
                a.booking_type AS appointment_booking_type,
                a.doctor_id AS appointment_doctor_id
         FROM clinic_notifications n
         LEFT JOIN appointments a ON a.id = n.related_appointment_id
         WHERE n.target_user_id = ? OR n.target_role = ? OR n.target_role = 'staff'
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT {$limit}"
    );
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function count_unread_clinic_notifications(mysqli $conn, string $role, int $userId): int {
    init_clinic_notifications($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM clinic_notifications
         WHERE read_at IS NULL
           AND (target_user_id = ? OR target_role = ? OR target_role = 'staff')"
    );
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function mark_clinic_notifications_read(mysqli $conn, string $role, int $userId): bool {
    init_clinic_notifications($conn);
    $stmt = $conn->prepare(
        "UPDATE clinic_notifications
         SET read_at = NOW()
         WHERE read_at IS NULL
           AND (target_user_id = ? OR target_role = ? OR target_role = 'staff')"
    );
    $stmt->bind_param('is', $userId, $role);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

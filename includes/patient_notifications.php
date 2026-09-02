<?php

function init_patient_notifications(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS patient_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        notification_type VARCHAR(60) NOT NULL,
        title VARCHAR(180) NOT NULL,
        message VARCHAR(700) NOT NULL,
        related_appointment_id INT NULL,
        target_url VARCHAR(255) DEFAULT 'view_appointments.php',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (related_appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
        INDEX idx_patient_notifications_user (patient_id, created_at),
        INDEX idx_patient_notifications_unread (patient_id, read_at, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function create_patient_notification(
    mysqli $conn,
    int $patientId,
    string $type,
    string $title,
    string $message,
    ?int $appointmentId = null,
    string $targetUrl = 'view_appointments.php'
): bool {
    if ($patientId <= 0) {
        return false;
    }

    init_patient_notifications($conn);
    $stmt = $conn->prepare(
        'INSERT INTO patient_notifications
            (patient_id, notification_type, title, message, related_appointment_id, target_url)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isssis', $patientId, $type, $title, $message, $appointmentId, $targetUrl);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved;
}

function patient_notification_date_label(string $date, string $time): string {
    $timestamp = strtotime(trim($date . ' ' . $time));
    if ($timestamp === false) {
        return 'your selected schedule';
    }

    return date('M j, Y', $timestamp) . ' at ' . date('g:i A', $timestamp);
}

function create_patient_appointment_notification(mysqli $conn, int $appointmentId, string $eventType): bool {
    init_patient_notifications($conn);
    $stmt = $conn->prepare(
        "SELECT a.id, a.patient_id, a.appointment_date, a.appointment_time, a.status,
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
         GROUP BY a.id, a.patient_id, a.appointment_date, a.appointment_time, a.status,
                  p.full_name, d.full_name, a.booking_type
         LIMIT 1"
    );
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$details) {
        return false;
    }

    $patientId = (int) $details['patient_id'];
    $schedule = patient_notification_date_label((string) $details['appointment_date'], (string) $details['appointment_time']);
    $services = trim((string) ($details['services'] ?? 'Clinic appointment'));
    $doctor = trim((string) ($details['doctor_name'] ?? ''));
    $doctorText = $doctor !== '' ? ' with ' . $doctor : '';
    $targetUrl = 'view_appointments.php?highlight=' . $appointmentId;

    $templates = [
        'booked' => [
            'appointment_booked',
            'Appointment request sent',
            "{$services}{$doctorText} is scheduled for {$schedule}. Please wait for clinic approval.",
        ],
        'confirmed' => [
            'appointment_confirmed',
            'Appointment approved',
            "Your {$services}{$doctorText} appointment is confirmed for {$schedule}.",
        ],
        'cancelled' => [
            'appointment_cancelled',
            'Appointment cancelled',
            "Your {$services}{$doctorText} appointment for {$schedule} was cancelled.",
        ],
        'completed' => [
            'appointment_completed',
            'Visit completed',
            "Your {$services}{$doctorText} visit for {$schedule} was marked completed.",
        ],
        'rescheduled' => [
            'appointment_rescheduled',
            'Appointment rescheduled',
            "Your {$services}{$doctorText} appointment was moved to {$schedule}.",
        ],
    ];

    $template = $templates[$eventType] ?? $templates['booked'];
    return create_patient_notification(
        $conn,
        $patientId,
        $template[0],
        $template[1],
        $template[2],
        $appointmentId,
        $targetUrl
    );
}

function fetch_patient_notifications(mysqli $conn, int $patientId, int $limit = 5): array {
    if ($patientId <= 0) {
        return [];
    }

    init_patient_notifications($conn);
    $limit = max(1, min(20, $limit));
    $stmt = $conn->prepare(
        "SELECT id, notification_type, title, message, related_appointment_id, target_url, created_at, read_at
         FROM patient_notifications
         WHERE patient_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function count_unread_patient_notifications(mysqli $conn, int $patientId): int {
    if ($patientId <= 0) {
        return 0;
    }

    init_patient_notifications($conn);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM patient_notifications WHERE patient_id = ? AND read_at IS NULL'
    );
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function mark_patient_notifications_read(mysqli $conn, int $patientId): bool {
    if ($patientId <= 0) {
        return false;
    }

    init_patient_notifications($conn);
    $stmt = $conn->prepare(
        'UPDATE patient_notifications SET read_at = NOW() WHERE patient_id = ? AND read_at IS NULL'
    );
    $stmt->bind_param('i', $patientId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

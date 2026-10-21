<?php
require_once __DIR__ . '/appointment_booking.php';

function appointment_reschedule_init_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS appointment_reschedule_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        patient_id INT NOT NULL,
        requested_by INT NOT NULL,
        original_date DATE DEFAULT NULL,
        original_time TIME DEFAULT NULL,
        requested_date DATE NOT NULL,
        requested_time TIME NOT NULL,
        reason VARCHAR(300) DEFAULT NULL,
        reason_details TEXT DEFAULT NULL,
        requested_by_role VARCHAR(20) NOT NULL DEFAULT 'patient',
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        rejection_reason VARCHAR(500) DEFAULT NULL,
        rejection_details TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_reschedule_request_appointment (appointment_id, status, id),
        INDEX idx_reschedule_request_patient (patient_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!dbColumnExists($conn, 'appointment_reschedule_requests', 'original_date')) {
        $conn->query("ALTER TABLE appointment_reschedule_requests ADD COLUMN original_date DATE DEFAULT NULL AFTER requested_by");
    }
    if (!dbColumnExists($conn, 'appointment_reschedule_requests', 'original_time')) {
        $conn->query("ALTER TABLE appointment_reschedule_requests ADD COLUMN original_time TIME DEFAULT NULL AFTER original_date");
    }
    if (!dbColumnExists($conn, 'appointment_reschedule_requests', 'reason')) {
        $conn->query("ALTER TABLE appointment_reschedule_requests ADD COLUMN reason VARCHAR(300) DEFAULT NULL AFTER requested_time");
    }
    if (!dbColumnExists($conn, 'appointment_reschedule_requests', 'rejection_reason')) {
        $conn->query("ALTER TABLE appointment_reschedule_requests ADD COLUMN rejection_reason VARCHAR(500) DEFAULT NULL AFTER reviewed_at");
    }
    if (!dbColumnExists($conn, 'appointment_reschedule_requests', 'reason_details')) {
        $conn->query("ALTER TABLE appointment_reschedule_requests ADD COLUMN reason_details TEXT DEFAULT NULL AFTER reason");
    }
    if (!dbColumnExists($conn, 'appointment_reschedule_requests', 'requested_by_role')) {
        $conn->query("ALTER TABLE appointment_reschedule_requests ADD COLUMN requested_by_role VARCHAR(20) NOT NULL DEFAULT 'patient' AFTER reason_details");
    }
    if (!dbColumnExists($conn, 'appointment_reschedule_requests', 'rejection_details')) {
        $conn->query("ALTER TABLE appointment_reschedule_requests ADD COLUMN rejection_details TEXT DEFAULT NULL AFTER rejection_reason");
    }
    $conn->query("UPDATE appointment_reschedule_requests r
        INNER JOIN users u ON u.id = r.requested_by
        SET r.requested_by_role = COALESCE(NULLIF(r.requested_by_role, ''), u.role)
        WHERE r.requested_by_role IS NULL OR r.requested_by_role = '' OR r.requested_by_role = 'patient'");
    $conn->query("UPDATE appointment_reschedule_requests r
        INNER JOIN appointments a ON a.id = r.appointment_id
        SET r.original_date = COALESCE(r.original_date, a.appointment_date),
            r.original_time = COALESCE(r.original_time, a.appointment_time)
        WHERE r.original_date IS NULL OR r.original_time IS NULL");
}

function appointment_reschedule_reasons(string $role): array {
    $role = strtolower(trim($role));
    if ($role === 'patient') {
        return [
            'Schedule conflict',
            'Medical appointment or emergency',
            'Transportation problem',
            'Work or school conflict',
            'Personal reason',
            'Other reason',
        ];
    }
    return [
        'Doctor unavailable',
        'Clinic schedule adjustment',
        'Clinic closed or emergency closure',
        'Schedule conflict',
        'Service unavailable',
        'Other reason',
    ];
}

function appointment_reschedule_reason_is_valid(string $reason, string $role): bool {
    return in_array(trim($reason), appointment_reschedule_reasons($role), true);
}

function appointment_reschedule_pending_join_sql(): string {
    return "LEFT JOIN (
        SELECT r.*
        FROM appointment_reschedule_requests r
        INNER JOIN (
            SELECT appointment_id, MAX(id) AS id
            FROM appointment_reschedule_requests
            WHERE status = 'pending'
            GROUP BY appointment_id
        ) latest ON latest.id = r.id
    ) rr ON rr.appointment_id = a.id";
}

function appointment_reschedule_rejected_join_sql(): string {
    return "LEFT JOIN (
        SELECT r.*
        FROM appointment_reschedule_requests r
        INNER JOIN (
            SELECT appointment_id, MAX(id) AS id
            FROM appointment_reschedule_requests
            WHERE status = 'rejected'
            GROUP BY appointment_id
        ) latest ON latest.id = r.id
    ) rrh ON rrh.appointment_id = a.id";
}

function appointment_reschedule_admin_join_sql(): string {
    return "LEFT JOIN (
        SELECT r.*
        FROM appointment_reschedule_requests r
        INNER JOIN (
            SELECT appointment_id, MAX(id) AS id
            FROM appointment_reschedule_requests
            WHERE status = 'approved' AND requested_by_role = 'admin'
            GROUP BY appointment_id
        ) latest ON latest.id = r.id
    ) rra ON rra.appointment_id = a.id";
}

function appointment_reschedule_normalize_time(string $time): string {
    $time = trim($time);
    return strlen($time) === 5 ? $time . ':00' : $time;
}

function appointment_reschedule_at(string $date, string $time): ?DateTimeImmutable {
    $normalizedTime = appointment_reschedule_normalize_time($time);
    $dateTime = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $date . ' ' . $normalizedTime,
        new DateTimeZone('Asia/Manila')
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dateTime || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
        return null;
    }
    return $dateTime->format('Y-m-d H:i:s') === $date . ' ' . $normalizedTime ? $dateTime : null;
}

function appointment_reschedule_is_eligible(array $appointment, ?DateTimeImmutable $now = null): bool {
    if (strtolower((string) ($appointment['status'] ?? '')) !== 'confirmed') {
        return false;
    }
    if (in_array(strtolower((string) ($appointment['queue_status'] ?? '')), ['serving', 'completed'], true)) {
        return false;
    }
    $appointmentAt = appointment_reschedule_at(
        (string) ($appointment['appointment_date'] ?? ''),
        (string) ($appointment['appointment_time'] ?? '')
    );
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    return $appointmentAt instanceof DateTimeImmutable && $appointmentAt > $now;
}

function appointment_reschedule_is_pending_editable(array $appointment, ?DateTimeImmutable $now = null): bool {
    if (strtolower((string) ($appointment['status'] ?? '')) !== 'pending') {
        return false;
    }
    if (in_array(strtolower((string) ($appointment['queue_status'] ?? '')), ['serving', 'completed'], true)) {
        return false;
    }
    $appointmentAt = appointment_reschedule_at(
        (string) ($appointment['appointment_date'] ?? ''),
        (string) ($appointment['appointment_time'] ?? '')
    );
    $now = $now ?: new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    return $appointmentAt instanceof DateTimeImmutable && $appointmentAt > $now;
}

function appointment_reschedule_is_patient_editable(array $appointment, ?DateTimeImmutable $now = null): bool {
    return appointment_reschedule_is_eligible($appointment, $now)
        || appointment_reschedule_is_pending_editable($appointment, $now);
}

function appointment_reschedule_slot_message(mysqli $conn, array $appointment, string $date, string $time, bool $checkCapacity = true): ?string {
    $date = trim($date);
    $time = appointment_reschedule_normalize_time($time);
    $dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$dateObject || (is_array($dateErrors) && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0)) || $dateObject->format('Y-m-d') !== $date) {
        return 'Choose a valid new appointment date.';
    }
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time) || !appointment_reschedule_at($date, $time)) {
        return 'Choose a valid new appointment time.';
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $appointmentAt = appointment_reschedule_at($date, $time);
    if (!$appointmentAt || $appointmentAt <= $now) {
        return 'The new appointment schedule must be in the future.';
    }

    $bookingType = strtolower((string) ($appointment['booking_type'] ?? ''));
    if ($bookingType === 'ultrasound' && !appointment_ultrasound_is_available_date($date)) {
        return 'Ultra sound appointments are available on Wednesday and Saturday, except clinic holidays.';
    }
    if ($bookingType !== 'consultation' && !appointment_clinic_is_open_at($date, $time)) {
        return 'The clinic is open Monday to Saturday, from 8:00 AM to 5:00 PM, except clinic holidays.';
    }
    if ($bookingType === 'consultation' && !user_is_doctor_available_at($conn, (int) ($appointment['doctor_id'] ?? 0), $date, $time)) {
        return 'The selected doctor is not available on that schedule. Please choose another date or time.';
    }

    $patientId = (int) ($appointment['patient_id'] ?? 0);
    $appointmentId = (int) ($appointment['id'] ?? 0);
    $duplicate = $conn->prepare(
        "SELECT id FROM appointments
         WHERE patient_id = ? AND appointment_date = ? AND appointment_time = ?
           AND status <> 'cancelled' AND id <> ? LIMIT 1"
    );
    if (!$duplicate) {
        return 'The selected schedule could not be checked.';
    }
    $duplicate->bind_param('issi', $patientId, $date, $time, $appointmentId);
    $duplicate->execute();
    $hasDuplicate = (bool) $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($hasDuplicate) {
        return 'You already have another appointment at that date and time.';
    }

    if (!$checkCapacity) {
        return null;
    }
    $capacitySql = "SELECT COUNT(*) AS total FROM appointments
                    WHERE appointment_date = ? AND status <> 'cancelled' AND id <> ?";
    $bookingType = strtolower((string) ($appointment['booking_type'] ?? ''));
    if ($bookingType === 'consultation') {
        $capacitySql .= " AND booking_type = 'consultation' AND doctor_id = ?";
        $capacityLimit = appointment_doctor_daily_limit();
    } elseif ($bookingType === 'ultrasound') {
        $capacitySql .= " AND booking_type = 'ultrasound'";
        $capacityLimit = appointment_ultrasound_daily_limit();
    } else {
        $capacitySql .= " AND booking_type IN ('package', 'individual')";
        $capacityLimit = appointment_lab_daily_limit();
    }
    $capacity = $conn->prepare($capacitySql);
    if (!$capacity) {
        return 'The selected schedule capacity could not be checked.';
    }
    if ($bookingType === 'consultation') {
        $doctorId = (int) ($appointment['doctor_id'] ?? 0);
        $capacity->bind_param('sii', $date, $appointmentId, $doctorId);
    } else {
        $capacity->bind_param('si', $date, $appointmentId);
    }
    $capacity->execute();
    $booked = (int) ($capacity->get_result()->fetch_assoc()['total'] ?? 0);
    $capacity->close();
    if ($booked >= $capacityLimit) {
        $label = $bookingType === 'consultation' ? 'The selected doctor' : ($bookingType === 'ultrasound' ? 'Ultra sound appointments' : 'Laboratory appointments');
        return $label . ' are fully booked on the selected date. Please choose another date.';
    }
    return null;
}

function appointment_reschedule_request_date_label(string $date, string $time): string {
    $timestamp = strtotime(trim($date . ' ' . $time));
    return $timestamp ? date('M j, Y', $timestamp) . ' at ' . date('g:i A', $timestamp) : 'the selected schedule';
}

function appointment_reschedule_lock_name(array $appointment, string $date): string {
    $bookingType = strtolower((string) ($appointment['booking_type'] ?? ''));
    $prefix = $bookingType === 'consultation'
        ? 'clinic-doctor-capacity-' . (int) ($appointment['doctor_id'] ?? 0)
        : 'clinic-appointment-capacity';
    return $prefix . '-' . $date;
}

function appointment_reschedule_release_lock(mysqli $conn, string $lockName): void {
    if ($lockName === '') {
        return;
    }
    $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
    if ($release) {
        $release->bind_param('s', $lockName);
        $release->execute();
        $release->close();
    }
}

function appointment_reschedule_notify_patient(mysqli $conn, array $request, string $event): void {
    $patientId = (int) ($request['patient_id'] ?? 0);
    $appointmentId = (int) ($request['appointment_id'] ?? 0);
    if ($patientId <= 0 || $appointmentId <= 0) return;
    $original = appointment_reschedule_request_date_label((string) ($request['appointment_date'] ?? ''), (string) ($request['appointment_time'] ?? ''));
    $requested = appointment_reschedule_request_date_label((string) ($request['requested_date'] ?? ''), (string) ($request['requested_time'] ?? ''));
    $reason = trim((string) ($request['reason'] ?? ''));
    $details = trim((string) ($request['reason_details'] ?? ''));
    $reasonText = $reason !== '' ? $reason : 'Not provided';
    if ($details !== '') $reasonText .= ' - ' . $details;
    if ($event === 'admin_rescheduled') {
        $title = 'Appointment rescheduled by the clinic';
        $message = "The clinic moved your appointment from {$original} to {$requested}. Reason: {$reasonText}.";
    } elseif ($event === 'requested') {
        $title = 'Reschedule request submitted';
        $message = "Your request to move the appointment from {$original} to {$requested} is pending approval. Your original confirmed schedule remains active. Reason: {$reasonText}.";
    } elseif ($event === 'approved') {
        $title = 'Reschedule request approved';
        $message = "Your reschedule request was approved. Your confirmed appointment is now scheduled for {$requested}.";
    } else {
        $title = 'Reschedule request rejected';
        $rejectionReason = trim((string) ($request['rejection_reason'] ?? ''));
        $rejectionDetails = trim((string) ($request['rejection_details'] ?? ''));
        $rejectionReason = $rejectionReason !== '' ? $rejectionReason : 'Not provided';
        if ($rejectionDetails !== '') $rejectionReason .= ' - ' . $rejectionDetails;
        $message = "Your reschedule request to {$requested} was rejected. Your original confirmed appointment on {$original} remains active. Reason: {$rejectionReason}.";
    }
    create_patient_notification($conn, $patientId, 'appointment_reschedule_' . $event, $title, $message, $appointmentId, 'patients_view_appointments.php?highlight=' . $appointmentId);
}

function appointment_reschedule_notify_reviewer(mysqli $conn, array $request, string $event): void {
    $appointmentId = (int) ($request['appointment_id'] ?? 0);
    $patientId = (int) ($request['patient_id'] ?? 0);
    $bookingType = strtolower((string) ($request['booking_type'] ?? ''));
    $patientName = trim((string) ($request['patient_name'] ?? 'Patient'));
    $requested = appointment_reschedule_request_date_label((string) ($request['requested_date'] ?? ''), (string) ($request['requested_time'] ?? ''));
    $reason = trim((string) ($request['reason'] ?? ''));
    $details = trim((string) ($request['reason_details'] ?? ''));
    $reasonText = $reason !== '' ? $reason : 'Not provided';
    if ($details !== '') $reasonText .= ' - ' . $details;
    if ($event === 'admin_rescheduled') {
        return;
    } elseif ($event === 'requested') {
        $title = 'Reschedule request pending';
        $message = "{$patientName} requested to move an appointment to {$requested}. Reason: {$reasonText}. Please review the request.";
    } elseif ($event === 'approved') {
        $title = 'Reschedule request approved';
        $message = "{$patientName}'s reschedule request for {$requested} was approved.";
    } else {
        $title = 'Reschedule request rejected';
        $rejectionReason = trim((string) ($request['rejection_reason'] ?? ''));
        $rejectionDetails = trim((string) ($request['rejection_details'] ?? ''));
        $rejectionText = $rejectionReason !== '' ? $rejectionReason : 'Not provided';
        if ($rejectionDetails !== '') $rejectionText .= ' - ' . $rejectionDetails;
        $message = "{$patientName}'s reschedule request for {$requested} was rejected. Reason: {$rejectionText}.";
    }
    if ($bookingType === 'consultation') {
        create_clinic_notification($conn, null, (int) ($request['doctor_id'] ?? 0), 'appointment_reschedule_' . $event, $title, $message, $appointmentId, 'doctor_view_appointments.php?highlight=' . $appointmentId);
    } else {
        create_admin_notification($conn, 'appointment_reschedule_' . $event, $title, $message, $patientId, $appointmentId, 'admin_view_appointments.php?highlight=' . $appointmentId);
    }
}

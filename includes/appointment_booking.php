<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/notification_settings.php';
require_once __DIR__ . '/appointment_capacity.php';
require_once __DIR__ . '/doctor_schedule.php';
require_once __DIR__ . '/lab_services_seed_data.php';
require_once __DIR__ . '/admin_notifications.php';
require_once __DIR__ . '/patient_notifications.php';
require_once __DIR__ . '/clinic_notifications.php';

function appointment_doctor_daily_limit(): int {
    return (int) appointment_capacity_settings()['doctor_limit'];
}

function appointment_lab_daily_limit(): int {
    return (int) appointment_capacity_settings()['laboratory_limit'];
}

function appointment_consultation_daily_limit(): int {
    return appointment_doctor_daily_limit();
}

function appointment_ultrasound_daily_limit(): int {
    return (int) appointment_capacity_settings()['ultrasound_limit'];
}

function appointment_consultation_daily_count(mysqli $conn, string $date): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE appointment_date = ?
           AND booking_type = 'consultation'
           AND status <> 'cancelled'"
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

/** @return array<string,int> */
function appointment_consultation_daily_counts_between(mysqli $conn, string $dateFrom, string $dateTo): array {
    $counts = [];
    $stmt = $conn->prepare(
        "SELECT appointment_date, COUNT(*) AS total
         FROM appointments
         WHERE appointment_date >= ?
           AND appointment_date < ?
           AND booking_type = 'consultation'
           AND status <> 'cancelled'
         GROUP BY appointment_date"
    );
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $date = (string) ($row['appointment_date'] ?? '');
        if ($date !== '') {
            $counts[$date] = (int) ($row['total'] ?? 0);
        }
    }
    $stmt->close();
    return $counts;
}

/** @return array{booked:int,remaining:int,limit:int,is_full:bool} */
function appointment_consultation_day_capacity(mysqli $conn, string $date): array {
    $booked = appointment_consultation_daily_count($conn, $date);
    $limit = appointment_consultation_daily_limit();
    return [
        'booked' => $booked,
        'remaining' => max(0, $limit - $booked),
        'limit' => $limit,
        'is_full' => $booked >= $limit,
    ];
}

function appointment_doctor_daily_count(mysqli $conn, int $doctorId, string $date): int {
    if ($doctorId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE doctor_id = ?
           AND appointment_date = ?
           AND booking_type = 'consultation'
           AND status <> 'cancelled'"
    );
    $stmt->bind_param('is', $doctorId, $date);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

/**
 * @return array<int,array<string,int>>
 */
function appointment_doctor_daily_counts_between(
    mysqli $conn,
    string $dateFrom,
    string $dateTo,
    array $doctorIds = []
): array {
    $counts = [];
    $doctorIds = array_values(array_unique(array_filter(
        array_map('intval', $doctorIds),
        static fn (int $id): bool => $id > 0
    )));

    $sql = "SELECT doctor_id, appointment_date, COUNT(*) AS total
            FROM appointments
            WHERE doctor_id IS NOT NULL
              AND appointment_date >= ?
              AND appointment_date < ?
              AND booking_type = 'consultation'
              AND status <> 'cancelled'";
    if ($doctorIds) {
        $sql .= ' AND doctor_id IN (' . implode(',', $doctorIds) . ')';
    }
    $sql .= ' GROUP BY doctor_id, appointment_date';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $doctorId = (int) ($row['doctor_id'] ?? 0);
        $date = (string) ($row['appointment_date'] ?? '');
        if ($doctorId > 0 && $date !== '') {
            $counts[$doctorId][$date] = (int) ($row['total'] ?? 0);
        }
    }
    $stmt->close();
    return $counts;
}

function appointment_doctor_day_capacity(mysqli $conn, int $doctorId, string $date): array {
    $booked = appointment_doctor_daily_count($conn, $doctorId, $date);
    $limit = appointment_doctor_daily_limit();
    return [
        'booked' => $booked,
        'remaining' => max(0, $limit - $booked),
        'limit' => $limit,
        'is_full' => $booked >= $limit,
    ];
}

function appointment_lab_daily_count(mysqli $conn, string $date): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE appointment_date = ?
           AND booking_type IN ('package', 'individual')
           AND status <> 'cancelled'"
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

/**
 * @return array<string,int>
 */
function appointment_lab_daily_counts_between(mysqli $conn, string $dateFrom, string $dateTo): array {
    $counts = [];
    $stmt = $conn->prepare(
        "SELECT appointment_date, COUNT(*) AS total
         FROM appointments
         WHERE appointment_date >= ?
           AND appointment_date < ?
           AND booking_type IN ('package', 'individual')
           AND status <> 'cancelled'
         GROUP BY appointment_date"
    );
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $date = (string) ($row['appointment_date'] ?? '');
        if ($date !== '') {
            $counts[$date] = (int) ($row['total'] ?? 0);
        }
    }
    $stmt->close();
    return $counts;
}

function appointment_lab_day_capacity(mysqli $conn, string $date): array {
    $booked = appointment_lab_daily_count($conn, $date);
    $limit = appointment_lab_daily_limit();
    return [
        'booked' => $booked,
        'remaining' => max(0, $limit - $booked),
        'limit' => $limit,
        'is_full' => $booked >= $limit,
    ];
}

function appointment_ultrasound_daily_count(mysqli $conn, string $date): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 0;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM appointments
         WHERE appointment_date = ?
           AND booking_type = 'ultrasound'
           AND status <> 'cancelled'"
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

/** @return array<string,int> */
function appointment_ultrasound_daily_counts_between(mysqli $conn, string $dateFrom, string $dateTo): array {
    $counts = [];
    $stmt = $conn->prepare(
        "SELECT appointment_date, COUNT(*) AS total
         FROM appointments
         WHERE appointment_date >= ?
           AND appointment_date < ?
           AND booking_type = 'ultrasound'
           AND status <> 'cancelled'
         GROUP BY appointment_date"
    );
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $date = (string) ($row['appointment_date'] ?? '');
        if ($date !== '') {
            $counts[$date] = (int) ($row['total'] ?? 0);
        }
    }
    $stmt->close();
    return $counts;
}

/** @return array{booked:int,remaining:int,limit:int,is_full:bool} */
function appointment_ultrasound_day_capacity(mysqli $conn, string $date): array {
    $booked = appointment_ultrasound_daily_count($conn, $date);
    $limit = appointment_ultrasound_daily_limit();
    return [
        'booked' => $booked,
        'remaining' => max(0, $limit - $booked),
        'limit' => $limit,
        'is_full' => $booked >= $limit,
    ];
}

function appointment_init_queue_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS clinic_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        queue_date DATE NOT NULL,
        queue_type ENUM('walk_in','online') NOT NULL DEFAULT 'online',
        queue_number VARCHAR(12) NOT NULL,
        patient_id INT NOT NULL,
        appointment_id INT DEFAULT NULL,
        service VARCHAR(160) NOT NULL DEFAULT 'General Consultation',
        status ENUM('waiting','serving','completed') NOT NULL DEFAULT 'waiting',
        time_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        time_called DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        UNIQUE KEY unique_daily_queue (queue_date, queue_number),
        KEY idx_queue_day_status (queue_date, status, queue_type, id),
        KEY idx_queue_patient (patient_id),
        KEY idx_queue_appointment (appointment_id)
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
}

function appointment_next_online_queue_number(mysqli $conn, string $date): string {
    $type = 'online';
    $stmt = $conn->prepare("SELECT queue_number FROM clinic_queue WHERE queue_date = ? AND queue_type = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('ss', $date, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if ($row && preg_match('/-(\d+)$/', (string) $row['queue_number'], $matches)) {
        $next = (int) $matches[1] + 1;
    }
    return 'O-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

function appointment_create_online_queue(mysqli $conn, int $appointmentId, int $patientId, string $date, string $service): string {
    $existingStmt = $conn->prepare("SELECT queue_number FROM clinic_queue WHERE appointment_id = ? LIMIT 1");
    $existingStmt->bind_param('i', $appointmentId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();
    if ($existing) {
        return (string) $existing['queue_number'];
    }

    $queueNumber = appointment_next_online_queue_number($conn, $date);
    $queueType = 'online';
    $insertStmt = $conn->prepare("INSERT INTO clinic_queue (queue_date, queue_type, queue_number, patient_id, appointment_id, service, status) VALUES (?, ?, ?, ?, ?, ?, 'waiting')");
    $insertStmt->bind_param('sssiis', $date, $queueType, $queueNumber, $patientId, $appointmentId, $service);
    if (!$insertStmt->execute()) {
        throw new RuntimeException('Could not generate the queue number.');
    }
    $insertStmt->close();

    return $queueNumber;
}

function appointment_mask_email(string $email): string {
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }

    [$name, $domain] = explode('@', $email, 2);
    $visible = substr($name, 0, min(2, strlen($name)));
    return $visible . str_repeat('*', max(1, strlen($name) - strlen($visible))) . '@' . $domain;
}

function appointment_unit_price(array $service, string $channel): float {
    if (
        $channel === 'home'
        && array_key_exists('home_service_price', $service)
        && $service['home_service_price'] !== null
        && (float) $service['home_service_price'] > 0
    ) {
        return (float) $service['home_service_price'];
    }

    return (float) $service['opd_price'];
}

function appointment_fetch_services(mysqli $conn, array $serviceIds): array {
    $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), static fn ($id) => $id > 0)));
    if (empty($serviceIds)) {
        return [];
    }

    $idList = implode(',', $serviceIds);
    $result = $conn->query(
        "SELECT id, name, category, description, included_tests, opd_price,
                home_service_price, is_package
         FROM lab_services
         WHERE is_active = 1 AND id IN ($idList)"
    );

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function appointment_clinic_is_open_at(string $date, string $time): bool {
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return false;
    }

    $dayOfWeek = (int) date('N', $timestamp);
    if ($dayOfWeek < 1 || $dayOfWeek > 6) {
        return false;
    }

    $normalizedTime = substr(trim($time), 0, 5);
    return $normalizedTime >= '08:00' && $normalizedTime <= '17:00';
}

function appointment_ultrasound_is_available_date(string $date): bool {
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return false;
    }

    return in_array((int) date('N', $timestamp), [3, 6], true);
}

function appointment_validate_payload(mysqli $conn, int $patientId, array $payload): array {
    $type = (string) ($payload['type'] ?? '');
    if (!in_array($type, ['package', 'individual', 'consultation', 'ultrasound'], true)) {
        return ['ok' => false, 'error' => 'The selected appointment type is invalid. Please review your booking again.'];
    }

    $date = trim((string) ($payload['appointment_date'] ?? ''));
    $time = trim((string) ($payload['appointment_time'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
        return ['ok' => false, 'error' => 'The appointment schedule is incomplete. Please choose the date and time again.'];
    }

    try {
        $timezone = new DateTimeZone('Asia/Manila');
        $appointmentAt = new DateTimeImmutable($date . ' ' . $time, $timezone);
        $now = new DateTimeImmutable('now', $timezone);
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'The appointment schedule could not be read. Please choose it again.'];
    }

    if ($appointmentAt <= $now) {
        return ['ok' => false, 'error' => 'The selected appointment time has already passed. Please choose a future schedule.'];
    }
    if ($type === 'ultrasound' && !appointment_ultrasound_is_available_date($date)) {
        return ['ok' => false, 'error' => 'Ultra sound appointments are available on Wednesday and Saturday only. Please choose another date.'];
    }
    if ($type !== 'consultation' && !appointment_clinic_is_open_at($date, $time)) {
        return ['ok' => false, 'error' => 'The clinic is open Monday to Saturday, from 8:00 AM to 5:00 PM. Please choose a schedule within clinic hours.'];
    }

    $serviceIds = [];
    $services = [];
    if (in_array($type, ['package', 'individual'], true)) {
        $serviceIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($payload['service_ids'] ?? [])),
            static fn ($id) => $id > 0
        )));
        $services = appointment_fetch_services($conn, $serviceIds);
        if (empty($serviceIds) || count($services) !== count($serviceIds)) {
            return ['ok' => false, 'error' => 'One or more selected services are no longer available. Please review your booking again.'];
        }

        foreach ($services as $service) {
            if (!lab_booking_service_matches_type($service, $type)) {
                return ['ok' => false, 'error' => 'One of the selected services does not match this booking type.'];
            }
        }
    }

    $doctorId = (int) ($payload['doctor_id'] ?? 0);
    $doctorName = '';
    if ($type === 'consultation') {
        if ($doctorId <= 0) {
            return ['ok' => false, 'error' => 'Please choose a doctor for your consultation.'];
        }

        $doctorStmt = $conn->prepare(
           "SELECT " . dbUsersNameExpression() . " AS full_name
             FROM users
             WHERE id = ? AND role = 'doctor' AND COALESCE(is_active, 1) = 1
             LIMIT 1"
        );
        $doctorStmt->bind_param('i', $doctorId);
        $doctorStmt->execute();
        $doctorRow = $doctorStmt->get_result()->fetch_assoc();
        $doctorStmt->close();
        if (!$doctorRow) {
            return ['ok' => false, 'error' => 'The selected doctor is no longer available. Please choose another doctor.'];
        }
        if (!user_is_doctor_available_at($conn, $doctorId, $date, $time)) {
            return ['ok' => false, 'error' => 'The selected doctor is not available on that schedule. Please choose another date.'];
        }

        $doctorName = (string) ($doctorRow['full_name'] ?? 'Selected doctor');
        $capacity = appointment_doctor_day_capacity($conn, $doctorId, $date);
        if ($capacity['is_full']) {
            return [
                'ok' => false,
                'error' => 'This doctor is fully booked on the selected date ('
                    . $capacity['booked'] . '/' . $capacity['limit']
                    . ' bookings). Please choose another date.',
            ];
        }
    } else {
        $capacity = $type === 'ultrasound'
            ? appointment_ultrasound_day_capacity($conn, $date)
            : appointment_lab_day_capacity($conn, $date);
        if ($capacity['is_full']) {
            return [
                'ok' => false,
                'error' => ($type === 'ultrasound' ? 'Ultra sound appointments' : 'Laboratory appointments')
                    . ' are fully booked on the selected date ('
                    . $capacity['booked'] . '/' . $capacity['limit']
                    . ' bookings). Please choose another date.',
            ];
        }
        $doctorId = 0;
    }

    $duplicate = $conn->prepare(
        "SELECT id FROM appointments
         WHERE patient_id = ? AND appointment_date = ? AND appointment_time = ?
           AND status != 'cancelled'
         LIMIT 1"
    );
    $duplicate->bind_param('iss', $patientId, $date, $time);
    $duplicate->execute();
    $duplicateRow = $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($duplicateRow) {
        return ['ok' => false, 'error' => 'You already have an appointment at this date and time.'];
    }

    $userStmt = $conn->prepare("SELECT " . dbUsersNameExpression() . " AS full_name, email, phone FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $userStmt->bind_param('i', $patientId);
    $userStmt->execute();
    $patient = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$patient) {
        return ['ok' => false, 'error' => 'Your patient account could not be found. Please sign in again.'];
    }
    $patientEmailValid = filter_var((string) ($patient['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $patientPhoneValid = clinic_sms_normalize_phone((string) ($patient['phone'] ?? '')) !== null;
    if (!$patientEmailValid && !$patientPhoneValid) {
        return ['ok' => false, 'error' => 'Add a valid Philippine mobile number to your patient profile before booking an appointment.'];
    }

    $channel = 'opd';
    $serviceNames = $type === 'consultation'
        ? ['Doctor consultation']
        : ($type === 'ultrasound' ? ['Ultra sound'] : []);
    $total = 0.0;
    foreach ($services as $service) {
        $serviceNames[] = (string) $service['name'];
        $total += appointment_unit_price($service, $channel);
    }

    return [
        'ok' => true,
        'booking' => [
            'type' => $type,
            'service_ids' => $serviceIds,
            'doctor_id' => $doctorId > 0 ? $doctorId : null,
            'price_channel' => $channel,
            'appointment_date' => $date,
            'appointment_time' => substr($time, 0, 5),
        ],
        'services' => $services,
        'service_names' => $serviceNames,
        'total' => $total,
        'patient' => $patient,
        'doctor_name' => $doctorName,
    ];
}

function appointment_email_layout(string $title, string $intro, array $rows, string $footer): array {
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeIntro = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');
    $rowHtml = '';
    $textRows = [];
    foreach ($rows as $label => $value) {
        $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
        $safeValue = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $rowHtml .= '<tr><td style="padding:9px 10px;color:#5d7280;border-bottom:1px solid #e4eef4">'
            . $safeLabel . '</td><td style="padding:9px 10px;font-weight:700;color:#073b4c;border-bottom:1px solid #e4eef4">'
            . $safeValue . '</td></tr>';
        $textRows[] = $label . ': ' . $value;
    }

    $html = '<!DOCTYPE html><html><body style="margin:0;background:#eef7fc;font-family:Arial,sans-serif;color:#073b4c">'
        . '<div style="max-width:600px;margin:28px auto;background:#fff;border:1px solid #d5e9f3;border-radius:12px;padding:28px">'
        . '<h1 style="font-size:24px;color:#0b4f80;margin:0 0 14px">' . $safeTitle . '</h1>'
        . '<p style="line-height:1.65">' . $safeIntro . '</p>'
        . '<table style="width:100%;border-collapse:collapse;background:#f8fcfe;border:1px solid #e4eef4;border-radius:8px">'
        . $rowHtml . '</table>'
        . '<p style="line-height:1.65;margin:20px 0 0">' . htmlspecialchars($footer, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="font-size:13px;color:#718692;margin-top:22px">Globalife Medical Laboratory &amp; Polyclinic</p>'
        . '</div></body></html>';

    $text = $title . "\n\n" . $intro . "\n\n" . implode("\n", $textRows) . "\n\n" . $footer;
    return ['html' => $html, 'text' => $text];
}

function appointment_send_booking_email(array $details): array {
    if (!clinic_notification_enabled('appointment_confirmation')) {
        return clinic_notification_disabled_result('Appointment confirmation');
    }
    $dateLabel = date('F j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $isClinicFeeAtDesk = in_array(($details['booking_type'] ?? ''), ['consultation', 'ultrasound'], true);
    $rows = [
        'Reference number' => '#' . (int) $details['appointment_id'],
        'Queue number' => (string) ($details['queue_number'] ?? 'Pending'),
        'Date' => $dateLabel,
        'Time' => $timeLabel,
        'Services' => implode(', ', (array) $details['service_names']),
        'Status' => 'Pending clinic confirmation',
    ];
    $rows['Payment'] = $isClinicFeeAtDesk
        ? (($details['booking_type'] ?? '') === 'ultrasound' ? 'Ultra sound fee confirmed at clinic' : 'Consultation fee confirmed at clinic')
        : 'Total: PHP ' . number_format((float) $details['total'], 2);
    if (!empty($details['doctor_name'])) {
        $rows['Doctor'] = (string) $details['doctor_name'];
    }

    $content = appointment_email_layout(
        'Appointment request received',
        'Hello ' . (string) $details['patient_name'] . '. Your verification code was accepted and your appointment request was submitted successfully.',
        $rows,
        'The clinic will review your request. Payment is made at the clinic. You will receive a reminder before the appointment after the clinic confirms it.'
    );

    return clinic_send_email(
        (string) $details['email'],
        (string) $details['patient_name'],
        'Globalife appointment request #' . (int) $details['appointment_id'],
        $content['html'],
        $content['text']
    );
}

function appointment_send_booking_sms(array $details): array {
    if (!clinic_notification_enabled('appointment_confirmation')) {
        return clinic_notification_disabled_result('Appointment confirmation');
    }
    $dateLabel = date('M j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $message = 'Globalife: Appointment request #' . (int) $details['appointment_id']
        . ' queue ' . (string) ($details['queue_number'] ?? '')
        . ' received for ' . $dateLabel . ' at ' . $timeLabel
        . '. Please wait for clinic confirmation.';

    return clinic_send_sms_message((string) ($details['phone'] ?? ''), $message);
}

function appointment_send_reminder_email(array $details): array {
    if (!clinic_notification_enabled('appointment_reminder')) {
        return clinic_notification_disabled_result('Appointment reminder');
    }
    $dateLabel = date('F j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $rows = [
        'Reference number' => '#' . (int) $details['appointment_id'],
        'Date' => $dateLabel,
        'Time' => $timeLabel,
        'Services' => (string) $details['services'],
    ];
    if (!empty($details['doctor_name'])) {
        $rows['Doctor'] = (string) $details['doctor_name'];
    }

    $content = appointment_email_layout(
        'Appointment reminder',
        'Hello ' . (string) $details['patient_name'] . '. This is a reminder that your confirmed Globalife appointment is approaching.',
        $rows,
        'Please arrive 10-15 minutes early and bring a valid ID, previous medical documents, and any request form you may have.'
    );

    return clinic_send_email(
        (string) $details['email'],
        (string) $details['patient_name'],
        'Reminder: Globalife appointment on ' . $dateLabel,
        $content['html'],
        $content['text']
    );
}

function appointment_send_reminder_sms(array $details): array {
    if (!clinic_notification_enabled('appointment_reminder')) {
        return clinic_notification_disabled_result('Appointment reminder');
    }
    $dateLabel = date('M j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $message = 'Globalife reminder: Appointment #' . (int) $details['appointment_id']
        . ' is on ' . $dateLabel . ' at ' . $timeLabel
        . '. Please arrive 10-15 minutes early.';

    return clinic_send_sms_message((string) ($details['phone'] ?? ''), $message);
}

function appointment_fetch_notification_details(mysqli $conn, int $appointmentId): ?array {
    $stmt = $conn->prepare(
        "SELECT a.id AS appointment_id, a.appointment_date, a.appointment_time, a.booking_type, a.cancellation_reason,
                " . dbUsersNameExpression('p') . " AS patient_name, p.email, p.phone,
                " . dbUsersNameExpression('d') . " AS doctor_name,
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
         GROUP BY a.id, a.appointment_date, a.appointment_time,
                 a.booking_type, a.cancellation_reason,
                 p.first_name, p.middle_name, p.last_name, p.suffix, p.email, p.phone,
                 d.first_name, d.middle_name, d.last_name, d.suffix
         LIMIT 1"
    );
    $stmt->bind_param('i', $appointmentId);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $details ?: null;
}

function appointment_send_clinic_confirmation_email(mysqli $conn, int $appointmentId): array {
    if (!clinic_notification_enabled('appointment_confirmation')) {
        return clinic_notification_disabled_result('Appointment confirmation');
    }
    $details = appointment_fetch_notification_details($conn, $appointmentId);
    if (!$details || !filter_var((string) ($details['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The patient does not have a valid email address.'];
    }

    $dateLabel = date('F j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $rows = [
        'Reference number' => '#' . (int) $details['appointment_id'],
        'Date' => $dateLabel,
        'Time' => $timeLabel,
        'Services' => (string) $details['services'],
        'Status' => 'Confirmed by the clinic',
    ];
    if (!empty($details['doctor_name'])) {
        $rows['Doctor'] = (string) $details['doctor_name'];
    }

    $content = appointment_email_layout(
        'Your appointment is confirmed',
        'Hello ' . (string) $details['patient_name'] . '. The clinic has confirmed your Globalife appointment.',
        $rows,
        'Please arrive 10-15 minutes early. We will also send email and SMS reminders before your appointment.'
    );

    return clinic_send_email(
        (string) $details['email'],
        (string) $details['patient_name'],
        'Confirmed: Globalife appointment #' . (int) $details['appointment_id'],
        $content['html'],
        $content['text']
    );
}

function appointment_send_clinic_confirmation_sms(mysqli $conn, int $appointmentId): array {
    if (!clinic_notification_enabled('appointment_confirmation')) {
        return clinic_notification_disabled_result('Appointment confirmation');
    }
    $details = appointment_fetch_notification_details($conn, $appointmentId);
    if (!$details) {
        return ['ok' => false, 'error' => 'The appointment notification details could not be found.'];
    }

    $dateLabel = date('M j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $message = 'Globalife: Appointment #' . (int) $details['appointment_id']
        . ' is confirmed for ' . $dateLabel . ' at ' . $timeLabel
        . '. Please arrive 10-15 minutes early.';

    return clinic_send_sms_message((string) ($details['phone'] ?? ''), $message);
}

function appointment_send_cancellation_email(mysqli $conn, int $appointmentId): array {
    if (!clinic_notification_enabled('appointment_cancellation')) {
        return clinic_notification_disabled_result('Appointment cancellation');
    }

    $details = appointment_fetch_notification_details($conn, $appointmentId);
    if (!$details || !filter_var((string) ($details['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The patient does not have a valid email address.'];
    }

    $dateLabel = date('F j, Y', strtotime((string) $details['appointment_date']));
    $timeLabel = date('g:i A', strtotime((string) $details['appointment_time']));
    $rows = [
        'Reference number' => '#' . (int) $details['appointment_id'],
        'Date' => $dateLabel,
        'Time' => $timeLabel,
        'Services' => (string) $details['services'],
        'Status' => 'Cancelled',
    ];
    if (trim((string) ($details['cancellation_reason'] ?? '')) !== '') {
        $rows['Reason'] = (string) $details['cancellation_reason'];
    }

    $content = appointment_email_layout(
        'Your appointment was cancelled',
        'Hello ' . (string) $details['patient_name'] . '. Your Globalife appointment has been cancelled.',
        $rows,
        'Please contact the clinic if you need help booking another appointment.'
    );

    return clinic_send_email(
        (string) $details['email'],
        (string) $details['patient_name'],
        'Cancelled: Globalife appointment #' . (int) $details['appointment_id'],
        $content['html'],
        $content['text']
    );
}

function appointment_send_cancellation_sms(mysqli $conn, int $appointmentId): array {
    if (!clinic_notification_enabled('appointment_cancellation')) {
        return clinic_notification_disabled_result('Appointment cancellation');
    }

    $details = appointment_fetch_notification_details($conn, $appointmentId);
    if (!$details) {
        return ['ok' => false, 'error' => 'The appointment notification details could not be found.'];
    }

    $message = 'Globalife: Appointment #' . (int) $details['appointment_id'] . ' on '
        . date('M j, Y', strtotime((string) $details['appointment_date']))
        . ' at ' . date('g:i A', strtotime((string) $details['appointment_time']))
        . ' was cancelled.';
    return clinic_send_sms_message((string) ($details['phone'] ?? ''), $message);
}

function appointment_create_direct(mysqli $conn, int $patientId, array $payload): array {
    $validated = appointment_validate_payload($conn, $patientId, $payload);
    if (!$validated['ok']) {
        return $validated;
    }
    appointment_init_queue_schema($conn);

    $booking = $validated['booking'];
    $serviceNames = $validated['service_names'];
    if (($booking['type'] ?? '') === 'consultation') {
        $notes = 'Doctor consultation | ' . (string) $validated['doctor_name']
            . ' | Consultation fee confirmed at clinic';
    } elseif (($booking['type'] ?? '') === 'ultrasound') {
        $notes = 'Ultra sound | Fee confirmed at clinic';
    } else {
        $notes = 'Services: ' . implode(', ', $serviceNames)
            . ' | Total: PHP ' . number_format((float) $validated['total'], 2);
    }

    $conn->begin_transaction();
    $capacityLockName = '';
    $queueNumber = '';
    try {
        $doctorId = $booking['doctor_id'];
        $appointmentDate = (string) $booking['appointment_date'];
        $appointmentTime = (string) $booking['appointment_time'];
        $bookingType = (string) $booking['type'];
        $total = (float) $validated['total'];
        $priceChannel = (string) $booking['price_channel'];
        if ($bookingType === 'consultation') {
            $capacityLockName = 'clinic-doctor-capacity-' . (int) $doctorId . '-' . $appointmentDate;
            $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
            $lockStmt->bind_param('s', $capacityLockName);
            $lockStmt->execute();
            $lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
            $lockStmt->close();
            if ($lockAcquired !== 1) {
                throw new RuntimeException('The selected date is being updated. Please try again.');
            }

            $capacity = appointment_doctor_day_capacity($conn, (int) $doctorId, $appointmentDate);
            if ($capacity['is_full']) {
                throw new RuntimeException(
                    'This doctor is fully booked on the selected date ('
                    . $capacity['booked'] . '/' . $capacity['limit']
                    . ' bookings). Please choose another date.'
                );
            }
        } elseif (in_array($bookingType, ['package', 'individual', 'ultrasound'], true)) {
            $capacityLockName = 'laboratory-appointment-capacity-' . $appointmentDate;
            $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
            $lockStmt->bind_param('s', $capacityLockName);
            $lockStmt->execute();
            $lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
            $lockStmt->close();
            if ($lockAcquired !== 1) {
                throw new RuntimeException('The selected date is being updated. Please try again.');
            }

            $capacity = appointment_lab_day_capacity($conn, $appointmentDate);
            if ($capacity['is_full']) {
                throw new RuntimeException(
                    ($bookingType === 'ultrasound' ? 'Ultra sound appointments' : 'Laboratory appointments')
                    . ' are fully booked on the selected date ('
                    . $capacity['booked'] . '/' . $capacity['limit']
                    . ' bookings). Please choose another date.'
                );
            }
        }
        if ($doctorId === null) {
            $insert = $conn->prepare(
                "INSERT INTO appointments
                    (patient_id, doctor_id, appointment_date, appointment_time, notes,
                     status, booking_type, total_display_price, price_channel)
                 VALUES (?, NULL, ?, ?, ?, 'pending', ?, ?, ?)"
            );
            $insert->bind_param(
                'issssds',
                $patientId,
                $appointmentDate,
                $appointmentTime,
                $notes,
                $bookingType,
                $total,
                $priceChannel
            );
        } else {
            $insert = $conn->prepare(
                "INSERT INTO appointments
                    (patient_id, doctor_id, appointment_date, appointment_time, notes,
                     status, booking_type, total_display_price, price_channel)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
            );
            $insert->bind_param(
                'iissssds',
                $patientId,
                $doctorId,
                $appointmentDate,
                $appointmentTime,
                $notes,
                $bookingType,
                $total,
                $priceChannel
            );
        }

        if (!$insert->execute()) {
            throw new RuntimeException('Could not save the appointment.');
        }
        $appointmentId = (int) $conn->insert_id;
        $insert->close();

        $line = $conn->prepare(
            'INSERT INTO appointment_services (appointment_id, service_id, unit_price)
             VALUES (?, ?, ?)'
        );
        foreach ($validated['services'] as $service) {
            $serviceId = (int) $service['id'];
            $unitPrice = appointment_unit_price($service, (string) $booking['price_channel']);
            $line->bind_param('iid', $appointmentId, $serviceId, $unitPrice);
            if (!$line->execute()) {
                throw new RuntimeException('Could not save the selected appointment services.');
            }
        }
        $line->close();

        $timezone = new DateTimeZone('Asia/Manila');
        $appointmentAt = new DateTimeImmutable(
            $appointmentDate . ' ' . $appointmentTime,
            $timezone
        );
        $reminderDays = clinic_notification_reminder_days();
        $reminderAt = $appointmentAt->modify('-' . $reminderDays . ' days');
        $now = new DateTimeImmutable('now', $timezone);
        if ($reminderAt < $now) {
            $reminderAt = $now;
        }
        $scheduledFor = $reminderAt->format('Y-m-d H:i:s');
        $reminder = $conn->prepare(
            "INSERT INTO appointment_email_reminders
                (appointment_id, reminder_type, scheduled_for)
             VALUES (?, ?, ?)"
        );
        $reminderType = $reminderDays . '_days';
        $reminder->bind_param('iss', $appointmentId, $reminderType, $scheduledFor);
        if (!$reminder->execute()) {
            throw new RuntimeException('Could not schedule the appointment reminder.');
        }
        $reminder->close();

        $queueService = $bookingType === 'consultation'
            ? 'General Consultation'
            : ($bookingType === 'ultrasound' ? 'Ultra sound' : implode(', ', $serviceNames));
        $queueNumber = appointment_create_online_queue(
            $conn,
            $appointmentId,
            $patientId,
            $appointmentDate,
            $queueService
        );

        $conn->commit();
        if ($capacityLockName !== '') {
            $releaseStmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStmt->bind_param('s', $capacityLockName);
            $releaseStmt->execute();
            $releaseStmt->close();
        }
    } catch (Throwable $e) {
        $conn->rollback();
        if ($capacityLockName !== '') {
            $releaseStmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $releaseStmt->bind_param('s', $capacityLockName);
            $releaseStmt->execute();
            $releaseStmt->close();
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    $details = [
        'appointment_id' => $appointmentId,
        'appointment_date' => $booking['appointment_date'],
        'appointment_time' => $booking['appointment_time'],
        'service_names' => $serviceNames,
        'total' => $validated['total'],
        'patient_name' => (string) $validated['patient']['full_name'],
        'email' => (string) $validated['patient']['email'],
        'phone' => (string) ($validated['patient']['phone'] ?? ''),
        'doctor_name' => (string) $validated['doctor_name'],
        'booking_type' => (string) $booking['type'],
        'queue_number' => $queueNumber,
    ];
    $emailResult = appointment_send_booking_email($details);
    $smsResult = appointment_send_booking_sms($details);
    create_patient_appointment_notification($conn, $appointmentId, 'booked');
    create_clinic_appointment_notification($conn, $appointmentId, 'booked');
    create_admin_appointment_notification($conn, $appointmentId, 'booked');

    return [
        'ok' => true,
        'appointment_id' => $appointmentId,
        'queue_number' => $queueNumber,
        'email_sent' => (bool) ($emailResult['ok'] || !empty($emailResult['disabled'])),
        'email_error' => ($emailResult['ok'] || !empty($emailResult['disabled'])) ? '' : (string) ($emailResult['error'] ?? ''),
        'sms_sent' => (bool) ($smsResult['ok'] || !empty($smsResult['disabled'])),
        'sms_error' => ($smsResult['ok'] || !empty($smsResult['disabled'])) ? '' : (string) ($smsResult['error'] ?? ''),
    ];
}

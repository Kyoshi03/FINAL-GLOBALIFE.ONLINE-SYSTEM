<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/appointment_booking.php';

$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$lock = @fopen($logDir . '/appointment_reminders.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Another reminder process is already running.\n");
}

$conn = getDBConnection();
$result = $conn->query(
    "SELECT r.id AS reminder_id, r.appointment_id,
            r.email_sent_at, r.sms_sent_at,
            a.appointment_date, a.appointment_time,
            p.full_name AS patient_name, p.email, p.phone,
            d.full_name AS doctor_name,
            COALESCE(
                GROUP_CONCAT(DISTINCT ls.name ORDER BY ls.name SEPARATOR ', '),
                CASE
                    WHEN a.booking_type = 'consultation' THEN 'Doctor consultation'
                    WHEN a.booking_type = 'ultrasound' THEN 'Ultra sound'
                    ELSE 'Clinic appointment'
                END
            ) AS services
     FROM appointment_email_reminders r
     INNER JOIN appointments a ON a.id = r.appointment_id
     INNER JOIN users p ON p.id = a.patient_id
     LEFT JOIN users d ON d.id = a.doctor_id
     LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
     LEFT JOIN lab_services ls ON ls.id = aps.service_id
     WHERE r.sent_at IS NULL
       AND (r.email_sent_at IS NULL OR r.sms_sent_at IS NULL)
       AND r.scheduled_for <= NOW()
       AND r.attempts < 5
       AND a.status = 'confirmed'
       AND TIMESTAMP(a.appointment_date, a.appointment_time) > NOW()
     GROUP BY r.id, r.appointment_id, a.appointment_date, a.appointment_time,
              r.email_sent_at, r.sms_sent_at,
              p.full_name, p.email, p.phone, d.full_name
     ORDER BY r.scheduled_for ASC
     LIMIT 50"
);

$sentCount = 0;
$failedCount = 0;
while ($result && ($row = $result->fetch_assoc())) {
    $reminderId = (int) $row['reminder_id'];
    $emailSent = $row['email_sent_at'] !== null;
    $smsSent = $row['sms_sent_at'] !== null;
    $errors = [];

    if (!$emailSent) {
        $emailResult = appointment_send_reminder_email($row);
        if ($emailResult['ok']) {
            $markEmail = $conn->prepare(
                'UPDATE appointment_email_reminders SET email_sent_at = NOW() WHERE id = ?'
            );
            $markEmail->bind_param('i', $reminderId);
            $markEmail->execute();
            $markEmail->close();
            $emailSent = true;
        } else {
            $errors[] = 'Email: ' . (string) ($emailResult['error'] ?? 'Unknown delivery error');
        }
    }

    if (!$smsSent) {
        $smsResult = appointment_send_reminder_sms($row);
        if ($smsResult['ok']) {
            $markSms = $conn->prepare(
                'UPDATE appointment_email_reminders SET sms_sent_at = NOW() WHERE id = ?'
            );
            $markSms->bind_param('i', $reminderId);
            $markSms->execute();
            $markSms->close();
            $smsSent = true;
        } else {
            $errors[] = 'SMS: ' . (string) ($smsResult['error'] ?? 'Unknown delivery error');
        }
    }

    $message = $errors ? substr(implode(' | ', $errors), 0, 500) : null;
    if ($emailSent && $smsSent) {
        $update = $conn->prepare(
            'UPDATE appointment_email_reminders
             SET sent_at = NOW(), attempts = attempts + 1, last_error = NULL
             WHERE id = ? AND sent_at IS NULL'
        );
        $update->bind_param('i', $reminderId);
        $sentCount++;
    } else {
        $update = $conn->prepare(
            'UPDATE appointment_email_reminders
             SET attempts = attempts + 1, last_error = ?
             WHERE id = ? AND sent_at IS NULL'
        );
        $update->bind_param('si', $message, $reminderId);
        $failedCount++;
    }
    $update->execute();
    $update->close();
}

$conn->close();
flock($lock, LOCK_UN);
fclose($lock);

echo 'Appointment reminders: ' . $sentCount . ' completed, ' . $failedCount . " pending retry.\n";

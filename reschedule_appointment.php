<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/appointment_booking.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$currentUser = getCurrentUser();
$userRole = (string) ($currentUser['role'] ?? '');
$appointmentPage = [
    'admin' => 'admin_view_appointments.php',
    'doctor' => 'doctor_view_appointments.php',
    'patient' => 'patients_view_appointments.php',
][$userRole] ?? 'index.php';

function reschedule_finish(?mysqli $conn, string $returnUrl, bool $ok, string $message): void {
    if ($conn) {
        $conn->close();
    }
    $_SESSION[$ok ? 'success' : 'error'] = $message;
    header('Location: ' . $returnUrl);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $appointmentPage);
    exit();
}

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$requestedDate = trim((string) ($_POST['appointment_date'] ?? ''));
$requestedTime = trim((string) ($_POST['appointment_time'] ?? ''));
$returnUrl = trim((string) ($_POST['return_url'] ?? $appointmentPage));
if (
    $returnUrl === ''
    || preg_match('/^(https?:)?\/\//i', $returnUrl)
    || strpos($returnUrl, "\n") !== false
    || strpos($returnUrl, "\r") !== false
) {
    $returnUrl = $appointmentPage;
}

if ($appointmentId <= 0) {
    reschedule_finish(null, $returnUrl, false, 'Invalid appointment selected.');
}

$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate, new DateTimeZone('Asia/Manila'));
$dateErrors = DateTimeImmutable::getLastErrors();
$dateHasErrors = is_array($dateErrors)
    && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0);
if (!$dateObject || $dateHasErrors || $dateObject->format('Y-m-d') !== $requestedDate) {
    reschedule_finish(null, $returnUrl, false, 'Choose a valid new appointment date.');
}

if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $requestedTime)) {
    reschedule_finish(null, $returnUrl, false, 'Choose a valid new appointment time.');
}

$normalizedTime = strlen($requestedTime) === 5 ? $requestedTime . ':00' : $requestedTime;
$appointmentAt = DateTimeImmutable::createFromFormat(
    '!Y-m-d H:i:s',
    $requestedDate . ' ' . $normalizedTime,
    new DateTimeZone('Asia/Manila')
);
$timeErrors = DateTimeImmutable::getLastErrors();
$timeHasErrors = is_array($timeErrors)
    && (($timeErrors['warning_count'] ?? 0) > 0 || ($timeErrors['error_count'] ?? 0) > 0);
if (!$appointmentAt || $timeHasErrors || $appointmentAt->format('Y-m-d H:i:s') !== $requestedDate . ' ' . $normalizedTime) {
    reschedule_finish(null, $returnUrl, false, 'Choose a valid new appointment date and time.');
}

$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
if ($appointmentAt <= $now) {
    reschedule_finish(null, $returnUrl, false, 'The new appointment schedule must be in the future.');
}

$conn = getDBConnection();
appointment_init_queue_schema($conn);

$stmt = $conn->prepare(
    "SELECT id, patient_id, doctor_id, appointment_date, appointment_time, status, booking_type
     FROM appointments
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param('i', $appointmentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    reschedule_finish($conn, $returnUrl, false, 'Appointment not found.');
}

$previousStatus = strtolower((string) ($appointment['status'] ?? ''));
$bookingType = strtolower((string) ($appointment['booking_type'] ?? ''));
$isOpenStatus = in_array($previousStatus, ['pending', 'confirmed'], true);
$hasPermission = false;
if ($userRole === 'admin') {
    $hasPermission = $isOpenStatus;
} elseif ($userRole === 'doctor') {
    $hasPermission = $isOpenStatus
        && $bookingType === 'consultation'
        && (int) ($appointment['doctor_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
} elseif ($userRole === 'patient') {
    $hasPermission = $isOpenStatus
        && (int) ($appointment['patient_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
}

if (!$hasPermission) {
    reschedule_finish($conn, $returnUrl, false, 'You do not have permission to reschedule this appointment.');
}

if ($bookingType === 'ultrasound' && !appointment_ultrasound_is_available_date($requestedDate)) {
    reschedule_finish($conn, $returnUrl, false, 'Ultra sound appointments are available on Wednesday and Saturday only.');
}
if ($bookingType !== 'consultation' && !appointment_clinic_is_open_at($requestedDate, $normalizedTime)) {
    reschedule_finish($conn, $returnUrl, false, 'The clinic is open Monday to Saturday, from 8:00 AM to 5:00 PM.');
}
if ($bookingType === 'consultation' && !user_is_doctor_available_at($conn, (int) $appointment['doctor_id'], $requestedDate, $normalizedTime)) {
    reschedule_finish($conn, $returnUrl, false, 'The selected doctor is not available on that schedule. Please choose another date or time.');
}

$duplicateStmt = $conn->prepare(
    "SELECT id
     FROM appointments
     WHERE patient_id = ?
       AND appointment_date = ?
       AND appointment_time = ?
       AND status <> 'cancelled'
       AND id <> ?
     LIMIT 1"
);
$patientId = (int) ($appointment['patient_id'] ?? 0);
$duplicateStmt->bind_param('issi', $patientId, $requestedDate, $normalizedTime, $appointmentId);
$duplicateStmt->execute();
$duplicate = $duplicateStmt->get_result()->fetch_assoc();
$duplicateStmt->close();
if ($duplicate) {
    reschedule_finish($conn, $returnUrl, false, 'You already have another appointment at that date and time.');
}

$capacityLockName = ($bookingType === 'consultation' ? 'clinic-doctor-capacity-' . (int) $appointment['doctor_id'] : 'clinic-appointment-capacity') . '-' . $requestedDate;
$lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
$lockStmt->bind_param('s', $capacityLockName);
$lockStmt->execute();
$lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
$lockStmt->close();
if ($lockAcquired !== 1) {
    reschedule_finish($conn, $returnUrl, false, 'The selected schedule is being updated. Please try again.');
}

$releaseLock = static function (mysqli $connection, string $lockName): void {
    if ($lockName === '') {
        return;
    }
    $release = $connection->prepare('SELECT RELEASE_LOCK(?)');
    if ($release) {
        $release->bind_param('s', $lockName);
        $release->execute();
        $release->close();
    }
};

try {
    $capacitySql = "SELECT COUNT(*) AS total FROM appointments WHERE appointment_date = ? AND status <> 'cancelled' AND id <> ?";
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
    $capacityStmt = $conn->prepare($capacitySql);
    if ($bookingType === 'consultation') {
        $doctorId = (int) $appointment['doctor_id'];
        $capacityStmt->bind_param('sii', $requestedDate, $appointmentId, $doctorId);
    } else {
        $capacityStmt->bind_param('si', $requestedDate, $appointmentId);
    }
    $capacityStmt->execute();
    $booked = (int) ($capacityStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $capacityStmt->close();
    if ($booked >= $capacityLimit) {
        $label = $bookingType === 'consultation' ? 'The selected doctor' : ($bookingType === 'ultrasound' ? 'Ultra sound appointments' : 'Laboratory appointments');
        throw new RuntimeException($label . ' are fully booked on the selected date. Please choose another date.');
    }

    $conn->begin_transaction();
    $newStatus = $previousStatus;
    if ($userRole === 'patient' && $previousStatus === 'confirmed') {
        $newStatus = 'pending';
    }
    $update = $conn->prepare(
        "UPDATE appointments
         SET appointment_date = ?, appointment_time = ?, status = ?, cancellation_reason = NULL
         WHERE id = ? AND status = ?"
    );
    $update->bind_param('sssis', $requestedDate, $normalizedTime, $newStatus, $appointmentId, $previousStatus);
    if (!$update->execute() || $update->affected_rows < 1) {
        $update->close();
        throw new RuntimeException('The appointment changed before it could be rescheduled. Please refresh and try again.');
    }
    $update->close();

    $queueStmt = $conn->prepare('SELECT id, queue_date FROM clinic_queue WHERE appointment_id = ? LIMIT 1 FOR UPDATE');
    $queueStmt->bind_param('i', $appointmentId);
    $queueStmt->execute();
    $queue = $queueStmt->get_result()->fetch_assoc();
    $queueStmt->close();
    if ($queue && (string) ($queue['queue_date'] ?? '') !== $requestedDate) {
        $newQueueNumber = appointment_next_online_queue_number($conn, $requestedDate);
        $queueId = (int) ($queue['id'] ?? 0);
        $queueUpdate = $conn->prepare('UPDATE clinic_queue SET queue_date = ?, queue_number = ? WHERE id = ?');
        $queueUpdate->bind_param('ssi', $requestedDate, $newQueueNumber, $queueId);
        if (!$queueUpdate->execute()) {
            $queueUpdate->close();
            throw new RuntimeException('The appointment was not rescheduled because its queue schedule could not be updated.');
        }
        $queueUpdate->close();
    }

    $reminderDays = clinic_notification_reminder_days();
    $reminderAt = $appointmentAt->modify('-' . $reminderDays . ' days');
    if ($reminderAt < $now) {
        $reminderAt = $now;
    }
    $scheduledFor = $reminderAt->format('Y-m-d H:i:s');
    $reminderType = $reminderDays . '_days';
    $reminderUpsert = $conn->prepare(
        "INSERT INTO appointment_email_reminders
            (appointment_id, reminder_type, scheduled_for)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE
            scheduled_for = VALUES(scheduled_for),
            email_sent_at = NULL,
            sms_sent_at = NULL,
            sent_at = NULL,
            attempts = 0,
            last_error = NULL"
    );
    $reminderUpsert->bind_param('iss', $appointmentId, $reminderType, $scheduledFor);
    if (!$reminderUpsert->execute()) {
        $reminderUpsert->close();
        throw new RuntimeException('The appointment was rescheduled, but its reminder could not be prepared.');
    }
    $reminderUpsert->close();

    $conn->commit();
} catch (Throwable $e) {
    if ($conn->errno === 0) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    } else {
        $conn->rollback();
    }
    $releaseLock($conn, $capacityLockName);
    reschedule_finish($conn, $returnUrl, false, $e->getMessage());
}

$releaseLock($conn, $capacityLockName);
create_patient_appointment_notification($conn, $appointmentId, 'rescheduled');
create_clinic_appointment_notification($conn, $appointmentId, 'rescheduled');
create_admin_appointment_notification($conn, $appointmentId, 'rescheduled');

$notificationWarnings = [];
$emailResult = appointment_send_rescheduled_email($conn, $appointmentId);
if (!$emailResult['ok'] && empty($emailResult['disabled'])) {
    $notificationWarnings[] = 'email';
}
$smsResult = appointment_send_rescheduled_sms($conn, $appointmentId);
if (!$smsResult['ok'] && empty($smsResult['disabled'])) {
    $notificationWarnings[] = 'SMS';
}

$statusText = $newStatus === 'pending' && $previousStatus === 'confirmed'
    ? 'Appointment rescheduled and sent back for clinic approval.'
    : 'Appointment rescheduled successfully.';
if ($notificationWarnings) {
    $statusText .= ' The schedule was saved, but the ' . implode(' and ', $notificationWarnings) . ' notification could not be delivered.';
}
reschedule_finish($conn, $returnUrl, true, $statusText);

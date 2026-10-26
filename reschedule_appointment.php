<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/appointment_reschedule.php';

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
    if ($conn) $conn->close();
    $_SESSION[$ok ? 'reschedule_success' : 'reschedule_error'] = $message;
    header('Location: ' . $returnUrl);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $appointmentPage);
    exit();
}

$returnUrl = trim((string) ($_POST['return_url'] ?? $appointmentPage));
if ($returnUrl === '' || preg_match('/^(https?:)?\/\//i', $returnUrl) || strpos($returnUrl, "\n") !== false || strpos($returnUrl, "\r") !== false) {
    $returnUrl = $appointmentPage;
}

if (!in_array($userRole, ['patient', 'admin', 'doctor'], true)) {
    reschedule_finish(null, $returnUrl, false, 'You cannot reschedule this appointment.');
}

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$requestedDate = trim((string) ($_POST['appointment_date'] ?? ''));
$requestedTime = trim((string) ($_POST['appointment_time'] ?? ''));
$rescheduleReason = trim((string) ($_POST['reason'] ?? ''));
$rescheduleDetails = trim((string) ($_POST['reason_details'] ?? ''));
if ($appointmentId <= 0) {
    reschedule_finish(null, $returnUrl, false, 'Invalid appointment selected.');
}

$conn = getDBConnection();
appointment_reschedule_init_schema($conn);
appointment_init_queue_schema($conn);

if ($userRole === 'admin') {
    if (!appointment_reschedule_reason_is_valid($rescheduleReason, 'admin')) {
        reschedule_finish($conn, $returnUrl, false, 'Please select a valid admin reschedule reason.');
    }
    $detailsLength = function_exists('mb_strlen') ? mb_strlen($rescheduleDetails, 'UTF-8') : strlen($rescheduleDetails);
    if ($detailsLength > 1000) {
        reschedule_finish($conn, $returnUrl, false, 'Additional reschedule details must be 1000 characters or fewer.');
    }
    if ($rescheduleReason !== 'Other reason') {
        $rescheduleDetails = '';
    }

    $adminLookup = $conn->prepare(
        "SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
                q.status AS queue_status,
                " . dbUsersNameExpression('p') . " AS patient_name
         FROM appointments a
         INNER JOIN users p ON p.id = a.patient_id
         LEFT JOIN clinic_queue q ON q.appointment_id = a.id
         WHERE a.id = ?
         LIMIT 1"
    );
    $adminLookup->bind_param('i', $appointmentId);
    $adminLookup->execute();
    $appointment = $adminLookup->get_result()->fetch_assoc();
    $adminLookup->close();
    if (!$appointment || !appointment_reschedule_is_eligible($appointment)) {
        reschedule_finish($conn, $returnUrl, false, 'Only upcoming Confirmed appointments that have not started can be rescheduled by an administrator.');
    }
    $normalizedTime = appointment_reschedule_normalize_time($requestedTime);
    $slotError = appointment_reschedule_slot_message($conn, $appointment, $requestedDate, $normalizedTime, true);
    if ($slotError !== null) {
        reschedule_finish($conn, $returnUrl, false, $slotError);
    }
    if ($requestedDate === (string) $appointment['appointment_date'] && $normalizedTime === appointment_reschedule_normalize_time((string) $appointment['appointment_time'])) {
        reschedule_finish($conn, $returnUrl, false, 'The requested schedule is the same as the current appointment schedule.');
    }

    $lockName = appointment_reschedule_lock_name($appointment, $requestedDate);
    $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
    $lockStmt->bind_param('s', $lockName);
    $lockStmt->execute();
    $lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
    $lockStmt->close();
    if ($lockAcquired !== 1) {
        reschedule_finish($conn, $returnUrl, false, 'The selected schedule is being updated. Please try again.');
    }

    try {
        $conn->begin_transaction();
        $appointmentLock = $conn->prepare("SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type, q.id AS queue_id, q.status AS queue_status
            FROM appointments a LEFT JOIN clinic_queue q ON q.appointment_id = a.id WHERE a.id = ? FOR UPDATE");
        $appointmentLock->bind_param('i', $appointmentId);
        $appointmentLock->execute();
        $lockedAppointment = $appointmentLock->get_result()->fetch_assoc();
        $appointmentLock->close();
        if (!$lockedAppointment || !appointment_reschedule_is_eligible($lockedAppointment)) {
            throw new RuntimeException('The appointment is no longer eligible for rescheduling. Please refresh the page.');
        }
        $pending = $conn->prepare("SELECT id FROM appointment_reschedule_requests WHERE appointment_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
        $pending->bind_param('i', $appointmentId);
        $pending->execute();
        $hasPending = (bool) $pending->get_result()->fetch_assoc();
        $pending->close();
        if ($hasPending) {
            throw new RuntimeException('A reschedule request is already pending for this appointment.');
        }
        $slotError = appointment_reschedule_slot_message($conn, $lockedAppointment, $requestedDate, $normalizedTime, true);
        if ($slotError !== null) {
            throw new RuntimeException($slotError);
        }
        $updateAppointment = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = 'confirmed', cancellation_reason = NULL, cancellation_details = NULL WHERE id = ? AND status = 'confirmed'");
        $updateAppointment->bind_param('ssi', $requestedDate, $normalizedTime, $appointmentId);
        if (!$updateAppointment->execute() || $updateAppointment->affected_rows !== 1) {
            $updateAppointment->close();
            throw new RuntimeException('The appointment changed before it could be rescheduled. Refresh and try again.');
        }
        $updateAppointment->close();

        $queueId = (int) ($lockedAppointment['queue_id'] ?? 0);
        if ($queueId > 0) {
            $queueNumber = appointment_next_online_queue_number($conn, $requestedDate);
            $queueUpdate = $conn->prepare("UPDATE clinic_queue SET queue_date = ?, queue_number = ?, status = 'waiting', time_called = NULL, completed_at = NULL WHERE id = ?");
            $queueUpdate->bind_param('ssi', $requestedDate, $queueNumber, $queueId);
            if (!$queueUpdate->execute() || $queueUpdate->affected_rows < 1) {
                $queueUpdate->close();
                throw new RuntimeException('The appointment was not rescheduled because its queue entry could not be updated.');
            }
            $queueUpdate->close();
        } else {
            appointment_create_online_queue($conn, $appointmentId, (int) $lockedAppointment['patient_id'], $requestedDate, appointment_queue_service_label($lockedAppointment));
        }

        $appointmentAt = appointment_reschedule_at($requestedDate, $normalizedTime);
        $reminderAt = $appointmentAt->modify('-' . clinic_notification_reminder_days() . ' days');
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        if ($reminderAt < $now) $reminderAt = $now;
        $scheduledFor = $reminderAt->format('Y-m-d H:i:s');
        $reminderType = clinic_notification_reminder_days() . '_days';
        $reminder = $conn->prepare("INSERT INTO appointment_email_reminders (appointment_id, reminder_type, scheduled_for)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE scheduled_for = VALUES(scheduled_for), email_sent_at = NULL, sms_sent_at = NULL, sent_at = NULL, attempts = 0, last_error = NULL");
        $reminder->bind_param('iss', $appointmentId, $reminderType, $scheduledFor);
        if (!$reminder->execute()) {
            $reminder->close();
            throw new RuntimeException('The appointment was not rescheduled because its reminder could not be updated.');
        }
        $reminder->close();

        $originalDate = (string) $lockedAppointment['appointment_date'];
        $originalTime = appointment_reschedule_normalize_time((string) $lockedAppointment['appointment_time']);
        $reviewedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $insert = $conn->prepare("INSERT INTO appointment_reschedule_requests
            (appointment_id, patient_id, requested_by, original_date, original_time, requested_date, requested_time, reason, reason_details, requested_by_role, status, reviewed_by, reviewed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin', 'approved', ?, ?)");
        $adminId = (int) ($currentUser['id'] ?? 0);
        $patientIdForRequest = (int) $lockedAppointment['patient_id'];
        $insert->bind_param('iiissssssis', $appointmentId, $patientIdForRequest, $adminId, $originalDate, $originalTime, $requestedDate, $normalizedTime, $rescheduleReason, $rescheduleDetails, $adminId, $reviewedAt);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('The reschedule record could not be saved.');
        }
        $insert->close();
        $requestId = (int) $conn->insert_id;
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        appointment_reschedule_release_lock($conn, $lockName);
        reschedule_finish($conn, $returnUrl, false, $e->getMessage());
    }
    appointment_reschedule_release_lock($conn, $lockName);
    $request = $appointment;
    $request['id'] = $requestId;
    $request['appointment_id'] = $appointmentId;
    $request['requested_date'] = $requestedDate;
    $request['requested_time'] = $normalizedTime;
    $request['reason'] = $rescheduleReason;
    $request['reason_details'] = $rescheduleDetails;
    $request['requested_by_role'] = 'admin';
    appointment_reschedule_notify_patient($conn, $request, 'admin_rescheduled');
    reschedule_finish($conn, $returnUrl, true, 'Appointment rescheduled successfully. The patient was notified.');
}

if ($userRole === 'doctor') {
    if (!appointment_reschedule_reason_is_valid($rescheduleReason, 'doctor')) {
        reschedule_finish($conn, $returnUrl, false, 'Please select a valid reason for rescheduling.');
    }
    $detailsLength = function_exists('mb_strlen') ? mb_strlen($rescheduleDetails, 'UTF-8') : strlen($rescheduleDetails);
    if ($detailsLength > 1000) {
        reschedule_finish($conn, $returnUrl, false, 'Additional reschedule details must be 1000 characters or fewer.');
    }
    if ($rescheduleReason !== 'Other reason') {
        $rescheduleDetails = '';
    }

    $doctorId = (int) ($currentUser['id'] ?? 0);
    $doctorLookup = $conn->prepare(
        "SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
                q.status AS queue_status,
                " . dbUsersNameExpression('p') . " AS patient_name
         FROM appointments a
         INNER JOIN users p ON p.id = a.patient_id
         LEFT JOIN clinic_queue q ON q.appointment_id = a.id
         WHERE a.id = ? AND a.doctor_id = ? AND a.booking_type = 'consultation'
         LIMIT 1"
    );
    $doctorLookup->bind_param('ii', $appointmentId, $doctorId);
    $doctorLookup->execute();
    $appointment = $doctorLookup->get_result()->fetch_assoc();
    $doctorLookup->close();
    if (!$appointment || !appointment_reschedule_is_pending_editable($appointment)) {
        reschedule_finish($conn, $returnUrl, false, 'Only your upcoming Pending consultation requests can be rescheduled.');
    }

    $normalizedTime = appointment_reschedule_normalize_time($requestedTime);
    $slotError = appointment_reschedule_slot_message($conn, $appointment, $requestedDate, $normalizedTime, true);
    if ($slotError !== null) {
        reschedule_finish($conn, $returnUrl, false, $slotError);
    }
    if ($requestedDate === (string) $appointment['appointment_date']
        && $normalizedTime === appointment_reschedule_normalize_time((string) $appointment['appointment_time'])) {
        reschedule_finish($conn, $returnUrl, false, 'The requested schedule is the same as the current appointment schedule.');
    }

    $lockName = appointment_reschedule_lock_name($appointment, $requestedDate);
    $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
    $lockStmt->bind_param('s', $lockName);
    $lockStmt->execute();
    $lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
    $lockStmt->close();
    if ($lockAcquired !== 1) {
        reschedule_finish($conn, $returnUrl, false, 'The selected schedule is being updated. Please try again.');
    }

    try {
        $conn->begin_transaction();
        $appointmentLock = $conn->prepare("SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
                q.status AS queue_status
            FROM appointments a LEFT JOIN clinic_queue q ON q.appointment_id = a.id WHERE a.id = ? FOR UPDATE");
        $appointmentLock->bind_param('i', $appointmentId);
        $appointmentLock->execute();
        $lockedAppointment = $appointmentLock->get_result()->fetch_assoc();
        $appointmentLock->close();
        if (!$lockedAppointment || (int) $lockedAppointment['doctor_id'] !== $doctorId
            || (string) $lockedAppointment['booking_type'] !== 'consultation'
            || !appointment_reschedule_is_pending_editable($lockedAppointment)) {
            throw new RuntimeException('This consultation is no longer eligible for rescheduling. Please refresh the page.');
        }
        $pending = $conn->prepare("SELECT id FROM appointment_reschedule_requests WHERE appointment_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
        $pending->bind_param('i', $appointmentId);
        $pending->execute();
        $hasPending = (bool) $pending->get_result()->fetch_assoc();
        $pending->close();
        if ($hasPending) {
            throw new RuntimeException('A reschedule request is already pending for this appointment.');
        }
        $slotError = appointment_reschedule_slot_message($conn, $lockedAppointment, $requestedDate, $normalizedTime, true);
        if ($slotError !== null) {
            throw new RuntimeException($slotError);
        }

        $updateAppointment = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?
            WHERE id = ? AND doctor_id = ? AND status = 'pending' AND booking_type = 'consultation'");
        $updateAppointment->bind_param('ssii', $requestedDate, $normalizedTime, $appointmentId, $doctorId);
        if (!$updateAppointment->execute() || $updateAppointment->affected_rows !== 1) {
            $updateAppointment->close();
            throw new RuntimeException('The pending consultation could not be rescheduled. Refresh and try again.');
        }
        $updateAppointment->close();

        $queueUpdate = $conn->prepare("UPDATE clinic_queue SET queue_date = ?
            WHERE appointment_id = ? AND status NOT IN ('completed', 'cancelled')");
        if ($queueUpdate) {
            $queueUpdate->bind_param('si', $requestedDate, $appointmentId);
            $queueUpdate->execute();
            $queueUpdate->close();
        }

        $appointmentAt = appointment_reschedule_at($requestedDate, $normalizedTime);
        $reminderAt = $appointmentAt->modify('-' . clinic_notification_reminder_days() . ' days');
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        if ($reminderAt < $now) $reminderAt = $now;
        $scheduledFor = $reminderAt->format('Y-m-d H:i:s');
        $reminder = $conn->prepare("UPDATE appointment_email_reminders
            SET scheduled_for = ?, email_sent_at = NULL, sms_sent_at = NULL, sent_at = NULL, attempts = 0, last_error = NULL
            WHERE appointment_id = ?");
        if ($reminder) {
            $reminder->bind_param('si', $scheduledFor, $appointmentId);
            $reminder->execute();
            $reminder->close();
        }

        $originalDate = (string) $lockedAppointment['appointment_date'];
        $originalTime = appointment_reschedule_normalize_time((string) $lockedAppointment['appointment_time']);
        $reviewedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $insert = $conn->prepare("INSERT INTO appointment_reschedule_requests
            (appointment_id, patient_id, requested_by, original_date, original_time, requested_date, requested_time,
             reason, reason_details, requested_by_role, status, reviewed_by, reviewed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'doctor', 'approved', ?, ?)");
        $patientId = (int) $lockedAppointment['patient_id'];
        $insert->bind_param('iiissssssis', $appointmentId, $patientId, $doctorId, $originalDate, $originalTime,
            $requestedDate, $normalizedTime, $rescheduleReason, $rescheduleDetails, $doctorId, $reviewedAt);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('The reschedule record could not be saved.');
        }
        $insert->close();
        $requestId = (int) $conn->insert_id;
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        appointment_reschedule_release_lock($conn, $lockName);
        reschedule_finish($conn, $returnUrl, false, $e->getMessage());
    }
    appointment_reschedule_release_lock($conn, $lockName);
    $request = $appointment;
    $request['id'] = $requestId;
    $request['appointment_id'] = $appointmentId;
    $request['requested_date'] = $requestedDate;
    $request['requested_time'] = $normalizedTime;
    $request['reason'] = $rescheduleReason;
    $request['reason_details'] = $rescheduleDetails;
    $request['requested_by_role'] = 'doctor';
    appointment_reschedule_notify_patient($conn, $request, 'doctor_rescheduled');
    reschedule_finish($conn, $returnUrl, true, 'Consultation rescheduled. It remains Pending, and the patient was notified.');
}

$stmt = $conn->prepare(
    "SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
            q.status AS queue_status,
            " . dbUsersNameExpression('p') . " AS patient_name
     FROM appointments a
     INNER JOIN users p ON p.id = a.patient_id
     LEFT JOIN clinic_queue q ON q.appointment_id = a.id
     WHERE a.id = ? AND a.patient_id = ?
     LIMIT 1"
);
$patientId = (int) ($currentUser['id'] ?? 0);
$stmt->bind_param('ii', $appointmentId, $patientId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    reschedule_finish($conn, $returnUrl, false, 'Appointment not found or it does not belong to your account.');
}
$isConfirmedReschedule = appointment_reschedule_is_eligible($appointment);
$isPendingScheduleChange = appointment_reschedule_is_pending_editable($appointment);
if (!$isConfirmedReschedule && !$isPendingScheduleChange) {
    reschedule_finish($conn, $returnUrl, false, 'Only upcoming Pending or Confirmed appointments that have not started can be changed.');
}
if ($isPendingScheduleChange) {
    $rescheduleReason = '';
    $rescheduleDetails = '';
} else {
    if (!appointment_reschedule_reason_is_valid($rescheduleReason, 'patient')) {
        reschedule_finish($conn, $returnUrl, false, 'Please select a valid reason for rescheduling.');
    }
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($rescheduleReason, 'UTF-8') : strlen($rescheduleReason);
    if ($reasonLength > 300) {
        reschedule_finish($conn, $returnUrl, false, 'The reschedule reason must be 300 characters or fewer.');
    }
    $detailsLength = function_exists('mb_strlen') ? mb_strlen($rescheduleDetails, 'UTF-8') : strlen($rescheduleDetails);
    if ($detailsLength > 1000) {
        reschedule_finish($conn, $returnUrl, false, 'Additional reschedule details must be 1000 characters or fewer.');
    }
    if ($rescheduleReason !== 'Other reason') {
        $rescheduleDetails = '';
    }
}

$normalizedTime = appointment_reschedule_normalize_time($requestedTime);
$slotError = appointment_reschedule_slot_message($conn, $appointment, $requestedDate, $normalizedTime, true);
if ($slotError !== null) {
    reschedule_finish($conn, $returnUrl, false, $slotError);
}
if ($requestedDate === (string) $appointment['appointment_date'] && $normalizedTime === appointment_reschedule_normalize_time((string) $appointment['appointment_time'])) {
    reschedule_finish($conn, $returnUrl, false, 'The requested schedule is the same as the current appointment schedule.');
}

$lockName = appointment_reschedule_lock_name($appointment, $requestedDate);
$lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
$lockStmt->bind_param('s', $lockName);
$lockStmt->execute();
$lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
$lockStmt->close();
if ($lockAcquired !== 1) {
    reschedule_finish($conn, $returnUrl, false, 'The selected schedule is being updated. Please try again.');
}

try {
    $conn->begin_transaction();
    $appointmentLock = $conn->prepare("SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type, q.status AS queue_status
        FROM appointments a LEFT JOIN clinic_queue q ON q.appointment_id = a.id WHERE a.id = ? FOR UPDATE");
    $appointmentLock->bind_param('i', $appointmentId);
    $appointmentLock->execute();
    $lockedAppointment = $appointmentLock->get_result()->fetch_assoc();
    $appointmentLock->close();
    $lockedIsConfirmedReschedule = $lockedAppointment && appointment_reschedule_is_eligible($lockedAppointment);
    $lockedIsPendingScheduleChange = $lockedAppointment && appointment_reschedule_is_pending_editable($lockedAppointment);
    if (!$lockedAppointment || (int) $lockedAppointment['patient_id'] !== $patientId || (!$lockedIsConfirmedReschedule && !$lockedIsPendingScheduleChange)) {
        throw new RuntimeException('The appointment is no longer eligible for rescheduling. Please refresh the page.');
    }

    $pending = $conn->prepare("SELECT id FROM appointment_reschedule_requests WHERE appointment_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
    $pending->bind_param('i', $appointmentId);
    $pending->execute();
    $hasPending = (bool) $pending->get_result()->fetch_assoc();
    $pending->close();
    if ($hasPending) {
        throw new RuntimeException('A reschedule request is already pending for this appointment.');
    }

    $slotError = appointment_reschedule_slot_message($conn, $lockedAppointment, $requestedDate, $normalizedTime, true);
    if ($slotError !== null) {
        throw new RuntimeException($slotError);
    }

    if ($lockedIsPendingScheduleChange) {
        $updateAppointment = $conn->prepare("UPDATE appointments
            SET appointment_date = ?, appointment_time = ?
            WHERE id = ? AND patient_id = ? AND status = 'pending'");
        $updateAppointment->bind_param('ssii', $requestedDate, $normalizedTime, $appointmentId, $patientId);
        if (!$updateAppointment->execute() || $updateAppointment->affected_rows !== 1) {
            $updateAppointment->close();
            throw new RuntimeException('The pending appointment schedule could not be changed. Please refresh and try again.');
        }
        $updateAppointment->close();

        $queueUpdate = $conn->prepare("UPDATE clinic_queue
            SET queue_date = ?
            WHERE appointment_id = ? AND status NOT IN ('completed', 'cancelled')");
        if ($queueUpdate) {
            $queueUpdate->bind_param('si', $requestedDate, $appointmentId);
            $queueUpdate->execute();
            $queueUpdate->close();
        }

        $reminderAt = new DateTimeImmutable($requestedDate . ' ' . $normalizedTime, new DateTimeZone('Asia/Manila'));
        $reminderAt = $reminderAt->modify('-' . clinic_notification_reminder_days() . ' days');
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        if ($reminderAt < $now) {
            $reminderAt = $now;
        }
        $scheduledFor = $reminderAt->format('Y-m-d H:i:s');
        $reminderUpdate = $conn->prepare("UPDATE appointment_email_reminders
            SET scheduled_for = ?, email_sent_at = NULL, sms_sent_at = NULL, sent_at = NULL, attempts = 0, last_error = NULL
            WHERE appointment_id = ?");
        if ($reminderUpdate) {
            $reminderUpdate->bind_param('si', $scheduledFor, $appointmentId);
            $reminderUpdate->execute();
            $reminderUpdate->close();
        }

        $conn->commit();
        appointment_reschedule_release_lock($conn, $lockName);
        reschedule_finish($conn, $returnUrl, true, 'Your pending appointment schedule was changed successfully. It remains Pending for clinic review.');
    }

    $insert = $conn->prepare(
        "INSERT INTO appointment_reschedule_requests
            (appointment_id, patient_id, requested_by, original_date, original_time, requested_date, requested_time, reason, reason_details, requested_by_role, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'patient', 'pending')"
    );
    $originalDate = (string) ($lockedAppointment['appointment_date'] ?? '');
    $originalTime = appointment_reschedule_normalize_time((string) ($lockedAppointment['appointment_time'] ?? ''));
    $insert->bind_param('iiissssss', $appointmentId, $patientId, $patientId, $originalDate, $originalTime, $requestedDate, $normalizedTime, $rescheduleReason, $rescheduleDetails);
    if (!$insert->execute()) {
        $insert->close();
        throw new RuntimeException('The reschedule request could not be saved. Please try again.');
    }
    $requestId = (int) $conn->insert_id;
    $insert->close();
    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    appointment_reschedule_release_lock($conn, $lockName);
    reschedule_finish($conn, $returnUrl, false, $e->getMessage());
}

appointment_reschedule_release_lock($conn, $lockName);
$request = $appointment;
$request['id'] = $requestId;
$request['appointment_id'] = $appointmentId;
$request['requested_date'] = $requestedDate;
$request['requested_time'] = $normalizedTime;
$request['reason'] = $rescheduleReason;
$request['reason_details'] = $rescheduleDetails;
$request['requested_by_role'] = 'patient';
appointment_reschedule_notify_patient($conn, $request, 'requested');
appointment_reschedule_notify_reviewer($conn, $request, 'requested');
reschedule_finish($conn, $returnUrl, true, 'Reschedule request submitted. Your original Confirmed appointment remains active until the clinic approves the new schedule.');

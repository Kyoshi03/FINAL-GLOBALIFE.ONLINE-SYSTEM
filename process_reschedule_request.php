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
$appointmentPage = $userRole === 'doctor' ? 'doctor_view_appointments.php' : 'admin_view_appointments.php';
$returnUrl = trim((string) ($_POST['return_url'] ?? $appointmentPage));
if ($returnUrl === '' || preg_match('/^(https?:)?\/\//i', $returnUrl) || strpos($returnUrl, "\n") !== false || strpos($returnUrl, "\r") !== false) {
    $returnUrl = $appointmentPage;
}

function reschedule_request_finish(?mysqli $conn, string $returnUrl, bool $ok, string $message): void {
    if ($conn) $conn->close();
    $_SESSION[$ok ? 'success' : 'error'] = $message;
    header('Location: ' . $returnUrl);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($userRole, ['admin', 'doctor'], true)) {
    reschedule_request_finish(null, $returnUrl, false, 'You do not have permission to review reschedule requests.');
}

$requestId = (int) ($_POST['request_id'] ?? 0);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
if ($requestId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    reschedule_request_finish(null, $returnUrl, false, 'Invalid reschedule request.');
}

$rejectionReason = trim((string) ($_POST['rejection_reason'] ?? ''));
$rejectionDetails = trim((string) ($_POST['rejection_details'] ?? ''));
if ($action === 'reject') {
    if (!appointment_reschedule_reason_is_valid($rejectionReason, $userRole === 'admin' ? 'admin' : 'doctor')) {
        reschedule_request_finish(null, $returnUrl, false, 'Please select a valid rejection reason.');
    }
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($rejectionReason, 'UTF-8') : strlen($rejectionReason);
    if ($rejectionReason === '') {
        reschedule_request_finish(null, $returnUrl, false, 'A reason is required before rejecting the reschedule request.');
    }
    if ($reasonLength > 300) {
        reschedule_request_finish(null, $returnUrl, false, 'The rejection reason must be 300 characters or fewer.');
    }
    $detailsLength = function_exists('mb_strlen') ? mb_strlen($rejectionDetails, 'UTF-8') : strlen($rejectionDetails);
    if ($detailsLength > 1000) {
        reschedule_request_finish(null, $returnUrl, false, 'Additional rejection details must be 1000 characters or fewer.');
    }
    if ($rejectionReason !== 'Other reason') {
        $rejectionDetails = '';
    }
}

$conn = getDBConnection();
appointment_reschedule_init_schema($conn);
appointment_init_queue_schema($conn);

    $lookup = $conn->prepare(
    "SELECT r.id, r.appointment_id, r.patient_id, r.requested_date, r.requested_time, r.reason, r.reason_details, r.requested_by_role, r.status AS request_status,
            a.doctor_id, a.appointment_date, a.appointment_time, a.status AS appointment_status, a.booking_type,
            q.status AS queue_status,
            " . dbUsersNameExpression('p') . " AS patient_name
     FROM appointment_reschedule_requests r
     INNER JOIN appointments a ON a.id = r.appointment_id
     INNER JOIN users p ON p.id = a.patient_id
     LEFT JOIN clinic_queue q ON q.appointment_id = a.id
     WHERE r.id = ?
     LIMIT 1"
);
$lookup->bind_param('i', $requestId);
$lookup->execute();
$request = $lookup->get_result()->fetch_assoc();
$lookup->close();
if (!$request) {
    reschedule_request_finish($conn, $returnUrl, false, 'Reschedule request not found.');
}

$bookingType = strtolower((string) ($request['booking_type'] ?? ''));
$canReview = $userRole === 'admin'
    ? $bookingType !== 'consultation'
    : ($bookingType === 'consultation' && (int) ($request['doctor_id'] ?? 0) === (int) ($currentUser['id'] ?? 0));
if (!$canReview) {
    reschedule_request_finish($conn, $returnUrl, false, 'You do not have permission to review this reschedule request.');
}

$lockName = appointment_reschedule_lock_name($request, (string) ($request['requested_date'] ?? ''));
$lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS acquired');
$lockStmt->bind_param('s', $lockName);
$lockStmt->execute();
$lockAcquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
$lockStmt->close();
if ($lockAcquired !== 1) {
    reschedule_request_finish($conn, $returnUrl, false, 'The selected schedule is being updated. Please try again.');
}

$approved = false;
$message = '';
try {
    $conn->begin_transaction();
    $locked = $conn->prepare(
        "SELECT r.id, r.appointment_id, r.patient_id, r.requested_date, r.requested_time, r.reason, r.reason_details, r.requested_by_role, r.status AS request_status,
                a.id AS id, a.doctor_id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
                q.id AS queue_id, q.queue_date, q.status AS queue_status,
                " . dbUsersNameExpression('p') . " AS patient_name
         FROM appointment_reschedule_requests r
         INNER JOIN appointments a ON a.id = r.appointment_id
         INNER JOIN users p ON p.id = a.patient_id
         LEFT JOIN clinic_queue q ON q.appointment_id = a.id
         WHERE r.id = ?
         LIMIT 1 FOR UPDATE"
    );
    $locked->bind_param('i', $requestId);
    $locked->execute();
    $request = $locked->get_result()->fetch_assoc();
    $locked->close();
    if (!$request || strtolower((string) ($request['request_status'] ?? '')) !== 'pending') {
        throw new RuntimeException('This reschedule request has already been reviewed.');
    }
    $bookingType = strtolower((string) ($request['booking_type'] ?? ''));
    $canReview = $userRole === 'admin'
        ? $bookingType !== 'consultation'
        : ($bookingType === 'consultation' && (int) ($request['doctor_id'] ?? 0) === (int) ($currentUser['id'] ?? 0));
    if (!$canReview) {
        throw new RuntimeException('You do not have permission to review this reschedule request.');
    }

    $reviewerId = (int) ($currentUser['id'] ?? 0);
    $reviewedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    if ($action === 'reject') {
        $update = $conn->prepare("UPDATE appointment_reschedule_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, rejection_reason = ?, rejection_details = ? WHERE id = ? AND status = 'pending'");
        $update->bind_param('isssi', $reviewerId, $reviewedAt, $rejectionReason, $rejectionDetails, $requestId);
        if (!$update->execute() || $update->affected_rows !== 1) {
            $update->close();
            throw new RuntimeException('The reschedule request changed before it could be rejected.');
        }
        $update->close();
        $request['rejection_reason'] = $rejectionReason;
        $request['rejection_details'] = $rejectionDetails;
        $conn->commit();
        $message = 'Reschedule request rejected. The original Confirmed appointment remains unchanged.';
    } else {
        $appointmentForValidation = [
            'id' => (int) $request['appointment_id'],
            'patient_id' => (int) $request['patient_id'],
            'doctor_id' => (int) ($request['doctor_id'] ?? 0),
            'appointment_date' => (string) $request['appointment_date'],
            'appointment_time' => (string) $request['appointment_time'],
            'status' => (string) $request['status'],
            'booking_type' => (string) $request['booking_type'],
            'queue_status' => (string) ($request['queue_status'] ?? ''),
        ];
        if (!appointment_reschedule_is_eligible($appointmentForValidation)) {
            throw new RuntimeException('The original appointment is no longer eligible for rescheduling. The request was not approved.');
        }
        $slotError = appointment_reschedule_slot_message($conn, $appointmentForValidation, (string) $request['requested_date'], (string) $request['requested_time'], true);
        if ($slotError !== null) {
            throw new RuntimeException('The new schedule is no longer available. ' . $slotError . ' The original Confirmed appointment remains active.');
        }

        $newDate = (string) $request['requested_date'];
        $newTime = appointment_reschedule_normalize_time((string) $request['requested_time']);
        $updateAppointment = $conn->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, status = 'confirmed', cancellation_reason = NULL, cancellation_details = NULL WHERE id = ? AND status = 'confirmed'");
        $appointmentId = (int) $request['appointment_id'];
        $updateAppointment->bind_param('ssi', $newDate, $newTime, $appointmentId);
        if (!$updateAppointment->execute() || $updateAppointment->affected_rows !== 1) {
            $updateAppointment->close();
            throw new RuntimeException('The appointment changed before it could be approved. Refresh and try again.');
        }
        $updateAppointment->close();

        $queueId = (int) ($request['queue_id'] ?? 0);
        if ($queueId > 0) {
            $queueNumber = appointment_next_online_queue_number($conn, $newDate);
            $queueUpdate = $conn->prepare("UPDATE clinic_queue SET queue_date = ?, queue_number = ?, status = 'waiting', time_called = NULL, completed_at = NULL WHERE id = ?");
            $queueUpdate->bind_param('ssi', $newDate, $queueNumber, $queueId);
            if (!$queueUpdate->execute() || $queueUpdate->affected_rows < 1) {
                $queueUpdate->close();
                throw new RuntimeException('The appointment was not approved because its queue entry could not be updated.');
            }
            $queueUpdate->close();
        } else {
            appointment_create_online_queue($conn, $appointmentId, (int) $request['patient_id'], $newDate, appointment_queue_service_label($appointmentForValidation));
        }

        $appointmentAt = appointment_reschedule_at($newDate, $newTime);
        $reminderDays = clinic_notification_reminder_days();
        $reminderAt = $appointmentAt->modify('-' . $reminderDays . ' days');
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        if ($reminderAt < $now) $reminderAt = $now;
        $scheduledFor = $reminderAt->format('Y-m-d H:i:s');
        $reminderType = $reminderDays . '_days';
        $reminder = $conn->prepare(
            "INSERT INTO appointment_email_reminders (appointment_id, reminder_type, scheduled_for)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE scheduled_for = VALUES(scheduled_for), email_sent_at = NULL, sms_sent_at = NULL, sent_at = NULL, attempts = 0, last_error = NULL"
        );
        $reminder->bind_param('iss', $appointmentId, $reminderType, $scheduledFor);
        if (!$reminder->execute()) {
            $reminder->close();
            throw new RuntimeException('The appointment was not approved because its reminder could not be updated.');
        }
        $reminder->close();

        $updateRequest = $conn->prepare("UPDATE appointment_reschedule_requests SET status = 'approved', reviewed_by = ?, reviewed_at = ? WHERE id = ? AND status = 'pending'");
        $updateRequest->bind_param('isi', $reviewerId, $reviewedAt, $requestId);
        if (!$updateRequest->execute() || $updateRequest->affected_rows !== 1) {
            $updateRequest->close();
            throw new RuntimeException('The reschedule request changed before it could be approved.');
        }
        $updateRequest->close();
        $request['requested_time'] = $newTime;
        $conn->commit();
        $approved = true;
        $message = 'Reschedule request approved. The appointment kept its ID and remains Confirmed on the new schedule.';
    }
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    appointment_reschedule_release_lock($conn, $lockName);
    reschedule_request_finish($conn, $returnUrl, false, $e->getMessage());
}

appointment_reschedule_release_lock($conn, $lockName);
appointment_reschedule_notify_patient($conn, $request, $approved ? 'approved' : 'rejected');
appointment_reschedule_notify_reviewer($conn, $request, $approved ? 'approved' : 'rejected');
if (!$approved) {
    $_SESSION['reschedule_rejection_success'] = [
        'title' => 'Reschedule request rejected',
        'message' => 'The original Confirmed appointment remains unchanged.',
    ];
}
reschedule_request_finish($conn, $returnUrl, true, $message);

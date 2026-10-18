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

if ($userRole !== 'patient') {
    reschedule_finish(null, $returnUrl, false, 'Only the patient can submit a reschedule request. Clinic staff must approve or reject it from the appointment list.');
}

$appointmentId = (int) ($_POST['appointment_id'] ?? 0);
$requestedDate = trim((string) ($_POST['appointment_date'] ?? ''));
$requestedTime = trim((string) ($_POST['appointment_time'] ?? ''));
if ($appointmentId <= 0) {
    reschedule_finish(null, $returnUrl, false, 'Invalid appointment selected.');
}

$conn = getDBConnection();
appointment_reschedule_init_schema($conn);
appointment_init_queue_schema($conn);

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
if (!appointment_reschedule_is_eligible($appointment)) {
    reschedule_finish($conn, $returnUrl, false, 'Only upcoming Confirmed appointments that have not started can be rescheduled.');
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
    if (!$lockedAppointment || (int) $lockedAppointment['patient_id'] !== $patientId || !appointment_reschedule_is_eligible($lockedAppointment)) {
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

    $insert = $conn->prepare(
        "INSERT INTO appointment_reschedule_requests
            (appointment_id, patient_id, requested_by, requested_date, requested_time, status)
         VALUES (?, ?, ?, ?, ?, 'pending')"
    );
    $insert->bind_param('iiiss', $appointmentId, $patientId, $patientId, $requestedDate, $normalizedTime);
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
appointment_reschedule_notify_patient($conn, $request, 'requested');
appointment_reschedule_notify_reviewer($conn, $request, 'requested');
reschedule_finish($conn, $returnUrl, true, 'Reschedule request submitted. Your original Confirmed appointment remains active until the clinic approves the new schedule.');

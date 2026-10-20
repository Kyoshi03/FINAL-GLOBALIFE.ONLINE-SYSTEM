<?php
require_once 'includes/session.php';
require_once 'config/database.php';
require_once 'includes/appointment_booking.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$currentUser = getCurrentUser();
$userRole = $currentUser['role'];
$appointmentPage = [
    'admin' => 'admin_view_appointments.php',
    'doctor' => 'doctor_view_appointments.php',
    'patient' => 'patients_view_appointments.php',
][$userRole] ?? 'admin_view_appointments.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $appointmentPage);
    exit();
}

$appointment_id = $_POST['appointment_id'] ?? '';
$new_status = $_POST['status'] ?? '';
$new_status = strtolower(trim((string) $new_status));
$returnUrl = trim((string) ($_POST['return_url'] ?? $appointmentPage));
$wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
if ($returnUrl === '' || preg_match('/^(https?:)?\/\//i', $returnUrl) || strpos($returnUrl, "\n") !== false || strpos($returnUrl, "\r") !== false) {
    $returnUrl = $appointmentPage;
}

function appointment_status_finish(?mysqli $conn, string $returnUrl, bool $wantsJson, bool $ok, string $message): void {
    if ($conn) {
        $conn->close();
    }
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
        ]);
        exit();
    }
    $_SESSION[$ok ? 'success' : 'error'] = $message;
    header('Location: ' . $returnUrl);
    exit();
}

if (empty($appointment_id) || empty($new_status)) {
    appointment_status_finish(null, $returnUrl, $wantsJson, false, 'Invalid request.');
}

if (!in_array($new_status, ['pending', 'confirmed', 'completed', 'cancelled'], true)) {
    appointment_status_finish(null, $returnUrl, $wantsJson, false, 'Invalid appointment status.');
}

$conn = getDBConnection();
appointment_init_queue_schema($conn);
appointment_init_cancellation_audit_schema($conn);

// Check if appointment exists and user has permission
$checkStmt = $conn->prepare("SELECT a.patient_id, a.doctor_id, a.status, a.booking_type, a.appointment_date, a.notes,
                                    q.status AS queue_status
                             FROM appointments a
                             LEFT JOIN clinic_queue q ON q.appointment_id = a.id
                             WHERE a.id = ?
                             ORDER BY q.id DESC
                             LIMIT 1");
$checkStmt->bind_param("i", $appointment_id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    $checkStmt->close();
    appointment_status_finish($conn, $returnUrl, $wantsJson, false, 'Appointment not found.');
}

$appointment = $result->fetch_assoc();
$checkStmt->close();
$previousStatus = strtolower((string) ($appointment['status'] ?? ''));

// Check permissions
$canUpdate = false;
if ($userRole === 'admin') {
    if ((string) ($appointment['booking_type'] ?? '') !== 'consultation') {
        $canUpdate = ($previousStatus === 'pending' && in_array($new_status, ['confirmed', 'cancelled'], true))
            || ($previousStatus === 'confirmed' && in_array($new_status, ['completed', 'cancelled'], true));
    }
} elseif ($userRole === 'doctor'
    && (int) $appointment['doctor_id'] === (int) $currentUser['id']
    && (string) ($appointment['booking_type'] ?? '') === 'consultation'
    && (
        ((string) $appointment['status'] === 'pending' && in_array($new_status, ['confirmed', 'cancelled'], true))
        || ((string) $appointment['status'] === 'confirmed' && $new_status === 'completed')
    )
) {
    $canUpdate = true;
} elseif ($userRole === 'patient' && $appointment['patient_id'] == $currentUser['id']) {
    // Patients can only cancel their own appointments
    $queueStatus = strtolower(trim((string) ($appointment['queue_status'] ?? '')));
    if ($new_status === 'cancelled'
        && in_array($appointment['status'], ['pending', 'confirmed'], true)
        && !in_array($queueStatus, ['serving', 'completed'], true)) {
        $canUpdate = true;
    }
}

if (!$canUpdate) {
    $queueStatus = strtolower(trim((string) ($appointment['queue_status'] ?? '')));
    $isPatientTryingToCancelActiveQueue = $userRole === 'patient'
        && $new_status === 'cancelled'
        && in_array($queueStatus, ['serving', 'completed'], true);
    appointment_status_finish(
        $conn,
        $returnUrl,
        $wantsJson,
        false,
        $isPatientTryingToCancelActiveQueue
            ? 'This appointment can no longer be cancelled because it is already being served or completed.'
            : 'You do not have permission to update this appointment.'
    );
}

$cancellationReason = trim((string) ($_POST['cancellation_reason'] ?? ''));
if ($new_status === 'cancelled') {
    $cancellationReason = preg_replace('/\s+/', ' ', $cancellationReason) ?? '';
    if ($cancellationReason === '') {
        appointment_status_finish($conn, $returnUrl, $wantsJson, false, 'Please add a cancellation reason before cancelling the appointment.');
    }
    if (strlen($cancellationReason) > 500) {
        $cancellationReason = substr($cancellationReason, 0, 500);
    }
}

// Update appointment status
if ($new_status === 'cancelled') {
    $cancelledByRole = normalizeUserRole((string) ($currentUser['role'] ?? $userRole));
    $cancelledByUserId = (int) ($currentUser['id'] ?? 0);
    $updateStmt = $conn->prepare("UPDATE appointments
                                  SET status = ?, cancellation_reason = ?, cancelled_by_role = ?,
                                      cancelled_by_user_id = ?, cancelled_at = NOW()
                                  WHERE id = ? AND status = ?");
    $updateStmt->bind_param("sssiis", $new_status, $cancellationReason, $cancelledByRole, $cancelledByUserId, $appointment_id, $previousStatus);
} else {
    $updateStmt = $conn->prepare("UPDATE appointments
                                  SET status = ?, cancellation_reason = NULL, cancelled_by_role = NULL,
                                      cancelled_by_user_id = NULL, cancelled_at = NULL
                                  WHERE id = ? AND status = ?");
    $updateStmt->bind_param("sis", $new_status, $appointment_id, $previousStatus);
}

if ($updateStmt->execute() && $updateStmt->affected_rows > 0) {
    if (in_array($new_status, ['completed', 'cancelled'], true)) {
        appointment_init_queue_schema($conn);
        if ($new_status === 'completed') {
            $queueStatusStmt = $conn->prepare("UPDATE clinic_queue
                SET status = 'completed', completed_at = COALESCE(completed_at, NOW())
                WHERE appointment_id = ? AND status IN ('waiting', 'serving')");
        } else {
            $queueStatusStmt = $conn->prepare("UPDATE clinic_queue
                SET status = 'cancelled', completed_at = NULL
                WHERE appointment_id = ? AND status IN ('waiting', 'serving')");
        }
        if ($queueStatusStmt) {
            $queueStatusStmt->bind_param('i', $appointment_id);
            $queueStatusStmt->execute();
            $queueStatusStmt->close();
        }
    }
    $statusMessage = $new_status === 'cancelled'
        ? 'Appointment cancelled with reason saved.'
        : (($userRole === 'doctor' && $new_status === 'confirmed')
            ? 'Appointment confirmed successfully.'
            : (($userRole === 'doctor' && $new_status === 'completed')
                ? 'Appointment marked as completed successfully.'
                : 'Appointment status updated successfully.'));
    if ($new_status !== $previousStatus) {
        create_patient_appointment_notification($conn, (int) $appointment_id, $new_status);
        create_clinic_appointment_notification($conn, (int) $appointment_id, $new_status);
        create_admin_appointment_notification($conn, (int) $appointment_id, $new_status);
    }
    if ($new_status === 'confirmed' && $appointment['status'] !== 'confirmed') {
        // Older appointments can be missing their queue row. Re-create it idempotently on confirmation.
        appointment_init_queue_schema($conn);
        try {
            appointment_create_online_queue(
                $conn,
                (int) $appointment_id,
                (int) $appointment['patient_id'],
                (string) $appointment['appointment_date'],
                appointment_queue_service_label($appointment)
            );
        } catch (Throwable $queueError) {
            $statusMessage .= ' The appointment was confirmed, but its queue number could not be generated.';
        }
    }
    if ($new_status === 'confirmed' && $appointment['status'] !== 'confirmed') {
        $emailResult = appointment_send_clinic_confirmation_email($conn, (int) $appointment_id);
        $smsResult = appointment_send_clinic_confirmation_sms($conn, (int) $appointment_id);
        $failedChannels = [];
        if (!$emailResult['ok'] && empty($emailResult['disabled'])) {
            $failedChannels[] = 'email';
        }
        if (!$smsResult['ok'] && empty($smsResult['disabled'])) {
            $failedChannels[] = 'SMS';
        }
        if ($failedChannels) {
            $statusMessage .= ' The status was saved, but the '
                . implode(' and ', $failedChannels)
                . ' confirmation could not be delivered.';
        }
    } elseif ($new_status === 'cancelled' && $appointment['status'] !== 'cancelled') {
        $emailResult = appointment_send_cancellation_email($conn, (int) $appointment_id);
        $smsResult = appointment_send_cancellation_sms($conn, (int) $appointment_id);
        $failedChannels = [];
        if (!$emailResult['ok'] && empty($emailResult['disabled'])) {
            $failedChannels[] = 'email';
        }
        if (!$smsResult['ok'] && empty($smsResult['disabled'])) {
            $failedChannels[] = 'SMS';
        }
        if ($failedChannels) {
            $statusMessage .= ' The status was saved, but the '
                . implode(' and ', $failedChannels)
                . ' cancellation notification could not be delivered.';
        }
    }
} else {
    $statusMessage = 'The appointment status changed before this request was completed. Refresh the page and try again.';
}

$updateStmt->close();
appointment_status_finish($conn, $returnUrl, $wantsJson, isset($statusMessage) && $statusMessage !== 'Error updating appointment status.', $statusMessage ?? 'Error updating appointment status.');

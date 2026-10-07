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

$conn = getDBConnection();

if (!function_exists('appointmentStatusColumnExists')) {
    function appointmentStatusColumnExists(mysqli $conn, string $column): bool {
        $safeColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM appointments LIKE '{$safeColumn}'");
        return $result && $result->num_rows > 0;
    }
}

if (!appointmentStatusColumnExists($conn, 'cancellation_reason')) {
    $conn->query("ALTER TABLE appointments ADD COLUMN cancellation_reason TEXT DEFAULT NULL AFTER notes");
}

// Check if appointment exists and user has permission
$checkStmt = $conn->prepare("SELECT patient_id, doctor_id, status, booking_type FROM appointments WHERE id = ?");
$checkStmt->bind_param("i", $appointment_id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    $checkStmt->close();
    appointment_status_finish($conn, $returnUrl, $wantsJson, false, 'Appointment not found.');
}

$appointment = $result->fetch_assoc();
$checkStmt->close();

// Check permissions
$canUpdate = false;
if ($userRole === 'admin') {
    $canUpdate = (string) ($appointment['booking_type'] ?? '') !== 'consultation';
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
    if ($new_status === 'cancelled' && ($appointment['status'] === 'pending' || $appointment['status'] === 'confirmed')) {
        $canUpdate = true;
    }
}

if (!$canUpdate) {
    appointment_status_finish($conn, $returnUrl, $wantsJson, false, 'You do not have permission to update this appointment.');
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
    $updateStmt = $conn->prepare("UPDATE appointments SET status = ?, cancellation_reason = ? WHERE id = ?");
    $updateStmt->bind_param("ssi", $new_status, $cancellationReason, $appointment_id);
} else {
    $updateStmt = $conn->prepare("UPDATE appointments SET status = ?, cancellation_reason = NULL WHERE id = ?");
    $updateStmt->bind_param("si", $new_status, $appointment_id);
}

if ($updateStmt->execute()) {
    $statusMessage = $new_status === 'cancelled'
        ? 'Appointment cancelled with reason saved.'
        : (($userRole === 'doctor' && $new_status === 'confirmed')
            ? 'Appointment confirmed successfully.'
            : (($userRole === 'doctor' && $new_status === 'completed')
                ? 'Appointment marked as completed successfully.'
                : 'Appointment status updated successfully.'));
    if ($new_status !== $appointment['status']) {
        create_patient_appointment_notification($conn, (int) $appointment_id, $new_status);
        create_clinic_appointment_notification($conn, (int) $appointment_id, $new_status);
        create_admin_appointment_notification($conn, (int) $appointment_id, $new_status);
    }
    if ($new_status === 'confirmed' && $appointment['status'] !== 'confirmed') {
        $emailResult = appointment_send_clinic_confirmation_email($conn, (int) $appointment_id);
        $smsResult = appointment_send_clinic_confirmation_sms($conn, (int) $appointment_id);
        $failedChannels = [];
        if (!$emailResult['ok']) {
            $failedChannels[] = 'email';
        }
        if (!$smsResult['ok']) {
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
    $statusMessage = 'Error updating appointment status.';
}

$updateStmt->close();
appointment_status_finish($conn, $returnUrl, $wantsJson, isset($statusMessage) && $statusMessage !== 'Error updating appointment status.', $statusMessage ?? 'Error updating appointment status.');

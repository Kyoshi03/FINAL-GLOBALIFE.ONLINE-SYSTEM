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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: view_appointments.php');
    exit();
}

$appointment_id = $_POST['appointment_id'] ?? '';
$new_status = $_POST['status'] ?? '';
$returnUrl = trim((string) ($_POST['return_url'] ?? 'view_appointments.php'));
if ($returnUrl === '' || preg_match('/^(https?:)?\/\//i', $returnUrl) || strpos($returnUrl, "\n") !== false || strpos($returnUrl, "\r") !== false) {
    $returnUrl = 'view_appointments.php';
}

if (empty($appointment_id) || empty($new_status)) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ' . $returnUrl);
    exit();
}

$conn = getDBConnection();

// Check if appointment exists and user has permission
$checkStmt = $conn->prepare("SELECT patient_id, doctor_id, status, booking_type FROM appointments WHERE id = ?");
$checkStmt->bind_param("i", $appointment_id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = 'Appointment not found.';
    $checkStmt->close();
    $conn->close();
    header('Location: ' . $returnUrl);
    exit();
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
    && in_array($new_status, ['confirmed', 'cancelled'], true)
    && (string) $appointment['status'] === 'pending'
) {
    $canUpdate = true;
} elseif ($userRole === 'patient' && $appointment['patient_id'] == $currentUser['id']) {
    // Patients can only cancel their own appointments
    if ($new_status === 'cancelled' && ($appointment['status'] === 'pending' || $appointment['status'] === 'confirmed')) {
        $canUpdate = true;
    }
}

if (!$canUpdate) {
    $_SESSION['error'] = 'You do not have permission to update this appointment.';
    $conn->close();
    header('Location: ' . $returnUrl);
    exit();
}

// Update appointment status
$updateStmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
$updateStmt->bind_param("si", $new_status, $appointment_id);

if ($updateStmt->execute()) {
    $_SESSION['success'] = 'Appointment status updated successfully.';
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
            $_SESSION['success'] .= ' The status was saved, but the '
                . implode(' and ', $failedChannels)
                . ' confirmation could not be delivered.';
        }
    }
} else {
    $_SESSION['error'] = 'Error updating appointment status.';
}

$updateStmt->close();
$conn->close();

header('Location: ' . $returnUrl);
exit();

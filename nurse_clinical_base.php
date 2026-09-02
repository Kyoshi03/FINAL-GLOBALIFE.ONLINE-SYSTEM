<?php
require_once 'includes/session.php';
checkRole('doctor');

require_once 'config/database.php';
require_once __DIR__ . '/includes/nurse_clinical.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';
require_once __DIR__ . '/includes/nurse_clinical_styles.php';

$clinicalTab = 'medical';
if (empty($clinicalReturnUrl)) {
    $clinicalReturnUrl = 'nurse_patients.php';
}

$currentUser = getCurrentUser();
$today = date('Y-m-d');

function nc_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M j, Y', $stamp) : '--';
}

function nc_short_text(?string $text, int $limit = 100): string {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nurse_clinical_action'])) {
    $clinicalAction = (string) $_POST['nurse_clinical_action'];
    $patientId = (int) ($_POST['clinical_patient_id'] ?? 0);
    $conn = getDBConnection();
 if (function_exists('nurse_clinical_ensure_schema')) {
    nurse_clinical_ensure_schema($conn);
}

    if (!nurse_clinical_patient_exists($conn, $patientId)) {
        $_SESSION['error'] = 'Please select a valid patient.';
    } elseif ($clinicalAction === 'add_medical') {
        $fields = nurse_medical_fields_from_post($_POST);
        $result = nurse_clinical_save_medical($conn, $patientId, (int) $currentUser['id'], $fields);
        if ($result['ok']) {
            $_SESSION['success'] = $result['message'];
            $_SESSION['nurse_last_record'] = ['type' => 'medical', 'id' => $result['id']];
        } else {
            $_SESSION['error'] = $result['error'];
            $_SESSION['nurse_clinical_form'] = nurse_medical_form_session_from_post($_POST, $patientId);
        }
    } else {
        $_SESSION['error'] = 'Invalid action.';
    }

    $conn->close();
    header('Location: nurse_patients.php');
    exit;
}

$conn = getDBConnection();
if (function_exists('nurse_clinical_ensure_schema')) {
    nurse_clinical_ensure_schema($conn);
}
$todayRecordCount = 0;
$rc = $conn->prepare('SELECT COUNT(*) AS total FROM medical_records WHERE DATE(created_at) = ?');
$rc->bind_param('s', $today);
$rc->execute();
if ($row = $rc->get_result()->fetch_assoc()) {
    $todayRecordCount = (int) $row['total'];
}
$rc->close();
$todayPatients = [];
$ap = $conn->prepare("SELECT DISTINCT a.patient_id, p.full_name AS patient_name FROM appointments a JOIN users p ON p.id = a.patient_id WHERE a.appointment_date = ? ORDER BY p.full_name");
$ap->bind_param('s', $today);
$ap->execute();
$todayPatients = $ap->get_result()->fetch_all(MYSQLI_ASSOC);
$ap->close();

$clinicalForm = $_SESSION['nurse_clinical_form'] ?? [];
unset($_SESSION['nurse_clinical_form']);
if (!empty($clinicalForm['tab'])) {
    $clinicalTab = 'medical';
}
$lastSavedRecord = $_SESSION['nurse_last_record'] ?? null;
unset($_SESSION['nurse_last_record']);

$patientOptions = [];
$patientSeen = [];
foreach ($todayPatients as $appointment) {
    $pid = (int) ($appointment['patient_id'] ?? 0);
    if ($pid > 0 && !isset($patientSeen[$pid])) {
        $patientSeen[$pid] = true;
        $patientOptions[] = ['id' => $pid, 'label' => (string) ($appointment['patient_name'] ?? '') . ' (today)'];
    }
}
$allPatientsResult = $conn->query("SELECT id, full_name, username FROM users WHERE role = 'patient' ORDER BY full_name ASC LIMIT 400");
if ($allPatientsResult) {
    while ($pRow = $allPatientsResult->fetch_assoc()) {
        $pid = (int) $pRow['id'];
        if (!isset($patientSeen[$pid])) {
            $patientSeen[$pid] = true;
            $patientOptions[] = ['id' => $pid, 'label' => (string) $pRow['full_name'] . ' (' . (string) $pRow['username'] . ')'];
        }
    }
}

$recentRecords = $conn->query("SELECT m.id AS record_id, m.patient_id, m.title, m.content, m.temperature, m.weight, m.notes, m.doctor_notes, m.vital_signs, m.created_at, p.full_name AS patient_name FROM medical_records m JOIN users p ON p.id = m.patient_id ORDER BY m.created_at DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);
$conn->close();

$selectedPatientId = (int) ($clinicalForm['patient_id'] ?? ($_GET['patient'] ?? 0));
$pageTitle = 'Medical notes | Doctor';
$pageHeading = 'Medical notes';
$pageDesc = 'Record patient temperature, weight, and notes.';

$additionalStyles = nurse_clinical_styles();
$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const patientSelect = document.getElementById("clinicalPatientSelect");
    const medHidden = document.getElementById("medicalPatientId");
    function syncPatient() {
        const v = patientSelect ? patientSelect.value : "";
        if (medHidden) medHidden.value = v;
    }
    if (patientSelect) {
        patientSelect.addEventListener("change", syncPatient);
        syncPatient();
    }
    document.querySelectorAll("form[id^=nurse]").forEach(function (form) {
        form.addEventListener("submit", function (e) {
            syncPatient();
            if (!patientSelect || !patientSelect.value) {
                e.preventDefault();
                alert("Please select a patient first.");
                patientSelect.focus();
            }
        });
    });
});
';

include 'includes/header.php';
require __DIR__ . '/includes/nurse_clinical_view.php';
include 'includes/footer.php';

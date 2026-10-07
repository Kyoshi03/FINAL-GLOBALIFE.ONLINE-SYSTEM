<?php
ob_start();
require_once 'includes/session.php';
checkRole('doctor');

require_once 'config/database.php';
require_once __DIR__ . '/includes/doctor_clinical.php';
require_once __DIR__ . '/includes/doctor_pdf_export.php';

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$action = strtolower(trim((string) ($_GET['action'] ?? 'print')));
$recordId = (int) ($_GET['id'] ?? 0);

if (!in_array($type, ['medical', 'lab'], true) || $recordId <= 0) {
    http_response_code(400);
    echo 'Invalid export request.';
    exit;
}

if (!in_array($action, ['print', 'download'], true)) {
    $action = 'print';
}

$conn = getDBConnection();
$data = $type === 'medical'
    ? doctor_clinical_fetch_medical($conn, $recordId)
    : doctor_clinical_fetch_lab($conn, $recordId);
$conn->close();

if (!$data) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$dateStamp = date('Y-m-d', strtotime((string) ($type === 'medical' ? $data['created_at'] : $data['result_date'])));
$prefix = $type === 'medical' ? 'MedicalRecord' : 'LabResult';
$filename = doctor_clinical_export_filename($prefix, (string) $data['patient_name'], $dateStamp);
$html = doctor_clinical_render_export_html($data, $type);

if ($action === 'download') {
    if (ob_get_length() !== false) {
        ob_end_clean();
    }
    clinic_doctor_pdf_output($type === 'lab' ? 'lab' : 'medical', $data);
}

header('Content-Type: text/html; charset=UTF-8');
echo $html;
echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},300);});</script>';


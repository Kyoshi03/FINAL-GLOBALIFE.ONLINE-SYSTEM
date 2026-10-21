<?php

require_once __DIR__ . '/includes/session.php';
checkAnyRole(['admin', 'doctor']);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/pwd_id.php';

$patientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);
if (!$patientId || $patientId < 1) {
    http_response_code(400);
    exit('Invalid patient.');
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT pwd_id_path FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
$stmt->bind_param('i', $patientId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();
$conn->close();

$filePath = $row ? pwd_id_file_path((string) ($row['pwd_id_path'] ?? '')) : null;
if (!$filePath) {
    http_response_code(404);
    exit('PWD ID not found.');
}

$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
$mime = $finfo ? (string) finfo_file($finfo, $filePath) : 'application/octet-stream';
if ($finfo) {
    finfo_close($finfo);
}
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported image type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="patient-' . $patientId . '-pwd-id.' . ($mime === 'image/png' ? 'png' : 'jpg') . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);

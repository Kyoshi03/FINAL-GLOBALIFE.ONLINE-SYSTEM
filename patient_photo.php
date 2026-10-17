<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit;
}

$file = (string) ($_GET['file'] ?? '');
if (!preg_match('/^patient_(\d+)_/i', basename($file), $matches)) {
    http_response_code(403);
    exit;
}

$patientId = (int) ($matches[1] ?? 0);
$currentUser = getCurrentUser();
$userRole = (string) ($currentUser['role'] ?? '');
$canView = $userRole === 'admin'
    || ($userRole === 'patient' && (int) ($currentUser['id'] ?? 0) === $patientId);

if (!$canView && $userRole === 'doctor') {
    require_once __DIR__ . '/config/database.php';
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT 1 FROM appointments
         WHERE doctor_id = ? AND patient_id = ?
         AND booking_type = 'consultation'
         LIMIT 1"
    );
    if ($stmt) {
        $doctorId = (int) ($currentUser['id'] ?? 0);
        $stmt->bind_param('ii', $doctorId, $patientId);
        $stmt->execute();
        $canView = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    }
    $conn->close();
}

if (!$canView) {
    http_response_code(403);
    exit;
}

$path = patientProfileStorageFilePath($file);
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit;
}

$info = @getimagesize($path);
if (!$info || empty($info['mime']) || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

$mtime = filemtime($path) ?: time();
$etag = '"' . sha1($file . '|' . $mtime . '|' . filesize($path)) . '"';
header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

if ((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

readfile($path);

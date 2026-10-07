<?php
require_once __DIR__ . '/includes/patient_profile_photo.php';

$file = (string) ($_GET['file'] ?? '');
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
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

if ((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

readfile($path);

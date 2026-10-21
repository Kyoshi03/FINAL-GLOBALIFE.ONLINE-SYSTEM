<?php

function pwd_disability_options(): array {
    return [
        'Physical / Orthopedic Disability',
        'Visual Disability',
        'Deaf or Hard of Hearing',
        'Speech and Language Impairment',
        'Intellectual Disability',
        'Other (please specify)',
    ];
}

function pwd_disability_type_is_valid(string $type): bool {
    return $type === '' || in_array($type, pwd_disability_options(), true);
}

function pwd_id_storage_roots(): array {
    $publicRoot = dirname(__DIR__);
    return [
        dirname($publicRoot) . '/globalife_storage',
        $publicRoot . '/storage',
    ];
}

function pwd_id_storage_root(): string {
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    foreach (pwd_id_storage_roots() as $candidateRoot) {
        if (!is_dir($candidateRoot)) {
            @mkdir($candidateRoot, 0700, true);
        }
        if (is_dir($candidateRoot) && is_writable($candidateRoot)) {
            $root = $candidateRoot;
            break;
        }
    }

    if ($root === null) {
        $root = dirname(__DIR__) . '/storage';
        @mkdir($root, 0700, true);
    }

    $pwdRoot = $root . '/pwd_ids';
    $pendingRoot = $pwdRoot . '/pending';
    @mkdir($pwdRoot, 0700, true);
    @mkdir($pendingRoot, 0700, true);
    return $root;
}

function pwd_id_directory(): string {
    pwd_id_storage_root();
    $directory = pwd_id_storage_root() . '/pwd_ids';
    @mkdir($directory, 0700, true);
    return $directory;
}

function pwd_id_pending_directory(): string {
    $directory = pwd_id_directory() . '/pending';
    @mkdir($directory, 0700, true);
    return $directory;
}

function pwd_id_path_is_allowed(string $relativePath): bool {
    return (bool) preg_match('#^pwd_ids/(?:pending/)?pwd_[A-Za-z0-9_-]+\.(?:jpg|png)$#', str_replace('\\', '/', ltrim($relativePath, '/')));
}

function pwd_id_absolute_path(string $relativePath): ?string {
    $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
    if (!pwd_id_path_is_allowed($relativePath)) {
        return null;
    }

    $fileName = basename($relativePath);
    $directory = str_starts_with($relativePath, 'pwd_ids/pending/')
        ? pwd_id_pending_directory()
        : pwd_id_directory();
    return $directory . '/' . $fileName;
}

function pwd_id_cleanup_pending_file(?string $relativePath): void {
    $fullPath = pwd_id_absolute_path((string) $relativePath);
    if ($fullPath && is_file($fullPath) && str_contains(str_replace('\\', '/', $fullPath), '/pwd_ids/pending/')) {
        @unlink($fullPath);
    }
}

function pwd_id_cleanup_old_pending_files(int $maxAge = 86400): void {
    $directory = pwd_id_pending_directory();
    $files = glob($directory . '/pwd_*.{jpg,png}', GLOB_BRACE) ?: [];
    $cutoff = time() - max(3600, $maxAge);
    foreach ($files as $file) {
        if (is_file($file) && (int) @filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}

function pwd_id_validate_upload(?array $file): array {
    if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Upload your PWD ID before creating the account.'];
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The PWD ID upload could not be read. Please choose the image again.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'The PWD ID upload is invalid. Please choose the image again.'];
    }
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'PWD ID must be a JPG, JPEG, or PNG image up to 5 MB.'];
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $imageInfo = @getimagesize($tmpName);
    if (!isset($allowedMime[$mime]) || !$imageInfo || (int) ($imageInfo[0] ?? 0) < 1 || (int) ($imageInfo[1] ?? 0) < 1) {
        return ['ok' => false, 'error' => 'PWD ID must be a valid JPG, JPEG, or PNG image.'];
    }

    return ['ok' => true, 'extension' => $allowedMime[$mime], 'mime' => $mime];
}

function pwd_id_store_uploaded_file(array $file, string $extension): ?string {
    $extension = $extension === 'png' ? 'png' : 'jpg';
    $fileName = 'pwd_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = pwd_id_pending_directory() . '/' . $fileName;
    if (!@move_uploaded_file((string) ($file['tmp_name'] ?? ''), $destination)) {
        return null;
    }
    @chmod($destination, 0600);
    return 'pwd_ids/pending/' . $fileName;
}

function pwd_id_promote_pending_file(string $pendingPath): ?string {
    $source = pwd_id_absolute_path($pendingPath);
    if (!$source || !is_file($source) || !str_contains(str_replace('\\', '/', $source), '/pwd_ids/pending/')) {
        return null;
    }

    $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
    $fileName = 'pwd_' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = pwd_id_directory() . '/' . $fileName;
    if (!@rename($source, $destination)) {
        return null;
    }
    @chmod($destination, 0600);
    return 'pwd_ids/' . $fileName;
}

function pwd_id_delete_file(?string $relativePath): void {
    $fullPath = pwd_id_absolute_path((string) $relativePath);
    if ($fullPath && is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function pwd_id_file_path(string $relativePath): ?string {
    $fullPath = pwd_id_absolute_path($relativePath);
    if (!$fullPath || !is_file($fullPath)) {
        return null;
    }
    return $fullPath;
}

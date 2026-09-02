<?php
function patientProfileColumnExists(mysqli $conn, string $column): bool {
    $safe = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM users LIKE '{$safe}'");
    return $result && $result->num_rows > 0;
}

function ensurePatientProfilePhotoColumn(mysqli $conn): void {
    if (!patientProfileColumnExists($conn, 'profile_photo')) {
        $conn->query("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL");
    }
    if (!patientProfileColumnExists($conn, 'profile_updated_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN profile_updated_at DATETIME DEFAULT NULL");
    }
    if (!patientProfileColumnExists($conn, 'first_name')) {
        $conn->query("ALTER TABLE users ADD COLUMN first_name VARCHAR(40) DEFAULT NULL AFTER full_name");
    }
    if (!patientProfileColumnExists($conn, 'middle_name')) {
        $conn->query("ALTER TABLE users ADD COLUMN middle_name VARCHAR(10) DEFAULT NULL AFTER first_name");
    }
    if (!patientProfileColumnExists($conn, 'last_name')) {
        $conn->query("ALTER TABLE users ADD COLUMN last_name VARCHAR(40) DEFAULT NULL AFTER middle_name");
    }
    if (!patientProfileColumnExists($conn, 'suffix')) {
        $conn->query("ALTER TABLE users ADD COLUMN suffix VARCHAR(10) DEFAULT NULL AFTER last_name");
    }
}

function patientProfileNormalizeNamePart(?string $value, bool $allowSeparators = false, bool $forceUppercase = false): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $pattern = $allowSeparators ? '/[^\p{L}\s\'-]/u' : '/[^\p{L}]/u';
    $value = preg_replace($pattern, '', $value) ?? '';

    if ($forceUppercase) {
        $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    return trim($value);
}

function patientProfileTitleCaseNamePart(?string $value, bool $allowSeparators = false): string {
    $value = patientProfileNormalizeNamePart($value, $allowSeparators, false);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value));
}

function patientProfileUppercaseNamePart(?string $value, bool $allowSeparators = false): string {
    $value = patientProfileNormalizeNamePart($value, $allowSeparators, false);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
}

function patientProfileBuildFullNameFromParts(array $row): string {
    $first = patientProfileTitleCaseNamePart($row['first_name'] ?? '', true);
    $middle = patientProfileUppercaseNamePart($row['middle_name'] ?? '', false);
    $last = patientProfileTitleCaseNamePart($row['last_name'] ?? '', true);
    $suffix = patientProfileUppercaseNamePart($row['suffix'] ?? '', false);

    $parts = array_filter([$first, $middle, $last, $suffix], static fn ($part) => $part !== '');
    return trim(implode(' ', $parts));
}

function patientProfileInitials(?string $name): string {
    $name = trim((string) $name);
    if ($name === '') return 'P';
    $parts = preg_split('/\s+/', $name) ?: [];
    $first = strtoupper(substr($parts[0] ?? 'P', 0, 1));
    $second = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $first . ($second !== '' ? $second : '');
}

function patientProfileUploadDir(): string {
    $dir = patientProfileStorageRoot() . '/patient_photos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function patientProfileStorageRoots(): array {
    $publicRoot = dirname(__DIR__);
    return [
        dirname($publicRoot) . '/globalife_storage',
        $publicRoot . '/storage',
    ];
}

function patientProfileStorageRoot(): string {
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    foreach (patientProfileStorageRoots() as $candidateRoot) {
        if (!is_dir($candidateRoot)) {
            @mkdir($candidateRoot, 0777, true);
        }
        if (is_dir($candidateRoot) && is_writable($candidateRoot)) {
            $root = $candidateRoot;
            return $root;
        }
    }

    $root = dirname(__DIR__) . '/storage';
    if (!is_dir($root)) {
        @mkdir($root, 0777, true);
    }
    return $root;
}

function patientProfileStorageFilePath(string $file): ?string {
    $file = basename(str_replace('\\', '/', $file));
    if (!preg_match('/^patient_\d+_[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$/i', $file)) {
        return null;
    }

    foreach (patientProfileStorageRoots() as $root) {
        $candidate = $root . '/patient_photos/' . $file;
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return patientProfileUploadDir() . '/' . $file;
}

function patientProfileStorageFileCandidates(string $file): array {
    $file = basename(str_replace('\\', '/', $file));
    if (!preg_match('/^patient_\d+_[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$/i', $file)) {
        return [];
    }

    return array_map(
        static fn (string $root): string => $root . '/patient_photos/' . $file,
        patientProfileStorageRoots()
    );
}

function patientProfilePathIsStored(string $path): bool {
    $normalized = str_replace('\\', '/', ltrim($path, '/\\'));
    return str_starts_with($normalized, 'patient_photos/');
}

function patientDeleteProfilePhotoFile(?string $path): void {
    $path = trim((string) $path);
    if ($path === '') return;

    if (patientProfilePathIsStored($path)) {
        foreach (patientProfileStorageFileCandidates(basename($path)) as $candidate) {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
        return;
    }

    $base = realpath(dirname(__DIR__));
    $full = realpath(dirname(__DIR__) . '/' . ltrim($path, '/\\'));
    if ($base && $full && strpos($full, $base) === 0 && is_file($full)) {
        @unlink($full);
    }
    foreach (patientProfileStorageFileCandidates(basename($path)) as $candidate) {
        if (is_file($candidate)) {
            @unlink($candidate);
        }
    }
}

function savePatientProfilePhotoFromBase64(int $patientId, string $dataUri, ?string $oldPath = null): ?string {
    if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,(.+)$/i', $dataUri, $m)) {
        return null;
    }
    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > 3 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info) {
        return null;
    }
    $dir = patientProfileUploadDir();
    $random = bin2hex(random_bytes(6));
    $file = 'patient_' . $patientId . '_' . date('YmdHis') . '_' . $random . '.' . $ext;
    $full = $dir . '/' . $file;
    if (@file_put_contents($full, $bytes) === false) {
        return null;
    }
    patientDeleteProfilePhotoFile($oldPath);
    return 'patient_photos/' . $file;
}

function patientProfilePhotoUrl(?string $path, ?string $updatedAt = null): ?string {
    $path = trim((string) $path);
    if ($path === '') return null;
    $normalizedPath = str_replace('\\', '/', ltrim($path, '/\\'));
    if (preg_match('#^https?://#i', $normalizedPath)) {
        return $normalizedPath;
    }
    if (patientProfilePathIsStored($normalizedPath)) {
        $file = basename($normalizedPath);
        $full = patientProfileStorageFilePath($file);
        if (!$full || !is_file($full)) return null;
        $version = $updatedAt ? strtotime($updatedAt) : filemtime($full);
        return 'patient_photo.php?file=' . rawurlencode($file) . ($version ? '&v=' . $version : '');
    }
    $full = dirname(__DIR__) . '/' . $normalizedPath;
    if (str_starts_with($normalizedPath, 'uploads/patient_photos/')) {
        $file = basename($normalizedPath);
        $stored = patientProfileStorageFilePath($file);
        if ($stored && !is_file($stored) && is_file($full)) {
            @copy($full, $stored);
        }
        if ($stored && is_file($stored)) {
            $version = $updatedAt ? strtotime($updatedAt) : filemtime($stored);
            return 'patient_photo.php?file=' . rawurlencode($file) . ($version ? '&v=' . $version : '');
        }
    }
    if (!is_file($full)) return null;
    $version = $updatedAt ? strtotime($updatedAt) : filemtime($full);
    return $normalizedPath . ($version ? '?v=' . $version : '');
}

function patientProfileHeaderDetails(mysqli $conn, int $patientId, string $fallbackName = 'Patient'): array {
    ensurePatientProfilePhotoColumn($conn);
    $stmt = $conn->prepare('SELECT full_name, first_name, middle_name, last_name, suffix, profile_photo, profile_updated_at FROM users WHERE id = ? AND role = ? LIMIT 1');
    if (!$stmt) {
        return [
            'name' => $fallbackName,
            'first_name' => explode(' ', trim($fallbackName))[0] ?? 'Patient',
            'initials' => patientProfileInitials($fallbackName),
            'photo_url' => null,
        ];
    }
    $role = 'patient';
    $stmt->bind_param('is', $patientId, $role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $name = patientProfileBuildFullNameFromParts($row);
    if ($name === '') {
        $name = trim((string) ($row['full_name'] ?? $fallbackName));
    }
    if ($name === '') {
        $name = 'Patient';
    }

    return [
        'name' => $name,
        'first_name' => explode(' ', $name)[0] ?? 'Patient',
        'initials' => patientProfileInitials($name),
        'photo_url' => patientProfilePhotoUrl($row['profile_photo'] ?? null, $row['profile_updated_at'] ?? null),
    ];
}

function updatePatientUserProfile(mysqli $conn, int $patientId, array $fields, ?string $photoPath, bool $removePhoto = false): array {
    ensurePatientProfilePhotoColumn($conn);
    $sql = "UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, email=?, phone=?, gender=?, date_of_birth=?, age=?, civil_status=?, address=?, barangay=?, city=?, emergency_contact_name=?, emergency_contact_relationship=?, emergency_contact_number=?, profile_photo=?, profile_updated_at=NOW() WHERE id=? AND role='patient'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not prepare profile update.'];
    }
    $firstName = patientProfileTitleCaseNamePart($fields['first_name'] ?? '', true);
    $middleName = patientProfileUppercaseNamePart($fields['middle_name'] ?? '', false);
    $lastName = patientProfileTitleCaseNamePart($fields['last_name'] ?? '', true);
    $suffix = patientProfileUppercaseNamePart($fields['suffix'] ?? '', false);
    $fullName = patientProfileBuildFullNameFromParts([
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'suffix' => $suffix,
    ]);
    if ($firstName === '' || $lastName === '') {
        $stmt->close();
        return ['ok' => false, 'error' => 'First name and last name are required.'];
    }
    $countLetters = static function (string $value): int {
        if ($value === '') {
            return 0;
        }
        preg_match_all('/\p{L}/u', $value, $matches);
        return count($matches[0] ?? []);
    };
    if ($countLetters($firstName) > 15) {
        $stmt->close();
        return ['ok' => false, 'error' => 'First name must not exceed 15 letters.'];
    }
    if ($countLetters($middleName) > 1 || ($middleName !== '' && $countLetters($middleName) < 1)) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Middle name must be exactly 1 letter.'];
    }
    if ($countLetters($lastName) > 15) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Last name must not exceed 15 letters.'];
    }
    if ($suffix !== '' && $countLetters($suffix) > 3) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Suffix must not exceed 3 letters.'];
    }
    $fullNameLength = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);
    if ($fullNameLength > 100) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Full name must not exceed 100 characters.'];
    }
    $email = (string) ($fields['email'] ?? '');
    $phone = (string) ($fields['phone'] ?? '');
    $gender = (string) ($fields['gender'] ?? '');
    $dob = (string) ($fields['date_of_birth'] ?? '');
    $age = (string) ($fields['age'] ?? '');
    $civil = (string) ($fields['civil_status'] ?? '');
    $address = (string) ($fields['address'] ?? '');
    $barangay = (string) ($fields['barangay'] ?? '');
    $city = (string) ($fields['city'] ?? '');
    $emName = (string) ($fields['emergency_contact_name'] ?? '');
    $emRel = (string) ($fields['emergency_contact_relationship'] ?? '');
    $emNum = (string) ($fields['emergency_contact_number'] ?? '');
    $photo = $removePhoto ? null : $photoPath;
    $bindTypes = str_repeat('s', 17) . 'i';
    $stmt->bind_param(
        $bindTypes,
        $firstName,
        $middleName,
        $lastName,
        $suffix,
        $email,
        $phone,
        $gender,
        $dob,
        $age,
        $civil,
        $address,
        $barangay,
        $city,
        $emName,
        $emRel,
        $emNum,
        $photo,
        $patientId
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Could not update profile.'];
    }
    $stmt->close();
    return ['ok' => true, 'error' => ''];
}

function patientAvatarStyles(): string {
    return '
.patient-avatar-wrap{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:inherit}
.patient-profile-avatar{width:42px;height:42px;border-radius:50%;object-fit:cover;display:inline-flex;align-items:center;justify-content:center;background:#0f7cc2;color:#fff;font-weight:800;border:2px solid #d8ecfb;flex:0 0 auto}
.patient-profile-avatar.sm{width:34px;height:34px;font-size:.78rem}.patient-profile-avatar.md{width:44px;height:44px}.patient-profile-avatar.lg{width:58px;height:58px;font-size:1.05rem}.patient-profile-avatar.xl{width:76px;height:76px;font-size:1.4rem}
.patient-avatar-name{display:grid;gap:2px}.patient-avatar-name strong{color:#073b4c}.patient-avatar-name small{color:#60727d}
';
}

function renderPatientAvatar(array $patient, array $options = []): string {
    $name = patientProfileBuildFullNameFromParts($patient);
    if ($name === '') {
        $name = (string) ($patient['full_name'] ?? $patient['patient_name'] ?? 'Patient');
    }
    $size = (string) ($options['size'] ?? 'md');
    $photo = patientProfilePhotoUrl($patient['profile_photo'] ?? null, $patient['profile_updated_at'] ?? null);
    $initials = patientProfileInitials($name);
    $avatar = $photo
        ? '<img src="' . htmlspecialchars($photo) . '" alt="Patient photo" class="patient-profile-avatar ' . htmlspecialchars($size) . '">'
        : '<span class="patient-profile-avatar ' . htmlspecialchars($size) . '">' . htmlspecialchars($initials) . '</span>';
    if (!empty($options['link'])) {
        $id = (int) ($options['patient_id'] ?? $patient['patient_id'] ?? $patient['id'] ?? 0);
        $linkTarget = (string) ($options['link_target'] ?? '');
        $target = in_array($linkTarget, ['clinical', 'doctor'], true) ? 'nurse_patient.php' : 'staff_patient.php';
        if ($id > 0) {
            return '<a class="patient-avatar-wrap" href="' . htmlspecialchars($target . '?id=' . $id) . '">' . $avatar . '</a>';
        }
    }
    return $avatar;
}

function renderPatientAvatarWithName(array $patient, array $options = []): string {
    $name = patientProfileBuildFullNameFromParts($patient);
    if ($name === '') {
        $name = (string) ($patient['full_name'] ?? $patient['patient_name'] ?? 'Patient');
    }
    $meta = (string) ($patient['username'] ?? $patient['phone'] ?? '');
    $avatar = renderPatientAvatar($patient, $options);
    return '<span class="patient-avatar-wrap">' . $avatar . '<span class="patient-avatar-name"><strong>' . htmlspecialchars($name) . '</strong>' . ($meta !== '' ? '<small>' . htmlspecialchars($meta) . '</small>' : '') . '</span></span>';
}

function fetchPatientsForStaffDirectory(mysqli $conn): array {
    ensurePatientProfilePhotoColumn($conn);
    $rows = [];
    $result = $conn->query("SELECT id, full_name, first_name, middle_name, last_name, suffix, username, email, phone, profile_photo, profile_updated_at FROM users WHERE role='patient' ORDER BY full_name ASC LIMIT 300");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function mergeAppointmentPatientProfile(array $appointment, array $patientsById): array {
    $patientId = (int) ($appointment['patient_id'] ?? 0);
    $directoryPatient = $patientId > 0 && isset($patientsById[$patientId]) && is_array($patientsById[$patientId])
        ? $patientsById[$patientId]
        : [];

    $patientName = trim((string) ($appointment['patient_name'] ?? ''));
    if ($patientName === '') {
        $patientName = trim((string) ($directoryPatient['full_name'] ?? $directoryPatient['patient_name'] ?? ''));
    }
    if ($patientName === '') {
        $patientName = 'Patient';
    }

    $profilePhoto = trim((string) ($appointment['profile_photo'] ?? ''));
    if ($profilePhoto === '') {
        $profilePhoto = trim((string) ($directoryPatient['profile_photo'] ?? ''));
    }

    $profileUpdatedAt = trim((string) ($appointment['profile_updated_at'] ?? ''));
    if ($profileUpdatedAt === '') {
        $profileUpdatedAt = trim((string) ($directoryPatient['profile_updated_at'] ?? ''));
    }

    $merged = array_merge($directoryPatient, $appointment);
    $merged['appointment_id'] = (int) ($appointment['id'] ?? 0);
    $merged['id'] = $patientId;
    $merged['patient_id'] = $patientId;
    $merged['full_name'] = $patientName;
    $merged['patient_name'] = $patientName;
    $merged['profile_photo'] = $profilePhoto !== '' ? $profilePhoto : null;
    $merged['profile_updated_at'] = $profileUpdatedAt !== '' ? $profileUpdatedAt : null;

    return $merged;
}

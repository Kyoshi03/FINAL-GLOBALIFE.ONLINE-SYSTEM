<?php
require_once 'includes/session.php';
checkRole('patient');

require_once 'config/database.php';
require_once 'includes/patient_profile_photo.php';
require_once 'includes/nurse_medical_fields.php';
require_once 'includes/password_reset.php';
require_once 'includes/sms.php';
require_once 'includes/patient_notifications.php';
require_once 'includes/clinic_notifications.php';
require_once 'includes/admin_notifications.php';

const PATIENT_PROFILE_FULL_NAME_MAX = 100;
const PATIENT_PROFILE_NAME_PART_MAX = 15;
const PATIENT_PROFILE_MIDDLE_NAME_MAX = 1;
const PATIENT_PROFILE_SUFFIX_MAX = 3;
const PATIENT_PROFILE_VERIFY_SESSION = 'patient_profile_pending_change';

function patient_profile_mask_email(string $email): string {
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }
    [$name, $domain] = explode('@', $email, 2);
    $prefix = substr($name, 0, 2);
    return $prefix . str_repeat('*', max(3, strlen($name) - 2)) . '@' . $domain;
}

function patient_profile_mask_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return '';
    }
    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function patient_profile_count_letters(string $value): int {
    if ($value === '') {
        return 0;
    }
    preg_match_all('/\p{L}/u', $value, $matches);
    return count($matches[0] ?? []);
}

function patient_profile_normalize_name_part(string $value, bool $allowSeparators = false, bool $forceUppercase = false): string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($value === '') {
        return '';
    }

    $pattern = $allowSeparators ? '/[^\p{L}\s\'-]+/u' : '/[^\p{L}]+/u';
    $value = preg_replace($pattern, '', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

    if ($forceUppercase) {
        $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    return $value;
}

function patient_profile_guess_name_parts(array $row): array {
    $first = trim((string) ($row['first_name'] ?? ''));
    $middle = trim((string) ($row['middle_name'] ?? ''));
    $last = trim((string) ($row['last_name'] ?? ''));
    $suffix = trim((string) ($row['suffix'] ?? ''));
    $full = trim((string) ($row['full_name'] ?? ''));

    if (($first === '' || $last === '') && $full !== '') {
        $tokens = preg_split('/\s+/u', $full) ?: [];
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn ($token) => $token !== ''));

        if ($tokens !== []) {
            $knownSuffixes = ['JR', 'SR', 'II', 'III', 'IV', 'V', 'VI'];
            $lastToken = strtoupper(preg_replace('/[^\p{L}]/u', '', (string) end($tokens)) ?? '');
            if ($suffix === '' && $lastToken !== '' && in_array($lastToken, $knownSuffixes, true)) {
                $suffix = array_pop($tokens) ?: '';
            }

            if ($first === '' && isset($tokens[0])) {
                $first = (string) $tokens[0];
            }

            if ($last === '' && count($tokens) > 1) {
                $last = (string) array_pop($tokens);
            }

            if ($middle === '' && count($tokens) > 2) {
                $middleTokens = array_slice($tokens, 1, max(0, count($tokens) - 2));
                $middleCandidate = trim(implode(' ', $middleTokens));
                if ($middleCandidate !== '' && preg_match('/\p{L}/u', $middleCandidate, $middleMatch)) {
                    $middle = $middleMatch[0] ?? '';
                }
            }
        }
    }

    return [
        'first_name' => function_exists('mb_convert_case')
            ? mb_convert_case(patient_profile_normalize_name_part($first, true, false), MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower(patient_profile_normalize_name_part($first, true, false))),
        'middle_name' => function_exists('mb_strtoupper')
            ? mb_strtoupper(patient_profile_normalize_name_part($middle, false, true), 'UTF-8')
            : strtoupper(patient_profile_normalize_name_part($middle, false, true)),
        'last_name' => function_exists('mb_convert_case')
            ? mb_convert_case(patient_profile_normalize_name_part($last, true, false), MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower(patient_profile_normalize_name_part($last, true, false))),
        'suffix' => function_exists('mb_strtoupper')
            ? mb_strtoupper(patient_profile_normalize_name_part($suffix, false, true), 'UTF-8')
            : strtoupper(patient_profile_normalize_name_part($suffix, false, true)),
    ];
}

function patient_profile_build_name_from_parts(array $parts): string {
    return trim(implode(' ', array_filter([
        patientProfileTitleCaseNamePart((string) ($parts['first_name'] ?? ''), true),
        patientProfileUppercaseNamePart((string) ($parts['middle_name'] ?? ''), false),
        patientProfileTitleCaseNamePart((string) ($parts['last_name'] ?? ''), true),
        patientProfileUppercaseNamePart((string) ($parts['suffix'] ?? ''), false),
    ], static fn ($part) => $part !== '')));
}

function patient_profile_pending_change(): ?array {
    $pending = $_SESSION[PATIENT_PROFILE_VERIFY_SESSION] ?? null;
    if (!is_array($pending)) {
        return null;
    }
    if ((int) ($pending['expires_at'] ?? 0) <= time()) {
        unset($_SESSION[PATIENT_PROFILE_VERIFY_SESSION]);
        return null;
    }
    return $pending;
}

function patient_profile_sensitive_delivery(array $currentUserRow, string $newEmail, string $newPhone, bool $emailChanged, bool $phoneChanged): array {
    $currentEmail = trim((string) ($currentUserRow['email'] ?? ''));
    $currentPhone = trim((string) ($currentUserRow['phone'] ?? ''));
    $newEmail = trim($newEmail);
    $newPhone = trim($newPhone);

    if ($phoneChanged) {
        if (clinic_sms_normalize_phone($newPhone) === null) {
            return ['ok' => false, 'error' => 'Enter a valid Philippine mobile number before changing your phone number.'];
        }
        return [
            'ok' => true,
            'channel' => 'sms',
            'email' => '',
            'phone' => $newPhone,
            'masked' => patient_profile_mask_phone($newPhone),
        ];
    }

    if ($emailChanged && $newEmail !== '') {
        return [
            'ok' => true,
            'channel' => 'email',
            'email' => $newEmail,
            'phone' => $newPhone,
            'masked' => patient_profile_mask_email($newEmail),
        ];
    }

    if (filter_var($currentEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => true,
            'channel' => 'email',
            'email' => $currentEmail,
            'phone' => $currentPhone,
            'masked' => patient_profile_mask_email($currentEmail),
        ];
    }

    $phoneForSms = clinic_sms_normalize_phone($currentPhone) !== null ? $currentPhone : $newPhone;
    if (clinic_sms_normalize_phone($phoneForSms) !== null) {
        return [
            'ok' => true,
            'channel' => 'sms',
            'email' => '',
            'phone' => $phoneForSms,
            'masked' => patient_profile_mask_phone($phoneForSms),
        ];
    }

    return ['ok' => false, 'error' => 'Add a valid email address or Philippine mobile number before changing your email, phone, or password.'];
}

function patient_profile_email_is_taken(mysqli $conn, int $patientId, string $email): bool {
    $email = trim(strtolower($email));
    if ($email === '') {
        return false;
    }
    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1");
    $stmt->bind_param('si', $email, $patientId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

function patient_profile_send_sensitive_code(array $delivery, string $name, array $payload): array {
    $code = (string) random_int(100000, 999999);
    $sent = clinic_send_verification_code(
        (string) $delivery['channel'],
        (string) ($delivery['email'] ?? ''),
        (string) ($delivery['phone'] ?? ''),
        $name,
        $code,
        'profile_update'
    );
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => $sent['error'] ?? 'Could not send the verification code.'];
    }

    $_SESSION[PATIENT_PROFILE_VERIFY_SESSION] = [
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'payload' => $payload,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => time() + 600,
        'attempts' => 0,
        'channel' => (string) $delivery['channel'],
        'email' => (string) ($delivery['email'] ?? ''),
        'phone' => (string) ($delivery['phone'] ?? ''),
        'masked' => (string) ($delivery['masked'] ?? ''),
    ];

    return ['ok' => true, 'masked' => (string) ($delivery['masked'] ?? '')];
}

function patient_profile_apply_sensitive_changes(mysqli $conn, int $patientId, array $payload): array {
    if (array_key_exists('email', $payload)) {
        $email = trim((string) $payload['email']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'The pending email address is invalid.'];
        }
        if (patient_profile_email_is_taken($conn, $patientId, $email)) {
            return ['ok' => false, 'error' => 'That email address is already used by another account.'];
        }
        $stmt = $conn->prepare("UPDATE users SET email = ?, profile_updated_at = NOW() WHERE id = ? AND role = 'patient'");
        $stmt->bind_param('si', $email, $patientId);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['ok' => false, 'error' => 'Could not update the email address.'];
        }
        $stmt->close();
    }

    if (array_key_exists('phone', $payload)) {
        $phone = trim((string) $payload['phone']);
        if (clinic_sms_normalize_phone($phone) === null) {
            return ['ok' => false, 'error' => 'The pending phone number is invalid.'];
        }
        $stmt = $conn->prepare("UPDATE users SET phone = ?, profile_updated_at = NOW() WHERE id = ? AND role = 'patient'");
        $stmt->bind_param('si', $phone, $patientId);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['ok' => false, 'error' => 'Could not update the phone number.'];
        }
        $stmt->close();
    }

    if (!empty($payload['password_hash'])) {
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'patient'");
        $stmt->bind_param('si', $payload['password_hash'], $patientId);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['ok' => false, 'error' => 'Could not update the password.'];
        }
        $stmt->close();
    }

    return ['ok' => true, 'error' => ''];
}

$pageTitle = "Patient Dashboard | Globalife Medical Laboratory & Polyclinic";
$currentUser = getCurrentUser();
$pageMessage = $_SESSION['patient_dashboard_message'] ?? '';
$pageError = $_SESSION['patient_dashboard_error'] ?? '';
unset($_SESSION['patient_dashboard_message'], $_SESSION['patient_dashboard_error']);
$profileMessage = '';
$profileError = '';

$conn = getDBConnection();
ensurePatientProfilePhotoColumn($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['patient_action'] ?? '') === 'cancel_appointment') {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    if ($appointmentId <= 0) {
        $_SESSION['patient_dashboard_error'] = 'Invalid appointment.';
    } else {
        $cancelStatus = 'cancelled';
        $cancel = $conn->prepare("UPDATE appointments
            SET status = ?
            WHERE id = ? AND patient_id = ? AND status IN ('pending', 'confirmed')");
        $cancel->bind_param('sii', $cancelStatus, $appointmentId, $currentUser['id']);
        if ($cancel->execute() && $cancel->affected_rows > 0) {
            $_SESSION['patient_dashboard_message'] = 'Appointment cancelled.';
            create_patient_appointment_notification($conn, $appointmentId, 'cancelled');
            create_clinic_appointment_notification($conn, $appointmentId, 'cancelled');
            create_admin_appointment_notification($conn, $appointmentId, 'cancelled');
        } else {
            $_SESSION['patient_dashboard_error'] = 'This appointment cannot be cancelled.';
        }
        $cancel->close();
    }
    $conn->close();
    header('Location: patients.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['patient_action'] ?? '') === 'mark_notifications_read') {
    mark_patient_notifications_read($conn, (int) $currentUser['id']);
    header('Location: patients.php?notifications=1');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['profile_verify_submit'])) {
    $verifyAction = (string) ($_POST['profile_verify_submit'] ?? 'verify');
    $pending = patient_profile_pending_change();

    if ($verifyAction === 'cancel') {
        unset($_SESSION[PATIENT_PROFILE_VERIFY_SESSION]);
        $_SESSION['patient_profile_message'] = 'Pending email, phone, or password change cancelled.';
        $conn->close();
        header('Location: patients.php?profile=1');
        exit;
    }

    if (!$pending || (int) ($pending['user_id'] ?? 0) !== (int) $currentUser['id']) {
        unset($_SESSION[PATIENT_PROFILE_VERIFY_SESSION]);
        $_SESSION['patient_profile_error'] = 'No active verification request was found. Please save your profile again.';
        $conn->close();
        header('Location: patients.php?profile=1');
        exit;
    }

    if ($verifyAction === 'resend') {
        $delivery = [
            'channel' => (string) ($pending['channel'] ?? 'email'),
            'email' => (string) ($pending['email'] ?? ''),
            'phone' => (string) ($pending['phone'] ?? ''),
            'masked' => (string) ($pending['masked'] ?? ''),
        ];
        $sent = patient_profile_send_sensitive_code($delivery, (string) $currentUser['full_name'], (array) ($pending['payload'] ?? []));
        $_SESSION[$sent['ok'] ? 'patient_profile_message' : 'patient_profile_error'] = $sent['ok']
            ? 'A new verification code was sent to ' . ($sent['masked'] ?: 'your contact') . '.'
            : ($sent['error'] ?? 'Could not resend the verification code.');
        $conn->close();
        header('Location: patients.php?profile=verify');
        exit;
    }

    $code = preg_replace('/\D+/', '', (string) ($_POST['profile_verification_code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        $_SESSION['patient_profile_error'] = 'Enter the complete 6-digit verification code.';
        $conn->close();
        header('Location: patients.php?profile=verify');
        exit;
    }

    if ((int) ($pending['attempts'] ?? 0) >= 5) {
        unset($_SESSION[PATIENT_PROFILE_VERIFY_SESSION]);
        $_SESSION['patient_profile_error'] = 'Too many incorrect attempts. Please save your profile again to request a new code.';
        $conn->close();
        header('Location: patients.php?profile=1');
        exit;
    }

    if (!password_verify($code, (string) ($pending['code_hash'] ?? ''))) {
        $_SESSION[PATIENT_PROFILE_VERIFY_SESSION]['attempts'] = (int) ($pending['attempts'] ?? 0) + 1;
        $_SESSION['patient_profile_error'] = 'Incorrect verification code. Please check the latest code sent to ' . ((string) ($pending['masked'] ?? 'your contact')) . '.';
        $conn->close();
        header('Location: patients.php?profile=verify');
        exit;
    }

    $applied = patient_profile_apply_sensitive_changes($conn, (int) $currentUser['id'], (array) ($pending['payload'] ?? []));
    if (!$applied['ok']) {
        $_SESSION['patient_profile_error'] = $applied['error'] ?? 'Could not finish the verified profile change.';
        $conn->close();
        header('Location: patients.php?profile=verify');
        exit;
    }

    unset($_SESSION[PATIENT_PROFILE_VERIFY_SESSION]);
    $_SESSION['patient_profile_message'] = 'Verified. Your email, phone, or password change has been completed.';
    $conn->close();
    header('Location: patients.php?saved=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['profile_action'] ?? '') === 'update_profile') {
    $firstName = patient_profile_normalize_name_part((string) ($_POST['first_name'] ?? ''), true, false);
    $middleName = patient_profile_normalize_name_part((string) ($_POST['middle_name'] ?? ''), false, true);
    $lastName = patient_profile_normalize_name_part((string) ($_POST['last_name'] ?? ''), true, false);
    $suffix = patient_profile_normalize_name_part((string) ($_POST['suffix'] ?? ''), false, true);
    $fullName = patientProfileBuildFullNameFromParts([
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'suffix' => $suffix,
    ]);
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $dateOfBirth = trim($_POST['date_of_birth'] ?? '');
    $civilStatus = trim($_POST['civil_status'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $emergencyName = trim($_POST['emergency_contact_name'] ?? '');
    $emergencyRelationship = trim($_POST['emergency_contact_relationship'] ?? '');
    $emergencyNumber = trim($_POST['emergency_contact_number'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmNewPassword = $_POST['confirm_new_password'] ?? '';
    $allowedGenders = ['', 'Male', 'Female', 'Other'];
    $fullNameLength = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);
    $computedAge = '';
    $parsedBirthDate = null;
    $birthDateIsValid = true;
    $currentProfileStmt = $conn->prepare('SELECT email, phone, password, profile_photo FROM users WHERE id = ? AND role = ? LIMIT 1');
    $patientRole = 'patient';
    $currentProfileStmt->bind_param('is', $currentUser['id'], $patientRole);
    $currentProfileStmt->execute();
    $currentProfileRow = $currentProfileStmt->get_result()->fetch_assoc() ?: [];
    $currentProfileStmt->close();
    $currentEmail = trim((string) ($currentProfileRow['email'] ?? ''));
    $currentPhone = trim((string) ($currentProfileRow['phone'] ?? ''));
    $emailChanged = strtolower($email) !== strtolower($currentEmail);
    $phoneChanged = preg_replace('/\D+/', '', $phone) !== preg_replace('/\D+/', '', $currentPhone);
    $passwordChangeRequested = $newPassword !== '' || $confirmNewPassword !== '' || $currentPassword !== '';
    $pendingSensitivePayload = [];

    if ($dateOfBirth !== '') {
        $parsedBirthDate = DateTime::createFromFormat('!Y-m-d', $dateOfBirth);
        $birthDateErrors = DateTime::getLastErrors();
        $birthDateIsValid = $parsedBirthDate instanceof DateTime
            && ($birthDateErrors === false || ($birthDateErrors['warning_count'] === 0 && $birthDateErrors['error_count'] === 0))
            && $parsedBirthDate->format('Y-m-d') === $dateOfBirth;
    }

    if ($firstName === '' || $lastName === '') {
        $profileError = 'First name and last name are required.';
    } elseif (patient_profile_count_letters($firstName) > PATIENT_PROFILE_NAME_PART_MAX) {
        $profileError = 'First name must not exceed ' . PATIENT_PROFILE_NAME_PART_MAX . ' letters.';
    } elseif (patient_profile_count_letters($lastName) > PATIENT_PROFILE_NAME_PART_MAX) {
        $profileError = 'Last name must not exceed ' . PATIENT_PROFILE_NAME_PART_MAX . ' letters.';
    } elseif ($middleName !== '' && patient_profile_count_letters($middleName) !== PATIENT_PROFILE_MIDDLE_NAME_MAX) {
        $profileError = 'Middle name must be exactly ' . PATIENT_PROFILE_MIDDLE_NAME_MAX . ' letter.';
    } elseif ($suffix !== '' && patient_profile_count_letters($suffix) > PATIENT_PROFILE_SUFFIX_MAX) {
        $profileError = 'Suffix must not exceed ' . PATIENT_PROFILE_SUFFIX_MAX . ' letters.';
    } elseif ($fullName === '') {
        $profileError = 'Please enter your full name.';
    } elseif ($fullNameLength > PATIENT_PROFILE_FULL_NAME_MAX) {
        $profileError = 'Full name must not exceed ' . PATIENT_PROFILE_FULL_NAME_MAX . ' characters.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileError = 'Please enter a valid email address.';
    } elseif ($emailChanged && $email !== '' && patient_profile_email_is_taken($conn, (int) $currentUser['id'], $email)) {
        $profileError = 'That email address is already used by another account.';
    } elseif ($phoneChanged && clinic_sms_normalize_phone($phone) === null) {
        $profileError = 'Please enter a valid Philippine mobile number before changing your phone number.';
    } elseif (!in_array($gender, $allowedGenders, true)) {
        $profileError = 'Please choose a valid gender.';
    } elseif (!$birthDateIsValid) {
        $profileError = 'Please enter a valid date of birth.';
    } elseif ($parsedBirthDate instanceof DateTime && $parsedBirthDate > new DateTime('today')) {
        $profileError = 'Date of birth cannot be in the future.';
    } else {
        if ($passwordChangeRequested) {
            if ($newPassword === '' || $confirmNewPassword === '') {
                $profileError = 'Enter and confirm your new password, or leave all password fields blank.';
            } elseif ($currentPassword === '') {
                $profileError = 'Enter your current password to change it.';
            } elseif ($newPassword !== $confirmNewPassword) {
                $profileError = 'New password and confirmation do not match.';
            } elseif (empty($currentProfileRow['password']) || !password_verify($currentPassword, $currentProfileRow['password'])) {
                $profileError = 'Current password is incorrect.';
            } else {
                $passwordErrors = pw_reset_validate_password($newPassword);
                if ($passwordErrors !== []) {
                    $profileError = implode(' ', $passwordErrors);
                }
            }
        }

        if ($profileError === '' && $emailChanged) {
            $pendingSensitivePayload['email'] = $email;
        }
        if ($profileError === '' && $phoneChanged) {
            $pendingSensitivePayload['phone'] = $phone;
        }
        if ($profileError === '' && $passwordChangeRequested) {
            $pendingSensitivePayload['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        if ($profileError === '') {
            if ($dateOfBirth !== '') {
                $todayDate = new DateTime('today');
                $computedAge = (string) $parsedBirthDate->diff($todayDate)->y;
            }

        $currentPhotoPath = $currentProfileRow['profile_photo'] ?? null;

        $removePhoto = ($_POST['remove_profile_photo'] ?? '') === '1';
        $croppedPhoto = trim($_POST['profile_photo_cropped'] ?? '');
        $newPhotoPath = $currentPhotoPath;

        if ($removePhoto) {
            patientDeleteProfilePhotoFile($currentPhotoPath);
            $newPhotoPath = null;
        } elseif ($croppedPhoto !== '') {
            $savedPhoto = savePatientProfilePhotoFromBase64((int) $currentUser['id'], $croppedPhoto, $currentPhotoPath);
            if ($savedPhoto === null) {
                $profileError = 'Could not save profile photo. Please try a JPG or PNG under 3 MB.';
            } else {
                $newPhotoPath = $savedPhoto;
            }
        }

        if ($profileError === '') {
            $result = updatePatientUserProfile($conn, (int) $currentUser['id'], [
                'full_name' => $fullName,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'suffix' => $suffix,
                'email' => array_key_exists('email', $pendingSensitivePayload) ? $currentEmail : $email,
                'phone' => array_key_exists('phone', $pendingSensitivePayload) ? $currentPhone : $phone,
                'gender' => $gender,
                'date_of_birth' => $dateOfBirth,
                'age' => $computedAge,
                'civil_status' => $civilStatus,
                'address' => $address,
                'barangay' => $barangay,
                'city' => $city,
                'emergency_contact_name' => $emergencyName,
                'emergency_contact_relationship' => $emergencyRelationship,
                'emergency_contact_number' => $emergencyNumber,
            ], $newPhotoPath, $removePhoto);

            if ($result['ok'] && $profileError === '') {
                $_SESSION['full_name'] = $fullName;
                if ($pendingSensitivePayload !== []) {
                    $delivery = patient_profile_sensitive_delivery(
                        $currentProfileRow,
                        $email,
                        $phone,
                        array_key_exists('email', $pendingSensitivePayload),
                        array_key_exists('phone', $pendingSensitivePayload)
                    );
                    if (empty($delivery['ok'])) {
                        $_SESSION['patient_profile_error'] = $delivery['error'] ?? 'Could not prepare profile verification.';
                        $conn->close();
                        header('Location: patients.php?profile=1');
                        exit;
                    }
                    $sent = patient_profile_send_sensitive_code($delivery, $fullName, $pendingSensitivePayload);
                    if (empty($sent['ok'])) {
                        $_SESSION['patient_profile_error'] = 'Profile details were saved, but the verification code could not be sent. ' . ($sent['error'] ?? 'Please try again.');
                        $conn->close();
                        header('Location: patients.php?profile=1');
                        exit;
                    }
                    $_SESSION['patient_profile_message'] = 'Profile details saved. Enter the verification code sent to ' . ($sent['masked'] ?: 'your contact') . ' to finish the email, phone, or password change.';
                    $conn->close();
                    header('Location: patients.php?profile=verify');
                    exit;
                }

                unset($_SESSION[PATIENT_PROFILE_VERIFY_SESSION]);
                $_SESSION['patient_profile_message'] = 'Profile updated successfully.';
                $conn->close();
                header('Location: patients.php?saved=1');
                exit;
            }
            if (!$result['ok']) {
                $profileError = $result['error'];
            }
        }
        }
    }

    if ($profileError !== '') {
        $_SESSION['patient_profile_error'] = $profileError;
        $conn->close();
        header('Location: patients.php?profile=1');
        exit;
    }
}

if (!empty($_SESSION['patient_profile_message'])) {
    $profileMessage = (string) $_SESSION['patient_profile_message'];
    unset($_SESSION['patient_profile_message']);
}
if (!empty($_SESSION['patient_profile_error'])) {
    $profileError = (string) $_SESSION['patient_profile_error'];
    unset($_SESSION['patient_profile_error']);
}
$pendingProfileChange = patient_profile_pending_change();

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$userDetails = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$profileNameParts = patient_profile_guess_name_parts($userDetails);

$stmt = $conn->prepare("SELECT a.*, d.full_name AS doctor_name
    FROM appointments a
    LEFT JOIN users d ON d.id = a.doctor_id AND d.role = 'doctor'
    WHERE a.patient_id = ?
      AND a.status IN ('pending', 'confirmed')
      AND a.appointment_date >= CURDATE()
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 5");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare("SELECT
        SUM(CASE WHEN status IN ('pending', 'confirmed') AND appointment_date >= CURDATE() THEN 1 ELSE 0 END) AS upcoming_count,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
        COUNT(*) AS total_count
    FROM appointments
    WHERE patient_id = ?");
$stmt->bind_param("i", $currentUser['id']);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$patientNotifications = [];
$patientUnreadNotificationCount = 0;

$patientNotifications = fetch_patient_notifications($conn, (int) $currentUser['id'], 20);
$patientUnreadNotificationCount = count_unread_patient_notifications($conn, (int) $currentUser['id']);

$conn->close();

$profileDisplayName = patientProfileBuildFullNameFromParts($userDetails);
if ($profileDisplayName === '') {
    $profileDisplayName = trim((string) ($userDetails['full_name'] ?? $currentUser['full_name']));
}
if ($profileDisplayName === '') {
    $profileDisplayName = 'Patient';
}

$firstName = trim(explode(' ', $profileDisplayName)[0] ?? 'Patient');
$profilePhotoUrl = patientProfilePhotoUrl($userDetails['profile_photo'] ?? null, $userDetails['profile_updated_at'] ?? null);
$profileInitials = patientProfileInitials($profileDisplayName);
$headerPatientPhotoUrl = $profilePhotoUrl;
$headerPatientInitials = $profileInitials;
$headerPatientDisplayName = $profileDisplayName;
$showProfileSuccessPopup = $profileMessage !== '' && $pendingProfileChange === null;
$showProfileErrorPopup = $profileError !== '' && $pendingProfileChange === null;
$showProfileVerificationPopup = $pendingProfileChange !== null;
$openProfileModal = $showProfileErrorPopup || (isset($_GET['profile']) && $_GET['profile'] === '1' && !$showProfileSuccessPopup);
$showNotificationsPage = isset($_GET['notifications']) && $_GET['notifications'] === '1';
$upcomingCount = (int) ($summary['upcoming_count'] ?? 0);
$completedCount = (int) ($summary['completed_count'] ?? 0);
$totalCount = (int) ($summary['total_count'] ?? 0);
$nextAppointment = $appointments[0] ?? null;

$isNewPatientWelcome = !empty($_SESSION['patient_welcome_new']);
unset($_SESSION['patient_welcome_new']);

if (!$isNewPatientWelcome && $totalCount === 0) {
    $createdAt = trim((string) ($userDetails['created_at'] ?? ''));
    if ($createdAt !== '' && ($createdStamp = strtotime($createdAt)) !== false && $createdStamp >= time() - (24 * 3600)) {
        $isNewPatientWelcome = true;
    }
}

$welcomeTitle = $isNewPatientWelcome
    ? 'Welcome, ' . $firstName . '!'
    : 'Welcome back, ' . $firstName;
$welcomeSubtitle = $isNewPatientWelcome
    ? 'Your patient account is ready. Book your first appointment below and bring a valid ID when you visit Globalife.'
    : 'Book appointments, check your upcoming schedule, and keep your contact details ready for clinic updates.';
$showGetStartedPanel = $isNewPatientWelcome || ($totalCount === 0 && $upcomingCount === 0);

$hideProfileStepInGetStarted = trim((string) ($profileDisplayName ?? '')) !== ''
    && trim((string) ($userDetails['email'] ?? '')) !== ''
    && trim((string) ($userDetails['phone'] ?? '')) !== ''
    && trim((string) ($userDetails['gender'] ?? '')) !== ''
    && trim((string) ($userDetails['date_of_birth'] ?? '')) !== ''
    && trim((string) ($userDetails['barangay'] ?? '')) !== ''
    && trim((string) ($userDetails['city'] ?? '')) !== '';

$profileFields = [
    'Full name' => $profileDisplayName,
    'Email' => $userDetails['email'] ?? '',
    'Phone' => $userDetails['phone'] ?? '',
    'Gender' => $userDetails['gender'] ?? '',
    'Date of birth' => $userDetails['date_of_birth'] ?? '',
    'Civil status' => $userDetails['civil_status'] ?? '',
    'Address' => $userDetails['address'] ?? '',
    'Barangay' => $userDetails['barangay'] ?? '',
    'City' => $userDetails['city'] ?? '',
    'Emergency contact' => $userDetails['emergency_contact_number'] ?? '',
];
$filledProfileFields = count(array_filter($profileFields, static fn ($v) => trim((string) $v) !== ''));
$profileScore = count($profileFields) > 0 ? (int) round(($filledProfileFields / count($profileFields)) * 100) : 0;
$readinessItems = [
    [
        'label' => 'Email reminders',
        'detail' => trim((string) ($userDetails['email'] ?? '')) !== '' ? (string) $userDetails['email'] : 'Add an email address',
        'ready' => trim((string) ($userDetails['email'] ?? '')) !== '',
    ],
    [
        'label' => 'SMS/contact calls',
        'detail' => trim((string) ($userDetails['phone'] ?? '')) !== '' ? (string) $userDetails['phone'] : 'Add your phone number',
        'ready' => trim((string) ($userDetails['phone'] ?? '')) !== '',
    ],
    [
        'label' => 'Emergency contact',
        'detail' => trim((string) ($userDetails['emergency_contact_number'] ?? '')) !== '' ? (string) $userDetails['emergency_contact_number'] : 'Add emergency number',
        'ready' => trim((string) ($userDetails['emergency_contact_number'] ?? '')) !== '',
    ],
    [
        'label' => 'Complete address',
        'detail' => trim((string) ($userDetails['address'] ?? '')) !== '' ? 'Address saved' : 'Add your current address',
        'ready' => trim((string) ($userDetails['address'] ?? '')) !== '',
    ],
];

function patient_format_date(?string $date): string {
    if (!$date) {
        return 'Not set';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt ? $dt->format('M d, Y') : $date;
}

function patient_format_time(?string $time): string {
    if (!$time) {
        return 'Not set';
    }
    $dt = DateTime::createFromFormat('H:i:s', $time) ?: DateTime::createFromFormat('H:i', $time);
    return $dt ? $dt->format('g:i A') : substr($time, 0, 5);
}

function patient_booking_label(?string $type): string {
    if ($type === 'consultation') {
        return 'Doctor consultation';
    }
    if ($type === 'package') {
        return 'Laboratory package';
    }
    if ($type === 'individual') {
        return 'Laboratory tests';
    }
    if ($type === 'ultrasound') {
        return 'Ultra sound';
    }
    return 'Clinic visit';
}

function patient_status_class(?string $status): string {
    $safe = strtolower((string) $status);
    return in_array($safe, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $safe : 'pending';
}

function patient_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function patient_format_datetime(?string $dateTime): string {
    if (!$dateTime) {
        return 'Not set';
    }
    $stamp = strtotime($dateTime);
    return $stamp ? date('M d, Y g:i A', $stamp) : $dateTime;
}

function patient_short_text(?string $text, int $limit = 90): string {
    $text = trim((string) $text);
    if ($text === '') {
        return 'No details posted.';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function patient_notification_meta(array $notification): array {
    $type = strtolower((string) ($notification['notification_type'] ?? ''));
    if (strpos($type, 'confirmed') !== false || strpos($type, 'approved') !== false) {
        return ['label' => 'Approved', 'class' => 'approved', 'icon' => 'M9 12l2 2 4-5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'];
    }
    if (strpos($type, 'cancelled') !== false) {
        return ['label' => 'Cancelled', 'class' => 'cancelled', 'icon' => 'M18 6 6 18M6 6l12 12'];
    }
    if (strpos($type, 'completed') !== false) {
        return ['label' => 'Completed', 'class' => 'completed', 'icon' => 'M9 12l2 2 4-5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'];
    }
    if (strpos($type, 'rescheduled') !== false) {
        return ['label' => 'Rescheduled', 'class' => 'rescheduled', 'icon' => 'M21 12a9 9 0 1 1-3-6.7M21 3v6h-6'];
    }
    if (strpos($type, 'booked') !== false) {
        return ['label' => 'Pending', 'class' => 'pending', 'icon' => 'M8 2v4M16 2v4M3 10h18 M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2z'];
    }
    return ['label' => 'Update', 'class' => 'update', 'icon' => 'M8 2v4M16 2v4M3 10h18 M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2z'];
}

$additionalStyles = '
    body {
        background: #f4f8fb;
        min-height: 100vh;
        color: #1f343d;
    }

    .patient-shell {
        max-width: 1180px;
        margin: 0 auto;
        padding: 28px 20px 42px;
    }

    .dashboard-layout section {
        padding-top: 0;
        padding-bottom: 0;
    }

    .patient-shell > .clinic-tips-bar {
        padding-top: 0;
        padding-bottom: 0;
    }

    .patient-hero {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        gap: 28px;
        align-items: center;
        background: #073b4c;
        color: #fff;
        border-radius: 8px;
        padding: 26px 30px;
        margin-bottom: 18px;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.18);
    }

    .patient-hero > div:not(.hero-actions) {
        min-width: 0;
        padding: 3px 0;
    }

    .patient-kicker {
        display: inline-flex;
        align-items: center;
        color: #a9edf2;
        font-size: 0.86rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .patient-hero h2 {
        margin: 0 0 10px;
        font-size: clamp(1.7rem, 3vw, 2.5rem);
        line-height: 1.14;
        color: #fff;
    }

    .patient-hero p {
        margin: 0;
        max-width: 720px;
        color: rgba(255, 255, 255, 0.82);
        line-height: 1.55;
    }

    .patient-hero.is-new-welcome {
        background: linear-gradient(135deg, #0077b6 0%, #073b4c 100%);
    }

    .new-welcome-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 10px;
        padding: 6px 12px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.16);
        color: #caf0f8;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .clinic-tips-bar {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .clinic-procedure-head {
        grid-column: 1 / -1;
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 2px;
    }

    .clinic-procedure-head h3 {
        margin: 0 0 4px;
        color: #073b4c;
        font-size: 1.15rem;
    }

    .clinic-procedure-head p {
        margin: 0;
        color: #60727d;
        font-size: 0.88rem;
        line-height: 1.45;
    }

    .clinic-tip-card {
        position: relative;
        background: #fff;
        border: 1px solid #dceef2;
        border-radius: 10px;
        padding: 16px;
        box-shadow: 0 8px 18px rgba(7, 59, 76, 0.05);
    }

    .clinic-step-number {
        display: inline-grid;
        place-items: center;
        width: 32px;
        height: 32px;
        margin-bottom: 10px;
        border-radius: 50%;
        background: #e5f5fb;
        color: #006b9f;
        font-size: 0.9rem;
        font-weight: 900;
    }

    .clinic-tip-card strong {
        display: block;
        color: #073b4c;
        font-size: 0.95rem;
        margin-bottom: 6px;
    }

    .clinic-tip-card p {
        margin: 0;
        color: #566872;
        font-size: 0.86rem;
        line-height: 1.5;
    }

    .get-started-panel {
        background: linear-gradient(135deg, #e8f8fc 0%, #f0faf9 100%);
        border: 1px solid rgba(72, 202, 228, 0.45);
        border-radius: 12px;
        padding: 20px 22px;
        margin-bottom: 18px;
    }

    .get-started-panel h3 {
        margin: 0 0 8px;
        color: #073b4c;
        font-size: 1.1rem;
    }

    .get-started-panel > p {
        margin: 0 0 16px;
        color: #566872;
        font-size: 0.92rem;
        line-height: 1.55;
    }

    .get-started-steps {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .get-started-steps li {
        background: #fff;
        border: 1px solid #d0ebf2;
        border-radius: 10px;
        padding: 14px;
        font-size: 0.88rem;
        color: #435761;
        line-height: 1.5;
    }

    .get-started-steps li strong {
        display: block;
        color: #0077b6;
        margin-bottom: 4px;
    }

    .get-started-steps a {
        color: #0077b6;
        font-weight: 700;
        text-decoration: none;
    }

    .get-started-steps a:hover {
        text-decoration: underline;
    }

    .hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        justify-content: flex-end;
        align-items: center;
    }

    .primary-btn,
    .secondary-btn,
    .plain-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 42px;
        padding: 10px 16px;
        border-radius: 8px;
        font-weight: 700;
        text-decoration: none;
        border: 1px solid transparent;
        transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    }

    .primary-btn {
        background: #0f7cc2;
        color: #fff;
        box-shadow: 0 10px 20px rgba(15, 124, 194, 0.24);
    }

    .primary-btn:hover {
        background: #0b4f80;
        transform: translateY(-1px);
    }

    .secondary-btn {
        background: #fff;
        color: #0b4f80;
        border-color: rgba(255, 255, 255, 0.45);
    }

    .secondary-btn:hover {
        border-color: #90e0ef;
        transform: translateY(-1px);
    }

    .plain-btn {
        background: #eef7ff;
        color: #0b4f80;
        border-color: #d4e6f5;
    }

    .plain-btn:hover {
        background: #dff1ff;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .summary-tile {
        background: #fff;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 16px;
    }

    .summary-label {
        display: block;
        color: #60727d;
        font-size: 0.84rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .summary-value {
        color: #073b4c;
        font-size: 1.55rem;
        font-weight: 800;
        line-height: 1;
    }

    .dashboard-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.75fr);
        gap: 16px;
        align-items: start;
    }

    .dashboard-layout > div,
    .dashboard-layout > aside {
        min-width: 0;
        display: grid;
        gap: 16px;
    }

    .panel {
        min-width: 0;
        box-sizing: border-box;
        background: #fff;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    }

    .dashboard-layout section.panel {
        padding: 20px;
    }

    .panel + .panel {
        margin-top: 0;
    }

    .panel-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 16px;
    }

    .panel-header > div {
        min-width: 0;
    }

    .panel-header h3 {
        margin: 0;
        color: #073b4c;
        font-size: 1.24rem;
    }

    .panel-header p {
        margin: 4px 0 0;
        color: #60727d;
        font-size: 0.92rem;
        line-height: 1.45;
    }

    .panel-header .plain-btn {
        flex: 0 0 auto;
        width: auto;
        min-width: 78px;
        min-height: 40px;
        padding: 8px 14px;
        white-space: nowrap;
    }

    .patient-notifications-page {
        max-width: 920px;
        margin: 0 auto;
    }

    .patient-notifications-hero {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 18px;
        padding: 24px 26px;
        margin-bottom: 16px;
        border: 1px solid #cde8f3;
        border-radius: 18px;
        background:
            radial-gradient(circle at 88% 10%, rgba(72, 202, 228, 0.22), transparent 32%),
            linear-gradient(135deg, #ffffff 0%, #f3fbff 100%);
        box-shadow: 0 18px 42px rgba(2, 62, 138, 0.08);
    }

    .patient-notifications-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 9px;
        color: #0077b6;
        font-weight: 950;
    }

    .patient-notifications-kicker svg,
    .patient-notification-card-icon svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.3;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .patient-notifications-hero h3 {
        margin: 0;
        color: #10233f;
        font-size: 1.55rem;
    }

    .patient-notifications-hero p {
        margin: 8px 0 0;
        color: #60727d;
        line-height: 1.45;
    }

    .patient-notifications-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 86px;
        min-height: 38px;
        padding: 0 14px;
        border-radius: 999px;
        background: #eaf8fc;
        color: #0077b6;
        font-weight: 950;
        white-space: nowrap;
    }

    .patient-notifications-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .patient-notification-list {
        display: grid;
        gap: 12px;
        max-height: 620px;
        overflow-y: auto;
        padding-right: 6px;
        scrollbar-width: thin;
        scrollbar-color: #9bd2e9 #eef8fc;
    }

    .patient-notification-card {
        display: grid;
        grid-template-columns: 48px minmax(0, 1fr) auto;
        gap: 14px;
        align-items: center;
        padding: 18px;
        border: 1px solid #cde8f3;
        border-radius: 16px;
        background: linear-gradient(135deg, #f8fdff 0%, #ffffff 100%);
        color: #344357;
        text-decoration: none;
        box-shadow: 0 12px 28px rgba(2, 62, 138, 0.06);
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }

    .patient-notification-card:hover {
        transform: translateY(-1px);
        border-color: #8fd6ee;
        box-shadow: 0 16px 32px rgba(2, 62, 138, 0.10);
    }

    .patient-notification-card.unread {
        border-color: #7fd0e8;
        background: linear-gradient(135deg, #eefaff 0%, #ffffff 100%);
    }

    .patient-notification-card-icon {
        display: inline-grid;
        place-items: center;
        width: 42px;
        height: 42px;
        border-radius: 14px;
        background: #dff6ff;
        color: #0077b6;
    }

    .patient-notification-card.completed .patient-notification-card-icon,
    .patient-notification-card.approved .patient-notification-card-icon {
        background: #e6f8ef;
        color: #0f7a48;
    }

    .patient-notification-card.cancelled .patient-notification-card-icon {
        background: #fff1f2;
        color: #be123c;
    }

    .patient-notification-card.rescheduled .patient-notification-card-icon {
        background: #fff7db;
        color: #8a5b00;
    }

    .patient-notification-card h4 {
        margin: 0 0 5px;
        color: #10233f;
        font-size: 1rem;
    }

    .patient-notification-card p {
        margin: 0;
        color: #53677a;
        font-size: 0.9rem;
        line-height: 1.45;
    }

    .patient-notification-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-top: 10px;
        color: #8b99aa;
        font-size: 0.78rem;
        font-weight: 800;
    }

    .patient-notification-status {
        display: inline-flex;
        padding: 4px 9px;
        border-radius: 999px;
        background: #eaf8fc;
        color: #0077b6;
        font-size: 0.72rem;
        font-weight: 950;
        text-transform: uppercase;
    }

    .patient-notification-card-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 36px;
        padding: 0 14px;
        border-radius: 11px;
        background: #eaf8fc;
        color: #0077b6;
        font-size: 0.82rem;
        font-weight: 950;
        white-space: nowrap;
    }

    .patient-notification-card:hover .patient-notification-card-action {
        background: #0077b6;
        color: #ffffff;
    }

    .next-card {
        border: 1px solid #d4e6f5;
        background: #f7fbff;
        border-radius: 8px;
        padding: 16px;
        margin-bottom: 14px;
    }

    .next-card-title {
        color: #0b4f80;
        font-weight: 800;
        margin-bottom: 8px;
    }

    .appointment-list {
        display: grid;
        gap: 10px;
    }

    .appointment-row {
        display: grid;
        grid-template-columns: 92px minmax(0, 1fr) auto;
        gap: 14px;
        align-items: center;
        border: 1px solid #e6eef5;
        border-radius: 8px;
        padding: 14px;
    }

    .date-box {
        text-align: center;
        background: #eef7ff;
        border: 1px solid #d4e6f5;
        border-radius: 8px;
        padding: 10px 8px;
        color: #0b4f80;
        font-weight: 800;
    }

    .date-box small {
        display: block;
        color: #60727d;
        font-size: 0.75rem;
        font-weight: 700;
        margin-top: 2px;
    }

    .appointment-main strong {
        display: block;
        color: #073b4c;
        margin-bottom: 5px;
    }

    .appointment-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 14px;
        color: #60727d;
        font-size: 0.91rem;
    }

    .appointment-side {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: flex-end;
    }

    .cancel-inline {
        border: 1px solid #ffd0d5;
        border-radius: 8px;
        background: #fff0f0;
        color: #9d1c2c;
        cursor: pointer;
        font-weight: 800;
        padding: 8px 11px;
    }

    .cancel-inline:hover {
        background: #ffe1e5;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 92px;
        border-radius: 999px;
        padding: 7px 12px;
        font-size: 0.78rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .status-badge.pending {
        background: #fff7e3;
        color: #8a5a00;
    }

    .status-badge.confirmed {
        background: #e7f7ed;
        color: #17643a;
    }

    .status-badge.completed {
        background: #e8f4f8;
        color: #0b4f80;
    }

    .status-badge.cancelled {
        background: #fff0f0;
        color: #9d1c2c;
    }

    .empty-state {
        text-align: center;
        border: 1px dashed #bdd7ea;
        border-radius: 8px;
        padding: 28px 18px;
        background: #f8fbff;
    }

    .empty-state h3 {
        margin: 0 0 8px;
        color: #073b4c;
    }

    .empty-state p {
        margin: 0 0 16px;
        color: #60727d;
    }

    .profile-list {
        display: grid;
        gap: 10px;
    }

    .profile-alert {
        border-radius: 8px;
        padding: 11px 12px;
        margin-bottom: 14px;
        font-weight: 700;
        font-size: 0.9rem;
    }

    .profile-alert.success {
        background: #e7f7ed;
        color: #17643a;
        border: 1px solid #bfe6ce;
    }

    .profile-alert.error {
        background: #fff0f0;
        color: #9d1c2c;
        border: 1px solid #ffd0d5;
    }

    .profile-verification-overlay {
        position: fixed;
        inset: 0;
        background: rgba(7, 59, 76, .52);
        z-index: 1300;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
    }

    .profile-verification-overlay.active {
        display: flex;
    }

    .profile-verification-card {
        width: min(520px, 100%);
        background: #fff;
        border-radius: 14px;
        border: 1px solid #cfe2f2;
        box-shadow: 0 22px 60px rgba(7, 59, 76, .25);
        padding: 24px;
        display: grid;
        gap: 14px;
    }

    .profile-verification-card h3 {
        margin: 0;
        color: #073b4c;
        font-size: 1.45rem;
    }

    .profile-verification-card p {
        margin: 0;
        color: #526b78;
        line-height: 1.5;
    }

    .profile-verification-alert {
        border-radius: 8px;
        padding: 10px 12px;
        font-weight: 800;
        font-size: .9rem;
    }

    .profile-verification-alert.success {
        background: #e7f7ed;
        color: #17643a;
        border: 1px solid #bfe6ce;
    }

    .profile-verification-alert.error {
        background: #fff0f0;
        color: #9d1c2c;
        border: 1px solid #ffd0d5;
    }

    .profile-verification-code {
        width: 100%;
        min-height: 62px;
        border: 1px solid #cfe2f2;
        border-radius: 10px;
        padding: 12px 14px;
        font: inherit;
        font-size: 1.35rem;
        font-weight: 900;
        letter-spacing: 10px;
        text-align: center;
        color: #073b4c;
        box-sizing: border-box;
    }

    .profile-verification-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .profile-verification-actions button {
        min-height: 46px;
        padding-inline: 18px;
    }

    .field-hint {
        color: #6b7e89;
        font-size: .82rem;
        font-weight: 700;
    }

    .profile-form {
        display: grid;
        gap: 12px;
    }

    .profile-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .profile-field {
        display: grid;
        gap: 6px;
    }

    .profile-field.full {
        grid-column: 1 / -1;
    }

    .profile-field label {
        color: #60727d;
        font-size: 0.84rem;
        font-weight: 800;
    }

    .profile-field input,
    .profile-field select,
    .profile-field textarea {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d4e6f5;
        border-radius: 8px;
        color: #1f343d;
        font: inherit;
        min-height: 42px;
        padding: 10px 11px;
        background: #fff;
    }

    .profile-field textarea {
        min-height: 74px;
        resize: vertical;
    }

    .profile-field input:focus,
    .profile-field select:focus,
    .profile-field textarea:focus {
        border-color: #0f7cc2;
        box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.1);
        outline: none;
    }

    .profile-password-section {
        margin-top: 20px;
        padding-top: 18px;
        border-top: 1px solid #e0ebf3;
    }

    .profile-password-section h4 {
        margin: 0 0 6px;
        color: #073b4c;
        font-size: 1rem;
    }

    .profile-password-section > p {
        margin: 0 0 14px;
        color: #60727d;
        font-size: 0.86rem;
        line-height: 1.5;
    }

    .record-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .record-column {
        min-width: 0;
    }

    .record-column h4 {
        margin: 0 0 10px;
        color: #073b4c;
        font-size: 1rem;
    }

    .record-list {
        display: grid;
        gap: 10px;
    }

    .record-card {
        border: 1px solid #e0ebf3;
        border-left: 3px solid #0f7cc2;
        border-radius: 8px;
        background: #f8fbff;
        padding: 12px;
    }

    .record-card strong {
        display: block;
        color: #073b4c;
        margin-bottom: 4px;
    }

    .record-card small {
        display: block;
        color: #60727d;
        font-weight: 700;
        margin-bottom: 6px;
    }

    .record-card p {
        margin: 0;
        color: #364d58;
        line-height: 1.45;
        font-size: 0.92rem;
    }

    .readiness-list {
        display: grid;
        gap: 9px;
    }

    .readiness-item {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 11px 12px;
        background: #fff;
    }

    .readiness-item strong {
        display: block;
        color: #073b4c;
        line-height: 1.35;
        margin-bottom: 3px;
    }

    .readiness-item span {
        display: block;
        color: #60727d;
        font-size: 0.88rem;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    .readiness-status {
        align-self: center;
        color: #17643a;
        font-weight: 900;
        white-space: nowrap;
    }

    .readiness-status.missing {
        color: #9d1c2c;
    }

    .profile-item {
        display: grid;
        grid-template-columns: 112px minmax(0, 1fr);
        gap: 10px;
        color: #263f4a;
        font-size: 0.92rem;
    }

    .profile-item span {
        color: #60727d;
        font-weight: 700;
    }

    .progress-track {
        width: 100%;
        height: 8px;
        background: #e7f0f7;
        border-radius: 999px;
        overflow: hidden;
        margin: 10px 0 8px;
    }

    .progress-bar {
        height: 100%;
        width: var(--value);
        background: #0f7cc2;
    }

    .action-list {
        display: grid;
        gap: 10px;
    }

    .action-link {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 14px;
        border: 1px solid #e0ebf3;
        border-radius: 8px;
        padding: 13px 14px;
        text-decoration: none;
        color: #073b4c;
        font-weight: 700;
        background: #fff;
    }

    .action-link:hover {
        background: #f7fbff;
        border-color: #bdd7ea;
    }

    .action-link small {
        display: block;
        color: #60727d;
        font-weight: 500;
        margin-top: 3px;
    }

    .patient-profile-trigger {
        border: none;
        background: transparent;
        padding: 0;
        cursor: pointer;
        border-radius: 50%;
        position: relative;
        flex-shrink: 0;
    }

    .patient-profile-trigger:focus-visible {
        outline: 3px solid #90e0ef;
        outline-offset: 4px;
    }

    .patient-profile-avatar {
        width: 88px;
        height: 88px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid rgba(255, 255, 255, 0.85);
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.2);
        display: block;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        font-size: 1.6rem;
        font-weight: 800;
        line-height: 88px;
        text-align: center;
    }

    .patient-profile-trigger .edit-badge {
        position: absolute;
        right: 0;
        bottom: 0;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #0f7cc2;
        color: #fff;
        border: 2px solid #073b4c;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        font-weight: 800;
    }

    .profile-card-mini {
        display: flex;
        align-items: center;
        gap: 14px;
        border: 1px solid #d4e6f5;
        background: #f7fbff;
        border-radius: 8px;
        padding: 14px;
        margin-bottom: 14px;
        cursor: pointer;
        text-align: left;
        width: 100%;
        font: inherit;
        color: inherit;
        transition: border-color 0.2s ease, background 0.2s ease;
    }

    .profile-card-mini:hover {
        border-color: #0f7cc2;
        background: #eef7ff;
    }

    .profile-card-mini .patient-profile-avatar {
        width: 64px;
        height: 64px;
        line-height: 64px;
        font-size: 1.2rem;
    }

    .profile-card-mini strong {
        display: block;
        color: #073b4c;
        margin-bottom: 4px;
    }

    .profile-card-mini span {
        color: #60727d;
        font-size: 0.88rem;
    }

    .profile-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(7, 59, 76, 0.55);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .profile-modal-overlay.active {
        display: flex;
    }

    .profile-modal {
        width: min(1080px, calc(100vw - 32px));
        max-height: 92vh;
        overflow: auto;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 24px 60px rgba(7, 59, 76, 0.28);
        border: 1px solid #e0ebf3;
    }

    .profile-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid #e0ebf3;
        background: #f7fbff;
    }

    .profile-modal-header h3 {
        margin: 0;
        color: #073b4c;
        font-size: 1.2rem;
    }

    .profile-modal-close {
        border: none;
        background: #eef7ff;
        color: #0b4f80;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        font-size: 1.4rem;
        cursor: pointer;
        line-height: 1;
    }

    .profile-modal-body {
        padding: 20px;
        display: grid;
        grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
        gap: 24px;
        align-items: start;
    }

    .photo-editor-panel {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d7e6f0;
        border-radius: 10px;
        padding: 16px;
        background: #fff;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.08);
    }

    .photo-editor-panel h4 {
        margin: 0 0 6px;
        color: #073b4c;
        font-size: 1rem;
    }

    .photo-help-text {
        margin: 0 0 14px;
        color: #60727d;
        font-size: 0.84rem;
        line-height: 1.45;
    }

    .photo-preview-wrap,
    .crop-stage {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        aspect-ratio: 1;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 12px;
        position: relative;
    }

    .photo-preview-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
        background: #edf5fb;
        border: 1px solid #cfe0ec;
        cursor: pointer;
    }

    .photo-preview-wrap:hover .photo-preview-overlay {
        background: rgba(7, 59, 76, 0.94);
        transform: translateY(-2px);
    }

    .photo-preview-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .photo-preview-wrap .patient-profile-avatar {
        width: 100%;
        height: 100%;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: 0;
        box-shadow: none;
        font-size: 2.2rem;
    }

    .photo-preview-wrap.has-photo .photo-fallback {
        display: none;
    }

    .photo-preview-overlay {
        position: absolute;
        left: 10px;
        right: 10px;
        bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px;
        border-radius: 8px;
        background: rgba(7, 59, 76, 0.88);
        color: #fff;
        text-align: left;
        box-sizing: border-box;
        transition: transform 0.2s ease, background 0.2s ease;
    }

    .photo-preview-icon {
        width: 34px;
        height: 34px;
        flex: 0 0 34px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff;
        color: #0077b6;
        font-size: 1.35rem;
        font-weight: 800;
    }

    .photo-preview-copy,
    .photo-preview-overlay strong,
    .photo-preview-copy span {
        display: block;
    }

    .photo-preview-overlay strong {
        font-size: 0.86rem;
        margin-bottom: 2px;
    }

    .photo-preview-copy span {
        font-size: 0.74rem;
        line-height: 1.35;
        color: #d9f5ff;
    }

    .crop-stage {
        display: none;
        background: #0f2730;
        border: 2px solid #0077b6;
        box-shadow: 0 10px 24px rgba(0, 119, 182, 0.18);
    }

    .crop-stage.active {
        display: block;
    }

    .crop-stage > img {
        display: block;
        max-width: none;
        width: 100%;
    }

    .photo-crop-tip {
        display: none;
        margin: 0 0 10px;
        padding: 9px 10px;
        box-sizing: border-box;
        border-radius: 8px;
        border: 1px solid #bce7f5;
        background: #f0fbff;
        color: #335d6b;
        font-size: 0.8rem;
        line-height: 1.4;
    }

    .photo-crop-tip.active {
        display: block;
    }

    .photo-file-name {
        display: none;
        margin: 0 0 10px;
        padding: 8px 10px;
        box-sizing: border-box;
        border-radius: 8px;
        background: #eef7ff;
        color: #0b4f80;
        font-size: 0.8rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .photo-file-name.active {
        display: block;
    }

    .photo-zoom-control {
        display: none;
        margin: 0 0 10px;
        padding: 10px;
        box-sizing: border-box;
        border-radius: 8px;
        background: #f8fbff;
        border: 1px solid #d4e6f5;
    }

    .photo-zoom-control.active {
        display: block;
    }

    .photo-zoom-control label {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        color: #0b4f80;
        font-size: 0.82rem;
        font-weight: 800;
        margin-bottom: 8px;
    }

    .photo-zoom-control input[type="range"] {
        width: 100%;
        accent-color: #0077b6;
    }

    .photo-toolbar {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 10px;
        width: 100%;
    }

    .photo-toolbar button,
    .photo-upload-label {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #d4e6f5;
        border-radius: 8px;
        padding: 10px 12px;
        font-size: 0.82rem;
        font-weight: 800;
        cursor: pointer;
        text-align: center;
        transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
    }

    .photo-toolbar button {
        background: #fff;
        color: #0b4f80;
    }

    #resetCropBtn {
        grid-column: 1 / -1;
    }

    .photo-upload-label {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-height: 42px;
        background: #0077b6;
        border-color: #0077b6;
        color: #fff;
    }

    .photo-upload-label input {
        display: none;
    }

    .photo-toolbar button:hover,
    .photo-upload-label:hover {
        transform: translateY(-1px);
    }

    .photo-toolbar button:hover {
        background: #eef7ff;
        border-color: #0f7cc2;
    }

    .photo-upload-label:hover {
        background: #005f93;
        border-color: #005f93;
    }

    .photo-remove-row {
        margin-top: 10px;
        padding: 9px 10px;
        box-sizing: border-box;
        border: 1px solid #ffd5d5;
        border-radius: 8px;
        background: #fff8f8;
        font-size: 0.82rem;
        color: #7a4141;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .photo-remove-row input {
        width: 16px;
        height: 16px;
        accent-color: #c1121f;
    }

    .profile-modal-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
    }

    @media (max-width: 900px) {
        .patient-hero,
        .dashboard-layout,
        .summary-grid,
        .record-grid,
        .clinic-tips-bar,
        .get-started-steps {
            grid-template-columns: 1fr;
        }

        .clinic-tips-bar {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .patient-notifications-hero {
            flex-direction: column;
        }

        .patient-notification-card {
            grid-template-columns: 44px minmax(0, 1fr);
            align-items: flex-start;
        }

        .patient-notification-card-action {
            grid-column: 2;
            width: fit-content;
        }

        .hero-actions {
            justify-content: flex-start;
        }

        .appointment-row {
            grid-template-columns: 1fr;
        }

        .appointment-side {
            align-items: flex-start;
        }

        .status-badge {
            width: fit-content;
        }
    }

    @media (max-width: 560px) {
        body.app-role-patient {
            overflow-x: hidden;
        }

        .patient-shell {
            width: 100%;
            max-width: 100%;
            padding: 18px 12px 34px;
            box-sizing: border-box;
            overflow-x: hidden;
        }

        .clinic-tips-bar {
            grid-template-columns: 1fr;
        }

        .patient-hero,
        .panel {
            padding: 18px;
        }

        .hero-actions,
        .primary-btn,
        .secondary-btn,
        .plain-btn {
            width: 100%;
        }

        .profile-item {
            grid-template-columns: 1fr;
            gap: 2px;
        }

        .profile-form-grid {
            grid-template-columns: 1fr;
        }

        .profile-modal-body {
            grid-template-columns: 1fr;
        }

        .patient-hero {
            grid-template-columns: 1fr;
            text-align: center;
            max-width: 100%;
            box-sizing: border-box;
        }

        .patient-hero > .patient-profile-trigger {
            margin: 0 auto;
            display: inline-grid;
            place-items: center;
            width: 88px;
            height: 88px;
            min-height: 0;
            background: transparent;
            border: 0;
            box-shadow: none;
            padding: 0;
        }
    }

    .profile-feedback-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(7, 59, 76, 0.55);
        z-index: 2500;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: profileFadeIn 0.25s ease;
    }
    .profile-feedback-overlay.active {
        display: flex;
    }
    @keyframes profileFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .profile-feedback-box {
        background: #fff;
        border-radius: 16px;
        padding: 32px 28px;
        max-width: 420px;
        width: 100%;
        text-align: center;
        box-shadow: 0 24px 60px rgba(7, 59, 76, 0.28);
        animation: profilePopIn 0.3s ease;
        border-top: 4px solid #48cae4;
    }
    @keyframes profilePopIn {
        from { opacity: 0; transform: scale(0.92) translateY(10px); }
        to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .profile-feedback-box.success { border-top-color: #28a745; }
    .profile-feedback-box.error { border-top-color: #dc3545; }
    .profile-feedback-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 16px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
    }
    .profile-feedback-icon.success {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
    }
    .profile-feedback-icon.error {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
    }
    .profile-feedback-icon svg { width: 28px; height: 28px; }
    .profile-feedback-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #073b4c;
        margin: 0 0 10px;
    }
    .profile-feedback-message {
        color: #60727d;
        font-size: 0.95rem;
        margin: 0 0 24px;
        line-height: 1.5;
    }
    .profile-feedback-btn {
        width: 100%;
        padding: 12px 20px;
        border: none;
        border-radius: 10px;
        font-size: 0.95rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
        transition: all 0.2s ease;
    }
    .profile-feedback-btn.success {
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(72, 202, 228, 0.4);
    }
    .profile-feedback-btn.error {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: #fff;
    }

    /* Globalife blue dashboard palette */
    body.app-role-patient {
        background:
            radial-gradient(circle at 80% 8%, rgba(72, 202, 228, 0.16), transparent 28%),
            linear-gradient(135deg, #f4fbff 0%, #f8fcff 54%, #eef8fc 100%);
    }
    body.app-role-patient .patient-shell {
        max-width: 1180px;
    }
    body.app-role-patient .patient-hero,
    body.app-role-patient .patient-hero.is-new-welcome {
        background:
            radial-gradient(circle at 88% 16%, rgba(202, 240, 248, 0.20), transparent 28%),
            linear-gradient(135deg, #0077b6 0%, #005fa8 42%, #023e8a 100%);
        border: 1px solid rgba(202, 240, 248, 0.28);
        box-shadow: 0 18px 42px rgba(2, 62, 138, 0.18);
    }
    body.app-role-patient .patient-kicker {
        color: #caf0f8;
        font-weight: 800;
    }
    body.app-role-patient .patient-hero p {
        color: rgba(255, 255, 255, 0.88);
    }
    body.app-role-patient .patient-hero .primary-btn,
    body.app-role-patient .primary-btn {
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #ffffff;
        box-shadow: 0 12px 24px rgba(0, 119, 182, 0.22);
    }
    body.app-role-patient .patient-hero .secondary-btn,
    body.app-role-patient .secondary-btn,
    body.app-role-patient .plain-btn {
        background: #ffffff;
        color: #023e8a;
        border-color: #cde8f3;
        box-shadow: 0 10px 20px rgba(2, 62, 138, 0.06);
    }
    body.app-role-patient .clinic-step-number {
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #ffffff;
    }
    body.app-role-patient .clinic-tip-card,
    body.app-role-patient .panel,
    body.app-role-patient .appointment-row,
    body.app-role-patient .profile-card-mini,
    body.app-role-patient .readiness-item,
    body.app-role-patient .record-card,
    body.app-role-patient .next-card,
    body.app-role-patient .empty-state {
        border-color: #d7eaf3;
        box-shadow: 0 12px 28px rgba(2, 62, 138, 0.06);
    }
    body.app-role-patient .clinic-procedure-head h3,
    body.app-role-patient .panel-header h3,
    body.app-role-patient .appointment-main strong,
    body.app-role-patient .profile-card-mini strong {
        color: #023e8a;
    }
    body.app-role-patient .date-box {
        background: #eaf8fc;
        border-color: #cde8f3;
        color: #0077b6;
    }
';

$additionalHeadLinks = '
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
';

include 'includes/header.php';
?>
    <main class="patient-shell">
        <?php if ($showNotificationsPage): ?>
        <section class="patient-notifications-page" aria-labelledby="patientNotificationsTitle">
            <div class="patient-notifications-hero">
                <div>
                    <span class="patient-notifications-kicker">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                        Notifications
                    </span>
                    <h3 id="patientNotificationsTitle">Appointment notifications</h3>
                    <p>Open an update to view the appointment details, approval, cancellation, or schedule change.</p>
                </div>
                <div class="patient-notifications-actions">
                    <span class="patient-notifications-count"><?php echo (int) $patientUnreadNotificationCount; ?> unread</span>
                    <?php if ($patientUnreadNotificationCount > 0): ?>
                        <form method="post" action="patients.php?notifications=1">
                            <input type="hidden" name="patient_action" value="mark_notifications_read">
                            <button type="submit" class="primary-btn">Mark all read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($patientNotifications)): ?>
                <div class="panel">
                    <div class="empty-state">
                        <h3>No notifications yet</h3>
                        <p>Appointment updates from the clinic will appear here.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="patient-notification-list">
                    <?php foreach ($patientNotifications as $notification): ?>
                        <?php
                        $notificationMeta = patient_notification_meta($notification);
                        $notificationUrl = trim((string) ($notification['target_url'] ?? 'view_appointments.php'));
                        $notificationUrl = $notificationUrl !== '' ? $notificationUrl : 'view_appointments.php';
                        $notificationUnread = empty($notification['read_at']);
                        $notificationTimestamp = strtotime((string) ($notification['created_at'] ?? ''));
                        $notificationTime = $notificationTimestamp ? date('M d, Y g:i A', $notificationTimestamp) : '';
                        ?>
                        <a class="patient-notification-card <?php echo htmlspecialchars($notificationMeta['class']); ?><?php echo $notificationUnread ? ' unread' : ''; ?>" href="<?php echo htmlspecialchars($notificationUrl); ?>">
                            <span class="patient-notification-card-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="<?php echo htmlspecialchars($notificationMeta['icon']); ?>"/></svg>
                            </span>
                            <span>
                                <h4><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Appointment update')); ?></h4>
                                <p><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                                <span class="patient-notification-meta">
                                    <?php if ($notificationTime !== ''): ?>
                                        <span><?php echo htmlspecialchars($notificationTime); ?></span>
                                    <?php endif; ?>
                                    <span class="patient-notification-status"><?php echo htmlspecialchars($notificationMeta['label']); ?></span>
                                    <span><?php echo $notificationUnread ? 'Unread' : 'Read'; ?></span>
                                </span>
                            </span>
                            <span class="patient-notification-card-action">Open Details</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php else: ?>
        <section class="patient-hero<?php echo $isNewPatientWelcome ? ' is-new-welcome' : ''; ?>">
            <button type="button" class="patient-profile-trigger" id="openProfileFromHero" aria-label="Open my profile">
                <?php if ($profilePhotoUrl): ?>
                    <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile photo" class="patient-profile-avatar">
                <?php else: ?>
                    <span class="patient-profile-avatar"><?php echo htmlspecialchars($profileInitials); ?></span>
                <?php endif; ?>
                <span class="edit-badge" aria-hidden="true">✎</span>
            </button>
            <div>
                <span class="patient-kicker">My Health Dashboard</span>
                <?php if ($isNewPatientWelcome): ?>
                    <span class="new-welcome-badge">New account</span>
                <?php endif; ?>
                <h2><?php echo htmlspecialchars($welcomeTitle); ?></h2>
                <p><?php echo htmlspecialchars($welcomeSubtitle); ?></p>
            </div>
            <div class="hero-actions">
                <?php if ($isNewPatientWelcome || $upcomingCount === 0): ?>
                    <a href="book_appointment.php?start=1" class="primary-btn">Book Appointment</a>
                <?php else: ?>
                    <a href="book_appointment.php?start=1" class="primary-btn">Book Appointment</a>
                <?php endif; ?>
                <a href="view_appointments.php" class="secondary-btn">View Appointments</a>
            </div>
        </section>

        <?php if ($showGetStartedPanel): ?>
        <section class="get-started-panel" aria-label="Getting started">
            <h3><?php echo $isNewPatientWelcome ? 'Get started with your clinic account' : 'Ready to schedule a visit?'; ?></h3>
            <p><?php echo $isNewPatientWelcome
                ? 'You are all set. Follow these steps for a smooth first visit at Globalife.'
                : 'You have no upcoming appointments. Book a slot when you are ready.'; ?></p>
            <ol class="get-started-steps">
                <?php $getStartedStep = 1; ?>
                <?php if (!$hideProfileStepInGetStarted): ?>
                <li>
                    <strong><?php echo $getStartedStep++; ?>. Complete your profile</strong>
                    Add phone, email, and emergency contact so the clinic can reach you.
                    <a href="#" id="openProfileFromGetStarted">Update profile</a>
                </li>
                <?php endif; ?>
                <li>
                    <strong><?php echo $getStartedStep++; ?>. Book online</strong>
                    Choose clinic consultation or laboratory services and pick a date and time.
                    <a href="book_appointment.php?start=1">Book now</a>
                </li>
                <li>
                    <strong><?php echo $getStartedStep++; ?>. Visit the clinic</strong>
                    Bring a valid ID, arrive on time, and pay at the front desk unless staff advises otherwise.
                </li>
            </ol>
        </section>
        <?php endif; ?>

        <section class="clinic-tips-bar" aria-labelledby="clinicProcedureTitle">
            <div class="clinic-procedure-head">
                <div>
                    <h3 id="clinicProcedureTitle">Your clinic visit, step by step</h3>
                    <p>Follow these four steps from booking your appointment to viewing your clinic records.</p>
                </div>
            </div>
            <div class="clinic-tip-card">
                <span class="clinic-step-number" aria-hidden="true">1</span>
                <strong>Book an appointment</strong>
                <p>Choose a service, review the doctor schedule, then select your preferred date and time.</p>
            </div>
            <div class="clinic-tip-card">
                <span class="clinic-step-number" aria-hidden="true">2</span>
                <strong>Check your confirmation</strong>
                <p>Enter the email verification code and check My Appointments for the latest booking status.</p>
            </div>
            <div class="clinic-tip-card">
                <span class="clinic-step-number" aria-hidden="true">3</span>
                <strong>Prepare for your visit</strong>
                <p>Bring a valid ID and related documents. Arrive 10 to 15 minutes before your schedule.</p>
            </div>
            <div class="clinic-tip-card">
                <span class="clinic-step-number" aria-hidden="true">4</span>
                <strong>Complete your clinic visit</strong>
                <p>Pay at the front desk as instructed, then view posted medical notes and lab results online.</p>
            </div>
        </section>

        <?php if ($pageMessage): ?>
            <div class="profile-alert success"><?php echo htmlspecialchars($pageMessage); ?></div>
        <?php endif; ?>
        <?php if ($pageError): ?>
            <div class="profile-alert error"><?php echo htmlspecialchars($pageError); ?></div>
        <?php endif; ?>

        <div class="dashboard-layout">
            <div>
            <section class="panel">
                <div class="panel-header">
                    <div>
                        <h3>Upcoming Appointments</h3>
                        <p>Your next clinic visits and booking status.</p>
                    </div>
                    <a href="view_appointments.php" class="plain-btn">See all</a>
                </div>

                <?php if ($nextAppointment): ?>
                    <div class="next-card">
                        <div class="next-card-title">Next appointment</div>
                        <div class="appointment-meta">
                            <span><?php echo patient_format_date($nextAppointment['appointment_date']); ?></span>
                            <span><?php echo patient_format_time($nextAppointment['appointment_time']); ?></span>
                            <span><?php echo htmlspecialchars(patient_booking_label($nextAppointment['booking_type'] ?? null)); ?></span>
                            <span>Ref #<?php echo (int) $nextAppointment['id']; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($appointments)): ?>
                    <div class="empty-state">
                        <h3>No upcoming appointments</h3>
                        <p>You have no scheduled visits yet. Start a booking when you are ready.</p>
                        <a href="book_appointment.php?start=1" class="primary-btn">Book Your First Appointment</a>
                    </div>
                <?php else: ?>
                    <div class="appointment-list">
                        <?php foreach ($appointments as $appointment): ?>
                            <?php
                            $statusClass = patient_status_class($appointment['status'] ?? '');
                            $doctorName = trim((string) ($appointment['doctor_name'] ?? ''));
                            $doctorText = $doctorName !== '' ? $doctorName : 'Clinic staff';
                            ?>
                            <article class="appointment-row">
                                <div class="date-box">
                                    <?php echo htmlspecialchars(patient_format_date($appointment['appointment_date'])); ?>
                                    <small><?php echo htmlspecialchars(patient_format_time($appointment['appointment_time'])); ?></small>
                                </div>
                                <div class="appointment-main">
                                    <strong><?php echo htmlspecialchars(patient_booking_label($appointment['booking_type'] ?? null)); ?></strong>
                                    <div class="appointment-meta">
                                        <span>Doctor: <?php echo htmlspecialchars($doctorText); ?></span>
                                        <span>Ref #<?php echo (int) $appointment['id']; ?></span>
                                        <?php if (isset($appointment['total_display_price']) && $appointment['total_display_price'] !== null): ?>
                                            <span>Total: PHP <?php echo number_format((float) $appointment['total_display_price'], 2); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="appointment-side">
                                    <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                        <?php echo htmlspecialchars(ucfirst($appointment['status'] ?? 'Pending')); ?>
                                    </span>
                                    <?php if (in_array($statusClass, ['pending', 'confirmed'], true)): ?>
                                        <form method="post" action="patients.php" onsubmit="return confirm('Cancel this appointment?');">
                                            <input type="hidden" name="patient_action" value="cancel_appointment">
                                            <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                            <button type="submit" class="cancel-inline">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            </div>

            <aside>
                <section class="panel" id="profile">
                    <div class="panel-header">
                        <div>
                            <h3>My Profile</h3>
                            <p>Tap your photo to update profile and picture.</p>
                        </div>
                    </div>

                    <button type="button" class="profile-card-mini" id="openProfileFromCard">
                        <?php if ($profilePhotoUrl): ?>
                            <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Profile photo" class="patient-profile-avatar">
                        <?php else: ?>
                            <span class="patient-profile-avatar"><?php echo htmlspecialchars($profileInitials); ?></span>
                        <?php endif; ?>
                        <div>
                            <strong><?php echo htmlspecialchars($profileDisplayName); ?></strong>
                            <span>Update photo, crop, rotate, and edit details</span>
                        </div>
                    </button>

                    <button type="button" class="primary-btn" id="openProfileFromBtn" style="width:100%;">Edit My Profile</button>

                    <div class="progress-track" aria-label="Profile completeness" style="margin-top:14px;">
                        <div class="progress-bar" style="--value: <?php echo $profileScore; ?>%;"></div>
                    </div>
                    <p style="margin:0;color:#60727d;font-size:.9rem;"><?php echo $profileScore; ?>% complete</p>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h3>Contact Readiness</h3>
                            <p>Details used for reminders and clinic follow-ups.</p>
                        </div>
                    </div>
                    <div class="readiness-list">
                        <?php foreach ($readinessItems as $item): ?>
                            <div class="readiness-item">
                                <div>
                                    <strong><?php echo htmlspecialchars($item['label']); ?></strong>
                                    <span><?php echo htmlspecialchars($item['detail']); ?></span>
                                </div>
                                <div class="readiness-status <?php echo $item['ready'] ? '' : 'missing'; ?>">
                                    <?php echo $item['ready'] ? 'Ready' : 'Missing'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>
        <?php endif; ?>
    </main>

    <div class="profile-modal-overlay" id="patientProfileModal" role="dialog" aria-modal="true" aria-labelledby="patientProfileModalTitle">
        <div class="profile-modal">
            <div class="profile-modal-header">
                <h3 id="patientProfileModalTitle">My Profile</h3>
                <button type="button" class="profile-modal-close" id="closeProfileModal" aria-label="Close profile editor">&times;</button>
            </div>
            <form method="post" action="patients.php?profile=1" class="profile-modal-body" id="patientProfileForm">
                <input type="hidden" name="profile_action" value="update_profile">
                <input type="hidden" name="profile_photo_cropped" id="profile_photo_cropped" value="">

                <div class="photo-editor-panel">
                    <h4>Profile picture</h4>
                    <p class="photo-help-text">Add or change your picture, then crop and zoom it before saving.</p>
                    <label class="photo-preview-wrap<?php echo $profilePhotoUrl ? ' has-photo' : ''; ?>" id="photoPreviewWrap" for="profilePhotoInput" aria-label="Add or change profile picture">
                        <?php if ($profilePhotoUrl): ?>
                            <img src="<?php echo htmlspecialchars($profilePhotoUrl); ?>" alt="Current profile photo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';this.closest('.photo-preview-wrap').classList.remove('has-photo');">
                        <?php endif; ?>
                        <span class="patient-profile-avatar photo-fallback" <?php echo $profilePhotoUrl ? 'style="display:none;"' : ''; ?>><?php echo htmlspecialchars($profileInitials); ?></span>
                        <span class="photo-preview-overlay">
                            <span class="photo-preview-icon">+</span>
                            <span class="photo-preview-copy">
                                <strong><?php echo $profilePhotoUrl ? 'Change photo' : 'Add photo'; ?></strong>
                                <span>Choose a photo, then crop and zoom it here.</span>
                            </span>
                        </span>
                    </label>
                    <div class="crop-stage" id="cropStage">
                        <img src="" alt="Crop preview" id="cropImage">
                    </div>
                    <p class="photo-crop-tip" id="photoCropTip">Drag the photo to crop it. Use zoom until the profile picture looks right.</p>
                    <div class="photo-file-name" id="photoFileName"></div>
                    <div class="photo-zoom-control" id="photoZoomControl">
                        <label for="photoZoomRange">
                            <span>Zoom</span>
                            <span id="photoZoomValue">0%</span>
                        </label>
                        <input type="range" id="photoZoomRange" min="-30" max="70" value="0" step="1">
                    </div>
                    <div class="photo-toolbar" id="photoToolbar" style="display:none;">
                        <button type="button" id="zoomInBtn">Zoom In</button>
                        <button type="button" id="zoomOutBtn">Zoom Out</button>
                        <button type="button" id="resetCropBtn">Reset Crop</button>
                    </div>
                    <label class="photo-upload-label">
                        Add / Change Photo
                        <input type="file" id="profilePhotoInput" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <?php if ($profilePhotoUrl): ?>
                        <div class="photo-remove-row">
                            <input type="checkbox" name="remove_profile_photo" id="remove_profile_photo" value="1">
                            <label for="remove_profile_photo">Remove current photo</label>
                        </div>
                    <?php endif; ?>
                </div>

                <div>
                    <div class="profile-form">
                        <div class="profile-form-grid">
                            <div class="profile-field">
                                <label for="modal_first_name">First name</label>
                                <input type="text" id="modal_first_name" name="first_name" maxlength="40" value="<?php echo htmlspecialchars($profileNameParts['first_name'] ?? ''); ?>" placeholder="Juan" required>
                                <span class="field-hint">15 letters max. <span id="modalFirstNameCounter">0/<?php echo PATIENT_PROFILE_NAME_PART_MAX; ?></span></span>
                            </div>
                            <div class="profile-field">
                                <label for="modal_middle_name">Middle name / initial <span class="optional">(optional)</span></label>
                                <input type="text" id="modal_middle_name" name="middle_name" maxlength="4" value="<?php echo htmlspecialchars($profileNameParts['middle_name'] ?? ''); ?>" placeholder="M">
                                <span class="field-hint">1 letter only. <span id="modalMiddleNameCounter">0/<?php echo PATIENT_PROFILE_MIDDLE_NAME_MAX; ?></span></span>
                            </div>
                            <div class="profile-field">
                                <label for="modal_last_name">Last name</label>
                                <input type="text" id="modal_last_name" name="last_name" maxlength="40" value="<?php echo htmlspecialchars($profileNameParts['last_name'] ?? ''); ?>" placeholder="Dela Cruz" required>
                                <span class="field-hint">15 letters max. <span id="modalLastNameCounter">0/<?php echo PATIENT_PROFILE_NAME_PART_MAX; ?></span></span>
                            </div>
                            <div class="profile-field">
                                <label for="modal_suffix">Suffix <span class="optional">(optional)</span></label>
                                <input type="text" id="modal_suffix" name="suffix" maxlength="6" value="<?php echo htmlspecialchars($profileNameParts['suffix'] ?? ''); ?>" placeholder="Jr">
                                <span class="field-hint">3 letters max. <span id="modalSuffixCounter">0/<?php echo PATIENT_PROFILE_SUFFIX_MAX; ?></span></span>
                            </div>
                            <div class="profile-field">
                                <label for="modal_email">Email</label>
                                <input type="email" id="modal_email" name="email" value="<?php echo htmlspecialchars($userDetails['email'] ?? ''); ?>" placeholder="name@example.com">
                            </div>
                            <div class="profile-field">
                                <label for="modal_phone">Phone</label>
                                <input type="text" id="modal_phone" name="phone" value="<?php echo htmlspecialchars($userDetails['phone'] ?? ''); ?>" placeholder="09XXXXXXXXX">
                            </div>
                            <div class="profile-field">
                                <label for="modal_gender">Gender</label>
                                <select id="modal_gender" name="gender">
                                    <option value="">Select gender</option>
                                    <?php foreach (['Male', 'Female', 'Other'] as $genderOption): ?>
                                        <option value="<?php echo htmlspecialchars($genderOption); ?>" <?php echo (($userDetails['gender'] ?? '') === $genderOption) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($genderOption); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="profile-field">
                                <label for="modal_date_of_birth">Date of birth</label>
                                <input type="date" id="modal_date_of_birth" name="date_of_birth" data-birthday-picker value="<?php echo htmlspecialchars($userDetails['date_of_birth'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_civil_status">Civil status</label>
                                <input type="text" id="modal_civil_status" name="civil_status" value="<?php echo htmlspecialchars($userDetails['civil_status'] ?? ''); ?>" placeholder="Single, Married, etc.">
                            </div>
                            <div class="profile-field">
                                <label>Age</label>
                                <input type="text" value="<?php echo htmlspecialchars($userDetails['age'] ?? 'Auto-calculated'); ?>" readonly>
                            </div>
                            <div class="profile-field full">
                                <label for="modal_address">Address</label>
                                <textarea id="modal_address" name="address" placeholder="House no., street, subdivision"><?php echo htmlspecialchars($userDetails['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="profile-field">
                                <label for="modal_barangay">Barangay</label>
                                <input type="text" id="modal_barangay" name="barangay" value="<?php echo htmlspecialchars($userDetails['barangay'] ?? ''); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_city">City</label>
                                <input type="text" id="modal_city" name="city" value="<?php echo htmlspecialchars($userDetails['city'] ?? ''); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_emergency_contact_name">Emergency contact</label>
                                <input type="text" id="modal_emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($userDetails['emergency_contact_name'] ?? ''); ?>">
                            </div>
                            <div class="profile-field">
                                <label for="modal_emergency_contact_relationship">Relationship</label>
                                <input type="text" id="modal_emergency_contact_relationship" name="emergency_contact_relationship" value="<?php echo htmlspecialchars($userDetails['emergency_contact_relationship'] ?? ''); ?>">
                            </div>
                            <div class="profile-field full">
                                <label for="modal_emergency_contact_number">Emergency number</label>
                                <input type="text" id="modal_emergency_contact_number" name="emergency_contact_number" value="<?php echo htmlspecialchars($userDetails['emergency_contact_number'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="profile-password-section">
                            <h4>Change password (optional)</h4>
                            <p>Leave blank to keep your current password. Use at least 8 characters with upper, lower, number, and special character.</p>
                            <div class="profile-form-grid">
                                <div class="profile-field full">
                                    <label for="modal_current_password">Current password</label>
                                    <input type="password" id="modal_current_password" name="current_password" autocomplete="current-password" placeholder="Required only if changing password">
                                </div>
                                <div class="profile-field">
                                    <label for="modal_new_password">New password</label>
                                    <input type="password" id="modal_new_password" name="new_password" autocomplete="new-password" placeholder="New password">
                                </div>
                                <div class="profile-field">
                                    <label for="modal_confirm_new_password">Confirm new password</label>
                                    <input type="password" id="modal_confirm_new_password" name="confirm_new_password" autocomplete="new-password" placeholder="Confirm new password">
                                </div>
                            </div>
                        </div>

                        <div class="profile-modal-actions">
                            <button type="submit" class="primary-btn">Save Profile</button>
                            <button type="button" class="plain-btn" id="cancelProfileModal">Cancel</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <?php if ($pendingProfileChange): ?>
    <div class="profile-verification-overlay active" id="profileVerificationPopup" role="dialog" aria-modal="true" aria-labelledby="profileVerificationTitle">
        <form method="post" action="patients.php?profile=verify" class="profile-verification-card">
            <h3 id="profileVerificationTitle">Verify email, phone, or password change</h3>
            <p>Enter the 6-digit code sent to <strong><?php echo htmlspecialchars((string) ($pendingProfileChange['masked'] ?? 'your contact')); ?></strong>. Your other profile details are saved, but sensitive changes need this final check.</p>
            <?php if ($profileMessage !== ''): ?>
                <div class="profile-verification-alert success"><?php echo htmlspecialchars($profileMessage); ?></div>
            <?php endif; ?>
            <?php if ($profileError !== ''): ?>
                <div class="profile-verification-alert error"><?php echo htmlspecialchars($profileError); ?></div>
            <?php endif; ?>
            <input type="text" class="profile-verification-code" name="profile_verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" aria-label="Profile verification code" autofocus>
            <div class="profile-verification-actions">
                <button type="submit" name="profile_verify_submit" value="verify" class="primary-btn" formnovalidate>Verify</button>
                <button type="submit" name="profile_verify_submit" value="resend" class="plain-btn" formnovalidate>Resend</button>
                <button type="submit" name="profile_verify_submit" value="cancel" class="plain-btn" formnovalidate>Cancel change</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="profile-feedback-overlay" id="profileSuccessPopup" role="dialog" aria-modal="true" aria-labelledby="profileSuccessTitle">
        <div class="profile-feedback-box success">
            <div class="profile-feedback-icon success">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h3 class="profile-feedback-title" id="profileSuccessTitle">Profile Saved!</h3>
            <p class="profile-feedback-message">Your profile has been saved successfully. All changes including your photo are now updated.</p>
            <button type="button" class="profile-feedback-btn success" id="profileSuccessOk">OK</button>
        </div>
    </div>

    <div class="profile-feedback-overlay" id="profileErrorPopup" role="dialog" aria-modal="true" aria-labelledby="profileErrorTitle">
        <div class="profile-feedback-box error">
            <div class="profile-feedback-icon error">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h3 class="profile-feedback-title" id="profileErrorTitle">Could Not Save Profile</h3>
            <p class="profile-feedback-message" id="profileErrorMessage"><?php echo htmlspecialchars($profileError ?: 'Please check your details and try again.'); ?></p>
            <button type="button" class="profile-feedback-btn error" id="profileErrorOk">Try Again</button>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
    (function() {
        function showProfileFeedback(id) {
            const overlay = document.getElementById(id);
            if (overlay) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function hideProfileFeedback(id) {
            const overlay = document.getElementById(id);
            if (overlay) {
                overlay.classList.remove('active');
                const modal = document.getElementById('patientProfileModal');
                const modalOpen = modal && modal.classList.contains('active');
                if (!modalOpen) {
                    document.body.style.overflow = '';
                }
            }
        }

        const modal = document.getElementById('patientProfileModal');
        const form = document.getElementById('patientProfileForm');
        const photoInput = document.getElementById('profilePhotoInput');
        const cropStage = document.getElementById('cropStage');
        const cropImage = document.getElementById('cropImage');
        const photoToolbar = document.getElementById('photoToolbar');
        const photoPreviewWrap = document.getElementById('photoPreviewWrap');
        const croppedField = document.getElementById('profile_photo_cropped');
        const removePhotoCheckbox = document.getElementById('remove_profile_photo');
        const photoCropTip = document.getElementById('photoCropTip');
        const photoFileName = document.getElementById('photoFileName');
        const photoZoomControl = document.getElementById('photoZoomControl');
        const photoZoomRange = document.getElementById('photoZoomRange');
        const photoZoomValue = document.getElementById('photoZoomValue');
        const modalFirstNameInput = document.getElementById('modal_first_name');
        const modalMiddleNameInput = document.getElementById('modal_middle_name');
        const modalLastNameInput = document.getElementById('modal_last_name');
        const modalSuffixInput = document.getElementById('modal_suffix');
        const modalFirstNameCounter = document.getElementById('modalFirstNameCounter');
        const modalMiddleNameCounter = document.getElementById('modalMiddleNameCounter');
        const modalLastNameCounter = document.getElementById('modalLastNameCounter');
        const modalSuffixCounter = document.getElementById('modalSuffixCounter');
        let cropper = null;
        let pendingCrop = false;
        let zoomLevel = 0;

        function countLetters(value) {
            const matches = String(value || '').match(/\p{L}/gu);
            return matches ? matches.length : 0;
        }

        function sanitizeNameField(value, options) {
            const allowSeparators = !!(options && options.allowSeparators);
            const forceUppercase = !!(options && options.forceUppercase);
            const maxLetters = Number((options && options.maxLetters) || 0);
            let nextValue = String(value || '').replace(/\s+/g, ' ');
            const pattern = allowSeparators ? /[^\p{L}\s'-]/gu : /[^\p{L}]/gu;
            nextValue = nextValue.replace(pattern, '');
            nextValue = nextValue.trim();
            if (forceUppercase) {
                nextValue = nextValue.toUpperCase();
            }

            if (maxLetters > 0) {
                let letters = 0;
                let result = '';
                for (const char of nextValue) {
                    if (/\p{L}/u.test(char)) {
                        if (letters >= maxLetters) {
                            continue;
                        }
                        letters++;
                    }
                    result += char;
                }
                nextValue = result;
            }

            return nextValue;
        }

        function syncProfileNameCounters() {
            if (modalFirstNameInput && modalFirstNameCounter) {
                modalFirstNameCounter.textContent = countLetters(modalFirstNameInput.value) + '/<?php echo PATIENT_PROFILE_NAME_PART_MAX; ?>';
            }
            if (modalMiddleNameInput && modalMiddleNameCounter) {
                modalMiddleNameCounter.textContent = countLetters(modalMiddleNameInput.value) + '/<?php echo PATIENT_PROFILE_MIDDLE_NAME_MAX; ?>';
            }
            if (modalLastNameInput && modalLastNameCounter) {
                modalLastNameCounter.textContent = countLetters(modalLastNameInput.value) + '/<?php echo PATIENT_PROFILE_NAME_PART_MAX; ?>';
            }
            if (modalSuffixInput && modalSuffixCounter) {
                modalSuffixCounter.textContent = countLetters(modalSuffixInput.value) + '/<?php echo PATIENT_PROFILE_SUFFIX_MAX; ?>';
            }
        }

        [
            [modalFirstNameInput, { allowSeparators: true, forceUppercase: false, maxLetters: <?php echo PATIENT_PROFILE_NAME_PART_MAX; ?> }],
            [modalMiddleNameInput, { allowSeparators: false, forceUppercase: true, maxLetters: <?php echo PATIENT_PROFILE_MIDDLE_NAME_MAX; ?> }],
            [modalLastNameInput, { allowSeparators: true, forceUppercase: false, maxLetters: <?php echo PATIENT_PROFILE_NAME_PART_MAX; ?> }],
            [modalSuffixInput, { allowSeparators: false, forceUppercase: true, maxLetters: <?php echo PATIENT_PROFILE_SUFFIX_MAX; ?> }]
        ].forEach(function(pair) {
            const input = pair[0];
            const options = pair[1];
            if (!input) return;
            input.addEventListener('input', function() {
                const nextValue = sanitizeNameField(input.value, options);
                if (nextValue !== input.value) {
                    const pos = input.selectionStart;
                    input.value = nextValue;
                    if (typeof pos === 'number') {
                        input.setSelectionRange(Math.min(pos, nextValue.length), Math.min(pos, nextValue.length));
                    }
                }
                syncProfileNameCounters();
            });
        });

        syncProfileNameCounters();

        function openProfileModal() {
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeProfileModal() {
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        ['openProfileFromHero', 'openProfileFromCard', 'openProfileFromBtn', 'openProfileFromAction', 'openProfileFromGetStarted'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('click', function(e) {
                    if (el.tagName === 'A') {
                        e.preventDefault();
                    }
                    openProfileModal();
                });
            }
        });

        function cancelProfileEditor() {
            destroyCropper();
            pendingCrop = false;
            croppedField.value = '';
            photoInput.value = '';
            closeProfileModal();
        }

        document.getElementById('closeProfileModal').addEventListener('click', cancelProfileEditor);
        document.getElementById('cancelProfileModal').addEventListener('click', cancelProfileEditor);
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                cancelProfileEditor();
            }
        });

        function cleanupCropStage() {
            cropStage.querySelectorAll('.cropper-container').forEach(function(container) {
                container.remove();
            });
            cropImage.className = '';
            cropImage.removeAttribute('style');
        }

        function destroyCropper() {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            cleanupCropStage();
            cropImage.onload = null;
            cropImage.src = '';
            cropStage.classList.remove('active');
            photoToolbar.style.display = 'none';
            photoPreviewWrap.style.display = 'flex';
            hideCropTools();
        }

        function setZoomUi(value) {
            zoomLevel = Math.max(-30, Math.min(70, Number(value) || 0));
            if (photoZoomRange) {
                photoZoomRange.value = String(zoomLevel);
            }
            if (photoZoomValue) {
                photoZoomValue.textContent = (zoomLevel > 0 ? '+' : '') + zoomLevel + '%';
            }
        }

        function changeZoom(nextValue) {
            if (!cropper) {
                return;
            }
            const nextZoom = Math.max(-30, Math.min(70, Number(nextValue) || 0));
            const zoomChange = nextZoom - zoomLevel;
            if (zoomChange !== 0) {
                cropper.zoom(zoomChange / 100);
            }
            setZoomUi(nextZoom);
        }

        function showCropTools(file) {
            if (photoFileName) {
                photoFileName.textContent = file ? 'Selected: ' + file.name : '';
                photoFileName.classList.toggle('active', !!file);
            }
            if (photoCropTip) {
                photoCropTip.classList.add('active');
            }
            if (photoZoomControl) {
                photoZoomControl.classList.add('active');
            }
            setZoomUi(0);
        }

        function hideCropTools() {
            if (photoFileName) {
                photoFileName.textContent = '';
                photoFileName.classList.remove('active');
            }
            if (photoCropTip) {
                photoCropTip.classList.remove('active');
            }
            if (photoZoomControl) {
                photoZoomControl.classList.remove('active');
            }
            setZoomUi(0);
        }

        function startCropper(file) {
            if (!file || !file.type.match(/^image\/(jpeg|png|webp)$/)) {
                alert('Please choose a JPG, PNG, or WebP image.');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                alert('Image must be 5 MB or smaller.');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(event) {
                destroyCropper();
                pendingCrop = true;
                if (removePhotoCheckbox) {
                    removePhotoCheckbox.checked = false;
                }
                photoPreviewWrap.style.display = 'none';
                cropStage.classList.add('active');
                photoToolbar.style.display = 'flex';
                showCropTools(file);
                cropImage.onload = function() {
                    cleanupCropStage();
                    cropper = new Cropper(cropImage, {
                        aspectRatio: 1,
                        viewMode: 2,
                        dragMode: 'move',
                        autoCropArea: 0.9,
                        responsive: true,
                        background: false,
                        movable: true,
                        zoomable: true,
                        zoomOnWheel: true,
                        wheelZoomRatio: 0.04,
                        minContainerWidth: 250,
                        minContainerHeight: 250,
                        ready: function() {
                            setZoomUi(0);
                        }
                    });
                    cropImage.onload = null;
                };
                cropImage.src = event.target.result;
            };
            reader.readAsDataURL(file);
        }

        photoInput.addEventListener('change', function() {
            if (photoInput.files && photoInput.files[0]) {
                startCropper(photoInput.files[0]);
            }
        });

        document.getElementById('zoomInBtn').addEventListener('click', function() {
            changeZoom(zoomLevel + 10);
        });
        document.getElementById('zoomOutBtn').addEventListener('click', function() {
            changeZoom(zoomLevel - 10);
        });
        document.getElementById('resetCropBtn').addEventListener('click', function() {
            if (cropper) {
                cropper.reset();
                setZoomUi(0);
            }
        });
        if (photoZoomRange) {
            photoZoomRange.addEventListener('input', function() {
                changeZoom(photoZoomRange.value);
            });
        }
        if (removePhotoCheckbox) {
            removePhotoCheckbox.addEventListener('change', function() {
                if (removePhotoCheckbox.checked) {
                    destroyCropper();
                    pendingCrop = false;
                    croppedField.value = '';
                    photoInput.value = '';
                }
            });
        }

        form.addEventListener('submit', function() {
            if (cropper) {
                const canvas = cropper.getCroppedCanvas({
                    width: 400,
                    height: 400,
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });
                if (canvas) {
                    croppedField.value = canvas.toDataURL('image/jpeg', 0.9);
                }
            } else if (!pendingCrop) {
                croppedField.value = '';
            }
        });

        document.getElementById('profileSuccessOk').addEventListener('click', function() {
            hideProfileFeedback('profileSuccessPopup');
        });
        document.getElementById('profileErrorOk').addEventListener('click', function() {
            hideProfileFeedback('profileErrorPopup');
        });
        document.getElementById('profileSuccessPopup').addEventListener('click', function(e) {
            if (e.target === this) hideProfileFeedback('profileSuccessPopup');
        });
        document.getElementById('profileErrorPopup').addEventListener('click', function(e) {
            if (e.target === this) hideProfileFeedback('profileErrorPopup');
        });

        <?php if ($showProfileSuccessPopup): ?>
        showProfileFeedback('profileSuccessPopup');
        <?php endif; ?>
        <?php if ($showProfileErrorPopup): ?>
        showProfileFeedback('profileErrorPopup');
        openProfileModal();
        <?php elseif ($openProfileModal): ?>
        openProfileModal();
        <?php endif; ?>
        <?php if ($showProfileVerificationPopup): ?>
        document.body.style.overflow = 'hidden';
        const verificationCodeInput = document.querySelector('.profile-verification-code');
        if (verificationCodeInput) {
            verificationCodeInput.focus();
        }
        <?php endif; ?>
    })();
    </script>
<?php include 'includes/footer.php'; ?>

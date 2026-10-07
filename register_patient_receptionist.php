<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/name_parts.php';
require_once __DIR__ . '/includes/admin_notifications.php';

$rprCaviteLocationSource = require __DIR__ . '/includes/cavite_locations.php';
$rprCaviteLocations = [];
if (is_array($rprCaviteLocationSource)) {
    foreach ($rprCaviteLocationSource as $locationCity => $locationBarangays) {
        $cityName = trim((string) $locationCity);
        if ($cityName === '' || !is_array($locationBarangays)) {
            continue;
        }
        $rprCaviteLocations[$cityName] = array_values(array_unique(array_filter(array_map(
            static fn ($barangay): string => trim((string) $barangay),
            $locationBarangays
        ), static fn (string $barangay): bool => $barangay !== '')));
    }
}
$rprCaviteCities = array_keys($rprCaviteLocations);
usort($rprCaviteCities, static fn (string $first, string $second): int => strnatcasecmp($first, $second));

$currentUser = getCurrentUser();
$error = '';
$success = '';
$rprFirstNameMax = 15;
$rprMiddleNameMax = 1;
$rprLastNameMax = 15;
$rprSuffixMax = 3;
$latestAllowedDob = (new DateTimeImmutable('today'))->modify('-18 years')->format('Y-m-d');

function rpr_value(string $key): string {
    return isset($_POST[$key]) ? htmlspecialchars((string) $_POST[$key]) : '';
}

function rpr_selected(string $key, string $value): string {
    return isset($_POST[$key]) && (string) $_POST[$key] === $value ? 'selected' : '';
}

function rpr_password_requirements(string $password): array {
    return [
        'length' => strlen($password) >= 8,
        'uppercase' => (bool) preg_match('/[A-Z]/', $password),
        'lowercase' => (bool) preg_match('/[a-z]/', $password),
        'number' => (bool) preg_match('/[0-9]/', $password),
        'special' => (bool) preg_match('/[^A-Za-z0-9]/', $password),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $suffix = trim($_POST['suffix'] ?? '');
    $gender = ucfirst(strtolower(trim((string) ($_POST['gender'] ?? ''))));
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $province = trim((string) ($_POST['province'] ?? ''));
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
    
    $birthDate = null;
    $birthDateIsValid = false;
    if ($date_of_birth !== '') {
        $birthDate = DateTime::createFromFormat('!Y-m-d', $date_of_birth);
        $birthDateErrors = DateTime::getLastErrors();
        $birthDateIsValid = $birthDate instanceof DateTime
            && ($birthDateErrors === false || ($birthDateErrors['warning_count'] === 0 && $birthDateErrors['error_count'] === 0))
            && $birthDate->format('Y-m-d') === $date_of_birth;
    }

    $age = null;
    if ($birthDateIsValid && $date_of_birth <= $latestAllowedDob) {
        $age = (new DateTimeImmutable('today'))->diff($birthDate)->y;
    }
    
    // Validation
    $display_full_name = clinic_name_build_full_name([
        'first_name' => $first_name,
        'middle_name' => $middle_name,
        'last_name' => $last_name,
        'suffix' => $suffix,
    ]);

    $firstNameLength = clinic_name_letter_count($first_name);
    $middleNameLength = clinic_name_letter_count($middle_name);
    $lastNameLength = clinic_name_letter_count($last_name);
    $suffixLength = clinic_name_letter_count($suffix);

    if (empty($first_name) || empty($last_name) || empty($gender) || empty($date_of_birth) || empty($province) || empty($barangay) || empty($city) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all required fields.';
    } elseif ($province !== 'Cavite') {
        $error = 'Only Cavite is available as the province.';
    } elseif (!array_key_exists($city, $rprCaviteLocations)) {
        $error = 'Please choose a valid Cavite city or municipality.';
    } elseif (!in_array($barangay, $rprCaviteLocations[$city], true)) {
        $error = 'Please choose a valid barangay for the selected city or municipality.';
    } elseif (!in_array($gender, ['Male', 'Female'], true)) {
        $error = 'Please choose a valid sex.';
    } elseif (!in_array($civil_status, ['', 'Single', 'Married', 'Widowed'], true)) {
        $error = 'Please choose a valid civil status.';
    } elseif ($firstNameLength > $rprFirstNameMax) {
        $error = 'First name must not exceed ' . $rprFirstNameMax . ' letters.';
    } elseif ($middle_name !== '' && $middleNameLength !== $rprMiddleNameMax) {
        $error = 'Middle name must be exactly 1 letter.';
    } elseif ($lastNameLength > $rprLastNameMax) {
        $error = 'Last name must not exceed ' . $rprLastNameMax . ' letters.';
    } elseif ($suffix !== '' && $suffixLength > $rprSuffixMax) {
        $error = 'Suffix must not exceed ' . $rprSuffixMax . ' letters.';
    } elseif (!$birthDateIsValid || $date_of_birth > $latestAllowedDob) {
        $error = 'You must be at least 18 years old to register.';
    } elseif ($password !== $confirm_password) {
        $error = 'Password and confirm password do not match.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!preg_match('/^.{8,}$/', $password)) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        $reqs = rpr_password_requirements($password);
        if (!$reqs['uppercase'] || !$reqs['lowercase'] || !$reqs['number'] || !$reqs['special']) {
            $error = 'Password must include uppercase, lowercase, number, and special character.';
        } else {
        $conn = getDBConnection();
        
        // Check if username already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $checkStmt->bind_param("s", $username);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Username already exists. Please choose another one.';
            $checkStmt->close();
        } else {
            $checkStmt->close();
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'patient';
            
            // Insert new patient
            $stmt = $conn->prepare("INSERT INTO users (username, password, first_name, middle_name, last_name, suffix, role, email, phone, gender, date_of_birth, age, civil_status, address, barangay, city, emergency_contact_name, emergency_contact_relationship, emergency_contact_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $fullAddress = implode(', ', array_values(array_filter([$address, $barangay, $city, 'Cavite'], static fn (string $part): bool => $part !== '')));
            $stmt->bind_param("sssssssssssisssssss", $username, $hashed_password, $first_name, $middle_name, $last_name, $suffix, $role, $email, $phone, $gender, $date_of_birth, $age, $civil_status, $fullAddress, $barangay, $city, $emergency_contact_name, $emergency_contact_relationship, $emergency_contact_number);
            
            if ($stmt->execute()) {
                $newPatientId = (int) $stmt->insert_id;
                create_admin_notification(
                    $conn,
                    'patient_account_created',
                    'New patient account',
                    $display_full_name . ' was registered at the clinic desk.',
                    $newPatientId
                );
                $success = 'Patient registered successfully!';
                // Clear form data on success
                $_POST = array();
            } else {
                $error = 'Registration failed. Please try again.';
            }
            
            $stmt->close();
        }
        
        $conn->close();
        }
    }
}

$rprSelectedProvince = isset($_POST['province']) ? trim((string) $_POST['province']) : 'Cavite';
$rprSelectedCity = isset($_POST['city']) ? trim((string) $_POST['city']) : '';
$rprSelectedBarangay = isset($_POST['barangay']) ? trim((string) $_POST['barangay']) : '';
$rprSelectedBarangays = $rprCaviteLocations[$rprSelectedCity] ?? [];

$pageTitle = "Register New Patient | Globalife Medical Laboratory & Polyclinic";
$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? 'globalife.online');
$requestDirectory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
$requestDirectory = $requestDirectory === '/' ? '' : rtrim($requestDirectory, '/');
$walkInRegistrationUrl = $requestScheme . '://' . $requestHost . $requestDirectory . '/register_patient.php?source=walkin_qr';
$walkInQrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=12&data=' . rawurlencode($walkInRegistrationUrl);
$walkInQrFallbackImageUrl = 'storage/qr/walkin_registration_local.png';
$walkInQrFallbackReady = is_file(__DIR__ . '/' . $walkInQrFallbackImageUrl);
$additionalStyles = '
    body {
        background:
            radial-gradient(circle at top left, rgba(15, 124, 194, 0.08), transparent 32%),
            radial-gradient(circle at top right, rgba(7, 59, 76, 0.07), transparent 28%),
            linear-gradient(135deg, #f5f9fc 0%, #eef6fb 100%);
        min-height: 100vh;
    }
    .container {
        width: min(96%, 1700px);
        max-width: 1700px;
        margin: 0 auto;
        padding: 0;
        box-sizing: border-box;
    }
    .registration-container {
        width: 100%;
        max-width: none;
        margin: 18px auto 34px;
        padding: 0;
    }
    .intake-shell {
        display: grid;
        grid-template-columns: minmax(0, 2fr) minmax(300px, 1fr);
        gap: 18px;
        align-items: start;
    }
    .intake-panel,
    .summary-panel {
        background: rgba(255, 255, 255, 0.94);
        border: 1px solid #d7e5ef;
        border-radius: 22px;
        box-shadow: 0 14px 36px rgba(19, 78, 112, 0.08);
        backdrop-filter: blur(12px);
    }
    .intake-panel {
        padding: 22px;
    }
    .summary-panel {
        padding: 18px;
        position: sticky;
        top: 78px;
        align-self: start;
        z-index: 5;
    }
    .registration-header {
        margin-bottom: 16px;
        padding: 20px 22px;
        border-radius: 20px;
        background:
            linear-gradient(135deg, rgba(9, 112, 171, 0.12), rgba(10, 69, 96, 0.05)),
            #f7fbfe;
        border: 1px solid #d6e7f2;
    }
    .header-kicker {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 6px 11px;
        border-radius: 999px;
        background: rgba(15, 124, 194, 0.1);
        color: #0b5d93;
        font-size: 0.74rem;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 10px;
    }
    .registration-header h2 {
        color: #073b4c;
        font-size: 2rem;
        margin: 0 0 8px;
        font-weight: 900;
        line-height: 1.08;
    }
    .registration-header p {
        color: #55707f;
        font-size: 1rem;
        margin: 0;
        line-height: 1.55;
        max-width: 720px;
    }
    .section-note {
        margin-top: 6px;
        color: #60727d;
        font-size: 0.84rem;
        line-height: 1.45;
    }
    .section-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e7edf3;
    }
    .section-badge {
        display: inline-flex;
        align-items: center;
        min-height: 32px;
        border-radius: 999px;
        padding: 5px 12px;
        background: #eff7ff;
        color: #0b4f80;
        font-size: 0.76rem;
        font-weight: 900;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .form-section {
        margin-bottom: 12px;
        padding: 16px;
        border: 1px solid #deebf3;
        border-radius: 18px;
        background: linear-gradient(180deg, #fcfeff 0%, #f8fbfe 100%);
    }
    .form-section-title {
        font-size: 1rem;
        font-weight: 900;
        color: #073b4c;
        margin: 0 0 6px;
    }
    .form-section p {
        margin: 0 0 12px;
        color: #60727d;
        line-height: 1.45;
        font-size: 0.85rem;
    }
    .form-group {
        margin-bottom: 10px;
    }
    .form-group label {
        display: block;
        margin-bottom: 6px;
        color: #364d58;
        font-weight: 800;
        font-size: 0.88rem;
    }
    .form-group label .required {
        color: #c1121f;
    }
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        min-height: 50px;
        padding: 12px 14px;
        border: 1px solid #c9ddea;
        border-radius: 14px;
        font-size: 0.94rem;
        box-sizing: border-box;
        transition: all 0.2s ease;
        font-family: inherit;
        background: #fff;
        color: #1f343d;
    }
    .form-group select {
        color-scheme: light;
        transition: border-color 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
    }
    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: #8aa0ad;
    }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #0f7cc2;
        box-shadow: 0 0 0 3px rgba(15, 124, 194, 0.1);
    }
    .form-group textarea {
        resize: vertical;
        min-height: 84px;
    }
    .rpr-dob-picker {
        position: relative;
    }
    .rpr-dob-picker-button {
        width: 100%;
        min-height: 50px;
        padding: 12px 44px 12px 14px;
        border: 1px solid #c9ddea;
        border-radius: 14px;
        background: #fff;
        color: #1f343d;
        font: inherit;
        font-size: 0.94rem;
        text-align: left;
        cursor: pointer;
    }
    .rpr-dob-picker-button.placeholder {
        color: #8aa0ad;
    }
    .rpr-dob-picker-button:focus {
        outline: none;
        border-color: #0f7cc2;
        box-shadow: 0 0 0 3px rgba(15, 124, 194, 0.1);
    }
    .rpr-dob-picker-icon {
        position: absolute;
        right: 14px;
        top: 15px;
        width: 20px;
        height: 20px;
        color: #0f7cc2;
        pointer-events: none;
    }
    .rpr-dob-calendar {
        display: none;
        position: fixed !important;
        top: 50%;
        left: 50%;
        z-index: 2600;
        width: min(460px, calc(100vw - 32px));
        max-height: calc(100vh - 32px);
        overflow-y: auto;
        box-sizing: border-box;
        padding: 20px;
        border: 1px solid #cfe2ed;
        border-radius: 14px;
        background: #fff;
        transform: translate(-50%, -50%);
        box-shadow: 0 0 0 100vmax rgba(7, 59, 76, 0.45), 0 18px 38px rgba(7, 59, 76, 0.24);
    }
    .rpr-dob-calendar.open {
        display: block;
    }
    .rpr-dob-calendar-controls {
        display: grid;
        grid-template-columns: 44px minmax(0, 1fr) 92px 44px;
        gap: 8px;
        align-items: center;
        margin-bottom: 14px;
    }
    .rpr-dob-calendar-controls button {
        width: 44px;
        height: 44px;
        border: 1px solid #d4e6f1;
        border-radius: 8px;
        background: #f5faff;
        color: #0b4f80;
        font-size: 1.3rem;
        cursor: pointer;
    }
    .rpr-dob-calendar-controls button:disabled {
        opacity: 0.35;
        cursor: not-allowed;
    }
    .rpr-dob-calendar-controls select,
    .rpr-dob-calendar-controls input {
        width: 100%;
        min-height: 44px;
        box-sizing: border-box;
        border: 1px solid #d4e6f1;
        border-radius: 8px;
        background: #fff;
        color: #073b4c;
        padding: 7px 9px;
        font: inherit;
        font-weight: 800;
    }
    .rpr-dob-weekdays,
    .rpr-dob-days {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 6px;
    }
    .rpr-dob-weekdays {
        margin-bottom: 6px;
        color: #60727d;
        font-size: 0.76rem;
        font-weight: 900;
        text-align: center;
        text-transform: uppercase;
    }
    .rpr-dob-weekdays span {
        padding: 6px 0;
    }
    .rpr-dob-day,
    .rpr-dob-day-empty {
        aspect-ratio: 1;
        min-width: 0;
    }
    .rpr-dob-day {
        border: 0;
        border-radius: 9px;
        background: #f6f9fb;
        color: #1f343d;
        font: inherit;
        font-size: 0.95rem;
        font-weight: 800;
        cursor: pointer;
    }
    .rpr-dob-day:hover,
    .rpr-dob-day:focus {
        outline: none;
        background: #dff3fb;
        color: #005f91;
    }
    .rpr-dob-day.selected {
        background: #0077b6;
        color: #fff;
    }
    .rpr-dob-day:disabled {
        background: #f4f4f4;
        color: #b4bec4;
        cursor: not-allowed;
    }
    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    .input-wrapper .password-input {
        padding-right: 48px;
    }
    .password-toggle {
        position: absolute;
        right: 14px;
        width: 20px;
        height: 20px;
        color: #0f7cc2;
        cursor: pointer;
        transition: color 0.2s ease;
    }
    .password-toggle:hover {
        color: #073b4c;
    }
    .password-requirements {
        margin-top: 7px;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 5px 8px;
        padding: 10px 12px;
        background: #f7fbfe;
        border: 1px solid #dbe8f3;
        border-radius: 14px;
    }
    .password-requirements-title {
        grid-column: 1 / -1;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        color: #5c7286;
        margin-bottom: 0;
    }
    .password-requirement {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 11px;
        color: #617284;
    }
    .password-requirement.valid {
        color: #157347;
    }
    .requirement-icon {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
    }
    .requirement-icon.valid {
        color: #157347;
    }
    .requirement-icon.invalid {
        color: #d63a49;
    }
    .password-strength {
        margin-top: 7px;
        height: 5px;
        background: #e7eef5;
        border-radius: 999px;
        overflow: hidden;
    }
    .password-strength-bar {
        height: 100%;
        width: 0;
        border-radius: 999px;
        transition: width 0.25s ease, background-color 0.25s ease;
    }
    .password-strength-bar.weak {
        width: 33%;
        background: #dc3545;
    }
    .password-strength-bar.medium {
        width: 66%;
        background: #f0ad4e;
    }
    .password-strength-bar.strong {
        width: 100%;
        background: #28a745;
    }
    .field-hint {
        display: block;
        margin-top: 6px;
        font-size: 10px;
        color: #6a7d8d;
    }
    .match-input-valid {
        border-color: #28a745 !important;
        box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.08);
    }
    .match-input-invalid {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.08);
    }
    .form-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .address-location-row {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .name-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) minmax(0, 1.7fr) minmax(0, 0.7fr);
        gap: 8px;
    }
    .name-grid .form-group label {
        min-height: 2.6em;
        line-height: 1.3;
    }
    .login-stack {
        display: grid;
        gap: 12px;
    }
    .error-message,
    .success-message {
        padding: 10px 12px;
        border-radius: 12px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 700;
    }
    .error-message {
        background: #fff0f0;
        color: #9d1c2c;
        border: 1px solid #ffd0d5;
    }
    .success-message {
        background: #e7f7ed;
        color: #17643a;
        border: 1px solid #bfe6ce;
    }
    .registration-success-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2600;
        place-items: center;
        padding: 18px;
        background: rgba(7, 59, 76, 0.58);
    }
    .registration-success-overlay.active {
        display: grid;
    }
    .registration-success-modal {
        width: min(420px, 100%);
        box-sizing: border-box;
        border-radius: 16px;
        background: #fff;
        padding: 26px 24px;
        box-shadow: 0 24px 60px rgba(7, 59, 76, 0.28);
        text-align: center;
    }
    .registration-success-icon {
        display: inline-grid;
        place-items: center;
        width: 64px;
        height: 64px;
        border-radius: 50%;
        margin-bottom: 14px;
        background: #e7f7ed;
        color: #17643a;
    }
    .registration-success-icon svg {
        width: 32px;
        height: 32px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.7;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .registration-success-modal h3 {
        margin: 0 0 8px;
        color: #073b4c;
        font-size: 1.3rem;
        line-height: 1.2;
    }
    .registration-success-modal p {
        margin: 0 0 18px;
        color: #60727d;
        font-size: 0.94rem;
        line-height: 1.5;
    }
    .registration-success-ok {
        min-width: 130px;
        min-height: 44px;
        border: 0;
        border-radius: 10px;
        background: #0f7cc2;
        color: #fff;
        font: inherit;
        font-size: 0.92rem;
        font-weight: 900;
        cursor: pointer;
        box-shadow: 0 10px 18px rgba(15, 124, 194, 0.2);
    }
    .registration-success-ok:hover {
        background: #0b5f96;
    }
    .submit-btn {
        width: 100%;
        background: linear-gradient(135deg, #0f7cc2 0%, #073b4c 100%);
        color: #fff;
        padding: 11px 14px;
        border: none;
        border-radius: 12px;
        font-size: 0.95rem;
        font-weight: 900;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        box-shadow: 0 10px 18px rgba(15, 124, 194, 0.2);
    }
    .submit-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 24px rgba(15, 124, 194, 0.24);
    }
    .qr-action-card {
        border: 1px solid #cfe4f1;
        border-radius: 16px;
        padding: 16px;
        background: #f5fbff;
        margin-bottom: 12px;
    }
    .qr-action-card h3 {
        margin: 0 0 6px;
        color: #073b4c;
        font-size: 1rem;
    }
    .qr-action-card p {
        margin: 0 0 12px;
        color: #60727d;
        font-size: 0.84rem;
        line-height: 1.5;
    }
    .qr-open-btn {
        width: 100%;
        min-height: 44px;
        border: 0;
        border-radius: 10px;
        background: #0f7cc2;
        color: #fff;
        padding: 10px 14px;
        font: inherit;
        font-size: 0.9rem;
        font-weight: 900;
        cursor: pointer;
        transition: background 0.2s ease, transform 0.2s ease;
    }
    .qr-open-btn:hover {
        background: #0b5f96;
        transform: translateY(-1px);
    }
    .qr-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2500;
        place-items: center;
        padding: 18px;
        background: rgba(7, 59, 76, 0.58);
    }
    .qr-modal-overlay.active {
        display: grid;
    }
    .qr-modal {
        width: min(430px, 100%);
        box-sizing: border-box;
        border-radius: 16px;
        background: #fff;
        padding: 20px;
        box-shadow: 0 24px 60px rgba(7, 59, 76, 0.28);
        text-align: center;
    }
    .qr-modal-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        text-align: left;
        margin-bottom: 14px;
    }
    .qr-modal-head h3 {
        margin: 0 0 4px;
        color: #073b4c;
        font-size: 1.2rem;
    }
    .qr-modal-head p {
        margin: 0;
        color: #60727d;
        font-size: 0.84rem;
        line-height: 1.45;
    }
    .qr-modal-close {
        width: 36px;
        height: 36px;
        flex: 0 0 36px;
        border: 0;
        border-radius: 8px;
        background: #edf6fc;
        color: #0b4f80;
        font-size: 1.35rem;
        cursor: pointer;
    }
    .qr-code-frame {
        display: inline-grid;
        place-items: center;
        border: 1px solid #d5e6f0;
        border-radius: 12px;
        background: #fff;
        padding: 10px;
    }
    .qr-code-frame img {
        display: block;
        width: min(250px, 64vw);
        height: auto;
        aspect-ratio: 1;
    }
    .qr-modal-note {
        margin: 12px 0;
        color: #526b78;
        font-size: 0.86rem;
        line-height: 1.5;
    }
    .qr-page-link {
        display: block;
        width: 100%;
        border: 0;
        border-radius: 10px;
        background: #073b4c;
        color: #fff;
        padding: 11px 14px;
        font: inherit;
        font-weight: 900;
        cursor: pointer;
    }
    .qr-print-brand {
        display: none;
    }
    @media print {
        @page {
            size: A4 portrait;
            margin: 14mm;
        }
        body * {
            visibility: hidden !important;
        }
        #walkInQrModal,
        #walkInQrModal * {
            visibility: visible !important;
        }
        #walkInQrModal {
            display: block !important;
            position: absolute;
            inset: 0;
            padding: 0;
            background: #fff;
        }
        #walkInQrModal .qr-modal {
            width: 100%;
            max-width: none;
            min-height: 250mm;
            border: 2px solid #0f7cc2;
            border-radius: 12px;
            box-shadow: none;
            padding: 20mm 16mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .qr-print-brand {
            display: grid;
            justify-items: center;
            gap: 8px;
            margin-bottom: 16px;
        }
        .qr-print-brand img {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #48cae4;
        }
        .qr-print-brand strong {
            color: #073b4c;
            font-size: 18pt;
            text-align: center;
        }
        .qr-modal-head {
            display: block;
            text-align: center;
            margin-bottom: 16px;
        }
        .qr-modal-head h3 {
            font-size: 28pt;
            text-transform: uppercase;
        }
        .qr-modal-head p {
            font-size: 13pt;
        }
        .qr-modal-close,
        .qr-page-link {
            display: none !important;
        }
        .qr-code-frame {
            border: 0;
            padding: 8px;
        }
        .qr-code-frame img {
            width: 105mm;
            height: 105mm;
            max-width: none;
        }
        .qr-modal-note {
            max-width: 150mm;
            color: #073b4c;
            font-size: 13pt;
            font-weight: 700;
        }
    }
    .summary-steps {
        margin-top: 12px;
        padding: 14px;
        border-radius: 16px;
        background: #0b4463;
        color: #f1f8fc;
    }
    .summary-steps h4 {
        margin: 0 0 10px;
        font-size: 0.9rem;
        font-weight: 900;
    }
    .summary-step {
        display: grid;
        grid-template-columns: 26px 1fr;
        gap: 10px;
        align-items: start;
    }
    .summary-step + .summary-step {
        margin-top: 10px;
    }
    .summary-step-number {
        width: 26px;
        height: 26px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.78rem;
        font-weight: 900;
    }
    .summary-step p {
        margin: 2px 0 0;
        font-size: 0.8rem;
        line-height: 1.45;
        color: rgba(241, 248, 252, 0.92);
    }
    .form-footer {
        margin-top: 10px;
        display: grid;
        gap: 6px;
    }
    .form-footer small {
        color: #60727d;
        line-height: 1.35;
        font-size: 0.78rem;
    }
    @media (max-width: 980px) {
        .intake-shell {
            grid-template-columns: 1fr;
        }
        .summary-panel {
            position: static;
        }
        .name-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 760px) {
        .container {
            width: calc(100% - 32px);
        }
        .registration-container {
            padding-bottom: 22px;
            margin-top: 12px;
        }
        .intake-panel,
        .summary-panel {
            padding: 12px 10px;
        }
        .registration-header {
            padding: 16px 14px;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
        .name-grid {
            grid-template-columns: 1fr;
        }
        .name-grid .form-group label {
            min-height: 0;
        }
        .rpr-dob-calendar {
            width: calc(100vw - 32px);
            padding: 16px;
        }
        .rpr-dob-calendar-controls {
            grid-template-columns: 38px minmax(0, 1fr) 78px 38px;
            gap: 5px;
        }
        .rpr-dob-calendar-controls button {
            width: 38px;
            height: 38px;
        }
        .rpr-dob-calendar-controls select,
        .rpr-dob-calendar-controls input {
            min-height: 38px;
            padding: 6px 8px;
            font-size: 0.88rem;
        }
        .registration-header h2 {
            font-size: 1.35rem;
        }
        .password-requirements {
            grid-template-columns: 1fr;
        }
    }
';

include 'includes/header.php';
?>

<div class="container">
    <div class="registration-container">
        <div class="intake-shell">
            <div class="intake-panel">
                <div class="registration-header">
                    <div class="header-kicker">Walk-in intake</div>
                    <h2>Walk-in patient intake</h2>
                    <p>Encode personal information for walk-in patients registered at the clinic.</p>
                </div>

                <?php if ($error): ?>
                    <div class="error-message">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                        </svg>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register_patient_receptionist.php" autocomplete="off">
                    <div class="form-section">
                        <div class="section-head">
                            <div>
                                <div class="form-section-title">Login Information</div>
                                <div class="section-note">Create the username the patient will use to sign in.</div>
                            </div>
                            <span class="section-badge">Required</span>
                        </div>

                        <div class="form-group">
                            <label for="username">Username <span class="required">*</span></label>
                            <input type="text" id="username" name="username" required placeholder="patient_username" value="<?php echo rpr_value('username'); ?>">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-head">
                            <div>
                                <div class="form-section-title">Personal information</div>
                                <div class="section-note">Basic identity details needed to create the patient account.</div>
                            </div>
                            <span class="section-badge">Required</span>
                        </div>

                        <div class="name-grid">
                            <div class="form-group">
                                <label for="first_name">First name <span class="required">*</span></label>
                                <input type="text" id="first_name" name="first_name" required maxlength="<?php echo $rprFirstNameMax; ?>" placeholder="Juan" value="<?php echo rpr_value('first_name'); ?>">
                                <span class="field-hint">Maximum of <?php echo $rprFirstNameMax; ?> letters.</span>
                            </div>
                            <div class="form-group">
                                <label for="middle_name">Middle name <span class="optional">(optional)</span></label>
                                <input type="text" id="middle_name" name="middle_name" maxlength="<?php echo $rprMiddleNameMax; ?>" placeholder="A" value="<?php echo rpr_value('middle_name'); ?>">
                                <span class="field-hint">Exactly 1 letter, auto-uppercase.</span>
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last name <span class="required">*</span></label>
                                <input type="text" id="last_name" name="last_name" required maxlength="<?php echo $rprLastNameMax; ?>" placeholder="Dela Cruz" value="<?php echo rpr_value('last_name'); ?>">
                                <span class="field-hint">Maximum of <?php echo $rprLastNameMax; ?> letters.</span>
                            </div>
                            <div class="form-group">
                                <label for="suffix">Suffix <span class="optional">(optional)</span></label>
                                <input type="text" id="suffix" name="suffix" maxlength="<?php echo $rprSuffixMax; ?>" placeholder="Jr" value="<?php echo rpr_value('suffix'); ?>">
                                <span class="field-hint">Optional, up to <?php echo $rprSuffixMax; ?> letters.</span>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gender">Sex <span class="required">*</span></label>
                                <select id="gender" name="gender" required>
                                    <option value="" hidden>Select Sex</option>
                                    <option value="Male" <?php echo rpr_selected('gender', 'Male'); ?>>Male</option>
                                    <option value="Female" <?php echo rpr_selected('gender', 'Female'); ?>>Female</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="date_of_birth">Date of birth <span class="required">*</span></label>
                                <div class="rpr-dob-picker" id="rprDobPicker">
                                    <input type="hidden" id="date_of_birth" name="date_of_birth" data-latest-allowed-dob="<?php echo htmlspecialchars($latestAllowedDob, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo rpr_value('date_of_birth'); ?>">
                                    <button type="button" class="rpr-dob-picker-button placeholder" id="rprDobPickerButton" aria-haspopup="dialog" aria-expanded="false">Select date of birth</button>
                                    <svg class="rpr-dob-picker-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    <div class="rpr-dob-calendar" id="rprDobCalendar" role="dialog" aria-label="Choose date of birth">
                                        <div class="rpr-dob-calendar-controls">
                                            <button type="button" id="rprDobPreviousMonth" aria-label="Previous month">&lsaquo;</button>
                                            <select id="rprDobMonth" aria-label="Birth month">
                                                <option value="0">January</option><option value="1">February</option><option value="2">March</option>
                                                <option value="3">April</option><option value="4">May</option><option value="5">June</option>
                                                <option value="6">July</option><option value="7">August</option><option value="8">September</option>
                                                <option value="9">October</option><option value="10">November</option><option value="11">December</option>
                                            </select>
                                            <input type="number" id="rprDobYear" min="1900" max="<?php echo (int) substr($latestAllowedDob, 0, 4); ?>" inputmode="numeric" aria-label="Birth year">
                                            <button type="button" id="rprDobNextMonth" aria-label="Next month">&rsaquo;</button>
                                        </div>
                                        <div class="rpr-dob-weekdays" aria-hidden="true">
                                            <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                                        </div>
                                        <div class="rpr-dob-days" id="rprDobDays"></div>
                                    </div>
                                </div>
                                <span class="field-hint">Must be at least 18 years old.</span>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="civil_status">Civil status <span class="optional">(optional)</span></label>
                                <select id="civil_status" name="civil_status">
                                    <option value="" hidden>Select Civil Status</option>
                                    <option value="Single" <?php echo rpr_selected('civil_status', 'Single'); ?>>Single</option>
                                    <option value="Married" <?php echo rpr_selected('civil_status', 'Married'); ?>>Married</option>
                                    <option value="Widowed" <?php echo rpr_selected('civil_status', 'Widowed'); ?>>Widowed</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone number <span class="optional">(optional)</span></label>
                                <input type="tel" id="phone" name="phone" placeholder="09xxxxxxxxx" value="<?php echo rpr_value('phone'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email address <span class="optional">(optional)</span></label>
                            <input type="email" id="email" name="email" placeholder="name@example.com" value="<?php echo rpr_value('email'); ?>">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-head">
                            <div>
                                <div class="form-section-title">Address information</div>
                                <div class="section-note">Use the complete address so future follow-ups and records stay consistent.</div>
                            </div>
                            <span class="section-badge">Location</span>
                        </div>

                        <div class="form-group">
                            <label for="address">Street address <span class="optional">(optional)</span></label>
                            <input type="text" id="address" name="address" placeholder="House No., street, subdivision" value="<?php echo rpr_value('address'); ?>">
                        </div>

                        <div class="form-row address-location-row">
                            <div class="form-group">
                                <label for="province">Province <span class="required">*</span></label>
                                <select id="province" name="province" required>
                                    <option value="Cavite" <?php echo $rprSelectedProvince === 'Cavite' ? 'selected' : ''; ?>>Cavite</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="city">City / Municipality <span class="required">*</span></label>
                                <select id="city" name="city" required>
                                    <option value="">Select city or municipality</option>
                                    <?php foreach ($rprCaviteCities as $cityOption): ?>
                                        <option value="<?php echo htmlspecialchars($cityOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $rprSelectedCity === $cityOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($cityOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="barangay">Barangay <span class="required">*</span></label>
                                <select id="barangay" name="barangay" required <?php echo $rprSelectedCity === '' ? 'disabled' : ''; ?>>
                                    <option value="">Select barangay</option>
                                    <?php foreach ($rprSelectedBarangays as $barangayOption): ?>
                                        <option value="<?php echo htmlspecialchars($barangayOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $rprSelectedBarangay === $barangayOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($barangayOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-head">
                            <div>
                                <div class="form-section-title">Account Security</div>
                                <div class="section-note">Create a secure password for the patient’s clinic account.</div>
                            </div>
                            <span class="section-badge">Security</span>
                        </div>

                        <div class="login-stack">
                            <div class="form-group">
                                <label for="password">Password <span class="required">*</span></label>
                                <div class="input-wrapper">
                                    <input class="password-input" type="password" id="password" name="password" required placeholder="Enter a temporary password" autocomplete="new-password">
                                    <svg class="password-toggle" id="passwordToggle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-label="Toggle password visibility" role="button" tabindex="0">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </div>
                                <div class="password-requirements">
                                    <div class="password-requirements-title">Password requirements</div>
                                    <div class="password-requirement" id="req-length">
                                        <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>At least 8 characters</span>
                                    </div>
                                    <div class="password-requirement" id="req-uppercase">
                                        <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>One uppercase letter (A-Z)</span>
                                    </div>
                                    <div class="password-requirement" id="req-lowercase">
                                        <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>One lowercase letter (a-z)</span>
                                    </div>
                                    <div class="password-requirement" id="req-number">
                                        <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>One number (0-9)</span>
                                    </div>
                                    <div class="password-requirement" id="req-special">
                                        <svg class="requirement-icon invalid" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                        <span>One special character (!@#$%^&*)</span>
                                    </div>
                                </div>
                                <div class="password-strength">
                                    <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                                <div class="input-wrapper">
                                    <input class="password-input" type="password" id="confirm_password" name="confirm_password" required placeholder="Re-enter the password" autocomplete="new-password">
                                    <svg class="password-toggle" id="confirmPasswordToggle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-label="Toggle confirm password visibility" role="button" tabindex="0">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </div>
                                <span class="field-hint">Both passwords must match before saving the patient record.</span>
                            </div>

                            <div class="form-footer">
                                <button type="submit" class="submit-btn">Register patient</button>
                                <small>Double-check the birthday before saving. Contact details can be completed during account verification.</small>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <aside class="summary-panel" aria-label="Walk-in registration options">
                <div class="qr-action-card">
                    <h3>Patient self-registration</h3>
                    <p>Let the walk-in patient register using their own phone instead of encoding the form at the desk.</p>
                    <button type="button" class="qr-open-btn" id="openWalkInQr">Show scan code</button>
                </div>
                <div class="summary-steps">
                    <h4>Registration flow</h4>
                    <div class="summary-step">
                        <span class="summary-step-number">1</span>
                        <p>Start with identity details so duplicate patient records are easier to spot.</p>
                    </div>
                    <div class="summary-step">
                        <span class="summary-step-number">2</span>
                        <p>Add the address details only when available. Keep the walk-in form quick and simple.</p>
                    </div>
                    <div class="summary-step">
                        <span class="summary-step-number">3</span>
                        <p>Finish with a temporary username and password so the patient can access future appointments later.</p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</div>

<?php if ($success): ?>
    <div class="registration-success-overlay active" id="registrationSuccessModal" role="dialog" aria-modal="true" aria-labelledby="registrationSuccessTitle" aria-hidden="false">
        <div class="registration-success-modal">
            <span class="registration-success-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></svg>
            </span>
            <h3 id="registrationSuccessTitle">Patient registered successfully</h3>
            <p><?php echo htmlspecialchars($success); ?></p>
            <button type="button" class="registration-success-ok" id="registrationSuccessOk">OK</button>
        </div>
    </div>
<?php endif; ?>

<div class="qr-modal-overlay" id="walkInQrModal" role="dialog" aria-modal="true" aria-labelledby="walkInQrTitle" aria-hidden="true">
    <div class="qr-modal">
        <div class="qr-print-brand">
            <img src="globalife.png" alt="Globalife Medical Laboratory and Polyclinic logo">
            <strong>Globalife Medical Laboratory &amp; Polyclinic</strong>
        </div>
        <div class="qr-modal-head">
            <div>
                <h3 id="walkInQrTitle">Scan to register</h3>
                <p>Use the patient&apos;s phone camera to open the official registration page.</p>
            </div>
            <button type="button" class="qr-modal-close" id="closeWalkInQr" aria-label="Close">&times;</button>
        </div>
        <div class="qr-code-frame">
            <img
                src="<?php echo htmlspecialchars($walkInQrImageUrl); ?>"
                <?php if ($walkInQrFallbackReady): ?>
                    onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($walkInQrFallbackImageUrl); ?>';"
                <?php endif; ?>
                alt="QR code for patient registration"
                width="250"
                height="250"
            >
        </div>
        <p class="qr-modal-note">After account verification, the administrator will receive a new patient account notification.</p>
        <button type="button" class="qr-page-link" id="printWalkInQr">Print / Save as PDF</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const registrationSuccessModal = document.getElementById('registrationSuccessModal');
    const registrationSuccessOk = document.getElementById('registrationSuccessOk');
    const walkInQrModal = document.getElementById('walkInQrModal');
    const openWalkInQr = document.getElementById('openWalkInQr');
    const closeWalkInQr = document.getElementById('closeWalkInQr');
    const printWalkInQr = document.getElementById('printWalkInQr');

    function openQrModal() {
        if (!walkInQrModal) return;
        walkInQrModal.classList.add('active');
        walkInQrModal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (closeWalkInQr) closeWalkInQr.focus();
    }

    function closeQrModal() {
        if (!walkInQrModal) return;
        walkInQrModal.classList.remove('active');
        walkInQrModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (openWalkInQr) openWalkInQr.focus();
    }

    if (openWalkInQr) openWalkInQr.addEventListener('click', openQrModal);
    if (closeWalkInQr) closeWalkInQr.addEventListener('click', closeQrModal);
    function closeRegistrationSuccess() {
        if (!registrationSuccessModal) return;
        registrationSuccessModal.classList.remove('active');
        registrationSuccessModal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    if (registrationSuccessModal) {
        document.body.style.overflow = 'hidden';
        if (registrationSuccessOk) registrationSuccessOk.focus();
        if (registrationSuccessOk) registrationSuccessOk.addEventListener('click', closeRegistrationSuccess);
        registrationSuccessModal.addEventListener('click', function (event) {
            if (event.target === registrationSuccessModal) {
                closeRegistrationSuccess();
            }
        });
    }
    if (printWalkInQr) {
        printWalkInQr.addEventListener('click', function () {
            window.print();
        });
    }
    if (walkInQrModal) {
        walkInQrModal.addEventListener('click', function (event) {
            if (event.target === walkInQrModal) closeQrModal();
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        if (registrationSuccessModal && registrationSuccessModal.classList.contains('active')) {
            closeRegistrationSuccess();
        }
        if (walkInQrModal && walkInQrModal.classList.contains('active')) {
            closeQrModal();
        }
    });

    const provinceInput = document.getElementById('province');
    const cityInput = document.getElementById('city');
    const barangayInput = document.getElementById('barangay');
    const caviteLocations = <?php echo json_encode($rprCaviteLocations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function populateRprBarangays(selectedBarangay) {
        if (!cityInput || !barangayInput) return;
        const barangays = Array.isArray(caviteLocations[cityInput.value]) ? caviteLocations[cityInput.value] : [];
        barangayInput.innerHTML = '<option value="">Select barangay</option>';
        barangays.forEach(function (barangay) {
            const option = document.createElement('option');
            option.value = barangay;
            option.textContent = barangay;
            option.selected = barangay === selectedBarangay;
            barangayInput.appendChild(option);
        });
        barangayInput.disabled = barangays.length === 0;
    }

    if (provinceInput) {
        provinceInput.addEventListener('change', function () {
            if (provinceInput.value !== 'Cavite' && cityInput) {
                cityInput.value = '';
            }
            populateRprBarangays('');
        });
    }
    if (cityInput) {
        cityInput.addEventListener('change', function () {
            populateRprBarangays('');
        });
    }
    populateRprBarangays(<?php echo json_encode($rprSelectedBarangay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);

    const registrationForm = document.querySelector('form[action="register_patient_receptionist.php"]');
    const dateOfBirthInput = document.getElementById('date_of_birth');
    const dobPicker = document.getElementById('rprDobPicker');
    const dobPickerButton = document.getElementById('rprDobPickerButton');
    const dobCalendar = document.getElementById('rprDobCalendar');
    const dobMonth = document.getElementById('rprDobMonth');
    const dobYear = document.getElementById('rprDobYear');
    const dobDays = document.getElementById('rprDobDays');
    const dobPreviousMonth = document.getElementById('rprDobPreviousMonth');
    const dobNextMonth = document.getElementById('rprDobNextMonth');

    function parseRprYmd(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!match) return null;
        const parsed = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        parsed.setHours(0, 0, 0, 0);
        return parsed.getFullYear() === Number(match[1])
            && parsed.getMonth() === Number(match[2]) - 1
            && parsed.getDate() === Number(match[3])
            ? parsed
            : null;
    }

    function rprDateToYmd(date) {
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0')
        ].join('-');
    }

    const latestAllowedDob = parseRprYmd(dateOfBirthInput ? dateOfBirthInput.dataset.latestAllowedDob : '');
    let selectedBirthDate = parseRprYmd(dateOfBirthInput ? dateOfBirthInput.value : '');
    let calendarMonth = latestAllowedDob ? latestAllowedDob.getMonth() : 0;
    let calendarYear = latestAllowedDob ? latestAllowedDob.getFullYear() : new Date().getFullYear();

    if (selectedBirthDate && latestAllowedDob && selectedBirthDate <= latestAllowedDob) {
        calendarMonth = selectedBirthDate.getMonth();
        calendarYear = selectedBirthDate.getFullYear();
    } else if (dateOfBirthInput && dateOfBirthInput.value) {
        dateOfBirthInput.value = '';
        selectedBirthDate = null;
    }

    function updateBirthDateButton() {
        if (!dobPickerButton) return;
        if (selectedBirthDate) {
            dobPickerButton.textContent = selectedBirthDate.toLocaleDateString('en-US', {
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });
            dobPickerButton.classList.remove('placeholder');
        } else {
            dobPickerButton.textContent = 'Select date of birth';
            dobPickerButton.classList.add('placeholder');
        }
    }

    function renderBirthCalendar() {
        if (!dobDays || !dobMonth || !dobYear || !latestAllowedDob) return;
        calendarYear = Math.min(latestAllowedDob.getFullYear(), Math.max(1900, Number(calendarYear) || latestAllowedDob.getFullYear()));
        if (calendarYear === latestAllowedDob.getFullYear() && calendarMonth > latestAllowedDob.getMonth()) {
            calendarMonth = latestAllowedDob.getMonth();
        }
        dobMonth.value = String(calendarMonth);
        dobYear.value = String(calendarYear);
        dobDays.innerHTML = '';

        const firstWeekday = new Date(calendarYear, calendarMonth, 1).getDay();
        const daysInMonth = new Date(calendarYear, calendarMonth + 1, 0).getDate();
        for (let blank = 0; blank < firstWeekday; blank += 1) {
            const empty = document.createElement('span');
            empty.className = 'rpr-dob-day-empty';
            dobDays.appendChild(empty);
        }

        for (let day = 1; day <= daysInMonth; day += 1) {
            const date = new Date(calendarYear, calendarMonth, day);
            date.setHours(0, 0, 0, 0);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rpr-dob-day';
            button.textContent = String(day);
            button.disabled = date > latestAllowedDob;
            if (selectedBirthDate && rprDateToYmd(date) === rprDateToYmd(selectedBirthDate)) {
                button.classList.add('selected');
            }
            button.addEventListener('click', function () {
                selectedBirthDate = date;
                dateOfBirthInput.value = rprDateToYmd(date);
                validateDateOfBirth();
                updateBirthDateButton();
                closeBirthCalendar();
            });
            dobDays.appendChild(button);
        }

        if (dobPreviousMonth) {
            dobPreviousMonth.disabled = calendarYear === 1900 && calendarMonth === 0;
        }
        if (dobNextMonth) {
            dobNextMonth.disabled = calendarYear === latestAllowedDob.getFullYear()
                && calendarMonth === latestAllowedDob.getMonth();
        }
    }

    function openBirthCalendar() {
        if (!dobCalendar || !dobPickerButton) return;
        if (dobCalendar.parentElement !== document.body) {
            document.body.appendChild(dobCalendar);
        }
        dobCalendar.classList.add('open');
        dobPickerButton.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        renderBirthCalendar();
    }

    function closeBirthCalendar() {
        if (!dobCalendar || !dobPickerButton) return;
        dobCalendar.classList.remove('open');
        dobPickerButton.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }

    function validateDateOfBirth() {
        if (!dateOfBirthInput || !dateOfBirthInput.value) {
            return false;
        }

        const isTooYoung = latestAllowedDob
            && dateOfBirthInput.value > rprDateToYmd(latestAllowedDob);
        return !isTooYoung;
    }

    updateBirthDateButton();
    if (dobPickerButton) {
        dobPickerButton.addEventListener('click', function () {
            if (dobCalendar && dobCalendar.classList.contains('open')) {
                closeBirthCalendar();
            } else {
                openBirthCalendar();
            }
        });
    }
    if (dobMonth) {
        dobMonth.addEventListener('change', function () {
            calendarMonth = Number(dobMonth.value);
            renderBirthCalendar();
        });
    }
    if (dobYear) {
        dobYear.addEventListener('change', function () {
            calendarYear = Number(dobYear.value);
            renderBirthCalendar();
        });
    }
    if (dobPreviousMonth) {
        dobPreviousMonth.addEventListener('click', function () {
            calendarMonth -= 1;
            if (calendarMonth < 0) {
                calendarMonth = 11;
                calendarYear -= 1;
            }
            renderBirthCalendar();
        });
    }
    if (dobNextMonth) {
        dobNextMonth.addEventListener('click', function () {
            calendarMonth += 1;
            if (calendarMonth > 11) {
                calendarMonth = 0;
                calendarYear += 1;
            }
            renderBirthCalendar();
        });
    }
    document.addEventListener('click', function (event) {
        if (dobPicker
            && !dobPicker.contains(event.target)
            && (!dobCalendar || !dobCalendar.contains(event.target))) {
            closeBirthCalendar();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeBirthCalendar();
    });

    if (registrationForm) {
        registrationForm.addEventListener('submit', function (event) {
            if (!validateDateOfBirth()) {
                event.preventDefault();
                if (dobPickerButton) {
                    dobPickerButton.focus();
                    openBirthCalendar();
                }
                return false;
            }
        });
    }

    // Keep an in-progress walk-in registration after an accidental refresh.
    // Password fields are intentionally excluded from the browser draft.
    const rprDraftKey = 'globalife.register_patient_receptionist.draft.v1';
    const rprDraftFieldNames = [
        'first_name', 'middle_name', 'last_name', 'suffix', 'gender',
        'date_of_birth', 'civil_status', 'phone', 'email', 'barangay',
        'city', 'address', 'username', 'emergency_contact_name',
        'emergency_contact_relationship', 'emergency_contact_number'
    ];
    const rprRegistrationCompleted = <?php echo $success !== '' ? 'true' : 'false'; ?>;
    let restoringRprDraft = false;

    function rprDraftFields() {
        if (!registrationForm) return [];
        return Array.from(registrationForm.querySelectorAll('[name]')).filter(function (field) {
            return rprDraftFieldNames.includes(field.name);
        });
    }

    function rprHasServerValues() {
        return rprDraftFields().some(function (field) {
            if (field.type === 'checkbox' || field.type === 'radio') return false;
            return String(field.value || '').trim() !== '';
        });
    }

    function saveRprDraft() {
        if (!registrationForm || restoringRprDraft || rprRegistrationCompleted) return;
        const draft = {};
        rprDraftFields().forEach(function (field) {
            if (field.type === 'checkbox') {
                draft[field.name] = { type: 'checkbox', checked: field.checked };
            } else if (field.type === 'radio') {
                if (!Object.prototype.hasOwnProperty.call(draft, field.name) || field.checked) {
                    draft[field.name] = { type: 'radio', value: field.checked ? field.value : null };
                }
            } else {
                draft[field.name] = field.value;
            }
        });

        try {
            sessionStorage.setItem(rprDraftKey, JSON.stringify(draft));
        } catch (error) {
            // Storage can be unavailable in private browsing; form use must continue.
        }
    }

    function restoreRprDraft() {
        if (!registrationForm || rprRegistrationCompleted || rprHasServerValues()) return;
        let draft;
        try {
            draft = JSON.parse(sessionStorage.getItem(rprDraftKey) || 'null');
        } catch (error) {
            draft = null;
        }
        if (!draft || typeof draft !== 'object') return;

        restoringRprDraft = true;
        rprDraftFields().forEach(function (field) {
            if (!Object.prototype.hasOwnProperty.call(draft, field.name)) return;
            const saved = draft[field.name];
            if (field.type === 'checkbox') {
                field.checked = !!(saved && saved.checked);
            } else if (field.type === 'radio') {
                field.checked = !!(saved && saved.value === field.value);
            } else if (typeof saved === 'string') {
                field.value = saved;
            }
            field.dispatchEvent(new Event(field.type === 'select-one' ? 'change' : 'input', { bubbles: true }));
        });
        restoringRprDraft = false;
        populateRprBarangays(typeof draft.barangay === 'string' ? draft.barangay : '');

        if (dateOfBirthInput) {
            const restoredBirthDate = parseRprYmd(dateOfBirthInput.value);
            if (restoredBirthDate && latestAllowedDob && restoredBirthDate <= latestAllowedDob) {
                selectedBirthDate = restoredBirthDate;
                calendarMonth = restoredBirthDate.getMonth();
                calendarYear = restoredBirthDate.getFullYear();
            } else if (dateOfBirthInput.value) {
                dateOfBirthInput.value = '';
                selectedBirthDate = null;
            }
            updateBirthDateButton();
        }
    }

    if (registrationForm) {
        registrationForm.addEventListener('input', saveRprDraft);
        registrationForm.addEventListener('change', saveRprDraft);
    }

    if (rprRegistrationCompleted) {
        try {
            sessionStorage.removeItem(rprDraftKey);
        } catch (error) {
            // Ignore unavailable browser storage.
        }
    } else {
        restoreRprDraft();
    }

    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const passwordToggle = document.getElementById('passwordToggle');
    const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
    const passwordStrengthBar = document.getElementById('passwordStrengthBar');

    if (!passwordInput || !confirmPasswordInput || !passwordToggle || !confirmPasswordToggle) {
        return;
    }

    const requirements = {
        length: document.getElementById('req-length'),
        uppercase: document.getElementById('req-uppercase'),
        lowercase: document.getElementById('req-lowercase'),
        number: document.getElementById('req-number'),
        special: document.getElementById('req-special')
    };

    const eyeOpenIcon = `
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
    `;

    const eyeClosedIcon = `
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
    `;

    function setToggleIcon(toggle, isHidden) {
        toggle.innerHTML = isHidden ? eyeOpenIcon : eyeClosedIcon;
    }

    function evaluatePassword(password) {
        return {
            length: password.length >= 8,
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };
    }

    function refreshPasswordUI() {
        const reqs = evaluatePassword(passwordInput.value);
        const passes = Object.values(reqs).filter(Boolean).length;

        Object.entries(reqs).forEach(([key, ok]) => {
            const row = requirements[key];
            if (!row) return;
            const icon = row.querySelector('.requirement-icon');
            if (ok) {
                row.classList.add('valid');
                row.classList.remove('invalid');
                icon.classList.add('valid');
                icon.classList.remove('invalid');
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />';
            } else {
                row.classList.remove('valid');
                row.classList.add('invalid');
                icon.classList.remove('valid');
                icon.classList.add('invalid');
                icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />';
            }
        });

        passwordStrengthBar.classList.remove('weak', 'medium', 'strong');
        if (passes <= 2) {
            passwordStrengthBar.classList.add('weak');
        } else if (passes === 3 || passes === 4) {
            passwordStrengthBar.classList.add('medium');
        } else if (passes === 5) {
            passwordStrengthBar.classList.add('strong');
        }

        confirmPasswordInput.classList.remove('match-input-valid', 'match-input-invalid');
        if (confirmPasswordInput.value.length > 0) {
            confirmPasswordInput.classList.add(
                passwordInput.value === confirmPasswordInput.value ? 'match-input-valid' : 'match-input-invalid'
            );
        }
    }

    function togglePassword(input, toggle) {
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        setToggleIcon(toggle, !isHidden);
    }

    setToggleIcon(passwordToggle, true);
    setToggleIcon(confirmPasswordToggle, true);

    passwordToggle.addEventListener('click', function () {
        togglePassword(passwordInput, passwordToggle);
    });
    confirmPasswordToggle.addEventListener('click', function () {
        togglePassword(confirmPasswordInput, confirmPasswordToggle);
    });
    passwordToggle.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            togglePassword(passwordInput, passwordToggle);
        }
    });
    confirmPasswordToggle.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            togglePassword(confirmPasswordInput, confirmPasswordToggle);
        }
    });

    passwordInput.addEventListener('input', refreshPasswordUI);
    confirmPasswordInput.addEventListener('input', refreshPasswordUI);
    refreshPasswordUI();
});
</script>

<?php include 'includes/footer.php'; ?>

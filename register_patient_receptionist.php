<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/name_parts.php';
require_once __DIR__ . '/includes/admin_notifications.php';

$currentUser = getCurrentUser();
$error = '';
$success = '';
$rprFirstNameMax = 15;
$rprMiddleNameMax = 1;
$rprLastNameMax = 15;
$rprSuffixMax = 3;

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
    $full_name = trim($_POST['full_name'] ?? '');
    $gender = $_POST['gender'] ?? '';
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $civil_status = trim($_POST['civil_status'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
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
    if ($birthDateIsValid && $birthDate <= new DateTime('today')) {
        $age = (new DateTime('today'))->diff($birthDate)->y;
    }
    
    // Validation
    $parsedName = clinic_name_split_full_name($full_name);
    $first_name = $first_name !== '' ? $first_name : (string) $parsedName['first_name'];
    $middle_name = $middle_name !== '' ? $middle_name : (string) $parsedName['middle_name'];
    $last_name = $last_name !== '' ? $last_name : (string) $parsedName['last_name'];
    $suffix = $suffix !== '' ? $suffix : (string) $parsedName['suffix'];
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

    if (empty($first_name) || empty($last_name) || empty($gender) || empty($date_of_birth) || empty($phone) || empty($email) || empty($barangay) || empty($city) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all required fields.';
    } elseif ($firstNameLength > $rprFirstNameMax) {
        $error = 'First name must not exceed ' . $rprFirstNameMax . ' letters.';
    } elseif ($middle_name !== '' && $middleNameLength !== $rprMiddleNameMax) {
        $error = 'Middle name must be exactly 1 letter.';
    } elseif ($lastNameLength > $rprLastNameMax) {
        $error = 'Last name must not exceed ' . $rprLastNameMax . ' letters.';
    } elseif ($suffix !== '' && $suffixLength > $rprSuffixMax) {
        $error = 'Suffix must not exceed ' . $rprSuffixMax . ' letters.';
    } elseif (!$birthDateIsValid) {
        $error = 'Please enter a valid date of birth.';
    } elseif ($birthDate > new DateTime('today')) {
        $error = 'Date of birth cannot be a future date.';
    } elseif ($password !== $confirm_password) {
        $error = 'Password and confirm password do not match.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
            $stmt->bind_param("sssssssssisssssssss", $username, $hashed_password, $first_name, $middle_name, $last_name, $suffix, $role, $email, $phone, $gender, $date_of_birth, $age, $civil_status, $address, $barangay, $city, $emergency_contact_name, $emergency_contact_relationship, $emergency_contact_number);
            
            if ($stmt->execute()) {
                $newPatientId = (int) $stmt->insert_id;
                create_admin_notification(
                    $conn,
                    'patient_account_created',
                    'New patient account',
                    $display_full_name . ' was registered by the reception desk.',
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
    .registration-container {
        max-width: 1460px;
        margin: 18px auto 34px;
        padding: 0 18px;
    }
    .intake-shell {
        display: grid;
        grid-template-columns: minmax(0, 1.85fr) minmax(300px, 360px);
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
        top: 16px;
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
    .header-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 14px;
    }
    .header-chip {
        display: inline-flex;
        align-items: center;
        padding: 8px 12px;
        border-radius: 999px;
        background: #fff;
        border: 1px solid #d7e5ef;
        color: #17475d;
        font-size: 0.8rem;
        font-weight: 800;
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
    .name-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
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
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #0f7cc2;
        text-decoration: none;
        font-weight: 800;
        margin-bottom: 12px;
        transition: color 0.2s;
    }
    .back-link:hover {
        color: #073b4c;
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
    }
    @media (max-width: 760px) {
        .registration-container {
            padding: 0 10px 22px;
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
        <a href="receptionist.php" class="back-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to dashboard
        </a>
        <div class="intake-shell">
            <div class="intake-panel">
                <div class="registration-header">
                    <div class="header-kicker">Reception desk intake</div>
                    <h2>Walk-in patient intake</h2>
                    <p>Encode personal information for patients registered directly at the clinic desk.</p>
                    <div class="header-meta">
                        <span class="header-chip">Faster walk-in encoding</span>
                        <span class="header-chip">Clear required fields</span>
                        <span class="header-chip">Patient portal ready</span>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="error-message">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                        </svg>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success-message">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                        <div><?php echo htmlspecialchars($success); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="register_patient_receptionist.php" autocomplete="off">
                    <div class="form-section">
                        <div class="section-head">
                            <div>
                                <div class="form-section-title">Personal information</div>
                                <div class="section-note">Basic identity and contact details needed to create the patient account.</div>
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
                                <label for="middle_name">Middle name</label>
                                <input type="text" id="middle_name" name="middle_name" maxlength="<?php echo $rprMiddleNameMax; ?>" placeholder="A" value="<?php echo rpr_value('middle_name'); ?>">
                                <span class="field-hint">Exactly 1 letter, auto-uppercase.</span>
                            </div>
                            <div class="form-group">
                                <label for="last_name">Last name <span class="required">*</span></label>
                                <input type="text" id="last_name" name="last_name" required maxlength="<?php echo $rprLastNameMax; ?>" placeholder="Dela Cruz" value="<?php echo rpr_value('last_name'); ?>">
                                <span class="field-hint">Maximum of <?php echo $rprLastNameMax; ?> letters.</span>
                            </div>
                            <div class="form-group">
                                <label for="suffix">Suffix</label>
                                <input type="text" id="suffix" name="suffix" maxlength="<?php echo $rprSuffixMax; ?>" placeholder="Jr" value="<?php echo rpr_value('suffix'); ?>">
                                <span class="field-hint">Optional, up to <?php echo $rprSuffixMax; ?> letters.</span>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gender">Gender <span class="required">*</span></label>
                                <select id="gender" name="gender" required>
                                    <option value="">Select gender</option>
                                    <option value="Male" <?php echo rpr_selected('gender', 'Male'); ?>>Male</option>
                                    <option value="Female" <?php echo rpr_selected('gender', 'Female'); ?>>Female</option>
                                    <option value="Other" <?php echo rpr_selected('gender', 'Other'); ?>>Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="date_of_birth">Date of birth <span class="required">*</span></label>
                                <input type="date" id="date_of_birth" name="date_of_birth" required data-birthday-picker max="<?php echo date('Y-m-d'); ?>" value="<?php echo rpr_value('date_of_birth'); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="civil_status">Civil status</label>
                                <select id="civil_status" name="civil_status">
                                    <option value="">Select status</option>
                                    <option value="Single" <?php echo rpr_selected('civil_status', 'Single'); ?>>Single</option>
                                    <option value="Married" <?php echo rpr_selected('civil_status', 'Married'); ?>>Married</option>
                                    <option value="Divorced" <?php echo rpr_selected('civil_status', 'Divorced'); ?>>Divorced</option>
                                    <option value="Widowed" <?php echo rpr_selected('civil_status', 'Widowed'); ?>>Widowed</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone number <span class="required">*</span></label>
                                <input type="tel" id="phone" name="phone" required placeholder="09xxxxxxxxx" value="<?php echo rpr_value('phone'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="email">Email address <span class="required">*</span></label>
                            <input type="email" id="email" name="email" required placeholder="name@example.com" value="<?php echo rpr_value('email'); ?>">
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
                            <label for="address">Street address</label>
                            <input type="text" id="address" name="address" placeholder="House No., street, subdivision" value="<?php echo rpr_value('address'); ?>">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="barangay">Barangay <span class="required">*</span></label>
                                <input type="text" id="barangay" name="barangay" required placeholder="Barangay name" value="<?php echo rpr_value('barangay'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="city">City <span class="required">*</span></label>
                                <input type="text" id="city" name="city" required placeholder="City or municipality" value="<?php echo rpr_value('city'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-head">
                            <div>
                                <div class="form-section-title">Emergency contact</div>
                                <div class="section-note">Optional, but strongly recommended for walk-in patients.</div>
                            </div>
                            <span class="section-badge">Optional</span>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="emergency_contact_name">Contact name</label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name" placeholder="Full name" value="<?php echo rpr_value('emergency_contact_name'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="emergency_contact_relationship">Relationship</label>
                                <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" placeholder="Spouse, parent, sibling" value="<?php echo rpr_value('emergency_contact_relationship'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="emergency_contact_number">Contact number</label>
                            <input type="tel" id="emergency_contact_number" name="emergency_contact_number" placeholder="09xxxxxxxxx" value="<?php echo rpr_value('emergency_contact_number'); ?>">
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-head">
                            <div>
                                <div class="form-section-title">Login credentials</div>
                                <div class="section-note">Create the patient’s clinic login so they can view bookings later if needed.</div>
                            </div>
                            <span class="section-badge">Account</span>
                        </div>

                        <div class="login-stack">
                            <div class="form-group">
                                <label for="username">Username <span class="required">*</span></label>
                                <input type="text" id="username" name="username" required placeholder="patient_username" value="<?php echo rpr_value('username'); ?>">
                            </div>

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
                                <small>Double-check the phone number and birthday before saving. Those two fields help prevent duplicate patient records.</small>
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
                    <h4>Reception flow</h4>
                    <div class="summary-step">
                        <span class="summary-step-number">1</span>
                        <p>Start with identity and contact details so duplicate patient records are easier to spot.</p>
                    </div>
                    <div class="summary-step">
                        <span class="summary-step-number">2</span>
                        <p>Add address and emergency contact only when available. These can stay lightweight for walk-ins.</p>
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
        <p class="qr-modal-note">After email verification, the administrator will receive a new patient account notification.</p>
        <button type="button" class="qr-page-link" id="printWalkInQr">Print / Save as PDF</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
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
        if (event.key === 'Escape' && walkInQrModal && walkInQrModal.classList.contains('active')) {
            closeQrModal();
        }
    });

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

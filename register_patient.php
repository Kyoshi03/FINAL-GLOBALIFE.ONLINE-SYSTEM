<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'includes/session.php';
require_once 'config/database.php';
require_once 'includes/mailer.php';
require_once 'includes/sms.php';

$registrationSource = trim((string) ($_POST['registration_source'] ?? $_GET['source'] ?? ''));
if ($registrationSource !== 'walkin_qr') {
    $registrationSource = '';
}

$error = '';
$success = '';
$showPasswordPopup = false;
$showSuccessPopup = false;
$passwordPopupMessage = '';
$registeredUsername = '';
$registeredDisplayName = '';

function register_patient_normalize_text(string $value): string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return $value;
}

function register_patient_letter_count(string $value): int {
    if ($value === '') {
        return 0;
    }

    return preg_match_all('/\p{L}/u', $value) ?: 0;
}

function register_patient_filter_name_part(string $value, bool $allowSeparators = false): string {
    $value = register_patient_normalize_text($value);
    if ($value === '') {
        return '';
    }

    $pattern = $allowSeparators ? '/[^\p{L}\s\'-]+/u' : '/[^\p{L}]+/u';
    $value = preg_replace($pattern, '', $value) ?? '';

    if ($allowSeparators) {
        $value = register_patient_normalize_text($value);
    }

    return $value;
}

function register_patient_title_case(string $value): string {
    $value = register_patient_filter_name_part($value, true);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value));
}

function register_patient_uppercase(string $value): string {
    $value = register_patient_filter_name_part($value, false);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
}

function register_patient_build_full_name(string $firstName, string $middleName, string $lastName, string $suffix = ''): string {
    $parts = array_filter([
        register_patient_title_case($firstName),
        register_patient_uppercase($middleName),
        register_patient_title_case($lastName),
        register_patient_uppercase($suffix),
    ], static fn ($part) => $part !== '');

    return trim(implode(' ', $parts));
}

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['created'])
    && $_GET['created'] === '1'
    && !empty($_SESSION['patient_registration_success'])
) {
    $registrationSuccess = $_SESSION['patient_registration_success'];
    unset($_SESSION['patient_registration_success']);

    $success = 'You have successfully created an account.';
    $showSuccessPopup = true;
    $registeredUsername = (string) ($registrationSuccess['username'] ?? '');
    $registeredDisplayName = (string) ($registrationSuccess['display_name'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = register_patient_normalize_text((string) ($_POST['first_name'] ?? ''));
    $middle_name = register_patient_normalize_text((string) ($_POST['middle_name'] ?? ''));
    $last_name = register_patient_normalize_text((string) ($_POST['last_name'] ?? ''));
    $suffix = register_patient_normalize_text((string) ($_POST['suffix'] ?? ''));
    $full_name = register_patient_build_full_name($first_name, $middle_name, $last_name, $suffix);
    $gender = $_POST['gender'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $civil_status = trim($_POST['civil_status'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $verification_channel = strtolower(trim($_POST['verification_channel'] ?? 'email'));
    if ($email === '' && $verification_channel === 'email') {
        $verification_channel = 'sms';
    }
    $barangay = trim($_POST['barangay'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_relationship = trim($_POST['emergency_contact_relationship'] ?? '');
    $emergency_contact_number = trim($_POST['emergency_contact_number'] ?? '');
    $agree_clinic_terms = !empty($_POST['agree_clinic_terms']);
    $todayDate = date('Y-m-d');
    $birthDateIsValid = false;
    
    // Calculate age from date of birth
    $age = null;
    if (!empty($date_of_birth)) {
        $dob = DateTime::createFromFormat('!Y-m-d', $date_of_birth);
        $dateErrors = DateTime::getLastErrors();
        $birthDateIsValid = $dob instanceof DateTime
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
            && $dob->format('Y-m-d') === $date_of_birth;
        if ($birthDateIsValid && $date_of_birth <= $todayDate) {
            $today = new DateTime($todayDate);
            $age = $today->diff($dob)->y;
        }
    }
    
    // Password validation function
    function validatePassword($password) {
        $errors = [];
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character (!@#$%^&*).';
        }
        return $errors;
    }
    
    // Validation
    if (
        empty($first_name) || empty($middle_name) || empty($last_name)
        || empty($gender) || empty($date_of_birth) || empty($phone)
        || empty($barangay) || empty($city) || empty($username)
        || empty($password) || empty($confirm_password)
    ) {
        $error = 'Please fill in all required fields marked with *.';
    } elseif (register_patient_letter_count($first_name) < 1) {
        $error = 'First name must contain at least 1 letter.';
    } elseif (register_patient_letter_count($first_name) > 15) {
        $error = 'First name must not exceed 15 letters.';
    } elseif (!preg_match('/^[\p{L}\s\'-]+$/u', $first_name)) {
        $error = 'First name may only contain letters, spaces, hyphens, or apostrophes.';
    } elseif (register_patient_letter_count($middle_name) !== 1) {
        $error = 'Middle name must be exactly 1 letter.';
    } elseif (!preg_match('/^[\p{L}]$/u', $middle_name)) {
        $error = 'Middle name must contain letters only.';
    } elseif (register_patient_letter_count($last_name) < 1) {
        $error = 'Last name must contain at least 1 letter.';
    } elseif (register_patient_letter_count($last_name) > 15) {
        $error = 'Last name must not exceed 15 letters.';
    } elseif (!preg_match('/^[\p{L}\s\'-]+$/u', $last_name)) {
        $error = 'Last name may only contain letters, spaces, hyphens, or apostrophes.';
    } elseif ($suffix !== '' && register_patient_letter_count($suffix) < 1) {
        $error = 'Suffix must contain at least 1 letter.';
    } elseif ($suffix !== '' && register_patient_letter_count($suffix) > 3) {
        $error = 'Suffix must not exceed 3 letters.';
    } elseif ($suffix !== '' && !preg_match('/^[\p{L}]+$/u', $suffix)) {
        $error = 'Suffix must contain letters only.';
    } elseif (!$birthDateIsValid) {
        $error = 'Please enter a valid date of birth.';
    } elseif ($date_of_birth > $todayDate) {
        $error = 'Date of birth cannot be a future date.';
    } elseif (!$agree_clinic_terms) {
        $error = 'Please read and agree to the clinic privacy notice before creating your account.';
    } elseif ($password !== $confirm_password) {
        $showPasswordPopup = true;
        $passwordPopupMessage = 'Password and confirm password do not match. Please type them again.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($verification_channel, ['email', 'sms'], true)) {
        $error = 'Please choose where you want to receive the verification code.';
    } elseif ($verification_channel === 'email' && $email === '') {
        $error = 'Enter an email address or choose SMS verification instead.';
    } elseif (clinic_sms_normalize_phone($phone) === null) {
        $error = 'Enter a valid Philippine mobile number, such as 09171234567.';
    } else {
        // Validate password requirements
        $passwordErrors = validatePassword($password);
        if (!empty($passwordErrors)) {
            $showPasswordPopup = true;
            $passwordPopupMessage = implode(' ', $passwordErrors);
        } else {
        $conn = getDBConnection();
        
        // Check if username already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = 'Username already exists. Please choose a different username.';
            $stmt->close();
        } else {
            $stmt->close();
            
            $normalizedPhone = clinic_sms_normalize_phone($phone) ?? '';
            $phoneLocal = '0' . substr($normalizedPhone, 3);
            $phoneIntlNoPlus = substr($normalizedPhone, 1);

            // Check if phone already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE phone IN (?, ?, ?, ?) LIMIT 1");
            $stmt->bind_param("ssss", $phone, $phoneLocal, $normalizedPhone, $phoneIntlNoPlus);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Mobile number already registered. Please use a different number or sign in to your existing account.';
                $stmt->close();
            } else {
                $stmt->close();

                if ($email !== '') {
                    // Check if email already exists
                    $stmt = $conn->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows > 0) {
                        $error = 'Email already registered. Please use a different email or leave it blank and verify by SMS.';
                        $stmt->close();
                    } else {
                        $stmt->close();
                    }
                }

                if ($error === '') {
                
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $role = 'patient';
                
                // Combine address
                $full_address = $address;
                if (!empty($barangay)) {
                    $full_address .= (!empty($full_address) ? ', ' : '') . $barangay;
                }
                if (!empty($city)) {
                    $full_address .= (!empty($full_address) ? ', ' : '') . $city;
                }
                
                $verificationCode = (string) random_int(100000, 999999);
                $_SESSION['pending_patient_registration'] = [
                    'data' => [
                        'username' => $username,
                        'password' => $hashed_password,
                        'full_name' => $full_name,
                        'role' => $role,
                        'email' => $email,
                        'phone' => $phone,
                        'first_name' => register_patient_title_case($first_name),
                        'middle_name' => register_patient_uppercase($middle_name),
                        'last_name' => register_patient_title_case($last_name),
                        'suffix' => register_patient_uppercase($suffix),
                        'gender' => $gender,
                        'date_of_birth' => $date_of_birth,
                        'age' => $age,
                        'civil_status' => $civil_status,
                        'address' => $full_address,
                        'barangay' => $barangay,
                        'city' => $city,
                        'emergency_contact_name' => $emergency_contact_name,
                        'emergency_contact_relationship' => $emergency_contact_relationship,
                        'emergency_contact_number' => $emergency_contact_number,
                        'registration_source' => $registrationSource,
                        'verification_channel' => $verification_channel,
                    ],
                    'code_hash' => password_hash($verificationCode, PASSWORD_DEFAULT),
                    'expires_at' => time() + 10 * 60,
                    'sent_at' => time(),
                    'attempts' => 0,
                ];

                $sent = clinic_send_verification_code(
                    $verification_channel,
                    $email,
                    $phone,
                    $full_name,
                    $verificationCode,
                    'registration'
                );

                if (!$sent['ok']) {
                    unset($_SESSION['pending_patient_registration']);
                    $error = $sent['error'];
                } else {
                    $conn->close();
                    header('Location: verify_patient_email.php?sent=1');
                    exit();
                }
                }
            }
        }
        
        $conn->close();
        }
    }
}

$pageTitle = "Patient Registration | Globalife Medical Laboratory & Polyclinic";
$additionalStyles = '
    body {
        background: linear-gradient(135deg, #90e0ef 0%, #48cae4 100%);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    .login-wrapper {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
        position: relative;
        overflow: hidden;
    }
    .login-wrapper::before {
        content: "";
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
        background-size: 50px 50px;
        animation: float 20s infinite linear;
    }
    @keyframes float {
        0% { transform: translate(0, 0) rotate(0deg); }
        100% { transform: translate(-50px, -50px) rotate(360deg); }
    }
    .login-container {
        max-width: 600px;
        width: 100%;
        padding: 50px 40px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        position: relative;
        z-index: 1;
        animation: slideUp 0.5s ease-out;
        border-top: 4px solid #48cae4;
        max-height: 90vh;
        overflow-y: auto;
    }
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .login-header {
        text-align: center;
        margin-bottom: 30px;
    }
    .login-logo {
        width: 80px;
        height: 80px;
        margin: 0 auto 20px;
        border-radius: 50%;
        border: 4px solid #48cae4;
        box-shadow: 0 4px 15px rgba(72, 202, 228, 0.3);
        object-fit: cover;
    }
    .role-badge {
        display: inline-block;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 15px;
    }
    .login-container h2 {
        color: #48cae4;
        margin: 0 0 8px 0;
        font-size: 2rem;
        font-weight: 700;
    }
    .login-subtitle {
        color: #666;
        font-size: 0.95rem;
        margin: 0;
    }
    .form-section {
        margin-bottom: 30px;
    }
    .form-section-title {
        color: #0077b6;
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e0e0e0;
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
    }
    .name-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    .name-grid--bottom {
        margin-bottom: 0;
    }
    .name-part {
        margin-bottom: 0;
    }
    .name-part label {
        font-size: 0.8rem;
        margin-bottom: 7px;
    }
    .name-part .field-hint {
        margin-top: 8px;
        margin-bottom: 0;
    }
    .form-group {
        margin-bottom: 20px;
        position: relative;
    }
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .form-group label .optional {
        color: #999;
        font-weight: normal;
        text-transform: none;
        font-size: 0.75rem;
    }
    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }
    .input-icon {
        position: absolute;
        left: 15px;
        color: #48cae4;
        width: 20px;
        height: 20px;
        z-index: 2;
    }
    .password-toggle {
        position: absolute;
        right: 15px;
        color: #48cae4;
        width: 20px;
        height: 20px;
        z-index: 2;
        cursor: pointer;
        transition: color 0.2s;
    }
    .password-toggle:hover {
        color: #0077b6;
    }
    .form-group input,
    .form-group select {
        width: 100%;
        padding: 12px 15px 12px 50px;
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        font-size: 1rem;
        box-sizing: border-box;
        transition: all 0.3s ease;
        background: #f8f9fa;
        font-family: inherit;
    }
    .form-group select {
        cursor: pointer;
    }
    .form-group input[type="password"] {
        padding-right: 50px;
    }
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: #48cae4;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(72, 202, 228, 0.1);
        transform: translateY(-2px);
    }
    .form-group input::placeholder {
        color: #999;
    }
    .age-display {
        display: inline-block;
        margin-left: 10px;
        padding: 5px 12px;
        background: #48cae4;
        color: #fff;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    .password-requirements {
        margin-top: 10px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 10px;
        border: 2px solid #e0e0e0;
    }
    .password-requirements-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #333;
        margin-bottom: 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .password-requirement {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
        font-size: 0.85rem;
        color: #666;
        transition: all 0.3s ease;
    }
    .password-requirement:last-child {
        margin-bottom: 0;
    }
    .password-requirement.valid {
        color: #28a745;
    }
    .password-requirement.invalid {
        color: #dc3545;
    }
    .requirement-icon {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
    }
    .requirement-icon.valid {
        color: #28a745;
    }
    .requirement-icon.invalid {
        color: #dc3545;
    }
    .password-strength {
        margin-top: 10px;
        height: 4px;
        background: #e0e0e0;
        border-radius: 2px;
        overflow: hidden;
    }
    .password-strength-bar {
        height: 100%;
        width: 0%;
        transition: all 0.3s ease;
        border-radius: 2px;
    }
    .password-strength-bar.weak {
        width: 33%;
        background: #dc3545;
    }
    .password-strength-bar.medium {
        width: 66%;
        background: #ffc107;
    }
    .password-strength-bar.strong {
        width: 100%;
        background: #28a745;
    }
    .error-message {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: #fff;
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        border-left: 4px solid #c33;
        display: flex;
        align-items: center;
        gap: 10px;
        animation: shake 0.5s;
        box-shadow: 0 4px 15px rgba(238, 90, 111, 0.3);
    }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-10px); }
        75% { transform: translateX(10px); }
    }
    .success-message {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        color: #fff;
        padding: 15px 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        border-left: 4px solid #2f9e44;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 15px rgba(79, 207, 102, 0.3);
    }
    .error-icon, .success-icon {
        width: 20px;
        height: 20px;
        flex-shrink: 0;
    }
    .login-btn-form {
        width: 100%;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        padding: 16px;
        border: none;
        border-radius: 12px;
        font-size: 1.1rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(72, 202, 228, 0.4);
        text-transform: uppercase;
        letter-spacing: 1px;
        position: relative;
        overflow: hidden;
        margin-top: 10px;
    }
    .login-btn-form::before {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.3);
        transform: translate(-50%, -50%);
        transition: width 0.6s, height 0.6s;
    }
    .login-btn-form:hover::before {
        width: 300px;
        height: 300px;
    }
    .login-btn-form:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(72, 202, 228, 0.5);
    }
    .login-btn-form:active {
        transform: translateY(0);
    }
    .login-btn-form span {
        position: relative;
        z-index: 1;
    }
    .back-to-home {
        text-align: center;
        margin-top: 25px;
        padding-top: 25px;
        border-top: 1px solid #e0e0e0;
    }
    .back-to-home a {
        color: #48cae4;
        text-decoration: none;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: color 0.2s;
        margin: 0 10px;
    }
    .back-to-home a:hover {
        color: #0077b6;
    }
    .back-icon {
        width: 18px;
        height: 18px;
    }
    .login-link {
        text-align: center;
        margin-top: 20px;
        color: #666;
        font-size: 0.9rem;
    }
    .login-link a {
        color: #48cae4;
        text-decoration: none;
        font-weight: 600;
        transition: color 0.2s;
    }
    .login-link a:hover {
        color: #0077b6;
        text-decoration: underline;
    }
    .popup-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: fadeIn 0.25s ease;
    }
    .popup-overlay.active {
        display: flex;
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .popup-box {
        background: #fff;
        border-radius: 16px;
        padding: 32px 28px;
        max-width: 400px;
        width: 100%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: popIn 0.3s ease;
        border-top: 4px solid #48cae4;
    }
    @keyframes popIn {
        from {
            opacity: 0;
            transform: scale(0.9) translateY(10px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
    .popup-box.error {
        border-top-color: #dc3545;
    }
    .popup-box.success {
        border-top-color: #28a745;
    }
    .popup-icon {
        width: 56px;
        height: 56px;
        margin: 0 auto 16px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .popup-icon.error {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: #fff;
    }
    .popup-icon.success {
        background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        color: #fff;
    }
    .popup-icon svg {
        width: 28px;
        height: 28px;
    }
    .popup-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #333;
        margin: 0 0 10px;
    }
    .popup-message {
        color: #666;
        font-size: 0.95rem;
        margin: 0 0 24px;
        line-height: 1.5;
    }
    .popup-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .popup-actions.row {
        flex-direction: row;
        gap: 12px;
    }
    .popup-btn {
        flex: 1;
        padding: 12px 20px;
        border: none;
        border-radius: 10px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        transition: all 0.2s ease;
        font-family: inherit;
    }
    .popup-btn-primary {
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(72, 202, 228, 0.4);
    }
    .popup-btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(72, 202, 228, 0.5);
    }
    .popup-btn-secondary {
        background: #f0f4f8;
        color: #0077b6;
        border: 2px solid #48cae4;
    }
    .popup-btn-secondary:hover {
        background: #e8f4f8;
    }
    .popup-btn-danger {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(238, 90, 111, 0.3);
    }
    .popup-btn-danger:hover {
        transform: translateY(-1px);
    }
    .reg-guide {
        background: linear-gradient(135deg, #e8f8fc 0%, #f0faf9 100%);
        border: 1px solid rgba(72, 202, 228, 0.45);
        border-radius: 12px;
        padding: 16px 18px;
        margin-bottom: 22px;
        text-align: left;
    }
    .reg-guide-title {
        color: #0077b6;
        font-weight: 700;
        font-size: 0.95rem;
        margin: 0 0 8px;
    }
    .reg-guide p {
        color: #51636d;
        font-size: 0.88rem;
        line-height: 1.55;
        margin: 0 0 10px;
    }
    .reg-guide ul {
        margin: 0;
        padding-left: 18px;
        color: #435761;
        font-size: 0.86rem;
        line-height: 1.6;
    }
    .required-legend {
        font-size: 0.82rem;
        color: #6c7a83;
        margin: 0 0 18px;
        text-align: left;
    }
    .required-legend strong {
        color: #c92a2a;
    }
    .field-hint {
        display: block;
        margin-top: 6px;
        font-size: 0.8rem;
        color: #6c7a83;
        line-height: 1.4;
        font-weight: 500;
    }
    .verification-choice {
        margin-top: 16px;
        padding: 16px;
        border: 1px solid #cfe5ef;
        border-radius: 12px;
        background: #f8fcfe;
    }
    .verification-choice > strong {
        display: block;
        color: #073b4c;
        margin-bottom: 5px;
    }
    .verification-choice > p {
        margin: 0 0 12px;
        color: #607784;
        font-size: .86rem;
        line-height: 1.45;
    }
    .verification-options {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .verification-option {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        padding: 12px 14px;
        border: 2px solid #d8e8f0;
        border-radius: 10px;
        background: #fff;
        cursor: pointer;
    }
    .verification-option:has(input:checked) {
        border-color: #0c83c3;
        background: #eaf7fd;
        box-shadow: 0 0 0 3px rgba(12,131,195,.1);
    }
    .verification-option.is-disabled {
        opacity: .58;
        cursor: not-allowed;
        background: #f3f7fa;
    }
    .verification-option.is-disabled:has(input:checked) {
        border-color: #d8e8f0;
        box-shadow: none;
    }
    .verification-option input {
        width: 18px;
        height: 18px;
        margin: 0;
        accent-color: #0c83c3;
    }
    .verification-option span {
        min-width: 0;
        color: #385668;
        font-size: .84rem;
        line-height: 1.35;
    }
    .verification-option strong {
        display: block;
        color: #073b4c;
        font-size: .92rem;
    }
    .dob-picker {
        position: relative;
    }
    .dob-picker-button {
        width: 100%;
        min-height: 50px;
        box-sizing: border-box;
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        background: #fff;
        color: #1f343d;
        padding: 12px 44px 12px 46px;
        text-align: left;
        font: inherit;
        cursor: pointer;
    }
    .dob-picker-button.placeholder {
        color: #8a9aa3;
    }
    .dob-picker-button:focus {
        outline: none;
        border-color: #48cae4;
        box-shadow: 0 0 0 3px rgba(72, 202, 228, 0.12);
    }
    .dob-calendar-icon {
        position: absolute;
        right: 15px;
        top: 15px;
        width: 20px;
        height: 20px;
        color: #0077b6;
        pointer-events: none;
    }
    .dob-calendar {
        display: none;
        position: fixed;
        z-index: 1500;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: min(340px, calc(100vw - 48px));
        box-sizing: border-box;
        border: 1px solid #cfe2ed;
        border-radius: 12px;
        background: #fff;
        padding: 14px;
        box-shadow: 0 0 0 100vmax rgba(7, 59, 76, 0.45), 0 18px 38px rgba(7, 59, 76, 0.24);
    }
    .dob-calendar.open {
        display: block;
    }
    .dob-calendar-controls {
        display: grid;
        grid-template-columns: 40px minmax(0, 1fr) 90px 40px;
        gap: 7px;
        align-items: center;
        margin-bottom: 12px;
    }
    .dob-calendar-controls button {
        width: 40px;
        height: 40px;
        border: 1px solid #d4e6f1;
        border-radius: 8px;
        background: #f5faff;
        color: #0b4f80;
        font-size: 1.25rem;
        cursor: pointer;
    }
    .dob-calendar-controls button:disabled {
        opacity: 0.35;
        cursor: not-allowed;
    }
    .dob-calendar-controls select,
    .dob-calendar-controls input {
        width: 100%;
        min-height: 40px;
        box-sizing: border-box;
        border: 1px solid #d4e6f1;
        border-radius: 8px;
        background: #fff;
        color: #073b4c;
        padding: 7px 9px;
        font: inherit;
        font-weight: 700;
    }
    .dob-weekdays,
    .dob-days {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        gap: 4px;
    }
    .dob-weekdays {
        margin-bottom: 5px;
        color: #60727d;
        font-size: 0.72rem;
        font-weight: 900;
        text-align: center;
        text-transform: uppercase;
    }
    .dob-weekdays span {
        padding: 5px 0;
    }
    .dob-day,
    .dob-day-empty {
        aspect-ratio: 1;
        min-width: 0;
    }
    .dob-day {
        border: 0;
        border-radius: 8px;
        background: #f6f9fb;
        color: #1f343d;
        font: inherit;
        font-size: 0.85rem;
        font-weight: 700;
        cursor: pointer;
    }
    .dob-day:hover,
    .dob-day:focus {
        outline: none;
        background: #dff3fb;
        color: #005f91;
    }
    .dob-day.selected {
        background: #0077b6;
        color: #fff;
    }
    .dob-day.today:not(.selected) {
        box-shadow: inset 0 0 0 2px #48cae4;
    }
    .dob-day:disabled {
        background: #f4f4f4;
        color: #b4bec4;
        cursor: not-allowed;
    }
    .dob-calendar-footer {
        display: flex;
        justify-content: flex-end;
        margin-top: 10px;
    }
    .dob-clear {
        border: 0;
        background: transparent;
        color: #0077b6;
        padding: 6px;
        font: inherit;
        font-weight: 800;
        cursor: pointer;
    }
    .terms-box {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        background: #f8fcfd;
        border: 1px solid #d0ebf2;
        border-radius: 12px;
        padding: 14px 16px;
        margin: 8px 0 20px;
        text-align: left;
    }
    .terms-box input {
        margin-top: 4px;
        width: 18px;
        height: 18px;
        flex-shrink: 0;
        accent-color: #0077b6;
    }
    .terms-box label {
        font-size: 0.88rem;
        color: #435761;
        line-height: 1.55;
        font-weight: 500;
        cursor: pointer;
    }
    .popup-box.success-popup {
        max-width: 460px;
        text-align: left;
        padding: 28px 26px 24px;
    }
    .success-popup-header {
        text-align: center;
        margin-bottom: 18px;
    }
    .success-popup-logo {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        border: 3px solid #48cae4;
        margin: 0 auto 12px;
        object-fit: contain;
        background: #fff;
    }
    .popup-icon.success.pulse {
        animation: successPulse 1.2s ease 2;
    }
    @keyframes successPulse {
        0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(64, 192, 87, 0.4); }
        50% { transform: scale(1.06); box-shadow: 0 0 0 12px rgba(64, 192, 87, 0); }
    }
    .username-save-box {
        background: #f0f9ff;
        border: 2px dashed #48cae4;
        border-radius: 12px;
        padding: 14px 16px;
        margin: 0 0 16px;
    }
    .username-save-label {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #0077b6;
        font-weight: 700;
        margin: 0 0 6px;
    }
    .username-save-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .username-save-value {
        font-size: 1.15rem;
        font-weight: 800;
        color: #073b4c;
        word-break: break-all;
    }
    .copy-username-btn {
        padding: 8px 14px;
        border-radius: 8px;
        border: none;
        background: #0077b6;
        color: #fff;
        font-size: 0.82rem;
        font-weight: 700;
        cursor: pointer;
        font-family: inherit;
    }
    .copy-username-btn:hover {
        background: #023e8a;
    }
    .success-next-steps {
        margin: 0 0 20px;
        padding: 0;
        list-style: none;
    }
    .success-next-steps li {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 12px;
        font-size: 0.9rem;
        color: #435761;
        line-height: 1.45;
    }
    .success-step-num {
        flex-shrink: 0;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: linear-gradient(135deg, #48cae4, #0077b6);
        color: #fff;
        font-weight: 800;
        font-size: 0.8rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .success-clinic-note {
        font-size: 0.82rem;
        color: #6c7a83;
        background: #fff8e6;
        border: 1px solid #ffe08a;
        border-radius: 10px;
        padding: 10px 12px;
        margin: 0 0 18px;
        line-height: 1.5;
    }
    .popup-box.success-popup .popup-actions {
        flex-direction: column;
    }
    .popup-box.success-popup .popup-btn {
        width: 100%;
        text-align: center;
        box-sizing: border-box;
    }
    @media (max-width: 600px) {
        .login-container {
            padding: 40px 30px;
            margin: 20px;
        }
        .login-container h2 {
            font-size: 1.6rem;
        }
        .form-row {
            grid-template-columns: 1fr;
        }
        .name-grid {
            grid-template-columns: 1fr;
        }
        .verification-options {
            grid-template-columns: 1fr;
        }
        .popup-actions.row {
            flex-direction: column;
        }
    }
';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="globalife.png">
    <link rel="stylesheet" href="main.css">
    <style><?php echo $additionalStyles; ?></style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <div class="login-header">
                <img src="globalife.png" alt="Clinic Logo" class="login-logo">
                <h2>Create Account</h2>
                <p class="login-subtitle">Register to book appointments and view your clinic records online</p>
            </div>

            <div class="reg-guide" role="note">
                <p class="reg-guide-title">Before you register</p>
                <p>Use accurate details (same as your valid ID when possible). Our clinic uses this for appointments, lab results, and emergency contact.</p>
                <ul>
                    <li>Active mobile number for SMS verification and clinic reminders</li>
                    <li>Email is optional, but helpful for password reset and email notices</li>
                    <li>A username you will remember — you need it every time you log in</li>
                    <li>Emergency contact (recommended for urgent clinic situations)</li>
                </ul>
            </div>
            <p class="required-legend"><strong>*</strong> Required fields</p>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <svg class="error-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            
            <form method="POST" action="register_patient.php" id="registerForm">
                <input type="hidden" name="registration_source" value="<?php echo htmlspecialchars($registrationSource); ?>">
                <!-- Personal Information -->
                <div class="form-section">
                    <div class="form-section-title">Personal Information</div>
                    
                    <div class="form-group">
                        <label>Name Details</label>
                        <div class="name-grid">
                            <div class="name-part">
                                <label for="first_name">First Name</label>
                                <div class="input-wrapper">
                                    <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    <input type="text" id="first_name" name="first_name" placeholder="Juan" maxlength="40" required autofocus value="<?php echo (!empty($success) ? '' : (isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : '')); ?>">
                                </div>
                                <span class="field-hint">15 letters max. <span id="firstNameCounter">0/15</span></span>
                            </div>

                            <div class="name-part">
                                <label for="middle_name">Middle Name / Initial</label>
                                <div class="input-wrapper">
                                    <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    <input type="text" id="middle_name" name="middle_name" placeholder="M" maxlength="1" required value="<?php echo (!empty($success) ? '' : (isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : '')); ?>" style="text-transform: uppercase;">
                                </div>
                                <span class="field-hint">1 letter only. <span id="middleNameCounter">0/1</span></span>
                            </div>
                        </div>

                        <div class="name-grid name-grid--bottom">
                            <div class="name-part">
                                <label for="last_name">Last Name</label>
                                <div class="input-wrapper">
                                    <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    <input type="text" id="last_name" name="last_name" placeholder="Dela Cruz" maxlength="40" required value="<?php echo (!empty($success) ? '' : (isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : '')); ?>">
                                </div>
                                <span class="field-hint">15 letters max. <span id="lastNameCounter">0/15</span></span>
                            </div>

                            <div class="name-part">
                                <label for="suffix">Suffix <span class="optional">(Optional)</span></label>
                                <div class="input-wrapper">
                                    <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                    </svg>
                                    <input type="text" id="suffix" name="suffix" placeholder="Jr" maxlength="3" value="<?php echo (!empty($success) ? '' : (isset($_POST['suffix']) ? htmlspecialchars($_POST['suffix']) : '')); ?>" style="text-transform: uppercase;">
                                </div>
                                <span class="field-hint">3 letters max. <span id="suffixCounter">0/3</span></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gender">Sex / Gender</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <select id="gender" name="gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo (!empty($success) ? '' : ((isset($_POST['gender']) && $_POST['gender'] === 'Male') ? 'selected' : '')); ?>>Male</option>
                                    <option value="Female" <?php echo (!empty($success) ? '' : ((isset($_POST['gender']) && $_POST['gender'] === 'Female') ? 'selected' : '')); ?>>Female</option>
                                    <option value="Other" <?php echo (!empty($success) ? '' : ((isset($_POST['gender']) && $_POST['gender'] === 'Other') ? 'selected' : '')); ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth (DOB)</label>
                            <div class="input-wrapper dob-picker" id="dobPicker">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <input type="hidden" id="date_of_birth" name="date_of_birth" value="<?php echo (!empty($success) ? '' : (isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : '')); ?>">
                                <button type="button" class="dob-picker-button placeholder" id="dobPickerButton" aria-haspopup="dialog" aria-expanded="false">Select birthday</button>
                                <svg class="dob-calendar-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <div class="dob-calendar" id="dobCalendar" role="dialog" aria-label="Choose date of birth">
                                    <div class="dob-calendar-controls">
                                        <button type="button" id="dobPreviousMonth" aria-label="Previous month">&lsaquo;</button>
                                        <select id="dobMonth" aria-label="Birth month">
                                            <option value="0">January</option><option value="1">February</option><option value="2">March</option>
                                            <option value="3">April</option><option value="4">May</option><option value="5">June</option>
                                            <option value="6">July</option><option value="7">August</option><option value="8">September</option>
                                            <option value="9">October</option><option value="10">November</option><option value="11">December</option>
                                        </select>
                                        <input type="number" id="dobYear" min="1900" max="<?php echo date('Y'); ?>" inputmode="numeric" aria-label="Birth year">
                                        <button type="button" id="dobNextMonth" aria-label="Next month">&rsaquo;</button>
                                    </div>
                                    <div class="dob-weekdays" aria-hidden="true">
                                        <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                                    </div>
                                    <div class="dob-days" id="dobDays"></div>
                                    <div class="dob-calendar-footer">
                                        <button type="button" class="dob-clear" id="dobClear">Clear date</button>
                                    </div>
                                </div>
                            </div>
                            <span class="field-hint">Future dates are not allowed.</span>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="age">Age <span class="age-display" id="ageDisplay" style="display: none;">0 years old</span></label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                </svg>
                                <input type="text" id="age" name="age" placeholder="Auto-computed" readonly style="background: #e9ecef; cursor: not-allowed;">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="civil_status">Civil Status <span class="optional">(Optional)</span></label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                </svg>
                                <select id="civil_status" name="civil_status">
                                    <option value="">Select Civil Status</option>
                                    <option value="Single" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Single') ? 'selected' : '')); ?>>Single</option>
                                    <option value="Married" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Married') ? 'selected' : '')); ?>>Married</option>
                                    <option value="Divorced" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Divorced') ? 'selected' : '')); ?>>Divorced</option>
                                    <option value="Widowed" <?php echo (!empty($success) ? '' : ((isset($_POST['civil_status']) && $_POST['civil_status'] === 'Widowed') ? 'selected' : '')); ?>>Widowed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Contact Information -->
                <div class="form-section">
                    <div class="form-section-title">Contact Information</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="phone">Mobile Number</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                                <input type="tel" id="phone" name="phone" placeholder="e.g. 09171234567" required pattern="[0-9+\s\-]{10,15}" value="<?php echo (!empty($success) ? '' : (isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : '')); ?>">
                            </div>
                            <span class="field-hint">For SMS or call about your appointment and lab updates.</span>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address <span class="optional">(Optional)</span></label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                <input type="email" id="email" name="email" placeholder="you@example.com" value="<?php echo (!empty($success) ? '' : (isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '')); ?>">
                            </div>
                            <span class="field-hint">Optional. If left blank, your verification code will be sent by SMS.</span>
                        </div>
                    </div>

                    <?php
                        $postedEmail = trim((string) ($_POST['email'] ?? ''));
                        $selectedVerificationChannel = strtolower((string) ($_POST['verification_channel'] ?? ($postedEmail === '' ? 'sms' : 'email')));
                        if ($postedEmail === '') {
                            $selectedVerificationChannel = 'sms';
                        }
                    ?>
                    <div class="verification-choice">
                        <strong>Where should we send your verification code?</strong>
                        <p>Choose one. If you do not add an email, SMS will be used automatically.</p>
                        <div class="verification-options">
                            <label class="verification-option" id="emailVerificationOption">
                                <input
                                    type="radio"
                                    name="verification_channel"
                                    value="email"
                                    <?php echo $selectedVerificationChannel !== 'sms' ? 'checked' : ''; ?>
                                >
                                <span>
                                    <strong>Email</strong>
                                    Send the code to the email address above, if provided.
                                </span>
                            </label>
                            <label class="verification-option">
                                <input
                                    type="radio"
                                    name="verification_channel"
                                    value="sms"
                                    <?php echo $selectedVerificationChannel === 'sms' ? 'checked' : ''; ?>
                                >
                                <span>
                                    <strong>SMS</strong>
                                    Text the code to the mobile number above.
                                </span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <input type="text" id="address" name="address" placeholder="Street address (optional)" value="<?php echo (!empty($success) ? '' : (isset($_POST['address']) ? htmlspecialchars($_POST['address']) : '')); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="barangay">Barangay</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                </svg>
                                <input type="text" id="barangay" name="barangay" placeholder="Enter barangay" required value="<?php echo (!empty($success) ? '' : (isset($_POST['barangay']) ? htmlspecialchars($_POST['barangay']) : '')); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="city">City</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                                </svg>
                                <input type="text" id="city" name="city" placeholder="Enter city" required value="<?php echo (!empty($success) ? '' : (isset($_POST['city']) ? htmlspecialchars($_POST['city']) : '')); ?>">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Information -->
                <div class="form-section">
                    <div class="form-section-title">Account Information</div>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <input type="text" id="username" name="username" placeholder="Choose a username" required autocomplete="username" value="<?php echo (!empty($success) ? '' : (isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '')); ?>">
                        </div>
                        <span class="field-hint">Save this — you will enter it every time you log in to the patient portal.</span>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <input type="password" id="password" name="password" placeholder="Create a secure password" required>
                                <svg class="password-toggle" id="passwordToggle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                            <div class="password-requirements">
                                <div class="password-requirements-title">Password Requirements:</div>
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
                            <label for="confirm_password">Confirm Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                                <svg class="password-toggle" id="confirmPasswordToggle" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Emergency Contact -->
                <div class="form-section">
                    <div class="form-section-title">Emergency Contact <span style="color: #0077b6; font-size: 0.85rem; font-weight: 600;">(Recommended for clinic safety)</span></div>
                    <p class="field-hint" style="margin: -8px 0 14px;">Helps our staff reach a family member if you need urgent assistance during a visit.</p>
                    
                    <div class="form-group">
                        <label for="emergency_contact_name">Name</label>
                        <div class="input-wrapper">
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            <input type="text" id="emergency_contact_name" name="emergency_contact_name" placeholder="Emergency contact full name" value="<?php echo (!empty($success) ? '' : (isset($_POST['emergency_contact_name']) ? htmlspecialchars($_POST['emergency_contact_name']) : '')); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emergency_contact_relationship">Relationship</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                </svg>
                                <input type="text" id="emergency_contact_relationship" name="emergency_contact_relationship" placeholder="e.g., Spouse, Parent, Sibling" value="<?php echo (!empty($success) ? '' : (isset($_POST['emergency_contact_relationship']) ? htmlspecialchars($_POST['emergency_contact_relationship']) : '')); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="emergency_contact_number">Contact Number</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                                <input type="tel" id="emergency_contact_number" name="emergency_contact_number" placeholder="Emergency contact number" value="<?php echo (!empty($success) ? '' : (isset($_POST['emergency_contact_number']) ? htmlspecialchars($_POST['emergency_contact_number']) : '')); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="terms-box">
                    <input type="checkbox" id="agree_clinic_terms" name="agree_clinic_terms" value="1" required <?php echo (!empty($_POST['agree_clinic_terms']) && empty($success)) ? 'checked' : ''; ?>>
                    <label for="agree_clinic_terms">
                        I agree that Globalife Medical Laboratory &amp; Polyclinic may collect and use my information for patient records, appointments, laboratory services, and contact related to my care. I confirm the details above are accurate.
                    </label>
                </div>
                
                <button type="submit" class="login-btn-form" id="submitRegisterBtn">
                    <span>Continue and Verify</span>
                </button>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="index.php#patient-login">Go to Login</a> | <a href="forgot_password.php">Forgot password?</a>
            </div>
            
            <div class="back-to-home">
                <a href="index.php">
                    <svg class="back-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to Home
                </a>
            </div>
        </div>
    </div>

    <!-- Password error popup -->
    <div class="popup-overlay" id="passwordPopup" role="dialog" aria-modal="true" aria-labelledby="passwordPopupTitle">
        <div class="popup-box error">
            <div class="popup-icon error">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h3 class="popup-title" id="passwordPopupTitle">Password Not Correct</h3>
            <p class="popup-message" id="passwordPopupMessage"><?php echo htmlspecialchars($passwordPopupMessage ?: 'Password and confirm password do not match, or your password does not meet the requirements. Please check the list below and try again.'); ?></p>
            <div class="popup-actions">
                <button type="button" class="popup-btn popup-btn-danger" id="passwordPopupClose">OK</button>
            </div>
        </div>
    </div>

    <!-- Success popup -->
    <div class="popup-overlay<?php echo $showSuccessPopup ? ' active' : ''; ?>" id="successPopup" role="dialog" aria-modal="true" aria-labelledby="successPopupTitle" data-no-dismiss="true">
        <div class="popup-box success success-popup">
            <div class="success-popup-header">
                <img src="globalife.png" alt="" class="success-popup-logo">
                <div class="popup-icon success pulse">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <h3 class="popup-title" id="successPopupTitle" style="margin-bottom: 6px;">You have successfully created an account</h3>
                <p class="popup-message" style="margin-bottom: 0; text-align: center;">
                    <?php if ($registeredDisplayName !== ''): ?>
                        Welcome, <strong><?php echo htmlspecialchars($registeredDisplayName); ?></strong>. Your patient account is ready to use.
                    <?php else: ?>
                        Your patient portal account is ready to use.
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($registeredUsername !== ''): ?>
            <div class="username-save-box">
                <p class="username-save-label">Save your username</p>
                <div class="username-save-row">
                    <span class="username-save-value" id="registeredUsernameDisplay"><?php echo htmlspecialchars($registeredUsername); ?></span>
                    <button type="button" class="copy-username-btn" id="copyUsernameBtn">Copy</button>
                </div>
            </div>
            <?php endif; ?>

            <p style="font-weight: 700; color: #073b4c; font-size: 0.95rem; margin: 0 0 10px; text-align: center;">Go back to sign in and use your username and password.</p>
            <ol class="success-next-steps">
                <li>
                    <span class="success-step-num">1</span>
                    <span>Use the username above and the password you created.</span>
                </li>
                <li>
                    <span class="success-step-num">2</span>
                    <span>Select <strong>Back to Sign In</strong> to log in to your patient dashboard.</span>
                </li>
                <li>
                    <span class="success-step-num">3</span>
                    <span>After logging in, you can book a clinic or laboratory appointment.</span>
                </li>
            </ol>

            <p class="success-clinic-note">
                Bring a valid ID on your visit. Payment for services is done at the clinic unless staff advises otherwise.
            </p>

            <div class="popup-actions">
                <a href="index.php?registered=1#patient-login" class="popup-btn popup-btn-primary" id="successLoginBtn">Back to Sign In</a>
            </div>
        </div>
    </div>

    <script>
        function showPopup(id) {
            const overlay = document.getElementById(id);
            if (overlay) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function hidePopup(id) {
            const overlay = document.getElementById(id);
            if (overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Clear form if registration was successful
            <?php if ($showPasswordPopup): ?>
            showPopup('passwordPopup');
            <?php endif; ?>
            <?php if ($showSuccessPopup): ?>
            const form = document.getElementById('registerForm');
            if (form) {
                form.reset();
                const ageDisplay = document.getElementById('ageDisplay');
                if (ageDisplay) {
                    ageDisplay.style.display = 'none';
                }
                const ageInput = document.getElementById('age');
                if (ageInput) {
                    ageInput.value = '';
                }
            }
            showPopup('successPopup');
            const successLoginBtn = document.getElementById('successLoginBtn');
            if (successLoginBtn) {
                window.setTimeout(function () {
                    successLoginBtn.focus();
                }, 350);
            }
            <?php endif; ?>

            const copyUsernameBtn = document.getElementById('copyUsernameBtn');
            const registeredUsernameDisplay = document.getElementById('registeredUsernameDisplay');
            if (copyUsernameBtn && registeredUsernameDisplay) {
                copyUsernameBtn.addEventListener('click', function () {
                    const text = registeredUsernameDisplay.textContent.trim();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function () {
                            copyUsernameBtn.textContent = 'Copied!';
                            window.setTimeout(function () {
                                copyUsernameBtn.textContent = 'Copy';
                            }, 2000);
                        });
                    } else {
                        window.prompt('Copy your username:', text);
                    }
                });
            }

            document.getElementById('passwordPopupClose').addEventListener('click', function() {
                hidePopup('passwordPopup');
            });
            document.getElementById('passwordPopup').addEventListener('click', function(e) {
                if (e.target === this) {
                    hidePopup('passwordPopup');
                }
            });

            const successPopup = document.getElementById('successPopup');
            if (successPopup) {
                successPopup.addEventListener('click', function (e) {
                    if (e.target === successPopup && successPopup.getAttribute('data-no-dismiss') === 'true') {
                        e.stopPropagation();
                    }
                });
            }

            const passwordInput = document.getElementById('password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const passwordToggle = document.getElementById('passwordToggle');
            const confirmPasswordToggle = document.getElementById('confirmPasswordToggle');
            const firstNameInput = document.getElementById('first_name');
            const middleNameInput = document.getElementById('middle_name');
            const lastNameInput = document.getElementById('last_name');
            const suffixInput = document.getElementById('suffix');
            const firstNameCounter = document.getElementById('firstNameCounter');
            const middleNameCounter = document.getElementById('middleNameCounter');
            const lastNameCounter = document.getElementById('lastNameCounter');
            const suffixCounter = document.getElementById('suffixCounter');
            const emailInput = document.getElementById('email');
            const emailVerificationOption = document.getElementById('emailVerificationOption');
            const emailVerificationRadio = document.querySelector('input[name="verification_channel"][value="email"]');
            const smsVerificationRadio = document.querySelector('input[name="verification_channel"][value="sms"]');
            const dateOfBirthInput = document.getElementById('date_of_birth');
            const dobPicker = document.getElementById('dobPicker');
            const dobPickerButton = document.getElementById('dobPickerButton');
            const dobCalendar = document.getElementById('dobCalendar');
            const dobMonth = document.getElementById('dobMonth');
            const dobYear = document.getElementById('dobYear');
            const dobDays = document.getElementById('dobDays');
            const dobPreviousMonth = document.getElementById('dobPreviousMonth');
            const dobNextMonth = document.getElementById('dobNextMonth');
            const dobClear = document.getElementById('dobClear');
            const ageInput = document.getElementById('age');
            const ageDisplay = document.getElementById('ageDisplay');
            const todayDate = new Date();
            todayDate.setHours(0, 0, 0, 0);
            const todayYmd = [
                todayDate.getFullYear(),
                String(todayDate.getMonth() + 1).padStart(2, '0'),
                String(todayDate.getDate()).padStart(2, '0')
            ].join('-');
            let selectedBirthDate = null;
            let calendarMonth = todayDate.getMonth();
            let calendarYear = todayDate.getFullYear();

                        function countLetters(value) {
                return (value.match(/\p{L}/gu) || []).length;
            }

            function updateNameField(input, counter, maxLetters, options = {}) {
                if (!input) return;
                const allowSeparators = !!options.allowSeparators;
                const forceUppercase = !!options.forceUppercase;
                let value = input.value || '';

                value = allowSeparators
                    ? value.replace(/[^\p{L}\s'-]/gu, '')
                    : value.replace(/[^\p{L}]/gu, '');

                if (allowSeparators) {
                    value = value.replace(/\s+/g, ' ').trimStart();
                }

                value = forceUppercase ? value.toUpperCase() : value;

                if (!allowSeparators) {
                    value = value.slice(0, maxLetters);
                } else if (maxLetters > 0) {
                    let letterTotal = 0;
                    let clipped = '';
                    for (const char of value) {
                        if (/\p{L}/u.test(char)) {
                            letterTotal++;
                            if (letterTotal > maxLetters) {
                                break;
                            }
                        }
                        clipped += char;
                    }
                    value = clipped;
                }

                if (input.value !== value) {
                    input.value = value;
                }

                if (counter) {
                    counter.textContent = countLetters(value) + '/' + maxLetters;
                }
            }

            const nameFieldConfigs = [
                [firstNameInput, firstNameCounter, 15, { allowSeparators: true, forceUppercase: false }],
                [middleNameInput, middleNameCounter, 1, { allowSeparators: false, forceUppercase: true }],
                [lastNameInput, lastNameCounter, 15, { allowSeparators: true, forceUppercase: false }],
                [suffixInput, suffixCounter, 3, { allowSeparators: false, forceUppercase: true }]
            ];

            nameFieldConfigs.forEach(([input, counter, maxLetters, options]) => {
                if (!input) return;
                input.addEventListener('input', () => updateNameField(input, counter, maxLetters, options));
                input.addEventListener('blur', () => updateNameField(input, counter, maxLetters, options));
                updateNameField(input, counter, maxLetters, options);
            });

            function updateVerificationChoices() {
                if (!emailInput || !emailVerificationRadio || !smsVerificationRadio) return;
                const hasEmail = emailInput.value.trim() !== '';
                emailVerificationRadio.disabled = !hasEmail;
                if (emailVerificationOption) {
                    emailVerificationOption.classList.toggle('is-disabled', !hasEmail);
                }
                if (!hasEmail) {
                    smsVerificationRadio.checked = true;
                }
            }

            if (emailInput) {
                emailInput.addEventListener('input', updateVerificationChoices);
                updateVerificationChoices();
            }

            function parseYmd(value) {
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

            function dateToYmd(date) {
                return [
                    date.getFullYear(),
                    String(date.getMonth() + 1).padStart(2, '0'),
                    String(date.getDate()).padStart(2, '0')
                ].join('-');
            }

            function birthDateLabel(date) {
                return date.toLocaleDateString('en-US', {
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric'
                });
            }

            function updateBirthDateButton() {
                if (!dobPickerButton) return;
                if (selectedBirthDate) {
                    dobPickerButton.textContent = birthDateLabel(selectedBirthDate);
                    dobPickerButton.classList.remove('placeholder');
                } else {
                    dobPickerButton.textContent = 'Select birthday';
                    dobPickerButton.classList.add('placeholder');
                }
            }

            function renderBirthCalendar() {
                if (!dobDays || !dobMonth || !dobYear) return;
                calendarYear = Math.min(todayDate.getFullYear(), Math.max(1900, Number(calendarYear) || todayDate.getFullYear()));
                if (calendarYear === todayDate.getFullYear() && calendarMonth > todayDate.getMonth()) {
                    calendarMonth = todayDate.getMonth();
                }
                dobMonth.value = String(calendarMonth);
                dobYear.value = String(calendarYear);
                dobDays.innerHTML = '';

                const firstWeekday = new Date(calendarYear, calendarMonth, 1).getDay();
                const daysInMonth = new Date(calendarYear, calendarMonth + 1, 0).getDate();
                for (let blank = 0; blank < firstWeekday; blank++) {
                    const empty = document.createElement('span');
                    empty.className = 'dob-day-empty';
                    dobDays.appendChild(empty);
                }

                for (let day = 1; day <= daysInMonth; day++) {
                    const date = new Date(calendarYear, calendarMonth, day);
                    date.setHours(0, 0, 0, 0);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'dob-day';
                    button.textContent = String(day);
                    button.disabled = date > todayDate;
                    if (dateToYmd(date) === todayYmd) button.classList.add('today');
                    if (selectedBirthDate && dateToYmd(date) === dateToYmd(selectedBirthDate)) {
                        button.classList.add('selected');
                    }
                    button.addEventListener('click', function () {
                        selectedBirthDate = date;
                        dateOfBirthInput.value = dateToYmd(date);
                        dateOfBirthInput.setCustomValidity('');
                        updateBirthDateButton();
                        calculateAge();
                        closeBirthCalendar();
                    });
                    dobDays.appendChild(button);
                }

                if (dobPreviousMonth) {
                    dobPreviousMonth.disabled = calendarYear === 1900 && calendarMonth === 0;
                }
                if (dobNextMonth) {
                    dobNextMonth.disabled = calendarYear === todayDate.getFullYear()
                        && calendarMonth === todayDate.getMonth();
                }
            }

            function openBirthCalendar() {
                if (!dobCalendar || !dobPickerButton) return;
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

            selectedBirthDate = parseYmd(dateOfBirthInput.value);
            if (selectedBirthDate) {
                calendarMonth = selectedBirthDate.getMonth();
                calendarYear = selectedBirthDate.getFullYear();
            }
            updateBirthDateButton();

            if (dobPickerButton) {
                dobPickerButton.addEventListener('click', function () {
                    if (dobCalendar.classList.contains('open')) {
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
                    calendarMonth--;
                    if (calendarMonth < 0) {
                        calendarMonth = 11;
                        calendarYear--;
                    }
                    renderBirthCalendar();
                });
            }
            if (dobNextMonth) {
                dobNextMonth.addEventListener('click', function () {
                    calendarMonth++;
                    if (calendarMonth > 11) {
                        calendarMonth = 0;
                        calendarYear++;
                    }
                    renderBirthCalendar();
                });
            }
            if (dobClear) {
                dobClear.addEventListener('click', function () {
                    selectedBirthDate = null;
                    dateOfBirthInput.value = '';
                    updateBirthDateButton();
                    calculateAge();
                    closeBirthCalendar();
                });
            }
            document.addEventListener('click', function (event) {
                if (dobPicker && !dobPicker.contains(event.target)) {
                    closeBirthCalendar();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeBirthCalendar();
            });
            
            // Calculate age from date of birth
            function calculateAge() {
                if (dateOfBirthInput.value) {
                    if (dateOfBirthInput.value > todayYmd) {
                        dateOfBirthInput.setCustomValidity('Date of birth cannot be a future date.');
                        ageInput.value = '';
                        ageDisplay.style.display = 'none';
                        return;
                    }
                    dateOfBirthInput.setCustomValidity('');
                    const dob = new Date(dateOfBirthInput.value);
                    const today = new Date();
                    let age = today.getFullYear() - dob.getFullYear();
                    const monthDiff = today.getMonth() - dob.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                        age--;
                    }
                    ageInput.value = age;
                    ageDisplay.textContent = age + ' years old';
                    ageDisplay.style.display = 'inline-block';
                } else {
                    dateOfBirthInput.setCustomValidity('');
                    ageInput.value = '';
                    ageDisplay.style.display = 'none';
                }
            }
            
            dateOfBirthInput.addEventListener('change', calculateAge);
            dateOfBirthInput.addEventListener('input', calculateAge);
            
            // Password validation and requirements checker
            function validatePasswordRequirements(password) {
                const requirements = {
                    length: password.length >= 8,
                    uppercase: /[A-Z]/.test(password),
                    lowercase: /[a-z]/.test(password),
                    number: /[0-9]/.test(password),
                    special: /[^A-Za-z0-9]/.test(password)
                };
                
                return requirements;
            }
            
            function updatePasswordRequirements(password) {
                const reqs = validatePasswordRequirements(password);
                
                // Update each requirement indicator
                updateRequirement('req-length', reqs.length);
                updateRequirement('req-uppercase', reqs.uppercase);
                updateRequirement('req-lowercase', reqs.lowercase);
                updateRequirement('req-number', reqs.number);
                updateRequirement('req-special', reqs.special);
                
                // Calculate password strength
                const validCount = Object.values(reqs).filter(v => v).length;
                const strengthBar = document.getElementById('passwordStrengthBar');
                
                if (password.length === 0) {
                    strengthBar.className = 'password-strength-bar';
                    strengthBar.style.width = '0%';
                } else if (validCount <= 2) {
                    strengthBar.className = 'password-strength-bar weak';
                } else if (validCount <= 4) {
                    strengthBar.className = 'password-strength-bar medium';
                } else {
                    strengthBar.className = 'password-strength-bar strong';
                }
            }
            
            function updateRequirement(id, isValid) {
                const req = document.getElementById(id);
                const icon = req.querySelector('.requirement-icon');
                
                if (isValid) {
                    req.classList.remove('invalid');
                    req.classList.add('valid');
                    icon.classList.remove('invalid');
                    icon.classList.add('valid');
                    icon.innerHTML = `
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    `;
                } else {
                    req.classList.remove('valid');
                    req.classList.add('invalid');
                    icon.classList.remove('valid');
                    icon.classList.add('invalid');
                    icon.innerHTML = `
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    `;
                }
            }
            
            // Real-time password validation
            passwordInput.addEventListener('input', function() {
                updatePasswordRequirements(passwordInput.value);
            });
            
            // Check password match in real-time
            confirmPasswordInput.addEventListener('input', function() {
                const passwordMatch = passwordInput.value === confirmPasswordInput.value;
                if (confirmPasswordInput.value.length > 0) {
                    if (passwordMatch) {
                        confirmPasswordInput.style.borderColor = '#28a745';
                    } else {
                        confirmPasswordInput.style.borderColor = '#dc3545';
                    }
                } else {
                    confirmPasswordInput.style.borderColor = '#e0e0e0';
                }
            });
            
            // Eye open icon (show password)
            const eyeOpenIcon = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            `;
            
            // Eye closed icon (hide password)
            const eyeClosedIcon = `
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            `;
            
            // Password toggle
            passwordToggle.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    passwordToggle.innerHTML = eyeClosedIcon;
                } else {
                    passwordInput.type = 'password';
                    passwordToggle.innerHTML = eyeOpenIcon;
                }
            });
            
            // Confirm password toggle
            confirmPasswordToggle.addEventListener('click', function() {
                if (confirmPasswordInput.type === 'password') {
                    confirmPasswordInput.type = 'text';
                    confirmPasswordToggle.innerHTML = eyeClosedIcon;
                } else {
                    confirmPasswordInput.type = 'password';
                    confirmPasswordToggle.innerHTML = eyeOpenIcon;
                }
            });
            
            // Form validation
            const form = document.getElementById('registerForm');
            form.addEventListener('submit', function(e) {
                if (!dateOfBirthInput.value) {
                    e.preventDefault();
                    dateOfBirthInput.setCustomValidity('Please select your date of birth.');
                    openBirthCalendar();
                    dobPickerButton.focus();
                    return false;
                }
                const reqs = validatePasswordRequirements(passwordInput.value);
                const allValid = Object.values(reqs).every(v => v === true);
                
                if (!allValid || passwordInput.value !== confirmPasswordInput.value) {
                    e.preventDefault();
                    const msgEl = document.getElementById('passwordPopupMessage');
                    if (msgEl) {
                        if (passwordInput.value !== confirmPasswordInput.value) {
                            msgEl.textContent = 'Password and confirm password do not match. Please type them again.';
                        } else {
                            msgEl.textContent = 'Your password does not meet all requirements. Check the green checkmarks below the password field.';
                        }
                    }
                    showPopup('passwordPopup');
                    return false;
                }

                const terms = document.getElementById('agree_clinic_terms');
                if (terms && !terms.checked) {
                    e.preventDefault();
                    terms.focus();
                    alert('Please agree to the clinic privacy notice before creating your account.');
                    return false;
                }
            });
        });
    </script>
</body>
</html>


<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sms.php';

function pw_reset_mask_email(string $email): string {
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return $email;
    }
    [$name, $domain] = explode('@', $email, 2);
    $visible = substr($name, 0, 2);
    return $visible . str_repeat('*', max(1, strlen($name) - 2)) . '@' . $domain;
}

function pw_reset_mask_phone(string $phone): string {
    return clinic_sms_mask_phone($phone);
}

function pw_reset_phone_candidates(string $phone): array {
    $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
    if ($digits === '') {
        return ['', '', ''];
    }

    $candidates = [$digits];
    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        $candidates[] = '63' . substr($digits, 1);
        $candidates[] = substr($digits, 1);
    } elseif (str_starts_with($digits, '639') && strlen($digits) === 12) {
        $candidates[] = '0' . substr($digits, 2);
        $candidates[] = substr($digits, 2);
    } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
        $candidates[] = '0' . $digits;
        $candidates[] = '63' . $digits;
    }

    $candidates = array_values(array_unique($candidates));
    while (count($candidates) < 3) {
        $candidates[] = '';
    }
    return array_slice($candidates, 0, 3);
}

function pw_reset_validate_password(string $password): array {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include an uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include a lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must include a number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must include a special character.';
    }
    return $errors;
}

function pw_reset_issue_code(string $contact, string $channel = 'email'): array {
    $channel = strtolower(trim($channel));
    if (!in_array($channel, ['email', 'sms'], true)) {
        return ['ok' => false, 'error' => 'Choose email or mobile number for verification.'];
    }

    $contact = trim($contact);
    $email = '';
    $phone = '';
    $conn = getDBConnection();

    if ($channel === 'email') {
        $email = strtolower($contact);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $conn->close();
            return ['ok' => false, 'error' => 'Please enter a valid email address.'];
        }
        $stmt = $conn->prepare("SELECT id, email, phone, first_name, middle_name, last_name, suffix FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param('s', $email);
    } else {
        $phone = clinic_sms_normalize_phone($contact) ?? '';
        if ($phone === '') {
            $conn->close();
            return ['ok' => false, 'error' => 'Please enter a valid registered mobile number.'];
        }
        [$phoneOne, $phoneTwo, $phoneThree] = pw_reset_phone_candidates($phone);
        $phoneExpression = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
        $stmt = $conn->prepare("SELECT id, email, phone, first_name, middle_name, last_name, suffix FROM users WHERE {$phoneExpression} IN (?, ?, ?) LIMIT 1");
        $stmt->bind_param('sss', $phoneOne, $phoneTwo, $phoneThree);
    }
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $conn->close();
        return ['ok' => false, 'error' => $channel === 'sms'
            ? 'No account was found with that mobile number.'
            : 'No account was found with that email address.'];
    }

    $userId = (int) $user['id'];
    $recent = $conn->prepare(
        'SELECT id FROM password_reset_codes
         WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
         ORDER BY id DESC LIMIT 1'
    );
    $recent->bind_param('i', $userId);
    $recent->execute();
    $recentRow = $recent->get_result()->fetch_assoc();
    $recent->close();
    if ($recentRow) {
        $conn->close();
        return ['ok' => false, 'error' => 'Please wait one minute before requesting another code.'];
    }

    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);

    $cleanup = $conn->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $cleanup->bind_param('i', $userId);
    $cleanup->execute();
    $cleanup->close();

    $insert = $conn->prepare(
        'INSERT INTO password_reset_codes (user_id, email, code_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $registeredEmail = strtolower(trim((string) ($user['email'] ?? '')));
    $registeredPhone = trim((string) ($user['phone'] ?? ''));
    if ($channel === 'email') {
        $contactKey = $registeredEmail;
    } else {
        $contactKey = clinic_sms_normalize_phone($registeredPhone) ?? $phone;
    }
    $insert->bind_param('iss', $userId, $contactKey, $hash);
    $ok = $insert->execute();
    $insert->close();
    $conn->close();

    if (!$ok) {
        return ['ok' => false, 'error' => 'Could not create a reset code. Please try again.'];
    }

    $sent = $channel === 'sms'
        ? clinic_send_sms_code($registeredPhone, $code, 'password_reset')
        : clinic_send_otp_email(
            $registeredEmail,
            clinic_name_display_from_row($user, ''),
            $code,
            'password_reset'
        );
    if (!$sent['ok']) {
        $conn = getDBConnection();
        $invalidate = $conn->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $invalidate->bind_param('i', $userId);
        $invalidate->execute();
        $invalidate->close();
        $conn->close();

        return ['ok' => false, 'error' => $sent['error']];
    }

    return [
        'ok' => true,
        'channel' => $channel,
        'contact_key' => $contactKey,
        'email' => $registeredEmail,
        'phone' => $registeredPhone,
    ];
}

function pw_reset_verify_code(string $email, string $code): array {
    $email = trim(strtolower($email));
    $code = preg_replace('/\D+/', '', trim($code));
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['ok' => false, 'error' => 'Enter the 6-digit reset code.'];
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT id, user_id, code_hash,
                CASE
                    WHEN DATE_ADD(created_at, INTERVAL 10 MINUTE) < NOW() THEN 1
                    ELSE 0
                END AS is_expired
         FROM password_reset_codes
         WHERE email = ? AND used_at IS NULL
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $conn->close();
        return ['ok' => false, 'error' => 'No active reset code was found. Request a new code.'];
    }

    if ((int) $row['is_expired'] === 1) {
        $conn->close();
        return ['ok' => false, 'error' => 'This reset code has expired. Request a new code.'];
    }

    if (!password_verify($code, $row['code_hash'])) {
        $conn->close();
        return ['ok' => false, 'error' => 'The reset code is incorrect. Check the latest email and try again.'];
    }

    $resetId = (int) $row['id'];
    $used = $conn->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?');
    $used->bind_param('i', $resetId);
    $used->execute();
    $used->close();
    $conn->close();

    return ['ok' => true, 'user_id' => (int) $row['user_id']];
}

function pw_reset_update_password(int $userId, string $password): bool {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $conn = getDBConnection();
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}
?>

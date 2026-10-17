<?php

/**
 * Return the digit-only forms that may represent the same Philippine mobile number.
 * Existing records may use local (09...), country-code (639...), or subscriber (9...)
 * format, so duplicate checks must compare all of them.
 */
function clinic_registration_phone_digits(string $phone): array
{
    $digits = preg_replace('/\D+/', '', trim($phone));
    if ($digits === null || $digits === '') {
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
/**
 * Find an existing account matching username, email, or mobile number.
 * The query is intentionally case-insensitive for username/email and strips
 * common phone formatting characters before comparing phone digits.
 */
function clinic_find_duplicate_user(mysqli $conn, string $username, string $email, string $phone): ?array
{
    $username = trim($username);
    $email = strtolower(trim($email));
    $phoneDigits = preg_replace('/\D+/', '', trim($phone)) ?? '';
    [$phoneOne, $phoneTwo, $phoneThree] = clinic_registration_phone_digits($phone);

    $phoneExpression = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
    $sql = "SELECT id, username, email, phone, role
            FROM users
            WHERE LOWER(username) = LOWER(?)
               OR (? <> '' AND LOWER(COALESCE(email, '')) = LOWER(?))
               OR (? <> '' AND {$phoneExpression} IN (?, ?, ?))
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'sssssss',
        $username,
        $email,
        $email,
        $phoneDigits,
        $phoneOne,
        $phoneTwo,
        $phoneThree
    );
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $duplicate;
}

function clinic_duplicate_user_message(array $duplicate, string $username, string $email, string $phone): string
{
    if (strcasecmp(trim((string) ($duplicate['username'] ?? '')), trim($username)) === 0) {
        return 'Username already exists. Please choose a different username.';
    }

    if ($email !== '' && strcasecmp(trim((string) ($duplicate['email'] ?? '')), trim($email)) === 0) {
        return 'Email already registered. Please use a different email address.';
    }

    $storedPhone = clinic_registration_phone_digits((string) ($duplicate['phone'] ?? ''));
    $submittedPhone = clinic_registration_phone_digits($phone);
    if ($phone !== '' && array_intersect($storedPhone, array_filter($submittedPhone, static fn (string $value): bool => $value !== '')) !== []) {
        return 'Mobile number already registered. Please use a different number.';
    }

    return 'That username, email, or mobile number is already registered. Please use different details.';
}


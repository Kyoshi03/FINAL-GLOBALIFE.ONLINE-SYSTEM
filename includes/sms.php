<?php

function clinic_sms_config(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'enabled' => false,
        'base_url' => 'https://skysms.skyio.site/api/v1',
        'api_key' => '',
        'use_subscription' => false,
    ];

    $path = __DIR__ . '/../config/sms.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
        }
    }

    $environmentValues = [
        'base_url' => getenv('SKYSMS_BASE_URL'),
        'api_key' => getenv('SKYSMS_API_KEY'),
    ];
    foreach ($environmentValues as $key => $value) {
        if ($value !== false && trim((string) $value) !== '') {
            $config[$key] = trim((string) $value);
        }
    }

    $enabled = getenv('SKYSMS_ENABLED');
    if ($enabled !== false && trim((string) $enabled) !== '') {
        $config['enabled'] = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    $subscription = getenv('SKYSMS_USE_SUBSCRIPTION');
    if ($subscription !== false && trim((string) $subscription) !== '') {
        $config['use_subscription'] = filter_var($subscription, FILTER_VALIDATE_BOOLEAN);
    }

    $config['base_url'] = rtrim(trim((string) $config['base_url']), '/');
    $config['api_key'] = trim((string) $config['api_key']);
    return $config;
}

function clinic_sms_ready(): bool {
    $config = clinic_sms_config();
    return !empty($config['enabled'])
        && $config['base_url'] !== ''
        && $config['api_key'] !== ''
        && strpos($config['api_key'], 'YOUR_') === false;
}

function clinic_sms_auth_check(): array {
    if (!clinic_sms_ready()) {
        return ['ok' => false, 'error' => 'SMS settings are incomplete.'];
    }

    $config = clinic_sms_config();
    $url = $config['base_url'] . '/sms/messages?per_page=1';
    $statusCode = 0;
    $responseBody = '';

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $config['api_key'],
                'Accept: application/json',
                'User-Agent: GlobalifeClinic/1.0',
            ],
        ]);
        $responseBody = (string) curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($responseBody === '' && $curlError !== '') {
            return ['ok' => false, 'error' => 'Could not connect to the SMS service.'];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'X-API-Key: ' . $config['api_key'],
                    'Accept: application/json',
                    'User-Agent: GlobalifeClinic/1.0',
                ]),
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $responseBody = $response === false ? '' : $response;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }
    }

    if ($statusCode >= 200 && $statusCode < 300) {
        return ['ok' => true];
    }
    if ($statusCode === 401 || $statusCode === 403) {
        return ['ok' => false, 'error' => 'SkySMS rejected the API key.'];
    }

    return ['ok' => false, 'error' => 'SkySMS authentication check failed with HTTP ' . $statusCode . '.'];
}

function clinic_sms_normalize_phone(string $phone): ?string {
    $digits = preg_replace('/\D+/', '', trim($phone));
    if ($digits === null || $digits === '') {
        return null;
    }

    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        return '+63' . substr($digits, 1);
    }
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        return '+' . $digits;
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        return '+63' . $digits;
    }

    return null;
}

function clinic_sms_mask_phone(string $phone): string {
    $normalized = clinic_sms_normalize_phone($phone);
    if ($normalized === null) {
        return $phone;
    }

    return substr($normalized, 0, 4)
        . str_repeat('*', max(4, strlen($normalized) - 8))
        . substr($normalized, -4);
}

function clinic_sms_request(string $path, array $payload): array {
    if (!clinic_sms_ready()) {
        return ['ok' => false, 'error' => 'SMS verification is not configured yet. Please choose email verification.'];
    }

    $config = clinic_sms_config();
    $url = $config['base_url'] . '/' . ltrim($path, '/');
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'The SMS request could not be prepared.'];
    }

    $statusCode = 0;
    $responseBody = '';

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $config['api_key'],
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: GlobalifeClinic/1.0',
            ],
        ]);
        $responseBody = (string) curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($responseBody === '' && $curlError !== '') {
            return ['ok' => false, 'error' => 'Could not connect to the SMS service. Please try email verification.'];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => 20,
                'ignore_errors' => true,
                'header' => implode("\r\n", [
                    'X-API-Key: ' . $config['api_key'],
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'User-Agent: GlobalifeClinic/1.0',
                ]),
                'content' => $json,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $responseBody = $response === false ? '' : $response;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $statusCode = (int) $matches[1];
            }
        }
    }

    $decoded = json_decode($responseBody, true);
    $success = $statusCode >= 200
        && $statusCode < 300
        && is_array($decoded)
        && !empty($decoded['success'])
        && empty($decoded['warning']);

    if ($success) {
        return ['ok' => true, 'data' => $decoded];
    }

    if ($statusCode === 401 || $statusCode === 403) {
        $message = 'SMS authentication failed. Please choose email verification.';
    } elseif ($statusCode === 402) {
        $message = 'SMS credits are currently unavailable. Please choose email verification.';
    } elseif ($statusCode === 429) {
        $message = 'Too many SMS requests. Please wait before trying again.';
    } elseif (is_array($decoded) && !empty($decoded['warning'])) {
        $message = 'The SMS provider rejected this message. Please choose email verification.';
    } else {
        $message = 'The verification text could not be sent. Please try again or choose email.';
    }

    return ['ok' => false, 'error' => $message];
}

function clinic_send_sms_code(string $phone, string $code, string $purpose): array {
    $normalizedPhone = clinic_sms_normalize_phone($phone);
    if ($normalizedPhone === null) {
        return ['ok' => false, 'error' => 'Enter a valid Philippine mobile number, such as 09171234567.'];
    }

    if ($purpose === 'appointment') {
        $label = 'appointment confirmation';
    } elseif ($purpose === 'profile_update') {
        $label = 'profile change';
    } else {
        $label = 'account verification';
    }
    $message = 'Globalife ' . $label . ' code: ' . $code
        . '. Valid for 10 minutes. Do not share this code.';

    return clinic_sms_request('sms/send', [
        'phone_number' => $normalizedPhone,
        'message' => $message,
    ]);
}

function clinic_send_sms_message(string $phone, string $message): array {
    $normalizedPhone = clinic_sms_normalize_phone($phone);
    if ($normalizedPhone === null) {
        return ['ok' => false, 'error' => 'The patient does not have a valid Philippine mobile number.'];
    }

    $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
    if ($message === '') {
        return ['ok' => false, 'error' => 'The SMS message is empty.'];
    }
    if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
    }

    return clinic_sms_request('sms/send', [
        'phone_number' => $normalizedPhone,
        'message' => $message,
    ]);
}

function clinic_send_verification_code(
    string $channel,
    string $email,
    string $phone,
    string $name,
    string $code,
    string $purpose
): array {
    if ($channel === 'sms') {
        return clinic_send_sms_code($phone, $code, $purpose);
    }

    require_once __DIR__ . '/mailer.php';
    return clinic_send_otp_email($email, $name, $code, $purpose);
}

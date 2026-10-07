<?php

function clinic_mail_is_live_host(): bool {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    return $host !== ''
        && strpos($host, 'localhost') === false
        && strpos($host, '127.0.0.1') === false
        && strpos($host, '::1') === false;
}

function clinic_mail_config(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'enabled' => false,
        'host' => '',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => 'Globalife Medical Laboratory & Polyclinic',
    ];

    $configDirectory = __DIR__ . '/../config/';
    $preferredFilename = clinic_mail_is_live_host() ? 'mail.production.php' : 'mail.local.php';
    $fallbackFilename = clinic_mail_is_live_host() ? 'mail.local.php' : 'mail.production.php';

    foreach ([$preferredFilename, $fallbackFilename] as $filename) {
        $path = $configDirectory . $filename;
        if (!is_file($path)) {
            continue;
        }

        $loaded = require $path;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
            break;
        }
    }

    $environmentValues = [
        'host' => getenv('SMTP_HOST'),
        'port' => getenv('SMTP_PORT'),
        'encryption' => getenv('SMTP_ENCRYPTION'),
        'username' => getenv('SMTP_USERNAME'),
        'password' => getenv('SMTP_PASSWORD'),
        'from_email' => getenv('SMTP_FROM_EMAIL'),
        'from_name' => getenv('SMTP_FROM_NAME'),
    ];
    foreach ($environmentValues as $key => $value) {
        if ($value !== false && trim((string) $value) !== '') {
            $config[$key] = $value;
        }
    }

    $enabledEnvironmentValue = getenv('SMTP_ENABLED');
    if ($enabledEnvironmentValue !== false && trim((string) $enabledEnvironmentValue) !== '') {
        $config['enabled'] = filter_var($enabledEnvironmentValue, FILTER_VALIDATE_BOOLEAN);
    }

    $config['host'] = trim((string) $config['host']);
    $config['username'] = trim((string) $config['username']);
    $config['from_email'] = trim((string) $config['from_email']);
    $config['from_name'] = trim((string) $config['from_name']);
    $config['password'] = trim((string) $config['password']);
    $config['encryption'] = strtolower(trim((string) $config['encryption']));
    $config['port'] = (int) $config['port'];

    return $config;
}

function clinic_mail_ready(): bool {
    $config = clinic_mail_config();
    return !empty($config['enabled'])
        && trim((string) $config['host']) !== ''
        && (int) $config['port'] > 0
        && trim((string) $config['username']) !== ''
        && filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)
        && trim((string) $config['password']) !== ''
        && strpos((string) $config['password'], 'PALITAN_') === false;
}

function clinic_smtp_read($socket): array {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }

    return [
        'code' => (int) substr($response, 0, 3),
        'message' => trim($response),
    ];
}

function clinic_smtp_command($socket, string $command, array $expectedCodes): array {
    fwrite($socket, $command . "\r\n");
    $response = clinic_smtp_read($socket);
    if (!in_array($response['code'], $expectedCodes, true)) {
        return ['ok' => false, 'error' => $response['message']];
    }
    return ['ok' => true, 'response' => $response['message']];
}

function clinic_mail_header_value(string $value): string {
    return str_replace(["\r", "\n"], '', trim($value));
}

function clinic_smtp_auth_check(): array {
    if (!clinic_mail_ready()) {
        return ['ok' => false, 'error' => 'SMTP settings are incomplete.'];
    }

    $config = clinic_mail_config();
    $encryption = strtolower(trim((string) $config['encryption']));
    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);
    $socket = @stream_socket_client(
        $transport . $config['host'] . ':' . (int) $config['port'],
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$socket) {
        return ['ok' => false, 'error' => 'Could not connect to the SMTP server.'];
    }

    stream_set_timeout($socket, 20);
    $greeting = clinic_smtp_read($socket);
    $ehloCommand = 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $_SERVER['SERVER_NAME'] ?? 'globalife.online');
    $ehlo = clinic_smtp_command($socket, $ehloCommand, [250]);

    if ($greeting['code'] !== 220 || !$ehlo['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => 'The SMTP server rejected the connection.'];
    }

    if ($encryption === 'tls' || $encryption === 'starttls') {
        $startTls = clinic_smtp_command($socket, 'STARTTLS', [220]);
        if (!$startTls['ok'] || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'error' => 'Could not start a secure SMTP connection.'];
        }
        $ehlo = clinic_smtp_command($socket, $ehloCommand, [250]);
        if (!$ehlo['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => 'The SMTP server rejected the secure connection.'];
        }
    }

    $auth = clinic_smtp_command($socket, 'AUTH LOGIN', [334]);
    $user = $auth['ok']
        ? clinic_smtp_command($socket, base64_encode((string) $config['username']), [334])
        : ['ok' => false];
    $pass = $user['ok']
        ? clinic_smtp_command($socket, base64_encode((string) $config['password']), [235])
        : ['ok' => false];

    @clinic_smtp_command($socket, 'QUIT', [221]);
    fclose($socket);

    return $pass['ok']
        ? ['ok' => true]
        : ['ok' => false, 'error' => 'SMTP login failed. Check the mailbox email and mailbox password.'];
}

function clinic_send_email(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): array {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The recipient email address is invalid.'];
    }
    if (!clinic_mail_ready()) {
        return ['ok' => false, 'error' => 'SMTP email notifications are not configured yet.'];
    }

    $config = clinic_mail_config();
    $encryption = strtolower(trim((string) $config['encryption']));
    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $remote = $transport . $config['host'] . ':' . (int) $config['port'];
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['ok' => false, 'error' => 'Could not connect to the email server.'];
    }

    stream_set_timeout($socket, 20);
    $greeting = clinic_smtp_read($socket);
    if ($greeting['code'] !== 220) {
        fclose($socket);
        return ['ok' => false, 'error' => 'The email server did not accept the connection.'];
    }

    $serverName = $_SERVER['SERVER_NAME'] ?? 'globalife.online';
    $ehlo = 'EHLO ' . preg_replace('/[^A-Za-z0-9.-]/', '', $serverName);
    $result = clinic_smtp_command($socket, $ehlo, [250]);
    if (!$result['ok']) {
        fclose($socket);
        return ['ok' => false, 'error' => 'The SMTP server rejected the connection greeting.'];
    }

    if ($encryption === 'tls' || $encryption === 'starttls') {
        $startTls = clinic_smtp_command($socket, 'STARTTLS', [220]);
        if (!$startTls['ok'] || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['ok' => false, 'error' => 'Could not start a secure SMTP connection.'];
        }

        $result = clinic_smtp_command($socket, $ehlo, [250]);
        if (!$result['ok']) {
            fclose($socket);
            return ['ok' => false, 'error' => 'The SMTP server rejected the secure connection.'];
        }
    }

    $commands = [
        ['AUTH LOGIN', [334]],
        [base64_encode((string) $config['username']), [334]],
        [base64_encode((string) $config['password']), [235]],
        ['MAIL FROM:<' . $config['from_email'] . '>', [250]],
        ['RCPT TO:<' . $toEmail . '>', [250, 251]],
        ['DATA', [354]],
    ];

    foreach ($commands as [$command, $expected]) {
        $result = clinic_smtp_command($socket, $command, $expected);
        if (!$result['ok']) {
            @clinic_smtp_command($socket, 'QUIT', [221]);
            fclose($socket);
            return ['ok' => false, 'error' => 'The email server rejected the message or login.'];
        }
    }

    $boundary = 'globalife_' . bin2hex(random_bytes(12));
    $safeSubject = clinic_mail_header_value($subject);
    $safeToName = clinic_mail_header_value($toName);
    $safeFromName = clinic_mail_header_value((string) $config['from_name']);
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@globalife.online>',
        'From: ' . $safeFromName . ' <' . $config['from_email'] . '>',
        'To: ' . ($safeToName !== '' ? $safeToName . ' ' : '') . '<' . $toEmail . '>',
        'Subject: ' . $safeSubject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = implode("\r\n", $headers) . "\r\n\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($textBody) . "\r\n\r\n";
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
    $body .= '--' . $boundary . "--\r\n";
    $body = preg_replace('/^\./m', '..', $body);

    fwrite($socket, $body . "\r\n.\r\n");
    $sent = clinic_smtp_read($socket);
    @clinic_smtp_command($socket, 'QUIT', [221]);
    fclose($socket);

    if ($sent['code'] !== 250) {
        return ['ok' => false, 'error' => 'The email server could not deliver the message.'];
    }

    return ['ok' => true];
}

function clinic_send_otp_email(string $email, string $name, string $code, string $purpose): array {
    $safeName = htmlspecialchars($name !== '' ? $name : 'Patient', ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    if ($purpose === 'registration') {
        $title = 'Verify your Globalife account';
        $instruction = 'Enter this code to verify your email and finish creating your patient account.';
    } elseif ($purpose === 'appointment') {
        $title = 'Confirm your Globalife appointment request';
        $instruction = 'Enter this code on the appointment verification page to confirm that this booking request is yours.';
    } elseif ($purpose === 'profile_update') {
        $title = 'Confirm your Globalife profile change';
        $instruction = 'Enter this code on your profile page to finish changing your email or password.';
    } else {
        $title = 'Reset your Globalife password';
        $instruction = 'Enter this code on the Forgot Password page to create a new password.';
    }

    $html = '<!DOCTYPE html><html><body style="margin:0;background:#eef7fc;font-family:Arial,sans-serif;color:#073b4c">'
        . '<div style="max-width:560px;margin:30px auto;background:#fff;border:1px solid #d5e9f3;border-radius:12px;padding:28px">'
        . '<h1 style="font-size:24px;color:#0b4f80;margin:0 0 16px">' . $title . '</h1>'
        . '<p>Hello <strong>' . $safeName . '</strong>,</p>'
        . '<p style="line-height:1.6">' . $instruction . '</p>'
        . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;text-align:center;padding:18px;background:#eef8fd;border-radius:10px;color:#0077b6">'
        . $safeCode . '</div>'
        . '<p style="line-height:1.6">This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>'
        . '<p style="font-size:13px;color:#607784">If you did not request this, you can safely ignore this email.</p>'
        . '</div></body></html>';

    $text = $title . "\n\n"
        . 'Hello ' . ($name !== '' ? $name : 'Patient') . ",\n\n"
        . $instruction . "\n\n"
        . 'Your verification code: ' . $code . "\n\n"
        . "This code expires in 10 minutes. Do not share it with anyone.\n";

    return clinic_send_email($email, $name, $title, $html, $text);
}

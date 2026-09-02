<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
mysqli_report(MYSQLI_REPORT_OFF);

$checks = [];

function deploy_check_add(array &$checks, string $label, bool $ok, string $detail = ''): void {
    $checks[] = [
        'label' => $label,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

deploy_check_add($checks, 'PHP version', version_compare(PHP_VERSION, '8.0.0', '>='), PHP_VERSION . ' detected');
deploy_check_add($checks, 'index.php exists', is_file(__DIR__ . '/index.php'), 'Root homepage file');
deploy_check_add($checks, 'globalife.png exists', is_file(__DIR__ . '/globalife.png'), 'Logo asset');
deploy_check_add($checks, 'includes/session.php exists', is_file(__DIR__ . '/includes/session.php'), 'Session helper');
require_once __DIR__ . '/includes/patient_profile_photo.php';
$profileStorageDir = patientProfileUploadDir();
deploy_check_add(
    $checks,
    'profile photo storage writable',
    is_dir($profileStorageDir) && is_writable($profileStorageDir),
    'Needed for patient profile photos outside Git deploy cleanup'
);
deploy_check_add($checks, 'OpenSSL for secure SMTP', extension_loaded('openssl'), 'Required for SSL/TLS email delivery');

require_once __DIR__ . '/includes/mailer.php';
$mailConfig = clinic_mail_config();
$mailOk = false;
if (clinic_mail_ready()) {
    $smtpCheck = clinic_smtp_auth_check();
    $mailOk = $smtpCheck['ok'];
    $mailDetail = $mailOk
        ? 'SMTP login verified: ' . $mailConfig['host'] . ':' . $mailConfig['port'] . ' as ' . $mailConfig['username']
        : $smtpCheck['error'];
} else {
    $mailDetail = 'Configure config/mail.production.php with your SMTP server and mailbox credentials';
}
deploy_check_add($checks, 'SMTP email notifications', $mailOk, $mailDetail);

require_once __DIR__ . '/includes/sms.php';
$smsConfig = clinic_sms_config();
$smsCheck = clinic_sms_auth_check();
deploy_check_add(
    $checks,
    'SMS notifications',
    $smsCheck['ok'],
    $smsCheck['ok']
        ? 'SkySMS API login verified at ' . $smsConfig['base_url']
        : (string) ($smsCheck['error'] ?? 'Configure config/sms.php or the SKYSMS_API_KEY environment variable')
);

$dbDetail = 'Not checked';
$dbOk = false;
try {
    require_once __DIR__ . '/config/database.php';
    $dbDetail = 'Using database ' . DB_NAME . ' as ' . DB_USER . ' on ' . DB_HOST;
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        $dbDetail .= ' - ' . $conn->connect_error;
    } else {
        $dbOk = true;
        $conn->close();
    }
} catch (Throwable $e) {
    $dbDetail = $e->getMessage();
}
deploy_check_add($checks, 'Database connection', $dbOk, $dbDetail);

$allOk = array_reduce($checks, static fn ($carry, $check) => $carry && $check['ok'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Globalife Deploy Check</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f9fd;margin:0;padding:32px;color:#073b4c}
        .wrap{max-width:860px;margin:0 auto;background:#fff;border:1px solid #dbeaf3;border-radius:14px;padding:24px;box-shadow:0 12px 28px rgba(20,79,123,.1)}
        h1{margin:0 0 8px;color:#0b4f80}
        p{color:#526b7a;line-height:1.5}
        table{width:100%;border-collapse:collapse;margin-top:18px}
        td{padding:12px;border-bottom:1px solid #edf3f8;vertical-align:top}
        .ok{color:#1f7a3a;font-weight:700}
        .fail{color:#b42318;font-weight:700}
        .badge{display:inline-block;border-radius:999px;padding:6px 10px;font-weight:700}
        .badge.ok{background:#e8f6ed}
        .badge.fail{background:#fdecec}
        code{background:#f3f7fa;padding:2px 6px;border-radius:6px}
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Globalife Deploy Check</h1>
        <p>This page checks the basics needed for the deployed system to load. Remove this file after deployment is confirmed.</p>
        <p><span class="badge <?php echo $allOk ? 'ok' : 'fail'; ?>"><?php echo $allOk ? 'Ready' : 'Needs attention'; ?></span></p>
        <table>
            <?php foreach ($checks as $check): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($check['label']); ?></strong></td>
                    <td class="<?php echo $check['ok'] ? 'ok' : 'fail'; ?>"><?php echo $check['ok'] ? 'OK' : 'Fix needed'; ?></td>
                    <td><?php echo htmlspecialchars($check['detail']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p>For Hostinger database settings, create <code>config/production.php</code> based on <code>config/production.example.php</code>.</p>
    </div>
</body>
</html>

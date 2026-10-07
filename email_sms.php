<?php
require_once 'includes/session.php';
checkRole('admin');

require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/sms.php';

if (empty($_SESSION['email_sms_csrf'])) {
    $_SESSION['email_sms_csrf'] = bin2hex(random_bytes(24));
}

$pageTitle = 'Email/SMS Settings | Globalife Administration';
$mailConfig = clinic_mail_config();
$smsConfig = clinic_sms_config();
$mailReady = clinic_mail_ready();
$testNotice = null;

$notificationSettings = [
    'reminder_days' => '1',
    'appointment_confirmation' => true,
    'appointment_cancellation' => true,
    'appointment_reminder' => true,
    'account_verification' => true,
];
$notificationSettingsPath = __DIR__ . '/storage/notification_settings.json';
if (is_file($notificationSettingsPath)) {
    $savedSettings = json_decode((string) file_get_contents($notificationSettingsPath), true);
    if (is_array($savedSettings)) {
        $notificationSettings = array_merge($notificationSettings, $savedSettings);
    }
}
$notificationSettings['reminder_days'] = (string) $notificationSettings['reminder_days'];
foreach (['appointment_confirmation', 'appointment_cancellation', 'appointment_reminder', 'account_verification'] as $notificationKey) {
    $notificationSettings[$notificationKey] = (bool) $notificationSettings[$notificationKey];
}
$originalNotificationSettings = $notificationSettings;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['email_sms_csrf'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $testNotice = ['type' => 'error', 'channel' => 'settings', 'message' => 'The request expired. Refresh the page and try again.'];
    } elseif (($_POST['action'] ?? '') === 'save_settings') {
        $updatedNotificationSettings = [
            'reminder_days' => in_array((string) ($_POST['reminder_days'] ?? '1'), ['1', '2', '3'], true) ? (string) $_POST['reminder_days'] : '1',
            'appointment_confirmation' => isset($_POST['appointment_confirmation']),
            'appointment_cancellation' => isset($_POST['appointment_cancellation']),
            'appointment_reminder' => isset($_POST['appointment_reminder']),
            'account_verification' => isset($_POST['account_verification']),
        ];
        if ($updatedNotificationSettings === $originalNotificationSettings) {
            $notificationSettings = $originalNotificationSettings;
            $testNotice = ['type' => 'info', 'channel' => 'settings', 'message' => 'No changes were made to the notification settings.'];
        } else {
            $notificationSettings = $updatedNotificationSettings;
            $saved = @file_put_contents($notificationSettingsPath, json_encode($notificationSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
            $testNotice = $saved === false
                ? ['type' => 'error', 'channel' => 'settings', 'message' => 'Notification settings could not be saved.']
                : ['type' => 'success', 'channel' => 'settings', 'message' => 'Notification settings saved successfully.'];
        }
    } elseif (($_POST['action'] ?? '') === 'test_email') {
        $recipient = trim((string) ($_POST['test_email'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $testNotice = ['type' => 'error', 'channel' => 'email', 'message' => 'Enter a valid email address.'];
        } elseif (!$mailReady) {
            $testNotice = ['type' => 'error', 'channel' => 'email', 'message' => 'Email service is not configured. Check the Hostinger mailbox settings first.'];
        } else {
            $mailResult = clinic_send_email(
                $recipient,
                'Administrator',
                'Globalife test email',
                '<p>This is a test email from Globalife Medical Laboratory &amp; Polyclinic.</p>',
                'This is a test email from Globalife Medical Laboratory & Polyclinic.'
            );
            $testNotice = [
                'type' => !empty($mailResult['ok']) ? 'success' : 'error',
                'channel' => 'email',
                'message' => !empty($mailResult['ok']) ? 'Test email sent successfully.' : (string) ($mailResult['error'] ?? 'Test email could not be sent.'),
            ];
        }
    } elseif (($_POST['action'] ?? '') === 'test_sms') {
        $testNotice = [
            'type' => 'error',
            'channel' => 'sms',
            'message' => 'SMS service is currently expired. Renew the SkySMS account and replace the API key before sending a test SMS.',
        ];
    }
}

$additionalStyles = '
body{background:#f4f8fb;color:#1f343d}
.notification-settings-page{max-width:1240px;margin:0 auto;padding:34px 20px 48px}
.notification-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.notification-card,.notification-options{border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06)}
.notification-card{padding:24px}
.notification-card-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:18px}
.notification-card-icon{width:58px;height:58px;flex:0 0 58px;border-radius:50%;display:grid;place-items:center;background:#eaf6ff;color:#0f7cc2}
.notification-card-icon svg{width:32px;height:32px;fill:none;stroke:currentColor;stroke-width:2.1;stroke-linecap:round;stroke-linejoin:round}
.notification-card h2{margin:4px 0 6px;color:#073b4c;font-size:1.18rem;line-height:1.2}
.notification-card p{margin:0;color:#607784;line-height:1.45}
.notification-status{display:inline-flex;align-items:center;gap:8px;margin:6px 0 7px;padding:9px 13px;border-radius:7px;background:#e4f7eb;color:#17643a;font-weight:800}
.notification-status::before{content:"";width:11px;height:11px;border-radius:50%;background:#1db15a}
.notification-status.needs-renewal{background:#fff1d9;color:#895900}
.notification-status.needs-renewal::before{background:#e3a326}
.notification-service-note{min-height:42px;text-align:center;color:#607784!important;font-size:.88rem}
.notification-card-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:48px;margin-top:18px;border:1px solid #3d9af0;border-radius:8px;background:#fff;color:#0665bf;font-size:.95rem;font-weight:800;cursor:pointer}
.notification-card-button:hover{background:#eff8ff}
.notification-card-button svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.notification-card-button:disabled{border-color:#c8d6df;color:#91a5b1;background:#f4f8fa;cursor:not-allowed}
.settings-select{width:100%;min-height:46px;margin-top:9px;border:1px solid #cfe0ea;border-radius:7px;padding:0 12px;background:#fff;color:#29475a;font-weight:700}
.reminder-info{display:flex;gap:9px;align-items:flex-start;margin-top:18px;padding:12px;border-radius:7px;background:#eaf6ff;color:#52718c;font-size:.84rem;line-height:1.45}
.reminder-info svg{width:19px;height:19px;flex:0 0 19px;fill:none;stroke:#0f7cc2;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.notification-options{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:24px;align-items:center;margin-top:18px;padding:24px 30px}
.notification-options h2{margin:0 0 6px;color:#073b4c;font-size:1.3rem}
.notification-options p{margin:0 0 16px;color:#607784}
.notification-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 30px}
.notification-check{display:flex;align-items:center;gap:10px;color:#29475a;font-weight:700}
.notification-check input{width:18px;height:18px;accent-color:#147ce5}
.save-settings-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:190px;min-height:50px;border:0;border-radius:8px;background:#147ce5;color:#fff;font-weight:800;cursor:pointer}
.save-settings-button svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.test-modal-overlay{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(5,26,48,.55)}
.test-modal-overlay.is-open{display:flex}
.test-modal{width:min(470px,100%);border-radius:12px;background:#fff;box-shadow:0 24px 70px rgba(5,26,48,.28);padding:26px}
.test-modal h2{margin:0 0 8px;color:#073b4c;font-size:1.35rem}
.test-modal p{margin:0 0 18px;color:#607784;line-height:1.5}
.test-modal label{display:grid;gap:8px;color:#29475a;font-weight:700}
.test-modal input{width:100%;box-sizing:border-box;min-height:46px;border:1px solid #bfd6e8;border-radius:7px;padding:10px 13px;font-size:1rem}
.test-modal input:focus{outline:0;border-color:#147ce5;box-shadow:0 0 0 3px rgba(20,124,229,.12)}
.test-modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}
.test-modal-actions button{min-height:42px;border-radius:7px;padding:9px 17px;font-weight:800;cursor:pointer}
.test-modal-close{border:1px solid #cfe0ea;background:#f5f9fb;color:#315a72}
.test-modal-submit{border:0;background:#147ce5;color:#fff}
.test-modal-submit:disabled{background:#b9c9d3;cursor:not-allowed}
.test-modal-result{text-align:center}
.test-modal-result .result-mark{width:52px;height:52px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#e4f7eb;color:#17643a;font-size:1.5rem;font-weight:900}
.test-modal-result.error .result-mark{background:#fff0f0;color:#b21f2f}
.test-modal-result.info .result-mark{background:#eaf6ff;color:#0f7cc2}
@media(max-width:980px){.notification-grid{grid-template-columns:1fr 1fr}.notification-card:last-child{grid-column:1/-1}.notification-options{grid-template-columns:1fr}}
@media(max-width:650px){.notification-settings-page{padding:22px 12px 38px}.notification-grid{grid-template-columns:1fr}.notification-card:last-child{grid-column:auto}.notification-options{padding:22px;grid-template-columns:1fr}.notification-checks{grid-template-columns:1fr}.save-settings-button{width:100%}}
';

function email_sms_icon(string $type): string {
    if ($type === 'sms') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M9 5h6M10 18h4"/></svg>';
    }
    if ($type === 'reminder') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v5l3 2"/></svg>';
    }
    if ($type === 'info') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 10v6M12 7h.01"/></svg>';
    }
    if ($type === 'save') {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h11l3 3v15H5z"/><path d="M8 3v6h8V3M8 21v-6h8v6"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';
}

include 'includes/header.php';
?>
<main class="notification-settings-page">
    <section class="notification-grid" aria-label="Notification channels">
        <article class="notification-card">
            <div class="notification-card-head">
                <span class="notification-card-icon"><?php echo email_sms_icon('email'); ?></span>
                <div><h2>Email Notifications</h2><p>Send appointment confirmations, updates, and reminders via email.</p></div>
            </div>
            <div class="notification-status<?php echo $mailReady ? '' : ' needs-renewal'; ?>"><?php echo $mailReady ? 'Configured' : 'Needs setup'; ?></div>
            <p class="notification-service-note">Email service is <?php echo $mailReady ? 'configured.' : 'not configured.'; ?></p>
            <button type="button" class="notification-card-button" data-open-test="email"><?php echo email_sms_icon('email'); ?>Send Test Email</button>
        </article>

        <article class="notification-card">
            <div class="notification-card-head">
                <span class="notification-card-icon"><?php echo email_sms_icon('sms'); ?></span>
                <div><h2>SMS Notifications</h2><p>Send appointment confirmations, updates, and reminders via SMS.</p></div>
            </div>
            <div class="notification-status needs-renewal">Needs renewal</div>
            <p class="notification-service-note">SMS service is expired and currently unavailable.</p>
            <button type="button" class="notification-card-button" data-open-test="sms"><?php echo email_sms_icon('sms'); ?>Send Test SMS</button>
        </article>

        <article class="notification-card">
            <div class="notification-card-head">
                <span class="notification-card-icon"><?php echo email_sms_icon('reminder'); ?></span>
                <div><h2>Reminder Settings</h2><p>Send automatic reminders before the appointment date.</p></div>
            </div>
            <label for="reminderDays">Send reminder</label>
            <select class="settings-select" id="reminderDays" form="notificationSettingsForm" name="reminder_days">
                <option value="1" <?php echo $notificationSettings['reminder_days'] === '1' ? 'selected' : ''; ?>>1 day before appointment</option>
                <option value="2" <?php echo $notificationSettings['reminder_days'] === '2' ? 'selected' : ''; ?>>2 days before appointment</option>
                <option value="3" <?php echo $notificationSettings['reminder_days'] === '3' ? 'selected' : ''; ?>>3 days before appointment</option>
            </select>
            <div class="reminder-info"><?php echo email_sms_icon('info'); ?><span>Reminders will be sent to confirmed appointments only.</span></div>
        </article>
    </section>

    <form class="notification-options" id="notificationSettingsForm" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['email_sms_csrf']); ?>">
        <input type="hidden" name="action" value="save_settings">
        <div>
            <h2>Notifications to Send</h2>
            <p>Select which notifications will be sent to patients.</p>
            <div class="notification-checks">
                <label class="notification-check"><input type="checkbox" name="appointment_confirmation" <?php echo !empty($notificationSettings['appointment_confirmation']) ? 'checked' : ''; ?>>Appointment confirmation</label>
                <label class="notification-check"><input type="checkbox" name="appointment_reminder" <?php echo !empty($notificationSettings['appointment_reminder']) ? 'checked' : ''; ?>>Appointment reminder</label>
                <label class="notification-check"><input type="checkbox" name="appointment_cancellation" <?php echo !empty($notificationSettings['appointment_cancellation']) ? 'checked' : ''; ?>>Appointment cancellation</label>
                <label class="notification-check"><input type="checkbox" name="account_verification" <?php echo !empty($notificationSettings['account_verification']) ? 'checked' : ''; ?>>Account verification (OTP)</label>
            </div>
        </div>
        <button class="save-settings-button" type="submit"><?php echo email_sms_icon('save'); ?>Save Settings</button>
    </form>
</main>

<div class="test-modal-overlay" id="emailTestModal" role="dialog" aria-modal="true" aria-labelledby="emailTestTitle">
    <form class="test-modal" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['email_sms_csrf']); ?>">
        <input type="hidden" name="action" value="test_email">
        <h2 id="emailTestTitle">Send Test Email</h2>
        <p>Enter the email address that should receive the test message.</p>
        <label>Email address<input type="email" name="test_email" value="<?php echo htmlspecialchars((string) ($mailConfig['from_email'] ?? '')); ?>" required></label>
        <div class="test-modal-actions"><button class="test-modal-close" type="button" data-close-modal>Cancel</button><button class="test-modal-submit" type="submit">Send Test Email</button></div>
    </form>
</div>

<div class="test-modal-overlay" id="smsTestModal" role="dialog" aria-modal="true" aria-labelledby="smsTestTitle">
    <div class="test-modal">
        <h2 id="smsTestTitle">Send Test SMS</h2>
        <p>SMS sending is temporarily unavailable because the SkySMS service or subscription has expired. Renew it first, then update the API key before testing.</p>
        <div class="test-modal-actions"><button class="test-modal-close" type="button" data-close-modal>Close</button><button class="test-modal-submit" type="button" disabled>Send Test SMS</button></div>
    </div>
</div>

<?php if ($testNotice !== null): ?>
    <?php $testNoticeIsError = $testNotice['type'] === 'error'; $testNoticeIsInfo = $testNotice['type'] === 'info'; ?>
    <div class="test-modal-overlay is-open" id="testResultModal" role="dialog" aria-modal="true" aria-labelledby="testResultTitle">
        <div class="test-modal test-modal-result <?php echo $testNoticeIsError ? 'error' : ($testNoticeIsInfo ? 'info' : ''); ?>">
            <div class="result-mark"><?php echo $testNoticeIsError ? '!' : ($testNoticeIsInfo ? 'i' : '&#10003;'); ?></div>
            <h2 id="testResultTitle"><?php echo $testNoticeIsError ? 'Unable to complete' : ($testNoticeIsInfo ? 'No changes made' : 'Success'); ?></h2>
            <p><?php echo htmlspecialchars($testNotice['message']); ?></p>
            <div class="test-modal-actions"><button class="test-modal-close" type="button" data-close-modal>Close</button></div>
        </div>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modals = document.querySelectorAll('.test-modal-overlay');
    const closeModal = function (modal) {
        if (modal) modal.classList.remove('is-open');
    };
    document.querySelectorAll('[data-open-test]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(button.dataset.openTest === 'sms' ? 'smsTestModal' : 'emailTestModal');
            if (modal) modal.classList.add('is-open');
        });
    });
    document.querySelectorAll('[data-close-modal]').forEach(function (button) {
        button.addEventListener('click', function () { closeModal(button.closest('.test-modal-overlay')); });
    });
    modals.forEach(function (modal) {
        modal.addEventListener('click', function (event) { if (event.target === modal) closeModal(modal); });
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') modals.forEach(closeModal);
    });
});
</script>
<?php include 'includes/footer.php'; ?>

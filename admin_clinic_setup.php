<?php
require_once 'includes/session.php';
checkRole('admin');

require_once __DIR__ . '/includes/sms.php';

$smsTestNotice = (array) ($_SESSION['admin_sms_test_notice'] ?? []);
unset($_SESSION['admin_sms_test_notice']);
if (empty($_SESSION['admin_settings_csrf'])) {
    $_SESSION['admin_settings_csrf'] = bin2hex(random_bytes(24));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_test_sms') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['admin_settings_csrf'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $_SESSION['admin_sms_test_notice'] = [
            'type' => 'error',
            'message' => 'The test request expired. Refresh the page and try again.',
        ];
    } else {
        $testPhone = (string) ($_POST['test_phone'] ?? '');
        $testResult = clinic_send_sms_message(
            $testPhone,
            'Globalife test message: SMS notifications are working. No action is required.'
        );
        $_SESSION['admin_sms_test_notice'] = [
            'type' => $testResult['ok'] ? 'success' : 'error',
            'message' => $testResult['ok']
                ? 'Test SMS sent successfully to ' . clinic_sms_mask_phone($testPhone) . '.'
                : (string) ($testResult['error'] ?? 'The test SMS could not be sent.'),
        ];
    }

    header('Location: admin_clinic_setup.php?modal=email_otp');
    exit();
}

$pageTitle = 'Admin Settings | Globalife Administration';

$settingsCards = [
    ['icon' => 'message', 'title' => 'Email, SMS & OTP Settings', 'text' => 'Send a test OTP message to verify the clinic mobile notification setup.', 'modal' => 'emailOtpModal'],
    ['icon' => 'backup', 'title' => 'Backup & Restore', 'text' => 'Download a database backup or review clinic activity before restoring data.', 'modal' => 'backupRestoreModal'],
];

function admin_settings_icon(string $icon): string {
    $icons = [
        'clinic' => '<path d="M4 21h16"/><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M9 9h6"/><path d="M12 6v6"/><path d="M10 21v-4h4v4"/>',
        'message' => '<path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/>',
        'shield' => '<path d="M12 3 5 6v5c0 4.5 2.8 8.3 7 10 4.2-1.7 7-5.5 7-10V6z"/><path d="M9.5 12h5"/><path d="M12 9.5v5"/>',
        'backup' => '<path d="M6 7c0-2.2 2.7-4 6-4s6 1.8 6 4-2.7 4-6 4-6-1.8-6-4z"/><path d="M6 7v6c0 2.2 2.7 4 6 4 .8 0 1.6-.1 2.3-.3"/><path d="M6 13v4c0 2.2 2.7 4 6 4 .7 0 1.4-.1 2-.2"/><path d="M18 14v6"/><path d="M15 17h6"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$icon] ?? $icons['clinic']) . '</svg>';
}

$additionalStyles = '
body{background:#f4f8fb;color:#1f343d}
.settings-page{max-width:1180px;margin:0 auto;padding:34px 20px 48px}
.settings-heading{margin-bottom:22px}
.settings-heading h1{margin:0 0 7px;color:#061a40;font-size:2.05rem;line-height:1.12}
.settings-heading p{margin:0;color:#607784;line-height:1.6}
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:18px}
.settings-card,.settings-panel{border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06)}
.settings-card{appearance:none;display:grid;grid-template-columns:118px minmax(0,1fr);gap:18px;align-items:center;min-height:174px;padding:24px 28px;color:inherit;text-align:left;font:inherit;cursor:pointer}
.settings-card:hover{border-color:#9ecbe0;background:#fbfdfe}
.settings-card:focus{outline:0;border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.12),0 10px 24px rgba(25,76,110,.06)}
.settings-icon{width:88px;height:88px;border-radius:50%;display:grid;place-items:center;background:#edf6ff;color:#0f66ad}
.settings-icon svg{width:44px;height:44px;fill:none;stroke:currentColor;stroke-width:2.1;stroke-linecap:round;stroke-linejoin:round}
.settings-copy h2{margin:0 0 9px;color:#073b4c;font-size:1.25rem}
.settings-copy p{margin:0;color:#607784;line-height:1.52;max-width:430px}
.settings-manage{display:inline-flex;align-items:center;justify-content:center;min-height:38px;min-width:112px;margin-top:18px;border-radius:7px;background:#0f7cc2;color:#fff;font-weight:900;box-shadow:0 8px 18px rgba(15,124,194,.18)}
.settings-panel{padding:22px}
.settings-panel h2{margin:0 0 8px;color:#073b4c;font-size:1.2rem}
.settings-panel p{margin:0 0 16px;color:#3d5a6b;line-height:1.5;max-width:560px}
.detail-list{display:grid;margin-top:4px}
.detail-row{display:flex;justify-content:space-between;gap:16px;padding:11px 0;border-bottom:1px solid #e4edf2}
.detail-row:last-child{border-bottom:0}
.detail-row span{color:#607784}
.detail-row strong{color:#073b4c;text-align:right}
.btn-row{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.settings-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;min-width:154px;border-radius:8px;border:1px solid transparent;padding:9px 15px;background:#0f7cc2;color:#fff;font-weight:900;text-decoration:none;cursor:pointer;text-align:center}
.settings-btn.secondary{background:#eef7ff;color:#0b4f80;border-color:#cfe3f2}
.test-sms-form{display:grid;grid-template-columns:minmax(180px,1fr) auto;gap:10px;align-items:end}
.test-sms-form label{display:grid;gap:7px;color:#365264;font-size:.82rem;font-weight:900}
.test-sms-form input{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cfe0ea;border-radius:8px;padding:9px 12px;color:#073b4c;font:inherit;background:#fff}
.test-sms-form input:focus{outline:0;border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.12)}
.settings-notice{margin-bottom:12px;border-radius:8px;padding:11px 13px;font-weight:800;line-height:1.45}
.settings-notice.success{background:#eaf7ef;border:1px solid #bfe2ca;color:#17643a}
.settings-notice.error{background:#fff0f0;border:1px solid #ffd0d5;color:#9d1c2c}
.settings-modal{position:fixed;inset:0;z-index:4300;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(7,24,38,.52)}
.settings-modal.is-open{display:flex}
.settings-modal-card{width:min(620px,100%);max-height:calc(100vh - 44px);overflow:auto;border:1px solid #d8e6ed;border-radius:10px;background:#fff;box-shadow:0 24px 70px rgba(7,24,38,.26)}
.settings-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:22px;border-bottom:1px solid #e4edf2;background:#f8fcff}
.settings-modal-head h2{margin:0 0 6px;color:#073b4c;font-size:1.25rem}
.settings-modal-head p{margin:0;color:#607784;line-height:1.45}
.settings-modal-close{width:38px;height:38px;border:1px solid #cfe3f2;border-radius:8px;background:#fff;color:#0b4f80;font-size:1.35rem;line-height:1;cursor:pointer}
.settings-modal-close:hover{background:#eef7ff}
.settings-modal-body{padding:22px}
@media(max-width:900px){.settings-grid{grid-template-columns:1fr}.settings-card{grid-template-columns:94px minmax(0,1fr);padding:22px}.settings-icon{width:76px;height:76px}.settings-icon svg{width:38px;height:38px}}
@media(max-width:560px){.settings-page{padding:22px 12px 38px}.settings-heading h1{font-size:1.7rem}.settings-card{grid-template-columns:1fr;gap:14px}.detail-row{flex-direction:column}.detail-row strong{text-align:left}.settings-btn{width:100%}.test-sms-form{grid-template-columns:1fr}}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const modalButtons = document.querySelectorAll("[data-settings-modal]");
    const modals = document.querySelectorAll(".settings-modal");

    function closeSettingsModal(modal) {
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
    }

    function openSettingsModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modals.forEach(closeSettingsModal);
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        const firstInput = modal.querySelector("input, button, a");
        if (firstInput) firstInput.focus();
    }

    modalButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openSettingsModal(button.getAttribute("data-settings-modal"));
        });
    });

    if (window.location.search.indexOf("modal=email_otp") !== -1) {
        openSettingsModal("emailOtpModal");
    }

    document.querySelectorAll("[data-close-settings-modal]").forEach(function (button) {
        button.addEventListener("click", function () {
            closeSettingsModal(button.closest(".settings-modal"));
        });
    });

    modals.forEach(function (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) closeSettingsModal(modal);
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            modals.forEach(closeSettingsModal);
        }
    });
});
';

include 'includes/header.php';
?>
<main class="settings-page">
    <section class="settings-heading">
        <h1>Admin Settings</h1>
        <p>Manage clinic and system preferences.</p>
    </section>

    <section class="settings-grid" aria-label="Settings modules">
        <?php foreach ($settingsCards as $card): ?>
            <button class="settings-card" type="button" data-settings-modal="<?php echo htmlspecialchars($card['modal']); ?>">
                <span class="settings-icon"><?php echo admin_settings_icon((string) $card['icon']); ?></span>
                <div class="settings-copy">
                    <h2><?php echo htmlspecialchars($card['title']); ?></h2>
                    <p><?php echo htmlspecialchars($card['text']); ?></p>
                    <span class="settings-manage">Manage</span>
                </div>
            </button>
        <?php endforeach; ?>
    </section>

    <div class="settings-modal" id="emailOtpModal" aria-hidden="true">
        <div class="settings-modal-card" role="dialog" aria-modal="true" aria-labelledby="emailOtpTitle">
            <div class="settings-modal-head">
                <div>
                    <h2 id="emailOtpTitle">Email, SMS &amp; OTP Settings</h2>
                    <p>Send a test message to check the mobile number used for SMS and OTP delivery.</p>
                </div>
                <button class="settings-modal-close" type="button" aria-label="Close" data-close-settings-modal>&times;</button>
            </div>
            <div class="settings-modal-body">
                <?php if (!empty($smsTestNotice['message'])): ?>
                    <div class="settings-notice <?php echo $smsTestNotice['type'] === 'success' ? 'success' : 'error'; ?>">
                        <?php echo htmlspecialchars((string) $smsTestNotice['message']); ?>
                    </div>
                <?php endif; ?>
                <form class="test-sms-form" method="POST" action="admin_clinic_setup.php">
                    <input type="hidden" name="action" value="send_test_sms">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['admin_settings_csrf']); ?>">
                    <label>
                        Test mobile number
                        <input type="tel" name="test_phone" inputmode="tel" placeholder="09171234567" pattern="(?:\+?63|0)?9\d{9}" required>
                    </label>
                    <button type="submit" class="settings-btn">Send test SMS</button>
                </form>
            </div>
        </div>
    </div>

    <div class="settings-modal" id="backupRestoreModal" aria-hidden="true">
        <div class="settings-modal-card" role="dialog" aria-modal="true" aria-labelledby="backupRestoreTitle">
            <div class="settings-modal-head">
                <div>
                    <h2 id="backupRestoreTitle">Backup &amp; Restore Database</h2>
                    <p>Download a SQL backup before deployment or before editing clinic data.</p>
                </div>
                <button class="settings-modal-close" type="button" aria-label="Close" data-close-settings-modal>&times;</button>
            </div>
            <div class="settings-modal-body">
                <p>Restore is intentionally manual through phpMyAdmin so the live database is not overwritten by accident.</p>
                <div class="btn-row">
                    <a class="settings-btn" href="admin_report_export.php?report=backup&amp;format=sql">Download SQL Backup</a>
                    <a class="settings-btn secondary" href="admin.php?notifications=1">Review activity first</a>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>

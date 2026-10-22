<?php
require_once 'includes/session.php';
checkRole('admin');

require_once __DIR__ . '/includes/appointment_capacity.php';

$pageTitle = 'Slot Management | Globalife Administration';
$noticeType = '';
$noticeTitle = '';
$noticeMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['slot_action'] ?? '') === 'save_capacity') {
    $currentCapacity = appointment_capacity_settings();
    $submittedCapacity = [];
    $validCapacity = true;
    foreach (array_keys($currentCapacity) as $key) {
        $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1 || $value > 999) {
            $validCapacity = false;
            break;
        }
        $submittedCapacity[$key] = (int) $value;
    }

    if (!$validCapacity) {
        $_SESSION['slot_management_notice'] = [
            'type' => 'error',
            'title' => 'Unable to save',
            'message' => 'Please enter a valid limit from 1 to 999 for every service.',
        ];
    } elseif ($submittedCapacity === $currentCapacity) {
        $_SESSION['slot_management_notice'] = [
            'type' => 'info',
            'title' => 'No changes made',
            'message' => 'The slot settings are already up to date.',
        ];
    } elseif (appointment_capacity_save($submittedCapacity)) {
        $_SESSION['slot_management_notice'] = [
            'type' => 'success',
            'title' => 'Successfully saved',
            'message' => 'The slot settings were updated successfully.',
        ];
    } else {
        $_SESSION['slot_management_notice'] = [
            'type' => 'error',
            'title' => 'Unable to save',
            'message' => 'The slot settings could not be saved. Please try again.',
        ];
    }
    header('Location: slot_management.php');
    exit;
}

$notice = $_SESSION['slot_management_notice'] ?? null;
unset($_SESSION['slot_management_notice']);
if (is_array($notice)) {
    $noticeType = (string) ($notice['type'] ?? 'info');
    $noticeTitle = (string) ($notice['title'] ?? 'Notice');
    $noticeMessage = (string) ($notice['message'] ?? '');
}

$capacity = appointment_capacity_settings();

$additionalStyles = '
body{background:#f4f8fb;color:#1f343d}
.slot-page{max-width:1180px;margin:0 auto;padding:34px 20px 48px}
.slot-heading{margin-bottom:22px}
.slot-heading h1{margin:0 0 7px;color:#061a40;font-size:2.05rem;line-height:1.12}
.slot-heading p{margin:0;color:#607784;line-height:1.6}
.slot-panel{border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06);padding:20px}
.slot-panel-head{display:flex;align-items:center;gap:14px;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #e0edf3}
.slot-panel-icon{width:44px;height:44px;flex:0 0 44px;display:flex;align-items:center;justify-content:center;margin:0;padding:0;border-radius:12px;background:#e5f3ff;color:#0878b5;line-height:0}
.slot-panel-icon svg{display:block;width:26px;height:26px;margin:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.slot-panel-head strong{display:block;color:#073b4c;font-size:1.08rem}
.slot-panel-head span{display:block;margin-top:3px;color:#607784;font-size:.86rem}
.slot-panel-head > .slot-panel-icon{display:flex;margin:0;color:#0878b5;font-size:initial}
.slot-panel-head > .slot-panel-icon svg{display:block;margin:0}
.slot-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.slot-card{border:1px solid #dce8f2;border-radius:8px;background:linear-gradient(135deg,#fafdff,#f1f8ff);padding:18px}
.slot-card-head{display:flex;align-items:center;gap:10px;margin-bottom:20px;color:#073b4c;font-weight:900}
.slot-card-icon{width:40px;height:40px;flex:0 0 40px;display:flex;align-items:center;justify-content:center;margin:0;padding:0;border-radius:10px;background:#e4f2ff;color:#0878b5;line-height:0}
.slot-card-icon svg{display:block;width:24px;height:24px;margin:0;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.slot-card-head > .slot-card-icon{display:flex;margin:0;color:#0878b5;font-size:initial}
.slot-card-head > .slot-card-icon svg{display:block;margin:0}
.slot-card label{display:block;color:#526c7b;font-size:.86rem;line-height:1.35}
.slot-input-row{display:flex;align-items:center;gap:10px;margin-top:8px}
.slot-input-row input{width:100%;min-width:0;padding:10px 12px;border:1px solid #cfe0eb;border-radius:7px;background:#fff;color:#173b54;font:inherit;font-weight:800;font-size:1rem}
.slot-input-row input:focus{outline:0;border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.12)}
.slot-note{margin:14px 0 0;padding:11px 12px;border-radius:7px;background:#eaf6ff;color:#0b65a0;font-size:.8rem;line-height:1.45}
.slot-actions{display:flex;justify-content:flex-end;margin-top:18px}
.slot-save{min-height:44px;padding:0 22px;border:0;border-radius:7px;background:#0f7cc2;color:#fff;font:inherit;font-weight:900;cursor:pointer;box-shadow:0 8px 18px rgba(15,124,194,.18)}
.slot-save:hover{background:#086aa8}
.slot-message{margin-bottom:16px;padding:13px 14px;border-radius:8px;font-weight:800}
.slot-message.ok{border:1px solid #bfe6ce;background:#e7f7ed;color:#17643a}
.slot-message.error{border:1px solid #ffd0d5;background:#fff0f0;color:#9d1c2c}
.slot-notice-modal{position:fixed;inset:0;z-index:6600;display:grid;place-items:center;padding:20px;background:rgba(3,37,56,.48);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}
.slot-notice-dialog{width:min(460px,100%);padding:28px 26px 24px;border:1px solid #c9e2ef;border-radius:18px;background:#fff;box-shadow:0 28px 80px rgba(4,35,52,.3);text-align:center}
.slot-notice-icon{width:58px;height:58px;margin:0 auto 14px;display:grid;place-items:center;border-radius:50%;background:#e4f7eb;color:#168448}
.slot-notice-modal.info .slot-notice-icon{background:#eaf6ff;color:#0878b5}
.slot-notice-modal.error .slot-notice-icon{background:#fff0f0;color:#b42335}
.slot-notice-icon svg{width:29px;height:29px;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.slot-notice-dialog h2{margin:0;color:#073b4c;font-size:1.35rem}
.slot-notice-dialog p{margin:9px 0 22px;color:#607784;line-height:1.5}
.slot-notice-close{min-height:42px;padding:0 24px;border:0;border-radius:8px;background:#0f7cc2;color:#fff;font:inherit;font-weight:900;cursor:pointer}
.slot-notice-close:hover{background:#086aa8}
@media(max-width:800px){.slot-grid{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.slot-page{padding:22px 12px 38px}.slot-panel{padding:14px}.slot-grid{grid-template-columns:1fr}.slot-actions,.slot-save{width:100%}}
';

include 'includes/header.php';
?>
<main class="slot-page">
    <section class="slot-heading">
        <h1>Slot Management</h1>
        <p>Set the maximum number of appointments accepted each day for every clinic service.</p>
    </section>

    <?php if ($noticeMessage !== ''): ?>
        <div class="slot-notice-modal <?php echo htmlspecialchars($noticeType); ?>" id="slotNoticeModal" role="dialog" aria-modal="true" aria-labelledby="slotNoticeTitle">
            <section class="slot-notice-dialog">
                <div class="slot-notice-icon" aria-hidden="true">
                    <?php if ($noticeType === 'success'): ?>
                        <svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>
                    <?php elseif ($noticeType === 'error'): ?>
                        <svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24"><path d="M12 10v6M12 7h.01"/><circle cx="12" cy="12" r="9"/></svg>
                    <?php endif; ?>
                </div>
                <h2 id="slotNoticeTitle"><?php echo htmlspecialchars($noticeTitle); ?></h2>
                <p><?php echo htmlspecialchars($noticeMessage); ?></p>
                <button type="button" class="slot-notice-close" id="slotNoticeClose">Close</button>
            </section>
        </div>
    <?php endif; ?>

    <form method="post" class="slot-panel">
        <input type="hidden" name="slot_action" value="save_capacity">
        <div class="slot-panel-head">
            <span class="slot-panel-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 9h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M8 13h3M13 13h3M8 17h3"/></svg></span>
            <div><strong>Service Appointment Capacity</strong><span>Set the maximum number of patients per day for each service.</span></div>
        </div>
        <div class="slot-grid">
            <div class="slot-card">
                <div class="slot-card-head"><span class="slot-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v4M16 3v4M5 10h14M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M12 13v4M10 15h4"/></svg></span>Doctor Consultation</div>
                <label for="doctor_limit">Daily appointment limit<br>(per doctor)</label>
                <div class="slot-input-row"><input type="number" min="1" max="999" id="doctor_limit" name="doctor_limit" value="<?php echo (int) $capacity['doctor_limit']; ?>" required></div>
                <p class="slot-note">This limit applies separately to each active doctor.</p>
            </div>
            <div class="slot-card">
                <div class="slot-card-head"><span class="slot-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9 3h6M10 3v6l-4.5 9.5A2 2 0 0 0 7.3 21h9.4a2 2 0 0 0 1.8-2.5L14 9V3"/><path d="M8 16h8"/></svg></span>Laboratory Test</div>
                <label for="laboratory_limit">Daily appointment limit<br>(all laboratory tests)</label>
                <div class="slot-input-row"><input type="number" min="1" max="999" id="laboratory_limit" name="laboratory_limit" value="<?php echo (int) $capacity['laboratory_limit']; ?>" required></div>
            </div>
            <div class="slot-card">
                <div class="slot-card-head"><span class="slot-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 15h4M13 15h4M8 9h8"/></svg></span>Ultrasound</div>
                <label for="ultrasound_limit">Daily appointment limit<br>(all ultrasound services)</label>
                <div class="slot-input-row"><input type="number" min="1" max="999" id="ultrasound_limit" name="ultrasound_limit" value="<?php echo (int) $capacity['ultrasound_limit']; ?>" required></div>
            </div>
        </div>
        <div class="slot-actions"><button type="submit" class="slot-save">Save Slot Settings</button></div>
    </form>
</main>
<?php if ($noticeMessage !== ''): ?>
<script>
(function () {
    var modal = document.getElementById('slotNoticeModal');
    var closeButton = document.getElementById('slotNoticeClose');
    if (!modal || !closeButton) return;
    function closeModal() {
        modal.remove();
        document.body.style.overflow = '';
    }
    document.body.style.overflow = 'hidden';
    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });
})();
</script>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>

<style>
.btn-reschedule { background:#fff7e8; color:#9a5b00; border:1px solid #f1d49c; }
.btn-reschedule:hover { background:#fff0cf; border-color:#e6bd69; }
.reschedule-modal { position:fixed; inset:0; z-index:3200; display:none; align-items:center; justify-content:center; padding:18px; background:rgba(3,18,30,.5); backdrop-filter:blur(4px); }
.reschedule-modal.is-open { display:flex; }
.reschedule-card { width:min(520px,100%); background:#fff; border:1px solid #cde8f3; border-radius:22px; box-shadow:0 24px 70px rgba(2,62,138,.2); overflow:hidden; }
.reschedule-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; padding:24px 26px; background:linear-gradient(135deg,#fff,#eefaff); border-bottom:1px solid #d8eef7; }
.reschedule-head h3 { margin:0; color:#10233f; font-size:1.28rem; }
.reschedule-head p { margin:8px 0 0; color:#60758a; font-size:.9rem; }
.reschedule-body { padding:22px 24px 8px; }
.reschedule-field { display:grid; gap:7px; margin-bottom:16px; }
.reschedule-field label { color:#315c70; font-weight:800; font-size:.9rem; }
.reschedule-field input { width:100%; box-sizing:border-box; min-height:46px; padding:10px 12px; border:1px solid #cfe3f2; border-radius:10px; color:#10233f; background:#fff; font:inherit; }
.reschedule-field input:focus { outline:none; border-color:#0f7cc2; box-shadow:0 0 0 4px rgba(15,124,194,.1); }
.reschedule-note { margin:0 0 8px; color:#60758a; line-height:1.45; font-size:.88rem; }
.reschedule-actions { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px 24px; }
@media (max-width:520px) { .reschedule-actions { flex-direction:column-reverse; } .reschedule-actions .btn { width:100%; } }
.reschedule-success-modal { position:fixed; inset:0; z-index:3300; display:flex; align-items:center; justify-content:center; padding:18px; background:rgba(3,18,30,.5); backdrop-filter:blur(4px); }
.reschedule-success-modal[aria-hidden="true"] { display:none; }
.reschedule-success-card { width:min(420px,100%); border:1px solid #cde8f3; border-radius:18px; background:#fff; box-shadow:0 24px 70px rgba(2,62,138,.2); text-align:center; }
.reschedule-success-body { padding:30px 28px 28px; }
.reschedule-success-icon { display:inline-grid; place-items:center; width:64px; height:64px; margin-bottom:16px; border-radius:50%; background:#dcf7e7; color:#148047; font-size:2rem; font-weight:950; }
.reschedule-success-body h3 { margin:0 0 8px; color:#073b4c; font-size:1.28rem; }
.reschedule-success-body p { margin:0 0 20px; color:#60727d; line-height:1.45; }
.reschedule-error-modal { position:fixed; inset:0; z-index:3300; display:flex; align-items:center; justify-content:center; padding:18px; background:rgba(3,18,30,.5); backdrop-filter:blur(4px); }
.reschedule-error-modal[aria-hidden="true"] { display:none; }
.reschedule-error-card { width:min(440px,100%); border:1px solid #f0c8ce; border-radius:18px; background:#fff; box-shadow:0 24px 70px rgba(2,62,138,.2); text-align:center; }
.reschedule-error-body { padding:30px 28px 28px; }
.reschedule-error-icon { display:inline-grid; place-items:center; width:64px; height:64px; margin-bottom:16px; border-radius:50%; background:#fff0f1; color:#c1121f; font-size:2rem; font-weight:950; }
.reschedule-error-body h3 { margin:0 0 8px; color:#073b4c; font-size:1.28rem; }
.reschedule-error-body p { margin:0 0 20px; color:#60727d; line-height:1.45; }
</style>
<?php
$rescheduleNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$rescheduleToday = $rescheduleNow->format('Y-m-d');
$rescheduleMinTime = $rescheduleNow->modify('+1 minute')->format('H:i');
?>
<div class="reschedule-modal" id="appointmentRescheduleModal" aria-hidden="true"
    data-today="<?php echo htmlspecialchars($rescheduleToday, ENT_QUOTES, 'UTF-8'); ?>"
    data-min-time="<?php echo htmlspecialchars($rescheduleMinTime, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="reschedule-card" role="dialog" aria-modal="true" aria-labelledby="appointmentRescheduleTitle">
        <div class="reschedule-head">
            <div>
                <h3 id="appointmentRescheduleTitle">Reschedule appointment</h3>
                <p id="appointmentRescheduleCurrent">Choose a new date and time.</p>
            </div>
            <button type="button" class="modal-close" data-close-reschedule aria-label="Close">&times;</button>
        </div>
        <form method="post" action="reschedule_appointment.php" id="appointmentRescheduleForm">
            <?php echo clinic_csrf_field(); ?>
            <input type="hidden" name="appointment_id" id="appointmentRescheduleId" value="">
            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars((string) $appointmentPage, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="reschedule-body">
                <p class="reschedule-note" id="appointmentRescheduleNotice">The new schedule will be checked against clinic hours, availability, and daily capacity.</p>
                <div class="reschedule-field">
                    <label for="appointmentRescheduleDate">New date</label>
                    <input type="date" name="appointment_date" id="appointmentRescheduleDate"
                        min="<?php echo htmlspecialchars($rescheduleToday, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="reschedule-field">
                    <label for="appointmentRescheduleTime">New time</label>
                    <input type="time" name="appointment_time" id="appointmentRescheduleTime" required>
                </div>
            </div>
            <div class="reschedule-actions">
                <button type="button" class="btn btn-light" data-close-reschedule>Cancel</button>
                <button type="submit" class="btn btn-primary">Save new schedule</button>
            </div>
        </form>
    </div>
</div>
<?php
$rescheduleSuccessMessage = trim((string) ($_SESSION['reschedule_success'] ?? ''));
$rescheduleErrorMessage = trim((string) ($_SESSION['reschedule_error'] ?? ''));
unset($_SESSION['reschedule_success']);
unset($_SESSION['reschedule_error']);
?>
<div class="reschedule-success-modal" id="rescheduleSuccessModal"
    aria-hidden="<?php echo $rescheduleSuccessMessage !== '' ? 'false' : 'true'; ?>"
    role="dialog" aria-modal="true" aria-labelledby="rescheduleSuccessTitle">
    <div class="reschedule-success-card">
        <div class="reschedule-success-body">
            <span class="reschedule-success-icon" aria-hidden="true">&#10003;</span>
            <h3 id="rescheduleSuccessTitle">Successfully rescheduled</h3>
            <p id="rescheduleSuccessText">You have successfully rescheduled your appointment.</p>
            <button type="button" class="btn btn-primary" id="rescheduleSuccessOk">OK</button>
        </div>
    </div>
</div>
<div class="reschedule-error-modal" id="rescheduleErrorModal"
    aria-hidden="<?php echo $rescheduleErrorMessage !== '' ? 'false' : 'true'; ?>"
    role="alertdialog" aria-modal="true" aria-labelledby="rescheduleErrorTitle">
    <div class="reschedule-error-card">
        <div class="reschedule-error-body">
            <span class="reschedule-error-icon" aria-hidden="true">!</span>
            <h3 id="rescheduleErrorTitle">Unable to reschedule</h3>
            <p id="rescheduleErrorText"><?php echo htmlspecialchars($rescheduleErrorMessage !== '' ? $rescheduleErrorMessage : 'Please check the new schedule and try again.', ENT_QUOTES, 'UTF-8'); ?></p>
            <button type="button" class="btn btn-primary" id="rescheduleErrorOk">OK</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('appointmentRescheduleModal');
    var form = document.getElementById('appointmentRescheduleForm');
    var idInput = document.getElementById('appointmentRescheduleId');
    var dateInput = document.getElementById('appointmentRescheduleDate');
    var timeInput = document.getElementById('appointmentRescheduleTime');
    var currentText = document.getElementById('appointmentRescheduleCurrent');
    var successModal = document.getElementById('rescheduleSuccessModal');
    var successOk = document.getElementById('rescheduleSuccessOk');
    var errorModal = document.getElementById('rescheduleErrorModal');
    var errorOk = document.getElementById('rescheduleErrorOk');
    if (!modal || !form || !idInput || !dateInput || !timeInput) return;
    var today = modal.getAttribute('data-today') || '';
    var minimumTimeToday = modal.getAttribute('data-min-time') || '';

    function updateTimeMinimum() {
        if (dateInput.value === today) {
            timeInput.min = minimumTimeToday;
            if (timeInput.value && timeInput.value < minimumTimeToday) {
                timeInput.value = '';
            }
        } else {
            timeInput.removeAttribute('min');
        }
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('[data-open-reschedule]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            idInput.value = button.getAttribute('data-appointment-id') || '';
            var currentDate = button.getAttribute('data-reschedule-date') || '';
            dateInput.value = currentDate && currentDate >= today ? currentDate : today;
            timeInput.value = (button.getAttribute('data-reschedule-time') || '').slice(0, 5);
            updateTimeMinimum();
            var current = button.getAttribute('data-reschedule-label') || 'Current appointment schedule';
            if (currentText) currentText.textContent = current;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            window.setTimeout(function () { dateInput.focus(); }, 50);
        });
    });
    dateInput.addEventListener('change', updateTimeMinimum);
    modal.querySelectorAll('[data-close-reschedule]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    function closeSuccessModal() {
        if (!successModal) return;
        successModal.setAttribute('aria-hidden', 'true');
    }
    if (successOk) successOk.addEventListener('click', closeSuccessModal);
    if (successModal) {
        successModal.addEventListener('click', function (event) {
            if (event.target === successModal) closeSuccessModal();
        });
    }
    function closeErrorModal() {
        if (!errorModal) return;
        errorModal.setAttribute('aria-hidden', 'true');
    }
    if (errorOk) errorOk.addEventListener('click', closeErrorModal);
    if (errorModal) {
        errorModal.addEventListener('click', function (event) {
            if (event.target === errorModal) closeErrorModal();
        });
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
        if (event.key === 'Escape' && successModal && successModal.getAttribute('aria-hidden') === 'false') closeSuccessModal();
        if (event.key === 'Escape' && errorModal && errorModal.getAttribute('aria-hidden') === 'false') closeErrorModal();
    });
})();
</script>

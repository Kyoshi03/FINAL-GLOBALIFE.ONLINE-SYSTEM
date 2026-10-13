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
</style>
<div class="reschedule-modal" id="appointmentRescheduleModal" aria-hidden="true">
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
                    <input type="date" name="appointment_date" id="appointmentRescheduleDate" required>
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
<script>
(function () {
    var modal = document.getElementById('appointmentRescheduleModal');
    var form = document.getElementById('appointmentRescheduleForm');
    var idInput = document.getElementById('appointmentRescheduleId');
    var dateInput = document.getElementById('appointmentRescheduleDate');
    var timeInput = document.getElementById('appointmentRescheduleTime');
    var currentText = document.getElementById('appointmentRescheduleCurrent');
    if (!modal || !form || !idInput || !dateInput || !timeInput) return;

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('[data-open-reschedule]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            idInput.value = button.getAttribute('data-appointment-id') || '';
            dateInput.value = button.getAttribute('data-reschedule-date') || '';
            timeInput.value = (button.getAttribute('data-reschedule-time') || '').slice(0, 5);
            var current = button.getAttribute('data-reschedule-label') || 'Current appointment schedule';
            if (currentText) currentText.textContent = current;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            window.setTimeout(function () { dateInput.focus(); }, 50);
        });
    });
    modal.querySelectorAll('[data-close-reschedule]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
})();
</script>

<style>
.btn-reschedule { background:#fff7e8; color:#9a5b00; border:1px solid #f1d49c; }
.btn-reschedule:hover { background:#fff0cf; border-color:#e6bd69; }
.reschedule-request-note { margin-top:6px; color:#9a5b00; font-size:.78rem; line-height:1.35; }
.reschedule-request-note strong { color:#7b4a00; }
.patient-reschedule-compact { display:none; }
.patient-reschedule-card-note .patient-reschedule-detail { display:none; }
.patient-reschedule-card-note .patient-reschedule-compact {
    display:flex;
    align-items:baseline;
    gap:8px;
    margin-top:7px;
    padding:7px 10px;
    border:1px solid #f1d49c;
    border-radius:9px;
    background:#fffaf0;
    color:#9a5b00;
    line-height:1.3;
}
.patient-reschedule-card-note .patient-reschedule-compact strong {
    color:#7b4a00;
    font-size:.76rem;
    white-space:nowrap;
}
.patient-reschedule-card-note .patient-reschedule-compact span {
    font-size:.74rem;
}
.request-pending-badge { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:7px 12px; border:1px solid #f1d49c; border-radius:9px; background:#fff7e8; color:#9a5b00; font:inherit; font-size:.78rem; font-weight:800; }
.reschedule-modal { position:fixed; inset:0; z-index:3200; display:none; align-items:center; justify-content:center; padding:18px; background:rgba(3,18,30,.5); backdrop-filter:blur(4px); }
.reschedule-modal.is-open { display:flex; }
.reschedule-card { width:min(520px,100%); background:#fff; border:1px solid #cde8f3; border-radius:22px; box-shadow:0 24px 70px rgba(2,62,138,.2); overflow:hidden; }
.reschedule-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; padding:24px 26px; background:linear-gradient(135deg,#fff,#eefaff); border-bottom:1px solid #d8eef7; }
.reschedule-head h3 { margin:0; color:#10233f; font-size:1.28rem; }
.reschedule-head p { margin:8px 0 0; color:#60758a; font-size:.9rem; }
.reschedule-body { padding:16px 18px 4px; }
.reschedule-field { display:grid; gap:7px; margin-bottom:16px; }
.reschedule-field label { color:#315c70; font-weight:800; font-size:.9rem; }
.reschedule-field input, .reschedule-field select { width:100%; box-sizing:border-box; min-height:46px; padding:10px 12px; border:1px solid #cfe3f2; border-radius:10px; color:#10233f; background:#fff; font:inherit; }
.reschedule-field input:focus, .reschedule-field select:focus { outline:none; border-color:#0f7cc2; box-shadow:0 0 0 4px rgba(15,124,194,.1); }
.reschedule-note { margin:0 0 8px; color:#60758a; line-height:1.45; font-size:.88rem; }
.reschedule-reason-field { margin:0 0 12px; }
.reschedule-reason-field[hidden] { display:none !important; }
.reschedule-reason-field label { display:block; margin:0 0 6px; color:#315c70; font-weight:800; font-size:.9rem; }
.reschedule-reason-field textarea { width:100%; min-height:72px; box-sizing:border-box; padding:10px 12px; border:1px solid #cfe3f2; border-radius:10px; color:#10233f; background:#fff; font:inherit; resize:vertical; }
.reschedule-reason-field textarea:focus { outline:none; border-color:#0f7cc2; box-shadow:0 0 0 4px rgba(15,124,194,.1); }
.reschedule-availability { min-height:18px; margin:0 0 11px; padding:7px 10px; border-radius:8px; background:#f2f8fc; color:#42657a; font-size:.78rem; line-height:1.35; }
.reschedule-availability.is-loading { color:#6e7d87; }
.reschedule-availability.is-error { background:#fff3f3; color:#a52835; }
.reschedule-availability.is-ready { background:#effaf3; color:#246b43; }
.reschedule-actions button {
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:42px;
    padding:10px 18px;
    border:1px solid transparent;
    border-radius:9px;
    font:inherit;
    font-size:.86rem;
    font-weight:800;
    line-height:1.2;
    cursor:pointer;
    transition:background .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease,transform .15s ease;
}
.reschedule-actions .btn-light {
    background:#fff;
    color:#0b4f80;
    border-color:#cfe3f2;
}
.reschedule-actions .btn-light:hover {
    background:#eef8ff;
    border-color:#8ec7e2;
    box-shadow:0 4px 10px rgba(15,124,194,.10);
    transform:translateY(-1px);
}
.reschedule-actions .btn-primary {
    background:#0f7cc2;
    color:#fff;
    border-color:#0f7cc2;
    box-shadow:0 5px 12px rgba(15,124,194,.16);
}
.reschedule-actions .btn-primary:hover:not(:disabled) {
    background:#0b66a0;
    border-color:#0b66a0;
    box-shadow:0 6px 14px rgba(11,102,160,.22);
    transform:translateY(-1px);
}
.reschedule-actions button:focus-visible {
    outline:3px solid rgba(15,124,194,.24);
    outline-offset:2px;
}
.reschedule-actions button:active:not(:disabled) {
    transform:translateY(0);
    box-shadow:none;
}
.reschedule-actions button:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }

@media (max-width: 900px) {
    .patient-reschedule-card-note .patient-reschedule-detail { display:none; }
    .patient-reschedule-card-note .patient-reschedule-compact {
        display:grid;
        gap:3px;
        margin-top:7px;
        padding:8px 10px;
        border:1px solid #f1d49c;
        border-radius:9px;
        background:#fffaf0;
        color:#9a5b00;
        line-height:1.3;
    }
    .patient-reschedule-card-note .patient-reschedule-compact strong {
        color:#7b4a00;
        font-size:.76rem;
    }
    .patient-reschedule-card-note .patient-reschedule-compact span {
        font-size:.74rem;
    }
}

.reschedule-calendar-panel { display:none !important; margin:0 0 16px; border:1px solid #d8e8f2; border-radius:15px; overflow:hidden; background:#fff; }
.reschedule-calendar-panel.is-visible { display:block !important; }
.reschedule-field[hidden] { display:none !important; }
.reschedule-calendar-head { display:grid; grid-template-columns:minmax(190px,1fr) minmax(210px,1fr); gap:14px; align-items:center; padding:15px; border-bottom:1px solid #d8e8f2; background:#fff; }
.reschedule-calendar-title { display:grid; grid-template-columns:38px 1fr 38px; align-items:center; gap:8px; }
.reschedule-calendar-title strong { color:#10233f; font-size:1.05rem; font-weight:900; text-align:center; }
.reschedule-calendar-nav { display:inline-grid; place-items:center; width:38px; height:38px; border:1px solid #cfe3f2; border-radius:9px; background:#fff; color:#0878b5; font-size:1.25rem; font-weight:900; cursor:pointer; }
.reschedule-calendar-nav:hover:not(:disabled) { background:#eef8ff; border-color:#8ec7e2; }
.reschedule-calendar-nav:disabled { color:#aab8c2; background:#f4f7f9; cursor:not-allowed; }
.reschedule-selected { display:flex; align-items:center; gap:10px; min-height:58px; padding:9px 12px; border:1px solid #cfe3f2; border-radius:11px; background:#fbfdff; }
.reschedule-selected-icon { display:grid; place-items:center; width:38px; height:38px; flex:0 0 38px; border-radius:10px; background:#eef8ff; color:#0878b5; font-size:1.25rem; }
.reschedule-selected-copy { min-width:0; display:grid; gap:3px; }
.reschedule-selected-copy > span:first-child { color:#0878b5; font-size:.78rem; font-weight:900; }
.reschedule-selected-copy strong { color:#10233f; font-size:.88rem; line-height:1.25; }
.reschedule-selected-time { color:#60758a; font-size:.76rem; font-weight:700; }
.reschedule-calendar-grid { display:grid; grid-template-columns:repeat(7,minmax(0,1fr)); gap:1px; background:#dcebf3; }
.reschedule-calendar-dow { padding:10px 3px; background:#f7fbfd; color:#526176; font-size:.68rem; font-weight:900; text-align:center; text-transform:uppercase; }
.reschedule-calendar-empty { min-height:64px; background:#fff; }
.reschedule-calendar-day { min-height:64px; padding:8px 7px; border:0; background:#fff; color:#10233f; text-align:left; cursor:pointer; }
.reschedule-calendar-day:hover:not(:disabled) { background:#f2faff; outline:2px solid #8ec7e2; outline-offset:-2px; }
.reschedule-calendar-day:disabled { cursor:not-allowed; background:#f7f9fa; color:#94a3ae; }
.reschedule-calendar-day.is-full:disabled { background:#fffaf2; color:#8a5b12; }
.reschedule-calendar-day.is-selected { background:#eef8ff; box-shadow:inset 0 0 0 2px #70b9e5; }
.reschedule-calendar-day.is-today .reschedule-day-number { background:#dff1ff; color:#086ca5; }
.reschedule-calendar-day.is-available { background:#fff; }
.reschedule-calendar-day.is-full { background:#fffaf2; }
.reschedule-day-number { display:inline-grid; place-items:center; width:23px; height:23px; margin-bottom:5px; border-radius:50%; font-size:.83rem; font-weight:900; }
.reschedule-calendar-day.is-available .reschedule-day-number { color:#10233f; }
.reschedule-calendar-day.is-full .reschedule-day-number { background:#fff0d2; color:#8a5b12; }
.reschedule-day-status { display:block; width:100%; overflow:hidden; border-radius:999px; padding:3px 6px; font-size:.62rem; font-weight:900; line-height:1.15; text-overflow:ellipsis; white-space:nowrap; }
.reschedule-day-status.available { background:#dff6e9; border:1px solid #bfe9d0; color:#137342; }
.reschedule-day-status.full { background:#fff0d2; border:1px solid #f0d6a8; color:#8a5b12; }
.reschedule-day-status.unavailable { background:#eef2f5; border:1px solid #d7e0e7; color:#657584; }
.reschedule-calendar-legend { display:flex; flex-wrap:wrap; gap:8px 14px; padding:11px 13px; border-top:1px solid #d8e8f2; color:#60758a; font-size:.72rem; }
.reschedule-calendar-legend span { display:inline-flex; align-items:center; gap:5px; }
.reschedule-calendar-legend i { width:9px; height:9px; border-radius:50%; background:#bfe9d0; }
.reschedule-calendar-legend .legend-full i { background:#f0d6a8; }
.reschedule-calendar-legend .legend-unavailable i { background:#d7e0e7; }
.reschedule-time-field { grid-template-columns:auto minmax(150px,220px); align-items:center; gap:10px; margin:2px 0 8px; }
.reschedule-time-field label { margin:0; white-space:nowrap; }
.reschedule-time-field input, .reschedule-time-field select { width:220px; min-height:42px; }
.reschedule-compact-field { margin-bottom:12px; }
.reschedule-compact-field select { min-height:42px; }
.reschedule-compact-field select:disabled { color:#7a8b99; background:#f7fafc; }
@media (max-width:620px) { .reschedule-calendar-head { grid-template-columns:1fr; } .reschedule-calendar-day { min-height:60px; padding:7px 5px; } .reschedule-day-status { font-size:.55rem; padding:3px 4px; } }
@media (max-width:520px) { .reschedule-time-field { grid-template-columns:1fr; gap:6px; } .reschedule-time-field input, .reschedule-time-field select { width:100%; } }
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
                <p class="reschedule-note" id="appointmentRescheduleNotice">Your appointment is already confirmed. Your current schedule will remain active until the reschedule request is approved.</p>
                <div class="reschedule-reason-field" id="appointmentRescheduleReasonField">
                    <label for="appointmentRescheduleReason">Reason for rescheduling (Optional)</label>
                    <textarea id="appointmentRescheduleReason" name="reason" maxlength="300" placeholder="Briefly explain why you need to change your schedule."></textarea>
                </div>
                <p class="reschedule-availability is-loading" id="appointmentRescheduleAvailability" aria-live="polite">Checking the doctor's available dates...</p>
                <div class="reschedule-calendar-panel" id="appointmentRescheduleCalendarPanel" aria-label="Doctor availability calendar">
                    <div class="reschedule-calendar-head">
                        <div class="reschedule-calendar-title">
                            <button type="button" class="reschedule-calendar-nav" id="appointmentReschedulePrevMonth" aria-label="Previous month">&lsaquo;</button>
                            <strong id="appointmentRescheduleMonthLabel">Choose a date</strong>
                            <button type="button" class="reschedule-calendar-nav" id="appointmentRescheduleNextMonth" aria-label="Next month">&rsaquo;</button>
                        </div>
                        <div class="reschedule-selected">
                            <span class="reschedule-selected-icon" aria-hidden="true">&#128197;</span>
                            <div class="reschedule-selected-copy">
                                <span>Selected date</span>
                                <strong id="appointmentRescheduleSelectedDate">Choose a date</strong>
                                <span class="reschedule-selected-time" id="appointmentRescheduleSelectedTime">Choose a time</span>
                            </div>
                        </div>
                    </div>
                    <div class="reschedule-calendar-grid" id="appointmentRescheduleCalendarGrid"></div>
                </div>
                <div class="reschedule-field reschedule-compact-field" id="appointmentRescheduleDateChoicesField" hidden>
                    <label for="appointmentRescheduleDateChoices">New date</label>
                    <select id="appointmentRescheduleDateChoices" aria-label="Available appointment dates"></select>
                </div>
                <div class="reschedule-field" id="appointmentRescheduleDateField">
                    <label for="appointmentRescheduleDate" id="appointmentRescheduleDateLabel">New date</label>
                    <input type="date" name="appointment_date" id="appointmentRescheduleDate"
                        min="<?php echo htmlspecialchars($rescheduleToday, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="reschedule-field reschedule-time-field">
                    <label for="appointmentRescheduleTime">New time</label>
                    <input type="time" name="appointment_time" id="appointmentRescheduleTime" required>
                    <select id="appointmentRescheduleTimeChoices" aria-label="Available appointment times" hidden></select>
                </div>
            </div>
            <div class="reschedule-actions">
                <button type="button" class="btn btn-light" data-close-reschedule>Cancel</button>
                <button type="submit" class="btn btn-primary" id="appointmentRescheduleSubmit" disabled>Submit Request</button>
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
            <h3 id="rescheduleSuccessTitle">Request submitted</h3>
            <p id="rescheduleSuccessText">Your original Confirmed appointment remains active while the clinic reviews your requested schedule.</p>
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
    var dateField = document.getElementById('appointmentRescheduleDateField');
    var dateChoicesField = document.getElementById('appointmentRescheduleDateChoicesField');
    var dateChoices = document.getElementById('appointmentRescheduleDateChoices');
    var timeInput = document.getElementById('appointmentRescheduleTime');
    var timeChoices = document.getElementById('appointmentRescheduleTimeChoices');
    var availabilityNote = document.getElementById('appointmentRescheduleAvailability');
    var submitButton = document.getElementById('appointmentRescheduleSubmit');
    var reasonField = document.getElementById('appointmentRescheduleReasonField');
    var reasonInput = document.getElementById('appointmentRescheduleReason');
    var calendarPanel = document.getElementById('appointmentRescheduleCalendarPanel');
    var calendarGrid = document.getElementById('appointmentRescheduleCalendarGrid');
    var monthLabel = document.getElementById('appointmentRescheduleMonthLabel');
    var selectedDateLabel = document.getElementById('appointmentRescheduleSelectedDate');
    var selectedTimeLabel = document.getElementById('appointmentRescheduleSelectedTime');
    var prevMonthButton = document.getElementById('appointmentReschedulePrevMonth');
    var nextMonthButton = document.getElementById('appointmentRescheduleNextMonth');
    var dateLabel = document.getElementById('appointmentRescheduleDateLabel');
    var currentText = document.getElementById('appointmentRescheduleCurrent');
    var title = document.getElementById('appointmentRescheduleTitle');
    var notice = document.getElementById('appointmentRescheduleNotice');
    var successModal = document.getElementById('rescheduleSuccessModal');
    var successOk = document.getElementById('rescheduleSuccessOk');
    var errorModal = document.getElementById('rescheduleErrorModal');
    var errorOk = document.getElementById('rescheduleErrorOk');
    if (!modal || !form || !idInput || !dateInput || !timeInput) return;
    var today = modal.getAttribute('data-today') || '';
    var minimumTimeToday = modal.getAttribute('data-min-time') || '';
    var availability = null;
    var availabilityRequest = null;
    var calendarMonth = null;

    function parseDateValue(value) {
        var parts = String(value || '').split('-').map(Number);
        if (parts.length !== 3 || parts.some(function (part) { return !Number.isFinite(part); })) return null;
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function dateValue(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }

    function monthValue(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
    }

    function formatDateLabel(value) {
        var date = parseDateValue(value);
        if (!date) return 'Choose a date';
        return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    function formatTimeLabel(value) {
        var parts = String(value || '').split(':');
        if (parts.length < 2) return value || '';
        var hour = Number(parts[0]);
        var minute = parts[1];
        if (!Number.isFinite(hour)) return value;
        var suffix = hour >= 12 ? 'PM' : 'AM';
        var displayHour = hour % 12 || 12;
        return displayHour + ':' + minute + ' ' + suffix;
    }

    function updateSelectedSummary() {
        if (selectedDateLabel) selectedDateLabel.textContent = formatDateLabel(dateInput.value);
        if (selectedTimeLabel) selectedTimeLabel.textContent = timeInput.value ? formatTimeLabel(timeInput.value) : 'Choose a time';
    }

    function setAvailabilityNote(message, state) {
        if (!availabilityNote) return;
        availabilityNote.textContent = message;
        availabilityNote.className = 'reschedule-availability ' + (state ? 'is-' + state : '');
    }

    function setSubmitEnabled(enabled) {
        if (submitButton) submitButton.disabled = !enabled;
    }

    function dateIsAvailable(date) {
        return availability && Array.isArray(availability.available_dates)
            && availability.available_dates.indexOf(date) !== -1;
    }

    function slotsForDate(date) {
        return availability && availability.slots_by_date && Array.isArray(availability.slots_by_date[date])
            ? availability.slots_by_date[date] : [];
    }

    function timeIsInDoctorSchedule(date, time) {
        if (!dateIsAvailable(date) || !/^\d{2}:\d{2}$/.test(time)) return false;
        return slotsForDate(date).some(function (slot) {
            return time >= slot.start && time <= slot.end;
        });
    }

    function firstAvailableDate() {
        return availability && availability.available_dates && availability.available_dates.length
            ? availability.available_dates[0] : '';
    }

    function firstAvailableTime(date) {
        var slots = slotsForDate(date);
        if (!slots.length) return '';
        var first = slots[0].start;
        if (date === today && minimumTimeToday > first) first = minimumTimeToday;
        return first;
    }

    function populateDateChoices(preferredDate) {
        if (!dateChoices) return '';
        dateChoices.innerHTML = '';
        dateChoices.disabled = false;
        var dates = availability && Array.isArray(availability.available_dates)
            ? availability.available_dates.slice() : [];
        dates.sort();
        if (!dates.length) {
            var emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = 'No available dates';
            dateChoices.appendChild(emptyOption);
            dateChoices.disabled = true;
            dateChoices.value = '';
            return '';
        }
        var groups = {};
        dates.forEach(function (value) {
            var parsed = parseDateValue(value);
            if (!parsed) return;
            var key = monthValue(parsed);
            if (!groups[key]) {
                groups[key] = document.createElement('optgroup');
                groups[key].label = parsed.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                dateChoices.appendChild(groups[key]);
            }
            var option = document.createElement('option');
            option.value = value;
            option.textContent = formatDateLabel(value);
            groups[key].appendChild(option);
        });
        var selectedDate = dateIsAvailable(preferredDate) ? preferredDate : dates[0];
        dateChoices.value = selectedDate || '';
        return selectedDate || '';
    }

    function setTimeChoices(date, preferredTime) {
        if (!timeChoices || !availability || availability.booking_type !== 'consultation') return;
        var options = [];
        var seen = {};
        slotsForDate(date).forEach(function (slot) {
            var startParts = slot.start.split(':').map(Number);
            var endParts = slot.end.split(':').map(Number);
            if (startParts.length < 2 || endParts.length < 2) return;
            var start = startParts[0] * 60 + startParts[1];
            var end = endParts[0] * 60 + endParts[1];
            if (date === today) {
                var minimumParts = minimumTimeToday.split(':').map(Number);
                if (minimumParts.length >= 2) start = Math.max(start, minimumParts[0] * 60 + minimumParts[1]);
            }
            for (var minutes = start; minutes <= end; minutes += 30) {
                var hour = Math.floor(minutes / 60);
                var minute = minutes % 60;
                var value = String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0');
                if (!seen[value]) {
                    seen[value] = true;
                    options.push(value);
                }
            }
        });
        if (preferredTime && timeIsInDoctorSchedule(date, preferredTime) && !seen[preferredTime]) options.push(preferredTime);
        options.sort();
        timeChoices.innerHTML = '';
        options.forEach(function (value) {
            var option = document.createElement('option');
            option.value = value;
            option.textContent = formatTimeLabel(value);
            timeChoices.appendChild(option);
        });
        timeChoices.hidden = false;
        timeChoices.required = true;
        timeInput.required = false;
        timeInput.style.display = 'none';
        timeChoices.value = preferredTime && options.indexOf(preferredTime) !== -1 ? preferredTime : (options[0] || '');
        timeInput.value = timeChoices.value;
    }

    function showManualTimeInput() {
        if (timeChoices) {
            timeChoices.hidden = true;
            timeChoices.required = false;
        }
        timeInput.style.display = '';
        timeInput.required = true;
    }

    function calendarMonthIsBefore(left, right) {
        return monthValue(left) < monthValue(right);
    }

    function calendarMonthIsAfter(left, right) {
        return monthValue(left) > monthValue(right);
    }

    function renderCalendar() {
        if (!calendarGrid || !availability || availability.booking_type !== 'consultation' || !calendarMonth) return;
        var minDate = parseDateValue(availability.date_min || today);
        var maxDate = parseDateValue(availability.date_max || today);
        if (!minDate || !maxDate) return;
        if (monthLabel) monthLabel.textContent = calendarMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        if (prevMonthButton) prevMonthButton.disabled = calendarMonthIsBefore(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1), new Date(minDate.getFullYear(), minDate.getMonth(), 1));
        if (nextMonthButton) nextMonthButton.disabled = calendarMonthIsAfter(new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1), new Date(maxDate.getFullYear(), maxDate.getMonth(), 1));
        calendarGrid.innerHTML = '';
        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (day) {
            var heading = document.createElement('div');
            heading.className = 'reschedule-calendar-dow';
            heading.textContent = day;
            calendarGrid.appendChild(heading);
        });
        var firstWeekday = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), 1).getDay();
        for (var blank = 0; blank < firstWeekday; blank++) {
            var empty = document.createElement('div');
            empty.className = 'reschedule-calendar-empty';
            calendarGrid.appendChild(empty);
        }
        var daysInMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 0).getDate();
        for (var dayNumber = 1; dayNumber <= daysInMonth; dayNumber++) {
            var date = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), dayNumber);
            var value = dateValue(date);
            var available = dateIsAvailable(value);
            var booked = Number((availability.booked_by_date || {})[value] || 0);
            var limit = Number(availability.daily_limit || 0);
            var full = !available && booked >= limit && limit > 0;
            var dateButton = document.createElement('button');
            dateButton.type = 'button';
            dateButton.className = 'reschedule-calendar-day';
            if (available) dateButton.classList.add('is-available');
            if (full) dateButton.classList.add('is-full');
            if (value === dateInput.value) dateButton.classList.add('is-selected');
            if (value === today) dateButton.classList.add('is-today');
            dateButton.setAttribute('data-date', value);
            dateButton.disabled = !available;
            var number = document.createElement('span');
            number.className = 'reschedule-day-number';
            number.textContent = dayNumber;
            dateButton.appendChild(number);
            var status = document.createElement('span');
            if (available || full) {
                status.className = 'reschedule-day-status ' + (available ? 'available' : 'full');
                status.textContent = available ? 'Available' : 'Fully booked';
                dateButton.appendChild(status);
            }
            if (available) {
                dateButton.addEventListener('click', function () {
                    dateInput.value = this.getAttribute('data-date') || '';
                    var selectedTime = timeChoices && timeChoices.value ? timeChoices.value : timeInput.value;
                    setTimeChoices(dateInput.value, timeIsInDoctorSchedule(dateInput.value, selectedTime) ? selectedTime : '');
                    setSubmitEnabled(updateAvailabilityForSelection());
                    renderCalendar();
                });
            }
            calendarGrid.appendChild(dateButton);
        }
        updateSelectedSummary();
    }

    function updateAvailabilityForSelection() {
        if (!availability || availability.booking_type !== 'consultation') {
            updateTimeMinimum();
            return true;
        }
        var date = dateInput.value;
        if (!dateIsAvailable(date)) {
            dateInput.setCustomValidity('Choose an available date for this doctor.');
            timeInput.setCustomValidity('Choose an available doctor schedule first.');
            var reason = availability.unavailable_reasons && availability.unavailable_reasons[date]
                ? availability.unavailable_reasons[date] : 'This doctor has no available schedule on the selected date.';
            setAvailabilityNote(reason + ' Choose another available date.', 'error');
            return false;
        }
        dateInput.setCustomValidity('');
        updateTimeMinimum();
        var validTime = timeIsInDoctorSchedule(date, timeInput.value)
            && (date !== today || timeInput.value >= minimumTimeToday);
        timeInput.setCustomValidity(validTime ? '' : 'Choose a time within the doctor\'s available schedule.');
        var slots = slotsForDate(date).map(function (slot) { return formatTimeLabel(slot.start) + ' - ' + formatTimeLabel(slot.end); }).join(', ');
        setAvailabilityNote('Doctor schedule: ' + slots + '.', 'ready');
        updateSelectedSummary();
        renderCalendar();
        return validTime;
    }

    function loadAvailability(appointmentId, preferredDate, preferredTime) {
        availability = null;
        setSubmitEnabled(false);
        setAvailabilityNote('Checking the doctor\'s available dates...', 'loading');
        if (availabilityRequest) availabilityRequest.abort();
        availabilityRequest = new AbortController();
        fetch('reschedule_availability.php?appointment_id=' + encodeURIComponent(appointmentId), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: availabilityRequest.signal
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok || !data.ok) throw new Error(data.error || 'The doctor schedule could not be loaded.');
                return data;
            });
        }).then(function (data) {
            availability = data;
            if (['ultrasound', 'package', 'individual'].indexOf(data.booking_type) !== -1
                && Array.isArray(data.available_dates)) {
                if (calendarPanel) calendarPanel.classList.remove('is-visible');
                if (dateField) dateField.style.display = 'none';
                if (dateChoicesField) dateChoicesField.hidden = false;
                if (dateChoices) dateChoices.required = true;
                if (dateLabel) dateLabel.textContent = 'New date';
                dateInput.min = data.date_min || today;
                dateInput.max = data.date_max || '';
                var availableDate = populateDateChoices(preferredDate);
                dateInput.value = availableDate;
                showManualTimeInput();
                if (!availableDate) {
                    setAvailabilityNote(
                        data.booking_type === 'ultrasound'
                            ? 'There are no available future ultrasound dates.'
                            : 'There are no available future laboratory dates.',
                        'error'
                    );
                    setSubmitEnabled(false);
                    return;
                }
                setAvailabilityNote(
                    data.booking_type === 'ultrasound'
                        ? 'Ultrasound is available on Wednesday and Saturday.'
                        : 'Laboratory appointments are available Monday to Saturday.',
                    'ready'
                );
                setSubmitEnabled(true);
                updateTimeMinimum();
                return;
            }
            if (data.booking_type !== 'consultation') {
                if (calendarPanel) calendarPanel.classList.remove('is-visible');
                if (dateField) dateField.style.display = '';
                if (dateChoicesField) dateChoicesField.hidden = true;
                if (dateChoices) dateChoices.required = false;
                if (dateLabel) dateLabel.textContent = 'New date';
                showManualTimeInput();
                setAvailabilityNote('The new schedule will still be checked before saving.', 'ready');
                setSubmitEnabled(true);
                updateTimeMinimum();
                return;
            }
            dateInput.min = data.date_min || today;
            dateInput.max = data.date_max || '';
            if (calendarPanel) calendarPanel.classList.remove('is-visible');
            if (dateField) dateField.style.display = 'none';
            if (dateChoicesField) dateChoicesField.hidden = false;
            if (dateChoices) dateChoices.required = true;
            if (dateLabel) dateLabel.textContent = 'New date';
            var selectedDate = populateDateChoices(preferredDate);
            dateInput.value = selectedDate;
            calendarMonth = parseDateValue(selectedDate || data.date_min || today);
            if (calendarMonth) calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth(), 1);
            var selectedTime = timeIsInDoctorSchedule(selectedDate, preferredTime) ? preferredTime : firstAvailableTime(selectedDate);
            setTimeChoices(selectedDate, selectedTime);
            if (!selectedDate) {
                setAvailabilityNote('There are no available future dates for this doctor.', 'error');
                setSubmitEnabled(false);
                return;
            }
            setSubmitEnabled(updateAvailabilityForSelection());
        }).catch(function (error) {
            if (error.name === 'AbortError') return;
            availability = { booking_type: 'consultation' };
            if (calendarPanel) calendarPanel.classList.remove('is-visible');
            if (dateField) dateField.style.display = '';
            if (dateChoicesField) dateChoicesField.hidden = true;
            if (dateChoices) dateChoices.required = false;
            showManualTimeInput();
            setAvailabilityNote(error.message || 'The doctor schedule could not be loaded. Please refresh and try again.', 'error');
            setSubmitEnabled(false);
        });
    }

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
        if (reasonInput) reasonInput.value = '';
    }
    document.querySelectorAll('[data-open-reschedule]').forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            idInput.value = button.getAttribute('data-appointment-id') || '';
            var currentDate = button.getAttribute('data-reschedule-date') || '';
            var currentTime = (button.getAttribute('data-reschedule-time') || '').slice(0, 5);
            dateInput.value = currentDate && currentDate >= today ? currentDate : today;
            timeInput.value = currentTime;
            if (calendarPanel) calendarPanel.classList.remove('is-visible');
            if (dateField) dateField.style.display = '';
            if (dateChoicesField) dateChoicesField.hidden = true;
            if (dateChoices) dateChoices.required = false;
            showManualTimeInput();
            dateInput.removeAttribute('max');
            dateInput.setCustomValidity('');
            timeInput.setCustomValidity('');
            var current = button.getAttribute('data-reschedule-label') || 'Current appointment schedule';
            var editMode = button.getAttribute('data-reschedule-mode') || 'confirmed';
            if (title) title.textContent = 'Reschedule appointment';
            if (reasonField) reasonField.hidden = editMode === 'pending';
            if (reasonInput) reasonInput.value = '';
            if (notice) notice.textContent = editMode === 'pending'
                ? 'This appointment is not yet confirmed. You can change your schedule before confirmation.'
                : 'This appointment is already confirmed. Your current schedule will remain active until the reschedule request is approved.';
            if (submitButton) submitButton.textContent = editMode === 'pending' ? 'Save Schedule' : 'Submit Request';
            if (currentText) currentText.textContent = current;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            loadAvailability(idInput.value, currentDate, currentTime);
            window.setTimeout(function () {
                if (dateChoicesField && !dateChoicesField.hidden) dateChoices.focus();
                else dateInput.focus();
            }, 50);
        });
    });
    dateInput.addEventListener('change', function () {
        if (availability && availability.booking_type === 'consultation') {
            var selectedTime = timeChoices && timeChoices.value ? timeChoices.value : timeInput.value;
            setTimeChoices(dateInput.value, timeIsInDoctorSchedule(dateInput.value, selectedTime) ? selectedTime : '');
            setSubmitEnabled(updateAvailabilityForSelection());
        } else {
            updateTimeMinimum();
        }
    });
    if (dateChoices) dateChoices.addEventListener('change', function () {
        dateInput.value = dateChoices.value;
        var selectedTime = timeChoices && timeChoices.value ? timeChoices.value : timeInput.value;
        setTimeChoices(dateInput.value, timeIsInDoctorSchedule(dateInput.value, selectedTime) ? selectedTime : '');
        setSubmitEnabled(updateAvailabilityForSelection());
    });
    if (prevMonthButton) prevMonthButton.addEventListener('click', function () {
        if (!calendarMonth || prevMonthButton.disabled) return;
        calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() - 1, 1);
        renderCalendar();
    });
    if (nextMonthButton) nextMonthButton.addEventListener('click', function () {
        if (!calendarMonth || nextMonthButton.disabled) return;
        calendarMonth = new Date(calendarMonth.getFullYear(), calendarMonth.getMonth() + 1, 1);
        renderCalendar();
    });
    timeInput.addEventListener('change', function () {
        if (availability && availability.booking_type === 'consultation') setSubmitEnabled(updateAvailabilityForSelection());
    });
    if (timeChoices) timeChoices.addEventListener('change', function () {
        timeInput.value = timeChoices.value;
        if (availability && availability.booking_type === 'consultation') setSubmitEnabled(updateAvailabilityForSelection());
    });
    form.addEventListener('submit', function (event) {
        if (availability && availability.booking_type === 'consultation' && !updateAvailabilityForSelection()) {
            event.preventDefault();
            dateInput.reportValidity();
            timeInput.reportValidity();
        }
    });
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

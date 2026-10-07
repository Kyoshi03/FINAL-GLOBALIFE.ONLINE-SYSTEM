<?php
require_once 'includes/session.php';
checkAnyRole(['admin', 'patient']);

require_once 'config/database.php';
require_once 'includes/appointment_booking.php';

$pageTitle = 'Clinic Calendar | Globalife Medical Laboratory & Polyclinic';
$currentUser = getCurrentUser();
$currentRole = (string) ($currentUser['role'] ?? '');
$calendarTimezone = new DateTimeZone('Asia/Manila');
$calendarNow = new DateTimeImmutable('now', $calendarTimezone);

// Keep old calendar.php links working while the role-specific pages are used everywhere else.
if (basename($_SERVER['PHP_SELF']) === 'calendar.php') {
    $calendarTarget = $currentRole === 'patient' ? 'patient_calendar.php' : 'admin_calendar.php';
    $calendarQuery = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
    header('Location: ' . $calendarTarget . ($calendarQuery !== '' ? '?' . $calendarQuery : ''));
    exit;
}

function calendar_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function calendar_status(array $appointment): string {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function calendar_status_label(string $status): string {
    return [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$status] ?? 'Pending';
}

function calendar_short_text(string $text, int $limit = 42): string {
    $text = trim($text);
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function calendar_service_label(array $appointment): string {
    $bookingType = strtolower((string) ($appointment['booking_type'] ?? ''));
    if ($bookingType === 'consultation') {
        return 'Doctor consultation';
    }
    if ($bookingType === 'package') {
        return 'Laboratory package';
    }
    if ($bookingType === 'individual') {
        return 'Laboratory tests';
    }
    if ($bookingType === 'ultrasound') {
        return 'Ultra sound';
    }

    $notes = trim((string) ($appointment['notes'] ?? ''));
    if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notes, $matches)) {
        $service = trim($matches[1]);
        if ($service !== '') {
            return calendar_short_text($service);
        }
    }

    return 'Clinic appointment';
}

if ($currentRole === 'patient') {
    $conn = getDBConnection();
    $calendarMonthParam = trim((string) ($_GET['calendar_month'] ?? ''));
    $calendarCurrentMonth = $calendarNow->format('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonthParam) || $calendarMonthParam < $calendarCurrentMonth) {
        $calendarMonthParam = $calendarCurrentMonth;
    }
    $calendarBase = DateTimeImmutable::createFromFormat('!Y-m-d', $calendarMonthParam . '-01') ?: new DateTimeImmutable('first day of this month');
    $calendarMonthStart = $calendarBase->modify('first day of this month');
    $calendarMonthEnd = $calendarBase->modify('first day of next month');
    $calendarGridStart = $calendarMonthStart->modify('-' . (int) $calendarMonthStart->format('w') . ' days');
    $calendarGridEnd = $calendarMonthStart->modify('last day of this month')->modify('+' . (6 - (int) $calendarMonthStart->modify('last day of this month')->format('w')) . ' days');
    $calendarPrevMonth = $calendarMonthStart->modify('-1 month')->format('Y-m');
    $calendarNextMonth = $calendarMonthStart->modify('+1 month')->format('Y-m');
    $calendarToday = $calendarNow->format('Y-m-d');
    $calendarCanGoPrevious = $calendarMonthStart->format('Y-m') > $calendarCurrentMonth;
    $doctorDailyLimit = appointment_doctor_daily_limit();
    $calendarDoctorSlotsByDow = array_fill(1, 7, []);

    $doctorNameSql = dbUsersNameExpression('u');
    $doctorSlotSql = "SELECT u.id, {$doctorNameSql} AS full_name, u.specialty, da.day_of_week
        FROM doctor_availability da
        INNER JOIN users u ON u.id = da.user_id
        WHERE u.role = 'doctor' AND COALESCE(u.is_active, 1) = 1
          AND da.time_start < da.time_end
        ORDER BY da.day_of_week, {$doctorNameSql}";
    $doctorSlotResult = $conn->query($doctorSlotSql);
    if ($doctorSlotResult) {
        while ($slot = $doctorSlotResult->fetch_assoc()) {
            $dow = (int) ($slot['day_of_week'] ?? 0);
            if ($dow < 1 || $dow > 7) {
                continue;
            }
            $doctorId = (int) $slot['id'];
            $calendarDoctorSlotsByDow[$dow][$doctorId] = [
                'id' => $doctorId,
                'doctor' => (string) $slot['full_name'],
                'specialty' => trim((string) ($slot['specialty'] ?? '')) ?: 'Doctor consultation',
            ];
        }
    }

    $calendarDoctorIds = [];
    foreach ($calendarDoctorSlotsByDow as $dow => $slots) {
        $calendarDoctorSlotsByDow[$dow] = array_values($slots);
        foreach ($calendarDoctorSlotsByDow[$dow] as $slot) {
            $calendarDoctorIds[(int) $slot['id']] = true;
        }
    }
    $calendarDoctorDayCounts = appointment_doctor_daily_counts_between(
        $conn,
        $calendarMonthStart->format('Y-m-d'),
        $calendarMonthEnd->format('Y-m-d'),
        array_keys($calendarDoctorIds)
    );
    $conn->close();

    $additionalStyles = '
body {
    background:
        radial-gradient(circle at top right, rgba(72, 202, 228, 0.18), transparent 34%),
        linear-gradient(135deg, #f5fbfd 0%, #eef8fc 100%);
}
.patient-calendar-page {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 18px 40px;
}
.patient-calendar-panel {
    overflow: hidden;
    border: 1px solid #cfe4f1;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 16px 34px rgba(25, 76, 110, .08);
}
.patient-calendar-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 18px;
    border-bottom: 1px solid #d8e8f2;
    background: linear-gradient(180deg, #fff 0%, #f8fcff 100%);
}
.patient-calendar-head h1 {
    margin: 0 0 4px;
    color: #073b4c;
    font-size: 1.35rem;
}
.patient-calendar-head p {
    margin: 0;
    color: #60727d;
    font-size: .9rem;
}
.patient-calendar-nav {
    display: flex;
    align-items: center;
    gap: 10px;
}
.patient-calendar-nav a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: 1px solid #cfe4f1;
    border-radius: 10px;
    color: #0077b6;
    text-decoration: none;
    font-size: 1.35rem;
    font-weight: 900;
    background: #f7fbff;
}
.patient-calendar-nav-disabled {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: 1px solid #d8e8f2;
    border-radius: 8px;
    color: #a7bbc7;
    font-size: 1.35rem;
    font-weight: 900;
    background: #f7fbff;
    cursor: not-allowed;
    opacity: .65;
}
.patient-calendar-month {
    min-width: 160px;
    color: #073b4c;
    font-weight: 950;
    text-align: center;
}
.availability-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(150px, 1fr));
    min-width: 980px;
}
.availability-dow,
.availability-day {
    border-right: 1px solid #e0edf5;
    border-bottom: 1px solid #e0edf5;
}
.availability-dow {
    padding: 11px 8px;
    background: #f6fbff;
    color: #5a6f7d;
    text-align: center;
    font-size: .84rem;
    font-weight: 950;
    letter-spacing: .02em;
    text-transform: uppercase;
}
.availability-day {
    position: relative;
    min-height: 112px;
    padding: 8px 6px;
    background: #fbfdff;
}
.availability-day.outside-month {
    background: #f7fafc;
    color: #96a6b0;
}
.availability-day.is-today {
    background: #f4fffc;
    box-shadow: inset 0 3px 0 #66d0bd;
}
.availability-date {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 26px;
    margin-bottom: 5px;
    border-radius: 999px;
    background: #e8f8f5;
    color: #006b64;
    font-weight: 950;
}
.availability-slot,
.availability-more,
.availability-closed {
    display: block;
    width: 100%;
    margin-top: 4px;
    border-radius: 7px;
    cursor: default;
}
.availability-slot {
    border: 1px solid #97d9cf;
    border-left: 3px solid #0ea58e;
    background: #eafaf6;
    padding: 5px 5px;
    color: #073b4c;
    line-height: 1.06;
}
.availability-specialty,
.availability-doctor,
.availability-count {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.availability-specialty {
    font-size: .59rem;
    font-weight: 950;
    text-transform: uppercase;
}
.availability-doctor {
    margin-top: 1px;
    font-size: .62rem;
    font-weight: 850;
}
.availability-count {
    margin-top: 1px;
    color: #0b5d5a;
    font-size: .58rem;
    font-weight: 950;
}
.availability-more {
    cursor: help;
}
.availability-doctor-popover {
    position: absolute;
    z-index: 20;
    left: 6px;
    top: calc(100% + 5px);
    display: none;
    width: max-content;
    min-width: 190px;
    max-width: 240px;
    padding: 9px 10px;
    border: 1px solid #b9d9eb;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 12px 24px rgba(7, 59, 76, .18);
    color: #073b4c;
    text-align: left;
    white-space: normal;
}
.availability-day.availability-popover-above > .availability-doctor-popover {
    top: auto;
    bottom: calc(100% + 5px);
}
.availability-day:hover {
    z-index: 30;
}
.availability-day:hover > .availability-doctor-popover,
.availability-day:focus-within > .availability-doctor-popover {
    display: block;
}
.availability-doctor-popover strong {
    display: block;
    margin-bottom: 5px;
    color: #0878b5;
    font-size: .66rem;
    text-transform: uppercase;
}
.availability-doctor-entry {
    display: block;
    padding: 4px 0;
    border-top: 1px solid #e2eff6;
    font-size: .68rem;
    line-height: 1.2;
}
.availability-doctor-entry:first-of-type {
    border-top: 0;
}
.availability-doctor-entry b,
.availability-doctor-entry small {
    display: block;
}
.availability-doctor-entry b {
    font-size: .7rem;
}
.availability-doctor-entry small {
    margin-top: 2px;
    color: #60727d;
    font-size: .62rem;
}
.availability-slot.is-full {
    border-color: #f0b5bd;
    border-left-color: #d94150;
    background: #fff4f5;
}
.availability-more,
.availability-closed {
    border: 1px solid #cfe4f1;
    background: #f3fbff;
    padding: 5px 5px;
    color: #0077b6;
    font-size: .62rem;
    font-weight: 950;
}
.availability-closed {
    color: #6f8290;
    background: #f8fafc;
}
.availability-scroll {
    overflow-x: auto;
}
@media (max-width: 760px) {
    .patient-calendar-head {
        align-items: stretch;
        flex-direction: column;
    }
    .patient-calendar-nav {
        justify-content: space-between;
    }
}
';

    include 'includes/header.php';
    ?>
    <main class="patient-calendar-page">
        <section class="patient-calendar-panel" aria-label="Doctor availability calendar">
            <div class="patient-calendar-head">
                <div>
                    <h1>Clinic calendar</h1>
                    <p>Doctor consultation availability only. Book Appointment is now handled in steps.</p>
                </div>
                <div class="patient-calendar-nav" aria-label="Month navigation">
                    <?php if ($calendarCanGoPrevious): ?>
                        <a href="patient_calendar.php?calendar_month=<?php echo htmlspecialchars($calendarPrevMonth); ?>" aria-label="Previous month">&lsaquo;</a>
                    <?php else: ?>
                        <span class="patient-calendar-nav-disabled" aria-disabled="true" aria-label="Previous month unavailable">&lsaquo;</span>
                    <?php endif; ?>
                    <strong class="patient-calendar-month"><?php echo htmlspecialchars($calendarMonthStart->format('F Y')); ?></strong>
                    <a href="patient_calendar.php?calendar_month=<?php echo htmlspecialchars($calendarNextMonth); ?>" aria-label="Next month">&rsaquo;</a>
                </div>
            </div>
            <div class="availability-scroll">
                <div class="availability-grid">
                    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                        <div class="availability-dow"><?php echo htmlspecialchars($weekday); ?></div>
                    <?php endforeach; ?>
                    <?php for ($cursor = $calendarGridStart; $cursor <= $calendarGridEnd; $cursor = $cursor->modify('+1 day')): ?>
                        <?php
                        $dateValue = $cursor->format('Y-m-d');
                        $dateDayOfWeek = (int) $cursor->format('N');
                        $clinicOpen = $dateDayOfWeek >= 1 && $dateDayOfWeek <= 6;
                        $doctorSlots = $calendarDoctorSlotsByDow[$dateDayOfWeek] ?? [];
                        $isCurrentMonth = $cursor->format('m') === $calendarMonthStart->format('m');
                        $isPastDate = $dateValue < $calendarToday;
                        ?>
                        <div class="availability-day <?php echo !$isCurrentMonth ? 'outside-month' : ''; ?> <?php echo $dateValue === $calendarToday ? 'is-today' : ''; ?>">
                            <?php if ($isCurrentMonth && !$isPastDate): ?>
                            <span class="availability-date"><?php echo htmlspecialchars($cursor->format('j')); ?></span>
                            <?php if (!$clinicOpen): ?>
                                <span class="availability-closed">Clinic closed</span>
                            <?php else: ?>
                                <?php foreach (array_slice($doctorSlots, 0, 1) as $slot): ?>
                                    <?php
                                    $booked = (int) ($calendarDoctorDayCounts[(int) $slot['id']][$dateValue] ?? 0);
                                    $isFull = $booked >= $doctorDailyLimit;
                                    ?>
                                    <span class="availability-slot <?php echo $isFull ? 'is-full' : ''; ?>">
                                        <strong class="availability-specialty"><?php echo htmlspecialchars((string) $slot['specialty']); ?></strong>
                                        <span class="availability-doctor"><?php echo htmlspecialchars((string) $slot['doctor']); ?></span>
                                        <span class="availability-count"><?php echo $isFull ? 'Fully booked' : $booked . '/' . $doctorDailyLimit . ' booked'; ?></span>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($doctorSlots) > 1): ?>
                                    <div class="availability-more" tabindex="0" aria-label="Show doctors assigned on this day">
                                        +<?php echo count($doctorSlots) - 1; ?> more doctors
                                    </div>
                                <?php elseif (empty($doctorSlots)): ?>
                                    <span class="availability-closed">No doctor schedule</span>
                                <?php endif; ?>
                                <?php if (!empty($doctorSlots)): ?>
                                    <div class="availability-doctor-popover" role="tooltip">
                                        <strong>Doctors available</strong>
                                        <?php foreach ($doctorSlots as $assignedDoctor): ?>
                                            <span class="availability-doctor-entry">
                                                <b><?php echo htmlspecialchars((string) $assignedDoctor['doctor']); ?></b>
                                                <small><?php echo htmlspecialchars((string) $assignedDoctor['specialty']); ?></small>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </section>
    </main>
    <script>
    (function () {
        var calendarGrid = document.querySelector('.availability-grid');
        if (!calendarGrid) return;

        var calendarDays = Array.prototype.slice.call(calendarGrid.querySelectorAll('.availability-day'));
        function updatePopoverPosition(day) {
            var popover = day.querySelector('.availability-doctor-popover');
            if (!popover) return;
            var dayRect = day.getBoundingClientRect();
            var gridRect = calendarGrid.getBoundingClientRect();
            day.classList.toggle('availability-popover-above', dayRect.bottom > gridRect.top + (gridRect.height * 0.58));
        }

        calendarDays.forEach(function (day) {
            if (!day.querySelector('.availability-doctor-popover')) return;
            day.addEventListener('mouseenter', function () { updatePopoverPosition(day); });
            day.addEventListener('focusin', function () { updatePopoverPosition(day); });
            day.addEventListener('mouseleave', function () {
                day.classList.remove('availability-popover-above');
            });
        });

        window.addEventListener('resize', function () {
            calendarDays.forEach(function (day) {
                if (day.matches(':hover') || day.matches(':focus-within')) updatePopoverPosition(day);
            });
        });
    }());
    </script>
    <?php
    include 'includes/footer.php';
    exit;
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT a.*,
                        " . dbUsersNameExpression('p') . " AS patient_name,
                        p.phone AS patient_phone,
                        " . dbUsersNameExpression('d') . " AS doctor_name
                        FROM appointments a
                        JOIN users p ON a.patient_id = p.id
                        LEFT JOIN users d ON a.doctor_id = d.id
                        ORDER BY a.appointment_date ASC, a.appointment_time ASC");
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$today = $calendarNow->format('Y-m-d');
$calendarCurrentMonth = $calendarNow->format('Y-m');
$calendarMonthParam = trim((string) ($_GET['calendar_month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonthParam) || $calendarMonthParam < $calendarCurrentMonth) {
    $calendarMonthParam = $calendarCurrentMonth;
}
$calendarMonth = DateTimeImmutable::createFromFormat('!Y-m-d', $calendarMonthParam . '-01') ?: new DateTimeImmutable('first day of this month');
$calendarMonthStart = $calendarMonth->modify('first day of this month');
$calendarMonthEnd = $calendarMonth->modify('last day of this month');
$calendarGridStart = $calendarMonthStart->modify('-' . (int) $calendarMonthStart->format('w') . ' days');
$calendarGridEnd = $calendarMonthEnd->modify('+' . (6 - (int) $calendarMonthEnd->format('w')) . ' days');

$calendarSelectedDate = trim((string) ($_GET['calendar_date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calendarSelectedDate) || $calendarSelectedDate < $today) {
    $calendarSelectedDate = $today;
}
$calendarSelected = DateTimeImmutable::createFromFormat('!Y-m-d', $calendarSelectedDate) ?: new DateTimeImmutable($today);
$calendarView = strtolower(trim((string) ($_GET['calendar_view'] ?? 'month')));
if (!in_array($calendarView, ['day', 'week', 'month'], true)) {
    $calendarView = 'month';
}

$calendarCanGoPrevious = $calendarMonthStart->format('Y-m') > $calendarCurrentMonth;
$previousMonthUrl = 'admin_calendar.php?calendar_month=' . $calendarMonthStart->modify('-1 month')->format('Y-m') . '&calendar_view=' . $calendarView;
$nextMonthUrl = 'admin_calendar.php?calendar_month=' . $calendarMonthStart->modify('+1 month')->format('Y-m') . '&calendar_view=' . $calendarView;
$todayUrl = 'admin_calendar.php?calendar_view=' . $calendarView;

$appointmentsByDate = [];
$statusTotals = ['pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($appointments as $appointment) {
    $dateKey = (string) ($appointment['appointment_date'] ?? '');
    $status = calendar_status($appointment);
    $statusTotals[$status]++;
    if ($dateKey !== '') {
        $appointmentsByDate[$dateKey][] = $appointment;
    }
}
foreach ($appointmentsByDate as &$dateAppointments) {
    usort($dateAppointments, function (array $a, array $b): int {
        return strcmp((string) ($a['appointment_time'] ?? ''), (string) ($b['appointment_time'] ?? ''));
    });
}
unset($dateAppointments);

$calendarDays = [];
for ($cursor = $calendarGridStart; $cursor <= $calendarGridEnd; $cursor = $cursor->modify('+1 day')) {
    $dateKey = $cursor->format('Y-m-d');
    $calendarDays[] = [
        'date' => $dateKey,
        'day' => $cursor,
        'appointments' => $appointmentsByDate[$dateKey] ?? [],
        'outside_month' => $cursor->format('m') !== $calendarMonthStart->format('m'),
        'is_today' => $dateKey === $today,
        'is_past' => $dateKey < $today,
    ];
}

$weekStart = $calendarSelected->modify('-' . (int) $calendarSelected->format('w') . ' days');
$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $day = $weekStart->modify('+' . $i . ' days');
    $dateKey = $day->format('Y-m-d');
    $weekDays[] = [
        'date' => $dateKey,
        'day' => $day,
        'appointments' => $appointmentsByDate[$dateKey] ?? [],
        'is_today' => $dateKey === $today,
    ];
}
$dayAppointments = $appointmentsByDate[$calendarSelected->format('Y-m-d')] ?? [];

$additionalStyles = '
body {
    background:
        radial-gradient(circle at top right, rgba(72, 202, 228, 0.18), transparent 34%),
        linear-gradient(135deg, #f5fbfd 0%, #eef8fc 100%);
}
.clinic-calendar-page {
    max-width: 1220px;
    margin: 0 auto;
    padding: 34px 20px 58px;
}
.calendar-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    border: 1px solid #d7eaf4;
    border-radius: 10px;
    padding: 26px;
    background:
        radial-gradient(circle at 92% 12%, rgba(72, 202, 228, 0.24), transparent 28%),
        linear-gradient(135deg, #ffffff 0%, #eefaff 100%);
    box-shadow: 0 18px 38px rgba(2, 62, 138, 0.08);
}
.calendar-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #0077b6;
    font-size: .84rem;
    font-weight: 950;
    text-transform: uppercase;
}
.calendar-kicker svg,
.calendar-card-icon svg {
    fill: none;
    stroke: currentColor;
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.calendar-hero h1 {
    margin: 8px 0 6px;
    color: #073b4c;
    font-size: 2rem;
    line-height: 1.1;
}
.calendar-hero p {
    margin: 0;
    color: #58707d;
    line-height: 1.55;
}
.calendar-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    gap: 10px;
    min-width: min(520px, 100%);
}
.calendar-stat {
    border: 1px solid #dcecf3;
    border-radius: 10px;
    padding: 13px;
    background: rgba(255,255,255,.84);
}
.calendar-stat span {
    display: block;
    color: #60727d;
    font-size: .76rem;
    font-weight: 950;
    text-transform: uppercase;
}
.calendar-stat strong {
    display: block;
    margin-top: 6px;
    color: #004b76;
    font-size: 1.7rem;
    line-height: 1;
}
.calendar-panel {
    margin-top: 18px;
    border: 1px solid #d7eaf4;
    border-radius: 10px;
    background: #ffffff;
    box-shadow: 0 16px 34px rgba(2, 62, 138, 0.07);
    overflow: hidden;
}
.calendar-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 18px;
    border-bottom: 1px solid #dcecf3;
    background: #fbfdff;
}
.calendar-tabs,
.calendar-month-nav,
.calendar-legend {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.calendar-tab,
.calendar-month-nav a,
.calendar-month-nav .calendar-nav-disabled,
.calendar-today-link {
    min-height: 38px;
    border: 1px solid #d7e8f2;
    border-radius: 999px;
    background: #f8fbff;
    color: #0b4f80;
    padding: 0 14px;
    display: inline-grid;
    place-items: center;
    font-weight: 950;
    text-decoration: none;
}
.calendar-month-nav .calendar-nav-disabled {
    color: #b4c3cc;
    background: #f4f8fb;
    cursor: not-allowed;
    pointer-events: none;
}
.calendar-tab.active {
    background: #0077b6;
    border-color: #0077b6;
    color: #ffffff;
    box-shadow: 0 10px 22px rgba(0,119,182,.20);
}
.calendar-month-label {
    color: #073b4c;
    font-size: 1.08rem;
    font-weight: 950;
}
.calendar-legend {
    padding: 0 18px 18px;
    justify-content: flex-end;
}
.calendar-legend span {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-height: 30px;
    border: 1px solid #dceaf1;
    border-radius: 999px;
    background: #fff;
    color: #47606d;
    padding: 5px 11px;
    font-size: .8rem;
    font-weight: 900;
}
.calendar-legend i {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.dot-pending { background: #e3a31a; }
.dot-confirmed { background: #0f7cc2; }
.dot-completed { background: #1f9d61; }
.dot-cancelled { background: #d94150; }
.calendar-content {
    padding: 18px;
}
.calendar-scroll {
    overflow-x: auto;
}
.month-grid {
    min-width: 820px;
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    border: 1px solid #dbe8f0;
    border-radius: 16px;
    overflow: hidden;
    background: #dbe8f0;
    gap: 1px;
}
.month-weekday {
    min-height: 36px;
    display: grid;
    place-items: center;
    background: #f3f8fb;
    color: #60727d;
    font-size: .76rem;
    font-weight: 950;
    text-transform: uppercase;
}
.month-day {
    min-height: 126px;
    background: #fff;
    padding: 10px;
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.month-day.outside-month {
    background: #f4f8fb;
    color: #9aaab3;
}
.day-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    color: #073b4c;
    font-weight: 950;
    font-size: .9rem;
}
.month-day.is-today .day-number {
    background: #dff4ff;
    color: #0077b6;
}
.calendar-event {
    display: block;
    border: 1px solid #cfe4f1;
    border-left-width: 4px;
    border-radius: 10px;
    background: #f8fcff;
    color: #123244;
    padding: 7px 8px;
    text-decoration: none;
    box-shadow: 0 6px 14px rgba(25, 76, 110, 0.04);
    cursor: default;
}
.calendar-event strong,
.calendar-event span {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.calendar-event strong {
    color: #073b4c;
    font-size: .78rem;
    line-height: 1.2;
}
.calendar-event span {
    margin-top: 3px;
    color: #60727d;
    font-size: .68rem;
    font-weight: 800;
}
.calendar-event.pending { border-left-color: #e3a31a; background: #fffaf0; }
.calendar-event.confirmed { border-left-color: #0f7cc2; background: #eef8ff; }
.calendar-event.completed { border-left-color: #1f9d61; background: #f0fbf4; }
.calendar-event.cancelled { border-left-color: #d94150; background: #fff4f5; }
.calendar-more {
    color: #0077b6;
    font-size: .78rem;
    font-weight: 950;
}
.week-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(150px, 1fr));
    gap: 10px;
    min-width: 980px;
}
.week-day-card,
.day-card {
    border: 1px solid #dbe8f0;
    border-radius: 14px;
    padding: 12px;
    background: #fdfefe;
}
.week-day-card.is-today {
    border-color: #83d9ef;
    background: #f3fbff;
}
.week-day-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
    color: #073b4c;
    font-weight: 950;
}
.day-list {
    display: grid;
    gap: 10px;
}
.day-card {
    display: grid;
    grid-template-columns: 92px minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
}
.day-time {
    display: inline-grid;
    place-items: center;
    min-height: 44px;
    border-radius: 12px;
    background: #eef8ff;
    color: #005f99;
    font-weight: 950;
}
.calendar-empty {
    border: 1px dashed #b9d9eb;
    border-radius: 12px;
    padding: 28px 16px;
    text-align: center;
    color: #60727d;
    background: #fbfdff;
}
@media (max-width: 820px) {
    .clinic-calendar-page {
        padding: 22px 12px 120px;
    }
    .calendar-hero,
    .calendar-toolbar {
        align-items: stretch;
        flex-direction: column;
    }
    .calendar-summary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        min-width: 0;
    }
    .calendar-legend {
        justify-content: flex-start;
    }
    .day-card {
        grid-template-columns: 1fr;
        align-items: stretch;
    }
}
';

include 'includes/header.php';
?>
<main class="clinic-calendar-page">
    <section class="calendar-hero" aria-labelledby="calendarTitle">
        <div>
            <span class="calendar-kicker">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M8 3v3M16 3v3M5 8h14M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
                Appointment calendar
            </span>
            <h1 id="calendarTitle">Clinic appointment calendar</h1>
            <p>View clinic appointments by day, week, or month.</p>
        </div>
        <div class="calendar-summary" aria-label="Appointment summary">
            <div class="calendar-stat"><span>Pending</span><strong><?php echo (int) $statusTotals['pending']; ?></strong></div>
            <div class="calendar-stat"><span>Confirmed</span><strong><?php echo (int) $statusTotals['confirmed']; ?></strong></div>
            <div class="calendar-stat"><span>Completed</span><strong><?php echo (int) $statusTotals['completed']; ?></strong></div>
            <div class="calendar-stat"><span>Cancelled</span><strong><?php echo (int) $statusTotals['cancelled']; ?></strong></div>
        </div>
    </section>

    <section class="calendar-panel" aria-label="Calendar">
        <div class="calendar-toolbar">
            <div class="calendar-tabs" aria-label="Calendar view">
                <a class="calendar-tab <?php echo $calendarView === 'day' ? 'active' : ''; ?>" href="admin_calendar.php?calendar_view=day&calendar_month=<?php echo htmlspecialchars($calendarMonthStart->format('Y-m')); ?>">Day</a>
                <a class="calendar-tab <?php echo $calendarView === 'week' ? 'active' : ''; ?>" href="admin_calendar.php?calendar_view=week&calendar_month=<?php echo htmlspecialchars($calendarMonthStart->format('Y-m')); ?>">Week</a>
                <a class="calendar-tab <?php echo $calendarView === 'month' ? 'active' : ''; ?>" href="admin_calendar.php?calendar_view=month&calendar_month=<?php echo htmlspecialchars($calendarMonthStart->format('Y-m')); ?>">Month</a>
            </div>
            <div class="calendar-month-nav" aria-label="Month navigation">
                <?php if ($calendarCanGoPrevious): ?>
                    <a href="<?php echo htmlspecialchars($previousMonthUrl); ?>" aria-label="Previous month">&larr;</a>
                <?php else: ?>
                    <span class="calendar-nav-disabled" aria-disabled="true" aria-label="Previous month unavailable">&larr;</span>
                <?php endif; ?>
                <strong class="calendar-month-label"><?php echo htmlspecialchars($calendarMonthStart->format('F Y')); ?></strong>
                <a href="<?php echo htmlspecialchars($nextMonthUrl); ?>" aria-label="Next month">&rarr;</a>
                <a class="calendar-today-link" href="<?php echo htmlspecialchars($todayUrl); ?>">Today</a>
            </div>
        </div>
        <div class="calendar-legend" aria-label="Appointment status legend">
            <span><i class="dot-pending"></i> Pending</span>
            <span><i class="dot-confirmed"></i> Confirmed</span>
            <span><i class="dot-completed"></i> Completed</span>
            <span><i class="dot-cancelled"></i> Cancelled</span>
        </div>

        <div class="calendar-content">
            <?php if ($calendarView === 'day'): ?>
                <h2><?php echo htmlspecialchars($calendarSelected->format('F d, Y')); ?></h2>
                <?php if (empty($dayAppointments)): ?>
                    <div class="calendar-empty">No appointments scheduled for this day.</div>
                <?php else: ?>
                    <div class="day-list">
                        <?php foreach ($dayAppointments as $appointment): ?>
                            <?php $status = calendar_status($appointment); ?>
                            <div class="day-card calendar-event <?php echo htmlspecialchars($status); ?>">
                                <span class="day-time"><?php echo calendar_time_label($appointment['appointment_time'] ?? ''); ?></span>
                                <span>
                                    <strong><?php echo htmlspecialchars((string) ($appointment['patient_name'] ?? 'Patient')); ?></strong>
                                    <span><?php echo htmlspecialchars(calendar_service_label($appointment)); ?><?php echo !empty($appointment['doctor_name']) ? ' | ' . htmlspecialchars((string) $appointment['doctor_name']) : ''; ?></span>
                                </span>
                                <span><?php echo htmlspecialchars(calendar_status_label($status)); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php elseif ($calendarView === 'week'): ?>
                <div class="calendar-scroll">
                    <div class="week-grid">
                        <?php foreach ($weekDays as $weekDay): ?>
                            <div class="week-day-card <?php echo $weekDay['is_today'] ? 'is-today' : ''; ?>">
                                <div class="week-day-head">
                                    <strong><?php echo htmlspecialchars($weekDay['day']->format('D')); ?></strong>
                                    <span><?php echo htmlspecialchars($weekDay['day']->format('M j')); ?></span>
                                </div>
                                <?php if (empty($weekDay['appointments'])): ?>
                                    <div class="calendar-empty">No bookings</div>
                                <?php else: ?>
                                    <?php foreach (array_slice($weekDay['appointments'], 0, 5) as $appointment): ?>
                                        <?php $status = calendar_status($appointment); ?>
                                        <div class="calendar-event <?php echo htmlspecialchars($status); ?>">
                                            <strong><?php echo htmlspecialchars((string) ($appointment['patient_name'] ?? 'Patient')); ?></strong>
                                            <span><?php echo calendar_time_label($appointment['appointment_time'] ?? ''); ?> | <?php echo htmlspecialchars(calendar_status_label($status)); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($weekDay['appointments']) > 5): ?>
                                        <div class="calendar-more">+<?php echo count($weekDay['appointments']) - 5; ?> more</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="calendar-scroll">
                    <div class="month-grid">
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                            <div class="month-weekday"><?php echo htmlspecialchars($weekday); ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($calendarDays as $calendarDay): ?>
                            <div class="month-day <?php echo $calendarDay['outside_month'] ? 'outside-month' : ''; ?> <?php echo $calendarDay['is_today'] ? 'is-today' : ''; ?>">
                                <?php if (!$calendarDay['is_past']): ?>
                                    <span class="day-number"><?php echo htmlspecialchars($calendarDay['day']->format('j')); ?></span>
                                    <?php foreach (array_slice($calendarDay['appointments'], 0, 3) as $appointment): ?>
                                        <?php $status = calendar_status($appointment); ?>
                                        <div class="calendar-event <?php echo htmlspecialchars($status); ?>">
                                            <strong><?php echo htmlspecialchars((string) ($appointment['patient_name'] ?? 'Patient')); ?></strong>
                                            <span><?php echo calendar_time_label($appointment['appointment_time'] ?? ''); ?> | <?php echo htmlspecialchars(calendar_status_label($status)); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($calendarDay['appointments']) > 3): ?>
                                        <div class="calendar-more">+<?php echo count($calendarDay['appointments']) - 3; ?> more</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>

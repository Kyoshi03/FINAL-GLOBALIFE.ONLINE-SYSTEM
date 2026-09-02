<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';

$pageTitle = 'Clinic Calendar | Globalife Medical Laboratory & Polyclinic';

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
        'cancelled' => 'Declined',
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

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT a.*,
                        p.full_name AS patient_name,
                        p.phone AS patient_phone,
                        d.full_name AS doctor_name
                        FROM appointments a
                        JOIN users p ON a.patient_id = p.id
                        LEFT JOIN users d ON a.doctor_id = d.id
                        ORDER BY a.appointment_date ASC, a.appointment_time ASC");
$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

$today = date('Y-m-d');
$calendarMonthParam = trim((string) ($_GET['calendar_month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonthParam)) {
    $calendarMonthParam = date('Y-m');
}
$calendarMonth = DateTimeImmutable::createFromFormat('!Y-m-d', $calendarMonthParam . '-01') ?: new DateTimeImmutable('first day of this month');
$calendarMonthStart = $calendarMonth->modify('first day of this month');
$calendarMonthEnd = $calendarMonth->modify('last day of this month');
$calendarGridStart = $calendarMonthStart->modify('-' . (int) $calendarMonthStart->format('w') . ' days');
$calendarGridEnd = $calendarMonthEnd->modify('+' . (6 - (int) $calendarMonthEnd->format('w')) . ' days');

$calendarSelectedDate = trim((string) ($_GET['calendar_date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calendarSelectedDate)) {
    $calendarSelectedDate = $today;
}
$calendarSelected = DateTimeImmutable::createFromFormat('!Y-m-d', $calendarSelectedDate) ?: new DateTimeImmutable($today);
$calendarView = strtolower(trim((string) ($_GET['calendar_view'] ?? 'month')));
if (!in_array($calendarView, ['day', 'week', 'month'], true)) {
    $calendarView = 'month';
}

$previousMonthUrl = 'calendar.php?calendar_month=' . $calendarMonthStart->modify('-1 month')->format('Y-m') . '&calendar_view=' . $calendarView;
$nextMonthUrl = 'calendar.php?calendar_month=' . $calendarMonthStart->modify('+1 month')->format('Y-m') . '&calendar_view=' . $calendarView;
$todayUrl = 'calendar.php?calendar_view=' . $calendarView;

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
                Reception calendar
            </span>
            <h1 id="calendarTitle">Clinic appointment calendar</h1>
            <p>View front desk bookings by day, week, or month.</p>
        </div>
        <div class="calendar-summary" aria-label="Appointment summary">
            <div class="calendar-stat"><span>Pending</span><strong><?php echo (int) $statusTotals['pending']; ?></strong></div>
            <div class="calendar-stat"><span>Confirmed</span><strong><?php echo (int) $statusTotals['confirmed']; ?></strong></div>
            <div class="calendar-stat"><span>Completed</span><strong><?php echo (int) $statusTotals['completed']; ?></strong></div>
            <div class="calendar-stat"><span>Declined</span><strong><?php echo (int) $statusTotals['cancelled']; ?></strong></div>
        </div>
    </section>

    <section class="calendar-panel" aria-label="Calendar">
        <div class="calendar-toolbar">
            <div class="calendar-tabs" aria-label="Calendar view">
                <a class="calendar-tab <?php echo $calendarView === 'day' ? 'active' : ''; ?>" href="calendar.php?calendar_view=day&calendar_month=<?php echo htmlspecialchars($calendarMonthStart->format('Y-m')); ?>">Day</a>
                <a class="calendar-tab <?php echo $calendarView === 'week' ? 'active' : ''; ?>" href="calendar.php?calendar_view=week&calendar_month=<?php echo htmlspecialchars($calendarMonthStart->format('Y-m')); ?>">Week</a>
                <a class="calendar-tab <?php echo $calendarView === 'month' ? 'active' : ''; ?>" href="calendar.php?calendar_view=month&calendar_month=<?php echo htmlspecialchars($calendarMonthStart->format('Y-m')); ?>">Month</a>
            </div>
            <div class="calendar-month-nav" aria-label="Month navigation">
                <a href="<?php echo htmlspecialchars($previousMonthUrl); ?>" aria-label="Previous month">&larr;</a>
                <strong class="calendar-month-label"><?php echo htmlspecialchars($calendarMonthStart->format('F Y')); ?></strong>
                <a href="<?php echo htmlspecialchars($nextMonthUrl); ?>" aria-label="Next month">&rarr;</a>
                <a class="calendar-today-link" href="<?php echo htmlspecialchars($todayUrl); ?>">Today</a>
            </div>
        </div>
        <div class="calendar-legend" aria-label="Appointment status legend">
            <span><i class="dot-pending"></i> Pending</span>
            <span><i class="dot-confirmed"></i> Confirmed</span>
            <span><i class="dot-completed"></i> Completed</span>
            <span><i class="dot-cancelled"></i> Declined</span>
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
                            <a class="day-card calendar-event <?php echo htmlspecialchars($status); ?>" href="view_appointments.php?highlight=<?php echo (int) ($appointment['id'] ?? 0); ?>">
                                <span class="day-time"><?php echo calendar_time_label($appointment['appointment_time'] ?? ''); ?></span>
                                <span>
                                    <strong><?php echo htmlspecialchars((string) ($appointment['patient_name'] ?? 'Patient')); ?></strong>
                                    <span><?php echo htmlspecialchars(calendar_service_label($appointment)); ?><?php echo !empty($appointment['doctor_name']) ? ' | ' . htmlspecialchars((string) $appointment['doctor_name']) : ''; ?></span>
                                </span>
                                <span><?php echo htmlspecialchars(calendar_status_label($status)); ?></span>
                            </a>
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
                                        <a class="calendar-event <?php echo htmlspecialchars($status); ?>" href="view_appointments.php?highlight=<?php echo (int) ($appointment['id'] ?? 0); ?>">
                                            <strong><?php echo htmlspecialchars((string) ($appointment['patient_name'] ?? 'Patient')); ?></strong>
                                            <span><?php echo calendar_time_label($appointment['appointment_time'] ?? ''); ?> | <?php echo htmlspecialchars(calendar_status_label($status)); ?></span>
                                        </a>
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
                                <span class="day-number"><?php echo htmlspecialchars($calendarDay['day']->format('j')); ?></span>
                                <?php foreach (array_slice($calendarDay['appointments'], 0, 3) as $appointment): ?>
                                    <?php $status = calendar_status($appointment); ?>
                                    <a class="calendar-event <?php echo htmlspecialchars($status); ?>" href="view_appointments.php?highlight=<?php echo (int) ($appointment['id'] ?? 0); ?>">
                                        <strong><?php echo htmlspecialchars((string) ($appointment['patient_name'] ?? 'Patient')); ?></strong>
                                        <span><?php echo calendar_time_label($appointment['appointment_time'] ?? ''); ?> | <?php echo htmlspecialchars(calendar_status_label($status)); ?></span>
                                    </a>
                                <?php endforeach; ?>
                                <?php if (count($calendarDay['appointments']) > 3): ?>
                                    <div class="calendar-more">+<?php echo count($calendarDay['appointments']) - 3; ?> more</div>
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

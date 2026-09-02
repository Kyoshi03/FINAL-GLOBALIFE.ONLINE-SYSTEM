<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/doctor_schedule.php';
require_once __DIR__ . '/includes/appointment_booking.php';

$pageTitle = 'Reception Desk | Globalife Administration';
$currentUser = getCurrentUser();
$allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['calendar']) || isset($_GET['calendar_month']) || isset($_GET['calendar_date']) || isset($_GET['calendar_view']))) {
    $calendarQuery = [];
    foreach (['calendar_month', 'calendar_date', 'calendar_view'] as $calendarKey) {
        if (isset($_GET[$calendarKey]) && trim((string) $_GET[$calendarKey]) !== '') {
            $calendarQuery[$calendarKey] = trim((string) $_GET[$calendarKey]);
        }
    }
    $calendarLocation = 'calendar.php' . (!empty($calendarQuery) ? '?' . http_build_query($calendarQuery) : '');
    header('Location: ' . $calendarLocation);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);
    $newStatus = strtolower(trim((string) ($_POST['status'] ?? '')));

    if ($appointmentId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
        $_SESSION['error'] = 'Invalid appointment update.';
    } else {
        $conn = getDBConnection();
        $oldStatusStmt = $conn->prepare('SELECT status FROM appointments WHERE id = ? LIMIT 1');
        $oldStatusStmt->bind_param('i', $appointmentId);
        $oldStatusStmt->execute();
        $oldStatus = (string) ($oldStatusStmt->get_result()->fetch_assoc()['status'] ?? '');
        $oldStatusStmt->close();

        $updateStmt = $conn->prepare('UPDATE appointments SET status = ? WHERE id = ?');
        $updateStmt->bind_param('si', $newStatus, $appointmentId);

        if ($updateStmt->execute()) {
            $_SESSION['success'] = 'Appointment status updated.';
            if ($newStatus !== $oldStatus) {
                create_patient_appointment_notification($conn, $appointmentId, $newStatus);
                create_clinic_appointment_notification($conn, $appointmentId, $newStatus);
                create_admin_appointment_notification($conn, $appointmentId, $newStatus);
            }
            if ($newStatus === 'confirmed' && $oldStatus !== 'confirmed') {
                $emailResult = appointment_send_clinic_confirmation_email($conn, $appointmentId);
                if (!$emailResult['ok']) {
                    $_SESSION['success'] .= ' The status was saved, but the confirmation email could not be delivered.';
                }
            }
        } else {
            $_SESSION['error'] = 'Error updating appointment status.';
        }

        $updateStmt->close();
        $conn->close();
    }

    header('Location: receptionist.php');
    exit();
}

function receptionist_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function receptionist_status(array $appointment): string {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function receptionist_status_label(string $status): string {
    return [
        'pending' => 'Needs confirmation',
        'confirmed' => 'Ready for doctor',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$status] ?? 'Needs confirmation';
}

function receptionist_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M d, Y', $stamp) : 'No date';
}

function receptionist_queue_timing_label(array $appointment, string $today, string $nowTime): string {
    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '');
    if ($date !== '') {
        if ($date < $today) {
            return 'Earlier appointment still open';
        }
        if ($date > $today) {
            return 'Upcoming on ' . receptionist_date_label($date);
        }
    }
    if ($time === '') {
        return 'Today';
    }
    if ($time < $nowTime) {
        return 'Earlier appointment still open';
    }
    if (substr($time, 0, 5) === substr($nowTime, 0, 5)) {
        return 'Due now';
    }
    return 'Upcoming today';
}

function receptionist_doctor_flow_label(array $appointment, mysqli $conn): array {
    $doctorId = (int) ($appointment['doctor_id'] ?? 0);
    if ($doctorId <= 0) {
        return [
            'class' => 'missing',
            'label' => 'No doctor assigned',
            'detail' => 'Assign doctor if this visit needs one',
        ];
    }

    $assignedRole = strtolower((string) ($appointment['doctor_role'] ?? ''));
    if ($assignedRole !== '' && $assignedRole !== 'doctor') {
        return [
            'class' => 'ok',
            'label' => 'Doctor assigned',
            'detail' => 'Ready for doctor flow',
        ];
    }

    if ((int) ($appointment['doctor_is_active'] ?? 1) !== 1) {
        return [
            'class' => 'off',
            'label' => 'Doctor unavailable',
            'detail' => 'This doctor is currently off duty',
        ];
    }

    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '');
    if ($date !== '' && $time !== '' && !doctor_time_matches_clinic_slot($conn, $doctorId, $date, $time)) {
        return [
            'class' => 'off',
            'label' => 'Outside clinic hours',
            'detail' => 'Check the doctor schedule',
        ];
    }

    return [
        'class' => 'ok',
        'label' => 'Doctor scheduled',
        'detail' => 'Within clinic hours',
    ];
}

function receptionist_short_text(string $text, int $limit = 70): string {
    $text = trim($text);
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function receptionist_calendar_service_label(array $appointment): string {
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
            return receptionist_short_text($service, 42);
        }
    }

    return 'Clinic appointment';
}

function receptionist_calendar_status_label(string $status): string {
    if ($status === 'cancelled') {
        return 'Declined';
    }
    return receptionist_status_label($status);
}

$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);
$today = date('Y-m-d');

$stmt = $conn->prepare("SELECT a.*,
                        p.full_name AS patient_name,
                        p.profile_photo,
                        p.profile_updated_at,
                        p.phone AS patient_phone,
                        d.full_name AS doctor_name,
                        d.role AS doctor_role,
                        COALESCE(d.is_active, 1) AS doctor_is_active
                        FROM appointments a
                        JOIN users p ON a.patient_id = p.id
                        LEFT JOIN users d ON a.doctor_id = d.id
                        ORDER BY a.appointment_date ASC, a.appointment_time ASC");
$stmt->execute();
$todayAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statusTotals = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$confirmedAppointments = [];
$nextAppointment = null;
$nowTime = date('H:i:s');
$patientIds = [];

foreach ($todayAppointments as $index => $appointment) {
    $status = receptionist_status($appointment);
    $todayAppointments[$index]['status_label'] = receptionist_status_label($status);
    $todayAppointments[$index]['queue_timing_label'] = receptionist_queue_timing_label($appointment, $today, $nowTime);
    $todayAppointments[$index]['doctor_flow'] = receptionist_doctor_flow_label($appointment, $conn);
    $appointment = $todayAppointments[$index];

    $statusTotals[$status]++;
    $patientIds[(int) $appointment['patient_id']] = true;

    if ($status === 'confirmed') {
        $confirmedAppointments[] = $appointment;
    }

    if ($nextAppointment === null && in_array($status, ['pending', 'confirmed'], true)) {
        $nextAppointment = $appointment;
    }
}

$staffCounts = ['doctor' => 0, 'doctor_available' => 0, 'doctor_unavailable' => 0];
$staffResult = $conn->query("SELECT
    SUM(CASE WHEN role = 'doctor' THEN 1 ELSE 0 END) AS doctors,
    SUM(CASE WHEN role = 'doctor' AND COALESCE(is_active, 1) = 1 THEN 1 ELSE 0 END) AS doctors_available,
    SUM(CASE WHEN role = 'doctor' AND COALESCE(is_active, 1) = 0 THEN 1 ELSE 0 END) AS doctors_unavailable
    FROM users
    WHERE role = 'doctor'");
if ($staffResult) {
    $row = $staffResult->fetch_assoc();
    $staffCounts['doctor'] = (int) ($row['doctors'] ?? 0);
    $staffCounts['doctor_available'] = (int) ($row['doctors_available'] ?? 0);
    $staffCounts['doctor_unavailable'] = (int) ($row['doctors_unavailable'] ?? 0);
}

$receptionNotifications = [];
$receptionUnreadNotifications = 0;
if (function_exists('fetch_clinic_notifications') && function_exists('count_unread_clinic_notifications')) {
    $receptionNotifications = fetch_clinic_notifications(
        $conn,
        'receptionist',
        (int) ($currentUser['id'] ?? 0),
        5
    );
    $receptionUnreadNotifications = count_unread_clinic_notifications(
        $conn,
        'receptionist',
        (int) ($currentUser['id'] ?? 0)
    );
}

$conn->close();

$totalToday = count($todayAppointments);
$activeQueue = $statusTotals['pending'] + $statusTotals['confirmed'];
$readyQueue = $statusTotals['confirmed'];
$todayLabel = date('F d, Y');
$upcomingAppointments = array_values(array_filter($todayAppointments, function (array $appointment) use ($today): bool {
    return (string) ($appointment['appointment_date'] ?? '') >= $today
        && in_array(receptionist_status($appointment), ['pending', 'confirmed'], true);
}));
$activeQueueAppointments = array_values(array_filter($todayAppointments, function (array $appointment): bool {
    return in_array(receptionist_status($appointment), ['pending', 'confirmed'], true);
}));
$recentActivities = array_slice($receptionNotifications, 0, 4);
$showReceptionCalendar = isset($_GET['calendar']) || isset($_GET['calendar_month']) || isset($_GET['calendar_date']);

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
$calendarPreviousMonthUrl = 'receptionist.php?calendar=1&calendar_month=' . $calendarMonthStart->modify('-1 month')->format('Y-m') . '#reception-calendar';
$calendarNextMonthUrl = 'receptionist.php?calendar=1&calendar_month=' . $calendarMonthStart->modify('+1 month')->format('Y-m') . '#reception-calendar';
$appointmentsByDate = [];
foreach ($todayAppointments as $appointment) {
    $dateKey = (string) ($appointment['appointment_date'] ?? '');
    if ($dateKey === '') {
        continue;
    }
    $appointmentsByDate[$dateKey][] = $appointment;
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
$calendarWeekStart = $calendarSelected->modify('-' . (int) $calendarSelected->format('w') . ' days');
$calendarWeekDays = [];
for ($i = 0; $i < 7; $i++) {
    $weekDay = $calendarWeekStart->modify('+' . $i . ' days');
    $dateKey = $weekDay->format('Y-m-d');
    $calendarWeekDays[] = [
        'date' => $dateKey,
        'day' => $weekDay,
        'appointments' => $appointmentsByDate[$dateKey] ?? [],
        'is_today' => $dateKey === $today,
    ];
}
$calendarDayAppointments = $appointmentsByDate[$calendarSelected->format('Y-m-d')] ?? [];

$additionalStyles = patientAvatarStyles() . '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.receptionist-dashboard {
    max-width: 1180px;
    margin: 0 auto;
    padding: 24px 20px 46px;
}

.receptionist-dashboard > section {
    padding-top: 0;
    padding-bottom: 0;
}

.reception-desk-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 18px;
    padding: 4px 2px 18px;
}

.reception-title-lockup {
    display: flex;
    align-items: center;
    gap: 12px;
}

.reception-title-logo {
    width: 42px;
    height: 42px;
    flex: 0 0 auto;
    border: 1px solid #c9e6f3;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 7px 18px rgba(21, 93, 130, 0.1);
    object-fit: contain;
    padding: 4px;
}

.reception-desk-heading h1 {
    margin: 0;
    color: #073b4c;
    font-size: clamp(1.45rem, 2.5vw, 2rem);
    line-height: 1.15;
}

.reception-desk-heading p {
    margin: 6px 0 0;
    color: #60727d;
    line-height: 1.45;
}

.reception-date-line {
    color: #315b6d;
    font-size: 0.88rem;
    font-weight: 800;
    white-space: nowrap;
}

.reception-operations-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.18fr) minmax(280px, 0.82fr);
    gap: 14px;
    margin-bottom: 14px;
}

.reception-sidebar-stack {
    display: grid;
    gap: 14px;
}

.operations-panel {
    padding: 18px;
}

.operations-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
}

.operations-panel-head h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.13rem;
}

.operations-panel-head p {
    margin: 4px 0 0;
    color: #60727d;
    font-size: 0.88rem;
    line-height: 1.4;
}

.operations-link {
    color: #0878b8;
    font-size: 0.86rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.operations-link:hover {
    text-decoration: underline;
}

.queue-overview {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 14px;
}

.queue-overview-item {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #f8fbff;
    padding: 11px;
}

.queue-overview-item span,
.queue-overview-item small {
    display: block;
    color: #60727d;
    font-size: 0.76rem;
    font-weight: 800;
}

.queue-overview-item strong {
    display: block;
    margin: 3px 0;
    color: #073b4c;
    font-size: 1.35rem;
    line-height: 1;
}

.compact-queue-list,
.activity-list,
.dashboard-notification-list {
    display: grid;
    gap: 8px;
}

.compact-queue-item,
.compact-upcoming-item,
.activity-item,
.dashboard-notification-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #fff;
    padding: 10px 12px;
}

.queue-patient,
.upcoming-patient,
.next-patient-identity {
    display: flex;
    align-items: center;
    min-width: 0;
    gap: 9px;
}

.queue-patient-copy,
.upcoming-patient-copy {
    min-width: 0;
}

.compact-queue-item,
.compact-upcoming-item,
.activity-item,
.dashboard-notification-item {
    color: inherit;
    text-decoration: none;
}

.compact-queue-item:hover,
.compact-upcoming-item:hover,
.activity-item:hover,
.dashboard-notification-item:hover {
    border-color: #9ed8ed;
    background: #f3fbff;
}

.queue-time {
    display: inline-grid;
    min-width: 62px;
    min-height: 38px;
    place-items: center;
    border-radius: 7px;
    background: #edf8ff;
    color: #0b5f91;
    font-size: 0.8rem;
    font-weight: 900;
    text-align: center;
}

.compact-queue-item .queue-patient-copy strong,
.compact-upcoming-item .upcoming-patient-copy strong,
.activity-item strong,
.dashboard-notification-item strong {
    display: block;
    color: #073b4c;
    font-size: 0.92rem;
}

.compact-queue-item .queue-patient-copy span,
.compact-upcoming-item .upcoming-patient-copy span,
.activity-item span,
.dashboard-notification-item span {
    display: block;
    margin-top: 2px;
    color: #60727d;
    font-size: 0.8rem;
    line-height: 1.35;
}

.next-patient-card {
    display: grid;
    gap: 12px;
    border: 1px solid #c9e7f4;
    border-radius: 10px;
    background: linear-gradient(145deg, #f9fdff 0%, #edf8fc 100%);
    padding: 14px;
}

.next-patient-topline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.next-patient-label {
    color: #0878b8;
    font-size: 0.76rem;
    font-weight: 900;
    letter-spacing: 0;
    text-transform: uppercase;
}

.next-patient-name {
    margin: 0;
    color: #073b4c;
    font-size: 1.12rem;
    line-height: 1.2;
}

.next-patient-identity {
    align-items: flex-start;
}

.next-patient-identity .patient-profile-avatar {
    margin-top: 1px;
}

.next-patient-service {
    margin: 4px 0 0;
    color: #60727d;
    font-size: 0.86rem;
}

.next-patient-details {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.next-patient-detail {
    border: 1px solid #dcebf3;
    border-radius: 7px;
    background: #fff;
    padding: 9px;
}

.next-patient-detail span {
    display: block;
    color: #60727d;
    font-size: 0.72rem;
    font-weight: 850;
    text-transform: uppercase;
}

.next-patient-detail strong {
    display: block;
    margin-top: 3px;
    color: #073b4c;
    font-size: 0.88rem;
    overflow-wrap: anywhere;
}

.next-patient-open {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    border-radius: 7px;
    background: #0878b8;
    color: #fff;
    font-size: 0.86rem;
    font-weight: 900;
    text-decoration: none;
}

.next-patient-open:hover {
    background: #05689f;
}

.notification-count {
    display: inline-grid;
    min-width: 28px;
    height: 28px;
    place-items: center;
    border-radius: 999px;
    background: #eaf7ff;
    color: #0878b8;
    font-size: 0.78rem;
    font-weight: 900;
}

.dashboard-notification-item {
    grid-template-columns: minmax(0, 1fr);
    background: #f8fbff;
}

.dashboard-notification-item.is-unread {
    border-left: 3px solid #0f9ac6;
    background: #f1fbff;
}

.activity-item {
    grid-template-columns: minmax(0, 1fr) auto;
}

.activity-date {
    color: #60727d;
    font-size: 0.75rem;
    font-weight: 800;
    white-space: nowrap;
}

.upcoming-list {
    display: grid;
    gap: 8px;
    max-height: 340px;
    overflow: auto;
    padding-right: 2px;
}

.desk-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
    gap: 14px;
    align-items: stretch;
    margin-bottom: 14px;
}

.hero-main,
.hero-side,
.metric-card,
.panel,
.appointment-row,
.reception-flow-card {
    border: 1px solid #d7e8f2;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 14px 34px rgba(25, 76, 110, 0.08);
}

.hero-main {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 92% 18%, rgba(65, 190, 222, 0.2), transparent 28%),
        linear-gradient(135deg, #06465a 0%, #075f92 55%, #0b4f80 100%);
    color: #fff;
    padding: 28px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.hero-main::after {
    content: "";
    position: absolute;
    right: -44px;
    bottom: -78px;
    width: 220px;
    height: 220px;
    border: 38px solid rgba(255, 255, 255, 0.08);
    border-radius: 50%;
}

.eyebrow {
    margin: 0 0 8px;
    color: #aeefff;
    font-size: 0.8rem;
    font-weight: 900;
    letter-spacing: 0;
    text-transform: uppercase;
}

.hero-main h1 {
    margin: 0 0 10px;
    color: #fff;
    font-size: clamp(1.65rem, 3vw, 2.2rem);
    line-height: 1.15;
}

.hero-main p {
    max-width: 720px;
    margin: 0;
    color: rgba(255, 255, 255, 0.88);
    line-height: 1.6;
}

.hero-side {
    padding: 22px;
    display: grid;
    gap: 10px;
    align-content: center;
    background: linear-gradient(180deg, #ffffff 0%, #f2f9fd 100%);
}

.hero-side span {
    color: #60727d;
    font-weight: 800;
    font-size: 0.84rem;
    text-transform: uppercase;
}

.hero-side strong {
    color: #073b4c;
    font-size: 1.55rem;
    line-height: 1.1;
}

.hero-side small {
    color: #60727d;
    font-weight: 700;
}

.success-message,
.error-message {
    border-radius: 8px;
    padding: 13px 14px;
    margin-bottom: 14px;
    font-weight: 800;
}

.success-message {
    background: #e7f7ed;
    color: #17643a;
    border: 1px solid #bfe6ce;
}

.error-message {
    background: #fff0f0;
    color: #9d1c2c;
    border: 1px solid #ffd0d5;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 14px;
}

.metric-card {
    position: relative;
    overflow: hidden;
    padding: 18px;
    display: grid;
    gap: 7px;
}

.metric-card::before {
    content: "";
    position: absolute;
    left: 0;
    top: 16px;
    bottom: 16px;
    width: 4px;
    border-radius: 0 999px 999px 0;
    background: #0f7cc2;
}

.metric-card span {
    color: #60727d;
    font-size: 0.82rem;
    font-weight: 900;
    text-transform: uppercase;
}

.metric-card strong {
    color: #073b4c;
    font-size: 2rem;
    line-height: 1;
}

.metric-card small {
    color: #60727d;
    font-weight: 700;
}

.metric-card.pending {
    border-color: #f2d58b;
    background: #fffaf0;
}

.metric-card.pending::before {
    background: #e3a31a;
}

.metric-card.ready {
    border-color: #bfe6ce;
    background: #f5fbf7;
}

.metric-card.ready::before {
    background: #1f9d61;
}

.reception-flow {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 14px;
}

.reception-flow-card {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    padding: 14px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fcff 100%);
}

.flow-number {
    width: 38px;
    height: 38px;
    display: inline-grid;
    place-items: center;
    border-radius: 12px;
    background: #e5f5fd;
    color: #0f7cc2;
    font-weight: 950;
}

.reception-flow-card strong {
    display: block;
    color: #073b4c;
    font-size: 0.98rem;
}

.reception-flow-card span {
    display: block;
    margin-top: 3px;
    color: #60727d;
    font-size: 0.86rem;
    line-height: 1.35;
}

.workbench-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
    gap: 14px;
    margin-bottom: 14px;
}

.panel {
    padding: 18px;
}

.panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
}

.panel-head h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.22rem;
}

.panel-head p {
    margin: 4px 0 0;
    color: #60727d;
    font-size: 0.92rem;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 0.74rem;
    font-weight: 900;
    text-transform: uppercase;
    text-align: center;
    line-height: 1.15;
    white-space: nowrap;
}

.status-badge.pending {
    background: #fff3cd;
    color: #856404;
}

.status-badge.confirmed {
    background: #e7f7ed;
    color: #17643a;
}

.status-badge.completed {
    background: #e8f4f8;
    color: #0b4f80;
}

.status-badge.cancelled {
    background: #fff0f0;
    color: #9d1c2c;
}

.next-patient {
    display: grid;
    gap: 10px;
}

.next-patient-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 2px 0 4px;
}

.queue-note,
.doctor-flow {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 0.78rem;
    font-weight: 900;
}

.queue-note {
    background: #eef7ff;
    color: #0b4f80;
}

.doctor-flow.ok {
    background: #e7f7ed;
    color: #17643a;
}

.doctor-flow.missing {
    background: #fffaf0;
    color: #856404;
}

.doctor-flow.off {
    background: #fff0f0;
    color: #9d1c2c;
}

.next-patient strong {
    color: #073b4c;
    font-size: 1.35rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.detail-pill {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #f8fbff;
    padding: 10px;
}

.detail-pill span {
    display: block;
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.detail-pill strong {
    display: block;
    margin-top: 4px;
    color: #1f343d;
    font-size: 0.96rem;
}

.handoff-list {
    display: grid;
    gap: 8px;
}

.handoff-item {
    border-left: 3px solid #0f7cc2;
    background: #f8fbff;
    border-radius: 6px;
    padding: 10px 12px;
    display: flex;
    justify-content: space-between;
    gap: 12px;
}

.handoff-item strong {
    display: block;
    color: #073b4c;
}

.handoff-item span {
    color: #60727d;
    font-size: 0.88rem;
}

.staff-strip {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 12px;
}

.staff-mini {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 12px;
    background: #fff;
}

.staff-mini span {
    display: block;
    color: #60727d;
    font-size: 0.8rem;
    font-weight: 900;
    text-transform: uppercase;
}

.staff-mini strong {
    display: block;
    margin-top: 6px;
    color: #073b4c;
    font-size: 1.3rem;
}

.staff-mini small {
    display: block;
    margin-top: 4px;
    color: #60727d;
    font-weight: 700;
}

.schedule-card {
    padding: 20px;
}

.appointment-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(160px, 220px);
    gap: 10px;
    margin-bottom: 14px;
}

.appointment-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
}

.appointment-summary span {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    border: 1px solid #e0ebf3;
    border-radius: 999px;
    background: #f8fbff;
    color: #364d58;
    padding: 5px 10px;
    font-size: 0.84rem;
    font-weight: 800;
}

input,
select {
    width: 100%;
    box-sizing: border-box;
    min-height: 40px;
    border: 1px solid #d4e6f5;
    border-radius: 8px;
    background: #fff;
    color: #1f343d;
    font: inherit;
    padding: 9px 10px;
}

input:focus,
select:focus {
    border-color: #0f7cc2;
    box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.1);
    outline: none;
}

.appointments-list {
    display: grid;
    gap: 10px;
}

.appointment-row {
    display: grid;
    grid-template-columns: 92px auto minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 14px;
}

.appointment-row.hidden {
    display: none;
}

.time-block {
    border-radius: 8px;
    background: #eef7ff;
    color: #0b4f80;
    padding: 10px;
    text-align: center;
    font-weight: 900;
}

.appointment-main {
    min-width: 0;
}

.appointment-patient {
    color: #073b4c;
    font-weight: 900;
    font-size: 1.05rem;
}

.appointment-details {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-top: 6px;
    color: #60727d;
    font-size: 0.9rem;
}

.appointment-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
}

.reception-calendar {
    margin-bottom: 16px;
    padding: 18px;
    overflow: hidden;
}

.calendar-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 16px;
}

.calendar-title h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.3rem;
}

.calendar-title p {
    margin: 4px 0 0;
    color: #60727d;
    font-size: 0.92rem;
}

.calendar-view-tabs {
    display: inline-flex;
    gap: 6px;
    padding: 5px;
    border: 1px solid #d9e8f1;
    border-radius: 999px;
    background: #f8fbff;
}

.calendar-tab {
    min-height: 34px;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: #315b6d;
    padding: 6px 14px;
    font: inherit;
    font-size: 0.86rem;
    font-weight: 900;
    cursor: pointer;
}

.calendar-tab.active {
    background: #0f7cc2;
    color: #fff;
    box-shadow: 0 10px 22px rgba(15, 124, 194, 0.22);
}

.calendar-control-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.calendar-month-nav {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.calendar-month-nav a,
.calendar-today-link {
    display: inline-grid;
    place-items: center;
    min-width: 38px;
    min-height: 38px;
    border: 1px solid #d7e8f2;
    border-radius: 999px;
    background: #f8fbff;
    color: #0b4f80;
    font-weight: 900;
    text-decoration: none;
}

.calendar-month-label {
    color: #073b4c;
    font-size: 1rem;
    font-weight: 900;
}

.calendar-legend {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
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
    font-size: 0.8rem;
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

.calendar-view {
    display: none;
}

.calendar-view.active {
    display: block;
}

.calendar-scroll {
    overflow-x: auto;
    padding-bottom: 4px;
}

.month-grid {
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
    font-size: 0.76rem;
    font-weight: 900;
    text-transform: uppercase;
}

.month-day {
    min-height: 124px;
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
    font-weight: 900;
    font-size: 0.9rem;
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

.calendar-event strong {
    display: block;
    overflow: hidden;
    color: #073b4c;
    font-size: 0.78rem;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.calendar-event span {
    display: block;
    margin-top: 3px;
    overflow: hidden;
    color: #60727d;
    font-size: 0.68rem;
    font-weight: 800;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.calendar-event.pending { border-left-color: #e3a31a; background: #fffaf0; }
.calendar-event.confirmed { border-left-color: #0f7cc2; background: #eef8ff; }
.calendar-event.completed { border-left-color: #1f9d61; background: #f0fbf5; }
.calendar-event.cancelled { border-left-color: #d94150; background: #fff4f5; }

.calendar-more {
    color: #60727d;
    font-size: 0.74rem;
    font-weight: 900;
}

.week-grid,
.day-timeline {
    display: grid;
    gap: 10px;
}

.week-grid {
    grid-template-columns: repeat(7, minmax(0, 1fr));
}

.week-day-card,
.day-appointment-card {
    border: 1px solid #dce8ef;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.05);
}

.week-day-card {
    min-height: 170px;
    padding: 12px;
}

.week-day-card.is-today {
    border-color: #9bdcf3;
    background: #f6fcff;
}

.week-day-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 10px;
}

.week-day-head strong {
    color: #073b4c;
}

.week-day-head span {
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
}

.day-timeline {
    max-width: 760px;
}

.day-appointment-card {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 14px;
}

.day-time {
    border-radius: 12px;
    background: #eef8ff;
    color: #0b4f80;
    padding: 10px;
    text-align: center;
    font-weight: 900;
}

.day-info strong {
    display: block;
    color: #073b4c;
    font-size: 1rem;
}

.day-info span {
    display: block;
    margin-top: 4px;
    color: #60727d;
    font-size: 0.9rem;
}

.calendar-empty {
    border: 1px dashed #bdd7ea;
    border-radius: 12px;
    background: #f8fbff;
    color: #60727d;
    padding: 18px;
    font-weight: 800;
    text-align: center;
}

.btn-action,
.dashboard-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px 13px;
    cursor: pointer;
    font-weight: 900;
    text-decoration: none;
    transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.btn-action:hover,
.dashboard-btn:hover {
    transform: translateY(-1px);
}

.btn-confirm {
    background: #17643a;
    color: #fff;
}

.btn-complete {
    background: #0f7cc2;
    color: #fff;
}

.btn-cancel {
    background: #c1121f;
    color: #fff;
}

.status-action-modal {
    position: fixed;
    inset: 0;
    z-index: 3000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(8, 42, 58, 0.5);
    backdrop-filter: blur(3px);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.18s ease, visibility 0.18s ease;
}

.status-action-modal.open {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}

.status-action-dialog {
    position: relative;
    width: min(100%, 460px);
    max-height: calc(100vh - 36px);
    overflow-y: auto;
    border: 1px solid #cfe0e9;
    border-top: 5px solid #17643a;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 24px 64px rgba(7, 43, 59, 0.28);
    transform: translateY(8px) scale(0.985);
    transition: transform 0.18s ease;
}

.status-action-modal.open .status-action-dialog {
    transform: translateY(0) scale(1);
}

.status-action-modal[data-action="completed"] .status-action-dialog {
    border-top-color: #0f7cc2;
}

.status-action-modal[data-action="cancelled"] .status-action-dialog {
    border-top-color: #c1121f;
}

.status-action-header {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 19px 54px 15px 20px;
    background: #fff;
    color: #073b4c;
}

.status-action-mark {
    position: relative;
    flex: 0 0 42px;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #e8f5ed;
    color: #17643a;
}

.status-action-mark::before,
.status-action-mark::after {
    content: "";
    position: absolute;
}

.status-action-mark::before {
    width: 9px;
    height: 17px;
    left: 16px;
    top: 9px;
    border-right: 3px solid currentColor;
    border-bottom: 3px solid currentColor;
    transform: rotate(42deg);
}

.status-action-modal[data-action="completed"] .status-action-mark {
    background: #e8f4fb;
    color: #0f7cc2;
}

.status-action-modal[data-action="completed"] .status-action-mark::before {
    width: 15px;
    height: 15px;
    left: 13px;
    top: 13px;
    border: 3px solid currentColor;
    border-radius: 2px;
    transform: none;
}

.status-action-modal[data-action="completed"] .status-action-mark::after {
    width: 7px;
    height: 3px;
    left: 18px;
    top: 20px;
    background: currentColor;
}

.status-action-modal[data-action="cancelled"] .status-action-mark {
    background: #fdecee;
    color: #c1121f;
}

.status-action-modal[data-action="cancelled"] .status-action-mark::before,
.status-action-modal[data-action="cancelled"] .status-action-mark::after {
    width: 19px;
    height: 3px;
    left: 12px;
    top: 20px;
    border: 0;
    border-radius: 2px;
    background: currentColor;
}

.status-action-modal[data-action="cancelled"] .status-action-mark::before {
    transform: rotate(45deg);
}

.status-action-modal[data-action="cancelled"] .status-action-mark::after {
    transform: rotate(-45deg);
}

.status-action-kicker {
    display: block;
    margin-bottom: 3px;
    color: #6a7f8b;
    font-size: 0.7rem;
    font-weight: 900;
    text-transform: uppercase;
}

.status-action-header h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.28rem;
    line-height: 1.2;
}

.status-action-close {
    position: absolute;
    top: 15px;
    right: 15px;
    width: 34px;
    height: 34px;
    border: 1px solid #d7e5ed;
    border-radius: 50%;
    background: #f7fafc;
    color: #315b6d;
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
}

.status-action-close:hover {
    border-color: #bdd3df;
    background: #edf4f7;
}

.status-action-body {
    padding: 0 20px 19px;
}

.status-action-message {
    margin: 0 0 15px;
    color: #526b7a;
    font-size: 0.95rem;
    line-height: 1.55;
}

.status-action-patient {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    margin-bottom: 14px;
    padding: 13px 0;
    border-top: 1px solid #e0ebf1;
    border-bottom: 1px solid #e0ebf1;
}

.status-action-patient strong,
.status-action-patient span {
    display: block;
}

.status-action-patient strong {
    color: #073b4c;
    font-size: 1.02rem;
}

.status-action-patient span {
    color: #526f80;
    font-size: 0.9rem;
    font-weight: 700;
    text-align: right;
}

.status-action-note {
    margin: 0;
    padding: 11px 13px;
    border-left: 3px solid #0f7cc2;
    border-radius: 6px;
    background: #eef7fd;
    color: #315b6d;
    font-size: 0.88rem;
    line-height: 1.5;
}

.status-action-modal[data-action="confirmed"] .status-action-note {
    border-left-color: #17643a;
    background: #edf8f1;
}

.status-action-modal[data-action="cancelled"] .status-action-note {
    border-left-color: #c1121f;
    background: #fff1f2;
}

.status-action-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 20px 18px;
    border-top: 1px solid #e3edf2;
    margin: 0;
    background: #fff;
    color: #073b4c;
    text-align: left;
}

.status-action-back,
.status-action-submit {
    min-height: 40px;
    border-radius: 8px;
    padding: 9px 15px;
    font: inherit;
    font-size: 0.92rem;
    font-weight: 900;
    cursor: pointer;
}

.status-action-back {
    border: 1px solid #cfdfe8;
    background: #fff;
    color: #315b6d;
}

.status-action-submit {
    border: 1px solid transparent;
    background: #17643a;
    color: #fff;
    min-width: 168px;
}

.status-action-submit:hover {
    filter: brightness(0.94);
}

.status-action-submit:disabled {
    cursor: wait;
    opacity: 0.72;
}

.status-action-modal[data-action="completed"] .status-action-submit {
    background: #0f7cc2;
}

.status-action-modal[data-action="cancelled"] .status-action-submit {
    background: #c1121f;
}

body.modal-open {
    overflow: hidden;
}

.dashboard-btn {
    background: #0f7cc2;
    color: #fff;
}

.dashboard-btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.empty-state {
    border: 1px dashed #bdd7ea;
    border-radius: 8px;
    padding: 20px;
    color: #60727d;
    background: #f8fbff;
}

.empty-state.hidden {
    display: none;
}

@media (max-width: 980px) {
    .desk-hero,
    .workbench-grid,
    .reception-flow,
    .metrics-grid,
    .reception-operations-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hero-main {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .receptionist-dashboard {
        padding: 18px 12px 36px;
    }

    .desk-hero,
    .workbench-grid,
    .reception-flow,
    .metrics-grid,
    .reception-operations-grid,
    .appointment-toolbar,
    .detail-grid,
    .staff-strip {
        grid-template-columns: 1fr;
    }

    .hero-main,
    .hero-side,
    .metric-card,
    .panel,
    .reception-flow-card {
        border-radius: 12px;
    }

    .hero-main {
        padding: 22px;
    }

    .reception-desk-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: 6px;
        padding-bottom: 14px;
    }

    .queue-overview {
        grid-template-columns: 1fr;
    }

    .compact-queue-item,
    .compact-upcoming-item,
    .activity-item {
        grid-template-columns: auto minmax(0, 1fr);
    }

    .compact-queue-item .status-badge,
    .compact-upcoming-item .status-badge,
    .activity-date {
        grid-column: 2;
        justify-self: start;
    }

    .next-patient-details {
        grid-template-columns: 1fr;
    }

    .reception-flow-card {
        align-items: flex-start;
    }

    .appointment-row {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .appointment-actions {
        justify-content: stretch;
    }

    .calendar-topline,
    .calendar-control-row {
        align-items: stretch;
        flex-direction: column;
    }

    .calendar-view-tabs {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        width: 100%;
        box-sizing: border-box;
    }

    .calendar-tab {
        width: 100%;
    }

    .calendar-legend {
        justify-content: flex-start;
    }

    .month-grid {
        min-width: 760px;
    }

    .week-grid,
    .day-appointment-card {
        grid-template-columns: 1fr;
    }

    .day-appointment-card {
        align-items: stretch;
    }

    .btn-action,
    .dashboard-btn {
        width: 100%;
    }

    .status-action-footer {
        flex-direction: column-reverse;
    }

    .status-action-back,
    .status-action-submit {
        width: 100%;
    }

    .status-action-patient {
        grid-template-columns: 1fr;
        gap: 4px;
    }

    .status-action-patient span {
        text-align: left;
    }
}
';

include 'includes/header.php';
?>
<main class="receptionist-dashboard">
    <section class="reception-desk-heading" aria-labelledby="receptionDeskTitle">
        <div class="reception-title-lockup">
            <img class="reception-title-logo" src="globalife.png" alt="Globalife Medical Laboratory and Polyclinic">
            <div>
                <h1 id="receptionDeskTitle">Reception desk</h1>
                <p>Manage arrivals, booking requests, and clinic handoffs from one place.</p>
            </div>
        </div>
        <span class="reception-date-line"><?php echo htmlspecialchars($todayLabel); ?></span>
    </section>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="success-message"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="error-message"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <section class="metrics-grid" aria-label="Appointment summary">
        <div class="metric-card">
            <span>Booked appointments</span>
            <strong><?php echo $totalToday; ?></strong>
            <small><?php echo count($patientIds); ?> patient<?php echo count($patientIds) === 1 ? '' : 's'; ?></small>
        </div>
        <div class="metric-card pending">
            <span>Needs confirmation</span>
            <strong><?php echo $statusTotals['pending']; ?></strong>
            <small>Review before clinic flow</small>
        </div>
        <div class="metric-card ready">
            <span>Ready for doctor</span>
            <strong><?php echo $readyQueue; ?></strong>
            <small>Confirmed and waiting</small>
        </div>
        <div class="metric-card">
            <span>Completed</span>
            <strong><?php echo $statusTotals['completed']; ?></strong>
            <small><?php echo $activeQueue; ?> still active</small>
        </div>
    </section>

    <section class="reception-operations-grid" aria-label="Reception operations">
        <article class="panel operations-panel">
            <div class="operations-panel-head">
                <div>
                    <h2>Today's queue</h2>
                    <p>Patients that need review or are ready for the clinic team.</p>
                </div>
                <a class="operations-link" href="view_appointments.php">Open appointments</a>
            </div>
            <div class="queue-overview">
                <div class="queue-overview-item">
                    <span>Waiting review</span>
                    <strong><?php echo $statusTotals['pending']; ?></strong>
                    <small>Pending requests</small>
                </div>
                <div class="queue-overview-item">
                    <span>Ready for care</span>
                    <strong><?php echo $readyQueue; ?></strong>
                    <small>Confirmed patients</small>
                </div>
                <div class="queue-overview-item">
                    <span>Completed</span>
                    <strong><?php echo $statusTotals['completed']; ?></strong>
                    <small>Visits today</small>
                </div>
            </div>
            <?php if (!empty($activeQueueAppointments)): ?>
                <div class="compact-queue-list">
                    <?php foreach (array_slice($activeQueueAppointments, 0, 4) as $queueAppointment): ?>
                        <?php $queueDoctorFlow = $queueAppointment['doctor_flow']; ?>
                        <a class="compact-queue-item" href="view_appointments.php?highlight=<?php echo (int) ($queueAppointment['id'] ?? 0); ?>">
                            <span class="queue-time"><?php echo receptionist_time_label($queueAppointment['appointment_time']); ?></span>
                            <div class="queue-patient">
                                <?php echo renderPatientAvatar($queueAppointment, ['size' => 'sm']); ?>
                                <div class="queue-patient-copy">
                                    <strong><?php echo htmlspecialchars($queueAppointment['patient_name']); ?></strong>
                                    <span><?php echo htmlspecialchars(receptionist_calendar_service_label($queueAppointment)); ?> | <?php echo htmlspecialchars($queueDoctorFlow['label']); ?></span>
                                </div>
                            </div>
                            <span class="status-badge <?php echo htmlspecialchars(receptionist_status($queueAppointment)); ?>"><?php echo htmlspecialchars($queueAppointment['status_label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No patients are waiting in the active queue.</div>
            <?php endif; ?>
        </article>

        <div class="reception-sidebar-stack">
            <article class="panel operations-panel">
                <div class="operations-panel-head">
                    <div>
                        <h2>Next patient</h2>
                        <p>Current queue priority for front desk review.</p>
                    </div>
                </div>
                <?php if ($nextAppointment): ?>
                    <?php $nextDoctorFlow = $nextAppointment['doctor_flow']; ?>
                    <div class="next-patient-card">
                        <div class="next-patient-topline">
                            <span class="next-patient-label"><?php echo htmlspecialchars($nextAppointment['queue_timing_label']); ?></span>
                            <span class="status-badge <?php echo htmlspecialchars(receptionist_status($nextAppointment)); ?>"><?php echo htmlspecialchars($nextAppointment['status_label']); ?></span>
                        </div>
                        <div class="next-patient-identity">
                            <?php echo renderPatientAvatar($nextAppointment, ['size' => 'md']); ?>
                            <div>
                                <h3 class="next-patient-name"><?php echo htmlspecialchars($nextAppointment['patient_name'] ?? 'Patient'); ?></h3>
                                <p class="next-patient-service"><?php echo htmlspecialchars(receptionist_calendar_service_label($nextAppointment)); ?></p>
                            </div>
                        </div>
                        <div class="next-patient-details">
                            <div class="next-patient-detail">
                                <span>Appointment time</span>
                                <strong><?php echo receptionist_time_label($nextAppointment['appointment_time']); ?></strong>
                            </div>
                            <div class="next-patient-detail">
                                <span>Doctor</span>
                                <strong><?php echo htmlspecialchars($nextAppointment['doctor_name'] ?: 'For clinic assignment'); ?></strong>
                            </div>
                            <div class="next-patient-detail">
                                <span>Contact</span>
                                <strong><?php echo htmlspecialchars($nextAppointment['patient_phone'] ?: 'No phone on file'); ?></strong>
                            </div>
                            <div class="next-patient-detail">
                                <span>Care flow</span>
                                <strong><?php echo htmlspecialchars($nextDoctorFlow['label']); ?></strong>
                            </div>
                        </div>
                        <a class="next-patient-open" href="view_appointments.php?highlight=<?php echo (int) ($nextAppointment['id'] ?? 0); ?>">Open appointment</a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">No next patient in the queue.</div>
                <?php endif; ?>
            </article>

            <article class="panel operations-panel">
                <div class="operations-panel-head">
                    <div>
                        <h2>Notifications</h2>
                        <p>Latest booking and clinic updates.</p>
                    </div>
                    <span class="notification-count"><?php echo $receptionUnreadNotifications; ?></span>
                </div>
                <?php if (empty($receptionNotifications)): ?>
                    <div class="empty-state">No new notifications.</div>
                <?php else: ?>
                    <div class="dashboard-notification-list">
                        <?php foreach (array_slice($receptionNotifications, 0, 2) as $notification): ?>
                            <a class="dashboard-notification-item<?php echo empty($notification['read_at']) ? ' is-unread' : ''; ?>" href="<?php echo htmlspecialchars((string) ($notification['target_url'] ?? 'clinic_notifications.php')); ?>">
                                <strong><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Clinic update')); ?></strong>
                                <span><?php echo htmlspecialchars(receptionist_short_text((string) ($notification['message'] ?? ''), 100)); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </div>
    </section>

    <?php if ($showReceptionCalendar): ?>
    <section class="panel reception-calendar" id="reception-calendar" aria-label="Reception calendar">
        <div class="calendar-topline">
            <div class="calendar-title">
                <h2>Clinic appointment calendar</h2>
                <p>View patient bookings by day, week, or month.</p>
            </div>
            <div class="calendar-view-tabs" role="tablist" aria-label="Calendar view">
                <button type="button" class="calendar-tab" data-calendar-tab="day">Day</button>
                <button type="button" class="calendar-tab" data-calendar-tab="week">Week</button>
                <button type="button" class="calendar-tab active" data-calendar-tab="month">Month</button>
            </div>
        </div>

        <div class="calendar-control-row">
            <div class="calendar-month-nav" aria-label="Month navigation">
                <a href="<?php echo htmlspecialchars($calendarPreviousMonthUrl); ?>" aria-label="Previous month">&larr;</a>
                <strong class="calendar-month-label"><?php echo htmlspecialchars($calendarMonthStart->format('F Y')); ?></strong>
                <a href="<?php echo htmlspecialchars($calendarNextMonthUrl); ?>" aria-label="Next month">&rarr;</a>
                <a class="calendar-today-link" href="receptionist.php?calendar=1#reception-calendar">Today</a>
            </div>
            <div class="calendar-legend" aria-label="Appointment status legend">
                <span><i class="dot-pending"></i> Pending</span>
                <span><i class="dot-confirmed"></i> Confirmed</span>
                <span><i class="dot-completed"></i> Completed</span>
                <span><i class="dot-cancelled"></i> Declined</span>
            </div>
        </div>

        <div class="calendar-view" data-calendar-view="day">
            <div class="calendar-title" style="margin-bottom:12px">
                <h2><?php echo htmlspecialchars($calendarSelected->format('F d, Y')); ?></h2>
                <p><?php echo count($calendarDayAppointments); ?> appointment<?php echo count($calendarDayAppointments) === 1 ? '' : 's'; ?> scheduled.</p>
            </div>
            <?php if (empty($calendarDayAppointments)): ?>
                <div class="calendar-empty">No appointments scheduled for this day.</div>
            <?php else: ?>
                <div class="day-timeline">
                    <?php foreach ($calendarDayAppointments as $appointment): ?>
                        <?php $calendarStatus = receptionist_status($appointment); ?>
                        <a class="day-appointment-card calendar-event <?php echo htmlspecialchars($calendarStatus); ?>" href="view_appointments.php?highlight=<?php echo (int) ($appointment['id'] ?? 0); ?>">
                            <span class="day-time"><?php echo receptionist_time_label($appointment['appointment_time']); ?></span>
                            <span class="day-info">
                                <strong><?php echo htmlspecialchars($appointment['patient_name'] ?? 'Patient'); ?></strong>
                                <span><?php echo htmlspecialchars(receptionist_calendar_service_label($appointment)); ?><?php echo !empty($appointment['doctor_name']) ? ' | ' . htmlspecialchars((string) $appointment['doctor_name']) : ''; ?></span>
                            </span>
                            <span class="status-badge <?php echo htmlspecialchars($calendarStatus); ?>"><?php echo htmlspecialchars(receptionist_calendar_status_label($calendarStatus)); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="calendar-view" data-calendar-view="week">
            <div class="week-grid">
                <?php foreach ($calendarWeekDays as $weekDay): ?>
                    <div class="week-day-card <?php echo $weekDay['is_today'] ? 'is-today' : ''; ?>">
                        <div class="week-day-head">
                            <strong><?php echo htmlspecialchars($weekDay['day']->format('D')); ?></strong>
                            <span><?php echo htmlspecialchars($weekDay['day']->format('M j')); ?></span>
                        </div>
                        <?php if (empty($weekDay['appointments'])): ?>
                            <div class="calendar-empty">No bookings</div>
                        <?php else: ?>
                            <?php foreach (array_slice($weekDay['appointments'], 0, 4) as $appointment): ?>
                                <?php $calendarStatus = receptionist_status($appointment); ?>
                                <a class="calendar-event <?php echo htmlspecialchars($calendarStatus); ?>" href="view_appointments.php?highlight=<?php echo (int) ($appointment['id'] ?? 0); ?>">
                                    <strong><?php echo htmlspecialchars($appointment['patient_name'] ?? 'Patient'); ?></strong>
                                    <span><?php echo receptionist_time_label($appointment['appointment_time']); ?> | <?php echo htmlspecialchars(receptionist_calendar_status_label($calendarStatus)); ?></span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (count($weekDay['appointments']) > 4): ?>
                                <div class="calendar-more">+<?php echo count($weekDay['appointments']) - 4; ?> more</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="calendar-view active" data-calendar-view="month">
            <div class="calendar-scroll">
                <div class="month-grid">
                    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                        <div class="month-weekday"><?php echo htmlspecialchars($weekday); ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($calendarDays as $calendarDay): ?>
                        <div class="month-day <?php echo $calendarDay['outside_month'] ? 'outside-month' : ''; ?> <?php echo $calendarDay['is_today'] ? 'is-today' : ''; ?>">
                            <span class="day-number"><?php echo htmlspecialchars($calendarDay['day']->format('j')); ?></span>
                            <?php foreach (array_slice($calendarDay['appointments'], 0, 3) as $appointment): ?>
                                <?php $calendarStatus = receptionist_status($appointment); ?>
                                <a class="calendar-event <?php echo htmlspecialchars($calendarStatus); ?>" href="view_appointments.php?highlight=<?php echo (int) ($appointment['id'] ?? 0); ?>" title="<?php echo htmlspecialchars(($appointment['patient_name'] ?? 'Patient') . ' - ' . receptionist_time_label($appointment['appointment_time'])); ?>">
                                    <strong><?php echo htmlspecialchars($appointment['patient_name'] ?? 'Patient'); ?></strong>
                                    <span><?php echo receptionist_time_label($appointment['appointment_time']); ?> | <?php echo htmlspecialchars(receptionist_calendar_status_label($calendarStatus)); ?></span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (count($calendarDay['appointments']) > 3): ?>
                                <div class="calendar-more">+<?php echo count($calendarDay['appointments']) - 3; ?> more</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="workbench-grid">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Upcoming appointments</h2>
                    <p>Pending and confirmed appointments from today onward.</p>
                </div>
                <a class="operations-link" href="view_appointments.php">View all</a>
            </div>

            <?php if (empty($upcomingAppointments)): ?>
                <div class="empty-state">No upcoming appointments to review.</div>
            <?php else: ?>
                <div class="upcoming-list">
                    <?php foreach (array_slice($upcomingAppointments, 0, 6) as $appointment): ?>
                        <?php $appointmentStatus = receptionist_status($appointment); ?>
                        <a class="compact-upcoming-item" href="view_appointments.php?highlight=<?php echo (int) ($appointment['id'] ?? 0); ?>">
                            <span class="queue-time"><?php echo receptionist_time_label($appointment['appointment_time']); ?></span>
                            <div class="upcoming-patient">
                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm']); ?>
                                <div class="upcoming-patient-copy">
                                    <strong><?php echo htmlspecialchars($appointment['patient_name'] ?? 'Patient'); ?></strong>
                                    <span><?php echo htmlspecialchars(receptionist_date_label($appointment['appointment_date'])); ?> | <?php echo htmlspecialchars(receptionist_calendar_service_label($appointment)); ?></span>
                                </div>
                            </div>
                            <span class="status-badge <?php echo htmlspecialchars($appointmentStatus); ?>"><?php echo htmlspecialchars($appointment['status_label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Recent activity</h2>
                    <p>Latest changes that affect the front desk.</p>
                </div>
                <a class="operations-link" href="clinic_notifications.php">View notifications</a>
            </div>

            <?php if (empty($recentActivities)): ?>
                <div class="empty-state">No recent activity yet.</div>
            <?php else: ?>
                <div class="activity-list">
                    <?php foreach ($recentActivities as $activity): ?>
                        <a class="activity-item" href="<?php echo htmlspecialchars((string) ($activity['target_url'] ?? 'clinic_notifications.php')); ?>">
                            <div>
                                <strong><?php echo htmlspecialchars((string) ($activity['title'] ?? 'Clinic update')); ?></strong>
                                <span><?php echo htmlspecialchars(receptionist_short_text((string) ($activity['message'] ?? ''), 88)); ?></span>
                            </div>
                            <time class="activity-date" datetime="<?php echo htmlspecialchars((string) ($activity['created_at'] ?? '')); ?>"><?php echo !empty($activity['created_at']) ? htmlspecialchars(date('M j', strtotime((string) $activity['created_at']))) : ''; ?></time>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="staff-strip">
                <div class="staff-mini">
                    <span>Doctors available</span>
                    <strong><?php echo $staffCounts['doctor_available']; ?></strong>
                    <small>of <?php echo $staffCounts['doctor']; ?> total</small>
                </div>
                <div class="staff-mini">
                    <span>Unavailable doctors</span>
                    <strong><?php echo $staffCounts['doctor_unavailable']; ?></strong>
                    <small>Off duty or inactive</small>
                </div>
            </div>
        </div>
    </section>

</main>

<script>
(function () {
    const tabs = document.querySelectorAll('[data-calendar-tab]');
    const views = document.querySelectorAll('[data-calendar-view]');
    if (!tabs.length || !views.length) {
        return;
    }

    function showCalendarView(viewName) {
        tabs.forEach(function (tab) {
            tab.classList.toggle('active', tab.getAttribute('data-calendar-tab') === viewName);
        });
        views.forEach(function (view) {
            view.classList.toggle('active', view.getAttribute('data-calendar-view') === viewName);
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            showCalendarView(tab.getAttribute('data-calendar-tab') || 'month');
        });
    });

    const requestedView = new URLSearchParams(window.location.search).get('calendar_view');
    if (requestedView && ['day', 'week', 'month'].includes(requestedView)) {
        showCalendarView(requestedView);
    }
})();
</script>

<?php include 'includes/footer.php'; ?>

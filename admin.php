<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/admin_notifications.php';
require_once __DIR__ . '/includes/doctor_schedule.php';

$pageTitle = 'Administrator Dashboard | Globalife Medical Laboratory & Polyclinic';
$currentUser = getCurrentUser();
$today = date('Y-m-d');

function admin_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function admin_column_exists(mysqli $conn, string $table, string $column): bool {
    $safeTable = $conn->real_escape_string($table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

function admin_count_query(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    if ($result && ($row = $result->fetch_assoc())) {
        return (int) ($row['total'] ?? 0);
    }
    return 0;
}

function admin_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('F j, Y', $stamp) : '--';
}

function admin_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function admin_valid_trend_date(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed instanceof DateTimeImmutable
        && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

function admin_trend_quarter_key(string $date): string {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed) {
        return '';
    }
    $quarter = (int) floor(((int) $parsed->format('n') - 1) / 3) + 1;
    return $parsed->format('Y') . '-Q' . $quarter;
}

function admin_appointment_trend_series(mysqli $conn, string $period, DateTimeImmutable $today): array {
    $period = strtolower($period);
    $points = [];

    if ($period === 'daily') {
        for ($offset = 6; $offset >= 0; $offset--) {
            $pointDate = $today->modify('-' . $offset . ' days');
            $key = $pointDate->format('Y-m-d');
            $points[] = [
                'key' => $key,
                'label' => $pointDate->format('M j'),
                'from' => $key,
                'to' => $key,
            ];
        }
    } elseif ($period === 'monthly') {
        $currentMonth = $today->modify('first day of this month');
        for ($offset = 11; $offset >= 0; $offset--) {
            $pointDate = $currentMonth->modify('-' . $offset . ' months');
            $points[] = [
                'key' => $pointDate->format('Y-m'),
                'label' => $pointDate->format('M Y'),
                'from' => $pointDate->format('Y-m-01'),
                'to' => $pointDate->modify('last day of this month')->format('Y-m-d'),
            ];
        }
    } else {
        $quarterStartMonth = (int) (floor(((int) $today->format('n') - 1) / 3) * 3 + 1);
        $currentQuarter = $today->setDate((int) $today->format('Y'), $quarterStartMonth, 1);
        for ($offset = 7; $offset >= 0; $offset--) {
            $pointDate = $currentQuarter->modify('-' . ($offset * 3) . ' months');
            $quarter = (int) floor(((int) $pointDate->format('n') - 1) / 3) + 1;
            $points[] = [
                'key' => $pointDate->format('Y') . '-Q' . $quarter,
                'label' => 'Q' . $quarter . ' ' . $pointDate->format('Y'),
                'from' => $pointDate->format('Y-m-01'),
                'to' => $pointDate->modify('+2 months')->modify('last day of this month')->format('Y-m-d'),
            ];
        }
    }

    $values = [];
    foreach ($points as $point) {
        $values[$point['key']] = 0;
    }

    $from = $points[0]['from'] ?? $today->format('Y-m-d');
    $lastPoint = $points[count($points) - 1] ?? null;
    $to = $lastPoint['to'] ?? $today->format('Y-m-d');
    $stmt = $conn->prepare(
        'SELECT appointment_date, COUNT(*) AS total
         FROM appointments
         WHERE appointment_date BETWEEN ? AND ?
         GROUP BY appointment_date'
    );
    if ($stmt) {
        $stmt->bind_param('ss', $from, $to);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $date = (string) ($row['appointment_date'] ?? '');
                $key = $period === 'daily'
                    ? $date
                    : ($period === 'monthly' ? substr($date, 0, 7) : admin_trend_quarter_key($date));
                if (array_key_exists($key, $values)) {
                    $values[$key] += (int) $row['total'];
                }
            }
        }
        $stmt->close();
    }

    return [
        'labels' => array_values(array_map(static fn(array $point): string => $point['label'], $points)),
        'values' => array_values($values),
        'total' => array_sum($values),
        'range' => $period === 'daily' ? 'Last 7 days' : ($period === 'monthly' ? 'Last 12 months' : 'Last 8 quarters'),
    ];
}

function admin_short_text(?string $text, int $limit = 64): string {
    $text = trim((string) $text);
    if ($text === '') {
        return 'None';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function admin_booking_label(?string $type): string {
    return [
        'consultation' => 'Doctor consultation',
        'package' => 'Laboratory package',
        'individual' => 'Laboratory tests',
        'ultrasound' => 'Ultra sound',
    ][(string) $type] ?? 'Clinic appointment';
}

function admin_appointment_services_text(array $appointment): string {
    $notes = trim((string) ($appointment['notes'] ?? ''));
    $bookingType = (string) ($appointment['booking_type'] ?? '');
    if ($bookingType === 'consultation') {
        return 'Doctor consultation';
    }
    if ($bookingType === 'ultrasound') {
        return 'Ultra sound';
    }
    if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notes, $matches)) {
        $services = trim((string) ($matches[1] ?? ''));
        if ($services !== '') {
            return $services;
        }
    }
    return admin_booking_label($bookingType);
}

function admin_appointment_services_with_prices(array $appointment, array $labServicePrices): string {
    $services = admin_appointment_services_text($appointment);
    $bookingType = (string) ($appointment['booking_type'] ?? '');
    if (!in_array($bookingType, ['individual', 'package'], true) || $services === '' || $services === 'Not listed') {
        return $services;
    }

    $serviceNames = $bookingType === 'individual'
        ? (preg_split('/\s*,\s*/', $services) ?: [$services])
        : [$services];
    $pricedServices = [];
    foreach ($serviceNames as $serviceName) {
        $serviceName = trim((string) $serviceName);
        if ($serviceName === '') {
            continue;
        }
        $priceKey = strtolower($serviceName);
        if (array_key_exists($priceKey, $labServicePrices)) {
            $pricedServices[] = $serviceName . ' - PHP ' . number_format((float) $labServicePrices[$priceKey], 2);
        } else {
            $pricedServices[] = $serviceName;
        }
    }
    return $pricedServices !== [] ? implode(', ', $pricedServices) : $services;
}

function admin_appointment_total_text(array $appointment): string {
    $notes = trim((string) ($appointment['notes'] ?? ''));
    if (preg_match('/Total:\s*(PHP\s*[0-9,]+(?:\.[0-9]{2})?)/i', $notes, $matches)) {
        return 'Total: ' . trim((string) $matches[1]);
    }
    return $notes !== '' ? $notes : 'None';
}

$conn = getDBConnection();
init_admin_notifications($conn);
init_doctor_schema_and_accounts($conn);
if (
    function_exists('initLabBookingSchema') &&
    (
        !admin_table_exists($conn, 'lab_services') ||
        !admin_table_exists($conn, 'medical_records') ||
        !admin_column_exists($conn, 'users', 'is_active')
    )
) {
    initLabBookingSchema($conn);
}

$message = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

$showAdminNotificationsPage = isset($_GET['notifications']) && $_GET['notifications'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_admin_notifications_read'])) {
    mark_admin_notifications_read($conn);
    $_SESSION['success'] = 'Notifications marked as read.';
    header('Location: ' . ($showAdminNotificationsPage ? 'admin.php?notifications=1' : 'admin.php'));
    exit();
}

$roleCounts = [
    'admin' => 0,
    'patient' => 0,
    'doctor' => 0,
];
$roleResult = $conn->query("SELECT CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END AS role, COUNT(*) AS total FROM users GROUP BY CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END");
if ($roleResult) {
    while ($row = $roleResult->fetch_assoc()) {
        $role = (string) $row['role'];
        if (isset($roleCounts[$role])) {
            $roleCounts[$role] = (int) $row['total'];
        }
    }
}

$appointmentStatus = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$statusResult = $conn->query('SELECT status, COUNT(*) AS total FROM appointments GROUP BY status');
if ($statusResult) {
    while ($row = $statusResult->fetch_assoc()) {
        $status = strtolower((string) $row['status']);
        if (isset($appointmentStatus[$status])) {
            $appointmentStatus[$status] = (int) $row['total'];
        }
    }
}

$todayStatus = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$todayStmt = $conn->prepare('SELECT status, COUNT(*) AS total FROM appointments WHERE appointment_date = ? GROUP BY status');
$todayStmt->bind_param('s', $today);
$todayStmt->execute();
$todayResult = $todayStmt->get_result();
while ($row = $todayResult->fetch_assoc()) {
    $status = strtolower((string) $row['status']);
    if (isset($todayStatus[$status])) {
        $todayStatus[$status] = (int) $row['total'];
    }
}
$todayStmt->close();

$appointmentRequests = [];
$patientNameSql = dbUsersNameExpression('p');
$doctorNameSql = dbUsersNameExpression('d');
$appointmentRequestsPerPage = 5;
$appointmentRequestsPage = max(1, (int) ($_GET['appointment_page'] ?? 1));
$appointmentRequestsTotal = admin_count_query($conn, "SELECT COUNT(*) AS total FROM appointments WHERE status = 'pending'");
$appointmentRequestsTotalPages = max(1, (int) ceil($appointmentRequestsTotal / $appointmentRequestsPerPage));
$appointmentRequestsPage = min($appointmentRequestsPage, $appointmentRequestsTotalPages);
$appointmentRequestsOffset = ($appointmentRequestsPage - 1) * $appointmentRequestsPerPage;
$requestStmt = $conn->prepare("SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.booking_type, a.notes, a.total_display_price, a.cancellation_reason,
                                a.patient_id,
                                {$patientNameSql} AS patient_name,
                                p.phone AS patient_phone,
                                p.email AS patient_email,
                                p.profile_photo,
                                p.profile_updated_at,
                                {$doctorNameSql} AS doctor_name
                                FROM appointments a
                                JOIN users p ON p.id = a.patient_id
                                LEFT JOIN users d ON d.id = a.doctor_id
                                WHERE a.status = 'pending'
                                ORDER BY a.appointment_date ASC, a.appointment_time ASC
                                LIMIT ? OFFSET ?");
$requestStmt->bind_param('ii', $appointmentRequestsPerPage, $appointmentRequestsOffset);
$requestStmt->execute();
$appointmentRequests = $requestStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$requestStmt->close();

$labServicePrices = [];
if (admin_table_exists($conn, 'lab_services')) {
    $labPriceResult = $conn->query('SELECT name, opd_price FROM lab_services');
    if ($labPriceResult) {
        while ($labPriceRow = $labPriceResult->fetch_assoc()) {
            $labServiceName = strtolower(trim((string) ($labPriceRow['name'] ?? '')));
            if ($labServiceName !== '') {
                $labServicePrices[$labServiceName] = (float) ($labPriceRow['opd_price'] ?? 0);
            }
        }
    }
}

$activeDoctors = admin_count_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'doctor' AND COALESCE(is_active, 1) = 1");
$inactiveDoctors = max(0, $roleCounts['doctor'] - $activeDoctors);
$activeLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 1');
$inactiveLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 0');
$packages = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 1');
$individualTests = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 0');
$medicalRecordCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM medical_records');
$labResultCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_result_entries');

$doctors = [];
$doctorNameSql = dbUsersNameExpression();
$doctorResult = $conn->query("SELECT id, {$doctorNameSql} AS full_name, specialty, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY COALESCE(is_active, 1) DESC, {$doctorNameSql} ASC LIMIT 6");
if ($doctorResult) {
    $doctors = $doctorResult->fetch_all(MYSQLI_ASSOC);
}

$adminNotifications = fetch_admin_notifications($conn, $showAdminNotificationsPage ? 50 : 8);
$unreadNotificationCount = count_unread_admin_notifications($conn);

$trendDate = trim((string) ($_GET['trend_date'] ?? $today));
if (!admin_valid_trend_date($trendDate)) {
    $trendDate = $today;
}
$trendToday = new DateTimeImmutable($trendDate);
$todayDate = new DateTimeImmutable($today);
if ($trendToday > $todayDate) {
    $trendDate = $today;
    $trendToday = $todayDate;
}
$trendReferenceLabel = $trendToday->format('F j, Y');
$trendMonthLabel = $trendToday->format('F Y');
$trendQuarter = (int) floor(((int) $trendToday->format('n') - 1) / 3) + 1;
$trendQuarterLabel = 'Q' . $trendQuarter . ' ' . $trendToday->format('Y');
$trendData = [
    'daily' => admin_appointment_trend_series($conn, 'daily', $trendToday),
    'monthly' => admin_appointment_trend_series($conn, 'monthly', $trendToday),
    'quarterly' => admin_appointment_trend_series($conn, 'quarterly', $trendToday),
];

$conn->close();

$totalUsers = array_sum($roleCounts);
$totalAppointments = array_sum($appointmentStatus);
$todayTotal = array_sum($todayStatus);
$openAppointments = $appointmentStatus['pending'] + $appointmentStatus['confirmed'];
$staffTotal = $roleCounts['admin'] + $roleCounts['doctor'];
$completedToday = $todayStatus['completed'];
$completedAppointments = $appointmentStatus['completed'];
$pendingAppointments = $appointmentStatus['pending'];

$additionalStyles = patientAvatarStyles() . '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.admin-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 46px;
}

.admin-wrap > section {
    padding-top: 0;
    padding-bottom: 0;
}

.admin-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
    gap: 16px;
    align-items: stretch;
    margin-bottom: 18px;
}

.hero-main,
.hero-side,
.metric-card,
.panel,
.activity-item {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
}

.hero-main {
    background: linear-gradient(135deg, #0077b6 0%, #064b9f 100%);
    color: #ffffff;
    padding: 28px 32px;
    display: grid;
    align-content: center;
    gap: 8px;
}

.eyebrow {
    margin: 0;
    color: #d8f3ff;
    font-size: 0.78rem;
    font-weight: 900;
    letter-spacing: 0;
    text-transform: uppercase;
}

.hero-main h1 {
    margin: 0;
    color: #ffffff;
    font-size: 2rem;
    line-height: 1.15;
}

.hero-main p {
    margin: 0;
    color: rgba(255, 255, 255, 0.9);
    line-height: 1.6;
}

.hero-side {
    padding: 18px;
    background: linear-gradient(135deg, #eef8ff 0%, #ffffff 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.clinic-illustration {
    width: 180px;
    height: 92px;
    position: relative;
}

.clinic-illustration::before {
    content: "";
    position: absolute;
    inset: 36px 0 8px;
    border-radius: 8px;
    border: 3px solid #b7ddf4;
    background: #f8fcff;
}

.clinic-illustration .clipboard {
    position: absolute;
    right: 26px;
    top: 0;
    width: 54px;
    height: 72px;
    border: 4px solid #2d9cdb;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 20px rgba(15, 124, 194, 0.12);
}

.clinic-illustration .clipboard::before {
    content: "";
    position: absolute;
    left: 17px;
    top: -11px;
    width: 20px;
    height: 14px;
    border-radius: 8px 8px 4px 4px;
    background: #0f7cc2;
}

.clinic-illustration .clipboard::after {
    content: "+";
    position: absolute;
    left: 17px;
    top: 16px;
    color: #2d9cdb;
    font-size: 28px;
    font-weight: 950;
}

.clinic-illustration .tube {
    position: absolute;
    bottom: 14px;
    width: 10px;
    height: 42px;
    border-radius: 7px;
    border: 3px solid #64b5e8;
    background: linear-gradient(#fff 42%, #9be7ff 42%);
}

.clinic-illustration .tube.one { left: 28px; }
.clinic-illustration .tube.two { left: 47px; height: 50px; }
.clinic-illustration .tube.three { left: 66px; }

.clinic-illustration .leaf {
    position: absolute;
    right: -2px;
    bottom: 18px;
    width: 32px;
    height: 46px;
    border-left: 3px solid #8ac7c8;
}

.clinic-illustration .leaf::before,
.clinic-illustration .leaf::after {
    content: "";
    position: absolute;
    left: 3px;
    width: 18px;
    height: 10px;
    border-radius: 18px 18px 18px 0;
    background: #bce5dc;
}

.clinic-illustration .leaf::before { top: 7px; }
.clinic-illustration .leaf::after { top: 24px; width: 24px; }

.message {
    border-radius: 8px;
    padding: 13px 14px;
    margin-bottom: 14px;
    font-weight: 800;
}

.message.ok {
    background: #e7f7ed;
    color: #17643a;
    border: 1px solid #bfe6ce;
}

.message.error {
    background: #fff0f0;
    color: #9d1c2c;
    border: 1px solid #ffd0d5;
}

.admin-notifications-page {
    display: grid;
    gap: 16px;
}

.admin-notifications-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    border: 1px solid #d7eaf4;
    border-radius: 8px;
    padding: 24px;
    background:
        radial-gradient(circle at 92% 18%, rgba(72, 202, 228, 0.22), transparent 30%),
        linear-gradient(135deg, #ffffff 0%, #eefaff 100%);
    box-shadow: 0 16px 34px rgba(25, 76, 110, 0.08);
}

.admin-notifications-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #0077b6;
    font-size: 0.86rem;
    font-weight: 950;
    text-transform: uppercase;
}

.admin-notifications-hero h1 {
    margin: 8px 0 6px;
    color: #073b4c;
    font-size: 2rem;
    line-height: 1.12;
}

.admin-notifications-hero p {
    margin: 0;
    color: #58707d;
    line-height: 1.55;
}

.admin-unread-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 120px;
    min-height: 46px;
    border-radius: 999px;
    background: #eaf8ff;
    color: #0077b6;
    font-weight: 950;
}

.admin-notification-feed {
    display: grid;
    gap: 12px;
}

.admin-notification-card {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    border: 1px solid #dce8ef;
    border-radius: 8px;
    padding: 16px;
    background: #ffffff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.05);
}

.admin-notification-card.unread {
    border-color: #8ed9ef;
    background: #f2fbff;
}

.admin-notification-icon {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #e7f7ed;
    color: #17643a;
}

.admin-notification-icon svg {
    width: 22px;
    height: 22px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.admin-notification-card h3 {
    margin: 0;
    color: #073b4c;
    font-size: 1.02rem;
}

.admin-notification-card p {
    margin: 5px 0 8px;
    color: #58707d;
    line-height: 1.45;
}

.admin-notification-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    color: #71838d;
    font-size: 0.84rem;
    font-weight: 800;
}

.admin-notification-status {
    display: inline-flex;
    border-radius: 999px;
    padding: 4px 9px;
    background: #eaf8ff;
    color: #0077b6;
    font-size: 0.72rem;
    font-weight: 950;
    text-transform: uppercase;
}

.admin-notification-open {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    border-radius: 8px;
    padding: 0 14px;
    background: #eef8ff;
    color: #0b4f80;
    font-weight: 950;
    text-decoration: none;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 18px;
}

.metric-card {
    padding: 22px;
    display: grid;
    grid-template-columns: 58px minmax(0, 1fr);
    gap: 16px;
    align-items: center;
}

.metric-card span {
    color: #314a6f;
    font-size: 0.9rem;
    font-weight: 900;
}

.metric-card strong {
    color: #0066cc;
    font-size: 2.2rem;
    line-height: 1;
}

.metric-card small {
    color: #0b65c2;
    font-weight: 700;
}

.metric-icon {
    width: 58px;
    height: 58px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: #edf6ff;
    color: #0f7cc2;
}

.metric-icon svg {
    width: 28px;
    height: 28px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.metric-card.ok .metric-icon {
    background: #e7f7ed;
    color: #0f9f62;
}

.metric-card.alert .metric-icon {
    background: #fff7e6;
    color: #b87500;
}

.metric-copy {
    display: grid;
    gap: 5px;
}

.metric-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 5px;
    color: #0066cc;
    font-size: 0.84rem;
    font-weight: 900;
    text-decoration: none;
}

.metric-link:hover {
    color: #0b4f80;
}

.trend-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 18px;
}

.trend-card {
    width: 100%;
    border: 1px solid #dce8ef;
    border-radius: 8px;
    padding: 18px 20px;
    background: #fff;
    color: #1f343d;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    cursor: pointer;
    font: inherit;
    text-align: left;
    transition: border-color .2s ease, background .2s ease, box-shadow .2s ease, transform .2s ease;
}

.trend-card:hover,
.trend-card:focus-visible {
    border-color: #8ed9ef;
    background: #f8fcff;
    box-shadow: 0 12px 26px rgba(25, 76, 110, 0.10);
    outline: none;
    transform: translateY(-1px);
}

.trend-card.is-active {
    border-color: #0f7cc2;
    background: #eef8ff;
    box-shadow: 0 12px 28px rgba(15, 124, 194, 0.13);
}

.trend-card-label {
    display: block;
    color: #607784;
    font-size: .78rem;
    font-weight: 900;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.trend-card strong {
    display: block;
    margin-top: 9px;
    color: #0066cc;
    font-size: 1.8rem;
    line-height: 1;
}

.trend-card small {
    display: block;
    margin-top: 8px;
    color: #708792;
    font-size: .82rem;
}

.trend-panel {
    margin-bottom: 18px;
}

.trend-panel-head {
    align-items: center;
}

.trend-panel-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    flex-wrap: wrap;
}

.trend-date-form {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.trend-date-form label {
    color: #073b4c;
    font-size: .76rem;
    font-weight: 900;
    white-space: nowrap;
}

.trend-date-form input {
    min-height: 36px;
    box-sizing: border-box;
    border: 1px solid #cfe1ee;
    border-radius: 8px;
    padding: 6px 9px;
    background: #fff;
    color: #1f343d;
    font: inherit;
    font-size: .82rem;
}

.trend-date-form input:focus {
    outline: none;
    border-color: #0f7cc2;
    box-shadow: 0 0 0 3px rgba(15, 124, 194, .10);
}

.trend-tabs {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px;
    border: 1px solid #d8e6ed;
    border-radius: 8px;
    background: #f5faff;
}

.trend-tab {
    min-height: 34px;
    border: 0;
    border-radius: 6px;
    padding: 6px 12px;
    background: transparent;
    color: #607784;
    cursor: pointer;
    font: inherit;
    font-size: .8rem;
    font-weight: 900;
}

.trend-tab:hover,
.trend-tab:focus-visible {
    color: #0b4f80;
    outline: 2px solid #8ed9ef;
    outline-offset: 1px;
}

.trend-tab.is-active {
    background: #0f7cc2;
    color: #fff;
}

.trend-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
    color: #607784;
    font-size: .84rem;
}

.trend-meta strong {
    color: #073b4c;
    font-size: 1rem;
}

.trend-chart-wrap {
    min-height: 280px;
}

.trend-chart {
    display: block;
    width: 100%;
    height: auto;
    overflow: visible;
}

.trend-chart .grid-line {
    stroke: #e5eef3;
    stroke-width: 1;
}

.trend-chart .axis-line {
    stroke: #b7cbd6;
    stroke-width: 1.2;
}

.trend-chart .trend-area {
    fill: rgba(15, 124, 194, .10);
    opacity: 0;
    animation: adminTrendFade .65s ease-out .1s forwards;
}

.trend-chart .trend-line {
    fill: none;
    stroke: #0077b6;
    stroke-width: 3;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 1400;
    stroke-dashoffset: 1400;
    animation: adminTrendDraw .8s ease-out forwards;
}

.trend-chart .trend-dot {
    fill: #fff;
    stroke: #0077b6;
    stroke-width: 2;
    opacity: 0;
    animation: adminTrendFade .35s ease-out .25s forwards;
}

.trend-chart text {
    fill: #607784;
    font-family: inherit;
    font-size: 11px;
}

.trend-chart .axis-title {
    fill: #073b4c;
    font-size: 11px;
    font-weight: 800;
}

.trend-empty {
    display: none;
    padding: 34px 10px;
    color: #607784;
    text-align: center;
    font-weight: 700;
}

@keyframes adminTrendDraw {
    to { stroke-dashoffset: 0; }
}

@keyframes adminTrendFade {
    to { opacity: 1; }
}

.main-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(300px, 0.8fr);
    gap: 16px;
    align-items: start;
    margin-bottom: 16px;
}

.dashboard-stack {
    display: grid;
    gap: 16px;
}

.panel {
    padding: 20px;
}

.panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
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

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    min-height: 38px;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px 13px;
    background: #0f7cc2;
    color: #fff;
    cursor: pointer;
    font-weight: 900;
    text-decoration: none;
    transition: background 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}

.btn:hover {
    background: #0b4f80;
    transform: translateY(-1px);
}

.btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.status-grid {
    display: grid;
    gap: 8px;
}

.status-row {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr) 42px;
    gap: 10px;
    align-items: center;
    color: #364d58;
    font-weight: 800;
}

.bar {
    height: 10px;
    border-radius: 999px;
    background: #edf3f7;
    overflow: hidden;
}

.bar span {
    display: block;
    height: 100%;
    min-width: 4px;
    border-radius: inherit;
    background: #0f7cc2;
}

.bar.pending span {
    background: #d09b21;
}

.bar.confirmed span,
.bar.completed span {
    background: #17643a;
}

.bar.cancelled span {
    background: #c1121f;
}

.activity-list {
    display: grid;
    gap: 9px;
}

.activity-item {
    padding: 12px;
    display: grid;
    gap: 4px;
}

.activity-item strong {
    color: #073b4c;
}

.activity-item span {
    color: #60727d;
    font-size: 0.9rem;
}

.queue-list {
    display: grid;
    gap: 10px;
}

.queue-table-wrap {
    overflow: auto;
    border: 1px solid #e0ebf3;
    border-radius: 8px;
}

.queue-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 680px;
}

.appointment-request-table {
    table-layout: fixed;
}

.appointment-request-table th:nth-child(1),
.appointment-request-table td:nth-child(1) { width: 33%; }
.appointment-request-table th:nth-child(2),
.appointment-request-table td:nth-child(2) { width: 18%; }
.appointment-request-table th:nth-child(3),
.appointment-request-table td:nth-child(3) { width: 20%; }
.appointment-request-table th:nth-child(4),
.appointment-request-table td:nth-child(4) { width: 12%; }
.appointment-request-table th:nth-child(5),
.appointment-request-table td:nth-child(5) { width: 17%; }

.appointment-request-table .queue-patient,
.appointment-request-table .queue-service {
    min-width: 0;
}

.appointment-request-table .queue-patient > div,
.appointment-request-table .queue-service > div {
    min-width: 0;
}

.appointment-request-table .queue-patient strong,
.appointment-request-table .queue-service strong {
    overflow-wrap: anywhere;
}

.appointment-request-table .queue-patient small,
.appointment-request-table .queue-service small {
    display: block;
    line-height: 1.35;
}

.appointment-request-table .queue-patient small {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.queue-table th,
.queue-table td {
    padding: 13px 16px;
    border-bottom: 1px solid #e6eef4;
    text-align: left;
}

.queue-table th {
    background: #f8fcff;
    color: #466779;
    font-size: 0.82rem;
    font-weight: 950;
}

.queue-table tr:last-child td {
    border-bottom: 0;
}

.queue-patient {
    display: flex;
    align-items: center;
    gap: 10px;
}

.queue-patient strong,
.queue-service strong {
    display: block;
    color: #073b4c;
}

.queue-patient small,
.queue-service small {
    color: #60727d;
    font-size: 0.82rem;
}
.request-schedule {
    min-width: 0;
    white-space: normal;
}
.request-schedule strong,
.request-schedule span {
    display: block;
}
.request-schedule strong {
    font-size: 0.84rem;
    line-height: 1.25;
}
.request-schedule span {
    margin-top: 2px;
    color: #60727d;
    font-size: 0.82rem;
}

.queue-action {
    width: 38px;
    height: 34px;
    border: 1px solid #d4e6f5;
    border-radius: 8px;
    background: #f8fcff;
    color: #0b4f80;
    cursor: pointer;
    font-size: 1.2rem;
    font-weight: 950;
}

.queue-action:hover {
    border-color: #8ed9ef;
    background: #eaf8ff;
}

.request-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.request-actions form {
    margin: 0;
}

.request-actions .btn {
    min-height: 34px;
    padding: 7px 12px;
    font-size: 0.82rem;
}

.appointment-request-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    padding: 14px 2px 2px;
}

.appointment-request-pagination .pagination-summary {
    margin: 0;
    color: #60727d;
    font-size: 0.86rem;
}

.appointment-request-pagination .pagination-controls {
    display: inline-flex;
    flex: 0 0 auto;
    flex-direction: row;
    align-items: center;
    gap: 6px;
    padding: 0;
}

.appointment-request-pagination .pagination-controls::before {
    content: none;
    display: none;
}

.appointment-request-pagination .pagination-button,
.appointment-request-pagination .pagination-page {
    width: 36px;
    min-width: 36px;
    max-width: 36px;
    height: 36px;
    min-height: 36px;
    max-height: 36px;
    padding: 0;
    box-sizing: border-box;
    flex: 0 0 36px;
    border: 1px solid #d4e6f5;
    border-radius: 8px;
    background: #f8fcff;
    color: #075da7;
    font: inherit;
    font-size: 0.86rem;
    font-weight: 850;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.appointment-request-pagination .pagination-button:not(:disabled):hover,
.appointment-request-pagination .pagination-button:not(:disabled):focus-visible {
    border-color: #8ed9ef;
    background: #eaf8ff;
}

.appointment-request-pagination .pagination-button:disabled {
    color: #aebfca;
    background: #f6f9fb;
    cursor: not-allowed;
}

.appointment-request-pagination .pagination-page.current {
    border-color: #0f7cc2;
    background: #0f7cc2;
    color: #fff;
    box-shadow: 0 6px 14px rgba(15, 124, 194, 0.18);
}

@media (max-width: 560px) {
    .appointment-request-pagination {
        align-items: flex-start;
        flex-direction: column;
    }

    .appointment-request-pagination .pagination-controls {
        width: 100%;
        justify-content: flex-end;
    }
}

.queue-item {
    width: 100%;
    border: 1px solid #dce8ef;
    border-radius: 10px;
    padding: 13px;
    background: #fff;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    text-align: left;
    cursor: pointer;
    box-shadow: 0 10px 22px rgba(25, 76, 110, 0.05);
}

.queue-item:hover {
    border-color: #8ed9ef;
    background: #f8fcff;
}

.queue-main strong,
.queue-meta span {
    display: block;
}

.queue-main strong {
    color: #073b4c;
    font-size: 1rem;
}

.queue-main span,
.queue-meta {
    color: #60727d;
    font-size: 0.9rem;
}

.queue-meta {
    text-align: right;
}

.queue-management {
    margin-top: 18px;
    padding: 16px;
    background: #fff;
}

.queue-management-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 14px;
    align-items: start;
}

.queue-tools {
    display: grid;
    grid-template-columns: 220px minmax(0, 1fr);
    gap: 14px;
    margin-bottom: 16px;
}

.queue-add-details {
    position: relative;
}

.queue-add-details summary {
    list-style: none;
}

.queue-add-details summary::-webkit-details-marker {
    display: none;
}

.queue-add-button,
.queue-search-shell {
    min-height: 52px;
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
}

.queue-add-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 100%;
    padding: 0 18px;
    background: #0d6bed;
    color: #fff;
    cursor: pointer;
    font-size: 1rem;
    font-weight: 900;
    box-shadow: 0 12px 24px rgba(13, 107, 237, 0.18);
}

.queue-add-panel {
    position: absolute;
    z-index: 20;
    top: calc(100% + 8px);
    left: 0;
    width: min(520px, calc(100vw - 48px));
    border: 1px solid #cfe1ee;
    border-radius: 8px;
    padding: 14px;
    background: #fff;
    box-shadow: 0 20px 40px rgba(25, 76, 110, 0.16);
}

.queue-search-shell {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 16px;
}

.queue-search-shell svg {
    width: 24px;
    height: 24px;
    fill: none;
    stroke: #0b4f80;
    stroke-width: 2.3;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.queue-search-shell input {
    width: 100%;
    border: 0;
    outline: 0;
    color: #0b2352;
    font: inherit;
    font-weight: 800;
}

.queue-search-shell input::placeholder {
    color: #5d6d8f;
    font-weight: 700;
}

.queue-section h3,
.now-serving-card h3 {
    margin: 0;
    color: #061b57;
    font-size: 1.05rem;
    font-weight: 950;
}

.queue-form-grid label {
    display: grid;
    gap: 5px;
    color: #466779;
    font-size: 0.82rem;
    font-weight: 900;
}

.queue-form-grid input,
.queue-form-grid select {
    width: 100%;
    min-height: 42px;
    border: 1px solid #cfe1ee;
    border-radius: 8px;
    padding: 8px 11px;
    color: #1f343d;
    background: #fff;
    font: inherit;
}

.queue-sections {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.queue-section {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
}

.queue-section h3 {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 14px 16px;
    background: #eaf7ff;
    border-bottom: 1px solid #e6eef4;
}

.queue-title-text {
    display: inline-flex;
    align-items: center;
    gap: 9px;
}

.queue-title-text svg {
    width: 26px;
    height: 26px;
    fill: none;
    stroke: #0d6bed;
    stroke-width: 2.4;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.queue-count-pill {
    border-radius: 8px;
    padding: 7px 10px;
    background: #dcefff;
    color: #0066cc;
    font-size: 0.78rem;
    font-weight: 950;
}

.queue-section .queue-table {
    min-width: 520px;
}

.queue-section .queue-table th,
.queue-section .queue-table td {
    padding: 11px 12px;
    color: #061b57;
    font-size: 0.82rem;
    font-weight: 800;
}

.queue-section .queue-table th {
    color: #122b63;
    font-size: 0.74rem;
}

.queue-number {
    color: #061b57;
    font-weight: 950;
}

.queue-search-results {
    grid-column: 1 / -1;
    display: grid;
    gap: 8px;
    margin-bottom: 14px;
    max-height: 220px;
    overflow: auto;
}

.queue-search-row {
    display: grid;
    gap: 2px;
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 10px;
    background: #fff;
}

.queue-search-row strong {
    color: #073b4c;
}

.queue-search-row span {
    color: #60727d;
    font-size: 0.84rem;
}

.now-serving-card {
    position: sticky;
    top: 98px;
    border: 1px solid #dce8ef;
    border-radius: 8px;
    padding: 0;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
}

.now-serving-card h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px;
    background: #eaf7ff;
}

.now-serving-card h3 svg {
    width: 28px;
    height: 28px;
    fill: #0b3b95;
}

.now-serving-number {
    display: block;
    margin: 12px 12px 0;
    padding: 22px 12px 4px;
    border-radius: 8px 8px 0 0;
    background: linear-gradient(135deg, #eef9ff 0%, #dff1ff 100%);
    color: #061b57;
    text-align: center;
    font-size: 2.55rem;
    line-height: 1;
    font-weight: 950;
}

.now-serving-name {
    display: block;
    margin: 0 12px;
    padding: 0 12px 18px;
    border-radius: 0 0 8px 8px;
    background: linear-gradient(135deg, #eef9ff 0%, #dff1ff 100%);
    color: #061b57;
    text-align: center;
    font-size: 1rem;
    font-weight: 950;
}

.now-serving-details {
    display: grid;
    gap: 10px;
    margin: 16px 16px;
    color: #061b57;
    font-size: 0.86rem;
    font-weight: 850;
}

.now-serving-details span {
    display: flex;
    justify-content: space-between;
    gap: 12px;
}

.now-serving-details strong {
    color: #1f343d;
}

.now-serving-actions {
    display: grid;
    gap: 10px;
    padding: 0 16px 16px;
}

.now-serving-actions .btn,
.now-serving-card > form .btn {
    width: 100%;
    min-height: 46px;
    border-radius: 6px;
}

.now-serving-card > form {
    padding: 0 16px 16px;
}

.queue-empty {
    padding: 14px 16px;
    color: #60727d;
}

.queue-bottom-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 14px;
    margin-top: 14px;
}

.queue-history-card,
.queue-priority-card {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
}

.queue-history-card h3,
.queue-priority-card h3 {
    margin: 0;
    padding: 14px 16px;
    color: #061b57;
    font-size: 1rem;
    font-weight: 950;
    border-bottom: 1px solid #e6eef4;
}

.queue-priority-card {
    padding-bottom: 12px;
    background: linear-gradient(135deg, #eef9ff 0%, #dff1ff 100%);
}

.queue-priority-card ol {
    margin: 12px 18px 0 34px;
    padding: 0;
    color: #061b57;
    font-size: 0.88rem;
    font-weight: 750;
}

.queue-priority-card li + li {
    margin-top: 8px;
}

.doctor-list {
    display: grid;
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    overflow: hidden;
}

.doctor-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: center;
    padding: 14px;
    border-bottom: 1px solid #edf3f7;
}

.doctor-row:last-child {
    border-bottom: 0;
}

.doctor-row strong {
    display: block;
    color: #073b4c;
    font-size: 0.95rem;
}

.doctor-row small {
    color: #60727d;
    font-weight: 700;
}

.doctor-schedule-link {
    width: 100%;
    max-width: 100%;
    margin-top: 14px;
}

.admin-modal {
    position: fixed;
    inset: 0;
    z-index: 4000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(7, 24, 38, 0.54);
}

.admin-modal.is-open {
    display: flex;
}

.admin-modal-card {
    width: min(680px, 100%);
    border: 1px solid #dce8ef;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(7, 24, 38, 0.26);
    overflow: hidden;
}

.admin-modal-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 20px 22px;
    background: #f8fcff;
    border-bottom: 1px solid #e7f0f5;
}

.admin-modal-head h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.18rem;
}

.admin-modal-head p {
    margin: 5px 0 0;
    color: #60727d;
}

.admin-modal-close {
    width: 40px;
    height: 40px;
    border: 1px solid #c7e5f4;
    border-radius: 10px;
    background: #eef8fc;
    color: #075985;
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
}

.admin-modal-body {
    padding: 20px 22px;
}

.admin-detail-grid {
    display: grid;
    grid-template-columns: 130px minmax(0, 1fr);
    gap: 10px 14px;
}

.admin-detail-grid span {
    color: #60727d;
    font-weight: 900;
}

.admin-detail-grid strong {
    color: #073b4c;
    overflow-wrap: anywhere;
}

.admin-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    padding: 0 22px 22px;
}

.admin-modal-actions form {
    margin: 0;
}

.admin-cancel-reason {
    display: none;
    padding: 0 22px 18px;
}

.admin-cancel-reason.is-open {
    display: block;
}

.admin-cancel-reason label {
    display: block;
    margin-bottom: 8px;
    color: #073b4c;
    font-weight: 900;
}

.admin-cancel-reason textarea {
    width: 100%;
    min-height: 96px;
    resize: vertical;
    border: 1px solid #d4e6f5;
    border-radius: 8px;
    background: #fbfdff;
    color: #1f343d;
    font: inherit;
    line-height: 1.45;
    padding: 12px;
    box-sizing: border-box;
}

.admin-cancel-reason textarea:focus {
    border-color: #0f7cc2;
    box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.1);
    outline: none;
}

.admin-cancel-reason small {
    display: none;
    margin-top: 7px;
    color: #9d1c2c;
    font-weight: 800;
}

.admin-cancel-reason small.is-open {
    display: block;
}

.admin-success-card {
    width: min(420px, 100%);
    text-align: center;
}

.admin-success-body {
    padding: 30px 28px 28px;
}

.admin-success-icon {
    display: inline-grid;
    place-items: center;
    width: 64px;
    height: 64px;
    margin-bottom: 16px;
    border-radius: 50%;
    background: #dcf7e7;
    color: #148047;
    font-size: 2rem;
    font-weight: 950;
}

.admin-success-body h2 {
    margin: 0 0 8px;
    color: #073b4c;
    font-size: 1.28rem;
}

.admin-success-body p {
    margin: 0 0 20px;
    color: #60727d;
    line-height: 1.45;
}

.btn.danger {
    background: #fff0f0;
    color: #9d1c2c;
    border-color: #ffd0d5;
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 6px 10px;
    font-size: 0.74rem;
    font-weight: 900;
    text-transform: uppercase;
}

.badge.pending {
    background: #fff3cd;
    color: #856404;
}

.badge.waiting {
    background: #fff7e6;
    color: #8a5a00;
}

.badge.serving {
    background: #eaf8ff;
    color: #0077b6;
}

.badge.confirmed,
.badge.active {
    background: #e7f7ed;
    color: #17643a;
}

.badge.completed {
    background: #e8f4f8;
    color: #0b4f80;
}

.badge.cancelled,
.badge.inactive {
    background: #fff0f0;
    color: #9d1c2c;
}

.health-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.health-box {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 12px;
    background: #f8fbff;
}

.health-box span {
    display: block;
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.health-box strong {
    display: block;
    color: #073b4c;
    margin-top: 5px;
    font-size: 1.3rem;
}

.empty-state {
    border: 1px dashed #bdd7ea;
    border-radius: 8px;
    padding: 18px;
    color: #60727d;
    background: #f8fbff;
}

.empty-state.hidden {
    display: none;
}

@media (max-width: 980px) {
    .admin-hero,
    .main-grid,
    .metrics-grid,
    .trend-metrics,
    .queue-management-grid,
    .queue-tools {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hero-main {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .admin-wrap {
        padding: 18px 12px 36px;
    }

    .admin-notifications-hero,
    .admin-notification-card {
        grid-template-columns: 1fr;
    }

    .admin-notifications-hero {
        align-items: flex-start;
        flex-direction: column;
    }

    .admin-notification-open {
        width: 100%;
    }

    .admin-hero,
    .main-grid,
    .metrics-grid,
    .trend-metrics,
    .queue-management-grid,
    .queue-tools,
    .health-grid {
        grid-template-columns: 1fr;
    }

    .now-serving-card {
        position: static;
    }

    .status-row {
        grid-template-columns: 92px minmax(0, 1fr) 34px;
    }

    .queue-item {
        grid-template-columns: 1fr;
    }

    .queue-meta {
        text-align: left;
    }

    .admin-detail-grid {
        grid-template-columns: 1fr;
    }

    .trend-panel-head {
        align-items: flex-start;
        flex-direction: column;
    }

    .trend-panel-actions {
        width: 100%;
        align-items: stretch;
        flex-direction: column;
    }

    .trend-date-form {
        align-items: stretch;
        flex-direction: column;
    }

    .trend-date-form input {
        width: 100%;
    }

    .trend-tabs {
        width: 100%;
    }

    .trend-tab {
        flex: 1;
    }

    .trend-chart-wrap {
        min-height: 220px;
    }

    .trend-chart text {
        font-size: 9px;
    }

    .btn {
        width: 100%;
    }
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    var dataNode = document.getElementById("adminTrendData");
    var chart = document.getElementById("adminTrendChart");
    if (!dataNode || !chart) return;

    var trendDateInput = document.getElementById("adminTrendDate");
    var trendScrollStorageKey = "adminAppointmentReportScrollY";
    function submitTrendForm() {
        try {
            sessionStorage.setItem(trendScrollStorageKey, String(window.scrollY || window.pageYOffset || 0));
        } catch (error) {
            // Scroll restoration is an enhancement; form submission must still work if storage is unavailable.
        }
        if (trendDateInput && trendDateInput.form) trendDateInput.form.submit();
    }

    if (trendDateInput) {
        var defaultTrendDate = trendDateInput.getAttribute("data-default-value") || trendDateInput.value;
        var trendPeriodInput = trendDateInput.form ? trendDateInput.form.querySelector("[name=trend_period]") : null;
        trendDateInput.removeAttribute("onchange");
        trendDateInput.addEventListener("change", function () {
            if (trendPeriodInput && typeof activePeriod !== "undefined" && activePeriod) {
                trendPeriodInput.value = activePeriod;
            }
            if (!trendDateInput.value) {
                trendDateInput.value = defaultTrendDate;
                submitTrendForm();
                return;
            }
            submitTrendForm();
        });
    }

    var trendData;
    try {
        trendData = JSON.parse(dataNode.textContent || "{}");
    } catch (error) {
        trendData = {};
    }

    var emptyState = document.getElementById("adminTrendEmpty");
    var trendPanel = document.querySelector(".trend-panel");
    var description = document.getElementById("adminTrendDescription");
    var range = document.getElementById("adminTrendRange");
    var total = document.getElementById("adminTrendTotal");
    var svgNamespace = "http://www.w3.org/2000/svg";
    var periodNames = { daily: "Daily", monthly: "Monthly", quarterly: "Quarterly" };
    var activePeriod = null;

    function svgElement(name, attributes) {
        var node = document.createElementNS(svgNamespace, name);
        Object.keys(attributes || {}).forEach(function (key) {
            node.setAttribute(key, attributes[key]);
        });
        return node;
    }

    function addText(parent, attributes, value) {
        var node = svgElement("text", attributes);
        node.textContent = value;
        parent.appendChild(node);
        return node;
    }

    function renderTrend(period) {
        var dataset = trendData[period];
        if (!dataset) return;
        activePeriod = period;
        if (trendPanel) {
            trendPanel.hidden = false;
            trendPanel.setAttribute("aria-hidden", "false");
        }
        var labels = Array.isArray(dataset.labels) ? dataset.labels : [];
        var values = Array.isArray(dataset.values) ? dataset.values.map(function (value) { return Number(value) || 0; }) : [];
        var totalValue = Number(dataset.total) || 0;
        var hasData = values.length > 0 && totalValue > 0;

        document.querySelectorAll("[data-trend-card]").forEach(function (button) {
            var active = button.getAttribute("data-trend-card") === period;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-pressed", active ? "true" : "false");
        });
        document.querySelectorAll("[data-trend-tab]").forEach(function (button) {
            var active = button.getAttribute("data-trend-tab") === period;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", active ? "true" : "false");
        });

        if (description) description.textContent = periodNames[period] + " appointments for " + (dataset.range || "the selected period") + ".";
        if (range) range.textContent = dataset.range || "Selected period";
        if (total) total.textContent = "Total: " + totalValue + " appointment" + (totalValue === 1 ? "" : "s");
        while (chart.firstChild) chart.removeChild(chart.firstChild);

        if (!hasData) {
            chart.style.display = "none";
            if (emptyState) emptyState.style.display = "block";
            return;
        }
        chart.style.display = "block";
        if (emptyState) emptyState.style.display = "none";

        var width = 920;
        var height = 320;
        var left = 58;
        var right = 22;
        var top = 22;
        var bottom = 52;
        var plotWidth = width - left - right;
        var plotHeight = height - top - bottom;
        var maxValue = Math.max.apply(null, values);
        var chartMax = Math.max(4, Math.ceil(maxValue / 4) * 4);
        var points = values.map(function (value, index) {
            var x = left + (values.length > 1 ? (index / (values.length - 1)) * plotWidth : plotWidth / 2);
            var y = top + plotHeight - ((value / chartMax) * plotHeight);
            return { x: x, y: y, value: value, label: labels[index] || "" };
        });

        for (var tick = 0; tick <= 4; tick++) {
            var tickY = top + plotHeight - ((tick / 4) * plotHeight);
            chart.appendChild(svgElement("line", { "class": "grid-line", x1: left, y1: tickY, x2: width - right, y2: tickY }));
            addText(chart, { x: left - 9, y: tickY + 4, "text-anchor": "end" }, String(Math.round(chartMax * (tick / 4))));
        }
        chart.appendChild(svgElement("line", { "class": "axis-line", x1: left, y1: top, x2: left, y2: top + plotHeight }));
        chart.appendChild(svgElement("line", { "class": "axis-line", x1: left, y1: top + plotHeight, x2: width - right, y2: top + plotHeight }));
        addText(chart, { "class": "axis-title", x: 14, y: top + (plotHeight / 2), transform: "rotate(-90 14 " + (top + (plotHeight / 2)) + ")", "text-anchor": "middle" }, "Appointments");

        var linePoints = points.map(function (point) { return point.x + "," + point.y; }).join(" ");
        var baseline = top + plotHeight;
        var areaPoints = linePoints + " " + points[points.length - 1].x + "," + baseline + " " + points[0].x + "," + baseline;
        chart.appendChild(svgElement("polygon", { "class": "trend-area", points: areaPoints }));
        chart.appendChild(svgElement("polyline", { "class": "trend-line", points: linePoints }));

        points.forEach(function (point) {
            var circle = svgElement("circle", { "class": "trend-dot", cx: point.x, cy: point.y, r: 4 });
            var title = svgElement("title", {});
            title.textContent = point.label + ": " + point.value + " appointment" + (point.value === 1 ? "" : "s");
            circle.appendChild(title);
            chart.appendChild(circle);
            addText(chart, { x: point.x, y: baseline + 24, "text-anchor": "middle" }, point.label);
        });
        addText(chart, { "class": "axis-title", x: left + (plotWidth / 2), y: height - 7, "text-anchor": "middle" }, "Period");
    }

    function hideTrend() {
        activePeriod = null;
        if (trendPanel) {
            trendPanel.hidden = true;
            trendPanel.setAttribute("aria-hidden", "true");
        }
        while (chart.firstChild) chart.removeChild(chart.firstChild);
        if (emptyState) emptyState.style.display = "none";
        document.querySelectorAll("[data-trend-card]").forEach(function (button) {
            button.classList.remove("is-active");
            button.setAttribute("aria-pressed", "false");
        });
        document.querySelectorAll("[data-trend-tab]").forEach(function (button) {
            button.classList.remove("is-active");
            button.setAttribute("aria-selected", "false");
        });
    }

    document.querySelectorAll("[data-trend-card], [data-trend-tab]").forEach(function (button) {
        button.addEventListener("click", function () {
            var period = button.getAttribute("data-trend-card") || button.getAttribute("data-trend-tab");
            if (activePeriod === period && trendPanel && !trendPanel.hidden) {
                hideTrend();
            } else {
                renderTrend(period);
            }
        });
    });
    var initialPeriod = trendPeriodInput ? trendPeriodInput.value : "";
    if (["daily", "monthly", "quarterly"].indexOf(initialPeriod) !== -1) {
        renderTrend(initialPeriod);
    } else {
        hideTrend();
    }

    try {
        var savedScrollY = sessionStorage.getItem(trendScrollStorageKey);
        if (savedScrollY !== null && savedScrollY !== "") {
            sessionStorage.removeItem(trendScrollStorageKey);
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    window.scrollTo(0, Number(savedScrollY) || 0);
                });
            });
        }
    } catch (error) {
        // Ignore storage restrictions and keep the normal page behavior.
    }
});

document.addEventListener("DOMContentLoaded", function () {
    var modal = document.getElementById("adminAppointmentModal");
    if (!modal) return;

    function setText(id, value) {
        var node = document.getElementById(id);
        if (node) node.textContent = value || "None";
    }

    function setForm(formId, appointmentId, status) {
        var form = document.getElementById(formId);
        if (!form) return;
        var idInput = form.querySelector("[name=appointment_id]");
        var statusInput = form.querySelector("[name=status]");
        if (idInput) idInput.value = appointmentId || "";
        if (statusInput) statusInput.value = status;
    }

    function setDetailRow(rowName, visible) {
        document.querySelectorAll("[data-admin-detail-row=\"" + rowName + "\"]").forEach(function (node) {
            node.style.display = visible ? "" : "none";
        });
    }

    function closeModal() {
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
    }

    document.querySelectorAll("[data-admin-appointment]").forEach(function (button) {
        button.addEventListener("click", function () {
            var data = button.dataset;
            setText("adminModalPatient", data.patient);
            setText("adminModalSub", data.schedule);
            setText("adminDetailPatient", data.patient);
            setText("adminDetailContact", data.contact);
            setText("adminDetailDoctor", data.doctor);
            setText("adminDetailSchedule", data.schedule);
            setText("adminDetailType", data.type);
            setText("adminDetailServices", data.services);
            setText("adminDetailStatus", data.statusLabel);
            setText("adminDetailNotes", data.notes);
            var bookingType = (data.bookingType || "").toLowerCase();
            var isKnownBookingType = ["consultation", "ultrasound", "package", "individual"].indexOf(bookingType) !== -1;
            setDetailRow("doctor", !isKnownBookingType || bookingType === "consultation");
            setDetailRow("type", !isKnownBookingType || bookingType !== "consultation");
            setDetailRow("services", !isKnownBookingType || bookingType === "package" || bookingType === "individual");
            var reasonRows = document.querySelectorAll("[data-admin-cancel-reason-detail]");
            setText("adminDetailCancelReason", data.cancelReason);
            reasonRows.forEach(function (node) {
                node.style.display = data.cancelReason && data.cancelReason !== "None" ? "" : "none";
            });
            setForm("adminConfirmForm", data.id, "confirmed");
            setForm("adminDeclineForm", data.id, "cancelled");

            var pendingActions = document.getElementById("adminPendingActions");
            if (pendingActions) {
                var adminCanRespond = data.status === "pending" && data.bookingType !== "consultation";
                pendingActions.style.display = adminCanRespond ? "flex" : "none";
            }
            modal.classList.add("is-open");
            modal.setAttribute("aria-hidden", "false");
        });
    });

    modal.querySelectorAll("[data-admin-close-modal]").forEach(function (button) {
        button.addEventListener("click", closeModal);
    });
    modal.addEventListener("click", function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.classList.contains("is-open")) {
            closeModal();
        }
    });

    document.querySelectorAll("[data-confirm-message]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            var confirmModal = document.getElementById("adminConfirmActionModal");
            var confirmText = document.getElementById("adminConfirmActionText");
            var confirmProceed = document.getElementById("adminConfirmActionProceed");
            var cancelReasonWrap = document.getElementById("adminCancelReasonWrap");
            var cancelReasonInput = document.getElementById("adminCancelReason");
            var cancelReasonError = document.getElementById("adminCancelReasonError");
            var form = button.closest("form");
            if (!confirmModal || !form) return;

            event.preventDefault();
            confirmModal.pendingForm = form;
            if (confirmText) {
                confirmText.textContent = button.getAttribute("data-confirm-message") || "Are you sure you want to continue?";
            }
            var statusInput = form.querySelector("[name=status]");
            var needsReason = statusInput && statusInput.value === "cancelled";
            if (cancelReasonWrap) cancelReasonWrap.classList.toggle("is-open", !!needsReason);
            if (cancelReasonInput) {
                cancelReasonInput.value = "";
                cancelReasonInput.required = !!needsReason;
            }
            if (cancelReasonError) cancelReasonError.classList.remove("is-open");
            confirmModal.classList.add("is-open");
            confirmModal.setAttribute("aria-hidden", "false");
            if (needsReason && cancelReasonInput) {
                window.setTimeout(function () { cancelReasonInput.focus(); }, 80);
            } else if (confirmProceed) {
                confirmProceed.focus();
            }
        });
    });

    var confirmActionModal = document.getElementById("adminConfirmActionModal");
    var confirmActionProceed = document.getElementById("adminConfirmActionProceed");
    var confirmCancelReasonWrap = document.getElementById("adminCancelReasonWrap");
    var confirmCancelReasonInput = document.getElementById("adminCancelReason");
    var confirmCancelReasonError = document.getElementById("adminCancelReasonError");
    var actionSuccessModal = document.getElementById("adminActionSuccessModal");
    var actionSuccessTitle = document.getElementById("adminActionSuccessTitle");
    var actionSuccessText = document.getElementById("adminActionSuccessText");
    var actionSuccessOk = document.getElementById("adminActionSuccessOk");

    function closeConfirmActionModal() {
        if (!confirmActionModal) return;
        confirmActionModal.classList.remove("is-open");
        confirmActionModal.setAttribute("aria-hidden", "true");
        confirmActionModal.pendingForm = null;
        if (confirmCancelReasonWrap) confirmCancelReasonWrap.classList.remove("is-open");
        if (confirmCancelReasonInput) confirmCancelReasonInput.value = "";
        if (confirmCancelReasonError) confirmCancelReasonError.classList.remove("is-open");
    }

    function showActionSuccess(status, message) {
        closeModal();
        closeConfirmActionModal();
        if (actionSuccessTitle) {
            actionSuccessTitle.textContent = status === "cancelled"
                ? "Appointment cancelled"
                : (status === "completed" ? "Appointment completed" : "Appointment confirmed");
        }
        if (actionSuccessText) {
            actionSuccessText.textContent = message || (status === "cancelled"
                ? "Appointment cancelled successfully."
                : (status === "completed" ? "Appointment completed successfully." : "Appointment confirmed successfully."));
        }
        if (actionSuccessModal) {
            actionSuccessModal.classList.add("is-open");
            actionSuccessModal.setAttribute("aria-hidden", "false");
        }
    }

    if (confirmActionProceed) {
        confirmActionProceed.addEventListener("click", async function () {
            var form = confirmActionModal ? confirmActionModal.pendingForm : null;
            var statusInput = form ? form.querySelector("[name=status]") : null;
            if (statusInput && statusInput.value === "cancelled") {
                var reason = confirmCancelReasonInput ? confirmCancelReasonInput.value.trim() : "";
                if (!reason) {
                    if (confirmCancelReasonError) confirmCancelReasonError.classList.add("is-open");
                    if (confirmCancelReasonInput) confirmCancelReasonInput.focus();
                    return;
                }
                var hiddenReason = form.querySelector("[name=cancellation_reason]");
                if (!hiddenReason) {
                    hiddenReason = document.createElement("input");
                    hiddenReason.type = "hidden";
                    hiddenReason.name = "cancellation_reason";
                    form.appendChild(hiddenReason);
                }
                hiddenReason.value = reason;
            }
            if (!form) return;
            var oldText = confirmActionProceed.textContent;
            confirmActionProceed.disabled = true;
            confirmActionProceed.textContent = "Saving...";
            try {
                var response = await fetch(form.action || "update_appointment_status.php", {
                    method: "POST",
                    body: new FormData(form),
                    headers: {
                        "Accept": "application/json",
                        "X-Requested-With": "XMLHttpRequest"
                    }
                });
                var result = await response.json();
                if (result && result.ok) {
                    showActionSuccess(statusInput ? statusInput.value : "", result.message);
                } else if (confirmCancelReasonError) {
                    confirmCancelReasonError.textContent = (result && result.message) || "Unable to update appointment.";
                    confirmCancelReasonError.classList.add("is-open");
                }
            } catch (error) {
                form.submit();
            } finally {
                confirmActionProceed.disabled = false;
                confirmActionProceed.textContent = oldText;
            }
        });
    }

    if (actionSuccessOk) {
        actionSuccessOk.addEventListener("click", function () {
            window.location.href = "admin.php";
        });
    }

    document.querySelectorAll("[data-admin-confirm-close]").forEach(function (button) {
        button.addEventListener("click", closeConfirmActionModal);
    });

    if (confirmActionModal) {
        confirmActionModal.addEventListener("click", function (event) {
            if (event.target === confirmActionModal) closeConfirmActionModal();
        });
    }

    if (actionSuccessModal) {
        actionSuccessModal.addEventListener("click", function (event) {
            if (event.target === actionSuccessModal) {
                window.location.href = "admin.php";
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeConfirmActionModal();
    });

    var queueSearchInput = document.getElementById("queueSearchInput");
    var queueSearchRows = Array.prototype.slice.call(document.querySelectorAll("[data-queue-search]"));
    if (queueSearchInput) {
        queueSearchInput.addEventListener("input", function () {
            var query = queueSearchInput.value.trim().toLowerCase();
            queueSearchRows.forEach(function (row) {
                row.style.display = !query || (row.getAttribute("data-queue-search") || "").indexOf(query) !== -1 ? "" : "none";
            });
        });
    }
});
';

include 'includes/header.php';
?>
<main class="admin-wrap">
    <section class="admin-hero">
        <div class="hero-main">
            <p class="eyebrow">Clinic overview</p>
            <h1>Administrator Dashboard</h1>
            <p>Welcome back. Here is what needs attention in the clinic today.</p>
        </div>
        <aside class="hero-side" aria-label="Current admin">
            <div class="clinic-illustration" aria-hidden="true">
                <span class="tube one"></span>
                <span class="tube two"></span>
                <span class="tube three"></span>
                <span class="clipboard"></span>
                <span class="leaf"></span>
            </div>
        </aside>
    </section>

    <?php if ($message): ?>
        <div class="message ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($showAdminNotificationsPage): ?>
        <section class="admin-notifications-page" aria-labelledby="adminNotificationsTitle">
            <div class="admin-notifications-hero">
                <div>
                    <span class="admin-notifications-kicker">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7M10 20a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        Admin notifications
                    </span>
                    <h1 id="adminNotificationsTitle">Clinic notifications</h1>
                    <p>New patient accounts, appointment bookings, cancellations, and important clinic updates appear here.</p>
                </div>
                <div class="admin-notifications-actions">
                    <span class="admin-unread-pill"><?php echo (int) $unreadNotificationCount; ?> unread</span>
                    <?php if ($unreadNotificationCount > 0): ?>
                        <form method="post">
                            <button class="btn secondary" type="submit" name="mark_admin_notifications_read" value="1">Mark all as read</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($adminNotifications)): ?>
                <div class="empty-state">No admin notifications yet.</div>
            <?php else: ?>
                <div class="admin-notification-feed">
                    <?php foreach ($adminNotifications as $notification): ?>
                        <?php
                        $notificationId = (int) ($notification['id'] ?? 0);
                        $notificationType = strtolower((string) ($notification['notification_type'] ?? ''));
                        $notificationTime = strtotime((string) ($notification['created_at'] ?? ''));
                        $notificationDate = $notificationTime ? date('M d, Y g:i A', $notificationTime) : '';
                        $isUnread = empty($notification['read_at']);
                        $statusLabel = str_replace('_', ' ', $notificationType !== '' ? $notificationType : 'admin update');
                        $openUrl = trim((string) ($notification['target_url'] ?? ''));
                        if ($openUrl === '') {
                            $openUrl = strpos($notificationType, 'appointment') !== false ? 'admin_view_appointments.php' : 'admin_accounts.php';
                        }
                        $openLabel = strpos($notificationType, 'appointment') !== false ? 'Open appointment' : 'Open accounts';
                        ?>
                        <article id="notification-<?php echo $notificationId; ?>" class="admin-notification-card <?php echo $isUnread ? 'unread' : ''; ?>">
                            <span class="admin-notification-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><path d="M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M19 8v6M22 11h-6"/></svg>
                            </span>
                            <div>
                                <h3><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Admin notification')); ?></h3>
                                <p><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                                <span class="admin-notification-meta">
                                    <?php if ($notificationDate !== ''): ?>
                                        <span><?php echo htmlspecialchars($notificationDate); ?></span>
                                    <?php endif; ?>
                                    <span class="admin-notification-status"><?php echo htmlspecialchars($statusLabel); ?></span>
                                    <span><?php echo $isUnread ? 'Unread' : 'Read'; ?></span>
                                </span>
                            </div>
                            <a class="admin-notification-open" href="<?php echo htmlspecialchars($openUrl); ?>"><?php echo htmlspecialchars($openLabel); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <?php include 'includes/footer.php'; ?>
    <?php exit; ?>
    <?php endif; ?>

    <section class="trend-metrics" aria-label="Appointment report periods">
        <button class="trend-card" type="button" data-trend-card="daily" aria-pressed="false">
            <span class="trend-card-label">Daily</span>
            <strong><?php echo (int) ($trendData['daily']['values'][count($trendData['daily']['values']) - 1] ?? 0); ?></strong>
            <small>Appointments on <?php echo htmlspecialchars($trendReferenceLabel); ?></small>
        </button>
        <button class="trend-card" type="button" data-trend-card="monthly" aria-pressed="false">
            <span class="trend-card-label">Monthly</span>
            <strong><?php echo (int) ($trendData['monthly']['values'][count($trendData['monthly']['values']) - 1] ?? 0); ?></strong>
            <small>Appointments in <?php echo htmlspecialchars($trendMonthLabel); ?></small>
        </button>
        <button class="trend-card" type="button" data-trend-card="quarterly" aria-pressed="false">
            <span class="trend-card-label">Quarterly</span>
            <strong><?php echo (int) ($trendData['quarterly']['values'][count($trendData['quarterly']['values']) - 1] ?? 0); ?></strong>
            <small>Appointments in <?php echo htmlspecialchars($trendQuarterLabel); ?></small>
        </button>
    </section>

    <section class="panel trend-panel" aria-labelledby="adminTrendTitle" aria-hidden="true" hidden>
        <div class="panel-head trend-panel-head">
            <div>
                <h2 id="adminTrendTitle">Appointment Report</h2>
                <p id="adminTrendDescription">Daily appointments for the last 7 days.</p>
            </div>
            <div class="trend-panel-actions">
                <form class="trend-date-form" method="get">
                    <label for="adminTrendDate">Reference date</label>
                    <input type="hidden" name="trend_period" value="<?php echo htmlspecialchars((string) ($_GET['trend_period'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="date" id="adminTrendDate" name="trend_date" value="<?php echo htmlspecialchars($trendDate, ENT_QUOTES, 'UTF-8'); ?>" max="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>" data-default-value="<?php echo htmlspecialchars($today, ENT_QUOTES, 'UTF-8'); ?>">
                </form>
                <div class="trend-tabs" role="tablist" aria-label="Appointment report period">
                    <button class="trend-tab" type="button" role="tab" aria-selected="false" data-trend-tab="daily">Daily</button>
                    <button class="trend-tab" type="button" role="tab" aria-selected="false" data-trend-tab="monthly">Monthly</button>
                    <button class="trend-tab" type="button" role="tab" aria-selected="false" data-trend-tab="quarterly">Quarterly</button>
                </div>
            </div>
        </div>
        <div class="trend-meta">
            <span id="adminTrendRange">Last 7 days</span>
            <strong id="adminTrendTotal">Total: <?php echo (int) ($trendData['daily']['total'] ?? 0); ?> appointments</strong>
        </div>
        <div class="trend-chart-wrap">
            <svg id="adminTrendChart" class="trend-chart" viewBox="0 0 920 320" role="img" aria-label="Daily appointment report chart"></svg>
            <div id="adminTrendEmpty" class="trend-empty">No appointments found for this period.</div>
        </div>
    </section>

    <script type="application/json" id="adminTrendData"><?php echo json_encode($trendData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

    <section class="metrics-grid" aria-label="Clinic summary">
        <div class="metric-card">
            <span class="metric-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M8 2v4M16 2v4M3 10h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
            </span>
            <div class="metric-copy">
                <span>Appointments</span>
                <strong><?php echo $totalAppointments; ?></strong>
                <a class="metric-link" href="admin_view_appointments.php?date_range=all">View appointments &rsaquo;</a>
            </div>
        </div>
        <div class="metric-card alert">
            <span class="metric-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 6v6l4 2"/><path d="M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9z"/></svg>
            </span>
            <div class="metric-copy">
                <span>Pending</span>
                <strong><?php echo $pendingAppointments; ?></strong>
                <a class="metric-link" href="admin_view_appointments.php?date_range=all&amp;status=pending">View pending &rsaquo;</a>
            </div>
        </div>
        <div class="metric-card ok">
            <span class="metric-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/><path d="M21 12a9 9 0 1 1-9-9"/></svg>
            </span>
            <div class="metric-copy">
                <span>Completed</span>
                <strong><?php echo $completedAppointments; ?></strong>
                <a class="metric-link" href="admin_view_appointments.php?date_range=all&amp;status=completed">View completed &rsaquo;</a>
            </div>
        </div>
    </section>

    <section class="main-grid">
        <div class="dashboard-stack">
            <div class="panel" id="appointment-requests">
                <div class="panel-head">
                    <div>
                        <h2>Appointment Requests</h2>
                        <p>Review and respond to new appointment requests.</p>
                    </div>
                </div>
                <?php if (empty($appointmentRequests)): ?>
                    <div class="empty-state">No pending appointment requests.</div>
                <?php else: ?>
                    <div class="queue-table-wrap">
                        <table class="queue-table appointment-request-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Service</th>
                                    <th class="request-schedule">Schedule</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointmentRequests as $appointment): ?>
                                    <?php
                                    $requestDate = admin_date_label($appointment['appointment_date']);
                                    $requestTime = admin_time_label($appointment['appointment_time']);
                                    $requestSchedule = $requestDate . ' ' . $requestTime;
                                    $requestServices = admin_appointment_services_text($appointment);
                                    $requestServicesWithPrices = admin_appointment_services_with_prices($appointment, $labServicePrices);
                                    $requestPriceDetails = in_array((string) ($appointment['booking_type'] ?? ''), ['individual', 'package'], true)
                                        ? admin_appointment_total_text($appointment)
                                        : (string) (($appointment['notes'] ?? '') ?: 'None');
                                    $requestContact = trim((string) (($appointment['patient_phone'] ?? '') ?: ($appointment['patient_email'] ?? '')));
                                    $requestContact = $requestContact !== '' ? $requestContact : 'No contact saved';
                                    $requestDoctor = trim((string) ($appointment['doctor_name'] ?? ''));
                                    $requestDoctor = $requestDoctor !== '' ? $requestDoctor : 'Not assigned';
                                    $requestStatus = strtolower((string) ($appointment['status'] ?? 'pending'));
                                    $requestStatusLabel = $requestStatus === 'cancelled' ? 'Cancelled' : ucfirst($requestStatus);
                                    $requestBookingType = (string) ($appointment['booking_type'] ?? '');
                                    $requestBookingLabel = admin_booking_label($requestBookingType);
                                    $adminCanRespond = $requestBookingType !== 'consultation';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="queue-patient">
                                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) ($appointment['patient_id'] ?? 0)]); ?>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                                    <small title="<?php echo htmlspecialchars($requestContact, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($requestContact); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="queue-service">
                                            <strong><?php echo htmlspecialchars($requestBookingLabel); ?></strong>
                                            <?php if ($requestServices !== $requestBookingLabel): ?>
                                                <small><?php echo htmlspecialchars($requestServices); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="request-schedule">
                                            <strong><?php echo htmlspecialchars($requestDate); ?></strong>
                                            <span><?php echo htmlspecialchars($requestTime); ?></span>
                                        </td>
                                        <td><span class="badge <?php echo htmlspecialchars($requestStatus); ?>"><?php echo htmlspecialchars($requestStatusLabel); ?></span></td>
                                        <td>
                                            <div class="request-actions">
                                                <button
                                                    type="button"
                                                    class="btn secondary"
                                                    data-admin-appointment
                                                    data-id="<?php echo (int) $appointment['id']; ?>"
                                                    data-patient="<?php echo htmlspecialchars((string) $appointment['patient_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-contact="<?php echo htmlspecialchars($requestContact, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-doctor="<?php echo htmlspecialchars($requestDoctor, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-schedule="<?php echo htmlspecialchars($requestSchedule, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-type="<?php echo htmlspecialchars(admin_booking_label($appointment['booking_type'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-booking-type="<?php echo htmlspecialchars($requestBookingType, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-services="<?php echo htmlspecialchars($requestServicesWithPrices, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-status="<?php echo htmlspecialchars($requestStatus, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-status-label="<?php echo htmlspecialchars($requestStatusLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-notes="<?php echo htmlspecialchars($requestPriceDetails, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-cancel-reason="<?php echo htmlspecialchars((string) (($appointment['cancellation_reason'] ?? '') ?: 'None'), ENT_QUOTES, 'UTF-8'); ?>"
                                                >Details</button>
                                                <?php if ($adminCanRespond): ?>
                                                    <form method="post" action="update_appointment_status.php">
                                                        <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                                        <input type="hidden" name="status" value="confirmed">
                                                        <input type="hidden" name="return_url" value="admin.php">
                                                        <button class="btn secondary" type="submit" data-confirm-message="Are you sure you want to confirm this appointment request?">Confirm</button>
                                                    </form>
                                                    <form method="post" action="update_appointment_status.php">
                                                        <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                                        <input type="hidden" name="status" value="cancelled">
                                                        <input type="hidden" name="return_url" value="admin.php">
                                                        <button class="btn danger" type="submit" data-confirm-message="Are you sure you want to cancel this appointment request?">Cancel</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                    $appointmentRequestsFirst = $appointmentRequestsTotal > 0
                        ? (($appointmentRequestsPage - 1) * $appointmentRequestsPerPage) + 1
                        : 0;
                    $appointmentRequestsLast = $appointmentRequestsTotal > 0
                        ? min($appointmentRequestsPage * $appointmentRequestsPerPage, $appointmentRequestsTotal)
                        : 0;
                    $appointmentRequestsPreviousUrl = 'admin.php?appointment_page=' . max(1, $appointmentRequestsPage - 1) . '#appointment-requests';
                    $appointmentRequestsNextUrl = 'admin.php?appointment_page=' . min($appointmentRequestsTotalPages, $appointmentRequestsPage + 1) . '#appointment-requests';
                    ?>
                    <div class="appointment-request-pagination" aria-label="Appointment request pagination">
                        <p class="pagination-summary">
                            Showing <?php echo (int) $appointmentRequestsFirst; ?> to <?php echo (int) $appointmentRequestsLast; ?> of <?php echo (int) $appointmentRequestsTotal; ?> appointment<?php echo $appointmentRequestsTotal === 1 ? '' : 's'; ?>.
                        </p>
                        <div class="pagination-controls" role="navigation" aria-label="Appointment request pages">
                            <?php if ($appointmentRequestsPage > 1): ?>
                                <a class="pagination-button" href="<?php echo htmlspecialchars($appointmentRequestsPreviousUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Previous page">&larr;</a>
                            <?php else: ?>
                                <button class="pagination-button" type="button" aria-label="Previous page" disabled>&larr;</button>
                            <?php endif; ?>
                            <span class="pagination-page current" aria-current="page"><?php echo (int) $appointmentRequestsPage; ?></span>
                            <?php if ($appointmentRequestsPage < $appointmentRequestsTotalPages): ?>
                                <a class="pagination-button" href="<?php echo htmlspecialchars($appointmentRequestsNextUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Next page">&rarr;</a>
                            <?php else: ?>
                                <button class="pagination-button" type="button" aria-label="Next page" disabled>&rarr;</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Doctor Availability</h2>
                    <p>Current doctor account status.</p>
                </div>
            </div>
            <?php if (empty($doctors)): ?>
                <div class="empty-state">No doctors registered yet.</div>
            <?php else: ?>
                <div class="doctor-list">
                    <?php foreach ($doctors as $doctor): ?>
                        <?php $doctorActive = (int) ($doctor['is_active'] ?? 1) === 1; ?>
                        <div class="doctor-row">
                            <div>
                                <strong><?php echo htmlspecialchars((string) ($doctor['full_name'] ?? 'Doctor')); ?></strong>
                                <small><?php echo htmlspecialchars((string) (($doctor['specialty'] ?? '') ?: 'No specialty')); ?></small>
                            </div>
                            <span class="badge <?php echo $doctorActive ? 'active' : 'inactive'; ?>"><?php echo $doctorActive ? 'Available' : 'Unavailable'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a class="btn secondary doctor-schedule-link" href="admin_doctors.php">View full schedule</a>
            <?php endif; ?>
        </div>
    </section>

    <?php if (false): ?>
    <section class="panel queue-management" id="queue-management" aria-labelledby="queueManagementTitle">
        <div class="panel-head">
            <div>
                <h2 id="queueManagementTitle">Queue Management</h2>
                <p>Walk-in patients are served first, then online appointments.</p>
            </div>
        </div>

        <div class="queue-tools">
            <details class="queue-add-details">
                <summary class="queue-add-button">+ Add to Queue</summary>
                <div class="queue-add-panel">
                    <form class="queue-form-grid" method="post" action="admin.php#queue-management">
                        <input type="hidden" name="queue_action" value="add">
                        <label>
                            Patient
                            <select name="patient_id" required>
                                <option value="">Select patient</option>
                                <?php foreach ($queuePatients as $patient): ?>
                                    <?php
                                    $patientContact = trim((string) (($patient['phone'] ?? '') ?: ($patient['email'] ?? '')));
                                    $patientLabel = (string) ($patient['full_name'] ?? 'Patient');
                                    if ($patientContact !== '') {
                                        $patientLabel .= ' - ' . $patientContact;
                                    }
                                    ?>
                                    <option value="<?php echo (int) $patient['id']; ?>"><?php echo htmlspecialchars($patientLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Queue Type
                            <select name="queue_type">
                                <option value="walk_in">Walk-in</option>
                                <option value="online">Online</option>
                            </select>
                        </label>
                        <label>
                            Online Appointment
                            <select name="appointment_id">
                                <option value="0">None</option>
                                <?php foreach ($queueAppointments as $appointment): ?>
                                    <?php
                                    $appointmentService = admin_appointment_services_text($appointment);
                                    $appointmentLabel = '#' . (int) $appointment['id'] . ' - ' . (string) $appointment['patient_name'] . ' - ' . admin_time_label($appointment['appointment_time']) . ' - ' . $appointmentService;
                                    ?>
                                    <option value="<?php echo (int) $appointment['id']; ?>"><?php echo htmlspecialchars($appointmentLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Service
                            <input type="text" name="service" value="General Consultation" placeholder="General Consultation">
                        </label>
                        <button class="btn" type="submit">Add to Queue</button>
                    </form>
                </div>
            </details>
            <div class="queue-search-shell">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35"/><circle cx="11" cy="11" r="7"/></svg>
                <input type="search" id="queueSearchInput" placeholder="Search patient name or queue number...">
            </div>
        </div>

        <div class="queue-search-results" id="queueSearchResults">
            <?php foreach ($queueRows as $queueRow): ?>
                <?php
                $queueSearchName = (string) ($queueRow['patient_name'] ?? 'Patient');
                $queueSearchNumber = (string) ($queueRow['queue_number'] ?? '');
                $queueSearchStatus = admin_queue_status_label($queueRow['status'] ?? '');
                ?>
                <div class="queue-search-row" data-queue-search="<?php echo htmlspecialchars(strtolower($queueSearchNumber . ' ' . $queueSearchName), ENT_QUOTES, 'UTF-8'); ?>">
                    <strong><?php echo htmlspecialchars($queueSearchNumber . ' - ' . $queueSearchName); ?></strong>
                    <span><?php echo htmlspecialchars(admin_queue_type_label($queueRow['queue_type'] ?? '') . ' | ' . (string) ($queueRow['service'] ?? 'General Consultation') . ' | ' . $queueSearchStatus); ?></span>
                </div>
            <?php endforeach; ?>
            <?php if (empty($queueRows)): ?>
                <div class="queue-empty">No queue records today.</div>
            <?php endif; ?>
        </div>

        <div class="queue-management-grid">
            <div>
                <div class="queue-sections">
                    <div class="queue-section">
                        <h3>
                            <span class="queue-title-text">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 4a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/><path d="M8 22l2-7-3-2 2-5h4l2 5 3 2"/><path d="m14 15 2 7"/></svg>
                                Walk-in Queue
                            </span>
                            <span class="queue-count-pill"><?php echo (int) $walkInWaitingCount; ?> waiting</span>
                        </h3>
                        <?php if (empty($walkInQueue)): ?>
                            <div class="queue-empty">No walk-in patients waiting.</div>
                        <?php else: ?>
                            <div class="queue-table-wrap">
                                <table class="queue-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Queue No.</th>
                                            <th>Patient Name</th>
                                            <th>Time Added</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($walkInQueue as $index => $queueRow): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                                <td><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                                <td><?php echo htmlspecialchars(admin_time_label($queueRow['time_added'] ?? null)); ?></td>
                                                <td><span class="badge <?php echo htmlspecialchars((string) $queueRow['status']); ?>"><?php echo htmlspecialchars(admin_queue_status_label($queueRow['status'] ?? '')); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="queue-section">
                        <h3>
                            <span class="queue-title-text">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/></svg>
                                Online Queue
                            </span>
                            <span class="queue-count-pill"><?php echo (int) $onlineWaitingCount; ?> waiting</span>
                        </h3>
                        <?php if (empty($onlineQueue)): ?>
                            <div class="queue-empty">No online appointments waiting.</div>
                        <?php else: ?>
                            <div class="queue-table-wrap">
                                <table class="queue-table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Queue No.</th>
                                            <th>Patient Name</th>
                                            <th>Time Added</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($onlineQueue as $index => $queueRow): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                                <td><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                                <td><?php echo htmlspecialchars(admin_time_label($queueRow['time_added'] ?? null)); ?></td>
                                                <td><span class="badge <?php echo htmlspecialchars((string) $queueRow['status']); ?>"><?php echo htmlspecialchars(admin_queue_status_label($queueRow['status'] ?? '')); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <aside class="now-serving-card" aria-label="Now serving">
                <h3>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>
                    Now Serving
                </h3>
                <?php if ($nowServing): ?>
                    <span class="now-serving-number"><?php echo htmlspecialchars((string) $nowServing['queue_number']); ?></span>
                    <span class="now-serving-name"><?php echo htmlspecialchars((string) $nowServing['patient_name']); ?></span>
                    <div class="now-serving-details">
                        <span>Type <strong><?php echo htmlspecialchars(admin_queue_type_label($nowServing['queue_type'] ?? '')); ?></strong></span>
                        <span>Service <strong><?php echo htmlspecialchars((string) ($nowServing['service'] ?? 'General Consultation')); ?></strong></span>
                        <span>Time Called <strong><?php echo htmlspecialchars(admin_time_label($nowServing['time_called'] ?? null)); ?></strong></span>
                    </div>
                    <div class="now-serving-actions">
                        <form method="post" action="admin.php#queue-management">
                            <input type="hidden" name="queue_action" value="complete">
                            <input type="hidden" name="queue_id" value="<?php echo (int) $nowServing['id']; ?>">
                            <button class="btn" type="submit">Mark as Completed</button>
                        </form>
                        <button class="btn secondary" type="button" disabled>Call Next</button>
                    </div>
                <?php else: ?>
                    <span class="now-serving-number">--</span>
                    <span class="now-serving-name">No patient serving</span>
                    <div class="now-serving-details">
                        <span>Status <strong>Ready</strong></span>
                    </div>
                    <form method="post" action="admin.php#queue-management">
                        <input type="hidden" name="queue_action" value="call_next">
                        <button class="btn" type="submit">Call Next</button>
                    </form>
                <?php endif; ?>
            </aside>
        </div>

        <div class="queue-bottom-grid">
            <div class="queue-history-card">
                <h3>Queue History (Today)</h3>
                <?php if (empty($completedQueue)): ?>
                    <div class="queue-empty">No completed queue records today.</div>
                <?php else: ?>
                    <div class="queue-table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Queue No.</th>
                                    <th>Patient Name</th>
                                    <th>Type</th>
                                    <th>Time Called</th>
                                    <th>Time Completed</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($completedQueue as $index => $queueRow): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                        <td><span class="badge <?php echo htmlspecialchars((string) $queueRow['queue_type']); ?>"><?php echo htmlspecialchars(admin_queue_type_label($queueRow['queue_type'] ?? '')); ?></span></td>
                                        <td><?php echo htmlspecialchars(admin_time_label($queueRow['time_called'] ?? null)); ?></td>
                                        <td><?php echo htmlspecialchars(admin_time_label($queueRow['completed_at'] ?? null)); ?></td>
                                        <td><span class="badge completed">Completed</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="queue-priority-card">
                <h3>Queue Priority</h3>
                <ol>
                    <li>Walk-in patients are given priority over online patients.</li>
                    <li>Only one patient can be Now Serving at a time.</li>
                    <li>Queue numbers reset daily.</li>
                </ol>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <div class="admin-modal" id="adminAppointmentModal" aria-hidden="true">
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminModalPatient">
            <div class="admin-modal-head">
                <div>
                    <h2 id="adminModalPatient">Appointment request</h2>
                    <p id="adminModalSub">Review appointment details</p>
                </div>
                <button class="admin-modal-close" type="button" data-admin-close-modal aria-label="Close appointment details">&times;</button>
            </div>
            <div class="admin-modal-body">
                <div class="admin-detail-grid">
                    <span>Patient</span><strong id="adminDetailPatient">None</strong>
                    <span>Contact</span><strong id="adminDetailContact">None</strong>
                    <span data-admin-detail-row="doctor">Doctor</span><strong data-admin-detail-row="doctor" id="adminDetailDoctor">None</strong>
                    <span>Schedule</span><strong id="adminDetailSchedule">None</strong>
                    <span data-admin-detail-row="type">Type</span><strong data-admin-detail-row="type" id="adminDetailType">None</strong>
                    <span data-admin-detail-row="services">Services</span><strong data-admin-detail-row="services" id="adminDetailServices">None</strong>
                    <span>Status</span><strong id="adminDetailStatus">None</strong>
                    <span>Notes</span><strong id="adminDetailNotes">None</strong>
                    <span data-admin-cancel-reason-detail>Cancellation reason</span><strong data-admin-cancel-reason-detail id="adminDetailCancelReason">None</strong>
                </div>
            </div>
            <div class="admin-modal-actions">
                <div id="adminPendingActions" class="admin-modal-actions" style="padding:0">
                    <form id="adminConfirmForm" method="post" action="update_appointment_status.php">
                        <input type="hidden" name="appointment_id" value="">
                        <input type="hidden" name="status" value="confirmed">
                        <input type="hidden" name="return_url" value="admin.php">
                        <button class="btn" type="submit" data-confirm-message="Are you sure you want to confirm this appointment request?">Confirm</button>
                    </form>
                    <form id="adminDeclineForm" method="post" action="update_appointment_status.php">
                        <input type="hidden" name="appointment_id" value="">
                        <input type="hidden" name="status" value="cancelled">
                        <input type="hidden" name="return_url" value="admin.php">
                        <button class="btn danger" type="submit" data-confirm-message="Are you sure you want to cancel this appointment request?">Cancel</button>
                    </form>
                </div>
                <button class="btn secondary" type="button" data-admin-close-modal>Close</button>
            </div>
        </div>
    </div>

    <div class="admin-modal" id="adminConfirmActionModal" aria-hidden="true">
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminConfirmActionTitle">
            <div class="admin-modal-head">
                <div>
                    <h2 id="adminConfirmActionTitle">Confirm appointment action</h2>
                    <p id="adminConfirmActionText">Are you sure you want to continue?</p>
                </div>
                <button class="admin-modal-close" type="button" data-admin-confirm-close aria-label="Close confirmation">&times;</button>
            </div>
            <div class="admin-cancel-reason" id="adminCancelReasonWrap">
                <label for="adminCancelReason">Cancellation reason</label>
                <textarea id="adminCancelReason" maxlength="500" placeholder="Add a short reason for cancelling this appointment"></textarea>
                <small id="adminCancelReasonError">Please add a reason before continuing.</small>
            </div>
            <div class="admin-modal-actions">
                <button class="btn secondary" type="button" data-admin-confirm-close>Back</button>
                <button class="btn" type="button" id="adminConfirmActionProceed">Yes, continue</button>
            </div>
        </div>
    </div>

    <div class="admin-modal" id="adminActionSuccessModal" aria-hidden="true">
        <div class="admin-modal-card admin-success-card" role="dialog" aria-modal="true" aria-labelledby="adminActionSuccessTitle">
            <div class="admin-success-body">
                <span class="admin-success-icon" aria-hidden="true">✓</span>
                <h2 id="adminActionSuccessTitle">Appointment updated</h2>
                <p id="adminActionSuccessText">Appointment status updated successfully.</p>
                <button class="btn" type="button" id="adminActionSuccessOk">OK</button>
            </div>
        </div>
    </div>

</main>
<?php include 'includes/footer.php'; ?>

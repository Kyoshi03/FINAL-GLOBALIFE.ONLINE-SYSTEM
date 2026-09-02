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
    return $stamp ? date('M j, Y', $stamp) : '--';
}

function admin_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
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
$requestStmt = $conn->prepare("SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.booking_type, a.notes,
                                a.patient_id,
                                p.full_name AS patient_name,
                                p.phone AS patient_phone,
                                p.email AS patient_email,
                                p.profile_photo,
                                p.profile_updated_at,
                                d.full_name AS doctor_name
                                FROM appointments a
                                JOIN users p ON p.id = a.patient_id
                                LEFT JOIN users d ON d.id = a.doctor_id
                                WHERE a.status = 'pending'
                                ORDER BY a.appointment_date ASC, a.appointment_time ASC
                                LIMIT 6");
$requestStmt->execute();
$appointmentRequests = $requestStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$requestStmt->close();

$todayQueueAppointments = [];
$queueStmt = $conn->prepare("SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.booking_type, a.notes,
                                a.patient_id,
                                p.full_name AS patient_name,
                                p.phone AS patient_phone,
                                p.email AS patient_email,
                                p.profile_photo,
                                p.profile_updated_at,
                                d.full_name AS doctor_name
                                FROM appointments a
                                JOIN users p ON p.id = a.patient_id
                                LEFT JOIN users d ON d.id = a.doctor_id
                                WHERE a.appointment_date = ?
                                  AND a.status = 'confirmed'
                                ORDER BY a.appointment_date ASC, a.appointment_time ASC
                                LIMIT 12");
$queueStmt->bind_param('s', $today);
$queueStmt->execute();
$todayQueueAppointments = $queueStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$queueStmt->close();

$activeDoctors = admin_count_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'doctor' AND COALESCE(is_active, 1) = 1");
$inactiveDoctors = max(0, $roleCounts['doctor'] - $activeDoctors);
$activeLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 1');
$inactiveLabServices = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 0');
$packages = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 1');
$individualTests = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_package = 0');
$medicalRecordCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM medical_records');
$labResultCount = admin_count_query($conn, 'SELECT COUNT(*) AS total FROM lab_result_entries');

$doctors = [];
$doctorResult = $conn->query("SELECT id, full_name, specialty, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY COALESCE(is_active, 1) DESC, full_name ASC LIMIT 6");
if ($doctorResult) {
    $doctors = $doctorResult->fetch_all(MYSQLI_ASSOC);
}

$adminNotifications = fetch_admin_notifications($conn, $showAdminNotificationsPage ? 50 : 8);
$unreadNotificationCount = count_unread_admin_notifications($conn);

$conn->close();

$totalUsers = array_sum($roleCounts);
$totalAppointments = array_sum($appointmentStatus);
$todayTotal = array_sum($todayStatus);
$openAppointments = $appointmentStatus['pending'] + $appointmentStatus['confirmed'];
$staffTotal = $roleCounts['admin'] + $roleCounts['doctor'];
$completedToday = $todayStatus['completed'];

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
    grid-template-columns: repeat(2, minmax(0, 1fr));
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
    .metrics-grid {
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
    .health-grid {
        grid-template-columns: 1fr;
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

    .btn {
        width: 100%;
    }
}
';

$additionalScripts = '
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
            var form = button.closest("form");
            if (!confirmModal || !form) return;

            event.preventDefault();
            confirmModal.pendingForm = form;
            if (confirmText) {
                confirmText.textContent = button.getAttribute("data-confirm-message") || "Are you sure you want to continue?";
            }
            confirmModal.classList.add("is-open");
            confirmModal.setAttribute("aria-hidden", "false");
            if (confirmProceed) confirmProceed.focus();
        });
    });

    var confirmActionModal = document.getElementById("adminConfirmActionModal");
    var confirmActionProceed = document.getElementById("adminConfirmActionProceed");

    function closeConfirmActionModal() {
        if (!confirmActionModal) return;
        confirmActionModal.classList.remove("is-open");
        confirmActionModal.setAttribute("aria-hidden", "true");
        confirmActionModal.pendingForm = null;
    }

    if (confirmActionProceed) {
        confirmActionProceed.addEventListener("click", function () {
            var form = confirmActionModal ? confirmActionModal.pendingForm : null;
            closeConfirmActionModal();
            if (form) form.submit();
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

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeConfirmActionModal();
    });
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
                            $openUrl = strpos($notificationType, 'appointment') !== false ? 'view_appointments.php' : 'admin_accounts.php';
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

    <section class="metrics-grid" aria-label="Clinic summary">
        <div class="metric-card">
            <span class="metric-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M8 2v4M16 2v4M3 10h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/></svg>
            </span>
            <div class="metric-copy">
                <span>Appointments today</span>
                <strong><?php echo $todayTotal; ?></strong>
                <a class="metric-link" href="view_appointments.php">View appointments &rsaquo;</a>
            </div>
        </div>
        <div class="metric-card ok">
            <span class="metric-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/><path d="M21 12a9 9 0 1 1-9-9"/></svg>
            </span>
            <div class="metric-copy">
                <span>Completed today</span>
                <strong><?php echo $completedToday; ?></strong>
                <a class="metric-link" href="view_appointments.php">View completed &rsaquo;</a>
            </div>
        </div>
    </section>

    <section class="main-grid">
        <div class="dashboard-stack">
            <div class="panel">
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
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Patient</th>
                                    <th>Service</th>
                                    <th>Date &amp; Time</th>
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
                                    $requestContact = trim((string) (($appointment['patient_phone'] ?? '') ?: ($appointment['patient_email'] ?? '')));
                                    $requestContact = $requestContact !== '' ? $requestContact : 'No contact saved';
                                    $requestDoctor = trim((string) ($appointment['doctor_name'] ?? ''));
                                    $requestDoctor = $requestDoctor !== '' ? $requestDoctor : 'Not assigned';
                                    $requestStatus = strtolower((string) ($appointment['status'] ?? 'pending'));
                                    $requestStatusLabel = $requestStatus === 'cancelled' ? 'Declined' : ucfirst($requestStatus);
                                    $requestBookingType = (string) ($appointment['booking_type'] ?? '');
                                    $adminCanRespond = $requestBookingType !== 'consultation';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="queue-patient">
                                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) ($appointment['patient_id'] ?? 0)]); ?>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                                    <small><?php echo htmlspecialchars($requestContact); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="queue-service">
                                            <strong><?php echo htmlspecialchars(admin_booking_label($appointment['booking_type'] ?? null)); ?></strong>
                                            <small><?php echo htmlspecialchars($requestServices); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($requestSchedule); ?></td>
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
                                                    data-services="<?php echo htmlspecialchars($requestServices, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-status="<?php echo htmlspecialchars($requestStatus, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-status-label="<?php echo htmlspecialchars($requestStatusLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-notes="<?php echo htmlspecialchars((string) (($appointment['notes'] ?? '') ?: 'None'), ENT_QUOTES, 'UTF-8'); ?>"
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
                                                        <button class="btn danger" type="submit" data-confirm-message="Are you sure you want to decline this appointment request?">Cancel</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2 id="adminQueueTitle">Today&apos;s Queue</h2>
                        <p>Patients scheduled today and waiting to be serviced.</p>
                    </div>
                </div>
                <?php if (empty($todayQueueAppointments)): ?>
                    <div class="empty-state">No patients waiting for service today.</div>
                <?php else: ?>
                    <div class="queue-table-wrap">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Patient</th>
                                    <th>Service</th>
                                    <th>Schedule</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayQueueAppointments as $index => $appointment): ?>
                                    <?php
                                    $queueDate = admin_date_label($appointment['appointment_date']);
                                    $queueTime = admin_time_label($appointment['appointment_time']);
                                    $queueSchedule = $queueDate . ' ' . $queueTime;
                                    $queueServices = admin_appointment_services_text($appointment);
                                    $queueContact = trim((string) (($appointment['patient_phone'] ?? '') ?: ($appointment['patient_email'] ?? '')));
                                    $queueContact = $queueContact !== '' ? $queueContact : 'No contact saved';
                                    $queueDoctor = trim((string) ($appointment['doctor_name'] ?? ''));
                                    $queueDoctor = $queueDoctor !== '' ? $queueDoctor : 'Not assigned';
                                    $queueStatus = strtolower((string) ($appointment['status'] ?? 'pending'));
                                    $queueStatusLabel = $queueStatus === 'cancelled' ? 'Declined' : ucfirst($queueStatus);
                                    ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <div class="queue-patient">
                                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) ($appointment['patient_id'] ?? 0)]); ?>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                                    <small><?php echo htmlspecialchars($queueContact); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="queue-service">
                                            <strong><?php echo htmlspecialchars(admin_booking_label($appointment['booking_type'] ?? null)); ?></strong>
                                            <small><?php echo htmlspecialchars($queueServices); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($queueSchedule); ?></td>
                                        <td><span class="badge <?php echo htmlspecialchars($queueStatus); ?>"><?php echo htmlspecialchars($queueStatusLabel); ?></span></td>
                                        <td>
                                            <button
                                                type="button"
                                                class="queue-action"
                                                data-admin-appointment
                                                data-id="<?php echo (int) $appointment['id']; ?>"
                                                data-patient="<?php echo htmlspecialchars((string) $appointment['patient_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-contact="<?php echo htmlspecialchars($queueContact, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-doctor="<?php echo htmlspecialchars($queueDoctor, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-schedule="<?php echo htmlspecialchars($queueSchedule, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-type="<?php echo htmlspecialchars(admin_booking_label($appointment['booking_type'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-booking-type="<?php echo htmlspecialchars((string) ($appointment['booking_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-services="<?php echo htmlspecialchars($queueServices, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status="<?php echo htmlspecialchars($queueStatus, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-status-label="<?php echo htmlspecialchars($queueStatusLabel, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-notes="<?php echo htmlspecialchars((string) (($appointment['notes'] ?? '') ?: 'None'), ENT_QUOTES, 'UTF-8'); ?>"
                                                aria-label="View appointment details"
                                            >...</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                    <span>Doctor</span><strong id="adminDetailDoctor">None</strong>
                    <span>Schedule</span><strong id="adminDetailSchedule">None</strong>
                    <span>Type</span><strong id="adminDetailType">None</strong>
                    <span>Services</span><strong id="adminDetailServices">None</strong>
                    <span>Status</span><strong id="adminDetailStatus">None</strong>
                    <span>Notes</span><strong id="adminDetailNotes">None</strong>
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
                        <button class="btn danger" type="submit" data-confirm-message="Are you sure you want to decline this appointment request?">Decline</button>
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
            <div class="admin-modal-actions">
                <button class="btn secondary" type="button" data-admin-confirm-close>Back</button>
                <button class="btn" type="button" id="adminConfirmActionProceed">Yes, continue</button>
            </div>
        </div>
    </div>

</main>
<?php include 'includes/footer.php'; ?>

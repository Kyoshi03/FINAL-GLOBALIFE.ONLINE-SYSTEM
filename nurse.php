<?php
require_once 'includes/session.php';
checkRole('doctor');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$pageTitle = 'Doctor Dashboard | Globalife Medical Laboratory & Polyclinic';
$currentUser = getCurrentUser();
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nurse_status_action'])) {
    $appointmentId = (int) ($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {
        $_SESSION['error'] = 'Invalid appointment.';
    } else {
        $conn = getDBConnection();
        $status = 'completed';
        $staffId = (int) ($currentUser['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ? AND doctor_id = ? AND booking_type = 'consultation' AND status = 'confirmed'");
        $stmt->bind_param('sii', $status, $appointmentId, $staffId);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success'] = 'Patient visit marked as completed.';
        } else {
            $_SESSION['error'] = 'Unable to update appointment status.';
        }

        $stmt->close();
        $conn->close();
    }

    header('Location: nurse.php');
    exit();
}

function nurse_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function nurse_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M j, Y', $stamp) : '--';
}

function nurse_status(array $appointment): string {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function nurse_status_label(string $status): string {
    return [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Declined',
    ][$status] ?? 'Pending';
}

function nurse_service_detail(array $appointment): string {
    $notes = (string) ($appointment['notes'] ?? '');
    if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notes, $matches)) {
        $service = trim($matches[1]);
        if ($service !== '') {
            return $service;
        }
    }
    return 'General Check-up';
}

function nurse_queue_timing_label(array $appointment, string $today, string $nowTime): string {
    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '');
    if ($date !== '') {
        if ($date < $today) {
            return 'Earlier appointment still open';
        }
        if ($date > $today) {
            return 'Upcoming on ' . nurse_date_label($date);
        }
    }
    if ($time !== '' && $time < $nowTime) {
        return 'Earlier today';
    }
    return 'Scheduled today';
}
function nurse_short_text(?string $text, int $limit = 72): string {
    $text = trim((string) $text);
    if ($text === '') {
        return 'None';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function nurse_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

$conn = getDBConnection();
if (
    function_exists('initLabBookingSchema') &&
    (!nurse_table_exists($conn, 'medical_records') || !nurse_table_exists($conn, 'lab_result_entries'))
) {
    initLabBookingSchema($conn);
}

$stmt = $conn->prepare("SELECT a.*,
                        p.full_name AS patient_name,
                        p.profile_photo,
                        p.profile_updated_at,
                        p.phone AS patient_phone,
                        p.email AS patient_email,
                        p.gender AS patient_gender,
                        p.age AS patient_age,
                        d.full_name AS doctor_name
                        FROM appointments a
                        JOIN users p ON a.patient_id = p.id
                        LEFT JOIN users d ON a.doctor_id = d.id
                        WHERE a.booking_type = 'consultation'
                          AND a.doctor_id = ?
                        ORDER BY a.appointment_date ASC, a.appointment_time ASC");
$staffId = (int) ($currentUser['id'] ?? 0);
$stmt->bind_param('i', $staffId);
$stmt->execute();
$todayAppointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statusTotals = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$nextPatient = null;
$nowTime = date('H:i:s');
$pendingRequests = [];
$todaysConsultations = [];
$completedTodayCount = 0;

foreach ($todayAppointments as $index => $appointment) {
    $status = nurse_status($appointment);
    $todayAppointments[$index]['status_label'] = nurse_status_label($status);
    $todayAppointments[$index]['queue_timing_label'] = nurse_queue_timing_label($appointment, $today, $nowTime);
    $appointment = $todayAppointments[$index];
    $statusTotals[$status]++;

    if ($status === 'pending') {
        $pendingRequests[] = $appointment;
    }

    if (($appointment['appointment_date'] ?? '') === $today && in_array($status, ['confirmed', 'completed'], true)) {
        $todaysConsultations[] = $appointment;
        if ($status === 'completed') {
            $completedTodayCount++;
        }
    }

    if ($nextPatient === null && in_array($status, ['pending', 'confirmed'], true)) {
        $nextPatient = $appointment;
    }
}
$patientCount = 0;
$patientResult = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'patient'");
if ($patientResult && ($row = $patientResult->fetch_assoc())) {
    $patientCount = (int) $row['total'];
}

$doctorCount = 0;
$doctorActiveCount = 0;
$doctorInactiveCount = 0;
$doctorStats = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN COALESCE(is_active, 1) = 1 THEN 1 ELSE 0 END) AS active_count,
    SUM(CASE WHEN COALESCE(is_active, 1) = 0 THEN 1 ELSE 0 END) AS inactive_count
    FROM users WHERE role = 'doctor'");
if ($doctorStats && ($row = $doctorStats->fetch_assoc())) {
    $doctorCount = (int) ($row['total'] ?? 0);
    $doctorActiveCount = (int) ($row['active_count'] ?? 0);
    $doctorInactiveCount = (int) ($row['inactive_count'] ?? 0);
}

$todayRecordCount = 0;
$recordCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM medical_records WHERE DATE(created_at) = ?');
$recordCountStmt->bind_param('s', $today);
$recordCountStmt->execute();
if ($row = $recordCountStmt->get_result()->fetch_assoc()) {
    $todayRecordCount = (int) $row['total'];
}
$recordCountStmt->close();

$todayLabCount = 0;
$labCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM lab_result_entries WHERE DATE(created_at) = ?');
$labCountStmt->bind_param('s', $today);
$labCountStmt->execute();
if ($row = $labCountStmt->get_result()->fetch_assoc()) {
    $todayLabCount = (int) $row['total'];
}
$labCountStmt->close();

$conn->close();

$totalToday = count($todaysConsultations);
$activeQueue = $statusTotals['pending'] + $statusTotals['confirmed'];
$todayLabel = date('F d, Y');

$additionalStyles = patientAvatarStyles() . '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.nurse-dashboard {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 46px;
}

.nurse-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 16px;
    align-items: stretch;
    margin-bottom: 16px;
}

.hero-main,
.privacy-card,
.metric-card,
.panel,
.queue-row,
.shortcut-card {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
}

.hero-main {
    background: #073b4c;
    color: #fff;
    padding: 26px;
    display: grid;
    align-content: center;
    gap: 8px;
}

.eyebrow {
    margin: 0;
    color: #8bd3e6;
    font-size: 0.78rem;
    font-weight: 900;
    letter-spacing: 0;
    text-transform: uppercase;
}

.hero-main h1 {
    margin: 0;
    color: #fff;
    font-size: 2rem;
    line-height: 1.15;
}

.hero-main p {
    margin: 0;
    color: rgba(255, 255, 255, 0.82);
    line-height: 1.6;
}

.privacy-card {
    padding: 18px;
    border-color: #f2d58b;
    background: #fffaf0;
    display: grid;
    gap: 8px;
}

.privacy-card span {
    color: #856404;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.privacy-card strong {
    color: #073b4c;
    font-size: 1.05rem;
}

.privacy-card p {
    margin: 0;
    color: #5d6b73;
    line-height: 1.45;
    font-size: 0.92rem;
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
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.metric-card {
    padding: 16px;
    display: grid;
    gap: 6px;
}

.metric-card span {
    color: #60727d;
    font-size: 0.8rem;
    font-weight: 900;
    text-transform: uppercase;
}

.metric-card strong {
    color: #073b4c;
    font-size: 1.85rem;
    line-height: 1;
}

.metric-card small {
    color: #60727d;
    font-weight: 700;
}

.metric-card.queue {
    border-color: #bfe6ce;
    background: #f5fbf7;
}

.metric-card.records {
    border-color: #bdd7ea;
    background: #f8fbff;
}

.workbench-grid {
    display: grid;
    grid-template-columns: minmax(0, 0.88fr) minmax(0, 1.12fr);
    gap: 16px;
    margin-bottom: 16px;
}

.panel {
    padding: 20px;
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

.patient-search {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    margin-bottom: 14px;
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

.btn,
.shortcut-card {
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

.btn:hover,
.shortcut-card:hover {
    transform: translateY(-1px);
}

.btn.primary {
    background: #0f7cc2;
    color: #fff;
}

.btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.btn.complete {
    background: #17643a;
    color: #fff;
}

.next-card {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #f8fbff;
    padding: 14px;
    display: grid;
    gap: 12px;
}

.next-card h3 {
    margin: 0;
    color: #073b4c;
    font-size: 1.25rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.detail-pill {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    background: #fff;
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
    color: #1f343d;
    margin-top: 4px;
    font-size: 0.95rem;
}

.activity-list {
    display: grid;
    gap: 9px;
}

.activity-item {
    border-left: 3px solid #0f7cc2;
    background: #f8fbff;
    border-radius: 6px;
    padding: 10px 12px;
    display: grid;
    gap: 4px;
    color: inherit;
    text-decoration: none;
}

.activity-item strong {
    color: #073b4c;
}

.activity-item span {
    color: #60727d;
    font-size: 0.88rem;
}

.queue-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(150px, 220px);
    gap: 10px;
    margin-bottom: 14px;
}

.queue-list {
    display: grid;
    gap: 10px;
}

.queue-row {
    display: grid;
    grid-template-columns: 90px auto minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 14px;
}

.queue-row.hidden {
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

.patient-name {
    color: #073b4c;
    font-weight: 900;
    font-size: 1.05rem;
}

.queue-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-top: 6px;
    color: #60727d;
    font-size: 0.9rem;
}

.queue-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 8px;
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

.dash-quick-actions {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}

.qa-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 12px;
    align-items: center;
    padding: 16px 18px;
    border-radius: 12px;
    text-decoration: none;
    color: inherit;
    border: 1px solid #dce8ef;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
}

.qa-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(25, 76, 110, 0.1);
}

.qa-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-weight: 900;
    font-size: 1.1rem;
    color: #fff;
}

.qa-medical .qa-icon { background: linear-gradient(135deg, #0077b6, #023e8a); }
.qa-lab .qa-icon { background: linear-gradient(135deg, #2a9d8f, #1d6f63); }
.qa-patients .qa-icon { background: linear-gradient(135deg, #e76f51, #c44532); }
.qa-doctors .qa-icon { background: linear-gradient(135deg, #6a4c93, #4a3468); }

.qa-card strong {
    display: block;
    color: #073b4c;
    font-size: 1rem;
    margin-bottom: 2px;
}

.qa-card small {
    color: #60727d;
    font-size: 0.82rem;
    line-height: 1.35;
}

.metric-card.clickable {
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.metric-card.clickable:hover {
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(25, 76, 110, 0.1);
}

.metric-card.doctors-metric {
    border-color: #d4c4e8;
    background: #f9f6fc;
}

.metric-card.doctors-metric:hover {
    border-color: #6a4c93;
}

.doctor-metric-split {
    display: flex;
    gap: 16px;
    margin: 6px 0 4px;
}

.doctor-metric-split > span {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: 0.78rem;
    font-weight: 700;
    color: #60727d;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.doctor-metric-split strong {
    font-size: 1.65rem;
    line-height: 1;
    letter-spacing: -0.02em;
}

.doctor-metric-split .active-num strong {
    color: #17643a;
}

.doctor-metric-split .inactive-num strong {
    color: #9d1c2c;
}

.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

.hero-actions a {
    display: inline-flex;
    align-items: center;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 800;
    font-size: 0.88rem;
    text-decoration: none;
    transition: transform 0.2s ease, background 0.2s ease;
}

.hero-actions .hero-cta-primary {
    background: #fff;
    color: #073b4c;
}

.hero-actions .hero-cta-secondary {
    background: rgba(255, 255, 255, 0.15);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.35);
}

.hero-actions a:hover {
    transform: translateY(-1px);
}

.shortcut-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 16px;
}

.shortcut-card {
    color: inherit;
    background: #fff;
    justify-content: flex-start;
    align-items: flex-start;
    flex-direction: column;
    padding: 16px;
    gap: 6px;
}

.shortcut-card span {
    color: #60727d;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.shortcut-card strong {
    color: #073b4c;
    font-size: 1.02rem;
}

.shortcut-card small {
    color: #60727d;
    line-height: 1.4;
}

@media (max-width: 980px) {
    .nurse-hero,
    .workbench-grid,
    .clinical-layout,
    .metrics-grid,
    .shortcut-grid,
    .dash-quick-actions {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .clinical-layout {
        grid-template-columns: 1fr;
    }

    .hero-main {
        grid-column: 1 / -1;
    }
}

@media (max-width: 760px) {
    .nurse-dashboard {
        padding: 18px 12px 36px;
    }

    .nurse-hero,
    .workbench-grid,
    .clinical-layout,
    .metrics-grid,
    .shortcut-grid,
    .dash-quick-actions,
    .clinical-tabs,
    .patient-search,
    .queue-toolbar,
    .detail-grid {
        grid-template-columns: 1fr;
    }

    .queue-row {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .queue-actions {
        justify-content: stretch;
    }

    .btn {
        width: 100%;
    }
}

.nurse-clean-dashboard {
    display: grid;
    gap: 18px;
}

.nurse-clean-title {
    padding: 10px 4px 4px;
}

.nurse-clean-title h1 {
    margin: 0;
    color: #061a40;
    font-size: 2rem;
    line-height: 1.15;
}

.nurse-clean-title p {
    margin: 8px 0 0;
    color: #607784;
}

.nurse-clean-cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.nurse-clean-card {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 22px;
    min-height: 118px;
    padding: 22px;
    border: 1px solid #d8e6ed;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
}

.nurse-clean-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #edf6ff;
    color: #0f7cc2;
    flex: 0 0 64px;
}

.nurse-clean-card-content {
    min-width: 170px;
}

.nurse-clean-icon.pending {
    background: #fff4df;
    color: #f08a00;
}

.nurse-clean-icon.done {
    background: #eaf7ef;
    color: #17643a;
}

.nurse-clean-icon svg {
    width: 30px;
    height: 30px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.nurse-clean-card-content span {
    display: block;
    color: #1f343d;
    font-weight: 900;
}

.nurse-clean-card-content strong {
    display: block;
    margin-top: 5px;
    color: #0066cc;
    font-size: 2rem;
    line-height: 1;
}

.nurse-clean-card-content small {
    display: block;
    margin-top: 8px;
    color: #607784;
}

.nurse-clean-card-content a {
    display: inline-flex;
    margin-top: 14px;
    color: #0066cc;
    font-size: .88rem;
    font-weight: 900;
    text-decoration: none;
}

.nurse-clean-card a.pending-link {
    color: #f08a00;
}

.nurse-clean-card a.done-link {
    color: #17643a;
}

.nurse-clean-panel {
    border: 1px solid #d8e6ed;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    overflow: hidden;
}

.nurse-clean-panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 22px 12px;
}

.nurse-clean-panel-head h2 {
    margin: 0;
    color: #1f343d;
    font-size: 1.18rem;
}

.nurse-clean-panel-head p {
    margin: 5px 0 0;
    color: #607784;
    font-size: .92rem;
}

.nurse-clean-panel-head a {
    color: #0066cc;
    font-size: .9rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.nurse-clean-table {
    margin: 0 22px 18px;
    border: 1px solid #e2ebf0;
    border-radius: 8px;
    overflow: hidden;
}

.nurse-clean-row {
    display: grid;
    gap: 14px;
    align-items: center;
    padding: 12px 14px;
    border-bottom: 1px solid #e6eef3;
}

.nurse-clean-row:last-child {
    border-bottom: 0;
}

.nurse-clean-row.requests {
    grid-template-columns: minmax(220px, 1.2fr) minmax(150px, .9fr) minmax(180px, 1fr) 120px minmax(220px, 1fr);
}

.nurse-clean-row.today {
    grid-template-columns: 110px minmax(220px, 1.2fr) minmax(180px, 1fr) 120px minmax(220px, 1fr);
}

.nurse-clean-head {
    background: #f8fcff;
    color: #526b7b;
    font-size: .78rem;
    font-weight: 950;
}

.nurse-clean-patient {
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
}

.nurse-clean-patient strong,
.nurse-clean-service strong,
.nurse-clean-date strong,
.nurse-clean-time strong {
    display: block;
    color: #073b4c;
    font-weight: 900;
}

.nurse-clean-patient small,
.nurse-clean-service small,
.nurse-clean-date small {
    display: block;
    margin-top: 3px;
    color: #607784;
    line-height: 1.35;
}

.nurse-clean-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
}

.nurse-clean-actions form {
    margin: 0;
}

.btn.confirm-soft {
    border-color: #bfe6ce;
    background: #f5fbf7;
    color: #17643a;
}

.btn.decline-soft {
    border-color: #ffd0d5;
    background: #fff;
    color: #c1121f;
}

.nurse-reminder-clean {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 15px 22px;
    background: #eef7ff;
    color: #0b4f80;
    font-weight: 800;
}

.nurse-reminder-clean span {
    width: 22px;
    height: 22px;
    border: 1px solid #0f7cc2;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-weight: 950;
}

.nurse-confirm-modal {
    position: fixed;
    inset: 0;
    z-index: 5000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(7, 59, 76, 0.45);
    backdrop-filter: blur(4px);
}

.nurse-confirm-modal.is-open {
    display: flex;
}

.nurse-confirm-card {
    width: min(430px, 100%);
    background: #fff;
    border: 1px solid #d5e8f4;
    border-radius: 8px;
    box-shadow: 0 24px 70px rgba(7, 59, 76, 0.24);
    overflow: hidden;
}

.nurse-confirm-head {
    padding: 22px 24px;
    border-bottom: 1px solid #deebf3;
    background: #fbfdff;
}

.nurse-confirm-head h2 {
    margin: 0 0 6px;
    color: #09233f;
    font-size: 1.25rem;
}

.nurse-confirm-head p {
    margin: 0;
    color: #607784;
    line-height: 1.5;
}

.nurse-confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 18px 24px 22px;
}

@media (max-width: 900px) {
    .nurse-clean-cards {
        grid-template-columns: 1fr;
    }

    .nurse-clean-row.requests,
    .nurse-clean-row.today {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .nurse-clean-head {
        display: none;
    }

    .nurse-clean-actions {
        justify-content: stretch;
    }

    .nurse-clean-card {
        justify-content: flex-start;
    }
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    var confirmModal = document.getElementById("nurseConfirmModal");
    var confirmText = document.getElementById("nurseConfirmText");
    var confirmProceed = document.getElementById("nurseConfirmProceed");
    var pendingForm = null;

    function closeConfirmModal() {
        if (!confirmModal) return;
        confirmModal.classList.remove("is-open");
        confirmModal.setAttribute("aria-hidden", "true");
        pendingForm = null;
    }

    document.querySelectorAll("[data-confirm-message]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            if (!confirmModal) return;
            event.preventDefault();
            pendingForm = button.closest("form");
            if (confirmText) {
                confirmText.textContent = button.getAttribute("data-confirm-message") || "Are you sure you want to continue?";
            }
            confirmModal.classList.add("is-open");
            confirmModal.setAttribute("aria-hidden", "false");
            if (confirmProceed) confirmProceed.focus();
        });
    });

    if (confirmProceed) {
        confirmProceed.addEventListener("click", function () {
            var form = pendingForm;
            pendingForm = null;
            closeConfirmModal();
            if (form) form.submit();
        });
    }

    document.querySelectorAll("[data-close-nurse-confirm]").forEach(function (button) {
        button.addEventListener("click", closeConfirmModal);
    });

    if (confirmModal) {
        confirmModal.addEventListener("click", function (event) {
            if (event.target === confirmModal) closeConfirmModal();
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeConfirmModal();
    });
});
';

include 'includes/header.php';
?>
<main class="nurse-dashboard">
    <div class="nurse-clean-dashboard">
        <section class="nurse-clean-title">
            <h1>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?></h1>
            <p>Here is what is happening with your consultation appointments today.</p>
        </section>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <section class="nurse-clean-cards" aria-label="Appointment summary">
            <div class="nurse-clean-card">
                <span class="nurse-clean-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                </span>
                <div class="nurse-clean-card-content">
                    <span>Appointments Today</span>
                    <strong><?php echo (int) $totalToday; ?></strong>
                    <small>Confirmed appointments</small>
                    <a href="view_appointments.php">View schedule</a>
                </div>
            </div>
            <div class="nurse-clean-card">
                <span class="nurse-clean-icon pending" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 2h12"/><path d="M6 22h12"/><path d="M8 2c0 4 8 4 8 10s-8 6-8 10"/><path d="M16 2c0 4-8 4-8 10s8 6 8 10"/></svg>
                </span>
                <div class="nurse-clean-card-content">
                    <span>Pending Requests</span>
                    <strong><?php echo (int) $statusTotals['pending']; ?></strong>
                    <small>Needs your action</small>
                    <a class="pending-link" href="#appointmentRequests">Review requests</a>
                </div>
            </div>
            <div class="nurse-clean-card">
                <span class="nurse-clean-icon done" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></svg>
                </span>
                <div class="nurse-clean-card-content">
                    <span>Completed Today</span>
                    <strong><?php echo (int) $completedTodayCount; ?></strong>
                    <small>Appointments completed</small>
                    <a class="done-link" href="view_appointments.php">View completed</a>
                </div>
            </div>
        </section>

        <section class="nurse-clean-panel" id="appointmentRequests">
            <div class="nurse-clean-panel-head">
                <div>
                    <h2>Appointment Requests</h2>
                    <p>New doctor consultation requests that need your confirmation.</p>
                </div>
                <a href="view_appointments.php">View all</a>
            </div>

            <?php if (empty($pendingRequests)): ?>
                <div class="nurse-clean-table">
                    <div class="empty-state">No pending consultation requests.</div>
                </div>
            <?php else: ?>
                <div class="nurse-clean-table">
                    <div class="nurse-clean-row nurse-clean-head requests">
                        <span>Patient</span>
                        <span>Date &amp; Time</span>
                        <span>Service</span>
                        <span>Status</span>
                        <span>Action</span>
                    </div>
                    <?php foreach (array_slice($pendingRequests, 0, 3) as $appointment): ?>
                        <div class="nurse-clean-row requests">
                            <div class="nurse-clean-patient">
                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'link_target' => 'doctor', 'patient_id' => (int) $appointment['patient_id']]); ?>
                                <div>
                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                    <small><?php echo htmlspecialchars($appointment['patient_phone'] ?: $appointment['patient_email'] ?: 'No contact'); ?></small>
                                </div>
                            </div>
                            <div class="nurse-clean-date">
                                <strong><?php echo htmlspecialchars(nurse_date_label($appointment['appointment_date'])); ?></strong>
                                <small><?php echo nurse_time_label($appointment['appointment_time']); ?></small>
                            </div>
                            <div class="nurse-clean-service">
                                <strong>Consultation</strong>
                                <small><?php echo htmlspecialchars(nurse_service_detail($appointment)); ?></small>
                            </div>
                            <div>
                                <span class="status-badge pending">Pending</span>
                            </div>
                            <div class="nurse-clean-actions">
                                <form method="post" action="update_appointment_status.php">
                                    <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                    <input type="hidden" name="status" value="confirmed">
                                    <input type="hidden" name="return_url" value="nurse.php">
                                    <button class="btn confirm-soft" type="submit" data-confirm-message="Are you sure you want to confirm this consultation request?">Confirm</button>
                                </form>
                                <form method="post" action="update_appointment_status.php">
                                    <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                    <input type="hidden" name="status" value="cancelled">
                                    <input type="hidden" name="return_url" value="nurse.php">
                                    <button class="btn decline-soft" type="submit" data-confirm-message="Are you sure you want to decline this consultation request?">Decline</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="nurse-clean-panel">
            <div class="nurse-clean-panel-head">
                <div>
                    <h2>Today&apos;s Appointments</h2>
                    <p>Your confirmed consultation appointments for today.</p>
                </div>
                <a href="view_appointments.php">View full schedule</a>
            </div>

            <?php if (empty($todaysConsultations)): ?>
                <div class="nurse-clean-table">
                    <div class="empty-state">No confirmed consultation appointments today.</div>
                </div>
            <?php else: ?>
                <div class="nurse-clean-table">
                    <div class="nurse-clean-row nurse-clean-head today">
                        <span>Time</span>
                        <span>Patient</span>
                        <span>Service</span>
                        <span>Status</span>
                        <span>Action</span>
                    </div>
                    <?php foreach (array_slice($todaysConsultations, 0, 5) as $appointment): ?>
                        <?php $status = nurse_status($appointment); ?>
                        <div class="nurse-clean-row today">
                            <div class="nurse-clean-time">
                                <strong><?php echo nurse_time_label($appointment['appointment_time']); ?></strong>
                            </div>
                            <div class="nurse-clean-patient">
                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'link_target' => 'doctor', 'patient_id' => (int) $appointment['patient_id']]); ?>
                                <div>
                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                    <small><?php echo htmlspecialchars($appointment['patient_phone'] ?: $appointment['patient_email'] ?: 'No contact'); ?></small>
                                </div>
                            </div>
                            <div class="nurse-clean-service">
                                <strong>Consultation</strong>
                                <small><?php echo htmlspecialchars(nurse_service_detail($appointment)); ?></small>
                            </div>
                            <div>
                                <span class="status-badge <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($appointment['status_label']); ?></span>
                            </div>
                            <div class="nurse-clean-actions">
                                <a class="btn secondary" href="nurse_patient.php?id=<?php echo (int) $appointment['patient_id']; ?>">View Patient</a>
                                <?php if ($status === 'confirmed'): ?>
                                    <a class="btn secondary" href="nurse_patient.php?id=<?php echo (int) $appointment['patient_id']; ?>">Add Note</a>
                                    <form method="post">
                                        <input type="hidden" name="nurse_status_action" value="complete">
                                        <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                        <button class="btn confirm-soft" type="submit" data-confirm-message="Are you sure you want to mark this visit as completed?">Mark Completed</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="nurse-reminder-clean">
                <span>i</span>
                <div><strong>Reminder:</strong> Please add medical notes after each consultation and mark as completed.</div>
            </div>
        </section>
    </div>

    <div class="nurse-confirm-modal" id="nurseConfirmModal" aria-hidden="true">
        <div class="nurse-confirm-card" role="dialog" aria-modal="true" aria-labelledby="nurseConfirmTitle">
            <div class="nurse-confirm-head">
                <h2 id="nurseConfirmTitle">Confirm appointment action</h2>
                <p id="nurseConfirmText">Are you sure you want to continue?</p>
            </div>
            <div class="nurse-confirm-actions">
                <button class="btn secondary" type="button" data-close-nurse-confirm>Back</button>
                <button class="btn confirm-soft" type="button" id="nurseConfirmProceed">Yes, continue</button>
            </div>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>









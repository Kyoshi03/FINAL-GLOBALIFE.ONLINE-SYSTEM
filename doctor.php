<?php
require_once 'includes/session.php';
checkRole('doctor');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/clinic_info.php';

$doctorClinicInfo = clinic_info_defaults();
try {
    $doctorClinicInfoConn = getDBConnection();
    $doctorClinicInfo = clinic_info_get($doctorClinicInfoConn);
    $doctorClinicInfoConn->close();
} catch (Throwable $e) {
    $doctorClinicInfo = clinic_info_defaults();
}
$pageTitle = 'Doctor Dashboard | ' . $doctorClinicInfo['clinic_name'];
$currentUser = getCurrentUser();
$today = date('Y-m-d');

function doctor_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '--';
}

function doctor_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M j, Y', $stamp) : '--';
}

function doctor_status(array $appointment): string {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function doctor_status_label(string $status): string {
    return [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][$status] ?? 'Pending';
}

function doctor_service_detail(array $appointment): string {
    $notes = (string) ($appointment['notes'] ?? '');
    if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notes, $matches)) {
        $service = trim($matches[1]);
        if ($service !== '') {
            return $service;
        }
    }
    return 'General Check-up';
}

function doctor_queue_timing_label(array $appointment, string $today, string $nowTime): string {
    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '');
    if ($date !== '') {
        if ($date < $today) {
            return 'Earlier appointment still open';
        }
        if ($date > $today) {
            return 'Upcoming on ' . doctor_date_label($date);
        }
    }
    if ($time !== '' && $time < $nowTime) {
        return 'Earlier today';
    }
    return 'Scheduled today';
}
function doctor_short_text(?string $text, int $limit = 72): string {
    $text = trim((string) $text);
    if ($text === '') {
        return 'None';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function doctor_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

$conn = getDBConnection();
if (
    function_exists('initLabBookingSchema') &&
    (!doctor_table_exists($conn, 'medical_records') || !doctor_table_exists($conn, 'lab_result_entries'))
) {
    initLabBookingSchema($conn);
}

$stmt = $conn->prepare("SELECT a.*,
                                " . dbUsersNameExpression('p') . " AS patient_name,
                        p.profile_photo,
                        p.profile_updated_at,
                        p.phone AS patient_phone,
                        p.email AS patient_email,
                        p.gender AS patient_gender,
                        p.age AS patient_age,
                                " . dbUsersNameExpression('d') . " AS doctor_name
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

foreach ($todayAppointments as $index => $appointment) {
    $status = doctor_status($appointment);
    $todayAppointments[$index]['status_label'] = doctor_status_label($status);
    $todayAppointments[$index]['queue_timing_label'] = doctor_queue_timing_label($appointment, $today, $nowTime);
    $appointment = $todayAppointments[$index];
    $statusTotals[$status]++;

    if ($status === 'pending') {
        $pendingRequests[] = $appointment;
    }

    if (($appointment['appointment_date'] ?? '') === $today && in_array($status, ['confirmed', 'completed'], true)) {
        $todaysConsultations[] = $appointment;
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

function doctor_summary_valid_date(string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTime && $parsed->format('Y-m-d') === $date;
}

function doctor_summary_default_range(string $period, string $today): array {
    $to = new DateTimeImmutable($today);
    $from = $to;

    if ($period === 'daily') {
        $from = $to->modify('-6 days');
    } elseif ($period === 'monthly') {
        // Show the current year's month-to-date trend so Monthly has multiple
        // month buckets when historical completed consultations are available.
        $from = new DateTimeImmutable($to->format('Y') . '-01-01');
    } else {
        // Show the current year's quarter trend so Quarterly has meaningful points
        // on first open instead of collapsing every record into one current-quarter point.
        $from = new DateTimeImmutable($to->format('Y') . '-01-01');
    }

    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

function doctor_summary_quarter_start(DateTimeImmutable $date): DateTimeImmutable {
    $month = (int) $date->format('n');
    $quarterStartMonth = 1 + (int) (floor(($month - 1) / 3) * 3);
    return new DateTimeImmutable($date->format('Y') . '-' . str_pad((string) $quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01');
}

function doctor_summary_period_range_label(string $period, string $from, string $to): string {
    $start = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    if ($period === 'monthly') {
        return $start->format('M Y') . ' - ' . $end->format('M Y');
    }
    if ($period === 'quarterly') {
        $startQuarter = (int) floor(((int) $start->format('n') - 1) / 3) + 1;
        $endQuarter = (int) floor(((int) $end->format('n') - 1) / 3) + 1;
        return 'Q' . $startQuarter . ' ' . $start->format('Y') . ' - Q' . $endQuarter . ' ' . $end->format('Y');
    }
    return doctor_date_label($from) . ' - ' . doctor_date_label($to);
}

function doctor_summary_bucket(string $date, string $period): string {
    $parsed = new DateTimeImmutable($date);
    if ($period === 'daily') {
        return $parsed->format('Y-m-d');
    }
    if ($period === 'monthly') {
        return $parsed->format('Y-m');
    }
    $quarter = (int) floor(((int) $parsed->format('n') - 1) / 3) + 1;
    return $parsed->format('Y') . '-Q' . $quarter;
}

function doctor_summary_pagination_url(string $period, string $from, string $to, int $page): string {
    return 'doctor.php?' . http_build_query([
        'summary_period' => $period,
        'summary_from' => $from,
        'summary_to' => $to,
        'summary_page' => $page,
    ]) . '#consultationSummary';
}

$summaryPeriod = strtolower(trim((string) ($_GET['summary_period'] ?? '')));
$allowedSummaryPeriods = ['daily', 'monthly', 'quarterly'];
if (!in_array($summaryPeriod, $allowedSummaryPeriods, true)) {
    $summaryPeriod = '';
}

$summaryFrom = '';
$summaryTo = '';
$summaryCompletedConsultations = [];
$summaryGraphPoints = [];
$summaryGraphMax = 0;
$summaryPage = max(1, (int) ($_GET['summary_page'] ?? 1));
$summaryPerPage = 5;

if ($summaryPeriod !== '') {
    $candidateFrom = trim((string) ($_GET['summary_from'] ?? ''));
    $candidateTo = trim((string) ($_GET['summary_to'] ?? ''));
    if (doctor_summary_valid_date($candidateFrom) && doctor_summary_valid_date($candidateTo) && $candidateTo >= $candidateFrom) {
        $summaryFrom = $candidateFrom;
        $summaryTo = $candidateTo;
    } else {
        [$summaryFrom, $summaryTo] = doctor_summary_default_range($summaryPeriod, $today);
    }

    foreach ($todayAppointments as $appointment) {
        if (doctor_status($appointment) !== 'completed') {
            continue;
        }
        $appointmentDate = (string) ($appointment['appointment_date'] ?? '');
        if (!doctor_summary_valid_date($appointmentDate) || $appointmentDate < $summaryFrom || $appointmentDate > $summaryTo) {
            continue;
        }
        $summaryCompletedConsultations[] = $appointment;
    }

    usort($summaryCompletedConsultations, static function (array $left, array $right): int {
        $leftKey = (string) ($left['appointment_date'] ?? '') . ' ' . (string) ($left['appointment_time'] ?? '');
        $rightKey = (string) ($right['appointment_date'] ?? '') . ' ' . (string) ($right['appointment_time'] ?? '');
        return strcmp($rightKey, $leftKey);
    });

    $summaryCounts = [];
    foreach ($summaryCompletedConsultations as $appointment) {
        $bucket = doctor_summary_bucket((string) $appointment['appointment_date'], $summaryPeriod);
        $summaryCounts[$bucket] = ($summaryCounts[$bucket] ?? 0) + 1;
    }

    $rangeStart = new DateTimeImmutable($summaryFrom);
    $rangeEnd = new DateTimeImmutable($summaryTo);
    if ($summaryPeriod === 'monthly') {
        $rangeStart = $rangeStart->modify('first day of this month');
        $rangeEnd = $rangeEnd->modify('first day of this month');
    } elseif ($summaryPeriod === 'quarterly') {
        $rangeStart = doctor_summary_quarter_start($rangeStart);
        $rangeEnd = doctor_summary_quarter_start($rangeEnd);
    }

    for ($cursor = $rangeStart; $cursor <= $rangeEnd;) {
        if ($summaryPeriod === 'daily') {
            $key = $cursor->format('Y-m-d');
            $label = $cursor->format('M j');
            $fullLabel = $cursor->format('F j, Y');
            $cursor = $cursor->modify('+1 day');
        } elseif ($summaryPeriod === 'monthly') {
            $key = $cursor->format('Y-m');
            $label = $cursor->format('M Y');
            $fullLabel = $cursor->format('F Y');
            $cursor = $cursor->modify('+1 month');
        } else {
            $quarter = (int) floor(((int) $cursor->format('n') - 1) / 3) + 1;
            $key = $cursor->format('Y') . '-Q' . $quarter;
            $label = 'Q' . $quarter . ' ' . $cursor->format('Y');
            $fullLabel = 'Quarter ' . $quarter . ' of ' . $cursor->format('Y');
            $cursor = $cursor->modify('+3 months');
        }
        $summaryGraphPoints[] = [
            'key' => $key,
            'label' => $label,
            'full_label' => $fullLabel,
            'value' => (int) ($summaryCounts[$key] ?? 0),
        ];
    }

    $summaryGraphMax = 0;
    foreach ($summaryGraphPoints as $point) {
        $summaryGraphMax = max($summaryGraphMax, (int) $point['value']);
    }
    $graphCount = count($summaryGraphPoints);
    foreach ($summaryGraphPoints as $index => &$point) {
        $point['x'] = $graphCount > 1 ? 70 + ($index * (820 / ($graphCount - 1))) : 480;
        $point['y'] = 250 - (($point['value'] / max(1, $summaryGraphMax)) * 190);
    }
    unset($point);
}

$summaryAllCompletedConsultations = [];
foreach ($todayAppointments as $appointment) {
    $appointmentDate = (string) ($appointment['appointment_date'] ?? '');
    if (doctor_status($appointment) === 'completed' && doctor_summary_valid_date($appointmentDate)) {
        $summaryAllCompletedConsultations[] = $appointment;
    }
}

$summaryDefaultRanges = [];
$summaryCardValues = [];
foreach ($allowedSummaryPeriods as $periodKey) {
    [$defaultFrom, $defaultTo] = doctor_summary_default_range($periodKey, $today);
    $summaryDefaultRanges[$periodKey] = ['from' => $defaultFrom, 'to' => $defaultTo];
    $summaryCardValues[$periodKey] = 0;
}
foreach ($summaryAllCompletedConsultations as $appointment) {
    $appointmentDate = (string) $appointment['appointment_date'];
    $appointmentMonth = substr($appointmentDate, 0, 7);
    $appointmentQuarter = doctor_summary_bucket($appointmentDate, 'quarterly');
    if ($appointmentDate === $today) {
        $summaryCardValues['daily']++;
    }
    if ($appointmentMonth === substr($today, 0, 7)) {
        $summaryCardValues['monthly']++;
    }
    if ($appointmentQuarter === doctor_summary_bucket($today, 'quarterly')) {
        $summaryCardValues['quarterly']++;
    }
}

$summaryClientRecords = array_map(static function (array $appointment): array {
    return [
        'id' => (int) ($appointment['id'] ?? 0),
        'patient_id' => (int) ($appointment['patient_id'] ?? 0),
        'patient_name' => (string) ($appointment['patient_name'] ?? 'Patient'),
        'date' => (string) ($appointment['appointment_date'] ?? ''),
        'time' => (string) ($appointment['appointment_time'] ?? ''),
    ];
}, $summaryAllCompletedConsultations);
$summaryClientData = [
    'records' => $summaryClientRecords,
    'defaults' => $summaryDefaultRanges,
    'initial_period' => $summaryPeriod,
    'initial_from' => $summaryFrom,
    'initial_to' => $summaryTo,
    'initial_page' => $summaryPage,
];

$summaryTotalRecords = count($summaryCompletedConsultations);
$summaryPageTotal = max(1, (int) ceil($summaryTotalRecords / $summaryPerPage));
$summaryPage = min($summaryPage, $summaryPageTotal);
$summaryPageRows = array_slice($summaryCompletedConsultations, ($summaryPage - 1) * $summaryPerPage, $summaryPerPage);

$conn->close();

$totalAppointments = count($todayAppointments);
$activeQueue = $statusTotals['pending'] + $statusTotals['confirmed'];

$additionalStyles = patientAvatarStyles() . '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.doctor-dashboard {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 46px;
}

.doctor-hero {
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
    .doctor-hero,
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
    .doctor-dashboard {
        padding: 18px 12px 36px;
    }

    .doctor-hero,
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

.doctor-clean-dashboard {
    display: grid;
    gap: 18px;
}

.doctor-clean-title {
    padding: 10px 4px 4px;
}

.doctor-clean-title h1 {
    margin: 0;
    color: #061a40;
    font-size: 2rem;
    line-height: 1.15;
}

.doctor-clean-title p {
    margin: 8px 0 0;
    color: #607784;
}

.doctor-clean-cards {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.doctor-clean-card {
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

.doctor-clean-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: #edf6ff;
    color: #0f7cc2;
    flex: 0 0 64px;
}

.doctor-clean-card-content {
    min-width: 0;
}

.doctor-clean-icon.pending {
    background: #fff4df;
    color: #f08a00;
}

.doctor-clean-icon.confirmed {
    background: #edf6ff;
    color: #0f7cc2;
}

.doctor-clean-icon.done {
    background: #eaf7ef;
    color: #17643a;
}

.doctor-clean-icon svg {
    width: 30px;
    height: 30px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.doctor-clean-card-content span {
    display: block;
    color: #1f343d;
    font-weight: 900;
}

.doctor-clean-card-content strong {
    display: block;
    margin-top: 5px;
    color: #0066cc;
    font-size: 2rem;
    line-height: 1;
}

.doctor-clean-card-content small {
    display: block;
    margin-top: 8px;
    color: #607784;
}

.doctor-clean-card-content a {
    display: inline-flex;
    margin-top: 14px;
    color: #0066cc;
    font-size: .88rem;
    font-weight: 900;
    text-decoration: none;
}

.doctor-clean-card a.pending-link {
    color: #f08a00;
}

.doctor-clean-card a.done-link {
    color: #17643a;
}

.doctor-clean-card a.confirmed-link {
    color: #0f7cc2;
}

.doctor-clean-panel {
    border: 1px solid #d8e6ed;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    overflow: hidden;
}

.doctor-clean-panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 20px 22px 12px;
}

.doctor-clean-panel-head h2 {
    margin: 0;
    color: #1f343d;
    font-size: 1.18rem;
}

.doctor-clean-panel-head p {
    margin: 5px 0 0;
    color: #607784;
    font-size: .92rem;
}

.doctor-clean-panel-head a {
    color: #0066cc;
    font-size: .9rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.doctor-clean-table {
    margin: 0 22px 18px;
    border: 1px solid #e2ebf0;
    border-radius: 8px;
    overflow: hidden;
}

.doctor-clean-row {
    display: grid;
    gap: 14px;
    align-items: center;
    padding: 12px 14px;
    border-bottom: 1px solid #e6eef3;
}

.doctor-clean-row:last-child {
    border-bottom: 0;
}

.doctor-clean-row.requests {
    grid-template-columns: minmax(220px, 1.2fr) minmax(150px, .9fr) minmax(180px, 1fr) 120px minmax(220px, 1fr);
}

.doctor-clean-row.requests > * {
    text-align: center;
}

.doctor-clean-row.today {
    grid-template-columns: 110px minmax(220px, 1.2fr) minmax(180px, 1fr) 120px minmax(220px, 1fr);
}

.doctor-clean-head {
    background: #f8fcff;
    color: #526b7b;
    font-size: .78rem;
    font-weight: 950;
}

.doctor-clean-patient {
    display: flex;
    justify-content: center;
    gap: 10px;
    align-items: center;
}

.doctor-clean-row.requests .doctor-clean-patient > div {
    text-align: center;
}

.doctor-clean-patient strong,
.doctor-clean-service strong,
.doctor-clean-date strong,
.doctor-clean-time strong {
    display: block;
    color: #073b4c;
    font-weight: 900;
}

.doctor-clean-patient small,
.doctor-clean-service small,
.doctor-clean-date small {
    display: block;
    margin-top: 3px;
    color: #607784;
    line-height: 1.35;
}

.doctor-clean-actions {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
}

.doctor-clean-row.requests .doctor-clean-actions {
    justify-content: center;
}

.doctor-clean-actions form {
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

.doctor-reminder-clean {
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 15px 22px;
    background: #eef7ff;
    color: #0b4f80;
    font-weight: 800;
}

.doctor-reminder-clean span {
    width: 22px;
    height: 22px;
    border: 1px solid #0f7cc2;
    border-radius: 50%;
    display: grid;
    place-items: center;
    font-weight: 950;
}

.doctor-confirm-modal {
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

.doctor-confirm-modal.is-open {
    display: flex;
}

.doctor-confirm-card {
    width: min(430px, 100%);
    background: #fff;
    border: 1px solid #d5e8f4;
    border-radius: 8px;
    box-shadow: 0 24px 70px rgba(7, 59, 76, 0.24);
    overflow: hidden;
}

.doctor-success-card {
    padding: 28px 24px 24px;
    text-align: center;
}

.doctor-success-icon {
    display: grid;
    place-items: center;
    width: 58px;
    height: 58px;
    margin: 0 auto 14px;
    border-radius: 50%;
    background: #e7f7ed;
    color: #17643a;
    font-size: 2rem;
    font-weight: 950;
}

.doctor-success-card h2 {
    margin: 0 0 8px;
    color: #09233f;
    font-size: 1.25rem;
}

.doctor-success-card p {
    margin: 0 0 20px;
    color: #607784;
    line-height: 1.5;
}

.doctor-confirm-head {
    padding: 22px 24px;
    border-bottom: 1px solid #deebf3;
    background: #fbfdff;
}

.doctor-confirm-head h2 {
    margin: 0 0 6px;
    color: #09233f;
    font-size: 1.25rem;
}

.doctor-confirm-head p {
    margin: 0;
    color: #607784;
    line-height: 1.5;
}

.doctor-cancel-reason {
    display: none;
    padding: 0 24px 4px;
}

.doctor-cancel-reason.is-open {
    display: block;
}

.doctor-cancel-reason label {
    display: block;
    margin-bottom: 8px;
    color: #09233f;
    font-weight: 900;
}

.doctor-cancel-reason textarea {
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

.doctor-cancel-reason textarea:focus {
    border-color: #0f7cc2;
    box-shadow: 0 0 0 4px rgba(15, 124, 194, 0.1);
    outline: none;
}

.doctor-cancel-reason small {
    display: none;
    margin-top: 7px;
    color: #9d1c2c;
    font-weight: 800;
}

.doctor-cancel-reason small.is-open {
    display: block;
}

.doctor-confirm-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 18px 24px 22px;
}

.consultation-summary {
    margin-top: 16px;
}

.consultation-summary-options {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 14px;
}

.consultation-period-card {
    width: 100%;
    min-height: 86px;
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
    color: #526b7b;
    cursor: pointer;
    padding: 16px 18px;
    text-align: left;
    font: inherit;
    transition: border-color .2s ease, background .2s ease, transform .2s ease;
}

.consultation-period-card:hover {
    border-color: #0f7cc2;
    transform: translateY(-1px);
}

.consultation-period-card.active {
    border-color: #0f7cc2;
    background: #eef7ff;
    box-shadow: 0 8px 18px rgba(15, 124, 194, .1);
    color: #0b4f80;
}

.consultation-period-card strong,
.consultation-period-card b,
.consultation-period-card span {
    display: block;
}

.consultation-period-card strong {
    color: #073b4c;
    font-size: 1rem;
    font-weight: 950;
    text-transform: uppercase;
}

.consultation-period-card.active strong {
    color: #0b4f80;
}

.consultation-period-card b {
    margin-top: 3px;
    color: #0f7cc2;
    font-size: 1.55rem;
    line-height: 1.05;
}

.consultation-period-card span {
    margin-top: 5px;
    color: #607784;
    font-size: .86rem;
}

.consultation-summary-body {
    margin-top: 16px;
    border-top: 1px solid #dce8ef;
    padding-top: 16px;
}

.consultation-summary-filters {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.consultation-summary-range-edit {
    min-height: 40px;
    box-sizing: border-box;
    border: 1px solid #cfe1ee;
    border-radius: 8px;
    color: #1f343d;
    background: #fff;
    font: inherit;
}

.consultation-summary-range-edit:focus-visible {
    outline: none;
    border-color: #0f7cc2;
    box-shadow: 0 0 0 3px rgba(15, 124, 194, .1);
}

.consultation-summary-range-edit {
    flex: 0 0 auto;
    padding: 8px 11px;
    color: #0b4f80;
    font-size: .78rem;
    font-weight: 900;
    cursor: pointer;
    white-space: nowrap;
}

.consultation-summary-range-edit:hover {
    border-color: #0f7cc2;
    background: #eef7ff;
}

.consultation-summary-range-edit[hidden] {
    display: none;
}

body.doctor-summary-range-open {
    overflow: hidden;
}

.consultation-range-modal {
    position: fixed;
    inset: 0;
    z-index: 1200;
    display: grid;
    place-items: center;
    padding: 20px;
    background: rgba(7, 59, 76, .42);
}

.consultation-range-modal[hidden] {
    display: none;
}

.consultation-range-dialog {
    width: min(900px, 100%);
    max-height: min(680px, calc(100vh - 40px));
    overflow: auto;
    border: 1px solid #cfe1ee;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 22px 60px rgba(7, 59, 76, .24);
}

.consultation-range-dialog-header,
.consultation-range-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 22px;
    border-bottom: 1px solid #e5eef3;
}

.consultation-range-dialog-header h3 {
    margin: 0;
    color: #073b4c;
    font-size: 1.05rem;
}

.consultation-range-close,
.consultation-range-nav,
.consultation-range-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #cfe1ee;
    border-radius: 7px;
    color: #0b4f80;
    background: #f8fbfd;
    font: inherit;
    font-weight: 900;
    cursor: pointer;
}

.consultation-range-close {
    width: 34px;
    height: 34px;
    font-size: 1.25rem;
    line-height: 1;
}

.consultation-range-close:hover,
.consultation-range-nav:hover {
    border-color: #8ed9ef;
    background: #eef7ff;
}

.consultation-range-nav:disabled {
    border-color: #d9e3e8;
    color: #9aaeb9;
    background: #f3f6f8;
    cursor: not-allowed;
    opacity: 1;
}

.consultation-range-nav:disabled:hover {
    border-color: #d9e3e8;
    background: #f3f6f8;
}

.consultation-range-calendars {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.consultation-range-month {
    padding: 20px 22px;
}

.consultation-range-month + .consultation-range-month {
    border-left: 1px solid #e5eef3;
}

.consultation-range-month-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 16px;
}

.consultation-range-month-head strong {
    color: #073b4c;
    font-size: 1rem;
}

.consultation-range-month-title {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-width: 0;
    color: #073b4c;
    font-size: 1rem;
    font-weight: 900;
}

.consultation-range-year-select {
    min-width: 72px;
    padding: 3px 20px 3px 5px;
    border: 1px solid transparent;
    border-radius: 5px;
    color: #073b4c;
    background: #fff;
    font: inherit;
    font-weight: 900;
    cursor: pointer;
}

.consultation-range-year-select:hover,
.consultation-range-year-select:focus-visible {
    border-color: #8ed9ef;
    outline: none;
    background: #eef7ff;
}

.consultation-range-nav {
    width: 30px;
    height: 30px;
    font-size: 1rem;
}

.consultation-range-weekdays,
.consultation-range-days {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 6px;
}

.consultation-range-weekdays {
    margin-bottom: 8px;
    color: #708792;
    font-size: .72rem;
    font-weight: 900;
    text-align: center;
}

.consultation-range-day {
    position: relative;
    min-width: 0;
    aspect-ratio: 1;
    border: 1px solid transparent;
    border-radius: 7px;
    color: #1f343d;
    background: #fff;
    font: inherit;
    font-size: .84rem;
    cursor: pointer;
}

.consultation-range-day:hover:not(:disabled) {
    border-color: #8ed9ef;
    background: #eef7ff;
}

.consultation-range-day.is-outside,
.consultation-range-day:disabled {
    color: #b9c8d0;
    background: #f8fbfd;
    cursor: not-allowed;
}

.consultation-range-day.is-in-range {
    border-radius: 0;
    color: #0b4f80;
    background: #eaf6fb;
}

.consultation-range-day.is-start,
.consultation-range-day.is-end {
    border-color: #0f7cc2;
    border-radius: 7px;
    color: #fff;
    background: #0f7cc2;
    font-weight: 900;
}

.consultation-range-day.is-start {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}

.consultation-range-day.is-end {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
}

.consultation-range-values {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.consultation-range-value {
    min-width: 145px;
    padding: 9px 11px;
    border: 1px solid #cfe1ee;
    border-radius: 7px;
    color: #405b67;
    background: #fff;
    font-size: .84rem;
}

.consultation-range-separator {
    color: #8aa0ac;
}

.consultation-range-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.consultation-range-action {
    min-height: 38px;
    padding: 8px 16px;
    background: #fff;
    font-size: .82rem;
}

.consultation-range-action.primary {
    border-color: #0f7cc2;
    color: #fff;
    background: #0f7cc2;
}

.consultation-range-message {
    margin: 8px 0 0;
    color: #b42318;
    font-size: .78rem;
    font-weight: 700;
}

.consultation-range-message[hidden] {
    display: none;
}

.consultation-summary-field {
    display: grid;
    gap: 5px;
    min-width: 170px;
}

.consultation-summary-field label {
    color: #526b7b;
    font-size: .78rem;
    font-weight: 950;
    text-transform: uppercase;
}

.consultation-summary-field input {
    min-height: 40px;
}

.consultation-summary-filter-button {
    min-height: 40px;
}

.consultation-summary-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin: 0 0 10px;
    color: #607784;
    font-size: .9rem;
}

.consultation-summary-meta strong {
    color: #073b4c;
}

.consultation-summary-chart {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
    overflow-x: auto;
}

.consultation-summary-chart svg {
    display: block;
    width: 100%;
    min-width: 680px;
    height: 320px;
}

.consultation-summary-chart text {
    fill: #607784;
    font-family: inherit;
    font-size: 12px;
}

.consultation-summary-chart .summary-axis-label {
    fill: #073b4c;
    font-weight: 900;
}

.consultation-summary-chart .summary-grid-line {
    stroke: #e2edf4;
    stroke-width: 1;
}

.consultation-summary-chart .summary-axis-line {
    stroke: #b8d0df;
    stroke-width: 1.5;
}

.consultation-summary-chart .summary-area {
    fill: rgba(15, 124, 194, .12);
    opacity: 0;
    animation: doctorSummaryFade 1s ease-out .15s forwards;
}

.consultation-summary-chart .summary-line {
    fill: none;
    stroke: #0f7cc2;
    stroke-width: 3.5;
    stroke-linejoin: round;
    stroke-linecap: round;
    stroke-dasharray: 3000;
    stroke-dashoffset: 3000;
    animation: doctorSummaryDraw 1.25s ease-out forwards;
}

.consultation-summary-chart .summary-dot {
    fill: #fff;
    stroke: #0f7cc2;
    stroke-width: 2.5;
    opacity: 0;
    animation: doctorSummaryFade .8s ease-out .35s forwards;
}

@keyframes doctorSummaryDraw {
    to { stroke-dashoffset: 0; }
}

@keyframes doctorSummaryFade {
    to { opacity: 1; }
}

.consultation-summary-table-wrap {
    margin-top: 16px;
    border: 1px solid #dce8ef;
    border-radius: 8px;
    overflow-x: auto;
}

.consultation-summary-table {
    width: 100%;
    min-width: 620px;
    border-collapse: collapse;
}

.consultation-summary-table th,
.consultation-summary-table td {
    border-bottom: 1px solid #e6eef3;
    padding: 12px 14px;
    text-align: left;
}

.consultation-summary-table th {
    background: #f8fcff;
    color: #526b7b;
    font-size: .78rem;
    font-weight: 950;
    text-transform: uppercase;
}

.consultation-summary-table td {
    color: #1f343d;
}

.consultation-summary-table tr:last-child td {
    border-bottom: 0;
}

.consultation-summary-details {
    color: #0b4f80;
    font-weight: 900;
    text-decoration: none;
}

.consultation-summary-details:hover {
    text-decoration: underline;
}

.consultation-summary-empty {
    display: grid;
    min-height: 220px;
    place-items: center;
    padding: 24px;
    color: #607784;
    text-align: center;
}

.consultation-summary-empty[hidden] {
    display: none !important;
}

.consultation-summary-table td.consultation-summary-empty {
    display: table-cell;
    min-height: 0;
    text-align: center;
}

.consultation-summary-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    padding: 12px 14px;
    border-top: 1px solid #e6eef3;
}

.consultation-summary-pages {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.consultation-summary-page {
    display: inline-flex;
    min-width: 34px;
    min-height: 34px;
    align-items: center;
    justify-content: center;
    border: 1px solid #d4e6f5;
    border-radius: 7px;
    background: #fff;
    color: #0b4f80;
    font-weight: 900;
    text-decoration: none;
}

.consultation-summary-page.active,
.consultation-summary-page:hover {
    border-color: #0f7cc2;
    background: #0f7cc2;
    color: #fff;
}

.consultation-summary-page.disabled {
    color: #9ab4c7;
    cursor: not-allowed;
    pointer-events: none;
}

@media (max-width: 700px) {
    .consultation-summary-options {
        grid-template-columns: 1fr;
    }

    .consultation-summary-field {
        width: 100%;
    }

    .consultation-summary-filter-button {
        width: 100%;
    }

    .consultation-summary-meta {
        align-items: flex-start;
        flex-direction: column;
    }

    .consultation-range-modal {
        padding: 10px;
    }

    .consultation-range-dialog {
        max-height: calc(100vh - 20px);
    }

    .consultation-range-calendars {
        grid-template-columns: 1fr;
    }

    .consultation-range-month + .consultation-range-month {
        border-top: 1px solid #e5eef3;
        border-left: 0;
    }

    .consultation-range-footer {
        align-items: stretch;
        flex-direction: column;
    }

    .consultation-range-values,
    .consultation-range-actions {
        width: 100%;
    }

    .consultation-range-value,
    .consultation-range-action {
        flex: 1;
        min-width: 0;
    }
}

@media (max-width: 900px) {
    .doctor-clean-cards {
        grid-template-columns: 1fr;
    }

    .doctor-clean-row.requests,
    .doctor-clean-row.today {
        grid-template-columns: 1fr;
        align-items: stretch;
    }

    .doctor-clean-head {
        display: none;
    }

    .doctor-clean-actions {
        justify-content: stretch;
    }

    .doctor-clean-card {
        justify-content: flex-start;
    }
}

@media (max-width: 1100px) and (min-width: 901px) {
    .doctor-clean-cards {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    var confirmModal = document.getElementById("doctorConfirmModal");
    var confirmText = document.getElementById("doctorConfirmText");
    var confirmProceed = document.getElementById("doctorConfirmProceed");
    var cancelReasonWrap = document.getElementById("doctorCancelReasonWrap");
    var cancelReasonInput = document.getElementById("doctorCancelReason");
    var cancelReasonError = document.getElementById("doctorCancelReasonError");
    var successModal = document.getElementById("doctorSuccessModal");
    var successTitle = document.getElementById("doctorSuccessTitle");
    var successText = document.getElementById("doctorSuccessText");
    var successOk = document.getElementById("doctorSuccessOk");
    var pendingForm = null;

    function closeConfirmModal() {
        if (!confirmModal) return;
        confirmModal.classList.remove("is-open");
        confirmModal.setAttribute("aria-hidden", "true");
        pendingForm = null;
        if (cancelReasonWrap) cancelReasonWrap.classList.remove("is-open");
        if (cancelReasonInput) cancelReasonInput.value = "";
        if (cancelReasonError) cancelReasonError.classList.remove("is-open");
    }

    function showSuccessModal(status, message) {
        if (successTitle) {
            successTitle.textContent = status === "cancelled" ? "Appointment cancelled" : "Appointment confirmed";
        }
        if (successText) {
            successText.textContent = message || (status === "cancelled"
                ? "Appointment cancelled successfully."
                : "Appointment confirmed successfully.");
        }
        if (successModal) {
            successModal.classList.add("is-open");
            successModal.setAttribute("aria-hidden", "false");
        }
    }

    document.querySelectorAll("[data-confirm-message]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            if (!confirmModal) return;
            event.preventDefault();
            pendingForm = button.closest("form");
            if (confirmText) {
                confirmText.textContent = button.getAttribute("data-confirm-message") || "Are you sure you want to continue?";
            }
            var statusInput = pendingForm ? pendingForm.querySelector("[name=status]") : null;
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

    if (confirmProceed) {
        confirmProceed.addEventListener("click", async function () {
            var form = pendingForm;
            var statusInput = form ? form.querySelector("[name=status]") : null;
            if (statusInput && statusInput.value === "cancelled") {
                var reason = cancelReasonInput ? cancelReasonInput.value.trim() : "";
                if (!reason) {
                    if (cancelReasonError) cancelReasonError.classList.add("is-open");
                    if (cancelReasonInput) cancelReasonInput.focus();
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
            closeConfirmModal();
            if (!form) return;

            var oldText = confirmProceed.textContent;
            confirmProceed.disabled = true;
            confirmProceed.textContent = "Saving...";
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
                    showSuccessModal(statusInput ? statusInput.value : "", result.message);
                } else {
                    if (confirmText) confirmText.textContent = (result && result.message) || "Unable to update appointment.";
                    pendingForm = form;
                    if (confirmModal) {
                        confirmModal.classList.add("is-open");
                        confirmModal.setAttribute("aria-hidden", "false");
                    }
                }
            } catch (error) {
                form.submit();
            } finally {
                confirmProceed.disabled = false;
                confirmProceed.textContent = oldText;
            }
        });
    }

    if (successOk) {
        successOk.addEventListener("click", function () {
            window.location.href = "doctor.php";
        });
    }

    document.querySelectorAll("[data-close-doctor-confirm]").forEach(function (button) {
        button.addEventListener("click", closeConfirmModal);
    });

    if (confirmModal) {
        confirmModal.addEventListener("click", function (event) {
            if (event.target === confirmModal) closeConfirmModal();
        });
    }

    if (successModal) {
        successModal.addEventListener("click", function (event) {
            if (event.target === successModal) {
                window.location.href = "doctor.php";
            }
        });
    }

    var summaryDataNode = document.getElementById("doctorConsultationSummaryData");
    var summaryBody = document.getElementById("doctorConsultationSummaryBody");
    if (summaryDataNode && summaryBody) {
        var summaryData = {};
        try {
            summaryData = JSON.parse(summaryDataNode.textContent || "{}");
        } catch (error) {
            summaryData = {};
        }

        var summaryRecords = Array.isArray(summaryData.records) ? summaryData.records : [];
        var summaryPeriod = summaryData.initial_period || "";
        var summaryPage = Math.max(1, parseInt(summaryData.initial_page || "1", 10));
        var summaryPageSize = 5;
        var summaryFromInput = document.getElementById("doctorSummaryFrom");
        var summaryToInput = document.getElementById("doctorSummaryTo");
        var summaryPeriodInput = summaryBody.querySelector("[name=summary_period]");
        var summaryChart = document.getElementById("doctorConsultationSummaryChart");
        var summaryEmpty = document.getElementById("doctorConsultationSummaryEmpty");
        var summaryRows = document.getElementById("doctorConsultationSummaryRows");
        var summaryPagination = document.getElementById("doctorConsultationSummaryPagination");
        var summaryMetaRange = document.getElementById("doctorSummaryMetaRange");
        var summaryTodayInput = document.getElementById("doctorSummaryToday");
        var summaryEditRange = document.getElementById("doctorSummaryEditRange");
        var summaryRangeModal = document.getElementById("doctorSummaryRangeModal");
        var summaryRangeCalendars = document.getElementById("doctorSummaryRangeCalendars");
        var summaryRangeFromValue = document.getElementById("doctorSummaryRangeFromValue");
        var summaryRangeToValue = document.getElementById("doctorSummaryRangeToValue");
        var summaryRangeMessage = document.getElementById("doctorSummaryRangeMessage");
        var summaryRangeClose = document.getElementById("doctorSummaryRangeClose");
        var summaryRangeCancel = document.getElementById("doctorSummaryRangeCancel");
        var summaryRangeSet = document.getElementById("doctorSummaryRangeSet");
        var summaryRangeModalState = null;
        var summaryDraftFrom = "";
        var summaryDraftTo = "";
        var summaryCalendarLeftMonth = null;
        var summaryCalendarRightMonth = null;
        var currentSummaryRows = [];

        function summarySvgElement(name, attributes) {
            var element = document.createElementNS("http://www.w3.org/2000/svg", name);
            Object.keys(attributes || {}).forEach(function (key) {
                element.setAttribute(key, attributes[key]);
            });
            return element;
        }

        function summaryAddText(svg, attributes, value) {
            var text = summarySvgElement("text", attributes);
            text.textContent = value;
            svg.appendChild(text);
        }

        function summaryFormatDate(value) {
            var date = new Date(value + "T00:00:00");
            if (Number.isNaN(date.getTime())) return value || "--";
            return date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
        }

        function summaryRangeLabel(period, range) {
            var start = new Date(range.from + "T00:00:00");
            var end = new Date(range.to + "T00:00:00");
            if (period === "monthly") {
                return start.toLocaleDateString("en-US", { month: "short", year: "numeric" }) + " - " + end.toLocaleDateString("en-US", { month: "short", year: "numeric" });
            }
            if (period === "quarterly") {
                var startQuarter = Math.floor(start.getMonth() / 3) + 1;
                var endQuarter = Math.floor(end.getMonth() / 3) + 1;
                return "Q" + startQuarter + " " + start.getFullYear() + " - Q" + endQuarter + " " + end.getFullYear();
            }
            return summaryFormatDate(range.from) + " - " + summaryFormatDate(range.to);
        }

        function summaryValidDate(value) {
            return /^\d{4}-\d{2}-\d{2}$/.test(value || "");
        }

        function summaryRange() {
            var from = summaryFromInput ? summaryFromInput.value : "";
            var to = summaryToInput ? summaryToInput.value : "";
            if (!summaryValidDate(from) || !summaryValidDate(to) || from > to) return null;
            return { from: from, to: to };
        }

        function summaryParseDate(value) {
            if (!summaryValidDate(value)) return null;
            var parts = value.split("-").map(Number);
            var date = new Date(parts[0], parts[1] - 1, parts[2]);
            return date.getFullYear() === parts[0] && date.getMonth() === parts[1] - 1 && date.getDate() === parts[2] ? date : null;
        }

        function summaryDateKey(date) {
            return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
        }

        function summaryDateLabel(value) {
            var date = summaryParseDate(value);
            return date ? date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) : "Select date";
        }

        function summaryTodayDate() {
            var today = summaryTodayInput ? summaryParseDate(summaryTodayInput.value) : null;
            return today || new Date();
        }

        function summaryCurrentMonth() {
            var today = summaryTodayDate();
            return new Date(today.getFullYear(), today.getMonth(), 1);
        }

        function summaryClampCalendarMonth(month) {
            var candidate = new Date(month.getFullYear(), month.getMonth(), 1);
            var currentMonth = summaryCurrentMonth();
            return candidate > currentMonth ? currentMonth : candidate;
        }

        function summaryCalendarYearOptions(month, side) {
            var currentYear = summaryTodayDate().getFullYear();
            var firstYear = 2000;
            var html = "<select class=\"consultation-range-year-select\" data-summary-range-year-side=\"" + side + "\" aria-label=\"Select year\">";
            for (var year = firstYear; year <= currentYear; year++) {
                html += "<option value=\"" + year + "\"" + (year === month.getFullYear() ? " selected" : "") + ">" + year + "</option>";
            }
            return html + "</select>";
        }

        function summarySetCalendarMonth(side, month) {
            var nextMonth = summaryClampCalendarMonth(month);
            if (side === "left") {
                summaryCalendarLeftMonth = nextMonth;
                if (summaryCalendarRightMonth && summaryCalendarRightMonth < nextMonth) {
                    summaryCalendarRightMonth = new Date(nextMonth.getFullYear(), nextMonth.getMonth(), 1);
                }
            } else {
                summaryCalendarRightMonth = nextMonth;
                if (summaryCalendarLeftMonth && summaryCalendarLeftMonth > nextMonth) {
                    summaryCalendarLeftMonth = new Date(nextMonth.getFullYear(), nextMonth.getMonth(), 1);
                }
            }
        }

        function summarySyncRangePreset() {
            if (summaryEditRange) summaryEditRange.hidden = false;
        }

        function summarySetRange(range) {
            if (!range || !summaryFromInput || !summaryToInput) return;
            summaryFromInput.value = range.from;
            summaryToInput.value = range.to;
            summaryPage = 1;
            summaryRender(summaryPeriod, false);
        }

        function summaryRenderRangeMonth(month, side) {
            month = summaryClampCalendarMonth(month);
            var year = month.getFullYear();
            var monthIndex = month.getMonth();
            var first = new Date(year, monthIndex, 1);
            var daysInMonth = new Date(year, monthIndex + 1, 0).getDate();
            var cellCount = Math.ceil((first.getDay() + daysInMonth) / 7) * 7;
            var currentMonth = summaryCurrentMonth();
            var isCurrentMonth = month.getFullYear() === currentMonth.getFullYear() && month.getMonth() === currentMonth.getMonth();
            var atMinimumMonth = year <= 2000 && monthIndex === 0;
            var previous = \'<button type="button" class="consultation-range-nav" data-summary-range-nav="-1" data-summary-range-side="\' + side + \'" aria-label="Previous month"\' + (atMinimumMonth ? " disabled" : "") + \'>\&#8249;</button>\';
            var next = \'<button type="button" class="consultation-range-nav" data-summary-range-nav="1" data-summary-range-side="\' + side + \'" aria-label="Next month"\' + (isCurrentMonth ? " disabled" : "") + \'>\&#8250;</button>\';
            var monthTitle = first.toLocaleDateString("en-US", { month: "long" });
            var html = \'<div class="consultation-range-month"><div class="consultation-range-month-head">\' + previous + \'<span class="consultation-range-month-title"><span>\' + monthTitle + \'</span>\' + summaryCalendarYearOptions(month, side) + \'</span>\' + next + \'</div><div class="consultation-range-weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div><div class="consultation-range-days">\';
            var todayValue = summaryTodayInput ? summaryTodayInput.value : "";
            for (var index = 0; index < cellCount; index++) {
                var date = new Date(year, monthIndex, 1 - first.getDay() + index);
                var key = summaryDateKey(date);
                var inMonth = date.getMonth() === monthIndex;
                var isFuture = todayValue && key > todayValue;
                var isStart = summaryDraftFrom === key;
                var isEnd = summaryDraftTo === key;
                var isInRange = summaryDraftFrom && summaryDraftTo && key > summaryDraftFrom && key < summaryDraftTo;
                var classes = "consultation-range-day" + (inMonth ? "" : " is-outside") + (isInRange ? " is-in-range" : "") + (isStart ? " is-start" : "") + (isEnd ? " is-end" : "");
                html += \'<button type="button" class="\' + classes + \'" data-summary-range-date="\' + key + \'"\' + (!inMonth || isFuture ? " disabled" : "") + \'>\' + date.getDate() + \'</button>\';
            }
            return html + "</div></div>";
        }

        function summaryRenderRangeCalendar() {
            if (!summaryRangeCalendars || !summaryCalendarLeftMonth || !summaryCalendarRightMonth) return;
            var invalidRange = !summaryDraftFrom || !summaryDraftTo || summaryDraftFrom > summaryDraftTo;
            summaryRangeCalendars.innerHTML = summaryRenderRangeMonth(summaryCalendarLeftMonth, "left") + summaryRenderRangeMonth(summaryCalendarRightMonth, "right");
            if (summaryRangeFromValue) summaryRangeFromValue.textContent = summaryDateLabel(summaryDraftFrom);
            if (summaryRangeToValue) summaryRangeToValue.textContent = summaryDateLabel(summaryDraftTo);
            if (summaryRangeSet) summaryRangeSet.disabled = invalidRange;
            if (summaryRangeMessage) summaryRangeMessage.hidden = !invalidRange;
        }

        function summaryOpenRangeModal() {
            if (!summaryRangeModal || !summaryRangeModal.hidden) return;
            var currentRange = summaryRange();
            summaryRangeModalState = { from: currentRange ? currentRange.from : "", to: currentRange ? currentRange.to : "" };
            summaryDraftFrom = summaryRangeModalState.from;
            summaryDraftTo = summaryRangeModalState.to;
            var start = summaryParseDate(summaryDraftFrom) || summaryParseDate(summaryTodayInput ? summaryTodayInput.value : "") || new Date();
            var end = summaryParseDate(summaryDraftTo);
            summaryCalendarLeftMonth = new Date(start.getFullYear(), start.getMonth(), 1);
            summaryCalendarRightMonth = end ? new Date(end.getFullYear(), end.getMonth(), 1) : new Date(start.getFullYear(), start.getMonth() + 1, 1);
            summaryRenderRangeCalendar();
            summaryRangeModal.hidden = false;
            summaryRangeModal.setAttribute("aria-hidden", "false");
            document.body.classList.add("doctor-summary-range-open");
            if (summaryRangeClose) summaryRangeClose.focus();
        }

        function summaryCloseRangeModal(restore) {
            if (!summaryRangeModal) return;
            if (restore && summaryRangeModalState) {
                if (summaryFromInput) summaryFromInput.value = summaryRangeModalState.from;
                if (summaryToInput) summaryToInput.value = summaryRangeModalState.to;
                if (summaryEditRange) summaryEditRange.hidden = false;
            }
            summaryRangeModal.hidden = true;
            summaryRangeModal.setAttribute("aria-hidden", "true");
            document.body.classList.remove("doctor-summary-range-open");
            if (summaryRangeMessage) summaryRangeMessage.hidden = true;
            summaryRangeModalState = null;
        }

        function summaryChooseRangeDate(key) {
            if (!summaryDraftFrom || summaryDraftTo || key < summaryDraftFrom) {
                summaryDraftFrom = key;
                summaryDraftTo = "";
            } else {
                summaryDraftTo = key;
            }
            summaryRenderRangeCalendar();
        }

        function summaryQuarterStart(date) {
            var quarterMonth = Math.floor(date.getMonth() / 3) * 3;
            return new Date(date.getFullYear(), quarterMonth, 1);
        }

        function summaryBucketKey(value, period) {
            var parts = String(value || "").split("-");
            var year = parts[0] || "";
            var month = Math.max(1, parseInt(parts[1] || "1", 10));
            if (period === "daily") return value;
            if (period === "monthly") return year + "-" + String(month).padStart(2, "0");
            return year + "-Q" + (Math.floor((month - 1) / 3) + 1);
        }

        function summaryBuildSeries(period, range) {
            var start = new Date(range.from + "T00:00:00");
            var end = new Date(range.to + "T00:00:00");
            if (period === "monthly") {
                start = new Date(start.getFullYear(), start.getMonth(), 1);
                end = new Date(end.getFullYear(), end.getMonth(), 1);
            } else if (period === "quarterly") {
                start = summaryQuarterStart(start);
                end = summaryQuarterStart(end);
            }

            var counts = {};
            var filtered = summaryRecords.filter(function (record) {
                return record.date >= range.from && record.date <= range.to;
            });
            filtered.forEach(function (record) {
                var key = summaryBucketKey(record.date, period);
                counts[key] = (counts[key] || 0) + 1;
            });

            var points = [];
            for (var cursor = start; cursor.getTime() <= end.getTime();) {
                var key;
                var label;
                var fullLabel;
                if (period === "daily") {
                    key = cursor.getFullYear() + "-" + String(cursor.getMonth() + 1).padStart(2, "0") + "-" + String(cursor.getDate()).padStart(2, "0");
                    label = cursor.toLocaleDateString("en-US", { month: "short", day: "numeric" });
                    fullLabel = cursor.toLocaleDateString("en-US", { month: "long", day: "numeric", year: "numeric" });
                    cursor.setDate(cursor.getDate() + 1);
                } else if (period === "monthly") {
                    key = cursor.getFullYear() + "-" + String(cursor.getMonth() + 1).padStart(2, "0");
                    label = cursor.toLocaleDateString("en-US", { month: "short", year: "numeric" });
                    fullLabel = cursor.toLocaleDateString("en-US", { month: "long", year: "numeric" });
                    cursor.setMonth(cursor.getMonth() + 1);
                } else {
                    var quarter = Math.floor(cursor.getMonth() / 3) + 1;
                    key = cursor.getFullYear() + "-Q" + quarter;
                    label = "Q" + quarter + " " + cursor.getFullYear();
                    fullLabel = "Quarter " + quarter + " of " + cursor.getFullYear();
                    cursor.setMonth(cursor.getMonth() + 3);
                }
                points.push({ key: key, label: label, fullLabel: fullLabel, value: counts[key] || 0 });
            }
            return { points: points, filtered: filtered };
        }

        function summaryRenderChart(period, range, series) {
            if (!summaryChart || !summaryEmpty) return;
            while (summaryChart.firstChild) summaryChart.removeChild(summaryChart.firstChild);
            if (series.filtered.length === 0 || series.points.length === 0) {
                summaryChart.style.display = "none";
                summaryEmpty.hidden = false;
                return;
            }
            summaryChart.style.display = "block";
            summaryEmpty.hidden = true;
            var height = 320;
            var chartContainer = summaryChart.parentElement;
            var width = Math.max(680, chartContainer ? chartContainer.clientWidth : 920);
            summaryChart.setAttribute("viewBox", "0 0 " + width + " " + height);
            var left = 58;
            var right = 22;
            var top = 18;
            var baseline = 260;
            var plotHeight = baseline - top;
            var plotWidth = width - left - right;
            var maxValue = series.points.reduce(function (max, point) { return Math.max(max, point.value); }, 0);
            var chartMax = Math.max(4, Math.ceil(maxValue / 4) * 4);
            var points = series.points.map(function (point, index) {
                var x = series.points.length > 1 ? left + (index * (plotWidth / (series.points.length - 1))) : left + (plotWidth / 2);
                var y = top + plotHeight - ((point.value / chartMax) * plotHeight);
                return { x: x, y: y, label: point.label, fullLabel: point.fullLabel, value: point.value };
            });

            for (var tick = 0; tick <= 4; tick++) {
                var tickY = top + plotHeight - ((chartMax * tick / 4) / chartMax * plotHeight);
                summaryChart.appendChild(summarySvgElement("line", { "class": "summary-grid-line", x1: left, y1: tickY, x2: width - right, y2: tickY }));
                summaryAddText(summaryChart, { x: left - 9, y: tickY + 4, "text-anchor": "end" }, String(Math.round(chartMax * tick / 4)));
            }
            summaryChart.appendChild(summarySvgElement("line", { "class": "summary-axis-line", x1: left, y1: top, x2: left, y2: baseline }));
            summaryChart.appendChild(summarySvgElement("line", { "class": "summary-axis-line", x1: left, y1: baseline, x2: width - right, y2: baseline }));
            var linePoints = points.length === 1
                ? left + "," + points[0].y + " " + (width - right) + "," + points[0].y
                : points.map(function (point) { return point.x + "," + point.y; }).join(" ");
            var areaPoints = left + "," + baseline + " " + linePoints + " " + (width - right) + "," + baseline;
            summaryChart.appendChild(summarySvgElement("polygon", { "class": "summary-area", points: areaPoints }));
            summaryChart.appendChild(summarySvgElement("polyline", { "class": "summary-line", points: linePoints }));
            var labelStep = Math.max(1, Math.ceil(points.length / 8));
            points.forEach(function (point, index) {
                if (point.value > 0) {
                    var dot = summarySvgElement("circle", { "class": "summary-dot", cx: point.x, cy: point.y, r: 4.5 });
                    var title = summarySvgElement("title", {});
                    title.textContent = point.fullLabel + ": " + point.value + " completed consultations";
                    dot.appendChild(title);
                    summaryChart.appendChild(dot);
                }
                if (index === 0 || index === points.length - 1 || index % labelStep === 0) {
                    summaryAddText(summaryChart, { x: point.x, y: baseline + 24, "text-anchor": "middle" }, point.label);
                }
            });
            summaryAddText(summaryChart, { "class": "summary-axis-label", x: left + (plotWidth / 2), y: height - 7, "text-anchor": "middle" }, "Period");
            summaryChart.setAttribute("aria-label", period + " consultation chart");
        }

        function summaryCreatePageLink(label, page, disabled, active, filtered) {
            var link = document.createElement("a");
            link.className = "consultation-summary-page" + (disabled ? " disabled" : "") + (active ? " active" : "");
            link.href = "#consultationSummary";
            link.textContent = label;
            if (active) link.setAttribute("aria-current", "page");
            if (!disabled) {
                link.addEventListener("click", function (event) {
                    event.preventDefault();
                    summaryPage = page;
                    summaryRenderTable(filtered);
                    summaryUpdateUrl();
                });
            }
            return link;
        }

        function summaryRenderTable(filtered) {
            currentSummaryRows = filtered.slice().sort(function (left, right) {
                return (String(right.date) + " " + String(right.time)).localeCompare(String(left.date) + " " + String(left.time));
            });
            var total = currentSummaryRows.length;
            var pageTotal = Math.max(1, Math.ceil(total / summaryPageSize));
            summaryPage = Math.min(summaryPage, pageTotal);
            if (summaryRows) {
                while (summaryRows.firstChild) summaryRows.removeChild(summaryRows.firstChild);
                if (total === 0) {
                    var emptyRow = document.createElement("tr");
                    var emptyCell = document.createElement("td");
                    emptyCell.colSpan = 3;
                    emptyCell.className = "consultation-summary-empty";
                    emptyCell.textContent = "No completed consultations found.";
                    emptyRow.appendChild(emptyCell);
                    summaryRows.appendChild(emptyRow);
                } else {
                    currentSummaryRows.slice((summaryPage - 1) * summaryPageSize, summaryPage * summaryPageSize).forEach(function (record) {
                        var row = document.createElement("tr");
                        var dateCell = document.createElement("td");
                        var nameCell = document.createElement("td");
                        var detailsCell = document.createElement("td");
                        var detailsLink = document.createElement("a");
                        dateCell.textContent = summaryFormatDate(record.date);
                        nameCell.textContent = record.patient_name || "Patient";
                        detailsLink.className = "consultation-summary-details";
                        detailsLink.href = "doctor_patients.php?patient=" + encodeURIComponent(record.patient_id);
                        detailsLink.textContent = "View Details";
                        detailsCell.appendChild(detailsLink);
                        row.appendChild(dateCell);
                        row.appendChild(nameCell);
                        row.appendChild(detailsCell);
                        summaryRows.appendChild(row);
                    });
                }
            }
            if (summaryPagination) {
                summaryPagination.innerHTML = "";
                if (total > 0) {
                    var pagination = document.createElement("div");
                    pagination.className = "consultation-summary-pagination";
                    var summary = document.createElement("span");
                    summary.textContent = "Showing " + (((summaryPage - 1) * summaryPageSize) + 1) + "-" + Math.min(summaryPage * summaryPageSize, total) + " of " + total;
                    var pages = document.createElement("div");
                    pages.className = "consultation-summary-pages";
                    pages.setAttribute("aria-label", "Completed consultation pages");
                    pages.appendChild(summaryCreatePageLink("Previous", Math.max(1, summaryPage - 1), summaryPage === 1, false, currentSummaryRows));
                    for (var pageNumber = 1; pageNumber <= pageTotal; pageNumber++) {
                        pages.appendChild(summaryCreatePageLink(String(pageNumber), pageNumber, false, pageNumber === summaryPage, currentSummaryRows));
                    }
                    pages.appendChild(summaryCreatePageLink("Next", Math.min(pageTotal, summaryPage + 1), summaryPage === pageTotal, false, currentSummaryRows));
                    pagination.appendChild(summary);
                    pagination.appendChild(pages);
                    summaryPagination.appendChild(pagination);
                }
            }
        }

        function summaryUpdateUrl() {
            if (!summaryPeriod) return;
            var url = new URL(window.location.href);
            url.searchParams.set("summary_period", summaryPeriod);
            url.searchParams.set("summary_from", summaryFromInput.value);
            url.searchParams.set("summary_to", summaryToInput.value);
            url.searchParams.set("summary_page", String(summaryPage));
            url.hash = "consultationSummary";
            window.history.replaceState({}, "", url.toString());
        }

        function summaryClearUrl() {
            var url = new URL(window.location.href);
            url.searchParams.delete("summary_period");
            url.searchParams.delete("summary_from");
            url.searchParams.delete("summary_to");
            url.searchParams.delete("summary_page");
            url.hash = "consultationSummary";
            window.history.replaceState({}, "", url.toString());
        }

        function summaryRender(period, resetRange) {
            var defaults = summaryData.defaults && summaryData.defaults[period] ? summaryData.defaults[period] : null;
            if (resetRange || !summaryRange()) {
                if (defaults && summaryFromInput && summaryToInput) {
                    summaryFromInput.value = defaults.from;
                    summaryToInput.value = defaults.to;
                }
                summaryPage = 1;
            }
            summaryPeriod = period;
            if (summaryPeriodInput) summaryPeriodInput.value = period;
            summaryBody.hidden = false;
            document.querySelectorAll("[data-summary-period]").forEach(function (button) {
                var active = button.getAttribute("data-summary-period") === period;
                button.classList.toggle("active", active);
                button.setAttribute("aria-pressed", active ? "true" : "false");
            });
            var range = summaryRange();
            if (!range) return;
            var series = summaryBuildSeries(period, range);
            summaryRenderChart(period, range, series);
            summaryRenderTable(series.filtered);
            if (summaryMetaRange) {
                summaryMetaRange.innerHTML = "";
                var rangeStrong = document.createElement("strong");
                rangeStrong.textContent = period.charAt(0).toUpperCase() + period.slice(1);
                summaryMetaRange.appendChild(rangeStrong);
                summaryMetaRange.appendChild(document.createTextNode(" · " + summaryRangeLabel(period, range)));
            }
            summarySyncRangePreset();
            summaryUpdateUrl();
        }

        document.querySelectorAll("[data-summary-period]").forEach(function (button) {
            button.addEventListener("click", function () {
                var nextPeriod = button.getAttribute("data-summary-period");
                if (summaryPeriod === nextPeriod && !summaryBody.hidden) {
                    summaryBody.hidden = true;
                    summaryPeriod = "";
                    if (summaryPeriodInput) summaryPeriodInput.value = "";
                    document.querySelectorAll("[data-summary-period]").forEach(function (periodButton) {
                        periodButton.classList.remove("active");
                        periodButton.setAttribute("aria-pressed", "false");
                    });
                    summaryClearUrl();
                    return;
                }
                summaryRender(nextPeriod, true);
                summaryBody.scrollIntoView({ behavior: "smooth", block: "start" });
            });
        });

        if (summaryEditRange) summaryEditRange.addEventListener("click", summaryOpenRangeModal);
        if (summaryRangeCalendars) {
            summaryRangeCalendars.addEventListener("click", function (event) {
                var nav = event.target.closest("[data-summary-range-nav]");
                var day = event.target.closest("[data-summary-range-date]");
                if (nav) {
                    var side = nav.getAttribute("data-summary-range-side");
                    var month = side === "left" ? summaryCalendarLeftMonth : summaryCalendarRightMonth;
                    var shift = Number(nav.getAttribute("data-summary-range-nav"));
                    summarySetCalendarMonth(side, new Date(month.getFullYear(), month.getMonth() + shift, 1));
                    summaryRenderRangeCalendar();
                    return;
                }
                if (day && !day.disabled) summaryChooseRangeDate(day.getAttribute("data-summary-range-date"));
            });
        }

        if (summaryRangeCalendars) {
            summaryRangeCalendars.addEventListener("change", function (event) {
                var yearSelect = event.target.closest("[data-summary-range-year-side]");
                if (!yearSelect) return;
                var side = yearSelect.getAttribute("data-summary-range-year-side");
                var month = side === "left" ? summaryCalendarLeftMonth : summaryCalendarRightMonth;
                var year = Number(yearSelect.value);
                if (!Number.isInteger(year) || year < 2000) return;
                summarySetCalendarMonth(side, new Date(year, month.getMonth(), 1));
                summaryRenderRangeCalendar();
            });
        }

        if (summaryRangeClose) summaryRangeClose.addEventListener("click", function () { summaryCloseRangeModal(true); });
        if (summaryRangeCancel) summaryRangeCancel.addEventListener("click", function () { summaryCloseRangeModal(true); });
        if (summaryRangeModal) summaryRangeModal.addEventListener("click", function (event) {
            if (event.target === summaryRangeModal) summaryCloseRangeModal(true);
        });
        if (summaryRangeSet) summaryRangeSet.addEventListener("click", function () {
            var invalidRange = !summaryDraftFrom || !summaryDraftTo || summaryDraftFrom > summaryDraftTo;
            if (invalidRange) {
                if (summaryRangeMessage) {
                    summaryRangeMessage.hidden = false;
                    summaryRangeMessage.textContent = "Select a valid start date and end date first.";
                }
                return;
            }
            if (summaryFromInput) summaryFromInput.value = summaryDraftFrom;
            if (summaryToInput) summaryToInput.value = summaryDraftTo;
            if (summaryEditRange) summaryEditRange.hidden = false;
            summaryCloseRangeModal(false);
            summarySetRange({ from: summaryDraftFrom, to: summaryDraftTo });
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && summaryRangeModal && !summaryRangeModal.hidden) summaryCloseRangeModal(true);
        });

        var summaryResizeTimer = null;
        window.addEventListener("resize", function () {
            if (!summaryPeriod || summaryBody.hidden) return;
            window.clearTimeout(summaryResizeTimer);
            summaryResizeTimer = window.setTimeout(function () {
                summaryRender(summaryPeriod, false);
            }, 120);
        });

        if (summaryPeriod) {
            summaryRender(summaryPeriod, false);
        }
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeConfirmModal();
    });
});
';

include 'includes/header.php';
?>
<main class="doctor-dashboard">
    <div class="doctor-clean-dashboard">
        <section class="doctor-clean-title">
            <h1>Welcome, <?php echo htmlspecialchars($currentUser['full_name']); ?></h1>
            <p>Here is what is happening with your consultation appointments.</p>
        </section>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <section class="doctor-clean-cards" aria-label="Appointment summary">
            <div class="doctor-clean-card">
                <span class="doctor-clean-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                </span>
                <div class="doctor-clean-card-content">
                    <span>Appointments</span>
                    <strong><?php echo (int) $totalAppointments; ?></strong>
                    <small>All assigned appointments</small>
                    <a href="doctor_view_appointments.php">View schedule</a>
                </div>
            </div>
            <div class="doctor-clean-card">
                <span class="doctor-clean-icon pending" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M6 2h12"/><path d="M6 22h12"/><path d="M8 2c0 4 8 4 8 10s-8 6-8 10"/><path d="M16 2c0 4-8 4-8 10s8 6 8 10"/></svg>
                </span>
                <div class="doctor-clean-card-content">
                    <span>Pending Requests</span>
                    <strong><?php echo (int) $statusTotals['pending']; ?></strong>
                    <small>Needs your action</small>
                    <a class="pending-link" href="#appointmentRequests">Review requests</a>
                </div>
            </div>
            <div class="doctor-clean-card">
                <span class="doctor-clean-icon confirmed" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></svg>
                </span>
                <div class="doctor-clean-card-content">
                    <span>Confirmed</span>
                    <strong><?php echo (int) $statusTotals['confirmed']; ?></strong>
                    <small>Appointments confirmed</small>
                    <a class="confirmed-link" href="doctor_view_appointments.php?status=confirmed">View confirmed</a>
                </div>
            </div>
            <div class="doctor-clean-card">
                <span class="doctor-clean-icon done" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></svg>
                </span>
                <div class="doctor-clean-card-content">
                    <span>Completed</span>
                    <strong><?php echo (int) $statusTotals['completed']; ?></strong>
                    <small>Appointments completed</small>
                    <a class="done-link" href="doctor_view_appointments.php?status=completed">View completed</a>
                </div>
            </div>
        </section>

        <section class="doctor-clean-panel consultation-summary" id="consultationSummary">
            <div class="doctor-clean-panel-head">
                <div>
                    <h2>Consultation Summary</h2>
                    <p>Choose a period to view completed consultations.</p>
                </div>
            </div>

            <div class="consultation-summary-options" role="group" aria-label="Consultation summary period">
                <?php foreach ([
                    'daily' => ['Daily', 'Completed today'],
                    'monthly' => ['Monthly', 'Completed this month'],
                    'quarterly' => ['Quarterly', 'Completed this quarter'],
                ] as $periodKey => $periodCopy): ?>
                    <button class="consultation-period-card<?php echo $summaryPeriod === $periodKey ? ' active' : ''; ?>" type="button" data-summary-period="<?php echo $periodKey; ?>" aria-pressed="<?php echo $summaryPeriod === $periodKey ? 'true' : 'false'; ?>">
                        <strong><?php echo htmlspecialchars($periodCopy[0]); ?></strong>
                        <b data-summary-card-value="<?php echo $periodKey; ?>"><?php echo (int) $summaryCardValues[$periodKey]; ?></b>
                        <span><?php echo htmlspecialchars($periodCopy[1]); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <div class="consultation-summary-body" id="doctorConsultationSummaryBody"<?php echo $summaryPeriod === '' ? ' hidden' : ''; ?>>
                    <div class="consultation-summary-filters">
                        <input id="doctorSummaryFrom" type="hidden" name="summary_from" value="<?php echo htmlspecialchars($summaryFrom); ?>">
                        <input id="doctorSummaryTo" type="hidden" name="summary_to" value="<?php echo htmlspecialchars($summaryTo); ?>">
                        <input id="doctorSummaryPeriod" type="hidden" name="summary_period" value="<?php echo htmlspecialchars($summaryPeriod); ?>">
                        <input id="doctorSummaryToday" type="hidden" value="<?php echo htmlspecialchars($today); ?>">
                        <button type="button" class="consultation-summary-range-edit" id="doctorSummaryEditRange">Edit dates</button>
                    </div>

                    <div class="consultation-summary-meta">
                        <span id="doctorSummaryMetaRange"><strong><?php echo $summaryPeriod !== '' ? htmlspecialchars(ucfirst($summaryPeriod)) : 'Select a period'; ?></strong><?php echo $summaryPeriod !== '' ? ' · ' . htmlspecialchars(doctor_summary_period_range_label($summaryPeriod, $summaryFrom, $summaryTo)) : ''; ?></span>
                    </div>

                    <div class="consultation-summary-chart">
                        <svg id="doctorConsultationSummaryChart" viewBox="0 0 920 320" role="img" aria-label="Consultation chart"></svg>
                        <div id="doctorConsultationSummaryEmpty" class="consultation-summary-empty" hidden>No completed consultations in this date range.</div>
                    </div>

                    <div class="consultation-summary-table-wrap">
                        <table class="consultation-summary-table">
                            <thead>
                                <tr>
                                    <th>Consultation Date</th>
                                    <th>Patient Name</th>
                                    <th>View Details</th>
                                </tr>
                            </thead>
                            <tbody id="doctorConsultationSummaryRows"></tbody>
                        </table>
                        <div id="doctorConsultationSummaryPagination"></div>
                    </div>
            </div>
            <script type="application/json" id="doctorConsultationSummaryData"><?php echo json_encode($summaryClientData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
        </section>

        <section class="doctor-clean-panel" id="appointmentRequests">
            <div class="doctor-clean-panel-head">
                <div>
                    <h2>Appointment Requests</h2>
                    <p>New doctor consultation requests that need your confirmation.</p>
                </div>
                <a href="doctor_view_appointments.php">View all</a>
            </div>

            <?php if (empty($pendingRequests)): ?>
                <div class="doctor-clean-table">
                    <div class="empty-state">No pending consultation requests.</div>
                </div>
            <?php else: ?>
                <div class="doctor-clean-table">
                    <div class="doctor-clean-row doctor-clean-head requests">
                        <span>Patient</span>
                        <span>Date &amp; Time</span>
                        <span>Service</span>
                        <span>Status</span>
                        <span>Action</span>
                    </div>
                    <?php foreach (array_slice($pendingRequests, 0, 3) as $appointment): ?>
                        <div class="doctor-clean-row requests">
                            <div class="doctor-clean-patient">
                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'link_target' => 'doctor', 'patient_id' => (int) $appointment['patient_id']]); ?>
                                <div>
                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                    <small><?php echo htmlspecialchars($appointment['patient_phone'] ?: $appointment['patient_email'] ?: 'No contact'); ?></small>
                                </div>
                            </div>
                            <div class="doctor-clean-date">
                                <strong><?php echo htmlspecialchars(doctor_date_label($appointment['appointment_date'])); ?></strong>
                                <small><?php echo doctor_time_label($appointment['appointment_time']); ?></small>
                            </div>
                            <div class="doctor-clean-service">
                                <strong>Consultation</strong>
                                <small><?php echo htmlspecialchars(doctor_service_detail($appointment)); ?></small>
                            </div>
                            <div>
                                <span class="status-badge pending">Pending</span>
                            </div>
                            <div class="doctor-clean-actions">
                                <form method="post" action="update_appointment_status.php">
                                    <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                    <input type="hidden" name="status" value="confirmed">
                                    <input type="hidden" name="return_url" value="doctor.php">
                                    <button class="btn confirm-soft" type="submit" data-confirm-message="Are you sure you want to confirm this consultation request?">Confirm</button>
                                </form>
                                <form method="post" action="update_appointment_status.php">
                                    <input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
                                    <input type="hidden" name="status" value="cancelled">
                                    <input type="hidden" name="return_url" value="doctor.php">
                                    <button class="btn decline-soft" type="submit" data-confirm-message="Are you sure you want to cancel this consultation request?">Cancel</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="consultation-range-modal" id="doctorSummaryRangeModal" hidden aria-hidden="true">
            <section class="consultation-range-dialog" role="dialog" aria-modal="true" aria-labelledby="doctorSummaryRangeTitle">
                <div class="consultation-range-dialog-header">
                    <h3 id="doctorSummaryRangeTitle">Edit dates</h3>
                    <button type="button" class="consultation-range-close" id="doctorSummaryRangeClose" aria-label="Close date range picker">&times;</button>
                </div>
                <div class="consultation-range-calendars" id="doctorSummaryRangeCalendars"></div>
                <div class="consultation-range-footer">
                    <div>
                        <div class="consultation-range-values" aria-live="polite">
                            <span class="consultation-range-value" id="doctorSummaryRangeFromValue">Select start date</span>
                            <span class="consultation-range-separator" aria-hidden="true">&ndash;</span>
                            <span class="consultation-range-value" id="doctorSummaryRangeToValue">Select end date</span>
                        </div>
                        <p class="consultation-range-message" id="doctorSummaryRangeMessage" hidden>Select a start date and an end date first.</p>
                    </div>
                    <div class="consultation-range-actions">
                        <button type="button" class="consultation-range-action" id="doctorSummaryRangeCancel">Cancel</button>
                        <button type="button" class="consultation-range-action primary" id="doctorSummaryRangeSet">Set Date</button>
                    </div>
                </div>
            </section>
        </div>

        <section class="doctor-clean-panel">
            <div class="doctor-clean-panel-head">
                <div>
                    <h2>Today&apos;s Appointments</h2>
                    <p>Your confirmed consultation appointments for today.</p>
                </div>
                <a href="doctor_view_appointments.php">View full schedule</a>
            </div>

            <?php if (empty($todaysConsultations)): ?>
                <div class="doctor-clean-table">
                    <div class="empty-state">No confirmed consultation appointments today.</div>
                </div>
            <?php else: ?>
                <div class="doctor-clean-table">
                    <div class="doctor-clean-row doctor-clean-head today">
                        <span>Time</span>
                        <span>Patient</span>
                        <span>Service</span>
                        <span>Status</span>
                        <span>Action</span>
                    </div>
                    <?php foreach (array_slice($todaysConsultations, 0, 5) as $appointment): ?>
                        <?php $status = doctor_status($appointment); ?>
                        <div class="doctor-clean-row today">
                            <div class="doctor-clean-time">
                                <strong><?php echo doctor_time_label($appointment['appointment_time']); ?></strong>
                            </div>
                            <div class="doctor-clean-patient">
                                <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'link_target' => 'doctor', 'patient_id' => (int) $appointment['patient_id']]); ?>
                                <div>
                                    <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                    <small><?php echo htmlspecialchars($appointment['patient_phone'] ?: $appointment['patient_email'] ?: 'No contact'); ?></small>
                                </div>
                            </div>
                            <div class="doctor-clean-service">
                                <strong>Consultation</strong>
                                <small><?php echo htmlspecialchars(doctor_service_detail($appointment)); ?></small>
                            </div>
                            <div>
                                <span class="status-badge <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars($appointment['status_label']); ?></span>
                            </div>
                            <div class="doctor-clean-actions">
                                <a class="btn secondary" href="doctor_patient.php?id=<?php echo (int) $appointment['patient_id']; ?>">View Patient</a>
                                <?php if ($status === 'confirmed'): ?>
                                    <a class="btn secondary" href="doctor_patient.php?id=<?php echo (int) $appointment['patient_id']; ?>">Add Note</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="doctor-reminder-clean">
                <span>i</span>
                <div><strong>Reminder:</strong> Please add medical notes after each consultation and update the appointment status from the appointments page.</div>
            </div>
        </section>
    </div>

    <div class="doctor-confirm-modal" id="doctorConfirmModal" aria-hidden="true">
        <div class="doctor-confirm-card" role="dialog" aria-modal="true" aria-labelledby="doctorConfirmTitle">
            <div class="doctor-confirm-head">
                <h2 id="doctorConfirmTitle">Confirm appointment action</h2>
                <p id="doctorConfirmText">Are you sure you want to continue?</p>
            </div>
            <div class="doctor-cancel-reason" id="doctorCancelReasonWrap">
                <label for="doctorCancelReason">Cancellation reason</label>
                <textarea id="doctorCancelReason" maxlength="500" placeholder="Add a short reason for cancelling this appointment"></textarea>
                <small id="doctorCancelReasonError">Please add a reason before continuing.</small>
            </div>
            <div class="doctor-confirm-actions">
                <button class="btn secondary" type="button" data-close-doctor-confirm>Back</button>
                <button class="btn confirm-soft" type="button" id="doctorConfirmProceed">Yes, continue</button>
            </div>
        </div>
    </div>
    <div class="doctor-confirm-modal" id="doctorSuccessModal" aria-hidden="true">
        <div class="doctor-confirm-card doctor-success-card" role="dialog" aria-modal="true" aria-labelledby="doctorSuccessTitle">
            <span class="doctor-success-icon" aria-hidden="true">✓</span>
            <h2 id="doctorSuccessTitle">Appointment updated</h2>
            <p id="doctorSuccessText">Appointment status updated successfully.</p>
            <button class="btn confirm-soft" type="button" id="doctorSuccessOk">OK</button>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>









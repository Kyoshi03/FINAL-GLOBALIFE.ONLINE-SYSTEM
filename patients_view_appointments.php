<?php
require_once __DIR__ . '/includes/session.php';
checkRole('patient');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/appointment_booking.php';

$currentUser = getCurrentUser();
$userRole = $currentUser['role'];
$isClinicalAppointmentsView = $userRole === 'doctor';
$appointmentPage = [
    'admin' => 'admin_view_appointments.php',
    'doctor' => 'doctor_view_appointments.php',
    'patient' => 'patients_view_appointments.php',
][$userRole] ?? 'admin_view_appointments.php';

// Determine page title based on user role
$pageTitle = "Appointments | Globalife Medical Laboratory & Polyclinic";

$conn = getDBConnection();
appointment_init_queue_schema($conn);
$patientNameSql = dbUsersNameExpression('p');
$doctorNameSql = dbUsersNameExpression('d');
$headerDoctorNameSql = dbUsersNameExpression('u');
$userNameSql = dbUsersNameExpression();

// Get appointments based on user role
if ($userRole === 'patient') {
    checkRole('patient');
    $stmt = $conn->prepare("SELECT a.*, {$headerDoctorNameSql} as doctor_name, q.queue_number
                            FROM appointments a 
                            LEFT JOIN users u ON a.doctor_id = u.id 
                            LEFT JOIN clinic_queue q ON q.appointment_id = a.id
                            WHERE a.patient_id = ? 
                            ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->bind_param("i", $currentUser['id']);
} elseif ($userRole === 'admin') {
    checkRole($userRole);
    $stmt = $conn->prepare("SELECT a.*, 
                            {$patientNameSql} as patient_name,
                            p.profile_photo,
                            p.profile_updated_at,
                            {$doctorNameSql} as doctor_name,
                            q.queue_number
                            FROM appointments a 
                            JOIN users p ON a.patient_id = p.id 
                            LEFT JOIN users d ON a.doctor_id = d.id 
                            LEFT JOIN clinic_queue q ON q.appointment_id = a.id
                            ORDER BY a.appointment_date DESC, a.appointment_time DESC");
} elseif ($userRole === 'doctor') {
    checkRole('doctor');
    $stmt = $conn->prepare("SELECT a.*, 
                            {$patientNameSql} as patient_name,
                            p.profile_photo,
                            p.profile_updated_at,
                            {$doctorNameSql} as doctor_name,
                            q.queue_number
                            FROM appointments a 
                            JOIN users p ON a.patient_id = p.id 
                            LEFT JOIN users d ON a.doctor_id = d.id 
                            LEFT JOIN clinic_queue q ON q.appointment_id = a.id
                            WHERE a.doctor_id = ?
                              AND a.booking_type = 'consultation'
                            ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->bind_param('i', $currentUser['id']);
} else {
    header('Location: index.php');
    exit();
}

$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$initialStatusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$allowedStatusFilters = ['pending', 'confirmed', 'completed', 'cancelled'];
if (!in_array($initialStatusFilter, $allowedStatusFilters, true)) {
    $initialStatusFilter = '';
}

function appointment_report_service_text(array $appointment): string {
    $bookingType = (string) ($appointment['booking_type'] ?? '');
    if ($bookingType === 'consultation') {
        return 'Consultation';
    }
    if ($bookingType === 'ultrasound') {
        return 'Ultrasound';
    }
    if ($bookingType === 'package') {
        return 'Lab Package';
    }
    if ($bookingType === 'individual') {
        return 'Lab Test';
    }
    return 'Appointment';
}

function appointment_report_queue_type_text(?string $type): string {
    return (string) $type === 'online' ? 'Online' : 'Walk-in';
}

function appointment_report_date_label(string $from, string $to): string {
    $fromTime = strtotime($from);
    $toTime = strtotime($to);
    if (!$fromTime || !$toTime) {
        return 'Selected dates';
    }
    if (date('Y-m', $fromTime) === date('Y-m', $toTime)) {
        return date('F j', $fromTime) . '-' . date('j, Y', $toTime);
    }
    return date('F j, Y', $fromTime) . ' - ' . date('F j, Y', $toTime);
}

$reportType = strtolower(trim((string) ($_GET['report_type'] ?? 'appointments')));
$allowedReportTypes = ['appointments', 'queue', 'patients'];
if (!in_array($reportType, $allowedReportTypes, true)) {
    $reportType = 'appointments';
}
$reportDateFrom = trim((string) ($_GET['date_from'] ?? date('Y-m-01')));
$reportDateTo = trim((string) ($_GET['date_to'] ?? date('Y-m-t')));
$reportDateFromObj = DateTime::createFromFormat('!Y-m-d', $reportDateFrom);
$reportDateToObj = DateTime::createFromFormat('!Y-m-d', $reportDateTo);
if (!$reportDateFromObj) {
    $reportDateFrom = date('Y-m-01');
}
if (!$reportDateToObj) {
    $reportDateTo = date('Y-m-t');
}
if ($reportDateFrom > $reportDateTo) {
    [$reportDateFrom, $reportDateTo] = [$reportDateTo, $reportDateFrom];
}
$reportStatus = strtolower(trim((string) ($_GET['report_status'] ?? '')));
$allowedReportStatusFilters = ['pending', 'confirmed', 'completed', 'cancelled', 'waiting', 'serving'];
if (!in_array($reportStatus, $allowedReportStatusFilters, true)) {
    $reportStatus = '';
}
$showAppointmentReport = $userRole === 'admin' && isset($_GET['generate_report']);
$generatedReport = [
    'title' => 'Appointments Report',
    'period' => appointment_report_date_label($reportDateFrom, $reportDateTo),
    'headers' => ['Date', 'Patient', 'Service', 'Status'],
    'rows' => [],
    'summary' => [],
];
$appointmentReportCounts = ['total' => 0, 'completed' => 0, 'pending' => 0, 'cancelled' => 0, 'confirmed' => 0];
if ($showAppointmentReport) {
    if ($reportType === 'queue') {
        $queueStatuses = ['waiting', 'serving', 'completed'];
        $queueStatus = in_array($reportStatus, $queueStatuses, true) ? $reportStatus : '';
        $queueWhere = ["q.queue_date >= '" . $conn->real_escape_string($reportDateFrom) . "'", "q.queue_date <= '" . $conn->real_escape_string($reportDateTo) . "'"];
        if ($queueStatus !== '') {
            $queueWhere[] = "q.status = '" . $conn->real_escape_string($queueStatus) . "'";
        }
        $queueSql = "SELECT q.queue_number, q.queue_type, q.status, q.queue_date, {$patientNameSql} AS patient_name
            FROM clinic_queue q
            JOIN users p ON p.id = q.patient_id
            WHERE " . implode(' AND ', $queueWhere) . "
            ORDER BY q.queue_date ASC, q.id ASC";
        $queueResult = $conn->query($queueSql);
        $queueCounts = ['walk_in' => 0, 'online' => 0, 'completed' => 0, 'waiting' => 0];
        if ($queueResult) {
            while ($queueRow = $queueResult->fetch_assoc()) {
                $queueType = (string) ($queueRow['queue_type'] ?? 'walk_in');
                $queueStatusValue = (string) ($queueRow['status'] ?? 'waiting');
                if (isset($queueCounts[$queueType])) {
                    $queueCounts[$queueType]++;
                }
                if (isset($queueCounts[$queueStatusValue])) {
                    $queueCounts[$queueStatusValue]++;
                }
                $generatedReport['rows'][] = [
                    (string) ($queueRow['queue_number'] ?? ''),
                    (string) ($queueRow['patient_name'] ?? 'Patient'),
                    appointment_report_queue_type_text($queueType),
                    ucfirst($queueStatusValue),
                ];
            }
        }
        $generatedReport['title'] = 'Queue Report';
        $generatedReport['headers'] = ['Queue', 'Patient', 'Type', 'Status'];
        $generatedReport['summary'] = [
            'Walk-in Patients' => $queueCounts['walk_in'],
            'Online Patients' => $queueCounts['online'],
            'Completed' => $queueCounts['completed'],
            'Waiting' => $queueCounts['waiting'],
        ];
    } elseif ($reportType === 'patients') {
        $patientWhere = ["DATE(created_at) >= '" . $conn->real_escape_string($reportDateFrom) . "'", "DATE(created_at) <= '" . $conn->real_escape_string($reportDateTo) . "'", "role = 'patient'"];
        $patientSql = "SELECT {$userNameSql} AS full_name, phone, email, created_at FROM users WHERE " . implode(' AND ', $patientWhere) . " ORDER BY created_at ASC, {$userNameSql} ASC";
        $patientResult = $conn->query($patientSql);
        if ($patientResult) {
            while ($patientRow = $patientResult->fetch_assoc()) {
                $generatedReport['rows'][] = [
                    date('M d', strtotime((string) $patientRow['created_at'])),
                    (string) ($patientRow['full_name'] ?? 'Patient'),
                    (string) (($patientRow['phone'] ?? '') ?: '-'),
                    (string) (($patientRow['email'] ?? '') ?: '-'),
                ];
            }
        }
        $generatedReport['title'] = 'Patients Report';
        $generatedReport['headers'] = ['Date', 'Patient', 'Contact', 'Email'];
        $generatedReport['summary'] = [
            'Total Patients' => count($generatedReport['rows']),
        ];
    } else {
        foreach ($appointments as $appointment) {
            $appointmentDate = (string) ($appointment['appointment_date'] ?? '');
            $appointmentStatus = strtolower((string) ($appointment['status'] ?? 'pending'));
            if ($appointmentDate < $reportDateFrom || $appointmentDate > $reportDateTo) {
                continue;
            }
            if ($reportStatus !== '' && $appointmentStatus !== $reportStatus) {
                continue;
            }
            $generatedReport['rows'][] = [
                date('M d', strtotime($appointmentDate)),
                (string) ($appointment['patient_name'] ?? 'Patient'),
                appointment_report_service_text($appointment),
                ucfirst($appointmentStatus),
            ];
            $appointmentReportCounts['total']++;
            if (isset($appointmentReportCounts[$appointmentStatus])) {
                $appointmentReportCounts[$appointmentStatus]++;
            }
        }
        $generatedReport['summary'] = [
            'Total Appointments' => $appointmentReportCounts['total'],
            'Completed' => $appointmentReportCounts['completed'],
            'Pending' => $appointmentReportCounts['pending'],
            'Cancelled' => $appointmentReportCounts['cancelled'],
        ];
    }
    $generatedReport['generated_at'] = date('Y-m-d H:i');
}

$statusCounts = [
    'all' => count($appointments),
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$todayCount = 0;
$upcomingCount = 0;
$needsAttentionCount = 0;
$now = new DateTime();
$todayYmd = $now->format('Y-m-d');

foreach ($appointments as $appointment) {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }

    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '00:00:00');
    if ($date === $todayYmd) {
        $todayCount++;
    }
    if ($date !== '') {
        try {
            $appointmentDateTime = new DateTime($date . ' ' . $time);
            if ($appointmentDateTime >= $now && $status !== 'cancelled' && $status !== 'completed') {
                $upcomingCount++;
            }
            if ($appointmentDateTime < $now && ($status === 'pending' || $status === 'confirmed')) {
                $needsAttentionCount++;
            }
        } catch (Exception $e) {
            // Ignore malformed appointment dates so the page can still load.
        }
    }
}

$patientsById = [];
if ($userRole !== 'patient' && !$isClinicalAppointmentsView) {
    foreach (fetchPatientsForStaffDirectory($conn) as $patientRow) {
        $patientsById[(int) $patientRow['id']] = $patientRow;
    }
}

if ($userRole === 'patient') {
    $stmt = $conn->prepare("SELECT {$userNameSql} AS full_name, profile_photo, profile_updated_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $patientHeaderDetails = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $headerPatientPhotoUrl = patientProfilePhotoUrl($patientHeaderDetails['profile_photo'] ?? null, $patientHeaderDetails['profile_updated_at'] ?? null);
    $headerPatientInitials = patientProfileInitials($patientHeaderDetails['full_name'] ?? $currentUser['full_name']);
    $headerPatientDisplayName = $patientHeaderDetails['full_name'] ?? $currentUser['full_name'];
}

$conn->close();

$additionalStyles = patientAvatarStyles() . '
    .patient-cell{display:flex;align-items:center;gap:10px}
    .patient-cell .patient-name{color:#0066cc;font-weight:700}
    .appointments-doctor,.appointments-services{color:#5c6f7a;font-size:.875rem;font-weight:500}
    .appointments-date{color:#1a3342;font-weight:700;font-size:.875rem}
    .appointment-schedule-cell{min-width:145px;white-space:nowrap}
    .appointment-schedule-cell strong,.appointment-schedule-cell span{display:block}
    .appointment-schedule-cell strong{font-size:.86rem}
    .appointment-schedule-cell span{color:#60758a;font-size:.82rem;margin-top:2px}
    body {
        background:#f4f8fb;
        min-height:100vh;
        color:#1f343d;
    }
    .appointments-container {
        max-width:1180px;
        margin:0 auto;
        padding:28px 20px 48px;
    }
    .page-header {
        background: #073b4c;
        border-radius: 8px;
        padding: 40px;
        margin-bottom: 40px;
        color: #fff;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.18);
    }
    .page-header.patient-page-header {
        background: #073b4c;
        border-radius: 8px;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.18);
    }
    .page-header h2 {
        margin: 0 0 10px 0;
        font-size: 2.5rem;
        font-weight: 700;
    }
    .page-header p {
        margin: 0;
        font-size: 1.1rem;
        opacity: 0.95;
    }
    .appointment-actions-top {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 22px;
    }
    .appointment-actions-top a {
        color: #fff;
        border: 1px solid rgba(255,255,255,.55);
        border-radius: 10px;
        padding: 10px 16px;
        text-decoration: none;
        font-weight: 700;
        background: rgba(255,255,255,.16);
    }
    .appointment-actions-top a:hover {
        background: rgba(255,255,255,.28);
    }
    .appointment-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: #fff;
        border: 1px solid #dceef2;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 5px 22px rgba(0,0,0,.06);
    }
    .stat-card span {
        display: block;
        color: #60758a;
        font-size: .82rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 8px;
    }
    .stat-card strong {
        color: #023e8a;
        font-size: 1.9rem;
        line-height: 1;
    }
    .stat-card p {
        margin: 8px 0 0;
        color: #5d6d76;
        font-size: .88rem;
        line-height: 1.45;
    }
    .appointment-tools {
        display:grid;
        grid-template-columns:minmax(240px,1fr) 180px 180px auto;
        gap:14px;
        align-items:end;
    }
    .appointment-tools.patient-filters {
        grid-template-columns:minmax(220px,1fr) 220px auto auto;
    }
    .patient-custom-range{grid-column:1 / -1;display:flex;gap:12px;flex-wrap:wrap}
    .patient-custom-range[hidden]{display:none}
    .patient-custom-range .tool-field{flex:1 1 200px}
    .patient-date-error{grid-column:1 / -1;margin:-4px 0 0;color:#b42318;font-size:.84rem;font-weight:700}
    .appointments-filter-card{
        background:#fff;
        border:1px solid #e4edf2;
        border-radius:12px;
        padding:20px 22px;
        margin-bottom:18px;
        box-shadow:0 10px 24px rgba(25,76,110,.05);
    }
    .appointment-report-card{
        display:flex;
        justify-content:flex-end;
        margin-bottom:14px;
    }
    .appointment-report-head{
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:14px;
    }
    .appointment-report-head h2{
        margin:0 0 4px;
        color:#073b4c;
        font-size:1.18rem;
    }
    .appointment-report-head p{
        margin:0;
        color:#607784;
        font-size:.9rem;
        line-height:1.45;
    }
    .appointment-generated-report{
        margin-top:16px;
        border:1px solid #d8e6ed;
        border-radius:8px;
        background:#fff;
        overflow:hidden;
    }
    .appointment-generated-head{
        padding:18px 20px;
        background:#f8fcff;
        border-bottom:1px solid #e4edf2;
        text-align:center;
    }
    .appointment-generated-head h3{
        margin:0 0 4px;
        color:#073b4c;
        font-size:1.15rem;
        font-weight:900;
        text-transform:uppercase;
    }
    .appointment-generated-head p{
        margin:0;
        color:#607784;
        font-size:.9rem;
    }
    .appointment-report-table{
        width:100%;
        border-collapse:collapse;
    }
    .appointment-report-table th,
    .appointment-report-table td{
        padding:11px 14px;
        border-bottom:1px solid #eef3f6;
        text-align:left;
        font-size:.86rem;
    }
    .appointment-report-table th{
        color:#607784;
        background:#fff;
        font-weight:900;
    }
    .appointment-report-summary{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:8px;
        padding:16px 20px;
        background:#f8fcff;
    }
    .appointment-report-summary span{
        color:#607784;
        font-size:.76rem;
        font-weight:900;
        text-transform:uppercase;
    }
    .appointment-report-summary strong{
        display:block;
        color:#073b4c;
        font-size:1.25rem;
        margin-top:3px;
    }
    .appointment-report-actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        padding:0 20px 18px;
    }
    .appointment-report-actions .btn{
        min-width:72px;
        text-align:center;
    }
    .report-print-header,
    .report-print-summary,
    .report-print-rows-title{
        display:none;
    }
    @media print{
        @page{size:A4;margin:12mm 14mm}
        html,body{width:100%!important;height:auto!important;margin:0!important;padding:0!important;background:#fff!important;color:#073b4c!important}
        body.printing-report > *{display:none!important}
        body.printing-report #appointmentReportModal{display:block!important;position:static!important;inset:auto!important;width:100%!important;height:auto!important;min-height:0!important;margin:0!important;padding:0!important;background:#fff!important;backdrop-filter:none!important;overflow:visible!important}
        body.printing-report #appointmentReportModal .report-modal-card{display:block!important;position:static!important;width:100%!important;max-height:none!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;box-shadow:none!important;overflow:visible!important}
        body.printing-report #appointmentReportModal .appointment-modal-head{display:none!important}
        body.printing-report #appointmentReportModal .appointment-modal-body{display:block!important;padding:0!important}
        #appointmentGeneratedReport{
            display:block!important;
            width:100%!important;
            max-width:100%!important;
            margin:0!important;
            padding:0!important;
            border:0!important;
            border-radius:0!important;
            box-shadow:none!important;
            background:#fff!important;
            overflow:visible!important;
            font-family:Arial,sans-serif!important;
            page-break-after:avoid!important;
        }
        #appointmentGeneratedReport *{visibility:visible!important}
        #appointmentGeneratedReport .appointment-generated-head,
        #appointmentGeneratedReport > .appointment-report-summary{display:none!important}
        #appointmentGeneratedReport .report-print-header{display:block!important;padding:0 0 22px!important}
        #appointmentGeneratedReport .report-print-line{display:block!important;height:2px!important;background:#0b8d96!important;margin:0 0 18px!important}
        #appointmentGeneratedReport .report-print-header .report-print-line:last-child{margin:22px 0 0!important;height:1px!important}
        #appointmentGeneratedReport .report-print-header h1{display:block!important;margin:0 0 8px!important;color:#032642!important;font-size:26px!important;line-height:1.12!important;font-weight:900!important}
        #appointmentGeneratedReport .report-print-header h2{display:block!important;margin:0 0 8px!important;color:#032642!important;font-size:16px!important;line-height:1.15!important;font-weight:900!important}
        #appointmentGeneratedReport .report-print-header p{display:block!important;margin:0!important;color:#032642!important;font-size:13px!important}
        #appointmentGeneratedReport .report-print-summary{display:block!important;margin:0 0 18px!important;padding:0!important;background:#fff!important}
        #appointmentGeneratedReport .report-print-summary div{display:grid!important;grid-template-columns:210px minmax(0,1fr)!important;margin:0 0 7px!important}
        #appointmentGeneratedReport .report-print-summary dt{display:block!important;color:#032642!important;font-size:13px!important;font-weight:900!important}
        #appointmentGeneratedReport .report-print-summary dd{display:block!important;margin:0!important;color:#032642!important;font-size:13px!important}
        #appointmentGeneratedReport .report-print-rows-title{display:block!important;margin:0 0 12px!important;color:#032642!important;font-size:15px!important;font-weight:900!important}
        #appointmentGeneratedReport .appointment-report-table{
            width:100%!important;
            border-collapse:collapse!important;
            margin:0 0 14px!important;
            page-break-inside:auto!important;
        }
        #appointmentGeneratedReport .appointment-report-table th,
        #appointmentGeneratedReport .appointment-report-table td{
            padding:7px 10px!important;
            border-bottom:1px solid #dfe8ee!important;
            color:#1f343d!important;
            font-size:11px!important;
            text-align:left!important;
        }
        #appointmentGeneratedReport .appointment-report-table th{
            color:#032642!important;
            font-weight:900!important;
            background:#fff!important;
            border-bottom:1px solid #0b8d96!important;
        }
        #appointmentGeneratedReport .appointment-report-summary{
            display:block!important;
            padding:0 0 0 4px!important;
            background:#fff!important;
            page-break-inside:avoid!important;
        }
        #appointmentGeneratedReport .appointment-report-summary div{
            margin:0 0 7px!important;
            page-break-inside:avoid!important;
        }
        #appointmentGeneratedReport .appointment-report-summary span{
            display:block!important;
            color:#1f343d!important;
            font-size:10px!important;
            font-weight:900!important;
            text-transform:uppercase!important;
        }
        #appointmentGeneratedReport .appointment-report-summary strong{
            display:block!important;
            margin-top:2px!important;
            color:#073b4c!important;
            font-size:14px!important;
            font-weight:900!important;
        }
        #appointmentGeneratedReport .appointment-report-actions{display:none!important}
    }
    .tool-field label {
        display:block;
        color:#1a3342;
        font-size:.875rem;
        font-weight:700;
        margin-bottom:8px;
    }
    .tool-field input,
    .tool-field select {
        width:100%;
        box-sizing:border-box;
        border:1px solid #dce8ef;
        border-radius:8px;
        min-height:42px;
        padding:9px 12px;
        font:inherit;
        font-size:.875rem;
        font-weight:500;
        color:#1a3342;
        background:#fff;
    }
    .tool-field input:focus,
    .tool-field select:focus {
        outline:none;
        border-color:#0f7cc2;
        box-shadow:0 0 0 3px rgba(15,124,194,.08);
    }
    .filter-reset-btn {
        min-height:42px;
        border:1px solid #dce8ef;
        border-radius:8px;
        background:#fff;
        color:#0066cc;
        font-weight:700;
        font-size:.875rem;
        cursor:pointer;
        padding:0 16px;
    }
    .filter-reset-btn:hover {
        background:#f3faff;
        border-color:#b8d9f0;
    }
    .appointment-result-count {
        margin:0 0 16px;
        color:#1a3342;
        font-size:.95rem;
        font-weight:800;
    }
    .filter-empty {
        display: none;
        text-align: center;
        padding: 28px 16px;
        color: #60758a;
        background: #f8fcfd;
        border: 1px dashed #b8dfe8;
        border-radius: 12px;
        margin-top: 16px;
    }
    .filter-empty strong {
        display: block;
        color: #073b4c;
        margin-bottom: 6px;
    }
    .appointments-table-wrapper {
        background:#fff;
        border:1px solid #e4edf2;
        border-radius:12px;
        padding:22px 24px 20px;
        box-shadow:0 10px 24px rgba(25,76,110,.05);
        overflow:hidden;
    }
    .appointments-scroll {
        max-height:620px;
        overflow:auto;
        padding-right:4px;
        scrollbar-width:thin;
        scrollbar-color:#c5d3dc #f4f8fb;
    }
    .appointments-table {
        width:100%;
        min-width:940px;
        border-collapse:collapse;
    }
    .appointments-table th {
        position:sticky;
        top:0;
        z-index:3;
        background:#fff;
        color:#708792;
        padding:12px 14px;
        text-align:left;
        font-size:.72rem;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
        border-bottom:1px solid #eef3f6;
    }
    .appointments-table td {
        padding:14px;
        border-bottom:1px solid #eef3f6;
        vertical-align:middle;
        font-size:.875rem;
    }
    .appointments-table tr:hover {
        background:#f8fcff;
    }
    .appointments-table tbody tr:last-child td {
        border-bottom: 0;
    }
    .appointment-row.hidden,
    .appointment-row.page-hidden {
        display: none;
    }
    .status-badge {
        padding:4px 10px;
        border-radius:999px;
        font-size:.68rem;
        font-weight:800;
        letter-spacing:.04em;
        text-transform:uppercase;
        display:inline-flex;
        align-items:center;
        white-space:nowrap;
    }
    .status-badge.pending {
        background:#fff6e6;
        color:#a16207;
    }
    .status-badge.confirmed {
        background:#e8f2ff;
        color:#0066cc;
    }
    .status-badge.completed {
        background:#e6f6ec;
        color:#168a45;
    }
    .status-badge.cancelled {
        background:#fdecef;
        color:#b42318;
    }
    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .action-buttons.pending-actions {
        display: grid;
        grid-template-columns: repeat(2, max-content);
        align-items: start;
    }
    .action-buttons.pending-actions .decline-action {
        grid-column: 2;
    }
    .btn {
        padding:8px 14px;
        border-radius:8px;
        font-weight:700;
        font-size:.82rem;
        cursor:pointer;
        text-decoration:none;
        display:inline-block;
        transition:background .15s ease,border-color .15s ease,color .15s ease;
    }
    .btn-primary {
        background:#0f7cc2;
        color:#fff;
        border:1px solid #0f7cc2;
    }
    .btn-primary:hover {
        background:#0b66a0;
        border-color:#0b66a0;
    }
    .btn-secondary {
        background:#eef7ff;
        color:#0b4f80;
        border:1px solid #cfe3f2;
    }
    .btn-secondary:hover {
        background:#e1f1fc;
        border-color:#afd2e8;
    }
    .btn-confirm {
        background:#28a745;
        color:#fff;
        border:1px solid #28a745;
    }
    .btn-confirm:hover {
        background:#218838;
    }
    .btn-cancel {
        background:#dc3545;
        color:#fff;
        border:1px solid #dc3545;
    }
    .btn-cancel:hover {
        background:#c82333;
    }
    .btn-complete {
        background:#17a2b8;
        color:#fff;
        border:1px solid #17a2b8;
    }
    .btn-complete:hover {
        background:#138496;
    }
    .btn-view,
    .btn-details {
        background:#fff;
        color:#0066cc;
        border:1px solid #cde1ed;
    }
    .btn-view:hover,
    .btn-details:hover {
        background:#f3faff;
        border-color:#0f7cc2;
    }
    .appointment-modal-actions .btn-view {
        background:#0077b6;
        color:#fff;
        border:1px solid #0077b6;
    }
    .appointment-modal-actions .btn-view:hover {
        background:#005f8d;
        border-color:#005f8d;
    }
    .appointment-row { cursor: pointer; }
    .appointment-row:focus { outline: 3px solid rgba(0,119,182,.25); outline-offset: -3px; }
    .appointment-row.is-highlighted { outline: 3px solid rgba(72, 202, 228, .45); outline-offset: -3px; background: #f0fbff; }
    .appointment-modal { position: fixed; inset: 0; z-index: 3000; display: none; align-items: center; justify-content: center; padding: 18px; background: rgba(3, 18, 30, .50); backdrop-filter: blur(4px); }
    .appointment-modal.is-open { display: flex; }
    .appointment-modal-card { width: min(720px, 100%); background: #fff; border-radius: 22px; box-shadow: 0 24px 70px rgba(2,62,138,.20); overflow: hidden; border: 1px solid #cde8f3; }
    .appointment-modal-card.report-modal-card { width: min(960px, 100%); max-height: calc(100vh - 36px); overflow: auto; }
    .appointment-modal-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; padding: 24px 26px; background: radial-gradient(circle at 90% 12%, rgba(72,202,228,.24), transparent 34%), linear-gradient(135deg, #ffffff 0%, #eefaff 100%); border-bottom: 1px solid #d8eef7; color: #10233f; }
    .appointment-modal-head h3 { margin: 0; color: #10233f; font-size: 1.28rem; line-height: 1.2; }
    .appointment-modal-head p { display: inline-flex; align-items: center; margin: 8px 0 0; padding: 7px 11px; border-radius: 999px; background: #eaf8fc; color: #0077b6; font-size: .86rem; font-weight: 850; }
    .modal-close { border: 0; background: #eaf8fc; color: #0077b6; width: 40px; height: 40px; border-radius: 13px; cursor: pointer; font-size: 1.35rem; line-height: 1; font-weight: 900; }
    .modal-close:hover { background: #d8f2fb; }
    .appointment-modal-body { padding: 22px 24px; }
    .detail-summary-strip { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; margin-bottom: 18px; }
    .detail-pill { background:#f8fdff; border:1px solid #d8eef7; border-radius:14px; padding:14px; }
    .detail-pill span { display:block; color:#60758a; font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
    .detail-pill strong { color:#10233f; font-size:1rem; overflow-wrap:anywhere; }
    .appointment-detail-grid { display: grid; grid-template-columns: 150px 1fr; gap: 12px 16px; padding: 16px; border: 1px solid #d8eef7; border-radius: 16px; background: #ffffff; }
    .appointment-detail-grid dt { color: #60758a; font-weight: 850; }
    .appointment-detail-grid dd { margin: 0; color: #315c70; overflow-wrap: anywhere; line-height: 1.45; }
    .detail-notes-box { margin-top: 16px; padding: 16px; border-radius: 16px; background:#f8fdff; border:1px solid #d8eef7; color:#315c70; line-height:1.55; }
    .detail-notes-box strong { display:block; color:#10233f; margin-bottom:6px; }
    .cancel-reason-field { display:none; padding: 0 22px 4px; }
    .cancel-reason-field.is-open { display:block; }
    .cancel-reason-field label { display:block; margin-bottom: 8px; color:#10233f; font-weight:800; }
    .cancel-reason-field textarea {
        width:100%;
        min-height:96px;
        resize:vertical;
        border:1px solid #d4e6f5;
        border-radius:8px;
        padding:12px;
        color:#203b4a;
        font:inherit;
        line-height:1.45;
        box-sizing:border-box;
        background:#fbfdff;
    }
    .cancel-reason-field textarea:focus { border-color:#0f7cc2; box-shadow:0 0 0 4px rgba(15,124,194,.1); outline:none; }
    .cancel-reason-error { display:none; margin-top:7px; color:#b4232d; font-size:.86rem; font-weight:700; }
    .cancel-reason-error.is-open { display:block; }
    .appointment-modal-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 0 24px 24px; }
    .appointment-success-card { width: min(420px, 100%); text-align: center; }
    .appointment-success-body { padding: 30px 28px 28px; }
    .appointment-success-icon { display: inline-grid; place-items: center; width: 64px; height: 64px; margin-bottom: 16px; border-radius: 50%; background: #dcf7e7; color: #148047; font-size: 2rem; font-weight: 950; }
    .appointment-success-body h3 { margin: 0 0 8px; color: #073b4c; font-size: 1.28rem; }
    .appointment-success-body p { margin: 0 0 20px; color: #60727d; line-height: 1.45; }
    .btn-light { background: #eef5fb; color: #023e8a; }
    .btn-light:hover { background: #dcecf8; }
    .clinical-appointments-page {
        max-width: 1280px;
        margin-top: 28px;
    }
    .clinical-page-title {
        margin: 0 0 24px;
    }
    .clinical-page-title h1 {
        margin: 0 0 8px;
        color: #061a40;
        font-size: 2rem;
        line-height: 1.15;
    }
    .clinical-page-title p {
        margin: 0;
        color: #60758a;
        font-size: 1rem;
    }
    .clinical-stat-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }
    .clinical-stat-card {
        display: grid;
        grid-template-columns: 58px minmax(0, 1fr);
        gap: 18px;
        align-items: center;
        min-height: 110px;
        padding: 22px;
        border: 1px solid #d8e6ed;
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    }
    .clinical-stat-icon {
        width: 58px;
        height: 58px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: #edf6ff;
        color: #0f7cc2;
    }
    .clinical-stat-icon.pending {
        background: #fff6df;
        color: #e08300;
    }
    .clinical-stat-icon.done {
        background: #eaf7ef;
        color: #17643a;
    }
    .clinical-stat-icon svg {
        width: 28px;
        height: 28px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .clinical-stat-card span {
        display: block;
        color: #1f343d;
        font-weight: 900;
    }
    .clinical-stat-card strong {
        display: block;
        margin-top: 5px;
        color: #0066cc;
        font-size: 2rem;
        line-height: 1;
    }
    .clinical-stat-card small {
        display: block;
        margin-top: 8px;
        color: #60758a;
    }
    .clinical-appointments-page .appointments-table-wrapper {
        border: 1px solid #d8e6ed;
        border-radius: 8px;
        padding: 22px;
        box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    }
    .clinical-panel-head {
        margin-bottom: 18px;
    }
    .clinical-panel-head h2 {
        margin: 0 0 5px;
        color: #1f343d;
        font-size: 1.24rem;
    }
    .clinical-panel-head p {
        margin: 0;
        color: #60758a;
        font-size: .92rem;
    }
    .clinical-appointments-page .appointment-tools {
        grid-template-columns: minmax(260px, 1fr) minmax(150px, 180px) minmax(150px, 180px) auto;
        align-items: center;
    }
    .clinical-appointments-page .tool-field label {
        display: none;
    }
    .appointment-pagination-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding-top: 18px;
        margin-top: 4px;
        border-top: 1px solid #eef3f6;
        color: #708792;
        font-size: .875rem;
        font-weight: 500;
    }
    .appointment-pagination {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .appointment-page-btn {
        width: 36px;
        height: 36px;
        border: 1px solid #dce8ef;
        border-radius: 7px;
        background: #fff;
        color: #0b4f80;
        font: inherit;
        font-size: .875rem;
        font-weight: 800;
        cursor: pointer;
    }
    .appointment-page-btn:hover:not(:disabled) {
        border-color: #0f7cc2;
        color: #0066cc;
    }
    .appointment-page-btn.active {
        border-color: #0066cc;
        background: #0066cc;
        color: #fff;
        box-shadow: 0 10px 20px rgba(0, 102, 204, .18);
    }
    .appointment-page-btn:disabled {
        opacity: .45;
        cursor: not-allowed;
    }
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }
    .empty-state svg {
        width: 100px;
        height: 100px;
        margin: 0 auto 20px;
        opacity: 0.3;
    }
    .empty-state h3 {
        color: #666;
        margin-bottom: 10px;
    }
    .add-appointment-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        color: #fff;
        padding: 12px 24px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 20px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
    }
    .add-appointment-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
    }
    @media (max-width: 768px) {
        .appointment-stats,
        .clinical-stat-grid,
        .appointment-report-summary,
        .appointment-tools {
            grid-template-columns: 1fr;
        }
        .appointment-pagination-footer {
            align-items: flex-start;
            flex-direction: column;
        }
        .detail-summary-strip {
            grid-template-columns: 1fr;
        }
        .appointment-detail-grid {
            grid-template-columns: 1fr;
        }
        .appointments-table-wrapper {
            padding: 15px;
        }
        .appointments-scroll {
            max-height: 560px;
        }
        .appointments-table {
            font-size: 0.9rem;
            min-width: 860px;
        }
        .appointments-table th,
        .appointments-table td {
            padding: 10px 8px;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            width: 100%;
            text-align: center;
        }
    }
';

include __DIR__ . '/includes/header.php';
?>

<div class="appointments-container<?php echo $isClinicalAppointmentsView ? ' clinical-appointments-page' : ''; ?>">
    <?php if (isset($_SESSION['success'])): ?>
        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #28a745;">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div style="background: #fee; color: #d90429; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #d90429;">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($isClinicalAppointmentsView): ?>
        <section class="clinical-page-title">
            <h1>Appointments</h1>
            <p>View and manage doctor consultation appointments assigned to you.</p>
        </section>
        <section class="clinical-stat-grid" aria-label="Appointment summary">
            <div class="clinical-stat-card">
                <span class="clinical-stat-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                </span>
                <div>
                    <span>Total Appointments</span>
                    <strong><?php echo (int) $statusCounts['all']; ?></strong>
                    <small>Assigned consultations</small>
                </div>
            </div>
            <div class="clinical-stat-card">
                <span class="clinical-stat-icon pending" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </span>
                <div>
                    <span>Pending</span>
                    <strong><?php echo (int) $statusCounts['pending']; ?></strong>
                    <small>Awaiting confirmation</small>
                </div>
            </div>
            <div class="clinical-stat-card">
                <span class="clinical-stat-icon done" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></svg>
                </span>
                <div>
                    <span>Completed</span>
                    <strong><?php echo (int) $statusCounts['completed']; ?></strong>
                    <small>Successfully completed</small>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($userRole === 'admin'): ?>
        <section class="appointment-report-card" aria-label="Generate reports">
            <div class="appointment-report-head">
                <a href="<?php echo htmlspecialchars($appointmentPage . '?generate_report=1&report_type=appointments&date_from=' . urlencode($reportDateFrom) . '&date_to=' . urlencode($reportDateTo) . '&report_status=' . urlencode($reportStatus), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">Generate Report</a>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$isClinicalAppointmentsView): ?>
        <section class="appointments-filter-card" aria-label="Appointment filters">
            <div class="appointment-tools patient-filters" id="patientAppointmentFilterBar">
                <?php if ($userRole !== 'patient'): ?>
                    <div class="tool-field">
                        <label for="appointmentSearch">Search appointments</label>
                        <input type="search" id="appointmentSearch" placeholder="Search patient, doctor, queue number, notes, or date">
                    </div>
                <?php endif; ?>
                <div class="tool-field">
                    <label for="appointmentStatusFilter">Status</label>
                    <select id="appointmentStatusFilter">
                        <option value="">All statuses</option>
                        <option value="pending"<?php echo $initialStatusFilter === 'pending' ? ' selected' : ''; ?>>Pending (<?php echo (int) $statusCounts['pending']; ?>)</option>
                        <option value="confirmed"<?php echo $initialStatusFilter === 'confirmed' ? ' selected' : ''; ?>>Confirmed (<?php echo (int) $statusCounts['confirmed']; ?>)</option>
                        <option value="completed"<?php echo $initialStatusFilter === 'completed' ? ' selected' : ''; ?>>Completed (<?php echo (int) $statusCounts['completed']; ?>)</option>
                        <option value="cancelled"<?php echo $initialStatusFilter === 'cancelled' ? ' selected' : ''; ?>>Cancelled (<?php echo (int) $statusCounts['cancelled']; ?>)</option>
                    </select>
                </div>
                <div class="tool-field">
                    <label for="appointmentDateRangeFilter">Date Filter</label>
                    <select id="appointmentDateRangeFilter">
                        <option value="">All dates</option>
                        <option value="today">Today</option>
                        <option value="yesterday">Yesterday</option>
                        <option value="past_3_days">Past 3 Days</option>
                        <option value="past_7_days">Past 7 Days</option>
                        <option value="past_2_weeks">Past 2 Weeks</option>
                        <option value="past_30_days">Past 30 Days</option>
                        <option value="custom">Custom Date Range</option>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" id="appointmentFilterApply">Apply Filter</button>
                <button type="button" class="filter-reset-btn" id="appointmentFilterReset">Reset</button>
                <div class="patient-custom-range" id="patientCustomDateRange" hidden>
                    <div class="tool-field">
                        <label for="appointmentDateFrom">Start Date</label>
                        <input type="date" id="appointmentDateFrom">
                    </div>
                    <div class="tool-field">
                        <label for="appointmentDateTo">End Date</label>
                        <input type="date" id="appointmentDateTo">
                    </div>
                </div>
                <p class="patient-date-error" id="patientDateError" hidden>End Date cannot be earlier than Start Date.</p>
            </div>
        </section>
    <?php endif; ?>

    <div class="appointments-table-wrapper">
        <?php if ($isClinicalAppointmentsView): ?>
            <div class="clinical-panel-head">
                <h2>All Appointments</h2>
                <p>Only doctor consultation appointments assigned to your account are shown here.</p>
            </div>
        <?php endif; ?>
        <?php if (empty($appointments)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <h3><?php echo $userRole === 'patient' ? 'No appointments found for the selected period.' : 'No Appointments Found'; ?></h3>
                <p><?php echo $isClinicalAppointmentsView ? 'No doctor consultation appointments are assigned to you.' : ($userRole === 'patient' ? 'Try another date range.' : 'There are no appointments in the system.'); ?></p>
                <?php if ($userRole === 'patient'): ?>
                    <a href="book_appointment.php?start=1" class="add-appointment-btn" style="margin-top: 20px;">Book Your First Appointment</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($isClinicalAppointmentsView): ?>
                <div class="appointment-tools" aria-label="Appointment filters">
                    <div class="tool-field">
                        <label for="appointmentSearch">Search appointments</label>
                        <input type="search" id="appointmentSearch" placeholder="Search patient, doctor, queue number, notes, or date">
                    </div>
                    <div class="tool-field">
                        <label for="appointmentStatusFilter">Status</label>
                        <select id="appointmentStatusFilter">
                            <option value="">All statuses</option>
                            <option value="pending">Pending (<?php echo (int) $statusCounts['pending']; ?>)</option>
                            <option value="confirmed">Confirmed (<?php echo (int) $statusCounts['confirmed']; ?>)</option>
                            <option value="completed">Completed (<?php echo (int) $statusCounts['completed']; ?>)</option>
                            <option value="cancelled">Cancelled (<?php echo (int) $statusCounts['cancelled']; ?>)</option>
                        </select>
                    </div>
                    <div class="tool-field">
                        <label for="appointmentDateFilter">Date</label>
                        <input type="date" id="appointmentDateFilter">
                    </div>
                    <button type="button" class="filter-reset-btn" id="appointmentFilterReset">Reset</button>
                </div>
            <?php endif; ?>
            <p class="appointment-result-count" id="appointmentResultCount"><?php echo count($appointments); ?> appointment<?php echo count($appointments) === 1 ? '' : 's'; ?> shown</p>
            <div class="appointments-scroll" aria-label="Scrollable appointments list">
                <table class="appointments-table">
                    <thead>
                        <tr>
                            <?php if ($userRole !== 'patient'): ?>
                                <th>Patient</th>
                            <?php endif; ?>
                            <?php if ($isClinicalAppointmentsView): ?>
                                <th>Service</th>
                                <th>Schedule</th>
                            <?php elseif ($userRole === 'admin'): ?>
                                <th>Doctor</th>
                                <th>Schedule</th>
                            <?php else: ?>
                                <th>Schedule</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <?php if (!$isClinicalAppointmentsView): ?>
                                <th>Services</th>
                            <?php endif; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                        $appDate = new DateTime($appointment['appointment_date']);
                        $formattedDate = $appDate->format('F d, Y');
                        $appTime = new DateTime($appointment['appointment_time']);
                        $formattedTime = $appTime->format('g:i A');
                        $patientDisplay = mergeAppointmentPatientProfile($appointment, $patientsById);
                        $notesFull = trim((string) ($appointment['notes'] ?? ''));
                        $servicesText = 'Not listed';
                        if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notesFull, $matches)) {
                            $servicesText = trim($matches[1]) !== '' ? trim($matches[1]) : 'Not listed';
                        }
                        $totalAmount = isset($appointment['total_display_price']) && $appointment['total_display_price'] !== null
                            ? 'PHP ' . number_format((float) $appointment['total_display_price'], 2)
                            : 'N/A';
                        $bookingType = isset($appointment['booking_type']) && $appointment['booking_type'] !== null && $appointment['booking_type'] !== ''
                            ? ucfirst((string) $appointment['booking_type'])
                            : 'N/A';
                        if (($appointment['booking_type'] ?? '') === 'consultation') {
                            $bookingType = 'Doctor consultation';
                            $servicesText = 'Doctor consultation';
                        } elseif (($appointment['booking_type'] ?? '') === 'package') {
                            $bookingType = 'Laboratory package';
                        } elseif (($appointment['booking_type'] ?? '') === 'individual') {
                            $bookingType = 'Laboratory tests';
                        } elseif (($appointment['booking_type'] ?? '') === 'ultrasound') {
                            $bookingType = 'Ultra sound';
                            $servicesText = 'Ultra sound';
                        }
                        $statusValue = strtolower((string) ($appointment['status'] ?? 'pending'));
                        $statusLabel = $statusValue === 'cancelled' ? 'Cancelled' : ucfirst($statusValue);
                        $cancellationReason = trim((string) ($appointment['cancellation_reason'] ?? ''));
                        $queueNumber = trim((string) ($appointment['queue_number'] ?? ''));
                        $queueNumberDisplay = $queueNumber !== '' ? $queueNumber : 'Not generated';
                        $searchText = trim(implode(' ', [
                            $queueNumberDisplay,
                            $patientDisplay['patient_name'] ?? '',
                            $appointment['doctor_name'] ?? '',
                            $formattedDate,
                            $formattedTime,
                            $statusValue,
                            $notesFull,
                            $cancellationReason,
                            $servicesText,
                            $bookingType,
                        ]));
                        $detailPayload = [
                            'queueNumber' => $queueNumberDisplay,
                            'patient' => $userRole === 'patient' ? ($currentUser['full_name'] ?? 'Patient') : ($patientDisplay['patient_name'] ?? 'N/A'),
                            'doctor' => $appointment['doctor_name'] ?? 'Not Assigned',
                            'date' => $formattedDate,
                            'time' => $formattedTime,
                            'rawDate' => (string) ($appointment['appointment_date'] ?? ''),
                            'status' => $statusLabel,
                            'statusKey' => $statusValue,
                            'bookingType' => $bookingType,
                            'bookingTypeKey' => strtolower((string) ($appointment['booking_type'] ?? '')),
                            'totalAmount' => $totalAmount,
                            'services' => $servicesText,
                            'notes' => $notesFull !== '' ? $notesFull : 'None',
                            'cancellationReason' => $cancellationReason !== '' ? $cancellationReason : 'None',
                        ];
                        ?>
                        <tr class="appointment-row" tabindex="0"
                            data-appointment='<?php echo htmlspecialchars(json_encode($detailPayload), ENT_QUOTES, 'UTF-8'); ?>'
                            data-appointment-id="<?php echo (int) ($appointment['id'] ?? 0); ?>"
                            data-status="<?php echo htmlspecialchars($statusValue); ?>"
                            data-date="<?php echo htmlspecialchars((string) ($appointment['appointment_date'] ?? '')); ?>"
                            data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                            <?php if ($userRole !== 'patient'): ?>
                                <td>
                                    <div class="patient-cell">
                                        <?php echo renderPatientAvatar($patientDisplay, [
                                            'size' => 'sm',
                                        ]); ?>
                                        <div>
                                            <span class="patient-name">
                                                <?php echo htmlspecialchars($patientDisplay['patient_name'] ?? 'N/A'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <?php if ($isClinicalAppointmentsView): ?>
                                <td>
                                    <strong>Doctor Consultation</strong><br>
                                    <span style="color:#60758a;font-size:.88rem;"><?php echo htmlspecialchars($appointment['doctor_name'] ?? 'Assigned doctor'); ?></span>
                                </td>
                                <td class="appointment-schedule-cell">
                                    <strong><?php echo htmlspecialchars($formattedDate); ?></strong>
                                    <span><?php echo htmlspecialchars($formattedTime); ?></span>
                                </td>
                            <?php elseif ($userRole === 'admin'): ?>
                                <td class="appointments-doctor"><?php echo htmlspecialchars($appointment['doctor_name'] ?? 'Not Assigned'); ?></td>
                                <td class="appointment-schedule-cell appointments-date">
                                    <strong><?php echo htmlspecialchars($formattedDate); ?></strong>
                                    <span><?php echo htmlspecialchars($formattedTime); ?></span>
                                </td>
                            <?php else: ?>
                                <td class="appointment-schedule-cell appointments-date">
                                    <strong><?php echo htmlspecialchars($formattedDate); ?></strong>
                                    <span><?php echo htmlspecialchars($formattedTime); ?></span>
                                </td>
                            <?php endif; ?>
                            <td>
                                <span class="status-badge <?php echo htmlspecialchars($statusValue); ?>">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                            </td>
                            <?php if (!$isClinicalAppointmentsView): ?>
                                <td class="appointments-services"><?php echo htmlspecialchars($servicesText !== 'Not listed' ? $servicesText : ($notesFull !== '' ? substr($notesFull, 0, 50) : 'Not listed')); ?><?php echo strlen($servicesText !== 'Not listed' ? $servicesText : $notesFull) > 50 ? '...' : ''; ?></td>
                            <?php endif; ?>
                            <td>
                                <div class="action-buttons <?php echo (($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation') || ($isClinicalAppointmentsView && ($appointment['booking_type'] ?? '') === 'consultation')) && $appointment['status'] === 'pending' ? 'pending-actions' : ''; ?>">
                                    <button type="button" class="btn btn-details" data-open-details>Details</button>
                                    <?php if (($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation') || ($isClinicalAppointmentsView && ($appointment['booking_type'] ?? '') === 'consultation' && $appointment['status'] === 'pending')): ?>
                                        <?php if ($appointment['status'] === 'pending'): ?>
                                            <form method="POST" action="update_appointment_status.php" class="confirm-action" style="display:inline;">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="confirmed">
                                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($appointmentPage, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-confirm" data-confirm-message="Are you sure you want to confirm this appointment?">Confirm</button>
                                            </form>
                                            <form method="POST" action="update_appointment_status.php" class="decline-action" style="display:inline;">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="cancelled">
                                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($appointmentPage, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-cancel" data-confirm-message="Are you sure you want to cancel this appointment?">Cancel</button>
                                            </form>
                                        <?php elseif ($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation' && $appointment['status'] === 'confirmed'): ?>
                                            <form method="POST" action="update_appointment_status.php" style="display:inline;">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($appointmentPage, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-complete" data-confirm-message="Are you sure you want to mark this appointment as completed?">Complete</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($userRole === 'patient' && $appointment['status'] === 'pending'): ?>
                                        <form method="POST" action="update_appointment_status.php" style="display:inline;">
                                            <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                            <input type="hidden" name="status" value="cancelled">
                                            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($appointmentPage, ENT_QUOTES, 'UTF-8'); ?>">
                                            <button type="submit" class="btn btn-cancel" data-confirm-message="Are you sure you want to cancel this appointment?">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="filter-empty" id="appointmentFilterEmpty">
                <strong>No appointments match your filters.</strong>
                <span>Try another search, status, or date.</span>
            </div>
            <div class="appointment-pagination-footer">
                <span id="appointmentPageInfo">Showing appointments</span>
                <div class="appointment-pagination" id="appointmentPagination" aria-label="Appointment pages"></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($showAppointmentReport): ?>
    <div class="appointment-modal is-open" id="appointmentReportModal" aria-hidden="false">
        <div class="appointment-modal-card report-modal-card" role="dialog" aria-modal="true" aria-labelledby="appointmentReportTitle">
            <div class="appointment-modal-head">
                <div>
                    <h3 id="appointmentReportTitle">Generate Report</h3>
                    <p><?php echo htmlspecialchars((string) $generatedReport['period']); ?></p>
                </div>
                <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
            </div>
            <div class="appointment-modal-body">
                <div class="appointment-generated-report" id="appointmentGeneratedReport">
                    <div class="report-print-header">
                        <div class="report-print-line"></div>
                        <h1>Globalife Medical Laboratory &amp; Polyclinic</h1>
                        <h2><?php echo htmlspecialchars((string) $generatedReport['title']); ?></h2>
                        <p>Generated: <?php echo htmlspecialchars((string) ($generatedReport['generated_at'] ?? date('Y-m-d H:i'))); ?></p>
                        <div class="report-print-line"></div>
                    </div>
                    <dl class="report-print-summary">
                        <div><dt>Period:</dt><dd><?php echo htmlspecialchars((string) $generatedReport['period']); ?></dd></div>
                        <?php foreach ($generatedReport['summary'] as $label => $value): ?>
                            <div><dt><?php echo htmlspecialchars((string) $label); ?>:</dt><dd><?php echo htmlspecialchars((string) $value); ?></dd></div>
                        <?php endforeach; ?>
                        <div><dt>Generated:</dt><dd><?php echo htmlspecialchars((string) ($generatedReport['generated_at'] ?? date('Y-m-d H:i'))); ?></dd></div>
                    </dl>
                    <h3 class="report-print-rows-title">Report rows</h3>
                    <div class="appointment-generated-head">
                        <h3><?php echo htmlspecialchars((string) $generatedReport['title']); ?></h3>
                        <p><?php echo htmlspecialchars((string) $generatedReport['period']); ?></p>
                    </div>
                    <table class="appointment-report-table">
                        <thead>
                            <tr>
                                <?php foreach ($generatedReport['headers'] as $header): ?>
                                    <th><?php echo htmlspecialchars((string) $header); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($generatedReport['rows'])): ?>
                                <tr>
                                    <td colspan="<?php echo count($generatedReport['headers']); ?>">No records match the selected filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($generatedReport['rows'] as $reportRow): ?>
                                    <tr>
                                        <?php foreach ($reportRow as $cell): ?>
                                            <td><?php echo htmlspecialchars((string) $cell); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="appointment-report-summary">
                        <?php foreach ($generatedReport['summary'] as $label => $value): ?>
                            <div><span><?php echo htmlspecialchars((string) $label); ?></span><strong><?php echo htmlspecialchars((string) $value); ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="appointment-report-actions">
                        <button type="button" class="btn btn-primary" id="printAppointmentReport">Print Report</button>
                        <a class="btn btn-secondary" href="admin_report_export.php?report=<?php echo urlencode($reportType); ?>&amp;format=pdf&amp;date_from=<?php echo urlencode($reportDateFrom); ?>&amp;date_to=<?php echo urlencode($reportDateTo); ?>&amp;status=<?php echo urlencode($reportStatus); ?>">Save as PDF</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="appointment-modal" id="appointmentDetailsModal" aria-hidden="true">
    <div class="appointment-modal-card" role="dialog" aria-modal="true" aria-labelledby="appointmentDetailsTitle">
        <div class="appointment-modal-head">
            <div>
                <h3 id="appointmentDetailsTitle">Appointment details</h3>
                <p id="appointmentDetailsSub">Review schedule and notes</p>
            </div>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <div class="appointment-modal-body">
            <div class="detail-summary-strip" id="appointmentDetailSummary"></div>
            <dl class="appointment-detail-grid" id="appointmentDetailsGrid"></dl>
            <div class="detail-notes-box" id="appointmentDetailNotes"></div>
        </div>
        <div class="appointment-modal-actions">
            <button type="button" class="btn btn-light" data-close-modal>Close</button>
        </div>
    </div>
</div>

<div class="appointment-modal" id="statusConfirmModal" aria-hidden="true">
    <div class="appointment-modal-card" role="dialog" aria-modal="true" aria-labelledby="statusConfirmTitle">
        <div class="appointment-modal-head">
            <div>
                <h3 id="statusConfirmTitle">Confirm action</h3>
                <p id="statusConfirmText">Please confirm this appointment update.</p>
            </div>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <div class="cancel-reason-field" id="statusCancelReasonWrap">
            <label for="statusCancelReason">Cancellation reason</label>
            <textarea id="statusCancelReason" maxlength="500" placeholder="Add a short reason for cancelling this appointment"></textarea>
            <div class="cancel-reason-error" id="statusCancelReasonError">Please add a reason before continuing.</div>
        </div>
        <div class="appointment-modal-actions" style="padding-top:20px;">
            <button type="button" class="btn btn-light" data-close-modal>Back</button>
            <button type="button" class="btn btn-confirm" id="statusConfirmYes">Yes, continue</button>
        </div>
    </div>
</div>

<div class="appointment-modal" id="statusSuccessModal" aria-hidden="true">
    <div class="appointment-modal-card appointment-success-card" role="dialog" aria-modal="true" aria-labelledby="statusSuccessTitle">
        <div class="appointment-success-body">
            <span class="appointment-success-icon" aria-hidden="true">✓</span>
            <h3 id="statusSuccessTitle">Appointment updated</h3>
            <p id="statusSuccessText">Appointment status updated successfully.</p>
            <button type="button" class="btn btn-confirm" id="statusSuccessOk">OK</button>
        </div>
    </div>
</div>

<div class="appointment-modal" id="filterSuccessModal" aria-hidden="true">
    <div class="appointment-modal-card appointment-success-card" role="dialog" aria-modal="true" aria-labelledby="filterSuccessTitle">
        <div class="appointment-success-body">
            <span class="appointment-success-icon" aria-hidden="true">✓</span>
            <h3 id="filterSuccessTitle">Filters Applied</h3>
            <p id="filterSuccessText">You have successfully applied the filters.</p>
            <button type="button" class="btn btn-confirm" id="filterSuccessOk">OK</button>
        </div>
    </div>
</div>

<script>
(function() {
    var detailModal = document.getElementById('appointmentDetailsModal');
    var detailGrid = document.getElementById('appointmentDetailsGrid');
    var detailSummary = document.getElementById('appointmentDetailSummary');
    var detailNotes = document.getElementById('appointmentDetailNotes');
    var detailSub = document.getElementById('appointmentDetailsSub');
    var confirmModal = document.getElementById('statusConfirmModal');
    var confirmText = document.getElementById('statusConfirmText');
    var confirmYes = document.getElementById('statusConfirmYes');
    var successModal = document.getElementById('statusSuccessModal');
    var successTitle = document.getElementById('statusSuccessTitle');
    var successText = document.getElementById('statusSuccessText');
    var successOk = document.getElementById('statusSuccessOk');
    var filterSuccessModal = document.getElementById('filterSuccessModal');
    var filterSuccessOk = document.getElementById('filterSuccessOk');
    var cancelReasonWrap = document.getElementById('statusCancelReasonWrap');
    var cancelReasonInput = document.getElementById('statusCancelReason');
    var cancelReasonError = document.getElementById('statusCancelReasonError');
    var pendingForm = null;
    var searchInput = document.getElementById('appointmentSearch');
    var statusFilter = document.getElementById('appointmentStatusFilter');
    var dateFilter = document.getElementById('appointmentDateFilter');
    var dateRangeFilter = document.getElementById('appointmentDateRangeFilter');
    var dateFromInput = document.getElementById('appointmentDateFrom');
    var dateToInput = document.getElementById('appointmentDateTo');
    var customDateRange = document.getElementById('patientCustomDateRange');
    var dateError = document.getElementById('patientDateError');
    var filterApply = document.getElementById('appointmentFilterApply');
    var resetFilter = document.getElementById('appointmentFilterReset');
    var resultCount = document.getElementById('appointmentResultCount');
    var filterEmpty = document.getElementById('appointmentFilterEmpty');
    var printReport = document.getElementById('printAppointmentReport');
    var reportResultModal = document.getElementById('appointmentReportModal');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.appointment-row'));
    var pageInfo = document.getElementById('appointmentPageInfo');
    var pagination = document.getElementById('appointmentPagination');
    var filteredRows = rows.slice();
    var currentPage = 1;
    var pageSize = 5;
    var customDateRangeApplied = false;

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    function closeAll() {
        closeModal(detailModal);
        closeModal(confirmModal);
        closeModal(successModal);
        closeModal(filterSuccessModal);
        closeModal(reportResultModal);
        pendingForm = null;
        if (cancelReasonInput) cancelReasonInput.value = '';
        if (cancelReasonWrap) cancelReasonWrap.classList.remove('is-open');
        if (cancelReasonError) cancelReasonError.classList.remove('is-open');
    }
    function text(value) {
        return value === undefined || value === null || value === '' ? 'N/A' : String(value);
    }
    function escapeHtml(value) {
        return text(value).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }
    function localDateString(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }
    function dateDaysAgo(days) {
        var date = new Date();
        date.setHours(0, 0, 0, 0);
        date.setDate(date.getDate() - days);
        return localDateString(date);
    }
    function selectedDateRange() {
        if (!dateRangeFilter || !dateRangeFilter.value) return null;
        var selected = dateRangeFilter.value;
        var today = localDateString(new Date());
        if (selected === 'today') return { from: today, to: today };
        if (selected === 'yesterday') {
            var yesterday = dateDaysAgo(1);
            return { from: yesterday, to: yesterday };
        }
        if (selected === 'past_3_days') return { from: dateDaysAgo(2), to: today };
        if (selected === 'past_7_days') return { from: dateDaysAgo(6), to: today };
        if (selected === 'past_2_weeks') return { from: dateDaysAgo(13), to: today };
        if (selected === 'past_30_days') return { from: dateDaysAgo(29), to: today };
        if (selected === 'custom' && customDateRangeApplied && dateFromInput && dateToInput) {
            return { from: dateFromInput.value, to: dateToInput.value };
        }
        return null;
    }
    function setDateError(message) {
        if (!dateError) return;
        dateError.textContent = message || '';
        dateError.hidden = !message;
    }
    function toggleCustomDateRange() {
        if (!customDateRange || !dateRangeFilter) return;
        customDateRange.hidden = dateRangeFilter.value !== 'custom';
        if (dateRangeFilter.value !== 'custom') setDateError('');
    }
    function showStatusSuccess(status, message) {
        closeModal(detailModal);
        closeModal(confirmModal);
        if (successTitle) {
            successTitle.textContent = status === 'cancelled'
                ? 'Appointment cancelled'
                : (status === 'completed' ? 'Appointment completed' : 'Appointment confirmed');
        }
        if (successText) {
            successText.textContent = message || (status === 'cancelled'
                ? 'Appointment cancelled successfully.'
                : (status === 'completed' ? 'Appointment completed successfully.' : 'Appointment confirmed successfully.'));
        }
        openModal(successModal);
    }
    function showDetails(row) {
        if (!row || !detailGrid) return;
        var data = {};
        try { data = JSON.parse(row.getAttribute('data-appointment') || '{}'); } catch (e) { data = {}; }
        if (detailSummary) {
            detailSummary.innerHTML = [
                ['Queue number', data.queueNumber],
                ['Status', data.status],
                ['Total', data.totalAmount]
            ].map(function(item) {
                return '<div class="detail-pill"><span>' + item[0] + '</span><strong>' + escapeHtml(item[1]) + '</strong></div>';
            }).join('');
        }
        var bookingType = String(data.bookingTypeKey || '').toLowerCase();
        var isKnownBookingType = ['consultation', 'ultrasound', 'package', 'individual'].indexOf(bookingType) !== -1;
        var items = [['Patient', data.patient]];
        if (!isKnownBookingType || bookingType === 'consultation') {
            items.push(['Doctor', data.doctor]);
        }
        items.push(['Date', data.date]);
        if (!isKnownBookingType || bookingType !== 'consultation') {
            items.push(['Booking type', data.bookingType]);
        }
        if (!isKnownBookingType || bookingType === 'package' || bookingType === 'individual') {
            items.push(['Services', data.services]);
        }
        detailGrid.innerHTML = items.map(function(item) {
            return '<dt>' + item[0] + '</dt><dd>' + escapeHtml(item[1]) + '</dd>';
        }).join('');
        if (detailNotes) {
            var notesHtml = '<strong>Notes</strong><span>' + escapeHtml(data.notes) + '</span>';
            if (data.statusKey === 'cancelled') {
                notesHtml += '<strong style="margin-top:12px;">Cancellation reason</strong><span>' + escapeHtml(data.cancellationReason) + '</span>';
            }
            detailNotes.innerHTML = notesHtml;
        }
        if (detailSub) detailSub.textContent = text(data.queueNumber) + ' • ' + text(data.status);
        openModal(detailModal);
    }

    function renderAppointmentPage() {
        var totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        currentPage = Math.min(currentPage, totalPages);
        var start = (currentPage - 1) * pageSize;
        var end = start + pageSize;

        rows.forEach(function(row) {
            row.classList.add('page-hidden');
        });
        filteredRows.slice(start, end).forEach(function(row) {
            row.classList.remove('page-hidden');
        });

        if (pageInfo) {
            pageInfo.textContent = filteredRows.length === 0
                ? 'No appointments found for the selected period.'
                : 'Showing ' + (start + 1) + ' to ' + Math.min(end, filteredRows.length) + ' of ' + filteredRows.length + ' appointments.';
        }

        if (!pagination) return;
        pagination.innerHTML = '';

        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'appointment-page-btn';
        prev.innerHTML = '&lsaquo;';
        prev.disabled = currentPage <= 1;
        prev.addEventListener('click', function() {
            currentPage -= 1;
            renderAppointmentPage();
        });
        pagination.appendChild(prev);

        for (var page = 1; page <= totalPages; page++) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'appointment-page-btn' + (page === currentPage ? ' active' : '');
            button.textContent = page;
            if (page === currentPage) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', (function(pageNumber) {
                return function() {
                    currentPage = pageNumber;
                    renderAppointmentPage();
                };
            })(page));
            pagination.appendChild(button);
        }

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'appointment-page-btn';
        next.innerHTML = '&rsaquo;';
        next.disabled = currentPage >= totalPages;
        next.addEventListener('click', function() {
            currentPage += 1;
            renderAppointmentPage();
        });
        pagination.appendChild(next);
    }

    function applyAppointmentFilters() {
        var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var status = statusFilter ? statusFilter.value : '';
        var date = dateFilter ? dateFilter.value : '';
        var dateRange = selectedDateRange();
        filteredRows = [];

        rows.forEach(function(row) {
            var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
            var rowStatus = row.getAttribute('data-status') || '';
            var rowDate = row.getAttribute('data-date') || '';
            var show = true;

            if (q && rowSearch.indexOf(q) === -1) show = false;
            if (status && rowStatus !== status) show = false;
            if (date && rowDate !== date) show = false;
            if (dateRange && (rowDate < dateRange.from || rowDate > dateRange.to)) show = false;

            row.classList.toggle('hidden', !show);
            if (show) filteredRows.push(row);
        });

        if (resultCount) {
            resultCount.textContent = filteredRows.length + ' appointment' + (filteredRows.length === 1 ? '' : 's') + ' found';
        }
        if (filterEmpty) {
            filterEmpty.style.display = filteredRows.length === 0 ? 'block' : 'none';
        }
        currentPage = 1;
        renderAppointmentPage();
    }

    [searchInput, statusFilter, dateFilter, dateRangeFilter].forEach(function(control) {
        if (control) control.addEventListener('input', applyAppointmentFilters);
        if (control && control.tagName === 'SELECT') {
            control.addEventListener('change', function() {
                if (control === statusFilter) {
                    syncStatusFilterToUrl();
                }
                if (control === dateRangeFilter) {
                    customDateRangeApplied = false;
                    toggleCustomDateRange();
                    if (dateRangeFilter.value !== 'custom') applyAppointmentFilters();
                } else {
                    applyAppointmentFilters();
                }
            });
        }
    });
    if (filterApply) {
        filterApply.addEventListener('click', function() {
            if (!dateRangeFilter || dateRangeFilter.value !== 'custom') {
                applyAppointmentFilters();
                openModal(filterSuccessModal);
                return;
            }
            if (!dateFromInput || !dateToInput || !dateFromInput.value || !dateToInput.value) {
                setDateError('Select a Start Date and End Date.');
                return;
            }
            if (dateToInput.value < dateFromInput.value) {
                setDateError('End Date cannot be earlier than Start Date.');
                return;
            }
            setDateError('');
            customDateRangeApplied = true;
            applyAppointmentFilters();
            openModal(filterSuccessModal);
        });
    }
    if (resetFilter) {
        resetFilter.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (dateFilter) dateFilter.value = '';
            if (dateRangeFilter) dateRangeFilter.value = '';
            if (dateFromInput) dateFromInput.value = '';
            if (dateToInput) dateToInput.value = '';
            customDateRangeApplied = false;
            toggleCustomDateRange();
            setDateError('');
            syncStatusFilterToUrl();
            applyAppointmentFilters();
        });
    }
    if (printReport) {
        printReport.addEventListener('click', function() {
            var report = document.getElementById('appointmentGeneratedReport');
            if (!report) return;
            var printWindow = window.open('', '_blank', 'width=980,height=720');
            if (!printWindow) {
                document.body.classList.add('printing-report');
                setTimeout(function() { window.print(); }, 50);
                return;
            }
            printWindow.document.open();
            printWindow.document.write('<!doctype html><html><head><title>Print Report</title><style>' +
                '@page{size:A4;margin:14mm 16mm}' +
                'html,body{margin:0;padding:0;background:#fff;color:#032642;font-family:Arial,sans-serif}' +
                '.appointment-generated-report{width:100%;margin:0;padding:0;background:#fff;color:#032642}' +
                '.report-print-header{display:block;padding:0 0 22px}' +
                '.report-print-line{display:block;height:2px;background:#0b8d96;margin:0 0 18px}' +
                '.report-print-header .report-print-line:last-child{height:1px;margin:22px 0 0}' +
                '.report-print-header h1{margin:0 0 8px;color:#032642;font-size:26px;line-height:1.12;font-weight:900}' +
                '.report-print-header h2{margin:0 0 8px;color:#032642;font-size:16px;line-height:1.15;font-weight:900}' +
                '.report-print-header p{margin:0;color:#032642;font-size:13px}' +
                '.appointment-generated-head{display:none}' +
                '.report-print-summary{display:block;margin:0 0 18px;padding:0;background:#fff}' +
                '.report-print-summary div{display:grid;grid-template-columns:210px minmax(0,1fr);margin:0 0 7px}' +
                '.report-print-summary dt{color:#032642;font-size:13px;font-weight:900}' +
                '.report-print-summary dd{margin:0;color:#032642;font-size:13px}' +
                '.report-print-rows-title{display:block;margin:0 0 12px;color:#032642;font-size:15px;font-weight:900}' +
                '.appointment-report-table{width:100%;border-collapse:collapse;margin:0 0 14px}' +
                '.appointment-report-table th,.appointment-report-table td{padding:7px 10px;border-bottom:1px solid #dfe8ee;color:#032642;font-size:11px;text-align:left}' +
                '.appointment-report-table th{font-weight:900;border-bottom:1px solid #0b8d96}' +
                '.appointment-report-summary,.appointment-report-actions{display:none}' +
                '</style></head><body>' + report.outerHTML + '</body></html>');
            printWindow.document.close();
            printWindow.focus();
            setTimeout(function() {
                printWindow.print();
                printWindow.close();
            }, 150);
        });
    }
    window.addEventListener('afterprint', function() {
        document.body.classList.remove('printing-report');
    });

    document.querySelectorAll('[data-open-details]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            showDetails(button.closest('[data-appointment]'));
        });
    });
    document.querySelectorAll('.appointment-row').forEach(function(row) {
        row.addEventListener('click', function(event) {
            if (event.target.closest('button, a, form')) return;
            showDetails(row);
        });
        row.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                showDetails(row);
            }
        });
    });

    function getInitialStatusFromUrl() {
        var allowed = ['pending', 'confirmed', 'completed', 'cancelled'];
        var initialStatus = new URLSearchParams(window.location.search).get('status');
        if (initialStatus && allowed.indexOf(initialStatus) !== -1) {
            return initialStatus;
        }
        return '';
    }

    function syncStatusFilterToUrl() {
        if (!statusFilter || !window.history.replaceState) return;
        var url = new URL(window.location.href);
        if (statusFilter.value) {
            url.searchParams.set('status', statusFilter.value);
        } else {
            url.searchParams.delete('status');
        }
        window.history.replaceState({}, '', url);
    }

    if (statusFilter) {
        statusFilter.value = getInitialStatusFromUrl();
    }
    toggleCustomDateRange();
    applyAppointmentFilters();
    var highlightedAppointmentId = new URLSearchParams(window.location.search).get('highlight');
    if (highlightedAppointmentId) {
        var highlightedRow = rows.find(function(row) {
            return row.getAttribute('data-appointment-id') === highlightedAppointmentId;
        });
        if (highlightedRow) {
            highlightedRow.scrollIntoView({ block: 'center', behavior: 'smooth' });
            highlightedRow.classList.add('is-highlighted');
            window.setTimeout(function() {
                showDetails(highlightedRow);
            }, 250);
        }
    }
    document.querySelectorAll('button[data-confirm-message]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            pendingForm = button.closest('form');
            if (confirmText) confirmText.textContent = button.getAttribute('data-confirm-message') || 'Confirm this action?';
            var statusInput = pendingForm ? pendingForm.querySelector('[name="status"]') : null;
            var needsReason = statusInput && statusInput.value === 'cancelled';
            if (cancelReasonWrap) cancelReasonWrap.classList.toggle('is-open', !!needsReason);
            if (cancelReasonInput) {
                cancelReasonInput.value = '';
                cancelReasonInput.required = !!needsReason;
            }
            if (cancelReasonError) cancelReasonError.classList.remove('is-open');
            openModal(confirmModal);
            if (needsReason && cancelReasonInput) {
                window.setTimeout(function() { cancelReasonInput.focus(); }, 80);
            }
        });
    });
    if (confirmYes) {
        confirmYes.addEventListener('click', async function() {
            var form = pendingForm;
            var statusInput = form ? form.querySelector('[name="status"]') : null;
            if (statusInput && statusInput.value === 'cancelled') {
                var reason = cancelReasonInput ? cancelReasonInput.value.trim() : '';
                if (!reason) {
                    if (cancelReasonError) cancelReasonError.classList.add('is-open');
                    if (cancelReasonInput) cancelReasonInput.focus();
                    return;
                }
                var reasonInput = form.querySelector('[name="cancellation_reason"]');
                if (!reasonInput) {
                    reasonInput = document.createElement('input');
                    reasonInput.type = 'hidden';
                    reasonInput.name = 'cancellation_reason';
                    form.appendChild(reasonInput);
                }
                reasonInput.value = reason;
            }
            if (!form) return;
            var oldText = confirmYes.textContent;
            confirmYes.disabled = true;
            confirmYes.textContent = 'Saving...';
            try {
                var response = await fetch(form.action || 'update_appointment_status.php', {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                var result = await response.json();
                if (result && result.ok) {
                    pendingForm = null;
                    showStatusSuccess(statusInput ? statusInput.value : '', result.message);
                } else if (cancelReasonError) {
                    cancelReasonError.textContent = (result && result.message) || 'Unable to update appointment.';
                    cancelReasonError.classList.add('is-open');
                }
            } catch (error) {
                form.submit();
            } finally {
                confirmYes.disabled = false;
                confirmYes.textContent = oldText;
            }
        });
    }
    if (successOk) {
        successOk.addEventListener('click', function() {
            window.location.href = <?php echo json_encode($appointmentPage, JSON_UNESCAPED_SLASHES); ?>;
        });
    }
    if (filterSuccessOk) {
        filterSuccessOk.addEventListener('click', function() {
            closeModal(filterSuccessModal);
        });
    }
    document.querySelectorAll('[data-close-modal]').forEach(function(button) {
        button.addEventListener('click', closeAll);
    });
    document.querySelectorAll('.appointment-modal').forEach(function(modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal && modal === successModal) {
            window.location.href = <?php echo json_encode($appointmentPage, JSON_UNESCAPED_SLASHES); ?>;
                return;
            }
            if (event.target === modal) closeAll();
        });
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeAll();
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>



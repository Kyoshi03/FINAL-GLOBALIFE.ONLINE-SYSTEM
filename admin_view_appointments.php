<?php
require_once __DIR__ . '/includes/session.php';
checkRole('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/appointment_booking.php';
require_once __DIR__ . '/includes/doctor_pdf_export.php';
require_once __DIR__ . '/includes/clinic_info.php';

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
$clinicInfo = clinic_info_get($conn);
$patientNameSql = dbUsersNameExpression('p');
$doctorNameSql = dbUsersNameExpression('d');
$headerDoctorNameSql = dbUsersNameExpression('u');
$userNameSql = dbUsersNameExpression();
$reportLogoPath = clinic_info_logo_web_path($clinicInfo);
$reportLogoFilePath = clinic_info_logo_file_path($clinicInfo);

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

function admin_appointment_valid_date(string $date): bool {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    return $dateObject instanceof DateTime && $dateObject->format('Y-m-d') === $date;
}

function admin_appointment_service_label(array $appointment): string {
    $notes = trim((string) ($appointment['notes'] ?? ''));
    $service = '';
    if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notes, $matches)) {
        $service = trim((string) ($matches[1] ?? ''));
    }
    $bookingType = strtolower((string) ($appointment['booking_type'] ?? ''));
    if ($bookingType === 'consultation') return 'Doctor consultation';
    if ($bookingType === 'ultrasound') return 'Ultra sound';
    if ($bookingType === 'package') return $service !== '' ? $service : 'Laboratory package';
    if ($bookingType === 'individual') return $service !== '' ? $service : 'Laboratory tests';
    return $service !== '' ? $service : 'Not listed';
}

function admin_appointment_doctor_label(array $appointment): string {
    if (strtolower((string) ($appointment['booking_type'] ?? '')) !== 'consultation') return '—';
    $doctor = trim((string) ($appointment['doctor_name'] ?? ''));
    return $doctor !== '' ? $doctor : '—';
}

function admin_appointment_filter_rows(array $appointments, string $from, string $to, string $search, string $status): array {
    $search = strtolower(trim($search));
    $filtered = [];
    foreach ($appointments as $appointment) {
        $date = (string) ($appointment['appointment_date'] ?? '');
        $appointmentStatus = strtolower((string) ($appointment['status'] ?? 'pending'));
        if (!admin_appointment_valid_date($from) || !admin_appointment_valid_date($to) || $date < $from || $date > $to) continue;
        if ($status !== '' && $appointmentStatus !== $status) continue;
        if ($search !== '') {
            $haystack = strtolower(implode(' ', [
                (string) ($appointment['patient_name'] ?? ''),
                admin_appointment_doctor_label($appointment),
                admin_appointment_service_label($appointment),
            ]));
            if (strpos($haystack, $search) === false) continue;
        }
        $filtered[] = $appointment;
    }
    return $filtered;
}

$appointmentToday = date('Y-m-d');
$appointmentDateRange = strtolower(trim((string) ($_GET['date_range'] ?? 'today')));
$allowedAppointmentRanges = ['all', 'today', 'yesterday', 'past_3_days', 'past_7_days', 'past_2_weeks', 'past_30_days', 'custom'];
if (!in_array($appointmentDateRange, $allowedAppointmentRanges, true)) $appointmentDateRange = 'today';
$appointmentDateFrom = $appointmentDateRange === 'all' ? '1900-01-01' : $appointmentToday;
$appointmentDateTo = $appointmentToday;
$appointmentRequestedFrom = trim((string) ($_GET['date_from'] ?? ''));
$appointmentRequestedTo = trim((string) ($_GET['date_to'] ?? ''));
$appointmentDateError = '';
switch ($appointmentDateRange) {
    case 'yesterday':
        $appointmentDateFrom = $appointmentDateTo = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'past_3_days':
        $appointmentDateFrom = date('Y-m-d', strtotime('-2 days'));
        break;
    case 'past_7_days':
        $appointmentDateFrom = date('Y-m-d', strtotime('-6 days'));
        break;
    case 'past_2_weeks':
        $appointmentDateFrom = date('Y-m-d', strtotime('-13 days'));
        break;
    case 'past_30_days':
        $appointmentDateFrom = date('Y-m-d', strtotime('-29 days'));
        break;
    case 'custom':
        $appointmentDateFrom = $appointmentRequestedFrom;
        $appointmentDateTo = $appointmentRequestedTo;
        if (!admin_appointment_valid_date($appointmentDateFrom) || !admin_appointment_valid_date($appointmentDateTo)) {
            $appointmentDateError = 'Please select both a valid Start Date and End Date.';
        } elseif ($appointmentDateTo < $appointmentDateFrom) {
            $appointmentDateError = 'End Date cannot be earlier than Start Date.';
        }
        break;
}
$appointmentSearch = trim((string) ($_GET['search'] ?? ''));
$filteredAppointmentRecords = $appointmentDateError === ''
    ? admin_appointment_filter_rows($appointments, $appointmentDateFrom, $appointmentDateTo, $appointmentSearch, $initialStatusFilter)
    : [];
$appointmentRangeLabels = [
    'all' => 'All Dates',
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'past_3_days' => 'Past 3 Days',
    'past_7_days' => 'Past 7 Days',
    'past_2_weeks' => 'Past 2 Weeks',
    'past_30_days' => 'Past 30 Days',
    'custom' => 'Custom Date Range',
];
    $appointmentFilterLabel = $appointmentRangeLabels[$appointmentDateRange] ?? 'Today';
$appointmentPeriodLabel = admin_appointment_valid_date($appointmentDateFrom) && admin_appointment_valid_date($appointmentDateTo)
    ? ($appointmentDateFrom === $appointmentDateTo ? date('F j, Y', strtotime($appointmentDateFrom)) : date('F j, Y', strtotime($appointmentDateFrom)) . ' - ' . date('F j, Y', strtotime($appointmentDateTo)))
    : 'Selected dates';

function admin_appointment_url(array $overrides = []): string {
    $params = array_merge([
        'date_range' => $_GET['date_range'] ?? 'today',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
    ], $overrides);
    $params = array_filter($params, static fn($value) => trim((string) $value) !== '');
    return 'admin_view_appointments.php?' . http_build_query($params);
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

function appointment_report_date_label(string $from, string $to): string {
    $fromTime = strtotime($from);
    $toTime = strtotime($to);
    if (!$fromTime || !$toTime) {
        return 'Selected dates';
    }
    if (date('Y-m-d', $fromTime) === date('Y-m-d', $toTime)) {
        return date('F j, Y', $fromTime);
    }
    return date('F j, Y', $fromTime) . ' - ' . date('F j, Y', $toTime);
}

$reportType = strtolower(trim((string) ($_GET['report_type'] ?? 'appointments')));
$allowedReportTypes = ['appointments', 'patients'];
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
$allowedReportStatusFilters = ['pending', 'confirmed', 'completed', 'cancelled'];
if (!in_array($reportStatus, $allowedReportStatusFilters, true)) {
    $reportStatus = '';
}
if (isset($_GET['date_range'])) {
    $reportDateFrom = $appointmentDateFrom;
    $reportDateTo = $appointmentDateTo;
}
if ($reportType === 'appointments') {
    if (isset($_GET['status']) && in_array($initialStatusFilter, $allowedStatusFilters, true)) {
        $reportStatus = $initialStatusFilter;
    }
}
$reportFilteredAppointmentRecords = $reportType === 'appointments'
    ? admin_appointment_filter_rows($appointments, $reportDateFrom, $reportDateTo, (string) ($_GET['search'] ?? ''), $reportStatus)
    : [];
$showAppointmentReport = $userRole === 'admin' && isset($_GET['generate_report']);
$generatedReport = [
    'title' => 'Appointment History Report',
    'period' => appointment_report_date_label($reportDateFrom, $reportDateTo),
    'headers' => ['Patient', 'Doctor', 'Schedule', 'Service', 'Status'],
    'rows' => [],
    'summary' => [],
];
$appointmentReportCounts = ['total' => 0, 'completed' => 0, 'pending' => 0, 'cancelled' => 0, 'confirmed' => 0];
if ($showAppointmentReport) {
    if ($reportType === 'patients') {
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
        foreach ($reportFilteredAppointmentRecords as $appointment) {
            $appointmentDate = (string) ($appointment['appointment_date'] ?? '');
            $appointmentStatus = strtolower((string) ($appointment['status'] ?? 'pending'));
            $generatedReport['rows'][] = [
                (string) ($appointment['patient_name'] ?? 'Patient'),
                admin_appointment_doctor_label($appointment),
                date('F j, Y', strtotime($appointmentDate)) . ' ' . date('g:i A', strtotime((string) ($appointment['appointment_time'] ?? ''))),
                admin_appointment_service_label($appointment),
                ucfirst($appointmentStatus === 'cancelled' ? 'cancelled' : $appointmentStatus),
            ];
            $appointmentReportCounts['total']++;
            if (isset($appointmentReportCounts[$appointmentStatus])) {
                $appointmentReportCounts[$appointmentStatus]++;
            }
        }
        $generatedReport['title'] = 'Appointment History Report';
        $generatedReport['headers'] = ['Patient', 'Doctor', 'Schedule', 'Service', 'Status'];
        $generatedReport['summary'] = [
            'Filter' => $appointmentFilterLabel,
            'Period' => $appointmentPeriodLabel,
            'Status' => $reportStatus !== '' ? ucfirst($reportStatus) : 'All Statuses',
            'Total Records' => count($reportFilteredAppointmentRecords),
        ];
    }
    $generatedReport['generated_at'] = date('F j, Y g:i A');
}

function admin_appointment_pdf_stream(array $rows, string $filterLabel, string $period, string $statusLabel, string $generatedLabel, int $totalRows, int $pageNumber, int $pageTotal, bool $hasLogo = false, string $clinicName = 'Globalife Medical Laboratory & Polyclinic', string $clinicLocation = ''): string {
    $ops = [];
    clinic_pdf_line($ops, 42, 560, 800, 560, 2.0);
    if ($hasLogo) {
        $ops[] = "q 44 0 0 44 46 505 cm /Im1 Do Q";
    }
    $headerX = $hasLogo ? 100 : 42;
    clinic_pdf_text($ops, $headerX, 540, 15, $clinicName, true);
    clinic_pdf_text($ops, $headerX, 521, 11, 'Appointment History Report', true);
    if ($clinicLocation !== '') {
        clinic_pdf_text($ops, $headerX, 507, 8, $clinicLocation);
    }
    clinic_pdf_text($ops, 42, 490, 9, 'Filter: ' . $filterLabel);
    clinic_pdf_text($ops, 205, 490, 9, 'Period: ' . $period);
    clinic_pdf_text($ops, 42, 475, 9, 'Status: ' . $statusLabel);
    clinic_pdf_text($ops, 205, 475, 9, 'Total Records: ' . $totalRows);
    clinic_pdf_text($ops, 595, 475, 9, 'Generated: ' . $generatedLabel);
    clinic_pdf_line($ops, 42, 462, 800, 462, 0.8);
    $columns = [['Patient', 145], ['Doctor', 135], ['Schedule', 155], ['Service', 205], ['Status', 110]];
    $left = 42; $top = 445; $rowHeight = 22; $tableWidth = array_sum(array_column($columns, 1));
    clinic_pdf_rect($ops, $left, $top - $rowHeight, $tableWidth, $rowHeight, true);
    $x = $left;
    foreach ($columns as [$label, $width]) { clinic_pdf_text($ops, $x + 5, $top - 15, 7.5, $label, true); $x += $width; }
    if (empty($rows)) {
        clinic_pdf_text($ops, $left + 5, $top - 42, 9, 'No appointment records found for the selected filters.');
    } else {
        $y = $top - $rowHeight;
        foreach ($rows as $row) {
            $y -= $rowHeight;
            clinic_pdf_line($ops, $left, $y, $left + $tableWidth, $y, 0.45);
            $date = (string) ($row['appointment_date'] ?? '');
            $time = (string) ($row['appointment_time'] ?? '');
            $values = [
                (string) ($row['patient_name'] ?? 'Patient'),
                admin_appointment_doctor_label($row),
                admin_appointment_valid_date($date) ? date('F j, Y', strtotime($date)) . ' ' . date('g:i A', strtotime($time)) : '--',
                admin_appointment_service_label($row),
                ucfirst((string) ($row['status'] ?? 'pending')),
            ];
            $x = $left;
            foreach ($columns as $index => [$label, $width]) {
                $value = clinic_pdf_clean((string) $values[$index]);
                $maxChars = max(10, (int) floor($width / 5.1));
                if (strlen($value) > $maxChars) $value = substr($value, 0, max(1, $maxChars - 3)) . '...';
                clinic_pdf_text($ops, $x + 5, $y + 7, 7.5, $value);
                $x += $width;
            }
        }
    }
    clinic_pdf_line($ops, 42, 34, 800, 34, 0.7);
    clinic_pdf_text($ops, 42, 20, 7.5, 'Globalife Clinic System');
    clinic_pdf_text($ops, 730, 20, 7.5, 'Page ' . $pageNumber . ' of ' . $pageTotal);
    return implode("\n", $ops);
}

function admin_appointment_pdf_build(array $streams, ?array $image = null): string {
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'];
    $pageEntries = [];
    $imageObjectId = $image ? count($objects) + (2 * count($streams)) + 1 : null;
    foreach ($streams as $stream) {
        $pageId = count($objects) + 1;
        $objects[] = '';
        $contentId = count($objects) + 1;
        $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        $pageEntries[] = [$pageId, $contentId];
    }
    $kids = [];
    foreach ($pageEntries as [$pageId, $contentId]) {
        $kids[] = $pageId . ' 0 R';
        $imageResource = $imageObjectId ? ' /XObject << /Im1 ' . $imageObjectId . ' 0 R >>' : '';
        $objects[$pageId - 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $imageResource . ' >> /Contents ' . $contentId . ' 0 R >>';
    }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageEntries) . ' >>';
    if ($image) {
        $objects[] = "<< /Type /XObject /Subtype /Image /Width " . (int) $image['width']
            . " /Height " . (int) $image['height']
            . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter " . ($image['filter'] ?? '/FlateDecode') . " /Length "
            . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
    }
    $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $index => $object) { $offsets[] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($index = 1; $index <= count($objects); $index++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

function admin_appointment_output_pdf(array $rows, string $filterLabel, string $period, string $statusLabel, string $logoFilePath, string $clinicName, string $clinicLocation): void {
    $chunks = array_chunk($rows, 17);
    if (empty($chunks)) $chunks = [[]];
    $generated = date('F j, Y g:i A');
    $logo = clinic_pdf_image($logoFilePath);
    $streams = [];
    foreach ($chunks as $index => $chunk) $streams[] = admin_appointment_pdf_stream($chunk, $filterLabel, $period, $statusLabel, $generated, count($rows), $index + 1, count($chunks), $logo !== null, $clinicName, $clinicLocation);
    $pdf = admin_appointment_pdf_build($streams, $logo);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="appointment_history_' . date('Ymd_His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
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

if ($userRole === 'admin' && strtolower((string) ($_GET['download'] ?? '')) === 'pdf' && $reportType === 'appointments') {
    admin_appointment_output_pdf(
        $reportFilteredAppointmentRecords,
        $appointmentFilterLabel,
        $appointmentPeriodLabel,
        $reportStatus !== '' ? ucfirst($reportStatus) : 'All Statuses',
        $reportLogoFilePath,
        (string) $clinicInfo['clinic_name'],
        (string) $clinicInfo['clinic_location']
    );
}

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
        grid-template-columns:180px 180px auto;
    }
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
    .admin-history-filters {
        grid-template-columns: minmax(170px, .85fr) minmax(220px, 1.25fr) minmax(150px, .75fr) auto;
        align-items: end;
    }
    .appointment-filter-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }
    .appointment-custom-range {
        display: grid;
        grid-column: 1 / -1;
        grid-template-columns: repeat(2, minmax(180px, 240px));
        gap: 12px;
    }
    .appointment-custom-range[hidden] { display: none; }
    .appointment-filter-error {
        grid-column: 1 / -1;
        margin: 0;
        color: #b42335;
        font-size: .84rem;
        font-weight: 700;
    }
    .appointments-table-wrapper { overflow: hidden; }
    .appointments-scroll { max-height: none; overflow: visible; padding-right: 0; }
    .appointments-table { width: 100%; min-width: 0; table-layout: fixed; }
    .appointments-table th,
    .appointments-table td { padding: 11px 8px; overflow-wrap: anywhere; }
    .appointments-table th:nth-child(1), .appointments-table td:nth-child(1) { width: 21%; }
    .appointments-table th:nth-child(2), .appointments-table td:nth-child(2) { width: 15%; }
    .appointments-table th:nth-child(3), .appointments-table td:nth-child(3) { width: 18%; }
    .appointments-table th:nth-child(4), .appointments-table td:nth-child(4) { width: 24%; }
    .appointments-table th:nth-child(5), .appointments-table td:nth-child(5) { width: 10%; text-align: center; }
    .appointments-table th:nth-child(6), .appointments-table td:nth-child(6) { width: 12%; text-align: center; }
    .appointments-table .status-badge { padding: 4px 7px; font-size: .62rem; }
    .appointments-table .action-buttons { justify-content: center; gap: 6px; }
    .appointments-table .action-buttons .btn { padding: 6px 8px; font-size: .72rem; }
    @media (max-width: 760px) {
        .appointments-scroll { overflow: visible; }
        .appointments-table, .appointments-table thead, .appointments-table tbody,
        .appointments-table tr, .appointments-table td { display: block; width: 100%; }
        .appointments-table thead { display: none; }
        .appointments-table tr { margin-bottom: 12px; padding: 8px 10px; border: 1px solid #e4edf2; border-radius: 10px; }
        .appointments-table td { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 36px; padding: 7px 0; text-align: right; border-bottom: 1px solid #eef3f6; }
        .appointments-table td::before { flex: 0 0 38%; content: attr(data-label); color: #708792; font-size: .7rem; font-weight: 800; letter-spacing: .04em; text-align: left; text-transform: uppercase; }
        .appointments-table td:last-child { border-bottom: 0; }
        .appointments-table td .action-buttons { flex: 1; justify-content: flex-end; }
        .admin-history-filters, .appointment-custom-range { grid-template-columns: 1fr; }
        .appointment-filter-actions { justify-content: flex-start; }
    }
    #appointmentReportModal .report-modal-card {
        width: min(1100px, 100%);
        max-height: calc(100vh - 40px);
        overflow: auto;
        border: 1px solid #dce8ef;
        border-radius: 12px;
        background: #fff;
        box-shadow: 0 24px 70px rgba(7, 59, 76, .28);
    }
    #appointmentReportModal { padding: 20px; }
    #appointmentReportModal .appointment-report-actions {
        display: flex;
        justify-content: flex-end;
        align-items: center;
        gap: 8px;
        padding: 14px 20px;
        border-bottom: 1px solid #e6eef4;
        background: #fbfdff;
    }
    #appointmentReportModal .appointment-report-action {
        min-height: 38px;
        border: 1px solid #dce8ef;
        border-radius: 8px;
        padding: 0 13px;
        background: #fff;
        color: #0b4f80;
        cursor: pointer;
        font: inherit;
        font-size: .82rem;
        font-weight: 700;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    #appointmentReportModal .appointment-report-action.primary {
        border-color: #0f7cc2;
        background: #0f7cc2;
        color: #fff;
    }
    #appointmentReportModal .appointment-report-action:hover { border-color: #0f7cc2; }
    #appointmentReportModal .appointment-generated-report {
        margin: 0;
        padding: 28px 30px 34px;
        border: 0;
        border-radius: 0;
        background: #fff;
        color: #10233f;
        overflow: visible;
    }
    #appointmentReportModal .appointment-report-header {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    #appointmentReportModal .appointment-report-logo {
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        object-fit: contain;
    }
    #appointmentReportModal .appointment-report-header-text { min-width: 0; }
    #appointmentReportModal .appointment-report-clinic {
        margin: 0 0 5px;
        color: #0066cc;
        font-size: .86rem;
        font-weight: 800;
    }
    #appointmentReportModal .appointment-report-location {
        margin: 0 0 5px;
        color: #60758a;
        font-size: .72rem;
        font-weight: 500;
    }
    #appointmentReportModal .appointment-report-title {
        margin: 0;
        color: #073b4c;
        font-size: 1.3rem;
        line-height: 1.2;
    }
    #appointmentReportModal .appointment-report-filter-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px 18px;
        margin: 20px 0;
        padding: 14px 16px;
        border: 1px solid #dce8ef;
        border-radius: 8px;
        background: #f7fbfe;
    }
    #appointmentReportModal .appointment-report-filter-summary div { display: grid; gap: 3px; min-width: 0; }
    #appointmentReportModal .appointment-report-filter-summary span {
        color: #708792;
        font-size: .68rem;
        font-weight: 800;
        letter-spacing: .04em;
        text-transform: uppercase;
    }
    #appointmentReportModal .appointment-report-filter-summary strong {
        color: #10233f;
        font-size: .82rem;
        font-weight: 700;
        overflow-wrap: anywhere;
    }
    #appointmentReportModal .appointment-report-table-wrap { overflow: visible; }
    #appointmentReportModal .appointment-report-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }
    #appointmentReportModal .appointment-report-table th,
    #appointmentReportModal .appointment-report-table td {
        padding: 9px 8px;
        border: 1px solid #dce8ef;
        text-align: left;
        vertical-align: top;
        font-size: .76rem;
        overflow-wrap: anywhere;
    }
    #appointmentReportModal .appointment-report-table th {
        background: #eaf7ff;
        color: #426a7e;
        font-size: .66rem;
        font-weight: 800;
        letter-spacing: .03em;
        text-transform: uppercase;
    }
    #appointmentReportModal .appointment-report-table th:nth-child(1), #appointmentReportModal .appointment-report-table td:nth-child(1) { width: 20%; }
    #appointmentReportModal .appointment-report-table th:nth-child(2), #appointmentReportModal .appointment-report-table td:nth-child(2) { width: 20%; }
    #appointmentReportModal .appointment-report-table th:nth-child(3), #appointmentReportModal .appointment-report-table td:nth-child(3) { width: 21%; }
    #appointmentReportModal .appointment-report-table th:nth-child(4), #appointmentReportModal .appointment-report-table td:nth-child(4) { width: 24%; }
    #appointmentReportModal .appointment-report-table th:nth-child(5), #appointmentReportModal .appointment-report-table td:nth-child(5) { width: 15%; }
    #appointmentReportModal .appointment-report-table .status-badge { padding: 3px 7px; font-size: .59rem; white-space: nowrap; }
    #appointmentReportModal .appointment-report-empty {
        padding: 18px;
        border: 1px solid #dce8ef;
        color: #60758a;
        text-align: center;
        font-size: .85rem;
        font-weight: 600;
    }
    @media (max-width: 900px) {
        #appointmentReportModal .appointment-report-filter-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 760px) {
        #appointmentReportModal .appointment-report-actions { flex-wrap: wrap; }
        #appointmentReportModal .appointment-report-action { flex: 1 1 120px; }
        #appointmentReportModal .appointment-generated-report { padding: 20px 16px 24px; }
        #appointmentReportModal .appointment-report-filter-summary { grid-template-columns: 1fr; }
        #appointmentReportModal .appointment-report-table-wrap { overflow: visible; }
        #appointmentReportModal .appointment-report-table,
        #appointmentReportModal .appointment-report-table thead,
        #appointmentReportModal .appointment-report-table tbody,
        #appointmentReportModal .appointment-report-table tr,
        #appointmentReportModal .appointment-report-table td { display: block; width: 100%; box-sizing: border-box; }
        #appointmentReportModal .appointment-report-table thead { display: none; }
        #appointmentReportModal .appointment-report-table tr { margin-bottom: 10px; padding: 8px 10px; border: 1px solid #e4edf2; border-radius: 8px; }
        #appointmentReportModal .appointment-report-table td { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 34px; padding: 7px 0; border: 0; border-bottom: 1px solid #eef3f6; text-align: right; }
        #appointmentReportModal .appointment-report-table td::before { flex: 0 0 40%; content: attr(data-label); color: #708792; font-size: .68rem; font-weight: 800; letter-spacing: .04em; text-align: left; text-transform: uppercase; }
        #appointmentReportModal .appointment-report-table td:last-child { border-bottom: 0; }
    }
    @media print {
        body.printing-report #appointmentReportModal .appointment-report-actions { display: none !important; }
        body.printing-report #appointmentReportModal .appointment-generated-report { padding: 0 !important; }
        body.printing-report #appointmentReportModal .appointment-report-filter-summary {
            display: grid !important;
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 10px 18px !important;
            margin: 20px 0 !important;
            padding: 14px 16px !important;
            border: 1px solid #dce8ef !important;
            border-radius: 8px !important;
            background: #f7fbfe !important;
        }
        body.printing-report #appointmentReportModal .appointment-report-filter-summary div {
            display: grid !important;
            gap: 3px !important;
            min-width: 0 !important;
            margin: 0 !important;
        }
        body.printing-report #appointmentReportModal .appointment-report-filter-summary span {
            display: block !important;
            color: #708792 !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            letter-spacing: .04em !important;
            text-transform: uppercase !important;
        }
        body.printing-report #appointmentReportModal .appointment-report-filter-summary strong {
            display: block !important;
            margin: 0 !important;
            color: #10233f !important;
            font-size: 12px !important;
            font-weight: 700 !important;
        }
        body.printing-report #appointmentReportModal .appointment-report-table th,
        body.printing-report #appointmentReportModal .appointment-report-table td {
            padding: 9px 8px !important;
            border: 1px solid #dce8ef !important;
            font-size: 11px !important;
            vertical-align: top !important;
            overflow-wrap: anywhere !important;
        }
        body.printing-report #appointmentReportModal .appointment-report-table th {
            background: #eaf7ff !important;
            color: #426a7e !important;
            font-size: 10px !important;
            font-weight: 800 !important;
            letter-spacing: .03em !important;
            text-transform: uppercase !important;
        }
        body.printing-report #appointmentReportModal .appointment-report-table .status-badge {
            display: inline-flex !important;
            align-items: center !important;
            padding: 3px 7px !important;
            border-radius: 999px !important;
            font-size: 9px !important;
            font-weight: 800 !important;
            letter-spacing: .04em !important;
            white-space: nowrap !important;
        }
    }
    /* Keep appointment actions centered and consistent across every row. */
    .appointments-table th:nth-child(6),
    .appointments-table td:nth-child(6) {
        width: 18%;
        text-align: center;
        vertical-align: middle;
    }
    .appointments-table th:nth-child(4),
    .appointments-table td:nth-child(4) { width: 18%; }
    .appointments-table .action-buttons {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        max-width: 190px;
        margin-inline: auto;
        flex-wrap: nowrap;
        white-space: nowrap;
    }
    .appointments-table .action-buttons .btn {
        min-height: 34px;
        box-sizing: border-box;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
    }
    .appointments-table .action-buttons:not(.pending-actions) .btn {
        min-width: 84px;
    }
    .appointments-table .action-buttons.pending-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: center;
        justify-content: center;
        justify-items: center;
        column-gap: 6px;
        row-gap: 6px;
    }
    .appointments-table .action-buttons.pending-actions > form {
        display: block !important;
        width: 100%;
        margin: 0;
    }
    .appointments-table .action-buttons.pending-actions > .btn,
    .appointments-table .action-buttons.pending-actions > form .btn {
        width: 100%;
        min-width: 0;
    }
    .appointments-table .action-buttons.pending-actions .decline-action {
        grid-column: 2;
        justify-self: stretch;
    }
    .appointments-table .action-buttons.pending-actions .confirm-action {
        grid-column: 1;
        grid-row: 1;
    }
    .appointments-table .action-buttons.pending-actions .decline-action {
        grid-row: 1;
    }
    .appointments-table .action-buttons.pending-actions > .btn-details {
        grid-column: 1 / -1;
        grid-row: 2;
        width: 100%;
        min-width: 0;
        justify-self: stretch;
    }
    @media (max-width: 760px) {
        .appointments-table td .action-buttons,
        .appointments-table td .action-buttons.pending-actions {
            width: 100%;
            max-width: 190px;
            justify-content: center;
            justify-items: center;
            margin-inline: auto;
        }
    .appointments-table td .action-buttons:not(.pending-actions) .btn { min-width: 84px; }
    }
    .appointment-filter-actions > .btn,
    .appointment-filter-actions > .filter-reset-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        height: 42px;
        min-height: 42px;
        margin: 0;
        line-height: 1;
    }
    .appointment-filter-actions > .filter-reset-btn {
        padding: 0 16px;
        text-decoration: none;
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

    <?php if (!empty($appointments) && !$isClinicalAppointmentsView): ?>
        <section class="appointments-filter-card" aria-label="Appointment filters">
            <form class="appointment-tools admin-history-filters" id="appointmentHistoryFilters" method="get" action="admin_view_appointments.php">
                <input type="hidden" name="filter_applied" value="1">
                <div class="tool-field">
                    <label for="appointmentDateRange">Date Filter</label>
                    <select id="appointmentDateRange" name="date_range">
                        <option value="all"<?php echo $appointmentDateRange === 'all' ? ' selected' : ''; ?>>All Dates</option>
                        <option value="today"<?php echo $appointmentDateRange === 'today' ? ' selected' : ''; ?>>Today</option>
                        <option value="yesterday"<?php echo $appointmentDateRange === 'yesterday' ? ' selected' : ''; ?>>Yesterday</option>
                        <option value="past_3_days"<?php echo $appointmentDateRange === 'past_3_days' ? ' selected' : ''; ?>>Past 3 Days</option>
                        <option value="past_7_days"<?php echo $appointmentDateRange === 'past_7_days' ? ' selected' : ''; ?>>Past 7 Days</option>
                        <option value="past_2_weeks"<?php echo $appointmentDateRange === 'past_2_weeks' ? ' selected' : ''; ?>>Past 2 Weeks</option>
                        <option value="past_30_days"<?php echo $appointmentDateRange === 'past_30_days' ? ' selected' : ''; ?>>Past 30 Days</option>
                        <option value="custom"<?php echo $appointmentDateRange === 'custom' ? ' selected' : ''; ?>>Custom Date Range</option>
                    </select>
                </div>
                <div class="tool-field">
                    <label for="appointmentSearch">Search</label>
                    <input type="search" id="appointmentSearch" name="search" value="<?php echo htmlspecialchars($appointmentSearch); ?>" placeholder="Patient name, doctor, or service">
                </div>
                <div class="tool-field">
                    <label for="appointmentStatusFilter">Status</label>
                    <select id="appointmentStatusFilter" name="status">
                        <option value="">All statuses</option>
                        <option value="pending"<?php echo $initialStatusFilter === 'pending' ? ' selected' : ''; ?>>Pending (<?php echo (int) $statusCounts['pending']; ?>)</option>
                        <option value="confirmed"<?php echo $initialStatusFilter === 'confirmed' ? ' selected' : ''; ?>>Confirmed (<?php echo (int) $statusCounts['confirmed']; ?>)</option>
                        <option value="completed"<?php echo $initialStatusFilter === 'completed' ? ' selected' : ''; ?>>Completed (<?php echo (int) $statusCounts['completed']; ?>)</option>
                        <option value="cancelled"<?php echo $initialStatusFilter === 'cancelled' ? ' selected' : ''; ?>>Cancelled (<?php echo (int) $statusCounts['cancelled']; ?>)</option>
                    </select>
                </div>
                <div class="appointment-filter-actions">
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                    <a href="admin_view_appointments.php" class="filter-reset-btn" id="appointmentFilterReset">Reset</a>
                    <a href="<?php echo htmlspecialchars(admin_appointment_url(['generate_report' => '1', 'report_type' => 'appointments']), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-primary">Generate Report</a>
                </div>
                <div class="appointment-custom-range" id="appointmentCustomRange"<?php echo $appointmentDateRange === 'custom' ? '' : ' hidden'; ?>">
                    <div class="tool-field">
                        <label for="appointmentDateFrom">Start Date</label>
                        <input type="date" id="appointmentDateFrom" name="date_from" value="<?php echo htmlspecialchars($appointmentDateRange === 'custom' ? $appointmentDateFrom : $appointmentRequestedFrom); ?>">
                    </div>
                    <div class="tool-field">
                        <label for="appointmentDateTo">End Date</label>
                        <input type="date" id="appointmentDateTo" name="date_to" value="<?php echo htmlspecialchars($appointmentDateRange === 'custom' ? $appointmentDateTo : $appointmentRequestedTo); ?>">
                    </div>
                </div>
                <?php if ($appointmentDateError !== ''): ?>
                    <p class="appointment-filter-error" role="alert"><?php echo htmlspecialchars($appointmentDateError); ?></p>
                <?php endif; ?>
            </form>
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
                <h3>No Appointments Found</h3>
                <p><?php echo $isClinicalAppointmentsView ? 'No doctor consultation appointments are assigned to you.' : ($userRole === 'patient' ? 'You don\'t have any appointments yet.' : 'There are no appointments in the system.'); ?></p>
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
            <p class="appointment-result-count" id="appointmentResultCount">Showing <?php echo count($filteredAppointmentRecords) > 0 ? '1 to ' . min(5, count($filteredAppointmentRecords)) . ' of ' . count($filteredAppointmentRecords) : '0 to 0 of 0'; ?> appointment<?php echo count($filteredAppointmentRecords) === 1 ? '' : 's'; ?>.</p>
            <div class="appointments-scroll" aria-label="Scrollable appointments list">
                <table class="appointments-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Doctor</th>
                            <th>Schedule</th>
                            <th>Service</th>
                            <th>Status</th>
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
                        $servicesText = admin_appointment_service_label($appointment);
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
                            'doctor' => admin_appointment_doctor_label($appointment),
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
                            <td data-label="Patient">
                                <div class="patient-cell">
                                    <?php echo renderPatientAvatar($patientDisplay, ['size' => 'sm']); ?>
                                    <div><span class="patient-name"><?php echo htmlspecialchars($patientDisplay['patient_name'] ?? 'N/A'); ?></span></div>
                                </div>
                            </td>
                            <td data-label="Doctor" class="appointments-doctor"><?php echo htmlspecialchars(admin_appointment_doctor_label($appointment)); ?></td>
                            <td data-label="Schedule" class="appointment-schedule-cell appointments-date">
                                <strong><?php echo htmlspecialchars($formattedDate); ?></strong>
                                <span><?php echo htmlspecialchars($formattedTime); ?></span>
                            </td>
                            <td data-label="Service" class="appointments-services"><?php echo htmlspecialchars($servicesText); ?></td>
                            <td data-label="Status">
                                <span class="status-badge <?php echo htmlspecialchars($statusValue); ?>">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                            </td>
                            <td data-label="Actions">
                                <div class="action-buttons <?php echo (($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation') || ($isClinicalAppointmentsView && ($appointment['booking_type'] ?? '') === 'consultation')) && $appointment['status'] === 'pending' ? 'pending-actions' : ''; ?>">
                                    <button type="button" class="btn btn-details" data-open-details>Details</button>
                                    <?php if (($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation') || ($isClinicalAppointmentsView && ($appointment['booking_type'] ?? '') === 'consultation' && $appointment['status'] === 'pending')): ?>
                                        <?php if ($appointment['status'] === 'pending'): ?>
                                            <form method="POST" action="update_appointment_status.php" class="confirm-action">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="confirmed">
                                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($appointmentPage, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-confirm" data-confirm-message="Are you sure you want to confirm this appointment?">Confirm</button>
                                            </form>
                                            <form method="POST" action="update_appointment_status.php" class="decline-action">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="cancelled">
                                                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($appointmentPage, ENT_QUOTES, 'UTF-8'); ?>">
                                                <button type="submit" class="btn btn-cancel" data-confirm-message="Are you sure you want to cancel this appointment?">Cancel</button>
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
                 <strong>No appointment records found for the selected period.</strong>
                <span>Try another date range, search, or status.</span>
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
            <div class="appointment-report-actions">
                <?php if ($reportType === 'appointments'): ?>
                    <a class="appointment-report-action" href="<?php echo htmlspecialchars(admin_appointment_url(['generate_report' => '1', 'download' => 'pdf', 'report_type' => 'appointments', 'status' => $reportStatus]), ENT_QUOTES, 'UTF-8'); ?>">Download PDF</a>
                <?php else: ?>
                    <a class="appointment-report-action" href="admin_report_export.php?report=<?php echo urlencode($reportType); ?>&amp;format=pdf&amp;date_from=<?php echo urlencode($reportDateFrom); ?>&amp;date_to=<?php echo urlencode($reportDateTo); ?>&amp;status=<?php echo urlencode($reportStatus); ?>">Download PDF</a>
                <?php endif; ?>
                <button type="button" class="appointment-report-action primary" id="printAppointmentReport">Print Report</button>
                <button type="button" class="appointment-report-action" data-close-modal>Close</button>
            </div>
            <div class="appointment-generated-report" id="appointmentGeneratedReport">
                <div class="appointment-report-header">
                    <img class="appointment-report-logo" src="<?php echo htmlspecialchars($reportLogoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Globalife clinic logo">
                    <div class="appointment-report-header-text">
                        <p class="appointment-report-clinic"><?php echo htmlspecialchars((string) $clinicInfo['clinic_name']); ?></p>
                        <p class="appointment-report-location"><?php echo htmlspecialchars((string) $clinicInfo['clinic_location']); ?></p>
                        <h2 class="appointment-report-title" id="appointmentReportTitle"><?php echo htmlspecialchars((string) $generatedReport['title']); ?></h2>
                    </div>
                </div>
                <div class="appointment-report-filter-summary">
                    <?php foreach ($generatedReport['summary'] as $label => $value): ?>
                        <div><span><?php echo htmlspecialchars((string) $label); ?></span><strong><?php echo htmlspecialchars((string) $value); ?></strong></div>
                    <?php endforeach; ?>
                    <div><span>Generated</span><strong><?php echo htmlspecialchars((string) ($generatedReport['generated_at'] ?? date('F j, Y g:i A'))); ?></strong></div>
                </div>
                <?php if (empty($generatedReport['rows'])): ?>
                    <div class="appointment-report-empty"><?php echo $reportType === 'appointments' ? 'No appointment records found for the selected filters.' : 'No records match the selected filters.'; ?></div>
                <?php else: ?>
                    <div class="appointment-report-table-wrap">
                        <table class="appointment-report-table">
                            <thead>
                                <tr>
                                    <?php foreach ($generatedReport['headers'] as $header): ?>
                                        <th><?php echo htmlspecialchars((string) $header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($generatedReport['rows'] as $reportRow): ?>
                                    <tr>
                                        <?php foreach ($reportRow as $cellIndex => $cell): ?>
                                            <td data-label="<?php echo htmlspecialchars((string) ($generatedReport['headers'][$cellIndex] ?? '')); ?>"><?php if ($reportType === 'appointments' && $cellIndex === 4): ?><span class="status-badge <?php echo htmlspecialchars(strtolower((string) $cell)); ?>"><?php echo htmlspecialchars((string) $cell); ?></span><?php else: ?><?php echo htmlspecialchars((string) $cell); ?><?php endif; ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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
    var filterApplied = <?php echo json_encode(isset($_GET['filter_applied']) && $appointmentDateError === ''); ?>;
    var cancelReasonWrap = document.getElementById('statusCancelReasonWrap');
    var cancelReasonInput = document.getElementById('statusCancelReason');
    var cancelReasonError = document.getElementById('statusCancelReasonError');
    var pendingForm = null;
    var searchInput = document.getElementById('appointmentSearch');
    var statusFilter = document.getElementById('appointmentStatusFilter');
    var dateRangeFilter = document.getElementById('appointmentDateRange');
    var dateFromFilter = document.getElementById('appointmentDateFrom');
    var dateToFilter = document.getElementById('appointmentDateTo');
    var dateFilter = document.getElementById('appointmentDateFilter');
    var customRange = document.getElementById('appointmentCustomRange');
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
    function showFilterSuccess() {
        openModal(filterSuccessModal);
    }
    if (filterSuccessOk) {
        filterSuccessOk.addEventListener('click', function() {
            closeModal(filterSuccessModal);
        });
    }
    if (filterApplied) {
        var filterUrl = new URL(window.location.href);
        filterUrl.searchParams.delete('filter_applied');
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, filterUrl.toString());
        }
        showFilterSuccess();
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
                ? 'Showing 0 to 0 of 0 appointments.'
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

    function getAppointmentDateRange() {
        if (!dateRangeFilter) {
            return dateFilter && dateFilter.value ? {from: dateFilter.value, to: dateFilter.value} : null;
        }
        var range = dateRangeFilter.value;
        var today = new Date();
        var formatDate = function(date) {
            return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
        };
        if (range === 'custom') return {from: dateFromFilter ? dateFromFilter.value : '', to: dateToFilter ? dateToFilter.value : ''};
        var from = new Date(today);
        var to = new Date(today);
        if (range === 'all') return {from: '1900-01-01', to: formatDate(to)};
        if (range === 'yesterday') {
            from.setDate(from.getDate() - 1);
            to = new Date(from);
        } else if (range === 'past_3_days') from.setDate(from.getDate() - 2);
        else if (range === 'past_7_days') from.setDate(from.getDate() - 6);
        else if (range === 'past_2_weeks') from.setDate(from.getDate() - 13);
        else if (range === 'past_30_days') from.setDate(from.getDate() - 29);
        return {from: formatDate(from), to: formatDate(to)};
    }

    function syncCustomRangeVisibility() {
        if (customRange) customRange.hidden = !(dateRangeFilter && dateRangeFilter.value === 'custom');
    }

    function applyAppointmentFilters() {
        var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var status = statusFilter ? statusFilter.value : '';
        var selectedDates = getAppointmentDateRange();
        filteredRows = [];

        rows.forEach(function(row) {
            var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
            var rowStatus = row.getAttribute('data-status') || '';
            var rowDate = row.getAttribute('data-date') || '';
            var show = true;
            if (q && rowSearch.indexOf(q) === -1) show = false;
            if (status && rowStatus !== status) show = false;
            if (selectedDates && (!selectedDates.from || !selectedDates.to || rowDate < selectedDates.from || rowDate > selectedDates.to)) show = false;
            row.classList.toggle('hidden', !show);
            if (show) filteredRows.push(row);
        });

        if (resultCount) {
            resultCount.textContent = filteredRows.length === 0
                ? 'Showing 0 to 0 of 0 appointments.'
                : 'Showing 1 to ' + Math.min(pageSize, filteredRows.length) + ' of ' + filteredRows.length + ' appointments.';
        }
        if (filterEmpty) filterEmpty.style.display = filteredRows.length === 0 ? 'block' : 'none';
        currentPage = 1;
        renderAppointmentPage();
    }

    [searchInput, statusFilter, dateRangeFilter, dateFromFilter, dateToFilter, dateFilter].forEach(function(control) {
        if (control) control.addEventListener('input', applyAppointmentFilters);
        if (control && control.tagName === 'SELECT') {
            control.addEventListener('change', function() {
                if (control === dateRangeFilter) syncCustomRangeVisibility();
                applyAppointmentFilters();
            });
        }
    });
    if (resetFilter && !dateRangeFilter) {
        resetFilter.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (dateFilter) dateFilter.value = '';
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
            var printReportContent = report.cloneNode(true);
            var printLogo = printReportContent.querySelector('.appointment-report-logo');
            if (printLogo) printLogo.src = new URL(printLogo.getAttribute('src'), window.location.href).href;
            printWindow.document.open();
            printWindow.document.write('<!doctype html><html><head><title>Print Report</title><style>' +
                '@page{size:A4;margin:14mm 16mm}' +
                'html,body{margin:0;padding:0;background:#fff;color:#032642;font-family:Arial,sans-serif}' +
                '.appointment-generated-report{width:100%;margin:0;padding:0;background:#fff;color:#032642}' +
                '.appointment-report-header{display:flex;align-items:center;gap:14px}.appointment-report-logo{width:58px;height:58px;flex:0 0 58px;object-fit:contain}.appointment-report-header-text{min-width:0}' +
                '.appointment-report-clinic{margin:0 0 5px;color:#0066cc;font-size:13px;font-weight:800}' +
                '.appointment-report-location{margin:0 0 5px;color:#60758a;font-size:11px;font-weight:500}' +
                '.appointment-report-title{margin:0 0 20px;color:#073b4c;font-size:21px;line-height:1.2;font-weight:900}' +
                '.appointment-report-filter-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px 18px;margin:20px 0;padding:14px 16px;border:1px solid #dce8ef;border-radius:8px;background:#f7fbfe}' +
                '.appointment-report-filter-summary div{display:grid;gap:3px;min-width:0}' +
                '.appointment-report-filter-summary span{color:#708792;font-size:10px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}' +
                '.appointment-report-filter-summary strong{color:#10233f;font-size:12px;font-weight:700;overflow-wrap:anywhere}' +
                '.appointment-report-table{width:100%;border-collapse:collapse;table-layout:fixed}' +
                '.appointment-report-table th,.appointment-report-table td{padding:7px 8px;border:1px solid #dce8ef;color:#10233f;font-size:11px;text-align:left;vertical-align:top;overflow-wrap:anywhere}' +
                '.appointment-report-table th{background:#eaf7ff;color:#426a7e;font-size:10px;font-weight:800;letter-spacing:.03em;text-transform:uppercase}' +
                '.appointment-report-table .status-badge{padding:3px 7px;font-size:9px;white-space:nowrap}' +
                '.status-badge{display:inline-flex;align-items:center;border-radius:999px;font-weight:800;letter-spacing:.04em;text-transform:uppercase}' +
                '.status-badge.pending{background:#fff6e6;color:#a16207}.status-badge.confirmed{background:#e8f2ff;color:#0066cc}.status-badge.completed{background:#e6f6ec;color:#168a45}.status-badge.cancelled{background:#fdecef;color:#b42318}' +
                '.appointment-report-actions{display:none}' +
                '</style></head><body>' + printReportContent.outerHTML + '</body></html>');
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
    syncCustomRangeVisibility();
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



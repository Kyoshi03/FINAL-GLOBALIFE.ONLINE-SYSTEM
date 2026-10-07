<?php
ob_start();

require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/doctor_pdf_export.php';

if (ob_get_length()) {
    ob_clean();
}

function admin_export_clean(string $value): string {
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], $value);
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function admin_export_count(mysqli $conn, string $sql): int {
    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    return (int) ($row['total'] ?? 0);
}

function admin_export_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function admin_export_date_range_label(string $from, string $to): string {
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

function admin_export_service_label(string $bookingType): string {
    return [
        'consultation' => 'Consultation',
        'ultrasound' => 'Ultrasound',
        'package' => 'Lab Package',
        'individual' => 'Lab Test',
    ][$bookingType] ?? 'Appointment';
}

function admin_export_queue_type_label(string $type): string {
    return $type === 'online' ? 'Online' : 'Walk-in';
}

function admin_export_report_data(mysqli $conn, string $report, array $filters = []): array {
    $report = strtolower($report);
    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }
    $status = strtolower(trim((string) ($filters['status'] ?? '')));

    if ($report === 'queue') {
        $rows = [];
        $summary = ['Walk-in Patients' => '0', 'Online Patients' => '0', 'Completed' => '0', 'Waiting' => '0'];
        if (admin_export_table_exists($conn, 'clinic_queue')) {
            $allowedQueueStatuses = ['waiting', 'serving', 'completed'];
            $where = [];
            if ($dateFrom !== '') {
                $where[] = "q.queue_date >= '" . $conn->real_escape_string($dateFrom) . "'";
            }
            if ($dateTo !== '') {
                $where[] = "q.queue_date <= '" . $conn->real_escape_string($dateTo) . "'";
            }
            if (in_array($status, $allowedQueueStatuses, true)) {
                $where[] = "q.status = '" . $conn->real_escape_string($status) . "'";
            }
            $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
            $patientNameSql = dbUsersNameExpression('p');
            $result = $conn->query("SELECT q.queue_number, q.queue_type, q.status, {$patientNameSql} AS patient_name
                FROM clinic_queue q
                JOIN users p ON p.id = q.patient_id
                {$whereSql}
                ORDER BY q.queue_date ASC, q.id ASC");
            $counts = ['walk_in' => 0, 'online' => 0, 'completed' => 0, 'waiting' => 0];
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $queueType = (string) ($row['queue_type'] ?? 'walk_in');
                    $queueStatus = (string) ($row['status'] ?? 'waiting');
                    if (isset($counts[$queueType])) $counts[$queueType]++;
                    if (isset($counts[$queueStatus])) $counts[$queueStatus]++;
                    $rows[] = [
                        $row['queue_number'],
                        $row['patient_name'],
                        admin_export_queue_type_label($queueType),
                        ucfirst($queueStatus),
                    ];
                }
            }
            $summary = [
                'Walk-in Patients' => (string) $counts['walk_in'],
                'Online Patients' => (string) $counts['online'],
                'Completed' => (string) $counts['completed'],
                'Waiting' => (string) $counts['waiting'],
            ];
        }
        return [
            'title' => 'Queue Report',
            'file' => 'queue_report',
            'summary' => ['Period' => admin_export_date_range_label($dateFrom, $dateTo)] + $summary + ['Generated' => date('Y-m-d H:i')],
            'headers' => ['Queue', 'Patient', 'Type', 'Status'],
            'rows' => $rows,
        ];
    }

    if ($report === 'patients') {
        $rows = [];
        $where = ["role = 'patient'"];
        if ($dateFrom !== '') {
            $where[] = "DATE(created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
        }
        if ($dateTo !== '') {
            $where[] = "DATE(created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
        }
        $patientNameSql = dbUsersNameExpression();
        $result = $conn->query("SELECT {$patientNameSql} AS full_name, phone, email, created_at FROM users WHERE " . implode(' AND ', $where) . " ORDER BY created_at ASC, {$patientNameSql} ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    date('M d', strtotime((string) $row['created_at'])),
                    $row['full_name'],
                    $row['phone'] ?: '-',
                    $row['email'] ?: '-',
                ];
            }
        }
        return [
            'title' => 'Patients Report',
            'file' => 'patient_reports',
            'summary' => [
                'Period' => admin_export_date_range_label($dateFrom, $dateTo),
                'Total Patients' => (string) count($rows),
                'Generated' => date('Y-m-d H:i'),
            ],
            'headers' => ['Date', 'Patient', 'Contact', 'Email'],
            'rows' => $rows,
        ];
    }

    if ($report === 'services') {
        $rows = [];
        $result = $conn->query('SELECT id, name, category, opd_price, home_service_price, is_package, is_active, created_at FROM lab_services ORDER BY is_package DESC, category, name');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    $row['id'],
                    $row['name'],
                    $row['category'],
                    !empty($row['is_package']) ? 'Package' : 'Individual',
                    'PHP ' . number_format((float) $row['opd_price'], 2),
                    $row['home_service_price'] !== null && $row['home_service_price'] !== '' ? 'PHP ' . number_format((float) $row['home_service_price'], 2) : '-',
                    !empty($row['is_active']) ? 'Active' : 'Inactive',
                    $row['created_at'],
                ];
            }
        }
        return [
            'title' => 'Service Reports',
            'file' => 'service_reports',
            'summary' => [
                'Total services' => (string) count($rows),
                'Active services' => (string) admin_export_count($conn, 'SELECT COUNT(*) AS total FROM lab_services WHERE is_active = 1'),
                'Generated' => date('Y-m-d H:i'),
            ],
            'headers' => ['ID', 'Service', 'Category', 'Type', 'OPD', 'Home', 'Status', 'Created'],
            'rows' => $rows,
        ];
    }

    if ($report === 'monthly') {
        $rows = [];
        $result = $conn->query("SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS month_key,
            COUNT(*) AS total,
            SUM(status = 'pending') AS pending_count,
            SUM(status = 'confirmed') AS confirmed_count,
            SUM(status = 'completed') AS completed_count,
            SUM(status = 'cancelled') AS cancelled_count,
            COALESCE(SUM(total_display_price), 0) AS total_amount
            FROM appointments
            GROUP BY DATE_FORMAT(appointment_date, '%Y-%m')
            ORDER BY month_key DESC
            LIMIT 18");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    $row['month_key'],
                    $row['total'],
                    $row['pending_count'],
                    $row['confirmed_count'],
                    $row['completed_count'],
                    $row['cancelled_count'],
                    'PHP ' . number_format((float) $row['total_amount'], 2),
                ];
            }
        }
        return [
            'title' => 'Monthly Statistics',
            'file' => 'monthly_statistics',
            'summary' => [
                'Months shown' => (string) count($rows),
                'Generated' => date('Y-m-d H:i'),
            ],
            'headers' => ['Month', 'Appointments', 'Pending', 'Confirmed', 'Completed', 'Cancelled', 'Total'],
            'rows' => $rows,
        ];
    }

    $rows = [];
    $allowedStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    $where = [];
    if ($dateFrom !== '') {
        $where[] = "a.appointment_date >= '" . $conn->real_escape_string($dateFrom) . "'";
    }
    if ($dateTo !== '') {
        $where[] = "a.appointment_date <= '" . $conn->real_escape_string($dateTo) . "'";
    }
    if (in_array($status, $allowedStatuses, true)) {
        $where[] = "a.status = '" . $conn->real_escape_string($status) . "'";
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $patientNameSql = dbUsersNameExpression('p');
    $result = $conn->query("SELECT {$patientNameSql} AS patient_name,
        a.appointment_date, a.appointment_time, a.status, COALESCE(a.booking_type, '-') AS booking_type
        FROM appointments a
        JOIN users p ON p.id = a.patient_id
        {$whereSql}
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 300");
    $statusTotals = [
        'completed' => 0,
        'pending' => 0,
        'cancelled' => 0,
        'confirmed' => 0,
    ];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rowStatus = strtolower((string) ($row['status'] ?? 'pending'));
            if (isset($statusTotals[$rowStatus])) {
                $statusTotals[$rowStatus]++;
            }
            $rows[] = [
                date('M d', strtotime((string) $row['appointment_date'])),
                $row['patient_name'],
                admin_export_service_label((string) $row['booking_type']),
                ucfirst((string) $row['status']),
            ];
        }
    }

    return [
        'title' => 'Appointments Report',
        'file' => 'appointment_reports',
        'summary' => [
            'Period' => admin_export_date_range_label($dateFrom, $dateTo),
            'Total Appointments' => (string) count($rows),
            'Completed' => (string) $statusTotals['completed'],
            'Pending' => (string) $statusTotals['pending'],
            'Cancelled' => (string) $statusTotals['cancelled'],
            'Generated' => date('Y-m-d H:i'),
        ],
        'headers' => ['Date', 'Patient', 'Service', 'Status'],
        'rows' => $rows,
    ];
}

function admin_export_excel(array $report): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $fileName = $report['file'] . '_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "\xEF\xBB\xBF";
    echo '<table border="1">';
    echo '<tr><th colspan="' . count($report['headers']) . '">' . htmlspecialchars($report['title']) . '</th></tr>';
    foreach ($report['summary'] as $label => $value) {
        echo '<tr><td><strong>' . htmlspecialchars($label) . '</strong></td><td colspan="' . (count($report['headers']) - 1) . '">' . htmlspecialchars($value) . '</td></tr>';
    }
    echo '<tr>';
    foreach ($report['headers'] as $header) {
        echo '<th>' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr>';
    foreach ($report['rows'] as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars(admin_export_clean((string) $cell)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

function admin_export_pdf(array $report): void {
    $ops = [];
    clinic_pdf_line($ops, 36, 810, 559, 810, 2.0);
    clinic_pdf_text($ops, 48, 786, 17, 'Globalife Medical Laboratory & Polyclinic', true);
    clinic_pdf_text($ops, 48, 768, 10, $report['title'], true);
    clinic_pdf_text($ops, 48, 752, 9, 'Generated: ' . date('Y-m-d H:i'));
    clinic_pdf_line($ops, 36, 736, 559, 736, 1.0);

    $y = 714;
    foreach ($report['summary'] as $label => $value) {
        clinic_pdf_text($ops, 48, $y, 9, $label . ':', true);
        clinic_pdf_text($ops, 190, $y, 9, (string) $value);
        $y -= 14;
    }

    $y -= 8;
    clinic_pdf_text($ops, 48, $y, 10, 'Report rows', true);
    $y -= 18;
    $headers = array_slice($report['headers'], 0, 4);
    $colXs = [48, 170, 300, 430];
    foreach ($headers as $i => $header) {
        clinic_pdf_text($ops, $colXs[$i], $y, 8, (string) $header, true);
    }
    $y -= 12;
    clinic_pdf_line($ops, 48, $y + 5, 545, $y + 5, 0.6);

    foreach (array_slice($report['rows'], 0, 26) as $row) {
        if ($y < 58) {
            break;
        }
        $visibleCells = array_slice($row, 0, 4);
        foreach ($visibleCells as $i => $cell) {
            $text = admin_export_clean((string) $cell);
            if (strlen($text) > 24) {
                $text = substr($text, 0, 24) . '...';
            }
            clinic_pdf_text($ops, $colXs[$i], $y, 8, $text);
        }
        $y -= 14;
    }

    if (count($report['rows']) > 26) {
        clinic_pdf_text($ops, 48, 44, 8, 'Only the first 26 rows are shown in PDF. Use Export Excel for the full report.');
    }

    $pdf = clinic_pdf_build(implode("\n", $ops));
    $fileName = $report['file'] . '_' . date('Ymd_His') . '.pdf';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$conn = getDBConnection();
$reportType = strtolower((string) ($_GET['report'] ?? 'appointments'));
$format = strtolower((string) ($_GET['format'] ?? 'pdf'));

$allowedReports = ['appointments', 'queue', 'patients', 'monthly'];
if (!in_array($reportType, $allowedReports, true)) {
    $reportType = 'appointments';
}
$report = admin_export_report_data($conn, $reportType, [
    'date_from' => (string) ($_GET['date_from'] ?? ''),
    'date_to' => (string) ($_GET['date_to'] ?? ''),
    'status' => (string) ($_GET['status'] ?? ''),
]);
$conn->close();

if ($format === 'excel') {
    admin_export_excel($report);
}

admin_export_pdf($report);

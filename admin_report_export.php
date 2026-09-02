<?php
ob_start();

require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/nurse_pdf_export.php';

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

function admin_export_report_data(mysqli $conn, string $report): array {
    $report = strtolower($report);
    if ($report === 'patients') {
        $rows = [];
        $result = $conn->query("SELECT id, full_name, username, email, phone, gender, age, city, created_at FROM users WHERE role = 'patient' ORDER BY created_at DESC, full_name ASC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = [
                    $row['id'],
                    $row['full_name'],
                    $row['username'],
                    $row['email'] ?: '-',
                    $row['phone'] ?: '-',
                    $row['gender'] ?: '-',
                    $row['age'] ?: '-',
                    $row['city'] ?: '-',
                    $row['created_at'],
                ];
            }
        }
        return [
            'title' => 'Patient Reports',
            'file' => 'patient_reports',
            'summary' => [
                'Total patients' => (string) count($rows),
                'Generated' => date('Y-m-d H:i'),
            ],
            'headers' => ['ID', 'Name', 'Username', 'Email', 'Phone', 'Gender', 'Age', 'City', 'Created'],
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
    $result = $conn->query("SELECT a.id, p.full_name AS patient_name, COALESCE(d.full_name, 'Not assigned') AS doctor_name,
        a.appointment_date, a.appointment_time, a.status, COALESCE(a.booking_type, '-') AS booking_type,
        COALESCE(a.total_display_price, 0) AS total_display_price, a.created_at
        FROM appointments a
        JOIN users p ON p.id = a.patient_id
        LEFT JOIN users d ON d.id = a.doctor_id
        ORDER BY a.appointment_date DESC, a.appointment_time DESC
        LIMIT 300");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $bookingTypeLabel = [
                'consultation' => 'Doctor consultation',
                'package' => 'Laboratory package',
                'individual' => 'Laboratory tests',
                'ultrasound' => 'Ultra sound',
            ][(string) $row['booking_type']] ?? ucfirst((string) $row['booking_type']);
            $rows[] = [
                $row['id'],
                $row['patient_name'],
                $row['doctor_name'],
                $row['appointment_date'],
                substr((string) $row['appointment_time'], 0, 5),
                ucfirst((string) $row['status']),
                $bookingTypeLabel,
                'PHP ' . number_format((float) $row['total_display_price'], 2),
                $row['created_at'],
            ];
        }
    }

    return [
        'title' => 'Appointment Reports',
        'file' => 'appointment_reports',
        'summary' => [
            'Total appointments shown' => (string) count($rows),
            'Open appointments' => (string) admin_export_count($conn, "SELECT COUNT(*) AS total FROM appointments WHERE status IN ('pending', 'confirmed')"),
            'Generated' => date('Y-m-d H:i'),
        ],
        'headers' => ['ID', 'Patient', 'Doctor', 'Date', 'Time', 'Status', 'Type', 'Total', 'Created'],
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

function admin_export_database_backup(mysqli $conn): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $fileName = 'globalife_database_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    echo "-- Globalife database backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tablesResult = $conn->query('SHOW TABLES');
    while ($tablesResult && ($tableRow = $tablesResult->fetch_array())) {
        $table = (string) $tableRow[0];
        $safeTable = str_replace('`', '``', $table);
        $createResult = $conn->query("SHOW CREATE TABLE `{$safeTable}`");
        $createRow = $createResult ? $createResult->fetch_assoc() : null;
        echo "DROP TABLE IF EXISTS `{$safeTable}`;\n";
        if ($createRow && isset($createRow['Create Table'])) {
            echo $createRow['Create Table'] . ";\n\n";
        }

        $dataResult = $conn->query("SELECT * FROM `{$safeTable}`");
        while ($dataResult && ($row = $dataResult->fetch_assoc())) {
            $columns = array_map(static fn ($column) => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
            $values = array_map(static function ($value) use ($conn) {
                if ($value === null) {
                    return 'NULL';
                }
                return "'" . $conn->real_escape_string((string) $value) . "'";
            }, array_values($row));
            echo 'INSERT INTO `' . $safeTable . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

$conn = getDBConnection();
$reportType = strtolower((string) ($_GET['report'] ?? 'appointments'));
$format = strtolower((string) ($_GET['format'] ?? 'pdf'));

if ($reportType === 'backup' && $format === 'sql') {
    admin_export_database_backup($conn);
}

$allowedReports = ['appointments', 'patients', 'services', 'monthly'];
if (!in_array($reportType, $allowedReports, true)) {
    $reportType = 'appointments';
}
$report = admin_export_report_data($conn, $reportType);
$conn->close();

if ($format === 'excel') {
    admin_export_excel($report);
}

admin_export_pdf($report);

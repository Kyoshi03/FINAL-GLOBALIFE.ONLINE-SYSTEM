<?php
ob_start();
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/doctor_pdf_export.php';
require_once __DIR__ . '/includes/clinic_info.php';

$pageTitle = 'Queue History | Globalife Medical Laboratory & Polyclinic';
$today = date('Y-m-d');
$dateRange = strtolower(trim((string) ($_GET['date_range'] ?? 'today')));
$allowedDateRanges = ['all', 'today', 'yesterday', 'past_3_days', 'past_7_days', 'past_2_weeks', 'past_30_days', 'custom'];
if (!in_array($dateRange, $allowedDateRanges, true)) {
    $dateRange = 'today';
}

function queue_history_valid_date(string $date): bool {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    return $dateObject instanceof DateTime && $dateObject->format('Y-m-d') === $date;
}

function queue_history_relative_date(string $baseDate, int $days): string {
    return (new DateTime($baseDate))->modify($days . ' days')->format('Y-m-d');
}

$dateFrom = $dateRange === 'all' ? '1900-01-01' : $today;
$dateTo = $today;
$dateRangeError = '';
$requestedDateFrom = trim((string) ($_GET['date_from'] ?? ''));
$requestedDateTo = trim((string) ($_GET['date_to'] ?? ''));
switch ($dateRange) {
    case 'yesterday':
        $dateFrom = $dateTo = queue_history_relative_date($today, -1);
        break;
    case 'past_3_days':
        $dateFrom = queue_history_relative_date($today, -2);
        break;
    case 'past_7_days':
        $dateFrom = queue_history_relative_date($today, -6);
        break;
    case 'past_2_weeks':
        $dateFrom = queue_history_relative_date($today, -13);
        break;
    case 'past_30_days':
        $dateFrom = queue_history_relative_date($today, -29);
        break;
    case 'custom':
        if (!queue_history_valid_date($requestedDateFrom) || !queue_history_valid_date($requestedDateTo)) {
            $dateFrom = $requestedDateFrom;
            $dateTo = $requestedDateTo;
            $dateRangeError = 'Please select both a valid Start Date and End Date.';
        } elseif ($requestedDateTo < $requestedDateFrom) {
            $dateFrom = $requestedDateFrom;
            $dateTo = $requestedDateTo;
            $dateRangeError = 'End Date cannot be earlier than Start Date.';
        } else {
            $dateFrom = $requestedDateFrom;
            $dateTo = $requestedDateTo;
        }
        break;
}

$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 5;

function queue_history_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('h:i A', $stamp) : '--';
}

function queue_history_type_label(?string $type): string {
    return (string) $type === 'online' ? 'Online' : 'Walk-in';
}

function queue_history_priority_options(): array {
    return ['senior', 'pregnant', 'pwd'];
}

function queue_history_priority_types(?string $priority): array {
    $rawValues = preg_split('/[,|]+/', strtolower(trim((string) $priority))) ?: [];
    $values = array_map('trim', $rawValues);
    return array_values(array_filter(queue_history_priority_options(), static function (string $option) use ($values): bool {
        return in_array($option, $values, true);
    }));
}

function queue_history_priority_value(?string $priority): string {
    return implode(',', queue_history_priority_types($priority));
}

function queue_history_priority_label(?string $priority): string {
    $types = queue_history_priority_types($priority);
    return $types === [] ? '—' : implode(', ', array_map('strtoupper', $types));
}

function queue_history_priority_badges_markup(?string $priority): string {
    $types = queue_history_priority_types($priority);
    if ($types === []) {
        return '<span class="queue-priority-empty">—</span>';
    }
    $badges = [];
    foreach ($types as $type) {
        $badges[] = '<span class="badge priority-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(strtoupper($type), ENT_QUOTES, 'UTF-8') . '</span>';
    }
    return implode(' ', $badges);
}

function queue_history_status_label(?string $status): string {
    return [
        'waiting' => 'Waiting',
        'serving' => 'Now Serving',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][(string) $status] ?? 'Waiting';
}

function queue_history_date_range_label(string $from, string $to): string {
    if ($from === '1900-01-01') return 'All Dates';
    $fromLabel = queue_history_valid_date($from) ? date('F j, Y', strtotime($from)) : '--';
    $toLabel = queue_history_valid_date($to) ? date('F j, Y', strtotime($to)) : '--';
    return $from === $to ? $fromLabel : $fromLabel . ' - ' . $toLabel;
}

function queue_history_filter_label(string $dateRange): string {
    return [
        'all' => 'All Dates',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'past_3_days' => 'Past 3 Days',
        'past_7_days' => 'Past 7 Days',
        'past_2_weeks' => 'Past 2 Weeks',
        'past_30_days' => 'Past 30 Days',
        'custom' => 'Custom Date Range',
    ][$dateRange] ?? 'Today';
}

function queue_history_filter_date_label(string $dateRange): string {
    return in_array($dateRange, ['today', 'yesterday'], true) ? 'Date' : 'Period';
}

function queue_history_init_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS clinic_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        queue_date DATE NOT NULL,
        queue_type ENUM('walk_in','online') NOT NULL DEFAULT 'walk_in',
        queue_number VARCHAR(12) NOT NULL,
        patient_id INT NOT NULL,
        appointment_id INT DEFAULT NULL,
        service VARCHAR(160) NOT NULL DEFAULT 'General Consultation',
        priority_type VARCHAR(100) NOT NULL DEFAULT '',
        status ENUM('waiting','serving','completed') NOT NULL DEFAULT 'waiting',
        time_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        time_called DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        UNIQUE KEY unique_daily_queue (queue_date, queue_number),
        KEY idx_queue_day_status (queue_date, status, queue_type, id),
        KEY idx_queue_patient (patient_id),
        KEY idx_queue_appointment (appointment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $priorityResult = $conn->query("SHOW COLUMNS FROM clinic_queue LIKE 'priority_type'");
    if (!$priorityResult || $priorityResult->num_rows === 0) {
        $conn->query("ALTER TABLE clinic_queue ADD COLUMN priority_type VARCHAR(100) NOT NULL DEFAULT '' AFTER service");
    } else {
        $priorityColumn = $priorityResult->fetch_assoc();
        if (stripos((string) ($priorityColumn['Type'] ?? ''), 'varchar') === false) {
            $conn->query("ALTER TABLE clinic_queue MODIFY COLUMN priority_type VARCHAR(100) NOT NULL DEFAULT ''");
        }
    }
    $conn->query("UPDATE clinic_queue SET priority_type = '' WHERE LOWER(TRIM(priority_type)) IN ('standard', 'special')");
}

function queue_history_url(array $overrides = []): string {
    $params = array_merge([
        'date_range' => $_GET['date_range'] ?? 'today',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'search' => $_GET['search'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ], $overrides);
    $params = array_filter($params, static fn($value) => trim((string) $value) !== '');
    return 'queue_history.php?' . http_build_query($params);
}

function queue_history_pdf_stream(array $rows, string $filterLabel, string $periodLabel, string $dateRangeLabel, int $totalRows, string $generatedLabel, int $pageNumber, int $pageTotal, bool $hasLogo = false, string $clinicName = 'Globalife Medical Laboratory & Polyclinic', string $clinicLocation = ''): string {
    $ops = [];
    clinic_pdf_line($ops, 36, 560, 806, 560, 2.0);
    if ($hasLogo) {
        $ops[] = "q 44 0 0 44 40 505 cm /Im1 Do Q";
    }
    $headerX = $hasLogo ? 94 : 36;
    clinic_pdf_text($ops, $headerX, 540, 15, $clinicName, true);
    clinic_pdf_text($ops, $headerX, 521, 11, 'Queue History Report', true);
    if ($clinicLocation !== '') {
        clinic_pdf_text($ops, $headerX, 507, 8, $clinicLocation);
    }
    clinic_pdf_text($ops, 36, 490, 9, 'Filter: ' . $filterLabel);
    clinic_pdf_text($ops, 220, 490, 9, $periodLabel . ': ' . $dateRangeLabel);
    clinic_pdf_text($ops, 36, 475, 9, 'Total Records: ' . $totalRows);
    clinic_pdf_text($ops, 600, 475, 9, 'Generated: ' . $generatedLabel);
    clinic_pdf_line($ops, 36, 462, 806, 462, 0.8);

    $columns = [
        ['Queue No.', 65],
        ['Patient Name', 110],
        ['Type', 55],
        ['Service', 125],
        ['Priority', 65],
        ['Time Added', 65],
        ['Time Called', 65],
        ['Status', 80],
        ['Date', 84],
    ];
    $left = 36;
    $top = 445;
    $rowHeight = 22;
    $tableWidth = array_sum(array_column($columns, 1));
    clinic_pdf_rect($ops, $left, $top - $rowHeight, $tableWidth, $rowHeight, true);
    $x = $left;
    foreach ($columns as [$label, $width]) {
        clinic_pdf_text($ops, $x + 5, $top - 15, 7.5, $label, true);
        $x += $width;
    }

    if (empty($rows)) {
        clinic_pdf_text($ops, $left + 5, $top - 42, 9, 'No queue records found for the selected period.');
    } else {
        $y = $top - $rowHeight;
        foreach ($rows as $row) {
            $y -= $rowHeight;
            clinic_pdf_line($ops, $left, $y, $left + $tableWidth, $y, 0.45);
            $values = [
                (string) ($row['queue_number'] ?? '--'),
                (string) ($row['patient_name'] ?? '--'),
                queue_history_type_label($row['queue_type'] ?? ''),
                (string) ($row['service'] ?? 'General Consultation'),
                queue_history_priority_label($row['priority_type'] ?? ''),
                queue_history_time_label($row['time_added'] ?? null),
                queue_history_time_label($row['time_called'] ?? null),
                queue_history_status_label($row['status'] ?? ''),
                queue_history_valid_date((string) ($row['queue_date'] ?? '')) ? date('M j, Y', strtotime((string) $row['queue_date'])) : '--',
            ];
            $x = $left;
            foreach ($columns as $index => [$label, $width]) {
                $value = clinic_pdf_clean($values[$index]);
                $maxChars = max(8, (int) floor($width / 5.1));
                if (strlen($value) > $maxChars) {
                    $value = substr($value, 0, max(1, $maxChars - 3)) . '...';
                }
                clinic_pdf_text($ops, $x + 5, $y + 7, 7.5, $value);
                $x += $width;
            }
        }
    }

    clinic_pdf_line($ops, 36, 34, 806, 34, 0.7);
    clinic_pdf_text($ops, 36, 20, 7.5, 'Globalife Clinic System');
    clinic_pdf_text($ops, 735, 20, 7.5, 'Page ' . $pageNumber . ' of ' . $pageTotal);
    return implode("\n", $ops);
}

function queue_history_pdf_build(array $streams, ?array $image = null): string {
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
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
    if ($image) {
        $objects[] = "<< /Type /XObject /Subtype /Image /Width " . (int) $image['width']
            . " /Height " . (int) $image['height']
            . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter " . ($image['filter'] ?? '/FlateDecode') . " /Length "
            . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
    }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pageEntries) . ' >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $number = $index + 1;
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($index = 1; $index <= count($objects); $index++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function queue_history_output_pdf(array $rows, string $filterLabel, string $periodLabel, string $dateRangeLabel, string $logoFilePath, string $clinicName, string $clinicLocation): void {
    $generatedLabel = date('F j, Y h:i A');
    $chunks = array_chunk($rows, 17);
    if (empty($chunks)) {
        $chunks = [[]];
    }
    $pageTotal = count($chunks);
    $logo = clinic_pdf_image($logoFilePath);
    $streams = [];
    foreach ($chunks as $index => $chunk) {
        $streams[] = queue_history_pdf_stream($chunk, $filterLabel, $periodLabel, $dateRangeLabel, count($rows), $generatedLabel, $index + 1, $pageTotal, $logo !== null, $clinicName, $clinicLocation);
    }
    $pdf = queue_history_pdf_build($streams, $logo);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="queue_history_' . date('Ymd_His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
}

$conn = getDBConnection();
queue_history_init_schema($conn);
$clinicInfo = clinic_info_get($conn);
$reportLogoPath = clinic_info_logo_web_path($clinicInfo);
$reportLogoFilePath = clinic_info_logo_file_path($clinicInfo);
$allHistoryRows = [];
if ($dateRangeError === '') {
    $where = "WHERE q.queue_date BETWEEN ? AND ? AND q.status = 'completed'";
    $types = 'ss';
    $params = [$dateFrom, $dateTo];

    if ($search !== '') {
        $patientNameSql = dbUsersNameExpression('p');
        $where .= " AND (q.queue_number LIKE ? OR {$patientNameSql} LIKE ? OR q.service LIKE ? OR q.queue_type LIKE ? OR q.priority_type LIKE ?)";
        $searchLike = '%' . $search . '%';
        $types .= 'sssss';
        array_push($params, $searchLike, $searchLike, $searchLike, $searchLike, $searchLike);
    }

    $patientNameSql = $patientNameSql ?? dbUsersNameExpression('p');
    $historySql = "SELECT q.queue_number, q.queue_type, q.priority_type, q.service, q.queue_date, q.time_added, q.time_called, q.completed_at, q.status, {$patientNameSql} AS patient_name
        FROM clinic_queue q
        JOIN users p ON p.id = q.patient_id
        {$where}
        ORDER BY q.queue_date DESC, q.completed_at DESC, q.id DESC";
    $historyStmt = $conn->prepare($historySql);
    $historyStmt->bind_param($types, ...$params);
    $historyStmt->execute();
    $allHistoryRows = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStmt->close();
}
$totalRows = count($allHistoryRows);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$historyRows = array_slice($allHistoryRows, $offset, $perPage);
$dateRangeLabel = queue_history_date_range_label($dateFrom, $dateTo);
$filterLabel = queue_history_filter_label($dateRange);
$downloadPdf = strtolower((string) ($_GET['download'] ?? '')) === 'pdf';
$showReportPreview = (string) ($_GET['generate_report'] ?? '') === '1';
$conn->close();

if ($downloadPdf) {
    queue_history_output_pdf($allHistoryRows, $filterLabel, queue_history_filter_date_label($dateRange), $dateRangeLabel, $reportLogoFilePath, (string) $clinicInfo['clinic_name'], (string) $clinicInfo['clinic_location']);
}

$additionalStyles = '
body { background:#f4f9fd; color:#10233f; }
.queue-history-page { max-width:1180px; margin:0 auto; padding:26px 22px 44px; }
.history-shell { border:1px solid #e4edf2; border-radius:12px; background:#fff; box-shadow:0 10px 24px rgba(25,76,110,.05); overflow:hidden; }
.history-head { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:18px 20px; background:#eaf7ff; border-bottom:1px solid #dce8ef; }
.history-head h1 { margin:0; color:#073b4c; font-size:1.28rem; font-weight:700; }
.history-head a { color:#0066cc; font-weight:700; text-decoration:none; }
.history-filters { display:grid; grid-template-columns:minmax(190px,.75fr) minmax(240px,1.25fr) auto; gap:12px; align-items:end; padding:18px 20px; border-bottom:1px solid #e6eef4; }
.history-filter-group { display:grid; gap:7px; }
.history-filter-group label { color:#1a3342; font-size:.78rem; font-weight:800; }
.history-field { width:100%; min-height:42px; box-sizing:border-box; border:1px solid #dce8ef; border-radius:8px; padding:9px 12px; color:#1a3342; background:#fff; font:inherit; font-size:.875rem; font-weight:500; }
.history-field:focus { outline:none; border-color:#0f7cc2; box-shadow:0 0 0 3px rgba(15,124,194,.08); }
.history-filter-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.history-filter-btn, .history-reset-btn, .history-generate-btn, .history-download-btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; border-radius:8px; padding:0 14px; font:inherit; font-size:.82rem; font-weight:700; text-decoration:none; white-space:nowrap; }
.history-filter-btn { border:1px solid #0f7cc2; background:#0f7cc2; color:#fff; cursor:pointer; }
.history-reset-btn { border:1px solid #dce8ef; background:#fff; color:#0066cc; }
.history-generate-btn { border:1px solid #0f7cc2; background:#0f7cc2; color:#fff; cursor:pointer; }
.history-download-btn { border:1px solid #d4e6f5; background:#eef7ff; color:#0b4f80; }
.history-filter-btn:hover, .history-generate-btn:hover, .history-download-btn:hover, .history-reset-btn:hover { border-color:#0f7cc2; }
.history-custom-range { display:grid; grid-column:1 / -1; grid-template-columns:repeat(2,minmax(180px,220px)); gap:12px; }
.history-custom-range[hidden] { display:none; }
.history-validation { grid-column:1 / -1; margin:0; color:#b42335; font-size:.8rem; font-weight:700; }
.history-table-wrap { overflow:visible; }
.history-table { width:100%; min-width:0; table-layout:fixed; border-collapse:collapse; }
.history-table th, .history-table td { padding:12px 8px; border-bottom:1px solid #eef3f6; text-align:left; color:#10233f; font-size:.82rem; font-weight:500; overflow-wrap:anywhere; }
.history-table th { background:#fff; color:#708792; font-size:.72rem; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
.history-table tr:last-child td { border-bottom:0; }
.history-table th:nth-child(1), .history-table td:nth-child(1) { width:7%; }
.history-table th:nth-child(2), .history-table td:nth-child(2) { width:18%; }
.history-table th:nth-child(3), .history-table td:nth-child(3) { width:10%; text-align:center; }
.history-table th:nth-child(4), .history-table td:nth-child(4) { width:16%; }
.history-table th:nth-child(5), .history-table td:nth-child(5) { width:10%; text-align:center; }
.history-table th:nth-child(6), .history-table td:nth-child(6) { width:9%; white-space:nowrap; }
.history-table th:nth-child(7), .history-table td:nth-child(7) { width:9%; white-space:nowrap; }
.history-table th:nth-child(8), .history-table td:nth-child(8) { width:9%; text-align:center; }
.history-table th:nth-child(9), .history-table td:nth-child(9) { width:12%; white-space:nowrap; }
.history-table td:nth-child(2), .history-table td:nth-child(4) { line-height:1.3; }
.history-table td:nth-child(9) { font-size:.76rem; }
.history-cell-clamp { display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:2; overflow:hidden; }
.history-table td:nth-child(3) .badge, .history-table td:nth-child(5) .badge, .history-table td:nth-child(8) .badge { white-space:nowrap; }
.history-table .badge { padding:3px 6px; font-size:.64rem; white-space:nowrap; }
.queue-number { color:#10233f; font-weight:700; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 10px; font-size:.68rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.badge.completed, .badge.online { background:#dff8e8; color:#08743e; }
.badge.walk_in { background:#dcefff; color:#0066cc; }
.badge.priority-senior, .badge.priority-pregnant, .badge.priority-pwd { background:#eee7ff; color:#5b3a91; }
.queue-priority-empty { color:#7890a1; font-weight:800; }
.history-empty { padding:22px 20px; color:#60758a; font-weight:500; }
.history-pagination { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:14px 20px 18px; border-top:1px solid #e6eef4; background:#fbfdff; }
.history-results-summary { color:#60758a; font-size:.875rem; font-weight:500; }
.history-pagination-controls { display:flex; align-items:center; justify-content:flex-end; flex-wrap:wrap; gap:7px; }
.history-page-btn { min-width:36px; min-height:36px; border:1px solid #dce8ef; border-radius:7px; padding:7px 11px; background:#fff; color:#0b4f80; font-size:.875rem; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
.history-page-btn:hover { border-color:#0f7cc2; color:#0066cc; }
.history-page-btn.is-active { background:#0066cc; border-color:#0066cc; color:#fff; box-shadow:0 10px 20px rgba(0,102,204,.18); }
.history-page-btn.is-disabled { pointer-events:none; opacity:.48; background:#eef7ff; color:#7890a1; }
 .history-report-overlay { position:fixed; inset:0; z-index:4000; display:grid; place-items:center; padding:20px; background:rgba(7,59,76,.52); }
.history-report-overlay[hidden] { display:none; }
.history-filter-success-modal { position:fixed; inset:0; z-index:5000; display:grid; place-items:center; padding:20px; background:rgba(7,59,76,.52); }
.history-filter-success-modal[hidden] { display:none; }
.history-filter-success-card { width:min(420px,100%); border:1px solid #cde8f3; border-radius:12px; background:#fff; box-shadow:0 24px 70px rgba(7,59,76,.28); text-align:center; }
.history-filter-success-body { padding:30px 28px 28px; }
.history-filter-success-icon { display:inline-grid; place-items:center; width:64px; height:64px; margin-bottom:16px; border-radius:50%; background:#dcf7e7; color:#148047; font-size:2rem; font-weight:950; }
.history-filter-success-body h2 { margin:0 0 8px; color:#073b4c; font-size:1.28rem; }
.history-filter-success-body p { margin:0 0 20px; color:#60727d; line-height:1.45; }
.history-report-dialog { width:min(1100px,100%); max-height:calc(100vh - 40px); overflow:auto; border:1px solid #dce8ef; border-radius:12px; background:#fff; box-shadow:0 24px 70px rgba(7,59,76,.28); }
.history-report-actions { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-bottom:1px solid #e6eef4; background:#fbfdff; }
.history-report-action { min-height:38px; border:1px solid #dce8ef; border-radius:8px; padding:0 13px; background:#fff; color:#0b4f80; cursor:pointer; font:inherit; font-size:.82rem; font-weight:700; }
.history-report-action.primary { border-color:#0f7cc2; background:#0f7cc2; color:#fff; }
.history-report-action:hover { border-color:#0f7cc2; }
.history-report-content { padding:28px 30px 34px; color:#10233f; }
.history-report-header { display:flex; align-items:center; gap:14px; }
.history-report-logo { width:58px; height:58px; flex:0 0 58px; object-fit:contain; }
.history-report-header-text { min-width:0; }
.history-report-content h2 { margin:0; color:#073b4c; font-size:1.3rem; }
.history-report-clinic { margin:0 0 5px; color:#0066cc; font-size:.86rem; font-weight:800; }
.history-report-location { margin:0 0 5px; color:#60758a; font-size:.72rem; font-weight:500; }
.history-report-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px 18px; margin:20px 0; padding:14px 16px; border:1px solid #dce8ef; border-radius:8px; background:#f7fbfe; }
.history-report-summary div { display:grid; gap:3px; min-width:0; }
.history-report-summary span { color:#708792; font-size:.68rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
.history-report-summary strong { color:#10233f; font-size:.82rem; font-weight:700; overflow-wrap:anywhere; }
.history-report-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.history-report-table th, .history-report-table td { padding:9px 8px; border:1px solid #dce8ef; text-align:left; vertical-align:top; font-size:.76rem; overflow-wrap:anywhere; }
.history-report-table th { background:#eaf7ff; color:#426a7e; font-size:.66rem; font-weight:800; letter-spacing:.03em; text-transform:uppercase; }
.history-report-table .queue-number { color:#0066cc; font-weight:800; }
.history-report-table .badge { padding:3px 7px; font-size:.59rem; white-space:nowrap; }
.history-report-empty { padding:18px; border:1px solid #dce8ef; color:#60758a; text-align:center; font-size:.85rem; font-weight:600; }
@media (max-width:900px) { .history-report-summary { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:760px) { .history-head, .history-filters { grid-template-columns:1fr; display:grid; } .history-custom-range, .history-report-filter-custom { grid-template-columns:1fr; } .history-filter-actions, .history-filter-btn, .history-reset-btn, .history-generate-btn, .history-download-btn { width:100%; } .history-pagination { align-items:flex-start; flex-direction:column; } .history-pagination-controls { width:100%; justify-content:flex-start; } .history-report-overlay, .history-report-filter-overlay { padding:8px; } .history-report-filter-head { padding:22px 20px; } .history-report-filter-body { padding:22px 20px 24px; } .history-report-filter-form { grid-template-columns:1fr; } .history-report-filter-custom, .history-report-filter-actions { grid-column:auto; } .history-report-filter-actions { align-items:stretch; flex-direction:column; } .history-report-filter-actions .history-generate-btn { width:100%; } .history-report-content { padding:20px 16px 24px; } .history-report-actions { flex-wrap:wrap; } .history-report-action { flex:1 1 120px; } .history-report-summary { grid-template-columns:1fr; } .history-report-table { min-width:0; } .history-table-wrap { overflow:visible; } .history-table, .history-table thead, .history-table tbody, .history-table tr, .history-table td { display:block; width:100%; } .history-table thead { display:none; } .history-table tr { box-sizing:border-box; margin-bottom:10px; padding:8px 10px; border:1px solid #e4edf2; border-radius:8px; background:#fff; } .history-table tr:last-child { margin-bottom:0; } .history-table td { box-sizing:border-box; display:flex; align-items:center; justify-content:space-between; gap:12px; width:100%; min-height:34px; padding:7px 0; border-bottom:1px solid #eef3f6; text-align:right; white-space:normal; } .history-table td::before { flex:0 0 40%; color:#708792; font-size:.68rem; font-weight:800; letter-spacing:.04em; text-align:left; text-transform:uppercase; content:attr(data-label); } .history-table td:last-child { border-bottom:0; } .history-table td:nth-child(3), .history-table td:nth-child(5), .history-table td:nth-child(8) { text-align:right; } .history-table td:nth-child(6), .history-table td:nth-child(7), .history-table td:nth-child(9) { white-space:normal; } }
@media print { body > *:not(main) { display:none !important; } .queue-history-page > section { display:none !important; } .history-report-overlay { position:static; display:block; padding:0; background:#fff; } .history-report-dialog { max-height:none; overflow:visible; border:0; box-shadow:none; } .history-report-actions { display:none; } .history-report-content { padding:0; } .history-report-summary { break-inside:avoid; } }
';

include 'includes/header.php';
?>

<main class="queue-history-page">
    <section class="history-shell">
        <div class="history-head">
            <div>
                <h1>Queue History</h1>
            </div>
            <a href="admin_queue.php">Back to Queuing</a>
        </div>

        <form class="history-filters" id="queueHistoryFilters" method="get" action="queue_history.php">
            <input type="hidden" name="filter_applied" value="1">
            <div class="history-filter-group">
                <label for="queueHistoryDateRange">Date Filter</label>
                <select class="history-field" id="queueHistoryDateRange" name="date_range">
                        <option value="all"<?php echo $dateRange === 'all' ? ' selected' : ''; ?>>All Dates</option>
                        <option value="today"<?php echo $dateRange === 'today' ? ' selected' : ''; ?>>Today</option>
                    <option value="yesterday"<?php echo $dateRange === 'yesterday' ? ' selected' : ''; ?>>Yesterday</option>
                    <option value="past_3_days"<?php echo $dateRange === 'past_3_days' ? ' selected' : ''; ?>>Past 3 Days</option>
                    <option value="past_7_days"<?php echo $dateRange === 'past_7_days' ? ' selected' : ''; ?>>Past 7 Days</option>
                    <option value="past_2_weeks"<?php echo $dateRange === 'past_2_weeks' ? ' selected' : ''; ?>>Past 2 Weeks</option>
                    <option value="past_30_days"<?php echo $dateRange === 'past_30_days' ? ' selected' : ''; ?>>Past 30 Days</option>
                    <option value="custom"<?php echo $dateRange === 'custom' ? ' selected' : ''; ?>>Custom Date Range</option>
                </select>
            </div>
            <div class="history-filter-group">
                <label for="queueHistorySearch">Search</label>
                <input class="history-field" type="search" id="queueHistorySearch" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Queue number, patient, or service">
            </div>
            <div class="history-filter-actions">
                <button class="history-filter-btn" type="submit">Apply Filter</button>
                <a class="history-reset-btn" href="queue_history.php">Reset</a>
                <a class="history-generate-btn" href="<?php echo htmlspecialchars(queue_history_url(['generate_report' => '1', 'page' => 1])); ?>">Generate Report</a>
            </div>
            <div class="history-custom-range" id="queueHistoryCustomRange"<?php echo $dateRange === 'custom' ? '' : ' hidden'; ?>>
                <div class="history-filter-group">
                    <label for="queueHistoryDateFrom">Start Date</label>
                    <input class="history-field" type="date" id="queueHistoryDateFrom" name="date_from" value="<?php echo htmlspecialchars($dateRange === 'custom' ? $dateFrom : $requestedDateFrom); ?>" data-custom-date>
                </div>
                <div class="history-filter-group">
                    <label for="queueHistoryDateTo">End Date</label>
                    <input class="history-field" type="date" id="queueHistoryDateTo" name="date_to" value="<?php echo htmlspecialchars($dateRange === 'custom' ? $dateTo : $requestedDateTo); ?>" data-custom-date>
                </div>
            </div>
            <p class="history-validation" id="queueHistoryValidation" role="alert"<?php echo $dateRangeError === '' ? ' hidden' : ''; ?>><?php echo htmlspecialchars($dateRangeError); ?></p>
        </form>

        <?php if (empty($historyRows)): ?>
            <div class="history-empty">No queue records found for the selected period.</div>
        <?php else: ?>
            <div class="history-table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Queue No.</th>
                            <th>Patient Name</th>
                            <th>Type</th>
                            <th>Service</th>
                            <th>Priority</th>
                            <th>Time Added</th>
                            <th>Time Called</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyRows as $index => $queueRow): ?>
                            <tr>
                                <td data-label="Queue No." class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                <td data-label="Patient Name"><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                <td data-label="Type"><span class="badge <?php echo htmlspecialchars((string) $queueRow['queue_type']); ?>"><?php echo htmlspecialchars(queue_history_type_label($queueRow['queue_type'] ?? '')); ?></span></td>
                                <td data-label="Service"><span class="history-cell-clamp"><?php echo htmlspecialchars((string) ($queueRow['service'] ?? 'General Consultation')); ?></span></td>
                                <td data-label="Priority"><?php echo queue_history_priority_badges_markup($queueRow['priority_type'] ?? ''); ?></td>
                                <td data-label="Time Added"><?php echo htmlspecialchars(queue_history_time_label($queueRow['time_added'] ?? null)); ?></td>
                                <td data-label="Time Called"><?php echo htmlspecialchars(queue_history_time_label($queueRow['time_called'] ?? null)); ?></td>
                                <td data-label="Status"><span class="badge completed"><?php echo htmlspecialchars(queue_history_status_label($queueRow['status'] ?? 'completed')); ?></span></td>
                                <td data-label="Date"><?php echo htmlspecialchars(queue_history_valid_date((string) $queueRow['queue_date']) ? date('F j, Y', strtotime((string) $queueRow['queue_date'])) : '--'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="history-pagination" aria-label="Queue history pagination">
            <div class="history-results-summary">
                <?php $summaryStart = $totalRows > 0 ? $offset + 1 : 0; $summaryEnd = min($offset + $perPage, $totalRows); ?>
                Showing <?php echo $summaryStart; ?> to <?php echo $summaryEnd; ?> of <?php echo $totalRows; ?> queue records.
            </div>
            <div class="history-pagination-controls">
                <a class="history-page-btn <?php echo $page <= 1 ? 'is-disabled' : ''; ?>" href="<?php echo htmlspecialchars(queue_history_url(['page' => max(1, $page - 1)])); ?>">Previous</a>
                <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                    <a class="history-page-btn <?php echo $pageNumber === $page ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(queue_history_url(['page' => $pageNumber])); ?>"<?php echo $pageNumber === $page ? ' aria-current="page"' : ''; ?>><?php echo $pageNumber; ?></a>
                <?php endfor; ?>
                <a class="history-page-btn <?php echo $page >= $totalPages ? 'is-disabled' : ''; ?>" href="<?php echo htmlspecialchars(queue_history_url(['page' => min($totalPages, $page + 1)])); ?>">Next</a>
            </div>
        </div>
    </section>

    <div class="history-report-overlay" id="queueHistoryReport" role="dialog" aria-modal="true" aria-labelledby="queueHistoryReportTitle"<?php echo $showReportPreview ? '' : ' hidden'; ?>>
        <div class="history-report-dialog">
            <div class="history-report-actions">
                <a class="history-download-btn" href="<?php echo htmlspecialchars(queue_history_url(['download' => 'pdf', 'page' => 1])); ?>">Download PDF</a>
                <button class="history-report-action primary" type="button" id="printQueueHistoryReport">Print Report</button>
                <button class="history-report-action" type="button" id="closeQueueHistoryReport">Close</button>
            </div>
            <div class="history-report-content" id="queueHistoryReportContent">
                <div class="history-report-header">
                    <img class="history-report-logo" src="<?php echo htmlspecialchars($reportLogoPath, ENT_QUOTES, 'UTF-8'); ?>" alt="Globalife clinic logo">
                    <div class="history-report-header-text">
                        <p class="history-report-clinic"><?php echo htmlspecialchars((string) $clinicInfo['clinic_name']); ?></p>
                        <p class="history-report-location"><?php echo htmlspecialchars((string) $clinicInfo['clinic_location']); ?></p>
                        <h2 id="queueHistoryReportTitle">Queue History Report</h2>
                    </div>
                </div>
                <div class="history-report-summary">
                    <div><span>Filter</span><strong><?php echo htmlspecialchars($filterLabel); ?></strong></div>
                    <?php if ($dateRange === 'custom'): ?>
                        <div><span>Start Date</span><strong><?php echo htmlspecialchars(queue_history_valid_date($dateFrom) ? date('F j, Y', strtotime($dateFrom)) : '--'); ?></strong></div>
                        <div><span>End Date</span><strong><?php echo htmlspecialchars(queue_history_valid_date($dateTo) ? date('F j, Y', strtotime($dateTo)) : '--'); ?></strong></div>
                    <?php else: ?>
                        <div><span><?php echo htmlspecialchars(queue_history_filter_date_label($dateRange)); ?></span><strong><?php echo htmlspecialchars($dateRangeLabel); ?></strong></div>
                    <?php endif; ?>
                    <div><span>Total Records</span><strong><?php echo (int) $totalRows; ?></strong></div>
                    <div><span>Generated</span><strong><?php echo htmlspecialchars(date('F j, Y') . ' - ' . date('g:i A')); ?></strong></div>
                </div>
                <?php if (empty($allHistoryRows)): ?>
                    <div class="history-report-empty">No queue records found for the selected period.</div>
                <?php else: ?>
                    <div class="history-table-wrap">
                        <table class="history-report-table">
                            <thead>
                                <tr>
                                    <th>Queue No.</th>
                                    <th>Patient Name</th>
                                    <th>Type</th>
                                    <th>Service</th>
                                    <th>Priority</th>
                                    <th>Time Added</th>
                                    <th>Time Called</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allHistoryRows as $queueRow): ?>
                                    <tr>
                                        <td class="queue-number"><?php echo htmlspecialchars((string) $queueRow['queue_number']); ?></td>
                                        <td><?php echo htmlspecialchars((string) $queueRow['patient_name']); ?></td>
                                        <td><span class="badge <?php echo htmlspecialchars((string) $queueRow['queue_type']); ?>"><?php echo htmlspecialchars(queue_history_type_label($queueRow['queue_type'] ?? '')); ?></span></td>
                                        <td><?php echo htmlspecialchars((string) ($queueRow['service'] ?? 'General Consultation')); ?></td>
                                        <td><?php echo queue_history_priority_badges_markup($queueRow['priority_type'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(queue_history_time_label($queueRow['time_added'] ?? null)); ?></td>
                                        <td><?php echo htmlspecialchars(queue_history_time_label($queueRow['time_called'] ?? null)); ?></td>
                                        <td><span class="badge completed"><?php echo htmlspecialchars(queue_history_status_label($queueRow['status'] ?? 'completed')); ?></span></td>
                                        <td><?php echo htmlspecialchars(queue_history_valid_date((string) $queueRow['queue_date']) ? date('F j, Y', strtotime((string) $queueRow['queue_date'])) : '--'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="history-filter-success-modal" id="filterSuccessModal" role="dialog" aria-modal="true" aria-labelledby="filterSuccessTitle"<?php echo (isset($_GET['filter_applied']) && $dateRangeError === '') ? '' : ' hidden'; ?>>
        <div class="history-filter-success-card">
            <div class="history-filter-success-body">
                <span class="history-filter-success-icon" aria-hidden="true">✓</span>
                <h2 id="filterSuccessTitle">Filters Applied</h2>
                <p>You have successfully applied the filters.</p>
                <button class="history-filter-btn" type="button" id="filterSuccessOk">OK</button>
            </div>
        </div>
    </div>
</main>

<script>
    (function () {
        var form = document.getElementById('queueHistoryFilters');
        var range = document.getElementById('queueHistoryDateRange');
        var customRange = document.getElementById('queueHistoryCustomRange');
        var validation = document.getElementById('queueHistoryValidation');
        var report = document.getElementById('queueHistoryReport');
        var closeReport = document.getElementById('closeQueueHistoryReport');
        var printReport = document.getElementById('printQueueHistoryReport');
        var filterSuccessModal = document.getElementById('filterSuccessModal');
        var filterSuccessOk = document.getElementById('filterSuccessOk');

        function syncCustomRange() {
            var isCustom = range && range.value === 'custom';
            if (customRange) customRange.hidden = !isCustom;
        }

        function showValidation(message) {
            if (!validation) return;
            validation.textContent = message;
            validation.hidden = false;
        }

        if (form && range && customRange) {
            syncCustomRange();
            range.addEventListener('change', function () {
                syncCustomRange();
                if (range.value !== 'custom') form.submit();
            });
            form.addEventListener('submit', function (event) {
                if (range.value !== 'custom') return;
                var start = document.getElementById('queueHistoryDateFrom').value;
                var end = document.getElementById('queueHistoryDateTo').value;
                if (!start || !end) {
                    event.preventDefault();
                    showValidation('Please select both a valid Start Date and End Date.');
                } else if (end < start) {
                    event.preventDefault();
                    showValidation('End Date cannot be earlier than Start Date.');
                }
            });
        }

        function closeReportModal() {
            if (!report) return;
            report.hidden = true;
            document.body.classList.remove('history-report-open');
        }

        function closeFilterSuccessModal() {
            if (!filterSuccessModal) return;
            filterSuccessModal.hidden = true;
        }

        if (filterSuccessOk) filterSuccessOk.addEventListener('click', closeFilterSuccessModal);
        if (filterSuccessModal) filterSuccessModal.addEventListener('click', function (event) {
            if (event.target === filterSuccessModal) closeFilterSuccessModal();
        });
        if (filterSuccessModal && !filterSuccessModal.hidden) {
            var filterUrl = new URL(window.location.href);
            filterUrl.searchParams.delete('filter_applied');
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, filterUrl.toString());
            }
        }

        if (closeReport) closeReport.addEventListener('click', closeReportModal);
        if (report) report.addEventListener('click', function (event) {
            if (event.target === report) closeReportModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            if (report && !report.hidden) closeReportModal();
            if (filterSuccessModal && !filterSuccessModal.hidden) closeFilterSuccessModal();
        });

        if (printReport) {
            printReport.addEventListener('click', function () {
                var content = document.getElementById('queueHistoryReportContent');
                var printWindow = window.open('', '_blank', 'width=1200,height=850');
                if (!printWindow || !content) return;
                var printContent = content.cloneNode(true);
                var printLogo = printContent.querySelector('.history-report-logo');
                if (printLogo) printLogo.src = new URL(printLogo.getAttribute('src'), window.location.href).href;
                printWindow.document.write('<!doctype html><html><head><title>Queue History Report</title><style>' +
                    'body{margin:0;padding:28px;color:#10233f;background:#fff;font-family:Arial,sans-serif}' +
                    '.history-report-header{display:flex;align-items:center;gap:14px}.history-report-logo{width:58px;height:58px;flex:0 0 58px;object-fit:contain}.history-report-header-text{min-width:0}' +
                    '.history-report-clinic{margin:0 0 5px;color:#0066cc;font-size:14px;font-weight:800}' +
                    '.history-report-location{margin:0 0 5px;color:#60758a;font-size:11px;font-weight:500}' +
                    'h2{margin:0;color:#073b4c;font-size:22px}' +
                    '.history-report-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px 18px;margin:20px 0;padding:14px 16px;border:1px solid #dce8ef;background:#f7fbfe}' +
                    '.history-report-summary div{display:grid;gap:3px}.history-report-summary span{color:#708792;font-size:10px;font-weight:800;text-transform:uppercase}.history-report-summary strong{font-size:12px}' +
                    'table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{padding:8px;border:1px solid #dce8ef;text-align:left;vertical-align:top;font-size:10px;overflow-wrap:anywhere}th{background:#eaf7ff;color:#426a7e;font-size:9px;text-transform:uppercase}.queue-number{color:#0066cc;font-weight:800}.badge{display:inline-block;padding:3px 7px;border-radius:999px;font-size:8px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}.badge.online,.badge.completed{background:#dff8e8;color:#08743e}.badge.walk_in{background:#dcefff;color:#0066cc}.badge.priority-senior,.badge.priority-pregnant,.badge.priority-pwd{background:#eee7ff;color:#5b3a91}.history-report-empty{padding:18px;border:1px solid #dce8ef;text-align:center;color:#60758a}' +
                    '@media print{body{padding:0}.history-report-summary{break-inside:avoid}}' +
                    '</style></head><body>' + printContent.innerHTML + '</body></html>');
                printWindow.document.close();
                printWindow.focus();
                printWindow.setTimeout(function () {
                    printWindow.print();
                }, 250);
            });
        }
    }());
</script>

<?php include 'includes/footer.php'; ?>

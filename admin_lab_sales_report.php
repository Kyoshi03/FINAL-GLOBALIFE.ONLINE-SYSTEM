<?php
require_once __DIR__ . '/includes/session.php';
checkRole('admin');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/appointment_booking.php';
require_once __DIR__ . '/includes/doctor_pdf_export.php';

$pageTitle = 'Laboratory Sales Report | Globalife Medical Laboratory & Polyclinic';
$conn = getDBConnection();
appointment_init_queue_schema($conn);

function sales_report_valid_date(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed instanceof DateTimeImmutable
        && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date;
}

function sales_report_period(string $period): string {
    return in_array($period, ['daily', 'monthly', 'quarterly'], true) ? $period : 'daily';
}

function sales_report_range_for_period(string $period, DateTimeImmutable $anchor): array {
    if ($period === 'monthly') {
        return [$anchor->modify('first day of this month'), $anchor->modify('last day of this month')];
    }
    if ($period === 'quarterly') {
        $firstMonth = (int) (floor(((int) $anchor->format('n') - 1) / 3) * 3 + 1);
        $start = $anchor->setDate((int) $anchor->format('Y'), $firstMonth, 1);
        return [$start, $start->modify('+2 months')->modify('last day of this month')];
    }
    return [$anchor, $anchor];
}

function sales_report_clean_service(string $service): string {
    $service = trim((string) preg_replace('/\s*[-|]\s*Total:\s*(?:PHP|₱)\s*[0-9,]+(?:\.[0-9]{2})?/i', '', $service));
    return $service !== '' ? $service : 'Laboratory service';
}

function sales_report_is_laboratory_service(string $service): bool {
    $value = strtolower(trim($service));
    return $value !== ''
        && strpos($value, 'consult') === false
        && strpos($value, 'ultra') === false
        && strpos($value, 'sonogram') === false;
}

function sales_report_add_transaction(array &$rows, string $date, array $items, string $source): void {
    if (!sales_report_valid_date($date)) {
        return;
    }

    $serviceNames = [];
    $unitPrices = [];
    $quantity = 0;
    $totalAmount = 0.0;
    foreach ($items as $item) {
        $service = sales_report_clean_service((string) ($item['name'] ?? ''));
        $unitPrice = round((float) ($item['price'] ?? 0), 2);
        $lineQuantity = max(1, (int) ($item['quantity'] ?? 1));
        if ($service === '' || $unitPrice <= 0 || !sales_report_is_laboratory_service($service)) {
            continue;
        }
        $serviceNames[] = $service;
        $unitPrices[] = $lineQuantity > 1
            ? sales_report_money($unitPrice) . ' x ' . $lineQuantity
            : sales_report_money($unitPrice);
        $quantity += $lineQuantity;
        $totalAmount += $unitPrice * $lineQuantity;
    }
    if ($serviceNames === [] || $quantity <= 0 || $totalAmount <= 0) {
        return;
    }
    $rows[] = [
        'date' => $date,
        'service' => implode(', ', $serviceNames),
        'quantity' => $quantity,
        'unit_price' => implode(' + ', $unitPrices),
        'total_amount' => round($totalAmount, 2),
        'source' => $source,
    ];
}

function sales_report_add_row(array &$rows, string $date, string $service, float $amount, string $source, int $quantity = 1): void {
    sales_report_add_transaction($rows, $date, [[
        'name' => $service,
        'price' => $amount,
        'quantity' => $quantity,
    ]], $source);
}

function sales_report_rows(mysqli $conn, string $from, string $to): array {
    $rows = [];

    // There is no payment_status column in the current schema. Completed rows
    // with a positive stored service amount are the paid-sale basis.
    $onlineStmt = $conn->prepare(
        "SELECT a.id, a.appointment_date, a.booking_type, a.total_display_price,
                ls.name AS service_name, aps.unit_price
         FROM appointments a
         LEFT JOIN appointment_services aps ON aps.appointment_id = a.id
         LEFT JOIN lab_services ls ON ls.id = aps.service_id
         WHERE a.status = 'completed'
           AND a.booking_type IN ('package', 'individual')
           AND a.appointment_date BETWEEN ? AND ?
         ORDER BY a.appointment_date ASC, a.id ASC, aps.id ASC"
    );
    if ($onlineStmt) {
        $onlineStmt->bind_param('ss', $from, $to);
        if ($onlineStmt->execute()) {
            $result = $onlineStmt->get_result();
            $onlineTransactions = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $appointmentId = (int) ($row['id'] ?? 0);
                $serviceName = trim((string) ($row['service_name'] ?? ''));
                $amount = $row['unit_price'] !== null
                    ? (float) $row['unit_price']
                    : (float) ($row['total_display_price'] ?? 0);
                if ($appointmentId <= 0) {
                    continue;
                }
                if (!isset($onlineTransactions[$appointmentId])) {
                    $onlineTransactions[$appointmentId] = [
                        'date' => (string) ($row['appointment_date'] ?? ''),
                        'fallback_name' => (string) ($row['booking_type'] ?? '') === 'package'
                            ? 'Laboratory Package'
                            : 'Laboratory Test',
                        'fallback_amount' => (float) ($row['total_display_price'] ?? 0),
                        'items' => [],
                    ];
                }
                if ($serviceName !== '' && $amount > 0) {
                    $onlineTransactions[$appointmentId]['items'][] = [
                        'name' => $serviceName,
                        'price' => $amount,
                        'quantity' => 1,
                    ];
                }
            }
            foreach ($onlineTransactions as $transaction) {
                if ($transaction['items'] !== []) {
                    sales_report_add_transaction($rows, $transaction['date'], $transaction['items'], 'Online appointment');
                } else {
                    sales_report_add_row($rows, $transaction['date'], $transaction['fallback_name'], $transaction['fallback_amount'], 'Online appointment');
                }
            }
        }
        $onlineStmt->close();
    }

    $queueLineStmt = $conn->prepare(
        "SELECT q.id AS queue_id, q.queue_date, qls.service_name, qls.unit_price, qls.quantity
         FROM clinic_queue q
         INNER JOIN clinic_queue_services qls ON qls.queue_id = q.id
         WHERE q.status = 'completed'
           AND q.queue_type = 'walk_in'
           AND (q.appointment_id IS NULL OR q.appointment_id = 0)
           AND q.queue_date BETWEEN ? AND ?
           AND qls.unit_price > 0
           AND qls.quantity > 0
         ORDER BY q.queue_date ASC, q.id ASC, qls.id ASC"
    );
    if ($queueLineStmt) {
        $queueLineStmt->bind_param('ss', $from, $to);
        if ($queueLineStmt->execute()) {
            $result = $queueLineStmt->get_result();
            $walkInTransactions = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $queueId = (int) ($row['queue_id'] ?? 0);
                if ($queueId <= 0) continue;
                if (!isset($walkInTransactions[$queueId])) {
                    $walkInTransactions[$queueId] = [
                        'date' => (string) ($row['queue_date'] ?? ''),
                        'items' => [],
                    ];
                }
                $walkInTransactions[$queueId]['items'][] = [
                    'name' => (string) ($row['service_name'] ?? ''),
                    'price' => (float) ($row['unit_price'] ?? 0),
                    'quantity' => (int) ($row['quantity'] ?? 1),
                ];
            }
            foreach ($walkInTransactions as $transaction) {
                sales_report_add_transaction($rows, $transaction['date'], $transaction['items'], 'Walk-in');
            }
        }
        $queueLineStmt->close();
    }

    $queueStmt = $conn->prepare(
        "SELECT q.queue_date, q.service, q.service_total
         FROM clinic_queue q
         WHERE q.status = 'completed'
           AND q.queue_type = 'walk_in'
           AND (q.appointment_id IS NULL OR q.appointment_id = 0)
           AND NOT EXISTS (SELECT 1 FROM clinic_queue_services qls WHERE qls.queue_id = q.id)
           AND q.queue_date BETWEEN ? AND ?
           AND q.service_total > 0
         ORDER BY q.queue_date ASC, q.id ASC"
    );
    if ($queueStmt) {
        $queueStmt->bind_param('ss', $from, $to);
        if ($queueStmt->execute()) {
            $result = $queueStmt->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                sales_report_add_row($rows, (string) ($row['queue_date'] ?? ''), (string) ($row['service'] ?? ''), (float) ($row['service_total'] ?? 0), 'Walk-in', 1);
            }
        }
        $queueStmt->close();
    }

    $rows = array_values($rows);
    usort($rows, static fn(array $a, array $b): int => [$a['date'], strtolower($a['service']), $a['source']] <=> [$b['date'], strtolower($b['service']), $b['source']]);
    return $rows;
}

function sales_report_money(float $amount): string {
    return '₱' . number_format($amount, 2);
}

function sales_report_pdf_page(array $rows, string $from, string $to, float $total, int $quantity, int $page, int $pages, ?array $logo): string {
    $ops = [];
    clinic_pdf_line($ops, 34, 805, 561, 805, 2.2);
    if ($logo) {
        $ops[] = 'q 54 0 0 54 42 738 cm /Im1 Do Q';
    } else {
        clinic_pdf_rect($ops, 42, 738, 54, 54, true);
        clinic_pdf_text($ops, 57, 760, 18, 'GL', true);
    }
    clinic_pdf_text($ops, 112, 780, 13, 'Globalife Medical Laboratory & Polyclinic', true);
    clinic_pdf_text($ops, 112, 764, 9, 'Laboratory Sales Report');
    clinic_pdf_text($ops, 42, 710, 16, 'LABORATORY SALES REPORT', true);
    clinic_pdf_text($ops, 42, 692, 9, 'Period: ' . date('M j, Y', strtotime($from)) . ' - ' . date('M j, Y', strtotime($to)));
    clinic_pdf_text($ops, 42, 676, 9, 'Completed and paid laboratory transactions only');
    clinic_pdf_text($ops, 350, 692, 9, 'Total Sales: ' . sales_report_money($total), true);
    clinic_pdf_text($ops, 350, 676, 9, 'Completed Tests: ' . number_format($quantity), true);

    $tableTop = 642;
    $rowHeight = 21;
    $columns = [['DATE', 92], ['LABORATORY TEST / PACKAGE', 235], ['QTY', 45], ['UNIT PRICE', 82], ['TOTAL AMOUNT', 85]];
    $tableWidth = array_sum(array_column($columns, 1));
    clinic_pdf_rect($ops, 42, $tableTop - $rowHeight, $tableWidth, $rowHeight, true);
    $x = 42;
    foreach ($columns as [$label, $width]) {
        clinic_pdf_text($ops, $x + 5, $tableTop - 14, 7.2, $label, true);
        $x += $width;
    }
    if (!$rows) {
        clinic_pdf_text($ops, 47, $tableTop - 42, 9, 'No completed and paid laboratory transactions found.');
    } else {
        $y = $tableTop - $rowHeight;
        foreach ($rows as $row) {
            $y -= $rowHeight;
            clinic_pdf_line($ops, 42, $y, 42 + $tableWidth, $y, 0.45);
            $values = [date('M j, Y', strtotime((string) $row['date'])), (string) $row['service'], number_format((int) $row['quantity']), (string) $row['unit_price'], sales_report_money((float) $row['total_amount'])];
            $x = 42;
            foreach ($columns as $index => [$label, $width]) {
                $value = clinic_pdf_clean($values[$index]);
                $maxChars = max(8, (int) floor($width / 5.2));
                if (strlen($value) > $maxChars) $value = substr($value, 0, max(1, $maxChars - 3)) . '...';
                clinic_pdf_text($ops, $x + 5, $y + 7, 7.6, $value);
                $x += $width;
            }
        }
    }
    clinic_pdf_line($ops, 42, 38, 553, 38, 0.7);
    clinic_pdf_text($ops, 42, 24, 7.5, 'Globalife Clinic System');
    clinic_pdf_text($ops, 465, 24, 7.5, 'Page ' . $page . ' of ' . $pages);
    return implode("\n", $ops);
}

function sales_report_pdf_build(array $streams, ?array $image): string {
    $pageCount = max(1, count($streams));
    $pageIds = [];
    $fontRegularId = 3 + $pageCount;
    $fontBoldId = $fontRegularId + 1;
    $contentStartId = $fontBoldId + 1;
    $imageId = $contentStartId + $pageCount;
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', ''];
    for ($i = 0; $i < $pageCount; $i++) {
        $pageIds[] = 3 + $i;
        $contentId = $contentStartId + $i;
        $imageResource = $image ? ' /XObject << /Im1 ' . $imageId . ' 0 R >>' : '';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontRegularId . ' 0 R /F2 ' . $fontBoldId . ' 0 R >>' . $imageResource . ' >> /Contents ' . $contentId . ' 0 R >>';
    }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds)) . '] /Count ' . $pageCount . ' >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    foreach ($streams as $stream) $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    if ($image) $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $image['width'] . ' /Height ' . (int) $image['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter ' . ($image['filter'] ?? '/FlateDecode') . ' /Length ' . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) { $offsets[] = strlen($pdf); $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
}

function sales_report_output_pdf(array $rows, string $from, string $to, float $total, int $quantity): void {
    $chunks = array_chunk($rows, 28);
    if (!$chunks) $chunks = [[]];
    $logo = clinic_pdf_image(__DIR__ . '/globalife.png');
    $streams = [];
    $pages = count($chunks);
    foreach ($chunks as $index => $chunk) $streams[] = sales_report_pdf_page($chunk, $from, $to, $total, $quantity, $index + 1, $pages, $logo);
    $pdf = sales_report_pdf_build($streams, $logo);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="laboratory_sales_report_' . date('Ymd_His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
}

$today = new DateTimeImmutable('today');
$period = sales_report_period(strtolower(trim((string) ($_GET['period'] ?? 'daily'))));
$showTrend = (string) ($_GET['show_trend'] ?? '') === '1';
$anchorDate = trim((string) ($_GET['anchor_date'] ?? $today->format('Y-m-d')));
if (!sales_report_valid_date($anchorDate)) $anchorDate = $today->format('Y-m-d');
$anchor = new DateTimeImmutable($anchorDate);
if ($anchor > $today) { $anchor = $today; $anchorDate = $today->format('Y-m-d'); }

$requestedFrom = trim((string) ($_GET['from_date'] ?? ''));
$requestedTo = trim((string) ($_GET['to_date'] ?? ''));
$rangePreset = strtolower(trim((string) ($_GET['range_preset'] ?? '')));
$allowedRangePresets = ['today', 'yesterday', 'past_3_days', 'past_7_days', 'past_2_weeks', 'past_30_days', 'current_month', 'current_quarter', 'custom'];
if (!in_array($rangePreset, $allowedRangePresets, true)) {
    $rangePreset = $requestedFrom !== '' || $requestedTo !== '' ? 'custom' : 'today';
}
$presetDays = [
    'today' => 0,
    'yesterday' => 1,
    'past_3_days' => 2,
    'past_7_days' => 6,
    'past_2_weeks' => 13,
    'past_30_days' => 29,
];
if ($rangePreset !== 'custom' && isset($presetDays[$rangePreset])) {
    $toDate = $rangePreset === 'yesterday' ? $today->modify('-1 day') : $today;
    $fromDate = $toDate->modify('-' . $presetDays[$rangePreset] . ' days');
} elseif ($rangePreset === 'current_month') {
    $fromDate = $anchor->modify('first day of this month');
    $toDate = $anchor->modify('last day of this month');
} elseif ($rangePreset === 'current_quarter') {
    $quarterStartMonth = ((int) floor(((int) $anchor->format('n') - 1) / 3) * 3) + 1;
    $fromDate = $anchor->setDate((int) $anchor->format('Y'), $quarterStartMonth, 1);
    $toDate = $fromDate->modify('+2 months')->modify('last day of this month');
} elseif (sales_report_valid_date($requestedFrom) && sales_report_valid_date($requestedTo)) {
    $fromDate = new DateTimeImmutable($requestedFrom);
    $toDate = new DateTimeImmutable($requestedTo);
    if ($fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];
} else {
    [$fromDate, $toDate] = sales_report_range_for_period($period, $anchor);
}
if ($fromDate > $today) $fromDate = $today;
if ($toDate > $today) $toDate = $today;
if ($fromDate > $toDate) $fromDate = $toDate;
$from = $fromDate->format('Y-m-d');
$to = $toDate->format('Y-m-d');

$rows = sales_report_rows($conn, $from, $to);
$totalSales = array_sum(array_map(static fn(array $row): float => (float) $row['total_amount'], $rows));
$completedTests = array_sum(array_map(static fn(array $row): int => (int) $row['quantity'], $rows));
$formatDateRange = static function (DateTimeInterface $start, DateTimeInterface $end): string {
    $startLabel = $start->format('F j, Y');
    return $start->format('Y-m-d') === $end->format('Y-m-d')
        ? $startLabel
        : $startLabel . ' - ' . $end->format('F j, Y');
};

$periodCards = [];
$periodRows = [];
foreach (['daily', 'monthly', 'quarterly'] as $cardPeriod) {
    [$cardFromDate, $cardToDate] = sales_report_range_for_period($cardPeriod, $anchor);
    if ($cardFromDate > $today) $cardFromDate = $today;
    if ($cardToDate > $today) $cardToDate = $today;
    if ($cardFromDate > $cardToDate) $cardFromDate = $cardToDate;
    $cardRows = sales_report_rows($conn, $cardFromDate->format('Y-m-d'), $cardToDate->format('Y-m-d'));
    $periodRows[$cardPeriod] = [
        'from' => $cardFromDate,
        'to' => $cardToDate,
        'rows' => $cardRows,
    ];
    $periodCards[$cardPeriod] = [
        'sales' => array_sum(array_map(static fn(array $row): float => (float) $row['total_amount'], $cardRows)),
        'tests' => array_sum(array_map(static fn(array $row): int => (int) $row['quantity'], $cardRows)),
    ];
}

$dailySales = [];
$cursor = $fromDate;
while ($cursor <= $toDate) { $dailySales[$cursor->format('Y-m-d')] = 0.0; $cursor = $cursor->modify('+1 day'); }
foreach ($rows as $row) if (array_key_exists($row['date'], $dailySales)) $dailySales[$row['date']] += (float) $row['total_amount'];

$formatMoney = static fn(float $amount): string => sales_report_money($amount);
$reportViews = [
    'current' => [
        'from' => $fromDate,
        'to' => $toDate,
        'rows' => $rows,
        'sales' => $totalSales,
        'tests' => $completedTests,
    ],
];
foreach ($periodRows as $viewKey => $viewData) {
    $reportViews[$viewKey] = [
        'from' => $viewData['from'],
        'to' => $viewData['to'],
        'rows' => $viewData['rows'],
        'sales' => array_sum(array_map(static fn(array $row): float => (float) $row['total_amount'], $viewData['rows'])),
        'tests' => array_sum(array_map(static fn(array $row): int => (int) $row['quantity'], $viewData['rows'])),
    ];
}
$rangeLabel = $formatDateRange($fromDate, $toDate);
$periodCardSubtitles = [
    'daily' => $anchor->format('M j, Y'),
    'monthly' => $anchor->format('F Y'),
    'quarterly' => 'Q' . (int) ceil((int) $anchor->format('n') / 3) . ' ' . $anchor->format('Y'),
];
if (strtolower((string) ($_GET['format'] ?? '')) === 'pdf') sales_report_output_pdf($rows, $from, $to, $totalSales, $completedTests);
$dailyTrendFromDate = $anchor->modify('-6 days');
$dailyTrendRows = sales_report_rows($conn, $dailyTrendFromDate->format('Y-m-d'), $anchor->format('Y-m-d'));
$conn->close();

$chartWidth = 1080; $chartHeight = 350; $chartLeft = 78; $chartRight = 28; $chartTop = 28; $chartBottom = 58;
$plotWidth = $chartWidth - $chartLeft - $chartRight; $plotHeight = $chartHeight - $chartTop - $chartBottom;
$chartMax = max(100.0, max($dailySales ?: [0.0]));
$chartTick = max(25.0, ceil($chartMax / 4 / 100) * 100); $chartMax = max($chartTick * 4, 100.0);
$chartPoints = []; $pointCount = count($dailySales); $pointIndex = 0;
foreach ($dailySales as $date => $amount) {
    $x = $chartLeft + ($pointCount > 1 ? ($pointIndex / ($pointCount - 1)) * $plotWidth : $plotWidth / 2);
    $y = $chartTop + $plotHeight - (($amount / $chartMax) * $plotHeight);
    $chartPoints[] = ['date' => $date, 'amount' => $amount, 'x' => $x, 'y' => $y]; $pointIndex++;
}
$chartPolyline = implode(' ', array_map(static fn(array $point): string => number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', ''), $chartPoints));
$labelStep = max(1, (int) ceil(max(1, $pointCount) / 12));
$chartBaseline = $chartTop + $plotHeight;
$chartAreaPoints = $chartPolyline;
if ($chartPoints) {
    $chartAreaPoints .= ' ' . number_format($chartPoints[count($chartPoints) - 1]['x'], 2, '.', '') . ',' . $chartBaseline;
    $chartAreaPoints .= ' ' . number_format($chartPoints[0]['x'], 2, '.', '') . ',' . $chartBaseline;
}

$buildSalesChartView = static function (array $dailyValues, DateTimeImmutable $viewFrom, DateTimeImmutable $viewTo) use ($chartWidth, $chartHeight, $chartLeft, $chartRight, $chartTop, $chartBottom, $plotWidth, $plotHeight): array {
    $viewMax = max(100.0, max($dailyValues ?: [0.0]));
    $viewTick = max(25.0, ceil($viewMax / 4 / 100) * 100);
    $viewMax = max($viewTick * 4, 100.0);
    $viewPoints = [];
    $viewPointCount = count($dailyValues);
    $viewPointIndex = 0;
    foreach ($dailyValues as $date => $amount) {
        $x = $chartLeft + ($viewPointCount > 1 ? ($viewPointIndex / ($viewPointCount - 1)) * $plotWidth : $plotWidth / 2);
        $y = $chartTop + $plotHeight - (((float) $amount / $viewMax) * $plotHeight);
        $viewPoints[] = ['date' => $date, 'amount' => (float) $amount, 'x' => $x, 'y' => $y];
        $viewPointIndex++;
    }
    $viewPolyline = implode(' ', array_map(static fn(array $point): string => number_format($point['x'], 2, '.', '') . ',' . number_format($point['y'], 2, '.', ''), $viewPoints));
    $viewArea = $viewPolyline;
    if ($viewPoints) {
        $viewArea .= ' ' . number_format($viewPoints[count($viewPoints) - 1]['x'], 2, '.', '') . ',' . ($chartTop + $plotHeight);
        $viewArea .= ' ' . number_format($viewPoints[0]['x'], 2, '.', '') . ',' . ($chartTop + $plotHeight);
    }
    return [
        'points' => $viewPoints,
        'polyline' => $viewPolyline,
        'area' => $viewArea,
        'chart_max' => $viewMax,
        'label_step' => max(1, (int) ceil(max(1, $viewPointCount) / 12)),
        'range_label' => $viewFrom->format('F j, Y') . ' - ' . $viewTo->format('F j, Y'),
    ];
};

$chartViews = [
    'current' => [
        'points' => $chartPoints,
        'polyline' => $chartPolyline,
        'area' => $chartAreaPoints,
        'chart_max' => $chartMax,
        'label_step' => $labelStep,
        'range_label' => $rangeLabel,
    ],
];
foreach (['daily', 'monthly', 'quarterly'] as $chartPeriod) {
    $viewDailySales = [];
    $viewFrom = $periodRows[$chartPeriod]['from'];
    $viewTo = $periodRows[$chartPeriod]['to'];
    $viewCursor = $viewFrom;
    while ($viewCursor <= $viewTo) {
        $viewDailySales[$viewCursor->format('Y-m-d')] = 0.0;
        $viewCursor = $viewCursor->modify('+1 day');
    }
    foreach ($periodRows[$chartPeriod]['rows'] as $periodRow) {
        if (array_key_exists($periodRow['date'], $viewDailySales)) {
            $viewDailySales[$periodRow['date']] += (float) $periodRow['total_amount'];
        }
    }
    $chartViews[$chartPeriod] = $buildSalesChartView($viewDailySales, $viewFrom, $viewTo);
}
$dailyTrendValues = [];
$dailyTrendCursor = $dailyTrendFromDate;
while ($dailyTrendCursor <= $anchor) {
    $dailyTrendValues[$dailyTrendCursor->format('Y-m-d')] = 0.0;
    $dailyTrendCursor = $dailyTrendCursor->modify('+1 day');
}
foreach ($dailyTrendRows as $dailyTrendRow) {
    if (array_key_exists($dailyTrendRow['date'], $dailyTrendValues)) {
        $dailyTrendValues[$dailyTrendRow['date']] += (float) $dailyTrendRow['total_amount'];
    }
}
$chartViews['daily'] = $buildSalesChartView($dailyTrendValues, $dailyTrendFromDate, $anchor);
foreach ($chartViews as $viewKey => &$chartView) {
    $viewData = $reportViews[$viewKey];
    $chartView['from'] = $viewData['from']->format('Y-m-d');
    $chartView['to'] = $viewData['to']->format('Y-m-d');
    $chartView['sales'] = (float) $viewData['sales'];
    $chartView['tests'] = (int) $viewData['tests'];
    $chartView['report_range_label'] = $formatDateRange($viewData['from'], $viewData['to']);
}
unset($chartView);
$allowedViewModes = ['current', 'daily', 'monthly', 'quarterly'];
$viewMode = strtolower(trim((string) ($_GET['view_mode'] ?? '')));
if (!in_array($viewMode, $allowedViewModes, true)) $viewMode = in_array($period, ['daily', 'monthly', 'quarterly'], true) ? $period : 'current';
$showGraph = $showTrend && $viewMode !== 'daily';
$selectedViewKey = $showTrend && isset($reportViews[$viewMode]) ? $viewMode : 'current';
$selectedReportView = $reportViews[$selectedViewKey];
$displayFrom = $selectedReportView['from']->format('Y-m-d');
$displayTo = $selectedReportView['to']->format('Y-m-d');
$displayRangeLabel = $formatDateRange($selectedReportView['from'], $selectedReportView['to']);
$displayTrendRangeLabel = $chartViews[$selectedViewKey]['range_label'] ?? $displayRangeLabel;
$displayTotalSales = (float) $selectedReportView['sales'];
$displayCompletedTests = (int) $selectedReportView['tests'];
$displayRangePreset = $selectedViewKey === 'current' ? $rangePreset : ($viewMode === 'monthly' ? 'current_month' : ($viewMode === 'quarterly' ? 'current_quarter' : 'today'));
$displaySummaryLabel = $displayRangeLabel;
if ($selectedViewKey === 'daily') $displaySummaryLabel = $anchor->format('F j, Y');
if ($selectedViewKey === 'monthly') $displaySummaryLabel = $anchor->format('F Y');
if ($selectedViewKey === 'quarterly') $displaySummaryLabel = 'Q' . (int) ceil((int) $anchor->format('n') / 3) . ' ' . $anchor->format('Y') . ' to date';
$viewSummaryLabels = [
    'current' => $displayRangeLabel,
    'daily' => $anchor->format('F j, Y'),
    'monthly' => $anchor->format('F Y'),
    'quarterly' => 'Q' . (int) ceil((int) $anchor->format('n') / 3) . ' ' . $anchor->format('Y') . ' to date',
];

$additionalStyles = '
body{background:#f4f8fb;color:#1f343d}.sales-wrap{max-width:1240px;margin:0 auto;padding:24px 20px 44px}.sales-wrap>section{padding:0}.sales-print-heading{display:none}.sales-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:14px}.sales-card{min-height:118px;box-sizing:border-box;padding:20px 22px;border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06)}.sales-card-label{display:block;color:#607784;font-size:.78rem;font-weight:900;letter-spacing:.04em;text-transform:uppercase}.sales-card strong{display:block;margin-top:11px;color:#0066cc;font-size:1.75rem;line-height:1}.sales-card small{display:block;margin-top:10px;color:#708792}.sales-period-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:14px;scroll-margin-top:76px}.sales-period-card{width:100%;min-height:118px;box-sizing:border-box;padding:18px 20px;border:1px solid #dce8ef;border-radius:8px;background:#fff;color:#1f343d;box-shadow:0 10px 24px rgba(25,76,110,.06);cursor:pointer;font:inherit;text-align:left;transition:border-color .2s ease,background .2s ease,box-shadow .2s ease,transform .2s ease}.sales-period-card:hover,.sales-period-card:focus-visible{border-color:#8ed9ef;background:#f8fcff;box-shadow:0 12px 26px rgba(25,76,110,.10);outline:none;transform:translateY(-1px)}.sales-period-card.is-active{border-color:#0f7cc2;background:#eef8ff;box-shadow:0 12px 28px rgba(15,124,194,.13)}.sales-period-card-label{display:block;color:#607784;font-size:.78rem;font-weight:900;letter-spacing:.04em;text-transform:uppercase}.sales-period-card strong{display:block;margin-top:9px;color:#0066cc;font-size:1.8rem;line-height:1}.sales-period-card small{display:block;margin-top:8px;color:#708792;font-size:.82rem}.sales-control-row{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px}.sales-controls{display:flex;align-items:flex-end;justify-content:flex-start;gap:10px;flex-wrap:wrap;max-width:100%}.sales-date-form{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;max-width:100%}.sales-range-field{display:grid;gap:5px}.sales-range-field label{color:#073b4c;font-size:.76rem;font-weight:900}.sales-date-form input,.sales-date-form select{width:155px;min-height:40px;box-sizing:border-box;border:1px solid #cfe1ee;border-radius:8px;padding:8px 10px;color:#1f343d;background:#fff;font:inherit}.sales-date-form input:focus,.sales-date-form select:focus{outline:none;border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.10)}.sales-actions{display:flex;align-items:center;gap:8px;margin:0;flex:0 0 auto}.sales-action{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 12px;border:1px solid #cfe1ee;border-radius:7px;color:#0b4f80;background:#fff;font:inherit;font-size:.8rem;font-weight:900;text-decoration:none;cursor:pointer}.sales-action.primary{color:#fff;border-color:#0f7cc2;background:#0f7cc2}.sales-panel{padding:22px;margin-bottom:14px;border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06)}.sales-panel-head{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin-bottom:15px}.sales-panel h3{margin:0;color:#073b4c;font-size:1.2rem}.sales-panel-head span{color:#607784;font-size:.84rem}.sales-table-wrap{overflow-x:auto}.sales-table{width:100%;min-width:650px;border-collapse:collapse}.sales-table th,.sales-table td{padding:12px 14px;border-bottom:1px solid #edf3f6;text-align:left;vertical-align:top}.sales-table th{color:#607784;font-size:.74rem;font-weight:900;letter-spacing:.05em;text-transform:uppercase;background:#f8fbfd}.sales-table td{color:#405b67;font-size:.9rem}.sales-table td strong{color:#073b4c}.sales-table .number{text-align:right;white-space:nowrap}.sales-table tfoot th{border-top:1px solid #d8e6ed;border-bottom:0;color:#073b4c;font-size:.92rem;background:#fff}.sales-chart{display:block;width:100%;height:auto;overflow:visible}.sales-chart .grid-line{stroke:#e5eef3;stroke-width:1}.sales-chart .axis-line{stroke:#b7cbd6;stroke-width:1.2}.sales-chart .trend-area{fill:rgba(15,124,194,.10);opacity:0;animation:salesTrendFade .65s ease-out .1s forwards}.sales-chart .trend-line{fill:none;stroke:#0077b6;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:1800;stroke-dashoffset:1800;animation:salesTrendDraw .8s ease-out forwards}.sales-chart .trend-dot{fill:#fff;stroke:#0077b6;stroke-width:2;opacity:0;animation:salesTrendFade .35s ease-out .25s forwards}.sales-chart text{fill:#607784;font-family:inherit;font-size:11px}.sales-chart .axis-title{fill:#073b4c;font-size:11px;font-weight:800}.sales-empty{padding:35px 10px;color:#607784;text-align:center;font-weight:700}@keyframes salesTrendDraw{to{stroke-dashoffset:0}}@keyframes salesTrendFade{to{opacity:1}}@media(max-width:1100px){.sales-period-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.sales-control-row{align-items:stretch;flex-direction:column;gap:12px}.sales-controls{justify-content:flex-start;width:100%}.sales-date-form{width:100%}.sales-range-field{flex:1 1 180px}}@media(max-width:760px){.sales-wrap{padding:22px 16px 42px}.sales-period-cards{grid-template-columns:1fr;gap:12px}.sales-control-row{gap:16px}.sales-controls,.sales-date-form{width:100%}.sales-date-form{align-items:stretch;flex-direction:column}.sales-range-field,.sales-date-form input,.sales-date-form select{width:100%}.sales-actions{flex-wrap:wrap}.sales-action{flex:1}.sales-cards{grid-template-columns:1fr}.sales-card{min-height:0}.sales-panel{padding:16px 12px;overflow:hidden}.sales-panel-head{align-items:flex-start;flex-direction:column;gap:5px}.sales-chart text{font-size:9px}}@media print{body{background:#fff!important}.no-print,.main-header,.sidebar,nav{display:none!important}.sales-wrap{max-width:none;padding:0}.sales-panel,.sales-card,.sales-period-card{box-shadow:none;break-inside:avoid}}';
$additionalStyles .= '@media print{header#mainHeader{display:block!important;position:static!important;background:#fff!important;color:#073b4c!important;box-shadow:none!important}header#mainHeader .header-flex{justify-content:flex-start!important;max-width:none!important;padding:0 0 16px!important}header#mainHeader .logo-section{color:#073b4c!important}header#mainHeader .mobile-menu-toggle,header#mainHeader nav,.patient-app-topbar,.patient-notification-wrap,.patient-topbar-menu-wrap{display:none!important}}';
$additionalStyles .= '@media print{.sales-print-heading{display:block!important;margin:12px 0 20px;padding-bottom:14px;border-bottom:1px solid #b7cbd6}.sales-print-heading h2{margin:0 0 8px;color:#073b4c;font-size:20pt;line-height:1.1;text-transform:uppercase}.sales-print-heading p{margin:4px 0;color:#405b67;font-size:9pt}.sales-period-cards,.sales-control-row,.sales-trend-panel,.sales-pagination{display:none!important}.sales-wrap{padding:0 0 20px}.sales-cards{gap:28px;margin:0 0 24px}.sales-card{min-height:0;padding:14px 16px;border:1px solid #cfe1ee;border-radius:0;box-shadow:none}.sales-card strong{margin-top:8px;font-size:18pt}.sales-card small{margin-top:7px;font-size:9pt}.sales-panel{padding:0;margin:0;border:0;border-radius:0;box-shadow:none}.sales-panel-head{display:none}.sales-table-wrap{overflow:visible}.sales-table{min-width:0;width:100%;table-layout:fixed}.sales-table th,.sales-table td{padding:8px 7px;border-bottom:1px solid #d8e6ed;font-size:8.5pt}.sales-table th{font-size:7.5pt;background:#f3f8fc}.sales-table th:nth-child(1),.sales-table td:nth-child(1){width:15%}.sales-table th:nth-child(2),.sales-table td:nth-child(2){width:38%}.sales-table th:nth-child(3),.sales-table td:nth-child(3){width:9%}.sales-table th:nth-child(4),.sales-table td:nth-child(4){width:20%}.sales-table th:nth-child(5),.sales-table td:nth-child(5){width:18%}.sales-table td strong{font-weight:700}.sales-table tfoot th{border-top:1px solid #b7cbd6;font-size:9pt;background:#fff}.sales-table tr{break-inside:avoid;page-break-inside:avoid}}';
$additionalStyles .= '.sales-range-select-wrap{display:flex;align-items:center;gap:8px}.sales-range-edit{min-height:40px;padding:8px 11px;border:1px solid #cfe1ee;border-radius:8px;color:#0b4f80;background:#fff;font:inherit;font-size:.78rem;font-weight:900;cursor:pointer;white-space:nowrap}.sales-range-edit:hover,.sales-range-edit:focus-visible{border-color:#0f7cc2;background:#eef8ff;outline:none}.sales-range-edit[hidden]{display:none}@media(max-width:760px){.sales-range-select-wrap{align-items:stretch;flex-direction:column}.sales-range-select-wrap select{width:100%!important}.sales-range-edit{width:100%}}';
$additionalStyles .= '.sales-pagination-row td{padding:0;border-top:1px solid #edf3f6}.sales-pagination-row[hidden]{display:none}.sales-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;color:#607784;font-size:.8rem}.sales-pagination-controls{display:flex;align-items:center;gap:5px}.sales-pagination button,.sales-pagination-page{min-width:32px;min-height:32px;padding:5px 9px;border:1px solid #cfe1ee;border-radius:7px;color:#0b4f80;background:#fff;font:inherit;font-size:.78rem;font-weight:800;cursor:pointer}.sales-pagination button:hover,.sales-pagination-page:hover,.sales-pagination-page.is-active{border-color:#0f7cc2;color:#fff;background:#0f7cc2}.sales-pagination button:disabled{opacity:.45;cursor:not-allowed}.sales-pagination-page{display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.sales-pagination-page[hidden]{display:none}@media(max-width:600px){.sales-pagination{align-items:stretch;flex-direction:column}.sales-pagination-controls{justify-content:space-between}.sales-pagination button,.sales-pagination-page{flex:1}.sales-pagination-controls [data-sales-page-buttons]{display:flex;flex:1;gap:5px}.sales-pagination-controls [data-sales-page-buttons] .sales-pagination-page{flex:1}}';
$additionalStyles .= '.sales-pagination-row td{padding:0!important;border-bottom:0!important;background:#fff}.sales-pagination-row .sales-pagination{border-top:1px solid #edf3f6}';
$additionalStyles .= '.sales-range-open{overflow:hidden}.sales-range-modal{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:20px;background:rgba(7,59,76,.42)}.sales-range-modal[hidden]{display:none}.sales-range-dialog{width:min(900px,100%);max-height:min(680px,calc(100vh - 40px));overflow:auto;border:1px solid #cfe1ee;border-radius:12px;background:#fff;box-shadow:0 22px 60px rgba(7,59,76,.24)}.sales-range-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;border-bottom:1px solid #e5eef3}.sales-range-dialog-header h2{margin:0;color:#073b4c;font-size:1.05rem}.sales-range-close,.sales-range-nav{display:inline-flex;align-items:center;justify-content:center;border:1px solid #d8e6ed;border-radius:7px;color:#0b4f80;background:#f8fbfd;font:inherit;font-weight:900;cursor:pointer}.sales-range-close{width:34px;height:34px;font-size:1.25rem;line-height:1}.sales-range-close:hover,.sales-range-nav:hover{border-color:#8ed9ef;background:#eef8ff}.sales-range-calendars{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.sales-range-month{padding:20px 22px}.sales-range-month+ .sales-range-month{border-left:1px solid #e5eef3}.sales-range-month-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:16px}.sales-range-month-head strong{color:#073b4c;font-size:1rem}.sales-range-nav{width:30px;height:30px;font-size:1rem}.sales-range-weekdays,.sales-range-days{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}.sales-range-weekdays{margin-bottom:8px;color:#708792;font-size:.72rem;font-weight:900;text-align:center}.sales-range-day{position:relative;min-width:0;aspect-ratio:1;border:1px solid transparent;border-radius:7px;color:#1f343d;background:#fff;font:inherit;font-size:.84rem;cursor:pointer}.sales-range-day:hover:not(:disabled){border-color:#8ed9ef;background:#eef8ff}.sales-range-day.is-outside{color:#b9c8d0;background:#f8fbfd;cursor:default}.sales-range-day:disabled{color:#b9c8d0;background:#f8fbfd;cursor:not-allowed}.sales-range-day.is-in-range{border-radius:0;background:#eaf6fb;color:#0b4f80}.sales-range-day.is-start,.sales-range-day.is-end{border-color:#0f7cc2;border-radius:7px;color:#fff;background:#0f7cc2;font-weight:900}.sales-range-day.is-start.is-end{border-radius:7px}.sales-range-day.is-start{border-top-right-radius:0;border-bottom-right-radius:0}.sales-range-day.is-end{border-top-left-radius:0;border-bottom-left-radius:0}.sales-range-footer{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 22px;border-top:1px solid #e5eef3;background:#fbfdfe}.sales-range-values{display:flex;align-items:center;gap:8px;min-width:0}.sales-range-value{min-width:145px;padding:9px 11px;border:1px solid #cfe1ee;border-radius:7px;color:#405b67;background:#fff;font-size:.84rem}.sales-range-separator{color:#8aa0ac}.sales-range-actions{display:flex;align-items:center;gap:8px}.sales-range-action{min-height:38px;padding:8px 16px;border:1px solid #cfe1ee;border-radius:7px;color:#0b4f80;background:#fff;font:inherit;font-size:.82rem;font-weight:900;cursor:pointer}.sales-range-action.primary{border-color:#0f7cc2;color:#fff;background:#0f7cc2}.sales-range-action:disabled{opacity:.5;cursor:not-allowed}@media(max-width:700px){.sales-range-modal{padding:10px}.sales-range-dialog{max-height:calc(100vh - 20px)}.sales-range-calendars{grid-template-columns:1fr}.sales-range-month{padding:16px}.sales-range-month+ .sales-range-month{border-top:1px solid #e5eef3;border-left:0}.sales-range-footer{align-items:stretch;flex-direction:column}.sales-range-values,.sales-range-actions{width:100%}.sales-range-value{flex:1;min-width:0}.sales-range-action{flex:1}}';
$additionalStyles .= '.sales-range-modal,.sales-range-dialog,.sales-range-footer,.sales-range-action{pointer-events:auto}.sales-range-dialog{position:relative;z-index:1}.sales-range-footer{position:relative;z-index:2}.sales-range-action{position:relative;z-index:3;appearance:none}.sales-range-message{margin:8px 0 0;color:#b42318;font-size:.78rem;font-weight:700}.sales-range-message[hidden]{display:none}@media(max-width:700px){.sales-range-message{margin-top:0}}';
$additionalStyles .= '.sales-range-nav:disabled{border-color:#d9e3e8;color:#9aaeb9;background:#f3f6f8;cursor:not-allowed;opacity:1}.sales-range-nav:disabled:hover{border-color:#d9e3e8;background:#f3f6f8}.sales-range-month-title{display:inline-flex;align-items:center;justify-content:center;gap:4px;min-width:0;color:#073b4c;font-size:1rem;font-weight:900}.sales-range-year-select{min-width:72px;padding:3px 20px 3px 5px;border:1px solid transparent;border-radius:5px;color:#073b4c;background:#fff;font:inherit;font-weight:900;cursor:pointer}.sales-range-year-select:hover,.sales-range-year-select:focus-visible{border-color:#8ed9ef;outline:none;background:#eef7ff}';

require_once __DIR__ . '/includes/header.php';
?>
<main class="sales-wrap">
    <div class="sales-print-heading" aria-hidden="true"><h2>Laboratory Sales Report</h2><p>Period: <?php echo htmlspecialchars($displayRangeLabel); ?></p><p>Completed and paid laboratory transactions only</p></div>
    <section class="sales-cards" aria-label="Laboratory sales summary"><article class="sales-card"><span class="sales-card-label">Total Laboratory Sales</span><strong id="salesTotalSalesValue"><?php echo htmlspecialchars($formatMoney($displayTotalSales)); ?></strong><small id="salesSummaryRange"><?php echo htmlspecialchars($displaySummaryLabel); ?></small></article><article class="sales-card"><span class="sales-card-label">Completed Tests</span><strong id="salesTotalTestsValue"><?php echo number_format($displayCompletedTests); ?></strong></article></section>
    <section class="sales-period-cards no-print" aria-label="Laboratory sales report periods">
        <?php foreach (['daily' => 'Daily', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly'] as $cardPeriod => $cardLabel): ?>
            <button class="sales-period-card<?php echo $viewMode === $cardPeriod ? ' is-active' : ''; ?>" type="button" data-sales-period="<?php echo $cardPeriod; ?>" aria-pressed="<?php echo $viewMode === $cardPeriod ? 'true' : 'false'; ?>">
                <span class="sales-period-card-label"><?php echo $cardLabel; ?></span>
                <strong><?php echo htmlspecialchars($formatMoney((float) $periodCards[$cardPeriod]['sales'])); ?></strong>
                <small><?php echo htmlspecialchars($periodCardSubtitles[$cardPeriod]); ?></small>
            </button>
        <?php endforeach; ?>
    </section>
    <section class="sales-control-row no-print" aria-label="Sales report filters">
        <div class="sales-controls"><form class="sales-date-form" method="get" id="salesFilterForm">
            <input type="hidden" name="period" id="salesPeriod" value="<?php echo htmlspecialchars($period, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="view_mode" id="salesViewMode" value="<?php echo htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="anchor_date" id="salesAnchorDate" value="<?php echo htmlspecialchars($anchorDate, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="show_trend" id="salesShowTrend" value="<?php echo $showTrend ? '1' : '0'; ?>">
            <div class="sales-range-field"><label for="salesRangePreset">Date Range</label><div class="sales-range-select-wrap"><select id="salesRangePreset" name="range_preset" data-today="<?php echo htmlspecialchars($today->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'past_3_days' => 'Past 3 Days', 'past_7_days' => 'Past 7 Days', 'past_2_weeks' => 'Past 2 Weeks', 'past_30_days' => 'Past 30 Days', 'current_month' => 'Current Month', 'current_quarter' => 'Current Quarter', 'custom' => 'Custom Date Range'] as $rangeValue => $rangeText): ?><option value="<?php echo $rangeValue; ?>"<?php echo $displayRangePreset === $rangeValue ? ' selected' : ''; ?>><?php echo $rangeText; ?></option><?php endforeach; ?>
            </select><button type="button" class="sales-range-edit" id="editCustomRange" hidden>Edit dates</button></div></div>
            <input type="hidden" id="salesFromDate" name="from_date" value="<?php echo htmlspecialchars($displayFrom, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" id="salesToDate" name="to_date" value="<?php echo htmlspecialchars($displayTo, ENT_QUOTES, 'UTF-8'); ?>">
        </form></div>
        <div class="sales-actions"><button type="button" class="sales-action primary" id="printSalesReport">Print Report</button><a class="sales-action" id="exportSalesPdf" download="laboratory_sales_report.pdf" href="admin_lab_sales_report.php?format=pdf&amp;period=<?php echo urlencode($period); ?>&amp;range_preset=<?php echo urlencode($rangePreset); ?>&amp;anchor_date=<?php echo urlencode($anchorDate); ?>&amp;from_date=<?php echo urlencode($from); ?>&amp;to_date=<?php echo urlencode($to); ?>">Download PDF</a></div>
    </section>
    <section class="sales-panel sales-trend-panel" id="salesTrendPanel" aria-label="Sales trend" style="padding-top:10px" aria-hidden="<?php echo $showGraph ? 'false' : 'true'; ?>"<?php echo $showGraph ? '' : ' hidden'; ?>>
        <div id="salesTrendViews">
        <?php foreach ($chartViews as $viewKey => $view): ?><div class="sales-chart-view" data-sales-chart-view="<?php echo htmlspecialchars($viewKey, ENT_QUOTES, 'UTF-8'); ?>" data-range-label="<?php echo htmlspecialchars($view['range_label'], ENT_QUOTES, 'UTF-8'); ?>" data-from-date="<?php echo htmlspecialchars($view['from'], ENT_QUOTES, 'UTF-8'); ?>" data-to-date="<?php echo htmlspecialchars($view['to'], ENT_QUOTES, 'UTF-8'); ?>" data-report-range-label="<?php echo htmlspecialchars($formatDateRange($reportViews[$viewKey]['from'], $reportViews[$viewKey]['to']), ENT_QUOTES, 'UTF-8'); ?>" data-summary-label="<?php echo htmlspecialchars($viewSummaryLabels[$viewKey] ?? $displayRangeLabel, ENT_QUOTES, 'UTF-8'); ?>" data-report-from-date="<?php echo htmlspecialchars($reportViews[$viewKey]['from']->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" data-report-to-date="<?php echo htmlspecialchars($reportViews[$viewKey]['to']->format('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" data-sales-total="<?php echo htmlspecialchars($formatMoney((float) $view['sales']), ENT_QUOTES, 'UTF-8'); ?>" data-completed-tests="<?php echo (int) $view['tests']; ?>"<?php echo !$showGraph || $viewKey !== $selectedViewKey ? ' hidden' : ''; ?>>
            <?php if ($view['points']): ?><svg class="sales-chart" viewBox="0 0 <?php echo $chartWidth; ?> <?php echo $chartHeight; ?>" role="img" aria-label="Daily laboratory sales trend">
                <?php for ($tick = 0; $tick <= 4; $tick++): ?><?php $tickY = $chartTop + $plotHeight - (($tick / 4) * $plotHeight); ?><line class="grid-line" x1="<?php echo $chartLeft; ?>" y1="<?php echo number_format($tickY, 2, '.', ''); ?>" x2="<?php echo $chartWidth - $chartRight; ?>" y2="<?php echo number_format($tickY, 2, '.', ''); ?>"></line><text x="<?php echo $chartLeft - 10; ?>" y="<?php echo number_format($tickY + 4, 2, '.', ''); ?>" text-anchor="end">₱<?php echo number_format($view['chart_max'] * ($tick / 4), 0); ?></text><?php endfor; ?>
                <line class="axis-line" x1="<?php echo $chartLeft; ?>" y1="<?php echo $chartTop; ?>" x2="<?php echo $chartLeft; ?>" y2="<?php echo $chartTop + $plotHeight; ?>"></line><line class="axis-line" x1="<?php echo $chartLeft; ?>" y1="<?php echo $chartTop + $plotHeight; ?>" x2="<?php echo $chartWidth - $chartRight; ?>" y2="<?php echo $chartTop + $plotHeight; ?>"></line><polygon class="trend-area" points="<?php echo htmlspecialchars($view['area'], ENT_QUOTES, 'UTF-8'); ?>"></polygon><polyline class="trend-line" points="<?php echo htmlspecialchars($view['polyline'], ENT_QUOTES, 'UTF-8'); ?>"></polyline>
                <?php foreach ($view['points'] as $index => $point): ?><circle class="trend-dot" cx="<?php echo number_format($point['x'], 2, '.', ''); ?>" cy="<?php echo number_format($point['y'], 2, '.', ''); ?>" r="3.5"><title><?php echo htmlspecialchars(date('M j, Y', strtotime($point['date'])) . ': ' . $formatMoney((float) $point['amount']), ENT_QUOTES, 'UTF-8'); ?></title></circle><?php if ($index % $view['label_step'] === 0 || $index === count($view['points']) - 1): ?><text x="<?php echo number_format($point['x'], 2, '.', ''); ?>" y="<?php echo $chartTop + $plotHeight + 25; ?>" text-anchor="middle"><?php echo htmlspecialchars(date('M j', strtotime($point['date']))); ?></text><?php endif; ?><?php endforeach; ?>
            </svg><?php else: ?><div class="sales-empty">No laboratory sales found for this period.</div><?php endif; ?>
        </div><?php endforeach; ?>
        </div>
    </section>
    <section class="sales-panel" id="salesDetailsPanel" aria-labelledby="salesDetailsTitle"><div class="sales-panel-head"><h3 id="salesDetailsTitle">Detailed Sales</h3><span id="salesDetailsRange"><?php echo htmlspecialchars($displayRangeLabel); ?></span></div>
        <div id="salesDetailsViews"><?php foreach ($reportViews as $viewKey => $viewData): ?><div data-sales-details-view="<?php echo htmlspecialchars($viewKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $viewKey !== $selectedViewKey ? ' hidden' : ''; ?>><?php if ($viewData['rows']): ?><div class="sales-table-wrap"><table class="sales-table" data-sales-detail-table><thead><tr><th>Date</th><th>Laboratory Test / Package</th><th class="number">Quantity</th><th class="number">Unit Price</th><th class="number">Total Amount</th></tr></thead><tbody data-sales-detail-body><?php foreach ($viewData['rows'] as $row): ?><tr><td><?php echo htmlspecialchars(date('M j, Y', strtotime((string) $row['date']))); ?></td><td><strong><?php echo htmlspecialchars((string) $row['service']); ?></strong></td><td class="number"><?php echo number_format((int) $row['quantity']); ?></td><td class="number"><?php echo htmlspecialchars((string) $row['unit_price']); ?></td><td class="number"><?php echo htmlspecialchars($formatMoney((float) $row['total_amount'])); ?></td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="4">Total Laboratory Sales</th><th class="number"><?php echo htmlspecialchars($formatMoney((float) $viewData['sales'])); ?></th></tr></tfoot></table></div><div class="sales-pagination no-print" data-sales-pagination><span data-sales-page-info>Showing 1-10 of <?php echo count($viewData['rows']); ?></span><div class="sales-pagination-controls"><button type="button" data-sales-page="prev" aria-label="Previous sales page">Previous</button><span data-sales-page-buttons></span><button type="button" data-sales-page="next" aria-label="Next sales page">Next</button></div></div><?php else: ?><div class="sales-empty">No laboratory sales found for this period.</div><?php endif; ?></div><?php endforeach; ?></div>
    </section>
</main>
<div class="sales-range-modal no-print" id="salesRangeModal" hidden aria-hidden="true">
    <section class="sales-range-dialog" role="dialog" aria-modal="true" aria-labelledby="salesRangeTitle">
        <div class="sales-range-dialog-header"><h2 id="salesRangeTitle">Custom Date Range</h2><button type="button" class="sales-range-close" id="salesRangeClose" aria-label="Close date range picker">&times;</button></div>
        <div class="sales-range-calendars" id="salesRangeCalendars"></div>
        <div class="sales-range-footer"><div><div class="sales-range-values" aria-live="polite"><span class="sales-range-value" id="salesRangeFromValue">Select start date</span><span class="sales-range-separator" aria-hidden="true">&ndash;</span><span class="sales-range-value" id="salesRangeToValue">Select end date</span></div><p class="sales-range-message" id="salesRangeMessage" hidden>Select a start date and an end date first.</p></div><div class="sales-range-actions"><button type="button" class="sales-range-action" id="salesRangeCancel">Cancel</button><button type="button" class="sales-range-action primary" id="salesRangeSet" aria-disabled="true">Set Date</button></div></div>
    </section>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
    var f=document.getElementById('salesFilterForm'),a=document.getElementById('salesFromDate'),b=document.getElementById('salesToDate'),p=document.getElementById('salesPeriod'),viewMode=document.getElementById('salesViewMode'),h=document.getElementById('salesAnchorDate'),r=document.getElementById('salesRangePreset'),editCustomRange=document.getElementById('editCustomRange'),trendPanel=document.getElementById('salesTrendPanel'),trendRange=document.getElementById('salesTrendRange'),showTrend=document.getElementById('salesShowTrend'),summarySales=document.getElementById('salesTotalSalesValue'),summaryTests=document.getElementById('salesTotalTestsValue'),summaryRange=document.getElementById('salesSummaryRange'),detailsRange=document.getElementById('salesDetailsRange'),pdfLink=document.getElementById('exportSalesPdf'),rangeModal=document.getElementById('salesRangeModal'),rangeCalendars=document.getElementById('salesRangeCalendars'),rangeFromValue=document.getElementById('salesRangeFromValue'),rangeToValue=document.getElementById('salesRangeToValue'),rangeSet=document.getElementById('salesRangeSet'),rangeMessage=document.getElementById('salesRangeMessage'),rangeClose=document.getElementById('salesRangeClose'),rangeCancel=document.getElementById('salesRangeCancel');
    if(!f||!a||!b||!p||!viewMode||!h||!r)return;
    var salesScrollStorageKey='adminLabSalesReportScrollY';
    function localDate(d){return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}
    function setActivePeriod(period,active){document.querySelectorAll('.sales-period-card').forEach(function(button){var selected=active&&button.getAttribute('data-sales-period')===period;button.classList.toggle('is-active',selected);button.setAttribute('aria-pressed',selected?'true':'false')})}
    function focusPeriodCard(period){var card=document.querySelector('.sales-period-card[data-sales-period="'+period+'"]'),cards=document.querySelector('.sales-period-cards');if(!card)return;var rect=cards?cards.getBoundingClientRect():null,viewportHeight=window.innerHeight||document.documentElement.clientHeight;if(rect&&(rect.top<76||rect.bottom>viewportHeight-16)){cards.scrollIntoView({behavior:'smooth',block:'start'})}try{card.focus({preventScroll:true})}catch(error){card.focus()}}
    function updatePdfLink(viewKey,selectedView){if(!pdfLink||!selectedView)return;try{var url=new URL(pdfLink.href,window.location.href);url.searchParams.set('period',viewKey==='current'?(p.value||'daily'):viewKey);url.searchParams.set('range_preset','custom');url.searchParams.set('from_date',selectedView.getAttribute('data-report-from-date')||a.value);url.searchParams.set('to_date',selectedView.getAttribute('data-report-to-date')||b.value);url.searchParams.set('anchor_date',h.value||a.value);pdfLink.href=url.toString()}catch(error){}}
    function showChart(viewKey){var selectedView=null;document.querySelectorAll('[data-sales-chart-view]').forEach(function(view){var selected=view.getAttribute('data-sales-chart-view')===viewKey;view.hidden=!selected;if(selected)selectedView=view});document.querySelectorAll('[data-sales-details-view]').forEach(function(view){view.hidden=view.getAttribute('data-sales-details-view')!==viewKey});if(selectedView){var graphRangeLabel=selectedView.getAttribute('data-range-label')||'',reportRangeLabel=selectedView.getAttribute('data-report-range-label')||graphRangeLabel,summaryLabel=selectedView.getAttribute('data-summary-label')||reportRangeLabel;if(trendRange)trendRange.textContent=graphRangeLabel;if(summarySales)summarySales.textContent=selectedView.getAttribute('data-sales-total')||'₱0.00';if(summaryTests)summaryTests.textContent=selectedView.getAttribute('data-completed-tests')||'0';if(summaryRange)summaryRange.textContent=summaryLabel;if(detailsRange)detailsRange.textContent=reportRangeLabel;if(viewKey!=='current'){if(a)a.value=selectedView.getAttribute('data-report-from-date')||a.value;if(b)b.value=selectedView.getAttribute('data-report-to-date')||b.value;var cardRange=viewKey==='monthly'?'current_month':(viewKey==='quarterly'?'current_quarter':'today');if(r)r.value=cardRange;syncCustomRangeEditor();lastRangePreset=cardRange;viewMode.value=viewKey;p.value=viewKey;if(showTrend)showTrend.value=viewKey==='daily'?'0':'1'}updatePdfLink(viewKey,selectedView);selectedView.querySelectorAll('.trend-area,.trend-line,.trend-dot').forEach(function(node){node.style.animation='none';void node.offsetWidth;node.style.animation=''})}}
    function setTrendVisible(visible,viewKey){if(!trendPanel)return;trendPanel.hidden=!visible;trendPanel.setAttribute('aria-hidden',visible?'false':'true');if(showTrend)showTrend.value=visible?'1':'0';if(visible&&viewKey)showChart(viewKey)}
    function submit(scrollTarget){if(a.value&&b.value&&a.value>b.value){var x=a.value;a.value=b.value;b.value=x}h.value=b.value||a.value||h.value;try{sessionStorage.setItem(salesScrollStorageKey,JSON.stringify({y:window.scrollY||window.pageYOffset||0,target:scrollTarget||'current'}))}catch(error){}f.submit()}
    function setRange(daysBack,endDate){var end=new Date((endDate||r.getAttribute('data-today'))+'T00:00:00');var start=new Date(end);start.setDate(end.getDate()-daysBack);a.value=localDate(start);b.value=localDate(end)}
    function setCurrentPeriodRange(preset){var anchorDate=parseRangeDate(h.value)||parseRangeDate(r.getAttribute('data-today'))||new Date(),start,end;if(preset==='current_month'){start=new Date(anchorDate.getFullYear(),anchorDate.getMonth(),1);end=new Date(anchorDate.getFullYear(),anchorDate.getMonth()+1,0)}else{start=new Date(anchorDate.getFullYear(),Math.floor(anchorDate.getMonth()/3)*3,1);end=new Date(start.getFullYear(),start.getMonth()+3,0)}a.value=localDate(start);b.value=localDate(end)}
    function useManualRange(showGraph){viewMode.value='current';p.value='daily';showTrend.value=showGraph?'1':'0';setActivePeriod('',false)}
    var lastRangePreset=r.value,rangeModalState=null,draftFrom='',draftTo='',calendarLeftMonth=null,calendarRightMonth=null;
    function syncCustomRangeEditor(){if(editCustomRange)editCustomRange.hidden=r.value!=='custom'}
    function parseRangeDate(value){if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return null;var parts=value.split('-').map(Number),date=new Date(parts[0],parts[1]-1,parts[2]);return date.getFullYear()===parts[0]&&date.getMonth()===parts[1]-1&&date.getDate()===parts[2]?date:null}
    function rangeDateKey(date){return date.getFullYear()+'-'+String(date.getMonth()+1).padStart(2,'0')+'-'+String(date.getDate()).padStart(2,'0')}
    function rangeDateLabel(value){var date=parseRangeDate(value);return date?date.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}):'Select date'}
    function rangeTodayDate(){return parseRangeDate(r.getAttribute('data-today'))||new Date()}
    function rangeCurrentMonth(){var today=rangeTodayDate();return new Date(today.getFullYear(),today.getMonth(),1)}
    function clampRangeCalendarMonth(month){var candidate=new Date(month.getFullYear(),month.getMonth(),1),current=rangeCurrentMonth();return candidate>current?current:candidate}
    function rangeCalendarYearOptions(month,side){var currentYear=rangeTodayDate().getFullYear(),html='<select class="sales-range-year-select" data-range-year-side="'+side+'" aria-label="Select year">';for(var year=2000;year<=currentYear;year++)html+='<option value="'+year+'"'+(year===month.getFullYear()?' selected':'')+'>'+year+'</option>';return html+'</select>'}
    function setRangeCalendarMonth(side,month){var nextMonth=clampRangeCalendarMonth(month);if(side==='left'){calendarLeftMonth=nextMonth;if(calendarRightMonth&&calendarRightMonth<nextMonth)calendarRightMonth=new Date(nextMonth.getFullYear(),nextMonth.getMonth(),1)}else{calendarRightMonth=nextMonth;if(calendarLeftMonth&&calendarLeftMonth>nextMonth)calendarLeftMonth=new Date(nextMonth.getFullYear(),nextMonth.getMonth(),1)}}
    function renderRangeMonth(month,side){month=clampRangeCalendarMonth(month);var year=month.getFullYear(),monthIndex=month.getMonth(),first=new Date(year,monthIndex,1),daysInMonth=new Date(year,monthIndex+1,0).getDate(),cellCount=Math.ceil((first.getDay()+daysInMonth)/7)*7,currentMonth=rangeCurrentMonth(),isCurrentMonth=year===currentMonth.getFullYear()&&monthIndex===currentMonth.getMonth(),atMinimumMonth=year<=2000&&monthIndex===0,previousNav='<button type="button" class="sales-range-nav" data-range-nav="-1" data-range-side="'+side+'" aria-label="Previous month"'+(atMinimumMonth?' disabled':'')+'>&#8249;</button>',nextNav='<button type="button" class="sales-range-nav" data-range-nav="1" data-range-side="'+side+'" aria-label="Next month"'+(isCurrentMonth?' disabled':'')+'>&#8250;</button>',monthTitle='<span class="sales-range-month-title"><span>'+first.toLocaleDateString('en-US',{month:'long'})+'</span>'+rangeCalendarYearOptions(month,side)+'</span>',html='<div class="sales-range-month"><div class="sales-range-month-head">'+previousNav+monthTitle+nextNav+'</div><div class="sales-range-weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div><div class="sales-range-days">';
       for(var index=0;index<cellCount;index++){var date=new Date(year,monthIndex,1-first.getDay()+index),key=rangeDateKey(date),inMonth=date.getMonth()===monthIndex,isFuture=key>r.getAttribute('data-today'),isStart=draftFrom===key,isEnd=draftTo===key,isInRange=draftFrom&&draftTo&&key>draftFrom&&key<draftTo,classes='sales-range-day'+(inMonth?'':' is-outside')+(isInRange?' is-in-range':'')+(isStart?' is-start':'')+(isEnd?' is-end':'');html+='<button type="button" class="'+classes+'" data-range-date="'+key+'"'+(!inMonth||isFuture?' disabled':'')+'>'+date.getDate()+'</button>'}
       return html+'</div></div>'}
    function renderRangeCalendar(){if(!rangeCalendars||!calendarLeftMonth||!calendarRightMonth)return;var invalidRange=!draftFrom||!draftTo||draftFrom>draftTo;rangeCalendars.innerHTML=renderRangeMonth(calendarLeftMonth,'left')+renderRangeMonth(calendarRightMonth,'right');if(rangeFromValue)rangeFromValue.textContent=rangeDateLabel(draftFrom);if(rangeToValue)rangeToValue.textContent=rangeDateLabel(draftTo);if(rangeSet){rangeSet.disabled=false;rangeSet.setAttribute('aria-disabled',invalidRange?'true':'false')}if(rangeMessage)rangeMessage.hidden=!invalidRange}
    function openRangeModal(){if(!rangeModal||!rangeCalendars||!rangeModal.hidden)return;rangeModalState={from:a.value,to:b.value,preset:lastRangePreset};draftFrom=a.value;draftTo=b.value;var start=parseRangeDate(draftFrom)||parseRangeDate(r.getAttribute('data-today'))||new Date(),end=parseRangeDate(draftTo);calendarLeftMonth=new Date(start.getFullYear(),start.getMonth(),1);calendarRightMonth=end?new Date(end.getFullYear(),end.getMonth(),1):new Date(start.getFullYear(),start.getMonth()+1,1);renderRangeCalendar();rangeModal.hidden=false;rangeModal.setAttribute('aria-hidden','false');document.body.classList.add('sales-range-open');if(rangeClose)rangeClose.focus()}
    function closeRangeModal(restore){if(!rangeModal)return;if(restore&&rangeModalState){a.value=rangeModalState.from;b.value=rangeModalState.to;r.value=rangeModalState.preset;lastRangePreset=rangeModalState.preset}rangeModal.hidden=true;rangeModal.setAttribute('aria-hidden','true');document.body.classList.remove('sales-range-open');if(rangeMessage)rangeMessage.hidden=true;rangeModalState=null}
    function chooseRangeDate(key){if(!draftFrom||draftTo||key<draftFrom){draftFrom=key;draftTo=''}else{draftTo=key}renderRangeCalendar()}
    if(rangeCalendars)rangeCalendars.addEventListener('click',function(event){var nav=event.target.closest('[data-range-nav]'),day=event.target.closest('[data-range-date]');if(nav){var side=nav.getAttribute('data-range-side'),month=side==='left'?calendarLeftMonth:calendarRightMonth,shift=Number(nav.getAttribute('data-range-nav'));setRangeCalendarMonth(side,new Date(month.getFullYear(),month.getMonth()+shift,1));renderRangeCalendar();return}if(day&&!day.disabled)chooseRangeDate(day.getAttribute('data-range-date'))});
    if(rangeCalendars)rangeCalendars.addEventListener('change',function(event){var yearSelect=event.target.closest('[data-range-year-side]');if(!yearSelect)return;var side=yearSelect.getAttribute('data-range-year-side'),month=side==='left'?calendarLeftMonth:calendarRightMonth,year=Number(yearSelect.value);if(!Number.isInteger(year)||year<2000)return;setRangeCalendarMonth(side,new Date(year,month.getMonth(),1));renderRangeCalendar()});
    if(rangeClose)rangeClose.addEventListener('click',function(){closeRangeModal(true)});
    if(rangeCancel)rangeCancel.addEventListener('click',function(){closeRangeModal(true)});
    if(rangeModal)rangeModal.addEventListener('click',function(event){if(event.target===rangeModal)closeRangeModal(true)});
    if(rangeSet)rangeSet.addEventListener('click',function(){var invalidRange=!draftFrom||!draftTo||draftFrom>draftTo;if(invalidRange){if(rangeMessage){rangeMessage.hidden=false;rangeMessage.textContent='Select a valid start date and end date first.'}return}a.value=draftFrom;b.value=draftTo;r.value='custom';lastRangePreset='custom';useManualRange(true);closeRangeModal(false);submit('custom-range')});
    document.addEventListener('keydown',function(event){if(event.key==='Escape'&&rangeModal&&!rangeModal.hidden)closeRangeModal(true)});
    a.addEventListener('change',function(){r.value='custom';lastRangePreset='custom';useManualRange(true);submit()});
    b.addEventListener('change',function(){r.value='custom';lastRangePreset='custom';useManualRange(true);submit()});
    var salesPaginationViews=[];
    function focusSalesDetails(){var detailsPanel=document.getElementById('salesDetailsPanel');if(!detailsPanel)return;var top=detailsPanel.getBoundingClientRect().top+window.pageYOffset-16;window.scrollTo({top:Math.max(0,Math.round(top)),behavior:'smooth'})}
    function setupSalesPagination(){document.querySelectorAll('[data-sales-pagination]').forEach(function(pager){var view=pager.closest('[data-sales-details-view]'),body=view?view.querySelector('[data-sales-detail-body]'):null,paginationRow=pager.closest('[data-sales-pagination-row]');if(!view||!body)return;var rows=Array.prototype.slice.call(body.querySelectorAll('tr')),info=pager.querySelector('[data-sales-page-info]'),buttons=pager.querySelector('[data-sales-page-buttons]'),previous=pager.querySelector('[data-sales-page="prev"]'),next=pager.querySelector('[data-sales-page="next"]'),pageSize=10,currentPage=1;function render(){var totalRows=rows.length,totalPages=Math.max(1,Math.ceil(totalRows/pageSize));currentPage=Math.min(Math.max(currentPage,1),totalPages);var first=(currentPage-1)*pageSize,last=Math.min(first+pageSize,totalRows);rows.forEach(function(row,index){row.style.display=index>=first&&index<last?'':'none'});if(info)info.textContent=totalRows?'Showing '+(first+1)+'-'+last+' of '+totalRows:'No sales';if(previous)previous.disabled=currentPage<=1;if(next)next.disabled=currentPage>=totalPages;if(buttons){buttons.innerHTML='';var start=Math.max(1,Math.min(currentPage-2,totalPages-4)),end=Math.min(totalPages,start+4);for(var page=start;page<=end;page++){var button=document.createElement('button');button.type='button';button.className='sales-pagination-page'+(page===currentPage?' is-active':'');button.textContent=String(page);button.setAttribute('aria-label','Sales page '+page);button.setAttribute('aria-current',page===currentPage?'page':'false');button.addEventListener('click',function(){currentPage=Number(this.textContent);render();focusSalesDetails()});buttons.appendChild(button)}}pager.hidden=totalPages<=1;if(paginationRow)paginationRow.hidden=totalPages<=1}if(previous)previous.addEventListener('click',function(){currentPage-=1;render();focusSalesDetails()});if(next)next.addEventListener('click',function(){currentPage+=1;render();focusSalesDetails()});view._salesPaginationRender=render;view._salesPaginationShowAll=function(){rows.forEach(function(row){row.style.display=''});pager.hidden=true;if(paginationRow)paginationRow.hidden=true};salesPaginationViews.push(view);render()})}
    setupSalesPagination();
    if(editCustomRange)editCustomRange.addEventListener('click',function(){openRangeModal()});
    syncCustomRangeEditor();
    r.addEventListener('change',function(){var v=r.value;if(v==='custom'){openRangeModal();return}if(v==='current_month'){setCurrentPeriodRange(v);viewMode.value='monthly';p.value='monthly';showTrend.value='1';setActivePeriod('monthly',true);lastRangePreset=v;submit();return}if(v==='current_quarter'){setCurrentPeriodRange(v);viewMode.value='quarterly';p.value='quarterly';showTrend.value='1';setActivePeriod('quarterly',true);lastRangePreset=v;submit();return}if(v==='today'){setRange(0)}else if(v==='yesterday'){var y=new Date(r.getAttribute('data-today')+'T00:00:00');y.setDate(y.getDate()-1);setRange(0,localDate(y))}else if(v==='past_3_days'){setRange(2)}else if(v==='past_7_days'){setRange(6)}else if(v==='past_2_weeks'){setRange(13)}else if(v==='past_30_days'){setRange(29)}useManualRange(v!=='today');lastRangePreset=v;submit()});
    document.querySelectorAll('[data-sales-period]').forEach(function(btn){btn.addEventListener('click',function(){var v=btn.getAttribute('data-sales-period'),isVisible=trendPanel&&!trendPanel.hidden,isActiveButton=btn.classList.contains('is-active');if(v==='daily'){showChart(v);setTrendVisible(false);setActivePeriod(v,true);focusPeriodCard(v);return}if(isVisible&&isActiveButton){setTrendVisible(false);setActivePeriod(v,false);return}setActivePeriod(v,true);setTrendVisible(true,v);focusPeriodCard(v)})});
    var print=document.getElementById('printSalesReport');if(print)print.addEventListener('click',function(){salesPaginationViews.forEach(function(view){if(view._salesPaginationShowAll)view._salesPaginationShowAll()});window.print();window.setTimeout(function(){salesPaginationViews.forEach(function(view){if(view._salesPaginationRender)view._salesPaginationRender()})},100)})
    try{var savedScrollState=sessionStorage.getItem(salesScrollStorageKey);if(savedScrollState){sessionStorage.removeItem(salesScrollStorageKey);var state;try{state=JSON.parse(savedScrollState)}catch(error){state={y:Number(savedScrollState)||0,target:'current'}}window.requestAnimationFrame(function(){window.requestAnimationFrame(function(){if(state&&state.target==='period-cards'){var cards=document.querySelector('.sales-period-cards');if(cards){window.scrollTo(0,Math.max(0,Math.round(cards.getBoundingClientRect().top+window.pageYOffset-76)));var activeCard=cards.querySelector('.sales-period-card.is-active');if(activeCard){try{activeCard.focus({preventScroll:true})}catch(error){activeCard.focus()}}return}}window.scrollTo(0,Number(state&&state.y)||0)})})}}catch(error){}
});
</script>

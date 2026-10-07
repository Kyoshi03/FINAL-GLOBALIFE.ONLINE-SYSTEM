<?php
require_once __DIR__ . '/includes/session.php';
checkRole('patient');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/appointment_booking.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$currentUser = getCurrentUser();
$pageTitle = 'Queue History | Globalife Medical Laboratory & Polyclinic';
$patientId = (int) ($currentUser['id'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$dateFilter = trim((string) ($_GET['date_filter'] ?? ''));
$allowedDateFilters = ['', 'today', 'yesterday', 'past_3_days', 'past_7_days', 'past_2_weeks', 'past_30_days', 'custom'];
if (!in_array($dateFilter, $allowedDateFilters, true)) {
    $dateFilter = '';
}
$customStartDate = trim((string) ($_GET['start_date'] ?? ''));
$customEndDate = trim((string) ($_GET['end_date'] ?? ''));
$dateFilterError = '';
$filterFromDate = '';
$filterToDate = '';

function patient_queue_history_valid_date(string $date): bool {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    return $dateObject instanceof DateTime && $dateObject->format('Y-m-d') === $date;
}

$todayDate = new DateTimeImmutable('today');
if ($dateFilter === 'today') {
    $filterFromDate = $filterToDate = $todayDate->format('Y-m-d');
} elseif ($dateFilter === 'yesterday') {
    $filterFromDate = $filterToDate = $todayDate->modify('-1 day')->format('Y-m-d');
} elseif (in_array($dateFilter, ['past_3_days', 'past_7_days', 'past_2_weeks', 'past_30_days'], true)) {
    $days = ['past_3_days' => 2, 'past_7_days' => 6, 'past_2_weeks' => 13, 'past_30_days' => 29][$dateFilter];
    $filterFromDate = $todayDate->modify('-' . $days . ' days')->format('Y-m-d');
    $filterToDate = $todayDate->format('Y-m-d');
} elseif ($dateFilter === 'custom') {
    if (!patient_queue_history_valid_date($customStartDate) || !patient_queue_history_valid_date($customEndDate)) {
        $dateFilterError = 'Select a valid Start Date and End Date.';
    } elseif ($customEndDate < $customStartDate) {
        $dateFilterError = 'End Date cannot be earlier than Start Date.';
    } else {
        $filterFromDate = $customStartDate;
        $filterToDate = $customEndDate;
    }
}
$historyPage = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 5;

function patient_queue_history_date_label(?string $date): string {
    $timestamp = strtotime((string) $date);
    return $timestamp ? date('F j, Y', $timestamp) : '--';
}

function patient_queue_history_time_label(?string $time): string {
    $timestamp = strtotime((string) $time);
    return $timestamp ? date('g:i A', $timestamp) : '--';
}

function patient_queue_history_url(string $search, string $dateFilter, string $startDate, string $endDate, int $page): string {
    $params = ['page' => max(1, $page)];
    if ($search !== '') {
        $params['search'] = $search;
    }
    if ($dateFilter !== '') {
        $params['date_filter'] = $dateFilter;
    }
    if ($startDate !== '') {
        $params['start_date'] = $startDate;
    }
    if ($endDate !== '') {
        $params['end_date'] = $endDate;
    }
    return 'patients_queue_history.php?' . http_build_query($params);
}

$conn = getDBConnection();
appointment_init_queue_schema($conn);

$where = [
    'q.patient_id = ?',
    "q.queue_type = 'walk_in'",
    "q.status = 'completed'",
];
$types = 'i';
$params = [$patientId];

if ($filterFromDate !== '' && $filterToDate !== '') {
    $where[] = 'q.queue_date BETWEEN ? AND ?';
    $types .= 'ss';
    $params[] = $filterFromDate;
    $params[] = $filterToDate;
}

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $where[] = "(
        q.queue_number LIKE ?
        OR q.service LIKE ?
        OR q.queue_date LIKE ?
        OR DATE_FORMAT(q.queue_date, '%M %e, %Y') LIKE ?
    )";
    $types .= 'ssss';
    array_push($params, $searchLike, $searchLike, $searchLike, $searchLike);
}

$whereSql = implode(' AND ', $where);
$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM clinic_queue q WHERE {$whereSql}");
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalRecords = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int) ceil($totalRecords / $pageSize));
$historyPage = min($historyPage, $totalPages);
$offset = ($historyPage - 1) * $pageSize;

$historySql = "SELECT q.queue_number, q.service, q.queue_date, q.time_called, q.completed_at, q.queue_type
    FROM clinic_queue q
    WHERE {$whereSql}
    ORDER BY q.completed_at DESC, q.id DESC
    LIMIT ? OFFSET ?";
$historyTypes = $types . 'ii';
$historyParams = array_merge($params, [$pageSize, $offset]);
$historyStmt = $conn->prepare($historySql);
$historyStmt->bind_param($historyTypes, ...$historyParams);
$historyStmt->execute();
$queueHistory = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();

$showingStart = $totalRecords > 0 ? $offset + 1 : 0;
$showingEnd = min($offset + count($queueHistory), $totalRecords);

$userNameSql = dbUsersNameExpression();
$headerProfileStmt = $conn->prepare("SELECT {$userNameSql} AS full_name, profile_photo, profile_updated_at FROM users WHERE id = ? LIMIT 1");
$headerProfileStmt->bind_param('i', $patientId);
$headerProfileStmt->execute();
$headerProfile = $headerProfileStmt->get_result()->fetch_assoc() ?: [];
$headerProfileStmt->close();
$conn->close();

$headerPatientPhotoUrl = patientProfilePhotoUrl($headerProfile['profile_photo'] ?? null, $headerProfile['profile_updated_at'] ?? null);
$headerPatientInitials = patientProfileInitials($headerProfile['full_name'] ?? $currentUser['full_name']);
$headerPatientDisplayName = $headerProfile['full_name'] ?? $currentUser['full_name'];

$additionalStyles = patientAvatarStyles() . '
body { background:#f4f8fb; min-height:100vh; color:#1f343d; }
.appointments-container { max-width:1180px; margin:0 auto; padding:28px 20px 48px; }
.appointments-filter-card { background:#fff; border:1px solid #e4edf2; border-radius:12px; padding:20px 22px; margin-bottom:18px; box-shadow:0 10px 24px rgba(25,76,110,.05); }
.appointment-tools { display:grid; gap:14px; align-items:end; }
.appointment-tools.patient-filters { grid-template-columns:minmax(240px,1fr) 180px auto auto; }
.tool-field label { display:block; margin-bottom:8px; color:#1a3342; font-size:.875rem; font-weight:700; }
.tool-field input, .tool-field select { width:100%; box-sizing:border-box; border:1px solid #dce8ef; border-radius:8px; min-height:42px; padding:9px 12px; color:#1a3342; background:#fff; font:inherit; font-size:.875rem; font-weight:500; }
.tool-field input:focus, .tool-field select:focus { outline:none; border-color:#0f7cc2; box-shadow:0 0 0 3px rgba(15,124,194,.08); }
.queue-custom-range { grid-column:1 / -1; display:flex; gap:12px; flex-wrap:wrap; }
.queue-custom-range[hidden] { display:none; }
.queue-custom-range .tool-field { flex:1 1 200px; }
.queue-date-error { grid-column:1 / -1; margin:-4px 0 0; color:#b42318; font-size:.84rem; font-weight:700; }
.filter-reset-btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; border:1px solid #dce8ef; border-radius:8px; padding:0 16px; background:#fff; color:#0066cc; font-size:.875rem; font-weight:700; text-decoration:none; }
.filter-reset-btn:hover { background:#f3faff; border-color:#b8d9f0; }
.filter-success-modal { position:fixed; inset:0; z-index:5000; display:grid; place-items:center; padding:20px; background:rgba(7,59,76,.52); }
.filter-success-modal[hidden] { display:none; }
.filter-success-card { width:min(420px,100%); border:1px solid #cde8f3; border-radius:12px; background:#fff; box-shadow:0 24px 70px rgba(7,59,76,.28); text-align:center; }
.filter-success-body { padding:30px 28px 28px; }
.filter-success-icon { display:inline-grid; place-items:center; width:64px; height:64px; margin-bottom:16px; border-radius:50%; background:#dcf7e7; color:#148047; font-size:2rem; font-weight:950; }
.filter-success-body h2 { margin:0 0 8px; color:#073b4c; font-size:1.28rem; }
.filter-success-body p { margin:0 0 20px; color:#60727d; line-height:1.45; }
.btn { display:inline-flex; align-items:center; justify-content:center; min-height:42px; border:1px solid transparent; border-radius:8px; padding:0 16px; font:inherit; font-size:.875rem; font-weight:700; cursor:pointer; text-decoration:none; }
.btn-primary { background:#0f7cc2; border-color:#0f7cc2; color:#fff; }
.btn-primary:hover { background:#0b66a0; border-color:#0b66a0; }
.appointments-table-wrapper { overflow:hidden; background:#fff; border:1px solid #e4edf2; border-radius:12px; padding:22px 24px 20px; box-shadow:0 10px 24px rgba(25,76,110,.05); }
.appointments-scroll { max-height:620px; overflow:auto; padding-right:4px; scrollbar-width:thin; scrollbar-color:#c5d3dc #f4f8fb; }
.appointments-table { width:100%; min-width:820px; border-collapse:collapse; }
.appointments-table th { position:sticky; top:0; z-index:3; background:#fff; color:#708792; padding:12px 14px; text-align:left; font-size:.72rem; font-weight:800; letter-spacing:.05em; text-transform:uppercase; border-bottom:1px solid #eef3f6; }
.appointments-table td { padding:14px; border-bottom:1px solid #eef3f6; vertical-align:middle; font-size:.875rem; }
.appointments-table tr:hover { background:#f8fcff; }
.appointments-table tbody tr:last-child td { border-bottom:0; }
.appointment-schedule-cell { min-width:145px; white-space:nowrap; }
.appointment-schedule-cell strong, .appointment-schedule-cell span { display:block; }
.appointment-schedule-cell strong { color:#1a3342; font-size:.86rem; }
.appointment-schedule-cell span { margin-top:2px; color:#60758a; font-size:.82rem; }
.queue-number { color:#0066cc; font-weight:800; }
.queue-service { color:#1f343d; font-weight:700; }
.queue-muted { color:#60758a; }
.status-badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 10px; font-size:.68rem; font-weight:800; letter-spacing:.04em; text-transform:uppercase; white-space:nowrap; }
.status-badge.completed { background:#e6f6ec; color:#168a45; }
.status-badge.walk-in { background:#dcefff; color:#0066cc; }
.filter-empty { padding:28px 16px; color:#60758a; text-align:center; }
.filter-empty strong { display:block; margin-bottom:6px; color:#073b4c; }
.appointment-pagination-footer { display:flex; align-items:center; justify-content:space-between; gap:14px; padding-top:18px; margin-top:4px; border-top:1px solid #eef3f6; color:#708792; font-size:.875rem; font-weight:500; }
.appointment-pagination { display:flex; align-items:center; gap:8px; }
.appointment-page-btn { width:36px; min-width:36px; height:36px; border:1px solid #dce8ef; border-radius:7px; padding:0 10px; background:#fff; color:#0b4f80; font:inherit; font-size:.875rem; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
.appointment-page-btn:hover { border-color:#0f7cc2; color:#0066cc; }
.appointment-page-btn.active { border-color:#0066cc; background:#0066cc; color:#fff; box-shadow:0 10px 20px rgba(0,102,204,.18); }
.appointment-page-btn.is-disabled { pointer-events:none; opacity:.45; }
.appointment-pagination .appointment-page-btn.previous, .appointment-pagination .appointment-page-btn.next { width:auto; }
@media (max-width:768px) {
    .appointments-container { padding:22px 14px 36px; }
    .appointment-tools.patient-filters { grid-template-columns:1fr; }
    .appointments-filter-card { padding:16px; }
    .appointments-table-wrapper { padding:15px; }
    .appointments-scroll { max-height:560px; }
    .appointments-table th, .appointments-table td { padding:10px 8px; }
    .appointment-pagination-footer { align-items:flex-start; flex-direction:column; }
    .appointment-pagination { flex-wrap:wrap; }
}
';

include __DIR__ . '/includes/header.php';
?>
<main class="appointments-container">
    <section class="appointments-filter-card" aria-label="Queue history filters">
        <form class="appointment-tools patient-filters" id="queueHistoryFilterForm" method="get" action="patients_queue_history.php">
            <input type="hidden" name="filter_applied" value="1">
            <div class="tool-field">
                <label for="queueHistorySearch">Search queue history</label>
                <input type="search" id="queueHistorySearch" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search queue number, service, or date">
            </div>
            <div class="tool-field">
                <label for="queueHistoryDateFilter">Date Filter</label>
                <select id="queueHistoryDateFilter" name="date_filter">
                    <option value="">All dates</option>
                    <option value="today"<?php echo $dateFilter === 'today' ? ' selected' : ''; ?>>Today</option>
                    <option value="yesterday"<?php echo $dateFilter === 'yesterday' ? ' selected' : ''; ?>>Yesterday</option>
                    <option value="past_3_days"<?php echo $dateFilter === 'past_3_days' ? ' selected' : ''; ?>>Past 3 Days</option>
                    <option value="past_7_days"<?php echo $dateFilter === 'past_7_days' ? ' selected' : ''; ?>>Past 7 Days</option>
                    <option value="past_2_weeks"<?php echo $dateFilter === 'past_2_weeks' ? ' selected' : ''; ?>>Past 2 Weeks</option>
                    <option value="past_30_days"<?php echo $dateFilter === 'past_30_days' ? ' selected' : ''; ?>>Past 30 Days</option>
                    <option value="custom"<?php echo $dateFilter === 'custom' ? ' selected' : ''; ?>>Custom Date Range</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Apply Filter</button>
            <a href="patients_queue_history.php" class="filter-reset-btn">Reset</a>
            <div class="queue-custom-range" id="queueCustomDateRange"<?php echo $dateFilter === 'custom' ? '' : ' hidden'; ?>>
                <div class="tool-field">
                    <label for="queueHistoryStartDate">Start Date</label>
                    <input type="date" id="queueHistoryStartDate" name="start_date" value="<?php echo htmlspecialchars($customStartDate); ?>">
                </div>
                <div class="tool-field">
                    <label for="queueHistoryEndDate">End Date</label>
                    <input type="date" id="queueHistoryEndDate" name="end_date" value="<?php echo htmlspecialchars($customEndDate); ?>">
                </div>
            </div>
            <?php if ($dateFilterError !== ''): ?><p class="queue-date-error" role="alert"><?php echo htmlspecialchars($dateFilterError); ?></p><?php endif; ?>
        </form>
    </section>

    <div class="filter-success-modal" id="filterSuccessModal" role="dialog" aria-modal="true" aria-labelledby="filterSuccessTitle"<?php echo (isset($_GET['filter_applied']) && $dateFilterError === '') ? '' : ' hidden'; ?>>
        <div class="filter-success-card">
            <div class="filter-success-body">
                <span class="filter-success-icon" aria-hidden="true">✓</span>
                <h2 id="filterSuccessTitle">Filters Applied</h2>
                <p>You have successfully applied the filters.</p>
                <button type="button" class="btn btn-primary" id="filterSuccessOk">OK</button>
            </div>
        </div>
    </div>

    <section class="appointments-table-wrapper" aria-label="Completed walk-in queue history">
        <?php if (empty($queueHistory)): ?>
            <div class="filter-empty">
                <strong>No queue records found for the selected period.</strong>
            </div>
        <?php else: ?>
            <div class="appointments-scroll">
                <table class="appointments-table">
                    <thead>
                        <tr>
                            <th>Queue No.</th>
                            <th>Schedule</th>
                            <th>Service</th>
                            <th>Time Called</th>
                            <th>Completed At</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($queueHistory as $queueRow): ?>
                            <tr>
                                <td class="queue-number"><?php echo htmlspecialchars((string) ($queueRow['queue_number'] ?? '--')); ?></td>
                                <td class="appointment-schedule-cell">
                                    <strong><?php echo htmlspecialchars(patient_queue_history_date_label($queueRow['queue_date'] ?? null)); ?></strong>
                                    <span>Walk-in visit</span>
                                </td>
                                <td class="queue-service"><?php echo htmlspecialchars((string) ($queueRow['service'] ?? 'General Consultation')); ?></td>
                                <td class="queue-muted"><?php echo htmlspecialchars(patient_queue_history_time_label($queueRow['time_called'] ?? null)); ?></td>
                                <td class="queue-muted"><?php echo htmlspecialchars(patient_queue_history_time_label($queueRow['completed_at'] ?? null)); ?></td>
                                <td><span class="status-badge walk-in">Walk-in</span></td>
                                <td><span class="status-badge completed">Completed</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="appointment-pagination-footer">
            <div class="appointment-result-count">Showing <?php echo $showingStart; ?> to <?php echo $showingEnd; ?> of <?php echo $totalRecords; ?> queue records.</div>
            <div class="appointment-pagination" aria-label="Queue history pages">
                <a class="appointment-page-btn previous<?php echo $historyPage <= 1 ? ' is-disabled' : ''; ?>" href="<?php echo htmlspecialchars(patient_queue_history_url($search, $dateFilter, $customStartDate, $customEndDate, $historyPage - 1)); ?>" aria-label="Previous page">Previous</a>
                <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                    <a class="appointment-page-btn<?php echo $pageNumber === $historyPage ? ' active' : ''; ?>" href="<?php echo htmlspecialchars(patient_queue_history_url($search, $dateFilter, $customStartDate, $customEndDate, $pageNumber)); ?>"<?php echo $pageNumber === $historyPage ? ' aria-current="page"' : ''; ?>><?php echo $pageNumber; ?></a>
                <?php endfor; ?>
                <a class="appointment-page-btn next<?php echo $historyPage >= $totalPages ? ' is-disabled' : ''; ?>" href="<?php echo htmlspecialchars(patient_queue_history_url($search, $dateFilter, $customStartDate, $customEndDate, $historyPage + 1)); ?>" aria-label="Next page">Next</a>
            </div>
        </div>
    </section>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('queueHistoryFilterForm');
    var filter = document.getElementById('queueHistoryDateFilter');
    var customRange = document.getElementById('queueCustomDateRange');
    var start = document.getElementById('queueHistoryStartDate');
    var end = document.getElementById('queueHistoryEndDate');
    var successModal = document.getElementById('filterSuccessModal');
    var successOk = document.getElementById('filterSuccessOk');
    function closeSuccessModal() {
        if (successModal) successModal.hidden = true;
    }
    if (successOk) successOk.addEventListener('click', closeSuccessModal);
    if (successModal) successModal.addEventListener('click', function (event) {
        if (event.target === successModal) closeSuccessModal();
    });
    if (successModal && !successModal.hidden) {
        var filterUrl = new URL(window.location.href);
        filterUrl.searchParams.delete('filter_applied');
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, filterUrl.toString());
        }
    }
    if (!form || !filter || !customRange) return;
    function toggleCustomRange() {
        customRange.hidden = filter.value !== 'custom';
    }
    filter.addEventListener('change', toggleCustomRange);
    form.addEventListener('submit', function (event) {
        if (filter.value !== 'custom') return;
        if (!start.value || !end.value || end.value < start.value) {
            event.preventDefault();
            window.alert(!start.value || !end.value
                ? 'Select a valid Start Date and End Date.'
                : 'End Date cannot be earlier than Start Date.');
        }
    });
    toggleCustomRange();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>

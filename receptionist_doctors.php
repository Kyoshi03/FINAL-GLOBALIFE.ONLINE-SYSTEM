<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/doctor_schedule.php';

$pageTitle = 'Doctor schedules | Globalife Administration';
$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);

$message = '';
$error = '';
$dayNames = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

function rd_normalize_time(string $time): string {
    $time = trim($time);
    return strlen($time) === 5 ? $time . ':00' : $time;
}

function rd_time_label(string $time): string {
    return doctor_format_time_hm(rd_normalize_time($time));
}

function rd_slot_label(array $slot, array $dayNames): string {
    $day = $dayNames[(int) ($slot['day_of_week'] ?? 0)] ?? 'Day';
    return $day . ', ' . rd_time_label((string) $slot['time_start']) . ' - ' . rd_time_label((string) $slot['time_end']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doctor_schedule_action'])) {
    $action = $_POST['doctor_schedule_action'];
    $doctorId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'save_slots') {
        if ($doctorId <= 0) {
            $error = 'Invalid doctor.';
        } else {
            $days = $_POST['slot_day'] ?? [];
            $starts = $_POST['slot_start'] ?? [];
            $ends = $_POST['slot_end'] ?? [];
            $slots = [];

            $rowCount = max(count($days), count($starts), count($ends));
            for ($i = 0; $i < $rowCount; $i++) {
                $day = (int) ($days[$i] ?? 0);
                $start = trim((string) ($starts[$i] ?? ''));
                $end = trim((string) ($ends[$i] ?? ''));

                if ($day < 1 && $start === '' && $end === '') {
                    continue;
                }

                if ($day < 1 || $day > 7 || $start === '' || $end === '') {
                    $error = 'Please complete every schedule row before saving.';
                    break;
                }

                $startDb = rd_normalize_time($start);
                $endDb = rd_normalize_time($end);
                if (strtotime($startDb) === false || strtotime($endDb) === false || strtotime($startDb) >= strtotime($endDb)) {
                    $error = 'Start time must be earlier than end time.';
                    break;
                }

                $slots[] = [$day, $startDb, $endDb];
            }

            if ($error === '' && empty($slots)) {
                $error = 'Add at least one clinic schedule row.';
            }

            if ($error === '') {
                usort($slots, static function (array $a, array $b): int {
                    return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
                });

                $lastByDay = [];
                foreach ($slots as $slot) {
                    [$day, $startDb, $endDb] = $slot;
                    if (isset($lastByDay[$day]) && strtotime($startDb) < strtotime($lastByDay[$day])) {
                        $error = 'Schedule rows cannot overlap on the same day.';
                        break;
                    }
                    $lastByDay[$day] = $endDb;
                }
            }

            if ($error === '') {
                $conn->begin_transaction();
                try {
                    $delete = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
                    $delete->bind_param('i', $doctorId);
                    $delete->execute();
                    $delete->close();

                    $insert = $conn->prepare('INSERT INTO doctor_availability (user_id, day_of_week, time_start, time_end) VALUES (?, ?, ?, ?)');
                    foreach ($slots as $slot) {
                        [$day, $startDb, $endDb] = $slot;
                        $insert->bind_param('iiss', $doctorId, $day, $startDb, $endDb);
                        $insert->execute();
                    }
                    $insert->close();

                    $conn->commit();
                    $message = 'Doctor schedule updated.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'Failed to update schedule.';
                }
            }
        }
    } elseif ($action === 'clear_slots') {
        if ($doctorId <= 0) {
            $error = 'Invalid doctor.';
        } else {
            $delete = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
            $delete->bind_param('i', $doctorId);
            if ($delete->execute()) {
                $message = 'Doctor schedule cleared.';
            } else {
                $error = 'Failed to clear schedule.';
            }
            $delete->close();
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $editId = (int) ($_POST['user_id'] ?? $editId);
}

$editDoctor = null;
$editSlots = [];
if ($editId > 0) {
    $stmt = $conn->prepare("SELECT id, full_name, specialty, email, phone, COALESCE(is_active, 1) AS is_active FROM users WHERE id = ? AND role = 'doctor'");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editDoctor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editDoctor) {
        $editSlots = doctor_fetch_availability_slots($conn, $editId);
    } else {
        $error = $error ?: 'Doctor not found.';
    }
}

$doctors = [];
$result = $conn->query("SELECT id, full_name, specialty, email, phone, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY full_name ASC");
if ($result) {
    while ($doctor = $result->fetch_assoc()) {
        $doctor['slots'] = doctor_fetch_availability_slots($conn, (int) $doctor['id']);
        $doctors[] = $doctor;
    }
}

$activeCount = count(array_filter($doctors, static fn ($doctor) => (int) $doctor['is_active'] === 1));
$inactiveCount = count($doctors) - $activeCount;
$scheduledCount = count(array_filter($doctors, static fn ($doctor) => !empty($doctor['slots'])));
$unscheduledCount = count($doctors) - $scheduledCount;

$conn->close();

$additionalStyles = '
body { background:#f4f8fb; color:#1f343d; }
.rd-wrap { max-width:1180px; margin:0 auto; padding:28px 20px 44px; }
.rd-hero { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:18px; align-items:center; background:#073b4c; color:#fff; border-radius:8px; padding:26px; margin-bottom:16px; box-shadow:0 14px 34px rgba(7,59,76,.16); }
.rd-hero h1 { margin:0 0 8px; color:#fff; font-size:1.9rem; }
.rd-hero p { margin:0; color:rgba(255,255,255,.82); line-height:1.6; }
.rd-stats { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
.rd-stat { min-width:110px; border:1px solid rgba(255,255,255,.2); border-radius:8px; padding:12px; background:rgba(255,255,255,.08); }
.rd-stat strong { display:block; color:#fff; font-size:1.35rem; }
.rd-stat span { color:rgba(255,255,255,.78); font-size:.82rem; font-weight:700; }
.rd-alert { border-radius:8px; padding:12px 14px; margin-bottom:14px; font-weight:700; }
.rd-alert.ok { background:#e7f7ed; color:#17643a; border:1px solid #bfe6ce; }
.rd-alert.er { background:#fff0f0; color:#9d1c2c; border:1px solid #ffd0d5; }
.rd-card { background:#fff; border:1px solid #e0ebf3; border-radius:8px; padding:20px; box-shadow:0 10px 24px rgba(25,76,110,.06); margin-bottom:16px; }
.rd-card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; }
.rd-card-head h2 { margin:0; color:#073b4c; font-size:1.25rem; }
.rd-card-head p { margin:4px 0 0; color:#60727d; font-size:.92rem; }
.rd-btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; border:1px solid transparent; border-radius:8px; padding:9px 14px; background:#0f7cc2; color:#fff; cursor:pointer; font-weight:800; text-decoration:none; transition:background .2s ease, border-color .2s ease, transform .2s ease; }
.rd-btn:hover { background:#0b4f80; transform:translateY(-1px); }
.rd-btn.secondary { background:#eef7ff; border-color:#d4e6f5; color:#0b4f80; }
.rd-btn.danger { background:#c1121f; }
.field { display:grid; gap:6px; }
.field label { color:#60727d; font-size:.84rem; font-weight:700; }
input, select { width:100%; box-sizing:border-box; border:1px solid #d4e6f5; border-radius:8px; color:#1f343d; font:inherit; min-height:40px; padding:9px 10px; background:#fff; }
input:focus, select:focus { border-color:#0f7cc2; box-shadow:0 0 0 4px rgba(15,124,194,.1); outline:none; }
.doctor-toolbar { display:grid; grid-template-columns:minmax(220px,1fr) minmax(150px,190px) minmax(150px,190px) auto; gap:10px; margin-bottom:14px; align-items:center; }
.doctor-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.doctor-card { border:1px solid #e0ebf3; border-radius:8px; padding:16px; background:#fff; display:grid; gap:12px; }
.doctor-card.inactive { opacity:.72; }
.doctor-card.no-schedule { border-color:#ffd0d5; background:#fffafa; }
.doctor-card-headline { display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
.doctor-badges { display:flex; flex-wrap:wrap; gap:6px; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:6px 10px; font-size:.76rem; font-weight:900; text-transform:uppercase; }
.badge.active, .badge.schedule-ok { background:#e7f7ed; color:#17643a; }
.badge.inactive, .badge.schedule-missing { background:#fff0f0; color:#9d1c2c; }
.badge.specialty { background:#eef7ff; color:#0b4f80; }
.doctor-title { margin:0 0 6px; color:#073b4c; font-size:1.08rem; }
.doctor-meta { display:flex; flex-wrap:wrap; gap:8px; color:#60727d; font-size:.9rem; }
.doctor-meta span { display:inline-flex; align-items:center; min-height:28px; border:1px solid #e0ebf3; border-radius:999px; padding:4px 9px; background:#f8fbff; }
.schedule-list { display:grid; gap:6px; margin:10px 0 14px; color:#364d58; font-size:.9rem; }
.schedule-line { border-left:3px solid #0f7cc2; padding:5px 0 5px 9px; background:#f7fbff; border-radius:6px; }
.doctor-actions { display:flex; justify-content:flex-end; gap:8px; }
.empty-note { border:1px dashed #bdd7ea; border-radius:8px; padding:14px; color:#60727d; background:#f8fbff; }
.empty-note.hidden, .doctor-card.hidden { display:none; }
.editor-layout { display:grid; grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr); gap:16px; }
.slot-row { display:grid; grid-template-columns:1.1fr 1fr 1fr auto; gap:10px; align-items:end; margin-bottom:10px; }
.editor-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:12px; }
.schedule-modal { display:none; position:fixed; inset:0; z-index:3000; align-items:center; justify-content:center; padding:20px; background:rgba(7,59,76,.58); }
.schedule-modal.active { display:flex; }
.schedule-modal-box { width:min(980px,100%); max-height:min(90vh,920px); overflow:auto; background:#fff; border-radius:8px; box-shadow:0 24px 60px rgba(7,59,76,.25); }
.schedule-modal-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:20px 22px 0; }
.schedule-modal-head h2 { margin:0; color:#073b4c; font-size:1.2rem; font-weight:700; }
.schedule-modal-head p { margin:5px 0 0; color:#60727d; font-size:.92rem; font-weight:400; }
.schedule-modal-body { padding:20px 22px 22px; }
.modal-close { width:38px; height:38px; border:1px solid #d4e6f5; border-radius:8px; background:#eef7ff; color:#0b4f80; cursor:pointer; font-size:1.1rem; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
@media (max-width:900px) { .rd-hero, .doctor-toolbar, .doctor-grid, .editor-layout { grid-template-columns:1fr; } .rd-stats { justify-content:flex-start; } }
@media (max-width:620px) { .rd-wrap { padding:18px 12px 34px; } .slot-row { grid-template-columns:1fr; } .schedule-modal { padding:12px; } .rd-card, .rd-hero { padding:18px; } .rd-btn { width:100%; } }
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const rows = document.getElementById("slotRows");
    const addButton = document.querySelector("[data-add-slot]");
    const searchInput = document.getElementById("doctorSearch");
    const statusFilter = document.getElementById("doctorStatusFilter");
    const scheduleFilter = document.getElementById("doctorScheduleFilter");
    const clearFilters = document.getElementById("doctorClearFilters");
    const noMatches = document.getElementById("doctorNoMatches");
    const scheduleModal = document.getElementById("scheduleEditorModal");

    function filterDoctors() {
        const query = (searchInput && searchInput.value ? searchInput.value : "").toLowerCase().trim();
        const status = statusFilter ? statusFilter.value : "";
        const schedule = scheduleFilter ? scheduleFilter.value : "";
        let visible = 0;

        document.querySelectorAll("[data-doctor-card]").forEach(function (card) {
            const haystack = (card.getAttribute("data-search") || "").toLowerCase();
            const cardStatus = card.getAttribute("data-status") || "";
            const cardSchedule = card.getAttribute("data-schedule") || "";
            const matchesQuery = !query || haystack.indexOf(query) !== -1;
            const matchesStatus = !status || status === cardStatus;
            const matchesSchedule = !schedule || schedule === cardSchedule;
            const show = matchesQuery && matchesStatus && matchesSchedule;
            card.classList.toggle("hidden", !show);
            if (show) visible++;
        });

        if (noMatches) noMatches.classList.toggle("hidden", visible !== 0);
    }

    if (searchInput) searchInput.addEventListener("input", filterDoctors);
    if (statusFilter) statusFilter.addEventListener("change", filterDoctors);
    if (scheduleFilter) scheduleFilter.addEventListener("change", filterDoctors);
    if (clearFilters) {
        clearFilters.addEventListener("click", function () {
            if (searchInput) searchInput.value = "";
            if (statusFilter) statusFilter.value = "";
            if (scheduleFilter) scheduleFilter.value = "";
            filterDoctors();
        });
    }
    filterDoctors();

    if (scheduleModal) {
        document.body.style.overflow = "hidden";
        scheduleModal.addEventListener("click", function (event) {
            if (event.target === scheduleModal) window.location.href = "receptionist_doctors.php";
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") window.location.href = "receptionist_doctors.php";
        });
    }

    if (rows && addButton) {
        addButton.addEventListener("click", function () {
            const first = rows.querySelector(".slot-row");
            if (!first) return;
            const clone = first.cloneNode(true);
            clone.querySelectorAll("input").forEach(function (input) { input.value = ""; });
            clone.querySelectorAll("select").forEach(function (select) { select.selectedIndex = 0; });
            rows.appendChild(clone);
        });

        rows.addEventListener("click", function (event) {
            const button = event.target.closest("[data-remove-slot]");
            if (!button) return;
            const allRows = rows.querySelectorAll(".slot-row");
            if (allRows.length <= 1) {
                allRows[0].querySelectorAll("input").forEach(function (input) { input.value = ""; });
                return;
            }
            button.closest(".slot-row").remove();
        });

        document.querySelectorAll("[data-fill-hours]").forEach(function (button) {
            button.addEventListener("click", function () {
                const start = button.getAttribute("data-start") || "";
                const end = button.getAttribute("data-end") || "";
                rows.querySelectorAll(".slot-row").forEach(function (row) {
                    const startInput = row.querySelector("input[name=\"slot_start[]\"]");
                    const endInput = row.querySelector("input[name=\"slot_end[]\"]");
                    if (startInput && !startInput.value) startInput.value = start;
                    if (endInput && !endInput.value) endInput.value = end;
                });
            });
        });
    }
});
';

include 'includes/header.php';
?>
<main class="rd-wrap">
    <section class="rd-hero">
        <div>
            <h1>Doctor Schedules</h1>
            <p>Manage clinic hours, review schedule coverage, and keep doctor booking details ready for patients.</p>
        </div>
        <div class="rd-stats" aria-label="Doctor summary">
            <div class="rd-stat"><strong><?php echo count($doctors); ?></strong><span>Total doctors</span></div>
            <div class="rd-stat"><strong><?php echo $activeCount; ?></strong><span>Active</span></div>
            <div class="rd-stat"><strong><?php echo $scheduledCount; ?></strong><span>With schedule</span></div>
        </div>
    </section>

    <?php if ($message): ?><div class="rd-alert ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="rd-alert er"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="rd-card">
        <div class="rd-card-head">
            <div>
                <h2>Doctor list</h2>
                <p><?php echo $activeCount; ?> active, <?php echo $inactiveCount; ?> inactive, <?php echo $unscheduledCount; ?> without clinic hours.</p>
            </div>
        </div>

        <?php if (empty($doctors)): ?>
            <div class="empty-note">No doctor accounts found. Ask the admin to add doctors.</div>
        <?php else: ?>
            <div class="doctor-toolbar" role="search" aria-label="Filter doctors">
                <input type="search" id="doctorSearch" placeholder="Search doctor, specialty, email, or phone">
                <select id="doctorStatusFilter" aria-label="Filter doctor status">
                    <option value="">All doctors</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
                <select id="doctorScheduleFilter" aria-label="Filter doctor schedule">
                    <option value="">All schedules</option>
                    <option value="scheduled">With schedule</option>
                    <option value="missing">No schedule</option>
                </select>
                <button type="button" class="rd-btn secondary" id="doctorClearFilters">Clear</button>
            </div>
            <div id="doctorNoMatches" class="empty-note hidden">No doctors match your search.</div>
            <div class="doctor-grid">
                <?php foreach ($doctors as $doctor): ?>
                    <?php
                    $active = (int) $doctor['is_active'] === 1;
                    $hasSchedule = !empty($doctor['slots']);
                    $slotCount = count($doctor['slots']);
                    $scheduleBadge = $hasSchedule ? $slotCount . ' schedule ' . ($slotCount === 1 ? 'row' : 'rows') : 'No schedule';
                    $searchText = trim(implode(' ', array_filter([
                        $doctor['full_name'] ?? '',
                        $doctor['specialty'] ?? '',
                        $doctor['email'] ?? '',
                        $doctor['phone'] ?? '',
                    ])));
                    ?>
                    <article class="doctor-card <?php echo $active ? '' : 'inactive'; ?> <?php echo $hasSchedule ? '' : 'no-schedule'; ?>" data-doctor-card data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>" data-status="<?php echo $active ? 'active' : 'inactive'; ?>" data-schedule="<?php echo $hasSchedule ? 'scheduled' : 'missing'; ?>">
                        <div class="doctor-card-headline">
                            <div>
                                <h3 class="doctor-title"><?php echo htmlspecialchars($doctor['full_name']); ?></h3>
                                <div class="doctor-badges">
                                    <span class="badge <?php echo $active ? 'active' : 'inactive'; ?>"><?php echo $active ? 'Active' : 'Inactive'; ?></span>
                                    <span class="badge specialty"><?php echo htmlspecialchars($doctor['specialty'] ?: 'No specialty'); ?></span>
                                </div>
                            </div>
                            <span class="badge <?php echo $hasSchedule ? 'schedule-ok' : 'schedule-missing'; ?>"><?php echo htmlspecialchars($scheduleBadge); ?></span>
                        </div>
                        <div class="doctor-meta">
                            <span><?php echo !empty($doctor['email']) ? htmlspecialchars($doctor['email']) : 'No email'; ?></span>
                            <span><?php echo !empty($doctor['phone']) ? htmlspecialchars($doctor['phone']) : 'No phone'; ?></span>
                        </div>
                        <div class="schedule-list">
                            <?php if (!$hasSchedule): ?>
                                <div class="schedule-line">No clinic hours set.</div>
                            <?php else: ?>
                                <?php foreach ($doctor['slots'] as $slot): ?>
                                    <div class="schedule-line"><?php echo htmlspecialchars(rd_slot_label($slot, $dayNames)); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($editDoctor): ?>
        <div class="schedule-modal active" id="scheduleEditorModal" role="dialog" aria-modal="true" aria-labelledby="scheduleEditorTitle">
            <div class="schedule-modal-box">
                <div class="schedule-modal-head">
                    <div>
                        <h2 id="scheduleEditorTitle">Edit schedule</h2>
                        <p><?php echo htmlspecialchars($editDoctor['full_name']); ?> - <?php echo htmlspecialchars($editDoctor['specialty'] ?: 'No specialty'); ?></p>
                    </div>
                    <a class="modal-close" href="receptionist_doctors.php" aria-label="Close schedule editor">X</a>
                </div>

                <div class="schedule-modal-body">
                    <div class="editor-layout">
                        <div>
                            <h3 class="doctor-title">Current hours</h3>
                            <div class="schedule-list">
                                <?php if (empty($editSlots)): ?>
                                    <div class="schedule-line">No clinic hours set.</div>
                                <?php else: ?>
                                    <?php foreach ($editSlots as $slot): ?>
                                        <div class="schedule-line"><?php echo htmlspecialchars(rd_slot_label($slot, $dayNames)); ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="empty-note">Tip: Add one row per clinic day. Start time must be earlier than end time.</div>
                        </div>

                        <div>
                            <form method="post">
                                <input type="hidden" name="doctor_schedule_action" value="save_slots">
                                <input type="hidden" name="user_id" value="<?php echo (int) $editDoctor['id']; ?>">

                                <div id="slotRows">
                                    <?php
                                    $slots = $editSlots;
                                    if (empty($slots)) {
                                        $slots = [['day_of_week' => 1, 'time_start' => '09:00:00', 'time_end' => '12:00:00']];
                                    }
                                    foreach ($slots as $slot):
                                        $selectedDay = (int) ($slot['day_of_week'] ?? 1);
                                        $start = substr((string) ($slot['time_start'] ?? ''), 0, 5);
                                        $end = substr((string) ($slot['time_end'] ?? ''), 0, 5);
                                    ?>
                                        <div class="slot-row">
                                            <div class="field">
                                                <label>Day</label>
                                                <select name="slot_day[]">
                                                    <?php foreach ($dayNames as $dayNumber => $dayLabel): ?>
                                                        <option value="<?php echo $dayNumber; ?>" <?php echo $selectedDay === $dayNumber ? 'selected' : ''; ?>><?php echo htmlspecialchars($dayLabel); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="field"><label>Start</label><input type="time" name="slot_start[]" value="<?php echo htmlspecialchars($start); ?>"></div>
                                            <div class="field"><label>End</label><input type="time" name="slot_end[]" value="<?php echo htmlspecialchars($end); ?>"></div>
                                            <button type="button" class="rd-btn secondary" data-remove-slot>Remove</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="editor-actions">
                                    <button type="button" class="rd-btn secondary" data-add-slot>Add row</button>
                                    <button type="button" class="rd-btn secondary" data-fill-hours data-start="08:00" data-end="12:00">Fill AM</button>
                                    <button type="button" class="rd-btn secondary" data-fill-hours data-start="13:00" data-end="17:00">Fill PM</button>
                                    <button type="submit" class="rd-btn">Save schedule</button>
                                </div>
                            </form>

                            <form method="post" class="editor-actions" onsubmit="return confirm('Clear all schedule rows for this doctor?');">
                                <input type="hidden" name="doctor_schedule_action" value="clear_slots">
                                <input type="hidden" name="user_id" value="<?php echo (int) $editDoctor['id']; ?>">
                                <button type="submit" class="rd-btn danger">Clear schedule</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>

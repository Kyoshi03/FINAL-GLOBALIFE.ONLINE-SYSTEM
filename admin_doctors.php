<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/name_parts.php';
require_once __DIR__ . '/includes/doctor_schedule.php';
require_once __DIR__ . '/includes/admin_notifications.php';

$pageTitle = 'Doctors & clinic hours | Globalife';
$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);
init_admin_notifications($conn);

$message = '';
$error = '';
$defaultDoctorPassword = 'password123';
$dayNames = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

function admin_doctor_unique_username(mysqli $conn, string $fullName): string {
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/', '.', $fullName), '.'));
    if ($base === '') {
        $base = 'doctor';
    }
    if (strpos($base, 'dr.') !== 0 && strpos($base, 'dra.') !== 0) {
        $base = 'dr.' . $base;
    }

    $candidate = $base;
    $suffix = 2;
    $check = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    while (true) {
        $check->bind_param('s', $candidate);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $check->close();
            return $candidate;
        }
        $candidate = $base . '.' . $suffix;
        $suffix++;
    }
}

function admin_doctor_normalize_time(string $time): string {
    $time = trim($time);
    return strlen($time) === 5 ? $time . ':00' : $time;
}

function admin_doctor_time_label(string $time): string {
    return doctor_format_time_hm(admin_doctor_normalize_time($time));
}

function admin_doctor_slot_label(array $slot, array $dayNames): string {
    $day = $dayNames[(int) ($slot['day_of_week'] ?? 0)] ?? 'Day';
    return $day . ', ' . admin_doctor_time_label((string) ($slot['time_start'] ?? '')) . ' - ' . admin_doctor_time_label((string) ($slot['time_end'] ?? ''));
}

function admin_doctor_resolve_name_parts(array $source): array {
    $fullNameInput = trim((string) ($source['full_name'] ?? ''));
    $firstName = trim((string) ($source['first_name'] ?? ''));
    $middleName = trim((string) ($source['middle_name'] ?? ''));
    $lastName = trim((string) ($source['last_name'] ?? ''));
    $suffix = trim((string) ($source['suffix'] ?? ''));
    if ($firstName === '' && $middleName === '' && $lastName === '' && $suffix === '' && $fullNameInput !== '') {
        $parsed = clinic_name_split_full_name($fullNameInput);
        $firstName = (string) ($parsed['first_name'] ?? '');
        $middleName = (string) ($parsed['middle_name'] ?? '');
        $lastName = (string) ($parsed['last_name'] ?? '');
        $suffix = (string) ($parsed['suffix'] ?? '');
    }

    $firstName = clinic_name_title_case_part($firstName, true);
    $middleName = clinic_name_uppercase_part($middleName, false);
    $lastName = clinic_name_title_case_part($lastName, true);
    $suffix = clinic_name_uppercase_part($suffix, false);

    return [
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'suffix' => $suffix,
        'display_name' => clinic_name_build_full_name([
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'suffix' => $suffix,
        ]),
    ];
}

function admin_doctor_validate_name_parts(array $nameParts): string {
    $countLetters = static function (string $value): int {
        if ($value === '') {
            return 0;
        }
        preg_match_all('/\p{L}/u', $value, $matches);
        return count($matches[0] ?? []);
    };

    $firstName = (string) ($nameParts['first_name'] ?? '');
    $middleName = (string) ($nameParts['middle_name'] ?? '');
    $lastName = (string) ($nameParts['last_name'] ?? '');
    $suffix = (string) ($nameParts['suffix'] ?? '');

    if ($firstName === '') {
        return 'First name is required.';
    }
    if ($lastName === '') {
        return 'Last name is required.';
    }
    if ($countLetters($firstName) > 15) {
        return 'First name must not exceed 15 letters.';
    }
    if ($middleName !== '' && $countLetters($middleName) !== 1) {
        return 'Middle name must be exactly 1 letter.';
    }
    if ($countLetters($lastName) > 15) {
        return 'Last name must not exceed 15 letters.';
    }
    if ($suffix !== '' && $countLetters($suffix) > 3) {
        return 'Suffix must not exceed 3 letters.';
    }

    return '';
}

function admin_doctor_parse_schedule_slots(bool $required = true): array {
    $days = $_POST['slot_day'] ?? [];
    $starts = $_POST['slot_start'] ?? [];
    $ends = $_POST['slot_end'] ?? [];
    $slots = [];
    $error = '';

    $rowCount = max(count($days), count($starts), count($ends));
    for ($i = 0; $i < $rowCount; $i++) {
        $day = (int) ($days[$i] ?? 0);
        $start = trim((string) ($starts[$i] ?? ''));
        $end = trim((string) ($ends[$i] ?? ''));

        if ($start === '' && $end === '') {
            continue;
        }

        if ($day < 1 || $day > 7 || $start === '' || $end === '') {
            $error = 'Please complete every schedule row before saving.';
            break;
        }

        $startDb = admin_doctor_normalize_time($start);
        $endDb = admin_doctor_normalize_time($end);
        if (strtotime($startDb) === false || strtotime($endDb) === false || strtotime($startDb) >= strtotime($endDb)) {
            $error = 'Start time must be earlier than end time.';
            break;
        }

        $slots[] = [$day, $startDb, $endDb];
    }

    if ($error === '' && $required && empty($slots)) {
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

    return ['slots' => $slots, 'error' => $error];
}

function admin_doctor_save_slots(mysqli $conn, int $doctorId, array $slots): void {
    $delete = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
    $delete->bind_param('i', $doctorId);
    $delete->execute();
    $delete->close();

    if (empty($slots)) {
        return;
    }

    $insert = $conn->prepare('INSERT INTO doctor_availability (user_id, day_of_week, time_start, time_end) VALUES (?, ?, ?, ?)');
    foreach ($slots as $slot) {
        [$day, $startDb, $endDb] = $slot;
        $insert->bind_param('iiss', $doctorId, $day, $startDb, $endDb);
        $insert->execute();
    }
    $insert->close();
}

function admin_doctor_form_slots(): array {
    if (isset($_POST['slot_day']) && is_array($_POST['slot_day'])) {
        $rows = [];
        $days = $_POST['slot_day'] ?? [];
        $starts = $_POST['slot_start'] ?? [];
        $ends = $_POST['slot_end'] ?? [];
        $rowCount = max(count($days), count($starts), count($ends));
        for ($i = 0; $i < $rowCount; $i++) {
            $rows[] = [
                'day_of_week' => (int) ($days[$i] ?? 1),
                'time_start' => (string) ($starts[$i] ?? '09:00'),
                'time_end' => (string) ($ends[$i] ?? '12:00'),
            ];
        }
        return $rows ?: [['day_of_week' => 1, 'time_start' => '09:00', 'time_end' => '12:00']];
    }

    return [['day_of_week' => 1, 'time_start' => '09:00', 'time_end' => '12:00']];
}

function admin_doctor_table_exists(mysqli $conn, string $table): bool {
    $safeTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $result && $result->num_rows > 0;
}

function admin_doctor_authored_record_count(mysqli $conn, int $doctorId): int {
    $total = 0;
    foreach (['medical_records', 'lab_result_entries'] as $table) {
        if (!admin_doctor_table_exists($conn, $table)) {
            continue;
        }
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM `{$table}` WHERE author_id = ?");
        $stmt->bind_param('i', $doctorId);
        $stmt->execute();
        $total += (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
    return $total;
}

$openAddDoctorModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['doctor_admin_action'] ?? '';

    if ($act === 'delete_doctor') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $confirmation = trim((string) ($_POST['delete_confirmation'] ?? ''));

        if ($id <= 0) {
            $error = 'Invalid doctor account.';
        } elseif ($confirmation !== 'DELETE') {
            $error = 'Type DELETE exactly to confirm permanent deletion.';
        } else {
            $doctorStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'doctor' LIMIT 1");
            $doctorStmt->bind_param('i', $id);
            $doctorStmt->execute();
            $doctor = $doctorStmt->get_result()->fetch_assoc();
            $doctorStmt->close();

            if (!$doctor) {
                $error = 'Doctor account was not found.';
            } elseif (admin_doctor_authored_record_count($conn, $id) > 0) {
                $error = 'This doctor has medical or laboratory records and cannot be permanently deleted. Disable the account instead to keep patient records safe.';
            } else {
                $conn->begin_transaction();
                try {
                    if (admin_doctor_table_exists($conn, 'appointments')) {
                        $appointments = $conn->prepare('UPDATE appointments SET doctor_id = NULL WHERE doctor_id = ?');
                        $appointments->bind_param('i', $id);
                        if (!$appointments->execute()) {
                            throw new RuntimeException('Unable to preserve doctor appointments.');
                        }
                        $appointments->close();
                    }

                    $delete = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'doctor'");
                    $delete->bind_param('i', $id);
                    if (!$delete->execute() || $delete->affected_rows !== 1) {
                        throw new RuntimeException('Doctor account deletion failed.');
                    }
                    $delete->close();

                    $conn->commit();
                    $message = $doctor['full_name'] . ' was deleted. Existing appointments were kept and marked as not assigned.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'Unable to delete this doctor account. No changes were saved.';
                }
            }
        }
    } elseif ($act === 'toggle_active') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $setActive = (int) ($_POST['set_active'] ?? -1);
        if ($id > 0 && ($setActive === 0 || $setActive === 1)) {
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'doctor'");
            $stmt->bind_param('ii', $setActive, $id);
            if ($stmt->execute()) {
                $message = $setActive === 1 ? 'Doctor enabled.' : 'Doctor disabled.';
            } else {
                $error = 'Unable to update doctor status.';
            }
            $stmt->close();
        }
    } elseif ($act === 'save_slots') {
        $id = (int) ($_POST['user_id'] ?? 0);
        if ($id <= 0) {
            $error = 'Invalid doctor.';
        } else {
            $parsed = admin_doctor_parse_schedule_slots(false);
            if ($parsed['error'] !== '') {
                $error = $parsed['error'];
            } else {
                $conn->begin_transaction();
                try {
                    admin_doctor_save_slots($conn, $id, $parsed['slots']);
                    $conn->commit();
                    $message = 'Clinic schedule saved.';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $error = 'Failed to save clinic schedule.';
                }
            }
        }
    } elseif ($act === 'save_profile') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $nameParts = admin_doctor_resolve_name_parts($_POST);
        $spec = trim((string) ($_POST['specialty'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $name = $nameParts['display_name'];
        $nameError = admin_doctor_validate_name_parts($nameParts);
        if ($id <= 0) {
            $error = 'Invalid doctor account.';
        } elseif ($nameError !== '') {
            $error = $nameError;
        } elseif ($name !== '') {
            $stmt = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, specialty = ?, email = ?, phone = ? WHERE id = ? AND role = 'doctor'");
            $stmt->bind_param('sssssssi', $nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name'], $nameParts['suffix'], $spec, $email, $phone, $id);
            if ($stmt->execute()) {
                $message = 'Doctor profile updated.';
            } else {
                $error = 'Unable to update doctor profile.';
            }
            $stmt->close();
        } else {
            $error = 'Doctor name is required.';
        }
    } elseif ($act === 'add_doctor') {
        $nameParts = admin_doctor_resolve_name_parts($_POST);
        $name = $nameParts['display_name'];
        $spec = trim((string) ($_POST['specialty'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $parsed = admin_doctor_parse_schedule_slots(true);
        $nameError = admin_doctor_validate_name_parts($nameParts);

        if ($nameError !== '') {
            $error = $nameError;
            $openAddDoctorModal = true;
        } elseif ($parsed['error'] !== '') {
            $error = $parsed['error'];
            $openAddDoctorModal = true;
        } else {
            $conn->begin_transaction();
            try {
                $username = admin_doctor_unique_username($conn, $name);
                $hash = password_hash($defaultDoctorPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, password, first_name, middle_name, last_name, suffix, role, specialty, email, phone, is_active) VALUES (?, ?, ?, ?, ?, ?, 'doctor', ?, ?, ?, ?, 1)");
                $stmt->bind_param('sssssssss', $username, $hash, $nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name'], $nameParts['suffix'], $spec, $email, $phone);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Doctor account insert failed.');
                }
                $newId = (int) $stmt->insert_id;
                $stmt->close();

                admin_doctor_save_slots($conn, $newId, $parsed['slots']);
                create_admin_notification(
                    $conn,
                    'doctor_account_created',
                    'New doctor account',
                    $name . ' was added to Doctors & Hours.',
                    $newId
                );
                $conn->commit();
                $message = 'Doctor account and schedule created. Username: ' . $username . ' | Default password: ' . $defaultDoctorPassword;
            } catch (Throwable $e) {
                $conn->rollback();
                $error = $conn->errno === 1062 ? 'Username already exists.' : 'Failed to create doctor account.';
                $openAddDoctorModal = true;
            }
        }
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editDoctor = null;
$editSlots = [];
$editDoctorNameParts = [
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'suffix' => '',
];
if ($editId > 0) {
    $stmt = $conn->prepare("SELECT id, username, full_name, first_name, middle_name, last_name, suffix, specialty, email, phone, COALESCE(is_active, 1) AS is_active FROM users WHERE id = ? AND role = 'doctor'");
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editDoctor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editDoctor) {
        $editDoctorNameParts = admin_doctor_resolve_name_parts($editDoctor);
        $editSlots = doctor_fetch_availability_slots($conn, $editId);
    }
}

$doctors = [];
$result = $conn->query("SELECT id, username, full_name, first_name, middle_name, last_name, suffix, specialty, email, phone, COALESCE(is_active, 1) AS is_active FROM users WHERE role = 'doctor' ORDER BY full_name ASC");
if ($result) {
    while ($doctor = $result->fetch_assoc()) {
        $doctor['slots'] = doctor_fetch_availability_slots($conn, (int) $doctor['id']);
        $doctors[] = $doctor;
    }
}

$activeCount = count(array_filter($doctors, static fn ($doctor) => (int) $doctor['is_active'] === 1));
$inactiveCount = count($doctors) - $activeCount;
$addFormSlots = admin_doctor_form_slots();

$conn->close();

$additionalStyles = '
body {
    background: #f4f8fb;
    color: #1f343d;
}

.ad-wrap {
    max-width: 1180px;
    margin: 0 auto;
    padding: 28px 20px 48px;
}

.ad-hero,
.ad-card,
.doctor-card,
.status-card,
.edit-card {
    border: 1px solid #dce8ef;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
}

.ad-hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 18px;
    align-items: center;
    margin-bottom: 16px;
    padding: 28px;
    background: #073b4c;
    color: #fff;
}

.ad-hero h1 {
    margin: 0;
    color: #fff;
    font-size: 2.1rem;
    line-height: 1.15;
}

.ad-hero p {
    margin: 8px 0 0;
    color: rgba(255, 255, 255, 0.84);
    line-height: 1.55;
}

.ad-stats {
    display: grid;
    grid-template-columns: repeat(3, 116px);
    gap: 10px;
}

.status-card {
    padding: 14px;
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.2);
}

.status-card strong {
    display: block;
    color: #fff;
    font-size: 1.8rem;
    line-height: 1;
}

.status-card span {
    display: block;
    margin-top: 6px;
    color: #8bd3e6;
    font-size: 0.78rem;
    font-weight: 900;
    text-transform: uppercase;
}

.ad-alert {
    border-radius: 8px;
    padding: 13px 14px;
    margin-bottom: 14px;
    font-weight: 800;
}

.ad-alert.ok {
    background: #e7f7ed;
    color: #17643a;
    border: 1px solid #bfe6ce;
}

.ad-alert.er {
    background: #fff0f0;
    color: #9d1c2c;
    border: 1px solid #ffd0d5;
}

.ad-card {
    padding: 20px;
    margin-bottom: 16px;
}

.ad-card-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}

.ad-card-head h2,
.edit-card h2 {
    margin: 0;
    color: #073b4c;
    font-size: 1.25rem;
}

.ad-card-head p,
.edit-card p,
.schedule-hint {
    margin: 5px 0 0;
    color: #60727d;
    line-height: 1.45;
}

.ad-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 9px 14px;
    background: #0f7cc2;
    color: #fff;
    cursor: pointer;
    font: inherit;
    font-size: 0.92rem;
    font-weight: 900;
    line-height: 1.15;
    text-align: center;
    text-decoration: none;
    white-space: nowrap;
}

.ad-btn.secondary {
    background: #eef7ff;
    border-color: #d4e6f5;
    color: #0b4f80;
}

.ad-btn.danger {
    background: #c1121f;
}

.doctor-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(160px, 220px);
    gap: 10px;
    margin-bottom: 14px;
}

.doctor-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.doctor-card {
    padding: 16px;
}

.doctor-card.is-active {
    border-color: #bfe6ce;
    background: #f5fbf7;
}

.doctor-card.is-inactive {
    border-color: #ffd0d5;
    background: #fff8f8;
}

.doctor-status-banner {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: -16px -16px 14px;
    padding: 11px 14px;
    border-radius: 8px 8px 0 0;
    font-weight: 900;
}

.doctor-card.is-active .doctor-status-banner {
    background: #dff3e6;
    color: #17643a;
}

.doctor-card.is-inactive .doctor-status-banner {
    background: #ffe5e9;
    color: #9d1c2c;
}

.doctor-status-dot {
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: currentColor;
}

.badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 6px 10px;
    background: #eef7ff;
    color: #0b4f80;
    font-size: 0.76rem;
    font-weight: 900;
    text-transform: uppercase;
}

.doctor-card h3 {
    margin: 10px 0 6px;
    color: #073b4c;
    font-size: 1.1rem;
}

.doctor-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 12px;
    color: #60727d;
    font-size: 0.9rem;
}

.schedule-list {
    display: grid;
    gap: 6px;
    margin: 12px 0 14px;
    color: #364d58;
    font-size: 0.9rem;
}

.schedule-line {
    border-left: 3px solid #0f7cc2;
    border-radius: 6px;
    background: #f8fbff;
    padding: 6px 0 6px 10px;
}

.doctor-actions,
.form-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 12px;
}

.doctor-actions form {
    display: inline-flex;
    margin: 0;
}

.doctor-card.hidden {
    display: none;
}

.empty-note {
    border: 1px dashed #bdd7ea;
    border-radius: 8px;
    padding: 14px;
    color: #60727d;
    background: #f8fbff;
}

.empty-note.hidden {
    display: none;
}

.edit-card {
    padding: 20px;
    margin-bottom: 16px;
}

.edit-layout {
    display: grid;
    grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
    gap: 16px;
    margin-top: 16px;
}

.edit-panel {
    border: 1px solid #e0ebf3;
    border-radius: 8px;
    padding: 16px;
    background: #f8fbff;
}

.edit-panel h3 {
    margin: 0 0 12px;
    color: #073b4c;
}

.field {
    display: grid;
    gap: 6px;
    margin-bottom: 10px;
}

.field label {
    color: #60727d;
    font-size: 0.84rem;
    font-weight: 900;
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

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.form-grid .full {
    grid-column: 1 / -1;
}

.name-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.name-grid .full {
    grid-column: 1 / -1;
}

.slot-row {
    display: grid;
    grid-template-columns: 1.1fr 1fr 1fr auto;
    gap: 10px;
    align-items: end;
    margin-bottom: 10px;
}

.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(7, 59, 76, 0.55);
}

.modal-overlay.active {
    display: flex;
}

.modal {
    width: min(720px, 100%);
    max-height: 90vh;
    overflow-y: auto;
    border-radius: 12px;
    background: #fff;
    padding: 22px;
    box-shadow: 0 20px 50px rgba(7, 59, 76, 0.25);
}

.modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}

.modal-head h2 {
    margin: 0 0 6px;
    color: #073b4c;
}

.modal-head p {
    margin: 0;
    color: #60727d;
}

.modal-close {
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 8px;
    background: #eef7ff;
    color: #0b4f80;
    cursor: pointer;
    font-size: 1.45rem;
    line-height: 1;
}

.modal-head-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ad-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.delete-confirm-modal {
    width: min(520px, 100%);
}

.edit-doctor-modal {
    width: min(960px, 100%);
}

.status-confirm-modal {
    width: min(500px, 100%);
}

.delete-warning {
    margin: 0 0 16px;
    border: 1px solid #ffc8cf;
    border-left: 4px solid #cc1730;
    border-radius: 8px;
    background: #fff5f6;
    color: #7f1d2d;
    padding: 12px 14px;
    line-height: 1.55;
}

.delete-doctor-name {
    color: #073b4c;
    font-weight: 900;
}

.status-warning {
    margin: 0 0 16px;
    border: 1px solid #cfe3f2;
    border-left: 4px solid #0f7cc2;
    border-radius: 8px;
    background: #f5fbff;
    color: #315466;
    padding: 12px 14px;
    line-height: 1.55;
}

.status-warning strong {
    color: #073b4c;
}

@media (max-width: 900px) {
    .ad-hero,
    .doctor-grid,
    .doctor-toolbar,
    .edit-layout {
        grid-template-columns: 1fr;
    }

    .ad-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 620px) {
    .ad-wrap {
        padding: 18px 12px 34px;
    }

    .ad-card-head,
    .doctor-actions,
    .form-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .form-grid,
    .slot-row {
        grid-template-columns: 1fr;
    }

    .ad-btn {
        width: 100%;
    }
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const addModal = document.getElementById("addDoctorModal");
    const editModal = document.getElementById("editDoctorModal");
    const openAddBtn = document.getElementById("btnOpenAddDoctor");
    const closeAddBtn = document.getElementById("closeAddDoctorModal");
    const cancelAddBtn = document.getElementById("cancelAddDoctorModal");
    const closeEditBtn = document.getElementById("closeEditDoctorModal");
    const cancelEditBtn = document.getElementById("cancelEditDoctorModal");
    const statusModal = document.getElementById("statusDoctorModal");
    const statusDoctorId = document.getElementById("statusDoctorId");
    const statusDoctorName = document.getElementById("statusDoctorName");
    const statusDoctorState = document.getElementById("statusDoctorState");
    const statusDoctorText = document.getElementById("statusDoctorText");
    const statusDoctorTitle = document.getElementById("statusDoctorModalTitle");
    const statusDoctorSubmit = document.getElementById("statusDoctorSubmit");
    const closeStatusDoctor = document.getElementById("closeStatusDoctorModal");
    const cancelStatusDoctor = document.getElementById("cancelStatusDoctor");
    const deleteModal = document.getElementById("deleteDoctorModal");
    const deleteDoctorId = document.getElementById("deleteDoctorId");
    const deleteDoctorName = document.getElementById("deleteDoctorName");
    const deleteConfirmation = document.getElementById("deleteDoctorConfirmation");
    const confirmDeleteDoctor = document.getElementById("confirmDeleteDoctor");
    const closeDeleteDoctor = document.getElementById("closeDeleteDoctorModal");
    const cancelDeleteDoctor = document.getElementById("cancelDeleteDoctor");

    function openAddModal() {
        if (!addModal) return;
        addModal.classList.add("active");
        addModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        const first = addModal.querySelector("input:not([type=hidden])");
        if (first) first.focus();
    }

    function closeAddModal() {
        if (!addModal) return;
        addModal.classList.remove("active");
        addModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    function openEditModal() {
        if (!editModal) return;
        editModal.classList.add("active");
        editModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        const first = editModal.querySelector("input:not([type=hidden])");
        if (first) first.focus();
    }

    function closeEditModal() {
        if (!editModal) return;
        editModal.classList.remove("active");
        editModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
        if (window.location.search.indexOf("edit=") !== -1) {
            window.history.replaceState({}, "", "admin_doctors.php");
        }
    }

    function openStatusModal(button) {
        if (!statusModal || !button) return;
        const doctorName = button.getAttribute("data-doctor-name") || "this doctor";
        const nextState = button.getAttribute("data-set-active") || "0";
        const willEnable = nextState === "1";
        if (statusDoctorId) statusDoctorId.value = button.getAttribute("data-doctor-id") || "";
        if (statusDoctorState) statusDoctorState.value = nextState;
        if (statusDoctorName) statusDoctorName.textContent = doctorName;
        if (statusDoctorTitle) statusDoctorTitle.textContent = willEnable ? "Enable doctor account?" : "Disable doctor account?";
        if (statusDoctorText) {
            statusDoctorText.textContent = willEnable
                ? "Are you sure you want to enable this doctor? Patients can book this doctor again."
                : "Are you sure you want to disable this doctor? This doctor will be hidden from new appointment booking.";
        }
        if (statusDoctorSubmit) {
            statusDoctorSubmit.textContent = willEnable ? "Enable doctor" : "Disable doctor";
            statusDoctorSubmit.classList.toggle("danger", !willEnable);
        }
        statusModal.classList.add("active");
        statusModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        if (statusDoctorSubmit) statusDoctorSubmit.focus();
    }

    function closeStatusModal() {
        if (!statusModal) return;
        statusModal.classList.remove("active");
        statusModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    function openDeleteModal(button) {
        if (!deleteModal || !button) return;
        if (deleteDoctorId) deleteDoctorId.value = button.getAttribute("data-doctor-id") || "";
        if (deleteDoctorName) deleteDoctorName.textContent = button.getAttribute("data-doctor-name") || "this doctor";
        if (deleteConfirmation) deleteConfirmation.value = "";
        if (confirmDeleteDoctor) confirmDeleteDoctor.disabled = true;
        deleteModal.classList.add("active");
        deleteModal.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        if (deleteConfirmation) deleteConfirmation.focus();
    }

    function closeDeleteModal() {
        if (!deleteModal) return;
        deleteModal.classList.remove("active");
        deleteModal.setAttribute("aria-hidden", "true");
        document.body.style.overflow = "";
    }

    if (openAddBtn) openAddBtn.addEventListener("click", openAddModal);
    if (closeAddBtn) closeAddBtn.addEventListener("click", closeAddModal);
    if (cancelAddBtn) cancelAddBtn.addEventListener("click", closeAddModal);
    if (closeEditBtn) closeEditBtn.addEventListener("click", closeEditModal);
    if (cancelEditBtn) cancelEditBtn.addEventListener("click", closeEditModal);
    if (addModal) {
        addModal.addEventListener("click", function (event) {
            if (event.target === addModal) closeAddModal();
        });
    }
    if (editModal) {
        editModal.addEventListener("click", function (event) {
            if (event.target === editModal) closeEditModal();
        });
    }
    document.querySelectorAll(".doctor-status-trigger").forEach(function (button) {
        button.addEventListener("click", function () { openStatusModal(button); });
    });
    if (closeStatusDoctor) closeStatusDoctor.addEventListener("click", closeStatusModal);
    if (cancelStatusDoctor) cancelStatusDoctor.addEventListener("click", closeStatusModal);
    if (statusModal) {
        statusModal.addEventListener("click", function (event) {
            if (event.target === statusModal) closeStatusModal();
        });
    }
    document.querySelectorAll(".doctor-delete-trigger").forEach(function (button) {
        button.addEventListener("click", function () { openDeleteModal(button); });
    });
    if (closeDeleteDoctor) closeDeleteDoctor.addEventListener("click", closeDeleteModal);
    if (cancelDeleteDoctor) cancelDeleteDoctor.addEventListener("click", closeDeleteModal);
    if (deleteModal) {
        deleteModal.addEventListener("click", function (event) {
            if (event.target === deleteModal) closeDeleteModal();
        });
    }
    if (deleteConfirmation) {
        deleteConfirmation.addEventListener("input", function () {
            if (confirmDeleteDoctor) confirmDeleteDoctor.disabled = deleteConfirmation.value.trim() !== "DELETE";
        });
    }

    function bindSlotRows(rowsEl, addBtn) {
        if (!rowsEl) return;
        if (addBtn) {
            addBtn.addEventListener("click", function () {
                const first = rowsEl.querySelector(".slot-row");
                if (!first) return;
                const clone = first.cloneNode(true);
                clone.querySelectorAll("input").forEach(function (input) { input.value = ""; });
                clone.querySelectorAll("select").forEach(function (select) { select.selectedIndex = 0; });
                rowsEl.appendChild(clone);
            });
        }
        rowsEl.addEventListener("click", function (event) {
            const button = event.target.closest("[data-remove-slot]");
            if (!button) return;
            const allRows = rowsEl.querySelectorAll(".slot-row");
            if (allRows.length <= 1) {
                allRows[0].querySelectorAll("input").forEach(function (input) { input.value = ""; });
                return;
            }
            button.closest(".slot-row").remove();
        });
    }

    bindSlotRows(document.getElementById("newSlotRows"), document.querySelector("[data-add-new-slot]"));
    bindSlotRows(document.getElementById("editSlotRows"), document.querySelector("[data-add-edit-slot]"));

    document.querySelectorAll("[data-fill-hours]").forEach(function (button) {
        button.addEventListener("click", function () {
            const target = document.getElementById(button.getAttribute("data-target") || "");
            if (!target) return;
            const start = button.getAttribute("data-start") || "";
            const end = button.getAttribute("data-end") || "";
            target.querySelectorAll(".slot-row").forEach(function (row) {
                const startInput = row.querySelector("input[name=\"slot_start[]\"]");
                const endInput = row.querySelector("input[name=\"slot_end[]\"]");
                if (startInput && !startInput.value) startInput.value = start;
                if (endInput && !endInput.value) endInput.value = end;
            });
        });
    });

    const searchInput = document.getElementById("doctorSearch");
    const statusFilter = document.getElementById("doctorStatusFilter");
    const noMatches = document.getElementById("doctorNoMatches");

    function filterDoctors() {
        const query = (searchInput && searchInput.value ? searchInput.value : "").toLowerCase().trim();
        const status = statusFilter ? statusFilter.value : "";
        let visible = 0;
        document.querySelectorAll("[data-doctor-card]").forEach(function (card) {
            const haystack = (card.getAttribute("data-search") || "").toLowerCase();
            const cardStatus = card.getAttribute("data-status") || "";
            const show = (!query || haystack.indexOf(query) !== -1) && (!status || cardStatus === status);
            card.classList.toggle("hidden", !show);
            if (show) visible++;
        });
        if (noMatches) noMatches.classList.toggle("hidden", visible !== 0);
    }

    if (searchInput) searchInput.addEventListener("input", filterDoctors);
    if (statusFilter) statusFilter.addEventListener("change", filterDoctors);
    filterDoctors();

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeAddModal();
            closeEditModal();
            closeStatusModal();
            closeDeleteModal();
        }
    });
' . ($openAddDoctorModal ? "\n    openAddModal();\n" : '') . '
' . ($editDoctor ? "\n    openEditModal();\n" : '') . '
});
';

include 'includes/header.php';
?>
<main class="ad-wrap">
    <section class="ad-hero">
        <div>
            <h1>Doctors &amp; clinic hours</h1>
            <p>Add doctor accounts, set clinic schedules, and control who is available for appointment booking.</p>
        </div>
        <div class="ad-stats" aria-label="Doctor status summary">
            <div class="status-card"><strong><?php echo count($doctors); ?></strong><span>Total</span></div>
            <div class="status-card"><strong><?php echo $activeCount; ?></strong><span>Active</span></div>
            <div class="status-card"><strong><?php echo $inactiveCount; ?></strong><span>Inactive</span></div>
        </div>
    </section>

    <?php if ($message): ?>
        <div class="ad-alert ok"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error && !$openAddDoctorModal): ?>
        <div class="ad-alert er"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($editDoctor): ?>
        <div class="modal-overlay" id="editDoctorModal" role="dialog" aria-modal="true" aria-labelledby="editDoctorModalTitle" aria-hidden="true">
            <div class="modal edit-doctor-modal">
                <div class="modal-head">
                <div>
                    <h2 id="editDoctorModalTitle">Manage <?php echo htmlspecialchars($editDoctor['full_name']); ?></h2>
                    <p>Edit profile details and update clinic schedule from one place.</p>
                </div>
                    <div class="modal-head-actions">
                        <button
                            type="button"
                            class="ad-btn <?php echo (int) ($editDoctor['is_active'] ?? 1) === 1 ? 'danger' : 'secondary'; ?> doctor-status-trigger"
                            data-doctor-id="<?php echo (int) $editDoctor['id']; ?>"
                            data-doctor-name="<?php echo htmlspecialchars($editDoctor['full_name'], ENT_QUOTES); ?>"
                            data-set-active="<?php echo (int) ($editDoctor['is_active'] ?? 1) === 1 ? 0 : 1; ?>"
                        ><?php echo (int) ($editDoctor['is_active'] ?? 1) === 1 ? 'Disable' : 'Enable'; ?></button>
                        <button type="button" class="modal-close" id="closeEditDoctorModal" aria-label="Close">&times;</button>
                    </div>
                </div>
            <div class="edit-layout">
                <div class="edit-panel">
                    <h3>Profile</h3>
                    <form method="post">
                        <input type="hidden" name="doctor_admin_action" value="save_profile">
                        <input type="hidden" name="user_id" value="<?php echo (int) $editDoctor['id']; ?>">
                        <div class="name-grid">
                            <div class="field"><label>First name</label><input name="first_name" value="<?php echo htmlspecialchars($editDoctorNameParts['first_name']); ?>" maxlength="15" required></div>
                            <div class="field"><label>Middle name</label><input name="middle_name" value="<?php echo htmlspecialchars($editDoctorNameParts['middle_name']); ?>" maxlength="1" placeholder="M"></div>
                            <div class="field"><label>Last name</label><input name="last_name" value="<?php echo htmlspecialchars($editDoctorNameParts['last_name']); ?>" maxlength="15" required></div>
                            <div class="field"><label>Suffix</label><input name="suffix" value="<?php echo htmlspecialchars($editDoctorNameParts['suffix']); ?>" maxlength="3" placeholder="Jr"></div>
                        </div>
                        <div class="field"><label>Specialty</label><input name="specialty" value="<?php echo htmlspecialchars($editDoctor['specialty'] ?? ''); ?>"></div>
                        <div class="field"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($editDoctor['email'] ?? ''); ?>"></div>
                        <div class="field"><label>Phone</label><input name="phone" value="<?php echo htmlspecialchars($editDoctor['phone'] ?? ''); ?>"></div>
                        <div class="form-actions"><button class="ad-btn" type="submit">Save profile</button></div>
                    </form>
                </div>
                <div class="edit-panel">
                    <h3>Doctor schedule</h3>
                    <p class="schedule-hint">Add one or more clinic hour rows. Leave all rows blank and save to clear the schedule.</p>
                    <form method="post">
                        <input type="hidden" name="doctor_admin_action" value="save_slots">
                        <input type="hidden" name="user_id" value="<?php echo (int) $editDoctor['id']; ?>">
                        <div id="editSlotRows">
                            <?php
                            $slots = $editSlots ?: [['day_of_week' => 1, 'time_start' => '09:00:00', 'time_end' => '12:00:00']];
                            foreach ($slots as $slot):
                                $selectedDay = (int) ($slot['day_of_week'] ?? 1);
                                $start = substr((string) ($slot['time_start'] ?? '09:00:00'), 0, 5);
                                $end = substr((string) ($slot['time_end'] ?? '12:00:00'), 0, 5);
                            ?>
                                <div class="slot-row">
                                    <div class="field">
                                        <label>Day</label>
                                        <select name="slot_day[]">
                                            <?php foreach ($dayNames as $dayNumber => $dayLabel): ?>
                                                <option value="<?php echo (int) $dayNumber; ?>" <?php echo $selectedDay === (int) $dayNumber ? 'selected' : ''; ?>><?php echo htmlspecialchars($dayLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="field"><label>Start</label><input type="time" name="slot_start[]" value="<?php echo htmlspecialchars($start); ?>"></div>
                                    <div class="field"><label>End</label><input type="time" name="slot_end[]" value="<?php echo htmlspecialchars($end); ?>"></div>
                                    <button type="button" class="ad-btn secondary" data-remove-slot>Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="ad-btn secondary" data-add-edit-slot>Add row</button>
                            <button type="button" class="ad-btn secondary" data-fill-hours data-target="editSlotRows" data-start="08:00" data-end="12:00">Fill AM</button>
                            <button type="button" class="ad-btn secondary" data-fill-hours data-target="editSlotRows" data-start="13:00" data-end="17:00">Fill PM</button>
                            <button class="ad-btn" type="submit">Save schedule</button>
                            <button type="button" class="ad-btn secondary" id="cancelEditDoctorModal">Close</button>
                        </div>
                    </form>
                </div>
            </div>
            </div>
        </div>
    <?php endif; ?>

    <section class="ad-card">
        <div class="ad-card-head">
            <div>
                <h2>Doctor accounts</h2>
                <p><?php echo $activeCount; ?> active, <?php echo $inactiveCount; ?> inactive. Use schedules so appointments show the right availability.</p>
            </div>
            <button type="button" class="ad-btn" id="btnOpenAddDoctor">Add doctor account</button>
        </div>

        <?php if (empty($doctors)): ?>
            <div class="empty-note">No doctor accounts yet. Click <strong>Add doctor account</strong> to create one.</div>
        <?php else: ?>
            <div class="doctor-toolbar" role="search" aria-label="Filter doctors">
                <input type="search" id="doctorSearch" placeholder="Search doctor, specialty, email, or phone">
                <select id="doctorStatusFilter" aria-label="Filter doctor status">
                    <option value="">All doctors</option>
                    <option value="active">Active only</option>
                    <option value="inactive">Inactive only</option>
                </select>
            </div>
            <div id="doctorNoMatches" class="empty-note hidden">No doctors match your search.</div>
            <div class="doctor-grid">
                <?php foreach ($doctors as $doctor): ?>
                    <?php
                    $active = (int) ($doctor['is_active'] ?? 1) === 1;
                    $searchText = trim(implode(' ', array_filter([
                        $doctor['full_name'] ?? '',
                        $doctor['username'] ?? '',
                        $doctor['specialty'] ?? '',
                        $doctor['email'] ?? '',
                        $doctor['phone'] ?? '',
                    ])));
                    ?>
                    <article class="doctor-card <?php echo $active ? 'is-active' : 'is-inactive'; ?>" data-doctor-card data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>" data-status="<?php echo $active ? 'active' : 'inactive'; ?>">
                        <div class="doctor-status-banner">
                            <span class="doctor-status-dot" aria-hidden="true"></span>
                            <span><?php echo $active ? 'Active - available for appointments' : 'Inactive - hidden from booking'; ?></span>
                        </div>
                        <span class="badge"><?php echo htmlspecialchars($doctor['specialty'] ?: 'No specialty'); ?></span>
                        <h3><?php echo htmlspecialchars($doctor['full_name']); ?></h3>
                        <div class="doctor-meta">
                            <span><?php echo htmlspecialchars($doctor['username']); ?></span>
                            <?php if (!empty($doctor['email'])): ?><span><?php echo htmlspecialchars($doctor['email']); ?></span><?php endif; ?>
                            <?php if (!empty($doctor['phone'])): ?><span><?php echo htmlspecialchars($doctor['phone']); ?></span><?php endif; ?>
                        </div>
                        <div class="schedule-list">
                            <?php if (empty($doctor['slots'])): ?>
                                <div class="schedule-line">No clinic hours set.</div>
                            <?php else: ?>
                                <?php foreach ($doctor['slots'] as $slot): ?>
                                    <div class="schedule-line"><?php echo htmlspecialchars(admin_doctor_slot_label($slot, $dayNames)); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="doctor-actions">
                            <a class="ad-btn secondary" href="admin_doctors.php?edit=<?php echo (int) $doctor['id']; ?>">Manage / Edit</a>
                            <button
                                type="button"
                                class="ad-btn <?php echo $active ? 'danger' : ''; ?> doctor-status-trigger"
                                data-doctor-id="<?php echo (int) $doctor['id']; ?>"
                                data-doctor-name="<?php echo htmlspecialchars($doctor['full_name'], ENT_QUOTES); ?>"
                                data-set-active="<?php echo $active ? 0 : 1; ?>"
                            ><?php echo $active ? 'Disable' : 'Enable'; ?></button>
                            <button
                                type="button"
                                class="ad-btn danger doctor-delete-trigger"
                                data-doctor-id="<?php echo (int) $doctor['id']; ?>"
                                data-doctor-name="<?php echo htmlspecialchars($doctor['full_name'], ENT_QUOTES); ?>"
                            >Delete</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="modal-overlay" id="addDoctorModal" role="dialog" aria-modal="true" aria-labelledby="addDoctorModalTitle" aria-hidden="true">
        <div class="modal">
            <div class="modal-head">
                <div>
                    <h2 id="addDoctorModalTitle">Add doctor account</h2>
                    <p>Create login, profile, and clinic schedule in one step.</p>
                </div>
                <button type="button" class="modal-close" id="closeAddDoctorModal" aria-label="Close">&times;</button>
            </div>
            <?php if ($error !== '' && $openAddDoctorModal): ?>
                <div class="ad-alert er"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="doctor_admin_action" value="add_doctor">
                <p class="schedule-hint">Username is generated automatically. Default password: <strong><?php echo htmlspecialchars($defaultDoctorPassword); ?></strong>.</p>
                <div class="form-grid">
                    <div class="name-grid full">
                        <div class="field"><label for="new_first_name">First name</label><input id="new_first_name" name="first_name" maxlength="15" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required></div>
                        <div class="field"><label for="new_middle_name">Middle name</label><input id="new_middle_name" name="middle_name" maxlength="1" placeholder="M" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"></div>
                        <div class="field"><label for="new_last_name">Last name</label><input id="new_last_name" name="last_name" maxlength="15" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required></div>
                        <div class="field"><label for="new_suffix">Suffix</label><input id="new_suffix" name="suffix" maxlength="3" placeholder="Jr" value="<?php echo htmlspecialchars($_POST['suffix'] ?? ''); ?>"></div>
                    </div>
                    <div class="field"><label for="new_specialty">Specialty</label><input id="new_specialty" name="specialty" placeholder="e.g. Pediatrician" value="<?php echo htmlspecialchars($_POST['specialty'] ?? ''); ?>"></div>
                    <div class="field"><label for="new_email">Email</label><input type="email" id="new_email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"></div>
                    <div class="field"><label for="new_phone">Phone</label><input id="new_phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"></div>
                </div>
                <div style="margin-top:16px">
                    <h3 style="margin:0 0 6px;color:#073b4c">Doctor schedule</h3>
                    <p class="schedule-hint">Add at least one day with start and end time.</p>
                    <div id="newSlotRows">
                        <?php foreach ($addFormSlots as $slot): ?>
                            <?php
                            $selectedDay = (int) ($slot['day_of_week'] ?? 1);
                            $start = substr((string) ($slot['time_start'] ?? '09:00'), 0, 5);
                            $end = substr((string) ($slot['time_end'] ?? '12:00'), 0, 5);
                            ?>
                            <div class="slot-row">
                                <div class="field">
                                    <label>Day</label>
                                    <select name="slot_day[]">
                                        <?php foreach ($dayNames as $dayNumber => $dayLabel): ?>
                                            <option value="<?php echo (int) $dayNumber; ?>" <?php echo $selectedDay === (int) $dayNumber ? 'selected' : ''; ?>><?php echo htmlspecialchars($dayLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="field"><label>Start</label><input type="time" name="slot_start[]" value="<?php echo htmlspecialchars($start); ?>" required></div>
                                <div class="field"><label>End</label><input type="time" name="slot_end[]" value="<?php echo htmlspecialchars($end); ?>" required></div>
                                <button type="button" class="ad-btn secondary" data-remove-slot>Remove</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="ad-btn secondary" data-add-new-slot>Add row</button>
                        <button type="button" class="ad-btn secondary" data-fill-hours data-target="newSlotRows" data-start="08:00" data-end="12:00">Fill AM</button>
                        <button type="button" class="ad-btn secondary" data-fill-hours data-target="newSlotRows" data-start="13:00" data-end="17:00">Fill PM</button>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="ad-btn" type="submit">Create doctor</button>
                    <button type="button" class="ad-btn secondary" id="cancelAddDoctorModal">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="statusDoctorModal" role="dialog" aria-modal="true" aria-labelledby="statusDoctorModalTitle" aria-hidden="true">
        <div class="modal status-confirm-modal">
            <div class="modal-head">
                <div>
                    <h2 id="statusDoctorModalTitle">Disable doctor account?</h2>
                    <p id="statusDoctorText">Are you sure you want to disable this doctor?</p>
                </div>
                <button type="button" class="modal-close" id="closeStatusDoctorModal" aria-label="Close">&times;</button>
            </div>
            <div class="status-warning">
                Doctor: <strong id="statusDoctorName">this doctor</strong>
            </div>
            <form method="post">
                <input type="hidden" name="doctor_admin_action" value="toggle_active">
                <input type="hidden" name="user_id" id="statusDoctorId" value="">
                <input type="hidden" name="set_active" id="statusDoctorState" value="">
                <div class="form-actions">
                    <button type="button" class="ad-btn secondary" id="cancelStatusDoctor">Cancel</button>
                    <button type="submit" class="ad-btn danger" id="statusDoctorSubmit">Disable doctor</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="deleteDoctorModal" role="dialog" aria-modal="true" aria-labelledby="deleteDoctorModalTitle" aria-hidden="true">
        <div class="modal delete-confirm-modal">
            <div class="modal-head">
                <div>
                    <h2 id="deleteDoctorModalTitle">Delete doctor account?</h2>
                    <p>This action cannot be undone.</p>
                </div>
                <button type="button" class="modal-close" id="closeDeleteDoctorModal" aria-label="Close">&times;</button>
            </div>
            <div class="delete-warning">
                You are about to permanently delete <span class="delete-doctor-name" id="deleteDoctorName">this doctor</span>.
                Existing appointments will remain in the system and will be marked as not assigned.
            </div>
            <form method="post" id="deleteDoctorForm">
                <input type="hidden" name="doctor_admin_action" value="delete_doctor">
                <input type="hidden" name="user_id" id="deleteDoctorId" value="">
                <div class="field">
                    <label for="deleteDoctorConfirmation">Type DELETE to confirm</label>
                    <input
                        type="text"
                        id="deleteDoctorConfirmation"
                        name="delete_confirmation"
                        placeholder="DELETE"
                        autocomplete="off"
                        spellcheck="false"
                        required
                    >
                </div>
                <div class="form-actions">
                    <button type="button" class="ad-btn secondary" id="cancelDeleteDoctor">Cancel</button>
                    <button type="submit" class="ad-btn danger" id="confirmDeleteDoctor" disabled>Delete doctor</button>
                </div>
            </form>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>

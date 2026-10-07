<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/doctor_schedule.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$pageTitle = 'User Management | Globalife Administration';
$currentUser = getCurrentUser();
$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);
ensurePatientProfilePhotoColumn($conn);
$dayNames = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'toggle_user') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $nextState = (int) ($_POST['next_state'] ?? 0) === 1 ? 1 : 0;

    if ($userId <= 0) {
        $_SESSION['error'] = 'Please choose a valid account.';
    } elseif ($userId === (int) ($currentUser['id'] ?? 0)) {
        $_SESSION['error'] = 'You cannot disable your own account.';
    } else {
        $checkStmt = $conn->prepare("SELECT role FROM users WHERE id = ? AND role IN ('patient', 'doctor') LIMIT 1");
        $checkStmt->bind_param('i', $userId);
        $checkStmt->execute();
        $toggleUser = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$toggleUser) {
            $_SESSION['error'] = 'Only patient and doctor accounts can be disabled here.';
        } else {
            $toggleRole = (string) $toggleUser['role'];
            $roleLabel = $toggleRole === 'doctor' ? 'Doctor' : 'Patient';
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role IN ('patient', 'doctor')");
            $stmt->bind_param('ii', $nextState, $userId);
            if ($stmt->execute()) {
                $_SESSION['success'] = $nextState === 1 ? $roleLabel . ' account enabled.' : $roleLabel . ' account disabled.';
            } else {
                $_SESSION['error'] = 'Account status could not be updated.';
            }
            $stmt->close();
        }
    }

    $conn->close();
    header('Location: admin_accounts.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'save_doctor_profile') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $suffix = trim((string) ($_POST['suffix'] ?? ''));
    $selectedSpecialty = trim((string) ($_POST['specialty'] ?? ''));
    $customSpecialty = trim((string) ($_POST['custom_specialty'] ?? ''));
    $specialty = $customSpecialty !== '' ? $customSpecialty : $selectedSpecialty;
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));

    if ($userId <= 0 || $firstName === '' || $lastName === '') {
        $_SESSION['error'] = 'Complete the doctor name before saving.';
    } else {
        $stmt = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, specialty = ?, email = ?, phone = ? WHERE id = ? AND role = 'doctor'");
        $stmt->bind_param('sssssssi', $firstName, $middleName, $lastName, $suffix, $specialty, $email, $phone, $userId);
        $saved = $stmt->execute();
        $_SESSION[$saved ? 'success' : 'error'] = $saved ? 'Doctor profile saved.' : 'Doctor profile could not be saved.';
        $stmt->close();
    }

    $conn->close();
    header('Location: admin_accounts.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'save_doctor_slots') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $days = $_POST['slot_day'] ?? [];
    $starts = $_POST['slot_start'] ?? [];
    $ends = $_POST['slot_end'] ?? [];

    if ($userId <= 0) {
        $_SESSION['error'] = 'Choose a valid doctor before saving schedule.';
    } else {
        $conn->begin_transaction();
        try {
            $delete = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
            $delete->bind_param('i', $userId);
            $delete->execute();
            $delete->close();

            $insert = $conn->prepare('INSERT INTO doctor_availability (user_id, day_of_week, time_start, time_end) VALUES (?, ?, ?, ?)');
            $rowCount = max(count($days), count($starts), count($ends));
            for ($i = 0; $i < $rowCount; $i++) {
                $day = (int) ($days[$i] ?? 0);
                $start = trim((string) ($starts[$i] ?? ''));
                $end = trim((string) ($ends[$i] ?? ''));
                if ($day < 1 || $day > 7 || $start === '' || $end === '') {
                    continue;
                }
                if (strlen($start) === 5) {
                    $start .= ':00';
                }
                if (strlen($end) === 5) {
                    $end .= ':00';
                }
                $insert->bind_param('iiss', $userId, $day, $start, $end);
                $insert->execute();
            }
            $insert->close();
            $conn->commit();
            $_SESSION['success'] = 'Doctor schedule saved.';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Doctor schedule could not be saved.';
        }
    }

    $conn->close();
    header('Location: admin_accounts.php');
    exit();
}

$message = (string) ($_SESSION['success'] ?? '');
$error = (string) ($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

$counts = ['admin' => 0, 'doctor' => 0, 'patient' => 0];
$countResult = $conn->query("SELECT CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END AS role, COUNT(*) AS total FROM users GROUP BY CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END");
while ($countResult && ($row = $countResult->fetch_assoc())) {
    if (isset($counts[$row['role']])) {
        $counts[$row['role']] = (int) $row['total'];
    }
}

$users = [];
$userNameSql = dbUsersNameExpression();
$result = $conn->query(
    "SELECT id, username, {$userNameSql} AS full_name, first_name, middle_name, last_name, suffix,
            CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END AS role,
            email, phone, profile_photo, profile_updated_at,
            gender, date_of_birth, age, civil_status, address, barangay, city,
            emergency_contact_name, emergency_contact_relationship, emergency_contact_number,
            created_at, COALESCE(is_active, 1) AS is_active
     FROM users
     ORDER BY FIELD(CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END, 'admin', 'doctor', 'patient'), {$userNameSql} ASC"
);
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}
foreach ($users as &$user) {
    if (($user['role'] ?? '') === 'doctor') {
        $user['slots'] = doctor_fetch_availability_slots($conn, (int) $user['id']);
    }
}
unset($user);

$patientAppointments = [];
$doctorNameSql = dbUsersNameExpression('d');
$appointmentResult = $conn->query(
    "SELECT a.id, a.patient_id, a.appointment_date, a.appointment_time, a.status, a.booking_type,
            a.notes, a.cancellation_reason, a.total_display_price,
            {$doctorNameSql} AS doctor_name
     FROM appointments a
     LEFT JOIN users d ON d.id = a.doctor_id
     INNER JOIN users p ON p.id = a.patient_id AND p.role = 'patient'
     WHERE a.status IN ('completed', 'cancelled')
     ORDER BY a.appointment_date DESC, a.appointment_time DESC"
);
if ($appointmentResult) {
    while ($row = $appointmentResult->fetch_assoc()) {
        $patientId = (int) ($row['patient_id'] ?? 0);
        if ($patientId <= 0) {
            continue;
        }
        if (!isset($patientAppointments[$patientId])) {
            $patientAppointments[$patientId] = [];
        }
        if (count($patientAppointments[$patientId]) >= 20) {
            continue;
        }
        $patientAppointments[$patientId][] = $row;
    }
}

function admin_accounts_booking_label(?string $type): string
{
    return [
        'consultation' => 'Consultation',
        'package' => 'Lab package',
        'individual' => 'Lab tests',
        'ultrasound' => 'Ultrasound',
    ][(string) $type] ?? ((string) ($type ?: 'Appointment'));
}

function admin_accounts_patient_value(?string $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : 'Not provided';
}

function admin_accounts_patient_date(?string $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? $value : 'Not provided';
}

function admin_accounts_status_label(string $status): string
{
    $status = strtolower(trim($status));
    return $status === 'cancelled' ? 'Cancelled' : ucfirst($status);
}

function admin_accounts_format_display_date(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '' || $date === '0000-00-00') {
        return 'N/A';
    }
    $dateTime = DateTime::createFromFormat('Y-m-d', $date);
    return $dateTime ? $dateTime->format('F d, Y') : $date;
}

function admin_accounts_appointment_detail(array $appointment, string $patientName): array
{
    $notesFull = trim((string) ($appointment['notes'] ?? ''));
    $servicesText = 'Not listed';
    if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notesFull, $matches)) {
        $servicesText = trim($matches[1]) !== '' ? trim($matches[1]) : 'Not listed';
    }

    $bookingTypeKey = (string) ($appointment['booking_type'] ?? '');
    $bookingType = admin_accounts_booking_label($bookingTypeKey);
    if ($bookingTypeKey === 'consultation') {
        $bookingType = 'Doctor consultation';
        $servicesText = 'Doctor consultation';
    } elseif ($bookingTypeKey === 'package') {
        $bookingType = 'Laboratory package';
    } elseif ($bookingTypeKey === 'individual') {
        $bookingType = 'Laboratory tests';
    } elseif ($bookingTypeKey === 'ultrasound') {
        $bookingType = 'Ultra sound';
        $servicesText = 'Ultra sound';
    }

    $totalAmount = isset($appointment['total_display_price']) && $appointment['total_display_price'] !== null
        ? 'PHP ' . number_format((float) $appointment['total_display_price'], 2)
        : 'N/A';
    $doctorName = trim((string) ($appointment['doctor_name'] ?? ''));
    $statusValue = strtolower((string) ($appointment['status'] ?? 'pending'));
    $cancellationReason = trim((string) ($appointment['cancellation_reason'] ?? ''));

    return [
        'reference' => '#' . (int) ($appointment['id'] ?? 0),
        'patient' => $patientName !== '' ? $patientName : 'N/A',
        'doctor' => $doctorName !== '' ? $doctorName : 'Not Assigned',
        'date' => admin_accounts_format_display_date($appointment['appointment_date'] ?? null),
        'time' => substr((string) ($appointment['appointment_time'] ?? ''), 0, 5),
        'status' => admin_accounts_status_label($statusValue),
        'statusKey' => $statusValue,
        'bookingType' => $bookingType,
        'totalAmount' => $totalAmount,
        'services' => $servicesText,
        'notes' => $notesFull !== '' ? $notesFull : 'None',
        'cancellationReason' => $cancellationReason !== '' ? $cancellationReason : 'None',
    ];
}

$patientProfiles = [];
foreach ($users as $user) {
    if (($user['role'] ?? '') !== 'patient') {
        continue;
    }
    $patientId = (int) $user['id'];
    $photoUrl = patientProfilePhotoUrl($user['profile_photo'] ?? null, $user['profile_updated_at'] ?? null);
    $appointments = [];
    foreach ($patientAppointments[$patientId] ?? [] as $appointment) {
        $appointments[] = [
            'id' => (int) ($appointment['id'] ?? 0),
            'date' => (string) ($appointment['appointment_date'] ?? ''),
            'time' => substr((string) ($appointment['appointment_time'] ?? ''), 0, 5),
            'status' => (string) ($appointment['status'] ?? ''),
            'type' => admin_accounts_booking_label($appointment['booking_type'] ?? null),
            'doctor' => trim((string) ($appointment['doctor_name'] ?? '')) !== '' ? (string) $appointment['doctor_name'] : '—',
            'detail' => admin_accounts_appointment_detail($appointment, (string) ($user['full_name'] ?? '')),
        ];
    }
    $patientProfiles[$patientId] = [
        'id' => $patientId,
        'full_name' => (string) ($user['full_name'] ?? ''),
        'username' => (string) ($user['username'] ?? ''),
        'email' => admin_accounts_patient_value($user['email'] ?? null),
        'phone' => admin_accounts_patient_value($user['phone'] ?? null),
        'gender' => admin_accounts_patient_value($user['gender'] ?? null),
        'age' => (isset($user['age']) && $user['age'] !== null && $user['age'] !== '') ? (string) (int) $user['age'] : 'Not provided',
        'date_of_birth' => admin_accounts_patient_date($user['date_of_birth'] ?? null),
        'civil_status' => admin_accounts_patient_value($user['civil_status'] ?? null),
        'address' => admin_accounts_patient_value($user['address'] ?? null),
        'location' => admin_accounts_patient_value(trim(implode(', ', array_filter([
            trim((string) ($user['city'] ?? '')),
            trim((string) ($user['barangay'] ?? '')),
        ])), ', ')),
        'emergency_contact' => admin_accounts_patient_value($user['emergency_contact_name'] ?? null),
        'emergency_relationship' => admin_accounts_patient_value($user['emergency_contact_relationship'] ?? null),
        'emergency_number' => admin_accounts_patient_value($user['emergency_contact_number'] ?? null),
        'created_at' => admin_accounts_patient_date(isset($user['created_at']) ? substr((string) $user['created_at'], 0, 10) : null),
        'is_active' => (int) ($user['is_active'] ?? 1) === 1,
        'photo' => $photoUrl ?: '',
        'initials' => patientProfileInitials((string) ($user['full_name'] ?? '')),
        'appointments' => $appointments,
    ];
}

$conn->close();

$staffCount = $counts['admin'] + $counts['doctor'];
$additionalStyles = patientAvatarStyles() . '
body{background:#f4f8fb;color:#1f343d}
.accounts-page{max-width:1180px;margin:0 auto;padding:34px 20px 48px}
.accounts-intro{display:grid;grid-template-columns:minmax(0,1fr) 430px;align-items:end;gap:24px;margin-bottom:22px}
.accounts-intro h1{margin:0 0 7px;color:#061a40;font-size:2.05rem;line-height:1.12}
.accounts-intro p{max-width:660px;margin:0;color:#607784;line-height:1.6}
.account-totals{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.account-total{min-width:0;min-height:96px;padding:18px 28px;border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06);display:flex;align-items:center;justify-content:center;gap:22px}
.account-total-icon{width:58px;height:58px;flex:0 0 58px;border-radius:50%;display:grid;place-items:center;background:#edf6ff;color:#0f7cc2}
.account-total-icon svg{display:block;width:29px;height:29px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;overflow:visible}
.account-symbol{position:relative;display:block;width:30px;height:30px}
.account-symbol::before{content:"";position:absolute;left:50%;top:5px;width:10px;height:10px;border:3px solid currentColor;border-radius:50%;transform:translateX(-50%);box-sizing:border-box}
.account-symbol::after{content:"";position:absolute;left:50%;top:18px;width:24px;height:13px;border:3px solid currentColor;border-bottom:0;border-radius:16px 16px 0 0;transform:translateX(-50%);box-sizing:border-box}
.account-total-copy{display:grid;gap:2px;min-width:92px;align-content:center}
.account-total span{display:block;color:#657b88;font-size:.92rem;font-weight:850}
.account-total strong{display:block;margin-top:2px;color:#0066cc;font-size:2rem;line-height:1}
.notice{margin-top:16px;padding:13px 15px;border-radius:8px;font-weight:800}
.notice.ok{border:1px solid #bfe6ce;background:#edf9f1;color:#17643a}
.notice.error{border:1px solid #ffd0d5;background:#fff0f0;color:#9d1c2c}
.account-layout{display:grid;grid-template-columns:1fr;gap:28px;margin-top:20px;align-items:start}
.account-panel{min-width:0;border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06);overflow:hidden}
.account-panel-head{padding:22px 22px 14px;border-bottom:1px solid #e1ebf0;display:flex;align-items:center;gap:14px}
.account-panel-icon{width:44px;height:44px;border-radius:8px;display:grid;place-items:center;background:#edf6ff;color:#0f7cc2;flex:0 0 auto}
.account-panel-icon svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.account-panel-head h2{margin:0;color:#073b4c;font-size:1.2rem}
.account-panel-head p{margin:5px 0 0;color:#657b88;font-size:.9rem;line-height:1.5}
.directory-tools input,.directory-tools select{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cfe0e9;border-radius:7px;background:#fff;color:#183b4d;padding:9px 11px;font:inherit}
.directory-tools input:focus,.directory-tools select:focus{border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.1);outline:none}
.directory-tools{display:grid;grid-template-columns:minmax(0,1fr) 190px 190px;gap:10px;padding:14px 22px;border-bottom:1px solid #e1ebf0}
.account-list{display:grid}
.account-list-head{display:grid;grid-template-columns:40px minmax(130px,1.2fr) minmax(135px,1fr) 82px 86px 150px;gap:10px;align-items:center;padding:12px 18px;border-bottom:1px solid #e4edf2;background:#f8fcff;color:#5f7280;font-size:.78rem;font-weight:950}
.account-row{display:grid;grid-template-columns:40px minmax(130px,1.2fr) minmax(135px,1fr) 82px 86px 150px;gap:10px;align-items:center;padding:12px 18px;border-bottom:1px solid #e4edf2}
.account-row-patient{cursor:pointer;transition:background .15s ease,box-shadow .15s ease}
.account-row-patient:hover,.account-row-patient:focus-visible{background:#f3faff;box-shadow:inset 3px 0 0 #0066cc;outline:none}
.account-row-patient .account-name{color:#0066cc}
.account-row-patient .account-actions{position:relative;z-index:1}
.patient-view-hint{color:#0066cc;font-size:.78rem;font-weight:900;white-space:nowrap}
.account-row:last-child{border-bottom:0}
.account-row.hidden,.account-row.page-hidden{display:none}
.account-avatar{display:flex;align-items:center;justify-content:center;width:40px;height:40px;border:1px solid #c8dce7;border-radius:50%;background:#eaf5fa;color:#0878b8;font-weight:900;overflow:hidden}
.account-avatar img{width:100%;height:100%;object-fit:cover}
.account-name{color:#073b4c;font-weight:900;font-size:.95rem;line-height:1.25}
.account-meta{display:flex;flex-wrap:wrap;gap:5px 10px;margin-top:4px;color:#667c88;font-size:.82rem}
.account-email{color:#667c88;font-size:.84rem;line-height:1.25;overflow-wrap:anywhere}
.account-role{display:flex;align-items:center}
.account-status{display:flex;align-items:center}
.account-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px;min-width:0}
.role-badge,.state-badge{display:inline-flex;align-items:center;min-height:27px;border-radius:14px;padding:4px 9px;font-size:.72rem;font-weight:900;text-transform:uppercase}
.role-badge.admin,.role-badge.doctor{background:#e7f2ff;color:#0066cc}
.role-badge.patient{background:#f2eaff;color:#6f42c1}
.state-badge.active{background:#e5f6eb;color:#17643a}
.state-badge.inactive{background:#fdecef;color:#a51220}
.edit-link,.toggle-user-btn{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border:1px solid #cde1ed;border-radius:6px;padding:6px 9px;background:#fff;color:#0066cc;font:inherit;font-size:.8rem;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.toggle-user-btn.danger{border-color:#ffc7cf;color:#c1121f}
.toggle-user-btn.restore{border-color:#bfe6ce;color:#17643a}
.account-action-muted{color:#90a3ad;font-size:.82rem;font-weight:800}
.empty-result{display:none;margin:18px 20px;padding:18px;border:1px dashed #c8dce6;border-radius:7px;color:#657b88;text-align:center}
.empty-result.show{display:block}
.account-list-footer{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 22px;border-top:1px solid #e4edf2;color:#667c88;font-size:.86rem}
.account-pagination{display:flex;align-items:center;gap:8px}
.page-btn{width:38px;height:38px;border:1px solid #d8e6ed;border-radius:7px;background:#fff;color:#0b4f80;font:inherit;font-weight:900;cursor:pointer}
.page-btn:hover:not(:disabled){border-color:#0f7cc2;color:#0066cc}
.page-btn.active{border-color:#0066cc;background:#0066cc;color:#fff;box-shadow:0 10px 20px rgba(0,102,204,.18)}
.page-btn:disabled{opacity:.45;cursor:not-allowed}
.account-modal{position:fixed;inset:0;z-index:4200;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(7,24,38,.54)}
.account-modal.is-open{display:flex}
.account-modal-card{width:min(460px,100%);border:1px solid #d8e6ed;border-radius:10px;background:#fff;box-shadow:0 24px 70px rgba(7,24,38,.26);overflow:hidden}
.doctor-account-modal-card{width:min(1060px,100%);max-height:88vh;overflow:auto;position:relative}
.account-modal-head{padding:22px;border-bottom:1px solid #e4edf2;background:#f8fcff}
.doctor-modal-close{position:absolute;top:16px;right:16px;width:30px;height:30px;border:0;border-radius:7px;background:transparent;color:#8aa0ad;font-size:1.25rem;line-height:1;font-weight:900;cursor:pointer;z-index:2}
.doctor-modal-close:hover{background:#eef4f8;color:#073b4c}
.account-modal-head h2{margin:0;color:#073b4c;font-size:1.25rem}
.account-modal-head p{margin:7px 0 0;color:#657b88;line-height:1.5}
.doctor-modal-grid{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.25fr);gap:18px;padding:18px 22px 22px}
.doctor-modal-panel{border:1px solid #d8e6ed;border-radius:8px;background:#f8fcff;padding:16px}
.doctor-modal-panel h3{margin:0 0 14px;color:#073b4c;font-size:1.05rem}
.doctor-modal-panel p{margin:0 0 12px;color:#657b88;line-height:1.45}
.doctor-name-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.doctor-field{display:grid;gap:6px;margin-bottom:11px}
.doctor-field label{color:#5f7280;font-size:.82rem;font-weight:950}
.doctor-field input,.doctor-field select{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cfe0e9;border-radius:7px;background:#fff;color:#183b4d;padding:9px 11px;font:inherit}
.doctor-slot-row{display:grid;grid-template-columns:minmax(135px,.9fr) minmax(130px,1fr) minmax(130px,1fr) auto;gap:10px;align-items:end;margin-bottom:10px}
.doctor-modal-actions{display:flex;flex-wrap:wrap;justify-content:flex-start;gap:10px;margin-top:12px}
.patient-detail-modal-card{width:min(980px,100%);max-height:90vh;overflow:auto;border-radius:16px;position:relative;box-shadow:0 28px 80px rgba(7,24,38,.22)}
.patient-modal-close{position:absolute;top:18px;right:18px;width:34px;height:34px;border:0;border-radius:8px;background:transparent;color:#8aa0ad;font-size:1.55rem;line-height:1;cursor:pointer;z-index:2}
.patient-modal-close:hover{background:#eef4f8;color:#073b4c}
.patient-detail-head{display:flex;align-items:flex-start;gap:18px;padding:24px 56px 20px 24px;border-bottom:1px solid #e8eef2;background:#fff}
.patient-detail-avatar{width:72px;height:72px;border:2px solid #e4edf2;border-radius:50%;background:#edf6ff;color:#0878b8;display:grid;place-items:center;font-weight:900;font-size:1.15rem;overflow:hidden;flex:0 0 72px}
.patient-detail-avatar img{width:100%;height:100%;object-fit:cover}
.patient-detail-head-copy{min-width:0;flex:1;padding-top:2px}
.patient-detail-head-copy h2{margin:0;color:#1a3342;font-size:1.65rem;font-weight:800;line-height:1.15;letter-spacing:-.02em}
.patient-detail-meta-line{display:flex;flex-wrap:wrap;align-items:center;gap:6px 0;margin-top:9px;color:#708792;font-size:.875rem;font-weight:500}
.patient-detail-meta-item{display:inline-flex;align-items:center;gap:7px}
.patient-detail-meta-item:not(:last-child)::after{content:"";width:4px;height:4px;margin:0 12px;border-radius:50%;background:#c8d4dc;flex:0 0 auto}
.patient-detail-meta-item svg{width:15px;height:15px;stroke:#94a7b3;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex:0 0 auto}
.patient-meta-empty{color:#9db0bc;font-weight:500}
.patient-detail-status{margin-top:12px}
.patient-status-badge{display:inline-flex;align-items:center;gap:6px;min-height:28px;border-radius:999px;padding:4px 12px;font-size:.7rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase}
.patient-status-badge.active{background:#e6f6ec;color:#168a45}
.patient-status-badge.inactive{background:#fdecef;color:#b42318}
.patient-status-badge .status-dot{width:7px;height:7px;border-radius:50%;background:currentColor}
.patient-detail-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:0;padding:0;background:#fff}
.patient-detail-panel{padding:22px 24px 24px;border-right:1px solid #e8eef2;min-height:100%}
.patient-detail-panel:last-child{border-right:0}
.patient-detail-panel h3{display:flex;align-items:center;gap:8px;margin:0 0 14px;color:#1a3342;font-size:1rem;font-weight:800}
.patient-detail-panel h3 svg{width:18px;height:18px;stroke:#0f7cc2;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.patient-detail-panel-appointments h3{margin-bottom:12px}
.patient-info-list{display:grid}
.patient-info-row{display:grid;grid-template-columns:22px minmax(120px,1fr) minmax(0,1.1fr);gap:10px 12px;align-items:center;padding:11px 0;border-bottom:1px solid #eef3f6}
.patient-info-row:last-child{border-bottom:0}
.patient-info-icon{display:grid;place-items:center;color:#94a7b3}
.patient-info-icon svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.patient-info-label{color:#708792;font-size:.875rem;font-weight:500}
.patient-info-value{color:#1a3342;font-size:.875rem;font-weight:700;text-align:right;line-height:1.35;overflow-wrap:anywhere}
.patient-info-value.is-empty{color:#9db0bc;font-weight:500}
.patient-appt-search-wrap{margin:0 0 14px;padding:0;border:0}
.patient-appt-search-label{display:block;margin-bottom:8px;color:#1a3342;font-size:.875rem;font-weight:700}
.patient-appt-search{width:100%;min-height:40px;box-sizing:border-box;border:1px solid #dce8ef;border-radius:8px;background:#f3f8fb;color:#1a3342;padding:9px 12px 9px 36px;font:inherit;font-size:.875rem;font-weight:500;background-image:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2716%27 height=%2716%27 fill=%27none%27 stroke=%27%2394a7b3%27 stroke-width=%272%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27%3E%3Ccircle cx=%277%27 cy=%277%27 r=%275%27/%3E%3Cpath d=%27M11 11l3 3%27/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:12px center}
.patient-appt-search:focus{border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.08);outline:none;background-color:#fff}
.patient-appt-search::placeholder{color:#9db0bc;font-weight:500}
.patient-appointment-list{display:grid;gap:10px;max-height:340px;overflow-y:auto;padding-right:4px;margin:0}
.patient-appt-pagination{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid #eef3f6}
.patient-appt-pagination.is-hidden{display:none}
.patient-appt-page-btn{min-width:36px;height:36px;padding:0 10px;border:1px solid #d8e6ed;border-radius:7px;background:#fff;color:#0b4f80;font:inherit;font-size:.82rem;font-weight:800;cursor:pointer}
.patient-appt-page-btn:hover:not(:disabled){border-color:#0f7cc2;color:#0066cc}
.patient-appt-page-btn.active{border-color:#0066cc;background:#0066cc;color:#fff;box-shadow:0 8px 18px rgba(0,102,204,.16)}
.patient-appt-page-btn:disabled{opacity:.45;cursor:not-allowed}
.patient-appt-page-btn.nav-btn{min-width:auto;padding:0 12px}
.patient-appointment-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;padding:13px 14px;border:1px solid #e4edf2;border-radius:10px;background:#fff;cursor:pointer;transition:border-color .15s ease,background .15s ease,box-shadow .15s ease}
.patient-appointment-item:hover,.patient-appointment-item:focus-visible{border-color:#b8d9f0;background:#f8fcff;box-shadow:0 4px 14px rgba(15,124,194,.08);outline:none}
.patient-appt-icon{width:38px;height:38px;border-radius:50%;background:#edf6ff;color:#0f7cc2;display:grid;place-items:center;flex:0 0 38px;pointer-events:none}
.patient-appt-icon svg{width:17px;height:17px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.patient-appt-main{min-width:0;display:grid;gap:3px}
.patient-appt-date{color:#1a3342;font-weight:700;font-size:.875rem;line-height:1.3}
.patient-appt-type{color:#5c6f7a;font-size:.82rem;font-weight:600}
.patient-appt-doctor{color:#94a7b3;font-size:.8rem;font-weight:500}
.appt-status{display:inline-flex;align-items:center;gap:5px;min-height:26px;border-radius:999px;padding:4px 10px;font-size:.65rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}
.appt-status svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.appt-status.pending{background:#fff6e6;color:#a16207}
.appt-status.confirmed{background:#e8f2ff;color:#0066cc}
.appt-status.completed{background:#e6f6ec;color:#168a45}
.appt-status.cancelled{background:#fdecef;color:#b42318}
.patient-empty-note{margin:0;padding:18px;border:1px dashed #dce8ef;border-radius:10px;color:#708792;text-align:center;font-size:.875rem;font-weight:600;background:#f8fcff}
.admin-appt-modal{position:fixed;inset:0;z-index:4300;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(3,18,30,.5);backdrop-filter:blur(4px)}
.admin-appt-modal.is-open{display:flex}
.admin-appt-modal-card{width:min(720px,100%);background:#fff;border-radius:22px;box-shadow:0 24px 70px rgba(2,62,138,.2);overflow:hidden;border:1px solid #cde8f3}
.admin-appt-modal-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:24px 26px;background:radial-gradient(circle at 90% 12%,rgba(72,202,228,.24),transparent 34%),linear-gradient(135deg,#fff 0%,#eefaff 100%);border-bottom:1px solid #d8eef7;color:#10233f}
.admin-appt-modal-head h3{margin:0;color:#10233f;font-size:1.28rem;line-height:1.2;font-weight:800}
.admin-appt-modal-head p{display:inline-flex;align-items:center;margin:8px 0 0;padding:7px 11px;border-radius:999px;background:#eaf8fc;color:#0077b6;font-size:.86rem;font-weight:800}
.admin-appt-modal-close{border:0;background:#eaf8fc;color:#0077b6;width:40px;height:40px;border-radius:13px;cursor:pointer;font-size:1.35rem;line-height:1;font-weight:900}
.admin-appt-modal-close:hover{background:#d8f2fb}
.admin-appt-modal-body{padding:22px 24px}
.admin-appt-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:18px}
.admin-appt-pill{background:#f8fdff;border:1px solid #d8eef7;border-radius:14px;padding:14px}
.admin-appt-pill span{display:block;color:#60758a;font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.admin-appt-pill strong{color:#10233f;font-size:1rem;font-weight:800;overflow-wrap:anywhere}
.admin-appt-detail-grid{display:grid;grid-template-columns:150px 1fr;gap:12px 16px;padding:16px;border:1px solid #d8eef7;border-radius:16px;background:#fff}
.admin-appt-detail-grid dt{color:#60758a;font-weight:800;margin:0}
.admin-appt-detail-grid dd{margin:0;color:#315c70;overflow-wrap:anywhere;line-height:1.45;font-weight:600}
.admin-appt-notes{margin-top:16px;padding:16px;border-radius:16px;background:#f8fdff;border:1px solid #d8eef7;color:#315c70;line-height:1.55;font-weight:600}
.admin-appt-notes strong{display:block;color:#10233f;margin-bottom:6px;font-weight:800}
.admin-appt-modal-actions{display:flex;justify-content:flex-end;gap:10px;padding:0 24px 24px}
.admin-appt-btn{min-height:40px;border:0;border-radius:10px;padding:0 16px;font:inherit;font-weight:800;cursor:pointer}
.admin-appt-btn.primary{background:#0077b6;color:#fff}
.admin-appt-btn.primary:hover{background:#005f8d}
.admin-appt-btn.light{background:#eef5fb;color:#023e8a}
.admin-appt-btn.light:hover{background:#dcecf8}
@media(max-width:720px){.admin-appt-summary{grid-template-columns:1fr}.admin-appt-detail-grid{grid-template-columns:1fr;gap:8px}}
.account-modal-body{display:grid;gap:8px;padding:18px 22px 0}
.account-modal-body label{color:#315466;font-size:.82rem;font-weight:900}
.account-modal-body input{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cfe0e9;border-radius:7px;background:#fff;color:#183b4d;padding:9px 11px;font:inherit}
.account-modal-body input:focus{border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.1);outline:none}
.account-modal-actions{display:flex;justify-content:flex-end;gap:10px;padding:18px 22px}
.modal-secondary,.modal-primary{min-height:40px;border-radius:7px;padding:8px 14px;font:inherit;font-weight:900;cursor:pointer}
.modal-secondary{border:1px solid #cde1ed;background:#eef7fc;color:#075985}
.modal-primary{border:1px solid #ffc7cf;background:#fff0f2;color:#c1121f}
.modal-primary.restore{border-color:#bfe6ce;background:#edf9f1;color:#17643a}
.account-success-card{width:min(390px,100%);border:1px solid #d8e6ed;border-radius:10px;background:#fff;box-shadow:0 24px 70px rgba(7,24,38,.26);padding:28px 24px;text-align:center}
.account-success-icon{display:inline-grid;place-items:center;width:58px;height:58px;margin-bottom:14px;border-radius:50%;background:#dcf7e7;color:#148047;font-size:1.8rem;font-weight:950}
.account-success-card h2{margin:0 0 8px;color:#073b4c;font-size:1.24rem}
.account-success-card p{margin:0 0 20px;color:#657b88;line-height:1.45}
@media(max-width:1000px){.accounts-intro,.account-layout,.doctor-modal-grid,.patient-detail-body{grid-template-columns:1fr}.patient-detail-panel{border-right:0;border-bottom:1px solid #e8eef2}.patient-detail-panel:last-child{border-bottom:0}.account-totals{max-width:560px}.directory-tools{grid-template-columns:minmax(0,1fr) 170px 170px}.account-row,.account-list-head{grid-template-columns:40px minmax(0,1fr) 82px 86px 150px}.account-email{display:none}.patient-info-row{grid-template-columns:22px minmax(0,1fr);grid-template-areas:"icon label" ". value"}.patient-info-icon{grid-area:icon}.patient-info-label{grid-area:label}.patient-info-value{grid-area:value;text-align:left;margin-top:-4px}}
@media(max-width:620px){.accounts-page{padding:20px 13px 38px}.accounts-intro h1{font-size:1.65rem}.account-totals{grid-template-columns:1fr}.directory-tools{grid-template-columns:1fr}.account-list-head{display:none}.account-row{grid-template-columns:auto minmax(0,1fr)}.account-email,.account-role,.account-status,.account-actions{grid-column:1/-1}.account-actions{justify-content:flex-start;padding-left:55px}.account-meta{display:grid;gap:4px}.account-list-footer{align-items:flex-start;flex-direction:column}.account-pagination{width:100%;justify-content:flex-end}}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const search = document.getElementById("accountSearch");
    const role = document.getElementById("accountRole");
    const status = document.getElementById("accountStatus");
    const empty = document.getElementById("accountNoMatches");
    const rows = Array.from(document.querySelectorAll("[data-account-row]"));
    const pageInfo = document.getElementById("accountPageInfo");
    const pagination = document.getElementById("accountPagination");
    const pageSize = 5;
    let currentPage = 1;
    let filteredRows = rows;

    function renderAccounts() {
        const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        currentPage = Math.min(currentPage, totalPages);
        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        rows.forEach(function (row) {
            row.classList.add("page-hidden");
        });
        filteredRows.slice(start, end).forEach(function (row) {
            row.classList.remove("page-hidden");
        });

        if (pageInfo) {
            if (filteredRows.length === 0) {
                pageInfo.textContent = "Showing 0 accounts";
            } else {
                pageInfo.textContent = "Showing " + (start + 1) + " to " + Math.min(end, filteredRows.length) + " of " + filteredRows.length + " accounts";
            }
        }

        if (!pagination) return;
        pagination.innerHTML = "";

        const prev = document.createElement("button");
        prev.type = "button";
        prev.className = "page-btn";
        prev.innerHTML = "&lsaquo;";
        prev.disabled = currentPage <= 1;
        prev.addEventListener("click", function () {
            currentPage -= 1;
            renderAccounts();
        });
        pagination.appendChild(prev);

        const current = document.createElement("button");
        current.type = "button";
        current.className = "page-btn active";
        current.textContent = currentPage;
        current.setAttribute("aria-current", "page");
        pagination.appendChild(current);

        const next = document.createElement("button");
        next.type = "button";
        next.className = "page-btn";
        next.innerHTML = "&rsaquo;";
        next.disabled = currentPage >= totalPages;
        next.addEventListener("click", function () {
            currentPage += 1;
            renderAccounts();
        });
        pagination.appendChild(next);
    }

    function filterAccounts() {
        const query = (search && search.value ? search.value : "").toLowerCase().trim();
        const selectedRole = role ? role.value : "";
        const selectedStatus = status ? status.value : "";
        filteredRows = [];
        rows.forEach(function (row) {
            const matchesText = !query || (row.getAttribute("data-search") || "").toLowerCase().includes(query);
            const matchesRole = !selectedRole || row.getAttribute("data-role") === selectedRole;
            const matchesStatus = !selectedStatus || row.getAttribute("data-status") === selectedStatus;
            const show = matchesText && matchesRole && matchesStatus;
            row.classList.toggle("hidden", !show);
            if (show) filteredRows.push(row);
        });
        currentPage = 1;
        if (empty) empty.classList.toggle("show", filteredRows.length === 0);
        renderAccounts();
    }

    if (search) search.addEventListener("input", filterAccounts);
    if (role) role.addEventListener("change", filterAccounts);
    if (status) status.addEventListener("change", filterAccounts);
    filterAccounts();

    const modal = document.getElementById("accountStatusModal");
    const modalTitle = document.getElementById("accountStatusTitle");
    const modalText = document.getElementById("accountStatusText");
    const modalUserId = document.getElementById("accountStatusUserId");
    const modalNextState = document.getElementById("accountStatusNextState");
    const modalSubmit = document.getElementById("accountStatusSubmit");
    const successModal = document.getElementById("accountSuccessModal");
    const successOk = document.getElementById("accountSuccessOk");
    const doctorModals = document.querySelectorAll("[data-account-doctor-modal]");

    function closeStatusModal() {
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
    }

    document.querySelectorAll("[data-toggle-account]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.stopPropagation();
            if (!modal || !modalTitle || !modalText || !modalUserId || !modalNextState || !modalSubmit) return;
            const name = button.getAttribute("data-user-name") || "this user";
            const nextState = button.getAttribute("data-next-state") || "0";
            const isRestore = nextState === "1";
            modalTitle.textContent = isRestore ? "Enable this user?" : "Disable this user?";
            modalText.textContent = isRestore
                ? "Are you sure you want to enable " + name + "? This account can log in again."
                : "Are you sure you want to disable " + name + "? This account cannot log in until enabled again.";
            modalUserId.value = button.getAttribute("data-user-id") || "";
            modalNextState.value = nextState;
            modalSubmit.textContent = isRestore ? "Enable User" : "Disable User";
            modalSubmit.classList.toggle("restore", isRestore);
            modal.classList.add("is-open");
            modal.setAttribute("aria-hidden", "false");
        });
    });

    document.querySelectorAll("[data-close-account-modal]").forEach(function (button) {
        button.addEventListener("click", closeStatusModal);
    });
    if (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) closeStatusModal();
        });
    }
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeStatusModal();
    });

    function closeSuccessModal() {
        if (!successModal) return;
        successModal.classList.remove("is-open");
        successModal.setAttribute("aria-hidden", "true");
    }

    if (successModal && successModal.classList.contains("is-open")) {
        if (successOk) successOk.focus();
    }
    if (successOk) successOk.addEventListener("click", closeSuccessModal);
    if (successModal) {
        successModal.addEventListener("click", function (event) {
            if (event.target === successModal) closeSuccessModal();
        });
    }
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeSuccessModal();
    });

    function closeDoctorModals() {
        doctorModals.forEach(function (modal) {
            modal.classList.remove("is-open");
            modal.setAttribute("aria-hidden", "true");
        });
    }

    document.querySelectorAll("[data-open-account-doctor]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.stopPropagation();
            const modal = document.getElementById(button.getAttribute("data-open-account-doctor") || "");
            if (!modal) return;
            modal.classList.add("is-open");
            modal.setAttribute("aria-hidden", "false");
        });
    });
    document.querySelectorAll("[data-close-account-doctor]").forEach(function (button) {
        button.addEventListener("click", closeDoctorModals);
    });
    doctorModals.forEach(function (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) closeDoctorModals();
        });
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeDoctorModals();
    });

    const patientModal = document.getElementById("patientDetailModal");
    const patientProfilesNode = document.getElementById("patientProfilesData");
    const patientProfiles = patientProfilesNode ? JSON.parse(patientProfilesNode.textContent || "{}") : {};
    const patientModalAvatar = document.getElementById("patientModalAvatar");
    const patientModalName = document.getElementById("patientModalName");
    const patientModalMeta = document.getElementById("patientModalMeta");
    const patientModalStatus = document.getElementById("patientModalStatus");
    const patientModalInfo = document.getElementById("patientModalInfo");
    const patientModalAppointments = document.getElementById("patientModalAppointments");
    const patientAppointmentSearch = document.getElementById("patientAppointmentSearch");
    const patientApptPagination = document.getElementById("patientApptPagination");
    let activePatientAppointments = [];
    let displayedPatientAppointments = [];
    let patientApptCurrentPage = 1;
    const patientApptPageSize = 5;
    const adminApptModal = document.getElementById("adminAppointmentDetailsModal");
    const adminApptSummary = document.getElementById("adminAppointmentDetailSummary");
    const adminApptGrid = document.getElementById("adminAppointmentDetailsGrid");
    const adminApptNotes = document.getElementById("adminAppointmentDetailNotes");
    const adminApptSub = document.getElementById("adminAppointmentDetailsSub");

    const iconUser = \'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>\';
    const iconPhone = \'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.89.31 1.76.57 2.6a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.48-1.14a2 2 0 0 1 2.11-.45c.84.26 1.71.45 2.6.57A2 2 0 0 1 22 16.92z"/></svg>\';
    const iconMail = \'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 7L2 7"/></svg>\';
    const iconCalendar = \'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>\';

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/\'/g, "&#39;");
    }

    function formatPatientDate(value) {
        if (!value || value === "Not provided") return "Not provided";
        if (value === "0000-00-00") return "0000-00-00";
        const date = new Date(value + "T00:00:00");
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleDateString("en-US", { year: "numeric", month: "short", day: "numeric" });
    }

    function isEmptyPatientValue(value) {
        return !value || value === "Not provided" || value === "—";
    }

    function isMutedPatientValue(value) {
        return isEmptyPatientValue(value) || value === "0" || value === "0000-00-00";
    }

    function formatAppointmentStatus(status) {
        const safe = String(status || "pending").toLowerCase();
        const label = safe.toUpperCase();
        let icon = \'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>\';
        if (safe === "completed") {
            icon = \'<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>\';
        } else if (safe === "cancelled") {
            icon = \'<svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>\';
        } else if (safe === "confirmed") {
            icon = \'<svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>\';
        }
        return \'<span class="appt-status \' + escapeHtml(safe) + \'">\' + icon + escapeHtml(label) + "</span>";
    }

    function renderPatientInfo(profile) {
        if (!patientModalInfo) return;
        const items = [
            { icon: iconMail, label: "Email", value: profile.email },
            { icon: iconPhone, label: "Phone", value: profile.phone },
            { icon: iconUser, label: "Sex", value: profile.gender },
            { icon: iconUser, label: "Age", value: profile.age },
            { icon: iconCalendar, label: "Date of Birth", value: formatPatientDate(profile.date_of_birth) },
            { icon: iconUser, label: "Civil Status", value: profile.civil_status },
            { icon: \'<svg viewBox="0 0 24 24"><path d="M3 10.5 12 4l9 6.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>\', label: "Address", value: profile.address },
            { icon: \'<svg viewBox="0 0 24 24"><path d="M12 21s7-4.35 7-10a7 7 0 1 0-14 0c0 5.65 7 10 7 10z"/><circle cx="12" cy="11" r="2.5"/></svg>\', label: "City / Barangay", value: profile.location },
            { icon: iconUser, label: "Contact Person", value: profile.emergency_contact },
            { icon: iconUser, label: "Relationship", value: profile.emergency_relationship },
            { icon: iconPhone, label: "Contact Number", value: profile.emergency_number },
            { icon: iconCalendar, label: "Registered", value: formatPatientDate(profile.created_at) }
        ];
        patientModalInfo.innerHTML = items.map(function (item) {
            const emptyClass = isMutedPatientValue(item.value) ? " is-empty" : "";
            return \'<div class="patient-info-row">\' +
                \'<span class="patient-info-icon">\' + item.icon + "</span>" +
                \'<span class="patient-info-label">\' + escapeHtml(item.label) + "</span>" +
                \'<span class="patient-info-value\' + emptyClass + \'">\' + escapeHtml(item.value) + "</span>" +
            "</div>";
        }).join("");
    }

    function formatMetaValue(value) {
        if (isEmptyPatientValue(value)) {
            return \'<span class="patient-meta-empty">Not provided</span>\';
        }
        return escapeHtml(value);
    }

    function renderPatientMeta(profile) {
        if (!patientModalMeta) return;
        const parts = [];
        if (profile.username) {
            parts.push(\'<span class="patient-detail-meta-item">\' + iconUser + escapeHtml(profile.username) + "</span>");
        }
        parts.push(\'<span class="patient-detail-meta-item">\' + iconPhone + formatMetaValue(profile.phone) + "</span>");
        parts.push(\'<span class="patient-detail-meta-item">\' + iconMail + formatMetaValue(profile.email) + "</span>");
        patientModalMeta.innerHTML = parts.join("");
    }

    function getFilteredPatientAppointments() {
        const normalized = String(patientAppointmentSearch && patientAppointmentSearch.value ? patientAppointmentSearch.value : "").toLowerCase().trim();
        if (!normalized) {
            return activePatientAppointments.slice();
        }
        return activePatientAppointments.filter(function (appointment) {
            const haystack = [
                appointment.id,
                formatPatientDate(appointment.date),
                appointment.date,
                appointment.time,
                appointment.type,
                appointment.doctor,
                appointment.status,
                appointment.detail ? appointment.detail.notes : ""
            ].join(" ").toLowerCase();
            return haystack.includes(normalized);
        });
    }

    function renderPatientAppointmentCards(appointments, isFiltered) {
        if (!patientModalAppointments) return;
        if (!appointments.length) {
            const message = isFiltered ? "No appointments match your search." : "No appointments yet.";
            patientModalAppointments.innerHTML = \'<p class="patient-empty-note">\' + message + "</p>";
            return;
        }
        patientModalAppointments.innerHTML = appointments.map(function (appointment) {
            const dateLabel = formatPatientDate(appointment.date);
            const timeLabel = appointment.time ? appointment.time : "—";
            const doctorLabel = appointment.doctor && appointment.doctor !== "—" ? "Dr. " + appointment.doctor : "Dr. —";
            const apptId = appointment.id ? String(appointment.id) : "";
            return \'<article class="patient-appointment-item" data-patient-appt="\' + escapeHtml(apptId) + \'" tabindex="0" role="button" aria-label="View appointment details">\' +
                \'<span class="patient-appt-icon">\' + iconCalendar + "</span>" +
                \'<div class="patient-appt-main">\' +
                    \'<div class="patient-appt-date">\' + escapeHtml(dateLabel) + " · " + escapeHtml(timeLabel) + "</div>" +
                    \'<div class="patient-appt-type">\' + escapeHtml(appointment.type || "Appointment") + "</div>" +
                    \'<div class="patient-appt-doctor">\' + escapeHtml(doctorLabel) + "</div>" +
                "</div>" +
                formatAppointmentStatus(appointment.status) +
            "</article>";
        }).join("");
    }

    function findPatientAppointment(appointmentId) {
        return activePatientAppointments.find(function (appointment) {
            return String(appointment.id) === String(appointmentId);
        }) || null;
    }

    function displayValue(value) {
        return value === undefined || value === null || value === "" ? "N/A" : String(value);
    }

    function openAdminAppointmentDetails(appointmentId) {
        const appointment = findPatientAppointment(appointmentId);
        if (!appointment || !appointment.detail) return;
        const data = appointment.detail;

        if (adminApptSummary) {
            adminApptSummary.innerHTML = [
                ["Reference", data.reference],
                ["Status", data.status],
                ["Total", data.totalAmount]
            ].map(function (item) {
                return \'<div class="admin-appt-pill"><span>\' + escapeHtml(item[0]) + \'</span><strong>\' + escapeHtml(displayValue(item[1])) + "</strong></div>";
            }).join("");
        }

        if (adminApptGrid) {
            const items = [
                ["Patient", data.patient],
                ["Doctor", data.doctor],
                ["Date", data.date],
                ["Booking type", data.bookingType],
                ["Services", data.services]
            ];
            adminApptGrid.innerHTML = items.map(function (item) {
                return \'<dt>\' + escapeHtml(item[0]) + \'</dt><dd>\' + escapeHtml(displayValue(item[1])) + "</dd>";
            }).join("");
        }

        if (adminApptNotes) {
            let notesHtml = \'<strong>Notes</strong><span>\' + escapeHtml(displayValue(data.notes)) + "</span>";
            if (data.statusKey === "cancelled") {
                notesHtml += \'<strong style="margin-top:12px;">Cancellation reason</strong><span>\' + escapeHtml(displayValue(data.cancellationReason)) + "</span>";
            }
            adminApptNotes.innerHTML = notesHtml;
        }

        if (adminApptSub) {
            adminApptSub.textContent = displayValue(data.reference) + " • " + displayValue(data.status);
        }

        if (adminApptModal) {
            adminApptModal.classList.add("is-open");
            adminApptModal.setAttribute("aria-hidden", "false");
        }
    }

    function closeAdminAppointmentDetails() {
        if (!adminApptModal) return;
        adminApptModal.classList.remove("is-open");
        adminApptModal.setAttribute("aria-hidden", "true");
    }

    function renderPatientApptPagination(totalPages) {
        if (!patientApptPagination) return;
        if (totalPages <= 0 || displayedPatientAppointments.length === 0) {
            patientApptPagination.innerHTML = "";
            patientApptPagination.classList.add("is-hidden");
            return;
        }

        patientApptPagination.classList.remove("is-hidden");
        patientApptPagination.innerHTML = "";

        const prev = document.createElement("button");
        prev.type = "button";
        prev.className = "patient-appt-page-btn nav-btn";
        prev.textContent = "Previous";
        prev.disabled = patientApptCurrentPage <= 1;
        prev.addEventListener("click", function () {
            patientApptCurrentPage -= 1;
            refreshPatientAppointments();
        });
        patientApptPagination.appendChild(prev);

        for (let page = 1; page <= totalPages; page += 1) {
            const pageBtn = document.createElement("button");
            pageBtn.type = "button";
            pageBtn.className = "patient-appt-page-btn" + (page === patientApptCurrentPage ? " active" : "");
            pageBtn.textContent = String(page);
            pageBtn.setAttribute("aria-label", "Page " + page);
            if (page === patientApptCurrentPage) {
                pageBtn.setAttribute("aria-current", "page");
            }
            pageBtn.addEventListener("click", function () {
                patientApptCurrentPage = page;
                refreshPatientAppointments();
            });
            patientApptPagination.appendChild(pageBtn);
        }

        const next = document.createElement("button");
        next.type = "button";
        next.className = "patient-appt-page-btn nav-btn";
        next.textContent = "Next";
        next.disabled = patientApptCurrentPage >= totalPages;
        next.addEventListener("click", function () {
            patientApptCurrentPage += 1;
            refreshPatientAppointments();
        });
        patientApptPagination.appendChild(next);
    }

    function refreshPatientAppointments() {
        const isFiltered = !!(patientAppointmentSearch && patientAppointmentSearch.value.trim());
        displayedPatientAppointments = getFilteredPatientAppointments();
        const totalPages = Math.max(1, Math.ceil(displayedPatientAppointments.length / patientApptPageSize));

        if (displayedPatientAppointments.length === 0) {
            patientApptCurrentPage = 1;
            renderPatientAppointmentCards([], isFiltered);
            renderPatientApptPagination(0);
            return;
        }

        patientApptCurrentPage = Math.min(Math.max(patientApptCurrentPage, 1), totalPages);
        const start = (patientApptCurrentPage - 1) * patientApptPageSize;
        const pageItems = displayedPatientAppointments.slice(start, start + patientApptPageSize);
        renderPatientAppointmentCards(pageItems, isFiltered);
        renderPatientApptPagination(totalPages);
    }

    function filterPatientAppointments() {
        patientApptCurrentPage = 1;
        refreshPatientAppointments();
    }

    function openPatientModal(patientId) {
        if (!patientModal) return;
        const profile = patientProfiles[String(patientId)] || patientProfiles[patientId];
        if (!profile) return;

        if (patientModalName) patientModalName.textContent = profile.full_name || "Patient";
        renderPatientMeta(profile);
        if (patientModalStatus) {
            const active = !!profile.is_active;
            patientModalStatus.className = "patient-status-badge " + (active ? "active" : "inactive");
            patientModalStatus.innerHTML = \'<span class="status-dot" aria-hidden="true"></span><span>\' + (active ? "Active" : "Disabled") + "</span>";
        }
        if (patientModalAvatar) {
            if (profile.photo) {
                patientModalAvatar.innerHTML = \'<img src="\' + escapeHtml(profile.photo) + \'" alt="">\';
            } else {
                patientModalAvatar.textContent = profile.initials || "P";
            }
        }

        renderPatientInfo(profile);
        activePatientAppointments = Array.isArray(profile.appointments) ? profile.appointments.slice() : [];
        displayedPatientAppointments = [];
        patientApptCurrentPage = 1;
        if (patientAppointmentSearch) patientAppointmentSearch.value = "";
        refreshPatientAppointments();
        patientModal.classList.add("is-open");
        patientModal.setAttribute("aria-hidden", "false");
    }

    function closePatientModal() {
        if (!patientModal) return;
        closeAdminAppointmentDetails();
        patientModal.classList.remove("is-open");
        patientModal.setAttribute("aria-hidden", "true");
        activePatientAppointments = [];
        displayedPatientAppointments = [];
        patientApptCurrentPage = 1;
        if (patientAppointmentSearch) patientAppointmentSearch.value = "";
        if (patientApptPagination) {
            patientApptPagination.innerHTML = "";
            patientApptPagination.classList.add("is-hidden");
        }
    }

    if (patientAppointmentSearch) {
        patientAppointmentSearch.addEventListener("input", filterPatientAppointments);
    }

    if (patientModalAppointments) {
        patientModalAppointments.addEventListener("click", function (event) {
            const card = event.target.closest("[data-patient-appt]");
            if (!card) return;
            openAdminAppointmentDetails(card.getAttribute("data-patient-appt"));
        });
        patientModalAppointments.addEventListener("keydown", function (event) {
            const card = event.target.closest("[data-patient-appt]");
            if (!card) return;
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                openAdminAppointmentDetails(card.getAttribute("data-patient-appt"));
            }
        });
    }

    document.querySelectorAll("[data-close-admin-appt-modal]").forEach(function (button) {
        button.addEventListener("click", closeAdminAppointmentDetails);
    });
    if (adminApptModal) {
        adminApptModal.addEventListener("click", function (event) {
            if (event.target === adminApptModal) closeAdminAppointmentDetails();
        });
    }
    const adminApptPrint = document.getElementById("adminAppointmentPrint");
    if (adminApptPrint) {
        adminApptPrint.addEventListener("click", function () {
            window.print();
        });
    }

    document.querySelectorAll("[data-close-patient-modal]").forEach(function (button) {
        button.addEventListener("click", closePatientModal);
    });

    document.querySelectorAll("[data-open-patient]").forEach(function (row) {
        row.addEventListener("click", function () {
            openPatientModal(row.getAttribute("data-open-patient"));
        });
        row.addEventListener("keydown", function (event) {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                openPatientModal(row.getAttribute("data-open-patient"));
            }
        });
    });

    if (patientModal) {
        patientModal.addEventListener("click", function (event) {
            if (event.target === patientModal) closePatientModal();
        });
    }
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            if (adminApptModal && adminApptModal.classList.contains("is-open")) {
                closeAdminAppointmentDetails();
                return;
            }
            closePatientModal();
        }
    });
});
';

include 'includes/header.php';
?>
<main class="accounts-page">
    <section class="accounts-intro">
        <div>
            <h1>Manage Users</h1>
            <p>Manage clinic staff accounts and patient access.</p>
        </div>
        <div class="account-totals" aria-label="Account totals">
            <div class="account-total">
                <span class="account-total-icon" aria-hidden="true">
                    <span class="account-symbol"></span>
                </span>
                <div class="account-total-copy"><span>Staff</span><strong><?php echo $staffCount; ?></strong></div>
            </div>
            <div class="account-total">
                <span class="account-total-icon" aria-hidden="true">
                    <span class="account-symbol"></span>
                </span>
                <div class="account-total-copy"><span>Patients</span><strong><?php echo $counts['patient']; ?></strong></div>
            </div>
        </div>
    </section>

    <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="account-layout">
        <section class="account-panel">
            <div class="account-panel-head">
                <span class="account-panel-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <div>
                    <h2>Accounts List</h2>
                    <p>Search all registered clinic staff and patients.</p>
                </div>
            </div>
            <div class="directory-tools" role="search">
                <input type="search" id="accountSearch" placeholder="Search name, username, or email" aria-label="Search accounts">
                <select id="accountRole" aria-label="Filter account role">
                    <option value="">All roles</option>
                    <option value="admin">Administrators</option>
                    <option value="doctor">Doctors</option>
                    <option value="patient">Patients</option>
                </select>
                <select id="accountStatus" aria-label="Filter account status">
                    <option value="">All status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="empty-result" id="accountNoMatches">No accounts match your search.</div>
            <div class="account-list">
                <div class="account-list-head" aria-hidden="true">
                    <span></span>
                    <span>Name</span>
                    <span>Email</span>
                    <span>Role</span>
                    <span>Status</span>
                    <span>Actions</span>
                </div>
                <?php foreach ($users as $user): ?>
                    <?php
                    $userRole = (string) $user['role'];
                    $active = (int) $user['is_active'] === 1;
                    $isCurrentUser = (int) $user['id'] === (int) ($currentUser['id'] ?? 0);
                    $emailText = trim((string) ($user['email'] ?? ''));
                    $emailText = $emailText !== '' ? $emailText : 'No email';
                    $photoUrl = patientProfilePhotoUrl($user['profile_photo'] ?? null, $user['profile_updated_at'] ?? null);
                    $searchText = implode(' ', [
                        $user['full_name'] ?? '',
                        $user['username'] ?? '',
                        $user['email'] ?? '',
                        $userRole,
                    ]);
                    $doctorModalId = 'accountDoctorModal' . (int) $user['id'];
                    $doctorSlots = $userRole === 'doctor'
                        ? (($user['slots'] ?? []) ?: [['day_of_week' => 1, 'time_start' => '09:00:00', 'time_end' => '12:00:00']])
                        : [];
                    ?>
                    <article class="account-row<?php echo $userRole === 'patient' ? ' account-row-patient' : ''; ?>" data-account-row data-role="<?php echo htmlspecialchars($userRole); ?>" data-status="<?php echo $active ? 'active' : 'inactive'; ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>"<?php if ($userRole === 'patient'): ?> data-open-patient="<?php echo (int) $user['id']; ?>" tabindex="0" role="button" aria-label="View <?php echo htmlspecialchars((string) $user['full_name'], ENT_QUOTES); ?> profile"<?php endif; ?>>
                        <div class="account-avatar">
                            <?php if ($photoUrl): ?>
                                <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="">
                            <?php else: ?>
                                <?php echo htmlspecialchars(patientProfileInitials((string) $user['full_name'])); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="account-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="account-meta">
                                <span><?php echo htmlspecialchars($user['username']); ?></span>
                            </div>
                        </div>
                        <div class="account-email"><?php echo htmlspecialchars($emailText); ?></div>
                        <div class="account-role">
                            <span class="role-badge <?php echo htmlspecialchars($userRole); ?>"><?php echo htmlspecialchars($userRole); ?></span>
                        </div>
                        <div class="account-status">
                            <span class="state-badge <?php echo $active ? 'active' : 'inactive'; ?>"><?php echo $active ? 'Active' : 'Disabled'; ?></span>
                        </div>
                        <div class="account-actions">
                            <?php if ($userRole === 'patient'): ?>
                                <span class="patient-view-hint">View profile</span>
                            <?php endif; ?>
                            <?php if ($userRole === 'doctor'): ?>
                                <button type="button" class="edit-link" data-open-account-doctor="<?php echo htmlspecialchars($doctorModalId); ?>">Edit</button>
                            <?php endif; ?>
                            <?php if ($userRole === 'doctor' || $userRole === 'patient'): ?>
                                <button
                                    type="button"
                                    class="toggle-user-btn <?php echo $active ? 'danger' : 'restore'; ?>"
                                    data-toggle-account
                                    data-user-id="<?php echo (int) $user['id']; ?>"
                                    data-user-name="<?php echo htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-next-state="<?php echo $active ? '0' : '1'; ?>"
                                ><?php echo $active ? 'Disable' : 'Enable'; ?></button>
                            <?php endif; ?>
                            <?php if ($isCurrentUser && $userRole !== 'doctor' && $userRole !== 'patient'): ?>
                                <span class="account-action-muted">Current</span>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php if ($userRole === 'doctor'): ?>
                        <div class="account-modal" id="<?php echo htmlspecialchars($doctorModalId); ?>" data-account-doctor-modal aria-hidden="true">
                            <div class="account-modal-card doctor-account-modal-card" role="dialog" aria-modal="true" aria-labelledby="<?php echo htmlspecialchars($doctorModalId); ?>Title">
                                <button type="button" class="doctor-modal-close" data-close-account-doctor aria-label="Close">&times;</button>
                                <div class="account-modal-head">
                                    <h2 id="<?php echo htmlspecialchars($doctorModalId); ?>Title">Manage <?php echo htmlspecialchars((string) $user['full_name']); ?></h2>
                                    <p>Edit profile details and update clinic schedule from one place.</p>
                                </div>
                                <div class="doctor-modal-grid">
                                    <section class="doctor-modal-panel">
                                        <h3>Profile</h3>
                                        <form method="post">
                                            <input type="hidden" name="account_action" value="save_doctor_profile">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                            <div class="doctor-name-grid">
                                                <div class="doctor-field"><label>First name</label><input name="first_name" value="<?php echo htmlspecialchars((string) ($user['first_name'] ?? '')); ?>" maxlength="15" required></div>
                                                <div class="doctor-field"><label>Middle name</label><input name="middle_name" value="<?php echo htmlspecialchars((string) ($user['middle_name'] ?? '')); ?>" maxlength="1"></div>
                                                <div class="doctor-field"><label>Last name</label><input name="last_name" value="<?php echo htmlspecialchars((string) ($user['last_name'] ?? '')); ?>" maxlength="15" required></div>
                                                <div class="doctor-field"><label>Suffix</label><input name="suffix" value="<?php echo htmlspecialchars((string) ($user['suffix'] ?? '')); ?>" maxlength="3"></div>
                                            </div>
                                            <?php
                                            $currentDoctorSpecialty = trim((string) ($user['specialty'] ?? ''));
                                            $presetSpecialties = ['General Doctor', 'Pediatrician', 'General Doctor / Pediatrician'];
                                            $usesCustomSpecialty = $currentDoctorSpecialty !== '' && !in_array($currentDoctorSpecialty, $presetSpecialties, true);
                                            ?>
                                            <div class="doctor-field">
                                                <label>Specialty</label>
                                                <select name="specialty">
                                                    <option value="" <?php echo $usesCustomSpecialty || $currentDoctorSpecialty === '' ? 'selected' : ''; ?>>Select specialty</option>
                                                    <?php foreach ($presetSpecialties as $presetSpecialty): ?>
                                                        <option value="<?php echo htmlspecialchars($presetSpecialty); ?>" <?php echo $currentDoctorSpecialty === $presetSpecialty ? 'selected' : ''; ?>><?php echo htmlspecialchars($presetSpecialty); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="doctor-field"><label>Custom specialty (optional)</label><input name="custom_specialty" placeholder="Type another specialty" value="<?php echo htmlspecialchars($usesCustomSpecialty ? $currentDoctorSpecialty : ''); ?>"></div>
                                            <div class="doctor-field"><label>Email (optional)</label><input type="email" name="email" value="<?php echo htmlspecialchars((string) ($user['email'] ?? '')); ?>"></div>
                                            <div class="doctor-field"><label>Phone (optional)</label><input name="phone" value="<?php echo htmlspecialchars((string) ($user['phone'] ?? '')); ?>"></div>
                                            <div class="doctor-modal-actions">
                                                <button class="modal-secondary restore" type="submit">Save profile</button>
                                            </div>
                                        </form>
                                    </section>
                                    <section class="doctor-modal-panel">
                                        <h3>Doctor schedule</h3>
                                        <p>Add one or more clinic hour rows. Leave all rows blank and save to clear the schedule.</p>
                                        <form method="post">
                                            <input type="hidden" name="account_action" value="save_doctor_slots">
                                            <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                            <?php foreach ($doctorSlots as $slot): ?>
                                                <?php
                                                $selectedDay = (int) ($slot['day_of_week'] ?? 1);
                                                $start = substr((string) ($slot['time_start'] ?? '09:00:00'), 0, 5);
                                                $end = substr((string) ($slot['time_end'] ?? '12:00:00'), 0, 5);
                                                ?>
                                                <div class="doctor-slot-row">
                                                    <div class="doctor-field">
                                                        <label>Day</label>
                                                        <select name="slot_day[]">
                                                            <?php foreach ($dayNames as $dayNumber => $dayLabel): ?>
                                                                <option value="<?php echo (int) $dayNumber; ?>" <?php echo $selectedDay === (int) $dayNumber ? 'selected' : ''; ?>><?php echo htmlspecialchars($dayLabel); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="doctor-field"><label>Start</label><input type="time" name="slot_start[]" value="<?php echo htmlspecialchars($start); ?>"></div>
                                                    <div class="doctor-field"><label>End</label><input type="time" name="slot_end[]" value="<?php echo htmlspecialchars($end); ?>"></div>
                                                    <button type="button" class="modal-secondary">Remove</button>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="doctor-modal-actions">
                                                <button class="modal-secondary restore" type="submit">Save schedule</button>
                                            </div>
                                        </form>
                                    </section>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="account-list-footer">
                <span id="accountPageInfo">Showing accounts</span>
                <div class="account-pagination" id="accountPagination" aria-label="Account pages"></div>
            </div>
        </section>
    </div>

    <div class="account-modal" id="patientDetailModal" aria-hidden="true">
        <div class="account-modal-card patient-detail-modal-card" role="dialog" aria-modal="true" aria-labelledby="patientModalName">
            <button type="button" class="patient-modal-close" data-close-patient-modal aria-label="Close">&times;</button>
            <div class="patient-detail-head">
                <div class="patient-detail-avatar" id="patientModalAvatar" aria-hidden="true">P</div>
                <div class="patient-detail-head-copy">
                    <h2 id="patientModalName">Patient</h2>
                    <div class="patient-detail-meta-line" id="patientModalMeta"></div>
                    <div class="patient-detail-status">
                        <span class="patient-status-badge active" id="patientModalStatus"><span class="status-dot" aria-hidden="true"></span><span>Active</span></span>
                    </div>
                </div>
            </div>
            <div class="patient-detail-body">
                <section class="patient-detail-panel">
                    <h3>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Personal Information
                    </h3>
                    <div class="patient-info-list" id="patientModalInfo"></div>
                </section>
                <section class="patient-detail-panel patient-detail-panel-appointments">
                    <h3>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        Appointment History
                    </h3>
                    <div class="patient-appt-search-wrap">
                        <label class="patient-appt-search-label" for="patientAppointmentSearch">Search appointments</label>
                        <input type="search" class="patient-appt-search" id="patientAppointmentSearch" placeholder="Search by date, type, doctor, or status..." aria-label="Search appointments">
                    </div>
                    <div class="patient-appointment-list" id="patientModalAppointments"></div>
                    <div class="patient-appt-pagination is-hidden" id="patientApptPagination" aria-label="Appointment pages"></div>
                </section>
            </div>
        </div>
    </div>

    <script type="application/json" id="patientProfilesData"><?php echo json_encode($patientProfiles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

    <div class="admin-appt-modal" id="adminAppointmentDetailsModal" aria-hidden="true">
        <div class="admin-appt-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminAppointmentDetailsTitle">
            <div class="admin-appt-modal-head">
                <div>
                    <h3 id="adminAppointmentDetailsTitle">Appointment details</h3>
                    <p id="adminAppointmentDetailsSub">Review schedule and notes</p>
                </div>
                <button type="button" class="admin-appt-modal-close" data-close-admin-appt-modal aria-label="Close">&times;</button>
            </div>
            <div class="admin-appt-modal-body">
                <div class="admin-appt-summary" id="adminAppointmentDetailSummary"></div>
                <dl class="admin-appt-detail-grid" id="adminAppointmentDetailsGrid"></dl>
                <div class="admin-appt-notes" id="adminAppointmentDetailNotes"></div>
            </div>
            <div class="admin-appt-modal-actions">
                <button type="button" class="admin-appt-btn primary" id="adminAppointmentPrint">Print details</button>
                <button type="button" class="admin-appt-btn light" data-close-admin-appt-modal>Close</button>
            </div>
        </div>
    </div>

    <div class="account-modal" id="accountStatusModal" aria-hidden="true">
        <form class="account-modal-card" method="post">
            <input type="hidden" name="account_action" value="toggle_user">
            <input type="hidden" name="user_id" id="accountStatusUserId" value="">
            <input type="hidden" name="next_state" id="accountStatusNextState" value="">
            <div class="account-modal-head">
                <h2 id="accountStatusTitle">Disable this user?</h2>
                <p id="accountStatusText">Are you sure you want to disable this user?</p>
            </div>
            <div class="account-modal-actions">
                <button class="modal-secondary" type="button" data-close-account-modal>Cancel</button>
                <button class="modal-primary" id="accountStatusSubmit" type="submit">Disable User</button>
            </div>
        </form>
    </div>

    <div class="account-modal<?php echo $message ? ' is-open' : ''; ?>" id="accountSuccessModal" aria-hidden="<?php echo $message ? 'false' : 'true'; ?>">
        <div class="account-success-card" role="dialog" aria-modal="true" aria-labelledby="accountSuccessTitle">
            <span class="account-success-icon" aria-hidden="true">✓</span>
            <h2 id="accountSuccessTitle">Successfully updated</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
            <button class="modal-secondary" type="button" id="accountSuccessOk">OK</button>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>

<?php
require_once 'includes/session.php';
checkRole('patient');

require_once 'config/database.php';
require_once 'includes/appointment_booking.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';
require_once __DIR__ . '/includes/doctor_schedule.php';

$currentUser = getCurrentUser();

if (isset($_GET['reset']) || isset($_GET['start'])) {
    unset($_SESSION['lab_booking']);
    header('Location: book_appointment.php');
    exit;
}

if (!isset($_SESSION['lab_booking']) || !is_array($_SESSION['lab_booking'])) {
    $_SESSION['lab_booking'] = [];
}
$bk =& $_SESSION['lab_booking'];
$defaults = [
    'step' => 1,
    'type' => null,
    'service_ids' => [],
    'doctor_id' => null,
    'consultation_specialty' => '',
    'price_channel' => 'opd',
    'appointment_date' => '',
    'appointment_time' => '',
    'calendar_ready' => false,
];
foreach ($defaults as $k => $v) {
    if (!array_key_exists($k, $bk)) {
        $bk[$k] = $v;
    }
}
$bk['price_channel'] = 'opd';

if (isset($_GET['step_back'])) {
    $cur = (int) ($bk['step'] ?? 1);
    if ($cur > 1) {
        if (($bk['type'] ?? '') === 'ultrasound' && $cur === 4) {
            $bk['step'] = 1;
        } else {
            $bk['step'] = $cur - 1;
        }
        if ($bk['step'] === 1) {
            $bk['type'] = null;
            $bk['service_ids'] = [];
            $bk['doctor_id'] = null;
            $bk['consultation_specialty'] = '';
            $bk['price_channel'] = 'opd';
            $bk['appointment_date'] = '';
            $bk['appointment_time'] = '';
            $bk['calendar_ready'] = false;
        } elseif ($bk['step'] === 2) {
            $bk['doctor_id'] = null;
            $bk['consultation_specialty'] = '';
            $bk['appointment_date'] = '';
            $bk['appointment_time'] = '';
        } elseif ($bk['step'] === 3) {
            $bk['doctor_id'] = null;
            $bk['appointment_date'] = '';
            $bk['appointment_time'] = '';
        } elseif (($bk['type'] ?? '') === 'ultrasound' && $bk['step'] < 4) {
            $bk['step'] = 1;
        } elseif ($bk['step'] <= 3) {
            $bk['appointment_time'] = '';
        }
    }
    header('Location: book_appointment.php');
    exit;
}

$conn = getDBConnection();
init_doctor_schema_and_accounts($conn);

$headerStmt = $conn->prepare("SELECT full_name, email, phone, profile_photo, profile_updated_at FROM users WHERE id = ?");
$headerStmt->bind_param("i", $currentUser['id']);
$headerStmt->execute();
$patientHeaderDetails = $headerStmt->get_result()->fetch_assoc() ?: [];
$headerStmt->close();
$headerPatientPhotoUrl = patientProfilePhotoUrl($patientHeaderDetails['profile_photo'] ?? null, $patientHeaderDetails['profile_updated_at'] ?? null);
$headerPatientInitials = patientProfileInitials($patientHeaderDetails['full_name'] ?? $currentUser['full_name']);
$headerPatientDisplayName = $patientHeaderDetails['full_name'] ?? $currentUser['full_name'];

$error = '';
$bookedId = isset($_GET['booked']) ? (int) $_GET['booked'] : 0;
$appointmentEmailWarning = (string) ($_SESSION['appointment_email_warning'] ?? '');
unset($_SESSION['appointment_email_warning']);

/**
 * Package deals in booking: first price sheet only (OPD pre-employment, sanitary permit, CVSU).
 *
 * @return array<int,array<string,mixed>>
 */
function fetchLabBookingPackages(mysqli $conn): array {
    $cats = lab_booking_package_only_categories();
    $placeholders = implode(',', array_fill(0, count($cats), '?'));
    $types = str_repeat('s', count($cats));
    $sql = "SELECT id, name, category, description, included_tests, opd_price, home_service_price, is_package FROM lab_services WHERE is_active = 1 AND is_package = 1 AND category IN ($placeholders) ORDER BY category, name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$cats);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Individual tests: second price sheet (excludes sheet-1 categories even if mis-tagged).
 *
 * @return array<int,array<string,mixed>>
 */
function fetchLabBookingIndividuals(mysqli $conn): array {
    $cats = lab_booking_package_only_categories();
    $placeholders = implode(',', array_fill(0, count($cats), '?'));
    $types = str_repeat('s', count($cats));
    $sql = "SELECT id, name, category, description, included_tests, opd_price, home_service_price, is_package FROM lab_services WHERE is_active = 1 AND is_package = 0 AND category NOT IN ($placeholders) ORDER BY category, name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$cats);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * @param int[] $ids
 * @return array<int,array<string,mixed>>
 */
function fetchServicesByIds(mysqli $conn, array $ids): array {
    if (empty($ids)) {
        return [];
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_filter($ids, fn ($i) => $i > 0);
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT id, name, category, description, included_tests, opd_price, home_service_price, is_package FROM lab_services WHERE is_active = 1 AND id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function serviceUnitPrice(array $svc, string $channel): float {
    if ($channel === 'home' && isset($svc['home_service_price']) && $svc['home_service_price'] !== null && (float) $svc['home_service_price'] > 0) {
        return (float) $svc['home_service_price'];
    }
    return (float) $svc['opd_price'];
}

function consultationDoctorUsernames(): array {
    return ['dra.mojica', 'dra.encina', 'dra.pangar', 'dra.tebelin', 'dra.aberia'];
}

function consultationDoctorDirectory(mysqli $conn): array {
    $usernames = consultationDoctorUsernames();
    $placeholders = implode(',', array_fill(0, count($usernames), '?'));
    $orderParts = [];
    foreach ($usernames as $index => $username) {
        $orderParts[] = "WHEN '" . $conn->real_escape_string($username) . "' THEN " . $index;
    }
    $sql = "SELECT id, username, full_name, specialty
            FROM users
            WHERE role = 'doctor'
              AND COALESCE(is_active, 1) = 1
              AND username IN ($placeholders)
            ORDER BY CASE username " . implode(' ', $orderParts) . ' ELSE 999 END, full_name';
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($usernames));
    $stmt->bind_param($types, ...$usernames);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['clinic_hours'] = doctor_format_clinic_hours_lines(
            doctor_fetch_availability_slots($conn, (int) $row['id'])
        );
    }
    unset($row);

    return $rows;
}

function consultationSpecialtyParts(string $specialty): array {
    $parts = preg_split('/\s*\/\s*/', trim($specialty));
    $parts = array_map('trim', is_array($parts) ? $parts : []);
    return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
}

function consultationSpecialties(array $doctors): array {
    $found = [];
    foreach ($doctors as $doctor) {
        foreach (consultationSpecialtyParts((string) ($doctor['specialty'] ?? 'General Doctor')) as $specialty) {
            $found[$specialty] = true;
        }
    }

    $ordered = [];
    foreach (['General Doctor', 'Pediatrician'] as $preferred) {
        if (isset($found[$preferred])) {
            $ordered[] = $preferred;
            unset($found[$preferred]);
        }
    }
    return array_merge($ordered, array_keys($found));
}

function consultationDoctorMatchesSpecialty(array $doctor, string $specialty): bool {
    $specialty = strtolower(trim($specialty));
    if ($specialty === '') {
        return false;
    }
    foreach (consultationSpecialtyParts((string) ($doctor['specialty'] ?? '')) as $part) {
        if (strtolower($part) === $specialty) {
            return true;
        }
    }
    return false;
}

function consultationDoctorNameKey(string $name): string {
    return strtolower((string) preg_replace('/\s+/', ' ', trim($name)));
}

function bookingAutomaticTime(mysqli $conn, array $booking, string $date): string {
    $dayOfWeek = (int) date('N', strtotime($date));
    $windows = [];

    if (($booking['type'] ?? '') === 'consultation' && !empty($booking['doctor_id'])) {
        foreach (doctor_fetch_availability_slots($conn, (int) $booking['doctor_id']) as $slot) {
            if ((int) ($slot['day_of_week'] ?? 0) === $dayOfWeek) {
                $windows[] = [
                    substr((string) $slot['time_start'], 0, 5),
                    substr((string) $slot['time_end'], 0, 5),
                ];
            }
        }
    } elseif (($booking['type'] ?? '') === 'ultrasound') {
        if (appointment_ultrasound_is_available_date($date)) {
            $windows[] = ['08:00', '17:00'];
        }
    } elseif ($dayOfWeek >= 1 && $dayOfWeek <= 6) {
        $windows[] = ['08:00', '17:00'];
    }

    $today = date('Y-m-d');
    $minimumTime = $date === $today ? date('H:i', strtotime('+15 minutes')) : '00:00';
    foreach ($windows as [$start, $end]) {
        $candidate = max($start, $minimumTime);
        if ($candidate <= $end) {
            return $candidate;
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['booking_action'] ?? '';

    if ($action === 'begin_booking') {
        $bk['appointment_date'] = '';
        $bk['appointment_time'] = '';
        $bk['doctor_id'] = null;
        $bk['consultation_specialty'] = '';
        $bk['calendar_ready'] = true;
        $bk['step'] = 1;
        header('Location: book_appointment.php');
        exit;
    }

    if ($action === 'select_type') {
        $t = $_POST['booking_type'] ?? '';
        if (!in_array($t, ['package', 'individual', 'consultation', 'ultrasound'], true)) {
            $error = 'Please choose a service type.';
        } else {
            $preferredDate = trim($_POST['preferred_date'] ?? '');
            if ($preferredDate !== '') {
                try {
                    $pickedDate = new DateTime($preferredDate);
                    $todayDate = new DateTime();
                    $todayDate->setTime(0, 0, 0);
                    if ($pickedDate >= $todayDate) {
                        $bk['appointment_date'] = $preferredDate;
                    }
                } catch (Exception $e) {
                    $bk['appointment_date'] = '';
                }
            }
            $bk['type'] = $t;
            $bk['service_ids'] = [];
            $bk['doctor_id'] = null;
            $bk['consultation_specialty'] = '';
            $bk['calendar_ready'] = true;
            $bk['step'] = $t === 'ultrasound' ? 4 : 2;
            header('Location: book_appointment.php');
            exit;
        }
    }

    if ($action === 'choose_services') {
        if ($bk['type'] === 'consultation') {
            $specialty = trim((string) ($_POST['consultation_specialty'] ?? ''));
            $availableSpecialties = consultationSpecialties(consultationDoctorDirectory($conn));
            if ($specialty === '' || !in_array($specialty, $availableSpecialties, true)) {
                $error = 'Please choose a doctor specialization.';
            } else {
                $bk['consultation_specialty'] = $specialty;
                $bk['doctor_id'] = null;
                $bk['service_ids'] = [];
                $bk['appointment_date'] = '';
                $bk['appointment_time'] = '';
                $bk['step'] = 3;
                header('Location: book_appointment.php');
                exit;
            }
        } elseif ($bk['type'] === 'package') {
            $pid = (int) ($_POST['package_id'] ?? 0);
            if ($pid <= 0) {
                $error = 'Please select a package.';
            } else {
                $bk['service_ids'] = [$pid];
                $bk['doctor_id'] = null;
                $bk['consultation_specialty'] = '';
                $bk['step'] = 3;
                header('Location: book_appointment.php');
                exit;
            }
        } elseif ($bk['type'] === 'individual') {
            $ids = $_POST['service_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $ids = array_map('intval', $ids);
            $ids = array_values(array_filter($ids, fn ($i) => $i > 0));
            if (empty($ids)) {
                $error = 'Select at least one laboratory test.';
            } else {
                $bk['service_ids'] = $ids;
                $bk['doctor_id'] = null;
                $bk['consultation_specialty'] = '';
                $bk['step'] = 3;
                header('Location: book_appointment.php');
                exit;
            }
        } else {
            $bk['step'] = 1;
            header('Location: book_appointment.php');
            exit;
        }
    }

    if ($action === 'choose_doctor') {
        if ($bk['type'] !== 'consultation') {
            $bk['step'] = 2;
            header('Location: book_appointment.php');
            exit;
        }
        $doctorId = (int) ($_POST['doctor_id'] ?? 0);
        $specialty = trim((string) ($bk['consultation_specialty'] ?? ''));
        $matchedDoctor = null;
        foreach (consultationDoctorDirectory($conn) as $doctor) {
            if ((int) ($doctor['id'] ?? 0) === $doctorId && consultationDoctorMatchesSpecialty($doctor, $specialty)) {
                $matchedDoctor = $doctor;
                break;
            }
        }
        if (!$matchedDoctor) {
            $error = 'Please choose a doctor for the selected specialization.';
        } else {
            $bk['doctor_id'] = $doctorId;
            $bk['appointment_date'] = '';
            $bk['appointment_time'] = '';
            $bk['step'] = 4;
            header('Location: book_appointment.php');
            exit;
        }
    }

    if ($action === 'set_channel') {
        $bk['price_channel'] = 'opd';
        if ($bk['type'] !== 'consultation') {
            $bk['doctor_id'] = null;
        }
        if (in_array($bk['type'], ['package', 'individual'], true) && empty($bk['service_ids'])) {
            $bk['step'] = 2;
            header('Location: book_appointment.php');
            exit;
        }
        $bk['step'] = 4;
        header('Location: book_appointment.php');
        exit;
    }

    if ($action === 'refresh_schedule') {
        $d = trim($_POST['appointment_date'] ?? '');
        if ($d !== '') {
            $bk['appointment_date'] = $d;
            $bk['appointment_time'] = bookingAutomaticTime($conn, $bk, $d);
        }
        if ($bk['type'] !== 'consultation') {
            $bk['doctor_id'] = null;
        }
        $bk['step'] = 4;
        header('Location: book_appointment.php');
        exit;
    }

    if ($action === 'set_schedule') {
        $d = trim($_POST['appointment_date'] ?? '');
        $bk['step'] = 4;
        if ($d !== '') {
            $bk['appointment_date'] = $d;
        }
        if ($d === '') {
            $error = 'Please choose an appointment date.';
            if ($bk['type'] !== 'consultation') {
                $bk['doctor_id'] = null;
            }
        } else {
            $t = bookingAutomaticTime($conn, $bk, $d);
            $selected_date = new DateTime($d);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            if ($selected_date < $today) {
                $error = 'Appointment date cannot be in the past.';
                if ($bk['type'] !== 'consultation') {
                    $bk['doctor_id'] = null;
                }
            } elseif ($bk['type'] === 'ultrasound' && !appointment_ultrasound_is_available_date($d)) {
                $error = 'Ultra sound appointments are available on Wednesday and Saturday only. Please choose another date.';
            } elseif ($t === '') {
                $error = 'There is no remaining availability on the selected date. Please choose another date.';
            } elseif ($bk['type'] === 'consultation' && empty($bk['doctor_id'])) {
                $error = 'Please choose a doctor before selecting a schedule.';
            } elseif ($bk['type'] === 'consultation' && !user_is_doctor_available_at($conn, (int) $bk['doctor_id'], $d, $t)) {
                $error = 'The selected doctor is not available on that date. Please choose one of the doctor schedule days.';
            } elseif ($bk['type'] === 'consultation' && appointment_doctor_day_capacity($conn, (int) $bk['doctor_id'], $d)['is_full']) {
                $capacity = appointment_doctor_day_capacity($conn, (int) $bk['doctor_id'], $d);
                $error = 'This doctor is fully booked on the selected date ('
                    . $capacity['booked'] . '/' . $capacity['limit']
                    . ' bookings). Please choose another date.';
            } elseif ($bk['type'] !== 'consultation' && appointment_lab_day_capacity($conn, $d)['is_full']) {
                $capacity = appointment_lab_day_capacity($conn, $d);
                $error = ($bk['type'] === 'ultrasound' ? 'Ultra sound appointments' : 'Laboratory appointments')
                    . ' are fully booked on the selected date ('
                    . $capacity['booked'] . '/' . $capacity['limit']
                    . ' bookings). Please choose another date.';
            } elseif ($bk['type'] !== 'consultation' && !appointment_clinic_is_open_at($d, $t)) {
                $error = 'The clinic is closed on the selected date. Please choose another date.';
            } else {
                if ($bk['type'] !== 'consultation') {
                    $bk['doctor_id'] = null;
                }
            }
            if ($error === '') {
                $bk['appointment_date'] = $d;
                $bk['appointment_time'] = $t;
                $bk['step'] = 5;
                header('Location: book_appointment.php');
                exit;
            }
        }
    }

    if ($action === 'confirm_booking') {
        $hasBookingSelection = $bk['type'] === 'consultation'
            ? !empty($bk['doctor_id'])
            : ($bk['type'] === 'ultrasound' || !empty($bk['service_ids']));
        if ($bk['step'] < 5 || !$hasBookingSelection || $bk['type'] === null) {
            $bk['step'] = 1;
            header('Location: book_appointment.php');
            exit;
        }
        if ($bk['type'] !== 'consultation') {
            $bk['doctor_id'] = null;
        }

        if ($error === '') {
            $booking = appointment_create_direct(
                $conn,
                (int) $currentUser['id'],
                [
                    'type' => $bk['type'],
                    'service_ids' => $bk['service_ids'],
                    'doctor_id' => $bk['type'] === 'consultation' && !empty($bk['doctor_id']) ? (int) $bk['doctor_id'] : null,
                    'price_channel' => $bk['price_channel'],
                    'appointment_date' => $bk['appointment_date'],
                    'appointment_time' => $bk['appointment_time'],
                ]
            );

            if (!$booking['ok']) {
                $error = (string) $booking['error'];
            } else {
                unset($_SESSION['lab_booking']);
                $failedChannels = [];
                if (empty($booking['email_sent'])) {
                    $failedChannels[] = 'email';
                }
                if (empty($booking['sms_sent'])) {
                    $failedChannels[] = 'SMS';
                }
                if ($failedChannels) {
                    $_SESSION['appointment_email_warning'] = 'Your appointment was saved, but the '
                        . implode(' and ', $failedChannels)
                        . ' notification could not be delivered. You can still view the booking in My Appointments.';
                }
                $appointmentId = (int) $booking['appointment_id'];
                $conn->close();
                header('Location: book_appointment.php?booked=' . $appointmentId);
                exit;
            }
        }
    }
}

$step = (int) ($bk['step'] ?? 1);
if ($step < 1) {
    $step = 1;
}
if ($step > 5) {
    $step = 5;
}

if ($step === 2 && empty($bk['type'])) {
    $step = 1;
    $bk['step'] = 1;
}
if ($bk['type'] === 'ultrasound' && in_array($step, [2, 3], true)) {
    $step = 4;
    $bk['step'] = 4;
}
if ($step >= 3 && $bk['type'] === 'consultation' && trim((string) ($bk['consultation_specialty'] ?? '')) === '') {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 4 && $bk['type'] === 'consultation' && empty($bk['doctor_id'])) {
    $step = 3;
    $bk['step'] = 3;
}
if ($step >= 3 && in_array($bk['type'], ['package', 'individual'], true) && empty($bk['service_ids'])) {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 4 && in_array($bk['type'], ['package', 'individual'], true) && empty($bk['service_ids'])) {
    $step = 2;
    $bk['step'] = 2;
}
if ($step >= 5 && ($bk['appointment_date'] === '' || $bk['appointment_time'] === '')) {
    $step = 4;
    $bk['step'] = 4;
}
if ($step === 1 && empty($bk['calendar_ready'])) {
    $bk['appointment_date'] = '';
    $bk['appointment_time'] = '';
    $bk['doctor_id'] = null;
    $bk['consultation_specialty'] = '';
}

$packageServices = [];
$individualServices = [];
$bookingDoctors = [];
$consultationSpecialties = [];
$consultationDoctorsForSpecialty = [];
$selectedSpecialty = trim((string) ($bk['consultation_specialty'] ?? ''));
if ($bk['type'] === 'package') {
    $packageServices = lab_order_package_services(fetchLabBookingPackages($conn));
}
if ($bk['type'] === 'individual') {
    $individualServices = fetchLabBookingIndividuals($conn);
}
if ($bk['type'] === 'consultation') {
    $bookingDoctors = consultationDoctorDirectory($conn);
    $consultationSpecialties = consultationSpecialties($bookingDoctors);
    $consultationDoctorsForSpecialty = array_values(array_filter(
        $bookingDoctors,
        static fn (array $doctor): bool => consultationDoctorMatchesSpecialty($doctor, $selectedSpecialty)
    ));
}

$groupedPackages = !empty($packageServices) ? lab_group_services_list($packageServices) : [];
$groupedIndividual = !empty($individualServices) ? lab_group_services_list($individualServices) : [];

$selectedServices = [];
$displayTotal = 0;
if (in_array($bk['type'], ['package', 'individual'], true) && !empty($bk['service_ids'])) {
    $selectedServices = fetchServicesByIds($conn, $bk['service_ids']);
    if (count($selectedServices) !== count($bk['service_ids'])) {
        $selectedServices = [];
        $bk['service_ids'] = [];
        $bk['step'] = 2;
        $step = 2;
    } else {
        foreach ($selectedServices as $s) {
            if (!lab_booking_service_matches_type($s, $bk['type'])) {
                $selectedServices = [];
                $bk['service_ids'] = [];
                $bk['step'] = 2;
                $step = 2;
                break;
            }
        }
    }
    if (!empty($selectedServices)) {
        if (($bk['type'] ?? '') === 'package') {
            $bk['price_channel'] = 'opd';
            $ch = 'opd';
        } else {
            $ch = $bk['price_channel'] === 'home' ? 'home' : 'opd';
        }
        foreach ($selectedServices as $s) {
            $displayTotal += serviceUnitPrice($s, $ch);
        }
    }
}

$calendarSelected = trim((string) ($bk['appointment_date'] ?? ''));
$calendarRequestedMonth = trim($_GET['calendar_month'] ?? '');
$calendarBaseDate = preg_match('/^\d{4}-\d{2}$/', $calendarRequestedMonth) ? ($calendarRequestedMonth . '-01') : ($calendarSelected !== '' ? $calendarSelected : date('Y-m-d'));
try {
    $calendarBase = new DateTime($calendarBaseDate);
} catch (Exception $e) {
    $calendarBase = new DateTime();
}
$calendarMonthStart = (clone $calendarBase)->modify('first day of this month');
$calendarMonthLabel = $calendarMonthStart->format('F Y');
$calendarFirstWeekday = (int) $calendarMonthStart->format('w');
$calendarDaysInMonth = (int) $calendarMonthStart->format('t');
$calendarToday = date('Y-m-d');
$calendarMonthEnd = (clone $calendarMonthStart)->modify('first day of next month')->format('Y-m-d');
$calendarPrevMonth = (clone $calendarMonthStart)->modify('-1 month')->format('Y-m');
$calendarNextMonth = (clone $calendarMonthStart)->modify('+1 month')->format('Y-m');
$calendarStartValue = $calendarMonthStart->format('Y-m-d');
$calendarDoctorSlotsByDow = [];
for ($dow = 1; $dow <= 7; $dow++) {
    $calendarDoctorSlotsByDow[$dow] = [];
}
$selectedDoctor = null;
if ($bk['type'] === 'consultation' && !empty($bk['doctor_id'])) {
    $selectedDoctorStmt = $conn->prepare(
        "SELECT id, full_name, specialty FROM users
         WHERE id = ? AND role = 'doctor' AND COALESCE(is_active, 1) = 1 LIMIT 1"
    );
    $selectedDoctorId = (int) $bk['doctor_id'];
    $selectedDoctorStmt->bind_param('i', $selectedDoctorId);
    $selectedDoctorStmt->execute();
    $selectedDoctor = $selectedDoctorStmt->get_result()->fetch_assoc() ?: null;
    $selectedDoctorStmt->close();
    if ($selectedDoctor) {
        $selectedDoctor['clinic_hours'] = doctor_format_clinic_hours_lines(
            doctor_fetch_availability_slots($conn, (int) $selectedDoctor['id'])
        );
        $isAllowedConsultationDoctor = false;
        foreach ($bookingDoctors as $doctor) {
            if (
                (int) ($doctor['id'] ?? 0) === (int) $selectedDoctor['id']
                && consultationDoctorMatchesSpecialty($doctor, $selectedSpecialty)
            ) {
                $isAllowedConsultationDoctor = true;
                break;
            }
        }
        if (!$isAllowedConsultationDoctor) {
            $selectedDoctor = null;
            $bk['doctor_id'] = null;
            if ($step >= 4) {
                $step = 3;
                $bk['step'] = 3;
            }
        }
    }
}
$consultationDoctorKeys = array_fill_keys(
    array_map(
        static fn (array $doctor): string => consultationDoctorNameKey((string) $doctor['full_name']),
        $bookingDoctors ?: consultationDoctorDirectory($conn)
    ),
    true
);
$doctorSlotSql = "SELECT u.id, u.full_name, u.specialty, da.day_of_week, da.time_start, da.time_end
    FROM doctor_availability da
    INNER JOIN users u ON u.id = da.user_id
    WHERE u.role = 'doctor' AND COALESCE(u.is_active, 1) = 1
      AND da.time_start < da.time_end";
if ($selectedDoctor) {
    $doctorSlotSql .= ' AND u.id = ' . (int) $selectedDoctor['id'];
}
$doctorSlotSql .= ' ORDER BY da.day_of_week, da.time_start, u.full_name';
$doctorSlotResult = $conn->query($doctorSlotSql);
if ($doctorSlotResult) {
    while ($slot = $doctorSlotResult->fetch_assoc()) {
        if ($bk['type'] === 'consultation' && empty($consultationDoctorKeys[consultationDoctorNameKey((string) ($slot['full_name'] ?? ''))])) {
            continue;
        }
        $dow = (int) ($slot['day_of_week'] ?? 0);
        if ($dow < 1 || $dow > 7) {
            continue;
        }
        $doctorSlotId = (int) $slot['id'];
        $calendarDoctorSlotsByDow[$dow][$doctorSlotId] = [
            'id' => (int) $slot['id'],
            'doctor' => (string) $slot['full_name'],
            'specialty' => (string) ($slot['specialty'] ?? ''),
            'start' => substr((string) $slot['time_start'], 0, 5),
            'end' => substr((string) $slot['time_end'], 0, 5),
        ];
    }
}
$calendarDoctorIds = [];
foreach ($calendarDoctorSlotsByDow as $dow => $doctorSlots) {
    $calendarDoctorSlotsByDow[$dow] = array_values($doctorSlots);
    foreach ($calendarDoctorSlotsByDow[$dow] as $slot) {
        $calendarDoctorIds[(int) $slot['id']] = true;
    }
}
$calendarDoctorDayCounts = appointment_doctor_daily_counts_between(
    $conn,
    $calendarStartValue,
    $calendarMonthEnd,
    array_keys($calendarDoctorIds)
);
$doctorDailyLimit = appointment_doctor_daily_limit();
$calendarConsultationDayCounts = appointment_consultation_daily_counts_between($conn, $calendarStartValue, $calendarMonthEnd);
$consultationDailyLimit = appointment_consultation_daily_limit();
$calendarLabDayCounts = appointment_lab_daily_counts_between($conn, $calendarStartValue, $calendarMonthEnd);
$labDailyLimit = appointment_lab_daily_limit();
$conn->close();

$pageTitle = "Book Appointment | Globalife Medical Laboratory & Polyclinic";
$additionalStyles = '
    body { background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%); min-height: 100vh; }
    .container { max-width: 1320px; }
    .booking-container { max-width: 720px; margin: 40px auto; padding: 40px; background: #fff; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
    .booking-container.book-wide { max-width: 1240px; }
    .booking-header { text-align: center; margin-bottom: 28px; }
    .booking-header h2 { color: #0077b6; font-size: 2rem; margin-bottom: 8px; }
    .stepper { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-bottom: 28px; font-size: 0.8rem; color: #555; }
    .stepper span { padding: 6px 12px; border-radius: 20px; background: #f0f0f0; }
    .stepper span.on { background: #0077b6; color: #fff; font-weight: 600; }
    .choice-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-top: 20px; }
    @media (max-width: 980px) { .choice-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 600px) { .choice-grid { grid-template-columns: 1fr; } }
    .choice-card { border: 2px solid #e0e0e0; border-radius: 16px; padding: 28px; text-align: center; cursor: pointer; transition: all 0.2s; background: #fafafa; }
    .choice-card:hover { border-color: #0077b6; box-shadow: 0 6px 20px rgba(0,119,182,0.15); }
    .choice-card h3 { margin: 0 0 10px; color: #023e8a; font-size: 1.25rem; }
    .choice-card p { margin: 0; color: #666; font-size: 0.95rem; }
    .svc-toolbar { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin-bottom: 16px; }
    .svc-toolbar label { font-weight: 600; font-size: 0.9rem; display: block; margin-bottom: 4px; }
    .svc-toolbar input, .svc-toolbar select { padding: 10px 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 1rem; min-width: 200px; box-sizing: border-box; }
    .svc-summary { background: linear-gradient(135deg, #e3f2fd, #f0f7fa); border: 1px solid #90caf9; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; font-size: 0.95rem; }
    .svc-summary strong { color: #023e8a; }
    .cat-block { margin-bottom: 18px; }
    .cat-heading { font-size: 1.05rem; font-weight: 700; color: #023e8a; margin: 0 0 10px; padding: 8px 0; border-bottom: 2px solid #caf0f8; }
    .service-list { max-height: 520px; overflow-y: auto; border: 1px solid #e8e8e8; border-radius: 12px; padding: 12px; }
    .service-row { display: flex; gap: 10px; align-items: flex-start; padding: 10px; border-radius: 8px; margin-bottom: 6px; }
    .service-row:hover { background: #f5fbff; }
    .service-row.hidden { display: none !important; }
    .service-row label { flex: 1; cursor: pointer; min-width: 0; }
    .btn-add-svc { flex-shrink: 0; padding: 6px 12px; font-size: 0.85rem; border-radius: 8px; border: 1px solid #0077b6; background: #fff; color: #0077b6; font-weight: 600; cursor: pointer; margin-top: 2px; }
    .btn-add-svc:hover { background: #0077b6; color: #fff; }
    .price-cols { display: flex; gap: 12px; flex-shrink: 0; font-size: 0.85rem; color: #555; }
    .price-cols span { min-width: 72px; text-align: right; }
    .price-tag { color: #0077b6; font-weight: 700; white-space: nowrap; }
    .consultation-fee-note { white-space: normal; overflow-wrap: anywhere; }
    .form-group { margin-bottom: 18px; }
    .form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
    .form-group input, .form-group select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 1rem; box-sizing: border-box; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
    .schedule-card { border: 1px solid #d8e8f2; border-radius: 18px; background: #f8fcff; padding: 18px; margin-bottom: 16px; box-shadow: 0 10px 24px rgba(20,79,123,.06); }
    .schedule-layout { display: grid; grid-template-columns: minmax(280px, 1.15fr) minmax(240px, .85fr); gap: 18px; align-items: start; }
    .schedule-controls { display: grid; gap: 14px; }
    .schedule-note { margin: 0; color: #4f6776; line-height: 1.5; font-size: .95rem; }
    .booking-calendar-panel { border: 1px solid #d8e8f2; border-radius: 18px; background: #fff; padding: 18px; margin: 0 0 18px; box-shadow: 0 10px 24px rgba(20,79,123,.06); }
    .booking-calendar-panel .schedule-layout { grid-template-columns: 1fr; }
    .calendar-actions { display: flex; justify-content: flex-end; align-items: center; gap: 14px; margin-top: 14px; }
    .calendar-actions .btn-primary { width: auto; min-width: 230px; margin-top: 0; padding: 13px 28px; }
    .calendar-actions .btn-primary:disabled { opacity: .48; cursor: not-allowed; background: #b8cad8; box-shadow: none; }
    .selected-date-card { display: grid; gap: 6px; padding: 16px; border: 1px solid #cfe3f0; border-radius: 14px; background: #fff; }
    .selected-date-card span { color: #60727d; font-size: .78rem; font-weight: 800; text-transform: uppercase; }
    .selected-date-card strong { color: #073b4c; font-size: 1.15rem; }
    .selected-date-card.is-invalid { border-color:#c9303e; box-shadow:0 0 0 3px rgba(201,48,62,.1); }
    .mini-calendar { border: 1px solid #d4e5ee; border-radius: 16px; background: #fff; overflow: hidden; box-shadow: 0 12px 28px rgba(22,72,103,.08); }
    .mini-calendar-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; background: linear-gradient(180deg,#ffffff 0%,#f8fcff 100%); color: #073b4c; border-bottom: 1px solid #d8e8f2; }
    .mini-calendar-head strong { font-size: 1.22rem; }
    .mini-calendar-head span { display: inline-flex; align-items:center; min-height:34px; padding:0 14px; border-radius:999px; background:#eaf4ff; border:1px solid #cfe3f0; color:#0b4f80; font-size:.82rem; font-weight:900; }
    .calendar-nav { display: flex; align-items: center; gap: 8px; }
    .calendar-nav a { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; border-radius: 999px; border: 1px solid #d8e8f2; color: #0b4f80; text-decoration: none; font-weight: 900; background: #fff; }
    .calendar-nav a:hover { background: #eef7ff; }
    .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0; padding: 0; background: #dcebf3; border-top: 1px solid #d8e8f2; }
    .cal-dow { background: #f7fbfd; color: #587082; font-size: .72rem; font-weight: 900; text-align: center; text-transform: uppercase; padding: 9px 4px; }
    .cal-empty { min-height: 148px; background: #f8fafb; border-top: 1px solid #d8e8f2; }
    .cal-day { min-height: 148px; border: 0; border-top: 1px solid #d8e8f2; background: #fff; color: #10384a; font-family: inherit; font-size: .92rem; font-weight: 800; cursor: pointer; display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 6px; padding: 9px; text-align: left; position: relative; }
    .cal-date-number { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 50%; }
    .calendar-events { display: grid; gap: 5px; }
    .cal-day small { display: block; max-width: 100%; min-height: 20px; padding: 5px 7px; border-radius: 7px; font-weight: 800; line-height: 1.2; white-space: normal; overflow: visible; text-overflow: clip; }
    .doctor-event { background:#e8f8f4; color:#073f38; border:1px solid #a9ddd1; border-left:4px solid #159a83; box-shadow:0 2px 5px rgba(21,154,131,.08); }
    .doctor-event.is-full { background:#fff8ec; color:#8a5b12; border-color:#f0d6a8; border-left-color:#d99b2b; }
    .doctor-event.is-full .availability-label,.doctor-event.is-full .availability-doctor,.doctor-event.is-full .availability-count { color:#8a5b12; }
    .clinic-hours-event { background:#ecf8f6; color:#0d5a50; border:1px solid #bee5dd; border-left:4px solid #2ca58d; pointer-events:none; box-shadow:0 2px 5px rgba(44,165,141,.07); }
    .clinic-hours-event span { display:block; }
    .clinic-hours-event .clinic-hours-label { font-size:.54rem; color:#287d70; font-weight:900; text-transform:uppercase; }
    .clinic-closed-event { background:#f6f8fa; color:#7a8a94; border:1px solid #dfe6eb; border-left:4px solid #bcc8d0; }
    .availability-label { display:block; color:#137e6c; font-size:.52rem; font-weight:900; letter-spacing:.02em; text-transform:uppercase; }
    .availability-doctor { display:block; color:#073f38; font-size:.67rem; font-weight:900; line-height:1.18; }
    .availability-count { display:block; margin-top:3px; color:#356b61; font-size:.58rem; font-weight:900; }
    .capacity-event { background:#eef7ff; color:#0b4f80; border:1px solid #c7e0f1; border-left:4px solid #2b83bd; }
    .capacity-event strong, .capacity-event span { display:block; }
    .capacity-event span { margin-top:2px; font-size:.58rem; color:#547082; }
    .capacity-event.is-full { background:#fff8ec; color:#8a5b12; border-color:#f0d6a8; border-left-color:#d99b2b; }
    .more-event { background:#f0faf7; color:#137e6c; border:1px solid #c6e8df; }
    .cal-day.is-clinic-open { background:#fbfdff; }
    .cal-day.has-doctor { background:#fbfffd; box-shadow:inset 0 3px 0 #6dc7b3; }
    .cal-day.has-doctor .cal-date-number { color:#0d6b60; background:#e8f7f4; }
    .cal-day:hover { background: #f8fcff; outline: 2px solid #8ec7e2; outline-offset: -2px; }
    .cal-day.calendar-readonly { cursor: default; }
    .cal-day.calendar-readonly:hover { background:#fbfdff; outline:none; }
    .cal-day.calendar-readonly.has-doctor:hover { background:#f6fcfa; }
    .cal-day.calendar-readonly.is-past:hover { background: #f7fafc; }
    .cal-day.is-today .cal-date-number { background: #eaf7ff; color: #0b65a0; }
    .cal-day.is-selected { background:#edf8ff; outline:3px solid #0f7cc2; outline-offset:-3px; box-shadow:none; }
    .cal-day.is-selected .cal-date-number { background: #0f7cc2; color: #fff; }
    .cal-day.is-fully-booked { background:#fffaf2; box-shadow:inset 0 3px 0 #d99b2b; }
    .cal-day.is-fully-booked .cal-date-number { background:#fff0d2; color:#8a5b12; }
    .cal-day.is-unavailable { background:#f8fafb; box-shadow:none; }
    .cal-day.is-unavailable .cal-date-number,
    .cal-day.is-closed .cal-date-number { background:#eef2f5; color:#7b8b95; }
    .cal-day:disabled { cursor:not-allowed; color:#8a99a3; background:#f8fafb; box-shadow:none; }
    .cal-day:disabled small { opacity: .82; }
    .cal-day.is-closed { background:#f8fafb; }
    @media (max-width: 760px) { .booking-container { margin: 22px auto; padding: 24px 14px; } .booking-calendar-panel { padding: 12px; } .schedule-layout, .booking-calendar-panel .schedule-layout { grid-template-columns: 1fr; } .mini-calendar { overflow-x: auto; } .cal-grid { min-width: 760px; } .cal-day, .cal-empty { min-height: 132px; } .calendar-actions { align-items:stretch; flex-direction:column; position: sticky; bottom: 0; z-index: 5; padding: 10px 0 0; background: linear-gradient(180deg, rgba(255,255,255,.65), #fff 45%); } .calendar-actions .btn-primary{width:100%; min-width:0;} }
    @media (max-width: 520px) { .cal-grid { min-width: 720px; } .cal-day, .cal-empty { min-height: 124px; } .cal-day { padding: 6px; } .cal-day small { display: block; padding: 4px 5px; } .availability-label { font-size: .5rem; } .availability-doctor { font-size: .62rem; } }
    .btn-primary { width: 100%; background: linear-gradient(135deg, #0077b6, #023e8a); color: #fff; padding: 14px; border: none; border-radius: 10px; font-size: 1.05rem; font-weight: 600; cursor: pointer; margin-top: 8px; }
    .btn-secondary { display: inline-block; padding: 10px 18px; border-radius: 8px; background: #e3f2fd; color: #023e8a; text-decoration: none; font-weight: 600; margin-right: 10px; border: none; cursor: pointer; font-size: 1rem; }
    .error-message { background: #fee; color: #c1121f; padding: 14px; border-radius: 10px; margin-bottom: 18px; border-left: 4px solid #c1121f; }
    .success-banner { background: #d4edda; color: #155724; padding: 20px; border-radius: 12px; margin-bottom: 22px; border-left: 4px solid #28a745; }
    .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 14px; border-radius: 8px; margin-bottom: 20px; color: #1565c0; font-size: 0.95rem; }
    .clinic-reminder-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin: 16px 0 22px; }
    .clinic-reminder-heading { grid-column:1 / -1; margin-bottom:2px; }
    .clinic-reminder-heading h3 { margin:0 0 4px; color:#073b4c; font-size:1.15rem; }
    .clinic-reminder-heading p { margin:0; color:#60727d; font-size:.88rem; line-height:1.45; }
    .clinic-reminder { background: #fff; border: 1px solid #dce9f4; border-radius: 10px; padding: 15px; box-shadow: 0 8px 18px rgba(20,79,123,.07); }
    .clinic-reminder-number { display:inline-grid; place-items:center; width:32px; height:32px; margin-bottom:10px; border-radius:50%; background:#e5f5fb; color:#006b9f; font-size:.9rem; font-weight:900; }
    .clinic-reminder strong { display: block; color: #0b4f80; margin-bottom: 5px; }
    .clinic-reminder span { color: #4a6072; font-size: .9rem; line-height: 1.45; }
    .review-check { display: flex; align-items: flex-start; gap: 10px; background: #f8fbff; border: 1px solid #dce9f4; border-radius: 10px; padding: 13px 14px; margin-bottom: 14px; color: #26495f; font-weight: 600; }
    .review-check input { margin-top: 3px; width: 18px; height: 18px; accent-color: #0077b6; }
    @media (max-width: 820px) { .clinic-reminder-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 520px) { .clinic-reminder-grid { grid-template-columns: 1fr; } }
    .detail-block { background: #f8fafc; border-radius: 12px; padding: 16px; margin-bottom: 14px; }
    .detail-block h4 { margin: 0 0 8px; color: #0077b6; font-size: 1rem; }
    .detail-block p { margin: 0; color: #444; line-height: 1.5; }
    .channel-opt { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px; }
    .channel-opt label { font-weight: 500; cursor: pointer; }
    .back-link { display: inline-flex; align-items: center; gap: 8px; color: #0077b6; text-decoration: none; font-weight: 500; margin-bottom: 18px; }
    .summary-table { width: 100%; border-collapse: collapse; margin: 12px 0; }
    .summary-table td { padding: 8px 0; border-bottom: 1px solid #eee; }
    .summary-table td:last-child { text-align: right; font-weight: 600; color: #0077b6; }
    .step-context-box { border-left-width:5px; background:linear-gradient(135deg,#eff9ff 0%,#f8fdff 100%); color:#115b7d; box-shadow:0 10px 24px rgba(0,119,182,.08); }
    .doctor-directory { margin-top:14px; padding:22px; border:1px solid #d7e9f2; border-radius:20px; background:linear-gradient(135deg,#ffffff 0%,#f7fcff 100%); box-shadow:0 16px 34px rgba(20,79,123,.08); }
    .doctor-directory-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px solid #e2eff6; }
    .doctor-directory-head span { display:block; color:#0884bd; font-size:.72rem; font-weight:950; letter-spacing:.08em; text-transform:uppercase; }
    .doctor-directory-head strong { display:block; margin-top:3px; color:#073b4c; font-size:1.18rem; line-height:1.2; }
    .doctor-directory-head small { color:#657a86; font-size:.88rem; line-height:1.35; text-align:right; }
    .selection-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-top:14px; }
    .selection-radio { position:absolute; opacity:0; pointer-events:none; }
    .selection-card { display:block; min-height:118px; padding:18px; border:2px solid #cfe4ef; border-radius:16px; background:#fff; cursor:pointer; box-shadow:0 8px 20px rgba(20,79,123,.06); transition:border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
    .selection-card:hover { transform:translateY(-2px); border-color:#8ec7e2; box-shadow:0 14px 28px rgba(20,79,123,.1); }
    .selection-radio:checked + .selection-card { border-color:#0f7cc2; background:#edf8ff; box-shadow:0 0 0 3px rgba(15,124,194,.12); }
    .selection-card strong { display:block; color:#073b4c; font-size:1.12rem; }
    .selection-card span { display:block; margin-top:8px; color:#526c7b; font-size:.9rem; line-height:1.4; }
    .doc-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin-top:0; }
    .doc-card { display:grid; grid-template-columns:48px minmax(0,1fr); gap:13px; align-items:start; min-height:116px; border-radius:16px; padding:16px; border:1px solid #cfe4ef; background:linear-gradient(180deg,#ffffff 0%,#fbfeff 100%); box-shadow:0 8px 20px rgba(20,79,123,.06); transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
    .doc-card:hover { transform:translateY(-2px); border-color:#9fd5eb; box-shadow:0 14px 28px rgba(20,79,123,.1); }
    .selection-radio:checked + .doc-card { border-color:#0f7cc2; background:#edf8ff; box-shadow:0 0 0 3px rgba(15,124,194,.12); }
    .doc-avatar { flex:0 0 48px; width:48px; height:48px; display:grid; place-items:center; border-radius:15px; background:linear-gradient(135deg,#def7ff,#eefaff); color:#0878b5; box-shadow:inset 0 0 0 1px rgba(8,120,181,.08); }
    .doc-avatar svg { width:24px; height:24px; stroke:currentColor; stroke-width:2; fill:none; stroke-linecap:round; stroke-linejoin:round; }
    .doc-copy { min-width:0; }
    .doc-badge { display:inline-flex; align-items:center; min-height:22px; padding:0 9px; border-radius:999px; background:#edf8fd; color:#0878b5; font-size:.64rem; font-weight:950; letter-spacing:.07em; text-transform:uppercase; }
    .doc-card h4 { margin:9px 0 5px; color:#073b4c; font-size:1.02rem; line-height:1.25; overflow-wrap:anywhere; }
    .doc-card-name { display:block; margin:9px 0 5px; color:#073b4c; font-size:1.02rem; font-weight:800; line-height:1.25; overflow-wrap:anywhere; }
    .doc-specialty { margin:0; color:#607784; font-size:.86rem; line-height:1.35; }
    .doc-list-note { margin:16px 0 0; padding:12px 14px; border-radius:13px; background:#f2f9fd; color:#4f6674; font-size:.9rem; line-height:1.45; }
    .doc-list-actions { margin-top:18px; display:flex; justify-content:flex-end; gap:10px; align-items:center; }
    .doc-list-actions .btn-primary { width:auto; min-width:260px; margin:0; padding:13px 28px; border-radius:12px; box-shadow:0 12px 24px rgba(0,119,182,.15); }
    @media (max-width: 980px) { .doc-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width: 640px) { .doctor-directory { padding:15px; } .doctor-directory-head { align-items:flex-start; flex-direction:column; } .doctor-directory-head small { text-align:left; } .selection-grid, .doc-grid { grid-template-columns:1fr; } .doc-list-actions .btn-primary { width:100%; min-width:0; } }
    .capacity-modal { position:fixed; inset:0; z-index:5400; display:none; place-items:center; padding:20px; background:rgba(5,35,52,.58); backdrop-filter:blur(5px); }
    .capacity-modal.open { display:grid; }
    .capacity-dialog { width:min(560px,100%); max-height:min(82vh,720px); overflow:auto; border:1px solid #c9e2ef; border-radius:22px; background:#fff; box-shadow:0 28px 80px rgba(4,35,52,.3); }
    .capacity-dialog-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; padding:22px 24px; background:linear-gradient(135deg,#f7fdff,#e8f7ff); border-bottom:1px solid #d8eaf3; }
    .capacity-dialog-head span { display:block; color:#0878b5; font-size:.74rem; font-weight:950; letter-spacing:.06em; text-transform:uppercase; }
    .capacity-dialog-head h3 { margin:5px 0 0; color:#073b4c; font-size:1.45rem; }
    .capacity-modal-close { flex:0 0 40px; width:40px; height:40px; border:0; border-radius:50%; background:#fff; color:#0878b5; font-size:1.45rem; cursor:pointer; box-shadow:0 5px 14px rgba(20,79,123,.12); }
    .capacity-dialog-body { padding:22px 24px 24px; }
    .capacity-summary { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-bottom:16px; }
    .capacity-stat { padding:13px; border:1px solid #dbeaf2; border-radius:14px; background:#f9fcfe; }
    .capacity-stat span { display:block; color:#687e8a; font-size:.68rem; font-weight:900; text-transform:uppercase; }
    .capacity-stat strong { display:block; margin-top:4px; color:#073b4c; font-size:1.25rem; }
    .capacity-doctors { display:grid; gap:10px; }
    .capacity-doctor-row { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:14px 16px; border:1px solid #dbeaf2; border-radius:14px; background:#fff; }
    .capacity-doctor-row.is-full { border-color:#f0d6a8; background:#fffaf2; }
    .capacity-doctor-copy strong { display:block; color:#073b4c; }
    .capacity-doctor-copy span { display:block; margin-top:3px; color:#687e8a; font-size:.8rem; }
    .capacity-chip { flex:0 0 auto; padding:7px 10px; border-radius:999px; background:#e8f8ef; color:#17643a; font-size:.75rem; font-weight:950; }
    .capacity-chip.full { background:#fff0d2; color:#8a5b12; }
    .capacity-message { margin:14px 0 0; padding:12px 14px; border-radius:12px; background:#eef8fd; color:#315c70; line-height:1.5; }
    .capacity-message.full { background:#fff8ec; color:#7b5418; }
    @media (max-width:520px) { .capacity-dialog-head,.capacity-dialog-body{padding:18px}.capacity-summary{grid-template-columns:1fr}.capacity-doctor-row{align-items:flex-start;flex-direction:column}.capacity-chip{align-self:flex-start} }
';

include 'includes/header.php';

$stepLabels = $bk['type'] === 'consultation'
    ? [
        1 => 'Appointment type',
        2 => 'Choose specialization',
        3 => 'Choose doctor',
        4 => 'Select schedule',
        5 => 'Visit clinic',
    ]
    : ($bk['type'] === 'ultrasound'
    ? [
        1 => 'Appointment type',
        4 => 'Schedule',
        5 => 'Confirm',
    ]
    : [
        1 => 'Appointment type',
        2 => 'Choose service',
        3 => 'Review details',
        4 => 'Schedule',
        5 => 'Confirm',
    ]);
$stepperItems = [];
foreach ($stepLabels as $actualStep => $label) {
    $stepperItems[] = [
        'actual' => (int) $actualStep,
        'label' => (string) $label,
    ];
}
?>

<div class="container">
    <div class="booking-container<?php echo in_array($step, [1, 2, 4, 5], true) ? ' book-wide' : ''; ?>">
        <a href="patients.php" class="back-link">Back to Dashboard</a>

        <?php if ($bookedId > 0): ?>
            <div class="success-banner">
                <strong>Appointment request successfully submitted.</strong><br>
                Reference #<?php echo $bookedId; ?>.
                <?php if ($appointmentEmailWarning === ''): ?>
                    We sent the booking details to your registered email and mobile number.
                <?php else: ?>
                    Your booking is saved and visible in My Appointments.
                <?php endif; ?>
                The clinic will review and confirm your request.
            </div>
            <?php if ($appointmentEmailWarning !== ''): ?>
                <div class="error-message"><?php echo htmlspecialchars($appointmentEmailWarning); ?></div>
            <?php endif; ?>
            <a href="view_appointments.php" class="btn-primary" style="display:inline-block;width:auto;padding:12px 24px;text-decoration:none;text-align:center;">View my appointments</a>
            <a href="book_appointment.php?start=1" class="btn-secondary" style="margin-top:12px;">Book another</a>
        <?php else: ?>

        <div class="booking-header">
            <h2>Book an appointment</h2>
            <p><?php echo $step === 1 && empty($bk['calendar_ready'])
                ? 'Review doctor availability, then continue to booking.'
                : 'Follow the steps below. Payment is made at the clinic after staff confirms your request.'; ?></p>
        </div>

        <?php if ($step === 1 && empty($bk['calendar_ready'])): ?>
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post" action="book_appointment.php" id="calendarFirstForm">
                <input type="hidden" name="booking_action" value="begin_booking">
                <section class="booking-calendar-panel" aria-label="Appointment availability calendar">
                <div class="schedule-layout">
                    <div class="mini-calendar">
                        <div class="mini-calendar-head">
                            <div class="calendar-nav">
                                <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarPrevMonth); ?>" aria-label="Previous month">&lsaquo;</a>
                                <strong><?php echo htmlspecialchars($calendarMonthLabel); ?></strong>
                                <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarNextMonth); ?>" aria-label="Next month">&rsaquo;</a>
                            </div>
                            <span>Doctor schedule</span>
                        </div>
                        <div class="cal-grid" id="startAppointmentCalendar">
                            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                                <div class="cal-dow"><?php echo $dow; ?></div>
            <?php endforeach; ?>
                            <?php for ($blank = 0; $blank < $calendarFirstWeekday; $blank++): ?>
                                <div class="cal-empty"></div>
                            <?php endfor; ?>
                            <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++): ?>
                                <?php
                                $dateValue = $calendarMonthStart->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                                $dateDayOfWeek = (int) date('N', strtotime($dateValue));
                                $clinicOpen = $dateDayOfWeek >= 1 && $dateDayOfWeek <= 6;
                                $doctorSlots = $calendarDoctorSlotsByDow[$dateDayOfWeek] ?? [];
                                $dayCapacityRows = [];
                                $dayBookedTotal = 0;
                                $dayFullDoctors = 0;
                                foreach ($doctorSlots as $slot) {
                                    $slotDoctorId = (int) $slot['id'];
                                    $slotBooked = (int) ($calendarDoctorDayCounts[$slotDoctorId][$dateValue] ?? 0);
                                    $slotFull = $slotBooked >= $doctorDailyLimit;
                                    $dayBookedTotal += $slotBooked;
                                    $dayFullDoctors += $slotFull ? 1 : 0;
                                    $dayCapacityRows[] = [
                                        'doctor' => (string) $slot['doctor'],
                                        'specialty' => (string) ($slot['specialty'] ?: 'General consultation'),
                                        'booked' => $slotBooked,
                                        'remaining' => max(0, $doctorDailyLimit - $slotBooked),
                                        'limit' => $doctorDailyLimit,
                                        'full' => $slotFull,
                                    ];
                                }
                                $dayAllDoctorsFull = !empty($dayCapacityRows) && $dayFullDoctors === count($dayCapacityRows);
                                $classes = ['cal-day'];
                                if ($dateValue === $calendarToday) {
                                    $classes[] = 'is-today';
                                }
                                if ($dateValue === $calendarSelected) {
                                    $classes[] = 'is-selected';
                                }
                                if ($clinicOpen) {
                                    $classes[] = 'is-clinic-open';
                                }
                                if (!empty($doctorSlots)) {
                                    $classes[] = 'has-doctor';
                                }
                                if (!$clinicOpen) {
                                    $classes[] = 'is-closed';
                                }
                                if ($dayAllDoctorsFull) {
                                    $classes[] = 'is-fully-booked';
                                }
                                $disabled = $dateValue < $calendarToday;
                                if ($disabled) {
                                    $classes[] = 'is-past';
                                }
                                ?>
                                <button
                                    type="button"
                                    class="<?php echo implode(' ', $classes); ?>"
                                    data-capacity-date="<?php echo htmlspecialchars($dateValue); ?>"
                                    data-capacity-doctors="<?php echo htmlspecialchars(json_encode($dayCapacityRows, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-capacity-booked="<?php echo $dayBookedTotal; ?>"
                                    data-capacity-limit="<?php echo count($dayCapacityRows) * $doctorDailyLimit; ?>"
                                    data-capacity-all-full="<?php echo $dayAllDoctorsFull ? '1' : '0'; ?>"
                                    <?php echo $disabled ? 'disabled' : ''; ?>
                                >
                                    <span class="cal-date-number"><?php echo $day; ?></span>
                                    <div class="calendar-events">
                                        <?php if (!$disabled): ?>
                                            <?php if (!$clinicOpen): ?>
                                                <small class="clinic-closed-event">Clinic closed</small>
                                            <?php endif; ?>
                                            <?php if ($clinicOpen): ?>
                                            <?php foreach (array_slice($doctorSlots, 0, 2) as $slot): ?>
                                                <?php
                                                $slotBooked = (int) ($calendarDoctorDayCounts[(int) $slot['id']][$dateValue] ?? 0);
                                                $slotFull = $slotBooked >= $doctorDailyLimit;
                                                ?>
                                                <small class="doctor-event<?php echo $slotFull ? ' is-full' : ''; ?>">
                                                    <span class="availability-label"><?php echo htmlspecialchars($slot['specialty'] !== '' ? $slot['specialty'] : 'Available'); ?></span>
                                                    <span class="availability-doctor"><?php echo htmlspecialchars($slot['doctor']); ?></span>
                                                    <span class="availability-count"><?php echo $slotFull ? 'Fully booked' : $slotBooked . '/' . $doctorDailyLimit . ' booked'; ?></span>
                                                </small>
                                            <?php endforeach; ?>
                                            <?php if (count($doctorSlots) > 2): ?>
                                                <small class="more-event">+<?php echo count($doctorSlots) - 2; ?> more doctor slots</small>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
                <div class="calendar-actions">
                    <button type="submit" class="btn-primary" id="continueFromCalendar">Continue</button>
                </div>
                </section>
            </form>
        <?php endif; ?>

        <?php if ($step !== 1 || !empty($bk['calendar_ready'])): ?>
        <div class="clinic-reminder-grid" aria-labelledby="bookingProcedureTitle">
            <div class="clinic-reminder-heading">
                <h3 id="bookingProcedureTitle">How to book your appointment</h3>
                <p>Complete these steps to submit and attend your clinic appointment.</p>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">1</span>
                <strong>Choose an appointment</strong>
                <span>Select a doctor consultation, ultrasound, laboratory package, or individual laboratory tests.</span>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">2</span>
                <strong>Select your schedule</strong>
                <span>Choose an open clinic date before continuing.</span>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">3</span>
                <strong>Submit your request</strong>
                <span>Review the appointment details, then send the request to the clinic.</span>
            </div>
            <div class="clinic-reminder">
                <span class="clinic-reminder-number" aria-hidden="true">4</span>
                <strong>Visit the clinic</strong>
                <span>Bring a valid ID, arrive 10-15 minutes early, and pay at the front desk.</span>
            </div>
        </div>
        <div class="stepper">
            <?php foreach ($stepperItems as $displayIndex => $stepperItem): ?>
                <span class="<?php echo $stepperItem['actual'] === $step ? 'on' : ''; ?>"><?php echo $displayIndex + 1; ?>. <?php echo htmlspecialchars($stepperItem['label']); ?></span>
            <?php endforeach; ?>
        </div>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <div class="info-box step-context-box">
                <strong>Step 1.</strong> Choose between a <strong>Doctor Consultation</strong>, <strong>Ultra sound</strong>, a clinic <strong>Package Deal</strong>, or <strong>Individual Laboratory Tests</strong>.
            </div>
            <form method="post" action="book_appointment.php">
                <input type="hidden" name="booking_action" value="select_type">
                <input type="hidden" name="preferred_date" id="preferredDateInput" value="<?php echo htmlspecialchars($bk['appointment_date']); ?>">
                <div class="choice-grid">
                    <button type="submit" name="booking_type" value="consultation" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Doctor consultation</h3>
                        <p>Review the clinic doctor list, then choose your visit date.</p>
                    </button>
                    <button type="submit" name="booking_type" value="package" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Package deals</h3>
                        <p>Best for clinic packages such as pre-employment, sanitary permit, and CVSU.</p>
                    </button>
                    <button type="submit" name="booking_type" value="individual" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Individual laboratory tests</h3>
                        <p>Choose one or more lab tests, then select your preferred appointment schedule.</p>
                    </button>
                    <button type="submit" name="booking_type" value="ultrasound" class="choice-card" style="font-family:inherit;width:100%;">
                        <h3>Ultra sound</h3>
                        <p>Submit an ultrasound appointment request, then choose your clinic schedule.</p>
                    </button>
                </div>
            </form>
        <?php elseif ($step === 2): ?>
            <div class="info-box">
                <strong>Step 2.</strong>
                <?php
                if ($bk['type'] === 'consultation') {
                    echo 'Choose the doctor specialization for your consultation.';
                } elseif ($bk['type'] === 'package') {
                    echo 'Choose one package. The final payment is made at the clinic.';
                } else {
                    echo 'Choose one or more individual tests. Use search or category filter to find services faster.';
                }
                ?>
            </div>
            <form method="post" action="book_appointment.php" id="formChooseServices">
                <input type="hidden" name="booking_action" value="choose_services">
                <?php if ($bk['type'] === 'consultation'): ?>
                    <?php if (empty($consultationSpecialties)): ?>
                        <p>No doctor specializations are available yet. Please contact the clinic.</p>
                    <?php else: ?>
                        <div class="doctor-directory">
                            <div class="doctor-directory-head">
                                <div>
                                    <span>Step 2</span>
                                    <strong>Choose specialization</strong>
                                </div>
                                <small>Select the specialty first, then choose your preferred doctor.</small>
                            </div>
                            <div class="selection-grid">
                                <?php foreach ($consultationSpecialties as $idx => $specialty): ?>
                                    <?php
                                    $specialtyId = 'specialty' . $idx;
                                    $doctorCount = count(array_filter(
                                        $bookingDoctors,
                                        static fn (array $doctor): bool => consultationDoctorMatchesSpecialty($doctor, (string) $specialty)
                                    ));
                                    ?>
                                    <div>
                                        <input
                                            class="selection-radio"
                                            type="radio"
                                            name="consultation_specialty"
                                            id="<?php echo htmlspecialchars($specialtyId); ?>"
                                            value="<?php echo htmlspecialchars((string) $specialty, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $selectedSpecialty === $specialty ? 'checked' : ''; ?>
                                            required
                                        >
                                        <label class="selection-card" for="<?php echo htmlspecialchars($specialtyId); ?>">
                                            <strong><?php echo htmlspecialchars((string) $specialty); ?></strong>
                                            <span><?php echo $doctorCount; ?> doctor<?php echo $doctorCount === 1 ? '' : 's'; ?> available</span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="doc-list-actions">
                                <button type="submit" class="btn-primary">Next: choose doctor</button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php elseif ($bk['type'] === 'package'): ?>
                    <?php if (empty($groupedPackages)): ?>
                        <p>No packages are available yet. Please contact the clinic staff.</p>
                    <?php else: ?>
                        <div class="svc-toolbar">
                            <div>
                                <label for="pkgSearch">Search</label>
                                <input type="search" id="pkgSearch" placeholder="Search package..." autocomplete="off">
                            </div>
                            <div>
                                <label for="pkgCatFilter">Category</label>
                                <select id="pkgCatFilter">
                                    <option value="">All categories</option>
                                    <?php foreach (array_keys($groupedPackages) as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="svc-summary" id="pkgSummary" aria-live="polite">
                            <strong>Summary:</strong> <span id="pkgSummaryText">No package selected</span>
                        </div>
                        <div class="service-list" id="pkgListWrap">
                            <?php foreach ($groupedPackages as $catName => $svcs): ?>
                                <div class="cat-block" data-category="<?php echo htmlspecialchars($catName); ?>">
                                    <h3 class="cat-heading"><?php echo htmlspecialchars($catName); ?></h3>
                                    <?php foreach ($svcs as $svc):
                                        $sid = (int) $svc['id'];
                                        $needle = strtolower($svc['name'] . ' ' . $catName . ' ' . ($svc['included_tests'] ?? ''));
                                        ?>
                                        <div class="service-row pkg-row" data-search="<?php echo htmlspecialchars($needle, ENT_QUOTES, 'UTF-8'); ?>" data-cat="<?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="radio" name="package_id" value="<?php echo $sid; ?>" id="pkg<?php echo $sid; ?>" class="pkg-radio"
                                                data-name="<?php echo htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-opd="<?php echo (float) $svc['opd_price']; ?>"
                                                <?php echo (count($bk['service_ids']) === 1 && (int) $bk['service_ids'][0] === $sid) ? 'checked' : ''; ?>>
                                            <label for="pkg<?php echo $sid; ?>">
                                                <strong><?php echo htmlspecialchars($svc['name']); ?></strong>
                                                <?php if (!empty($svc['included_tests'])): ?>
                                                    <br><small style="color:#666;"><?php echo htmlspecialchars($svc['included_tests']); ?></small>
                                                <?php endif; ?>
                                            </label>
                                            <span class="price-tag">PHP <?php echo number_format((float) $svc['opd_price'], 0); ?></span>
                                            <button type="button" class="btn-add-svc pkg-add" data-target="pkg<?php echo $sid; ?>">Add</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (empty($groupedIndividual)): ?>
                        <p>No individual tests are available yet. Please contact the clinic staff.</p>
                    <?php else: ?>
                        <div class="svc-toolbar">
                            <div>
                                <label for="indSearch">Search</label>
                                <input type="search" id="indSearch" placeholder="Search test or category..." autocomplete="off">
                            </div>
                            <div>
                                <label for="indCatFilter">Category</label>
                                <select id="indCatFilter">
                                    <option value="">All categories</option>
                                    <?php foreach (array_keys($groupedIndividual) as $c): ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="svc-summary" id="indSummary" aria-live="polite">
                            <strong>Summary:</strong> <span id="indCount">0</span> test(s) | Subtotal <span id="indSubOpd">PHP 0</span>
                        </div>
                        <div class="service-list" id="indListWrap">
                            <?php foreach ($groupedIndividual as $catName => $svcs): ?>
                                <div class="cat-block" data-category="<?php echo htmlspecialchars($catName); ?>">
                                    <h3 class="cat-heading"><?php echo htmlspecialchars($catName); ?></h3>
                                    <?php foreach ($svcs as $svc):
                                        $sid = (int) $svc['id'];
                                        $home = $svc['home_service_price'];
                                        $homeNum = ($home !== null && $home !== '') ? (float) $home : '';
                                        $needle = strtolower($svc['name'] . ' ' . $catName);
                                        ?>
                                        <div class="service-row ind-row" data-search="<?php echo htmlspecialchars($needle, ENT_QUOTES, 'UTF-8'); ?>" data-cat="<?php echo htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="checkbox" name="service_ids[]" value="<?php echo $sid; ?>" id="t<?php echo $sid; ?>" class="ind-cb"
                                                data-opd="<?php echo (float) $svc['opd_price']; ?>"
                                                data-home="<?php echo $homeNum !== '' ? htmlspecialchars((string) $homeNum, ENT_QUOTES, 'UTF-8') : ''; ?>"
                                                <?php echo in_array($sid, array_map('intval', $bk['service_ids']), true) ? 'checked' : ''; ?>>
                                            <label for="t<?php echo $sid; ?>">
                                                <strong><?php echo htmlspecialchars($svc['name']); ?></strong>
                                            </label>
                                            <div class="price-cols">
                                                <span>PHP <?php echo number_format((float) $svc['opd_price'], 0); ?></span>
                                            </div>
                                            <button type="button" class="btn-add-svc ind-add" data-target="t<?php echo $sid; ?>">Add</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (in_array($bk['type'], ['package', 'individual'], true)): ?>
                    <button type="submit" class="btn-primary">Next: view details</button>
                <?php endif; ?>
            </form>
            <script>
            (function() {
                function norm(s) { return (s || '').toLowerCase().trim(); }
                function filterRows(searchEl, catEl, rowSel, catBlockSel) {
                    var q = norm(searchEl && searchEl.value);
                    var cat = catEl && catEl.value;
                    document.querySelectorAll(rowSel).forEach(function(row) {
                        var ok = true;
                        if (cat && row.getAttribute('data-cat') !== cat) ok = false;
                        if (ok && q && row.getAttribute('data-search').indexOf(q) === -1) ok = false;
                        row.classList.toggle('hidden', !ok);
                    });
                    document.querySelectorAll(catBlockSel).forEach(function(block) {
                        var vis = !!block.querySelector(rowSel + ':not(.hidden)');
                        block.style.display = vis ? '' : 'none';
                    });
                }
                var pkgSearch = document.getElementById('pkgSearch');
                var pkgCat = document.getElementById('pkgCatFilter');
                if (pkgSearch && pkgCat) {
                    function updPkgSummary() {
                        var r = document.querySelector('.pkg-radio:checked');
                        var el = document.getElementById('pkgSummaryText');
                        if (!el) return;
                        if (!r) { el.textContent = 'No package selected'; return; }
                        var opd = r.getAttribute('data-opd');
                        el.textContent = r.getAttribute('data-name') + ' - PHP ' + Number(opd).toLocaleString();
                    }
                    pkgSearch.addEventListener('input', function() { filterRows(pkgSearch, pkgCat, '.pkg-row', '.cat-block'); });
                    pkgCat.addEventListener('change', function() { filterRows(pkgSearch, pkgCat, '.pkg-row', '.cat-block'); });
                    document.querySelectorAll('.pkg-add').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var id = btn.getAttribute('data-target');
                            var inp = document.getElementById(id);
                            if (inp) { inp.checked = true; updPkgSummary(); }
                        });
                    });
                    document.querySelectorAll('.pkg-radio').forEach(function(r) { r.addEventListener('change', updPkgSummary); });
                    updPkgSummary();
                }
                var indSearch = document.getElementById('indSearch');
                var indCat = document.getElementById('indCatFilter');
                if (indSearch && indCat) {
                    function indTotals() {
                        var opd = 0, home = 0, n = 0;
                        document.querySelectorAll('.ind-cb:checked').forEach(function(cb) {
                            n++;
                            opd += parseFloat(cb.getAttribute('data-opd')) || 0;
                            var h = cb.getAttribute('data-home');
                            if (h !== '') home += parseFloat(h) || 0;
                        });
                        var c = document.getElementById('indCount');
                        var o = document.getElementById('indSubOpd');
                        var hEl = document.getElementById('indSubHome');
                        if (c) c.textContent = n;
                        if (o) o.textContent = 'PHP ' + opd.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                        if (hEl) hEl.textContent = 'PHP ' + home.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                    }
                    indSearch.addEventListener('input', function() { filterRows(indSearch, indCat, '.ind-row', '.cat-block'); });
                    indCat.addEventListener('change', function() { filterRows(indSearch, indCat, '.ind-row', '.cat-block'); });
                    document.querySelectorAll('.ind-add').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var id = btn.getAttribute('data-target');
                            var inp = document.getElementById(id);
                            if (inp) { inp.checked = !inp.checked; indTotals(); }
                        });
                    });
                    document.querySelectorAll('.ind-cb').forEach(function(cb) { cb.addEventListener('change', indTotals); });
                    indTotals();
                }
            })();
            </script>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>
            <a href="book_appointment.php?reset=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Start over</a>

        <?php elseif ($step === 3): ?>
            <div class="info-box">
                <strong>Step 3.</strong>
                <?php echo $bk['type'] === 'consultation'
                    ? 'Choose a doctor for ' . htmlspecialchars($selectedSpecialty) . '.'
                    : 'Review the selected services and prices. Payment is made at the clinic.'; ?>
            </div>
            <?php if ($bk['type'] === 'consultation'): ?>
                <form method="post" action="book_appointment.php">
                    <input type="hidden" name="booking_action" value="choose_doctor">
                    <?php if (empty($consultationDoctorsForSpecialty)): ?>
                        <p>No doctors are available for this specialization yet. Please choose another specialization.</p>
                    <?php else: ?>
                        <div class="doctor-directory">
                            <div class="doctor-directory-head">
                                <div>
                                    <span>Step 3</span>
                                    <strong>Choose doctor</strong>
                                </div>
                                <small><?php echo htmlspecialchars($selectedSpecialty); ?> schedule will be used in the next step.</small>
                            </div>
                            <div class="doc-grid">
                                <?php foreach ($consultationDoctorsForSpecialty as $doctor): ?>
                                    <?php $doctorInputId = 'doctor' . (int) $doctor['id']; ?>
                                    <div>
                                        <input
                                            class="selection-radio"
                                            type="radio"
                                            name="doctor_id"
                                            id="<?php echo htmlspecialchars($doctorInputId); ?>"
                                            value="<?php echo (int) $doctor['id']; ?>"
                                            <?php echo (int) ($bk['doctor_id'] ?? 0) === (int) $doctor['id'] ? 'checked' : ''; ?>
                                            required
                                        >
                                        <label class="doc-card" for="<?php echo htmlspecialchars($doctorInputId); ?>">
                                            <span class="doc-avatar" aria-hidden="true">
                                                <svg viewBox="0 0 24 24">
                                                    <path d="M12 5v14"></path>
                                                    <path d="M5 12h14"></path>
                                                    <path d="M7 4h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"></path>
                                                </svg>
                                            </span>
                                            <span class="doc-copy">
                                                <span class="doc-badge"><?php echo htmlspecialchars((string) ($doctor['specialty'] ?? 'Clinic Doctor')); ?></span>
                                                <span class="doc-card-name"><?php echo htmlspecialchars((string) $doctor['full_name']); ?></span>
                                                <span class="doc-specialty"><?php echo nl2br(htmlspecialchars((string) ($doctor['clinic_hours'] ?? ''))); ?></span>
                                            </span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="doc-list-actions">
                                <button type="submit" class="btn-primary">Next: select schedule</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <?php foreach ($selectedServices as $svc): ?>
                    <div class="detail-block">
                        <h4><?php echo htmlspecialchars($svc['name']); ?></h4>
                        <p><?php echo nl2br(htmlspecialchars($svc['description'] ?? '')); ?></p>
                        <?php if (!empty($svc['included_tests'])): ?>
                            <p><strong>Included tests:</strong> <?php echo htmlspecialchars($svc['included_tests']); ?></p>
                        <?php endif; ?>
                        <p class="price-tag">Price: PHP <?php echo number_format((float) $svc['opd_price'], 2); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($bk['type'] !== 'consultation'): ?>
            <form method="post" action="book_appointment.php">
                <input type="hidden" name="booking_action" value="set_channel">
                <input type="hidden" name="price_channel" value="opd">
                <?php if ($bk['type'] !== 'ultrasound'): ?>
                    <p style="font-size:1.1rem;margin:16px 0;"><strong>Total:</strong> <span class="price-tag">PHP <?php echo number_format($displayTotal, 2); ?></span></p>
                <?php endif; ?>
                <button type="submit" class="btn-primary">Next: choose date</button>
            </form>
            <?php endif; ?>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>

        <?php elseif ($step === 4): ?>
            <div class="info-box">
                <strong><?php echo $bk['type'] === 'ultrasound' ? 'Step 2 - Schedule.' : 'Step 4 - Appointment schedule.'; ?></strong>
                <?php if ($bk['type'] === 'consultation' && $selectedDoctor): ?>
                    Choose an available schedule for <?php echo htmlspecialchars((string) $selectedDoctor['full_name']); ?>.
                <?php elseif ($bk['type'] === 'consultation'): ?>
                    Choose an open clinic date for your consultation.
                <?php elseif ($bk['type'] === 'ultrasound'): ?>
                    Choose an open clinic date for your ultrasound appointment.
                <?php else: ?>
                    Choose an open clinic date for your laboratory visit.
                <?php endif; ?>
            </div>
            <form method="post" action="book_appointment.php" id="scheduleForm">
                <input type="hidden" name="booking_action" id="booking_action_field" value="set_schedule">
                <input type="hidden" name="appointment_date" id="appointment_date" value="<?php echo htmlspecialchars($bk['appointment_date']); ?>">
                <div class="schedule-card">
                    <div class="schedule-layout">
                        <div class="mini-calendar" aria-label="Appointment calendar">
                            <div class="mini-calendar-head">
                                <div class="calendar-nav">
                                    <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarPrevMonth); ?>" aria-label="Previous month">&lsaquo;</a>
                                    <strong><?php echo htmlspecialchars($calendarMonthLabel); ?></strong>
                                    <a href="book_appointment.php?calendar_month=<?php echo urlencode($calendarNextMonth); ?>" aria-label="Next month">&rsaquo;</a>
                                </div>
                                <span><?php echo $bk['type'] === 'consultation' ? 'Consultation capacity' : 'Pick a visit date'; ?></span>
                            </div>
                            <div class="cal-grid" id="appointmentCalendar">
                                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?>
                                    <div class="cal-dow"><?php echo $dow; ?></div>
                                <?php endforeach; ?>
                                <?php for ($blank = 0; $blank < $calendarFirstWeekday; $blank++): ?>
                                    <div class="cal-empty"></div>
                                <?php endfor; ?>
                                <?php for ($day = 1; $day <= $calendarDaysInMonth; $day++): ?>
                                    <?php
                                    $dateValue = $calendarMonthStart->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                                    $dateDayOfWeek = (int) date('N', strtotime($dateValue));
                                    $isConsultationSchedule = $bk['type'] === 'consultation';
                                    $isUltrasoundSchedule = $bk['type'] === 'ultrasound';
                                    $doctorSlots = $calendarDoctorSlotsByDow[$dateDayOfWeek] ?? [];
                                    $doctorAvailable = $isConsultationSchedule && $selectedDoctor && !empty($doctorSlots);
                                    $clinicOpen = $isConsultationSchedule
                                        ? $doctorAvailable
                                        : ($isUltrasoundSchedule ? appointment_ultrasound_is_available_date($dateValue) : ($dateDayOfWeek >= 1 && $dateDayOfWeek <= 6));
                                    $selectedDoctorIdForCalendar = $selectedDoctor ? (int) $selectedDoctor['id'] : 0;
                                    $consultationBooked = $selectedDoctorIdForCalendar > 0
                                        ? (int) ($calendarDoctorDayCounts[$selectedDoctorIdForCalendar][$dateValue] ?? 0)
                                        : (int) ($calendarConsultationDayCounts[$dateValue] ?? 0);
                                    $consultationLimit = $doctorDailyLimit;
                                    $consultationFull = $consultationBooked >= $consultationLimit;
                                    $consultationCapacityRows = [[
                                        'doctor' => $selectedDoctor ? (string) $selectedDoctor['full_name'] : 'Doctor consultations',
                                        'specialty' => $selectedDoctor ? (string) ($selectedDoctor['specialty'] ?: 'General consultation') : 'Doctor consultation',
                                        'booked' => $consultationBooked,
                                        'remaining' => max(0, $consultationLimit - $consultationBooked),
                                        'limit' => $consultationLimit,
                                        'full' => $consultationFull || !$doctorAvailable,
                                    ]];
                                    $labBooked = (int) ($calendarLabDayCounts[$dateValue] ?? 0);
                                    $labFull = $bk['type'] !== 'consultation' && $labBooked >= $labDailyLimit;
                                    $labCapacityRows = [[
                                        'doctor' => $bk['type'] === 'ultrasound' ? 'Ultra sound appointment slots' : 'Laboratory appointment slots',
                                        'specialty' => $bk['type'] === 'package'
                                            ? 'Package deals'
                                            : ($bk['type'] === 'ultrasound' ? 'Ultra sound' : 'Individual laboratory tests'),
                                        'booked' => $labBooked,
                                        'remaining' => max(0, $labDailyLimit - $labBooked),
                                        'limit' => $labDailyLimit,
                                        'full' => (bool) $labFull,
                                    ]];
                                    $capacityRows = $bk['type'] === 'consultation' ? $consultationCapacityRows : $labCapacityRows;
                                    $capacityBooked = $bk['type'] === 'consultation' ? $consultationBooked : $labBooked;
                                    $capacityLimit = $bk['type'] === 'consultation' ? $consultationLimit : $labDailyLimit;
                                    $capacityFull = $bk['type'] === 'consultation' ? $consultationFull : $labFull;
                                    $classes = ['cal-day'];
                                    if ($dateValue === $calendarToday) {
                                        $classes[] = 'is-today';
                                    }
                                    if ($dateValue === $calendarSelected) {
                                        $classes[] = 'is-selected';
                                    }
                                    if ($clinicOpen) {
                                        $classes[] = 'is-clinic-open';
                                    }
                                    if ($doctorAvailable) {
                                        $classes[] = 'has-doctor';
                                    }
                                    if (!$clinicOpen) {
                                        $classes[] = 'is-closed';
                                    }
                                    if ($capacityFull) {
                                        $classes[] = 'is-fully-booked';
                                    }
                                    if ($isConsultationSchedule && !$doctorAvailable) {
                                        $classes[] = 'is-unavailable';
                                    }
                                    $disabled = $dateValue < $calendarToday || ($isConsultationSchedule ? !$doctorAvailable : !$clinicOpen);
                                    ?>
                                    <button
                                        type="button"
                                        class="<?php echo implode(' ', $classes); ?>"
                                        data-date="<?php echo htmlspecialchars($dateValue); ?>"
                                        data-selectable="<?php echo ($capacityFull || ($isConsultationSchedule && !$doctorAvailable)) ? '0' : '1'; ?>"
                                        data-capacity-date="<?php echo htmlspecialchars($dateValue); ?>"
                                        data-capacity-doctors="<?php echo htmlspecialchars(json_encode($capacityRows, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-capacity-booked="<?php echo $capacityBooked; ?>"
                                        data-capacity-limit="<?php echo $capacityLimit; ?>"
                                        data-capacity-all-full="<?php echo $capacityFull ? '1' : '0'; ?>"
                                        <?php echo $disabled ? ' disabled' : ''; ?>
                                    >
                                        <span class="cal-date-number"><?php echo $day; ?></span>
                                        <div class="calendar-events">
                                            <?php if ($clinicOpen && $dateValue >= $calendarToday): ?>
                                                <small class="clinic-hours-event">
                                                    <span class="clinic-hours-label"><?php echo $bk['type'] === 'consultation' ? 'Doctor schedule' : 'Clinic open'; ?></span>
                                                    <?php if ($bk['type'] === 'consultation' && !empty($doctorSlots[0])): ?>
                                                        <span><?php echo htmlspecialchars($doctorSlots[0]['start'] . ' - ' . $doctorSlots[0]['end']); ?></span>
                                                    <?php endif; ?>
                                                </small>
                                                <?php if ($bk['type'] === 'consultation'): ?>
                                                    <small class="capacity-event<?php echo $consultationFull ? ' is-full' : ''; ?>">
                                                        <strong><?php echo $consultationFull ? 'Fully booked' : $consultationBooked . '/' . $consultationLimit . ' booked'; ?></strong>
                                                        <span><?php echo max(0, $consultationLimit - $consultationBooked); ?> slots remaining</span>
                                                    </small>
                                                <?php else: ?>
                                                <small class="capacity-event<?php echo $labFull ? ' is-full' : ''; ?>">
                                                    <strong><?php echo $labFull ? 'Fully booked' : $labBooked . '/' . $labDailyLimit . ' booked'; ?></strong>
                                                    <span><?php echo max(0, $labDailyLimit - $labBooked); ?> appointment slots remaining</span>
                                                </small>
                                                <?php endif; ?>
                                            <?php elseif (!$clinicOpen && $dateValue >= $calendarToday): ?>
                                                <small class="clinic-closed-event"><?php echo $bk['type'] === 'consultation' ? 'Doctor not available' : ($bk['type'] === 'ultrasound' ? 'Ultra sound unavailable' : 'Clinic closed'); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </button>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="schedule-controls">
                            <div class="selected-date-card">
                                <span>Selected date</span>
                                <strong id="selectedDateText"><?php echo $bk['appointment_date'] !== '' ? date('M d, Y', strtotime($bk['appointment_date'])) : 'Choose a date from the calendar'; ?></strong>
                            </div>
                            <p class="schedule-note">
                                <?php if ($bk['type'] === 'consultation'): ?>
                                    The calendar only enables the selected doctor schedule. Up to <?php echo $doctorDailyLimit; ?> bookings are accepted per doctor schedule day.
                                <?php elseif ($bk['type'] === 'ultrasound'): ?>
                                    Ultra sound appointments are available every Wednesday and Saturday, up to <?php echo $labDailyLimit; ?> bookings per day.
                                <?php else: ?>
                                    Laboratory appointments are available on open clinic dates, up to <?php echo $labDailyLimit; ?> bookings per day.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-primary" id="btnScheduleNext"><?php echo $bk['type'] === 'ultrasound' ? 'Next: confirm' : 'Next: review and confirm'; ?></button>
            </form>

            <script>
            (function() {
                var form = document.getElementById('scheduleForm');
                var actionField = document.getElementById('booking_action_field');
                var dateInp = document.getElementById('appointment_date');
                var btnNext = document.getElementById('btnScheduleNext');
                var calendar = document.getElementById('appointmentCalendar');
                var selectedDateCard = document.querySelector('.selected-date-card');
                var selectedDateText = document.getElementById('selectedDateText');
                if (!form || !actionField || !dateInp || !calendar) return;

                function formatDateLabel(value) {
                    if (!value) return 'Choose a date from the calendar';
                    var parts = value.split('-');
                    if (parts.length !== 3) return value;
                    var dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
                    return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                }
                function updateView() {
                    if (selectedDateText) {
                        selectedDateText.textContent = formatDateLabel(dateInp.value);
                    }
                    calendar.querySelectorAll('.cal-day').forEach(function(day) {
                        day.classList.toggle('is-selected', day.getAttribute('data-date') === dateInp.value);
                    });
                }

                calendar.querySelectorAll('.cal-day').forEach(function(day) {
                    day.addEventListener('click', function() {
                        if (day.disabled) return;
                        if (
                            typeof window.openDoctorCapacity === 'function'
                            && day.hasAttribute('data-capacity-date')
                            && day.getAttribute('data-capacity-all-full') === '1'
                        ) {
                            window.openDoctorCapacity(day);
                            return;
                        }
                        if (day.getAttribute('data-selectable') === '0') {
                            return;
                        }
                        dateInp.value = day.getAttribute('data-date') || '';
                        selectedDateCard.classList.remove('is-invalid');
                        updateView();
                    });
                });

                if (btnNext) {
                    btnNext.addEventListener('click', function() {
                        actionField.value = 'set_schedule';
                    });
                }
                form.addEventListener('submit', function(event) {
                    actionField.value = 'set_schedule';
                    if (!dateInp.value) {
                        event.preventDefault();
                        selectedDateCard.classList.add('is-invalid');
                        selectedDateText.textContent = 'Choose a date from the calendar';
                        calendar.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }
                    var selectedDay = calendar.querySelector('.cal-day[data-date="' + dateInp.value + '"]');
                    if (selectedDay && selectedDay.getAttribute('data-selectable') === '0') {
                        event.preventDefault();
                        if (typeof window.openDoctorCapacity === 'function') {
                            window.openDoctorCapacity(selectedDay);
                        }
                    }
                });
                updateView();
            })();
            </script>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>

        <?php elseif ($step === 5): ?>
            <div class="info-box">
                <strong><?php echo $bk['type'] === 'ultrasound' ? 'Step 3 - Confirm.' : 'Step 5 - Visit the clinic.'; ?></strong> Review your appointment summary, verify your request, then visit the clinic on your selected schedule.
            </div>
            <table class="summary-table">
                <tr>
                    <td>Appointment type</td>
                    <td>
                        <?php
                        echo $bk['type'] === 'consultation'
                            ? 'Doctor consultation'
                            : ($bk['type'] === 'package'
                                ? 'Laboratory package'
                                : ($bk['type'] === 'ultrasound' ? 'Ultra sound' : 'Individual laboratory tests'));
                        ?>
                    </td>
                </tr>
                <?php if ($bk['type'] === 'consultation' && $selectedDoctor): ?>
                    <tr><td>Doctor</td><td><?php echo htmlspecialchars((string) $selectedDoctor['full_name']); ?></td></tr>
                    <tr><td>Specialty</td><td><?php echo htmlspecialchars((string) ($selectedDoctor['specialty'] ?: 'General consultation')); ?></td></tr>
                <?php elseif ($bk['type'] === 'consultation'): ?>
                    <tr><td>Doctor</td><td>Please choose a doctor</td></tr>
                <?php endif; ?>
                <tr><td>Date</td><td><?php echo htmlspecialchars($bk['appointment_date']); ?></td></tr>
                <?php if ($bk['type'] === 'consultation'): ?>
                    <tr><td>Payment</td><td>Consultation fee paid at clinic</td></tr>
                <?php elseif ($bk['type'] === 'ultrasound'): ?>
                    <tr><td>Payment</td><td>Ultra sound fee paid at clinic</td></tr>
                <?php else: ?>
                    <tr><td><strong>Total (pay at clinic)</strong></td><td><strong>PHP <?php echo number_format($displayTotal, 2); ?></strong></td></tr>
                <?php endif; ?>
            </table>
            <?php if (in_array($bk['type'], ['package', 'individual'], true)): ?>
                <h4 style="margin:16px 0 8px;color:#023e8a;">Selected services</h4>
                <ul style="margin:0;padding-left:20px;color:#444;">
                    <?php foreach ($selectedServices as $svc): ?>
                        <li><?php echo htmlspecialchars($svc['name']); ?> - PHP <?php echo number_format(serviceUnitPrice($svc, 'opd'), 2); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="book_appointment.php" style="margin-top:24px;">
                <input type="hidden" name="booking_action" value="confirm_booking">
                <label class="review-check">
                    <input type="checkbox" required>
                    <span>I reviewed the details and understand that this appointment request will be submitted for clinic review.</span>
                </label>
                <button type="submit" class="btn-primary">Submit appointment request</button>
            </form>
            <a href="book_appointment.php?step_back=1" class="btn-secondary" style="margin-top:12px;display:inline-block;">Back</a>
        <?php endif; ?>
        <?php endif; ?>

        <?php endif; ?>

        <div class="capacity-modal" id="doctorCapacityModal" aria-hidden="true">
            <section class="capacity-dialog" role="dialog" aria-modal="true" aria-labelledby="doctorCapacityTitle">
                <div class="capacity-dialog-head">
                    <div>
                        <span>Appointment capacity</span>
                        <h3 id="doctorCapacityTitle">Appointment availability</h3>
                    </div>
                    <button type="button" class="capacity-modal-close" id="doctorCapacityClose" aria-label="Close appointment availability">&times;</button>
                </div>
                <div class="capacity-dialog-body">
                    <div class="capacity-summary">
                        <div class="capacity-stat"><span>Appointments</span><strong id="capacityBooked">0</strong></div>
                        <div class="capacity-stat"><span>Maximum</span><strong id="capacityLimit"><?php echo $bk['type'] === 'consultation' ? $doctorDailyLimit : $labDailyLimit; ?></strong></div>
                        <div class="capacity-stat"><span>Remaining</span><strong id="capacityRemaining"><?php echo $bk['type'] === 'consultation' ? $doctorDailyLimit : $labDailyLimit; ?></strong></div>
                    </div>
                    <div class="capacity-doctors" id="capacityDoctorRows"></div>
                    <p class="capacity-message" id="capacityMessage">Select an available date to continue.</p>
                </div>
            </section>
        </div>
        <script>
        (function() {
            var modal = document.getElementById('doctorCapacityModal');
            var closeButton = document.getElementById('doctorCapacityClose');
            var title = document.getElementById('doctorCapacityTitle');
            var bookedEl = document.getElementById('capacityBooked');
            var limitEl = document.getElementById('capacityLimit');
            var remainingEl = document.getElementById('capacityRemaining');
            var rowsEl = document.getElementById('capacityDoctorRows');
            var messageEl = document.getElementById('capacityMessage');
            if (!modal || !closeButton || !title || !rowsEl) return;

            function safeNumber(value) {
                var number = Number(value);
                return Number.isFinite(number) ? number : 0;
            }
            function dateLabel(value) {
                var parts = (value || '').split('-');
                if (parts.length !== 3) return value || 'Appointment date';
                return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]))
                    .toLocaleDateString('en-US', { weekday:'long', month:'long', day:'numeric', year:'numeric' });
            }
            function closeModal() {
                modal.classList.remove('open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }
            window.openDoctorCapacity = function(day) {
                var allFull = day.getAttribute('data-capacity-all-full') === '1';
                if (!allFull) {
                    return;
                }
                var doctors = [];
                try {
                    doctors = JSON.parse(day.getAttribute('data-capacity-doctors') || '[]');
                } catch (error) {
                    doctors = [];
                }
                var booked = safeNumber(day.getAttribute('data-capacity-booked'));
                var limit = safeNumber(day.getAttribute('data-capacity-limit'));
                var remaining = Math.max(0, limit - booked);

                title.textContent = 'Sorry, this day is fully booked';
                bookedEl.textContent = String(booked);
                limitEl.textContent = String(limit);
                remainingEl.textContent = String(remaining);
                rowsEl.innerHTML = '';

                if (!doctors.length) {
                    var empty = document.createElement('div');
                    empty.className = 'capacity-doctor-row is-full';
                    empty.textContent = 'No appointment slots are available on this date.';
                    rowsEl.appendChild(empty);
                } else {
                    doctors.forEach(function(doctor) {
                        var row = document.createElement('div');
                        row.className = 'capacity-doctor-row' + (doctor.full ? ' is-full' : '');
                        var copy = document.createElement('div');
                        copy.className = 'capacity-doctor-copy';
                        var name = document.createElement('strong');
                        name.textContent = doctor.doctor || 'Appointment slots';
                        var detail = document.createElement('span');
                        detail.textContent = (doctor.specialty || 'Clinic schedule') + ' - ' + safeNumber(doctor.booked) + '/' + safeNumber(doctor.limit) + ' booked';
                        copy.appendChild(name);
                        copy.appendChild(detail);
                        var chip = document.createElement('span');
                        chip.className = 'capacity-chip' + (doctor.full ? ' full' : '');
                        chip.textContent = doctor.full ? 'Fully booked' : safeNumber(doctor.remaining) + ' remaining';
                        row.appendChild(copy);
                        row.appendChild(chip);
                        rowsEl.appendChild(row);
                    });
                }

                messageEl.classList.toggle('full', allFull);
                messageEl.textContent = dateLabel(day.getAttribute('data-capacity-date'))
                    + ' has reached the appointment limit. Please choose another available date.';
                modal.classList.add('open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            };

            document.querySelectorAll('#startAppointmentCalendar [data-capacity-date]').forEach(function(day) {
                day.addEventListener('click', function() {
                    if (!day.disabled && day.getAttribute('data-capacity-all-full') === '1') {
                        window.openDoctorCapacity(day);
                    }
                });
            });
            closeButton.addEventListener('click', closeModal);
            modal.addEventListener('click', function(event) {
                if (event.target === modal) closeModal();
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && modal.classList.contains('open')) closeModal();
            });
        })();
        </script>
    </div>
</div>

<?php include 'includes/footer.php'; ?>


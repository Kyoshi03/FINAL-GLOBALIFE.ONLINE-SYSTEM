<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/appointment_booking.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function reschedule_availability_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) {
    reschedule_availability_response(['ok' => false, 'error' => 'Please sign in again.'], 401);
}

$appointmentId = (int) ($_GET['appointment_id'] ?? 0);
if ($appointmentId <= 0) {
    reschedule_availability_response(['ok' => false, 'error' => 'Invalid appointment selected.'], 400);
}

$currentUser = getCurrentUser();
$userRole = (string) ($currentUser['role'] ?? '');
$conn = getDBConnection();

$stmt = $conn->prepare(
    "SELECT id, patient_id, doctor_id, appointment_date, appointment_time, status, booking_type
     FROM appointments
     WHERE id = ?
     LIMIT 1"
);
if (!$stmt) {
    $conn->close();
    reschedule_availability_response(['ok' => false, 'error' => 'The appointment could not be checked.'], 500);
}
$stmt->bind_param('i', $appointmentId);
$stmt->execute();
$appointment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$appointment) {
    $conn->close();
    reschedule_availability_response(['ok' => false, 'error' => 'Appointment not found.'], 404);
}

$status = strtolower((string) ($appointment['status'] ?? ''));
$bookingType = strtolower((string) ($appointment['booking_type'] ?? ''));
$isOpenStatus = in_array($status, ['pending', 'confirmed'], true);
$hasPermission = false;
if ($userRole === 'admin') {
    $hasPermission = $isOpenStatus;
} elseif ($userRole === 'doctor') {
    $hasPermission = $isOpenStatus
        && $bookingType === 'consultation'
        && (int) ($appointment['doctor_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
} elseif ($userRole === 'patient') {
    $hasPermission = $isOpenStatus
        && (int) ($appointment['patient_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
}

if (!$hasPermission) {
    $conn->close();
    reschedule_availability_response(['ok' => false, 'error' => 'You do not have permission to reschedule this appointment.'], 403);
}

$baseResponse = [
    'ok' => true,
    'booking_type' => $bookingType,
    'current_date' => (string) ($appointment['appointment_date'] ?? ''),
    'current_time' => substr((string) ($appointment['appointment_time'] ?? ''), 0, 5),
];

if ($bookingType === 'ultrasound') {
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $rangeEnd = $today->modify('+365 days');
    $from = $today->format('Y-m-d');
    $to = $rangeEnd->format('Y-m-d');
    $bookedByDate = [];
    $countStmt = $conn->prepare(
        "SELECT appointment_date, COUNT(*) AS total
         FROM appointments
         WHERE appointment_date >= ?
           AND appointment_date <= ?
           AND booking_type = 'ultrasound'
           AND status <> 'cancelled'
           AND id <> ?
         GROUP BY appointment_date"
    );
    if ($countStmt) {
        $countStmt->bind_param('ssi', $from, $to, $appointmentId);
        if ($countStmt->execute()) {
            foreach ($countStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $date = (string) ($row['appointment_date'] ?? '');
                if ($date !== '') {
                    $bookedByDate[$date] = (int) ($row['total'] ?? 0);
                }
            }
        }
        $countStmt->close();
    }

    $limit = appointment_ultrasound_daily_limit();
    $availableDates = [];
    $unavailableReasons = [];
    $cursor = $today;
    while ($cursor <= $rangeEnd) {
        $date = $cursor->format('Y-m-d');
        $booked = (int) ($bookedByDate[$date] ?? 0);
        if (!appointment_ultrasound_is_available_date($date)) {
            $unavailableReasons[$date] = 'Ultrasound is available on Wednesday and Saturday only.';
        } elseif ($booked >= $limit) {
            $unavailableReasons[$date] = 'Ultrasound is fully booked on this date.';
        } else {
            $availableDates[] = $date;
        }
        $cursor = $cursor->modify('+1 day');
    }

    $conn->close();
    reschedule_availability_response($baseResponse + [
        'date_min' => $from,
        'date_max' => $to,
        'daily_limit' => $limit,
        'available_dates' => $availableDates,
        'booked_by_date' => $bookedByDate,
        'unavailable_reasons' => $unavailableReasons,
    ]);
}

if (in_array($bookingType, ['package', 'individual'], true)) {
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    $rangeEnd = $today->modify('+365 days');
    $from = $today->format('Y-m-d');
    $to = $rangeEnd->format('Y-m-d');
    $bookedByDate = [];
    $countStmt = $conn->prepare(
        "SELECT appointment_date, COUNT(*) AS total
         FROM appointments
         WHERE appointment_date >= ?
           AND appointment_date <= ?
           AND booking_type IN ('package', 'individual')
           AND status <> 'cancelled'
           AND id <> ?
         GROUP BY appointment_date"
    );
    if ($countStmt) {
        $countStmt->bind_param('ssi', $from, $to, $appointmentId);
        if ($countStmt->execute()) {
            foreach ($countStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $date = (string) ($row['appointment_date'] ?? '');
                if ($date !== '') {
                    $bookedByDate[$date] = (int) ($row['total'] ?? 0);
                }
            }
        }
        $countStmt->close();
    }

    $limit = appointment_lab_daily_limit();
    $availableDates = [];
    $unavailableReasons = [];
    $cursor = $today;
    while ($cursor <= $rangeEnd) {
        $date = $cursor->format('Y-m-d');
        $booked = (int) ($bookedByDate[$date] ?? 0);
        if (!appointment_clinic_is_open_at($date, '08:00')) {
            $unavailableReasons[$date] = 'Laboratory appointments are available Monday to Saturday only.';
        } elseif ($booked >= $limit) {
            $unavailableReasons[$date] = 'Laboratory appointments are fully booked on this date.';
        } else {
            $availableDates[] = $date;
        }
        $cursor = $cursor->modify('+1 day');
    }

    $conn->close();
    reschedule_availability_response($baseResponse + [
        'date_min' => $from,
        'date_max' => $to,
        'daily_limit' => $limit,
        'available_dates' => $availableDates,
        'booked_by_date' => $bookedByDate,
        'unavailable_reasons' => $unavailableReasons,
    ]);
}

if ($bookingType !== 'consultation') {
    $conn->close();
    reschedule_availability_response($baseResponse);
}

$doctorId = (int) ($appointment['doctor_id'] ?? 0);
$doctorStmt = $conn->prepare(
    "SELECT id, " . dbUsersNameExpression() . " AS full_name, COALESCE(is_active, 1) AS is_active
     FROM users
     WHERE id = ? AND role = 'doctor'
     LIMIT 1"
);
if (!$doctorStmt) {
    $conn->close();
    reschedule_availability_response(['ok' => false, 'error' => 'The doctor schedule could not be loaded.'], 500);
}
$doctorStmt->bind_param('i', $doctorId);
$doctorStmt->execute();
$doctor = $doctorStmt->get_result()->fetch_assoc();
$doctorStmt->close();

$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$rangeEnd = $today->modify('+365 days');
$from = $today->format('Y-m-d');
$to = $rangeEnd->format('Y-m-d');
$slots = doctor_fetch_availability_slots($conn, $doctorId);
$slotsByDay = [];
foreach ($slots as $slot) {
    $day = (int) ($slot['day_of_week'] ?? 0);
    if ($day < 1 || $day > 7) {
        continue;
    }
    $slotsByDay[$day][] = [
        'start' => substr((string) ($slot['time_start'] ?? ''), 0, 5),
        'end' => substr((string) ($slot['time_end'] ?? ''), 0, 5),
    ];
}

$bookedByDate = [];
$countStmt = $conn->prepare(
    "SELECT appointment_date, COUNT(*) AS total
     FROM appointments
     WHERE doctor_id = ?
       AND appointment_date >= ?
       AND appointment_date <= ?
       AND booking_type = 'consultation'
       AND status <> 'cancelled'
       AND id <> ?
     GROUP BY appointment_date"
);
if ($countStmt) {
    $countStmt->bind_param('issi', $doctorId, $from, $to, $appointmentId);
    if ($countStmt->execute()) {
        foreach ($countStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $date = (string) ($row['appointment_date'] ?? '');
            if ($date !== '') {
                $bookedByDate[$date] = (int) ($row['total'] ?? 0);
            }
        }
    }
    $countStmt->close();
}

$limit = appointment_doctor_daily_limit();
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$availableDates = [];
$slotsByDate = [];
$unavailableReasons = [];
$cursor = $today;
while ($cursor <= $rangeEnd) {
    $date = $cursor->format('Y-m-d');
    $day = (int) $cursor->format('N');
    $dateSlots = $slotsByDay[$day] ?? [];
    $booked = (int) ($bookedByDate[$date] ?? 0);
    $hasFutureTime = true;
    if ($date === $today->format('Y-m-d')) {
        $hasFutureTime = false;
        foreach ($dateSlots as $slot) {
            if ($slot['end'] > $now->format('H:i')) {
                $hasFutureTime = true;
                break;
            }
        }
    }

    if (!$doctor || (int) ($doctor['is_active'] ?? 0) !== 1) {
        $unavailableReasons[$date] = 'Doctor is not active.';
    } elseif (!$dateSlots) {
        $unavailableReasons[$date] = 'No doctor schedule on this date.';
    } elseif ($booked >= $limit) {
        $unavailableReasons[$date] = 'Doctor is fully booked on this date.';
    } elseif (!$hasFutureTime) {
        $unavailableReasons[$date] = 'No remaining schedule today.';
    } else {
        $availableDates[] = $date;
        $slotsByDate[$date] = $dateSlots;
    }
    $cursor = $cursor->modify('+1 day');
}

$conn->close();
reschedule_availability_response($baseResponse + [
    'doctor_id' => $doctorId,
    'doctor_name' => (string) ($doctor['full_name'] ?? 'Selected doctor'),
    'date_min' => $from,
    'date_max' => $to,
    'daily_limit' => $limit,
    'available_dates' => $availableDates,
    'slots_by_date' => $slotsByDate,
    'booked_by_date' => $bookedByDate,
    'unavailable_reasons' => $unavailableReasons,
]);

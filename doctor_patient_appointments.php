<?php
require_once 'includes/session.php';
checkRole('doctor');
require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$pageTitle = 'Appointment History | Doctor';
$currentUser = getCurrentUser();
$doctorId = (int) ($currentUser['id'] ?? 0);
$patientId = (int) ($_GET['patient_id'] ?? 0);
$perPage = 5;
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($patientId <= 0) {
    header('Location: doctor_patients.php');
    exit;
}

$conn = getDBConnection();

function dah_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('F j, Y', $stamp) : '—';
}

function dah_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '—';
}

function dah_status_label(?string $status): string {
    return [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][strtolower((string) $status)] ?? 'Pending';
}

function dah_status_class(?string $status): string {
    $status = strtolower((string) $status);
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

function dah_service_label(array $appointment): string {
    return [
        'consultation' => 'Doctor consultation',
        'package' => 'Laboratory package',
        'individual' => 'Laboratory tests',
        'ultrasound' => 'Ultrasound',
    ][strtolower((string) ($appointment['booking_type'] ?? 'consultation'))] ?? 'Appointment';
}

$accessStmt = $conn->prepare("SELECT 1 FROM appointments WHERE doctor_id = ? AND patient_id = ? AND booking_type = 'consultation' LIMIT 1");
$accessStmt->bind_param('ii', $doctorId, $patientId);
$accessStmt->execute();
$hasAccess = $accessStmt->get_result()->num_rows > 0;
$accessStmt->close();

if (!$hasAccess) {
    $conn->close();
    header('Location: doctor_patients.php');
    exit;
}

$userNameSql = dbUsersNameExpression();
$patientStmt = $conn->prepare("SELECT id, {$userNameSql} AS full_name, username, email, profile_photo, profile_updated_at FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
$patientStmt->bind_param('i', $patientId);
$patientStmt->execute();
$patient = $patientStmt->get_result()->fetch_assoc();
$patientStmt->close();

if (!$patient) {
    $conn->close();
    header('Location: doctor_patients.php');
    exit;
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM appointments WHERE doctor_id = ? AND patient_id = ? AND booking_type = 'consultation'");
$countStmt->bind_param('ii', $doctorId, $patientId);
$countStmt->execute();
$totalAppointments = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int) ceil($totalAppointments / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$appointmentSql = "
    SELECT id, appointment_date, appointment_time, status, booking_type
    FROM appointments
    WHERE doctor_id = ?
      AND patient_id = ?
      AND booking_type = 'consultation'
    ORDER BY appointment_date DESC, appointment_time DESC
    LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$appointmentStmt = $conn->prepare($appointmentSql);
$appointmentStmt->bind_param('ii', $doctorId, $patientId);
$appointmentStmt->execute();
$appointments = $appointmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$appointmentStmt->close();

$conn->close();

$showingStart = $totalAppointments > 0 ? $offset + 1 : 0;
$showingEnd = min($offset + count($appointments), $totalAppointments);
$patientQuery = ['patient_id' => $patientId];

function dah_page_url(int $page, int $patientId): string {
    return 'doctor_patient_appointments.php?' . http_build_query(['patient_id' => $patientId, 'page' => $page]);
}

$additionalStyles = patientAvatarStyles() . '
body{background:linear-gradient(135deg,#f5fbff 0%,#eaf7fb 100%);min-height:100vh;color:#073b4c}
.dah-wrap{max-width:1120px;margin:0 auto;padding:34px 22px 48px}
.dah-card{background:#fff;border:1px solid #d5e8f4;border-radius:8px;box-shadow:0 12px 30px rgba(15,86,124,.08);overflow:hidden}
.dah-profile{display:flex;align-items:center;gap:14px;padding:20px 24px;margin-bottom:18px}.dah-profile h1{margin:0;color:#09233f;font-size:1.45rem}.dah-profile p{margin:5px 0 0;color:#607889}
.dah-head{padding:22px 24px;border-bottom:1px solid #deebf3;background:#fbfdff}.dah-head h2{margin:0;color:#073b4c;font-size:1.3rem}.dah-head p{margin:5px 0 0;color:#607889}
.dah-table{width:100%;border-collapse:collapse;table-layout:fixed}.dah-table th,.dah-table td{padding:15px 18px;border-bottom:1px solid #deebf3;text-align:center;vertical-align:middle;overflow-wrap:anywhere}.dah-table th{background:#f5f9fc;color:#526c7f;font-size:.82rem;letter-spacing:.02em}.dah-table th:nth-child(1){width:22%}.dah-table th:nth-child(2){width:16%}.dah-table th:nth-child(3){width:36%}.dah-table th:nth-child(4){width:26%}
.dah-service{color:#213b4b;line-height:1.35}.dah-empty{padding:38px 24px;text-align:center;color:#607889}
.dah-foot{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 24px}.dah-count{color:#607889}.dah-pages{display:flex;align-items:center;gap:8px}.dah-pages .dah-page{box-sizing:border-box;min-width:36px;width:auto;height:36px;min-height:36px;padding:0 10px;border:1px solid #d5e8f4;border-radius:8px;background:#fff;color:#0077b6;font-size:.85rem;line-height:1;font-weight:800;text-decoration:none;display:grid;place-items:center}.dah-pages .dah-page.active{width:36px;padding:0;background:#0077b6;color:#fff;border-color:#0077b6;box-shadow:0 10px 24px rgba(0,119,182,.18)}.dah-pages .dah-page.disabled{pointer-events:none;color:#adc0cc;background:#f8fbfd}
.dah-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:6px 10px;font-weight:900;font-size:.78rem;white-space:nowrap}.dah-status.pending{background:#fff3d6;color:#a36600}.dah-status.confirmed{background:#e3f1ff;color:#075fa7}.dah-status.completed{background:#e6f7ed;color:#08723d}.dah-status.cancelled{background:#ffe9ec;color:#bc2633}
@media(max-width:760px){.dah-wrap{padding:24px 14px 36px}.dah-profile{padding:18px}.dah-head{padding:18px}.dah-table,.dah-table thead,.dah-table tbody,.dah-table tr,.dah-table th,.dah-table td{display:block}.dah-table thead{display:none}.dah-table tr{padding:14px 18px;border-bottom:1px solid #deebf3}.dah-table td{padding:7px 0;border:0}.dah-table td::before{content:attr(data-label);display:block;color:#607889;font-size:.78rem;font-weight:800;margin-bottom:3px}.dah-foot{flex-direction:column;align-items:flex-start;padding:18px}.dah-pages{width:100%;justify-content:flex-start}}
';

include 'includes/header.php';
?>
<main class="dah-wrap">
  <section class="dah-card dah-profile">
    <?php echo renderPatientAvatar($patient, ['size' => 'md']); ?>
    <div>
      <h1><?php echo htmlspecialchars((string) $patient['full_name']); ?></h1>
      <p><?php echo htmlspecialchars((string) $patient['username']); ?><?php echo !empty($patient['email']) ? ' - ' . htmlspecialchars((string) $patient['email']) : ''; ?></p>
    </div>
  </section>

  <section class="dah-card">
    <div class="dah-head">
      <h2>Appointment History</h2>
      <p>All doctor consultation appointments for this patient.</p>
    </div>

    <?php if (empty($appointments)): ?>
      <div class="dah-empty">No appointment records found.</div>
    <?php else: ?>
      <table class="dah-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time</th>
            <th>Service</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($appointments as $appointment): ?>
            <tr>
              <td data-label="Date"><?php echo htmlspecialchars(dah_date_label($appointment['appointment_date'] ?? null)); ?></td>
              <td data-label="Time"><?php echo htmlspecialchars(dah_time_label($appointment['appointment_time'] ?? null)); ?></td>
              <td data-label="Service" class="dah-service"><?php echo htmlspecialchars(dah_service_label($appointment)); ?></td>
              <td data-label="Status"><span class="dah-status <?php echo htmlspecialchars(dah_status_class($appointment['status'] ?? null)); ?>"><?php echo htmlspecialchars(dah_status_label($appointment['status'] ?? null)); ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div class="dah-foot">
      <div class="dah-count">Showing <?php echo $showingStart; ?> to <?php echo $showingEnd; ?> of <?php echo $totalAppointments; ?> appointments.</div>
      <div class="dah-pages" aria-label="Pagination">
        <a class="dah-page <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(dah_page_url(max(1, $page - 1), $patientId)); ?>" aria-label="Previous page">Previous</a>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a class="dah-page <?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(dah_page_url($i, $patientId)); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <a class="dah-page <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(dah_page_url(min($totalPages, $page + 1), $patientId)); ?>" aria-label="Next page">Next</a>
      </div>
    </div>
  </section>
</main>
<?php include 'includes/footer.php'; ?>

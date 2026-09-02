<?php
require_once 'includes/session.php';
checkRole('doctor');
require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/nurse_clinical.php';
require_once __DIR__ . '/includes/nurse_clinical_styles.php';

$pageTitle = 'Patient List | Doctor';
$currentUser = getCurrentUser();
$doctorId = (int) ($currentUser['id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? $_POST['return_q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? $_POST['return_page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;
$message = '';
$error = '';
$openPatientId = (int) ($_GET['patient'] ?? $_POST['selected_patient_id'] ?? 0);

$conn = getDBConnection();
nurse_clinical_ensure_schema($conn);

function np_bind_params(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [&$types];
    foreach ($params as &$value) {
        $refs[] = &$value;
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function np_prepare_or_fail(mysqli $conn, string $sql): mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Database query failed: ' . $conn->error);
    }
    return $stmt;
}

function np_doctor_can_access_patient(mysqli $conn, int $doctorId, int $patientId): bool {
    $stmt = $conn->prepare("SELECT 1 FROM appointments WHERE doctor_id = ? AND patient_id = ? AND booking_type = 'consultation' LIMIT 1");
    $stmt->bind_param('ii', $doctorId, $patientId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['nurse_patient_action'] ?? '') === 'add_medical') {
    $patientId = (int) ($_POST['patient_id'] ?? 0);
    $openPatientId = $patientId;

    if ($patientId <= 0 || !np_doctor_can_access_patient($conn, $doctorId, $patientId)) {
        $error = 'You can only add notes for your consultation patients.';
    } else {
        $result = nurse_clinical_save_medical($conn, $patientId, $doctorId, nurse_medical_fields_from_post($_POST));
        if (!empty($result['ok'])) {
            $message = 'Medical note saved.';
        } else {
            $error = $result['error'] ?? 'Failed to save medical note.';
        }
    }
}

$where = "a.booking_type = 'consultation' AND a.doctor_id = ?";
$params = [$doctorId];
$types = 'i';

if ($q !== '') {
    $where .= " AND (p.full_name LIKE ? OR p.username LIKE ? OR p.email LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM (
        SELECT p.id
        FROM users p
        JOIN appointments a ON a.patient_id = p.id
        WHERE {$where}
        GROUP BY p.id
    ) patient_scope";
$countStmt = np_prepare_or_fail($conn, $countSql);
np_bind_params($countStmt, $types, $params);
$countStmt->execute();
$totalPatients = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int) ceil($totalPatients / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = "
    SELECT
        p.id,
        p.full_name,
        p.username,
        p.email,
        p.profile_photo,
        p.profile_updated_at,
        COUNT(a.id) AS consultation_count,
        MIN(CASE WHEN a.status IN ('pending', 'confirmed') AND a.appointment_date >= CURDATE() THEN a.appointment_date END) AS next_date,
        MIN(CASE WHEN a.status IN ('pending', 'confirmed') AND a.appointment_date >= CURDATE() THEN a.appointment_time END) AS next_time,
        MAX(a.appointment_date) AS latest_visit
    FROM users p
    JOIN appointments a ON a.patient_id = p.id
    WHERE {$where}
    GROUP BY p.id, p.full_name, p.username, p.email, p.profile_photo, p.profile_updated_at
    ORDER BY
        COALESCE(MIN(CASE WHEN a.status IN ('pending', 'confirmed') AND a.appointment_date >= CURDATE() THEN a.appointment_date END), '9999-12-31') ASC,
        p.full_name ASC
    LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;
$listParams = $params;
$listTypes = $types;
$listStmt = np_prepare_or_fail($conn, $listSql);
np_bind_params($listStmt, $listTypes, $listParams);
$listStmt->execute();
$patients = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$listStmt->close();

$patientIds = array_map(static fn ($row) => (int) $row['id'], $patients);
$appointmentsByPatient = [];
$medicalByPatient = [];

if (!empty($patientIds)) {
    $idList = implode(',', $patientIds);

    $appointmentSql = "
        SELECT id, patient_id, appointment_date, appointment_time, status, notes
        FROM appointments
        WHERE booking_type = 'consultation'
          AND doctor_id = ?
          AND patient_id IN ({$idList})
        ORDER BY appointment_date DESC, appointment_time DESC";
    $appointmentStmt = np_prepare_or_fail($conn, $appointmentSql);
    $appointmentStmt->bind_param('i', $doctorId);
    $appointmentStmt->execute();
    $appointmentRows = $appointmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $appointmentStmt->close();

    foreach ($appointmentRows as $appointment) {
        $appointmentsByPatient[(int) $appointment['patient_id']][] = $appointment;
    }

    $medicalSql = "
        SELECT m.*, u.full_name AS author_name
        FROM medical_records m
        LEFT JOIN users u ON u.id = m.author_id
        WHERE m.patient_id IN ({$idList})
        ORDER BY m.created_at DESC";
    $medicalRows = $conn->query($medicalSql)->fetch_all(MYSQLI_ASSOC);

    foreach ($medicalRows as $record) {
        $medicalByPatient[(int) $record['patient_id']][] = $record;
    }
}

$conn->close();

function np_date_label(?string $date): string {
    $stamp = strtotime((string) $date);
    return $stamp ? date('M j, Y', $stamp) : 'No upcoming visit';
}

function np_time_label(?string $time): string {
    $stamp = strtotime((string) $time);
    return $stamp ? date('g:i A', $stamp) : '';
}

function np_page_url(int $page, string $q): string {
    $query = ['page' => $page];
    if ($q !== '') {
        $query['q'] = $q;
    }
    return 'nurse_patients.php?' . http_build_query($query);
}

function np_status_label(?string $status): string {
    return [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ][strtolower((string) $status)] ?? 'Pending';
}

function np_status_class(?string $status): string {
    $status = strtolower((string) $status);
    return in_array($status, ['pending', 'confirmed', 'completed', 'cancelled'], true) ? $status : 'pending';
}

$showingStart = $totalPatients > 0 ? $offset + 1 : 0;
$showingEnd = min($offset + count($patients), $totalPatients);
$clinicalForm = [];

$additionalStyles = patientAvatarStyles() . nurse_clinical_styles() . '
body{background:linear-gradient(135deg,#f5fbff 0%,#eaf7fb 100%);min-height:100vh;color:#073b4c}
.np-wrap{max-width:1120px;margin:0 auto;padding:34px 22px 48px}
.np-title{display:flex;justify-content:space-between;gap:18px;align-items:flex-end;margin-bottom:22px}
.np-title h1{margin:0;color:#09233f;font-size:2rem;letter-spacing:0}
.np-title p{margin:8px 0 0;color:#5e7687}
.np-alert{border-radius:8px;margin-bottom:14px;padding:12px 14px;font-weight:800}
.np-alert.ok{background:#e8f8ef;color:#08723d;border:1px solid #c9eed9}
.np-alert.err{background:#fff1f1;color:#b4232a;border:1px solid #ffd3d6}
.np-card{background:#fff;border:1px solid #d5e8f4;border-radius:8px;box-shadow:0 12px 30px rgba(15,86,124,.08);overflow:hidden}
.np-card-head{display:flex;align-items:center;gap:14px;padding:22px 24px;border-bottom:1px solid #deebf3}
.np-card-icon{width:48px;height:48px;border-radius:8px;background:#edf7ff;color:#0077b6;display:grid;place-items:center;flex:0 0 auto}
.np-card-icon svg,.np-icon svg{width:26px;height:26px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.np-card-head h2{margin:0;color:#073b4c;font-size:1.28rem}
.np-card-head p{margin:4px 0 0;color:#607889}
.np-toolbar{display:flex;gap:12px;padding:18px 24px;border-bottom:1px solid #deebf3;background:#fbfdff}
.np-search{flex:1;display:flex;gap:12px}
.np-search input{flex:1;min-width:0;height:48px;border:1px solid #c9dfea;border-radius:8px;padding:0 16px;font:inherit;color:#073b4c;background:#fff}
.np-btn{height:48px;border:1px solid #0077b6;border-radius:8px;background:#0077b6;color:#fff;font-weight:800;padding:0 20px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.np-btn.secondary{background:#fff;color:#0077b6}
.np-table{width:100%;border-collapse:collapse;table-layout:fixed}
.np-table th,.np-table td{padding:18px 24px;border-bottom:1px solid #deebf3;text-align:left;vertical-align:middle}
.np-table th{background:#f5f9fc;color:#526c7f;font-size:.86rem}
.np-patient{display:flex;align-items:center;gap:12px;min-width:0}
.np-patient-text{min-width:0}
.np-patient strong{display:block;color:#073b4c;font-size:1.02rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.np-patient span{display:block;color:#6b8293;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.np-pill{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#eef8fc;color:#006494;font-weight:800;padding:8px 12px;white-space:nowrap}
.np-next strong{display:block;color:#073b4c}
.np-next span{display:block;color:#6b8293;font-size:.9rem;margin-top:2px}
.np-action{display:inline-flex;align-items:center;justify-content:center;min-height:42px;border:1px solid #b9d8ed;border-radius:8px;background:#fff;color:#006db3;font-weight:800;text-decoration:none;padding:0 14px;white-space:nowrap;cursor:pointer;font:inherit}
.np-action:hover{background:#edf7ff}
.np-empty{padding:38px 24px;text-align:center;color:#607889}
.np-foot{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 24px}
.np-count{color:#607889}
.np-pages{display:flex;align-items:center;gap:8px}
.np-page{min-width:42px;height:42px;border:1px solid #d5e8f4;border-radius:8px;background:#fff;color:#0077b6;font-weight:800;text-decoration:none;display:grid;place-items:center}
.np-page.active{background:#0077b6;color:#fff;border-color:#0077b6;box-shadow:0 10px 24px rgba(0,119,182,.18)}
.np-page.disabled{pointer-events:none;color:#adc0cc;background:#f8fbfd}
.np-modal{position:fixed;inset:0;z-index:5000;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(6,24,38,.56)}
.np-modal.is-open{display:flex}
.np-modal-card{width:min(980px,100%);max-height:90vh;overflow:auto;background:#fff;border:1px solid #d8e7f0;border-radius:8px;box-shadow:0 24px 70px rgba(7,59,76,.25)}
.np-modal-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;padding:22px 24px;border-bottom:1px solid #deebf3;background:#fbfdff}
.np-modal-profile{display:flex;align-items:center;gap:14px;min-width:0}
.np-modal-profile h2{margin:0;color:#09233f;font-size:1.42rem}
.np-modal-profile p{margin:5px 0 0;color:#5e7687}
.np-close{width:42px;height:42px;border:1px solid #d5e8f4;border-radius:8px;background:#fff;color:#073b4c;font-size:1.4rem;line-height:1;cursor:pointer}
.np-modal-body{padding:22px 24px}
.np-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:20px}
.np-summary-card{border:1px solid #deebf3;border-radius:8px;background:#fff;padding:16px;display:flex;align-items:center;gap:12px}
.np-icon{width:44px;height:44px;border-radius:50%;background:#edf7ff;color:#0077b6;display:grid;place-items:center;flex:0 0 auto}
.np-icon.green{background:#e8f8ef;color:#178c51}.np-icon.violet{background:#f1edff;color:#6b4be8}
.np-summary-card span{display:block;color:#657b8a;font-weight:700}.np-summary-card strong{display:block;color:#09233f;margin-top:3px;font-size:1.08rem}
.np-details-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:18px}
.np-panel{border:1px solid #deebf3;border-radius:8px;background:#fff;overflow:hidden}
.np-panel-head{padding:16px 18px;border-bottom:1px solid #deebf3}
.np-panel-head h3{margin:0;color:#09233f}.np-panel-head p{margin:4px 0 0;color:#607889}
.np-mini-table{width:100%;border-collapse:collapse}.np-mini-table th,.np-mini-table td{padding:13px 16px;border-bottom:1px solid #edf3f7;text-align:left;vertical-align:top}.np-mini-table th{color:#5d7180;font-size:.84rem}
.status-badge{display:inline-flex;border-radius:999px;padding:7px 10px;font-weight:900;font-size:.82rem}
.status-badge.pending{background:#fff3d6;color:#a36600}.status-badge.confirmed{background:#e3f1ff;color:#075fa7}.status-badge.completed{background:#e6f7ed;color:#08723d}.status-badge.cancelled{background:#ffe9ec;color:#bc2633}
.np-note-list{display:grid;gap:10px;padding:16px}
.np-note-card{border:1px solid #e4edf3;border-radius:8px;background:#fbfdff;padding:14px}
.np-note-card strong{display:block;color:#09233f}.np-note-card small{display:block;color:#718696;margin:3px 0 10px}
.np-note-line{display:grid;grid-template-columns:115px minmax(0,1fr);gap:12px;margin:6px 0;color:#213b4b}.np-note-line b{color:#607889}
.np-note-empty{padding:16px;color:#607889}
.np-note-form{padding:16px;border-top:1px solid #deebf3;background:#fbfdff}
.np-note-form h4{margin:0 0 12px;color:#09233f}
.np-note-form .clinical-form-grid{margin-bottom:12px}
@media(max-width:920px){.np-summary,.np-details-grid{grid-template-columns:1fr}}
@media(max-width:820px){
  .np-title{display:block}
  .np-toolbar,.np-search{flex-direction:column}
  .np-table,.np-table thead,.np-table tbody,.np-table tr,.np-table th,.np-table td{display:block}
  .np-table thead{display:none}
  .np-table tr{padding:16px 18px;border-bottom:1px solid #deebf3}
  .np-table td{padding:7px 0;border:0}
  .np-table td::before{content:attr(data-label);display:block;color:#607889;font-size:.78rem;font-weight:800;margin-bottom:4px}
  .np-foot{flex-direction:column;align-items:flex-start}
}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  }

  function openPatientModal(patientId) {
    var modal = document.getElementById("patient-modal-" + patientId);
    if (!modal) return;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    var closeButton = modal.querySelector("[data-close-patient-modal]");
    if (closeButton) closeButton.focus();
  }

  document.querySelectorAll("[data-open-patient-modal]").forEach(function (button) {
    button.addEventListener("click", function () {
      openPatientModal(button.getAttribute("data-open-patient-modal"));
    });
  });

  document.querySelectorAll("[data-close-patient-modal]").forEach(function (button) {
    button.addEventListener("click", function () {
      closeModal(button.closest(".np-modal"));
    });
  });

  document.querySelectorAll(".np-modal").forEach(function (modal) {
    modal.addEventListener("click", function (event) {
      if (event.target === modal) closeModal(modal);
    });
  });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      document.querySelectorAll(".np-modal.is-open").forEach(closeModal);
    }
  });

  var selectedPatientId = "' . (int) $openPatientId . '";
  if (selectedPatientId !== "0") {
    openPatientModal(selectedPatientId);
  }
});
';

include 'includes/header.php';
?>
<div class="np-wrap">
  <div class="np-title">
    <div>
      <h1>Patient List</h1>
      <p>Consultation patients assigned to your doctor account.</p>
    </div>
  </div>

  <?php if ($message !== ''): ?><div class="np-alert ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="np-alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <section class="np-card">
    <div class="np-card-head">
      <div class="np-card-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <div>
        <h2>Consultation Patients</h2>
        <p>Click View details to open appointment history and medical notes.</p>
      </div>
    </div>

    <div class="np-toolbar">
      <form class="np-search" method="get">
        <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search patient name, username, or email">
        <button class="np-btn" type="submit">Search</button>
        <?php if ($q !== ''): ?><a class="np-btn secondary" href="nurse_patients.php">Reset</a><?php endif; ?>
      </form>
    </div>

    <?php if (empty($patients)): ?>
      <div class="np-empty">No consultation patients found.</div>
    <?php else: ?>
      <table class="np-table">
        <thead>
          <tr>
            <th style="width:36%">Patient</th>
            <th style="width:18%">Appointments</th>
            <th style="width:26%">Next Schedule</th>
            <th style="width:20%">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($patients as $patient): ?>
            <tr>
              <td data-label="Patient">
                <div class="np-patient">
                  <?php echo renderPatientAvatar($patient, ['size' => 'md']); ?>
                  <div class="np-patient-text">
                    <strong><?php echo htmlspecialchars((string) $patient['full_name']); ?></strong>
                    <span><?php echo htmlspecialchars((string) $patient['username']); ?><?php echo !empty($patient['email']) ? ' - ' . htmlspecialchars((string) $patient['email']) : ''; ?></span>
                  </div>
                </div>
              </td>
              <td data-label="Appointments">
                <span class="np-pill"><?php echo (int) $patient['consultation_count']; ?> consultation<?php echo (int) $patient['consultation_count'] === 1 ? '' : 's'; ?></span>
              </td>
              <td data-label="Next Schedule">
                <div class="np-next">
                  <strong><?php echo htmlspecialchars(np_date_label($patient['next_date'] ?? null)); ?></strong>
                  <?php if (!empty($patient['next_time'])): ?><span><?php echo htmlspecialchars(np_time_label($patient['next_time'])); ?></span><?php endif; ?>
                </div>
              </td>
              <td data-label="Action">
                <button class="np-action" type="button" data-open-patient-modal="<?php echo (int) $patient['id']; ?>">View details</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div class="np-foot">
      <div class="np-count">Showing <?php echo $showingStart; ?> to <?php echo $showingEnd; ?> of <?php echo $totalPatients; ?> patients</div>
      <div class="np-pages" aria-label="Pagination">
        <a class="np-page <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(np_page_url(max(1, $page - 1), $q)); ?>">&lsaquo;</a>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <a class="np-page <?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(np_page_url($i, $q)); ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <a class="np-page <?php echo $page >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(np_page_url(min($totalPages, $page + 1), $q)); ?>">&rsaquo;</a>
      </div>
    </div>
  </section>

  <?php foreach ($patients as $patient): ?>
    <?php
      $patientId = (int) $patient['id'];
      $patientAppointments = $appointmentsByPatient[$patientId] ?? [];
      $patientMedical = $medicalByPatient[$patientId] ?? [];
      $latestVisit = np_date_label($patient['latest_visit'] ?? null);
    ?>
    <section class="np-modal" id="patient-modal-<?php echo $patientId; ?>" aria-hidden="true">
      <div class="np-modal-card" role="dialog" aria-modal="true" aria-labelledby="patient-title-<?php echo $patientId; ?>">
        <div class="np-modal-head">
          <div class="np-modal-profile">
            <?php echo renderPatientAvatar($patient, ['size' => 'lg']); ?>
            <div>
              <h2 id="patient-title-<?php echo $patientId; ?>"><?php echo htmlspecialchars((string) $patient['full_name']); ?></h2>
              <p><?php echo htmlspecialchars((string) $patient['username']); ?><?php echo !empty($patient['email']) ? ' - ' . htmlspecialchars((string) $patient['email']) : ''; ?></p>
            </div>
          </div>
          <button class="np-close" type="button" data-close-patient-modal aria-label="Close">&times;</button>
        </div>
        <div class="np-modal-body">
          <div class="np-summary">
            <div class="np-summary-card">
              <span class="np-icon green" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg></span>
              <div><span>Status</span><strong>Active Patient</strong></div>
            </div>
            <div class="np-summary-card">
              <span class="np-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v3M16 3v3M5 8h14M6 5h12a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/></svg></span>
              <div><span>Total Appointments</span><strong><?php echo (int) $patient['consultation_count']; ?></strong></div>
            </div>
            <div class="np-summary-card">
              <span class="np-icon violet" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 7v5l3 2"/><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z"/></svg></span>
              <div><span>Latest Visit</span><strong><?php echo htmlspecialchars($latestVisit); ?></strong></div>
            </div>
          </div>

          <div class="np-details-grid">
            <section class="np-panel">
              <div class="np-panel-head">
                <h3>Appointment History</h3>
                <p>Doctor consultation appointments only.</p>
              </div>
              <?php if (empty($patientAppointments)): ?>
                <div class="np-note-empty">No appointment history yet.</div>
              <?php else: ?>
                <table class="np-mini-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Time</th>
                      <th>Status</th>
                      <th>Note Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($patientAppointments as $appointment): ?>
                      <tr>
                        <td><?php echo htmlspecialchars(np_date_label($appointment['appointment_date'] ?? null)); ?></td>
                        <td><?php echo htmlspecialchars(np_time_label($appointment['appointment_time'] ?? null)); ?></td>
                        <td><span class="status-badge <?php echo htmlspecialchars(np_status_class($appointment['status'] ?? null)); ?>"><?php echo htmlspecialchars(np_status_label($appointment['status'] ?? null)); ?></span></td>
                        <td><?php echo empty($patientMedical) ? 'No medical note' : 'Medical notes available'; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </section>

            <section class="np-panel">
              <div class="np-panel-head">
                <h3>Medical Notes</h3>
                <p>Temperature, weight, and notes for this patient.</p>
              </div>
              <?php if (empty($patientMedical)): ?>
                <div class="np-note-empty">No medical notes yet.</div>
              <?php else: ?>
                <div class="np-note-list">
                  <?php foreach ($patientMedical as $record): ?>
                    <article class="np-note-card">
                      <strong><?php echo htmlspecialchars((string) ($record['title'] ?? 'Medical note')); ?></strong>
                      <small><?php echo htmlspecialchars((string) ($record['author_name'] ?? 'Doctor')); ?> - <?php echo htmlspecialchars(np_date_label($record['created_at'] ?? null)); ?></small>
                      <div class="np-note-line"><b>Temperature</b><span><?php echo htmlspecialchars(trim((string) ($record['temperature'] ?? '')) !== '' ? (string) $record['temperature'] : 'Not recorded'); ?></span></div>
                      <div class="np-note-line"><b>Weight</b><span><?php echo htmlspecialchars(trim((string) ($record['weight'] ?? '')) !== '' ? (string) $record['weight'] : 'Not recorded'); ?></span></div>
                      <div class="np-note-line"><b>Notes</b><span><?php echo nl2br(htmlspecialchars(trim((string) ($record['notes'] ?? $record['content'] ?? '')) !== '' ? (string) ($record['notes'] ?? $record['content'] ?? '') : 'Not recorded')); ?></span></div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <form class="np-note-form" method="post">
                <h4>Add Medical Note</h4>
                <input type="hidden" name="nurse_patient_action" value="add_medical">
                <input type="hidden" name="patient_id" value="<?php echo $patientId; ?>">
                <input type="hidden" name="selected_patient_id" value="<?php echo $patientId; ?>">
                <input type="hidden" name="return_page" value="<?php echo $page; ?>">
                <input type="hidden" name="return_q" value="<?php echo htmlspecialchars($q); ?>">
                <?php require __DIR__ . '/includes/nurse_medical_form.inc.php'; ?>
                <button class="np-btn" type="submit">Save medical note</button>
              </form>
            </section>
          </div>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php include 'includes/footer.php'; ?>

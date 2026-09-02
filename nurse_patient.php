<?php
require_once 'includes/session.php';
checkRole('doctor');
require_once 'config/database.php';
require_once __DIR__ . '/includes/nurse_clinical.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/nurse_clinical_styles.php';

$currentUser = getCurrentUser();
$role = (string) ($currentUser['role'] ?? '');
$isDoctor = $role === 'doctor';
$staffId = (int) ($currentUser['id'] ?? 0);
$patientId = (int) ($_GET['id'] ?? 0);
if ($patientId <= 0) {
    header('Location: nurse_patients.php');
    exit;
}

$conn = getDBConnection();
$message = '';
$error = '';

function nurse_patient_doctor_can_access_patient(mysqli $conn, int $doctorId, int $patientId): bool {
    $stmt = $conn->prepare('SELECT 1 FROM appointments WHERE doctor_id = ? AND patient_id = ? LIMIT 1');
    $stmt->bind_param('ii', $doctorId, $patientId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['nurse_patient_action'] ?? '';
    $pid = (int) ($_POST['patient_id'] ?? 0);
    if ($pid !== $patientId) {
        $error = 'Invalid request.';
    } elseif ($isDoctor && !nurse_patient_doctor_can_access_patient($conn, $staffId, $pid)) {
        $error = 'You can only update patients assigned to your appointments.';
    } elseif ($act === 'add_medical') {
        $result = nurse_clinical_save_medical($conn, $pid, (int) $currentUser['id'], nurse_medical_fields_from_post($_POST));
        if ($result['ok']) {
            $message = $result['message'];
        } else {
            $error = $result['error'];
        }
    }
}

$st = $conn->prepare("SELECT id, full_name, username, email, phone, profile_photo, profile_updated_at FROM users WHERE id = ? AND role='patient'");
$st->bind_param('i', $patientId);
$st->execute();
$patient = $st->get_result()->fetch_assoc();
$st->close();
if (!$patient) {
    $conn->close();
    header('Location: nurse_patients.php');
    exit;
}
if ($isDoctor && !nurse_patient_doctor_can_access_patient($conn, $staffId, $patientId)) {
    $conn->close();
    header('Location: nurse_patients.php');
    exit;
}

if ($isDoctor) {
    $ap = $conn->prepare("SELECT id, appointment_date, appointment_time, status, booking_type FROM appointments WHERE patient_id = ? AND doctor_id = ? ORDER BY appointment_date DESC, appointment_time DESC LIMIT 80");
    $ap->bind_param('ii', $patientId, $staffId);
} else {
    $ap = $conn->prepare("SELECT id, appointment_date, appointment_time, status, booking_type FROM appointments WHERE patient_id = ? ORDER BY appointment_date DESC, appointment_time DESC LIMIT 80");
    $ap->bind_param('i', $patientId);
}
$ap->execute();
$appointments = $ap->get_result()->fetch_all(MYSQLI_ASSOC);
$ap->close();

function nurse_patient_booking_label(?string $type): string {
    return [
        'consultation' => 'Doctor consultation',
        'package' => 'Laboratory package',
        'individual' => 'Laboratory tests',
        'ultrasound' => 'Ultra sound',
    ][(string) $type] ?? (string) ($type ?? '');
}

$mr = $conn->prepare("SELECT m.*, u.full_name AS author_name FROM medical_records m LEFT JOIN users u ON u.id = m.author_id WHERE patient_id = ? ORDER BY created_at DESC");
$mr->bind_param('i', $patientId);
$mr->execute();
$medicalRows = $mr->get_result()->fetch_all(MYSQLI_ASSOC);
$mr->close();

$conn->close();

$pageTitle = 'Patient records | Doctor';
$clinicalForm = [];
$additionalStyles = patientAvatarStyles() . nurse_clinical_styles() . '
.pv-wrap{max-width:1150px;margin:28px auto;padding:0 20px 40px}
.pv-head{background:linear-gradient(135deg,#0077b6,#023e8a);color:#fff;border-radius:16px;padding:20px 24px;margin-bottom:18px}
.pv-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}@media(max-width:950px){.pv-grid{grid-template-columns:1fr}}
.pv-card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 4px 18px rgba(0,0,0,.06)}
.pv-card h2{margin:0 0 12px;color:#0077b6;font-size:1.15rem}
.pv-t{width:100%;border-collapse:collapse}.pv-t th,.pv-t td{padding:8px 6px;border-bottom:1px solid #eee;text-align:left}
.pv-t th{background:#f8f9fa;color:#023e8a}
input,textarea,select{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;box-sizing:border-box;margin-bottom:10px}
textarea{min-height:90px}.btn{background:#0077b6;color:#fff;border:none;padding:11px 16px;border-radius:8px;font-weight:700;cursor:pointer}
.ok{background:#d4edda;color:#155724;padding:10px;border-radius:8px;margin-bottom:10px}.er{background:#f8d7da;color:#721c24;padding:10px;border-radius:8px;margin-bottom:10px}
.ref{max-height:260px;overflow:auto;border:1px solid #e8e8e8;border-radius:10px;padding:8px 10px;background:#fafafa}
.cat{font-weight:800;color:#0077b6;margin:10px 0 6px}
.pv-actions-panel{background:#fff;border-radius:14px;padding:18px;box-shadow:0 4px 18px rgba(0,0,0,.06);display:grid;gap:12px}
.pv-actions-panel h2{margin:0;color:#023e8a;font-size:1.12rem}
.pv-actions{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.pv-action{min-height:48px;border:1px solid #d4e6f5;border-radius:10px;background:#f8fbff;color:#0b4f80;font-weight:800;cursor:pointer;padding:10px;text-align:center}
.pv-action.primary{background:#0077b6;color:#fff;border-color:#0077b6}
.pv-action.add{background:#e7f7ed;color:#17643a;border-color:#bfe6ce}
.pv-action:hover{transform:translateY(-1px)}
.pv-modal{position:fixed;inset:0;z-index:3000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(3,18,30,.55)}
.pv-modal.is-open{display:flex}
.pv-modal-card{width:min(980px,100%);max-height:88vh;overflow:auto;background:#fff;border-radius:14px;box-shadow:0 18px 55px rgba(0,0,0,.24)}
.pv-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:18px 20px;background:#f1f8fd;border-bottom:1px solid #dce9f4}
.pv-modal-head h2{margin:0;color:#023e8a;font-size:1.16rem}
.pv-modal-close{border:none;background:#fff;color:#023e8a;border-radius:8px;width:38px;height:38px;font-size:1.35rem;line-height:1;cursor:pointer}
.pv-modal-body{padding:20px}
.pv-record{border-bottom:1px solid #eee;padding-bottom:10px;margin-bottom:10px}
@media(max-width:900px){.pv-actions{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.pv-actions{grid-template-columns:1fr}}
';
$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  }
  function openModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    var closeBtn = modal.querySelector("[data-close-modal]");
    if (closeBtn) closeBtn.focus();
  }

  document.querySelectorAll("[data-open-modal]").forEach(function (button) {
    button.addEventListener("click", function () {
      openModal(button.getAttribute("data-open-modal"));
    });
  });
  document.querySelectorAll("[data-close-modal]").forEach(function (button) {
    button.addEventListener("click", function () {
      closeModal(button.closest(".pv-modal"));
    });
  });
  document.querySelectorAll(".pv-modal").forEach(function (modal) {
    modal.addEventListener("click", function (event) {
      if (event.target === modal) closeModal(modal);
    });
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      document.querySelectorAll(".pv-modal.is-open").forEach(closeModal);
    }
  });

  var hashMap = {
    "#appointments": "appointments",
    "#medical-records": "medical-records",
    "#add-medical-record": "add-medical-record"
  };
  if (hashMap[window.location.hash]) {
    openModal(hashMap[window.location.hash]);
  }
});
';
include 'includes/header.php';
?>
<div class="pv-wrap">
  <a href="nurse_patients.php" style="display:inline-block;margin-bottom:10px;color:#0077b6;font-weight:700;text-decoration:none;">← Back to patient list</a>
  <?php if ($message): ?><div class="ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="er"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <div class="pv-head">
    <div class="pv-head-profile">
      <?php echo renderPatientAvatar($patient, ['size' => 'xl']); ?>
      <div>
        <h1 style="margin:0 0 6px"><?php echo htmlspecialchars($patient['full_name']); ?></h1>
        <p style="margin:0;opacity:.95"><?php echo htmlspecialchars($patient['username']); ?><?php echo !empty($patient['phone']) ? ' · ' . htmlspecialchars($patient['phone']) : ''; ?><?php echo !empty($patient['email']) ? ' · ' . htmlspecialchars($patient['email']) : ''; ?></p>
      </div>
    </div>
  </div>
  <div class="pv-actions-panel">
    <h2>Patient actions</h2>
    <div class="pv-actions">
      <button class="pv-action primary" type="button" data-open-modal="appointments">Appointment history</button>
      <button class="pv-action" type="button" data-open-modal="medical-records">Medical notes</button>
      <button class="pv-action add" type="button" data-open-modal="add-medical-record">Add medical note</button>
    </div>
  </div>

  <section class="pv-modal" id="appointments" aria-hidden="true">
    <div class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="appointmentsTitle">
      <div class="pv-modal-head">
        <h2 id="appointmentsTitle">Appointment history</h2>
        <button class="pv-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
      </div>
      <div class="pv-modal-body">
        <?php if (empty($appointments)): ?><p style="color:#666">No appointments.</p><?php else: ?>
          <table class="pv-t"><thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($appointments as $a): ?><tr><td><?php echo htmlspecialchars($a['appointment_date']); ?></td><td><?php echo htmlspecialchars(substr((string)$a['appointment_time'],0,5)); ?></td><td><?php echo htmlspecialchars(nurse_patient_booking_label($a['booking_type'] ?? null)); ?></td><td><?php echo htmlspecialchars($a['status']); ?></td></tr><?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="pv-modal" id="medical-records" aria-hidden="true">
    <div class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="medicalRecordsTitle">
      <div class="pv-modal-head">
        <h2 id="medicalRecordsTitle">Medical notes</h2>
        <button class="pv-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
      </div>
      <div class="pv-modal-body">
        <?php foreach ($medicalRows as $r): ?>
          <div class="pv-record">
            <strong><?php echo htmlspecialchars(trim((string) ($r['diagnosis'] ?? '')) !== '' ? $r['diagnosis'] : $r['title']); ?></strong>
            <small style="color:#666"> - <?php echo htmlspecialchars($r['author_name'] ?? ''); ?> - <?php echo htmlspecialchars($r['created_at']); ?></small>
            <?php nurse_medical_render_sections($r); ?>
            <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
              <a class="btn" style="padding:8px 12px;font-size:0.85rem;background:#eef7ff;color:#0b4f80" href="nurse_export.php?type=medical&amp;id=<?php echo (int)$r['id']; ?>&amp;action=print" target="_blank" rel="noopener">Print</a>
              <a class="btn" style="padding:8px 12px;font-size:0.85rem;background:#fff;color:#0b4f80;border:1px solid #d4e6f5" href="nurse_export.php?type=medical&amp;id=<?php echo (int)$r['id']; ?>&amp;action=download">Download</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($medicalRows)): ?><p style="color:#666">No medical notes yet.</p><?php endif; ?>
      </div>
    </div>
  </section>

  <section class="pv-modal" id="add-medical-record" aria-hidden="true">
    <div class="pv-modal-card" role="dialog" aria-modal="true" aria-labelledby="addMedicalTitle">
      <div class="pv-modal-head">
        <h2 id="addMedicalTitle">Add medical note</h2>
        <button class="pv-modal-close" type="button" data-close-modal aria-label="Close">&times;</button>
      </div>
      <div class="pv-modal-body">
        <form method="post">
          <input type="hidden" name="nurse_patient_action" value="add_medical">
          <input type="hidden" name="patient_id" value="<?php echo $patientId; ?>">
          <?php require __DIR__ . '/includes/nurse_medical_form.inc.php'; ?>
          <button class="btn" type="submit">Save medical note</button>
        </form>
      </div>
    </div>
  </section>

</div>
<?php include 'includes/footer.php'; ?>

<?php
require_once 'includes/session.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

$currentUser = getCurrentUser();
$userRole = $currentUser['role'];
$isClinicalAppointmentsView = $userRole === 'doctor';

// Determine page title based on user role
$pageTitle = "Appointments | Globalife Medical Laboratory & Polyclinic";

$conn = getDBConnection();

// Get appointments based on user role
if ($userRole === 'patient') {
    checkRole('patient');
    $stmt = $conn->prepare("SELECT a.*, u.full_name as doctor_name 
                            FROM appointments a 
                            LEFT JOIN users u ON a.doctor_id = u.id 
                            WHERE a.patient_id = ? 
                            ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->bind_param("i", $currentUser['id']);
} elseif ($userRole === 'admin') {
    checkRole($userRole);
    $stmt = $conn->prepare("SELECT a.*, 
                            p.full_name as patient_name,
                            p.profile_photo,
                            p.profile_updated_at,
                            d.full_name as doctor_name 
                            FROM appointments a 
                            JOIN users p ON a.patient_id = p.id 
                            LEFT JOIN users d ON a.doctor_id = d.id 
                            ORDER BY a.appointment_date DESC, a.appointment_time DESC");
} elseif ($userRole === 'doctor') {
    checkRole('doctor');
    $stmt = $conn->prepare("SELECT a.*, 
                            p.full_name as patient_name,
                            p.profile_photo,
                            p.profile_updated_at,
                            d.full_name as doctor_name 
                            FROM appointments a 
                            JOIN users p ON a.patient_id = p.id 
                            LEFT JOIN users d ON a.doctor_id = d.id 
                            WHERE a.doctor_id = ?
                              AND a.booking_type = 'consultation'
                            ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->bind_param('i', $currentUser['id']);
} else {
    header('Location: index.php');
    exit();
}

$stmt->execute();
$appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statusCounts = [
    'all' => count($appointments),
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
];
$todayCount = 0;
$upcomingCount = 0;
$needsAttentionCount = 0;
$now = new DateTime();
$todayYmd = $now->format('Y-m-d');

foreach ($appointments as $appointment) {
    $status = strtolower((string) ($appointment['status'] ?? 'pending'));
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }

    $date = (string) ($appointment['appointment_date'] ?? '');
    $time = (string) ($appointment['appointment_time'] ?? '00:00:00');
    if ($date === $todayYmd) {
        $todayCount++;
    }
    if ($date !== '') {
        try {
            $appointmentDateTime = new DateTime($date . ' ' . $time);
            if ($appointmentDateTime >= $now && $status !== 'cancelled' && $status !== 'completed') {
                $upcomingCount++;
            }
            if ($appointmentDateTime < $now && ($status === 'pending' || $status === 'confirmed')) {
                $needsAttentionCount++;
            }
        } catch (Exception $e) {
            // Ignore malformed appointment dates so the page can still load.
        }
    }
}

$patientsDirectory = [];
$patientsById = [];
$staffProfileLinkTarget = 'staff';
if ($userRole === 'doctor') {
    $staffProfileLinkTarget = 'doctor';
}
if ($userRole !== 'patient' && !$isClinicalAppointmentsView) {
    $patientsDirectory = fetchPatientsForStaffDirectory($conn);
    foreach ($patientsDirectory as $patientRow) {
        $patientsById[(int) $patientRow['id']] = $patientRow;
    }
}

if ($userRole === 'patient') {
    $stmt = $conn->prepare("SELECT full_name, profile_photo, profile_updated_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $patientHeaderDetails = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $headerPatientPhotoUrl = patientProfilePhotoUrl($patientHeaderDetails['profile_photo'] ?? null, $patientHeaderDetails['profile_updated_at'] ?? null);
    $headerPatientInitials = patientProfileInitials($patientHeaderDetails['full_name'] ?? $currentUser['full_name']);
    $headerPatientDisplayName = $patientHeaderDetails['full_name'] ?? $currentUser['full_name'];
}

$conn->close();

$additionalStyles = patientAvatarStyles() . '
    .patient-cell{display:flex;align-items:center;gap:10px}
    .patients-panel{background:#fff;border-radius:20px;padding:24px 28px;margin-bottom:28px;box-shadow:0 5px 25px rgba(0,0,0,.08)}
    .patients-panel h3{margin:0 0 6px;color:#0077b6;font-size:1.35rem}
    .patients-panel>p{margin:0 0 16px;color:#666;font-size:.95rem}
    .patients-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px}
    .patients-toolbar input{flex:1;min-width:200px;padding:10px 14px;border:2px solid #e0e0e0;border-radius:10px;font:inherit}
    .patients-toolbar span{color:#555;font-size:.9rem;font-weight:600}
    .patients-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;max-height:260px;overflow:auto;padding-right:8px;scrollbar-width:thin;scrollbar-color:#9fc5d8 #edf5f8}
    .patients-grid::-webkit-scrollbar,.appointments-scroll::-webkit-scrollbar{width:9px;height:9px}
    .patients-grid::-webkit-scrollbar-thumb,.appointments-scroll::-webkit-scrollbar-thumb{background:#9fc5d8;border-radius:999px}
    .patients-grid::-webkit-scrollbar-track,.appointments-scroll::-webkit-scrollbar-track{background:#edf5f8;border-radius:999px}
    .patient-card-item{display:flex;align-items:center;gap:12px;border:1px solid #e6eef5;border-radius:12px;padding:12px 14px;background:#f8fbff;text-decoration:none;color:inherit;transition:border-color .2s,box-shadow .2s}
    .patient-card-item:hover{border-color:#48cae4;box-shadow:0 4px 14px rgba(0,119,182,.12)}
    .patient-card-meta{display:flex;flex-direction:column;gap:2px;min-width:0}
    .patient-card-meta strong{color:#023e8a;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .patient-card-meta span{color:#666;font-size:.82rem}
    .patient-card-view{font-size:.8rem;font-weight:700;color:#0077b6;margin-top:4px}
    .patient-card-item.hidden{display:none}
    body {
        background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%);
        min-height: 100vh;
    }
    .appointments-container {
        max-width: 1200px;
        margin: 40px auto;
        padding: 40px 20px;
    }
    .page-header {
        background: #073b4c;
        border-radius: 8px;
        padding: 40px;
        margin-bottom: 40px;
        color: #fff;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.18);
    }
    .page-header.patient-page-header {
        background: #073b4c;
        border-radius: 8px;
        box-shadow: 0 14px 34px rgba(7, 59, 76, 0.18);
    }
    .page-header h2 {
        margin: 0 0 10px 0;
        font-size: 2.5rem;
        font-weight: 700;
    }
    .page-header p {
        margin: 0;
        font-size: 1.1rem;
        opacity: 0.95;
    }
    .appointment-actions-top {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 22px;
    }
    .appointment-actions-top a {
        color: #fff;
        border: 1px solid rgba(255,255,255,.55);
        border-radius: 10px;
        padding: 10px 16px;
        text-decoration: none;
        font-weight: 700;
        background: rgba(255,255,255,.16);
    }
    .appointment-actions-top a:hover {
        background: rgba(255,255,255,.28);
    }
    .appointment-stats {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 28px;
    }
    .stat-card {
        background: #fff;
        border: 1px solid #dceef2;
        border-radius: 14px;
        padding: 18px;
        box-shadow: 0 5px 22px rgba(0,0,0,.06);
    }
    .stat-card span {
        display: block;
        color: #60758a;
        font-size: .82rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 8px;
    }
    .stat-card strong {
        color: #023e8a;
        font-size: 1.9rem;
        line-height: 1;
    }
    .stat-card p {
        margin: 8px 0 0;
        color: #5d6d76;
        font-size: .88rem;
        line-height: 1.45;
    }
    .appointment-tools {
        display: grid;
        grid-template-columns: minmax(240px, 1fr) 180px 180px auto;
        gap: 12px;
        align-items: end;
        margin-bottom: 18px;
    }
    .appointment-tools.patient-filters {
        grid-template-columns: 180px 180px auto;
    }
    .tool-field label {
        display: block;
        color: #365264;
        font-size: .78rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 6px;
    }
    .tool-field input,
    .tool-field select {
        width: 100%;
        box-sizing: border-box;
        border: 2px solid #e0eaf0;
        border-radius: 10px;
        min-height: 42px;
        padding: 9px 12px;
        font: inherit;
        background: #fff;
    }
    .tool-field input:focus,
    .tool-field select:focus {
        outline: none;
        border-color: #48cae4;
        box-shadow: 0 0 0 4px rgba(72,202,228,.12);
    }
    .filter-reset-btn {
        min-height: 42px;
        border: 1px solid #cfe4e9;
        border-radius: 10px;
        background: #eef7fb;
        color: #023e8a;
        font-weight: 800;
        cursor: pointer;
        padding: 0 16px;
    }
    .filter-reset-btn:hover {
        background: #dff0f7;
    }
    .appointment-result-count {
        margin: 0 0 16px;
        color: #5d6d76;
        font-weight: 700;
    }
    .filter-empty {
        display: none;
        text-align: center;
        padding: 28px 16px;
        color: #60758a;
        background: #f8fcfd;
        border: 1px dashed #b8dfe8;
        border-radius: 12px;
        margin-top: 16px;
    }
    .filter-empty strong {
        display: block;
        color: #073b4c;
        margin-bottom: 6px;
    }
    .appointments-table-wrapper {
        background: #fff;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }
    .appointments-scroll {
        max-height: 620px;
        overflow: auto;
        padding-right: 8px;
        scrollbar-width: thin;
        scrollbar-color: #9fc5d8 #edf5f8;
    }
    .appointments-table {
        width: 100%;
        min-width: 940px;
        border-collapse: collapse;
    }
    .appointments-table th {
        position: sticky;
        top: 0;
        z-index: 3;
        background: #f8f9fa;
        color: #0077b6;
        padding: 15px;
        text-align: left;
        font-weight: 700;
        border-bottom: 2px solid #e0e0e0;
    }
    .appointments-table td {
        padding: 15px;
        border-bottom: 1px solid #e0e0e0;
    }
    .appointments-table tr:hover {
        background: #f8f9fa;
    }
    .appointments-table tbody tr:last-child td {
        border-bottom: 0;
    }
    .appointment-row.hidden,
    .appointment-row.page-hidden {
        display: none;
    }
    .status-badge {
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
        display: inline-block;
    }
    .status-badge.pending {
        background: #fff3cd;
        color: #856404;
    }
    .status-badge.confirmed {
        background: #d4edda;
        color: #155724;
    }
    .status-badge.completed {
        background: #d1ecf1;
        color: #0c5460;
    }
    .status-badge.cancelled {
        background: #f8d7da;
        color: #721c24;
    }
    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .action-buttons.pending-actions {
        display: grid;
        grid-template-columns: repeat(2, max-content);
        align-items: start;
    }
    .action-buttons.pending-actions .decline-action {
        grid-column: 2;
    }
    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }
    .btn-confirm {
        background: #28a745;
        color: #fff;
    }
    .btn-confirm:hover {
        background: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(40, 167, 69, 0.3);
    }
    .btn-cancel {
        background: #dc3545;
        color: #fff;
    }
    .btn-cancel:hover {
        background: #c82333;
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
    }
    .btn-complete {
        background: #17a2b8;
        color: #fff;
    }
    .btn-complete:hover {
        background: #138496;
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(23, 162, 184, 0.3);
    }
    .btn-view {
        background: #0077b6;
        color: #fff;
    }
    .btn-view:hover {
        background: #005f8d;
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0, 119, 182, 0.3);
    }
    .appointment-row { cursor: pointer; }
    .appointment-row:focus { outline: 3px solid rgba(0,119,182,.25); outline-offset: -3px; }
    .appointment-row.is-highlighted { outline: 3px solid rgba(72, 202, 228, .45); outline-offset: -3px; background: #f0fbff; }
    .appointment-modal { position: fixed; inset: 0; z-index: 3000; display: none; align-items: center; justify-content: center; padding: 18px; background: rgba(3, 18, 30, .50); backdrop-filter: blur(4px); }
    .appointment-modal.is-open { display: flex; }
    .appointment-modal-card { width: min(720px, 100%); background: #fff; border-radius: 22px; box-shadow: 0 24px 70px rgba(2,62,138,.20); overflow: hidden; border: 1px solid #cde8f3; }
    .appointment-modal-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; padding: 24px 26px; background: radial-gradient(circle at 90% 12%, rgba(72,202,228,.24), transparent 34%), linear-gradient(135deg, #ffffff 0%, #eefaff 100%); border-bottom: 1px solid #d8eef7; color: #10233f; }
    .appointment-modal-head h3 { margin: 0; color: #10233f; font-size: 1.28rem; line-height: 1.2; }
    .appointment-modal-head p { display: inline-flex; align-items: center; margin: 8px 0 0; padding: 7px 11px; border-radius: 999px; background: #eaf8fc; color: #0077b6; font-size: .86rem; font-weight: 850; }
    .modal-close { border: 0; background: #eaf8fc; color: #0077b6; width: 40px; height: 40px; border-radius: 13px; cursor: pointer; font-size: 1.35rem; line-height: 1; font-weight: 900; }
    .modal-close:hover { background: #d8f2fb; }
    .appointment-modal-body { padding: 22px 24px; }
    .detail-summary-strip { display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 10px; margin-bottom: 18px; }
    .detail-pill { background:#f8fdff; border:1px solid #d8eef7; border-radius:14px; padding:14px; }
    .detail-pill span { display:block; color:#60758a; font-size:.75rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
    .detail-pill strong { color:#10233f; font-size:1rem; overflow-wrap:anywhere; }
    .appointment-detail-grid { display: grid; grid-template-columns: 150px 1fr; gap: 12px 16px; padding: 16px; border: 1px solid #d8eef7; border-radius: 16px; background: #ffffff; }
    .appointment-detail-grid dt { color: #60758a; font-weight: 850; }
    .appointment-detail-grid dd { margin: 0; color: #315c70; overflow-wrap: anywhere; line-height: 1.45; }
    .detail-notes-box { margin-top: 16px; padding: 16px; border-radius: 16px; background:#f8fdff; border:1px solid #d8eef7; color:#315c70; line-height:1.55; }
    .detail-notes-box strong { display:block; color:#10233f; margin-bottom:6px; }
    .appointment-modal-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 0 24px 24px; }
    .btn-light { background: #eef5fb; color: #023e8a; }
    .btn-light:hover { background: #dcecf8; }
    .clinical-appointments-page {
        max-width: 1280px;
        margin-top: 28px;
    }
    .clinical-page-title {
        margin: 0 0 24px;
    }
    .clinical-page-title h1 {
        margin: 0 0 8px;
        color: #061a40;
        font-size: 2rem;
        line-height: 1.15;
    }
    .clinical-page-title p {
        margin: 0;
        color: #60758a;
        font-size: 1rem;
    }
    .clinical-stat-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin-bottom: 22px;
    }
    .clinical-stat-card {
        display: grid;
        grid-template-columns: 58px minmax(0, 1fr);
        gap: 18px;
        align-items: center;
        min-height: 110px;
        padding: 22px;
        border: 1px solid #d8e6ed;
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    }
    .clinical-stat-icon {
        width: 58px;
        height: 58px;
        border-radius: 50%;
        display: grid;
        place-items: center;
        background: #edf6ff;
        color: #0f7cc2;
    }
    .clinical-stat-icon.pending {
        background: #fff6df;
        color: #e08300;
    }
    .clinical-stat-icon.done {
        background: #eaf7ef;
        color: #17643a;
    }
    .clinical-stat-icon svg {
        width: 28px;
        height: 28px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    .clinical-stat-card span {
        display: block;
        color: #1f343d;
        font-weight: 900;
    }
    .clinical-stat-card strong {
        display: block;
        margin-top: 5px;
        color: #0066cc;
        font-size: 2rem;
        line-height: 1;
    }
    .clinical-stat-card small {
        display: block;
        margin-top: 8px;
        color: #60758a;
    }
    .clinical-appointments-page .appointments-table-wrapper {
        border: 1px solid #d8e6ed;
        border-radius: 8px;
        padding: 22px;
        box-shadow: 0 10px 24px rgba(25, 76, 110, 0.06);
    }
    .clinical-panel-head {
        margin-bottom: 18px;
    }
    .clinical-panel-head h2 {
        margin: 0 0 5px;
        color: #1f343d;
        font-size: 1.24rem;
    }
    .clinical-panel-head p {
        margin: 0;
        color: #60758a;
        font-size: .92rem;
    }
    .clinical-appointments-page .appointment-tools {
        grid-template-columns: minmax(260px, 1fr) minmax(150px, 180px) minmax(150px, 180px) auto;
        align-items: center;
    }
    .clinical-appointments-page .tool-field label {
        display: none;
    }
    .appointment-pagination-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding-top: 18px;
        color: #60758a;
        font-size: .9rem;
    }
    .appointment-pagination {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .appointment-page-btn {
        width: 38px;
        height: 38px;
        border: 1px solid #d8e6ed;
        border-radius: 7px;
        background: #fff;
        color: #0b4f80;
        font: inherit;
        font-weight: 900;
        cursor: pointer;
    }
    .appointment-page-btn.active {
        border-color: #0066cc;
        background: #0066cc;
        color: #fff;
        box-shadow: 0 10px 20px rgba(0, 102, 204, .18);
    }
    .appointment-page-btn:disabled {
        opacity: .45;
        cursor: not-allowed;
    }
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #999;
    }
    .empty-state svg {
        width: 100px;
        height: 100px;
        margin: 0 auto 20px;
        opacity: 0.3;
    }
    .empty-state h3 {
        color: #666;
        margin-bottom: 10px;
    }
    .add-appointment-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        color: #fff;
        padding: 12px 24px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 20px;
        transition: all 0.3s ease;
        box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
    }
    .add-appointment-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
    }
    @media (max-width: 768px) {
        .appointment-stats,
        .clinical-stat-grid,
        .appointment-tools {
            grid-template-columns: 1fr;
        }
        .appointment-pagination-footer {
            align-items: flex-start;
            flex-direction: column;
        }
        .detail-summary-strip {
            grid-template-columns: 1fr;
        }
        .appointment-detail-grid {
            grid-template-columns: 1fr;
        }
        .appointments-table-wrapper {
            padding: 15px;
        }
        .appointments-scroll {
            max-height: 560px;
        }
        .appointments-table {
            font-size: 0.9rem;
            min-width: 860px;
        }
        .appointments-table th,
        .appointments-table td {
            padding: 10px 8px;
        }
        .action-buttons {
            flex-direction: column;
        }
        .btn {
            width: 100%;
            text-align: center;
        }
    }
';

include 'includes/header.php';
?>

<div class="appointments-container<?php echo $isClinicalAppointmentsView ? ' clinical-appointments-page' : ''; ?>">
    <?php if (isset($_SESSION['success'])): ?>
        <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #28a745;">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div style="background: #fee; color: #d90429; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #d90429;">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($isClinicalAppointmentsView): ?>
        <section class="clinical-page-title">
            <h1>Appointments</h1>
            <p>View and manage doctor consultation appointments assigned to you.</p>
        </section>
        <section class="clinical-stat-grid" aria-label="Appointment summary">
            <div class="clinical-stat-card">
                <span class="clinical-stat-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 10h18"/><path d="M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>
                </span>
                <div>
                    <span>Total Appointments</span>
                    <strong><?php echo (int) $statusCounts['all']; ?></strong>
                    <small>Assigned consultations</small>
                </div>
            </div>
            <div class="clinical-stat-card">
                <span class="clinical-stat-icon pending" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                </span>
                <div>
                    <span>Pending</span>
                    <strong><?php echo (int) $statusCounts['pending']; ?></strong>
                    <small>Awaiting confirmation</small>
                </div>
            </div>
            <div class="clinical-stat-card">
                <span class="clinical-stat-icon done" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="m20 6-11 11-5-5"/></svg>
                </span>
                <div>
                    <span>Completed</span>
                    <strong><?php echo (int) $statusCounts['completed']; ?></strong>
                    <small>Successfully completed</small>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($userRole !== 'patient' && !$isClinicalAppointmentsView && !empty($patientsDirectory)): ?>
        <div class="patients-panel">
            <h3>Patients</h3>
            <p>Updated profile names and photos from patient accounts. Click a patient to view full details.</p>
            <div class="patients-toolbar">
                <input type="search" id="patientDirectorySearch" placeholder="Search patient name, username, phone, or email" aria-label="Search patients">
                <span><?php echo count($patientsDirectory); ?> patient<?php echo count($patientsDirectory) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="patients-grid" id="patientsDirectoryGrid">
                <?php foreach ($patientsDirectory as $patientRow): ?>
                    <?php
                    $pid = (int) $patientRow['id'];
                    $profileHref = $staffProfileLinkTarget === 'doctor'
                        ? 'nurse_patient.php?id=' . $pid
                        : 'staff_patient.php?id=' . $pid;
                    $searchText = trim(implode(' ', [
                        $patientRow['full_name'] ?? '',
                        $patientRow['username'] ?? '',
                        $patientRow['phone'] ?? '',
                        $patientRow['email'] ?? '',
                    ]));
                    ?>
                    <a href="<?php echo htmlspecialchars($profileHref); ?>" class="patient-card-item" data-patient-card data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                        <?php echo renderPatientAvatar($patientRow, ['size' => 'md', 'patient_id' => $pid]); ?>
                        <div class="patient-card-meta">
                            <strong><?php echo htmlspecialchars($patientRow['full_name']); ?></strong>
                            <?php if (!empty($patientRow['phone'])): ?>
                                <span><?php echo htmlspecialchars($patientRow['phone']); ?></span>
                            <?php elseif (!empty($patientRow['email'])): ?>
                                <span><?php echo htmlspecialchars($patientRow['email']); ?></span>
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($patientRow['username']); ?></span>
                            <?php endif; ?>
                            <span class="patient-card-view">View profile</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <p id="patientDirectoryEmpty" class="empty-state" style="display:none;padding:24px 12px;">No patients match your search.</p>
        </div>
        <script>
        (function() {
            var search = document.getElementById('patientDirectorySearch');
            var cards = document.querySelectorAll('[data-patient-card]');
            var empty = document.getElementById('patientDirectoryEmpty');
            if (!search || !cards.length) return;
            function filterPatients() {
                var q = search.value.trim().toLowerCase();
                var visible = 0;
                cards.forEach(function(card) {
                    var text = (card.getAttribute('data-search') || '').toLowerCase();
                    var show = q === '' || text.indexOf(q) !== -1;
                    card.classList.toggle('hidden', !show);
                    if (show) visible++;
                });
                if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
            }
            search.addEventListener('input', filterPatients);
        })();
        </script>
    <?php endif; ?>

    <?php if ($userRole === 'patient'): ?>
        <a href="book_appointment.php?start=1" class="add-appointment-btn">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Book New Appointment
        </a>
    <?php endif; ?>
    
    <div class="appointments-table-wrapper">
        <?php if ($isClinicalAppointmentsView): ?>
            <div class="clinical-panel-head">
                <h2>All Appointments</h2>
                <p>Only doctor consultation appointments assigned to your account are shown here.</p>
            </div>
        <?php endif; ?>
        <?php if (empty($appointments)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <h3>No Appointments Found</h3>
                <p><?php echo $isClinicalAppointmentsView ? 'No doctor consultation appointments are assigned to you.' : ($userRole === 'patient' ? 'You don\'t have any appointments yet.' : 'There are no appointments in the system.'); ?></p>
                <?php if ($userRole === 'patient'): ?>
                    <a href="book_appointment.php?start=1" class="add-appointment-btn" style="margin-top: 20px;">Book Your First Appointment</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="appointment-tools<?php echo $userRole === 'patient' ? ' patient-filters' : ''; ?>" aria-label="Appointment filters">
                <?php if ($userRole !== 'patient'): ?>
                    <div class="tool-field">
                        <label for="appointmentSearch">Search appointments</label>
                        <input type="search" id="appointmentSearch" placeholder="Search patient, doctor, reference, notes, or date">
                    </div>
                <?php endif; ?>
                <div class="tool-field">
                    <label for="appointmentStatusFilter">Status</label>
                    <select id="appointmentStatusFilter">
                        <option value="">All statuses</option>
                        <option value="pending">Pending (<?php echo (int) $statusCounts['pending']; ?>)</option>
                        <option value="confirmed">Confirmed (<?php echo (int) $statusCounts['confirmed']; ?>)</option>
                        <option value="completed">Completed (<?php echo (int) $statusCounts['completed']; ?>)</option>
                        <option value="cancelled">Declined (<?php echo (int) $statusCounts['cancelled']; ?>)</option>
                    </select>
                </div>
                <div class="tool-field">
                    <label for="appointmentDateFilter">Date</label>
                    <input type="date" id="appointmentDateFilter">
                </div>
                <button type="button" class="filter-reset-btn" id="appointmentFilterReset">Reset</button>
            </div>
            <p class="appointment-result-count" id="appointmentResultCount"><?php echo count($appointments); ?> appointment<?php echo count($appointments) === 1 ? '' : 's'; ?> shown</p>
            <div class="appointments-scroll" aria-label="Scrollable appointments list">
                <table class="appointments-table">
                    <thead>
                        <tr>
                            <?php if ($userRole !== 'patient'): ?>
                                <th>Patient</th>
                            <?php endif; ?>
                            <?php if ($isClinicalAppointmentsView): ?>
                                <th>Service</th>
                                <th>Date &amp; Time</th>
                            <?php elseif ($userRole === 'admin'): ?>
                                <th>Doctor</th>
                                <th>Date</th>
                            <?php else: ?>
                                <th>Date</th>
                            <?php endif; ?>
                            <th>Status</th>
                            <?php if (!$isClinicalAppointmentsView): ?>
                                <th>Notes</th>
                            <?php endif; ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                        $appDate = new DateTime($appointment['appointment_date']);
                        $formattedDate = $appDate->format('M d, Y');
                        $appTime = new DateTime($appointment['appointment_time']);
                        $formattedTime = $appTime->format('g:i A');
                        $patientDisplay = mergeAppointmentPatientProfile($appointment, $patientsById);
                        $patientProfileHref = $staffProfileLinkTarget === 'doctor'
                            ? 'nurse_patient.php?id=' . (int) ($appointment['patient_id'] ?? 0)
                            : 'staff_patient.php?id=' . (int) ($appointment['patient_id'] ?? 0);
                        $notesFull = trim((string) ($appointment['notes'] ?? ''));
                        $servicesText = 'Not listed';
                        if (preg_match('/Services:\s*(.*?)(?:\s*\|\s*(?:Channel:|(?:Est\.\s*)?Total:)|\s*$)/i', $notesFull, $matches)) {
                            $servicesText = trim($matches[1]) !== '' ? trim($matches[1]) : 'Not listed';
                        }
                        $totalAmount = isset($appointment['total_display_price']) && $appointment['total_display_price'] !== null
                            ? 'PHP ' . number_format((float) $appointment['total_display_price'], 2)
                            : 'N/A';
                        $bookingType = isset($appointment['booking_type']) && $appointment['booking_type'] !== null && $appointment['booking_type'] !== ''
                            ? ucfirst((string) $appointment['booking_type'])
                            : 'N/A';
                        if (($appointment['booking_type'] ?? '') === 'consultation') {
                            $bookingType = 'Doctor consultation';
                            $servicesText = 'Doctor consultation';
                        } elseif (($appointment['booking_type'] ?? '') === 'package') {
                            $bookingType = 'Laboratory package';
                        } elseif (($appointment['booking_type'] ?? '') === 'individual') {
                            $bookingType = 'Laboratory tests';
                        } elseif (($appointment['booking_type'] ?? '') === 'ultrasound') {
                            $bookingType = 'Ultra sound';
                            $servicesText = 'Ultra sound';
                        }
                        $statusValue = strtolower((string) ($appointment['status'] ?? 'pending'));
                        $statusLabel = $statusValue === 'cancelled' ? 'Declined' : ucfirst($statusValue);
                        $searchText = trim(implode(' ', [
                            '#' . (int) ($appointment['id'] ?? 0),
                            $patientDisplay['patient_name'] ?? '',
                            $appointment['doctor_name'] ?? '',
                            $formattedDate,
                            $formattedTime,
                            $statusValue,
                            $notesFull,
                            $servicesText,
                            $bookingType,
                        ]));
                        $detailPayload = [
                            'reference' => '#' . (int) ($appointment['id'] ?? 0),
                            'patient' => $userRole === 'patient' ? ($currentUser['full_name'] ?? 'Patient') : ($patientDisplay['patient_name'] ?? 'N/A'),
                            'doctor' => $appointment['doctor_name'] ?? 'Not Assigned',
                            'date' => $formattedDate,
                            'time' => $formattedTime,
                            'rawDate' => (string) ($appointment['appointment_date'] ?? ''),
                            'status' => $statusLabel,
                            'statusKey' => $statusValue,
                            'bookingType' => $bookingType,
                            'totalAmount' => $totalAmount,
                            'services' => $servicesText,
                            'notes' => $notesFull !== '' ? $notesFull : 'None',
                        ];
                        ?>
                        <tr class="appointment-row" tabindex="0"
                            data-appointment='<?php echo htmlspecialchars(json_encode($detailPayload), ENT_QUOTES, 'UTF-8'); ?>'
                            data-appointment-id="<?php echo (int) ($appointment['id'] ?? 0); ?>"
                            data-status="<?php echo htmlspecialchars($statusValue); ?>"
                            data-date="<?php echo htmlspecialchars((string) ($appointment['appointment_date'] ?? '')); ?>"
                            data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                            <?php if ($userRole !== 'patient'): ?>
                                <td>
                                    <div class="patient-cell">
                                        <?php echo renderPatientAvatar($patientDisplay, [
                                            'size' => 'sm',
                                            'link' => true,
                                            'link_target' => $staffProfileLinkTarget,
                                            'patient_id' => (int) ($appointment['patient_id'] ?? 0),
                                        ]); ?>
                                        <div>
                                            <a href="<?php echo htmlspecialchars($patientProfileHref); ?>" style="font-weight:700;color:#0077b6;text-decoration:none;">
                                                <?php echo htmlspecialchars($patientDisplay['patient_name'] ?? 'N/A'); ?>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            <?php endif; ?>
                            <?php if ($isClinicalAppointmentsView): ?>
                                <td>
                                    <strong>Doctor Consultation</strong><br>
                                    <span style="color:#60758a;font-size:.88rem;"><?php echo htmlspecialchars($appointment['doctor_name'] ?? 'Assigned doctor'); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo $formattedDate; ?></strong><br>
                                    <span style="color:#60758a;font-size:.88rem;"><?php echo $formattedTime; ?></span>
                                </td>
                            <?php elseif ($userRole === 'admin'): ?>
                                <td><?php echo htmlspecialchars($appointment['doctor_name'] ?? 'Not Assigned'); ?></td>
                                <td><strong><?php echo $formattedDate; ?></strong></td>
                            <?php else: ?>
                                <td><strong><?php echo $formattedDate; ?></strong></td>
                            <?php endif; ?>
                            <td>
                                <span class="status-badge <?php echo htmlspecialchars($statusValue); ?>">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                            </td>
                            <?php if (!$isClinicalAppointmentsView): ?>
                                <td><?php echo htmlspecialchars($servicesText !== 'Not listed' ? $servicesText : ($notesFull !== '' ? substr($notesFull, 0, 50) : 'No notes')); ?><?php echo strlen($servicesText !== 'Not listed' ? $servicesText : $notesFull) > 50 ? '...' : ''; ?></td>
                            <?php endif; ?>
                            <td>
                                <div class="action-buttons <?php echo (($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation') || ($isClinicalAppointmentsView && ($appointment['booking_type'] ?? '') === 'consultation')) && $appointment['status'] === 'pending' ? 'pending-actions' : ''; ?>">
                                    <button type="button" class="btn btn-view" data-open-details>Details</button>
                                    <?php if (($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation') || ($isClinicalAppointmentsView && ($appointment['booking_type'] ?? '') === 'consultation' && $appointment['status'] === 'pending')): ?>
                                        <?php if ($appointment['status'] === 'pending'): ?>
                                            <form method="POST" action="update_appointment_status.php" class="confirm-action" style="display:inline;">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="confirmed">
                                                <input type="hidden" name="return_url" value="view_appointments.php">
                                                <button type="submit" class="btn btn-confirm" data-confirm-message="Are you sure you want to confirm this appointment?">Confirm</button>
                                            </form>
                                            <form method="POST" action="update_appointment_status.php" class="decline-action" style="display:inline;">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="cancelled">
                                                <input type="hidden" name="return_url" value="view_appointments.php">
                                                <button type="submit" class="btn btn-cancel" data-confirm-message="Are you sure you want to decline this appointment?">Decline</button>
                                            </form>
                                        <?php elseif ($userRole === 'admin' && ($appointment['booking_type'] ?? '') !== 'consultation' && $appointment['status'] === 'confirmed'): ?>
                                            <form method="POST" action="update_appointment_status.php" style="display:inline;">
                                                <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <input type="hidden" name="return_url" value="view_appointments.php">
                                                <button type="submit" class="btn btn-complete" data-confirm-message="Are you sure you want to mark this appointment as completed?">Complete</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($userRole === 'patient' && $appointment['status'] === 'pending'): ?>
                                        <form method="POST" action="update_appointment_status.php" style="display:inline;">
                                            <input type="hidden" name="appointment_id" value="<?php echo $appointment['id']; ?>">
                                            <input type="hidden" name="status" value="cancelled">
                                            <input type="hidden" name="return_url" value="view_appointments.php">
                                            <button type="submit" class="btn btn-cancel" data-confirm-message="Are you sure you want to cancel this appointment?">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="filter-empty" id="appointmentFilterEmpty">
                <strong>No appointments match your filters.</strong>
                <span>Try another search, status, or date.</span>
            </div>
            <div class="appointment-pagination-footer">
                <span id="appointmentPageInfo">Showing appointments</span>
                <div class="appointment-pagination" id="appointmentPagination" aria-label="Appointment pages"></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="appointment-modal" id="appointmentDetailsModal" aria-hidden="true">
    <div class="appointment-modal-card" role="dialog" aria-modal="true" aria-labelledby="appointmentDetailsTitle">
        <div class="appointment-modal-head">
            <div>
                <h3 id="appointmentDetailsTitle">Appointment details</h3>
                <p id="appointmentDetailsSub">Review schedule and notes</p>
            </div>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <div class="appointment-modal-body">
            <div class="detail-summary-strip" id="appointmentDetailSummary"></div>
            <dl class="appointment-detail-grid" id="appointmentDetailsGrid"></dl>
            <div class="detail-notes-box" id="appointmentDetailNotes"></div>
        </div>
        <div class="appointment-modal-actions">
            <button type="button" class="btn btn-view" id="printAppointmentDetails">Print details</button>
            <button type="button" class="btn btn-light" data-close-modal>Close</button>
        </div>
    </div>
</div>

<div class="appointment-modal" id="statusConfirmModal" aria-hidden="true">
    <div class="appointment-modal-card" role="dialog" aria-modal="true" aria-labelledby="statusConfirmTitle">
        <div class="appointment-modal-head">
            <div>
                <h3 id="statusConfirmTitle">Confirm action</h3>
                <p id="statusConfirmText">Please confirm this appointment update.</p>
            </div>
            <button type="button" class="modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>
        <div class="appointment-modal-actions" style="padding-top:20px;">
            <button type="button" class="btn btn-light" data-close-modal>Back</button>
            <button type="button" class="btn btn-confirm" id="statusConfirmYes">Yes, continue</button>
        </div>
    </div>
</div>

<script>
(function() {
    var detailModal = document.getElementById('appointmentDetailsModal');
    var detailGrid = document.getElementById('appointmentDetailsGrid');
    var detailSummary = document.getElementById('appointmentDetailSummary');
    var detailNotes = document.getElementById('appointmentDetailNotes');
    var detailSub = document.getElementById('appointmentDetailsSub');
    var confirmModal = document.getElementById('statusConfirmModal');
    var confirmText = document.getElementById('statusConfirmText');
    var confirmYes = document.getElementById('statusConfirmYes');
    var pendingForm = null;
    var searchInput = document.getElementById('appointmentSearch');
    var statusFilter = document.getElementById('appointmentStatusFilter');
    var dateFilter = document.getElementById('appointmentDateFilter');
    var resetFilter = document.getElementById('appointmentFilterReset');
    var resultCount = document.getElementById('appointmentResultCount');
    var filterEmpty = document.getElementById('appointmentFilterEmpty');
    var printDetails = document.getElementById('printAppointmentDetails');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.appointment-row'));
    var pageInfo = document.getElementById('appointmentPageInfo');
    var pagination = document.getElementById('appointmentPagination');
    var filteredRows = rows.slice();
    var currentPage = 1;
    var pageSize = 6;

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    function closeAll() {
        closeModal(detailModal);
        closeModal(confirmModal);
        pendingForm = null;
    }
    function text(value) {
        return value === undefined || value === null || value === '' ? 'N/A' : String(value);
    }
    function escapeHtml(value) {
        return text(value).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
        });
    }
    function showDetails(row) {
        if (!row || !detailGrid) return;
        var data = {};
        try { data = JSON.parse(row.getAttribute('data-appointment') || '{}'); } catch (e) { data = {}; }
        if (detailSummary) {
            detailSummary.innerHTML = [
                ['Reference', data.reference],
                ['Status', data.status],
                ['Total', data.totalAmount]
            ].map(function(item) {
                return '<div class="detail-pill"><span>' + item[0] + '</span><strong>' + escapeHtml(item[1]) + '</strong></div>';
            }).join('');
        }
        var items = [
            ['Patient', data.patient],
            ['Doctor', data.doctor],
            ['Date', data.date],
            ['Booking type', data.bookingType],
            ['Services', data.services]
        ];
        detailGrid.innerHTML = items.map(function(item) {
            return '<dt>' + item[0] + '</dt><dd>' + escapeHtml(item[1]) + '</dd>';
        }).join('');
        if (detailNotes) {
            detailNotes.innerHTML = '<strong>Notes</strong><span>' + escapeHtml(data.notes) + '</span>';
        }
        if (detailSub) detailSub.textContent = text(data.reference) + ' • ' + text(data.status);
        openModal(detailModal);
    }

    function renderAppointmentPage() {
        var totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        currentPage = Math.min(currentPage, totalPages);
        var start = (currentPage - 1) * pageSize;
        var end = start + pageSize;

        rows.forEach(function(row) {
            row.classList.add('page-hidden');
        });
        filteredRows.slice(start, end).forEach(function(row) {
            row.classList.remove('page-hidden');
        });

        if (pageInfo) {
            pageInfo.textContent = filteredRows.length === 0
                ? 'Showing 0 appointments'
                : 'Showing ' + (start + 1) + ' to ' + Math.min(end, filteredRows.length) + ' of ' + filteredRows.length + ' appointments';
        }

        if (!pagination) return;
        pagination.innerHTML = '';

        var prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'appointment-page-btn';
        prev.innerHTML = '&lsaquo;';
        prev.disabled = currentPage <= 1;
        prev.addEventListener('click', function() {
            currentPage -= 1;
            renderAppointmentPage();
        });
        pagination.appendChild(prev);

        for (var page = 1; page <= totalPages; page++) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'appointment-page-btn' + (page === currentPage ? ' active' : '');
            button.textContent = page;
            if (page === currentPage) button.setAttribute('aria-current', 'page');
            button.addEventListener('click', (function(pageNumber) {
                return function() {
                    currentPage = pageNumber;
                    renderAppointmentPage();
                };
            })(page));
            pagination.appendChild(button);
        }

        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'appointment-page-btn';
        next.innerHTML = '&rsaquo;';
        next.disabled = currentPage >= totalPages;
        next.addEventListener('click', function() {
            currentPage += 1;
            renderAppointmentPage();
        });
        pagination.appendChild(next);
    }

    function applyAppointmentFilters() {
        var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
        var status = statusFilter ? statusFilter.value : '';
        var date = dateFilter ? dateFilter.value : '';
        filteredRows = [];

        rows.forEach(function(row) {
            var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();
            var rowStatus = row.getAttribute('data-status') || '';
            var rowDate = row.getAttribute('data-date') || '';
            var show = true;

            if (q && rowSearch.indexOf(q) === -1) show = false;
            if (status && rowStatus !== status) show = false;
            if (date && rowDate !== date) show = false;

            row.classList.toggle('hidden', !show);
            if (show) filteredRows.push(row);
        });

        if (resultCount) {
            resultCount.textContent = filteredRows.length + ' appointment' + (filteredRows.length === 1 ? '' : 's') + ' shown';
        }
        if (filterEmpty) {
            filterEmpty.style.display = filteredRows.length === 0 ? 'block' : 'none';
        }
        currentPage = 1;
        renderAppointmentPage();
    }

    [searchInput, statusFilter, dateFilter].forEach(function(control) {
        if (control) control.addEventListener('input', applyAppointmentFilters);
        if (control && control.tagName === 'SELECT') control.addEventListener('change', applyAppointmentFilters);
    });
    if (resetFilter) {
        resetFilter.addEventListener('click', function() {
            if (searchInput) searchInput.value = '';
            if (statusFilter) statusFilter.value = '';
            if (dateFilter) dateFilter.value = '';
            applyAppointmentFilters();
        });
    }
    if (printDetails) {
        printDetails.addEventListener('click', function() {
            window.print();
        });
    }

    document.querySelectorAll('[data-open-details]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            showDetails(button.closest('[data-appointment]'));
        });
    });
    document.querySelectorAll('.appointment-row').forEach(function(row) {
        row.addEventListener('click', function(event) {
            if (event.target.closest('button, a, form')) return;
            showDetails(row);
        });
        row.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                showDetails(row);
            }
        });
    });
    applyAppointmentFilters();
    var highlightedAppointmentId = new URLSearchParams(window.location.search).get('highlight');
    if (highlightedAppointmentId) {
        var highlightedRow = rows.find(function(row) {
            return row.getAttribute('data-appointment-id') === highlightedAppointmentId;
        });
        if (highlightedRow) {
            highlightedRow.scrollIntoView({ block: 'center', behavior: 'smooth' });
            highlightedRow.classList.add('is-highlighted');
            window.setTimeout(function() {
                showDetails(highlightedRow);
            }, 250);
        }
    }
    document.querySelectorAll('button[data-confirm-message]').forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            pendingForm = button.closest('form');
            if (confirmText) confirmText.textContent = button.getAttribute('data-confirm-message') || 'Confirm this action?';
            openModal(confirmModal);
        });
    });
    if (confirmYes) {
        confirmYes.addEventListener('click', function() {
            var form = pendingForm;
            pendingForm = null;
            closeModal(confirmModal);
            if (form) form.submit();
        });
    }
    document.querySelectorAll('[data-close-modal]').forEach(function(button) {
        button.addEventListener('click', closeAll);
    });
    document.querySelectorAll('.appointment-modal').forEach(function(modal) {
        modal.addEventListener('click', function(event) {
            if (event.target === modal) closeAll();
        });
    });
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') closeAll();
    });
})();
</script>

<?php include 'includes/footer.php'; ?>



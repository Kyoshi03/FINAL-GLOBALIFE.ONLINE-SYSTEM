<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/appointment_booking.php';

$currentUser = getCurrentUser();

// Get all active doctors
$conn = getDBConnection();
$doctorsQuery = "SELECT id, full_name, email, phone FROM users WHERE role = 'doctor' AND COALESCE(is_active, 1) = 1 ORDER BY full_name";
$doctors = $conn->query($doctorsQuery)->fetch_all(MYSQLI_ASSOC);

// Get appointments for each doctor
$doctorAppointments = [];
foreach ($doctors as $doctor) {
    $stmt = $conn->prepare("SELECT a.*, p.full_name as patient_name, p.profile_photo, p.profile_updated_at
        FROM appointments a
        JOIN users p ON a.patient_id = p.id
        WHERE a.doctor_id = ?
          AND a.booking_type = 'consultation'
          AND a.status IN ('pending', 'confirmed')
          AND a.appointment_date >= CURDATE()
        ORDER BY a.appointment_date ASC, a.appointment_time ASC");
    $stmt->bind_param("i", $doctor['id']);
    $stmt->execute();
    $appointments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $doctorAppointments[$doctor['id']] = $appointments;
    $stmt->close();
}
$conn->close();
$doctorDailyLimit = appointment_doctor_daily_limit();

$pageTitle = "Doctor Schedules | Globalife Medical Laboratory & Polyclinic";
$additionalStyles = patientAvatarStyles() . '
    .schedule-patient{display:flex;align-items:center;gap:8px}
    body {
        background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%);
        min-height: 100vh;
    }
    .schedules-container {
        max-width: 1200px;
        margin: 40px auto;
        padding: 40px 20px;
    }
    .page-header {
        background: linear-gradient(135deg, #0077b6 0%, #48cae4 100%);
        border-radius: 20px;
        padding: 40px;
        margin-bottom: 40px;
        color: #fff;
        box-shadow: 0 10px 40px rgba(0, 119, 182, 0.2);
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
    .doctors-list {
        display: grid;
        gap: 25px;
    }
    .doctor-card {
        background: #fff;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 5px 25px rgba(0, 0, 0, 0.08);
        border: 2px solid transparent;
        transition: all 0.3s ease;
    }
    .doctor-card:hover {
        border-color: #0077b6;
        box-shadow: 0 10px 40px rgba(0, 119, 182, 0.15);
    }
    .doctor-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid #e0e0e0;
    }
    .doctor-info h3 {
        color: #0077b6;
        margin: 0 0 10px 0;
        font-size: 1.5rem;
        font-weight: 700;
    }
    .doctor-info p {
        margin: 5px 0;
        color: #666;
        font-size: 0.95rem;
    }
    .doctor-icon {
        width: 60px;
        height: 60px;
        background: linear-gradient(135deg, #0077b6 0%, #48cae4 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.5rem;
        font-weight: 700;
        box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
    }
    .appointments-schedule {
        margin-top: 20px;
    }
    .schedule-title {
        font-size: 1.1rem;
        font-weight: 600;
        color: #333;
        margin-bottom: 15px;
    }
    .appointments-list {
        display: grid;
        gap: 12px;
    }
    .daily-capacity-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        gap: 10px;
        margin: 0 0 18px;
    }
    .daily-capacity-card {
        padding: 14px 15px;
        border: 1px solid #cfe3ef;
        border-radius: 12px;
        background: #f7fcff;
    }
    .daily-capacity-card.full {
        border-color: #efc2c8;
        background: #fff5f6;
    }
    .daily-capacity-card strong {
        display: block;
        color: #073b4c;
        margin-bottom: 5px;
    }
    .daily-capacity-card span {
        display: block;
        color: #60727d;
        font-size: .84rem;
    }
    .daily-capacity-card b {
        display: inline-block;
        margin-top: 8px;
        padding: 5px 9px;
        border-radius: 999px;
        background: #e8f8ef;
        color: #17643a;
        font-size: .75rem;
    }
    .daily-capacity-card.full b {
        background: #ffe1e5;
        color: #a61b2b;
    }
    .appointment-schedule-item {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        padding: 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        transition: all 0.3s ease;
    }
    .appointment-schedule-item:hover {
        border-color: #0077b6;
        transform: translateX(5px);
        box-shadow: 0 5px 15px rgba(0, 119, 182, 0.1);
    }
    .schedule-info {
        flex: 1;
    }
    .schedule-date {
        font-size: 1.1rem;
        font-weight: 700;
        color: #0077b6;
        margin-bottom: 5px;
    }
    .schedule-details {
        color: #666;
        font-size: 0.9rem;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }
    .schedule-status {
        padding: 5px 12px;
        border-radius: 15px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    .schedule-status.pending {
        background: #fff3cd;
        color: #856404;
    }
    .schedule-status.confirmed {
        background: #d4edda;
        color: #155724;
    }
    .empty-schedule {
        text-align: center;
        padding: 40px 20px;
        color: #999;
        font-style: italic;
    }
    .back-link {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #0077b6;
        text-decoration: none;
        font-weight: 500;
        margin-bottom: 20px;
        transition: color 0.2s;
    }
    .back-link:hover {
        color: #023e8a;
    }
    @media (max-width: 768px) {
        .doctor-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        .doctor-icon {
            align-self: center;
        }
        .appointment-schedule-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
    }
';

include 'includes/header.php';
?>

<div class="schedules-container">
    <a href="receptionist.php" class="back-link">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
        </svg>
        Back to Dashboard
    </a>
    
    <div class="page-header">
        <h2>Doctor Schedules</h2>
        <p>View and manage doctor availability and appointments</p>
    </div>
    
    <?php if (empty($doctors)): ?>
        <div class="doctor-card">
            <div class="empty-schedule">
                <h3>No Doctors Available</h3>
                <p>There are no doctors registered in the system.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="doctors-list">
            <?php foreach ($doctors as $doctor): ?>
                <?php
                $appointments = $doctorAppointments[$doctor['id']] ?? [];
                $dailyCounts = [];
                foreach ($appointments as $appointment) {
                    $dateKey = (string) $appointment['appointment_date'];
                    $dailyCounts[$dateKey] = ($dailyCounts[$dateKey] ?? 0) + 1;
                }
                $initial = strtoupper(substr($doctor['full_name'], 0, 1));
                ?>
                <div class="doctor-card">
                    <div class="doctor-header">
                        <div class="doctor-info">
                            <h3>Dr. <?php echo htmlspecialchars($doctor['full_name']); ?></h3>
                            <?php if ($doctor['email']): ?>
                                <p>📧 <?php echo htmlspecialchars($doctor['email']); ?></p>
                            <?php endif; ?>
                            <?php if ($doctor['phone']): ?>
                                <p>📱 <?php echo htmlspecialchars($doctor['phone']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="doctor-icon"><?php echo $initial; ?></div>
                    </div>
                    
                    <div class="appointments-schedule">
                        <div class="schedule-title">
                            Upcoming Appointments (<?php echo count($appointments); ?>)
                        </div>
                        <?php if (!empty($dailyCounts)): ?>
                            <div class="daily-capacity-grid">
                                <?php foreach ($dailyCounts as $dateKey => $dailyCount): ?>
                                    <?php $isFull = $dailyCount >= $doctorDailyLimit; ?>
                                    <div class="daily-capacity-card<?php echo $isFull ? ' full' : ''; ?>">
                                        <strong><?php echo htmlspecialchars(date('M d, Y', strtotime($dateKey))); ?></strong>
                                        <span><?php echo $dailyCount; ?> of <?php echo $doctorDailyLimit; ?> appointments</span>
                                        <b><?php echo $isFull ? 'Fully booked' : ($doctorDailyLimit - $dailyCount) . ' remaining'; ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($appointments)): ?>
                            <div class="empty-schedule">
                                <p>No upcoming appointments scheduled</p>
                            </div>
                        <?php else: ?>
                            <div class="appointments-list">
                                <?php foreach ($appointments as $appointment): ?>
                                    <?php
                                    $appDate = new DateTime($appointment['appointment_date']);
                                    $formattedDate = $appDate->format('M d, Y');
                                    $appTime = new DateTime($appointment['appointment_time']);
                                    $formattedTime = $appTime->format('g:i A');
                                    $patientName = $appointment['patient_name'] ?? 'Unknown';
                                    ?>
                                    <div class="appointment-schedule-item">
                                        <div class="schedule-info">
                                            <div class="schedule-date"><?php echo $formattedDate; ?></div>
                                            <div class="schedule-details">
                                                <span>🕐 <?php echo $formattedTime; ?></span>
                                                <span class="schedule-patient">
                                                    <?php echo renderPatientAvatar($appointment, ['size' => 'sm', 'link' => true, 'patient_id' => (int) ($appointment['patient_id'] ?? 0)]); ?>
                                                    <?php echo htmlspecialchars($patientName); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <span class="schedule-status <?php echo $appointment['status']; ?>">
                                            <?php echo ucfirst($appointment['status']); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>

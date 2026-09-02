<?php
require_once 'includes/session.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/clinic_notifications.php';

if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$currentUser = getCurrentUser();
$role = (string) ($currentUser['role'] ?? '');
if ($role !== 'doctor') {
    header('Location: ' . dashboardForRole($role));
    exit;
}

$conn = getDBConnection();
init_clinic_notifications($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['clinic_action'] ?? '') === 'mark_notifications_read') {
    mark_clinic_notifications_read($conn, $role, (int) ($currentUser['id'] ?? 0));
    $_SESSION['success'] = 'Notifications marked as read.';
    $conn->close();
    header('Location: clinic_notifications.php');
    exit;
}

$notifications = fetch_clinic_notifications($conn, $role, (int) ($currentUser['id'] ?? 0), 50);
$unreadCount = count_unread_clinic_notifications($conn, $role, (int) ($currentUser['id'] ?? 0));
$conn->close();

$pageTitle = 'Clinic Notifications | Globalife Medical Laboratory & Polyclinic';
$roleLabel = [
    'doctor' => 'Doctor Updates',
][$role] ?? 'Clinic Team';

function clinic_notification_card_meta(array $notification): array {
    $type = strtolower((string) ($notification['notification_type'] ?? ''));
    if (strpos($type, 'confirmed') !== false) {
        return ['class' => 'approved', 'label' => 'Confirmed', 'icon' => 'm20 6-11 11-5-5'];
    }
    if (strpos($type, 'cancelled') !== false) {
        return ['class' => 'cancelled', 'label' => 'Cancelled', 'icon' => 'M18 6 6 18M6 6l12 12'];
    }
    if (strpos($type, 'completed') !== false) {
        return ['class' => 'completed', 'label' => 'Completed', 'icon' => 'M9 12l2 2 4-5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z'];
    }
    if (strpos($type, 'rescheduled') !== false) {
        return ['class' => 'rescheduled', 'label' => 'Rescheduled', 'icon' => 'M21 12a9 9 0 1 1-3-6.7M21 3v6h-6'];
    }
    return ['class' => 'new', 'label' => 'New', 'icon' => 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2z'];
}

$additionalStyles = '
body {
    background:
        radial-gradient(circle at top right, rgba(72, 202, 228, 0.18), transparent 32%),
        linear-gradient(135deg, #f5fbfd 0%, #eef8fc 100%);
}
.clinic-notifications {
    max-width: 1180px;
    margin: 0 auto;
    padding: 34px 20px 56px;
}
.clinic-notifications-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    border: 1px solid #d7eaf4;
    border-radius: 10px;
    padding: 26px;
    background:
        radial-gradient(circle at 92% 12%, rgba(72, 202, 228, 0.26), transparent 28%),
        linear-gradient(135deg, #ffffff 0%, #eefaff 100%);
    box-shadow: 0 18px 38px rgba(2, 62, 138, 0.08);
}
.clinic-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #0077b6;
    font-size: .84rem;
    font-weight: 950;
    text-transform: uppercase;
}
.clinic-kicker svg,
.clinic-card-icon svg {
    fill: none;
    stroke: currentColor;
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.clinic-notifications-hero h1 {
    margin: 8px 0 6px;
    color: #073b4c;
    font-size: 2rem;
    line-height: 1.1;
}
.clinic-notifications-hero p {
    margin: 0;
    color: #58707d;
    line-height: 1.55;
}
.clinic-hero-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.clinic-unread-pill {
    min-height: 46px;
    min-width: 112px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #eaf8ff;
    color: #0077b6;
    font-weight: 950;
}
.clinic-mark-btn,
.clinic-card-action {
    min-height: 42px;
    border: 0;
    border-radius: 8px;
    padding: 0 15px;
    background: #0077b6;
    color: #fff;
    font-weight: 950;
    text-decoration: none;
    cursor: pointer;
}
.clinic-card-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}
.clinic-card-actions form {
    margin: 0;
}
.clinic-card-action.confirm {
    background: #0f8f5f;
}
.clinic-card-action.decline {
    background: #fff4f4;
    color: #9f2434;
    border: 1px solid #ffd4dc;
}
.clinic-mark-btn {
    background: #eef8ff;
    color: #0b4f80;
    border: 1px solid #cfe5f4;
}
.clinic-feed {
    display: grid;
    gap: 12px;
    margin-top: 18px;
}
.clinic-card {
    display: grid;
    grid-template-columns: 50px minmax(0, 1fr) auto;
    gap: 15px;
    align-items: center;
    border: 1px solid #dce8ef;
    border-radius: 10px;
    padding: 17px;
    background: rgba(255, 255, 255, 0.96);
    box-shadow: 0 12px 26px rgba(25, 76, 110, 0.06);
}
.clinic-card.unread {
    border-color: #8ed9ef;
    background: #f4fbff;
}
.clinic-card-icon {
    width: 50px;
    height: 50px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    background: #eaf8ff;
    color: #0077b6;
}
.clinic-card.approved .clinic-card-icon,
.clinic-card.completed .clinic-card-icon {
    background: #e7f7ed;
    color: #17643a;
}
.clinic-card.cancelled .clinic-card-icon {
    background: #fff0f0;
    color: #a5162c;
}
.clinic-card.rescheduled .clinic-card-icon {
    background: #fff7df;
    color: #9a6900;
}
.clinic-card h3 {
    margin: 0;
    color: #073b4c;
    font-size: 1.05rem;
}
.clinic-card p {
    margin: 5px 0 8px;
    color: #58707d;
    line-height: 1.45;
}
.clinic-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    color: #71838d;
    font-size: .84rem;
    font-weight: 800;
}
.clinic-status {
    border-radius: 999px;
    padding: 4px 9px;
    background: #eaf8ff;
    color: #0077b6;
    font-size: .72rem;
    font-weight: 950;
    text-transform: uppercase;
}
.clinic-empty {
    margin-top: 18px;
    border: 1px dashed #b9d9eb;
    border-radius: 10px;
    padding: 34px 18px;
    text-align: center;
    background: #ffffff;
    color: #58707d;
    font-weight: 800;
}
@media (max-width: 760px) {
    .clinic-notifications {
        padding: 22px 12px 120px;
    }
    .clinic-notifications-hero,
    .clinic-card {
        grid-template-columns: 1fr;
    }
    .clinic-notifications-hero {
        align-items: flex-start;
        flex-direction: column;
    }
    .clinic-hero-actions,
    .clinic-card-action,
    .clinic-card-actions {
        width: 100%;
    }
    .clinic-card-action,
    .clinic-mark-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
}
';

include 'includes/header.php';
?>
<main class="clinic-notifications">
    <section class="clinic-notifications-hero" aria-labelledby="clinicNotificationsTitle">
        <div>
            <span class="clinic-kicker">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7M10 20a2 2 0 0 0 4 0"/></svg>
                <?php echo htmlspecialchars($roleLabel); ?>
            </span>
            <h1 id="clinicNotificationsTitle">Clinic notifications</h1>
            <p>Appointment requests, approvals, cancellations, and queue updates for your clinic role.</p>
        </div>
        <div class="clinic-hero-actions">
            <span class="clinic-unread-pill"><?php echo (int) $unreadCount; ?> unread</span>
            <?php if ($unreadCount > 0): ?>
                <form method="post">
                    <input type="hidden" name="clinic_action" value="mark_notifications_read">
                    <button class="clinic-mark-btn" type="submit">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <?php if (empty($notifications)): ?>
        <div class="clinic-empty">No clinic notifications yet.</div>
    <?php else: ?>
        <section class="clinic-feed" aria-label="Notification list">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $meta = clinic_notification_card_meta($notification);
                $isUnread = empty($notification['read_at']);
                $time = strtotime((string) ($notification['created_at'] ?? ''));
                $timeLabel = $time ? date('M d, Y g:i A', $time) : '';
                $url = trim((string) ($notification['target_url'] ?? 'view_appointments.php'));
                $url = $url !== '' ? $url : 'view_appointments.php';
                ?>
                <article class="clinic-card <?php echo htmlspecialchars($meta['class']); ?> <?php echo $isUnread ? 'unread' : ''; ?>">
                    <span class="clinic-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="<?php echo htmlspecialchars($meta['icon']); ?>"/></svg>
                    </span>
                    <div>
                        <h3><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Clinic update')); ?></h3>
                        <p><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                        <span class="clinic-card-meta">
                            <?php if ($timeLabel !== ''): ?>
                                <span><?php echo htmlspecialchars($timeLabel); ?></span>
                            <?php endif; ?>
                            <span class="clinic-status"><?php echo htmlspecialchars($meta['label']); ?></span>
                            <span><?php echo $isUnread ? 'Unread' : 'Read'; ?></span>
                        </span>
                    </div>
                    <?php
                    $canDoctorRespond = $role === 'doctor'
                        && (string) ($notification['appointment_booking_type'] ?? '') === 'consultation'
                        && (string) ($notification['appointment_status'] ?? '') === 'pending'
                        && (int) ($notification['appointment_doctor_id'] ?? 0) === (int) ($currentUser['id'] ?? 0);
                    ?>
                    <div class="clinic-card-actions">
                        <?php if ($canDoctorRespond): ?>
                            <form method="post" action="update_appointment_status.php">
                                <input type="hidden" name="appointment_id" value="<?php echo (int) ($notification['related_appointment_id'] ?? 0); ?>">
                                <input type="hidden" name="status" value="confirmed">
                                <input type="hidden" name="return_url" value="clinic_notifications.php">
                                <button class="clinic-card-action confirm" type="submit" data-confirm-message="Confirm this consultation request?">Confirm</button>
                            </form>
                            <form method="post" action="update_appointment_status.php">
                                <input type="hidden" name="appointment_id" value="<?php echo (int) ($notification['related_appointment_id'] ?? 0); ?>">
                                <input type="hidden" name="status" value="cancelled">
                                <input type="hidden" name="return_url" value="clinic_notifications.php">
                                <button class="clinic-card-action decline" type="submit" data-confirm-message="Decline this consultation request?">Decline</button>
                            </form>
                        <?php endif; ?>
                        <a class="clinic-card-action" href="<?php echo htmlspecialchars($url); ?>">Details</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>

<?php
$panelDoctor = $panelDoctor ?? [];
$panelSlots = $panelSlots ?? [];
$doctorId = (int) ($panelDoctor['id'] ?? 0);
$isActive = (int) ($panelDoctor['is_active'] ?? 1) === 1;
if (!isset($dayNames) || !is_array($dayNames)) {
    $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
}
if (empty($panelSlots)) {
    $panelSlots = [['day_of_week' => 1, 'time_start' => '09:00:00', 'time_end' => '12:00:00']];
}
?>
<div class="doctor-edit-panel" data-edit-panel="<?php echo $doctorId; ?>">
    <div class="editor-layout">
        <div class="editor-card">
            <h3>Profile</h3>
            <form method="post" action="nurse_doctors.php">
                <input type="hidden" name="nurse_doctor_action" value="save_profile">
                <input type="hidden" name="user_id" value="<?php echo $doctorId; ?>">
                <div class="name-grid full">
                    <div class="field">
                        <label>First name</label>
                        <input name="first_name" maxlength="15" value="<?php echo htmlspecialchars((string) ($panelDoctor['first_name'] ?? '')); ?>" required>
                    </div>
                    <div class="field">
                        <label>Middle name</label>
                        <input name="middle_name" maxlength="1" value="<?php echo htmlspecialchars((string) ($panelDoctor['middle_name'] ?? '')); ?>" required style="text-transform:uppercase;">
                    </div>
                    <div class="field">
                        <label>Last name</label>
                        <input name="last_name" maxlength="15" value="<?php echo htmlspecialchars((string) ($panelDoctor['last_name'] ?? '')); ?>" required>
                    </div>
                    <div class="field">
                        <label>Suffix</label>
                        <input name="suffix" maxlength="3" value="<?php echo htmlspecialchars((string) ($panelDoctor['suffix'] ?? '')); ?>" style="text-transform:uppercase;">
                    </div>
                </div>
                <div class="field">
                    <label>Specialty</label>
                    <input name="specialty" value="<?php echo htmlspecialchars((string) ($panelDoctor['specialty'] ?? '')); ?>">
                </div>
                <div class="field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars((string) ($panelDoctor['email'] ?? '')); ?>">
                </div>
                <div class="field">
                    <label>Phone</label>
                    <input name="phone" value="<?php echo htmlspecialchars((string) ($panelDoctor['phone'] ?? '')); ?>">
                </div>
                <div class="editor-actions">
                    <button class="rd-btn" type="submit">Save profile</button>
                </div>
            </form>
        </div>

        <div class="editor-card">
            <h3>Clinic schedule</h3>
            <form method="post" action="nurse_doctors.php">
                <input type="hidden" name="doctor_schedule_action" value="save_slots">
                <input type="hidden" name="user_id" value="<?php echo $doctorId; ?>">
                <div class="slot-rows" id="editSlotRows<?php echo $doctorId; ?>" data-slot-rows>
                    <?php foreach ($panelSlots as $slot): ?>
                        <?php
                        $selectedDay = (int) ($slot['day_of_week'] ?? 1);
                        $start = substr((string) ($slot['time_start'] ?? '09:00:00'), 0, 5);
                        $end = substr((string) ($slot['time_end'] ?? '12:00:00'), 0, 5);
                        ?>
                        <div class="slot-row">
                            <div class="field">
                                <label>Day</label>
                                <select name="slot_day[]">
                                    <?php foreach ($dayNames as $dayNumber => $dayLabel): ?>
                                        <option value="<?php echo $dayNumber; ?>" <?php echo $selectedDay === (int) $dayNumber ? 'selected' : ''; ?>><?php echo htmlspecialchars($dayLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field"><label>Start</label><input type="time" name="slot_start[]" value="<?php echo htmlspecialchars($start); ?>" required></div>
                            <div class="field"><label>End</label><input type="time" name="slot_end[]" value="<?php echo htmlspecialchars($end); ?>" required></div>
                            <button type="button" class="rd-btn secondary" data-remove-slot>Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="editor-actions">
                    <button type="button" class="rd-btn secondary" data-add-slot-row data-target="editSlotRows<?php echo $doctorId; ?>">Add row</button>
                    <button class="rd-btn" type="submit">Save schedule</button>
                </div>
            </form>
            <form method="post" action="nurse_doctors.php" class="editor-actions" onsubmit="return confirm('Clear all schedule rows for this doctor?');">
                <input type="hidden" name="doctor_schedule_action" value="clear_slots">
                <input type="hidden" name="user_id" value="<?php echo $doctorId; ?>">
                <button class="rd-btn secondary" type="submit">Clear schedule</button>
            </form>
        </div>
    </div>

    <div class="editor-actions" style="margin-top:14px;">
        <form
            method="post"
            action="nurse_doctors.php"
            class="doctor-toggle-form"
            data-doctor-name="<?php echo htmlspecialchars((string) ($panelDoctor['full_name'] ?? 'Doctor'), ENT_QUOTES, 'UTF-8'); ?>"
            data-is-active="<?php echo $isActive ? '1' : '0'; ?>"
            style="display:inline-flex"
        >
            <input type="hidden" name="nurse_doctor_action" value="toggle_active">
            <input type="hidden" name="user_id" value="<?php echo $doctorId; ?>">
            <input type="hidden" name="set_active" value="<?php echo $isActive ? 0 : 1; ?>">
            <input type="hidden" name="stay_on_edit" value="1">
            <button type="submit" class="rd-btn <?php echo $isActive ? 'danger' : ''; ?> btn-toggle-doctor">
                <?php echo $isActive ? 'Deactivate doctor' : 'Activate doctor'; ?>
            </button>
        </form>
        <form method="post" class="doctor-delete-form" action="nurse_doctors.php" style="display:inline">
            <input type="hidden" name="nurse_doctor_action" value="delete_doctor">
            <input type="hidden" name="user_id" value="<?php echo $doctorId; ?>">
            <button type="submit" class="rd-btn danger doctor-delete-btn" data-doctor-name="<?php echo htmlspecialchars((string) ($panelDoctor['full_name'] ?? 'Doctor'), ENT_QUOTES, 'UTF-8'); ?>">Delete doctor</button>
        </form>
    </div>
</div>

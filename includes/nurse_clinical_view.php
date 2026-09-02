<?php
$clinicalTab = $clinicalTab ?? 'medical';
?>
<main class="clinical-shell">
    <section class="clinical-hero">
        <h1><?php echo htmlspecialchars($pageHeading ?? 'Clinical records'); ?></h1>
        <p><?php echo htmlspecialchars($pageDesc ?? 'Manage patient clinical records.'); ?></p>
    </section>

    <?php if (!empty($_SESSION['success'])): ?><div class="ok"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['error'])): ?><div class="er"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div><?php endif; ?>

    <div class="clinical-tabs">
        <a href="nurse_patients.php" class="active">Medical notes</a>
    </div>

    <div class="clinical-grid">
        <section class="clinical-card">
            <h2>Add medical note</h2>
            <div class="field">
                <label>Select patient</label>
                <select id="clinicalPatientSelect">
                    <option value="">Choose patient</option>
                    <?php foreach (($patientOptions ?? []) as $option): ?>
                        <option value="<?php echo (int) $option['id']; ?>" <?php echo ((int) ($selectedPatientId ?? 0) === (int) $option['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($option['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <form method="post" id="nurseMedicalForm">
                <input type="hidden" name="nurse_clinical_action" value="add_medical">
                <input type="hidden" name="clinical_tab" value="medical">
                <input type="hidden" name="clinical_patient_id" id="medicalPatientId" value="<?php echo (int) ($selectedPatientId ?? 0); ?>">
                <?php require __DIR__ . '/nurse_medical_form.inc.php'; ?>
                <button class="clinical-btn" type="submit">Save medical note</button>
            </form>
        </section>

        <section class="clinical-card">
            <h2>Recent medical notes</h2>
            <div class="recent-list active">
                <?php if (empty($recentRecords)): ?><p style="color:#666">No medical notes yet.</p><?php endif; ?>
                <?php foreach (($recentRecords ?? []) as $record): ?>
                    <div class="recent-item">
                        <strong><?php echo htmlspecialchars($record['patient_name'] . ' - ' . $record['title']); ?></strong>
                        <small><?php echo htmlspecialchars($record['created_at']); ?></small>
                        <?php nurse_medical_render_sections($record); ?>
                        <div class="record-actions" aria-label="Medical note actions">
                            <a class="record-action-btn print" href="nurse_export.php?type=medical&amp;id=<?php echo (int) $record['record_id']; ?>&amp;action=print" target="_blank" rel="noopener">
                                <span aria-hidden="true">P</span>
                                Print report
                            </a>
                            <a class="record-action-btn download" href="nurse_export.php?type=medical&amp;id=<?php echo (int) $record['record_id']; ?>&amp;action=download">
                                <span aria-hidden="true">D</span>
                                Download PDF
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</main>

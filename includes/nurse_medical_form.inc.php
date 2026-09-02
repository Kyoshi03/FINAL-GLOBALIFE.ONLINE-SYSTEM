<?php
$clinicalForm = isset($clinicalForm) && is_array($clinicalForm) ? $clinicalForm : [];
$fieldValues = array_merge([
    'title' => 'Medical note',
    'temperature' => '',
    'weight' => '',
    'notes' => '',
], $clinicalForm);
?>
<div class="clinical-form-grid">
    <div class="field">
        <label>Temperature</label>
        <input name="temperature" value="<?php echo htmlspecialchars((string) $fieldValues['temperature']); ?>" placeholder="Example: 36.8 C">
    </div>
    <div class="field">
        <label>Weight</label>
        <input name="weight" value="<?php echo htmlspecialchars((string) $fieldValues['weight']); ?>" placeholder="Example: 58 kg">
    </div>
    <div class="field full">
        <label>Notes</label>
        <textarea name="notes" placeholder="Patient notes"><?php echo htmlspecialchars((string) $fieldValues['notes']); ?></textarea>
    </div>
</div>

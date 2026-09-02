<?php
function nurse_medical_field_definitions(): array {
    return [
        'title' => 'Record title',
        'temperature' => 'Temperature',
        'weight' => 'Weight',
        'notes' => 'Notes',
    ];
}

function nurse_medical_fields_from_post(array $post): array {
    $out = [];
    foreach (array_keys(nurse_medical_field_definitions()) as $field) {
        $out[$field] = trim((string) ($post[$field] ?? $post['mr_' . $field] ?? ''));
    }
    if (($out['title'] ?? '') === '') {
        $out['title'] = 'Medical note';
    }
    return $out;
}

function nurse_medical_form_session_from_post(array $post, int $patientId): array {
    return array_merge(['tab' => 'medical', 'patient_id' => $patientId], nurse_medical_fields_from_post($post));
}

function nurse_medical_sections_for_display(array $record): array {
    $defs = nurse_medical_field_definitions();
    $sections = [];
    foreach ($defs as $field => $label) {
        if ($field === 'title') {
            continue;
        }
        $value = trim((string) ($record[$field] ?? ''));
        if ($value !== '') {
            $sections[$label] = $value;
        }
    }
    if (empty($sections)) {
        $legacyNotes = trim((string) (($record['doctor_notes'] ?? '') ?: ($record['content'] ?? '')));
        $legacyVitals = trim((string) ($record['vital_signs'] ?? ''));
        if ($legacyVitals !== '') {
            $sections['Temperature'] = $legacyVitals;
        }
        if ($legacyNotes !== '') {
            $sections['Notes'] = $legacyNotes;
        }
    }
    return $sections;
}

function nurse_medical_render_sections(array $record): void {
    $sections = nurse_medical_sections_for_display($record);
    if (empty($sections)) {
        echo '<div style="white-space:pre-wrap;color:#444">' . htmlspecialchars((string) ($record['content'] ?? '')) . '</div>';
        return;
    }
    echo '<table class="medical-section-table">';
    foreach ($sections as $label => $value) {
        echo '<tr><th>' . htmlspecialchars($label) . '</th><td>' . nl2br(htmlspecialchars($value)) . '</td></tr>';
    }
    echo '</table>';
}

<?php
require_once __DIR__ . '/nurse_medical_fields.php';

function nurse_clinical_column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $result && $result->num_rows > 0;
}

function nurse_clinical_ensure_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS medical_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        author_id INT NOT NULL,
        title VARCHAR(220) NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_med_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $extra = [
        'temperature' => 'VARCHAR(40)',
        'weight' => 'VARCHAR(40)',
        'notes' => 'TEXT',
        'diagnosis' => 'TEXT',
        'doctor_notes' => 'TEXT',
        'treatment' => 'TEXT',
        'medical_history' => 'TEXT',
        'vital_signs' => 'TEXT',
        'allergies' => 'TEXT',
        'patient_progress' => 'TEXT',
    ];
    foreach ($extra as $column => $type) {
        if (!nurse_clinical_column_exists($conn, 'medical_records', $column)) {
            $conn->query("ALTER TABLE medical_records ADD COLUMN `$column` $type NULL");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS lab_result_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        author_id INT NOT NULL,
        lab_service_id INT NULL,
        test_name VARCHAR(255) NOT NULL,
        result_text TEXT,
        result_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_labres_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function nurse_clinical_patient_exists(mysqli $conn, int $patientId): bool {
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
    $stmt->bind_param('i', $patientId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function nurse_clinical_save_medical(mysqli $conn, int $patientId, int $authorId, array $fields): array {
    nurse_clinical_ensure_schema($conn);
    $title = trim((string) ($fields['title'] ?? ''));
    $temperature = trim((string) ($fields['temperature'] ?? ''));
    $weight = trim((string) ($fields['weight'] ?? ''));
    $notes = trim((string) ($fields['notes'] ?? ''));

    if ($title === '') {
        $title = 'Medical note';
    }
    if ($temperature === '' && $weight === '' && $notes === '') {
        return ['ok' => false, 'error' => 'Please enter medical note details.'];
    }

    $stmt = $conn->prepare("INSERT INTO medical_records
        (patient_id, author_id, title, content, temperature, weight, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return ['ok' => false, 'error' => 'Could not prepare medical note.'];
    }
    $stmt->bind_param('iisssss', $patientId, $authorId, $title, $notes, $temperature, $weight, $notes);
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Failed to save medical note.'];
    }
    $id = (int) $conn->insert_id;
    $stmt->close();
    return ['ok' => true, 'message' => 'Medical note saved.', 'id' => $id];
}

function nurse_clinical_save_lab(mysqli $conn, int $patientId, int $authorId, string $name, string $text, string $date, int $serviceId = 0): array {
    nurse_clinical_ensure_schema($conn);
    $name = trim($name);
    $text = trim($text);
    $date = trim($date);
    if ($name === '' || $date === '') {
        return ['ok' => false, 'error' => 'Test name and result date are required.'];
    }
    if (strtotime($date) === false) {
        return ['ok' => false, 'error' => 'Invalid result date.'];
    }
    if ($serviceId > 0) {
        $stmt = $conn->prepare('INSERT INTO lab_result_entries (patient_id, author_id, lab_service_id, test_name, result_text, result_date) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiisss', $patientId, $authorId, $serviceId, $name, $text, $date);
    } else {
        $stmt = $conn->prepare('INSERT INTO lab_result_entries (patient_id, author_id, lab_service_id, test_name, result_text, result_date) VALUES (?, ?, NULL, ?, ?, ?)');
        $stmt->bind_param('iisss', $patientId, $authorId, $name, $text, $date);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'Failed to save lab result.'];
    }
    $id = (int) $conn->insert_id;
    $stmt->close();
    return ['ok' => true, 'message' => 'Lab result saved.', 'id' => $id];
}

function nurse_clinical_fetch_medical(mysqli $conn, int $id): ?array {
    nurse_clinical_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT m.*, p.full_name AS patient_name, p.date_of_birth, p.gender, p.phone, p.email, u.full_name AS author_name
        FROM medical_records m
        JOIN users p ON p.id = m.patient_id
        LEFT JOIN users u ON u.id = m.author_id
        WHERE m.id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function nurse_clinical_fetch_lab(mysqli $conn, int $id): ?array {
    nurse_clinical_ensure_schema($conn);
    $stmt = $conn->prepare("SELECT l.*, p.full_name AS patient_name, p.date_of_birth, p.gender, p.phone, p.email, u.full_name AS author_name
        FROM lab_result_entries l
        JOIN users p ON p.id = l.patient_id
        LEFT JOIN users u ON u.id = l.author_id
        WHERE l.id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function nurse_clinical_export_filename(string $prefix, string $patientName, string $dateStamp): string {
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($patientName)) ?: 'Patient';
    return $prefix . '_' . $safe . '_' . $dateStamp . '.html';
}

function nurse_clinical_report_date(?string $value, string $format = 'F j, Y'): string {
    if (trim((string) $value) === '' || trim((string) $value) === '0000-00-00') {
        return 'Not recorded';
    }
    $stamp = strtotime((string) $value);
    return $stamp ? date($format, $stamp) : 'Not recorded';
}

function nurse_clinical_report_age(?string $dateOfBirth): string {
    $dateOfBirth = trim((string) $dateOfBirth);
    if ($dateOfBirth === '' || $dateOfBirth === '0000-00-00') {
        return 'Not recorded';
    }

    try {
        return (string) (new DateTime($dateOfBirth))->diff(new DateTime('today'))->y;
    } catch (Exception $e) {
        return 'Not recorded';
    }
}

function nurse_clinical_lab_report_rows(string $testName, string $resultText): array {
    $lines = preg_split('/\R/', trim($resultText)) ?: [];
    $rows = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '|') === false) {
            continue;
        }

        $parts = array_map('trim', explode('|', $line));
        $rows[] = [
            'test' => $parts[0] ?? '',
            'result' => $parts[1] ?? '',
            'range' => $parts[2] ?? '',
            'unit' => $parts[3] ?? '',
        ];
    }

    if ($rows) {
        return $rows;
    }

    return [[
        'test' => $testName,
        'result' => trim($resultText) !== '' ? trim($resultText) : 'No result details recorded.',
        'range' => '',
        'unit' => '',
    ]];
}

function nurse_clinical_render_export_html(array $data, string $type): string {
    $isLab = $type === 'lab';
    $title = $isLab ? 'Laboratory Report' : 'Medical Record';
    $recordDate = $isLab ? ($data['result_date'] ?? '') : ($data['created_at'] ?? '');
    $patientId = (int) ($data['patient_id'] ?? 0);
    $labRows = $isLab
        ? nurse_clinical_lab_report_rows((string) ($data['test_name'] ?? ''), (string) ($data['result_text'] ?? ''))
        : [];
    $medicalRows = $isLab ? [] : array_merge([
        'Record Title' => $data['title'] ?? '',
    ], nurse_medical_sections_for_display($data));

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>
<style>
@page{size:A4;margin:12mm}
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;color:#163744;background:#edf4f6;margin:0}
.sheet{width:190mm;min-height:273mm;margin:18px auto;background:#fff;padding:10mm 12mm 12mm;box-shadow:0 16px 40px rgba(7,59,76,.12)}
.letterhead{display:grid;grid-template-columns:76px minmax(0,1fr) auto;gap:16px;align-items:center;padding-bottom:14px}
.logo{width:68px;height:68px;border:3px solid #55c6d8;border-radius:50%;object-fit:cover}
.clinic-name{margin:0 0 5px;color:#073b4c;font-size:21px;line-height:1.2}
.clinic-details{margin:0;color:#58717d;font-size:11px;line-height:1.5}
.document-id{text-align:right;color:#58717d;font-size:11px;line-height:1.6}
.document-id strong{display:block;color:#073b4c;font-size:12px}
.report-band{margin:0 0 17px;padding:7px 12px;background:#138f91;color:#fff;text-align:center;font-size:15px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.patient-grid{display:grid;grid-template-columns:1fr 1fr;gap:7px 28px;margin-bottom:22px}
.patient-field{display:grid;grid-template-columns:105px minmax(0,1fr);gap:8px;padding-bottom:5px;border-bottom:1px solid #e1eaed;font-size:12px}
.patient-field span{color:#647985;font-weight:700}
.patient-field strong{color:#163744;overflow-wrap:anywhere}
.report-title{margin:0 0 14px;color:#08787b;text-align:center;font-size:17px;letter-spacing:.08em;text-transform:uppercase}
.result-table{width:100%;border-collapse:collapse;margin-bottom:24px;page-break-inside:auto}
.result-table th{padding:9px 10px;border-top:2px solid #138f91;border-bottom:1px solid #93cfd0;background:#eef8f8;color:#075e60;font-size:11px;text-align:left;text-transform:uppercase}
.result-table td{padding:10px;border-bottom:1px solid #dce8eb;color:#203f4b;font-size:12px;line-height:1.5;vertical-align:top;white-space:pre-wrap}
.result-table tr{page-break-inside:avoid}
.medical-table th{width:29%}
.empty-cell{color:#97a6ad}
.signoff{display:flex;justify-content:flex-end;margin-top:34px}
.signature{min-width:245px;padding-top:9px;border-top:1px solid #78909b;text-align:center}
.signature strong{display:block;color:#073b4c;font-size:13px}
.signature span{display:block;margin-top:4px;color:#637883;font-size:11px}
.footer{display:flex;justify-content:space-between;gap:20px;margin-top:36px;padding-top:10px;border-top:1px solid #dce7eb;color:#71848d;font-size:9px}
@media(max-width:760px){.sheet{width:100%;min-height:0;margin:0;padding:20px}.letterhead{grid-template-columns:58px 1fr}.logo{width:54px;height:54px}.document-id{grid-column:1/-1;text-align:left}.patient-grid{grid-template-columns:1fr}}
@media print{body{background:#fff}.sheet{width:auto;min-height:auto;margin:0;padding:0;box-shadow:none}}
</style>
</head>
<body>
<main class="sheet">
  <div class="letterhead">
    <img src="globalife.png" class="logo" alt="Clinic logo">
    <div>
      <h1 class="clinic-name">Globalife Medical Laboratory &amp; Polyclinic</h1>
      <p class="clinic-details">Barangay Sahud Ulan, Tanza, Cavite<br>Professional medical and laboratory services</p>
    </div>
    <div class="document-id">
      <strong>Patient ID: GL-<?php echo str_pad((string) $patientId, 5, '0', STR_PAD_LEFT); ?></strong>
      Report date: <?php echo htmlspecialchars(nurse_clinical_report_date($recordDate)); ?>
    </div>
  </div>
  <div class="report-band"><?php echo htmlspecialchars($title); ?></div>
  <div class="patient-grid">
    <div class="patient-field"><span>Patient name</span><strong><?php echo htmlspecialchars((string) ($data['patient_name'] ?? 'N/A')); ?></strong></div>
    <div class="patient-field"><span>Age</span><strong><?php echo htmlspecialchars(nurse_clinical_report_age($data['date_of_birth'] ?? null)); ?></strong></div>
    <div class="patient-field"><span>Date of birth</span><strong><?php echo htmlspecialchars(nurse_clinical_report_date($data['date_of_birth'] ?? null)); ?></strong></div>
    <div class="patient-field"><span>Sex</span><strong><?php echo htmlspecialchars(ucfirst((string) ($data['gender'] ?? 'Not recorded'))); ?></strong></div>
    <div class="patient-field"><span>Contact</span><strong><?php echo htmlspecialchars((string) ($data['phone'] ?? 'Not recorded')); ?></strong></div>
    <div class="patient-field"><span>Prepared by</span><strong><?php echo htmlspecialchars((string) ($data['author_name'] ?? 'Clinic staff')); ?></strong></div>
  </div>

  <?php if ($isLab): ?>
    <h2 class="report-title"><?php echo htmlspecialchars((string) ($data['test_name'] ?? 'Laboratory Result')); ?></h2>
    <table class="result-table">
      <thead><tr><th>Test name</th><th>Result</th><th>Reference range</th><th>Units</th></tr></thead>
      <tbody>
      <?php foreach ($labRows as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars((string) $row['test']); ?></td>
          <td><?php echo htmlspecialchars((string) $row['result']); ?></td>
          <td class="<?php echo $row['range'] === '' ? 'empty-cell' : ''; ?>"><?php echo htmlspecialchars($row['range'] !== '' ? (string) $row['range'] : 'Not provided'); ?></td>
          <td class="<?php echo $row['unit'] === '' ? 'empty-cell' : ''; ?>"><?php echo htmlspecialchars($row['unit'] !== '' ? (string) $row['unit'] : 'Not provided'); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <h2 class="report-title"><?php echo htmlspecialchars((string) ($data['title'] ?? 'Medical Record')); ?></h2>
    <table class="result-table medical-table">
      <thead><tr><th>Clinical section</th><th>Findings and notes</th></tr></thead>
      <tbody>
      <?php foreach ($medicalRows as $label => $value): ?>
        <tr><td><?php echo htmlspecialchars((string) $label); ?></td><td><?php echo nl2br(htmlspecialchars(trim((string) $value) !== '' ? (string) $value : 'Not recorded')); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="signoff">
    <div class="signature">
      <strong><?php echo htmlspecialchars((string) ($data['author_name'] ?? 'Clinic staff')); ?></strong>
      <span>Prepared and recorded by Globalife clinic staff</span>
    </div>
  </div>
  <div class="footer">
    <span>Generated by the Globalife Clinic System. Keep this medical document confidential.</span>
    <span><?php echo htmlspecialchars(date('F j, Y g:i A')); ?></span>
  </div>
</main>
</body>
</html>
    <?php
    return (string) ob_get_clean();
}

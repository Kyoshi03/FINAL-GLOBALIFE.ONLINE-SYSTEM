<?php
mysqli_report(MYSQLI_REPORT_OFF);

require_once __DIR__ . '/../includes/name_parts.php';

function dbIsLiveHost(): bool {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    return $host !== ''
        && strpos($host, 'localhost') === false
        && strpos($host, '127.0.0.1') === false
        && strpos($host, '::1') === false;
}

// Database configuration. On hosting, create config/production.php using
// production.example.php as a guide. Local XAMPP keeps using these defaults.
$productionConfig = [];
$productionConfigPath = __DIR__ . '/production.php';
$isLiveHost = dbIsLiveHost();
$hasProductionConfig = $isLiveHost && is_file($productionConfigPath);
if ($hasProductionConfig) {
    $loadedConfig = require $productionConfigPath;
    if (is_array($loadedConfig)) {
        $productionConfig = $loadedConfig;
    }
}

function dbConfigValue(array $config, string $key, string $envName, string $fallback): string {
    if (array_key_exists($key, $config) && trim((string) $config[$key]) !== '') {
        return (string) $config[$key];
    }

    $envValue = getenv($envName);
    if ($envValue !== false && trim((string) $envValue) !== '') {
        return (string) $envValue;
    }

    return $fallback;
}

define('DB_HOST', dbConfigValue($productionConfig, 'host', 'DB_HOST', 'localhost'));
define('DB_USER', dbConfigValue($productionConfig, 'user', 'DB_USER', 'root'));
define('DB_PASS', dbConfigValue($productionConfig, 'pass', 'DB_PASS', ''));
define('DB_NAME', dbConfigValue($productionConfig, 'name', 'DB_NAME', 'clinic1_db'));
define('DB_HAS_PRODUCTION_CONFIG', $hasProductionConfig);
define('DB_ENVIRONMENT', $isLiveHost ? 'hosting' : 'local');

function dbHasPlaceholderCredentials(): bool {
    $password = trim(DB_PASS);
    return DB_ENVIRONMENT === 'hosting'
        && (
            $password === ''
            || stripos($password, 'PALITAN_MO') !== false
            || stripos($password, 'HOSTINGER_DATABASE_PASSWORD') !== false
            || stripos($password, 'your_hostinger') !== false
            || stripos($password, 'MYSQL_PASSWORD_HERE') !== false
        );
}

function renderDatabaseSetupError(string $title, string $message): void {
    http_response_code(500);
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeDb = htmlspecialchars(DB_NAME, ENT_QUOTES, 'UTF-8');
    $safeUser = htmlspecialchars(DB_USER, ENT_QUOTES, 'UTF-8');
    $safeHost = htmlspecialchars(DB_HOST, ENT_QUOTES, 'UTF-8');
    $settingsDetails = DB_ENVIRONMENT === 'hosting'
        ? '<p>For security, database credentials are hidden on the live website.</p>'
        : "<p>Host: <code>{$safeHost}</code><br>User: <code>{$safeUser}</code><br>Database: <code>{$safeDb}</code></p>";

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle}</title>
    <style>
        body{margin:0;font-family:Arial,sans-serif;background:#eef7fc;color:#073b4c;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
        .box{max-width:760px;background:#fff;border:1px solid #cfe4f1;border-radius:14px;padding:28px;box-shadow:0 16px 36px rgba(20,79,123,.14)}
        h1{margin:0 0 12px;color:#0b4f80;font-size:28px}
        p{font-size:17px;line-height:1.55;color:#526b7a}
        code{background:#f2f7fb;border:1px solid #dbeaf3;border-radius:6px;padding:2px 6px;color:#073b4c}
        .hint{margin-top:18px;padding:16px;border-radius:10px;background:#f6fbff;border:1px dashed #bdd9eb}
    </style>
</head>
<body>
    <main class="box">
        <h1>{$safeTitle}</h1>
        <p>{$safeMessage}</p>
        <div class="hint">
            <p><strong>Database configuration:</strong></p>
            {$settingsDetails}
            <p>Create or update <code>config/production.php</code> with the exact Hostinger database name, user, password, and host.</p>
        </div>
    </main>
</body>
</html>
HTML;
    exit;
}

// Create database connection
function getDBConnection() {
    static $schemaInitialized = false;

    if (dbIsLiveHost() && !DB_HAS_PRODUCTION_CONFIG) {
        renderDatabaseSetupError(
            'Missing Hostinger database config',
            'The live website is still using the local XAMPP database defaults. Upload config/production.php with the real Hostinger database credentials.'
        );
    }

    if (dbHasPlaceholderCredentials()) {
        renderDatabaseSetupError(
            'Hostinger database password required',
            'The production database password has not been configured. In Hostinger hPanel, open Databases > Management, reset or copy the password for the assigned MySQL user, then place that exact password in config/production.php.'
        );
    }

    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        if ((int) $conn->connect_errno === 1049) {
            if (!initDatabase()) {
                renderDatabaseSetupError(
                    'Database setup needed',
                    'The configured database was not found, and the system could not create it automatically on the hosting server.'
                );
            }
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        }

        if ($conn->connect_error) {
            $connectionMessage = DB_ENVIRONMENT === 'hosting'
                ? 'The hosting database rejected the configured credentials. Confirm that the MySQL username, database name, and database-user password in config/production.php exactly match Hostinger hPanel.'
                : 'The system could not connect to the database: ' . $conn->connect_error;
            renderDatabaseSetupError(
                'Database connection failed',
                $connectionMessage
            );
        }
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");

    if (!$schemaInitialized) {
        if (!initDatabase()) {
            renderDatabaseSetupError(
                'Database tables not ready',
                'The database connected, but the system could not create or verify the required tables.'
            );
        }
        $schemaInitialized = true;
    }
    
    return $conn;
}

function dbIsMissingInEngine(Throwable $e): bool {
    $message = strtolower($e->getMessage());
    return strpos($message, "doesn't exist in engine") !== false
        || strpos($message, "does not exist in engine") !== false;
}

function dbTableWorks(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);

    try {
        $result = $conn->query("SHOW COLUMNS FROM `$table`");
        return $result !== false;
    } catch (mysqli_sql_exception $e) {
        if (dbIsMissingInEngine($e)) {
            return false;
        }

        if ((int) $e->getCode() === 1146) {
            return true;
        }

        throw $e;
    }
}

function dbUsersFullNameExpression(): string {
    return "TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(middle_name, ''), NULLIF(last_name, ''), NULLIF(suffix, '')))";
}

function dbBackfillUsersNameParts(mysqli $conn): void {
    $requiredColumns = ['full_name', 'first_name', 'middle_name', 'last_name', 'suffix'];
    foreach ($requiredColumns as $column) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");
        if (!$check || $check->num_rows === 0) {
            return;
        }
    }

    $result = $conn->query("SELECT id, full_name, first_name, middle_name, last_name, suffix FROM users ORDER BY id ASC");
    if (!$result) {
        return;
    }

    $update = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, suffix = ? WHERE id = ?");
    if (!$update) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $parsed = clinic_name_split_full_name((string) ($row['full_name'] ?? ''));

        $firstName = trim((string) ($row['first_name'] ?? ''));
        $middleName = trim((string) ($row['middle_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));
        $suffix = trim((string) ($row['suffix'] ?? ''));

        $newFirstName = $firstName !== '' ? $firstName : $parsed['first_name'];
        $newMiddleName = $middleName !== '' ? $middleName : $parsed['middle_name'];
        $newLastName = $lastName !== '' ? $lastName : $parsed['last_name'];
        $newSuffix = $suffix !== '' ? $suffix : $parsed['suffix'];

        if ($newFirstName === $firstName && $newMiddleName === $middleName && $newLastName === $lastName && $newSuffix === $suffix) {
            continue;
        }

        $id = (int) $row['id'];
        $update->bind_param('ssssi', $newFirstName, $newMiddleName, $newLastName, $newSuffix, $id);
        $update->execute();
    }

    $update->close();
}

function dbEnsureGeneratedUsersFullNameColumn(mysqli $conn): void {
    $columnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
    $columnInfo = $columnResult ? $columnResult->fetch_assoc() : null;
    $generatedDefinition = "VARCHAR(100) GENERATED ALWAYS AS (" . dbUsersFullNameExpression() . ") STORED";

    if (!$columnInfo) {
        $conn->query("ALTER TABLE users ADD COLUMN full_name {$generatedDefinition} AFTER suffix");
        return;
    }

    $extra = strtolower((string) ($columnInfo['Extra'] ?? ''));
    if (strpos($extra, 'generated') === false) {
        dbBackfillUsersNameParts($conn);
        $conn->query("ALTER TABLE users MODIFY COLUMN full_name {$generatedDefinition} AFTER suffix");
    }
}

function dbDropTableIfPresent(mysqli $conn, string $table): void {
    $table = $conn->real_escape_string($table);

    try {
        $conn->query("DROP TABLE IF EXISTS `$table`");
    } catch (mysqli_sql_exception $e) {
        if (!dbIsMissingInEngine($e)) {
            throw $e;
        }
    }
}

function repairBrokenEngineTables(mysqli $conn): void {
    $knownTables = [
        'appointment_services',
        'lab_result_entries',
        'medical_records',
        'password_reset_codes',
        'appointments',
        'lab_categories',
        'lab_services',
        'doctor_availability',
        'users',
    ];

    foreach ($knownTables as $table) {
        if (!dbTableWorks($conn, $table)) {
            $conn->query('SET FOREIGN_KEY_CHECKS=0');
            foreach ($knownTables as $dropTable) {
                dbDropTableIfPresent($conn, $dropTable);
            }
            $conn->query('SET FOREIGN_KEY_CHECKS=1');
            return;
        }
    }
}

// Initialize database tables if they don't exist
function initDatabase() {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);
    if ($conn->connect_error) {
        return false;
    }

    $databaseName = str_replace('`', '``', DB_NAME);
    
    // Create database if it doesn't exist
    $conn->query("CREATE DATABASE IF NOT EXISTS `$databaseName`");

    if (!$conn->select_db(DB_NAME)) {
        $conn->close();
        return false;
    }

    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");
    repairBrokenEngineTables($conn);
    
    // Create users table
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        first_name VARCHAR(40) DEFAULT NULL,
        middle_name VARCHAR(10) DEFAULT NULL,
        last_name VARCHAR(40) DEFAULT NULL,
        suffix VARCHAR(10) DEFAULT NULL,
        full_name VARCHAR(100) GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(middle_name, ''), NULLIF(last_name, ''), NULLIF(suffix, '')))) STORED,
        role ENUM('admin', 'patient', 'doctor') NOT NULL,
        email VARCHAR(100),
        phone VARCHAR(20),
        gender ENUM('Male', 'Female', 'Other') DEFAULT NULL,
        date_of_birth DATE DEFAULT NULL,
        age INT DEFAULT NULL,
        civil_status VARCHAR(20) DEFAULT NULL,
        address VARCHAR(255) DEFAULT NULL,
        barangay VARCHAR(100) DEFAULT NULL,
        city VARCHAR(100) DEFAULT NULL,
        emergency_contact_name VARCHAR(100) DEFAULT NULL,
        emergency_contact_relationship VARCHAR(50) DEFAULT NULL,
        emergency_contact_number VARCHAR(20) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $conn->query("UPDATE users SET role = 'admin' WHERE role = 'receptionist'");
    $conn->query("UPDATE users SET role = 'doctor' WHERE role = 'nurse'");
    $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'patient', 'doctor') NOT NULL");
    
    // Add new columns if they don't exist (for existing databases)
    $columns_to_add = [
        ['first_name', "VARCHAR(40) DEFAULT NULL", 'password'],
        ['middle_name', "VARCHAR(10) DEFAULT NULL", 'first_name'],
        ['last_name', "VARCHAR(40) DEFAULT NULL", 'middle_name'],
        ['suffix', "VARCHAR(10) DEFAULT NULL", 'last_name'],
        ['gender', "ENUM('Male', 'Female', 'Other') DEFAULT NULL", 'phone'],
        ['date_of_birth', 'DATE DEFAULT NULL', 'gender'],
        ['age', 'INT DEFAULT NULL', 'date_of_birth'],
        ['civil_status', 'VARCHAR(20) DEFAULT NULL', 'age'],
        ['address', 'VARCHAR(255) DEFAULT NULL', 'civil_status'],
        ['barangay', 'VARCHAR(100) DEFAULT NULL', 'address'],
        ['city', 'VARCHAR(100) DEFAULT NULL', 'barangay'],
        ['emergency_contact_name', 'VARCHAR(100) DEFAULT NULL', 'city'],
        ['emergency_contact_relationship', 'VARCHAR(50) DEFAULT NULL', 'emergency_contact_name'],
        ['emergency_contact_number', 'VARCHAR(20) DEFAULT NULL', 'emergency_contact_relationship']
    ];
    
    foreach ($columns_to_add as $col) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '{$col[0]}'");
        if ($check->num_rows == 0) {
            $after = !empty($col[2]) ? "AFTER {$col[2]}" : "";
            $conn->query("ALTER TABLE users ADD COLUMN {$col[0]} {$col[1]} {$after}");
        }
    }

    dbEnsureGeneratedUsersFullNameColumn($conn);

    $emailColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'email'");
    $emailInfo = $emailColumn ? $emailColumn->fetch_assoc() : null;
    if ($emailInfo && strtoupper((string) ($emailInfo['Null'] ?? '')) !== 'YES') {
        $conn->query("ALTER TABLE users MODIFY email VARCHAR(100) DEFAULT NULL");
    }
    
    // Create appointments table
    $conn->query("CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        doctor_id INT,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
    )");
    
    initLabBookingSchema($conn);
    
    $conn->close();
    return true;
}

function lab_sync_categories_from_services(mysqli $conn): void {
    $conn->query("INSERT IGNORE INTO lab_categories (name, sort_order)
        SELECT DISTINCT TRIM(category), 0 FROM lab_services
        WHERE category IS NOT NULL AND TRIM(category) <> ''");
}

function dbColumnExists($conn, $table, $column) {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}

function initLabBookingSchema($conn) {
    $apptCols = [
        ['booking_type', "ENUM('package','individual','consultation','ultrasound') DEFAULT NULL", 'notes'],
        ['total_display_price', 'DECIMAL(10,2) DEFAULT NULL', 'booking_type'],
        ['price_channel', "ENUM('opd','home') DEFAULT 'opd'", 'total_display_price'],
    ];
    foreach ($apptCols as $col) {
        if (!dbColumnExists($conn, 'appointments', $col[0])) {
            $after = !empty($col[2]) ? "AFTER `{$col[2]}`" : '';
            $conn->query("ALTER TABLE appointments ADD COLUMN `{$col[0]}` {$col[1]} {$after}");
        }
    }
    $bookingTypeColumn = $conn->query("SHOW COLUMNS FROM appointments LIKE 'booking_type'");
    $bookingTypeDefinition = $bookingTypeColumn ? $bookingTypeColumn->fetch_assoc() : null;
    if (
        $bookingTypeDefinition
        && (
            stripos((string) ($bookingTypeDefinition['Type'] ?? ''), 'consultation') === false
            || stripos((string) ($bookingTypeDefinition['Type'] ?? ''), 'ultrasound') === false
        )
    ) {
        $conn->query("ALTER TABLE appointments MODIFY COLUMN booking_type ENUM('package','individual','consultation','ultrasound') DEFAULT NULL");
    }
    $conn->query(
        "UPDATE appointments
         SET notes = REPLACE(notes, 'Est. total:', 'Total:')
         WHERE notes LIKE '%Est. total:%'"
    );
    
    $conn->query("CREATE TABLE IF NOT EXISTS lab_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        category VARCHAR(120) NOT NULL,
        description TEXT,
        included_tests TEXT,
        opd_price DECIMAL(10,2) NOT NULL DEFAULT 0,
        home_service_price DECIMAL(10,2) DEFAULT NULL,
        is_package TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    $conn->query("CREATE TABLE IF NOT EXISTS appointment_services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        service_id INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
        FOREIGN KEY (service_id) REFERENCES lab_services(id) ON DELETE RESTRICT,
        INDEX idx_appt (appointment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS lab_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(220) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_lab_cat_name (name(190))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (dbColumnExists($conn, 'lab_services', 'category')) {
        $conn->query("ALTER TABLE lab_services MODIFY category VARCHAR(220) NOT NULL");
    }

    lab_sync_categories_from_services($conn);
    
    $cnt = $conn->query('SELECT COUNT(*) AS c FROM lab_services');
    if ($cnt && ($row = $cnt->fetch_assoc()) && (int)$row['c'] === 0) {
        seedDefaultLabServices($conn);
    }

    require_once __DIR__ . '/../includes/doctor_schedule.php';
    init_doctor_schema_and_accounts($conn);
    initNurseClinicalSchema($conn);
}

function initNurseClinicalSchema(mysqli $conn): void {
    if (!dbColumnExists($conn, 'users', 'is_active')) {
        $conn->query('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
    }
    $conn->query("CREATE TABLE IF NOT EXISTS medical_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        author_id INT NOT NULL,
        title VARCHAR(220) NOT NULL,
        content TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_med_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS lab_result_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        author_id INT NOT NULL,
        lab_service_id INT NULL,
        test_name VARCHAR(255) NOT NULL,
        result_text TEXT,
        result_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (lab_service_id) REFERENCES lab_services(id) ON DELETE SET NULL,
        INDEX idx_labres_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS password_reset_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        email VARCHAR(100) NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_reset_email (email),
        INDEX idx_reset_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS appointment_email_reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        appointment_id INT NOT NULL,
        reminder_type VARCHAR(30) NOT NULL DEFAULT '24_hours',
        scheduled_for DATETIME NOT NULL,
        email_sent_at DATETIME DEFAULT NULL,
        sms_sent_at DATETIME DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(500) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
        UNIQUE KEY uq_appointment_reminder (appointment_id, reminder_type),
        INDEX idx_reminders_due (sent_at, scheduled_for)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!dbColumnExists($conn, 'appointment_email_reminders', 'email_sent_at')) {
        $conn->query("ALTER TABLE appointment_email_reminders ADD COLUMN email_sent_at DATETIME DEFAULT NULL AFTER scheduled_for");
    }
    if (!dbColumnExists($conn, 'appointment_email_reminders', 'sms_sent_at')) {
        $conn->query("ALTER TABLE appointment_email_reminders ADD COLUMN sms_sent_at DATETIME DEFAULT NULL AFTER email_sent_at");
    }
}

function seedDefaultLabServices($conn) {
    require_once __DIR__ . '/../includes/lab_services_seed_data.php';
    lab_insert_catalog_rows($conn, lab_catalog_seed_rows());
}

/**
 * Insert catalog rows (used by seed + admin import). Skips when same name+category+is_package exists.
 */
function lab_insert_catalog_rows(mysqli $conn, array $rows): int {
    $check = $conn->prepare('SELECT id FROM lab_services WHERE name = ? AND category = ? AND is_package = ? LIMIT 1');
    $insNull = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?,?,?,?,?,NULL,?,1)');
    $insHome = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?,?,?,?,?,?,?,1)');
    $added = 0;
    foreach ($rows as $r) {
        $name = $r[0];
        $cat = $r[1];
        $desc = $r[2] ?? '';
        $incl = $r[3] ?? '';
        $opd = (float) $r[4];
        $home = $r[5];
        $isPkg = (int) ($r[6] ?? 0);
        $check->bind_param('ssi', $name, $cat, $isPkg);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            continue;
        }
        if ($home === null || $home === '') {
            $insNull->bind_param('ssssdi', $name, $cat, $desc, $incl, $opd, $isPkg);
            $insNull->execute();
        } else {
            $hv = (float) $home;
            $insHome->bind_param('ssssddi', $name, $cat, $desc, $incl, $opd, $hv, $isPkg);
            $insHome->execute();
        }
        $added++;
    }
    $check->close();
    $insNull->close();
    $insHome->close();
    return $added;
}

// Database tables are initialized only when a real connection is requested.

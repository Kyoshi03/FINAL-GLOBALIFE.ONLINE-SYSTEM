<?php
require_once __DIR__ . '/includes/name_parts.php';

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'clinic1_db');

// Create database connection
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}

function clinic_db_users_full_name_expression(): string {
    return "TRIM(CONCAT_WS(' ', NULLIF(first_name, ''), NULLIF(middle_name, ''), NULLIF(last_name, ''), NULLIF(suffix, '')))";
}

function clinic_db_backfill_users_name_parts(mysqli $conn): void {
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

function clinic_db_ensure_generated_full_name(mysqli $conn): void {
    $columnResult = $conn->query("SHOW COLUMNS FROM users LIKE 'full_name'");
    $columnInfo = $columnResult ? $columnResult->fetch_assoc() : null;
    $generatedDefinition = "VARCHAR(100) GENERATED ALWAYS AS (" . clinic_db_users_full_name_expression() . ") STORED";

    if (!$columnInfo) {
        $conn->query("ALTER TABLE users ADD COLUMN full_name {$generatedDefinition} AFTER suffix");
        return;
    }

    $extra = strtolower((string) ($columnInfo['Extra'] ?? ''));
    if (strpos($extra, 'generated') === false) {
        clinic_db_backfill_users_name_parts($conn);
        $conn->query("ALTER TABLE users MODIFY COLUMN full_name {$generatedDefinition} AFTER suffix");
    }
}

// Initialize database tables if they don't exist
function initDatabase() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    // Create database if it doesn't exist
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    $conn->select_db(DB_NAME);
    
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

    clinic_db_ensure_generated_full_name($conn);
    
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
    $conn->query("UPDATE users SET role = 'admin' WHERE role = 'receptionist'");
    $conn->query("UPDATE users SET role = 'doctor' WHERE role = 'nurse'");
    $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'patient', 'doctor') NOT NULL");

    $bookingTypeColumn = $conn->query("SHOW COLUMNS FROM appointments LIKE 'booking_type'");
    $bookingTypeDefinition = $bookingTypeColumn ? $bookingTypeColumn->fetch_assoc() : null;
    if (!$bookingTypeDefinition) {
        $conn->query("ALTER TABLE appointments ADD COLUMN booking_type ENUM('package','individual','consultation','ultrasound') DEFAULT NULL AFTER notes");
    } elseif (
        stripos((string) ($bookingTypeDefinition['Type'] ?? ''), 'ultrasound') === false
        || stripos((string) ($bookingTypeDefinition['Type'] ?? ''), 'consultation') === false
    ) {
        $conn->query("ALTER TABLE appointments MODIFY COLUMN booking_type ENUM('package','individual','consultation','ultrasound') DEFAULT NULL");
    }
    $totalDisplayColumn = $conn->query("SHOW COLUMNS FROM appointments LIKE 'total_display_price'");
    if (!$totalDisplayColumn || !$totalDisplayColumn->fetch_assoc()) {
        $conn->query("ALTER TABLE appointments ADD COLUMN total_display_price DECIMAL(10,2) DEFAULT NULL AFTER booking_type");
    }
    $priceChannelColumn = $conn->query("SHOW COLUMNS FROM appointments LIKE 'price_channel'");
    if (!$priceChannelColumn || !$priceChannelColumn->fetch_assoc()) {
        $conn->query("ALTER TABLE appointments ADD COLUMN price_channel ENUM('opd','home') DEFAULT 'opd' AFTER total_display_price");
    }
    
    $conn->close();
}

// Call initDatabase on first load
initDatabase();
?>


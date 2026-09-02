<?php
/**
 * Database Setup Script for Globalife Medical Laboratory & Polyclinic
 * Run this file once to set up the database: http://localhost/clinic1/setup_database.php
 */

require_once 'config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Setup</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #0077b6; }
        .success { color: #28a745; padding: 10px; background: #d4edda; border-radius: 5px; margin: 10px 0; }
        .error { color: #dc3545; padding: 10px; background: #f8d7da; border-radius: 5px; margin: 10px 0; }
        .info { color: #0077b6; padding: 10px; background: #d1ecf1; border-radius: 5px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #0077b6; color: white; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🗄️ Database Setup - Globalife Medical Laboratory & Polyclinic</h1>";

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    echo "<div class='success'>✓ Connected to MySQL server successfully</div>";
    
    // Create database
    $conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    echo "<div class='success'>✓ Database '" . DB_NAME . "' created/verified</div>";
    
    $conn->select_db(DB_NAME);
    
    // Create users table with all fields
    $createUsersTable = "CREATE TABLE IF NOT EXISTS users (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($createUsersTable)) {
        echo "<div class='success'>✓ Users table created/verified</div>";
    } else {
        throw new Exception("Error creating users table: " . $conn->error);
    }
    
    // Add columns if they don't exist (for existing tables)
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
    
    $columnsAdded = 0;
    foreach ($columns_to_add as $col) {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE '{$col[0]}'");
        if ($check->num_rows == 0) {
            $after = !empty($col[2]) ? "AFTER {$col[2]}" : "";
            if ($conn->query("ALTER TABLE users ADD COLUMN {$col[0]} {$col[1]} {$after}")) {
                echo "<div class='info'>✓ Added column: {$col[0]}</div>";
                $columnsAdded++;
            }
        }
    }
    
    if ($columnsAdded == 0) {
        echo "<div class='info'>ℹ All columns already exist in users table</div>";
    }
    
    // Create appointments table
    $createAppointmentsTable = "CREATE TABLE IF NOT EXISTS appointments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        doctor_id INT,
        appointment_date DATE NOT NULL,
        appointment_time TIME NOT NULL,
        status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
        notes TEXT,
        booking_type ENUM('package','individual','consultation','ultrasound') DEFAULT NULL,
        total_display_price DECIMAL(10,2) DEFAULT NULL,
        price_channel ENUM('opd','home') DEFAULT 'opd',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($createAppointmentsTable)) {
        echo "<div class='success'>✓ Appointments table created/verified</div>";
    } else {
        echo "<div class='error'>⚠ Error creating appointments table: " . $conn->error . "</div>";
    }
    
    // Create indexes (check if they exist first)
    $indexes = [
        ['idx_username', 'users', 'username'],
        ['idx_email', 'users', 'email'],
        ['idx_role', 'users', 'role'],
        ['idx_patient_id', 'appointments', 'patient_id'],
        ['idx_appointment_date', 'appointments', 'appointment_date']
    ];
    
    $indexesCreated = 0;
    foreach ($indexes as $idx) {
        $check = $conn->query("SHOW INDEX FROM {$idx[1]} WHERE Key_name = '{$idx[0]}'");
        if ($check->num_rows == 0) {
            if ($conn->query("CREATE INDEX {$idx[0]} ON {$idx[1]}({$idx[2]})")) {
                $indexesCreated++;
            }
        }
    }
    
    if ($indexesCreated > 0) {
        echo "<div class='success'>✓ Created {$indexesCreated} index(es)</div>";
    } else {
        echo "<div class='info'>ℹ All indexes already exist</div>";
    }
    
    // Show table structure
    echo "<h2>📊 Users Table Structure</h2>";
    echo "<table>";
    echo "<tr><th>Column Name</th><th>Type</th><th>Null</th><th>Default</th></tr>";
    
    $result = $conn->query("DESCRIBE users");
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td><strong>{$row['Field']}</strong></td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if there are any users
    $userCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
    echo "<div class='info'>ℹ Total users in database: <strong>{$userCount}</strong></div>";
    
    if ($userCount == 0) {
        echo "<div class='info'>💡 Tip: Run <a href='init_users.php'>init_users.php</a> to create default user accounts</div>";
    }
    
    echo "<div class='success'><strong>✅ Database setup completed successfully!</strong></div>";
    echo "<p><a href='index.php'>Go to Homepage</a> | <a href='register_patient.php'>Register Patient</a></p>";
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<div class='error'><strong>❌ Error:</strong> " . $e->getMessage() . "</div>";
}

echo "</div></body></html>";
?>


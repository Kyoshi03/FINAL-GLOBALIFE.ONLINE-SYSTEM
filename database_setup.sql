-- Globalife Medical Laboratory & Polyclinic Database Setup
-- Run this SQL script in phpMyAdmin or MySQL command line

CREATE DATABASE IF NOT EXISTS clinic1_db;
USE clinic1_db;

-- Create users table with all required fields
CREATE TABLE IF NOT EXISTS users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create appointments table
CREATE TABLE IF NOT EXISTS appointments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add indexes for better performance (if they don't exist)
-- Note: MySQL doesn't support IF NOT EXISTS for CREATE INDEX, so run these only if indexes don't exist
-- Or use the setup_database.php script which handles this automatically

-- CREATE INDEX idx_username ON users(username);
-- CREATE INDEX idx_email ON users(email);
-- CREATE INDEX idx_role ON users(role);
-- CREATE INDEX idx_patient_id ON appointments(patient_id);
-- CREATE INDEX idx_appointment_date ON appointments(appointment_date);


<?php
/**
 * Initialize default users for the clinic system
 * Run this file once to create default admin, doctor, and patient accounts
 * Default password for all users: password123
 */

require_once 'config/database.php';
require_once __DIR__ . '/includes/name_parts.php';

$conn = getDBConnection();

// Default password (password123) - in production, use stronger passwords
$defaultPassword = password_hash('password123', PASSWORD_DEFAULT);

// Default users
$users = [
    [
        'username' => 'admin',
        'password' => $defaultPassword,
        'first_name' => 'Administrator',
        'middle_name' => '',
        'last_name' => '',
        'suffix' => '',
        'role' => 'admin',
        'email' => 'admin@globalife.com',
        'phone' => '09123456789'
    ],
    [
        'username' => 'doctor1',
        'password' => $defaultPassword,
        'first_name' => 'Roda',
        'middle_name' => '',
        'last_name' => 'Tebelin',
        'suffix' => '',
        'role' => 'doctor',
        'email' => 'doctor1@globalife.com',
        'phone' => '09123456790'
    ],
    [
        'username' => 'receptionist1',
        'password' => $defaultPassword,
        'first_name' => 'Receptionist',
        'middle_name' => '',
        'last_name' => 'User',
        'suffix' => '',
        'role' => 'admin',
        'email' => 'receptionist1@globalife.com',
        'phone' => '09123456791'
    ],
    [
        'username' => 'patient1',
        'password' => $defaultPassword,
        'first_name' => 'Junnie',
        'middle_name' => '',
        'last_name' => 'Abrador',
        'suffix' => '',
        'role' => 'patient',
        'email' => 'patient1@globalife.com',
        'phone' => '09123456792'
    ]
];

$stmt = $conn->prepare("INSERT INTO users (username, password, first_name, middle_name, last_name, suffix, role, email, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($users as $user) {
    // Check if user already exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $checkStmt->bind_param("s", $user['username']);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->bind_param("sssssssss", 
            $user['username'],
            $user['password'],
            $user['first_name'],
            $user['middle_name'],
            $user['last_name'],
            $user['suffix'],
            $user['role'],
            $user['email'],
            $user['phone']
        );
        
        if ($stmt->execute()) {
            echo "User '{$user['username']}' created successfully.<br>";
        } else {
            echo "Error creating user '{$user['username']}': " . $stmt->error . "<br>";
        }
    } else {
        echo "User '{$user['username']}' already exists. Skipping.<br>";
    }
    
    $checkStmt->close();
}

$stmt->close();
$conn->close();

echo "<br><strong>Default users initialized!</strong><br>";
echo "All users have the password: <strong>password123</strong><br>";
echo "<br>You can now login with:<br>";
echo "- admin / password123 (Admin)<br>";
echo "- doctor1 / password123 (Doctor)<br>";
echo "- receptionist1 / password123 (Admin / Reception Desk)<br>";
echo "- patient1 / password123 (Patient)<br>";
?>

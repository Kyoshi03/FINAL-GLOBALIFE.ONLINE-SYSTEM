<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function normalizeUserRole(string $role): string {
    return $role === 'receptionist' ? 'admin' : $role;
}

function dashboardForRole(string $role): string {
    $role = normalizeUserRole($role);
    switch ($role) {
        case 'admin':
            return 'admin.php';
        case 'doctor':
            return 'nurse.php';
        case 'patient':
            return 'patients.php';
        default:
            return 'index.php';
    }
}

function redirectToDashboardForCurrentUser(): void {
    if (!isLoggedIn()) {
        return;
    }

    header('Location: ' . dashboardForRole((string) $_SESSION['user_role']));
    exit();
}

// Check user role
function checkRole($requiredRole) {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
    
    $currentRole = normalizeUserRole((string) $_SESSION['user_role']);
    $requiredRole = normalizeUserRole((string) $requiredRole);
    if ($currentRole !== $requiredRole) {
        header('Location: index.php');
        exit();
    }
}

/** Allow any of the given roles for shared pages. */
function checkAnyRole(array $requiredRoles) {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
    $currentRole = normalizeUserRole((string) $_SESSION['user_role']);
    $requiredRoles = array_map('normalizeUserRole', $requiredRoles);
    if (!in_array($currentRole, $requiredRoles, true)) {
        header('Location: index.php');
        exit();
    }
}

// Get current user data
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role' => normalizeUserRole((string) $_SESSION['user_role'])
    ];
}

// Login function
function login($username, $password) {
    require_once __DIR__ . '/../config/database.php';
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT id, username, password, full_name, role, COALESCE(is_active, 1) AS is_active FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        if ((int) $user['is_active'] !== 1) {
            $stmt->close();
            $conn->close();
            return false;
        }

        if (password_verify($password, $user['password'])) {
            $sessionRole = normalizeUserRole((string) $user['role']);
            if ($user['role'] === 'receptionist') {
                $migrateStmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ? AND role = 'receptionist'");
                if ($migrateStmt) {
                    $migrateStmt->bind_param('i', $user['id']);
                    $migrateStmt->execute();
                    $migrateStmt->close();
                }
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_role'] = $sessionRole;
            
            $stmt->close();
            $conn->close();
            return true;
        }
    }
    
    $stmt->close();
    $conn->close();
    return false;
}

// Logout function
function logout() {
    session_destroy();
    header('Location: index.php');
    exit();
}
?>

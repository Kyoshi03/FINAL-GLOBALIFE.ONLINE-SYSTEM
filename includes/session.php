<?php
// The clinic operates on Philippine time across every page and scheduled job.
date_default_timezone_set('Asia/Manila');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    // Keep sessions in the app's writable storage instead of relying on a host-specific temp path.
    $sessionPath = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0700, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    session_start();
}

/** Return the application-wide CSRF token for the current session. */
function clinic_csrf_token(): string {
    if (empty($_SESSION['_clinic_csrf_token'])) {
        $_SESSION['_clinic_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_clinic_csrf_token'];
}

/** Render a hidden CSRF field for normal HTML forms. */
function clinic_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(clinic_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function clinic_csrf_request_token(): string {
    $postedToken = trim((string) ($_POST['csrf_token'] ?? ''));
    if ($postedToken !== '') {
        return $postedToken;
    }

    return trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
}

function clinic_csrf_token_matches(string $submittedToken): bool {
    if ($submittedToken === '') {
        return false;
    }

    $validTokens = [clinic_csrf_token()];
    // Keep the older page-specific tokens valid while those pages migrate to
    // the shared token, so existing open tabs are not broken.
    foreach ($_SESSION as $key => $value) {
        if (is_string($key) && str_ends_with($key, '_csrf') && is_string($value)) {
            $validTokens[] = $value;
        }
    }

    foreach ($validTokens as $validToken) {
        if ($validToken !== '' && hash_equals($validToken, $submittedToken)) {
            return true;
        }
    }

    return false;
}

/** Reject state-changing requests that did not come from this application. */
function clinic_csrf_guard_request(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $submittedToken = clinic_csrf_request_token();
    if (clinic_csrf_token_matches($submittedToken)) {
        return;
    }

    $wantsJson = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    http_response_code(403);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'The security token expired. Refresh the page and try again.']);
    } else {
        echo 'The security token expired. Refresh the page and try again.';
    }
    exit();
}

/** Inject a token into legacy POST forms that have not been updated explicitly. */
function clinic_csrf_inject_form(string $html): string {
    if (stripos($html, '<form') === false) {
        return $html;
    }

    $field = clinic_csrf_field();
    return preg_replace_callback(
        '/(<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>)(?![^<]*name\s*=\s*["\']csrf_token["\'])/is',
        static function (array $match) use ($field): string {
            return $match[1] . $field;
        },
        $html
    ) ?? $html;
}

ob_start('clinic_csrf_inject_form');

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
            return 'doctor.php';
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

    $username = trim((string) $username);
    $stmt = $conn->prepare("SELECT id, username, password, first_name, middle_name, last_name, suffix, role, COALESCE(is_active, 1) AS is_active FROM users WHERE username = ? LIMIT 1");
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
            $_SESSION['full_name'] = clinic_name_display_from_row($user);
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

clinic_csrf_guard_request();
?>

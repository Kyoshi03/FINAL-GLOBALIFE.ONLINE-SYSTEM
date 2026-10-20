<?php
// The clinic operates on Philippine time across every page and scheduled job.
date_default_timezone_set('Asia/Manila');

/**
 * Resolve the session namespace from the portal being opened. This lets an
 * admin, patient, and doctor keep separate logins in the same browser profile.
 */
function clinic_session_context(): string {
    $forced = strtolower(trim((string) ($_SERVER['CLINIC_SESSION_CONTEXT'] ?? '')));
    if (in_array($forced, ['admin', 'patient', 'doctor', 'public'], true)) {
        return $forced;
    }

    $requested = strtolower(trim((string) ($_GET['portal'] ?? $_POST['portal'] ?? '')));
    if (in_array($requested, ['admin', 'patient', 'doctor', 'public'], true)) {
        return $requested;
    }

    $page = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    if ($page === 'login_admin.php'
        || str_starts_with($page, 'admin_')
        || str_starts_with($page, 'receptionist_')
        || in_array($page, [
            'admin.php',
            'clinic_information.php',
            'doctor_schedules.php',
            'email_sms.php',
            'mark_admin_notifications.php',
            'queue_history.php',
            'register_patient_receptionist.php',
            'slot_management.php',
        ], true)) {
        return 'admin';
    }

    if (str_starts_with($page, 'doctor_')
        || in_array($page, ['doctor.php', 'doctor_export.php', 'change_password.php'], true)) {
        return 'doctor';
    }

    if ($page === 'index.php'
        || str_starts_with($page, 'patient')
        || str_starts_with($page, 'patients')
        || $page === 'book_appointment.php') {
        return 'patient';
    }

    return 'public';
}

function clinic_session_name_for_context(string $context): string {
    return 'CLINIC_' . strtoupper($context) . '_SESSION';
}

function clinic_session_name_for_role(string $role): string {
    $role = normalizeUserRole($role);
    return clinic_session_name_for_context(in_array($role, ['admin', 'patient', 'doctor'], true) ? $role : 'public');
}

function clinic_session_cookie_params(): array {
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || $forwardedProto === 'https';

    return [
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function clinic_is_forced_session_page(): bool {
    $page = strtolower(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
    return in_array($page, ['index.php', 'login.php', 'login_admin.php', 'login_doctor.php'], true)
        || isset($_GET['portal'])
        || isset($_POST['portal']);
}

// Start the session if not already started.
if (session_status() === PHP_SESSION_NONE) {
    // Keep sessions in the app's writable storage instead of relying on a host-specific temp path.
    $sessionPath = __DIR__ . '/../storage/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0700, true);
    }
    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }

    // Protect the login session cookie without breaking local HTTP testing.
    ini_set('session.use_strict_mode', '1');
    // Give users enough time to complete long registration forms before the
    // server garbage-collects their session and invalidates the CSRF token.
    ini_set('session.gc_maxlifetime', '7200');
    $clinicSessionContext = clinic_session_context();
    $clinicSessionName = clinic_session_name_for_context($clinicSessionContext);
    $clinicUseLegacySession = !clinic_is_forced_session_page()
        && !isset($_COOKIE[$clinicSessionName])
        && isset($_COOKIE['PHPSESSID']);
    session_name($clinicUseLegacySession ? 'PHPSESSID' : $clinicSessionName);
    session_set_cookie_params(clinic_session_cookie_params());
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
        '/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>.*?<\/form>/is',
        static function (array $match) use ($field): string {
            $form = $match[0];
            if (preg_match('/<input\b[^>]*\bname\s*=\s*["\']csrf_token["\']/i', $form)) {
                return $form;
            }

            return preg_replace_callback(
                '/<form\b[^>]*>/i',
                static function (array $openingTag) use ($field): string {
                    return $openingTag[0] . $field;
                },
                $form,
                1
            ) ?? $form;
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

/** Move an authenticated login into the role-specific browser session. */
function clinic_switch_session_to_role(string $role): void {
    $targetName = clinic_session_name_for_role($role);
    if (session_name() === $targetName) {
        return;
    }

    $sessionData = $_SESSION;
    $oldName = session_name();
    session_write_close();
    setcookie($oldName, '', array_merge(clinic_session_cookie_params(), ['expires' => time() - 3600]));

    session_name($targetName);
    session_set_cookie_params(clinic_session_cookie_params());
    session_start();
    session_regenerate_id(true);
    $_SESSION = $sessionData;
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
            // Prevent session fixation by issuing a fresh session ID after authentication.
            session_regenerate_id(true);
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
            clinic_switch_session_to_role($sessionRole);
            
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

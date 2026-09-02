<?php
if (!isset($pageTitle)) {
    $pageTitle = "Globalife Medical Laboratory & Polyclinic";
}
$currentUser = isLoggedIn() ? getCurrentUser() : null;
$headerLoginHref = $publicLoginHref ?? 'login.php';
$headerSignUpHref = $publicSignUpHref ?? 'register_patient.php';
$headerPatientPhotoUrl = $headerPatientPhotoUrl ?? null;
$headerPatientInitials = $headerPatientInitials ?? ($profileInitials ?? null);
$headerPatientDisplayName = $headerPatientDisplayName ?? null;
$headerBrandOnly = !empty($headerBrandOnly);
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentPageClass = 'page-' . preg_replace('/[^a-z0-9]+/i', '-', pathinfo($currentPage, PATHINFO_FILENAME) ?: 'app');
$logoHref = 'index.php';
if ($currentPage === 'index.php') {
    $logoHref = '#home';
}
if (isLoggedIn()) {
    $logoHref = dashboardForRole($currentUser['role']);
}
if (
    isLoggedIn()
    && ($currentUser['role'] ?? '') === 'patient'
    && ($headerPatientPhotoUrl === null || $headerPatientInitials === null || $headerPatientDisplayName === null)
) {
    $profileHelperPath = __DIR__ . '/patient_profile_photo.php';
    if (is_file($profileHelperPath)) {
        require_once $profileHelperPath;
    }
    if (function_exists('patientProfileHeaderDetails') && function_exists('getDBConnection')) {
        try {
            $headerProfileConn = getDBConnection();
            $headerProfile = patientProfileHeaderDetails(
                $headerProfileConn,
                (int) ($currentUser['id'] ?? 0),
                (string) ($currentUser['full_name'] ?? 'Patient')
            );
            $headerProfileConn->close();
            $headerPatientPhotoUrl = $headerPatientPhotoUrl ?? $headerProfile['photo_url'];
            $headerPatientInitials = $headerPatientInitials ?? $headerProfile['initials'];
            $headerPatientDisplayName = $headerPatientDisplayName ?? $headerProfile['name'];
        } catch (Throwable $e) {
            $headerPatientInitials = $headerPatientInitials ?? strtoupper(substr((string) ($currentUser['full_name'] ?? 'P'), 0, 1));
            $headerPatientDisplayName = $headerPatientDisplayName ?? (string) ($currentUser['full_name'] ?? 'Patient');
        }
    }
}
$headerPatientNotifications = [];
$headerPatientUnreadNotifications = 0;
if (isLoggedIn() && ($currentUser['role'] ?? '') === 'patient' && function_exists('getDBConnection')) {
    $notificationHelperPath = __DIR__ . '/patient_notifications.php';
    if (is_file($notificationHelperPath)) {
        require_once $notificationHelperPath;
    }
    if (function_exists('fetch_patient_notifications') && function_exists('count_unread_patient_notifications')) {
        try {
            $headerNotificationConn = getDBConnection();
            $headerPatientNotifications = fetch_patient_notifications($headerNotificationConn, (int) ($currentUser['id'] ?? 0), 9);
            $headerPatientUnreadNotifications = count_unread_patient_notifications($headerNotificationConn, (int) ($currentUser['id'] ?? 0));
            $headerNotificationConn->close();
        } catch (Throwable $e) {
            $headerPatientNotifications = [];
            $headerPatientUnreadNotifications = 0;
        }
    }
}
if (isLoggedIn() && ($currentUser['role'] ?? '') === 'admin' && function_exists('getDBConnection')) {
    $adminNotificationHelperPath = __DIR__ . '/admin_notifications.php';
    if (is_file($adminNotificationHelperPath)) {
        require_once $adminNotificationHelperPath;
    }
    if (function_exists('fetch_admin_notifications') && function_exists('count_unread_admin_notifications')) {
        try {
            $headerNotificationConn = getDBConnection();
            $headerPatientNotifications = fetch_admin_notifications($headerNotificationConn, 9);
            $headerPatientUnreadNotifications = count_unread_admin_notifications($headerNotificationConn);
            $headerNotificationConn->close();
        } catch (Throwable $e) {
            $headerPatientNotifications = [];
            $headerPatientUnreadNotifications = 0;
        }
    }
}
if (isLoggedIn() && (string) ($currentUser['role'] ?? '') === 'doctor' && function_exists('getDBConnection')) {
    $clinicNotificationHelperPath = __DIR__ . '/clinic_notifications.php';
    if (is_file($clinicNotificationHelperPath)) {
        require_once $clinicNotificationHelperPath;
    }
    if (function_exists('fetch_clinic_notifications') && function_exists('count_unread_clinic_notifications')) {
        try {
            $headerNotificationConn = getDBConnection();
            $headerPatientNotifications = fetch_clinic_notifications(
                $headerNotificationConn,
                (string) ($currentUser['role'] ?? ''),
                (int) ($currentUser['id'] ?? 0),
                9
            );
            $headerPatientUnreadNotifications = count_unread_clinic_notifications(
                $headerNotificationConn,
                (string) ($currentUser['role'] ?? ''),
                (int) ($currentUser['id'] ?? 0)
            );
            $headerNotificationConn->close();
        } catch (Throwable $e) {
            $headerPatientNotifications = [];
            $headerPatientUnreadNotifications = 0;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="apple-touch-icon" href="globalife.png">
    <link rel="stylesheet" href="main.css">
    <link rel="stylesheet" href="birthday-picker.css">
    <?php if (isset($additionalHeadLinks)): ?>
        <?php echo $additionalHeadLinks; ?>
    <?php endif; ?>
    <?php if (isset($additionalStyles)): ?>
        <style><?php echo $additionalStyles; ?></style>
    <?php endif; ?>
    <style>
    /* Enhanced Professional Header */
    header {
        background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        color: #fff;
        padding: 0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        position: sticky;
        top: 0;
        z-index: 1000;
        transition: all 0.3s ease;
    }
    header.scrolled {
        padding: 5px 0;
        box-shadow: 0 2px 15px rgba(0, 0, 0, 0.15);
    }
    .header-flex {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        padding: 18px 20px;
        max-width: 1400px;
        margin: 0 auto;
    }
    .header-flex.brand-only {
        justify-content: center;
        max-width: 900px;
        min-height: 88px;
    }
    .header-flex.brand-only .logo-section {
        text-align: center;
    }
    .logo-section {
        display: flex;
        align-items: center;
        gap: 15px;
        text-decoration: none;
        color: inherit;
        transition: transform 0.2s;
    }
    .logo-section:hover {
        transform: scale(1.02);
    }
    .logo-img {
        width: 55px;
        height: 55px;
        border-radius: 50%;
        object-fit: cover;
        box-shadow: 0 4px 15px rgba(255, 255, 255, 0.2);
        border: 3px solid rgba(255, 255, 255, 0.3);
        background: #fff;
        transition: all 0.3s ease;
    }
    .logo-img:hover {
        transform: scale(1.05) rotate(5deg);
        box-shadow: 0 6px 20px rgba(255, 255, 255, 0.3);
    }
    header h1 {
        font-size: 1.3rem;
        margin: 0;
        font-weight: 700;
        letter-spacing: -0.5px;
        text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    nav {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    nav.role-nurse {
        gap: 4px;
    }
    nav.role-nurse a {
        padding-right: 12px;
        padding-left: 12px;
        font-size: 0.88rem;
    }
    nav.role-nurse .logout-btn {
        padding-right: 18px;
        padding-left: 18px;
    }
    nav a {
        color: rgba(255, 255, 255, 0.95);
        text-decoration: none;
        padding: 10px 18px;
        border-radius: 8px;
        font-weight: 500;
        font-size: 0.95rem;
        transition: all 0.3s ease;
        position: relative;
        white-space: nowrap;
    }
    nav a::before {
        content: "";
        position: absolute;
        bottom: 5px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 2px;
        background: #90e0ef;
        transition: width 0.3s ease;
    }
    nav a:hover {
        color: #fff;
        background: rgba(255, 255, 255, 0.15);
        transform: translateY(-2px);
    }
    nav a:hover::before {
        width: 70%;
    }
    nav a.active {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
    }
    nav a.active::before {
        width: 70%;
    }
    .login-btn, .logout-btn, .signup-btn {
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        color: #fff;
        padding: 10px 24px;
        border-radius: 25px;
        font-weight: 600;
        text-decoration: none;
        border: 2px solid rgba(255, 255, 255, 0.3);
        transition: all 0.3s ease;
        cursor: pointer;
        font-size: 0.95rem;
        white-space: nowrap;
    }
    .login-btn:hover, .logout-btn:hover, .signup-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }
    .signup-btn {
        background: #fff;
        color: #023e8a;
        border-color: #fff;
    }
    .signup-btn:hover {
        background: #caf0f8;
        color: #023e8a;
        border-color: #caf0f8;
    }
    .logout-btn {
        background: rgba(217, 4, 41, 0.2);
        border-color: rgba(217, 4, 41, 0.4);
    }
    .logout-btn:hover {
        background: rgba(217, 4, 41, 0.3);
        border-color: rgba(217, 4, 41, 0.6);
    }
    .logout-confirm-overlay {
        position: fixed;
        inset: 0;
        z-index: 3000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(7, 59, 76, 0.62);
        opacity: 0;
        pointer-events: none;
        visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }
    .logout-confirm-overlay.is-open {
        opacity: 1;
        pointer-events: auto;
        visibility: visible;
    }
    .logout-confirm-box {
        width: min(100%, 420px);
        box-sizing: border-box;
        background: #fff;
        border: 1px solid #dceef2;
        border-radius: 8px;
        box-shadow: 0 24px 70px rgba(0, 0, 0, 0.24);
        padding: 28px;
        text-align: center;
    }
    .logout-confirm-icon {
        width: 58px;
        height: 58px;
        margin: 0 auto 16px;
        border-radius: 50%;
        background: #fff4e6;
        color: #c2410c;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.55rem;
        font-weight: 800;
    }
    .logout-confirm-box h2 {
        color: #073b4c;
        font-size: 1.35rem;
        margin: 0 0 8px;
    }
    .logout-confirm-box p {
        color: #51636d;
        line-height: 1.55;
        margin: 0 0 22px;
    }
    .logout-confirm-actions {
        display: flex;
        gap: 12px;
    }
    .logout-confirm-actions button {
        flex: 1;
        min-height: 44px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 800;
        font-family: inherit;
    }
    .logout-cancel-btn {
        background: #f0f7fa;
        color: #073b4c;
        border: 1px solid #cfe4e9;
    }
    .logout-confirm-btn {
        background: #d90429;
        color: #fff;
        border: 1px solid #d90429;
    }
    .logout-cancel-btn:hover {
        background: #e4f2f6;
    }
    .logout-confirm-btn:hover {
        background: #b00020;
    }
    .user-info {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 16px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 25px;
        margin-right: 10px;
    }
    .user-avatar {
        width: 35px;
        height: 35px;
        border-radius: 50%;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-weight: 700;
        font-size: 0.9rem;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }
    .user-avatar-photo {
        object-fit: cover;
        background: #fff;
    }
    .user-name {
        font-size: 0.9rem;
        font-weight: 600;
    }
    .mobile-menu-toggle {
        display: none;
        background: none;
        border: none;
        color: #fff;
        font-size: 1.8rem;
        cursor: pointer;
        padding: 5px 10px;
        border-radius: 5px;
        transition: background 0.2s;
    }
    .mobile-menu-toggle:hover {
        background: rgba(255, 255, 255, 0.1);
    }
    @media (max-width: 1180px) and (min-width: 901px) {
        .header-flex:has(nav.role-nurse) {
            gap: 12px;
            padding-right: 14px;
            padding-left: 14px;
        }
        .header-flex:has(nav.role-nurse) h1 {
            font-size: 1.05rem;
        }
        nav.role-nurse a {
            padding: 9px 8px;
            font-size: 0.8rem;
        }
        nav.role-nurse .logout-btn {
            padding: 9px 14px;
            font-size: 0.82rem;
        }
    }
    @media (max-width: 900px) {
        .mobile-menu-toggle {
            display: block;
        }
        nav {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            flex-direction: column;
            align-items: stretch;
            padding: 20px;
            gap: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            transform: translateY(-100%);
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        nav.active {
            transform: translateY(0);
            opacity: 1;
            visibility: visible;
        }
        nav a {
            padding: 12px 20px;
            border-radius: 8px;
        }
        header h1 {
            font-size: 1.1rem;
        }
        .logo-img {
            width: 45px;
            height: 45px;
        }
        .user-info {
            display: flex;
            margin: 0 0 8px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.14);
            justify-content: flex-start;
        }
    }
    body.app-role-patient {
        --patient-sidebar: #071c2e;
        --patient-sidebar-2: #0b3558;
        --patient-blue: #0878d8;
        --patient-blue-2: #00a6d6;
        --patient-soft-blue: #eaf6ff;
        --patient-ink: #10233f;
        --patient-muted: #667a8d;
        --patient-line: #d9e8f2;
        background:
            radial-gradient(circle at 80% 10%, rgba(0, 166, 214, 0.12), transparent 28%),
            linear-gradient(135deg, #f4f8fc 0%, #f8fbff 54%, #eef8fb 100%);
    }
    body.app-role-patient .mobile-menu-toggle {
        display: none;
    }
    body.app-role-patient nav a.has-svg-icon::before {
        display: none;
        content: none;
    }
    body.app-role-patient .nav-ui-icon {
        display: inline-grid;
        place-items: center;
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        border-radius: 12px;
        background: rgba(234, 246, 255, 0.10);
        color: #bdeeff;
    }
    body.app-role-patient .nav-ui-icon svg {
        width: 20px;
        height: 20px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    body.app-role-patient .nav-label {
        min-width: 0;
    }
    .patient-app-topbar {
        display: none;
    }
    @media (min-width: 901px) {
        body.app-role-patient {
            padding-left: 276px;
            padding-top: 82px;
        }
        body.app-role-patient header {
            position: fixed;
            inset: 0 auto 0 0;
            width: 276px;
            min-height: 100vh;
            background:
                radial-gradient(circle at 88% 8%, rgba(0, 166, 214, 0.26), transparent 31%),
                linear-gradient(180deg, var(--patient-sidebar) 0%, #0a2440 54%, #061827 100%);
            border-right: 1px solid rgba(144, 224, 239, 0.18);
            box-shadow: 16px 0 40px rgba(7, 28, 46, 0.20);
        }
        body.app-role-patient header.scrolled {
            padding: 0;
        }
        body.app-role-patient .header-flex {
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            padding: 22px 14px;
            max-width: none;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            gap: 0;
        }
        body.app-role-patient .logo-section {
            display: grid;
            grid-template-columns: 58px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
            margin: 4px 2px 28px;
            padding: 16px;
            border-radius: 20px;
            color: #fff;
            background: linear-gradient(135deg, rgba(8, 120, 216, 0.22), rgba(255, 255, 255, 0.06));
            border: 1px solid rgba(144, 224, 239, 0.18);
            box-shadow: 0 20px 34px rgba(0, 0, 0, 0.18);
        }
        body.app-role-patient .logo-section:hover {
            transform: translateY(-1px);
        }
        body.app-role-patient .logo-img {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            border: 3px solid rgba(255, 255, 255, 0.88);
            box-shadow: 0 14px 26px rgba(0, 119, 182, 0.26);
        }
        body.app-role-patient header h1 {
            color: #ffffff;
            font-size: 1.02rem;
            line-height: 1.24;
            letter-spacing: 0;
            text-shadow: none;
        }
        body.app-role-patient nav {
            display: flex;
            flex: 1;
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
            padding: 0;
        }
        body.app-role-patient nav::before {
            content: "MAIN";
            margin: 14px 12px 8px;
            color: #9fb4c8;
            font-size: 0.72rem;
            font-weight: 900;
            letter-spacing: 0.13em;
        }
        body.app-role-patient nav .user-info {
            display: none;
        }
        body.app-role-patient nav a {
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 64px;
            padding: 10px 14px;
            border-radius: 16px;
            color: #d7e6f2;
            background: transparent;
            border: 1px solid transparent;
            font-size: 0.94rem;
            font-weight: 850;
            text-decoration: none;
            white-space: normal;
        }
        body.app-role-patient nav a .nav-label {
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
            line-height: 1.22;
        }
        body.app-role-patient nav a:hover {
            transform: none;
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }
        body.app-role-patient nav a.active {
            color: #ffffff;
            background: linear-gradient(135deg, #0878d8 0%, #005a9e 100%);
            border-color: rgba(144, 224, 239, 0.32);
            box-shadow: 0 16px 30px rgba(0, 119, 182, 0.26);
        }
        body.app-role-patient nav a.active .nav-ui-icon {
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
        }
        body.app-role-patient .logout-form {
            margin-top: auto;
        }
        body.app-role-patient .logout-btn {
            width: 100%;
            min-height: 52px;
            border-radius: 16px;
            background: transparent;
            border: 1px solid rgba(144, 224, 239, 0.38);
            color: #e7f7ff;
            font-weight: 900;
        }
        body.app-role-patient .logout-btn:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(144, 224, 239, 0.70);
            transform: none;
        }
        .patient-app-topbar {
            position: fixed;
            top: 0;
            left: 276px;
            right: 0;
            z-index: 900;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 82px;
            padding: 0 34px;
            box-sizing: border-box;
            background: rgba(255, 255, 255, 0.96);
            border-bottom: 1px solid #dfe9f1;
            box-shadow: 0 8px 22px rgba(7, 28, 46, 0.06);
            backdrop-filter: blur(14px);
        }
        .patient-app-topbar h2 {
            margin: 0;
            color: var(--patient-ink);
            font-size: 1.45rem;
            line-height: 1.1;
        }
        .patient-topbar-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .patient-round-btn,
        .patient-profile-chip {
            min-height: 46px;
            border: 1px solid #dce8f1;
            background: #ffffff;
            color: #17324d;
            box-shadow: 0 10px 24px rgba(7, 28, 46, 0.06);
        }
        .patient-round-btn {
            width: 46px;
            border-radius: 50%;
            display: inline-grid;
            place-items: center;
            position: relative;
        }
        .patient-round-btn svg,
        .patient-profile-chip svg {
            width: 19px;
            height: 19px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2.2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .patient-notification-dot {
            position: absolute;
            top: -4px;
            right: -3px;
            display: inline-grid;
            place-items: center;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            border-radius: 999px;
            background: #ef4444;
            border: 2px solid #fff;
            color: #ffffff;
            font-size: 0.68rem;
            font-weight: 950;
            line-height: 1;
        }
        .patient-profile-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 194px;
            padding: 6px 13px 6px 8px;
            border-radius: 999px;
            font-weight: 850;
            text-decoration: none;
        }
        .patient-chip-avatar {
            display: inline-grid;
            place-items: center;
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #0878d8, #00a6d6);
            color: #fff;
            font-size: 0.86rem;
            font-weight: 950;
        }
        .patient-chip-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
    }
    @media (max-width: 900px) {
        body.app-role-patient {
            padding-bottom: 102px;
        }
        body.app-role-patient header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: linear-gradient(135deg, #0878d8 0%, #005a9e 100%);
        }
        body.app-role-patient .header-flex {
            padding: 12px 14px;
            gap: 12px;
        }
        body.app-role-patient .logo-section {
            min-width: 0;
            gap: 10px;
        }
        body.app-role-patient .logo-img {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
        }
        body.app-role-patient header h1 {
            max-width: 230px;
            font-size: 0.94rem;
            line-height: 1.2;
        }
        body.app-role-patient nav {
            position: fixed;
            left: 14px;
            right: 14px;
            bottom: 14px;
            top: auto;
            z-index: 1500;
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(76px, 1fr);
            align-items: center;
            gap: 6px;
            padding: 8px;
            border-radius: 28px;
            background: linear-gradient(135deg, #071c2e 0%, #0b2440 100%);
            border: 1px solid rgba(144, 224, 239, 0.18);
            box-shadow: 0 18px 42px rgba(7, 28, 46, 0.36);
            transform: none;
            opacity: 1;
            visibility: visible;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        body.app-role-patient nav::-webkit-scrollbar {
            display: none;
        }
        body.app-role-patient nav::before,
        body.app-role-patient nav .user-info,
        body.app-role-patient nav .logout-form {
            display: none;
        }
        body.app-role-patient nav a {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 4px;
            min-height: 62px;
            padding: 7px 8px;
            border-radius: 22px;
            color: #d8e5ef;
            font-size: 0.74rem;
            font-weight: 850;
            line-height: 1.05;
            text-align: center;
            text-decoration: none;
            white-space: nowrap;
        }
        body.app-role-patient .nav-ui-icon {
            width: 26px;
            height: 26px;
            flex: 0 0 26px;
            border-radius: 0;
            background: transparent;
            color: inherit;
        }
        body.app-role-patient .nav-ui-icon svg {
            width: 24px;
            height: 24px;
        }
        body.app-role-patient nav a.active {
            background: linear-gradient(135deg, #00a6d6 0%, #0878d8 100%);
            color: #ffffff;
            box-shadow: 0 12px 26px rgba(0, 119, 182, 0.32);
        }
    }

    /* Globalife patient navigation polish: match public index blue palette */
    body.app-role-patient {
        --patient-sidebar: #0077b6;
        --patient-sidebar-2: #023e8a;
        --patient-blue: #0077b6;
        --patient-blue-2: #48cae4;
        --patient-ink: #023e8a;
    }
    @media (min-width: 901px) {
        body.app-role-patient header {
            background:
                radial-gradient(circle at 20% 8%, rgba(202, 240, 248, 0.30), transparent 26%),
                linear-gradient(160deg, #0077b6 0%, #005fa8 44%, #023e8a 100%);
            border-right: 1px solid rgba(202, 240, 248, 0.28);
            box-shadow: 14px 0 34px rgba(2, 62, 138, 0.18);
        }
        body.app-role-patient .logo-section {
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(202, 240, 248, 0.30);
            box-shadow: 0 18px 34px rgba(2, 62, 138, 0.20);
        }
        body.app-role-patient nav::before {
            color: rgba(234, 248, 255, 0.74);
        }
        body.app-role-patient nav a {
            color: #eaf8ff;
        }
        body.app-role-patient nav a:hover {
            background: rgba(255, 255, 255, 0.14);
        }
        body.app-role-patient nav a.active {
            background: #ffffff;
            color: #023e8a;
            border-color: rgba(202, 240, 248, 0.75);
            box-shadow: 0 14px 28px rgba(2, 62, 138, 0.22);
        }
        body.app-role-patient nav a.active .nav-ui-icon {
            background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
            color: #ffffff;
        }
        body.app-role-patient .logout-btn {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(202, 240, 248, 0.46);
            color: #ffffff;
        }
        body.app-role-patient .logout-btn:hover {
            background: rgba(255, 255, 255, 0.18);
            border-color: rgba(202, 240, 248, 0.85);
        }
    }
    @media (max-width: 900px) {
        body.app-role-patient header {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        }
        body.app-role-patient nav {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            border-color: rgba(202, 240, 248, 0.35);
            box-shadow: 0 18px 42px rgba(2, 62, 138, 0.30);
        }
        body.app-role-patient nav a.active {
            background: #ffffff;
            color: #023e8a;
            box-shadow: 0 12px 24px rgba(2, 62, 138, 0.20);
        }
        body.app-role-patient nav a.active .nav-ui-icon {
            color: #0077b6;
        }
    }

    /* Patient UI final palette: clean Globalife clinic blue, consistent with index.php */
    body.app-role-patient {
        --patient-blue: #0077b6;
        --patient-blue-deep: #023e8a;
        --patient-blue-soft: #eaf9fd;
        --patient-cyan: #48cae4;
        --patient-ink: #073b4c;
        --patient-muted: #5c7280;
        --patient-line: #d8edf1;
    }
    @media (min-width: 901px) {
        body.app-role-patient {
            background:
                radial-gradient(circle at 12% 12%, rgba(72, 202, 228, 0.16), transparent 26%),
                radial-gradient(circle at 92% 10%, rgba(46, 196, 182, 0.12), transparent 28%),
                linear-gradient(135deg, #f5fbfd 0%, #f8fcff 58%, #edf8fb 100%);
        }
        body.app-role-patient header {
            background:
                radial-gradient(circle at 18% 6%, rgba(72, 202, 228, 0.32), transparent 28%),
                radial-gradient(circle at 88% 82%, rgba(2, 62, 138, 0.26), transparent 34%),
                linear-gradient(180deg, #1294c8 0%, #0077b6 42%, #00579e 100%);
            border-right: 1px solid rgba(202, 240, 248, 0.36);
            box-shadow: 14px 0 34px rgba(2, 62, 138, 0.22);
        }
        body.app-role-patient .logo-section {
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(202, 240, 248, 0.28);
            color: #ffffff;
            box-shadow: 0 18px 34px rgba(2, 62, 138, 0.18);
            backdrop-filter: blur(12px);
        }
        body.app-role-patient header h1 {
            color: #ffffff;
        }
        body.app-role-patient .logo-img {
            border-color: rgba(255, 255, 255, 0.86);
            box-shadow: 0 12px 24px rgba(2, 62, 138, 0.18);
        }
        body.app-role-patient nav::before {
            color: rgba(234, 248, 255, 0.74);
        }
        body.app-role-patient nav a {
            color: rgba(255, 255, 255, 0.86);
            background: transparent;
        }
        body.app-role-patient nav a .nav-ui-icon {
            background: rgba(255, 255, 255, 0.12);
            color: #dff8ff;
        }
        body.app-role-patient nav a:hover {
            background: rgba(255, 255, 255, 0.13);
            color: #ffffff;
        }
        body.app-role-patient nav a.active {
            background: #ffffff;
            color: #023e8a;
            border-color: rgba(255, 255, 255, 0.82);
            box-shadow: 0 18px 30px rgba(2, 62, 138, 0.20);
        }
        body.app-role-patient nav a.active .nav-ui-icon {
            background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
            color: #ffffff;
        }
        body.app-role-patient .logout-btn {
            background: rgba(255, 255, 255, 0.10);
            border-color: rgba(202, 240, 248, 0.45);
            color: #ffffff;
            box-shadow: none;
        }
        body.app-role-patient .logout-btn:hover {
            background: rgba(255, 255, 255, 0.18);
            border-color: rgba(202, 240, 248, 0.80);
            color: #ffffff;
        }
        .patient-app-topbar {
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid rgba(0, 119, 182, 0.12);
            box-shadow: 0 10px 24px rgba(2, 62, 138, 0.06);
        }
        .patient-app-topbar h2 {
            color: #023e8a;
        }
        .patient-round-btn,
        .patient-profile-chip {
            border-color: rgba(0, 119, 182, 0.14);
            color: #023e8a;
            box-shadow: 0 10px 22px rgba(2, 62, 138, 0.06);
        }
    }
    @media (max-width: 900px) {
        body.app-role-patient header,
        body.app-role-patient nav {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
        }
        body.app-role-patient nav {
            border-color: rgba(202, 240, 248, 0.40);
            box-shadow: 0 18px 40px rgba(2, 62, 138, 0.28);
        }
        body.app-role-patient nav a {
            color: rgba(255, 255, 255, 0.82);
        }
        body.app-role-patient nav a.active {
            background: #ffffff;
            color: #023e8a;
            box-shadow: 0 12px 24px rgba(2, 62, 138, 0.18);
        }
    }

    /* System-wide authenticated dashboard UI. Public homepage/index remains untouched. */
    body.app-layout {
        --app-blue: #0077b6;
        --app-blue-deep: #023e8a;
        --app-cyan: #48cae4;
        --app-ink: #073b4c;
        --app-muted: #60727d;
        --app-line: #d8edf1;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        box-sizing: border-box;
        background:
            radial-gradient(circle at 10% 8%, rgba(72, 202, 228, 0.16), transparent 26%),
            radial-gradient(circle at 92% 10%, rgba(46, 196, 182, 0.11), transparent 28%),
            linear-gradient(135deg, #f5fbfd 0%, #f9fcff 58%, #eef8fb 100%);
    }
    body.app-layout > main {
        flex: 1 0 auto;
        width: 100%;
        box-sizing: border-box;
    }
    body.app-layout > footer {
        flex-shrink: 0;
        margin-top: auto;
        padding: 20px 16px;
        background: linear-gradient(135deg, var(--app-blue) 0%, var(--app-blue-deep) 100%);
        color: #eaf8ff;
        border-top: 1px solid rgba(202, 240, 248, 0.25);
        box-shadow: 0 -10px 24px rgba(2, 62, 138, 0.08);
    }
    body.app-layout > footer .container {
        max-width: 1180px;
        margin: 0 auto;
    }
    body.app-layout > footer p {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 650;
        letter-spacing: 0;
    }
    body.app-layout nav a.has-svg-icon::before {
        display: none;
        content: none;
    }
    body.app-layout .nav-ui-icon {
        display: inline-grid;
        place-items: center;
        width: 38px;
        height: 38px;
        flex: 0 0 38px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.12);
        color: #dff8ff;
    }
    body.app-layout .nav-ui-icon svg,
    body.app-layout .patient-round-btn svg,
    body.app-layout .patient-profile-chip svg {
        fill: none;
        stroke: currentColor;
        stroke-width: 2.2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    body.app-layout .nav-ui-icon svg {
        width: 20px;
        height: 20px;
    }
    body.app-layout .topbar-calendar-link {
        text-decoration: none;
    }
    body.app-layout .topbar-calendar-link.is-active {
        background: #eaf8ff;
        border-color: rgba(0, 119, 182, 0.22);
        color: #0077b6;
        box-shadow: 0 10px 24px rgba(0, 119, 182, 0.12);
    }
    body.app-layout .nav-label {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    body.app-layout.app-role-patient .nav-label {
        overflow: visible;
        text-overflow: clip;
        white-space: normal;
        line-height: 1.18;
    }
    body.app-layout .panel,
    body.app-layout .metric-card,
    body.app-layout .account-panel,
    body.app-layout .card,
    body.app-layout .dashboard-card,
    body.app-layout .appointment-card,
    body.app-layout .service-card,
    body.app-layout .doctor-card {
        border-color: var(--app-line);
        box-shadow: 0 14px 30px rgba(2, 62, 138, 0.06);
    }
    body.app-layout h1,
    body.app-layout h2,
    body.app-layout h3,
    body.app-layout .panel-head h2,
    body.app-layout .account-panel-head h2 {
        color: var(--app-ink);
    }
    body.app-layout .primary-btn,
    body.app-layout .cta-btn,
    body.app-layout .btn-primary,
    body.app-layout .patient-submit-btn,
    body.app-layout .save-btn {
        background: linear-gradient(135deg, var(--app-cyan) 0%, var(--app-blue) 100%);
        border-color: transparent;
        color: #ffffff;
    }
    body.app-layout .patient-hero,
    body.app-layout .patient-hero.is-new-welcome,
    body.app-layout .hero-main {
        background:
            radial-gradient(circle at 18% 22%, rgba(72, 202, 228, 0.22), transparent 30%),
            radial-gradient(circle at 92% 8%, rgba(255, 255, 255, 0.16), transparent 24%),
            linear-gradient(135deg, #0077b6 0%, #0064ad 48%, #023e8a 100%) !important;
        border: 1px solid rgba(202, 240, 248, 0.28) !important;
        box-shadow: 0 18px 42px rgba(2, 62, 138, 0.18) !important;
        color: #ffffff !important;
    }
    body.app-layout .patient-hero h1,
    body.app-layout .patient-hero h2,
    body.app-layout .patient-hero h3,
    body.app-layout .hero-main h1,
    body.app-layout .hero-main h2,
    body.app-layout .hero-main h3 {
        color: #ffffff !important;
    }
    body.app-layout .patient-hero p,
    body.app-layout .hero-main p {
        color: rgba(255, 255, 255, 0.88) !important;
    }
    body.app-layout .patient-kicker,
    body.app-layout .hero-main .eyebrow,
    body.app-layout .eyebrow {
        color: #caf0f8 !important;
    }
    body.app-layout .patient-app-topbar {
        display: none;
    }
    body.app-layout nav .logout-form {
        display: none !important;
    }
    body.app-layout .topbar-breadcrumb {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        color: #738294;
        font-size: 0.92rem;
        font-weight: 750;
    }
    body.app-layout .topbar-breadcrumb-icon {
        display: inline-grid;
        place-items: center;
        width: 28px;
        height: 28px;
        border-radius: 9px;
        color: #6f7d8d;
        background: rgba(255, 255, 255, 0.70);
        border: 1px solid rgba(2, 62, 138, 0.08);
    }
    body.app-layout .topbar-breadcrumb-icon svg,
    body.app-layout .topbar-chevron svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    body.app-layout .topbar-chevron {
        display: inline-grid;
        place-items: center;
        color: #9ca6b3;
    }
    body.app-layout .topbar-current {
        min-width: 0;
        color: #111827;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    body.app-layout .patient-topbar-menu-wrap {
        position: relative;
        display: inline-flex;
    }
    body.app-layout .patient-notification-wrap {
        position: relative;
        display: inline-flex;
    }
    body.app-layout .patient-chip-avatar {
        display: inline-grid;
        place-items: center;
        width: 38px;
        height: 38px;
        max-width: 38px;
        max-height: 38px;
        flex: 0 0 38px;
        border-radius: 50%;
        overflow: hidden;
        background: linear-gradient(135deg, #ff4d72 0%, #ef2f5f 100%);
        color: #ffffff;
        font-size: 0.96rem;
        font-weight: 950;
        line-height: 1;
    }
    body.app-layout .patient-chip-avatar img {
        display: block;
        width: 100% !important;
        height: 100% !important;
        max-width: 100% !important;
        max-height: 100% !important;
        object-fit: cover;
    }
    body.app-layout .patient-topbar-menu-wrap .patient-profile-trigger {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        min-height: 42px;
        padding: 5px 8px 5px 5px;
        border: 0;
        background: transparent;
        color: #25364b;
        cursor: pointer;
    }
    body.app-layout .patient-topbar-menu-wrap .patient-profile-trigger:hover {
        background: transparent;
        box-shadow: none;
        transform: none;
    }
    body.app-layout .patient-topbar-menu-wrap .patient-profile-trigger svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.4;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    body.app-layout .patient-profile-menu {
        position: absolute;
        top: calc(100% + 12px);
        right: 0;
        z-index: 1800;
        width: 265px;
        padding: 16px;
        border: 1px solid rgba(2, 62, 138, 0.10);
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 24px 55px rgba(15, 23, 42, 0.14);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-6px);
        transition: opacity 0.16s ease, transform 0.16s ease, visibility 0.16s ease;
    }
    body.app-layout .patient-profile-menu::before {
        content: "";
        position: absolute;
        top: -7px;
        right: 24px;
        width: 14px;
        height: 14px;
        background: #ffffff;
        border-left: 1px solid rgba(2, 62, 138, 0.10);
        border-top: 1px solid rgba(2, 62, 138, 0.10);
        transform: rotate(45deg);
    }
    body.app-layout .patient-topbar-menu-wrap.is-open .patient-profile-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    body.app-layout .patient-notification-panel {
        position: absolute;
        top: calc(100% + 12px);
        right: 0;
        z-index: 1800;
        width: min(430px, calc(100vw - 32px));
        padding: 16px;
        border: 1px solid rgba(0, 119, 182, 0.13);
        border-radius: 20px;
        background: #ffffff;
        box-shadow: 0 26px 60px rgba(15, 23, 42, 0.14);
        opacity: 0;
        visibility: hidden;
        transform: translateY(-6px);
        transition: opacity 0.16s ease, transform 0.16s ease, visibility 0.16s ease;
    }
    body.app-layout .patient-notification-panel::before {
        content: "";
        position: absolute;
        top: -7px;
        right: 20px;
        width: 14px;
        height: 14px;
        background: #ffffff;
        border-left: 1px solid rgba(2, 62, 138, 0.10);
        border-top: 1px solid rgba(2, 62, 138, 0.10);
        transform: rotate(45deg);
    }
    body.app-layout .patient-notification-wrap.is-open .patient-notification-panel {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    body.app-layout .notification-panel-title {
        margin: 0;
        color: #10233f;
        font-size: 1.08rem;
        font-weight: 950;
        letter-spacing: 0;
    }
    body.app-layout .notification-item {
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr);
        align-items: start;
        gap: 11px;
        padding: 11px;
        border-radius: 14px;
        color: #344357;
        text-decoration: none;
    }
    body.app-layout .notification-item:hover {
        background: #f6fbff;
    }
    body.app-layout .notification-item-icon {
        display: inline-grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border-radius: 12px;
        background: #eaf8fc;
        color: #0077b6;
    }
    body.app-layout .notification-item-icon svg {
        width: 18px;
        height: 18px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.3;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    body.app-layout .notification-item strong {
        display: block;
        color: #10233f;
        font-size: 0.88rem;
        line-height: 1.2;
    }
    body.app-layout .notification-item span:not(.notification-item-icon) {
        display: block;
        margin-top: 4px;
        color: #60727d;
        font-size: 0.78rem;
        line-height: 1.42;
    }
    body.app-layout .patient-profile-menu .profile-menu-form {
        margin: 0;
    }
    body.app-layout .patient-profile-menu a,
    body.app-layout .patient-profile-menu .profile-menu-logout {
        display: flex;
        align-items: center;
        gap: 12px;
        width: 100%;
        min-height: 48px;
        padding: 10px 12px;
        border: 0;
        border-radius: 12px;
        background: transparent;
        color: #344357;
        font: inherit;
        font-size: 1rem;
        font-weight: 800;
        text-align: left;
        text-decoration: none;
        cursor: pointer;
        box-sizing: border-box;
    }
    body.app-layout .patient-profile-menu a:hover,
    body.app-layout .patient-profile-menu .profile-menu-logout:hover {
        background: #f2f8fc;
        transform: none;
        box-shadow: none;
    }
    body.app-layout .patient-profile-menu .profile-menu-logout {
        color: #c9184a;
    }
    body.app-layout .profile-menu-icon {
        display: inline-grid;
        place-items: center;
        width: 26px;
        height: 26px;
        color: currentColor;
    }
    body.app-layout .profile-menu-icon svg {
        width: 21px;
        height: 21px;
        fill: none;
        stroke: currentColor;
        stroke-width: 2.4;
        stroke-linecap: round;
        stroke-linejoin: round;
    }
    @media (min-width: 901px) {
        body.app-layout {
            padding-left: 276px;
            padding-top: 62px;
        }
        body.app-layout header {
            position: fixed;
            inset: 0 auto 0 0;
            width: 276px;
            min-height: 100vh;
            background:
                radial-gradient(circle at 18% 6%, rgba(72, 202, 228, 0.32), transparent 28%),
                radial-gradient(circle at 88% 82%, rgba(2, 62, 138, 0.26), transparent 34%),
                linear-gradient(180deg, #1294c8 0%, #0077b6 42%, #00579e 100%);
            border-right: 1px solid rgba(202, 240, 248, 0.36);
            box-shadow: 14px 0 34px rgba(2, 62, 138, 0.22);
        }
        body.app-layout header.scrolled {
            padding: 0;
        }
        body.app-layout .header-flex {
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            padding: 22px 14px;
            max-width: none;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            gap: 0;
        }
        body.app-layout .logo-section {
            display: grid;
            grid-template-columns: 58px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
            margin: 4px 2px 28px;
            padding: 16px;
            border-radius: 20px;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(202, 240, 248, 0.28);
            box-shadow: 0 18px 34px rgba(2, 62, 138, 0.18);
            backdrop-filter: blur(12px);
        }
        body.app-layout .logo-section:hover {
            transform: translateY(-1px);
        }
        body.app-layout .logo-img {
            width: 58px;
            height: 58px;
            border-radius: 16px;
            border: 3px solid rgba(255, 255, 255, 0.86);
            box-shadow: 0 12px 24px rgba(2, 62, 138, 0.18);
        }
        body.app-layout header h1 {
            color: #ffffff;
            font-size: 1.02rem;
            line-height: 1.24;
            letter-spacing: 0;
            text-shadow: none;
        }
        body.app-layout .mobile-menu-toggle {
            display: none;
        }
        body.app-layout nav {
            display: flex;
            flex: 1;
            flex-direction: column;
            align-items: stretch;
            gap: 8px;
            padding: 0;
        }
        body.app-layout nav::before {
            content: "MAIN";
            margin: 14px 12px 8px;
            color: rgba(234, 248, 255, 0.74);
            font-size: 0.72rem;
            font-weight: 900;
            letter-spacing: 0.13em;
        }
        body.app-layout nav .user-info {
            display: none;
        }
        body.app-layout nav a {
            display: flex;
            align-items: center;
            gap: 14px;
            min-height: 56px;
            padding: 9px 14px;
            border-radius: 16px;
            color: rgba(255, 255, 255, 0.86);
            background: transparent;
            border: 1px solid transparent;
            font-size: 0.98rem;
            font-weight: 850;
            text-decoration: none;
            white-space: nowrap;
        }
        body.app-layout.app-role-patient nav a {
            min-height: 68px;
            padding: 10px 12px;
            white-space: normal;
        }
        body.app-layout.app-role-patient nav a .nav-label {
            display: block;
            min-width: 0;
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
            line-height: 1.18;
            word-break: normal;
            overflow-wrap: anywhere;
        }
        body.app-layout nav a:hover {
            transform: none;
            background: rgba(255, 255, 255, 0.13);
            color: #ffffff;
        }
        body.app-layout nav a.active {
            background: #ffffff;
            color: var(--app-blue-deep);
            border-color: rgba(255, 255, 255, 0.82);
            box-shadow: 0 18px 30px rgba(2, 62, 138, 0.20);
        }
        body.app-layout nav a.active .nav-ui-icon {
            background: linear-gradient(135deg, var(--app-cyan) 0%, var(--app-blue) 100%);
            color: #ffffff;
        }
        body.app-layout .logout-form {
            margin-top: auto;
        }
        body.app-layout .logout-btn {
            width: 100%;
            min-height: 52px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(202, 240, 248, 0.45);
            color: #ffffff;
            font-weight: 900;
            box-shadow: none;
        }
        body.app-layout .logout-btn:hover {
            background: rgba(255, 255, 255, 0.18);
            border-color: rgba(202, 240, 248, 0.80);
            color: #ffffff;
            transform: none;
        }
        body.app-layout .patient-app-topbar {
            position: fixed;
            top: 0;
            left: 276px;
            right: 0;
            z-index: 900;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 62px;
            padding: 0 30px;
            box-sizing: border-box;
            background:
                radial-gradient(circle at 96% 0%, rgba(72, 202, 228, 0.16), transparent 26%),
                linear-gradient(90deg, #f7f5ff 0%, #f4f7ff 50%, #eefcff 100%);
            border-bottom: 1px solid rgba(2, 62, 138, 0.08);
            box-shadow: 0 8px 20px rgba(2, 62, 138, 0.05);
            backdrop-filter: blur(14px);
        }
        body.app-layout .patient-app-topbar h2 {
            display: none;
        }
        body.app-layout .patient-topbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        body.app-layout .patient-round-btn,
        body.app-layout .patient-profile-chip,
        body.app-layout .patient-topbar-menu-wrap .patient-profile-trigger {
            min-height: 40px;
            border: 1px solid rgba(2, 62, 138, 0.10);
            background: rgba(255, 255, 255, 0.72);
            color: #24364a;
            box-shadow: 0 8px 18px rgba(2, 62, 138, 0.05);
        }
        body.app-layout .patient-round-btn {
            width: 40px;
            border-radius: 999px;
            display: inline-grid;
            place-items: center;
            position: relative;
        }
        body.app-layout .patient-profile-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 174px;
            max-width: 260px;
            padding: 5px 12px 5px 6px;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 850;
        }
        body.app-layout .patient-profile-chip > span:not(.patient-chip-avatar) {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.app-layout .patient-chip-avatar {
            display: inline-grid;
            place-items: center;
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #ff4d72 0%, #ef2f5f 100%);
            color: #ffffff;
            font-size: 0.96rem;
            font-weight: 950;
        }
        body.app-layout .patient-chip-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        body.app-layout .patient-notification-dot {
            position: absolute;
            top: 7px;
            right: 8px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ef4444;
            border: 2px solid #ffffff;
        }
    }
    @media (max-width: 900px) {
        body.app-layout {
            padding-bottom: 102px;
            overflow-x: hidden;
        }
        body.app-layout header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: linear-gradient(135deg, var(--app-blue) 0%, var(--app-blue-deep) 100%);
        }
        body.app-layout .header-flex {
            padding: 12px 14px;
            gap: 12px;
        }
        body.app-layout .logo-section {
            min-width: 0;
            gap: 10px;
        }
        body.app-layout .logo-img {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
        }
        body.app-layout header h1 {
            max-width: 230px;
            color: #ffffff;
            font-size: 0.94rem;
            line-height: 1.2;
        }
        body.app-layout .mobile-menu-toggle {
            display: none;
        }
        body.app-layout nav {
            position: fixed;
            left: 14px;
            right: 14px;
            bottom: 14px;
            top: auto;
            z-index: 1500;
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: minmax(76px, 1fr);
            align-items: center;
            gap: 6px;
            padding: 8px;
            border-radius: 28px;
            background: linear-gradient(135deg, var(--app-blue) 0%, var(--app-blue-deep) 100%);
            border: 1px solid rgba(202, 240, 248, 0.40);
            box-shadow: 0 18px 40px rgba(2, 62, 138, 0.28);
            transform: none;
            opacity: 1;
            visibility: visible;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        body.app-layout nav::-webkit-scrollbar {
            display: none;
        }
        body.app-layout nav::before,
        body.app-layout nav .user-info,
        body.app-layout nav .logout-form,
        body.app-layout nav .logout-btn {
            display: none !important;
        }
        body.app-layout nav a {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 4px;
            min-height: 62px;
            padding: 7px 8px;
            border-radius: 22px;
            color: rgba(255, 255, 255, 0.82);
            font-size: 0.74rem;
            font-weight: 850;
            line-height: 1.05;
            text-align: center;
            text-decoration: none;
            white-space: nowrap;
        }
        body.app-layout.app-role-patient nav a {
            min-height: 68px;
            padding: 10px 12px;
            white-space: normal;
        }
        body.app-layout.app-role-patient nav a .nav-label {
            display: block;
            min-width: 0;
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
            line-height: 1.18;
            word-break: normal;
            overflow-wrap: anywhere;
        }
        body.app-layout.app-role-patient nav a {
            min-width: 104px;
            white-space: normal;
        }
        body.app-layout.app-role-patient nav a .nav-label {
            display: block;
            max-width: 92px;
            overflow: visible;
            text-overflow: clip;
            white-space: normal;
            line-height: 1.05;
        }
        body.app-layout .nav-ui-icon {
            width: 26px;
            height: 26px;
            flex: 0 0 26px;
            border-radius: 0;
            background: transparent;
            color: inherit;
        }
        body.app-layout .nav-ui-icon svg {
            width: 24px;
            height: 24px;
        }
        body.app-layout nav a.active {
            background: #ffffff;
            color: var(--app-blue-deep);
            box-shadow: 0 12px 24px rgba(2, 62, 138, 0.18);
        }
        body.app-layout .patient-app-topbar {
            position: sticky;
            top: 66px;
            z-index: 920;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 58px;
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.96);
            border-bottom: 1px solid rgba(2, 62, 138, 0.08);
            box-shadow: 0 8px 20px rgba(2, 62, 138, 0.05);
            box-sizing: border-box;
        }
        body.app-layout .patient-app-topbar .topbar-breadcrumb {
            display: none;
        }
        body.app-layout .patient-app-topbar h2 {
            display: block;
            margin: 0;
            min-width: 0;
            color: #10233f;
            font-size: 1.08rem;
            line-height: 1.15;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.app-layout .patient-topbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
        }
        body.app-layout .patient-round-btn,
        body.app-layout .patient-topbar-menu-wrap .patient-profile-trigger {
            min-height: 40px;
            border-radius: 999px;
            background: #f7fbff;
            border: 1px solid rgba(2, 62, 138, 0.08);
            color: #24364a;
        }
        body.app-layout .patient-round-btn {
            width: 40px;
            display: inline-grid;
            place-items: center;
            position: relative;
        }
        body.app-layout .patient-topbar-menu-wrap .patient-profile-trigger {
            padding: 3px 4px 3px 3px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        body.app-layout .patient-chip-avatar {
            width: 38px;
            height: 38px;
            flex-basis: 38px;
        }
        body.app-layout .patient-profile-menu {
            position: fixed;
            top: 122px;
            right: 16px;
            width: min(300px, calc(100vw - 32px));
        }
        body.app-layout .patient-profile-menu::before {
            right: 20px;
        }
    }
    /* Final patient mobile header repair: keep actions inside the header and prevent avatar overflow. */
    body.app-layout .patient-notification-wrap {
        position: relative;
        display: inline-flex;
    }
    body.app-layout .patient-notification-wrap.has-unread .patient-round-btn {
        background: #eef8ff;
        color: #0077b6;
        box-shadow: 0 10px 24px rgba(0, 119, 182, 0.12);
    }
    body.app-layout .patient-notification-wrap:not(.has-unread) .patient-notification-dot {
        display: none;
    }
    body.app-layout .notification-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 2px 0 12px;
        border-bottom: 1px solid rgba(2, 62, 138, 0.10);
    }
    body.app-layout .notification-panel-head .notification-panel-title {
        margin: 0;
    }
    body.app-layout .notification-view-all {
        color: #0077b6;
        font-size: 0.86rem;
        font-weight: 900;
        text-decoration: none;
        white-space: nowrap;
    }
    body.app-layout .notification-list {
        display: grid;
        gap: 6px;
        padding-top: 10px;
        max-height: 250px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: #9bd2e9 #eef8fc;
    }
    body.app-layout .notification-empty {
        display: grid;
        min-height: 76px;
        place-items: center;
        color: #758399;
        font-size: 0.95rem;
        text-align: center;
    }
    body.app-layout .notification-time {
        display: block;
        margin-top: 5px;
        color: #8b99aa;
        font-size: 0.72rem;
        font-weight: 750;
    }
    body.app-layout .notification-item.is-unread {
        background: #f0fbff;
    }
    body.app-layout .notification-item.is-unread .notification-item-icon {
        background: #dff6ff;
        color: #0077b6;
    }
    body.app-layout .patient-notification-dot {
        position: absolute !important;
        top: 7px !important;
        right: 8px !important;
        display: block !important;
        min-width: 0 !important;
        width: 10px !important;
        height: 10px !important;
        padding: 0 !important;
        border-radius: 999px !important;
        background: #ef4444 !important;
        border: 2px solid #ffffff !important;
        color: transparent !important;
        font-size: 0 !important;
        line-height: 0 !important;
        box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.10);
    }
    body.app-layout .patient-chip-avatar,
    body.app-layout .patient-chip-avatar img {
        width: 38px !important;
        height: 38px !important;
        max-width: 38px !important;
        max-height: 38px !important;
    }
    body.app-layout .patient-chip-avatar {
        display: inline-grid;
        place-items: center;
        flex: 0 0 38px;
        min-width: 38px;
        min-height: 38px;
        border-radius: 999px;
        overflow: hidden;
        line-height: 1;
    }
    body.app-layout .patient-chip-avatar img {
        display: block;
        object-fit: cover;
    }
    body.app-layout .patient-topbar-name {
        display: inline-block;
        max-width: 190px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        color: #25364b;
        font-weight: 850;
    }
    @media (min-width: 901px) {
        body.app-role-patient .patient-topbar-menu-wrap .patient-profile-trigger {
            width: auto;
            min-width: 210px;
            max-width: 310px;
            min-height: 46px;
            padding: 4px 14px 4px 6px;
            gap: 10px;
            border-radius: 16px;
            background: #f7fbff;
            border: 1px solid rgba(2, 62, 138, 0.08);
            box-shadow: 0 10px 24px rgba(2, 62, 138, 0.06);
        }
        body.app-role-patient .patient-round-btn {
            width: 46px;
            min-width: 46px;
            min-height: 46px;
            background: #f7fbff;
            border: 1px solid rgba(2, 62, 138, 0.08);
        }
        body.app-role-patient .patient-chip-avatar,
        body.app-role-patient .patient-chip-avatar img {
            width: 40px !important;
            height: 40px !important;
            max-width: 40px !important;
            max-height: 40px !important;
        }
    }
    @media (max-width: 900px) {
        body.app-role-patient {
            overflow-x: hidden;
        }
        body.app-role-patient header {
            min-height: 108px;
            overflow: visible;
        }
        body.app-role-patient .header-flex {
            min-height: 108px;
            padding: 14px 18px;
            box-sizing: border-box;
        }
        body.app-role-patient .logo-section {
            width: auto;
            min-width: 0;
            margin: 0;
            padding: 0;
            border: 0;
            background: transparent;
            box-shadow: none;
            gap: 12px;
        }
        body.app-role-patient .logo-img {
            width: 54px;
            height: 54px;
            flex: 0 0 54px;
            border-radius: 50%;
        }
        body.app-role-patient header h1 {
            display: block;
            max-width: 270px;
            color: #ffffff;
            font-size: 1rem;
            line-height: 1.22;
        }
        body.app-role-patient .patient-app-topbar {
            position: sticky;
            top: 0;
            left: 0;
            right: 0;
            z-index: 995;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            min-height: 72px;
            padding: 10px 18px;
            border: 0;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 8px 22px rgba(2, 62, 138, 0.08);
            backdrop-filter: none;
            box-sizing: border-box;
        }
        body.app-role-patient .patient-app-topbar .topbar-breadcrumb {
            display: none !important;
        }
        body.app-role-patient .patient-app-topbar h2 {
            display: block !important;
            margin: 0;
            min-width: 0;
            color: #10233f;
            font-size: 1.2rem;
            line-height: 1.15;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        body.app-role-patient .patient-topbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: auto;
            max-width: none;
            flex: 0 0 auto;
        }
        body.app-role-patient .patient-round-btn,
        body.app-role-patient .patient-topbar-menu-wrap .patient-profile-trigger {
            width: 48px;
            min-width: 48px;
            min-height: 48px;
            padding: 0;
            border-radius: 999px;
            border: 1px solid rgba(2, 62, 138, 0.08);
            background: #f7fbff;
            color: #16324a;
            box-shadow: 0 6px 14px rgba(2, 62, 138, 0.14);
        }
        body.app-role-patient .patient-round-btn svg {
            width: 24px;
            height: 24px;
        }
        body.app-role-patient .patient-topbar-menu-wrap .patient-profile-trigger {
            display: inline-flex;
            justify-content: center;
            gap: 4px;
            width: 74px;
            min-width: 74px;
            min-height: 48px;
            padding: 2px 4px 2px 2px;
        }
        body.app-role-patient .patient-topbar-menu-wrap .patient-profile-trigger svg {
            width: 13px;
            height: 13px;
            flex: 0 0 13px;
        }
        body.app-role-patient .patient-chip-avatar,
        body.app-role-patient .patient-chip-avatar img {
            width: 42px !important;
            height: 42px !important;
            max-width: 42px !important;
            max-height: 42px !important;
        }
        body.app-role-patient .patient-topbar-name {
            display: none;
        }
        body.app-role-patient .patient-profile-menu,
        body.app-role-patient .patient-notification-panel {
            position: fixed;
            top: 184px;
            right: 12px;
            left: auto;
            width: min(320px, calc(100vw - 24px));
            max-width: calc(100vw - 24px);
        }
        body.app-role-patient .patient-profile-menu::before,
        body.app-role-patient .patient-notification-panel::before {
            right: 24px;
        }
        body.app-role-patient .notification-item {
            grid-template-columns: 32px minmax(0, 1fr);
        }
    }
    @media (max-width: 560px) {
        body.app-role-patient .header-flex {
            padding: 14px 16px;
        }
        body.app-role-patient .logo-img {
            width: 50px;
            height: 50px;
            flex-basis: 50px;
        }
        body.app-role-patient header h1 {
            max-width: 250px;
            font-size: 0.96rem;
        }
        body.app-role-patient .patient-topbar-actions {
            gap: 6px;
        }
        body.app-role-patient .patient-round-btn {
            width: 44px;
            min-width: 44px;
            min-height: 44px;
        }
        body.app-role-patient .patient-round-btn svg {
            width: 22px;
            height: 22px;
        }
        body.app-role-patient .patient-topbar-menu-wrap .patient-profile-trigger {
            width: 68px;
            min-width: 68px;
            min-height: 44px;
        }
        body.app-role-patient .patient-chip-avatar,
        body.app-role-patient .patient-chip-avatar img {
            width: 38px !important;
            height: 38px !important;
            max-width: 38px !important;
            max-height: 38px !important;
        }
    }
    /* Final mobile sticky header: keep the clinic bar and page toolbar visible while scrolling. */
    @media (max-width: 900px) {
        body.app-layout {
            --app-mobile-brand-height: 66px;
            --app-mobile-topbar-height: 58px;
        }
        body.app-layout header {
            position: sticky !important;
            top: 0 !important;
            z-index: 2600 !important;
        }
        body.app-layout .patient-app-topbar {
            position: sticky !important;
            top: var(--app-mobile-brand-height) !important;
            z-index: 2550 !important;
            display: flex !important;
            background: rgba(255, 255, 255, 0.98) !important;
            border-bottom: 1px solid rgba(2, 62, 138, 0.10) !important;
            box-shadow: 0 10px 24px rgba(2, 62, 138, 0.08) !important;
        }
        body.app-role-patient {
            --app-mobile-brand-height: 108px;
            --app-mobile-topbar-height: 72px;
        }
        body.app-layout .patient-profile-menu,
        body.app-layout .patient-notification-panel {
            position: fixed !important;
            top: calc(var(--app-mobile-brand-height) + var(--app-mobile-topbar-height) + 10px) !important;
            z-index: 2700 !important;
        }
    }
    @media (max-width: 560px) {
        body.app-layout {
            --app-mobile-brand-height: 66px;
            --app-mobile-topbar-height: 58px;
        }
        body.app-role-patient {
            --app-mobile-brand-height: 106px;
            --app-mobile-topbar-height: 68px;
        }
    }
    </style>
</head>
<body class="<?php echo htmlspecialchars($currentPageClass); ?> <?php echo isLoggedIn() ? 'app-layout app-role-' . htmlspecialchars((string) $currentUser['role']) : 'public-role'; ?>">
    <header id="mainHeader">
        <div class="header-flex<?php echo $headerBrandOnly ? ' brand-only' : ''; ?>">
            <a href="<?php echo htmlspecialchars($logoHref); ?>" class="logo-section" data-logo-home="<?php echo $currentPage === 'index.php' ? '1' : '0'; ?>">
                <img src="globalife.png" alt="Clinic Logo" class="logo-img">
                <h1>Globalife Medical Laboratory & Polyclinic</h1>
            </a>
            <?php if (!$headerBrandOnly): ?>
                <button class="mobile-menu-toggle" onclick="toggleMobileMenu()" aria-label="Open menu">&#9776;</button>
                <nav id="mainNav" class="<?php echo isLoggedIn() ? 'role-' . htmlspecialchars((string) $currentUser['role']) : 'role-public'; ?>">
                <?php if (isLoggedIn()): ?>
                    <?php if ($currentUser['role'] === 'admin'): ?>
                        <a href="admin.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'admin.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-9.5z"/></svg></span>
                            <span class="nav-label">Dashboard</span>
                        </a>
                        <a href="admin_accounts.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'admin_accounts.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                            <span class="nav-label">User Management</span>
                        </a>
                        <a href="register_patient_receptionist.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'register_patient_receptionist.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M20 8v6M17 11h6"/></svg></span>
                            <span class="nav-label">Register Patient</span>
                        </a>
                        <a href="view_appointments.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v3M16 3v3M5 8h14M6 5h12a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/><path d="m8.5 14 2 2 5-5"/></svg></span>
                            <span class="nav-label">Appointments</span>
                        </a>
                        <a href="admin_lab_services.php" class="has-svg-icon <?php echo in_array(basename($_SERVER['PHP_SELF']), ['admin_lab_services.php', 'receptionist_lab_services.php'], true) ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 2v6L4.5 19A2 2 0 0 0 6.35 22h11.3a2 2 0 0 0 1.85-3L14 8V2"/><path d="M8 2h8"/><path d="M7 16h10"/></svg></span>
                            <span class="nav-label">Lab Services</span>
                        </a>
                        <a href="admin_doctors.php" class="has-svg-icon <?php echo in_array(basename($_SERVER['PHP_SELF']), ['admin_doctors.php', 'receptionist_doctors.php', 'doctor_schedules.php'], true) ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v6"/><path d="M9 6h6"/><path d="M6 21v-3a6 6 0 0 1 12 0v3"/><path d="M8 13a4 4 0 1 1 8 0"/></svg></span>
                            <span class="nav-label">Doctors &amp; Hours</span>
                        </a>
                        <a href="admin_clinic_setup.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'admin_clinic_setup.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21a2 2 0 0 1-4 0v-.08a1.7 1.7 0 0 0-1.03-1.56 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.03H3a2 2 0 0 1 0-4h.08A1.7 1.7 0 0 0 4.64 8.9a1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.88.34H9a1.7 1.7 0 0 0 1-1.56V3a2 2 0 0 1 4 0v.08a1.7 1.7 0 0 0 1.03 1.56 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.88V9a1.7 1.7 0 0 0 1.56 1H21a2 2 0 0 1 0 4h-.08A1.7 1.7 0 0 0 19.4 15z"/></svg></span>
                            <span class="nav-label">Settings</span>
                        </a>
                    <?php elseif ($currentUser['role'] === 'doctor'): ?>
                        <a href="nurse.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'nurse.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-9.5z"/></svg></span>
                            <span class="nav-label">Dashboard</span>
                        </a>
                        <a href="view_appointments.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v3M16 3v3M5 8h14M6 5h12a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/><path d="m8.5 14 2 2 5-5"/></svg></span>
                            <span class="nav-label">Appointments</span>
                        </a>
                        <a href="nurse_patients.php" class="has-svg-icon <?php echo in_array(basename($_SERVER['PHP_SELF']), ['nurse_patients.php', 'nurse_patient.php'], true) ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M20 8v6M17 11h6"/></svg></span>
                            <span class="nav-label">Patient List</span>
                        </a>
                    <?php elseif ($currentUser['role'] === 'patient'): ?>
                        <div class="user-info">
                            <?php
                            $patientHeaderName = trim((string) ($headerPatientDisplayName ?? $currentUser['full_name']));
                            $patientHeaderFirstName = explode(' ', $patientHeaderName)[0] ?? 'Patient';
                            $patientHeaderInitials = trim((string) ($headerPatientInitials ?? strtoupper(substr($patientHeaderName, 0, 1))));
                            ?>
                            <?php if (!empty($headerPatientPhotoUrl)): ?>
                                <img src="<?php echo htmlspecialchars((string) $headerPatientPhotoUrl); ?>" alt="Profile photo" class="user-avatar user-avatar-photo">
                            <?php else: ?>
                                <div class="user-avatar"><?php echo htmlspecialchars($patientHeaderInitials); ?></div>
                            <?php endif; ?>
                            <span class="user-name"><?php echo htmlspecialchars($patientHeaderFirstName); ?></span>
                        </div>
                        <a href="patients.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'patients.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-9.5z"/></svg></span>
                            <span class="nav-label">Dashboard</span>
                        </a>
                        <a href="book_appointment.php?start=1" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'book_appointment.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4.5 9h15M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M12 12v5M9.5 14.5h5"/></svg></span>
                            <span class="nav-label">Book Appointments</span>
                        </a>
                        <a href="view_appointments.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'view_appointments.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 3v3M16 3v3M5 8h14M6 5h12a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/><path d="m8.5 14 2 2 5-5"/></svg></span>
                            <span class="nav-label">Appointment History</span>
                        </a>
                        <a href="patient_medical_records.php" class="has-svg-icon <?php echo basename($_SERVER['PHP_SELF']) === 'patient_medical_records.php' ? 'active' : ''; ?>">
                            <span class="nav-ui-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M14 3v5h5M8 13h8M8 17h6"/></svg></span>
                            <span class="nav-label">Medical Notes</span>
                        </a>
                    <?php endif; ?>
                    <form action="logout.php" method="post" style="display:inline;" class="logout-form">
                        <button type="submit" class="logout-btn">Log Out</button>
                    </form>
                <?php else: ?>
                    <a href="#home">Home</a>
                    <a href="#about">About</a>
                    <a href="#services">Services</a>
                    <a href="<?php echo htmlspecialchars($headerLoginHref); ?>" class="login-btn">Log In</a>
                    <a href="<?php echo htmlspecialchars($headerSignUpHref); ?>" class="signup-btn">Sign Up</a>
                <?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    </header>
    <?php if (isLoggedIn()): ?>
        <?php
        $patientTopbarName = trim((string) ($headerPatientDisplayName ?? $currentUser['full_name'] ?? 'Patient'));
        $patientTopbarInitial = trim((string) ($headerPatientInitials ?? strtoupper(substr($patientTopbarName, 0, 1))));
        $patientTopbarTitle = preg_replace('/\s*\|\s*.*/', '', (string) $pageTitle);
        $patientTopbarTitle = trim($patientTopbarTitle) !== '' ? trim($patientTopbarTitle) : 'Dashboard';
        $patientTopbarDashboardHref = dashboardForRole((string) ($currentUser['role'] ?? 'patient'));
        $patientTopbarProfileHref = dashboardForRole((string) ($currentUser['role'] ?? 'patient'));
        $patientTopbarSettingsHref = $patientTopbarProfileHref;
        if ($currentPage === 'patients.php') {
            $patientTopbarTitle = 'Dashboard';
            $patientTopbarProfileHref = 'patients.php?profile=1';
            $patientTopbarSettingsHref = 'patients.php?profile=1';
            if (isset($_GET['profile'])) {
                $patientTopbarTitle = 'My Profile';
            } elseif (isset($_GET['notifications'])) {
                $patientTopbarTitle = 'Notifications';
            }
        } elseif ($currentPage === 'admin.php') {
            $patientTopbarTitle = isset($_GET['notifications']) ? 'Notifications' : 'Dashboard';
            $patientTopbarSettingsHref = 'admin_clinic_setup.php';
        } elseif ($currentPage === 'clinic_notifications.php') {
            $patientTopbarTitle = 'Notifications';
        } elseif ($currentPage === 'book_appointment.php') {
            $patientTopbarTitle = 'Book Appointment';
            $patientTopbarProfileHref = 'patients.php?profile=1';
            $patientTopbarSettingsHref = 'patients.php?profile=1';
        } elseif ($currentPage === 'view_appointments.php') {
            $patientTopbarTitle = ($currentUser['role'] ?? '') === 'patient' ? 'My Appointments' : 'Appointments';
            if (($currentUser['role'] ?? '') === 'patient') {
                $patientTopbarProfileHref = 'patients.php?profile=1';
                $patientTopbarSettingsHref = 'patients.php?profile=1';
            }
        } elseif ($currentPage === 'patient_medical_records.php') {
            $patientTopbarTitle = 'Medical Notes';
            $patientTopbarProfileHref = 'patients.php?profile=1';
            $patientTopbarSettingsHref = 'patients.php?profile=1';
        } elseif ($currentPage === 'admin_clinic_setup.php') {
            $patientTopbarTitle = 'Clinic Setup';
            $patientTopbarSettingsHref = 'admin_clinic_setup.php';
        } elseif ($currentPage === 'admin_accounts.php') {
            $patientTopbarTitle = 'User Management';
            $patientTopbarSettingsHref = 'admin_clinic_setup.php';
        } elseif ($currentPage === 'admin_lab_services.php' || $currentPage === 'receptionist_lab_services.php') {
            $patientTopbarTitle = 'Laboratory Services';
        } elseif ($currentPage === 'admin_doctors.php' || $currentPage === 'receptionist_doctors.php') {
            $patientTopbarTitle = 'Doctors & Hours';
        } elseif ($currentPage === 'nurse.php') {
            $patientTopbarTitle = 'Clinical Dashboard';
        } elseif ($currentPage === 'receptionist.php') {
            $patientTopbarTitle = 'Clinic Queue';
        } elseif ($currentPage === 'calendar.php') {
            $patientTopbarTitle = 'Calendar';
        } elseif ($currentPage === 'nurse_patients.php' || $currentPage === 'nurse_patient.php') {
            $patientTopbarTitle = 'Patients';
        } elseif ($currentPage === 'nurse_lab.php') {
            $patientTopbarTitle = 'Medical Notes';
        } elseif ($currentPage === 'register_patient_receptionist.php') {
            $patientTopbarTitle = 'Register Patient';
        }
        $topbarRole = (string) ($currentUser['role'] ?? '');
        $topbarCalendarHref = $topbarRole === 'admin' ? 'calendar.php' : '';
        $topbarNotificationsHref = $topbarRole === 'patient'
            ? 'patients.php?notifications=1'
            : ($topbarRole === 'admin' ? 'admin.php?notifications=1' : ($topbarRole === 'doctor' ? 'clinic_notifications.php' : $patientTopbarDashboardHref));
        $topbarMarkNotificationsUrl = $topbarRole === 'admin'
            ? 'mark_admin_notifications.php'
            : ($topbarRole === 'doctor' ? 'mark_clinic_notifications.php' : 'mark_patient_notifications.php');
        ?>
        <div class="patient-app-topbar" aria-label="Workspace toolbar">
            <div class="topbar-breadcrumb" aria-label="Page location">
                <span class="topbar-breadcrumb-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M8 5h8v14H8z"/><path d="M12 9v6M15 12h-6"/></svg>
                </span>
                <span>Dashboard</span>
                <span class="topbar-chevron" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
                </span>
                <strong class="topbar-current"><?php echo htmlspecialchars($patientTopbarTitle); ?></strong>
            </div>
            <h2><?php echo htmlspecialchars($patientTopbarTitle); ?></h2>
            <div class="patient-topbar-actions">
                <?php if ($topbarCalendarHref !== ''): ?>
                    <a href="<?php echo htmlspecialchars($topbarCalendarHref); ?>" class="patient-round-btn topbar-calendar-link<?php echo $currentPage === 'calendar.php' ? ' is-active' : ''; ?>" aria-label="Open clinic calendar" title="Calendar">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3v3M16 3v3M5 8h14M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M8 12h3M13 12h3M8 16h3M13 16h3"/></svg>
                    </a>
                <?php endif; ?>
                <div class="patient-notification-wrap<?php echo $headerPatientUnreadNotifications > 0 ? ' has-unread' : ''; ?>" data-notification-menu>
                    <button type="button" class="patient-round-btn" aria-label="Open notifications" aria-haspopup="true" aria-expanded="false" data-notification-menu-button data-has-unread="<?php echo $headerPatientUnreadNotifications > 0 ? '1' : '0'; ?>" data-mark-read-url="<?php echo htmlspecialchars($topbarMarkNotificationsUrl); ?>">
                        <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"/><path d="M10 20a2 2 0 0 0 4 0"/></svg>
                        <?php if ($headerPatientUnreadNotifications > 0): ?>
                            <span class="patient-notification-dot" aria-label="<?php echo htmlspecialchars((string) $headerPatientUnreadNotifications); ?> unread notifications"><?php echo htmlspecialchars((string) min($headerPatientUnreadNotifications, 9)); ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="patient-notification-panel" role="menu" aria-label="Notifications">
                        <div class="notification-panel-head">
                            <h3 class="notification-panel-title">Notifications</h3>
                            <a class="notification-view-all" href="<?php echo htmlspecialchars($topbarNotificationsHref); ?>">View all</a>
                        </div>
                        <div class="notification-list">
                            <?php if (empty($headerPatientNotifications)): ?>
                                <div class="notification-empty">No notifications yet</div>
                            <?php else: ?>
                                <?php foreach (array_slice($headerPatientNotifications, 0, 4) as $notification): ?>
                                    <?php
                                    $notificationType = (string) ($notification['notification_type'] ?? '');
                                    $notificationUrl = trim((string) ($notification['target_url'] ?? ''));
                                    if ($notificationUrl === '') {
                                        $notificationUrl = $topbarRole === 'admin'
                                            ? 'admin.php?notifications=1#notification-' . (int) ($notification['id'] ?? 0)
                                            : 'view_appointments.php';
                                    }
                                    $notificationUnread = empty($notification['read_at']);
                                    $notificationTimestamp = strtotime((string) ($notification['created_at'] ?? ''));
                                    $notificationTime = $notificationTimestamp ? date('M j, g:i A', $notificationTimestamp) : '';
                                    $iconPath = 'M8 2v4M16 2v4M3 10h18 M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2z';
                                    if (strpos($notificationType, 'account') !== false || strpos($notificationType, 'user') !== false || strpos($notificationType, 'patient') !== false) {
                                        $iconPath = 'M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2M9.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M19 8v6M22 11h-6';
                                    } elseif (strpos($notificationType, 'confirmed') !== false || strpos($notificationType, 'approved') !== false) {
                                        $iconPath = 'm20 6-11 11-5-5';
                                    } elseif (strpos($notificationType, 'cancelled') !== false) {
                                        $iconPath = 'M18 6 6 18M6 6l12 12';
                                    } elseif (strpos($notificationType, 'completed') !== false) {
                                        $iconPath = 'M9 12l2 2 4-5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z';
                                    } elseif (strpos($notificationType, 'rescheduled') !== false) {
                                        $iconPath = 'M21 12a9 9 0 1 1-3-6.7M21 3v6h-6';
                                    }
                                    ?>
                                    <a href="<?php echo htmlspecialchars($notificationUrl); ?>" class="notification-item<?php echo $notificationUnread ? ' is-unread' : ''; ?>" role="menuitem">
                                        <span class="notification-item-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="<?php echo htmlspecialchars($iconPath); ?>"/></svg></span>
                                        <span>
                                            <strong><?php echo htmlspecialchars((string) ($notification['title'] ?? 'Appointment update')); ?></strong>
                                            <span><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></span>
                                            <?php if ($notificationTime !== ''): ?>
                                                <small class="notification-time"><?php echo htmlspecialchars($notificationTime); ?></small>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="patient-topbar-menu-wrap" data-profile-menu>
                    <button type="button" class="patient-profile-trigger" aria-label="Open account menu" aria-haspopup="true" aria-expanded="false" data-profile-menu-button>
                        <span class="patient-chip-avatar">
                            <?php if (!empty($headerPatientPhotoUrl)): ?>
                                <img src="<?php echo htmlspecialchars((string) $headerPatientPhotoUrl); ?>" alt="">
                            <?php else: ?>
                                <?php echo htmlspecialchars($patientTopbarInitial); ?>
                            <?php endif; ?>
                        </span>
                        <span class="patient-topbar-name"><?php echo htmlspecialchars($patientTopbarName); ?></span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="patient-profile-menu" role="menu" aria-label="Account menu">
                        <a href="<?php echo htmlspecialchars($patientTopbarDashboardHref); ?>" role="menuitem">
                            <span class="profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-9.5z"/></svg></span>
                            <span>Dashboard</span>
                        </a>
                        <a href="<?php echo htmlspecialchars($patientTopbarProfileHref); ?>" role="menuitem">
                            <span class="profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><path d="M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg></span>
                            <span>My Profile</span>
                        </a>
                        <a href="<?php echo htmlspecialchars($topbarNotificationsHref); ?>" role="menuitem">
                            <span class="profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"/><path d="M10 20a2 2 0 0 0 4 0"/></svg></span>
                            <span>Notifications</span>
                        </a>
                        <form action="logout.php" method="post" class="logout-form profile-menu-form">
                            <button type="submit" class="profile-menu-logout" role="menuitem">
                                <span class="profile-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg></span>
                                <span>Log Out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <?php if (isLoggedIn()): ?>
        <div class="logout-confirm-overlay" id="logoutConfirm" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-hidden="true">
            <div class="logout-confirm-box">
                <div class="logout-confirm-icon" aria-hidden="true">?</div>
                <h2 id="logoutConfirmTitle">Are you sure you want to log out?</h2>
                <p>You will be returned to the homepage. Make sure any changes you need are saved before leaving.</p>
                <div class="logout-confirm-actions">
                    <button type="button" class="logout-cancel-btn" data-logout-cancel>Stay Logged In</button>
                    <button type="button" class="logout-confirm-btn" data-logout-confirm>Yes, Log Out</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script>
    function toggleMobileMenu() {
        const nav = document.getElementById('mainNav');
        if (nav) {
            nav.classList.toggle('active');
        }
    }
    document.addEventListener('click', function(event) {
        const nav = document.getElementById('mainNav');
        const toggle = document.querySelector('.mobile-menu-toggle');
        if (nav && toggle && !nav.contains(event.target) && !toggle.contains(event.target)) {
            nav.classList.remove('active');
        }
    });
    window.addEventListener('scroll', function() {
        const header = document.getElementById('mainHeader');
        if (window.scrollY > 50) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    });
    (function() {
        const menuWrap = document.querySelector('[data-profile-menu]');
        if (!menuWrap) {
            return;
        }
        const menuButton = menuWrap.querySelector('[data-profile-menu-button]');
        function closeProfileMenu() {
            menuWrap.classList.remove('is-open');
            if (menuButton) {
                menuButton.setAttribute('aria-expanded', 'false');
            }
        }
        function toggleProfileMenu() {
            const isOpen = menuWrap.classList.toggle('is-open');
            if (menuButton) {
                menuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
        }
        if (menuButton) {
            menuButton.addEventListener('click', function(event) {
                event.stopPropagation();
                toggleProfileMenu();
            });
        }
        document.addEventListener('click', function(event) {
            if (!menuWrap.contains(event.target)) {
                closeProfileMenu();
            }
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeProfileMenu();
            }
        });
    })();
    (function() {
        const menuWrap = document.querySelector('[data-notification-menu]');
        if (!menuWrap) {
            return;
        }
        const menuButton = menuWrap.querySelector('[data-notification-menu-button]');
        const profileWrap = document.querySelector('[data-profile-menu]');
        let readRequestSent = false;
        function clearUnreadState() {
            menuWrap.classList.remove('has-unread');
            if (menuButton) {
                menuButton.dataset.hasUnread = '0';
            }
            const dot = menuWrap.querySelector('.patient-notification-dot');
            if (dot) {
                dot.remove();
            }
            menuWrap.querySelectorAll('.notification-item.is-unread').forEach(function(item) {
                item.classList.remove('is-unread');
            });
        }
        function markNotificationsRead() {
            if (readRequestSent || !menuButton || menuButton.dataset.hasUnread !== '1') {
                return;
            }
            readRequestSent = true;
            fetch(menuButton.dataset.markReadUrl || 'mark_patient_notifications.php', {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin'
            })
                .then(function(response) { return response.ok ? response.json() : {ok: false}; })
                .then(function(data) {
                    if (data && data.ok) {
                        clearUnreadState();
                    }
                })
                .catch(function() {
                    readRequestSent = false;
                });
        }
        function closeNotificationMenu() {
            menuWrap.classList.remove('is-open');
            if (menuButton) {
                menuButton.setAttribute('aria-expanded', 'false');
            }
        }
        function toggleNotificationMenu() {
            const isOpen = menuWrap.classList.toggle('is-open');
            if (profileWrap) {
                profileWrap.classList.remove('is-open');
                const profileButton = profileWrap.querySelector('[data-profile-menu-button]');
                if (profileButton) {
                    profileButton.setAttribute('aria-expanded', 'false');
                }
            }
            if (menuButton) {
                menuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
            if (isOpen) {
                window.setTimeout(markNotificationsRead, 500);
            }
        }
        if (menuButton) {
            menuButton.addEventListener('click', function(event) {
                event.stopPropagation();
                toggleNotificationMenu();
            });
        }
        document.addEventListener('click', function(event) {
            if (!menuWrap.contains(event.target)) {
                closeNotificationMenu();
            }
        });
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeNotificationMenu();
            }
        });
    })();
    (function() {
        const logoutForms = document.querySelectorAll('.logout-form');
        const modal = document.getElementById('logoutConfirm');
        const cancelBtn = document.querySelector('[data-logout-cancel]');
        const confirmBtn = document.querySelector('[data-logout-confirm]');
        let pendingForm = null;

        function openLogoutConfirm(form) {
            pendingForm = form;
            if (!modal) {
                form.submit();
                return;
            }
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            if (confirmBtn) {
                confirmBtn.focus();
            }
        }

        function closeLogoutConfirm() {
            pendingForm = null;
            if (!modal) {
                return;
            }
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
        }

        logoutForms.forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (form.dataset.confirmed === 'true') {
                    return;
                }
                event.preventDefault();
                openLogoutConfirm(form);
            });
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', closeLogoutConfirm);
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                if (!pendingForm) {
                    closeLogoutConfirm();
                    return;
                }
                pendingForm.dataset.confirmed = 'true';
                pendingForm.submit();
            });
        }

        if (modal) {
            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeLogoutConfirm();
                }
            });
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal && modal.classList.contains('is-open')) {
                closeLogoutConfirm();
            }
        });
    })();
    </script>

<?php
require_once 'includes/session.php';

$loginError = '';
$submittedUsername = '';
$loginSuccess = '';

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $loginSuccess = 'You have successfully created an account. Sign in to continue.';
} elseif (isset($_GET['reset']) && $_GET['reset'] === '1') {
    $loginSuccess = 'Your password was updated successfully. Sign in with your new password.';
}

$currentUser = getCurrentUser();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $currentUser) {
    redirectToDashboardForCurrentUser();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['portal_login'])) {
    $submittedUsername = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($submittedUsername === '' || $password === '') {
        $loginError = 'Please enter your username and password.';
    } elseif (login($submittedUsername, $password)) {
        $user = getCurrentUser();

        if ($user) {
            if ($user['role'] === 'patient' && !empty($_SESSION['patient_pending_welcome'])) {
                $_SESSION['patient_welcome_new'] = true;
                unset($_SESSION['patient_pending_welcome']);
            }
            header('Location: ' . dashboardForRole($user['role']));
            exit();
        }

        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['full_name'], $_SESSION['user_role']);
        $loginError = 'We could not open this account. Please try again.';
    } else {
        $loginError = 'Invalid username or password. Check your details and try again.';
    }
}

$isPatientLoggedIn = $currentUser && $currentUser['role'] === 'patient';
$isLoggedInOnHome = $currentUser !== null;
$currentUserDashboard = $currentUser ? dashboardForRole($currentUser['role']) : 'index.php';
$publicLoginHref = '#patient-login';
$publicSignUpHref = 'register_patient.php';
$pageTitle = "Globalife Medical Appointment System";
$additionalStyles = '
    body {
        background: #f5f8fa;
    }

    html {
        scroll-behavior: smooth;
    }

    #home,
    #visit-guide,
    #about,
    #services,
    #contact {
        scroll-margin-top: 100px;
    }

    .hero .container,
    .visit-guide-section .container,
    .about-section .container,
    #services .container,
    .patient-login-section .container,
    .contact-band .container {
        max-width: 1120px;
    }

    .hero {
        position: relative;
        overflow: hidden;
        text-align: left;
        padding: 86px 0 74px;
        background:
            radial-gradient(circle at 12% 18%, rgba(72, 202, 228, 0.24), transparent 28%),
            radial-gradient(circle at 88% 12%, rgba(46, 196, 182, 0.18), transparent 24%),
            linear-gradient(135deg, #eaf9fd 0%, #f7fbf6 55%, #fff4ed 100%);
    }

    .hero::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: 0;
        height: 1px;
        background: linear-gradient(90deg, transparent, rgba(0, 119, 182, 0.22), transparent);
    }

    .hero-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
        gap: 44px;
        align-items: center;
    }

    .eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        color: #006d77;
        font-size: 0.9rem;
        font-weight: 700;
        margin-bottom: 16px;
    }

    .eyebrow::before {
        content: "";
        width: 34px;
        height: 2px;
        border-radius: 8px;
        background: #2ec4b6;
    }

    .hero h2 {
        color: #073b4c;
        font-size: clamp(2.15rem, 4vw, 4rem);
        line-height: 1.05;
        margin: 0 0 18px;
        max-width: 760px;
    }

    .hero p {
        color: #40525b;
        font-size: 1.08rem;
        line-height: 1.75;
        margin: 0 0 28px;
        max-width: 660px;
    }

    .hero-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        margin-bottom: 26px;
    }

    .cta-btn,
    .secondary-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 46px;
        padding: 12px 24px;
        border-radius: 8px;
        font-weight: 700;
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .cta-btn {
        background: #0077b6;
        color: #fff;
        box-shadow: 0 12px 24px rgba(0, 119, 182, 0.22);
    }

    .cta-btn:hover {
        background: #023e8a;
        transform: translateY(-2px);
        box-shadow: 0 16px 30px rgba(2, 62, 138, 0.24);
    }

    .secondary-btn {
        color: #006d77;
        background: rgba(255, 255, 255, 0.74);
        border: 1px solid rgba(0, 109, 119, 0.18);
    }

    .secondary-btn:hover {
        background: #fff;
        transform: translateY(-2px);
        box-shadow: 0 12px 24px rgba(7, 59, 76, 0.1);
    }

    .hero-highlights {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
    }

    .hero-highlights span {
        color: #16434f;
        background: rgba(255, 255, 255, 0.72);
        border: 1px solid rgba(0, 119, 182, 0.14);
        border-radius: 8px;
        padding: 9px 12px;
        font-size: 0.92rem;
        font-weight: 600;
    }

    .patient-paths {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 18px;
    }

    .patient-path {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 700;
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .patient-path--new {
        background: #fff;
        color: #0077b6;
        border: 2px solid #48cae4;
        box-shadow: 0 8px 18px rgba(0, 119, 182, 0.1);
    }

    .patient-path--return {
        background: rgba(0, 119, 182, 0.08);
        color: #073b4c;
        border: 1px solid rgba(0, 119, 182, 0.2);
    }

    .patient-path:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(0, 119, 182, 0.14);
    }

    .visit-guide-section {
        background: #fff;
        padding: 56px 0 64px;
        border-bottom: 1px solid #e3eef2;
    }

    .visit-steps {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 16px;
        margin-top: 28px;
    }

    .visit-step-card {
        background: #f8fcfd;
        border: 1px solid #dceef2;
        border-radius: 12px;
        padding: 22px 18px;
        position: relative;
        box-shadow: 0 10px 22px rgba(7, 59, 76, 0.05);
    }

    .visit-step-num {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: linear-gradient(135deg, #48cae4, #0077b6);
        color: #fff;
        font-weight: 800;
        font-size: 0.9rem;
        margin-bottom: 12px;
    }

    .visit-step-card h4 {
        color: #073b4c;
        font-size: 1rem;
        margin: 0 0 8px;
    }

    .visit-step-card p {
        color: #566872;
        font-size: 0.9rem;
        line-height: 1.55;
        margin: 0;
    }

    .visit-step-card a {
        color: #0077b6;
        font-weight: 700;
        text-decoration: none;
    }

    .visit-step-card a:hover {
        text-decoration: underline;
    }

    .clinic-essentials {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-top: 28px;
    }

    .essential-card {
        display: flex;
        gap: 14px;
        align-items: center;
        background: #f8fcfd;
        border: 1px solid #dceef2;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 8px 24px rgba(13, 88, 126, 0.06);
    }

    .essential-icon {
        flex-shrink: 0;
        width: 54px;
        height: 54px;
        border-radius: 16px;
        background: linear-gradient(145deg, #48cae4, #0077b6);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.22);
    }

    .essential-icon svg {
        width: 28px;
        height: 28px;
        stroke-width: 2;
    }

    .essential-copy {
        min-width: 0;
    }

    .essential-card h4 {
        color: #073b4c;
        font-size: 0.98rem;
        margin: 0 0 6px;
    }

    .essential-card p {
        color: #566872;
        font-size: 0.88rem;
        line-height: 1.55;
        margin: 0;
    }

    .services-note {
        margin-top: 22px;
        padding: 14px 18px;
        background: #fff8e6;
        border: 1px solid #ffe08a;
        border-radius: 10px;
        color: #5c4a1a;
        font-size: 0.92rem;
        line-height: 1.6;
    }

    .login-first-time {
        background: linear-gradient(135deg, #e8f8fc, #f0faf9);
        border: 1px solid rgba(72, 202, 228, 0.5);
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 16px;
        font-size: 0.88rem;
        color: #435761;
        line-height: 1.55;
    }

    .login-first-time strong {
        color: #0077b6;
    }

    .login-first-time a {
        color: #0077b6;
        font-weight: 700;
        text-decoration: none;
    }

    .login-first-time a:hover {
        text-decoration: underline;
    }

    .field-hint {
        display: block;
        margin-top: 6px;
        font-size: 0.8rem;
        color: #6c7a83;
        line-height: 1.4;
    }

    .login-help-box {
        margin-top: 14px;
        padding: 12px 14px;
        background: #f8fcfd;
        border-radius: 10px;
        border: 1px dashed #b8dfe8;
        font-size: 0.82rem;
        color: #566872;
        line-height: 1.55;
    }

    .login-help-box a {
        color: #0077b6;
        font-weight: 700;
        text-decoration: none;
    }

    .contact-details {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-top: 18px;
    }

    .contact-detail-item {
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.14);
        border-radius: 10px;
        padding: 14px 16px;
    }

    .contact-detail-item strong {
        display: block;
        color: #caf0f8;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 6px;
    }

    .contact-detail-item span {
        color: rgba(255, 255, 255, 0.9);
        font-size: 0.9rem;
        line-height: 1.5;
    }

    .hero-panel {
        background: rgba(255, 255, 255, 0.86);
        border: 1px solid rgba(0, 119, 182, 0.16);
        border-radius: 8px;
        padding: 28px;
        box-shadow: 0 22px 50px rgba(7, 59, 76, 0.12);
    }

    .hero-logo-shell {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 132px;
        height: 132px;
        margin-bottom: 24px;
        border-radius: 50%;
        background: #fff;
        border: 1px solid rgba(72, 202, 228, 0.55);
        box-shadow: 0 16px 30px rgba(0, 119, 182, 0.12);
    }

    .hero-logo-shell img {
        width: 108px;
        height: 108px;
        object-fit: contain;
        border-radius: 50%;
    }

    .panel-label {
        color: #0077b6 !important;
        font-size: 0.88rem !important;
        font-weight: 700;
        margin: 0 0 8px !important;
    }

    .hero-panel h3 {
        color: #073b4c;
        font-size: 1.35rem;
        line-height: 1.35;
        margin: 0 0 20px;
    }

    .quick-list {
        display: grid;
        gap: 12px;
    }

    .quick-item,
    .login-benefits li {
        display: grid;
        grid-template-columns: 52px 1fr;
        gap: 14px;
        align-items: center;
        color: #334b57;
        font-weight: 600;
        line-height: 1.45;
    }

    .clinic-mark {
        position: relative;
        width: 48px;
        height: 48px;
        flex-shrink: 0;
        border-radius: 14px;
        background: linear-gradient(145deg, #48cae4 0%, #0077b6 100%);
        box-shadow: 0 10px 22px rgba(0, 119, 182, 0.22);
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid rgba(255, 255, 255, 0.45);
    }

    .clinic-mark--service {
        width: 52px;
        height: 52px;
        border-radius: 16px;
    }

    .clinic-mark-plus {
        position: absolute;
        top: 3px;
        left: 7px;
        font-size: 1.05rem;
        font-weight: 800;
        color: #fff;
        line-height: 1;
        z-index: 2;
        text-shadow: 0 1px 3px rgba(2, 62, 138, 0.35);
    }

    .clinic-mark-icon {
        width: 26px;
        height: 26px;
        color: #fff;
        margin-top: 8px;
        filter: drop-shadow(0 1px 2px rgba(2, 62, 138, 0.2));
    }

    .clinic-mark--service .clinic-mark-icon {
        width: 28px;
        height: 28px;
    }

    .about-section {
        background: #fff;
        padding: 72px 0;
        border-top: 1px solid #e3eef2;
        border-bottom: 1px solid #e3eef2;
    }

    .section-heading {
        max-width: 760px;
        margin-bottom: 28px;
    }

    .section-heading h3 {
        color: #073b4c;
        font-size: 2rem;
        margin-bottom: 12px;
    }

    .section-heading p {
        color: #51636d;
        line-height: 1.75;
        margin: 0;
    }

    .mission-vision {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
        margin-top: 26px;
    }

    .mission-vision > div {
        background: #fff;
        border: 1px solid #d8edf1;
        border-radius: 8px;
        padding: 24px;
        box-shadow: 0 12px 24px rgba(7, 59, 76, 0.06);
    }

    .mission-vision h4 {
        color: #006d77;
        font-size: 1rem;
        margin-top: 0;
        margin-bottom: 12px;
    }

    .mission-vision p {
        color: #435761;
        line-height: 1.7;
        margin-bottom: 0;
    }

    #services {
        background: #f5f8fa;
        padding: 72px 0;
    }

    .services-list {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .services-list li {
        display: grid;
        grid-template-columns: 52px 1fr;
        gap: 14px;
        align-items: flex-start;
        background: #fff;
        border: 1px solid #dceef2;
        border-radius: 12px;
        padding: 22px;
        box-shadow: 0 10px 22px rgba(7, 59, 76, 0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .services-list li:hover {
        transform: translateY(-3px);
        box-shadow: 0 16px 28px rgba(0, 119, 182, 0.12);
    }

    .services-list strong {
        display: block;
        color: #073b4c;
        margin-bottom: 6px;
    }

    .services-list p {
        color: #566872;
        line-height: 1.55;
        margin: 0;
        font-size: 0.95rem;
    }

    .patient-login-section {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(7, 59, 76, 0.62);
        opacity: 0;
        pointer-events: none;
        visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
    }

    .patient-login-section.is-open {
        opacity: 1;
        pointer-events: auto;
        visibility: visible;
    }

    .patient-login-grid {
        display: grid;
        grid-template-columns: minmax(0, 0.9fr) minmax(320px, 1.1fr);
        gap: 30px;
        align-items: center;
        position: relative;
        width: min(100%, 980px);
        max-height: calc(100vh - 48px);
        box-sizing: border-box;
        overflow: auto;
        background: #fff;
        border: 1px solid rgba(216, 237, 241, 0.9);
        border-radius: 8px;
        padding: 34px;
        box-shadow: 0 28px 70px rgba(0, 0, 0, 0.24);
    }

    .modal-close-btn {
        position: absolute;
        top: 14px;
        right: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border: 0;
        border-radius: 50%;
        background: #eef8fa;
        color: #073b4c;
        cursor: pointer;
        font-size: 1.4rem;
        line-height: 1;
        text-decoration: none;
        transition: background 0.2s ease, transform 0.2s ease;
    }

    .modal-close-btn:hover {
        background: #d8edf1;
        transform: rotate(90deg);
    }

    .patient-login-copy h3 {
        color: #073b4c;
        font-size: 2rem;
        line-height: 1.2;
        margin: 0 0 14px;
    }

    .patient-login-copy p {
        color: #51636d;
        line-height: 1.75;
        margin: 0 0 22px;
    }

    .login-benefits {
        display: grid;
        gap: 12px;
        margin: 0;
        padding: 0;
        list-style: none;
    }


    .patient-login-panel,
    .patient-ready-panel {
        background: transparent;
        border: 0;
        border-radius: 0;
        padding: 0;
        box-shadow: none;
    }

    .patient-login-panel h4,
    .patient-ready-panel h4 {
        color: #073b4c;
        font-size: 1.35rem;
        margin: 0 0 8px;
    }

    .patient-login-panel > p,
    .patient-ready-panel > p {
        color: #5d6d76;
        line-height: 1.65;
        margin: 0 0 22px;
    }

    .login-alert {
        background: #fff0f0;
        border: 1px solid #ffd2d2;
        border-left: 4px solid #d90429;
        border-radius: 8px;
        color: #8f1d2c;
        font-weight: 600;
        line-height: 1.45;
        margin-bottom: 18px;
        padding: 12px 14px;
    }

    .login-alert.success {
        background: #eefaf2;
        border-color: #c7ead2;
        border-left-color: #218838;
        color: #17652b;
    }

    .patient-form-group {
        margin-bottom: 16px;
    }

    .patient-form-group label {
        display: block;
        color: #213943;
        font-size: 0.9rem;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .patient-input-wrap {
        position: relative;
    }

    .patient-input-wrap input {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #cfe4e9;
        border-radius: 8px;
        background: #fff;
        color: #1f343d;
        font-size: 1rem;
        min-height: 48px;
        padding: 12px 14px;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .patient-input-wrap input:focus {
        border-color: #0077b6;
        box-shadow: 0 0 0 4px rgba(0, 119, 182, 0.1);
        outline: none;
    }

    .patient-input-wrap input[type="password"],
    .patient-input-wrap input[type="text"].password-visible {
        padding-right: 74px;
    }

    .password-toggle-btn {
        position: absolute;
        top: 50%;
        right: 8px;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #0077b6;
        cursor: pointer;
        font-weight: 700;
        padding: 8px;
    }

    .password-toggle-btn:hover {
        color: #023e8a;
    }

    .patient-submit-btn {
        width: 100%;
        min-height: 48px;
        border: 0;
        border-radius: 8px;
        background: #0077b6;
        color: #fff;
        cursor: pointer;
        font-size: 1rem;
        font-weight: 800;
        margin-top: 6px;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .patient-submit-btn:hover {
        background: #023e8a;
        box-shadow: 0 12px 24px rgba(2, 62, 138, 0.2);
        transform: translateY(-2px);
    }

    .login-panel-links {
        display: flex;
        flex-wrap: wrap;
        gap: 10px 18px;
        justify-content: center;
        margin-top: 18px;
        color: #5d6d76;
        font-size: 0.94rem;
    }

    .login-panel-links a {
        color: #0077b6;
        font-weight: 700;
        text-decoration: none;
    }

    .login-panel-links a:hover {
        color: #023e8a;
        text-decoration: underline;
    }

    .patient-ready-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .contact-band {
        padding: 44px 0;
        background: #073b4c;
        color: #fff;
    }

    .contact-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 24px;
        align-items: center;
    }

    .contact-band h3 {
        color: #fff;
        font-size: 1.7rem;
        margin: 0 0 10px;
    }

    .contact-band p {
        color: rgba(255, 255, 255, 0.82);
        margin: 0;
        line-height: 1.7;
    }

    .contact-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        justify-content: flex-end;
    }

    .contact-band .secondary-btn {
        color: #fff;
        background: transparent;
        border-color: rgba(255, 255, 255, 0.32);
    }

    .contact-band .secondary-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        box-shadow: none;
    }

    @media (max-width: 900px) {
        .hero {
            padding: 58px 0 52px;
        }

        .hero-grid,
        .contact-grid {
            grid-template-columns: 1fr;
        }

        .hero-panel {
            max-width: 520px;
        }

        .mission-vision,
        .visit-steps,
        .clinic-essentials,
        .contact-details,
        .services-list,
        .patient-login-grid {
            grid-template-columns: 1fr;
        }

        .visit-steps {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .contact-actions {
            justify-content: flex-start;
        }
    }

    @media (max-width: 560px) {
        .hero h2 {
            font-size: 2.1rem;
        }

        .hero-actions,
        .contact-actions,
        .patient-ready-actions {
            flex-direction: column;
        }

        .cta-btn,
        .secondary-btn,
        .patient-submit-btn {
            width: 100%;
            box-sizing: border-box;
        }

        .patient-login-section {
            align-items: flex-start;
            padding: 16px;
        }

        .patient-login-grid {
            padding: 46px 20px 22px;
        }

        .visit-steps {
            grid-template-columns: 1fr;
        }
    }
';

$additionalScripts = '
    document.addEventListener("DOMContentLoaded", function () {
        const loginModal = document.getElementById("patient-login");
        const loginTriggers = document.querySelectorAll("a[href=\"#patient-login\"]");
        const closeTriggers = document.querySelectorAll("[data-login-close]");
        const passwordInput = document.getElementById("patient-password");
        const passwordToggle = document.querySelector("[data-password-toggle]");
        const shouldOpenPatientLogin = ' . (($loginError !== '' || $loginSuccess !== '') ? 'true' : 'false') . ';

        function openPatientLogin(event) {
            if (event) {
                event.preventDefault();
            }

            if (!loginModal) {
                return;
            }

            loginModal.classList.add("is-open");
            loginModal.setAttribute("aria-hidden", "false");

            if (window.location.hash !== "#patient-login") {
                history.replaceState(null, "", "#patient-login");
            }

            const firstInput = document.getElementById("patient-username");
            if (firstInput) {
                setTimeout(function () {
                    firstInput.focus();
                }, 80);
            }
        }

        function closePatientLogin(event) {
            if (event) {
                event.preventDefault();
            }

            if (!loginModal) {
                return;
            }

            loginModal.classList.remove("is-open");
            loginModal.setAttribute("aria-hidden", "true");

            if (window.location.hash === "#patient-login") {
                history.replaceState(null, "", window.location.pathname + window.location.search);
            }
        }

        loginTriggers.forEach(function (trigger) {
            trigger.addEventListener("click", openPatientLogin);
        });

        closeTriggers.forEach(function (trigger) {
            trigger.addEventListener("click", closePatientLogin);
        });

        if (loginModal) {
            loginModal.addEventListener("click", function (event) {
                if (event.target === loginModal) {
                    closePatientLogin(event);
                }
            });
        }

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && loginModal && loginModal.classList.contains("is-open")) {
                closePatientLogin(event);
            }
        });

        if (passwordInput && passwordToggle) {
            passwordToggle.addEventListener("click", function () {
                const isHidden = passwordInput.type === "password";
                passwordInput.type = isHidden ? "text" : "password";
                passwordInput.classList.toggle("password-visible", isHidden);
                passwordToggle.textContent = isHidden ? "Hide" : "Show";
                passwordToggle.setAttribute("aria-pressed", isHidden ? "true" : "false");
            });
        }

        if (shouldOpenPatientLogin || window.location.hash === "#patient-login") {
            openPatientLogin();
        }

        function scrollToPageSection(hash) {
            if (!hash || hash === "#patient-login") {
                return;
            }
            const target = document.querySelector(hash);
            if (!target) {
                return;
            }
            const header = document.getElementById("mainHeader");
            const offset = header ? header.offsetHeight + 20 : 90;
            const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
            window.scrollTo({ top: Math.max(0, top), behavior: "smooth" });
        }

        document.querySelectorAll("a[href=\"#home\"], a[href=\"#visit-guide\"], a[href=\"#about\"], a[href=\"#services\"], a[href=\"#contact\"]").forEach(function (link) {
            link.addEventListener("click", function (event) {
                const hash = link.getAttribute("href");
                const target = hash ? document.querySelector(hash) : null;
                if (!target) {
                    return;
                }
                event.preventDefault();
                scrollToPageSection(hash);
                history.replaceState(null, "", hash);
            });
        });

        const logoLink = document.querySelector(".logo-section[data-logo-home=\"1\"]");
        if (logoLink) {
            logoLink.addEventListener("click", function (event) {
                const target = document.querySelector("#home");
                if (!target) {
                    return;
                }
                event.preventDefault();
                scrollToPageSection("#home");
                history.replaceState(null, "", "#home");
            });
        }

        if (window.location.hash === "#home" || window.location.hash === "#visit-guide" || window.location.hash === "#about" || window.location.hash === "#services" || window.location.hash === "#contact") {
            window.setTimeout(function () {
                scrollToPageSection(window.location.hash);
            }, 120);
        }
    });
';

include 'includes/header.php';
?>
    <section id="home" class="hero">
        <div class="container hero-grid">
            <div class="hero-copy">
                <span class="eyebrow">Medical laboratory and polyclinic</span>
                <h2>Book clinic and laboratory visits with less waiting.</h2>
                <p>
                    Welcome to Globalife Medical Laboratory & Polyclinic. Create your account,
                    log in, and choose the clinic or laboratory service you need.
                </p>
                <div class="patient-paths">
                    <a href="register_patient.php" class="patient-path patient-path--new">New here? Sign Up</a>
                    <a href="#patient-login" class="patient-path patient-path--return">Already have an account? Log In</a>
                </div>
                <div class="hero-actions">
                    <a href="#patient-login" class="cta-btn">Book Appointment</a>
                    <a href="#about" class="secondary-btn">About Us</a>
                    <a href="#services" class="secondary-btn">View Services</a>
                </div>
                <div class="hero-highlights" aria-label="Clinic highlights">
                    <span>Online booking</span>
                    <span>Laboratory services</span>
                    <span>Clinic check-ups</span>
                </div>
            </div>

            <aside class="hero-panel" aria-label="Globalife care summary">
                <div class="hero-logo-shell">
                    <img src="globalife.png" alt="Globalife clinic logo">
                </div>
                <p class="panel-label">Globalife Medical Laboratory & Polyclinic</p>
                <h3>Reliable healthcare support for everyday clinic and laboratory needs.</h3>
                <div class="quick-list">
                    <div class="quick-item">
                        <span class="clinic-mark" aria-hidden="true">
                            <span class="clinic-mark-plus">+</span>
                            <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                            </svg>
                        </span>
                        <span>Friendly, compassionate care support</span>
                    </div>
                    <div class="quick-item">
                        <span class="clinic-mark" aria-hidden="true">
                            <span class="clinic-mark-plus">+</span>
                            <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </span>
                        <span>Simple appointment coordination</span>
                    </div>
                    <div class="quick-item">
                        <span class="clinic-mark" aria-hidden="true">
                            <span class="clinic-mark-plus">+</span>
                            <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                            </svg>
                        </span>
                        <span>Laboratory and medical services in one place</span>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <section id="visit-guide" class="visit-guide-section">
        <div class="container">
            <div class="section-heading">
                <h3>How to book your clinic visit</h3>
                <p>Follow these steps to book online and prepare for your visit.</p>
            </div>
            <div class="visit-steps">
                <div class="visit-step-card">
                    <span class="visit-step-num">1</span>
                    <h4>Create your account</h4>
                    <p>First-time visitors should <a href="register_patient.php">Sign Up</a> with correct personal details, email, and mobile number.</p>
                </div>
                <div class="visit-step-card">
                    <span class="visit-step-num">2</span>
                    <h4>Log In</h4>
                    <p>Use your <strong>username</strong> and <strong>password</strong>. If you forgot your password, reset it using your registered email.</p>
                </div>
                <div class="visit-step-card">
                    <span class="visit-step-num">3</span>
                    <h4>Book online</h4>
                    <p>After logging in, choose a clinic consultation or laboratory service and select your schedule.</p>
                </div>
                <div class="visit-step-card">
                    <span class="visit-step-num">4</span>
                    <h4>Visit the clinic</h4>
                    <p>Bring a <strong>valid ID</strong>, arrive on time, and pay at the clinic unless staff gives other instructions.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="about" class="about-section">
        <div class="container">
            <div class="section-heading">
                <h3>About Us</h3>
                <p>
                    Globalife Medical Laboratory & Polyclinic provides practical and affordable care
                    for the community. We offer laboratory tests, check-ups, and medical services with
                    a team ready to assist every visitor clearly and respectfully.
                </p>
            </div>
            <div class="mission-vision">
                <div>
                    <h4>Mission</h4>
                    <p>
                        Our mission is to help improve community health by providing reliable laboratory
                        and clinic services. We aim to serve every guest with respect, professionalism,
                        teamwork, and clear communication.
                    </p>
                </div>
                <div>
                    <h4>Vision</h4>
                    <p>
                        Our vision is to be a trusted healthcare provider known for dependable service,
                        people-first care, and continuous improvement in clinic and laboratory work.
                    </p>
                </div>
            </div>
            <div class="clinic-essentials" aria-label="Important information for clinic visitors">
                <div class="essential-card">
                    <span class="essential-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                            <circle cx="9" cy="11" r="2"></circle>
                            <path d="M13.5 10h3.5M13.5 14h3.5M7 16c.7-1 1.5-1.5 2.5-1.5S11.3 15 12 16"></path>
                        </svg>
                    </span>
                    <div class="essential-copy">
                        <h4>Valid ID required</h4>
                        <p>Please bring a government-issued ID on your visit for verification and clinic records.</p>
                    </div>
                </div>
                <div class="essential-card">
                    <span class="essential-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M4 7h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z"></path>
                            <path d="M2 10h20"></path>
                            <path d="M7 15h4"></path>
                        </svg>
                    </span>
                    <div class="essential-copy">
                        <h4>Payment at the clinic</h4>
                        <p>Consultation and laboratory fees are paid at the clinic front desk unless our staff gives other instructions.</p>
                    </div>
                </div>
                <div class="essential-card">
                    <span class="essential-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M12 7v5l3 2"></path>
                        </svg>
                    </span>
                    <div class="essential-copy">
                        <h4>Arrive on time</h4>
                        <p>Come a few minutes before your scheduled slot. Late arrivals may need to be rescheduled depending on clinic capacity.</p>
                    </div>
                </div>
                <div class="essential-card">
                    <span class="essential-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                            <path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"></path>
                            <path d="M14 3v5h5"></path>
                            <path d="M9 13h6M9 17h6"></path>
                        </svg>
                    </span>
                    <div class="essential-copy">
                        <h4>Medical documents</h4>
                        <p>Bring previous lab results, prescriptions, or referral letters if you have them. These help our doctors and staff assist you faster.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <section id="services">
        <div class="container">
            <div class="section-heading">
                <h3>Our Services</h3>
                <p>Choose from clinic and laboratory services for everyday health needs.</p>
            </div>
            <ul class="services-list">
                <li>
                    <span class="clinic-mark clinic-mark--service" aria-hidden="true">
                        <span class="clinic-mark-plus">+</span>
                        <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                    </span>
                    <div>
                        <strong>General Consultation</strong>
                        <p>Schedule check-ups and consultations with clinic professionals.</p>
                    </div>
                </li>
                <li>
                    <span class="clinic-mark clinic-mark--service" aria-hidden="true">
                        <span class="clinic-mark-plus">+</span>
                        <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>
                        </svg>
                    </span>
                    <div>
                        <strong>Laboratory Tests</strong>
                        <p>Access routine laboratory testing for better health decisions.</p>
                    </div>
                </li>
                <li>
                    <span class="clinic-mark clinic-mark--service" aria-hidden="true">
                        <span class="clinic-mark-plus">+</span>
                        <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                    </span>
                    <div>
                        <strong>Home Healthcare Service</strong>
                        <p>Ask our staff about care support when visiting the clinic is difficult.</p>
                    </div>
                </li>
            </ul>
            <p class="services-note">
                <strong>Good to know:</strong> Online booking reserves your slot. Final service availability and pricing are confirmed at the clinic. For home service or pickup and delivery, coordinate with our staff.
            </p>
        </div>
    </section>
    <section id="contact" class="contact-band">
        <div class="container contact-grid">
            <div>
                <h3>Ready to schedule your visit?</h3>
                <p>
                    <strong>New here?</strong> Sign Up first, then Log In to book.
                    <strong>Already registered?</strong> Log In with your username and password to continue.
                </p>
                <div class="contact-details">
                    <div class="contact-detail-item">
                        <strong>Clinic hours</strong>
                        <span>Monday to Saturday. Please contact our front desk for today&#39;s schedule and holidays.</span>
                    </div>
                    <div class="contact-detail-item">
                        <strong>What to bring</strong>
                        <span>Valid ID, your booking confirmation, and any medical documents or referrals.</span>
                    </div>
                    <div class="contact-detail-item">
                        <strong>Need help?</strong>
                        <span>Visit the clinic reception or ask our staff during your appointment for assistance.</span>
                    </div>
                </div>
            </div>
            <div class="contact-actions">
                <a href="register_patient.php" class="secondary-btn">Sign Up</a>
                <a href="#patient-login" class="cta-btn">Log In &amp; Book</a>
            </div>
        </div>
    </section>

    <section id="patient-login" class="patient-login-section" role="dialog" aria-modal="true" aria-labelledby="patient-login-title" aria-hidden="true">
        <div class="container patient-login-grid">
            <button type="button" class="modal-close-btn" data-login-close aria-label="Close login">&times;</button>
            <div class="patient-login-copy">
                <h3 id="patient-login-title">Welcome!</h3>
                <p>
                    Enter your username and password. No need to choose a role;
                    Globalife will open the correct dashboard for your account.
                </p>
                <ul class="login-benefits">
                    <li>
                        <span class="clinic-mark" aria-hidden="true">
                            <span class="clinic-mark-plus">+</span>
                            <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                            </svg>
                        </span>
                        <span>One simple login form for every account</span>
                    </li>
                    <li>
                        <span class="clinic-mark" aria-hidden="true">
                            <span class="clinic-mark-plus">+</span>
                            <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                        </span>
                        <span>New here? <a href="register_patient.php" style="color:#0077b6;font-weight:700;">Sign Up</a> first before booking online</span>
                    </li>
                    <li>
                        <span class="clinic-mark" aria-hidden="true">
                            <span class="clinic-mark-plus">+</span>
                            <svg class="clinic-mark-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </span>
                        <span>Every account opens the right dashboard automatically</span>
                    </li>
                </ul>
            </div>

            <?php if ($isLoggedInOnHome): ?>
                <div class="patient-ready-panel">
                    <h4>You are already signed in</h4>
                    <p>
                        Continue to your dashboard, or book an appointment if your account can schedule visits.
                    </p>
                    <div class="patient-ready-actions">
                        <a href="<?php echo htmlspecialchars($currentUserDashboard); ?>" class="cta-btn">Go to Dashboard</a>
                        <?php if ($isPatientLoggedIn): ?>
                            <a href="book_appointment.php?start=1" class="secondary-btn">Book Appointment</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="patient-login-panel">
                    <h4>Log In</h4>
                    <p>Use the username and password assigned to your account.</p>

                    <div class="login-first-time">
                        <strong>New here?</strong> Create an account before booking.
                        <a href="register_patient.php">Sign Up</a> takes only a few minutes.
                    </div>

                    <?php if ($loginError): ?>
                        <div class="login-alert">
                            <?php echo htmlspecialchars($loginError); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($loginSuccess): ?>
                        <div class="login-alert success" role="status">
                            <?php echo htmlspecialchars($loginSuccess); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="index.php" class="patient-login-form">
                        <input type="hidden" name="portal_login" value="1">

                        <div class="patient-form-group">
                            <label for="patient-username">Username</label>
                            <div class="patient-input-wrap">
                                <input
                                    type="text"
                                    id="patient-username"
                                    name="username"
                                    value="<?php echo htmlspecialchars($submittedUsername); ?>"
                                    placeholder="Enter your username"
                                    autocomplete="username"
                                    required
                                >
                            </div>
                            
                        </div>

                        <div class="patient-form-group">
                            <label for="patient-password">Password</label>
                            <div class="patient-input-wrap">
                                <input
                                    type="password"
                                    id="patient-password"
                                    name="password"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required
                                >
                                <button type="button" class="password-toggle-btn" data-password-toggle aria-pressed="false">Show</button>
                            </div>
                        </div>

                        <button type="submit" class="patient-submit-btn">Log In</button>
                    </form>

                    <div class="login-panel-links">
                        <span><a href="forgot_password.php">Forgot password?</a></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
<?php include 'includes/footer.php'; ?>

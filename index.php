<?php
require_once 'includes/session.php';
require_once __DIR__ . '/config/database.php';

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
$pageTitle = "Globalife Medical Appointment System";
require_once __DIR__ . '/includes/clinic_info.php';
$publicClinicInfo = clinic_info_defaults();
if (function_exists('getDBConnection')) {
    try {
        $publicClinicInfoConn = getDBConnection();
        $publicClinicInfo = clinic_info_get($publicClinicInfoConn);
        $publicClinicInfoConn->close();
    } catch (Throwable $e) {
        $publicClinicInfo = clinic_info_defaults();
    }
}
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
    #contact {
        scroll-margin-top: 100px;
    }

    .hero .container,
    .visit-guide-section .container,
    .about-section .container,
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
        margin: 0 auto 24px;
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

    .contact-us-panel {
        margin-top: 28px;
        padding: 28px;
        background: #f8fcfd;
        border: 1px solid #d8edf1;
        border-radius: 8px;
        box-shadow: 0 12px 24px rgba(7, 59, 76, 0.06);
    }

    .contact-us-panel h4 {
        color: #073b4c;
        font-size: 1.35rem;
        margin: 0 0 10px;
    }

    .contact-us-panel p {
        color: #51636d;
        line-height: 1.7;
        margin: 0 0 16px;
    }

    .contact-us-list {
        display: grid;
        gap: 10px;
        margin: 0;
        padding: 0;
        list-style: none;
    }

    .contact-us-list li {
        color: #073b4c;
        line-height: 1.6;
    }

    .contact-us-list strong {
        color: #006d77;
        margin-right: 6px;
    }

    .contact-us-list a {
        color: #0077b6;
        font-weight: 700;
        text-decoration: underline;
        text-underline-offset: 4px;
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
        margin: 0 0 18px;
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

        document.querySelectorAll("a[href=\"#home\"], a[href=\"#visit-guide\"], a[href=\"#about\"], a[href=\"#contact\"]").forEach(function (link) {
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

        if (window.location.hash === "#home" || window.location.hash === "#visit-guide" || window.location.hash === "#about" || window.location.hash === "#contact") {
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
                <h2>Book clinic and laboratory visits with less waiting.</h2>
                <div class="hero-actions">
                    <a href="#patient-login" class="cta-btn">Book Appointment</a>
                </div>
                <div class="hero-highlights" aria-label="Clinic highlights">
                    <span>Online booking</span>
                    <span>Laboratory services</span>
                    <span>Clinic check-ups</span>
                </div>
            </div>

            <aside class="hero-panel" aria-label="Globalife care summary">
                <div class="hero-logo-shell">
                    <img src="<?php echo htmlspecialchars((string) ($publicClinicInfo['clinic_logo'] ?? 'globalife.png')); ?>" alt="Globalife clinic logo">
                </div>
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
                        <span>Laboratory and Doctor Consultation</span>
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
            <div id="contact" class="contact-us-panel">
                <h4>Contact Us</h4>
                <p>Visit or message <?php echo htmlspecialchars((string) $publicClinicInfo['clinic_name']); ?>.</p>
                <ul class="contact-us-list">
                    <li>
                        <strong>Location:</strong>
                        <a href="<?php echo htmlspecialchars((string) $publicClinicInfo['clinic_location_url']); ?>" target="_blank" rel="noopener">
                            <?php echo htmlspecialchars((string) $publicClinicInfo['clinic_location']); ?>
                        </a>
                    </li>
                    <li>
                        <strong>Facebook:</strong>
                        <span><?php echo htmlspecialchars((string) $publicClinicInfo['clinic_facebook']); ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </section>
    <section class="contact-band">
        <div class="container contact-grid">
            <div>
                <h3>Ready to schedule your visit?</h3>
                <p>
                    <strong>New here?</strong> Sign Up first, then Log In to book.
                    <strong>Already registered?</strong> Log In with your username and password to continue.
                </p>
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
                        <span>Your dashboard opens automatically after login.</span>
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

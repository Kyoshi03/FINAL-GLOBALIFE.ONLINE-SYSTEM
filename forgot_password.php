<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'includes/session.php';
require_once 'includes/password_reset.php';

$isForgotPasswordModal = (($_GET['modal'] ?? '') === '1') || (($_POST['modal'] ?? '') === '1');
$forgotPasswordAction = 'forgot_password.php' . ($isForgotPasswordModal ? '?modal=1' : '');
$step = 'request';
$error = '';
$success = '';
$recoveryMethod = in_array($_POST['recovery_method'] ?? ($_SESSION['pw_reset_channel'] ?? 'email'), ['email', 'sms'], true)
    ? (string) ($_POST['recovery_method'] ?? ($_SESSION['pw_reset_channel'] ?? 'email'))
    : 'email';
$contactValue = trim((string) ($_POST['contact'] ?? ''));

if (!empty($_SESSION['pw_reset_verified']) && !empty($_SESSION['pw_reset_user_id'])) {
    $step = 'reset';
} elseif (!empty($_SESSION['pw_reset_contact_key'])) {
    $step = 'verify';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_code') {
        $recoveryMethod = ($_POST['recovery_method'] ?? 'email') === 'sms' ? 'sms' : 'email';
        $contactValue = trim((string) ($_POST['contact'] ?? ''));
        $contactIsValid = $recoveryMethod === 'email'
            ? filter_var($contactValue, FILTER_VALIDATE_EMAIL)
            : clinic_sms_normalize_phone($contactValue) !== null;
        if ($contactValue === '' || !$contactIsValid) {
            $error = $recoveryMethod === 'sms'
                ? 'Please enter a valid registered mobile number.'
                : 'Please enter a valid email address.';
            $step = 'request';
        } else {
            $result = pw_reset_issue_code($contactValue, $recoveryMethod);
            if (!$result['ok']) {
                $error = $result['error'];
                $step = 'request';
            } else {
                $_SESSION['pw_reset_channel'] = $result['channel'];
                $_SESSION['pw_reset_contact_key'] = $result['contact_key'];
                $_SESSION['pw_reset_email'] = $result['email'];
                $_SESSION['pw_reset_phone'] = $result['phone'];
                unset($_SESSION['pw_reset_verified'], $_SESSION['pw_reset_user_id']);
                header('Location: forgot_password.php?step=verify&sent=1' . ($isForgotPasswordModal ? '&modal=1' : ''));
                exit();
            }
        }
    } elseif ($action === 'resend_code') {
        $channel = $_SESSION['pw_reset_channel'] ?? 'email';
        $contact = $channel === 'sms' ? ($_SESSION['pw_reset_phone'] ?? '') : ($_SESSION['pw_reset_email'] ?? '');
        if ($contact === '') {
            header('Location: ' . $forgotPasswordAction);
            exit();
        }
        $result = pw_reset_issue_code($contact, $channel);
        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            $success = $channel === 'sms'
                ? 'A new code was sent to ' . pw_reset_mask_phone($contact) . '.'
                : 'A new code was sent to ' . pw_reset_mask_email($contact) . '.';
        }
        $step = 'verify';
    } elseif ($action === 'verify_code') {
        $contactKey = $_SESSION['pw_reset_contact_key'] ?? '';
        $code = $_POST['code'] ?? '';
        if ($contactKey === '') {
            header('Location: ' . $forgotPasswordAction);
            exit();
        }
        $verify = pw_reset_verify_code($contactKey, $code);
        if (!$verify['ok']) {
            $error = $verify['error'];
            $step = 'verify';
        } else {
            $_SESSION['pw_reset_verified'] = true;
            $_SESSION['pw_reset_user_id'] = $verify['user_id'];
            header('Location: forgot_password.php?step=reset' . ($isForgotPasswordModal ? '&modal=1' : ''));
            exit();
        }
    } elseif ($action === 'reset_password') {
        $userId = (int) ($_SESSION['pw_reset_user_id'] ?? 0);
        $email = $_SESSION['pw_reset_email'] ?? '';
        $updatePasswordClicked = isset($_POST['update_password']) && $_POST['update_password'] === '1';
        if (!$updatePasswordClicked) {
            $error = 'Click Update password to apply the new password.';
            $step = 'reset';
        } elseif ($userId <= 0 || empty($_SESSION['pw_reset_verified'])) {
            header('Location: ' . $forgotPasswordAction);
            exit();
        } else {
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if ($password !== $confirm) {
                $error = 'Passwords do not match.';
                $step = 'reset';
            } else {
                $pwErrors = pw_reset_validate_password($password);
                if ($pwErrors) {
                    $error = implode(' ', $pwErrors);
                    $step = 'reset';
                } elseif (!pw_reset_update_password($userId, $password)) {
                    $error = 'Could not update password. Please try again.';
                    $step = 'reset';
                } else {
                    unset($_SESSION['pw_reset_channel'], $_SESSION['pw_reset_contact_key'], $_SESSION['pw_reset_email'], $_SESSION['pw_reset_phone'], $_SESSION['pw_reset_verified'], $_SESSION['pw_reset_user_id']);
                    if ($isForgotPasswordModal) {
                        echo '<script>window.top.location.replace(' . json_encode('index.php?reset=1&login=1#patient-login') . ');</script>';
                        exit();
                    }
                    header('Location: index.php?reset=1&login=1#patient-login');
                    exit();
                }
            }
        }
    }
}

if (isset($_GET['step'])) {
    $allowed = ['request', 'verify', 'reset'];
    $g = $_GET['step'];
    if (in_array($g, $allowed, true)) {
        if ($g === 'verify' && empty($_SESSION['pw_reset_contact_key'])) {
            $step = 'request';
        } elseif ($g === 'reset' && (empty($_SESSION['pw_reset_verified']) || empty($_SESSION['pw_reset_user_id']))) {
            $step = empty($_SESSION['pw_reset_contact_key']) ? 'request' : 'verify';
        } else {
            $step = $g;
        }
    }
}

if (isset($_GET['sent']) && $step === 'verify' && $success === '') {
    $channel = $_SESSION['pw_reset_channel'] ?? 'email';
    $contact = $channel === 'sms' ? ($_SESSION['pw_reset_phone'] ?? '') : ($_SESSION['pw_reset_email'] ?? '');
    if ($contact !== '') {
        $maskedContact = $channel === 'sms' ? pw_reset_mask_phone($contact) : pw_reset_mask_email($contact);
        $deliveryName = $channel === 'sms' ? 'phone' : 'inbox and spam folder';
        $success = 'We sent a 6-digit code to ' . $maskedContact . '. Check your ' . $deliveryName . '.';
    }
}

$resetChannel = $_SESSION['pw_reset_channel'] ?? 'email';
$resetContact = $resetChannel === 'sms' ? ($_SESSION['pw_reset_phone'] ?? '') : ($_SESSION['pw_reset_email'] ?? '');
$maskedContact = $resetContact !== ''
    ? ($resetChannel === 'sms' ? pw_reset_mask_phone($resetContact) : pw_reset_mask_email($resetContact))
    : '';
$stepNumber = ['request' => 1, 'verify' => 2, 'reset' => 3][$step] ?? 1;
$previousStep = $step === 'verify' ? 'request' : ($step === 'reset' ? 'verify' : '');
$backHref = $step === 'request'
    ? 'index.php?login=1#patient-login'
    : $forgotPasswordAction . '&step=' . $previousStep;
$backAttributes = $step === 'request' ? 'target="_top" data-forgot-back-login' : '';

$pageTitle = 'Forgot Password | Globalife Medical Laboratory & Polyclinic';
$headerBrandOnly = true;
$headerNavigationTarget = '_top';
$additionalStyles = '
    body {
        background: linear-gradient(135deg, #90e0ef 0%, #48cae4 100%);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    .login-wrapper {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 20px;
    }
    .login-container {
        max-width: 480px;
        width: 100%;
        padding: 45px 40px;
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        border-top: 4px solid #48cae4;
    }
    .login-container h2 {
        color: #48cae4;
        margin: 0 0 8px;
        font-size: 1.75rem;
        text-align: center;
    }
    .login-subtitle {
        color: #666;
        font-size: 0.95rem;
        text-align: center;
        margin: 0 0 28px;
        line-height: 1.5;
    }
    .step-pills {
        display: flex;
        gap: 8px;
        justify-content: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .step-pill {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 6px 12px;
        border-radius: 20px;
        background: #e8f8fc;
        color: #5c7a85;
    }
    .step-pill.active {
        background: linear-gradient(135deg, #48cae4, #0077b6);
        color: #fff;
    }
    .form-group { margin-bottom: 22px; }
    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 0.9rem;
    }
    .form-group input {
        width: 100%;
        padding: 14px 16px;
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        font-size: 1rem;
        box-sizing: border-box;
        background: #f8f9fa;
    }
    .form-group input:focus {
        outline: none;
        border-color: #48cae4;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(72, 202, 228, 0.15);
    }
    .recovery-method {
        width: 100%;
        padding: 14px 16px;
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        font-size: 1rem;
        box-sizing: border-box;
        background: #f8f9fa;
        color: #333;
    }
    .recovery-method:focus {
        outline: none;
        border-color: #48cae4;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(72, 202, 228, 0.15);
    }
    .code-input {
        text-align: center;
        letter-spacing: 0.35em;
        font-size: 1.35rem;
        font-weight: 700;
    }
    .login-btn-form {
        width: 100%;
        padding: 15px;
        border: none;
        border-radius: 12px;
        background: linear-gradient(135deg, #48cae4 0%, #0077b6 100%);
        color: #fff;
        font-size: 1.05rem;
        font-weight: 700;
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .login-btn-form:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 119, 182, 0.35);
    }
    .error-message {
        background: linear-gradient(135deg, #ff6b6b, #ee5a6f);
        color: #fff;
        padding: 10px 12px;
        border-radius: 12px;
        margin-bottom: 12px;
        font-size: 0.92rem;
        line-height: 1.3;
    }
    .success-message {
        background: linear-gradient(135deg, #51cf66, #37b24d);
        color: #fff;
        padding: 10px 12px;
        border-radius: 12px;
        margin-bottom: 12px;
        font-size: 0.92rem;
        line-height: 1.3;
    }
    .form-footer {
        text-align: center;
        margin-top: 22px;
        font-size: 0.9rem;
        color: #666;
    }
    .form-footer a {
        color: #48cae4;
        font-weight: 600;
        text-decoration: none;
    }
    .form-footer a:hover { text-decoration: underline; }
    .inline-link-btn {
        background: none;
        border: none;
        color: #0077b6;
        font-weight: 600;
        cursor: pointer;
        padding: 0;
        font-size: inherit;
        text-decoration: underline;
    }
    .forgot-content {
        width: min(100%, 560px);
        margin: 0 auto;
        text-align: center;
    }
    .forgot-logo {
        width: 54px;
        height: 54px;
        margin: 0 auto 8px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #48cae4;
        box-shadow: 0 8px 20px rgba(0, 119, 182, 0.15);
    }
    .forgot-content h2 {
        color: #073b4c;
        margin: 0 0 4px;
        font-size: 1.45rem;
        text-align: center;
    }
    .forgot-content .login-subtitle {
        margin-bottom: 14px;
    }
    .forgot-form {
        text-align: left;
    }
    .forgot-form .login-btn-form {
        margin-top: 0;
        padding-top: 10px;
        padding-bottom: 10px;
    }
    .forgot-content .form-group {
        margin-bottom: 12px;
    }
    .forgot-content .form-group label {
        margin-bottom: 5px;
    }
    .forgot-content .form-group input {
        padding-top: 11px;
        padding-bottom: 11px;
    }
    .forgot-password-input {
        position: relative;
    }
    .forgot-password-input input {
        padding-right: 48px !important;
    }
    .forgot-password-toggle {
        position: absolute;
        top: 50%;
        right: 8px;
        display: grid;
        width: 34px;
        height: 34px;
        place-items: center;
        padding: 6px;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: #0077b6;
        cursor: pointer;
        transform: translateY(-50%);
    }
    .forgot-password-toggle:hover,
    .forgot-password-toggle:focus-visible {
        background: #e8f7fb;
        outline: none;
    }
    .forgot-password-toggle svg {
        width: 21px;
        height: 21px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 2;
    }
    .forgot-content .form-footer {
        margin-top: 12px;
    }
    .forgot-step-nav {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin: 10px 0 8px;
        font-size: 0.9rem;
    }
    .forgot-step-nav a {
        color: #0077b6;
        text-decoration: underline;
    }
    .forgot-step-nav a:hover {
        color: #023e8a;
    }
    .forgot-step-nav span {
        color: #5c6f7a;
        white-space: nowrap;
    }
';

if ($isForgotPasswordModal) {
    $additionalStyles .= '
        html,
        body {
            min-height: 100%;
            background: #ffffff;
        }

        body {
            display: block;
            overflow-x: hidden;
        }

        #mainHeader,
        footer {
            display: none !important;
        }

        .login-wrapper {
            display: block;
            min-height: 100%;
            padding: 0;
        }

        .login-container {
            width: 100%;
            max-width: none;
            min-height: 100%;
            margin: 0;
            padding: 20px clamp(20px, 4vw, 48px) 24px;
            border: 0;
            border-radius: 0;
            box-shadow: none;
            box-sizing: border-box;
        }

        @media (max-width: 560px) {
            .login-container {
                padding: 24px 18px 32px;
            }
        }
    ';
}

include 'includes/header.php';
?>
    <div class="login-wrapper">
        <div class="login-container">
            <div class="forgot-content">
                <img src="globalife.png" alt="Globalife Medical Laboratory &amp; Polyclinic" class="forgot-logo">
                <h2>Reset your password</h2>
                <p class="login-subtitle">Choose your registered email or mobile number to receive a secure verification code.</p>

                <?php if ($error): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if ($step === 'request'): ?>
                    <form method="POST" action="<?php echo htmlspecialchars($forgotPasswordAction); ?>" class="forgot-form">
                        <input type="hidden" name="action" value="request_code">
                        <div class="form-group">
                            <label for="recovery_method">Send verification code via</label>
                            <select id="recovery_method" name="recovery_method" class="recovery-method" autocomplete="off">
                                <option value="email"<?php echo $recoveryMethod === 'email' ? ' selected' : ''; ?>>Registered email</option>
                                <option value="sms"<?php echo $recoveryMethod === 'sms' ? ' selected' : ''; ?>>Registered mobile number</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="recovery_contact" id="recoveryContactLabel"><?php echo $recoveryMethod === 'sms' ? 'Registered mobile number' : 'Registered email address'; ?></label>
                            <input
                                type="<?php echo $recoveryMethod === 'sms' ? 'tel' : 'email'; ?>"
                                id="recovery_contact"
                                name="contact"
                                value="<?php echo htmlspecialchars($contactValue); ?>"
                                placeholder="<?php echo $recoveryMethod === 'sms' ? '09171234567' : 'you@example.com'; ?>"
                                autocomplete="<?php echo $recoveryMethod === 'sms' ? 'tel' : 'email'; ?>"
                                required
                                autofocus
                            >
                        </div>
                        <div class="forgot-step-nav">
                            <a href="<?php echo htmlspecialchars($backHref); ?>" <?php echo $backAttributes; ?>>← Back</a>
                            <span>Step <?php echo $stepNumber; ?> of 3</span>
                        </div>
                        <button type="submit" class="login-btn-form">Continue</button>
                    </form>

                <?php elseif ($step === 'verify'): ?>
                    <form method="POST" action="<?php echo htmlspecialchars($forgotPasswordAction); ?>" class="forgot-form">
                        <input type="hidden" name="action" value="verify_code">
                        <div class="form-group">
                            <label for="code">6-digit code</label>
                            <input
                                type="text"
                                id="code"
                                name="code"
                                class="code-input"
                                inputmode="numeric"
                                pattern="[0-9]{6}"
                                maxlength="6"
                                placeholder="000000"
                                autocomplete="one-time-code"
                                required
                                autofocus
                            >
                        </div>
                        <p class="login-subtitle" style="margin-top: -8px; margin-bottom: 10px;">
                            Code sent to <strong><?php echo htmlspecialchars($maskedContact); ?></strong>
                        </p>
                        <div class="forgot-step-nav">
                            <a href="<?php echo htmlspecialchars($backHref); ?>" <?php echo $backAttributes; ?>>← Back</a>
                            <span>Step <?php echo $stepNumber; ?> of 3</span>
                        </div>
                        <button type="submit" class="login-btn-form">Verify code</button>
                    </form>
                    <form method="POST" action="<?php echo htmlspecialchars($forgotPasswordAction); ?>" style="text-align: center; margin-top: 10px;">
                        <input type="hidden" name="action" value="resend_code">
                        <button type="submit" class="inline-link-btn">Resend code</button>
                    </form>

                <?php else: ?>
                    <form method="POST" action="<?php echo htmlspecialchars($forgotPasswordAction); ?>" class="forgot-form">
                        <input type="hidden" name="action" value="reset_password">
                        <div class="form-group">
                            <label for="password">New password</label>
                            <div class="forgot-password-input">
                                <input type="password" id="password" name="password" autocomplete="new-password" required>
                                <button type="button" class="forgot-password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false" title="Show password">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm new password</label>
                            <div class="forgot-password-input">
                                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                                <button type="button" class="forgot-password-toggle" data-password-toggle="confirm_password" aria-label="Show password" aria-pressed="false" title="Show password">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                        <circle cx="12" cy="12" r="3"></circle>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <p class="login-subtitle" style="font-size: 0.82rem; margin-top: -8px;">
                            At least 8 characters with upper, lower, number, and special character.
                        </p>
                        <div class="forgot-step-nav">
                            <a href="<?php echo htmlspecialchars($backHref); ?>" <?php echo $backAttributes; ?>>← Back</a>
                            <span>Step <?php echo $stepNumber; ?> of 3</span>
                        </div>
                        <button type="submit" name="update_password" value="1" class="login-btn-form">Update password</button>
                    </form>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <script>
        (function () {
            var eyeOpenIcon = '<path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path><circle cx="12" cy="12" r="3"></circle>';
            var recoveryMethod = document.getElementById('recovery_method');
            var recoveryContact = document.getElementById('recovery_contact');
            var recoveryContactLabel = document.getElementById('recoveryContactLabel');
            if (recoveryMethod && recoveryContact && recoveryContactLabel) {
                recoveryMethod.addEventListener('change', function () {
                    var isSms = recoveryMethod.value === 'sms';
                    recoveryContact.value = '';
                    recoveryContact.type = isSms ? 'tel' : 'email';
                    recoveryContact.placeholder = isSms ? '09171234567' : 'you@example.com';
                    recoveryContact.autocomplete = isSms ? 'tel' : 'email';
                    recoveryContactLabel.textContent = isSms ? 'Registered mobile number' : 'Registered email address';
                    recoveryContact.focus();
                });
            }

            var eyeClosedIcon = '<path d="M3 3l18 18"></path><path d="M10.58 10.58a2 2 0 002.83 2.83"></path><path d="M9.88 5.09A10.7 10.7 0 0112 5c4.48 0 8.27 2.94 9.54 7a10.7 10.7 0 01-3.04 4.27"></path><path d="M6.61 6.61A10.7 10.7 0 002.46 12c1.27 4.06 5.06 7 9.54 7 1.01 0 1.98-.16 2.88-.45"></path>';
            document.querySelectorAll('[data-password-toggle]').forEach(function (toggle) {
                var input = document.getElementById(toggle.getAttribute('data-password-toggle'));
                var icon = toggle.querySelector('svg');
                if (!input || !icon) return;
                toggle.addEventListener('click', function () {
                    var shouldShow = input.type === 'password';
                    input.type = shouldShow ? 'text' : 'password';
                    toggle.setAttribute('aria-label', shouldShow ? 'Hide password' : 'Show password');
                    toggle.setAttribute('title', shouldShow ? 'Hide password' : 'Show password');
                    toggle.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
                    icon.innerHTML = shouldShow ? eyeClosedIcon : eyeOpenIcon;
                });
            });
        })();
        document.querySelectorAll('[data-forgot-back-login]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var topWindow = window.top;
                var forgotModal = topWindow.document.getElementById('forgot-password-modal');
                var loginModal = topWindow.document.getElementById('patient-login');

                if (forgotModal && loginModal) {
                    forgotModal.classList.remove('is-open');
                    forgotModal.setAttribute('aria-hidden', 'true');
                    loginModal.classList.add('is-open');
                    loginModal.setAttribute('aria-hidden', 'false');
                    topWindow.document.body.style.overflow = 'hidden';
                    topWindow.history.replaceState(null, '', 'index.php?login=1#patient-login');

                    var usernameInput = topWindow.document.getElementById('patient-username');
                    if (usernameInput) {
                        topWindow.setTimeout(function () {
                            usernameInput.focus();
                        }, 80);
                    }
                    return;
                }

                topWindow.location.assign('index.php?login=1#patient-login');
            });
        });
    </script>
<?php include 'includes/footer.php'; ?>

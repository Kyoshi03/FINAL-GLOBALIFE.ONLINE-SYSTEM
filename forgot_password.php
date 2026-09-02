<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'includes/session.php';
require_once 'includes/password_reset.php';

$step = 'request';
$error = '';
$success = '';
$emailValue = '';

if (!empty($_SESSION['pw_reset_verified']) && !empty($_SESSION['pw_reset_user_id'])) {
    $step = 'reset';
} elseif (!empty($_SESSION['pw_reset_email'])) {
    $step = 'verify';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_code') {
        $emailValue = trim($_POST['email'] ?? '');
        if ($emailValue === '' || !filter_var($emailValue, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
            $step = 'request';
        } else {
            $result = pw_reset_issue_code($emailValue);
            if (!$result['ok']) {
                $error = $result['error'];
                $step = 'request';
            } else {
                $_SESSION['pw_reset_email'] = $result['email'];
                unset($_SESSION['pw_reset_verified'], $_SESSION['pw_reset_user_id']);
                header('Location: forgot_password.php?step=verify&sent=1');
                exit();
            }
        }
    } elseif ($action === 'resend_code') {
        $email = $_SESSION['pw_reset_email'] ?? '';
        if ($email === '') {
            header('Location: forgot_password.php');
            exit();
        }
        $result = pw_reset_issue_code($email);
        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            $success = 'A new code was sent to ' . pw_reset_mask_email($email) . '.';
        }
        $step = 'verify';
    } elseif ($action === 'verify_code') {
        $email = $_SESSION['pw_reset_email'] ?? '';
        $code = $_POST['code'] ?? '';
        if ($email === '') {
            header('Location: forgot_password.php');
            exit();
        }
        $verify = pw_reset_verify_code($email, $code);
        if (!$verify['ok']) {
            $error = $verify['error'];
            $step = 'verify';
        } else {
            $_SESSION['pw_reset_verified'] = true;
            $_SESSION['pw_reset_user_id'] = $verify['user_id'];
            header('Location: forgot_password.php?step=reset');
            exit();
        }
    } elseif ($action === 'reset_password') {
        $userId = (int) ($_SESSION['pw_reset_user_id'] ?? 0);
        $email = $_SESSION['pw_reset_email'] ?? '';
        if ($userId <= 0 || empty($_SESSION['pw_reset_verified'])) {
            header('Location: forgot_password.php');
            exit();
        }
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
                unset($_SESSION['pw_reset_email'], $_SESSION['pw_reset_verified'], $_SESSION['pw_reset_user_id']);
                header('Location: index.php?reset=1#patient-login');
                exit();
            }
        }
    }
}

if (isset($_GET['step'])) {
    $allowed = ['request', 'verify', 'reset'];
    $g = $_GET['step'];
    if (in_array($g, $allowed, true)) {
        if ($g === 'verify' && empty($_SESSION['pw_reset_email'])) {
            $step = 'request';
        } elseif ($g === 'reset' && (empty($_SESSION['pw_reset_verified']) || empty($_SESSION['pw_reset_user_id']))) {
            $step = empty($_SESSION['pw_reset_email']) ? 'request' : 'verify';
        } else {
            $step = $g;
        }
    }
}

if (isset($_GET['sent']) && $step === 'verify' && $success === '') {
    $email = $_SESSION['pw_reset_email'] ?? '';
    if ($email !== '') {
        $success = 'We sent a 6-digit code to ' . pw_reset_mask_email($email) . '. Check your inbox and spam folder.';
    }
}

$maskedEmail = isset($_SESSION['pw_reset_email']) ? pw_reset_mask_email($_SESSION['pw_reset_email']) : '';

$pageTitle = 'Forgot Password | Globalife Medical Laboratory & Polyclinic';
$headerBrandOnly = true;
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
        padding: 14px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 0.92rem;
    }
    .success-message {
        background: linear-gradient(135deg, #51cf66, #37b24d);
        color: #fff;
        padding: 14px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
        font-size: 0.92rem;
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
';

include 'includes/header.php';
?>
    <div class="login-wrapper">
        <div class="login-container">
            <h2>Forgot Password</h2>
            <p class="login-subtitle">Reset your password using a secure code sent to your registered email.</p>

            <div class="step-pills">
                <span class="step-pill<?php echo $step === 'request' ? ' active' : ''; ?>">1. Email</span>
                <span class="step-pill<?php echo $step === 'verify' ? ' active' : ''; ?>">2. Code</span>
                <span class="step-pill<?php echo $step === 'reset' ? ' active' : ''; ?>">3. New password</span>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if ($step === 'request'): ?>
                <form method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="request_code">
                    <div class="form-group">
                        <label for="email">Registered email address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?php echo htmlspecialchars($emailValue); ?>"
                            placeholder="you@example.com"
                            autocomplete="email"
                            required
                            autofocus
                        >
                    </div>
                    <button type="submit" class="login-btn-form">Send verification code</button>
                </form>

            <?php elseif ($step === 'verify'): ?>
                <form method="POST" action="forgot_password.php">
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
                    <p class="login-subtitle" style="margin-top: -8px; margin-bottom: 20px;">
                        Code sent to <strong><?php echo htmlspecialchars($maskedEmail); ?></strong>
                    </p>
                    <button type="submit" class="login-btn-form">Verify code</button>
                </form>
                <form method="POST" action="forgot_password.php" style="text-align: center; margin-top: 16px;">
                    <input type="hidden" name="action" value="resend_code">
                    <button type="submit" class="inline-link-btn">Resend code</button>
                </form>

            <?php else: ?>
                <form method="POST" action="forgot_password.php">
                    <input type="hidden" name="action" value="reset_password">
                    <div class="form-group">
                        <label for="password">New password</label>
                        <input type="password" id="password" name="password" autocomplete="new-password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm new password</label>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                    </div>
                    <p class="login-subtitle" style="font-size: 0.82rem; margin-top: -8px;">
                        At least 8 characters with upper, lower, number, and special character.
                    </p>
                    <button type="submit" class="login-btn-form">Update password</button>
                </form>
            <?php endif; ?>

            <div class="form-footer">
                <a href="index.php#patient-login">← Back to sign in</a>
                &nbsp;·&nbsp;
                <a href="index.php#patient-login">Home login</a>
            </div>
        </div>
    </div>
<?php include 'includes/footer.php'; ?>

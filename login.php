<?php
require_once 'includes/session.php';

if (isLoggedIn()) {
    redirectToDashboardForCurrentUser();
}

$error = '';
$blockedLoginMessage = null;
$submittedUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedUsername = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($submittedUsername === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } elseif (login($submittedUsername, $password)) {
        $user = getCurrentUser();
        header('Location: ' . dashboardForRole($user['role']));
        exit();
    } else {
        $blockedLoginMessage = consumeLoginBlockedMessage();
        $error = $blockedLoginMessage === null
            ? 'Invalid username or password. Please check your details and try again.'
            : '';
    }
}

$pageTitle = "Login | Globalife Medical Laboratory & Polyclinic";
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
    <style>
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(135deg, #eaf9fd 0%, #f7fbf6 55%, #fff4ed 100%);
        }

        .login-page {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 42px 20px;
        }

        .login-card {
            width: min(100%, 460px);
            box-sizing: border-box;
            background: #fff;
            border: 1px solid #dceef2;
            border-radius: 8px;
            box-shadow: 0 24px 60px rgba(7, 59, 76, 0.16);
            padding: 34px;
        }

        .login-brand {
            text-align: center;
            margin-bottom: 24px;
        }

        .login-brand img {
            width: 82px;
            height: 82px;
            border-radius: 50%;
            object-fit: contain;
            border: 3px solid #caf0f8;
            background: #fff;
            margin-bottom: 14px;
        }

        .login-brand h2 {
            color: #073b4c;
            font-size: 1.8rem;
            margin: 0 0 8px;
        }

        .login-brand p {
            color: #51636d;
            line-height: 1.55;
            margin: 0;
        }

        .login-note {
            background: #eef9fc;
            border: 1px solid #b8e9f4;
            border-radius: 8px;
            color: #334b57;
            font-size: 0.92rem;
            line-height: 1.55;
            padding: 12px 14px;
            margin-bottom: 18px;
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

        .disabled-login-modal {
            position: fixed;
            inset: 0;
            z-index: 2600;
            display: grid;
            place-items: center;
            padding: 20px;
            background: rgba(7, 59, 76, 0.68);
        }

        .disabled-login-dialog {
            width: min(100%, 480px);
            box-sizing: border-box;
            overflow: hidden;
            border: 1px solid #d8edf1;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(7, 59, 76, 0.28);
        }

        .disabled-login-head {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 22px 24px;
            background: #eef9fc;
            border-bottom: 1px solid #d8edf1;
        }

        .disabled-login-icon {
            display: inline-grid;
            flex: 0 0 42px;
            place-items: center;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #fff0f0;
            color: #d90429;
            font-size: 1.45rem;
            font-weight: 800;
        }

        .disabled-login-head h2 {
            margin: 0;
            color: #073b4c;
            font-size: 1.35rem;
            line-height: 1.25;
        }

        .disabled-login-body {
            padding: 24px;
        }

        .disabled-login-body p {
            margin: 0;
            color: #51636d;
            font-size: 1rem;
            line-height: 1.6;
        }

        .disabled-login-message {
            padding: 14px 16px;
            border: 1px solid #ffd2d2;
            border-left: 4px solid #d90429;
            border-radius: 8px;
            background: #fff7f7;
            color: #8f1d2c !important;
            font-weight: 700;
        }

        .disabled-login-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 22px;
        }

        .disabled-login-close {
            min-height: 44px;
            padding: 10px 22px;
            border: 0;
            border-radius: 8px;
            background: #0077b6;
            color: #fff;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
        }

        .disabled-login-close:hover,
        .disabled-login-close:focus-visible {
            background: #005f91;
        }

        @media (max-width: 560px) {
            .disabled-login-modal {
                padding: 14px;
            }

            .disabled-login-head,
            .disabled-login-body {
                padding: 18px;
            }

            .disabled-login-head h2 {
                font-size: 1.15rem;
            }

            .disabled-login-actions,
            .disabled-login-close {
                width: 100%;
            }
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            color: #213943;
            font-size: 0.92rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap input {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid #cfe4e9;
            border-radius: 8px;
            color: #1f343d;
            font-size: 1rem;
            min-height: 48px;
            padding: 12px 14px;
        }

        .input-wrap input:focus {
            border-color: #0077b6;
            box-shadow: 0 0 0 4px rgba(0, 119, 182, 0.1);
            outline: none;
        }

        .password-wrap input {
            padding-right: 74px;
        }

        .password-toggle {
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

        .field-hint {
            display: block;
            color: #667780;
            font-size: 0.82rem;
            line-height: 1.4;
            margin-top: 6px;
        }

        .login-submit {
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
        }

        .login-submit:hover {
            background: #023e8a;
        }

        .login-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px 16px;
            color: #5d6d76;
            font-size: 0.92rem;
            margin-top: 18px;
        }

        .login-links a {
            color: #0077b6;
            font-weight: 700;
            text-decoration: none;
        }

        .login-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <main class="login-page">
        <section class="login-card" aria-labelledby="login-title">
            <div class="login-brand">
                <img src="globalife.png" alt="Globalife clinic logo">
                <h2 id="login-title">Welcome back</h2>
                <p>Use one login form for patients, admin, and doctors.</p>
            </div>

            <div class="login-note">
                Enter your username and password. We will automatically open the right dashboard for your account.
            </div>

            <?php if ($error): ?>
                <div class="login-alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <input
                            type="text"
                            id="username"
                            name="username"
                            value="<?php echo htmlspecialchars($submittedUsername); ?>"
                            placeholder="Enter your username"
                            autocomplete="username"
                            required
                        >
                    </div>
                    <span class="field-hint">Use the username given to you or the one you created when signing up.</span>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap password-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button type="button" class="password-toggle" id="passwordToggle" aria-pressed="false">Show</button>
                    </div>
                    <span class="field-hint">Forgot it? Use the reset link below.</span>
                </div>

                <button type="submit" class="login-submit">Log In</button>
            </form>

            <div class="login-links">
                <a href="register_patient.php">Sign Up as patient</a>
                <a href="forgot_password.php">Forgot password?</a>
                <a href="index.php">Back to Home</a>
            </div>
        </section>
    </main>

    <?php if ($blockedLoginMessage !== null): ?>
        <section class="disabled-login-modal" role="alertdialog" aria-modal="true" aria-labelledby="disabled-login-title" aria-describedby="disabled-login-message">
            <div class="disabled-login-dialog">
                <div class="disabled-login-head">
                    <span class="disabled-login-icon" aria-hidden="true">!</span>
                    <h2 id="disabled-login-title">Account access unavailable</h2>
                </div>
                <div class="disabled-login-body">
                    <p class="disabled-login-message" id="disabled-login-message">
                        <?php echo htmlspecialchars($blockedLoginMessage); ?>
                    </p>
                    <div class="disabled-login-actions">
                        <button type="button" class="disabled-login-close">Close</button>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');

        passwordToggle.addEventListener('click', function () {
            const shouldShow = passwordInput.type === 'password';
            passwordInput.type = shouldShow ? 'text' : 'password';
            passwordToggle.textContent = shouldShow ? 'Hide' : 'Show';
            passwordToggle.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
        });

        const disabledLoginModal = document.querySelector('.disabled-login-modal');
        const disabledLoginClose = document.querySelector('.disabled-login-close');

        function closeDisabledLoginModal() {
            if (disabledLoginModal) {
                disabledLoginModal.remove();
            }
        }

        if (disabledLoginModal) {
            disabledLoginModal.addEventListener('click', function (event) {
                if (event.target === disabledLoginModal) {
                    closeDisabledLoginModal();
                }
            });
        }

        if (disabledLoginClose) {
            disabledLoginClose.addEventListener('click', closeDisabledLoginModal);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && disabledLoginModal) {
                closeDisabledLoginModal();
            }
        });
    </script>
</body>
</html>

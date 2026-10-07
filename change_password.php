<?php
require_once 'includes/session.php';
checkRole('doctor');

require_once 'config/database.php';
require_once 'includes/password_reset.php';
require_once 'includes/clinic_info.php';

$conn = getDBConnection();
$currentUser = getCurrentUser();
$clinicInfo = clinic_info_get($conn);
$pageTitle = 'Change Password | ' . $clinicInfo['clinic_name'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? AND role = \'doctor\' LIMIT 1');
    $stmt->bind_param('i', $currentUser['id']);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Please complete all password fields.';
    } elseif (empty($account['password']) || !password_verify($currentPassword, (string) $account['password'])) {
        $error = 'Current password is incorrect.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $passwordErrors = pw_reset_validate_password($newPassword);
        if ($passwordErrors !== []) {
            $error = implode(' ', $passwordErrors);
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ? AND role = \'doctor\'');
            $stmt->bind_param('si', $hash, $currentUser['id']);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success = 'Your password was changed successfully.';
            } else {
                $error = 'Could not change your password. Please try again.';
            }
            $stmt->close();
        }
    }
}

$conn->close();
$additionalStyles = '
    body { background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%); min-height: 100vh; }
    .password-page { width:min(680px, calc(100% - 32px)); margin:42px auto; }
    .password-card { background:#fff; border:1px solid #d8eaf3; border-radius:18px; padding:30px; box-shadow:0 16px 38px rgba(20,79,123,.1); }
    .password-card h1 { margin:0 0 8px; color:#073b4c; font-size:1.9rem; }
    .password-card > p { margin:0 0 26px; color:#5e7380; line-height:1.55; }
    .password-field { margin-bottom:18px; }
    .password-field label { display:block; margin-bottom:7px; color:#31596d; font-weight:800; }
    .password-input-wrap { position:relative; }
    .password-field input { width:100%; box-sizing:border-box; padding:13px 52px 13px 14px; border:1px solid #c9e2ef; border-radius:10px; color:#173e52; font-size:1rem; outline:none; }
    .password-field input:focus { border-color:#0878b5; box-shadow:0 0 0 3px rgba(8,120,181,.12); }
    .password-toggle { position:absolute; top:50%; right:12px; display:grid; place-items:center; width:34px; height:34px; padding:0; transform:translateY(-50%); border:0; border-radius:8px; background:transparent; color:#5d7b8c; cursor:pointer; }
    .password-toggle:hover, .password-toggle:focus-visible { background:#eef8fd; color:#0878b5; outline:none; }
    .password-toggle svg { width:20px; height:20px; fill:none; stroke:currentColor; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
    .password-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top:24px; }
    .password-primary, .password-secondary { display:inline-flex; align-items:center; justify-content:center; min-height:44px; padding:0 18px; border-radius:10px; font-weight:800; text-decoration:none; cursor:pointer; }
    .password-primary { border:0; background:#0878b5; color:#fff; }
    .password-secondary { border:1px solid #c9e2ef; background:#f5fbff; color:#08618f; }
    .password-alert { margin:0 0 18px; padding:13px 15px; border-radius:10px; line-height:1.45; }
    .password-alert.error { background:#fff0f0; border:1px solid #f2c2c2; color:#a62a2a; }
    .password-alert.success { background:#edfaf1; border:1px solid #bfe6ca; color:#17643a; }
    .password-success-modal { position:fixed; inset:0; z-index:6500; display:grid; place-items:center; padding:20px; background:rgba(3,37,56,.45); backdrop-filter:blur(7px); -webkit-backdrop-filter:blur(7px); }
    .password-success-dialog { width:min(420px,100%); padding:30px 26px 26px; border:1px solid #c9e2ef; border-radius:20px; background:#fff; box-shadow:0 28px 80px rgba(4,35,52,.3); text-align:center; }
    .password-success-icon { width:62px; height:62px; margin:0 auto 14px; display:grid; place-items:center; border-radius:50%; background:#e5f8ed; color:#16804a; font-size:2rem; font-weight:900; }
    .password-success-dialog h2 { margin:0 0 8px; color:#073b4c; font-size:1.45rem; }
    .password-success-dialog p { margin:0; color:#5e7380; line-height:1.5; }
    .password-success-close { margin-top:22px; min-width:120px; min-height:42px; padding:0 18px; border:0; border-radius:10px; background:#0878b5; color:#fff; font-weight:800; cursor:pointer; }
';

include 'includes/header.php';
?>
<main class="password-page">
    <section class="password-card" aria-labelledby="changePasswordTitle">
        <h1 id="changePasswordTitle">Change Password</h1>
        <p>Update your account password using your current password.</p>
        <?php if ($error !== ''): ?>
            <div class="password-alert error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($success !== ''): ?>
            <div class="password-alert success" role="status"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <form method="post" action="change_password.php">
            <div class="password-field">
                <label for="current_password">Current password</label>
                <div class="password-input-wrap">
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" data-password-toggle="current_password" aria-label="Show current password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <div class="password-field">
                <label for="new_password">New password</label>
                <div class="password-input-wrap">
                    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                    <button type="button" class="password-toggle" data-password-toggle="new_password" aria-label="Show new password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <div class="password-field">
                <label for="confirm_password">Confirm new password</label>
                <div class="password-input-wrap">
                    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                    <button type="button" class="password-toggle" data-password-toggle="confirm_password" aria-label="Show confirm password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <div class="password-actions">
                <button type="submit" class="password-primary">Save Password</button>
                <a href="doctor.php" class="password-secondary">Back to Dashboard</a>
            </div>
        </form>
    </section>
</main>
<?php if ($success !== ''): ?>
<div class="password-success-modal" id="passwordSuccessModal" role="dialog" aria-modal="true" aria-labelledby="passwordSuccessTitle">
    <section class="password-success-dialog">
        <div class="password-success-icon" aria-hidden="true">✓</div>
        <h2 id="passwordSuccessTitle">Password changed successfully</h2>
        <p>Your new password is now active.</p>
        <button type="button" class="password-success-close" id="passwordSuccessClose">OK</button>
    </section>
</div>
<script>
(function () {
    var modal = document.getElementById('passwordSuccessModal');
    var close = document.getElementById('passwordSuccessClose');
    if (!modal || !close) return;
    document.body.style.overflow = 'hidden';
    close.addEventListener('click', function () {
        modal.remove();
        document.body.style.overflow = '';
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));
            if (!input) return;
            var showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.setAttribute('aria-pressed', showing ? 'false' : 'true');
            button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            button.innerHTML = showing
                ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
                : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.8 10.8 0 0 1 12 6c6.5 0 10 6 10 6a18.5 18.5 0 0 1-3.1 3.7M6.2 6.8C3.5 8.5 2 12 2 12s3.5 6 10 6c1.2 0 2.3-.2 3.3-.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
        });
    });
})();
</script>
<?php include 'includes/footer.php'; ?>

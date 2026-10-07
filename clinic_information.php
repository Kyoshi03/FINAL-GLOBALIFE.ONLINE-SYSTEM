<?php
require_once 'includes/session.php';
checkRole('admin');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/clinic_info.php';

if (empty($_SESSION['admin_settings_csrf'])) {
    $_SESSION['admin_settings_csrf'] = bin2hex(random_bytes(24));
}

$clinicInfoNotice = (array) ($_SESSION['admin_clinic_info_notice'] ?? []);
unset($_SESSION['admin_clinic_info_notice']);
$clinicInfoNoticeType = in_array((string) ($clinicInfoNotice['type'] ?? ''), ['success', 'info', 'error'], true)
    ? (string) $clinicInfoNotice['type']
    : 'info';
$clinicInfoNoticeTitle = $clinicInfoNoticeType === 'success'
    ? 'Successfully saved'
    : ($clinicInfoNoticeType === 'error' ? 'Unable to save' : 'No changes made');

$clinicInfo = clinic_info_defaults();
try {
    $clinicInfoConn = getDBConnection();
    $clinicInfo = clinic_info_get($clinicInfoConn);
    $clinicInfoConn->close();
} catch (Throwable $e) {
    $clinicInfo = clinic_info_defaults();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_clinic_info') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $sessionToken = (string) ($_SESSION['admin_settings_csrf'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $_SESSION['admin_clinic_info_notice'] = [
            'type' => 'error',
            'message' => 'The request expired. Refresh the page and try again.',
        ];
        header('Location: clinic_information.php');
        exit();
    }

    $clinicDefaults = clinic_info_defaults();
    $submittedClinicValues = [
        'clinic_name' => trim((string) ($_POST['clinic_name'] ?? '')),
        'clinic_location' => trim((string) ($_POST['clinic_location'] ?? '')),
        'clinic_facebook' => trim((string) ($_POST['clinic_facebook'] ?? '')),
    ];
    foreach ($submittedClinicValues as $key => $value) {
        if ($value === '') {
            $submittedClinicValues[$key] = $clinicDefaults[$key];
        }
    }
    $hasLogoUpload = is_array($_FILES['clinic_logo'] ?? null)
        && (int) ($_FILES['clinic_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $hasClinicInfoChanges = $hasLogoUpload
        || $submittedClinicValues['clinic_name'] !== trim((string) ($clinicInfo['clinic_name'] ?? ''))
        || $submittedClinicValues['clinic_location'] !== trim((string) ($clinicInfo['clinic_location'] ?? ''))
        || $submittedClinicValues['clinic_facebook'] !== trim((string) ($clinicInfo['clinic_facebook'] ?? ''));

    if (!$hasClinicInfoChanges) {
        $_SESSION['admin_clinic_info_notice'] = [
            'type' => 'info',
            'message' => 'No changes were made to the clinic information.',
        ];
        header('Location: clinic_information.php');
        exit();
    }

    try {
        $logoPath = (string) ($clinicInfo['clinic_logo'] ?? 'globalife.png');
        $logoFile = $_FILES['clinic_logo'] ?? null;
        if (is_array($logoFile) && (int) ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) ($logoFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Logo upload failed.');
            }
            if ((int) ($logoFile['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new RuntimeException('Logo file is too large.');
            }

            $tmpName = (string) ($logoFile['tmp_name'] ?? '');
            $imageInfo = $tmpName !== '' ? @getimagesize($tmpName) : false;
            $allowedMimeTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ];
            $mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
            if (!isset($allowedMimeTypes[$mimeType])) {
                throw new RuntimeException('Logo must be PNG or JPG.');
            }

            $logoDir = __DIR__ . '/uploads/clinic';
            if (!is_dir($logoDir) && !mkdir($logoDir, 0755, true)) {
                throw new RuntimeException('Logo upload folder could not be created.');
            }
            $newLogoName = 'clinic-logo-' . date('YmdHis') . '.' . $allowedMimeTypes[$mimeType];
            $newLogoPath = $logoDir . '/' . $newLogoName;
            if (!move_uploaded_file($tmpName, $newLogoPath)) {
                throw new RuntimeException('Logo could not be saved.');
            }
            $logoPath = 'uploads/clinic/' . $newLogoName;
        }

        $clinicInfoConn = getDBConnection();
        clinic_info_save($clinicInfoConn, [
            'clinic_name' => $_POST['clinic_name'] ?? '',
            'clinic_location' => $_POST['clinic_location'] ?? '',
            'clinic_location_url' => $clinicInfo['clinic_location_url'] ?? '',
            'clinic_facebook' => $_POST['clinic_facebook'] ?? '',
            'clinic_logo' => $logoPath,
        ]);
        $clinicInfoConn->close();
        $_SESSION['admin_clinic_info_notice'] = [
            'type' => 'success',
            'message' => 'Clinic information updated successfully.',
        ];
    } catch (Throwable $e) {
        $_SESSION['admin_clinic_info_notice'] = [
            'type' => 'error',
            'message' => 'Clinic information could not be saved. Please try again.',
        ];
    }

    header('Location: clinic_information.php');
    exit();
}

$pageTitle = 'Clinic Information | Globalife Administration';
$additionalStyles = '
body{background:#f4f8fb;color:#1f343d}
.settings-page{max-width:980px;margin:0 auto;padding:34px 20px 48px}
.settings-panel{border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06);overflow:hidden}
.settings-panel-head{display:flex;align-items:center;gap:18px;padding:26px 30px;border-bottom:1px solid #e4edf2;background:#fbfdff}
.settings-panel-icon{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:#edf6ff;color:#0f66ad}
.settings-panel-icon svg{width:32px;height:32px;fill:none;stroke:currentColor;stroke-width:2.1;stroke-linecap:round;stroke-linejoin:round}
.settings-panel-head h1{margin:0 0 6px;color:#061a40;font-size:1.55rem;line-height:1.15}
.settings-panel-head p{margin:0;color:#607784;line-height:1.45}
.settings-panel-body{padding:26px 30px}
.settings-form{display:grid;gap:22px}
.settings-form label,.settings-form-field{display:grid;gap:10px;color:#061a40;font-size:.94rem;font-weight:700}
.settings-form input,.settings-form textarea{width:100%;box-sizing:border-box;border:1px solid #bfd6e8;border-radius:8px;padding:13px 16px;font-size:.98rem;font-weight:400;color:#101b3d;line-height:1.45;background:#fff}
.settings-form input:focus,.settings-form textarea:focus{outline:0;border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.12)}
.settings-form textarea{min-height:106px;resize:vertical}
.settings-form input[type=file].clinic-logo-input{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
.settings-notice{margin-bottom:20px;border-radius:8px;padding:15px 18px;font-weight:700;line-height:1.45}
.settings-notice.success{background:#eaf7ef;border:1px solid #bfe2ca;color:#17643a}
.settings-notice.error{background:#fff0f0;border:1px solid #ffd0d5;color:#9d1c2c}
.clinic-info-notice-modal{position:fixed;inset:0;z-index:6600;display:grid;place-items:center;padding:20px;background:rgba(3,37,56,.48);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}
.clinic-info-notice-dialog{width:min(460px,100%);padding:28px 26px 24px;border:1px solid #c9e2ef;border-radius:18px;background:#fff;box-shadow:0 28px 80px rgba(4,35,52,.3);text-align:center}
.clinic-info-notice-icon{width:58px;height:58px;margin:0 auto 14px;display:grid;place-items:center;border-radius:50%;background:#e4f7eb;color:#168448}
.clinic-info-notice-modal.info .clinic-info-notice-icon{background:#eaf6ff;color:#0878b5}
.clinic-info-notice-modal.error .clinic-info-notice-icon{background:#fff0f0;color:#b42335}
.clinic-info-notice-icon svg{width:29px;height:29px;fill:none;stroke:currentColor;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.clinic-info-notice-dialog h2{margin:0;color:#073b4c;font-size:1.35rem}
.clinic-info-notice-dialog p{margin:9px 0 22px;color:#607784;line-height:1.5}
.clinic-info-notice-close{min-height:42px;padding:0 24px;border:0;border-radius:8px;background:#0f7cc2;color:#fff;font:inherit;font-weight:900;cursor:pointer}
.clinic-info-notice-close:hover{background:#086aa8}
.clinic-logo-upload{display:grid;grid-template-columns:112px minmax(0,1fr);gap:18px;align-items:center}
.clinic-logo-preview-box{width:94px;height:94px;border:2px dashed #b9dced;border-radius:12px;background:#f6fbff;display:grid;place-items:center;overflow:hidden;color:#607784;font-size:.9rem;font-weight:500;text-align:center;line-height:1.25}
.clinic-logo-preview-box img{width:100%;height:100%;object-fit:contain;background:#fff}
.clinic-logo-preview-box span{padding:8px}
.clinic-logo-upload-text{display:grid;gap:8px;justify-items:start}
.clinic-logo-button{display:inline-flex;align-items:center;justify-content:center;min-width:180px;min-height:48px;border-radius:8px;background:linear-gradient(135deg,#1f7be0,#14a8df);color:#fff;font-weight:700;font-size:1rem;cursor:pointer;box-shadow:0 10px 20px rgba(15,124,194,.18)}
.clinic-logo-button:hover{background:linear-gradient(135deg,#0f66ad,#0f9cca)}
.clinic-logo-help{margin:0;color:#607784;font-size:.86rem;font-weight:400;line-height:1.45}
.settings-actions{display:flex;justify-content:flex-end;gap:14px;margin:26px -30px -26px;padding:20px 30px;border-top:1px solid #e4edf2;background:#fbfdff}
.settings-btn{display:inline-flex;align-items:center;justify-content:center;min-height:50px;min-width:160px;border-radius:10px;border:1px solid transparent;padding:10px 18px;background:linear-gradient(135deg,#1f7be0,#14a8df);color:#fff;font-weight:700;text-decoration:none;cursor:pointer}
.settings-btn.secondary{background:#eef7ff;color:#0b4f80;border-color:#cfe3f2}
@media(max-width:560px){.settings-page{padding:22px 12px 38px}.settings-panel-head{align-items:flex-start;padding:22px;flex-direction:column}.settings-panel-body{padding:22px}.clinic-logo-upload{grid-template-columns:1fr}.clinic-logo-button{width:100%}.settings-actions{margin:22px -22px -22px;padding:18px 22px;flex-direction:column-reverse}.settings-btn{width:100%}}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const logoInput = document.getElementById("clinicLogoInput");
    const logoPreview = document.getElementById("clinicLogoPreview");
    const logoPreviewText = document.getElementById("clinicLogoPreviewText");
    if (logoInput && logoPreview) {
        logoInput.addEventListener("change", function () {
            const file = logoInput.files && logoInput.files[0] ? logoInput.files[0] : null;
            if (!file) return;
            const previewUrl = URL.createObjectURL(file);
            logoPreview.src = previewUrl;
            logoPreview.hidden = false;
            if (logoPreviewText) logoPreviewText.hidden = true;
            logoPreview.onload = function () {
                URL.revokeObjectURL(previewUrl);
            };
        });
    }
    const noticeModal = document.getElementById("clinicInfoNoticeModal");
    const noticeClose = document.getElementById("clinicInfoNoticeClose");
    if (noticeModal && noticeClose) {
        const closeNotice = function () {
            noticeModal.remove();
            document.body.style.overflow = "";
        };
        document.body.style.overflow = "hidden";
        noticeClose.addEventListener("click", closeNotice);
        noticeModal.addEventListener("click", function (event) {
            if (event.target === noticeModal) closeNotice();
        });
        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape") closeNotice();
        });
    }
});
';

function settings_page_icon(): string {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21h16"/><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M9 9h6"/><path d="M12 6v6"/><path d="M10 21v-4h4v4"/></svg>';
}

include 'includes/header.php';
?>
<main class="settings-page">
    <section class="settings-panel">
        <div class="settings-panel-head">
            <span class="settings-panel-icon"><?php echo settings_page_icon(); ?></span>
            <div>
                <h1>Clinic Information</h1>
                <p>Edit the public clinic name, location, and Facebook details.</p>
            </div>
        </div>
        <div class="settings-panel-body">
            <?php if (!empty($clinicInfoNotice['message'])): ?>
                <div class="clinic-info-notice-modal <?php echo htmlspecialchars($clinicInfoNoticeType); ?>" id="clinicInfoNoticeModal" role="dialog" aria-modal="true" aria-labelledby="clinicInfoNoticeTitle">
                    <section class="clinic-info-notice-dialog">
                        <div class="clinic-info-notice-icon" aria-hidden="true">
                            <?php if ($clinicInfoNoticeType === 'success'): ?>
                                <svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>
                            <?php elseif ($clinicInfoNoticeType === 'error'): ?>
                                <svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24"><path d="M12 10v6M12 7h.01"/><circle cx="12" cy="12" r="9"/></svg>
                            <?php endif; ?>
                        </div>
                        <h2 id="clinicInfoNoticeTitle"><?php echo htmlspecialchars($clinicInfoNoticeTitle); ?></h2>
                        <p><?php echo htmlspecialchars((string) $clinicInfoNotice['message']); ?></p>
                        <button type="button" class="clinic-info-notice-close" id="clinicInfoNoticeClose">Close</button>
                    </section>
                </div>
            <?php endif; ?>
            <form class="settings-form" method="POST" action="clinic_information.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_clinic_info">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['admin_settings_csrf']); ?>">
                <div class="settings-form-field">
                    <span>Logo</span>
                    <div class="clinic-logo-upload">
                        <div class="clinic-logo-preview-box">
                            <img id="clinicLogoPreview" src="<?php echo htmlspecialchars((string) ($clinicInfo['clinic_logo'] ?? 'globalife.png')); ?>" alt="Current clinic logo">
                            <span id="clinicLogoPreviewText" hidden>Logo<br>Preview</span>
                        </div>
                        <div class="clinic-logo-upload-text">
                            <label class="clinic-logo-button" for="clinicLogoInput">Upload Logo</label>
                            <input id="clinicLogoInput" class="clinic-logo-input" type="file" name="clinic_logo" accept="image/png,image/jpeg">
                            <p class="clinic-logo-help">Recommended: 200 x 200px, PNG or JPG</p>
                        </div>
                    </div>
                </div>
                <label>
                    Clinic name
                    <input type="text" name="clinic_name" value="<?php echo htmlspecialchars((string) $clinicInfo['clinic_name']); ?>" required>
                </label>
                <label>
                    Location
                    <textarea name="clinic_location" required><?php echo htmlspecialchars((string) $clinicInfo['clinic_location']); ?></textarea>
                </label>
                <label>
                    Facebook
                    <input type="text" name="clinic_facebook" value="<?php echo htmlspecialchars((string) $clinicInfo['clinic_facebook']); ?>" required>
                </label>
                <div class="settings-actions">
                    <button type="submit" class="settings-btn">Save clinic info</button>
                </div>
            </form>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>

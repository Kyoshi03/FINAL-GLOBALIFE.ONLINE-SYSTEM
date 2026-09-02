<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/name_parts.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';
require_once __DIR__ . '/includes/admin_notifications.php';

$pageTitle = 'User Management | Globalife Administration';
$currentUser = getCurrentUser();
$conn = getDBConnection();
ensurePatientProfilePhotoColumn($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'toggle_user') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $nextState = (int) ($_POST['next_state'] ?? 0) === 1 ? 1 : 0;

    if ($userId <= 0) {
        $_SESSION['error'] = 'Please choose a valid account.';
    } elseif ($userId === (int) ($currentUser['id'] ?? 0)) {
        $_SESSION['error'] = 'You cannot disable your own account.';
    } else {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
        $checkStmt->bind_param('i', $userId);
        $checkStmt->execute();
        $patientExists = $checkStmt->get_result()->num_rows === 1;
        $checkStmt->close();

        if (!$patientExists) {
            $_SESSION['error'] = 'Only patient accounts can be disabled here.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ? AND role = 'patient'");
            $stmt->bind_param('ii', $nextState, $userId);
            if ($stmt->execute()) {
                $_SESSION['success'] = $nextState === 1 ? 'Patient account enabled.' : 'Patient account disabled.';
            } else {
                $_SESSION['error'] = 'Account status could not be updated.';
            }
            $stmt->close();
        }
    }

    $conn->close();
    header('Location: admin_accounts.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'delete_user') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $confirmation = strtoupper(trim((string) ($_POST['delete_confirmation'] ?? '')));

    if ($userId <= 0) {
        $_SESSION['error'] = 'Please choose a valid account.';
    } elseif ($userId === (int) ($currentUser['id'] ?? 0)) {
        $_SESSION['error'] = 'You cannot delete your own account.';
    } elseif ($confirmation !== 'DELETE') {
        $_SESSION['error'] = 'Type DELETE to confirm account deletion.';
    } else {
        $lookupStmt = $conn->prepare('SELECT role, full_name FROM users WHERE id = ? LIMIT 1');
        $lookupStmt->bind_param('i', $userId);
        $lookupStmt->execute();
        $targetUser = $lookupStmt->get_result()->fetch_assoc();
        $lookupStmt->close();

        if (!$targetUser) {
            $_SESSION['error'] = 'Account was not found.';
        } else {
            if ((string) $targetUser['role'] === 'doctor') {
                $clearDoctor = $conn->prepare('UPDATE appointments SET doctor_id = NULL WHERE doctor_id = ?');
                if ($clearDoctor) {
                    $clearDoctor->bind_param('i', $userId);
                    $clearDoctor->execute();
                    $clearDoctor->close();
                }
            }

            $deleteStmt = $conn->prepare('DELETE FROM users WHERE id = ?');
            $deleteStmt->bind_param('i', $userId);
            if ($deleteStmt->execute()) {
                $_SESSION['success'] = trim((string) ($targetUser['full_name'] ?? 'Account')) . ' was deleted.';
            } else {
                $_SESSION['error'] = 'Account could not be deleted.';
            }
            $deleteStmt->close();
        }
    }

    $conn->close();
    header('Location: admin_accounts.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'add_staff') {
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $suffix = trim((string) ($_POST['suffix'] ?? ''));
    $role = trim((string) ($_POST['role'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $email = trim((string) ($_POST['email'] ?? ''));
    $allowedRoles = ['admin', 'doctor'];
    $displayName = clinic_name_build_full_name([
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'last_name' => $lastName,
        'suffix' => $suffix,
    ]);

    if ($displayName === '' || $username === '' || !in_array($role, $allowedRoles, true)) {
        $_SESSION['error'] = 'Complete the staff name, username, and staff role.';
    } elseif (strlen($password) < 8) {
        $_SESSION['error'] = 'Use a temporary password with at least 8 characters.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = 'Enter a valid email address.';
    } elseif (clinic_name_letter_count($firstName) > 15) {
        $_SESSION['error'] = 'First name must not exceed 15 letters.';
    } elseif ($middleName !== '' && clinic_name_letter_count($middleName) !== 1) {
        $_SESSION['error'] = 'Middle name must be a single letter.';
    } elseif (clinic_name_letter_count($lastName) > 15) {
        $_SESSION['error'] = 'Last name must not exceed 15 letters.';
    } elseif ($suffix !== '' && clinic_name_letter_count($suffix) > 3) {
        $_SESSION['error'] = 'Suffix must not exceed 3 letters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            'INSERT INTO users (username, password, first_name, middle_name, last_name, suffix, role, email, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->bind_param('ssssssss', $username, $hash, $firstName, $middleName, $lastName, $suffix, $role, $email);
        if ($stmt->execute()) {
            $newStaffId = (int) $stmt->insert_id;
            create_admin_notification(
                $conn,
                'staff_account_created',
                'New staff account',
                $displayName . ' was added as ' . ucfirst($role) . '.',
                $newStaffId
            );
            $_SESSION['success'] = 'Staff account created successfully.';
        } else {
            $_SESSION['error'] = $conn->errno === 1062
                ? 'That username is already in use.'
                : 'The staff account could not be created.';
        }
        $stmt->close();
    }

    $conn->close();
    header('Location: admin_accounts.php');
    exit();
}

$message = (string) ($_SESSION['success'] ?? '');
$error = (string) ($_SESSION['error'] ?? '');
unset($_SESSION['success'], $_SESSION['error']);

$counts = ['admin' => 0, 'doctor' => 0, 'patient' => 0];
$countResult = $conn->query("SELECT CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END AS role, COUNT(*) AS total FROM users GROUP BY CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END");
while ($countResult && ($row = $countResult->fetch_assoc())) {
    if (isset($counts[$row['role']])) {
        $counts[$row['role']] = (int) $row['total'];
    }
}

$users = [];
$result = $conn->query(
    "SELECT id, username, full_name, first_name, middle_name, last_name, suffix,
            CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END AS role,
            email, phone, profile_photo,
            profile_updated_at, COALESCE(is_active, 1) AS is_active
     FROM users
     ORDER BY FIELD(CASE WHEN role = 'receptionist' THEN 'admin' ELSE role END, 'admin', 'doctor', 'patient'), full_name ASC"
);
if ($result) {
    $users = $result->fetch_all(MYSQLI_ASSOC);
}
$conn->close();

$staffCount = $counts['admin'] + $counts['doctor'];
$additionalStyles = patientAvatarStyles() . '
body{background:#f4f8fb;color:#1f343d}
.accounts-page{max-width:1180px;margin:0 auto;padding:34px 20px 48px}
.accounts-intro{display:grid;grid-template-columns:minmax(0,1fr) 430px;align-items:end;gap:24px;margin-bottom:22px}
.accounts-intro h1{margin:0 0 7px;color:#061a40;font-size:2.05rem;line-height:1.12}
.accounts-intro p{max-width:660px;margin:0;color:#607784;line-height:1.6}
.account-totals{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.account-total{min-width:0;min-height:96px;padding:18px 28px;border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06);display:flex;align-items:center;justify-content:center;gap:22px}
.account-total-icon{width:58px;height:58px;flex:0 0 58px;border-radius:50%;display:grid;place-items:center;background:#edf6ff;color:#0f7cc2}
.account-total-icon svg{display:block;width:29px;height:29px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;overflow:visible}
.account-symbol{position:relative;display:block;width:30px;height:30px}
.account-symbol::before{content:"";position:absolute;left:50%;top:5px;width:10px;height:10px;border:3px solid currentColor;border-radius:50%;transform:translateX(-50%);box-sizing:border-box}
.account-symbol::after{content:"";position:absolute;left:50%;top:18px;width:24px;height:13px;border:3px solid currentColor;border-bottom:0;border-radius:16px 16px 0 0;transform:translateX(-50%);box-sizing:border-box}
.account-total-copy{display:grid;gap:2px;min-width:92px;align-content:center}
.account-total span{display:block;color:#657b88;font-size:.92rem;font-weight:850}
.account-total strong{display:block;margin-top:2px;color:#0066cc;font-size:2rem;line-height:1}
.notice{margin-top:16px;padding:13px 15px;border-radius:8px;font-weight:800}
.notice.ok{border:1px solid #bfe6ce;background:#edf9f1;color:#17643a}
.notice.error{border:1px solid #ffd0d5;background:#fff0f0;color:#9d1c2c}
.account-layout{display:grid;grid-template-columns:340px minmax(0,1fr);gap:28px;margin-top:20px;align-items:start}
.account-panel{min-width:0;border:1px solid #d8e6ed;border-radius:8px;background:#fff;box-shadow:0 10px 24px rgba(25,76,110,.06);overflow:hidden}
.account-panel-head{padding:22px 22px 14px;border-bottom:1px solid #e1ebf0;display:flex;align-items:center;gap:14px}
.account-panel-icon{width:44px;height:44px;border-radius:8px;display:grid;place-items:center;background:#edf6ff;color:#0f7cc2;flex:0 0 auto}
.account-panel-icon svg{width:23px;height:23px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.account-panel-head h2{margin:0;color:#073b4c;font-size:1.2rem}
.account-panel-head p{margin:5px 0 0;color:#657b88;font-size:.9rem;line-height:1.5}
.staff-create-form{display:grid;gap:12px;padding:18px 22px 22px}
.name-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.field{display:grid;gap:6px}
.field label{color:#315466;font-size:.82rem;font-weight:900}
.field input,.field select,.directory-tools input,.directory-tools select{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cfe0e9;border-radius:7px;background:#fff;color:#183b4d;padding:9px 11px;font:inherit}
.field input:focus,.field select:focus,.directory-tools input:focus,.directory-tools select:focus{border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.1);outline:none}
.password-wrap{position:relative}
.password-wrap input{padding-right:62px}
.password-toggle{position:absolute;right:5px;top:5px;bottom:5px;border:0;border-radius:5px;background:#edf5f8;color:#075985;font:inherit;font-size:.78rem;font-weight:900;cursor:pointer}
.primary-btn{min-height:46px;border:0;border-radius:7px;background:#0f7cc2;color:#fff;font:inherit;font-weight:900;cursor:pointer;box-shadow:0 10px 22px rgba(15,124,194,.18)}
.primary-btn:hover{background:#0b659f}
.directory-tools{display:grid;grid-template-columns:minmax(0,1fr) 190px;gap:10px;padding:14px 22px;border-bottom:1px solid #e1ebf0}
.account-list{display:grid}
.account-list-head{display:grid;grid-template-columns:40px minmax(130px,1.2fr) minmax(135px,1fr) 82px 86px 150px;gap:10px;align-items:center;padding:12px 18px;border-bottom:1px solid #e4edf2;background:#f8fcff;color:#5f7280;font-size:.78rem;font-weight:950}
.account-row{display:grid;grid-template-columns:40px minmax(130px,1.2fr) minmax(135px,1fr) 82px 86px 150px;gap:10px;align-items:center;padding:12px 18px;border-bottom:1px solid #e4edf2}
.account-row:last-child{border-bottom:0}
.account-row.hidden,.account-row.page-hidden{display:none}
.account-avatar{display:flex;align-items:center;justify-content:center;width:40px;height:40px;border:1px solid #c8dce7;border-radius:50%;background:#eaf5fa;color:#0878b8;font-weight:900;overflow:hidden}
.account-avatar img{width:100%;height:100%;object-fit:cover}
.account-name{color:#073b4c;font-weight:900;font-size:.95rem;line-height:1.25}
.account-meta{display:flex;flex-wrap:wrap;gap:5px 10px;margin-top:4px;color:#667c88;font-size:.82rem}
.account-email{color:#667c88;font-size:.84rem;line-height:1.25;overflow-wrap:anywhere}
.account-role{display:flex;align-items:center}
.account-status{display:flex;align-items:center}
.account-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px;min-width:0}
.role-badge,.state-badge{display:inline-flex;align-items:center;min-height:27px;border-radius:14px;padding:4px 9px;font-size:.72rem;font-weight:900;text-transform:uppercase}
.role-badge.admin,.role-badge.doctor{background:#e7f2ff;color:#0066cc}
.role-badge.patient{background:#f2eaff;color:#6f42c1}
.state-badge.active{background:#e5f6eb;color:#17643a}
.state-badge.inactive{background:#fdecef;color:#a51220}
.edit-link,.toggle-user-btn,.delete-user-btn{display:inline-flex;align-items:center;justify-content:center;min-height:32px;border:1px solid #cde1ed;border-radius:6px;padding:6px 9px;background:#fff;color:#0066cc;font:inherit;font-size:.8rem;font-weight:900;text-decoration:none;cursor:pointer;white-space:nowrap}
.toggle-user-btn.danger{border-color:#ffc7cf;color:#c1121f}
.toggle-user-btn.restore{border-color:#bfe6ce;color:#17643a}
.delete-user-btn{border-color:#ffc7cf;color:#c1121f}
.account-action-muted{color:#90a3ad;font-size:.82rem;font-weight:800}
.empty-result{display:none;margin:18px 20px;padding:18px;border:1px dashed #c8dce6;border-radius:7px;color:#657b88;text-align:center}
.empty-result.show{display:block}
.account-list-footer{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 22px;border-top:1px solid #e4edf2;color:#667c88;font-size:.86rem}
.account-pagination{display:flex;align-items:center;gap:8px}
.page-btn{width:38px;height:38px;border:1px solid #d8e6ed;border-radius:7px;background:#fff;color:#0b4f80;font:inherit;font-weight:900;cursor:pointer}
.page-btn:hover:not(:disabled){border-color:#0f7cc2;color:#0066cc}
.page-btn.active{border-color:#0066cc;background:#0066cc;color:#fff;box-shadow:0 10px 20px rgba(0,102,204,.18)}
.page-btn:disabled{opacity:.45;cursor:not-allowed}
.account-modal{position:fixed;inset:0;z-index:4200;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(7,24,38,.54)}
.account-modal.is-open{display:flex}
.account-modal-card{width:min(460px,100%);border:1px solid #d8e6ed;border-radius:10px;background:#fff;box-shadow:0 24px 70px rgba(7,24,38,.26);overflow:hidden}
.account-modal-head{padding:22px;border-bottom:1px solid #e4edf2;background:#f8fcff}
.account-modal-head h2{margin:0;color:#073b4c;font-size:1.25rem}
.account-modal-head p{margin:7px 0 0;color:#657b88;line-height:1.5}
.account-modal-body{display:grid;gap:8px;padding:18px 22px 0}
.account-modal-body label{color:#315466;font-size:.82rem;font-weight:900}
.account-modal-body input{width:100%;min-height:42px;box-sizing:border-box;border:1px solid #cfe0e9;border-radius:7px;background:#fff;color:#183b4d;padding:9px 11px;font:inherit}
.account-modal-body input:focus{border-color:#0f7cc2;box-shadow:0 0 0 3px rgba(15,124,194,.1);outline:none}
.account-modal-actions{display:flex;justify-content:flex-end;gap:10px;padding:18px 22px}
.modal-secondary,.modal-primary{min-height:40px;border-radius:7px;padding:8px 14px;font:inherit;font-weight:900;cursor:pointer}
.modal-secondary{border:1px solid #cde1ed;background:#eef7fc;color:#075985}
.modal-primary{border:1px solid #ffc7cf;background:#fff0f2;color:#c1121f}
.modal-primary.restore{border-color:#bfe6ce;background:#edf9f1;color:#17643a}
@media(max-width:1000px){.accounts-intro,.account-layout{grid-template-columns:1fr}.account-totals{max-width:560px}.account-row,.account-list-head{grid-template-columns:40px minmax(0,1fr) 82px 86px 150px}.account-email{display:none}}
@media(max-width:620px){.accounts-page{padding:20px 13px 38px}.accounts-intro h1{font-size:1.65rem}.account-totals{grid-template-columns:1fr}.directory-tools{grid-template-columns:1fr}.account-list-head{display:none}.account-row{grid-template-columns:auto minmax(0,1fr)}.account-email,.account-role,.account-status,.account-actions{grid-column:1/-1}.account-actions{justify-content:flex-start;padding-left:55px}.account-meta{display:grid;gap:4px}.account-list-footer{align-items:flex-start;flex-direction:column}.account-pagination{width:100%;justify-content:flex-end}}
';

$additionalScripts = '
document.addEventListener("DOMContentLoaded", function () {
    const search = document.getElementById("accountSearch");
    const role = document.getElementById("accountRole");
    const empty = document.getElementById("accountNoMatches");
    const rows = Array.from(document.querySelectorAll("[data-account-row]"));
    const pageInfo = document.getElementById("accountPageInfo");
    const pagination = document.getElementById("accountPagination");
    const pageSize = 5;
    let currentPage = 1;
    let filteredRows = rows;

    function renderAccounts() {
        const totalPages = Math.max(1, Math.ceil(filteredRows.length / pageSize));
        currentPage = Math.min(currentPage, totalPages);
        const start = (currentPage - 1) * pageSize;
        const end = start + pageSize;
        rows.forEach(function (row) {
            row.classList.add("page-hidden");
        });
        filteredRows.slice(start, end).forEach(function (row) {
            row.classList.remove("page-hidden");
        });

        if (pageInfo) {
            if (filteredRows.length === 0) {
                pageInfo.textContent = "Showing 0 accounts";
            } else {
                pageInfo.textContent = "Showing " + (start + 1) + " to " + Math.min(end, filteredRows.length) + " of " + filteredRows.length + " accounts";
            }
        }

        if (!pagination) return;
        pagination.innerHTML = "";

        const prev = document.createElement("button");
        prev.type = "button";
        prev.className = "page-btn";
        prev.innerHTML = "&lsaquo;";
        prev.disabled = currentPage <= 1;
        prev.addEventListener("click", function () {
            currentPage -= 1;
            renderAccounts();
        });
        pagination.appendChild(prev);

        const current = document.createElement("button");
        current.type = "button";
        current.className = "page-btn active";
        current.textContent = currentPage;
        current.setAttribute("aria-current", "page");
        pagination.appendChild(current);

        const next = document.createElement("button");
        next.type = "button";
        next.className = "page-btn";
        next.innerHTML = "&rsaquo;";
        next.disabled = currentPage >= totalPages;
        next.addEventListener("click", function () {
            currentPage += 1;
            renderAccounts();
        });
        pagination.appendChild(next);
    }

    function filterAccounts() {
        const query = (search && search.value ? search.value : "").toLowerCase().trim();
        const selectedRole = role ? role.value : "";
        filteredRows = [];
        rows.forEach(function (row) {
            const matchesText = !query || (row.getAttribute("data-search") || "").toLowerCase().includes(query);
            const matchesRole = !selectedRole || row.getAttribute("data-role") === selectedRole;
            const show = matchesText && matchesRole;
            row.classList.toggle("hidden", !show);
            if (show) filteredRows.push(row);
        });
        currentPage = 1;
        if (empty) empty.classList.toggle("show", filteredRows.length === 0);
        renderAccounts();
    }

    if (search) search.addEventListener("input", filterAccounts);
    if (role) role.addEventListener("change", filterAccounts);
    filterAccounts();

    document.querySelectorAll("[data-password-toggle]").forEach(function (button) {
        button.addEventListener("click", function () {
            const input = document.getElementById(button.getAttribute("data-password-toggle"));
            if (!input) return;
            const hidden = input.type === "password";
            input.type = hidden ? "text" : "password";
            button.textContent = hidden ? "Hide" : "Show";
        });
    });

    const modal = document.getElementById("accountStatusModal");
    const modalTitle = document.getElementById("accountStatusTitle");
    const modalText = document.getElementById("accountStatusText");
    const modalUserId = document.getElementById("accountStatusUserId");
    const modalNextState = document.getElementById("accountStatusNextState");
    const modalSubmit = document.getElementById("accountStatusSubmit");
    const deleteModal = document.getElementById("accountDeleteModal");
    const deleteTitle = document.getElementById("accountDeleteTitle");
    const deleteText = document.getElementById("accountDeleteText");
    const deleteUserId = document.getElementById("accountDeleteUserId");
    const deleteInput = document.getElementById("accountDeleteInput");
    const deleteSubmit = document.getElementById("accountDeleteSubmit");

    function closeStatusModal() {
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
    }

    document.querySelectorAll("[data-toggle-account]").forEach(function (button) {
        button.addEventListener("click", function () {
            if (!modal || !modalTitle || !modalText || !modalUserId || !modalNextState || !modalSubmit) return;
            const name = button.getAttribute("data-user-name") || "this user";
            const nextState = button.getAttribute("data-next-state") || "0";
            const isRestore = nextState === "1";
            modalTitle.textContent = isRestore ? "Enable this user?" : "Disable this user?";
            modalText.textContent = isRestore
                ? "Are you sure you want to enable " + name + "? This account can log in again."
                : "Are you sure you want to disable " + name + "? This account cannot log in until enabled again.";
            modalUserId.value = button.getAttribute("data-user-id") || "";
            modalNextState.value = nextState;
            modalSubmit.textContent = isRestore ? "Enable User" : "Disable User";
            modalSubmit.classList.toggle("restore", isRestore);
            modal.classList.add("is-open");
            modal.setAttribute("aria-hidden", "false");
        });
    });

    document.querySelectorAll("[data-close-account-modal]").forEach(function (button) {
        button.addEventListener("click", closeStatusModal);
    });
    if (modal) {
        modal.addEventListener("click", function (event) {
            if (event.target === modal) closeStatusModal();
        });
    }
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeStatusModal();
    });

    function closeDeleteModal() {
        if (!deleteModal) return;
        deleteModal.classList.remove("is-open");
        deleteModal.setAttribute("aria-hidden", "true");
        if (deleteInput) deleteInput.value = "";
        if (deleteSubmit) deleteSubmit.disabled = true;
    }

    document.querySelectorAll("[data-delete-account]").forEach(function (button) {
        button.addEventListener("click", function () {
            if (!deleteModal || !deleteTitle || !deleteText || !deleteUserId || !deleteInput || !deleteSubmit) return;
            const name = button.getAttribute("data-user-name") || "this user";
            deleteTitle.textContent = "Delete this user?";
            deleteText.textContent = "This will permanently delete " + name + ". Type DELETE to continue.";
            deleteUserId.value = button.getAttribute("data-user-id") || "";
            deleteInput.value = "";
            deleteSubmit.disabled = true;
            deleteModal.classList.add("is-open");
            deleteModal.setAttribute("aria-hidden", "false");
            deleteInput.focus();
        });
    });

    if (deleteInput && deleteSubmit) {
        deleteInput.addEventListener("input", function () {
            deleteSubmit.disabled = deleteInput.value.trim().toUpperCase() !== "DELETE";
        });
    }
    document.querySelectorAll("[data-close-delete-modal]").forEach(function (button) {
        button.addEventListener("click", closeDeleteModal);
    });
    if (deleteModal) {
        deleteModal.addEventListener("click", function (event) {
            if (event.target === deleteModal) closeDeleteModal();
        });
    }
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") closeDeleteModal();
    });
});
';

include 'includes/header.php';
?>
<main class="accounts-page">
    <section class="accounts-intro">
        <div>
            <h1>User Management</h1>
            <p>Create clinic staff accounts and manage patient access.</p>
        </div>
        <div class="account-totals" aria-label="Account totals">
            <div class="account-total">
                <span class="account-total-icon" aria-hidden="true">
                    <span class="account-symbol"></span>
                </span>
                <div class="account-total-copy"><span>Staff</span><strong><?php echo $staffCount; ?></strong></div>
            </div>
            <div class="account-total">
                <span class="account-total-icon" aria-hidden="true">
                    <span class="account-symbol"></span>
                </span>
                <div class="account-total-copy"><span>Patients</span><strong><?php echo $counts['patient']; ?></strong></div>
            </div>
        </div>
    </section>

    <?php if ($message): ?><div class="notice ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="account-layout">
        <section class="account-panel">
            <div class="account-panel-head">
                <span class="account-panel-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><path d="M8 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M20 8v6M23 11h-6"/></svg>
                </span>
                <div>
                    <h2>Add Account</h2>
                    <p>Create a new staff account.</p>
                </div>
            </div>
            <form method="post" class="staff-create-form">
                <input type="hidden" name="account_action" value="add_staff">
                <div class="name-grid">
                    <div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" maxlength="15" required autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"></div>
                    <div class="field"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" maxlength="1" autocomplete="additional-name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"></div>
                    <div class="field"><label for="last_name">Last name</label><input id="last_name" name="last_name" maxlength="15" required autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"></div>
                    <div class="field"><label for="suffix">Suffix</label><input id="suffix" name="suffix" maxlength="3" autocomplete="honorific-suffix" value="<?php echo htmlspecialchars($_POST['suffix'] ?? ''); ?>"></div>
                </div>
                <div class="field">
                    <label for="role">Staff role</label>
                    <select id="role" name="role" required>
                        <option value="">Select role</option>
                        <option value="admin">Administrator</option>
                        <option value="doctor">Doctor</option>
                    </select>
                </div>
                <div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="username"></div>
                <div class="field">
                    <label for="staff_password">Temporary password</label>
                    <div class="password-wrap">
                        <input type="password" id="staff_password" name="password" required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle" data-password-toggle="staff_password">Show</button>
                    </div>
                </div>
                <div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" autocomplete="email" placeholder="Optional"></div>
                <button type="submit" class="primary-btn">Create Account</button>
            </form>
        </section>

        <section class="account-panel">
            <div class="account-panel-head">
                <span class="account-panel-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </span>
                <div>
                    <h2>Accounts List</h2>
                    <p>Search all registered clinic staff and patients.</p>
                </div>
            </div>
            <div class="directory-tools" role="search">
                <input type="search" id="accountSearch" placeholder="Search name, username, or email" aria-label="Search accounts">
                <select id="accountRole" aria-label="Filter account role">
                    <option value="">All roles</option>
                    <option value="admin">Administrators</option>
                    <option value="doctor">Doctors</option>
                    <option value="patient">Patients</option>
                </select>
            </div>
            <div class="empty-result" id="accountNoMatches">No accounts match your search.</div>
            <div class="account-list">
                <div class="account-list-head" aria-hidden="true">
                    <span></span>
                    <span>Name</span>
                    <span>Email</span>
                    <span>Role</span>
                    <span>Status</span>
                    <span>Actions</span>
                </div>
                <?php foreach ($users as $user): ?>
                    <?php
                    $userRole = (string) $user['role'];
                    $active = (int) $user['is_active'] === 1;
                    $isCurrentUser = (int) $user['id'] === (int) ($currentUser['id'] ?? 0);
                    $emailText = trim((string) ($user['email'] ?? ''));
                    $emailText = $emailText !== '' ? $emailText : 'No email';
                    $photoUrl = patientProfilePhotoUrl($user['profile_photo'] ?? null, $user['profile_updated_at'] ?? null);
                    $searchText = implode(' ', [
                        $user['full_name'] ?? '',
                        $user['username'] ?? '',
                        $user['email'] ?? '',
                        $userRole,
                    ]);
                    ?>
                    <article class="account-row" data-account-row data-role="<?php echo htmlspecialchars($userRole); ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES); ?>">
                        <div class="account-avatar">
                            <?php if ($photoUrl): ?>
                                <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="">
                            <?php else: ?>
                                <?php echo htmlspecialchars(patientProfileInitials((string) $user['full_name'])); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="account-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                            <div class="account-meta">
                                <span><?php echo htmlspecialchars($user['username']); ?></span>
                            </div>
                        </div>
                        <div class="account-email"><?php echo htmlspecialchars($emailText); ?></div>
                        <div class="account-role">
                            <span class="role-badge <?php echo htmlspecialchars($userRole); ?>"><?php echo htmlspecialchars($userRole); ?></span>
                        </div>
                        <div class="account-status">
                            <span class="state-badge <?php echo $active ? 'active' : 'inactive'; ?>"><?php echo $active ? 'Active' : 'Disabled'; ?></span>
                        </div>
                        <div class="account-actions">
                            <?php if ($userRole === 'doctor'): ?>
                                <a class="edit-link" href="admin_doctors.php?edit=<?php echo (int) $user['id']; ?>">Edit</a>
                            <?php elseif ($userRole === 'patient'): ?>
                                <button
                                    type="button"
                                    class="toggle-user-btn <?php echo $active ? 'danger' : 'restore'; ?>"
                                    data-toggle-account
                                    data-user-id="<?php echo (int) $user['id']; ?>"
                                    data-user-name="<?php echo htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-next-state="<?php echo $active ? '0' : '1'; ?>"
                                ><?php echo $active ? 'Disable' : 'Enable'; ?></button>
                            <?php endif; ?>
                            <?php if (!$isCurrentUser): ?>
                                <button
                                    type="button"
                                    class="delete-user-btn"
                                    data-delete-account
                                    data-user-id="<?php echo (int) $user['id']; ?>"
                                    data-user-name="<?php echo htmlspecialchars((string) $user['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                >Delete</button>
                            <?php elseif ($userRole !== 'doctor' && $userRole !== 'patient'): ?>
                                <span class="account-action-muted">Current</span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="account-list-footer">
                <span id="accountPageInfo">Showing accounts</span>
                <div class="account-pagination" id="accountPagination" aria-label="Account pages"></div>
            </div>
        </section>
    </div>

    <div class="account-modal" id="accountStatusModal" aria-hidden="true">
        <form class="account-modal-card" method="post">
            <input type="hidden" name="account_action" value="toggle_user">
            <input type="hidden" name="user_id" id="accountStatusUserId" value="">
            <input type="hidden" name="next_state" id="accountStatusNextState" value="">
            <div class="account-modal-head">
                <h2 id="accountStatusTitle">Disable this user?</h2>
                <p id="accountStatusText">Are you sure you want to disable this user?</p>
            </div>
            <div class="account-modal-actions">
                <button class="modal-secondary" type="button" data-close-account-modal>Cancel</button>
                <button class="modal-primary" id="accountStatusSubmit" type="submit">Disable User</button>
            </div>
        </form>
    </div>

    <div class="account-modal" id="accountDeleteModal" aria-hidden="true">
        <form class="account-modal-card" method="post">
            <input type="hidden" name="account_action" value="delete_user">
            <input type="hidden" name="user_id" id="accountDeleteUserId" value="">
            <div class="account-modal-head">
                <h2 id="accountDeleteTitle">Delete this user?</h2>
                <p id="accountDeleteText">Type DELETE to continue.</p>
            </div>
            <div class="account-modal-body">
                <label for="accountDeleteInput">Type DELETE</label>
                <input id="accountDeleteInput" name="delete_confirmation" autocomplete="off" placeholder="DELETE">
            </div>
            <div class="account-modal-actions">
                <button class="modal-secondary" type="button" data-close-delete-modal>Cancel</button>
                <button class="modal-primary" id="accountDeleteSubmit" type="submit" disabled>Delete User</button>
            </div>
        </form>
    </div>
</main>
<?php include 'includes/footer.php'; ?>

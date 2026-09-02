<?php
require_once 'includes/session.php';
checkRole('admin');
require_once 'config/database.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';

$pageTitle = 'Laboratory services | Globalife Administration';
$conn = getDBConnection();
initLabBookingSchema($conn);

$message = $_SESSION['lab_service_message'] ?? '';
$error = $_SESSION['lab_service_error'] ?? '';
$showAddModal = !empty($_SESSION['lab_service_add_open']);
unset($_SESSION['lab_service_message'], $_SESSION['lab_service_error'], $_SESSION['lab_service_add_open']);
$showSuccessModal = $message !== '';

function receptionist_lab_redirect(array $params = []): void {
    $query = $params ? '?' . http_build_query($params) : '';
    header('Location: receptionist_lab_services.php' . $query);
    exit;
}

function receptionist_lab_price($value): string {
    if ($value === null || $value === '') {
        return 'None';
    }
    return '&#8369;' . number_format((float) $value, 2);
}

function receptionist_lab_category_label(?string $category): string {
    $category = trim((string) $category);
    if ($category === '') {
        return 'Other services';
    }

    $knownLabels = [
        'OPD PRE EMPLOYMENT',
        'SANITARY PERMIT',
        'CVSU',
        'HEMATOLOGY',
        'CLINICAL MICROSCOPY',
        'BLOOD CHEMISTRY',
        'CHEMISTRY PACKAGES',
        'BUNTIS PACKAGE',
        'THYROID FUNCTION TEST',
        'HEPATITIS',
        'HORMONES',
        'TUMOR MARKERS',
        'SEROLOGY',
        'BACTERIOLOGY',
        'HIV TEST',
        'OTHERS',
    ];

    foreach ($knownLabels as $label) {
        if (stripos($category, $label) !== false) {
            return $label;
        }
    }

    $clean = preg_replace('/^[^A-Za-z0-9]+/', '', $category) ?? $category;
    return trim($clean) !== '' ? trim($clean) : 'Other services';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['receptionist_svc_action'] ?? '';

    if ($action === 'import_catalog') {
        $imported = lab_insert_catalog_rows($conn, lab_catalog_seed_rows());
        lab_sync_categories_from_services($conn);
        $_SESSION['lab_service_message'] = "Imported {$imported} missing catalog row(s).";
        receptionist_lab_redirect();
    }

    if ($action === 'toggle_active') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare('UPDATE lab_services SET is_active = 1 - is_active WHERE id = ?');
            $st->bind_param('i', $id);
            $_SESSION['lab_service_message'] = $st->execute() ? 'Service status updated.' : 'Could not update service status.';
            $st->close();
            lab_sync_categories_from_services($conn);
        }
        receptionist_lab_redirect();
    }

    if ($action === 'bulk_delete_services') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['service_ids'] ?? []), fn ($id) => $id > 0)));
        if (empty($ids)) {
            $_SESSION['lab_service_error'] = 'Choose at least one service to delete.';
            receptionist_lab_redirect();
        }

        $deleted = 0;
        $blocked = 0;
        $deleteId = 0;
        $st = $conn->prepare('DELETE FROM lab_services WHERE id = ?');
        $st->bind_param('i', $deleteId);
        foreach ($ids as $deleteId) {
            if ($st->execute() && $st->affected_rows > 0) {
                $deleted++;
            } else {
                $blocked++;
            }
        }
        $st->close();
        lab_sync_categories_from_services($conn);

        if ($deleted > 0) {
            $_SESSION['lab_service_message'] = "Deleted {$deleted} selected service(s)." . ($blocked > 0 ? " {$blocked} could not be deleted because they may already be used in appointments." : '');
        } else {
            $_SESSION['lab_service_error'] = 'Could not delete selected services. If they are already used in appointments, set them inactive instead.';
        }
        receptionist_lab_redirect();
    }

    if ($action === 'delete_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id > 0) {
            $st = $conn->prepare('DELETE FROM lab_services WHERE id = ?');
            $st->bind_param('i', $id);
            if ($st->execute()) {
                $_SESSION['lab_service_message'] = 'Service deleted.';
            } else {
                $_SESSION['lab_service_error'] = 'Could not delete this service. If it is already used in an appointment, set it inactive instead.';
            }
            $st->close();
            lab_sync_categories_from_services($conn);
        }
        receptionist_lab_redirect();
    }

    if ($action === 'add_service' || $action === 'save_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $included = trim($_POST['included_tests'] ?? '');
        $opd = max(0, (float) ($_POST['opd_price'] ?? 0));
        $homeRaw = trim((string) ($_POST['home_service_price'] ?? ''));
        $home = $homeRaw === '' ? null : max(0, (float) $homeRaw);
        $isPackage = isset($_POST['is_package']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $category === '') {
            $_SESSION['lab_service_error'] = 'Name and category are required.';
            if ($action === 'add_service') {
                $_SESSION['lab_service_add_open'] = 1;
            }
            receptionist_lab_redirect($id > 0 ? ['edit' => $id] : []);
        }

        if ($action === 'add_service') {
            if ($home === null) {
                $st = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
                $st->bind_param('ssssdii', $name, $category, $description, $included, $opd, $isPackage, $isActive);
            } else {
                $st = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $st->bind_param('ssssddii', $name, $category, $description, $included, $opd, $home, $isPackage, $isActive);
            }
            $_SESSION['lab_service_message'] = $st->execute() ? 'Service added.' : 'Could not add service.';
            $st->close();
        } else {
            if ($id <= 0) {
                $_SESSION['lab_service_error'] = 'Invalid service.';
                receptionist_lab_redirect();
            }

            if ($home === null) {
                $st = $conn->prepare('UPDATE lab_services SET name=?, category=?, description=?, included_tests=?, opd_price=?, home_service_price=NULL, is_package=?, is_active=? WHERE id=?');
                $st->bind_param('ssssdiii', $name, $category, $description, $included, $opd, $isPackage, $isActive, $id);
            } else {
                $st = $conn->prepare('UPDATE lab_services SET name=?, category=?, description=?, included_tests=?, opd_price=?, home_service_price=?, is_package=?, is_active=? WHERE id=?');
                $st->bind_param('ssssddiii', $name, $category, $description, $included, $opd, $home, $isPackage, $isActive, $id);
            }
            $_SESSION['lab_service_message'] = $st->execute() ? 'Service updated.' : 'Could not update service.';
            $st->close();
        }

        lab_sync_categories_from_services($conn);
        receptionist_lab_redirect();
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $st = $conn->prepare('SELECT * FROM lab_services WHERE id = ?');
    $st->bind_param('i', $editId);
    $st->execute();
    $editRow = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$editRow) {
        $error = 'Selected service was not found.';
    }
}

$q = trim($_GET['q'] ?? '');
$cat = trim($_GET['cat'] ?? '');
$categoryQuery = trim($_GET['category_q'] ?? '');
$type = trim($_GET['type'] ?? '');
$status = trim($_GET['status'] ?? '');

$rows = [];
$result = $conn->query('SELECT * FROM lab_services ORDER BY is_package DESC, category, name');
if ($result) {
    $rows = $result->fetch_all(MYSQLI_ASSOC);
}
$conn->close();

$allRows = $rows;
$categories = [];
foreach ($allRows as $row) {
    $category = trim((string) ($row['category'] ?? ''));
    if ($category !== '') {
        $categories[$category] = true;
    }
}
$categories = array_keys($categories);
natcasesort($categories);

$activeCount = count(array_filter($allRows, fn ($r) => !empty($r['is_active'])));
$inactiveCount = count($allRows) - $activeCount;
$packageCount = count(array_filter($allRows, fn ($r) => !empty($r['is_package'])));
$individualCount = count($allRows) - $packageCount;
$homeCount = count(array_filter($allRows, fn ($r) => ($r['home_service_price'] ?? null) !== null && $r['home_service_price'] !== ''));

$rows = array_values(array_filter($rows, function ($row) use ($q, $cat, $categoryQuery, $type, $status) {
    if ($cat !== '' && ($row['category'] ?? '') !== $cat) {
        return false;
    }
    if ($categoryQuery !== '' && stripos((string) ($row['category'] ?? ''), $categoryQuery) === false) {
        return false;
    }
    if ($type === 'package' && empty($row['is_package'])) {
        return false;
    }
    if ($type === 'individual' && !empty($row['is_package'])) {
        return false;
    }
    if ($status === 'active' && empty($row['is_active'])) {
        return false;
    }
    if ($status === 'inactive' && !empty($row['is_active'])) {
        return false;
    }
    if ($q !== '') {
        $blob = strtolower(($row['name'] ?? '') . ' ' . ($row['category'] ?? '') . ' ' . ($row['included_tests'] ?? '') . ' ' . ($row['description'] ?? ''));
        if (strpos($blob, strtolower($q)) === false) {
            return false;
        }
    }
    return true;
}));

$groupedPackages = lab_group_services_list(array_values(array_filter($rows, fn ($r) => !empty($r['is_package']))));
$groupedIndividuals = lab_group_services_list(array_values(array_filter($rows, fn ($r) => empty($r['is_package']))));
$printRows = array_values(array_filter($allRows, fn ($r) => !empty($r['is_active'])));
$printGroupedPackages = lab_group_services_list(array_values(array_filter($printRows, fn ($r) => !empty($r['is_package']))));
$printGroupedIndividuals = lab_group_services_list(array_values(array_filter($printRows, fn ($r) => empty($r['is_package']))));
$duplicateGroups = [];
foreach ($allRows as $row) {
    $dupKey = strtolower(trim((string) ($row['category'] ?? ''))) . '|' . strtolower(trim((string) ($row['name'] ?? '')));
    $duplicateGroups[$dupKey][] = $row;
}
$duplicateGroups = array_filter($duplicateGroups, fn ($group) => count($group) > 1);
$hasDuplicateGroups = !empty($duplicateGroups);
$openEditModal = $editRow !== null;

$additionalStyles = '
    body { background:#f4f8fb; color:#1f343d; }
    .lab-wrap { max-width:1180px; margin:28px auto; padding:0 20px 48px; }
    .lab-hero { background:#073b4c; color:#fff; border-radius:8px; padding:24px; display:flex; justify-content:space-between; gap:18px; align-items:flex-start; box-shadow:0 14px 34px rgba(7,59,76,.16); }
    .lab-hero h1 { margin:0 0 8px; font-size:1.65rem; color:#fff; }
    .lab-hero p { margin:0; color:rgba(255,255,255,.78); line-height:1.5; max-width:720px; }
    .lab-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin:16px 0; }
    .lab-stat, .lab-card { background:#fff; border:1px solid #dbe9f1; border-radius:8px; box-shadow:0 10px 24px rgba(25,76,110,.06); }
    .lab-stat { padding:15px; }
    .lab-stat span { display:block; color:#60727d; font-weight:800; font-size:.78rem; text-transform:uppercase; }
    .lab-stat strong { display:block; margin-top:6px; color:#073b4c; font-size:1.55rem; line-height:1; }
    .lab-card { padding:20px; margin-bottom:16px; }
    .lab-card.service-section-card { padding:18px; }
    .lab-card h2 { margin:0 0 14px; color:#073b4c; font-size:1.32rem; font-weight:900; letter-spacing:0; }
    .lab-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .tool-row { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:16px; align-items:center; }
    .tool-row p { margin:0; color:#60727d; line-height:1.45; max-width:620px; }
    .tool-actions { display:flex; flex-wrap:wrap; gap:10px; justify-content:flex-end; align-items:center; }
    .tool-actions .btn { min-height:42px; padding:10px 14px; white-space:nowrap; }
    .tool-actions .primary-action { min-width:210px; }
    .field { display:grid; gap:5px; color:#60727d; font-size:.82rem; font-weight:800; }
    .field input, .field select, .field textarea { width:100%; box-sizing:border-box; border:1px solid #cfe0eb; border-radius:8px; padding:10px 11px; font:inherit; color:#1f343d; background:#fff; }
    .field textarea { min-height:78px; resize:vertical; }
    .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .field.full { grid-column:1/-1; }
    .checks { display:flex; gap:16px; flex-wrap:wrap; margin:14px 0; color:#435761; font-weight:800; }
    .btn { box-sizing:border-box; border:none; border-radius:8px; padding:10px 14px; min-height:40px; min-width:116px; display:inline-flex; align-items:center; justify-content:center; gap:7px; font:inherit; font-size:.9rem; line-height:1.15; font-weight:900; text-align:center; white-space:nowrap; cursor:pointer; text-decoration:none; background:#0f7cc2; color:#fff; }
    .btn.secondary { background:#eef7ff; color:#0b4f80; border:1px solid #d4e6f5; }
    .btn.warn { background:#fff0f0; color:#9d1c2c; border:1px solid #ffd0d5; }
    .btn.small { padding:8px 10px; min-height:36px; min-width:94px; font-size:.82rem; }
    .lab-toolbar .btn { min-width:108px; }
    .modal-actions .btn, .confirm-actions .btn, .dup-actions .btn { min-height:42px; }
    .btn:disabled { opacity:.48; cursor:not-allowed; }
    .msg-ok, .msg-err { border-radius:8px; padding:12px 14px; margin:14px 0 0; font-weight:800; }
    .msg-ok { background:#e7f7ed; color:#17643a; border:1px solid #bfe6ce; }
    .msg-err { background:#fff0f0; color:#9d1c2c; border:1px solid #ffd0d5; }
    .section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin:20px 0 12px; }
    .section-head .section-title { margin:0; color:#073b4c; font-size:1.16rem; font-weight:900; display:flex; align-items:center; gap:10px; }
    .section-head .btn { white-space:nowrap; }
    .section-title { margin:20px 0 12px; color:#073b4c; font-size:1.08rem; font-weight:900; display:flex; align-items:center; gap:10px; }
    .lab-table { width:100%; border-collapse:collapse; }
    .lab-table th, .lab-table td { padding:14px 12px; border-bottom:1px solid #edf2f6; text-align:left; vertical-align:top; }
    .lab-table th { background:#eaf3f8; color:#073b4c; font-size:.83rem; font-weight:900; text-transform:uppercase; }
    .select-col { width:44px; text-align:center !important; }
    .bulk-check, .js-select-visible { width:18px; height:18px; accent-color:#0f7cc2; cursor:pointer; }
    .bulk-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin:0 0 14px; padding:12px 14px; border:1px solid #bddff2; border-radius:8px; background:#f8fbff; color:#60727d; font-weight:800; line-height:1.4; }
    .bulk-bar-main { display:flex; flex-direction:column; gap:2px; }
    .bulk-bar-main small { color:#71838c; font-weight:700; }
    .bulk-bar .btn { flex-shrink:0; }
    .bulk-count { color:#073b4c; font-size:1rem; }
    body:not(.bulk-mode) .select-col, body:not(.bulk-mode) .bulk-bar { display:none; }
    body.bulk-mode .bulk-bar { display:flex; }
    .service-list-scroll { max-height:520px; overflow:auto; padding-right:8px; scrollbar-width:thin; scrollbar-color:#9fc5d8 #edf5f8; }
    .service-list-scroll::-webkit-scrollbar { width:9px; height:9px; }
    .service-list-scroll::-webkit-scrollbar-thumb { background:#9fc5d8; border-radius:999px; }
    .service-list-scroll::-webkit-scrollbar-track { background:#edf5f8; border-radius:999px; }
    .service-group { border:1px solid #dce9f1; border-radius:8px; background:#fff; overflow:hidden; margin-bottom:14px; }
    .service-group:last-child { margin-bottom:0; }
    .service-group-title { position:sticky; top:0; z-index:5; margin:0; padding:12px 14px; border-bottom:1px solid #e3edf3; background:#f7fbfd; color:#073b4c; font-size:1rem; font-weight:900; display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .service-group-title span { flex-shrink:0; }
    .table-scroll { overflow-x:auto; }
    .service-name strong { color:#073b4c; font-size:1rem; }
    .service-name small { display:block; margin-top:5px; color:#60727d; line-height:1.5; font-size:.84rem; }
    .pill { display:inline-flex; align-items:center; border-radius:999px; padding:6px 11px; font-size:.78rem; font-weight:900; }
    .pill.on { background:#e7f7ed; color:#17643a; }
    .pill.off { background:#fff0f0; color:#9d1c2c; }
    .pill.neutral { background:#eef7ff; color:#0b4f80; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .actions form { margin:0; }
    .empty { color:#60727d; padding:12px 0; }
    .modal-overlay { display:none; position:fixed; inset:0; z-index:3000; background:rgba(7,59,76,.58); padding:20px; align-items:center; justify-content:center; }
    .modal-overlay.active { display:flex; }
    .modal-box { width:min(860px,100%); max-height:min(90vh, 920px); overflow:auto; background:#fff; border-radius:8px; box-shadow:0 24px 60px rgba(7,59,76,.25); }
    .modal-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; padding:18px 20px 0; }
    .modal-head h2 { margin:0; color:#073b4c; font-size:1.15rem; }
    .modal-body { padding:20px; }
    .modal-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; padding:0 20px 20px; }
    .icon-close { border:none; background:#eef7ff; color:#0b4f80; width:38px; height:38px; border-radius:8px; cursor:pointer; font-size:1.1rem; font-weight:900; }
    .confirm-box, .success-box { width:min(460px,100%); background:#fff; border-radius:8px; padding:24px; box-shadow:0 24px 60px rgba(7,59,76,.25); }
    .confirm-box h2, .success-box h2 { margin:0 0 10px; color:#073b4c; font-size:1.15rem; }
    .confirm-box p, .success-box p { margin:0 0 18px; color:#60727d; line-height:1.5; }
    .confirm-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
    .confirm-input { width:100%; box-sizing:border-box; border:1px solid #cfe0eb; border-radius:8px; padding:10px 11px; font:inherit; color:#1f343d; margin:0 0 16px; display:none; }
    .confirm-input.active { display:block; }
    .success-box { text-align:center; }
    .print-price-list { display:none; }
    .print-header { border-bottom:2px solid #073b4c; padding-bottom:10px; margin-bottom:14px; display:flex; align-items:center; gap:14px; }
    .print-logo { width:58px; height:58px; border-radius:8px; object-fit:cover; flex-shrink:0; }
    .print-header h1 { margin:0 0 4px; color:#073b4c; font-size:22px; }
    .print-header p { margin:0; color:#3d4f58; font-size:12px; }
    .print-section-title { margin:14px 0 7px; color:#073b4c; font-size:15px; }
    .print-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
    .print-table th, .print-table td { border:1px solid #b8c8d1; padding:6px 7px; text-align:left; vertical-align:top; font-size:11px; }
    .print-table th { background:#eef5f8; color:#073b4c; }
    .print-note { margin-top:12px; color:#3d4f58; font-size:11px; }
    .dup-list { display:grid; gap:10px; }
    .dup-group { border:1px solid #dbe9f1; border-radius:8px; padding:16px; background:#f8fbff; }
    .dup-group h3 { margin:0 0 12px; font-size:1.05rem; color:#073b4c; font-weight:900; }
    .dup-item { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; padding:12px 0; border-top:1px solid #e8eef3; }
    .dup-item:first-of-type { border-top:none; padding-top:0; }
    .dup-meta strong { display:block; color:#073b4c; }
    .dup-meta small { display:block; color:#60727d; margin-top:4px; line-height:1.45; font-size:.84rem; }
    .dup-actions { display:flex; gap:7px; flex-wrap:wrap; justify-content:flex-end; }
    .dup-empty { color:#60727d; }
    @media print {
        @page { size: A4 portrait; margin:10mm; }
        body { background:#fff; color:#111; }
        body > header, body > footer, .lab-wrap > :not(.print-price-list), .modal-overlay, .logout-confirm-overlay, script { display:none !important; }
        .lab-wrap { display:block; max-width:none; margin:0; padding:0; }
        .print-price-list { display:block !important; width:190mm; margin:0 auto; }
        .print-table th, .print-table td { color:#111; }
        .print-logo { width:52px; height:52px; }
    }
    @media (max-width:900px) { .lab-hero, .lab-toolbar { flex-direction:column; align-items:stretch; } .tool-row { grid-template-columns:1fr; } .tool-actions { justify-content:flex-start; } .tool-actions .btn { flex:1 1 180px; } .lab-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .form-grid { grid-template-columns:1fr; } .lab-table { min-width:820px; } .bulk-bar { align-items:flex-start; flex-direction:column; } .bulk-bar .btn { width:100%; } }
    @media (max-width:560px) { .lab-wrap { padding:0 12px 36px; } .lab-grid { grid-template-columns:1fr; } .lab-hero, .lab-card { padding:16px; } }
';

include 'includes/header.php';
?>
<main class="lab-wrap">
    <?php if ($message): ?><div class="msg-ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg-err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="lab-card">
        <h2>Catalog tools</h2>
        <div class="tool-row">
            <p>Add a new lab test or package, then use filters below to review active and inactive services.</p>
            <div class="tool-actions">
                <button type="button" class="btn primary-action" data-open-modal="addServiceModal">Add laboratory service</button>
                <a class="btn secondary" href="receptionist_lab_services.php?status=active">Active services</a>
                <button type="button" class="btn secondary" data-open-modal="duplicatesModal">Duplicates</button>
            </div>
        </div>
        <datalist id="labCategoryList">
            <?php foreach ($categories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>" label="<?php echo htmlspecialchars(receptionist_lab_category_label($category)); ?>"></option><?php endforeach; ?>
        </datalist>
    </section>

    <form method="post" id="bulkDeleteForm" data-confirm-form="1" data-confirm-mode="delete" data-confirm-message="Delete selected services? Type DELETE to confirm." data-confirm-title="Delete selected services" data-confirm-button="Delete selected">
        <input type="hidden" name="receptionist_svc_action" value="bulk_delete_services">
    </form>

    <section class="lab-card">
        <h2>Search and filters</h2>
        <form class="lab-toolbar" method="get">
            <label class="field">Search
                <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name, category, included tests">
            </label>
            <label class="field">Category
                <select name="cat">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>" <?php echo $cat === $category ? 'selected' : ''; ?>><?php echo htmlspecialchars(receptionist_lab_category_label($category)); ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field">Type
                <select name="type">
                    <option value="">All types</option>
                    <option value="package" <?php echo $type === 'package' ? 'selected' : ''; ?>>Packages</option>
                    <option value="individual" <?php echo $type === 'individual' ? 'selected' : ''; ?>>Individual tests</option>
                </select>
            </label>
            <label class="field">Status
                <select name="status">
                    <option value="">All status</option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </label>
            <button type="submit" class="btn">Apply</button>
            <?php if ($q !== '' || $cat !== '' || $categoryQuery !== '' || $type !== '' || $status !== ''): ?><a class="btn secondary" href="receptionist_lab_services.php">Clear</a><?php endif; ?>
        </form>
    </section>

    <section class="lab-card service-section-card">
        <div class="section-head">
            <h2 class="section-title">Package deals</h2>
            <button type="button" class="btn warn bulkToggleBtn">Selected delete</button>
        </div>
        <div class="bulk-bar">
            <span class="bulk-bar-main">
                <span><strong class="bulk-count">0</strong> selected for delete</span>
                <small>Check services below, then continue only when you are sure.</small>
            </span>
            <button type="submit" form="bulkDeleteForm" class="btn warn bulkSubmitBtn" disabled>Continue delete</button>
        </div>
        <?php if (empty($groupedPackages)): ?>
            <p class="empty">No matching package rows.</p>
        <?php else: ?>
        <div class="service-list-scroll package-list-scroll" aria-label="Scrollable package deals list">
            <?php foreach ($groupedPackages as $category => $list): ?>
            <div class="service-group">
            <h3 class="service-group-title"><?php echo htmlspecialchars(receptionist_lab_category_label($category)); ?> <span class="pill neutral"><?php echo count($list); ?></span></h3>
            <div class="table-scroll">
                <table class="lab-table">
                    <thead><tr><th class="select-col"><input type="checkbox" class="js-select-visible" aria-label="Select all package deals"></th><th>Name</th><th>OPD</th><th>Home</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td class="select-col"><input type="checkbox" class="bulk-check" name="service_ids[]" value="<?php echo (int) $row['id']; ?>" form="bulkDeleteForm" aria-label="Select <?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>"></td>
                            <td class="service-name"><strong><?php echo htmlspecialchars($row['name']); ?></strong><?php if (!empty($row['included_tests'])): ?><small><?php echo htmlspecialchars($row['included_tests']); ?></small><?php endif; ?></td>
                            <td><?php echo receptionist_lab_price((string) $row['opd_price']); ?></td>
                            <td><?php echo receptionist_lab_price($row['home_service_price']); ?></td>
                            <td><span class="pill <?php echo !empty($row['is_active']) ? 'on' : 'off'; ?>"><?php echo !empty($row['is_active']) ? 'Active' : 'Inactive'; ?></span></td>
                            <td class="actions">
                                <button type="button" class="btn small secondary js-edit-service" data-service-id="<?php echo (int) $row['id']; ?>" data-service-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>" data-service-category="<?php echo htmlspecialchars($row['category'], ENT_QUOTES); ?>" data-service-description="<?php echo htmlspecialchars($row['description'] ?? '', ENT_QUOTES); ?>" data-service-included="<?php echo htmlspecialchars($row['included_tests'] ?? '', ENT_QUOTES); ?>" data-service-opd="<?php echo htmlspecialchars((string) ($row['opd_price'] ?? '0'), ENT_QUOTES); ?>" data-service-home="<?php echo htmlspecialchars($row['home_service_price'] !== null ? (string) $row['home_service_price'] : '', ENT_QUOTES); ?>" data-service-package="<?php echo !empty($row['is_package']) ? '1' : '0'; ?>" data-service-active="<?php echo !empty($row['is_active']) ? '1' : '0'; ?>">Edit</button>
                                <form method="post" <?php echo !empty($row['is_active']) ? 'data-confirm-form="1" data-confirm-mode="simple" data-confirm-message="Deactivate this service?" data-confirm-title="Deactivate service" data-confirm-button="Deactivate"' : ''; ?>>
                                    <input type="hidden" name="receptionist_svc_action" value="toggle_active">
                                    <input type="hidden" name="service_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn small secondary"><?php echo !empty($row['is_active']) ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                                <form method="post" data-confirm-form="1" data-confirm-mode="delete" data-confirm-message="Delete this service? Type DELETE to confirm." data-confirm-title="Delete service" data-confirm-button="Delete">
                                    <input type="hidden" name="receptionist_svc_action" value="delete_service">
                                    <input type="hidden" name="service_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn small warn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="lab-card service-section-card">
        <div class="section-head">
            <h2 class="section-title">Individual laboratory tests</h2>
            <button type="button" class="btn warn bulkToggleBtn">Selected delete</button>
        </div>
        <div class="bulk-bar">
            <span class="bulk-bar-main">
                <span><strong class="bulk-count">0</strong> selected for delete</span>
                <small>Use this only for services that should be removed from the catalog.</small>
            </span>
            <button type="submit" form="bulkDeleteForm" class="btn warn bulkSubmitBtn" disabled>Continue delete</button>
        </div>
        <?php if (empty($groupedIndividuals)): ?>
            <p class="empty">No matching individual test rows.</p>
        <?php else: ?>
        <div class="service-list-scroll individual-list-scroll" aria-label="Scrollable individual laboratory tests list">
            <?php foreach ($groupedIndividuals as $category => $list): ?>
            <div class="service-group">
            <h3 class="service-group-title"><?php echo htmlspecialchars(receptionist_lab_category_label($category)); ?> <span class="pill neutral"><?php echo count($list); ?></span></h3>
            <div class="table-scroll">
                <table class="lab-table">
                    <thead><tr><th class="select-col"><input type="checkbox" class="js-select-visible" aria-label="Select all individual tests"></th><th>Name</th><th>OPD</th><th>Home</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($list as $row): ?>
                        <tr>
                            <td class="select-col"><input type="checkbox" class="bulk-check" name="service_ids[]" value="<?php echo (int) $row['id']; ?>" form="bulkDeleteForm" aria-label="Select <?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>"></td>
                            <td class="service-name"><strong><?php echo htmlspecialchars($row['name']); ?></strong><?php if (!empty($row['included_tests'])): ?><small><?php echo htmlspecialchars($row['included_tests']); ?></small><?php endif; ?></td>
                            <td><?php echo receptionist_lab_price((string) $row['opd_price']); ?></td>
                            <td><?php echo receptionist_lab_price($row['home_service_price']); ?></td>
                            <td><span class="pill <?php echo !empty($row['is_active']) ? 'on' : 'off'; ?>"><?php echo !empty($row['is_active']) ? 'Active' : 'Inactive'; ?></span></td>
                            <td class="actions">
                                <button type="button" class="btn small secondary js-edit-service" data-service-id="<?php echo (int) $row['id']; ?>" data-service-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>" data-service-category="<?php echo htmlspecialchars($row['category'], ENT_QUOTES); ?>" data-service-description="<?php echo htmlspecialchars($row['description'] ?? '', ENT_QUOTES); ?>" data-service-included="<?php echo htmlspecialchars($row['included_tests'] ?? '', ENT_QUOTES); ?>" data-service-opd="<?php echo htmlspecialchars((string) ($row['opd_price'] ?? '0'), ENT_QUOTES); ?>" data-service-home="<?php echo htmlspecialchars($row['home_service_price'] !== null ? (string) $row['home_service_price'] : '', ENT_QUOTES); ?>" data-service-package="<?php echo !empty($row['is_package']) ? '1' : '0'; ?>" data-service-active="<?php echo !empty($row['is_active']) ? '1' : '0'; ?>">Edit</button>
                                <form method="post" <?php echo !empty($row['is_active']) ? 'data-confirm-form="1" data-confirm-mode="simple" data-confirm-message="Deactivate this service?" data-confirm-title="Deactivate service" data-confirm-button="Deactivate"' : ''; ?>>
                                    <input type="hidden" name="receptionist_svc_action" value="toggle_active">
                                    <input type="hidden" name="service_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn small secondary"><?php echo !empty($row['is_active']) ? 'Deactivate' : 'Activate'; ?></button>
                                </form>
                                <form method="post" data-confirm-form="1" data-confirm-mode="delete" data-confirm-message="Delete this service? Type DELETE to confirm." data-confirm-title="Delete service" data-confirm-button="Delete">
                                    <input type="hidden" name="receptionist_svc_action" value="delete_service">
                                    <input type="hidden" name="service_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" class="btn small warn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section class="print-price-list" aria-label="Printable laboratory price list">
        <div class="print-header">
            <img src="globalife.png" alt="Globalife logo" class="print-logo">
            <div>
                <h1>Globalife Medical Laboratory &amp; Polyclinic</h1>
                <p>Laboratory services price list</p>
                <p>Printed: <?php echo date('M d, Y'); ?></p>
            </div>
        </div>
        <?php if (empty($printGroupedPackages) && empty($printGroupedIndividuals)): ?><p>No active laboratory services available.</p><?php endif; ?>
        <?php if (!empty($printGroupedPackages)): ?>
            <h2 class="print-section-title">Package deals</h2>
            <?php foreach ($printGroupedPackages as $category => $list): ?>
                <h3 class="print-section-title"><?php echo htmlspecialchars(receptionist_lab_category_label($category)); ?></h3>
                <table class="print-table"><thead><tr><th>Service</th><th>Included tests</th><th>OPD price</th><th>Home service</th></tr></thead><tbody>
                <?php foreach ($list as $row): ?><tr><td><?php echo htmlspecialchars($row['name']); ?></td><td><?php echo htmlspecialchars($row['included_tests'] ?: '-'); ?></td><td><?php echo receptionist_lab_price((string) $row['opd_price']); ?></td><td><?php echo receptionist_lab_price($row['home_service_price']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($printGroupedIndividuals)): ?>
            <h2 class="print-section-title">Individual laboratory tests</h2>
            <?php foreach ($printGroupedIndividuals as $category => $list): ?>
                <h3 class="print-section-title"><?php echo htmlspecialchars(receptionist_lab_category_label($category)); ?></h3>
                <table class="print-table"><thead><tr><th>Service</th><th>Included tests</th><th>OPD price</th><th>Home service</th></tr></thead><tbody>
                <?php foreach ($list as $row): ?><tr><td><?php echo htmlspecialchars($row['name']); ?></td><td><?php echo htmlspecialchars($row['included_tests'] ?: '-'); ?></td><td><?php echo receptionist_lab_price((string) $row['opd_price']); ?></td><td><?php echo receptionist_lab_price($row['home_service_price']); ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endforeach; ?>
        <?php endif; ?>
        <p class="print-note">Prices may change after staff verification. Home service price is shown only when available.</p>
    </section>

    <div class="modal-overlay" id="duplicatesModal" role="dialog" aria-modal="true" aria-labelledby="duplicatesTitle" aria-hidden="true">
        <div class="modal-box">
            <div class="modal-head"><div><h2 id="duplicatesTitle">Duplicate services</h2><p style="margin:6px 0 0;color:#60727d;">Repeated name/category entries. Edit or delete them here.</p></div><button type="button" class="icon-close" data-close-modal="duplicatesModal" aria-label="Close duplicates dialog">X</button></div>
            <div class="modal-body">
                <?php if ($hasDuplicateGroups): ?>
                    <div class="dup-list">
                        <?php foreach ($duplicateGroups as $dupGroup): ?>
                            <?php $groupLabel = receptionist_lab_category_label($dupGroup[0]['category'] ?? '') . ' - ' . trim((string) ($dupGroup[0]['name'] ?? '')); ?>
                            <div class="dup-group">
                                <h3><?php echo htmlspecialchars($groupLabel !== ' - ' ? $groupLabel : 'Unnamed service'); ?> <span class="pill neutral"><?php echo count($dupGroup); ?></span></h3>
                                <?php foreach ($dupGroup as $row): ?>
                                    <div class="dup-item">
                                        <div class="dup-meta"><strong><?php echo htmlspecialchars($row['name']); ?></strong><small>Category: <?php echo htmlspecialchars(receptionist_lab_category_label($row['category'])); ?> | OPD: <?php echo receptionist_lab_price((string) $row['opd_price']); ?> | Status: <?php echo !empty($row['is_active']) ? 'Active' : 'Inactive'; ?></small></div>
                                        <div class="dup-actions">
                                            <button type="button" class="btn small secondary js-edit-service" data-service-id="<?php echo (int) $row['id']; ?>" data-service-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>" data-service-category="<?php echo htmlspecialchars($row['category'], ENT_QUOTES); ?>" data-service-description="<?php echo htmlspecialchars($row['description'] ?? '', ENT_QUOTES); ?>" data-service-included="<?php echo htmlspecialchars($row['included_tests'] ?? '', ENT_QUOTES); ?>" data-service-opd="<?php echo htmlspecialchars((string) ($row['opd_price'] ?? '0'), ENT_QUOTES); ?>" data-service-home="<?php echo htmlspecialchars($row['home_service_price'] !== null ? (string) $row['home_service_price'] : '', ENT_QUOTES); ?>" data-service-package="<?php echo !empty($row['is_package']) ? '1' : '0'; ?>" data-service-active="<?php echo !empty($row['is_active']) ? '1' : '0'; ?>">Edit</button>
                                            <form method="post" data-confirm-form="1" data-confirm-mode="delete" data-confirm-message="Delete this service? Type DELETE to confirm." data-confirm-title="Delete service" data-confirm-button="Delete"><input type="hidden" name="receptionist_svc_action" value="delete_service"><input type="hidden" name="service_id" value="<?php echo (int) $row['id']; ?>"><button type="submit" class="btn small warn">Delete</button></form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="dup-empty">No duplicate services found right now.</p>
                <?php endif; ?>
            </div>
            <div class="modal-actions"><button type="button" class="btn secondary" data-close-modal="duplicatesModal">Close</button></div>
        </div>
    </div>

    <div class="modal-overlay<?php echo $showAddModal ? ' active' : ''; ?>" id="addServiceModal" role="dialog" aria-modal="true" aria-labelledby="addServiceTitle" aria-hidden="<?php echo $showAddModal ? 'false' : 'true'; ?>">
        <div class="modal-box">
            <div class="modal-head"><div><h2 id="addServiceTitle">Add laboratory service</h2><p style="margin:6px 0 0;color:#60727d;">Create a new test or package for the clinic catalog and patient booking.</p></div><button type="button" class="icon-close" data-close-modal="addServiceModal" aria-label="Close add dialog">X</button></div>
            <form method="post"><input type="hidden" name="receptionist_svc_action" value="add_service">
                <div class="modal-body">
                    <div class="form-grid">
                        <label class="field">Service name<input type="text" name="name" required placeholder="Example: CBC, Urinalysis, Chem 5"></label>
                        <label class="field">Category<input type="text" name="category" required list="labCategoryList" placeholder="Example: HEMATOLOGY"></label>
                        <label class="field full">Description<textarea name="description" placeholder="Optional notes for staff"></textarea></label>
                        <label class="field full">Included tests<textarea name="included_tests" placeholder="For packages: CBC, UA, X-Ray, etc."></textarea></label>
                        <label class="field">OPD price<input type="number" step="0.01" min="0" name="opd_price" required value="0"></label>
                        <label class="field">Home service price<input type="number" step="0.01" min="0" name="home_service_price" placeholder="Leave blank if unavailable"></label>
                    </div>
                    <div class="checks" style="margin-top:16px;"><label><input type="checkbox" name="is_package"> Package deal</label><label><input type="checkbox" name="is_active" checked> Active in booking</label></div>
                </div>
                <div class="modal-actions"><button type="button" class="btn secondary" data-close-modal="addServiceModal">Cancel</button><button type="submit" class="btn">Add service</button></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay<?php echo $openEditModal ? ' active' : ''; ?>" id="editServiceModal" role="dialog" aria-modal="true" aria-labelledby="editServiceTitle" aria-hidden="<?php echo $openEditModal ? 'false' : 'true'; ?>">
        <div class="modal-box">
            <div class="modal-head"><div><h2 id="editServiceTitle">Edit laboratory service</h2><p style="margin:6px 0 0;color:#60727d;">Update price, category, package type, or availability.</p></div><button type="button" class="icon-close" data-close-modal="editServiceModal" aria-label="Close edit dialog">X</button></div>
            <form method="post" id="editServiceForm"><input type="hidden" name="receptionist_svc_action" value="save_service"><input type="hidden" name="service_id" id="edit_service_id" value="<?php echo (int) ($editRow['id'] ?? 0); ?>">
                <div class="modal-body">
                    <div class="form-grid">
                        <label class="field">Service name<input type="text" name="name" id="edit_service_name" required value="<?php echo htmlspecialchars($editRow['name'] ?? ''); ?>" placeholder="Example: CBC, Urinalysis, Chem 5"></label>
                        <label class="field">Category<input type="text" name="category" id="edit_service_category" required list="labCategoryList" value="<?php echo htmlspecialchars($editRow['category'] ?? ''); ?>" placeholder="Example: HEMATOLOGY"></label>
                        <label class="field full">Description<textarea name="description" id="edit_service_description" placeholder="Optional notes for staff"><?php echo htmlspecialchars($editRow['description'] ?? ''); ?></textarea></label>
                        <label class="field full">Included tests<textarea name="included_tests" id="edit_service_included" placeholder="For packages: CBC, UA, X-Ray, etc."><?php echo htmlspecialchars($editRow['included_tests'] ?? ''); ?></textarea></label>
                        <label class="field">OPD price<input type="number" step="0.01" min="0" name="opd_price" id="edit_service_opd" required value="<?php echo htmlspecialchars((string) ($editRow['opd_price'] ?? '0')); ?>"></label>
                        <label class="field">Home service price<input type="number" step="0.01" min="0" name="home_service_price" id="edit_service_home" value="<?php echo isset($editRow['home_service_price']) && $editRow['home_service_price'] !== null ? htmlspecialchars((string) $editRow['home_service_price']) : ''; ?>" placeholder="Leave blank if unavailable"></label>
                    </div>
                    <div class="checks" style="margin-top:16px;"><label><input type="checkbox" name="is_package" id="edit_service_package" <?php echo !empty($editRow['is_package']) ? 'checked' : ''; ?>> Package deal</label><label><input type="checkbox" name="is_active" id="edit_service_active" <?php echo !isset($editRow['is_active']) || !empty($editRow['is_active']) ? 'checked' : ''; ?>> Active in booking</label></div>
                </div>
                <div class="modal-actions"><button type="button" class="btn secondary" data-close-modal="editServiceModal">Cancel</button><button type="submit" class="btn">Save changes</button></div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="confirmActionModal" role="dialog" aria-modal="true" aria-labelledby="confirmActionTitle" aria-hidden="true">
        <div class="confirm-box"><h2 id="confirmActionTitle">Are you sure?</h2><p id="confirmActionMessage">This action needs confirmation.</p><input type="text" class="confirm-input" id="confirmDeleteInput" placeholder="Type DELETE to continue" autocomplete="off" spellcheck="false"><div class="confirm-actions"><button type="button" class="btn secondary" data-close-modal="confirmActionModal">Cancel</button><button type="button" class="btn warn" id="confirmActionButton">Continue</button></div></div>
    </div>

    <div class="modal-overlay<?php echo $showSuccessModal ? ' active' : ''; ?>" id="successActionModal" role="dialog" aria-modal="true" aria-labelledby="successActionTitle" aria-hidden="<?php echo $showSuccessModal ? 'false' : 'true'; ?>">
        <div class="success-box"><h2 id="successActionTitle">Saved</h2><p id="successActionMessage"><?php echo htmlspecialchars($message ?: 'Done.'); ?></p><button type="button" class="btn" data-close-modal="successActionModal">OK</button></div>
    </div>
</main>
<script>
(function () {
    const addModal = document.getElementById('addServiceModal');
    const editModal = document.getElementById('editServiceModal');
    const duplicatesModal = document.getElementById('duplicatesModal');
    const confirmModal = document.getElementById('confirmActionModal');
    const successModal = document.getElementById('successActionModal');
    const confirmTitle = document.getElementById('confirmActionTitle');
    const confirmMessage = document.getElementById('confirmActionMessage');
    const confirmButton = document.getElementById('confirmActionButton');
    const confirmDeleteInput = document.getElementById('confirmDeleteInput');
    const bulkSelectedCounts = Array.from(document.querySelectorAll('.bulk-count'));
    const bulkSubmitBtns = Array.from(document.querySelectorAll('.bulkSubmitBtn'));
    const bulkToggleBtns = Array.from(document.querySelectorAll('.bulkToggleBtn'));
    const bulkDeleteForm = document.getElementById('bulkDeleteForm');
    const closeTargets = document.querySelectorAll('[data-close-modal]');
    let pendingForm = null;
    let pendingMode = 'simple';
    let bulkMode = false;

    function updateBulkState() {
        const checks = Array.from(document.querySelectorAll('.bulk-check'));
        const selected = checks.filter(function (check) { return check.checked; }).length;
        bulkSelectedCounts.forEach(function (counter) { counter.textContent = selected; });
        bulkSubmitBtns.forEach(function (btn) { btn.disabled = selected === 0; });
        bulkToggleBtns.forEach(function (btn) { btn.textContent = bulkMode ? 'Cancel delete' : 'Selected delete'; });
        document.querySelectorAll('.js-select-visible').forEach(function (master) {
            const table = master.closest('table');
            const tableChecks = table ? Array.from(table.querySelectorAll('.bulk-check')) : [];
            const tableSelected = tableChecks.filter(function (check) { return check.checked; }).length;
            master.checked = tableChecks.length > 0 && tableSelected === tableChecks.length;
            master.indeterminate = tableSelected > 0 && tableSelected < tableChecks.length;
        });
    }

    function setBulkMode(nextMode) {
        bulkMode = !!nextMode;
        document.body.classList.toggle('bulk-mode', bulkMode);
        updateBulkState();
    }

    function cancelBulkDelete() {
        document.querySelectorAll('.bulk-check').forEach(function (check) { check.checked = false; });
        document.querySelectorAll('.js-select-visible').forEach(function (master) {
            master.checked = false;
            master.indeterminate = false;
        });
        setBulkMode(false);
        if (confirmModal && confirmModal.classList.contains('active')) {
            closeModal(confirmModal);
            pendingForm = null;
            pendingMode = 'simple';
            if (confirmDeleteInput) {
                confirmDeleteInput.value = '';
                confirmDeleteInput.classList.remove('active');
            }
            if (confirmButton) confirmButton.disabled = false;
        }
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        if (![addModal, editModal, duplicatesModal, confirmModal, successModal].some(function (m) { return m && m.classList.contains('active'); })) {
            document.body.style.overflow = '';
        }
    }

    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () { openModal(document.getElementById(btn.getAttribute('data-open-modal'))); });
    });

    bulkToggleBtns.forEach(function (bulkToggleBtn) {
        bulkToggleBtn.addEventListener('click', function () {
            if (!bulkMode) {
                setBulkMode(true);
                const firstCheck = document.querySelector('.bulk-check:not(:disabled)');
                if (firstCheck) firstCheck.focus();
                return;
            }
            cancelBulkDelete();
        });
    });

    document.querySelectorAll('.bulk-check').forEach(function (check) { check.addEventListener('change', updateBulkState); });
    document.querySelectorAll('.js-select-visible').forEach(function (master) {
        master.addEventListener('change', function () {
            const table = master.closest('table');
            if (!table) return;
            table.querySelectorAll('.bulk-check').forEach(function (check) { check.checked = master.checked; });
            updateBulkState();
        });
    });

    document.querySelectorAll('.js-edit-service').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('edit_service_id').value = btn.getAttribute('data-service-id') || '0';
            document.getElementById('edit_service_name').value = btn.getAttribute('data-service-name') || '';
            document.getElementById('edit_service_category').value = btn.getAttribute('data-service-category') || '';
            document.getElementById('edit_service_description').value = btn.getAttribute('data-service-description') || '';
            document.getElementById('edit_service_included').value = btn.getAttribute('data-service-included') || '';
            document.getElementById('edit_service_opd').value = btn.getAttribute('data-service-opd') || '0';
            document.getElementById('edit_service_home').value = btn.getAttribute('data-service-home') || '';
            document.getElementById('edit_service_package').checked = (btn.getAttribute('data-service-package') || '0') === '1';
            document.getElementById('edit_service_active').checked = (btn.getAttribute('data-service-active') || '0') === '1';
            openModal(editModal);
        });
    });

    closeTargets.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const target = document.getElementById(btn.getAttribute('data-close-modal'));
            closeModal(target);
            if (btn.getAttribute('data-close-modal') === 'confirmActionModal') {
                pendingForm = null;
                pendingMode = 'simple';
                if (confirmDeleteInput) {
                    confirmDeleteInput.value = '';
                    confirmDeleteInput.classList.remove('active');
                }
                confirmButton.disabled = false;
            }
        });
    });

    [addModal, editModal, duplicatesModal, confirmModal].forEach(function (modal) {
        if (!modal) return;
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeModal(modal);
                if (modal === confirmModal) pendingForm = null;
            }
        });
    });

    document.querySelectorAll('form[data-confirm-form="1"]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            const message = form.getAttribute('data-confirm-message') || '';
            if (!message) return;
            if (form === bulkDeleteForm && Array.from(document.querySelectorAll('.bulk-check')).filter(function (check) { return check.checked; }).length === 0) {
                e.preventDefault();
                setBulkMode(true);
                return;
            }
            e.preventDefault();
            pendingForm = form;
            pendingMode = form.getAttribute('data-confirm-mode') || 'simple';
            confirmTitle.textContent = form.getAttribute('data-confirm-title') || 'Are you sure?';
            confirmMessage.textContent = message;
            confirmButton.textContent = form.getAttribute('data-confirm-button') || 'Continue';
            confirmButton.className = 'btn warn';
            if (confirmDeleteInput) {
                confirmDeleteInput.value = '';
                confirmDeleteInput.classList.toggle('active', pendingMode === 'delete');
            }
            confirmButton.disabled = pendingMode === 'delete';
            openModal(confirmModal);
        });
    });

    confirmButton.addEventListener('click', function () {
        if (pendingMode === 'delete' && confirmDeleteInput && confirmDeleteInput.value.trim().toUpperCase() !== 'DELETE') {
            confirmDeleteInput.focus();
            return;
        }
        if (pendingForm) pendingForm.submit();
    });

    if (confirmDeleteInput) {
        confirmDeleteInput.addEventListener('input', function () {
            confirmButton.disabled = pendingMode === 'delete' && confirmDeleteInput.value.trim().toUpperCase() !== 'DELETE';
        });
    }

    updateBulkState();
    <?php if ($openEditModal): ?>openModal(editModal);<?php endif; ?>
    <?php if ($showAddModal): ?>openModal(addModal);<?php endif; ?>
    <?php if ($hasDuplicateGroups && isset($_GET['duplicates']) && $_GET['duplicates'] === '1'): ?>openModal(duplicatesModal);<?php endif; ?>
    <?php if ($showSuccessModal): ?>openModal(successModal);<?php endif; ?>
})();
</script>
<?php include 'includes/footer.php'; ?>

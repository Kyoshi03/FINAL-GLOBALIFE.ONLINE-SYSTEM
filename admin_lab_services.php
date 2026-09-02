<?php
require_once 'includes/session.php';
checkRole('admin');

require_once 'config/database.php';
require_once __DIR__ . '/includes/lab_services_seed_data.php';

$pageTitle = 'Laboratory services | Globalife';
$conn = getDBConnection();
initLabBookingSchema($conn);

$message = $_SESSION['admin_lab_service_message'] ?? '';
$error = $_SESSION['admin_lab_service_error'] ?? '';
$showAddModal = !empty($_SESSION['admin_lab_service_add_open']);
unset($_SESSION['admin_lab_service_message'], $_SESSION['admin_lab_service_error'], $_SESSION['admin_lab_service_add_open']);

function admin_lab_redirect(array $params = []): void {
    $query = $params ? '?' . http_build_query($params) : '';
    header('Location: admin_lab_services.php' . $query);
    exit;
}

function admin_lab_price($value): string {
    if ($value === null || $value === '') {
        return 'None';
    }
    return '&#8369;' . number_format((float) $value, 2);
}

function admin_lab_category_label(?string $category): string {
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
    $action = $_POST['admin_svc_action'] ?? '';

    if ($action === 'import_catalog') {
        $imported = lab_insert_catalog_rows($conn, lab_catalog_seed_rows());
        lab_sync_categories_from_services($conn);
        $_SESSION['admin_lab_service_message'] = "Imported {$imported} missing catalog row(s).";
        admin_lab_redirect();
    }

    if ($action === 'toggle_active') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE lab_services SET is_active = 1 - is_active WHERE id = ?');
            $stmt->bind_param('i', $id);
            $_SESSION['admin_lab_service_message'] = $stmt->execute() ? 'Service status updated.' : 'Could not update service status.';
            $stmt->close();
            lab_sync_categories_from_services($conn);
        }
        admin_lab_redirect();
    }

    if ($action === 'delete_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('DELETE FROM lab_services WHERE id = ?');
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                $_SESSION['admin_lab_service_message'] = 'Service deleted.';
            } else {
                $_SESSION['admin_lab_service_error'] = 'Could not delete this service. If it is already used in an appointment, set it inactive instead.';
            }
            $stmt->close();
            lab_sync_categories_from_services($conn);
        }
        admin_lab_redirect();
    }

    if ($action === 'add_service' || $action === 'save_service') {
        $id = (int) ($_POST['service_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $included = trim((string) ($_POST['included_tests'] ?? ''));
        $opd = max(0, (float) ($_POST['opd_price'] ?? 0));
        $homeRaw = trim((string) ($_POST['home_service_price'] ?? ''));
        $home = $homeRaw === '' ? null : max(0, (float) $homeRaw);
        $isPackage = isset($_POST['is_package']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $category === '') {
            $_SESSION['admin_lab_service_error'] = 'Name and category are required.';
            if ($action === 'add_service') {
                $_SESSION['admin_lab_service_add_open'] = 1;
            }
            admin_lab_redirect($id > 0 ? ['edit' => $id] : []);
        }

        if ($action === 'add_service') {
            if ($home === null) {
                $stmt = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)');
                $stmt->bind_param('ssssdii', $name, $category, $description, $included, $opd, $isPackage, $isActive);
            } else {
                $stmt = $conn->prepare('INSERT INTO lab_services (name, category, description, included_tests, opd_price, home_service_price, is_package, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('ssssddii', $name, $category, $description, $included, $opd, $home, $isPackage, $isActive);
            }
            $_SESSION['admin_lab_service_message'] = $stmt->execute() ? 'Service added.' : 'Could not add service.';
            $stmt->close();
        } else {
            if ($id <= 0) {
                $_SESSION['admin_lab_service_error'] = 'Invalid service.';
                admin_lab_redirect();
            }

            if ($home === null) {
                $stmt = $conn->prepare('UPDATE lab_services SET name=?, category=?, description=?, included_tests=?, opd_price=?, home_service_price=NULL, is_package=?, is_active=? WHERE id=?');
                $stmt->bind_param('ssssdiii', $name, $category, $description, $included, $opd, $isPackage, $isActive, $id);
            } else {
                $stmt = $conn->prepare('UPDATE lab_services SET name=?, category=?, description=?, included_tests=?, opd_price=?, home_service_price=?, is_package=?, is_active=? WHERE id=?');
                $stmt->bind_param('ssssddiii', $name, $category, $description, $included, $opd, $home, $isPackage, $isActive, $id);
            }
            $_SESSION['admin_lab_service_message'] = $stmt->execute() ? 'Service updated.' : 'Could not update service.';
            $stmt->close();
        }

        lab_sync_categories_from_services($conn);
        admin_lab_redirect();
    }
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $stmt = $conn->prepare('SELECT * FROM lab_services WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$editRow) {
        $error = 'Selected service was not found.';
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$cat = trim((string) ($_GET['cat'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

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

$rows = array_values(array_filter($rows, function ($row) use ($q, $cat, $type, $status) {
    if ($cat !== '' && ($row['category'] ?? '') !== $cat) {
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
    .field { display:grid; gap:5px; color:#60727d; font-size:.82rem; font-weight:800; }
    .field input, .field select, .field textarea { width:100%; box-sizing:border-box; border:1px solid #cfe0eb; border-radius:8px; padding:10px 11px; font:inherit; color:#1f343d; background:#fff; }
    .field textarea { min-height:78px; resize:vertical; }
    .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .field.full { grid-column:1/-1; }
    .checks { display:flex; gap:16px; flex-wrap:wrap; margin:14px 0; color:#435761; font-weight:800; }
    .btn { box-sizing:border-box; border:none; border-radius:8px; padding:10px 14px; min-height:40px; min-width:116px; display:inline-flex; align-items:center; justify-content:center; gap:7px; font:inherit; font-size:.9rem; line-height:1.15; font-weight:900; text-align:center; white-space:nowrap; cursor:pointer; text-decoration:none; background:#0f7cc2; color:#fff; }
    .btn.secondary { background:#eef7ff; color:#0b4f80; border:1px solid #d4e6f5; }
    .btn.warn { background:#fff0f0; color:#9d1c2c; border:1px solid #ffd0d5; }
    .btn.small { min-width:94px; min-height:36px; padding:8px 10px; font-size:.82rem; }
    .btn.primary-action { min-width:210px; }
    .lab-toolbar .btn { min-width:108px; }
    .tool-actions .btn, .modal-actions .btn { min-height:42px; }
    .btn:disabled { opacity:.48; cursor:not-allowed; }
    .msg-ok, .msg-err { border-radius:8px; padding:12px 14px; margin:14px 0 0; font-weight:800; }
    .msg-ok { background:#e7f7ed; color:#17643a; border:1px solid #bfe6ce; }
    .msg-err { background:#fff0f0; color:#9d1c2c; border:1px solid #ffd0d5; }
    .section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin:20px 0 12px; }
    .section-head .section-title { margin:0; color:#073b4c; font-size:1.16rem; font-weight:900; display:flex; align-items:center; gap:10px; }
    .lab-table { width:100%; border-collapse:collapse; }
    .lab-table th, .lab-table td { padding:14px 12px; border-bottom:1px solid #edf2f6; text-align:left; vertical-align:top; }
    .lab-table th { background:#eaf3f8; color:#073b4c; font-size:.83rem; font-weight:900; text-transform:uppercase; }
    .service-list-scroll { max-height:520px; overflow:auto; padding-right:8px; scrollbar-width:thin; scrollbar-color:#9fc5d8 #edf5f8; }
    .service-list-scroll::-webkit-scrollbar { width:9px; height:9px; }
    .service-list-scroll::-webkit-scrollbar-thumb { background:#9fc5d8; border-radius:999px; }
    .service-list-scroll::-webkit-scrollbar-track { background:#edf5f8; border-radius:999px; }
    .service-group { border:1px solid #dce9f1; border-radius:8px; background:#fff; overflow:hidden; margin-bottom:14px; }
    .service-group:last-child { margin-bottom:0; }
    .service-group-title { position:sticky; top:0; z-index:5; margin:0; padding:12px 14px; border-bottom:1px solid #e3edf3; background:#f7fbfd; color:#073b4c; font-size:1rem; font-weight:900; display:flex; align-items:center; justify-content:space-between; gap:10px; }
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
    @media (max-width:900px) { .lab-hero, .lab-toolbar { flex-direction:column; align-items:stretch; } .tool-row { grid-template-columns:1fr; } .tool-actions { justify-content:flex-start; } .tool-actions .btn { flex:1 1 180px; } .lab-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .form-grid { grid-template-columns:1fr; } .lab-table { min-width:820px; } }
    @media (max-width:560px) { .lab-wrap { padding:0 12px 36px; } .lab-grid { grid-template-columns:1fr; } .lab-hero, .lab-card { padding:16px; } .btn { width:100%; } }
';

include 'includes/header.php';
?>
<main class="lab-wrap">
    <section class="lab-hero">
        <div>
            <h1>Laboratory services manager</h1>
            <p>Add packages, update prices, hide unavailable tests, and keep the service catalog ready for bookings.</p>
        </div>
        <form method="post" onsubmit="return confirm('Import missing default catalog rows?')">
            <input type="hidden" name="admin_svc_action" value="import_catalog">
            <button type="submit" class="btn secondary">Import catalog</button>
        </form>
    </section>

    <?php if ($message): ?><div class="msg-ok"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg-err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <section class="lab-grid" aria-label="Lab service summary">
        <div class="lab-stat"><span>Total services</span><strong><?php echo count($allRows); ?></strong></div>
        <div class="lab-stat"><span>Active</span><strong><?php echo $activeCount; ?></strong></div>
        <div class="lab-stat"><span>Inactive</span><strong><?php echo $inactiveCount; ?></strong></div>
        <div class="lab-stat"><span>Packages</span><strong><?php echo $packageCount; ?></strong></div>
        <div class="lab-stat"><span>Individual</span><strong><?php echo $individualCount; ?></strong></div>
    </section>

    <section class="lab-card">
        <h2>Catalog tools</h2>
        <div class="tool-row">
            <p>Create or update laboratory services. Package and individual lists below are scrollable for easier admin review.</p>
            <div class="tool-actions">
                <button type="button" class="btn primary-action" data-open-modal="addServiceModal">Add laboratory service</button>
                <a class="btn secondary" href="admin_lab_services.php?status=active">Active services</a>
            </div>
        </div>
        <datalist id="labCategoryList">
            <?php foreach ($categories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>" label="<?php echo htmlspecialchars(admin_lab_category_label($category)); ?>"></option><?php endforeach; ?>
        </datalist>
    </section>

    <section class="lab-card">
        <h2>Search and filters</h2>
        <form class="lab-toolbar" method="get">
            <label class="field">Search
                <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name, category, included tests">
            </label>
            <label class="field">Category
                <select name="cat">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?><option value="<?php echo htmlspecialchars($category); ?>" <?php echo $cat === $category ? 'selected' : ''; ?>><?php echo htmlspecialchars(admin_lab_category_label($category)); ?></option><?php endforeach; ?>
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
            <?php if ($q !== '' || $cat !== '' || $type !== '' || $status !== ''): ?><a class="btn secondary" href="admin_lab_services.php">Clear</a><?php endif; ?>
        </form>
    </section>

    <?php
    $renderTable = function (array $groups, string $emptyText): void {
        if (empty($groups)) {
            echo '<p class="empty">' . htmlspecialchars($emptyText) . '</p>';
            return;
        }
        echo '<div class="service-list-scroll">';
        foreach ($groups as $category => $list) {
            echo '<div class="service-group">';
            echo '<h3 class="service-group-title">' . htmlspecialchars(admin_lab_category_label($category)) . ' <span class="pill neutral">' . count($list) . '</span></h3>';
            echo '<div class="table-scroll"><table class="lab-table"><thead><tr><th>Name</th><th>OPD</th><th>Home</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            foreach ($list as $row) {
                $editData = ' data-service-id="' . (int) $row['id'] . '"'
                    . ' data-service-name="' . htmlspecialchars($row['name'], ENT_QUOTES) . '"'
                    . ' data-service-category="' . htmlspecialchars($row['category'], ENT_QUOTES) . '"'
                    . ' data-service-description="' . htmlspecialchars($row['description'] ?? '', ENT_QUOTES) . '"'
                    . ' data-service-included="' . htmlspecialchars($row['included_tests'] ?? '', ENT_QUOTES) . '"'
                    . ' data-service-opd="' . htmlspecialchars((string) ($row['opd_price'] ?? '0'), ENT_QUOTES) . '"'
                    . ' data-service-home="' . htmlspecialchars($row['home_service_price'] !== null ? (string) $row['home_service_price'] : '', ENT_QUOTES) . '"'
                    . ' data-service-package="' . (!empty($row['is_package']) ? '1' : '0') . '"'
                    . ' data-service-active="' . (!empty($row['is_active']) ? '1' : '0') . '"';
                echo '<tr>';
                echo '<td class="service-name"><strong>' . htmlspecialchars($row['name']) . '</strong>' . (!empty($row['included_tests']) ? '<small>' . htmlspecialchars($row['included_tests']) . '</small>' : '') . '</td>';
                echo '<td>' . admin_lab_price((string) $row['opd_price']) . '</td>';
                echo '<td>' . admin_lab_price($row['home_service_price']) . '</td>';
                echo '<td><span class="pill ' . (!empty($row['is_active']) ? 'on' : 'off') . '">' . (!empty($row['is_active']) ? 'Active' : 'Inactive') . '</span></td>';
                echo '<td class="actions">';
                echo '<button type="button" class="btn small secondary js-edit-service"' . $editData . '>Edit</button>';
                echo '<form method="post"><input type="hidden" name="admin_svc_action" value="toggle_active"><input type="hidden" name="service_id" value="' . (int) $row['id'] . '"><button type="submit" class="btn small secondary">' . (!empty($row['is_active']) ? 'Deactivate' : 'Activate') . '</button></form>';
                echo '<form method="post" onsubmit="return confirm(\'Delete this service?\')"><input type="hidden" name="admin_svc_action" value="delete_service"><input type="hidden" name="service_id" value="' . (int) $row['id'] . '"><button type="submit" class="btn small warn">Delete</button></form>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div></div>';
        }
        echo '</div>';
    };
    ?>

    <section class="lab-card service-section-card">
        <div class="section-head"><h2 class="section-title">Package deals</h2></div>
        <?php $renderTable($groupedPackages, 'No matching package rows.'); ?>
    </section>

    <section class="lab-card service-section-card">
        <div class="section-head"><h2 class="section-title">Individual laboratory tests</h2></div>
        <?php $renderTable($groupedIndividuals, 'No matching individual test rows.'); ?>
    </section>

    <div class="modal-overlay<?php echo $showAddModal ? ' active' : ''; ?>" id="addServiceModal" role="dialog" aria-modal="true" aria-labelledby="addServiceTitle" aria-hidden="<?php echo $showAddModal ? 'false' : 'true'; ?>">
        <div class="modal-box">
            <div class="modal-head"><div><h2 id="addServiceTitle">Add laboratory service</h2><p style="margin:6px 0 0;color:#60727d;">Create a new test or package for booking.</p></div><button type="button" class="icon-close" data-close-modal="addServiceModal" aria-label="Close add dialog">X</button></div>
            <form method="post"><input type="hidden" name="admin_svc_action" value="add_service">
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
            <form method="post" id="editServiceForm"><input type="hidden" name="admin_svc_action" value="save_service"><input type="hidden" name="service_id" id="edit_service_id" value="<?php echo (int) ($editRow['id'] ?? 0); ?>">
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
</main>
<script>
(function () {
    const addModal = document.getElementById('addServiceModal');
    const editModal = document.getElementById('editServiceModal');

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
        if (![addModal, editModal].some(function (m) { return m && m.classList.contains('active'); })) {
            document.body.style.overflow = '';
        }
    }

    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () { openModal(document.getElementById(btn.getAttribute('data-open-modal'))); });
    });

    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () { closeModal(document.getElementById(btn.getAttribute('data-close-modal'))); });
    });

    [addModal, editModal].forEach(function (modal) {
        if (!modal) return;
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal(modal);
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

    <?php if ($openEditModal): ?>openModal(editModal);<?php endif; ?>
    <?php if ($showAddModal): ?>openModal(addModal);<?php endif; ?>
})();
</script>
<?php include 'includes/footer.php'; ?>

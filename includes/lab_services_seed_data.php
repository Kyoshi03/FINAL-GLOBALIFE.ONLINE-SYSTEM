<?php
/**
 * Seed catalog row format:
 * [name, category, description, included_tests, opd_price, home_service_price|null, is_package]
 */
function lab_catalog_seed_rows(): array {
    $rows = [];

    $add = function (string $name, string $cat, float $opd, ?float $home, string $incl = '', string $desc = '', int $pkg = 0) use (&$rows): void {
        $rows[] = [$name, $cat, $desc, $incl, $opd, $home, $pkg];
    };

    // First picture: package deals only
    $add('DRUGTEST', '🧾 OPD PRE EMPLOYMENT', 300, null, 'Drug Test', '', 1);
    $add('ROUTINE', '🧾 OPD PRE EMPLOYMENT', 400, null, 'CBC, FA, UA, CXR, P.E', '', 1);
    $add('ROUTINE + DRUGTEST', '🧾 OPD PRE EMPLOYMENT', 550, null, 'CBC, FA, UA, CXR, P.E + Drug Test', '', 1);
    $add('ROUTINE + HEPA B(S)', '🧾 OPD PRE EMPLOYMENT', 500, null, 'CBC, FA, UA, CXR, P.E + HBsAg', '', 1);
    $add('ROUTINE + HEPA B(S) + DRUGTEST', '🧾 OPD PRE EMPLOYMENT', 650, null, 'CBC, FA, UA, CXR, P.E + HBsAg + Drug Test', '', 1);
    $add('ROUTINE + HEPA A&B + DRUGTEST', '🧾 OPD PRE EMPLOYMENT', 1100, null, 'CBC, FA, UA, CXR, P.E + Hepa A & B + Drug Test', '', 1);
    $add('ROUTINE + PREGTEST + DRUGTEST', '🧾 OPD PRE EMPLOYMENT', 650, null, 'CBC, FA, UA, CXR, P.E + Pregnancy Test + Drug Test', '', 1);
    $add('ROUTINE + HEPA B(S) + PREGTEST', '🧾 OPD PRE EMPLOYMENT', 600, null, 'CBC, FA, UA, CXR, P.E + HBsAg + Pregnancy Test', '', 1);
    $add('ROUTINE + HEPA B(S) + DRUGTEST + PREGTEST', '🧾 OPD PRE EMPLOYMENT', 750, null, 'CBC, FA, UA, CXR, P.E + HBsAg + Drug Test + Pregnancy Test', '', 1);
    $add('CIVIL SERVICE', '🧾 SANITARY PERMIT', 550, null, 'CBC, BT, UA, XRAY, DRUGTEST', '', 1);
    $add('SET A', '🧾 SANITARY PERMIT', 500, null, 'XRAY, UA, FA, ANTI HAV IGM (HEPA A)', '', 1);
    $add('SET B', '🧾 SANITARY PERMIT', 350, null, 'XRAY, UA, FA, HBSAG(S)', '', 1);
    $add('SET C', '🧾 SANITARY PERMIT', 250, null, 'XRAY, UA, FA', '', 1);
    $add('ROUTINE + ABO + DRUGTEST', '🧾 CVSU', 500, null, 'CBC, FA, UA, CXR, P.E + Blood Typing + Drug Test', '', 1);
    $add('ROUTINE + ABO', '🧾 CVSU', 400, null, 'CBC, FA, UA, CXR, P.E + Blood Typing', '', 1);
    $add('ROUTINE + ABO + HBSAG', '🧾 CVSU', 450, null, 'CBC, FA, UA, CXR, P.E + Blood Typing + HBsAg', '', 1);
    $add('ROUTINE + ABO + HBSAG + DRUGTEST', '🧾 CVSU', 550, null, 'CBC, FA, UA, CXR, P.E + Blood Typing + HBsAg + Drug Test', '', 1);

    // Second picture: individual tests
    $add('Complete Blood Count', '🩸 HEMATOLOGY', 120, 190);
    $add('Platelet Count', '🩸 HEMATOLOGY', 120, 190);
    $add('Hgb & Hct', '🩸 HEMATOLOGY', 100, 150);
    $add('Urinalysis', '🔬 CLINICAL MICROSCOPY', 50, 70);
    $add('Pregnancy Test', '🔬 CLINICAL MICROSCOPY', 150, 185);
    $add('SERUM PREGNANCY TEST', '🔬 CLINICAL MICROSCOPY', 250, 325);
    $add('FBS/RBS/PPBS', '🧪 BLOOD CHEMISTRY', 140, 180);
    $add('OGCT', '🧪 BLOOD CHEMISTRY', 300, 350);
    $add('OGTT', '🧪 BLOOD CHEMISTRY', 600, 700);
    $add('BUN', '🧪 BLOOD CHEMISTRY', 150, 200);
    $add('Creatinine', '🧪 BLOOD CHEMISTRY', 150, 200);
    $add('T3', '🧬 THYROID FUNCTION TEST', 500, 750);
    $add('T4', '🧬 THYROID FUNCTION TEST', 500, 750);
    $add('TSH', '🧬 THYROID FUNCTION TEST', 600, 880);
    $add('HBsAg (Screening)', '🦠 HEPATITIS', 180, 220);
    $add('Prolactin', '🧑‍⚕️ HORMONES', 700, 915);
    $add('CEA', '🎗️ TUMOR MARKERS', 1850, 2405);
    $add('VDRL/RPR', '🧫 SEROLOGY', 200, 250);
    $add('Gram Stain', '🧫 BACTERIOLOGY', 160, 200);
    $add('HIV Screening', '🧪 HIV TEST', 550, 685);
    $add('ECG', '🧾 OTHERS', 200, 230);
    $add('X-Ray (various)', '🧾 OTHERS', 400, 450);
    $add('Drug Test', '🧾 OTHERS', 300, 390);

    // Listed as individual catalog entries on second sheet
    $add('Chem 5 (FBS, BUN, CREA, BUA, TC)', '🧪 CHEMISTRY PACKAGES', 450, 635);
    $add('Chem 6 (FBS, BUN, CREA, BUA, TC, TG)', '🧪 CHEMISTRY PACKAGES', 550, 765);
    $add('CHEM 10 (chem 8 + OT + PT)', '🧪 CHEMISTRY PACKAGES', 850, 1170);
    $add('LIPID PROFILE (TC, TG, HDL, LDL)', '🧪 CHEMISTRY PACKAGES', 500, 685);
    $add('Buntis #1 (HBSAG, VDRL, CBC, BT, UA)', '🤰 BUNTIS PACKAGE', 1000, 1235);
    $add('Buntis #3 (HBSAG, VDRL, CBC, BT, UA)', '🤰 BUNTIS PACKAGE', 650, 845);

    return $rows;
}

/**
 * Keep official package display order.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function lab_order_package_services(array $rows): array {
    $order = [
        ['🧾 OPD PRE EMPLOYMENT', 'DRUGTEST'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE + DRUGTEST'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE + HEPA B(S)'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE + HEPA B(S) + DRUGTEST'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE + HEPA A&B + DRUGTEST'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE + PREGTEST + DRUGTEST'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE + HEPA B(S) + PREGTEST'],
        ['🧾 OPD PRE EMPLOYMENT', 'ROUTINE + HEPA B(S) + DRUGTEST + PREGTEST'],
        ['🧾 SANITARY PERMIT', 'CIVIL SERVICE'],
        ['🧾 SANITARY PERMIT', 'SET A'],
        ['🧾 SANITARY PERMIT', 'SET B'],
        ['🧾 SANITARY PERMIT', 'SET C'],
        ['🧾 CVSU', 'ROUTINE + ABO + DRUGTEST'],
        ['🧾 CVSU', 'ROUTINE + ABO'],
        ['🧾 CVSU', 'ROUTINE + ABO + HBSAG'],
        ['🧾 CVSU', 'ROUTINE + ABO + HBSAG + DRUGTEST'],
    ];
    $rank = [];
    foreach ($order as $i => $pair) {
        $rank[$pair[0] . "\0" . $pair[1]] = $i;
    }
    usort($rows, function ($a, $b) use ($rank) {
        $ka = ($a['category'] ?? '') . "\0" . ($a['name'] ?? '');
        $kb = ($b['category'] ?? '') . "\0" . ($b['name'] ?? '');
        $ra = $rank[$ka] ?? 10000;
        $rb = $rank[$kb] ?? 10000;
        if ($ra !== $rb) return $ra <=> $rb;
        return strcmp($ka, $kb);
    });
    return $rows;
}

/**
 * @param array<int,array<string,mixed>> $list
 * @return array<string,array<int,array<string,mixed>>>
 */
function lab_group_services_list(array $list): array {
    $order = lab_category_sort_order();
    $g = [];
    foreach ($list as $row) {
        $cat = (string) ($row['category'] ?? 'Other');
        $g[$cat][] = $row;
    }
    uksort($g, function ($a, $b) use ($order) {
        $ia = array_search($a, $order, true);
        $ib = array_search($b, $order, true);
        $ia = $ia === false ? 9999 : $ia;
        $ib = $ib === false ? 9999 : $ib;
        if ($ia !== $ib) return $ia <=> $ib;
        return strcmp($a, $b);
    });
    return $g;
}

function lab_booking_package_only_categories(): array {
    return ['🧾 OPD PRE EMPLOYMENT', '🧾 SANITARY PERMIT', '🧾 CVSU'];
}

function lab_booking_service_matches_type(array $row, string $type): bool {
    $pkg = (int) ($row['is_package'] ?? 0);
    $cat = (string) ($row['category'] ?? '');
    $sheet1 = lab_booking_package_only_categories();
    if ($type === 'package') {
        return $pkg === 1 && in_array($cat, $sheet1, true);
    }
    return $pkg === 0 && !in_array($cat, $sheet1, true);
}

function lab_category_sort_order(): array {
    return [
        '🩸 HEMATOLOGY',
        '🔬 CLINICAL MICROSCOPY',
        '🧪 BLOOD CHEMISTRY',
        '🧪 CHEMISTRY PACKAGES',
        '🤰 BUNTIS PACKAGE',
        '🧬 THYROID FUNCTION TEST',
        '🦠 HEPATITIS',
        '🧑‍⚕️ HORMONES',
        '🎗️ TUMOR MARKERS',
        '🧫 SEROLOGY',
        '🧫 BACTERIOLOGY',
        '🧪 HIV TEST',
        '🧾 OTHERS',
        '🧾 OPD PRE EMPLOYMENT',
        '🧾 SANITARY PERMIT',
        '🧾 CVSU',
    ];
}


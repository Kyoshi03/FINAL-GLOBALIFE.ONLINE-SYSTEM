<?php

function clinic_info_defaults(): array {
    return [
        'clinic_name' => 'Globalife Medical Laboratory & Polyclinic',
        'clinic_location' => '9012 Jasmin St. De Roman Subdivision Daang Amaya 1 Tanza, Cavite',
        'clinic_location_url' => 'https://www.bing.com/maps/default.aspx?v=2&pc=FACEBK&mid=8100&where1=9012%20Jasmin%20St.%20De%20Roman%20Subdivision%20Daang%20Amaya%201%20Tanza%2C%20Cavite&FORM=FBKPL1&mkt=en-GB&fbclid=IwcGRvZgVleHRuA2FlbQIxMABicmlkETEwYkdORERiZzF4aDlOb0Vjc3J0YwZhcHBfaWQQMjIyMDM5MTc4ODIwMDg5MgABHkupAnN_h1_CuRPTjClZcI6976911eFKgSovA9q9eBZRp4ADnYkqSUtrbNP6_aem_klG6ueK4CU9ub-hfljfWiQ',
        'clinic_facebook' => 'Globalife Medical Laboratory and Polyclinic - Tanza Main',
        'clinic_logo' => 'globalife.png',
    ];
}

function clinic_info_logo_web_path(array $clinicInfo): string {
    $logoPath = trim((string) ($clinicInfo['clinic_logo'] ?? ''));
    if ($logoPath === '') {
        return 'globalife.png';
    }

    $relativePath = ltrim(str_replace('\\', '/', $logoPath), '/');
    $filePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    return is_file($filePath) ? $relativePath : 'globalife.png';
}

function clinic_info_logo_file_path(array $clinicInfo): string {
    $webPath = clinic_info_logo_web_path($clinicInfo);
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $webPath);
}

function clinic_info_ensure_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS clinic_settings (
            setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function clinic_info_get(mysqli $conn): array {
    $info = clinic_info_defaults();
    clinic_info_ensure_table($conn);

    $result = $conn->query("SELECT setting_key, setting_value FROM clinic_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = (string) ($row['setting_key'] ?? '');
            if (array_key_exists($key, $info)) {
                $value = trim((string) ($row['setting_value'] ?? ''));
                if ($value !== '') {
                    $info[$key] = $value;
                }
            }
        }
    }

    return $info;
}

function clinic_info_save(mysqli $conn, array $info): void {
    clinic_info_ensure_table($conn);
    $defaults = clinic_info_defaults();
    $stmt = $conn->prepare(
        "INSERT INTO clinic_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    if (!$stmt) {
        throw new RuntimeException('Clinic information could not be saved.');
    }

    foreach ($defaults as $key => $defaultValue) {
        $value = trim((string) ($info[$key] ?? $defaultValue));
        if ($value === '') {
            $value = $defaultValue;
        }
        $stmt->bind_param('ss', $key, $value);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Clinic information could not be saved.');
        }
    }

    $stmt->close();
}

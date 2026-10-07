<?php

function appointment_capacity_settings_path(): string {
    return dirname(__DIR__) . '/storage/appointment_capacity.json';
}

function appointment_capacity_defaults(): array {
    return [
        'doctor_limit' => 30,
        'laboratory_limit' => 15,
        'ultrasound_limit' => 10,
    ];
}

function appointment_capacity_settings(): array {
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = appointment_capacity_defaults();
    $path = appointment_capacity_settings_path();
    if (is_file($path)) {
        $saved = json_decode((string) file_get_contents($path), true);
        if (is_array($saved)) {
            foreach ($settings as $key => $default) {
                if (array_key_exists($key, $saved)) {
                    $value = filter_var($saved[$key], FILTER_VALIDATE_INT);
                    if ($value !== false) {
                        $settings[$key] = max(1, min(999, (int) $value));
                    }
                }
            }
        }
    }

    return $settings;
}

function appointment_capacity_save(array $values): bool {
    $settings = appointment_capacity_defaults();
    foreach ($settings as $key => $default) {
        $value = filter_var($values[$key] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < 1 || $value > 999) {
            return false;
        }
        $settings[$key] = (int) $value;
    }

    $directory = dirname(appointment_capacity_settings_path());
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    return file_put_contents(
        appointment_capacity_settings_path(),
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    ) !== false;
}

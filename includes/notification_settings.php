<?php

function clinic_notification_settings_path(): string {
    return dirname(__DIR__) . '/storage/notification_settings.json';
}

function clinic_notification_settings(): array {
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }

    $settings = [
        'appointment_confirmation' => true,
        'appointment_cancellation' => true,
        'appointment_reminder' => true,
        'account_verification' => true,
    ];
    $path = clinic_notification_settings_path();
    if (is_file($path)) {
        $saved = json_decode((string) file_get_contents($path), true);
        if (is_array($saved)) {
            foreach ($settings as $key => $default) {
                if (array_key_exists($key, $saved)) {
                    $settings[$key] = (bool) $saved[$key];
                }
            }
        }
    }

    return $settings;
}

function clinic_notification_enabled(string $key): bool {
    $settings = clinic_notification_settings();
    return array_key_exists($key, $settings) ? (bool) $settings[$key] : true;
}

function clinic_notification_reminder_days(): int {
    $settings = clinic_notification_settings();
    $days = (int) ($settings['reminder_days'] ?? 1);
    return in_array($days, [1, 2, 3], true) ? $days : 1;
}

function clinic_notification_disabled_result(string $label): array {
    return [
        'ok' => false,
        'disabled' => true,
        'error' => $label . ' notifications are disabled in Notification Settings.',
    ];
}

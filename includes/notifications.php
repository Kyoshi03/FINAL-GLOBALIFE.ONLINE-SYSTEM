<?php
/**
 * Best-effort booking notifications.
 * - Email: uses PHP mail() when email exists
 * - SMS: if SEMAPHORE_API_KEY is set, sends via Semaphore API
 * - Fallback: always logs SMS payload to logs/sms_queue.log
 */

if (!function_exists('clinic_notify_booking_confirmed')) {
    /**
     * @param string[] $lines
     */
    function clinic_notify_booking_confirmed(string $fullName, string $email, string $phone, array $lines): void {
        $subject = 'Appointment Confirmation - Globalife';
        $body = "Hello {$fullName},\n\nYour appointment is successfully booked.\n\n" . implode("\n", $lines) . "\n\nPayment is at the clinic.\n\n- Globalife Medical Laboratory & Polyclinic";

        // Email (best effort)
        if ($email !== '') {
            @mail($email, $subject, $body, "From: no-reply@globalife.local\r\n");
        }

        // SMS payload text
        $smsText = "Globalife booking confirmed. " . implode(' | ', $lines);

        // Always log queued SMS for audit/fallback
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logLine = date('Y-m-d H:i:s') . ' | phone=' . ($phone !== '' ? $phone : 'N/A') . ' | ' . $smsText . PHP_EOL;
        @file_put_contents($logDir . '/sms_queue.log', $logLine, FILE_APPEND);

        // Optional Semaphore SMS (best effort)
        $apiKey = getenv('SEMAPHORE_API_KEY') ?: ($_ENV['SEMAPHORE_API_KEY'] ?? '');
        if ($apiKey && $phone !== '') {
            $ch = @curl_init('https://api.semaphore.co/api/v4/messages');
            if ($ch) {
                $payload = http_build_query([
                    'apikey' => $apiKey,
                    'number' => $phone,
                    'message' => $smsText,
                    'sendername' => getenv('SEMAPHORE_SENDER') ?: 'GLOBALIFE',
                ]);
                @curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                @curl_exec($ch);
                @curl_close($ch);
            }
        }
    }
}


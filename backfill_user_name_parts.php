<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/includes/patient_profile_photo.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This maintenance script can only be run from the command line.');
}

set_time_limit(0);

function backfill_normalize_name(?string $value, bool $allowSeparators = false, bool $forceUppercase = false): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $pattern = $allowSeparators ? '/[^\p{L}\s\'-]/u' : '/[^\p{L}]/u';
    $value = preg_replace($pattern, '', $value) ?? '';
    $value = trim($value);

    if ($forceUppercase) {
        $value = function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }

    return $value;
}

function backfill_letter_count(string $value): int
{
    if ($value === '') {
        return 0;
    }

    if (function_exists('preg_match_all')) {
        return preg_match_all('/\p{L}/u', $value) ?: 0;
    }

    return strlen(preg_replace('/[^A-Za-z]/', '', $value) ?? '');
}

function backfill_title_case(string $value, bool $allowSeparators = false): string
{
    $value = backfill_normalize_name($value, $allowSeparators, false);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value));
}

function backfill_uppercase(string $value, bool $allowSeparators = false): string
{
    $value = backfill_normalize_name($value, $allowSeparators, false);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
}

function backfill_build_full_name(array $parts): string
{
    $first = backfill_title_case((string) ($parts['first_name'] ?? ''), true);
    $middle = backfill_uppercase((string) ($parts['middle_name'] ?? ''), false);
    $last = backfill_title_case((string) ($parts['last_name'] ?? ''), true);
    $suffix = backfill_uppercase((string) ($parts['suffix'] ?? ''), false);

    return trim(implode(' ', array_filter([$first, $middle, $last, $suffix], static fn ($part) => $part !== '')));
}

function backfill_parse_full_name(string $fullName): array
{
    $fullName = backfill_normalize_name($fullName, true, false);
    if ($fullName === '') {
        return [
            'first_name' => '',
            'middle_name' => '',
            'last_name' => '',
            'suffix' => '',
        ];
    }

    $suffixes = ['JR', 'SR', 'II', 'III', 'IV', 'V', 'VI'];
    $tokens = preg_split('/\s+/u', $fullName) ?: [];
    $tokens = array_values(array_filter(array_map('trim', $tokens), static fn ($part) => $part !== ''));

    $suffix = '';
    if (!empty($tokens)) {
        $lastToken = backfill_uppercase((string) end($tokens));
        if ($lastToken !== '' && in_array($lastToken, $suffixes, true)) {
            $suffix = array_pop($tokens) ?: '';
        }
    }

    $count = count($tokens);
    if ($count === 0) {
        return [
            'first_name' => '',
            'middle_name' => '',
            'last_name' => '',
            'suffix' => backfill_uppercase($suffix),
        ];
    }

    if ($count === 1) {
        $first = $tokens[0];
        $middle = '';
        $last = '';
    } elseif ($count === 2) {
        $first = $tokens[0];
        $middle = '';
        $last = $tokens[1];
    } else {
        $first = $tokens[0];
        $last = $tokens[$count - 1];
        $middle = implode(' ', array_slice($tokens, 1, -1));
    }

    return [
        'first_name' => backfill_title_case($first, true),
        'middle_name' => backfill_uppercase($middle, false),
        'last_name' => backfill_title_case($last, true),
        'suffix' => backfill_uppercase($suffix, false),
    ];
}

$conn = getDBConnection();
ensurePatientProfilePhotoColumn($conn);

$result = $conn->query("SELECT id, role, full_name, first_name, middle_name, last_name, suffix FROM users ORDER BY id ASC");
if (!$result) {
    die("Failed to read users table: " . $conn->error);
}

$update = $conn->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ?, suffix = ? WHERE id = ?");
if (!$update) {
    die("Failed to prepare update statement: " . $conn->error);
}

$updatedRows = 0;
$skippedRows = 0;

while ($row = $result->fetch_assoc()) {
    $existingFullName = trim((string) ($row['full_name'] ?? ''));
    $parsed = backfill_parse_full_name($existingFullName);

    $firstName = trim((string) ($row['first_name'] ?? ''));
    $middleName = trim((string) ($row['middle_name'] ?? ''));
    $lastName = trim((string) ($row['last_name'] ?? ''));
    $suffix = trim((string) ($row['suffix'] ?? ''));

    $newFirstName = $firstName !== '' ? backfill_title_case($firstName, true) : $parsed['first_name'];
    $newMiddleName = $middleName !== '' ? backfill_uppercase($middleName, false) : $parsed['middle_name'];
    $newLastName = $lastName !== '' ? backfill_title_case($lastName, true) : $parsed['last_name'];
    $newSuffix = $suffix !== '' ? backfill_uppercase($suffix, false) : $parsed['suffix'];

    $newFullName = $existingFullName;
    if ($newFullName === '') {
        $newFullName = backfill_build_full_name([
            'first_name' => $newFirstName,
            'middle_name' => $newMiddleName,
            'last_name' => $newLastName,
            'suffix' => $newSuffix,
        ]);
    }

    $changed = $newFirstName !== $firstName
        || $newMiddleName !== $middleName
        || $newLastName !== $lastName
        || $newSuffix !== $suffix;

    if (!$changed) {
        $skippedRows++;
        continue;
    }

    $id = (int) $row['id'];
    $update->bind_param(str_repeat('s', 4) . 'i', $newFirstName, $newMiddleName, $newLastName, $newSuffix, $id);
    if (!$update->execute()) {
        echo "Failed to update user #{$id}: " . $update->error . PHP_EOL;
        continue;
    }

    $updatedRows++;
}

$update->close();
$conn->close();

echo "Backfill completed." . PHP_EOL;
echo "Updated: {$updatedRows}" . PHP_EOL;
echo "Skipped: {$skippedRows}" . PHP_EOL;

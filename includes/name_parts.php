<?php
function clinic_name_normalize_part(?string $value, bool $allowSeparators = false, bool $forceUppercase = false): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $pattern = $allowSeparators ? '/[^\p{L}\s\'-]/u' : '/[^\p{L}]/u';
    $value = preg_replace($pattern, '', $value) ?? '';

    if ($forceUppercase) {
        $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    return trim($value);
}

function clinic_name_title_case_part(?string $value, bool $allowSeparators = false): string {
    $value = clinic_name_normalize_part($value, $allowSeparators, false);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_convert_case')
        ? mb_convert_case($value, MB_CASE_TITLE, 'UTF-8')
        : ucwords(strtolower($value));
}

function clinic_name_uppercase_part(?string $value, bool $allowSeparators = false): string {
    $value = clinic_name_normalize_part($value, $allowSeparators, false);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($value, 'UTF-8')
        : strtoupper($value);
}

function clinic_name_letter_count(string $value): int {
    if ($value === '') {
        return 0;
    }
    if (function_exists('preg_match_all')) {
        return preg_match_all('/\p{L}/u', $value) ?: 0;
    }
    return strlen(preg_replace('/[^A-Za-z]/', '', $value) ?? '');
}

function clinic_name_build_full_name(array $parts): string {
    $first = clinic_name_title_case_part($parts['first_name'] ?? '', true);
    $middle = clinic_name_uppercase_part($parts['middle_name'] ?? '', false);
    $last = clinic_name_title_case_part($parts['last_name'] ?? '', true);
    $suffix = clinic_name_uppercase_part($parts['suffix'] ?? '', false);
    return trim(implode(' ', array_filter([$first, $middle, $last, $suffix], static fn ($p) => $p !== '')));
}

/**
 * Returns one consistent name for cards, headers, emails, and reports.
 * The separate database fields remain the source of truth; legacy full_name
 * is only a display fallback while older records are being cleaned up.
 */
function clinic_name_display_from_row(array $row, string $fallback = 'Patient'): string {
    $name = clinic_name_build_full_name([
        'first_name' => $row['first_name'] ?? '',
        'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'suffix' => $row['suffix'] ?? '',
    ]);

    if ($name !== '') {
        return $name;
    }

    $legacyName = trim((string) ($row['full_name'] ?? ''));
    return $legacyName !== '' ? $legacyName : $fallback;
}

function clinic_name_split_full_name(?string $fullName): array {
    $fullName = clinic_name_normalize_part($fullName, true, false);
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
        $lastToken = clinic_name_uppercase_part((string) end($tokens));
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
            'suffix' => clinic_name_uppercase_part($suffix),
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
        'first_name' => clinic_name_title_case_part($first, true),
        'middle_name' => clinic_name_uppercase_part($middle, false),
        'last_name' => clinic_name_title_case_part($last, true),
        'suffix' => clinic_name_uppercase_part($suffix, false),
    ];
}

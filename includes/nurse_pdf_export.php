<?php
/**
 * Small single-page PDF renderer for nurse medical record and lab result exports.
 * Uses built-in PDF fonts only so the export works without Composer libraries.
 */

function clinic_pdf_clean(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strtr($text, [
        '₱' => 'PHP ',
        '—' => '-',
        '–' => '-',
        '•' => '-',
        '“' => '"',
        '”' => '"',
        '‘' => "'",
        '’' => "'",
        "\r" => '',
    ]);
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text) ?? '';
}

function clinic_pdf_escape(string $text): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], clinic_pdf_clean($text));
}

function clinic_pdf_text(array &$ops, float $x, float $y, float $size, string $text, bool $bold = false): void {
    $font = $bold ? '/F2' : '/F1';
    $ops[] = sprintf("0.05 0.16 0.24 rg BT %s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET", $font, $size, $x, $y, clinic_pdf_escape($text));
}

function clinic_pdf_center_text(array &$ops, float $y, float $size, string $text, bool $bold = false): void {
    $clean = clinic_pdf_clean($text);
    $x = 297.5 - (strlen($clean) * $size * 0.25);
    clinic_pdf_text($ops, max(45, $x), $y, $size, $clean, $bold);
}

function clinic_pdf_white_center_text(array &$ops, float $y, float $size, string $text): void {
    $clean = clinic_pdf_clean($text);
    $x = 297.5 - (strlen($clean) * $size * 0.25);
    $ops[] = sprintf(
        "1 1 1 rg BT /F2 %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET",
        $size,
        max(45, $x),
        $y,
        clinic_pdf_escape($clean)
    );
}

function clinic_pdf_line(array &$ops, float $x1, float $y1, float $x2, float $y2, float $width = 1.0): void {
    $ops[] = sprintf("0.08 0.56 0.57 RG %.2F w %.2F %.2F m %.2F %.2F l S", $width, $x1, $y1, $x2, $y2);
}

function clinic_pdf_rect(array &$ops, float $x, float $y, float $w, float $h, bool $fill = false): void {
    if ($fill) {
        $ops[] = sprintf("0.94 0.97 1.00 rg %.2F %.2F %.2F %.2F re f", $x, $y, $w, $h);
    }
    $ops[] = sprintf("0.78 0.84 0.90 RG 0.6 w %.2F %.2F %.2F %.2F re S", $x, $y, $w, $h);
}

function clinic_pdf_wrap(string $text, int $maxChars): array {
    $text = trim(clinic_pdf_clean($text));
    if ($text === '') {
        return [''];
    }
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            $out[] = '';
            continue;
        }
        foreach (explode("\n", wordwrap($line, $maxChars, "\n", true)) as $wrapped) {
            $out[] = $wrapped;
        }
    }
    return $out;
}

function clinic_pdf_paeth(int $a, int $b, int $c): int {
    $p = $a + $b - $c;
    $pa = abs($p - $a);
    $pb = abs($p - $b);
    $pc = abs($p - $c);
    if ($pa <= $pb && $pa <= $pc) {
        return $a;
    }
    return $pb <= $pc ? $b : $c;
}

function clinic_pdf_png_image(string $path): ?array {
    $png = @file_get_contents($path);
    if ($png === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        return null;
    }

    $offset = 8;
    $length = strlen($png);
    $width = 0;
    $height = 0;
    $bitDepth = 0;
    $colorType = -1;
    $compressed = '';

    while ($offset + 12 <= $length) {
        $chunkLength = unpack('N', substr($png, $offset, 4))[1];
        $chunkType = substr($png, $offset + 4, 4);
        $chunkData = substr($png, $offset + 8, $chunkLength);
        $offset += 12 + $chunkLength;

        if ($chunkType === 'IHDR' && strlen($chunkData) >= 13) {
            $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $chunkData);
            $width = (int) $header['width'];
            $height = (int) $header['height'];
            $bitDepth = (int) $header['bitDepth'];
            $colorType = (int) $header['colorType'];
            if ((int) $header['interlace'] !== 0) {
                return null;
            }
        } elseif ($chunkType === 'IDAT') {
            $compressed .= $chunkData;
        } elseif ($chunkType === 'IEND') {
            break;
        }
    }

    if ($width <= 0 || $height <= 0 || $bitDepth !== 8 || !in_array($colorType, [0, 2, 4, 6], true)) {
        return null;
    }

    $decoded = @gzuncompress($compressed);
    if ($decoded === false && function_exists('zlib_decode')) {
        $decoded = @zlib_decode($compressed);
    }
    if ($decoded === false) {
        return null;
    }

    $bytesPerPixel = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$colorType];
    $rowLength = $width * $bytesPerPixel;
    $cursor = 0;
    $previous = array_fill(0, $rowLength, 0);
    $rgb = '';

    for ($rowIndex = 0; $rowIndex < $height; $rowIndex++) {
        if ($cursor >= strlen($decoded)) {
            return null;
        }
        $filter = ord($decoded[$cursor++]);
        $source = substr($decoded, $cursor, $rowLength);
        $cursor += $rowLength;
        if (strlen($source) !== $rowLength) {
            return null;
        }

        $row = [];
        for ($i = 0; $i < $rowLength; $i++) {
            $value = ord($source[$i]);
            $left = $i >= $bytesPerPixel ? $row[$i - $bytesPerPixel] : 0;
            $up = $previous[$i] ?? 0;
            $upperLeft = $i >= $bytesPerPixel ? ($previous[$i - $bytesPerPixel] ?? 0) : 0;

            if ($filter === 1) {
                $value = ($value + $left) & 255;
            } elseif ($filter === 2) {
                $value = ($value + $up) & 255;
            } elseif ($filter === 3) {
                $value = ($value + intdiv($left + $up, 2)) & 255;
            } elseif ($filter === 4) {
                $value = ($value + clinic_pdf_paeth($left, $up, $upperLeft)) & 255;
            } elseif ($filter !== 0) {
                return null;
            }
            $row[$i] = $value;
        }

        for ($pixel = 0; $pixel < $width; $pixel++) {
            $base = $pixel * $bytesPerPixel;
            if ($colorType === 0) {
                $red = $green = $blue = $row[$base];
                $alpha = 255;
            } elseif ($colorType === 2) {
                $red = $row[$base];
                $green = $row[$base + 1];
                $blue = $row[$base + 2];
                $alpha = 255;
            } elseif ($colorType === 4) {
                $red = $green = $blue = $row[$base];
                $alpha = $row[$base + 1];
            } else {
                $red = $row[$base];
                $green = $row[$base + 1];
                $blue = $row[$base + 2];
                $alpha = $row[$base + 3];
            }

            if ($alpha < 255) {
                $red = (int) round(($red * $alpha + 255 * (255 - $alpha)) / 255);
                $green = (int) round(($green * $alpha + 255 * (255 - $alpha)) / 255);
                $blue = (int) round(($blue * $alpha + 255 * (255 - $alpha)) / 255);
            }
            $rgb .= chr($red) . chr($green) . chr($blue);
        }
        $previous = $row;
    }

    return [
        'width' => $width,
        'height' => $height,
        'data' => gzcompress($rgb, 9),
    ];
}

function clinic_pdf_table_row(array &$ops, float &$y, string $label, string $value, int $maxLines = 4): void {
    $x = 65;
    $labelW = 150;
    $valueW = 365;
    $lines = clinic_pdf_wrap($value, 66);
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $lines[] = 'Text shortened to fit this A4 export.';
    }
    $height = max(24, 11 + (count($lines) * 12));
    $bottom = $y - $height;
    clinic_pdf_rect($ops, $x, $bottom, $labelW, $height, true);
    clinic_pdf_rect($ops, $x + $labelW, $bottom, $valueW, $height, false);
    clinic_pdf_text($ops, $x + 8, $y - 16, 9, $label, true);
    $lineY = $y - 16;
    foreach ($lines as $line) {
        clinic_pdf_text($ops, $x + $labelW + 8, $lineY, 9, $line);
        $lineY -= 12;
    }
    $y = $bottom;
}

function clinic_pdf_build(string $stream, ?array $image = null): string {
    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $imageResource = $image ? " /XObject << /Im1 7 0 R >>" : '';
    $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>" . $imageResource . " >> /Contents 6 0 R >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";
    $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    if ($image) {
        $objects[] = "<< /Type /XObject /Subtype /Image /Width " . (int) $image['width']
            . " /Height " . (int) $image['height']
            . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length "
            . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
    }

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($pdf);
        $n = $i + 1;
        $pdf .= $n . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function clinic_nurse_pdf_output(string $type, array $record): void {
    $ops = [];
    $logo = clinic_pdf_png_image(__DIR__ . '/../globalife.png');
    clinic_pdf_line($ops, 34, 815, 561, 815, 2.2);
    clinic_pdf_line($ops, 34, 735, 561, 735, 2.2);

    if ($logo) {
        $ops[] = "q 58 0 0 58 46 747 cm /Im1 Do Q";
    } else {
        $ops[] = "0.08 0.56 0.57 rg 46 752 58 48 re f";
        clinic_pdf_text($ops, 63, 772, 18, 'GL', true);
    }
    clinic_pdf_text($ops, 300, 790, 12, 'Globalife Medical Laboratory & Polyclinic', true);
    clinic_pdf_text($ops, 376, 774, 9, 'Barangay Sahud Ulan, Tanza, Cavite', true);
    clinic_pdf_text($ops, 398, 758, 9, 'Professional clinic report', true);

    $documentTitle = $type === 'lab' ? 'LABORATORY REPORT' : 'MEDICAL RECORD';
    $ops[] = "0.08 0.56 0.57 rg 46 682 503 26 re f";
    clinic_pdf_white_center_text($ops, 690, 12, $documentTitle);

    $y = 650;
    clinic_pdf_text($ops, 65, $y, 12, 'Patient Information', true);
    $y -= 18;
    clinic_pdf_table_row($ops, $y, 'Name', (string) ($record['patient_name'] ?? 'N/A'), 2);
    clinic_pdf_table_row($ops, $y, 'Date of Birth', (string) ($record['date_of_birth'] ?? 'Not recorded'), 2);
    clinic_pdf_table_row($ops, $y, 'Gender', (string) ($record['gender'] ?? 'Not recorded'), 2);
    clinic_pdf_table_row($ops, $y, 'Contact Number', (string) ($record['phone'] ?? 'Not recorded'), 2);
    clinic_pdf_table_row($ops, $y, 'Email', (string) ($record['email'] ?? 'Not recorded'), 2);

    $y -= 28;
    clinic_pdf_line($ops, 65, $y + 12, 530, $y + 12, 0.8);
    clinic_pdf_text($ops, 65, $y, 12, $type === 'lab' ? 'Lab Result Details' : 'Medical Note Details', true);
    $y -= 18;

    if ($type === 'lab') {
        clinic_pdf_table_row($ops, $y, 'Test Name', (string) ($record['test_name'] ?? 'N/A'), 3);
        clinic_pdf_table_row($ops, $y, 'Result Date', (string) ($record['result_date'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Prepared By', (string) ($record['author_name'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Result Details', (string) ($record['result_text'] ?? ''), 18);
    } else {
        clinic_pdf_table_row($ops, $y, 'Title', (string) ($record['title'] ?? 'N/A'), 3);
        clinic_pdf_table_row($ops, $y, 'Date Added', (string) ($record['created_at'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Prepared By', (string) ($record['author_name'] ?? 'N/A'), 2);
        clinic_pdf_table_row($ops, $y, 'Medical Note Details', (string) ($record['content'] ?? ''), 18);
    }

    clinic_pdf_line($ops, 65, 70, 530, 70, 0.7);
    clinic_pdf_text($ops, 65, 54, 8, 'Generated by Globalife Clinic System. Keep this document confidential.');
    clinic_pdf_text($ops, 405, 54, 8, 'Generated: ' . date('Y-m-d H:i'));

    $pdf = clinic_pdf_build(implode("\n", $ops), $logo);
    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', strtolower((string) ($record['patient_name'] ?? 'patient'))) ?: 'patient';
    $fileName = $safeName . '_' . ($type === 'lab' ? 'lab_result' : 'medical_record') . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
}

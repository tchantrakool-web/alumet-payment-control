<?php
/**
 * Minimal .xlsx reader — no external dependencies.
 * Returns array of rows (array of cell values) for the first sheet.
 */
function readXlsx(string $filePath, int $maxRows = 5000): array {
    if (!file_exists($filePath)) throw new RuntimeException("File not found: $filePath");

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) throw new RuntimeException("Cannot open xlsx file");

    // Read shared strings
    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml) {
        $ss  = simplexml_load_string($ssXml);
        $siNodes = $ss->xpath('//*[local-name()="si"]') ?: [];
        foreach ($siNodes as $si) {
            // Concatenate all <t> elements (handles rich text)
            $val = '';
            foreach (($si->xpath('.//*[local-name()="t"]') ?: []) as $t) {
                $val .= (string)$t;
            }
            $sharedStrings[] = $val;
        }
    }

    // Find first sheet relation
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $sheetFile   = 'xl/worksheets/sheet1.xml';
    if ($workbookXml) {
        $wb = simplexml_load_string($workbookXml);
        $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        // just use sheet1 for simplicity
    }

    $sheetXml = $zip->getFromName($sheetFile);
    $zip->close();

    if (!$sheetXml) throw new RuntimeException("Cannot read worksheet");

    $ws = simplexml_load_string($sheetXml);
    $ws->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];
    $rowCount = 0;

    $rowNodes = $ws->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
    foreach ($rowNodes as $row) {
        $rowData = [];
        $cellNodes = $row->xpath('./*[local-name()="c"]') ?: [];
        foreach ($cellNodes as $cell) {
            $cellRef = (string)($cell['r'] ?? '');
            $col     = columnIndex($cellRef);
            $type    = (string)($cell['t'] ?? '');
            $vNodes  = $cell->xpath('./*[local-name()="v"]') ?: [];
            $rawVal  = !empty($vNodes) ? (string)$vNodes[0] : '';

            // Pad gaps
            while (count($rowData) < $col) $rowData[] = '';

            if ($type === 's') {
                $rowData[] = $sharedStrings[(int)$rawVal] ?? '';
            } elseif ($type === 'inlineStr') {
                $inlineText = '';
                foreach (($cell->xpath('.//*[local-name()="is"]//*[local-name()="t"]') ?: []) as $t) {
                    $inlineText .= (string)$t;
                }
                $rowData[] = $inlineText;
            } elseif ($rawVal !== '') {
                // Check if it's a date serial (numeric) - basic handling
                $rowData[] = $rawVal;
            } else {
                $rowData[] = '';
            }
        }
        $rows[] = $rowData;
        if (++$rowCount >= $maxRows) break;
    }

    return $rows;
}

function columnIndex(string $cellRef): int {
    preg_match('/^([A-Z]+)/', strtoupper($cellRef), $m);
    if (empty($m[1])) return 0;
    $col = 0;
    foreach (str_split($m[1]) as $ch) {
        $col = $col * 26 + (ord($ch) - ord('A') + 1);
    }
    return $col - 1;
}

function excelDateToString(string $serial): string {
    if (!is_numeric($serial)) return $serial;
    $days = (int)floor((float)$serial);
    return (new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC')))
        ->modify("+{$days} days")
        ->format('Y-m-d');
}

function looksLikeDate(string $val): bool {
    return is_numeric($val) && (float)$val > 20000 && (float)$val < 60000;
}

function normalizeSpreadsheetDate(string $value): string {
    $value = trim($value);
    if ($value === '' || $value === '0') return '';
    if (looksLikeDate($value)) return excelDateToString($value);

    $value = str_replace('.', '/', $value);
    foreach (['!Y-m-d', '!d/m/y', '!d/m/Y', '!d-m-y', '!d-m-Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $year = (int)$date->format('Y');
            if ($year > 2400) $date = $date->modify('-543 years');
            // Thai source files sometimes use the last two digits of the
            // Buddhist year (e.g. 69 for 2569 / 2026).
            if (preg_match('/(?:^|[\/-])\d{2}$/', $value) && $year >= 2060) {
                $date = $date->modify('-43 years');
            }
            return $date->format('Y-m-d');
        }
    }

    return $value;
}

function parseSpreadsheetMoney(string $value): float {
    $value = trim($value);
    if ($value === '' || $value === '-') return 0.0;
    $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
    $clean = preg_replace('/[^0-9.\-]/u', '', str_replace(',', '', $value));
    $amount = is_numeric($clean) ? (float)$clean : 0.0;
    return $negative ? -abs($amount) : $amount;
}

<?php

require_once __DIR__ . '/excel_reader.php';

/**
 * Imports a GRPO-centric SAP workbook into the invoice-centric application.
 * Multiple GRPO rows for one invoice are consolidated, while rows without an
 * invoice receive a stable GRPO key so later refreshes do not duplicate them.
 */
function importSapPurchaseFile(
    PDO $db,
    string $filePath,
    string $originalFilename,
    ?int $userId = null
): array {
    $rows = readSapPurchaseRows($filePath);
    if (empty($rows)) {
        throw new RuntimeException('The workbook is empty.');
    }

    [$headerRow, $headerIdx] = findSapHeaderRow($rows);
    $map = mapSapHeaders($headerRow);
    foreach (['vendor_name', 'grpo_doc_num'] as $requiredField) {
        if (!isset($map[$requiredField])) {
            throw new RuntimeException("Required SAP column is missing: {$requiredField}");
        }
    }

    $batchNo = nextSapBatchNo($db);
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }

    try {
        $db->prepare(
            "INSERT INTO sap_import_batches (batch_no, filename, status, imported_by)
             VALUES (?, ?, 'processing', ?)"
        )->execute([$batchNo, $originalFilename, $userId]);
        $batchId = (int)$db->lastInsertId();

        $stmtError = $db->prepare(
            "INSERT INTO import_error_logs
             (import_batch_id, import_type, row_number, field_name, error_message, raw_data)
             VALUES (?, 'SAP', ?, ?, ?, ?)"
        );

        $groups = [];
        $errors = 0;
        $totalRecords = 0;

        foreach (array_slice($rows, $headerIdx + 1) as $offset => $row) {
            if (count(array_filter($row, fn($value) => trim((string)$value) !== '')) === 0) {
                continue;
            }

            $totalRecords++;
            $rowNumber = $headerIdx + $offset + 2;
            $get = fn(string $field): string =>
                isset($map[$field]) ? trim((string)($row[$map[$field]] ?? '')) : '';

            $vendorName = normalizeSapText($get('vendor_name'));
            $grpoDocNum = $get('grpo_doc_num');
            if ($vendorName === '' || $grpoDocNum === '') {
                $errors++;
                $missingField = $vendorName === '' ? 'vendor_name' : 'grpo_doc_num';
                $stmtError->execute([
                    $batchId,
                    $rowNumber,
                    $missingField,
                    "Required value is empty: {$missingField}",
                    implode(',', array_slice($row, 0, 20)),
                ]);
                continue;
            }

            $sourceInvoiceNo = $get('ap_invoice_doc_num');
            $invoiceNo = $sourceInvoiceNo !== '' ? $sourceInvoiceNo : 'GRPO-' . $grpoDocNum;
            $groupKey = 'DOC:' . $invoiceNo;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'source_invoice_no' => $sourceInvoiceNo,
                    'ap_invoice_doc_num' => $invoiceNo,
                    'vendor_code' => $get('vendor_code'),
                    'vendor_name' => $vendorName,
                    'po_doc_nums' => [],
                    'grpo_doc_nums' => [],
                    'grpo_dates' => [],
                    'grpo_totals' => [],
                    'ap_invoice_dates' => [],
                    'ap_invoice_totals' => [],
                    'ap_paid_amounts' => [],
                    'ap_balances' => [],
                    'payment_doc_nums' => [],
                    'payment_totals' => [],
                    'payment_statuses' => [],
                ];
            }

            $group = &$groups[$groupKey];
            addSapDistinctValue($group['po_doc_nums'], $get('po_doc_num'));
            addSapDistinctValue($group['grpo_doc_nums'], $grpoDocNum);
            addSapDistinctValue($group['grpo_dates'], normalizeSpreadsheetDate($get('grpo_date')));
            addSapDistinctValue($group['ap_invoice_dates'], normalizeSpreadsheetDate($get('ap_invoice_date')));
            addSapDistinctValue($group['payment_doc_nums'], $get('payment_doc_num'));
            addSapDistinctValue($group['payment_statuses'], $get('payment_status'));
            $group['grpo_totals'][] = parseSpreadsheetMoney($get('grpo_total'));
            $group['ap_invoice_totals'][] = parseSpreadsheetMoney($get('ap_invoice_total'));
            $group['ap_paid_amounts'][] = parseSpreadsheetMoney($get('ap_paid_amount'));
            $group['ap_balances'][] = parseSpreadsheetMoney($get('ap_balance'));
            $group['payment_totals'][] = parseSpreadsheetMoney($get('payment_total'));
            unset($group);
        }

        $stats = [
            'batch_id' => $batchId,
            'batch_no' => $batchNo,
            'filename' => $originalFilename,
            'total_records' => $totalRecords,
            'consolidated_records' => count($groups),
            'inserted_records' => 0,
            'updated_records' => 0,
            'merged_placeholders' => 0,
            'error_records' => $errors,
        ];

        foreach ($groups as $group) {
            $record = finalizeSapGroup($group);
            $existingId = findSapInvoiceId($db, $record['ap_invoice_doc_num']);
            $placeholderIds = findSapPlaceholderIds($db, $record['grpo_doc_nums']);

            if ($existingId === null && $record['source_invoice_no'] !== '' && !empty($placeholderIds)) {
                $existingId = array_shift($placeholderIds);
            }

            if ($existingId === null) {
                insertSapInvoice($db, $batchId, $record);
                $existingId = (int)$db->lastInsertId();
                $stats['inserted_records']++;
            } else {
                updateSapInvoice($db, $existingId, $batchId, $record);
                $stats['updated_records']++;
            }

            foreach ($placeholderIds as $placeholderId) {
                if ($placeholderId !== $existingId) {
                    mergeSapInvoicePlaceholder($db, $placeholderId, $existingId);
                    $stats['merged_placeholders']++;
                }
            }
            upsertSapVendor($db, $record['vendor_code'], $record['vendor_name']);
        }

        $processed = $stats['inserted_records'] + $stats['updated_records'];
        $db->prepare(
            "UPDATE sap_import_batches
             SET total_records = ?, imported_records = ?, error_records = ?, status = 'completed'
             WHERE id = ?"
        )->execute([$totalRecords, $processed, $errors, $batchId]);

        if ($ownsTransaction) {
            $db->commit();
        }
        return $stats;
    } catch (Throwable $error) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function readSapPurchaseRows(string $filePath): array {
    if (!is_file($filePath)) {
        throw new RuntimeException("File not found: {$filePath}");
    }
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $rows = [];
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV file: {$filePath}");
        }
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }
    if ($ext !== 'xlsx') {
        throw new RuntimeException('Supported SAP import formats are .xlsx and .csv.');
    }
    return readXlsx($filePath, 50000);
}

function findSapHeaderRow(array $rows): array {
    foreach ($rows as $index => $row) {
        $normalized = array_map(
            fn($value) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$value))),
            $row
        );
        if (in_array('vendorname', $normalized, true) || in_array('vendor name', $normalized, true)) {
            return [array_map('trim', $row), $index];
        }
    }
    return [array_map('trim', $rows[0]), 0];
}

function mapSapHeaders(array $headerRow): array {
    $aliases = [
        'vendor_code' => ['vendorcode', 'vendor_code', 'vendor code', 'cardcode'],
        'vendor_name' => ['vendorname', 'vendor_name', 'vendor name', 'cardname'],
        'po_doc_num' => ['po_docnum', 'po_docnums', 'po docnum', 'po docnums', 'podocnum', 'podocnums', 'po no', 'po number'],
        'grpo_doc_num' => ['grpo_docnum', 'grpo docnum', 'grpodocnum', 'grpo no'],
        'grpo_date' => ['grpo_date', 'grpodate', 'grpo date'],
        'grpo_total' => ['grpo_total', 'grpototal', 'grpo total', 'grpo amount'],
        'ap_invoice_doc_num' => ['ap_invoice_docnum', 'ap invoice docnum', 'apinvoicedocnum', 'invoice no', 'ap_docnum', 'ap docnum'],
        'ap_invoice_date' => ['ap_invoice_date', 'ap invoice date', 'invoice date', 'apinvoicedate'],
        'ap_invoice_total' => ['ap_invoice_total', 'ap invoice total', 'invoice total', 'ap total', 'amount'],
        'ap_paid_amount' => ['ap_paid_amount', 'ap paid amount', 'paid amount', 'paid'],
        'ap_balance' => ['ap_balance', 'ap balance', 'balance', 'outstanding'],
        'payment_doc_num' => ['payment_docnum', 'payment_docnums', 'payment docnum', 'payment docnums', 'paymentdocnum', 'paymentdocnums', 'payment no'],
        'payment_total' => ['payment_total', 'payment total', 'paymenttotal'],
        'payment_status' => ['payment_status', 'paymentstatus', 'payment status', 'status'],
    ];
    $map = [];
    foreach ($headerRow as $index => $header) {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', (string)$header)));
        foreach ($aliases as $field => $fieldAliases) {
            if (!isset($map[$field]) && in_array($normalized, $fieldAliases, true)) {
                $map[$field] = $index;
            }
        }
    }
    return $map;
}

function finalizeSapGroup(array $group): array {
    sort($group['grpo_dates']);
    sort($group['ap_invoice_dates']);
    return [
        'source_invoice_no' => $group['source_invoice_no'],
        'ap_invoice_doc_num' => $group['ap_invoice_doc_num'],
        'vendor_code' => $group['vendor_code'],
        'vendor_name' => $group['vendor_name'],
        'po_doc_num' => implode(', ', $group['po_doc_nums']),
        'grpo_doc_num' => implode(', ', $group['grpo_doc_nums']),
        'grpo_doc_nums' => $group['grpo_doc_nums'],
        'grpo_date' => end($group['grpo_dates']) ?: '',
        'grpo_total' => array_sum($group['grpo_totals']),
        'ap_invoice_date' => end($group['ap_invoice_dates']) ?: '',
        'ap_invoice_total' => max($group['ap_invoice_totals'] ?: [0]),
        'ap_paid_amount' => max($group['ap_paid_amounts'] ?: [0]),
        'ap_balance' => max($group['ap_balances'] ?: [0]),
        'payment_doc_num' => implode(', ', $group['payment_doc_nums']),
        'payment_total' => max($group['payment_totals'] ?: [0]),
        'payment_status' => latestSapMeaningfulValue($group['payment_statuses'], 'Imported'),
    ];
}

function addSapDistinctValue(array &$values, string $value): void {
    $value = trim($value);
    if ($value !== '' && !in_array($value, $values, true)) {
        $values[] = $value;
    }
}

function normalizeSapText(string $value): string {
    return trim(str_replace("\u{00A0}", ' ', $value));
}

function latestSapMeaningfulValue(array $values, string $fallback): string {
    foreach (array_reverse($values) as $value) {
        $value = trim($value);
        if ($value !== '' && $value !== '-') {
            return $value;
        }
    }
    return $fallback;
}

function nextSapBatchNo(PDO $db): string {
    $prefix = 'SAP' . date('Ym');
    $stmt = $db->prepare(
        "SELECT batch_no FROM sap_import_batches
         WHERE batch_no LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $sequence = $last ? ((int)substr((string)$last, -4) + 1) : 1;
    return sprintf('%s%04d', $prefix, $sequence);
}

function findSapInvoiceId(PDO $db, string $invoiceNo): ?int {
    $stmt = $db->prepare(
        'SELECT id FROM sap_ap_invoices WHERE ap_invoice_doc_num = ? ORDER BY id LIMIT 1'
    );
    $stmt->execute([$invoiceNo]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function findSapPlaceholderIds(PDO $db, array $grpoDocNums): array {
    if (empty($grpoDocNums)) {
        return [];
    }
    $conditions = [];
    $params = [];
    foreach ($grpoDocNums as $grpoDocNum) {
        $conditions[] = "(',' || REPLACE(grpo_doc_num, ' ', '') || ',') LIKE ? ESCAPE '\\'";
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], str_replace(' ', '', $grpoDocNum));
        $params[] = '%,' . $escaped . ',%';
    }
    $stmt = $db->prepare(
        "SELECT id FROM sap_ap_invoices
         WHERE (ap_invoice_doc_num LIKE 'IMPORT-%' OR ap_invoice_doc_num LIKE 'GRPO-%')
           AND (" . implode(' OR ', $conditions) . ')
         ORDER BY id'
    );
    $stmt->execute($params);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}

function insertSapInvoice(PDO $db, int $batchId, array $record): void {
    $db->prepare(
        'INSERT INTO sap_ap_invoices
         (import_batch_id, vendor_code, vendor_name, po_doc_num, grpo_doc_num, grpo_date, grpo_total,
          ap_invoice_doc_num, ap_invoice_date, ap_invoice_total, ap_paid_amount, ap_balance,
          payment_doc_num, payment_total, payment_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(sapInvoiceSqlValues($batchId, $record));
}

function updateSapInvoice(PDO $db, int $id, int $batchId, array $record): void {
    $values = sapInvoiceSqlValues($batchId, $record);
    $values[] = $id;
    $db->prepare(
        "UPDATE sap_ap_invoices SET
            import_batch_id = ?, vendor_code = ?, vendor_name = ?, po_doc_num = ?,
            grpo_doc_num = ?, grpo_date = ?, grpo_total = ?, ap_invoice_doc_num = ?,
            ap_invoice_date = ?, ap_invoice_total = ?, ap_paid_amount = ?, ap_balance = ?,
            payment_doc_num = ?, payment_total = ?, payment_status = ?,
            is_deleted = 0, updated_at = datetime('now','localtime')
         WHERE id = ?"
    )->execute($values);
}

function sapInvoiceSqlValues(int $batchId, array $record): array {
    return [
        $batchId,
        $record['vendor_code'],
        $record['vendor_name'],
        $record['po_doc_num'],
        $record['grpo_doc_num'],
        $record['grpo_date'],
        $record['grpo_total'],
        $record['ap_invoice_doc_num'],
        $record['ap_invoice_date'],
        $record['ap_invoice_total'],
        $record['ap_paid_amount'],
        $record['ap_balance'],
        $record['payment_doc_num'],
        $record['payment_total'],
        $record['payment_status'],
    ];
}

function mergeSapInvoicePlaceholder(PDO $db, int $placeholderId, int $targetId): void {
    $db->prepare('UPDATE payment_request_items SET sap_invoice_id = ? WHERE sap_invoice_id = ?')
        ->execute([$targetId, $placeholderId]);
    $db->prepare('UPDATE finance_ap_records SET sap_invoice_id = ? WHERE sap_invoice_id = ?')
        ->execute([$targetId, $placeholderId]);
    $db->prepare('DELETE FROM sap_ap_invoices WHERE id = ?')->execute([$placeholderId]);
}

function upsertSapVendor(PDO $db, string $vendorCode, string $vendorName): void {
    if ($vendorCode === '') {
        return;
    }
    $db->prepare(
        "INSERT INTO vendors (vendor_code, vendor_name)
         VALUES (?, ?)
         ON CONFLICT(vendor_code) DO UPDATE SET
            vendor_name = excluded.vendor_name,
            updated_at = datetime('now','localtime')"
    )->execute([$vendorCode, $vendorName]);
}

<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/excel_reader.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) { flash('error', 'Your session token expired. Please try again.'); redirect(BASE_URL . '/modules/import/'); }
if (!canEdit('import')) { flash('error', 'Import Center is read-only for your role.'); redirect(BASE_URL . '/modules/import/'); }

function normalizeFinanceHeader(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    return preg_replace('/[\s_\-\/().:]+/u', '', $value) ?? '';
}

function financeHeaderAliases(): array {
    return [
        'vendor_name'    => ['vendorname', 'supplier', 'suppliername', 'ชื่อsupplier', 'ชื่อเจ้าหนี้', 'เจ้าหนี้'],
        'invoice_no'     => ['apinvoice', 'apinvoiceno', 'apinvoicenumber', 'เลขที่apinvoice'],
        'tax_invoice_no' => ['taxinvoice', 'taxinvoiceno', 'เลขที่ใบกำกับ', 'เลขที่ใบกำกับภาษี'],
        'invoice_date'   => ['invoicedate', 'วันที่invoice', 'วันที่ใบรับวางบิล', 'วันที่ใบแจ้งหนี้'],
        'due_date'       => ['duedate', 'dueจริง', 'วันครบกำหนดจริง', 'วันที่ครบกำหนด', 'dueคำนวน', 'dueคำนวณ'],
        'amount'         => ['amount', 'grossamount', 'ยอดรวมสุทธิก่อนหักณที่จ่าย', 'ยอดรวมก่อนหักณที่จ่าย', 'จำนวนเงิน'],
        'wht_amount'     => ['wht', 'whtamount', 'ภาษีหักณที่จ่าย', 'หักณที่จ่าย'],
        'net_payable'    => ['netpayable', 'netamount', 'ยอดสุทธิหลังหักณที่จ่าย', 'ยอดสุทธิ'],
        'cheque_date'    => ['chequedate', 'datechq', 'วันที่เช็ค'],
        'cheque_bank'    => ['bank', 'bankname', 'ธนาคาร'],
        'cheque_no'      => ['chequeno', 'nochq', 'เลขที่เช็ค'],
        'paid_date'      => ['paiddate', 'paymentdate', 'วันที่จ่ายจริง'],
        'payment_status' => ['paymentstatus', 'status', 'สถานะ'],
        'remark'         => ['remark', 'remarks', 'note', 'รายละเอียด', 'หมายเหตุ'],
    ];
}

function mapFinanceHeader(array $row, array $aliases): array {
    $map = [];
    $normalizedHeaders = array_map(static fn($header): string => normalizeFinanceHeader((string)$header), $row);
    foreach ($aliases as $field => $fieldAliases) {
        foreach ($fieldAliases as $alias) {
            foreach ($normalizedHeaders as $colIdx => $normalized) {
                if ($normalized !== '' && $normalized === $alias) {
                    $map[$field] = $colIdx;
                    break 2;
                }
            }
        }
    }
    return $map;
}

function findFinanceHeader(array $rows): array {
    $aliases = financeHeaderAliases();
    $bestIndex = -1;
    $bestMap = [];
    foreach (array_slice($rows, 0, 50, true) as $index => $row) {
        $candidate = mapFinanceHeader($row, $aliases);
        if (count($candidate) > count($bestMap)) {
            $bestIndex = (int)$index;
            $bestMap = $candidate;
        }
    }
    return [$bestIndex, $bestMap];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excel_file']) || ($_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash('error', 'Please select a Finance Excel file first.');
    redirect(BASE_URL . '/modules/import/');
}

$file = $_FILES['excel_file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$period = trim($_POST['period'] ?? '');

if (!in_array($ext, ['xlsx', 'csv'], true)) {
    flash('error', 'Finance import supports .xlsx and .csv files.');
    redirect(BASE_URL . '/modules/import/');
}
if (($file['size'] ?? 0) > 20 * 1024 * 1024) {
    flash('error', 'Finance import file must not exceed 20 MB.');
    redirect(BASE_URL . '/modules/import/');
}

$uploadDir = ROOT_PATH . '/uploads/imports/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    flash('error', 'Unable to create the import directory.');
    redirect(BASE_URL . '/modules/import/');
}
$safeOriginalName = basename((string)$file['name']);
$savedName = 'fin_' . date('YmdHis') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $safeOriginalName);
$savedPath = $uploadDir . $savedName;

if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
    flash('error', 'Unable to save the uploaded Finance file.');
    redirect(BASE_URL . '/modules/import/');
}

$db = null;
try {
    $db = getDB();
    if ($ext === 'csv') {
        $rows = [];
        $handle = fopen($savedPath, 'rb');
        if ($handle === false) throw new RuntimeException('Unable to read CSV file.');
        while (($line = fgetcsv($handle)) !== false) $rows[] = $line;
        fclose($handle);
    } else {
        $rows = readXlsx($savedPath);
    }

    if (empty($rows)) throw new RuntimeException('The Finance file is empty.');

    [$headerIdx, $map] = findFinanceHeader($rows);
    if ($headerIdx < 0 || !isset($map['vendor_name']) || (!isset($map['amount']) && !isset($map['net_payable']))) {
        throw new RuntimeException('Could not identify the Finance header row or required Supplier/Amount columns.');
    }

    $get = static fn(array $row, string $field): string => isset($map[$field]) ? trim((string)($row[$map[$field]] ?? '')) : '';
    $dataRows = array_slice($rows, $headerIdx + 1);
    $candidateRows = [];
    foreach ($dataRows as $offset => $row) {
        $hasBusinessData = $get($row, 'vendor_name') !== ''
            || $get($row, 'invoice_no') !== ''
            || $get($row, 'tax_invoice_no') !== ''
            || parseSpreadsheetMoney($get($row, 'amount')) != 0.0
            || parseSpreadsheetMoney($get($row, 'net_payable')) != 0.0
            || $get($row, 'cheque_no') !== '';
        if ($hasBusinessData) {
            $candidateRows[] = ['source_row' => $headerIdx + $offset + 2, 'values' => $row];
        }
    }
    if (empty($candidateRows)) throw new RuntimeException('No payable records were found below the Finance header.');

    $db->beginTransaction();
    $batchNo = generateNo('FIN', 'finance_import_batches', 'batch_no');
    $user = currentUser();
    $db->prepare("INSERT INTO finance_import_batches (batch_no, filename, period, total_records, imported_by, status) VALUES (?,?,?,?,?,'processing')")
        ->execute([$batchNo, $safeOriginalName, $period, count($candidateRows), $user['id']]);
    $batchId = (int)$db->lastInsertId();

    $stmtRec = $db->prepare("INSERT INTO finance_ap_records
        (import_batch_id, vendor_name, invoice_no, tax_invoice_no, invoice_date, due_date, amount, wht_amount, net_payable,
         cheque_date, cheque_bank, cheque_no, paid_date, payment_status, remark, source_row)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmtErr = $db->prepare("INSERT INTO import_error_logs
        (import_batch_id, import_type, row_number, field_name, error_message, raw_data) VALUES (?,?,?,?,?,?)");
    $stmtVendor = $db->prepare("INSERT INTO vendors (vendor_name)
        SELECT ? WHERE NOT EXISTS (SELECT 1 FROM vendors WHERE vendor_name = ? COLLATE NOCASE)");

    $imported = 0;
    $errors = 0;
    foreach ($candidateRows as $candidate) {
        $row = $candidate['values'];
        $sourceRow = (int)$candidate['source_row'];
        $vendorName = $get($row, 'vendor_name');
        if ($vendorName === '') {
            $errors++;
            $stmtErr->execute([$batchId, 'Finance', $sourceRow, 'vendor_name', 'Supplier name is empty', json_encode($row, JSON_UNESCAPED_UNICODE)]);
            continue;
        }

        $amount = parseSpreadsheetMoney($get($row, 'amount'));
        $wht = parseSpreadsheetMoney($get($row, 'wht_amount'));
        $net = parseSpreadsheetMoney($get($row, 'net_payable'));
        if ($net == 0.0 && $amount != 0.0) $net = $amount - $wht;

        $invoiceNo = $get($row, 'invoice_no');
        if ($invoiceNo === '') $invoiceNo = 'FIN-' . $batchId . '-R' . $sourceRow;
        $invoiceDate = normalizeSpreadsheetDate($get($row, 'invoice_date'));
        $dueDate = normalizeSpreadsheetDate($get($row, 'due_date'));
        $chequeDate = normalizeSpreadsheetDate($get($row, 'cheque_date'));
        $paidDate = normalizeSpreadsheetDate($get($row, 'paid_date'));
        $normalizedDates = [
            'invoice_date' => &$invoiceDate,
            'due_date' => &$dueDate,
            'cheque_date' => &$chequeDate,
            'paid_date' => &$paidDate,
        ];
        foreach ($normalizedDates as $field => &$dateValue) {
            if ($dateValue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                $errors++;
                $stmtErr->execute([$batchId, 'Finance', $sourceRow, $field, "Invalid date value: {$dateValue}", json_encode($row, JSON_UNESCAPED_UNICODE)]);
                $dateValue = '';
            }
        }
        unset($dateValue);
        $chequeNo = $get($row, 'cheque_no');
        $status = $get($row, 'payment_status');
        if ($status === '') {
            $status = $paidDate !== '' ? 'Paid' : (($chequeNo !== '' || $chequeDate !== '') ? 'Cheque Prepared' : 'Imported');
        }

        try {
            $stmtRec->execute([
                $batchId, $vendorName, $invoiceNo, $get($row, 'tax_invoice_no'), $invoiceDate, $dueDate,
                $amount, $wht, $net, $chequeDate, $get($row, 'cheque_bank'), $chequeNo, $paidDate,
                $status, $get($row, 'remark'), $sourceRow,
            ]);
            $stmtVendor->execute([$vendorName, $vendorName]);
            $imported++;
        } catch (PDOException $e) {
            $errors++;
            $stmtErr->execute([$batchId, 'Finance', $sourceRow, 'general', $e->getMessage(), json_encode($row, JSON_UNESCAPED_UNICODE)]);
        }
    }

    $status = $errors > 0 ? 'completed_with_errors' : 'completed';
    $db->prepare("UPDATE finance_import_batches SET imported_records=?, error_records=?, status=? WHERE id=?")
        ->execute([$imported, $errors, $status, $batchId]);
    $db->commit();

    auditLog('IMPORT_FINANCE', 'import', $batchId, '', "imported={$imported} errors={$errors}");
    flash('success', "Finance Import completed: {$imported} records, {$errors} errors (Batch: {$batchNo})");
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    @unlink($savedPath);
    flash('error', 'Finance Import failed: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/import/');

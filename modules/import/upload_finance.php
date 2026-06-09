<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/excel_reader.php';
requireLogin();
if (!canAccess('import')) { flash('error','Access denied'); redirect(BASE_URL . '/modules/import/'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['excel_file']['tmp_name'])) {
    flash('error', 'กรุณาเลือกไฟล์ก่อน');
    redirect(BASE_URL . '/modules/import/');
}

$file   = $_FILES['excel_file'];
$ext    = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$period = trim($_POST['period'] ?? '');

if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
    flash('error', 'รองรับเฉพาะไฟล์ .xlsx, .xls, .csv');
    redirect(BASE_URL . '/modules/import/');
}

$uploadDir = ROOT_PATH . '/uploads/imports/';
$savedName = 'fin_' . date('YmdHis') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$savedPath = $uploadDir . $savedName;

if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
    flash('error', 'ไม่สามารถอัปโหลดไฟล์ได้');
    redirect(BASE_URL . '/modules/import/');
}

try {
    $db = getDB();

    if ($ext === 'csv') {
        $rows = [];
        $fp   = fopen($savedPath, 'r');
        while (($line = fgetcsv($fp)) !== false) $rows[] = $line;
        fclose($fp);
    } else {
        $rows = readXlsx($savedPath);
    }

    if (empty($rows)) throw new RuntimeException('ไฟล์ว่างเปล่า ไม่พบข้อมูล');

    // Find header row
    $headerRow = null;
    $headerIdx = 0;
    foreach ($rows as $i => $row) {
        $flat = implode(' ', array_map('strtolower', array_filter($row)));
        if (str_contains($flat, 'vendor') || str_contains($flat, 'invoice') || str_contains($flat, 'due') || str_contains($flat, 'amount') || str_contains($flat, 'เจ้าหนี้')) {
            $headerRow = array_map('trim', $row);
            $headerIdx = $i;
            break;
        }
    }
    if ($headerRow === null) {
        $headerRow = array_map('trim', $rows[0]);
        $headerIdx = 0;
    }

    $keyMap = [
        'vendor_name'    => ['vendorname','vendor_name','vendor name','supplier','ชื่อเจ้าหนี้','เจ้าหนี้'],
        'invoice_no'     => ['invoice_no','invoice no','invoiceno','invoice number','เลขที่ invoice','เลขที่ใบแจ้งหนี้'],
        'invoice_date'   => ['invoice_date','invoice date','invoicedate','วันที่ invoice','วันที่ใบแจ้งหนี้'],
        'due_date'       => ['due_date','due date','duedate','วันครบกำหนด','due'],
        'amount'         => ['amount','จำนวนเงิน','ยอดเงิน','total'],
        'wht_amount'     => ['wht','wht_amount','wht amount','ภาษีหัก ณ ที่จ่าย','หัก ณ ที่จ่าย'],
        'net_payable'    => ['net_payable','net payable','net amount','ยอดสุทธิ','net'],
        'cheque_date'    => ['cheque_date','cheque date','chequedate','วันที่เช็ค','cheque'],
        'cheque_no'      => ['cheque_no','cheque no','chequeno','เลขที่เช็ค'],
        'payment_status' => ['payment_status','payment status','status','สถานะ'],
        'remark'         => ['remark','หมายเหตุ','note','remarks'],
    ];

    $map = [];
    foreach ($headerRow as $colIdx => $hdr) {
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', $hdr)));
        foreach ($keyMap as $field => $aliases) {
            if (in_array($norm, $aliases, true) && !isset($map[$field])) {
                $map[$field] = $colIdx;
            }
        }
    }

    $batchNo = generateNo('FIN', 'finance_import_batches', 'batch_no');
    $user    = currentUser();

    $stmtBatch = $db->prepare("INSERT INTO finance_import_batches (batch_no, filename, period, imported_by) VALUES (?,?,?,?)");
    $stmtBatch->execute([$batchNo, $file['name'], $period, $user['id']]);
    $batchId = $db->lastInsertId();

    $stmtRec = $db->prepare("INSERT INTO finance_ap_records
        (import_batch_id, vendor_name, invoice_no, invoice_date, due_date, amount, wht_amount, net_payable, cheque_date, cheque_no, payment_status, remark)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

    $stmtErr = $db->prepare("INSERT INTO import_error_logs (import_batch_id, import_type, row_number, field_name, error_message, raw_data) VALUES (?,?,?,?,?,?)");

    $imported = 0;
    $errors   = 0;
    $dataRows = array_slice($rows, $headerIdx + 1);
    $get      = fn($row, $field) => isset($map[$field]) ? trim($row[$map[$field]] ?? '') : '';

    foreach ($dataRows as $rIdx => $row) {
        if (count(array_filter($row)) === 0) continue;

        $vendorName = $get($row, 'vendor_name');
        if (empty($vendorName)) {
            $errors++;
            $stmtErr->execute([$batchId, 'Finance', $rIdx + $headerIdx + 2, 'vendor_name', 'Vendor name is empty', implode(',', array_slice($row, 0, 5))]);
            continue;
        }

        $dueDate    = $get($row, 'due_date');
        $invDate    = $get($row, 'invoice_date');
        $cheqDate   = $get($row, 'cheque_date');

        if (looksLikeDate($dueDate))  $dueDate  = excelDateToString($dueDate);
        if (looksLikeDate($invDate))  $invDate  = excelDateToString($invDate);
        if (looksLikeDate($cheqDate)) $cheqDate = excelDateToString($cheqDate);

        $amount     = (float)str_replace(',', '', $get($row, 'amount'));
        $wht        = (float)str_replace(',', '', $get($row, 'wht_amount'));
        $net        = (float)str_replace(',', '', $get($row, 'net_payable'));
        if ($net == 0 && $amount > 0) $net = $amount - $wht;

        $invNo      = $get($row, 'invoice_no') ?: 'FIN-' . $batchId . '-' . ($rIdx + 1);
        $status     = $get($row, 'payment_status') ?: 'Imported';

        try {
            $stmtRec->execute([
                $batchId, $vendorName, $invNo, $invDate, $dueDate,
                $amount, $wht, $net, $cheqDate, $get($row, 'cheque_no'), $status, $get($row, 'remark'),
            ]);
            $imported++;

            // Upsert vendor by name
            $db->prepare("INSERT OR IGNORE INTO vendors (vendor_name) VALUES (?)")->execute([$vendorName]);
        } catch (PDOException $e) {
            $errors++;
            $stmtErr->execute([$batchId, 'Finance', $rIdx + $headerIdx + 2, 'general', $e->getMessage(), implode(',', array_slice($row, 0, 5))]);
        }
    }

    $db->prepare("UPDATE finance_import_batches SET total_records=?, imported_records=?, error_records=?, status='completed' WHERE id=?")
       ->execute([count($dataRows), $imported, $errors, $batchId]);

    auditLog('IMPORT_FINANCE', 'import', $batchId, '', "imported=$imported errors=$errors");
    flash('success', "Finance Import สำเร็จ: {$imported} รายการ, ข้อผิดพลาด: {$errors} รายการ (Batch: {$batchNo})");

} catch (Exception $e) {
    flash('error', 'Import ล้มเหลว: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/import/');

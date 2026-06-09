<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/excel_reader.php';
requireLogin();
if (!canAccess('import')) { flash('error','Access denied'); redirect(BASE_URL . '/modules/import/'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['excel_file']['tmp_name'])) {
    flash('error', 'กรุณาเลือกไฟล์ก่อน');
    redirect(BASE_URL . '/modules/import/');
}

$file     = $_FILES['excel_file'];
$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
    flash('error', 'รองรับเฉพาะไฟล์ .xlsx, .xls, .csv');
    redirect(BASE_URL . '/modules/import/');
}

$uploadDir  = ROOT_PATH . '/uploads/imports/';
$savedName  = 'sap_' . date('YmdHis') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$savedPath  = $uploadDir . $savedName;

if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
    flash('error', 'ไม่สามารถอัปโหลดไฟล์ได้');
    redirect(BASE_URL . '/modules/import/');
}

try {
    $db = getDB();

    // SAP field mapping (auto-detect headers)
    // Expected columns: VendorCode, VendorName, PO_DocNum, GRPO_DocNum, GRPO_Date, GRPO_Total,
    //                   AP_Invoice_DocNum, AP_Invoice_Date, AP_Invoice_Total, AP_Paid_Amount,
    //                   AP_Balance, Payment_DocNum, Payment_Total, Payment_Status

    if ($ext === 'csv') {
        $rows = [];
        $fp   = fopen($savedPath, 'r');
        while (($line = fgetcsv($fp)) !== false) $rows[] = $line;
        fclose($fp);
    } else {
        $rows = readXlsx($savedPath);
    }

    if (empty($rows)) throw new RuntimeException('ไฟล์ว่างเปล่า ไม่พบข้อมูล');

    // Find header row (first non-empty row)
    $headerRow = null;
    $headerIdx = 0;
    foreach ($rows as $i => $row) {
        $flat = implode(' ', array_map('strtolower', $row));
        if (str_contains($flat, 'vendor') || str_contains($flat, 'invoice') || str_contains($flat, 'ap') || str_contains($flat, 'docnum')) {
            $headerRow = array_map('trim', $row);
            $headerIdx = $i;
            break;
        }
    }
    if ($headerRow === null) {
        $headerRow = array_map('trim', $rows[0]);
        $headerIdx = 0;
    }

    // Map header names to indices
    $map = [];
    $keyMap = [
        'vendor_code'       => ['vendorcode','vendor_code','vendor code','cardcode'],
        'vendor_name'       => ['vendorname','vendor_name','vendor name','cardname'],
        'po_doc_num'        => ['po_docnum','po_docnums','po docnum','po docnums','podocnum','podocnums','po no','po number'],
        'grpo_doc_num'      => ['grpo_docnum','grpo docnum','grpodocnum','grpo no'],
        'grpo_date'         => ['grpo_date','grpodate','grpo date'],
        'grpo_total'        => ['grpo_total','grpototal','grpo total','grpo amount'],
        'ap_invoice_doc_num'=> ['ap_invoice_docnum','ap invoice docnum','apinvoicedocnum','invoice no','ap_docnum','ap docnum'],
        'ap_invoice_date'   => ['ap_invoice_date','ap invoice date','invoice date','apinvoicedate'],
        'ap_invoice_total'  => ['ap_invoice_total','ap invoice total','invoice total','ap total','amount'],
        'ap_paid_amount'    => ['ap_paid_amount','ap paid amount','paid amount','paid'],
        'ap_balance'        => ['ap_balance','ap balance','balance','outstanding'],
        'payment_doc_num'   => ['payment_docnum','payment_docnums','payment docnum','payment docnums','paymentdocnum','paymentdocnums','payment no'],
        'payment_total'     => ['payment_total','payment total','paymenttotal'],
        'payment_status'    => ['payment_status','paymentstatus','payment status','status'],
    ];
    foreach ($headerRow as $colIdx => $hdr) {
        $norm = strtolower(trim(preg_replace('/\s+/', ' ', $hdr)));
        foreach ($keyMap as $field => $aliases) {
            if (in_array($norm, $aliases, true) && !isset($map[$field])) {
                $map[$field] = $colIdx;
            }
        }
    }

    $batchNo = generateNo('SAP', 'sap_import_batches', 'batch_no');
    $user    = currentUser();

    $stmtBatch = $db->prepare("INSERT INTO sap_import_batches (batch_no, filename, imported_by) VALUES (?, ?, ?)");
    $stmtBatch->execute([$batchNo, $file['name'], $user['id']]);
    $batchId = $db->lastInsertId();

    $stmtInv = $db->prepare("INSERT OR IGNORE INTO sap_ap_invoices
        (import_batch_id, vendor_code, vendor_name, po_doc_num, grpo_doc_num, grpo_date, grpo_total,
         ap_invoice_doc_num, ap_invoice_date, ap_invoice_total, ap_paid_amount, ap_balance,
         payment_doc_num, payment_total, payment_status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $stmtErr = $db->prepare("INSERT INTO import_error_logs (import_batch_id, import_type, row_number, field_name, error_message, raw_data) VALUES (?,?,?,?,?,?)");

    $imported = 0;
    $errors   = 0;
    $dataRows = array_slice($rows, $headerIdx + 1);

    $get = fn($row, $field) => isset($map[$field]) ? trim($row[$map[$field]] ?? '') : '';

    foreach ($dataRows as $rIdx => $row) {
        if (count(array_filter($row)) === 0) continue;

        $grpoDate = $get($row, 'grpo_date');
        if (looksLikeDate($grpoDate)) $grpoDate = excelDateToString($grpoDate);

        $invDate = $get($row, 'ap_invoice_date');
        if (looksLikeDate($invDate)) $invDate = excelDateToString($invDate);

        $invTotal  = (float)str_replace(',', '', $get($row, 'ap_invoice_total'));
        $paidAmt   = (float)str_replace(',', '', $get($row, 'ap_paid_amount'));
        $balance   = (float)str_replace(',', '', $get($row, 'ap_balance'));
        $grpoTotal = (float)str_replace(',', '', $get($row, 'grpo_total'));
        $payTotal  = (float)str_replace(',', '', $get($row, 'payment_total'));
        $status    = $get($row, 'payment_status') ?: 'Imported';

        $vendorCode = $get($row, 'vendor_code');
        $vendorName = $get($row, 'vendor_name');
        $invNo = $get($row, 'ap_invoice_doc_num');

        if (empty($vendorName)) {
            $errors++;
            $stmtErr->execute([$batchId, 'SAP', $rIdx + $headerIdx + 2, 'vendor_name', 'Vendor name is empty', implode(',', $row)]);
            continue;
        }

        if (empty($invNo)) {
            $invNo = 'IMPORT-' . $batchId . '-' . ($rIdx + 1);
        }

        try {
            $stmtInv->execute([
                $batchId, $vendorCode, $vendorName,
                $get($row, 'po_doc_num'), $get($row, 'grpo_doc_num'), $grpoDate, $grpoTotal,
                $invNo, $invDate, $invTotal, $paidAmt, $balance,
                $get($row, 'payment_doc_num'), $payTotal, $status,
            ]);
            if ($stmtInv->rowCount() > 0) {
                $imported++;
                // Upsert vendor
                if ($vendorCode || $vendorName) {
                    $db->prepare("
                        INSERT INTO vendors (vendor_code, vendor_name)
                        VALUES (?, ?)
                        ON CONFLICT(vendor_code) DO UPDATE SET
                            vendor_name = excluded.vendor_name,
                            updated_at = datetime('now','localtime')
                    ")->execute([$vendorCode ?: null, $vendorName]);
                }
            }
        } catch (PDOException $e) {
            $errors++;
            $stmtErr->execute([$batchId, 'SAP', $rIdx + $headerIdx + 2, 'ap_invoice_doc_num', $e->getMessage(), implode(',', array_slice($row, 0, 5))]);
        }
    }

    // Update batch
    $db->prepare("UPDATE sap_import_batches SET total_records=?, imported_records=?, error_records=?, status='completed' WHERE id=?")
       ->execute([count($dataRows), $imported, $errors, $batchId]);

    auditLog('IMPORT_SAP', 'import', $batchId, '', "imported=$imported errors=$errors");

    flash('success', "SAP Import สำเร็จ: {$imported} รายการ, ข้อผิดพลาด: {$errors} รายการ (Batch: {$batchNo})");

} catch (Exception $e) {
    flash('error', 'Import ล้มเหลว: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/import/');

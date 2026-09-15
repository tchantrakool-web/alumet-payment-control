<?php

require_once __DIR__ . '/../../config/bootstrap.php';
require_once ROOT_PATH . '/config/sap_importer.php';

requireLogin();
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !verifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)
) {
    flash('error', 'Your session token expired. Please try again.');
    redirect(BASE_URL . '/modules/import/');
}
if (!canEdit('import')) {
    flash('error', 'Import Center is read-only for your role.');
    redirect(BASE_URL . '/modules/import/');
}

$returnUrl = '/modules/import/';
if (!empty($_POST['return_url'])) {
    $candidate = trim((string)$_POST['return_url']);
    if (str_starts_with($candidate, BASE_URL)) {
        $candidate = substr($candidate, strlen(BASE_URL));
    }
    if (str_starts_with($candidate, '/')) {
        $returnUrl = $candidate;
    }
}
$redirectUrl = BASE_URL . $returnUrl;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['excel_file']['tmp_name'])) {
    flash('error', 'กรุณาเลือกไฟล์ก่อน');
    redirect($redirectUrl);
}

$file = $_FILES['excel_file'];
$ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'csv'], true)) {
    flash('error', 'รองรับเฉพาะไฟล์ .xlsx และ .csv');
    redirect($redirectUrl);
}

$uploadDir = ROOT_PATH . '/uploads/imports/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    flash('error', 'ไม่สามารถสร้างโฟลเดอร์อัปโหลดได้');
    redirect($redirectUrl);
}

$savedName = 'sap_' . date('YmdHis') . '_' .
    preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$file['name']);
$savedPath = $uploadDir . $savedName;
if (!move_uploaded_file((string)$file['tmp_name'], $savedPath)) {
    flash('error', 'ไม่สามารถอัปโหลดไฟล์ได้');
    redirect($redirectUrl);
}

try {
    $user = currentUser();
    $result = importSapPurchaseFile(
        getDB(),
        $savedPath,
        (string)$file['name'],
        isset($user['id']) ? (int)$user['id'] : null
    );
    $processed = $result['inserted_records'] + $result['updated_records'];

    auditLog(
        'IMPORT_SAP',
        'import',
        $result['batch_id'],
        '',
        "processed={$processed} errors={$result['error_records']}"
    );
    flash(
        'success',
        "SAP Import สำเร็จ: {$processed} รายการ, ข้อผิดพลาด: " .
        "{$result['error_records']} รายการ (Batch: {$result['batch_no']})"
    );
} catch (Throwable $error) {
    flash('error', 'Import ล้มเหลว: ' . $error->getMessage());
}

redirect($redirectUrl);

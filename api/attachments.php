<?php
require_once __DIR__ . '/../config/bootstrap.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    flash('error', 'Invalid request');
    redirect(BASE_URL . '/dashboard.php');
}

$relatedType = trim($_POST['related_type'] ?? '');
$relatedId   = (int)($_POST['related_id'] ?? 0);
$docType     = trim($_POST['doc_type'] ?? 'Other');

if (!$relatedType || !$relatedId) {
    flash('error', 'Invalid attachment target');
    redirect(BASE_URL . '/dashboard.php');
}

if (empty($_FILES['attachment']['tmp_name'])) {
    flash('error', 'กรุณาเลือกไฟล์');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/dashboard.php');
}

$file = $_FILES['attachment'];
$allowedExt = ['pdf','png','jpg','jpeg','xlsx','xls','doc','docx'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExt)) {
    flash('error', 'ประเภทไฟล์ไม่รองรับ');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/dashboard.php');
}

if ($file['size'] > 10 * 1024 * 1024) {
    flash('error', 'ไฟล์ใหญ่เกิน 10MB');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/dashboard.php');
}

$uploadDir  = ROOT_PATH . '/uploads/attachments/';
$savedName  = uniqid('att_', true) . '.' . $ext;
$savedPath  = $uploadDir . $savedName;

if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
    flash('error', 'ไม่สามารถอัปโหลดไฟล์ได้');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/dashboard.php');
}

$db   = getDB();
$user = currentUser();

$db->prepare("INSERT INTO attachments (related_type, related_id, filename, original_filename, file_type, file_size, document_type, uploaded_by) VALUES (?,?,?,?,?,?,?,?)")
   ->execute([$relatedType, $relatedId, $savedName, $file['name'], $file['type'], $file['size'], $docType, $user['id']]);

auditLog('UPLOAD_ATTACHMENT', $relatedType, $relatedId, '', $file['name']);
flash('success', 'อัปโหลดเอกสารสำเร็จ');

$referer = $_SERVER['HTTP_REFERER'] ?? BASE_URL . '/dashboard.php';
redirect($referer);

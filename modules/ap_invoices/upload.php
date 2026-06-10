<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!canAccess('import')) {
    flash('error', 'Access denied');
    redirect(BASE_URL . '/modules/ap_invoices/');
}

$pageTitle = 'Upload SAP AP Invoices';
include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-2xl font-bold text-gray-800">Upload SAP AP Invoices</h1>
    <p class="mt-1 text-sm text-gray-500">นำเข้าข้อมูล SAP AP Invoice เพื่อใช้สร้าง Payment Request ได้ทันที</p>
  </div>
  <a href="<?= BASE_URL ?>/modules/ap_invoices/"
     class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-medium">
    &larr; Back to AP Invoice Queue
  </a>
</div>

<div class="bg-white rounded-xl border p-6 shadow-sm max-w-2xl">
  <form method="POST" action="<?= BASE_URL ?>/modules/import/upload_sap.php" enctype="multipart/form-data">
    <input type="hidden" name="return_url" value="/modules/ap_invoices/upload.php">
    <div class="mb-5">
      <label class="block text-sm font-medium text-gray-700 mb-2">SAP AP Invoice File</label>
      <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-blue-400 transition-colors">
        <input type="file" name="excel_file" id="sapUploadFile" accept=".xlsx,.xls,.csv" class="hidden"
               onchange="document.getElementById('sapUploadLabel').textContent = this.files[0]?.name || 'ยังไม่ได้เลือกไฟล์'">
        <label for="sapUploadFile" class="cursor-pointer">
          <div class="text-3xl">📁</div>
          <p id="sapUploadLabel" class="mt-3 text-sm text-gray-600">คลิกเพื่อเลือกไฟล์ Excel (.xlsx, .xls, .csv)</p>
          <p class="mt-2 text-xs text-gray-400">รองรับการนำเข้าข้อมูล AP Invoice จาก SAP B1</p>
        </label>
      </div>
    </div>

    <div class="text-sm text-gray-500 mb-5">
      <p>ไฟล์ต้องมีข้อมูล AP Invoice ที่สำคัญ เช่น Vendor Code, Vendor Name, PO No., GRPO No., Invoice No., Invoice Date, Invoice Total, Paid Amount, Balance, Payment Doc. หากไม่แน่ใจให้เปิดดูตัวอย่างที่ทีม SAP ส่งมา</p>
    </div>

    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-2 rounded-lg text-sm font-medium transition-colors">
      Upload SAP AP Invoice
    </button>
  </form>
</div>

<?php include ROOT_PATH . '/layouts/footer.php';

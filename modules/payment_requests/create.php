<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!hasRole('admin','maker','finance_manager')) { flash('error','Access denied'); redirect(BASE_URL . '/modules/payment_requests/'); }
$pageTitle = 'Create Payment Request';
$db = getDB();

$preInvoiceId = (int)($_GET['invoice_id'] ?? 0);
$preInvoice = null;
if ($preInvoiceId) {
    $st = $db->prepare("SELECT * FROM sap_ap_invoices WHERE id=? AND is_deleted=0");
    $st->execute([$preInvoiceId]);
    $preInvoice = $st->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendorId     = (int)($_POST['vendor_id'] ?? 0);
    $vendorName   = trim($_POST['vendor_name'] ?? '');
    $vendorCode   = trim($_POST['vendor_code'] ?? '');
    $dueDate      = trim($_POST['due_date'] ?? '');
    $payMethod    = trim($_POST['payment_method'] ?? 'cheque');
    $priority     = trim($_POST['priority'] ?? 'normal');
    $note         = trim($_POST['note'] ?? '');
    $invoiceIds   = array_values(array_filter($_POST['invoice_ids'] ?? [], static fn($id) => (int)$id > 0));

    if (empty($dueDate)) { flash('error','Please specify Due Date'); redirect(BASE_URL . '/modules/payment_requests/create.php'); }
    if (empty($invoiceIds)) { flash('error','Please select at least 1 AP Invoice'); redirect(BASE_URL . '/modules/payment_requests/create.php'); }

    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $selInvoices  = $db->prepare("SELECT * FROM sap_ap_invoices WHERE id IN ($placeholders) AND is_deleted=0");
    $selInvoices->execute($invoiceIds);
    $selInvoices  = $selInvoices->fetchAll();

    if (empty($selInvoices)) {
        flash('error', 'Selected AP invoices were not found');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }

    $vendorNames = array_values(array_unique(array_map(static fn($inv) => trim((string)$inv['vendor_name']), $selInvoices)));
    if (count($vendorNames) > 1) {
        flash('error', 'Please select AP invoices from the same vendor only');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }

    $vendorName = $vendorName ?: ($selInvoices[0]['vendor_name'] ?? '');
    $vendorCode = $vendorCode ?: ($selInvoices[0]['vendor_code'] ?? '');

    $reqNo = generateNo('PR', 'payment_requests', 'request_no');
    $user  = currentUser();

    $totalAmt = array_sum(array_map(static fn($inv) => (float)$inv['ap_balance'], $selInvoices));
    $whtAmt   = 0;
    $netPayable = $totalAmt - $whtAmt;

    $stmtPR = $db->prepare("INSERT INTO payment_requests
        (request_no, vendor_id, vendor_code, vendor_name, total_amount, wht_amount, net_payable, due_date, payment_method, priority, note, status, created_by, submitted_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'Waiting Check',?,datetime('now','localtime'))");
    $stmtPR->execute([$reqNo, $vendorId ?: null, $vendorCode, $vendorName, $totalAmt, $whtAmt, $netPayable, $dueDate, $payMethod, $priority, $note, $user['id']]);
    $prId = $db->lastInsertId();

    $stmtItem = $db->prepare("INSERT INTO payment_request_items (payment_request_id, sap_invoice_id, ap_invoice_no, invoice_date, invoice_amount, net_amount, due_date) VALUES (?,?,?,?,?,?,?)");
    foreach ($selInvoices as $inv) {
        $stmtItem->execute([$prId, $inv['id'], $inv['ap_invoice_doc_num'], $inv['ap_invoice_date'], $inv['ap_invoice_total'], $inv['ap_balance'], $dueDate]);
        $db->prepare("UPDATE sap_ap_invoices SET payment_status='Waiting Check', updated_at=datetime('now','localtime') WHERE id=?")->execute([$inv['id']]);
    }

    auditLog('CREATE', 'payment_requests', $prId, '', "status=Waiting Check vendor=$vendorName amount=$netPayable");
    flash('success', "Payment Request {$reqNo} created successfully");
    redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $prId);
}

$vendors = $db->query("SELECT * FROM vendors WHERE is_active=1 ORDER BY vendor_name")->fetchAll();
$vendorMap = [];
foreach ($vendors as $vendor) {
    $key = trim((string)($vendor['vendor_code'] ?? '')) . '|' . trim((string)$vendor['vendor_name']);
    $vendorMap[$key] = (int)$vendor['id'];
}

$availableStatuses = ['Imported', 'pending', 'ยังไม่ได้จ่ายเงิน', 'Unpaid', 'Outstanding'];
$placeholders = implode(',', array_fill(0, count($availableStatuses), '?'));
$invoicesStmt = $db->prepare("
    SELECT *
    FROM sap_ap_invoices
    WHERE is_deleted = 0
      AND ap_balance > 0
      AND (
            payment_status IN ($placeholders)
            OR payment_status IS NULL
            OR payment_status = ''
          )
    ORDER BY vendor_name, ap_invoice_date, ap_invoice_doc_num
");
$invoicesStmt->execute($availableStatuses);
$invoices = $invoicesStmt->fetchAll();

$invByVendor = [];
foreach ($invoices as $inv) {
    $key = trim((string)$inv['vendor_name']);
    if ($key === '') {
        $key = '(No vendor name)';
    }
    if (!isset($invByVendor[$key])) {
        $invByVendor[$key] = [
            'vendor_name' => $inv['vendor_name'],
            'vendor_code' => $inv['vendor_code'],
            'vendor_id' => $vendorMap[trim((string)($inv['vendor_code'] ?? '')) . '|' . trim((string)($inv['vendor_name'] ?? ''))] ?? 0,
            'invoice_count' => 0,
            'total_balance' => 0,
            'items' => [],
        ];
    }
    $invByVendor[$key]['invoice_count']++;
    $invByVendor[$key]['total_balance'] += (float)$inv['ap_balance'];
    $invByVendor[$key]['items'][] = $inv;
}

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5">
  <a href="<?= BASE_URL ?>/modules/payment_requests/" class="text-sm text-gray-500 hover:text-gray-700">← Payment Requests</a>
  <h1 class="text-2xl font-bold text-gray-800 mt-1">Create Payment Request</h1>
  <p class="text-sm text-gray-500 mt-1">Finance staff can pick unpaid AP invoices by vendor and submit one vendor per payment request.</p>
</div>

<form method="POST" x-data='prForm(<?= json_encode($vendorMap, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($preInvoice ? [[
    'id' => (int)$preInvoice['id'],
    'amount' => (float)$preInvoice['ap_balance'],
    'vendor' => $preInvoice['vendor_name'],
    'code' => $preInvoice['vendor_code'] ?? '',
]] : [], JSON_UNESCAPED_UNICODE) ?>)' class="grid grid-cols-1 lg:grid-cols-3 gap-5">
  <div class="lg:col-span-2 space-y-4">
    <div class="bg-white rounded-xl border p-5">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <div>
          <h3 class="font-semibold text-gray-700">Unpaid AP Invoices by Vendor</h3>
          <p class="text-xs text-gray-500 mt-1"><?= count($invoices) ?> unpaid AP invoice(s) across <?= count($invByVendor) ?> vendor(s)</p>
        </div>
      </div>

      <div class="mb-4">
        <input type="text" x-model="vendorFilter" placeholder="Filter by vendor..."
               class="theme-input border border-gray-300 rounded-lg px-3 py-2 text-sm w-full">
      </div>

      <div class="space-y-3 max-h-[720px] overflow-y-auto pr-1">
        <?php foreach ($invByVendor as $groupIndex => $group): ?>
        <?php
          $vendorNameLower = strtolower((string)($group['vendor_name'] ?? ''));
          $vendorCodeLower = strtolower((string)($group['vendor_code'] ?? ''));
          $groupKey = 'vendor_' . $groupIndex;
        ?>
        <div class="rounded-xl border overflow-hidden"
             x-show="!vendorFilter || '<?= addslashes($vendorNameLower) ?>'.includes(vendorFilter.toLowerCase()) || '<?= addslashes($vendorCodeLower) ?>'.includes(vendorFilter.toLowerCase())">
          <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" style="background:#F7FBF8;">
            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="toggleVendorGroup('<?= $groupKey ?>')">
              <span class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-gray-200 bg-white text-xs font-bold text-gray-500" x-text="isVendorOpen('<?= $groupKey ?>') ? '-' : '+'"></span>
              <span class="min-w-0">
                <span class="block truncate font-semibold text-gray-800"><?= h($group['vendor_name'] ?: '(No vendor name)') ?></span>
                <span class="block text-xs text-gray-500"><?= h($group['vendor_code'] ?: '-') ?> · <?= $group['invoice_count'] ?> invoice(s)</span>
              </span>
            </button>
            <div class="flex items-center gap-3">
              <div class="text-right">
                <div class="text-xs text-gray-500">Outstanding</div>
                <div class="font-semibold text-orange-600"><?= fmtMoney($group['total_balance']) ?></div>
              </div>
              <button type="button"
                      class="theme-btn-secondary rounded-lg px-3 py-1.5 text-xs font-medium"
                      @click.stop='selectVendorInvoices("<?= $groupKey ?>", <?= htmlspecialchars(json_encode(array_map(static fn($item) => [
                          'id' => (int)$item['id'],
                          'amount' => (float)$item['ap_balance'],
                          'vendor' => $item['vendor_name'],
                          'code' => $item['vendor_code'] ?? '',
                      ], $group['items'])), ENT_QUOTES, 'UTF-8') ?>)'>
                Select Vendor
              </button>
            </div>
          </div>

          <div class="overflow-x-auto" x-show="isVendorOpen('<?= $groupKey ?>')">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-gray-500 border-b bg-gray-50">
                  <th class="px-3 py-2 w-8"></th>
                  <th class="px-3 py-2">Invoice No.</th>
                  <th class="px-3 py-2">Invoice Date</th>
                  <th class="px-3 py-2">PO / GRPO</th>
                  <th class="px-3 py-2">Status</th>
                  <th class="px-3 py-2 text-right">Outstanding</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($group['items'] as $inv): ?>
                <tr class="border-b last:border-0 hover:bg-blue-50 cursor-pointer"
                    @click="toggleInvoice(<?= (int)$inv['id'] ?>, <?= (float)$inv['ap_balance'] ?>, '<?= addslashes($inv['vendor_name']) ?>', '<?= addslashes($inv['vendor_code'] ?? '') ?>')">
                  <td class="px-3 py-2">
                    <input type="checkbox" name="invoice_ids[]" value="<?= (int)$inv['id'] ?>"
                           :checked="selected.includes(<?= (int)$inv['id'] ?>)"
                           @click.stop="toggleInvoice(<?= (int)$inv['id'] ?>, <?= (float)$inv['ap_balance'] ?>, '<?= addslashes($inv['vendor_name']) ?>', '<?= addslashes($inv['vendor_code'] ?? '') ?>')"
                           <?= $preInvoice && (int)$preInvoice['id'] === (int)$inv['id'] ? 'checked' : '' ?>>
                  </td>
                  <td class="px-3 py-2 font-mono text-xs"><?= h($inv['ap_invoice_doc_num']) ?></td>
                  <td class="px-3 py-2 text-xs"><?= fmtDate($inv['ap_invoice_date']) ?></td>
                  <td class="px-3 py-2 text-xs text-gray-500">
                    <?= h($inv['po_doc_num'] ?: '-') ?><br>
                    <span class="text-gray-400"><?= h($inv['grpo_doc_num'] ?: '') ?></span>
                  </td>
                  <td class="px-3 py-2 text-xs text-gray-500"><?= h($inv['payment_status'] ?: '-') ?></td>
                  <td class="px-3 py-2 text-right font-semibold text-orange-600"><?= fmtMoney($inv['ap_balance']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($invoices)): ?>
        <div class="rounded-xl border bg-white px-4 py-8 text-center text-gray-400">
          No unpaid AP invoices are available for creating a payment request.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="bg-white rounded-xl border p-5">
      <h3 class="font-semibold text-gray-700 mb-4">Request Details</h3>

      <div class="mb-3">
        <label class="block text-xs text-gray-500 mb-1">Vendor <span class="text-red-500">*</span></label>
        <input type="text" name="vendor_name" x-model="vendorName" readonly
               class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm" placeholder="Select unpaid invoices first">
        <input type="hidden" name="vendor_code" x-model="vendorCode">
        <input type="hidden" name="vendor_id" x-model="vendorId">
      </div>

      <div class="mb-3">
        <label class="block text-xs text-gray-500 mb-1">Vendor Code</label>
        <input type="text" x-model="vendorCode" readonly
               class="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2 text-sm" placeholder="Vendor code will appear here">
      </div>

      <div class="mb-3">
        <label class="block text-xs text-gray-500 mb-1">Due Date <span class="text-red-500">*</span></label>
        <input type="date" name="due_date" required class="theme-input w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
               value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
      </div>

      <div class="mb-3">
        <label class="block text-xs text-gray-500 mb-1">Payment Method</label>
        <select name="payment_method" class="theme-input w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <option value="cheque">Cheque</option>
          <option value="transfer">Bank Transfer</option>
          <option value="cash">Cash</option>
        </select>
      </div>

      <div class="mb-3">
        <label class="block text-xs text-gray-500 mb-1">Priority</label>
        <select name="priority" class="theme-input w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <option value="normal">Normal</option>
          <option value="urgent">Urgent</option>
          <option value="low">Low</option>
        </select>
      </div>

      <div class="mb-4">
        <label class="block text-xs text-gray-500 mb-1">Note</label>
        <textarea name="note" rows="3" class="theme-input w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
                  placeholder="Additional note..."></textarea>
      </div>

      <div class="rounded-lg p-3 mb-4" style="background:#ECF1F7;">
        <div class="flex justify-between text-sm mb-1">
          <span class="text-gray-600">Selected Items:</span>
          <span class="font-semibold" x-text="selected.length"></span>
        </div>
        <div class="flex justify-between text-sm mb-1">
          <span class="text-gray-600">Selected Vendor:</span>
          <span class="font-medium text-right" x-text="vendorName || '-'"></span>
        </div>
        <div class="flex justify-between text-sm mb-1">
          <span class="text-gray-600">Vendor Code:</span>
          <span class="font-medium text-right" x-text="vendorCode || '-'"></span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Outstanding Total:</span>
          <span class="font-bold" style="color:#003B5C;" x-text="'฿' + totalAmount.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})"></span>
        </div>
      </div>

      <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        One payment request should contain AP invoices from one vendor only.
      </div>

      <button type="submit" :disabled="selected.length === 0 || !vendorName"
              class="theme-btn-primary w-full disabled:bg-gray-300 text-white py-2.5 rounded-lg font-medium text-sm transition-colors">
        Submit to Checker
      </button>
    </div>
  </div>
</form>

<script>
function prForm(vendorMap, preselected) {
    const state = {
        selected: [],
        selectedAmounts: {},
        totalAmount: 0,
        vendorName: '',
        vendorCode: '',
        vendorId: 0,
        vendorFilter: '',
        expandedVendors: {},
        setVendor(vendor, code) {
            this.vendorName = vendor || '';
            this.vendorCode = code || '';
            this.vendorId = vendorMap[(this.vendorCode || '') + '|' + (this.vendorName || '')] || 0;
        },
        clearSelection() {
            this.selected = [];
            this.selectedAmounts = {};
            this.totalAmount = 0;
            this.setVendor('', '');
        },
        syncTotal() {
            this.totalAmount = Object.values(this.selectedAmounts).reduce((sum, amount) => sum + amount, 0);
        },
        toggleVendorGroup(groupKey) {
            this.expandedVendors[groupKey] = !this.isVendorOpen(groupKey);
        },
        isVendorOpen(groupKey) {
            return this.expandedVendors[groupKey] !== false;
        },
        toggleInvoice(id, amount, vendor, code) {
            const idx = this.selected.indexOf(id);
            if (idx >= 0) {
                this.selected.splice(idx, 1);
                delete this.selectedAmounts[id];
                if (this.selected.length === 0) {
                    this.setVendor('', '');
                }
                this.syncTotal();
                return;
            }

            if (this.vendorName && this.vendorName !== vendor) {
                alert('Please select invoices from the same vendor only.');
                return;
            }

            if (!this.vendorName) {
                this.setVendor(vendor, code);
            }

            this.selected.push(id);
            this.selectedAmounts[id] = Number(amount);
            this.syncTotal();
        },
        selectVendorInvoices(groupKey, items) {
            if (!items.length) return;
            const vendor = items[0].vendor || '';
            const code = items[0].code || '';
            if (this.vendorName && this.vendorName !== vendor) {
                if (!confirm('This will replace your current selection with another vendor. Continue?')) {
                    return;
                }
            }

            this.clearSelection();
            this.setVendor(vendor, code);
            this.expandedVendors[groupKey] = true;
            items.forEach(item => {
                this.selected.push(item.id);
                this.selectedAmounts[item.id] = Number(item.amount);
            });
            this.syncTotal();
        }
    };

    if (Array.isArray(preselected) && preselected.length) {
        state.selectVendorInvoices('vendor_0', preselected);
    }

    return state;
}
</script>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>

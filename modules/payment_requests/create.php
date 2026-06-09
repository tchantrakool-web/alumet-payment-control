<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();
if (!hasRole('admin', 'maker', 'finance_manager')) {
    flash('error', 'Access denied');
    redirect(BASE_URL . '/modules/payment_requests/');
}

$pageTitle = 'Create Payment Request';
$db = getDB();

$preInvoiceId = (int)($_GET['invoice_id'] ?? 0);
$preInvoice = null;
if ($preInvoiceId) {
    $stmt = $db->prepare("SELECT * FROM sap_ap_invoices WHERE id = ? AND is_deleted = 0");
    $stmt->execute([$preInvoiceId]);
    $preInvoice = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendorId = (int)($_POST['vendor_id'] ?? 0);
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $vendorCode = trim($_POST['vendor_code'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');
    $payMethod = trim($_POST['payment_method'] ?? 'cheque');
    $priority = trim($_POST['priority'] ?? 'normal');
    $note = trim($_POST['note'] ?? '');
    $whtApplicable = !empty($_POST['wht_applicable']) ? 1 : 0;
    $whtRate = max(0, (float)($_POST['wht_rate'] ?? 0));
    $postedWhtAmount = max(0, (float)($_POST['wht_amount'] ?? 0));
    $postedWhtBase = max(0, (float)($_POST['wht_base_amount'] ?? 0));
    $taxInvoiceRequired = !empty($_POST['tax_invoice_required']) ? 1 : 0;
    $invoiceIds = array_values(array_filter($_POST['invoice_ids'] ?? [], static fn($id) => (int)$id > 0));

    if ($dueDate === '') {
        flash('error', 'Please specify due date');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }
    if (empty($invoiceIds)) {
        flash('error', 'Please select at least one AP invoice');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }

    $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
    $stmtInvoices = $db->prepare("SELECT * FROM sap_ap_invoices WHERE id IN ($placeholders) AND is_deleted = 0");
    $stmtInvoices->execute($invoiceIds);
    $selectedInvoices = $stmtInvoices->fetchAll();

    if (empty($selectedInvoices)) {
        flash('error', 'Selected AP invoices were not found');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }

    $vendorNames = array_values(array_unique(array_map(static fn($inv) => trim((string)$inv['vendor_name']), $selectedInvoices)));
    if (count($vendorNames) > 1) {
        flash('error', 'Please select AP invoices from the same vendor only');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }

    $vendorName = $vendorName !== '' ? $vendorName : (string)($selectedInvoices[0]['vendor_name'] ?? '');
    $vendorCode = $vendorCode !== '' ? $vendorCode : (string)($selectedInvoices[0]['vendor_code'] ?? '');

    $grossAmount = array_sum(array_map(static fn($inv) => (float)$inv['ap_balance'], $selectedInvoices));
    $whtBaseAmount = $postedWhtBase > 0 ? $postedWhtBase : $grossAmount;
    $whtAmount = $whtApplicable ? $postedWhtAmount : 0.0;
    if ($whtApplicable && $whtAmount <= 0 && $whtRate > 0) {
        $whtAmount = round(($whtBaseAmount * $whtRate) / 100, 2);
    }
    if (!$whtApplicable) {
        $whtRate = 0;
        $whtBaseAmount = 0;
        $whtAmount = 0;
    }

    $netPayable = round($grossAmount - $whtAmount, 2);
    if ($whtApplicable && $whtAmount <= 0) {
        flash('error', 'Please provide WHT rate or WHT amount');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }
    if ($netPayable < 0) {
        flash('error', 'Net payable cannot be negative');
        redirect(BASE_URL . '/modules/payment_requests/create.php');
    }

    $hasPo = 1;
    $hasGrn = 1;
    foreach ($selectedInvoices as $invoice) {
        if (trim((string)($invoice['po_doc_num'] ?? '')) === '') {
            $hasPo = 0;
        }
        if (trim((string)($invoice['grpo_doc_num'] ?? '')) === '') {
            $hasGrn = 0;
        }
    }

    $requestNo = generateNo('PR', 'payment_requests', 'request_no');
    $user = currentUser();

    $stmtRequest = $db->prepare("
        INSERT INTO payment_requests (
            request_no, vendor_id, vendor_code, vendor_name,
            total_amount, gross_amount, wht_applicable, wht_rate, wht_base_amount, wht_amount, net_payable,
            due_date, payment_method, status, priority, note,
            tax_invoice_required, has_po, has_grn, created_by, submitted_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, datetime('now','localtime')
        )
    ");
    $stmtRequest->execute([
        $requestNo,
        $vendorId ?: null,
        $vendorCode,
        $vendorName,
        $grossAmount,
        $grossAmount,
        $whtApplicable,
        $whtRate,
        $whtBaseAmount,
        $whtAmount,
        $netPayable,
        $dueDate,
        $payMethod,
        'Pending Documents',
        $priority,
        $note,
        $taxInvoiceRequired,
        $hasPo,
        $hasGrn,
        $user['id'],
    ]);
    $paymentRequestId = (int)$db->lastInsertId();

    $stmtItem = $db->prepare("
        INSERT INTO payment_request_items (
            payment_request_id, sap_invoice_id, ap_invoice_no, invoice_date,
            invoice_amount, wht_amount, net_amount, due_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $remainingWht = $whtAmount;
    $itemCount = count($selectedInvoices);
    foreach ($selectedInvoices as $index => $invoice) {
        $invoiceBalance = (float)$invoice['ap_balance'];
        $itemWht = 0.0;
        if ($whtApplicable && $grossAmount > 0) {
            if ($index === $itemCount - 1) {
                $itemWht = round($remainingWht, 2);
            } else {
                $itemWht = round(($whtAmount * $invoiceBalance) / $grossAmount, 2);
                $remainingWht -= $itemWht;
            }
        }
        $itemNet = max(0, round($invoiceBalance - $itemWht, 2));

        $stmtItem->execute([
            $paymentRequestId,
            $invoice['id'],
            $invoice['ap_invoice_doc_num'],
            $invoice['ap_invoice_date'],
            $invoice['ap_invoice_total'],
            $itemWht,
            $itemNet,
            $dueDate,
        ]);

        $db->prepare("UPDATE sap_ap_invoices SET payment_status = 'Pending Documents', updated_at = datetime('now','localtime') WHERE id = ?")
            ->execute([$invoice['id']]);
    }

    auditLog('CREATE', 'payment_requests', $paymentRequestId, '', "status=Pending Documents vendor={$vendorName} amount={$netPayable}");
    flash('success', "Payment Request {$requestNo} created successfully");
    redirect(BASE_URL . '/modules/payment_requests/detail.php?id=' . $paymentRequestId);
}

$vendors = $db->query("SELECT * FROM vendors WHERE is_active = 1 ORDER BY vendor_name")->fetchAll();
$vendorMap = [];
foreach ($vendors as $vendor) {
    $key = trim((string)($vendor['vendor_code'] ?? '')) . '|' . trim((string)$vendor['vendor_name']);
    $vendorMap[$key] = (int)$vendor['id'];
}

$stmtInvoices = $db->prepare("
    SELECT *
    FROM sap_ap_invoices
    WHERE is_deleted = 0
      AND ap_balance > 0
      AND COALESCE(payment_status, '') NOT IN ('Pending Documents', 'Paid', 'Rejected', 'Cancelled')
    ORDER BY vendor_name, ap_invoice_date, ap_invoice_doc_num
");
$stmtInvoices->execute();
$invoices = $stmtInvoices->fetchAll();

$invoicesByVendor = [];
foreach ($invoices as $invoice) {
    $groupKey = trim((string)$invoice['vendor_name']);
    if ($groupKey === '') {
        $groupKey = '(No vendor name)';
    }
    if (!isset($invoicesByVendor[$groupKey])) {
        $vendorKey = trim((string)($invoice['vendor_code'] ?? '')) . '|' . trim((string)($invoice['vendor_name'] ?? ''));
        $invoicesByVendor[$groupKey] = [
            'vendor_name' => $invoice['vendor_name'],
            'vendor_code' => $invoice['vendor_code'],
            'vendor_id' => $vendorMap[$vendorKey] ?? 0,
            'invoice_count' => 0,
            'total_balance' => 0,
            'items' => [],
        ];
    }
    $invoicesByVendor[$groupKey]['invoice_count']++;
    $invoicesByVendor[$groupKey]['total_balance'] += (float)$invoice['ap_balance'];
    $invoicesByVendor[$groupKey]['items'][] = $invoice;
}

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-5">
  <a href="<?= BASE_URL ?>/modules/payment_requests/" class="text-sm text-gray-500 hover:text-gray-700">&larr; Payment Requests</a>
  <h1 class="mt-1 text-2xl font-bold text-gray-800">Create Payment Request</h1>
  <p class="mt-1 text-sm text-gray-500">Accounting can group one vendor per request, capture WHT, and start the document-check workflow.</p>
</div>

<form method="POST" x-data='prForm(<?= json_encode($vendorMap, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($preInvoice ? [[
    'id' => (int)$preInvoice['id'],
    'amount' => (float)$preInvoice['ap_balance'],
    'vendor' => $preInvoice['vendor_name'],
    'code' => $preInvoice['vendor_code'] ?? '',
]] : [], JSON_UNESCAPED_UNICODE) ?>)' class="grid grid-cols-1 gap-5 lg:grid-cols-3">
  <div class="space-y-4 lg:col-span-2">
    <div class="rounded-xl border bg-white p-5">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h3 class="font-semibold text-gray-700">Unpaid AP Invoices by Vendor</h3>
          <p class="mt-1 text-xs text-gray-500"><?= count($invoices) ?> unpaid AP invoice(s) across <?= count($invoicesByVendor) ?> vendor(s)</p>
        </div>
      </div>

      <div class="mb-4">
        <input type="text" x-model="vendorFilter" placeholder="Filter by vendor..."
               class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>

      <div class="max-h-[720px] space-y-3 overflow-y-auto pr-1">
        <?php foreach ($invoicesByVendor as $groupIndex => $group): ?>
        <?php
          $vendorNameLower = strtolower((string)($group['vendor_name'] ?? ''));
          $vendorCodeLower = strtolower((string)($group['vendor_code'] ?? ''));
          $groupKey = 'vendor_' . $groupIndex;
        ?>
        <div class="overflow-hidden rounded-xl border"
             x-show="!vendorFilter || '<?= addslashes($vendorNameLower) ?>'.includes(vendorFilter.toLowerCase()) || '<?= addslashes($vendorCodeLower) ?>'.includes(vendorFilter.toLowerCase())">
          <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3" style="background:#F7FBF8;">
            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" @click="toggleVendorGroup('<?= $groupKey ?>')">
              <span class="inline-flex h-7 w-7 items-center justify-center rounded-full border border-gray-200 bg-white text-xs font-bold text-gray-500" x-text="isVendorOpen('<?= $groupKey ?>') ? '-' : '+'"></span>
              <span class="min-w-0">
                <span class="block truncate font-semibold text-gray-800"><?= h($group['vendor_name'] ?: '(No vendor name)') ?></span>
                <span class="block text-xs text-gray-500"><?= h($group['vendor_code'] ?: '-') ?> · <?= (int)$group['invoice_count'] ?> invoice(s)</span>
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
                <tr class="border-b bg-gray-50 text-left text-xs text-gray-500">
                  <th class="w-8 px-3 py-2"></th>
                  <th class="px-3 py-2">Invoice No.</th>
                  <th class="px-3 py-2">Invoice Date</th>
                  <th class="px-3 py-2">PO / GRPO</th>
                  <th class="px-3 py-2">Status</th>
                  <th class="px-3 py-2 text-right">Outstanding</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($group['items'] as $invoice): ?>
                <tr class="cursor-pointer border-b last:border-0 hover:bg-blue-50"
                    @click="toggleInvoice(<?= (int)$invoice['id'] ?>, <?= (float)$invoice['ap_balance'] ?>, '<?= addslashes($invoice['vendor_name']) ?>', '<?= addslashes($invoice['vendor_code'] ?? '') ?>')">
                  <td class="px-3 py-2">
                    <input type="checkbox" name="invoice_ids[]" value="<?= (int)$invoice['id'] ?>"
                           :checked="selected.includes(<?= (int)$invoice['id'] ?>)"
                           @click.stop="toggleInvoice(<?= (int)$invoice['id'] ?>, <?= (float)$invoice['ap_balance'] ?>, '<?= addslashes($invoice['vendor_name']) ?>', '<?= addslashes($invoice['vendor_code'] ?? '') ?>')"
                           <?= $preInvoice && (int)$preInvoice['id'] === (int)$invoice['id'] ? 'checked' : '' ?>>
                  </td>
                  <td class="px-3 py-2 font-mono text-xs"><?= h($invoice['ap_invoice_doc_num']) ?></td>
                  <td class="px-3 py-2 text-xs"><?= fmtDate($invoice['ap_invoice_date']) ?></td>
                  <td class="px-3 py-2 text-xs text-gray-500">
                    <?= h($invoice['po_doc_num'] ?: '-') ?><br>
                    <span class="text-gray-400"><?= h($invoice['grpo_doc_num'] ?: '') ?></span>
                  </td>
                  <td class="px-3 py-2 text-xs text-gray-500"><?= h($invoice['payment_status'] ?: '-') ?></td>
                  <td class="px-3 py-2 text-right font-semibold text-orange-600"><?= fmtMoney($invoice['ap_balance']) ?></td>
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
    <div class="rounded-xl border bg-white p-5">
      <h3 class="mb-4 font-semibold text-gray-700">Request Details</h3>

      <div class="mb-3">
        <label class="mb-1 block text-xs text-gray-500">Vendor <span class="text-red-500">*</span></label>
        <input type="text" name="vendor_name" x-model="vendorName" readonly
               class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm" placeholder="Select unpaid invoices first">
        <input type="hidden" name="vendor_code" x-model="vendorCode">
        <input type="hidden" name="vendor_id" x-model="vendorId">
      </div>

      <div class="mb-3">
        <label class="mb-1 block text-xs text-gray-500">Vendor Code</label>
        <input type="text" x-model="vendorCode" readonly
               class="w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm" placeholder="Vendor code will appear here">
      </div>

      <div class="mb-3">
        <label class="mb-1 block text-xs text-gray-500">Due Date <span class="text-red-500">*</span></label>
        <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+7 days')) ?>"
               class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
      </div>

      <div class="mb-3">
        <label class="mb-1 block text-xs text-gray-500">Payment Method</label>
        <select name="payment_method" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <option value="cheque">Cheque</option>
          <option value="transfer">Bank Transfer</option>
          <option value="cash">Cash</option>
        </select>
      </div>

      <div class="mb-3 rounded-lg border border-gray-200 p-3">
        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
          <input type="checkbox" name="wht_applicable" x-model="whtApplicable">
          Apply withholding tax (WHT)
        </label>
        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
          <div>
            <label class="mb-1 block text-xs text-gray-500">WHT Rate (%)</label>
            <input type="number" step="0.01" min="0" name="wht_rate" x-model.number="whtRate"
                   class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="Example: 3.00">
          </div>
          <div>
            <label class="mb-1 block text-xs text-gray-500">WHT Amount</label>
            <input type="number" step="0.01" min="0" name="wht_amount" x-model.number="whtAmount"
                   class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="Auto or manual">
          </div>
          <div class="md:col-span-2">
            <label class="mb-1 block text-xs text-gray-500">WHT Base Amount</label>
            <input type="number" step="0.01" min="0" name="wht_base_amount" x-model.number="whtBaseAmount"
                   class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          </div>
        </div>
      </div>

      <div class="mb-3 rounded-lg border border-gray-200 p-3">
        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
          <input type="checkbox" name="tax_invoice_required" value="1" checked>
          Tax invoice required for this request
        </label>
        <p class="mt-2 text-xs text-gray-500">If checked, the request cannot move past accounting review until a tax invoice is confirmed.</p>
      </div>

      <div class="mb-3">
        <label class="mb-1 block text-xs text-gray-500">Priority</label>
        <select name="priority" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <option value="normal">Normal</option>
          <option value="urgent">Urgent</option>
          <option value="low">Low</option>
        </select>
      </div>

      <div class="mb-4">
        <label class="mb-1 block text-xs text-gray-500">Note</label>
        <textarea name="note" rows="3" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                  placeholder="Additional note..."></textarea>
      </div>

      <div class="mb-4 rounded-lg p-3" style="background:#ECF1F7;">
        <div class="mb-1 flex justify-between text-sm">
          <span class="text-gray-600">Selected Items:</span>
          <span class="font-semibold" x-text="selected.length"></span>
        </div>
        <div class="mb-1 flex justify-between text-sm">
          <span class="text-gray-600">Selected Vendor:</span>
          <span class="text-right font-medium" x-text="vendorName || '-'"></span>
        </div>
        <div class="mb-1 flex justify-between text-sm">
          <span class="text-gray-600">Vendor Code:</span>
          <span class="text-right font-medium" x-text="vendorCode || '-'"></span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Gross Amount:</span>
          <span class="font-bold" style="color:#003B5C;" x-text="'THB ' + totalAmount.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})"></span>
        </div>
        <div class="mt-1 flex justify-between text-sm">
          <span class="text-gray-600">WHT Amount:</span>
          <span class="font-semibold text-amber-700" x-text="'THB ' + computedWht.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})"></span>
        </div>
        <div class="mt-1 flex justify-between text-sm">
          <span class="text-gray-600">Net Payable:</span>
          <span class="font-bold text-emerald-700" x-text="'THB ' + computedNet.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})"></span>
        </div>
      </div>

      <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
        One payment request should contain AP invoices from one vendor only. New requests start in Pending Documents until supporting files are confirmed.
      </div>

      <button type="submit" :disabled="selected.length === 0 || !vendorName"
              class="theme-btn-primary w-full rounded-lg py-2.5 text-sm font-medium text-white transition-colors disabled:bg-gray-300">
        Create Request
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
        whtApplicable: false,
        whtRate: 0,
        whtAmount: 0,
        whtBaseAmount: 0,
        vendorName: '',
        vendorCode: '',
        vendorId: 0,
        vendorFilter: '',
        expandedVendors: {},
        get computedWht() {
            if (!this.whtApplicable) {
                return 0;
            }
            if (Number(this.whtAmount) > 0) {
                return Number(this.whtAmount);
            }
            if (Number(this.whtRate) > 0) {
                return Number(((Number(this.whtBaseAmount) || Number(this.totalAmount)) * Number(this.whtRate)) / 100);
            }
            return 0;
        },
        get computedNet() {
            return Math.max(0, Number(this.totalAmount) - Number(this.computedWht));
        },
        setVendor(vendor, code) {
            this.vendorName = vendor || '';
            this.vendorCode = code || '';
            this.vendorId = vendorMap[(this.vendorCode || '') + '|' + (this.vendorName || '')] || 0;
        },
        clearSelection() {
            this.selected = [];
            this.selectedAmounts = {};
            this.totalAmount = 0;
            this.whtBaseAmount = 0;
            this.setVendor('', '');
        },
        syncTotal() {
            this.totalAmount = Object.values(this.selectedAmounts).reduce((sum, amount) => sum + amount, 0);
            if (!this.whtBaseAmount || Number(this.whtBaseAmount) < Number(this.totalAmount)) {
                this.whtBaseAmount = this.totalAmount;
            }
        },
        toggleVendorGroup(groupKey) {
            this.expandedVendors[groupKey] = !this.isVendorOpen(groupKey);
        },
        isVendorOpen(groupKey) {
            return this.expandedVendors[groupKey] !== false;
        },
        toggleInvoice(id, amount, vendor, code) {
            const index = this.selected.indexOf(id);
            if (index >= 0) {
                this.selected.splice(index, 1);
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
            if (!items.length) {
                return;
            }
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

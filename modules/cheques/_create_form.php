<?php
/** @var array $eligiblePRs */
/** @var array $unavailablePRs */
/** @var array $thaiBanks */
?>
<form method="POST" class="space-y-3">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="create_cheque">
  <div>
    <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.select_pr') ?></label>
    <select name="payment_request_id" x-model="newCheque.payment_request_id"
            @change="newCheque.payee_name = prSupplierMap[newCheque.payment_request_id] || ''"
            required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
      <option value="">-- เลือกเลขที่ PR --</option>
      <?php if (!empty($eligiblePRs)): ?>
      <optgroup label="พร้อมออกเช็ค">
        <?php foreach ($eligiblePRs as $pr): ?>
        <option value="<?= (int) $pr['id'] ?>"><?= h($pr['request_no']) ?> — <?= h($pr['vendor_name']) ?> — ฿<?= fmtMoney((float) $pr['net_payable']) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endif; ?>
      <?php if (!empty($unavailablePRs)): ?>
      <optgroup label="PR อื่น (ยังเลือกไม่ได้)">
        <?php foreach ($unavailablePRs as $pr):
          $unavailableReason = (int) $pr['has_active_cheque'] === 1 ? 'ออกเช็คแล้ว' : (string) $pr['status'];
        ?>
        <option value="" disabled><?= h($pr['request_no']) ?> — <?= h($unavailableReason) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <?php endif; ?>
    </select>
    <p class="mt-1 text-xs <?= empty($eligiblePRs) ? 'text-amber-600' : 'text-gray-400' ?>">
      <?= empty($eligiblePRs)
        ? 'ขณะนี้ไม่มี PR ที่พร้อมออกเช็ค: PR ต้องมีสถานะ Approved for Payment และยังไม่มีเช็คที่ใช้งานอยู่'
        : 'เลือกได้เฉพาะ PR สถานะ Approved for Payment ที่ยังไม่เคยออกเช็ค' ?>
    </p>
  </div>
  <div class="grid grid-cols-2 gap-3">
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.col.cheque_no') ?></label>
      <input type="text" name="cheque_no" x-model="newCheque.cheque_no" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1">Cheque Date</label>
      <input type="date" name="cheque_date" x-model="newCheque.cheque_date" required
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1">Bank / ธนาคาร</label>
      <select name="bank" x-model="newCheque.bank" required
              class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
        <option value="">-- เลือกธนาคาร --</option>
        <?php foreach ($thaiBanks as $bankName): ?>
        <option value="<?= h($bankName) ?>"><?= h($bankName) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1">Payee Name / Supplier</label>
      <input type="text" name="payee_name" x-model="newCheque.payee_name" readonly required
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none bg-gray-100 text-gray-700 cursor-not-allowed"
             placeholder="เลือก Payment Request ก่อน">
    </div>
  </div>
  <div class="flex gap-2 justify-end pt-2">
    <button type="button" @click="showForm = false"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-600">Cancel</button>
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Create</button>
  </div>
</form>

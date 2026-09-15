<?php /** @var array $readyPRs */ ?>
<form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="create_batch">
  <div class="grid grid-cols-2 gap-3 mb-4">
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('batch.batch_date') ?></label>
      <input type="date" name="batch_date" value="<?= date('Y-m-d') ?>" required
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-400">
    </div>
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('batch.payment_type') ?></label>
      <select name="payment_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none">
        <option value="cheque">Cheque</option>
        <option value="transfer">Bank Transfer</option>
      </select>
    </div>
    <div class="col-span-2">
      <label class="block text-xs text-gray-500 mb-1">Note</label>
      <input type="text" name="note" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="หมายเหตุ...">
    </div>
  </div>

  <div class="mb-4">
    <label class="block text-xs text-gray-500 mb-2"><?= t('batch.select_prs') ?></label>
    <?php if (empty($readyPRs)): ?>
    <p class="text-sm text-gray-400 p-3 border rounded-lg text-center"><?= t('batch.no_prs') ?></p>
    <?php else: ?>
    <div class="border rounded-lg divide-y max-h-64 overflow-y-auto">
      <?php foreach ($readyPRs as $pr): ?>
      <label class="flex items-center gap-3 px-3 py-2 hover:bg-gray-50 cursor-pointer">
        <input type="checkbox" name="pr_ids[]" value="<?= $pr['id'] ?>">
        <div class="flex-1 min-w-0">
          <div class="flex items-center justify-between">
            <span class="font-mono text-xs"><?= h($pr['request_no']) ?></span>
            <span class="font-semibold text-blue-700 text-sm">฿<?= fmtMoney($pr['net_payable']) ?></span>
          </div>
          <div class="text-xs text-gray-500 truncate"><?= h($pr['vendor_name']) ?> | Due: <?= fmtDate($pr['due_date']) ?></div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="flex gap-2 justify-end">
    <button type="button" @click="showForm = false"
            class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50"><?= t('btn.cancel') ?></button>
    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700"><?= t('batch.create_btn') ?></button>
  </div>
</form>

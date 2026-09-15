<?php
/** @var array $c cheque row */
/** @var array $allowedStatuses */
/** @var array $chequeStatusLabels */
$chequeId = (int)$c['id'];
?>
<div x-data="{ newStatus: '<?= h($allowedStatuses[0]) ?>' }">
  <form method="POST" class="space-y-3">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="cheque_id" value="<?= $chequeId ?>">
    <p class="text-sm text-gray-600">Cheque: <span class="font-semibold"><?= h((string)$c['cheque_no']) ?></span></p>
    <div>
      <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.new_status') ?></label>
      <select name="new_status" x-model="newStatus" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none">
        <?php foreach ($allowedStatuses as $status): ?>
        <option value="<?= h($status) ?>"><?= h($chequeStatusLabels[$status] ?? $status) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div x-show="newStatus === 'received'" class="grid grid-cols-2 gap-3">
      <div>
        <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.receiver_name') ?></label>
        <input type="text" name="receiver_name" :required="newStatus === 'received'"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="<?= t('cheque.receiver_name') ?>...">
      </div>
      <div>
        <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.received_date') ?></label>
        <input type="date" name="received_date" value="<?= date('Y-m-d') ?>" :required="newStatus === 'received'"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none">
      </div>
    </div>
    <div x-show="['cancelled', 'void'].includes(newStatus)">
      <label class="block text-xs text-gray-500 mb-1"><?= t('cheque.void_reason') ?></label>
      <input type="text" name="void_reason" :required="['cancelled', 'void'].includes(newStatus)"
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm outline-none" placeholder="เหตุผล...">
    </div>
    <div class="flex gap-2 justify-end pt-2">
      <button type="button" @click="open[<?= $chequeId ?>] = false"
              class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-600">Cancel</button>
      <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium hover:bg-blue-700">Update</button>
    </div>
  </form>
</div>

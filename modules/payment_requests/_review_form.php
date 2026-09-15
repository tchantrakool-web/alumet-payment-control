<?php /** @var array $request */ /** @var array $checklist */ ?>
<form method="POST" class="space-y-4" id="review-form">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save_review">

  <!-- Classifications: checklist confirmations + WHT/tax flags, grouped together -->
  <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
      <input type="checkbox" name="has_po" value="1" <?= $checklist['po'] ? 'checked' : '' ?>>
      <?= t('pr.detail.po_confirmed') ?>
    </label>
    <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
      <input type="checkbox" name="has_grn" value="1" <?= $checklist['grn'] ? 'checked' : '' ?>>
      <?= t('pr.detail.grn_confirmed') ?>
    </label>
    <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
      <input type="checkbox" name="has_invoice" value="1" <?= $checklist['invoice'] ? 'checked' : '' ?>>
      <?= t('pr.detail.invoice_confirmed') ?>
    </label>
    <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
      <input type="checkbox" name="has_tax_invoice" value="1" <?= $checklist['tax_invoice'] ? 'checked' : '' ?>>
      <?= t('pr.detail.tax_confirmed') ?>
    </label>
    <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
      <input type="checkbox" name="wht_applicable" id="wht_applicable" value="1" <?= (int)$request['wht_applicable'] === 1 ? 'checked' : '' ?>>
      <?= t('pr.detail.wht_applicable') ?>
    </label>
    <label class="flex items-center gap-2 rounded-lg border p-3 text-sm">
      <input type="checkbox" name="tax_invoice_required" value="1" <?= (int)$request['tax_invoice_required'] === 1 ? 'checked' : '' ?>>
      <?= t('pr.detail.tax_req_checkbox') ?>
    </label>
  </div>

  <!-- Amounts: gross/WHT/net figures, grouped together -->
  <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
    <div>
      <label class="mb-1 block text-xs text-gray-500"><?= t('label.gross_amount') ?></label>
      <input type="number" step="0.01" min="0" name="gross_amount" id="gross_amount" value="<?= h((string)($request['gross_amount'] ?? $request['total_amount'])) ?>"
             class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="mb-1 block text-xs text-gray-500"><?= t('label.wht_rate') ?></label>
      <?php
        $whtRateOptions = [1.0, 3.0, 5.0];
        $storedWhtRate = (float)$request['wht_rate'];
        if ($storedWhtRate > 0 && !in_array($storedWhtRate, $whtRateOptions, true)) {
            $whtRateOptions[] = $storedWhtRate;
            sort($whtRateOptions);
        }
      ?>
      <select name="wht_rate" id="wht_rate" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        <?php foreach ($whtRateOptions as $rate): ?>
          <option value="<?= $rate ?>" <?= $storedWhtRate === $rate ? 'selected' : '' ?>><?= rtrim(rtrim(number_format($rate, 2), '0'), '.') ?>%</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="mb-1 block text-xs text-gray-500"><?= t('label.wht_base') ?></label>
      <input type="number" step="0.01" min="0" name="wht_base_amount" id="wht_base_amount" value="<?= h((string)$request['wht_base_amount']) ?>"
             class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="mb-1 block text-xs text-gray-500"><?= t('label.wht_amount') ?></label>
      <input type="number" step="0.01" min="0" name="wht_amount" id="wht_amount" value="<?= h((string)$request['wht_amount']) ?>" readonly
             class="theme-input w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm">
    </div>
    <div>
      <label class="mb-1 block text-xs text-gray-500"><?= t('label.net_payable') ?></label>
      <div id="net_payable_preview" class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-emerald-700">
        THB <?= fmtMoney((float)$request['net_payable']) ?>
      </div>
    </div>
  </div>

  <!-- Free text: always full width -->
  <div>
    <label class="mb-1 block text-xs text-gray-500"><?= t('pr.detail.note_label') ?></label>
    <textarea name="note" rows="4" class="theme-input w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"><?= h((string)$request['note']) ?></textarea>
  </div>

  <div class="flex justify-end gap-2 pt-1">
    <button type="button" @click="showForm = false" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-600 hover:bg-gray-50"><?= t('btn.cancel') ?></button>
    <button class="theme-btn-secondary rounded-lg px-4 py-2 text-sm font-medium"><?= t('pr.detail.save_review') ?></button>
  </div>
</form>

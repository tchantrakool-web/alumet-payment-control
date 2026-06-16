<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();

if (!canAccess('bpmn')) {
    flash('error', t('msg.access_denied'));
    redirect(BASE_URL . '/dashboard.php');
}

$pageTitle = 'BPMN Workflow';
$workflowAssetVersion = '20260609-4';

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-6 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
  <div>
    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700"><?= t('bpmn.module') ?></p>
    <h1 class="mt-2 text-3xl font-bold text-gray-800"><?= t('bpmn.title') ?></h1>
    <p class="mt-2 max-w-4xl text-base leading-7 text-gray-600"><?= t('bpmn.subtitle') ?></p>
  </div>
  <div class="flex flex-wrap gap-3">
    <a href="<?= BASE_URL ?>/docs/BPMN_Swimlane_Team_Discussion.md" target="_blank" class="rounded-xl bg-[#003B5C] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0a4f78]">
      <?= t('bpmn.team_discussion') ?>
    </a>
    <a href="<?= BASE_URL ?>/docs/BPMN_Swimlane.md" target="_blank" class="rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm font-semibold text-sky-800 transition hover:bg-sky-50">
      <?= t('bpmn.current_flow') ?>
    </a>
    <a href="<?= BASE_URL ?>/docs/User_Guideline.md" target="_blank" class="rounded-xl border border-emerald-200 bg-white px-4 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-50">
      <?= t('bpmn.user_guideline') ?>
    </a>
  </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-3">
  <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700"><?= t('bpmn.objective') ?></p>
    <h2 class="mt-2 text-xl font-bold text-emerald-950"><?= t('bpmn.objective.title') ?></h2>
    <p class="mt-2 text-sm leading-6 text-emerald-900"><?= t('bpmn.objective.desc') ?></p>
  </div>
  <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700"><?= t('bpmn.key_control') ?></p>
    <h2 class="mt-2 text-xl font-bold text-amber-950"><?= t('bpmn.key_control.title') ?></h2>
    <p class="mt-2 text-sm leading-6 text-amber-900"><?= t('bpmn.key_control.desc') ?></p>
  </div>
  <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm">
    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700"><?= t('bpmn.discussion') ?></p>
    <h2 class="mt-2 text-xl font-bold text-sky-950"><?= t('bpmn.discussion.title') ?></h2>
    <p class="mt-2 text-sm leading-6 text-sky-900"><?= t('bpmn.discussion.desc') ?></p>
  </div>
</div>

<div class="space-y-6">
  <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-gray-800"><?= t('bpmn.detailed') ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?= t('bpmn.detailed_desc') ?></p>
      </div>
      <a href="<?= BASE_URL ?>/docs/assets/mvp-workflow-swimlane.svg?v=<?= h($workflowAssetVersion) ?>" target="_blank" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
        <?= t('bpmn.detailed_svg') ?>
      </a>
    </div>
    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-50 p-3">
      <img
        src="<?= BASE_URL ?>/docs/assets/mvp-workflow-swimlane.svg?v=<?= h($workflowAssetVersion) ?>"
        alt="Detailed Thai MVP payment control workflow"
        class="w-full rounded-2xl bg-white"
        loading="lazy"
      >
    </div>
  </div>

  <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-gray-800"><?= t('bpmn.executive') ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?= t('bpmn.executive_desc') ?></p>
      </div>
      <a href="<?= BASE_URL ?>/docs/assets/mvp-workflow-executive-th.svg?v=<?= h($workflowAssetVersion) ?>" target="_blank" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
        <?= t('bpmn.executive_svg') ?>
      </a>
    </div>
    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-slate-50 p-3">
      <img
        src="<?= BASE_URL ?>/docs/assets/mvp-workflow-executive-th.svg?v=<?= h($workflowAssetVersion) ?>"
        alt="Executive Thai MVP payment control workflow"
        class="w-full rounded-2xl bg-white"
        loading="lazy"
      >
    </div>
  </div>
</div>

<?php include ROOT_PATH . '/layouts/footer.php'; ?>

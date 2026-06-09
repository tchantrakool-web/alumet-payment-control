<?php
require_once __DIR__ . '/../../config/bootstrap.php';
requireLogin();

if (!canAccess('bpmn')) {
    setFlash('error', 'You do not have permission to access BPMN Workflow.');
    redirect(BASE_URL . '/dashboard.php');
}

$pageTitle = 'BPMN Workflow';
$workflowAssetVersion = '20260609-4';

include ROOT_PATH . '/layouts/header.php';
?>

<div class="mb-6 flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
  <div>
    <p class="text-sm font-semibold uppercase tracking-[0.2em] text-sky-700">BPMN Module</p>
    <h1 class="mt-2 text-3xl font-bold text-gray-800">MVP Payment Workflow</h1>
    <p class="mt-2 max-w-4xl text-base leading-7 text-gray-600">
      This module separates the end-to-end payment workflow from the dashboard so the team can review the swimlane,
      discuss handoff points, and align controls for Procurement, Accounting, Finance, and Management.
    </p>
  </div>
  <div class="flex flex-wrap gap-3">
    <a href="<?= BASE_URL ?>/docs/BPMN_Swimlane_Team_Discussion.md" target="_blank" class="rounded-xl bg-[#003B5C] px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0a4f78]">
      Open Team Discussion
    </a>
    <a href="<?= BASE_URL ?>/docs/BPMN_Swimlane.md" target="_blank" class="rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm font-semibold text-sky-800 transition hover:bg-sky-50">
      Open Current Flow
    </a>
    <a href="<?= BASE_URL ?>/docs/User_Guideline.md" target="_blank" class="rounded-xl border border-emerald-200 bg-white px-4 py-3 text-sm font-semibold text-emerald-800 transition hover:bg-emerald-50">
      Open User Guideline
    </a>
  </div>
</div>

<div class="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-3">
  <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-emerald-700">Objective</p>
    <h2 class="mt-2 text-xl font-bold text-emerald-950">Create one shared workflow view</h2>
    <p class="mt-2 text-sm leading-6 text-emerald-900">
      Use this page to confirm ownership, required documents, approval routing, and return-for-correction behavior before extending the MVP.
    </p>
  </div>
  <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-amber-700">Key Control</p>
    <h2 class="mt-2 text-xl font-bold text-amber-950">Documents must be complete before moving forward</h2>
    <p class="mt-2 text-sm leading-6 text-amber-900">
      The Phase 1 flow expects PO, GRN or GRPO, Vendor Invoice, tax details when applicable, and payment proof before final closure.
    </p>
  </div>
  <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm">
    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-sky-700">Discussion Focus</p>
    <h2 class="mt-2 text-xl font-bold text-sky-950">Handoffs and return paths</h2>
    <p class="mt-2 text-sm leading-6 text-sky-900">
      Review where Procurement, Accounting, Finance Review, and Approver should receive work back for correction and what evidence is mandatory.
    </p>
  </div>
</div>

<div class="space-y-6">
  <div class="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="mb-4 flex items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold text-gray-800">เวอร์ชันปฏิบัติงาน</h2>
        <p class="mt-1 text-sm text-gray-500">รายละเอียดครบสำหรับทีมงาน พร้อมลูกศร return, reject และสถานะของระบบ</p>
      </div>
      <a href="<?= BASE_URL ?>/docs/assets/mvp-workflow-swimlane.svg?v=<?= h($workflowAssetVersion) ?>" target="_blank" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
        Open Detailed SVG
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
        <h2 class="text-xl font-bold text-gray-800">เวอร์ชันผู้บริหาร</h2>
        <p class="mt-1 text-sm text-gray-500">ลดรายละเอียด technical และเน้นเส้นทางหลักกับจุดควบคุมที่ต้องติดตาม</p>
      </div>
      <a href="<?= BASE_URL ?>/docs/assets/mvp-workflow-executive-th.svg?v=<?= h($workflowAssetVersion) ?>" target="_blank" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">
        Open Executive SVG
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

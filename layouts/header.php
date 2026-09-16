<!DOCTYPE html>
<html lang="<?= defined('APP_LANG') ? APP_LANG : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'Payment Control Tower') ?> | <?= h(getSetting('company_name', 'Alumet Co., Ltd.')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.17"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.17.3/dist/cdn.min.js"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/theme.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.tailwindcss.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
  :root{--brand-primary:#006B3F;--brand-primary-soft:#E6F4EC;--brand-secondary:#003B5C;--brand-secondary-soft:#DCE8F0;--brand-accent:#A4D65E;--brand-accent-soft:#F1F9E6;--brand-ink:#17313D}
  [x-cloak]{display:none!important}
  html{background:#eef4f1}
  body{font-family:'Sarabun','Segoe UI',system-ui,sans-serif;background:radial-gradient(circle at top left,rgba(164,214,94,.18),transparent 22%),linear-gradient(180deg,#f8fbf8 0%,#eef4f1 100%);color:var(--brand-ink)}
  .sidebar-group{--sidebar-tone:#475569;--sidebar-soft:#f1f5f9;--sidebar-border:#dbe3ea;background:rgba(255,255,255,.88);border:1px solid var(--sidebar-border);border-left:3px solid var(--sidebar-tone);border-radius:.75rem;padding:.38rem;box-shadow:0 1px 2px rgba(15,23,42,.035)}
  .sidebar-group--dashboard{--sidebar-tone:#0f766e;--sidebar-soft:#ecfdf5;--sidebar-border:#d4ebe4}
  .sidebar-group--maker{--sidebar-tone:#0369a1;--sidebar-soft:#eef8ff;--sidebar-border:#cfe7f4}
  .sidebar-group--checker{--sidebar-tone:#b45309;--sidebar-soft:#fff8e8;--sidebar-border:#f2dfb4}
  .sidebar-group--approver{--sidebar-tone:#047857;--sidebar-soft:#ecfdf5;--sidebar-border:#cde9dc}
  .sidebar-group--monitoring{--sidebar-tone:#475569;--sidebar-soft:#f1f5f9;--sidebar-border:#dbe3ea}
  .sidebar-group--admin{--sidebar-tone:#6d28d9;--sidebar-soft:#f5f3ff;--sidebar-border:#ded7f4}
  .sidebar-link{display:flex;align-items:center;gap:.65rem;padding:.5rem .6rem;border-radius:.55rem;color:#334155;font-size:.82rem;font-weight:600;white-space:nowrap;transition:background-color .18s,color .18s,box-shadow .18s}
  .sidebar-link:hover{background:var(--sidebar-soft);color:var(--sidebar-tone)}
  .sidebar-link.active{background:var(--sidebar-soft);color:var(--sidebar-tone);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--sidebar-tone) 24%,transparent)}
  .sidebar-icon{display:inline-flex;align-items:center;justify-content:center;width:1.65rem;height:1.65rem;border-radius:.48rem;background:var(--sidebar-soft);color:var(--sidebar-tone);flex:0 0 auto}
  .sidebar-link.active .sidebar-icon{background:var(--sidebar-tone);color:#fff}
  .sidebar-section-label{display:flex;align-items:center;gap:.42rem;padding:.25rem .55rem;color:#334155;font-size:.69rem;font-weight:800;letter-spacing:.035em;line-height:1.2}
  .sidebar-section-label small{margin-left:auto;color:var(--sidebar-tone);font-size:.58rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
  .sidebar-section-marker{width:.42rem;height:.42rem;border-radius:999px;background:var(--sidebar-tone);box-shadow:0 0 0 3px var(--sidebar-soft);flex:0 0 auto}
  .nav-bilingual{display:flex;flex-direction:column;line-height:1.05;gap:.16rem}
  .nav-bilingual small{font-size:.65rem;font-weight:500;color:#64748b;letter-spacing:.01em}
  .sidebar-scroll::-webkit-scrollbar{width:6px}
  .sidebar-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px}
  .theme-input:focus{outline:none;border-color:var(--brand-primary);box-shadow:0 0 0 3px rgba(0,107,63,.15)}
  .theme-btn-primary{background:var(--brand-primary);color:#fff}.theme-btn-primary:hover{background:#005432}
  .theme-btn-secondary{background:var(--brand-secondary);color:#fff}.theme-btn-secondary:hover{background:#032f49}
  .notification-panel{position:absolute;right:0;top:calc(100% + .7rem);width:min(24rem,calc(100vw - 1.5rem));max-height:min(36rem,calc(100vh - 5rem));overflow:hidden;background:#fff;border:1px solid #dbe5e0;border-radius:1rem;box-shadow:0 22px 60px rgba(3,29,45,.28);color:var(--brand-ink);z-index:80}
  .notification-scroll{max-height:min(27rem,calc(100vh - 12rem));overflow-y:auto;overscroll-behavior:contain}
</style>
<?php
$notificationInitial = notificationPayload(getDB(), currentUser() ?? []);
$notificationQueueUrl = hasRole('checker', 'approver', 'executive')
    ? BASE_URL . '/modules/approval/'
    : BASE_URL . '/modules/payment_requests/';
$notificationJsConfig = json_encode([
    'initial' => $notificationInitial,
    'endpoint' => BASE_URL . '/api/notifications.php',
    'csrf' => csrfToken(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<script>
window.paymentNotificationConfig = <?= $notificationJsConfig ?: '{}' ?>;
function notificationBell() {
    const config = window.paymentNotificationConfig || {};
    return {
        open: false,
        loading: false,
        items: config.initial?.items || [],
        unreadCount: config.initial?.unread_count || 0,
        totalCount: config.initial?.total_count || 0,
        timer: null,
        init() {
            this.timer = window.setInterval(() => this.refresh(), 60000);
        },
        destroy() {
            if (this.timer) window.clearInterval(this.timer);
        },
        async refresh() {
            if (document.hidden) return;
            this.loading = true;
            try {
                const response = await fetch(config.endpoint, {headers: {'Accept': 'application/json'}});
                if (!response.ok) return;
                const data = await response.json();
                this.items = data.items || [];
                this.unreadCount = data.unread_count || 0;
                this.totalCount = data.total_count || 0;
            } catch (_) {
                // Keep the last known list when the connection or session is unavailable.
            } finally {
                this.loading = false;
            }
        },
        post(action, key = '') {
            const body = new URLSearchParams({action, key, csrf_token: config.csrf || ''});
            return fetch(config.endpoint, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
                body,
                keepalive: true
            });
        },
        openItem(item) {
            if (!item.is_read) {
                item.is_read = true;
                this.unreadCount = Math.max(0, this.unreadCount - 1);
                this.post('mark_read', item.key).catch(() => {});
            }
            window.location.assign(item.url);
        },
        async markAllRead() {
            if (this.unreadCount === 0) return;
            const previousItems = this.items.map(item => ({...item}));
            const previousUnreadCount = this.unreadCount;
            this.items.forEach(item => item.is_read = true);
            this.unreadCount = 0;
            try {
                const response = await this.post('mark_all_read');
                if (!response.ok) throw new Error('Unable to update notifications');
                const data = await response.json();
                this.items = data.items || [];
                this.unreadCount = data.unread_count || 0;
                this.totalCount = data.total_count || 0;
            } catch (_) {
                this.items = previousItems;
                this.unreadCount = previousUnreadCount;
            }
        }
    };
}
</script>
</head>
<body class="min-h-screen" x-data="{ mobileNavOpen: false }" @keydown.escape.window="mobileNavOpen = false">

<aside
  class="fixed inset-y-0 left-0 z-50 w-72 flex flex-col border-r border-slate-200 bg-[#f5f8f7] text-slate-800 shadow-[8px_0_30px_rgba(15,23,42,.08)] transform transition-transform duration-200 ease-out lg:translate-x-0"
  :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full'"
  aria-label="Sidebar navigation"
>
  <div class="h-16 px-4 flex items-center gap-3 border-b border-slate-200 bg-white shrink-0">
    <a href="<?= BASE_URL ?>/dashboard.php" class="flex items-center gap-3 min-w-0">
      <img src="<?= BASE_URL ?>/uploads/branding/alumet-logo.jpg" alt="Alumet" class="h-9 w-auto rounded-md border border-slate-200 bg-white px-2 py-1">
      <div class="min-w-0">
        <div class="font-bold text-sm leading-none text-[#003B5C] truncate">Payment Control Tower</div>
        <div class="text-[11px] text-slate-500 truncate mt-1"><?= h(getSetting('company_name', 'Alumet Co., Ltd.')) ?></div>
      </div>
    </a>
    <button type="button" @click="mobileNavOpen = false" class="ml-auto p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 transition-colors lg:hidden" aria-label="Close navigation">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg>
    </button>
  </div>

  <?php include ROOT_PATH . '/layouts/sidebar.php'; ?>

  <div class="border-t border-slate-200 bg-white p-3 shrink-0 space-y-2">
    <div class="flex items-center gap-2 px-1">
      <div class="min-w-0 flex-1">
        <div class="text-sm font-semibold text-slate-800 truncate"><?= h(currentUser()['full_name'] ?? '') ?></div>
        <div class="text-[11px] text-slate-500 truncate"><?= h($_SESSION['role_name'] ?? '') ?></div>
      </div>
      <?php $currentLang = defined('APP_LANG') ? APP_LANG : 'en'; ?>
      <a href="?lang=<?= $currentLang === 'en' ? 'th' : 'en' ?>" class="shrink-0 text-xs font-semibold text-slate-600 px-2.5 py-1.5 rounded-md border border-slate-200 hover:bg-slate-100 transition-colors" title="Switch language"><?= $currentLang === 'en' ? 'TH' : 'EN' ?></a>
    </div>
    <a href="<?= BASE_URL ?>/logout.php" class="flex items-center justify-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:border-red-200 hover:bg-red-50 hover:text-red-700 transition-colors">
      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75"/></svg>
      <?= t('auth.logout') ?>
    </a>
  </div>
</aside>

<div x-cloak x-show="mobileNavOpen" x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/40 lg:hidden" @click="mobileNavOpen = false"></div>

<header class="fixed inset-x-0 top-0 z-30 lg:left-72 text-white shadow-lg" style="background:linear-gradient(90deg,#003B5C 0%,#0A536F 68%,#006B3F 100%);">
  <div class="h-16 px-4 sm:px-6 flex items-center gap-3 sm:gap-4">
    <button type="button" @click="mobileNavOpen = !mobileNavOpen" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle navigation" :aria-expanded="mobileNavOpen.toString()">
      <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div class="min-w-0 flex-1">
      <div class="font-bold text-base sm:text-lg tracking-wide leading-none truncate"><?= h($pageTitle ?? 'Payment Control Tower') ?></div>
    </div>
    <div class="relative" x-data="notificationBell()" @keydown.escape.window="open = false">
      <button type="button" @click="open = !open; if (open) refresh()" class="relative grid h-10 w-10 place-items-center rounded-xl border border-white/15 text-white hover:bg-white/10 transition-colors" aria-label="<?= APP_LANG === 'th' ? 'การแจ้งเตือน' : 'Notifications' ?>" :aria-expanded="open.toString()">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14.857 17.082a23.9 23.9 0 0 0 5.454-1.31A8.97 8.97 0 0 1 18 9.75V9a6 6 0 0 0-12 0v.75a8.97 8.97 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.3 24.3 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>
        <span x-cloak x-show="unreadCount > 0" x-text="unreadCount > 99 ? '99+' : unreadCount" class="absolute -right-1.5 -top-1.5 min-w-[1.25rem] h-5 px-1 grid place-items-center rounded-full bg-red-500 text-[10px] font-bold leading-none ring-2 ring-[#0A536F]" aria-live="polite"></span>
      </button>

      <div x-cloak x-show="open" x-transition.origin.top.right @click.outside="open = false" class="notification-panel">
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div>
            <h2 class="font-bold text-slate-800"><?= APP_LANG === 'th' ? 'งานที่ต้องดำเนินการ' : 'Action required' ?></h2>
            <p class="text-xs text-slate-500"><span x-text="totalCount"></span> <?= APP_LANG === 'th' ? 'รายการในคิวของคุณ' : 'items in your queue' ?></p>
          </div>
          <button type="button" @click="markAllRead()" x-show="unreadCount > 0" class="shrink-0 text-xs font-semibold text-emerald-700 hover:text-emerald-900"><?= APP_LANG === 'th' ? 'อ่านทั้งหมดแล้ว' : 'Mark all read' ?></button>
        </div>

        <div class="notification-scroll">
          <template x-if="loading && items.length === 0"><div class="px-4 py-10 text-center text-sm text-slate-400"><?= APP_LANG === 'th' ? 'กำลังโหลด...' : 'Loading...' ?></div></template>
          <template x-if="!loading && items.length === 0">
            <div class="px-6 py-10 text-center">
              <div class="mx-auto mb-3 grid h-11 w-11 place-items-center rounded-full bg-emerald-50 text-emerald-600">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m4.5 12.75 6 6 9-13.5"/></svg>
              </div>
              <p class="font-semibold text-slate-700"><?= APP_LANG === 'th' ? 'ไม่มีงานค้างในขณะนี้' : 'You are all caught up' ?></p>
              <p class="mt-1 text-xs text-slate-400"><?= APP_LANG === 'th' ? 'งานใหม่ที่ส่งถึงคุณจะแสดงที่นี่' : 'New assigned work will appear here' ?></p>
            </div>
          </template>
          <template x-for="item in items" :key="item.key">
            <a :href="item.url" @click.prevent="openItem(item)" class="relative block border-b border-slate-100 px-4 py-3.5 hover:bg-emerald-50/60 transition-colors last:border-0" :class="item.is_read ? 'bg-white' : 'bg-sky-50/60'">
              <span x-show="!item.is_read" class="absolute left-1.5 top-5 h-2 w-2 rounded-full bg-sky-500"></span>
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex items-center gap-2">
                    <p class="truncate text-sm font-bold text-slate-800" x-text="item.title"></p>
                    <span x-show="item.priority === 'urgent'" class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700"><?= APP_LANG === 'th' ? 'ด่วน' : 'URGENT' ?></span>
                  </div>
                  <p class="mt-0.5 truncate text-xs font-semibold text-[#006B3F]" x-text="item.request_no + ' · ' + item.vendor_name"></p>
                  <p class="mt-1 text-xs text-slate-500" x-text="item.message"></p>
                </div>
                <span class="shrink-0 text-xs font-bold text-slate-700" x-text="Number(item.amount).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})"></span>
              </div>
              <div class="mt-2 flex items-center justify-between text-[11px]">
                <span :class="item.is_overdue ? 'font-bold text-red-600' : 'text-slate-400'" x-text="(item.is_overdue ? '<?= APP_LANG === 'th' ? 'เกินกำหนด · ' : 'Overdue · ' ?>' : '<?= APP_LANG === 'th' ? 'ครบกำหนด · ' : 'Due · ' ?>') + item.due_date"></span>
                <span class="font-semibold text-[#003B5C]"><?= APP_LANG === 'th' ? 'เปิดงาน →' : 'Open →' ?></span>
              </div>
            </a>
          </template>
        </div>
        <a href="<?= h($notificationQueueUrl) ?>" class="block border-t border-slate-100 bg-slate-50 px-4 py-3 text-center text-xs font-bold text-[#003B5C] hover:bg-slate-100"><?= APP_LANG === 'th' ? 'ดูรายการงานทั้งหมด' : 'View all work items' ?></a>
      </div>
    </div>
  </div>
</header>

<main class="w-full min-h-screen pt-16 lg:pl-72 px-3 sm:px-5 lg:px-7 pb-7">
  <?php
  $success = getFlash('success');
  $error   = getFlash('error');
  if ($success): ?>
    <div x-data="{show:true}" x-show="show" class="mb-4 flex items-center justify-between bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
      <span><?= h($success) ?></span><button @click="show=false" class="text-green-600 hover:text-green-800" aria-label="Close">&times;</button>
    </div>
  <?php endif; if ($error): ?>
    <div x-data="{show:true}" x-show="show" class="mb-4 flex items-center justify-between bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
      <span><?= h($error) ?></span><button @click="show=false" class="text-red-600 hover:text-red-800" aria-label="Close">&times;</button>
    </div>
  <?php endif; ?>

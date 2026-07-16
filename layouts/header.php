<!DOCTYPE html>
<html lang="<?= defined('APP_LANG') ? APP_LANG : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'Payment Control Tower') ?> | <?= h(getSetting('company_name', 'Alumet Co., Ltd.')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.tailwindcss.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
  :root{--brand-primary:#006B3F;--brand-primary-soft:#E6F4EC;--brand-secondary:#003B5C;--brand-secondary-soft:#DCE8F0;--brand-accent:#A4D65E;--brand-accent-soft:#F1F9E6;--brand-ink:#17313D}
  [x-cloak]{display:none!important}
  html{background:#eef4f1}
  body{font-family:'Sarabun','Segoe UI',system-ui,sans-serif;background:radial-gradient(circle at top left,rgba(164,214,94,.18),transparent 22%),linear-gradient(180deg,#f8fbf8 0%,#eef4f1 100%);color:var(--brand-ink)}
  .topnav-link{height:2.55rem;display:inline-flex;align-items:center;gap:.5rem;padding:0 .72rem;border-radius:.65rem;color:#d9e8e2;font-size:.8rem;font-weight:600;white-space:nowrap;transition:background-color .18s,color .18s}
  .topnav-link:hover{background:rgba(255,255,255,.1);color:#fff}
  .topnav-link.active{background:rgba(164,214,94,.18);color:#fff;box-shadow:inset 0 0 0 1px rgba(164,214,94,.25)}
  .topnav-icon{display:inline-flex;align-items:center;justify-content:center;width:1.45rem;height:1.45rem;border-radius:.45rem;background:var(--brand-primary-soft);color:var(--brand-primary);font-size:.68rem;font-weight:800;flex:0 0 auto}
  .topnav-link .topnav-icon{background:rgba(255,255,255,.12);color:#fff}
  .nav-bilingual{display:flex;flex-direction:column;line-height:1.05;gap:.16rem}
  .nav-bilingual small{font-size:.65rem;font-weight:500;opacity:.7;letter-spacing:.01em}
  .topnav-dropdown{position:absolute;top:calc(100% - .15rem);left:0;min-width:14rem;padding:.45rem;background:#fff;border:1px solid #dbe5e0;border-radius:.8rem;box-shadow:0 18px 45px rgba(3,29,45,.2);z-index:60}
  .topnav-dropdown-title,.mobile-nav-section{padding:.45rem .65rem;color:#70867c;font-size:.68rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
  .topnav-dropdown-link,.mobile-nav-link{display:flex;align-items:center;gap:.65rem;padding:.62rem .65rem;border-radius:.6rem;color:#334b41;font-size:.84rem;font-weight:600;transition:background-color .15s,color .15s}
  .topnav-dropdown-link:hover,.mobile-nav-link:hover{background:#f0f7f3;color:var(--brand-primary)}
  .topnav-dropdown-link.active,.mobile-nav-link.active{background:var(--brand-primary-soft);color:var(--brand-primary)}
  .theme-input:focus{outline:none;border-color:var(--brand-primary);box-shadow:0 0 0 3px rgba(0,107,63,.15)}
  .theme-btn-primary{background:var(--brand-primary);color:#fff}.theme-btn-primary:hover{background:#005432}
  .theme-btn-secondary{background:var(--brand-secondary);color:#fff}.theme-btn-secondary:hover{background:#032f49}
</style>
</head>
<body class="min-h-screen" x-data="{ mobileNavOpen: false }" @keydown.escape.window="mobileNavOpen = false">

<header class="fixed inset-x-0 top-0 z-50 text-white shadow-lg" style="background:linear-gradient(90deg,#003B5C 0%,#0A536F 68%,#006B3F 100%);">
  <div class="h-16 px-4 sm:px-6 flex items-center gap-4 border-b border-white/10">
    <a href="<?= BASE_URL ?>/dashboard.php" class="flex items-center gap-3 min-w-0">
      <img src="<?= BASE_URL ?>/uploads/branding/alumet-logo.jpg" alt="Alumet" class="h-9 w-auto rounded-md bg-white px-2 py-1 shadow-sm">
      <div class="min-w-0">
        <div class="font-bold text-base sm:text-lg tracking-wide leading-none truncate">Payment Control Tower</div>
        <div class="text-xs hidden sm:block truncate mt-1" style="color:#B8D4C4;"><?= h(getSetting('company_name', 'Alumet Co., Ltd.')) ?></div>
      </div>
    </a>
    <div class="ml-auto hidden md:flex items-center gap-3 min-w-0">
      <div class="text-right min-w-0">
        <div class="text-sm font-semibold truncate"><?= h(currentUser()['full_name'] ?? '') ?></div>
        <div class="text-[11px] truncate" style="color:#B8D4C4;"><?= h($_SESSION['role_name'] ?? '') ?></div>
      </div>
      <?php $currentLang = defined('APP_LANG') ? APP_LANG : 'en'; ?>
      <a href="?lang=<?= $currentLang === 'en' ? 'th' : 'en' ?>" class="text-xs px-2.5 py-1.5 rounded-md border border-white/20 hover:bg-white/10 transition-colors" title="Switch language"><?= $currentLang === 'en' ? 'TH' : 'EN' ?></a>
      <a href="<?= BASE_URL ?>/logout.php" class="text-sm px-2 py-1.5 rounded-md hover:bg-white/10 transition-colors" style="color:#D8E7E1;"><?= t('auth.logout') ?></a>
    </div>
    <button type="button" @click="mobileNavOpen = !mobileNavOpen" class="lg:hidden ml-auto p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle navigation" :aria-expanded="mobileNavOpen.toString()">
      <svg x-show="!mobileNavOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
      <svg x-cloak x-show="mobileNavOpen" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12"/></svg>
    </button>
  </div>
  <?php include ROOT_PATH . '/layouts/sidebar.php'; ?>
</header>

<main class="w-full min-h-screen pt-20 lg:pt-32 px-3 sm:px-5 lg:px-7 pb-7">
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

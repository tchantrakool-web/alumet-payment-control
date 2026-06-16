<!DOCTYPE html>
<html lang="<?= defined('APP_LANG') ? APP_LANG : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'Payment Control Tower') ?> | <?= h(getSetting('company_name', 'Alumet Co., Ltd.')) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.tailwindcss.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
  :root{
    --brand-primary:#006B3F;
    --brand-primary-soft:#E6F4EC;
    --brand-secondary:#003B5C;
    --brand-secondary-soft:#DCE8F0;
    --brand-accent:#A4D65E;
    --brand-accent-soft:#F1F9E6;
    --brand-ink:#17313D;
  }
  [x-cloak]{display:none!important}
  body{
    background:
      radial-gradient(circle at top left, rgba(164,214,94,.18), transparent 22%),
      linear-gradient(180deg, #f8fbf8 0%, #eef4f1 100%);
    color:var(--brand-ink);
  }
  .sidebar-link{
    display:flex;
    align-items:center;
    gap:.65rem;
    padding:.72rem .9rem;
    border-radius:.9rem;
    font-size:.875rem;
    font-weight:600;
    color:#cfe0d8;
    transition:background-color .2s,color .2s,transform .2s,box-shadow .2s;
  }
  .sidebar-link:hover{
    background:rgba(255,255,255,.08);
    color:#ffffff;
    transform:translateX(2px);
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.05);
  }
  .sidebar-link.active{
    background:linear-gradient(135deg, rgba(164,214,94,.22) 0%, rgba(255,255,255,.12) 100%);
    color:#ffffff;
    box-shadow:inset 0 0 0 1px rgba(164,214,94,.28), 0 10px 22px rgba(3,29,45,.18);
  }
  .sidebar-section{
    color:#9dc3b3;
    letter-spacing:.12em;
  }
  .sidebar-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:1.55rem;
    height:1.55rem;
    border-radius:.55rem;
    background:rgba(255,255,255,.08);
    color:#f3fbf8;
    font-size:.72rem;
    font-weight:800;
    flex-shrink:0;
  }
  .theme-input:focus{
    outline:none;
    border-color:var(--brand-primary);
    box-shadow:0 0 0 3px rgba(0,107,63,.15);
  }
  .theme-btn-primary{background:var(--brand-primary);color:#fff}
  .theme-btn-primary:hover{background:#005432}
  .theme-btn-secondary{background:var(--brand-secondary);color:#fff}
  .theme-btn-secondary:hover{background:#032f49}
</style>
</head>
<body class="min-h-screen" x-data="{ sidebarOpen: true }">

<nav class="fixed top-0 left-0 right-0 z-30 text-white h-14 flex items-center px-4 gap-4 shadow-lg" style="background:linear-gradient(90deg, #003B5C 0%, #0A536F 100%);">
  <button @click="sidebarOpen = !sidebarOpen" class="text-white hover:text-[#A4D65E] p-1 rounded transition-colors" aria-label="Toggle sidebar">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
  </button>
  <a href="<?= BASE_URL ?>/dashboard.php" class="flex items-center gap-3 min-w-0">
    <img src="<?= BASE_URL ?>/uploads/branding/alumet-logo.jpg" alt="Alumet" class="h-8 w-auto rounded-md bg-white/95 px-2 py-1 shadow-sm">
    <div class="min-w-0">
      <div class="font-bold text-lg tracking-wide leading-none">Payment Control Tower</div>
      <div class="text-xs hidden md:block truncate" style="color:#B8D4C4;"><?= h(getSetting('company_name', 'Alumet Co., Ltd.')) ?></div>
    </div>
  </a>
  <div class="ml-auto flex items-center gap-3">
    <span class="text-sm" style="color:#D8E7E1;"><?= h(currentUser()['full_name'] ?? '') ?></span>
    <span class="text-xs px-2 py-0.5 rounded-full" style="background:rgba(164,214,94,.18); color:#F5FFE9;"><?= h($_SESSION['role_name'] ?? '') ?></span>
    <?php $currentLang = defined('APP_LANG') ? APP_LANG : 'en'; ?>
    <a href="?lang=<?= $currentLang === 'en' ? 'th' : 'en' ?>" class="text-xs px-2 py-1 rounded border border-white/20 hover:bg-white/10 transition-colors ml-1" style="color:#B8D4C4;" title="Switch language"><?= $currentLang === 'en' ? 'TH' : 'EN' ?></a>
    <a href="<?= BASE_URL ?>/logout.php" class="text-sm ml-2 hover:text-white transition-colors" style="color:#B8D4C4;"><?= t('auth.logout') ?></a>
  </div>
</nav>

<div class="flex pt-14 min-h-screen">
  <aside class="fixed left-0 top-14 bottom-0 z-20 transition-all duration-300 overflow-y-auto overflow-x-hidden"
         style="background:linear-gradient(180deg, #042D46 0%, #003B5C 52%, #005432 100%);"
         :class="sidebarOpen ? 'w-56' : 'w-0'">
    <div class="p-3 space-y-0.5" x-show="sidebarOpen">
      <?php include ROOT_PATH . '/layouts/sidebar.php'; ?>
    </div>
  </aside>

  <main class="flex-1 transition-all duration-300 p-5" :class="sidebarOpen ? 'ml-56' : 'ml-0'">
    <?php
    $success = getFlash('success');
    $error   = getFlash('error');
    if ($success): ?>
      <div x-data="{show:true}" x-show="show" class="mb-4 flex items-center justify-between bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">
        <span><?= h($success) ?></span>
        <button @click="show=false" class="text-green-600 hover:text-green-800">×</button>
      </div>
    <?php endif; if ($error): ?>
      <div x-data="{show:true}" x-show="show" class="mb-4 flex items-center justify-between bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">
        <span><?= h($error) ?></span>
        <button @click="show=false" class="text-red-600 hover:text-red-800">×</button>
      </div>
    <?php endif; ?>

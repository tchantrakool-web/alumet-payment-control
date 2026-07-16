<?php
require_once __DIR__ . '/config/bootstrap.php';
if (isLoggedIn()) redirect(BASE_URL . '/dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT u.*, r.name AS role_name FROM users u
                              LEFT JOIN roles r ON r.id = u.role_id
                              WHERE u.username = ? AND u.is_active = 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            login($user);
            auditLog('LOGIN', 'auth', $user['id']);
            redirect(BASE_URL . '/dashboard.php');
        }
    }
    $error = t('login.invalid');
}
?>
<!DOCTYPE html>
<html lang="<?= defined('APP_LANG') ? APP_LANG : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('login.title') ?> | Payment Control Tower</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center px-4" style="font-family:'Sarabun','Segoe UI',system-ui,sans-serif;background:radial-gradient(circle at top left, rgba(164,214,94,.22), transparent 24%), linear-gradient(135deg, #003B5C 0%, #005432 100%);">
<div class="w-full max-w-md">
  <div class="overflow-hidden rounded-3xl border border-white/25 bg-white/95 shadow-2xl backdrop-blur">
    <div class="px-8 py-7 text-center" style="background:linear-gradient(135deg, #003B5C 0%, #006B3F 100%);">
      <img src="<?= BASE_URL ?>/uploads/branding/alumet-logo.jpg" alt="Alumet" class="mx-auto mb-4 h-16 w-auto rounded-xl bg-white px-4 py-2 shadow-lg">
      <h1 class="text-xl font-bold text-white">Payment Control Tower</h1>
      <p class="mt-1 text-sm" style="color:#D9F0B4;"><?= h(getSetting('company_name', 'Alumet Co., Ltd.')) ?></p>
    </div>

    <div class="px-8 py-8">
      <?php if ($error): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="mb-4 flex justify-end">
        <?php $currentLang = defined('APP_LANG') ? APP_LANG : 'en'; ?>
        <a href="?lang=<?= $currentLang === 'en' ? 'th' : 'en' ?>" class="text-xs px-2 py-1 rounded border text-gray-500 hover:bg-gray-100 transition-colors"><?= $currentLang === 'en' ? 'TH' : 'EN' ?></a>
      </div>
      <form method="POST" class="space-y-4">
        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700"><?= t('login.username') ?></label>
          <input
            type="text"
            name="username"
            required
            autofocus
            class="w-full rounded-xl border px-4 py-2.5 outline-none transition"
            style="border-color:#cdd9d2;"
            onfocus="this.style.borderColor='#006B3F'; this.style.boxShadow='0 0 0 3px rgba(0,107,63,.15)'"
            onblur="this.style.borderColor='#cdd9d2'; this.style.boxShadow='none'"
            placeholder="<?= t('login.placeholder.username') ?>"
          >
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700"><?= t('login.password') ?></label>
          <input
            type="password"
            name="password"
            required
            class="w-full rounded-xl border px-4 py-2.5 outline-none transition"
            style="border-color:#cdd9d2;"
            onfocus="this.style.borderColor='#006B3F'; this.style.boxShadow='0 0 0 3px rgba(0,107,63,.15)'"
            onblur="this.style.borderColor='#cdd9d2'; this.style.boxShadow='none'"
            placeholder="<?= t('login.placeholder.password') ?>"
          >
        </div>
        <button type="submit" class="w-full rounded-xl py-2.5 font-semibold text-white transition hover:opacity-95" style="background:#006B3F;">
          <?= t('btn.login') ?>
        </button>
      </form>

      <div class="mt-6 rounded-2xl border p-4" style="background:#F7FBF8; border-color:#DCE8F0;">
        <p class="mb-2 text-xs font-medium text-gray-500"><?= t('login.demo_accounts') ?></p>
        <div class="grid grid-cols-2 gap-1 text-xs text-gray-600">
          <span>admin / admin1234</span><span style="color:#003B5C;">Admin</span>
          <span>maker1 / maker1234</span><span style="color:#006B3F;">Maker</span>
          <span>checker1 / checker1234</span><span style="color:#6B8E23;">Checker</span>
          <span>approver1 / approver1234</span><span style="color:#8A5A12;">Approver</span>
          <span>finance1 / finance1234</span><span style="color:#006B3F;">Finance Mgr</span>
          <span>md1 / md1234</span><span style="color:#9E2A2B;">MD</span>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>

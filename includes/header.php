<?php
/**
 * ============================================================
 *  Fayl: includes/header.php
 *  Vazifasi: Sahifaning yuqori qismi (<head>, menyu, flash xabarlar).
 *
 *  Ishlatilishi (sahifada):
 *      $pageTitle = 'Katalog';
 *      require_once __DIR__ . '/includes/auth.php';
 *      require_once __DIR__ . '/includes/header.php';
 *      ... sahifa mazmuni ...
 *      require_once __DIR__ . '/includes/footer.php';
 * ============================================================
 */

// Sahifa sarlavhasi berilmagan bo'lsa, standart nom ishlatiladi
$pageTitle = $pageTitle ?? 'Bosh sahifa';

$user = currentUser();

// Joriy sahifaning yo'li (menyuda faol havolani ajratish uchun). Misol: "login.php"
$currentPath = ltrim(str_replace(BASE_URL, '', $_SERVER['SCRIPT_NAME']), '/');

// Menyu bandlari. 'show' – bu band kimga ko'rinishini belgilaydi.
$navItems = [
    ['file' => 'index.php',       'label' => "Bosh sahifa",         'show' => true],
    ['file' => 'catalog.php',     'label' => "Katalog",             'show' => true],
    ['file' => 'resources.php',   'label' => "Elektron resurslar",  'show' => $user !== null && $user['role'] === 'talaba'],
    ['file' => 'cabinet.php',     'label' => "Mening kabinetim",    'show' => $user !== null && $user['role'] === 'talaba'],
    ['file' => 'admin/index.php', 'label' => "Band qilishlar",      'show' => isAdmin()],
    ['file' => 'admin/books.php', 'label' => "Kitoblarni boshqarish", 'show' => isAdmin()],
    ['file' => 'login.php',       'label' => "Kirish",              'show' => $user === null],
    ['file' => 'register.php',    'label' => "Ro'yxatdan o'tish",   'show' => $user === null],
];

// Flash xabarlar uchun Tailwind ranglari
$flashColors = [
    'success' => 'bg-green-50 text-green-800 border-green-300',
    'error'   => 'bg-red-50 text-red-800 border-red-300',
    'info'    => 'bg-blue-50 text-blue-800 border-blue-300',
];
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <!-- Telefon ekranida to'g'ri ko'rinishi uchun -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> – Kutubxona ARM</title>

    <!-- Tailwind CSS (CDN orqali) -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex flex-col bg-slate-100 text-slate-800">

    <!-- ====== SAYT SARLAVHASI VA MENYU ====== -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-10">
        <div class="max-w-6xl mx-auto px-4 flex flex-wrap items-center justify-between min-h-[60px]">

            <!-- Logotip -->
            <a href="<?= e(url('index.php')) ?>" class="text-xl font-bold text-blue-600">📚 Kutubxona ARM</a>

            <!-- Telefon uchun menyu tugmasi (md ekrandan kichik bo'lsa ko'rinadi) -->
            <button type="button" id="menuToggle" aria-label="Menyuni ochish"
                    class="md:hidden border border-slate-300 rounded-md px-3 py-1 text-xl">☰</button>

            <!-- Menyu: telefonda yashirin, kompyuterda doim ochiq -->
            <nav id="nav" class="hidden w-full md:w-auto md:flex flex-col md:flex-row md:items-center gap-1 pb-3 md:pb-0">
                <?php foreach ($navItems as $item): ?>
                    <?php if ($item['show']): ?>
                        <?php
                        $isActive = ($currentPath === $item['file']);
                        $linkClass = $isActive
                            ? 'bg-blue-50 text-blue-600'
                            : 'text-slate-700 hover:bg-blue-50 hover:text-blue-600';
                        ?>
                        <a href="<?= e(url($item['file'])) ?>"
                           class="px-3 py-2 rounded-md text-[15px] <?= $linkClass ?>">
                            <?= e($item['label']) ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($user !== null): ?>
                    <!-- Chiqish: CSRF himoyali POST forma -->
                    <form method="post" action="<?= e(url('logout.php')) ?>" class="md:ml-2">
                        <?= csrfField() ?>
                        <button type="submit"
                                class="w-full text-left px-3 py-2 rounded-md text-[15px] text-red-600 hover:bg-red-50">
                            Chiqish (<?= e($user['name']) ?>)
                        </button>
                    </form>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- ====== ASOSIY QISM ====== -->
    <main class="flex-1 w-full max-w-6xl mx-auto px-4 py-6">

        <!-- Flash xabarlar (masalan: "Xush kelibsiz!") -->
        <?php foreach (getFlashes() as $flash): ?>
            <div class="mb-4 border rounded-md px-4 py-3 <?= e($flashColors[$flash['type']] ?? $flashColors['info']) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>
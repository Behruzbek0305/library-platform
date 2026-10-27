<?php
/**
 * ============================================================
 *  Fayl: includes/header.php
 *  Vazifasi: Sahifaning yuqori qismi (<head>, menyu, flash xabarlar).
 *
 *  Menyu ikki ko'rinishda:
 *    - kompyuter (1024px dan keng): sarlavhaning ichida bir qatorda
 *    - telefon va planshet: ☰ tugmasi bosilganda o'ng tomondan
 *      ixcham yon panel (drawer) ochiladi, orqa fon qoraroq bo'ladi
 *  Yon panelning ishlashi: assets/js/menu.js
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
    ['file' => 'index.php',       'label' => "Bosh sahifa",           'show' => true],
    ['file' => 'catalog.php',     'label' => "Katalog",               'show' => true],
    ['file' => 'resources.php',   'label' => "Elektron resurslar",    'show' => $user !== null && $user['role'] === 'talaba'],
    ['file' => 'cabinet.php',     'label' => "Mening kabinetim",      'show' => $user !== null && $user['role'] === 'talaba'],
    ['file' => 'admin/index.php', 'label' => "Band qilishlar",        'show' => isAdmin()],
    ['file' => 'admin/books.php', 'label' => "Kitoblarni boshqarish", 'show' => isAdmin()],
    ['file' => 'login.php',       'label' => "Kirish",                'show' => $user === null],
    ['file' => 'register.php',    'label' => "Ro'yxatdan o'tish",     'show' => $user === null],
];

// Ko'rinadigan bandlarni ajratib olamiz (ikkala menyuda ham shu ro'yxat ishlatiladi)
$visibleNav = array_filter($navItems, static fn(array $item): bool => $item['show']);

// Faol va oddiy havola uchun Tailwind sinflari
$navLinkClass = static fn(bool $active): string => $active
    ? 'bg-blue-50 text-blue-600'
    : 'text-slate-700 hover:bg-blue-50 hover:text-blue-600';

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

    <!-- ====== SAYT SARLAVHASI ====== -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-10">
        <div class="max-w-6xl mx-auto px-4 h-14 flex items-center justify-between gap-3">

            <!-- Logotip -->
            <a href="<?= e(url('index.php')) ?>" class="text-xl font-bold text-blue-600 whitespace-nowrap">📚 Kutubxona ARM</a>

            <!-- Kompyuter menyusi (1024px dan keng ekranda ko'rinadi) -->
            <nav class="hidden lg:flex items-center gap-1" aria-label="Asosiy menyu">
                <?php foreach ($visibleNav as $item): ?>
                    <a href="<?= e(url($item['file'])) ?>"
                       class="px-3 py-2 rounded-md text-[15px] whitespace-nowrap <?= $navLinkClass($currentPath === $item['file']) ?>">
                        <?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>

                <?php if ($user !== null): ?>
                    <!-- Chiqish: CSRF himoyali POST forma -->
                    <form method="post" action="<?= e(url('logout.php')) ?>" class="ml-2">
                        <?= csrfField() ?>
                        <button type="submit"
                                class="px-3 py-2 rounded-md text-[15px] whitespace-nowrap text-red-600 hover:bg-red-50">
                            Chiqish
                        </button>
                    </form>
                <?php endif; ?>
            </nav>

            <!-- ☰ tugmasi (telefon va planshetda ko'rinadi) -->
            <button type="button" id="menuToggle"
                    aria-label="Menyuni ochish" aria-expanded="false" aria-controls="drawer"
                    class="lg:hidden border border-slate-300 rounded-md px-3 py-1 text-xl hover:bg-slate-50">☰</button>
        </div>
    </header>

    <!-- ====== YON PANEL (DRAWER): o'ng tomondan ochiladi ======
         Sarlavhadan tashqarida turadi, shunda u hamma narsaning ustida ko'rinadi.
         "inert" – yopiq paytda ichidagi havolalarga Tab bilan o'tib bo'lmasligi uchun. -->
    <div id="drawerBackdrop"
         class="fixed inset-0 z-40 bg-black/40 opacity-0 pointer-events-none transition-opacity duration-200 lg:hidden"
         aria-hidden="true"></div>

    <aside id="drawer" role="dialog" aria-modal="true" aria-label="Asosiy menyu" inert
           class="fixed top-0 right-0 z-50 h-full w-72 max-w-[85vw] bg-white shadow-2xl
                  translate-x-full transition-transform duration-200 flex flex-col lg:hidden">

        <!-- Yon panel sarlavhasi va yopish tugmasi -->
        <div class="h-14 px-4 flex items-center justify-between border-b border-slate-200 shrink-0">
            <span class="font-bold text-blue-600">Menyu</span>
            <button type="button" id="drawerClose" aria-label="Menyuni yopish"
                    class="rounded-md px-2.5 py-1 text-xl text-slate-500 hover:bg-slate-100">✕</button>
        </div>

        <?php if ($user !== null): ?>
            <!-- Kim tizimga kirgani -->
            <div class="px-4 py-3 border-b border-slate-200 bg-slate-50 shrink-0">
                <div class="font-semibold truncate"><?= e($user['name']) ?></div>
                <div class="text-xs text-slate-500 truncate"><?= e($user['email']) ?> · <?= e($user['role']) ?></div>
            </div>
        <?php endif; ?>

        <!-- Havolalar -->
        <nav class="flex-1 overflow-y-auto p-3 flex flex-col gap-1" aria-label="Telefon menyusi">
            <?php foreach ($visibleNav as $item): ?>
                <a href="<?= e(url($item['file'])) ?>"
                   class="px-3 py-2.5 rounded-md text-[15px] <?= $navLinkClass($currentPath === $item['file']) ?>">
                    <?= e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($user !== null): ?>
            <!-- Chiqish tugmasi panelning pastida -->
            <form method="post" action="<?= e(url('logout.php')) ?>" class="p-3 border-t border-slate-200 shrink-0">
                <?= csrfField() ?>
                <button type="submit"
                        class="w-full px-3 py-2.5 rounded-md text-[15px] text-red-600 border border-red-200 hover:bg-red-50">
                    Chiqish
                </button>
            </form>
        <?php endif; ?>
    </aside>

    <!-- ====== ASOSIY QISM ====== -->
    <main class="flex-1 w-full max-w-6xl mx-auto px-4 py-6">

        <!-- Flash xabarlar (masalan: "Xush kelibsiz!") -->
        <?php foreach (getFlashes() as $flash): ?>
            <div class="mb-4 border rounded-md px-4 py-3 <?= e($flashColors[$flash['type']] ?? $flashColors['info']) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>
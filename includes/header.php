<?php
/**
 * ============================================================
 *  Fayl: includes/header.php
 *  Vazifasi: Sahifaning yuqori qismi (<head>, menyu, flash xabarlar).
 *
 *  YANGI DIZAYN (ko'zga ko'rinarli shrift):
 *    - Sarlavhalar: Montserrat (800) – qalin va yirik, o'zbek harflari (oʻ, gʻ) bilan
 *    - Asosiy matn: Manrope (500) – o'qishga qulay, kattaroq o'lcham (17px)
 *    - Tailwind "blue" ranglari boyroq royal-ko'k rangga almashtirildi:
 *      barcha sahifalardagi bg-blue-600, text-blue-600 va h.k. avtomatik yangilanadi
 *    - sarlavha tepasida gradient chiziq, menyu bandlari kattaroq va qalinroq
 *    - tugma, input va kartochkalar uchun umumiy chiroyli stillar (pastdagi <style>)
 *
 *  Menyu ikki ko'rinishda:
 *    - kompyuter (1024px dan keng): sarlavhaning ichida bir qatorda
 *    - telefon va planshet: ☰ tugmasi bilan ochiladigan yon panel (assets/js/menu.js)
 *
 *  FLASH XABARLAR (success / error / info) o'zi yo'qoladi (pastdagi JS).
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
    ['file' => 'index.php',          'label' => "Bosh sahifa",           'show' => true],
    ['file' => 'catalog.php',        'label' => "Katalog",               'show' => true],
    ['file' => 'resources.php',      'label' => "Elektron resurslar",    'show' => $user !== null && $user['role'] === 'talaba'],
    ['file' => 'cabinet.php',        'label' => "Mening kabinetim",      'show' => $user !== null && $user['role'] === 'talaba'],
    ['file' => 'admin/index.php',    'label' => "Band qilishlar",        'show' => isAdmin()],
    ['file' => 'admin/books.php',    'label' => "Kitoblarni boshqarish", 'show' => isAdmin()],
    ['file' => 'admin/students.php', 'label' => "Talabalar",             'show' => isAdmin()],
    // Faqat Superadmin ko'radi (oddiy admin va talabaga menyuda chiqmaydi)
    ['file' => 'admin/staff.php',    'label' => "Xodimlar",              'show' => isSuperAdmin()],
    ['file' => 'login.php',          'label' => "Kirish",                'show' => $user === null],
    ['file' => 'register.php',       'label' => "Ro'yxatdan o'tish",     'show' => $user === null],
];

// Ko'rinadigan bandlarni ajratib olamiz (ikkala menyuda ham shu ro'yxat ishlatiladi)
$visibleNav = array_filter($navItems, static fn(array $item): bool => $item['show']);

// Faol va oddiy havola uchun Tailwind sinflari (faol band – to'q kapsula + soya)
$navLinkClass = static fn(bool $active): string => $active
    ? 'bg-ink text-white shadow-md shadow-ink/25'
    : 'text-slate-700 hover:bg-blue-50 hover:text-blue-700';

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

    <!-- Shriftlar: Montserrat (sarlavhalar) va Manrope (matn).
         Ikkalasi ham lotin kengaytmasini (oʻ, gʻ) qo'llaydi. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Montserrat:wght@600;700;800;900&display=swap" rel="stylesheet">

    <!-- Tailwind CSS (CDN orqali) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Loyiha uchun umumiy shrift va ranglar
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        // Asosiy matn shrifti
                        sans:    ['Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        // Sarlavhalar shrifti (class: font-display)
                        display: ['Montserrat', 'Manrope', 'ui-sans-serif', 'system-ui', 'sans-serif']
                    },
                    colors: {
                        // ink – to'q ko'k (sarlavha, banner va faol menyu uchun)
                        ink: {
                            DEFAULT: '#0a1a3f',
                            800: '#102553',
                            700: '#1a3470'
                        },
                        // "blue" – boyroq royal-ko'k: bg-blue-600 kabi barcha sinflar shu ranglarni oladi
                        blue: {
                            50:  '#eef4ff',
                            100: '#dbe7ff',
                            200: '#bcd1ff',
                            300: '#8db1ff',
                            400: '#5b8aff',
                            500: '#3466f5',
                            600: '#2350e0',
                            700: '#1c3fb8',
                            800: '#1c3794',
                            900: '#1b3076'
                        }
                    }
                }
            }
        };
    </script>

    <!-- Umumiy stillar: butun saytga ta'sir qiladi (alohida sahifalarni o'zgartirish shart emas) -->
    <style type="text/tailwindcss">
        @layer base {
            /* Asosiy matn kattaroq: Tailwind rem ishlatgani uchun hamma o'lcham mutanosib kattalashadi */
            html { font-size: 16px; scroll-behavior: smooth; }
            @media (min-width: 640px) { html { font-size: 17px; } }

            body { font-weight: 500; letter-spacing: -0.005em; }

            /* Sarlavhalar: Montserrat, juda qalin, ixcham harf oralig'i */
            h1, h2, h3, h4 {
                font-family: 'Montserrat', 'Manrope', ui-sans-serif, system-ui, sans-serif;
                font-weight: 800;
                letter-spacing: -0.02em;
                color: #0a1a3f;
            }
            h1 { font-size: 1.9rem; line-height: 1.15; }
            h2 { font-size: 1.45rem; line-height: 1.2; }
            h3 { line-height: 1.25; }

            /* Jadval sarlavhalari va yorliqlar aniqroq ko'rinsin */
            th { font-family: 'Montserrat', 'Manrope', sans-serif; font-weight: 700; letter-spacing: 0.01em; }
            label { font-weight: 700; color: #1e293b; }

            /* Tugmalar: qalinroq shrift */
            button, [type="submit"] { font-weight: 700; }

            /* Klaviatura bilan yurganda aniq ko'rinadigan fokus */
            :focus-visible { outline: 3px solid rgba(35, 80, 224, .45); outline-offset: 2px; }

            /* Matnni belgilash rangi */
            ::selection { background: #fcd34d; color: #0a1a3f; }
        }

        @layer components {
            /* Hamma tugmalar uchun silliq animatsiya (bosilganda biroz ko'tariladi) */
            button:not(:disabled), a[class*="bg-"] {
                transition: transform .15s ease, box-shadow .15s ease, background-color .15s ease, color .15s ease;
            }
            button:not(:disabled):hover { transform: translateY(-1px); }
            button:not(:disabled):active { transform: translateY(0); }

            /* Input va select: fokusda aniq ko'k halqa */
            input[type="text"], input[type="email"], input[type="password"], input[type="number"],
            select, textarea {
                font-weight: 600;
                transition: border-color .15s ease, box-shadow .15s ease;
            }
            input:focus, select:focus, textarea:focus { border-color: #2350e0; }

            /* Oq kartochkalar: yengil soya bilan "ko'tarilgan" ko'rinish */
            .bg-white.border.rounded-xl, .bg-white.border.rounded-2xl {
                box-shadow: 0 1px 2px rgba(10, 26, 63, .04), 0 4px 14px rgba(10, 26, 63, .04);
            }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col bg-slate-50 text-slate-800 antialiased">

    <!-- ====== SAYT SARLAVHASI ====== -->
    <header class="sticky top-0 z-10">
        <!-- Yuqoridagi ingichka gradient chiziq (brend rangi) -->
        <div class="h-1.5 bg-gradient-to-r from-blue-600 via-indigo-500 to-amber-300" aria-hidden="true"></div>

        <div class="bg-white/90 backdrop-blur border-b border-slate-200 shadow-sm shadow-slate-900/5">
            <div class="max-w-6xl mx-auto px-4 h-[68px] flex items-center justify-between gap-3">

                <!-- Logotip: gradientli kitob belgisi + yirik nom -->
                <a href="<?= e(url('index.php')) ?>" class="flex items-center gap-3 whitespace-nowrap">
                    <span class="grid place-items-center w-11 h-11 rounded-2xl bg-gradient-to-br from-ink to-blue-700 text-amber-300 shadow-lg shadow-blue-700/30" aria-hidden="true">
                        <svg viewBox="0 0 24 24" class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15Z"/>
                            <path d="M4 20.5A2.5 2.5 0 0 0 6.5 23H20v-5"/>
                        </svg>
                    </span>
                    <span class="leading-none">
                        <span class="block font-display text-xl font-black tracking-tight text-ink">Kutubxona</span>
                        <span class="block mt-0.5 text-[11px] font-extrabold uppercase tracking-[0.22em] text-blue-600">Axborot resurs markazi</span>
                    </span>
                </a>

                <!-- Kompyuter menyusi (1024px dan keng ekranda ko'rinadi) -->
                <nav class="hidden lg:flex items-center gap-1" aria-label="Asosiy menyu">
                    <?php foreach ($visibleNav as $item): ?>
                        <a href="<?= e(url($item['file'])) ?>"
                           class="px-3.5 py-2 rounded-full text-[14px] font-bold whitespace-nowrap transition-colors <?= $navLinkClass($currentPath === $item['file']) ?>">
                            <?= e($item['label']) ?>
                        </a>
                    <?php endforeach; ?>

                    <?php if ($user !== null): ?>
                        <!-- Chiqish: CSRF himoyali POST forma -->
                        <form method="post" action="<?= e(url('logout.php')) ?>" class="ml-2">
                            <?= csrfField() ?>
                            <button type="submit"
                                    class="px-3.5 py-2 rounded-full text-[14px] font-bold whitespace-nowrap text-red-600 border border-red-200 hover:bg-red-50 transition-colors">
                                Chiqish
                            </button>
                        </form>
                    <?php endif; ?>
                </nav>

                <!-- ☰ tugmasi (telefon va planshetda ko'rinadi) -->
                <button type="button" id="menuToggle"
                        aria-label="Menyuni ochish" aria-expanded="false" aria-controls="drawer"
                        class="lg:hidden grid place-items-center w-11 h-11 border-2 border-slate-200 rounded-xl text-xl text-ink hover:bg-slate-50">☰</button>
            </div>
        </div>
    </header>

    <!-- ====== YON PANEL (DRAWER): o'ng tomondan ochiladi ======
         Sarlavhadan tashqarida turadi, shunda u hamma narsaning ustida ko'rinadi.
         "inert" – yopiq paytda ichidagi havolalarga Tab bilan o'tib bo'lmasligi uchun.
         Id'lar menu.js bilan bog'liq – o'zgartirmang. -->
    <div id="drawerBackdrop"
         class="fixed inset-0 z-40 bg-ink/60 opacity-0 pointer-events-none transition-opacity duration-200 lg:hidden"
         aria-hidden="true"></div>

    <aside id="drawer" role="dialog" aria-modal="true" aria-label="Asosiy menyu" inert
           class="fixed top-0 right-0 z-50 h-full w-72 max-w-[85vw] bg-white shadow-2xl
                  translate-x-full transition-transform duration-200 flex flex-col lg:hidden">

        <!-- Yon panel sarlavhasi va yopish tugmasi -->
        <div class="h-[68px] px-4 flex items-center justify-between border-b border-slate-200 shrink-0 bg-gradient-to-r from-ink to-ink-700">
            <span class="font-display font-extrabold text-white text-lg">Menyu</span>
            <button type="button" id="drawerClose" aria-label="Menyuni yopish"
                    class="rounded-lg px-2.5 py-1 text-xl text-white/80 hover:bg-white/10">✕</button>
        </div>

        <?php if ($user !== null): ?>
            <!-- Kim tizimga kirgani -->
            <div class="px-4 py-3 border-b border-slate-200 bg-blue-50 shrink-0">
                <div class="font-extrabold truncate text-ink"><?= e($user['name']) ?></div>
                <div class="text-xs font-semibold text-slate-500 truncate"><?= e($user['email']) ?> · <?= e($user['role']) ?></div>
            </div>
        <?php endif; ?>

        <!-- Havolalar -->
        <nav class="flex-1 overflow-y-auto p-3 flex flex-col gap-1" aria-label="Telefon menyusi">
            <?php foreach ($visibleNav as $item): ?>
                <a href="<?= e(url($item['file'])) ?>"
                   class="px-4 py-3 rounded-xl text-base font-bold <?= $navLinkClass($currentPath === $item['file']) ?>">
                    <?= e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($user !== null): ?>
            <!-- Chiqish tugmasi panelning pastida -->
            <form method="post" action="<?= e(url('logout.php')) ?>" class="p-3 border-t border-slate-200 shrink-0">
                <?= csrfField() ?>
                <button type="submit"
                        class="w-full px-3 py-3 rounded-xl text-base font-bold text-red-600 border-2 border-red-200 hover:bg-red-50">
                    Chiqish
                </button>
            </form>
        <?php endif; ?>
    </aside>

    <!-- ====== ASOSIY QISM ====== -->
    <main class="flex-1 w-full max-w-6xl mx-auto px-4 py-6">

        <!-- Flash xabarlar (masalan: "Xush kelibsiz!").
             data-flash – turi (success/error/info); pastdagi skript ularni vaqti bilan yo'qotadi.
             Xatolar role="alert" (ekran o'qigichlar darhol o'qiydi), qolganlari role="status". -->
        <?php $flashList = getFlashes(); ?>
        <?php foreach ($flashList as $flash): ?>
            <?php $flashType = isset($flashColors[$flash['type']]) ? (string) $flash['type'] : 'info'; ?>
            <div data-flash="<?= e($flashType) ?>" role="<?= $flashType === 'error' ? 'alert' : 'status' ?>"
                 class="mb-4 border-2 rounded-xl px-4 py-3 text-[15px] font-bold flex items-start justify-between gap-3 shadow-sm <?= e($flashColors[$flashType]) ?>">
                <div class="min-w-0 break-words"><?= e($flash['message']) ?></div>
                <button type="button" data-flash-close aria-label="Xabarni yopish"
                        class="shrink-0 -my-1 -mr-1 rounded px-2 py-0.5 text-lg leading-none opacity-60 hover:opacity-100">✕</button>
            </div>
        <?php endforeach; ?>

        <?php if ($flashList): ?>
            <!-- ====== FLASH XABARLARNI AVTOMATIK YO'QOTISH (Vanilla JS) ======
                 Xabarlardan KEYIN turadi, shuning uchun ular allaqachon sahifada. -->
            <script>
            (function () {
                'use strict';

                // Necha millisekunddan keyin yo'qolishi (har tur uchun alohida o'zgartiring).
                // Masalan, xato uzoqroq tursin desangiz: error: 10000
                var DURATION = { success: 3000, info: 3000, error: 3000 };
                var FADE_MS  = 600;   // yo'qolish animatsiyasi davomiyligi

                var boxes = document.querySelectorAll('[data-flash]');

                Array.prototype.forEach.call(boxes, function (box) {
                    var type      = box.getAttribute('data-flash');
                    var remaining = DURATION[type] || DURATION.info;   // qolgan vaqt
                    var startedAt = 0;
                    var timer     = null;
                    var closed    = false;

                    /** Xabarni silliq yo'qotadi: xiralashadi, balandligi 0 ga tushadi, keyin o'chadi */
                    function hide() {
                        if (closed) { return; }
                        closed = true;
                        clearTimeout(timer);

                        box.style.overflow   = 'hidden';
                        box.style.maxHeight  = box.offsetHeight + 'px';
                        box.style.transition = 'opacity ' + FADE_MS + 'ms ease, max-height ' + FADE_MS + 'ms ease, ' +
                                               'margin ' + FADE_MS + 'ms ease, padding ' + FADE_MS + 'ms ease, ' +
                                               'border-width ' + FADE_MS + 'ms ease';
                        void box.offsetHeight;   // brauzer boshlang'ich holatni "ko'rib" olishi uchun

                        box.style.opacity       = '0';
                        box.style.maxHeight     = '0';
                        box.style.marginBottom  = '0';
                        box.style.paddingTop    = '0';
                        box.style.paddingBottom = '0';
                        box.style.borderWidth   = '0';

                        setTimeout(function () {
                            if (box.parentNode) { box.parentNode.removeChild(box); }
                        }, FADE_MS + 100);
                    }

                    function start() {
                        if (closed) { return; }
                        startedAt = Date.now();
                        clearTimeout(timer);
                        timer = setTimeout(hide, remaining);
                    }

                    function pause() {
                        if (closed) { return; }
                        clearTimeout(timer);
                        remaining = Math.max(1500, remaining - (Date.now() - startedAt));   // kamida 1.5 soniya qoladi
                    }

                    // Sichqoncha ustiga kelsa yoki tugmaga fokus tushsa – vaqt to'xtaydi
                    box.addEventListener('mouseenter', pause);
                    box.addEventListener('mouseleave', start);
                    box.addEventListener('focusin', pause);
                    box.addEventListener('focusout', start);

                    // ✕ tugmasi – darhol yopadi
                    var closeBtn = box.querySelector('[data-flash-close]');
                    if (closeBtn) { closeBtn.addEventListener('click', hide); }

                    start();
                });
            })();
            </script>
        <?php endif; ?>
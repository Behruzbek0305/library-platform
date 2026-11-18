<?php
/**
 * ============================================================
 *  Fayl: index.php – Bosh sahifa (ZAMONAVIY DIZAYN)
 *  Ko'rsatadi: qidiruv va filtrlar, yangi kitoblar, kategoriyalar,
 *  "Qanday ishlaydi" bo'limi.
 *  (Texnik topshiriq, 6-band: "Bosh sahifa")
 *
 *  3-bosqich: KENGAYTIRILGAN FILTRLASH (mantiq o'zgarmagan)
 *    Qidiruv (nomi/muallif), Soha (field), Kategoriya (category) va
 *    Janr (genre) – ularning ISTALGANI yoki istalgan kombinatsiyasi
 *    ishlaydi. Hech qaysi filtrni tanlash majburiy emas.
 *
 *  YANGI DIZAYN:
 *    - to'q ko'k banner: chap tomonda sarlavha va yirik qidiruv paneli,
 *      o'ng tomonda kitob javoni (faqat CSS bilan chizilgan, rasm yo'q)
 *    - bannerning pastida bazadan olingan haqiqiy ko'rsatkichlar
 *    - yangilangan kitob kartochkasi, kategoriya kartochkalari
 *    - "Qanday ishlaydi" – 3 qadam; kirmaganlar uchun ro'yxatdan o'tish taklifi
 *
 *  Eslatma: avval migrations/007_field_genre.sql ni ishga tushiring.
 *  Qidiruv tavsiyalari oynasi (assets/js/live-search.js) forma ichida ochiladi,
 *  shuning uchun banner qismida overflow-hidden ISHLATILMAGAN (oyna kesilib qolmasin).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';

// ------------------------------------------------------------
// 1. FILTR PARAMETRLARINI XAVFSIZ OLISH (GET)
//    Bo'sh qiymat = "bu filtr tanlanmagan".
// ------------------------------------------------------------
$q        = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$field    = mb_substr(trim((string) ($_GET['field'] ?? '')), 0, 100);
$category = (string) ($_GET['category'] ?? '');
$genre    = mb_substr(trim((string) ($_GET['genre'] ?? '')), 0, 100);

// Kategoriya faqat ruxsat etilgan ro'yxatdan bo'lishi shart
if (!in_array($category, BOOK_CATEGORIES, true)) {
    $category = '';
}

$books        = [];     // topilgan kitoblar (filtr yoqilganda)
$newBooks     = [];     // yangi kitoblar (filtr yoqilmaganda)
$categories   = [];     // kategoriyalar va ulardagi kitoblar soni
$fieldOptions = [];     // "Soha" ro'yxati (bazadagi mavjud qiymatlar)
$genreOptions = [];     // "Janr" ro'yxati (bazadagi mavjud qiymatlar)

// Banner ostidagi ko'rsatkichlar (xatolik bo'lsa 0 bo'lib qoladi)
$stats = ['books' => 0, 'available' => 0, 'students' => 0];

try {
    // --------------------------------------------------------
    // 2. SELECT ro'yxatlari uchun qiymatlar – bazadan olinadi,
    //    shuning uchun yangi soha/janr qo'shilsa, ro'yxatda o'zi chiqadi.
    // --------------------------------------------------------
    $fieldOptions = $pdo->query(
        "SELECT DISTINCT `field` FROM books
         WHERE `field` IS NOT NULL AND `field` <> '' ORDER BY `field`"
    )->fetchAll(PDO::FETCH_COLUMN);

    $genreOptions = $pdo->query(
        "SELECT DISTINCT genre FROM books
         WHERE genre IS NOT NULL AND genre <> '' ORDER BY genre"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Soha va janr foydalanuvchi qo'lda yozib yuborishi mumkin bo'lgan qiymatlar:
    // faqat bazada bor qiymatlargina qabul qilinadi, boshqasi "tanlanmagan" bo'ladi.
    if ($field !== '' && !in_array($field, $fieldOptions, true)) {
        $field = '';
    }
    if ($genre !== '' && !in_array($genre, $genreOptions, true)) {
        $genre = '';
    }

    // --------------------------------------------------------
    // 3. DINAMIK SO'ROV: WHERE 1 = 1 ustiga faqat TANLANGAN
    //    filtrlar "AND ..." ko'rinishida qo'shiladi.
    //    DIQQAT: ATTR_EMULATE_PREPARES = false, shuning uchun har bir
    //    nomli parametr so'rovda faqat BIR marta uchrashi kerak.
    // --------------------------------------------------------
    $sql    = 'SELECT * FROM books WHERE 1 = 1';
    $params = [];

    if ($q !== '') {
        // LIKE belgilari (% va _) oddiy harf sifatida qidirilishi uchun "escape" qilinadi
        $escaped = addcslashes($q, '%_\\');
        $sql .= ' AND (title LIKE :q_title OR author LIKE :q_author)';
        $params[':q_title']  = '%' . $escaped . '%';
        $params[':q_author'] = '%' . $escaped . '%';
    }

    if ($field !== '') {
        $sql .= ' AND `field` = :field';
        $params[':field'] = $field;
    }

    if ($category !== '') {
        $sql .= ' AND category = :category';
        $params[':category'] = $category;
    }

    if ($genre !== '') {
        $sql .= ' AND genre = :genre';
        $params[':genre'] = $genre;
    }

    // Tartib: qidiruv bo'lsa – nomi shu so'z bilan boshlanadiganlar tepada
    if ($q !== '') {
        $sql .= ' ORDER BY (title LIKE :q_prefix) DESC, title ASC';
        $params[':q_prefix'] = $escaped . '%';
    } else {
        $sql .= ' ORDER BY title ASC';
    }

    // Filtr yoqilganmi? (qidiruv ham, tanlovlar ham hisobga olinadi)
    $hasFilter = ($q !== '' || $field !== '' || $category !== '' || $genre !== '');

    if ($hasFilter) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $books = $stmt->fetchAll();
    } else {
        // Filtr yo'q: eng so'nggi qo'shilgan 4 ta kitob
        $newBooks = $pdo->query('SELECT * FROM books ORDER BY created_at DESC, id DESC LIMIT 4')->fetchAll();
    }

    // Har bir kategoriyada nechta kitob borligi
    $categories = $pdo->query(
        'SELECT category, COUNT(*) AS total FROM books GROUP BY category ORDER BY category'
    )->fetchAll();

    // Banner ostidagi ko'rsatkichlar: kitoblar, bo'sh nusxalar va talabalar soni
    $row = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM books) AS books,
            (SELECT COALESCE(SUM(available_copies), 0) FROM books) AS available,
            (SELECT COUNT(*) FROM users WHERE role = 'talaba') AS students"
    )->fetch();
    if ($row) {
        $stats = [
            'books'     => (int) $row['books'],
            'available' => (int) $row['available'],
            'students'  => (int) $row['students'],
        ];
    }

} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Bosh sahifada xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi. (007-migratsiya ishga tushirilganmi?)");
    $hasFilter = ($q !== '' || $field !== '' || $category !== '' || $genre !== '');
}

// Tizimga kirmagan mehmonmi? (pastdagi "ro'yxatdan o'ting" taklifi uchun)
$isGuest = currentUser() === null;

// ------------------------------------------------------------
// 3.1. YORDAMCHI FUNKSIYALAR (faqat shu sahifa uchun)
// ------------------------------------------------------------

/**
 * Yangi dizayndagi kitob kartochkasi.
 * Bosilganda kitob oynasi (book-modal.js) ochiladi: bookDataAttrs() kerakli
 * data-atributlarni qo'yadi, JavaScript o'chiq bo'lsa havola kitob sahifasiga olib boradi.
 */
function renderModernBookCard(array $book): void
{
    $style     = categoryStyle((string) $book['category']);
    $available = (int) $book['available_copies'];
    ?>
    <a href="<?= e(url('book.php?id=' . (int) $book['id'])) ?>" <?= bookDataAttrs($book) ?>
       class="group flex flex-col bg-white border border-slate-200 rounded-2xl overflow-hidden
              hover:border-slate-300 hover:shadow-xl hover:shadow-slate-900/5 transition">

        <!-- Muqova: rasm to'liq ko'rinadi (kesilmaydi), bo'lmasa kategoriya belgisi -->
        <div class="relative h-56 flex items-center justify-center text-6xl <?= e($style['bg']) ?>">
            <?php if (!empty($book['cover_image'])): ?>
                <img src="<?= e(url((string) $book['cover_image'])) ?>" alt="<?= e($book['title']) ?>"
                     class="h-full w-full object-contain">
            <?php else: ?>
                <?= $style['emoji'] ?>
            <?php endif; ?>

            <!-- Mavjudlik belgisi: rangli nuqta + matn -->
            <span class="absolute left-3 top-3 inline-flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold shadow-sm
                         <?= $available > 0 ? 'text-green-700' : 'text-red-700' ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= $available > 0 ? 'bg-green-500' : 'bg-red-500' ?>"></span>
                <?= $available > 0 ? 'Bor: ' . $available . ' ta' : "Hozir yo'q" ?>
            </span>
        </div>

        <div class="flex-1 p-4">
            <h3 class="font-bold leading-snug text-ink line-clamp-2"><?= e($book['title']) ?></h3>
            <p class="mt-1 text-sm text-slate-500 line-clamp-1"><?= e($book['author']) ?></p>
            <span class="inline-block mt-3 rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                <?= e($book['category']) ?>
            </span>
        </div>
    </a>
    <?php
}

// Banner o'ng tomonidagi kitob javoni: [balandlik, kenglik, rang, chiziq rangi, qiyalik]
$spines = [
    ['h-40', 'w-9',  'bg-sky-300',    'bg-sky-500/60',    ''],
    ['h-56', 'w-11', 'bg-amber-300',  'bg-amber-500/60',  ''],
    ['h-48', 'w-8',  'bg-white',      'bg-slate-300',     ''],
    ['h-60', 'w-12', 'bg-indigo-300', 'bg-indigo-500/60', ''],
    ['h-44', 'w-9',  'bg-rose-300',   'bg-rose-500/60',   ''],
    ['h-52', 'w-10', 'bg-emerald-300','bg-emerald-600/50',''],
    ['h-56', 'w-9',  'bg-amber-200',  'bg-amber-500/60',  '-rotate-6 origin-bottom-left ml-2'],
    ['h-36', 'w-10', 'bg-sky-200',    'bg-sky-500/50',    'ml-3'],
];

// ------------------------------------------------------------
// 4. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Bosh sahifa';
require_once __DIR__ . '/includes/header.php';

// Filtr maydonlari uchun umumiy Tailwind sinflari (to'q fonda shaffof ko'rinish)
$selectClass = 'w-full min-w-0 h-11 rounded-xl border border-white/20 bg-white/10 px-3 text-sm text-white '
             . 'focus:outline-none focus:ring-2 focus:ring-amber-300/60 [&>option]:text-slate-800';
?>

<!-- ====== ASOSIY BANNER ======
     Fon alohida qatlamda (overflow-hidden shu qatlamda), shunda qidiruv tavsiyalari
     oynasi bannerdan chiqib turganda kesilib qolmaydi. -->
<section class="relative rounded-3xl text-white">

    <!-- Fon: to'q ko'k va juda xira nuqtali to'r -->
    <div class="absolute inset-0 rounded-3xl overflow-hidden bg-ink" aria-hidden="true">
        <div class="absolute inset-0"
             style="background-image: radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px); background-size: 22px 22px;"></div>
    </div>

    <div class="relative grid lg:grid-cols-[1.15fr_0.85fr] gap-8 items-center px-5 sm:px-10 pt-10 pb-8 lg:pt-14 lg:pb-10">

        <!-- Chap: sarlavha va qidiruv paneli -->
        <div class="min-w-0">
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight leading-[1.1]">
                Kerakli kitobni bir zumda toping
            </h1>
            <p class="mt-4 max-w-lg text-base sm:text-lg text-slate-300 leading-relaxed">
                Universitet kutubxonasi katalogidan kitob qidiring, onlayn band qiling
                va kutubxonadan olib keting.
            </p>

            <!-- Qidiruv formasi: GET orqali shu sahifaning o'ziga yuboriladi.
                 relative + data-live-search-root: yozish paytidagi tavsiyalar oynasi
                 forma kengligicha chiqadi (assets/js/live-search.js). -->
            <form method="get" action="<?= e(url('index.php')) ?>" id="filterForm" data-live-search-root
                  class="relative mt-7 space-y-3">

                <!-- Yirik qidiruv maydoni: ichida lupa belgisi va "Qidirish" tugmasi -->
                <div class="relative">
                    <svg viewBox="0 0 24 24" aria-hidden="true"
                         class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"
                         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
                    </svg>

                    <input type="text" name="q" value="<?= e($q) ?>" maxlength="100"
                           placeholder="Kitob nomi yoki muallif..."
                           aria-label="Kitob nomi yoki muallifi"
                           data-live-search
                           data-endpoint="<?= e(url('search.php')) ?>"
                           data-catalog="<?= e(url('index.php')) ?>"
                           class="w-full h-14 pl-12 pr-28 sm:pr-32 rounded-2xl bg-white text-slate-800 placeholder-slate-400
                                  shadow-lg shadow-black/20 focus:outline-none focus:ring-4 focus:ring-amber-300/50">

                    <button type="submit"
                            class="absolute right-2 top-2 bottom-2 px-5 rounded-xl bg-amber-300 hover:bg-amber-200
                                   text-ink text-sm font-bold transition-colors">
                        Qidirish
                    </button>
                </div>

                <!-- Filtrlar: soha, kategoriya, janr -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <select name="field" aria-label="Soha bo'yicha filtr" class="<?= e($selectClass) ?>">
                        <option value="">Barcha sohalar</option>
                        <?php foreach ($fieldOptions as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $opt === $field ? 'selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="category" aria-label="Kategoriya bo'yicha filtr" class="<?= e($selectClass) ?>">
                        <option value="">Barcha kategoriyalar</option>
                        <?php foreach (BOOK_CATEGORIES as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= $cat === $category ? 'selected' : '' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select name="genre" aria-label="Janr bo'yicha filtr" class="<?= e($selectClass) ?>">
                        <option value="">Barcha janrlar</option>
                        <?php foreach ($genreOptions as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= $opt === $genre ? 'selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($hasFilter): ?>
                    <!-- Filtrni tozalash: barcha parametrlarsiz sahifaga qaytadi -->
                    <a href="<?= e(url('index.php')) ?>"
                       class="inline-block text-sm font-semibold text-amber-300 hover:text-amber-200 underline underline-offset-4">
                        Filtrni tozalash
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- O'ng: kitob javoni (faqat CSS bilan; kichik ekranlarda yashirin) -->
        <div class="hidden lg:flex items-end justify-center h-72 select-none" aria-hidden="true">
            <div class="flex items-end gap-1.5 border-b-4 border-amber-300/50 pb-0 px-4">
                <?php foreach ($spines as [$h, $w, $bg, $band, $extra]): ?>
                    <div class="relative <?= e($h . ' ' . $w . ' ' . $bg . ' ' . $extra) ?> rounded-t-md shadow-md shadow-black/30">
                        <!-- Kitob orqasidagi ikkita chiziq (yorliq) -->
                        <span class="absolute inset-x-0 top-4 h-1.5 <?= e($band) ?>"></span>
                        <span class="absolute inset-x-0 top-8 h-1 <?= e($band) ?>"></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Haqiqiy ko'rsatkichlar (bazadan) -->
    <dl class="relative grid grid-cols-3 border-t border-white/10 text-center sm:text-left">
        <div class="px-4 sm:px-10 py-4 sm:py-5">
            <dd class="text-2xl sm:text-3xl font-extrabold"><?= number_format($stats['books'], 0, '', ' ') ?></dd>
            <dt class="text-xs sm:text-sm text-slate-400">kitob katalogda</dt>
        </div>
        <div class="px-4 sm:px-10 py-4 sm:py-5 border-l border-white/10">
            <dd class="text-2xl sm:text-3xl font-extrabold"><?= number_format($stats['available'], 0, '', ' ') ?></dd>
            <dt class="text-xs sm:text-sm text-slate-400">nusxa hozir bo'sh</dt>
        </div>
        <div class="px-4 sm:px-10 py-4 sm:py-5 border-l border-white/10">
            <dd class="text-2xl sm:text-3xl font-extrabold"><?= number_format($stats['students'], 0, '', ' ') ?></dd>
            <dt class="text-xs sm:text-sm text-slate-400">talaba ro'yxatdan o'tgan</dt>
        </div>
    </dl>
</section>

<?php if ($hasFilter): ?>
    <!-- ====== FILTR NATIJALARI ====== -->
    <div class="mt-10 mb-4 flex items-end justify-between gap-3">
        <h2 class="text-2xl font-extrabold tracking-tight text-ink">Natijalar</h2>
        <p class="text-sm text-slate-500">Topildi: <?= count($books) ?> ta kitob</p>
    </div>

    <?php if ($books && $q !== ''): ?>
        <!-- Qidiruv bor: ro'yxat ko'rinishi (eng mosi tepada) -->
        <div class="bg-white border border-slate-200 rounded-2xl divide-y divide-slate-200 overflow-hidden">
            <?php foreach ($books as $book): ?>
                <?php renderBookRow($book); ?>
            <?php endforeach; ?>
        </div>
    <?php elseif ($books): ?>
        <!-- Faqat tanlovlar: kartochkalar -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <?php foreach ($books as $book): ?>
                <?php renderModernBookCard($book); ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white border border-dashed border-slate-300 rounded-2xl p-10 text-center text-slate-500">
            Bu filtrlar bo'yicha hech narsa topilmadi. Ba'zi filtrlarni olib tashlab ko'ring.
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ====== YANGI KITOBLAR (filtr yoqilmaganda) ====== -->
    <div class="mt-12 mb-4 flex items-end justify-between gap-3">
        <h2 class="text-2xl font-extrabold tracking-tight text-ink">Yangi kitoblar</h2>
        <a href="<?= e(url('catalog.php')) ?>" class="text-sm font-semibold text-blue-600 hover:underline">
            Barcha kitoblar
        </a>
    </div>

    <?php if ($newBooks): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <?php foreach ($newBooks as $book): ?>
                <?php renderModernBookCard($book); ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white border border-dashed border-slate-300 rounded-2xl p-8 text-center text-slate-500">
            Hozircha katalogda kitoblar yo'q.
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ====== KATEGORIYALAR (tez filtr: bosilganda shu kategoriya bo'yicha filtrlanadi) ====== -->
<h2 class="mt-14 mb-4 text-2xl font-extrabold tracking-tight text-ink">Kategoriyalar</h2>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
    <?php foreach ($categories as $cat): ?>
        <?php
        $isActive  = ($cat['category'] === $category);
        $catStyle  = categoryStyle((string) $cat['category']);
        ?>
        <a href="<?= e(url('index.php?category=' . urlencode((string) $cat['category']))) ?>"
           class="flex items-center gap-4 rounded-2xl border p-4 transition
                  <?= $isActive ? 'border-ink bg-white ring-2 ring-ink/10' : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-lg hover:shadow-slate-900/5' ?>">
            <span class="grid place-items-center w-14 h-14 shrink-0 rounded-xl text-3xl <?= e($catStyle['bg']) ?>" aria-hidden="true">
                <?= $catStyle['emoji'] ?>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-bold text-ink"><?= e($cat['category']) ?></span>
                <span class="block text-sm text-slate-500"><?= (int) $cat['total'] ?> ta kitob</span>
            </span>
            <svg viewBox="0 0 24 24" aria-hidden="true" class="w-5 h-5 shrink-0 text-slate-400"
                 fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m9 6 6 6-6 6"/>
            </svg>
        </a>
    <?php endforeach; ?>
</div>

<!-- ====== QANDAY ISHLAYDI (ketma-ket 3 qadam, shuning uchun raqamlangan) ====== -->
<h2 class="mt-14 mb-4 text-2xl font-extrabold tracking-tight text-ink">Kitobni qanday olasiz</h2>
<ol class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <li class="bg-white border border-slate-200 rounded-2xl p-6">
        <span class="grid place-items-center w-9 h-9 rounded-full bg-ink text-amber-300 text-sm font-extrabold">1</span>
        <h3 class="mt-4 font-bold text-ink">Kitobni toping</h3>
        <p class="mt-1 text-sm text-slate-600 leading-relaxed">
            Nomi yoki muallifi bo'yicha qidiring, kerak bo'lsa soha, kategoriya va janr bilan toraytiring.
        </p>
    </li>
    <li class="bg-white border border-slate-200 rounded-2xl p-6">
        <span class="grid place-items-center w-9 h-9 rounded-full bg-ink text-amber-300 text-sm font-extrabold">2</span>
        <h3 class="mt-4 font-bold text-ink">Band qiling</h3>
        <p class="mt-1 text-sm text-slate-600 leading-relaxed">
            Olib ketish muddatini tanlang (1, 2 yoki 3 kun). Tizim sizga 6 belgili kod beradi.
        </p>
    </li>
    <li class="bg-white border border-slate-200 rounded-2xl p-6">
        <span class="grid place-items-center w-9 h-9 rounded-full bg-ink text-amber-300 text-sm font-extrabold">3</span>
        <h3 class="mt-4 font-bold text-ink">Kutubxonadan oling</h3>
        <p class="mt-1 text-sm text-slate-600 leading-relaxed">
            Kodni kutubxonachiga ko'rsating. Muddatda olib ketmasangiz, band qilish avtomatik bekor bo'ladi.
        </p>
    </li>
</ol>

<?php if ($isGuest): ?>
    <!-- ====== MEHMONLAR UCHUN TAKLIF ====== -->
    <div class="mt-10 flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-2xl bg-ink px-6 py-6 text-white">
        <div>
            <h2 class="text-xl font-extrabold">Kitob band qilish uchun hisob oching</h2>
            <p class="mt-1 text-sm text-slate-300">Ro'yxatdan o'tish bir daqiqa oladi. Kerak: HEMIS ID va email.</p>
        </div>
        <div class="flex gap-2 shrink-0">
            <a href="<?= e(url('register.php')) ?>"
               class="rounded-xl bg-amber-300 hover:bg-amber-200 text-ink font-bold text-sm px-5 py-2.5 transition-colors">
                Ro'yxatdan o'tish
            </a>
            <a href="<?= e(url('login.php')) ?>"
               class="rounded-xl border border-white/25 hover:bg-white/10 font-semibold text-sm px-5 py-2.5 transition-colors">
                Kirish
            </a>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
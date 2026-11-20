<?php
/**
 * ============================================================
 *  Fayl: catalog.php – Kitoblar katalogi (ZAMONAVIY DIZAYN)
 *  Imkoniyatlar (Texnik topshiriq, 4-band):
 *    №2 – Kitoblar katalogini ko'rish
 *    №3 – Nomi yoki muallifi bo'yicha qidirish
 *    №4 – Kategoriya bo'yicha saralash
 *
 *  KENGAYTIRILGAN FILTRLASH (mantiq o'zgarmagan, index.php bilan bir xil):
 *    Qidiruv (q), Soha (field), Kategoriya (category) va Janr (genre) –
 *    ularning ISTALGANI yoki istalgan kombinatsiyasi ishlaydi.
 *    Manzil misollari:
 *      catalog.php?field=IT
 *      catalog.php?category=Darslik&genre=Darslik
 *      catalog.php?q=dastur&field=IT&category=Darslik
 *
 *  DIZAYN:
 *    - to'q ko'k banner: sarlavha va yirik qidiruv paneli
 *    - soha / kategoriya / janr: MAXSUS DROPDOWN'lar (renderFilterDropdown() –
 *      includes/book_helpers.php, boshqaruv: assets/js/dropdown.js).
 *      Bosilganda chiroyli ro'yxat ochiladi va chevron tepaga buriladi;
 *      variant tanlansa filtr darhol qo'llanadi.
 *    - kategoriya tugmalari (Hammasi / Darslik / Badiiy / Ilmiy)
 *    - faol filtrlar belgilari: har birini alohida ✕ bilan olib tashlash mumkin
 *    - kitob kartochkasi: includes/book_helpers.php dagi renderModernBookCard()
 *
 *  Eslatma: avval migrations/007_field_genre.sql ni ishga tushiring.
 *  Qidiruv tavsiyalari oynasi (assets/js/live-search.js) forma ichida ochiladi,
 *  shuning uchun banner qismida overflow-hidden ISHLATILMAGAN
 *  (fon alohida qatlamda, oyna kesilib qolmasligi uchun).
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

$books        = [];   // topilgan kitoblar
$fieldOptions = [];   // "Soha" ro'yxati (bazadagi mavjud qiymatlar)
$genreOptions = [];   // "Janr" ro'yxati (bazadagi mavjud qiymatlar)
$totalBooks   = 0;    // katalogdagi jami kitoblar (sarlavha ostida ko'rsatiladi)

try {
    // --------------------------------------------------------
    // 2. Dropdown ro'yxatlari uchun qiymatlar – bazadan olinadi,
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

    $totalBooks = (int) $pdo->query('SELECT COUNT(*) FROM books')->fetchColumn();

    // Soha va janr qo'lda yozib yuborilishi mumkin: faqat bazada bor
    // qiymatlar qabul qilinadi, boshqasi "tanlanmagan" hisoblanadi.
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
    $sql    = BOOK_SELECT . ' WHERE 1 = 1';
    $params = [];

    if ($q !== '') {
        // LIKE belgilari (% va _) oddiy harf sifatida qidirilishi uchun "escape" qilinadi
        $escaped = addcslashes($q, '%_\\');
        $sql .= ' AND (b.title LIKE :q_title OR b.author LIKE :q_author)';
        $params[':q_title']  = '%' . $escaped . '%';
        $params[':q_author'] = '%' . $escaped . '%';
    }

    if ($field !== '') {
        $sql .= ' AND b.`field` = :field';
        $params[':field'] = $field;
    }

    if ($category !== '') {
        $sql .= ' AND b.category = :category';
        $params[':category'] = $category;
    }

    if ($genre !== '') {
        $sql .= ' AND b.genre = :genre';
        $params[':genre'] = $genre;
    }

    // Tartib: qidiruv bo'lsa – nomi shu so'z bilan boshlanadiganlar tepada
    if ($q !== '') {
        $sql .= ' ORDER BY (b.title LIKE :q_prefix) DESC, b.title ASC';
        $params[':q_prefix'] = $escaped . '%';
    } else {
        $sql .= ' ORDER BY b.title ASC';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();

} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Katalogda xatolik: ' . $ex->getMessage());
    setFlash('error', "Katalogni yuklashda xatolik yuz berdi. (007-migratsiya ishga tushirilganmi?)");
}

// Biror filtr yoqilganmi? ("Tozalash" tugmasi va belgilar uchun)
$hasFilter = ($q !== '' || $field !== '' || $category !== '' || $genre !== '');

// Joriy filtrlar (havolalar yasash uchun)
$current = ['q' => $q, 'field' => $field, 'category' => $category, 'genre' => $genre];

// ------------------------------------------------------------
// 3.1. YORDAMCHI FUNKSIYA (faqat shu sahifa uchun)
// ------------------------------------------------------------

/**
 * Joriy filtrlardan katalog manzilini yasaydi.
 * $override orqali ayrim filtrni almashtirish yoki ('' berib) olib tashlash mumkin.
 * Bo'sh qiymatlar manzildan tushib qoladi.
 */
function catalogUrl(array $filters, array $override = []): string
{
    $merged = $override + $filters;
    $query  = array_filter($merged, static fn($v): bool => (string) $v !== '');

    return 'catalog.php' . ($query ? '?' . http_build_query($query) : '');
}

// ------------------------------------------------------------
// 4. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Katalog';
require_once __DIR__ . '/includes/header.php';

// Faol filtr belgilari: [yozuv, qaysi filtrni olib tashlaydi]
$chips = [];
if ($q !== '')        { $chips[] = ['«' . $q . '»', 'q']; }
if ($field !== '')    { $chips[] = ['Soha: ' . $field, 'field']; }
if ($category !== '') { $chips[] = ['Kategoriya: ' . $category, 'category']; }
if ($genre !== '')    { $chips[] = ['Janr: ' . $genre, 'genre']; }
?>

<!-- ====== KATALOG BANNERI ======
     Fon alohida qatlamda (overflow-hidden shu qatlamda), shunda qidiruv tavsiyalari
     va dropdown ro'yxatlari bannerdan chiqib turganda kesilib qolmaydi. -->
<section class="relative rounded-3xl text-white">

    <!-- Fon: to'q ko'k va juda xira nuqtali to'r -->
    <div class="absolute inset-0 rounded-3xl overflow-hidden bg-ink" aria-hidden="true">
        <div class="absolute inset-0"
             style="background-image: radial-gradient(rgba(255,255,255,.07) 1px, transparent 1px); background-size: 22px 22px;"></div>
    </div>

    <div class="relative px-5 sm:px-10 pt-9 pb-8">

        <!-- Sarlavha -->
        <div class="min-w-0">
            <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight leading-tight">Kitoblar katalogi</h1>
            <p class="mt-2 text-sm sm:text-base text-slate-300">
                Katalogda jami <b class="text-white"><?= number_format($totalBooks, 0, '', ' ') ?></b> ta kitob.
                Nomi, muallifi, sohasi, kategoriyasi yoki janri bo'yicha toping.
            </p>
        </div>

        <!-- Qidiruv formasi: GET orqali shu sahifaning o'ziga yuboriladi.
             relative + data-live-search-root: yozish paytidagi tavsiyalar oynasi
             forma kengligicha chiqadi (assets/js/live-search.js). -->
        <form method="get" action="<?= e(url('catalog.php')) ?>" data-live-search-root
              class="relative mt-6 space-y-4">

            <!-- Yirik qidiruv maydoni: ichida lupa belgisi va "Qidirish" tugmasi -->
            <div class="relative">
                <svg viewBox="0 0 24 24" aria-hidden="true"
                     class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
                </svg>

                <input type="text" name="q" value="<?= e($q) ?>" maxlength="100"
                       placeholder="Kitob nomi yoki muallif..."
                       aria-label="Nomi yoki muallifi bo'yicha qidirish"
                       data-live-search
                       data-endpoint="<?= e(url('search.php')) ?>"
                       data-catalog="<?= e(url('catalog.php')) ?>"
                       class="w-full h-14 pl-12 pr-28 sm:pr-32 rounded-2xl bg-white text-slate-800 placeholder-slate-400
                              shadow-lg shadow-black/20 focus:outline-none focus:ring-4 focus:ring-amber-300/50">

                <button type="submit"
                        class="absolute right-2 top-2 bottom-2 px-5 rounded-xl bg-amber-300 hover:bg-amber-200
                               text-ink text-sm font-bold transition-colors">
                    Qidirish
                </button>
            </div>

            <!-- Filtrlar: soha, kategoriya, janr – maxsus dropdown'lar.
                 Bosilganda ro'yxat ochiladi va chevron tepaga buriladi (assets/js/dropdown.js).
                 Variant tanlansa forma o'zi yuboriladi. -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <?php renderFilterDropdown('field', 'Soha', 'Barcha sohalar', $fieldOptions, $field); ?>
                <?php renderFilterDropdown('category', 'Kategoriya', 'Barcha kategoriyalar', BOOK_CATEGORIES, $category); ?>
                <?php renderFilterDropdown('genre', 'Janr', 'Barcha janrlar', $genreOptions, $genre); ?>
            </div>

            <?php if ($hasFilter): ?>
                <!-- Barcha filtrlarni (qidiruvni ham) tozalaydi -->
                <a href="<?= e(url('catalog.php')) ?>"
                   class="inline-block text-sm font-semibold text-amber-300 hover:text-amber-200 underline underline-offset-4">
                    Hammasini tozalash
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Kategoriya tugmalari: bosilganda boshqa filtrlar saqlanib, faqat kategoriya almashadi -->
    <nav class="relative border-t border-white/10 px-5 sm:px-10 py-4 flex flex-wrap items-center gap-2"
         aria-label="Kategoriya bo'yicha tezkor filtr">
        <?php
        // [kategoriya qiymati => yozuv]: bo'sh qiymat = hammasi
        $quickCats = ['' => 'Hammasi'];
        foreach (BOOK_CATEGORIES as $cat) {
            $quickCats[$cat] = $cat;
        }
        ?>
        <?php foreach ($quickCats as $value => $label): ?>
            <?php
            $isActive = ($category === (string) $value);
            $catStyle = $value !== '' ? categoryStyle((string) $value) : null;
            ?>
            <a href="<?= e(url(catalogUrl($current, ['category' => (string) $value]))) ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>
               class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-sm font-semibold transition-colors
                      <?= $isActive ? 'bg-amber-300 text-ink' : 'bg-white/10 text-white hover:bg-white/20' ?>">
                <?php if ($catStyle !== null): ?>
                    <span aria-hidden="true"><?= $catStyle['emoji'] ?></span>
                <?php endif; ?>
                <?= e($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</section>

<!-- ====== NATIJALAR SARLAVHASI ====== -->
<div class="mt-10 mb-4 flex flex-wrap items-end justify-between gap-3">
    <h2 class="text-2xl font-extrabold tracking-tight text-ink">
        <?= $hasFilter ? 'Natijalar' : 'Barcha kitoblar' ?>
    </h2>
    <p class="text-sm text-slate-500">Topildi: <b><?= count($books) ?></b> ta kitob</p>
</div>

<?php if ($chips): ?>
    <!-- Faol filtrlar: har birini alohida ✕ bilan olib tashlash mumkin -->
    <div class="mb-5 flex flex-wrap items-center gap-2">
        <?php foreach ($chips as [$chipLabel, $chipKey]): ?>
            <a href="<?= e(url(catalogUrl($current, [$chipKey => '']))) ?>"
               title="Bu filtrni olib tashlash"
               class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm
                      font-medium text-ink hover:border-slate-300 hover:bg-slate-50 transition-colors">
                <span class="break-all"><?= e($chipLabel) ?></span>
                <span class="text-slate-400" aria-hidden="true">✕</span>
                <span class="sr-only">filtrni olib tashlash</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ====== NATIJALAR ====== -->
<?php if ($books && $q !== ''): ?>
    <!-- Qidiruv natijalari: ro'yxat ko'rinishida (eng mosi tepada) -->
    <div class="bg-white border border-slate-200 rounded-2xl divide-y divide-slate-200 overflow-hidden">
        <?php foreach ($books as $book): ?>
            <?php renderBookRow($book); ?>
        <?php endforeach; ?>
    </div>
<?php elseif ($books): ?>
    <!-- Oddiy ko'rish yoki faqat tanlovlar: kartochkalar (renderModernBookCard – book_helpers.php da) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <?php foreach ($books as $book): ?>
            <?php renderModernBookCard($book); ?>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-2xl p-10 text-center text-slate-500">
        <?php if ($hasFilter): ?>
            Bu filtrlar bo'yicha hech narsa topilmadi. Ba'zi filtrlarni olib tashlab ko'ring.
            <div class="mt-4">
                <a href="<?= e(url('catalog.php')) ?>"
                   class="inline-block rounded-xl bg-ink px-5 py-2 text-sm font-bold text-white hover:bg-ink-800 transition-colors">
                    Filtrni tozalash
                </a>
            </div>
        <?php else: ?>
            Hozircha katalogda kitoblar yo'q.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
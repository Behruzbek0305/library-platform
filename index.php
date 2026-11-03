<?php
/**
 * ============================================================
 *  Fayl: index.php – Bosh sahifa
 *  Ko'rsatadi: qidiruv va filtrlar, yangi kitoblar, kategoriyalar.
 *  (Texnik topshiriq, 6-band: "Bosh sahifa")
 *
 *  3-bosqich: KENGAYTIRILGAN FILTRLASH
 *    Qidiruv (nomi/muallif), Soha (field), Kategoriya (category) va
 *    Janr (genre) – ularning ISTALGANI yoki istalgan kombinatsiyasi
 *    ishlaydi. Hech qaysi filtrni tanlash majburiy emas.
 *    Manzil misollari:
 *      index.php?field=IT
 *      index.php?category=Darslik&genre=Darslik
 *      index.php?q=dastur&field=IT&category=Darslik&genre=Darslik
 *
 *  Eslatma: avval migrations/007_field_genre.sql ni ishga tushiring.
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

} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Bosh sahifada xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi. (007-migratsiya ishga tushirilganmi?)");
    $hasFilter = ($q !== '' || $field !== '' || $category !== '' || $genre !== '');
}

// ------------------------------------------------------------
// 4. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Bosh sahifa';
require_once __DIR__ . '/includes/header.php';

// Hamma select va input uchun umumiy Tailwind sinflari
$fieldClass = 'w-full min-w-0 border border-slate-300 rounded-md px-3 py-2 bg-white text-slate-800 text-sm '
            . 'focus:outline-none focus:ring-2 focus:ring-blue-300';
?>

<!-- ====== ASOSIY BANNER VA QIDIRUV / FILTR FORMASI ====== -->
<section class="bg-gradient-to-br from-blue-600 to-indigo-600 text-white rounded-xl px-4 sm:px-6 py-8 text-center">
    <h1 class="text-3xl font-bold mb-2">Kerakli kitobni tez toping</h1>
    <p class="opacity-90 mb-5">
        Talabalar uchun onlayn kutubxona: kitob qidiring, band qiling va elektron resurslarni yuklab oling.
    </p>

    <!-- Filtr formasi: GET orqali shu sahifaning o'ziga yuboriladi.
         relative + data-live-search-root: yozish paytidagi tavsiyalar oynasi
         butun forma kengligicha chiqadi (assets/js/live-search.js). -->
    <form method="get" action="<?= e(url('index.php')) ?>" id="filterForm" data-live-search-root
          class="relative max-w-4xl mx-auto bg-white text-slate-800 text-left rounded-xl p-3
                 grid grid-cols-1 md:grid-cols-3 gap-2">

        <!-- Qidiruv inputi (butun qatorni egallaydi) -->
        <div class="md:col-span-3 min-w-0">
            <input type="text" name="q" value="<?= e($q) ?>" maxlength="100"
                   placeholder="Kitob nomi yoki muallif..."
                   aria-label="Kitob nomi yoki muallifi"
                   data-live-search
                   data-endpoint="<?= e(url('search.php')) ?>"
                   data-catalog="<?= e(url('index.php')) ?>"
                   class="<?= e($fieldClass) ?>">
        </div>

        <!-- Soha -->
        <select name="field" aria-label="Soha bo'yicha filtr" class="<?= e($fieldClass) ?>">
            <option value="">Barcha sohalar</option>
            <?php foreach ($fieldOptions as $opt): ?>
                <option value="<?= e($opt) ?>" <?= $opt === $field ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Kategoriya -->
        <select name="category" aria-label="Kategoriya bo'yicha filtr" class="<?= e($fieldClass) ?>">
            <option value="">Barcha kategoriyalar</option>
            <?php foreach (BOOK_CATEGORIES as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $cat === $category ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Janr -->
        <select name="genre" aria-label="Janr bo'yicha filtr" class="<?= e($fieldClass) ?>">
            <option value="">Barcha janrlar</option>
            <?php foreach ($genreOptions as $opt): ?>
                <option value="<?= e($opt) ?>" <?= $opt === $genre ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Tugmalar -->
        <div class="md:col-span-3 flex gap-2">
            <button type="submit"
                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-5 py-2 text-sm">
                Qidirish
            </button>

            <?php if ($hasFilter): ?>
                <!-- Filtrni tozalash: barcha parametrlarsiz sahifaga qaytadi -->
                <a href="<?= e(url('index.php')) ?>"
                   class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-sm text-center">
                    Filtrni tozalash
                </a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if ($hasFilter): ?>
    <!-- ====== FILTR NATIJALARI ====== -->
    <h2 class="text-xl font-bold mt-8 mb-1">Natijalar</h2>
    <p class="text-slate-500 mb-3">Topildi: <?= count($books) ?> ta kitob</p>

    <?php if ($books && $q !== ''): ?>
        <!-- Qidiruv bor: ro'yxat ko'rinishi (eng mosi tepada) -->
        <div class="bg-white border border-slate-200 rounded-xl divide-y divide-slate-200 overflow-hidden">
            <?php foreach ($books as $book): ?>
                <?php renderBookRow($book); ?>
            <?php endforeach; ?>
        </div>
    <?php elseif ($books): ?>
        <!-- Faqat tanlovlar: kartochkalar -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($books as $book): ?>
                <?php renderBookCard($book); ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
            Bu filtrlar bo'yicha hech narsa topilmadi. Ba'zi filtrlarni olib tashlab ko'ring.
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ====== YANGI KITOBLAR (filtr yoqilmaganda) ====== -->
    <h2 class="text-xl font-bold mt-8 mb-3">Yangi kitoblar</h2>
    <?php if ($newBooks): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ($newBooks as $book): ?>
                <?php renderBookCard($book); ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="bg-white border border-dashed border-slate-300 rounded-xl p-6 text-center text-slate-500">
            Hozircha katalogda kitoblar yo'q.
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ====== KATEGORIYALAR (tez filtr: bosilganda shu kategoriya bo'yicha filtrlanadi) ====== -->
<h2 class="text-xl font-bold mt-8 mb-3">Kategoriyalar</h2>
<div class="flex flex-wrap gap-2">
    <?php foreach ($categories as $cat): ?>
        <?php $isActive = ($cat['category'] === $category); ?>
        <a href="<?= e(url('index.php?category=' . urlencode((string) $cat['category']))) ?>"
           class="border rounded-full px-4 py-2 hover:border-blue-600 hover:text-blue-600
                  <?= $isActive ? 'bg-blue-50 border-blue-600 text-blue-600' : 'bg-white border-slate-200' ?>">
            <?= e($cat['category']) ?>
            <span class="text-slate-400 text-sm">(<?= (int) $cat['total'] ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
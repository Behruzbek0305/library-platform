<?php
/**
 * ============================================================
 *  Fayl: catalog.php – Kitoblar katalogi
 *  Imkoniyatlar (Texnik topshiriq, 4-band):
 *    №2 – Kitoblar katalogini ko'rish
 *    №3 – Nomi yoki muallifi bo'yicha qidirish
 *    №4 – Kategoriya bo'yicha saralash
 *
 *  3-bosqich: KENGAYTIRILGAN FILTRLASH (index.php bilan bir xil mantiq)
 *    Qidiruv (q), Soha (field), Kategoriya (category) va Janr (genre) –
 *    ularning ISTALGANI yoki istalgan kombinatsiyasi ishlaydi.
 *    Hech qaysi filtrni tanlash majburiy emas.
 *    Manzil misollari:
 *      catalog.php?field=IT
 *      catalog.php?category=Darslik&genre=Darslik
 *      catalog.php?q=dastur&field=IT&category=Darslik
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

$books        = [];   // topilgan kitoblar
$fieldOptions = [];   // "Soha" ro'yxati (bazadagi mavjud qiymatlar)
$genreOptions = [];   // "Janr" ro'yxati (bazadagi mavjud qiymatlar)

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

// Biror filtr yoqilganmi? ("Tozalash" tugmasi uchun)
$hasFilter = ($q !== '' || $field !== '' || $category !== '' || $genre !== '');

// ------------------------------------------------------------
// 4. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Katalog';
require_once __DIR__ . '/includes/header.php';

// Hamma select va input uchun umumiy Tailwind sinflari
$fieldClass = 'w-full min-w-0 border border-slate-300 rounded-md px-3 py-2 bg-white text-slate-800 text-sm '
            . 'focus:outline-none focus:ring-2 focus:ring-blue-300';
?>

<h1 class="text-2xl font-bold mb-4">Kitoblar katalogi</h1>

<!-- ====== QIDIRUV VA FILTRLAR ======
     relative + data-live-search-root: yozish paytidagi tavsiyalar oynasi
     butun forma kengligicha chiqadi (assets/js/live-search.js). -->
<form method="get" action="<?= e(url('catalog.php')) ?>" data-live-search-root
      class="relative bg-white border border-slate-200 rounded-xl p-3 mb-3
             grid grid-cols-1 md:grid-cols-3 gap-2">

    <!-- Qidiruv inputi (butun qatorni egallaydi) -->
    <div class="md:col-span-3 min-w-0">
        <input type="text" name="q" value="<?= e($q) ?>" maxlength="100"
               placeholder="Nomi yoki muallifi bo'yicha qidirish..."
               aria-label="Nomi yoki muallifi bo'yicha qidirish"
               data-live-search
               data-endpoint="<?= e(url('search.php')) ?>"
               data-catalog="<?= e(url('catalog.php')) ?>"
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
            <!-- Barcha filtrlarni tozalaydi -->
            <a href="<?= e(url('catalog.php')) ?>"
               class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-sm text-center">
                Tozalash
            </a>
        <?php endif; ?>
    </div>
</form>

<p class="text-slate-500 mb-4">Topildi: <?= count($books) ?> ta kitob</p>

<!-- ====== NATIJALAR ====== -->
<?php if ($books && $q !== ''): ?>
    <!-- Qidiruv natijalari: ro'yxat ko'rinishida (eng mosi tepada) -->
    <div class="bg-white border border-slate-200 rounded-xl divide-y divide-slate-200 overflow-hidden">
        <?php foreach ($books as $book): ?>
            <?php renderBookRow($book); ?>
        <?php endforeach; ?>
    </div>
<?php elseif ($books): ?>
    <!-- Oddiy ko'rish yoki faqat tanlovlar: kartochkalar -->
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
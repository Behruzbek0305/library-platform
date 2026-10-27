<?php
/**
 * ============================================================
 *  Fayl: catalog.php – Kitoblar katalogi
 *  Imkoniyatlar (Texnik topshiriq, 4-band):
 *    №2 – Kitoblar katalogini ko'rish
 *    №3 – Nomi yoki muallifi bo'yicha qidirish
 *    №4 – Kategoriya bo'yicha saralash
 *  Qidiruv GET orqali ishlaydi: catalog.php?q=...&category=...
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';

// ------------------------------------------------------------
// 1. QIDIRUV PARAMETRLARINI XAVFSIZ OLISH
// ------------------------------------------------------------
$q        = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$category = (string) ($_GET['category'] ?? '');

// Kategoriya faqat ruxsat etilgan ro'yxatdan bo'lishi shart
if (!in_array($category, BOOK_CATEGORIES, true)) {
    $category = '';
}

// ------------------------------------------------------------
// 2. SO'ROVNI YIG'ISH (Prepared Statement)
// ------------------------------------------------------------
$sql    = BOOK_SELECT . ' WHERE 1 = 1';
$params = [];

if ($q !== '') {
    // LIKE belgilari (% va _) oddiy harf sifatida qidirilishi uchun ularni "escape" qilamiz
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= ' AND (b.title LIKE :q_title OR b.author LIKE :q_author)';
    $params[':q_title']  = $like;
    $params[':q_author'] = $like;
}

if ($category !== '') {
    $sql .= ' AND b.category = :category';
    $params[':category'] = $category;
}

if ($q !== '') {
    // Eng mosi tepada: avval nomi shu so'z bilan boshlanadiganlar, keyin qolganlari
    $sql .= ' ORDER BY (b.title LIKE :q_prefix) DESC, b.title ASC';
    $params[':q_prefix'] = addcslashes($q, '%_\\') . '%';
} else {
    $sql .= ' ORDER BY b.title ASC';
}

$books = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Katalogda xatolik: ' . $ex->getMessage());
    setFlash('error', "Katalogni yuklashda xatolik yuz berdi.");
}

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Katalog';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-4">Kitoblar katalogi</h1>

<!-- ====== QIDIRUV VA FILTR ====== -->
<form method="get" action="<?= e(url('catalog.php')) ?>"
      class="grid grid-cols-1 md:grid-cols-[2fr_1fr_auto_auto] gap-2 mb-3">

    <!-- Yozish paytidagi tavsiyalar shu blokning ichida chiqadi (assets/js/live-search.js) -->
    <div class="relative min-w-0">
        <input type="text" name="q" value="<?= e($q) ?>" maxlength="100"
               placeholder="Nomi yoki muallifi bo'yicha qidirish..."
               aria-label="Nomi yoki muallifi bo'yicha qidirish"
               data-live-search
               data-endpoint="<?= e(url('search.php')) ?>"
               data-catalog="<?= e(url('catalog.php')) ?>"
               class="w-full min-w-0 border border-slate-300 rounded-md px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
    </div>

    <select name="category"
            class="border border-slate-300 rounded-md px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
        <option value="">Barcha kategoriyalar</option>
        <?php foreach (BOOK_CATEGORIES as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $cat === $category ? 'selected' : '' ?>><?= e($cat) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-5 py-2">
        Qidirish
    </button>

    <?php if ($q !== '' || $category !== ''): ?>
        <a href="<?= e(url('catalog.php')) ?>"
           class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-center">
            Tozalash
        </a>
    <?php endif; ?>
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
    <!-- Oddiy ko'rish: kartochkalar -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ($books as $book): ?>
            <?php renderBookCard($book); ?>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        Hech narsa topilmadi. Boshqa so'z bilan qidirib ko'ring.
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
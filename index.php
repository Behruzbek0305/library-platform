<?php
/**
 * ============================================================
 *  Fayl: index.php – Bosh sahifa
 *  Ko'rsatadi: qidiruv oynasi, yangi kitoblar, kategoriyalar.
 *  (Texnik topshiriq, 6-band: "Bosh sahifa")
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';

// ------------------------------------------------------------
// 1. MA'LUMOTLARNI BAZADAN OLISH
// ------------------------------------------------------------
$newBooks   = [];
$categories = [];

try {
    // Eng so'nggi qo'shilgan 4 ta kitob
    $stmt = $pdo->query(BOOK_SELECT . ' ORDER BY b.created_at DESC, b.id DESC LIMIT 4');
    $newBooks = $stmt->fetchAll();

    // Har bir kategoriyada nechta kitob borligi
    $stmt = $pdo->query('SELECT category, COUNT(*) AS total FROM books GROUP BY category ORDER BY category');
    $categories = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Bosh sahifada xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi.");
}

// ------------------------------------------------------------
// 2. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Bosh sahifa';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ====== ASOSIY BANNER VA QIDIRUV ====== -->
<section class="bg-gradient-to-br from-blue-600 to-indigo-600 text-white rounded-xl px-6 py-10 text-center">
    <h1 class="text-3xl font-bold mb-2">Kerakli kitobni tez toping</h1>
    <p class="opacity-90 mb-5">
        Talabalar uchun onlayn kutubxona: kitob qidiring, band qiling va elektron resurslarni yuklab oling.
    </p>

    <!-- Qidiruv katalog sahifasiga GET orqali yuboriladi -->
    <form method="get" action="<?= e(url('catalog.php')) ?>" class="flex gap-2 max-w-xl mx-auto">
        <input type="text" name="q" maxlength="100" placeholder="Kitob nomi yoki muallif..."
               class="flex-1 rounded-md px-3 py-2 text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-300">
        <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold rounded-md px-5 py-2">
            Qidirish
        </button>
    </form>
</section>

<!-- ====== YANGI KITOBLAR ====== -->
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

<!-- ====== KATEGORIYALAR ====== -->
<h2 class="text-xl font-bold mt-8 mb-3">Kategoriyalar</h2>
<div class="flex flex-wrap gap-2">
    <?php foreach ($categories as $cat): ?>
        <a href="<?= e(url('catalog.php?category=' . urlencode((string) $cat['category']))) ?>"
           class="bg-white border border-slate-200 rounded-full px-4 py-2 hover:border-blue-600 hover:text-blue-600">
            <?= e($cat['category']) ?>
            <span class="text-slate-400 text-sm">(<?= (int) $cat['total'] ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
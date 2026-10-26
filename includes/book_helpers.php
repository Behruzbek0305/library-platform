<?php
/**
 * ============================================================
 *  Fayl: includes/book_helpers.php
 *  Vazifasi: Kitoblar bilan ishlaydigan umumiy qismlar:
 *            kategoriyalar, SQL so'rov shabloni, kitob kartochkasi,
 *            band qilish holati belgisi (badge).
 *
 *  Ulash: require_once __DIR__ . '/includes/book_helpers.php';
 *  (functions.php avval ulangan bo'lishi kerak – auth.php buni qiladi)
 * ============================================================
 */

declare(strict_types=1);

// ------------------------------------------------------------
// 1. KATEGORIYALAR (Texnik topshiriq: darslik, badiiy, ilmiy)
//    Bazadagi books.category ENUM qiymatlari bilan bir xil bo'lishi shart.
// ------------------------------------------------------------
const BOOK_CATEGORIES = ['Darslik', 'Badiiy', 'Ilmiy'];

/** Kategoriyaga qarab muqova uchun belgi va rang (rasm bo'lmasa shu ko'rsatiladi) */
function categoryStyle(string $category): array
{
    return match ($category) {
        'Darslik' => ['emoji' => '📘', 'bg' => 'bg-blue-100'],
        'Badiiy'  => ['emoji' => '📖', 'bg' => 'bg-pink-100'],
        'Ilmiy'   => ['emoji' => '🔬', 'bg' => 'bg-green-100'],
        default   => ['emoji' => '📕', 'bg' => 'bg-slate-200'],
    };
}

// ------------------------------------------------------------
// 2. KITOBLARNI TANLASH UCHUN SQL SHABLONI
//    "available" – bo'sh nusxalar soni:
//    jami nusxalar minus (kutilmoqda + tasdiqlandi) holatidagi band qilishlar.
//    CAST ... SIGNED va GREATEST – natija hech qachon manfiy bo'lmasligi uchun.
//    Shablonga WHERE / ORDER BY qismini o'zingiz qo'shasiz.
// ------------------------------------------------------------
const BOOK_SELECT = "
    SELECT b.*,
           GREATEST(0, CAST(b.copies AS SIGNED) - (
               SELECT COUNT(*) FROM reservations r
               WHERE r.book_id = b.id AND r.status IN ('kutilmoqda', 'tasdiqlandi')
           )) AS available
    FROM books b
";

// ------------------------------------------------------------
// 3. KITOB KARTOCHKASI (bosh sahifa va katalogda ishlatiladi)
// ------------------------------------------------------------
function renderBookCard(array $book): void
{
    $style     = categoryStyle((string) $book['category']);
    $available = (int) $book['available'];
    ?>
    <a href="<?= e(url('book.php?id=' . (int) $book['id'])) ?>"
       class="block bg-white border border-slate-200 rounded-xl overflow-hidden hover:-translate-y-1 hover:shadow-lg transition">

        <!-- Muqova: rasm bo'lsa rasm, bo'lmasa kategoriya belgisi -->
        <div class="h-32 flex items-center justify-center text-5xl <?= e($style['bg']) ?>">
            <?php if (!empty($book['cover_image'])): ?>
                <img src="<?= e(url((string) $book['cover_image'])) ?>" alt="<?= e($book['title']) ?>"
                     class="h-full w-full object-cover">
            <?php else: ?>
                <?= $style['emoji'] ?>
            <?php endif; ?>
        </div>

        <div class="p-3">
            <h3 class="font-bold leading-snug"><?= e($book['title']) ?></h3>
            <p class="text-sm text-slate-500"><?= e($book['author']) ?></p>

            <div class="mt-2 flex flex-wrap gap-1">
                <span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-600">
                    <?= e($book['category']) ?>
                </span>
                <?php if ($available > 0): ?>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">
                        Bor: <?= $available ?> ta
                    </span>
                <?php else: ?>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700">
                        Hozir yo'q
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php
}

// ------------------------------------------------------------
// 4. BAND QILISH HOLATI BELGISI (kabinet va admin panelda ishlatiladi)
// ------------------------------------------------------------
function statusBadge(string $status): string
{
    return match ($status) {
        'tasdiqlandi' => '<span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">Tasdiqlandi</span>',
        'qaytarildi'  => '<span class="text-xs px-2 py-0.5 rounded-full bg-slate-200 text-slate-700">Qaytarildi</span>',
        default       => '<span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Kutilmoqda</span>',
    };
}
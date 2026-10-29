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
 *
 *  1-bosqich o'zgarishlari:
 *    - muqova rasmi kesilmaydi, to'liq ko'rinadi (object-contain)
 *    - kartochka bosilganda kitob sahifasiga o'tmaydi, qalqib chiqadigan
 *      oyna ochiladi (assets/js/book-modal.js). JavaScript o'chiq bo'lsa,
 *      havola avvalgidek kitob sahifasiga olib boradi.
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
// 2.1. JORIY TALABA BAND QILGAN KITOBLAR ID'LARI
//      Qalqib chiqadigan oynada "Siz allaqachon band qilgansiz" deb ko'rsatish uchun.
//      Bir so'rov ichida bazaga faqat bir marta murojaat qilinadi.
// ------------------------------------------------------------
function userBookedIds(): array
{
    global $pdo;
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $ids  = [];
    $user = currentUser();

    // Faqat talabalar kitob band qila oladi
    if ($user === null || $user['role'] !== 'talaba') {
        return $ids;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT book_id FROM reservations
             WHERE user_id = :user_id AND status IN ('kutilmoqda', 'tasdiqlandi')"
        );
        $stmt->execute([':user_id' => $user['id']]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Band qilingan kitoblarni olishda xatolik: ' . $ex->getMessage());
    }

    return $ids;
}

// ------------------------------------------------------------
// 2.2. KITOB MA'LUMOTLARI – HTML data-atributlar ko'rinishida
//      Qalqib chiqadigan oyna (book-modal.js) shu atributlardan o'qiydi.
//      Hamma qiymat e() orqali o'tadi (XSS'dan himoya).
// ------------------------------------------------------------
function bookDataAttrs(array $book): string
{
    $style = categoryStyle((string) $book['category']);
    $cover = !empty($book['cover_image']) ? url((string) $book['cover_image']) : '';

    $attrs = [
        'data-book-card'   => '',
        'data-id'          => (string) (int) $book['id'],
        'data-title'       => (string) $book['title'],
        'data-author'      => (string) $book['author'],
        'data-category'    => (string) $book['category'],
        'data-description' => (string) ($book['description'] ?? ''),
        'data-copies'      => (string) (int) $book['copies'],
        'data-available'   => (string) (int) $book['available'],
        'data-cover'       => $cover,
        'data-emoji'       => $style['emoji'],
        'data-bg'          => $style['bg'],
        'data-booked'      => in_array((int) $book['id'], userBookedIds(), true) ? '1' : '0',
    ];

    $out = [];
    foreach ($attrs as $name => $value) {
        $out[] = $value === '' && $name === 'data-book-card'
            ? $name
            : $name . '="' . e($value) . '"';
    }

    return implode(' ', $out);
}

// ------------------------------------------------------------
// 3. KITOB KARTOCHKASI (bosh sahifa va katalogda ishlatiladi)
//    Bosilganda qalqib chiqadigan oyna ochiladi.
// ------------------------------------------------------------
function renderBookCard(array $book): void
{
    $style     = categoryStyle((string) $book['category']);
    $available = (int) $book['available'];
    ?>
    <a href="<?= e(url('book.php?id=' . (int) $book['id'])) ?>" <?= bookDataAttrs($book) ?>
       class="block bg-white border border-slate-200 rounded-xl overflow-hidden hover:-translate-y-1 hover:shadow-lg transition">

        <!-- Muqova: rasm bo'lsa to'liq ko'rinadi (kesilmaydi), bo'lmasa kategoriya belgisi -->
        <div class="h-40 flex items-center justify-center text-5xl <?= e($style['bg']) ?>">
            <?php if (!empty($book['cover_image'])): ?>
                <img src="<?= e(url((string) $book['cover_image'])) ?>" alt="<?= e($book['title']) ?>"
                     class="h-full w-full object-contain">
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
// 3.0. KITOB QATORI – RO'YXAT KO'RINISHI (qidiruv natijalari uchun)
//      Kichik muqova, nomi, muallifi, kategoriya va "Bor / Hozir yo'q" belgisi.
//      Bosilganda qalqib chiqadigan oyna ochiladi.
// ------------------------------------------------------------
function renderBookRow(array $book): void
{
    $style     = categoryStyle((string) $book['category']);
    $available = (int) $book['available'];
    ?>
    <a href="<?= e(url('book.php?id=' . (int) $book['id'])) ?>" <?= bookDataAttrs($book) ?>
       class="flex items-center gap-3 p-3 hover:bg-slate-50">

        <!-- Kichik muqova (to'liq ko'rinadi) -->
        <div class="w-14 h-14 shrink-0 rounded-lg overflow-hidden flex items-center justify-center text-2xl <?= e($style['bg']) ?>">
            <?php if (!empty($book['cover_image'])): ?>
                <img src="<?= e(url((string) $book['cover_image'])) ?>" alt="" class="w-full h-full object-contain">
            <?php else: ?>
                <?= $style['emoji'] ?>
            <?php endif; ?>
        </div>

        <!-- Nomi, muallifi, kategoriya -->
        <div class="min-w-0 flex-1">
            <div class="font-bold leading-snug truncate"><?= e($book['title']) ?></div>
            <div class="text-sm text-slate-500 truncate"><?= e($book['author']) ?></div>
            <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-600">
                <?= e($book['category']) ?>
            </span>
        </div>

        <!-- Bor yoki yo'q -->
        <?php if ($available > 0): ?>
            <span class="shrink-0 text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">
                Bor: <?= $available ?> ta
            </span>
        <?php else: ?>
            <span class="shrink-0 text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700">
                Hozir yo'q
            </span>
        <?php endif; ?>
    </a>
    <?php
}

// ------------------------------------------------------------
// 3.1. MUQOVA RASMINI DISKDAN O'CHIRISH (admin kitobni o'chirganda yoki rasmni almashtirganda)
//      Faqat uploads/covers/ papkasidagi fayllar o'chiriladi (boshqa joyga tegilmaydi).
// ------------------------------------------------------------
function deleteCoverFile(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === ''
        || !str_starts_with($relativePath, 'uploads/covers/')
        || str_contains($relativePath, '..')) {
        return;
    }

    $fullPath = __DIR__ . '/../' . $relativePath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

// ------------------------------------------------------------
// 4. BAND QILISH HOLATI BELGISI (kabinet va admin panelda ishlatiladi)
// ------------------------------------------------------------
function statusBadge(string $status, ?string $deadline = null): string
{
    if ($status === 'rad_etildi') {
        $isOverdue = $deadline !== null && $deadline !== '' && strtotime($deadline) < time();
        $label = $isOverdue ? "Rad etildi (muddati o'tgan)" : "Rad etildi";
        return '<span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-medium">' . e($label) . '</span>';
    }

    return match ($status) {
        'tasdiqlandi' => '<span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">Tasdiqlandi</span>',
        'qaytarildi'  => '<span class="text-xs px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 font-medium">Qaytarildi</span>',
        default       => '<span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-medium">Kutilmoqda</span>',
    };
}

// ------------------------------------------------------------
// 5. OLIB KETISH MUDDATI (1, 2 yoki 3 kun) VA AVTOMATIK RAD ETISH
// ------------------------------------------------------------
const MAX_PICKUP_DAYS = 3;
const PICKUP_DAY_OPTIONS = [1, 2, 3];

/**
 * Olib ketish muddati o'tib ketgan, hali "kutilmoqda" holatidagi band qilishlarni
 * avtomatik "rad_etildi" holatiga o'tkazadi.
 * Natijada kitob nusxasi darhol bo'shab, boshqa talabalar uchun ochiq bo'ladi.
 */
function expireOverdueReservations(PDO $pdo): int
{
    static $alreadyRun = false;
    if ($alreadyRun) {
        return 0;
    }
    $alreadyRun = true;

    try {
        $stmt = $pdo->prepare(
            "UPDATE reservations
             SET status = 'rad_etildi'
             WHERE status = 'kutilmoqda'
               AND pickup_deadline IS NOT NULL
               AND pickup_deadline < NOW()"
        );
        $stmt->execute();
        return (int) $stmt->rowCount();
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Muddati o\'tgan band qilishlarni yangilashda xatolik: ' . $ex->getMessage());
        return 0;
    }
}

/**
 * Qolgan vaqtni hisoblaydi va inson o'qiy oladigan matn qaytaradi.
 * Masalan: "1 kun 4 soat qoldi", "14 soat qoldi", "Muddati o'tgan".
 */
function formatRemainingTime(?string $deadline): array
{
    if ($deadline === null || $deadline === '') {
        return ['text' => '', 'expired' => false, 'urgent' => false];
    }

    $now = time();
    $target = strtotime($deadline);
    $diff = $target - $now;

    if ($diff <= 0) {
        return [
            'text'    => "Muddati o'tgan",
            'expired' => true,
            'urgent'  => false,
        ];
    }

    $hours   = (int) floor($diff / 3600);
    $minutes = (int) floor(($diff % 3600) / 60);

    if ($hours >= 24) {
        $days     = (int) floor($hours / 24);
        $remHours = $hours % 24;
        $text     = $days . ' kun' . ($remHours > 0 ? ' ' . $remHours . ' soat' : '') . ' qoldi';
        $urgent   = false;
    } elseif ($hours > 0) {
        $text   = $hours . ' soat' . ($minutes > 0 ? ' ' . $minutes . ' daqiqa' : '') . ' qoldi';
        $urgent = $hours <= 6; // oxirgi 6 soat qolganda ogohlantiruvchi qizil
    } else {
        $text   = max(1, $minutes) . ' daqiqa qoldi';
        $urgent = true;
    }

    return [
        'text'    => $text,
        'expired' => false,
        'urgent'  => $urgent,
    ];
}

/**
 * Band qilish formalarida olib ketish muddatini tanlash maydoni
 */
function renderPickupSelect(string $selectId = 'pickup_days', string $selectName = 'pickup_days', int $default = 1): void
{
    ?>
    <div class="space-y-1 text-left">
        <label for="<?= e($selectId) ?>" class="block text-sm font-semibold text-slate-700">
            Qachon olib ketasiz? (Olib ketish muddati)
        </label>
        <select id="<?= e($selectId) ?>" name="<?= e($selectName) ?>" required
                class="w-full border border-slate-300 rounded-md px-3 py-2 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
            <option value="1" <?= $default === 1 ? 'selected' : '' ?>>1 kun ichida (24 soat)</option>
            <option value="2" <?= $default === 2 ? 'selected' : '' ?>>2 kun ichida (48 soat)</option>
            <option value="3" <?= $default === 3 ? 'selected' : '' ?>>3 kun ichida (uzog'i 3 kun / 72 soat)</option>
        </select>
        <p class="text-xs text-slate-500">
            ⚠️ Tanlangan muddat ichida olib ketmasangiz, band qilish avtomatik rad etiladi.
        </p>
    </div>
    <?php
}

// Global $pdo mavjud bo'lsa, muddati o'tganlarni avtomatik tozalaymiz
if (isset($pdo) && $pdo instanceof PDO) {
    expireOverdueReservations($pdo);
}

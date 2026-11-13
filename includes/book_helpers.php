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
//    "available" – bo'sh nusxalar soni (6-bosqich):
//    endi bu SON bazada books.available_copies ustunida AMALDA saqlanadi:
//      * kitob berilganda (offlayn yoki onlayn) 1 taga kamaytiriladi,
//      * «Qaytarildi» deb belgilanganda 1 taga qo'shiladi.
//    IF(...IS NULL...) qismi – migratsiya (006_available_copies.sql)
//    hali ishga tushirilmagan eski bazalarda ham sayt ishlashi uchun
//    dinamik hisoblab qo'yiladi (fallback). Shablonga WHERE / ORDER BY
//    qismini o'zingiz qo'shasiz.
// ------------------------------------------------------------
// Kitoblar to'g'ridan-to'g'ri books jadvalidan olinadi.
// "available" – books.available_copies ustunining qisqa nomi (eski kod bilan moslik uchun).
const BOOK_SELECT = "
    SELECT b.*, b.available_copies AS available
    FROM books b
";

/**
 * Kitobning bo'sh nusxalarini 1 taga KAMAYTIRISH (real-time).
 * So'rov sharti: UPDATE books SET available_copies = available_copies - 1
 *                WHERE id = ? AND available_copies > 0
 *
 * @return bool true – kamaytirildi; false – bo'sh nusxa qolmagan
 *              (yoki ustun hali yo'q – eski baza, unda dinamik hisob ishlaydi).
 */
function decrementAvailableCopies(PDO $pdo, int $bookId): bool
{
    static $hasColumn = null;

    if ($hasColumn === null) {
        $col = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'books'
                AND COLUMN_NAME  = 'available_copies'"
        )->fetchColumn();
        $hasColumn = (int) $col > 0;
    }

    if (!$hasColumn) {
        return true; // migratsiya qilinmagan – BOOK_SELECT dinamik hisoblaydi
    }

    $stmt = $pdo->prepare(
        'UPDATE books
            SET available_copies = available_copies - 1
          WHERE id = :id AND available_copies > 0'
    );
    $stmt->execute([':id' => $bookId]);

    return $stmt->rowCount() > 0;
}

/**
 * Kitobning bo'sh nusxalarini 1 taga QO'SHISH (kitob haqiqatan qaytganda).
 * copies (jami nusxa) dan oshib ketmasligi uchun shart qo'yilgan.
 */
function incrementAvailableCopies(PDO $pdo, int $bookId): void
{
    static $hasColumn = null;

    if ($hasColumn === null) {
        $col = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'books'
                AND COLUMN_NAME  = 'available_copies'"
        )->fetchColumn();
        $hasColumn = (int) $col > 0;
    }

    if (!$hasColumn) {
        return; // ustun yo'q – dinamik hisob baribir to'g'ri ko'rsatadi
    }

    $stmt = $pdo->prepare(
        'UPDATE books
            SET available_copies = available_copies + 1
          WHERE id = :id AND available_copies < copies'
    );
    $stmt->execute([':id' => $bookId]);
}

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
             WHERE user_id = :user_id AND status IN ('kutilmoqda', 'tasdiqlandi', 'berilgan')"
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
        'data-available'   => (string) (int) $book['available_copies'],
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
    $available = (int) $book['available_copies'];
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
    $available = (int) $book['available_copies'];
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
//    $deadline – olib ketish muddati (kutilmoqda uchun)
//    $dueDate  – qaytarish muddati (berilgan uchun; o'tsa KECHIKISH hisoblanadi,
//                kitob baribir talaba qo'lida qolaveradi)
// ------------------------------------------------------------
function statusBadge(string $status, ?string $deadline = null, ?string $dueDate = null, mixed $rejectedBy = false): string
{
    // Muddati o'tib avtomatik rad etilgan (auto_rejected = 1)
    if ($status === 'rad_etildi') {
        // $rejectedBy uzatilgan (false emas) va bo'sh bo'lsa – muddati o'tib tizim avtomatik rad etgan
        if ($rejectedBy !== false && empty($rejectedBy)) {
            return '<span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-medium whitespace-nowrap">⏱️ Avto-rad etilgan</span>';
        }

        // Kutubxonachi qo'lda rad etgan – muddatga qaramasdan shunchaki "Rad etildi"
        return '<span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-medium">Rad etildi</span>';
    }

    // "Berilgan": muddat o'tsa kechikish belgisi chiqadi, lekin kitob avtomatik "qaytarildi" bo'lmaydi
    // DIQQAT: tizimda kitob qo'lga berilganda holat "tasdiqlandi" bo'ladi (onlayn tasdiqlash ham,
    // offlayn berish ham), shuning uchun kechikish belgisi ikkala holat uchun ishlaydi.
    if ($status === 'berilgan' || $status === 'tasdiqlandi') {
        $overdue = $dueDate !== null && $dueDate !== '' && strtotime($dueDate) < time();
        if ($overdue) {
            $label = $status === 'berilgan' ? 'Berilgan' : 'Tasdiqlandi';
            return '<span class="text-xs px-2 py-0.5 rounded-full bg-orange-100 text-orange-700 font-medium">⏰ ' . $label . ' (muddat o\'tdi)</span>'
                 . '<span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-semibold">Kechikkan</span>';
        }
        if ($status === 'berilgan') {
            return '<span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-medium">Berilgan</span>';
        }
        // "tasdiqlandi" (muddati o'tmagan) – pastdagi match ishlaydi
    }

    return match ($status) {
        'tasdiqlandi' => '<span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-medium">Tasdiqlandi</span>',
        'qaytarildi'  => '<span class="text-xs px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 font-medium">Qaytarildi</span>',
        default       => '<span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700 font-medium">Kutilmoqda</span>',
    };
}

// ------------------------------------------------------------
// 4.1. XODIMLAR IZI (kabinet va admin panelda ishlatiladi)
//      Har bir amalni qaysi kutubxonachi bajarganini ALOHIDA ko'rsatadi
//      (turli xodimlar bo'lishi mumkin):
//        - tasdiqlagan / offlayn bergan  (staff_name,    served_by)
//        - «Qaytarildi» deb belgilagan   (returner_name, returned_by)
//        - rad etgan                     (rejecter_name, rejected_by)
//      $r – so'rov qatori: status, is_offline (bo'lsa) va yuqoridagi nomlar
//      (LEFT JOIN users staff / returner / rejecter orqali keladi).
// ------------------------------------------------------------
function renderStaffTrail(array $r): void
{
    $status   = (string) ($r['status'] ?? '');
    $approver = trim((string) ($r['staff_name'] ?? ''));
    $returner = trim((string) ($r['returner_name'] ?? ''));
    $rejecter = trim((string) ($r['rejecter_name'] ?? ''));
    $isOffline = (int) ($r['is_offline'] ?? 0) === 1;

    $line = static function (string $label, string $valueHtml): void {
        echo '<div class="text-slate-700 mt-0.5">' . e($label) . ': ' . $valueHtml . '</div>';
    };
    $none = '<span class="text-slate-400">—</span>';

    // 1) Tasdiqlagan (yoki offlayn bergan) xodim
    if ($approver !== '') {
        $line($isOffline ? 'Bergan xodim' : 'Tasdiqlagan xodim', '<b>' . e($approver) . '</b>');
    } elseif ($status === 'kutilmoqda') {
        // Hali hech kim biriktirilmagan
        $line("Mas'ul xodim", '<span class="text-amber-600 font-medium">Kutilmoqda</span>');
    } elseif (in_array($status, ['tasdiqlandi', 'berilgan', 'qaytarildi'], true)) {
        // Eski yozuv: tasdiqlagan xodim saqlanmagan
        $line('Tasdiqlagan xodim', $none);
    }

    // 2) «Qaytarildi» deb belgilagan xodim
    if ($status === 'qaytarildi') {
        $line('Qaytarilgan deb belgilagan', $returner !== '' ? '<b>' . e($returner) . '</b>' : $none);
    }

    // 3) Rad etgan xodim (NULL bo'lsa – muddati o'tib avtomatik rad etilgan)
    if ($status === 'rad_etildi') {
        $line('Rad etgan xodim', $rejecter !== ''
            ? '<b>' . e($rejecter) . '</b>'
            : '<span class="text-slate-500">Avtomatik (muddati o\'tgan)</span>');
    }
}

/**
 * Xodimlar izini BITTA matn qilib qaytaradi (oyna/modal va data-atributlar uchun).
 * Misol: "Tasdiqlagan: Aliyev A. · Qabul qilgan: Valiyev V."
 */
function staffTrailText(array $r): string
{
    $status    = (string) ($r['status'] ?? '');
    $approver  = trim((string) ($r['staff_name'] ?? ''));
    $returner  = trim((string) ($r['returner_name'] ?? ''));
    $rejecter  = trim((string) ($r['rejecter_name'] ?? ''));
    $isOffline = (int) ($r['is_offline'] ?? 0) === 1;
    $parts     = [];

    if ($approver !== '') {
        $parts[] = ($isOffline ? 'Bergan: ' : 'Tasdiqlagan: ') . $approver;
    } elseif ($status === 'kutilmoqda') {
        $parts[] = 'Kutilmoqda';
    }

    if ($status === 'qaytarildi' && $returner !== '') {
        $parts[] = 'Qabul qilgan: ' . $returner;
    }

    if ($status === 'rad_etildi') {
        $parts[] = $rejecter !== '' ? 'Rad etgan: ' . $rejecter : "Avtomatik (muddati o'tgan)";
    }

    return implode(' · ', $parts);
}

/**
 * Jadvalda ustun bormi? (migratsiya qilinmagan eski bazalarda sayt yiqilib
 * qolmasligi uchun). Natija bir so'rov ichida keshlanadi.
 */
function columnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;

    if (!isset($cache[$key])) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = :t
                AND COLUMN_NAME  = :c'
        );
        $stmt->execute([':t' => $table, ':c' => $column]);
        $cache[$key] = (int) $stmt->fetchColumn() > 0;
    }

    return $cache[$key];
}

// ------------------------------------------------------------
// 5. OLIB KETISH MUDDATI (1, 2 yoki 3 kun) VA AVTOMATIK RAD ETISH
//    OFFLINE_DAY_OPTIONS – kutubxonachi uchun "Offlayn registratsiya"
//    formasidagi muddatlar (1, 2, 3, 5, 10 kun). Bu – kitobning berilish
//    muddati, olib ketish muddati emas, shuning uchun MAX_PICKUP_DAYS
//    chegarasiga kirmaydi.
// ------------------------------------------------------------
const MAX_PICKUP_DAYS = 3;
const PICKUP_DAY_OPTIONS = [1, 2, 3];
const OFFLINE_DAY_OPTIONS = [1, 2, 3, 5, 10];

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
        // 1) Olib olish muddati o'tgan "kutilmoqda" bandlar rad etiladi.
        //    Ular avval books.available_copies ni kamaytirgan bo'lgani uchun
        //    har bir rad etilgan bandga bitta bo'sh nusxa QAYTARILADI (6-bosqich).
        $stmt = $pdo->prepare(
            "SELECT id, book_id FROM reservations
             WHERE status = 'kutilmoqda'
               AND pickup_deadline IS NOT NULL
               AND pickup_deadline < :now"
        );
        // Vaqt PHP'dan (Asia/Tashkent) olinadi – pickup_deadline ham shunday yozilgan
        $stmt->execute([':now' => date('Y-m-d H:i:s')]);
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        if ($expired) {
            $upd = $pdo->prepare(
                "UPDATE reservations
                SET status = 'rad_etildi', auto_rejected = 1, rejected_at = :now
                WHERE id = :id AND status = 'kutilmoqda'"
            );
            $nowStr = date('Y-m-d H:i:s');
            foreach ($expired as $row) {
                $upd->execute([':now' => $nowStr, ':id' => (int) $row['id']]);
                if ($upd->rowCount() > 0) {
                    $count++;
                    incrementAvailableCopies($pdo, (int) $row['book_id']);
                }
            }
        }

        // 2) "tasdiqlandi/berilgan" holatdagi kitoblar muddati o'tsa ham
        //    AVTOMATIK qaytarilmaydi – ular talaba qo'lida turibdi deb
        //    hisoblanadi va available_copies dagi o'rinlari bandligicha qoladi.
        //    Kitob faqat kutubxonachi «Qaytarildi» tugmasini bossa bo'shaydi!

        return $count;
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Muddati o\'tgan band qilishlarni yangilashda xatolik: ' . $ex->getMessage());
        return 0;
    }
}

/**
 * Qolgan vaqtni hisoblaydi va inson o'qiy oladigan matn qaytaradi.
 * Masalan: "1 kun 4 soat qoldi", "14 soat qoldi", "Muddati o'tgan".
 * $overdueLabel – muddat o'tganda chiqadigan matn. Standart: "Muddati o'tgan".
 * Qaytarish muddati uchun "Kechikkan" ma'nosidagi matn beriladi – chunki
 * kitob hali talaba qo'lida (u avtomatik "qaytarildi" bo'lmaydi).
 */
function formatRemainingTime(?string $deadline, string $expiredText = "Muddati o'tgan"): array
{
    if ($deadline === null || $deadline === '') {
        return ['text' => '', 'expired' => false, 'urgent' => false];
    }

    $now = time();
    $target = strtotime($deadline);
    $diff = $target - $now;

    if ($diff <= 0) {
        return [
            'text'    => $expiredText,
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

/**
 * Talaba ID'sini qoidaga muvofiq tozalaydi: bo'shliqlar olib tashlanadi,
 * faqat raqamlar qoldiriladi (HEMIS ID'da bo'shliq bo'lmaydi).
 */
function normalizeHemisId(mixed $value): string
{
    return (string) preg_replace('/\D+/', '', (string) $value);
}

/**
 * "Necha kunga berilayotgani" maydoni (1, 2, 3, 5 yoki 10 kun).
 * Ruxsat etilmagan qiymat kelganda $default qaytariladi.
 */
function offlineLoanDays(mixed $value, int $default = 3): int
{
    $days = filter_var($value, FILTER_VALIDATE_INT);

    return ($days !== false && in_array($days, OFFLINE_DAY_OPTIONS, true)) ? $days : $default;
}

/**
 * Offlayn berish formasi uchun umumiy input sinfi (oyna ichida ham,
 * kitob sahifasida ham bir xil ko'rinishi uchun).
 */
function offlineFieldClass(): string
{
    return 'w-full border border-slate-300 rounded-md px-3 py-2 text-sm bg-white '
         . 'focus:outline-none focus:ring-2 focus:ring-green-300';
}

/**
 * Oflayn berish uchun tanlash maydoni: 1 / 2 / 3 / 5 / 10 kun.
 * $name – POST maydoni nomi (process-offline.php shu nomdan o'qiydi).
 * MUHIM: bu – kitobning QAYTARISH muddati. Muddat tugasa ham kitob
 * avtomatik "qaytarildi" bo'LMAYDI: kutubxonachi kitobni qo'lda
 * qabul qilib, admin panelda «Qaytarildi» tugmasini bosgandagina
 * holat o'zgaradi. Shuning uchun bu yerda hech qanday avtomatik
 * belgilash haqida yozilmaydi.
 */
function renderOfflineDaysSelect(string $selectId, string $name = 'loan_days', int $default = 3): void
{
    ?>
    <div class="space-y-1">
        <label for="<?= e($selectId) ?>" class="block text-sm font-semibold text-slate-700">
            Necha kunga berilmoqda? (Qaytarish muddati)
        </label>
        <select id="<?= e($selectId) ?>" name="<?= e($name) ?>" required
                class="<?= e(offlineFieldClass()) ?>">
            <?php foreach (OFFLINE_DAY_OPTIONS as $day): ?>
                <option value="<?= (int) $day ?>" <?= $day === $default ? 'selected' : '' ?>>
                    <?= (int) $day ?> kun
                </option>
            <?php endforeach; ?>
        </select>
        <p class="text-xs text-slate-500">
            ⏰ Kitob shu muddat ichida qaytarilishi kerak. Muddat o'tsa, band qilish
            admin panelda <b>«Kechikkan»</b> deb ko'rsatiladi – lekin kitob baribir
            talaba qo'lida hisoblanadi. Holatni faqat siz (kutubxonachi) kitobni
            qo'lda qabul qilib, «Qaytarildi» tugmasini bosganingizdagina o'zgartirasiz.
        </p>
    </div>
    <?php
}

/**
 * ============================================================
 *  OFFLAYN BERISH FORMASI (faqat kutubxonachi uchun)
 *  POST → admin/process-offline.php (kitob ID'si book_id bilan)
 *
 *  Maydonlar: hemis_id, student_name, student_email, faculty,
 *             group_name, loan_days + csrf_token + book_id.
 *
 *  $variant – HTML id/prefix (bitta sahifada ikkita forma bo'lsa
 *             takrorlanmasligi uchun).
 *  $title   – modul ichida sarlavha o'rniga ko'rsatiladi (kitob
 *             sahifasida sarlavha kerak emas).
 *  $backText – "Yopish" havolasi matni (bo'sh bo'lsa havola chiqmaydi).
 *
 *  MUHIM: forma har doim to'liq yo'l bilan ochiladi va tekshiriladi:
 *    - action="..." atributi qo'yilganmi (JS tashqarisidagi bloklar ham)
 *    - bo'sh nusxa bor-yo'qligidan qat'i narp ishlaydi
 * ============================================================
 */
function renderOfflineIssueForm(int $bookId, string $variant = 'modal', string $title = '', string $backText = ''): void
{
    if ($bookId < 1) {
        return;
    }

    $fieldClass = offlineFieldClass();
    ?>
    <form method="post" action="<?= e(url('admin/process-offline.php')) ?>"
          class="space-y-3 text-left" data-offline-form
          data-endpoint="<?= e(url('admin/process-offline.php')) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="book_id" value="<?= (int) $bookId ?>">
        <input type="hidden" name="from" value="<?= e($variant) ?>">

        <?php if ($title !== ''): ?>
            <p class="font-bold text-green-800"><?= e($title) ?></p>
        <?php endif; ?>

        <div class="space-y-1">
            <label for="off_hemis_<?= e($variant) ?>" class="block text-sm font-semibold text-slate-700">
                Talabaning HEMIS ID si
            </label>
            <input type="text" id="off_hemis_<?= e($variant) ?>" name="hemis_id" required
                    data-digits-only data-hemis-length="<?= (int) HEMIS_ID_LENGTH ?>"
                    inputmode="numeric" autocomplete="off"
                    class="<?= $fieldClass ?>">
            <p class="text-xs text-slate-500">Faqat raqam.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="space-y-1">
                <label for="off_name_<?= e($variant) ?>" class="block text-sm font-semibold text-slate-700">
                    Talaba FIO si
                </label>
                <input type="text" id="off_name_<?= e($variant) ?>" name="student_name" required
                       maxlength="100" autocomplete="off"
                        class="<?= $fieldClass ?>">
            </div>

            <div class="space-y-1">
                <label for="off_email_<?= e($variant) ?>" class="block text-sm font-semibold text-slate-700">
                    Email adresi
                </label>
                <input type="email" id="off_email_<?= e($variant) ?>" name="student_email" required
                       maxlength="150" autocomplete="off"
                       class="<?= $fieldClass ?>">
            </div>

            <div class="space-y-1">
                <label for="off_faculty_<?= e($variant) ?>" class="block text-sm font-semibold text-slate-700">
                    Fakulteti
                </label>
                <input type="text" id="off_faculty_<?= e($variant) ?>" name="faculty" required
                       maxlength="150" autocomplete="off"
                       class="<?= $fieldClass ?>">
            </div>

            <div class="space-y-1">
                <label for="off_group_<?= e($variant) ?>" class="block text-sm font-semibold text-slate-700">
                    Guruhi
                </label>
                <input type="text" id="off_group_<?= e($variant) ?>" name="group_name" required
                       maxlength="30" autocomplete="off"
                       class="<?= $fieldClass ?>">
            </div>
        </div>

        <?php renderOfflineDaysSelect('off_loan_days_' . $variant, 'loan_days', 3); ?>

        <button type="submit"
                class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold rounded-md px-5 py-2.5">
            Talabaga berish
        </button>

        <?php if ($backText !== ''): ?>
            <button type="button" data-offline-back
                    class="w-full text-sm text-slate-600 hover:text-slate-800 py-1">
                ← <?= e($backText) ?>
            </button>
        <?php endif; ?>
    </form>
    <?php
}

// ------------------------------------------------------------
// 5.1. BAND QILISH KODI (6 belgili: harf + raqam, takrorlanmaydigan)
//      Talaba kutubxonaga borganda shu kodni ko'rsatadi, kutubxonachi
//      uni admin panelda kiritib, buyurtmani bir zumda topadi.
//      Misol: K7M4P2 (ekranda "K7M 4P2" ko'rinishida chiqadi).
//
//      Alfavitdan ko'z adashtiradigan belgilar CHIQARIB TASHLANGAN:
//      0 va O, 1 va I va L – og'zaki aytganda adashib ketmaslik uchun.
//      Qoldi: 23 ta harf + 8 ta raqam = 31 belgi → 31^6 ≈ 887 million kod.
// ------------------------------------------------------------
const RESERVATION_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const RESERVATION_CODE_LENGTH   = 6;

/**
 * Kiritilgan matnni kod ko'rinishiga keltiradi: bo'shliq, tire va boshqa
 * belgilar olib tashlanadi, harflar KATTA qilinadi, eng ko'pi 6 belgi.
 * "k7m 4p2" → "K7M4P2". Qidiruv maydoni va tekshiruvlarda ishlatiladi.
 */
function normalizeReservationCode(string $value): string
{
    $clean = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', $value));
    return substr($clean, 0, RESERVATION_CODE_LENGTH);
}

/**
 * Bazada hali yo'q bo'lgan 6 belgili tasodifiy kod yaratadi.
 * random_int() – kriptografik xavfsiz generator (kodni oldindan taxmin qilib bo'lmaydi).
 *
 * @throws RuntimeException – 20 urinishda ham bo'sh kod topilmasa (amalda bo'lmaydi)
 */
function generateReservationCode(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM reservations WHERE pickup_code = :code');
    $max   = strlen(RESERVATION_CODE_ALPHABET) - 1;

    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($j = 0; $j < RESERVATION_CODE_LENGTH; $j++) {
            $code .= RESERVATION_CODE_ALPHABET[random_int(0, $max)];
        }

        $check->execute([':code' => $code]);
        if ((int) $check->fetchColumn() === 0) {
            return $code;
        }
    }

    throw new RuntimeException('Bo\'sh band qilish kodini topib bo\'lmadi.');
}

/** Kodni o'qishga qulay ko'rinishda chiqaradi: "K7M4P2" → "K7M 4P2" */
function formatReservationCode(string $code): string
{
    $code = strtoupper($code);
    return strlen($code) === RESERVATION_CODE_LENGTH ? substr($code, 0, 3) . ' ' . substr($code, 3) : $code;
}

/**
 * Admin panelda buyurtma yonida kichik kod belgisini chizadi.
 * Kod bo'sh bo'lsa (offlayn berilgan yoki eski yozuv) hech narsa chiqmaydi.
 * Kutilayotgan buyurtmada belgi ko'zga tashlanadigan (sariq) bo'ladi.
 */
function renderCodeBadge(?string $code, string $status): void
{
    if ($code === null || $code === '') {
        return;
    }

    $cls = $status === 'kutilmoqda'
        ? 'bg-amber-100 text-amber-800 border-amber-300'
        : 'bg-slate-100 text-slate-600 border-slate-200';
    ?>
    <span class="inline-block mt-1 px-1.5 py-0.5 rounded border font-mono text-xs font-bold tracking-wider <?= e($cls) ?>"
          title="Talaba kodi">
        🔑 <?= e(formatReservationCode($code)) ?>
    </span>
    <?php
}

// Global $pdo mavjud bo'lsa, muddati o'tganlarni avtomatik tozalaymiz.
// CLI (cron skripti o'zi chaqiradi va sonini to'g'ri chiqaradi) va search.php
// (har bir tugma bosilishida ortiqcha yozuv so'rovi ketmasligi uchun) bundan mustasno.
if (PHP_SAPI !== 'cli' && !defined('SKIP_AUTO_EXPIRE') && isset($pdo) && $pdo instanceof PDO) {
    expireOverdueReservations($pdo);
}
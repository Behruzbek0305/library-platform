<?php
/**
 * ============================================================
 *  Fayl: cabinet.php – Mening kabinetim
 *  Ko'rsatadi: talabaning ma'lumotlari, band qilgan kitoblari va holati.
 *  Holati "kutilmoqda" bo'lganini bekor qilish mumkin.
 *
 *  REFAKTORING:
 *   1) HEMIS ID va generatsiya kodi matni belgilanadi (select-text) va
 *      "Nusxalash" tugmasi bor (assets/js/hemis.js dagi [data-copy] ishlaydi).
 *   2) So'rovga LEFT JOIN users staff qo'shildi: jadvalda "Mas'ul xodim" ko'rinadi.
 *      Biriktirilmagan "kutilmoqda" so'rovda – "Kutilmoqda".
 *   3) Kitob nomi yoki rasmi bosilganda ixcham modal oyna ochiladi
 *      (assets/js/cabinet-modal.js): muqova, kod, vaqtlar, jonli taymer,
 *      mas'ul xodim va holat belgisi.
 *
 *  Eslatma: ustunlar migrations/ orqali qo'shilgan bo'lishi kerak
 *  (served_by, rejected_by, pickup_code).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';
require_once __DIR__ . '/includes/student_helpers.php';

// Faqat tizimga kirgan talabalar uchun (kutubxonachi kira olmaydi)
requireStudent();
$user = currentUser();

// ------------------------------------------------------------
// YORDAMCHI FUNKSIYALAR
// ------------------------------------------------------------

/** Sana-vaqtni "30.10.2026 13:05" ko'rinishida chiqaradi (bo'sh bo'lsa bo'sh matn). */
function formatDateTime(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return date('d.m.Y H:i', strtotime($value));
}

/**
 * Holatga qarab qaysi muddat sanalishini aniqlaydi.
 *   kutilmoqda           → olib ketish muddati (pickup_deadline)
 *   tasdiqlandi/berilgan → qaytarish muddati (due_date)
 *
 * @return array{label: string, value: string}|null
 */
function reservationDeadline(array $r): ?array
{
    $status = (string) ($r['status'] ?? '');

    if ($status === 'kutilmoqda' && !empty($r['pickup_deadline'])) {
        return ['label' => 'Olib ketishga qoldi:', 'value' => (string) $r['pickup_deadline']];
    }

    if (($status === 'tasdiqlandi' || $status === 'berilgan') && !empty($r['due_date'])) {
        return ['label' => 'Topshirishga qoldi:', 'value' => (string) $r['due_date']];
    }

    return null;
}

/** Mas'ul xodim matni: ism, yoki "Kutilmoqda" (hali biriktirilmagan), yoki "—". */
function staffText(array $r): string
{
    $name = trim((string) ($r['staff_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return (string) ($r['status'] ?? '') === 'kutilmoqda' ? 'Kutilmoqda' : '—';
}

/**
 * Modal va ro'yxat uchun holat yozuvi va Tailwind sinflari.
 * Rad etilgan va rejected_by bo'sh bo'lsa – muddati o'tib AVTOMATIK rad etilgan
 * (talaba o'zi bekor qilsa yozuv o'chib ketadi, shuning uchun bu holat faqat avtomatik).
 *
 * @return array{0: string, 1: string} [matn, sinflar]
 */
function reservationStatusInfo(array $r): array
{
    $status = (string) ($r['status'] ?? '');

    if ($status === 'rad_etildi') {
        return empty($r['rejected_by'])
            ? ['⏱️ Avto-rad etilgan', 'bg-red-100 text-red-700']
            : ['Rad etildi', 'bg-red-100 text-red-700'];
    }
    if ($status === 'berilgan') {
        $late = !empty($r['due_date']) && strtotime((string) $r['due_date']) < time();
        return $late
            ? ['Kechikkan', 'bg-orange-100 text-orange-700']
            : ['Berilgan', 'bg-green-100 text-green-700'];
    }

    return match ($status) {
        'tasdiqlandi' => ['Tasdiqlandi', 'bg-blue-100 text-blue-700'],
        'qaytarildi'  => ['Qaytarildi', 'bg-slate-200 text-slate-700'],
        default       => ['Kutilmoqda', 'bg-amber-100 text-amber-700'],
    };
}

/**
 * "Nusxalash" tugmasi. Bosilganda qiymat clipboard'ga tushadi
 * (assets/js/hemis.js dagi [data-copy] ishlovchisi).
 */
function renderCopyButton(string $value, string $label = 'Nusxalash'): void
{
    if ($value === '') {
        return;
    }
    ?>
    <button type="button" data-copy="<?= e($value) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"
            class="inline-flex items-center gap-1 rounded-md border border-slate-300 hover:bg-slate-50
                   text-slate-600 text-xs px-2 py-1 transition-colors">
        <span aria-hidden="true">📋</span><span><?= e($label) ?></span>
    </button>
    <?php
}

/**
 * Modal ochuvchi elementga qo'yiladigan data-atributlar (hammasi e() orqali o'tadi).
 * cabinet-modal.js shu atributlardan o'qiydi.
 */
function reservationDataAttrs(array $r): string
{
    $style    = categoryStyle((string) ($r['category'] ?? ''));
    $deadline = reservationDeadline($r);
    [$statusText, $statusClass] = reservationStatusInfo($r);
    $code = (string) ($r['pickup_code'] ?? '');

    $attrs = [
        'data-title'        => (string) $r['title'],
        'data-author'       => (string) $r['author'],
        'data-book-url'     => url('book.php?id=' . (int) $r['book_id']),
        'data-cover'        => !empty($r['cover_image']) ? url((string) $r['cover_image']) : '',
        'data-emoji'        => $style['emoji'],
        'data-bg'           => $style['bg'],
        'data-code'         => $code,
        'data-code-view'    => $code !== '' ? formatReservationCode($code) : '',
        'data-reserved'     => formatDateTime((string) ($r['created_at'] ?? '')),
        'data-picked'       => formatDateTime((string) ($r['picked_up_at'] ?? '')),
        'data-returned'     => formatDateTime((string) ($r['returned_at'] ?? '')),
        'data-timer-label'  => $deadline['label'] ?? '',
        'data-timer-at'     => $deadline !== null ? date('c', strtotime($deadline['value'])) : '',
        'data-staff'        => staffText($r),
        'data-status'       => $statusText,
        'data-status-class' => $statusClass,
    ];

    $out = ['data-res-open'];
    foreach ($attrs as $name => $value) {
        $out[] = $name . '="' . e($value) . '"';
    }

    return implode(' ', $out);
}

/**
 * Kitob bloki: kichik muqova + nom + muallif. Muqova yoki nom bosilganda modal ochiladi.
 */
function renderBookTrigger(array $r): void
{
    $style = categoryStyle((string) ($r['category'] ?? ''));
    $attrs = reservationDataAttrs($r);
    ?>
    <div class="flex items-start gap-3">
        <button type="button" <?= $attrs ?> aria-label="<?= e('«' . $r['title'] . '» tafsilotlarini ochish') ?>"
                class="w-12 h-16 shrink-0 rounded-md overflow-hidden flex items-center justify-center text-2xl <?= e($style['bg']) ?>">
            <?php if (!empty($r['cover_image'])): ?>
                <img src="<?= e(url((string) $r['cover_image'])) ?>" alt="" class="w-full h-full object-contain">
            <?php else: ?>
                <?= $style['emoji'] ?>
            <?php endif; ?>
        </button>
        <div class="min-w-0">
            <button type="button" <?= $attrs ?>
                    class="font-semibold text-blue-600 hover:underline text-left break-words"><?= e($r['title']) ?></button>
            <div class="text-slate-500 break-words"><?= e($r['author']) ?></div>
        </div>
    </div>
    <?php
}

/** "Mas'ul xodim: Ism" qatori (jadval va telefon kartochkasi uchun). */
function renderStaffLine(array $r): void
{
    $text    = staffText($r);
    $pending = $text === 'Kutilmoqda';
    ?>
    <div class="text-slate-700 mt-0.5">
        Mas'ul xodim:
        <b class="<?= $pending ? 'text-amber-600' : '' ?>"><?= e($text) ?></b>
    </div>
    <?php
}

/**
 * Real vaqt taymer bloki (ro'yxat uchun). Boshlang'ich matnni server hisoblaydi,
 * keyin sahifa oxiridagi skript data-expires bo'yicha har soniyada yangilaydi.
 */
function renderDeadlineTimer(array $r): void
{
    $deadline = reservationDeadline($r);
    if ($deadline === null) {
        return;
    }

    $rem = formatRemainingTime($deadline['value'], 'Muddati tugadi');

    if ($rem['expired']) {
        $cls = 'bg-red-100 text-red-700';
    } elseif ($rem['urgent']) {
        $cls = 'bg-orange-100 text-orange-700';
    } else {
        $cls = 'bg-amber-100 text-amber-800';
    }
    ?>
    <div class="mt-1 text-xs">
        <span class="text-slate-600"><?= e($deadline['label']) ?></span>
        <span data-expires="<?= e(date('c', strtotime($deadline['value']))) ?>"
              class="inline-block px-1.5 py-0.5 rounded font-medium <?= e($cls) ?>">
            <?= e($rem['text']) ?>
        </span>
    </div>
    <?php
}

/**
 * Generatsiya kodini katta ko'rinishda chizadi (faqat "kutilmoqda" holatida).
 * Matn belgilanadi (select-text), yonida "Nusxalash" tugmasi bor.
 * Nusxalanganda bo'shliqsiz kod ketadi: K7M4P2.
 */
function renderPickupCode(array $r): void
{
    $code = (string) ($r['pickup_code'] ?? '');

    if ($r['status'] !== 'kutilmoqda' || $code === '') {
        return;
    }
    ?>
    <div class="mt-2 inline-block rounded-lg border-2 border-dashed border-blue-300 bg-blue-50 px-3 py-2">
        <div class="text-[11px] uppercase tracking-wide text-blue-700">Sizning kodingiz</div>
        <div class="flex items-center gap-2">
            <div class="font-mono text-2xl font-bold tracking-[0.2em] text-blue-800 select-text cursor-text"
                 style="user-select: text; -webkit-user-select: text;">
                <?= e(formatReservationCode($code)) ?>
            </div>
            <?php renderCopyButton($code); ?>
        </div>
        <div class="text-[11px] text-slate-500">Kutubxonada shu kodni ko'rsating</div>
    </div>
    <?php
}

// ------------------------------------------------------------
// 1. BEKOR QILISH (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
        redirect('cabinet.php');
    }

    $reservationId = filter_var($_POST['cancel_id'] ?? null, FILTER_VALIDATE_INT);

    if ($reservationId !== false && $reservationId > 0) {
        try {
            $pdo->beginTransaction();

            // Faqat o'ziga tegishli va hali tasdiqlanmagan band qilishni topamiz (qatorni qulflaymiz)
            $stmt = $pdo->prepare(
                "SELECT book_id FROM reservations
                 WHERE id = :id AND user_id = :user_id AND status = 'kutilmoqda'
                 FOR UPDATE"
            );
            $stmt->execute([':id' => $reservationId, ':user_id' => $user['id']]);
            $cancelBookId = (int) ($stmt->fetchColumn() ?: 0);

            if ($cancelBookId > 0) {
                $del = $pdo->prepare(
                    "DELETE FROM reservations
                     WHERE id = :id AND user_id = :user_id AND status = 'kutilmoqda'"
                );
                $del->execute([':id' => $reservationId, ':user_id' => $user['id']]);

                if ($del->rowCount() > 0) {
                    // Bekor qilingan band bo'sh nusxani QAYTARADI
                    incrementAvailableCopies($pdo, $cancelBookId);
                    $pdo->commit();
                    setFlash('success', 'Band qilish bekor qilindi.');
                } else {
                    $pdo->rollBack();
                    setFlash('error', "Bu band qilishni bekor qilib bo'lmaydi.");
                }
            } else {
                $pdo->rollBack();
                setFlash('error', "Bu band qilishni bekor qilib bo'lmaydi.");
            }
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[Kutubxona-ARM] Bekor qilishda xatolik: ' . $ex->getMessage());
            setFlash('error', 'Kutilmagan xatolik yuz berdi.');
        }
    }

    redirect('cabinet.php');
}

// ------------------------------------------------------------
// 2. FOYDALANUVCHINING BAND QILISHLARINI OLISH
//    LEFT JOIN users staff: so'rovni tasdiqlagan / offlayn bergan kutubxonachi.
//    LEFT JOIN ishlatilgani uchun xodim biriktirilmagan so'rovlar ham tushib qolmaydi.
// ------------------------------------------------------------
$reservations = [];
try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.reserved_date, r.created_at, r.pickup_days, r.pickup_deadline, r.status,
                r.picked_up_at, r.returned_at, r.due_date, r.pickup_code,
                r.served_by, r.rejected_by, r.is_offline,
                b.id AS book_id, b.title, b.author, b.category, b.cover_image,
                staff.name AS staff_name
         FROM reservations r
         JOIN books b ON b.id = r.book_id
         LEFT JOIN users staff ON r.served_by = staff.id
         WHERE r.user_id = :user_id
         ORDER BY r.created_at DESC, r.id DESC'
    );
    $stmt->execute([':user_id' => $user['id']]);
    $reservations = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Kabinetda xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi.");
}

// Talaba ma'lumotlari to'liqmi?
$profileComplete = isStudentProfileComplete($user);

// Ma'lumotlar kartochkasi: [yorliq, qiymat, nusxalash tugmasi kerakmi]
$profileRows = [
    ["To'liq ism",         (string) $user['name'],                 false],
    ['Fakultet',           (string) ($user['faculty'] ?? ''),      false],
    ["Ta'lim yo'nalishi",  (string) ($user['direction'] ?? ''),    false],
    ['Guruh',              (string) ($user['group_name'] ?? ''),   false],
    ['HEMIS ID',           (string) ($user['hemis_id'] ?? ''),     true],
    ['Email',              (string) $user['email'],                false],
];

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Mening kabinetim';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-4">Mening kabinetim</h1>

<!-- ====== TALABA MA'LUMOTLARI ====== -->
<?php if (!$profileComplete): ?>
    <div class="mb-3 border border-amber-300 bg-amber-50 text-amber-800 rounded-md px-4 py-3 text-sm">
        Ma'lumotlaringiz to'liq emas. Kitob band qilish uchun
        <a href="<?= e(url('profile.php')) ?>" class="underline font-semibold">ma'lumotlarni to'ldiring</a>.
    </div>
<?php endif; ?>

<div class="bg-white border border-slate-200 rounded-xl p-4 mb-6">
    <div class="flex items-center justify-between gap-3 mb-3">
        <h2 class="text-lg font-bold">Mening ma'lumotlarim</h2>
        <a href="<?= e(url('profile.php')) ?>"
           class="bg-slate-700 hover:bg-slate-800 text-white text-xs rounded-md px-3 py-1.5">
            Tahrirlash
        </a>
    </div>

    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
        <?php foreach ($profileRows as [$label, $value, $copyable]): ?>
            <div class="min-w-0">
                <dt class="text-slate-500"><?= e($label) ?></dt>
                <dd class="font-semibold break-words flex items-center gap-2 flex-wrap">
                    <?php if ($value !== ''): ?>
                        <!-- select-text: matn bemalol belgilanadi va nusxalanadi -->
                        <span class="select-text cursor-text <?= $copyable ? 'font-mono' : '' ?>"
                              style="user-select: text; -webkit-user-select: text;"><?= e($value) ?></span>
                        <?php if ($copyable): ?>
                            <?php renderCopyButton($value); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-amber-600 font-normal">kiritilmagan</span>
                    <?php endif; ?>
                </dd>
            </div>
        <?php endforeach; ?>
    </dl>
</div>

<!-- ====== BAND QILGAN KITOBLAR ====== -->
<h2 class="text-lg font-bold mb-3">Band qilgan kitoblarim</h2>

<?php if (!$reservations): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        Siz hali kitob band qilmagansiz.
        <a href="<?= e(url('catalog.php')) ?>" class="text-blue-600 hover:underline">Katalogga o'tish</a>
    </div>
<?php else: ?>
    <!-- Kompyuter va planshet (768px dan keng): jadval -->
    <div class="hidden md:block bg-white border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3">Kitob</th>
                    <th class="px-4 py-3">Sana / Muddat / Mas'ul xodim</th>
                    <th class="px-4 py-3">Holati</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $r): ?>
                    <?php $pickupDays = (int) ($r['pickup_days'] ?? 1); ?>
                    <tr class="border-t border-slate-200 align-top">
                        <td class="px-4 py-3">
                            <?php renderBookTrigger($r); ?>
                            <?php renderPickupCode($r); ?>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <div>Band qilingan: <b><?= e(date('d.m.Y', strtotime((string) $r['reserved_date']))) ?></b></div>
                            <?php if (!empty($r['pickup_deadline'])): ?>
                                <div class="text-slate-600 mt-0.5">
                                    Olib ketish: <b><?= $pickupDays ?> kun</b> (<?= e(date('d.m.Y H:i', strtotime((string) $r['pickup_deadline']))) ?> gacha)
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($r['picked_up_at'])): ?>
                                <div class="text-green-700 mt-0.5">Olgan vaqtingiz: <b><?= e(formatDateTime((string) $r['picked_up_at'])) ?></b></div>
                            <?php endif; ?>
                            <?php if (!empty($r['due_date']) && in_array($r['status'], ['tasdiqlandi', 'berilgan'], true)): ?>
                                <div class="text-slate-600 mt-0.5">Topshirish muddati: <b><?= e(formatDateTime((string) $r['due_date'])) ?></b></div>
                            <?php endif; ?>
                            <?php if (!empty($r['returned_at'])): ?>
                                <div class="text-slate-700 mt-0.5">Qaytargan vaqtingiz: <b><?= e(formatDateTime((string) $r['returned_at'])) ?></b></div>
                            <?php endif; ?>

                            <!-- MAS'UL XODIM -->
                            <?php renderStaffLine($r); ?>

                            <!-- REAL VAQT TAYMERI -->
                            <?php renderDeadlineTimer($r); ?>
                        </td>
                        <td class="px-4 py-3"><?= statusBadge((string) $r['status'], (string) ($r['pickup_deadline'] ?? ''), (string) ($r['due_date'] ?? '')) ?></td>
                        <td class="px-4 py-3 text-right">
                            <?php if ($r['status'] === 'kutilmoqda'): ?>
                                <form method="post" action="<?= e(url('cabinet.php')) ?>"
                                      onsubmit="return confirm('Band qilishni bekor qilmoqchimisiz?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="cancel_id" value="<?= (int) $r['id'] ?>">
                                    <button type="submit"
                                            class="bg-red-600 hover:bg-red-700 text-white text-xs rounded-md px-3 py-1.5">
                                        Bekor qilish
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Telefon (768px dan tor): har bir band qilish alohida kartochka -->
    <div class="md:hidden space-y-3">
        <?php foreach ($reservations as $r): ?>
            <?php $pickupDays = (int) ($r['pickup_days'] ?? 1); ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0"><?php renderBookTrigger($r); ?></div>
                    <div class="shrink-0"><?= statusBadge((string) $r['status'], (string) ($r['pickup_deadline'] ?? ''), (string) ($r['due_date'] ?? '')) ?></div>
                </div>

                <?php renderPickupCode($r); ?>

                <div class="mt-2 text-xs text-slate-600 space-y-1">
                    <div>Band qilingan: <b><?= e(date('d.m.Y', strtotime((string) $r['reserved_date']))) ?></b></div>
                    <?php if (!empty($r['pickup_deadline'])): ?>
                        <div>Olib ketish: <b><?= $pickupDays ?> kun</b> (<?= e(date('d.m.Y H:i', strtotime((string) $r['pickup_deadline']))) ?> gacha)</div>
                    <?php endif; ?>

                    <?php if (!empty($r['picked_up_at'])): ?>
                        <div class="text-green-700">Olgan vaqtingiz: <b><?= e(formatDateTime((string) $r['picked_up_at'])) ?></b></div>
                    <?php endif; ?>
                    <?php if (!empty($r['due_date']) && in_array($r['status'], ['tasdiqlandi', 'berilgan'], true)): ?>
                        <div>Topshirish muddati: <b><?= e(formatDateTime((string) $r['due_date'])) ?></b></div>
                    <?php endif; ?>
                    <?php if (!empty($r['returned_at'])): ?>
                        <div class="text-slate-700">Qaytargan vaqtingiz: <b><?= e(formatDateTime((string) $r['returned_at'])) ?></b></div>
                    <?php endif; ?>

                    <!-- MAS'UL XODIM -->
                    <?php renderStaffLine($r); ?>

                    <!-- REAL VAQT TAYMERI -->
                    <?php renderDeadlineTimer($r); ?>
                </div>

                <?php if ($r['status'] === 'kutilmoqda'): ?>
                    <form method="post" action="<?= e(url('cabinet.php')) ?>" class="mt-3"
                          onsubmit="return confirm('Band qilishni bekor qilmoqchimisiz?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="cancel_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit"
                                class="w-full bg-red-600 hover:bg-red-700 text-white text-sm rounded-md py-2">
                            Bekor qilish
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Tailwind CDN dinamik qo'yiladigan holat ranglarini ham bilib olishi uchun (ko'rinmaydi) -->
<div class="hidden bg-amber-100 text-amber-700 bg-blue-100 text-blue-700 bg-green-100 text-green-700
            bg-orange-100 text-orange-700 bg-red-100 text-red-700 bg-slate-200 text-slate-700
            bg-slate-100 bg-pink-100 text-amber-800" aria-hidden="true"></div>

<!-- ====== KITOB VA BUYURTMA TAFSILOTI (MODAL) ======
     Ichini assets/js/cabinet-modal.js to'ldiradi. data-server-time – taymer
     foydalanuvchi kompyuter soatiga bog'liq bo'lmasligi uchun. -->
<div id="resModal" role="dialog" aria-modal="true" aria-labelledby="resModalTitle" inert
     data-server-time="<?= (int) time() ?>"
     class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50
            opacity-0 pointer-events-none transition-opacity duration-200">

    <div class="relative w-full max-w-md max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl">

        <button type="button" id="resModalClose" aria-label="Oynani yopish"
                class="absolute top-2 right-2 z-10 rounded-full bg-white/90 hover:bg-white shadow w-9 h-9 text-lg text-slate-600">✕</button>

        <!-- Muqova (to'liq ko'rinadi) -->
        <div id="resModalCover" class="h-48 flex items-center justify-center text-7xl bg-slate-100">
            <img id="resModalImg" src="" alt="" class="hidden h-full w-full object-contain">
            <span id="resModalEmoji"></span>
        </div>

        <div class="p-5">
            <div class="flex items-start justify-between gap-2">
                <h2 id="resModalTitle" class="text-xl font-bold leading-snug break-words"></h2>
                <span id="resModalStatus" class="shrink-0 text-xs px-2 py-0.5 rounded-full font-medium"></span>
            </div>
            <p class="text-slate-600 mt-1 break-words"><b>Muallif:</b> <span id="resModalAuthor"></span></p>

            <!-- 🔑 Generatsiya kodi (burchakda nusxalash tugmasi) -->
            <div id="resModalCodeBox" class="hidden relative mt-4 rounded-lg border-2 border-dashed border-blue-300 bg-blue-50 px-4 py-3">
                <div class="text-[11px] uppercase tracking-wide text-blue-700">🔑 Generatsiya kodi</div>
                <div id="resModalCode"
                     class="font-mono text-2xl font-bold tracking-[0.2em] text-blue-800 select-text cursor-text"
                     style="user-select: text; -webkit-user-select: text;"></div>
                <button type="button" id="resModalCodeCopy" data-copy="" title="Kodni nusxalash" aria-label="Kodni nusxalash"
                        class="absolute top-2 right-2 inline-flex items-center gap-1 rounded-md border border-blue-300
                               hover:bg-blue-100 text-blue-700 text-xs px-2 py-1 transition-colors">
                    <span aria-hidden="true">📋</span><span>Nusxalash</span>
                </button>
            </div>

            <!-- Vaqtlar va mas'ul xodim -->
            <dl class="mt-4 grid grid-cols-2 gap-x-3 gap-y-3 text-sm">
                <div class="col-span-2">
                    <dt class="text-xs text-slate-500">Band qilingan</dt>
                    <dd id="resModalReserved" class="font-semibold"></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Olib ketilgan</dt>
                    <dd id="resModalPicked" class="font-semibold"></dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500">Qaytarilgan</dt>
                    <dd id="resModalReturned" class="font-semibold"></dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-slate-500">Mas'ul xodim</dt>
                    <dd id="resModalStaff" class="font-semibold break-words"></dd>
                </div>
            </dl>

            <!-- Jonli taymer (sekundlar bilan) -->
            <div id="resModalTimerBox" class="hidden mt-4 rounded-lg bg-slate-50 border border-slate-200 px-4 py-3 text-sm">
                <span id="resModalTimerLabel" class="text-slate-600"></span>
                <span id="resModalTimer" class="inline-block px-2 py-0.5 rounded font-mono font-semibold"></span>
            </div>

            <a id="resModalMore" href="#" class="block mt-4 text-center text-sm text-blue-600 hover:underline">
                Kitob sahifasini ochish →
            </a>
        </div>
    </div>
</div>

<!-- ====== RO'YXATDAGI JONLI TAYMER (Vanilla JS) ======
     Har soniyada [data-expires] elementlarini yangilaydi: "01 kun 02:15:40".
     Matn faqat textContent orqali qo'yiladi (XSS bo'lmaydi). -->
<script>
(function () {
    'use strict';

    var nodes = document.querySelectorAll('[data-expires]');
    if (!nodes.length) { return; }

    // Server va brauzer soati orasidagi farq (soniyada)
    var offset = <?= (int) time() ?> - Math.floor(Date.now() / 1000);

    var BASE    = 'inline-block px-1.5 py-0.5 rounded font-medium ';
    var NORMAL  = BASE + 'bg-amber-100 text-amber-800';
    var URGENT  = BASE + 'bg-orange-100 text-orange-700';
    var EXPIRED = BASE + 'bg-red-100 text-red-700 font-semibold';

    var items = [];
    Array.prototype.forEach.call(nodes, function (node) {
        var ms = Date.parse(node.getAttribute('data-expires'));
        if (!isNaN(ms)) {
            items.push({ node: node, ts: Math.floor(ms / 1000) });
        }
    });

    function two(n) { return n < 10 ? '0' + n : String(n); }

    function tick() {
        var now    = Math.floor(Date.now() / 1000) + offset;
        var active = 0;

        items.forEach(function (item) {
            var left = item.ts - now;

            if (left <= 0) {
                item.node.className   = EXPIRED;
                item.node.textContent = '⚠️ Muddati tugadi';
                return;
            }

            active++;

            var d = Math.floor(left / 86400);
            var h = Math.floor((left % 86400) / 3600);
            var m = Math.floor((left % 3600) / 60);
            var s = left % 60;

            item.node.textContent = two(d) + ' kun ' + two(h) + ':' + two(m) + ':' + two(s);
            item.node.className   = left <= 6 * 3600 ? URGENT : NORMAL;
        });

        if (active === 0) {
            clearInterval(timerId);
        }
    }

    tick();
    var timerId = setInterval(tick, 1000);
})();
</script>

<!-- Modal oyna skripti -->
<script src="<?= e(url('assets/js/cabinet-modal.js')) ?>" defer></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
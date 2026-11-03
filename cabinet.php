<?php
/**
 * ============================================================
 *  Fayl: cabinet.php – Mening kabinetim
 *  Ko'rsatadi: talabaning ma'lumotlari (2-bosqich), band qilgan kitoblari va holati.
 *  Holati "kutilmoqda" bo'lganini bekor qilish mumkin.
 *  (Texnik topshiriq: funksional talab №5, sahifa "Mening kabinetim")
 *
 *  4-bosqich: kitob qachon berilgani (picked_up_at) va qachon
 *  qaytarilgani (returned_at) ham ko'rsatiladi.
 *
 *  REAL VAQT TAYMERI (yangi):
 *    - "kutilmoqda"            → olib ketish muddatigacha (pickup_deadline)
 *    - "tasdiqlandi/berilgan"  → topshirish (qaytarish) muddatigacha (due_date)
 *    Muddat HTML'da data-expires="2026-11-05T18:00:00+05:00" ko'rinishida
 *    turadi, sahifa oxiridagi JavaScript uni har soniyada yangilaydi:
 *    "01 kun 02:15:40". Muddat o'tsa – qizil "Muddati tugadi" belgisi chiqadi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';
require_once __DIR__ . '/includes/student_helpers.php';

// Faqat tizimga kirgan talabalar uchun (kutubxonachi kira olmaydi)
requireStudent();
$user = currentUser();

/**
 * Sana-vaqtni "30.10.2026 13:05" ko'rinishida chiqaradi (bo'sh bo'lsa bo'sh matn).
 */
function formatDateTime(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return date('d.m.Y H:i', strtotime($value));
}

/**
 * Band qilish holatiga qarab, qaysi muddat sanalishini aniqlaydi.
 *   kutilmoqda           → olib ketish muddati (pickup_deadline)
 *   tasdiqlandi/berilgan → qaytarish muddati (due_date)
 * Taymer kerak bo'lmasa (qaytarilgan, rad etilgan yoki muddat yo'q) null qaytaradi.
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

/**
 * Real vaqt taymer blokini chizadi (jadval ham, telefon kartochkasi ham shuni ishlatadi).
 *
 * Boshlang'ich matnni server hisoblaydi (JavaScript o'chiq bo'lsa ham ko'rinadi),
 * keyin sahifa oxiridagi skript data-expires bo'yicha har soniyada yangilaydi.
 * data-expires – ISO 8601 (date('c')), vaqt zonasi bilan: 2026-11-05T18:00:00+05:00.
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
//    (4-bosqich: picked_up_at va returned_at ham olinadi)
//    (Taymer: qaytarish muddati uchun due_date ham olinadi)
// ------------------------------------------------------------
$reservations = [];
try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.reserved_date, r.pickup_days, r.pickup_deadline, r.status,
                r.picked_up_at, r.returned_at, r.due_date,
                b.id AS book_id, b.title, b.author
         FROM reservations r
         JOIN books b ON b.id = r.book_id
         WHERE r.user_id = :user_id
         ORDER BY r.created_at DESC, r.id DESC'
    );
    $stmt->execute([':user_id' => $user['id']]);
    $reservations = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Kabinetda xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi.");
}

// Talaba ma'lumotlari to'liqmi? (to'liq bo'lmasa, ogohlantirish chiqadi)
$profileComplete = isStudentProfileComplete($user);

// Ma'lumotlar kartochkasi uchun qatorlar: [yorliq, qiymat]
$profileRows = [
    ["To'liq ism",         (string) $user['name']],
    ['Fakultet',           (string) ($user['faculty'] ?? '')],
    ["Ta'lim yo'nalishi",  (string) ($user['direction'] ?? '')],
    ['Guruh',              (string) ($user['group_name'] ?? '')],
    ['HEMIS ID',           (string) ($user['hemis_id'] ?? '')],
    ['Email',              (string) $user['email']],
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
        <?php foreach ($profileRows as [$label, $value]): ?>
            <div class="min-w-0">
                <dt class="text-slate-500"><?= e($label) ?></dt>
                <dd class="font-semibold break-words">
                    <?php if ($value !== '' && $label === 'HEMIS ID'): ?>
                        <!-- HEMIS ID: bosilganda nusxalanadi (assets/js/hemis.js) -->
                        <?php renderHemisCopy($value); ?>
                        <span class="text-xs font-normal text-slate-400 ml-1">(nusxalash uchun bosing)</span>
                    <?php elseif ($value !== ''): ?>
                        <?= e($value) ?>
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
                    <th class="px-4 py-3">Sana / Muddat</th>
                    <th class="px-4 py-3">Holati</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $r): ?>
                    <?php $pickupDays = (int) ($r['pickup_days'] ?? 1); ?>
                    <tr class="border-t border-slate-200">
                        <td class="px-4 py-3">
                            <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                               class="font-semibold text-blue-600 hover:underline break-words"><?= e($r['title']) ?></a>
                            <div class="text-slate-500 break-words"><?= e($r['author']) ?></div>
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
                    <div class="min-w-0">
                        <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                           class="font-semibold text-blue-600 hover:underline break-words"><?= e($r['title']) ?></a>
                        <div class="text-sm text-slate-500 break-words"><?= e($r['author']) ?></div>
                    </div>
                    <div class="shrink-0"><?= statusBadge((string) $r['status'], (string) ($r['pickup_deadline'] ?? ''), (string) ($r['due_date'] ?? '')) ?></div>
                </div>

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

<!-- ====== JONLI TAYMER (Vanilla JS) ======
     Har soniyada [data-expires] elementlarini yangilaydi: "01 kun 02:15:40 qoldi".
     Muddat o'tsa – qizil "Muddati tugadi" belgisi.
     Server vaqti bilan sinxronlanadi (foydalanuvchi kompyuter soati noto'g'ri bo'lsa ham to'g'ri ishlaydi).
     Matn faqat textContent orqali qo'yiladi (XSS bo'lmaydi). -->
<script>
(function () {
    'use strict';

    var nodes = document.querySelectorAll('[data-expires]');
    if (!nodes.length) { return; }

    // Server va brauzer soati orasidagi farq (soniyada)
    var offset = <?= (int) time() ?> - Math.floor(Date.now() / 1000);

    // Tailwind sinflari: oddiy / shoshilinch (oxirgi 6 soat) / muddati tugagan
    var BASE    = 'inline-block px-1.5 py-0.5 rounded font-medium ';
    var NORMAL  = BASE + 'bg-amber-100 text-amber-800';
    var URGENT  = BASE + 'bg-orange-100 text-orange-700';
    var EXPIRED = BASE + 'bg-red-100 text-red-700 font-semibold';

    // Har bir element uchun muddatni (soniyada) bir marta o'qib olamiz
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
        var active = 0;   // hali tugamagan taymerlar soni

        items.forEach(function (item) {
            var left = item.ts - now;

            // Muddat o'tgan: qizil badge, taymer to'xtaydi
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

            // Format: 01 kun 02:15:40
            item.node.textContent = two(d) + ' kun ' + two(h) + ':' + two(m) + ':' + two(s);
            item.node.className   = left <= 6 * 3600 ? URGENT : NORMAL;
        });

        // Hammasi tugagan bo'lsa, intervalni to'xtatamiz
        if (active === 0) {
            clearInterval(timerId);
        }
    }

    tick();
    var timerId = setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
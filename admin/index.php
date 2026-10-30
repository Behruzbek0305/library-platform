<?php
/**
 * ============================================================
 *  Fayl: admin/index.php – Admin panel: band qilishlar
 *  Funksional talab №8 (Texnik topshiriq, 4-band):
 *  kutubxonachi band qilishlar ro'yxatini ko'radi va tasdiqlaydi.
 *
 *  Amallar (hammasi POST + CSRF):
 *    approve – "kutilmoqda"  → "tasdiqlandi"  (picked_up_at yoziladi)
 *    return  – "tasdiqlandi" → "qaytarildi"   (returned_at yoziladi, nusxa yana bo'sh)
 *    reject  – "kutilmoqda" → "rad_etildi"   (yozuv saqlanadi, talaba ham ko'radi)
 *
 *  2-bosqich: har bir band qilishda talabaning HEMIS ID'si, fakulteti,
 *  yo'nalishi va guruhi ko'rsatiladi (kitobni kimga berishni aniqlash uchun).
 *
 *  4-bosqich: kitob berilgan (picked_up_at) va qaytarilgan (returned_at)
 *  vaqtlar bazaga yoziladi va jadvalda ko'rsatiladi.
 *  Bo'sh nusxalar soni alohida ustunda saqlanmaydi: u includes/book_helpers.php
 *  dagi BOOK_SELECT orqali har safar hisoblanadi, shuning uchun "qaytarildi"
 *  holati yozilishi bilan nusxa o'zi bo'shaydi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';
require_once __DIR__ . '/../includes/student_helpers.php';

// Faqat kutubxonachi kira oladi
requireAdmin();

// Filtr uchun ruxsat etilgan holatlar ('' – hammasi)
const STATUS_FILTERS = ['kutilmoqda', 'tasdiqlandi', 'qaytarildi', 'rad_etildi'];

$filter = (string) ($_GET['status'] ?? '');
if (!in_array($filter, STATUS_FILTERS, true)) {
    $filter = '';
}

// ------------------------------------------------------------
// 1. AMALLARNI BAJARISH (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Amaldan keyin foydalanuvchi o'sha filtrda qoladi
    $backFilter = (string) ($_POST['status_filter'] ?? '');
    $backUrl    = 'admin/index.php' . (in_array($backFilter, STATUS_FILTERS, true) ? '?status=' . $backFilter : '');

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
        redirect($backUrl);
    }

    $action = (string) ($_POST['action'] ?? '');
    $id     = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);

    if ($id === false || $id < 1) {
        setFlash('error', "Noto'g'ri so'rov.");
        redirect($backUrl);
    }

    try {
        $stmt    = null;
        $message = '';

        // Joriy vaqt PHP'dan olinadi (Asia/Tashkent, functions.php da o'rnatilgan).
        // pickup_deadline ham shu vaqt mintaqasi bilan yozilgan, shuning uchun
        // MySQL'ning NOW() vaqt mintaqasi bilan chalkashlik bo'lmaydi.
        $now = date('Y-m-d H:i:s');

        if ($action === 'approve') {
            // Tasdiqlash: kitob talabaga berildi – olib ketilgan vaqt yoziladi.
            // "AND status = 'kutilmoqda'" sharti tugma ikki marta bosilsa ham vaqtni qayta yozmaydi.
            $stmt = $pdo->prepare(
                "UPDATE reservations
                 SET status = 'tasdiqlandi', picked_up_at = :now
                 WHERE id = :id AND status = 'kutilmoqda'"
            );
            $stmt->execute([':now' => $now, ':id' => $id]);
            $message = 'Band qilish tasdiqlandi. Kitob berilgan vaqt saqlandi.';

        } elseif ($action === 'return') {
            // Qaytarildi: qaytarilgan vaqt yoziladi.
            // Nusxa yana bo'sh bo'ladi: BOOK_SELECT faqat "kutilmoqda" va "tasdiqlandi"
            // holatlarini band deb hisoblaydi, shuning uchun alohida hisoblagich kerak emas.
            $stmt = $pdo->prepare(
                "UPDATE reservations
                 SET status = 'qaytarildi', returned_at = :now
                 WHERE id = :id AND status = 'tasdiqlandi'"
            );
            $stmt->execute([':now' => $now, ':id' => $id]);
            $message = "Kitob qaytarildi deb belgilandi. Nusxa yana bo'sh.";

        } elseif ($action === 'reject') {
            // O'chirmaymiz, holatini "rad_etildi" qilamiz – talaba ham, kutubxonachi ham ko'radi
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'rad_etildi' WHERE id = :id AND status = 'kutilmoqda'");
            $stmt->execute([':id' => $id]);
            $message = 'Band qilish rad etildi.';

        } else {
            setFlash('error', "Noma'lum amal.");
            redirect($backUrl);
        }

        if ($stmt !== null && $stmt->rowCount() > 0) {
            setFlash('success', $message);
        } else {
            setFlash('error', "Bu amalni bajarib bo'lmadi (holat allaqachon o'zgargan bo'lishi mumkin).");
        }
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Admin amalida xatolik: ' . $ex->getMessage());
        setFlash('error', 'Kutilmagan xatolik yuz berdi.');
    }

    redirect($backUrl);
}

// ------------------------------------------------------------
// 2. MA'LUMOTLARNI OLISH
// ------------------------------------------------------------
$stats        = ['books' => 0, 'pending' => 0, 'approved' => 0, 'students' => 0];
$reservations = [];

try {
    // Umumiy ko'rsatkichlar (bitta so'rovda)
    $stats = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM books) AS books,
            (SELECT COUNT(*) FROM reservations WHERE status = 'kutilmoqda') AS pending,
            (SELECT COUNT(*) FROM reservations WHERE status = 'tasdiqlandi') AS approved,
            (SELECT COUNT(*) FROM users WHERE role = 'talaba') AS students"
    )->fetch();

    // Band qilishlar: avval kutilayotganlar, keyin tasdiqlanganlar, oxirida qaytarilganlar
    // (talabaning fakulteti, yo'nalishi, guruhi va HEMIS ID'si ham olinadi;
    //  4-bosqich: picked_up_at, returned_at va is_offline ham olinadi)
    $sql = 'SELECT r.id, r.reserved_date, r.pickup_days, r.pickup_deadline, r.status,
                   r.picked_up_at, r.returned_at, r.is_offline,
                   u.name AS user_name, u.email AS user_email,
                   u.faculty, u.direction, u.group_name, u.hemis_id,
                   b.id AS book_id, b.title AS book_title
            FROM reservations r
            JOIN users u ON u.id = r.user_id
            JOIN books b ON b.id = r.book_id';
    $params = [];

    if ($filter !== '') {
        $sql .= ' WHERE r.status = :status';
        $params[':status'] = $filter;
    }

    $sql .= " ORDER BY FIELD(r.status, 'kutilmoqda', 'tasdiqlandi', 'qaytarildi', 'rad_etildi'), r.created_at DESC, r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Admin panelda xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi.");
}

// Filtr tugmalari
$tabs = [
    ''            => 'Hammasi',
    'kutilmoqda'  => 'Kutilmoqda',
    'tasdiqlandi' => 'Tasdiqlangan',
    'qaytarildi'  => 'Qaytarilgan',
    'rad_etildi'  => 'Rad etilgan',
];

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

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Admin panel';
require_once __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold mb-4">Admin panel – band qilishlar</h1>

<!-- ====== UMUMIY KO'RSATKICHLAR ====== -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold"><?= (int) $stats['books'] ?></div>
        <div class="text-sm text-slate-500">Kitoblar</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold text-amber-600"><?= (int) $stats['pending'] ?></div>
        <div class="text-sm text-slate-500">Tasdiq kutayotgan</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold text-green-600"><?= (int) $stats['approved'] ?></div>
        <div class="text-sm text-slate-500">Hozir qo'lda (tasdiqlangan)</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold"><?= (int) $stats['students'] ?></div>
        <div class="text-sm text-slate-500">Talabalar</div>
    </div>
</div>

<!-- ====== FILTR ====== -->
<div class="flex flex-wrap gap-2 mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <?php $active = ($filter === $value); ?>
        <a href="<?= e(url('admin/index.php' . ($value !== '' ? '?status=' . $value : ''))) ?>"
           class="px-4 py-1.5 rounded-full text-sm border
                  <?= $active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-slate-200 hover:border-blue-600' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ====== BAND QILISHLAR JADVALI ====== -->
<?php if (!$reservations): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        Hozircha band qilishlar yo'q.
    </div>
<?php else: ?>
    <!-- Kompyuter va planshet (768px dan keng): jadval -->
    <div class="hidden md:block bg-white border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3">Talaba</th>
                    <th class="px-4 py-3">Kitob</th>
                    <th class="px-4 py-3">Sana / Muddat</th>
                    <th class="px-4 py-3">Holati</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $r): ?>
                    <?php
                    $rem = formatRemainingTime((string) ($r['pickup_deadline'] ?? ''));
                    $pickupDays = (int) ($r['pickup_days'] ?? 1);
                    ?>
                    <tr class="border-t border-slate-200">
                        <td class="px-4 py-3">
                            <div class="font-semibold break-words"><?= e($r['user_name']) ?></div>
                            <div class="text-slate-500 break-all"><?= e($r['user_email']) ?></div>
                            <?php if (!empty($r['hemis_id'])): ?>
                                <div class="text-xs text-slate-600 mt-1">HEMIS ID: <b><?= e($r['hemis_id']) ?></b></div>
                            <?php endif; ?>
                            <?php if (studentInfoLine($r) !== ''): ?>
                                <div class="text-xs text-slate-500 break-words"><?= e(studentInfoLine($r)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                               class="text-blue-600 hover:underline break-words"><?= e($r['book_title']) ?></a>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <div>Band qilingan: <b><?= e(date('d.m.Y', strtotime((string) $r['reserved_date']))) ?></b></div>
                            <?php if (!empty($r['pickup_deadline'])): ?>
                                <div class="text-slate-600 mt-0.5">
                                    Muddati: <b><?= $pickupDays ?> kun</b> (<?= e(date('d.m.Y H:i', strtotime((string) $r['pickup_deadline']))) ?> gacha)
                                </div>
                                <?php if ($r['status'] === 'kutilmoqda'): ?>
                                    <div class="mt-1">
                                        <?php if ($rem['urgent']): ?>
                                            <span class="inline-block px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-medium">⚠️ <?= e($rem['text']) ?></span>
                                        <?php else: ?>
                                            <span class="inline-block px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-medium">⏳ <?= e($rem['text']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($r['picked_up_at'])): ?>
                                <div class="text-green-700 mt-0.5">Berilgan: <b><?= e(formatDateTime((string) $r['picked_up_at'])) ?></b></div>
                            <?php endif; ?>
                            <?php if (!empty($r['returned_at'])): ?>
                                <div class="text-slate-700 mt-0.5">Qaytarilgan: <b><?= e(formatDateTime((string) $r['returned_at'])) ?></b></div>
                            <?php endif; ?>
                            <?php if ((int) $r['is_offline'] === 1): ?>
                                <div class="mt-1"><span class="inline-block px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 font-medium">Offlayn berilgan</span></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3"><?= statusBadge((string) $r['status'], (string) ($r['pickup_deadline'] ?? '')) ?></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2 justify-end">
                                <?php if ($r['status'] === 'kutilmoqda'): ?>
                                    <!-- Tasdiqlash -->
                                    <form method="post" action="<?= e(url('admin/index.php')) ?>">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="status_filter" value="<?= e($filter) ?>">
                                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-xs rounded-md px-3 py-1.5">
                                            Tasdiqlash
                                        </button>
                                    </form>
                                    <!-- Rad etish -->
                                    <form method="post" action="<?= e(url('admin/index.php')) ?>"
                                          onsubmit="return confirm('Bu band qilishni rad etmoqchimisiz? Talaba kabinetida \'Rad etildi\' deb ko\'rinadi.');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="status_filter" value="<?= e($filter) ?>">
                                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-xs rounded-md px-3 py-1.5">
                                            Rad etish
                                        </button>
                                    </form>
                                <?php elseif ($r['status'] === 'tasdiqlandi'): ?>
                                    <!-- Qaytarildi -->
                                    <form method="post" action="<?= e(url('admin/index.php')) ?>">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="return">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="status_filter" value="<?= e($filter) ?>">
                                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-xs rounded-md px-3 py-1.5">
                                            Qaytarildi
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Telefon (768px dan tor): har bir band qilish alohida kartochka -->
    <div class="md:hidden space-y-3">
        <?php foreach ($reservations as $r): ?>
            <?php
            $rem = formatRemainingTime((string) ($r['pickup_deadline'] ?? ''));
            $pickupDays = (int) ($r['pickup_days'] ?? 1);
            ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-semibold break-words"><?= e($r['user_name']) ?></div>
                        <div class="text-sm text-slate-500 break-all"><?= e($r['user_email']) ?></div>
                        <?php if (!empty($r['hemis_id'])): ?>
                            <div class="text-xs text-slate-600 mt-1">HEMIS ID: <b><?= e($r['hemis_id']) ?></b></div>
                        <?php endif; ?>
                        <?php if (studentInfoLine($r) !== ''): ?>
                            <div class="text-xs text-slate-500 break-words"><?= e(studentInfoLine($r)) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="shrink-0"><?= statusBadge((string) $r['status'], (string) ($r['pickup_deadline'] ?? '')) ?></div>
                </div>

                <div class="mt-2 text-xs text-slate-600 space-y-1">
                    <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                       class="font-semibold text-sm text-blue-600 hover:underline block break-words"><?= e($r['book_title']) ?></a>
                    <div>Band qilingan: <b><?= e(date('d.m.Y', strtotime((string) $r['reserved_date']))) ?></b></div>
                    <?php if (!empty($r['pickup_deadline'])): ?>
                        <div>Muddati: <b><?= $pickupDays ?> kun</b> (<?= e(date('d.m.Y H:i', strtotime((string) $r['pickup_deadline']))) ?> gacha)</div>
                        <?php if ($r['status'] === 'kutilmoqda'): ?>
                            <div class="pt-0.5">
                                <?php if ($rem['urgent']): ?>
                                    <span class="inline-block px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-medium">⚠️ <?= e($rem['text']) ?></span>
                                <?php else: ?>
                                    <span class="inline-block px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-medium">⏳ <?= e($rem['text']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($r['picked_up_at'])): ?>
                        <div class="text-green-700">Berilgan: <b><?= e(formatDateTime((string) $r['picked_up_at'])) ?></b></div>
                    <?php endif; ?>
                    <?php if (!empty($r['returned_at'])): ?>
                        <div class="text-slate-700">Qaytarilgan: <b><?= e(formatDateTime((string) $r['returned_at'])) ?></b></div>
                    <?php endif; ?>
                    <?php if ((int) $r['is_offline'] === 1): ?>
                        <div><span class="inline-block px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 font-medium">Offlayn berilgan</span></div>
                    <?php endif; ?>
                </div>

                <?php if ($r['status'] === 'kutilmoqda'): ?>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <!-- Tasdiqlash -->
                        <form method="post" action="<?= e(url('admin/index.php')) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="status_filter" value="<?= e($filter) ?>">
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white text-sm rounded-md py-2">
                                Tasdiqlash
                            </button>
                        </form>
                        <!-- Rad etish -->
                        <form method="post" action="<?= e(url('admin/index.php')) ?>"
                              onsubmit="return confirm('Bu band qilishni rad etmoqchimisiz? Talaba kabinetida \'Rad etildi\' deb ko\'rinadi.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>">
                            <input type="hidden" name="status_filter" value="<?= e($filter) ?>">
                            <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-sm rounded-md py-2">
                                Rad etish
                            </button>
                        </form>
                    </div>
                <?php elseif ($r['status'] === 'tasdiqlandi'): ?>
                    <!-- Qaytarildi -->
                    <form method="post" action="<?= e(url('admin/index.php')) ?>" class="mt-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="return">
                        <input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" name="status_filter" value="<?= e($filter) ?>">
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-md py-2">
                            Qaytarildi
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
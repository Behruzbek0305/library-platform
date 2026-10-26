<?php
/**
 * ============================================================
 *  Fayl: admin/index.php – Admin panel: band qilishlar
 *  Funksional talab №8 (Texnik topshiriq, 4-band):
 *  kutubxonachi band qilishlar ro'yxatini ko'radi va tasdiqlaydi.
 *
 *  Amallar (hammasi POST + CSRF):
 *    approve – "kutilmoqda"  → "tasdiqlandi"
 *    return  – "tasdiqlandi" → "qaytarildi"  (kitob qaytarildi, nusxa yana bo'sh)
 *    reject  – "kutilmoqda" → "rad_etildi"  (yozuv saqlanadi, talaba ham ko'radi)
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

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
        if ($action === 'approve') {
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'tasdiqlandi' WHERE id = :id AND status = 'kutilmoqda'");
            $stmt->execute([':id' => $id]);
            $message = 'Band qilish tasdiqlandi.';
        } elseif ($action === 'return') {
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'qaytarildi' WHERE id = :id AND status = 'tasdiqlandi'");
            $stmt->execute([':id' => $id]);
            $message = 'Kitob qaytarildi deb belgilandi.';
        } elseif ($action === 'reject') {
            // O'chirmaymiz, holatini "rad_etildi" qilamiz – talaba ham, kutubxonachi ham ko'radi
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'rad_etildi' WHERE id = :id AND status = 'kutilmoqda'");
            $stmt->execute([':id' => $id]);
            $message = 'Band qilish rad etildi.';
        } else {
            setFlash('error', "Noma'lum amal.");
            redirect($backUrl);
        }

        if ($stmt->rowCount() > 0) {
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
    $sql = 'SELECT r.id, r.reserved_date, r.status,
                   u.name AS user_name, u.email AS user_email,
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
    <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full min-w-[640px] text-sm text-left">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3">Talaba</th>
                    <th class="px-4 py-3">Kitob</th>
                    <th class="px-4 py-3">Sana</th>
                    <th class="px-4 py-3">Holati</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $r): ?>
                    <tr class="border-t border-slate-200">
                        <td class="px-4 py-3">
                            <div class="font-semibold"><?= e($r['user_name']) ?></div>
                            <div class="text-slate-500"><?= e($r['user_email']) ?></div>
                        </td>
                        <td class="px-4 py-3">
                            <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                               class="text-blue-600 hover:underline"><?= e($r['book_title']) ?></a>
                        </td>
                        <td class="px-4 py-3"><?= e(date('d.m.Y', strtotime((string) $r['reserved_date']))) ?></td>
                        <td class="px-4 py-3"><?= statusBadge((string) $r['status']) ?></td>
                        <td class="px-4 py-3">
                            <div class="flex gap-2 justify-end">
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
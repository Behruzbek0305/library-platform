<?php
/**
 * ============================================================
 *  Fayl: cabinet.php – Mening kabinetim
 *  Ko'rsatadi: foydalanuvchining band qilgan kitoblari va holati.
 *  Holati "kutilmoqda" bo'lganini bekor qilish mumkin.
 *  (Texnik topshiriq: funksional talab №5, sahifa "Mening kabinetim")
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';

// Faqat tizimga kirgan talabalar uchun (kutubxonachi kira olmaydi)
requireStudent();
$user = currentUser();

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
            // Faqat o'ziga tegishli va hali tasdiqlanmagan band qilishni o'chirish mumkin
            $stmt = $pdo->prepare(
                "DELETE FROM reservations
                 WHERE id = :id AND user_id = :user_id AND status = 'kutilmoqda'"
            );
            $stmt->execute([':id' => $reservationId, ':user_id' => $user['id']]);

            if ($stmt->rowCount() > 0) {
                setFlash('success', 'Band qilish bekor qilindi.');
            } else {
                setFlash('error', "Bu band qilishni bekor qilib bo'lmaydi.");
            }
        } catch (PDOException $ex) {
            error_log('[Kutubxona-ARM] Bekor qilishda xatolik: ' . $ex->getMessage());
            setFlash('error', 'Kutilmagan xatolik yuz berdi.');
        }
    }

    redirect('cabinet.php');
}

// ------------------------------------------------------------
// 2. FOYDALANUVCHINING BAND QILISHLARINI OLISH
// ------------------------------------------------------------
$reservations = [];
try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.reserved_date, r.status, b.id AS book_id, b.title, b.author
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

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Mening kabinetim';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold">Mening kabinetim</h1>
<p class="text-slate-500 mb-5"><?= e($user['name']) ?> (<?= e($user['email']) ?>)</p>

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
                    <th class="px-4 py-3">Sana</th>
                    <th class="px-4 py-3">Holati</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $r): ?>
                    <tr class="border-t border-slate-200">
                        <td class="px-4 py-3">
                            <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                               class="font-semibold text-blue-600 hover:underline break-words"><?= e($r['title']) ?></a>
                            <div class="text-slate-500 break-words"><?= e($r['author']) ?></div>
                        </td>
                        <td class="px-4 py-3"><?= e(date('d.m.Y', strtotime((string) $r['reserved_date']))) ?></td>
                        <td class="px-4 py-3"><?= statusBadge((string) $r['status']) ?></td>
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
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                           class="font-semibold text-blue-600 hover:underline break-words"><?= e($r['title']) ?></a>
                        <div class="text-sm text-slate-500 break-words"><?= e($r['author']) ?></div>
                    </div>
                    <div class="shrink-0"><?= statusBadge((string) $r['status']) ?></div>
                </div>

                <div class="mt-2 text-sm text-slate-500">
                    Sana: <?= e(date('d.m.Y', strtotime((string) $r['reserved_date']))) ?>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
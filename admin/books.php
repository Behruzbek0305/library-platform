<?php
/**
 * ============================================================
 *  Fayl: admin/books.php – Kitoblarni boshqarish (ro'yxat va o'chirish)
 *  Funksional talab №7 (Texnik topshiriq, 4-band):
 *  kutubxonachi kitob qo'shadi, tahrirlaydi va o'chiradi.
 *  Qo'shish va tahrirlash – admin/book_form.php sahifasida.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

requireAdmin();

// ------------------------------------------------------------
// 1. KITOBNI O'CHIRISH (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
        redirect('admin/books.php');
    }

    $id = filter_var($_POST['delete_id'] ?? null, FILTER_VALIDATE_INT);

    if ($id !== false && $id > 0) {
        try {
            // Kitob hozir kimdadir bo'lsa yoki band qilingan bo'lsa, o'chirmaymiz
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE book_id = :id AND status IN ('kutilmoqda', 'tasdiqlandi')"
            );
            $stmt->execute([':id' => $id]);

            if ((int) $stmt->fetchColumn() > 0) {
                setFlash('error', "Bu kitobni o'chirib bo'lmaydi: u band qilingan yoki talabada turibdi.");
            } else {
                // Muqova rasmi nomini avval olib qo'yamiz (diskdan o'chirish uchun)
                $stmt = $pdo->prepare('SELECT title, cover_image FROM books WHERE id = :id');
                $stmt->execute([':id' => $id]);
                $book = $stmt->fetch();

                if ($book) {
                    $del = $pdo->prepare('DELETE FROM books WHERE id = :id');
                    $del->execute([':id' => $id]);

                    deleteCoverFile($book['cover_image']);
                    setFlash('success', "«" . $book['title'] . "» o'chirildi.");
                } else {
                    setFlash('error', 'Bunday kitob topilmadi.');
                }
            }
        } catch (PDOException $ex) {
            error_log('[Kutubxona-ARM] Kitobni o\'chirishda xatolik: ' . $ex->getMessage());
            setFlash('error', 'Kutilmagan xatolik yuz berdi.');
        }
    }

    redirect('admin/books.php');
}

// ------------------------------------------------------------
// 2. KITOBLAR RO'YXATI
// ------------------------------------------------------------
$books = [];
try {
    $books = $pdo->query(BOOK_SELECT . ' ORDER BY b.id DESC')->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Kitoblar ro\'yxatida xatolik: ' . $ex->getMessage());
    setFlash('error', "Kitoblarni yuklashda xatolik yuz berdi.");
}

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Kitoblarni boshqarish';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h1 class="text-2xl font-bold">Kitoblarni boshqarish</h1>
    <a href="<?= e(url('admin/book_form.php')) ?>"
       class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-4 py-2">
        + Yangi kitob qo'shish
    </a>
</div>

<p class="text-slate-500 mb-3">Jami: <?= count($books) ?> ta kitob</p>

<?php if (!$books): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        Katalogda hali kitoblar yo'q.
    </div>
<?php else: ?>
    <!-- Kompyuter va planshet (768px dan keng): jadval -->
    <div class="hidden md:block bg-white border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3">Nomi</th>
                    <th class="px-4 py-3">Muallif</th>
                    <th class="px-4 py-3">Kategoriya</th>
                    <th class="px-4 py-3">Bo'sh / Jami</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($books as $b): ?>
                    <tr class="border-t border-slate-200">
                        <td class="px-4 py-3 font-semibold break-words">
                            <a href="<?= e(url('book.php?id=' . (int) $b['id'])) ?>"
                               class="hover:underline"><?= e($b['title']) ?></a>
                        </td>
                        <td class="px-4 py-3 break-words"><?= e($b['author']) ?></td>
                        <td class="px-4 py-3"><?= e($b['category']) ?></td>
                        <td class="px-4 py-3"><?= (int) $b['available'] ?> / <?= (int) $b['copies'] ?></td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2 justify-end">
                                <a href="<?= e(url('admin/book_form.php?id=' . (int) $b['id'])) ?>"
                                   class="bg-slate-700 hover:bg-slate-800 text-white text-xs rounded-md px-3 py-1.5">
                                    Tahrirlash
                                </a>
                                <form method="post" action="<?= e(url('admin/books.php')) ?>"
                                      onsubmit="return confirm('Bu kitobni o\'chirishni xohlaysizmi?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="delete_id" value="<?= (int) $b['id'] ?>">
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-xs rounded-md px-3 py-1.5">
                                        O'chirish
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Telefon (768px dan tor): har bir kitob alohida kartochka -->
    <div class="md:hidden space-y-3">
        <?php foreach ($books as $b): ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <a href="<?= e(url('book.php?id=' . (int) $b['id'])) ?>"
                   class="font-semibold hover:underline break-words"><?= e($b['title']) ?></a>
                <div class="text-sm text-slate-500 break-words"><?= e($b['author']) ?></div>

                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-600"><?= e($b['category']) ?></span>
                    <span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-700">
                        Bo'sh: <?= (int) $b['available'] ?> / <?= (int) $b['copies'] ?>
                    </span>
                </div>

                <div class="mt-3 grid grid-cols-2 gap-2">
                    <a href="<?= e(url('admin/book_form.php?id=' . (int) $b['id'])) ?>"
                       class="text-center bg-slate-700 hover:bg-slate-800 text-white text-sm rounded-md py-2">
                        Tahrirlash
                    </a>
                    <form method="post" action="<?= e(url('admin/books.php')) ?>"
                          onsubmit="return confirm('Bu kitobni o\'chirishni xohlaysizmi?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="delete_id" value="<?= (int) $b['id'] ?>">
                        <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white text-sm rounded-md py-2">
                            O'chirish
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
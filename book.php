<?php
/**
 * ============================================================
 *  Fayl: book.php – Kitob sahifasi
 *  Ko'rsatadi: muqova, muallif, tavsif, bo'sh nusxalar va "Band qilish" tugmasi.
 *  Manzil: book.php?id=5
 *  (Texnik topshiriq, 6-band: "Kitob sahifasi")
 *
 *  1-bosqich: muqova rasmi kesilmaydi, to'liq ko'rinadi (object-contain).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';

// ------------------------------------------------------------
// 1. KITOBNI BAZADAN OLISH
// ------------------------------------------------------------
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$book = null;
if ($id !== false && $id !== null && $id > 0) {
    try {
        $stmt = $pdo->prepare(BOOK_SELECT . ' WHERE b.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $book = $stmt->fetch() ?: null;
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Kitob sahifasida xatolik: ' . $ex->getMessage());
    }
}

// Kitob topilmasa, katalogga qaytaramiz
if ($book === null) {
    setFlash('error', 'Bunday kitob topilmadi.');
    redirect('catalog.php');
}

// ------------------------------------------------------------
// 2. FOYDALANUVCHI SHU KITOBNI ALLAQACHON BAND QILGANMI?
// ------------------------------------------------------------
$user           = currentUser();
$alreadyBooked  = false;

if ($user !== null && $user['role'] === 'talaba') {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations
         WHERE user_id = :user_id AND book_id = :book_id
           AND status IN ('kutilmoqda', 'tasdiqlandi')"
    );
    $stmt->execute([':user_id' => $user['id'], ':book_id' => $book['id']]);
    $alreadyBooked = (int) $stmt->fetchColumn() > 0;
}

$available = (int) $book['available'];
$style     = categoryStyle((string) $book['category']);

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = (string) $book['title'];
require_once __DIR__ . '/includes/header.php';
?>

<a href="<?= e(url('catalog.php')) ?>" class="text-blue-600 hover:underline text-sm">← Katalogga qaytish</a>

<div class="mt-3 bg-white border border-slate-200 rounded-xl overflow-hidden md:flex">

    <!-- Muqova: rasm to'liq ko'rinadi (object-contain), kesilmaydi -->
    <div class="md:w-72 h-72 md:h-auto md:min-h-[18rem] flex items-center justify-center text-7xl <?= e($style['bg']) ?>">
        <?php if (!empty($book['cover_image'])): ?>
            <img src="<?= e(url((string) $book['cover_image'])) ?>" alt="<?= e($book['title']) ?>"
                 class="h-full w-full object-contain">
        <?php else: ?>
            <?= $style['emoji'] ?>
        <?php endif; ?>
    </div>

    <!-- Ma'lumotlar -->
    <div class="p-6 flex-1">
        <h1 class="text-2xl font-bold"><?= e($book['title']) ?></h1>
        <p class="text-slate-600 mt-1"><b>Muallif:</b> <?= e($book['author']) ?></p>
        <p class="text-slate-600"><b>Kategoriya:</b> <?= e($book['category']) ?></p>
        <p class="text-slate-600">
            <b>Bo'sh nusxalar:</b> <?= $available ?> / <?= (int) $book['copies'] ?>
        </p>

        <p class="mt-4 text-slate-700">
            <?= e($book['description'] ?: 'Tavsif kiritilmagan.') ?>
        </p>

        <!-- ====== BAND QILISH TUGMASI (foydalanuvchi holatiga qarab) ====== -->
        <div class="mt-6">
            <?php if ($user === null): ?>
                <p class="text-sm text-slate-500">
                    Band qilish uchun avval
                    <a href="<?= e(url('login.php')) ?>" class="text-blue-600 hover:underline">tizimga kiring</a>
                    yoki
                    <a href="<?= e(url('register.php')) ?>" class="text-blue-600 hover:underline">ro'yxatdan o'ting</a>.
                </p>

            <?php elseif ($user['role'] !== 'talaba'): ?>
                <p class="text-sm text-slate-500">Kitoblarni faqat talabalar band qila oladi.</p>

            <?php elseif ($alreadyBooked): ?>
                <p class="text-sm text-green-700 bg-green-50 border border-green-300 rounded-md px-3 py-2 inline-block">
                    Siz bu kitobni allaqachon band qilgansiz.
                    <a href="<?= e(url('cabinet.php')) ?>" class="underline">Kabinetga o'tish</a>
                </p>

            <?php elseif ($available <= 0): ?>
                <button type="button" disabled
                        class="bg-slate-400 text-white rounded-md px-5 py-2 cursor-not-allowed">
                    Hozir bo'sh nusxa yo'q
                </button>

            <?php else: ?>
                <form method="post" action="<?= e(url('reserve.php')) ?>" class="max-w-xs space-y-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="book_id" value="<?= (int) $book['id'] ?>">

                    <?php renderPickupSelect('pickup_days', 'pickup_days', 1); ?>

                    <button type="submit"
                            class="w-full sm:w-auto bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-5 py-2.5">
                        Band qilish
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
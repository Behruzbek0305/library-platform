<?php
/**
 * ============================================================
 *  Fayl: index.php – Bosh sahifa (vaqtinchalik variant)
 *  Bu sahifa 3-bosqichda to'liq qayta yoziladi:
 *  qidiruv oynasi, yangi kitoblar va kategoriyalar qo'shiladi.
 *  Hozircha 2-bosqichdagi kirish/ro'yxatdan o'tishni sinash uchun xizmat qiladi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Bosh sahifa';
require_once __DIR__ . '/includes/header.php';

$user = currentUser();
?>

<!-- Asosiy banner -->
<section class="bg-gradient-to-br from-blue-600 to-indigo-600 text-white rounded-xl px-6 py-10 text-center">
    <h1 class="text-3xl font-bold mb-2">Kerakli kitobni tez toping</h1>
    <p class="opacity-90">
        Talabalar uchun onlayn kutubxona: kitob qidiring, band qiling va elektron resurslarni yuklab oling.
    </p>
</section>

<!-- Foydalanuvchi holati (sinov uchun) -->
<section class="mt-6 bg-white border border-slate-200 rounded-xl p-5">
    <?php if ($user): ?>
        <p>Siz tizimdasiz: <b><?= e($user['name']) ?></b> (<?= e($user['email']) ?>)</p>
        <p class="text-sm text-slate-500 mt-1">
            Rolingiz: <b><?= e($user['role']) ?></b>
        </p>
    <?php else: ?>
        <p>Siz hali tizimga kirmagansiz.</p>
        <div class="mt-3 flex gap-2">
            <a href="<?= e(url('login.php')) ?>"
               class="bg-blue-600 hover:bg-blue-700 text-white rounded-md px-4 py-2">Kirish</a>
            <a href="<?= e(url('register.php')) ?>"
               class="border border-slate-300 hover:bg-slate-50 rounded-md px-4 py-2">Ro'yxatdan o'tish</a>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
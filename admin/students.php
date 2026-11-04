<?php
/**
 * ============================================================
 *  Fayl: admin/students.php – Talabalar ro'yxati (kengaytirilgan jadval)
 *  Vazifasi: kutubxonachi barcha talabalarni ko'radi va qidiradi.
 *
 *  Ustunlar (har biri alohida):
 *    № | FIO | Email | HEMIS ID | Fakultet | Yo'nalish | Guruh |
 *    Ro'yxatdan o'tgan sana | Jami band qilishlar | Kutilayotgan |
 *    Hozir qo'lida | Kechikkan
 *
 *  Filtrlar: qidiruv (FIO / email / HEMIS ID), fakultet, guruh.
 *  Hamma so'rov PDO prepared statements bilan (SQL Injection'dan himoya).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

// Faqat kutubxonachi kira oladi
requireAdmin();

// ------------------------------------------------------------
// 1. FILTRLARNI XAVFSIZ OLISH (GET)
// ------------------------------------------------------------
$q       = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$faculty = mb_substr(trim((string) ($_GET['faculty'] ?? '')), 0, 150);
$group   = mb_substr(trim((string) ($_GET['group'] ?? '')), 0, 30);

$students  = [];
$faculties = [];
$groups    = [];

try {
    // Dropdown ro'yxatlari: bazada mavjud fakultet va guruhlar
    $faculties = $pdo->query(
        "SELECT DISTINCT faculty FROM users
         WHERE role = 'talaba' AND faculty IS NOT NULL AND faculty <> '' ORDER BY faculty"
    )->fetchAll(PDO::FETCH_COLUMN);

    $groups = $pdo->query(
        "SELECT DISTINCT group_name FROM users
         WHERE role = 'talaba' AND group_name IS NOT NULL AND group_name <> '' ORDER BY group_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    // ------------------------------------------------------------
    // 2. TALABALAR SO'ROVI
    //    Band qilishlar sonlari kichik so'rovlar (subquery) orqali hisoblanadi,
    //    shuning uchun har bir talaba bitta qatorda chiqadi.
    //    DIQQAT: har bir nomli parametr faqat BIR marta ishlatiladi.
    // ------------------------------------------------------------
    $sql = "SELECT u.id, u.name, u.email, u.faculty, u.direction, u.group_name, u.hemis_id, u.created_at,
                   (SELECT COUNT(*) FROM reservations r
                     WHERE r.user_id = u.id) AS total_res,
                   (SELECT COUNT(*) FROM reservations r
                     WHERE r.user_id = u.id AND r.status = 'kutilmoqda') AS pending_res,
                   (SELECT COUNT(*) FROM reservations r
                     WHERE r.user_id = u.id AND r.status IN ('tasdiqlandi', 'berilgan')) AS active_res,
                   (SELECT COUNT(*) FROM reservations r
                     WHERE r.user_id = u.id AND r.status IN ('tasdiqlandi', 'berilgan')
                       AND r.due_date IS NOT NULL AND r.due_date < NOW()) AS overdue_res
            FROM users u
            WHERE u.role = 'talaba'";
    $params = [];

    if ($q !== '') {
        // LIKE belgilari (% va _) oddiy harf sifatida qidirilishi uchun "escape" qilinadi
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $sql .= ' AND (u.name LIKE :q_name OR u.email LIKE :q_email OR u.hemis_id LIKE :q_hemis)';
        $params[':q_name']  = $like;
        $params[':q_email'] = $like;
        $params[':q_hemis'] = $like;
    }
    if ($faculty !== '') {
        $sql .= ' AND u.faculty = :faculty';
        $params[':faculty'] = $faculty;
    }
    if ($group !== '') {
        $sql .= ' AND u.group_name = :group_name';
        $params[':group_name'] = $group;
    }

    $sql .= ' ORDER BY u.name ASC, u.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Talabalar ro\'yxatida xatolik: ' . $ex->getMessage());
    setFlash('error', "Talabalarni yuklashda xatolik yuz berdi.");
}

/** Qiymat bor bo'lsa xavfsiz matn, bo'lmasa kulrang "—" */
function cellValue(?string $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? e($value) : '<span class="text-slate-400">—</span>';
}

$hasSearch  = $q !== '' || $faculty !== '' || $group !== '';
$inputClass = 'w-full border border-slate-300 rounded-md px-3 py-2 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-300';

// Jadval ustunlari (har biri alohida)
$columns = [
    '№', 'Talaba FIO', 'Email', 'HEMIS ID', 'Fakultet', "Yo'nalish", 'Guruh',
    "Ro'yxatdan o'tgan", 'Jami band qilish', 'Kutilayotgan', "Hozir qo'lida", 'Kechikkan',
];

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Talabalar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h1 class="text-2xl font-bold">Talabalar</h1>
    <a href="<?= e(url('admin/index.php')) ?>" class="text-blue-600 hover:underline text-sm">← Band qilishlarga qaytish</a>
</div>

<!-- ====== QIDIRUV VA FILTRLAR ====== -->
<form method="get" action="<?= e(url('admin/students.php')) ?>"
      class="bg-white border border-slate-200 rounded-xl p-3 mb-3 grid grid-cols-1 md:grid-cols-[1.4fr_1fr_1fr_auto_auto] gap-2">

    <input type="text" name="q" value="<?= e($q) ?>" maxlength="100"
           placeholder="FIO, email yoki HEMIS ID..." aria-label="Talabani qidirish"
           class="<?= $inputClass ?>">

    <select name="faculty" aria-label="Fakultet bo'yicha filtr" class="<?= $inputClass ?>">
        <option value="">Barcha fakultetlar</option>
        <?php foreach ($faculties as $f): ?>
            <option value="<?= e($f) ?>" <?= $f === $faculty ? 'selected' : '' ?>><?= e($f) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="group" aria-label="Guruh bo'yicha filtr" class="<?= $inputClass ?>">
        <option value="">Barcha guruhlar</option>
        <?php foreach ($groups as $g): ?>
            <option value="<?= e($g) ?>" <?= $g === $group ? 'selected' : '' ?>><?= e($g) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-5 py-2 text-sm">
        Qidirish
    </button>

    <?php if ($hasSearch): ?>
        <a href="<?= e(url('admin/students.php')) ?>"
           class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-sm text-center">
            Tozalash
        </a>
    <?php endif; ?>
</form>

<p class="text-slate-500 text-sm mb-3">Topildi: <?= count($students) ?> ta talaba</p>

<?php if (!$students): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        <?= $hasSearch ? "Bu filtr bo'yicha talaba topilmadi." : "Hozircha talabalar yo'q." ?>
    </div>
<?php else: ?>

    <!-- Kompyuter (1024px dan keng): jadval, har bir ma'lumot alohida ustunda -->
    <div class="hidden lg:block bg-white border border-slate-200 rounded-xl overflow-x-auto">
        <table class="min-w-max w-full text-xs text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <?php foreach ($columns as $col): ?>
                        <th class="px-3 py-3 font-semibold whitespace-nowrap"><?= e($col) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $i => $s): ?>
                    <tr class="border-t border-slate-200">
                        <td class="px-3 py-3 text-slate-500"><?= $i + 1 ?></td>
                        <td class="px-3 py-3 font-semibold whitespace-nowrap"><?= cellValue($s['name']) ?></td>
                        <td class="px-3 py-3 whitespace-nowrap"><?= cellValue($s['email']) ?></td>
                        <td class="px-3 py-3 whitespace-nowrap font-mono"><?= cellValue($s['hemis_id']) ?></td>
                        <td class="px-3 py-3 whitespace-nowrap"><?= cellValue($s['faculty']) ?></td>
                        <td class="px-3 py-3 whitespace-nowrap"><?= cellValue($s['direction']) ?></td>
                        <td class="px-3 py-3 whitespace-nowrap"><?= cellValue($s['group_name']) ?></td>
                        <td class="px-3 py-3 whitespace-nowrap"><?= e(date('d.m.Y', strtotime((string) $s['created_at']))) ?></td>
                        <td class="px-3 py-3 text-center"><?= (int) $s['total_res'] ?></td>
                        <td class="px-3 py-3 text-center">
                            <?= (int) $s['pending_res'] > 0
                                ? '<span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 font-medium">' . (int) $s['pending_res'] . '</span>'
                                : '<span class="text-slate-400">0</span>' ?>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <?= (int) $s['active_res'] > 0
                                ? '<span class="px-1.5 py-0.5 rounded bg-green-100 text-green-700 font-medium">' . (int) $s['active_res'] . '</span>'
                                : '<span class="text-slate-400">0</span>' ?>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <?= (int) $s['overdue_res'] > 0
                                ? '<span class="px-1.5 py-0.5 rounded bg-red-100 text-red-700 font-semibold">' . (int) $s['overdue_res'] . '</span>'
                                : '<span class="text-slate-400">0</span>' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Telefon va planshet (1024px dan tor): har bir talaba alohida kartochka -->
    <div class="lg:hidden space-y-3">
        <?php foreach ($students as $s): ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="font-semibold break-words"><?= e($s['name']) ?></div>
                <div class="text-xs text-slate-500 break-all"><?= e($s['email']) ?></div>

                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                    <div><dt class="text-slate-500">HEMIS ID</dt><dd class="font-mono font-semibold"><?= cellValue($s['hemis_id']) ?></dd></div>
                    <div><dt class="text-slate-500">Guruh</dt><dd class="font-semibold"><?= cellValue($s['group_name']) ?></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Fakultet</dt><dd class="font-semibold break-words"><?= cellValue($s['faculty']) ?></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Yo'nalish</dt><dd class="font-semibold break-words"><?= cellValue($s['direction']) ?></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Ro'yxatdan o'tgan</dt><dd class="font-semibold"><?= e(date('d.m.Y', strtotime((string) $s['created_at']))) ?></dd></div>
                    <div><dt class="text-slate-500">Jami band qilish</dt><dd class="font-semibold"><?= (int) $s['total_res'] ?></dd></div>
                    <div><dt class="text-slate-500">Kutilayotgan</dt><dd class="font-semibold"><?= (int) $s['pending_res'] ?></dd></div>
                    <div><dt class="text-slate-500">Hozir qo'lida</dt><dd class="font-semibold"><?= (int) $s['active_res'] ?></dd></div>
                    <div><dt class="text-slate-500">Kechikkan</dt><dd class="font-semibold <?= (int) $s['overdue_res'] > 0 ? 'text-red-700' : '' ?>"><?= (int) $s['overdue_res'] ?></dd></div>
                </dl>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
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
 *  Refaktoring (2-qadam):
 *    - jadval alohida ustunlarga bo'lindi: FIO, HEMIS ID, fakultet va guruh, kitob,
 *      band qilingan vaqt + jonli taymer, berilgan/qaytarilgan sana, holat, harakatlar
 *    - kutilayotganlar ichida muddati eng oz qolgani eng tepada
 *    - HEMIS ID bo'yicha qidiruv, fakultet va guruh bo'yicha filtr
 *    - tugmalar bitta funksiyada (renderActions), jadval va kartochka ikkalasi ishlatadi
 *
 *  Eslatma: so'rovdagi "expires_at" bazada "pickup_deadline",
 *  "reserved_at" esa "created_at" ustuniga to'g'ri keladi.
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

// ------------------------------------------------------------
// 0. YORDAMCHI FUNKSIYALAR
// ------------------------------------------------------------

/** Sana-vaqtni "30.10.2026 13:05" ko'rinishida chiqaradi (bo'sh bo'lsa bo'sh matn) */
function formatDateTime(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return date('d.m.Y H:i', strtotime($value));
}

/**
 * Filtrlarni xavfsiz tarzda oladi (GET yoki POST massividan).
 * Qaytaradi: ['status' => ..., 'q' => ..., 'faculty' => ..., 'group' => ...]
 */
function readFilters(array $src, string $statusKey): array
{
    $status = (string) ($src[$statusKey] ?? '');
    if (!in_array($status, STATUS_FILTERS, true)) {
        $status = '';
    }

    return [
        'status'  => $status,
        // HEMIS ID faqat raqam: boshqa belgilar olib tashlanadi (eng ko'pi 20 ta)
        'q'       => mb_substr((string) preg_replace('/\D+/', '', (string) ($src['q'] ?? '')), 0, 20),
        'faculty' => mb_substr(trim((string) ($src['faculty'] ?? '')), 0, 150),
        'group'   => mb_substr(trim((string) ($src['group'] ?? '')), 0, 30),
    ];
}

/** Filtrlardan admin sahifa manzilini yasaydi (bo'sh filtrlar tushib qoladi) */
function adminUrl(array $filters): string
{
    $query = array_filter($filters, static fn(string $v): bool => $v !== '');
    return 'admin/index.php' . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Holatga qarab amal tugmalarini chizadi.
 * $compact = true bo'lsa (telefon kartochkasi) tugmalar keng va katta bo'ladi.
 * Formalar filtrlarni yashirin maydonlarda olib yuradi, shunda amaldan keyin
 * kutubxonachi o'sha qidiruv natijasida qoladi.
 */
function renderActions(array $r, array $filters, bool $compact): void
{
    $status = (string) $r['status'];
    if ($status !== 'kutilmoqda' && $status !== 'tasdiqlandi') {
        echo '<span class="text-xs text-slate-400">—</span>';
        return;
    }

    $size = $compact ? 'w-full text-sm py-2' : 'text-xs px-3 py-1.5';

    // Tugmalar ro'yxati: [amal, yozuv, rang, tasdiq matni]
    $buttons = $status === 'kutilmoqda'
        ? [
            ['approve', 'Tasdiqlash', 'bg-green-600 hover:bg-green-700', null],
            ['reject',  'Rad etish',  'bg-red-600 hover:bg-red-700',
                "Bu band qilishni rad etmoqchimisiz? Talaba kabinetida 'Rad etildi' deb ko'rinadi."],
        ]
        : [
            ['return', 'Qaytarildi', 'bg-blue-600 hover:bg-blue-700', null],
        ];

    echo '<div class="' . ($compact ? 'grid grid-cols-' . count($buttons) . ' gap-2' : 'flex flex-wrap gap-2') . '">';

    foreach ($buttons as [$action, $label, $color, $confirmText]) {
        // Tasdiq oynasi matni JS satriga xavfsiz joylanadi (json_encode + e)
        $onsubmit = $confirmText !== null
            ? ' onsubmit="' . e('return confirm(' . json_encode($confirmText, JSON_UNESCAPED_UNICODE) . ');') . '"'
            : '';
        ?>
        <form method="post" action="<?= e(url('admin/index.php')) ?>"<?= $onsubmit ?>>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= e($action) ?>">
            <input type="hidden" name="reservation_id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="status_filter" value="<?= e($filters['status']) ?>">
            <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
            <input type="hidden" name="faculty" value="<?= e($filters['faculty']) ?>">
            <input type="hidden" name="group" value="<?= e($filters['group']) ?>">
            <button type="submit" class="<?= e($color . ' ' . $size) ?> text-white rounded-md">
                <?= e($label) ?>
            </button>
        </form>
        <?php
    }

    echo '</div>';
}

/**
 * Qolgan vaqt belgisi (taymer). Sahifa ochilganda server hisoblagan matn turadi,
 * keyin pastdagi JavaScript uni har soniyada yangilaydi (data-deadline-ts bo'yicha).
 */
function renderTimer(array $r): void
{
    if ($r['status'] !== 'kutilmoqda' || empty($r['pickup_deadline'])) {
        return;
    }

    $rem = formatRemainingTime((string) $r['pickup_deadline']);
    $cls = $rem['urgent'] || $rem['expired']
        ? 'bg-red-100 text-red-700'
        : 'bg-amber-100 text-amber-800';
    ?>
    <span data-deadline-ts="<?= (int) strtotime((string) $r['pickup_deadline']) ?>"
          class="inline-block mt-1 px-1.5 py-0.5 rounded font-medium text-xs <?= e($cls) ?>">
        <?= $rem['urgent'] ? '⚠️' : '⏳' ?> <?= e($rem['text']) ?>
    </span>
    <?php
}

// ------------------------------------------------------------
// 1. AMALLARNI BAJARISH (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Amaldan keyin foydalanuvchi o'sha filtr va qidiruvda qoladi
    $backUrl = adminUrl(readFilters($_POST, 'status_filter'));

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
        $now = date('Y-m-d H:i:s');

        if ($action === 'approve') {
            // Tasdiqlash: kitob talabaga berildi – olib ketilgan vaqt yoziladi
            $stmt = $pdo->prepare(
                "UPDATE reservations
                 SET status = 'tasdiqlandi', picked_up_at = :now
                 WHERE id = :id AND status = 'kutilmoqda'"
            );
            $stmt->execute([':now' => $now, ':id' => $id]);
            $message = 'Band qilish tasdiqlandi. Kitob berilgan vaqt saqlandi.';

        } elseif ($action === 'return') {
            // Qaytarildi: qaytarilgan vaqt yoziladi (nusxa BOOK_SELECT orqali o'zi bo'shaydi)
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
// 2. FILTRLAR VA MA'LUMOTLARNI OLISH
// ------------------------------------------------------------
$filters = readFilters($_GET, 'status');

$stats        = ['books' => 0, 'pending' => 0, 'approved' => 0, 'students' => 0];
$reservations = [];
$faculties    = [];   // fakultet dropdown uchun
$groups       = [];   // guruh dropdown uchun

try {
    // Umumiy ko'rsatkichlar (bitta so'rovda)
    $stats = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM books) AS books,
            (SELECT COUNT(*) FROM reservations WHERE status = 'kutilmoqda') AS pending,
            (SELECT COUNT(*) FROM reservations WHERE status = 'tasdiqlandi') AS approved,
            (SELECT COUNT(*) FROM users WHERE role = 'talaba') AS students"
    )->fetch();

    // Dropdown ro'yxatlari: bazada mavjud fakultet va guruhlar
    $faculties = $pdo->query(
        "SELECT DISTINCT faculty FROM users
         WHERE role = 'talaba' AND faculty IS NOT NULL AND faculty <> '' ORDER BY faculty"
    )->fetchAll(PDO::FETCH_COLUMN);

    $groups = $pdo->query(
        "SELECT DISTINCT group_name FROM users
         WHERE role = 'talaba' AND group_name IS NOT NULL AND group_name <> '' ORDER BY group_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    // Band qilishlar ro'yxati (hamma shart prepared statement orqali)
    $sql = 'SELECT r.id, r.reserved_date, r.created_at, r.pickup_days, r.pickup_deadline, r.status,
                   r.picked_up_at, r.returned_at, r.is_offline,
                   u.name AS user_name, u.email AS user_email,
                   u.faculty, u.direction, u.group_name, u.hemis_id,
                   b.id AS book_id, b.title AS book_title
            FROM reservations r
            JOIN users u ON u.id = r.user_id
            JOIN books b ON b.id = r.book_id
            WHERE 1 = 1';
    $params = [];

    if ($filters['status'] !== '') {
        $sql .= ' AND r.status = :status';
        $params[':status'] = $filters['status'];
    }
    if ($filters['q'] !== '') {
        // HEMIS ID bo'yicha qisman qidiruv (faqat raqamlar, shuning uchun LIKE belgilari xavfsiz)
        $sql .= ' AND u.hemis_id LIKE :hemis';
        $params[':hemis'] = '%' . $filters['q'] . '%';
    }
    if ($filters['faculty'] !== '') {
        $sql .= ' AND u.faculty = :faculty';
        $params[':faculty'] = $filters['faculty'];
    }
    if ($filters['group'] !== '') {
        $sql .= ' AND u.group_name = :group_name';
        $params[':group_name'] = $filters['group'];
    }

    // Tartib:
    //  1) kutilayotganlar tepada, ular ichida muddati eng oz qolgani birinchi (pickup_deadline ASC)
    //  2) qolganlari (tasdiqlangan, qaytarilgan, rad etilgan) – eng yangisi birinchi
    $sql .= " ORDER BY CASE WHEN r.status = 'kutilmoqda' THEN 1 ELSE 2 END,
                       CASE WHEN r.status = 'kutilmoqda' THEN r.pickup_deadline END ASC,
                       r.created_at DESC, r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Admin panelda xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi.");
}

// Holat tugmalari (boshqa filtrlar saqlanadi)
$tabs = [
    ''            => 'Hammasi',
    'kutilmoqda'  => 'Kutilmoqda',
    'tasdiqlandi' => 'Tasdiqlangan',
    'qaytarildi'  => 'Qaytarilgan',
    'rad_etildi'  => 'Rad etilgan',
];

// Qidiruv yoki dropdown filtr yoqilganmi? ("Tozalash" tugmasi uchun)
$hasSearch = $filters['q'] !== '' || $filters['faculty'] !== '' || $filters['group'] !== '';

$inputClass = 'w-full border border-slate-300 rounded-md px-3 py-2 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-300';

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

<!-- ====== QIDIRUV VA FILTRLAR ====== -->
<form method="get" action="<?= e(url('admin/index.php')) ?>"
      class="bg-white border border-slate-200 rounded-xl p-3 mb-3 grid grid-cols-1 md:grid-cols-[1.2fr_1fr_1fr_auto_auto] gap-2">

    <!-- Holat tanlangan bo'lsa, qidirganda ham saqlanib qoladi -->
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">

    <input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="20" inputmode="numeric"
           placeholder="HEMIS ID bo'yicha qidirish..." aria-label="HEMIS ID bo'yicha qidirish"
           class="<?= $inputClass ?>">

    <select name="faculty" aria-label="Fakultet bo'yicha filtr" class="<?= $inputClass ?>">
        <option value="">Barcha fakultetlar</option>
        <?php foreach ($faculties as $f): ?>
            <option value="<?= e($f) ?>" <?= $f === $filters['faculty'] ? 'selected' : '' ?>><?= e($f) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="group" aria-label="Guruh bo'yicha filtr" class="<?= $inputClass ?>">
        <option value="">Barcha guruhlar</option>
        <?php foreach ($groups as $g): ?>
            <option value="<?= e($g) ?>" <?= $g === $filters['group'] ? 'selected' : '' ?>><?= e($g) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-5 py-2 text-sm">
        Qidirish
    </button>

    <?php if ($hasSearch): ?>
        <a href="<?= e(url(adminUrl(['status' => $filters['status'], 'q' => '', 'faculty' => '', 'group' => '']))) ?>"
           class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-sm text-center">
            Tozalash
        </a>
    <?php endif; ?>
</form>

<!-- ====== HOLAT BO'YICHA FILTR ====== -->
<div class="flex flex-wrap gap-2 mb-3">
    <?php foreach ($tabs as $value => $label): ?>
        <?php $active = ($filters['status'] === $value); ?>
        <a href="<?= e(url(adminUrl(['status' => $value] + $filters))) ?>"
           class="px-4 py-1.5 rounded-full text-sm border
                  <?= $active ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-slate-200 hover:border-blue-600' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</div>

<p class="text-slate-500 text-sm mb-3">Topildi: <?= count($reservations) ?> ta band qilish</p>

<!-- ====== BAND QILISHLAR ====== -->
<?php if (!$reservations): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        <?= $hasSearch || $filters['status'] !== '' ? 'Bu filtr bo\'yicha hech narsa topilmadi.' : 'Hozircha band qilishlar yo\'q.' ?>
    </div>
<?php else: ?>

    <!-- Kompyuter (1024px dan keng): jadval. Ustunlar kengroq bo'lgani uchun lg dan boshlanadi -->
    <div class="hidden lg:block bg-white border border-slate-200 rounded-xl overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-3 py-3 font-semibold">Talaba FIO</th>
                    <th class="px-3 py-3 font-semibold">HEMIS ID</th>
                    <th class="px-3 py-3 font-semibold">Fakultet va guruh</th>
                    <th class="px-3 py-3 font-semibold">Kitob</th>
                    <th class="px-3 py-3 font-semibold">Band qilingan / Qolgan muddat</th>
                    <th class="px-3 py-3 font-semibold">Berilgan / Qaytarilgan</th>
                    <th class="px-3 py-3 font-semibold">Holati</th>
                    <th class="px-3 py-3 font-semibold text-right">Harakatlar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $r): ?>
                    <tr class="border-t border-slate-200 align-top">
                        <!-- Talaba FIO -->
                        <td class="px-3 py-3">
                            <div class="font-semibold break-words"><?= e($r['user_name']) ?></div>
                            <div class="text-xs text-slate-500 break-all"><?= e($r['user_email']) ?></div>
                        </td>

                        <!-- HEMIS ID -->
                        <td class="px-3 py-3 whitespace-nowrap font-mono text-xs">
                            <?= !empty($r['hemis_id']) ? e($r['hemis_id']) : '<span class="text-slate-400">—</span>' ?>
                        </td>

                        <!-- Fakultet va guruh -->
                        <td class="px-3 py-3 text-xs">
                            <div class="break-words"><?= !empty($r['faculty']) ? e($r['faculty']) : '<span class="text-slate-400">—</span>' ?></div>
                            <?php if (!empty($r['group_name'])): ?>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 font-medium"><?= e($r['group_name']) ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Kitob nomi -->
                        <td class="px-3 py-3">
                            <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                               class="text-blue-600 hover:underline break-words"><?= e($r['book_title']) ?></a>
                        </td>

                        <!-- Band qilingan vaqt va qolgan muddat (taymer) -->
                        <td class="px-3 py-3 text-xs">
                            <div><b><?= e(formatDateTime((string) $r['created_at'])) ?></b></div>
                            <?php if (!empty($r['pickup_deadline'])): ?>
                                <div class="text-slate-600 mt-0.5">
                                    <?= (int) $r['pickup_days'] ?> kun · <?= e(formatDateTime((string) $r['pickup_deadline'])) ?> gacha
                                </div>
                            <?php endif; ?>
                            <?php renderTimer($r); ?>
                        </td>

                        <!-- Olib ketilgan va qaytarilgan sana -->
                        <td class="px-3 py-3 text-xs">
                            <?php if (!empty($r['picked_up_at'])): ?>
                                <div class="text-green-700">Berilgan: <b><?= e(formatDateTime((string) $r['picked_up_at'])) ?></b></div>
                            <?php endif; ?>
                            <?php if (!empty($r['returned_at'])): ?>
                                <div class="text-slate-700 mt-0.5">Qaytarilgan: <b><?= e(formatDateTime((string) $r['returned_at'])) ?></b></div>
                            <?php endif; ?>
                            <?php if ((int) $r['is_offline'] === 1): ?>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 font-medium">Offlayn</span>
                            <?php endif; ?>
                            <?php if (empty($r['picked_up_at']) && empty($r['returned_at']) && (int) $r['is_offline'] !== 1): ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Holati -->
                        <td class="px-3 py-3">
                            <?= statusBadge((string) $r['status'], (string) ($r['pickup_deadline'] ?? '')) ?>
                        </td>

                        <!-- Harakatlar -->
                        <td class="px-3 py-3">
                            <div class="flex justify-end"><?php renderActions($r, $filters, false); ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Telefon va planshet (1024px dan tor): har bir band qilish alohida kartochka -->
    <div class="lg:hidden space-y-3">
        <?php foreach ($reservations as $r): ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-semibold break-words"><?= e($r['user_name']) ?></div>
                        <div class="text-xs text-slate-500 break-all"><?= e($r['user_email']) ?></div>
                    </div>
                    <div class="shrink-0"><?= statusBadge((string) $r['status'], (string) ($r['pickup_deadline'] ?? '')) ?></div>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                    <div>
                        <dt class="text-slate-500">HEMIS ID</dt>
                        <dd class="font-mono font-semibold"><?= !empty($r['hemis_id']) ? e($r['hemis_id']) : '—' ?></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Guruh</dt>
                        <dd class="font-semibold"><?= !empty($r['group_name']) ? e($r['group_name']) : '—' ?></dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Fakultet</dt>
                        <dd class="font-semibold break-words"><?= !empty($r['faculty']) ? e($r['faculty']) : '—' ?></dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Kitob</dt>
                        <dd>
                            <a href="<?= e(url('book.php?id=' . (int) $r['book_id'])) ?>"
                               class="font-semibold text-sm text-blue-600 hover:underline break-words"><?= e($r['book_title']) ?></a>
                        </dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Band qilingan</dt>
                        <dd>
                            <b><?= e(formatDateTime((string) $r['created_at'])) ?></b>
                            <?php if (!empty($r['pickup_deadline'])): ?>
                                <span class="text-slate-600">· <?= (int) $r['pickup_days'] ?> kun (<?= e(formatDateTime((string) $r['pickup_deadline'])) ?> gacha)</span>
                            <?php endif; ?>
                            <div><?php renderTimer($r); ?></div>
                        </dd>
                    </div>
                    <?php if (!empty($r['picked_up_at'])): ?>
                        <div class="text-green-700">
                            <dt class="text-slate-500">Berilgan</dt>
                            <dd class="font-semibold"><?= e(formatDateTime((string) $r['picked_up_at'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($r['returned_at'])): ?>
                        <div>
                            <dt class="text-slate-500">Qaytarilgan</dt>
                            <dd class="font-semibold"><?= e(formatDateTime((string) $r['returned_at'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ((int) $r['is_offline'] === 1): ?>
                        <div class="col-span-2">
                            <span class="inline-block px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 font-medium">Offlayn berilgan</span>
                        </div>
                    <?php endif; ?>
                </dl>

                <?php if ($r['status'] === 'kutilmoqda' || $r['status'] === 'tasdiqlandi'): ?>
                    <div class="mt-3"><?php renderActions($r, $filters, true); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ====== JONLI TAYMER ======
     Har soniyada kutilayotgan band qilishlarning qolgan vaqtini yangilaydi.
     Server vaqti bilan sinxronlanadi, shuning uchun kompyuter soati noto'g'ri bo'lsa ham to'g'ri ishlaydi.
     Matn faqat textContent orqali qo'yiladi (XSS bo'lmaydi). -->
<script>
(function () {
    'use strict';

    var nodes = document.querySelectorAll('[data-deadline-ts]');
    if (!nodes.length) { return; }

    // Server va brauzer soati orasidagi farq (soniyada)
    var offset = <?= (int) time() ?> - Math.floor(Date.now() / 1000);

    var BASE   = 'inline-block mt-1 px-1.5 py-0.5 rounded font-medium text-xs ';
    var NORMAL = BASE + 'bg-amber-100 text-amber-800';
    var URGENT = BASE + 'bg-red-100 text-red-700';

    function two(n) { return n < 10 ? '0' + n : String(n); }

    function tick() {
        var now = Math.floor(Date.now() / 1000) + offset;

        Array.prototype.forEach.call(nodes, function (node) {
            var left = parseInt(node.getAttribute('data-deadline-ts'), 10) - now;

            if (left <= 0) {
                node.className = URGENT;
                node.textContent = "⚠️ Muddati o'tgan";
                return;
            }

            var d = Math.floor(left / 86400);
            var h = Math.floor((left % 86400) / 3600);
            var m = Math.floor((left % 3600) / 60);
            var s = left % 60;

            var text = (d > 0 ? d + ' kun ' : '') + two(h) + ':' + two(m) + ':' + two(s) + ' qoldi';

            // Oxirgi 6 soatda qizil rangga o'tadi
            var urgent = left <= 6 * 3600;
            node.className = urgent ? URGENT : NORMAL;
            node.textContent = (urgent ? '⚠️ ' : '⏳ ') + text;
        });
    }

    tick();
    setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
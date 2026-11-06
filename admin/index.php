<?php
/**
 * ============================================================
 *  Fayl: admin/index.php – Admin panel: band qilishlar
 *  Funksional talab №8 (Texnik topshiriq, 4-band).
 *
 *  Amallar (hammasi POST + CSRF):
 *    approve – "kutilmoqda"  → "tasdiqlandi"  (kutubxonachi QAYTARISH KUNINI TANLAYDI: 1/2/3/5/10)
 *    return  – "tasdiqlandi" → "qaytarildi"
 *    reject  – "kutilmoqda"  → "rad_etildi"
 *
 *  TUZATISHLAR (debugging):
 *    1) Tasdiqlashda qaytarish muddati (due_date, loan_days) endi yoziladi –
 *       oldin yozilmagani uchun talaba muddatni ko'rmas, "Kechikkan" hisobi ishlamas edi.
 *    2) "Kechikkan" belgisi "tasdiqlandi" holatida ham chiqadi (muddat o'tgan bo'lsa).
 *    3) Taymer endi tasdiqlangan kitoblar uchun ham ishlaydi (topshirishga qolgan vaqt).
 *    4) Xodimlar izi: tasdiqlagan / qabul qilgan («Qaytarildi») / rad etgan xodim ALOHIDA ko'rinadi.
 *    5) "Talaba kodi" qidiruvi boshqa filtrlarni yo'qotmaydi.
 *
 *  Ustun hali yo'q bo'lsa ham sahifa ishlaydi (auto_rejected uchun zaxira hisob bor).
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

// Tasdiqlashda standart qaytarish muddati (kun) – select'da shu tanlangan turadi
const DEFAULT_LOAN_DAYS = 3;

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

/** Filtrlarni xavfsiz oladi (GET yoki POST massividan) */
function readFilters(array $src, string $statusKey): array
{
    $status = (string) ($src[$statusKey] ?? '');
    if (!in_array($status, STATUS_FILTERS, true)) {
        $status = '';
    }

    return [
        'status'  => $status,
        // HEMIS ID faqat raqam (eng ko'pi 20 ta)
        'q'       => mb_substr((string) preg_replace('/\D+/', '', (string) ($src['q'] ?? '')), 0, 20),
        'faculty' => mb_substr(trim((string) ($src['faculty'] ?? '')), 0, 150),
        'group'   => mb_substr(trim((string) ($src['group'] ?? '')), 0, 30),
        // Talaba kodi: harf + raqam, eng ko'pi 6 belgi, katta harflarda
        'code'    => normalizeReservationCode((string) ($src['code'] ?? '')),
    ];
}

/** Filtrlardan admin sahifa manzilini yasaydi (bo'sh filtrlar tushib qoladi) */
function adminUrl(array $filters): string
{
    $query = array_filter($filters, static fn(string $v): bool => $v !== '');
    return 'admin/index.php' . ($query ? '?' . http_build_query($query) : '');
}

/** Qiymat bor bo'lsa xavfsiz matn, bo'lmasa kulrang "—" */
function dash(?string $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? e($value) : '<span class="text-slate-400">—</span>';
}

/**
 * Kitob talabada turibdi-yu, qaytarish muddati o'tib ketganmi?
 * (holat "tasdiqlandi" yoki "berilgan" va due_date < hozir)
 */
function isOverdue(array $r): bool
{
    return in_array((string) $r['status'], ['tasdiqlandi', 'berilgan'], true)
        && !empty($r['due_date'])
        && strtotime((string) $r['due_date']) < time();
}

/**
 * Holat belgisi. Har doim BITTA qatorda (whitespace-nowrap).
 * auto_rejected = 1 bo'lsa – "⏱️ Avto-rad etilgan" maxsus belgisi.
 * Qaytarish muddati o'tgan bo'lsa – qo'shimcha qizil "Kechikkan" belgisi.
 */
function adminStatusBadge(array $r): string
{
    $base   = 'inline-block whitespace-nowrap text-xs px-2 py-0.5 rounded-full font-medium ';
    $status = (string) $r['status'];

    if ($status === 'rad_etildi' && (int) ($r['auto_rejected'] ?? 0) === 1) {
        return '<span class="' . $base . 'bg-orange-100 text-orange-700 border border-orange-300">⏱️ Avto-rad etilgan</span>';
    }

    $badge = match ($status) {
        'kutilmoqda'  => '<span class="' . $base . 'bg-amber-100 text-amber-700">Kutilmoqda</span>',
        'tasdiqlandi' => '<span class="' . $base . 'bg-blue-100 text-blue-700">Tasdiqlandi</span>',
        'berilgan'    => '<span class="' . $base . 'bg-green-100 text-green-700">Berilgan</span>',
        'qaytarildi'  => '<span class="' . $base . 'bg-slate-200 text-slate-700">Qaytarildi</span>',
        'rad_etildi'  => '<span class="' . $base . 'bg-red-100 text-red-700">Rad etildi</span>',
        default       => '<span class="' . $base . 'bg-slate-100 text-slate-600">' . e($status) . '</span>',
    };

    if (isOverdue($r)) {
        $badge .= '<div class="mt-1"><span class="' . $base . 'bg-red-100 text-red-700 font-semibold">⏰ Kechikkan</span></div>';
    }

    return $badge;
}

/** Holatning matnli nomi (modal uchun) */
function adminStatusLabel(array $r): string
{
    if ($r['status'] === 'rad_etildi' && (int) ($r['auto_rejected'] ?? 0) === 1) {
        return '⏱️ Avto-rad etilgan';
    }

    $label = [
        'kutilmoqda'  => 'Kutilmoqda',
        'tasdiqlandi' => 'Tasdiqlandi',
        'berilgan'    => 'Berilgan',
        'qaytarildi'  => 'Qaytarildi',
        'rad_etildi'  => 'Rad etildi',
    ][(string) $r['status']] ?? (string) $r['status'];

    return isOverdue($r) ? $label . ' · ⏰ Kechikkan' : $label;
}

/**
 * Modal uchun ma'lumotlar paketi (JSON). Kitob nomi/rasmi tugmasiga
 * data-order atributi sifatida qo'yiladi; JS uni o'qib modalni to'ldiradi.
 */
function orderPayload(array $r): string
{
    $style = categoryStyle((string) ($r['book_category'] ?? ''));

    $data = [
        'title'     => (string) $r['book_title'],
        'author'    => (string) ($r['book_author'] ?? ''),
        'genre'     => (string) ($r['book_genre'] ?? ''),
        'cover'     => !empty($r['cover_image']) ? url((string) $r['cover_image']) : '',
        'emoji'     => $style['emoji'],
        'student'   => (string) $r['user_name'],
        'hemis'     => (string) ($r['hemis_id'] ?? ''),
        'faculty'   => (string) ($r['faculty'] ?? ''),
        'group'     => (string) ($r['group_name'] ?? ''),
        'code'      => !empty($r['pickup_code']) ? formatReservationCode((string) $r['pickup_code']) : '',
        'status'    => adminStatusLabel($r),
        'reserved'  => formatDateTime((string) $r['created_at']),
        'picked'    => formatDateTime((string) ($r['picked_up_at'] ?? '')),
        'due'       => formatDateTime((string) ($r['due_date'] ?? '')),
        'returned'  => formatDateTime((string) ($r['returned_at'] ?? '')),
        // Xodimlar izi: tasdiqlagan / qabul qilgan / rad etgan (alohida-alohida)
        'staff'     => staffTrailText($r),
        'offline'   => (int) ($r['is_offline'] ?? 0) === 1,
        'auto'      => $r['status'] === 'rad_etildi' && (int) ($r['auto_rejected'] ?? 0) === 1,
        'deadline'  => formatDateTime((string) ($r['pickup_deadline'] ?? '')),
    ];

    return e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP));
}

/** Ixcham muqova (w-9 h-12): rasm bo'lsa rasm, bo'lmasa kategoriya belgisi */
function renderMiniCover(array $r): void
{
    $style = categoryStyle((string) ($r['book_category'] ?? ''));

    if (!empty($r['cover_image'])): ?>
        <img src="<?= e(url((string) $r['cover_image'])) ?>" alt=""
             class="w-9 h-12 flex-shrink-0 rounded object-cover shadow-sm">
    <?php else: ?>
        <span class="w-9 h-12 flex-shrink-0 rounded shadow-sm flex items-center justify-center text-lg <?= e($style['bg']) ?>"><?= $style['emoji'] ?></span>
    <?php endif;
}

/**
 * "Kitob" ustuni: muqova + nomi. Ikkalasi ham bitta tugma – bosilganda modal ochiladi.
 */
function renderBookButton(array $r): void
{
    ?>
    <button type="button" data-order-open data-order="<?= orderPayload($r) ?>"
            class="flex items-center gap-2 text-left w-full group">
        <?php renderMiniCover($r); ?>
        <span class="min-w-0 font-semibold text-blue-600 group-hover:underline break-words leading-snug"><?= e($r['book_title']) ?></span>
    </button>
    <?php
}

/**
 * Holatga qarab amal tugmalari.
 * "Tasdiqlash" formasida QAYTARISH KUNINI tanlash maydoni bor (1/2/3/5/10 kun).
 * Kompyuter jadvalida tugmalar ustma-ust (tor ustun), telefonda yonma-yon.
 */
function renderActions(array $r, array $filters, bool $compact): void
{
    $status = (string) $r['status'];
    if ($status !== 'kutilmoqda' && $status !== 'tasdiqlandi') {
        echo '<span class="text-xs text-slate-400">—</span>';
        return;
    }

    $size = $compact ? 'w-full text-sm py-2' : 'w-full text-xs px-2.5 py-1.5 whitespace-nowrap';

    // [amal, yozuv, rang, tasdiq matni]
    $buttons = $status === 'kutilmoqda'
        ? [
            ['approve', 'Tasdiqlash', 'bg-green-600 hover:bg-green-700', null],
            ['reject',  'Rad etish',  'bg-red-600 hover:bg-red-700',
                "Bu band qilishni rad etmoqchimisiz? Talaba kabinetida 'Rad etildi' deb ko'rinadi."],
        ]
        : [
            ['return', 'Qaytarildi', 'bg-blue-600 hover:bg-blue-700', null],
        ];

    echo '<div class="' . ($compact ? 'grid grid-cols-' . count($buttons) . ' gap-2' : 'flex flex-col gap-1.5') . '">';

    foreach ($buttons as [$action, $label, $color, $confirmText]) {
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
            <input type="hidden" name="code" value="<?= e($filters['code']) ?>">

            <?php if ($action === 'approve'): ?>
                <!-- Qaytarish kunini kutubxonachi tanlaydi (server qiymatni qayta tekshiradi) -->
                <label class="block text-[11px] text-slate-500 mb-0.5">Necha kunga beriladi?</label>
                <select name="loan_days" aria-label="Necha kunga beriladi (qaytarish muddati)"
                        class="w-full mb-1 border border-slate-300 rounded-md px-1.5 py-1 bg-white text-xs
                               focus:outline-none focus:ring-2 focus:ring-green-300">
                    <?php foreach (OFFLINE_DAY_OPTIONS as $day): ?>
                        <option value="<?= (int) $day ?>" <?= $day === DEFAULT_LOAN_DAYS ? 'selected' : '' ?>>
                            <?= (int) $day ?> kun
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="submit" class="<?= e($color . ' ' . $size) ?> text-white rounded-md">
                <?= e($label) ?>
            </button>
        </form>
        <?php
    }

    echo '</div>';
}

/**
 * Qolgan vaqt belgisi (taymer). Matnni pastdagi JS har soniyada yangilaydi.
 *   kutilmoqda           → olib ketish muddati (pickup_deadline), tugasa "Muddati o'tgan"
 *   tasdiqlandi/berilgan → qaytarish muddati (due_date), tugasa "Kechikkan"
 */
function renderTimer(array $r): void
{
    $status = (string) $r['status'];

    if ($status === 'kutilmoqda') {
        $target  = (string) ($r['pickup_deadline'] ?? '');
        $title   = 'Olib ketish muddatigacha qolgan vaqt';
        $expired = "Muddati o'tgan";
    } elseif ($status === 'tasdiqlandi' || $status === 'berilgan') {
        $target  = (string) ($r['due_date'] ?? '');
        $title   = 'Kitobni topshirish muddatigacha qolgan vaqt';
        $expired = 'Kechikkan';
    } else {
        return;
    }

    if ($target === '') {
        return;
    }

    $rem = formatRemainingTime($target, $expired);
    $cls = $rem['urgent'] || $rem['expired']
        ? 'bg-red-100 text-red-700'
        : 'bg-amber-100 text-amber-800';
    ?>
    <span data-deadline-ts="<?= (int) strtotime($target) ?>"
          data-expired-text="<?= e($expired) ?>" title="<?= e($title) ?>"
          class="inline-block mt-1 px-1.5 py-0.5 rounded font-medium text-xs <?= e($cls) ?>">
        <?= $rem['urgent'] || $rem['expired'] ? '⚠️' : '⏳' ?> <?= e($rem['text']) ?>
    </span>
    <?php
}

// ------------------------------------------------------------
// 1. AMALLARNI BAJARISH (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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

        $now     = date('Y-m-d H:i:s');
        $staffId = (int) ($_SESSION['user_id'] ?? 0);

        // Kitob ID si (available_copies ni to'g'ri boshqarish uchun)
        $stmtBook = $pdo->prepare('SELECT book_id FROM reservations WHERE id = :id');
        $stmtBook->execute([':id' => $id]);
        $resBookId = (int) ($stmtBook->fetchColumn() ?: 0);

        if ($action === 'approve') {
            // Qaytarish muddati: kutubxonachi tanlaydi (1/2/3/5/10 kun).
            // Noto'g'ri qiymat kelsa – standart muddat (DEFAULT_LOAN_DAYS) olinadi.
            $loanDays = offlineLoanDays($_POST['loan_days'] ?? null, DEFAULT_LOAN_DAYS);
            $dueDate  = date('Y-m-d H:i:s', strtotime("+{$loanDays} days"));

            $stmt = $pdo->prepare(
                "UPDATE reservations
                 SET status = 'tasdiqlandi', picked_up_at = :now, due_date = :due_date,
                     loan_days = :loan_days, served_by = :staff_id
                 WHERE id = :id AND status = 'kutilmoqda'"
            );
            $stmt->execute([
                ':now'       => $now,
                ':due_date'  => $dueDate,
                ':loan_days' => $loanDays,
                ':staff_id'  => $staffId > 0 ? $staffId : null,
                ':id'        => $id,
            ]);
            $message = "Band qilish tasdiqlandi. Kitob {$loanDays} kunga berildi (qaytarish muddati: "
                     . date('d.m.Y H:i', strtotime($dueDate)) . ').';

        } elseif ($action === 'return') {
            $stmt = $pdo->prepare(
                "UPDATE reservations
                 SET status = 'qaytarildi', returned_at = :now, returned_by = :staff_id
                 WHERE id = :id AND status IN ('tasdiqlandi', 'berilgan')"
            );
            $stmt->execute([
                ':now'      => $now,
                ':staff_id' => $staffId > 0 ? $staffId : null,
                ':id'       => $id,
            ]);
            $message = "Kitob qaytarildi deb belgilandi. Nusxa yana bo'sh.";

            if ($stmt->rowCount() > 0 && $resBookId > 0) {
                incrementAvailableCopies($pdo, $resBookId);
            }

        } elseif ($action === 'reject') {
            // Qo'lda rad etish: rejected_by yoziladi (shu sababli avto-rad etish emas)
            $stmt = $pdo->prepare(
                "UPDATE reservations
                 SET status = 'rad_etildi', rejected_by = :staff_id
                 WHERE id = :id AND status = 'kutilmoqda'"
            );
            $stmt->execute([
                ':staff_id' => $staffId > 0 ? $staffId : null,
                ':id'       => $id,
            ]);
            $message = 'Band qilish rad etildi.';

            if ($stmt->rowCount() > 0 && $resBookId > 0) {
                incrementAvailableCopies($pdo, $resBookId);
            }

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
$faculties    = [];
$groups       = [];

try {
    $stats = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM books) AS books,
            (SELECT COUNT(*) FROM reservations WHERE status = 'kutilmoqda') AS pending,
            (SELECT COUNT(*) FROM reservations WHERE status = 'tasdiqlandi') AS approved,
            (SELECT COUNT(*) FROM users WHERE role = 'talaba') AS students"
    )->fetch();

    $faculties = $pdo->query(
        "SELECT DISTINCT faculty FROM users
         WHERE role = 'talaba' AND faculty IS NOT NULL AND faculty <> '' ORDER BY faculty"
    )->fetchAll(PDO::FETCH_COLUMN);

    $groups = $pdo->query(
        "SELECT DISTINCT group_name FROM users
         WHERE role = 'talaba' AND group_name IS NOT NULL AND group_name <> '' ORDER BY group_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    // reservations.auto_rejected ustuni bormi? Yo'q bo'lsa – zaxira hisob:
    // rad etilgan va rad etgan xodimi yo'q yozuv = avto-rad etilgan.
    $autoExpr = columnExists($pdo, 'reservations', 'auto_rejected')
        ? 'r.auto_rejected'
        : "(r.status = 'rad_etildi' AND r.rejected_by IS NULL)";

    // Xodimlar izi uchun uchta alohida JOIN: tasdiqlagan (staff), qabul qilgan (returner), rad etgan (rejecter)
    $sql = "SELECT r.id, r.reserved_date, r.created_at, r.pickup_days, r.pickup_deadline, r.status,
                   r.picked_up_at, r.due_date, r.loan_days, r.returned_at, r.is_offline,
                   r.served_by, r.returned_by, r.rejected_by,
                   r.pickup_code, {$autoExpr} AS auto_rejected,
                   u.name AS user_name, u.email AS user_email,
                   u.faculty, u.direction, u.group_name, u.hemis_id,
                   b.id AS book_id, b.title AS book_title, b.author AS book_author,
                   b.genre AS book_genre, b.category AS book_category, b.cover_image,
                   staff.name    AS staff_name,
                   returner.name AS returner_name,
                   rejecter.name AS rejecter_name
            FROM reservations r
            JOIN users u ON u.id = r.user_id
            JOIN books b ON b.id = r.book_id
            LEFT JOIN users staff    ON r.served_by   = staff.id
            LEFT JOIN users returner ON r.returned_by = returner.id
            LEFT JOIN users rejecter ON r.rejected_by = rejecter.id
            WHERE 1 = 1";
    $params = [];

    if ($filters['status'] !== '') {
        $sql .= ' AND r.status = :status';
        $params[':status'] = $filters['status'];
    }
    if ($filters['q'] !== '') {
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
    if ($filters['code'] !== '') {
        $sql .= ' AND r.pickup_code LIKE :pickup_code';
        $params[':pickup_code'] = $filters['code'] . '%';
    }

    // Kutilayotganlar tepada (muddati eng oz qolgani birinchi), qolganlari – yangisi birinchi
    $sql .= " ORDER BY CASE WHEN r.status = 'kutilmoqda' THEN 1 ELSE 2 END,
                       CASE WHEN r.status = 'kutilmoqda' THEN r.pickup_deadline END ASC,
                       r.created_at DESC, r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Admin panelda xatolik: ' . $ex->getMessage());
    setFlash('error', "Ma'lumotlarni yuklashda xatolik yuz berdi. (migrations/010_tuzatishlar.sql ishga tushirilganmi?)");
}

$tabs = [
    ''            => 'Hammasi',
    'kutilmoqda'  => 'Kutilmoqda',
    'tasdiqlandi' => 'Tasdiqlangan',
    'qaytarildi'  => 'Qaytarilgan',
    'rad_etildi'  => 'Rad etilgan',
];

$hasSearch = $filters['q'] !== '' || $filters['faculty'] !== '' || $filters['group'] !== '' || $filters['code'] !== '';

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

<!-- ====== TALABA KODI BO'YICHA TEZKOR QIDIRUV ======
     Boshqa filtrlar (holat, HEMIS ID, fakultet, guruh) yashirin maydonlar bilan saqlanadi. -->
<form method="get" action="<?= e(url('admin/index.php')) ?>" id="codeSearchForm"
      class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-3 flex flex-col sm:flex-row sm:items-center gap-2">
    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
    <input type="hidden" name="faculty" value="<?= e($filters['faculty']) ?>">
    <input type="hidden" name="group" value="<?= e($filters['group']) ?>">

    <label for="codeInput" class="text-sm font-semibold text-amber-900 shrink-0">🔑 Talaba kodi:</label>

    <input type="text" id="codeInput" name="code" value="<?= e($filters['code']) ?>"
           maxlength="7" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false"
           placeholder="Masalan: K7M4P2"
           aria-label="Talaba kodi bo'yicha qidirish"
           class="border border-amber-300 rounded-md px-3 py-2 bg-white font-mono text-lg tracking-widest uppercase
                  focus:outline-none focus:ring-2 focus:ring-amber-300 sm:w-48">

    <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-semibold rounded-md px-5 py-2 text-sm">
        Topish
    </button>

    <?php if ($filters['code'] !== ''): ?>
        <a href="<?= e(url(adminUrl(['status' => $filters['status'], 'q' => $filters['q'], 'faculty' => $filters['faculty'], 'group' => $filters['group'], 'code' => '']))) ?>"
           class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-sm text-center">
            Tozalash
        </a>
    <?php endif; ?>
</form>

<!-- ====== QIDIRUV VA FILTRLAR ====== -->
<form method="get" action="<?= e(url('admin/index.php')) ?>"
      class="bg-white border border-slate-200 rounded-xl p-3 mb-3 grid grid-cols-1 md:grid-cols-[1.2fr_1fr_1fr_auto_auto] gap-2">

    <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
    <input type="hidden" name="code" value="<?= e($filters['code']) ?>">

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
        <a href="<?= e(url(adminUrl(['status' => $filters['status'], 'q' => '', 'faculty' => '', 'group' => '', 'code' => '']))) ?>"
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

    <!-- Kompyuter (1024px dan keng): 8 ustunli ixcham jadval.
         overflow-x-auto YO'Q – jadval konteyner kengligiga 100% moslashadi. -->
    <div class="hidden lg:block bg-white border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-xs text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-2.5 py-3 font-semibold">Talaba FIO</th>
                    <th class="px-2.5 py-3 font-semibold whitespace-nowrap">HEMIS ID</th>
                    <th class="px-2.5 py-3 font-semibold">Fakultet va guruh</th>
                    <th class="px-2.5 py-3 font-semibold">Kitob</th>
                    <th class="px-2.5 py-3 font-semibold">Band qilingan / Qolgan vaqt</th>
                    <th class="px-2.5 py-3 font-semibold">Berilgan / Muddat / Xodimlar</th>
                    <th class="px-2.5 py-3 font-semibold whitespace-nowrap">Holati</th>
                    <th class="px-2.5 py-3 font-semibold text-right">Harakatlar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $r): ?>
                    <tr class="border-t border-slate-200 align-top">

                        <!-- 1) FIO (bold) + Email + 🔑 sariq kod badge -->
                        <td class="px-2.5 py-3 min-w-0">
                            <div class="font-bold text-sm break-words"><?= e($r['user_name']) ?></div>
                            <div class="text-slate-500 break-all"><?= e($r['user_email']) ?></div>
                            <?php renderCodeBadge($r['pickup_code'] ?? null, (string) $r['status']); ?>
                        </td>

                        <!-- 2) HEMIS ID – bitta qatorda -->
                        <td class="px-2.5 py-3 whitespace-nowrap font-mono">
                            <?= dash($r['hemis_id'] ?? null) ?>
                        </td>

                        <!-- 3) Fakultet + guruh badge -->
                        <td class="px-2.5 py-3">
                            <div class="break-words"><?= dash($r['faculty'] ?? null) ?></div>
                            <?php if (!empty($r['group_name'])): ?>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 font-medium"><?= e($r['group_name']) ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- 4) Kitob: ixcham rasm + nomi (bosilganda modal) -->
                        <td class="px-2.5 py-3">
                            <?php renderBookButton($r); ?>
                        </td>

                        <!-- 5) Band vaqti + real-time taymer (kutilmoqda: olib ketishga) -->
                        <td class="px-2.5 py-3">
                            <div class="font-bold whitespace-nowrap"><?= e(formatDateTime((string) $r['created_at'])) ?></div>
                            <?php if (!empty($r['pickup_deadline'])): ?>
                                <div class="text-slate-600 mt-0.5"><?= (int) $r['pickup_days'] ?> kun · <?= e(formatDateTime((string) $r['pickup_deadline'])) ?> gacha</div>
                            <?php endif; ?>
                            <?php if ($r['status'] === 'kutilmoqda'): ?>
                                <?php renderTimer($r); ?>
                            <?php endif; ?>
                        </td>

                        <!-- 6) Olib ketilgan sana + qaytarish muddati (taymer) + xodimlar izi -->
                        <td class="px-2.5 py-3">
                            <?php if (!empty($r['picked_up_at'])): ?>
                                <div class="text-green-700 whitespace-nowrap"><b><?= e(formatDateTime((string) $r['picked_up_at'])) ?></b></div>
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>

                            <?php if (!empty($r['due_date']) && in_array($r['status'], ['tasdiqlandi', 'berilgan'], true)): ?>
                                <div class="text-slate-600 mt-0.5">
                                    <?= (int) ($r['loan_days'] ?? 0) > 0 ? (int) $r['loan_days'] . ' kunga · ' : '' ?>
                                    <span class="whitespace-nowrap"><?= e(formatDateTime((string) $r['due_date'])) ?> gacha</span>
                                </div>
                                <?php renderTimer($r); ?>
                            <?php endif; ?>

                            <!-- Tasdiqlagan / qabul qilgan / rad etgan xodim – alohida qatorlarda -->
                            <?php renderStaffTrail($r); ?>

                            <?php if ((int) $r['is_offline'] === 1): ?>
                                <span class="inline-block mt-1 px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 font-medium">Offlayn</span>
                            <?php endif; ?>
                        </td>

                        <!-- 7) Holati – bitta qatorda -->
                        <td class="px-2.5 py-3 whitespace-nowrap">
                            <?= adminStatusBadge($r) ?>
                        </td>

                        <!-- 8) Harakatlar -->
                        <td class="px-2.5 py-3">
                            <div class="flex justify-end"><?php renderActions($r, $filters, false); ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Telefon va planshet (1024px dan tor): kartochkalar -->
    <div class="lg:hidden space-y-3">
        <?php foreach ($reservations as $r): ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-bold break-words"><?= e($r['user_name']) ?></div>
                        <div class="text-xs text-slate-500 break-all"><?= e($r['user_email']) ?></div>
                        <?php renderCodeBadge($r['pickup_code'] ?? null, (string) $r['status']); ?>
                    </div>
                    <div class="shrink-0"><?= adminStatusBadge($r) ?></div>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                    <div>
                        <dt class="text-slate-500">HEMIS ID</dt>
                        <dd class="font-mono font-semibold whitespace-nowrap"><?= dash($r['hemis_id'] ?? null) ?></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Guruh</dt>
                        <dd class="font-semibold"><?= dash($r['group_name'] ?? null) ?></dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Fakultet</dt>
                        <dd class="font-semibold break-words"><?= dash($r['faculty'] ?? null) ?></dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500 mb-1">Kitob</dt>
                        <dd><?php renderBookButton($r); ?></dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Band qilingan</dt>
                        <dd>
                            <b><?= e(formatDateTime((string) $r['created_at'])) ?></b>
                            <?php if (!empty($r['pickup_deadline'])): ?>
                                <span class="text-slate-600">· <?= (int) $r['pickup_days'] ?> kun (<?= e(formatDateTime((string) $r['pickup_deadline'])) ?> gacha)</span>
                            <?php endif; ?>
                            <?php if ($r['status'] === 'kutilmoqda'): ?>
                                <div><?php renderTimer($r); ?></div>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if (!empty($r['picked_up_at'])): ?>
                        <div>
                            <dt class="text-slate-500">Berilgan</dt>
                            <dd class="font-semibold text-green-700"><?= e(formatDateTime((string) $r['picked_up_at'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($r['due_date']) && in_array($r['status'], ['tasdiqlandi', 'berilgan'], true)): ?>
                        <div>
                            <dt class="text-slate-500">Qaytarish muddati</dt>
                            <dd class="font-semibold">
                                <?= e(formatDateTime((string) $r['due_date'])) ?>
                                <div><?php renderTimer($r); ?></div>
                            </dd>
                        </div>
                    <?php endif; ?>
                    <div class="col-span-2">
                        <dt class="text-slate-500">Xodimlar</dt>
                        <dd><?php renderStaffTrail($r); ?></dd>
                    </div>
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

<!-- ====== BUYURTMA TAFSILOTLARI MODALI ======
     Ichini pastdagi JS to'ldiradi (faqat textContent – XSS bo'lmaydi). -->
<div id="orderModal" role="dialog" aria-modal="true" aria-labelledby="orderModalTitle" inert
     class="fixed inset-0 z-[65] flex items-center justify-center p-4 bg-black/50
            opacity-0 pointer-events-none transition-opacity duration-200">
    <div class="relative w-full max-w-md max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl">

        <button type="button" id="orderModalClose" aria-label="Oynani yopish"
                class="absolute top-2 right-2 z-10 rounded-full bg-white/90 hover:bg-white shadow w-9 h-9 text-lg text-slate-600">✕</button>

        <!-- Katta muqova -->
        <div id="omCoverBox" class="h-52 flex items-center justify-center text-7xl bg-slate-100">
            <img id="omCover" src="" alt="" class="hidden h-full w-full object-contain">
            <span id="omEmoji"></span>
        </div>

        <div class="p-5 space-y-4">
            <!-- Kitob -->
            <div>
                <h2 id="orderModalTitle" class="text-xl font-bold leading-snug break-words"></h2>
                <p class="text-sm text-slate-600 mt-1 break-words"><b>Muallif:</b> <span id="omAuthor"></span></p>
                <p class="text-sm text-slate-600"><b>Janr:</b> <span id="omGenre"></span></p>
            </div>

            <!-- Avto-rad etilgan ogohlantirishi -->
            <div id="omAuto" class="hidden border border-orange-300 bg-orange-50 text-orange-800 rounded-md px-3 py-2 text-sm">
                ⏱️ Bu band qilish olib ketish muddati o'tgani uchun <b>avtomatik rad etilgan</b>.
                Kitob nusxasi boshqa talabalar uchun bo'shatilgan.
            </div>

            <!-- Talaba -->
            <div class="border border-slate-200 rounded-lg p-3">
                <div class="text-xs uppercase tracking-wide text-slate-500 mb-1">Talaba</div>
                <div id="omStudent" class="font-bold break-words"></div>
                <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                    <div><dt class="text-slate-500">HEMIS ID</dt><dd id="omHemis" class="font-mono font-semibold whitespace-nowrap"></dd></div>
                    <div><dt class="text-slate-500">Guruh</dt><dd id="omGroup" class="font-semibold"></dd></div>
                    <div class="col-span-2"><dt class="text-slate-500">Fakultet</dt><dd id="omFaculty" class="font-semibold break-words"></dd></div>
                </dl>
                <div id="omCodeWrap" class="mt-2">
                    <span class="inline-block px-1.5 py-0.5 rounded border border-amber-300 bg-amber-100 text-amber-800 font-mono text-xs font-bold tracking-wider">
                        🔑 <span id="omCode"></span>
                    </span>
                </div>
            </div>

            <!-- Sanalar va xodimlar -->
            <dl class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                <div class="col-span-2"><dt class="text-slate-500">Holati</dt><dd id="omStatus" class="font-semibold"></dd></div>
                <div><dt class="text-slate-500">Band qilingan</dt><dd id="omReserved" class="font-semibold"></dd></div>
                <div><dt class="text-slate-500">Olib ketilgan</dt><dd id="omPicked" class="font-semibold"></dd></div>
                <div><dt class="text-slate-500">Qaytarish muddati</dt><dd id="omDue" class="font-semibold"></dd></div>
                <div><dt class="text-slate-500">Qaytarilgan</dt><dd id="omReturned" class="font-semibold"></dd></div>
                <div class="col-span-2"><dt class="text-slate-500">Mas'ul xodimlar</dt><dd id="omStaff" class="font-semibold break-words"></dd></div>
            </dl>
        </div>
    </div>
</div>

<!-- ====== JONLI TAYMER ====== -->
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
                // Matn serverdan keladi: olib ketish uchun "Muddati o'tgan", qaytarish uchun "Kechikkan"
                node.className = URGENT;
                node.textContent = '⚠️ ' + (node.getAttribute('data-expired-text') || "Muddati o'tgan");
                return;
            }

            var d = Math.floor(left / 86400);
            var h = Math.floor((left % 86400) / 3600);
            var m = Math.floor((left % 3600) / 60);
            var s = left % 60;

            var text   = (d > 0 ? d + ' kun ' : '') + two(h) + ':' + two(m) + ':' + two(s) + ' qoldi';
            var urgent = left <= 6 * 3600;   // oxirgi 6 soatda qizil

            node.className = urgent ? URGENT : NORMAL;
            node.textContent = (urgent ? '⚠️ ' : '⏳ ') + text;
        });
    }

    tick();
    setInterval(tick, 1000);
})();
</script>

<!-- ====== TALABA KODI MAYDONI ====== -->
<script>
(function () {
    'use strict';

    var form  = document.getElementById('codeSearchForm');
    var input = document.getElementById('codeInput');
    if (!form || !input) { return; }

    input.addEventListener('input', function () {
        var cleaned = input.value.replace(/[^A-Za-z0-9]+/g, '').toUpperCase().slice(0, 6);
        if (cleaned !== input.value) { input.value = cleaned; }
        if (cleaned.length === 6) { form.submit(); }   // 6 belgi – avtomatik qidiradi
    });

    if (input.value === '' && window.matchMedia('(min-width: 1024px)').matches) {
        input.focus({ preventScroll: true });
    }
})();
</script>

<!-- ====== BUYURTMA MODALI (Vanilla JS) ======
     Kitob nomi yoki rasmi ([data-order-open]) bosilganda ochiladi.
     Esc, orqa fonga bosish yoki ✕ yopadi; Tab oyna ichida aylanadi.
     Hamma matn textContent orqali qo'yiladi (innerHTML ishlatilmaydi). -->
<script>
(function () {
    'use strict';

    var modal    = document.getElementById('orderModal');
    var closeBtn = document.getElementById('orderModalClose');
    if (!modal || !closeBtn) { return; }

    var lastFocus = null;
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    var BG = ['bg-slate-100', 'bg-blue-100', 'bg-pink-100', 'bg-green-100', 'bg-slate-200'];

    function $(id) { return document.getElementById(id); }

    /** Matnni qo'yadi; bo'sh bo'lsa "—" */
    function setText(id, value) {
        $(id).textContent = (value !== undefined && value !== null && value !== '') ? value : '—';
    }

    /** Modalni buyurtma ma'lumotlari bilan to'ldiradi */
    function fill(d) {
        $('orderModalTitle').textContent = d.title || '';
        setText('omAuthor', d.author);
        setText('omGenre', d.genre);

        // Katta muqova: rasm bo'lsa rasm, bo'lmasa kategoriya belgisi
        var box = $('omCoverBox'), img = $('omCover'), emoji = $('omEmoji');
        BG.forEach(function (c) { box.classList.remove(c); });
        box.classList.add('bg-slate-100');
        if (d.cover) {
            img.src = d.cover;
            img.alt = d.title || '';
            img.classList.remove('hidden');
            emoji.textContent = '';
        } else {
            img.removeAttribute('src');
            img.alt = '';
            img.classList.add('hidden');
            emoji.textContent = d.emoji || '📕';
        }

        // Talaba
        $('omStudent').textContent = d.student || '';
        setText('omHemis', d.hemis);
        setText('omGroup', d.group);
        setText('omFaculty', d.faculty);

        // Generatsiya kodi (offlayn berilganda kod bo'lmaydi)
        $('omCodeWrap').classList.toggle('hidden', !d.code);
        $('omCode').textContent = d.code || '';

        // Holat, sanalar, xodimlar
        setText('omStatus', d.status);
        setText('omReserved', d.reserved);
        setText('omPicked', d.picked);
        setText('omDue', d.due);
        setText('omReturned', d.returned);
        setText('omStaff', d.staff);

        // Avto-rad etilgan ogohlantirishi
        $('omAuto').classList.toggle('hidden', !d.auto);
    }

    function openModal(trigger) {
        var data;
        try {
            data = JSON.parse(trigger.getAttribute('data-order'));
        } catch (err) {
            return;   // buzilgan ma'lumot – modal ochilmaydi
        }

        lastFocus = document.activeElement;
        fill(data);

        modal.inert = false;
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');
        modal.scrollTop = 0;
        closeBtn.focus({ preventScroll: true });
    }

    function closeModal() {
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        document.body.classList.remove('overflow-hidden');
        modal.inert = true;

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    function isOpen() { return !modal.classList.contains('pointer-events-none'); }

    // Kitob nomi / rasmi bosilganda (hodisa delegatsiyasi)
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-order-open]');
        if (trigger) {
            event.preventDefault();
            openModal(trigger);
        }
    });

    closeBtn.addEventListener('click', closeModal);

    // Qoraytirilgan fonga bosilsa yopiladi, oyna ichiga bosilsa – yo'q
    modal.addEventListener('click', function (event) {
        if (event.target === modal) { closeModal(); }
    });

    // Esc – yopish, Tab – oyna ichida aylantirish
    document.addEventListener('keydown', function (event) {
        if (!isOpen()) { return; }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.key === 'Tab') {
            var items = Array.prototype.filter.call(modal.querySelectorAll(FOCUSABLE), function (n) {
                return n.offsetParent !== null;
            });
            if (!items.length) { return; }

            var first = items[0], last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first.focus();
            }
        }
    });

    // "Orqaga" tugmasi bilan qaytilganda ochiq oyna qolib ketmasin
    window.addEventListener('pageshow', function () { if (isOpen()) { closeModal(); } });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
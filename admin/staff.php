<?php
/**
 * ============================================================
 *  Fayl: admin/staff.php – Xodimlar (kutubxonachilar) boshqaruvi
 *  Faqat SUPERADMIN uchun.
 *
 *  Imkoniyatlar:
 *    - xodimlar ro'yxati: bergan, qaytarib olgan va rad etgan kitoblar soni
 *    - "+ Xodim qo'shish": MODAL OYNA ochiladi (FIO, email, parol).
 *      Parol password_hash() bilan saqlanadi. Paroldagi "Ko'rsatish" tugmasi
 *      yozilayotgan parolni ko'rish imkonini beradi (xato yozmaslik uchun).
 *      Xatolar oynaning ichida chiqadi, oyna ochiq va to'ldirilgan qoladi.
 *    - "Bloklash" (qizil) ↔ "Blokdan ochish" (sariq) tugmasi.
 *      Kasal bo'lgan xodim vaqtincha bloklanadi: tizimga kira olmaydi.
 *      Ishdan ketganlarni blokdan ochish shart emas.
 *    - xodim ismi bosilganda: xodim bilan bog'liq BARCHA yozuvlar oynada chiqadi
 *      (JSON: ?detail=ID):
 *        * bergan / tasdiqlagan (served_by) + kitobning hozirgi holati:
 *          Talabada / Kechikkan / Qaytarilgan
 *        * qaytarib olgan       (returned_by)
 *        * rad etgan            (rejected_by) – aniq vaqt rejected_at da
 *      Oyna ichida filtr tugmalari bor (Hammasi, Bergan, Qaytarib olgan,
 *      Rad etgan, Hozir qo'lida, Kechikkan).
 *
 *  O'chirish va parolni yangilash tugmalari YO'Q (so'rov bo'yicha).
 *  Eslatma: avval migrations/011_xodim_bloklash.sql va
 *           migrations/012_rejected_at.sql ni ishga tushiring.
 *
 *  YANGI: xodim qo'shish formasi modal oynaga o'tkazildi, parolni
 *  ko'rsatish/yashirish tugmasi qo'shildi, xatolar oyna ichida ko'rsatiladi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

// Faqat Bosh admin (superadmin) kira oladi
requireSuperAdmin();

$meId = (int) ($_SESSION['user_id'] ?? 0);

/** Sana-vaqtni "30.10.2026 13:05" ko'rinishida chiqaradi */
function staffDate(?string $value): string
{
    return ($value === null || $value === '') ? '' : date('d.m.Y H:i', strtotime($value));
}

/** Kechikish davomiyligini matnga aylantiradi: "6 kun 3 soat", "5 soat", "1 soatdan kam" */
function lateText(int $seconds): string
{
    $days  = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);

    if ($days > 0) {
        return $days . ' kun' . ($hours > 0 ? ' ' . $hours . ' soat' : '');
    }
    return $hours > 0 ? $hours . ' soat' : '1 soatdan kam';
}

// ------------------------------------------------------------
// 1. POST AMALLARI: xodim qo'shish, bloklash, blokdan ochish (CSRF bilan)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
        redirect('admin/staff.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    // ---------- 1.1. YANGI XODIM QO'SHISH ----------
    if ($action === 'add') {
        $newName  = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        $newEmail = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $newPass  = (string) ($_POST['password'] ?? '');
        $newPass2 = (string) ($_POST['password_confirm'] ?? '');

        $addErrors = [];

        if (mb_strlen($newName) < 3 || mb_strlen($newName) > 100) {
            $addErrors[] = "Xodim FIO si 3 tadan 100 tagacha belgidan iborat bo'lsin.";
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newEmail) > 150) {
            $addErrors[] = "Email manzili noto'g'ri kiritilgan.";
        }
        if (strlen($newPass) < 6 || strlen($newPass) > 72) {
            $addErrors[] = "Parol 6 tadan 72 tagacha belgidan iborat bo'lsin.";
        }
        if ($newPass !== $newPass2) {
            $addErrors[] = "Parol va uning takrori bir xil emas.";
        }

        if (!$addErrors) {
            try {
                // Email band emasligini tekshiramiz
                $chk = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $chk->execute([':email' => $newEmail]);

                if ($chk->fetch()) {
                    $addErrors[] = "Bu email bilan akkaunt allaqachon mavjud.";
                } else {
                    // Parol xeshlanadi (ochiq saqlanmaydi), rol har doim 'admin' (kutubxonachi)
                    $ins = $pdo->prepare(
                        "INSERT INTO users (name, email, password, role)
                         VALUES (:name, :email, :password, 'admin')"
                    );
                    $ins->execute([
                        ':name'     => $newName,
                        ':email'    => $newEmail,
                        ':password' => password_hash($newPass, PASSWORD_DEFAULT),
                    ]);

                    setFlash('success', "Yangi xodim qo'shildi: {$newName}. U shu email va parol bilan tizimga kira oladi.");
                    redirect('admin/staff.php');
                }
            } catch (PDOException $ex) {
                // 23000 – UNIQUE cheklov (ikki kishi bir vaqtda bir xil email kiritsa)
                if ((string) $ex->getCode() === '23000') {
                    $addErrors[] = "Bu email bilan akkaunt allaqachon mavjud.";
                } else {
                    error_log('[Kutubxona-ARM] Xodim qo\'shishda xatolik: ' . $ex->getMessage());
                    $addErrors[] = "Kutilmagan xatolik yuz berdi. Keyinroq urinib ko'ring.";
                }
            }
        }

        // Xatolik bo'lsa: xatolar OYNA ICHIDA ko'rsatiladi (flash emas), oyna ochiq va
        // to'ldirilgan holda qaytadi. Parol xavfsizlik uchun qaytarilmaydi.
        $_SESSION['staff_add_errors'] = $addErrors;
        $_SESSION['staff_add_old']    = ['name' => $newName, 'email' => $newEmail];
        redirect('admin/staff.php');
    }

    // ---------- 1.2. BLOKLASH / BLOKDAN OCHISH ----------
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);

    if ($targetId === false || $targetId < 1 || !in_array($action, ['block', 'unblock'], true)) {
        setFlash('error', "Noto'g'ri so'rov.");
        redirect('admin/staff.php');
    }

    // O'zini bloklay olmaydi (tizimdan chiqib qolmasligi uchun)
    if ($action === 'block' && $targetId === $meId) {
        setFlash('error', "O'zingizni bloklab bo'lmaydi.");
        redirect('admin/staff.php');
    }

    try {
        if ($action === 'block') {
            $stmt = $pdo->prepare(
                "UPDATE users SET is_blocked = 1, blocked_at = :now
                 WHERE id = :id AND role = 'admin' AND is_blocked = 0"
            );
            $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $targetId]);
            $okText = 'Xodim bloklandi. U blokdan ochilmaguncha tizimga kira olmaydi.';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE users SET is_blocked = 0, blocked_at = NULL
                 WHERE id = :id AND role = 'admin' AND is_blocked = 1"
            );
            $stmt->execute([':id' => $targetId]);
            $okText = 'Xodim blokdan ochildi. U yana ishlay oladi.';
        }

        if ($stmt->rowCount() > 0) {
            setFlash('success', $okText);
        } else {
            setFlash('error', "Amalni bajarib bo'lmadi (holat allaqachon o'zgargan bo'lishi mumkin).");
        }
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Xodimni bloklashda xatolik: ' . $ex->getMessage());
        setFlash('error', "Kutilmagan xatolik yuz berdi. (011-migratsiya ishga tushirilganmi?)");
    }

    redirect('admin/staff.php');
}

// ------------------------------------------------------------
// 2. XODIM TAFSILOTI (JSON): ?detail=ID
//    Xodim bilan bog'liq hamma yozuv:
//      - bergan / tasdiqlagan (served_by) + kitobning hozirgi holati
//      - qaytarib olgan       (returned_by)
//      - rad etgan            (rejected_by, vaqti rejected_at)
// ------------------------------------------------------------
if (isset($_GET['detail'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $staffId = filter_var($_GET['detail'], FILTER_VALIDATE_INT);
    if ($staffId === false || $staffId < 1) {
        http_response_code(400);
        echo '{"error":"bad_id"}';
        exit;
    }

    try {
        $st = $pdo->prepare("SELECT id, name, email FROM users WHERE id = :id AND role = 'admin' LIMIT 1");
        $st->execute([':id' => $staffId]);
        $staff = $st->fetch();

        if (!$staff) {
            http_response_code(404);
            echo '{"error":"not_found"}';
            exit;
        }

        // Har bir nomli parametr faqat BIR marta (ATTR_EMULATE_PREPARES = false),
        // shuning uchun :a, :b va :c alohida nomlangan.
        $st = $pdo->prepare(
            'SELECT r.id, r.status, r.served_by, r.returned_by, r.rejected_by, r.rejected_at, r.is_offline,
                    r.created_at, r.picked_up_at, r.due_date, r.returned_at,
                    u.name AS student, u.hemis_id, u.faculty, u.group_name,
                    b.title AS book,
                    returner.name AS returner_name
             FROM reservations r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             LEFT JOIN users returner ON returner.id = r.returned_by
             WHERE r.served_by = :a OR r.returned_by = :b OR r.rejected_by = :c
             ORDER BY r.id DESC
             LIMIT 500'
        );
        $st->execute([':a' => $staffId, ':b' => $staffId, ':c' => $staffId]);

        $now   = time();
        $items = [];

        foreach ($st->fetchAll() as $row) {
            // Talaba va kitob haqidagi umumiy ma'lumot (hamma turdagi yozuvda bir xil)
            $base = [
                'student' => (string) $row['student'],
                'hemis'   => (string) ($row['hemis_id'] ?? ''),
                'faculty' => (string) ($row['faculty'] ?? ''),
                'group'   => (string) ($row['group_name'] ?? ''),
                'book'    => (string) $row['book'],
            ];
            $status = (string) $row['status'];

            // ---- 1) Xodim kitobni bergan / tasdiqlagan ----
            if ((int) $row['served_by'] === $staffId) {
                $when = (string) ($row['picked_up_at'] ?: $row['created_at']);

                // Kitobning hozirgi holati: qaytarilgan / kechikkan / talabada
                $state = '';        // done | late | holding
                $note  = '';        // qo'shimcha izoh matni

                if ($status === 'qaytarildi') {
                    $state = 'done';
                    $note  = 'Qaytarilgan: ' . staffDate((string) ($row['returned_at'] ?? ''));
                    if (!empty($row['returner_name'])) {
                        $note .= ' · Qabul qilgan: ' . $row['returner_name'];
                    }
                } elseif (in_array($status, ['tasdiqlandi', 'berilgan'], true)) {
                    $dueTs = !empty($row['due_date']) ? strtotime((string) $row['due_date']) : 0;

                    if ($dueTs > 0 && $dueTs < $now) {
                        $state = 'late';
                        $note  = 'Muddati: ' . staffDate((string) $row['due_date'])
                               . ' · ' . lateText($now - $dueTs) . ' kechikkan';
                    } else {
                        $state = 'holding';
                        $note  = $dueTs > 0
                            ? 'Qaytarish muddati: ' . staffDate((string) $row['due_date'])
                            : 'Hozir talabada';
                    }
                }

                $items[] = $base + [
                    'role'    => (int) $row['is_offline'] === 1 ? 'Offlayn bergan' : 'Tasdiqlagan',
                    'kind'    => 'given',
                    'state'   => $state,
                    'note'    => $note,
                    'atLabel' => 'Berilgan: ',
                    'at'      => staffDate($when),
                    'ts'      => (int) strtotime($when),
                ];
            }

            // ---- 2) Xodim kitobni qaytarib olgan ----
            if ((int) $row['returned_by'] === $staffId) {
                $when = (string) ($row['returned_at'] ?: $row['created_at']);
                $items[] = $base + [
                    'role'    => 'Qabul qilgan',
                    'kind'    => 'returned',
                    'state'   => '',
                    'note'    => '',
                    'atLabel' => 'Qabul qilingan: ',
                    'at'      => staffDate($when),
                    'ts'      => (int) strtotime($when),
                ];
            }

            // ---- 3) Xodim band qilishni rad etgan ----
            // Yangi yozuvlarda aniq rad etilgan vaqt (rejected_at) bor.
            // 012-migratsiyadan oldingi eski yozuvlarda u yo'q: band qilingan vaqt ko'rsatiladi.
            if ((int) $row['rejected_by'] === $staffId) {
                if (!empty($row['rejected_at'])) {
                    $when    = (string) $row['rejected_at'];
                    $atLabel = 'Rad etilgan: ';
                    $note    = 'Band qilingan: ' . staffDate((string) $row['created_at']);
                } else {
                    $when    = (string) $row['created_at'];
                    $atLabel = 'Band qilingan: ';
                    $note    = 'Aniq rad etilgan vaqt saqlanmagan';
                }

                $items[] = $base + [
                    'role'    => 'Rad etgan',
                    'kind'    => 'rejected',
                    'state'   => '',
                    'note'    => $note,
                    'atLabel' => $atLabel,
                    'at'      => staffDate($when),
                    'ts'      => (int) strtotime($when),
                ];
            }
        }

        // Eng yangisi tepada
        usort($items, static fn(array $x, array $y): int => $y['ts'] <=> $x['ts']);

        echo json_encode([
            'name'  => (string) $staff['name'],
            'email' => (string) $staff['email'],
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Xodim tafsilotida xatolik: ' . $ex->getMessage());
        http_response_code(500);
        echo '{"error":"server"}';
    }
    exit;
}

// ------------------------------------------------------------
// 3. XODIM QO'SHISH OYNASI HOLATI
//    Oldingi urinishda xatolik bo'lgan bo'lsa, oyna ochiq holda, xatolar va
//    to'ldirilgan maydonlar bilan qaytadi (parol qaytarilmaydi).
//    Sahifa yangilanganda (refresh) bu ma'lumotlar o'chib ketadi.
// ------------------------------------------------------------
$addOld    = $_SESSION['staff_add_old'] ?? ['name' => '', 'email' => ''];
$addErrors = $_SESSION['staff_add_errors'] ?? [];
unset($_SESSION['staff_add_old'], $_SESSION['staff_add_errors']);
$showAdd = !empty($addErrors);

// ------------------------------------------------------------
// 4. XODIMLAR RO'YXATI (bergan / qaytarib olgan / rad etgan soni bilan)
// ------------------------------------------------------------
$staffList = [];
try {
    $staffList = $pdo->query(
        "SELECT u.id, u.name, u.email, u.is_blocked, u.blocked_at, u.created_at,
                (SELECT COUNT(*) FROM reservations r WHERE r.served_by   = u.id) AS given_cnt,
                (SELECT COUNT(*) FROM reservations r WHERE r.returned_by = u.id) AS returned_cnt,
                (SELECT COUNT(*) FROM reservations r WHERE r.rejected_by = u.id) AS rejected_cnt
         FROM users u
         WHERE u.role = 'admin'
         ORDER BY u.is_blocked ASC, u.name ASC, u.id ASC"
    )->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Xodimlar ro\'yxatida xatolik: ' . $ex->getMessage());
    setFlash('error', "Xodimlarni yuklashda xatolik yuz berdi. (011-migratsiya ishga tushirilganmi?)");
}

/** Holat belgisi: Faol (yashil) yoki Bloklangan (qizil) */
function staffStatusBadge(bool $blocked): string
{
    return $blocked
        ? '<span class="inline-block whitespace-nowrap text-xs px-2 py-0.5 rounded-full font-medium bg-red-100 text-red-700">🚫 Bloklangan</span>'
        : '<span class="inline-block whitespace-nowrap text-xs px-2 py-0.5 rounded-full font-medium bg-green-100 text-green-700">Faol</span>';
}

/**
 * Harakat tugmasi: faol xodimda qizil "Bloklash",
 * bloklangan xodimda sariq "Blokdan ochish". O'zingiz uchun – tugma yo'q.
 */
function renderStaffAction(array $s, int $meId, bool $full): void
{
    if ((int) $s['id'] === $meId) {
        echo '<span class="text-xs text-slate-400">Siz</span>';
        return;
    }

    $blocked = (int) $s['is_blocked'] === 1;
    $action  = $blocked ? 'unblock' : 'block';
    $label   = $blocked ? 'Blokdan ochish' : 'Bloklash';
    $color   = $blocked ? 'bg-amber-500 hover:bg-amber-600' : 'bg-red-600 hover:bg-red-700';
    $size    = $full ? 'w-full text-sm py-2' : 'text-xs px-3 py-1.5 whitespace-nowrap';

    // Bloklashdan oldin tasdiq so'raladi (matn json_encode bilan xavfsiz qo'yiladi)
    $confirmText = '«' . $s['name'] . '» bloklansinmi? U tizimga kira olmaydi.';
    $onsubmit    = $blocked
        ? ''
        : ' onsubmit="' . e('return confirm(' . json_encode($confirmText, JSON_UNESCAPED_UNICODE) . ');') . '"';
    ?>
    <form method="post" action="<?= e(url('admin/staff.php')) ?>"<?= $onsubmit ?>>
        <?= csrfField() ?>
        <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
        <input type="hidden" name="action" value="<?= e($action) ?>">
        <button type="submit" class="<?= e($color . ' ' . $size) ?> text-white rounded-md"><?= e($label) ?></button>
    </form>
    <?php
}

// ------------------------------------------------------------
// 5. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Xodimlar';
require_once __DIR__ . '/../includes/header.php';

// Forma maydonlari uchun umumiy Tailwind sinflari
$addInput = 'w-full border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-300';
// Parol maydonlari: o'ng tomonda "Ko'rsatish" tugmasiga joy qoldiriladi
$passInput = $addInput . ' pr-28';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-2">
    <h1 class="text-2xl font-bold">Xodimlar (kutubxonachilar)</h1>
    <div class="flex items-center gap-3">
        <!-- Yangi xodim qo'shish OYNASINI ochadi -->
        <button type="button" id="staffAddToggle" aria-haspopup="dialog" aria-controls="staffAddModal"
                class="bg-green-600 hover:bg-green-700 text-white font-semibold rounded-md px-4 py-2 text-sm">
            + Xodim qo'shish
        </button>
        <a href="<?= e(url('admin/index.php')) ?>" class="text-blue-600 hover:underline text-sm">← Band qilishlarga qaytish</a>
    </div>
</div>

<p class="text-slate-500 text-sm mb-4">
    Kasal bo'lgan xodimni vaqtincha <b>bloklang</b> – u qaytguncha tizimga kira olmaydi.
    Ishdan ketganlarni blokdan ochish shart emas. Xodim ismini bosing – kimlarga xizmat ko'rsatgani
    (bergan, qaytarib olgan, rad etgan va kechikayotgan kitoblar) chiqadi.
</p>

<?php if (!$staffList): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        Hozircha xodimlar yo'q.
    </div>
<?php else: ?>

    <!-- Kompyuter (768px dan keng): jadval -->
    <div class="hidden md:block bg-white border border-slate-200 rounded-xl overflow-hidden">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-4 py-3 font-semibold">№</th>
                    <th class="px-4 py-3 font-semibold">Xodim</th>
                    <th class="px-4 py-3 font-semibold text-center">Bergan kitoblar</th>
                    <th class="px-4 py-3 font-semibold text-center">Qaytarib olgan</th>
                    <th class="px-4 py-3 font-semibold text-center">Rad etgan</th>
                    <th class="px-4 py-3 font-semibold">Holati</th>
                    <th class="px-4 py-3 font-semibold text-right">Harakatlar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staffList as $i => $s): ?>
                    <?php $blocked = (int) $s['is_blocked'] === 1; ?>
                    <tr class="border-t border-slate-200 align-middle <?= $blocked ? 'bg-slate-50' : '' ?>">
                        <td class="px-4 py-3 text-slate-500"><?= $i + 1 ?></td>
                        <td class="px-4 py-3">
                            <!-- Ism bosilganda: kimlarga xizmat ko'rsatgani oynada chiqadi -->
                            <button type="button" data-staff-open="<?= (int) $s['id'] ?>"
                                    class="font-semibold text-blue-600 hover:underline text-left break-words">
                                <?= e($s['name']) ?>
                            </button>
                            <div class="text-xs text-slate-500 break-all"><?= e($s['email']) ?></div>
                            <?php if ($blocked && !empty($s['blocked_at'])): ?>
                                <div class="text-xs text-red-600">Bloklangan: <?= e(staffDate((string) $s['blocked_at'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded bg-green-100 text-green-700 font-semibold"><?= (int) $s['given_cnt'] ?> ta</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded bg-slate-200 text-slate-700 font-semibold"><?= (int) $s['returned_cnt'] ?> ta</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="px-2 py-0.5 rounded bg-red-100 text-red-700 font-semibold"><?= (int) $s['rejected_cnt'] ?> ta</span>
                        </td>
                        <td class="px-4 py-3"><?= staffStatusBadge($blocked) ?></td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end"><?php renderStaffAction($s, $meId, false); ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Telefon (768px dan tor): kartochkalar -->
    <div class="md:hidden space-y-3">
        <?php foreach ($staffList as $s): ?>
            <?php $blocked = (int) $s['is_blocked'] === 1; ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4 <?= $blocked ? 'bg-slate-50' : '' ?>">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <button type="button" data-staff-open="<?= (int) $s['id'] ?>"
                                class="font-bold text-blue-600 hover:underline text-left break-words">
                            <?= e($s['name']) ?>
                        </button>
                        <div class="text-xs text-slate-500 break-all"><?= e($s['email']) ?></div>
                    </div>
                    <div class="shrink-0"><?= staffStatusBadge($blocked) ?></div>
                </div>

                <dl class="mt-3 grid grid-cols-3 gap-3 text-xs">
                    <div><dt class="text-slate-500">Bergan</dt><dd class="font-semibold text-green-700"><?= (int) $s['given_cnt'] ?> ta</dd></div>
                    <div><dt class="text-slate-500">Qaytarib olgan</dt><dd class="font-semibold"><?= (int) $s['returned_cnt'] ?> ta</dd></div>
                    <div><dt class="text-slate-500">Rad etgan</dt><dd class="font-semibold text-red-700"><?= (int) $s['rejected_cnt'] ?> ta</dd></div>
                </dl>

                <div class="mt-3"><?php renderStaffAction($s, $meId, true); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- ====== YANGI XODIM QO'SHISH MODALI ======
     Tugma bosilganda ochiladi. Xatolik bo'lsa (server) sahifa shu oyna ochiq holda qaytadi.
     "inert" – yopiq paytda ichidagi maydonlarga Tab bilan o'tib bo'lmaydi. -->
<div id="staffAddModal" role="dialog" aria-modal="true" aria-labelledby="staffAddTitle"
     <?= $showAdd ? '' : 'inert' ?>
     class="fixed inset-0 z-[65] flex items-center justify-center p-4 bg-black/50 transition-opacity duration-200
            <?= $showAdd ? 'opacity-100' : 'opacity-0 pointer-events-none' ?>">
    <div class="relative w-full max-w-lg max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl p-5">

        <button type="button" id="staffAddClose" aria-label="Oynani yopish"
                class="absolute top-2 right-2 rounded-full bg-white hover:bg-slate-100 shadow w-9 h-9 text-lg text-slate-600">✕</button>

        <h2 id="staffAddTitle" class="text-xl font-bold text-green-800 pr-10">Yangi xodim (kutubxonachi) qo'shish</h2>
        <p class="text-xs text-slate-500 mt-1">
            Xodim shu email va parol bilan tizimga kiradi. Parolni xodimga o'zingiz yetkazasiz.
        </p>

        <!-- Xatoliklar: server yoki brauzer (JS) yozadi, faqat textContent orqali -->
        <div id="staffAddErrors" role="alert"
             class="<?= $addErrors ? '' : 'hidden' ?> mt-3 border border-red-300 bg-red-50 text-red-800 rounded-md px-4 py-3 text-sm">
            <ul class="list-disc pl-5 space-y-1">
                <?php foreach ($addErrors as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <form method="post" action="<?= e(url('admin/staff.php')) ?>" id="staffAddForm"
              autocomplete="off" novalidate class="mt-3 space-y-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">

            <div>
                <label for="add_name" class="block text-sm font-semibold mb-1">To'liq ism (FIO)</label>
                <input type="text" id="add_name" name="name" required maxlength="100"
                       value="<?= e($addOld['name']) ?>" class="<?= e($addInput) ?>">
            </div>

            <div>
                <label for="add_email" class="block text-sm font-semibold mb-1">Email (kirish uchun login)</label>
                <input type="email" id="add_email" name="email" required maxlength="150"
                       value="<?= e($addOld['email']) ?>" class="<?= e($addInput) ?>">
            </div>

            <!-- Parol: o'ng tomondagi tugma yozilgan parolni ko'rsatadi / yashiradi -->
            <div>
                <label for="add_pass" class="block text-sm font-semibold mb-1">Parol (kamida 6 belgi)</label>
                <div class="relative">
                    <input type="password" id="add_pass" name="password" required minlength="6" maxlength="72"
                           autocomplete="new-password" class="<?= e($passInput) ?>">
                    <button type="button" data-pass-toggle="add_pass" aria-pressed="false"
                            aria-label="Parolni ko'rsatish"
                            class="absolute inset-y-0 right-0 px-3 text-xs text-slate-600 hover:text-slate-900 whitespace-nowrap">
                        👁 Ko'rsatish
                    </button>
                </div>
            </div>

            <div>
                <label for="add_pass2" class="block text-sm font-semibold mb-1">Parolni takrorlang</label>
                <div class="relative">
                    <input type="password" id="add_pass2" name="password_confirm" required minlength="6" maxlength="72"
                           autocomplete="new-password" class="<?= e($passInput) ?>">
                    <button type="button" data-pass-toggle="add_pass2" aria-pressed="false"
                            aria-label="Takroriy parolni ko'rsatish"
                            class="absolute inset-y-0 right-0 px-3 text-xs text-slate-600 hover:text-slate-900 whitespace-nowrap">
                        👁 Ko'rsatish
                    </button>
                </div>
            </div>

            <div class="flex gap-2 pt-1">
                <button type="submit" id="staffAddSubmit"
                        class="bg-green-600 hover:bg-green-700 disabled:bg-slate-400 text-white font-semibold rounded-md px-5 py-2 text-sm">
                    Qo'shish
                </button>
                <button type="button" id="staffAddCancel"
                        class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-sm">
                    Bekor qilish
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ====== XODIM TAFSILOTI MODALI ======
     Ichini pastdagi JS to'ldiradi (faqat textContent – XSS bo'lmaydi). -->
<div id="staffModal" role="dialog" aria-modal="true" aria-labelledby="staffModalTitle" inert
     class="fixed inset-0 z-[65] flex items-center justify-center p-4 bg-black/50
            opacity-0 pointer-events-none transition-opacity duration-200">
    <div class="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl p-5">

        <button type="button" id="staffModalClose" aria-label="Oynani yopish"
                class="absolute top-2 right-2 rounded-full bg-white hover:bg-slate-100 shadow w-9 h-9 text-lg text-slate-600">✕</button>

        <h2 id="staffModalTitle" class="text-xl font-bold pr-10 break-words"></h2>
        <p id="staffModalEmail" class="text-xs text-slate-500 break-all"></p>

        <!-- Filtr tugmalari (sonlarini JS yozadi) -->
        <div id="staffModalFilters" class="hidden mt-3 flex flex-wrap gap-2">
            <button type="button" data-staff-filter="all"      data-label="Hammasi"></button>
            <button type="button" data-staff-filter="given"    data-label="Bergan"></button>
            <button type="button" data-staff-filter="returned" data-label="Qaytarib olgan"></button>
            <button type="button" data-staff-filter="rejected" data-label="Rad etgan"></button>
            <button type="button" data-staff-filter="holding"  data-label="Hozir qo'lida"></button>
            <button type="button" data-staff-filter="late"     data-label="Kechikkan"></button>
        </div>

        <!-- Yuklanmoqda / xato / bo'sh xabari -->
        <p id="staffModalMsg" class="hidden mt-4 text-sm text-slate-500"></p>

        <!-- Yozuvlar ro'yxati -->
        <div id="staffModalList" class="mt-4 space-y-2"></div>
    </div>
</div>

<!-- ====== XODIM QO'SHISH MODALI (Vanilla JS) ======
     - tugma bosilganda ochiladi; Esc, ✕, "Bekor qilish" yopadi
     - qoraytirilgan fonga bosilganda faqat maydonlar BO'SH bo'lsa yopiladi
       (tasodifan bosib, yozganlarni yo'qotib qo'ymaslik uchun)
     - parol "Ko'rsatish / Yashirish" tugmalari
     - yuborishdan oldin parollar mosligi brauzerda tekshiriladi
     - oyna yopilganda parollar tozalanadi va yana yashiriladi
     - Tab oyna ichida aylanadi -->
<script>
(function () {
    'use strict';

    var modal     = document.getElementById('staffAddModal');
    var openBtn   = document.getElementById('staffAddToggle');
    var closeBtn  = document.getElementById('staffAddClose');
    var cancelBtn = document.getElementById('staffAddCancel');
    var form      = document.getElementById('staffAddForm');
    var errBox    = document.getElementById('staffAddErrors');
    var submitBtn = document.getElementById('staffAddSubmit');
    if (!modal || !openBtn || !closeBtn || !form || !errBox) { return; }

    var nameEl  = document.getElementById('add_name');
    var emailEl = document.getElementById('add_email');
    var passEl  = document.getElementById('add_pass');
    var pass2El = document.getElementById('add_pass2');

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    var lastFocus = null;

    function isOpen() { return !modal.classList.contains('pointer-events-none'); }

    /** Xatolar ro'yxatini chizadi (matn faqat textContent orqali). Bo'sh bo'lsa – yashiradi. */
    function showErrors(messages) {
        errBox.textContent = '';
        if (!messages.length) {
            errBox.classList.add('hidden');
            return;
        }
        var ul = document.createElement('ul');
        ul.className = 'list-disc pl-5 space-y-1';
        messages.forEach(function (text) {
            var li = document.createElement('li');
            li.textContent = text;
            ul.appendChild(li);
        });
        errBox.appendChild(ul);
        errBox.classList.remove('hidden');
    }

    /** Parol maydonini yana yashirin holatga qaytaradi */
    function hidePassword(input) {
        input.type = 'password';
        var btn = form.querySelector('[data-pass-toggle="' + input.id + '"]');
        if (btn) {
            btn.textContent = '👁 Ko\'rsatish';
            btn.setAttribute('aria-pressed', 'false');
            btn.setAttribute('aria-label', 'Parolni ko\'rsatish');
        }
    }

    function openModal() {
        lastFocus = document.activeElement;
        modal.inert = false;
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');
        modal.scrollTop = 0;
        if (nameEl) { nameEl.focus({ preventScroll: true }); }
    }

    function closeModal() {
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        document.body.classList.remove('overflow-hidden');
        modal.inert = true;

        // Xavfsizlik: parollar xotirada qolmasin va yashirin holatga qaytsin
        passEl.value = '';
        pass2El.value = '';
        hidePassword(passEl);
        hidePassword(pass2El);
        showErrors([]);
        if (submitBtn) { submitBtn.disabled = false; }

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    /** Hech narsa yozilmaganmi? (fonga bosib yopish uchun ruxsat) */
    function isFormEmpty() {
        return !(nameEl.value.trim() || emailEl.value.trim() || passEl.value || pass2El.value);
    }

    openBtn.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) { cancelBtn.addEventListener('click', closeModal); }

    modal.addEventListener('click', function (event) {
        if (event.target === modal && isFormEmpty()) { closeModal(); }
    });

    // Parolni ko'rsatish / yashirish (hodisa delegatsiyasi)
    form.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-pass-toggle]');
        if (!btn) { return; }

        var input = document.getElementById(btn.getAttribute('data-pass-toggle'));
        if (!input) { return; }

        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? '🙈 Yashirish' : '👁 Ko\'rsatish';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Parolni yashirish' : 'Parolni ko\'rsatish');
        input.focus({ preventScroll: true });
    });

    // Yuborishdan oldin brauzerdagi tekshiruv (server baribir qayta tekshiradi)
    form.addEventListener('submit', function (event) {
        var errors = [];
        var nameLen = nameEl.value.trim().length;

        if (nameLen < 3 || nameLen > 100) {
            errors.push('Xodim FIO si 3 tadan 100 tagacha belgidan iborat bo\'lsin.');
        }
        if (!emailEl.value.trim() || !emailEl.checkValidity()) {
            errors.push('Email manzili noto\'g\'ri kiritilgan.');
        }
        if (passEl.value.length < 6 || passEl.value.length > 72) {
            errors.push('Parol 6 tadan 72 tagacha belgidan iborat bo\'lsin.');
        }
        if (passEl.value !== pass2El.value) {
            errors.push('Parol va uning takrori bir xil emas.');
        }

        if (errors.length) {
            event.preventDefault();
            showErrors(errors);
            return;
        }

        // Ikki marta bosib, ikki marta yuborilmasligi uchun
        if (submitBtn) { submitBtn.disabled = true; }
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
            var focusable = Array.prototype.filter.call(modal.querySelectorAll(FOCUSABLE), function (n) {
                return n.offsetParent !== null;
            });
            if (!focusable.length) { return; }

            var first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first.focus();
            }
        }
    });

    // Server xatolik bilan oynani ochiq qaytargan bo'lsa: sahifa aylanmasin va fokus maydonga o'tsin
    if (isOpen()) {
        document.body.classList.add('overflow-hidden');
        // Birinchi to'ldirilmagan maydonga fokus
        var target = !nameEl.value ? nameEl : (!emailEl.value ? emailEl : passEl);
        target.focus({ preventScroll: true });
    }

    // "Orqaga" tugmasi bilan qaytilganda ochiq oyna qolib ketmasin
    window.addEventListener('pageshow', function (event) {
        if (event.persisted && isOpen()) { closeModal(); }
    });
})();
</script>

<!-- ====== XODIM TAFSILOTI MODALI (Vanilla JS) ======
     Ism bosilganda ?detail=ID dan JSON olinadi va ro'yxat chiziladi.
     Filtr tugmalari ro'yxatni mijoz tomonda saralaydi.
     Esc, orqa fon yoki ✕ yopadi; Tab oyna ichida aylanadi. -->
<script>
(function () {
    'use strict';

    var modal    = document.getElementById('staffModal');
    var closeBtn = document.getElementById('staffModalClose');
    if (!modal || !closeBtn) { return; }

    var titleEl   = document.getElementById('staffModalTitle');
    var emailEl   = document.getElementById('staffModalEmail');
    var filtersEl = document.getElementById('staffModalFilters');
    var msgEl     = document.getElementById('staffModalMsg');
    var listEl    = document.getElementById('staffModalList');

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    var DETAIL_URL = <?= json_encode(url('admin/staff.php'), JSON_UNESCAPED_SLASHES) ?>;

    // Filtr tugmalarining faol / oddiy ko'rinishi (Tailwind sinflari)
    var CHIP_BASE   = 'px-3 py-1 rounded-full text-xs border ';
    var CHIP_ACTIVE = CHIP_BASE + 'bg-blue-600 text-white border-blue-600';
    var CHIP_NORMAL = CHIP_BASE + 'bg-white border-slate-200 hover:border-blue-600';
    var CHIP_LATE   = CHIP_BASE + 'bg-red-50 text-red-700 border-red-300 hover:border-red-600';

    var lastFocus  = null;
    var controller = null;      // eski so'rovni bekor qilish uchun
    var items      = [];        // serverdan kelgan yozuvlar
    var filter     = 'all';     // hozirgi tanlangan filtr

    function isOpen() { return !modal.classList.contains('pointer-events-none'); }

    /** Element yaratadi (matn faqat textContent orqali) */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function showMessage(text) {
        msgEl.textContent = text;
        msgEl.classList.remove('hidden');
    }

    function hideMessage() {
        msgEl.classList.add('hidden');
    }

    /** Yozuv tanlangan filtrga mos keladimi? */
    function matches(it, f) {
        switch (f) {
            case 'given':    return it.kind === 'given';
            case 'returned': return it.kind === 'returned';
            case 'rejected': return it.kind === 'rejected';
            case 'holding':  return it.kind === 'given' && (it.state === 'holding' || it.state === 'late');
            case 'late':     return it.kind === 'given' && it.state === 'late';
            default:         return true;
        }
    }

    /** Filtr tugmalaridagi sonlarni yangilaydi va faolini belgilaydi */
    function renderFilters() {
        Array.prototype.forEach.call(filtersEl.querySelectorAll('[data-staff-filter]'), function (btn) {
            var f     = btn.getAttribute('data-staff-filter');
            var count = items.filter(function (it) { return matches(it, f); }).length;

            btn.textContent = btn.getAttribute('data-label') + ' (' + count + ')';
            btn.className   = (f === filter) ? CHIP_ACTIVE : CHIP_NORMAL;

            // Kechikkan bor bo'lsa, tugma qizil rangda ajralib turadi
            if (f === 'late' && count > 0 && f !== filter) {
                btn.className = CHIP_LATE;
            }
        });
    }

    /** Kitob holati belgisi (faqat "bergan" yozuvlar uchun) */
    function stateBadge(state) {
        if (state === 'late')    { return el('span', 'shrink-0 text-xs px-2 py-0.5 rounded-full font-semibold bg-red-100 text-red-700', '⏰ Kechikkan'); }
        if (state === 'holding') { return el('span', 'shrink-0 text-xs px-2 py-0.5 rounded-full font-medium bg-amber-100 text-amber-800', '📖 Talabada'); }
        if (state === 'done')    { return el('span', 'shrink-0 text-xs px-2 py-0.5 rounded-full font-medium bg-blue-100 text-blue-700', '✔ Qaytarilgan'); }
        return null;
    }

    /** Rol belgisining ranglari */
    function roleClass(kind) {
        if (kind === 'given')    { return 'bg-green-100 text-green-700'; }
        if (kind === 'rejected') { return 'bg-red-100 text-red-700'; }
        return 'bg-slate-200 text-slate-700';
    }

    /** Tanlangan filtr bo'yicha ro'yxatni chizadi */
    function renderList() {
        listEl.textContent = '';

        var shown = items.filter(function (it) { return matches(it, filter); });

        if (!shown.length) {
            showMessage(items.length
                ? 'Bu filtr bo\'yicha yozuv yo\'q.'
                : 'Bu xodim hali hech kimga xizmat ko\'rsatmagan.');
            return;
        }
        hideMessage();

        shown.forEach(function (it) {
            // Kechikkan kitob kartochkasi qizil ramka bilan ajralib turadi
            var border = it.state === 'late' ? 'border-red-300 bg-red-50/40' : 'border-slate-200';
            var card   = el('div', 'border ' + border + ' rounded-lg p-3 text-sm');

            // Yuqori qator: talaba FIO + belgilar (rol va kitob holati)
            var top    = el('div', 'flex items-start justify-between gap-2');
            top.appendChild(el('div', 'font-bold break-words min-w-0', it.student));

            var badges = el('div', 'shrink-0 flex flex-col items-end gap-1');
            badges.appendChild(el('span', 'text-xs px-2 py-0.5 rounded-full font-medium ' + roleClass(it.kind), it.role));
            var sb = stateBadge(it.state);
            if (sb) { badges.appendChild(sb); }
            top.appendChild(badges);
            card.appendChild(top);

            // Talaba ma'lumotlari
            var info = [];
            if (it.hemis)   { info.push('HEMIS: ' + it.hemis); }
            if (it.group)   { info.push('Guruh: ' + it.group); }
            if (it.faculty) { info.push(it.faculty); }
            if (info.length) { card.appendChild(el('div', 'text-xs text-slate-500 mt-0.5 break-words', info.join(' · '))); }

            // Kitob
            card.appendChild(el('div', 'mt-1 text-slate-700 break-words', '📖 ' + it.book));

            // Vaqt qatori: qaysi amal vaqti ekanini yorliq bilan ko'rsatamiz (yorliqni server beradi)
            card.appendChild(el('div', 'text-xs text-slate-500 mt-0.5', (it.atLabel || '') + it.at));

            // Qo'shimcha izoh: muddat, kechikish, kim qabul qilgani va hokazo
            if (it.note) {
                var noteCls = it.state === 'late' ? 'text-red-700 font-semibold' : 'text-slate-600';
                card.appendChild(el('div', 'text-xs mt-0.5 ' + noteCls, it.note));
            }

            listEl.appendChild(card);
        });
    }

    /** Server javobi kelgach: sarlavha, filtrlar va ro'yxatni chizadi */
    function render(data) {
        titleEl.textContent = data.name;
        emailEl.textContent = data.email;
        items  = data.items || [];
        filter = 'all';

        filtersEl.classList.toggle('hidden', !items.length);
        renderFilters();
        renderList();
    }

    function loadDetail(id) {
        if (controller) { controller.abort(); }
        controller = new AbortController();

        // Tozalab, "yuklanmoqda" ko'rsatamiz
        titleEl.textContent = '';
        emailEl.textContent = '';
        listEl.textContent = '';
        items = [];
        filtersEl.classList.add('hidden');
        showMessage('Yuklanmoqda…');

        fetch(DETAIL_URL + '?detail=' + encodeURIComponent(id), {
            signal: controller.signal,
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) {
                hideMessage();
                render(data);
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') { showMessage('Ma\'lumotni yuklab bo\'lmadi. Qayta urinib ko\'ring.'); }
            });
    }

    function openModal(id) {
        lastFocus = document.activeElement;
        modal.inert = false;
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');
        modal.scrollTop = 0;
        closeBtn.focus({ preventScroll: true });
        loadDetail(id);
    }

    function closeModal() {
        if (controller) { controller.abort(); }
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        document.body.classList.remove('overflow-hidden');
        modal.inert = true;
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    // Xodim ismi bosilganda (hodisa delegatsiyasi)
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-staff-open]');
        if (trigger) {
            event.preventDefault();
            openModal(trigger.getAttribute('data-staff-open'));
        }
    });

    // Filtr tugmasi bosilganda ro'yxat qayta chiziladi
    filtersEl.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-staff-filter]');
        if (!btn) { return; }
        filter = btn.getAttribute('data-staff-filter');
        renderFilters();
        renderList();
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
            var focusable = Array.prototype.filter.call(modal.querySelectorAll(FOCUSABLE), function (n) {
                return n.offsetParent !== null;
            });
            if (!focusable.length) { return; }

            var first = focusable[0], last = focusable[focusable.length - 1];
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
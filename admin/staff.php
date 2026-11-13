<?php
/**
 * ============================================================
 *  Fayl: admin/staff.php – Xodimlar (kutubxonachilar) boshqaruvi
 *
 *  Imkoniyatlar:
 *    - xodimlar ro'yxati: bergan kitoblar soni va qaytarib olgan kitoblar soni
 *    - "Bloklash" (qizil) ↔ "Blokdan ochish" (sariq) tugmasi.
 *      Kasal bo'lgan xodim vaqtincha bloklanadi: tizimga kira olmaydi.
 *      Ishdan ketganlarni blokdan ochish shart emas.
 *    - xodim ismi bosilganda: kimlarga xizmat ko'rsatgani (kitob berilgan va
 *      qabul qilingan talabalar) oynada chiqadi (JSON: ?detail=ID)
 *
 *  O'chirish va parolni yangilash tugmalari YO'Q (so'rov bo'yicha olib tashlangan).
 *  Eslatma: avval migrations/011_xodim_bloklash.sql ni ishga tushiring.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

// Faqat kutubxonachi kira oladi
requireAdmin();

$meId = (int) ($_SESSION['user_id'] ?? 0);

/** Sana-vaqtni "30.10.2026 13:05" ko'rinishida chiqaradi */
function staffDate(?string $value): string
{
    return ($value === null || $value === '') ? '' : date('d.m.Y H:i', strtotime($value));
}

// ------------------------------------------------------------
// 1. BLOKLASH / BLOKDAN OCHISH (POST + CSRF)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
        redirect('admin/staff.php');
    }

    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    $action   = (string) ($_POST['action'] ?? '');

    if ($targetId === false || $targetId < 1 || !in_array($action, ['block', 'unblock'], true)) {
        setFlash('error', "Noto'g'ri so'rov.");
        redirect('admin/staff.php');
    }

    // Kutubxonachi o'zini bloklay olmaydi (tizimdan chiqib qolmasligi uchun)
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
//    Xodim kimlarga kitob bergan va kimlardan qabul qilgan.
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

        // Har bir nomli parametr faqat BIR marta (ATTR_EMULATE_PREPARES = false)
        $st = $pdo->prepare(
            'SELECT r.served_by, r.returned_by, r.is_offline, r.created_at, r.picked_up_at, r.returned_at,
                    u.name AS student, u.hemis_id, u.faculty, u.group_name, b.title AS book
             FROM reservations r
             JOIN users u ON u.id = r.user_id
             JOIN books b ON b.id = r.book_id
             WHERE r.served_by = :a OR r.returned_by = :b
             ORDER BY r.id DESC
             LIMIT 300'
        );
        $st->execute([':a' => $staffId, ':b' => $staffId]);

        $items = [];
        foreach ($st->fetchAll() as $row) {
            $base = [
                'student' => (string) $row['student'],
                'hemis'   => (string) ($row['hemis_id'] ?? ''),
                'faculty' => (string) ($row['faculty'] ?? ''),
                'group'   => (string) ($row['group_name'] ?? ''),
                'book'    => (string) $row['book'],
            ];

            // Xodim kitobni bergan / tasdiqlagan bo'lsa
            if ((int) $row['served_by'] === $staffId) {
                $when = (string) ($row['picked_up_at'] ?: $row['created_at']);
                $items[] = $base + [
                    'role' => (int) $row['is_offline'] === 1 ? 'Offlayn bergan' : 'Tasdiqlagan',
                    'kind' => 'given',
                    'at'   => staffDate($when),
                    'ts'   => strtotime($when),
                ];
            }
            // Xodim kitobni qaytarib olgan bo'lsa
            if ((int) $row['returned_by'] === $staffId) {
                $when = (string) ($row['returned_at'] ?: $row['created_at']);
                $items[] = $base + [
                    'role' => 'Qabul qilgan',
                    'kind' => 'returned',
                    'at'   => staffDate($when),
                    'ts'   => strtotime($when),
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
// 3. XODIMLAR RO'YXATI (bergan / qaytarib olgan kitoblar soni bilan)
// ------------------------------------------------------------
$staffList = [];
try {
    $staffList = $pdo->query(
        "SELECT u.id, u.name, u.email, u.is_blocked, u.blocked_at, u.created_at,
                (SELECT COUNT(*) FROM reservations r WHERE r.served_by   = u.id) AS given_cnt,
                (SELECT COUNT(*) FROM reservations r WHERE r.returned_by = u.id) AS returned_cnt
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

    // Bloklashdan oldin tasdiq so'raladi
    $onsubmit = $blocked ? '' : ' onsubmit="' . e('return confirm("«' . $s['name'] . '» bloklansinmi? U tizimga kira olmaydi.");') . '"';
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
// 4. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Xodimlar';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-2">
    <h1 class="text-2xl font-bold">Xodimlar (kutubxonachilar)</h1>
    <a href="<?= e(url('admin/index.php')) ?>" class="text-blue-600 hover:underline text-sm">← Band qilishlarga qaytish</a>
</div>
<p class="text-slate-500 text-sm mb-4">
    Kasal bo'lgan xodimni vaqtincha <b>bloklang</b> – u qaytguncha tizimga kira olmaydi.
    Ishdan ketganlarni blokdan ochish shart emas. Xodim ismini bosing – kimlarga xizmat ko'rsatgani chiqadi.
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

                <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
                    <div><dt class="text-slate-500">Bergan kitoblar</dt><dd class="font-semibold text-green-700"><?= (int) $s['given_cnt'] ?> ta</dd></div>
                    <div><dt class="text-slate-500">Qaytarib olgan</dt><dd class="font-semibold"><?= (int) $s['returned_cnt'] ?> ta</dd></div>
                </dl>

                <div class="mt-3"><?php renderStaffAction($s, $meId, true); ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

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

        <p id="staffModalSummary" class="mt-3 text-sm text-slate-700"></p>

        <!-- Yuklanmoqda / xato xabari -->
        <p id="staffModalMsg" class="hidden mt-4 text-sm text-slate-500"></p>

        <!-- Xizmat ko'rsatilgan talabalar ro'yxati -->
        <div id="staffModalList" class="mt-4 space-y-2"></div>
    </div>
</div>

<!-- ====== XODIM MODALI (Vanilla JS) ======
     Ism bosilganda ?detail=ID dan JSON olinadi va ro'yxat chiziladi.
     Esc, orqa fon yoki ✕ yopadi; Tab oyna ichida aylanadi. -->
<script>
(function () {
    'use strict';

    var modal    = document.getElementById('staffModal');
    var closeBtn = document.getElementById('staffModalClose');
    if (!modal || !closeBtn) { return; }

    var titleEl   = document.getElementById('staffModalTitle');
    var emailEl   = document.getElementById('staffModalEmail');
    var summaryEl = document.getElementById('staffModalSummary');
    var msgEl     = document.getElementById('staffModalMsg');
    var listEl    = document.getElementById('staffModalList');

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    var DETAIL_URL = <?= json_encode(url('admin/staff.php'), JSON_UNESCAPED_SLASHES) ?>;

    var lastFocus  = null;
    var controller = null;   // eski so'rovni bekor qilish uchun

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

    /** Server javobidan ro'yxatni chizadi */
    function render(data) {
        titleEl.textContent = data.name;
        emailEl.textContent = data.email;

        var given = 0, returned = 0;
        data.items.forEach(function (it) { if (it.kind === 'given') { given++; } else { returned++; } });
        summaryEl.textContent = 'Bergan: ' + given + ' ta · Qaytarib olgan: ' + returned + ' ta';

        listEl.textContent = '';

        if (!data.items.length) {
            showMessage('Bu xodim hali hech kimga xizmat ko\'rsatmagan.');
            return;
        }

        data.items.forEach(function (it) {
            var card = el('div', 'border border-slate-200 rounded-lg p-3 text-sm');

            // Yuqori qator: talaba FIO + rol belgisi
            var top  = el('div', 'flex items-start justify-between gap-2');
            top.appendChild(el('div', 'font-bold break-words min-w-0', it.student));
            top.appendChild(el('span',
                'shrink-0 text-xs px-2 py-0.5 rounded-full font-medium ' +
                (it.kind === 'given' ? 'bg-green-100 text-green-700' : 'bg-slate-200 text-slate-700'),
                it.role));
            card.appendChild(top);

            // Talaba ma'lumotlari
            var info = [];
            if (it.hemis)   { info.push('HEMIS: ' + it.hemis); }
            if (it.group)   { info.push('Guruh: ' + it.group); }
            if (it.faculty) { info.push(it.faculty); }
            if (info.length) { card.appendChild(el('div', 'text-xs text-slate-500 mt-0.5 break-words', info.join(' · '))); }

            // Kitob va vaqt
            card.appendChild(el('div', 'mt-1 text-slate-700 break-words', '📖 ' + it.book));
            card.appendChild(el('div', 'text-xs text-slate-500 mt-0.5', it.at));

            listEl.appendChild(card);
        });
    }

    function loadDetail(id) {
        if (controller) { controller.abort(); }
        controller = new AbortController();

        // Tozalab, "yuklanmoqda" ko'rsatamiz
        titleEl.textContent = '';
        emailEl.textContent = '';
        summaryEl.textContent = '';
        listEl.textContent = '';
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
                msgEl.classList.add('hidden');
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
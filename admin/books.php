<?php
/**
 * ============================================================
 *  Fayl: admin/books.php – Kitoblarni boshqarish (zamonaviy ko'rinish)
 *  Funksional talab №7 (Texnik topshiriq, 4-band):
 *  kutubxonachi kitob qo'shadi, tahrirlaydi va o'chiradi.
 *  Qo'shish va tahrirlash – admin/book_form.php sahifasida.
 *
 *  Yangiliklar:
 *    - umumiy ko'rsatkichlar (kitob, nusxa, bo'sh, talabada, tugagan)
 *    - muqova rasmi bilan jadval; barcha ma'lumot ko'rinib turadi:
 *      nomi, muallif, kategoriya, soha, janr, tavsif, nusxalar, sana
 *    - har bir kitobning band / talabada / kechikkan sonlari
 *    - qidiruv (nomi, muallif), kategoriya, mavjudlik filtri va saralash
 *    - sahifalash (bir sahifada 15 ta kitob)
 *    - band yoki talabadagi kitobni o'chirish tugmasi o'chiq turadi
 *
 *  Eslatma: soha va janr ustunlari uchun migrations/007_field_genre.sql
 *  ishga tushirilgan bo'lishi kerak.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

requireAdmin();

// ------------------------------------------------------------
// 0. SOZLAMALAR VA YORDAMCHI FUNKSIYALAR
// ------------------------------------------------------------

// Bir sahifada nechta kitob chiqishi
const BOOKS_PER_PAGE = 15;

// Mavjudlik filtri: bor – bo'sh nusxa bor, yoq – hammasi band yoki talabada
const STOCK_FILTERS = ['bor' => "Bo'sh nusxasi bor", 'yoq' => "Bo'sh nusxasi yo'q"];

// Saralash variantlari: kalit => [yozuv, SQL tartib]. Faqat shu ro'yxatdagi qiymatlar qabul qilinadi.
const SORT_OPTIONS = [
    'new'   => ["Yangi qo'shilganlar", 'b.id DESC'],
    'title' => ['Nomi bo\'yicha (A–Z)', 'b.title ASC'],
    'low'   => ["Bo'sh nusxasi kam", 'b.available_copies ASC, b.title ASC'],
];

/** Filtrlarni xavfsiz oladi (GET yoki POST massividan) */
function readBookFilters(array $src): array
{
    $category = (string) ($src['category'] ?? '');
    if (!in_array($category, BOOK_CATEGORIES, true)) {
        $category = '';
    }

    $stock = (string) ($src['stock'] ?? '');
    if (!array_key_exists($stock, STOCK_FILTERS)) {
        $stock = '';
    }

    $sort = (string) ($src['sort'] ?? 'new');
    if (!array_key_exists($sort, SORT_OPTIONS)) {
        $sort = 'new';
    }

    return [
        'q'        => mb_substr(trim((string) ($src['q'] ?? '')), 0, 100),
        'category' => $category,
        'stock'    => $stock,
        'sort'     => $sort,
        'page'     => max(1, (int) ($src['page'] ?? 1)),
    ];
}

/** Filtrlardan sahifa manzilini yasaydi (standart qiymatlar manzildan tushib qoladi) */
function booksUrl(array $filters, array $override = []): string
{
    $f = $override + $filters;

    $query = [
        'q'        => $f['q'],
        'category' => $f['category'],
        'stock'    => $f['stock'],
        'sort'     => $f['sort'] === 'new' ? '' : $f['sort'],
        'page'     => (int) $f['page'] > 1 ? (string) (int) $f['page'] : '',
    ];
    $query = array_filter($query, static fn($v): bool => $v !== '');

    return 'admin/books.php' . ($query ? '?' . http_build_query($query) : '');
}

/** Qiymat bor bo'lsa xavfsiz matn, bo'lmasa kulrang "—" */
function bookCell(?string $value): string
{
    $value = trim((string) $value);
    return $value !== '' ? e($value) : '<span class="text-slate-400">—</span>';
}

/** Muqova rasmi: rasm bo'lsa rasm (kesilmaydi), bo'lmasa kategoriya belgisi */
function renderBookCover(array $b, string $sizeClass): void
{
    $style = categoryStyle((string) $b['category']);
    ?>
    <div class="<?= e($sizeClass) ?> shrink-0 rounded-lg overflow-hidden border border-slate-200
                flex items-center justify-center text-3xl <?= e($style['bg']) ?>">
        <?php if (!empty($b['cover_image'])): ?>
            <img src="<?= e(url((string) $b['cover_image'])) ?>" alt="<?= e($b['title']) ?>"
                 loading="lazy" class="w-full h-full object-contain">
        <?php else: ?>
            <span aria-hidden="true"><?= $style['emoji'] ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/** Nusxalar progress-bari: "3 / 5 bo'sh" va rangli chiziq */
function renderStockBar(array $b): void
{
    $total = (int) $b['copies'];
    $avail = (int) $b['available_copies'];
    $pct   = $total > 0 ? (int) round($avail / $total * 100) : 0;
    $pct   = max(0, min(100, $pct));

    $bar  = $avail === 0 ? 'bg-red-500' : ($pct <= 30 ? 'bg-amber-500' : 'bg-green-500');
    $text = $avail === 0 ? 'text-red-700' : 'text-slate-700';
    ?>
    <div class="min-w-[7rem]">
        <div class="text-sm font-semibold <?= e($text) ?>">
            <?= $avail ?> <span class="text-slate-400 font-normal">/ <?= $total ?></span>
            <span class="text-xs font-normal text-slate-500">bo'sh</span>
        </div>
        <div class="mt-1 h-1.5 w-full rounded-full bg-slate-200 overflow-hidden"
             role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>">
            <div class="h-full rounded-full <?= e($bar) ?>" style="width: <?= $pct ?>%"></div>
        </div>
    </div>
    <?php
}

/** Band qilish holati belgilari: kutilmoqda / talabada / kechikkan */
function renderLoanBadges(array $b): void
{
    $pending = (int) $b['pending_cnt'];
    $onHand  = (int) $b['onhand_cnt'];
    $overdue = (int) $b['overdue_cnt'];

    if ($pending + $onHand + $overdue === 0) {
        echo '<span class="text-xs text-slate-400">Band qilish yo\'q</span>';
        return;
    }

    echo '<div class="flex flex-wrap gap-1">';
    if ($pending > 0) {
        echo '<span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 font-medium whitespace-nowrap">⏳ Kutilmoqda: ' . $pending . '</span>';
    }
    if ($onHand > 0) {
        echo '<span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-medium whitespace-nowrap">📖 Talabada: ' . $onHand . '</span>';
    }
    if ($overdue > 0) {
        echo '<span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700 font-semibold whitespace-nowrap">⏰ Kechikkan: ' . $overdue . '</span>';
    }
    echo '</div>';
}

/** Kategoriya, soha va janr belgilari */
function renderBookTags(array $b): void
{
    echo '<div class="flex flex-wrap gap-1">';
    echo '<span class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-medium">' . e($b['category']) . '</span>';

    if (!empty($b['field'])) {
        echo '<span class="text-xs px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700" title="Soha">🏷️ ' . e($b['field']) . '</span>';
    }
    if (!empty($b['genre'])) {
        echo '<span class="text-xs px-2 py-0.5 rounded-full bg-pink-50 text-pink-700" title="Janr">🎭 ' . e($b['genre']) . '</span>';
    }
    echo '</div>';
}

/**
 * Harakat tugmalari: Tahrirlash va O'chirish.
 * Kitob band qilingan yoki talabada bo'lsa, "O'chirish" o'chiq turadi.
 */
function renderBookActions(array $b, array $filters, bool $full): void
{
    $active   = (int) $b['pending_cnt'] + (int) $b['onhand_cnt'];
    $btnSize  = $full ? 'w-full text-sm py-2' : 'text-xs px-3 py-1.5 whitespace-nowrap';
    $confirm  = '«' . $b['title'] . '» kitobini o\'chirishni xohlaysizmi? Bu amalni ortga qaytarib bo\'lmaydi.';
    ?>
    <div class="<?= $full ? 'grid grid-cols-2 gap-2' : 'flex flex-col gap-1.5 items-stretch' ?>">
        <a href="<?= e(url('admin/book_form.php?id=' . (int) $b['id'])) ?>"
           class="text-center bg-slate-700 hover:bg-slate-800 text-white rounded-md <?= e($btnSize) ?>">
            ✏️ Tahrirlash
        </a>

        <?php if ($active > 0): ?>
            <!-- Band qilingan kitobni o'chirib bo'lmaydi -->
            <button type="button" disabled
                    title="Bu kitob band qilingan yoki talabada turibdi"
                    class="bg-slate-300 text-slate-500 cursor-not-allowed rounded-md <?= e($btnSize) ?>">
                🗑️ O'chirish
            </button>
        <?php else: ?>
            <form method="post" action="<?= e(url('admin/books.php')) ?>"
                  onsubmit="<?= e('return confirm(' . json_encode($confirm, JSON_UNESCAPED_UNICODE) . ');') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="delete_id" value="<?= (int) $b['id'] ?>">
                <!-- O'chirgandan keyin shu filtrlar bilan qaytish uchun -->
                <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
                <input type="hidden" name="category" value="<?= e($filters['category']) ?>">
                <input type="hidden" name="stock" value="<?= e($filters['stock']) ?>">
                <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>">
                <input type="hidden" name="page" value="<?= (int) $filters['page'] ?>">
                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white rounded-md <?= e($btnSize) ?>">
                    🗑️ O'chirish
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

// ------------------------------------------------------------
// 1. KITOBNI O'CHIRISH (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $backUrl = booksUrl(readBookFilters($_POST));

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
        redirect($backUrl);
    }

    $id = filter_var($_POST['delete_id'] ?? null, FILTER_VALIDATE_INT);

    if ($id !== false && $id > 0) {
        try {
            // Kitob hozir kimdadir bo'lsa yoki band qilingan bo'lsa, o'chirmaymiz
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE book_id = :id AND status IN ('kutilmoqda', 'tasdiqlandi', 'berilgan')"
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

    redirect($backUrl);
}

// ------------------------------------------------------------
// 2. MA'LUMOTLARNI OLISH (statistika, filtrlangan ro'yxat)
// ------------------------------------------------------------
$filters = readBookFilters($_GET);

$stats      = ['books' => 0, 'copies' => 0, 'available' => 0, 'soldout' => 0];
$books      = [];
$totalFound = 0;
$totalPages = 1;
$onHandAll  = 0;

try {
    // 2.1. Umumiy ko'rsatkichlar
    $stats = $pdo->query(
        "SELECT COUNT(*) AS books,
                COALESCE(SUM(copies), 0) AS copies,
                COALESCE(SUM(available_copies), 0) AS available,
                COALESCE(SUM(available_copies = 0), 0) AS soldout
         FROM books"
    )->fetch();

    // Hozir talabalar qo'lida / band qilingan nusxalar (jami)
    $onHandAll = (int) $pdo->query(
        "SELECT COUNT(*) FROM reservations WHERE status IN ('tasdiqlandi', 'berilgan')"
    )->fetchColumn();

    // 2.2. Filtr shartlari. DIQQAT: har bir nomli parametr faqat BIR marta ishlatiladi
    //      (ATTR_EMULATE_PREPARES = false), shuning uchun :q_title va :q_author alohida.
    $where  = ' WHERE 1 = 1';
    $params = [];

    if ($filters['q'] !== '') {
        // LIKE belgilari (% va _) oddiy harf sifatida qidirilishi uchun "escape" qilinadi
        $like   = '%' . addcslashes($filters['q'], '%_\\') . '%';
        $where .= ' AND (b.title LIKE :q_title OR b.author LIKE :q_author)';
        $params[':q_title']  = $like;
        $params[':q_author'] = $like;
    }
    if ($filters['category'] !== '') {
        $where .= ' AND b.category = :category';
        $params[':category'] = $filters['category'];
    }
    if ($filters['stock'] === 'bor') {
        $where .= ' AND b.available_copies > 0';
    } elseif ($filters['stock'] === 'yoq') {
        $where .= ' AND b.available_copies = 0';
    }

    // 2.3. Topilganlar soni va sahifalash
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM books b' . $where);
    $cnt->execute($params);
    $totalFound = (int) $cnt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalFound / BOOKS_PER_PAGE));
    if ($filters['page'] > $totalPages) {
        $filters['page'] = $totalPages;
    }
    $offset = ($filters['page'] - 1) * BOOKS_PER_PAGE;

    // 2.4. Kitoblar ro'yxati + har bir kitob bo'yicha band qilishlar soni
    //      ORDER BY qiymati faqat SORT_OPTIONS ro'yxatidan olinadi (SQL Injection yo'q).
    $orderBy = SORT_OPTIONS[$filters['sort']][1];

    $sql = "SELECT b.*,
                   (SELECT COUNT(*) FROM reservations r
                     WHERE r.book_id = b.id AND r.status = 'kutilmoqda') AS pending_cnt,
                   (SELECT COUNT(*) FROM reservations r
                     WHERE r.book_id = b.id AND r.status IN ('tasdiqlandi', 'berilgan')) AS onhand_cnt,
                   (SELECT COUNT(*) FROM reservations r
                     WHERE r.book_id = b.id AND r.status IN ('tasdiqlandi', 'berilgan')
                       AND r.due_date IS NOT NULL AND r.due_date < NOW()) AS overdue_cnt
            FROM books b
            {$where}
            ORDER BY {$orderBy}
            LIMIT " . (int) BOOKS_PER_PAGE . ' OFFSET ' . (int) $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $books = $stmt->fetchAll();

} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Kitoblar ro\'yxatida xatolik: ' . $ex->getMessage());
    setFlash('error', "Kitoblarni yuklashda xatolik yuz berdi. (007-migratsiya ishga tushirilganmi?)");
}

$hasFilter  = $filters['q'] !== '' || $filters['category'] !== '' || $filters['stock'] !== '' || $filters['sort'] !== 'new';
$inputClass = 'w-full border border-slate-300 rounded-md px-3 py-2 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-300';
$firstNo    = ($filters['page'] - 1) * BOOKS_PER_PAGE;

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Kitoblarni boshqarish';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- ====== SARLAVHA ====== -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-2xl font-bold">Kitoblarni boshqarish</h1>
        <p class="text-sm text-slate-500">Katalogdagi barcha kitoblar, nusxalar va band qilish holati</p>
    </div>
    <a href="<?= e(url('admin/book_form.php')) ?>"
       class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-4 py-2 shadow-sm">
        + Yangi kitob qo'shish
    </a>
</div>

<!-- ====== UMUMIY KO'RSATKICHLAR ====== -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold"><?= (int) $stats['books'] ?></div>
        <div class="text-xs text-slate-500">📚 Jami kitoblar</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold"><?= (int) $stats['copies'] ?></div>
        <div class="text-xs text-slate-500">📦 Jami nusxalar</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold text-green-600"><?= (int) $stats['available'] ?></div>
        <div class="text-xs text-slate-500">✅ Hozir bo'sh nusxalar</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4">
        <div class="text-2xl font-bold text-blue-600"><?= $onHandAll ?></div>
        <div class="text-xs text-slate-500">📖 Talabalar qo'lida</div>
    </div>
    <div class="bg-white border border-slate-200 rounded-xl p-4 col-span-2 lg:col-span-1">
        <div class="text-2xl font-bold text-red-600"><?= (int) $stats['soldout'] ?></div>
        <div class="text-xs text-slate-500">⛔ Nusxasi tugagan kitoblar</div>
    </div>
</div>

<!-- ====== QIDIRUV, FILTR VA SARALASH ====== -->
<form method="get" action="<?= e(url('admin/books.php')) ?>"
      class="bg-white border border-slate-200 rounded-xl p-3 mb-3
             grid grid-cols-1 md:grid-cols-[1.6fr_1fr_1fr_1fr_auto_auto] gap-2">

    <input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100"
           placeholder="🔍 Nomi yoki muallifi bo'yicha qidirish..." aria-label="Kitobni qidirish"
           class="<?= $inputClass ?>">

    <select name="category" aria-label="Kategoriya bo'yicha filtr" class="<?= $inputClass ?>">
        <option value="">Barcha kategoriyalar</option>
        <?php foreach (BOOK_CATEGORIES as $cat): ?>
            <option value="<?= e($cat) ?>" <?= $cat === $filters['category'] ? 'selected' : '' ?>><?= e($cat) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="stock" aria-label="Mavjudlik bo'yicha filtr" class="<?= $inputClass ?>">
        <option value="">Barcha holatlar</option>
        <?php foreach (STOCK_FILTERS as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $key === $filters['stock'] ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="sort" aria-label="Saralash" class="<?= $inputClass ?>">
        <?php foreach (SORT_OPTIONS as $key => $opt): ?>
            <option value="<?= e($key) ?>" <?= $key === $filters['sort'] ? 'selected' : '' ?>><?= e($opt[0]) ?></option>
        <?php endforeach; ?>
    </select>

    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-5 py-2 text-sm">
        Qidirish
    </button>

    <?php if ($hasFilter): ?>
        <a href="<?= e(url('admin/books.php')) ?>"
           class="border border-slate-300 bg-white hover:bg-slate-50 rounded-md px-4 py-2 text-sm text-center">
            Tozalash
        </a>
    <?php endif; ?>
</form>

<p class="text-slate-500 text-sm mb-3">
    Topildi: <b><?= $totalFound ?></b> ta kitob
    <?php if ($totalPages > 1): ?>
        · <?= (int) $filters['page'] ?>-sahifa / <?= $totalPages ?>
    <?php endif; ?>
</p>

<?php if (!$books): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        <?= $hasFilter ? "Bu filtr bo'yicha kitob topilmadi." : "Katalogda hali kitoblar yo'q." ?>
    </div>
<?php else: ?>

    <!-- ====== KOMPYUTER (1024px dan keng): jadval ====== -->
    <div class="hidden lg:block bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
        <table class="w-full text-sm text-left">
            <thead class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wide">
                <tr>
                    <th class="px-3 py-3 font-semibold w-10">№</th>
                    <th class="px-3 py-3 font-semibold">Kitob</th>
                    <th class="px-3 py-3 font-semibold">Muallif</th>
                    <th class="px-3 py-3 font-semibold">Turkum</th>
                    <th class="px-3 py-3 font-semibold">Nusxalar</th>
                    <th class="px-3 py-3 font-semibold">Band qilish</th>
                    <th class="px-3 py-3 font-semibold whitespace-nowrap">Qo'shilgan</th>
                    <th class="px-3 py-3 font-semibold text-right">Harakatlar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($books as $i => $b): ?>
                    <tr class="border-t border-slate-200 align-top hover:bg-slate-50/60">
                        <td class="px-3 py-3 text-slate-400"><?= $firstNo + $i + 1 ?></td>

                        <!-- Kitob: muqova + nomi + ID + qisqa tavsif -->
                        <td class="px-3 py-3">
                            <div class="flex items-start gap-3">
                                <?php renderBookCover($b, 'w-16 h-24'); ?>
                                <div class="min-w-0 max-w-xs">
                                    <a href="<?= e(url('book.php?id=' . (int) $b['id'])) ?>"
                                       class="font-bold text-slate-900 hover:text-blue-600 hover:underline break-words leading-snug">
                                        <?= e($b['title']) ?>
                                    </a>
                                    <div class="text-xs text-slate-400 mt-0.5">ID: #<?= (int) $b['id'] ?></div>
                                    <?php if (!empty($b['description'])): ?>
                                        <p class="text-xs text-slate-500 mt-1 line-clamp-3 break-words"
                                           title="<?= e($b['description']) ?>"><?= e($b['description']) ?></p>
                                    <?php else: ?>
                                        <p class="text-xs text-slate-400 mt-1 italic">Tavsif kiritilmagan</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <td class="px-3 py-3 break-words max-w-[10rem]"><?= bookCell($b['author']) ?></td>

                        <!-- Kategoriya, soha, janr -->
                        <td class="px-3 py-3"><?php renderBookTags($b); ?></td>

                        <!-- Bo'sh / jami nusxalar -->
                        <td class="px-3 py-3"><?php renderStockBar($b); ?></td>

                        <!-- Kutilmoqda / talabada / kechikkan -->
                        <td class="px-3 py-3"><?php renderLoanBadges($b); ?></td>

                        <td class="px-3 py-3 whitespace-nowrap text-xs text-slate-600">
                            <?= e(date('d.m.Y', strtotime((string) $b['created_at']))) ?>
                        </td>

                        <td class="px-3 py-3">
                            <div class="flex justify-end"><?php renderBookActions($b, $filters, false); ?></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ====== TELEFON VA PLANSHET (1024px dan tor): kartochkalar ====== -->
    <div class="lg:hidden space-y-3">
        <?php foreach ($books as $b): ?>
            <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
                <div class="flex items-start gap-3">
                    <?php renderBookCover($b, 'w-20 h-28'); ?>

                    <div class="min-w-0 flex-1">
                        <a href="<?= e(url('book.php?id=' . (int) $b['id'])) ?>"
                           class="font-bold text-slate-900 hover:underline break-words leading-snug block">
                            <?= e($b['title']) ?>
                        </a>
                        <div class="text-sm text-slate-600 break-words"><?= e($b['author']) ?></div>
                        <div class="text-xs text-slate-400 mt-0.5">
                            ID: #<?= (int) $b['id'] ?> · <?= e(date('d.m.Y', strtotime((string) $b['created_at']))) ?>
                        </div>
                        <div class="mt-2"><?php renderBookTags($b); ?></div>
                    </div>
                </div>

                <?php if (!empty($b['description'])): ?>
                    <p class="text-xs text-slate-500 mt-3 line-clamp-3 break-words"><?= e($b['description']) ?></p>
                <?php endif; ?>

                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                    <?php renderStockBar($b); ?>
                    <?php renderLoanBadges($b); ?>
                </div>

                <div class="mt-3"><?php renderBookActions($b, $filters, true); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ====== SAHIFALASH ====== -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-5 flex flex-wrap items-center justify-center gap-1.5" aria-label="Sahifalash">
            <?php if ($filters['page'] > 1): ?>
                <a href="<?= e(url(booksUrl($filters, ['page' => $filters['page'] - 1]))) ?>"
                   class="px-3 py-1.5 rounded-md border border-slate-200 bg-white hover:border-blue-600 text-sm">← Oldingi</a>
            <?php endif; ?>

            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php
                // Uzun ro'yxatda faqat birinchi, oxirgi va joriy sahifa atrofi ko'rsatiladi
                if ($p !== 1 && $p !== $totalPages && abs($p - $filters['page']) > 2) {
                    if (abs($p - $filters['page']) === 3) {
                        echo '<span class="px-1 text-slate-400">…</span>';
                    }
                    continue;
                }
                $isCurrent = $p === $filters['page'];
                ?>
                <a href="<?= e(url(booksUrl($filters, ['page' => $p]))) ?>"
                   <?= $isCurrent ? 'aria-current="page"' : '' ?>
                   class="px-3 py-1.5 rounded-md border text-sm
                          <?= $isCurrent ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border-slate-200 hover:border-blue-600' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>

            <?php if ($filters['page'] < $totalPages): ?>
                <a href="<?= e(url(booksUrl($filters, ['page' => $filters['page'] + 1]))) ?>"
                   class="px-3 py-1.5 rounded-md border border-slate-200 bg-white hover:border-blue-600 text-sm">Keyingi →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php
/**
 * ============================================================
 *  Fayl: admin/book_form.php – Kitob qo'shish va tahrirlash
 *  Manzillar:
 *    admin/book_form.php        – yangi kitob qo'shish
 *    admin/book_form.php?id=5   – 5-kitobni tahrirlash
 *
 *  Muqova rasmi: JPG, PNG yoki WEBP, eng ko'pi 2 MB (uploads/covers/).
 *
 *  3-bosqich: SOHA (field) va JANR (genre) – ixtiyoriy, bo'sh bo'lsa NULL.
 *
 *  TAKRORLANISHNI TEKSHIRISH: nomi va muallifi bir xil kitob ikki marta qo'shilmaydi
 *  (katta-kichik harf, ortiqcha bo'shliq va apostrof turlari farqi hisobga olinmaydi).
 *
 *  YANGI (ONLAYN MUTOLAA, 2-qism):
 *    1) PDF FAYL: kitobning onlayn nusxasi (faqat PDF, eng ko'pi 50 MB).
 *       uploads/books/ papkasiga tasodifiy nom bilan saqlanadi. Papkaga to'g'ridan-to'g'ri
 *       kirishni taqiqlovchi .htaccess avtomatik yoziladi: fayl faqat PHP orqali
 *       (keyingi qism: tizimga kirgan talabaga) beriladi.
 *       Tahrirlashda: yangi PDF tanlansa eskisi almashadi, "O'chirish" belgisi bilan olib tashlanadi.
 *    2) MUNDARIJA: bo'lim nomi + boshlanadigan bet raqami + daraja (asosiy / ichki).
 *       Qatorlarni qo'shish, o'chirish va ↑ ↓ bilan tartiblash mumkin (book_toc jadvali).
 *       Mundarija butunlay ixtiyoriy.
 *    3) Hammasi (kitob + mundarija) BITTA TRANZAKSIYADA saqlanadi: xatolik bo'lsa
 *       yarim-yorti ma'lumot qolmaydi, yuklangan fayllar ham tozalanadi.
 *
 *  Eslatma: avval migrations/007_field_genre.sql va 013_kitob_pdf_mundarija.sql ishga tushirilgan bo'lsin.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

requireAdmin();

// Muqova rasmi sozlamalari
const MAX_COVER_SIZE  = 2 * 1024 * 1024;   // 2 MB
const COVER_DIR_REL   = 'uploads/covers';  // loyiha ildiziga nisbatan yo'l
const ALLOWED_COVERS  = [                  // ruxsat etilgan turlar va kengaytmalari
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// PDF (onlayn nusxa) sozlamalari
const MAX_PDF_SIZE    = 50 * 1024 * 1024;  // 50 MB (php.ini dagi upload_max_filesize ham shuncha bo'lishi kerak)
const PDF_DIR_REL     = 'uploads/books';   // loyiha ildiziga nisbatan yo'l

// Mundarija chegaralari
const MAX_TOC_ROWS    = 300;               // bitta kitobda eng ko'pi bilan nechta bo'lim
const MAX_TOC_PAGE    = 9999;              // bet raqamining eng kattasi

// ------------------------------------------------------------
// 0. YORDAMCHI FUNKSIYALAR
// ------------------------------------------------------------

/**
 * Matnni solishtirish uchun tayyorlaydi:
 *   - ortiqcha bo'shliqlar bitta bo'shliqqa aylanadi
 *   - o' / oʻ / o’ / o‘ / o` kabi apostrof turlari oddiy ' ga almashtiriladi
 * Katta-kichik harf farqini bazaning utf8mb4_unicode_ci kolatsiyasi o'zi hisobga olmaydi.
 */
function bookCompareText(string $text): string
{
    $text = trim((string) preg_replace('/\s+/u', ' ', $text));

    return str_replace(['ʻ', 'ʼ', '’', '‘', '`', '´'], "'", $text);
}

/**
 * Bazadagi ustunni xuddi shunday tayyorlaydigan SQL ifodasi
 * (apostrof turlari oddiy ' ga almashtiriladi, chetdagi bo'shliqlar olib tashlanadi).
 * $column – faqat shu fayl ichidagi qat'iy nom ('title' yoki 'author'), foydalanuvchi qiymati emas.
 */
function bookCompareSql(string $column): string
{
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(b.{$column}),"
         . "'ʻ',''''),'ʼ',''''),'’',''''),'‘',''''),'`',''''),'´','''')";
}

/**
 * Nomi va muallifi bir xil kitob bormi? Bo'lsa – uning ma'lumotlarini qaytaradi.
 * $exceptId – tahrirlashda kitobning o'zini hisobga olmaslik uchun (yangi kitobda 0).
 * DIQQAT: har bir nomli parametr so'rovda faqat BIR marta ishlatiladi.
 */
function findDuplicateBook(PDO $pdo, string $title, string $author, int $exceptId = 0): ?array
{
    $stmt = $pdo->prepare(
        'SELECT b.id, b.title, b.author, b.copies, b.available_copies
         FROM books b
         WHERE ' . bookCompareSql('title') . ' = :title_key
           AND ' . bookCompareSql('author') . ' = :author_key
           AND b.id <> :except_id
         ORDER BY b.id ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':title_key'  => bookCompareText($title),
        ':author_key' => bookCompareText($author),
        ':except_id'  => $exceptId,
    ]);

    return $stmt->fetch() ?: null;
}

/**
 * Formadan kelgan mundarija qatorlarini o'qiydi va tekshiradi.
 * Forma maydonlari: toc_title[], toc_page[], toc_level[] (qatorlar tartibi saqlanadi).
 *   - butunlay bo'sh qatorlar tashlab yuboriladi
 *   - nomi 1–200 belgi, bet raqami 1–MAX_TOC_PAGE, daraja 1 yoki 2
 * Xatoliklar $errors ga qo'shiladi. Qaytariladigan massiv formani qayta chizish uchun ham ishlatiladi.
 *
 * @return array<int, array{title: string, page: string, level: int}>
 */
function parseTocInput(array $post, array &$errors): array
{
    $titles = $post['toc_title'] ?? [];
    $pages  = $post['toc_page'] ?? [];
    $levels = $post['toc_level'] ?? [];

    if (!is_array($titles) || !is_array($pages) || !is_array($levels)) {
        return [];
    }

    $rows = [];
    $seen = 0;

    foreach ($titles as $i => $rawTitle) {
        $seen++;
        if ($seen > MAX_TOC_ROWS) {
            $errors[] = "Mundarijada eng ko'pi bilan " . MAX_TOC_ROWS . " ta bo'lim bo'lishi mumkin.";
            break;
        }

        $title   = is_scalar($rawTitle) ? trim((string) preg_replace('/\s+/u', ' ', (string) $rawTitle)) : '';
        $pageRaw = isset($pages[$i]) && is_scalar($pages[$i]) ? trim((string) $pages[$i]) : '';
        $level   = isset($levels[$i]) && is_scalar($levels[$i]) ? (int) $levels[$i] : 1;
        if ($level !== 1 && $level !== 2) {
            $level = 1;
        }

        // Butunlay bo'sh qator – e'tiborsiz qoldiriladi
        if ($title === '' && $pageRaw === '') {
            continue;
        }

        $line = count($rows) + 1;   // foydalanuvchiga ko'rsatiladigan qator raqami

        if ($title === '') {
            $errors[] = "Mundarija, {$line}-qator: bo'lim nomini kiriting.";
        } elseif (mb_strlen($title) > 200) {
            $errors[] = "Mundarija, {$line}-qator: bo'lim nomi 200 belgidan oshmasin.";
        }

        $page = filter_var($pageRaw, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > MAX_TOC_PAGE) {
            $errors[] = "Mundarija, {$line}-qator: bet raqami 1 dan " . MAX_TOC_PAGE . " gacha butun son bo'lsin.";
        }

        $rows[] = ['title' => $title, 'page' => $pageRaw, 'level' => $level];
    }

    return $rows;
}

/** Bitta mundarija qatorining HTML'i (mavjud qatorlar va JS uchun <template> shu funksiyadan chiqadi) */
function renderTocRow(array $row): void
{
    $level = (int) ($row['level'] ?? 1);
    ?>
    <div data-toc-row class="flex flex-wrap sm:flex-nowrap items-center gap-2">
        <select name="toc_level[]" aria-label="Bo'lim darajasi"
                class="border border-slate-300 rounded-md px-2 py-2 bg-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">
            <option value="1" <?= $level === 1 ? 'selected' : '' ?>>Asosiy</option>
            <option value="2" <?= $level === 2 ? 'selected' : '' ?>>Ichki</option>
        </select>

        <input type="text" name="toc_title[]" value="<?= e($row['title'] ?? '') ?>" maxlength="200"
               placeholder="Bo'lim nomi" aria-label="Bo'lim nomi"
               class="flex-1 min-w-[10rem] border border-slate-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">

        <input type="number" name="toc_page[]" value="<?= e($row['page'] ?? '') ?>" min="1" max="<?= (int) MAX_TOC_PAGE ?>"
               placeholder="Bet" aria-label="Boshlanadigan bet raqami"
               class="w-20 border border-slate-300 rounded-md px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300">

        <div class="flex gap-1 shrink-0">
            <button type="button" data-toc-up aria-label="Yuqoriga" title="Yuqoriga"
                    class="w-8 h-8 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-600">↑</button>
            <button type="button" data-toc-down aria-label="Pastga" title="Pastga"
                    class="w-8 h-8 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-slate-600">↓</button>
            <button type="button" data-toc-remove aria-label="Qatorni o'chirish" title="Qatorni o'chirish"
                    class="w-8 h-8 rounded-md border border-red-200 bg-white hover:bg-red-50 text-red-600">✕</button>
        </div>
    </div>
    <?php
}

// ------------------------------------------------------------
// 1. TAHRIRLASH REJIMIMI? (?id=...)
// ------------------------------------------------------------
$id     = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = ($id !== null && $id !== false && $id > 0);
$book   = null;

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM books WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $book = $stmt->fetch() ?: null;

    if ($book === null) {
        setFlash('error', 'Bunday kitob topilmadi.');
        redirect('admin/books.php');
    }
}

// Forma maydonlarining boshlang'ich qiymatlari
$form = [
    'title'       => $book['title']       ?? '',
    'author'      => $book['author']      ?? '',
    'category'    => $book['category']    ?? BOOK_CATEGORIES[0],
    'field'       => $book['field']       ?? '',
    'genre'       => $book['genre']       ?? '',
    'copies'      => (string) ($book['copies'] ?? 1),
    'description' => $book['description'] ?? '',
];
$errors    = [];
$duplicate = null;   // takror topilsa, mavjud kitob ma'lumotlari shu yerga tushadi

// Mundarija qatorlari: tahrirlashda bazadan, yangi kitobda bo'sh
$tocRows = [];
if ($isEdit) {
    try {
        $stmt = $pdo->prepare(
            'SELECT title, page_no, level FROM book_toc
             WHERE book_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':id' => $id]);
        foreach ($stmt->fetchAll() as $t) {
            $tocRows[] = ['title' => (string) $t['title'], 'page' => (string) (int) $t['page_no'], 'level' => (int) $t['level']];
        }
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] Mundarijani o\'qishda xatolik: ' . $ex->getMessage());
    }
}

// ------------------------------------------------------------
// 1.1. SOHA VA JANR TAVSIYALARI (datalist uchun)
// ------------------------------------------------------------
$fieldSuggestions = [];
$genreSuggestions = [];
try {
    $fieldSuggestions = $pdo->query(
        "SELECT DISTINCT `field` FROM books
         WHERE `field` IS NOT NULL AND `field` <> '' ORDER BY `field`"
    )->fetchAll(PDO::FETCH_COLUMN);

    $genreSuggestions = $pdo->query(
        "SELECT DISTINCT genre FROM books
         WHERE genre IS NOT NULL AND genre <> '' ORDER BY genre"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Soha/janr tavsiyalarida xatolik: ' . $ex->getMessage());
}

// ------------------------------------------------------------
// 2. FORMA YUBORILGANDA (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 2.0. PHP post_max_size dan katta fayl yuborilsa, $_POST va $_FILES BUTUNLAY bo'sh keladi.
    //      Bu holatni CSRF xatosidan ajratib, aniq tushuntiramiz.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = "Yuborilgan fayllar hajmi server chegarasidan oshib ketdi. "
                  . "PDF hajmini kamaytiring yoki php.ini dagi post_max_size va upload_max_filesize ni oshiring.";
    } elseif (!csrfVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = "Sessiya muddati tugagan. Sahifani yangilab, qayta urinib ko'ring.";
    } else {
        // 2.1. Matn maydonlarini olamiz (ortiqcha bo'shliqlar tozalanadi)
        $form['title']       = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['title'] ?? '')));
        $form['author']      = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['author'] ?? '')));
        $form['category']    = (string) ($_POST['category'] ?? '');
        $form['field']       = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['field'] ?? '')));
        $form['genre']       = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['genre'] ?? '')));
        $form['copies']      = trim((string) ($_POST['copies'] ?? ''));
        $form['description'] = trim((string) ($_POST['description'] ?? ''));

        // 2.2. Tekshiruvlar
        $titleOk  = mb_strlen($form['title'])  >= 1 && mb_strlen($form['title'])  <= 200;
        $authorOk = mb_strlen($form['author']) >= 1 && mb_strlen($form['author']) <= 150;

        if (!$titleOk) {
            $errors[] = "Kitob nomi 1 tadan 200 tagacha belgidan iborat bo'lsin.";
        }
        if (!$authorOk) {
            $errors[] = "Muallif 1 tadan 150 tagacha belgidan iborat bo'lsin.";
        }
        if (!in_array($form['category'], BOOK_CATEGORIES, true)) {
            $errors[] = "Kategoriya noto'g'ri tanlangan.";
        }

        // Soha va janr ixtiyoriy, lekin yozilsa 100 belgidan oshmasin
        if (mb_strlen($form['field']) > 100) {
            $errors[] = "Soha 100 belgidan oshmasin.";
        }
        if (mb_strlen($form['genre']) > 100) {
            $errors[] = "Janr 100 belgidan oshmasin.";
        }

        $copies = filter_var($form['copies'], FILTER_VALIDATE_INT);
        if ($copies === false || $copies < 1 || $copies > 1000) {
            $errors[] = "Nusxalar soni 1 dan 1000 gacha butun son bo'lsin.";
        }
        if (mb_strlen($form['description']) > 2000) {
            $errors[] = "Tavsif 2000 belgidan oshmasin.";
        }

        // 2.2.0. MUNDARIJA qatorlari (xatolar $errors ga tushadi, qatorlar formada qayta chiqadi)
        $tocRows = parseTocInput($_POST, $errors);

        // 2.2.1. TAKRORLANISH: nomi va muallifi bir xil kitob allaqachon bormi?
        //        Fayllar diskka yozilishidan OLDIN tekshiramiz (yetim fayl qolmasligi uchun).
        if ($titleOk && $authorOk) {
            try {
                $duplicate = findDuplicateBook($pdo, $form['title'], $form['author'], $isEdit ? (int) $id : 0);

                if ($duplicate !== null) {
                    $errors[] = "«" . $duplicate['title'] . "» (muallif: " . $duplicate['author'] . ") kitobi katalogda allaqachon mavjud.";
                }
            } catch (PDOException $ex) {
                error_log('[Kutubxona-ARM] Takrorlanishni tekshirishda xatolik: ' . $ex->getMessage());
                $errors[] = "Kitobni tekshirishda xatolik yuz berdi. Keyinroq urinib ko'ring.";
            }
        }

        // Tahrirlashda: nusxalar soni hozir band bo'lganlardan kam bo'lmasligi kerak
        $active = 0;
        if ($isEdit && $copies !== false && $copies >= 1) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE book_id = :id AND status IN ('kutilmoqda', 'tasdiqlandi', 'berilgan')"
            );
            $stmt->execute([':id' => $id]);
            $active = (int) $stmt->fetchColumn();

            if ($copies < $active) {
                $errors[] = "Nusxalar soni hozir band qilinganlar sonidan ($active) kam bo'lishi mumkin emas.";
            }
        }

        // 2.3. Muqova rasmini tekshiramiz (ixtiyoriy)
        $coverTmp = null;   // vaqtinchalik fayl yo'li
        $coverExt = null;   // fayl kengaytmasi

        if (isset($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['cover'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Rasmni yuklashda xatolik yuz berdi (kod: " . (int) $file['error'] . ").";
            } elseif ($file['size'] > MAX_COVER_SIZE) {
                $errors[] = "Rasm hajmi 2 MB dan oshmasligi kerak.";
            } else {
                // getimagesize() faylning haqiqatan rasm ekanini ichidan tekshiradi
                $info = @getimagesize($file['tmp_name']);
                $mime = is_array($info) ? ($info['mime'] ?? '') : '';

                if (!isset(ALLOWED_COVERS[$mime])) {
                    $errors[] = "Faqat JPG, PNG yoki WEBP rasm yuklash mumkin.";
                } else {
                    $coverTmp = $file['tmp_name'];
                    $coverExt = ALLOWED_COVERS[$mime];
                }
            }
        }

        // 2.3.1. PDF faylni tekshiramiz (ixtiyoriy)
        //        Kengaytmaga ishonilmaydi: faylning BOSHIDA "%PDF-" imzosi bo'lishi shart.
        $pdfTmp     = null;   // vaqtinchalik fayl yo'li
        $pdfSize    = 0;      // hajmi (bayt)
        $removePdf  = $isEdit && !empty($_POST['remove_pdf']);   // "PDF ni o'chirish" belgisi

        if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
            $pf = $_FILES['pdf'];

            if ($pf['error'] === UPLOAD_ERR_INI_SIZE || $pf['error'] === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = "PDF hajmi server chegarasidan oshib ketdi (php.ini: upload_max_filesize).";
            } elseif ($pf['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "PDF ni yuklashda xatolik yuz berdi (kod: " . (int) $pf['error'] . ").";
            } elseif ($pf['size'] > MAX_PDF_SIZE) {
                $errors[] = "PDF hajmi " . (int) (MAX_PDF_SIZE / 1048576) . " MB dan oshmasligi kerak.";
            } else {
                $fh   = @fopen($pf['tmp_name'], 'rb');
                $head = $fh ? (string) fread($fh, 5) : '';
                if ($fh) {
                    fclose($fh);
                }

                if ($head !== '%PDF-') {
                    $errors[] = "Faqat haqiqiy PDF fayl yuklash mumkin.";
                } else {
                    $pdfTmp  = $pf['tmp_name'];
                    $pdfSize = (int) $pf['size'];
                }
            }
        }

        // 2.4. Xatolik bo'lmasa – fayllarni diskka ko'chiramiz
        $newCoverPath = null;   // bazaga yoziladigan yangi rasm yo'li
        $newPdfPath   = null;   // bazaga yoziladigan yangi PDF yo'li

        if (!$errors && $coverTmp !== null) {
            $dir = __DIR__ . '/../' . COVER_DIR_REL;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $fileName = bin2hex(random_bytes(8)) . '.' . $coverExt;   // tasodifiy nom

            if (@move_uploaded_file($coverTmp, $dir . '/' . $fileName)) {
                $newCoverPath = COVER_DIR_REL . '/' . $fileName;
            } else {
                $errors[] = "Rasmni saqlab bo'lmadi. uploads/covers papkasiga yozish ruxsatini tekshiring.";
            }
        }

        if (!$errors && $pdfTmp !== null) {
            $dir = __DIR__ . '/../' . PDF_DIR_REL;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            // Papkaga to'g'ridan-to'g'ri kirishni taqiqlaymiz (Apache): PDF faqat PHP orqali beriladi.
            // Nginx'da buni server sozlamasida qilish kerak (location /uploads/books/ { deny all; }).
            $htaccess = $dir . '/.htaccess';
            if (!is_file($htaccess)) {
                @file_put_contents(
                    $htaccess,
                    "# PDF fayllarga to'g'ridan-to'g'ri kirish taqiqlangan (faqat PHP orqali beriladi)\n"
                    . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                    . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
                );
            }

            $fileName = bin2hex(random_bytes(12)) . '.pdf';   // tasodifiy nom (taxmin qilib bo'lmaydi)

            if (@move_uploaded_file($pdfTmp, $dir . '/' . $fileName)) {
                $newPdfPath = PDF_DIR_REL . '/' . $fileName;
            } else {
                // Rasm allaqachon saqlangan bo'lsa, yetim qolmasligi uchun o'chiramiz
                deleteCoverFile($newCoverPath);
                $newCoverPath = null;
                $errors[] = "PDF ni saqlab bo'lmadi. uploads/books papkasiga yozish ruxsatini tekshiring.";
            }
        }

        // 2.5. Hammasi joyida bo'lsa – bazaga yozamiz (BITTA TRANZAKSIYADA)
        if (!$errors) {
            // Bo'sh soha/janr bazaga NULL bo'lib yoziladi
            $fieldValue = $form['field'] !== '' ? $form['field'] : null;
            $genreValue = $form['genre'] !== '' ? $form['genre'] : null;

            // Yakuniy PDF holati: yangi yuklangan > o'chirilgan > o'zgarishsiz
            $finalPdf  = $isEdit ? ($book['pdf_file'] ?? null) : null;
            $finalSize = $isEdit ? ($book['pdf_size'] ?? null) : null;
            if ($removePdf) {
                $finalPdf  = null;
                $finalSize = null;
            }
            if ($newPdfPath !== null) {
                $finalPdf  = $newPdfPath;
                $finalSize = $pdfSize;
            }

            try {
                $pdo->beginTransaction();

                // 6-bosqich: available_copies ustuni bormi?
                $col = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'books'
                       AND COLUMN_NAME  = 'available_copies'"
                )->fetchColumn();
                $hasAvailable = (int) $col > 0;

                if ($isEdit) {
                    // DIQQAT: har bir nomli parametr so'rovda faqat BIR marta uchrashi kerak
                    // (ATTR_EMULATE_PREPARES = false), shu sababli :copies_avail alohida.
                    $stmt = $pdo->prepare(
                        'UPDATE books
                         SET title = :title, author = :author, category = :category,
                             `field` = :field, genre = :genre,
                             description = :description, copies = :copies,
                             cover_image = :cover_image,
                             pdf_file = :pdf_file, pdf_size = :pdf_size'
                             . ($hasAvailable
                                ? ', available_copies = GREATEST(0, :copies_avail - :active_count)'
                                : '')
                             . ' WHERE id = :id'
                    );
                    $params = [
                        ':title'       => $form['title'],
                        ':author'      => $form['author'],
                        ':category'    => $form['category'],
                        ':field'       => $fieldValue,
                        ':genre'       => $genreValue,
                        ':description' => $form['description'] !== '' ? $form['description'] : null,
                        ':copies'      => $copies,
                        ':cover_image' => $newCoverPath ?? $book['cover_image'],
                        ':pdf_file'    => $finalPdf,
                        ':pdf_size'    => $finalSize,
                        ':id'          => $id,
                    ];
                    if ($hasAvailable) {
                        // Bo'sh = yangi jami nusxa − hozir band/talabada turganlar (manfiy emas)
                        $params[':copies_avail'] = $copies;
                        $params[':active_count'] = $active;
                    }
                    $stmt->execute($params);

                    $savedBookId = (int) $id;
                } else {
                    $params = [
                        ':title'       => $form['title'],
                        ':author'      => $form['author'],
                        ':category'    => $form['category'],
                        ':field'       => $fieldValue,
                        ':genre'       => $genreValue,
                        ':description' => $form['description'] !== '' ? $form['description'] : null,
                        ':cover_image' => $newCoverPath,
                        ':pdf_file'    => $finalPdf,
                        ':pdf_size'    => $finalSize,
                        ':copies'      => $copies,
                    ];

                    if ($hasAvailable) {
                        $stmt = $pdo->prepare(
                            'INSERT INTO books (title, author, category, `field`, genre, description, cover_image, pdf_file, pdf_size, copies, available_copies)
                             VALUES (:title, :author, :category, :field, :genre, :description, :cover_image, :pdf_file, :pdf_size, :copies, :copies_avail)'
                        );
                        // Yangi kitobda hamma nusxa bo'sh
                        $params[':copies_avail'] = $copies;
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO books (title, author, category, `field`, genre, description, cover_image, pdf_file, pdf_size, copies)
                             VALUES (:title, :author, :category, :field, :genre, :description, :cover_image, :pdf_file, :pdf_size, :copies)'
                        );
                    }
                    $stmt->execute($params);

                    $savedBookId = (int) $pdo->lastInsertId();
                }

                // MUNDARIJA: eskisi to'liq o'chiriladi va formadagi tartib bo'yicha qayta yoziladi
                $del = $pdo->prepare('DELETE FROM book_toc WHERE book_id = :book_id');
                $del->execute([':book_id' => $savedBookId]);

                if ($tocRows) {
                    $tocIns = $pdo->prepare(
                        'INSERT INTO book_toc (book_id, title, page_no, level, sort_order)
                         VALUES (:book_id, :title, :page_no, :level, :sort_order)'
                    );
                    foreach ($tocRows as $order => $row) {
                        $tocIns->execute([
                            ':book_id'    => $savedBookId,
                            ':title'      => $row['title'],
                            ':page_no'    => (int) $row['page'],
                            ':level'      => (int) $row['level'],
                            ':sort_order' => $order,
                        ]);
                    }
                }

                $pdo->commit();

                // Tranzaksiya muvaffaqiyatli: endi eski (keraksiz) fayllarni diskdan o'chiramiz
                if ($isEdit) {
                    if ($newCoverPath !== null) {
                        deleteCoverFile($book['cover_image']);
                    }
                    if ($newPdfPath !== null || $removePdf) {
                        deleteBookPdfFile($book['pdf_file'] ?? null);
                    }
                }

                setFlash('success', $isEdit ? "Kitob ma'lumotlari yangilandi." : "Yangi kitob qo'shildi.");
                redirect('admin/books.php');

            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                // Baza xatosi bo'lsa, yuklangan fayllar yetim qolmasligi uchun o'chiramiz
                deleteCoverFile($newCoverPath);
                deleteBookPdfFile($newPdfPath);

                if ((string) $ex->getCode() === '23000') {
                    // UNIQUE indeks (uq_books_title_author): ikki kishi bir vaqtda bir xil kitob qo'shdi
                    try {
                        $duplicate = findDuplicateBook($pdo, $form['title'], $form['author'], $isEdit ? (int) $id : 0);
                    } catch (PDOException $ignored) {
                        $duplicate = null;
                    }
                    $errors[] = "Bu nomi va muallifli kitob katalogda allaqachon mavjud.";
                } else {
                    error_log('[Kutubxona-ARM] Kitobni saqlashda xatolik: ' . $ex->getMessage());
                    $errors[] = "Kitobni saqlashda xatolik yuz berdi. (007 va 013-migratsiyalar ishga tushirilganmi?)";
                }
            }
        }
    }
}

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = $isEdit ? 'Kitobni tahrirlash' : "Yangi kitob qo'shish";
require_once __DIR__ . '/../includes/header.php';

// Formaning action manzili (tahrirlashda ?id= saqlanadi)
$formAction = 'admin/book_form.php' . ($isEdit ? '?id=' . (int) $id : '');

// Hozirgi PDF (faqat tahrirlashda)
$currentPdf     = $isEdit ? (string) ($book['pdf_file'] ?? '') : '';
$currentPdfSize = $isEdit ? formatFileSize((int) ($book['pdf_size'] ?? 0)) : '';
?>

<a href="<?= e(url('admin/books.php')) ?>" class="text-blue-600 hover:underline text-sm">← Kitoblar ro'yxatiga qaytish</a>

<div class="max-w-2xl mx-auto bg-white border border-slate-200 rounded-xl p-6 mt-3">
    <h1 class="text-2xl font-bold mb-4"><?= e($pageTitle) ?></h1>

    <?php if ($errors): ?>
        <div class="mb-4 border border-red-300 bg-red-50 text-red-800 rounded-md px-4 py-3 text-sm">
            <ul class="list-disc pl-5 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($duplicate !== null): ?>
        <!-- Takror topildi: mavjud kitobni tahrirlashga havola -->
        <div class="mb-4 border border-amber-300 bg-amber-50 text-amber-900 rounded-md px-4 py-3 text-sm">
            <div class="font-bold mb-1">📚 Bu kitob katalogda bor</div>
            <p>
                <b><?= e($duplicate['title']) ?></b> – <?= e($duplicate['author']) ?>
                (ID: #<?= (int) $duplicate['id'] ?>, bo'sh nusxalar:
                <?= (int) $duplicate['available_copies'] ?> / <?= (int) $duplicate['copies'] ?>).
            </p>
            <p class="mt-1 text-amber-800">
                Yangi nusxa qo'shmoqchi bo'lsangiz, shu kitobni tahrirlab <b>«Nusxalar soni»</b> ni oshiring.
            </p>
            <a href="<?= e(url('admin/book_form.php?id=' . (int) $duplicate['id'])) ?>"
               class="inline-block mt-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md px-3 py-1.5 text-xs font-semibold">
                ✏️ Mavjud kitobni tahrirlash
            </a>
        </div>
    <?php endif; ?>

    <?php if ($errors && ($_FILES['cover']['name'] ?? '') !== '' || $errors && ($_FILES['pdf']['name'] ?? '') !== ''): ?>
        <!-- Brauzer xavfsizlik tufayli tanlangan fayllarni xatodan keyin saqlab qola olmaydi -->
        <div class="mb-4 border border-blue-200 bg-blue-50 text-blue-800 rounded-md px-4 py-3 text-xs">
            ℹ️ Xatolik tufayli tanlangan rasm / PDF fayllarni qaytadan tanlang (brauzer ularni saqlab qola olmaydi).
        </div>
    <?php endif; ?>

    <!-- enctype="multipart/form-data" – rasm va PDF yuklash uchun shart -->
    <form method="post" action="<?= e(url($formAction)) ?>" enctype="multipart/form-data" novalidate id="bookForm">
        <?= csrfField() ?>

        <label for="title" class="block text-sm font-bold mt-3 mb-1">Kitob nomi</label>
        <input type="text" id="title" name="title" value="<?= e($form['title']) ?>" required maxlength="200"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="author" class="block text-sm font-bold mt-3 mb-1">Muallifi</label>
        <input type="text" id="author" name="author" value="<?= e($form['author']) ?>" required maxlength="150"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="category" class="block text-sm font-bold mt-3 mb-1">Kategoriya</label>
        <select id="category" name="category"
                class="w-full border border-slate-300 rounded-md px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
            <?php foreach (BOOK_CATEGORIES as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $cat === $form['category'] ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Soha va janr yonma-yon (telefonda ketma-ket) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-3">
            <div>
                <label for="field" class="block text-sm font-bold mt-3 mb-1">Soha (ixtiyoriy)</label>
                <input type="text" id="field" name="field" value="<?= e($form['field']) ?>" maxlength="100"
                       list="fieldList" autocomplete="off" placeholder="Masalan: IT"
                       class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">
                <datalist id="fieldList">
                    <?php foreach ($fieldSuggestions as $opt): ?>
                        <option value="<?= e($opt) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div>
                <label for="genre" class="block text-sm font-bold mt-3 mb-1">Janr (ixtiyoriy)</label>
                <input type="text" id="genre" name="genre" value="<?= e($form['genre']) ?>" maxlength="100"
                       list="genreList" autocomplete="off" placeholder="Masalan: Roman"
                       class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">
                <datalist id="genreList">
                    <?php foreach ($genreSuggestions as $opt): ?>
                        <option value="<?= e($opt) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <p class="text-xs text-slate-500 mt-1">
            Ro'yxatdan tanlang yoki yangisini yozing. Katalogdagi filtrlar shu qiymatlardan foydalanadi.
        </p>

        <label for="copies" class="block text-sm font-bold mt-3 mb-1">Nusxalar soni (qog'oz nusxalar)</label>
        <input type="number" id="copies" name="copies" value="<?= e($form['copies']) ?>" min="1" max="1000" required
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="description" class="block text-sm font-bold mt-3 mb-1">Qisqa tavsif</label>
        <textarea id="description" name="description" rows="4" maxlength="2000"
                  class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300"><?= e($form['description']) ?></textarea>

        <label for="cover" class="block text-sm font-bold mt-3 mb-1">Muqova rasmi (ixtiyoriy)</label>
        <?php if ($isEdit && !empty($book['cover_image'])): ?>
            <img src="<?= e(url((string) $book['cover_image'])) ?>" alt="Hozirgi muqova"
                 class="h-28 rounded-md mb-2 border border-slate-200">
            <p class="text-xs text-slate-500 mb-1">Yangi rasm tanlasangiz, hozirgisi almashtiriladi.</p>
        <?php endif; ?>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"
               class="w-full text-sm">
        <p class="text-xs text-slate-500 mt-1">JPG, PNG yoki WEBP, eng ko'pi 2 MB.</p>

        <!-- ====== ONLAYN NUSXA (PDF) ====== -->
        <fieldset class="mt-6 border border-emerald-200 bg-emerald-50/50 rounded-lg p-4">
            <legend class="px-2 text-sm font-bold text-emerald-800">📄 Onlayn mutolaa uchun PDF (ixtiyoriy)</legend>

            <?php if ($currentPdf !== ''): ?>
                <!-- Hozirgi PDF haqida ma'lumot va o'chirish belgisi -->
                <div class="mb-3 rounded-md bg-white border border-emerald-200 px-3 py-2 text-sm">
                    <div class="font-semibold text-emerald-800">
                        ✅ PDF yuklangan<?= $currentPdfSize !== '' ? ' · ' . e($currentPdfSize) : '' ?>
                    </div>
                    <label class="mt-2 inline-flex items-center gap-2 text-xs text-red-700 font-semibold cursor-pointer">
                        <input type="checkbox" name="remove_pdf" value="1" class="rounded border-slate-300">
                        PDF ni o'chirish (kitob onlayn o'qib bo'lmaydigan bo'ladi)
                    </label>
                </div>
                <p class="text-xs text-slate-500 mb-1">Yangi PDF tanlasangiz, hozirgisi almashtiriladi.</p>
            <?php endif; ?>

            <input type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" class="w-full text-sm">
            <p class="text-xs text-slate-500 mt-1">
                Faqat PDF, eng ko'pi <?= (int) (MAX_PDF_SIZE / 1048576) ?> MB.
                Fayl yopiq papkada saqlanadi: uni faqat tizimga kirgan talabalar kitob sahifasi orqali o'qiydi.
            </p>
        </fieldset>

        <!-- ====== MUNDARIJA ====== -->
        <fieldset class="mt-4 border border-slate-200 rounded-lg p-4">
            <legend class="px-2 text-sm font-bold text-slate-700">📑 Mundarija (ixtiyoriy)</legend>
            <p class="text-xs text-slate-500 mb-3">
                Bo'lim nomi va u <b>PDF ning nechanchi betidan</b> boshlanishini kiriting.
                Talaba mundarijadan bosib shu betga o'tadi. Tartibni ↑ ↓ tugmalari bilan o'zgartiring.
            </p>

            <!-- Mavjud qatorlar (server chizadi, yangilarini JS qo'shadi) -->
            <div id="tocList" class="space-y-2">
                <?php foreach ($tocRows as $row): ?>
                    <?php renderTocRow($row); ?>
                <?php endforeach; ?>
            </div>

            <p id="tocEmpty" class="<?= $tocRows ? 'hidden' : '' ?> text-xs text-slate-400 italic">
                Hozircha bo'lim yo'q.
            </p>

            <button type="button" id="tocAdd"
                    class="mt-3 inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white hover:bg-slate-50 px-3 py-1.5 text-sm font-semibold text-slate-700">
                + Bo'lim qo'shish
            </button>

            <!-- Yangi qator qolipi: JS shundan nusxa oladi (ichidagi maydonlar yuborilmaydi) -->
            <template id="tocRowTpl">
                <?php renderTocRow(['title' => '', 'page' => '', 'level' => 1]); ?>
            </template>
        </fieldset>

        <button type="submit" id="bookSubmit"
                class="w-full mt-6 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-400 disabled:cursor-not-allowed
                       text-white font-semibold rounded-md py-2.5">
            <?= $isEdit ? 'Saqlash' : "Qo'shish" ?>
        </button>
    </form>
</div>

<!-- ====== MUNDARIJA MUHARRIRI VA IKKI MARTA BOSISHDAN HIMOYA (Vanilla JS) ======
     - "+ Bo'lim qo'shish": qolipdan yangi qator qo'shadi va fokusni nomiga qo'yadi
     - ↑ ↓: qatorni yuqoriga/pastga suradi (yuborilganda DOM tartibi saqlanadi)
     - ✕: qatorni olib tashlaydi
     - Enter bosilganda forma tasodifan yuborilmasin: mundarija maydonlarida Enter yangi qator qo'shadi
     - yuborilgach tugma o'chadi (ikki marta yozilmasin); katta PDF yuklanayotganda "Yuklanmoqda…" ko'rsatiladi -->
<script>
(function () {
    'use strict';

    var form   = document.getElementById('bookForm');
    var button = document.getElementById('bookSubmit');
    if (!form || !button) { return; }

    var list     = document.getElementById('tocList');
    var addBtn   = document.getElementById('tocAdd');
    var tpl      = document.getElementById('tocRowTpl');
    var emptyMsg = document.getElementById('tocEmpty');

    /** "Hozircha bo'lim yo'q" yozuvini holatga qarab ko'rsatadi/yashiradi */
    function syncEmpty() {
        if (emptyMsg && list) {
            emptyMsg.classList.toggle('hidden', list.children.length > 0);
        }
    }

    /** Qolipdan yangi qator qo'shadi; bet raqami oldingi qatordan 1 ta ko'p qilib taklif qilinmaydi (xato bo'lmasin) */
    function addRow() {
        if (!list || !tpl) { return; }

        var row = tpl.content.firstElementChild.cloneNode(true);
        list.appendChild(row);
        syncEmpty();

        var titleInput = row.querySelector('input[name="toc_title[]"]');
        if (titleInput) { titleInput.focus(); }
    }

    if (addBtn) { addBtn.addEventListener('click', addRow); }

    // Qator tugmalari (hodisa delegatsiyasi)
    if (list) {
        list.addEventListener('click', function (event) {
            var row = event.target.closest('[data-toc-row]');
            if (!row) { return; }

            if (event.target.closest('[data-toc-remove]')) {
                row.remove();
                syncEmpty();
            } else if (event.target.closest('[data-toc-up]')) {
                var prev = row.previousElementSibling;
                if (prev) { list.insertBefore(row, prev); }
            } else if (event.target.closest('[data-toc-down]')) {
                var next = row.nextElementSibling;
                if (next) { list.insertBefore(next, row); }
            }
        });

        // Mundarija maydonida Enter: formani yubormaydi, yangi qator qo'shadi
        list.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && event.target.matches('input')) {
                event.preventDefault();
                addRow();
            }
        });
    }

    // Ikki marta bosishdan himoya
    var sent = false;
    var pdfInput = document.getElementById('pdf');

    form.addEventListener('submit', function (event) {
        if (sent) {
            event.preventDefault();   // forma allaqachon yuborilgan
            return;
        }
        sent = true;
        button.disabled = true;
        button.textContent = (pdfInput && pdfInput.files && pdfInput.files.length)
            ? 'PDF yuklanmoqda… (kuting)'
            : 'Saqlanmoqda…';
    });

    // "Orqaga" tugmasi bilan qaytilganda tugma yana ishlasin
    window.addEventListener('pageshow', function () {
        sent = false;
        button.disabled = false;
        button.textContent = <?= json_encode($isEdit ? 'Saqlash' : "Qo'shish", JSON_UNESCAPED_UNICODE) ?>;
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php
/**
 * ============================================================
 *  Fayl: read_pdf.php – PDF ni himoyalangan holda uzatish
 *  Manzil: read_pdf.php?id=5   (faqat o'quvchi sahifasi / pdf.js chaqiradi)
 *
 *  Nima qiladi:
 *    1) Tizimga kirganini va rolini tekshiradi (talaba, admin, superadmin).
 *       Mehmonga 401 beradi. Bloklangan xodim currentUser() da avtomatik chiqariladi.
 *    2) PDF yo'lini bazadan oladi va uploads/books/ ichida ekanini realpath() bilan tekshiradi
 *       (yo'lni almashtirib boshqa faylni o'qishga urinishdan himoya).
 *    3) Faylni diskdan o'qib, HTTP RANGE so'rovlari bilan uzatadi: pdf.js butun faylni
 *       kutmasdan kerakli qismini so'raydi, shuning uchun katta PDF ham tez ochiladi.
 *
 *  PDF papkasi Apache'da <Directory> bilan yopilgan (403), lekin PHP faylni HTTP orqali
 *  emas, to'g'ridan-to'g'ri diskdan o'qiydi, shuning uchun bu taqiq unga ta'sir qilmaydi.
 *
 *  Sarlavhalar: "inline" (yuklab olish oynasi chiqmaydi), keshlanmaydi, nosniff.
 * ============================================================
 */

declare(strict_types=1);

// Muddati o'tgan band qilishlarni tozalash bu yerda kerak emas (har bir bo'lak so'rovida ortiqcha yuk bo'lmasin)
define('SKIP_AUTO_EXPIRE', true);

require_once __DIR__ . '/includes/auth.php';

/** Oddiy matnli xato bilan to'xtaydi (bu fayl sahifa emas, faqat PDF beradi) */
function pdfFail(int $code, string $text): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($text);
}

// ------------------------------------------------------------
// 1. RUXSAT: faqat tizimga kirgan talaba yoki kutubxonachi
// ------------------------------------------------------------
$user = currentUser();
if ($user === null) {
    pdfFail(401, 'Avval tizimga kiring.');
}
if (!in_array($user['role'], ['talaba', 'admin', 'superadmin'], true)) {
    pdfFail(403, 'Ruxsat yo\'q.');
}

// Sessiya qulfini darhol bo'shatamiz: pdf.js bir vaqtda bir nechta bo'lak so'raydi,
// qulf ushlab turilsa ular navbat kutib qoladi va sayt sekinlashadi.
session_write_close();

// Javob siqilmasin: siqilsa Content-Length va Range (206) so'rovlari buziladi
@ini_set('zlib.output_compression', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}

// ------------------------------------------------------------
// 2. KITOB VA FAYL YO'LINI TEKSHIRISH
// ------------------------------------------------------------
$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($bookId === false || $bookId === null || $bookId < 1) {
    pdfFail(400, 'Noto\'g\'ri so\'rov.');
}

try {
    $stmt = $pdo->prepare('SELECT pdf_file FROM books WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $bookId]);
    $relative = (string) ($stmt->fetchColumn() ?: '');
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] PDF yo\'lini o\'qishda xatolik: ' . $ex->getMessage());
    pdfFail(500, 'Server xatosi.');
}

if ($relative === '' || !str_starts_with($relative, 'uploads/books/') || str_contains($relative, '..')) {
    pdfFail(404, 'Bu kitobning onlayn nusxasi yo\'q.');
}

// realpath() simvolik havolalar va ".." hiylalarini ham ochib beradi
$baseDir  = realpath(__DIR__ . '/uploads/books');
$fullPath = realpath(__DIR__ . '/' . $relative);

if ($baseDir === false || $fullPath === false
    || !str_starts_with($fullPath, $baseDir . DIRECTORY_SEPARATOR)
    || !is_file($fullPath) || !is_readable($fullPath)) {
    pdfFail(404, 'Fayl topilmadi.');
}

// ------------------------------------------------------------
// 3. RANGE SO'ROVINI TAHLIL QILISH
//    "Range: bytes=0-65535" → faqat shu bo'lakni beramiz (206 Partial Content)
// ------------------------------------------------------------
$size   = (int) filesize($fullPath);
$start  = 0;
$end    = $size - 1;
$status = 200;

if ($size <= 0) {
    pdfFail(404, 'Fayl bo\'sh.');
}

if (isset($_SERVER['HTTP_RANGE'])) {
    // Faqat bitta oraliq qo'llab-quvvatlanadi (pdf.js shuni so'raydi)
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) $_SERVER['HTTP_RANGE']), $m)
        || ($m[1] === '' && $m[2] === '')) {
        header('Content-Range: bytes */' . $size);
        pdfFail(416, 'Noto\'g\'ri oraliq.');
    }

    if ($m[1] === '') {
        // "bytes=-500" → oxirgi 500 bayt
        $len = (int) $m[2];
        if ($len <= 0) {
            header('Content-Range: bytes */' . $size);
            pdfFail(416, 'Noto\'g\'ri oraliq.');
        }
        $start = max(0, $size - $len);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') {
            $end = min((int) $m[2], $end);
        }
    }

    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        pdfFail(416, 'Oraliq fayldan tashqarida.');
    }

    $status = 206;
}

// ------------------------------------------------------------
// 4. SARLAVHALAR VA FAYLNI OQIM BILAN UZATISH
// ------------------------------------------------------------
$length = $end - $start + 1;

http_response_code($status);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="kitob.pdf"');   // inline: yuklab olish oynasi chiqmaydi
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Cache-Control: private, no-store');                    // umumiy keshlarda saqlanmaydi
header('X-Content-Type-Options: nosniff');
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

// HEAD so'rovida faqat sarlavhalar yuboriladi
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

// Katta fayl uchun vaqt chegarasi va chiqish buferi o'chiriladi (xotira to'lib ketmasin)
@set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}

$fh = fopen($fullPath, 'rb');
if ($fh === false) {
    exit;
}

fseek($fh, $start);
$left = $length;

while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, (int) min(65536, $left));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
    flush();

    // Foydalanuvchi sahifani yopib qo'ygan bo'lsa, ortiqcha o'qimaymiz
    if (connection_aborted()) {
        break;
    }
}

fclose($fh);
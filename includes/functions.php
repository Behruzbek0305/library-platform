<?php
/**
 * ============================================================
 *  Fayl: includes/functions.php
 *  Vazifasi: Butun saytda ishlatiladigan yordamchi funksiyalar
 *            (XSS himoyasi, yo'naltirish, flash xabarlar, CSRF himoya)
 * ============================================================
 */

declare(strict_types=1);

// Sana va vaqt O'zbekiston vaqti bo'yicha ishlashi uchun (band qilish sanasi shu bilan yoziladi)
date_default_timezone_set('Asia/Tashkent');

// ------------------------------------------------------------
// 1. SAYT MANZILI (BASE_URL)
//    Bu – loyihaning brauzerdagi papka manzili. Avtomatik aniqlanadi:
//      .../htdocs/kutubxona_arm/index.php  → BASE_URL = '/kutubxona_arm'
//      .../htdocs/library/public/index.php → BASE_URL = '/library/public'
//      http://localhost:8000/index.php     → BASE_URL = ''
//    XAMPP'da papkangiz nomi nima bo'lishidan qat'i narp ishlaydi.
//    Qo'lda belgilamoqchi bo'lsangiz, config/db.php ichiga
//    define('BASE_URL', '/sizning-papka'); qatorini yozing.
// ------------------------------------------------------------
if (!defined('BASE_URL')) {
    $baseDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($baseDir !== '/' && $baseDir !== '') {
        $baseDir = rtrim($baseDir, '/');
    } else {
        $baseDir = '';
    }
    define('BASE_URL', $baseDir);
}

/**
 * Sayt ichidagi to'liq yo'lni qaytaradi.
 * Misol (BASE_URL='/kutubxona_arm'): url('login.php') → /kutubxona_arm/login.php
 * Misol (BASE_URL=''):               url('login.php') → /login.php
 */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Papkalar sonini qaytaradi: 0 – papka ichida emas, 1 – bitta papkada...
 * Misol: /xampp/kutubxona_arm/admin/books.php bo'lsa SCRIPT_NAME'dan
 *        "admin" papkasidan tashqariga chiqish kerakligi bilinadi.
 */
function scriptDirDepth(): int
{
    $dir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    return $dir === '' ? 0 : substr_count($dir, '/') + 1;
}

/**
 * Sahifani brauzer konsoliga xabar yozdirdi (F12 → Console).
 * Matn HTML'ga JSON ko'rinishida joylanadi, shuning uchun < / > belgilari
 * ajratib yoziladi (skript ichida </script> bo'lib ketmasligi uchun).
 */
function debugConsole(string $message): void
{
    echo '<script>console.info('
        . str_replace(['<', '>'], ['\\u003c', '\\u003e'], json_encode('[Kutubxona-ARM] ' . $message, JSON_UNESCAPED_UNICODE | JSON_HEX_AMP))
        . ');</script>' . "\n";
}

/**
 * Boshqa sahifaga yo'naltiradi va skriptni to'xtatadi.
 * Misol: redirect('index.php');
 */
function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

// ------------------------------------------------------------
// 2. XSS HIMOYASI
//    Foydalanuvchi kiritgan har qanday matnni sahifaga chiqarishdan
//    oldin shu funksiyadan o'tkazing. Misol: echo e($matn);
// ------------------------------------------------------------
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ------------------------------------------------------------
// 3. FLASH XABARLAR (bir marta ko'rsatiladigan xabarlar)
//    Turlari: success (yashil), error (qizil), info (ko'k)
// ------------------------------------------------------------

/** Keyingi sahifada ko'rsatiladigan xabarni saqlaydi */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Saqlangan xabarlarni olib, sessiyadan o'chiradi */
function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// ------------------------------------------------------------
// 4. CSRF HIMOYASI
//    Har bir POST formaga yashirin token qo'shiladi va serverda tekshiriladi.
//    Bu boshqa saytdan yuborilgan soxta so'rovlarning oldini oladi.
// ------------------------------------------------------------

/** Joriy sessiya uchun tasodifiy token yaratadi (bo'lsa, mavjudini qaytaradi) */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Formaga qo'yiladigan yashirin input: <?= csrfField() ?> */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Forma yuborgan tokenni sessiyadagi bilan solishtiradi */
function csrfVerify(mixed $token): bool
{
    return !empty($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}
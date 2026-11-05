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
//    Loyiha /srv/http/library-platform/ ichida turgani uchun
//    brauzerdagi manzil: http://localhost/library-platform/
//    Agar papka nomini o'zgartirsangiz, faqat shu qatorni o'zgartiring.
// ------------------------------------------------------------
if (!defined('BASE_URL')) {
    define('BASE_URL', '/library-platform');
}

/**
 * Sayt ichidagi to'liq yo'lni qaytaradi.
 * Misol: url('login.php') → /library-platform/login.php
 */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
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
// 1.1. HEMIS ID QOIDASI
//      HEMIS ID aniq HEMIS_ID_LENGTH ta raqamdan iborat bo'lishi shart
//      (masalan: 341231102185 – 12 ta raqam). Kam ham, ko'p ham mumkin emas.
//      Uzunlik o'zgarsa, faqat shu bitta qiymatni o'zgartiring – barcha
//      forma, tekshiruv va filtrlar shundan foydalanadi.
// ------------------------------------------------------------
const HEMIS_ID_LENGTH = 12;

// Xato matni: frontend (hemis.js) va backend bir xil matn chiqaradi
const HEMIS_ID_ERROR = "HEMIS ID ro'ppa-rosa " . HEMIS_ID_LENGTH . " ta raqamdan iborat bo'lishi shart!";

/** HEMIS ID to'g'ri formatdami? (faqat raqam, aniq 12 ta; D – oxirgi "\n" ni ham rad etadi) */
function isValidHemisId(string $hemisId): bool
{
    return (bool) preg_match('/^\d{' . HEMIS_ID_LENGTH . '}$/D', $hemisId);
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
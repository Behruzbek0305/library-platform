<?php
/**
 * ============================================================
 *  Fayl: includes/auth.php
 *  Vazifasi: Sessiya, tizimga kirish holati va ruxsatlarni boshqarish.
 *
 *  Har bir sahifaning eng boshida shu faylni ulang:
 *      require_once __DIR__ . '/includes/auth.php';
 *  (admin/ papkasidagi fayllarda: __DIR__ . '/../includes/auth.php')
 *
 *  Bu fayl o'zi config/db.php va includes/functions.php ni ham ulaydi.
 *
 *  2-bosqich: currentUser() endi talabaning fakulteti, yo'nalishi,
 *  guruhi va HEMIS ID'sini ham qaytaradi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';   // $pdo obyekti shu yerdan keladi
require_once __DIR__ . '/functions.php';      // yordamchi funksiyalar

// ------------------------------------------------------------
// 1. SESSIYANI XAVFSIZ SOZLAMALAR BILAN BOSHLASH
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,                          // brauzer yopilguncha amal qiladi
        'path'     => '/',
        'httponly' => true,                       // JavaScript cookie'ni o'qiy olmaydi (XSS'dan himoya)
        'samesite' => 'Lax',                      // boshqa saytdan keladigan so'rovlarni cheklaydi
        'secure'   => !empty($_SERVER['HTTPS']),  // HTTPS bo'lsagina "secure" yoqiladi
    ]);
    session_start();
}

// ------------------------------------------------------------
// 2. JORIY FOYDALANUVCHI
// ------------------------------------------------------------

/**
 * Tizimga kirgan foydalanuvchi ma'lumotlarini bazadan olib qaytaradi.
 * Kirmagan bo'lsa null qaytaradi. Parol xeshi hech qachon qaytarilmaydi.
 */
function currentUser(): ?array
{
    global $pdo;
    static $user = false;           // bir so'rov ichida bazaga bir marta murojaat qilish uchun

    if ($user !== false) {
        return $user;
    }

    $user = null;

    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, role, faculty, direction, group_name, hemis_id
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $row = $stmt->fetch();

        if ($row) {
            $user = $row;
        } else {
            // Foydalanuvchi bazadan o'chirilgan bo'lsa, sessiyani tozalaymiz
            unset($_SESSION['user_id']);
        }
    }

    return $user;
}

/** Foydalanuvchi tizimga kirganmi? */
function isLoggedIn(): bool
{
    return currentUser() !== null;
}

/** Foydalanuvchi kutubxonachi (admin) mi? */
function isAdmin(): bool
{
    $user = currentUser();
    return $user !== null && $user['role'] === 'admin';
}

// ------------------------------------------------------------
// 3. KIRISH VA CHIQISH
// ------------------------------------------------------------

/** Foydalanuvchini tizimga kiritadi (sessiya ID'si yangilanadi – session fixation'dan himoya) */
function loginUser(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

/** Foydalanuvchini tizimdan chiqaradi va sessiyani to'liq yo'q qiladi */
function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    session_destroy();
}

// ------------------------------------------------------------
// 4. RUXSATLARNI TEKSHIRISH (sahifa boshida chaqiriladi)
// ------------------------------------------------------------

/** Faqat tizimga kirganlar uchun sahifalarda ishlatiladi */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'Avval tizimga kiring.');
        redirect('login.php');
    }
}

/**
 * Faqat talabalar uchun sahifalarda ishlatiladi (masalan: "Mening kabinetim").
 * Kirmagan foydalanuvchi – kirish sahifasiga, kutubxonachi – bosh sahifaga yo'naltiriladi.
 */
function requireStudent(): void
{
    requireLogin();

    if (isAdmin()) {
        setFlash('info', 'Kabinet talabalar uchun. Kutubxonachi sifatida admin paneldan foydalaning.');
        redirect('admin/index.php');
    }
}

/** Faqat kutubxonachi (admin) uchun sahifalarda ishlatiladi */
function requireAdmin(): void
{
    if (!isAdmin()) {
        setFlash('error', 'Bu sahifa faqat kutubxonachi uchun.');
        redirect('index.php');
    }
}
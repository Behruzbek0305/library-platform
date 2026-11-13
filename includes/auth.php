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
 * YANGI: akkaunt bloklangan bo'lsa (is_blocked = 1), sessiya darhol yopiladi.
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
            'SELECT id, name, email, role, is_blocked, faculty, direction, group_name, hemis_id
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $row = $stmt->fetch();

        if ($row && (int) $row['is_blocked'] === 1) {
            // Bloklangan xodim: sessiya tozalanadi va xabar beriladi
            unset($_SESSION['user_id']);
            setFlash('error', 'Akkauntingiz vaqtincha bloklangan. Kutubxona rahbariga murojaat qiling.');
        } elseif ($row) {
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

// ------------------------------------------------------------
// ROLLAR VA RUXSATLAR
//   talaba     – oddiy foydalanuvchi
//   admin      – kutubxonachi (band qilishlar, kitoblar, talabalar)
//   superadmin – bosh admin (admin huquqlari + xodimlarni boshqarish)
// Rol har safar currentUser() orqali BAZADAN olinadi (sessiyaga ishonilmaydi),
// shuning uchun bazada rol o'zgarsa, eski sessiya eski huquq bilan qolib ketmaydi.
// ------------------------------------------------------------

/** Foydalanuvchi Superadmin (Bosh admin) mi? */
function isSuperAdmin(): bool
{
    $user = currentUser();
    return $user !== null && $user['role'] === 'superadmin';
}

/** Foydalanuvchi admin YOKI superadmin mi? (admin panelga kirish huquqi) */
function isAdminOrSuperAdmin(): bool
{
    $user = currentUser();
    return $user !== null && in_array($user['role'], ['admin', 'superadmin'], true);
}

/**
 * Eski kod bilan moslik: loyihada isAdmin() ko'p joyda ishlatilgan.
 * Endi u superadminni ham "admin" deb qabul qiladi, aks holda
 * superadmin admin sahifalarga kira olmay qolardi.
 */
function isAdmin(): bool
{
    return isAdminOrSuperAdmin();
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

/** Faqat kutubxonachi (admin yoki superadmin) uchun sahifalarda ishlatiladi */
function requireAdmin(): void
{
    if (!isAdminOrSuperAdmin()) {
        setFlash('error', 'Bu sahifa faqat kutubxonachi uchun.');
        redirect('index.php');
    }
}

/**
 * Faqat Superadmin uchun sahifalarda ishlatiladi (masalan admin/staff.php).
 * Oddiy admin – admin panelga, boshqalar – bosh sahifaga qaytariladi.
 */
function requireSuperAdmin(): void
{
    if (!isSuperAdmin()) {
        setFlash('error', 'Bu sahifa faqat Bosh admin (superadmin) uchun.');
        redirect(isAdminOrSuperAdmin() ? 'admin/index.php' : 'index.php');
    }
}
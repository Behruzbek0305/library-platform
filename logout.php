<?php
/**
 * ============================================================
 *  Fayl: logout.php
 *  Vazifasi: Tizimdan chiqish.
 *  Faqat POST so'rov va to'g'ri CSRF token bilan ishlaydi
 *  (menyudagi "Chiqish" tugmasi shuni yuboradi).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Faqat POST va to'g'ri token bo'lsa chiqaramiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfVerify($_POST['csrf_token'] ?? null)) {
    logoutUser();

    // Sessiya yo'q qilingani uchun flash xabar uchun yangi sessiya ochamiz
    session_start();
    setFlash('success', 'Tizimdan chiqdingiz.');
}

redirect('index.php');
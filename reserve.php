<?php
/**
 * ============================================================
 *  Fayl: reserve.php – Kitobni band qilish (faqat POST)
 *  Funksional talab №5 (Texnik topshiriq, 4-band).
 *
 *  Tekshiruvlar: tizimga kirgan talaba, to'g'ri CSRF token,
 *  talaba ma'lumotlari to'liq (2-bosqich), kitob mavjud,
 *  bo'sh nusxa bor, shu kitob avval band qilinmagan.
 *  Ikki talaba bir vaqtda oxirgi nusxani band qilmasligi uchun
 *  tranzaksiya va "FOR UPDATE" qulfi ishlatiladi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';
require_once __DIR__ . '/includes/student_helpers.php';

// 1) Faqat POST so'rov qabul qilinadi
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('catalog.php');
}

// 2) Faqat tizimga kirganlar band qila oladi
requireLogin();
$user = currentUser();

if ($user['role'] !== 'talaba') {
    setFlash('error', 'Kitoblarni faqat talabalar band qila oladi.');
    redirect('catalog.php');
}

// 3) CSRF token
if (!csrfVerify($_POST['csrf_token'] ?? null)) {
    setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
    redirect('catalog.php');
}

// 3.1) Talaba ma'lumotlari to'liqmi? (to'liq ism, fakultet, yo'nalish, guruh, HEMIS ID)
//      To'liq bo'lmasa, avval profil sahifasida to'ldiradi.
if (!isStudentProfileComplete($user)) {
    setFlash('error', "Kitob band qilishdan oldin ma'lumotlaringizni (fakultet, yo'nalish, guruh, HEMIS ID) to'ldiring.");
    redirect('profile.php');
}

// 4) Kitob ID'sini tekshiramiz
$bookId = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
if ($bookId === false || $bookId < 1) {
    setFlash('error', "Noto'g'ri so'rov.");
    redirect('catalog.php');
}

// 4.1) Olib ketish muddati (1, 2 yoki 3 kun, uzog'i 3 kun)
$pickupDays = filter_var($_POST['pickup_days'] ?? 1, FILTER_VALIDATE_INT);
if ($pickupDays === false || $pickupDays < 1 || $pickupDays > MAX_PICKUP_DAYS) {
    $pickupDays = 1;
}
$pickupDeadline = date('Y-m-d H:i:s', strtotime("+{$pickupDays} days"));

$bookPage = 'book.php?id=' . $bookId;

try {
    // Tranzaksiya boshlanadi
    $pdo->beginTransaction();

    // Kitob qatorini qulflaymiz: boshqa so'rov tugaguncha kutib turadi
    $stmt = $pdo->prepare('SELECT id, title, copies FROM books WHERE id = :id FOR UPDATE');
    $stmt->execute([':id' => $bookId]);
    $book = $stmt->fetch();

    if (!$book) {
        $pdo->rollBack();
        setFlash('error', 'Bunday kitob topilmadi.');
        redirect('catalog.php');
    }

    // Hozir band bo'lgan nusxalar soni
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations
         WHERE book_id = :book_id AND status IN ('kutilmoqda', 'tasdiqlandi')"
    );
    $stmt->execute([':book_id' => $bookId]);
    $taken = (int) $stmt->fetchColumn();

    if ((int) $book['copies'] - $taken <= 0) {
        $pdo->rollBack();
        setFlash('error', "Bu kitob hozir bo'sh emas.");
        redirect($bookPage);
    }

    // Shu foydalanuvchi bu kitobni allaqachon band qilganmi?
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations
         WHERE user_id = :user_id AND book_id = :book_id
           AND status IN ('kutilmoqda', 'tasdiqlandi')"
    );
    $stmt->execute([':user_id' => $user['id'], ':book_id' => $bookId]);

    if ((int) $stmt->fetchColumn() > 0) {
        $pdo->rollBack();
        setFlash('error', 'Siz bu kitobni allaqachon band qilgansiz.');
        redirect($bookPage);
    }

    // Band qilishni yozamiz (holati: kutilmoqda – kutubxonachi tasdiqlaydi)
    $stmt = $pdo->prepare(
        "INSERT INTO reservations (user_id, book_id, reserved_date, pickup_days, pickup_deadline, status)
         VALUES (:user_id, :book_id, :reserved_date, :pickup_days, :pickup_deadline, 'kutilmoqda')"
    );
    $stmt->execute([
        ':user_id'         => $user['id'],
        ':book_id'         => $bookId,
        ':reserved_date'   => date('Y-m-d'),
        ':pickup_days'     => $pickupDays,
        ':pickup_deadline' => $pickupDeadline,
    ]);

    $pdo->commit();

    $deadlineText = date('d.m.Y H:i', strtotime($pickupDeadline));
    setFlash('success', "«" . $book['title'] . "» band qilindi! Olib ketish muddati: {$pickupDays} kun ({$deadlineText} gacha). Kutubxonachi tasdiqlashini kuting.");
    redirect('cabinet.php');

} catch (PDOException $ex) {
    // Xatolik bo'lsa, tranzaksiyani bekor qilamiz
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Kutubxona-ARM] Band qilishda xatolik: ' . $ex->getMessage());
    setFlash('error', "Band qilishda xatolik yuz berdi. Keyinroq urinib ko'ring.");
    redirect($bookPage);
}
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
 *
 *  YANGI: har bir band qilishga 6 belgili (harf + raqam) noyob kod (pickup_code)
 *  beriladi. Talaba uni kutubxonada ko'rsatadi, kutubxonachi admin
 *  panelda shu kod bilan buyurtmani tez topadi.
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
    $pdo->beginTransaction();

    // Kitob qatorini qulflaymiz va bo'sh nusxalar sonini TO'G'RIDAN-TO'G'RI books jadvalidan olamiz
    $stmt = $pdo->prepare('SELECT id, title, available_copies FROM books WHERE id = :id FOR UPDATE');
    $stmt->execute([':id' => $bookId]);
    $book = $stmt->fetch();

    if (!$book) {
        $pdo->rollBack();
        setFlash('error', 'Bunday kitob topilmadi.');
        redirect('catalog.php');
    }

    // Bo'sh nusxa yo'q bo'lsa – band qilib bo'lmaydi
    if ((int) $book['available_copies'] <= 0) {
        $pdo->rollBack();
        setFlash('error', "Bu kitob hozir bo'sh emas.");
        redirect($bookPage);
    }

    // Shu foydalanuvchi bu kitobni allaqachon band qilgan yoki qo'lida ushlab turibdimi?
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations
         WHERE user_id = :user_id AND book_id = :book_id
           AND status IN ('kutilmoqda', 'tasdiqlandi', 'berilgan')"
    );
    $stmt->execute([':user_id' => $user['id'], ':book_id' => $bookId]);

    if ((int) $stmt->fetchColumn() > 0) {
        $pdo->rollBack();
        setFlash('error', 'Siz bu kitobni allaqachon band qilgansiz.');
        redirect($bookPage);
    }

    // Band qilishni yozamiz (holati: kutilmoqda – kutubxonachi tasdiqlaydi).
    // pickup_code: 6 belgili (harf + raqam) noyob kod. Kamdan-kam hollarda ikki talaba bir
    // soniyada bir xil kod olib qolsa, UNIQUE kalit xato beradi (SQLSTATE 23000) –
    // shunda yangi kod yaratib, INSERT'ni qayta urinamiz (eng ko'pi 5 marta).
    // InnoDB'da xato bergan INSERT tranzaksiyani buzmaydi, shuning uchun davom etish mumkin.
    $insert = $pdo->prepare(
        "INSERT INTO reservations (user_id, book_id, reserved_date, pickup_days, pickup_deadline, pickup_code, status)
         VALUES (:user_id, :book_id, :reserved_date, :pickup_days, :pickup_deadline, :pickup_code, 'kutilmoqda')"
    );

    $pickupCode = '';
    $inserted   = false;

    for ($attempt = 0; $attempt < 5 && !$inserted; $attempt++) {
        $pickupCode = generateReservationCode($pdo);

        try {
            $insert->execute([
                ':user_id'         => $user['id'],
                ':book_id'         => $bookId,
                ':reserved_date'   => date('Y-m-d'),
                ':pickup_days'     => $pickupDays,
                ':pickup_deadline' => $pickupDeadline,
                ':pickup_code'     => $pickupCode,
            ]);
            $inserted = true;
        } catch (PDOException $ex) {
            // Faqat "kod takrorlandi" xatosida qayta urinamiz, boshqa xatolik – yuqoriga uzatiladi
            if ((string) $ex->getCode() !== '23000') {
                throw $ex;
            }
        }
    }

    if (!$inserted) {
        $pdo->rollBack();
        setFlash('error', "Band qilish kodini yaratib bo'lmadi. Qayta urinib ko'ring.");
        redirect($bookPage);
    }

    // Bo'sh nusxani 1 taga kamaytiramiz (qator qulflangan, shuning uchun xavfsiz)
    if (!decrementAvailableCopies($pdo, $bookId)) {
        $pdo->rollBack();
        setFlash('error', "Bu kitob hozir bo'sh emas.");
        redirect($bookPage);
    }

    $pdo->commit();

    $deadlineText = date('d.m.Y H:i', strtotime($pickupDeadline));
    setFlash('success',
        "«" . $book['title'] . "» band qilindi! Sizning kodingiz: " . formatReservationCode($pickupCode)
        . ". Kutubxonada shu kodni ko'rsating. Olib ketish muddati: {$pickupDays} kun ({$deadlineText} gacha)."
    );
    redirect('cabinet.php');

} catch (PDOException | RuntimeException $ex) {
    // Xatolik bo'lsa, tranzaksiyani bekor qilamiz
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Kutubxona-ARM] Band qilishda xatolik: ' . $ex->getMessage());
    setFlash('error', "Band qilishda xatolik yuz berdi. Keyinroq urinib ko'ring.");
    redirect($bookPage);
}
<?php
/**
 * ============================================================
 *  Fayl: admin/process-offline.php – OFFLAYN BERISH (faqat POST)
 *  2-bosqich: kitob modalidagi «Offlayn Registratsiya qilish»
 *  formasini qabul qiluvchi backend mantiq.
 *
 *  Formadan keladigan maydonlar:
 *      book_id, hemis_id, full_name/student_name (FIO), email/student_email,
 *      faculty, user_group/group_name (Guruh), duration_days/loan_days
 *      (1/2/3/5/10 kun), csrf_token, from (modal | page).
 *      Eski nomlar ham qabul qilinadi (moslik uchun).
 *
 *  Bajariladigan ishlar:
 *   1. TALABANI IZLASH/YARATISH – users jadvalidan hemis_id yoki
 *      email bo'yicha qidiriladi; topilmasa yangi talaba
 *      (role='talaba') yaratiladi va user_id olinadi.
 *   2. BAND QILISHNI SAQLASH – reservations ga yozuv:
 *      status='tasdiqlandi', picked_up_at=NOW(), is_offline=1,
 *      due_date = NOW() + X kun (loan_days), expires_at = due_date,
 *      served_by = joriy kutubxonachi ID si ($_SESSION['user_id']).
 *   3. REAL-TIME KAMAYTIRISH – books.available_copies ni 1 taga
 *      kamaytirish: UPDATE ... AND available_copies > 0 (bu jadvalda
 *      ustun bo'lmasa avtomatik SKIP qilinadi – nusxa soni BOOK_SELECT
 *      orqali hisoblab keliladi, shuning uchun natija baribir to'g'ri).
 *
 *  Mantiq loyihadagi admin/index.php "approve" amali bilan bir xil:
 *  kitob faqat kutubxonachi admin panelda «Qaytarildi» tugmasini
 *  bossa qaytilgan hisoblanadi – avtomatik hech qachon!
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

// ------------------------------------------------------------
// 0. RUXSATLAR VA SO'ROV TURI
// ------------------------------------------------------------

// Faqat POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('catalog.php');
}

$from     = (string) ($_POST['from'] ?? 'modal');
$backUrl  = $from === 'page' && isset($_POST['book_id'])
              ? 'book.php?id=' . max(1, (int) $_POST['book_id'])
              : 'catalog.php';

// Faqat tizimga kirgan kutubxonachi (admin)
requireAdmin();

// CSRF token
if (!csrfVerify($_POST['csrf_token'] ?? null)) {
    setFlash('error', "Sessiya muddati tugagan. Qayta urinib ko'ring.");
    redirect($backUrl);
}

// ------------------------------------------------------------
// 1. KELGAN MA'LUMOTLARNI TOZALASH / TEKSHIRISH
// ------------------------------------------------------------
$bookId    = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
$hemisId   = normalizeHemisId($_POST['hemis_id'] ?? '');            // faqat raqamlar
$fullName  = trim((string) ($_POST['student_name'] ?? ''));         // FIO
$email     = strtolower(trim((string) ($_POST['email'] ?? $_POST['student_email'] ?? '')));
$faculty   = trim((string) ($_POST['faculty'] ?? ''));
$userGroup = trim((string) ($_POST['user_group'] ?? $_POST['group_name'] ?? '')); // Guruh
$days      = offlineLoanDays($_POST['duration_days'] ?? $_POST['loan_days'] ?? null, 3); // 1/2/3/5/10

$errors = [];

if ($bookId === false || $bookId < 1) {
    $errors[] = "Kitob noto'g'ri tanlangan.";
}
if ($hemisId === '' || mb_strlen($hemisId) < 4) {
    $errors[] = 'Talaba HEMIS ID sini kiriting (kamida 4 ta raqam).';
}
if ($fullName === '' || mb_strlen($fullName) < 3) {
    $errors[] = 'Talaba FIO sini kiriting.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Talaba Email adresi noto\'g\'ri.';
}
if ($faculty === '') {
    $errors[] = 'Fakultetni kiriting.';
}
if ($userGroup === '') {
    $errors[] = 'Guruhni kiriting.';
}

if ($errors) {
    setFlash('error', implode(' ', $errors));
    redirect($backUrl);
}

// ------------------------------------------------------------
// 2. ASOSIY ISH – TRANZAKSIYA ICIDA
// ------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // --- 2.1. Kitobni qulflab olamiz va bo'sh nusxalar sonini books jadvalidan o'qiymiz ---
    $stmt = $pdo->prepare('SELECT id, title, copies, available_copies FROM books WHERE id = :id FOR UPDATE');
    $stmt->execute([':id' => $bookId]);
    $book = $stmt->fetch();

    if (!$book) {
        $pdo->rollBack();
        setFlash('error', 'Bunday kitob topilmadi.');
        redirect($backUrl);
    }

    // --- 2.2. Bo'sh nusxa yo'q bo'lsa, offlayn berib bo'lmaydi ---
    if ((int) $book['available_copies'] <= 0) {
        $pdo->rollBack();
        setFlash('error',
            '«' . $book['title'] . '» uchun hozir bo\'sh nusxa yo\'q '
            . '(jami ' . (int) $book['copies'] . ' ta, barchasi band yoki talabada). '
            . 'Avval nusxani qaytarib oling yoki kitobga yangi nusxa qo\'shing.');
        redirect($backUrl);
    }

    // --- 2.3. TALABANI IZLASH: avval HEMIS ID, keyin EMAIL bo'yicha ---
    $student = null;

    if ($hemisId !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, name, email, role FROM users WHERE hemis_id = :hemis LIMIT 1"
        );
        $stmt->execute([':hemis' => $hemisId]);
        $student = $stmt->fetch();
    }

    if (!$student && $email !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, name, email, role FROM users WHERE email = :email LIMIT 1"
        );
        $stmt->execute([':email' => $email]);
        $student = $stmt->fetch();
    }

    if ($student) {
        // Topilgan akkaunt talaba emasmi? (masalan, kutubxonachi o'zini yozib qo'ymasin)
        if ($student['role'] !== 'talaba') {
            $pdo->rollBack();
            setFlash('error',
                'Bu HEMIS ID / Email tizimda talaba sifatida emas, balki "'
                . ($student['role'] === 'admin' ? 'kutubxonachi' : $student['role'])
                . '" sifatida ro\'yxatdan o\'tgan. Boshqa ma\'lumot kiritinng.');
            redirect($backUrl);
        }
        $userId = (int) $student['id'];
        $isNewStudent = false;
    } else {
        // --- 2.4. YO'Q BO'LSA: YANGI TALABA YARATISH (role = 'talaba') ---
        // Akkaunt parol bilan ochilmaydi – talaba keyinroq "Parolni tiklash"
        // orqali o'zi parol qo'yadi. Parol maydoni NOT NULL bo'lgani uchun
        // inkor etilgan (isset() = false) tasodifiy hash saqlanadi.
        $stmt = $pdo->prepare(
            "INSERT INTO users (name, email, password, role, faculty, direction, group_name, hemis_id)
             VALUES (:name, :email, :password, 'talaba', :faculty, NULL, :grp, :hemis)"
        );
        $stmt->execute([
            ':name'     => $fullName,
            ':email'    => $email,
            ':password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            ':faculty'  => $faculty,
            ':grp'      => $userGroup,
            ':hemis'    => $hemisId !== '' ? $hemisId : null,
        ]);
        $userId = (int) $pdo->lastInsertId();
        $isNewStudent = true;
    }

    // --- 2.5. Shu talaba bu kitobni allaqachon olmaqtimi? ---
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM reservations
         WHERE user_id = :user_id AND book_id = :book_id
           AND status IN ('kutilmoqda', 'tasdiqlandi', 'berilgan')"
    );
    $stmt->execute([':user_id' => $userId, ':book_id' => $bookId]);

    if ((int) $stmt->fetchColumn() > 0) {
        $pdo->rollBack();
        setFlash('error',
            'Bu talabaning «' . $book['title'] . '» kitobi hozircha qo\'lida '
            . 'yoki band qilingan turibdi. Avval uni admin panelda «Qaytarildi» deb belgilang.');
        redirect($backUrl);
    }

    // --- 2.6. BAND QILISHNI SAQLASH ---
    // Vaqt PHP'dan olinadi (Asia/Tashkent) – bazaning soat zonasi bilan
    // chalkashlik bo'lmasligi uchun. due_date = NOW() + X kun.
    $now      = date('Y-m-d H:i:s');
    $dueDate  = date('Y-m-d H:i:s', strtotime("+{$days} days"));

    // Xizmat ko'rsatgan xodim: hozir tizimda turgan kutubxonachi (requireAdmin() yuqorida tekshirgan)
    $staffId  = (int) ($_SESSION['user_id'] ?? 0);

    // Bazada expires_at ustuni bormi? (so'ralgan: expires_at = NOW() + INTERVAL X DAY)
    $col = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = 'reservations'
            AND COLUMN_NAME  = 'expires_at'"
    )->fetchColumn();
    $hasExpiresAt = (int) $col > 0;

    if ($hasExpiresAt) {
        $sql = "INSERT INTO reservations
                    (user_id, book_id, reserved_date, pickup_days, pickup_deadline,
                     status, picked_up_at, due_date, expires_at, loan_days, is_offline, served_by)
                VALUES
                    (:user_id, :book_id, :reserved_date, :pickup_days, :pickup_deadline,
                     'tasdiqlandi', :picked_up_at, :due_date, :expires_at, :loan_days, 1, :served_by)";
    } else {
        $sql = "INSERT INTO reservations
                    (user_id, book_id, reserved_date, pickup_days, pickup_deadline,
                     status, picked_up_at, due_date, loan_days, is_offline, served_by)
                VALUES
                    (:user_id, :book_id, :reserved_date, :pickup_days, :pickup_deadline,
                     'tasdiqlandi', :picked_up_at, :due_date, :loan_days, 1, :served_by)";
    }

    $params = [
        ':user_id'         => $userId,
        ':book_id'         => $bookId,
        ':reserved_date'   => date('Y-m-d'),
        ':pickup_days'     => $days,          // rasmiy sxemada MAYJORIY maydon
        ':pickup_deadline' => $dueDate,       // admin panel taymeri shu ustunni o'qiydi
        ':picked_up_at'    => $now,           // NOW() – kitob qo'lga berildi
        ':due_date'        => $dueDate,       // NOW() + X kun – qaytarish muddati
        ':loan_days'       => $days,          // 1 / 2 / 3 / 5 / 10
        ':served_by'       => $staffId > 0 ? $staffId : null,   // xizmat ko'rsatgan kutubxonachi
    ];

    // DIQQAT: ATTR_EMULATE_PREPARES = false bo'lgani uchun har bir nomli parametr
    // so'rovda faqat BIR marta uchrashi kerak – shu sababli :expires_at alohida beriladi.
    if ($hasExpiresAt) {
        $params[':expires_at'] = $dueDate;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservationId = (int) $pdo->lastInsertId();

    // --- 2.7. REAL-TIME: bo'sh nusxani kamaytirish ---
    // books.available_copies ustuni endi bazada MAVJUD (migrations/006_available_copies.sql):
    //   UPDATE books SET available_copies = available_copies - 1 WHERE id = ? AND available_copies > 0
    // Ustun bo'lmasa (migratsiya hali ishga tushirilmagan eski baza) funksiya
    // muammosiz true qaytaradi – BOOK_SELECT dinamik hisob bilan ishlaydi.
    if (!decrementAvailableCopies($pdo, $bookId)) {
        // Raketa bo'sh edi – berilmaydi (INSERT ham ortga qaytariladi)
        $pdo->rollBack();
        setFlash('error', 'Kechirasiz, ayni paytda bu kitobning bo\'sh nusxasi qolmadi.');
        redirect($backUrl);
    }

    $pdo->commit();

    // --- 2.8. MUVAFFAQIYAT HABARI ---
    $who = $fullName . ($hemisId !== '' ? " (HEMIS: {$hemisId})" : '');
    setFlash('success',
        '✅ «' . $book['title'] . '» kitob ' . $who . ' ga ' . $days . ' kunga berildi.'
        . ($isNewStudent ? ' Yangi talaba tizimga qo\'shildi.' : '')
        . ' Qaytarish muddati: ' . date('d.m.Y H:i', strtotime($dueDate))
        . '. Muddat tugagach kitob avtomatik qaytgan hisoblanmaydi —'
        . ' talaba kitobni topshirganda admin panelda «Qaytarildi» tugmasini bosing.'
    );

    error_log(sprintf(
        '[Kutubxona-ARM] Offlayn berildi: reservation #%d, book #%d, user #%d, %d kun, admin #%d',
        $reservationId, $bookId, $userId, $days, (int) ($_SESSION['user_id'] ?? 0)
    ));

    redirect('admin/index.php?status_filter=tasdiqlandi');

} catch (PDOException $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Email yoki HEMIS ID band bo'lib qolganmi? (UNIQUE key)
    if ($ex->getCode() === '23000' || str_contains($ex->getMessage(), 'Duplicate')) {
        setFlash('error',
            'Bu Email yoki HEMIS ID tizimda allaqachon mavjud, lekin ma\'lumotlar mos kelmadi. '
            . 'Tekshirib, qayta urinib ko\'ring yoki talabani mavjud akkaunti orqali tanlang.');
    } else {
        error_log('[Kutubxona-ARM] Offlayn berishda xatolik: ' . $ex->getMessage());
        setFlash('error', 'Offlayn registratsiyada xatolik yuz berdi. Keyinroq urinib ko\'ring.');
    }
    redirect($backUrl);
}
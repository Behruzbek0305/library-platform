<?php
/**
 * ============================================================
 *  KUTUBXONA – AXBOROT RESURS MARKAZI (Kutubxona - ARM)
 *  Fayl: config/db.php – MySQL bazasiga xavfsiz ulanish (PDO)
 * ============================================================
 *
 *  Ishlatilishi (boshqa fayllarda):
 *      require_once __DIR__ . '/config/db.php';
 *      $stmt = $pdo->prepare('SELECT * FROM books WHERE id = :id');
 *      $stmt->execute([':id' => $id]);
 *
 *  Eslatma: SQL so'rovlarida foydalanuvchi ma'lumotlarini HECH QACHON
 *  to'g'ridan-to'g'ri qo'shmang – faqat prepared statements ishlating
 *  (SQL Injection'dan himoya).
 */

// Qat'iy turlarni tekshirish rejimi (PHP 8.x uchun yaxshi amaliyot)
declare(strict_types=1);

// ------------------------------------------------------------
// 1. BAZA SOZLAMALARI
//    XAMPP'ning standart sozlamalari: foydalanuvchi "root", parol bo'sh.
//    Haqiqiy serverda albatta o'zgartiring!
// ------------------------------------------------------------
define('DB_HOST', 'localhost');        // Baza serveri manzili
define('DB_NAME', 'kutubxona_arm');    // Baza nomi (database.sql da yaratilgan)
define('DB_USER', 'root');             // Baza foydalanuvchisi
define('DB_PASS', 'kenjayev0305');                 // Baza paroli (XAMPP'da bo'sh)
define('DB_CHARSET', 'utf8mb4');       // Kodirovka (o'zbek harflari va emoji uchun)

// ------------------------------------------------------------
// 2. DSN (ulanish manzili) VA PDO SOZLAMALARI
// ------------------------------------------------------------
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

$options = [
    // Xatolik bo'lsa PDOException tashlansin (try-catch bilan ushlash uchun)
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Natijalar sukut bo'yicha assotsiativ massiv ko'rinishida qaytsin
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Haqiqiy prepared statements (emulyatsiya o'chirilgan) – SQL Injection'dan yaxshiroq himoya
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// ------------------------------------------------------------
// 3. ULANISH (Try-Catch bilan)
// ------------------------------------------------------------
try {
    // Bazaga ulanish obyektini yaratamiz – boshqa fayllarda $pdo nomi bilan ishlatiladi
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Haqiqiy xatolik matnini faqat serverning log fayliga yozamiz
    // (parol va baza ma'lumotlari foydalanuvchiga ko'rinib qolmasligi uchun)
    error_log('[Kutubxona-ARM] Bazaga ulanishda xatolik: ' . $e->getMessage());

    // Foydalanuvchiga esa umumiy, xavfsiz xabar ko'rsatamiz
    http_response_code(500);
    exit('Kechirasiz, ma\'lumotlar bazasiga ulanib bo\'lmadi. Iltimos, keyinroq urinib ko\'ring.');
}
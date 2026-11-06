<?php
/**
 * ============================================================
 *  Fayl: scripts/expire_reservations.php
 *  Vazifasi: Cron yoki CLI orqali olib ketish muddati o'tgan
 *            band qilishlarni avtomatik rad etish.
 *
 *  Ishlatish:
 *      php scripts/expire_reservations.php
 *
 *  Cron sozlamasi (masalan har soatda):
 *      0 * * * * php /srv/http/library-platform/scripts/expire_reservations.php >> /var/log/kutubxona_cron.log 2>&1
 * ============================================================
 */

declare(strict_types=1);

// Faqat buyruq satridan (cron/CLI) ishlaydi – brauzer orqali ochib bo'lmaydi
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Bu skript faqat buyruq satridan ishga tushiriladi.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/book_helpers.php';

// DIQQAT: book_helpers.php CLI rejimida avtomatik tozalamaydi, shuning uchun
// bu yerdagi chaqiruv haqiqiy (rad etilgan) sonni qaytaradi.
$count = expireOverdueReservations($pdo);

$time = date('Y-m-d H:i:s');
echo "[$time] Muddati o'tgan band qilishlar tekshirildi. Rad etilganlar soni: $count\n";
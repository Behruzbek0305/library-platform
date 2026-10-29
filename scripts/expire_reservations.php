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

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/book_helpers.php';

$count = expireOverdueReservations($pdo);

$time = date('Y-m-d H:i:s');
echo "[$time] Muddati o'tgan band qilishlar tekshirildi. Rad etilganlar soni: $count\n";


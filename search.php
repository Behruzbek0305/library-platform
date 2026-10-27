<?php
/**
 * ============================================================
 *  Fayl: search.php – Qidiruv tavsiyalari (JSON)
 *  Manzil: search.php?q=qodi
 *  Vazifasi: yozish paytida eng mos 6 ta kitobni JSON ko'rinishida qaytaradi.
 *  Uni assets/js/live-search.js chaqiradi.
 *
 *  Sessiya ochilmaydi: bir vaqtdagi tez-tez so'rovlar bir-birini kutib qolmasin.
 *  Katalog ochiq ma'lumot, shuning uchun tizimga kirish shart emas.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/book_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Qidiruv so'zi (eng ko'pi 100 belgi)
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

if ($q === '') {
    echo '[]';
    exit;
}

// LIKE belgilari (% va _) oddiy harf sifatida qidirilishi uchun "escape" qilinadi
$escaped = addcslashes($q, '%_\\');

try {
    // Eng mosi tepada: avval nomi shu so'z bilan boshlanadiganlar, keyin qolganlari
    $stmt = $pdo->prepare(
        BOOK_SELECT . '
         WHERE b.title LIKE :q_title OR b.author LIKE :q_author
         ORDER BY (b.title LIKE :q_prefix) DESC, b.title ASC
         LIMIT 6'
    );
    $stmt->execute([
        ':q_title'  => '%' . $escaped . '%',
        ':q_author' => '%' . $escaped . '%',
        ':q_prefix' => $escaped . '%',
    ]);

    $result = [];
    foreach ($stmt->fetchAll() as $book) {
        $style = categoryStyle((string) $book['category']);

        $result[] = [
            'id'        => (int) $book['id'],
            'title'     => (string) $book['title'],
            'author'    => (string) $book['author'],
            'category'  => (string) $book['category'],
            'emoji'     => $style['emoji'],
            'available' => (int) $book['available'],
            'url'       => url('book.php?id=' . (int) $book['id']),
            'cover'     => !empty($book['cover_image']) ? url((string) $book['cover_image']) : null,
        ];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Qidiruv tavsiyalarida xatolik: ' . $ex->getMessage());
    http_response_code(500);
    echo '[]';
}
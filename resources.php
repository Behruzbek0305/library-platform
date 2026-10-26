<?php
/**
 * ============================================================
 *  Fayl: resources.php – Elektron resurslar
 *  Ko'rsatadi: PDF fayllar va tashqi havolalar ro'yxati.
 *  Funksional talab №6 (Texnik topshiriq, 4-band) – faqat talabalar uchun.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Faqat tizimga kirgan talabalar ko'ra oladi
requireStudent();

/**
 * Resurs manzilini xavfsiz havolaga aylantiradi.
 *  - http:// yoki https:// bilan boshlansa – tashqi havola
 *  - oddiy fayl yo'li bo'lsa (masalan uploads/resources/x.pdf) – sayt ichidagi fayl
 *  - boshqa hamma narsa (masalan javascript:...) rad etiladi va null qaytadi
 */
function resourceLink(string $fileUrl): ?string
{
    $fileUrl = trim($fileUrl);

    if (preg_match('#^https?://#i', $fileUrl)) {
        return $fileUrl;
    }

    if (preg_match('#^[A-Za-z0-9_\-./]+$#', $fileUrl) && !str_contains($fileUrl, '..')) {
        return url($fileUrl);
    }

    return null;
}

// ------------------------------------------------------------
// MA'LUMOTLARNI OLISH
// ------------------------------------------------------------
$resources = [];
try {
    $stmt = $pdo->query('SELECT id, title, type, file_url FROM resources ORDER BY type, title');
    $resources = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('[Kutubxona-ARM] Resurslarda xatolik: ' . $ex->getMessage());
    setFlash('error', "Resurslarni yuklashda xatolik yuz berdi.");
}

// ------------------------------------------------------------
// SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Elektron resurslar';
require_once __DIR__ . '/includes/header.php';
?>

<h1 class="text-2xl font-bold mb-1">Elektron resurslar</h1>
<p class="text-slate-500 mb-5">PDF darsliklar, maqolalar va foydali havolalar.</p>

<?php if (!$resources): ?>
    <div class="bg-white border border-dashed border-slate-300 rounded-xl p-8 text-center text-slate-500">
        Hozircha elektron resurslar qo'shilmagan.
    </div>
<?php else: ?>
    <div class="space-y-3">
        <?php foreach ($resources as $r): ?>
            <?php
            $link  = resourceLink((string) $r['file_url']);
            $isPdf = $r['type'] === 'PDF';
            ?>
            <div class="bg-white border border-slate-200 rounded-xl px-4 py-3 flex items-center justify-between gap-3">
                <div>
                    <div class="font-semibold"><?= e($r['title']) ?></div>
                    <span class="inline-block mt-1 text-xs px-2 py-0.5 rounded-full
                                 <?= $isPdf ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' ?>">
                        <?= e($r['type']) ?>
                    </span>
                </div>

                <?php if ($link !== null): ?>
                    <a href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer"
                       <?= $isPdf ? 'download' : '' ?>
                       class="shrink-0 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded-md px-4 py-2">
                        <?= $isPdf ? 'Yuklab olish' : 'Ochish' ?>
                    </a>
                <?php else: ?>
                    <span class="text-sm text-slate-400">Havola noto'g'ri</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
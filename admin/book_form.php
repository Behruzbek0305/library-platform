<?php
/**
 * ============================================================
 *  Fayl: admin/book_form.php – Kitob qo'shish va tahrirlash
 *  Manzillar:
 *    admin/book_form.php        – yangi kitob qo'shish
 *    admin/book_form.php?id=5   – 5-kitobni tahrirlash
 *
 *  Muqova rasmi: JPG, PNG yoki WEBP, eng ko'pi 2 MB.
 *  Rasm uploads/covers/ papkasiga tasodifiy nom bilan saqlanadi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/book_helpers.php';

requireAdmin();

// Rasm sozlamalari
const MAX_COVER_SIZE  = 2 * 1024 * 1024;   // 2 MB
const COVER_DIR_REL   = 'uploads/covers';  // loyiha ildiziga nisbatan yo'l
const ALLOWED_COVERS  = [                  // ruxsat etilgan turlar va kengaytmalari
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// ------------------------------------------------------------
// 1. TAHRIRLASH REJIMIMI? (?id=...)
// ------------------------------------------------------------
$id     = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$isEdit = ($id !== null && $id !== false && $id > 0);
$book   = null;

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT * FROM books WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $book = $stmt->fetch() ?: null;

    if ($book === null) {
        setFlash('error', 'Bunday kitob topilmadi.');
        redirect('admin/books.php');
    }
}

// Forma maydonlarining boshlang'ich qiymatlari
$form = [
    'title'       => $book['title']       ?? '',
    'author'      => $book['author']      ?? '',
    'category'    => $book['category']    ?? BOOK_CATEGORIES[0],
    'copies'      => (string) ($book['copies'] ?? 1),
    'description' => $book['description'] ?? '',
];
$errors = [];

// ------------------------------------------------------------
// 2. FORMA YUBORILGANDA (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = "Sessiya muddati tugagan. Sahifani yangilab, qayta urinib ko'ring.";
    } else {
        // 2.1. Matn maydonlarini olamiz
        $form['title']       = trim((string) ($_POST['title'] ?? ''));
        $form['author']      = trim((string) ($_POST['author'] ?? ''));
        $form['category']    = (string) ($_POST['category'] ?? '');
        $form['copies']      = trim((string) ($_POST['copies'] ?? ''));
        $form['description'] = trim((string) ($_POST['description'] ?? ''));

        // 2.2. Tekshiruvlar
        if (mb_strlen($form['title']) < 1 || mb_strlen($form['title']) > 200) {
            $errors[] = "Kitob nomi 1 tadan 200 tagacha belgidan iborat bo'lsin.";
        }
        if (mb_strlen($form['author']) < 1 || mb_strlen($form['author']) > 150) {
            $errors[] = "Muallif 1 tadan 150 tagacha belgidan iborat bo'lsin.";
        }
        if (!in_array($form['category'], BOOK_CATEGORIES, true)) {
            $errors[] = "Kategoriya noto'g'ri tanlangan.";
        }

        $copies = filter_var($form['copies'], FILTER_VALIDATE_INT);
        if ($copies === false || $copies < 1 || $copies > 1000) {
            $errors[] = "Nusxalar soni 1 dan 1000 gacha butun son bo'lsin.";
        }
        if (mb_strlen($form['description']) > 2000) {
            $errors[] = "Tavsif 2000 belgidan oshmasin.";
        }

        // Tahrirlashda: nusxalar soni hozir band bo'lganlardan kam bo'lmasligi kerak
        if ($isEdit && $copies !== false && $copies >= 1) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE book_id = :id AND status IN ('kutilmoqda', 'tasdiqlandi')"
            );
            $stmt->execute([':id' => $id]);
            $active = (int) $stmt->fetchColumn();

            if ($copies < $active) {
                $errors[] = "Nusxalar soni hozir band qilinganlar sonidan ($active) kam bo'lishi mumkin emas.";
            }
        }

        // 2.3. Muqova rasmini tekshiramiz (ixtiyoriy)
        $coverTmp = null;   // vaqtinchalik fayl yo'li
        $coverExt = null;   // fayl kengaytmasi

        if (isset($_FILES['cover']) && $_FILES['cover']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['cover'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Rasmni yuklashda xatolik yuz berdi (kod: " . (int) $file['error'] . ").";
            } elseif ($file['size'] > MAX_COVER_SIZE) {
                $errors[] = "Rasm hajmi 2 MB dan oshmasligi kerak.";
            } else {
                // getimagesize() faylning haqiqatan rasm ekanini ichidan tekshiradi
                $info = @getimagesize($file['tmp_name']);
                $mime = is_array($info) ? ($info['mime'] ?? '') : '';

                if (!isset(ALLOWED_COVERS[$mime])) {
                    $errors[] = "Faqat JPG, PNG yoki WEBP rasm yuklash mumkin.";
                } else {
                    $coverTmp = $file['tmp_name'];
                    $coverExt = ALLOWED_COVERS[$mime];
                }
            }
        }

        // 2.4. Xatolik bo'lmasa – saqlaymiz
        if (!$errors) {
            $newCoverPath = null;   // bazaga yoziladigan yangi rasm yo'li

            // Rasmni diskka ko'chiramiz
            if ($coverTmp !== null) {
                $dir = __DIR__ . '/../' . COVER_DIR_REL;
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }

                $fileName = bin2hex(random_bytes(8)) . '.' . $coverExt;   // tasodifiy nom

                if (@move_uploaded_file($coverTmp, $dir . '/' . $fileName)) {
                    $newCoverPath = COVER_DIR_REL . '/' . $fileName;
                } else {
                    $errors[] = "Rasmni saqlab bo'lmadi. uploads/covers papkasiga yozish ruxsatini tekshiring.";
                }
            }
        }

        if (!$errors) {
            try {
                if ($isEdit) {
                    // Yangi rasm yuklangan bo'lsa almashtiramiz, bo'lmasa eskisi qoladi
                    $stmt = $pdo->prepare(
                        'UPDATE books
                         SET title = :title, author = :author, category = :category,
                             description = :description, copies = :copies,
                             cover_image = :cover_image
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        ':title'       => $form['title'],
                        ':author'      => $form['author'],
                        ':category'    => $form['category'],
                        ':description' => $form['description'] !== '' ? $form['description'] : null,
                        ':copies'      => $copies,
                        ':cover_image' => $newCoverPath ?? $book['cover_image'],
                        ':id'          => $id,
                    ]);

                    // Eski rasm endi keraksiz – diskdan o'chiramiz
                    if ($newCoverPath !== null) {
                        deleteCoverFile($book['cover_image']);
                    }

                    setFlash('success', "Kitob ma'lumotlari yangilandi.");
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO books (title, author, category, description, cover_image, copies)
                         VALUES (:title, :author, :category, :description, :cover_image, :copies)'
                    );
                    $stmt->execute([
                        ':title'       => $form['title'],
                        ':author'      => $form['author'],
                        ':category'    => $form['category'],
                        ':description' => $form['description'] !== '' ? $form['description'] : null,
                        ':cover_image' => $newCoverPath,
                        ':copies'      => $copies,
                    ]);

                    setFlash('success', "Yangi kitob qo'shildi.");
                }

                redirect('admin/books.php');

            } catch (PDOException $ex) {
                // Baza xatosi bo'lsa, yuklangan rasm yetim qolmasligi uchun o'chiramiz
                deleteCoverFile($newCoverPath);
                error_log('[Kutubxona-ARM] Kitobni saqlashda xatolik: ' . $ex->getMessage());
                $errors[] = "Kitobni saqlashda xatolik yuz berdi. Keyinroq urinib ko'ring.";
            }
        }
    }
}

// ------------------------------------------------------------
// 3. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = $isEdit ? 'Kitobni tahrirlash' : "Yangi kitob qo'shish";
require_once __DIR__ . '/../includes/header.php';

// Formaning action manzili (tahrirlashda ?id= saqlanadi)
$formAction = 'admin/book_form.php' . ($isEdit ? '?id=' . (int) $id : '');
?>

<a href="<?= e(url('admin/books.php')) ?>" class="text-blue-600 hover:underline text-sm">← Kitoblar ro'yxatiga qaytish</a>

<div class="max-w-xl mx-auto bg-white border border-slate-200 rounded-xl p-6 mt-3">
    <h1 class="text-2xl font-bold mb-4"><?= e($pageTitle) ?></h1>

    <?php if ($errors): ?>
        <div class="mb-4 border border-red-300 bg-red-50 text-red-800 rounded-md px-4 py-3 text-sm">
            <ul class="list-disc pl-5 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- enctype="multipart/form-data" – rasm yuklash uchun shart -->
    <form method="post" action="<?= e(url($formAction)) ?>" enctype="multipart/form-data" novalidate>
        <?= csrfField() ?>

        <label for="title" class="block text-sm font-bold mt-3 mb-1">Kitob nomi</label>
        <input type="text" id="title" name="title" value="<?= e($form['title']) ?>" required maxlength="200"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="author" class="block text-sm font-bold mt-3 mb-1">Muallifi</label>
        <input type="text" id="author" name="author" value="<?= e($form['author']) ?>" required maxlength="150"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="category" class="block text-sm font-bold mt-3 mb-1">Kategoriya</label>
        <select id="category" name="category"
                class="w-full border border-slate-300 rounded-md px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-blue-300">
            <?php foreach (BOOK_CATEGORIES as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $cat === $form['category'] ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="copies" class="block text-sm font-bold mt-3 mb-1">Nusxalar soni</label>
        <input type="number" id="copies" name="copies" value="<?= e($form['copies']) ?>" min="1" max="1000" required
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="description" class="block text-sm font-bold mt-3 mb-1">Qisqa tavsif</label>
        <textarea id="description" name="description" rows="4" maxlength="2000"
                  class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300"><?= e($form['description']) ?></textarea>

        <label for="cover" class="block text-sm font-bold mt-3 mb-1">Muqova rasmi (ixtiyoriy)</label>
        <?php if ($isEdit && !empty($book['cover_image'])): ?>
            <img src="<?= e(url((string) $book['cover_image'])) ?>" alt="Hozirgi muqova"
                 class="h-28 rounded-md mb-2 border border-slate-200">
            <p class="text-xs text-slate-500 mb-1">Yangi rasm tanlasangiz, hozirgisi almashtiriladi.</p>
        <?php endif; ?>
        <input type="file" id="cover" name="cover" accept="image/jpeg,image/png,image/webp"
               class="w-full text-sm">
        <p class="text-xs text-slate-500 mt-1">JPG, PNG yoki WEBP, eng ko'pi 2 MB.</p>

        <button type="submit"
                class="w-full mt-5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md py-2.5">
            <?= $isEdit ? 'Saqlash' : "Qo'shish" ?>
        </button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<?php
/**
 * ============================================================
 *  Fayl: profile.php – Mening ma'lumotlarim (talaba profilini tahrirlash)
 *  Vazifasi: talaba o'z to'liq ismi, fakulteti, yo'nalishi, guruhi va
 *            HEMIS ID'sini kiritadi yoki o'zgartiradi.
 *
 *  Kimlar uchun: 2-bosqichdan oldin ro'yxatdan o'tgan talabalar (bu
 *  ma'lumotlar bo'sh) va xato kiritganlar. Email va parol bu yerda o'zgarmaydi.
 *  Kitob band qilishdan oldin ma'lumotlar to'liq bo'lishi shart (reserve.php).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/student_helpers.php';

// Faqat tizimga kirgan talabalar uchun
requireStudent();
$user = currentUser();

$errors = [];

// Forma maydonlarining boshlang'ich qiymatlari – hozirgi ma'lumotlar
$form = [
    'name'       => (string) ($user['name'] ?? ''),
    'faculty'    => (string) ($user['faculty'] ?? ''),
    'direction'  => (string) ($user['direction'] ?? ''),
    'group_name' => (string) ($user['group_name'] ?? ''),
    'hemis_id'   => (string) ($user['hemis_id'] ?? ''),
];

// ------------------------------------------------------------
// FORMA YUBORILGANDA (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = "Sessiya muddati tugagan. Sahifani yangilab, qayta urinib ko'ring.";
    } else {
        // Ma'lumotlarni tekshiramiz
        $result = validateStudentData($_POST);
        $form   = $result['data'];
        $errors = $result['errors'];

        // Bu HEMIS ID boshqa talabada bormi? (o'zining ID'sini hisobga olmaymiz)
        if (!$errors && hemisIdTaken($pdo, $form['hemis_id'], (int) $user['id'])) {
            $errors[] = "Bu HEMIS ID boshqa akkauntda ishlatilgan.";
        }

        if (!$errors) {
            try {
                // Faqat o'zining yozuvini yangilaydi (id sessiyadan olinadi, formadan emas)
                $stmt = $pdo->prepare(
                    "UPDATE users
                     SET name = :name, faculty = :faculty, direction = :direction,
                         group_name = :group_name, hemis_id = :hemis_id
                     WHERE id = :id AND role = 'talaba'"
                );
                $stmt->execute([
                    ':name'       => $form['name'],
                    ':faculty'    => $form['faculty'],
                    ':direction'  => $form['direction'],
                    ':group_name' => $form['group_name'],
                    ':hemis_id'   => $form['hemis_id'],
                    ':id'         => $user['id'],
                ]);

                setFlash('success', "Ma'lumotlaringiz saqlandi.");
                redirect('cabinet.php');
            } catch (PDOException $ex) {
                // 23000 – UNIQUE cheklov: ikki kishi bir vaqtda bir xil HEMIS ID kiritganda
                if ((string) $ex->getCode() === '23000') {
                    $errors[] = "Bu HEMIS ID boshqa akkauntda ishlatilgan.";
                } else {
                    error_log('[Kutubxona-ARM] Profilni saqlashda xatolik: ' . $ex->getMessage());
                    $errors[] = "Kutilmagan xatolik yuz berdi. Keyinroq urinib ko'ring.";
                }
            }
        }
    }
}

// ------------------------------------------------------------
// SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = "Mening ma'lumotlarim";
require_once __DIR__ . '/includes/header.php';
?>

<a href="<?= e(url('cabinet.php')) ?>" class="text-blue-600 hover:underline text-sm">← Kabinetga qaytish</a>

<div class="max-w-md mx-auto bg-white border border-slate-200 rounded-xl p-6 mt-3">
    <h1 class="text-2xl font-bold mb-1">Mening ma'lumotlarim</h1>
    <p class="text-sm text-slate-500 mb-2">
        Email: <b><?= e($user['email']) ?></b> (o'zgartirib bo'lmaydi)
    </p>

    <?php if (!isStudentProfileComplete($user)): ?>
        <div class="mb-3 border border-amber-300 bg-amber-50 text-amber-800 rounded-md px-4 py-3 text-sm">
            Kitob band qilish uchun avval barcha ma'lumotlarni to'ldiring.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="mb-4 border border-red-300 bg-red-50 text-red-800 rounded-md px-4 py-3 text-sm">
            <ul class="list-disc pl-5 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('profile.php')) ?>" novalidate>
        <?= csrfField() ?>

        <?php renderStudentFields($form); ?>

        <button type="submit"
                class="w-full mt-5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md py-2.5">
            Saqlash
        </button>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
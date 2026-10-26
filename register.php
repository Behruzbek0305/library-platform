<?php
/**
 * ============================================================
 *  Fayl: register.php
 *  Vazifasi: Talabaning ro'yxatdan o'tishi (ism, email, parol).
 *  Funksional talab №1 (Texnik topshiriq, 4-band).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Allaqachon tizimga kirgan bo'lsa, bu sahifa kerak emas
if (isLoggedIn()) {
    redirect('index.php');
}

$errors = [];   // Xatoliklar ro'yxati
$name   = '';   // Forma qayta ko'rsatilganda maydonlar to'ldirilgan holda qolishi uchun
$email  = '';

// ------------------------------------------------------------
// FORMA YUBORILGANDA (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) CSRF tokenni tekshiramiz
    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = "Sessiya muddati tugagan. Sahifani yangilab, qayta urinib ko'ring.";
    } else {
        // 2) Kiritilgan ma'lumotlarni tozalab olamiz
        $name      = trim((string) ($_POST['name'] ?? ''));
        $email     = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password  = (string) ($_POST['password'] ?? '');
        $password2 = (string) ($_POST['password_confirm'] ?? '');

        // 3) Tekshiruvlar (validatsiya)
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors[] = "Ism va familiya 2 tadan 100 tagacha belgidan iborat bo'lsin.";
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $errors[] = "Email manzili noto'g'ri kiritilgan.";
        }
        if (strlen($password) < 6) {
            $errors[] = "Parol kamida 6 belgidan iborat bo'lsin.";
        }
        if (strlen($password) > 72) {
            $errors[] = "Parol 72 belgidan uzun bo'lmasligi kerak.";
        }
        if ($password !== $password2) {
            $errors[] = "Parol va uning takrori bir xil emas.";
        }

        // 4) Xatolik bo'lmasa – bazaga yozamiz
        if (!$errors) {
            try {
                // Bu email avval ro'yxatdan o'tganmi? (Prepared statement)
                $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
                $check->execute([':email' => $email]);

                if ($check->fetch()) {
                    $errors[] = "Bu email bilan allaqachon ro'yxatdan o'tilgan.";
                } else {
                    // Parol password_hash() bilan xeshlanadi (ochiq ko'rinishda saqlanmaydi)
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    // Rol har doim "talaba" – admin faqat bazadan qo'lda qo'shiladi
                    $insert = $pdo->prepare(
                        'INSERT INTO users (name, email, password, role)
                         VALUES (:name, :email, :password, :role)'
                    );
                    $insert->execute([
                        ':name'     => $name,
                        ':email'    => $email,
                        ':password' => $hash,
                        ':role'     => 'talaba',
                    ]);

                    // Ro'yxatdan o'tgan zahoti tizimga kiritamiz
                    loginUser((int) $pdo->lastInsertId());
                    setFlash('success', 'Xush kelibsiz, ' . $name . '! Ro\'yxatdan o\'tish muvaffaqiyatli yakunlandi.');
                    redirect('index.php');
                }
            } catch (PDOException $ex) {
                // 23000 – UNIQUE cheklov buzildi (ikki kishi bir vaqtda bir email kiritganda)
                if ((string) $ex->getCode() === '23000') {
                    $errors[] = "Bu email bilan allaqachon ro'yxatdan o'tilgan.";
                } else {
                    error_log('[Kutubxona-ARM] Ro\'yxatdan o\'tishda xatolik: ' . $ex->getMessage());
                    $errors[] = "Kutilmagan xatolik yuz berdi. Keyinroq urinib ko'ring.";
                }
            }
        }
    }
}

// ------------------------------------------------------------
// SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = "Ro'yxatdan o'tish";
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto bg-white border border-slate-200 rounded-xl p-6 mt-4">
    <h1 class="text-2xl font-bold mb-4">Ro'yxatdan o'tish</h1>

    <!-- Xatoliklar ro'yxati -->
    <?php if ($errors): ?>
        <div class="mb-4 border border-red-300 bg-red-50 text-red-800 rounded-md px-4 py-3 text-sm">
            <ul class="list-disc pl-5 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('register.php')) ?>" novalidate>
        <?= csrfField() ?>

        <label for="name" class="block text-sm font-bold mt-3 mb-1">Ism va familiya</label>
        <input type="text" id="name" name="name" value="<?= e($name) ?>" required maxlength="100"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="email" class="block text-sm font-bold mt-3 mb-1">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required maxlength="150"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="password" class="block text-sm font-bold mt-3 mb-1">Parol (kamida 6 belgi)</label>
        <input type="password" id="password" name="password" required minlength="6" maxlength="72"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="password_confirm" class="block text-sm font-bold mt-3 mb-1">Parolni takrorlang</label>
        <input type="password" id="password_confirm" name="password_confirm" required minlength="6" maxlength="72"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <button type="submit"
                class="w-full mt-5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md py-2.5">
            Ro'yxatdan o'tish
        </button>
    </form>

    <p class="text-sm text-slate-500 mt-4 text-center">
        Akkauntingiz bormi?
        <a href="<?= e(url('login.php')) ?>" class="text-blue-600 hover:underline">Kirish</a>
    </p>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php
/**
 * ============================================================
 *  Fayl: login.php
 *  Vazifasi: Tizimga kirish (email va parol).
 *  Funksional talab №1 (Texnik topshiriq, 4-band).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Allaqachon tizimga kirgan bo'lsa, bosh sahifaga yo'naltiramiz
if (isLoggedIn()) {
    redirect('index.php');
}

$errors = [];
$email  = '';

// Foydalanuvchi topilmaganda ham password_verify() ishlatiladi.
// Shunda javob vaqti bir xil bo'lib, "bunday email bor yoki yo'q" ekanini bilib bo'lmaydi.
const DUMMY_HASH = '$2y$10$w4q0/k82iJbbF5dSuix7KupmGuj9mHqOtAIux1IGku0KuLQcH3f6e';

// ------------------------------------------------------------
// URINISHLARNI CHEKLASH (oddiy himoya, sessiya asosida)
// 10 daqiqa ichida 5 marta xato kiritilsa, 10 daqiqa kutish kerak.
// Eslatma: bu faqat oddiy brauzer hujumlarini sekinlashtiradi; cookie'ni tashlab
// ketadigan skriptlarga qarshi serverning o'zida (fail2ban, WAF) himoya kerak.
// ------------------------------------------------------------
const LOGIN_MAX_FAILS   = 5;
const LOGIN_WINDOW_SEC  = 600;

$fails = $_SESSION['login_fails'] ?? ['count' => 0, 'since' => time()];
if (time() - (int) $fails['since'] > LOGIN_WINDOW_SEC) {
    $fails = ['count' => 0, 'since' => time()];   // oyna tugadi – hisob yangilanadi
}
$blocked   = (int) $fails['count'] >= LOGIN_MAX_FAILS;
$waitMin   = max(1, (int) ceil((LOGIN_WINDOW_SEC - (time() - (int) $fails['since'])) / 60));

// ------------------------------------------------------------
// FORMA YUBORILGANDA (POST)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) CSRF tokenni tekshiramiz
    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        $errors[] = "Sessiya muddati tugagan. Sahifani yangilab, qayta urinib ko'ring.";
    } elseif ($blocked) {
        $errors[] = "Juda ko'p xato urinish. Taxminan {$waitMin} daqiqadan keyin qayta urinib ko'ring.";
    } else {
        $email    = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $errors[] = "Email va parolni kiriting.";
        } else {
            try {
                // 2) Foydalanuvchini email bo'yicha qidiramiz (Prepared statement)
                $stmt = $pdo->prepare('SELECT id, name, password, role, is_blocked FROM users WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $found = $stmt->fetch();

                // 3) Parolni tekshiramiz
                $hashToCheck = $found ? $found['password'] : DUMMY_HASH;
                $passwordOk  = password_verify($password, $hashToCheck);

                if ($found && $passwordOk) {
                    // Bloklangan akkaunt (parol to'g'ri bo'lsa ham) tizimga kira olmaydi
                    if ((int) $found['is_blocked'] === 1) {
                        $errors[] = "Akkauntingiz vaqtincha bloklangan. Kutubxona rahbariga murojaat qiling.";
                    }
                    else {
                        // Xesh algoritmi eskirgan bo'lsa, yangisi bilan yangilaymiz
                            if (password_needs_rehash($found['password'], PASSWORD_DEFAULT)) {
                            $upd = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                            $upd->execute([
                                ':password' => password_hash($password, PASSWORD_DEFAULT),
                                ':id'       => $found['id'],
                            ]);
                        }

                        unset($_SESSION['login_fails']);   // muvaffaqiyatli kirish – hisob tozalanadi
                        loginUser((int) $found['id']);
                        setFlash('success', 'Xush kelibsiz, ' . $found['name'] . '!');

                        // Kutubxonachi – admin panelga, talaba – bosh sahifaga
                        redirect(in_array($found['role'], ['admin', 'superadmin'], true) ? 'admin/index.php' : 'index.php');
                    }
                }

                // Email yoki parol noto'g'ri (qaysi biri ekanini aytmaymiz)
                $fails['count'] = (int) $fails['count'] + 1;
                $_SESSION['login_fails'] = $fails;
                $errors[] = "Email yoki parol noto'g'ri.";
            } catch (PDOException $ex) {
                error_log('[Kutubxona-ARM] Kirishda xatolik: ' . $ex->getMessage());
                $errors[] = "Kutilmagan xatolik yuz berdi. Keyinroq urinib ko'ring.";
            }
        }
    }
}

// ------------------------------------------------------------
// SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'Kirish';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto bg-white border border-slate-200 rounded-xl p-6 mt-4">
    <h1 class="text-2xl font-bold mb-4">Tizimga kirish</h1>

    <!-- Xatoliklar -->
    <?php if ($errors): ?>
        <div class="mb-4 border border-red-300 bg-red-50 text-red-800 rounded-md px-4 py-3 text-sm">
            <ul class="list-disc pl-5 space-y-1">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
        <?= csrfField() ?>

        <label for="email" class="block text-sm font-bold mt-3 mb-1">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required maxlength="150"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <label for="password" class="block text-sm font-bold mt-3 mb-1">Parol</label>
        <input type="password" id="password" name="password" required maxlength="72"
               class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300">

        <button type="submit"
                class="w-full mt-5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md py-2.5">
            Kirish
        </button>
    </form>

    <p class="text-sm text-slate-500 mt-4 text-center">
        Akkauntingiz yo'qmi?
        <a href="<?= e(url('register.php')) ?>" class="text-blue-600 hover:underline">Ro'yxatdan o'tish</a>
    </p>
    <p class="text-xs text-slate-400 mt-2 text-center">
        Kutubxonachi sizni oldin kiritgan bo'lsa, "Ro'yxatdan o'tish" orqali shu email va HEMIS ID bilan parol o'rnating.
    </p>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
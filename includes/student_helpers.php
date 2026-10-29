<?php
/**
 * ============================================================
 *  Fayl: includes/student_helpers.php
 *  Vazifasi: Talaba ma'lumotlari bilan ishlaydigan umumiy qismlar:
 *            tekshiruv (validatsiya), HEMIS ID takrorlanishini tekshirish,
 *            forma maydonlarini chizish, ma'lumotlar to'liqligini aniqlash.
 *
 *  Ishlatiladi: register.php, profile.php, reserve.php, cabinet.php, admin/index.php
 *
 *  Ulash: require_once __DIR__ . '/includes/student_helpers.php';
 *  (functions.php avval ulangan bo'lishi kerak – auth.php buni qiladi)
 *
 *  Talaba ma'lumotlari: to'liq ism, fakultet, yo'nalish, guruh, HEMIS ID.
 *  HEMIS ID keyinchalik HEMIS platformasi bilan integratsiyada ma'lumotlar
 *  to'g'riligini tekshirish uchun kerak bo'ladi.
 * ============================================================
 */

declare(strict_types=1);

// ------------------------------------------------------------
// 1. YORDAMCHI: ortiqcha bo'shliqlarni tozalash
//    "  Ali    Valiyev " → "Ali Valiyev"
// ------------------------------------------------------------
function cleanSpaces(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

// ------------------------------------------------------------
// 2. TALABA MA'LUMOTLARINI TEKSHIRISH
//    Kirish: $_POST yoki shunga o'xshash massiv.
//    Qaytaradi: ['data' => tozalangan qiymatlar, 'errors' => xatoliklar ro'yxati]
//    Parol va email bu yerda tekshirilmaydi (ular register.php da).
// ------------------------------------------------------------
function validateStudentData(array $input): array
{
    // 2.1. Qiymatlarni tozalab olamiz
    $data = [
        'name'       => cleanSpaces((string) ($input['name'] ?? '')),
        'faculty'    => cleanSpaces((string) ($input['faculty'] ?? '')),
        'direction'  => cleanSpaces((string) ($input['direction'] ?? '')),
        'group_name' => cleanSpaces((string) ($input['group_name'] ?? '')),
        // HEMIS ID'da bo'shliq bo'lmaydi (nusxalashda tasodifan tushib qolishi mumkin)
        'hemis_id'   => (string) preg_replace('/\s+/u', '', (string) ($input['hemis_id'] ?? '')),
    ];

    $errors = [];

    // 2.2. To'liq ism: kamida 2 ta so'z (familiya va ism), faqat harflar
    $nameLen = mb_strlen($data['name']);
    if ($nameLen < 5 || $nameLen > 100) {
        $errors[] = "To'liq ism 5 tadan 100 tagacha belgidan iborat bo'lsin (familiya, ism va sharif).";
    } else {
        $words = explode(' ', $data['name']);

        if (count($words) < 2) {
            $errors[] = "Familiya va ismingizni to'liq yozing (kamida ikkita so'z).";
        } else {
            foreach ($words as $word) {
                // Har bir so'z harf bilan boshlansin; ichida apostrof, defis va nuqta bo'lishi mumkin (O'tkir, G'ulom)
                if (mb_strlen($word) < 2 || !preg_match('/^\p{L}[\p{L}\'’ʻʼ`\-.]*$/u', $word)) {
                    $errors[] = "To'liq ismda faqat harflar bo'lsin (raqam va belgilar mumkin emas).";
                    break;
                }
            }
        }
    }

    // 2.3. Fakultet va yo'nalish
    if (mb_strlen($data['faculty']) < 2 || mb_strlen($data['faculty']) > 150) {
        $errors[] = "Fakultet nomi 2 tadan 150 tagacha belgidan iborat bo'lsin.";
    }
    if (mb_strlen($data['direction']) < 2 || mb_strlen($data['direction']) > 150) {
        $errors[] = "Ta'lim yo'nalishi 2 tadan 150 tagacha belgidan iborat bo'lsin.";
    }

    // 2.4. Guruh: harf yoki raqam bilan boshlansin (masalan: DI-21-01, 401-guruh)
    if (mb_strlen($data['group_name']) < 2 || mb_strlen($data['group_name']) > 30
        || !preg_match('/^[\p{L}\p{N}][\p{L}\p{N}\s\-\/\'’.]*$/u', $data['group_name'])) {
        $errors[] = "Guruh nomi 2 tadan 30 tagacha belgi bo'lsin (harf, raqam, defis).";
    }

    // 2.5. HEMIS ID: faqat raqam. Aniq uzunlik HEMIS bilan integratsiyada aniqlanadi,
    //      hozircha 6 dan 20 gacha raqam qabul qilinadi.
    if (!preg_match('/^\d{6,20}$/', $data['hemis_id'])) {
        $errors[] = "HEMIS ID faqat raqamlardan iborat bo'lsin (6 tadan 20 tagacha raqam).";
    }

    return ['data' => $data, 'errors' => $errors];
}

// ------------------------------------------------------------
// 3. HEMIS ID BAZADA BORMI?
//    $exceptUserId – profilni tahrirlashda o'zining ID'sini hisobga olmaslik uchun.
// ------------------------------------------------------------
function hemisIdTaken(PDO $pdo, string $hemisId, int $exceptUserId = 0): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE hemis_id = :hemis_id AND id <> :id');
    $stmt->execute([':hemis_id' => $hemisId, ':id' => $exceptUserId]);

    return (int) $stmt->fetchColumn() > 0;
}

// ------------------------------------------------------------
// 4. TALABA MA'LUMOTLARI TO'LIQMI?
//    Band qilishdan oldin tekshiriladi (reserve.php). 2-bosqichdan oldin
//    ro'yxatdan o'tgan talabalarda fakultet, guruh va HEMIS ID bo'sh bo'ladi.
// ------------------------------------------------------------
function isStudentProfileComplete(?array $user): bool
{
    if ($user === null) {
        return false;
    }

    foreach (['name', 'faculty', 'direction', 'group_name', 'hemis_id'] as $field) {
        if (trim((string) ($user[$field] ?? '')) === '') {
            return false;
        }
    }

    // Eski akkauntlarda ism bitta so'z bo'lishi mumkin: to'liq ism talab qilinadi
    return count(explode(' ', cleanSpaces((string) $user['name']))) >= 2;
}

// ------------------------------------------------------------
// 5. QISQA MA'LUMOT QATORI: "Fakultet · Yo'nalish · Guruh: DI-21-01"
//    Admin panelda talabani aniqlash uchun ishlatiladi.
// ------------------------------------------------------------
function studentInfoLine(array $row): string
{
    $parts = [];

    if (!empty($row['faculty'])) {
        $parts[] = (string) $row['faculty'];
    }
    if (!empty($row['direction'])) {
        $parts[] = (string) $row['direction'];
    }
    if (!empty($row['group_name'])) {
        $parts[] = 'Guruh: ' . $row['group_name'];
    }

    return implode(' · ', $parts);
}

// ------------------------------------------------------------
// 6. FORMA MAYDONLARINI CHIZISH (register.php va profile.php uchun umumiy)
//    $form – maydonlarning hozirgi qiymatlari (name, faculty, direction, group_name, hemis_id).
//    Hamma qiymat e() orqali o'tadi (XSS'dan himoya).
// ------------------------------------------------------------
function renderStudentFields(array $form): void
{
    $input = 'w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-300';
    ?>
    <label for="name" class="block text-sm font-bold mt-3 mb-1">To'liq ism (familiya, ism, sharif)</label>
    <input type="text" id="name" name="name" value="<?= e($form['name'] ?? '') ?>" required maxlength="100"
           autocomplete="name" placeholder="Masalan: Karimov Aziz Bahodirovich" class="<?= $input ?>">

    <label for="faculty" class="block text-sm font-bold mt-3 mb-1">Fakultet</label>
    <input type="text" id="faculty" name="faculty" value="<?= e($form['faculty'] ?? '') ?>" required maxlength="150"
           placeholder="Masalan: Axborot texnologiyalari" class="<?= $input ?>">

    <label for="direction" class="block text-sm font-bold mt-3 mb-1">Ta'lim yo'nalishi</label>
    <input type="text" id="direction" name="direction" value="<?= e($form['direction'] ?? '') ?>" required maxlength="150"
           placeholder="Masalan: Dasturiy injiniring" class="<?= $input ?>">

    <label for="group_name" class="block text-sm font-bold mt-3 mb-1">Guruh</label>
    <input type="text" id="group_name" name="group_name" value="<?= e($form['group_name'] ?? '') ?>" required maxlength="30"
           placeholder="Masalan: DI-21-01" class="<?= $input ?>">

    <label for="hemis_id" class="block text-sm font-bold mt-3 mb-1">HEMIS ID</label>
    <input type="text" id="hemis_id" name="hemis_id" value="<?= e($form['hemis_id'] ?? '') ?>" required maxlength="20"
           inputmode="numeric" placeholder="Talaba ID raqami (faqat raqam)" class="<?= $input ?>">
    <p class="text-xs text-slate-500 mt-1">HEMIS ID talabalik guvohnomangizda yoki HEMIS kabinetingizda yozilgan.</p>
    <?php
}
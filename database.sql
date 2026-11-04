-- ============================================================
--  KUTUBXONA – AXBOROT RESURS MARKAZI (Kutubxona - ARM)
--  Ma'lumotlar bazasi: database.sql
--  Muhit: MySQL / MariaDB (XAMPP + phpMyAdmin)
--  Kodirovka: utf8mb4_unicode_ci
--
--  Jadvallar (Texnik topshiriqning 8-bandiga muvofiq):
--    1) users         – foydalanuvchilar (talaba yoki admin)
--    2) books         – kitoblar katalogi
--    3) reservations  – band qilishlar
--    4) resources     – elektron resurslar (PDF yoki havola)
--
--  2-bosqich: users jadvaliga fakultet, yo'nalish, guruh va HEMIS ID qo'shildi.
--  3-bosqich: reservations jadvaliga pickup_days va pickup_deadline qo'shildi,
--             muddati o'tganda avtomatik rad etish tizimi joriy etildi.
--  5-bosqich: OFFLAYN BERISH + HAQIQIY QAYTARISH TIZIMI:
--             - "berilgan" holati: kitob talaba qo'lida, due_date – qaytarish muddati
--             - picked_up_at / due_date / returned_at / loan_days / is_offline ustunlari
--             - kitobni faqat kutubxonachi «Qaytarildi» tugmasi orqali qaytara oladi;
--               hech qanday avtomatik "qaytarildi" belgilash YO'Q (muddat o'tsa – KECHIKISH)
--
--  6-bosqich: books.available_copies – real-time bo'sh nusxalar soni.
--  7-bosqich: books.field (Soha) va books.genre (Janr) – kengaytirilgan filtrlar.
--  8-bosqich: reservations.pickup_code – 6 belgili (harf + raqam) talaba kodi.
--  Xodimlar izi: served_by / returned_by / rejected_by (kim tasdiqladi, qabul qildi, rad etdi).
--
--  Ishga tushirish: phpMyAdmin → "Import" bo'limidan shu faylni yuklang.
--  DIQQAT: bu fayl bazani o'chirib qayta yaratadi. Mavjud bazani saqlab qolmoqchi
--  bo'lsangiz, uning o'rniga migrations/002_talaba_malumotlari.sql ni ishlating.
-- ============================================================

-- ------------------------------------------------------------
-- 1. BAZANI YARATISH VA TANLASH
-- ------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `kutubxona_arm`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `kutubxona_arm`;

-- Import paytida xatolik chiqmasligi uchun tashqi kalit tekshiruvini vaqtincha o'chiramiz
SET FOREIGN_KEY_CHECKS = 0;

-- Qayta import qilinganda eski jadvallar o'chiriladi (bog'liqlik tartibida)
DROP TABLE IF EXISTS `reservations`;
DROP TABLE IF EXISTS `resources`;
DROP TABLE IF EXISTS `books`;
DROP TABLE IF EXISTS `users`;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- 2. JADVAL: users (Foydalanuvchilar)
--    Maydonlar: ism, email, fakultet, yo'nalish, guruh, HEMIS ID, parol, rol
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,          -- Foydalanuvchi ID raqami
  `name`       VARCHAR(100) NOT NULL,                         -- To'liq ism (familiya, ism, sharif)
  `email`      VARCHAR(150) NOT NULL,                         -- Email (kirish uchun login)
  `faculty`    VARCHAR(150) NULL,                             -- Fakultet (talabalar uchun)
  `direction`  VARCHAR(150) NULL,                             -- Ta'lim yo'nalishi (talabalar uchun)
  `group_name` VARCHAR(30)  NULL,                             -- Guruh (talabalar uchun)
  `hemis_id`   VARCHAR(20)  NULL,                             -- HEMIS ID – keyingi integratsiya uchun
  `password`   VARCHAR(255) NOT NULL,                         -- password_hash() natijasi (ochiq parol SAQLANMAYDI)
  `role`       ENUM('talaba','admin') NOT NULL DEFAULT 'talaba', -- Rol: talaba yoki kutubxonachi (admin)
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- Ro'yxatdan o'tgan vaqti
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),                      -- Bitta email bilan faqat bitta akkaunt
  UNIQUE KEY `uq_users_hemis` (`hemis_id`)                    -- Bitta HEMIS ID bilan faqat bitta akkaunt
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. JADVAL: books (Kitoblar)
--    Maydonlar: nomi, muallifi, kategoriya, soha, janr, tavsif, muqova rasmi, nusxalar soni
-- ------------------------------------------------------------
CREATE TABLE `books` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,         -- Kitob ID raqami
  `title`       VARCHAR(200) NOT NULL,                        -- Kitob nomi
  `author`      VARCHAR(150) NOT NULL,                        -- Muallifi
  `category`    ENUM('Darslik','Badiiy','Ilmiy') NOT NULL,    -- Kategoriya (darslik, badiiy, ilmiy)
  `field`       VARCHAR(100) NULL,                            -- Soha (masalan: IT, Adabiyot) – filtr uchun
  `genre`       VARCHAR(100) NULL,                            -- Janr (masalan: Roman, Qissa) – filtr uchun
  `description` TEXT NULL,                                    -- Qisqa tavsif
  `cover_image` VARCHAR(255) NULL,                            -- Muqova rasmi manzili (masalan: uploads/covers/1.jpg)
  `copies`      INT UNSIGNED NOT NULL DEFAULT 1,              -- Kutubxonadagi jami nusxalar soni
  `available_copies` INT UNSIGNED NOT NULL DEFAULT 1,         -- HOZIR BO'SH nusxalar soni (6-bosqich: real-time; berilganda kamayadi, «Qaytarildi» da qo'shiladi)
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Katalogga qo'shilgan vaqti
  PRIMARY KEY (`id`),
  KEY `idx_books_title` (`title`),                            -- Nomi bo'yicha qidiruvni tezlashtiradi
  KEY `idx_books_author` (`author`),                          -- Muallif bo'yicha qidiruvni tezlashtiradi
  KEY `idx_books_category` (`category`),                      -- Kategoriya bo'yicha saralashni tezlashtiradi
  KEY `idx_books_field` (`field`),                            -- Soha bo'yicha filtr
  KEY `idx_books_genre` (`genre`),                            -- Janr bo'yicha filtr
  KEY `idx_books_available` (`available_copies`)              -- Bo'shlar soni bo'yicha filtr tez ishlashi uchun
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. JADVAL: reservations (Band qilishlar)
--    Maydonlar: foydalanuvchi, kitob, sana, holati
--    Holatlar: kutilmoqda → tasdiqlandi → berilgan → qaytarildi
--              (kutilmoqda → rad_etildi ham mumkin)
--      kutilmoqda   – talaba onlayn band qildi, olib ketishi kutilmoqda
--      tasdiqlandi  – kutubxonachi ruxsat berdi, kitob hali turgan joyida
--      berilgan     – kitob HAQIQIY talaba qo'lida (due_date – qaytarish muddati)
--      qaytarildi   – kutubxonachi kitobni qabul qildi (returned_at yozilgan)
--      rad_etildi   – bekor qilindi yoki olib ketish muddati o'tdi
--    MUHIM: "qaytarildi" faqat kutubxonachi tugma orqali belgilanadi.
-- ============================================================
CREATE TABLE `reservations` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,       -- Band qilish ID raqami
  `user_id`         INT UNSIGNED NOT NULL,                      -- Kim band qildi (users.id)
  `book_id`         INT UNSIGNED NOT NULL,                      -- Qaysi kitob (books.id)
  `reserved_date`   DATE NOT NULL,                              -- Band qilingan sana
  `pickup_days`     TINYINT UNSIGNED NOT NULL DEFAULT 1,        -- Olib ketish muddati: 1, 2 yoki 3 kun
  `pickup_deadline` DATETIME NOT NULL,                          -- Shu muddatgacha olib ketmasa avtomatik rad etiladi
  `pickup_code`     CHAR(6) NULL,                               -- 6 belgili kod (harf+raqam, masalan K7M4P2): talaba kutubxonada ko'rsatadi (offlaynda NULL)
  `status`          ENUM('kutilmoqda','tasdiqlandi','berilgan','qaytarildi','rad_etildi') NOT NULL DEFAULT 'kutilmoqda', -- Holati
  `picked_up_at`    DATETIME NULL,                              -- Kitob qo'lga berilgan vaqt (offlayn yoki approve)
  `due_date`        DATETIME NULL,                              -- Qaytarish muddati = picked_up_at + loan_days kun
  `expires_at`      DATETIME NULL,                              -- Offlayn berishda due_date nusxasi (process-offline.php yozadi)
  `returned_at`     DATETIME NULL,                              -- Haqiqatan qaytarilgan vaqt (faqat kutubxonachi belgilaydi)
  `loan_days`       TINYINT UNSIGNED NULL,                      -- Necha kunga berilgan (1/2/3/5/10)
  `is_offline`      TINYINT(1) NOT NULL DEFAULT 0,              -- 1 = kutubxonachi offlayn bergan (talaba onlayn band qilmagan)
  `served_by`       INT UNSIGNED NULL,                          -- Tasdiqlagan / offlayn bergan kutubxonachi (users.id)
  `returned_by`     INT UNSIGNED NULL,                          -- «Qaytarildi» deb belgilagan kutubxonachi (users.id)
  `rejected_by`     INT UNSIGNED NULL,                          -- Rad etgan kutubxonachi (NULL = muddati o'tib avtomatik rad etilgan)
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Yozuv yaratilgan vaqt
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_res_pickup_code` (`pickup_code`),             -- kod takrorlanmasligi bazada kafolatlanadi
  KEY `idx_res_user` (`user_id`),
  KEY `idx_res_served` (`served_by`),
  KEY `idx_res_book` (`book_id`),
  KEY `idx_res_status` (`status`),
  KEY `idx_res_deadline` (`status`, `pickup_deadline`),
  KEY `idx_res_due` (`status`, `due_date`),                     -- muddati kelgan (kechikkan) kitoblarni tez topish uchun
  -- Foydalanuvchi o'chirilsa, uning band qilishlari ham o'chadi
  CONSTRAINT `fk_res_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  -- Kitob o'chirilsa (admin o'chirsa), unga tegishli band qilishlar ham o'chadi
  CONSTRAINT `fk_res_book`
    FOREIGN KEY (`book_id`) REFERENCES `books` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  -- Xodim akkaunti o'chirilsa, band qilish saqlanib qoladi, xodim izi NULL bo'ladi
  CONSTRAINT `fk_res_served`
    FOREIGN KEY (`served_by`)   REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_res_returned`
    FOREIGN KEY (`returned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_res_rejected`
    FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. JADVAL: resources (Elektron resurslar)
--    Maydonlar: nomi, turi (PDF yoki havola), fayl manzili
-- ------------------------------------------------------------
CREATE TABLE `resources` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,          -- Resurs ID raqami
  `title`      VARCHAR(200) NOT NULL,                         -- Resurs nomi
  `type`       ENUM('PDF','Havola') NOT NULL DEFAULT 'Havola', -- Turi: PDF fayl yoki tashqi havola
  `file_url`   VARCHAR(500) NOT NULL,                         -- Fayl manzili (PDF yo'li yoki https:// havola)
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- Qo'shilgan vaqti
  PRIMARY KEY (`id`),
  KEY `idx_resources_type` (`type`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. SINOV MA'LUMOTLARI
-- ============================================================

-- ------------------------------------------------------------
-- 6.1. Sinov foydalanuvchilari
--      Parollar password_hash() (bcrypt) bilan xeshlangan:
--        admin  → admin@kutubxona.uz  / parol: admin123
--        talaba → talaba@kutubxona.uz / parol: talaba123
--      Sinov talabasining fakulteti, yo'nalishi, guruhi va HEMIS ID'si to'ldirilgan.
-- ------------------------------------------------------------
INSERT INTO `users` (`id`, `name`, `email`, `faculty`, `direction`, `group_name`, `hemis_id`, `password`, `role`) VALUES
(1, 'Kutubxonachi', 'admin@kutubxona.uz', NULL, NULL, NULL, NULL,
    '$2y$10$w4q0/k82iJbbF5dSuix7KupmGuj9mHqOtAIux1IGku0KuLQcH3f6e', 'admin'),
(2, 'Talabayev Sinov', 'talaba@kutubxona.uz', 'Axborot texnologiyalari', 'Dasturiy injiniring', 'DI-21-01', '123456789012',
    '$2y$10$AvUe7Mqo8JUC0fRoMaivDe0m0.x32v7U73tS.wwBt6XAqAo2swam2', 'talaba');

-- ------------------------------------------------------------
-- 6.2. Namunaviy kitoblar (Texnik topshiriq talabi: kamida 30 ta yozuv)
--      Matn ichidagi apostrof (') SQL'da ikkita ('') bilan yoziladi.
-- ------------------------------------------------------------
INSERT INTO `books` (`id`, `title`, `author`, `category`, `description`, `cover_image`, `copies`) VALUES
(1,  'O''tkan kunlar', 'Abdulla Qodiriy', 'Badiiy', 'O''zbek adabiyotidagi birinchi roman.', NULL, 3),
(2,  'Mehrobdan chayon', 'Abdulla Qodiriy', 'Badiiy', 'Tarixiy roman.', NULL, 2),
(3,  'Sariq devni minib', 'Xudoyberdi To''xtaboyev', 'Badiiy', 'Sarguzasht qissa.', NULL, 4),
(4,  'Shum bola', 'G''afur G''ulom', 'Badiiy', 'Bolalik haqida mashhur qissa.', NULL, 3),
(5,  'Ikki eshik orasi', 'O''tkir Hoshimov', 'Badiiy', 'Hayot va taqdir haqida roman.', NULL, 2),
(6,  'Dunyoning ishlari', 'O''tkir Hoshimov', 'Badiiy', 'Hayotiy hikoyalar to''plami.', NULL, 3),
(7,  'Kichik shahzoda', 'Antuan de Sent-Ekzyuperi', 'Badiiy', 'Katta yoshdagilar uchun ham ibratli ertak.', NULL, 2),
(8,  'Vaqtning qisqacha tarixi', 'Stiven Xoking', 'Ilmiy', 'Koinot va vaqt haqida ilmiy-ommabop kitob.', NULL, 2),
(9,  'Loyihalarni boshqarish asoslari', 'Namunaviy muallif', 'Darslik', 'Loyiha rejalashtirish va boshqarish bo''yicha namunaviy darslik.', NULL, 5),
(10, 'Dasturlash asoslari', 'Namunaviy muallif', 'Darslik', 'Boshlovchilar uchun dasturlashga kirish.', NULL, 5),
(11, 'Ma''lumotlar bazasi asoslari', 'Namunaviy muallif', 'Darslik', 'Jadvallar va so''rovlar haqida darslik.', NULL, 4),
(12, 'Boy ota, kambag''al ota', 'Robert Kiyosaki', 'Ilmiy', 'Moliyaviy savodxonlik haqida kitob.', NULL, 3),
(13, 'Xamsa', 'Alisher Navoiy', 'Badiiy', 'Besh dostondan iborat buyuk adabiy asar.', NULL, 2),
(14, 'Kecha va kunduz', 'Cho''lpon', 'Badiiy', 'XX asr boshidagi o''zbek hayotini aks ettiruvchi roman.', NULL, 2),
(15, 'Sarob', 'Abdulla Qahhor', 'Badiiy', 'Inson taqdiri va jamiyat haqidagi roman.', NULL, 2),
(16, 'Sherlok Xolms haqida hikoyalar', 'Artur Konan Doyl', 'Badiiy', 'Mashhur detektiv hikoyalar to''plami.', NULL, 3),
(17, 'Robinzon Kruzo', 'Daniel Defo', 'Badiiy', 'Cho''l orolda omon qolish haqidagi sarguzasht roman.', NULL, 2),
(18, 'Boburnoma', 'Zahiriddin Muhammad Bobur', 'Badiiy', 'Bobur xotiralari va tarixiy-memuar asar.', NULL, 3),
(19, 'Atom odatlar', 'Jeyms Klir', 'Ilmiy', 'Kichik odatlar orqali katta natijalarga erishish haqida.', NULL, 3),
(20, 'Sapiens: insoniyatning qisqacha tarixi', 'Yuval Noy Xarari', 'Ilmiy', 'Inson turining kelib chiqishi va rivojlanishi haqida.', NULL, 2),
(21, 'Tez va sekin fikrlash', 'Daniel Kaneman', 'Ilmiy', 'Inson tafakkuri va qaror qabul qilish psixologiyasi.', NULL, 2),
(22, 'Algoritmlar asoslari', 'Namunaviy muallif', 'Darslik', 'Algoritmlar va ma''lumotlar tuzilmalariga kirish.', NULL, 4),
(23, 'Kompyuter tarmoqlari', 'Namunaviy muallif', 'Darslik', 'Tarmoq texnologiyalari va protokollari asoslari.', NULL, 3),
(24, 'Oliy matematika', 'Namunaviy muallif', 'Darslik', 'Oliy o''quv yurtlari uchun matematika kursi.', NULL, 5),
(25, 'Fizika asoslari', 'Namunaviy muallif', 'Darslik', 'Umumiy fizika kursi bo''yicha darslik.', NULL, 4),
(26, 'Iqtisodiyot nazariyasi', 'Namunaviy muallif', 'Darslik', 'Mikro va makroiqtisodiyot asoslari.', NULL, 4),
(27, 'Menejment asoslari', 'Namunaviy muallif', 'Darslik', 'Boshqaruv nazariyasi va amaliyoti.', NULL, 4),
(28, 'Ingliz tili grammatikasi', 'Namunaviy muallif', 'Darslik', 'Ingliz tili grammatikasi bo''yicha o''quv qo''llanma.', NULL, 5),
(29, 'Veb dasturlash asoslari (HTML, CSS, JS)', 'Namunaviy muallif', 'Darslik', 'Veb-sayt yaratishni noldan o''rganish uchun qo''llanma.', NULL, 4),
(30, 'Statistika asoslari', 'Namunaviy muallif', 'Darslik', 'Ma''lumotlarni tahlil qilish va statistik usullar.', NULL, 3);

-- ------------------------------------------------------------
-- 6.3. Namunaviy elektron resurslar
-- ------------------------------------------------------------
INSERT INTO `resources` (`id`, `title`, `type`, `file_url`) VALUES
(1, 'Ziyouz kutubxonasi', 'Havola', 'https://ziyouz.com'),
(2, 'Lex.uz – qonunchilik ma''lumotlari', 'Havola', 'https://lex.uz'),
(3, 'Vikipediya (o''zbek tilida)', 'Havola', 'https://uz.wikipedia.org'),
(4, 'Khan Academy – bepul darslar', 'Havola', 'https://www.khanacademy.org'),
(5, 'W3Schools – veb dasturlash', 'Havola', 'https://www.w3schools.com'),
(6, 'Dasturlash asoslari (namunaviy PDF)', 'PDF', 'uploads/resources/dasturlash-asoslari.pdf');

-- ------------------------------------------------------------
-- 6.4. Namunaviy band qilishlar (admin panelni sinash uchun)
--      Sanalar NOW() ga nisbatan beriladi, shuning uchun import qachon
--      qilinmasin, namunalar muddati o'tib avtomatik rad etilib ketmaydi.
--      1-yozuv: kutilmoqda, kod K7M4P2 (admin panelda shu kodni yozib sinang)
--      2-yozuv: tasdiqlandi (kitob talabada, served_by = kutubxonachi)
-- ------------------------------------------------------------
INSERT INTO `reservations`
  (`user_id`, `book_id`, `reserved_date`, `pickup_days`, `pickup_deadline`, `pickup_code`, `status`,
   `picked_up_at`, `due_date`, `loan_days`, `served_by`)
VALUES
(2, 9, CURDATE(), 2, DATE_ADD(NOW(), INTERVAL 2 DAY), 'K7M4P2', 'kutilmoqda',
   NULL, NULL, NULL, NULL),
(2, 1, CURDATE(), 1, DATE_ADD(NOW(), INTERVAL 1 DAY), 'R3X9WC', 'tasdiqlandi',
   NOW(), DATE_ADD(NOW(), INTERVAL 3 DAY), 3, 1);

-- ------------------------------------------------------------
-- 6.5. Namunaviy soha (field) va janr (genre) – kengaytirilgan filtrlar uchun
-- ------------------------------------------------------------
UPDATE `books` SET `field` = 'Adabiyot',      `genre` = 'Roman'            WHERE `id` IN (1, 2, 5, 14, 15, 17);
UPDATE `books` SET `field` = 'Adabiyot',      `genre` = 'Qissa'            WHERE `id` IN (3, 4, 7);
UPDATE `books` SET `field` = 'Adabiyot',      `genre` = 'Hikoyalar'        WHERE `id` IN (6, 16);
UPDATE `books` SET `field` = 'Adabiyot',      `genre` = 'Doston'           WHERE `id` = 13;
UPDATE `books` SET `field` = 'Tarix',         `genre` = 'Memuar'           WHERE `id` = 18;
UPDATE `books` SET `field` = 'Fan',           `genre` = 'Ilmiy-ommabop'    WHERE `id` IN (8, 20);
UPDATE `books` SET `field` = 'Psixologiya',   `genre` = 'Ilmiy-ommabop'    WHERE `id` IN (19, 21);
UPDATE `books` SET `field` = 'Iqtisodiyot',   `genre` = 'Ilmiy-ommabop'    WHERE `id` = 12;
UPDATE `books` SET `field` = 'IT',            `genre` = 'Darslik'          WHERE `id` IN (10, 11, 22, 23, 29);
UPDATE `books` SET `field` = 'Matematika',    `genre` = 'Darslik'          WHERE `id` IN (24, 30);
UPDATE `books` SET `field` = 'Fizika',        `genre` = 'Darslik'          WHERE `id` = 25;
UPDATE `books` SET `field` = 'Iqtisodiyot',   `genre` = 'Darslik'          WHERE `id` IN (9, 26, 27);
UPDATE `books` SET `field` = 'Tilshunoslik',  `genre` = 'Darslik'          WHERE `id` = 28;

-- ------------------------------------------------------------
-- 6.6. available_copies ni HAQIQIY bo'sh soniga to'g'rilash
--      Band qilishlar kiritilgandan KEYIN bajariladi (shuning uchun shu yerda).
--      Formula: bo'sh = jami nusxa − hozir band/talabada turganlar (manfiy emas).
-- ------------------------------------------------------------
UPDATE `books` b
SET b.`available_copies` = GREATEST(
      0,
      CAST(b.`copies` AS SIGNED) - (
        SELECT COUNT(*) FROM `reservations` r
        WHERE r.`book_id` = b.`id`
          AND r.`status` IN ('kutilmoqda', 'tasdiqlandi', 'berilgan')
      )
    );

-- ------------------------------------------------------------
-- AUTO_INCREMENT hisoblagichlarini keyingi yozuvlar uchun to'g'rilaymiz
-- ------------------------------------------------------------
ALTER TABLE `users`     AUTO_INCREMENT = 3;
ALTER TABLE `books`     AUTO_INCREMENT = 31;
ALTER TABLE `resources` AUTO_INCREMENT = 7;
ALTER TABLE `reservations` AUTO_INCREMENT = 3;

-- ============================================================
--  TUGADI. Baza tayyor: kutubxona_arm
-- ============================================================
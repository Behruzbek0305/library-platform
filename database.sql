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
--    Maydonlar: nomi, muallifi, kategoriya, tavsif, muqova rasmi, nusxalar soni
-- ------------------------------------------------------------
CREATE TABLE `books` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,         -- Kitob ID raqami
  `title`       VARCHAR(200) NOT NULL,                        -- Kitob nomi
  `author`      VARCHAR(150) NOT NULL,                        -- Muallifi
  `category`    ENUM('Darslik','Badiiy','Ilmiy') NOT NULL,    -- Kategoriya (darslik, badiiy, ilmiy)
  `description` TEXT NULL,                                    -- Qisqa tavsif
  `cover_image` VARCHAR(255) NULL,                            -- Muqova rasmi manzili (masalan: uploads/covers/1.jpg)
  `copies`      INT UNSIGNED NOT NULL DEFAULT 1,              -- Kutubxonadagi jami nusxalar soni
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Katalogga qo'shilgan vaqti
  PRIMARY KEY (`id`),
  KEY `idx_books_title` (`title`),                            -- Nomi bo'yicha qidiruvni tezlashtiradi
  KEY `idx_books_author` (`author`),                          -- Muallif bo'yicha qidiruvni tezlashtiradi
  KEY `idx_books_category` (`category`)                       -- Kategoriya bo'yicha saralashni tezlashtiradi
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. JADVAL: reservations (Band qilishlar)
--    Maydonlar: foydalanuvchi, kitob, sana, holati
--    Holatlar: kutilmoqda, tasdiqlandi, qaytarildi, rad_etildi
-- ------------------------------------------------------------
CREATE TABLE `reservations` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,       -- Band qilish ID raqami
  `user_id`       INT UNSIGNED NOT NULL,                      -- Kim band qildi (users.id)
  `book_id`       INT UNSIGNED NOT NULL,                      -- Qaysi kitob (books.id)
  `reserved_date` DATE NOT NULL,                              -- Band qilingan sana
  `status`        ENUM('kutilmoqda','tasdiqlandi','qaytarildi','rad_etildi') NOT NULL DEFAULT 'kutilmoqda', -- Holati
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Yozuv yaratilgan vaqt
  PRIMARY KEY (`id`),
  KEY `idx_res_user` (`user_id`),
  KEY `idx_res_book` (`book_id`),
  KEY `idx_res_status` (`status`),
  -- Foydalanuvchi o'chirilsa, uning band qilishlari ham o'chadi
  CONSTRAINT `fk_res_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  -- Kitob o'chirilsa (admin o'chirsa), unga tegishli band qilishlar ham o'chadi
  CONSTRAINT `fk_res_book`
    FOREIGN KEY (`book_id`) REFERENCES `books` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
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
-- ------------------------------------------------------------
INSERT INTO `reservations` (`user_id`, `book_id`, `reserved_date`, `status`) VALUES
(2, 9, '2026-10-20', 'kutilmoqda'),
(2, 1, '2026-10-18', 'tasdiqlandi');

-- ------------------------------------------------------------
-- AUTO_INCREMENT hisoblagichlarini keyingi yozuvlar uchun to'g'rilaymiz
-- ------------------------------------------------------------
ALTER TABLE `users`     AUTO_INCREMENT = 3;
ALTER TABLE `books`     AUTO_INCREMENT = 31;
ALTER TABLE `resources` AUTO_INCREMENT = 7;

-- ============================================================
--  TUGADI. Baza tayyor: kutubxona_arm
-- ============================================================
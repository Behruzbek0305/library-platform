# 📚 Kutubxona – axborot resurs markazi platformasi

Universitet talabalari uchun oddiy onlayn kutubxona (MVP): kitoblar katalogi, qidiruv, kitobni oldindan band qilish va elektron resurslar. Kutubxonachi uchun alohida admin panel bor.

**Fan:** Loyihalarni boshqarish · **Turi:** o'quv loyihasi (MVP) · **Interfeys tili:** o'zbekcha

---

## ✨ Imkoniyatlar

Texnik topshiriqdagi 8 ta asosiy imkoniyat:

| № | Imkoniyat | Kim uchun | Qayerda |
| --- | --- | --- | --- |
| 1 | Ro'yxatdan o'tish va tizimga kirish | Talaba | `register.php`, `login.php` |
| 2 | Kitoblar katalogini ko'rish | Hamma | `catalog.php`, `book.php` |
| 3 | Nomi yoki muallifi bo'yicha qidirish | Hamma | `index.php`, `catalog.php` |
| 4 | Kategoriya bo'yicha saralash (darslik, badiiy, ilmiy) | Hamma | `catalog.php` |
| 5 | Kitobni band qilish (1, 2, 3 kun muddat bilan) va o'z band qilganlarini ko'rish | Talaba | `reserve.php`, `cabinet.php` |
| 6 | Elektron resursni (PDF, havola) yuklab olish | Talaba | `resources.php` |
| 7 | Kitob qo'shish, tahrirlash va o'chirish (muqova rasmi bilan) | Kutubxonachi | `admin/books.php`, `admin/book_form.php` |
| 8 | Band qilishlarni ko'rish, tasdiqlash va rad etish | Kutubxonachi | `admin/index.php` |

Qo'shimcha qulayliklar: qidiruvda yozish paytida tavsiyalar chiqadi (Enter bosilsa, natijalar ro'yxat ko'rinishida), telefonda menyu o'ng tomondan ochiladi, jadvallar telefonda kartochkaga aylanadi.

### Band qilish holatlari

| Holat | Ma'nosi | Nusxani band qiladimi? |
| --- | --- | --- |
| Kutilmoqda | Talaba band qildi, kutubxonachi hali ko'rmagan | Ha |
| Tasdiqlandi | Kutubxonachi tasdiqladi va qaytarish kunini (1/2/3/5/10) tanladi; kitob talabada. Muddat o'tsa "Kechikkan" belgisi chiqadi, lekin kitob avtomatik qaytarilmaydi | Ha |
| Qaytarildi | Kutubxonachi kitobni qo'lda qabul qildi («Qaytarildi» tugmasi) | Yo'q |
| Rad etildi | Kutubxonachi rad etdi yoki olib ketish muddati o'tib avtomatik rad etildi | Yo'q |

> **Olib ketish muddati (3-bosqich):** Talaba kitob band qilishda olib ketish muddatini (1 kun, 2 kun yoki ko'pi bilan 3 kun) belgilaydi. Belgilangan muddat ichida kutubxonachi tasdiqlab, talaba olib ketmasa, tizim avtomatik ravishda band qilishni "Rad etildi (muddati o'tgan)" holatiga o'tkazadi va kitob darhol boshqa talabalar uchun bo'sh bo'ladi. Shuningdek, `scripts/expire_reservations.php` orqali cron vazifa qilib qo'yish ham mumkin.

> **Offlayn berish:** talaba onlayn band qilmagan bo'lsa, kutubxonachi kitob oynasida (yoki kitob sahifasida) «Offlayn Registratsiya qilish» tugmasi orqali kitobni to'g'ridan-to'g'ri beradi (`admin/process-offline.php`). Talaba bazada bo'lmasa, avtomatik akkaunt yaratiladi (`password_set = 0`). Talaba keyinroq **«Ro'yxatdan o'tish»** sahifasida shu email va HEMIS ID bilan parol o'rnatadi – yangi akkaunt ochilmaydi, mavjudi faollashadi.

> **Xodimlar izi:** admin panel va kabinetda kitobni kim tasdiqlagani (yoki offlayn bergani), kim qabul qilgani («Qaytarildi») va kim rad etgani alohida ko'rsatiladi.

---

## 🛠 Texnologiyalar

- **Backend:** sof PHP 8.1+ (PDO, framework yo'q)
- **Baza:** MySQL / MariaDB (`utf8mb4_unicode_ci`)
- **Frontend:** HTML5, [Tailwind CSS](https://tailwindcss.com) (CDN orqali), oddiy JavaScript (yon menyu va jonli qidiruv uchun, kutubxonasiz)
- **Narx:** faqat bepul vositalar

Eslatma: texnik topshiriqdagi "Variant B" HTML/CSS/JS + Firebase edi. Loyiha o'rniga PHP + MySQL bilan yozildi, chunki ma'lumotlar server tomonida saqlanadi va parol xavfsiz xeshlanadi.

---

## 🚀 O'rnatish (mahalliy kompyuterda)

### Talablar

- PHP 8.1 yoki yangisi, `pdo_mysql` kengaytmasi yoqilgan
- MySQL yoki MariaDB
- Veb-server (Apache yoki Nginx + php-fpm), yoki PHP'ning o'rnatilgan serveri

### Qadamlar

**1. Loyihani oling**

```bash
git clone https://github.com/Behruzbek0305/library-platform.git
cd library-platform
```

Veb-server ildizi ichiga qo'ying (masalan Arch'da `/srv/http/library-platform`).

**2. Bazani yarating**

```bash
sudo mariadb < database.sql
```

Bu `kutubxona_arm` bazasini, jadvallarni va sinov ma'lumotlarini yaratadi.

> Diqqat: `database.sql` bazani o'chirib, qayta yaratadi. Ishlayotgan bazaga qayta import qilmang.

**3. Baza uchun alohida foydalanuvchi yarating** (`root` bilan ishlash tavsiya etilmaydi)

```bash
sudo mariadb -e "CREATE USER 'kutubxona'@'localhost' IDENTIFIED BY 'KUCHLI_PAROL'; GRANT ALL PRIVILEGES ON kutubxona_arm.* TO 'kutubxona'@'localhost'; FLUSH PRIVILEGES;"
```

**4. Ulanish sozlamalarini yozing**

```bash
cp config/db.example.php config/db.php
```

`config/db.php` ichida `DB_USER` va `DB_PASS`ni o'zingiznikiga almashtiring. Bu fayl `.gitignore`da, GitHub'ga yuklanmaydi.

**5. Rasmlar uchun papka ruxsati**

```bash
mkdir -p uploads/covers uploads/resources
sudo chown -R $USER:http uploads
sudo chmod -R 775 uploads
```

(`http` – Arch'dagi veb-server foydalanuvchisi. Ubuntu/Debian'da `www-data`.)

**6. Sayt manzilini tekshiring**

`includes/functions.php` ichidagi `BASE_URL` papka nomiga mos bo'lsin:

```php
define('BASE_URL', '/library-platform');
```

Ildizdan ishga tushirsangiz (masalan `php -S localhost:8000`), qiymatni bo'sh qoldiring: `define('BASE_URL', '');`

**6.1. Mavjud bazani yangilash** (yangi o'rnatishda kerak emas – `database.sql` yangi)

phpMyAdmin → `kutubxona_arm` → «SQL» bo'limiga `migrations/010_tuzatishlar.sql` ni qo'ying (faqat bir marta).

**6.2. Vaqt zonasi:** `config/db.php` ulanish paytida MySQL vaqtini O'zbekiston vaqtiga (`+05:00`) o'rnatadi – muddatlar va «Band qilingan» vaqti to'g'ri chiqishi uchun. Qo'shimcha sozlash kerak emas.

**6.3. Avtomatik rad etish (ixtiyoriy, cron):** muddati o'tgan band qilishlar sayt ochilganda ham tozalanadi. Sayt ochilmasa ham tozalanishi uchun:

```bash
0 * * * * php /srv/http/library-platform/scripts/expire_reservations.php >> /var/log/kutubxona_cron.log 2>&1
```

Skript faqat buyruq satridan ishlaydi (brauzerda 403 beradi).

**7. Oching:** `http://localhost/library-platform/`

---

## 🔑 Sinov akkauntlari

Bular faqat mahalliy sinov uchun. Haqiqiy serverga chiqarishdan oldin parollarni almashtiring yoki akkauntlarni o'chiring.

| Rol | Email | Parol |
| --- | --- | --- |
| Kutubxonachi (admin) | `admin@kutubxona.uz` | `admin123` |
| Talaba | `talaba@kutubxona.uz` | `talaba123` |

Yangi talabalar `register.php` orqali o'zlari ro'yxatdan o'tadi. Admin akkaunt faqat bazadan qo'shiladi.

---

## 📁 Papka tuzilmasi

```
library-platform/
├── index.php            Bosh sahifa: qidiruv, filtrlar, yangi kitoblar, kategoriyalar
├── catalog.php          Katalog: qidiruv, soha / kategoriya / janr filtrlari
├── book.php             Kitob sahifasi, "Band qilish" (talaba) / offlayn berish (kutubxonachi)
├── reserve.php          Band qilish (POST, tranzaksiya, 6 belgili kod)
├── cabinet.php          Talabaning kabineti (taymer, kod, xodimlar izi)
├── profile.php          Talaba ma'lumotlarini tahrirlash
├── search.php           Qidiruv tavsiyalari (JSON), yozish paytida chaqiriladi
├── resources.php        Elektron resurslar (faqat talaba)
├── register.php         Ro'yxatdan o'tish (offlayn akkauntni faollashtirish ham shu yerda)
├── login.php            Kirish (xato urinishlar cheklangan)
├── logout.php           Chiqish (POST)
├── admin/
│   ├── index.php            Band qilishlar: tasdiqlash (qaytarish kunini tanlash), qaytarildi, rad etish
│   ├── process-offline.php  Offlayn berish (POST)
│   ├── books.php            Kitoblar ro'yxati va o'chirish
│   ├── book_form.php        Kitob qo'shish va tahrirlash (rasm, soha, janr)
│   └── students.php         Talabalar ro'yxati va qidiruv
├── includes/
│   ├── functions.php        e(), url(), redirect(), flash, CSRF, HEMIS ID qoidasi
│   ├── auth.php             Sessiya, rollar, requireLogin/Student/Admin
│   ├── book_helpers.php     Kitoblar, holat belgilari, xodimlar izi, avto-rad etish, kodlar
│   ├── student_helpers.php  Talaba ma'lumotlarini tekshirish va forma maydonlari
│   ├── header.php           Menyu va umumiy yuqori qism
│   └── footer.php           Kitob oynasi (modal) va umumiy pastki qism
├── assets/js/
│   ├── menu.js              Telefon uchun yon menyu
│   ├── live-search.js       Yozish paytida kitob tavsiyalari
│   ├── book-modal.js        Kitob oynasi va offlayn forma
│   ├── cabinet-modal.js     Kabinetdagi buyurtma oynasi
│   └── hemis.js             HEMIS ID tekshiruvi va nusxalash
├── scripts/
│   └── expire_reservations.php  Cron: muddati o'tgan band qilishlarni rad etish (faqat CLI)
├── migrations/
│   └── 010_tuzatishlar.sql      Mavjud bazani yangilash (bir marta)
├── config/
│   ├── db.php               Baza ulanishi (GitHub'da yo'q)
│   └── db.example.php       Namuna (parolsiz)
├── uploads/
│   ├── covers/              Kitob muqovalari
│   └── resources/           PDF resurslar
└── database.sql             Baza sxemasi va sinov ma'lumotlari         Baza sxemasi va sinov ma'lumotlari
```

### Ma'lumotlar bazasi

4 ta jadval: `users`, `books`, `reservations`, `resources`. `reservations` jadvali `users` va `books`ga tashqi kalit (Foreign Key) orqali bog'langan.

---

## 🔒 Xavfsizlik

- Parollar `password_hash()` bilan saqlanadi, `password_verify()` bilan tekshiriladi
- Hamma SQL so'rovlari PDO prepared statements bilan (SQL Injection'dan himoya)
- Sahifaga chiqadigan matn `htmlspecialchars` orqali o'tadi (XSS'dan himoya)
- Hamma POST formalarda CSRF token bor, "Chiqish" ham POST orqali
- Kirishda sessiya ID'si yangilanadi, sessiya cookie'si `HttpOnly` va `SameSite`
- Rollar serverda tekshiriladi: talaba admin sahifalarga, kutubxonachi talaba sahifalariga kira olmaydi
- Rasm yuklashda turi (JPG, PNG, WEBP), hajmi (2 MB gacha) va haqiqiy rasm ekani tekshiriladi, fayl tasodifiy nom bilan saqlanadi
- Oxirgi nusxani ikki talaba bir vaqtda band qila olmaydi (tranzaksiya va qator qulfi)

---

## 🧪 Qo'lda sinash ro'yxati

- [ ] Talaba ro'yxatdan o'tadi va tizimga kiradi
- [ ] Katalogda 30 ta kitob ko'rinadi
- [ ] Qidiruv (nomi, muallif) va kategoriya filtri ishlaydi
- [ ] Talaba kitobni band qiladi, kabinetda ko'radi, bekor qiladi
- [ ] Kutubxonachi band qilishni tasdiqlaydi, rad etadi, "qaytarildi" qiladi
- [ ] Kutubxonachi kitob qo'shadi (rasm bilan), tahrirlaydi, o'chiradi
- [ ] Talaba PDF resursni yuklab oladi
- [ ] Sayt telefon ekranida to'g'ri ochiladi
- [ ] Parol bazada `$2y$...` xesh ko'rinishida turibdi
- [ ] Admin panelda «Tasdiqlash» bosilganda tanlangan kun (1/2/3/5/10) bo'yicha qaytarish muddati va taymer chiqadi
- [ ] Muddati o'tgan tasdiqlangan kitobda qizil «Kechikkan» belgisi chiqadi, kitob o'zi qaytmaydi
- [ ] Olib ketish muddati o'tgan band qilish o'z vaqtida (5 soat kechikmasdan) «Avto-rad etilgan» bo'ladi
- [ ] Offlayn berilgan talaba «Ro'yxatdan o'tish»da o'z email va HEMIS ID si bilan parol o'rnatib kira oladi
- [ ] Admin va kabinetda tasdiqlagan, qabul qilgan va rad etgan xodim alohida ko'rinadi
- [ ] `php scripts/expire_reservations.php` haqiqiy rad etilganlar sonini chiqaradi

---

## 🔮 Keyingi versiyalar uchun g'oyalar

Texnik topshiriq bo'yicha hozircha loyihaga kirmaydi:

- Resurslarni admin paneldan boshqarish (hozir phpMyAdmin orqali)
- Katalogda sahifalash (kitoblar ko'paysa)
- Parolni tiklash
- Ruscha va inglizcha interfeys

To'lov, chat, sun'iy intellekt tavsiyalari va mobil ilova loyihaga kirmaydi.

---

## 📄 Eslatma

O'quv loyihasi. Haqiqiy serverga chiqarishdan oldin: HTTPS yoqing, `config/db.php`da kuchli parol qo'ying (parol hech qachon ommaviy joyga yuklanmasin), sinov akkauntlarini o'chiring va `uploads/` papkasida PHP bajarilishini taqiqlang.
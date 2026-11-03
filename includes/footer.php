<?php
/**
 * ============================================================
 *  Fayl: includes/footer.php
 *  Vazifasi: Sahifaning pastki qismi (</main>, kitob oynasi, footer, JavaScript fayllari).
 *  header.php da ochilgan <main> shu yerda yopiladi.
 *
 *  JavaScript fayllari (Vanilla JS, assets/js/ papkasida):
 *    menu.js         – telefon uchun yon panel (drawer)
 *    live-search.js  – qidiruvda yozish paytidagi tavsiyalar
 *    book-modal.js   – kitob kartochkasi bosilganda qalqib chiqadigan oyna
 *
 *  1-bosqich: kitob oynasi (modal) shu yerda joylashgan. Oynaning ichini
 *  book-modal.js to'ldiradi; bu yerda faqat bo'sh qolip va "Band qilish"
 *  formasi (CSRF token bilan) turadi. Band qilishning o'zi avvalgidek
 *  reserve.php orqali bajariladi.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/book_helpers.php';

// Oyna ichidagi tugmalar foydalanuvchi roliga qarab o'zgaradi.
// Rol: guest (kirmagan) | student (talaba) | admin (kutubxonachi).
$footerUser = currentUser();
$modalRole  = $footerUser === null ? 'guest' : ($footerUser['role'] === 'admin' ? 'admin' : 'student');

// JS kelgan-ketganini tekshiramiz (footer.php skriptni shu manzildan ulaydi)
$hasBookModalJs = is_readable(__DIR__ . '/../assets/js/book-modal.js');
?>
    </main>

    <?php if ($modalRole === 'admin' && !$hasBookModalJs): ?>
        <!-- Kutubxonachiga ogohlantirish: skript bo'lmasa modalning tugmalari ishlamaydi -->
        <div class="mb-4 border rounded-md px-4 py-3 bg-amber-50 text-amber-800 border-amber-300 text-sm">
            ⚠️ <b>Diqqat:</b> <code>assets/js/book-modal.js</code> fayli topilmadi –
            kitob oynasi va «Offlayn Registratsiya qilish» tugmasi ishlamaydi.
            Faylni loyihangizdagi <code>assets/js/</code> papkasiga qo'ying.
        </div>
    <?php endif; ?>

    <!-- ====== KITOB OYNASI (qalqib chiqadi, orqa fon qoraroq) ======
         "inert" – yopiq paytda ichidagi elementlarga Tab bilan o'tib bo'lmaydi.
         Oynaning o'zi bo'sh qolip: matn va rasmni book-modal.js qo'yadi. -->
    <div id="bookModal" role="dialog" aria-modal="true" aria-labelledby="bookModalTitle" inert
         data-role="<?= e($modalRole) ?>"
         class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50
                opacity-0 pointer-events-none transition-opacity duration-200">

        <div class="relative w-full max-w-md max-h-[90vh] overflow-y-auto bg-white rounded-xl shadow-2xl">

            <!-- Yopish tugmasi -->
            <button type="button" id="bookModalClose" aria-label="Oynani yopish"
                    class="absolute top-2 right-2 z-10 rounded-full bg-white/90 hover:bg-white shadow
                           w-9 h-9 text-lg text-slate-600">✕</button>

            <!-- Muqova: rasm to'liq ko'rinadi (object-contain), rasm bo'lmasa kategoriya belgisi -->
            <div id="bookModalCover" class="h-52 flex items-center justify-center text-7xl bg-slate-100">
                <img id="bookModalImg" src="" alt="" class="hidden h-full w-full object-contain">
                <span id="bookModalEmoji"></span>
            </div>

            <div class="p-5">
                <h2 id="bookModalTitle" class="text-xl font-bold leading-snug break-words"></h2>
                <p class="text-slate-600 mt-1 break-words"><b>Muallif:</b> <span id="bookModalAuthor"></span></p>

                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    <span id="bookModalCategory" class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-600"></span>
                    <span id="bookModalAvailable" class="px-2 py-0.5 rounded-full"></span>
                </div>

                <p id="bookModalDesc" class="mt-3 text-sm text-slate-700 whitespace-pre-line break-words"></p>

                <!-- ====== HOLATGA QARAB TUGMALAR (qaysi biri ko'rinishini JS belgilaydi) ====== -->
                <div class="mt-5">

                    <!-- Tizimga kirmagan -->
                    <p data-state="guest" class="hidden text-sm text-slate-500">
                        Band qilish uchun avval
                        <a href="<?= e(url('login.php')) ?>" class="text-blue-600 hover:underline">tizimga kiring</a>
                        yoki
                        <a href="<?= e(url('register.php')) ?>" class="text-blue-600 hover:underline">ro'yxatdan o'ting</a>.
                    </p>

                    <!-- ====== Kutubxonachi: avvalgi kulrang "Faqat talabalar band qila oladi"
                         tugmasi o'rniga yashil "Offlayn Registratsiya qilish" tugmasi.
                         data-offline-endpoint – forma qayerga POST bo'lishini JS bilib olishi
                         uchun (shu atribut bo'lmasa ham ishlaydi, lekin tekshiruv osonroq). ====== -->
                    <div data-state="admin" class="hidden space-y-3"
                         data-offline-endpoint="<?= e(url('admin/process-offline.php')) ?>">
                        <button type="button" id="bookModalOfflineBtn"
                                class="w-full bg-green-600 hover:bg-green-700
                                       disabled:bg-slate-400 disabled:hover:bg-slate-400 disabled:cursor-not-allowed
                                       text-white font-semibold rounded-md px-5 py-2.5">
                            Offlayn Registratsiya qilish
                        </button>

                        <p id="bookModalOfflineWarn" class="hidden text-xs text-red-700 bg-red-50 border border-red-200 rounded-md px-3 py-2">
                            Hozir bo'sh nusxa yo'q – kitob qaytarilguncha offlayn berib bo'lmaydi.
                        </p>
                    </div>

                    <!-- ====== Kutubxonachi: kitob modalining ichidagi talaba ma'lumotlari formasi ======
                          POST → admin/process-offline.php (book_id bilan).
                          JS bu blokni ochib/yopadi va book_id ni to'ldiradi. -->
                    <div data-state="offline" class="hidden border border-green-200 bg-green-50/60 rounded-lg p-3">
                        <div class="flex items-start justify-between gap-2">
                            <h3 class="font-bold text-green-800 leading-snug">Kitobni talabaga berish (offlayn)</h3>
                            <button type="button" id="bookModalOfflineBack" data-offline-back aria-label="Orqaga"
                                    class="shrink-0 rounded-full w-7 h-7 text-slate-500 hover:bg-white">✕</button>
                        </div>
                        <p class="text-xs text-slate-600 mt-1 mb-3">
                            Talaba onlayn band qilmagan – kutubxonchi uni shu yerda qo'lda kiritadi.
                        </p>

                        <form method="post" action="<?= e(url('admin/process-offline.php')) ?>"
                              id="bookModalOfflineForm" data-offline-form class="space-y-3"
                              data-endpoint="<?= e(url('admin/process-offline.php')) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="book_id" id="bookModalOfflineBookId" value="">
                            <input type="hidden" name="from" value="modal">

                            <div class="space-y-1">
                                <label for="off_hemis_modal" class="block text-sm font-semibold text-slate-700">
                                    Talabaning HEMIS ID si
                                </label>
                                <input type="text" id="off_hemis_modal" name="hemis_id" required
                                       maxlength="<?= (int) HEMIS_ID_LENGTH ?>" minlength="<?= (int) HEMIS_ID_LENGTH ?>"
                                       pattern="\d{<?= (int) HEMIS_ID_LENGTH ?>}" inputmode="numeric" autocomplete="off"
                                       data-digits-only
                                       placeholder="Masalan: 341231102185" class="<?= e(offlineFieldClass()) ?>">
                                <p class="text-xs text-slate-500">Faqat raqam, aniq <?= (int) HEMIS_ID_LENGTH ?> ta.</p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div class="space-y-1">
                                    <label for="off_name_modal" class="block text-sm font-semibold text-slate-700">
                                        Talaba FIO si
                                    </label>
                                    <input type="text" id="off_name_modal" name="student_name" required
                                           maxlength="100" autocomplete="off"
                                           placeholder="Karimov Aziz Bahodirovich" class="<?= e(offlineFieldClass()) ?>">
                                </div>

                                <div class="space-y-1">
                                    <label for="off_email_modal" class="block text-sm font-semibold text-slate-700">
                                        Email adresi
                                    </label>
                                    <input type="email" id="off_email_modal" name="student_email" required
                                           maxlength="150" autocomplete="off"
                                           placeholder="talaba@universitet.uz" class="<?= e(offlineFieldClass()) ?>">
                                </div>

                                <div class="space-y-1">
                                    <label for="off_faculty_modal" class="block text-sm font-semibold text-slate-700">
                                        Fakulteti
                                    </label>
                                    <input type="text" id="off_faculty_modal" name="faculty" required
                                           maxlength="150" autocomplete="off"
                                           placeholder="Axborot texnologiyalari" class="<?= e(offlineFieldClass()) ?>">
                                </div>

                                <div class="space-y-1">
                                    <label for="off_group_modal" class="block text-sm font-semibold text-slate-700">
                                        Guruhi
                                    </label>
                                    <input type="text" id="off_group_modal" name="group_name" required
                                           maxlength="30" autocomplete="off"
                                           placeholder="DI-21-01" class="<?= e(offlineFieldClass()) ?>">
                                </div>
                            </div>

                            <?php renderOfflineDaysSelect('off_loan_days_modal', 'loan_days', 3); ?>

                            <button type="submit"
                                    class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold rounded-md px-5 py-2.5">
                                Talabaga berish
                            </button>

                            <button type="button" data-offline-back
                                    class="w-full text-sm text-slate-600 hover:text-slate-800 py-1">
                                ← Orqaga
                            </button>
                        </form>
                    </div>

                    <!-- Talaba: allaqachon band qilgan -->
                    <p data-state="booked"
                       class="hidden text-sm text-green-700 bg-green-50 border border-green-300 rounded-md px-3 py-2">
                        Siz bu kitobni allaqachon band qilgansiz.
                        <a href="<?= e(url('cabinet.php')) ?>" class="underline">Kabinetga o'tish</a>
                    </p>

                    <!-- Talaba: bo'sh nusxa yo'q -->
                    <button type="button" data-state="unavailable" disabled
                            class="hidden w-full bg-slate-400 text-white rounded-md px-5 py-2.5 cursor-not-allowed">
                        Hozir bo'sh nusxa yo'q
                    </button>

                    <!-- Talaba: band qilish (reserve.php avvalgidek ishlaydi) -->
                    <form data-state="reserve" method="post" action="<?= e(url('reserve.php')) ?>" class="hidden space-y-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="book_id" id="bookModalBookId" value="">

                        <?php renderPickupSelect('modal_pickup_days', 'pickup_days', 1); ?>

                        <button type="submit"
                                class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-md px-5 py-2.5">
                            Band qilish
                        </button>
                    </form>

                    <!-- "Batafsil" havolasi: to'liq kitob sahifasi -->
                    <a id="bookModalMore" href="#"
                       class="block mt-3 text-center text-sm text-blue-600 hover:underline">
                        Kitob sahifasini ochish →
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== SAYT OSTKI QISMI ====== -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-sm text-slate-500">
        © <?= date('Y') ?> Kutubxona – axborot resurs markazi. Talabalar loyihasi.
    </footer>

    <?php /* ============================================================
        MUHIM: skript manzili papka chuqurligiga qarab o'zgaradi.
        header.php hisoblagan $jsBase ishlatiladi:
          index.php / book.php  (ildizda) → assets/js/...
          admin/index.php       (ichida)  → ../assets/js/...
        Nodir holat: fayl nomini o'zgartirib qo'ygan bo'lsangiz yoki
        git checkout qisman bo'lsa, brauzer 404 beradi va HECH QANDAY
        tugma ishlamaydi. Shu sababli tekshirib, konsolga xabar chiqaramiz.
        ============================================================ */ ?>
    <?php if (!$hasBookModalJs): ?>
        <script>
            console.error('[Kutubxona-ARM] assets/js/book-modal.js topilmadi! Kitob oynasi va tugmalar ishlamaydi. Faylni "assets/js/" papkasiga qo\'ying.');
        </script>
    <?php endif; ?>

    <script src="<?= e(url('assets/js/menu.js')) ?>" defer></script>
    <script src="<?= e(url('assets/js/live-search.js')) ?>" defer></script>
    <script src="<?= e(url('assets/js/book-modal.js')) ?>" defer></script>
    <!-- HEMIS ID ni bir bosishda nusxalash va maydonda faqat raqamga ruxsat berish -->
    <script src="<?= e(url('assets/js/hemis.js')) ?>" defer></script>
</body>
</html>
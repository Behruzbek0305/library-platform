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

// Oyna ichidagi tugmalar foydalanuvchi roliga qarab o'zgaradi
$footerUser = currentUser();
$modalRole  = $footerUser === null ? 'guest' : ($footerUser['role'] === 'talaba' ? 'student' : 'admin');
?>
    </main>

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

                    <!-- Kutubxonachi -->
                    <p data-state="admin" class="hidden text-sm text-slate-500">
                        Kitoblarni faqat talabalar band qila oladi.
                    </p>

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

    <script src="<?= e(url('assets/js/menu.js')) ?>" defer></script>
    <script src="<?= e(url('assets/js/live-search.js')) ?>" defer></script>
    <script src="<?= e(url('assets/js/book-modal.js')) ?>" defer></script>
</body>
</html>
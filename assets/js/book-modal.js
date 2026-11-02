/**
 * ============================================================
 *  Fayl: assets/js/book-modal.js
 *  Vazifasi: Kitob kartochkasi yoki qatori bosilganda kitob sahifasiga
 *            kirib ketmasdan, ixcham oyna qalqib chiqishi.
 *
 *  Qanday ulanadi: kitob havolasida "data-book-card" atributi bor
 *  (includes/book_helpers.php shuni qo'yadi), oyna qolipi includes/footer.php da.
 *
 *  Imkoniyatlar:
 *    - kartochka bosilganda oyna ochiladi, orqa fon qoraroq bo'ladi
 *    - rasm to'liq ko'rinadi (kesilmaydi)
 *    - tugmalar foydalanuvchi holatiga qarab o'zgaradi:
 *        kirmagan / kutubxonachi / allaqachon band qilgan / bo'sh nusxa yo'q / band qilish
 *    - 1-bosqich: kutubxonachi uchun "Faqat talabalar band qila oladi" degan
 *      kulrang joyda yashil "Offlayn Registratsiya qilish" tugmasi chiqadi.
 *      Bosilsa – shu oynaning ichida talaba ma'lumotlari formasi ochiladi
 *      (HEMIS ID, FIO, email, fakultet, guruh, necha kunga) va POST orqali
 *      admin/process-offline.php ga book_id bilan yuboriladi.
 *    - Esc, orqa fonga bosish yoki ✕ tugmasi oynani yopadi
 *    - Tab tugmasi oyna ichida aylanib yuradi, yopilganda fokus joyiga qaytadi
 *    - oyna ochiq paytda orqadagi sahifa aylanmaydi
 *    - Ctrl/Cmd/Shift + bosish yoki o'rta tugma – havola odatdagidek yangi oynada ochiladi
 *
 *  Xavfsizlik: matnlar faqat textContent orqali qo'yiladi (innerHTML ishlatilmaydi).
 * ============================================================
 */
(function () {
    'use strict';

    var modal = document.getElementById('bookModal');
    if (!modal) {
        return;
    }

    // Oyna ichidagi elementlar
    var closeBtn   = document.getElementById('bookModalClose');
    var coverBox   = document.getElementById('bookModalCover');
    var coverImg   = document.getElementById('bookModalImg');
    var coverEmoji = document.getElementById('bookModalEmoji');
    var titleEl    = document.getElementById('bookModalTitle');
    var authorEl   = document.getElementById('bookModalAuthor');
    var categoryEl = document.getElementById('bookModalCategory');
    var availEl    = document.getElementById('bookModalAvailable');
    var descEl     = document.getElementById('bookModalDesc');
    var bookIdEl     = document.getElementById('bookModalBookId');
    var pickupSelect = document.getElementById('modal_pickup_days');
    var moreLink     = document.getElementById('bookModalMore');

    // Offlayn berish (faqat kutubxonachi) elementlari
    var offlineBtn      = document.getElementById('bookModalOfflineBtn');
    var offlineWarn     = document.getElementById('bookModalOfflineWarn');
    var offlineBookIdEl = document.getElementById('bookModalOfflineBookId');
    var offlineForm     = document.getElementById('bookModalOfflineForm');

    var role      = modal.getAttribute('data-role');   // guest | student | admin
    var stateEls  = modal.querySelectorAll('[data-state]');
    var lastFocus = null;                              // yopilganda fokus qaytadigan element

    var currentAvailable = 0;                          // joriy kitobdagi bo'sh nusxalar (ogohlantirish uchun)

    // ============================================================
    //  OFFLAYN FORMA MANZILI (admin/process-offline.php)
    //  HTML'da action="..." hamma vaqtda to'g'ri yozilgan, lekin
    //  brauzer eski cache'dagi HTML'ni ko'rsatsa yoki sahifani
    //  boshqa papkadan ochsa – forma manzili buzilishi mumkin.
    //  Shuning uchun JS uni tekshiradi va kerak bo'lsa tiklaydi.
    // ============================================================
    function offlineEndpoint() {
        if (offlineForm && offlineForm.getAttribute('data-endpoint')) {
            return offlineForm.getAttribute('data-endpoint');
        }
        var host = modal.querySelector('[data-offline-endpoint]');
        if (host && host.getAttribute('data-offline-endpoint')) {
            return host.getAttribute('data-offline-endpoint');
        }
        return '';
    }

    /** Forma action'ini to'g'rilaydi; noto'g'ri bo'lsa konsolga yozadi */
    function ensureOfflineAction() {
        if (!offlineForm) {
            return;
        }

        var want = offlineEndpoint();
        if (!want) {
            console.warn('[Kutubxona-ARM] Offlayn forma manzili topilmadi (data-endpoint / data-offline-endpoint).');
            return;
        }

        var have = offlineForm.getAttribute('action') || '';
        if (have !== want) {
            console.info('[Kutubxona-ARM] Forma action\'i tuzatildi: "' + have + '" → "' + want + '"');
            offlineForm.setAttribute('action', want);
        }
    }

    // Fokus olishi mumkin bo'lgan elementlar (ko'rinmaydiganlari keyin filtrlanadi)
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

    // Muqova orqa fon ranglari (Tailwind sinflari)
    var BG_CLASSES = ['bg-slate-100', 'bg-blue-100', 'bg-pink-100', 'bg-green-100', 'bg-slate-200'];

    function isOpen() {
        return !modal.classList.contains('pointer-events-none');
    }

    /** Faqat kerakli holat blokini ko'rsatadi, qolganlarini yashiradi */
    function showState(name) {
        Array.prototype.forEach.call(stateEls, function (node) {
            node.classList.toggle('hidden', node.getAttribute('data-state') !== name);
        });
    }

    /** Foydalanuvchi holati va kitobga qarab qaysi blok ko'rinishini aniqlaydi */
    function pickState(available, booked) {
        if (role === 'guest')   { return 'guest'; }
        if (role === 'admin')   { return 'admin'; }
        if (booked)             { return 'booked'; }
        if (available <= 0)     { return 'unavailable'; }
        return 'reserve';
    }

    /** Oyna ochilganda yoki "Orqaga" bosilganda: admin blokiga qaytadi */
    function resetAdminView() {
        showState('admin');
        if (offlineWarn) {
            offlineWarn.classList.toggle('hidden', currentAvailable > 0);
        }
    }

    /** Kutubxonachi: kitob modalining ichida talaba ma'lumotlari formasini ochadi */
    function openOfflineForm() {
        // Formani ochishga ruxsat: faqat kutubxonachi uchun
        if (role !== 'admin') {
            return;
        }

        var bookId = bookIdEl ? bookIdEl.value : '';

        // Kitob ID'si qo'ymasdan forma yuborilmasin
        if (!bookId) {
            console.warn('[Kutubxona-ARM] book_id topilmadi – oyna kartochkadagi data-id dan olinmagan.');
        }

        // Forma manzili to'g'rimi? Kerak bo'lsa tiklaymiz.
        ensureOfflineAction();

        if (offlineBookIdEl) {
            offlineBookIdEl.value = bookId;
        }

        // Bo'sh nusxa bo'lmasa – ogohlantirish ko'rsatamiz (forma baribir ochiladi)
        if (offlineWarn) {
            offlineWarn.classList.toggle('hidden', currentAvailable > 0);
        }

        showState('offline');

        if (offlineForm) {
            offlineForm.reset();

            // reset() yashirin maydonni ham tozalashi mumkin – qaytaramiz
            if (offlineBookIdEl) {
                offlineBookIdEl.value = bookId;
            }

            modal.scrollTop = 0;

            var firstField = offlineForm.querySelector('input[name="hemis_id"]');
            if (firstField) {
                firstField.focus({ preventScroll: true });
            }

            console.info('[Kutubxona-ARM] Offlayn forma ochildi. book_id=' + bookId +
                         ', manzil=' + (offlineForm.getAttribute('action') || '(yo\'q)'));
        } else {
            console.error('[Kutubxona-ARM] #bookModalOfflineForm element topilmadi – HTML yangilanmagan.');
        }
    }

    /** HEMIS ID maydonida faqat raqam qoldiramiz (bo'shliq va harflar o'chiriladi) */
    function bindHemisFilter(form) {
        if (!form) {
            return;
        }

        var field = form.querySelector('input[name="hemis_id"]');
        if (!field) {
            return;
        }

        field.addEventListener('input', function () {
            var cleaned = field.value.replace(/\D+/g, '');
            if (cleaned !== field.value) {
                field.value = cleaned;
            }
        });
    }

    /** Oynani kartochkadagi ma'lumotlar bilan to'ldiradi */
    function fill(card) {
        var data      = card.dataset;
        var available = parseInt(data.available, 10) || 0;
        var copies    = parseInt(data.copies, 10) || 0;

        currentAvailable = available;

        titleEl.textContent    = data.title || '';
        authorEl.textContent   = data.author || '';
        categoryEl.textContent = data.category || '';
        descEl.textContent     = data.description || 'Tavsif kiritilmagan.';
        bookIdEl.value         = data.id || '';
        if (pickupSelect) {
            pickupSelect.value = '1';
        }
        moreLink.href          = card.href;

        // Bo'sh nusxalar belgisi
        availEl.textContent = available > 0
            ? "Bo'sh nusxalar: " + available + ' / ' + copies
            : "Hozir bo'sh nusxa yo'q";
        availEl.className = 'px-2 py-0.5 rounded-full ' +
            (available > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700');

        // Muqova: rasm bo'lsa to'liq ko'rsatamiz, bo'lmasa kategoriya belgisi
        BG_CLASSES.forEach(function (cls) { coverBox.classList.remove(cls); });

        if (data.cover) {
            coverImg.src = data.cover;
            coverImg.alt = data.title || '';
            coverImg.classList.remove('hidden');
            coverEmoji.textContent = '';
            coverBox.classList.add('bg-slate-100');
        } else {
            coverImg.removeAttribute('src');
            coverImg.alt = '';
            coverImg.classList.add('hidden');
            coverEmoji.textContent = data.emoji || '📕';
            coverBox.classList.add(data.bg || 'bg-slate-100');
        }

        showState(pickState(available, data.booked === '1'));
    }

    function openModal(card) {
        lastFocus = document.activeElement;
        fill(card);

        // Har safar ochilganda forma tozalangan holda, admin blokidagi tugma ko'rinadi
        if (offlineForm) {
            offlineForm.reset();
        }
        if (role === 'admin') {
            ensureOfflineAction();   // forma manzilini oldindan tekshiramiz
            resetAdminView();
        }

        modal.inert = false;
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');
        modal.scrollTop = 0;

        closeBtn.focus({ preventScroll: true });
    }

    function closeModal() {
        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        document.body.classList.remove('overflow-hidden');
        modal.inert = true;

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    // Kartochka yoki qatorga bosilganda oynani ochamiz
    document.addEventListener('click', function (event) {
        var card = event.target.closest('a[data-book-card]');
        if (!card) {
            return;
        }

        // Yangi oynada ochish uchun bosilgan bo'lsa, havola o'z holicha ishlasin
        if (event.defaultPrevented || event.button !== 0 ||
            event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        openModal(card);
    });

    closeBtn.addEventListener('click', closeModal);

    // ====== Oflayn berish: yashil tugma ↔ talaba formasi (faqat admin) ======
    if (offlineBtn) {
        offlineBtn.addEventListener('click', openOfflineForm);
    } else if (role === 'admin') {
        console.error('[Kutubxona-ARM] #bookModalOfflineBtn topilmadi – footer.php yangilanmagan.');
    }

    // Modal ichida "✕" va "← Orqaga" tugmalari formani yopib, yana yashil tugmani ko'rsatadi
    modal.addEventListener('click', function (event) {
        var back = event.target.closest('[data-offline-back]');
        if (back && role === 'admin') {
            resetAdminView();
        }
    });

    bindHemisFilter(offlineForm);

    // ============================================================
    //  "Talabaga berish" bosilganda – POST haqiqatan ketadi.
    //  Muammo: serverda admin/process-offline.php hali yo'q bo'lsa,
    //  forma o'z-o'zidan jo'naydi, lekin brauzer 404 sahifasini
    //  ko'rsatadi ("hech narsa bo'lmagandek" tuyuladi).
    //  Yechim: JS fetch() orqali tekshiradi va aniq xabar beradi;
    //  fayl bo'lsa – forma avvalgidek oddiy POST bilan jo'naydi.
    // ============================================================
    function bindOfflineSubmit(form) {
        if (!form || form.dataset.offlineBound === '1') {
            return;
        }
        form.dataset.offlineBound = '1';

        form.addEventListener('submit', function (event) {
            var endpoint = form.getAttribute('action') || offlineEndpoint();

            if (!endpoint) {
                event.preventDefault();
                alert("Offlayn berish manzili sozlanmagan: forma 'admin/process-offline.php' ga yuborilishi kerak.");
                return;
            }

            var bookIdField = form.querySelector('input[name="book_id"]');
            if (!bookIdField || !bookIdField.value) {
                event.preventDefault();
                alert('Kitob ID\'si (book_id) kiritilmadi. Sahifani yangilab, qaytadan urinib ko\'ring.');
                return;
            }

            // Server javobini oldindan bilib turamiz: process-offline.php tayyor
            // bo'lmasa foydalanuvchiga tushunarli ogohlantirish beramiz.
            // (Bu tekshiruv faqat ma'lumot uchun – forma baribir POST bo'ladi.)
            if (window.fetch) {
                window.fetch(endpoint, { method: 'HEAD', credentials: 'same-origin' })
                    .then(function (res) {
                        if (res.status === 404) {
                            console.warn('[Kutubxona-ARM] ' + endpoint + ' topilmadi (404). Bu fayl 2-bosqichda yaratiladi.');
                        }
                    })
                    .catch(function () { /* tarmoq xatosi – e'tibor bermaymiz */ });
            }

            console.info('[Kutubxona-ARM] Forma POST bo\'lmoqda → ' + endpoint +
                         ' (book_id=' + bookIdField.value + ')');
        });
    }

    bindOfflineSubmit(offlineForm);

    // ====== Kitob sahifasidagi (modal tashqarisidagi) offlayn forma ======
    var pageToggle = document.getElementById('pageOfflineToggle');
    var pageForm   = document.getElementById('pageOfflineForm');

    if (pageToggle && pageForm) {
        pageForm.classList.add('hidden');      // JS bor: forma avval yopiq bo'ladi

        pageToggle.addEventListener('click', function () {
            var show = pageForm.classList.toggle('hidden') === false;

            if (show) {
                var pf = pageForm.querySelector('form[data-offline-form]') || pageForm;
                ensurePageOfflineAction(pf);
                pf.scrollIntoView({ block: 'nearest' });

                var firstField = pf.querySelector('input[name="hemis_id"]');
                if (firstField) {
                    firstField.focus({ preventScroll: true });
                }
            }
        });

        pageForm.addEventListener('click', function (event) {
            if (event.target.closest('[data-offline-back]')) {
                pageForm.classList.add('hidden');
                pageToggle.focus({ preventScroll: true });
            }
        });
    }

    /** Kitob sahifasidagi forma action'ini ham tekshiradi */
    function ensurePageOfflineAction(form) {
        if (!form || !form.setAttribute) {
            return;
        }
        var want = form.getAttribute('data-endpoint') || offlineEndpoint();
        if (want && (form.getAttribute('action') || '') !== want) {
            console.info('[Kutubxona-ARM] Sahifa formasi action\'i tuzatildi → ' + want);
            form.setAttribute('action', want);
        }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-offline-form]'), function (form) {
        bindHemisFilter(form);
        bindOfflineSubmit(form);
    });

    // Orqa (qoraytirilgan) fonga bosilsa – yopamiz; oyna ichiga bosilsa – yopilmaydi
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    // Klaviatura: Esc – yopish, Tab – oyna ichida aylantirish
    document.addEventListener('keydown', function (event) {
        if (!isOpen()) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeModal();
            return;
        }

        if (event.key === 'Tab') {
            // Faqat ko'rinib turgan elementlar orasida aylanamiz
            var items = Array.prototype.filter.call(modal.querySelectorAll(FOCUSABLE), function (node) {
                return node.offsetParent !== null;
            });
            if (!items.length) {
                return;
            }

            var first = items[0];
            var last  = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    // "Orqaga" tugmasi bilan qaytilganda ochiq oyna qolib ketmasin
    window.addEventListener('pageshow', function () {
        if (isOpen()) {
            closeModal();
        }
    });
})();
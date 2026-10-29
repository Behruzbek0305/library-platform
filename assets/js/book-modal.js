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
    var bookIdEl   = document.getElementById('bookModalBookId');
    var moreLink   = document.getElementById('bookModalMore');

    var role      = modal.getAttribute('data-role');   // guest | student | admin
    var stateEls  = modal.querySelectorAll('[data-state]');
    var lastFocus = null;                              // yopilganda fokus qaytadigan element

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

    /** Oynani kartochkadagi ma'lumotlar bilan to'ldiradi */
    function fill(card) {
        var data      = card.dataset;
        var available = parseInt(data.available, 10) || 0;
        var copies    = parseInt(data.copies, 10) || 0;

        titleEl.textContent    = data.title || '';
        authorEl.textContent   = data.author || '';
        categoryEl.textContent = data.category || '';
        descEl.textContent     = data.description || 'Tavsif kiritilmagan.';
        bookIdEl.value         = data.id || '';
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
/**
 * ============================================================
 *  Fayl: assets/js/confirm-action.js
 *  Vazifasi: Xavfli tugmalarni (o'chirish, rad etish, bloklash,
 *            "Qaytarildi" va h.k.) tasodifan bosib yuborishdan himoya qilish.
 *
 *  Qanday ulanadi: <form> ga quyidagi atributlar beriladi:
 *    data-confirm-title  – oyna sarlavhasi (SHU ATRIBUT BOR BO'LSA tasdiq so'raladi)
 *    data-confirm-text   – tushuntirish matni (nima bo'lishi aniq yozilsin)
 *    data-confirm-ok     – tasdiqlash tugmasi yozuvi (standart: "Ha, davom etish")
 *    data-confirm-tone   – danger (qizil) | warn (sariq) | ok (yashil)
 *    data-confirm-delay  – tasdiq tugmasi necha ms bosilmasligi (standart 800)
 *    data-once           – forma bir marta yuborilgach tugmalar bloklanadi
 *  Forma ichidagi <select data-confirm-show="Yorliq"> tanlangan qiymati
 *  oynada "Yorliq: qiymat" ko'rinishida chiqadi (masalan, necha kunga beriladi).
 *
 *  Himoya qatlamlari:
 *    1) maxsus tasdiq oynasi (fokus "Bekor qilish" da – Enter xavfsiz)
 *    2) tasdiq tugmasi dastlab bir necha yuz ms bloklangan (double-click himoyasi)
 *    3) Esc / orqa fon / "Bekor qilish" – hech narsa qilmaydi
 *    4) yuborilgach sahifadagi barcha [data-once] formalar bloklanadi
 *
 *  Xavfsizlik: matnlar faqat textContent orqali qo'yiladi (innerHTML yo'q).
 * ============================================================
 */
(function () {
    'use strict';

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

    // Amal turiga qarab rang va belgi
    var TONES = {
        danger: { icon: '⚠️', ring: 'bg-red-100',   ok: 'bg-red-600 hover:bg-red-700' },
        warn:   { icon: '⚠️', ring: 'bg-amber-100', ok: 'bg-amber-500 hover:bg-amber-600' },
        ok:     { icon: '✅', ring: 'bg-green-100', ok: 'bg-green-600 hover:bg-green-700' }
    };

    var overlay = null, iconEl, titleEl, textEl, extraEl, cancelBtn, okBtn;
    var pendingForm = null;     // tasdiq kutayotgan forma
    var lastFocus   = null;     // oyna yopilganda fokus qaytadigan element
    var enableTimer = null;     // tasdiq tugmasini keyinroq yoqish uchun

    /** Element yaratadi (matn faqat textContent orqali) */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function isOpen() {
        return overlay !== null && !overlay.classList.contains('pointer-events-none');
    }

    // ------------------------------------------------------------
    // 1. OYNA QOLIPINI BIR MARTA YASAYMIZ
    // ------------------------------------------------------------
    function build() {
        if (overlay) { return; }

        overlay = el('div',
            'fixed inset-0 z-[80] flex items-center justify-center p-4 bg-black/50 ' +
            'opacity-0 pointer-events-none transition-opacity duration-150');
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'confirmActionTitle');
        overlay.inert = true;

        var box = el('div', 'w-full max-w-sm bg-white rounded-2xl shadow-2xl p-5');

        iconEl  = el('div', 'grid place-items-center w-12 h-12 rounded-full text-2xl mx-auto');
        titleEl = el('h2', 'mt-3 text-lg font-bold text-center break-words');
        titleEl.id = 'confirmActionTitle';
        textEl  = el('p', 'mt-2 text-sm text-slate-600 text-center break-words');
        extraEl = el('p', 'hidden mt-2 text-sm font-semibold text-slate-800 text-center bg-slate-100 rounded-md px-3 py-1.5');

        var row = el('div', 'mt-5 grid grid-cols-2 gap-2');
        cancelBtn = el('button',
            'rounded-lg border border-slate-300 bg-white hover:bg-slate-50 text-slate-800 font-semibold py-2.5', 'Bekor qilish');
        cancelBtn.type = 'button';
        okBtn = el('button',
            'rounded-lg text-white font-semibold py-2.5 disabled:opacity-40 disabled:cursor-not-allowed', '');
        okBtn.type = 'button';

        row.appendChild(cancelBtn);
        row.appendChild(okBtn);
        box.appendChild(iconEl);
        box.appendChild(titleEl);
        box.appendChild(textEl);
        box.appendChild(extraEl);
        box.appendChild(row);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        cancelBtn.addEventListener('click', function () { closeDialog(); });
        okBtn.addEventListener('click', confirmAndSubmit);

        // Orqa fonga bosish – BEKOR qilish (hech qachon tasdiqlamaydi)
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) { closeDialog(); }
        });
    }

    // ------------------------------------------------------------
    // 2. OCHISH / YOPISH
    // ------------------------------------------------------------
    function openDialog(form) {
        build();
        pendingForm = form;
        lastFocus   = document.activeElement;

        var tone  = TONES[form.getAttribute('data-confirm-tone')] || TONES.danger;
        var delay = parseInt(form.getAttribute('data-confirm-delay'), 10);
        if (isNaN(delay) || delay < 0) { delay = 800; }

        iconEl.className    = 'grid place-items-center w-12 h-12 rounded-full text-2xl mx-auto ' + tone.ring;
        iconEl.textContent  = tone.icon;
        titleEl.textContent = form.getAttribute('data-confirm-title') || '';
        textEl.textContent  = form.getAttribute('data-confirm-text') || '';
        okBtn.textContent   = form.getAttribute('data-confirm-ok') || 'Ha, davom etish';
        okBtn.className     = 'rounded-lg text-white font-semibold py-2.5 disabled:opacity-40 disabled:cursor-not-allowed ' + tone.ok;

        // Forma ichidagi tanlangan qiymat (masalan: "Berish muddati: 3 kun")
        var sel = form.querySelector('select[data-confirm-show]');
        if (sel && sel.selectedIndex >= 0) {
            extraEl.textContent = sel.getAttribute('data-confirm-show') + ': ' + sel.options[sel.selectedIndex].text;
            extraEl.classList.remove('hidden');
        } else {
            extraEl.textContent = '';
            extraEl.classList.add('hidden');
        }

        // Tasdiq tugmasi dastlab bloklangan: tez-tez ikki marta bosish tasdiqlab yubormasin
        okBtn.disabled = true;
        clearTimeout(enableTimer);
        enableTimer = setTimeout(function () { okBtn.disabled = false; }, delay);

        overlay.inert = false;
        overlay.classList.remove('opacity-0', 'pointer-events-none');
        overlay.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');

        // Fokus XAVFSIZ tugmada: tasodifan Enter bosilsa ham amal bajarilmaydi
        cancelBtn.focus({ preventScroll: true });
    }

    function closeDialog() {
        if (!overlay) { return; }
        clearTimeout(enableTimer);
        overlay.classList.add('opacity-0', 'pointer-events-none');
        overlay.classList.remove('opacity-100');
        overlay.inert = true;
        document.body.classList.remove('overflow-hidden');
        pendingForm = null;

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    /** "Ha" bosilganda: formani tasdiqlangan deb belgilab, qayta yuboramiz */
    function confirmAndSubmit() {
        if (okBtn.disabled || !pendingForm) { return; }

        var form = pendingForm;
        closeDialog();
        form.setAttribute('data-confirmed', '1');

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();   // submit hodisasi qayta ishlaydi, bu safar o'tkazib yuboriladi
        } else {
            lockAll();
            form.submit();
        }
    }

    // ------------------------------------------------------------
    // 3. IKKI MARTA YUBORISHDAN HIMOYA
    // ------------------------------------------------------------
    function lockAll() {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-once]'), function (f) {
            f.setAttribute('data-locked', '1');

            Array.prototype.forEach.call(f.querySelectorAll('button[type="submit"], button:not([type])'), function (b) {
                if (b.disabled) { return; }
                b.setAttribute('data-orig-text', b.textContent);
                b.disabled = true;
                b.classList.add('opacity-60', 'cursor-not-allowed');
                b.textContent = 'Kuting…';
            });
        });
    }

    function unlockAll() {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-locked]'), function (f) {
            f.removeAttribute('data-locked');
            f.removeAttribute('data-confirmed');

            Array.prototype.forEach.call(f.querySelectorAll('button[data-orig-text]'), function (b) {
                b.textContent = b.getAttribute('data-orig-text');
                b.removeAttribute('data-orig-text');
                b.disabled = false;
                b.classList.remove('opacity-60', 'cursor-not-allowed');
            });
        });
    }

    // ------------------------------------------------------------
    // 4. FORMA YUBORILISHINI USHLAB OLISH (capture bosqichida)
    // ------------------------------------------------------------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.hasAttribute) { return; }

        // a) Tasdiq kerak va hali berilmagan: formani to'xtatib, oyna ochamiz
        if (form.hasAttribute('data-confirm-title') && form.getAttribute('data-confirmed') !== '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (form.getAttribute('data-locked') !== '1') { openDialog(form); }
            return;
        }
        form.removeAttribute('data-confirmed');

        // b) Allaqachon yuborilgan bo'lsa – ikkinchi yuborishni to'xtatamiz
        if (form.hasAttribute('data-once')) {
            if (form.getAttribute('data-locked') === '1') {
                event.preventDefault();
                return;
            }
            // Tugmalarni keyinroq o'chiramiz (submit hodisasi tugagach)
            setTimeout(lockAll, 0);
        }
    }, true);

    // ------------------------------------------------------------
    // 5. KLAVIATURA: Esc – bekor qilish, Tab – oyna ichida aylanadi
    // ------------------------------------------------------------
    document.addEventListener('keydown', function (event) {
        if (!isOpen()) { return; }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog();
            return;
        }

        if (event.key === 'Tab') {
            var items = Array.prototype.filter.call(overlay.querySelectorAll(FOCUSABLE), function (n) {
                return n.offsetParent !== null;
            });
            if (!items.length) { return; }

            var first = items[0], last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault(); first.focus();
            }
        }
    });

    // "Orqaga" tugmasi bilan qaytilganda hamma narsa dastlabki holatiga qaytsin
    window.addEventListener('pageshow', function () {
        if (isOpen()) { closeDialog(); }
        unlockAll();
    });
})();
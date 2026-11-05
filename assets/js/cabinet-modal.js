/**
 * ============================================================
 *  Fayl: assets/js/cabinet-modal.js
 *  Vazifasi: "Mening kabinetim" sahifasida kitob nomi yoki rasmi bosilganda
 *            buyurtma tafsilotlari bilan ixcham modal oyna ochish.
 *
 *  Qanday ulanadi: trigger elementda "data-res-open" atributi va
 *  data-title, data-author, data-cover, data-code, data-reserved,
 *  data-picked, data-returned, data-timer-at, data-timer-label,
 *  data-staff, data-status, data-status-class ... bor (cabinet.php qo'yadi).
 *  Modal qolipi: cabinet.php ichidagi #resModal.
 *
 *  Imkoniyatlar:
 *    - muqova, nom, muallif, holat belgisi
 *    - 🔑 kod va "Nusxalash" tugmasi (nusxalashni hemis.js dagi [data-copy] bajaradi)
 *    - band qilingan / olib ketilgan / qaytarilgan vaqtlar, mas'ul xodim
 *    - jonli taymer (sekundlar bilan), server vaqtiga sinxron
 *    - Esc, orqa fon yoki ✕ yopadi; Tab oyna ichida aylanadi; fokus joyiga qaytadi
 *
 *  Xavfsizlik: hamma matn faqat textContent orqali qo'yiladi (innerHTML yo'q).
 * ============================================================
 */
(function () {
    'use strict';

    var modal = document.getElementById('resModal');
    if (!modal) {
        return;
    }

    var closeBtn    = document.getElementById('resModalClose');
    var coverBox    = document.getElementById('resModalCover');
    var coverImg    = document.getElementById('resModalImg');
    var coverEmoji  = document.getElementById('resModalEmoji');
    var titleEl     = document.getElementById('resModalTitle');
    var authorEl    = document.getElementById('resModalAuthor');
    var statusEl    = document.getElementById('resModalStatus');
    var codeBox     = document.getElementById('resModalCodeBox');
    var codeEl      = document.getElementById('resModalCode');
    var codeCopyBtn = document.getElementById('resModalCodeCopy');
    var reservedEl  = document.getElementById('resModalReserved');
    var pickedEl    = document.getElementById('resModalPicked');
    var returnedEl  = document.getElementById('resModalReturned');
    var staffEl     = document.getElementById('resModalStaff');
    var timerBox    = document.getElementById('resModalTimerBox');
    var timerLabel  = document.getElementById('resModalTimerLabel');
    var timerEl     = document.getElementById('resModalTimer');
    var moreLink    = document.getElementById('resModalMore');

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';
    var BG_CLASSES = ['bg-slate-100', 'bg-blue-100', 'bg-pink-100', 'bg-green-100', 'bg-slate-200'];

    // Server va brauzer soati orasidagi farq (soniyada)
    var serverOffset = (parseInt(modal.getAttribute('data-server-time'), 10) || 0) - Math.floor(Date.now() / 1000);

    var timerId   = null;
    var timerTs   = 0;
    var lastFocus = null;

    // Taymer rang sinflari
    var T_BASE    = 'inline-block px-2 py-0.5 rounded font-mono font-semibold ';
    var T_NORMAL  = T_BASE + 'bg-amber-100 text-amber-800';
    var T_URGENT  = T_BASE + 'bg-orange-100 text-orange-700';
    var T_EXPIRED = T_BASE + 'bg-red-100 text-red-700';

    function two(n) { return n < 10 ? '0' + n : String(n); }

    function isOpen() {
        return !modal.classList.contains('pointer-events-none');
    }

    /** Qiymat bo'sh bo'lsa "—" qo'yadi */
    function setText(node, value) {
        node.textContent = value && value !== '' ? value : '—';
    }

    // ------------------------------------------------------------
    // TAYMER
    // ------------------------------------------------------------
    function stopTimer() {
        if (timerId !== null) {
            clearInterval(timerId);
            timerId = null;
        }
    }

    function tick() {
        var left = timerTs - (Math.floor(Date.now() / 1000) + serverOffset);

        if (left <= 0) {
            timerEl.className   = T_EXPIRED;
            timerEl.textContent = '⚠️ Muddati tugadi';
            stopTimer();
            return;
        }

        var d = Math.floor(left / 86400);
        var h = Math.floor((left % 86400) / 3600);
        var m = Math.floor((left % 3600) / 60);
        var s = left % 60;

        timerEl.textContent = two(d) + ' kun ' + two(h) + ':' + two(m) + ':' + two(s);
        timerEl.className   = left <= 6 * 3600 ? T_URGENT : T_NORMAL;
    }

    function startTimer(isoValue, label) {
        stopTimer();

        var ms = isoValue ? Date.parse(isoValue) : NaN;
        if (isNaN(ms)) {
            timerBox.classList.add('hidden');
            return;
        }

        timerTs = Math.floor(ms / 1000);
        timerLabel.textContent = label ? label + ' ' : '';
        timerBox.classList.remove('hidden');

        tick();
        if (timerEl.textContent.indexOf('Muddati') === -1) {
            timerId = setInterval(tick, 1000);
        }
    }

    // ------------------------------------------------------------
    // OYNANI TO'LDIRISH
    // ------------------------------------------------------------
    function fill(btn) {
        var d = btn.dataset;

        titleEl.textContent  = d.title || '';
        authorEl.textContent = d.author || '';
        moreLink.href        = d.bookUrl || '#';

        // Holat belgisi
        statusEl.textContent = d.status || '';
        statusEl.className   = 'shrink-0 text-xs px-2 py-0.5 rounded-full font-medium ' + (d.statusClass || '');

        // Muqova: rasm bo'lsa to'liq, bo'lmasa kategoriya belgisi
        BG_CLASSES.forEach(function (cls) { coverBox.classList.remove(cls); });
        if (d.cover) {
            coverImg.src = d.cover;
            coverImg.alt = d.title || '';
            coverImg.classList.remove('hidden');
            coverEmoji.textContent = '';
            coverBox.classList.add('bg-slate-100');
        } else {
            coverImg.removeAttribute('src');
            coverImg.alt = '';
            coverImg.classList.add('hidden');
            coverEmoji.textContent = d.emoji || '📕';
            coverBox.classList.add(d.bg || 'bg-slate-100');
        }

        // Kod (offlayn berilgan yoki eski yozuvlarda bo'lmaydi)
        if (d.code) {
            codeEl.textContent = d.codeView || d.code;
            codeCopyBtn.setAttribute('data-copy', d.code);   // nusxalanganda bo'shliqsiz: K7M4P2
            codeBox.classList.remove('hidden');
        } else {
            codeEl.textContent = '';
            codeCopyBtn.setAttribute('data-copy', '');
            codeBox.classList.add('hidden');
        }

        setText(reservedEl, d.reserved);
        setText(pickedEl, d.picked);
        setText(returnedEl, d.returned);
        setText(staffEl, d.staff);

        startTimer(d.timerAt, d.timerLabel);
    }

    function openModal(btn) {
        lastFocus = document.activeElement;
        fill(btn);

        modal.inert = false;
        modal.classList.remove('opacity-0', 'pointer-events-none');
        modal.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');
        modal.scrollTop = 0;

        closeBtn.focus({ preventScroll: true });
    }

    function closeModal() {
        stopTimer();

        modal.classList.add('opacity-0', 'pointer-events-none');
        modal.classList.remove('opacity-100');
        document.body.classList.remove('overflow-hidden');
        modal.inert = true;

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus({ preventScroll: true });
        }
    }

    // ------------------------------------------------------------
    // HODISALAR
    // ------------------------------------------------------------
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-res-open]');
        if (!trigger) {
            return;
        }
        event.preventDefault();
        openModal(trigger);
    });

    closeBtn.addEventListener('click', closeModal);

    // Qoraytirilgan fonga bosilsa yopamiz; oyna ichiga bosilsa yopilmaydi
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    // Esc – yopish, Tab – oyna ichida aylantirish
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
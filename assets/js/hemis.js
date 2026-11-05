/**
 * ============================================================
 *  Fayl: assets/js/hemis.js
 *  Vazifasi: HEMIS ID bilan ishlaydigan kichik imkoniyatlar.
 *
 *  1) NUSXALASH: [data-copy] atributli element (tugma) bosilganda
 *     uning qiymati (masalan 341231102185 yoki kod K7M4P2) clipboard'ga
 *     nusxalanadi va pastda qisqa "Nusxalandi" xabari chiqadi.
 *
 *  2) FAQAT RAQAM: [data-digits-only] input'larda harf, bo'shliq va boshqa
 *     belgilar avtomatik o'chiriladi (nusxalab qo'yilganda ham).
 *     MUHIM: qiymat HECH QACHON qirqilmaydi (slice yo'q) – 12 tadan ko'p
 *     raqam kiritilsa, yuborishda xatolik chiqadi.
 *
 *  3) VALIDATSIYA: [data-hemis-length] atributli input'ning qiymati forma
 *     yuborilishida aniq shu uzunlikdagi raqamlardan iborat bo'lishi shart
 *     (/^\d{12}$/). Aks holda forma yuborilmaydi va xato matni chiqadi:
 *     "HEMIS ID ro'ppa-rosa 12 ta raqamdan iborat bo'lishi shart!"
 *     (Server tomonida ham xuddi shu tekshiruv bor: includes/functions.php)
 *
 *  Xavfsizlik: xabar matni faqat textContent orqali qo'yiladi (innerHTML yo'q).
 * ============================================================
 */
(function () {
    'use strict';

    // ------------------------------------------------------------
    // 1. "NUSXALANDI" XABARI
    // ------------------------------------------------------------
    var toast      = null;
    var toastTimer = null;

    function showToast(text, isError) {
        if (!toast) {
            toast = document.createElement('div');
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            document.body.appendChild(toast);
        }

        toast.className =
            'fixed bottom-6 left-1/2 -translate-x-1/2 z-[70] px-4 py-2 rounded-md shadow-lg text-sm ' +
            'text-white transition-opacity duration-200 ' + (isError ? 'bg-red-600' : 'bg-slate-800');
        toast.textContent = text;
        toast.style.opacity = '1';

        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () {
            toast.style.opacity = '0';
        }, 1500);
    }

    // ------------------------------------------------------------
    // 2. MATNNI NUSXALASH
    //    navigator.clipboard (HTTPS/localhost) yoki zaxira: execCommand('copy')
    // ------------------------------------------------------------
    function fallbackCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        document.body.appendChild(area);
        area.select();

        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }

        document.body.removeChild(area);
        return ok;
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(
                function () { return true; },
                function () { return fallbackCopy(text); }
            );
        }
        return Promise.resolve(fallbackCopy(text));
    }

    // ------------------------------------------------------------
    // 3. BOSILGANDA NUSXALASH (hodisa delegatsiyasi)
    // ------------------------------------------------------------
    var HIGHLIGHT = ['bg-green-100', 'text-green-700'];

    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-copy]');
        if (!target) {
            return;
        }

        var value = target.getAttribute('data-copy') || '';
        if (value === '') {
            return;
        }

        event.preventDefault();

        copyText(value).then(function (ok) {
            if (!ok) {
                showToast('Nusxalab bo\'lmadi. Matnni qo\'lda belgilab oling.', true);
                return;
            }

            showToast('Nusxalandi: ' + value, false);

            HIGHLIGHT.forEach(function (cls) { target.classList.add(cls); });
            setTimeout(function () {
                HIGHLIGHT.forEach(function (cls) { target.classList.remove(cls); });
            }, 1000);
        });
    });

    // ------------------------------------------------------------
    // 4. FAQAT RAQAM (qirqish YO'Q)
    //    "341 231 102 185" → "341231102185". 12 tadan ko'p raqam kiritilsa,
    //    ortiqcha raqamlar o'chib ketmaydi – yuborishda xato ko'rsatiladi.
    // ------------------------------------------------------------
    document.addEventListener('paste', function (event) {
        var field = event.target;
        if (!field || !field.matches || !field.matches('input[data-digits-only]')) {
            return;
        }

        var clip = event.clipboardData || window.clipboardData;
        if (!clip) {
            return;
        }

        event.preventDefault();

        var digits = String(clip.getData('text') || '').replace(/\D+/g, '');
        field.setRangeText(digits, field.selectionStart, field.selectionEnd, 'end');

        // Boshqa skriptlar ham o'zgarishdan xabardor bo'lsin
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });

    document.addEventListener('input', function (event) {
        var field = event.target;
        if (!field || !field.matches || !field.matches('input[data-digits-only]')) {
            return;
        }

        var cleaned = field.value.replace(/\D+/g, '');
        if (cleaned !== field.value) {
            field.value = cleaned;
        }
    });

    // ------------------------------------------------------------
    // 5. HEMIS ID VALIDATSIYASI (forma yuborilganda)
    // ------------------------------------------------------------
    function hemisMessage(length) {
        return "HEMIS ID ro'ppa-rosa " + length + " ta raqamdan iborat bo'lishi shart!";
    }

    /** Input ostidagi xato yozuvini ko'rsatadi (kerak bo'lsa yaratadi) */
    function showFieldError(field, message) {
        if (!field.hemisErrorEl) {
            var box = document.createElement('p');
            box.setAttribute('role', 'alert');
            box.className = 'text-xs text-red-600 font-semibold mt-1';
            field.insertAdjacentElement('afterend', box);
            field.hemisErrorEl = box;
        }
        field.hemisErrorEl.textContent = message;
        field.setAttribute('aria-invalid', 'true');
        field.classList.add('border-red-500');
    }

    function clearFieldError(field) {
        if (field.hemisErrorEl) {
            field.hemisErrorEl.remove();
            field.hemisErrorEl = null;
        }
        field.removeAttribute('aria-invalid');
        field.classList.remove('border-red-500');
    }

    // Yozish boshlanganda xato yozuvi o'chadi
    document.addEventListener('input', function (event) {
        var field = event.target;
        if (field && field.matches && field.matches('input[data-hemis-length]')) {
            clearFieldError(field);
        }
    });

    // Capture bosqichida: boshqa ishlovchilardan oldin tekshiramiz
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.querySelectorAll) {
            return;
        }

        var fields = form.querySelectorAll('input[data-hemis-length]');

        for (var i = 0; i < fields.length; i++) {
            var field  = fields[i];
            var length = parseInt(field.getAttribute('data-hemis-length'), 10) || 12;
            var value  = field.value.replace(/\s+/g, '');
            var regex  = new RegExp('^\\d{' + length + '}$');

            if (!regex.test(value)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                showFieldError(field, hemisMessage(length));
                field.focus();
                return;
            }
        }
    }, true);
})();
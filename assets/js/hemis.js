/**
 * ============================================================
 *  Fayl: assets/js/hemis.js
 *  Vazifasi: HEMIS ID bilan ishlaydigan ikkita kichik imkoniyat.
 *
 *  1) NUSXALASH: [data-copy] atributli element (tugma) bosilganda
 *     uning qiymati (masalan 341231102185) clipboard'ga nusxalanadi va
 *     pastda qisqa "Nusxalandi" xabari chiqadi.
 *     Ishlatiladi: admin paneldagi jadval va kartochkalar, "Mening kabinetim".
 *     (includes/book_helpers.php dagi renderHemisCopy() shu atributni qo'yadi.)
 *
 *  2) FAQAT RAQAM: [data-digits-only] atributli input'larda harf, bo'shliq
 *     va boshqa belgilar avtomatik o'chiriladi (nusxalab qo'yilganda ham).
 *
 *  Xavfsizlik: xabar matni faqat textContent orqali qo'yiladi (innerHTML yo'q).
 * ============================================================
 */
(function () {
    'use strict';

    // ------------------------------------------------------------
    // 1. "NUSXALANDI" XABARI (bitta element, kerak bo'lganda yaratiladi)
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

        // Rang: muvaffaqiyat – qora fon, xato – qizil fon
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
    //    Zamonaviy usul: navigator.clipboard (faqat HTTPS yoki localhost'da ishlaydi).
    //    Zaxira usul: yashirin textarea + execCommand('copy') (oddiy http serverlar uchun).
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
    // 3. BOSILGANDA NUSXALASH (hodisa delegatsiyasi: keyin qo'shilgan elementlar uchun ham ishlaydi)
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
                showToast('Nusxalab bo\'lmadi. Raqamni qo\'lda belgilab oling.', true);
                return;
            }

            showToast('Nusxalandi: ' + value, false);

            // Tugma bir lahza yashil bo'lib turadi
            HIGHLIGHT.forEach(function (cls) { target.classList.add(cls); });
            setTimeout(function () {
                HIGHLIGHT.forEach(function (cls) { target.classList.remove(cls); });
            }, 1000);
        });
    });

    // ------------------------------------------------------------
    // 4. FAQAT RAQAM: [data-digits-only] input'larida boshqa belgilarni o'chiramiz
    //    (nusxalab qo'yilgan "341 231 102 185" ham "341231102185" bo'ladi)
    // ------------------------------------------------------------
    // Qo'yishda (paste): brauzer maxlength bo'yicha matnni bo'shliqlar bilan birga
    // kesib yuboradi ("341 231 102 185" → "341 231 102 " va raqamlar yo'qoladi).
    // Shuning uchun avval bo'shliqlarni tozalaymiz, keyin o'zimiz joylaymiz.
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
        var start  = field.selectionStart;
        var end    = field.selectionEnd;
        field.setRangeText(digits, start, end, 'end');

        var max = parseInt(field.getAttribute('maxlength'), 10);
        if (!isNaN(max) && field.value.length > max) {
            field.value = field.value.slice(0, max);
        }

        // Boshqa skriptlar (masalan book-modal.js) ham o'zgarishdan xabardor bo'lsin
        field.dispatchEvent(new Event('input', { bubbles: true }));
    });

    document.addEventListener('input', function (event) {
        var field = event.target;
        if (!field || !field.matches || !field.matches('input[data-digits-only]')) {
            return;
        }

        var cleaned = field.value.replace(/\D+/g, '');

        // maxlength nusxalab qo'yishda ham ishlaydi, lekin ehtiyot uchun qirqamiz
        var max = parseInt(field.getAttribute('maxlength'), 10);
        if (!isNaN(max) && cleaned.length > max) {
            cleaned = cleaned.slice(0, max);
        }

        if (cleaned !== field.value) {
            field.value = cleaned;
        }
    });
})();
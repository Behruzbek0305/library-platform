/**
 * ============================================================
 *  Fayl: assets/js/dropdown.js
 *  Vazifasi: Filtr dropdown'larini (soha, kategoriya, janr) boshqarish.
 *
 *  Qanday ulanadi: includes/book_helpers.php dagi renderFilterDropdown()
 *  [data-dropdown] blokini chizadi. Ichida:
 *      [data-dropdown-input]   – yashirin input (tanlangan qiymat)
 *      [data-dropdown-toggle]  – tugma (aria-expanded chevronni buradi)
 *      [data-dropdown-panel]   – ochiladigan ro'yxat
 *      [data-value]            – ro'yxatdagi variantlar
 *
 *  Imkoniyatlar:
 *    - tugma bosilganda ro'yxat ochiladi, chevron tepaga buriladi;
 *      qayta bosilsa yopiladi, chevron pastga qaytadi
 *    - bir vaqtda faqat bitta ro'yxat ochiq turadi
 *    - variant tanlansa, qiymat inputga yoziladi va forma avtomatik yuboriladi
 *    - tashqariga bosish yoki Esc ro'yxatni yopadi (Esc fokusni tugmaga qaytaradi)
 *    - ↑ ↓ bilan variantlar orasida yurish, Enter bilan tanlash
 *
 *  Xavfsizlik: matnlar serverdan keladi va PHP tomonda e() orqali o'tgan;
 *  bu yerda innerHTML ishlatilmaydi.
 * ============================================================
 */
(function () {
    'use strict';

    var roots = document.querySelectorAll('[data-dropdown]');
    if (!roots.length) {
        return;
    }

    /** Bitta dropdown'ni ochadi yoki yopadi */
    function setOpen(root, open) {
        var toggle = root.querySelector('[data-dropdown-toggle]');
        var panel  = root.querySelector('[data-dropdown-panel]');

        panel.classList.toggle('hidden', !open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function isOpen(root) {
        return root.querySelector('[data-dropdown-toggle]').getAttribute('aria-expanded') === 'true';
    }

    /** Hammasini yopadi ($except – shu dropdown'dan tashqari) */
    function closeAll(except) {
        Array.prototype.forEach.call(roots, function (root) {
            if (root !== except) {
                setOpen(root, false);
            }
        });
    }

    /** Ochiq ro'yxatdagi variantlar */
    function optionsOf(root) {
        return Array.prototype.slice.call(root.querySelectorAll('[data-value]'));
    }

    /** Variantni tanlaydi: qiymatni yozadi va formani yuboradi */
    function choose(root, option) {
        var input = root.querySelector('[data-dropdown-input]');
        input.value = option.getAttribute('data-value') || '';

        setOpen(root, false);

        if (input.form) {
            input.form.submit();
        }
    }

    Array.prototype.forEach.call(roots, function (root) {
        var toggle = root.querySelector('[data-dropdown-toggle]');
        var panel  = root.querySelector('[data-dropdown-panel]');
        if (!toggle || !panel) {
            return;
        }

        // Tugma bosilganda: ochish / yopish
        toggle.addEventListener('click', function () {
            var willOpen = !isOpen(root);
            closeAll(root);
            setOpen(root, willOpen);
        });

        // Variant bosilganda
        panel.addEventListener('click', function (event) {
            var option = event.target.closest('[data-value]');
            if (option) {
                choose(root, option);
            }
        });

        // Klaviatura: ↑ ↓ – yurish, Esc – yopish
        root.addEventListener('keydown', function (event) {
            var key = event.key;

            if (key === 'Escape' && isOpen(root)) {
                event.preventDefault();
                setOpen(root, false);
                toggle.focus({ preventScroll: true });
                return;
            }

            if (key !== 'ArrowDown' && key !== 'ArrowUp') {
                return;
            }
            event.preventDefault();

            // Yopiq bo'lsa avval ochamiz
            if (!isOpen(root)) {
                closeAll(root);
                setOpen(root, true);
            }

            var items = optionsOf(root);
            var index = items.indexOf(document.activeElement);

            if (index === -1) {
                // Birinchi marta: tanlangan variantga (yo'q bo'lsa birinchisiga) o'tamiz
                var selected = panel.querySelector('[aria-selected="true"]');
                index = items.indexOf(selected);
                if (index === -1) { index = 0; }
            } else {
                index += (key === 'ArrowDown' ? 1 : -1);
                if (index < 0) { index = items.length - 1; }
                if (index >= items.length) { index = 0; }
            }

            items[index].focus({ preventScroll: false });
        });
    });

    // Tashqariga bosilsa hammasi yopiladi
    document.addEventListener('pointerdown', function (event) {
        if (!event.target.closest('[data-dropdown]')) {
            closeAll(null);
        }
    });

    // "Orqaga" tugmasi bilan qaytilganda ochiq ro'yxat qolib ketmasin
    window.addEventListener('pageshow', function () {
        closeAll(null);
    });
})();
/**
 * ============================================================
 *  Fayl: assets/js/menu.js
 *  Vazifasi: Telefon va planshetda o'ng tomondan ochiladigan yon panel (drawer).
 *
 *  Imkoniyatlar:
 *    - ☰ tugmasi bosilganda panel o'ngdan suriladi, orqa fon qoraroq bo'ladi
 *    - ochilganda fokus panelning ichiga o'tadi (yopish tugmasiga)
 *    - Tab tugmasi panel ichida aylanib yuradi (orqa sahifaga chiqib ketmaydi)
 *    - Esc, orqa fonga bosish yoki ✕ tugmasi paneli yopadi
 *    - yopilganda fokus ☰ tugmasiga qaytadi
 *    - panel ochiq paytda orqadagi sahifa aylanmaydi
 *    - ekran kengayib, kompyuter menyusi chiqsa, panel o'zi yopiladi
 * ============================================================
 */
(function () {
    'use strict';

    var toggle   = document.getElementById('menuToggle');
    var drawer   = document.getElementById('drawer');
    var backdrop = document.getElementById('drawerBackdrop');
    var closeBtn = document.getElementById('drawerClose');

    if (!toggle || !drawer || !backdrop || !closeBtn) {
        return;
    }

    // Tailwind "lg" chegarasi (kompyuter menyusi shu kenglikdan boshlanadi)
    var desktopQuery = window.matchMedia('(min-width: 1024px)');

    // Fokus olishi mumkin bo'lgan elementlar
    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

    function isOpen() {
        return toggle.getAttribute('aria-expanded') === 'true';
    }

    function openDrawer() {
        drawer.inert = false;
        drawer.classList.remove('translate-x-full');
        backdrop.classList.remove('opacity-0', 'pointer-events-none');
        backdrop.classList.add('opacity-100');
        document.body.classList.add('overflow-hidden');

        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Menyuni yopish');

        // Fokusni panelning ichiga o'tkazamiz
        closeBtn.focus({ preventScroll: true });
    }

    function closeDrawer(returnFocus) {
        drawer.classList.add('translate-x-full');
        backdrop.classList.remove('opacity-100');
        backdrop.classList.add('opacity-0', 'pointer-events-none');
        document.body.classList.remove('overflow-hidden');
        drawer.inert = true;

        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Menyuni ochish');

        if (returnFocus) {
            toggle.focus({ preventScroll: true });
        }
    }

    toggle.addEventListener('click', function () {
        if (isOpen()) {
            closeDrawer(true);
        } else {
            openDrawer();
        }
    });

    closeBtn.addEventListener('click', function () {
        closeDrawer(true);
    });

    backdrop.addEventListener('click', function () {
        closeDrawer(true);
    });

    // Klaviatura: Esc – yopish, Tab – panel ichida aylantirish
    document.addEventListener('keydown', function (event) {
        if (!isOpen()) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            closeDrawer(true);
            return;
        }

        if (event.key === 'Tab') {
            var items = drawer.querySelectorAll(FOCUSABLE);
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

    // Ekran kengayib kompyuter menyusi chiqsa, panelni yopamiz
    function onViewportChange(event) {
        if (event.matches && isOpen()) {
            closeDrawer(false);
        }
    }

    if (desktopQuery.addEventListener) {
        desktopQuery.addEventListener('change', onViewportChange);
    } else if (desktopQuery.addListener) {
        desktopQuery.addListener(onViewportChange);   // eski brauzerlar uchun
    }
})();
/**
 * ============================================================
 *  Fayl: assets/js/live-search.js
 *  Vazifasi: Qidiruv maydonida yozish paytida kitob tavsiyalarini ko'rsatish.
 *
 *  Qanday ulanadi: input'ga quyidagi atributlar beriladi:
 *      data-live-search
 *      data-endpoint="/library-platform/search.php"
 *      data-catalog="/library-platform/catalog.php"
 *  va input'ning ota-elementi "relative" bo'lishi kerak.
 *  Oyna kengroq bo'lishi kerak bo'lsa, "relative" qilingan forma (yoki blok)ga
 *  data-live-search-root atributini bering: oyna o'sha elementning kengligicha ochiladi.
 *
 *  Ishlashi:
 *    - yozishni to'xtatgach 200 ms kutib, search.php dan eng mos 6 ta kitob olinadi
 *    - tavsiyalar ro'yxat ko'rinishida chiqadi, orqa fon qoraroq bo'ladi
 *    - ↑ ↓ bilan tanlanadi, Enter tanlangan kitobni ochadi
 *    - hech narsa tanlanmagan bo'lsa Enter formani yuboradi: barcha natijalar
 *      katalogda ro'yxat ko'rinishida chiqadi
 *    - Esc yoki tashqariga bosish ro'yxatni yopadi
 *
 *  Xavfsizlik: serverdan kelgan matn faqat textContent orqali qo'yiladi
 *  (innerHTML ishlatilmaydi), shuning uchun XSS bo'lishi mumkin emas.
 * ============================================================
 */
(function () {
    'use strict';

    var inputs = document.querySelectorAll('input[data-live-search]');
    if (!inputs.length || !window.fetch || !window.AbortController) {
        return;
    }

    var DEBOUNCE_MS = 200;

    // Tavsiyalar ochiq paytda sahifani qoraytiradigan orqa fon
    var backdrop = document.createElement('div');
    backdrop.className = 'fixed inset-0 z-40 bg-black/40 hidden';
    backdrop.setAttribute('aria-hidden', 'true');
    document.body.appendChild(backdrop);

    /** Yordamchi: element yaratadi */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    /** Topilgan so'zni sariq rang bilan ajratib ko'rsatadi (xavfsiz usulda) */
    function highlight(parent, text, query) {
        var index = query ? text.toLowerCase().indexOf(query.toLowerCase()) : -1;

        if (index < 0) {
            parent.textContent = text;
            return;
        }

        parent.appendChild(document.createTextNode(text.slice(0, index)));
        parent.appendChild(el('mark', 'bg-yellow-200 text-inherit rounded-sm', text.slice(index, index + query.length)));
        parent.appendChild(document.createTextNode(text.slice(index + query.length)));
    }

    function setup(input, number) {
        // Tavsiyalar oynasi shu elementga nisbatan joylashadi: data-live-search-root bo'lsa
        // o'sha (masalan butun forma – tor ekranda oyna kengroq chiqadi), bo'lmasa input'ning ota-elementi
        var wrapper    = input.closest('[data-live-search-root]') || input.parentElement;
        var endpoint   = input.getAttribute('data-endpoint');
        var catalogUrl = input.getAttribute('data-catalog');
        var listId     = 'live-search-list-' + number;

        var options     = [];     // ro'yxatdagi havolalar (klaviatura bilan yurish uchun)
        var activeIndex = -1;
        var lastQuery   = '';
        var timer       = null;
        var controller  = null;

        // Tavsiyalar ro'yxati
        var list = el('div',
            'absolute left-0 right-0 top-full mt-1 z-50 hidden bg-white text-slate-800 text-left ' +
            'rounded-lg shadow-xl border border-slate-200 overflow-hidden');
        list.id = listId;
        list.setAttribute('role', 'listbox');
        wrapper.appendChild(list);

        // Maxsus imkoniyatlar uchun atributlar
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);

        function isOpen() {
            return !list.classList.contains('hidden');
        }

        function setActive(index) {
            activeIndex = index;

            options.forEach(function (option, i) {
                var selected = (i === index);
                option.classList.toggle('bg-blue-50', selected);
                option.setAttribute('aria-selected', selected ? 'true' : 'false');
            });

            if (index >= 0 && options[index]) {
                input.setAttribute('aria-activedescendant', options[index].id);
                options[index].scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function openList() {
            list.classList.remove('hidden');
            backdrop.classList.remove('hidden');
            wrapper.classList.add('z-50');   // qidiruv maydoni qoraytirilgan fonning ustida qoladi
            input.setAttribute('aria-expanded', 'true');
        }

        function closeList() {
            list.classList.add('hidden');
            backdrop.classList.add('hidden');
            wrapper.classList.remove('z-50');
            input.setAttribute('aria-expanded', 'false');
            setActive(-1);
        }

        /** Server javobidan ro'yxatni chizadi */
        function render(items, query) {
            list.textContent = '';
            options = [];
            lastQuery = query;

            if (!items.length) {
                list.appendChild(el('div', 'px-4 py-3 text-sm text-slate-500', 'Hech narsa topilmadi'));
                activeIndex = -1;
                return;
            }

            items.forEach(function (book, i) {
                var link = el('a', 'flex items-center gap-3 px-3 py-2 hover:bg-slate-50');
                link.href = book.url;
                link.id = listId + '-' + i;
                link.setAttribute('role', 'option');
                link.setAttribute('aria-selected', 'false');

                // Muqova yoki kategoriya belgisi
                var thumb = el('div', 'w-10 h-10 shrink-0 rounded-md overflow-hidden flex items-center justify-center text-xl bg-slate-100');
                if (book.cover) {
                    var img = el('img', 'w-full h-full object-cover');
                    img.src = book.cover;
                    img.alt = '';
                    thumb.appendChild(img);
                } else {
                    thumb.textContent = book.emoji;
                }

                // Nomi va muallifi
                var text   = el('div', 'min-w-0 flex-1');
                var title  = el('div', 'text-sm font-semibold truncate');
                var author = el('div', 'text-xs text-slate-500 truncate');
                highlight(title, book.title, query);
                highlight(author, book.author, query);
                text.appendChild(title);
                text.appendChild(author);

                // Bor yoki yo'q
                var badge = book.available > 0
                    ? el('span', 'shrink-0 text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700', 'Bor')
                    : el('span', 'shrink-0 text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700', "Yo'q");

                link.appendChild(thumb);
                link.appendChild(text);
                link.appendChild(badge);
                list.appendChild(link);
                options.push(link);
            });

            // Pastda: barcha natijalarni katalogda ko'rish
            if (catalogUrl) {
                var all = el('a', 'block px-4 py-2.5 text-sm font-semibold text-blue-600 bg-slate-50 border-t border-slate-200 hover:bg-slate-100',
                    "Barcha natijalarni ko'rish →");
                all.href = catalogUrl + '?q=' + encodeURIComponent(query);
                all.id = listId + '-all';
                all.setAttribute('role', 'option');
                all.setAttribute('aria-selected', 'false');
                list.appendChild(all);
                options.push(all);
            }

            activeIndex = -1;
        }

        /** Serverdan tavsiyalarni so'raydi */
        function search() {
            var query = input.value.trim();

            if (query === '') {
                if (controller) { controller.abort(); }
                closeList();
                return;
            }

            // Oldingi tugamagan so'rovni bekor qilamiz (eski javob yangisini bosib ketmasligi uchun)
            if (controller) { controller.abort(); }
            controller = new AbortController();

            fetch(endpoint + '?q=' + encodeURIComponent(query), {
                signal: controller.signal,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }
                    return response.json();
                })
                .then(function (items) {
                    // Foydalanuvchi shu orada yozishda davom etgan bo'lsa, eski javobni tashlab yuboramiz
                    if (input.value.trim() !== query) { return; }
                    render(items, query);
                    openList();
                })
                .catch(function (error) {
                    if (error.name !== 'AbortError') { closeList(); }
                });
        }

        // Yozilganda: biroz kutib, keyin qidiramiz
        input.addEventListener('input', function () {
            clearTimeout(timer);
            if (input.value.trim() === '') {
                search();
            } else {
                timer = setTimeout(search, DEBOUNCE_MS);
            }
        });

        // Maydonga qaytilganda oldingi tavsiyalar yana ko'rinadi
        input.addEventListener('focus', function () {
            if (list.childElementCount && input.value.trim() !== '' && input.value.trim() === lastQuery) {
                openList();
            }
        });

        // Klaviatura bilan boshqarish
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (!isOpen()) {
                    if (list.childElementCount && input.value.trim() === lastQuery && lastQuery !== '') {
                        event.preventDefault();
                        openList();
                    }
                    return;
                }

                event.preventDefault();
                if (!options.length) { return; }

                var step = event.key === 'ArrowDown' ? 1 : -1;
                var next = activeIndex + step;
                if (next < 0) { next = options.length - 1; }
                if (next >= options.length) { next = 0; }
                setActive(next);

            } else if (event.key === 'Enter') {
                // Biror variant tanlangan bo'lsa – o'sha sahifaga o'tamiz.
                // Tanlanmagan bo'lsa – forma yuboriladi va katalogda hamma natija chiqadi.
                if (isOpen() && activeIndex >= 0 && options[activeIndex]) {
                    event.preventDefault();
                    window.location.href = options[activeIndex].href;
                }

            } else if (event.key === 'Escape') {
                if (isOpen()) {
                    event.preventDefault();
                    closeList();
                }

            } else if (event.key === 'Tab') {
                closeList();
            }
        });

        // Tashqariga yoki qoraytirilgan fonga bosilsa – yopamiz
        document.addEventListener('pointerdown', function (event) {
            if (isOpen() && !wrapper.contains(event.target)) {
                closeList();
            }
        });

        // Forma yuborilganda ro'yxatni yopamiz
        if (input.form) {
            input.form.addEventListener('submit', closeList);
        }

        // "Orqaga" tugmasi bilan qaytilganda ochiq ro'yxat qolib ketmasin
        window.addEventListener('pageshow', closeList);
    }

    Array.prototype.forEach.call(inputs, setup);
})();
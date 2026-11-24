<?php
/**
 * ============================================================
 *  Fayl: read.php – Onlayn o'qish sahifasi (jonli varaqlash)
 *  Manzil: read.php?id=5
 *
 *  Texnologiya:
 *    - PDF.js    – PDF betlarini canvas'ga rasm qilib chizadi
 *    - StPageFlip – varaqni burchakdan bukib o'giradi (sichqoncha va barmoq bilan)
 *    Kompyuterda 2 betlik, tor ekranda (telefon) 1 betlik rejim avtomatik tanlanadi.
 *
 *  Katta PDF (300+ bet) uchun optimizatsiya:
 *    - hamma bet birdaniga chizilmaydi: faqat joriy bet atrofidagi betlar (orqada 2, oldinda 3)
 *    - uzoqlashgan betlarning canvas'i bo'shatiladi (xotira to'lib, telefon qotib qolmasin)
 *    - tiniqlik: canvas o'lchami devicePixelRatio bilan (ko'pi bilan 1600 px kenglikda)
 *    - PDF butunlay yuklanmaydi (disableAutoFetch): kerakli bo'laklar Range so'rovi bilan
 *      read_pdf.php dan olinadi
 *    - thumbnail paneli: faqat ekranda ko'rinib turgan kichik rasmlar chiziladi
 *      (IntersectionObserver), ko'rinishdan chiqqanlari bo'shatiladi
 *    - chizish ishlari bitta navbatda bajariladi: joriy betlar thumbnail'dan ustun
 *
 *  Himoya: yuklab olish va chop etish tugmalari yo'q, Ctrl+S / Ctrl+P va o'ng tugma to'silgan,
 *  chop etishda sahifa bo'sh chiqadi, PDF faqat read_pdf.php orqali (kirish tekshiruvi bilan) beriladi.
 *  Eslatma: brauzerning tarmoq panelidan faylni olish texnik jihatdan mumkin; bu choralar
 *  oddiy foydalanuvchi uchun yetarli.
 *
 *  Kimlar uchun: tizimga kirgan talaba va kutubxonachi (admin, superadmin).
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/book_helpers.php';

// Faqat tizimga kirganlar (talaba yoki kutubxonachi)
requireLogin();
$user = currentUser();

// ------------------------------------------------------------
// 1. KITOB VA MUNDARIJANI OLISH
// ------------------------------------------------------------
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$book = null;
$toc  = [];

if ($id !== false && $id !== null && $id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id, title, author, pdf_file FROM books WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $book = $stmt->fetch() ?: null;

        if ($book !== null) {
            $stmt = $pdo->prepare(
                'SELECT title, page_no, level FROM book_toc
                 WHERE book_id = :id ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute([':id' => $id]);
            foreach ($stmt->fetchAll() as $t) {
                $toc[] = [
                    'title' => (string) $t['title'],
                    'page'  => (int) $t['page_no'],
                    'level' => (int) $t['level'],
                ];
            }
        }
    } catch (PDOException $ex) {
        error_log('[Kutubxona-ARM] O\'qish sahifasida xatolik: ' . $ex->getMessage());
        $book = null;
    }
}

if ($book === null) {
    setFlash('error', 'Bunday kitob topilmadi.');
    redirect('catalog.php');
}

// Onlayn nusxa (PDF) yo'q bo'lsa, kitob sahifasiga qaytaramiz
if (empty($book['pdf_file'])) {
    setFlash('info', 'Bu kitobning onlayn nusxasi hali yuklanmagan.');
    redirect('book.php?id=' . (int) $book['id']);
}

// JavaScript'ga beriladigan sozlamalar (JSON_HEX_* – </script> orqali buzib bo'lmasligi uchun)
$readerConfig = [
    'pdfUrl' => url('read_pdf.php?id=' . (int) $book['id']),
    'bookId' => (int) $book['id'],
    'userId' => (int) $user['id'],
    'toc'    => $toc,
];
$configJson = json_encode(
    $readerConfig,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

// ------------------------------------------------------------
// 2. SAHIFANI CHIZISH
// ------------------------------------------------------------
$pageTitle = 'O\'qish: ' . $book['title'];
require_once __DIR__ . '/includes/header.php';

// Tugmalar uchun umumiy Tailwind sinflari
$btn = 'h-9 min-w-[2.25rem] px-2 rounded-md border border-slate-300 bg-white hover:bg-slate-50 text-sm font-semibold text-slate-700 disabled:opacity-40 disabled:cursor-not-allowed';
?>

<style>
    /* Chop etishda sahifa bo'sh chiqadi */
    @media print {
        body * { display: none !important; }
        body::before { content: "Bu kitobni chop etish taqiqlangan."; display: block; padding: 2rem; font-size: 1.25rem; }
    }
    /* Matn belgilash va rasmni sudrab olish o'chirilgan */
    #stage { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
    /* To'liq ekran rejimida o'quvchi butun ekranni egallaydi */
    #reader:fullscreen { height: 100vh !important; border-radius: 0; border: 0; }
    /* Bet canvas'i: bo'sh paytda ostidagi bet raqami ko'rinib turadi */
    .rd-canvas { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: transparent; }
</style>

<div class="flex flex-wrap items-center justify-between gap-2">
    <a href="<?= e(url('book.php?id=' . (int) $book['id'])) ?>" class="text-blue-600 hover:underline text-sm">← Kitob sahifasiga qaytish</a>
    <p class="text-xs text-slate-500">Onlayn nusxa faqat tanishish uchun. Kitobni kutubxonadan olib keting.</p>
</div>

<div id="reader" class="mt-2 bg-white border border-slate-200 rounded-xl overflow-hidden flex flex-col"
     style="height: calc(100vh - 150px); min-height: 520px;">

    <!-- ====== YUQORI PANEL: panel tugmasi, kitob nomi, bet, to'liq ekran ====== -->
    <div class="border-b border-slate-200 bg-slate-50 px-3 py-2 flex flex-wrap items-center gap-2">
        <button type="button" id="panelToggle" class="<?= $btn ?>" aria-controls="sidePanel" aria-expanded="false">☰ Betlar</button>

        <div class="min-w-0 flex-1 basis-40">
            <div class="font-bold text-sm truncate" title="<?= e($book['title']) ?>"><?= e($book['title']) ?></div>
            <div class="text-xs text-slate-500 truncate"><?= e($book['author']) ?></div>
        </div>

        <div class="flex items-center gap-1">
            <button type="button" id="prevPage" class="<?= $btn ?>" aria-label="Oldingi bet" disabled>‹</button>
            <input type="number" id="pageInput" min="1" value="1" inputmode="numeric" aria-label="Bet raqami"
                   class="h-9 w-16 text-center rounded-md border border-slate-300 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-blue-300">
            <span class="text-sm text-slate-600 whitespace-nowrap">/ <span id="pageTotal">–</span></span>
            <button type="button" id="nextPage" class="<?= $btn ?>" aria-label="Keyingi bet" disabled>›</button>
        </div>

        <button type="button" id="fsToggle" class="<?= $btn ?> hidden" aria-label="To'liq ekran" title="To'liq ekran">⛶</button>
    </div>

    <div class="flex flex-1 min-h-0 relative">

        <!-- ====== YON PANEL: mundarija va betlar (thumbnail) ======
             Kompyuterda chapda turadi, telefonda kitob ustidan ochiladi. -->
        <aside id="sidePanel" aria-label="Mundarija va betlar"
               class="hidden absolute md:static inset-y-0 left-0 z-20 w-64 shrink-0 bg-white border-r border-slate-200 shadow-xl md:shadow-none">
            <div class="flex flex-col h-full">
                <div class="flex border-b border-slate-200 text-sm font-semibold shrink-0">
                    <button type="button" id="tabToc" class="flex-1 px-3 py-2 hidden">Mundarija</button>
                    <button type="button" id="tabThumbs" class="flex-1 px-3 py-2">Betlar</button>
                </div>

                <!-- Mundarija -->
                <nav id="tocList" class="hidden flex-1 overflow-y-auto p-2 space-y-0.5" aria-label="Mundarija"></nav>

                <!-- Thumbnail'lar: faqat ko'rinib turganlari chiziladi -->
                <div id="thumbScroll" class="flex-1 overflow-y-auto p-2">
                    <div id="thumbList" class="grid grid-cols-2 gap-2"></div>
                </div>
            </div>
        </aside>

        <!-- ====== KITOB MAYDONI ====== -->
        <div id="stage" class="flex-1 min-w-0 relative" style="background:#334155;" aria-label="Kitob">
            <div id="bookHost" style="position:absolute; inset:12px;">
                <div id="book" style="width:100%; height:100%;"></div>
            </div>
            <p id="readerMsg" class="absolute inset-0 grid place-items-center text-slate-200 text-sm px-6 text-center">PDF yuklanmoqda…</p>
        </div>
    </div>
</div>

<!-- Kutubxonalar (CDN). Versiyalar qotirilgan: kutilmagan yangilanish o'quvchini buzmasin -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.min.js"></script>

<script>
(function () {
    'use strict';

    var CONFIG = <?= $configJson ?>;

    // ------------------------------------------------------------
    // 0. SOZLAMALAR, ELEMENTLAR VA HOLAT
    // ------------------------------------------------------------
    var AHEAD        = 3;      // joriy betdan oldinga nechta bet oldindan chiziladi
    var BEHIND       = 2;      // orqaga nechta bet
    var KEEP         = 8;      // shu masofadan uzoqdagi betlarning canvas'i bo'shatiladi
    var MAX_PX       = 1600;   // bet canvas'ining eng katta kengligi (piksel) – telefon xotirasi uchun
    var THUMB_PX     = 220;    // thumbnail canvas kengligi (piksel)
    var DPR          = Math.min(window.devicePixelRatio || 1, 2.5);

    var reader      = document.getElementById('reader');
    var stage       = document.getElementById('stage');
    var bookEl      = document.getElementById('book');
    var msgEl       = document.getElementById('readerMsg');
    var pageInput   = document.getElementById('pageInput');
    var totalEl     = document.getElementById('pageTotal');
    var prevBtn     = document.getElementById('prevPage');
    var nextBtn     = document.getElementById('nextPage');
    var panelToggle = document.getElementById('panelToggle');
    var sidePanel   = document.getElementById('sidePanel');
    var tabToc      = document.getElementById('tabToc');
    var tabThumbs   = document.getElementById('tabThumbs');
    var tocList     = document.getElementById('tocList');
    var thumbScroll = document.getElementById('thumbScroll');
    var thumbList   = document.getElementById('thumbList');
    var fsToggle    = document.getElementById('fsToggle');

    var pdfDoc   = null;     // yuklangan PDF
    var numPages = 0;
    var baseW = 0, baseH = 0;   // birinchi betning o'lchami (kitob nisbati shundan olinadi)
    var flip     = null;     // StPageFlip obyekti
    var cur      = 0;        // hozirgi (chap) bet indeksi, 0 dan boshlanadi
    var ready    = false;    // kitob to'liq tayyor bo'ldimi

    var pages      = [];     // [{wrap, canvas, key}]  key = qaysi o'lchamda chizilgani (-1 = chizilmagan)
    var thumbs     = [];     // [{box, canvas, state, visible}]
    var thumbQueue = [];     // chizilishi kutilayotgan thumbnail indekslari
    var tocButtons = [];     // [{node, page}]
    var busy       = false;  // chizish navbati band yoki yo'q
    var currentPx  = 800;    // hozirgi bet uchun canvas kengligi (piksel)
    var sizeKey    = 0;      // o'lcham "chelagi": o'zgarsa betlar qayta chiziladi
    var litThumbs  = [];     // hozir ajratib ko'rsatilgan thumbnail'lar
    var STORAGE_KEY = 'readpos:' + CONFIG.userId + ':' + CONFIG.bookId;

    /** Element yaratadi (matn faqat textContent orqali – XSS bo'lmaydi) */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function showMessage(text, isError) {
        msgEl.textContent = text;
        msgEl.className = 'absolute inset-0 grid place-items-center text-sm px-6 text-center ' +
                          (isError ? 'text-red-300 font-semibold' : 'text-slate-200');
        msgEl.classList.remove('hidden');
    }

    function hideMessage() { msgEl.classList.add('hidden'); }

    // ------------------------------------------------------------
    // 1. OXIRGI O'QILGAN BETNI ESLAB QOLISH (localStorage; xato bo'lsa jim o'tamiz)
    // ------------------------------------------------------------
    function savePage() {
        try { localStorage.setItem(STORAGE_KEY, String(cur)); } catch (e) { /* xotira yopiq bo'lishi mumkin */ }
    }

    function loadSavedPage() {
        try {
            var v = parseInt(localStorage.getItem(STORAGE_KEY), 10);
            return isNaN(v) ? 0 : v;
        } catch (e) {
            return 0;
        }
    }

    // ------------------------------------------------------------
    // 2. O'LCHAMNI ANIQLASH
    //    Ko'rinib turgan bet qancha kenglikda ekanini o'lchab, canvas piksel o'lchamini hisoblaymiz.
    // ------------------------------------------------------------
    function measure() {
        var w = 0;

        // Ko'rinib turgan bet elementi (yashirin betlarning kengligi 0 bo'ladi)
        for (var k = cur; k <= cur + 1 && k < numPages; k++) {
            if (pages[k] && pages[k].wrap.clientWidth > 0) { w = pages[k].wrap.clientWidth; break; }
        }
        // Zaxira: maydon kengligidan taxminiy hisob (tor bo'lsa 1 bet, keng bo'lsa 2 bet)
        if (!w) {
            var hw = bookEl.clientWidth || stage.clientWidth || 600;
            w = hw < 520 ? hw : hw / 2;
        }

        currentPx = Math.max(300, Math.min(Math.round(w * DPR), MAX_PX));
        sizeKey   = Math.round(currentPx / 150);   // kichik o'zgarishlarda qayta chizmaslik uchun "chelak"
    }

    // ------------------------------------------------------------
    // 3. CHIZISH NAVBATI
    //    Bir vaqtda faqat bitta bet chiziladi. Avval joriy betlar, keyin thumbnail'lar.
    // ------------------------------------------------------------

    /** Joriy bet atrofidagi betlar, muhimlik tartibida */
    function windowOrder() {
        var order = [cur, cur + 1, cur - 1, cur + 2, cur + 3, cur - 2];
        var out = [];
        order.forEach(function (i) {
            // AHEAD va BEHIND sozlamalari doirasidagi betlargina
            if (i >= 0 && i < numPages && i <= cur + AHEAD && i >= cur - BEHIND) { out.push(i); }
        });
        return out;
    }

    /** Bet canvas'ini bo'shatadi (xotirani qaytaradi) */
    function freePage(i) {
        var p = pages[i];
        p.canvas.width = 0;
        p.canvas.height = 0;
        p.key = -1;
    }

    /** Joriy holatga qarab: uzoq betlarni bo'shatadi va chizishni boshlaydi */
    function schedule() {
        if (!pdfDoc) { return; }
        for (var i = 0; i < numPages; i++) {
            if (pages[i].key !== -1 && (i < cur - KEEP || i > cur + KEEP + 1)) { freePage(i); }
        }
        pump();
    }

    /** Bitta betni chizadi (avval yashirin canvas'ga, so'ng ko'rinadiganiga ko'chiradi: miltillash bo'lmaydi) */
    function renderPage(i) {
        var p = pages[i];
        var wantedKey = sizeKey;
        var targetPx  = currentPx;

        return pdfDoc.getPage(i + 1).then(function (page) {
            var vp1 = page.getViewport({ scale: 1 });
            var vp  = page.getViewport({ scale: targetPx / vp1.width });

            var off = document.createElement('canvas');
            off.width  = Math.floor(vp.width);
            off.height = Math.floor(vp.height);

            return page.render({ canvasContext: off.getContext('2d'), viewport: vp }).promise.then(function () {
                p.canvas.width  = off.width;
                p.canvas.height = off.height;
                p.canvas.getContext('2d').drawImage(off, 0, 0);
                p.key = wantedKey;

                off.width = 0;      // vaqtinchalik canvas xotirasini qaytaramiz
                off.height = 0;
                page.cleanup();
            });
        });
    }

    /** Thumbnail'ni chizadi */
    function renderThumb(i) {
        var t = thumbs[i];

        return pdfDoc.getPage(i + 1).then(function (page) {
            var vp1 = page.getViewport({ scale: 1 });
            var vp  = page.getViewport({ scale: THUMB_PX / vp1.width });

            t.canvas.width  = Math.floor(vp.width);
            t.canvas.height = Math.floor(vp.height);

            return page.render({ canvasContext: t.canvas.getContext('2d'), viewport: vp }).promise.then(function () {
                t.state = 'ready';
                page.cleanup();
            });
        });
    }

    /** Navbatdagi keyingi ishni tanlaydi: avval joriy betlar, keyin thumbnail'lar */
    function nextJob() {
        var order = windowOrder();
        for (var a = 0; a < order.length; a++) {
            if (pages[order[a]].key !== sizeKey) { return { type: 'page', i: order[a] }; }
        }
        while (thumbQueue.length) {
            var ti = thumbQueue.shift();
            if (thumbs[ti].visible && thumbs[ti].state !== 'ready') { return { type: 'thumb', i: ti }; }
        }
        return null;
    }

    function pump() {
        if (busy || !pdfDoc) { return; }
        var job = nextJob();
        if (!job) { return; }

        busy = true;
        var task = job.type === 'page' ? renderPage(job.i) : renderThumb(job.i);

        task.catch(function () {
            // Bitta bet chizilmasa, butun o'quvchi to'xtab qolmasin: keyingisiga o'tamiz
            if (job.type === 'page') { pages[job.i].key = sizeKey; }
        }).then(function () {
            busy = false;
            pump();
        });
    }

    // ------------------------------------------------------------
    // 4. INTERFEYSNI YANGILASH (bet raqami, tugmalar, faol thumbnail va mundarija)
    // ------------------------------------------------------------
    function isLandscape() {
        return flip && flip.getOrientation() === 'landscape';
    }

    /** Ko'rinib turgan betlar oralig'i (1 dan boshlab): [chap, o'ng] */
    function visibleRange() {
        var left  = cur + 1;
        var right = (isLandscape() && cur > 0 && cur + 2 <= numPages) ? cur + 2 : left;
        return [left, right];
    }

    function updateUI() {
        var r = visibleRange();

        pageInput.value = String(r[0]);
        prevBtn.disabled = cur <= 0;
        nextBtn.disabled = r[1] >= numPages;

        // Faol thumbnail'lar
        litThumbs.forEach(function (n) { n.classList.remove('ring-2', 'ring-blue-500'); });
        litThumbs = [];
        for (var p = r[0]; p <= r[1]; p++) {
            if (thumbs[p - 1]) {
                thumbs[p - 1].box.classList.add('ring-2', 'ring-blue-500');
                litThumbs.push(thumbs[p - 1].box);
            }
        }
        if (thumbs[r[0] - 1] && !sidePanel.classList.contains('hidden') && !thumbScroll.classList.contains('hidden')) {
            thumbs[r[0] - 1].box.scrollIntoView({ block: 'nearest' });
        }

        // Mundarijada faol band: bet raqami ko'rinayotgan oxirgi betdan oshmagan oxirgi band
        var active = -1;
        tocButtons.forEach(function (b, i) {
            if (b.page <= r[1] && b.page <= numPages) { active = i; }
        });
        tocButtons.forEach(function (b, i) {
            var on = (i === active);
            b.node.classList.toggle('bg-blue-50', on);
            b.node.classList.toggle('text-blue-700', on);
        });
    }

    /** Varaq o'girilganda (yoki boshqa usul bilan bet o'zgarganda) chaqiriladi */
    function onPageChanged(idx) {
        if (typeof idx !== 'number' || isNaN(idx)) { return; }
        cur = Math.max(0, Math.min(numPages - 1, idx));
        savePage();
        measure();
        updateUI();
        schedule();
    }

    /** Kutubxonaning haqiqiy holatidan o'qib, interfeysni to'g'rilaydi */
    function syncFromFlip() {
        if (flip) { onPageChanged(flip.getCurrentPageIndex()); }
    }

    // ------------------------------------------------------------
    // 5. BETGA O'TISH
    // ------------------------------------------------------------
    function goTo(n) {
        if (!flip || !ready) { return; }
        n = parseInt(n, 10);
        if (isNaN(n)) { updateUI(); return; }

        var idx  = Math.max(0, Math.min(numPages - 1, n - 1));
        var diff = Math.abs(idx - cur);

        if (diff === 0) { updateUI(); return; }

        // Yaqin betga – varaq o'girilib, uzoq betga – birdaniga o'tamiz
        if (diff <= 2) { flip.flip(idx); } else { flip.turnToPage(idx); }

        setTimeout(syncFromFlip, 80);
    }

    // ------------------------------------------------------------
    // 6. YON PANEL: MUNDARIJA VA THUMBNAIL'LAR
    // ------------------------------------------------------------
    var TAB_ON  = 'flex-1 px-3 py-2 text-blue-700 border-b-2 border-blue-600 bg-blue-50';
    var TAB_OFF = 'flex-1 px-3 py-2 text-slate-600 hover:bg-slate-50';

    function setTab(name) {
        var toc = (name === 'toc');
        tocList.classList.toggle('hidden', !toc);
        thumbScroll.classList.toggle('hidden', toc);
        tabToc.className    = (toc ? TAB_ON : TAB_OFF) + (CONFIG.toc.length ? '' : ' hidden');
        tabThumbs.className = toc ? TAB_OFF : TAB_ON;
        if (!toc) { updateUI(); }   // thumbnail ochilganda joriy betga aylantiriladi
    }

    function setPanel(open) {
        sidePanel.classList.toggle('hidden', !open);
        panelToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        // Panel ochilib-yopilganda kitob maydoni o'zgaradi: kutubxona qayta hisoblasin
        setTimeout(refreshLayout, 60);
    }

    function buildToc() {
        CONFIG.toc.forEach(function (item) {
            var tooFar = item.page > numPages;   // PDF dan tashqaridagi bet – o'chiq ko'rsatiladi
            var btn = el('button',
                'w-full flex items-baseline justify-between gap-2 text-left rounded-md px-2 py-1.5 text-sm hover:bg-slate-100 ' +
                (item.level === 2 ? 'pl-6 text-slate-600' : 'font-semibold text-slate-800') +
                (tooFar ? ' opacity-40 cursor-not-allowed' : ''));
            btn.type = 'button';
            btn.disabled = tooFar;
            if (tooFar) { btn.title = 'Bu bet PDF da yo\'q (PDF ' + numPages + ' betdan iborat)'; }

            btn.appendChild(el('span', 'min-w-0 break-words', item.title));
            btn.appendChild(el('span', 'shrink-0 text-xs text-slate-400', String(item.page)));

            btn.addEventListener('click', function () {
                goTo(item.page);
                if (window.innerWidth < 768) { setPanel(false); }   // telefonda o'tgach panel yopiladi
            });

            tocList.appendChild(btn);
            tocButtons.push({ node: btn, page: item.page });
        });
    }

    /** Thumbnail kataklarini yasaydi (rasm keyin, ko'ringanda chiziladi) */
    function buildThumbs() {
        var io = null;

        if ('IntersectionObserver' in window) {
            io = new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    var i = parseInt(en.target.getAttribute('data-i'), 10);
                    var t = thumbs[i];
                    t.visible = en.isIntersecting;

                    if (en.isIntersecting) {
                        if (t.state !== 'ready' && thumbQueue.indexOf(i) < 0) { thumbQueue.push(i); }
                    } else if (t.state === 'ready') {
                        // Ko'rinishdan chiqdi: xotirani bo'shatamiz
                        t.canvas.width = 0;
                        t.canvas.height = 0;
                        t.state = 'empty';
                    }
                });
                pump();
            }, { root: thumbScroll, rootMargin: '300px 0px' });
        }

        for (var i = 0; i < numPages; i++) {
            var box = el('button', 'relative block w-full rounded-md overflow-hidden bg-white border border-slate-200 hover:border-blue-400');
            box.type = 'button';
            box.setAttribute('data-i', String(i));
            box.setAttribute('aria-label', (i + 1) + '-bet');
            box.style.aspectRatio = baseW + ' / ' + baseH;

            var num = el('span', 'absolute inset-0 grid place-items-center text-slate-300 text-lg font-bold', String(i + 1));
            var cv  = el('canvas', 'rd-canvas');
            var tag = el('span', 'absolute bottom-0 right-0 bg-black/60 text-white text-[10px] px-1.5 rounded-tl', String(i + 1));

            box.appendChild(num);
            box.appendChild(cv);
            box.appendChild(tag);

            (function (index) {
                box.addEventListener('click', function () {
                    goTo(index + 1);
                    if (window.innerWidth < 768) { setPanel(false); }
                });
            })(i);

            thumbList.appendChild(box);
            thumbs.push({ box: box, canvas: cv, state: 'empty', visible: false });
            if (io) { io.observe(box); }
        }

        // Eski brauzer (IntersectionObserver yo'q): faqat birinchi 12 ta thumbnail chiziladi
        if (!io) {
            for (var k = 0; k < Math.min(12, numPages); k++) {
                thumbs[k].visible = true;
                thumbQueue.push(k);
            }
        }
    }

    // ------------------------------------------------------------
    // 7. KITOBNI YASASH (StPageFlip)
    // ------------------------------------------------------------
    function buildBook(startIdx) {
        // Har bir bet uchun bo'sh qolip: ichida bet raqami va canvas (lazy chiziladi)
        for (var i = 0; i < numPages; i++) {
            var wrap = el('div', 'page');
            wrap.style.background = '#fff';
            wrap.style.overflow = 'hidden';
            // Birinchi va oxirgi bet – qattiq muqova
            if (i === 0 || i === numPages - 1) { wrap.setAttribute('data-density', 'hard'); }

            var label = el('span', '', String(i + 1));
            label.style.cssText = 'position:absolute;inset:0;display:grid;place-items:center;color:#cbd5e1;font-size:2rem;font-weight:700;';
            var cv = el('canvas', 'rd-canvas');

            wrap.appendChild(label);
            wrap.appendChild(cv);
            bookEl.appendChild(wrap);
            pages.push({ wrap: wrap, canvas: cv, key: -1 });
        }

        flip = new St.PageFlip(bookEl, {
            width:  Math.round(baseW),
            height: Math.round(baseH),
            size: 'stretch',            // maydon o'lchamiga moslashadi
            minWidth: 260,  maxWidth: 1000,
            minHeight: 320, maxHeight: 1400,
            showCover: true,            // birinchi bet – yakka muqova
            usePortrait: true,          // tor ekranda (telefon) 1 betlik rejim
            mobileScrollSupport: true,
            drawShadow: true,
            maxShadowOpacity: 0.5,
            flippingTime: 700,
            startPage: startIdx,
            autoSize: true,
            swipeDistance: 30,
            useMouseEvents: true,       // burchakdan tortib varaq o'girish
            clickEventForward: false
        });

        flip.loadFromHTML(pages.map(function (p) { return p.wrap; }));

        flip.on('flip', function (e) { onPageChanged(e.data); });
        flip.on('changeOrientation', function () {
            // 1 betlik va 2 betlik rejim almashdi: bet kengligi o'zgaradi
            setTimeout(function () { measure(); updateUI(); schedule(); }, 80);
        });

        // Kutubxona tayyor bo'lgach (init hodisasi yoki kechiktirilgan zaxira) – bir marta
        var started = false;
        function afterInit() {
            if (started) { return; }
            started = true;
            ready = true;
            hideMessage();
            cur = flip.getCurrentPageIndex();
            measure();
            updateUI();
            schedule();
        }
        flip.on('init', afterInit);
        setTimeout(afterInit, 400);
    }

    /** Maydon o'lchami o'zgarganda (panel, to'liq ekran, burish) kitobni qayta hisoblaydi */
    var layoutTimer = null;
    function refreshLayout() {
        if (!flip || !ready) { return; }
        clearTimeout(layoutTimer);
        layoutTimer = setTimeout(function () {
            try { flip.update(); } catch (e) { /* kutubxona hali tayyor emas */ }
            setTimeout(function () { measure(); schedule(); }, 120);
        }, 150);
    }

    // ------------------------------------------------------------
    // 8. TUGMALAR, KLAVIATURA, TO'LIQ EKRAN
    // ------------------------------------------------------------
    prevBtn.addEventListener('click', function () { if (flip && ready) { flip.flipPrev('bottom'); setTimeout(syncFromFlip, 800); } });
    nextBtn.addEventListener('click', function () { if (flip && ready) { flip.flipNext('bottom'); setTimeout(syncFromFlip, 800); } });

    pageInput.addEventListener('change', function () { goTo(pageInput.value); });
    pageInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); goTo(pageInput.value); pageInput.blur(); }
    });

    panelToggle.addEventListener('click', function () { setPanel(sidePanel.classList.contains('hidden')); });
    tabToc.addEventListener('click', function () { setTab('toc'); });
    tabThumbs.addEventListener('click', function () { setTab('thumbs'); });

    document.addEventListener('keydown', function (event) {
        // Saqlash (Ctrl+S) va chop etish (Ctrl+P) to'silgan
        if ((event.ctrlKey || event.metaKey) && (event.key === 's' || event.key === 'S' || event.key === 'p' || event.key === 'P')) {
            event.preventDefault();
            return;
        }

        // Bet raqami maydonida yozayotganda strelkalar varaq o'girmasin
        var t = event.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT')) { return; }
        if (!flip || !ready) { return; }

        if (event.key === 'ArrowRight' || event.key === 'PageDown') { event.preventDefault(); nextBtn.click(); }
        else if (event.key === 'ArrowLeft' || event.key === 'PageUp') { event.preventDefault(); prevBtn.click(); }
        else if (event.key === 'Home') { event.preventDefault(); goTo(1); }
        else if (event.key === 'End') { event.preventDefault(); goTo(numPages); }
    });

    // To'liq ekran (iPhone Safari buni qo'llamaydi – tugma ko'rsatilmaydi)
    if (document.fullscreenEnabled && reader.requestFullscreen) {
        fsToggle.classList.remove('hidden');
        fsToggle.addEventListener('click', function () {
            if (document.fullscreenElement) { document.exitFullscreen(); } else { reader.requestFullscreen(); }
        });
        document.addEventListener('fullscreenchange', refreshLayout);
    }

    // O'ng tugma menyusi ("Rasmni saqlash") va rasmni sudrab olish o'chirilgan
    stage.addEventListener('contextmenu', function (event) { event.preventDefault(); });
    stage.addEventListener('dragstart', function (event) { event.preventDefault(); });

    // Oyna o'lchami o'zgarsa (telefonni burish va h.k.) kitob qayta hisoblanadi
    if (window.ResizeObserver) {
        new ResizeObserver(refreshLayout).observe(stage);
    } else {
        window.addEventListener('resize', refreshLayout);
    }

    // ------------------------------------------------------------
    // 9. PDF NI YUKLASH
    // ------------------------------------------------------------
    if (!window.pdfjsLib) {
        showMessage('PDF o\'quvchi kutubxonasi yuklanmadi. Internet aloqasini tekshirib, sahifani yangilang.', true);
        return;
    }
    if (!window.St || !window.St.PageFlip) {
        showMessage('Varaqlash kutubxonasi yuklanmadi. Internet aloqasini tekshirib, sahifani yangilang.', true);
        return;
    }

    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    // withCredentials  – sessiya cookie'si read_pdf.php ga yetib borishi uchun
    // disableAutoFetch – butun PDF fonda yuklanmaydi, faqat kerakli bo'laklar (Range) olinadi
    pdfjsLib.getDocument({
        url: CONFIG.pdfUrl,
        withCredentials: true,
        disableAutoFetch: true,
        rangeChunkSize: 262144
    }).promise.then(function (doc) {
        pdfDoc   = doc;
        numPages = doc.numPages;
        totalEl.textContent = String(numPages);
        pageInput.max = String(numPages);

        return doc.getPage(1);
    }).then(function (first) {
        var vp = first.getViewport({ scale: 1 });
        baseW = vp.width;
        baseH = vp.height;
        first.cleanup();

        var startIdx = Math.max(0, Math.min(numPages - 1, loadSavedPage()));

        showMessage('Kitob tayyorlanmoqda…', false);
        buildThumbs();

        // Mundarija bo'lsa – tab ochiladi va kompyuterda panel o'zi ochiq turadi
        if (CONFIG.toc.length) {
            buildToc();
            panelToggle.textContent = '☰ Mundarija';
            setTab('toc');
            if (window.innerWidth >= 768) { sidePanel.classList.remove('hidden'); panelToggle.setAttribute('aria-expanded', 'true'); }
        } else {
            setTab('thumbs');
        }

        buildBook(startIdx);
    }).catch(function (err) {
        var text = 'PDF ni ochib bo\'lmadi.';
        if (err && (err.status === 401 || err.status === 403)) { text = 'Bu kitobni o\'qish uchun tizimga kiring.'; }
        else if (err && err.status === 404) { text = 'Bu kitobning onlayn nusxasi topilmadi.'; }
        showMessage(text, true);
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
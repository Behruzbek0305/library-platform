<?php
/**
 * ============================================================
 *  Fayl: includes/footer.php
 *  Vazifasi: Sahifaning pastki qismi (</main>, footer, JavaScript fayllari).
 *  header.php da ochilgan <main> shu yerda yopiladi.
 *
 *  JavaScript fayllari (Vanilla JS, assets/js/ papkasida):
 *    menu.js         – telefon uchun yon panel (drawer)
 *    live-search.js  – qidiruvda yozish paytidagi tavsiyalar
 * ============================================================
 */
?>
    </main>

    <!-- ====== SAYT OSTKI QISMI ====== -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-sm text-slate-500">
        © <?= date('Y') ?> Kutubxona – axborot resurs markazi. Talabalar loyihasi.
    </footer>

    <script src="<?= e(url('assets/js/menu.js')) ?>" defer></script>
    <script src="<?= e(url('assets/js/live-search.js')) ?>" defer></script>
</body>
</html>
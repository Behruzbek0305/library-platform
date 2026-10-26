<?php
/**
 * ============================================================
 *  Fayl: includes/footer.php
 *  Vazifasi: Sahifaning pastki qismi (</main>, footer, kichik JavaScript).
 *  header.php da ochilgan <main> shu yerda yopiladi.
 * ============================================================
 */
?>
    </main>

    <!-- ====== SAYT OSTKI QISMI ====== -->
    <footer class="bg-white border-t border-slate-200 py-4 text-center text-sm text-slate-500">
        © <?= date('Y') ?> Kutubxona – axborot resurs markazi. Talabalar loyihasi.
    </footer>

    <!-- Telefon menyusini ochish/yopish (oddiy Vanilla JS) -->
    <script>
        document.getElementById('menuToggle').addEventListener('click', function () {
            document.getElementById('nav').classList.toggle('hidden');
        });
    </script>
</body>
</html>
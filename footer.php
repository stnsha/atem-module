<?php
/**
 * ATEM base layout - bottom partial.
 * Closes the container opened in header.php and loads scripts.
 * A page may set $page_js before including footer.php to load a page script.
 */
?>
    </div><!-- /.atem-container -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>window.ATEM_MODULE_BASE = <?php echo json_encode(ATEM_BASE); ?>;</script>
    <?php if (isset($page_js) && $page_js !== ''): ?>
    <script src="<?php echo $page_js; ?>?v=<?php echo time(); ?>"></script>
    <?php endif; ?>
</body>

</html>

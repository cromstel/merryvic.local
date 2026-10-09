<?php
/**
 * Vendor field accessibility enhancements.
 *
 * Adds role="note" and aria-live="polite" to any element that
 * carries the class `vendor-note`. Adjust the selector if the class
 * differs in the theme/template.
 */

add_action( 'wp_footer', function () { ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var notes = document.querySelectorAll('.vendor-note');
    notes.forEach(function(el) {
        el.setAttribute('role', 'note');
        el.setAttribute('aria-live', 'polite');
    });
});
</script>
<?php });

<?php
/**
 * Footer principale del tema DFN Theme
 *
 * Gestisce il markup di chiusura e l'inclusione del template del footer.
 *
 * @package DFN_Theme
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! function_exists('elementor_theme_do_location') || ! elementor_theme_do_location('footer')) {
    get_template_part('template-parts/footer');
}

wp_footer();
?>
</body>
</html>

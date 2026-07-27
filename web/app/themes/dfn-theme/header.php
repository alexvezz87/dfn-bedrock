<?php
/**
 * Header principale del tema DFN Theme
 *
 * Gestisce l'apertura HTML, i tag di head e l'inclusione del template dell'header.
 *
 * @package DFN_Theme
 */

if (! defined('ABSPATH')) {
    exit;
}

$viewport_content = apply_filters('hello_elementor_viewport_content', 'width=device-width, initial-scale=1');
$enable_skip_link  = apply_filters('hello_elementor_enable_skip_link', true);
$skip_link_url    = apply_filters('hello_elementor_skip_link_url', '#content');
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="<?php echo esc_attr($viewport_content); ?>">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>

<?php wp_body_open(); ?>

<?php if ($enable_skip_link) : ?>
    <a class="skip-link screen-reader-text" href="<?php echo esc_url($skip_link_url); ?>"><?php echo esc_html__('Skip to content', 'hello-elementor'); ?></a>
<?php endif; ?>

<?php
if (! function_exists('elementor_theme_do_location') || ! elementor_theme_do_location('header')) {
    get_template_part('template-parts/header');
}

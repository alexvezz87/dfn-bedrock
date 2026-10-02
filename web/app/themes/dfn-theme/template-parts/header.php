<?php
/**
 * Template part per l'header del sito (Ispirato al layout FAI)
 *
 * Include:
 * 1. Logo personalizzabile da WP Admin (Customizer)
 * 2. Voci di menu (Location: menu-1 / Header)
 * 3. Elemento Login / Registrazione / Area Utente
 * 4. Navigazione mobile responsive con toggle
 *
 * @package DFN_Theme
 */

if (! defined('ABSPATH')) {
    exit;
}

$site_name = get_bloginfo('name');
?>

<header id="site-header" class="dfn-site-header">
    <div class="dfn-header-container">
        
        <!-- 1. LOGO SITO -->
        <div class="dfn-header-logo">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="dfn-header-brand-link" rel="home" title="<?php echo esc_attr($site_name ? $site_name : 'DFN Prenotazioni'); ?>">
                <?php 
                $logo_src = '';
                if (has_custom_logo()) {
                    $custom_logo_id = get_theme_mod('custom_logo');
                    $logo_src = wp_get_attachment_image_url($custom_logo_id, 'full');
                }
                if (empty($logo_src)) {
                    $logo_src = get_stylesheet_directory_uri() . '/assets/images/logo-cupola-2.png';
                }
                ?>
                <img src="<?php echo esc_url($logo_src); ?>" class="custom-logo" alt="<?php echo esc_attr($site_name ? $site_name : 'DFN Prenotazioni'); ?>" />
                <div class="dfn-brand-text-group">
                    <span class="dfn-brand-name">DFN Prenotazioni</span>
                    <span class="dfn-brand-claim">Portale Eventi & Prenotazioni</span>
                </div>
            </a>
        </div>

        <!-- 2. MENU PRINCIPALE -->
        <nav class="dfn-header-nav" aria-label="<?php echo esc_attr__('Menu Principale', 'dfn-theme'); ?>">
            <?php
            if (has_nav_menu('menu-1')) {
                wp_nav_menu([
                    'theme_location' => 'menu-1',
                    'container'      => false,
                    'menu_class'     => 'dfn-header-menu',
                    'depth'          => 2,
                    'fallback_cb'    => false,
                ]);
            } else {
                // Menu di fallback con 2 voci predefinite come richiesto
                ?>
                <ul class="dfn-header-menu">
                    <li class="menu-item"><a href="<?php echo esc_url(home_url('/')); ?>">Eventi</a></li>
                    <li class="menu-item"><a href="<?php echo esc_url(home_url('/#chi-siamo')); ?>">Chi Siamo</a></li>
                </ul>
                <?php
            }
            ?>
        </nav>

        <!-- 3. AREA UTENTE / LOGIN & REGISTRAZIONE -->
        <div class="dfn-header-user-action">
            <?php echo do_shortcode('[cv_login_biglietti]'); ?>
        </div>

        <!-- 4. PULSANTE MENU MOBILE -->
        <button class="dfn-mobile-toggle" aria-expanded="false" aria-label="<?php echo esc_attr__('Apri Menu', 'dfn-theme'); ?>">
            <svg class="icon-menu" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg class="icon-close" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>

    </div>
</header>

<?php

/**
 * Template part per il footer del sito (Ispirato al layout del sito ufficiale FAI)
 *
 * Include:
 * 1. Top Bar con menu di navigazione secondaria (Footer Menu / Privacy, Area Personale, Convenzioni, etc.)
 * 2. Griglia principale con 3 sezioni: Dati fiscali e contatti Delegazione FAI Novara, Informativa Legale, Social Network
 *
 * @package DFN_Theme
 */

if (! defined('ABSPATH')) {
    exit;
}

$my_account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/');
$privacy_url    = get_privacy_policy_url();
if (empty($privacy_url)) {
    $privacy_url = home_url('/privacy-policy/');
}
?>

<footer id="site-footer" class="dfn-site-footer">
    <!-- 1. TOP BAR NAVIGAZIONE FOOTER MENU -->
    <div class="dfn-footer-top-bar">
        <div class="dfn-footer-container">
            <nav class="dfn-footer-nav" aria-label="<?php echo esc_attr__('Menu Footer', 'dfn-theme'); ?>">
                <?php
                if (has_nav_menu('footer-menu')) {
                    wp_nav_menu([
                        'theme_location' => 'footer-menu',
                        'container'      => false,
                        'menu_class'     => 'dfn-footer-menu',
                        'depth'          => 1,
                        'fallback_cb'    => false,
                    ]);
                } else {
                ?>
                    <ul class="dfn-footer-menu">
                        <li><a href="<?php echo esc_url($my_account_url); ?>"><?php esc_html_e('AREA PERSONALE', 'dfn-theme'); ?></a></li>
                        <li><a href="<?php echo esc_url($privacy_url); ?>"><?php esc_html_e('PRIVACY POLICY', 'dfn-theme'); ?></a></li>
                        <li><a href="https://fondoambiente.it/cosa-facciamo/convenzioni/" target="_blank" rel="noopener"><?php esc_html_e('CONVENZIONI', 'dfn-theme'); ?></a></li>
                        <li><a href="mailto:novara@delegazionefai.fondoambiente.it"><?php esc_html_e('CONTATTACI', 'dfn-theme'); ?></a></li>
                        <li><a href="https://fondoambiente.it" target="_blank" rel="noopener"><?php esc_html_e('SITO UFFICIALE FAI', 'dfn-theme'); ?></a></li>
                    </ul>
                <?php
                }
                ?>
            </nav>
        </div>
    </div>

    <!-- 2. MAIN FOOTER CONTENT (INFO, LEGALE, SOCIAL) -->
    <div class="dfn-footer-main-bar">
        <div class="dfn-footer-container dfn-footer-grid">

            <!-- SEZIONE 1: Dati Delegazione & Contatti -->
            <div class="dfn-footer-col dfn-footer-info">
                <div class="dfn-footer-brand">
                    <strong>DFN Prenotazioni</strong>
                </div>
                <div class="dfn-footer-text">
                    Tel. <a href="tel:+393471375245">+39 347 137 5245</a><br>
                    Email: <a href="mailto:novara@delegazionefai.fondoambiente.it">novara@delegazionefai.fondoambiente.it</a><br>
                </div>
            </div>

            <!-- SEZIONE 2: Descrizione Fondazione -->
            <div class="dfn-footer-col dfn-footer-desc">
                <p>
                    Portale per la prenotazione di eventi culturali sul territorio novarese.
                </p>
            </div>

        </div>
    </div>

    <!-- 3. BOTTOM COPYRIGHT BAR -->
    <div class="dfn-footer-bottom-bar">
        <div class="dfn-footer-container">
            <p>&copy; <?php echo esc_html(date('Y')); ?> DFN Prenotazioni. Tutti i diritti riservati.</p>
        </div>
    </div>
</footer>
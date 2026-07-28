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
                    <strong>Delegazione FAI Novara</strong>
                </div>
                <div class="dfn-footer-text">
                    Via Gaudenzio Ferrari, 20 - 28100 Novara<br>
                    Tel. <a href="tel:+393471375245">+39 347 137 5245</a><br>
                    Email: <a href="mailto:novara@delegazionefai.fondoambiente.it">novara@delegazionefai.fondoambiente.it</a><br>
                    C.F. 94050380032
                </div>
            </div>

            <!-- SEZIONE 2: Descrizione Fondazione -->
            <div class="dfn-footer-col dfn-footer-desc">
                <p>
                    Fondazione nazionale senza scopo di lucro per la tutela e la valorizzazione dell'arte, della natura e del paesaggio italiani.
                </p>
                <p class="dfn-footer-subtext">
                    Riconosciuta con DPR 941 del 3.12.1975 - Iscritta al RUNTS rep. n. 2092
                </p>
            </div>

            <!-- SEZIONE 3: Social Network FAI Novara -->
            <div class="dfn-footer-col dfn-footer-social">
                <span class="dfn-social-title"><?php esc_html_e('Seguici su', 'dfn-theme'); ?></span>
                <div class="dfn-social-icons">
                    <!-- Facebook -->
                    <a href="https://www.facebook.com/faigiovani.novara/" target="_blank" rel="noopener" aria-label="Facebook FAI Giovani Novara" title="Facebook FAI Giovani Novara" class="dfn-social-link">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M22.675 0h-21.35c-.732 0-1.325.593-1.325 1.325v21.351c0 .731.593 1.324 1.325 1.324h11.495v-9.294h-3.128v-3.622h3.128v-2.671c0-3.1 1.893-4.788 4.659-4.788 1.325 0 2.463.099 2.795.143v3.24l-1.918.001c-1.504 0-1.795.715-1.795 1.763v2.313h3.587l-.467 3.622h-3.12v9.293h6.116c.73 0 1.323-.593 1.323-1.325v-21.35c0-.732-.593-1.325-1.325-1.325z"/>
                        </svg>
                    </a>

                    <!-- Instagram -->
                    <a href="https://www.instagram.com/fainovara/" target="_blank" rel="noopener" aria-label="Instagram FAI Novara" title="Instagram FAI Novara" class="dfn-social-link">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/>
                        </svg>
                    </a>

                    <!-- Sito Web -->
                    <a href="https://fondoambiente.it" target="_blank" rel="noopener" aria-label="Sito Ufficiale FAI" title="Sito Ufficiale FAI" class="dfn-social-link">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                        </svg>
                    </a>
                </div>
            </div>

        </div>
    </div>

    <!-- 3. BOTTOM COPYRIGHT BAR -->
    <div class="dfn-footer-bottom-bar">
        <div class="dfn-footer-container">
            <p>&copy; <?php echo esc_html(date('Y')); ?> FAI - Fondo per l'Ambiente Italiano ETS — Delegazione di Novara. Tutti i diritti riservati.</p>
        </div>
    </div>
</footer>

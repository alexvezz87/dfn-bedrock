<?php
/**
 * DFN Booking System 2.0 — Live Scanner Page
 *
 * Interfaccia grafica ottimizzata per mobile (PWA standalone) e gestore degli asset
 * per la scansione live dei QR code all'ingresso degli eventi FAI.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Aggancia il caricamento degli asset dello scanner
add_action('admin_enqueue_scripts', 'dfn_enqueue_scanner_assets');

/**
 * Carica gli asset dello scanner solo sulla pagina dedicata dello scanner live.
 *
 * @param string $hook Pagina admin corrente.
 * @return void
 */
function dfn_enqueue_scanner_assets($hook): void
{
    if (strpos($hook, 'dfn-scanner-live') === false) {
        return;
    }

    // Enqueue degli stili premium specifici per lo scanner
    wp_enqueue_style(
        'dfn-scanner-css',
        get_stylesheet_directory_uri() . '/assets/css/dfn-scanner.css',
        [],
        '2.0.0',
    );

    // Libreria esterna per la decodifica dei QR Code via camera
    wp_enqueue_script(
        'html5-qrcode',
        'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
        [],
        null,
        false,
    );

    // Controller script dello scanner
    wp_enqueue_script(
        'dfn-scanner-js',
        get_stylesheet_directory_uri() . '/assets/js/dfn-scanner.js',
        [ 'html5-qrcode', 'jquery' ],
        '2.0.0',
        true,
    );

    // Localizza variabili utili per AJAX dello scanner
    wp_localize_script('dfn-scanner-js', 'dfnScannerVars', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('dfn_scanner_nonce'),
    ]);
}

/**
 * Rende l'interfaccia a schermo intero dello scanner per gli operatori.
 *
 * @return void
 */
function dfn_render_pagina_scanner_live(): void
{
    if (! current_user_can('dfn_use_scanner')) {
        wp_die(esc_html__('Non hai i permessi necessari per accedere allo scanner.', 'dfn-theme'));
    }
    ?>
    <div class="dfn-scanner-wrapper">
        <div class="dfn-scanner-header">
            <h1>📷 FAI Prenotazioni — Check-in Live</h1>
            <p><?php esc_html_e('Mostra il codice QR ricevuto per convalidare l\'accesso ed incassare le quote In Loco.', 'dfn-theme'); ?></p>
        </div>

        <!-- Sezione telecamera -->
        <div id="dfn-reader"></div>

        <button id="dfn-btn-start" class="dfn-scanner-btn-start">
            <span class="dashicons dashicons-camera" style="margin-right: 6px;"></span>
            <?php esc_html_e('Avvia Fotocamera', 'dfn-theme'); ?>
        </button>

        <!-- Area per visualizzare le modali di successo/errore/saldo -->
        <div id="dfn-scan-modal-container" style="display: none;"></div>
    </div>
    <?php
}

// -------------------------------------------------------------------
// PWA STANDALONE CONFIGURATION (NASCONDE LA BARRA DI ADMIN DI WP)
// -------------------------------------------------------------------
add_action('admin_head', 'dfn_scanner_pwa_head_adaptations');

/**
 * Ottimizza l'interfaccia rimuovendo menu e barre di amministrazione di WordPress
 * per trasformare l'interfaccia dello scanner in una vera e propria PWA per smartphone.
 *
 * @return void
 */
function dfn_scanner_pwa_head_adaptations(): void
{
    if (isset($_GET['page']) && $_GET['page'] === 'dfn-scanner-live') {
        echo '<meta name="mobile-web-app-capable" content="yes"><meta name="theme-color" content="#111827">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';

        // CSS per nascondere l'admin bar e la barra laterale di WordPress per gli operatori
        echo '<style>
            #wpadminbar { display: none !important; }
            html.wp-toolbar { padding-top: 0 !important; }
            #adminmenumain { display: none !important; }
            #wpcontent { margin-left: 0 !important; }
            #wpfooter { display: none !important; }
            .update-nag, .notice, .notice-error, .notice-warning, .notice-success, .notice-info { display: none !important; }
        </style>';
    }
}

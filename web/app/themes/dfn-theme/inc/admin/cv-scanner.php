<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * INTERFACCIA SCANNER LIVE, PWA MANIFEST E ELABORAZIONE SCANSIONE
 * ========================================================================
 */
add_action( 'admin_menu', 'cv_aggiungi_pagina_scanner_live' );
function cv_aggiungi_pagina_scanner_live() {
    $hook = add_menu_page( 'Scanner Live Eventi', '🔴 Scanner Live', 'cv_use_scanner', 'cv-scanner-live', 'cv_render_pagina_scanner_live', 'dashicons-camera', 56 );
    add_action( "admin_enqueue_scripts", 'cv_enqueue_scanner_assets' );
}

function cv_enqueue_scanner_assets( $hook ) {
    if ( $hook !== 'toplevel_page_cv-scanner-live' ) return;

    wp_enqueue_style( 'cv-scanner-css', get_stylesheet_directory_uri() . '/assets/css/cv-scanner.css', array(), '1.0' );
    
    // Libreria esterna QR Code
    wp_enqueue_script( 'html5-qrcode', 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js', array(), null, false );
    wp_enqueue_script( 'cv-scanner-js', get_stylesheet_directory_uri() . '/assets/js/cv-scanner.js', array('html5-qrcode', 'jquery'), '1.0', true );

    wp_localize_script( 'cv-scanner-js', 'cvScannerVars', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'cv_scanner_nonce_action' )
    ) );
}

function cv_render_pagina_scanner_live() {
    if ( ! current_user_can( 'cv_use_scanner' ) ) return;
    ?>
    <div class="cv-scanner-wrapper">
        <div class="cv-header"><h1>Scanner Check-in</h1></div>
        <div id="reader"></div>
        <button id="btn-start" class="button button-primary button-hero">📷 Avvia Fotocamera</button>
        <div id="scan-result"></div>
    </div>
    <?php
}

// ---------------------------------------------------------
// LOGICA PWA (MANIFEST E HEAD TAGS)
// ---------------------------------------------------------
add_action( 'parse_request', 'cv_pwa_manifest_endpoint' );
function cv_pwa_manifest_endpoint( $wp ) {
    if ( isset( $_GET['cv_manifest'] ) ) {
        header( 'Content-Type: application/json; charset=utf-8' );
        $icon_url = site_url( '/wp-content/uploads/2023/02/favicon-dfn.png' ); 

        echo json_encode( array(
            'name'             => 'Scanner CandleVibes',
            'short_name'       => 'Scanner CV',
            'description'      => 'Strumento di Check-in per Eventi',
            'start_url'        => admin_url( 'admin.php?page=cv-scanner-live' ), 
            'display'          => 'standalone', 
            'background_color' => '#111111', 
            'theme_color'      => '#111111', 
            'icons'            => array(
                array( 'src' => $icon_url, 'sizes' => '192x192', 'type' => 'image/png' ),
                array( 'src' => $icon_url, 'sizes' => '512x512', 'type' => 'image/png' )
            )
        ) );
        exit;
    }
}

add_action( 'admin_head', 'cv_pwa_add_head_tags' );
function cv_pwa_add_head_tags() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'cv-scanner-live' ) {
        $manifest_url = site_url( '/?cv_manifest=1' );
        echo '<link rel="manifest" href="' . esc_url( $manifest_url ) . '">';
        echo '<meta name="mobile-web-app-capable" content="yes"><meta name="theme-color" content="#111111">';
        echo '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="Scanner CV">';
        echo '<style>#wpadminbar { display: none !important; } html.wp-toolbar { padding-top: 0 !important; } #adminmenumain { display: none !important; } #wpcontent { margin-left: 0 !important; }</style>';
    }
}


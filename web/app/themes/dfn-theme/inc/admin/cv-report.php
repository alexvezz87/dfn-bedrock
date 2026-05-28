<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * REPORT CHECK-IN E MAPPA TAVOLI (SOLO INTERFACCIA)
 * ========================================================================
 */
add_action( 'admin_menu', 'cv_aggiungi_pagina_report_checkin' );
function cv_aggiungi_pagina_report_checkin() {
    $hook = add_submenu_page(
        'woocommerce', 'Report Check-in Eventi', 'Check-in Eventi', 'manage_woocommerce', 'cv-report-checkin', 'cv_render_pagina_report_checkin'
    );
    add_action( "admin_enqueue_scripts", 'cv_enqueue_report_assets' );
}

function cv_enqueue_report_assets( $hook ) {
    if ( $hook !== 'woocommerce_page_cv-report-checkin' ) return;

    wp_enqueue_style( 'cv-report-css', get_stylesheet_directory_uri() . '/assets/css/cv-report.css', array(), '1.0' );
    wp_enqueue_script( 'cv-report-js', get_stylesheet_directory_uri() . '/assets/js/cv-report.js', array('jquery'), '1.0', true );

    $selected_event = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
    
    wp_localize_script( 'cv-report-js', 'cvReportVars', array(
        'ajaxurl'       => admin_url( 'admin-ajax.php' ),
        'eventId'       => $selected_event,
        'nonceFetch'    => wp_create_nonce('cv_fetch_report_nonce'),
        'nonceManual'   => wp_create_nonce('cv_manual_checkin_nonce'),
        'nonceTable'    => wp_create_nonce('cv_assign_table_nonce'),
        'nonceReminder' => wp_create_nonce('cv_reminder_nonce'),
        'nonceFeedback' => wp_create_nonce('cv_feedback_nonce') 
    ) );
}

function cv_render_pagina_report_checkin() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $selected_event = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
    $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );
    
    $is_delitto = ($selected_event === CV_EVENT_ID_DELITTO);

    echo '<div class="wrap"><h1 class="wp-heading-inline">Report & Cassa Check-in</h1>';
    echo '<p style="font-size:15px; color:#555;">Seleziona un evento per monitorare gli accessi in tempo reale, validare manualmente i biglietti e gestire l\'evento.</p>';
    
    echo '<form method="GET" style="margin-top:20px; margin-bottom: 20px; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px; display:inline-block;">';
    echo '<input type="hidden" name="page" value="cv-report-checkin">';
    echo '<label style="font-weight:bold; margin-right:10px;">Seleziona l\'evento:</label>';
    echo '<select name="event_id" id="cv-event-selector" style="min-width:300px;"><option value="">-- Seleziona un Evento --</option>';
    foreach ( $products as $product ) {
        echo '<option value="' . esc_attr( $product->get_id() ) . '" ' . selected( $selected_event, $product->get_id(), false ) . '>' . esc_html( $product->get_name() ) . '</option>';
    }
    echo '</select><button type="submit" class="button button-primary" style="margin-left:10px;">Carica Partecipanti</button></form>';

    if ( $selected_event > 0 ) {
        echo '<div style="background:#fff; border-left:4px solid #2271b1; padding:20px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04); max-width: 1000px; display:flex; justify-content:space-between; align-items:center; flex-wrap: wrap; gap: 20px;">';
        
        echo '<div style="flex-grow:1;">';
        echo '<h2 style="margin-top:0; display:flex; align-items:center; font-size:18px;">Riepilogo Ingressi <span id="cv-sync-icon" class="dashicons dashicons-update" style="display:none; margin-left:10px; color:#888;"></span></h2>';
        echo '<div style="display:flex; gap:30px; margin-top:15px; flex-wrap:wrap;">';
        echo '<div style="background:#f0f6fc; padding:10px 20px; border-radius:8px; border:1px solid #c8d7e1; min-width:120px;"><span style="font-size:12px; color:#555; text-transform:uppercase; font-weight:bold;">Venduti</span><br><strong style="font-size:32px; color:#2271b1;" id="cv-tot-venduti">-</strong></div>';
        echo '<div style="background:#eaf7ea; padding:10px 20px; border-radius:8px; border:1px solid #c3e6c3; min-width:120px;"><span style="font-size:12px; color:#555; text-transform:uppercase; font-weight:bold;">Entrati (Check-in)</span><br><strong style="color:#16a34a; font-size:32px;" id="cv-tot-checkin">-</strong></div>';
        echo '<div style="background:#fef2f2; padding:10px 20px; border-radius:8px; border:1px solid #fecaca; min-width:120px;"><span style="font-size:12px; color:#555; text-transform:uppercase; font-weight:bold;">In Attesa</span><br><strong style="color:#d63638; font-size:32px;" id="cv-tot-residui">-</strong></div>';
        echo '<div style="background:#fffbeb; padding:10px 20px; border-radius:8px; border:1px solid #fde68a; min-width:120px;"><span style="font-size:12px; color:#555; text-transform:uppercase; font-weight:bold;">Posti Liberi</span><br><strong style="color:#d97706; font-size:32px;" id="cv-tot-liberi">-</strong></div>';
        echo '</div></div>';
        
        echo '<div style="text-align:right; border-left: 1px solid #eee; padding-left: 20px; min-width: 250px;">';
        if ( $is_delitto ) {
            echo '<button id="cv-auto-assign-tables-btn" class="button" style="background:#10b981; color:#fff; border-color:#10b981; font-size: 14px; padding: 5px 15px; margin-bottom: 8px; display:block; width:100%;">🪄 Smistamento Automatico</button>';
            echo '<button id="cv-show-map-btn" class="button" style="background:#8b5cf6; color:#fff; border-color:#8b5cf6; font-size: 14px; padding: 5px 15px; margin-bottom: 8px; display:block; width:100%;">🗺️ Mappa Tavoli (Stampa)</button>';
        }
        
        echo '<button id="cv-send-reminders-btn" class="button button-primary" style="background:#ff6600; border-color:#ff6600; font-size: 14px; padding: 5px 15px; display:block; width:100%;">📧 Invia Reminder a Tutti</button>';
        echo '<button id="cv-send-feedback-btn" class="button" style="background:#eab308; color:#fff; border-color:#d97706; font-size: 14px; padding: 5px 15px; display:block; width:100%; margin-top:8px;">⭐ Richiedi Recensioni</button>';
        
        echo '</div></div>';

        echo '<div id="cv-validator-leaderboard" style="margin-bottom:20px;"></div>';

        echo '<div style="margin: 20px 0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:15px;">';
        echo '<div style="display:flex; gap:10px; flex:1; max-width:600px;">';
        echo '<input type="text" id="cv-search-table" placeholder="🔍 Cerca cliente o numero..." style="width:100%; max-width:300px; padding:0 15px; border-radius:4px; border:1px solid #8c8f94; font-size:15px; height:40px;">';
        echo '<select id="cv-filter-status" style="padding:0 15px; border-radius:4px; border:1px solid #8c8f94; font-size:15px; height:40px; color:#333; font-weight:600;">
                <option value="all">📊 Tutti gli stati</option>
                <option value="pending">🔴 Da validare</option>
                <option value="partial">🟠 In validazione</option>
                <option value="complete">🟢 Validati</option>
              </select>';
        echo '</div>';
        echo '<div id="cv-pagination-top" style="display:flex; gap:5px; align-items:center;"></div>';
        echo '</div>';

        $colspan = $is_delitto ? 11 : 10;
        $colonna_tavolo = $is_delitto ? '<th style="background:#fff9e6; text-align:center;">Tavolo (Modificabile)</th>' : '';

        echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px; border-bottom: 1px solid #c3c4c7; transition: opacity 0.2s;">';
        echo '<thead><tr><th>Ordine #</th><th>Cliente</th><th>Qualifica</th><th>Telefono</th><th>Biglietti</th>' . $colonna_tavolo . '<th>Stato Arrivi</th><th>Validato da</th><th style="background:#eaf7ea; text-align:center;">Azioni Cassa</th><th style="background:#eef6fc; text-align:center;">Messaggi</th><th style="background:#f6f7f7; text-align:center;">Storico</th></tr></thead>';
        echo '<tbody id="cv-report-tbody"><tr><td colspan="' . $colspan . '" style="text-align:center; padding:30px;">⏳ Caricamento dati in corso...</td></tr></tbody></table>';

        echo '<div id="cv-pagination-bottom" style="display:flex; gap:5px; align-items:center; justify-content:center; margin-top:20px;"></div>';

        // POPUPS
        echo '<div id="cv-cassa-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:999999; align-items:center; justify-content:center;"><div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:450px; box-shadow:0 10px 30px rgba(0,0,0,0.4); max-height:85vh; display:flex; flex-direction:column;"><h2 style="margin-top:0; font-size:22px; border-bottom: 2px solid #eee; padding-bottom: 10px;">Cassa Check-in</h2><p style="font-size:16px;">Cliente: <strong id="cv-modal-cliente-name" style="color:#2271b1;"></strong></p><div id="cv-modal-buttons-area" style="flex-grow:1; overflow-y:auto; margin: 15px 0; padding-right: 5px;"></div><button type="button" class="button cv-close-modal-btn" style="text-align:center; width:100%; padding: 10px; height: auto; font-size: 16px;">Chiudi Finestra</button></div></div>';
        echo '<div id="cv-history-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:999999; align-items:center; justify-content:center;"><div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:500px; box-shadow:0 10px 30px rgba(0,0,0,0.4); max-height:85vh; display:flex; flex-direction:column;"><h2 style="margin-top:0; font-size:22px; border-bottom: 2px solid #eee; padding-bottom: 10px;">📜 Log Operazioni Cliente</h2><p style="font-size:16px;">Ordine Cliente: <strong id="cv-history-cliente-name" style="color:#2271b1;"></strong></p><div id="cv-history-content-area" style="flex-grow:1; overflow-y:auto; margin: 10px 0; padding:10px; background:#f9f9f9; border:1px solid #ddd; border-radius:5px;"></div><button type="button" class="button cv-close-modal-btn" style="text-align:center; width:100%; padding: 10px; height: auto; font-size: 16px; margin-top:10px;">Chiudi Log</button></div></div>';
        echo '<div id="cv-table-map-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:999999; align-items:center; justify-content:center;"><div class="cv-modal-content" style="background:#f0f0f1; padding:30px; border-radius:10px; width:95%; max-width:1200px; box-shadow:0 15px 40px rgba(0,0,0,0.5); max-height:90vh; display:flex; flex-direction:column;"><div class="cv-no-print" style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #ddd; padding-bottom:15px; margin-bottom:20px;"><h2 style="margin:0; font-size:26px;">🗺️ Disposizione Tavoli in Sala</h2><div><button class="button cv-print-map-btn" style="background:#8b5cf6; color:white; border-color:#8b5cf6; font-size:16px; margin-right:10px;">🖨️ Stampa Foglio Sala</button><button class="button cv-close-modal-btn" style="font-size:16px;">❌ Chiudi</button></div></div><div id="cv-table-map-content" style="flex-grow:1; overflow-y:auto; padding-right:10px;"></div></div></div>';
    }
    echo '</div>';
}
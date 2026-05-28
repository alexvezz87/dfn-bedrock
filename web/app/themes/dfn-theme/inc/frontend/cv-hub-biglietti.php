<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * HUB BIGLIETTI E GENERAZIONE SINGOLI QR
 * ========================================================================
 */

// 1. HUB: PANNELLO CLIENTE MULTI-BIGLIETTO
add_action( 'template_redirect', 'cv_render_hub_biglietti' );
function cv_render_hub_biglietti() {
    if ( isset( $_GET['cv_hub'] ) && isset( $_GET['order_id'] ) && isset( $_GET['token'] ) ) {
        $order_id = intval( $_GET['order_id'] );
        $token = sanitize_text_field( $_GET['token'] );
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) wp_die( 'Ordine non trovato.' );
        
        $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_hub', wp_salt('nonce') );
        if ( ! hash_equals( $expected_token, $token ) ) wp_die( 'Link non valido o scaduto.', 'Errore di sicurezza' );

        if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Ordine Non Valido</title>';
            echo '<style>body{font-family:sans-serif; background:#f0f0f1; padding:20px; display:flex; justify-content:center; align-items:center; min-height:80vh;}</style></head><body>';
            echo '<div style="background:#fff; padding:40px; border-radius:10px; text-align:center; max-width:500px; box-shadow:0 4px 15px rgba(0,0,0,0.1);">';
            echo '<h1 style="color:#d63638; margin-top:0;">🚫 Ordine Non Valido</h1>';
            echo '<p style="font-size:18px; color:#555; line-height:1.5;">I biglietti associati a questo ordine non sono più disponibili poiché la prenotazione risulta <strong>annullata, scaduta o rimborsata</strong>.</p>';
            echo '<a href="' . site_url() . '" style="display:inline-block; margin-top:25px; padding:12px 25px; background:#2271b1; color:#fff; text-decoration:none; border-radius:5px; font-weight:bold;">Torna al sito</a>';
            echo '</div></body></html>';
            exit;
        }

        $tot_biglietti = 0; $nomi_eventi = array();
        foreach ( $order->get_items() as $item ) { $tot_biglietti += $item->get_quantity(); $nomi_eventi[] = $item->get_name(); }
        $titolo_evento = implode(' + ', $nomi_eventi);
        $nome_cliente = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>I tuoi Biglietti</title>';
        echo '<style>
            body { font-family:sans-serif; background:#f0f0f1; padding:20px; max-width:600px; margin:0 auto; color:#333; } 
            .ticket-card { background:#fff; padding:20px; border-radius:10px; margin-bottom:20px; box-shadow:0 2px 5px rgba(0,0,0,0.1); text-align:center; } 
            .btn { padding:12px 15px; border-radius:5px; text-decoration:none; font-weight:bold; display:inline-block; margin-top:10px; box-sizing:border-box; width:100%; cursor:pointer; border:none; font-size:16px; } 
            .btn-print { background:#333; color:#fff; } .btn-wa { background:#25D366; color:#fff; } .btn-link { background:#2271b1; color:#fff; margin-top:5px; } .btn-download { background:#8b5cf6; color:#fff; margin-top:5px; }
            .qr-img { width: 200px; height: 200px; margin: 15px auto; display: block; border:1px solid #eee; padding:10px; border-radius:8px;}
            @media print {
                body { background: #fff; padding: 0; } .no-print { display: none !important; }
                .ticket-card { box-shadow: none; border: 2px dashed #666; page-break-inside: avoid; break-inside: avoid; margin-bottom: 25px; padding: 15px; }
                .qr-img { width: 250px; height: 250px; border:none; }
            }
        </style></head><body>';
        
        echo '<div style="text-align:center; margin-bottom:20px;"><h1 style="margin-bottom:5px;">🎟️ I tuoi Biglietti</h1><p style="margin-top:0;"><strong>' . esc_html($titolo_evento) . '</strong><br>Intestati a: ' . esc_html($nome_cliente) . '<br>Ordine #' . $order_id . '</p><button onclick="window.print();" class="btn btn-print no-print cv-track-btn" data-action="Stampa massiva di tutti i biglietti (PDF/Carta)">🖨️ Stampa tutti i biglietti su Carta</button></div>';

        for ( $i = 1; $i <= $tot_biglietti; $i++ ) {
            $ticket_token = hash_hmac( 'sha256', $order->get_order_key() . '_ticket_' . $i, wp_salt('nonce') );
            $single_ticket_url = site_url( '/?cv_ticket=1&order_id=' . $order_id . '&t=' . $i . '&token=' . $ticket_token );
            $download_url = site_url( '/?cv_download_qr=1&order_id=' . $order_id . '&t=' . $i . '&token=' . $ticket_token );
            $checkin_url = site_url( '/?cv_checkin=1&order_id=' . $order_id . '&ticket=' . $i . '&token=' . $ticket_token );
            $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode( $checkin_url ) . '&margin=10';
            $is_validato = $order->get_meta( '_cv_ticket_validato_' . $i ) === 'yes';

            echo '<div class="ticket-card">';
            echo '<h3 style="margin:0 0 10px 0; color:#2271b1; font-size:20px;">Biglietto ' . $i . ' di ' . $tot_biglietti . '</h3>';
            
            if ( $is_validato ) {
                echo '<span style="color:green; font-weight:bold; background:#eaf7ea; padding:5px 15px; border-radius:20px; font-size:14px;">✅ Già Utilizzato</span>';
            } else {
                echo '<span style="color:#fff; background:#2271b1; padding:5px 15px; border-radius:20px; font-size:14px; font-weight:bold;">🎫 Valido</span>';
                echo '<img src="' . esc_url($qr_api_url) . '" class="qr-img" alt="QR Code" />';
                echo '<div class="no-print">';
                $wa_text = urlencode("Ecco il tuo biglietto (" . $i . " di " . $tot_biglietti . ") per l'evento '" . $titolo_evento . "'. Apri il link e mostra il QR all'ingresso: " . $single_ticket_url);
                echo '<a href="https://wa.me/?text=' . $wa_text . '" target="_blank" class="btn btn-wa cv-track-btn" data-action="Condivisione WhatsApp del Biglietto ' . $i . '">💬 Invia singolo biglietto su WhatsApp</a>';
                echo '<a href="' . esc_url($single_ticket_url) . '" target="_blank" class="btn btn-link cv-track-btn" data-action="Apertura link web del Biglietto ' . $i . '">🔗 Apri link del biglietto</a>';
                echo '<a href="' . esc_url($download_url) . '" class="btn btn-download cv-track-btn" data-action="Download Immagine QR Biglietto ' . $i . '">⬇️ Salva QR Code (Immagine)</a>';
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '<div class="no-print" style="text-align:center; margin-top:30px;"><a href="' . site_url() . '" style="color:#666; text-decoration:none;">Torna al sito principale</a></div>';
        echo '<script>document.addEventListener("DOMContentLoaded", function() { var ajaxurl = "' . admin_url('admin-ajax.php') . '"; function sendTrack(actionDesc) { var formData = new FormData(); formData.append("action", "cv_track_ticket_action"); formData.append("order_id", "' . $order_id . '"); formData.append("token", "' . esc_js($token) . '"); formData.append("action_type", actionDesc); fetch(ajaxurl, { method: "POST", body: formData, keepalive: true }).catch(function(){}); } if (!sessionStorage.getItem("cv_hub_viewed_" + ' . $order_id . ')) { sendTrack("Accesso visualizzazione Hub Biglietti"); sessionStorage.setItem("cv_hub_viewed_" + ' . $order_id . ', "yes"); } var trackBtns = document.querySelectorAll(".cv-track-btn"); trackBtns.forEach(function(btn) { btn.addEventListener("click", function() { sendTrack(this.getAttribute("data-action")); }); }); });</script></body></html>';
        exit;
    }
}

// 2. PAGINA DEL SINGOLO BIGLIETTO WEB
add_action( 'template_redirect', 'cv_render_singolo_biglietto' );
function cv_render_singolo_biglietto() {
    if ( isset( $_GET['cv_ticket'] ) && isset( $_GET['order_id'] ) && isset( $_GET['t'] ) && isset( $_GET['token'] ) ) {
        $order_id = intval( $_GET['order_id'] );
        $ticket_index = intval( $_GET['t'] );
        $token = sanitize_text_field( $_GET['token'] );
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) wp_die( 'Ordine non trovato.' );
        $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_ticket_' . $ticket_index, wp_salt('nonce') );
        if ( ! hash_equals( $expected_token, $token ) ) wp_die( 'Link non valido o manomesso.', 'Errore Sicurezza' );

        if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Biglietto Annullato</title><style>body{font-family:sans-serif; background:#111; color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px;}</style></head><body><div style="background:#fff; color:#000; border-radius:15px; padding:40px 20px; text-align:center; max-width:400px; width:100%;"><h2 style="color:#d63638; margin-top:0;">🚫 Biglietto Non Valido</h2><p style="font-size:16px;">Questo biglietto è stato disattivato poiché la prenotazione principale risulta annullata o scaduta.</p></div></body></html>';
            exit;
        }

        $nomi_eventi = array(); $tot_biglietti = 0;
        foreach ( $order->get_items() as $item ) { $nomi_eventi[] = $item->get_name(); $tot_biglietti += $item->get_quantity(); }
        $titolo_evento = implode(' + ', $nomi_eventi);
        $nome_cliente = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        $checkin_url = site_url( '/?cv_checkin=1&order_id=' . $order_id . '&ticket=' . $ticket_index . '&token=' . $token );
        $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode( $checkin_url ) . '&margin=10';
        $download_url = site_url( '/?cv_download_qr=1&order_id=' . $order_id . '&t=' . $ticket_index . '&token=' . $token );
        $is_validato = $order->get_meta( '_cv_ticket_validato_' . $ticket_index ) === 'yes';

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Biglietto ' . $ticket_index . '</title><style>body{font-family:sans-serif; background:#111; color:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px; box-sizing:border-box;} .ticket-container{background:#fff; color:#000; border-radius:15px; padding:30px; text-align:center; max-width:400px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.5);} .qr-image{width:100%; max-width:250px; height:auto; margin:20px auto 10px auto; display:block; border: 1px solid #eee; padding:10px; border-radius:10px;} .badge{background:#eaf7ea; color:#166534; padding:5px 15px; border-radius:20px; font-weight:bold; display:inline-block; margin-bottom:15px;} </style></head><body><div class="ticket-container">';
        echo '<h4 style="color:#777; margin-top:0; margin-bottom:5px; text-transform:uppercase; font-size:12px;">Ingresso Evento</h4><h2 style="margin:0 0 20px 0; color:#2271b1; font-size:24px;">' . esc_html($titolo_evento) . '</h2>';
        
        if ( $is_validato ) {
            echo '<div style="background:#fef2f2; color:#991b1b; padding:20px; border-radius:8px; font-size:20px; font-weight:bold; margin:30px 0;">❌ BIGLIETTO GIÀ UTILIZZATO</div>';
        } else {
            echo '<div class="badge">Biglietto ' . $ticket_index . ' di ' . $tot_biglietti . '</div>';
            echo '<img src="' . esc_url($qr_api_url) . '" class="qr-image" alt="QR Code" />';
            echo '<a href="' . esc_url($download_url) . '" style="display:inline-block; margin-bottom:20px; padding:12px 20px; background:#8b5cf6; color:#fff; text-decoration:none; border-radius:8px; font-weight:bold; font-size:16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">⬇️ Salva QR nel telefono</a>';
            echo '<p style="font-size:14px; color:#555; margin-bottom:0;">Intestatario Ordine:</p><p style="font-size:18px; margin-top:5px; margin-bottom:0;"><strong>' . esc_html($nome_cliente) . '</strong></p>';
            echo '<p style="font-size:13px; margin-top:20px; border-top:1px dashed #ccc; padding-top:15px; color:#666;">Puoi salvare il QR Code sul telefono, fare uno screenshot, oppure tenere aperto questo link per mostrarlo all\'ingresso.</p>';
        }
        echo '</div></body></html>';
        exit;
    }
}

// 3. GESTORE DOWNLOAD FORZATO IMMAGINE QR CODE
add_action( 'template_redirect', 'cv_gestisci_download_qr' );
function cv_gestisci_download_qr() {
    if ( isset( $_GET['cv_download_qr'] ) && isset( $_GET['order_id'] ) && isset( $_GET['t'] ) && isset( $_GET['token'] ) ) {
        $order_id = intval( $_GET['order_id'] );
        $ticket_index = intval( $_GET['t'] );
        $token = sanitize_text_field( $_GET['token'] );
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_die( 'Ordine non trovato.' );

        $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_ticket_' . $ticket_index, wp_salt('nonce') );
        if ( ! hash_equals( $expected_token, $token ) ) wp_die( 'Token di sicurezza non valido.', 'Errore Sicurezza' );

        $checkin_url = site_url( '/?cv_checkin=1&order_id=' . $order_id . '&ticket=' . $ticket_index . '&token=' . $token );
        $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' . urlencode( $checkin_url ) . '&margin=20';

        $response = wp_remote_get( $qr_api_url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) wp_die( 'Errore di connessione durante la generazione del file. Riprova tra poco.' );

        $image_data = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        if ( empty( $image_data ) ) wp_die( 'Impossibile scaricare il file in questo momento.' );

        $filename = 'Biglietto-' . $order_id . '-Posto-' . $ticket_index . '.png';
        header( 'Content-Description: File Transfer' ); header( 'Content-Type: ' . $content_type ); header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Expires: 0' ); header( 'Cache-Control: must-revalidate' ); header( 'Pragma: public' ); header( 'Content-Length: ' . strlen( $image_data ) );
        echo $image_data;
        exit;
    }
}
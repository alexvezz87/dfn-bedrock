<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * BACKEND: BACHECA RECENSIONI EVENTI
 * ========================================================================
 */

add_action( 'admin_menu', 'cv_aggiungi_pagina_recensioni' );
function cv_aggiungi_pagina_recensioni() {
    add_submenu_page(
        'woocommerce',
        'Recensioni Eventi',
        '⭐ Recensioni Eventi',
        'manage_woocommerce',
        'cv-recensioni-eventi',
        'cv_render_pagina_recensioni'
    );
}

function cv_render_pagina_recensioni() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $selected_event = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
    $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );

    echo '<div class="wrap"><h1>Recensioni e Feedback Eventi</h1>';
    echo '<p>Scopri cosa pensano i partecipanti dei tuoi eventi e leggi i loro suggerimenti.</p>';

    // --- LOGICA DI CANCELLAZIONE DELLA RECENSIONE ---
    if ( isset($_POST['cv_delete_review_nonce']) && wp_verify_nonce($_POST['cv_delete_review_nonce'], 'cv_delete_review') ) {
        $order_id_to_delete = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if ( $order_id_to_delete > 0 ) {
            $order_to_update = wc_get_order( $order_id_to_delete );
            if ( $order_to_update ) {
                // Rimuoviamo i dati della recensione dall'ordine
                $order_to_update->delete_meta_data( '_cv_event_rating' );
                $order_to_update->delete_meta_data( '_cv_event_review' );
                $order_to_update->delete_meta_data( '_cv_event_rating_date' );
                
                // Opzionale: Aggiungiamo una nota all'ordine per tracciabilità
                $current_user = wp_get_current_user();
                $order_to_update->add_order_note( "🗑️ La recensione del cliente è stata eliminata manualmente dall'operatore: {$current_user->display_name}" );
                
                $order_to_update->save();
                
                echo '<div class="notice notice-success is-dismissible"><p>✅ Recensione eliminata con successo. La media voti è stata ricalcolata.</p></div>';
            }
        }
    }
    // ------------------------------------------------

    echo '<form method="GET" style="margin-bottom: 20px; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px; display:inline-block;">';
    echo '<input type="hidden" name="page" value="cv-recensioni-eventi">';
    echo '<select name="event_id" style="min-width:300px;"><option value="">-- Seleziona un Evento --</option>';
    foreach ( $products as $product ) {
        echo '<option value="' . esc_attr( $product->get_id() ) . '" ' . selected( $selected_event, $product->get_id(), false ) . '>' . esc_html( $product->get_name() ) . '</option>';
    }
    echo '</select> <button type="submit" class="button button-primary">Carica Recensioni</button></form>';

    if ( $selected_event > 0 ) {
        // PERF-01: Filtriamo direttamente per product_id per non caricare tutti gli ordini
        $orders = wc_get_orders( array( 
            'status' => array( 'wc-processing', 'wc-completed' ), 
            'limit'  => -1,
            'product_id' => $selected_event,
        ) );

        $recensioni = array();
        $somma_voti = 0;
        $tot_voti = 0;

        foreach ( $orders as $order ) {
            
            // Verifichiamo manualmente che questo ordine contenga l'evento selezionato
            $has_event = false;
            foreach ( $order->get_items() as $item ) {
                if ( $item->get_product_id() == $selected_event ) {
                    $has_event = true;
                    break;
                }
            }
            
            // Se l'ordine non c'entra niente con l'evento, lo saltiamo
            if ( ! $has_event ) continue;

            $rating = $order->get_meta( '_cv_event_rating' );
            if ( ! empty( $rating ) ) {
                // IL FIX È QUI: Usiamo wp_unslash per rimuovere i backslash dai testi salvati nel database
                $review_text = wp_unslash( $order->get_meta( '_cv_event_review' ) );
                $review_date = $order->get_meta( '_cv_event_rating_date' );
                
                if ( ! empty($review_date) ) {
                    $data_mostrata = date_i18n( 'd/m/Y H:i', strtotime($review_date) );
                    $timestamp = strtotime($review_date);
                } else {
                    $data_mostrata = $order->get_date_modified()->date_i18n('d/m/Y') . ' <span style="color:#aaa; font-size:11px;">(Stimata)</span>';
                    $timestamp = $order->get_date_modified()->getTimestamp();
                }

                $recensioni[] = array(
                    'order_id'  => $order->get_id(),
                    'cliente'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'voto'      => intval($rating),
                    'testo'     => $review_text,
                    'data'      => $data_mostrata,
                    'timestamp' => $timestamp 
                );
                
                $somma_voti += intval($rating);
                $tot_voti++;
            }
        }

        // Riordina l'array dalle recensioni più recenti a quelle più vecchie
        usort($recensioni, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        if ( $tot_voti > 0 ) {
            $media = round( $somma_voti / $tot_voti, 1 );
            
            echo '<div style="background:#fff; border-left:4px solid #f59e0b; padding:20px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04); max-width: 400px; text-align:center;">';
            echo '<h3 style="margin:0; color:#555; text-transform:uppercase;">Valutazione Globale</h3>';
            echo '<div style="font-size:48px; font-weight:bold; color:#d97706; line-height:1;">' . $media . '<span style="font-size:24px; color:#ccc;">/5</span></div>';
            echo '<p style="margin:5px 0 0 0; color:#777;">Basata su <strong>' . $tot_voti . '</strong> recensioni rilasciate.</p>';
            echo '</div>';

            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th style="width:160px;">Data</th><th style="width:200px;">Cliente</th><th style="width:150px;">Voto</th><th>Commento / Suggerimento</th><th style="width:80px; text-align:center;">Azioni</th></tr></thead><tbody>';
            
            foreach ( $recensioni as $rec ) {
                $stelle_html = str_repeat('⭐', $rec['voto']) . str_repeat('☆', 5 - $rec['voto']);
                echo '<tr>';
                echo '<td style="vertical-align: middle;">' . $rec['data'] . '</td>';
                echo '<td style="vertical-align: middle;"><strong>' . esc_html($rec['cliente']) . '</strong></td>';
                echo '<td style="vertical-align: middle;"><span style="font-size:16px;">' . $stelle_html . '</span></td>';
                echo '<td style="vertical-align: middle;">' . esc_html( empty($rec['testo']) ? 'Nessun commento testuale rilasciato.' : $rec['testo'] ) . '</td>';
                
                // Bottone di eliminazione con finestra di conferma
                echo '<td style="text-align:center; vertical-align: middle;">';
                echo '<form method="POST" action="" onsubmit="return confirm(\'Sei sicuro di voler eliminare definitivamente questa recensione?\');" style="margin:0;">';
                wp_nonce_field( 'cv_delete_review', 'cv_delete_review_nonce' );
                echo '<input type="hidden" name="order_id" value="' . esc_attr($rec['order_id']) . '">';
                echo '<button type="submit" class="button" style="color:#d63638; border-color:#d63638; padding: 2px 8px; min-height: 0; line-height: 1.5;" title="Elimina Recensione">❌</button>';
                echo '</form>';
                echo '</td>';
                
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<div class="notice notice-info"><p>Nessuna recensione ricevuta per questo evento al momento.</p></div>';
        }
    }
    echo '</div>';
}
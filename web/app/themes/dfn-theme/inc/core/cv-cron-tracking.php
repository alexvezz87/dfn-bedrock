<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * CRON JOB E TRACCIAMENTO IN BACKGROUND
 * ========================================================================
 */

// 1. REGISTRAZIONE E GESTIONE DEL WP CRON EVENT per l'annullamento ordini
add_action( 'init', 'cv_registra_cron_spazzino' );
/**
 * Registra l'evento cron orario se non è già programmato.
 *
 * @return void
 */
function cv_registra_cron_spazzino(): void {
    if ( ! wp_next_scheduled( 'cv_cron_annulla_ordini_scaduti' ) ) {
        wp_schedule_event( time(), 'hourly', 'cv_cron_annulla_ordini_scaduti' );
    }
}

add_action( 'switch_theme', 'cv_rimuovi_cron_spazzino' );
/**
 * Rimuove il cron event alla disattivazione del tema per evitare orfani nel database.
 *
 * @return void
 */
function cv_rimuovi_cron_spazzino(): void {
    $timestamp = wp_next_scheduled( 'cv_cron_annulla_ordini_scaduti' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'cv_cron_annulla_ordini_scaduti' );
    }
}

// Colleghiamo l'azione del cron alla funzione di pulizia
add_action( 'cv_cron_annulla_ordini_scaduti', 'cv_forza_annullamento_ordini_scaduti' );

/**
 * Annulla gli ordini in attesa (pending) da più di 24 ore.
 * Processa gli ordini a lotti limitati per evitare timeout di PHP (Maximum execution time exceeded).
 *
 * @return void
 */
function cv_forza_annullamento_ordini_scaduti(): void {
    // Lock di sicurezza per evitare esecuzioni concorrenti
    if ( get_transient( 'cv_spazzino_ordini_lock' ) ) {
        return;
    }
    set_transient( 'cv_spazzino_ordini_lock', 1, 10 * MINUTE_IN_SECONDS );

    $limite_tempo = time() - ( 24 * 60 * 60 ); 
    
    // Soluzione Senior: limitiamo a un massimo di 10 ordini per volta per prevenire il sovraccarico e i timeout SMTP
    $ordini_scaduti = wc_get_orders( array(
        'status'       => 'pending',
        'limit'        => 10,
        'date_created' => '<' . $limite_tempo,
    ) );

    if ( ! empty( $ordini_scaduti ) ) {
        foreach ( $ordini_scaduti as $order ) {
            $order->update_status( 'cancelled', '⏰ Ordine annullato automaticamente dal sistema (scadute le 24 ore in attesa).' );
        }
    }
}

// 2. EMAIL AL CLIENTE QUANDO LA PRENOTAZIONE SCADE E RIPRISTINO SCORTE
add_action( 'woocommerce_order_status_pending_to_cancelled', 'cv_email_cliente_ordine_scaduto', 10, 2 );
function cv_email_cliente_ordine_scaduto( $order_id, $order ) {
    if ( ! $order ) return;

    // Se l'ordine è stato annullato manualmente dall'utente, non inviare l'email di scadenza automatica
    if ( 'yes' === $order->get_meta( '_dfn_cancelled_manually' ) ) {
        return;
    }

    remove_action( 'woocommerce_order_status_pending_to_cancelled', 'wc_maybe_increase_stock_levels' );

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && $product->managing_stock() ) {
            $qty = $item->get_quantity();
            $vecchio_stock = $product->get_stock_quantity();
            $nuovo_stock = wc_update_product_stock( $product, $qty, 'increase' );
            $nota = sprintf( '🎟️ Livelli del magazzino ripristinati: %s (%d&rarr;%d) dal sistema CandleVibes.', $product->get_name(), $vecchio_stock, $nuovo_stock );
            $order->add_order_note( $nota );
        }
    }

    $email_cliente = $order->get_billing_email();
    if ( empty( $email_cliente ) ) return;

    $nomi_eventi = array();
    foreach ( $order->get_items() as $item ) { $nomi_eventi[] = $item->get_name(); }
    $titolo_evento = implode( ' + ', $nomi_eventi );

    $mailer  = WC()->mailer();
    $subject = 'Prenotazione Scaduta - ' . $titolo_evento;
    
    $messaggio  = '<p>Ciao <strong>' . esc_html( $order->get_billing_first_name() ) . '</strong>,</p>';
    $messaggio .= '<p>Ti informiamo che la tua prenotazione temporanea (Ordine #' . $order_id . ') per <strong>' . esc_html( $titolo_evento ) . '</strong> è stata <strong>annullata in automatico</strong>.</p>';
    $messaggio .= '<p>Come indicato in precedenza, i posti venivano riservati per un massimo di 24 ore in attesa del saldo. Non avendo ricevuto il pagamento entro i termini prestabiliti, i biglietti sono tornati disponibili per l\'acquisto al pubblico.</p>';
    $messaggio .= '<p>Se desideri ancora partecipare al nostro evento, ti invitiamo a effettuare una nuova prenotazione sul nostro sito, compatibilmente con i posti attualmente rimasti liberi.</p>';
    $messaggio .= '<p>A presto!</p>';

    $email_html = $mailer->wrap_message( 'Prenotazione Scaduta', $messaggio );
    $mailer->send( $email_cliente, $subject, $email_html, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

// 3. SENSORE DI TRACCIAMENTO CLICK SUL LINK DI PAGAMENTO
add_action( 'template_redirect', 'cv_track_payment_page_visit' );
function cv_track_payment_page_visit() {
    if ( is_wc_endpoint_url( 'order-pay' ) ) {
        global $wp;
        $order_id = absint( $wp->query_vars['order-pay'] );
        if ( $order_id && isset( $_GET['cv_track_pay'] ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $lock_key = 'cv_tracked_pay_' . $order_id;
                if ( ! get_transient( $lock_key ) ) {
                    $order->add_order_note( '👀 <strong>TRACCIAMENTO:</strong> Il cliente ha aperto la mail e ha cliccato sul link di pagamento.' );
                    set_transient( $lock_key, 1, 12 * HOUR_IN_SECONDS );
                }
            }
        }
    }
}
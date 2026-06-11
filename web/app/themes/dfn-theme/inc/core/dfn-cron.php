<?php
/**
 * DFN Booking System 2.0 — Background Cron Jobs
 *
 * Gestisce i processi in background periodici:
 * 1. Cancellazione ordini "pending" scaduti in base al timeout configurato per evento (auto_cancel_hours).
 * 2. Invio promemoria pre-evento automatico (24 ore prima dell'inizio dello slot).
 * 3. Gestore della lista d'attesa (waitlist) con scadenza TTL (2 ore) e scorrimento FIFO automatico.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Registrazione degli eventi cron orari su WordPress
add_action( 'init', 'dfn_registra_cron_jobs' );
add_action( 'switch_theme', 'dfn_rimuovi_cron_jobs' );

/**
 * Registra il cron job orario per le attività di manutenzione in background.
 */
function dfn_registra_cron_jobs(): void {
    if ( ! wp_next_scheduled( 'dfn_cron_hourly_maintenance' ) ) {
        wp_schedule_event( time(), 'hourly', 'dfn_cron_hourly_maintenance' );
    }
}

/**
 * Rimuove il cron job alla disattivazione del tema per evitare orfani nel DB.
 */
function dfn_rimuovi_cron_jobs(): void {
    $timestamp = wp_next_scheduled( 'dfn_cron_hourly_maintenance' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'dfn_cron_hourly_maintenance' );
    }
}

// Collega l'azione di manutenzione oraria del cron
add_action( 'dfn_cron_hourly_maintenance', 'dfn_run_hourly_maintenance' );

/**
 * Funzione principale che esegue tutte le operazioni orarie.
 */
function dfn_run_hourly_maintenance(): void {
    dfn_cron_annulla_ordini_scaduti();
    dfn_cron_invia_promemoria_24h();
    dfn_cron_gestisci_scadenza_waitlist();
}

/**
 * 1. ANNULLAMENTO ORDINI PENDING SCADUTI (timeout configurabile per evento)
 *
 * Consulta il campo auto_cancel_hours dell'evento associato a ciascun ordine:
 * - 0 = nessun annullamento automatico (ideale per pagamento in loco)
 * - N = annulla dopo N ore dalla creazione dell'ordine
 * Ordini senza booking DFN associato vengono annullati con il fallback di 24 ore.
 */
function dfn_cron_annulla_ordini_scaduti(): void {
    if ( get_transient( 'dfn_spazzino_ordini_lock' ) ) {
        return;
    }
    set_transient( 'dfn_spazzino_ordini_lock', 1, 10 * MINUTE_IN_SECONDS );

    // Recupera ordini in stato pending (senza filtro tempo fisso)
    $args = array(
        'status' => 'pending',
        'limit'  => 30,
    );
    $orders = wc_get_orders( $args );

    if ( empty( $orders ) ) {
        return;
    }

    foreach ( $orders as $order ) {
        $order_id = $order->get_id();

        // Trova il booking e l'evento associato all'ordine
        $booking = dfn_db_get_booking_by_order( $order_id );
        if ( ! $booking ) {
            // Ordine senza booking DFN: applica il vecchio comportamento (24h)
            $created_ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
            if ( $created_ts > 0 && time() > ( $created_ts + 24 * HOUR_IN_SECONDS ) ) {
                $order->update_status( 'cancelled', __( '⏰ Ordine annullato automaticamente: scaduto il termine di 24 ore per il pagamento online.', 'dfn-theme' ) );
            }
            continue;
        }

        $event = dfn_db_get_event( $booking->event_id );
        if ( ! $event ) {
            continue;
        }

        // Leggi il timeout configurato sull'evento (default 24 per retrocompatibilità)
        $auto_cancel_hours = isset( $event->auto_cancel_hours ) ? (int) $event->auto_cancel_hours : 24;

        // Se 0 → nessun annullamento automatico, skip
        if ( $auto_cancel_hours === 0 ) {
            continue;
        }

        // Controlla se l'ordine ha superato il timeout configurato
        $created_ts = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
        if ( $created_ts <= 0 ) {
            continue;
        }

        $limite = $created_ts + ( $auto_cancel_hours * HOUR_IN_SECONDS );

        if ( time() < $limite ) {
            continue; // Non ancora scaduto
        }

        // Annulla l'ordine con messaggio che include le ore configurate
        $order->update_status( 'cancelled', sprintf(
            /* translators: %d: number of hours */
            __( '⏰ Ordine annullato automaticamente: superato il termine di %d ore per il completamento del pagamento.', 'dfn-theme' ),
            $auto_cancel_hours
        ) );
    }
}

/**
 * Gestore dell'email di notifica cancellazione ordine online e ripristino stock.
 * Hooked all'azione nativa di WooCommerce.
 */
add_action( 'woocommerce_order_status_cancelled', 'dfn_email_cliente_ordine_scaduto', 10, 2 );
function dfn_email_cliente_ordine_scaduto( $order_id, $order ) {
    if ( ! $order ) {
        return;
    }

    // Evita il raddoppio del ripristino stock di WooCommerce
    remove_action( 'woocommerce_order_status_pending_to_cancelled', 'wc_maybe_increase_stock_levels' );
    remove_action( 'woocommerce_order_status_cancelled', 'wc_maybe_increase_stock_levels' );

    // Se l'ordine era con saldo "In Loco" e viene cancellato,
    // o se viene annullato un ordine online scaduto, ripristiniamo le scorte
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( $product && $product->managing_stock() ) {
            $qty = $item->get_quantity();
            $vecchio_stock = $product->get_stock_quantity();
            $nuovo_stock = wc_update_product_stock( $product, $qty, 'increase' );
            $nota = sprintf( '🎟️ Magazzino ripristinato dal sistema: %s (%d &rarr; %d).', $product->get_name(), $vecchio_stock, $nuovo_stock );
            $order->add_order_note( $nota );
        }
    }

    // Se esiste un booking per questo ordine, gestiamo l'annullamento della prenotazione e il rilascio della capienza
    $booking = dfn_db_get_booking_by_order( $order_id );
    if ( $booking ) {
        // Procedi solo se la prenotazione non è già stata annullata (es. da cancellazione autonoma visitatore)
        if ( $booking->status !== 'cancelled' ) {
            global $wpdb;
            $table_slots = $wpdb->prefix . 'dfn_event_slots';
            $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';

            $wpdb->query( 'START TRANSACTION' );

            // 1. Rilascia la capienza negli slot orari associati
            $assocs = $wpdb->get_results( $wpdb->prepare(
                "SELECT slot_id, persons FROM {$table_booking_slots} WHERE booking_id = %d",
                $booking->id
            ) );

            foreach ( $assocs as $assoc ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$table_slots} SET booked_count = GREATEST(0, CAST(booked_count AS SIGNED) - %d) WHERE id = %d",
                    intval( $assoc->persons ),
                    intval( $assoc->slot_id )
                ) );
            }

            // 2. Aggiorna lo stato in 'cancelled' nella tabella prenotazioni
            $wpdb->update(
                $wpdb->prefix . 'dfn_bookings',
                array( 'status' => 'cancelled' ),
                array( 'id' => $booking->id ),
                array( '%s' ),
                array( '%d' )
            );

            $wpdb->query( 'COMMIT' );

            // 3. Invia email di cancellazione centralizzata
            dfn_send_booking_cancellation( $booking->id );
        }
        return;
    }

    // Se è un normale ordine online scaduto, invia l'email di notifica scadenza online
    $email_cliente = $order->get_billing_email();
    if ( empty( $email_cliente ) ) {
        return;
    }

    $nomi_eventi = array();
    foreach ( $order->get_items() as $item ) {
        $nomi_eventi[] = $item->get_name();
    }
    $titolo_evento = implode( ' + ', $nomi_eventi );

    $subject = 'Prenotazione Temporanea Scaduta - ' . $titolo_evento;

    $messaggio  = '<p>Ciao <strong>' . esc_html( $order->get_billing_first_name() ) . '</strong>,</p>';
    $messaggio .= '<p>Ti informiamo che la tua prenotazione temporanea (Ordine #' . $order_id . ') per l\'evento <strong>' . esc_html( $titolo_evento ) . '</strong> è stata <strong>annullata automaticamente</strong>.</p>';
    $messaggio .= '<p>Come indicato al checkout, i posti venivano riservati per un massimo di 24 ore in attesa del saldo online. Non avendo completato la transazione nei termini, i biglietti sono tornati disponibili per il pubblico.</p>';
    $messaggio .= '<p>Se desideri ancora partecipare, puoi effettuare una nuova prenotazione sul nostro portale, verificando la disponibilità di posti rimasti.</p>';
    $messaggio .= '<p>A presto!</p>';

    dfn_send_notification_email( $email_cliente, $subject, 'Prenotazione Scaduta', $messaggio );
}

/**
 * 2. PROMEMORIA PRE-EVENTO AUTOMATICO (24 ore prima dell'inizio dello slot)
 */
function dfn_cron_invia_promemoria_24h(): void {
    global $wpdb;

    if ( get_transient( 'dfn_reminder_cron_lock' ) ) {
        return;
    }
    set_transient( 'dfn_reminder_cron_lock', 1, 15 * MINUTE_IN_SECONDS );

    // Trova le prenotazioni confermate per eventi o slot che si tengono nelle prossime 24-36 ore,
    // e che non hanno ancora ricevuto il promemoria
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $table_slots    = $wpdb->prefix . 'dfn_event_slots';
    $table_bs       = $wpdb->prefix . 'dfn_booking_slots';
    $table_events   = $wpdb->prefix . 'dfn_events';

    $now = current_time( 'mysql' );
    $target_time_start = date( 'Y-m-d H:i:s', time() + 12 * HOUR_IN_SECONDS );
    $target_time_end   = date( 'Y-m-d H:i:s', time() + 36 * HOUR_IN_SECONDS );

    // 1. Trova le prenotazioni con slot orari definiti nelle prossime 24-36 ore
    $query_slots = $wpdb->prepare(
        "SELECT DISTINCT b.id FROM {$table_bookings} b
         JOIN {$table_bs} bs ON b.id = bs.booking_id
         JOIN {$table_slots} s ON bs.slot_id = s.id
         WHERE b.status = 'confirmed'
           AND CONCAT(s.slot_date, ' ', s.slot_time_start) BETWEEN %s AND %s
           AND b.id NOT IN (
               SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dfn_reminder_sent' AND meta_value = 'yes'
           )
         LIMIT 20",
        $target_time_start,
        $target_time_end
    );
    $bookings_slots = $wpdb->get_col( $query_slots );

    // 2. Trova le prenotazioni a ingresso libero (free-flow) senza slot per eventi nelle prossime 24-36 ore
    $query_free = $wpdb->prepare(
        "SELECT DISTINCT b.id FROM {$table_bookings} b
         JOIN {$table_events} e ON b.event_id = e.id
         WHERE b.status = 'confirmed'
           AND e.access_type = 'free_flow'
           AND CONCAT(e.event_date_start, ' ', e.event_time_start) BETWEEN %s AND %s
           AND b.id NOT IN (
               SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dfn_reminder_sent' AND meta_value = 'yes'
           )
         LIMIT 20",
        $target_time_start,
        $target_time_end
    );
    $bookings_free = $wpdb->get_col( $query_free );

    $all_bookings = array_unique( array_merge( $bookings_slots, $bookings_free ) );

    if ( empty( $all_bookings ) ) {
        return;
    }

    foreach ( $all_bookings as $booking_id ) {
        $booking_id = (int) $booking_id;
        
        // Invia l'email di promemoria 24 ore prima
        $sent = dfn_send_booking_24h_reminder( $booking_id );

        if ( $sent ) {
            // Segna il promemoria come inviato utilizzando i postmeta dell'ordine WooCommerce legato alla prenotazione
            $booking = $wpdb->get_row( $wpdb->prepare( "SELECT order_id FROM {$table_bookings} WHERE id = %d", $booking_id ) );
            if ( $booking ) {
                update_post_meta( $booking->order_id, '_dfn_reminder_sent', 'yes' );
            }
        }
    }
}

/**
 * 3. GESTORE SCADENZA WAITLIST (TTL 2 ORE) E FIFO AUTOMATICO
 */
function dfn_cron_gestisci_scadenza_waitlist(): void {
    global $wpdb;

    if ( get_transient( 'dfn_waitlist_cron_lock' ) ) {
        return;
    }
    set_transient( 'dfn_waitlist_cron_lock', 1, 5 * MINUTE_IN_SECONDS );

    $table_waitlist = $wpdb->prefix . 'dfn_waitlist';
    $now = current_time( 'mysql' );

    // 1. Trova tutte le prenotazioni in waitlist notificate il cui TTL è scaduto
    $expired_entries = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table_waitlist} WHERE status = 'notified' AND ttl_expires_at < %s",
        $now
    ) );

    if ( empty( $expired_entries ) ) {
        return;
    }

    foreach ( $expired_entries as $entry ) {
        // Aggiorna lo stato della voce scaduta a 'expired'
        $wpdb->update(
            $table_waitlist,
            array( 'status' => 'expired' ),
            array( 'id' => $entry->id ),
            array( '%s' ),
            array( '%d' )
        );

        // Se la voce era legata a uno slot specifico, sblocca la capacità temporaneamente occupata,
        // o procedi a notificare il prossimo in coda FIFO per lo stesso evento ed eventuale slot
        $slot_id_filter = $entry->slot_id ? $wpdb->prepare( "AND slot_id = %d", $entry->slot_id ) : "AND slot_id IS NULL";
        
        $next_in_queue = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_waitlist} 
             WHERE event_id = %d 
               {$slot_id_filter} 
               AND status = 'waiting' 
             ORDER BY created_at ASC 
             LIMIT 1",
            $entry->event_id
        ) );

        if ( $next_in_queue ) {
            $ttl_limit = date( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ); // 2 ore di priorità

            $wpdb->update(
                $table_waitlist,
                array(
                    'status'         => 'notified',
                    'notified_at'    => $now,
                    'ttl_expires_at' => $ttl_limit,
                ),
                array( 'id' => $next_in_queue->id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            // Invia la notifica via email per promuovere l'utente
            dfn_send_waitlist_notification( $next_in_queue->id );
        }
    }
}

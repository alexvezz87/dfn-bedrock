<?php
/**
 * DFN Booking System 2.0 — Slot Retrieval API
 *
 * Endpoint AJAX per recuperare le date degli eventi e la disponibilità oraria dei turni.
 * Supporta l'allocazione automatica e la self-selection del visitatore.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Registrazione degli endpoint AJAX per utenti pubblici e registrati
add_action( 'wp_ajax_dfn_get_event_dates', 'dfn_ajax_get_event_dates' );
add_action( 'wp_ajax_nopriv_dfn_get_event_dates', 'dfn_ajax_get_event_dates' );

add_action( 'wp_ajax_dfn_get_available_slots', 'dfn_ajax_get_available_slots' );
add_action( 'wp_ajax_nopriv_dfn_get_available_slots', 'dfn_ajax_get_available_slots' );

add_action( 'wp_ajax_dfn_get_event_slots', 'dfn_ajax_get_event_slots' );
add_action( 'wp_ajax_nopriv_dfn_get_event_slots', 'dfn_ajax_get_event_slots' );

/**
 * Recupera le date attive per un determinato prodotto/evento WooCommerce.
 * Ritorna un array JSON di date (formato Y-m-d) per popolare il calendario.
 */
function dfn_ajax_get_event_dates() {
    $product_id = isset( $_GET['product_id'] ) ? intval( $_GET['product_id'] ) : 0;
    if ( $product_id <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'ID prodotto non valido.', 'dfn-theme' ) ) );
    }

    $event = dfn_db_get_event_by_product( $product_id );
    if ( ! $event ) {
        wp_send_json_error( array( 'message' => __( 'Evento non associato a questo prodotto.', 'dfn-theme' ) ) );
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';

    // Se a fasce orarie, preleviamo le date in cui ci sono slot con disponibilità
    if ( 'time_slots' === $event->access_type ) {
        $dates = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT slot_date FROM {$table_slots} 
             WHERE event_id = %d 
               AND slot_date >= CURDATE()
               AND is_locked = 0 
               AND booked_count < (capacity + bonus_capacity)
             ORDER BY slot_date ASC",
            $event->id
        ) );
    } else {
        // Se flusso libero, ritorna l'intervallo di date dell'evento stesso
        $dates = array();
        $start = strtotime( $event->event_date_start );
        $end   = $event->event_date_end ? strtotime( $event->event_date_end ) : $start;

        for ( $d = $start; $d <= $end; $d += DAY_IN_SECONDS ) {
            $date_str = date( 'Y-m-d', $d );
            if ( strtotime( $date_str ) >= strtotime( date( 'Y-m-d' ) ) ) {
                $dates[] = $date_str;
            }
        }
    }

    wp_send_json_success( array(
        'dates'           => $dates,
        'access_type'     => $event->access_type,
        'allocation_mode' => $event->allocation_mode,
        'payment_mode'    => $event->payment_mode
    ) );
}

/**
 * Recupera l'elenco dei turni/slot disponibili per un evento in una data specifica.
 * Calcola la capacità rimanente e i prezzi per popolarne il widget frontend.
 */
function dfn_ajax_get_available_slots() {
    $product_id = isset( $_GET['product_id'] ) ? intval( $_GET['product_id'] ) : 0;
    $date       = isset( $_GET['date'] ) ? sanitize_text_field( $_GET['date'] ) : '';

    if ( $product_id <= 0 || empty( $date ) ) {
        wp_send_json_error( array( 'message' => __( 'Parametri incompleti.', 'dfn-theme' ) ) );
    }

    $event = dfn_db_get_event_by_product( $product_id );
    if ( ! $event ) {
        wp_send_json_error( array( 'message' => __( 'Evento non trovato.', 'dfn-theme' ) ) );
    }

    // Se l'evento è a ingresso libero (free-flow), non ha slot, calcoliamo la cap globale rimanente
    if ( 'free_flow' === $event->access_type ) {
        global $wpdb;
        $booked_global = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings 
             WHERE event_id = %d AND status != 'cancelled'",
            $event->id
        ) ) ?: 0;

        $available = max( 0, intval( $event->total_capacity ) - intval( $booked_global ) );

        wp_send_json_success( array(
            'access_type'    => 'free_flow',
            'available'      => $available,
            'price_standard' => floatval( $event->price_standard ),
            'price_fai'      => floatval( $event->price_fai )
        ) );
    }

    // Altrimenti, preleva gli slot dal DB custom
    $slots = dfn_db_get_available_slots( $event->id, $date );
    $formatted_slots = array();

    foreach ( $slots as $slot ) {
        // Calcola disponibilità effettiva per gli utenti (escludendo il bonus se l'allocazione è pubblica)
        // Il bonus è riservato per overbooking / botteghino volontari
        $available_seats = max( 0, intval( $slot->capacity ) - intval( $slot->booked_count ) );

        $formatted_slots[] = array(
            'slot_id'         => $slot->id,
            'time_start'      => date( 'H:i', strtotime( $slot->slot_time_start ) ),
            'time_end'        => date( 'H:i', strtotime( $slot->slot_time_end ) ),
            'available'       => $available_seats,
            'available_bonus' => max( 0, intval( $slot->capacity + $slot->bonus_capacity ) - intval( $slot->booked_count ) ),
            'is_full'         => ( $available_seats <= 0 )
        );
    }

    wp_send_json_success( array(
        'access_type'     => 'time_slots',
        'allocation_mode' => $event->allocation_mode,
        'slots'           => $formatted_slots,
        'price_standard'  => floatval( $event->price_standard ),
        'price_fai'       => floatval( $event->price_fai )
    ) );
}

/**
 * Gestisce la richiesta AJAX per recuperare tutti gli slot orari attivi di un evento
 * in una specifica data, includendo la capacità standard e bonus.
 */
function dfn_ajax_get_event_slots() {
    check_ajax_referer( 'dfn_booking_nonce', 'nonce' );

    $event_id = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
    $date     = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';

    if ( $event_id <= 0 || empty( $date ) ) {
        wp_send_json_error( array( 'message' => __( 'Parametri non validi.', 'dfn-theme' ) ) );
    }

    $event = dfn_db_get_event( $event_id );
    if ( ! $event ) {
        wp_send_json_error( array( 'message' => __( 'Evento non trovato.', 'dfn-theme' ) ) );
    }

    $slots = dfn_db_get_available_slots( $event_id, $date );
    $formatted_slots = array();

    foreach ( $slots as $slot ) {
        $formatted_slots[] = array(
            'slot_id'  => intval( $slot->id ),
            'time'     => date( 'H:i', strtotime( $slot->slot_time_start ) ),
            'capacity' => intval( $slot->capacity ),
            'bonus'    => intval( $slot->bonus_capacity ),
            'booked'   => intval( $slot->booked_count ),
        );
    }

    wp_send_json_success( $formatted_slots );
}

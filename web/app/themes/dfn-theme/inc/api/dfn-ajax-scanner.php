<?php
/**
 * DFN Booking System 2.0 — Live Scanner API Router
 *
 * Endpoint AJAX dedicati alla validazione e al consolidamento d'incasso in tempo reale
 * durante la scansione all'ingresso degli eventi FAI.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_dfn_process_scan', 'dfn_process_scan_ajax_handler' );
add_action( 'wp_ajax_dfn_consolidate_in_loco_payment', 'dfn_consolidate_in_loco_payment_ajax_handler' );

/**
 * Processa la scansione del codice QR convalidando l'accesso o chiedendo l'incasso.
 *
 * @return void
 */
function dfn_process_scan_ajax_handler(): void {
    check_ajax_referer( 'dfn_scanner_nonce', 'security' );

    if ( ! current_user_can( 'dfn_use_scanner' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Non hai i permessi necessari per usare lo scanner.', 'dfn-theme' ) ) );
    }

    $qr_token = isset( $_POST['qr_token'] ) ? sanitize_text_field( $_POST['qr_token'] ) : '';
    if ( empty( $qr_token ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Token QR non fornito.', 'dfn-theme' ) ) );
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // Cerca il booking nel DB
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE qr_token = %s LIMIT 1",
        $qr_token
    ) );

    if ( ! $booking ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Codice QR non valido o prenotazione inesistente.', 'dfn-theme' ) ) );
    }

    $order = wc_get_order( $booking->order_id );
    if ( ! $order ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Ordine WooCommerce correlato non trovato.', 'dfn-theme' ) ) );
    }

    // Se il biglietto è già stato scansionato (utente entrato)
    if ( $booking->status === 'checked_in' ) {
        $validated_by = 'Staff';
        if ( $booking->checked_in_by ) {
            $user_info = get_userdata( $booking->checked_in_by );
            if ( $user_info ) {
                $validated_by = $user_info->display_name;
            }
        }

        wp_send_json_success( array(
            'status'         => 'checked_in',
            'customer_name'  => $booking->customer_name,
            'total_persons'  => $booking->total_persons,
            'checked_in_at'  => date_i18n( 'd/m/Y - H:i:s', strtotime( $booking->checked_in_at ) ),
            'checked_in_by'  => $validated_by,
        ) );
    }

    // Recupera informazioni evento
    $event_title = get_the_title( $booking->event_id ) ?: esc_html__( 'Evento FAI', 'dfn-theme' );

    // Se l'ordine è con pagamento all'ingresso (In Loco) ed è ancora pendente
    if ( $order->get_payment_method() === 'dfn_in_loco' && $order->has_status( 'pending' ) ) {
        // Cerca i dati del listino dell'evento per mostrare il breakdown
        $event = dfn_db_get_event( $booking->event_id );
        $price_standard = $event ? floatval( $event->price_standard ) : 0.00;
        $price_fai      = $event ? floatval( $event->price_fai ) : 0.00;

        wp_send_json_success( array(
            'payment_required'       => true,
            'customer_name'          => $booking->customer_name,
            'total_persons'          => $booking->total_persons,
            'persons_standard'       => $booking->persons_standard,
            'persons_fai'            => $booking->persons_fai,
            'event_title'            => $event_title,
            'price_standard_formatted'=> wc_price( $price_standard ),
            'price_fai_formatted'    => wc_price( $price_fai ),
            'amount_due_formatted'   => wc_price( floatval( $booking->amount_due ) ),
        ) );
    }

    // Se l'ordine è pagato online (processing / completed) o l'ingresso è gratuito (saldo zero)
    if ( $order->has_status( array( 'processing', 'completed' ) ) || floatval( $order->get_total() ) === 0.00 ) {
        // Aggiorna il record di ingresso
        $wpdb->update(
            $table_bookings,
            array(
                'status'        => 'checked_in',
                'checked_in_at' => current_time( 'mysql' ),
                'checked_in_by' => get_current_user_id(),
            ),
            array( 'id' => $booking->id ),
            array( '%s', '%s', '%d' ),
            array( '%d' )
        );

        wp_send_json_success( array(
            'status'         => 'success',
            'customer_name'  => $booking->customer_name,
            'total_persons'  => $booking->total_persons,
            'event_title'    => $event_title,
            'order_id'       => $booking->order_id,
        ) );
    }

    wp_send_json_error( array( 'message' => esc_html__( 'Impossibile convalidare l\'accesso. L\'ordine risulta annullato o non valido.', 'dfn-theme' ) ) );
}

/**
 * Consolida il saldo fisico registrato al banchetto ed effettua l'ingresso del gruppo.
 *
 * @return void
 */
function dfn_consolidate_in_loco_payment_ajax_handler(): void {
    check_ajax_referer( 'dfn_scanner_nonce', 'security' );

    if ( ! current_user_can( 'dfn_checkin_and_collect' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Non hai le autorizzazioni per incassare i contributi.', 'dfn-theme' ) ) );
    }

    $qr_token = isset( $_POST['qr_token'] ) ? sanitize_text_field( $_POST['qr_token'] ) : '';
    $method   = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : 'cash'; // cash o pos

    if ( empty( $qr_token ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Token QR non valido.', 'dfn-theme' ) ) );
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // Cerca il booking
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE qr_token = %s LIMIT 1",
        $qr_token
    ) );

    if ( ! $booking ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Prenotazione non trovata.', 'dfn-theme' ) ) );
    }

    $order = wc_get_order( $booking->order_id );
    if ( ! $order ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Ordine WooCommerce non trovato.', 'dfn-theme' ) ) );
    }

    // Inizia la transazione sicura
    $wpdb->query( 'START TRANSACTION' );

    try {
        // 1. Aggiorna lo stato dell'ordine WooCommerce a "completed"
        $method_title = ( $method === 'pos' ) ? __( 'Contributo In Loco (POS/Carta)', 'dfn-theme' ) : __( 'Contributo In Loco (Contanti)', 'dfn-theme' );
        $order->update_meta_data( '_dfn_physical_payment_method', $method );
        $order->update_meta_data( '_dfn_collected_by', (string) get_current_user_id() );
        $order->update_meta_data( '_dfn_collected_at', current_time( 'mysql' ) );
        $order->update_status( 'completed', sprintf( __( 'Contributo riscosso in loco tramite %s.', 'dfn-theme' ), $method_title ) );
        $order->save();

        // 2. Aggiorna la prenotazione a checked_in e registra il metodo d'incasso
        $wpdb->update(
            $table_bookings,
            array(
                'status'         => 'checked_in',
                'payment_method' => ( $method === 'pos' ) ? 'in_loco_pos' : 'in_loco_cash',
                'amount_paid'    => $booking->amount_due,
                'checked_in_at'  => current_time( 'mysql' ),
                'checked_in_by'  => get_current_user_id(),
            ),
            array( 'id' => $booking->id ),
            array( '%s', '%s', '%f', '%s', '%d' ),
            array( '%d' )
        );

        $wpdb->query( 'COMMIT' );
    } catch ( \Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        wp_send_json_error( array( 'message' => esc_html__( 'Errore critico del database durante il consolidamento del pagamento.', 'dfn-theme' ) ) );
    }

    // Recupera informazioni aggiornate per la risposta dello scanner
    $validated_by = 'Staff';
    $user_info = get_userdata( get_current_user_id() );
    if ( $user_info ) {
        $validated_by = $user_info->display_name;
    }

    wp_send_json_success( array(
        'status'         => 'checked_in',
        'customer_name'  => $booking->customer_name,
        'total_persons'  => $booking->total_persons,
        'checked_in_at'  => date_i18n( 'd/m/Y - H:i:s' ),
        'checked_in_by'  => $validated_by,
    ) );
}

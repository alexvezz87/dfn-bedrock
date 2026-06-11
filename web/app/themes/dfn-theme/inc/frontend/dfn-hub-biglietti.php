<?php
/**
 * DFN Booking System 2.0 — Premium Monolithic Group Ticket Hub
 *
 * Sostituisce cv-hub-biglietti.php fornendo un unico QR Code di gruppo,
 * evitando le code e massimizzando l'efficienza d'ingresso.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Intercetta la richiesta dell'hub biglietti o del download
add_action( 'template_redirect', 'dfn_render_group_ticket_hub' );
add_action( 'template_redirect', 'dfn_handle_qr_download' );
add_action( 'template_redirect', 'dfn_handle_visitor_cancellation' );

/**
 * Gestisce il rendering della pagina dell'Hub Biglietti di Gruppo.
 */
function dfn_render_group_ticket_hub(): void {
    if ( ! isset( $_GET['dfn_hub'] ) || ! isset( $_GET['order_id'] ) || ! isset( $_GET['token'] ) ) {
        return;
    }

    $order_id = intval( $_GET['order_id'] );
    $token    = sanitize_text_field( $_GET['token'] );

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_die( esc_html__( 'Ordine non trovato.', 'dfn-theme' ), esc_html__( 'Errore', 'dfn-theme' ), 404 );
    }

    // Verifica token di sicurezza transazionale
    $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_dfn_hub', wp_salt( 'nonce' ) );
    if ( ! hash_equals( $expected_token, $token ) ) {
        wp_die( esc_html__( 'Link non valido o scaduto.', 'dfn-theme' ), esc_html__( 'Errore di sicurezza', 'dfn-theme' ), 403 );
    }

    // Recupera la prenotazione collegata a questo ordine
    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id
    ) );

    if ( ! $booking ) {
        wp_die( esc_html__( 'Nessuna prenotazione custom associata a questo ordine nel database.', 'dfn-theme' ) );
    }

    // Se l'ordine è annullato, rimborsato o non pagato (escluso in loco pending)
    $payment_method = $order->get_payment_method();
    $is_valid_status = $order->has_status( array( 'processing', 'completed' ) ) || ( $payment_method === 'dfn_in_loco' && $order->has_status( 'pending' ) );

    if ( ! $is_valid_status ) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . esc_html__( 'Ordine Non Valido', 'dfn-theme' ) . '</title>';
        echo '<style>body { font-family: sans-serif; background: #f3f7f4; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 80vh; } .card { background: #fff; padding: 40px; border-radius: 16px; text-align: center; max-width: 500px; box-shadow: 0 10px 30px rgba(0,75,35,0.05); }</style></head><body>';
        echo '<div class="card">';
        echo '<h1 style="color: #dc2626; margin-top: 0;">🚫 ' . esc_html__( 'Prenotazione Non Valida', 'dfn-theme' ) . '</h1>';
        echo '<p style="font-size: 16px; color: #64748b; line-height: 1.6;">' . esc_html__( 'I biglietti associati a questa prenotazione non sono disponibili. L\'ordine potrebbe essere stato annullato, scaduto o rimborsato.', 'dfn-theme' ) . '</p>';
        echo '<a href="' . esc_url( site_url() ) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #004b23; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold;">' . esc_html__( 'Torna alla Home', 'dfn-theme' ) . '</a>';
        echo '</div></body></html>';
        exit;
    }

    // Dati dell'evento
    $event_title = get_the_title( $booking->event_id ) ?: esc_html__( 'Evento FAI', 'dfn-theme' );
    $event       = dfn_db_get_event( $booking->event_id );
    $location    = $event ? $event->location : esc_html__( 'Bene FAI', 'dfn-theme' );
    $date_start  = $event ? date_i18n( 'd M Y', strtotime( $event->event_date_start ) ) : '';

    // Turno orario se applicabile
    $slot_info = '';
    if ( $event && 'time_slots' === $event->access_type ) {
        $slot_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT slot_id FROM {$wpdb->prefix}dfn_booking_slots WHERE booking_id = %d LIMIT 1",
            $booking->id
        ) );
        if ( $slot_id ) {
            $slot = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dfn_event_slots WHERE id = %d",
                $slot_id
            ) );
            if ( $slot ) {
                $slot_info = date( 'H:i', strtotime( $slot->slot_time_start ) );
            }
        }
    }

    // QR Code generation URL
    $qr_api_url   = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode( $booking->qr_token ) . '&margin=10';
    $download_url = site_url( '/?dfn_download_qr=1&order_id=' . $order_id . '&token=' . $token );
    $wa_text      = urlencode( sprintf( __( 'Ecco il biglietto di gruppo per %s. Mostra questo QR Code all\'ingresso: %s', 'dfn-theme' ), $event_title, site_url( '/?dfn_hub=1&order_id=' . $order_id . '&token=' . $token ) ) );

    // Enqueue degli stili dedicati
    wp_enqueue_style( 'dfn-visitor-dashboard-css', get_stylesheet_directory_uri() . '/assets/css/dfn-visitor-dashboard.css', array(), '2.0.0' );

    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php esc_html_e( 'I tuoi Biglietti — FAI Prenotazioni', 'dfn-theme' ); ?></title>
        <?php wp_head(); ?>
    </head>
    <body>
        <div class="dfn-hub-wrapper">
            <div class="dfn-hub-card">
                <div class="dfn-hub-header-decor"></div>
                
                <h1 class="dfn-hub-title"><?php esc_html_e( '🎟️ Il tuo Ingresso di Gruppo', 'dfn-theme' ); ?></h1>
                <p class="dfn-hub-subtitle"><?php printf( esc_html__( 'Ordine #%d — Gestione Ingressi Centralizzata', 'dfn-theme' ), $order_id ); ?></p>

                <?php if ( $booking->status === 'checked_in' ) : ?>
                    <span class="dfn-badge-validated"><?php esc_html_e( '✅ GRUPPO ENTRATO / UTILIZZATO', 'dfn-theme' ); ?></span>
                <?php else : ?>
                    <span class="dfn-badge-group"><?php printf( esc_html__( '🎫 Biglietto Attivo per %d Ingressi', 'dfn-theme' ), intval( $booking->total_persons ) ); ?></span>
                <?php endif; ?>

                <div class="dfn-hub-info-box">
                    <h3 class="dfn-hub-info-title"><?php echo esc_html( $event_title ); ?></h3>
                    <div class="dfn-hub-info-detail">📍 <strong><?php esc_html_e( 'Luogo:', 'dfn-theme' ); ?></strong> <?php echo esc_html( $location ); ?></div>
                    <div class="dfn-hub-info-detail">📅 <strong><?php esc_html_e( 'Data:', 'dfn-theme' ); ?></strong> <?php echo esc_html( $date_start ); ?></div>
                    <?php if ( ! empty( $slot_info ) ) : ?>
                        <div class="dfn-hub-info-detail">⏰ <strong><?php esc_html_e( 'Orario Turno:', 'dfn-theme' ); ?></strong> <?php echo esc_html( $slot_info ); ?></div>
                    <?php endif; ?>
                    <div class="dfn-hub-info-detail">👥 <strong><?php esc_html_e( 'Intestato a:', 'dfn-theme' ); ?></strong> <?php echo esc_html( $booking->customer_name ); ?></div>
                    <div class="dfn-hub-info-detail" style="margin-top: 10px; border-top: 1px dashed var(--dfn-gray-medium); padding-top: 10px; font-size: 13px;">
                        👥 Breakdown: <?php printf( esc_html__( '%d Standard + %d Soci FAI', 'dfn-theme' ), intval( $booking->persons_standard ), intval( $booking->persons_fai ) ); ?>
                    </div>
                </div>

                <?php if ( $booking->status !== 'checked_in' ) : ?>
                    <div class="dfn-hub-qr-container">
                        <img src="<?php echo esc_url( $qr_api_url ); ?>" class="dfn-hub-qr-image" alt="<?php esc_attr_e( 'Codice QR d\'Ingresso', 'dfn-theme' ); ?>" />
                    </div>
                <?php else : ?>
                    <div style="margin: 40px 0; font-size: 18px; color: #004b23; font-weight: bold;">
                        <?php esc_html_e( 'L\'accesso per questo gruppo è già stato convalidato dallo staff.', 'dfn-theme' ); ?>
                    </div>
                <?php endif; ?>

                <div class="dfn-hub-buttons dfn-no-print">
                    <?php if ( $booking->status !== 'checked_in' ) : ?>
                        <a href="https://wa.me/?text=<?php echo $wa_text; ?>" target="_blank" class="dfn-hub-btn dfn-hub-btn-wa">
                            💬 <?php esc_html_e( 'Condividi su WhatsApp', 'dfn-theme' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $download_url ); ?>" class="dfn-hub-btn dfn-hub-btn-save">
                            ⬇️ <?php esc_html_e( 'Salva QR come Immagine', 'dfn-theme' ); ?>
                        </a>
                    <?php endif; ?>
                    <button onclick="window.print();" class="dfn-hub-btn dfn-hub-btn-print">
                        🖨️ <?php esc_html_e( 'Stampa Biglietto Cartaceo', 'dfn-theme' ); ?>
                    </button>
                </div>
            </div>
            
            <div class="dfn-no-print" style="margin-top: 20px;">
                <a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" style="color: var(--dfn-text-muted); text-decoration: none; font-weight: 600; font-size: 14px;">
                    ← <?php esc_html_e( 'Torna al tuo Botteghino', 'dfn-theme' ); ?>
                </a>
            </div>
        </div>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Gestisce lo scaricamento forzato in formato PNG dell'immagine del QR code.
 */
function dfn_handle_qr_download(): void {
    if ( ! isset( $_GET['dfn_download_qr'] ) || ! isset( $_GET['order_id'] ) || ! isset( $_GET['token'] ) ) {
        return;
    }

    $order_id = intval( $_GET['order_id'] );
    $token    = sanitize_text_field( $_GET['token'] );

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_dfn_hub', wp_salt( 'nonce' ) );
    if ( ! hash_equals( $expected_token, $token ) ) {
        return;
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id
    ) );

    if ( ! $booking || empty( $booking->qr_token ) ) {
        return;
    }

    $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' . urlencode( $booking->qr_token ) . '&margin=20';
    $response   = wp_remote_get( $qr_api_url, array( 'timeout' => 12 ) );

    if ( is_wp_error( $response ) ) {
        wp_die( esc_html__( 'Impossibile scaricare l\'immagine in questo momento.', 'dfn-theme' ) );
    }

    $image_data   = wp_remote_retrieve_body( $response );
    $content_type = wp_remote_retrieve_header( $response, 'content-type' );

    if ( empty( $image_data ) ) {
        return;
    }

    $filename = 'Ingresso-Gruppo-Ordine-' . $order_id . '.png';
    header( 'Content-Description: File Transfer' );
    header( 'Content-Type: ' . $content_type );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Expires: 0' );
    header( 'Cache-Control: must-revalidate' );
    header( 'Pragma: public' );
    header( 'Content-Length: ' . strlen( $image_data ) );
    echo $image_data;
    exit;
}

/**
 * Gestisce l'annullamento autonomo della prenotazione da parte del visitatore.
 */
function dfn_handle_visitor_cancellation(): void {
    if ( ! isset( $_GET['dfn_cancel_booking'] ) || ! isset( $_GET['order_id'] ) || ! isset( $_GET['token'] ) ) {
        return;
    }

    $order_id = intval( $_GET['order_id'] );
    $token    = sanitize_text_field( $_GET['token'] );

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        wp_die( esc_html__( 'Ordine non trovato.', 'dfn-theme' ), esc_html__( 'Errore', 'dfn-theme' ), 404 );
    }

    // Verifica token di sicurezza per evitare annullamenti non autorizzati
    $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_dfn_cancel', wp_salt( 'nonce' ) );
    if ( ! hash_equals( $expected_token, $token ) ) {
        wp_die( esc_html__( 'Link di cancellazione non valido o scaduto.', 'dfn-theme' ), esc_html__( 'Errore di sicurezza', 'dfn-theme' ), 403 );
    }

    // Se la prenotazione è già annullata
    if ( $order->has_status( 'cancelled' ) ) {
        wp_die( esc_html__( 'Questa prenotazione è già stata annullata in precedenza.', 'dfn-theme' ), esc_html__( 'Prenotazione Annullata', 'dfn-theme' ) );
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id
    ) );

    if ( ! $booking ) {
        wp_die( esc_html__( 'Nessuna prenotazione custom associata a questo ordine nel database.', 'dfn-theme' ) );
    }

    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';

    $wpdb->query( 'START TRANSACTION' );

    // 1. Decrementa booked_count sugli slot associati
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

    // 2. Cambia lo stato in 'cancelled' nella tabella prenotazioni
    $wpdb->update(
        $table_bookings,
        array( 'status' => 'cancelled' ),
        array( 'id' => $booking->id ),
        array( '%s' ),
        array( '%d' )
    );

    $wpdb->query( 'COMMIT' );

    // 3. Aggiorna lo stato dell'ordine WooCommerce (questo farà partire i ripristini stock)
    $order->update_status( 'cancelled', __( 'Prenotazione annullata autonomamente dal visitatore tramite link email.', 'dfn-theme' ) );

    // Invia email di annullamento centralizzata al visitatore
    dfn_send_booking_cancellation( $booking->id );

    // Render della pagina di conferma annullamento
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . esc_html__( 'Prenotazione Annullata', 'dfn-theme' ) . '</title>';
    echo '<style>body { font-family: sans-serif; background: #f3f7f4; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 80vh; } .card { background: #fff; padding: 40px; border-radius: 16px; text-align: center; max-width: 500px; box-shadow: 0 10px 30px rgba(0,75,35,0.05); }</style></head><body>';
    echo '<div class="card">';
    echo '<h1 style="color: #166534; margin-top: 0;">✅ ' . esc_html__( 'Annullamento Completato', 'dfn-theme' ) . '</h1>';
    echo '<p style="font-size: 16px; color: #64748b; line-height: 1.6;">' . esc_html__( 'La tua prenotazione è stata annullata con successo. Ti abbiamo inviato un\'email di conferma dell\'annullamento.', 'dfn-theme' ) . '</p>';
    echo '<a href="' . esc_url( site_url() ) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #004b23; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold;">' . esc_html__( 'Torna alla Home', 'dfn-theme' ) . '</a>';
    echo '</div></body></html>';
    exit;
}

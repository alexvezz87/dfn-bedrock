<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * 1. ETICHETTA QUALIFICA (SOCIO FAI / AUTORITÀ / CASSA LIVE / STANDARD)
 */
function cv_is_order_fai( $order ) {
    if ( ! $order ) return false;
    $coupons = $order->get_coupon_codes();
    if ( in_array( 'socio_fai_novara_2025', array_map( 'strtolower', $coupons ) ) ) return true;
    foreach ( $order->get_items( 'fee' ) as $item ) {
        if ( strpos( strtolower( $item->get_name() ), 'fai' ) !== false ) return true;
    }
    return false;
}

function cv_get_order_qualifica_label( $order ) {
    if ( ! $order ) return '';
    $badges = array();

    if ( $order->get_meta('_cv_is_authority') === 'yes' ) {
        $badges[] = '<span style="background:#6b21a8; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block; margin-right:4px; margin-bottom:4px;">🌟 AUTORITÀ</span>';
    }
    if ( $order->get_payment_method_title() === 'Contanti in Loco (Botteghino)' ) {
        $badges[] = '<span style="background:#16a34a; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block; margin-right:4px; margin-bottom:4px;">💵 CASSA LIVE</span>';
    }
    if ( cv_is_order_fai( $order ) ) {
        $badges[] = '<span style="background:#ff6600; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block; margin-right:4px; margin-bottom:4px;">SOCIO FAI</span>';
    }
    if ( empty( $badges ) ) {
        return '<span style="color:#aaa; font-size:12px;">Standard</span>';
    }
    return implode( '', $badges );
}

add_filter( 'manage_woocommerce_page_wc-orders_columns', 'cv_add_fai_column_to_orders' );
add_filter( 'manage_edit-shop_order_columns', 'cv_add_fai_column_to_orders' );
function cv_add_fai_column_to_orders( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $column ) {
        $new_columns[$key] = $column;
        if ( 'order_status' === $key ) $new_columns['cv_fai_status'] = 'Qualifica';
    }
    return $new_columns;
}

add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'cv_populate_fai_column', 10, 2 );
add_action( 'manage_shop_order_posts_custom_column', 'cv_populate_fai_column', 10, 2 );
/**
 * Popola la colonna 'Qualifica' nella lista ordini WooCommerce.
 *
 * @param string     $column   Nome della colonna.
 * @param int|object $order_id ID dell'ordine (o oggetto WC_Order in HPOS).
 * @return void
 */
function cv_populate_fai_column( $column, $order_id ): void {
    if ( 'cv_fai_status' === $column ) {
        $order = wc_get_order( $order_id );
        // Late escaping: kses_post per permettere solo HTML sicuro (span, strong)
        echo wp_kses_post( cv_get_order_qualifica_label( $order ) );
    }
}

/**
 * 2. PLACEHOLDER EMAIL WOOCOMMERCE {nome_evento}
 */
add_filter( 'woocommerce_email_format_string' , 'cv_custom_email_placeholders', 20, 2 );
function cv_custom_email_placeholders( $string, $email ) {
    if ( isset( $email->object ) && is_a( $email->object, 'WC_Order' ) ) {
        $order = $email->object;
        if ( strpos( $string, '{nome_evento}' ) !== false ) {
            $nomi_eventi = array();
            foreach ( $order->get_items() as $item ) { $nomi_eventi[] = $item->get_name(); }
            $titolo_evento = implode( ' + ', $nomi_eventi );
            $string = str_replace( '{nome_evento}', $titolo_evento, $string );
        }
    }
    return $string;
}

/**
 * 3. GESTORE DEI LOG UTENTE (LOGIN, REGISTRAZIONE, ACCESSI)
 */
/**
 * Registra un'azione nel log attività dell'utente.
 *
 * Tiene un massimo di 50 voci per utente, eliminando le più vecchie.
 *
 * @param int    $user_id ID dell'utente WordPress.
 * @param string $azione  Descrizione dell'azione da registrare.
 * @return void
 */
function cv_aggiungi_log_utente( int $user_id, string $azione ): void {
    $log = get_user_meta( $user_id, '_cv_user_activity_log', true );
    if ( ! is_array( $log ) ) {
        $log = array();
    }

    // Sanitizza l'IP: supporta proxy (X-Forwarded-For) e fallback a REMOTE_ADDR
    $ip_raw = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
        ? explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )[0]
        : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'N/A' );
    $ip = trim( $ip_raw );

    $log[] = array(
        'data'   => current_time( 'mysql' ),
        'azione' => $azione,
        'ip'     => $ip,
    );

    if ( count( $log ) > 50 ) {
        $log = array_slice( $log, -50 );
    }

    update_user_meta( $user_id, '_cv_user_activity_log', $log );
}

add_action('wp_login', 'cv_track_user_login', 10, 2);
function cv_track_user_login($user_login, $user) { cv_aggiungi_log_utente($user->ID, '🔑 Login effettuato'); }

add_action('user_register', 'cv_track_user_registration', 10, 1);
function cv_track_user_registration($user_id) {
    $user_info = get_userdata($user_id);
    $ordini_passati = wc_get_orders(array('billing_email' => $user_info->user_email, 'limit' => 1));
    $messaggio = '🆕 Registrazione completata' . (!empty($ordini_passati) ? ' (Riconosciuto come vecchio cliente FAI)' : '');
    cv_aggiungi_log_utente($user_id, $messaggio);
}

add_action('template_redirect', 'cv_track_access_tickets');
function cv_track_access_tickets() {
    if (is_user_logged_in() && is_account_page() && is_wc_endpoint_url('orders')) {
        $user_id = get_current_user_id();
        $lock_key = 'cv_log_tickets_lock_' . $user_id;
        if (!get_transient($lock_key)) {
            cv_aggiungi_log_utente($user_id, '🎟️ Visualizzata sezione "I Miei Biglietti"');
            set_transient($lock_key, 1, HOUR_IN_SECONDS);
        }
    }
}

add_action('show_user_profile', 'cv_mostra_log_nel_profilo');
add_action('edit_user_profile', 'cv_mostra_log_nel_profilo');
function cv_mostra_log_nel_profilo($user) {
    $log = get_user_meta($user->ID, '_cv_user_activity_log', true);
    ?>
    <div style="margin-top: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
        <h3>📜 Log Attività CandleVibes</h3>
        <?php if (empty($log)) : echo '<p>Nessuna attività.</p>'; else : $log = array_reverse($log); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th style="width:180px;">Data e Ora</th><th>Azione</th><th style="width:120px;">Indirizzo IP</th></tr></thead>
                <tbody>
                    <?php foreach ($log as $entry) : ?>
                        <tr><td><strong><?php echo date_i18n('d/m/Y - H:i:s', strtotime($entry['data'])); ?></strong></td><td><?php echo esc_html($entry['azione']); ?></td><td><small><?php echo esc_html($entry['ip']); ?></small></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
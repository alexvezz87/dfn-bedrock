<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * Ottiene un'impostazione configurata a database o il suo valore di default.
 *
 * @param string $key La chiave dell'impostazione.
 * @param mixed $default Il valore di default opzionale se non configurato.
 * @return mixed Il valore dell'opzione o il default.
 */
function dfn_get_setting( $key, $default = null ) {
    static $settings = null;
    if ( $settings === null ) {
        $settings = get_option( 'dfn_settings', array() );
        if ( ! is_array( $settings ) ) {
            $settings = array();
        }
    }

    // Elenco completo dei valori predefiniti (fallback)
    $defaults = array(
        'delegation_name'             => 'FAI Novara',
        'delegation_footer'           => 'FAI - Delegazione di Novara',
        'email_staff_signature'       => 'Lo Staff della Delegazione FAI Novara',
        'email_new_booking'           => get_option( 'admin_email' ),
        'email_verify_fai'            => get_option( 'admin_email' ),
        'email_cc_bcc'                => '',
        'email_primary_color'         => '#004b23',
        'email_accent_color'          => '#c69c3a',
        'email_bg_color'              => '#f4f6f8',
        'email_text_color'            => '#2d3748',
        'email_disclaimer'            => "Questa è un'email automatica inviata dal sistema di prenotazione. Si prega di non rispondere direttamente a questo messaggio.",
        'cron_timeout_no_booking'     => 24,
        'cron_reminder_start'         => 12,
        'cron_reminder_end'           => 36,
        'cron_waitlist_ttl'           => 2,
        'cron_batch_reminder'         => 20,
        'cron_batch_expired'          => 30,
        'fai_coupon_code'             => 'socio_fai_novara_2025',
        'fai_expiry_warning_days'     => 15,
        'fai_member_types'            => 'INDIVIDUALE, COPPIA, FAMIGLIA',
        'fai_no_email_placeholder'    => 'no-email@dfn.it',
        'limit_max_fai_members'       => 100,
        'limit_max_activity_logs'     => 50,
        'text_early_arrival'          => 'almeno 10 minuti prima',
        'text_no_bookings_myaccount'  => 'Non hai ancora effettuato nessuna prenotazione. Consulta i nostri eventi per prenotare il tuo posto.',
        'text_checkout_btn'           => 'Effettua Prenotazione',
        'enable_admin_notification'   => 'yes',
        'enable_reminder_24h'         => 'yes',
        'enable_auto_waitlist'        => 'yes',
        'enable_auto_complete_paid'   => 'yes',
        'setup_roles_version'         => '2.0',
        'setup_fai_discount'          => 5,
    );

    if ( isset( $settings[ $key ] ) ) {
        return $settings[ $key ];
    }

    if ( $default !== null ) {
        return $default;
    }

    return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
}

/**
 * 1. ETICHETTA QUALIFICA (SOCIO FAI / AUTORITÀ / CASSA LIVE / STANDARD)
 */

/**
 * Verifica se un ordine WooCommerce ha una componente FAI (sconto socio o biglietti FAI).
 *
 * @param WC_Order|false $order L'oggetto ordine WooCommerce.
 * @return bool
 */
function dfn_is_order_fai( $order ) {
    if ( ! $order ) return false;

    // 1. Verifica tramite i coupon
    $coupons = $order->get_coupon_codes();
    $fai_coupon = strtolower( dfn_get_setting( 'fai_coupon_code', 'socio_fai_novara_2025' ) );
    if ( in_array( $fai_coupon, array_map( 'strtolower', $coupons ) ) ) return true;

    // 2. Verifica tramite fees (ereditate) o custom items
    foreach ( $order->get_items( 'fee' ) as $item ) {
        if ( strpos( strtolower( $item->get_name() ), 'fai' ) !== false ) return true;
    }

    // 3. Verifica tramite i record di booking nel database dfn_bookings (se presenti)
    global $wpdb;
    $order_id = $order->get_id();
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT persons_fai FROM {$wpdb->prefix}dfn_bookings WHERE order_id = %d LIMIT 1",
        $order_id
    ) );

    if ( $booking && $booking->persons_fai > 0 ) {
        return true;
    }

    return false;
}

/**
 * Ottiene l'etichetta HTML per qualificare un ordine nella lista.
 *
 * @param WC_Order|false $order L'oggetto ordine WooCommerce.
 * @return string HTML dell'etichetta.
 */
function dfn_get_order_qualifica_label( $order ) {
    if ( ! $order ) return '';
    $badges = array();

    if ( $order->get_meta('_dfn_is_authority') === 'yes' || $order->get_meta('_cv_is_authority') === 'yes' ) {
        $badges[] = '<span style="background:#6b21a8; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block; margin-right:4px; margin-bottom:4px;">🌟 AUTORITÀ</span>';
    }

    if ( dfn_is_order_fai( $order ) ) {
        $badges[] = '<span style="background:#ff6600; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block; margin-right:4px; margin-bottom:4px;">SOCIO FAI</span>';
    }

    if ( empty( $badges ) ) {
        return '<span style="color:#aaa; font-size:12px;">Standard</span>';
    }
    return implode( '', $badges );
}

/**
 * Ottiene l'etichetta HTML per la tipologia/stato di pagamento di un ordine.
 *
 * @param WC_Order|false $order L'oggetto ordine WooCommerce.
 * @return string HTML dell'etichetta.
 */
function dfn_get_order_payment_type_label( $order ) {
    if ( ! $order ) return '';
    
    $payment_method = $order->get_payment_method();
    $status = $order->get_status();
    
    // Controlla se la prenotazione ha una parte pagata e una parte dovuta (Ibrido)
    global $wpdb;
    $booking = $wpdb->get_row( $wpdb->prepare(
        "SELECT amount_paid, amount_due FROM {$wpdb->prefix}dfn_bookings WHERE order_id = %d LIMIT 1",
        $order->get_id()
    ) );
    
    if ( $booking && isset( $booking->amount_paid ) && isset( $booking->amount_due ) && floatval( $booking->amount_paid ) > 0 && floatval( $booking->amount_due ) > 0 ) {
        return '<span style="background:#7c3aed; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">🔄 IBRIDO</span>';
    }
    
    if ( $payment_method === 'dfn_in_loco' || $order->get_payment_method_title() === 'Contanti in Loco (Botteghino)' ) {
        if ( $status === 'pending' ) {
            return '<span style="background:#eab308; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">🕒 IN LOCO (SOSPESO)</span>';
        }
        
        $physical_method = $order->get_meta('_dfn_physical_payment_method');
        if ( $physical_method === 'cash' ) {
            return '<span style="background:#16a34a; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">💵 BOTTEGHINO (CASH)</span>';
        } elseif ( $physical_method === 'pos' ) {
            return '<span style="background:#0284c7; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">💳 BOTTEGHINO (POS)</span>';
        } else {
            return '<span style="background:#16a34a; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">💵 CASSA LIVE</span>';
        }
    }
    
    return '<span style="background:#2563eb; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">🌐 PAGATO ONLINE</span>';
}

add_filter( 'manage_woocommerce_page_wc-orders_columns', 'dfn_add_fai_column_to_orders' );
add_filter( 'manage_edit-shop_order_columns', 'dfn_add_fai_column_to_orders' );
function dfn_add_fai_column_to_orders( $columns ) {
    $new_columns = array();
    foreach ( $columns as $key => $column ) {
        $new_columns[$key] = $column;
        if ( 'order_status' === $key ) {
            $new_columns['dfn_fai_status'] = 'Qualifica';
            $new_columns['dfn_payment_type'] = 'Pagamento';
        }
    }
    return $new_columns;
}

add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'dfn_populate_fai_column', 10, 2 );
add_action( 'manage_shop_order_posts_custom_column', 'dfn_populate_fai_column', 10, 2 );
/**
 * Popola le colonne personalizzate nella lista ordini WooCommerce.
 *
 * @param string     $column   Nome della colonna.
 * @param int|object $order_id ID dell'ordine (o oggetto WC_Order in HPOS).
 * @return void
 */
function dfn_populate_fai_column( $column, $order_id ): void {
    if ( 'dfn_fai_status' === $column ) {
        $order = wc_get_order( $order_id );
        echo wp_kses_post( dfn_get_order_qualifica_label( $order ) );
    } elseif ( 'dfn_payment_type' === $column ) {
        $order = wc_get_order( $order_id );
        echo wp_kses_post( dfn_get_order_payment_type_label( $order ) );
    }
}

/**
 * 2. PLACEHOLDER EMAIL WOOCOMMERCE {nome_evento}
 */
add_filter( 'woocommerce_email_format_string' , 'dfn_custom_email_placeholders', 20, 2 );
function dfn_custom_email_placeholders( $string, $email ) {
    if ( isset( $email->object ) && is_a( $email->object, 'WC_Order' ) ) {
        $order = $email->object;
        if ( strpos( $string, '{nome_evento}' ) !== false ) {
            $nomi_eventi = array();
            foreach ( $order->get_items() as $item ) {
                $nomi_eventi[] = $item->get_name();
            }
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
function dfn_aggiungi_log_utente( int $user_id, string $azione ): void {
    $log = get_user_meta( $user_id, '_dfn_user_activity_log', true );
    if ( ! is_array( $log ) ) {
        $log = array();
    }

    $ip_raw = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
        ? explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) )[0]
        : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'N/A' );
    $ip = trim( $ip_raw );

    $log[] = array(
        'data'   => current_time( 'mysql' ),
        'azione' => $azione,
        'ip'     => $ip,
    );

    $max_logs = intval( dfn_get_setting( 'limit_max_activity_logs', 50 ) );
    if ( count( $log ) > $max_logs ) {
        $log = array_slice( $log, -$max_logs );
    }

    update_user_meta( $user_id, '_dfn_user_activity_log', $log );
}

add_action('wp_login', 'dfn_track_user_login', 10, 2);
function dfn_track_user_login($user_login, $user) {
    dfn_aggiungi_log_utente($user->ID, '🔑 Login effettuato');
}

add_action('user_register', 'dfn_track_user_registration', 10, 1);
function dfn_track_user_registration($user_id) {
    $user_info = get_userdata($user_id);
    $ordini_passati = wc_get_orders(array('billing_email' => $user_info->user_email, 'limit' => 1));
    $messaggio = '🆕 Registrazione completata' . (!empty($ordini_passati) ? ' (Riconosciuto come vecchio cliente FAI)' : '');
    dfn_aggiungi_log_utente($user_id, $messaggio);
}

add_action('template_redirect', 'dfn_track_access_tickets');
function dfn_track_access_tickets() {
    if (is_user_logged_in() && is_account_page() && is_wc_endpoint_url('orders')) {
        $user_id = get_current_user_id();
        $lock_key = 'dfn_log_tickets_lock_' . $user_id;
        if (!get_transient($lock_key)) {
            dfn_aggiungi_log_utente($user_id, '🎟️ Visualizzata sezione "I Miei Biglietti"');
            set_transient($lock_key, 1, HOUR_IN_SECONDS);
        }
    }
}

add_action('show_user_profile', 'dfn_mostra_log_nel_profilo');
add_action('edit_user_profile', 'dfn_mostra_log_nel_profilo');
function dfn_mostra_log_nel_profilo($user) {
    $log = get_user_meta($user->ID, '_dfn_user_activity_log', true);
    if (empty($log)) {
        // Fallback al vecchio log per compatibilità
        $log = get_user_meta($user->ID, '_cv_user_activity_log', true);
    }
    ?>
    <div style="margin-top: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
        <h3>📜 Log Attività FAI Prenotazioni</h3>
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

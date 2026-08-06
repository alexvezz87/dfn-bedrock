<?php

/**
 * DFN Mobile Web App & PWA Hub — Gestione Eventi Mobile (/gestione-eventi/)
 *
 * Fornisce un'interfaccia mobile-first completa, protetta da login e ottimizzata
 * per l'uso su smartphone e tablet sul campo per la verifica biglietti QR, 
 * l'inserimento rapido prenotazioni, il botteghino live, il check-in e la validazione tessere FAI.
 *
 * @package DFN_Theme
 * @since   2.1.5
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Nasconde la barra di amministrazione di WordPress quando si naviga l'App Mobile (/gestione-eventi/).
 */
function dfn_hide_admin_bar_on_mobile_app(bool $show): bool
{
    if (is_page('gestione-eventi') || (is_singular() && has_shortcode(get_post()->post_content ?? '', 'dfn_mobile_app'))) {
        return false;
    }
    return $show;
}
add_filter('show_admin_bar', 'dfn_hide_admin_bar_on_mobile_app', 99);

/**
 * Renderizza lo Shortcode [dfn_mobile_app]
 */
function dfn_mobile_app_shortcode(): string
{
    ob_start();
    dfn_render_mobile_app();
    return ob_get_clean();
}
add_shortcode('dfn_mobile_app', 'dfn_mobile_app_shortcode');

/**
 * Crea o assicura l'esistenza della pagina WordPress "Gestione Eventi" (/gestione-eventi/)
 * contenente lo shortcode [dfn_mobile_app].
 *
 * @return void
 */
function dfn_auto_create_mobile_app_page(): void
{
    if (get_option('dfn_mobile_app_page_v211') === 'yes') {
        return;
    }

    $page_slug  = 'gestione-eventi';
    $page_title = 'Gestione Eventi Mobile';

    $existing_page = get_page_by_path($page_slug);

    if (! $existing_page) {
        $page_id = wp_insert_post([
            'post_title'     => $page_title,
            'post_name'      => $page_slug,
            'post_content'   => '[dfn_mobile_app]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]);
        if ($page_id && ! is_wp_error($page_id)) {
            update_option('dfn_mobile_app_page_id', $page_id);
        }
    } else {
        if (strpos($existing_page->post_content, '[dfn_mobile_app]') === false) {
            wp_update_post([
                'ID'           => $existing_page->ID,
                'post_content' => $existing_page->post_content . "\n[dfn_mobile_app]",
            ]);
        }
    }

    update_option('dfn_mobile_app_page_v211', 'yes');
}
add_action('init', 'dfn_auto_create_mobile_app_page');

/**
 * AJAX Handler per recuperare i dettagli del check-in mobile di un evento.
 */
function dfn_ajax_mobile_get_event_checkin_list(): void
{
    $sec = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($sec, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_booking_nonce')
    ) {
        if (! is_user_logged_in()) {
            wp_send_json_error(__('Permessi non sufficienti.', 'dfn-theme'), 401);
        }
    }

    $event_id      = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $selected_date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';

    if (! $event_id) {
        wp_send_json_error(__('ID evento non valido.', 'dfn-theme'));
    }

    global $wpdb;
    $table_events        = $wpdb->prefix . 'dfn_events';
    $table_bookings      = $wpdb->prefix . 'dfn_bookings';
    $table_slots         = $wpdb->prefix . 'dfn_time_slots';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';

    $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_events} WHERE id = %d", $event_id));
    if (! $event) {
        wp_send_json_error(__('Evento non trovato.', 'dfn-theme'));
    }

    // 1. Raccogli le date disponibili per l'evento
    $raw_dates = [];

    // a) Date da dfn_event_slots
    $table_event_slots = $wpdb->prefix . 'dfn_event_slots';
    $slot_dates = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT slot_date FROM {$table_event_slots} WHERE event_id = %d AND slot_date IS NOT NULL ORDER BY slot_date ASC",
        $event_id
    ));
    if (! empty($slot_dates)) {
        $raw_dates = array_merge($raw_dates, $slot_dates);
    }

    // b) Date da dfn_time_slots (se presente)
    $table_time_slots = $wpdb->prefix . 'dfn_time_slots';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_time_slots}'") === $table_time_slots) {
        $ts_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT slot_date FROM {$table_time_slots} WHERE event_id = %d AND slot_date IS NOT NULL ORDER BY slot_date ASC",
            $event_id
        ));
        if (! empty($ts_dates)) {
            $raw_dates = array_merge($raw_dates, $ts_dates);
        }
    }

    // c) Genera date tra event_date_start e event_date_end
    if (! empty($event->event_date_start)) {
        $start = $event->event_date_start;
        $end   = (! empty($event->event_date_end) && $event->event_date_end >= $start) ? $event->event_date_end : $start;
        $cur   = new \DateTime($start);
        $endDt = new \DateTime($end);
        while ($cur <= $endDt) {
            $raw_dates[] = $cur->format('Y-m-d');
            $cur->modify('+1 day');
        }
    }

    $raw_dates = array_values(array_unique(array_filter($raw_dates)));
    sort($raw_dates);

    // Formatta la lista di date disponibili per il JS
    $available_dates_formatted = [];
    foreach ($raw_dates as $d) {
        $available_dates_formatted[] = [
            'date'  => $d,
            'label' => date('d/m/Y', strtotime($d)),
        ];
    }

    // Determina la data selezionata di default (oggi se presente nelle date, altrimenti la prima data dell'evento)
    $today_str = date('Y-m-d');
    if (empty($selected_date)) {
        if (in_array($today_str, $raw_dates, true)) {
            $selected_date = $today_str;
        } elseif (! empty($raw_dates)) {
            $selected_date = $raw_dates[0];
        } else {
            $selected_date = 'all';
        }
    }

    // 2. Filtra le prenotazioni in base alla data selezionata (Ordinate dalla più recente alla meno recente)
    if ('all' === $selected_date) {
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_bookings} WHERE event_id = %d AND status != 'cancelled' ORDER BY created_at DESC, id DESC",
            $event_id
        ));
    } else {
        // a) Cerca booking IDs legati a uno slot fisico in dfn_event_slots per quella data
        $b_ids_slots = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT bs.booking_id 
             FROM {$wpdb->prefix}dfn_booking_slots bs 
             INNER JOIN {$table_event_slots} s ON bs.slot_id = s.id 
             WHERE s.event_id = %d AND s.slot_date = %s",
            $event_id,
            $selected_date
        ));

        // b) Cerca booking IDs dai metadati dell'ordine WooCommerce (_dfn_booking_date)
        $table_oi  = $wpdb->prefix . 'woocommerce_order_items';
        $table_oim = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $b_ids_meta = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT b.id 
             FROM {$table_bookings} b
             INNER JOIN {$table_oi} oi ON b.order_id = oi.order_id
             INNER JOIN {$table_oim} oim ON oi.order_item_id = oim.order_item_id
             WHERE b.event_id = %d 
               AND oim.meta_key = '_dfn_booking_date' 
               AND oim.meta_value = %s
               AND b.status != 'cancelled'",
            $event_id,
            $selected_date
        ));

        // c) Per eventi a data unica o prive di metadati specifici, includi prenotazioni se la data selezionata è event_date_start
        $b_ids_default = [];
        if ($selected_date === $event->event_date_start) {
            $b_ids_default = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$table_bookings} WHERE event_id = %d AND status != 'cancelled'",
                $event_id
            ));
        }

        $all_matching_b_ids = array_values(array_unique(array_filter(array_merge(
            $b_ids_slots ?: [],
            $b_ids_meta ?: [],
            $b_ids_default ?: []
        ))));

        if (! empty($all_matching_b_ids)) {
            $in_sql = implode(',', array_map('absint', $all_matching_b_ids));
            $bookings = $wpdb->get_results(
                "SELECT * FROM {$table_bookings} WHERE id IN ({$in_sql}) AND status != 'cancelled' ORDER BY created_at DESC, id DESC"
            );
        } else {
            $bookings = [];
        }
    }

    $total_capacity   = intval($event->total_capacity);
    $total_booked     = 0;
    $total_checked_in = 0;

    $formatted_bookings = [];
    foreach ($bookings as $b) {
        $total_booked += intval($b->total_persons);
        $is_checked   = (! empty($b->checked_in_at) && $b->checked_in_at !== '0000-00-00 00:00:00') || $b->status === 'checked_in';
        if ($is_checked) {
            $total_checked_in += intval($b->total_persons);
        }

        $formatted_bookings[] = [
            'id'              => intval($b->id),
            'customer_name'   => esc_html($b->customer_name),
            'customer_email'  => esc_html($b->customer_email),
            'customer_phone'  => esc_html($b->customer_phone ?: ''),
            'total_persons'   => intval($b->total_persons),
            'persons_std'     => intval($b->persons_standard),
            'persons_fai'     => intval($b->persons_fai),
            'amount_due'      => floatval($b->amount_due),
            'amount_paid'     => floatval($b->amount_paid),
            'checked_in'      => $is_checked,
            'checked_in_time' => ($is_checked && ! empty($b->checked_in_at) && $b->checked_in_at !== '0000-00-00 00:00:00') ? date('H:i', strtotime($b->checked_in_at)) : '',
            'qr_token'        => esc_html($b->qr_token),
        ];
    }

    wp_send_json_success([
        'event_title'      => esc_html(get_the_title($event->product_id)),
        'event_date'       => date('d/m/Y', strtotime($event->event_date_start)),
        'event_time'       => date('H:i', strtotime($event->event_time_start)),
        'total_capacity'   => $total_capacity,
        'total_booked'     => $total_booked,
        'total_checked_in' => $total_checked_in,
        'total_remaining'  => max(0, $total_booked - $total_checked_in),
        'available_dates'  => $available_dates_formatted,
        'selected_date'    => $selected_date,
        'bookings'         => $formatted_bookings,
    ]);
}
add_action('wp_ajax_dfn_mobile_get_event_checkin_list', 'dfn_ajax_mobile_get_event_checkin_list');

/**
 * AJAX Handler per eseguire o annullare il Check-in di una prenotazione dall'App Mobile.
 */
function dfn_ajax_mobile_do_checkin(): void
{
    $sec = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($sec, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_scanner_nonce')
    ) {
        if (! is_user_logged_in()) {
            wp_send_json_error(__('Permessi non sufficienti.', 'dfn-theme'), 401);
        }
    }

    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $qr_token   = isset($_POST['qr_token']) ? sanitize_text_field(wp_unslash($_POST['qr_token'])) : '';

    global $wpdb;
    $table_bookings      = $wpdb->prefix . 'dfn_bookings';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';

    if ($booking_id > 0) {
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_bookings} WHERE id = %d", $booking_id));
    } elseif (! empty($qr_token)) {
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_bookings} WHERE qr_token = %s", $qr_token));
    } else {
        $booking = null;
    }

    if (! $booking) {
        wp_send_json_error(__('Prenotazione non trovata.', 'dfn-theme'));
    }

    $is_already_checked = (! empty($booking->checked_in_at) && $booking->checked_in_at !== '0000-00-00 00:00:00') || $booking->status === 'checked_in';

    if ($is_already_checked) {
        // Toggle: Annulla check-in se già eseguito
        $wpdb->update(
            $table_bookings,
            [
                'status'        => 'confirmed',
                'checked_in_at' => null,
                'checked_in_by' => null,
            ],
            [ 'id' => $booking->id ]
        );

        $wpdb->update(
            $table_booking_slots,
            [
                'checked_in_at' => null,
                'checked_in_by' => null,
            ],
            [ 'booking_id' => $booking->id ]
        );

        wp_send_json_success([
            'checked_in' => false,
            'message'    => __('Check-in annullato.', 'dfn-theme'),
            'booking_id' => $booking->id,
        ]);
    } else {
        // Esegui Check-in
        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $wpdb->update(
            $table_bookings,
            [
                'status'        => 'checked_in',
                'checked_in_at' => $now,
                'checked_in_by' => $user_id,
            ],
            [ 'id' => $booking->id ]
        );

        $wpdb->update(
            $table_booking_slots,
            [
                'checked_in_at' => $now,
                'checked_in_by' => $user_id,
            ],
            [ 'booking_id' => $booking->id ]
        );

        if (! empty($booking->order_id)) {
            $order = wc_get_order($booking->order_id);
            if ($order) {
                $order->update_meta_data('_cv_checked_in', 'yes');
                $order->update_meta_data('_cv_checked_in_at', $now);
                $order->update_meta_data('_cv_checked_in_by', $user_id);
                $order->save();
            }
        }

        wp_send_json_success([
            'checked_in'      => true,
            'checked_in_time' => date('H:i', strtotime($now)),
            'message'         => __('Check-in registrato con successo.', 'dfn-theme'),
            'booking_id'      => $booking->id,
        ]);
    }
}
add_action('wp_ajax_dfn_mobile_do_checkin', 'dfn_ajax_mobile_do_checkin');

/**
 * AJAX Handler per reinviare l'email del biglietto con QR code al cliente.
 */
function dfn_ajax_mobile_resend_ticket_email(): void
{
    $sec = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($sec, 'dfn_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_scanner_nonce')
    ) {
        if (! is_user_logged_in()) {
            wp_send_json_error(__('Permessi non sufficienti.', 'dfn-theme'), 401);
        }
    }

    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    if (! $booking_id) {
        wp_send_json_error(__('ID prenotazione non valido.', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_bookings} WHERE id = %d", $booking_id));

    if (! $booking) {
        wp_send_json_error(__('Prenotazione non trovata.', 'dfn-theme'));
    }

    $email = trim($booking->customer_email);
    if (empty($email) || strpos($email, 'no-email@') !== false || ! is_email($email)) {
        wp_send_json_error(__('Impossibile inviare l\'email: la prenotazione ha un indirizzo email fittizio (' . esc_html($email) . ').', 'dfn-theme'));
    }

    $sent = false;
    if (function_exists('dfn_send_booking_confirmation')) {
        $sent = dfn_send_booking_confirmation($booking_id);
    }

    if (! $sent) {
        $event = dfn_db_get_event($booking->event_id);
        $event_title = ($event && ! empty($event->product_id)) ? get_the_title($event->product_id) : '';
        if (empty($event_title) || $event_title === 'Privacy Policy') {
            $event_title = get_the_title($booking->event_id);
            if (empty($event_title) || $event_title === 'Privacy Policy') {
                $event_title = 'Evento FAI Novara';
            }
        }

        $qr_token = esc_html($booking->qr_token);
        $qr_img_url = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($booking->qr_token) . '&margin=10';

        $subject = '🎟️ Il tuo Biglietto con QR Code — ' . $event_title;
        $content = '
            <p>Ciao <strong>' . esc_html($booking->customer_name) . '</strong>,</p>
            <p>Ecco il tuo biglietto di ingresso per l\'evento <strong>' . esc_html($event_title) . '</strong>:</p>
            
            <div style="text-align: center; margin: 20px 0; padding: 20px; background: #ffffff; border: 2px dashed #004b23; border-radius: 12px;">
                <p style="font-size: 13px; font-weight: bold; color: #004b23; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 1px;">
                    📱 Mostra questo QR Code all\'ingresso per lo scanner
                </p>
                <img src="' . esc_url($qr_img_url) . '" alt="QR Code Biglietto" width="240" height="240" style="display: block; margin: 0 auto; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.08);" />
                <p style="font-family: monospace; font-size: 15px; font-weight: bold; color: #334155; margin-top: 12px; letter-spacing: 1px;">
                    ' . $qr_token . '
                </p>
            </div>

            <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:15px; border-radius:8px; margin:15px 0;">
                <p style="margin:4px 0;">👥 <strong>Partecipanti:</strong> ' . intval($booking->total_persons) . ' Persone (Interi: ' . intval($booking->persons_standard) . ', FAI: ' . intval($booking->persons_fai) . ')</p>
            </div>
            <p>Conserva questa email ed esibisci il codice QR direttamente dal tuo smartphone all\'arrivo.</p>
        ';

        $sent = dfn_send_notification_email($email, $subject, 'Biglietto di Prenotazione', $content);
    }

    if ($sent) {
        wp_send_json_success(__('Email del biglietto con QR Code inviata con successo!', 'dfn-theme'));
    } else {
        wp_send_json_error(__('Errore durante l\'invio dell\'email.', 'dfn-theme'));
    }
}
add_action('wp_ajax_dfn_mobile_resend_ticket_email', 'dfn_ajax_mobile_resend_ticket_email');

/**
 * AJAX Handler per Annullare una Prenotazione dall'App Mobile.
 */
function dfn_ajax_mobile_cancel_booking(): void
{
    $sec = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($sec, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_scanner_nonce')
    ) {
        if (! is_user_logged_in()) {
            wp_send_json_error(__('Permessi non sufficienti.', 'dfn-theme'), 401);
        }
    }

    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $note       = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : 'Annullata da operatore tramite App Mobile';

    if (! $booking_id) {
        wp_send_json_error(__('ID prenotazione non valido.', 'dfn-theme'));
    }

    if (function_exists('dfn_cancel_booking_by_id')) {
        $res = dfn_cancel_booking_by_id($booking_id, $note);
        if ($res) {
            wp_send_json_success([
                'message'    => __('Prenotazione annullata con successo! I posti sono stati liberati.', 'dfn-theme'),
                'booking_id' => $booking_id,
            ]);
        } else {
            wp_send_json_error(__('Impossibile annullare la prenotazione.', 'dfn-theme'));
        }
    } else {
        wp_send_json_error(__('Funzione di annullamento non disponibile.', 'dfn-theme'));
    }
}
add_action('wp_ajax_dfn_mobile_cancel_booking', 'dfn_ajax_mobile_cancel_booking');

/**
 * AJAX Handler per Spostare il Turno/Data di una Prenotazione dall'App Mobile.
 */
function dfn_ajax_mobile_move_booking(): void
{
    $sec = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($sec, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_scanner_nonce')
    ) {
        if (! is_user_logged_in()) {
            wp_send_json_error(__('Permessi non sufficienti.', 'dfn-theme'), 401);
        }
    }

    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    $to_slot_id = isset($_POST['to_slot_id']) ? absint($_POST['to_slot_id']) : 0;
    $notify     = isset($_POST['notify']) && '1' === (string)$_POST['notify'];

    if (! $booking_id || ! $to_slot_id) {
        wp_send_json_error(__('Seleziona uno slot valido di destinazione.', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings      = $wpdb->prefix . 'dfn_bookings';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';
    $table_slots         = $wpdb->prefix . 'dfn_event_slots';

    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_bookings} WHERE id = %d", $booking_id));
    if (! $booking) {
        wp_send_json_error(__('Prenotazione non trovata.', 'dfn-theme'));
    }

    $current_assoc = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_booking_slots} WHERE booking_id = %d LIMIT 1", $booking_id));
    $from_slot_id  = $current_assoc ? intval($current_assoc->slot_id) : 0;

    if ($from_slot_id === $to_slot_id) {
        wp_send_json_error(__('La prenotazione è già assegnata a questo turno.', 'dfn-theme'));
    }

    $persons = intval($booking->total_persons);

    $to_slot = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_slots} WHERE id = %d", $to_slot_id));
    if (! $to_slot) {
        wp_send_json_error(__('Slot di destinazione non trovato.', 'dfn-theme'));
    }

    if (intval($to_slot->is_locked) === 1) {
        wp_send_json_error(__('Lo slot di destinazione è bloccato per le prenotazioni.', 'dfn-theme'));
    }

    $avail = (intval($to_slot->capacity) + intval($to_slot->bonus_capacity)) - intval($to_slot->booked_count);
    if ($persons > $avail) {
        wp_send_json_error(sprintf(__('Posti insufficienti nello slot scelto (Necessari: %d, Disponibili: %d).', 'dfn-theme'), $persons, max(0, $avail)));
    }

    $wpdb->query('START TRANSACTION');

    if ($from_slot_id > 0) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_slots} SET booked_count = GREATEST(0, CAST(booked_count AS SIGNED) - %d) WHERE id = %d",
            $persons,
            $from_slot_id
        ));
        $wpdb->update(
            $table_booking_slots,
            [ 'slot_id' => $to_slot_id ],
            [ 'booking_id' => $booking_id, 'slot_id' => $from_slot_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );
    } else {
        $wpdb->insert(
            $table_booking_slots,
            [ 'booking_id' => $booking_id, 'slot_id' => $to_slot_id, 'persons' => $persons ],
            [ '%d', '%d', '%d' ]
        );
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$table_slots} SET booked_count = booked_count + %d WHERE id = %d",
        $persons,
        $to_slot_id
    ));

    $wpdb->query('COMMIT');

    if (! empty($booking->order_id)) {
        $order = wc_get_order($booking->order_id);
        if ($order) {
            foreach ($order->get_items() as $item) {
                $item->update_meta_data('_dfn_booking_date', $to_slot->slot_date);
                $item->update_meta_data('_dfn_booking_slot_id', $to_slot_id);
                $item->save();
            }
            $order->add_order_note(sprintf(
                __('Turno spostato al %s ore %s da App Mobile dall\'operatore.', 'dfn-theme'),
                date('d/m/Y', strtotime($to_slot->slot_date)),
                date('H:i', strtotime($to_slot->slot_time_start))
            ));
        }
    }

    if ($notify && function_exists('dfn_send_booking_confirmation')) {
        dfn_send_booking_confirmation($booking_id);
    }

    $new_slot_info = date('d/m/Y', strtotime($to_slot->slot_date)) . ' • ⏰ ' . date('H:i', strtotime($to_slot->slot_time_start));

    wp_send_json_success([
        'message'       => __('Turno spostato con successo!', 'dfn-theme'),
        'booking_id'    => $booking_id,
        'new_slot_info' => $new_slot_info,
    ]);
}
add_action('wp_ajax_dfn_mobile_move_booking', 'dfn_ajax_mobile_move_booking');

/**
 * AJAX Handler per recuperare i dettagli completi di una prenotazione per la Modale Mobile.
 */
function dfn_ajax_mobile_get_booking_details(): void
{
    $sec = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($sec, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_booking_nonce') &&
        ! wp_verify_nonce($sec, 'dfn_scanner_nonce')
    ) {
        if (! is_user_logged_in()) {
            wp_send_json_error(__('Permessi non sufficienti.', 'dfn-theme'), 401);
        }
    }

    $booking_id = isset($_POST['booking_id']) ? absint($_POST['booking_id']) : 0;
    if (! $booking_id) {
        wp_send_json_error(__('ID prenotazione non valido.', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings      = $wpdb->prefix . 'dfn_bookings';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';
    $table_slots         = $wpdb->prefix . 'dfn_event_slots';

    $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_bookings} WHERE id = %d", $booking_id));
    if (! $b) {
        wp_send_json_error(__('Prenotazione non trovata.', 'dfn-theme'));
    }

    $event = dfn_db_get_event($b->event_id);
    $order = $b->order_id ? wc_get_order($b->order_id) : null;

    $current_slot_info = 'Flusso Libero';
    $current_slot_id   = 0;
    $assoc = $wpdb->get_row($wpdb->prepare(
        "SELECT s.* FROM {$table_booking_slots} bs JOIN {$table_slots} s ON bs.slot_id = s.id WHERE bs.booking_id = %d LIMIT 1",
        $b->id
    ));
    if ($assoc) {
        $current_slot_id   = intval($assoc->id);
        $current_slot_info = date('d/m/Y', strtotime($assoc->slot_date)) . ' • ⏰ ' . date('H:i', strtotime($assoc->slot_time_start));
    }

    $all_slots = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_slots} WHERE event_id = %d AND is_locked = 0 ORDER BY slot_date ASC, slot_time_start ASC",
        $b->event_id
    ));

    $available_slots_formatted = [];
    foreach ($all_slots as $s) {
        $free = (intval($s->capacity) + intval($s->bonus_capacity)) - intval($s->booked_count);
        $available_slots_formatted[] = [
            'id'         => intval($s->id),
            'date'       => date('d/m/Y', strtotime($s->slot_date)),
            'time'       => date('H:i', strtotime($s->slot_time_start)),
            'label'      => date('d/m/Y', strtotime($s->slot_date)) . ' ore ' . date('H:i', strtotime($s->slot_time_start)) . ' (Disponibili: ' . max(0, $free) . ' posti)',
            'free'       => max(0, $free),
            'is_current' => intval($s->id) === $current_slot_id,
        ];
    }

    // Recupera storico operazioni dall'ordine se presente
    $history_logs_formatted = [];
    if ($order) {
        $history_meta = $order->get_meta('_cv_ticket_history');
        if (! empty($history_meta) && is_array($history_meta)) {
            usort($history_meta, function ($a, $b) {
                return strtotime($b['time']) - strtotime($a['time']);
            });
            foreach ($history_meta as $log) {
                $history_logs_formatted[] = [
                    'time'   => date_i18n('d/m/Y - H:i:s', strtotime($log['time'])),
                    'action' => esc_html($log['action']),
                ];
            }
        }
    }

    $is_checked = (! empty($b->checked_in_at) && $b->checked_in_at !== '0000-00-00 00:00:00') || $b->status === 'checked_in';

    $data = [
        'id'                 => intval($b->id),
        'order_id'           => intval($b->order_id),
        'event_id'           => intval($b->event_id),
        'customer_name'      => esc_html($b->customer_name),
        'customer_email'     => esc_html($b->customer_email),
        'customer_phone'     => esc_html($b->customer_phone ?: 'Non fornito'),
        'status'             => $b->status,
        'status_label'       => ('cancelled' === $b->status ? 'Annullata' : ($is_checked ? 'Validato / Entrato' : 'In Attesa')),
        'payment_method'     => esc_html($b->payment_method ?: 'stripe'),
        'payment_status'     => ($b->amount_due > 0 ? 'Da pagare in loco' : 'Pagato'),
        'total_persons'      => intval($b->total_persons),
        'persons_std'        => intval($b->persons_standard),
        'persons_fai'        => intval($b->persons_fai),
        'amount_due'         => floatval($b->amount_due),
        'amount_paid'        => floatval($b->amount_paid),
        'notes'              => esc_html($b->notes ?: 'Nessuna nota richiesta.'),
        'created_at'         => date('d/m/Y H:i', strtotime($b->created_at)),
        'checked_in'         => $is_checked,
        'checked_in_time'    => ($is_checked && ! empty($b->checked_in_at) && $b->checked_in_at !== '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($b->checked_in_at)) : '',
        'current_slot_info'  => $current_slot_info,
        'current_slot_id'    => $current_slot_id,
        'available_slots'    => $available_slots_formatted,
        'history_logs'       => $history_logs_formatted,
    ];

    wp_send_json_success($data);
}
add_action('wp_ajax_dfn_mobile_get_booking_details', 'dfn_ajax_mobile_get_booking_details');

/**
 * Renderizza l'intera applicazione mobile o la schermata di login se non autenticato.
 *
 * @return void
 */
function dfn_render_mobile_app(): void
{
    if (! is_user_logged_in()) {
        dfn_render_mobile_login();
        return;
    }

    $current_user = wp_get_current_user();

    $has_access = current_user_can('manage_options') 
               || current_user_can('dfn_manage_events') 
               || current_user_can('dfn_quick_booking') 
               || current_user_can('dfn_use_scanner')
               || current_user_can('dfn_checkin_and_collect');

    if (! $has_access) {
        dfn_render_mobile_access_denied($current_user);
        return;
    }

    global $wpdb;

    $table_events = $wpdb->prefix . 'dfn_events';
    $today        = date('Y-m-d');
    $events       = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_events} 
         WHERE status = 'published' AND event_date_start >= %s 
         ORDER BY event_date_start ASC, event_time_start ASC LIMIT 5",
        $today
    ));

    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $pending_bookings = $wpdb->get_results(
        "SELECT b.*, e.location 
         FROM {$table_bookings} b 
         LEFT JOIN {$table_events} e ON b.event_id = e.id 
         WHERE b.status IN ('pending', 'pending_fai_verification') 
         ORDER BY b.created_at DESC LIMIT 10"
    );

    $table_fai = $wpdb->prefix . 'dfn_fai_members';
    $pending_fai = $wpdb->get_results(
        "SELECT * FROM {$table_fai} 
         WHERE verified = 0 
         ORDER BY created_at DESC LIMIT 10"
    );

    $count_pending_bookings = count($pending_bookings);
    $count_pending_fai      = count($pending_fai);
    $count_events           = count($events);

    $user_display_name = ! empty($current_user->display_name) ? $current_user->display_name : $current_user->user_login;
    $user_initials     = strtoupper(substr($current_user->first_name ?: $current_user->user_login, 0, 1) . substr($current_user->last_name ?: '', 0, 1));
    if (empty(trim($user_initials))) {
        $user_initials = strtoupper(substr($current_user->user_login, 0, 2));
    }
    
    $user_roles = (array) $current_user->roles;
    $primary_role_name = 'Operatore Staff';
    if (in_array('administrator', $user_roles, true)) {
        $primary_role_name = 'Amministratore';
    } elseif (in_array('dfn_manager', $user_roles, true)) {
        $primary_role_name = 'Gestore Eventi';
    } elseif (in_array('dfn_volunteer', $user_roles, true)) {
        $primary_role_name = 'Volontario FAI';
    }

    $nonces = [
        'booking' => wp_create_nonce('dfn_booking_nonce'),
        'scanner' => wp_create_nonce('dfn_scanner_nonce'),
        'admin'   => wp_create_nonce('dfn_admin_events_nonce'),
        'quick'   => wp_create_nonce('dfn_quick_booking_nonce'),
        'fai'     => wp_create_nonce('dfn_fai_admin_nonce'),
    ];
    ?>

    <div id="dfn-mobile-app-root" class="dfn-mobile-app-root" data-nonces='<?php echo esc_attr(json_encode($nonces)); ?>'>
        
        <!-- HEADER MOBILE -->
        <header class="dfn-mobile-app-header">
            <div class="dfn-mobile-header-brand">
                <img src="/app/uploads/2026/07/cropped-logo_fai_trasparente.png" class="dfn-mobile-logo-img" alt="FAI Logo" />
                <div class="dfn-mobile-brand-titles">
                    <h1>FAI Novara</h1>
                    <span>Gestione Eventi Mobile</span>
                </div>
            </div>
            <div class="dfn-mobile-header-actions">
                <button type="button" id="dfn-pwa-install-btn" class="dfn-mobile-icon-btn" title="Installa App" style="display:none;">
                    <span class="dashicons dashicons-download"></span>
                </button>
                <button type="button" class="dfn-mobile-icon-btn dfn-btn-refresh" id="dfn-mobile-refresh-btn" title="Aggiorna Dati">
                    <span class="dashicons dashicons-update"></span>
                </button>
            </div>
        </header>

        <!-- CONTENITORE VISTE TAB -->
        <main class="dfn-mobile-app-main">

            <!-- TAB 1: DASHBOARD HOME -->
            <section id="dfn-tab-home" class="dfn-mobile-tab-pane active">
                
                <div class="dfn-mobile-card dfn-user-summary-card">
                    <div class="dfn-user-avatar">
                        <?php echo esc_html($user_initials); ?>
                    </div>
                    <div class="dfn-user-info">
                        <h2><?php echo esc_html($user_display_name); ?></h2>
                        <div class="dfn-user-role-badge">
                            <span class="dfn-role-dot"></span>
                            <span class="dfn-role-name"><?php echo esc_html($primary_role_name); ?></span>
                        </div>
                    </div>
                    <button type="button" class="dfn-user-profile-btn" data-target-tab="profile" title="Area Personale">
                        <span class="dashicons dashicons-admin-users"></span>
                    </button>
                </div>

                <div class="dfn-mobile-stats-grid">
                    <div class="dfn-stat-pill" data-target-tab="home" data-scroll-to="sec-upcoming">
                        <span class="dfn-stat-val"><?php echo intval($count_events); ?></span>
                        <span class="dfn-stat-lbl">Eventi In Arrivo</span>
                    </div>
                    <div class="dfn-stat-pill <?php echo $count_pending_bookings > 0 ? 'warning' : ''; ?>" data-target-tab="home" data-scroll-to="sec-bookings">
                        <span class="dfn-stat-val"><?php echo intval($count_pending_bookings); ?></span>
                        <span class="dfn-stat-lbl">Da Confermare</span>
                    </div>
                    <div class="dfn-stat-pill <?php echo $count_pending_fai > 0 ? 'info' : ''; ?>" data-target-tab="home" data-scroll-to="sec-fai">
                        <span class="dfn-stat-val"><?php echo intval($count_pending_fai); ?></span>
                        <span class="dfn-stat-lbl">Tessere FAI</span>
                    </div>
                </div>

                <!-- SEZIONE 1: EVENTI IN ARRIVO -->
                <div id="sec-upcoming" class="dfn-mobile-section">
                    <div class="dfn-section-title">
                        <h3>📅 Prossimi Eventi</h3>
                        <span class="dfn-badge-count"><?php echo intval($count_events); ?></span>
                    </div>

                    <?php if (! empty($events)) : ?>
                        <div class="dfn-mobile-cards-list">
                            <?php foreach ($events as $ev) : 
                                $date_formatted = date_i18n('d M Y', strtotime($ev->event_date_start));
                                $time_formatted = date('H:i', strtotime($ev->event_time_start));
                                ?>
                                <div class="dfn-mobile-card dfn-event-card-item">
                                    <div class="dfn-event-card-top">
                                        <span class="dfn-event-date-badge">📅 <?php echo esc_html($date_formatted); ?> • ⏰ <?php echo esc_html($time_formatted); ?></span>
                                        <?php if (! empty($ev->is_test_event)) : ?>
                                            <span class="dfn-event-status-pill test" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; font-weight:bold;">🧪 TEST</span>
                                        <?php else : ?>
                                            <span class="dfn-event-status-pill open">Aperto</span>
                                        <?php endif; ?>
                                    </div>
                                    <h4 class="dfn-event-title"><?php echo esc_html(get_the_title($ev->product_id)); ?></h4>
                                    <p class="dfn-event-location">📍 <?php echo esc_html($ev->location ?: 'Novara'); ?></p>
                                    <div class="dfn-event-card-actions three-col">
                                        <button type="button" class="dfn-mobile-btn primary btn-quick-book-event" data-event-id="<?php echo absint($ev->id); ?>">
                                            ⚡ Prenota
                                        </button>
                                        <button type="button" class="dfn-mobile-btn secondary btn-botteghino-event" data-event-id="<?php echo absint($ev->id); ?>">
                                            🎟️ Botteghino
                                        </button>
                                        <button type="button" class="dfn-mobile-btn success btn-open-checkin-event" data-event-id="<?php echo absint($ev->id); ?>">
                                            📋 Check-in
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="dfn-mobile-empty-state">
                            <p>Nessun evento in arrivo registrato.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SEZIONE 2: PRENOTAZIONI DA CONFERMARE -->
                <div id="sec-bookings" class="dfn-mobile-section">
                    <div class="dfn-section-title">
                        <h3>📋 Prenotazioni da Confermare</h3>
                        <?php if ($count_pending_bookings > 0) : ?>
                            <span class="dfn-badge-count warning"><?php echo intval($count_pending_bookings); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (! empty($pending_bookings)) : ?>
                        <div class="dfn-mobile-cards-list">
                            <?php foreach ($pending_bookings as $b) : ?>
                                <div class="dfn-mobile-card dfn-booking-card-item" id="dfn-booking-card-<?php echo absint($b->id); ?>">
                                    <div class="dfn-booking-card-header">
                                        <strong class="dfn-customer-name"><?php echo esc_html($b->customer_name); ?></strong>
                                        <span class="dfn-booking-status-tag pending">In Attesa</span>
                                    </div>
                                    <div class="dfn-booking-details">
                                        <p>📧 <?php echo esc_html($b->customer_email); ?></p>
                                        <?php if ($b->customer_phone) : ?><p>📞 <?php echo esc_html($b->customer_phone); ?></p><?php endif; ?>
                                        <p>👥 <strong><?php echo intval($b->total_persons); ?> Persone</strong> (Intero: <?php echo intval($b->persons_standard); ?>, FAI: <?php echo intval($b->persons_fai); ?>)</p>
                                    </div>
                                    <div class="dfn-booking-actions">
                                        <button type="button" class="dfn-mobile-btn success btn-confirm-booking" data-booking-id="<?php echo absint($b->id); ?>">
                                            ✅ Conferma subito
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="dfn-mobile-empty-state">
                            <p>🎉 Nessuna prenotazione in attesa di conferma.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SEZIONE 3: SOCI FAI DA VALIDARE -->
                <div id="sec-fai" class="dfn-mobile-section">
                    <div class="dfn-section-title">
                        <h3>🪪 Tessere FAI da Validare</h3>
                        <?php if ($count_pending_fai > 0) : ?>
                            <span class="dfn-badge-count info"><?php echo intval($count_pending_fai); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (! empty($pending_fai)) : ?>
                        <div class="dfn-mobile-cards-list">
                            <?php foreach ($pending_fai as $f) : ?>
                                <div class="dfn-mobile-card dfn-fai-card-item" id="dfn-fai-card-<?php echo absint($f->id); ?>">
                                    <div class="dfn-fai-card-header">
                                        <strong><?php echo esc_html($f->first_name . ' ' . $f->last_name); ?></strong>
                                        <span class="dfn-card-number">N° <?php echo esc_html($f->card_number); ?></span>
                                    </div>
                                    <div class="dfn-fai-details">
                                        <?php if ($f->email) : ?><p>📧 <?php echo esc_html($f->email); ?></p><?php endif; ?>
                                    </div>
                                    <div class="dfn-fai-actions">
                                        <button type="button" class="dfn-mobile-btn success btn-validate-fai" data-fai-id="<?php echo absint($f->id); ?>">
                                            🪪 Valida Tessera
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="dfn-mobile-empty-state">
                            <p>Tutte le tessere FAI risultano verificate.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </section>


            <!-- TAB 2: SCANNER LIVE (Html5Qrcode Engine) -->
            <section id="dfn-tab-scanner" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card dfn-scanner-card">
                    <div class="dfn-scanner-header">
                        <h3>🔍 Scanner QR Code Live</h3>
                        <p>Inquadra il codice QR del biglietto: la convalida avverrà in automatico.</p>
                    </div>

                    <!-- Contenitore Videocamera Html5Qrcode Full Width -->
                    <div id="dfn-mobile-qr-reader" class="dfn-scanner-camera-viewport"></div>

                    <!-- Output Dettaglio e Risultato Scansione QR Code -->
                    <div id="dfn-scanner-result-box" class="dfn-scanner-result-box" style="display:none;"></div>
                </div>
            </section>


            <!-- TAB 3: INSERIMENTO RAPIDO PRENOTAZIONE -->
            <section id="dfn-tab-quick" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card">
                    <h3>⚡ Inserimento Rapido Prenotazione</h3>
                    <p class="dfn-subtitle">Registra una nuova prenotazione prima o durante l'evento.</p>

                    <form id="dfn-mobile-quick-booking-form" class="dfn-mobile-form">
                        <!-- Step 1: Evento & Data -->
                        <div class="dfn-form-group">
                            <label for="dfn-m-qb-event">Evento *</label>
                            <select id="dfn-m-qb-event" name="event_id" required>
                                <option value="">Seleziona Evento...</option>
                                <?php foreach ($events as $ev) : ?>
                                    <option value="<?php echo absint($ev->id); ?>"
                                            data-access="<?php echo esc_attr($ev->access_type ?? 'time_slots'); ?>"
                                            data-name="<?php echo esc_attr(get_the_title($ev->product_id)); ?>">
                                        <?php echo esc_html(get_the_title($ev->product_id)); ?><?php echo ! empty($ev->is_test_event) ? ' 🧪 [TEST]' : ''; ?> (<?php echo esc_html(date('d/m/Y', strtotime($ev->event_date_start))); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="dfn-form-group" id="dfn-m-qb-date-wrap" style="display:none;">
                            <label for="dfn-m-qb-date">Data *</label>
                            <select id="dfn-m-qb-date" name="date" required>
                                <option value="">— Seleziona prima un evento —</option>
                            </select>
                        </div>

                        <!-- Step 2: Turno -->
                        <div class="dfn-form-group" id="dfn-m-qb-slot-wrap" style="display:none;">
                            <label for="dfn-m-qb-slot">Turno <span class="dfn-qb-optional">(opzionale)</span></label>
                            <select id="dfn-m-qb-slot" name="slot_id">
                                <option value="0">🤖 Auto — Smistamento automatico</option>
                            </select>
                        </div>

                        <!-- Step 3: Dati Prenotante -->
                        <div id="dfn-m-qb-guest-wrap" style="display:none;">
                            <div class="dfn-form-row">
                                <div class="dfn-form-group">
                                    <label for="dfn-m-qb-lastname">Cognome *</label>
                                    <input type="text" id="dfn-m-qb-lastname" name="last_name" required placeholder="Es. Rossi" />
                                </div>
                                <div class="dfn-form-group">
                                    <label for="dfn-m-qb-firstname">Nome <span class="dfn-qb-optional">(opzionale)</span></label>
                                    <input type="text" id="dfn-m-qb-firstname" name="first_name" placeholder="Es. Mario" />
                                </div>
                            </div>

                            <div class="dfn-form-row">
                                <div class="dfn-form-group">
                                    <label for="dfn-m-qb-qty-std">Posti Standard *</label>
                                    <input type="number" id="dfn-m-qb-qty-std" name="qty_standard" min="0" value="1" required />
                                </div>
                                <div class="dfn-form-group">
                                    <label for="dfn-m-qb-qty-fai">Soci FAI</label>
                                    <input type="number" id="dfn-m-qb-qty-fai" name="qty_fai" min="0" value="0" />
                                </div>
                            </div>

                            <!-- Tessere FAI dinamiche -->
                            <div id="dfn-m-qb-fai-cards-wrap" style="display:none; margin-bottom: 15px;">
                                <div class="dfn-qb-fai-header" style="font-weight: 700; margin-bottom: 8px; color: #004b23;">
                                    🏅 Dati tessere Soci FAI
                                </div>
                                <div id="dfn-m-qb-fai-cards-list"></div>
                            </div>

                            <div class="dfn-form-row">
                                <div class="dfn-form-group">
                                    <label for="dfn-m-qb-email">Email <span class="dfn-qb-optional">(opzionale)</span></label>
                                    <input type="email" id="dfn-m-qb-email" name="email" placeholder="mario.rossi@email.it" />
                                </div>
                                <div class="dfn-form-group">
                                    <label for="dfn-m-qb-phone">Telefono <span class="dfn-qb-optional">(opzionale)</span></label>
                                    <input type="tel" id="dfn-m-qb-phone" name="phone" placeholder="333 1234567" />
                                </div>
                            </div>

                            <div class="dfn-form-group">
                                <label for="dfn-m-qb-notes">Note <span class="dfn-qb-optional">(opzionale)</span></label>
                                <textarea id="dfn-m-qb-notes" name="notes" rows="2" placeholder="Richieste particolari, accessibilità..."></textarea>
                            </div>

                            <button type="submit" class="dfn-mobile-btn primary large" style="margin-top: 15px;">
                                ✅ Salva e Conferma Prenotazione
                            </button>
                        </div>
                    </form>
                </div>
            </section>


            <!-- TAB 4: BOTTEGHINO LIVE -->
            <section id="dfn-tab-botteghino" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card">
                    <h3>🎟️ Botteghino Live</h3>
                    <p class="dfn-subtitle">Gestisci le prenotazioni in sede: incassa in contanti, invia link di pagamento, riserva posti o registra l'incasso.</p>

                    <form id="dfn-mobile-botteghino-form" class="dfn-mobile-form">
                        <!-- Step 1: Evento, Data, Turno -->
                        <div class="dfn-form-group">
                            <label for="dfn-bot-event">Evento *</label>
                            <select id="dfn-bot-event" name="event_id" required>
                                <option value="">Seleziona Evento...</option>
                                <?php foreach ($events as $ev) : ?>
                                    <option value="<?php echo absint($ev->id); ?>"
                                            data-access="<?php echo esc_attr($ev->access_type ?? 'time_slots'); ?>"
                                            data-name="<?php echo esc_attr(get_the_title($ev->product_id)); ?>">
                                        <?php echo esc_html(get_the_title($ev->product_id)); ?> (<?php echo esc_html(date('d/m/Y', strtotime($ev->event_date_start))); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="dfn-form-group" id="dfn-bot-date-wrap" style="display:none;">
                            <label for="dfn-bot-date">Data *</label>
                            <select id="dfn-bot-date" name="date" required>
                                <option value="">— Seleziona prima un evento —</option>
                            </select>
                        </div>

                        <div class="dfn-form-group" id="dfn-bot-slot-wrap" style="display:none;">
                            <label for="dfn-bot-slot">Turno <span class="dfn-qb-optional">(opzionale)</span></label>
                            <select id="dfn-bot-slot" name="slot_id">
                                <option value="0">🤖 Auto — Smistamento automatico</option>
                            </select>
                        </div>

                        <!-- Step 2: Dati Prenotante & Biglietti -->
                        <div id="dfn-bot-guest-wrap" style="display:none;">
                            <div class="dfn-form-group" style="position:relative; background:#f0f6fc; padding:10px; border-radius:8px; border:1px solid #cbd5e1; margin-bottom:15px;">
                                <label for="dfn-bot-cust-search" style="font-weight:700; color:#1e293b;">🔍 Cerca Cliente Esistente <span class="dfn-qb-optional">(opzionale)</span></label>
                                <input type="text" id="dfn-bot-cust-search" placeholder="Digita nome o email cliente..." autocomplete="off" style="width:100%; margin-top:4px;" />
                                <div id="dfn-bot-cust-results" style="display:none; position:absolute; left:0; right:0; top:100%; z-index:99; background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; max-height:180px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.15);"></div>
                            </div>

                            <div class="dfn-form-row">
                                <div class="dfn-form-group">
                                    <label for="dfn-bot-firstname">Nome <span class="dfn-qb-optional">(opzionale)</span></label>
                                    <input type="text" id="dfn-bot-firstname" name="first_name" placeholder="Es. Mario" />
                                </div>
                                <div class="dfn-form-group">
                                    <label for="dfn-bot-lastname">Cognome *</label>
                                    <input type="text" id="dfn-bot-lastname" name="last_name" placeholder="Es. Rossi" required />
                                </div>
                            </div>

                            <div class="dfn-form-row">
                                <div class="dfn-form-group">
                                    <label for="dfn-bot-email">Email <span class="dfn-qb-optional">(opzionale)</span></label>
                                    <input type="email" id="dfn-bot-email" name="email" placeholder="mario.rossi@email.it" />
                                </div>
                                <div class="dfn-form-group">
                                    <label for="dfn-bot-phone">Telefono <span class="dfn-qb-optional">(opzionale)</span></label>
                                    <input type="tel" id="dfn-bot-phone" name="phone" placeholder="333 1234567" />
                                </div>
                            </div>

                            <div class="dfn-form-row">
                                <div class="dfn-form-group">
                                    <label for="dfn-bot-qty-std">Posti Standard *</label>
                                    <input type="number" id="dfn-bot-qty-std" name="qty_standard" min="0" value="1" required />
                                </div>
                                <div class="dfn-form-group">
                                    <label for="dfn-bot-qty-fai">Soci FAI</label>
                                    <input type="number" id="dfn-bot-qty-fai" name="qty_fai" min="0" value="0" />
                                </div>
                            </div>

                            <!-- Tessere FAI dinamiche -->
                            <div id="dfn-bot-fai-cards-wrap" style="display:none; margin-bottom: 15px;">
                                <div class="dfn-qb-fai-header" style="font-weight: 700; margin-bottom: 8px; color: #92400e; background:#fffbeb; padding:8px 12px; border-radius:6px; border:1px solid #f59e0b;">
                                    🏅 Dati tessere Soci FAI
                                </div>
                                <div id="dfn-bot-fai-cards-list"></div>
                            </div>

                            <div class="dfn-form-group">
                                <label for="dfn-bot-notes">Note <span class="dfn-qb-optional">(opzionale)</span></label>
                                <textarea id="dfn-bot-notes" name="notes" rows="2" placeholder="Richieste particolari, accessibilità..."></textarea>
                            </div>

                            <!-- Step 4: Salta Fila Auto Check-in -->
                            <div class="dfn-form-group" style="background:#eaf7ea; padding:12px; border-radius:8px; border:1px solid #c3e6c3; margin-bottom:15px;">
                                <label style="margin:0; display:flex; align-items:center; cursor:pointer; color:#166534; font-weight:700; font-size:14px;">
                                    <input type="checkbox" id="dfn-bot-auto-checkin" name="auto_checkin" value="1" style="margin-right:8px; width:18px; height:18px; flex-shrink:0;" />
                                    Salta fila: Valida automaticamente i biglietti
                                </label>
                            </div>

                            <!-- Step 5: Selezione Metodo di Pagamento con Select -->
                            <div class="dfn-form-group">
                                <label for="dfn-bot-payment">Metodo Pagamento / Modalità *</label>
                                <select id="dfn-bot-payment" name="payment_method" required>
                                    <option value="contanti">💵 Incassa in Contanti / POS</option>
                                    <option value="link">💳 Invia Link di Pagamento (Carta)</option>
                                    <option value="prenotazione">📋 Solo Prenotazione (Paga all'arrivo)</option>
                                    <option value="autorita">🎁 Riserva Posti Autorità (Omaggio)</option>
                                </select>
                            </div>

                            <button type="submit" class="dfn-mobile-btn success large" style="margin-top:15px; width:100%;">
                                💶 Emetti Biglietto & Registra Incasso
                            </button>
                        </div>
                    </form>
                </div>
            </section>


            <!-- TAB 5: AREA PERSONALE -->
            <section id="dfn-tab-profile" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card">
                    <div class="dfn-profile-card-header">
                        <div class="dfn-user-avatar large">
                            <?php echo esc_html($user_initials); ?>
                        </div>
                        <h3><?php echo esc_html($user_display_name); ?></h3>
                        <p class="dfn-user-email">📧 <?php echo esc_html($current_user->user_email); ?></p>
                        <span class="dfn-role-name-tag"><?php echo esc_html($primary_role_name); ?></span>
                    </div>

                    <form id="dfn-mobile-profile-form" class="dfn-mobile-form" style="margin-top:20px;">
                        <h4>Modifica Profilo & Password</h4>

                        <div class="dfn-form-group">
                            <label for="dfn-prof-name">Nome Visualizzato</label>
                            <input type="text" id="dfn-prof-name" name="display_name" value="<?php echo esc_attr($current_user->display_name); ?>" required />
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-prof-email">Email</label>
                            <input type="email" id="dfn-prof-email" name="user_email" value="<?php echo esc_attr($current_user->user_email); ?>" required />
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-prof-pass">Nuova Password (lascia vuoto se invariata)</label>
                            <input type="password" id="dfn-prof-pass" name="new_password" placeholder="Nuova password..." />
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-theme-toggle-select">Tema Grafico</label>
                            <select id="dfn-theme-toggle-select">
                                <option value="light">☀️ Tema Chiaro (Flat / Predefinito)</option>
                                <option value="dark">🌙 Tema Scuro</option>
                            </select>
                        </div>

                        <button type="submit" class="dfn-mobile-btn primary">
                            Salva Profilo
                        </button>
                    </form>

                    <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                        <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>" class="dfn-mobile-btn danger large" style="text-align:center; text-decoration:none; display:block;">
                            🚪 Disconnettiti / Logout
                        </a>
                    </div>
                </div>
            </section>

        </main>

        <!-- BOTTOM TAB BAR NAVIGATION -->
        <nav class="dfn-mobile-tab-bar">
            <button type="button" class="dfn-tab-btn active" data-tab="home">
                <span class="dashicons dashicons-dashboard"></span>
                <span class="dfn-tab-lbl">Home</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="scanner">
                <span class="dashicons dashicons-camera"></span>
                <span class="dfn-tab-lbl">Scanner</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="quick">
                <span class="dashicons dashicons-plus-alt2"></span>
                <span class="dfn-tab-lbl">Rapido</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="botteghino">
                <span class="dashicons dashicons-tickets-alt"></span>
                <span class="dfn-tab-lbl">Botteghino</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="profile">
                <span class="dashicons dashicons-admin-users"></span>
                <span class="dfn-tab-lbl">Profilo</span>
            </button>
        </nav>

        <!-- MODAL MOBILE CHECK-IN EVENTO -->
        <div id="dfn-mobile-checkin-modal" class="dfn-mobile-modal" style="display:none;">
            <div class="dfn-mobile-modal-content">
                <div class="dfn-modal-header">
                    <div>
                        <h3 id="dfn-mci-event-title">Check-in Evento</h3>
                        <span id="dfn-mci-event-subtitle" class="dfn-modal-subtitle"></span>
                    </div>
                    <button type="button" class="dfn-modal-close-btn" id="dfn-btn-close-checkin-modal">&times;</button>
                </div>

                <div class="dfn-mci-stats-box">
                    <div class="dfn-mci-stat">
                        <span class="val" id="dfn-mci-stat-booked">0</span>
                        <span class="lbl">Prenotati</span>
                    </div>
                    <div class="dfn-mci-stat success">
                        <span class="val" id="dfn-mci-stat-checked">0</span>
                        <span class="lbl">Entrati</span>
                    </div>
                    <div class="dfn-mci-stat warning">
                        <span class="val" id="dfn-mci-stat-remaining">0</span>
                        <span class="lbl">Rimanenti</span>
                    </div>
                </div>

                <div class="dfn-mci-progress-bar">
                    <div class="dfn-mci-progress-fill" id="dfn-mci-progress-fill" style="width: 0%;"></div>
                </div>

                <!-- Selector Data Evento (se più date) -->
                <div id="dfn-mci-date-wrap" style="display:none; margin: 12px 0 6px 0;">
                    <label for="dfn-mci-date-select" style="font-size:13px; font-weight:700; color:#1e293b; display:block; margin-bottom:4px;">
                        📅 Seleziona Data Evento:
                    </label>
                    <select id="dfn-mci-date-select" style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; font-weight:600; background:#ffffff; color:#1e293b;">
                        <option value="all">📅 Tutte le date</option>
                    </select>
                </div>

                <div class="dfn-mci-search-box">
                    <input type="text" id="dfn-mci-search-input" placeholder="🔍 Cerca nominativo, email o telefono..." />
                </div>

                <div id="dfn-mci-bookings-list" class="dfn-mobile-cards-list" style="margin-top:14px; max-height: 55vh; overflow-y: auto;">
                    <p style="text-align:center; padding:20px; color:#64748b;">Caricamento lista prenotazioni...</p>
                </div>
            </div>
        </div>

        <!-- MODALE 2: GESTIONE & DETTAGLI SINGOLA PRENOTAZIONE -->
        <div id="dfn-mobile-booking-details-modal" class="dfn-mobile-modal" style="display:none; z-index: 100050;">
            <div class="dfn-modal-content" style="max-width: 520px;">
                <div class="dfn-modal-header">
                    <div>
                        <h3 id="dfn-mbd-title">Gestione Prenotazione</h3>
                        <span class="dfn-modal-subtitle">Dettagli cliente ed azioni operative</span>
                    </div>
                    <button type="button" class="dfn-modal-close-btn" id="dfn-btn-close-mbd-modal">&times;</button>
                </div>
                <div class="dfn-modal-body" id="dfn-mbd-body" style="padding: 16px;">
                    <p style="text-align:center; padding:20px; color:#64748b;">Caricamento dettagli prenotazione...</p>
                </div>
            </div>
        </div>

        <!-- MODALE 3: SPOSTA TURNO / DATA PRENOTAZIONE -->
        <div id="dfn-mobile-move-slot-modal" class="dfn-mobile-modal" style="display:none; z-index: 100060;">
            <div class="dfn-modal-content" style="max-width: 480px;">
                <div class="dfn-modal-header">
                    <div>
                        <h3>✏️ Sposta Turno / Data</h3>
                        <span class="dfn-modal-subtitle">Riassegna la prenotazione a un nuovo turno</span>
                    </div>
                    <button type="button" class="dfn-modal-close-btn" id="dfn-btn-close-move-slot-modal">&times;</button>
                </div>
                <div class="dfn-modal-body" style="padding: 16px;">
                    <form id="dfn-mobile-move-slot-form">
                        <input type="hidden" id="dfn-move-booking-id" name="booking_id" value="0" />
                        
                        <div style="background:#f8fafc; border:1px solid #cbd5e1; padding:12px; border-radius:8px; margin-bottom:14px;">
                            <p style="margin:0; font-size:13px; color:#475569;">
                                Cliente: <strong id="dfn-move-customer-name" style="color:#0f172a;">-</strong><br>
                                Turno Attuale: <strong id="dfn-move-current-slot" style="color:#2563eb;">-</strong>
                            </p>
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-move-target-slot-select" style="font-size:13px; font-weight:700; color:#1e293b; display:block; margin-bottom:6px;">
                                📅 Seleziona Nuovo Turno / Data Disponibile:
                            </label>
                            <select id="dfn-move-target-slot-select" name="to_slot_id" required style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:14px; font-weight:600; background:#ffffff;">
                                <option value="">Caricamento turni...</option>
                            </select>
                        </div>

                        <div class="dfn-form-group" style="margin-top:12px;">
                            <label style="display:flex; align-items:center; cursor:pointer; font-size:13px; color:#334155; font-weight:600;">
                                <input type="checkbox" id="dfn-move-notify-customer" name="notify" value="1" checked style="width:18px; height:18px; margin-right:8px;" />
                                📧 Invia email di conferma aggiornata al cliente
                            </label>
                        </div>

                        <div style="margin-top:20px; display:flex; gap:10px;">
                            <button type="button" id="dfn-btn-cancel-move-slot" class="dfn-mobile-btn secondary" style="flex:1;">
                                Annulla
                            </button>
                            <button type="submit" class="dfn-mobile-btn success" style="flex:2;">
                                💾 Conferma Spostamento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODALE 4: LOG STORICO OPERAZIONI CLIENTE -->
        <div id="dfn-mobile-history-modal" class="dfn-mobile-modal" style="display:none; z-index: 100070;">
            <div class="dfn-modal-content" style="max-width: 540px;">
                <div class="dfn-modal-header">
                    <div>
                        <h3>📜 Log Operazioni Cliente</h3>
                        <span class="dfn-modal-subtitle" id="dfn-history-customer-subtitle">Storico interventi</span>
                    </div>
                    <button type="button" class="dfn-modal-close-btn" id="dfn-btn-close-history-modal">&times;</button>
                </div>
                <div class="dfn-modal-body" style="padding: 16px;">
                    <div id="dfn-history-list-content" style="max-height:55vh; overflow-y:auto; overflow-x:auto; background:#f8fafc; border:1px solid #cbd5e1; padding:12px; border-radius:8px; font-family:monospace; font-size:13px; line-height:1.6;">
                        <p style="text-align:center; color:#64748b;">Nessuna registrazione trovata.</p>
                    </div>
                    <button type="button" id="dfn-btn-close-history-bottom" class="dfn-mobile-btn secondary" style="margin-top:14px; width:100%;">
                        Chiudi Log
                    </button>
                </div>
            </div>
        </div>

        <!-- TOAST FEEDBACK NOTIFICATIONS -->
        <div id="dfn-mobile-toast" class="dfn-mobile-toast" style="display:none;"></div>
    </div>

    <?php
}

/**
 * Gestisce il submit della form di login mobile PRIMA dell'output HTML (hook template_redirect).
 *
 * @return void
 */
function dfn_handle_mobile_login_submit(): void
{
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
        return;
    }

    if (empty($_POST['dfn_mobile_login_nonce']) || ! wp_verify_nonce($_POST['dfn_mobile_login_nonce'], 'dfn_mobile_login_action')) {
        return;
    }

    $raw_user = trim(sanitize_text_field($_POST['log'] ?? ''));
    $raw_pass = $_POST['pwd'] ?? '';

    if (empty($raw_user) || empty($raw_pass)) {
        return;
    }

    // Se l'utente ha inserito un'email (con eventuale spazio da tastiera mobile), risolvila al nome utente
    if (is_email($raw_user)) {
        $user_obj = get_user_by('email', $raw_user);
        if ($user_obj) {
            $raw_user = $user_obj->user_login;
        }
    }

    $creds = [
        'user_login'    => $raw_user,
        'user_password' => $raw_pass,
        'remember'      => true,
    ];

    $user = wp_signon($creds, is_ssl());

    if (is_wp_error($user)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        set_transient('dfn_mobile_login_err_' . md5($ip), $user->get_error_message(), 60);
        $target = add_query_arg('login_error', '1', wp_get_referer() ?: get_permalink());
        nocache_headers();
        wp_safe_redirect($target);
        exit;
    }

    nocache_headers();
    $target = remove_query_arg('login_error', wp_get_referer() ?: get_permalink());
    wp_safe_redirect($target);
    exit;
}
add_action('template_redirect', 'dfn_handle_mobile_login_submit', 5);

// Disabilita il caching delle pagine per l'App Mobile (/gestione-eventi/)
add_action('template_redirect', function () {
    if (is_page('gestione-eventi') || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'gestione-eventi') !== false)) {
        nocache_headers();
    }
}, 1);

/**
 * Renderizza la schermata di login mobile per utenti non autenticati.
 *
 * @return void
 */
function dfn_render_mobile_login(): void
{
    $login_error = '';
    if (isset($_GET['login_error'])) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $transient_key = 'dfn_mobile_login_err_' . md5($ip);
        $err_msg = get_transient($transient_key);
        if ($err_msg) {
            $login_error = 'Credenziali non corrette. Riprova.';
            delete_transient($transient_key);
        } else {
            $login_error = 'Credenziali non corrette. Riprova.';
        }
    }
    ?>
    <div class="dfn-mobile-login-wrapper">
        <div class="dfn-mobile-login-card">
            <div class="dfn-login-header">
                <img src="/app/uploads/2026/07/cropped-logo_fai_trasparente.png" class="dfn-login-logo-img" alt="FAI Logo" />
                <h2>FAI Novara — Gestione</h2>
                <p>Accedi con il tuo account operatore o amministratore per accedere all'App Mobile.</p>
            </div>

            <?php if ($login_error) : ?>
                <div class="dfn-login-alert error">
                    ⚠️ <?php echo esc_html($login_error); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="dfn-mobile-form">
                <?php wp_nonce_field('dfn_mobile_login_action', 'dfn_mobile_login_nonce'); ?>
                
                <div class="dfn-form-group">
                    <label for="dfn-log-username">Nome Utente o Email</label>
                    <input type="text" id="dfn-log-username" name="log" required placeholder="Inserisci username..." autocomplete="username" />
                </div>

                <div class="dfn-form-group">
                    <label for="dfn-log-password">Password</label>
                    <input type="password" id="dfn-log-password" name="pwd" required placeholder="Inserisci password..." autocomplete="current-password" />
                </div>

                <button type="submit" class="dfn-mobile-btn primary large">
                    Accedi
                </button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Renderizza la schermata di accesso negato se l'utente non possiede i ruoli autorizzati.
 *
 * @param WP_User $user Utente loggato.
 * @return void
 */
function dfn_render_mobile_access_denied(WP_User $user): void
{
    ?>
    <div class="dfn-mobile-login-wrapper">
        <div class="dfn-mobile-login-card">
            <div class="dfn-login-header">
                <span class="dfn-login-logo">⛔</span>
                <h2>Accesso Riservato</h2>
                <p>L'account <strong><?php echo esc_html($user->user_login); ?></strong> non dispone delle autorizzazioni necessarie per accedere all'App di gestione eventi.</p>
            </div>
            <div style="margin-top:20px;">
                <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>" class="dfn-mobile-btn secondary large" style="text-align:center; display:block; text-decoration:none;">
                    Disconnettiti
                </a>
            </div>
        </div>
    </div>
    <?php
}

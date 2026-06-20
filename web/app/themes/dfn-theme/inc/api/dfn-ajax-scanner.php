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

if (! defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_dfn_process_scan', 'dfn_process_scan_ajax_handler');
add_action('wp_ajax_dfn_consolidate_in_loco_payment', 'dfn_consolidate_in_loco_payment_ajax_handler');

/**
 * Processa la scansione del codice QR convalidando l'accesso o chiedendo l'incasso.
 *
 * @return void
 */
function dfn_process_scan_ajax_handler(): void
{
    check_ajax_referer('dfn_scanner_nonce', 'security');

    if (! current_user_can('dfn_use_scanner')) {
        wp_send_json_error([ 'message' => esc_html__('Non hai i permessi necessari per usare lo scanner.', 'dfn-theme') ]);
    }

    $qr_token_raw = isset($_POST['qr_token']) ? sanitize_text_field($_POST['qr_token']) : '';
    if (empty($qr_token_raw)) {
        wp_send_json_error([ 'message' => esc_html__('Token QR non fornito.', 'dfn-theme') ]);
    }

    // Estrae l'eventuale slot_id accodato (es: TOKEN-SLOTID)
    $parts = explode('-', $qr_token_raw);
    $qr_token = $parts[0];
    $target_slot_id = isset($parts[1]) ? intval($parts[1]) : 0;

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // Cerca il booking nel DB
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE qr_token = %s LIMIT 1",
        $qr_token,
    ));

    if (! $booking) {
        wp_send_json_error([ 'message' => esc_html__('Codice QR non valido o prenotazione inesistente.', 'dfn-theme') ]);
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        wp_send_json_error([ 'message' => esc_html__('Ordine WooCommerce correlato non trovato.', 'dfn-theme') ]);
    }

    // Se stiamo scansionando uno slot specifico
    if ($target_slot_id > 0) {
        $slot_assoc = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dfn_booking_slots WHERE booking_id = %d AND slot_id = %d",
            $booking->id,
            $target_slot_id,
        ));
        if (! $slot_assoc) {
            wp_send_json_error([ 'message' => esc_html__('Questo turno non appartiene alla prenotazione.', 'dfn-theme') ]);
        }

        if (! empty($slot_assoc->checked_in_at)) {
            $validated_by = 'Staff';
            if ($slot_assoc->checked_in_by) {
                $user_info = get_userdata($slot_assoc->checked_in_by);
                if ($user_info) {
                    $validated_by = $user_info->display_name;
                }
            }

            wp_send_json_success([
                'status'         => 'checked_in',
                'customer_name'  => $booking->customer_name . ' (Turno Singolo)',
                'total_persons'  => $slot_assoc->persons,
                'checked_in_at'  => date_i18n('d/m/Y - H:i:s', strtotime($slot_assoc->checked_in_at)),
                'checked_in_by'  => $validated_by,
            ]);
        }
    } else {
        // Se il biglietto intero è già stato scansionato (utente entrato)
        if ($booking->status === 'checked_in') {
            $validated_by = 'Staff';
            if ($booking->checked_in_by) {
                $user_info = get_userdata($booking->checked_in_by);
                if ($user_info) {
                    $validated_by = $user_info->display_name;
                }
            }

            wp_send_json_success([
                'status'         => 'checked_in',
                'customer_name'  => $booking->customer_name,
                'total_persons'  => $booking->total_persons,
                'checked_in_at'  => date_i18n('d/m/Y - H:i:s', strtotime($booking->checked_in_at)),
                'checked_in_by'  => $validated_by,
            ]);
        }
    }

    // Recupera informazioni evento
    $event_title = get_the_title($booking->event_id) ?: esc_html__('Evento FAI', 'dfn-theme');

    // Se l'ordine è con pagamento all'ingresso (In Loco) ed è ancora pendente
    if ($order->get_payment_method() === 'dfn_in_loco' && $order->has_status('pending')) {
        // Cerca i dati del listino dell'evento per mostrare il breakdown
        $event = dfn_db_get_event($booking->event_id);
        $price_standard = $event ? floatval($event->price_standard) : 0.00;
        $price_fai      = $event ? floatval($event->price_fai) : 0.00;

        wp_send_json_success([
            'payment_required'       => true,
            'customer_name'          => $booking->customer_name,
            'total_persons'          => $booking->total_persons,
            'persons_standard'       => $booking->persons_standard,
            'persons_fai'            => $booking->persons_fai,
            'event_title'            => $event_title,
            'price_standard_formatted' => wc_price($price_standard),
            'price_fai_formatted'    => wc_price($price_fai),
            'amount_due_formatted'   => wc_price(floatval($booking->amount_due)),
        ]);
    }

    // Se l'ordine è pagato online (processing / completed) o l'ingresso è gratuito (saldo zero)
    if ($order->has_status([ 'processing', 'completed' ]) || floatval($order->get_total()) === 0.00) {
        if ($target_slot_id > 0) {
            // Aggiorna lo slot specifico
            $wpdb->update(
                $wpdb->prefix . 'dfn_booking_slots',
                [
                    'checked_in_at' => current_time('mysql'),
                    'checked_in_by' => get_current_user_id(),
                ],
                [ 'booking_id' => $booking->id, 'slot_id' => $target_slot_id ],
                [ '%s', '%d' ],
                [ '%d', '%d' ],
            );

            // Controlla se tutti gli altri slot della prenotazione sono completati
            $unconfirmed = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_booking_slots WHERE booking_id = %d AND checked_in_at IS NULL",
                $booking->id,
            ));

            if (intval($unconfirmed) === 0) {
                $wpdb->update(
                    $table_bookings,
                    [
                        'status'        => 'checked_in',
                        'checked_in_at' => current_time('mysql'),
                        'checked_in_by' => get_current_user_id(),
                    ],
                    [ 'id' => $booking->id ],
                    [ '%s', '%s', '%d' ],
                    [ '%d' ],
                );
            }

            wp_send_json_success([
                'status'         => 'success',
                'customer_name'  => $booking->customer_name . ' (Turno)',
                'total_persons'  => $wpdb->get_var($wpdb->prepare("SELECT persons FROM {$wpdb->prefix}dfn_booking_slots WHERE booking_id = %d AND slot_id = %d", $booking->id, $target_slot_id)),
                'event_title'    => $event_title,
                'order_id'       => $booking->order_id,
            ]);
        } else {
            // Aggiorna il record di ingresso globale
            $wpdb->update(
                $table_bookings,
                [
                    'status'        => 'checked_in',
                    'checked_in_at' => current_time('mysql'),
                    'checked_in_by' => get_current_user_id(),
                ],
                [ 'id' => $booking->id ],
                [ '%s', '%s', '%d' ],
                [ '%d' ],
            );

            // Per sicurezza, smarca tutti gli slot
            $wpdb->update(
                $wpdb->prefix . 'dfn_booking_slots',
                [
                    'checked_in_at' => current_time('mysql'),
                    'checked_in_by' => get_current_user_id(),
                ],
                [ 'booking_id' => $booking->id ],
                [ '%s', '%d' ],
                [ '%d' ],
            );

            wp_send_json_success([
                'status'         => 'success',
                'customer_name'  => $booking->customer_name,
                'total_persons'  => $booking->total_persons,
                'event_title'    => $event_title,
                'order_id'       => $booking->order_id,
            ]);
        }
    }

    wp_send_json_error([ 'message' => esc_html__('Impossibile convalidare l\'accesso. L\'ordine risulta annullato o non valido.', 'dfn-theme') ]);
}

/**
 * Consolida il saldo fisico registrato al banchetto ed effettua l'ingresso del gruppo.
 *
 * @return void
 */
function dfn_consolidate_in_loco_payment_ajax_handler(): void
{
    check_ajax_referer('dfn_scanner_nonce', 'security');

    if (! current_user_can('dfn_checkin_and_collect')) {
        wp_send_json_error([ 'message' => esc_html__('Non hai le autorizzazioni per incassare i contributi.', 'dfn-theme') ]);
    }

    $qr_token_raw = isset($_POST['qr_token']) ? sanitize_text_field($_POST['qr_token']) : '';
    $method   = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'cash'; // cash o pos

    if (empty($qr_token_raw)) {
        wp_send_json_error([ 'message' => esc_html__('Token QR non valido.', 'dfn-theme') ]);
    }

    $parts = explode('-', $qr_token_raw);
    $qr_token = $parts[0];
    $target_slot_id = isset($parts[1]) ? intval($parts[1]) : 0;

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // Cerca il booking
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE qr_token = %s LIMIT 1",
        $qr_token,
    ));

    if (! $booking) {
        wp_send_json_error([ 'message' => esc_html__('Prenotazione non trovata.', 'dfn-theme') ]);
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        wp_send_json_error([ 'message' => esc_html__('Ordine WooCommerce non trovato.', 'dfn-theme') ]);
    }

    // Inizia la transazione sicura
    $wpdb->query('START TRANSACTION');

    try {
        // 1. Aggiorna lo stato dell'ordine WooCommerce a "completed"
        $method_title = ($method === 'pos') ? __('Contributo In Loco (POS/Carta)', 'dfn-theme') : __('Contributo In Loco (Contanti)', 'dfn-theme');
        $order->update_meta_data('_dfn_physical_payment_method', $method);
        $order->update_meta_data('_dfn_collected_by', (string) get_current_user_id());
        $order->update_meta_data('_dfn_collected_at', current_time('mysql'));
        $order->update_status('completed', sprintf(__('Contributo riscosso in loco tramite %s.', 'dfn-theme'), $method_title));
        $order->save();

        // 2. Aggiorna la prenotazione a checked_in e registra il metodo d'incasso
        if ($target_slot_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'dfn_booking_slots',
                [
                    'checked_in_at' => current_time('mysql'),
                    'checked_in_by' => get_current_user_id(),
                ],
                [ 'booking_id' => $booking->id, 'slot_id' => $target_slot_id ],
                [ '%s', '%d' ],
                [ '%d', '%d' ],
            );

            // Controlla se tutti sono smarcati
            $unconfirmed = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_booking_slots WHERE booking_id = %d AND checked_in_at IS NULL",
                $booking->id,
            ));

            $new_status = (intval($unconfirmed) === 0) ? 'checked_in' : $booking->status;
        } else {
            $new_status = 'checked_in';

            // Smarca tutti gli slot per sicurezza
            $wpdb->update(
                $wpdb->prefix . 'dfn_booking_slots',
                [
                    'checked_in_at' => current_time('mysql'),
                    'checked_in_by' => get_current_user_id(),
                ],
                [ 'booking_id' => $booking->id ],
                [ '%s', '%d' ],
                [ '%d' ],
            );
        }

        $wpdb->update(
            $table_bookings,
            [
                'status'         => $new_status,
                'payment_method' => ($method === 'pos') ? 'in_loco_pos' : 'in_loco_cash',
                'amount_paid'    => $booking->amount_due,
                'checked_in_at'  => current_time('mysql'),
                'checked_in_by'  => get_current_user_id(),
            ],
            [ 'id' => $booking->id ],
            [ '%s', '%s', '%f', '%s', '%d' ],
            [ '%d' ],
        );

        $wpdb->query('COMMIT');
    } catch (\Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error([ 'message' => esc_html__('Errore critico del database durante il consolidamento del pagamento.', 'dfn-theme') ]);
    }

    // Recupera informazioni aggiornate per la risposta dello scanner
    $validated_by = 'Staff';
    $user_info = get_userdata(get_current_user_id());
    if ($user_info) {
        $validated_by = $user_info->display_name;
    }

    wp_send_json_success([
        'status'         => 'checked_in',
        'customer_name'  => $booking->customer_name,
        'total_persons'  => $booking->total_persons,
        'checked_in_at'  => date_i18n('d/m/Y - H:i:s'),
        'checked_in_by'  => $validated_by,
    ]);
}

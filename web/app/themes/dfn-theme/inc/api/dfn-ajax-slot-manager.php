<?php

/**
 * DFN Booking System 2.0 — AJAX Slot Manager Endpoints
 *
 * Gestisce tutte le operazioni AJAX per la pagina "Gestione Turni" dell'admin.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Registrazione degli hook AJAX per l'area amministrativa
add_action('wp_ajax_dfn_admin_get_slots', 'dfn_ajax_admin_get_slots');
add_action('wp_ajax_dfn_admin_generate_initial_slots', 'dfn_ajax_admin_generate_initial_slots');
add_action('wp_ajax_dfn_admin_add_slot', 'dfn_ajax_admin_add_slot');
add_action('wp_ajax_dfn_admin_update_slot', 'dfn_ajax_admin_update_slot');
add_action('wp_ajax_dfn_admin_lock_slot', 'dfn_ajax_admin_lock_slot');
add_action('wp_ajax_dfn_admin_delete_slot', 'dfn_ajax_admin_delete_slot');
add_action('wp_ajax_dfn_admin_add_booking', 'dfn_ajax_admin_add_booking');
add_action('wp_ajax_dfn_admin_move_booking', 'dfn_ajax_admin_move_booking');
add_action('wp_ajax_dfn_admin_delete_booking', 'dfn_ajax_admin_delete_booking');

/**
 * Verifica i permessi di sicurezza dell'amministratore.
 */
function dfn_ajax_admin_verify_access(): void
{
    if (! current_user_can('manage_options') && ! current_user_can('edit_pages')) {
        wp_send_json_error([ 'message' => esc_html__('Permessi insufficienti.', 'dfn-theme') ]);
    }
    check_ajax_referer('dfn_admin_events_nonce', 'nonce');
}

/**
 * 1. Carica slot + prenotazioni per un evento/data
 */
function dfn_ajax_admin_get_slots(): void
{
    dfn_ajax_admin_verify_access();

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $date     = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

    if ($event_id <= 0 || empty($date)) {
        wp_send_json_error([ 'message' => esc_html__('Parametri mancanti.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';

    // --- Gestione speciale per eventi a Flusso Libero (free_flow) ---
    // Questi eventi non hanno slot fisici: costruiamo uno slot virtuale
    // contenente tutte le prenotazioni dell'evento (con created_at nella data richiesta)
    $event = dfn_db_get_event($event_id);
    if ($event && 'free_flow' === $event->access_type) {
        $bookings_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_bookings}
             WHERE event_id = %d
               AND DATE(created_at) = %s
               AND status != 'cancelled'
             ORDER BY created_at ASC",
            $event_id,
            $date,
        ));

        // Se nessuna prenotazione nella data specifica, mostra comunque tutte le prenotazioni dell'evento
        if (empty($bookings_raw)) {
            $bookings_raw = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_bookings}
                 WHERE event_id = %d AND status != 'cancelled'
                 ORDER BY created_at ASC",
                $event_id,
            ));
        }

        $bookings_list = [];
        foreach ($bookings_raw as $b) {
            $fai_cards = [];
            if ($b->order_id) {
                $order = wc_get_order($b->order_id);
                if ($order) {
                    $fai_cards = $order->get_meta('_dfn_fai_cards') ?: [];
                }
            }
            $bookings_list[] = [
                'id'               => intval($b->id),
                'order_id'         => intval($b->order_id),
                'customer_name'    => esc_html($b->customer_name),
                'customer_email'   => esc_html($b->customer_email),
                'customer_phone'   => esc_html($b->customer_phone),
                'total_persons'    => intval($b->total_persons),
                'persons_standard' => intval($b->persons_standard),
                'persons_fai'      => intval($b->persons_fai),
                'slot_persons'     => intval($b->total_persons),
                'status'           => esc_html($b->status),
                'qr_token'         => esc_html($b->qr_token),
                'notes'            => esc_html($b->notes),
                'created_at'       => esc_html($b->created_at),
                'fai_cards'        => $fai_cards,
            ];
        }

        $total_booked = array_sum(array_column($bookings_list, 'total_persons'));

        // Slot virtuale: id=0 segnala al JS che è flusso libero
        $virtual_slot = [
            'id'             => 0,
            'time_start'     => date('H:i', strtotime($event->event_time_start)),
            'time_end'       => $event->event_time_end ? date('H:i', strtotime($event->event_time_end)) : '23:59',
            'capacity'       => intval($event->total_capacity),
            'bonus_capacity' => 0,
            'booked_count'   => $total_booked,
            'is_locked'      => 0,
            'is_free_flow'   => true,
            'bookings'       => $bookings_list,
        ];

        wp_send_json_success([ 'slots' => [ $virtual_slot ] ]);
        return;
    }
    // --- Fine gestione free_flow ---

    // Recupera slot ordinati per orario di inizio
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_slots} WHERE event_id = %d AND slot_date = %s ORDER BY slot_time_start ASC",
        $event_id,
        $date,
    ));

    $slots_data = [];
    foreach ($slots as $slot) {
        // Recupera le prenotazioni collegate a questo slot
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, bs.persons as slot_persons 
             FROM {$table_bookings} b
             INNER JOIN {$table_booking_slots} bs ON b.id = bs.booking_id
             WHERE bs.slot_id = %d AND b.status != 'cancelled'
             ORDER BY b.created_at ASC",
            $slot->id,
        ));

        $bookings_list = [];
        foreach ($bookings as $b) {
            $fai_cards = [];
            if ($b->order_id) {
                $order = wc_get_order($b->order_id);
                if ($order) {
                    $fai_cards = $order->get_meta('_dfn_fai_cards') ?: [];
                }
            }
            $bookings_list[] = [
                'id'               => intval($b->id),
                'order_id'         => intval($b->order_id),
                'customer_name'    => esc_html($b->customer_name),
                'customer_email'   => esc_html($b->customer_email),
                'customer_phone'   => esc_html($b->customer_phone),
                'total_persons'    => intval($b->total_persons),
                'persons_standard' => intval($b->persons_standard),
                'persons_fai'      => intval($b->persons_fai),
                'slot_persons'     => intval($b->slot_persons),
                'status'           => esc_html($b->status),
                'qr_token'         => esc_html($b->qr_token),
                'notes'            => esc_html($b->notes),
                'created_at'       => esc_html($b->created_at),
                'fai_cards'        => $fai_cards,
            ];
        }

        $slots_data[] = [
            'id'             => intval($slot->id),
            'time_start'     => substr($slot->slot_time_start, 0, 5),
            'time_end'       => substr($slot->slot_time_end, 0, 5),
            'capacity'       => intval($slot->capacity),
            'bonus_capacity' => intval($slot->bonus_capacity),
            'booked_count'   => intval($slot->booked_count),
            'is_locked'      => intval($slot->is_locked),
            'bookings'       => $bookings_list,
        ];
    }

    wp_send_json_success([ 'slots' => $slots_data ]);
}


/**
 * 2. Genera slot iniziali per un evento che non ne ha
 */
function dfn_ajax_admin_generate_initial_slots(): void
{
    dfn_ajax_admin_verify_access();

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    if ($event_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('ID evento non valido.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_slots} WHERE event_id = %d", $event_id));

    if ($count > 0) {
        wp_send_json_error([ 'message' => esc_html__('Questo evento ha già degli slot generati.', 'dfn-theme') ]);
    }

    if (! function_exists('dfn_db_generate_slots_for_event')) {
        require_once get_template_directory() . '/inc/core/dfn-database.php';
    }

    $generated = dfn_db_generate_slots_for_event($event_id);
    if ($generated > 0) {
        wp_send_json_success([
            'message' => sprintf(esc_html__('Generati con successo %d slot orari per l\'evento.', 'dfn-theme'), $generated),
        ]);
    } else {
        wp_send_json_error([ 'message' => esc_html__('Impossibile generare gli slot. Verifica le date e le fasce orarie impostate nell\'evento.', 'dfn-theme') ]);
    }
}

/**
 * 3. Aggiunge un singolo slot orario personalizzato
 */
function dfn_ajax_admin_add_slot(): void
{
    dfn_ajax_admin_verify_access();

    $event_id   = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $date       = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $time_start = isset($_POST['time_start']) ? sanitize_text_field($_POST['time_start']) : '';
    $time_end   = isset($_POST['time_end']) ? sanitize_text_field($_POST['time_end']) : '';
    $capacity   = isset($_POST['capacity']) ? intval($_POST['capacity']) : 0;
    $bonus      = isset($_POST['bonus_capacity']) ? intval($_POST['bonus_capacity']) : 0;

    if ($event_id <= 0 || empty($date) || empty($time_start) || empty($time_end) || $capacity < 0 || $bonus < 0) {
        wp_send_json_error([ 'message' => esc_html__('Dati non validi o mancanti.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';

    // Formatta orari per sicurezza (es. da HH:MM a HH:MM:SS)
    $time_start_db = date('H:i:s', strtotime($time_start));
    $time_end_db   = date('H:i:s', strtotime($time_end));

    // Verifica se esiste già uno slot identico
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_slots} WHERE event_id = %d AND slot_date = %s AND slot_time_start = %s",
        $event_id,
        $date,
        $time_start_db,
    ));

    if ($exists) {
        wp_send_json_error([ 'message' => esc_html__('Esiste già uno slot per questo orario di inizio in questa data.', 'dfn-theme') ]);
    }

    $inserted = $wpdb->insert(
        $table_slots,
        [
            'event_id'        => $event_id,
            'slot_date'       => $date,
            'slot_time_start' => $time_start_db,
            'slot_time_end'   => $time_end_db,
            'capacity'        => $capacity,
            'bonus_capacity'  => $bonus,
            'booked_count'    => 0,
            'is_locked'       => 0,
        ],
        [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ],
    );

    if ($inserted) {
        wp_send_json_success([ 'message' => esc_html__('Slot orario aggiunto con successo.', 'dfn-theme') ]);
    } else {
        wp_send_json_error([ 'message' => esc_html__('Errore nel salvataggio dello slot.', 'dfn-theme') ]);
    }
}

/**
 * 4. Modifica capacità/bonus di uno slot esistente
 */
function dfn_ajax_admin_update_slot(): void
{
    dfn_ajax_admin_verify_access();

    $slot_id  = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
    $capacity = isset($_POST['capacity']) ? intval($_POST['capacity']) : 0;
    $bonus    = isset($_POST['bonus_capacity']) ? intval($_POST['bonus_capacity']) : 0;

    if ($slot_id <= 0 || $capacity < 0 || $bonus < 0) {
        wp_send_json_error([ 'message' => esc_html__('Dati non validi.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';

    $updated = $wpdb->update(
        $table_slots,
        [
            'capacity'       => $capacity,
            'bonus_capacity' => $bonus,
        ],
        [ 'id' => $slot_id ],
        [ '%d', '%d' ],
        [ '%d' ],
    );

    if ($updated !== false) {
        wp_send_json_success([ 'message' => esc_html__('Slot aggiornato con successo.', 'dfn-theme') ]);
    } else {
        wp_send_json_error([ 'message' => esc_html__('Impossibile aggiornare lo slot.', 'dfn-theme') ]);
    }
}

/**
 * 5. Lock/Unlock uno slot
 */
function dfn_ajax_admin_lock_slot(): void
{
    dfn_ajax_admin_verify_access();

    $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
    $lock    = isset($_POST['lock']) ? intval($_POST['lock']) : 0;

    if ($slot_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Slot non valido.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';

    $updated = $wpdb->update(
        $table_slots,
        [ 'is_locked' => $lock ? 1 : 0 ],
        [ 'id' => $slot_id ],
        [ '%d' ],
        [ '%d' ],
    );

    if ($updated !== false) {
        $msg = $lock ? esc_html__('Slot bloccato con successo.', 'dfn-theme') : esc_html__('Slot sbloccato con successo.', 'dfn-theme');
        wp_send_json_success([ 'message' => $msg ]);
    } else {
        wp_send_json_error([ 'message' => esc_html__('Errore durante la modifica dello stato di blocco.', 'dfn-theme') ]);
    }
}

/**
 * 6. Elimina uno slot solo se vuoto
 */
function dfn_ajax_admin_delete_slot(): void
{
    dfn_ajax_admin_verify_access();

    $slot_id = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;

    if ($slot_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Slot non valido.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';

    // Verifica se è vuoto
    $slot = $wpdb->get_row($wpdb->prepare("SELECT booked_count FROM {$table_slots} WHERE id = %d", $slot_id));
    if (! $slot) {
        wp_send_json_error([ 'message' => esc_html__('Slot non trovato.', 'dfn-theme') ]);
    }

    if (intval($slot->booked_count) > 0) {
        wp_send_json_error([ 'message' => esc_html__('Non puoi eliminare uno slot che contiene delle prenotazioni attive. Sposta prima i visitatori in altri slot.', 'dfn-theme') ]);
    }

    $deleted = $wpdb->delete($table_slots, [ 'id' => $slot_id ], [ '%d' ]);

    if ($deleted) {
        wp_send_json_success([ 'message' => esc_html__('Slot eliminato con successo.', 'dfn-theme') ]);
    } else {
        wp_send_json_error([ 'message' => esc_html__('Errore nell\'eliminazione dello slot.', 'dfn-theme') ]);
    }
}

/**
 * 7. Inserimento manuale di una prenotazione
 */
function dfn_ajax_admin_add_booking(): void
{
    dfn_ajax_admin_verify_access();

    $event_id     = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $slot_id      = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
    $date         = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $first_name   = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name    = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $email        = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone        = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $qty_standard = isset($_POST['qty_standard']) ? intval($_POST['qty_standard']) : 0;
    $qty_fai      = isset($_POST['qty_fai']) ? intval($_POST['qty_fai']) : 0;
    $notes        = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $fai_cards_raw = isset($_POST['fai_cards']) ? $_POST['fai_cards'] : [];

    // Se l'email è vuota, usiamo una mail fittizia per evitare problemi col database e con WooCommerce
    if (empty($email)) {
        $email = 'no-email@dfn.it';
    }

    // slot_id = 0 è permesso per eventi free_flow (non hanno slot fisici)
    if ($event_id <= 0 || empty($date) || empty($first_name) || empty($last_name)) {
        wp_send_json_error([ 'message' => esc_html__('I campi Nome e Cognome dell\'acquirente sono obbligatori.', 'dfn-theme') ]);
    }

    $total_qty = $qty_standard + $qty_fai;
    if ($total_qty <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Specifica almeno un biglietto.', 'dfn-theme') ]);
    }

    if (! function_exists('dfn_db_get_event')) {
        require_once get_template_directory() . '/inc/core/dfn-database.php';
    }

    $event = dfn_db_get_event($event_id);
    if (! $event) {
        wp_send_json_error([ 'message' => esc_html__('Evento non valido.', 'dfn-theme') ]);
    }

    // Per eventi time_slots, lo slot_id è obbligatorio
    $is_free_flow = ('free_flow' === $event->access_type);
    if (! $is_free_flow && $slot_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Seleziona un turno valido.', 'dfn-theme') ]);
    }

    global $wpdb;

    // Processa tessere FAI
    $fai_cards = [];
    if ($qty_fai > 0 && is_array($fai_cards_raw)) {
        $table_members = $wpdb->prefix . 'dfn_fai_members';
        foreach ($fai_cards_raw as $index => $card_data) {
            $c_nome    = isset($card_data['nome']) ? sanitize_text_field($card_data['nome']) : '';
            $c_cognome = isset($card_data['cognome']) ? sanitize_text_field($card_data['cognome']) : '';
            $c_num     = isset($card_data['tessera']) ? sanitize_text_field($card_data['tessera']) : '';
            // Data di scadenza vuota di default al momento dell'inserimento per verifica admin successiva
            $c_expiry  = null;

            if (empty($c_nome) || empty($c_cognome) || empty($c_num)) {
                wp_send_json_error([ 'message' => sprintf(esc_html__('Dati tessera Socio FAI incompleti per il partecipante #%d.', 'dfn-theme'), $index + 1) ]);
            }

            // Inserisci o aggiorna nel DB (stato da verificare = 0, scadenza NULL/vuota)
            $wpdb->insert(
                $table_members,
                [
                    'first_name'  => $c_nome,
                    'last_name'   => $c_cognome,
                    'email'       => ($email === 'no-email@dfn.it') ? null : $email,
                    'card_number' => $c_num,
                    'card_expiry' => $c_expiry,
                    'verified'    => 0,
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%d' ],
            );

            $fai_cards[] = [
                'nome'    => $c_nome,
                'cognome' => $c_cognome,
                'tessera' => $c_num,
            ];
        }
    }

    // Creazione programmatica dell'ordine WooCommerce
    try {
        if (! function_exists('wc_create_order')) {
            wp_send_json_error([ 'message' => esc_html__('WooCommerce non è attivo.', 'dfn-theme') ]);
        }

        $order = wc_create_order();
        $product = wc_get_product($event->product_id);
        if (! $product) {
            throw new \Exception('Prodotto WooCommerce dell\'evento non trovato.');
        }

        $order->add_product($product, $total_qty);

        // Dati di fatturazione
        $order->set_billing_first_name($first_name);
        $order->set_billing_last_name($last_name);
        $order->set_billing_email($email);
        $order->set_billing_phone($phone);
        $order->set_customer_note($notes);
        $order->set_payment_method('dfn_in_loco');

        // Applica sconto/adeguamento Soci FAI se presente
        $price_standard = floatval($event->price_standard);
        $price_fai      = floatval($event->price_fai);
        $unit_discount  = $price_standard - $price_fai;
        $total_discount = $unit_discount * $qty_fai;

        if ($total_discount !== 0.00) {
            $item_fee = new \WC_Order_Item_Fee();
            $fee_name = $total_discount > 0.00
                ? sprintf(__('Sconto Soci FAI (%d tessere) - Ins. Manuale', 'dfn-theme'), $qty_fai)
                : sprintf(__('Adeguamento Soci FAI (%d tessere) - Ins. Manuale', 'dfn-theme'), $qty_fai);
            $item_fee->set_name($fee_name);
            $item_fee->set_amount((string) (-$total_discount));
            $item_fee->set_total((string) (-$total_discount));
            $order->add_item($item_fee);
        }

        $order->calculate_totals();

        // Aggiungi metadati per l'allocazione alla riga prodotto
        foreach ($order->get_items() as $item) {
            if (is_a($item, 'WC_Order_Item_Product') && $item->get_product_id() === intval($event->product_id)) {
                $item->update_meta_data('_dfn_booking_date', $date);
                $item->update_meta_data('_dfn_booking_slot_id', (string) $slot_id);
                $item->update_meta_data('_dfn_qty_standard', (string) $qty_standard);
                $item->update_meta_data('_dfn_qty_fai', (string) $qty_fai);
                $item->save();
            }
        }

        // Salva metadati generali ordine
        $order->update_meta_data('_dfn_payment_in_loco', 'yes');
        if (! empty($fai_cards)) {
            $order->update_meta_data('_dfn_fai_cards', $fai_cards);
        }
        $order->save();

        // Salva in stato completato direttamente trattandosi di inserimento da admin
        $order->update_status('completed', __('Prenotazione creata manualmente dall\'amministratore.', 'dfn-theme'));

        // Esegui allocazione
        if ($is_free_flow) {
            // Per eventi free_flow: crea la prenotazione direttamente senza slot fisici
            if (! function_exists('dfn_allocate_slots_on_checkout')) {
                require_once get_template_directory() . '/inc/api/dfn-ajax-bookings.php';
            }
            dfn_allocate_slots_on_checkout($order->get_id(), [], $order);

            wp_send_json_success([
                'message'  => esc_html__('Prenotazione manuale registrata con successo per l\'evento a flusso libero.', 'dfn-theme'),
                'order_id' => $order->get_id(),
            ]);
        } else {
            if (! function_exists('dfn_allocate_slots_on_checkout')) {
                require_once get_template_directory() . '/inc/api/dfn-ajax-bookings.php';
            }
            dfn_allocate_slots_on_checkout($order->get_id(), [], $order);

            wp_send_json_success([
                'message'  => esc_html__('Prenotazione manuale registrata con successo e allocata allo slot.', 'dfn-theme'),
                'order_id' => $order->get_id(),
            ]);
        }

    } catch (\Exception $e) {
        wp_send_json_error([ 'message' => $e->getMessage() ]);
    }
}


/**
 * 8. Sposta prenotazione tra slot + invio email opzionale
 */
function dfn_ajax_admin_move_booking(): void
{
    dfn_ajax_admin_verify_access();

    $booking_id     = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $from_slot_id   = isset($_POST['from_slot_id']) ? intval($_POST['from_slot_id']) : 0;
    $to_slot_id     = isset($_POST['to_slot_id']) ? intval($_POST['to_slot_id']) : 0;
    $notify_visitor = isset($_POST['notify_visitor']) && '1' === $_POST['notify_visitor'];

    if ($booking_id <= 0 || $from_slot_id <= 0 || $to_slot_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Parametri non validi.', 'dfn-theme') ]);
    }

    if ($from_slot_id === $to_slot_id) {
        wp_send_json_error([ 'message' => esc_html__('Lo slot di destinazione è identico a quello di origine.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    $wpdb->query('START TRANSACTION');

    // 1. Legge il numero di persone associate a questa prenotazione per questo slot
    $assoc = $wpdb->get_row($wpdb->prepare(
        "SELECT persons FROM {$table_booking_slots} WHERE booking_id = %d AND slot_id = %d",
        $booking_id,
        $from_slot_id,
    ));

    if (! $assoc) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error([ 'message' => esc_html__('Associazione prenotazione-slot non trovata.', 'dfn-theme') ]);
    }
    $persons = intval($assoc->persons);

    // 2. Legge e blocca lo slot di destinazione per controllare la capacità
    $to_slot = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_slots} WHERE id = %d FOR UPDATE",
        $to_slot_id,
    ));

    if (! $to_slot) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error([ 'message' => esc_html__('Slot di destinazione non trovato.', 'dfn-theme') ]);
    }

    if (intval($to_slot->is_locked) === 1) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error([ 'message' => esc_html__('Lo slot di destinazione è bloccato.', 'dfn-theme') ]);
    }

    // Controlla capacità
    $available_space = (intval($to_slot->capacity) + intval($to_slot->bonus_capacity)) - intval($to_slot->booked_count);
    if ($persons > $available_space) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error([ 'message' => sprintf(esc_html__('Spazio insufficiente nello slot di destinazione. Posti necessari: %d, posti disponibili: %d.', 'dfn-theme'), $persons, $available_space) ]);
    }

    // 3. Decrementa lo slot d'origine
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table_slots} SET booked_count = GREATEST(0, CAST(booked_count AS SIGNED) - %d) WHERE id = %d",
        $persons,
        $from_slot_id,
    ));

    // 4. Incrementa lo slot di destinazione
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table_slots} SET booked_count = booked_count + %d WHERE id = %d",
        $persons,
        $to_slot_id,
    ));

    // 5. Aggiorna la riga di associazione
    $wpdb->update(
        $table_booking_slots,
        [ 'slot_id' => $to_slot_id ],
        [ 'booking_id' => $booking_id, 'slot_id' => $from_slot_id ],
        [ '%d' ],
        [ '%d', '%d' ],
    );

    // 6. Ottieni info ordine WC per salvare nota e metadati
    $booking = $wpdb->get_row($wpdb->prepare("SELECT order_id, customer_email, customer_name FROM {$table_bookings} WHERE id = %d", $booking_id));

    $wpdb->query('COMMIT');

    if ($booking && $booking->order_id) {
        $order = wc_get_order($booking->order_id);
        if ($order) {
            // Aggiorna il meta della riga prodotto dell'ordine per coerenza
            foreach ($order->get_items() as $item) {
                if (is_a($item, 'WC_Order_Item_Product')) {
                    $item->update_meta_data('_dfn_booking_slot_id', (string) $to_slot_id);
                    $item->save();
                }
            }

            $formatted_time = substr($to_slot->slot_time_start, 0, 5) . ' - ' . substr($to_slot->slot_time_end, 0, 5);
            $order->add_order_note(sprintf(
                __('Spostata prenotazione per %d persone da slot ID %d a slot ID %d (Nuovo Orario: %s) via admin.', 'dfn-theme'),
                $persons,
                $from_slot_id,
                $to_slot_id,
                $formatted_time,
            ));
        }

        // Invia email di notifica al visitatore se richiesto (e se l'email non è quella fittizia)
        if ($notify_visitor && ! empty($booking->customer_email) && 'no-email@dfn.it' !== $booking->customer_email) {
            // Invia email di modifica
            if (function_exists('dfn_send_booking_confirmation')) {
                dfn_send_booking_confirmation($booking_id);
            }
        }
    }

    wp_send_json_success([ 'message' => esc_html__('Prenotazione spostata con successo.', 'dfn-theme') ]);
}

/**
 * 9. Cancella prenotazione
 */
function dfn_ajax_admin_delete_booking(): void
{
    dfn_ajax_admin_verify_access();

    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;

    if ($booking_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Prenotazione non valida.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_bookings} WHERE id = %d", $booking_id));
    if (! $booking) {
        wp_send_json_error([ 'message' => esc_html__('Prenotazione non trovata.', 'dfn-theme') ]);
    }

    $wpdb->query('START TRANSACTION');

    // 1. Trova le associazioni con gli slot
    $assocs = $wpdb->get_results($wpdb->prepare(
        "SELECT slot_id, persons FROM {$table_booking_slots} WHERE booking_id = %d",
        $booking_id,
    ));

    // 2. Decrementa booked_count sugli slot associati
    foreach ($assocs as $assoc) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_slots} SET booked_count = GREATEST(0, CAST(booked_count AS SIGNED) - %d) WHERE id = %d",
            intval($assoc->persons),
            intval($assoc->slot_id),
        ));
    }

    // 3. Cambia lo stato in 'cancelled' nella tabella prenotazioni
    $wpdb->update(
        $table_bookings,
        [ 'status' => 'cancelled' ],
        [ 'id' => $booking_id ],
        [ '%s' ],
        [ '%d' ],
    );

    $wpdb->query('COMMIT');

    // 4. Aggiorna lo stato dell'ordine WooCommerce
    if ($booking->order_id) {
        $order = wc_get_order($booking->order_id);
        if ($order) {
            // Segna l'ordine come cancellato dall'amministratore PRIMA di cambiarne lo stato.
            // Questo flag viene letto dall'hook woocommerce_order_status_cancelled
            // per inviare l'email corretta ("cancellato dallo staff") invece di quella di scadenza.
            $order->update_meta_data('_dfn_admin_cancelled', 'yes');
            $order->update_meta_data('_dfn_cancelled_manually', 'yes');
            $order->save();
            $order->update_status('cancelled', __('Prenotazione cancellata dall\'amministratore via Gestione Turni.', 'dfn-theme'));
        }
    }

    wp_send_json_success([ 'message' => esc_html__('Prenotazione cancellata con successo.', 'dfn-theme') ]);
}

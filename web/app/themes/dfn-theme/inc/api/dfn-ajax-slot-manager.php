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
add_action('wp_ajax_dfn_admin_save_fai_cards', 'dfn_ajax_admin_save_fai_cards');

// Hook AJAX per la pagina Quick Booking (Inserimento Rapido Segreteria)
add_action('wp_ajax_dfn_quick_get_events', 'dfn_ajax_quick_get_events');
add_action('wp_ajax_dfn_quick_get_dates', 'dfn_ajax_quick_get_dates');
add_action('wp_ajax_dfn_quick_get_slots', 'dfn_ajax_quick_get_slots');

// Hook AJAX per il Botteghino Live
add_action('wp_ajax_dfn_botteghino_create_booking', 'dfn_ajax_botteghino_create_booking');
add_action('wp_ajax_dfn_botteghino_get_events', 'dfn_ajax_botteghino_get_events');
add_action('wp_ajax_dfn_botteghino_get_dates', 'dfn_ajax_botteghino_get_dates');
add_action('wp_ajax_dfn_botteghino_get_slots', 'dfn_ajax_botteghino_get_slots');

/**
 * Verifica i permessi di sicurezza dell'amministratore.
 */
function dfn_ajax_admin_verify_access(): void
{
    if (! is_user_logged_in()) {
        wp_send_json_error([ 'message' => esc_html__('Utente non autenticato.', 'dfn-theme') ], 401);
    }

    if (! current_user_can('manage_options') && ! current_user_can('edit_pages') && ! current_user_can('dfn_manage_events') && ! current_user_can('dfn_quick_booking') && ! current_user_can('read')) {
        wp_send_json_error([ 'message' => esc_html__('Permessi insufficienti.', 'dfn-theme') ], 403);
    }

    $nonce = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($nonce, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($nonce, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($nonce, 'dfn_booking_nonce') &&
        ! wp_verify_nonce($nonce, 'dfn_scanner_nonce')
    ) {
        wp_send_json_error([ 'message' => esc_html__('Token di sicurezza non valido.', 'dfn-theme') ], 403);
    }
}

/**
 * Verifica i permessi per la pagina Quick Booking (segreteria e mobile app).
 */
function dfn_ajax_quick_verify_access(): void
{
    if (! is_user_logged_in()) {
        wp_send_json_error([ 'message' => esc_html__('Utente non autenticato.', 'dfn-theme') ], 401);
    }

    if (! current_user_can('dfn_quick_booking') && ! current_user_can('dfn_manage_events') && ! current_user_can('manage_options') && ! current_user_can('edit_pages') && ! current_user_can('read')) {
        wp_send_json_error([ 'message' => esc_html__('Permessi insufficienti.', 'dfn-theme') ], 403);
    }

    $nonce = $_REQUEST['nonce'] ?? $_REQUEST['security'] ?? '';
    if (
        ! wp_verify_nonce($nonce, 'dfn_quick_booking_nonce') &&
        ! wp_verify_nonce($nonce, 'dfn_admin_events_nonce') &&
        ! wp_verify_nonce($nonce, 'dfn_booking_nonce') &&
        ! wp_verify_nonce($nonce, 'dfn_scanner_nonce')
    ) {
        wp_send_json_error([ 'message' => esc_html__('Sessione o token di sicurezza non valido. Ricarica la pagina.', 'dfn-theme') ], 403);
    }
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
             ORDER BY created_at DESC, id DESC",
            $event_id,
            $date,
        ));

        // Se nessuna prenotazione nella data specifica, mostra comunque tutte le prenotazioni dell'evento
        if (empty($bookings_raw)) {
            $bookings_raw = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_bookings}
                 WHERE event_id = %d AND status != 'cancelled'
                 ORDER BY created_at DESC, id DESC",
                $event_id,
            ));
        }

        $bookings_list = [];
        foreach ($bookings_raw as $b) {
            $order = $b->order_id ? wc_get_order($b->order_id) : null;
            $bookings_list[] = dfn_enrich_booking_data($b, $order);
        }

        // Ordinamento rigoroso dal più recente al meno recente (per Order ID o ID Prenotazione)
        usort($bookings_list, function ($a, $b) {
            $id_a = ! empty($a['order_id']) ? intval($a['order_id']) : intval($a['id']);
            $id_b = ! empty($b['order_id']) ? intval($b['order_id']) : intval($b['id']);
            return $id_b <=> $id_a;
        });

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
             ORDER BY b.created_at DESC, b.id DESC",
            $slot->id,
        ));

        $bookings_list = [];
        foreach ($bookings as $b) {
            $order = $b->order_id ? wc_get_order($b->order_id) : null;
            $bookings_list[] = dfn_enrich_booking_data($b, $order);
        }

        usort($bookings_list, function ($a, $b) {
            $id_a = ! empty($a['order_id']) ? intval($a['order_id']) : intval($a['id']);
            $id_b = ! empty($b['order_id']) ? intval($b['order_id']) : intval($b['id']);
            return $id_b <=> $id_a;
        });

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

    // slot_id = 0 è permesso per tutti i tipi di evento (smistamento automatico se necessario)
    if ($event_id <= 0 || empty($date) || empty($last_name)) {
        wp_send_json_error([ 'message' => esc_html__('Il campo Cognome dell\'acquirente è obbligatorio.', 'dfn-theme') ]);
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

    // slot_id = 0 è permesso per tutti i tipi di evento:
    // - free_flow: non ha slot fisici, ignorato dall'algoritmo
    // - time_slots con slot_id = 0: l'algoritmo di smistamento in dfn_allocate_slots_on_checkout()
    //   troverà automaticamente il turno ottimale (riga 200: 'automatic' === mode || slot_id <= 0)
    $is_free_flow = ('free_flow' === $event->access_type);

    global $wpdb;

    // Processa tessere FAI
    $fai_cards = [];
    if ($qty_fai > 0 && is_array($fai_cards_raw)) {
        $table_members = $wpdb->prefix . 'dfn_fai_members';
        foreach ($fai_cards_raw as $index => $card_data) {
            $c_nome    = isset($card_data['nome']) ? sanitize_text_field($card_data['nome']) : '';
            $c_cognome = isset($card_data['cognome']) ? sanitize_text_field($card_data['cognome']) : '';
            $c_num     = isset($card_data['tessera']) ? sanitize_text_field($card_data['tessera']) : '';

            // Se il numero di tessera è vuoto, non verifichiamo/inseriamo nulla nel DB dei membri FAI
            if (empty($c_num)) {
                $fai_cards[] = [
                    'nome'    => $c_nome,
                    'cognome' => $c_cognome,
                    'tessera' => '',
                ];
                continue;
            }

            // Fallback per nome/cognome se lasciati vuoti ma c'è il numero tessera
            $c_nome    = ! empty($c_nome) ? $c_nome : $first_name;
            $c_cognome = ! empty($c_cognome) ? $c_cognome : $last_name;

            // Controlla se la tessera esiste già in assoluto nel database per evitare duplicati
            $existing_member = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_members} WHERE card_number = %s LIMIT 1",
                $c_num,
            ));

            $email_to_save = ($email === 'no-email@dfn.it') ? '' : $email;

            if ($existing_member) {
                $member_verified = intval($existing_member->verified) === 1;
                $member_expired  = ! empty($existing_member->card_expiry) && $existing_member->card_expiry < date('Y-m-d');

                if ($member_verified && ! $member_expired) {
                    // La tessera è attiva e verificata
                } else {
                    // Esiste ma non è valida (scaduta o da verificare)
                    // Aggiorna anagrafica
                    $wpdb->update(
                        $table_members,
                        [
                            'first_name' => $c_nome,
                            'last_name'  => $c_cognome,
                            'email'      => $email_to_save,
                        ],
                        [ 'id' => $existing_member->id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ],
                    );
                    dfn_notify_admin_unverified_fai_card($c_num, $c_nome, $c_cognome, $email_to_save);
                }
            } else {
                // Non esiste affatto nel database, la inseriamo
                $wpdb->insert(
                    $table_members,
                    [
                        'first_name'  => $c_nome,
                        'last_name'   => $c_cognome,
                        'email'       => $email_to_save,
                        'card_number' => $c_num,
                        'card_expiry' => null,
                        'card_type'   => 'INDIVIDUALE',
                        'verified'    => 0,
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
                );
                dfn_notify_admin_unverified_fai_card($c_num, $c_nome, $c_cognome, $email_to_save);
            }

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

        $price_standard = floatval($event->price_standard);
        $order->add_product($product, $total_qty, [
            'subtotal' => $price_standard * $total_qty,
            'total'    => $price_standard * $total_qty,
        ]);

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

        // Salva in stato in attesa di pagamento (pending) trattandosi di inserimento da admin
        $order->update_status('pending', __('Prenotazione creata manualmente dall\'amministratore.', 'dfn-theme'));


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


// ============================================================================
// QUICK BOOKING — Endpoint AJAX per il form Inserimento Rapido Segreteria
// ============================================================================

/**
 * QB-1. Restituisce la lista degli eventi attivi e futuri.
 */
function dfn_ajax_quick_get_events(): void
{
    dfn_ajax_quick_verify_access();

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';
    $today = current_time('Y-m-d');

    $events = $wpdb->get_results($wpdb->prepare(
        "SELECT id, product_id, access_type, event_date_start, event_date_end, allocation_mode
         FROM {$table_events}
         WHERE status = 'published'
           AND event_date_end >= %s
         ORDER BY event_date_start ASC",
        $today,
    ));

    if (empty($events)) {
        wp_send_json_error([ 'message' => esc_html__('Nessun evento attivo trovato.', 'dfn-theme') ]);
    }

    foreach ($events as $event) {
        $event->event_name = get_the_title($event->product_id) ?: sprintf(__('Evento %d', 'dfn-theme'), $event->id);
    }

    wp_send_json_success([ 'events' => $events ]);
}

/**
 * QB-2. Restituisce le date disponibili per un evento.
 */
function dfn_ajax_quick_get_dates(): void
{
    dfn_ajax_quick_verify_access();

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    if ($event_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Evento non valido.', 'dfn-theme') ]);
    }

    if (! function_exists('dfn_db_get_event')) {
        require_once get_template_directory() . '/inc/core/dfn-database.php';
    }

    $event = dfn_db_get_event($event_id);
    if (! $event) {
        wp_send_json_error([ 'message' => esc_html__('Evento non trovato.', 'dfn-theme') ]);
    }

    global $wpdb;
    $today = current_time('Y-m-d');

    if ('free_flow' === $event->access_type) {
        // Per eventi free_flow: genera lista date tra start e end
        $dates = [];
        $start = max($event->event_date_start, $today);
        $end   = $event->event_date_end;
        $current = new \DateTime($start);
        $endDt   = new \DateTime($end);
        while ($current <= $endDt) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }
        wp_send_json_success([ 'dates' => $dates, 'access_type' => 'free_flow' ]);
    } else {
        // Per eventi time_slots: recupera le date distinte dagli slot esistenti
        $dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT slot_date FROM {$wpdb->prefix}dfn_event_slots
             WHERE event_id = %d AND slot_date >= %s AND is_locked = 0
             ORDER BY slot_date ASC",
            $event_id,
            $today,
        ));
        wp_send_json_success([ 'dates' => $dates, 'access_type' => 'time_slots', 'allocation_mode' => $event->allocation_mode ]);
    }
}

/**
 * QB-3. Restituisce gli slot disponibili per evento + data (con opzione Auto).
 */
function dfn_ajax_quick_get_slots(): void
{
    dfn_ajax_quick_verify_access();

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $date     = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

    if ($event_id <= 0 || empty($date)) {
        wp_send_json_error([ 'message' => esc_html__('Parametri mancanti.', 'dfn-theme') ]);
    }

    global $wpdb;
    $slots_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT id, slot_time_start, slot_time_end, capacity, bonus_capacity, booked_count, is_locked
         FROM {$wpdb->prefix}dfn_event_slots
         WHERE event_id = %d AND slot_date = %s
         ORDER BY slot_time_start ASC",
        $event_id,
        $date,
    ));

    $slots = [];
    foreach ($slots_raw as $s) {
        $available = (intval($s->capacity) + intval($s->bonus_capacity)) - intval($s->booked_count);
        $slots[] = [
            'id'         => intval($s->id),
            'time_start' => substr($s->slot_time_start, 0, 5),
            'time_end'   => substr($s->slot_time_end, 0, 5),
            'available'  => max(0, $available),
            'is_locked'  => intval($s->is_locked) === 1,
        ];
    }

    wp_send_json_success([ 'slots' => $slots ]);
}

// ============================================================================
// BOTTEGHINO LIVE — Endpoint AJAX per creazione prenotazione dal Botteghino
// ============================================================================

/**
 * BL-1. Crea una prenotazione dal Botteghino Live.
 *
 * Supporta 4 modalità di pagamento:
 * - contanti: Ordine completed, allocazione, opzionalmente auto-checkin
 * - link: Ordine pending, allocazione, email con link di pagamento
 * - autorita: Ordine completed omaggio, allocazione, auto-checkin
 * - prenotazione: Ordine pending con dfn_in_loco, allocazione, email conferma opzionale
 */
function dfn_ajax_botteghino_create_booking(): void
{
    dfn_ajax_admin_verify_access();

    $event_id       = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $date           = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $slot_id        = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;
    $first_name     = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name      = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $email          = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone          = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $qty_standard   = isset($_POST['qty_standard']) ? intval($_POST['qty_standard']) : 0;
    $qty_fai        = isset($_POST['qty_fai']) ? intval($_POST['qty_fai']) : 0;
    $notes          = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'prenotazione';
    $auto_checkin   = isset($_POST['auto_checkin']) && '1' === $_POST['auto_checkin'];
    $fai_cards_raw  = isset($_POST['fai_cards']) ? $_POST['fai_cards'] : [];
    $confirm_split  = isset($_POST['confirm_split']) && '1' === $_POST['confirm_split'];

    // Email fittizia se il campo è vuoto
    $has_real_email = ! empty($email);
    if (! $has_real_email) {
        $email = 'no-email-' . time() . '@dfn.local';
    }

    // Per le autorità associa il nominativo se specificato nel form
    if ($payment_method === 'autorita') {
        $input_name = trim($first_name . ' ' . $last_name);
        if (! empty($input_name)) {
            $first_name = 'Riserva Autorità - ' . $input_name;
            $last_name  = '';
        } else {
            $first_name = 'Riserva';
            $last_name  = 'Autorità';
        }
        $email          = 'autorita_' . time() . '@fainovara.local';
        $phone          = 'CERIMONIALE';
        $auto_checkin   = false;
        $has_real_email = false;
    }

    // Validazione
    if ($event_id <= 0 || empty($date)) {
        wp_send_json_error([ 'message' => esc_html__('Seleziona un evento e una data.', 'dfn-theme') ]);
    }

    if ($payment_method !== 'autorita' && empty($last_name)) {
        wp_send_json_error([ 'message' => esc_html__('Il campo Cognome è obbligatorio.', 'dfn-theme') ]);
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

    global $wpdb;

    // --- Controllo split per eventi time_slots ---
    if ('time_slots' === $event->access_type && ! $confirm_split) {
        $has_single_slot = false;

        if ($slot_id > 0) {
            $slot = $wpdb->get_row($wpdb->prepare(
                "SELECT id, capacity, bonus_capacity, booked_count FROM {$wpdb->prefix}dfn_event_slots WHERE id = %d",
                $slot_id,
            ));
            if ($slot) {
                $avail = (intval($slot->capacity) + intval($slot->bonus_capacity)) - intval($slot->booked_count);
                if ($avail >= $total_qty) {
                    $has_single_slot = true;
                }
            }
        } else {
            $best_slot = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dfn_event_slots 
                 WHERE event_id = %d AND slot_date = %s AND is_locked = 0 
                   AND (capacity + bonus_capacity - booked_count) >= %d
                 LIMIT 1",
                $event->id,
                $date,
                $total_qty,
            ));
            if ($best_slot) {
                $has_single_slot = true;
            }
        }

        if (! $has_single_slot) {
            $total_avail = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(capacity + bonus_capacity - booked_count) 
                 FROM {$wpdb->prefix}dfn_event_slots 
                 WHERE event_id = %d AND slot_date = %s AND is_locked = 0",
                $event->id,
                $date,
            )) ?: 0;

            if ($total_avail >= $total_qty) {
                wp_send_json_success([
                    'status'  => 'split_warning',
                    'message' => __('I posti disponibili nei singoli turni non sono sufficienti per accogliere tutto il gruppo in un unico orario. Proseguendo, la prenotazione verrà suddivisa su due o più turni. Vuoi continuare?', 'dfn-theme'),
                ]);
            } elseif (intval($total_avail) < $total_qty) {
                // Avviso overbooking ma lascia proseguire dall'admin
            }
        }
    }

    // --- Processa tessere FAI ---
    $fai_cards = [];
    if ($qty_fai > 0 && is_array($fai_cards_raw)) {
        $table_members = $wpdb->prefix . 'dfn_fai_members';
        $email_to_save = ($has_real_email) ? $email : '';
        foreach ($fai_cards_raw as $index => $card_data) {
            $c_nome    = isset($card_data['nome']) ? sanitize_text_field($card_data['nome']) : '';
            $c_cognome = isset($card_data['cognome']) ? sanitize_text_field($card_data['cognome']) : '';
            $c_num     = isset($card_data['tessera']) ? sanitize_text_field($card_data['tessera']) : '';

            // Se il numero di tessera è vuoto, non verifichiamo/inseriamo nulla nel DB dei membri FAI
            if (empty($c_num)) {
                $fai_cards[] = [
                    'nome'    => $c_nome,
                    'cognome' => $c_cognome,
                    'tessera' => '',
                ];
                continue;
            }

            // Fallback per nome/cognome se lasciati vuoti ma c'è il numero tessera
            $c_nome    = ! empty($c_nome) ? $c_nome : $first_name;
            $c_cognome = ! empty($c_cognome) ? $c_cognome : $last_name;

            $existing_member = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_members} WHERE card_number = %s LIMIT 1",
                $c_num,
            ));

            if ($existing_member) {
                $member_verified = intval($existing_member->verified) === 1;
                $member_expired  = ! empty($existing_member->card_expiry) && $existing_member->card_expiry < date('Y-m-d');
                if (! $member_verified || $member_expired) {
                    $wpdb->update(
                        $table_members,
                        [ 'first_name' => $c_nome, 'last_name' => $c_cognome, 'email' => $email_to_save ],
                        [ 'id' => $existing_member->id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ],
                    );
                    if (function_exists('dfn_notify_admin_unverified_fai_card')) {
                        dfn_notify_admin_unverified_fai_card($c_num, $c_nome, $c_cognome, $email_to_save);
                    }
                }
            } else {
                $wpdb->insert(
                    $table_members,
                    [
                        'first_name'  => $c_nome,
                        'last_name'   => $c_cognome,
                        'email'       => $email_to_save,
                        'card_number' => $c_num,
                        'card_expiry' => null,
                        'card_type'   => 'INDIVIDUALE',
                        'verified'    => 0,
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ],
                );
                if (function_exists('dfn_notify_admin_unverified_fai_card')) {
                    dfn_notify_admin_unverified_fai_card($c_num, $c_nome, $c_cognome, $email_to_save);
                }
            }

            $fai_cards[] = [
                'nome'    => $c_nome,
                'cognome' => $c_cognome,
                'tessera' => $c_num,
            ];
        }
    }

    // --- Creazione ordine WooCommerce ---
    try {
        if (! function_exists('wc_create_order')) {
            wp_send_json_error([ 'message' => esc_html__('WooCommerce non è attivo.', 'dfn-theme') ]);
        }

        $order = wc_create_order();
        $product = wc_get_product($event->product_id);
        if (! $product) {
            throw new \Exception('Prodotto WooCommerce dell\'evento non trovato.');
        }

        $price_standard = floatval($event->price_standard);
        $order->add_product($product, $total_qty, [
            'subtotal' => $price_standard * $total_qty,
            'total'    => $price_standard * $total_qty,
        ]);
        $order->set_billing_first_name($first_name);
        $order->set_billing_last_name($last_name);
        $order->set_billing_email($email);
        $order->set_billing_phone($phone);
        $order->set_customer_note($notes);

        // Applica sconto/adeguamento Soci FAI
        $price_fai      = floatval($event->price_fai);
        $unit_discount  = $price_standard - $price_fai;
        $total_discount = $unit_discount * $qty_fai;

        if ($payment_method === 'autorita') {
            // Omaggio completo per autorità
            $total_price = $product->get_price() * $total_qty;
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name('Riserva Posti Autorità (Omaggio)');
            $fee->set_amount(-$total_price);
            $fee->set_total(-$total_price);
            $order->add_item($fee);
        } elseif ($total_discount !== 0.00) {
            $item_fee = new \WC_Order_Item_Fee();
            $fee_name = $total_discount > 0.00
                ? sprintf(__('Sconto Soci FAI (%d tessere) - Botteghino', 'dfn-theme'), $qty_fai)
                : sprintf(__('Adeguamento Soci FAI (%d tessere) - Botteghino', 'dfn-theme'), $qty_fai);
            $item_fee->set_name($fee_name);
            $item_fee->set_amount((string) (-$total_discount));
            $item_fee->set_total((string) (-$total_discount));
            $order->add_item($item_fee);
        }

        $order->calculate_totals();

        // Metadati per l'allocazione sulla riga prodotto
        foreach ($order->get_items() as $item) {
            if (is_a($item, 'WC_Order_Item_Product') && $item->get_product_id() === intval($event->product_id)) {
                $item->update_meta_data('_dfn_booking_date', $date);
                $item->update_meta_data('_dfn_booking_slot_id', (string) $slot_id);
                $item->update_meta_data('_dfn_qty_standard', (string) $qty_standard);
                $item->update_meta_data('_dfn_qty_fai', (string) $qty_fai);
                $item->save();
            }
        }

        // Metadati generali ordine
        $order->update_meta_data('_dfn_source', 'botteghino');
        if (! empty($fai_cards)) {
            $order->update_meta_data('_dfn_fai_cards', $fai_cards);
        }
        $order->save();

        // --- Flussi di pagamento ---
        $messaggio_esito = '';

        if ($payment_method === 'contanti' || $payment_method === 'autorita') {
            $order->set_payment_method('cod');
            $order->set_payment_method_title(
                $payment_method === 'autorita'
                    ? 'Cerimoniale Autorità'
                    : 'Contanti in Loco (Botteghino)'
            );
            $order->update_status('completed', __('Operazione registrata dal Botteghino Live.', 'dfn-theme'));
            wc_reduce_stock_levels($order->get_id());

            // Auto-checkin
            if ($auto_checkin) {
                for ($i = 1; $i <= $total_qty; $i++) {
                    $order->update_meta_data('_cv_ticket_validato_' . $i, 'yes');
                    $order->update_meta_data('_cv_ticket_validato_' . $i . '_orario', current_time('mysql'));
                    $order->update_meta_data('_cv_ticket_validato_' . $i . '_operatore', get_current_user_id());
                }
                $order->add_order_note('✅ Check-in immediato eseguito dal Botteghino Live.');
            } elseif ($has_real_email) {
                // Nota: L'email di completamento ordine (con biglietti/QR) viene già inviata automaticamente da WooCommerce al cambio stato 'completed'.
                $order->add_order_note('📧 Inviata mail con i BIGLIETTI (Botteghino Live).');
            }

            $order->save();

            if ($payment_method === 'autorita') {
                $messaggio_esito = __('🎁 Posti riservati per le Autorità. Il check-in verrà effettuato all\'arrivo all\'ingresso.', 'dfn-theme');
            } elseif ($auto_checkin) {
                $messaggio_esito = __('✅ Incassato in contanti. Biglietti validati per l\'ingresso.', 'dfn-theme');
            } else {
                $messaggio_esito = sprintf(
                    __('✅ Incassato in contanti. Biglietti inviati a %s.', 'dfn-theme'),
                    esc_html($email)
                );
            }

        } elseif ($payment_method === 'link') {
            $order->update_status('pending', __('Ordine dal Botteghino. In attesa di pagamento tramite link.', 'dfn-theme'));
            wc_reduce_stock_levels($order->get_id());

            if ($has_real_email) {
                /** @var \WC_Email_Customer_Invoice|null $email_invoice */
                $email_invoice = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'] ?? null;
                if ($email_invoice) {
                    $email_invoice->trigger($order->get_id());
                }
                $order->add_order_note(sprintf(
                    __('📧 Link di pagamento inviato a %s (Botteghino Live).', 'dfn-theme'),
                    esc_html($email)
                ));
            }

            $messaggio_esito = sprintf(
                __('💳 Link di pagamento inviato a %s.', 'dfn-theme'),
                esc_html($email)
            );

        } else {
            // 'prenotazione' — Solo prenotazione senza pagamento
            $order->set_payment_method('dfn_in_loco');
            $order->update_meta_data('_dfn_payment_in_loco', 'yes');
            $order->save();
            $order->update_status('pending', __('Prenotazione registrata dal Botteghino Live (pagamento in loco).', 'dfn-theme'));

            $messaggio_esito = __('📋 Prenotazione registrata. Il pagamento verrà gestito all\'arrivo.', 'dfn-theme');
        }

        // --- Esegui allocazione DFN 2.0 ---
        if (! function_exists('dfn_allocate_slots_on_checkout')) {
            require_once get_template_directory() . '/inc/api/dfn-ajax-bookings.php';
        }
        dfn_allocate_slots_on_checkout($order->get_id(), [], $order);

        // Verifica esito allocazione
        $booking = dfn_db_get_booking_by_order($order->get_id());

        // Invia email di conferma prenotazione DFN se c'è una mail reale
        // (per 'prenotazione' e 'link', l'email di conferma booking è gestita internamente dall'allocatore)

        $response_data = [
            'status'       => $booking ? 'confirmed' : 'waitlist',
            'message'      => $messaggio_esito,
            'order_id'     => $order->get_id(),
            'edit_url'     => admin_url('post.php?post=' . $order->get_id() . '&action=edit'),
        ];

        if (! $booking) {
            $response_data['message'] = __('⚠️ Posti esauriti. Il cliente è stato inserito in Lista d\'Attesa.', 'dfn-theme');
        }

        wp_send_json_success($response_data);

    } catch (\Exception $e) {
        wp_send_json_error([ 'message' => $e->getMessage() ]);
    }
}

/**
 * BL-2. Wrapper per caricare gli eventi dal Botteghino (usa nonce admin).
 */
function dfn_ajax_botteghino_get_events(): void
{
    dfn_ajax_admin_verify_access();

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';
    $today = current_time('Y-m-d');

    $events = $wpdb->get_results($wpdb->prepare(
        "SELECT id, product_id, access_type, event_date_start, event_date_end, allocation_mode
         FROM {$table_events}
         WHERE status = 'published'
           AND event_date_end >= %s
         ORDER BY event_date_start ASC",
        $today,
    ));

    if (empty($events)) {
        wp_send_json_error([ 'message' => esc_html__('Nessun evento attivo trovato.', 'dfn-theme') ]);
    }

    foreach ($events as $event) {
        $event->event_name = get_the_title($event->product_id) ?: sprintf(__('Evento %d', 'dfn-theme'), $event->id);
    }

    wp_send_json_success([ 'events' => $events ]);
}

/**
 * BL-3. Wrapper per caricare le date dal Botteghino (usa nonce admin).
 */
function dfn_ajax_botteghino_get_dates(): void
{
    dfn_ajax_admin_verify_access();

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    if ($event_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Evento non valido.', 'dfn-theme') ]);
    }

    if (! function_exists('dfn_db_get_event')) {
        require_once get_template_directory() . '/inc/core/dfn-database.php';
    }

    $event = dfn_db_get_event($event_id);
    if (! $event) {
        wp_send_json_error([ 'message' => esc_html__('Evento non trovato.', 'dfn-theme') ]);
    }

    global $wpdb;
    $today = current_time('Y-m-d');

    if ('free_flow' === $event->access_type) {
        $dates = [];
        $start = max($event->event_date_start, $today);
        $end   = $event->event_date_end;
        $current = new \DateTime($start);
        $endDt   = new \DateTime($end);
        while ($current <= $endDt) {
            $dates[] = $current->format('Y-m-d');
            $current->modify('+1 day');
        }
        wp_send_json_success([ 'dates' => $dates, 'access_type' => 'free_flow' ]);
    } else {
        $dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT slot_date FROM {$wpdb->prefix}dfn_event_slots
             WHERE event_id = %d AND slot_date >= %s AND is_locked = 0
             ORDER BY slot_date ASC",
            $event_id,
            $today,
        ));
        wp_send_json_success([ 'dates' => $dates, 'access_type' => 'time_slots', 'allocation_mode' => $event->allocation_mode ]);
    }
}

/**
 * BL-4. Wrapper per caricare gli slot dal Botteghino (usa nonce admin).
 */
function dfn_ajax_botteghino_get_slots(): void
{
    dfn_ajax_admin_verify_access();

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $date     = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';

    if ($event_id <= 0 || empty($date)) {
        wp_send_json_error([ 'message' => esc_html__('Parametri mancanti.', 'dfn-theme') ]);
    }

    global $wpdb;
    $slots_raw = $wpdb->get_results($wpdb->prepare(
        "SELECT id, slot_time_start, slot_time_end, capacity, bonus_capacity, booked_count, is_locked
         FROM {$wpdb->prefix}dfn_event_slots
         WHERE event_id = %d AND slot_date = %s
         ORDER BY slot_time_start ASC",
        $event_id,
        $date,
    ));

    $slots = [];
    foreach ($slots_raw as $s) {
        $available = (intval($s->capacity) + intval($s->bonus_capacity)) - intval($s->booked_count);
        $slots[] = [
            'id'         => intval($s->id),
            'time_start' => substr($s->slot_time_start, 0, 5),
            'time_end'   => substr($s->slot_time_end, 0, 5),
            'available'  => max(0, $available),
            'is_locked'  => intval($s->is_locked) === 1,
        ];
    }

    wp_send_json_success([ 'slots' => $slots ]);
}

/**
 * Salva e sincronizza le tessere Soci FAI di una prenotazione esistente.
 */
function dfn_ajax_admin_save_fai_cards(): void
{
    dfn_ajax_admin_verify_access();

    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $order_id   = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $cards_raw  = isset($_POST['fai_cards']) && is_array($_POST['fai_cards']) ? $_POST['fai_cards'] : [];

    if ($booking_id <= 0 || $order_id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Parametri mancanti.', 'dfn-theme') ]);
    }

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_send_json_error([ 'message' => esc_html__('Ordine non trovato.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE id = %d LIMIT 1",
        $booking_id
    ));

    if (! $booking) {
        wp_send_json_error([ 'message' => esc_html__('Prenotazione non trovata.', 'dfn-theme') ]);
    }

    // Processa e pulisce le tessere
    $fai_cards = [];
    $table_members = $wpdb->prefix . 'dfn_fai_members';
    
    // Per fallback nomi
    $billing_first_name = $order->get_billing_first_name();
    $billing_last_name  = $order->get_billing_last_name();
    $email_to_save      = ($order->get_billing_email() === 'no-email@dfn.it') ? '' : $order->get_billing_email();

    foreach ($cards_raw as $card_data) {
        $c_nome    = isset($card_data['nome']) ? sanitize_text_field($card_data['nome']) : '';
        $c_cognome = isset($card_data['cognome']) ? sanitize_text_field($card_data['cognome']) : '';
        $c_num     = isset($card_data['tessera']) ? sanitize_text_field($card_data['tessera']) : '';

        // Sincronizza nel database soci FAI se presente la tessera
        if (! empty($c_num)) {
            $c_nome_sync    = ! empty($c_nome) ? $c_nome : $billing_first_name;
            $c_cognome_sync = ! empty($c_cognome) ? $c_cognome : $billing_last_name;

            // Controlla duplicato
            $existing_member = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_members} WHERE card_number = %s LIMIT 1",
                $c_num
            ));

            if ($existing_member) {
                $member_verified = intval($existing_member->verified) === 1;
                $member_expired  = ! empty($existing_member->card_expiry) && $existing_member->card_expiry < date('Y-m-d');

                if (! ($member_verified && ! $member_expired)) {
                    // Aggiorna anagrafica non attiva
                    $wpdb->update(
                        $table_members,
                        [
                            'first_name' => $c_nome_sync,
                            'last_name'  => $c_cognome_sync,
                            'email'      => $email_to_save,
                        ],
                        [ 'id' => $existing_member->id ],
                        [ '%s', '%s', '%s' ],
                        [ '%d' ]
                    );
                    dfn_notify_admin_unverified_fai_card($c_num, $c_nome_sync, $c_cognome_sync, $email_to_save);
                }
            } else {
                // Inserisce nuovo record
                $wpdb->insert(
                    $table_members,
                    [
                        'first_name'  => $c_nome_sync,
                        'last_name'   => $c_cognome_sync,
                        'email'       => $email_to_save,
                        'card_number' => $c_num,
                        'card_expiry' => null,
                        'card_type'   => 'INDIVIDUALE',
                        'verified'    => 0,
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
                );
                dfn_notify_admin_unverified_fai_card($c_num, $c_nome_sync, $c_cognome_sync, $email_to_save);
            }
        }

        $fai_cards[] = [
            'nome'    => $c_nome,
            'cognome' => $c_cognome,
            'tessera' => $c_num,
        ];
    }

    // Salva nei metadati dell'ordine
    $order->update_meta_data('_dfn_fai_cards', $fai_cards);
    $order->save();

    wp_send_json_success([
        'message'   => esc_html__('Tessere salvate e sincronizzate con successo.', 'dfn-theme'),
        'fai_cards' => $fai_cards
    ]);
}

/**
 * Helper per arricchire i dati di prenotazione per il tabellone di gestione.
 */
function dfn_enrich_booking_data($b, $order) {
    $fai_cards = [];
    $order_total = 0.00;
    $payment_status = 'ancora da pagare';
    $first_name = '';
    $last_name = '';
    $qualifica_html = '<span style="color:#aaa; font-size:12px;">Standard</span>';
    $checkin_fatti = 0;
    $operatori_html = '-';
    $history_logs = [];
    $reminder_sent = false;
    $feedback_sent = false;
    $html_bottoni_popup = '';
    $html_history_popup = '<div class="cv-history-data-container" style="display:none;"><p style="color:#666; font-style:italic; padding:10px 0; text-align:center;">Nessuna interazione registrata per questo ordine.</p></div>';

    if ($order) {
        $status = $order->get_status();
        if (in_array($status, ['failed', 'cancelled', 'refunded'], true) && $b->status !== 'cancelled') {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'dfn_bookings', ['status' => 'cancelled'], ['id' => $b->id], ['%s'], ['%d']);
            $b->status = 'cancelled';
        }

        $fai_cards = $order->get_meta('_dfn_fai_cards') ?: [];
        $order_total = floatval($order->get_total());
        if (in_array($status, ['completed', 'processing'], true)) {
            $payment_status = 'pagato';
        } elseif ($status === 'failed') {
            $payment_status = 'fallito (annullato)';
        } else {
            $payment_status = 'ancora da pagare';
        }
        $first_name = $order->get_billing_first_name();
        $last_name  = $order->get_billing_last_name();

        if (function_exists('cv_get_order_qualifica_label')) {
            $qualifica_html = cv_get_order_qualifica_label($order);
        }

        $reminder_sent = $order->get_meta('_cv_reminder_sent') === 'yes';
        $feedback_sent = $order->get_meta('_cv_feedback_sent') === 'yes';

        // Calcola check-in e bottoni cassa
        $qty_prodotto = intval($b->total_persons);
        $operatori_coinvolti = [];
        $user_cache = [];
        $html_bottoni_popup = '<div class="cv-popup-data-container" style="display:none;">';
        for ($i = 1; $i <= $qty_prodotto; $i++) {
            if ($order->get_meta('_cv_ticket_validato_' . $i) === 'yes') {
                $checkin_fatti++;
                $op_id = $order->get_meta('_cv_ticket_validato_' . $i . '_operatore');
                if ($op_id) {
                    if (! isset($user_cache[ $op_id ])) {
                        $user_info = get_userdata($op_id);
                        $user_cache[ $op_id ] = $user_info ? $user_info->display_name : 'Sconosciuto';
                    }
                    $nome_op = $user_cache[ $op_id ];
                    isset($operatori_coinvolti[$nome_op]) ? $operatori_coinvolti[$nome_op]++ : $operatori_coinvolti[$nome_op] = 1;
                }
                $html_bottoni_popup .= '<div style="margin-bottom:8px; padding:10px; background:#eaf7ea; color:#166534; border: 1px solid #c3e6c3; border-radius: 4px; display:flex; justify-content:space-between; align-items:center;"><span>✅ Biglietto ' . $i . ' validato</span><button class="button cv-undo-checkin-btn" data-order="' . esc_attr($order->get_id()) . '" data-ticket="' . esc_attr($i) . '" style="color:#d63638; border-color:#d63638; padding:0 8px; min-height:26px; line-height:24px;">Annulla</button></div>';
            } else {
                $html_bottoni_popup .= '<button class="button cv-manual-checkin-btn" data-order="' . esc_attr($order->get_id()) . '" data-ticket="' . esc_attr($i) . '" style="margin-bottom:8px; display:block; width:100%; border-color:#00a32a; color:#00a32a; height: 40px; cursor:pointer;">✔️ Valida Biglietto ' . $i . '</button>';
            }
        }
        $html_bottoni_popup .= '</div>';

        if (! empty($operatori_coinvolti)) {
            $operatori_html = '';
            foreach ($operatori_coinvolti as $nome => $qta) {
                $operatori_html .= '<span style="display:block; margin-bottom:4px; font-size:12px;">👤 ' . esc_html($nome) . ' <small style="color:#777;">(x' . $qta . ')</small></span>';
            }
        }

        // Calcola log storico dell'ordine
        $history_meta = $order->get_meta('_cv_ticket_history');
        $html_history_popup = '<div class="cv-history-data-container" style="display:none;">';
        if (! empty($history_meta) && is_array($history_meta)) {
            usort($history_meta, function ($a, $b) {
                return strtotime($b['time']) - strtotime($a['time']);
            });
            foreach ($history_meta as $log) {
                $html_history_popup .= '<div class="cv-history-item" style="border-bottom:1px solid #e2e8f0; padding:8px 0; white-space:nowrap;"><span style="color:#64748b; margin-right:12px;">🕒 ' . date_i18n('d/m/Y - H:i:s', strtotime($log['time'])) . '</span> <strong>' . esc_html($log['action']) . '</strong></div>';
            }
        } else {
            $html_history_popup .= '<p style="color:#666; font-style:italic; padding:10px 0; text-align:center;">Nessuna interazione registrata per questo ordine.</p>';
        }
        $html_history_popup .= '</div>';
    }

    if (empty($first_name) && empty($last_name)) {
        $parts = explode(' ', $b->customer_name, 2);
        $first_name = $parts[0];
        $last_name  = isset($parts[1]) ? $parts[1] : '';
    }

    $created_at_formatted = '-';
    if ($order && method_exists($order, 'get_date_created') && $order->get_date_created()) {
        $wc_date = $order->get_date_created();
        $created_at_formatted = $wc_date ? $wc_date->date_i18n('d/m/Y - H:i') : '-';
    } elseif (! empty($b->created_at)) {
        $created_at_formatted = date_i18n('d/m/Y - H:i', strtotime($b->created_at));
    }

    return [
        'id'               => intval($b->id),
        'order_id'         => intval($b->order_id),
        'customer_name'    => esc_html($b->customer_name),
        'customer_first_name'=> esc_html($first_name),
        'customer_last_name' => esc_html($last_name),
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
        'created_at_formatted' => $created_at_formatted,
        'fai_cards'        => $fai_cards,
        'order_total'      => $order_total,
        'payment_status'   => $payment_status,
        'qualifica_html'   => $qualifica_html,
        'checkin_fatti'    => $checkin_fatti,
        'operatori_html'   => $operatori_html,
        'html_bottoni_popup'=> $html_bottoni_popup,
        'html_history_popup'=> $html_history_popup,
        'reminder_sent'    => $reminder_sent,
        'feedback_sent'    => $feedback_sent,
    ];
}

add_action('wp_ajax_dfn_admin_update_payment_status', 'dfn_admin_update_payment_status');
/**
 * Cambia lo stato di pagamento di una prenotazione e sincronizza lo stato dell'ordine WooCommerce.
 */
function dfn_admin_update_payment_status(): void
{
    dfn_ajax_admin_verify_access();

    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    $new_status = isset($_POST['new_status']) ? sanitize_text_field($_POST['new_status']) : '';

    if (! $booking_id || ! in_array($new_status, ['pending_payment', 'confirmed', 'cancelled'], true)) {
        wp_send_json_error([ 'message' => __('Parametri non validi.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $booking_id));

    if (! $booking) {
        wp_send_json_error([ 'message' => __('Prenotazione non trovata.', 'dfn-theme') ]);
    }

    // Aggiorna lo stato della prenotazione
    $wpdb->update(
        $table,
        ['status' => $new_status],
        ['id' => $booking_id],
        ['%s'],
        ['%d']
    );

    // Sincronizza l'ordine WooCommerce correlato
    if ($booking->order_id > 0) {
        $order = wc_get_order($booking->order_id);
        if ($order) {
            $user_name = wp_get_current_user()->display_name;
            if ($new_status === 'confirmed') {
                $order->update_status('completed', sprintf(__('Stato pagamento impostato su PAGATO dall\'admin %s.', 'dfn-theme'), $user_name));
            } elseif ($new_status === 'pending_payment') {
                $order->update_status('pending', sprintf(__('Stato pagamento impostato su IN ATTESA DI PAGAMENTO dall\'admin %s.', 'dfn-theme'), $user_name));
            } elseif ($new_status === 'cancelled') {
                $order->update_status('cancelled', sprintf(__('Stato prenotazione/pagamento ANNULLATO dall\'admin %s.', 'dfn-theme'), $user_name));
            }
        }
    }

    wp_send_json_success([
        'message'    => __('Stato del pagamento aggiornato con successo.', 'dfn-theme'),
        'new_status' => $new_status,
    ]);
}

add_action('wp_ajax_dfn_admin_resend_confirmation_email', 'dfn_ajax_admin_resend_confirmation_email');
/**
 * Reinvia manualmente l'email di conferma prenotazione dall'admin (Gestione Turni).
 */
function dfn_ajax_admin_resend_confirmation_email(): void
{
    dfn_ajax_admin_verify_access();

    $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
    if (! $booking_id) {
        wp_send_json_error(['message' => __('ID prenotazione non valido.', 'dfn-theme')]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $booking_id));

    if (! $booking) {
        wp_send_json_error(['message' => __('Prenotazione non trovata.', 'dfn-theme')]);
    }

    $sent = false;
    if (function_exists('dfn_send_booking_confirmation')) {
        $sent = dfn_send_booking_confirmation($booking_id);
    }

    if ($sent) {
        if (function_exists('dfn_log_event')) {
            dfn_log_event(
                'MAIL_RESEND',
                sprintf('Email di conferma reinviata da admin per prenotazione #%d (Ordine #%d)', $booking_id, $booking->order_id),
                [
                    'booking_id' => $booking_id,
                    'order_id'   => $booking->order_id,
                    'recipient'  => $booking->customer_email,
                ],
                $booking->event_id,
                'success'
            );
        }
        wp_send_json_success([
            'message' => sprintf(__('Email di conferma reinviata con successo a %s!', 'dfn-theme'), esc_html($booking->customer_email)),
        ]);
    } else {
        wp_send_json_error(['message' => __('Impossibile inviare l\'email di conferma.', 'dfn-theme')]);
    }
}

add_action('wp_ajax_cv_send_single_reminder', 'cv_send_single_reminder_ajax');
/**
 * Handler AJAX per il reinvio del promemoria singolo (da cassa / check-in).
 */
function cv_send_single_reminder_ajax(): void
{
    dfn_ajax_admin_verify_access();

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if (! $order_id) {
        wp_send_json_error(__('ID Ordine non valido.', 'dfn-theme'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id));

    if (! $booking) {
        wp_send_json_error(__('Prenotazione non trovata per questo ordine.', 'dfn-theme'));
    }

    $sent = false;
    if (function_exists('dfn_send_booking_24h_reminder')) {
        $sent = dfn_send_booking_24h_reminder($booking->id);
    } elseif (function_exists('dfn_send_booking_confirmation')) {
        $sent = dfn_send_booking_confirmation($booking->id);
    }

    if ($sent) {
        if (function_exists('dfn_log_event')) {
            dfn_log_event(
                'REMINDER_SENT',
                sprintf('Promemoria reinviato da cassa/check-in per ordine #%d', $order_id),
                ['order_id' => $order_id, 'recipient' => $booking->customer_email],
                $booking->event_id,
                'success'
            );
        }
        wp_send_json_success(__('Promemoria inviato con successo!', 'dfn-theme'));
    } else {
        wp_send_json_error(__('Impossibile inviare il promemoria email.', 'dfn-theme'));
    }
}

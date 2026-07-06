<?php

/**
 * DFN Booking System 2.0 — Booking Engine & Transaction Allocator
 *
 * Cuore dell'allocazione prenotazioni: gestisce la sessione del carrello, i metadati
 * dell'ordine, la mitigazione delle race conditions (FOR UPDATE) e l'algoritmo
 * di allocazione automatica/self-selection al checkout.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ========================================================================
 * 1. GESTIONE SESSIONE CARRELLO (SALVATAGGIO DATE/SLOT NEL CARRELLO WC)
 * ========================================================================
 */

add_filter('woocommerce_add_cart_item_data', 'dfn_save_slot_data_in_cart', 10, 3);
/**
 * Memorizza la data e l'eventuale ID dello slot orario selezionato dall'utente
 * all'interno dei dati dell'elemento del carrello WooCommerce.
 *
 * @param array $cart_item_data Dati correnti dell'elemento.
 * @param int   $product_id     ID prodotto.
 * @param int   $variation_id   ID variazione.
 * @return array Dati aggiornati.
 */
function dfn_save_slot_data_in_cart($cart_item_data, $product_id, $variation_id)
{
    if (isset($_POST['dfn_booking_date'])) {
        $cart_item_data['dfn_booking_date'] = sanitize_text_field($_POST['dfn_booking_date']);
    }
    if (isset($_POST['dfn_booking_slot_id'])) {
        $cart_item_data['dfn_booking_slot_id'] = intval($_POST['dfn_booking_slot_id']);
    }
    if (isset($_POST['dfn_qty_standard'])) {
        $cart_item_data['dfn_qty_standard'] = intval($_POST['dfn_qty_standard']);
    }
    if (isset($_POST['dfn_qty_fai'])) {
        $cart_item_data['dfn_qty_fai'] = intval($_POST['dfn_qty_fai']);
    }
    return $cart_item_data;
}

add_filter('woocommerce_get_cart_item_from_session', 'dfn_load_slot_data_from_session', 10, 2);
/**
 * Recupera i metadati della prenotazione dalla sessione di WooCommerce
 * per mantenerli sincronizzati durante la navigazione.
 */
function dfn_load_slot_data_from_session($cart_item, $values)
{
    if (isset($values['dfn_booking_date'])) {
        $cart_item['dfn_booking_date'] = $values['dfn_booking_date'];
    }
    if (isset($values['dfn_booking_slot_id'])) {
        $cart_item['dfn_booking_slot_id'] = $values['dfn_booking_slot_id'];
    }
    if (isset($values['dfn_qty_standard'])) {
        $cart_item['dfn_qty_standard'] = $values['dfn_qty_standard'];
    }
    if (isset($values['dfn_qty_fai'])) {
        $cart_item['dfn_qty_fai'] = $values['dfn_qty_fai'];
    }
    return $cart_item;
}

/**
 * ========================================================================
 * 2. SALVATAGGIO NEI METADATI DELL'ORDINE WOOCOMMERCE
 * ========================================================================
 */

add_action('woocommerce_checkout_create_order_line_item', 'dfn_add_slot_metadata_to_order_items', 10, 4);
/**
 * Aggiunge i metadati della prenotazione alla riga d'ordine di WooCommerce,
 * in modo da renderli permanenti nel database una volta creato l'ordine.
 */
function dfn_add_slot_metadata_to_order_items($item, $cart_item_key, $values, $order)
{
    if (isset($values['dfn_booking_date'])) {
        $item->add_meta_data('_dfn_booking_date', $values['dfn_booking_date'], true);
    }
    if (isset($values['dfn_booking_slot_id'])) {
        $item->add_meta_data('_dfn_booking_slot_id', $values['dfn_booking_slot_id'], true);
    }
    if (isset($values['dfn_qty_standard'])) {
        $item->add_meta_data('_dfn_qty_standard', $values['dfn_qty_standard'], true);
    }
    if (isset($values['dfn_qty_fai'])) {
        $item->add_meta_data('_dfn_qty_fai', $values['dfn_qty_fai'], true);
    }
}

/**
 * ========================================================================
/**
 * Distribuisce una quantità in modo bilanciato tra un set di slot candidati,
 * rispettando la capienza massima disponibile di ciascun slot.
 *
 * @param int   $total_qty Quantità totale da distribuire (persone).
 * @param array $slots     Array di oggetti slot, ognuno con 'id' e 'available_capacity'.
 * @return array Array associativo [slot_id => quantità_allocata].
 */
function dfn_distribute_slots_balanced($total_qty, $slots)
{
    $allocations = [];
    foreach ($slots as $s) {
        $allocations[ $s->id ] = 0;
    }

    for ($i = 0; $i < $total_qty; $i++) {
        $best_slot_id = null;
        $min_val = PHP_INT_MAX;

        foreach ($slots as $s) {
            $limit = intval($s->available_capacity);
            $curr = $allocations[ $s->id ];

            if ($curr < $limit) {
                if ($curr < $min_val) {
                    $min_val = $curr;
                    $best_slot_id = $s->id;
                }
            }
        }

        if ($best_slot_id === null) {
            // Se non c'è abbastanza spazio complessivo, si ferma
            break;
        }

        $allocations[ $best_slot_id ]++;
    }

    return $allocations;
}

add_action('woocommerce_checkout_order_processed', 'dfn_allocate_slots_on_checkout', 10, 3);
/**
 * Trigger primario all'atto della creazione dell'ordine.
 * Esegue le query transazionali con row-locking, applicando l'algoritmo di allocazione.
 *
 * @param int      $order_id ID dell'ordine appena creato.
 * @param array    $posted_data Dati inviati.
 * @param WC_Order $order Oggetto ordine WooCommerce.
 */
function dfn_allocate_slots_on_checkout($order_id, $posted_data, $order)
{
    global $wpdb;

    // Rileva se l'ordine contiene elementi legati a eventi
    foreach ($order->get_items() as $item_id => $item) {
        if (! is_a($item, 'WC_Order_Item_Product')) {
            continue;
        }
        $product_id = $item->get_product_id();
        $event      = dfn_db_get_event_by_product($product_id);

        if (! $event) {
            continue; // Non è un evento FAI
        }

        // Recupera dati del checkout salvati nei metadati della riga
        $booking_date = $item->get_meta('_dfn_booking_date');
        $slot_id      = intval($item->get_meta('_dfn_booking_slot_id'));
        // Nota: NON usare ?: qui perché 0 è falsy in PHP — un qty_standard=0 verrebbe ignorato.
        // Usiamo un controllo esplicito: se il meta esiste (anche con valore "0") lo usiamo,
        // altrimenti usiamo la quantità WooCommerce come fallback.
        $meta_qty_std = $item->get_meta('_dfn_qty_standard');
        $qty_std      = ($meta_qty_std !== '' && $meta_qty_std !== false && $meta_qty_std !== null)
                        ? intval($meta_qty_std)
                        : intval($item->get_quantity());
        $meta_qty_fai = $item->get_meta('_dfn_qty_fai');
        $qty_fai      = ($meta_qty_fai !== '' && $meta_qty_fai !== false && $meta_qty_fai !== null)
                        ? intval($meta_qty_fai)
                        : 0;
        $total_qty    = $qty_std + $qty_fai;

        if (empty($booking_date)) {
            $booking_date = $event->event_date_start;
        }

        // -------------------------------------------------------------------
        // ALGORITMO DI ALLOCAZIONE DI VOLTA IN VOLTA SELEZIONATO (Fasce Orarie)
        // -------------------------------------------------------------------
        if ('time_slots' === $event->access_type) {

            $wpdb->query('START TRANSACTION');

            $need_split = false;
            $selected_slot = null;

            // Se l'evento prevede allocazione AUTOMATICA (🤖), il sistema decide il turno migliore
            if ('automatic' === $event->allocation_mode || $slot_id <= 0) {

                // Cerca lo slot disponibile più vicino (in ordine temporale) con capienza sufficiente (first-fit)
                $selected_slot = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, capacity, bonus_capacity, booked_count 
                     FROM {$wpdb->prefix}dfn_event_slots 
                     WHERE event_id = %d 
                       AND slot_date = %s 
                       AND is_locked = 0 
                       AND (capacity + bonus_capacity - booked_count) >= %d
                     ORDER BY slot_time_start ASC 
                     LIMIT 1 FOR UPDATE",
                    $event->id,
                    $booking_date,
                    $total_qty,
                ));

                if ($selected_slot) {
                    $slot_id = intval($selected_slot->id);
                } else {
                    $need_split = true;
                }
            } else {
                // Modalità SELF-SELECTION (👈) — blocca la riga dello slot selezionato
                $selected_slot = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, capacity, bonus_capacity, booked_count, is_locked 
                     FROM {$wpdb->prefix}dfn_event_slots 
                     WHERE id = %d FOR UPDATE",
                    $slot_id,
                ));

                if ($selected_slot) {
                    $avail = (intval($selected_slot->capacity) + intval($selected_slot->bonus_capacity)) - intval($selected_slot->booked_count);
                    if (intval($selected_slot->is_locked) === 1 || $avail < $total_qty) {
                        $need_split = true;
                    }
                } else {
                    $need_split = true;
                }
            }

            if (! $need_split && $selected_slot) {
                // Allocazione Standard su un singolo slot

                // 1. Incrementa il conteggio nello slot
                $wpdb->update(
                    $wpdb->prefix . 'dfn_event_slots',
                    [ 'booked_count' => intval($selected_slot->booked_count) + $total_qty ],
                    [ 'id' => $slot_id ],
                    [ '%d' ],
                    [ '%d' ],
                );

                // 2. Crea il record master di prenotazione
                $qr_token = wp_hash($order_id . '|' . $event->id . '|' . time());
                $booking_status = ('manual' === $event->approval_workflow) ? 'pending_approval' : 'confirmed';

                $wpdb->insert(
                    $wpdb->prefix . 'dfn_bookings',
                    [
                        'order_id'         => $order_id,
                        'event_id'         => $event->id,
                        'customer_email'   => $order->get_billing_email(),
                        'customer_name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                        'customer_phone'   => $order->get_billing_phone(),
                        'total_persons'    => $total_qty,
                        'persons_standard' => $qty_std,
                        'persons_fai'      => $qty_fai,
                        'status'           => $booking_status,
                        'qr_token'         => $qr_token,
                        'payment_method'   => $order->get_payment_method(),
                        'amount_due'       => ($order->get_payment_method() === 'dfn_in_loco') ? floatval($order->get_total()) : 0.00,
                        'amount_paid'      => ($order->get_payment_method() !== 'dfn_in_loco') ? floatval($order->get_total()) : 0.00,
                        'notes'            => $order->get_customer_note(),
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s' ],
                );

                $booking_id = $wpdb->insert_id;

                // 3. Associa N:M il booking allo slot
                $wpdb->insert(
                    $wpdb->prefix . 'dfn_booking_slots',
                    [
                        'booking_id' => $booking_id,
                        'slot_id'    => $slot_id,
                        'persons'    => $total_qty,
                    ],
                    [ '%d', '%d', '%d' ],
                );

                // Aggiorna metadati riga ordine
                $item->update_meta_data('_dfn_booking_slot_id', (string) $slot_id);
                $item->save();

                $wpdb->query('COMMIT');

                // Invio notifica centralizzata in base al workflow
                if ('manual' === $event->approval_workflow) {
                    dfn_send_booking_pending_approval($booking_id);
                    $order->add_order_note(__('🎟️ Prenotazione FAI creata in stato: In Attesa di Approvazione Staff.', 'dfn-theme'));
                } else {
                    dfn_send_booking_confirmation($booking_id);
                    $order->add_order_note(__('🎟️ Prenotazione FAI allocata e confermata con successo!', 'dfn-theme'));
                }

                // Invia la notifica semplificata all'amministratore
                dfn_send_admin_new_booking_notification($booking_id);

            } elseif ($need_split) {
                // Eseguiamo l'allocazione divisa (Split)
                $available_slots = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dfn_event_slots 
                     WHERE event_id = %d AND slot_date = %s AND is_locked = 0 
                     ORDER BY slot_time_start ASC FOR UPDATE",
                    $event->id,
                    $booking_date,
                ));

                // Aggiungiamo a ciascuno slot un campo provvisorio per la capacità disponibile (inclusa bonus_capacity)
                foreach ($available_slots as $s) {
                    $s->available_capacity = (intval($s->capacity) + intval($s->bonus_capacity)) - intval($s->booked_count);
                }

                // Rimosso slot senza spazio residuo
                $available_slots = array_filter($available_slots, function ($s) {
                    return $s->available_capacity > 0;
                });

                // Calcola lo spazio totale disponibile
                $total_avail = 0;
                foreach ($available_slots as $s) {
                    $total_avail += $s->available_capacity;
                }

                if ($total_avail >= $total_qty) {
                    // Abbiamo spazio sufficiente per completare lo split!

                    // Selezioniamo il set minimo di slot necessari per accogliere il gruppo ("meno spezziamo meglio è")
                    $selected_candidates = [];
                    $selected_ids = [];
                    $accumulated_capacity = 0;

                    // Se c'è uno slot selezionato ed ha spazio, lo inseriamo per primo
                    if ($slot_id > 0) {
                        foreach ($available_slots as $s) {
                            if (intval($s->id) === $slot_id) {
                                $selected_candidates[] = $s;
                                $selected_ids[] = $s->id;
                                $accumulated_capacity += $s->available_capacity;
                                break;
                            }
                        }
                    }

                    // Se lo slot selezionato non basta a coprire l'intera quantità, aggiungiamo altri slot
                    // Li ordiniamo per capacità decrescente per prendere il minor numero possibile di turni
                    if ($accumulated_capacity < $total_qty) {
                        $other_slots = array_filter($available_slots, function ($s) use ($selected_ids) {
                            return ! in_array($s->id, $selected_ids);
                        });

                        usort($other_slots, function ($a, $b) {
                            return $b->available_capacity - $a->available_capacity; // Decrescente
                        });

                        foreach ($other_slots as $s) {
                            $selected_candidates[] = $s;
                            $selected_ids[] = $s->id;
                            $accumulated_capacity += $s->available_capacity;

                            if ($accumulated_capacity >= $total_qty) {
                                break;
                            }
                        }
                    }

                    // Distribuiamo in modo bilanciato le persone tra gli slot minimi individuati
                    $allocations = dfn_distribute_slots_balanced($total_qty, $selected_candidates);

                    // Eseguiamo gli inserimenti
                    $qr_token = wp_hash($order_id . '|' . $event->id . '|' . time());
                    $booking_status = ('manual' === $event->approval_workflow) ? 'pending_approval' : 'confirmed';

                    $wpdb->insert(
                        $wpdb->prefix . 'dfn_bookings',
                        [
                            'order_id'         => $order_id,
                            'event_id'         => $event->id,
                            'customer_email'   => $order->get_billing_email(),
                            'customer_name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                            'customer_phone'   => $order->get_billing_phone(),
                            'total_persons'    => $total_qty,
                            'persons_standard' => $qty_std,
                            'persons_fai'      => $qty_fai,
                            'status'           => $booking_status,
                            'qr_token'         => $qr_token,
                            'payment_method'   => $order->get_payment_method(),
                            'amount_due'       => ($order->get_payment_method() === 'dfn_in_loco') ? floatval($order->get_total()) : 0.00,
                            'amount_paid'      => ($order->get_payment_method() !== 'dfn_in_loco') ? floatval($order->get_total()) : 0.00,
                            'notes'            => $order->get_customer_note(),
                        ],
                        [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s' ],
                    );

                    $booking_id = $wpdb->insert_id;
                    $allocated_slots_notes = [];

                    foreach ($selected_candidates as $s) {
                        $to_allocate = isset($allocations[ $s->id ]) ? intval($allocations[ $s->id ]) : 0;
                        if ($to_allocate > 0) {
                            // 1. Incrementa booked_count sullo slot
                            $wpdb->update(
                                $wpdb->prefix . 'dfn_event_slots',
                                [ 'booked_count' => intval($s->booked_count) + $to_allocate ],
                                [ 'id' => $s->id ],
                                [ '%d' ],
                                [ '%d' ],
                            );

                            // 2. Associa N:M
                            $wpdb->insert(
                                $wpdb->prefix . 'dfn_booking_slots',
                                [
                                    'booking_id' => $booking_id,
                                    'slot_id'    => $s->id,
                                    'persons'    => $to_allocate,
                                ],
                                [ '%d', '%d', '%d' ],
                            );

                            $time_formatted = substr($s->slot_time_start, 0, 5) . '-' . substr($s->slot_time_end, 0, 5);
                            $allocated_slots_notes[] = sprintf('%d in slot %s', $to_allocate, $time_formatted);
                        }
                    }

                    // Aggiorna metadati riga ordine con il primo slot per retrocompatibilità
                    if (! empty($selected_candidates)) {
                        $item->update_meta_data('_dfn_booking_slot_id', (string) $selected_candidates[0]->id);
                        $item->save();
                    }

                    $wpdb->query('COMMIT');

                    // Aggiungi nota riassuntiva sul frazionamento all'ordine
                    $split_note = sprintf(
                        __('🎟️ Prenotazione FAI suddivisa su più turni: %s', 'dfn-theme'),
                        implode(', ', $allocated_slots_notes),
                    );
                    $order->add_order_note($split_note);

                    // Invio notifica centralizzata in base al workflow
                    if ('manual' === $event->approval_workflow) {
                        dfn_send_booking_pending_approval($booking_id);
                    } else {
                        dfn_send_booking_confirmation($booking_id);
                    }
                    dfn_send_admin_new_booking_notification($booking_id);

                } else {
                    // OVERBOOKING: Spazio non disponibile! Annulla transazione
                    $wpdb->query('ROLLBACK');

                    // Inserimento automatico in Waitlist (Lista d'Attesa)
                    $wpdb->insert(
                        $wpdb->prefix . 'dfn_waitlist',
                        [
                            'event_id'       => $event->id,
                            'slot_id'        => $slot_id > 0 ? $slot_id : null,
                            'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                            'customer_email' => $order->get_billing_email(),
                            'customer_phone' => $order->get_billing_phone(),
                            'persons'        => $total_qty,
                            'fai_cards'      => $qty_fai,
                            'status'         => 'waiting',
                        ],
                        [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ],
                    );

                    $order->add_order_note(__('⚠️ OVERBOOKING: Posti esauriti durante il checkout. Il cliente è stato inserito automaticamente in Lista d\'Attesa.', 'dfn-theme'));
                }
            }

        } else {
            // -------------------------------------------------------------------
            // INGRESSO LIBERO (FREE FLOW)
            // -------------------------------------------------------------------
            $wpdb->query('START TRANSACTION');

            // Verifica capacità globale dell'evento
            $booked_global = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings 
                 WHERE event_id = %d AND status != 'cancelled' FOR UPDATE",
                $event->id,
            )) ?: 0;

            if ((intval($booked_global) + $total_qty) <= intval($event->total_capacity)) {

                $qr_token = wp_hash($order_id . '|' . $event->id . '|' . time());
                $booking_status = ('manual' === $event->approval_workflow) ? 'pending_approval' : 'confirmed';

                $wpdb->insert(
                    $wpdb->prefix . 'dfn_bookings',
                    [
                        'order_id'         => $order_id,
                        'event_id'         => $event->id,
                        'customer_email'   => $order->get_billing_email(),
                        'customer_name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                        'customer_phone'   => $order->get_billing_phone(),
                        'total_persons'    => $total_qty,
                        'persons_standard' => $qty_std,
                        'persons_fai'      => $qty_fai,
                        'status'           => $booking_status,
                        'qr_token'         => $qr_token,
                        'payment_method'   => $order->get_payment_method(),
                        'amount_due'       => ($order->get_payment_method() === 'dfn_in_loco') ? floatval($order->get_total()) : 0.00,
                        'amount_paid'      => ($order->get_payment_method() !== 'dfn_in_loco') ? floatval($order->get_total()) : 0.00,
                        'notes'            => $order->get_customer_note(),
                        'created_at'       => $booking_date . ' ' . date('H:i:s'),
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s' ],
                );

                $booking_id = $wpdb->insert_id;
                $wpdb->query('COMMIT');

                if ('manual' === $event->approval_workflow) {
                    dfn_send_booking_pending_approval($booking_id);
                } else {
                    dfn_send_booking_confirmation($booking_id);
                }

                // Invia la notifica semplificata all'amministratore
                dfn_send_admin_new_booking_notification($booking_id);

            } else {
                $wpdb->query('ROLLBACK');

                // Inserimento automatico in Waitlist
                $wpdb->insert(
                    $wpdb->prefix . 'dfn_waitlist',
                    [
                        'event_id'       => $event->id,
                        'slot_id'        => null,
                        'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                        'customer_email' => $order->get_billing_email(),
                        'customer_phone' => $order->get_billing_phone(),
                        'persons'        => $total_qty,
                        'fai_cards'      => $qty_fai,
                        'status'         => 'waiting',
                    ],
                    [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ],
                );

                $order->add_order_note(__('⚠️ OVERBOOKING (Free Flow): Posti esauriti. Cliente inserito in Lista d\'Attesa.', 'dfn-theme'));
            }
        }
    }

}

add_action('wp_ajax_dfn_create_direct_booking', 'dfn_ajax_create_direct_booking');
add_action('wp_ajax_nopriv_dfn_create_direct_booking', 'dfn_ajax_create_direct_booking');

/**
 * Gestisce la creazione diretta di un ordine e prenotazione via AJAX dall'interfaccia prodotto,
 * bypassando il carrello.
 */
function dfn_ajax_create_direct_booking(): void
{
    check_ajax_referer('dfn_booking_nonce', 'nonce');

    $event_id     = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $product_id   = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $qty_standard = isset($_POST['qty_standard']) ? intval($_POST['qty_standard']) : 0;
    $qty_fai      = isset($_POST['qty_fai']) ? intval($_POST['qty_fai']) : 0;
    $date         = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $slot_id      = isset($_POST['slot_id']) ? intval($_POST['slot_id']) : 0;

    $first_name   = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name    = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $email        = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone        = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $notes        = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';

    $fai_cards_raw = isset($_POST['fai_cards']) ? $_POST['fai_cards'] : [];

    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone)) {
        wp_send_json_error([ 'message' => esc_html__('Compila tutti i campi anagrafici obbligatori.', 'dfn-theme') ]);
    }

    $total_qty = $qty_standard + $qty_fai;
    if ($total_qty <= 0) {
        wp_send_json_error([ 'message' => esc_html__('Seleziona almeno un biglietto.', 'dfn-theme') ]);
    }

    $event = dfn_db_get_event($event_id);
    if (! $event) {
        wp_send_json_error([ 'message' => esc_html__('Evento non valido.', 'dfn-theme') ]);
    }

    global $wpdb;

    $confirm_split = isset($_POST['confirm_split']) && '1' === $_POST['confirm_split'];

    // Se l'evento ha fasce orarie
    if ('time_slots' === $event->access_type) {
        $has_single_slot = false;

        if ('self_selection' === $event->allocation_mode && $slot_id > 0) {
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
            // Automatic o nessun slot specifico selezionato: cerchiamo se ne esiste almeno uno che contenga tutti
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

        // Se non c'è un singolo slot in grado di contenere tutti
        if (! $has_single_slot) {
            // Calcoliamo la capienza totale disponibile sommando tutti gli slot non bloccati in quel giorno
            $total_avail = $wpdb->get_var($wpdb->prepare(
                "SELECT SUM(capacity + bonus_capacity - booked_count) 
                 FROM {$wpdb->prefix}dfn_event_slots 
                 WHERE event_id = %d AND slot_date = %s AND is_locked = 0",
                $event->id,
                $date,
            )) ?: 0;

            if ($total_avail >= $total_qty) {
                // Abbiamo abbastanza posti complessivi, ma devono essere divisi
                if (! $confirm_split) {
                    wp_send_json_success([
                        'status' => 'split_warning',
                        'message' => __('I posti disponibili nei singoli turni non sono sufficienti per accogliere tutto il gruppo in un unico orario. Proseguendo, la prenotazione verrà suddivisa su due o più turni differenti. Vuoi continuare?', 'dfn-theme'),
                    ]);
                }
            }
        }
    }

    // 1. Processa e verifica le tessere FAI fornite
    $fai_cards = [];
    $all_verified = true;
    $unverified_cards = [];

    if ($qty_fai > 0 && is_array($fai_cards_raw)) {
        $table_members = $wpdb->prefix . 'dfn_fai_members';
        $user_id_to_save = is_user_logged_in() ? get_current_user_id() : null;

        // 1. Controllo duplicati all'interno della richiesta stessa
        $input_card_numbers = [];
        foreach ($fai_cards_raw as $index => $card_data) {
            $c_num = isset($card_data['tessera']) ? sanitize_text_field($card_data['tessera']) : '';
            if (! empty($c_num)) {
                if (in_array($c_num, $input_card_numbers, true)) {
                    wp_send_json_error([ 'message' => sprintf(esc_html__('Errore: Hai inserito la tessera FAI n° %s più di una volta in questa prenotazione.', 'dfn-theme'), $c_num) ]);
                }
                $input_card_numbers[] = $c_num;
            }
        }

        // 2. Recupera tutte le tessere già utilizzate per prenotazioni attive per questo evento
        $used_cards = [];
        if ($event_id > 0) {
            $order_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}dfn_bookings WHERE event_id = %d AND status != 'cancelled'",
                $event_id,
            ));
            if (! empty($order_ids)) {
                foreach ($order_ids as $order_id) {
                    $order_cards = get_post_meta($order_id, '_dfn_fai_cards', true);
                    if (is_array($order_cards)) {
                        foreach ($order_cards as $c) {
                            if (isset($c['tessera'])) {
                                $used_cards[] = sanitize_text_field($c['tessera']);
                            }
                        }
                    }
                }
            }
        }

        foreach ($fai_cards_raw as $index => $card_data) {
            $c_nome    = isset($card_data['nome']) ? sanitize_text_field($card_data['nome']) : '';
            $c_cognome = isset($card_data['cognome']) ? sanitize_text_field($card_data['cognome']) : '';
            $c_num     = isset($card_data['tessera']) ? sanitize_text_field($card_data['tessera']) : '';

            // Se il numero di tessera è vuoto, non verifichiamo/inseriamo nulla nel DB dei membri FAI e non eseguiamo controlli duplicati
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

            // Controllo se la tessera è già stata usata per questo evento
            if (in_array($c_num, $used_cards, true)) {
                wp_send_json_error([ 'message' => sprintf(esc_html__('La tessera FAI n° %s ha già usufruito dello sconto per questo evento.', 'dfn-theme'), $c_num) ]);
            }

            // Controlla se la tessera esiste già in assoluto nel database per evitare duplicati
            $existing_member = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_members} WHERE card_number = %s LIMIT 1",
                $c_num,
            ));

            if ($existing_member) {
                $member_verified = intval($existing_member->verified) === 1;
                $member_expired  = ! empty($existing_member->card_expiry) && $existing_member->card_expiry < date('Y-m-d');

                if ($member_verified && ! $member_expired) {
                    // La tessera è attiva e verificata
                    // Se l'utente è loggato, la associamo se non è già associata
                    if ($user_id_to_save && empty($existing_member->user_id)) {
                        $wpdb->update(
                            $table_members,
                            [ 'user_id' => $user_id_to_save ],
                            [ 'id' => $existing_member->id ],
                            [ '%d' ],
                            [ '%d' ],
                        );
                    }
                } else {
                    // Esiste ma non è valida (scaduta o da verificare)
                    $all_verified = false;
                    $unverified_cards[] = $c_num;

                    // Aggiorna anagrafica e utente
                    $update_data = [
                        'first_name' => $c_nome,
                        'last_name'  => $c_cognome,
                        'email'      => $email,
                    ];
                    $update_formats = [ '%s', '%s', '%s' ];

                    if ($user_id_to_save && empty($existing_member->user_id)) {
                        $update_data['user_id'] = $user_id_to_save;
                        $update_formats[] = '%d';
                    }

                    $wpdb->update(
                        $table_members,
                        $update_data,
                        [ 'id' => $existing_member->id ],
                        $update_formats,
                        [ '%d' ],
                    );
                    dfn_notify_admin_unverified_fai_card($c_num, $c_nome, $c_cognome, $email);
                }
            } else {
                // Non esiste affatto nel database, la inseriamo
                $all_verified = false;
                $unverified_cards[] = $c_num;

                $wpdb->insert(
                    $table_members,
                    [
                        'first_name'  => $c_nome,
                        'last_name'   => $c_cognome,
                        'email'       => $email,
                        'card_number' => $c_num,
                        'card_expiry' => null,
                        'card_type'   => 'INDIVIDUALE',
                        'verified'    => 0,
                        'user_id'     => $user_id_to_save,
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' ],
                );
                dfn_notify_admin_unverified_fai_card($c_num, $c_nome, $c_cognome, $email);
            }

            $fai_cards[] = [
                'nome'    => $c_nome,
                'cognome' => $c_cognome,
                'tessera' => $c_num,
            ];
        }
    }

    // 2. Creazione programmatica dell'ordine WooCommerce
    try {
        $order = wc_create_order();
        $product = wc_get_product($product_id);
        if (! $product) {
            throw new \Exception('Prodotto WooCommerce non trovato.');
        }

        $order->add_product($product, $total_qty);

        // Applica i dati di fatturazione minimi
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
                ? sprintf(__('Sconto Soci FAI (%d tessere)', 'dfn-theme'), $qty_fai)
                : sprintf(__('Adeguamento Soci FAI (%d tessere)', 'dfn-theme'), $qty_fai);
            $item_fee->set_name($fee_name);
            $item_fee->set_amount((string) (-$total_discount));
            $item_fee->set_total((string) (-$total_discount));
            $order->add_item($item_fee);
        }

        $order->calculate_totals();

        // Aggiungi metadati per l'allocazione alla riga prodotto
        foreach ($order->get_items() as $item) {
            if (is_a($item, 'WC_Order_Item_Product') && $item->get_product_id() === $product_id) {
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

        // Aggiorna anagrafica utente se vuota
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            if (! get_user_meta($user_id, 'billing_first_name', true) && ! empty($first_name)) {
                update_user_meta($user_id, 'billing_first_name', $first_name);
            }
            if (! get_user_meta($user_id, 'billing_last_name', true) && ! empty($last_name)) {
                update_user_meta($user_id, 'billing_last_name', $last_name);
            }
            if (! get_user_meta($user_id, 'billing_email', true) && ! empty($email)) {
                update_user_meta($user_id, 'billing_email', $email);
            }
            if (! get_user_meta($user_id, 'billing_phone', true) && ! empty($phone)) {
                update_user_meta($user_id, 'billing_phone', $phone);
            }
        }

        // Passa lo stato a pending
        $order->update_status('pending', __('Prenotazione diretta via widget.', 'dfn-theme'));
        wc_reduce_stock_levels($order->get_id());

        // 3. Esegui allocazione
        dfn_allocate_slots_on_checkout($order->get_id(), [], $order);

        // Svuota carrello per sicurezza
        if (WC()->cart instanceof \WC_Cart) {
            WC()->cart->empty_cart();
        }

        // Verifica esito dell'allocazione (Waitlist o Booking)
        $booking = dfn_db_get_booking_by_order($order->get_id());

        $response_data = [
            'order_id'         => $order->get_id(),
            'total_confirmed'  => $all_verified,
            'unverified_cards' => $unverified_cards,
            'amount_due'       => floatval($order->get_total()),
            'amount_standard'  => $price_standard * $total_qty,
        ];

        if ($booking) {
            $response_data['status'] = 'confirmed';
            $response_data['message'] = sprintf(
                esc_html__('La tua prenotazione è confermata per il giorno %s.', 'dfn-theme'),
                date_i18n('d F Y', strtotime($date)),
            );
        } else {
            $response_data['status'] = 'waitlist';
            $response_data['message'] = esc_html__('Posti esauriti. Sei stato inserito con priorità in Lista d\'Attesa! Riceverai una notifica se si libererà un turno.', 'dfn-theme');
        }

        wp_send_json_success($response_data);

    } catch (\Exception $e) {
        wp_send_json_error([ 'message' => $e->getMessage() ]);
    }
}

/**
 * AJAX endpoint to fetch verified FAI cards associated with the logged-in user.
 */
add_action('wp_ajax_dfn_get_user_fai_cards', 'dfn_ajax_get_user_fai_cards');
function dfn_ajax_get_user_fai_cards(): void
{
    check_ajax_referer('dfn_booking_nonce', 'nonce');

    if (! is_user_logged_in()) {
        wp_send_json_error([ 'message' => esc_html__('Utente non autorizzato.', 'dfn-theme') ]);
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table_members = $wpdb->prefix . 'dfn_fai_members';
    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;

    // Recupera tutte le tessere FAI già utilizzate per prenotazioni attive per questo evento
    $used_cards = [];
    if ($event_id > 0) {
        $order_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}dfn_bookings WHERE event_id = %d AND status != 'cancelled'",
            $event_id,
        ));
        if (! empty($order_ids)) {
            foreach ($order_ids as $order_id) {
                $order_cards = get_post_meta($order_id, '_dfn_fai_cards', true);
                if (is_array($order_cards)) {
                    foreach ($order_cards as $c) {
                        if (isset($c['tessera'])) {
                            $used_cards[] = sanitize_text_field($c['tessera']);
                        }
                    }
                }
            }
        }
    }

    $cards = $wpdb->get_results($wpdb->prepare(
        "SELECT first_name, last_name, card_number, card_expiry 
         FROM {$table_members} 
         WHERE user_id = %d AND verified = 1 
         ORDER BY id DESC",
        $user_id,
    ));

    $formatted_cards = [];
    $today = date('Y-m-d');
    foreach ($cards as $card) {
        // Esclude le tessere che hanno già usufruito dello sconto in questo evento
        if (in_array($card->card_number, $used_cards, true)) {
            continue;
        }

        $expired = false;
        if (! empty($card->card_expiry) && $card->card_expiry < $today) {
            $expired = true;
        }

        $formatted_cards[] = [
            'nome'    => $card->first_name,
            'cognome' => $card->last_name,
            'tessera' => $card->card_number,
            'scaduta' => $expired,
        ];
    }

    wp_send_json_success([ 'cards' => $formatted_cards ]);
}

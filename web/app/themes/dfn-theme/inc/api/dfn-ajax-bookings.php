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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ========================================================================
 * 1. GESTIONE SESSIONE CARRELLO (SALVATAGGIO DATE/SLOT NEL CARRELLO WC)
 * ========================================================================
 */

add_filter( 'woocommerce_add_cart_item_data', 'dfn_save_slot_data_in_cart', 10, 3 );
/**
 * Memorizza la data e l'eventuale ID dello slot orario selezionato dall'utente
 * all'interno dei dati dell'elemento del carrello WooCommerce.
 *
 * @param array $cart_item_data Dati correnti dell'elemento.
 * @param int   $product_id     ID prodotto.
 * @param int   $variation_id   ID variazione.
 * @return array Dati aggiornati.
 */
function dfn_save_slot_data_in_cart( $cart_item_data, $product_id, $variation_id ) {
    if ( isset( $_POST['dfn_booking_date'] ) ) {
        $cart_item_data['dfn_booking_date'] = sanitize_text_field( $_POST['dfn_booking_date'] );
    }
    if ( isset( $_POST['dfn_booking_slot_id'] ) ) {
        $cart_item_data['dfn_booking_slot_id'] = intval( $_POST['dfn_booking_slot_id'] );
    }
    if ( isset( $_POST['dfn_qty_standard'] ) ) {
        $cart_item_data['dfn_qty_standard'] = intval( $_POST['dfn_qty_standard'] );
    }
    if ( isset( $_POST['dfn_qty_fai'] ) ) {
        $cart_item_data['dfn_qty_fai'] = intval( $_POST['dfn_qty_fai'] );
    }
    return $cart_item_data;
}

add_filter( 'woocommerce_get_cart_item_from_session', 'dfn_load_slot_data_from_session', 10, 2 );
/**
 * Recupera i metadati della prenotazione dalla sessione di WooCommerce
 * per mantenerli sincronizzati durante la navigazione.
 */
function dfn_load_slot_data_from_session( $cart_item, $values ) {
    if ( isset( $values['dfn_booking_date'] ) ) {
        $cart_item['dfn_booking_date'] = $values['dfn_booking_date'];
    }
    if ( isset( $values['dfn_booking_slot_id'] ) ) {
        $cart_item['dfn_booking_slot_id'] = $values['dfn_booking_slot_id'];
    }
    if ( isset( $values['dfn_qty_standard'] ) ) {
        $cart_item['dfn_qty_standard'] = $values['dfn_qty_standard'];
    }
    if ( isset( $values['dfn_qty_fai'] ) ) {
        $cart_item['dfn_qty_fai'] = $values['dfn_qty_fai'];
    }
    return $cart_item;
}

/**
 * ========================================================================
 * 2. SALVATAGGIO NEI METADATI DELL'ORDINE WOOCOMMERCE
 * ========================================================================
 */

add_action( 'woocommerce_checkout_create_order_line_item', 'dfn_add_slot_metadata_to_order_items', 10, 4 );
/**
 * Aggiunge i metadati della prenotazione alla riga d'ordine di WooCommerce,
 * in modo da renderli permanenti nel database una volta creato l'ordine.
 */
function dfn_add_slot_metadata_to_order_items( $item, $cart_item_key, $values, $order ) {
    if ( isset( $values['dfn_booking_date'] ) ) {
        $item->add_meta_data( '_dfn_booking_date', $values['dfn_booking_date'], true );
    }
    if ( isset( $values['dfn_booking_slot_id'] ) ) {
        $item->add_meta_data( '_dfn_booking_slot_id', $values['dfn_booking_slot_id'], true );
    }
    if ( isset( $values['dfn_qty_standard'] ) ) {
        $item->add_meta_data( '_dfn_qty_standard', $values['dfn_qty_standard'], true );
    }
    if ( isset( $values['dfn_qty_fai'] ) ) {
        $item->add_meta_data( '_dfn_qty_fai', $values['dfn_qty_fai'], true );
    }
}

/**
 * ========================================================================
 * 3. CORE ALLOCATOR & TRANSACTION MANAGER
 * ========================================================================
 */

add_action( 'woocommerce_checkout_order_processed', 'dfn_allocate_slots_on_checkout', 10, 3 );
/**
 * Trigger primario all'atto della creazione dell'ordine.
 * Esegue le query transazionali con row-locking, applicando l'algoritmo di allocazione.
 *
 * @param int      $order_id ID dell'ordine appena creato.
 * @param array    $posted_data Dati inviati.
 * @param WC_Order $order Oggetto ordine WooCommerce.
 */
function dfn_allocate_slots_on_checkout( $order_id, $posted_data, $order ) {
    global $wpdb;

    // Rileva se l'ordine contiene elementi legati a eventi
    foreach ( $order->get_items() as $item_id => $item ) {
        if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
            continue;
        }
        $product_id = $item->get_product_id();
        $event      = dfn_db_get_event_by_product( $product_id );

        if ( ! $event ) {
            continue; // Non è un evento FAI
        }

        // Recupera dati del checkout salvati nei metadati della riga
        $booking_date = $item->get_meta( '_dfn_booking_date' );
        $slot_id      = intval( $item->get_meta( '_dfn_booking_slot_id' ) );
        $qty_std      = intval( $item->get_meta( '_dfn_qty_standard' ) ) ?: intval( $item->get_quantity() );
        $qty_fai      = intval( $item->get_meta( '_dfn_qty_fai' ) ) ?: 0;
        $total_qty    = $qty_std + $qty_fai;

        if ( empty( $booking_date ) ) {
            $booking_date = $event->event_date_start;
        }

        // -------------------------------------------------------------------
        // ALGORITMO DI ALLOCAZIONE DI VOLTA IN VOLTA SELEZIONATO (Fasce Orarie)
        // -------------------------------------------------------------------
        if ( 'time_slots' === $event->access_type ) {
            
            $wpdb->query( 'START TRANSACTION' );

            $need_split = false;
            $selected_slot = null;

            // Se l'evento prevede allocazione AUTOMATICA (🤖), il sistema decide il turno migliore
            if ( 'automatic' === $event->allocation_mode || $slot_id <= 0 ) {
                
                // Cerca lo slot disponibile più vicino (in ordine temporale) con capienza sufficiente (first-fit)
                $selected_slot = $wpdb->get_row( $wpdb->prepare(
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
                    $total_qty
                ) );

                if ( $selected_slot ) {
                    $slot_id = intval( $selected_slot->id );
                } else {
                    $need_split = true;
                }
            } else {
                // Modalità SELF-SELECTION (👈) — blocca la riga dello slot selezionato
                $selected_slot = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, capacity, bonus_capacity, booked_count, is_locked 
                     FROM {$wpdb->prefix}dfn_event_slots 
                     WHERE id = %d FOR UPDATE",
                    $slot_id
                ) );

                if ( $selected_slot ) {
                    $avail = ( intval( $selected_slot->capacity ) + intval( $selected_slot->bonus_capacity ) ) - intval( $selected_slot->booked_count );
                    if ( intval( $selected_slot->is_locked ) === 1 || $avail < $total_qty ) {
                        $need_split = true;
                    }
                } else {
                    $need_split = true;
                }
            }

            if ( ! $need_split && $selected_slot ) {
                // Allocazione Standard su un singolo slot
                
                // 1. Incrementa il conteggio nello slot
                $wpdb->update(
                    $wpdb->prefix . 'dfn_event_slots',
                    array( 'booked_count' => intval( $selected_slot->booked_count ) + $total_qty ),
                    array( 'id' => $slot_id ),
                    array( '%d' ),
                    array( '%d' )
                );

                // 2. Crea il record master di prenotazione
                $qr_token = wp_hash( $order_id . '|' . $event->id . '|' . time() );
                $booking_status = ( 'manual' === $event->approval_workflow ) ? 'pending_approval' : 'confirmed';

                $wpdb->insert(
                    $wpdb->prefix . 'dfn_bookings',
                    array(
                        'order_id'         => $order_id,
                        'event_id'         => $event->id,
                        'customer_email'   => $order->get_billing_email(),
                        'customer_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                        'customer_phone'   => $order->get_billing_phone(),
                        'total_persons'    => $total_qty,
                        'persons_standard' => $qty_std,
                        'persons_fai'      => $qty_fai,
                        'status'           => $booking_status,
                        'qr_token'         => $qr_token,
                        'payment_method'   => $order->get_payment_method(),
                        'amount_due'       => ( $order->get_payment_method() === 'dfn_in_loco' ) ? floatval( $order->get_total() ) : 0.00,
                        'amount_paid'      => ( $order->get_payment_method() !== 'dfn_in_loco' ) ? floatval( $order->get_total() ) : 0.00,
                        'notes'            => $order->get_customer_note()
                    ),
                    array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s' )
                );

                $booking_id = $wpdb->insert_id;

                // 3. Associa N:M il booking allo slot
                $wpdb->insert(
                    $wpdb->prefix . 'dfn_booking_slots',
                    array(
                        'booking_id' => $booking_id,
                        'slot_id'    => $slot_id,
                        'persons'    => $total_qty
                    ),
                    array( '%d', '%d', '%d' )
                );

                // Aggiorna metadati riga ordine
                $item->update_meta_data( '_dfn_booking_slot_id', (string) $slot_id );
                $item->save();

                $wpdb->query( 'COMMIT' );

                // Invio notifica centralizzata in base al workflow
                if ( 'manual' === $event->approval_workflow ) {
                    dfn_send_booking_pending_approval( $booking_id );
                    $order->add_order_note( __( '🎟️ Prenotazione FAI creata in stato: In Attesa di Approvazione Staff.', 'dfn-theme' ) );
                } else {
                    dfn_send_booking_confirmation( $booking_id );
                    $order->add_order_note( __( '🎟️ Prenotazione FAI allocata e confermata con successo!', 'dfn-theme' ) );
                }
                
                // Invia la notifica semplificata all'amministratore
                dfn_send_admin_new_booking_notification( $booking_id );

            } elseif ( $need_split ) {
                // Eseguiamo l'allocazione divisa (Split)
                $available_slots = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dfn_event_slots 
                     WHERE event_id = %d AND slot_date = %s AND is_locked = 0 
                     ORDER BY slot_time_start ASC FOR UPDATE",
                    $event->id,
                    $booking_date
                ) );

                // Prioritizza lo slot selezionato se specificato
                if ( $slot_id > 0 ) {
                    usort( $available_slots, function( $a, $b ) use ( $slot_id ) {
                        if ( intval( $a->id ) === $slot_id ) return -1;
                        if ( intval( $b->id ) === $slot_id ) return 1;
                        return strcmp( $a->slot_time_start, $b->slot_time_start );
                    } );
                }

                // Calcola lo spazio totale disponibile
                $total_avail = 0;
                foreach ( $available_slots as $s ) {
                    $total_avail += ( intval( $s->capacity ) + intval( $s->bonus_capacity ) ) - intval( $s->booked_count );
                }

                if ( $total_avail >= $total_qty ) {
                    // Abbiamo spazio sufficiente per completare lo split!
                    $qr_token = wp_hash( $order_id . '|' . $event->id . '|' . time() );
                    $booking_status = ( 'manual' === $event->approval_workflow ) ? 'pending_approval' : 'confirmed';

                    $wpdb->insert(
                        $wpdb->prefix . 'dfn_bookings',
                        array(
                            'order_id'         => $order_id,
                            'event_id'         => $event->id,
                            'customer_email'   => $order->get_billing_email(),
                            'customer_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                            'customer_phone'   => $order->get_billing_phone(),
                            'total_persons'    => $total_qty,
                            'persons_standard' => $qty_std,
                            'persons_fai'      => $qty_fai,
                            'status'           => $booking_status,
                            'qr_token'         => $qr_token,
                            'payment_method'   => $order->get_payment_method(),
                            'amount_due'       => ( $order->get_payment_method() === 'dfn_in_loco' ) ? floatval( $order->get_total() ) : 0.00,
                            'amount_paid'      => ( $order->get_payment_method() !== 'dfn_in_loco' ) ? floatval( $order->get_total() ) : 0.00,
                            'notes'            => $order->get_customer_note()
                        ),
                        array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s' )
                    );

                    $booking_id = $wpdb->insert_id;
                    $remaining = $total_qty;
                    $allocated_slots_notes = array();

                    foreach ( $available_slots as $s ) {
                        $avail = ( intval( $s->capacity ) + intval( $s->bonus_capacity ) ) - intval( $s->booked_count );
                        if ( $avail > 0 ) {
                            $to_allocate = min( $remaining, $avail );

                            // 1. Incrementa booked_count sullo slot
                            $wpdb->update(
                                $wpdb->prefix . 'dfn_event_slots',
                                array( 'booked_count' => intval( $s->booked_count ) + $to_allocate ),
                                array( 'id' => $s->id ),
                                array( '%d' ),
                                array( '%d' )
                            );

                            // 2. Associa N:M
                            $wpdb->insert(
                                $wpdb->prefix . 'dfn_booking_slots',
                                array(
                                    'booking_id' => $booking_id,
                                    'slot_id'    => $s->id,
                                    'persons'    => $to_allocate
                                ),
                                array( '%d', '%d', '%d' )
                            );

                            $time_formatted = substr( $s->slot_time_start, 0, 5 ) . '-' . substr( $s->slot_time_end, 0, 5 );
                            $allocated_slots_notes[] = sprintf( '%d in slot %s', $to_allocate, $time_formatted );

                            $remaining -= $to_allocate;
                            if ( $remaining <= 0 ) {
                                break;
                            }
                        }
                    }

                    // Aggiorna metadati riga ordine con il primo slot per retrocompatibilità
                    if ( ! empty( $available_slots ) ) {
                        $item->update_meta_data( '_dfn_booking_slot_id', (string) $available_slots[0]->id );
                        $item->save();
                    }

                    $wpdb->query( 'COMMIT' );

                    // Aggiungi nota riassuntiva sul frazionamento all'ordine
                    $split_note = sprintf(
                        __( '🎟️ Prenotazione FAI suddivisa su più turni: %s', 'dfn-theme' ),
                        implode( ', ', $allocated_slots_notes )
                    );
                    $order->add_order_note( $split_note );

                    // Invio notifica centralizzata in base al workflow
                    if ( 'manual' === $event->approval_workflow ) {
                        dfn_send_booking_pending_approval( $booking_id );
                    } else {
                        dfn_send_booking_confirmation( $booking_id );
                    }
                    dfn_send_admin_new_booking_notification( $booking_id );

                } else {
                    // OVERBOOKING: Spazio non disponibile! Annulla transazione
                    $wpdb->query( 'ROLLBACK' );

                    // Inserimento automatico in Waitlist (Lista d'Attesa)
                    $wpdb->insert(
                        $wpdb->prefix . 'dfn_waitlist',
                        array(
                            'event_id'       => $event->id,
                            'slot_id'        => $slot_id > 0 ? $slot_id : null,
                            'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                            'customer_email' => $order->get_billing_email(),
                            'customer_phone' => $order->get_billing_phone(),
                            'persons'        => $total_qty,
                            'fai_cards'      => $qty_fai,
                            'status'         => 'waiting'
                        ),
                        array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
                    );

                    $order->add_order_note( __( '⚠️ OVERBOOKING: Posti esauriti durante il checkout. Il cliente è stato inserito automaticamente in Lista d\'Attesa.', 'dfn-theme' ) );
                }
            }

        } else {
            // -------------------------------------------------------------------
            // INGRESSO LIBERO (FREE FLOW)
            // -------------------------------------------------------------------
            $wpdb->query( 'START TRANSACTION' );

            // Verifica capacità globale dell'evento
            $booked_global = $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings 
                 WHERE event_id = %d AND status != 'cancelled' FOR UPDATE",
                $event->id
            ) ) ?: 0;

            if ( ( intval( $booked_global ) + $total_qty ) <= intval( $event->total_capacity ) ) {
                
                $qr_token = wp_hash( $order_id . '|' . $event->id . '|' . time() );
                $booking_status = ( 'manual' === $event->approval_workflow ) ? 'pending_approval' : 'confirmed';

                $wpdb->insert(
                    $wpdb->prefix . 'dfn_bookings',
                    array(
                        'order_id'         => $order_id,
                        'event_id'         => $event->id,
                        'customer_email'   => $order->get_billing_email(),
                        'customer_name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                        'customer_phone'   => $order->get_billing_phone(),
                        'total_persons'    => $total_qty,
                        'persons_standard' => $qty_std,
                        'persons_fai'      => $qty_fai,
                        'status'           => $booking_status,
                        'qr_token'         => $qr_token,
                        'payment_method'   => $order->get_payment_method(),
                        'amount_due'       => ( $order->get_payment_method() === 'dfn_in_loco' ) ? floatval( $order->get_total() ) : 0.00,
                        'amount_paid'      => ( $order->get_payment_method() !== 'dfn_in_loco' ) ? floatval( $order->get_total() ) : 0.00,
                        'notes'            => $order->get_customer_note()
                    ),
                    array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s' )
                );

                $booking_id = $wpdb->insert_id;
                $wpdb->query( 'COMMIT' );

                if ( 'manual' === $event->approval_workflow ) {
                    dfn_send_booking_pending_approval( $booking_id );
                } else {
                    dfn_send_booking_confirmation( $booking_id );
                }

                // Invia la notifica semplificata all'amministratore
                dfn_send_admin_new_booking_notification( $booking_id );

            } else {
                $wpdb->query( 'ROLLBACK' );

                // Inserimento automatico in Waitlist
                $wpdb->insert(
                    $wpdb->prefix . 'dfn_waitlist',
                    array(
                        'event_id'       => $event->id,
                        'slot_id'        => null,
                        'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                        'customer_email' => $order->get_billing_email(),
                        'customer_phone' => $order->get_billing_phone(),
                        'persons'        => $total_qty,
                        'fai_cards'      => $qty_fai,
                        'status'         => 'waiting'
                    ),
                    array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
                );

                $order->add_order_note( __( '⚠️ OVERBOOKING (Free Flow): Posti esauriti. Cliente inserito in Lista d\'Attesa.', 'dfn-theme' ) );
            }
        }
    }

}

add_action( 'wp_ajax_dfn_create_direct_booking', 'dfn_ajax_create_direct_booking' );
add_action( 'wp_ajax_nopriv_dfn_create_direct_booking', 'dfn_ajax_create_direct_booking' );

/**
 * Gestisce la creazione diretta di un ordine e prenotazione via AJAX dall'interfaccia prodotto,
 * bypassando il carrello.
 */
function dfn_ajax_create_direct_booking(): void {
    check_ajax_referer( 'dfn_booking_nonce', 'nonce' );

    $event_id     = isset( $_POST['event_id'] ) ? intval( $_POST['event_id'] ) : 0;
    $product_id   = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
    $qty_standard = isset( $_POST['qty_standard'] ) ? intval( $_POST['qty_standard'] ) : 0;
    $qty_fai      = isset( $_POST['qty_fai'] ) ? intval( $_POST['qty_fai'] ) : 0;
    $date         = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
    $slot_id      = isset( $_POST['slot_id'] ) ? intval( $_POST['slot_id'] ) : 0;

    $first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( $_POST['first_name'] ) : '';
    $last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( $_POST['last_name'] ) : '';
    $email        = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
    $phone        = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
    $notes        = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

    $fai_cards_raw = isset( $_POST['fai_cards'] ) ? $_POST['fai_cards'] : array();

    if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) || empty( $phone ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Compila tutti i campi anagrafici obbligatori.', 'dfn-theme' ) ) );
    }

    $total_qty = $qty_standard + $qty_fai;
    if ( $total_qty <= 0 ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Seleziona almeno un biglietto.', 'dfn-theme' ) ) );
    }

    $event = dfn_db_get_event( $event_id );
    if ( ! $event ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Evento non valido.', 'dfn-theme' ) ) );
    }

    global $wpdb;

    $confirm_split = isset( $_POST['confirm_split'] ) && '1' === $_POST['confirm_split'];

    // Se l'evento ha fasce orarie
    if ( 'time_slots' === $event->access_type ) {
        $has_single_slot = false;

        if ( 'self_selection' === $event->allocation_mode && $slot_id > 0 ) {
            $slot = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, capacity, bonus_capacity, booked_count FROM {$wpdb->prefix}dfn_event_slots WHERE id = %d",
                $slot_id
            ) );
            if ( $slot ) {
                $avail = ( intval( $slot->capacity ) + intval( $slot->bonus_capacity ) ) - intval( $slot->booked_count );
                if ( $avail >= $total_qty ) {
                    $has_single_slot = true;
                }
            }
        } else {
            // Automatic o nessun slot specifico selezionato: cerchiamo se ne esiste almeno uno che contenga tutti
            $best_slot = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}dfn_event_slots 
                 WHERE event_id = %d AND slot_date = %s AND is_locked = 0 
                   AND (capacity + bonus_capacity - booked_count) >= %d
                 LIMIT 1",
                $event->id,
                $date,
                $total_qty
            ) );
            if ( $best_slot ) {
                $has_single_slot = true;
            }
        }

        // Se non c'è un singolo slot in grado di contenere tutti
        if ( ! $has_single_slot ) {
            // Calcoliamo la capienza totale disponibile sommando tutti gli slot non bloccati in quel giorno
            $total_avail = $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(capacity + bonus_capacity - booked_count) 
                 FROM {$wpdb->prefix}dfn_event_slots 
                 WHERE event_id = %d AND slot_date = %s AND is_locked = 0",
                $event->id,
                $date
            ) ) ?: 0;

            if ( $total_avail >= $total_qty ) {
                // Abbiamo abbastanza posti complessivi, ma devono essere divisi
                if ( ! $confirm_split ) {
                    wp_send_json_success( array(
                        'status' => 'split_warning',
                        'message' => __( 'I posti disponibili nei singoli turni non sono sufficienti per accogliere tutto il gruppo in un unico orario. Proseguendo, la prenotazione verrà suddivisa su due o più turni differenti. Vuoi continuare?', 'dfn-theme' )
                    ) );
                }
            }
        }
    }

    // 1. Processa e verifica le tessere FAI fornite
    $fai_cards = array();
    $all_verified = true;
    $unverified_cards = array();

    if ( $qty_fai > 0 && is_array( $fai_cards_raw ) ) {
        $table_members = $wpdb->prefix . 'dfn_fai_members';
        foreach ( $fai_cards_raw as $index => $card_data ) {
            $c_nome    = isset( $card_data['nome'] ) ? sanitize_text_field( $card_data['nome'] ) : '';
            $c_cognome = isset( $card_data['cognome'] ) ? sanitize_text_field( $card_data['cognome'] ) : '';
            $c_num     = isset( $card_data['tessera'] ) ? sanitize_text_field( $card_data['tessera'] ) : '';

            if ( empty( $c_nome ) || empty( $c_cognome ) || empty( $c_num ) ) {
                wp_send_json_error( array( 'message' => sprintf( esc_html__( 'Dati tessera Socio FAI incompleti per il partecipante #%d.', 'dfn-theme' ), $index + 1 ) ) );
            }

            // Verifica nel DB
            $member = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$table_members} WHERE card_number = %s AND verified = 1 AND card_expiry >= CURDATE() LIMIT 1",
                $c_num
            ) );

            if ( ! $member ) {
                $all_verified = false;
                $unverified_cards[] = $c_num;

                // Salva nel DB come da verificare
                $wpdb->insert(
                    $table_members,
                    array(
                        'first_name'  => $c_nome,
                        'last_name'   => $c_cognome,
                        'email'       => $email, // Collega email acquirente per contatto
                        'card_number' => $c_num,
                        'card_expiry' => null,
                        'verified'    => 0,
                    ),
                    array( '%s', '%s', '%s', '%s', '%s', '%d' )
                );
            }

            $fai_cards[] = array(
                'nome'    => $c_nome,
                'cognome' => $c_cognome,
                'tessera' => $c_num,
            );
        }
    }

    // 2. Creazione programmatica dell'ordine WooCommerce
    try {
        $order = wc_create_order();
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            throw new \Exception( 'Prodotto WooCommerce non trovato.' );
        }

        $order->add_product( $product, $total_qty );

        // Applica i dati di fatturazione minimi
        $order->set_billing_first_name( $first_name );
        $order->set_billing_last_name( $last_name );
        $order->set_billing_email( $email );
        $order->set_billing_phone( $phone );
        $order->set_customer_note( $notes );
        $order->set_payment_method( 'dfn_in_loco' );

        // Applica sconto Soci FAI se presente
        $price_standard = floatval( $event->price_standard );
        $price_fai      = floatval( $event->price_fai );
        $unit_discount  = max( 0.00, $price_standard - $price_fai );
        $total_discount = $unit_discount * $qty_fai;

        if ( $total_discount > 0.00 ) {
            $item_fee = new \WC_Order_Item_Fee();
            $item_fee->set_name( sprintf( 'Sconto Soci FAI (%d tessere)', $qty_fai ) );
            $item_fee->set_amount( (string) (-$total_discount) );
            $item_fee->set_total( (string) (-$total_discount) );
            $order->add_item( $item_fee );
        }

        $order->calculate_totals();

        // Aggiungi metadati per l'allocazione alla riga prodotto
        foreach ( $order->get_items() as $item ) {
            if ( is_a( $item, 'WC_Order_Item_Product' ) && $item->get_product_id() === $product_id ) {
                $item->update_meta_data( '_dfn_booking_date', $date );
                $item->update_meta_data( '_dfn_booking_slot_id', (string) $slot_id );
                $item->update_meta_data( '_dfn_qty_standard', (string) $qty_standard );
                $item->update_meta_data( '_dfn_qty_fai', (string) $qty_fai );
                $item->save();
            }
        }

        // Salva metadati generali ordine
        $order->update_meta_data( '_dfn_payment_in_loco', 'yes' );
        if ( ! empty( $fai_cards ) ) {
            $order->update_meta_data( '_dfn_fai_cards', $fai_cards );
        }
        $order->save();

        // Passa lo stato a pending
        $order->update_status( 'pending', __( 'Prenotazione diretta via widget.', 'dfn-theme' ) );
        wc_reduce_stock_levels( $order->get_id() );

        // 3. Esegui allocazione
        dfn_allocate_slots_on_checkout( $order->get_id(), array(), $order );

        // Svuota carrello per sicurezza
        if ( WC()->cart instanceof \WC_Cart ) {
            WC()->cart->empty_cart();
        }

        // Verifica esito dell'allocazione (Waitlist o Booking)
        $booking = dfn_db_get_booking_by_order( $order->get_id() );
        
        $response_data = array(
            'order_id'         => $order->get_id(),
            'total_confirmed'  => $all_verified,
            'unverified_cards' => $unverified_cards,
            'amount_due'       => floatval( $order->get_total() ),
            'amount_standard'  => $price_standard * $total_qty,
        );

        if ( $booking ) {
            $response_data['status'] = 'confirmed';
            $response_data['message'] = sprintf(
                esc_html__( 'La tua prenotazione è confermata per il giorno %s.', 'dfn-theme' ),
                date_i18n( 'd F Y', strtotime( $date ) )
            );
        } else {
            $response_data['status'] = 'waitlist';
            $response_data['message'] = esc_html__( 'Posti esauriti. Sei stato inserito con priorità in Lista d\'Attesa! Riceverai una notifica se si libererà un turno.', 'dfn-theme' );
        }

        wp_send_json_success( $response_data );

    } catch ( \Exception $e ) {
        wp_send_json_error( array( 'message' => $e->getMessage() ) );
    }
}


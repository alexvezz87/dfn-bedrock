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

            // Se l'evento prevede allocazione AUTOMATICA (🤖), il sistema decide il turno migliore
            if ( 'automatic' === $event->allocation_mode || $slot_id <= 0 ) {
                
                // Cerca lo slot con maggior disponibilità residua in quella data
                $best_slot = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, capacity, bonus_capacity, booked_count 
                     FROM {$wpdb->prefix}dfn_event_slots 
                     WHERE event_id = %d 
                       AND slot_date = %s 
                       AND is_locked = 0 
                     ORDER BY (capacity - booked_count) DESC, slot_time_start ASC 
                     LIMIT 1 FOR UPDATE",
                    $event->id,
                    $booking_date
                ) );

                if ( $best_slot ) {
                    $slot_id = intval( $best_slot->id );
                    $item->update_meta_data( '_dfn_booking_slot_id', $slot_id );
                    $item->save();
                }
            } else {
                // Modalità SELF-SELECTION (👈) — blocca la riga dello slot selezionato
                $wpdb->query( $wpdb->prepare(
                    "SELECT id, capacity, bonus_capacity, booked_count 
                     FROM {$wpdb->prefix}dfn_event_slots 
                     WHERE id = %d FOR UPDATE",
                    $slot_id
                ) );
            }

            // Recuperiamo i dati aggiornati dello slot bloccato
            $slot = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dfn_event_slots WHERE id = %d",
                $slot_id
            ) );

            // Verifica se c'è spazio sufficiente (inclusa la capacità bonus)
            if ( $slot && ( intval( $slot->booked_count ) + $total_qty ) <= ( intval( $slot->capacity ) + intval( $slot->bonus_capacity ) ) ) {
                
                // 1. Incrementa il conteggio nello slot
                $wpdb->update(
                    $wpdb->prefix . 'dfn_event_slots',
                    array( 'booked_count' => intval( $slot->booked_count ) + $total_qty ),
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

                $wpdb->query( 'COMMIT' );

                // Invio notifica centralizzata in base al workflow
                if ( 'manual' === $event->approval_workflow ) {
                    dfn_send_booking_pending_approval( $booking_id );
                    $order->add_order_note( __( '🎟️ Prenotazione FAI creata in stato: In Attesa di Approvazione Staff.', 'dfn-theme' ) );
                } else {
                    dfn_send_booking_confirmation( $booking_id );
                    $order->add_order_note( __( '🎟️ Prenotazione FAI allocata e confermata con successo!', 'dfn-theme' ) );
                }

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

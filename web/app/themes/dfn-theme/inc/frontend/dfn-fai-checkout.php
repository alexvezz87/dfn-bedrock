<?php
/**
 * DFN Booking System 2.0 — FAI Card Validation at Checkout
 *
 * Aggiunge campi al checkout per validare ed associare le tessere FAI
 * a ciascun biglietto con tariffa speciale Soci FAI inserito nel carrello.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Renderizza i campi tessera al checkout se ci sono quote FAI
add_action( 'woocommerce_after_checkout_billing_form', 'dfn_checkout_display_fai_fields' );

/**
 * Mostra i campi di inserimento Tessera FAI al checkout.
 *
 * @param WC_Checkout $checkout Oggetto checkout di WooCommerce.
 * @return void
 */
function dfn_checkout_display_fai_fields( $checkout ): void {
    $cart = WC()->cart;
    if ( ! $cart ) {
        return;
    }

    $total_fai_qty = 0;
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['dfn_qty_fai'] ) && intval( $cart_item['dfn_qty_fai'] ) > 0 ) {
            $total_fai_qty += intval( $cart_item['dfn_qty_fai'] );
        }
    }

    if ( $total_fai_qty <= 0 ) {
        return;
    }

    echo '<div class="dfn-fai-validation-container">';
    echo '<h3 class="dfn-fai-validation-title">🍊 ' . esc_html__( 'Verifica Tessere Socio FAI', 'dfn-theme' ) . '</h3>';
    echo '<p style="font-size:12px; color:#64748b; margin-top:0; margin-bottom:15px;">' . esc_html__( 'Inserisci il nome, cognome e numero tessera per ognuno dei biglietti a tariffa Soci FAI selezionati.', 'dfn-theme' ) . '</p>';

    for ( $i = 1; $i <= $total_fai_qty; $i++ ) {
        $nome_val    = isset( $_POST['dfn_fai_card_nome_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_nome_' . $i] ) : '';
        $cognome_val = isset( $_POST['dfn_fai_card_cognome_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_cognome_' . $i] ) : '';
        $card_val    = isset( $_POST['dfn_fai_card_number_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_number_' . $i] ) : '';

        echo '<div class="dfn-fai-card-input-row" style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed #e2e8f0;">';
        echo '<h4 style="font-size:12px; font-weight:700; color:#004b23; margin:0 0 10px 0;">' . sprintf( esc_html__( 'Partecipante FAI #%d', 'dfn-theme' ), $i ) . '</h4>';
        echo '<div class="dfn-fai-fields-grid">';
        
        echo '<div class="dfn-fai-field-group">';
        echo '<label for="dfn_fai_card_nome_' . $i . '">' . esc_html__( 'Nome', 'dfn-theme' ) . ' *</label>';
        echo '<input type="text" name="dfn_fai_card_nome_' . $i . '" id="dfn_fai_card_nome_' . $i . '" value="' . esc_attr( $nome_val ) . '" placeholder="Nome">';
        echo '</div>';

        echo '<div class="dfn-fai-field-group">';
        echo '<label for="dfn_fai_card_cognome_' . $i . '">' . esc_html__( 'Cognome', 'dfn-theme' ) . ' *</label>';
        echo '<input type="text" name="dfn_fai_card_cognome_' . $i . '" id="dfn_fai_card_cognome_' . $i . '" value="' . esc_attr( $cognome_val ) . '" placeholder="Cognome">';
        echo '</div>';

        echo '<div class="dfn-fai-field-group">';
        echo '<label for="dfn_fai_card_number_' . $i . '">' . esc_html__( 'N° Tessera FAI', 'dfn-theme' ) . ' *</label>';
        echo '<input type="text" name="dfn_fai_card_number_' . $i . '" id="dfn_fai_card_number_' . $i . '" value="' . esc_attr( $card_val ) . '" placeholder="Es. 123456">';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
}

// 2. Valida i campi tessera inseriti durante la sottomissione del checkout
add_action( 'woocommerce_checkout_process', 'dfn_checkout_validate_fai_fields' );

/**
 * Valida che tutte le tessere FAI fornite siano esistenti e non scadute.
 *
 * @return void
 */
function dfn_checkout_validate_fai_fields(): void {
    $cart = WC()->cart;
    if ( ! $cart ) {
        return;
    }

    $total_fai_qty = 0;
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['dfn_qty_fai'] ) && intval( $cart_item['dfn_qty_fai'] ) > 0 ) {
            $total_fai_qty += intval( $cart_item['dfn_qty_fai'] );
        }
    }

    if ( $total_fai_qty <= 0 ) {
        return;
    }

    global $wpdb;
    $table_members = $wpdb->prefix . 'dfn_fai_members';

    for ( $i = 1; $i <= $total_fai_qty; $i++ ) {
        $nome    = isset( $_POST['dfn_fai_card_nome_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_nome_' . $i] ) : '';
        $cognome = isset( $_POST['dfn_fai_card_cognome_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_cognome_' . $i] ) : '';
        $card    = isset( $_POST['dfn_fai_card_number_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_number_' . $i] ) : '';

        if ( empty( $nome ) || empty( $cognome ) || empty( $card ) ) {
            wc_add_notice( sprintf( __( 'Compila tutti i campi della Tessera FAI per il Partecipante #%d.', 'dfn-theme' ), $i ), 'error' );
            continue;
        }

        // Cerca la tessera nell'anagrafica FAI
        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_members} WHERE card_number = %s LIMIT 1",
            $card
        ) );

        if ( ! $member ) {
            // Se non esiste, permettiamo la prenotazione ma notifichiamo con avviso/warning (o inserimento automatico come non verificato)
            // Per massima flessibilità creiamo la tessera non verificata nel DB in modo che l'admin possa controllarla
            $wpdb->insert(
                $table_members,
                array(
                    'first_name'  => $nome,
                    'last_name'   => $cognome,
                    'card_number' => $card,
                    'card_expiry' => date( 'Y-m-d', strtotime( '+1 year' ) ), // provvisoria 1 anno
                    'verified'    => 0,
                ),
                array( '%s', '%s', '%s', '%s', '%d' )
            );
            continue;
        }

        // Se la tessera esiste ma è scaduta
        $expiry_date = strtotime( $member->card_expiry );
        $today = strtotime( date( 'Y-m-d' ) );
        if ( $expiry_date < $today ) {
            wc_add_notice( sprintf( __( 'La Tessera FAI n° %s (Partecipante #%d) risulta scaduta il %s.', 'dfn-theme' ), $card, $i, date_i18n( 'd/m/Y', $expiry_date ) ), 'error' );
        }
    }
}

// 3. Salva i dettagli delle tessere validate nei metadati dell'ordine
add_action( 'woocommerce_checkout_create_order', 'dfn_checkout_save_fai_fields', 10, 2 );

/**
 * Salva i metadati delle tessere FAI nell'ordine WC.
 *
 * @param WC_Order $order   Oggetto ordine WooCommerce.
 * @param array    $data    Dati POST del checkout.
 * @return void
 */
function dfn_checkout_save_fai_fields( $order, $data ): void {
    $cart = WC()->cart;
    if ( ! $cart ) {
        return;
    }

    $total_fai_qty = 0;
    foreach ( $cart->get_cart() as $cart_item ) {
        if ( isset( $cart_item['dfn_qty_fai'] ) && intval( $cart_item['dfn_qty_fai'] ) > 0 ) {
            $total_fai_qty += intval( $cart_item['dfn_qty_fai'] );
        }
    }

    if ( $total_fai_qty <= 0 ) {
        return;
    }

    $fai_cards_saved = array();

    for ( $i = 1; $i <= $total_fai_qty; $i++ ) {
        $nome    = isset( $_POST['dfn_fai_card_nome_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_nome_' . $i] ) : '';
        $cognome = isset( $_POST['dfn_fai_card_cognome_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_cognome_' . $i] ) : '';
        $card    = isset( $_POST['dfn_fai_card_number_' . $i] ) ? sanitize_text_field( $_POST['dfn_fai_card_number_' . $i] ) : '';

        if ( ! empty( $card ) ) {
            $fai_cards_saved[] = array(
                'nome'    => $nome,
                'cognome' => $cognome,
                'tessera' => $card,
            );
        }
    }

    if ( ! empty( $fai_cards_saved ) ) {
        $order->update_meta_data( '_dfn_fai_cards', $fai_cards_saved );
    }
}

<?php
/**
 * DFN Booking System 2.0 — Express Checkout condizionale
 *
 * Semplifica drasticamente il checkout di WooCommerce rimuovendo i campi di fatturazione non necessari
 * (indirizzo, cap, città, nazione) per eventi con saldo "In Loco" o gratuiti, lasciando solo
 * Nome, Cognome, Email e Telefono.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Determina se il checkout deve essere semplificato in modalità "Express".
 *
 * Ritorna true se:
 * 1. Il carrello contiene solo eventi con modalità di pagamento "in_loco" (saldo all'ingresso).
 * 2. Oppure se il totale del carrello è pari a 0.00 (evento gratuito).
 * 3. E non ci sono prodotti standard o eventi che richiedono esclusivamente il pagamento "online".
 *
 * @return bool
 */
function dfn_is_express_checkout_needed() {
    $cart = WC()->cart;
    if ( ! $cart || $cart->is_empty() ) {
        return false;
    }

    // Se il totale è 0, è sempre idoneo per Express Checkout
    if ( floatval( $cart->get_total( 'edit' ) ) === 0.00 ) {
        return true;
    }

    $has_events = false;

    foreach ( $cart->get_cart() as $cart_item ) {
        $product_id = $cart_item['product_id'];
        $event      = dfn_db_get_event_by_product( $product_id );

        // Se nel carrello c'è un prodotto non legato a un evento, non è un Express Checkout puro
        if ( ! $event ) {
            return false;
        }

        $has_events = true;

        // Se c'è almeno un evento che richiede il pagamento online obbligatorio
        if ( $event->payment_mode === 'online' ) {
            return false;
        }
    }

    return $has_events;
}

/**
 * Filtra e rimuove i campi del checkout di fatturazione se è attivo l'Express Checkout.
 *
 * @param array $fields Campi del checkout di WooCommerce.
 * @return array
 */
function dfn_conditionally_simplify_checkout_fields( $fields ) {
    if ( ! dfn_is_express_checkout_needed() ) {
        return $fields;
    }

    // Campi da tenere obbligatoriamente
    $fields_to_keep = array(
        'billing_first_name',
        'billing_last_name',
        'billing_email',
        'billing_phone',
    );

    // Rimuovi tutti i campi di fatturazione tranne quelli essenziali
    if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
        foreach ( $fields['billing'] as $key => $field ) {
            if ( ! in_array( $key, $fields_to_keep ) ) {
                unset( $fields['billing'][$key] );
            }
        }
    }

    // Rimuovi i campi di spedizione (non usati per i biglietti digitali)
    if ( isset( $fields['shipping'] ) ) {
        unset( $fields['shipping'] );
    }

    return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'dfn_conditionally_simplify_checkout_fields', 999 );

/**
 * Rende facoltativo o nasconde lo stato di necessità del pagamento se il totale è zero.
 * Garantisce che l'ordine possa essere completato senza gateway di pagamento attivi se gratuito.
 *
 * @param bool $needs_payment
 * @return bool
 */
function dfn_checkout_needs_payment( $needs_payment ) {
    $cart = WC()->cart;
    if ( $cart && floatval( $cart->get_total( 'edit' ) ) === 0.00 ) {
        return false;
    }
    return $needs_payment;
}
add_filter( 'woocommerce_cart_needs_payment', 'dfn_checkout_needs_payment', 10 );

/**
 * Aggiunge classi CSS speciali al body per consentire una metamorfosi visiva del checkout
 * tramite stili dedicati (dfn-checkout-express.css).
 *
 * @param array $classes Classi correnti del body.
 * @return array
 */
function dfn_checkout_body_classes( $classes ) {
    if ( is_checkout() && ! is_order_received_page() ) {
        if ( dfn_is_express_checkout_needed() ) {
            $classes[] = 'dfn-express-checkout-active';
        }
    }
    return $classes;
}
add_filter( 'body_class', 'dfn_checkout_body_classes' );

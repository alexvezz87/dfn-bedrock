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

if (! defined('ABSPATH')) {
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
function dfn_is_express_checkout_needed()
{
    $cart = WC()->cart;
    if (! $cart || $cart->is_empty()) {
        return false;
    }

    // Se il totale è 0, è sempre idoneo per Express Checkout
    if (floatval($cart->get_total('edit')) === 0.00) {
        return true;
    }

    $has_events = false;

    foreach ($cart->get_cart() as $cart_item) {
        $product_id = $cart_item['product_id'];
        $event      = dfn_db_get_event_by_product($product_id);

        // Se nel carrello c'è un prodotto non legato a un evento, non è un Express Checkout puro
        if (! $event) {
            return false;
        }

        $has_events = true;

        // Se c'è almeno un evento che richiede il pagamento online obbligatorio
        if ($event->payment_mode === 'online') {
            return false;
        }
    }

    return $has_events;
}

/**
 * Evita la duplicazione della checkbox di accettazione contributo non rimborsabile
 * se è già presente nella sezione di fatturazione.
 *
 * @param array $fields Campi del checkout di WooCommerce.
 * @return array
 */
function dfn_deduplicate_checkout_fields($fields)
{
    if (isset($fields['billing']['accettazione'])) {
        if (isset($fields['order']['accettazione'])) {
            unset($fields['order']['accettazione']);
        }
        if (isset($fields['additional']['accettazione'])) {
            unset($fields['additional']['accettazione']);
        }
    }
    return $fields;
}
add_filter('woocommerce_checkout_fields', 'dfn_deduplicate_checkout_fields', 9999);

/**
 * Pulisce le righe dei totali ordine per evitare che checkbox legali o campi booleani
 * vengano stampati come voci di costo nella tabella dei totali della Thank You page.
 *
 * @param array $total_rows
 * @param WC_Order $order
 * @param string $tax_display
 * @return array
 */
function dfn_clean_order_item_totals($total_rows, $order, $tax_display)
{
    if (! is_array($total_rows)) {
        return $total_rows;
    }

    foreach ($total_rows as $key => $row) {
        if (
            strpos(strtolower($key), 'accettazione') !== false
            || strpos(strtolower($key), 'thwcfd') !== false
            || (isset($row['label']) && (
                stripos($row['label'], 'accettazione') !== false
                || stripos($row['label'], 'rimborsabile') !== false
                || stripos($row['label'], 'questo contributo') !== false
                || stripos($row['label'], 'fondazione') !== false
            ))
            || (isset($row['value']) && ($row['value'] === '1' || $row['value'] === 1))
        ) {
            unset($total_rows[$key]);
        }
    }
    return $total_rows;
}
add_filter('woocommerce_get_order_item_totals', 'dfn_clean_order_item_totals', 99, 3);

/**
 * Rimuove il campo di consenso / accettazione dai dettagli ordine di THWCFD.
 */
add_filter('thwcfd_order_details_display_fields', function ($fields) {
    if (is_array($fields) && isset($fields['accettazione'])) {
        unset($fields['accettazione']);
    }
    return $fields;
}, 999);
add_filter('thwcfd_display_custom_fields_in_order_details', '__return_false', 999);

/**
 * Rende facoltativo o nasconde lo stato di necessità del pagamento se il totale è zero.
 * Garantisce che l'ordine possa essere completato senza gateway di pagamento attivi se gratuito.
 *
 * @param bool $needs_payment
 * @return bool
 */
function dfn_checkout_needs_payment($needs_payment)
{
    $cart = WC()->cart;
    if ($cart && floatval($cart->get_total('edit')) === 0.00) {
        return false;
    }
    return $needs_payment;
}
add_filter('woocommerce_cart_needs_payment', 'dfn_checkout_needs_payment', 10);

add_action('woocommerce_cart_calculate_fees', 'dfn_apply_fai_members_discount_to_cart', 10, 1);
/**
 * Calcola e applica dinamicamente lo sconto per Soci FAI nel carrello/checkout.
 * Lo sconto è pari alla differenza tra tariffa standard e tariffa FAI,
 * moltiplicato per il numero di tessere FAI indicate.
 *
 * @param WC_Cart $cart Oggetto carrello di WooCommerce.
 * @return void
 */
function dfn_apply_fai_members_discount_to_cart($cart)
{
    if (is_admin() && ! defined('DOING_AJAX')) {
        return;
    }

    $total_discount = 0.00;
    $total_fai_qty  = 0;

    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        $qty_fai    = isset($cart_item['dfn_qty_fai']) ? intval($cart_item['dfn_qty_fai']) : 0;

        if ($qty_fai <= 0) {
            continue;
        }

        // Recupera l'evento legato al prodotto per conoscerne i prezzi dedicati
        $event = dfn_db_get_event_by_product($product_id);
        if (! $event) {
            continue;
        }

        $price_standard = floatval($event->price_standard);
        $price_fai      = floatval($event->price_fai);

        // La scontistica unitaria è la differenza tra biglietto ordinario e socio FAI
        $unit_discount = $price_standard - $price_fai;

        if ($unit_discount > 0.00) {
            $total_discount += ($unit_discount * $qty_fai);
            $total_fai_qty  += $qty_fai;
        }
    }

    // Se c'è uno sconto calcolato per tessere FAI valide, lo applica come fee negativa
    if ($total_discount > 0.00 && $total_fai_qty > 0) {
        $fee_label = sprintf(__('Sconto Soci FAI (%d %s)', 'dfn-theme'), $total_fai_qty, $total_fai_qty > 1 ? __('tessere', 'dfn-theme') : __('tessera', 'dfn-theme'));
        $cart->add_fee(
            $fee_label,
            -$total_discount,
            false,
        );
    }
}

/**
 * Pre-popola i campi di fatturazione del checkout di WooCommerce utilizzando i dati salvati
 * nella sessione di WooCommerce (es. provenienti dal widget di prenotazione).
 */
function dfn_prefill_checkout_billing_fields($value, $input)
{
    if (WC()->session) {
        switch ($input) {
            case 'billing_first_name':
                $session_val = WC()->session->get('dfn_checkout_first_name');
                if ($session_val) {
                    return $session_val;
                }
                break;
            case 'billing_last_name':
                $session_val = WC()->session->get('dfn_checkout_last_name');
                if ($session_val) {
                    return $session_val;
                }
                break;
            case 'billing_email':
                $session_val = WC()->session->get('dfn_checkout_email');
                if ($session_val) {
                    return $session_val;
                }
                break;
            case 'billing_phone':
                $session_val = WC()->session->get('dfn_checkout_phone');
                if ($session_val) {
                    return $session_val;
                }
                break;
            case 'order_comments':
                $session_val = WC()->session->get('dfn_checkout_notes');
                if ($session_val) {
                    return $session_val;
                }
                break;
        }
    }
    return $value;
}
add_filter('woocommerce_checkout_get_value', 'dfn_prefill_checkout_billing_fields', 10, 2);

/**
 * Garantisce che le note inserite nella modale del widget vengano trasferite all'ordine WooCommerce al checkout.
 *
 * @param WC_Order $order Oggetto ordine WooCommerce in creazione.
 */
function dfn_sync_customer_notes_to_order($order)
{
    if (empty($order->get_customer_note()) && WC()->session) {
        $session_notes = WC()->session->get('dfn_checkout_notes');
        if (! empty($session_notes)) {
            $order->set_customer_note($session_notes);
        }
    }
}
add_action('woocommerce_checkout_create_order', 'dfn_sync_customer_notes_to_order', 10, 1);

/**
 * Visualizza un box di avviso in evidenza al checkout per ricordare la scadenza oraria dei posti riservati.
 */
function dfn_render_checkout_payment_deadline_box(): void
{
    // 1. Caso pagina di pagamento per ordine esistente (order-pay)
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
        global $wp;
        $order_id = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if ($order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->needs_payment()) {
                $booking = function_exists('dfn_db_get_booking_by_order') ? dfn_db_get_booking_by_order($order_id) : null;
                $auto_cancel_hours = 24;
                if ($booking && function_exists('dfn_db_get_event')) {
                    $event = dfn_db_get_event((int) $booking->event_id);
                    if ($event && isset($event->auto_cancel_hours)) {
                        $auto_cancel_hours = (int) $event->auto_cancel_hours;
                    }
                } else {
                    $auto_cancel_hours = (int) dfn_get_setting('cron_timeout_no_booking', 24);
                }

                if ($auto_cancel_hours > 0) {
                    dfn_output_checkout_deadline_html($auto_cancel_hours, true);
                }
            }
        }
        return;
    }

    // 2. Caso carrello / checkout standard WooCommerce
    $cart = WC()->cart;
    if (! $cart || $cart->is_empty()) {
        return;
    }

    // Se l'importo totale è zero (gratuito) o è un checkout express 'in loco' puro, non serve l'avviso di pagamento online
    if (floatval($cart->get_total('edit')) === 0.00) {
        return;
    }

    $auto_cancel_hours = 0;
    $has_online_event  = false;

    foreach ($cart->get_cart() as $cart_item) {
        $product_id = $cart_item['product_id'] ?? 0;
        $event      = function_exists('dfn_db_get_event_by_product') ? dfn_db_get_event_by_product($product_id) : null;

        if ($event) {
            $pay_mode = $event->payment_mode ?? 'online';
            if ($pay_mode === 'online' || $pay_mode === 'misto') {
                $has_online_event = true;
                $event_hours = isset($event->auto_cancel_hours) ? (int) $event->auto_cancel_hours : 24;
                if ($event_hours > 0 && ($auto_cancel_hours === 0 || $event_hours < $auto_cancel_hours)) {
                    $auto_cancel_hours = $event_hours;
                }
            }
        }
    }

    if ($has_online_event && $auto_cancel_hours > 0) {
        dfn_output_checkout_deadline_html($auto_cancel_hours, false);
    }
}
add_action('woocommerce_before_checkout_form', 'dfn_render_checkout_payment_deadline_box', 5);
add_action('before_woocommerce_pay', 'dfn_render_checkout_payment_deadline_box', 5);

/**
 * Renderizza l'HTML del box di avviso termine pagamento al checkout.
 */
function dfn_output_checkout_deadline_html(int $auto_cancel_hours, bool $is_order_pay = false): void
{
    ?>
    <div class="dfn-checkout-deadline-notice" style="max-width: 600px; margin: 0 auto 24px auto; background: #fffbeb; border: 1.5px solid #fde68a; border-left: 5px solid #d97706; border-radius: 10px; padding: 16px 20px; box-shadow: 0 2px 6px rgba(217, 119, 6, 0.08); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; box-sizing: border-box;">
        <div style="display: flex; align-items: flex-start; gap: 14px;">
            <span style="font-size: 24px; line-height: 1.2; flex-shrink: 0;">⏱️</span>
            <div>
                <strong style="color: #b45309; font-size: 14.5px; font-weight: 700; display: block; margin-bottom: 4px;">
                    <?php esc_html_e('Termine per il completamento del contributo', 'dfn-theme'); ?>
                </strong>
                <p style="margin: 0; font-size: 13.5px; color: #92400e; line-height: 1.5;">
                    <?php if ($is_order_pay) : ?>
                        <?php printf(
                            esc_html__('I tuoi posti sono temporaneamente riservati. Ti ricordiamo che hai a disposizione un massimo di %d ore dall\'invio della richiesta di pagamento per completare la transazione prima che i posti vengano automaticamente liberati.', 'dfn-theme'),
                            $auto_cancel_hours
                        ); ?>
                    <?php else : ?>
                        <?php printf(
                            esc_html__('I posti selezionati rimarranno riservati per un massimo di %d ore. Ti invitiamo a completare il pagamento online per garantire e confermare definitivamente la tua prenotazione prima della scadenza.', 'dfn-theme'),
                            $auto_cancel_hours
                        ); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    <?php
}



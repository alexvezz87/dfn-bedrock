<?php
/**
 * DFN Booking System 2.0 — Native Order Pay Template
 *
 * Template dedicato ed istituzionale per la pagina di pagamento di ordini differiti
 * (link inviati via email per tessere FAI approvate, botteghino live, solleciti).
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 */

defined('ABSPATH') || exit;

$totals = $order->get_order_item_totals();
$booking = function_exists('dfn_db_get_booking_by_order') ? dfn_db_get_booking_by_order($order->get_id()) : null;
$event = ($booking && function_exists('dfn_db_get_event')) ? dfn_db_get_event((int) $booking->event_id) : null;
$fai_cards = $order->get_meta('_dfn_fai_cards');
?>

<div class="dfn-checkout-container dfn-pay-order-container">

    <div class="dfn-pay-header">
        <div class="dfn-pay-header-badge">
            <span class="dfn-pulse-dot"></span>
            <?php esc_html_e('In attesa di pagamento', 'dfn-theme'); ?>
        </div>
        <h1 class="dfn-pay-title">
            <?php printf(esc_html__('Completa il pagamento della Prenotazione #%s', 'dfn-theme'), esc_html($order->get_order_number())); ?>
        </h1>
        <p class="dfn-pay-subtitle">
            <?php esc_html_e('I tuoi posti sono temporaneamente riservati. Seleziona il metodo di pagamento per confermare definitivamente i biglietti.', 'dfn-theme'); ?>
        </p>
    </div>

    <form id="order_review" method="post" class="dfn-pay-form">

        <div class="dfn-checkout-layout">

            <!-- Colonna Sinistra: Riepilogo Dettagli Prenotazione & Partecipanti -->
            <div class="dfn-checkout-main">

                <!-- Card Dettagli Ordine ed Evento -->
                <div class="dfn-card dfn-order-details-card">
                    <div class="dfn-card-header">
                        <div class="dfn-card-header-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </div>
                        <div>
                            <h2 class="dfn-card-title"><?php esc_html_e('Riepilogo Biglietti & Evento', 'dfn-theme'); ?></h2>
                            <p class="dfn-card-subtitle"><?php esc_html_e('Dettaglio dei posti e delle quote riservate per te.', 'dfn-theme'); ?></p>
                        </div>
                    </div>

                    <div class="dfn-card-body">
                        <?php if ($event) : ?>
                            <div class="dfn-event-pay-highlight">
                                <div class="dfn-event-pay-title"><?php echo esc_html($event->title); ?></div>
                                <?php if (! empty($event->event_date)) : ?>
                                    <div class="dfn-event-pay-meta">
                                        <span>📅 <?php echo esc_html(date_i18n('l d F Y', strtotime($event->event_date))); ?></span>
                                        <?php if (! empty($event->event_time)) : ?>
                                            <span>⏰ <?php echo esc_html(substr($event->event_time, 0, 5)); ?></span>
                                        <?php endif; ?>
                                        <?php if (! empty($event->location)) : ?>
                                            <span>📍 <?php echo esc_html($event->location); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <table class="dfn-table shop_table">
                            <thead>
                                <tr>
                                    <th class="product-name"><?php esc_html_e('Voce', 'dfn-theme'); ?></th>
                                    <th class="product-quantity"><?php esc_html_e('Qtà', 'dfn-theme'); ?></th>
                                    <th class="product-total"><?php esc_html_e('Importo', 'dfn-theme'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($order->get_items()) > 0) : ?>
                                    <?php foreach ($order->get_items() as $item_id => $item) : ?>
                                        <?php if (! apply_filters('woocommerce_order_item_visible', true, $item)) continue; ?>
                                        <tr class="<?php echo esc_attr(apply_filters('woocommerce_order_item_class', 'order_item', $item, $order)); ?>">
                                            <td class="product-name">
                                                <strong><?php echo wp_kses_post(apply_filters('woocommerce_order_item_name', $item->get_name(), $item, false)); ?></strong>
                                                <?php wc_display_item_meta($item); ?>
                                            </td>
                                            <td class="product-quantity"><?php echo esc_html($item->get_quantity()); ?></td>
                                            <td class="product-subtotal"><?php echo $order->get_formatted_line_subtotal($item); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if (! empty($fai_cards) && is_array($fai_cards)) : ?>
                            <div class="dfn-pay-fai-cards">
                                <h3 class="dfn-pay-fai-title">🏷️ <?php esc_html_e('Tessere Soci FAI Convalidate', 'dfn-theme'); ?></h3>
                                <div class="dfn-pay-fai-list">
                                    <?php foreach ($fai_cards as $idx => $card) : ?>
                                        <div class="dfn-pay-fai-item">
                                            <span class="dfn-pay-fai-name"><?php echo esc_html(trim(($card['nome'] ?? '') . ' ' . ($card['cognome'] ?? ''))); ?></span>
                                            <span class="dfn-pay-fai-badge">N° <?php echo esc_html($card['tessera'] ?? ''); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="dfn-pay-customer-recap">
                            <div class="dfn-recap-row">
                                <span class="dfn-recap-label"><?php esc_html_e('Intestatario:', 'dfn-theme'); ?></span>
                                <span class="dfn-recap-val"><?php echo esc_html($order->get_formatted_billing_full_name()); ?></span>
                            </div>
                            <div class="dfn-recap-row">
                                <span class="dfn-recap-label"><?php esc_html_e('Email:', 'dfn-theme'); ?></span>
                                <span class="dfn-recap-val"><?php echo esc_html($order->get_billing_email()); ?></span>
                            </div>
                            <?php if ($order->get_billing_phone()) : ?>
                                <div class="dfn-recap-row">
                                    <span class="dfn-recap-label"><?php esc_html_e('Telefono:', 'dfn-theme'); ?></span>
                                    <span class="dfn-recap-val"><?php echo esc_html($order->get_billing_phone()); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Box Informativo di Garanzia & Sicurezza -->
                <div class="dfn-checkout-trust-badges">
                    <div class="dfn-trust-item">
                        <div class="dfn-trust-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </div>
                        <div class="dfn-trust-text">
                            <strong><?php esc_html_e('Transazione Protetta SSL', 'dfn-theme'); ?></strong>
                            <span><?php esc_html_e('Circuiti sicuri Visa, Mastercard, AMEX e Bancomat.', 'dfn-theme'); ?></span>
                        </div>
                    </div>
                    <div class="dfn-trust-item">
                        <div class="dfn-trust-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </div>
                        <div class="dfn-trust-text">
                            <strong><?php esc_html_e('Ricezione Biglietti Immediata', 'dfn-theme'); ?></strong>
                            <span><?php esc_html_e('Riceverai subito il pass digitale via email.', 'dfn-theme'); ?></span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Colonna Destra: Totale e Selezione Pagamento -->
            <div class="dfn-checkout-sidebar">
                <div class="dfn-sticky-sidebar">

                    <div class="dfn-card dfn-pay-sidebar-card">
                        <div class="dfn-card-header">
                            <div class="dfn-card-header-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                            </div>
                            <div>
                                <h3 class="dfn-card-title"><?php esc_html_e('Importo da Saldare', 'dfn-theme'); ?></h3>
                                <p class="dfn-card-subtitle"><?php esc_html_e('Seleziona il metodo di pagamento preferito.', 'dfn-theme'); ?></p>
                            </div>
                        </div>

                        <div class="dfn-card-body">

                            <!-- Tabella Totali -->
                            <div class="dfn-pay-totals-box">
                                <?php if ($totals) : ?>
                                    <?php foreach ($totals as $total) : ?>
                                        <div class="dfn-total-row <?php echo ($total['label'] === __('Total:', 'woocommerce') || strpos($total['label'], 'Totale') !== false) ? 'dfn-total-final' : ''; ?>">
                                            <span class="dfn-total-label"><?php echo wp_kses_post($total['label']); ?></span>
                                            <span class="dfn-total-value"><?php echo wp_kses_post($total['value']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <?php do_action('woocommerce_pay_order_before_payment'); ?>

                            <div id="payment" class="dfn-payment-section">
                                <?php if ($order->needs_payment()) : ?>
                                    <ul class="wc_payment_methods payment_methods methods" aria-label="<?php esc_attr_e('Metodi di pagamento disponibili', 'dfn-theme'); ?>">
                                        <?php
                                        if (! empty($available_gateways)) {
                                            foreach ($available_gateways as $gateway) {
                                                wc_get_template('checkout/payment-method.php', ['gateway' => $gateway]);
                                            }
                                        } else {
                                            echo '<li class="woocommerce-notice woocommerce-notice--info">';
                                            wc_print_notice(apply_filters('woocommerce_no_available_payment_methods_message', esc_html__('Nessun metodo di pagamento disponibile al momento. Contatta l\'assistenza per supporto.', 'dfn-theme')), 'notice');
                                            echo '</li>';
                                        }
                                        ?>
                                    </ul>
                                <?php endif; ?>

                                <div class="form-row dfn-place-order-row">
                                    <input type="hidden" name="woocommerce_pay" value="1" />

                                    <?php wc_get_template('checkout/terms.php'); ?>

                                    <?php do_action('woocommerce_pay_order_before_submit'); ?>

                                    <?php echo apply_filters(
                                        'woocommerce_pay_order_button_html',
                                        '<button type="submit" class="button alt dfn-btn-primary dfn-btn-pay" id="place_order" value="' . esc_attr($order_button_text) . '" data-value="' . esc_attr($order_button_text) . '">' . esc_html($order_button_text) . '</button>'
                                    ); ?>

                                    <?php do_action('woocommerce_pay_order_after_submit'); ?>

                                    <?php wp_nonce_field('woocommerce-pay', 'woocommerce-pay-nonce'); ?>
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>

        </div>

    </form>

</div>

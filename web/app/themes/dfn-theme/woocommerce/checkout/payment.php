<?php
/**
 * DFN Booking System 2.0 — Native Checkout Payment Section
 *
 * Sezione di pagamento nativa, pulita ed ottimizzata per gateway Stripe,
 * carte di credito/debito e metodi personalizzati DFN.
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 */

defined('ABSPATH') || exit;

if (! wp_doing_ajax()) {
    do_action('woocommerce_review_order_before_payment');
}
?>

<div id="payment" class="woocommerce-checkout-payment dfn-payment-section">
    <div class="dfn-payment-header">
        <h4 class="dfn-payment-title"><?php esc_html_e('Metodo di Pagamento', 'dfn-theme'); ?></h4>
    </div>

    <?php if (WC()->cart && WC()->cart->needs_payment()) : ?>
        <ul class="wc_payment_methods payment_methods methods dfn-payment-methods-list" aria-label="<?php esc_attr_e('Metodi di pagamento', 'dfn-theme'); ?>">
            <?php
            if (! empty($available_gateways)) {
                foreach ($available_gateways as $gateway) {
                    wc_get_template('checkout/payment-method.php', ['gateway' => $gateway]);
                }
            } else {
                echo '<li class="woocommerce-notice woocommerce-notice--info dfn-no-payment-notice">';
                wc_print_notice(
                    apply_filters('woocommerce_no_available_payment_methods_message', WC()->customer->get_billing_country() ? esc_html__('Nessun metodo di pagamento disponibile al momento. Contatta l\'assistenza.', 'dfn-theme') : esc_html__('Compila i dati richiesti sopra per visualizzare i metodi di pagamento disponibili.', 'dfn-theme')),
                    'notice'
                );
                echo '</li>';
            }
            ?>
        </ul>
    <?php endif; ?>

    <div class="form-row place-order dfn-place-order-wrapper">
        <noscript>
            <?php
            printf(
                esc_html__('Poiché il browser non supporta JavaScript o è disabilitato, assicurati di fare clic su %1$sAggiorna totali%2$s prima di confermare la prenotazione.', 'dfn-theme'),
                '<em>',
                '</em>'
            );
            ?>
            <br/><button type="submit" class="button alt" name="woocommerce_checkout_update_totals" value="<?php esc_attr_e('Aggiorna totali', 'dfn-theme'); ?>"><?php esc_html_e('Aggiorna totali', 'dfn-theme'); ?></button>
        </noscript>

        <?php wc_get_template('checkout/terms.php'); ?>

        <?php do_action('woocommerce_review_order_before_submit'); ?>

        <?php echo apply_filters(
            'woocommerce_order_button_html',
            '<button type="submit" class="button alt dfn-btn-primary dfn-btn-place-order" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr($order_button_text) . '" data-value="' . esc_attr($order_button_text) . '"><span class="dfn-btn-lock-icon">🔒</span> ' . esc_html($order_button_text) . '</button>'
        ); ?>

        <?php do_action('woocommerce_review_order_after_submit'); ?>

        <?php wp_nonce_field('woocommerce-process_checkout', 'woocommerce-process-checkout-nonce'); ?>
    </div>
</div>

<?php
if (! wp_doing_ajax()) {
    do_action('woocommerce_review_order_after_payment');
}

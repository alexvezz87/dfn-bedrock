<?php
/**
 * DFN Booking System 2.0 — Native Review Order Template
 *
 * Visualizza il riepilogo del carrello/ordine nel checkout, con indicazione
 * chiara delle tariffe ordinarie, sconti soci FAI e totale.
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 */

defined('ABSPATH') || exit;
?>

<div class="dfn-review-order-table-wrapper">
    <table class="shop_table woocommerce-checkout-review-order-table dfn-review-table">
        <thead>
            <tr>
                <th class="product-name"><?php esc_html_e('Biglietti / Posti', 'dfn-theme'); ?></th>
                <th class="product-total"><?php esc_html_e('Quota', 'dfn-theme'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php
            do_action('woocommerce_review_order_before_cart_contents');

            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);

                if ($_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters('woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key)) {
                    ?>
                    <tr class="<?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
                        <td class="product-name">
                            <div class="dfn-cart-item-info">
                                <span class="dfn-cart-item-title">
                                    <?php echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key)); ?>
                                </span>
                                <?php echo apply_filters('woocommerce_checkout_cart_item_quantity', ' <strong class="product-quantity">' . sprintf('&times;&nbsp;%s', $cart_item['quantity']) . '</strong>', $cart_item, $cart_item_key); ?>
                                <?php echo wc_get_formatted_cart_item_data($cart_item); ?>
                            </div>
                        </td>
                        <td class="product-total">
                            <?php echo apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key); ?>
                        </td>
                    </tr>
                    <?php
                }
            }

            do_action('woocommerce_review_order_after_cart_contents');
            ?>
        </tbody>
        <tfoot>
            <tr class="cart-subtotal">
                <th><?php esc_html_e('Subtotale', 'dfn-theme'); ?></th>
                <td><?php wc_cart_totals_subtotal_html(); ?></td>
            </tr>

            <?php foreach (WC()->cart->get_coupons() as $code => $coupon) : ?>
                <tr class="cart-discount coupon-<?php echo esc_attr(sanitize_title($code)); ?>">
                    <th><?php wc_cart_totals_coupon_label($coupon); ?></th>
                    <td><?php wc_cart_totals_coupon_html($coupon); ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()) : ?>
                <?php do_action('woocommerce_review_order_before_shipping'); ?>
                <?php wc_cart_totals_shipping_html(); ?>
                <?php do_action('woocommerce_review_order_after_shipping'); ?>
            <?php endif; ?>

            <?php foreach (WC()->cart->get_fees() as $fee) : ?>
                <tr class="fee <?php echo (strpos(strtolower($fee->name), 'fai') !== false || strpos(strtolower($fee->name), 'sconto') !== false) ? 'dfn-fai-fee-highlight' : ''; ?>">
                    <th>
                        <?php if (strpos(strtolower($fee->name), 'fai') !== false || strpos(strtolower($fee->name), 'sconto') !== false) : ?>
                            <span class="dfn-fai-fee-badge">🏷️ <?php echo esc_html($fee->name); ?></span>
                        <?php else : ?>
                            <?php echo esc_html($fee->name); ?>
                        <?php endif; ?>
                    </th>
                    <td><?php wc_cart_totals_fee_html($fee); ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if (wc_tax_enabled() && ! WC()->cart->display_prices_including_tax()) : ?>
                <?php if ('itemized' === get_option('woocommerce_tax_total_display')) : ?>
                    <?php foreach (WC()->cart->get_tax_totals() as $code => $tax) : ?>
                        <tr class="tax-rate tax-rate-<?php echo esc_attr(sanitize_title($code)); ?>">
                            <th><?php echo esc_html($tax->label); ?></th>
                            <td><?php echo wp_kses_post($tax->formatted_amount); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr class="tax-total">
                        <th><?php echo esc_html(WC()->countries->tax_or_vat()); ?></th>
                        <td><?php wc_cart_totals_taxes_total_html(); ?></td>
                    </tr>
                <?php endif; ?>
            <?php endif; ?>

            <?php do_action('woocommerce_review_order_before_order_total'); ?>

            <tr class="order-total dfn-order-total-row">
                <th><?php esc_html_e('Totale Contributo', 'dfn-theme'); ?></th>
                <td><strong><?php wc_cart_totals_order_total_html(); ?></strong></td>
            </tr>

            <?php do_action('woocommerce_review_order_after_order_total'); ?>
        </tfoot>
    </table>
</div>

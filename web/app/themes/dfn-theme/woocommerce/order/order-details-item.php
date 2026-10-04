<?php
/**
 * Order Item Details
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 *
 * @var WC_Order_Item_Product $item
 * @var WC_Product            $product
 */

defined('ABSPATH') || exit;

if (! apply_filters('woocommerce_order_item_visible', true, $item)) {
    return;
}
?>
<tr class="<?php echo esc_attr(apply_filters('woocommerce_order_item_class', 'woocommerce-table__line-item order_item', $item, $order)); ?>">

    <td class="woocommerce-table__product-name product-name">
        <div class="dfn-cart-item-info">
            <span class="dfn-cart-item-title">
                <?php
                $is_visible        = $product && $product->is_visible();
                $product_permalink = apply_filters('woocommerce_order_item_permalink', $is_visible ? $product->get_permalink($item) : '', $item, $order);

                echo wp_kses_post(apply_filters('woocommerce_order_item_name', $product_permalink ? sprintf('<a href="%s" class="dfn-order-item-link">%s</a>', esc_url($product_permalink), esc_html($item->get_name())) : esc_html($item->get_name()), $item, $is_visible));
                ?>
            </span>

            <?php
            $qty          = $item->get_quantity();
            $refunded_qty = $order->get_qty_refunded_for_item($item_id);

            if ($refunded_qty) {
                $qty_display = '<del>' . esc_html($qty) . '</del> <ins>' . esc_html($qty - ($refunded_qty * -1)) . '</ins>';
            } else {
                $qty_display = esc_html($qty);
            }

            echo apply_filters('woocommerce_order_item_quantity_html', ' <strong class="product-quantity">' . sprintf('&times;&nbsp;%s', $qty_display) . '</strong>', $item);
            ?>

            <?php do_action('woocommerce_order_item_meta_start', $item_id, $item, $order, false); ?>

            <?php
            wc_display_item_meta(
                $item,
                [
                    'before'    => '<div class="dfn-item-meta-list">',
                    'after'     => '</div>',
                    'separator' => '<span class="dfn-meta-sep">, </span>',
                ]
            );
            ?>

            <?php do_action('woocommerce_order_item_meta_end', $item_id, $item, $order, false); ?>
        </div>
    </td>

    <td class="woocommerce-table__product-total product-total">
        <?php echo $order->get_formatted_line_subtotal($item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </td>

</tr>

<?php if ($show_purchase_note && $purchase_note) : ?>
<tr class="woocommerce-table__product-purchase-note product-purchase-note">
    <td colspan="2"><?php echo wpautop(do_shortcode(wp_kses_post($purchase_note))); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
</tr>
<?php endif; ?>

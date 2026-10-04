<?php
/**
 * DFN Booking System 2.0 — Native Order Details Template
 *
 * Visualizza il dettaglio dei biglietti e delle quote nella Thank You page
 * e nell'Area Personale con il design system FAI / DFN (.dfn-card).
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 *
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;

$order = isset($order) && $order instanceof WC_Order ? $order : (isset($order_id) ? wc_get_order($order_id) : null);

if (! $order) {
    return;
}

$order_items        = $order->get_items(apply_filters('woocommerce_purchase_order_item_types', 'line_item'));
$show_purchase_note = $order->has_status(apply_filters('woocommerce_reports_order_statuses', ['completed', 'processing']));
$downloads          = $order->get_downloadable_items();
$show_downloads     = $order->has_downloadable_item() && $order->is_download_permitted();

if ($show_downloads) {
    wc_get_template(
        'order/order-downloads.php',
        [
            'downloads'  => $downloads,
            'show_title' => true,
        ]
    );
}
?>

<div class="dfn-card dfn-thankyou-order-details-card">
    <div class="dfn-card-header">
        <div class="dfn-card-header-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
        </div>
        <div>
            <h2 class="dfn-card-title"><?php esc_html_e('Riepilogo Biglietti & Quota Versata', 'dfn-theme'); ?></h2>
            <p class="dfn-card-subtitle"><?php esc_html_e('Dettaglio dei posti acquistati e delle quote di contribuzione.', 'dfn-theme'); ?></p>
        </div>
    </div>

    <div class="dfn-card-body">
        <?php do_action('woocommerce_order_details_before_order_table', $order); ?>

        <div class="dfn-review-order-table-wrapper dfn-thankyou-table-wrapper">
            <table class="woocommerce-table woocommerce-table--order-details shop_table order_details dfn-review-table">
                <thead>
                    <tr>
                        <th class="woocommerce-table__product-name product-name"><?php esc_html_e('Biglietti / Posti', 'dfn-theme'); ?></th>
                        <th class="woocommerce-table__product-table product-total"><?php esc_html_e('Quota', 'dfn-theme'); ?></th>
                    </tr>
                </thead>

                <tbody>
                    <?php
                    do_action('woocommerce_order_details_before_order_table_items', $order);

                    foreach ($order_items as $item_id => $item) {
                        $product = $item->get_product();

                        wc_get_template(
                            'order/order-details-item.php',
                            [
                                'order'              => $order,
                                'item_id'            => $item_id,
                                'item'               => $item,
                                'show_purchase_note' => $show_purchase_note,
                                'purchase_note'      => $product ? $product->get_purchase_note() : '',
                                'product'            => $product,
                            ]
                        );
                    }

                    do_action('woocommerce_order_details_after_order_table_items', $order);
                    ?>
                </tbody>

                <tfoot>
                    <?php
                    foreach ($order->get_order_item_totals() as $key => $total) {
                        // Salta il display grezzo della checkbox accettazione come totale
                        if (
                            strpos(strtolower($key), 'accettazione') !== false
                            || strpos(strtolower($total['label']), 'accettazione') !== false
                            || strpos(strtolower($total['label']), 'rimborsabile') !== false
                            || strpos(strtolower($total['label']), 'questo contributo') !== false
                            || strpos(strtolower($total['label']), 'fondazione') !== false
                        ) {
                            continue;
                        }
                        $is_total = ($key === 'order_total' || strpos(strtolower($total['label']), 'totale') !== false);
                        ?>
                        <tr class="<?php echo esc_attr($key); ?> <?php echo $is_total ? 'dfn-order-total-row' : ''; ?>">
                            <th scope="row"><?php echo esc_html($total['label']); ?></th>
                            <td><?php echo wp_kses_post($total['value']); ?></td>
                        </tr>
                        <?php
                    }
                    ?>
                    <?php if ($order->get_customer_note()) : ?>
                        <tr class="dfn-order-note-row">
                            <th><?php esc_html_e('Note ordine:', 'dfn-theme'); ?></th>
                            <td><?php echo wp_kses_post(nl2br(wptexturize($order->get_customer_note()))); ?></td>
                        </tr>
                    <?php endif; ?>
                </tfoot>
            </table>
        </div>

        <?php do_action('woocommerce_order_details_after_order_table', $order); ?>
    </div>
</div>

<?php
/**
 * Action hook fired after the order details.
 *
 * @since 4.4.0
 * @param WC_Order $order Order data.
 */
do_action('woocommerce_after_order_details', $order);

if ($show_customer_details = apply_filters('woocommerce_order_details_show_customer_details', true, $order)) {
    wc_get_template('order/order-details-customer.php', ['order' => $order]);
}

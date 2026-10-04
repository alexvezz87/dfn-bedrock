<?php
/**
 * DFN Booking System 2.0 — Native Customer Details Template
 *
 * Visualizza i dati anagrafici e di fatturazione del cliente nella Thank You page
 * in perfetto stile istituzionale FAI (.dfn-card).
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 *
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;

$show_shipping = ! wc_ship_to_billing_address_only() && $order->needs_shipping_address();
?>

<div class="dfn-card dfn-thankyou-customer-card">
    <div class="dfn-card-header">
        <div class="dfn-card-header-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        </div>
        <div>
            <h2 class="dfn-card-title"><?php esc_html_e('Dati di Fatturazione & Contatto', 'dfn-theme'); ?></h2>
            <p class="dfn-card-subtitle"><?php esc_html_e('Riferimenti indicati durante la procedura di prenotazione.', 'dfn-theme'); ?></p>
        </div>
    </div>

    <div class="dfn-card-body">
        <div class="dfn-customer-details-grid <?php echo $show_shipping ? 'has-shipping' : ''; ?>">

            <div class="dfn-customer-column">
                <h3 class="dfn-customer-section-title"><?php esc_html_e('Indirizzo di Fatturazione', 'dfn-theme'); ?></h3>
                <div class="dfn-customer-info-box">
                    <div class="dfn-customer-name">
                        <strong><?php echo esc_html($order->get_formatted_billing_full_name()); ?></strong>
                        <?php if ($order->get_billing_company()) : ?>
                            <span class="dfn-customer-company"><?php echo esc_html($order->get_billing_company()); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($order->get_billing_address_1()) : ?>
                        <div class="dfn-customer-address">
                            <span><?php echo esc_html($order->get_billing_address_1()); ?></span>
                            <?php if ($order->get_billing_address_2()) : ?>
                                <span><?php echo esc_html($order->get_billing_address_2()); ?></span>
                            <?php endif; ?>
                            <span><?php echo esc_html(trim($order->get_billing_postcode() . ' ' . $order->get_billing_city() . ' (' . $order->get_billing_state() . ')')); ?></span>
                            <span><?php echo esc_html(WC()->countries->countries[$order->get_billing_country()] ?? $order->get_billing_country()); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dfn-customer-column">
                <h3 class="dfn-customer-section-title"><?php esc_html_e('Recapiti & Notifiche', 'dfn-theme'); ?></h3>
                <div class="dfn-customer-contacts-box">
                    <?php if ($order->get_billing_email()) : ?>
                        <div class="dfn-contact-item">
                            <span class="dfn-contact-icon">✉️</span>
                            <div>
                                <span class="dfn-contact-label"><?php esc_html_e('Email di conferma:', 'dfn-theme'); ?></span>
                                <strong class="dfn-contact-val"><?php echo esc_html($order->get_billing_email()); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($order->get_billing_phone()) : ?>
                        <div class="dfn-contact-item">
                            <span class="dfn-contact-icon">📞</span>
                            <div>
                                <span class="dfn-contact-label"><?php esc_html_e('Telefono:', 'dfn-theme'); ?></span>
                                <strong class="dfn-contact-val"><?php echo esc_html($order->get_billing_phone()); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($order->get_payment_method_title()) : ?>
                        <div class="dfn-contact-item">
                            <span class="dfn-contact-icon">💳</span>
                            <div>
                                <span class="dfn-contact-label"><?php esc_html_e('Metodo di pagamento:', 'dfn-theme'); ?></span>
                                <strong class="dfn-contact-val"><?php echo wp_kses_post($order->get_payment_method_title()); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($show_shipping) : ?>
                <div class="dfn-customer-column">
                    <h3 class="dfn-customer-section-title"><?php esc_html_e('Indirizzo di Spedizione', 'dfn-theme'); ?></h3>
                    <div class="dfn-customer-info-box">
                        <address>
                            <?php echo wp_kses_post($order->get_formatted_shipping_address(esc_html__('N/A', 'dfn-theme'))); ?>
                        </address>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

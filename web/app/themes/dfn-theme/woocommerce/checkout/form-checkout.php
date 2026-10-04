<?php
/**
 * DFN Booking System 2.0 — Native Checkout Template
 *
 * Sostituisce completamente i layout di Elementor PRO con un'esperienza di checkout
 * nativa, fluida, ultra-performante ed allineata al brand FAI / DFN.
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

do_action('woocommerce_before_checkout_form', $checkout);

// Se la registrazione al checkout è obbligatoria e l'utente non è loggato
if (! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in()) {
    echo '<div class="dfn-checkout-login-notice">';
    echo esc_html(apply_filters('woocommerce_checkout_must_be_logged_in_message', __('È necessario effettuare l\'accesso per completare la prenotazione.', 'dfn-theme')));
    echo '</div>';
    return;
}
?>

<div class="dfn-checkout-container">

    <!-- Step Progress Indicator -->
    <div class="dfn-checkout-steps" aria-label="<?php esc_attr_e('Stato di avanzamento prenotazione', 'dfn-theme'); ?>">
        <div class="dfn-step dfn-step-completed">
            <span class="dfn-step-num"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></span>
            <span class="dfn-step-label"><?php esc_html_e('Scelta Posti', 'dfn-theme'); ?></span>
        </div>
        <div class="dfn-step-divider"></div>
        <div class="dfn-step dfn-step-active">
            <span class="dfn-step-num">2</span>
            <span class="dfn-step-label"><?php esc_html_e('Dati & Pagamento', 'dfn-theme'); ?></span>
        </div>
        <div class="dfn-step-divider"></div>
        <div class="dfn-step">
            <span class="dfn-step-num">3</span>
            <span class="dfn-step-label"><?php esc_html_e('Conferma Biglietti', 'dfn-theme'); ?></span>
        </div>
    </div>

    <form name="checkout" method="post" class="checkout woocommerce-checkout dfn-checkout-form" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__('Checkout Prenotazione', 'dfn-theme'); ?>">

        <div class="dfn-checkout-layout">

            <!-- Colonna Principale: Dati Intestatario e Tessere FAI -->
            <div class="dfn-checkout-main">

                <?php if ($checkout->get_checkout_fields()) : ?>

                    <?php do_action('woocommerce_checkout_before_customer_details'); ?>

                    <div class="dfn-card dfn-billing-card" id="customer_details">
                        <div class="dfn-card-header">
                            <div class="dfn-card-header-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                            </div>
                            <div>
                                <h2 class="dfn-card-title"><?php esc_html_e('Intestatario della Prenotazione', 'dfn-theme'); ?></h2>
                                <p class="dfn-card-subtitle"><?php esc_html_e('Inserisci i riferimenti a cui intestare la prenotazione ed inviare i biglietti digitali.', 'dfn-theme'); ?></p>
                            </div>
                        </div>

                        <div class="dfn-card-body">
                            <?php do_action('woocommerce_checkout_billing'); ?>
                        </div>

                        <?php if (WC()->cart && WC()->cart->needs_shipping_address()) : ?>
                            <div class="dfn-shipping-section">
                                <?php do_action('woocommerce_checkout_shipping'); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php do_action('woocommerce_checkout_after_customer_details'); ?>

                <?php endif; ?>

                <!-- Box Informativo di Garanzia & Sicurezza -->
                <div class="dfn-checkout-trust-badges">
                    <div class="dfn-trust-item">
                        <div class="dfn-trust-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </div>
                        <div class="dfn-trust-text">
                            <strong><?php esc_html_e('Pagamento Protetto & Sicuro', 'dfn-theme'); ?></strong>
                            <span><?php esc_html_e('Crittografia SSL 256-bit certificata Stripe.', 'dfn-theme'); ?></span>
                        </div>
                    </div>
                    <div class="dfn-trust-item">
                        <div class="dfn-trust-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        </div>
                        <div class="dfn-trust-text">
                            <strong><?php esc_html_e('Emissione Istantanea Biglietti', 'dfn-theme'); ?></strong>
                            <span><?php esc_html_e('QR Code inviati immediatamente via email.', 'dfn-theme'); ?></span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Colonna Laterale (Sticky): Riepilogo Ordine & Pagamento -->
            <div class="dfn-checkout-sidebar">
                <div class="dfn-sticky-sidebar">

                    <div class="dfn-card dfn-review-card">
                        <div class="dfn-card-header">
                            <div class="dfn-card-header-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
                            </div>
                            <div>
                                <h3 class="dfn-card-title" id="order_review_heading"><?php esc_html_e('Riepilogo Prenotazione', 'dfn-theme'); ?></h3>
                                <p class="dfn-card-subtitle"><?php esc_html_e('Verifica i posti selezionati e la quota di contributo.', 'dfn-theme'); ?></p>
                            </div>
                        </div>

                        <div class="dfn-card-body">
                            <?php do_action('woocommerce_checkout_before_order_review'); ?>

                            <div id="order_review" class="woocommerce-checkout-review-order">
                                <?php do_action('woocommerce_checkout_order_review'); ?>
                            </div>

                            <?php do_action('woocommerce_checkout_after_order_review'); ?>
                        </div>
                    </div>

                </div>
            </div>

        </div>

    </form>

</div>

<?php do_action('woocommerce_after_checkout_form', $checkout); ?>

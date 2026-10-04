<?php
/**
 * DFN Booking System 2.0 — Native Payment Method Item Template
 *
 * Mantiene la gerarchia DOM standard di WooCommerce compatibile al 100%
 * con i gateway ufficiali (Stripe UPE, PayPal, ecc.), applicando lo stile
 * istituzionale e moderno DFN via CSS.
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 */

defined('ABSPATH') || exit;
?>
<li class="wc_payment_method payment_method_<?php echo esc_attr($gateway->id); ?> dfn-payment-method-card <?php echo $gateway->chosen ? 'dfn-payment-method-selected' : ''; ?>">
    <input id="payment_method_<?php echo esc_attr($gateway->id); ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr($gateway->id); ?>" <?php checked($gateway->chosen, true); ?> data-order_button_text="<?php echo esc_attr($gateway->order_button_text); ?>" />

    <label for="payment_method_<?php echo esc_attr($gateway->id); ?>" class="dfn-payment-method-label">
        <span class="dfn-payment-method-title"><?php echo $gateway->get_title(); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?></span>
        <span class="dfn-payment-method-icons"><?php echo $gateway->get_icon(); /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?></span>
    </label>

    <?php if ($gateway->has_fields() || $gateway->get_description()) : ?>
        <div class="payment_box payment_method_<?php echo esc_attr($gateway->id); ?> dfn-payment-method-fields" <?php if (! $gateway->chosen) : /* phpcs:ignore Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace */ ?>style="display:none;"<?php endif; /* phpcs:ignore Squiz.ControlStructures.ControlSignature.NewlineAfterOpenBrace */ ?>>
            <?php $gateway->payment_fields(); ?>
        </div>
    <?php endif; ?>
</li>

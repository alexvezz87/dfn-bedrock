<?php
/**
 * Customer order details email (Customer Invoice / Payment Request)
 *
 * Override template for DFN Theme / FAI Booking System.
 * Inserisce un pulsante CTA evidente in stile FAI per il pagamento rapido dell'ordine.
 *
 * @package DFN_Theme
 * @version 11.1.0
 * 
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

/**
 * Executes the e-mail header.
 *
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p style="font-size: 15px; color: #1e293b; margin: 0 0 14px 0; line-height: 1.5;">
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Ciao %s,', 'dfn-theme' ), esc_html( $order->get_billing_first_name() ) );
} else {
	esc_html_e( 'Gentile Utente,', 'dfn-theme' );
}
?>
</p>

<?php if ( $order->needs_payment() ) : 
	$pay_url = $order->get_checkout_payment_url();
	$order_total = $order->get_formatted_order_total();
?>
	<p style="font-size: 14.5px; color: #334155; line-height: 1.6; margin: 0 0 18px 0;">
	<?php
	if ( $order->has_status( OrderStatus::FAILED ) ) {
		esc_html_e( 'Il precedente tentativo di pagamento su DFN Prenotazioni non è andato a buon fine. I tuoi posti sono ancora temporaneamente riservati: fai clic sul pulsante verde sottostante per completare il versamento del contributo in tutta sicurezza.', 'dfn-theme' );
	} else {
		esc_html_e( 'È stata registrata una richiesta di prenotazione per te su DFN Prenotazioni. Per confermare e garantire definitivamente i tuoi posti, procedi con il versamento del contributo tramite il pulsante sottostante.', 'dfn-theme' );
	}
	?>
	</p>

	<!-- ================================================================= -->
	<!-- PULSANTE CTA IN STILE UFFICIALE FAI (CROSS-CLIENT BULLETPROOF)    -->
	<!-- ================================================================= -->
	<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 22px 0 26px 0;">
		<tr>
			<td align="center" style="padding: 4px 0;">
				<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
					<tr>
						<td align="center" bgcolor="#004b23" style="background-color: #004b23; border-radius: 8px; box-shadow: 0 4px 10px rgba(0, 75, 35, 0.35); text-align: center;">
							<a href="<?php echo esc_url( $pay_url ); ?>" 
							   target="_blank" 
							   style="display: inline-block; padding: 15px 34px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; font-weight: 700; color: #ffffff; text-decoration: none; border-radius: 8px; border: 1.5px solid #003b1c; text-align: center; letter-spacing: 0.4px;">
								<!--[if mso]><i style="letter-spacing: 34px; mso-font-width: -100%; mso-text-raise: 30pt">&nbsp;</i><![endif]-->
								<span style="mso-text-raise: 15pt;">💳 Paga e Conferma la Prenotazione &raquo;</span>
								<!--[if mso]><i style="letter-spacing: 34px; mso-font-width: -100%">&nbsp;</i><![endif]-->
							</a>
						</td>
					</tr>
				</table>
				<div style="font-size: 12px; color: #64748b; margin-top: 10px; line-height: 1.4;">
					Importo da versare: <strong style="color: #004b23; font-size: 13.5px;"><?php echo wp_strip_all_tags( $order_total ); ?></strong> &bull; Connessione protetta SSL
				</div>
			</td>
		</tr>
	</table>

<?php else : ?>
	<p style="font-size: 14.5px; color: #334155; line-height: 1.6; margin: 0 0 16px 0;">
	<?php
	/* translators: %s Order date */
	printf( esc_html__( 'Di seguito trovi il riepilogo dettagliato del tuo ordine registrato il %s:', 'dfn-theme' ), esc_html( wc_format_datetime( $order->get_date_created() ) ) );
	?>
	</p>
<?php endif; ?>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php

/**
 * Hook for the woocommerce_email_order_details.
 *
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook for the woocommerce_email_order_meta.
 *
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * Hook for woocommerce_email_customer_details.
 *
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/**
 * Executes the email footer.
 *
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );

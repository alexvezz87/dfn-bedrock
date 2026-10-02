<?php
/**
 * Customer invoice email (plain text)
 *
 * Override template for DFN Theme / FAI Booking System.
 *
 * @package DFN_Theme
 * @version 9.7.0
 * 
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

use Automattic\WooCommerce\Enums\OrderStatus;

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $order->get_billing_first_name() ) ) {
	echo sprintf( esc_html__( 'Ciao %s,', 'dfn-theme' ), esc_html( $order->get_billing_first_name() ) ) . "\n\n";
} else {
	echo esc_html__( 'Gentile Utente,', 'dfn-theme' ) . "\n\n";
}

if ( $order->needs_payment() ) {
	if ( $order->has_status( OrderStatus::FAILED ) ) {
		echo esc_html__( 'Il precedente tentativo di pagamento su DFN Prenotazioni non è andato a buon fine. I tuoi posti sono ancora riservati.', 'dfn-theme' ) . "\n\n";
	} else {
		echo esc_html__( 'È stata registrata una richiesta di prenotazione per te su DFN Prenotazioni.', 'dfn-theme' ) . "\n\n";
	}

	echo esc_html__( 'Per completare il pagamento e confermare definitivamente i tuoi posti, visita il seguente link sicuro:', 'dfn-theme' ) . "\n";
	echo esc_url( $order->get_checkout_payment_url() ) . "\n\n";
} else {
	/* translators: %s: Order date */
	echo sprintf( esc_html__( 'Di seguito trovi i dettagli del tuo ordine registrato il %s:', 'dfn-theme' ), esc_html( wc_format_datetime( $order->get_date_created() ) ) ) . "\n\n";
}

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

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) );

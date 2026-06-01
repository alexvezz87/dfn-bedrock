<?php
/**
 * DFN Booking System 2.0 — Visitor Account & Dashboard Controller
 *
 * Gestisce l'integrazione con l'area riservata WooCommerce "My Account"
 * e le azioni rapide di accesso ai biglietti di gruppo.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Inietta gli asset premium nella pagina "Mio Account"
add_action( 'wp_enqueue_scripts', 'dfn_enqueue_myaccount_assets' );

// Associa automaticamente ordini passati in fase di registrazione
add_action( 'woocommerce_created_customer', 'dfn_associate_past_orders_to_new_customer', 10, 1 );

// Blocco login automatico dopo la registrazione per ragioni di sicurezza email
add_filter( 'woocommerce_registration_auth_new_customer', '__return_false' );
add_filter( 'woocommerce_registration_redirect', 'dfn_registration_redirect_with_notice', 10, 1 );

// Aggiunge la voce di menu rapida "Biglietto Gruppo" alla lista degli ordini cliente
add_filter( 'woocommerce_my_account_my_orders_actions', 'dfn_add_group_tickets_action_button', 10, 2 );

/**
 * Registra gli asset CSS dedicati alla bacheca visitatori e all'hub biglietti.
 */
function dfn_enqueue_myaccount_assets(): void {
    if ( is_account_page() ) {
        wp_enqueue_style(
            'dfn-visitor-dashboard-css',
            get_stylesheet_directory_uri() . '/assets/css/dfn-visitor-dashboard.css',
            array(),
            '2.0.0'
        );
    }
}

/**
 * Associa ordini precedentemente effettuati con la stessa email all'account appena registrato.
 *
 * @param int $customer_id ID del cliente WooCommerce.
 */
function dfn_associate_past_orders_to_new_customer( int $customer_id ): void {
    if ( function_exists( 'wc_update_new_customer_past_orders' ) ) {
        wc_update_new_customer_past_orders( $customer_id );
    }
}

/**
 * Aggiunge un avviso descrittivo alla registrazione per informare l'utente sulla password generata via mail.
 *
 * @param string $redirect_url URL di destinazione.
 * @return string
 */
function dfn_registration_redirect_with_notice( string $redirect_url ): string {
    wc_add_notice(
        esc_html__( 'Registrazione completata con successo! 📧 Ti abbiamo inviato una password sicura via email. Controlla la tua posta (anche la cartella Spam) ed utilizzala per accedere al tuo Botteghino Personale.', 'dfn-theme' ),
        'success'
    );
    return wc_get_page_permalink( 'myaccount' );
}

/**
 * Inserisce il pulsante rapido "Mostra Biglietto Gruppo" per gli ordini confermati o in elaborazione.
 *
 * @param array<string, array<string, string>> $actions Azioni dell'ordine correnti.
 * @param \WC_Order $order Oggetto ordine WooCommerce.
 * @return array<string, array<string, string>>
 */
function dfn_add_group_tickets_action_button( array $actions, $order ): array {
    if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
        $hub_token = hash_hmac( 'sha256', $order->get_order_key() . '_dfn_hub', wp_salt( 'nonce' ) );
        $hub_url   = site_url( '/?dfn_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token );

        $ticket_action = array(
            'dfn_group_ticket' => array(
                'url'  => $hub_url,
                'name' => esc_html__( '🎟️ Biglietto Gruppo', 'dfn-theme' ),
            )
        );

        // Fondi in cima alle azioni per massima visibilità
        $actions = array_merge( $ticket_action, $actions );
    }
    return $actions;
}

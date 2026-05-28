<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * RESTYLING "MIO ACCOUNT", LOGICA LOGIN E BOTTONI
 * ========================================================================
 */

// Inietta il CSS solo nella pagina My Account
add_action( 'wp_enqueue_scripts', 'cv_enqueue_myaccount_css' );
function cv_enqueue_myaccount_css() {
    if ( is_account_page() ) {
        wp_enqueue_style( 'cv-myaccount-css', get_stylesheet_directory_uri() . '/assets/css/cv-myaccount.css', array(), '1.0' );
    }
}

// Associa ordini passati ai nuovi utenti
add_action( 'woocommerce_created_customer', 'cv_associa_ordini_passati_al_nuovo_account', 10, 1 );
function cv_associa_ordini_passati_al_nuovo_account( $customer_id ) {
    if ( function_exists( 'wc_update_new_customer_past_orders' ) ) {
        wc_update_new_customer_past_orders( $customer_id );
    }
}

// Blocco login automatico e reindirizzamento dopo registrazione
add_filter( 'woocommerce_registration_auth_new_customer', '__return_false' );
add_filter( 'woocommerce_registration_redirect', 'cv_avviso_dopo_registrazione', 10, 1 );
function cv_avviso_dopo_registrazione( $redirect_url ) {
    wc_add_notice( 'Registrazione completata! 📧 Ti abbiamo inviato una password sicura via email. Controlla la posta (anche nello Spam) e usala per accedere al tuo Botteghino Personale.', 'success' );
    return wc_get_page_permalink( 'myaccount' );
}

// Aggiunge il bottone "Mostra Biglietti" alla tabella ordini
add_filter( 'woocommerce_my_account_my_orders_actions', 'cv_aggiungi_bottone_hub_ordini', 10, 2 );
function cv_aggiungi_bottone_hub_ordini( $actions, $order ) {
    if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
        $hub_token = hash_hmac( 'sha256', $order->get_order_key() . '_hub', wp_salt('nonce') );
        $hub_url = site_url( '/?cv_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token );
        $azione_biglietti = array( 'cv_biglietti' => array( 'url'  => $hub_url, 'name' => '🎟️ Mostra Biglietti' ) );
        $actions = array_merge( $azione_biglietti, $actions );
    }
    return $actions;
}
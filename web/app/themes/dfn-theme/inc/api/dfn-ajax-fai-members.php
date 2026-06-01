<?php
/**
 * DFN Booking System 2.0 — FAI Members AJAX CRUD API
 *
 * Endpoint transazionali per gestire la creazione, modifica ed eliminazione
 * dei soci FAI all'interno dell'anagrafica centralizzata.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_dfn_save_fai_member', 'dfn_save_fai_member_ajax_handler' );
add_action( 'wp_ajax_dfn_delete_fai_member', 'dfn_delete_fai_member_ajax_handler' );

/**
 * Crea o aggiorna un socio FAI nel database custom.
 */
function dfn_save_fai_member_ajax_handler(): void {
    check_ajax_referer( 'dfn_fai_admin_nonce', 'security' );

    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Non hai le autorizzazioni necessarie.', 'dfn-theme' ) ) );
    }

    $id          = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
    $first_name  = isset( $_POST['first_name'] ) ? sanitize_text_field( $_POST['first_name'] ) : '';
    $last_name   = isset( $_POST['last_name'] ) ? sanitize_text_field( $_POST['last_name'] ) : '';
    $email       = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
    $phone       = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
    $card_number = isset( $_POST['card_number'] ) ? sanitize_text_field( $_POST['card_number'] ) : '';
    $card_expiry = isset( $_POST['card_expiry'] ) ? sanitize_text_field( $_POST['card_expiry'] ) : '';

    if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) || empty( $card_number ) || empty( $card_expiry ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Tutti i campi obbligatori devono essere compilati.', 'dfn-theme' ) ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    $data = array(
        'first_name'  => $first_name,
        'last_name'   => $last_name,
        'email'       => $email,
        'phone'       => ! empty( $phone ) ? $phone : null,
        'card_number' => $card_number,
        'card_expiry' => $card_expiry,
        'verified'    => 1,
        'verified_by' => get_current_user_id(),
        'verified_at' => current_time( 'mysql' ),
    );

    $formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' );

    if ( $id > 0 ) {
        // Aggiorna
        $wpdb->update(
            $table,
            $data,
            array( 'id' => $id ),
            $formats,
            array( '%d' )
        );
        $message = esc_html__( 'Socio FAI aggiornato con successo.', 'dfn-theme' );
    } else {
        // Inserisci
        $wpdb->insert(
            $table,
            $data,
            $formats
        );
        $message = esc_html__( 'Nuovo socio FAI registrato con successo.', 'dfn-theme' );
    }

    wp_send_json_success( array( 'message' => $message ) );
}

/**
 * Elimina un socio FAI dal database custom.
 */
function dfn_delete_fai_member_ajax_handler(): void {
    check_ajax_referer( 'dfn_fai_admin_nonce', 'security' );

    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        wp_send_json_error( array( 'message' => esc_html__( 'Non hai le autorizzazioni necessarie.', 'dfn-theme' ) ) );
    }

    $id = isset( $_POST['member_id'] ) ? intval( $_POST['member_id'] ) : 0;
    if ( $id <= 0 ) {
        wp_send_json_error( array( 'message' => esc_html__( 'ID socio non valido.', 'dfn-theme' ) ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    $deleted = $wpdb->delete(
        $table,
        array( 'id' => $id ),
        array( '%d' )
    );

    if ( $deleted ) {
        wp_send_json_success( array( 'message' => esc_html__( 'Socio FAI eliminato correttamente dal database.', 'dfn-theme' ) ) );
    } else {
        wp_send_json_error( array( 'message' => esc_html__( 'Impossibile eliminare il socio. Record non trovato.', 'dfn-theme' ) ) );
    }
}

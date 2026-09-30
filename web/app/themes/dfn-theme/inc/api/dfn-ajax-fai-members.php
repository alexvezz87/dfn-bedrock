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

if (! defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_dfn_save_fai_member', 'dfn_save_fai_member_ajax_handler');
add_action('wp_ajax_dfn_delete_fai_member', 'dfn_delete_fai_member_ajax_handler');

/**
 * Crea o aggiorna un socio FAI nel database custom.
 */
function dfn_save_fai_member_ajax_handler(): void
{
    check_ajax_referer('dfn_fai_admin_nonce', 'security');

    if (! current_user_can('dfn_manage_events')) {
        wp_send_json_error([ 'message' => esc_html__('Non hai le autorizzazioni necessarie.', 'dfn-theme') ]);
    }

    $id          = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
    $first_name  = isset($_POST['first_name']) ? (function_exists('dfn_sanitize_name') ? dfn_sanitize_name($_POST['first_name']) : sanitize_text_field(wp_unslash($_POST['first_name']))) : '';
    $last_name   = isset($_POST['last_name']) ? (function_exists('dfn_sanitize_name') ? dfn_sanitize_name($_POST['last_name']) : sanitize_text_field(wp_unslash($_POST['last_name']))) : '';
    $email       = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone       = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
    $card_number = isset($_POST['card_number']) ? sanitize_text_field(wp_unslash($_POST['card_number'])) : '';
    $card_expiry = isset($_POST['card_expiry']) ? sanitize_text_field(wp_unslash($_POST['card_expiry'])) : '';

    if (empty($first_name) || empty($last_name) || empty($email) || empty($card_number) || empty($card_expiry)) {
        wp_send_json_error([ 'message' => esc_html__('Tutti i campi obbligatori devono essere compilati.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    $data = [
        'first_name'  => $first_name,
        'last_name'   => $last_name,
        'email'       => $email,
        'phone'       => ! empty($phone) ? $phone : null,
        'card_number' => $card_number,
        'card_expiry' => $card_expiry,
        'verified'    => 1,
        'verified_by' => get_current_user_id(),
        'verified_at' => current_time('mysql'),
    ];

    $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ];

    if ($id > 0) {
        // Aggiorna
        $wpdb->update(
            $table,
            $data,
            [ 'id' => $id ],
            $formats,
            [ '%d' ],
        );
        $message = esc_html__('Socio FAI aggiornato con successo.', 'dfn-theme');
    } else {
        // Inserisci
        $wpdb->insert(
            $table,
            $data,
            $formats,
        );
        $message = esc_html__('Nuovo socio FAI registrato con successo.', 'dfn-theme');
    }

    wp_send_json_success([ 'message' => $message ]);
}

/**
 * Elimina un socio FAI dal database custom.
 */
function dfn_delete_fai_member_ajax_handler(): void
{
    check_ajax_referer('dfn_fai_admin_nonce', 'security');

    if (! current_user_can('dfn_manage_events')) {
        wp_send_json_error([ 'message' => esc_html__('Non hai le autorizzazioni necessarie.', 'dfn-theme') ]);
    }

    $id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
    if ($id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('ID socio non valido.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    $deleted = $wpdb->delete(
        $table,
        [ 'id' => $id ],
        [ '%d' ],
    );

    if ($deleted) {
        wp_send_json_success([ 'message' => esc_html__('Socio FAI eliminato correttamente dal database.', 'dfn-theme') ]);
    } else {
        wp_send_json_error([ 'message' => esc_html__('Impossibile eliminare il socio. Record non trovato.', 'dfn-theme') ]);
    }
}

add_action('wp_ajax_dfn_verify_fai_member', 'dfn_verify_fai_member_ajax_handler');
/**
 * Convalida rapidamente un socio FAI marcandolo verified = 1 (usato anche da app mobile).
 */
function dfn_verify_fai_member_ajax_handler(): void
{
    $nonce = $_POST['nonce'] ?? $_POST['security'] ?? '';
    if (! wp_verify_nonce($nonce, 'dfn_fai_admin_nonce') && 
        ! wp_verify_nonce($nonce, 'dfn_admin_events_nonce') && 
        ! wp_verify_nonce($nonce, 'dfn_booking_nonce')) {
        wp_send_json_error([ 'message' => esc_html__('Sessione scaduta o richiesta non valida.', 'dfn-theme') ]);
    }

    $can_verify = current_user_can('dfn_manage_events') || 
                  (function_exists('dfn_user_can') && dfn_user_can('dfn_act_fai_members')) || 
                  current_user_can('manage_options');

    if (! $can_verify) {
        wp_send_json_error([ 'message' => esc_html__('Non hai le autorizzazioni necessarie per validare tessere FAI.', 'dfn-theme') ]);
    }

    $id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
    if ($id <= 0) {
        wp_send_json_error([ 'message' => esc_html__('ID socio non valido.', 'dfn-theme') ]);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    $updated = $wpdb->update(
        $table,
        [
            'verified'    => 1,
            'verified_by' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
        ],
        [ 'id' => $id ],
        [ '%d', '%d', '%s' ],
        [ '%d' ]
    );

    if ($updated !== false) {
        wp_send_json_success([ 'message' => esc_html__('Tessera FAI validata con successo.', 'dfn-theme') ]);
    } else {
        wp_send_json_error([ 'message' => esc_html__('Errore durante l\'aggiornamento del database.', 'dfn-theme') ]);
    }
}


<?php
/**
 * DFN Booking System 2.0.1 — API AJAX Promozioni & Marketing Eventi (Issue #41)
 *
 * Gestisce tutte le operazioni asincrone:
 * - Calcolo in tempo reale del pubblico target (con esclusione di chi ha già acquistato)
 * - Invio di email di test formattate
 * - Salvataggio bozze e avvio campagne con popolamento coda
 * - Elaborazione manuale a lotti (batch) e cancellazione campagne
 *
 * @package DFN_Theme
 * @since   2.0.1
 */

if (! defined('ABSPATH')) {
    exit;
}

// Registrazione degli endpoint AJAX per utenti amministratori / operatori autorizzati
add_action('wp_ajax_dfn_promotions_get_audience_stats', 'dfn_ajax_promotions_get_audience_stats');
add_action('wp_ajax_dfn_promotions_send_test_email',    'dfn_ajax_promotions_send_test_email');
add_action('wp_ajax_dfn_promotions_save_campaign',     'dfn_ajax_promotions_save_campaign');
add_action('wp_ajax_dfn_promotions_process_batch',      'dfn_ajax_promotions_process_batch');
add_action('wp_ajax_dfn_promotions_cancel_campaign',    'dfn_ajax_promotions_cancel_campaign');
add_action('wp_ajax_dfn_promotions_delete_campaign',    'dfn_ajax_promotions_delete_campaign');

/**
 * Endpoint AJAX: Calcola le statistiche del pubblico target per l'evento selezionato.
 */
function dfn_ajax_promotions_get_audience_stats(): void
{
    check_ajax_referer('dfn_promotions_ajax_nonce', 'nonce');

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if ($event_id <= 0) {
        wp_send_json_error(['message' => __('ID evento non valido.', 'dfn-theme')]);
    }

    if (! function_exists('dfn_promotions_get_audience_stats')) {
        wp_send_json_error(['message' => __('Funzione promozioni non trovata.', 'dfn-theme')]);
    }

    $stats = dfn_promotions_get_audience_stats($event_id);

    wp_send_json_success([
        'total_contacts' => number_format_i18n($stats['total_contacts']),
        'already_booked' => number_format_i18n($stats['already_booked']),
        'eligible'       => number_format_i18n($stats['eligible']),
        'raw_eligible'   => $stats['eligible'],
    ]);
}

/**
 * Endpoint AJAX: Invia una singola email di test formattata alla casella specificata.
 */
function dfn_ajax_promotions_send_test_email(): void
{
    check_ajax_referer('dfn_promotions_ajax_nonce', 'nonce');

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
    if (! is_email($test_email)) {
        wp_send_json_error(['message' => __('Indirizzo email di test non valido.', 'dfn-theme')]);
    }

    $event_id    = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $subject     = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
    $intro       = isset($_POST['intro']) ? sanitize_textarea_field(wp_unslash($_POST['intro'])) : '';
    $custom_text = isset($_POST['custom_text']) ? sanitize_textarea_field(wp_unslash($_POST['custom_text'])) : '';
    $cta_text    = isset($_POST['cta_text']) ? sanitize_text_field(wp_unslash($_POST['cta_text'])) : 'Prenota il tuo posto';

    if ($event_id <= 0) {
        wp_send_json_error(['message' => __('Seleziona prima l\'evento da promuovere.', 'dfn-theme')]);
    }

    if (empty($subject)) {
        $subject = __('Anteprima Evento FAI', 'dfn-theme');
    }

    // Recupera evento
    $events = function_exists('dfn_promotions_get_eligible_events') ? dfn_promotions_get_eligible_events(false) : [];
    $event = null;
    foreach ($events as $ev) {
        if ((int) $ev->id === $event_id) {
            $event = $ev;
            break;
        }
    }

    if (! $event) {
        wp_send_json_error(['message' => __('Evento non trovato nel catalogo.', 'dfn-theme')]);
    }

    // Costruisce oggetto dummy per generare l'email
    $promo_obj = (object) [
        'email_subject'     => '[TEST PREVIEW] ' . $subject,
        'email_intro'       => $intro,
        'email_custom_text' => $custom_text,
        'cta_text'          => $cta_text,
    ];

    $current_user = wp_get_current_user();
    $recipient_name = $current_user->display_name ?: 'Tester';

    $email_html = dfn_promotions_build_email_html($promo_obj, $recipient_name, $event);
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $sent = wp_mail($test_email, $promo_obj->email_subject, $email_html, $headers);

    if ($sent) {
        wp_send_json_success([
            'message' => sprintf(__('Email di test inviata con successo a %s! Controlla la tua casella di posta.', 'dfn-theme'), esc_html($test_email)),
        ]);
    } else {
        wp_send_json_error([
            'message' => __('wp_mail() non è riuscito a spedire il messaggio. Verifica la configurazione del server SMTP.', 'dfn-theme'),
        ]);
    }
}

/**
 * Endpoint AJAX: Salva la campagna come bozza oppure avvia l'invio accodando i destinatari.
 */
function dfn_ajax_promotions_save_campaign(): void
{
    check_ajax_referer('dfn_promotions_ajax_nonce', 'nonce');

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $event_id    = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    $title       = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $subject     = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
    $intro       = isset($_POST['intro']) ? sanitize_textarea_field(wp_unslash($_POST['intro'])) : '';
    $custom_text = isset($_POST['custom_text']) ? sanitize_textarea_field(wp_unslash($_POST['custom_text'])) : '';
    $cta_text    = isset($_POST['cta_text']) ? sanitize_text_field(wp_unslash($_POST['cta_text'])) : 'Prenota il tuo posto';
    $is_launch   = ! empty($_POST['is_launch']);

    if ($event_id <= 0) {
        wp_send_json_error(['message' => __('Seleziona un evento valido.', 'dfn-theme')]);
    }
    if (empty($title)) {
        wp_send_json_error(['message' => __('Inserisci il titolo interno della campagna.', 'dfn-theme')]);
    }
    if (empty($subject)) {
        wp_send_json_error(['message' => __('Inserisci l\'oggetto dell\'email.', 'dfn-theme')]);
    }

    $existing_promo_id = isset($_POST['promo_id']) ? absint($_POST['promo_id']) : 0;

    $campaign_data = [
        'event_id'          => $event_id,
        'title'             => $title,
        'email_subject'     => $subject,
        'email_intro'       => $intro,
        'email_custom_text' => $custom_text,
        'cta_text'          => $cta_text,
        'status'            => $is_launch ? 'sending' : 'draft',
    ];

    if ($existing_promo_id > 0) {
        $campaign_data['id'] = $existing_promo_id;
    }

    $promo_id = dfn_db_save_promotion($campaign_data);
    if (! $promo_id) {
        wp_send_json_error(['message' => __('Errore durante il salvataggio della campagna a database.', 'dfn-theme')]);
    }

    if ($is_launch) {
        // Estrai il target audience con esclusione automatica di chi ha già prenotato
        $audience = dfn_promotions_get_target_audience($event_id);
        $queued_count = dfn_db_populate_promotion_queue($promo_id, $audience);

        if ($queued_count === 0) {
            // Nessun destinatario idoneo disponibile
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'dfn_promotions', ['status' => 'completed'], ['id' => $promo_id]);

            wp_send_json_success([
                'message' => __('Campagna creata! Tutti i clienti del portale risultano già prenotati per questo evento o non ci sono contatti in archivio.', 'dfn-theme'),
                'promo_id' => $promo_id,
            ]);
        }

        // Avvia immediatamente il primo lotto (es. fino a 15 email) per dare feedback immediato
        $batch_result = dfn_promotions_process_queue_batch(15);

        // Assicura che l'evento cron per la coda sia schedulato
        if (! wp_next_scheduled('dfn_cron_process_promotions')) {
            wp_schedule_event(time() + 60, 'every_five_minutes', 'dfn_cron_process_promotions');
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Campagna avviata! %d contatti accodati. Primo lotto di %d email inviato subito, le restanti verranno spedite a scaglioni in background.', 'dfn-theme'),
                $queued_count,
                $batch_result['sent']
            ),
            'promo_id' => $promo_id,
        ]);
    } else {
        wp_send_json_success([
            'message' => __('Bozza della campagna promozionale salvata con successo!', 'dfn-theme'),
            'promo_id' => $promo_id,
        ]);
    }
}

/**
 * Endpoint AJAX: Elabora manualmente il prossimo lotto di invio.
 */
function dfn_ajax_promotions_process_batch(): void
{
    check_ajax_referer('dfn_promotions_ajax_nonce', 'nonce');

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $result = dfn_promotions_process_queue_batch(25);

    wp_send_json_success([
        'message'   => sprintf(__('Lotto elaborato: %d email inviate con successo, %d fallite.', 'dfn-theme'), $result['sent'], $result['failed']),
        'processed' => $result['processed'],
        'sent'      => $result['sent'],
        'failed'    => $result['failed'],
    ]);
}

/**
 * Endpoint AJAX: Interrompe una campagna in corso.
 */
function dfn_ajax_promotions_cancel_campaign(): void
{
    check_ajax_referer('dfn_promotions_ajax_nonce', 'nonce');

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $promo_id = isset($_POST['promo_id']) ? absint($_POST['promo_id']) : 0;
    if ($promo_id <= 0) {
        wp_send_json_error(['message' => __('ID campagna non valido.', 'dfn-theme')]);
    }

    global $wpdb;
    $wpdb->update($wpdb->prefix . 'dfn_promotions', ['status' => 'cancelled', 'completed_at' => current_time('mysql')], ['id' => $promo_id]);

    wp_send_json_success(['message' => __('Campagna interrotta con successo.', 'dfn-theme')]);
}

/**
 * Endpoint AJAX: Elimina una campagna e i relativi record in coda.
 */
function dfn_ajax_promotions_delete_campaign(): void
{
    check_ajax_referer('dfn_promotions_ajax_nonce', 'nonce');

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $promo_id = isset($_POST['promo_id']) ? absint($_POST['promo_id']) : 0;
    if ($promo_id <= 0) {
        wp_send_json_error(['message' => __('ID campagna non valido.', 'dfn-theme')]);
    }

    $deleted = dfn_db_delete_promotion($promo_id);
    if ($deleted) {
        wp_send_json_success(['message' => __('Campagna eliminata con successo.', 'dfn-theme')]);
    } else {
        wp_send_json_error(['message' => __('Impossibile eliminare la campagna.', 'dfn-theme')]);
    }
}

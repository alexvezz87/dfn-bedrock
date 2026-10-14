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
add_action('wp_ajax_dfn_promotions_launch_scheduled_now', 'dfn_ajax_promotions_launch_scheduled_now');
add_action('wp_ajax_dfn_promotions_resume_campaign',   'dfn_ajax_promotions_resume_campaign');

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

    $send_mode        = (isset($_POST['send_mode']) && $_POST['send_mode'] === 'scheduled') ? 'scheduled' : 'immediate';
    $scheduled_at_raw = isset($_POST['scheduled_at']) ? sanitize_text_field($_POST['scheduled_at']) : '';
    $initial_batch_sz = isset($_POST['initial_batch_size']) ? max(1, min(100, absint($_POST['initial_batch_size']))) : 15;
    $batch_size       = isset($_POST['batch_size']) ? max(5, min(200, absint($_POST['batch_size']))) : 25;
    $batch_interval   = isset($_POST['batch_interval_minutes']) ? max(1, min(1440, absint($_POST['batch_interval_minutes']))) : 5;

    $scheduled_mysql = null;
    if ($send_mode === 'scheduled' && ! empty($scheduled_at_raw)) {
        $ts = strtotime(str_replace('T', ' ', $scheduled_at_raw));
        if ($ts && $ts > time()) {
            $scheduled_mysql = date('Y-m-d H:i:s', $ts);
        } else {
            wp_send_json_error(['message' => __('La data e l\'ora di pianificazione devono essere successive al momento attuale.', 'dfn-theme')]);
        }
    }

    $campaign_data = [
        'event_id'               => $event_id,
        'title'                  => $title,
        'email_subject'          => $subject,
        'email_intro'            => $intro,
        'email_custom_text'      => $custom_text,
        'cta_text'               => $cta_text,
        'initial_batch_size'     => $initial_batch_sz,
        'batch_size'             => $batch_size,
        'batch_interval_minutes' => $batch_interval,
        // Imposta inizialmente come 'draft' per evitare che WP-Cron intercetti la campagna
        // prima che la coda dei destinatari sia stata interamente inserita (race condition)
        'status'                 => 'draft',
    ];

    if ($scheduled_mysql) {
        $campaign_data['scheduled_at'] = $scheduled_mysql;
    } elseif ($send_mode === 'immediate' && $is_launch) {
        $campaign_data['scheduled_at'] = current_time('mysql');
    }

    if ($existing_promo_id > 0) {
        $campaign_data['id'] = $existing_promo_id;
    }

    $promo_id = dfn_db_save_promotion($campaign_data);
    if (! $promo_id) {
        global $wpdb;
        $db_err = (! empty($wpdb->last_error) && current_user_can('manage_options')) ? ': ' . $wpdb->last_error : '';
        wp_send_json_error(['message' => __('Errore durante il salvataggio della campagna a database.', 'dfn-theme') . $db_err]);
    }

    if ($is_launch) {
        // Estrai il target audience con esclusione automatica di chi ha già prenotato
        $audience = dfn_promotions_get_target_audience($event_id);
        $queued_count = dfn_db_populate_promotion_queue($promo_id, $audience);

        if ($queued_count === 0) {
            // Nessun destinatario idoneo disponibile: contrassegna come completata
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'dfn_promotions', [
                'status'       => 'completed',
                'completed_at' => current_time('mysql'),
            ], ['id' => $promo_id]);

            wp_send_json_success([
                'message' => __('Campagna creata! Tutti i clienti del portale risultano già prenotati per questo evento o non ci sono contatti in archivio.', 'dfn-theme'),
                'promo_id' => $promo_id,
            ]);
        }

        // Ora che tutti i destinatari sono stati inseriti in coda con successo,
        // aggiorna lo stato su 'scheduled' o 'sending' per sbloccare l'invio
        $target_status = ($send_mode === 'scheduled') ? 'scheduled' : 'sending';
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'dfn_promotions', [
            'status'       => $target_status,
            'completed_at' => null,
        ], ['id' => $promo_id]);

        // Assicura che l'evento cron per la coda sia schedulato
        if (! wp_next_scheduled('dfn_cron_process_promotions')) {
            wp_schedule_event(time() + 60, 'every_five_minutes', 'dfn_cron_process_promotions');
        }

        if ($send_mode === 'scheduled' && $scheduled_mysql) {
            $formatted_date = date_i18n('d/m/Y \a\l\l\e H:i', strtotime($scheduled_mysql));
            wp_send_json_success([
                'message' => sprintf(
                    __('Campagna pianificata con successo per il %s! %d contatti messi in coda. Il primo invio avverrà automaticamente alla data prestabilita.', 'dfn-theme'),
                    $formatted_date,
                    $queued_count
                ),
                'promo_id' => $promo_id,
            ]);
        } else {
            // Invio immediato: avvia il primo lotto personalizzato
            $batch_result = dfn_promotions_process_queue_batch($initial_batch_sz);

            wp_send_json_success([
                'message' => sprintf(
                    __('Campagna avviata! %d contatti accodati. Primo lotto di %d email inviato subito, le restanti verranno spedite a scaglioni in background (%d email ogni %d min).', 'dfn-theme'),
                    $queued_count,
                    $batch_result['sent'],
                    $batch_size,
                    $batch_interval
                ),
                'promo_id' => $promo_id,
            ]);
        }
    } else {
        wp_send_json_success([
            'message' => __('Bozza della campagna promozionale salvata con successo con le impostazioni personalizzate!', 'dfn-theme'),
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

    // Blocca immediatamente tutti i record ancora in attesa in coda
    $blocked_count = (int) $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->prefix}dfn_promotion_queue 
             SET status = 'cancelled', error_message = 'Invio bloccato tempestivamente dall\'amministratore'
             WHERE promotion_id = %d AND status = 'pending'",
            $promo_id
        )
    );

    wp_send_json_success([
        'message'       => sprintf(
            __('Campagna interrotta immediatamente! %d email ancora in coda sono state bloccate e non partiranno.', 'dfn-theme'),
            $blocked_count
        ),
        'blocked_count' => $blocked_count,
    ]);
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

/**
 * Endpoint AJAX: Avvia anticipatamente una campagna in stato pianificato senza attendere l'orario prefissato.
 */
function dfn_ajax_promotions_launch_scheduled_now(): void
{
    check_ajax_referer('dfn_promotions_ajax_nonce', 'nonce');

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $promo_id = isset($_POST['promo_id']) ? absint($_POST['promo_id']) : 0;
    if ($promo_id <= 0) {
        wp_send_json_error(['message' => __('ID campagna non valido.', 'dfn-theme')]);
    }

    $promo = function_exists('dfn_db_get_promotion') ? dfn_db_get_promotion($promo_id) : null;
    if (! $promo || $promo->status !== 'scheduled') {
        wp_send_json_error(['message' => __('Questa campagna non è in stato pianificato.', 'dfn-theme')]);
    }

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'dfn_promotions',
        [
            'status'       => 'sending',
            'scheduled_at' => current_time('mysql'),
        ],
        ['id' => $promo_id]
    );

    // Elabora subito il primo lotto personalizzato
    $initial_batch = ! empty($promo->initial_batch_size) ? absint($promo->initial_batch_size) : 15;
    $batch_result = dfn_promotions_process_queue_batch($initial_batch);

    wp_send_json_success([
        'message' => sprintf(
            __('Pianificazione anticipata con successo! Campagna avviata ora: inviate subito %d email del primo lotto.', 'dfn-theme'),
            $batch_result['sent']
        ),
    ]);
}

/**
 * ========================================================================
 * SEZIONE TRACKING FUNNEL & CONVERSIONI
 * ========================================================================
 */

add_action('init', 'dfn_promotions_handle_tracking_request');

/**
 * Intercetta le richieste di tracking aperture (pixel 1x1) e click (redirect CTA con UTM).
 */
function dfn_promotions_handle_tracking_request(): void
{
    $action = isset($_GET['dfn_action']) ? sanitize_key($_GET['dfn_action']) : '';
    if (! in_array($action, ['promo_open', 'promo_click'], true)) {
        return;
    }

    $token = isset($_GET['dfn_token']) ? sanitize_text_field($_GET['dfn_token']) : '';
    if (empty($token) && isset($_GET['token'])) {
        $token = sanitize_text_field($_GET['token']);
    }

    // 1. Pixel Tracciamento Apertura
    if ($action === 'promo_open') {
        if (! empty($token) && function_exists('dfn_promotions_record_open')) {
            dfn_promotions_record_open($token);
        }

        // Restituisce GIF trasparente 1x1 (43 bytes) non cachabile
        header('Content-Type: image/gif');
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }

    // 2. Redirect Tracciamento Click CTA & Rilascio Cookie di Attribuzione
    if ($action === 'promo_click') {
        $dest_url = home_url('/eventi/');
        if (! empty($token) && function_exists('dfn_promotions_record_click')) {
            $record = dfn_promotions_record_click($token);
            if ($record && ! empty($record['target_url'])) {
                $dest_url = $record['target_url'];
            }
        }

        // Rilascia cookie di prima parte per attribuzione conversione (durata 30 giorni)
        if (! empty($token)) {
            $cookie_path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $cookie_domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
            setcookie('dfn_promo_att', $token, [
                'expires'  => time() + (30 * DAY_IN_SECONDS),
                'path'     => $cookie_path,
                'domain'   => $cookie_domain,
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        wp_safe_redirect($dest_url);
        exit;
    }
}

/**
 * Salva il token di attribuzione della promozione nei metadati dell'ordine al momento del checkout.
 */
add_action('woocommerce_checkout_order_processed', 'dfn_promotions_attach_cookie_to_order', 10, 3);
function dfn_promotions_attach_cookie_to_order(int $order_id, array $posted_data, $order): void
{
    if (! empty($_COOKIE['dfn_promo_att'])) {
        $token = sanitize_text_field($_COOKIE['dfn_promo_att']);
        update_post_meta($order_id, '_dfn_promo_att_token', $token);
    }
}

/**
 * Intercetta il completamento dell'ordine / pagamento per registrare la conversione.
 */
add_action('woocommerce_payment_complete', 'dfn_promotions_handle_order_completed', 20, 1);
add_action('woocommerce_order_status_processing', 'dfn_promotions_handle_order_completed', 20, 1);
add_action('woocommerce_order_status_completed', 'dfn_promotions_handle_order_completed', 20, 1);
function dfn_promotions_handle_order_completed(int $order_id): void
{
    if (function_exists('dfn_promotions_attribute_order')) {
        dfn_promotions_attribute_order($order_id);
    }
}

/**
 * Endpoint AJAX: Riprende l'invio di una campagna congelata o prematuramente chiusa con destinatari pendenti.
 */
function dfn_ajax_promotions_resume_campaign(): void
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
    $table_promotions = $wpdb->prefix . 'dfn_promotions';
    $table_queue      = $wpdb->prefix . 'dfn_promotion_queue';

    $promo = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_promotions} WHERE id = %d", $promo_id));
    if (! $promo) {
        wp_send_json_error(['message' => __('Campagna non trovata.', 'dfn-theme')]);
    }

    $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_queue} WHERE promotion_id = %d AND status = 'pending'", $promo_id));
    if ($pending === 0) {
        wp_send_json_error(['message' => __('Non ci sono destinatari in attesa di invio per questa campagna.', 'dfn-theme')]);
    }

    // Riattiva lo stato sending rimuovendo completed_at e azzerando last_batch_at per invio immediato
    $wpdb->update($table_promotions, [
        'status'        => 'sending',
        'completed_at'  => null,
        'last_batch_at' => null,
    ], ['id' => $promo_id]);

    // Assicura che l'evento cron per la coda sia schedulato
    if (! wp_next_scheduled('dfn_cron_process_promotions')) {
        wp_schedule_event(time() + 60, 'every_five_minutes', 'dfn_cron_process_promotions');
    }

    // Elabora subito un lotto
    $batch_size = ! empty($promo->batch_size) ? absint($promo->batch_size) : 25;
    $batch_result = dfn_promotions_process_queue_batch($batch_size);

    wp_send_json_success([
        'message' => sprintf(
            __('Invio ripreso con successo! Inviate %d email subito, le restanti verranno spedite a lotti ogni %d min in background.', 'dfn-theme'),
            $batch_result['sent'],
            ! empty($promo->batch_interval_minutes) ? $promo->batch_interval_minutes : 5
        ),
        'batch_result' => $batch_result,
    ]);
}



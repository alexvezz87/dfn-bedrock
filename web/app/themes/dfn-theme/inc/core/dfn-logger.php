<?php
/**
 * DFN Booking System 2.0 — Sistema di Log Centralizzato
 *
 * Fornisce l'helper globale dfn_log_write() per registrare azioni
 * di sistema nella tabella wp_dfn_logs.
 * Agganciato automaticamente a wp_mail_failed per catturare gli errori
 * di invio email senza modificare ogni singolo punto di chiamata.
 *
 * @package DFN_Theme
 * @since   2.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Scrive un record nella tabella wp_dfn_logs.
 *
 * @param string $type        Tipologia dell'azione (es. 'email', 'sistema').
 * @param string $executor    Chi ha eseguito l'azione (es. 'FAI Prenotazioni', 'WooCommerce').
 * @param string $description Breve descrizione dell'azione.
 * @param string $outcome     Esito: 'success' oppure 'failure'.
 * @return int|false          ID del record inserito, o false in caso di errore.
 */
function dfn_log_write(string $type, string $executor, string $description, string $outcome = 'success')
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_logs';

    $result = $wpdb->insert(
        $table,
        [
            'logged_at'   => current_time('mysql'),
            'type'        => sanitize_text_field($type),
            'executor'    => sanitize_text_field($executor),
            'description' => sanitize_textarea_field($description),
            'outcome'     => in_array($outcome, ['success', 'failure'], true) ? $outcome : 'success',
        ],
        [ '%s', '%s', '%s', '%s', '%s' ]
    );

    return $result ? $wpdb->insert_id : false;
}

/**
 * Helper specifico per loggare un'email inviata tramite dfn_send_notification_email().
 *
 * @param string       $to       Destinatario (stringa o array).
 * @param string       $subject  Oggetto dell'email.
 * @param string       $from     Mittente (indirizzo email).
 * @param bool         $sent     True se inviata con successo, false altrimenti.
 * @param string       $label    Etichetta breve del tipo di mail (es. 'Conferma prenotazione').
 * @param string       $executor Chi ha avviato l'invio (default: 'FAI Prenotazioni').
 */
function dfn_log_email($to, string $subject, string $from, bool $sent, string $label = '', string $executor = 'FAI Prenotazioni'): void
{
    $to_display  = is_array($to) ? implode(', ', $to) : $to;
    $label_part  = $label ? "[{$label}] " : '';
    $description = "{$label_part}Oggetto: {$subject} | Mittente: {$from} | Destinatario: {$to_display}";

    dfn_log_write(
        'email',
        $executor,
        $description,
        $sent ? 'success' : 'failure'
    );
}

/**
 * Estrae o determina l'indirizzo Mittente (From) da un header email o dai valori di sistema.
 *
 * @param string|array $headers  Header passati a wp_mail.
 * @param string       $executor Nome dell'esecutore rilevato.
 * @return string                Indirizzo o stringa mittente formattata.
 */
function dfn_log_extract_from_address($headers, string $executor): string
{
    $from = '';

    if (is_array($headers)) {
        foreach ($headers as $header) {
            if (is_string($header) && stripos(trim($header), 'from:') === 0) {
                $from = trim(substr(trim($header), 5));
                break;
            }
        }
    } elseif (is_string($headers) && ! empty($headers)) {
        $lines = explode("\n", str_replace("\r\n", "\n", $headers));
        foreach ($lines as $line) {
            if (stripos(trim($line), 'from:') === 0) {
                $from = trim(substr(trim($line), 5));
                break;
            }
        }
    }

    if (empty($from)) {
        if ($executor === 'WooCommerce' && function_exists('WC')) {
            $from_name  = get_option('woocommerce_email_from_name', get_bloginfo('name'));
            $from_email = get_option('woocommerce_email_from_address', get_option('admin_email'));
            $from       = ! empty($from_name) ? "{$from_name} <{$from_email}>" : $from_email;
        } elseif (function_exists('dfn_get_setting')) {
            $from_name  = dfn_get_setting('delegation_name', 'FAI Prenotazioni');
            $from_email = dfn_get_setting('delegation_email', get_option('admin_email'));
            $from       = ! empty($from_name) ? "{$from_name} <{$from_email}>" : $from_email;
        } else {
            $from = get_option('admin_email');
        }
    }

    return $from;
}

/**
 * Hook automatico su wp_mail_succeeded — cattura TUTTI gli invii riusciti di wp_mail()
 * (WooCommerce, FAI Prenotazioni, WordPress core, ecc.).
 *
 * @param array $mail_data Dati dell'email inviata (to, subject, message, headers, attachments).
 */
add_action('wp_mail_succeeded', 'dfn_log_wp_mail_succeeded');
function dfn_log_wp_mail_succeeded(array $mail_data): void
{
    $to_raw   = $mail_data['to'] ?? '';
    $to       = is_array($to_raw) ? implode(', ', $to_raw) : $to_raw;
    $subject  = $mail_data['subject'] ?? 'N/A';
    $headers  = $mail_data['headers'] ?? '';

    $executor = dfn_log_detect_executor();
    $from     = dfn_log_extract_from_address($headers, $executor);

    $description = "Oggetto: {$subject} | Mittente: {$from} | Destinatario: {$to}";

    dfn_log_write(
        'email',
        $executor,
        $description,
        'success'
    );
}

/**
 * Hook automatico su wp_mail_failed — cattura i fallimenti di wp_mail()
 * per tutte le email inviate (WooCommerce, WordPress, ecc.).
 *
 * @param WP_Error $error L'errore restituito da PHPMailer.
 */
add_action('wp_mail_failed', 'dfn_log_wp_mail_failed');
function dfn_log_wp_mail_failed(WP_Error $error): void
{
    $data    = $error->get_error_data();
    $to      = isset($data['to']) ? (is_array($data['to']) ? implode(', ', $data['to']) : $data['to']) : 'N/A';
    $subject = $data['subject'] ?? 'N/A';
    $headers = $data['headers'] ?? '';
    $message = $error->get_error_message();

    $executor = dfn_log_detect_executor();
    $from     = dfn_log_extract_from_address($headers, $executor);

    dfn_log_write(
        'email',
        $executor,
        "ERRORE invio email — Oggetto: {$subject} | Mittente: {$from} | Destinatario: {$to} | Errore: {$message}",
        'failure'
    );
}

/**
 * Rileva l'executor di sistema tramite analisi precisa dello stack di chiamata.
 *
 * @return string Nome dell'executor ('WooCommerce', 'FAI Prenotazioni' o 'WordPress').
 */
function dfn_log_detect_executor(): string
{
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);

    foreach ($backtrace as $frame) {
        $file  = isset($frame['file']) ? str_replace('\\', '/', $frame['file']) : '';
        $class = $frame['class'] ?? '';
        $func  = $frame['function'] ?? '';

        // 1. Controlla prima se un'email WooCommerce ha generato l'invio
        if (
            strpos($class, 'WC_Email') !== false ||
            strpos($class, 'WC_Emails') !== false ||
            strpos($file, '/plugins/woocommerce/') !== false ||
            strpos($file, '/woocommerce/') !== false ||
            $func === 'wc_mail'
        ) {
            return 'WooCommerce';
        }

        // 2. Controlla se l'invio parte dai nostri file di notifica FAI Prenotazioni
        if (
            strpos($file, 'dfn-notifications.php') !== false ||
            strpos($file, 'dfn-security.php') !== false ||
            $func === 'dfn_send_notification_email'
        ) {
            return 'FAI Prenotazioni';
        }
    }

    return 'WordPress';
}



/**
 * Elimina automaticamente i log più vecchi di N giorni.
 *
 * @param int $days Numero di giorni di retention (default: 90).
 */
function dfn_log_purge_old(int $days = 90): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_logs';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));
}

// Pianifica la pulizia log settimanale tramite wp-cron
add_action('init', function () {
    if (! wp_next_scheduled('dfn_cron_log_purge')) {
        wp_schedule_event(time(), 'weekly', 'dfn_cron_log_purge');
    }
});

add_action('dfn_cron_log_purge', function () {
    $retention = (int) dfn_get_setting('log_retention_days', 90);
    dfn_log_purge_old($retention ?: 90);
});

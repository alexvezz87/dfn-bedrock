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
 * Hook automatico su wp_mail_succeeded — cattura TUTTI gli invii riusciti di wp_mail()
 * (WooCommerce, FAI Prenotazioni, WordPress core, ecc.).
 *
 * @param array $mail_data Dati dell'email inviata (to, subject, message, headers, attachments).
 */
add_action('wp_mail_succeeded', 'dfn_log_wp_mail_succeeded');
function dfn_log_wp_mail_succeeded(array $mail_data): void
{
    $to_raw  = $mail_data['to'] ?? '';
    $to      = is_array($to_raw) ? implode(', ', $to_raw) : $to_raw;
    $subject = $mail_data['subject'] ?? 'N/A';

    $executor = dfn_log_detect_executor();

    $description = "Oggetto: {$subject} | Destinatario: {$to}";

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
    $message = $error->get_error_message();

    $executor = dfn_log_detect_executor();

    dfn_log_write(
        'email',
        $executor,
        "ERRORE invio email — Destinatario: {$to} | Oggetto: {$subject} | Errore: {$message}",
        'failure'
    );
}

/**
 * Rileva l'executor di sistema tramite analisi dello stack di chiamata.
 * Cerca nei frame di chiamata i namespace WooCommerce, FAI Prenotazioni o WordPress.
 *
 * @return string Nome dell'executor.
 */
function dfn_log_detect_executor(): string
{
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
    foreach ($backtrace as $frame) {
        $file  = $frame['file'] ?? '';
        $class = $frame['class'] ?? '';
        $func  = $frame['function'] ?? '';

        if (strpos($file, 'woocommerce') !== false || strpos($class, 'WC_') !== false || strpos($func, 'wc_') !== false) {
            return 'WooCommerce';
        }
        if (strpos($file, 'dfn-') !== false || strpos($func, 'dfn_') !== false) {
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

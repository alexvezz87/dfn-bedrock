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
 * Recupera l'indirizzo IP del client in modo sicuro, tenendo conto di eventuali proxy o Cloudflare.
 *
 * @return string Indirizzo IP anonimizzato/valido o '127.0.0.1'.
 */
function dfn_log_get_client_ip(): string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];

    foreach ($keys as $key) {
        if (! empty($_SERVER[$key])) {
            $ip_list = explode(',', (string) $_SERVER[$key]);
            $ip      = trim($ip_list[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return '127.0.0.1';
}

/**
 * Risolve l'etichetta amichevole del ruolo o dei ruoli assegnati a un utente WP_User.
 *
 * @param WP_User|int|null $user Istanza utente o ID utente.
 * @return string Etichetta/e del ruolo separate da virgola (es. 'Segreteria FAI', 'Cliente', 'Amministratore').
 */
function dfn_log_get_user_roles_label($user): string
{
    if (is_numeric($user)) {
        $user = get_userdata((int) $user);
    }

    if (! ($user instanceof WP_User) || empty($user->roles)) {
        return __('Ospite / Non registrato', 'dfn-theme');
    }

    // Se esiste la configurazione dei ruoli FAI, usiamo le etichette amichevoli
    $custom_roles = function_exists('dfn_get_custom_roles_list') ? dfn_get_custom_roles_list() : [];
    
    // Mappa dei ruoli WP nativi in italiano
    $native_role_names = [
        'administrator' => __('Amministratore', 'dfn-theme'),
        'editor'        => __('Editor', 'dfn-theme'),
        'author'        => __('Autore', 'dfn-theme'),
        'contributor'   => __('Collaboratore', 'dfn-theme'),
        'subscriber'    => __('Sottoscrittore', 'dfn-theme'),
        'customer'      => __('Cliente', 'dfn-theme'),
        'shop_manager'  => __('Gestore Negozio', 'dfn-theme'),
    ];

    $labels = [];
    foreach ($user->roles as $role_key) {
        if (isset($custom_roles[$role_key]['name'])) {
            $labels[] = $custom_roles[$role_key]['name'];
        } elseif (isset($native_role_names[$role_key])) {
            $labels[] = $native_role_names[$role_key];
        } else {
            $labels[] = ucfirst(str_replace(['_', '-'], ' ', $role_key));
        }
    }

    return implode(', ', $labels);
}

/**
 * Hook automatico su 'wp_login' — registra gli accessi riusciti al sito.
 *
 * @param string  $user_login Nome utente con cui è avvenuto il login.
 * @param WP_User $user       Oggetto WP_User dell'utente autenticato.
 */
add_action('wp_login', 'dfn_log_wp_login', 20, 2);
function dfn_log_wp_login(string $user_login, WP_User $user): void
{
    $roles_label  = dfn_log_get_user_roles_label($user);
    $ip           = dfn_log_get_client_ip();
    $display_name = ! empty($user->display_name) ? $user->display_name : $user_login;
    $email        = ! empty($user->user_email) ? $user->user_email : 'N/A';

    $description = sprintf(
        "Accesso riuscito al sistema | Utente: %s (%s) | Ruolo: %s | Email: %s | IP: %s",
        $display_name,
        $user_login,
        $roles_label,
        $email,
        $ip
    );

    dfn_log_write(
        'login',
        $display_name,
        $description,
        'success'
    );
}

/**
 * Hook automatico su 'wp_login_failed' — registra i tentativi di accesso falliti.
 *
 * @param string $username Username inserito durante il tentativo di login.
 */
add_action('wp_login_failed', 'dfn_log_wp_login_failed', 20, 1);
function dfn_log_wp_login_failed(string $username): void
{
    $ip          = dfn_log_get_client_ip();
    $attempted   = ! empty($username) ? sanitize_text_field($username) : 'Sconosciuto / Vuoto';
    $description = sprintf(
        "Tentativo di accesso fallito (credenziali non valide) | Username inserito: %s | IP: %s",
        $attempted,
        $ip
    );

    dfn_log_write(
        'login',
        $attempted,
        $description,
        'failure'
    );
}

/**
 * Hook automatico su 'wp_logout' — registra la disconnessione degli utenti.
 *
 * @param int $user_id ID dell'utente che si sta disconnettendo.
 */
add_action('wp_logout', 'dfn_log_wp_logout', 20, 1);
function dfn_log_wp_logout(int $user_id = 0): void
{
    $current_user = $user_id ? get_userdata($user_id) : wp_get_current_user();
    $ip           = dfn_log_get_client_ip();

    if ($current_user && $current_user->exists()) {
        $display_name = ! empty($current_user->display_name) ? $current_user->display_name : $current_user->user_login;
        $roles_label  = dfn_log_get_user_roles_label($current_user);

        $description = sprintf(
            "Disconnessione (logout) dal sito | Utente: %s (%s) | Ruolo: %s | IP: %s",
            $display_name,
            $current_user->user_login,
            $roles_label,
            $ip
        );
        $executor = $display_name;
    } else {
        $executor    = 'Ospite / Sessione';
        $description = sprintf("Disconnessione (logout) sessione utente | IP: %s", $ip);
    }

    dfn_log_write(
        'logout',
        $executor,
        $description,
        'success'
    );
}

/**
 * Hook automatico su 'password_reset' — registra il cambio/reimpostazione password.
 *
 * @param WP_User $user Oggetto WP_User dell'utente che ha reimpostato la password.
 */
add_action('password_reset', 'dfn_log_password_reset', 20, 1);
function dfn_log_password_reset(WP_User $user): void
{
    $ip           = dfn_log_get_client_ip();
    $display_name = ! empty($user->display_name) ? $user->display_name : $user->user_login;
    $roles_label  = dfn_log_get_user_roles_label($user);

    $description = sprintf(
        "Reimpostazione password completata con successo | Utente: %s (%s) | Ruolo: %s | IP: %s",
        $display_name,
        $user->user_login,
        $roles_label,
        $ip
    );

    dfn_log_write(
        'sicurezza',
        $display_name,
        $description,
        'success'
    );
}

/**
 * Hook automatico su 'profile_update' — registra la modifica dei dati del profilo utente.
 *
 * @param int     $user_id       ID dell'utente modificato.
 * @param WP_User $old_user_data Dati utente precedenti alla modifica.
 */
add_action('profile_update', 'dfn_log_profile_update', 20, 2);
function dfn_log_profile_update(int $user_id, WP_User $old_user_data): void
{
    $new_user = get_userdata($user_id);
    if (! ($new_user instanceof WP_User)) {
        return;
    }

    // Identifica chi ha effettuato la modifica (l'utente stesso o un amministratore)
    $modifier_id   = get_current_user_id();
    $modifier_user = $modifier_id ? get_userdata($modifier_id) : null;
    $modifier_name = ($modifier_user instanceof WP_User) ? $modifier_user->display_name : $new_user->display_name;

    $ip          = dfn_log_get_client_ip();
    $roles_label = dfn_log_get_user_roles_label($new_user);

    $changes = [];
    if ($old_user_data->user_email !== $new_user->user_email) {
        $changes[] = sprintf("Email modificata (%s -> %s)", $old_user_data->user_email, $new_user->user_email);
    }
    if ($old_user_data->display_name !== $new_user->display_name) {
        $changes[] = sprintf("Nome visualizzato modificato (%s -> %s)", $old_user_data->display_name, $new_user->display_name);
    }
    if ($old_user_data->user_pass !== $new_user->user_pass) {
        $changes[] = "Password aggiornata dal profilo";
    }

    $change_str = ! empty($changes) ? implode('; ', $changes) : 'Aggiornamento anagrafica / impostazioni account';

    $description = sprintf(
        "Modifica dati profilo | Utente target: %s (%s) | Ruolo: %s | Modificato da: %s | Dettagli: %s | IP: %s",
        $new_user->display_name,
        $new_user->user_login,
        $roles_label,
        $modifier_name,
        $change_str,
        $ip
    );

    dfn_log_write(
        'profilo',
        $modifier_name,
        $description,
        'success'
    );
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


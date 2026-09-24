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

    // Cerca di estrarre ID ordine o ID prenotazione per arricchire la ricerca nei log
    $context_tag = $GLOBALS['dfn_current_email_context'] ?? '';
    if (empty($context_tag) && preg_match('/#(\d{3,7})\b/', $subject, $m_sub)) {
        $context_tag = "[Ordine #{$m_sub[1]}] ";
    }

    $description = "{$context_tag}Oggetto: {$subject} | Mittente: {$from} | Destinatario: {$to}";

    dfn_log_write(
        'email',
        $executor,
        $description,
        'success'
    );
}

/**
 * Registra un'azione di business relativa a una prenotazione (creazione, conferma, approvazione).
 *
 * @param int    $booking_id ID della prenotazione.
 * @param string $action     Etichetta dell'azione (es. 'Creata', 'Approvata dallo staff').
 * @param string $details    Eventuali note o dettagli aggiuntivi.
 * @param string $actor      Chi ha eseguito l'azione (se vuoto, deduce dall'utente o dal cliente).
 */
function dfn_log_booking(int $booking_id, string $action, string $details = '', string $actor = ''): void
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return;
    }

    $order_id    = ! empty($booking->order_id) ? (string) $booking->order_id : 'N/D';
    $event       = function_exists('dfn_db_get_event') ? dfn_db_get_event((int) $booking->event_id) : null;
    $event_title = $event ? get_the_title($event->product_id) : 'Evento #' . $booking->event_id;

    if (empty($actor)) {
        $user = wp_get_current_user();
        $actor = ($user && $user->exists()) ? $user->display_name : ($booking->customer_name ?: 'Visitatore');
    }

    $desc = sprintf(
        "Prenotazione #%d (Ordine #%s) | Evento: %s | Cliente: %s (%s) | Posti: %d (Interi: %d, FAI: %d) | Azione: %s",
        $booking_id,
        $order_id,
        $event_title,
        $booking->customer_name,
        $booking->customer_email,
        (int) $booking->total_persons,
        (int) $booking->persons_standard,
        (int) $booking->persons_fai,
        $action
    );

    if (! empty($details)) {
        $desc .= " | Dettagli: " . $details;
    }

    dfn_log_write('prenotazione', $actor, $desc, 'success');
}

/**
 * Registra il rilascio di una recensione da parte di un visitatore.
 *
 * @param int    $booking_id ID della prenotazione.
 * @param int    $rating     Voto espresso (1-5).
 * @param string $comment    Commento opzionale del visitatore.
 * @param string $actor      Nome del visitatore (se vuoto, deduce dalla prenotazione o utente).
 */
function dfn_log_review(int $booking_id, int $rating, string $comment = '', string $actor = ''): void
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return;
    }

    $order_id    = ! empty($booking->order_id) ? (string) $booking->order_id : 'N/D';
    $event       = function_exists('dfn_db_get_event') ? dfn_db_get_event((int) $booking->event_id) : null;
    $event_title = $event ? get_the_title($event->product_id) : 'Evento #' . $booking->event_id;

    if (empty($actor)) {
        $user  = wp_get_current_user();
        $actor = ($user && $user->exists()) ? $user->display_name : ($booking->customer_name ?: 'Visitatore');
    }

    $desc = sprintf(
        "Prenotazione #%d (Ordine #%s) | Evento: %s | Cliente: %s (%s) | Posti: %d (Interi: %d, FAI: %d) | Azione: Recensione Ricevuta",
        $booking_id,
        $order_id,
        $event_title,
        $booking->customer_name,
        $booking->customer_email,
        (int) $booking->total_persons,
        (int) $booking->persons_standard,
        (int) $booking->persons_fai
    );

    $comment_text = ! empty($comment) ? sprintf(' | Commento: "%s"', $comment) : '';
    $desc .= sprintf(' | Dettagli: Voto: %d/5 stelle%s', $rating, $comment_text);

    dfn_log_write('recensione', $actor, $desc, 'success');
}

/**
 * Registra l'annullamento di una prenotazione.
 *
 * @param int    $booking_id      ID della prenotazione.
 * @param string $cancelled_by    Autore dell'annullamento (es. Visitatore, Staff, Cron Timeout).
 * @param string $reason          Motivo o modalità di annullamento.
 * @param int    $seats_restored  Numero di posti ripristinati in capienza / magazzino.
 */
function dfn_log_cancellation(int $booking_id, string $cancelled_by, string $reason = '', int $seats_restored = 0): void
{
    global $wpdb;
    $booking     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    $order_id    = $booking && ! empty($booking->order_id) ? (string) $booking->order_id : 'N/D';
    $event_id    = $booking ? (int) $booking->event_id : 0;
    $event       = $event_id && function_exists('dfn_db_get_event') ? dfn_db_get_event($event_id) : null;
    $event_title = $event ? get_the_title($event->product_id) : ($event_id ? 'Evento #' . $event_id : 'N/D');
    $customer    = $booking ? "{$booking->customer_name} ({$booking->customer_email})" : 'N/D';

    $desc = sprintf(
        "Annullamento Prenotazione #%d (Ordine #%s) | Evento: %s | Cliente: %s | Annullato da: %s",
        $booking_id,
        $order_id,
        $event_title,
        $customer,
        $cancelled_by
    );

    if (! empty($reason)) {
        $desc .= " | Motivo: " . $reason;
    }
    if ($seats_restored > 0) {
        $desc .= sprintf(" | Posti ripristinati: +%d", $seats_restored);
    }

    dfn_log_write('annullamento', $cancelled_by, $desc, 'success');
}

/**
 * Registra la convalida o il rifiuto di una tessera FAI.
 *
 * @param string $card_number Numero tessera FAI.
 * @param string $action      Azione eseguita ('Convalidata', 'Rifiutata').
 * @param string $actor       Chi ha eseguito l'azione.
 * @param string $details     Dettagli opzionali (es. nominativo socio, motivazione o ordine).
 */
function dfn_log_fai_card(string $card_number, string $action, string $actor = '', string $details = ''): void
{
    if (empty($actor)) {
        $user  = wp_get_current_user();
        $actor = ($user && $user->exists()) ? $user->display_name : 'Staff FAI';
    }

    $is_rejection = (stripos($action, 'rifiut') !== false || stripos($action, 'reject') !== false);
    $outcome      = $is_rejection ? 'failure' : 'success';

    $desc = sprintf("Tessera FAI n° %s | Azione: %s", $card_number, $action);
    if (! empty($details)) {
        $desc .= " | " . $details;
    }

    dfn_log_write('tessera_fai', $actor, $desc, $outcome);
}

/**
 * Registra la scansione del biglietto / check-in al varco d'ingresso.
 *
 * @param int    $booking_id ID della prenotazione.
 * @param string $actor      Operatore o volontario allo scanner.
 * @param string $details    Dettagli (es. varchi, posti convalidati).
 */
function dfn_log_checkin(int $booking_id, string $actor = '', string $details = ''): void
{
    global $wpdb;
    $booking     = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    $order_id    = $booking && ! empty($booking->order_id) ? (string) $booking->order_id : 'N/D';
    $event_id    = $booking ? (int) $booking->event_id : 0;
    $event       = $event_id && function_exists('dfn_db_get_event') ? dfn_db_get_event($event_id) : null;
    $event_title = $event ? get_the_title($event->product_id) : ($event_id ? 'Evento #' . $event_id : 'N/D');

    if (empty($actor)) {
        $user  = wp_get_current_user();
        $actor = ($user && $user->exists()) ? $user->display_name : 'Volontario / Scanner';
    }

    $desc = sprintf(
        "Check-in QR convalidato | Prenotazione #%d (Ordine #%s) | Evento: %s | Ospite: %s | Posti: %d",
        $booking_id,
        $order_id,
        $event_title,
        $booking ? $booking->customer_name : 'N/D',
        $booking ? (int) $booking->total_persons : 0
    );

    if (! empty($details)) {
        $desc .= " | " . $details;
    }

    dfn_log_write('checkin', $actor, $desc, 'success');
}

/**
 * Registra lo spostamento di turno o orario di una prenotazione.
 *
 * @param int    $booking_id    ID della prenotazione.
 * @param string $old_slot_info Info slot originario.
 * @param string $new_slot_info Info nuovo slot assegnato.
 * @param string $actor         Operatore che ha effettuato lo spostamento.
 */
function dfn_log_slot_change(int $booking_id, string $old_slot_info, string $new_slot_info, string $actor = ''): void
{
    global $wpdb;
    $booking  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    $order_id = $booking && ! empty($booking->order_id) ? (string) $booking->order_id : 'N/D';

    if (empty($actor)) {
        $user  = wp_get_current_user();
        $actor = ($user && $user->exists()) ? $user->display_name : 'Staff';
    }

    $desc = sprintf(
        "Spostamento Turno | Prenotazione #%d (Ordine #%s) | Ospite: %s | Da: %s | A: %s",
        $booking_id,
        $order_id,
        $booking ? $booking->customer_name : 'N/D',
        $old_slot_info,
        $new_slot_info
    );

    dfn_log_write('spostamento', $actor, $desc, 'success');
}

/**
 * Registra variazioni o ripristini manuali/automatici delle scorte di posti.
 *
 * @param int    $event_id   ID evento DFN.
 * @param int    $product_id ID prodotto WooCommerce.
 * @param int    $qty_diff   Differenza posti (+N o -N).
 * @param string $reason     Motivo dell'aggiornamento.
 * @param string $actor      Chi ha effettuato la variazione.
 */
function dfn_log_stock(int $event_id, int $product_id, int $qty_diff, string $reason, string $actor = 'Sistema'): void
{
    $product_name = get_the_title($product_id) ?: 'Prodotto #' . $product_id;
    $diff_str     = ($qty_diff > 0 ? "+{$qty_diff}" : "{$qty_diff}") . " posti";

    $desc = sprintf(
        "Variazione Disponibilità (%s) | Evento #%d (%s) | Motivo: %s",
        $diff_str,
        $event_id,
        $product_name,
        $reason
    );

    dfn_log_write('stock', $actor, $desc, 'success');
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
 * Risolve l'etichetta amichevole del ruolo o dei ruoli assegnati a un utente WP_User (compresi i ruoli delegazione FAI).
 *
 * @param WP_User|int|null $user Istanza utente o ID utente.
 * @return string Etichetta/e del ruolo separate da virgola (es. 'Cliente, Segreteria FAI').
 */
function dfn_log_get_user_roles_label($user): string
{
    if (is_numeric($user)) {
        $user = get_userdata((int) $user);
    }

    if (! ($user instanceof WP_User)) {
        return __('Ospite / Non registrato', 'dfn-theme');
    }

    $all_role_slugs = (array) ($user->roles ?? []);

    // Recupera anche tutti i ruoli FAI assegnati nello user_meta
    $assigned_fai_roles = get_user_meta($user->ID, '_dfn_assigned_fai_roles', true);
    if (is_array($assigned_fai_roles) && ! empty($assigned_fai_roles)) {
        $all_role_slugs = array_unique(array_merge($all_role_slugs, $assigned_fai_roles));
    }

    if (empty($all_role_slugs)) {
        return __('Ospite / Non registrato', 'dfn-theme');
    }

    // Se esiste la configurazione dei ruoli FAI, recuperiamo le definizioni
    $stored_fai_roles = function_exists('dfn_get_stored_roles') ? dfn_get_stored_roles() : [];
    
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
    foreach ($all_role_slugs as $role_key) {
        if (isset($stored_fai_roles[$role_key]['label'])) {
            $labels[] = $stored_fai_roles[$role_key]['label'];
        } elseif (isset($native_role_names[$role_key])) {
            $labels[] = $native_role_names[$role_key];
        } else {
            $labels[] = ucfirst(str_replace(['_', '-'], ' ', $role_key));
        }
    }

    return ! empty($labels) ? implode(', ', array_unique($labels)) : __('Cliente', 'dfn-theme');
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




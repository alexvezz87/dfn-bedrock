<?php
/**
 * DFN Booking System 2.0 — Centralized Email Notifications
 *
 * Gestisce tutti gli invii di notifiche via email con template HTML premium
 * in linea con la palette FAI (verde scuro e ocra).
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Invia un'email HTML formattata con il template premium di FAI Prenotazioni / Volontari.
 *
 * @param string|array $to              Destinatario o lista di destinatari.
 * @param string       $subject         Oggetto dell'email.
 * @param string       $title           Titolo visivo all'interno del template.
 * @param string       $content_html     Contenuto HTML principale.
 * @param array        $attachments      Allegati (opzionale).
 * @param string       $context_info     Tag di contesto opzionale per il logger (es. '[Ordine #123] [Booking #45]').
 * @param array        $sender_override  Mittente personalizzato opzionale: ['name' => '...', 'email' => '...'].
 * @return bool True se l'invio ha avuto successo, false altrimenti.
 */
function dfn_send_notification_email($to, $subject, $title, $content_html, $attachments = [], string $context_info = '', array $sender_override = [])
{
    if (! empty($context_info)) {
        $GLOBALS['dfn_current_email_context'] = rtrim($context_info) . ' ';
    }

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    // Gestione Mittente Personalizzato (Solo Nome Mittente per garantire l'autenticazione SPF/DKIM del server SMTP)
    $from_name = ! empty($sender_override['name']) ? trim((string) $sender_override['name']) : '';
    $reply_to  = ! empty($sender_override['reply_to']) ? trim((string) $sender_override['reply_to']) : '';

    if (! empty($reply_to) && is_email($reply_to)) {
        $headers[] = sprintf('Reply-To: %s <%s>', $from_name ?: get_bloginfo('name'), $reply_to);
    }

    // Gestione Cc (Copia Visibile)
    $cc_raw = dfn_get_setting('email_cc', '');
    if (! empty($cc_raw)) {
        $emails_cc = array_map('sanitize_email', array_map('trim', explode(',', $cc_raw)));
        foreach ($emails_cc as $email) {
            if (is_email($email)) {
                $headers[] = 'Cc: ' . $email;
            }
        }
    }

    // Gestione Bcc (Copia Nascosta)
    $bcc_raw = dfn_get_setting('email_bcc', '');
    if (empty($bcc_raw)) {
        $bcc_raw = dfn_get_setting('email_cc_bcc', ''); // Retrocompatibilità per impostazione precedente
    }
    if (! empty($bcc_raw)) {
        $emails_bcc = array_map('sanitize_email', array_map('trim', explode(',', $bcc_raw)));
        foreach ($emails_bcc as $email) {
            if (is_email($email)) {
                $headers[] = 'Bcc: ' . $email;
            }
        }
    }

    // GESTIONE AMBIENTE & SICUREZZA TEST (STAGING / SANDBOX / MUTE)
    $sandbox_mode = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('email_sandbox_mode', 'live') : (function_exists('dfn_get_setting') ? dfn_get_setting('email_sandbox_mode', 'live') : 'live');

    // 1. MUTE MODE (Blocco Totale Silenzioso - Non invia nulla e logga l'evento)
    if ($sandbox_mode === 'mute') {
        $to_str = is_array($to) ? implode(', ', $to) : (string) $to;
        if (function_exists('dfn_log_write')) {
            dfn_log_write('sistema', 'DFN Mailer [MUTE]', sprintf("Email intercettata [MUTE MODE - Destinatario: %s | Oggetto: %s]", $to_str, $subject), 'info');
        }
        $GLOBALS['dfn_current_email_context'] = '';
        return true; // Restituisce true per non interrompere i processi di checkout o cron
    }

    // 2. SANDBOX REDIRECT MODE (Devia tutte le email a una casella di test designata)
    if ($sandbox_mode === 'sandbox_redirect') {
        $sandbox_recipient = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('email_sandbox_recipient', '') : '';
        if (empty($sandbox_recipient) && function_exists('dfn_get_setting')) {
            $sandbox_recipient = dfn_get_setting('email_sandbox_recipient', '');
        }
        if (empty($sandbox_recipient)) {
            $sandbox_recipient = get_option('admin_email');
        }

        $orig_to_str = is_array($to) ? implode(', ', $to) : (string) $to;
        $subject = '[TEST SANDBOX] ' . $subject;
        $sandbox_banner = '<div style="background:#fffbeb; border:2px dashed #f59e0b; padding:12px 16px; margin-bottom:20px; border-radius:6px; font-family:sans-serif; font-size:13px; color:#92400e;"><strong>🧪 EMAIL IN MODALITÀ SANDBOX / TEST STAGING</strong><br>Destinatario originario: <code>' . esc_html($orig_to_str) . '</code></div>';
        $content_html = $sandbox_banner . $content_html;
        $to = $sandbox_recipient;
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ]; // Reset CC/BCC per evitare fughe di email
    }

    // Filtro dinamico per applicare il nome mittente (From Name) mantenendo l'indirizzo email del server SMTP
    $filter_from_name = null;
    if (! empty($from_name)) {
        $filter_from_name = function () use ($from_name) {
            return $from_name;
        };
        add_filter('wp_mail_from_name', $filter_from_name, 9999);
    }

    // Se $to contiene virgole, lo convertiamo in array per wp_mail
    if (is_string($to) && strpos($to, ',') !== false) {
        $to = array_map('sanitize_email', array_map('trim', explode(',', $to)));
        $to = array_filter($to, 'is_email');
    }

    // Evita l'invio all'indirizzo fittizio no-email@dfn.it
    if (is_string($to)) {
        if (trim(strtolower($to)) === 'no-email@dfn.it') {
            $GLOBALS['dfn_current_email_context'] = '';
            if ($filter_from_name) {
                remove_filter('wp_mail_from_name', $filter_from_name, 9999);
            }
            return true;
        }
    } elseif (is_array($to)) {
        $to = array_filter($to, function ($email) {
            return trim(strtolower($email)) !== 'no-email@dfn.it';
        });
        if (empty($to)) {
            $GLOBALS['dfn_current_email_context'] = '';
            if ($filter_from_name) {
                remove_filter('wp_mail_from_name', $filter_from_name, 9999);
            }
            return true;
        }
    }

    // Genera il template HTML completo
    $body = dfn_get_email_html_template($title, $content_html);

    $sent = wp_mail($to, $subject, $body, $headers, $attachments);

    // Rimozione filtro temporaneo sul nome mittente
    if ($filter_from_name !== null) {
        remove_filter('wp_mail_from_name', $filter_from_name, 9999);
    }

    $GLOBALS['dfn_current_email_context'] = '';

    return $sent;
}


/**
 * Restituisce la struttura HTML del template email premium FAI Novara.
 *
 * @param string $title        Titolo dell'email.
 * @param string $content_html Contenuto HTML principale.
 * @return string HTML completo.
 */
function dfn_get_email_html_template($title, $content_html)
{
    $bg_color      = dfn_get_setting('email_bg_color', '#f4f6f8');
    $primary_color = dfn_get_setting('email_primary_color', '#004b23');
    $accent_color  = dfn_get_setting('email_accent_color', '#e74f30');
    $text_color    = dfn_get_setting('email_text_color', '#2d3748');
    $white         = '#ffffff';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo esc_html($title); ?></title>
        <style>
            body {
                margin: 0;
                padding: 0;
                background-color: <?php echo esc_attr($bg_color); ?>;
                font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
                color: <?php echo esc_attr($text_color); ?>;
                -webkit-font-smoothing: antialiased;
            }
            .email-container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .email-header {
                background-color: <?php echo esc_attr($primary_color); ?>;
                padding: 30px;
                text-align: center;
                border-top-left-radius: 8px;
                border-top-right-radius: 8px;
                border-bottom: 4px solid <?php echo esc_attr($accent_color); ?>;
            }
            .email-header h1 {
                color: <?php echo esc_attr($white); ?>;
                margin: 0;
                font-size: 24px;
                font-weight: 600;
                letter-spacing: 0.5px;
            }
            .email-body {
                background-color: <?php echo esc_attr($white); ?>;
                padding: 40px 30px;
                border-bottom-left-radius: 8px;
                border-bottom-right-radius: 8px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            }
            .email-footer {
                text-align: center;
                padding: 20px;
                font-size: 12px;
                color: #718096;
            }
            p {
                font-size: 16px;
                line-height: 1.6;
                margin-top: 0;
                margin-bottom: 20px;
            }
            .button {
                display: inline-block;
                background-color: <?php echo esc_attr($primary_color); ?>;
                color: <?php echo esc_attr($white); ?> !important;
                padding: 14px 28px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: bold;
                font-size: 15px;
                margin-top: 15px;
                margin-bottom: 15px;
                text-align: center;
                border-bottom: 3px solid #002e15;
            }
            .info-box {
                background-color: #f7fafc;
                border-left: 4px solid <?php echo esc_attr($accent_color); ?>;
                padding: 20px;
                margin: 25px 0;
                border-radius: 0 6px 6px 0;
            }
            .info-box-title {
                font-weight: bold;
                font-size: 15px;
                color: <?php echo esc_attr($primary_color); ?>;
                margin-bottom: 10px;
            }
            .info-box table {
                width: 100%;
                border-collapse: collapse;
            }
            .info-box table td {
                padding: 6px 0;
                font-size: 14px;
                vertical-align: top;
            }
            .info-box table td.label {
                font-weight: bold;
                color: #4a5568;
                width: 140px;
            }
            .text-center {
                text-align: center;
            }
            .divider {
                height: 1px;
                background-color: #e2e8f0;
                margin: 25px 0;
            }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="email-header">
                <h1><?php echo esc_html(dfn_get_setting('delegation_name', 'FAI Novara')); ?></h1>
            </div>
            <div class="email-body">
                <h2 style="color: <?php echo esc_attr($primary_color); ?>; margin-top: 0; margin-bottom: 20px; font-size: 20px;"><?php echo esc_html($title); ?></h2>
                <?php echo $content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
            </div>
            <div class="email-footer">
                <p style="font-size: 11px; margin-bottom: 5px;"><?php echo esc_html(dfn_get_setting('delegation_footer', 'FAI - Delegazione di Novara')); ?> &copy; <?php echo esc_html(date('Y')); ?></p>
                <p style="font-size: 10px;"><?php echo esc_html(dfn_get_setting('email_disclaimer', "Questa è un'email automatica inviata dal sistema di prenotazione. Si prega di non rispondere direttamente.")); ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Invia email di conferma prenotazione immediata (workflow automatico).
 *
 * @param int $booking_id ID del booking nella tabella dfn_bookings.
 * @return bool
 */
function dfn_send_booking_confirmation(int $booking_id)
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        return false;
    }

    // Recupera informazioni sullo slot
    $slot_info = '';
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, bs.persons FROM {$wpdb->prefix}dfn_event_slots s 
         JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id 
         WHERE bs.booking_id = %d",
        $booking_id,
    ));

    if (! empty($slots)) {
        if (count($slots) === 1) {
            $slot = $slots[0];
            $slot_info = date_i18n('d F Y', strtotime($slot->slot_date)) . ' - ore ' . date('H:i', strtotime($slot->slot_time_start));
        } else {
            $slot_info_parts = [];
            foreach ($slots as $s) {
                $slot_info_parts[] = 'ore ' . date('H:i', strtotime($s->slot_time_start)) . ' (' . absint($s->persons) . ' ' . ($s->persons == 1 ? 'persona' : 'persone') . ')';
            }
            $slot_info = date_i18n('d F Y', strtotime($slots[0]->slot_date)) . ' — ' . implode(', ', $slot_info_parts);
        }
    } else {
        $slot_info = date_i18n('d F Y', strtotime($event->event_date_start)) . ' (Ingresso Libero)';
    }

    $product_name = get_the_title($event->product_id);

    // Link all'hub biglietti / QR effettivo
    $token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
    $hub_url = add_query_arg([
        'dfn_hub'  => 1,
        'order_id' => $booking->order_id,
        'token'    => $token,
    ], home_url('/'));

    // Link di cancellazione
    $cancel_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
    $cancel_url = add_query_arg([
        'dfn_cancel_booking' => 1,
        'order_id'           => $booking->order_id,
        'token'              => $cancel_token,
    ], home_url('/'));

    // Link di modifica
    $modify_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_modify', wp_salt('nonce'));
    $modify_url = add_query_arg([
        'dfn_modify_booking' => 1,
        'order_id'           => $booking->order_id,
        'token'              => $modify_token,
    ], home_url('/'));

    $details_table = '<div class="info-box">';
    $details_table .= '<div class="info-box-title">Dettagli della Prenotazione</div>';
    $details_table .= '<table>';
    $details_table .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td class="label">Data e Inizio Visita:</td><td>' . esc_html($slot_info) . '</td></tr>';
    $details_table .= '<tr><td class="label">Luogo:</td><td>' . esc_html($event->location) . '</td></tr>';
    $details_table .= '<tr><td class="label">Partecipanti:</td><td>' . absint($booking->total_persons) . ' totali (' . absint($booking->persons_standard) . ' Standard + ' . absint($booking->persons_fai) . ' Soci FAI)</td></tr>';
    $is_event_free = ($event && (
        (floatval($event->price_standard) === 0.00 && floatval($event->price_fai) === 0.00) ||
        ($event->pricing_type ?? '') === 'free' ||
        ! empty($event->is_free)
    ));
    if ($is_event_free && floatval($booking->amount_due) > 0) {
        $wpdb->update($wpdb->prefix . 'dfn_bookings', ['amount_due' => 0.00], ['id' => $booking->id]);
        $booking->amount_due = 0.00;
    }

    $payment_mode_text = $is_event_free ? 'Gratuito (Ingresso Libero)' : ($booking->payment_method === 'dfn_in_loco' ? 'Contributo all\'ingresso (Botteghino)' : 'Versato Online');

    $details_table .= '<tr><td class="label">Modalità Contributo:</td><td>' . $payment_mode_text . '</td></tr>';
    if (! $is_event_free && $booking->payment_method === 'dfn_in_loco' && $booking->amount_due > 0) {
        $details_table .= '<tr><td class="label">Contributo minimo suggerito:</td><td style="font-weight:bold; color:#ff6600;">' . wc_price($booking->amount_due) . '</td></tr>';
    } elseif ($is_event_free) {
        $details_table .= '<tr><td class="label">Contributo minimo suggerito:</td><td style="font-weight:bold; color:#004b23;">Ingresso Gratuito (€0.00)</td></tr>';
    }
    if (! empty($booking->notes)) {
        $details_table .= '<tr><td class="label">Note / Richieste:</td><td style="font-style:italic; color:#475569;">' . esc_html($booking->notes) . '</td></tr>';
    }
    $details_table .= '</table>';
    $details_table .= '</div>';

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
        'url_biglietto' => esc_url($hub_url),
        'url_annullamento' => esc_url($cancel_url),
        'url_modifica' => esc_url($modify_url),
    ];

    $intro_html = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_intro'), $replacements);
    $notes_html = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_notes'), $replacements);

    $content = $intro_html;
    $content .= $details_table;
    $content .= $notes_html;

    $content .= '<p>Per accedere all\'evento, mostra all\'ingresso il codice QR del tuo gruppo cliccando sul pulsante sottostante (è sufficiente mostrare un solo codice QR per tutto il gruppo).</p>';
    $content .= '<div class="text-center"><a href="' . esc_url($hub_url) . '" class="button">Mostra Codice QR / Ingressi</a></div>';

    if ($booking->payment_method === 'dfn_in_loco') {
        $content .= '<p style="font-size: 14px; color: #4a5568;"><em>Nota: Avendo scelto il contributo all\'ingresso, ti chiediamo di arrivare circa 10 minuti prima dell\'orario indicato per agevolare la ricezione del contributo presso il botteghino.</em></p>';
    }

    $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Devi modificare il numero di partecipanti? <a href="' . esc_url($modify_url) . '" style="color: #004b23; text-decoration: underline; font-weight: bold;">Modifica la prenotazione qui</a></p>';
    $content .= '<p style="text-align: center; margin-top: 10px; font-size: 13px; color: #718096;">Non puoi più partecipare affatto? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_title'), $replacements);

    $context_tag = "[Ordine #" . ($booking->order_id ?: 'N/D') . "] [Booking #{$booking_id}]";
    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content, [], $context_tag);
}

/**
 * Invia email di notifica "In Attesa di Approvazione" (workflow manuale).
 *
 * @param int $booking_id ID del booking.
 * @return bool
 */
function dfn_send_booking_pending_approval(int $booking_id)
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $product_name = get_the_title($event->product_id);

    $details_table = '<div class="info-box">';
    $details_table .= '<div class="info-box-title">Dettagli della Richiesta</div>';
    $details_table .= '<table>';
    $details_table .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td class="label">Stato:</td><td style="font-weight:bold; color:#e74f30;">In Attesa di Approvazione Staff</td></tr>';
    $details_table .= '<tr><td class="label">Partecipanti:</td><td>' . absint($booking->total_persons) . ' totali</td></tr>';
    if (! empty($booking->notes)) {
        $details_table .= '<tr><td class="label">Note / Richieste:</td><td style="font-style:italic; color:#475569;">' . esc_html($booking->notes) . '</td></tr>';
    }
    $details_table .= '</table>';
    $details_table .= '</div>';

    $auto_cancel_hours = isset($event->auto_cancel_hours) ? (int) $event->auto_cancel_hours : 24;
    $pay_mode = isset($event->payment_mode) ? $event->payment_mode : 'online';
    $is_online = ($pay_mode !== 'in_loco' && $pay_mode !== 'gratuito');

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
        'ore_scadenza' => (string) $auto_cancel_hours,
    ];

    $body_template = dfn_get_setting('email_pending_body');
    $content = dfn_replace_email_placeholders($body_template, $replacements);

    if (strpos($body_template, '{dettagli_prenotazione}') === false) {
        $content .= $details_table;
    }

    if ($is_online && $auto_cancel_hours > 0) {
        $content .= '<div class="info-box" style="background:#fffbeb; border: 1.5px solid #fde68a; border-left: 4px solid #d97706; padding: 14px 18px; margin: 20px 0; border-radius: 8px;">';
        $content .= '<strong style="color: #b45309; font-size: 14px; display: block; margin-bottom: 4px;">⏱️ Termine per il versamento del contributo:</strong>';
        $content .= '<p style="margin: 0; font-size: 13.5px; color: #92400e; line-height: 1.5;">' . sprintf(
            esc_html__('I tuoi posti sono temporaneamente riservati. Non appena la richiesta sarà approvata dallo staff, riceverai un\'email con il link per effettuare il pagamento online: avrai a disposizione un massimo di %d ore dall\'invio del link per completare il contributo prima che la prenotazione scada e i posti vengano automaticamente liberati.', 'dfn-theme'),
            $auto_cancel_hours
        ) . '</p>';
        $content .= '</div>';
    } else {
        $content .= '<p>Non è ancora necessario versare alcun contributo o mostrare QR code. Riceverai un secondo messaggio con l\'esito della richiesta.</p>';
    }

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_pending_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_pending_title'), $replacements);

    $context_tag = "[Ordine #" . ($booking->order_id ?: 'N/D') . "] [Booking #{$booking_id}]";
    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content, [], $context_tag);
}

/**
 * Inserisce un avviso sul termine di pagamento (in ore) nelle email WooCommerce di pagamento in sospeso (es. Customer Invoice).
 */
add_action('woocommerce_email_before_order_table', 'dfn_email_payment_deadline_notice', 10, 4);
function dfn_email_payment_deadline_notice($order, $sent_to_admin, $plain_text, $email)
{
    if ($sent_to_admin || ! $order) {
        return;
    }

    if (! $order->has_status(['pending', 'on-hold', 'pending_approval'])) {
        return;
    }

    $email_id = $email ? $email->id : '';
    if (! in_array($email_id, ['customer_invoice', 'customer_pending_order'], true)) {
        return;
    }

    $booking = function_exists('dfn_db_get_booking_by_order') ? dfn_db_get_booking_by_order($order->get_id()) : null;
    $auto_cancel_hours = 24;

    if ($booking && function_exists('dfn_db_get_event')) {
        $event = dfn_db_get_event((int) $booking->event_id);
        if ($event && isset($event->auto_cancel_hours)) {
            $auto_cancel_hours = (int) $event->auto_cancel_hours;
        }
    } else {
        $auto_cancel_hours = (int) dfn_get_setting('cron_timeout_no_booking', 24);
    }

    if ($auto_cancel_hours <= 0) {
        return;
    }

    if ($plain_text) {
        echo "\n" . sprintf(__('⏱️ NOTA BENE: I posti rimarranno riservati per un massimo di %d ore dall\'invio di questa email. Ti invitiamo a completare il pagamento tramite il link sottostante entro tale termine per confermare definitivamente la tua prenotazione.', 'dfn-theme'), $auto_cancel_hours) . "\n\n";
    } else {
        echo '<div style="background-color: #fffbeb; border: 1.5px solid #fde68a; border-left: 5px solid #d97706; padding: 14px 18px; margin: 20px 0; border-radius: 8px;">';
        echo '<strong style="color: #b45309; font-size: 14px; display: block; margin-bottom: 4px;">⏱️ Termine per il versamento del contributo</strong>';
        echo '<p style="margin: 0; font-size: 13.5px; color: #92400e; line-height: 1.5;">' . sprintf(
            esc_html__('I posti rimarranno riservati per un periodo massimo di %d ore. Ti invitiamo a completare il pagamento tramite il pulsante verde di pagamento entro tale termine per garantire e confermare definitivamente la tua partecipazione prima dell\'annullamento automatico.', 'dfn-theme'),
            $auto_cancel_hours
        ) . '</p>';
        echo '</div>';
    }
}

/**
 * Invia email di aggiornamento sullo stato di approvazione (Approvata o Rifiutata).
 *
 * @param int  $booking_id ID del booking.
 * @param bool $approved   True se approvato, false se rifiutato/annullato.
 * @return bool
 */
function dfn_send_booking_approval_status(int $booking_id, bool $approved = true)
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $product_name = get_the_title($event->product_id);

    if ($approved) {
        // Se approvato, invia direttamente la conferma classica che include dettagli e QR
        return dfn_send_booking_confirmation($booking_id);
    } else {
        // Se il booking rifiutato era in pending_approval (Tessere FAI non verificate), usiamo i nuovi template specifici
        $is_fai_pending = ($booking->status === 'pending_approval');

        $subj_setting = $is_fai_pending ? 'email_fai_booking_rejected_subject' : 'email_declined_subject';
        $title_setting = $is_fai_pending ? 'email_fai_booking_rejected_title' : 'email_declined_title';
        $body_setting = $is_fai_pending ? 'email_fai_booking_rejected_body' : 'email_declined_body';

        $body_template = dfn_get_setting($body_setting);
        $has_motivo_placeholder = (strpos($body_template, '{motivo_rifiuto}') !== false);

        $formatted_motivo = '';
        if (! empty($booking->notes)) {
            $formatted_motivo = '<div class="info-box" style="border-left: 4px solid ' . esc_attr(dfn_get_setting('email_accent_color', '#e74f30')) . '; background-color: #f7fafc; padding: 18px 20px; margin: 25px 0; border-radius: 0 6px 6px 0;">';
            $formatted_motivo .= '<div class="info-box-title" style="font-weight: bold; font-size: 15px; color: ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; margin-bottom: 8px;">Nota dallo Staff</div>';
            $formatted_motivo .= '<p style="margin: 0; font-size: 14px; color: #2d3748; line-height: 1.5;">' . esc_html($booking->notes) . '</p>';
            $formatted_motivo .= '</div>';
        }

        $replacements = [
            'nome_cliente'   => esc_html($booking->customer_name),
            'nome_evento'    => esc_html($product_name),
            'motivo_rifiuto' => $formatted_motivo ? $formatted_motivo : esc_html($booking->notes),
        ];

        $content = dfn_replace_email_placeholders($body_template, $replacements);

        if (! $has_motivo_placeholder && ! empty($booking->notes)) {
            $content .= $formatted_motivo;
        }

        if (! $is_fai_pending) {
            $content .= '<p>I posti precedentemente riservati sono stati liberati e resi nuovamente disponibili.</p>';
        }

        $subject = dfn_replace_email_placeholders(dfn_get_setting($subj_setting), $replacements);
        $title   = dfn_replace_email_placeholders(dfn_get_setting($title_setting), $replacements);

        return dfn_send_notification_email($booking->customer_email, $subject, $title, $content);
    }
}

/**
 * Invia email di notifica cancellazione prenotazione.
 *
 * @param int $booking_id ID della prenotazione.
 * @return bool
 */
function dfn_send_booking_cancellation(int $booking_id)
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $product_name = get_the_title($event->product_id);

    $details_table = '<div class="info-box">';
    $details_table .= '<div class="info-box-title">Riepilogo Annullamento</div>';
    $details_table .= '<table>';
    $details_table .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td class="label">Data Prenotata:</td><td>' . date_i18n('d F Y', strtotime($event->event_date_start)) . '</td></tr>';
    $details_table .= '<tr><td class="label">Stato:</td><td style="font-weight:bold; color:#e53e3e;">ANNULLATA</td></tr>';
    $details_table .= '</table>';
    $details_table .= '</div>';

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
    ];

    $body_template = dfn_get_setting('email_cancelled_body');
    $content = dfn_replace_email_placeholders($body_template, $replacements);

    if (strpos($body_template, '{dettagli_prenotazione}') === false) {
        $content .= $details_table;
    }

    $content .= '<p>Speriamo di poterti accogliere in uno dei nostri prossimi eventi FAI.</p>';

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_cancelled_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_cancelled_title'), $replacements);

    $context_tag = "[Ordine #" . ($booking->order_id ?: 'N/D') . "] [Booking #{$booking_id}]";
    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content, [], $context_tag);
}

/**
 * Invia email di notifica cancellazione prenotazione da parte dell'amministratore/staff.
 *
 * Utilizzata quando lo staff cancella manualmente una prenotazione dal pannello
 * "Gestione Turni". Il testo è differente rispetto alla cancellazione autonoma
 * del visitatore e da quella per scadenza automatica.
 *
 * @param int $booking_id ID della prenotazione.
 * @return bool
 */
function dfn_send_booking_admin_cancellation(int $booking_id): bool
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $product_name = get_the_title($event->product_id);

    $details_table = '<div class="info-box">';
    $details_table .= '<div class="info-box-title">Riepilogo Annullamento</div>';
    $details_table .= '<table>';
    $details_table .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td class="label">Data Prenotata:</td><td>' . date_i18n('d F Y', strtotime($event->event_date_start)) . '</td></tr>';
    $details_table .= '<tr><td class="label">Partecipanti:</td><td>' . absint($booking->total_persons) . ' totali</td></tr>';
    $details_table .= '<tr><td class="label">Stato:</td><td style="font-weight:bold; color:#e53e3e;">ANNULLATA DALLO STAFF</td></tr>';
    $details_table .= '</table>';
    $details_table .= '</div>';

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
    ];

    $body_template = dfn_get_setting('email_admin_cancelled_body');
    $content = dfn_replace_email_placeholders($body_template, $replacements);

    if (strpos($body_template, '{dettagli_prenotazione}') === false) {
        $content .= $details_table;
    }

    if ($booking->payment_method !== 'dfn_in_loco' && (float) $booking->amount_paid > 0) {
        $content .= '<p>I posti precedentemente riservati sono stati liberati e resi nuovamente disponibili.</p>';
    }

    $content .= '<p>Speriamo di poterti accogliere in uno dei nostri prossimi eventi FAI.</p>';

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_admin_cancelled_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_admin_cancelled_title'), $replacements);

    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content);
}

/**
 * Invia email di promemoria 24 ore prima dell'inizio dell'evento.
 *
 * @param int $booking_id ID del booking.
 * @return bool
 */
function dfn_send_booking_24h_reminder(int $booking_id)
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking || $booking->status !== 'confirmed') {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        return false;
    }

    // Recupera informazioni sullo slot
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, bs.persons FROM {$wpdb->prefix}dfn_event_slots s 
         JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id 
         WHERE bs.booking_id = %d",
        $booking_id,
    ));

    $slot_info = '';
    if (! empty($slots)) {
        if (count($slots) === 1) {
            $slot = $slots[0];
            $slot_info = date_i18n('d F Y', strtotime($slot->slot_date)) . ' - ore ' . date('H:i', strtotime($slot->slot_time_start));
        } else {
            $slot_info_parts = [];
            foreach ($slots as $s) {
                $slot_info_parts[] = 'ore ' . date('H:i', strtotime($s->slot_time_start)) . ' (' . absint($s->persons) . ' ' . ($s->persons == 1 ? 'persona' : 'persone') . ')';
            }
            $slot_info = date_i18n('d F Y', strtotime($slots[0]->slot_date)) . ' — ' . implode(', ', $slot_info_parts);
        }
    } else {
        $slot_info = date_i18n('d F Y', strtotime($event->event_date_start)) . ' (Ingresso Libero)';
    }

    $product_name = get_the_title($event->product_id);

    // Link all'hub biglietti / QR effettivo
    $token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
    $hub_url = add_query_arg([
        'dfn_hub'  => 1,
        'order_id' => $booking->order_id,
        'token'    => $token,
    ], home_url('/'));

    // Link di cancellazione
    $cancel_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
    $cancel_url = add_query_arg([
        'dfn_cancel_booking' => 1,
        'order_id'           => $booking->order_id,
        'token'              => $cancel_token,
    ], home_url('/'));

    $details_table = '<div class="info-box">';
    $details_table .= '<div class="info-box-title">Dettagli per Domani</div>';
    $details_table .= '<table>';
    $details_table .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td class="label">Data e Inizio Visita:</td><td><strong>' . esc_html($slot_info) . '</strong></td></tr>';
    $details_table .= '<tr><td class="label">Luogo di Ritrovo:</td><td>' . esc_html($event->location) . '</td></tr>';
    if ($booking->payment_method === 'dfn_in_loco' && $booking->amount_due > 0) {
        $details_table .= '<tr><td class="label">Contributo minimo suggerito:</td><td style="font-weight:bold; color:#ff6600;">' . wc_price($booking->amount_due) . ' (Cassa Live)</td></tr>';
    }
    $details_table .= '</table>';
    $details_table .= '</div>';

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
        'url_biglietto' => esc_url($hub_url),
        'url_annullamento' => esc_url($cancel_url),
    ];

    $intro_html = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_intro'), $replacements);
    $notes_html = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_notes'), $replacements);

    $content = $intro_html;
    $content .= $details_table;
    $content .= $notes_html;

    if ($booking->payment_method === 'dfn_in_loco') {
        $content .= '<p style="font-size: 14px; color: #4a5568;"><em>Nota: Avendo optato per il contributo all\'ingresso, ti chiediamo di presentarti con qualche minuto di anticipo al fine di evitare code e velocizzare il check-in.</em></p>';
    }

    $content .= '<div class="text-center"><a href="' . esc_url($hub_url) . '" class="button">Apri Prenotazione con Codice QR</a></div>';

    $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Non puoi più partecipare? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_title'), $replacements);

    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content);
}

/**
 * Invia email a un utente in lista d'attesa quando si libera un posto.
 * Include un link prioritario con validità di 2 ore (TTL).
 *
 * @param int $waitlist_id ID della voce waitlist.
 * @return bool
 */
function dfn_send_waitlist_notification(int $waitlist_id)
{
    global $wpdb;
    $waitlist = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_waitlist WHERE id = %d", $waitlist_id));
    if (! $waitlist || $waitlist->status !== 'notified') {
        return false;
    }

    $event = dfn_db_get_event($waitlist->event_id);
    if (! $event) {
        return false;
    }

    $product_name = get_the_title($event->product_id);

    $hash = wp_hash($waitlist->id . '|' . $waitlist->customer_email . '|' . $waitlist->ttl_expires_at);
    $checkout_url = add_query_arg([
        'add-to-cart' => $event->product_id,
        'quantity'    => $waitlist->persons,
        'dfn_wl_id'   => $waitlist->id,
        'dfn_wl_hash' => $hash,
    ], wc_get_checkout_url());

    $waitlist_ttl = intval(dfn_get_setting('cron_waitlist_ttl', 2));

    $details_table = '<div class="info-box">';
    $details_table .= '<div class="info-box-title">La tua Prenotazione Riservata</div>';
    $details_table .= '<table>';
    $details_table .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td class="label">Posti Riservati:</td><td>' . absint($waitlist->persons) . '</td></tr>';
    $details_table .= '<tr><td class="label">Scadenza Priorità:</td><td style="color:#e53e3e; font-weight:bold;">' . date('H:i', strtotime($waitlist->ttl_expires_at)) . ' di oggi</td></tr>';
    $details_table .= '</table>';
    $details_table .= '</div>';

    $replacements = [
        'nome_cliente' => esc_html($waitlist->customer_name),
        'nome_evento'  => esc_html($product_name),
        'ore_waitlist' => $waitlist_ttl,
        'dettagli_prenotazione' => $details_table,
    ];

    $body_template = dfn_get_setting('email_waitlist_body');
    $content = dfn_replace_email_placeholders($body_template, $replacements);

    if (strpos($body_template, '{dettagli_prenotazione}') === false) {
        $content .= $details_table;
    }

    $content .= '<p>Clicca sul pulsante sottostante per accedere direttamente al checkout veloce e confermare subito la tua presenza:</p>';
    $content .= '<div class="text-center"><a href="' . esc_url($checkout_url) . '" class="button">Completa la Prenotazione Ora</a></div>';

    $content .= '<p style="font-size: 13px; color: #718096;"><em>Se non completerai la prenotazione entro le ore ' . date('H:i', strtotime($waitlist->ttl_expires_at)) . ', il sistema annullerà automaticamente la tua prenotazione riservata e sbloccherà lo slot per il prossimo utente in attesa.</em></p>';

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_waitlist_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_waitlist_title'), $replacements);

    return dfn_send_notification_email($waitlist->customer_email, $subject, $title, $content);
}

/**
 * Invia email di notifica quando una tessera FAI viene approvata/verificata manualmente dallo staff.
 *
 * @param string $email       Email del socio.
 * @param string $first_name  Nome.
 * @param string $last_name   Cognome.
 * @param string $card_number Numero tessera.
 * @return bool
 */
function dfn_send_fai_card_approved_email(string $email, string $first_name, string $last_name, string $card_number): bool
{
    $subject = dfn_replace_email_placeholders(
        dfn_get_setting('email_fai_approved_subject'),
        [
            'nome_cliente' => esc_html($first_name . ' ' . $last_name),
            'numero_tessera' => esc_html($card_number),
        ]
    );

    $title = dfn_replace_email_placeholders(
        dfn_get_setting('email_fai_approved_title'),
        [
            'nome_cliente' => esc_html($first_name . ' ' . $last_name),
            'numero_tessera' => esc_html($card_number),
        ]
    );

    $content = dfn_replace_email_placeholders(
        dfn_get_setting('email_fai_approved_body'),
        [
            'nome_cliente' => esc_html($first_name . ' ' . $last_name),
            'numero_tessera' => esc_html($card_number),
        ]
    );

    $context_tag = "[Tessera #{$card_number}]";
    return dfn_send_notification_email($email, $subject, $title, $content, [], $context_tag);
}

/**
 * Invia email di notifica quando una tessera FAI risulta non valida/rifiutata.
 *
 * @param string $email       Email del socio.
 * @param string $first_name  Nome.
 * @param string $last_name   Cognome.
 * @param string $card_number Numero tessera.
 * @param string $reason      Motivazione del rifiuto.
 * @return bool
 */
function dfn_send_fai_card_rejected_email(string $email, string $first_name, string $last_name, string $card_number, string $reason): bool
{
    $subject = dfn_replace_email_placeholders(
        dfn_get_setting('email_fai_rejected_subject'),
        [
            'nome_cliente' => esc_html($first_name . ' ' . $last_name),
            'numero_tessera' => esc_html($card_number),
            'motivo_rifiuto' => esc_html($reason),
        ]
    );

    $title = dfn_replace_email_placeholders(
        dfn_get_setting('email_fai_rejected_title'),
        [
            'nome_cliente' => esc_html($first_name . ' ' . $last_name),
            'numero_tessera' => esc_html($card_number),
            'motivo_rifiuto' => esc_html($reason),
        ]
    );

    $body_template = dfn_get_setting('email_fai_rejected_body');
    $has_motivo_placeholder = (strpos($body_template, '{motivo_rifiuto}') !== false);

    $replacements = [
        'nome_cliente' => esc_html($first_name . ' ' . $last_name),
        'numero_tessera' => esc_html($card_number),
    ];
    if ($has_motivo_placeholder) {
        $replacements['motivo_rifiuto'] = esc_html($reason);
    }

    $content = dfn_replace_email_placeholders($body_template, $replacements);

    if (!$has_motivo_placeholder) {
        $content .= '<div class="info-box" style="border-left: 4px solid #e53e3e; background: #fff5f5; padding: 15px; margin: 15px 0;">';
        $content .= '<div class="info-box-title" style="color: #e53e3e; font-weight: bold; margin-bottom: 5px;">Motivazione dello Staff</div>';
        $content .= '<p style="margin:0; font-size:14px; color: #c53030;">' . esc_html($reason) . '</p>';
        $content .= '</div>';
    }

    $context_tag = "[Tessera #{$card_number}]";
    return dfn_send_notification_email($email, $subject, $title, $content, [], $context_tag);
}

/**
 * Invia email di notifica all'amministratore per una nuova prenotazione.
 *
 * @param int $booking_id ID del booking.
 * @return bool
 */
function dfn_send_admin_new_booking_notification(int $booking_id)
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    // Recupera informazioni sullo slot
    $slot_info = '';
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, bs.persons FROM {$wpdb->prefix}dfn_event_slots s 
         JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id 
         WHERE bs.booking_id = %d",
        $booking_id,
    ));

    if (! empty($slots)) {
        if (count($slots) === 1) {
            $slot = $slots[0];
            $slot_info = date_i18n('d F Y', strtotime($slot->slot_date)) . ' - ore ' . date('H:i', strtotime($slot->slot_time_start));
        } else {
            $slot_info_parts = [];
            foreach ($slots as $s) {
                $slot_info_parts[] = 'ore ' . date('H:i', strtotime($s->slot_time_start)) . ' (' . absint($s->persons) . ' ' . ($s->persons == 1 ? 'persona' : 'persone') . ')';
            }
            $slot_info = date_i18n('d F Y', strtotime($slots[0]->slot_date)) . ' — ' . implode(', ', $slot_info_parts);
        }
    } else {
        $slot_info = date_i18n('d F Y', strtotime($event->event_date_start)) . ' (Ingresso Libero)';
    }

    $product_name = get_the_title($event->product_id);
    $subject = 'Nuova Prenotazione: ' . $booking->customer_name . ' - ' . $product_name;

    // Se la notifica admin è disabilitata, non inviamo l'email
    if (dfn_get_setting('enable_admin_notification', 'yes') !== 'yes') {
        return true;
    }

    $admin_email = dfn_get_setting('email_new_booking', get_option('admin_email'));
    if (! empty($event->is_test_event) && ! empty($event->test_notification_email)) {
        $admin_email = $event->test_notification_email;
        $subject = '🧪 [EVENTO TEST] ' . $subject;
    }

    $content = '<p>Gentile Amministratore,</p>';
    $content .= '<p>Ti notifichiamo che è stata registrata una nuova prenotazione per l\'evento <strong>' . esc_html($product_name) . '</strong>.</p>';

    $content .= '<div class="info-box" style="border-left: 4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color: #f7fafc;">';
    $content .= '<div class="info-box-title" style="color: ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . ';">Dettagli Visitatore</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label" style="font-weight:bold; color:#4a5568; width:140px;">Nome:</td><td>' . esc_html($booking->customer_name) . '</td></tr>';
    $content .= '<tr><td class="label" style="font-weight:bold; color:#4a5568; width:140px;">Email:</td><td>' . esc_html($booking->customer_email) . '</td></tr>';
    $content .= '<tr><td class="label" style="font-weight:bold; color:#4a5568; width:140px;">Telefono:</td><td>' . esc_html($booking->customer_phone) . '</td></tr>';
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<div class="info-box">';
    $content .= '<div class="info-box-title">Dettagli della Prenotazione</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td class="label">Data e Turno:</td><td>' . esc_html($slot_info) . '</td></tr>';
    $content .= '<tr><td class="label">Ingressi:</td><td><strong>' . absint($booking->total_persons) . '</strong> totali (' . absint($booking->persons_standard) . ' Intero Standard + ' . absint($booking->persons_fai) . ' Ridotto Socio FAI)</td></tr>';
    $is_event_free = ($event && (
        (floatval($event->price_standard) === 0.00 && floatval($event->price_fai) === 0.00) ||
        ($event->pricing_type ?? '') === 'free' ||
        ! empty($event->is_free)
    ));
    if ($is_event_free && floatval($booking->amount_due) > 0) {
        $wpdb->update($wpdb->prefix . 'dfn_bookings', ['amount_due' => 0.00], ['id' => $booking->id]);
        $booking->amount_due = 0.00;
    }

    $payment_mode_text = $is_event_free ? 'Gratuito (Ingresso Libero)' : ($booking->payment_method === 'dfn_in_loco' ? 'Contributo all\'ingresso (Botteghino)' : 'Versato Online');

    $content .= '<tr><td class="label">Modalità Contributo:</td><td>' . $payment_mode_text . '</td></tr>';
    $content .= '<tr><td class="label">Contributo:</td><td>' . ($is_event_free ? 'Ingresso Gratuito (€0.00)' : wc_price($booking->payment_method === 'dfn_in_loco' ? $booking->amount_due : $booking->amount_paid)) . '</td></tr>';
    if (! empty($booking->notes)) {
        $content .= '<tr><td class="label">Note:</td><td>' . esc_html($booking->notes) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    // Aggiungi link per visualizzare l'ordine nell'admin di WordPress
    $order_url = admin_url('post.php?post=' . $booking->order_id . '&action=edit');
    $content .= '<div class="text-center"><a href="' . esc_url($order_url) . '" class="button">Visualizza Ordine in WordPress</a></div>';

    $context_tag = "[Ordine #" . ($booking->order_id ?: 'N/D') . "] [Booking #{$booking_id}]";
    return dfn_send_notification_email($admin_email, $subject, 'Notifica Nuova Prenotazione', $content, [], $context_tag);
}

/**
 * Invia una notifica all'amministratore per una tessera FAI che richiede verifica.
 *
 * @param string $card_number Numero della tessera.
 * @param string $first_name  Nome del titolare.
 * @param string $last_name   Cognome del titolare.
 * @param string $email       Email del titolare.
 * @return bool
 */
function dfn_notify_admin_unverified_fai_card($card_number, $first_name, $last_name, $email = '')
{
    $to = dfn_get_setting('email_verify_fai', get_option('admin_email'));
    $subject = 'Tessera FAI da Verificare: ' . $card_number;

    $content = '<p>Gentile Amministratore,</p>';
    $content .= '<p>È stata inserita nel sistema una nuova tessera FAI che richiede la <strong>verifica manuale</strong> dello stato di iscrizione.</p>';
    $content .= '<div class="info-box" style="border-left: 4px solid ' . esc_attr(dfn_get_setting('email_accent_color', '#e74f30')) . '; background-color: #f7fafc;">';
    $content .= '<div class="info-box-title" style="color: ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . ';">Dettagli Tessera</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Numero Tessera:</td><td><strong>' . esc_html($card_number) . '</strong></td></tr>';
    $content .= '<tr><td class="label">Titolare:</td><td>' . esc_html($first_name . ' ' . $last_name) . '</td></tr>';
    if (! empty($email)) {
        $content .= '<tr><td class="label">Email:</td><td>' . esc_html($email) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    $admin_url = admin_url('admin.php?page=dfn-fai-members');
    $content .= '<p>Puoi approvare o modificare la tessera direttamente nella sezione anagrafica.</p>';
    $content .= '<div class="text-center"><a href="' . esc_url($admin_url) . '" class="button">Gestisci Soci FAI</a></div>';

    return dfn_send_notification_email($to, $subject, 'Verifica Tessera FAI', $content);
}

/**
 * Invia una notifica all'amministratore per una nuova prenotazione FAI in attesa di verifica tessere.
 * Contiene riepilogo completo della prenotazione + elenco tessere da verificare +
 * link CTA diretto alla sezione admin "Verifica Prenotazioni FAI".
 *
 * @param int $booking_id ID del booking.
 * @return bool
 */
function dfn_send_admin_fai_booking_pending_notification(int $booking_id): bool
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        return false;
    }

    // Recupera informazioni sullo slot
    $slot_info = '';
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, bs.persons FROM {$wpdb->prefix}dfn_event_slots s 
         JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id 
         WHERE bs.booking_id = %d",
        $booking_id,
    ));

    if (! empty($slots)) {
        if (count($slots) === 1) {
            $slot = $slots[0];
            $slot_info = date_i18n('d F Y', strtotime($slot->slot_date)) . ' - ore ' . date('H:i', strtotime($slot->slot_time_start));
        } else {
            $slot_info_parts = [];
            foreach ($slots as $s) {
                $slot_info_parts[] = 'ore ' . date('H:i', strtotime($s->slot_time_start)) . ' (' . absint($s->persons) . ' pers.)';
            }
            $slot_info = date_i18n('d F Y', strtotime($slots[0]->slot_date)) . ' — ' . implode(', ', $slot_info_parts);
        }
    } else {
        $slot_info = date_i18n('d F Y', strtotime($event->event_date_start)) . ' (Ingresso Libero)';
    }

    $product_name = get_the_title($event->product_id);
    $admin_email  = dfn_get_setting('email_verify_fai', get_option('admin_email'));
    $subject      = '🔍 [FAI] Prenotazione da Verificare: ' . $booking->customer_name . ' — ' . $product_name;

    // Box dati visitatore
    $content = '<p>Gentile Staff della Delegazione FAI,</p>';
    $content .= '<p>È stata ricevuta una nuova prenotazione che include <strong>tessere FAI da verificare</strong>. La prenotazione è al momento in stato <strong style="color:#e74f30;">In Attesa di Verifica</strong>. I posti sono stati riservati temporaneamente.</p>';

    $content .= '<div class="info-box" style="border-left: 4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color: #f7fafc; padding: 20px; margin: 20px 0;">';
    $content .= '<div class="info-box-title" style="color: ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight: bold; margin-bottom: 10px;">Dati del Visitatore</div>';
    $content .= '<table style="width:100%; border-collapse:collapse;">';
    $content .= '<tr><td style="font-weight:bold; color:#4a5568; width:150px; padding:4px 0;">Nome:</td><td>' . esc_html($booking->customer_name) . '</td></tr>';
    $content .= '<tr><td style="font-weight:bold; color:#4a5568; padding:4px 0;">Email:</td><td>' . esc_html($booking->customer_email) . '</td></tr>';
    if (! empty($booking->customer_phone)) {
        $content .= '<tr><td style="font-weight:bold; color:#4a5568; padding:4px 0;">Telefono:</td><td>' . esc_html($booking->customer_phone) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    // Box dettagli prenotazione
    $content .= '<div class="info-box" style="border-left: 4px solid ' . esc_attr(dfn_get_setting('email_accent_color', '#e74f30')) . '; background-color: #fffdf0; padding: 20px; margin: 20px 0;">';
    $content .= '<div class="info-box-title" style="color: ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight: bold; margin-bottom: 10px;">Dettagli Prenotazione</div>';
    $content .= '<table style="width:100%; border-collapse:collapse;">';
    $content .= '<tr><td style="font-weight:bold; color:#4a5568; width:150px; padding:4px 0;">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td style="font-weight:bold; color:#4a5568; padding:4px 0;">Data e Turno:</td><td>' . esc_html($slot_info) . '</td></tr>';
    $content .= '<tr><td style="font-weight:bold; color:#4a5568; padding:4px 0;">Luogo:</td><td>' . esc_html($event->location) . '</td></tr>';
    $content .= '<tr><td style="font-weight:bold; color:#4a5568; padding:4px 0;">Partecipanti:</td><td><strong>' . absint($booking->total_persons) . '</strong> totali (' . absint($booking->persons_standard) . ' Standard + ' . absint($booking->persons_fai) . ' Soci FAI)</td></tr>';
    $content .= '<tr><td style="font-weight:bold; color:#4a5568; padding:4px 0;">Contributo:</td><td style="font-weight:bold; color:#004b23;">' . wc_price(floatval($order->get_total())) . '</td></tr>';
    $content .= '</table>';
    $content .= '</div>';

    // Box tessere FAI da verificare
    $fai_cards = $order->get_meta('_dfn_fai_cards');
    if (! empty($fai_cards) && is_array($fai_cards)) {
        $unverified = [];
        $table_members = $wpdb->prefix . 'dfn_fai_members';
        foreach ($fai_cards as $card) {
            if (empty($card['tessera'])) {
                continue;
            }
            $verified = $wpdb->get_var($wpdb->prepare(
                "SELECT verified FROM {$table_members} WHERE card_number = %s LIMIT 1",
                $card['tessera']
            ));
            if (intval($verified) !== 1) {
                $unverified[] = $card;
            }
        }

        if (! empty($unverified)) {
            $content .= '<div class="info-box" style="border-left: 4px solid #e53e3e; background-color: #fff5f5; padding: 20px; margin: 20px 0;">';
            $content .= '<div class="info-box-title" style="color: #e53e3e; font-weight: bold; margin-bottom: 10px;">⚠️ Tessere FAI da Verificare (' . count($unverified) . ')</div>';
            $content .= '<table style="width:100%; border-collapse:collapse;">';
            $content .= '<tr style="background:#fee; font-size:13px;"><th style="text-align:left; padding:4px 6px;">Titolare</th><th style="text-align:left; padding:4px 6px;">N° Tessera</th></tr>';
            foreach ($unverified as $card) {
                $titolare = trim(($card['nome'] ?? '') . ' ' . ($card['cognome'] ?? ''));
                $content .= '<tr><td style="padding:4px 6px; font-size:14px;">' . esc_html($titolare) . '</td><td style="padding:4px 6px; font-size:14px; font-weight:bold;">' . esc_html($card['tessera']) . '</td></tr>';
            }
            $content .= '</table>';
            $content .= '</div>';
        }
    }

    // CTA link alla nuova sezione admin
    $verify_url = admin_url('admin.php?page=dfn-fai-pending-bookings');
    $content .= '<p>Clicca sul pulsante qui sotto per accedere direttamente alla sezione di verifica e approvare o rifiutare questa prenotazione:</p>';
    $content .= '<div class="text-center" style="text-align:center; margin: 25px 0;"><a href="' . esc_url($verify_url) . '" class="button" style="background-color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; color:#fff; padding:14px 28px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:15px;">Verifica Prenotazione FAI</a></div>';
    $content .= '<p style="font-size:13px; color:#718096; text-align:center;">Se non intervieni, la prenotazione resterà in attesa e i posti rimarranno riservati fino alla tua decisione.</p>';

    $context_tag = "[Ordine #" . ($booking->order_id ?: 'N/D') . "] [Booking #{$booking_id}]";
    return dfn_send_notification_email($admin_email, $subject, '🔍 Nuova Prenotazione FAI da Verificare', $content, [], $context_tag);
}

add_filter('woocommerce_send_email', 'dfn_prevent_dummy_email_notifications', 10, 6);
/**
 * Previene l'invio delle notifiche WooCommerce (es: Nuovo Ordine, Ordine Completato)
 * all'indirizzo email fittizio no-email@dfn.it.
 */
function dfn_prevent_dummy_email_notifications($send, $to, $subject, $message, $headers, $attachments) {
    if (empty($to)) {
        return $send;
    }
    if (is_string($to)) {
        if (strpos($to, ',') !== false) {
            $emails = array_map('trim', explode(',', $to));
            $filtered = array_filter($emails, function($email) {
                return trim(strtolower($email)) !== 'no-email@dfn.it';
            });
            if (empty($filtered)) {
                return false;
            }
        } else {
            if (trim(strtolower($to)) === 'no-email@dfn.it') {
                return false;
            }
        }
    } elseif (is_array($to)) {
        $filtered = array_filter($to, function($email) {
            return trim(strtolower($email)) !== 'no-email@dfn.it';
        });
        if (empty($filtered)) {
            return false;
        }
    }
    return $send;
}

/**
 * Invia le email di notifica modifica (una all'utente con i nuovi dati e una all'amministratore).
 *
 * @param int $booking_id ID della prenotazione.
 * @return bool
 */
function dfn_send_booking_modification_notifications(int $booking_id): bool
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        return false;
    }

    // Recupera informazioni sullo slot
    $slot_info = '';
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, bs.persons FROM {$wpdb->prefix}dfn_event_slots s 
         JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id 
         WHERE bs.booking_id = %d",
        $booking_id
    ));

    if (! empty($slots)) {
        if (count($slots) === 1) {
            $slot = $slots[0];
            $slot_info = date_i18n('d F Y', strtotime($slot->slot_date)) . ' - ore ' . date('H:i', strtotime($slot->slot_time_start));
        } else {
            $slot_info_parts = [];
            foreach ($slots as $s) {
                $slot_info_parts[] = 'ore ' . date('H:i', strtotime($s->slot_time_start)) . ' (' . absint($s->persons) . ' ' . ($s->persons == 1 ? 'persona' : 'persone') . ')';
            }
            $slot_info = date_i18n('d F Y', strtotime($slots[0]->slot_date)) . ' — ' . implode(', ', $slot_info_parts);
        }
    } else {
        $slot_info = date_i18n('d F Y', strtotime($event->event_date_start)) . ' (Ingresso Libero)';
    }

    $product_name = get_the_title($event->product_id);

    // Link all'hub biglietti / QR effettivo
    $token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
    $hub_url = add_query_arg([
        'dfn_hub'  => 1,
        'order_id' => $booking->order_id,
        'token'    => $token,
    ], home_url('/'));

    // Link di cancellazione
    $cancel_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
    $cancel_url = add_query_arg([
        'dfn_cancel_booking' => 1,
        'order_id'           => $booking->order_id,
        'token'              => $cancel_token,
    ], home_url('/'));

    // Link di modifica
    $modify_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_modify', wp_salt('nonce'));
    $modify_url = add_query_arg([
        'dfn_modify_booking' => 1,
        'order_id'           => $booking->order_id,
        'token'              => $modify_token,
    ], home_url('/'));

    $details_table = '<div class="info-box">';
    $details_table .= '<div class="info-box-title">Dettagli della Prenotazione Aggiornata</div>';
    $details_table .= '<table>';
    $details_table .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td class="label">Data e Inizio Visita:</td><td>' . esc_html($slot_info) . '</td></tr>';
    $details_table .= '<tr><td class="label">Luogo:</td><td>' . esc_html($event->location) . '</td></tr>';
    $details_table .= '<tr><td class="label">Partecipanti:</td><td>' . absint($booking->total_persons) . ' totali (' . absint($booking->persons_standard) . ' Standard + ' . absint($booking->persons_fai) . ' Soci FAI)</td></tr>';
    $is_event_free = ($event && (
        (floatval($event->price_standard) === 0.00 && floatval($event->price_fai) === 0.00) ||
        ($event->pricing_type ?? '') === 'free' ||
        ! empty($event->is_free)
    ));
    if ($is_event_free && floatval($booking->amount_due) > 0) {
        $wpdb->update($wpdb->prefix . 'dfn_bookings', ['amount_due' => 0.00], ['id' => $booking->id]);
        $booking->amount_due = 0.00;
    }

    $payment_mode_text = $is_event_free ? 'Gratuito (Ingresso Libero)' : ($booking->payment_method === 'dfn_in_loco' ? 'Contributo all\'ingresso (Botteghino)' : 'Versato Online');

    $details_table .= '<tr><td class="label">Modalità Contributo:</td><td>' . $payment_mode_text . '</td></tr>';
    if (! $is_event_free && $booking->payment_method === 'dfn_in_loco' && $booking->amount_due > 0) {
        $details_table .= '<tr><td class="label">Contributo minimo suggerito:</td><td style="font-weight:bold; color:#ff6600;">' . wc_price($booking->amount_due) . '</td></tr>';
    } elseif ($is_event_free) {
        $details_table .= '<tr><td class="label">Contributo minimo suggerito:</td><td style="font-weight:bold; color:#004b23;">Ingresso Gratuito (€0.00)</td></tr>';
    }
    $details_table .= '</table>';
    $details_table .= '</div>';

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
        'url_biglietto' => esc_url($hub_url),
        'url_annullamento' => esc_url($cancel_url),
        'url_modifica' => esc_url($modify_url),
    ];

    // --- 1. EMAIL PER L'UTENTE (MODIFICA CONFERMATA) ---
    $intro_html = dfn_replace_email_placeholders(dfn_get_setting('email_modify_intro'), $replacements);
    $notes_html = dfn_replace_email_placeholders(dfn_get_setting('email_modify_notes'), $replacements);

    $content = $intro_html;
    $content .= $details_table;
    $content .= $notes_html;

    $content .= '<p>Per accedere all\'evento, mostra all\'ingresso il codice QR del tuo gruppo cliccando sul pulsante sottostante (è sufficiente mostrare un solo codice QR per tutto il gruppo).</p>';
    $content .= '<div class="text-center"><a href="' . esc_url($hub_url) . '" class="button">Mostra Codice QR / Ingressi</a></div>';

    if ($booking->payment_method === 'dfn_in_loco') {
        $content .= '<p style="font-size: 14px; color: #4a5568;"><em>Nota: Avendo scelto il contributo all\'ingresso, ti chiediamo di arrivare circa 10 minuti prima dell\'orario indicato per agevolare la ricezione del contributo presso il botteghino.</em></p>';
    }

    $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Devi modificare ulteriormente il numero di partecipanti? <a href="' . esc_url($modify_url) . '" style="color: #004b23; text-decoration: underline; font-weight: bold;">Modifica la prenotazione qui</a></p>';
    $content .= '<p style="text-align: center; margin-top: 10px; font-size: 13px; color: #718096;">Non puoi più partecipare affatto? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_modify_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_modify_title'), $replacements);

    $sent_user = dfn_send_notification_email($booking->customer_email, $subject, $title, $content);

    // --- 2. EMAIL PER L'AMMINISTRATORE (NOTIFICA MODIFICA) ---
    $admin_email = dfn_get_setting('email_verify_fai', get_option('admin_email'));
    $admin_subject = '[Notifica FAI] Prenotazione Modificata dall\'Utente: ' . $product_name;

    if (! empty($event->is_test_event) && ! empty($event->test_notification_email)) {
        $admin_email = $event->test_notification_email;
        $admin_subject = '🧪 [EVENTO TEST] ' . $admin_subject;
    }
    
    $admin_content = '<p>Gentile Staff della Delegazione FAI,</p>';
    $admin_content .= '<p>La prenotazione di <strong>' . esc_html($booking->customer_name) . '</strong> per l\'evento <strong>' . esc_html($product_name) . '</strong> è stata modificata autonomamente dall\'utente tramite l\'area di salvagente e-mail o l\'area riservata.</p>';
    $admin_content .= $details_table;
    $admin_content .= '<p>I posti liberati sono stati reinseriti nella disponibilità dello slot.</p>';

    $sent_admin = dfn_send_notification_email($admin_email, $admin_subject, 'Notifica di Modifica', $admin_content);

    return $sent_user && $sent_admin;
}

/**
 * ========================================================================
 * SEZIONE NOTIFICHE & EMAIL MODULO VOLONTARI FAI (v2.1)
 * ========================================================================
 */

/**
 * Converte blocchi di testo semplice (separati da doppio a capo) in paragrafi HTML puliti.
 *
 * @param string $text Testo semplice inserito dall'utente.
 * @return string HTML con paragrafi stilizzati.
 */
function dfn_format_volunteer_text_paragraphs(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $paragraphs = preg_split("/\r\n\r\n|\n\n|\r\r/", $text);
    $output = '';

    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p !== '') {
            $output .= '<p style="margin:0 0 16px; font-size:15px; line-height:1.6; color:#2d3748;">' . nl2br(esc_html($p)) . '</p>';
        }
    }

    return $output;
}

/**
 * Converte linee di testo semplice (1 voce per riga) in un elenco puntato HTML FAI.
 *
 * @param string $text Testo con una voce per riga.
 * @return string HTML <ul><li>...</li></ul>
 */
function dfn_format_volunteer_bullet_list(string $text): string
{
    $lines = preg_split("/\r\n|\n|\r/", trim($text));
    $items = [];

    foreach ($lines as $line) {
        $clean = trim($line, " \t\n\r\0\x0B•-*");
        if ($clean !== '') {
            $items[] = '<li style="margin-bottom:6px;">' . esc_html($clean) . '</li>';
        }
    }

    if (empty($items)) {
        return '';
    }

    return '<ul style="margin:0; padding-left:20px; color:#334155; line-height:1.6; font-size:14.5px;">' . implode('', $items) . '</ul>';
}

/**
 * Sostituisce i segnaposto dinamici nei template email del modulo Volontari.
 *
 * @param string       $text           Testo contenente i segnaposto {tag}.
 * @param array|object $volunteer_data Dati anagrafici del volontario o candidato.
 * @param int          $user_id        ID utente WordPress collegato (opzionale).
 * @return string
 */
function dfn_replace_volunteer_email_placeholders(string $text, $volunteer_data, int $user_id = 0, string $password_reset_url = ''): string
{
    $v = is_object($volunteer_data) ? (array) $volunteer_data : (array) $volunteer_data;

    $first_name = $v['first_name'] ?? '';
    $last_name  = $v['last_name'] ?? '';
    $email      = $v['email'] ?? '';
    $phone      = ! empty($v['phone']) ? $v['phone'] : '—';
    $card_no    = ! empty($v['card_number']) ? $v['card_number'] : 'Da assegnare';

    // Recupera lo username WordPress collegato se disponibile
    $username = $email;
    if ($user_id > 0) {
        $user_obj = get_userdata($user_id);
        if ($user_obj) {
            $username = $user_obj->user_login;
        }
    } elseif (! empty($v['user_id'])) {
        $user_obj = get_userdata((int) $v['user_id']);
        if ($user_obj) {
            $username = $user_obj->user_login;
        }
    }

    // Costruzione etichetta mansioni / disponibilità
    $mansioni_list = [];
    if (! empty($v['is_guide'])) {
        $mansioni_list[] = '🏛️ Guida Culturale / Cicerone';
    }
    if (! empty($v['has_safety_course'])) {
        $mansioni_list[] = '🦺 Corso Sicurezza Attivo';
    }
    if (empty($mansioni_list)) {
        $mansioni_list[] = '👥 Volontario Operativo / Accoglienza';
    }
    $mansioni_str = implode(', ', $mansioni_list);

    $delegation_name   = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
    $delegation_footer = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_footer', 'FAI - Delegazione di Novara') : 'FAI - Delegazione di Novara';
    $created_at        = ! empty($v['created_at']) ? date_i18n('d/m/Y H:i', strtotime($v['created_at'])) : current_time('d/m/Y H:i');

    $access_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('volontari-fai') : site_url('/mio-account/volontari-fai/');
    $pwd_url    = ! empty($password_reset_url) ? $password_reset_url : $access_url;
    $admin_url  = admin_url('admin.php?page=dfn-volunteers&status=pending');

    $replacements = [
        '{nome}'                  => $first_name,
        '{cognome}'               => $last_name,
        '{email}'                 => $email,
        '{username}'              => $username,
        '{telefono}'              => $phone,
        '{tessera_fai}'           => $card_no,
        '{mansioni}'              => $mansioni_str,
        '{delegazione}'           => $delegation_name,
        '{citta}'                 => $delegation_footer,
        '{data_richiesta}'        => $created_at,
        '{link_accesso}'          => $access_url,
        '{link_imposta_password}' => $pwd_url,
        '{link_admin}'            => $admin_url,
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $text);
}

/**
 * Assembla il layout HTML completo per l'email di notifica allo staff/amministratore.
 *
 * @param array $v       Dati del candidato.
 * @param int   $user_id ID utente WP collegato.
 * @return string HTML formattato.
 */
function dfn_build_volunteer_admin_email_html(array $v, int $user_id = 0): string
{
    $intro_raw     = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_admin_intro') : '';
    $box_title_raw = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_admin_box_title', '👤 Dati e Disponibilità del Candidato') : '👤 Dati e Disponibilità del Candidato';
    $instr_raw     = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_admin_instructions') : '';
    $btn_text_raw  = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_admin_btn_text', 'Valuta Candidatura nel Pannello Admin →') : 'Valuta Candidatura nel Pannello Admin →';

    $intro_text = dfn_replace_volunteer_email_placeholders((string) $intro_raw, $v, $user_id);
    $box_title  = dfn_replace_volunteer_email_placeholders((string) $box_title_raw, $v, $user_id);
    $instr_text = dfn_replace_volunteer_email_placeholders((string) $instr_raw, $v, $user_id);
    $btn_text   = dfn_replace_volunteer_email_placeholders((string) $btn_text_raw, $v, $user_id);
    $admin_url  = admin_url('admin.php?page=dfn-volunteers&status=pending');

    $html = dfn_format_volunteer_text_paragraphs($intro_text);

    // Box riepilogo dati candidato
    $html .= '<div class="info-box" style="background-color:#f8fafc; border-left:4px solid #004b23; padding:16px 20px; margin:20px 0; border-radius:4px;">';
    if (! empty($box_title)) {
        $html .= '<p class="info-box-title" style="font-weight:700; font-size:15px; color:#004b23; margin:0 0 12px;">' . esc_html($box_title) . '</p>';
    }
    $html .= '<table style="width:100%; border-collapse:collapse; font-size:14px;">';
    $html .= '<tr><td style="padding:4px 0; font-weight:600; width:130px; color:#475569;">Candidato:</td><td style="padding:4px 0; color:#0f172a; font-weight:600;">' . esc_html(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')) . '</td></tr>';
    $html .= '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Email:</td><td style="padding:4px 0;"><a href="mailto:' . esc_attr($v['email'] ?? '') . '" style="color:#004b23; font-weight:600;">' . esc_html($v['email'] ?? '') . '</a></td></tr>';
    $html .= '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Telefono:</td><td style="padding:4px 0; color:#0f172a;">' . esc_html(! empty($v['phone']) ? $v['phone'] : '—') . '</td></tr>';

    $mansioni = [];
    if (! empty($v['is_guide'])) {
        $mansioni[] = '🏛️ Guida Culturale / Cicerone';
    }
    if (! empty($v['has_safety_course'])) {
        $mansioni[] = '🦺 Corso Sicurezza';
    }
    if (empty($mansioni)) {
        $mansioni[] = '👥 Volontario Operativo / Accoglienza';
    }
    $html .= '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Disponibilità:</td><td style="padding:4px 0; color:#0f172a;">' . esc_html(implode(', ', $mansioni)) . '</td></tr>';

    if (! empty($v['volunteer_notes'])) {
        $html .= '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Note / Competenze:</td><td style="padding:4px 0; color:#0f172a;">' . esc_html($v['volunteer_notes']) . '</td></tr>';
    }
    $created_at = ! empty($v['created_at']) ? date_i18n('d/m/Y H:i', strtotime($v['created_at'])) : current_time('d/m/Y H:i');
    $html .= '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Data invio:</td><td style="padding:4px 0; color:#0f172a;">' . esc_html($created_at) . '</td></tr>';
    $html .= '</table>';
    $html .= '</div>';

    if (! empty($instr_text)) {
        $html .= dfn_format_volunteer_text_paragraphs($instr_text);
    }

    if (! empty($btn_text)) {
        $html .= '<div style="text-align:center; margin:26px 0;"><a href="' . esc_url($admin_url) . '" class="button">' . esc_html($btn_text) . '</a></div>';
    }

    return $html;
}

/**
 * Assembla il layout HTML per l'email di ricezione/presa in carico al candidato.
 *
 * @param array $v Dati del candidato.
 * @return string HTML formattato.
 */
function dfn_build_volunteer_pending_email_html(array $v): string
{
    $intro_raw     = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_pending_intro') : '';
    $box_title_raw = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_pending_box_title', '📋 Stato della tua richiesta: In fase di verifica') : '📋 Stato della tua richiesta: In fase di verifica';
    $box_text_raw  = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_pending_box_text') : '';
    $closing_raw   = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_pending_closing') : '';
    $sig_raw       = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_pending_signature') : '';

    $intro_text   = dfn_replace_volunteer_email_placeholders((string) $intro_raw, $v);
    $box_title    = dfn_replace_volunteer_email_placeholders((string) $box_title_raw, $v);
    $box_text     = dfn_replace_volunteer_email_placeholders((string) $box_text_raw, $v);
    $closing_text = dfn_replace_volunteer_email_placeholders((string) $closing_raw, $v);
    $sig_text     = dfn_replace_volunteer_email_placeholders((string) $sig_raw, $v);

    $html = dfn_format_volunteer_text_paragraphs($intro_text);

    if (! empty($box_title) || ! empty($box_text)) {
        $html .= '<div class="info-box" style="background-color:#f8fafc; border-left:4px solid #166534; padding:16px 20px; margin:22px 0; border-radius:4px;">';
        if (! empty($box_title)) {
            $html .= '<p class="info-box-title" style="font-weight:700; font-size:15px; color:#166534; margin:0 0 8px;">' . esc_html($box_title) . '</p>';
        }
        if (! empty($box_text)) {
            $html .= '<p style="margin:0; font-size:14px; color:#334155; line-height:1.5;">' . nl2br(esc_html($box_text)) . '</p>';
        }
        $html .= '</div>';
    }

    if (! empty($closing_text)) {
        $html .= dfn_format_volunteer_text_paragraphs($closing_text);
    }

    if (! empty($sig_text)) {
        $html .= '<p style="margin-top:24px; font-size:15px; color:#2d3748; line-height:1.5;">' . nl2br(esc_html($sig_text)) . '</p>';
    }

    return $html;
}

/**
 * Assembla il layout HTML per l'email di approvazione e benvenuto al volontario.
 *
 * @param array $v       Dati del volontario approvato.
 * @param int   $user_id ID utente WP collegato.
 * @return string HTML formattato.
 */
function dfn_build_volunteer_approved_email_html(array $v, int $user_id = 0): string
{
    $intro_raw       = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_intro') : '';
    $box_title_raw   = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_box_title', '🏛️ Cosa puoi fare adesso nella tua Area Riservata?') : '🏛️ Cosa puoi fare adesso nella tua Area Riservata?';
    $box_bullets_raw = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_box_bullets') : '';
    $btn_text_raw    = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_btn_text', 'Accedi alla tua Bacheca Volontario →') : 'Accedi alla tua Bacheca Volontario →';
    $notes_raw       = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_notes') : '';
    $sig_raw         = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_signature') : '';

    $intro_text   = dfn_replace_volunteer_email_placeholders((string) $intro_raw, $v, $user_id);
    $box_title    = dfn_replace_volunteer_email_placeholders((string) $box_title_raw, $v, $user_id);
    $box_bullets  = dfn_replace_volunteer_email_placeholders((string) $box_bullets_raw, $v, $user_id);
    $btn_text     = dfn_replace_volunteer_email_placeholders((string) $btn_text_raw, $v, $user_id);
    $notes_text   = dfn_replace_volunteer_email_placeholders((string) $notes_raw, $v, $user_id);
    $sig_text     = dfn_replace_volunteer_email_placeholders((string) $sig_raw, $v, $user_id);

    $access_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('volontari-fai') : site_url('/mio-account/volontari-fai/');

    $html = dfn_format_volunteer_text_paragraphs($intro_text);

    if (! empty($box_title) || ! empty($box_bullets)) {
        $html .= '<div class="info-box" style="background-color:#f8fafc; border-left:4px solid #004b23; padding:16px 20px; margin:22px 0; border-radius:4px;">';
        if (! empty($box_title)) {
            $html .= '<p class="info-box-title" style="font-weight:700; font-size:15px; color:#004b23; margin:0 0 10px;">' . esc_html($box_title) . '</p>';
        }
        if (! empty($box_bullets)) {
            $html .= dfn_format_volunteer_bullet_list($box_bullets);
        }
        $html .= '</div>';
    }

    if (! empty($btn_text)) {
        $html .= '<div style="text-align:center; margin:28px 0;"><a href="' . esc_url($access_url) . '" class="button">' . esc_html($btn_text) . '</a></div>';
    }

    if (! empty($notes_text)) {
        $html .= '<p style="font-size:13.5px; color:#64748b; line-height:1.5;"><em>' . nl2br(esc_html($notes_text)) . '</em></p>';
    }

    if (! empty($sig_text)) {
        $html .= '<p style="margin-top:24px; font-size:15px; color:#2d3748; line-height:1.5;">' . nl2br(esc_html($sig_text)) . '</p>';
    }

    return $html;
}

/**
 * Restituisce i dati del mittente configurato per tutte le email relative ai Volontari FAI.
 * Personalizza unicamente il nome visibile del mittente (From Name), preservando
 * l'indirizzo email del server SMTP (es. noreply@dfnprenotazioni.it) per garantire la recapitabilità.
 *
 * @return array ['name' => string]
 */
function dfn_get_volunteer_email_sender(): array
{
    $delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
    $default_name    = 'Coordinamento Volontari ' . $delegation_name;

    $name = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_sender_name', $default_name) : $default_name;

    return [
        'name' => trim((string) $name),
    ];
}

/**
 * Invia una notifica allo staff/amministratore quando viene inviata una nuova registrazione volontario online.
 *
 * @param array|object $volunteer_data Dati del candidato.
 * @param int          $user_id        ID utente WP creato o collegato.
 * @param string       $override_to    Email di destinazione opzionale (usata per i test).
 * @return bool
 */
function dfn_send_volunteer_admin_notification($volunteer_data, int $user_id = 0, string $override_to = ''): bool
{
    $v = is_object($volunteer_data) ? (array) $volunteer_data : (array) $volunteer_data;

    $recipients_str = $override_to ?: (function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_admin_recipients', '') : '');
    if (empty($recipients_str)) {
        $recipients_str = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_email', get_option('admin_email')) : get_option('admin_email');
    }

    $emails = array_map('sanitize_email', array_map('trim', explode(',', $recipients_str)));
    $emails = array_filter($emails, 'is_email');
    if (empty($emails)) {
        $emails = [ get_option('admin_email') ];
    }

    $raw_subject = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_admin_subject', 'Nuova Candidatura Volontario FAI: {nome} {cognome}') : 'Nuova Candidatura Volontario FAI: {nome} {cognome}';
    $raw_title   = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_admin_title', 'Nuova Candidatura Volontario FAI') : 'Nuova Candidatura Volontario FAI';

    $subject = dfn_replace_volunteer_email_placeholders((string) $raw_subject, $v, $user_id);
    $title   = dfn_replace_volunteer_email_placeholders((string) $raw_title, $v, $user_id);
    $body    = dfn_build_volunteer_admin_email_html($v, $user_id);
    $sender  = dfn_get_volunteer_email_sender();

    return dfn_send_notification_email($emails, $subject, $title, $body, [], '[Candidatura Volontario]', $sender);
}

/**
 * Invia un'email automatica di presa in carico al candidato al momento della registrazione online.
 *
 * @param array|object $volunteer_data Dati del candidato.
 * @param string       $override_to    Email di destinazione opzionale.
 * @return bool
 */
function dfn_send_volunteer_candidate_pending_email($volunteer_data, string $override_to = ''): bool
{
    if (function_exists('dfn_get_volunteer_setting')) {
        $enabled = dfn_get_volunteer_setting('vol_enable_candidate_pending_email', 'yes');
        if ($enabled === 'no' && empty($override_to)) {
            return true;
        }
    }

    $v = is_object($volunteer_data) ? (array) $volunteer_data : (array) $volunteer_data;
    $to = $override_to ?: ($v['email'] ?? '');

    if (empty($to) || ! is_email($to)) {
        return false;
    }

    $raw_subject = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_pending_subject', 'Candidatura Volontario FAI Ricevuta - {delegazione}') : 'Candidatura Volontario FAI Ricevuta - {delegazione}';
    $raw_title   = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_pending_title', 'Grazie per la tua candidatura!') : 'Grazie per la tua candidatura!';

    $subject = dfn_replace_volunteer_email_placeholders((string) $raw_subject, $v);
    $title   = dfn_replace_volunteer_email_placeholders((string) $raw_title, $v);
    $body    = dfn_build_volunteer_pending_email_html($v);
    $sender  = dfn_get_volunteer_email_sender();

    return dfn_send_notification_email($to, $subject, $title, $body, [], '[Presa in carico Volontario]', $sender);
}

/**
 * Invia un'email di approvazione e benvenuto al volontario non appena l'amministratore approva la sua candidatura.
 *
 * @param array|object $volunteer_data Dati del volontario approvato.
 * @param int          $user_id        ID utente WordPress collegato.
 * @param string       $override_to    Email di destinazione opzionale.
 * @return bool
 */
function dfn_send_volunteer_approved_email($volunteer_data, int $user_id = 0, string $override_to = ''): bool
{
    if (function_exists('dfn_get_volunteer_setting')) {
        $enabled = dfn_get_volunteer_setting('vol_enable_approved_email', 'yes');
        if ($enabled === 'no' && empty($override_to)) {
            return true;
        }
    }

    $v = is_object($volunteer_data) ? (array) $volunteer_data : (array) $volunteer_data;
    $to = $override_to ?: ($v['email'] ?? '');

    if (empty($to) || ! is_email($to)) {
        return false;
    }

    $raw_subject = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_subject', 'Benvenuto nella Squadra Volontari del {delegazione}!') : 'Benvenuto nella Squadra Volontari del {delegazione}!';
    $raw_title   = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_approved_title', 'La tua candidatura è stata approvata!') : 'La tua candidatura è stata approvata!';

    $subject = dfn_replace_volunteer_email_placeholders((string) $raw_subject, $v, $user_id);
    $title   = dfn_replace_volunteer_email_placeholders((string) $raw_title, $v, $user_id);
    $body    = dfn_build_volunteer_approved_email_html($v, $user_id);
    $sender  = dfn_get_volunteer_email_sender();

    return dfn_send_notification_email($to, $subject, $title, $body, [], '[Approvazione Volontario]', $sender);
}

/**
 * Assembla il layout HTML per l'email di invio/reinvio credenziali e benvenuto al volontario.
 *
 * @param array|object $volunteer_data     Dati anagrafici del volontario.
 * @param WP_User|null $user               Istanza utente WordPress.
 * @param string       $password_reset_url URL per impostare o reimpostare la password.
 * @return string HTML formattato.
 */
function dfn_build_volunteer_credentials_email_html($volunteer_data, $user = null, string $password_reset_url = ''): string
{
    $v       = is_object($volunteer_data) ? (array) $volunteer_data : (array) $volunteer_data;
    $user_id = ($user instanceof \WP_User) ? $user->ID : (int) ($v['user_id'] ?? 0);

    $intro_raw        = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_intro') : '';
    $box_title_raw    = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_box_title', '🏛️ Le tue Credenziali di Accesso') : '🏛️ Le tue Credenziali di Accesso';
    $btn_text_raw     = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_btn_text', '🔐 Imposta la tua Password ed Accedi →') : '🔐 Imposta la tua Password ed Accedi →';
    $info_title_raw   = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_info_title', '✨ Cosa puoi fare nella tua Area Riservata?') : '✨ Cosa puoi fare nella tua Area Riservata?';
    $info_bullets_raw = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_info_bullets') : '';
    $notes_raw        = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_notes') : '';
    $sig_raw          = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_signature') : '';

    $intro_text   = dfn_replace_volunteer_email_placeholders((string) $intro_raw, $v, $user_id, $password_reset_url);
    $box_title    = dfn_replace_volunteer_email_placeholders((string) $box_title_raw, $v, $user_id, $password_reset_url);
    $btn_text     = dfn_replace_volunteer_email_placeholders((string) $btn_text_raw, $v, $user_id, $password_reset_url);
    $info_title   = dfn_replace_volunteer_email_placeholders((string) $info_title_raw, $v, $user_id, $password_reset_url);
    $info_bullets = dfn_replace_volunteer_email_placeholders((string) $info_bullets_raw, $v, $user_id, $password_reset_url);
    $notes_text   = dfn_replace_volunteer_email_placeholders((string) $notes_raw, $v, $user_id, $password_reset_url);
    $sig_text     = dfn_replace_volunteer_email_placeholders((string) $sig_raw, $v, $user_id, $password_reset_url);

    $access_url   = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('volontari-fai') : site_url('/mio-account/volontari-fai/');
    $cta_url      = ! empty($password_reset_url) ? $password_reset_url : $access_url;
    $username     = ($user instanceof \WP_User) ? $user->user_login : ($v['email'] ?? '');
    $email_addr   = $v['email'] ?? '';

    $html = dfn_format_volunteer_text_paragraphs($intro_text);

    // Box Credenziali in evidenza (Design System FAI)
    $html .= '<div class="info-box" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #004b23; padding:18px 22px; margin:24px 0; border-radius:6px;">';
    if (! empty($box_title)) {
        $html .= '<p class="info-box-title" style="font-weight:700; font-size:15.5px; color:#004b23; margin:0 0 12px;">' . esc_html($box_title) . '</p>';
    }
    $html .= '<table style="width:100%; border-collapse:collapse; font-size:14px;">';
    $html .= '<tr><td style="padding:6px 0; font-weight:600; width:140px; color:#475569;">👤 Nome Utente:</td><td style="padding:6px 0; color:#0f172a; font-weight:700;"><code style="background:#e2e8f0; padding:2px 8px; border-radius:4px; font-size:13.5px;">' . esc_html($username) . '</code></td></tr>';
    $html .= '<tr><td style="padding:6px 0; font-weight:600; color:#475569;">📧 Email:</td><td style="padding:6px 0; color:#0f172a; font-weight:600;">' . esc_html($email_addr) . '</td></tr>';
    $html .= '<tr><td style="padding:6px 0; font-weight:600; color:#475569;">🔑 Password:</td><td style="padding:6px 0; color:#15803d; font-weight:600;">Da impostare tramite il pulsante sicuro qui sotto</td></tr>';
    $html .= '</table>';
    $html .= '</div>';

    // Pulsante di attivazione password
    if (! empty($btn_text)) {
        $html .= '<div style="text-align:center; margin:28px 0;">';
        $html .= '<a href="' . esc_url($cta_url) . '" class="button" style="display:inline-block; background-color:#004b23; color:#ffffff; font-size:15px; font-weight:700; text-decoration:none; padding:13px 26px; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1);">' . esc_html($btn_text) . '</a>';
        $html .= '</div>';
    }

    // Box Opportunità / Cosa puoi fare
    if (! empty($info_title) || ! empty($info_bullets)) {
        $html .= '<div style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #166534; padding:16px 20px; margin:24px 0; border-radius:6px;">';
        if (! empty($info_title)) {
            $html .= '<p style="font-weight:700; font-size:14.5px; color:#166534; margin:0 0 10px;">' . esc_html($info_title) . '</p>';
        }
        if (! empty($info_bullets)) {
            $html .= dfn_format_volunteer_bullet_list($info_bullets);
        }
        $html .= '</div>';
    }

    if (! empty($notes_text)) {
        $html .= '<p style="font-size:13px; color:#64748b; line-height:1.5; margin:16px 0;"><em>' . nl2br(esc_html($notes_text)) . '</em></p>';
    }

    if (! empty($sig_text)) {
        $html .= '<p style="margin-top:24px; font-size:15px; color:#2d3748; line-height:1.5;">' . nl2br(esc_html($sig_text)) . '</p>';
    }

    return $html;
}

/**
 * Invia o reinvia l'email di benvenuto e credenziali (con link sicuro per impostare la password) al volontario.
 * Se l'utente WordPress non esiste ancora, viene creato automaticamente.
 *
 * @param int|object|array $volunteer_or_id ID del record in dfn_fai_members o array/object.
 * @param bool             $force_create_user Se true, crea l'utente WP se non esistente.
 * @param string           $override_to       Destinatario opzionale per test.
 * @return array ['success' => bool, 'message' => string, 'user_id' => int]
 */
function dfn_send_volunteer_credentials_email($volunteer_or_id, bool $force_create_user = true, string $override_to = ''): array
{
    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    $vol = null;
    if (is_numeric($volunteer_or_id)) {
        $vol = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", (int) $volunteer_or_id));
    } elseif (is_object($volunteer_or_id)) {
        $vol = $volunteer_or_id;
    } elseif (is_array($volunteer_or_id)) {
        $vol = (object) $volunteer_or_id;
    }

    if (! $vol) {
        return [
            'success' => false,
            'message' => __('Scheda anagrafica volontario non trovata.', 'dfn-theme'),
            'user_id' => 0,
        ];
    }

    $email = sanitize_email($vol->email ?? '');
    if (empty($email) || ! is_email($email)) {
        return [
            'success' => false,
            'message' => __('Indirizzo email del volontario non valido o assente.', 'dfn-theme'),
            'user_id' => 0,
        ];
    }

    $user = null;
    if (! empty($vol->user_id)) {
        $user = get_userdata((int) $vol->user_id);
    }

    if (! $user) {
        $user_by_email = get_user_by('email', $email);
        if ($user_by_email) {
            $user = $user_by_email;
            if (! empty($vol->id)) {
                $wpdb->update($table_fai, ['user_id' => $user->ID], ['id' => $vol->id], ['%d'], ['%d']);
                $vol->user_id = $user->ID;
            }
        }
    }

    if (! $user && $force_create_user) {
        $first_name = ! empty($vol->first_name) ? trim($vol->first_name) : 'Volontario';
        $last_name  = ! empty($vol->last_name) ? trim($vol->last_name) : 'FAI';

        $base_login = sanitize_user(strtolower($first_name . '.' . $last_name), true);
        $base_login = preg_replace('/[^a-z0-9._-]/i', '', $base_login);
        if (empty($base_login)) {
            $base_login = sanitize_user(strstr($email, '@', true), true);
        }
        if (empty($base_login)) {
            $base_login = 'volontario';
        }

        $login   = $base_login;
        $counter = 1;
        while (username_exists($login)) {
            $counter++;
            $login = $base_login . $counter;
        }

        $random_password = wp_generate_password(24, true, true);
        $new_user_id     = wp_insert_user([
            'user_login'   => $login,
            'user_pass'    => $random_password,
            'user_email'   => $email,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
            'role'         => 'dfn_volunteer',
        ]);

        if (is_wp_error($new_user_id)) {
            return [
                'success' => false,
                'message' => sprintf(__('Errore nella creazione dell\'account utente: %s', 'dfn-theme'), $new_user_id->get_error_message()),
                'user_id' => 0,
            ];
        }

        $user = get_userdata($new_user_id);
        if (! empty($vol->id)) {
            $wpdb->update($table_fai, ['user_id' => $new_user_id], ['id' => $vol->id], ['%d'], ['%d']);
            $vol->user_id = $new_user_id;
        }
    }

    if (! $user) {
        return [
            'success' => false,
            'message' => __('Nessun account utente collegato e creazione automatica non eseguita.', 'dfn-theme'),
            'user_id' => 0,
        ];
    }

    // Assicura che l'utente abbia il ruolo dfn_volunteer
    if (! in_array('dfn_volunteer', (array) $user->roles, true)) {
        $user->add_role('dfn_volunteer');
    }

    // Generazione della chiave di reset password sicura
    $key = get_password_reset_key($user);
    if (is_wp_error($key)) {
        $key = wp_generate_password(20, false);
    }

    $lost_pwd_endpoint = function_exists('wc_get_endpoint_url') && function_exists('wc_get_page_permalink')
        ? wc_get_endpoint_url('lost-password', '', wc_get_page_permalink('myaccount'))
        : site_url('/mio-account/lost-password/');

    $password_reset_url = add_query_arg([
        'key'   => $key,
        'id'    => $user->ID,
        'login' => rawurlencode($user->user_login),
    ], $lost_pwd_endpoint);

    $to          = $override_to ?: $email;
    $raw_subject = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_subject', 'Benvenuto nella Squadra Volontari del {delegazione}! Attiva le tue credenziali') : 'Benvenuto nella Squadra Volontari del {delegazione}! Attiva le tue credenziali';
    $raw_title   = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_email_credentials_title', 'Benvenuto nella Squadra Volontari FAI!') : 'Benvenuto nella Squadra Volontari FAI!';

    $subject = dfn_replace_volunteer_email_placeholders((string) $raw_subject, (array) $vol, $user->ID, $password_reset_url);
    $title   = dfn_replace_volunteer_email_placeholders((string) $raw_title, (array) $vol, $user->ID, $password_reset_url);
    $body    = dfn_build_volunteer_credentials_email_html((array) $vol, $user, $password_reset_url);
    $sender  = dfn_get_volunteer_email_sender();

    $sent = dfn_send_notification_email($to, $subject, $title, $body, [], '[Credenziali Volontario]', $sender);

    if ($sent) {
        if (function_exists('dfn_log_volunteer_roster') && ! empty($vol->id)) {
            dfn_log_volunteer_roster((int) $vol->id, 'Invio email credenziali account', "Email con link impostazione password inviata a {$to} (Username: {$user->user_login})");
        }
        if (function_exists('dfn_log_write')) {
            $author_name = is_user_logged_in() ? wp_get_current_user()->display_name : 'Sistema';
            dfn_log_write('volontari', $author_name, sprintf("Inviata email credenziali e benvenuto a %s %s (%s)", $vol->first_name, $vol->last_name, $to), 'success');
        }

        return [
            'success' => true,
            'message' => sprintf(__('Email di benvenuto e credenziali inviata con successo a %s %s (%s)!', 'dfn-theme'), esc_html($vol->first_name), esc_html($vol->last_name), esc_html($to)),
            'user_id' => $user->ID,
        ];
    }

    return [
        'success' => false,
        'message' => __('Errore durante l\'invio dell\'email dal server di posta. Verifica i log del server.', 'dfn-theme'),
        'user_id' => $user->ID,
    ];
}

/**
 * --------------------------------------------------------------------------
 * PERSONALIZZAZIONE EMAIL RESET PASSWORD (TEMPLATE FAI & MITTENTE DEDICATO)
 * --------------------------------------------------------------------------
 */

/**
 * Personalizza il nome del mittente (From Name) ESCLUSIVAMENTE per l'email di recupero password.
 * Tutte le altre comunicazioni del sito e del sistema prenotazioni rimangono con il mittente predefinito.
 *
 * @param string $from_name Nome mittente originale.
 * @param object $email     Istanza WC_Email.
 * @return string
 */
add_filter('woocommerce_email_from_name', 'dfn_custom_reset_password_from_name', 20, 2);
function dfn_custom_reset_password_from_name($from_name, $email = null)
{
    if ($email && is_object($email) && isset($email->id) && $email->id === 'customer_reset_password') {
        $sender = function_exists('dfn_get_volunteer_email_sender') ? dfn_get_volunteer_email_sender() : null;
        if (! empty($sender['name'])) {
            return $sender['name'];
        }
        $delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
        return 'Coordinamento Volontari ' . $delegation_name;
    }
    return $from_name;
}

/**
 * Personalizza l'oggetto dell'email di reset password inviata da WooCommerce.
 *
 * @param string $subject Oggetto originale.
 * @param object $object  Dati utente.
 * @param object $email   Istanza WC_Email.
 * @return string
 */
add_filter('woocommerce_email_subject_customer_reset_password', 'dfn_custom_reset_password_subject', 20, 3);
function dfn_custom_reset_password_subject($subject, $object, $email = null)
{
    $sender = function_exists('dfn_get_volunteer_email_sender') ? dfn_get_volunteer_email_sender() : null;
    $sender_name = ! empty($sender['name']) ? $sender['name'] : ('Coordinamento Volontari ' . (function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara'));
    return sprintf('Richiesta di reimpostazione password — %s', $sender_name);
}

/**
 * Fallback per l'oggetto del messaggio in caso di recupero password da form standard WordPress.
 */
add_filter('retrieve_password_title', 'dfn_custom_retrieve_password_title', 20, 3);
function dfn_custom_retrieve_password_title($title, $user_login, $user_data)
{
    $sender = function_exists('dfn_get_volunteer_email_sender') ? dfn_get_volunteer_email_sender() : null;
    $sender_name = ! empty($sender['name']) ? $sender['name'] : ('Coordinamento Volontari ' . (function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara'));
    return sprintf('Richiesta di reimpostazione password — %s', $sender_name);
}

/**
 * --------------------------------------------------------------------------
 * PROMEMORIA PAGAMENTO ONLINE & ALERT STAFF VERIFICHE IN SOSPESO
 * --------------------------------------------------------------------------
 */

/**
 * Invia email di promemoria al cliente per il versamento del contributo online prima della scadenza.
 *
 * @param int $booking_id ID del booking.
 * @return bool
 */
function dfn_send_booking_payment_reminder(int $booking_id): bool
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        return false;
    }

    $auto_cancel_hours = isset($event->auto_cancel_hours) ? (int) $event->auto_cancel_hours : 24;
    $sent_at = $order->get_meta('_dfn_payment_link_sent_at');
    $baseline_ts = $sent_at ? strtotime($sent_at) : ($order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0);
    $expire_ts = $baseline_ts + ($auto_cancel_hours * HOUR_IN_SECONDS);
    $remaining_seconds = max(0, $expire_ts - time());
    $remaining_hours = (int) ceil($remaining_seconds / HOUR_IN_SECONDS);
    $data_ora_scadenza = date_i18n('H:i \d\e\l d/m/Y', $expire_ts);

    // Recupera informazioni sullo slot
    $slot_info = '';
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, bs.persons FROM {$wpdb->prefix}dfn_event_slots s 
         JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id 
         WHERE bs.booking_id = %d",
        $booking_id
    ));

    if (! empty($slots)) {
        if (count($slots) === 1) {
            $slot = $slots[0];
            $slot_info = date_i18n('d F Y', strtotime($slot->slot_date)) . ' - ore ' . date('H:i', strtotime($slot->slot_time_start));
        } else {
            $slot_info_parts = [];
            foreach ($slots as $s) {
                $slot_info_parts[] = 'ore ' . date('H:i', strtotime($s->slot_time_start)) . ' (' . absint($s->persons) . ' ' . ($s->persons == 1 ? 'persona' : 'persone') . ')';
            }
            $slot_info = date_i18n('d F Y', strtotime($slots[0]->slot_date)) . ' — ' . implode(', ', $slot_info_parts);
        }
    } else {
        $slot_info = date_i18n('d F Y', strtotime($event->event_date_start)) . ' (Ingresso Libero)';
    }

    $product_name = get_the_title($event->product_id);
    $pay_url = $order->get_checkout_payment_url();
    $btn_primary = dfn_get_setting('email_primary_color', '#004b23');

    $details_table = '<div class="info-box" style="border-left: 4px solid ' . esc_attr($btn_primary) . '; background-color: #f7fafc; padding: 18px 20px; margin: 20px 0; border-radius: 0 6px 6px 0;">';
    $details_table .= '<div class="info-box-title" style="font-weight: bold; font-size: 15px; color: ' . esc_attr($btn_primary) . '; margin-bottom: 8px;">Dettagli della Prenotazione Riservata</div>';
    $details_table .= '<table style="width: 100%; border-collapse: collapse;">';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096; width: 140px;">Evento:</td><td style="padding: 6px 0; font-weight: bold;">' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096;">Turno:</td><td style="padding: 6px 0; font-weight: bold;">' . esc_html($slot_info) . '</td></tr>';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096;">Partecipanti:</td><td style="padding: 6px 0;">' . absint($booking->total_persons) . ' totali (' . absint($booking->persons_standard) . ' Standard, ' . absint($booking->persons_fai) . ' Soci FAI)</td></tr>';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096;">Totale Contributo:</td><td style="padding: 6px 0; font-weight: bold; color: #2d3748;">' . wc_price($order->get_total()) . '</td></tr>';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096;">Scadenza Riserva:</td><td style="padding: 6px 0; font-weight: bold; color: #d97706;">⏱️ ' . esc_html($data_ora_scadenza) . ' (mancano ca. ' . esc_html((string) $remaining_hours) . ' ore)</td></tr>';
    $details_table .= '</table>';
    $details_table .= '</div>';

    $cta_btn = '<div style="text-align: center; margin: 30px 0 25px 0;">';
    $cta_btn .= '<a href="' . esc_url($pay_url) . '" style="background-color: ' . esc_attr($btn_primary) . '; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">';
    $cta_btn .= esc_html__('👉 Paga e Conferma i tuoi Posti Adesso', 'dfn-theme');
    $cta_btn .= '</a>';
    $cta_btn .= '<p style="margin: 10px 0 0 0; font-size: 12.5px; color: #718096;">Oppure copia questo link nel browser: <a href="' . esc_url($pay_url) . '" style="color: ' . esc_attr($btn_primary) . '; word-break: break-all;">' . esc_html($pay_url) . '</a></p>';
    $cta_btn .= '</div>';

    $replacements = [
        'nome_cliente'          => esc_html($booking->customer_name),
        'nome_evento'           => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
        'link_pagamento'        => $cta_btn,
        'ore_rimaste'           => (string) $remaining_hours,
        'data_ora_scadenza'     => $data_ora_scadenza,
        'totale_ordine'         => wc_price($order->get_total()),
    ];

    $intro_template = dfn_get_setting('email_payment_reminder_intro');
    $content = dfn_replace_email_placeholders($intro_template, $replacements);

    if (strpos($intro_template, '{dettagli_prenotazione}') === false) {
        $content .= $details_table;
    }

    if (strpos($intro_template, '{link_pagamento}') === false) {
        $content .= $cta_btn;
    }

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_payment_reminder_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_payment_reminder_title'), $replacements);

    $context_tag = "[Ordine #" . ($booking->order_id ?: 'N/D') . "] [Booking #{$booking_id}] [Reminder Pagamento]";
    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content, [], $context_tag);
}

/**
 * Invia email di sollecito allo staff per una prenotazione rimasta in pending_approval a metà del tempo di riserva.
 *
 * @param int $booking_id ID del booking.
 * @return bool
 */
function dfn_send_admin_pending_approval_reminder(int $booking_id): bool
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $auto_cancel_hours = isset($event->auto_cancel_hours) ? (int) $event->auto_cancel_hours : 24;
    $created_ts = strtotime($booking->created_at);
    $elapsed_hours = (int) round(max(0, time() - $created_ts) / HOUR_IN_SECONDS);

    $product_name = get_the_title($event->product_id);
    $admin_url = admin_url('admin.php?page=dfn-fai-pending-bookings');
    $btn_primary = dfn_get_setting('email_primary_color', '#004b23');

    $details_table = '<div class="info-box" style="border-left: 4px solid #d97706; background-color: #fffbeb; padding: 18px 20px; margin: 20px 0; border-radius: 0 6px 6px 0;">';
    $details_table .= '<div class="info-box-title" style="font-weight: bold; font-size: 15px; color: #b45309; margin-bottom: 8px;">Dettagli Richiesta in Attesa</div>';
    $details_table .= '<table style="width: 100%; border-collapse: collapse;">';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096; width: 140px;">Evento:</td><td style="padding: 6px 0; font-weight: bold;">' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096;">Cliente:</td><td style="padding: 6px 0; font-weight: bold;">' . esc_html($booking->customer_name) . ' (' . esc_html($booking->customer_email) . ')</td></tr>';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096;">Partecipanti:</td><td style="padding: 6px 0;">' . absint($booking->total_persons) . ' totali (' . absint($booking->persons_fai) . ' Soci FAI)</td></tr>';
    $details_table .= '<tr><td style="padding: 6px 0; color: #718096;">In attesa da:</td><td style="padding: 6px 0; font-weight: bold; color: #b45309;">' . esc_html((string) $elapsed_hours) . ' ore (Scadenza riserva tra ' . max(0, $auto_cancel_hours - $elapsed_hours) . 'h)</td></tr>';
    $details_table .= '</table>';
    $details_table .= '</div>';

    $cta_btn = '<div style="text-align: center; margin: 25px 0;">';
    $cta_btn .= '<a href="' . esc_url($admin_url) . '" style="background-color: ' . esc_attr($btn_primary) . '; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block;">';
    $cta_btn .= esc_html__('🔍 Apri Pannello Verifiche FAI', 'dfn-theme');
    $cta_btn .= '</a>';
    $cta_btn .= '</div>';

    $replacements = [
        'booking_id'            => (string) $booking->id,
        'order_id'              => (string) ($booking->order_id ?: 'N/D'),
        'nome_evento'           => esc_html($product_name),
        'ore_trascorse'         => (string) $elapsed_hours,
        'ore_totali'            => (string) $auto_cancel_hours,
        'dettagli_prenotazione' => $details_table,
        'link_approvazione'     => $cta_btn,
    ];

    $body_template = dfn_get_setting('email_admin_pending_approval_body');
    $content = dfn_replace_email_placeholders($body_template, $replacements);

    if (strpos($body_template, '{dettagli_prenotazione}') === false) {
        $content .= $details_table;
    }
    if (strpos($body_template, '{link_approvazione}') === false) {
        $content .= $cta_btn;
    }

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_admin_pending_approval_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_admin_pending_approval_title'), $replacements);

    $admin_email = dfn_get_setting('email_verify_fai');
    if (empty($admin_email)) {
        $admin_email = dfn_get_setting('delegation_email', get_option('admin_email'));
    }

    $context_tag = "[Booking #{$booking_id}] [Sollecito Staff]";
    return dfn_send_notification_email($admin_email, $subject, $title, $content, [], $context_tag);
}

/**
 * Invia email al cliente quando una richiesta in pending_approval scade per decorrenza del termine massimo.
 *
 * @param int $booking_id ID del booking.
 * @return bool
 */
function dfn_send_booking_expired_unapproved(int $booking_id): bool
{
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_bookings WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        return false;
    }

    $auto_cancel_hours = isset($event->auto_cancel_hours) ? (int) $event->auto_cancel_hours : 24;
    $product_name = get_the_title($event->product_id);

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'ore_totali'   => (string) $auto_cancel_hours,
    ];

    $body_template = dfn_get_setting('email_fai_booking_expired_body');
    $content = dfn_replace_email_placeholders($body_template, $replacements);

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_fai_booking_expired_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_fai_booking_expired_title'), $replacements);

    $context_tag = "[Ordine #" . ($booking->order_id ?: 'N/D') . "] [Booking #{$booking_id}] [Scadenza Verifica]";
    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content, [], $context_tag);
}

/**
 * ==========================================================================
 * MODULO 2.1.1: NOTIFICHE E CONVOCAZIONI RIUNIONI DI DELEGAZIONE E TEAM (#26)
 * ==========================================================================
 */

/**
 * Costruisce il template HTML per l'email di convocazione o promemoria riunione volontari (Plenaria o di Team).
 *
 * @param object      $meeting       Riga della riunione da wp_dfn_volunteer_meetings.
 * @param object|null $team          Oggetto team da wp_dfn_teams (o null se Plenaria).
 * @param bool        $is_reminder   Se si tratta di un promemoria (es. 7gg o 24h prima).
 * @param string      $reminder_type Tipo promemoria ('7d', '1d', etc.).
 * @return string HTML renderizzato.
 */
function dfn_build_volunteer_meeting_email_html(object $meeting, ?object $team = null, bool $is_reminder = false, string $reminder_type = ''): string
{
    $delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
    $m_date = strtotime($meeting->meeting_date);
    $date_formatted = date_i18n('l d F Y', $m_date);
    $time_formatted = substr($meeting->meeting_time_start, 0, 5);
    if (! empty($meeting->meeting_time_end)) {
        $time_formatted .= ' - ' . substr($meeting->meeting_time_end, 0, 5);
    }

    $is_team = ! empty($team) && ! empty($team->id);
    $scope_title = $is_team ? ($team->icon . ' ' . $team->name) : '🏛️ Plenaria di Delegazione (' . $delegation_name . ')';
    $scope_bg    = $is_team ? ($team->badge_bg ?: '#f0fdf4') : '#f8fafc';
    $scope_color = $is_team ? ($team->color ?: '#004b23') : '#1e293b';
    $scope_border= $is_team ? ($team->color ? $team->color . '40' : '#86efac') : '#cbd5e1';

    $intro_text = $is_reminder 
        ? ($reminder_type === '1d' ? 'Ti ricordiamo che <strong>domani</strong> si terrà la seguente riunione programmata.' : 'Ti ricordiamo che tra una settimana si terrà la seguente riunione programmata.')
        : 'Sei invitato a partecipare alla seguente riunione di delegazione/squadra:';

    $hub_url = function_exists('wc_get_page_permalink') ? wc_get_endpoint_url('riunioni-fai', '', wc_get_page_permalink('myaccount')) : home_url('/mio-account/riunioni-fai/');

    $html = '<div style="font-family:\'Outfit\',-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif; max-width:600px; margin:0 auto; color:#1e293b; line-height:1.6;">';
    
    // Header Badge Ambito
    $html .= '<div style="margin-bottom: 20px; text-align: center;">';
    $html .= '<span style="display:inline-block; background:' . esc_attr($scope_bg) . '; color:' . esc_attr($scope_color) . '; border:1px solid ' . esc_attr($scope_border) . '; padding:6px 14px; border-radius:20px; font-weight:700; font-size:13px;">' . esc_html($scope_title) . '</span>';
    $html .= '</div>';

    // Titolo Riunione
    $html .= '<h2 style="font-size:22px; font-weight:800; color:#0f172a; margin:0 0 12px 0; text-align:center;">' . esc_html($meeting->title) . '</h2>';
    $html .= '<p style="font-size:14.5px; color:#475569; text-align:center; margin:0 0 24px 0;">' . $intro_text . '</p>';

    // Box Scheda Dettagli
    $html .= '<div style="background:#ffffff; border:1px solid #e2e8f0; border-left:4px solid ' . esc_attr($scope_color) . '; border-radius:10px; padding:20px 24px; margin-bottom:24px; box-shadow:0 2px 6px rgba(0,0,0,0.04);">';
    
    // Data & Ora
    $html .= '<div style="display:flex; align-items:flex-start; gap:12px; margin-bottom:14px;">';
    $html .= '<div style="font-size:20px; line-height:1;">🗓️</div>';
    $html .= '<div><strong style="font-size:14px; color:#0f172a; display:block;">Data &amp; Orario:</strong>';
    $html .= '<span style="font-size:14.5px; color:#334155; font-weight:600;">' . esc_html(ucfirst($date_formatted)) . '</span> <span style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:12.5px; font-weight:700; color:#0f172a; margin-left:6px; border:1px solid #e2e8f0;">⏰ ore ' . esc_html($time_formatted) . '</span>';
    $html .= '</div></div>';

    // Sede / Luogo
    $html .= '<div style="display:flex; align-items:flex-start; gap:12px; margin-bottom:14px;">';
    $html .= '<div style="font-size:20px; line-height:1;">📍</div>';
    $html .= '<div><strong style="font-size:14px; color:#0f172a; display:block;">Sede / Luogo:</strong>';
    $html .= '<span style="font-size:14px; color:#334155;">' . esc_html($meeting->location) . '</span>';
    $html .= '</div></div>';

    // Ordine del giorno
    if (! empty($meeting->agenda)) {
        $html .= '<div style="margin-top:16px; padding-top:14px; border-top:1px dashed #e2e8f0;">';
        $html .= '<strong style="font-size:13.5px; color:#0f172a; display:block; margin-bottom:6px;">📝 Ordine del Giorno &amp; Note:</strong>';
        $html .= '<div style="font-size:13.5px; color:#475569; background:#f8fafc; padding:12px 14px; border-radius:6px; border:1px solid #edf2f7; white-space:pre-line;">' . esc_html($meeting->agenda) . '</div>';
        $html .= '</div>';
    }

    $html .= '</div>';

    // Bottone Partecipazione Online (se presente link)
    if (! empty($meeting->meeting_link)) {
        $html .= '<div style="text-align:center; margin-bottom:20px;">';
        $html .= '<a href="' . esc_url($meeting->meeting_link) . '" target="_blank" rel="noopener noreferrer" style="background:#0284c7; color:#ffffff; font-weight:700; font-size:14px; text-decoration:none; padding:12px 24px; border-radius:8px; display:inline-block; box-shadow:0 2px 4px rgba(2,132,199,0.3);">';
        $html .= '🔗 Partecipa alla Riunione Online (Meet / Zoom) &rarr;';
        $html .= '</a>';
        $html .= '</div>';
    }

    // Link Bacheca Volontario
    $html .= '<div style="text-align:center; margin-bottom:24px;">';
    $html .= '<a href="' . esc_url($hub_url) . '" style="color:#004b23; font-weight:700; font-size:13.5px; text-decoration:underline;">';
    $html .= '👉 Consulta tutte le tue riunioni nell\'Area Riservata';
    $html .= '</a>';
    $html .= '</div>';

    // Canali Squadra WhatsApp & Drive se presenti
    if ($is_team && (! empty($team->whatsapp_url) || ! empty($team->drive_url))) {
        $html .= '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; text-align:center; margin-bottom:20px;">';
        $html .= '<div style="font-size:12px; font-weight:700; color:#64748b; margin-bottom:8px; text-transform:uppercase;">Canali ' . esc_html($team->name) . ':</div>';
        $html .= '<div style="display:inline-flex; gap:10px; flex-wrap:wrap; justify-content:center;">';
        if (! empty($team->whatsapp_url)) {
            $html .= '<a href="' . esc_url($team->whatsapp_url) . '" target="_blank" style="background:#25d366; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; text-decoration:none;">💬 Gruppo WhatsApp</a>';
        }
        if (! empty($team->drive_url)) {
            $html .= '<a href="' . esc_url($team->drive_url) . '" target="_blank" style="background:#0284c7; color:#ffffff; font-size:12px; font-weight:700; padding:6px 12px; border-radius:6px; text-decoration:none;">📁 Cartella Drive</a>';
        }
        $html .= '</div></div>';
    }

    // Footer
    $html .= '<p style="font-size:12px; color:#94a3b8; text-align:center; margin-top:24px; border-top:1px solid #f1f5f9; padding-top:14px;">';
    $html .= 'Questa notifica è stata generata automaticamente dalla Delegazione ' . esc_html($delegation_name) . '. Puoi gestire le tue preferenze di notifica accedendo al tuo profilo.';
    $html .= '</p>';

    $html .= '</div>';

    return $html;
}

/**
 * Invia email di convocazione o promemoria per una riunione a tutti i destinatari profilati (Plenaria o Team specifico).
 *
 * @param int         $meeting_id          ID della riunione in wp_dfn_volunteer_meetings.
 * @param bool        $is_reminder         Se true, genera un'email di promemoria.
 * @param string      $reminder_type       Tipo promemoria ('7d', '1d', etc.).
 * @param array<int>  $override_member_ids Array opzionale di ID membri per invio mirato/test.
 * @return array Risultato dell'invio con contatori e destinatari.
 */
function dfn_send_volunteer_meeting_notification(int $meeting_id, bool $is_reminder = false, string $reminder_type = '', array $override_member_ids = []): array
{
    global $wpdb;
    $table_meetings = $wpdb->prefix . 'dfn_volunteer_meetings';
    $table_fai      = $wpdb->prefix . 'dfn_fai_members';

    $meeting = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_meetings} WHERE id = %d", $meeting_id));
    if (! $meeting) {
        return [
            'success'      => false,
            'message'      => __('Riunione non trovata.', 'dfn-theme'),
            'sent_count'   => 0,
            'failed_count' => 0,
            'recipients'   => [],
        ];
    }

    // Verifica toggle impostazioni notifiche / promemoria riunioni
    if ($is_reminder) {
        $reminders_enabled = function_exists('dfn_get_volunteer_setting') ? (dfn_get_volunteer_setting('vol_enable_meeting_reminders', 'yes') === 'yes') : true;
        if (! $reminders_enabled) {
            return [
                'success'      => false,
                'message'      => __('Promemoria automatici riunioni disattivati nelle impostazioni.', 'dfn-theme'),
                'sent_count'   => 0,
                'failed_count' => 0,
                'recipients'   => [],
            ];
        }
    } else {
        $notifs_enabled = function_exists('dfn_get_volunteer_setting') ? (dfn_get_volunteer_setting('vol_enable_meeting_notifications', 'yes') === 'yes') : true;
        if (! $notifs_enabled) {
            return [
                'success'      => false,
                'message'      => __('Invio notifiche convocazione riunioni disattivato nelle impostazioni.', 'dfn-theme'),
                'sent_count'   => 0,
                'failed_count' => 0,
                'recipients'   => [],
            ];
        }
    }

    $team = null;
    $is_team = ! empty($meeting->team_id) && (int) $meeting->team_id > 0;
    if ($is_team) {
        $team = function_exists('dfn_get_team') ? dfn_get_team((int) $meeting->team_id) : null;
    }

    // 1. Identificazione dei destinatari
    $recipients_pool = [];
    if (! empty($override_member_ids)) {
        $placeholders = implode(',', array_fill(0, count($override_member_ids), '%d'));
        $sql = "SELECT * FROM {$table_fai} WHERE id IN ({$placeholders}) AND is_volunteer = 1";
        $recipients_pool = $wpdb->get_results($wpdb->prepare($sql, ...$override_member_ids));
    } elseif ($is_team) {
        $recipients_pool = function_exists('dfn_get_team_members') ? dfn_get_team_members((int) $meeting->team_id) : [];
        // Aggiungi anche i supervisori se non già presenti
        $supervisors = function_exists('dfn_get_team_supervisors') ? dfn_get_team_supervisors((int) $meeting->team_id) : [];
        $existing_emails = array_map(fn($v) => strtolower($v->email ?? ''), $recipients_pool);
        foreach ($supervisors as $sup_u) {
            if ($sup_u && ! empty($sup_u->user_email) && ! in_array(strtolower($sup_u->user_email), $existing_emails, true)) {
                $recipients_pool[] = (object) [
                    'id'         => 0,
                    'user_id'    => $sup_u->ID,
                    'first_name' => $sup_u->first_name ?: $sup_u->display_name,
                    'last_name'  => $sup_u->last_name,
                    'email'      => $sup_u->user_email,
                ];
            }
        }
    } else {
        // Plenaria: tutti i volontari ufficiali attivi
        $recipients_pool = $wpdb->get_results("SELECT * FROM {$table_fai} WHERE is_volunteer = 1 AND volunteer_status = 'active' ORDER BY last_name ASC, first_name ASC");
    }

    if (empty($recipients_pool)) {
        return [
            'success'      => false,
            'message'      => __('Nessun volontario trovato tra i destinatari del gruppo.', 'dfn-theme'),
            'sent_count'   => 0,
            'failed_count' => 0,
            'recipients'   => [],
        ];
    }

    $delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
    $scope_tag = $is_team && $team ? '[' . $team->name . ']' : '[' . $delegation_name . ']';
    $m_date_str = date_i18n('d/m/Y', strtotime($meeting->meeting_date));

    if ($is_reminder) {
        $rem_label = ($reminder_type === '1d') ? 'Domani' : 'Promemoria';
        $subject = sprintf('[%s] %s Riunione: %s - %s', $rem_label, $scope_tag, $meeting->title, $m_date_str);
        $title_header = sprintf('⏰ %s Riunione %s', $rem_label, $is_team && $team ? $team->name : 'Plenaria');
    } else {
        $subject = sprintf('Convocazione Riunione %s: %s - %s', $scope_tag, $meeting->title, $m_date_str);
        $title_header = sprintf('📅 Convocazione Riunione %s', $is_team && $team ? $team->name : 'Plenaria');
    }

    $html_content = dfn_build_volunteer_meeting_email_html($meeting, $team, $is_reminder, $reminder_type);
    $sender = function_exists('dfn_get_volunteer_email_sender') ? dfn_get_volunteer_email_sender() : null;

    $sent_emails   = [];
    $failed_emails = [];

    foreach ($recipients_pool as $recip) {
        $to = sanitize_email($recip->email ?? '');
        if (empty($to) || ! is_email($to)) {
            continue;
        }

        // Verifica preferenze di notifica se collegato a utente WP
        $u_id = ! empty($recip->user_id) ? (int) $recip->user_id : 0;
        if ($u_id > 0 && function_exists('dfn_user_wants_meetings_notification')) {
            if (! dfn_user_wants_meetings_notification($u_id)) {
                continue; // Utente ha disattivato le notifiche per le riunioni
            }
        }

        $sent = dfn_send_notification_email($to, $subject, $title_header, $html_content, [], '[Riunione Volontari]', $sender);
        if ($sent) {
            $sent_emails[] = $to;
        } else {
            $failed_emails[] = $to;
        }
    }

    // Aggiornamento stato reminder nel DB
    if ($is_reminder) {
        if ($reminder_type === '7d') {
            $wpdb->update($table_meetings, ['reminder_7d_sent' => 1], ['id' => $meeting_id], ['%d'], ['%d']);
        } elseif ($reminder_type === '1d') {
            $wpdb->update($table_meetings, ['reminder_1d_sent' => 1], ['id' => $meeting_id], ['%d'], ['%d']);
        }
    }

    // Logging
    $target_desc = $is_team && $team ? "Team {$team->name}" : 'Plenaria Volontari';
    $action_desc = $is_reminder ? "Invio promemoria ({$reminder_type})" : "Invio convocazione";
    if (function_exists('dfn_log_volunteer_meeting')) {
        dfn_log_volunteer_meeting($meeting_id, "{$action_desc} email", "Inviate con successo a " . count($sent_emails) . " volontari ({$target_desc})");
    }
    if (function_exists('dfn_log_write')) {
        $author_name = is_user_logged_in() ? wp_get_current_user()->display_name : 'Sistema';
        dfn_log_write('volontari', $author_name, sprintf("%s per la riunione '%s' inviato a %d destinatari (%s)", $action_desc, $meeting->title, count($sent_emails), $target_desc), 'success');
    }

    return [
        'success'      => count($sent_emails) > 0,
        'message'      => sprintf(__('Email inviate con successo a %d volontari.', 'dfn-theme'), count($sent_emails)),
        'sent_count'   => count($sent_emails),
        'failed_count' => count($failed_emails),
        'recipients'   => $sent_emails,
        'target_label' => $target_desc,
    ];
}


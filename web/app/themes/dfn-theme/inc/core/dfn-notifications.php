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
 * Invia un'email HTML formattata con il template premium di FAI Prenotazioni.
 *
 * @param string $to          Destinatario.
 * @param string $subject     Oggetto dell'email.
 * @param string $title       Titolo visivo all'interno del template.
 * @param string $content_html Contenuto HTML principale.
 * @param array  $attachments Allegati (opzionale).
 * @return bool True se l'invio ha avuto successo, false altrimenti.
 */
function dfn_send_notification_email($to, $subject, $title, $content_html, $attachments = [])
{
    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

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

    // Se $to contiene virgole, lo convertiamo in array per wp_mail
    if (is_string($to) && strpos($to, ',') !== false) {
        $to = array_map('sanitize_email', array_map('trim', explode(',', $to)));
        $to = array_filter($to, 'is_email');
    }

    // Evita l'invio all'indirizzo fittizio no-email@dfn.it
    if (is_string($to)) {
        if (trim(strtolower($to)) === 'no-email@dfn.it') {
            return true;
        }
    } elseif (is_array($to)) {
        $to = array_filter($to, function ($email) {
            return trim(strtolower($email)) !== 'no-email@dfn.it';
        });
        if (empty($to)) {
            return true;
        }
    }

    // Genera il template HTML completo
    $body = dfn_get_email_html_template($title, $content_html);

    return wp_mail($to, $subject, $body, $headers, $attachments);
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

    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content);
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
    $details_table .= '</table>';
    $details_table .= '</div>';

    $replacements = [
        'nome_cliente' => esc_html($booking->customer_name),
        'nome_evento'  => esc_html($product_name),
        'dettagli_prenotazione' => $details_table,
    ];

    $body_template = dfn_get_setting('email_pending_body');
    $content = dfn_replace_email_placeholders($body_template, $replacements);

    if (strpos($body_template, '{dettagli_prenotazione}') === false) {
        $content .= $details_table;
    }

    $content .= '<p>Non è ancora necessario versare alcun contributo o mostrare QR code. Riceverai un secondo messaggio con l\'esito della richiesta.</p>';

    $subject = dfn_replace_email_placeholders(dfn_get_setting('email_pending_subject'), $replacements);
    $title   = dfn_replace_email_placeholders(dfn_get_setting('email_pending_title'), $replacements);

    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content);
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

    return dfn_send_notification_email($booking->customer_email, $subject, $title, $content);
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

    return dfn_send_notification_email($email, $subject, $title, $content);
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

    return dfn_send_notification_email($email, $subject, $title, $content);
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

    return dfn_send_notification_email($admin_email, $subject, 'Notifica Nuova Prenotazione', $content);
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

    return dfn_send_notification_email($admin_email, $subject, '🔍 Nuova Prenotazione FAI da Verificare', $content);
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



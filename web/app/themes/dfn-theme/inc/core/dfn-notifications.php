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

    $cc_bcc_raw = dfn_get_setting('email_cc_bcc', '');
    if (! empty($cc_bcc_raw)) {
        $emails = array_map('sanitize_email', array_map('trim', explode(',', $cc_bcc_raw)));
        foreach ($emails as $email) {
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
    $accent_color  = dfn_get_setting('email_accent_color', '#c69c3a');
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
    $subject = 'Conferma Prenotazione: ' . $product_name;

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

    $content = '<p>Gentile <strong>' . esc_html($booking->customer_name) . '</strong>,</p>';
    $content .= '<p>La tua prenotazione per l\'evento <strong>' . esc_html($product_name) . '</strong> è stata confermata con successo!</p>';

    $content .= '<div class="info-box">';
    $content .= '<div class="info-box-title">Dettagli della Prenotazione</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td class="label">Data e Inizio Visita:</td><td>' . esc_html($slot_info) . '</td></tr>';
    $content .= '<tr><td class="label">Luogo:</td><td>' . esc_html($event->location) . '</td></tr>';
    $content .= '<tr><td class="label">Partecipanti:</td><td>' . absint($booking->total_persons) . ' totali (' . absint($booking->persons_standard) . ' Standard + ' . absint($booking->persons_fai) . ' Soci FAI)</td></tr>';
    $content .= '<tr><td class="label">Modalità Contributo:</td><td>' . ($booking->payment_method === 'dfn_in_loco' ? 'Contributo all\'ingresso (Botteghino)' : 'Versato Online') . '</td></tr>';
    if ($booking->payment_method === 'dfn_in_loco' && $booking->amount_due > 0) {
        $content .= '<tr><td class="label">Contributo minimo suggerito:</td><td style="font-weight:bold; color:#ff6600;">' . wc_price($booking->amount_due) . '</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<p style="font-size: 14px; color: #4a5568; margin-top: 15px; margin-bottom: 15px;"><strong>⚠️ Importante:</strong> Ti chiediamo di presentarti presso il luogo dell\'evento <strong>almeno 10 minuti prima</strong> dell\'orario d\'inizio della visita indicato per facilitare le operazioni di accettazione.</p>';

    $content .= '<p>Per accedere all\'evento, mostra all\'ingresso il codice QR del tuo gruppo cliccando sul pulsante sottostante (è sufficiente mostrare un solo codice QR per todo il gruppo).</p>';
    $content .= '<div class="text-center"><a href="' . esc_url($hub_url) . '" class="button">Mostra Codice QR / Ingressi</a></div>';

    if ($booking->payment_method === 'dfn_in_loco') {
        $content .= '<p style="font-size: 14px; color: #4a5568;"><em>Nota: Avendo scelto il contributo all\'ingresso, ti chiediamo di arrivare circa 10 minuti prima dell\'orario indicato per agevolare la ricezione del contributo presso il botteghino.</em></p>';
    }

    $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Non puoi più partecipare? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

    return dfn_send_notification_email($booking->customer_email, $subject, 'Prenotazione Confermata!', $content);
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
    $subject = 'Richiesta di Prenotazione Ricevuta: ' . $product_name;

    $content = '<p>Gentile <strong>' . esc_html($booking->customer_name) . '</strong>,</p>';
    $content .= '<p>Abbiamo ricevuto la tua richiesta di prenotazione per l\'evento <strong>' . esc_html($product_name) . '</strong>.</p>';
    $content .= '<p>Questo evento richiede l\'<strong>approvazione manuale</strong> da parte del nostro staff. Stiamo verificando la disponibilità e ti invieremo un\'email di conferma non appena la richiesta sarà approvata (solitamente entro poche ore).</p>';

    $content .= '<div class="info-box">';
    $content .= '<div class="info-box-title">Dettagli della Richiesta</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td class="label">Stato:</td><td style="font-weight:bold; color:#c69c3a;">In Attesa di Approvazione Staff</td></tr>';
    $content .= '<tr><td class="label">Partecipanti:</td><td>' . absint($booking->total_persons) . ' totali</td></tr>';
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<p>Non è ancora necessario versare alcun contributo o mostrare QR code. Riceverai un secondo messaggio con l\'esito della richiesta.</p>';

    return dfn_send_notification_email($booking->customer_email, $subject, 'Richiesta in Fase di Verifica', $content);
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
        $subject = 'Richiesta di Prenotazione Rifiutata: ' . $product_name;

        $content = '<p>Gentile <strong>' . esc_html($booking->customer_name) . '</strong>,</p>';
        $content .= '<p>Siamo spiacenti di informarti che la tua richiesta di prenotazione per l\'evento <strong>' . esc_html($product_name) . '</strong> non è stata approvata dallo staff.</p>';
        $content .= '<p>Ciò può essere dovuto al superamento della capacità massima dei turni disponibili o ad altre esigenze logistiche organizzative.</p>';

        if (! empty($booking->notes)) {
            $content .= '<div class="info-box">';
            $content .= '<div class="info-box-title">Nota dallo Staff</div>';
            $content .= '<p style="margin:0; font-size:14px;">' . esc_html($booking->notes) . '</p>';
            $content .= '</div>';
        }

        $content .= '<p>Se hai già effettuato transazioni online relative a questo ordine, verrà emesso un rimborso integrale nel più breve tempo possibile.</p>';

        return dfn_send_notification_email($booking->customer_email, $subject, 'Richiesta non Approvata', $content);
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
    $subject = 'Annullamento Prenotazione: ' . $product_name;

    $content = '<p>Gentile <strong>' . esc_html($booking->customer_name) . '</strong>,</p>';
    $content .= '<p>Ti confermiamo che la tua prenotazione per l\'evento <strong>' . esc_html($product_name) . '</strong> è stata <strong>annullata</strong> con successo.</p>';
    $content .= '<p>I posti precedentemente riservati a tuo nome sono stati liberati e resi nuovamente disponibili per altri visitatori.</p>';

    $content .= '<div class="info-box">';
    $content .= '<div class="info-box-title">Riepilogo Annullamento</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td class="label">Data Prenotata:</td><td>' . date_i18n('d F Y', strtotime($event->event_date_start)) . '</td></tr>';
    $content .= '<tr><td class="label">Stato:</td><td style="font-weight:bold; color:#e53e3e;">ANNULLATA</td></tr>';
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<p>Speriamo di poterti accogliere in uno dei nostri prossimi eventi FAI.</p>';

    return dfn_send_notification_email($booking->customer_email, $subject, 'Prenotazione Annullata', $content);
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
    $subject = 'Prenotazione Annullata dallo Staff: ' . $product_name;

    $content = '<p>Gentile <strong>' . esc_html($booking->customer_name) . '</strong>,</p>';
    $content .= '<p>Ti informiamo che la tua prenotazione per l\'evento <strong>' . esc_html($product_name) . '</strong> è stata <strong>annullata dal nostro staff</strong>.</p>';
    $content .= '<p>Se hai domande o desideri chiarimenti, ti invitiamo a contattarci rispondendo a questa email o telefonicamente.</p>';

    $content .= '<div class="info-box">';
    $content .= '<div class="info-box-title">Riepilogo Annullamento</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td class="label">Data Prenotata:</td><td>' . date_i18n('d F Y', strtotime($event->event_date_start)) . '</td></tr>';
    $content .= '<tr><td class="label">Partecipanti:</td><td>' . absint($booking->total_persons) . ' totali</td></tr>';
    $content .= '<tr><td class="label">Stato:</td><td style="font-weight:bold; color:#e53e3e;">ANNULLATA DALLO STAFF</td></tr>';
    $content .= '</table>';
    $content .= '</div>';

    if ($booking->payment_method !== 'dfn_in_loco' && (float) $booking->amount_paid > 0) {
        $content .= '<p>Qualora tu abbia già versato il contributo online, ti sarà emesso un rimborso integrale nel più breve tempo possibile.</p>';
    }

    $content .= '<p>Speriamo di poterti accogliere in uno dei nostri prossimi eventi FAI.</p>';

    return dfn_send_notification_email($booking->customer_email, $subject, 'Prenotazione Annullata dallo Staff', $content);
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
    $subject = 'Promemoria Evento Domani: ' . $product_name;

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

    $content = '<p>Gentile <strong>' . esc_html($booking->customer_name) . '</strong>,</p>';
    $content .= '<p>Questo è un promemoria per ricordarti che domani si terrà l\'evento <strong>' . esc_html($product_name) . '</strong> a cui ti sei prenotato!</p>';

    $content .= '<div class="info-box">';
    $content .= '<div class="info-box-title">Dettagli per Domani</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td class="label">Data e Inizio Visita:</td><td><strong>' . esc_html($slot_info) . '</strong></td></tr>';
    $content .= '<tr><td class="label">Luogo di Ritrovo:</td><td>' . esc_html($event->location) . '</td></tr>';
    if ($booking->payment_method === 'dfn_in_loco' && $booking->amount_due > 0) {
        $content .= '<tr><td class="label">Contributo minimo suggerito:</td><td style="font-weight:bold; color:#ff6600;">' . wc_price($booking->amount_due) . ' (Cassa Live)</td></tr>';
    }
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<p><strong>Informazioni importanti per l\'accesso:</strong></p>';
    $content .= '<ul>';
    $content .= '<li>Ti chiediamo di presentarti presso il luogo dell\'evento <strong>almeno 10 minuti prima</strong> dell\'orario d\'inizio della visita indicato per facilitare l\'accettazione.</li>';
    $content .= '<li>Tieni a portata di mano questo messaggio per mostrare il codice QR all\'ingresso. Clicca sul pulsante in basso per aprire la prenotazione digitale sul tuo telefono.</li>';
    if ($booking->payment_method === 'dfn_in_loco') {
        $content .= '<li>Avendo optato per il contributo all\'ingresso, per favore presentati con qualche minuto di anticipo al fine di evitare code e velocizzare il check-in.</li>';
    }
    $content .= '<li>Ti ricordiamo di portare con te la tessera di iscrizione FAI (in corso di validità) per ciascun partecipante registrato come Socio FAI, in quanto lo staff effettuerà la verifica all\'ingresso.</li>';
    $content .= '</ul>';

    $content .= '<div class="text-center"><a href="' . esc_url($hub_url) . '" class="button">Apri Prenotazione con Codice QR</a></div>';

    $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Non puoi più partecipare? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

    return dfn_send_notification_email($booking->customer_email, $subject, 'Ti aspettiamo domani!', $content);
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
    $subject = 'Un posto si è liberato! Prenota ora: ' . $product_name;

    // Genera il link di acquisto prioritario con parametri waitlist e hash di sicurezza
    // Questo link porterà al checkout con il prodotto e i turni pre-selezionati,
    // e convaliderà la sessione della waitlist per 2h.
    $hash = wp_hash($waitlist->id . '|' . $waitlist->customer_email . '|' . $waitlist->ttl_expires_at);
    $checkout_url = add_query_arg([
        'add-to-cart' => $event->product_id,
        'quantity'    => $waitlist->persons,
        'dfn_wl_id'   => $waitlist->id,
        'dfn_wl_hash' => $hash,
    ], wc_get_checkout_url());

    $content = '<p>Gentile <strong>' . esc_html($waitlist->customer_name) . '</strong>,</p>';
    $content .= '<p>Buone notizie! Si è liberata la disponibilità per l\'evento <strong>' . esc_html($product_name) . '</strong> a cui eri iscritto in lista d\'attesa.</p>';

    $content .= '<p>Avendo priorità di prenotazione, abbiamo riservato i posti per te. Hai a disposizione <strong>2 ore</strong> da questo momento per completare la tua prenotazione prima che il posto venga offerto alla persona successiva in lista.</p>';

    $content .= '<div class="info-box">';
    $content .= '<div class="info-box-title">La tua Prenotazione Riservata</div>';
    $content .= '<table>';
    $content .= '<tr><td class="label">Evento:</td><td>' . esc_html($product_name) . '</td></tr>';
    $content .= '<tr><td class="label">Posti Riservati:</td><td>' . absint($waitlist->persons) . '</td></tr>';
    $content .= '<tr><td class="label">Scadenza Priorità:</td><td style="color:#e53e3e; font-weight:bold;">' . date('H:i', strtotime($waitlist->ttl_expires_at)) . ' di oggi</td></tr>';
    $content .= '</table>';
    $content .= '</div>';

    $content .= '<p>Clicca sul pulsante sottostante per accedere direttamente al checkout veloce e confermare subito la tua presenza:</p>';
    $content .= '<div class="text-center"><a href="' . esc_url($checkout_url) . '" class="button">Completa la Prenotazione Ora</a></div>';

    $content .= '<p style="font-size: 13px; color: #718096;"><em>Se non completerai la prenotazione entro le ore ' . date('H:i', strtotime($waitlist->ttl_expires_at)) . ', il sistema annullerà automaticamente la tua prenotazione riservata e sbloccherà lo slot per il prossimo utente in attesa.</em></p>';

    return dfn_send_notification_email($waitlist->customer_email, $subject, 'Posto Disponibile in Lista d\'Attesa!', $content);
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
    $subject = 'Tessera FAI Verificata con Successo';

    $content = '<p>Gentile <strong>' . esc_html($first_name . ' ' . $last_name) . '</strong>,</p>';
    $content .= '<p>Ti informiamo che il nostro staff ha completato con successo la verifica della tua <strong>Tessera Socio FAI n° ' . esc_html($card_number) . '</strong>.</p>';
    $content .= '<p>La tessera risulta <strong>attiva e valida</strong>. La tariffa scontata riservata ai Soci FAI è stata confermata correttamente per la tua prenotazione.</p>';
    $content .= '<p>Non devi fare altro! Ti basterà presentare la tua tessera FAI e il codice QR della prenotazione all\'ingresso dell\'evento.</p>';

    return dfn_send_notification_email($email, $subject, 'Tessera FAI Approvata', $content);
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
    $subject = 'Aggiornamento Verifica Tessera FAI';

    $content = '<p>Gentile <strong>' . esc_html($first_name . ' ' . $last_name) . '</strong>,</p>';
    $content .= '<p>Ti informiamo che abbiamo effettuato la verifica della tua <strong>Tessera Socio FAI n° ' . esc_html($card_number) . '</strong> inserita in fase di prenotazione.</p>';
    $content .= '<p>Purtroppo, la tessera <strong>non è risultata valida</strong> per il seguente motivo:</p>';
    $content .= '<div class="info-box" style="border-left: 4px solid #e53e3e; background: #fff5f5; padding: 15px; margin: 15px 0;">';
    $content .= '<div class="info-box-title" style="color: #e53e3e; font-weight: bold; margin-bottom: 5px;">Motivazione dello Staff</div>';
    $content .= '<p style="margin:0; font-size:14px; color: #c53030;">' . esc_html($reason) . '</p>';
    $content .= '</div>';
    $content .= '<p>Ti ricordiamo che, qualora non fosse possibile presentare una tessera FAI valida e attiva all\'ingresso dell\'evento, ti verrà richiesto di lasciare il contributo alla tariffa Standard (Intero).</p>';
    $content .= '<p>Se si tratta di un errore di inserimento, puoi rispondere a questa email o contattare il nostro staff per fornirci i dati corretti.</p>';

    return dfn_send_notification_email($email, $subject, 'Verifica Tessera FAI Fallita', $content);
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
    $content .= '<tr><td class="label">Modalità Contributo:</td><td>' . ($booking->payment_method === 'dfn_in_loco' ? 'Contributo all\'ingresso (Botteghino)' : 'Versato Online') . '</td></tr>';
    $content .= '<tr><td class="label">Contributo:</td><td>' . wc_price($booking->payment_method === 'dfn_in_loco' ? $booking->amount_due : $booking->amount_paid) . '</td></tr>';
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
    $content .= '<div class="info-box" style="border-left: 4px solid ' . esc_attr(dfn_get_setting('email_accent_color', '#c69c3a')) . '; background-color: #f7fafc;">';
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

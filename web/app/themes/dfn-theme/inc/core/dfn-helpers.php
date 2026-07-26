<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ottiene un'impostazione configurata a database o il suo valore di default.
 *
 * @param string $key La chiave dell'impostazione.
 * @param mixed $default Il valore di default opzionale se non configurato.
 * @return mixed Il valore dell'opzione o il default.
 */
function dfn_get_setting($key, $default = null)
{
    static $settings = null;
    if ($settings === null) {
        $settings = get_option('dfn_settings', []);
        if (! is_array($settings)) {
            $settings = [];
        }
    }

    // Elenco completo dei valori predefiniti (fallback)
    $defaults = [
        'delegation_name'             => 'FAI Novara',
        'delegation_email'            => 'novara@delegazione.fondoambiente.it',
        'delegation_footer'           => 'FAI - Delegazione di Novara',
        'email_staff_signature'       => 'Lo Staff della Delegazione FAI Novara',
        'email_new_booking'           => get_option('admin_email'),
        'email_verify_fai'            => get_option('admin_email'),
        'email_cc_bcc'                => '',
        'email_primary_color'         => '#004b23',
        'email_accent_color'          => '#e74f30',
        'email_bg_color'              => '#f4f6f8',
        'email_text_color'            => '#2d3748',
        'email_disclaimer'            => "Questa è un'email automatica inviata dal sistema di prenotazione. Si prega di non rispondere direttamente a questo messaggio.",
        'cron_timeout_no_booking'     => 24,
        'cron_reminder_start'         => 12,
        'cron_reminder_end'           => 36,
        'cron_waitlist_ttl'           => 2,
        'cron_batch_reminder'         => 20,
        'cron_batch_expired'          => 30,
        'fai_coupon_code'             => 'socio_fai_novara_2025',
        'fai_expiry_warning_days'     => 15,
        'fai_member_types'            => 'INDIVIDUALE, COPPIA, FAMIGLIA',
        'fai_no_email_placeholder'    => 'no-email@dfn.it',
        'limit_max_fai_members'       => 100,
        'limit_max_activity_logs'     => 50,
        'text_early_arrival'          => 'almeno 10 minuti prima',
        'text_no_bookings_myaccount'  => 'Non hai ancora effettuato nessuna prenotazione. Consulta i nostri eventi per prenotare il tuo posto.',
        'text_checkout_btn'           => 'Effettua Prenotazione',
        'enable_admin_notification'   => 'yes',
        'enable_reminder_24h'         => 'yes',
        'enable_auto_waitlist'        => 'yes',
        'enable_auto_complete_paid'   => 'yes',
        'setup_roles_version'         => '2.0',
        'setup_fai_discount'          => 5,

        // Defaults per i testi e-mail
        'email_confirm_subject'       => 'Conferma Prenotazione: {nome_evento}',
        'email_confirm_title'         => 'Prenotazione Confermata!',
        'email_confirm_intro'         => "Gentile <strong>{nome_cliente}</strong>,<br><br>La tua prenotazione per l'evento <strong>{nome_evento}</strong> è stata confermata con successo!",
        'email_confirm_notes'         => "<strong>⚠️ Importante:</strong> Ti chiediamo di presentarti presso il luogo dell'evento <strong>almeno 10 minuti prima</strong> dell'orario d'inizio della visita indicato per facilitare le operazioni di accettazione.",

        'email_modify_subject'        => 'Modifica Prenotazione: {nome_evento}',
        'email_modify_title'          => 'Prenotazione Modificata!',
        'email_modify_intro'          => "Gentile <strong>{nome_cliente}</strong>,<br><br>La tua prenotazione per l'evento <strong>{nome_evento}</strong> è stata modificata con successo con i nuovi quantitativi indicati di seguito.",
        'email_modify_notes'          => "<strong>⚠️ Importante:</strong> Ti chiediamo di presentarti presso il luogo dell'evento <strong>almeno 10 minuti prima</strong> dell'orario d'inizio della visita indicato per facilitare le operazioni di accettazione.",

        'email_pending_subject'       => 'Richiesta di Prenotazione Ricevuta: {nome_evento}',
        'email_pending_title'         => 'Richiesta in Fase di Verifica',
        'email_pending_body'          => "Gentile <strong>{nome_cliente}</strong>,<br><br>Abbiamo ricevuto la tua richiesta di prenotazione per l'evento <strong>{nome_evento}</strong>.<br><br>Questo evento richiede l'<strong>approvazione manuale</strong> da parte del nostro staff. Stiamo verificando la disponibilità e ti invieremo un'email di conferma non appena la richiesta sarà approvata (solitamente entro poche ore).",

        'email_declined_subject'      => 'Richiesta di Prenotazione Rifiutata: {nome_evento}',
        'email_declined_title'        => 'Richiesta non Approvata',
        'email_declined_body'         => "Gentile <strong>{nome_cliente}</strong>,<br><br>Siamo spiacenti di informarti che la tua richiesta di prenotazione per l'evento <strong>{nome_evento}</strong> non è stata approvata dallo staff.<br><br>Ciò può essere dovuto al superamento della capacità massima dei turni disponibili o ad altre esigenze logistiche organizzative.",

        'email_cancelled_subject'     => 'Annullamento Prenotazione: {nome_evento}',
        'email_cancelled_title'       => 'Prenotazione Annullata',
        'email_cancelled_body'        => "Gentile <strong>{nome_cliente}</strong>,<br><br>Ti confermiamo che la tua prenotazione per l'evento <strong>{nome_evento}</strong> è stata <strong>annullata</strong> con successo.<br><br>I posti precedentemente riservati a tuo nome sono stati liberati e resi nuovamente disponibili per altri visitatori.",

        'email_admin_cancelled_subject'=> 'Prenotazione Annullata dallo Staff: {nome_evento}',
        'email_admin_cancelled_title' => 'Prenotazione Annullata dallo Staff',
        'email_admin_cancelled_body'  => "Gentile <strong>{nome_cliente}</strong>,<br><br>Ti informiamo che la tua prenotazione per l'evento <strong>{nome_evento}</strong> è stata <strong>annullata dal nostro staff</strong>.<br><br>Se hai domande o desideri chiarimenti, ti invitiamo a contattarci rispondendo a questa email o telefonicamente.",

        'email_reminder_subject'      => 'Promemoria Evento Domani: {nome_evento}',
        'email_reminder_title'        => 'Ti aspettiamo domani!',
        'email_reminder_intro'        => "Gentile <strong>{nome_cliente}</strong>,<br><br>Questo è un promemoria per ricordarti che domani si terrà l'evento <strong>{nome_evento}</strong> a cui ti sei prenotato!",
        'email_reminder_notes'        => "<ul>\n<li>Ti chiediamo di presentarti presso il luogo dell'evento <strong>almeno 10 minuti prima</strong> dell'orario d'inizio della visita indicato per facilitare l'accettazione.</li>\n<li>Tieni a portata di mano questo messaggio per mostrare il codice QR all'ingresso. Clicca sul pulsante in basso per aprire la prenotazione digitale sul tuo telefono.</li>\n<li>Ti ricordiamo di portare con te la tessera di iscrizione FAI (in corso di validità) per ciascun partecipante registrato come Socio FAI, in quanto lo staff effettuerà la verifica all'ingresso.</li>\n</ul>",

        'email_waitlist_subject'      => 'Un posto si è liberato! Prenota ora: {nome_evento}',
        'email_waitlist_title'        => "Posto Disponibile in Lista d'Attesa!",
        'email_waitlist_body'         => "Gentile <strong>{nome_cliente}</strong>,<br><br>Buone notizie! Si è liberata la disponibilità per l'evento <strong>{nome_evento}</strong> a cui eri iscritto in lista d'attesa.<br><br>Avendo priorità di prenotazione, abbiamo riservato i posti per te. Hai a disposizione <strong>{ore_waitlist} ore</strong> da questo momento per completare la tua prenotazione prima che il posto venga offerto alla persona successiva in lista.",

        'email_fai_approved_subject'  => 'Tessera FAI Verificata con Successo',
        'email_fai_approved_title'    => 'Tessera FAI Approvata',
        'email_fai_approved_body'     => "Gentile <strong>{nome_cliente}</strong>,<br><br>Ti informiamo che il nostro staff ha completato con successo la verifica della tua <strong>Tessera Socio FAI n° {numero_tessera}</strong>.<br><br>La tessera risulta <strong>attiva e valida</strong>. La tariffa scontata riservata ai Soci FAI è stata confermata correttamente per la tua prenotazione.<br><br>Non devi fare altro! Ti basterà presentare la tua tessera FAI e il codice QR della prenotazione all'ingresso dell'evento.",

        'email_fai_rejected_subject'  => 'Aggiornamento Verifica Tessera FAI',
        'email_fai_rejected_title'    => 'Verifica Tessera FAI Fallita',
        'email_fai_rejected_body'     => "Gentile <strong>{nome_cliente}</strong>,<br><br>Ti informiamo che abbiamo effettuato la verifica della tua <strong>Tessera Socio FAI n° {numero_tessera}</strong> inserita in fase di prenotazione.<br><br>Purtroppo, la tessera <strong>non è risultata valida</strong> per il seguente motivo:<br><br><strong>{motivo_rifiuto}</strong><br><br>Ti ricordiamo che, qualora non fosse possibile presentare una tessera FAI valida e attiva all'ingresso dell'evento, ti verrà richiesto di lasciare il contributo alla tariffa Standard (Intero).<br><br>Se si tratta di un errore di inserimento, puoi rispondere a questa email o contattare il nostro staff per fornirci i dati corretti.",
        'email_fai_booking_rejected_subject'  => 'Richiesta di Prenotazione Rifiutata: {nome_evento}',
        'email_fai_booking_rejected_title'    => 'Richiesta non Approvata',
        'email_fai_booking_rejected_body'     => "Gentile <strong>{nome_cliente}</strong>,<br><br>Siamo spiacenti di informarti che la tua richiesta di prenotazione per l'evento <strong>{nome_evento}</strong> non è stata approvata dallo staff.<br><br>Ciò può essere dovuto al superamento della capacità massima dei turni disponibili o ad altre esigenze logistiche organizzative.",
    ];

    if (isset($settings[ $key ])) {
        return is_string($settings[ $key ]) ? stripslashes($settings[ $key ]) : $settings[ $key ];
    }

    if ($default !== null) {
        return $default;
    }

    return isset($defaults[ $key ]) ? $defaults[ $key ] : null;
}

/**
 * 1. ETICHETTA QUALIFICA (SOCIO FAI / AUTORITÀ / CASSA LIVE / STANDARD)
 */

/**
 * Verifica se un ordine WooCommerce ha una componente FAI (sconto socio o biglietti FAI).
 *
 * @param WC_Order|false $order L'oggetto ordine WooCommerce.
 * @return bool
 */
function dfn_is_order_fai($order)
{
    if (! $order) {
        return false;
    }

    // 1. Verifica tramite i coupon
    $coupons = $order->get_coupon_codes();
    $fai_coupon = strtolower(dfn_get_setting('fai_coupon_code', 'socio_fai_novara_2025'));
    if (in_array($fai_coupon, array_map('strtolower', $coupons))) {
        return true;
    }

    // 2. Verifica tramite fees (ereditate) o custom items
    foreach ($order->get_items('fee') as $item) {
        if (strpos(strtolower($item->get_name()), 'fai') !== false) {
            return true;
        }
    }

    // 3. Verifica tramite i record di booking nel database dfn_bookings (se presenti)
    global $wpdb;
    $order_id = $order->get_id();
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT persons_fai FROM {$wpdb->prefix}dfn_bookings WHERE order_id = %d LIMIT 1",
        $order_id,
    ));

    if ($booking && $booking->persons_fai > 0) {
        return true;
    }

    return false;
}

/**
 * Ottiene l'etichetta HTML per qualificare un ordine nella lista.
 *
 * @param WC_Order|false $order L'oggetto ordine WooCommerce.
 * @return string HTML dell'etichetta.
 */
function dfn_get_order_qualifica_label($order)
{
    if (! $order) {
        return '';
    }
    $badges = [];

    if ($order->get_meta('_dfn_is_authority') === 'yes' || $order->get_meta('_cv_is_authority') === 'yes') {
        $badges[] = '<span style="background:#6b21a8; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block; margin-right:4px; margin-bottom:4px;">🌟 AUTORITÀ</span>';
    }

    if (dfn_is_order_fai($order)) {
        $badges[] = '<span style="background:#ff6600; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block; margin-right:4px; margin-bottom:4px;">SOCIO FAI</span>';
    }

    if (empty($badges)) {
        return '<span style="color:#aaa; font-size:12px;">Standard</span>';
    }
    return implode('', $badges);
}

/**
 * Ottiene l'etichetta HTML per la tipologia/stato di pagamento di un ordine.
 *
 * @param WC_Order|false $order L'oggetto ordine WooCommerce.
 * @return string HTML dell'etichetta.
 */
function dfn_get_order_payment_type_label($order)
{
    if (! $order) {
        return '';
    }

    $payment_method = $order->get_payment_method();
    $status = $order->get_status();

    // Controlla se la prenotazione ha una parte pagata e una parte dovuta (Ibrido)
    global $wpdb;
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT amount_paid, amount_due FROM {$wpdb->prefix}dfn_bookings WHERE order_id = %d LIMIT 1",
        $order->get_id(),
    ));

    if ($booking && isset($booking->amount_paid) && isset($booking->amount_due) && floatval($booking->amount_paid) > 0 && floatval($booking->amount_due) > 0) {
        return '<span style="background:#7c3aed; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">🔄 IBRIDO</span>';
    }

    if ($payment_method === 'dfn_in_loco' || $order->get_payment_method_title() === 'Contanti in Loco (Botteghino)') {
        if ($status === 'pending') {
            return '<span style="background:#eab308; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">🕒 IN LOCO (SOSPESO)</span>';
        }

        $physical_method = $order->get_meta('_dfn_physical_payment_method');
        if ($physical_method === 'cash') {
            return '<span style="background:#16a34a; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">💵 BOTTEGHINO (CASH)</span>';
        } elseif ($physical_method === 'pos') {
            return '<span style="background:#0284c7; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">💳 BOTTEGHINO (POS)</span>';
        } else {
            return '<span style="background:#16a34a; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">💵 CASSA LIVE</span>';
        }
    }

    return '<span style="background:#2563eb; color:#fff; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:11px; white-space:nowrap; display:inline-block;">🌐 DONATO ONLINE</span>';
}

add_filter('manage_woocommerce_page_wc-orders_columns', 'dfn_add_fai_column_to_orders');
add_filter('manage_edit-shop_order_columns', 'dfn_add_fai_column_to_orders');
function dfn_add_fai_column_to_orders($columns)
{
    $new_columns = [];
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        if ('order_status' === $key) {
            $new_columns['dfn_fai_status'] = 'Qualifica';
            $new_columns['dfn_payment_type'] = 'Contributo';
        }
    }
    return $new_columns;
}

add_action('manage_woocommerce_page_wc-orders_custom_column', 'dfn_populate_fai_column', 10, 2);
add_action('manage_shop_order_posts_custom_column', 'dfn_populate_fai_column', 10, 2);
/**
 * Popola le colonne personalizzate nella lista ordini WooCommerce.
 *
 * @param string     $column   Nome della colonna.
 * @param int|object $order_id ID dell'ordine (o oggetto WC_Order in HPOS).
 * @return void
 */
function dfn_populate_fai_column($column, $order_id): void
{
    if ('dfn_fai_status' === $column) {
        $order = wc_get_order($order_id);
        echo wp_kses_post(dfn_get_order_qualifica_label($order));
    } elseif ('dfn_payment_type' === $column) {
        $order = wc_get_order($order_id);
        echo wp_kses_post(dfn_get_order_payment_type_label($order));
    }
}

/**
 * 2. PLACEHOLDER EMAIL WOOCOMMERCE {nome_evento}
 */
add_filter('woocommerce_email_format_string', 'dfn_custom_email_placeholders', 20, 2);
function dfn_custom_email_placeholders($string, $email)
{
    if (isset($email->object) && is_a($email->object, 'WC_Order')) {
        $order = $email->object;
        if (strpos($string, '{nome_evento}') !== false) {
            $nomi_eventi = [];
            foreach ($order->get_items() as $item) {
                $nomi_eventi[] = $item->get_name();
            }
            $titolo_evento = implode(' + ', $nomi_eventi);
            $string = str_replace('{nome_evento}', $titolo_evento, $string);
        }
    }
    return $string;
}

/**
 * 3. GESTORE DEI LOG UTENTE (LOGIN, REGISTRAZIONE, ACCESSI)
 */
/**
 * Registra un'azione nel log attività dell'utente.
 *
 * Tiene un massimo di 50 voci per utente, eliminando le più vecchie.
 *
 * @param int    $user_id ID dell'utente WordPress.
 * @param string $azione  Descrizione dell'azione da registrare.
 * @return void
 */
function dfn_aggiungi_log_utente(int $user_id, string $azione): void
{
    $log = get_user_meta($user_id, '_dfn_user_activity_log', true);
    if (! is_array($log)) {
        $log = [];
    }

    $ip_raw = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
        ? explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])))[0]
        : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'N/A');
    $ip = trim($ip_raw);

    $log[] = [
        'data'   => current_time('mysql'),
        'azione' => $azione,
        'ip'     => $ip,
    ];

    $max_logs = intval(dfn_get_setting('limit_max_activity_logs', 50));
    if (count($log) > $max_logs) {
        $log = array_slice($log, -$max_logs);
    }

    update_user_meta($user_id, '_dfn_user_activity_log', $log);
}

add_action('wp_login', 'dfn_track_user_login', 10, 2);
function dfn_track_user_login($user_login, $user)
{
    dfn_aggiungi_log_utente($user->ID, '🔑 Login effettuato');
}

add_action('user_register', 'dfn_track_user_registration', 10, 1);
function dfn_track_user_registration($user_id)
{
    $user_info = get_userdata($user_id);
    $ordini_passati = wc_get_orders(['billing_email' => $user_info->user_email, 'limit' => 1]);
    $messaggio = '🆕 Registrazione completata' . (!empty($ordini_passati) ? ' (Riconosciuto come vecchio cliente FAI)' : '');
    dfn_aggiungi_log_utente($user_id, $messaggio);
}

add_action('template_redirect', 'dfn_track_access_tickets');
function dfn_track_access_tickets()
{
    if (is_user_logged_in() && is_account_page() && is_wc_endpoint_url('orders')) {
        $user_id = get_current_user_id();
        $lock_key = 'dfn_log_tickets_lock_' . $user_id;
        if (!get_transient($lock_key)) {
            dfn_aggiungi_log_utente($user_id, '🎟️ Visualizzata sezione "I Miei Biglietti"');
            set_transient($lock_key, 1, HOUR_IN_SECONDS);
        }
    }
}

add_action('show_user_profile', 'dfn_mostra_log_nel_profilo');
add_action('edit_user_profile', 'dfn_mostra_log_nel_profilo');
function dfn_mostra_log_nel_profilo($user)
{
    $log = get_user_meta($user->ID, '_dfn_user_activity_log', true);
    if (empty($log)) {
        // Fallback al vecchio log per compatibilità
        $log = get_user_meta($user->ID, '_cv_user_activity_log', true);
    }
    ?>
    <div style="margin-top: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 5px;">
        <h3>📜 Log Attività FAI Prenotazioni</h3>
        <?php if (empty($log)) : echo '<p>Nessuna attività.</p>';
        else : $log = array_reverse($log); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th style="width:180px;">Data e Ora</th><th>Azione</th><th style="width:120px;">Indirizzo IP</th></tr></thead>
                <tbody>
                    <?php foreach ($log as $entry) : ?>
                        <tr><td><strong><?php echo date_i18n('d/m/Y - H:i:s', strtotime($entry['data'])); ?></strong></td><td><?php echo esc_html($entry['azione']); ?></td><td><small><?php echo esc_html($entry['ip']); ?></small></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Sostituisce i segnaposto in un testo con i valori reali passati.
 *
 * @param string $text Il testo contenente i segnaposto tipo {nome_cliente}.
 * @param array $replacements Array associativo chiave => valore di sostituzioni.
 * @return string Il testo con i segnaposto sostituiti.
 */
function dfn_replace_email_placeholders(string $text, array $replacements): string
{
    foreach ($replacements as $placeholder => $value) {
        $text = str_replace('{' . $placeholder . '}', (string) $value, $text);
    }
    return $text;
}

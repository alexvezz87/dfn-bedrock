<?php
/**
 * DFN Booking & Volunteer System — Volunteer Settings Panel
 *
 * Fornisce un pannello di configurazione per il modulo Volontari FAI strutturato a tab verticali:
 * - Mittente & Destinatari notifiche amministrative (nuove registrazioni e candidature)
 * - Modalità approvazione preventiva (Sì/No)
 * - Modelli email guidati (senza bisogno di conoscere l'HTML) con ANTEPRIMA GRAFICA LIVE in tempo reale
 * - Strumento di test invio email
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_volunteer_settings_register_menu', 20);
add_action('wp_ajax_dfn_send_volunteer_test_email', 'dfn_ajax_send_volunteer_test_email');

/**
 * Registra il sottomenu "Impostazioni" sotto la voce principale "Volontari FAI".
 */
function dfn_volunteer_settings_register_menu(): void
{
    add_submenu_page(
        'dfn-volunteers',
        __('Impostazioni Volontari FAI', 'dfn-theme'),
        __('Impostazioni', 'dfn-theme'),
        'manage_options',
        'dfn-volunteer-settings',
        'dfn_render_volunteer_settings_page'
    );
}

/**
 * Helper per recuperare una chiave di impostazione del modulo Volontari.
 * Se il valore a database è assente o vuoto, restituisce il valore predefinito guidato.
 *
 * @param string $key     Chiave opzione.
 * @param mixed  $default Valore di fallback opzionale.
 * @return mixed
 */
function dfn_get_volunteer_setting(string $key, $default = null)
{
    static $vol_settings = null;
    if ($vol_settings === null || isset($GLOBALS['dfn_volunteer_settings_cache'])) {
        $vol_settings = isset($GLOBALS['dfn_volunteer_settings_cache']) ? $GLOBALS['dfn_volunteer_settings_cache'] : get_option('dfn_volunteer_settings', []);
        if (! is_array($vol_settings)) {
            $vol_settings = [];
        }
    }

    $delegation_name  = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
    $delegation_email = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_email', get_option('admin_email')) : get_option('admin_email');

    $defaults = [
        'vol_email_sender_name'              => 'Coordinamento Volontari ' . $delegation_name,
        'vol_email_admin_recipients'         => $delegation_email,
        'vol_require_approval'               => 'yes',
        'vol_enable_candidate_pending_email' => 'yes',
        'vol_enable_approved_email'          => 'yes',
        'vol_enable_teams'                   => 'yes',
        'vol_enable_meeting_notifications'   => 'yes',
        'vol_enable_meeting_reminders'       => 'yes',
        'email_sandbox_mode'                 => 'live',
        'email_sandbox_recipient'            => $delegation_email,
        'vol_survey_enable_preferred_place'  => 'no',
        'vol_enable_whatsapp_share'          => 'no',
        'vol_whatsapp_share_template'        => "Ciao! Ti inoltro l'aggiornamento per la squadra {squadra} della {delegazione}. Accedi al portale per tutti i dettagli:\n{link_accesso}",

        // 1. Email Admin: Notifica nuova candidatura
        'vol_email_admin_subject'            => 'Nuova Candidatura Volontario FAI: {nome} {cognome}',
        'vol_email_admin_title'              => 'Nuova Candidatura Volontario FAI',
        'vol_email_admin_intro'              => "Gentile Staff della {delegazione},\n\nÈ stata inviata una nuova richiesta di registrazione come Volontario FAI tramite il portale online:",
        'vol_email_admin_box_title'          => '👤 Dati e Disponibilità del Candidato',
        'vol_email_admin_instructions'       => "La candidatura è attualmente In Attesa di Approvazione. Puoi esaminare la scheda anagrafica e approvare il volontario direttamente dal pannello di controllo:",
        'vol_email_admin_btn_text'           => 'Valuta Candidatura nel Pannello Admin →',

        // 2. Email Candidato: Presa in carico (In Attesa)
        'vol_email_pending_subject'          => 'Candidatura Volontario FAI Ricevuta - {delegazione}',
        'vol_email_pending_title'            => 'Grazie per la tua candidatura!',
        'vol_email_pending_intro'            => "Gentile {nome},\n\nAbbiamo ricevuto con entusiasmo la tua candidatura per entrare a far parte della Squadra Volontari del {delegazione}!",
        'vol_email_pending_box_title'        => '📋 Stato della tua richiesta: In fase di verifica',
        'vol_email_pending_box_text'         => "La tua scheda è stata presa in carico dallo staff di Delegazione. Verificheremo i tuoi dati e ti invieremo un'email di conferma non appena il tuo account sarà approvato, fornendoti tutte le indicazioni per partecipare alle attività e alle riunioni.",
        'vol_email_pending_closing'          => "Grazie di cuore per il tuo tempo e per la tua passione a sostegno del patrimonio e della bellezza del nostro territorio.",
        'vol_email_pending_signature'        => "A presto,\nLo Staff della {delegazione}",

        // 3. Email Volontario: Approvazione e Benvenuto
        'vol_email_approved_subject'         => 'Benvenuto nella Squadra Volontari del {delegazione}!',
        'vol_email_approved_title'           => 'La tua candidatura è stata approvata!',
        'vol_email_approved_intro'           => "Gentile {nome},\n\nSiamo felici di comunicarti che la tua candidatura come Volontario del {delegazione} è stata approvata con successo! 🎉",
        'vol_email_approved_box_title'       => '🏛️ Cosa puoi fare adesso nella tua Area Riservata?',
        'vol_email_approved_box_bullets'     => "Consultare e dare disponibilità per i turni delle Giornate FAI ed eventi di delegazione\nPartecipare ai sondaggi di disponibilità e pianificazione oraria\nVisualizzare il calendario aggiornato delle riunioni di delegazione\nScaricare e consultare i materiali informativi, guide e schede di visita",
        'vol_email_approved_btn_text'        => 'Accedi alla tua Bacheca Volontario →',
        'vol_email_approved_notes'           => "Nota: Per accedere ti basterà utilizzare l'indirizzo email {email} e la password scelta in fase di registrazione.",
        'vol_email_approved_signature'       => "Benvenuto a bordo e buon lavoro per la nostra missione comune!\nLo Staff della {delegazione}",

        // 4. Email Volontario: Invio / Reinvio Credenziali Account
        'vol_email_credentials_subject'      => 'Benvenuto nella Squadra Volontari del {delegazione}! Attiva le tue credenziali',
        'vol_email_credentials_title'        => 'Benvenuto nella Squadra Volontari FAI!',
        'vol_email_credentials_intro'        => "Gentile {nome},\n\nSiamo felici di averti con noi nella Squadra Volontari del {delegazione}! 🎉\n\nPer permetterti di accedere alla tua Area Riservata e consultare i turni, le riunioni e i materiali, abbiamo preparato il tuo account personale.",
        'vol_email_credentials_box_title'    => '🏛️ Le tue Credenziali di Accesso',
        'vol_email_credentials_btn_text'     => '🔐 Imposta la tua Password ed Accedi →',
        'vol_email_credentials_info_title'   => '✨ Cosa puoi fare nella tua Area Riservata?',
        'vol_email_credentials_info_bullets' => "Consultare i turni assegnati e le sedi operative per le Giornate FAI\nCompilare i sondaggi di disponibilità oraria\nVisualizzare il calendario delle riunioni di delegazione e i verbali\nScaricare le dispense informative e le schede storico-artistiche",
        'vol_email_credentials_notes'        => "Nota di sicurezza: Il link per l'impostazione della password è personale e valido per 24 ore. In caso di necessità potrai sempre richiedere un nuovo link dalla pagina di login: {link_accesso}.",
        'vol_email_credentials_signature'    => "Benvenuto a bordo e buon lavoro per la nostra missione comune!\nLo Staff e il Coordinamento Volontari della {delegazione}",
    ];

    // Se esiste a database ed è valorizzato (non stringa vuota)
    if (isset($vol_settings[$key]) && trim((string) $vol_settings[$key]) !== '') {
        return $vol_settings[$key];
    }

    if (isset($defaults[$key])) {
        return $defaults[$key];
    }

    return $default;
}

/**
 * Salva i campi inviati tramite POST nella schermata Impostazioni Volontari.
 */
function dfn_volunteer_settings_save_fields(): void
{
    if (! isset($_POST['dfn_vol_settings_nonce']) || ! wp_verify_nonce($_POST['dfn_vol_settings_nonce'], 'dfn_save_vol_settings_action')) {
        return;
    }

    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members')) {
        return;
    }

    $existing_settings = get_option('dfn_volunteer_settings', []);
    if (! is_array($existing_settings)) {
        $existing_settings = [];
    }

    $fields = [
        'vol_email_sender_name'              => 'sanitize_text_field',
        'vol_email_admin_recipients'         => 'sanitize_email_list',
        'vol_require_approval'               => 'sanitize_text_field',
        'vol_enable_candidate_pending_email' => 'sanitize_text_field',
        'vol_enable_approved_email'          => 'sanitize_text_field',
        'vol_enable_teams'                   => 'sanitize_text_field',
        'vol_enable_meeting_notifications'   => 'sanitize_text_field',
        'vol_enable_meeting_reminders'       => 'sanitize_text_field',
        'email_sandbox_mode'                 => 'sanitize_text_field',
        'email_sandbox_recipient'            => 'sanitize_email',
        'vol_survey_enable_preferred_place'  => 'sanitize_text_field',
        'vol_enable_whatsapp_share'          => 'sanitize_text_field',
        'vol_whatsapp_share_template'        => 'sanitize_textarea_field',

        // 1. Admin Notification
        'vol_email_admin_subject'            => 'sanitize_text_field',
        'vol_email_admin_title'              => 'sanitize_text_field',
        'vol_email_admin_intro'              => 'sanitize_textarea_field',
        'vol_email_admin_box_title'          => 'sanitize_text_field',
        'vol_email_admin_instructions'       => 'sanitize_textarea_field',
        'vol_email_admin_btn_text'           => 'sanitize_text_field',

        // 2. Candidate Pending
        'vol_email_pending_subject'          => 'sanitize_text_field',
        'vol_email_pending_title'            => 'sanitize_text_field',
        'vol_email_pending_intro'            => 'sanitize_textarea_field',
        'vol_email_pending_box_title'        => 'sanitize_text_field',
        'vol_email_pending_box_text'         => 'sanitize_textarea_field',
        'vol_email_pending_closing'          => 'sanitize_textarea_field',
        'vol_email_pending_signature'        => 'sanitize_textarea_field',

        // 3. Volunteer Approved
        'vol_email_approved_subject'         => 'sanitize_text_field',
        'vol_email_approved_title'           => 'sanitize_text_field',
        'vol_email_approved_intro'           => 'sanitize_textarea_field',
        'vol_email_approved_box_title'       => 'sanitize_text_field',
        'vol_email_approved_box_bullets'     => 'sanitize_textarea_field',
        'vol_email_approved_btn_text'        => 'sanitize_text_field',
        'vol_email_approved_notes'           => 'sanitize_textarea_field',
        'vol_email_approved_signature'       => 'sanitize_textarea_field',

        // 4. Volunteer Credentials
        'vol_email_credentials_subject'      => 'sanitize_text_field',
        'vol_email_credentials_title'        => 'sanitize_text_field',
        'vol_email_credentials_intro'        => 'sanitize_textarea_field',
        'vol_email_credentials_box_title'    => 'sanitize_text_field',
        'vol_email_credentials_btn_text'     => 'sanitize_text_field',
        'vol_email_credentials_info_title'   => 'sanitize_text_field',
        'vol_email_credentials_info_bullets' => 'sanitize_textarea_field',
        'vol_email_credentials_notes'        => 'sanitize_textarea_field',
        'vol_email_credentials_signature'    => 'sanitize_textarea_field',
    ];

    $merged = $existing_settings;
    $raw_input = $_POST['dfn_vol_settings'] ?? [];
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'notifiche';

    if (is_array($raw_input)) {
        foreach ($raw_input as $field_key => $val) {
            if (! isset($fields[$field_key])) {
                continue;
            }
            $sanitize_type = $fields[$field_key];

            if ($sanitize_type === 'sanitize_email_list') {
                $emails = array_map('sanitize_email', array_map('trim', explode(',', $val)));
                $emails = array_filter($emails);
                $merged[$field_key] = implode(', ', $emails);
            } elseif ($sanitize_type === 'sanitize_email') {
                $merged[$field_key] = sanitize_email(wp_unslash($val));
            } elseif ($sanitize_type === 'sanitize_textarea_field') {
                $merged[$field_key] = sanitize_textarea_field(wp_unslash($val));
            } else {
                $merged[$field_key] = sanitize_text_field(wp_unslash($val));
            }
        }
    }

    // Toggle checkboxes default 'no' if unchecked in POST when saving notifications, channels or surveys tab
    if ($active_tab === 'notifiche') {
        $toggles = ['vol_require_approval', 'vol_enable_candidate_pending_email', 'vol_enable_approved_email', 'vol_enable_meeting_notifications', 'vol_enable_meeting_reminders'];
        foreach ($toggles as $t_key) {
            if (! isset($raw_input[$t_key])) {
                $merged[$t_key] = 'no';
            }
        }
    } elseif ($active_tab === 'sondaggi') {
        $toggles = ['vol_survey_enable_preferred_place'];
        foreach ($toggles as $t_key) {
            if (! isset($raw_input[$t_key])) {
                $merged[$t_key] = 'no';
            }
        }
    } elseif ($active_tab === 'canali') {
        $toggles = ['vol_enable_teams', 'vol_enable_whatsapp_share'];
        foreach ($toggles as $t_key) {
            if (! isset($raw_input[$t_key])) {
                $merged[$t_key] = 'no';
            }
        }
    }

    $updated = update_option('dfn_volunteer_settings', $merged);
    $GLOBALS['dfn_volunteer_settings_cache'] = $merged;

    if (function_exists('dfn_log_write')) {
        dfn_log_write(
            'volontari',
            wp_get_current_user()->display_name,
            'Aggiornate impostazioni e testi email Volontari FAI',
            'success'
        );
    }

    if ($updated) {
        add_settings_error(
            'dfn_vol_settings_messages',
            'dfn_vol_settings_updated',
            __('Impostazioni Volontari salvate con successo.', 'dfn-theme'),
            'updated'
        );
    } else {
        add_settings_error(
            'dfn_vol_settings_messages',
            'dfn_vol_settings_no_change',
            __('Nessuna modifica rilevata o impostazioni già aggiornate.', 'dfn-theme'),
            'info'
        );
    }
}

/**
 * Renderizza la pagina di amministrazione "Impostazioni Volontari FAI".
 */
function dfn_render_volunteer_settings_page(): void
{
    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members')) {
        wp_die(__('Permessi insufficienti per accedere a questa sezione.', 'dfn-theme'));
    }

    // Salvataggio su POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfn_vol_settings_nonce'])) {
        dfn_volunteer_settings_save_fields();
    }

    // Mostra messaggi di notifica/errore
    if (function_exists('settings_errors')) {
        settings_errors('dfn_vol_settings_messages');
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'notifiche';
    $delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';

    $tabs = [
        'notifiche'     => '🔔 Notifiche &amp; Destinatari',
        'canali'        => '🛡️ Squadre &amp; Canali',
        'sondaggi'      => '📋 Sondaggi &amp; Logistica',
        'modelli-email' => '📝 Modelli E-mail',
        'test-invio'    => '🧪 Test Invio E-mail',
    ];
    ?>
    <style>
        .dfn-settings-wrap {
            max-width: 1380px;
        }
        .dfn-vol-accordion-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 24px;
        }
        .dfn-vol-accordion-item {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: border-color 0.15s ease;
        }
        .dfn-vol-accordion-item.is-open {
            border-color: #004b23;
            box-shadow: 0 2px 8px rgba(0,75,35,0.08);
        }
        .dfn-vol-accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            background: #f8fafc;
            cursor: pointer;
            user-select: none;
            border-bottom: 1px solid transparent;
            transition: background-color 0.15s ease;
        }
        .dfn-vol-accordion-item.is-open .dfn-vol-accordion-header {
            background: #f0fdf4;
            border-bottom: 1px solid #bbf7d0;
        }
        .dfn-vol-accordion-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
        }
        .dfn-vol-accordion-badge {
            font-size: 11.5px;
            font-weight: 600;
            color: #166534;
            background: #dcfce7;
            padding: 3px 9px;
            border-radius: 12px;
        }
        .dfn-vol-accordion-arrow {
            font-size: 12px;
            color: #64748b;
            transition: transform 0.2s ease;
        }
        .dfn-vol-accordion-item.is-open .dfn-vol-accordion-arrow {
            transform: rotate(180deg);
        }
        .dfn-vol-accordion-body {
            display: none;
            padding: 24px;
            background: #ffffff;
        }
        .dfn-vol-accordion-item.is-open .dfn-vol-accordion-body {
            display: block;
        }
        .dfn-email-builder-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1100px) {
            .dfn-email-builder-grid {
                grid-template-columns: 1fr;
            }
        }
        .dfn-form-col {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .dfn-chips-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 14px;
        }
        .dfn-chip-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            font-family: monospace;
            color: #004b23;
            cursor: pointer;
            transition: all 0.1s ease;
            user-select: none;
        }
        .dfn-chip-btn:hover {
            background: #f0fdf4;
            border-color: #004b23;
            color: #166534;
            transform: translateY(-1px);
        }
        .dfn-form-card {
            background: #fafafa;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .dfn-form-card-title {
            font-size: 13px;
            font-weight: 700;
            color: #004b23;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }
        .dfn-field-box {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .dfn-field-box label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        .dfn-field-box input[type="text"],
        .dfn-field-box textarea {
            width: 100%;
            border-radius: 6px;
            border: 1.5px solid #cbd5e1;
            padding: 8px 12px;
            font-size: 13.5px;
            line-height: 1.5;
            background: #ffffff;
            color: #0f172a;
            box-sizing: border-box;
            font-family: inherit;
        }
        .dfn-field-box input[type="text"]:focus,
        .dfn-field-box textarea:focus {
            border-color: #004b23;
            outline: none;
            box-shadow: 0 0 0 1px #004b23;
        }
        .dfn-help-text {
            font-size: 11.5px;
            color: #64748b;
            line-height: 1.4;
            margin-top: 2px;
        }
        .dfn-mockup-wrapper {
            position: sticky;
            top: 35px;
        }
        .dfn-mockup-card {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.07);
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .dfn-mockup-header-banner {
            background: #004b23;
            color: #ffffff;
            padding: 20px 24px;
            text-align: center;
            border-bottom: 4px solid #e74f30;
        }
        .dfn-mockup-header-banner h3 {
            margin: 0;
            color: #ffffff !important;
            font-size: 18px !important;
            font-weight: 700;
            letter-spacing: -0.2px;
        }
        .dfn-mockup-content {
            padding: 24px;
            color: #334155;
            font-size: 13.5px;
            line-height: 1.65;
        }
        .dfn-mockup-content p {
            margin: 0 0 14px 0;
            font-size: 13.5px;
            line-height: 1.65;
            color: #334155;
        }
        .dfn-mockup-content p:last-child {
            margin-bottom: 0;
        }
        .dfn-mockup-footer-bar {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 12px 18px;
            text-align: center;
            font-size: 11.5px;
            color: #64748b;
        }
        .dfn-mockup-content .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #004b23;
            border-radius: 6px;
            padding: 14px 18px;
            margin: 16px 0;
        }
        .dfn-mockup-content .info-box-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #004b23;
            margin-bottom: 6px;
        }
        .dfn-mockup-content .button {
            display: inline-block;
            background: #004b23;
            color: #ffffff !important;
            padding: 10px 22px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            box-shadow: 0 2px 6px rgba(0,75,35,0.25);
            border: none;
        }
    </style>

    <div class="wrap dfn-settings-wrap">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:12px;">
            <div>
                <h1 class="wp-heading-inline" style="margin:0; font-size:24px; font-weight:700;">⚙️ Impostazioni Gestione Volontari FAI</h1>
                <p class="description" style="margin:6px 0 0 0; font-size:14px; color:#64748b;">
                    Configura i destinatari delle notifiche, le regole di approvazione preventiva e compila i testi delle email attraverso i campi guidati con anteprima grafica renderizzata in tempo reale.
                </p>
            </div>
            <div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" class="button button-secondary" style="font-weight:600;">
                    &larr; <?php esc_html_e('Torna a Elenco Volontari', 'dfn-theme'); ?>
                </a>
            </div>
        </div>
        <hr class="wp-header-end">

        <div class="dfn-settings-container" style="display: flex; gap: 20px; margin-top: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;">
            
            <!-- Sidebar Navigation Tabs (Vertical) -->
            <div class="dfn-settings-sidebar" style="width: 240px; background: #f6f7f7; border-right: 1px solid #ccd0d4; flex-shrink: 0;">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php foreach ($tabs as $tab_id => $tab_label) :
                        $is_active = ($active_tab === $tab_id);
                        $tab_url = admin_url('admin.php?page=dfn-volunteer-settings&tab=' . $tab_id);
                        $bg = $is_active ? '#ffffff' : 'transparent';
                        $color = $is_active ? '#004b23' : '#2c3338';
                        $border_left = $is_active ? '4px solid #004b23' : '4px solid transparent';
                        $font_weight = $is_active ? '600' : 'normal';
                        ?>
                        <li style="margin: 0; border-bottom: 1px solid #e5e5e5;">
                            <a href="<?php echo esc_url($tab_url); ?>" style="display: block; padding: 15px 20px; color: <?php echo esc_attr($color); ?>; background-color: <?php echo esc_attr($bg); ?>; border-left: <?php echo esc_attr($border_left); ?>; font-weight: <?php echo esc_attr($font_weight); ?>; text-decoration: none; outline: none; box-shadow: none; transition: all 0.1s ease-in-out;">
                                <?php echo $tab_label; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Form Content Area -->
            <div class="dfn-settings-content" style="flex-grow: 1; padding: 30px 40px; min-width: 0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-settings&tab=' . $active_tab)); ?>">
                    <?php wp_nonce_field('dfn_save_vol_settings_action', 'dfn_vol_settings_nonce'); ?>

                    <?php if ($active_tab === 'notifiche') : ?>
                        <!-- TAB 1: NOTIFICHE & DESTINATARI -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🔔 Destinatari, Flussi &amp; Mittente</h2>
                        <p class="description" style="margin-bottom: 25px;">Configura il nome mittente delle comunicazioni, gli indirizzi di notifica per lo staff e le regole di approvazione preventiva delle candidature dei volontari.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="vol_email_sender_name">Nome Mittente Email (From Name)</label></th>
                                <td>
                                    <input name="dfn_vol_settings[vol_email_sender_name]" type="text" id="vol_email_sender_name" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_sender_name')); ?>" class="regular-text" style="max-width:480px;" placeholder="es. Coordinamento Volontari <?php echo esc_attr($delegation_name); ?>" />
                                    <p class="description"><strong>Comportamento:</strong> Il nome visualizzato come mittente nelle caselle di posta dei volontari e dei candidati quando ricevono comunicazioni (es. <em>Coordinamento Volontari FAI Novara</em>). L'indirizzo email di invio rimane quello di sistema del server SMTP per garantire la massima recapitabilità SPF/DKIM ed evitare filtri antispam.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="vol_email_admin_recipients">Destinatari Notifiche Staff</label></th>
                                <td>
                                    <input name="dfn_vol_settings[vol_email_admin_recipients]" type="text" id="vol_email_admin_recipients" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_recipients')); ?>" class="regular-text" style="max-width:480px;" />
                                    <p class="description"><strong>Comportamento:</strong> Gli indirizzi email dello staff FAI (separati da virgola se multipli) a cui inviare una notifica immediata quando un candidato compila il form di registrazione online.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Approvazione Preventiva</th>
                                <td>
                                    <label for="vol_require_approval" style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_require_approval]" id="vol_require_approval" value="yes" <?php checked(dfn_get_volunteer_setting('vol_require_approval', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Richiedi approvazione manuale dell'amministratore prima di abilitare l'accesso volontario
                                    </label>
                                    <p class="description" style="margin-top:6px;"><strong>Consigliato:</strong> Se attivo, le nuove registrazioni online entrano in stato <em>In Attesa</em>. L'utente non riceve il ruolo Volontario né l'accesso alla bacheca turni fino a quando un amministratore non approva esplicitamente la candidatura dal pannello.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Email di Ricezione al Candidato</th>
                                <td>
                                    <label for="vol_enable_candidate_pending_email" style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_enable_candidate_pending_email]" id="vol_enable_candidate_pending_email" value="yes" <?php checked(dfn_get_volunteer_setting('vol_enable_candidate_pending_email', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Invia email automatica di presa in carico al candidato al termine dell'invio del form
                                    </label>
                                    <p class="description" style="margin-top:6px;"><strong>Comportamento:</strong> Informa subito l'utente che la sua richiesta è stata ricevuta e che la candidatura è attualmente in fase di valutazione da parte dello staff.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Email di Benvenuto ad Approvazione</th>
                                <td>
                                    <label for="vol_enable_approved_email" style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_enable_approved_email]" id="vol_enable_approved_email" value="yes" <?php checked(dfn_get_volunteer_setting('vol_enable_approved_email', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Invia email di conferma e istruzioni al volontario quando la candidatura viene approvata
                                    </label>
                                    <p class="description" style="margin-top:6px;"><strong>Comportamento:</strong> Invia una mail formattata con il pulsante di accesso alla bacheca volontari, promemoria riunioni e credenziali operative quando la candidatura viene approvata.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Convocazioni Email Riunioni</th>
                                <td>
                                    <label for="vol_enable_meeting_notifications" style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_enable_meeting_notifications]" id="vol_enable_meeting_notifications" value="yes" <?php checked(dfn_get_volunteer_setting('vol_enable_meeting_notifications', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Abilita invio immediato email di convocazione quando si pubblica una nuova riunione
                                    </label>
                                    <p class="description" style="margin-top:6px;"><strong>Comportamento:</strong> Se attivo, consente di inviare la mail di convocazione formattata (plenaria o ai membri del singolo team) all'atto della pubblicazione o cliccando su <em>Invia Notifiche</em>.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Promemoria Automatici Riunioni (7gg e 1gg)</th>
                                <td>
                                    <label for="vol_enable_meeting_reminders" style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_enable_meeting_reminders]" id="vol_enable_meeting_reminders" value="yes" <?php checked(dfn_get_volunteer_setting('vol_enable_meeting_reminders', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Invia automaticamente promemoria email a 7 giorni e a 1 giorno prima dell'incontro
                                    </label>
                                    <p class="description" style="margin-top:6px;"><strong>Comportamento:</strong> Controlla l'attivazione del cron job automatico per i promemoria pre-riunione. Rispetta sempre le preferenze individuali espresse da ciascun volontario nel proprio profilo.</p>
                                </td>
                            </tr>
                        </table>

                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                            <?php submit_button(__('Salva Impostazioni', 'dfn-theme'), 'primary', 'submit', false, [ 'style' => 'background: #004b23; border-color: #003318; box-shadow: none; text-shadow: none;' ]); ?>
                        </div>

                    <?php elseif ($active_tab === 'canali') : 
                        $teams_enabled_val = dfn_get_volunteer_setting('vol_enable_teams', 'yes');
                    ?>
                        <!-- TAB: SQUADRE & CANALI DI COMUNICAZIONE (WHATSAPP & GOOGLE DRIVE) -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🛡️ Squadre di Lavoro, Canali &amp; Sotto-modulo v2.1.1</h2>
                        <p class="description" style="margin-bottom: 25px;">
                            Configura l'attivazione della gestione squadre e i canali integrati (WhatsApp e Google Drive) per i gruppi operativi di Delegazione.
                        </p>

                        <!-- MASTER SWITCH SOTTO-MODULO SQUADRE (v2.1.1) -->
                        <div style="background:#ffffff; border:2px solid <?php echo $teams_enabled_val === 'yes' ? '#86efac' : '#cbd5e1'; ?>; border-radius:10px; padding:20px; margin-bottom:24px; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
                                <div style="flex:1; min-width:280px;">
                                    <h3 style="font-size:16.5px; font-weight:800; color:#0f172a; margin:0 0 6px 0; display:flex; align-items:center; gap:8px;">
                                        <span>🧩</span> Modulo Gestione Squadre &amp; Team (v2.1.1)
                                    </h3>
                                    <p style="font-size:13.5px; color:#475569; margin:0 0 14px 0; line-height:1.5;">
                                        Permette di suddividere i volontari in gruppi operativi specializzati (es. <em>Ambiente, Comunicazione, Scuola, Guide</em>), nominare Delegati Responsabili e convocare riunioni mirate solo ai membri di quello specifico gruppo.
                                    </p>
                                    
                                    <label for="vol_enable_teams" style="display:inline-flex; align-items:center; gap:10px; font-weight:700; cursor:pointer; font-size:14.5px; background:#f8fafc; padding:10px 16px; border-radius:8px; border:1px solid #cbd5e1;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_enable_teams]" id="vol_enable_teams" value="yes" <?php checked($teams_enabled_val, 'yes'); ?> style="accent-color:#004b23; width:20px; height:20px;" />
                                        <span>Abilita Gestione Squadre, Supervisori &amp; Riunioni di Team (v2.1.1)</span>
                                    </label>
                                </div>
                                <div>
                                    <?php if ($teams_enabled_val === 'yes') : ?>
                                        <span style="background:#dcfce7; color:#166534; font-weight:700; font-size:12px; padding:4px 10px; border-radius:12px; border:1px solid #86efac; display:inline-flex; align-items:center; gap:4px;">
                                            ✅ Modulo 2.1.1 Attivo
                                        </span>
                                    <?php else : ?>
                                        <span style="background:#f1f5f9; color:#64748b; font-weight:700; font-size:12px; padding:4px 10px; border-radius:12px; border:1px solid #cbd5e1; display:inline-flex; align-items:center; gap:4px;">
                                            ⏸️ Modalità 2.1 Pura (Delegazione Unica)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="margin-top:14px; padding-top:12px; border-top:1px solid #f1f5f9; font-size:12.5px; color:#64748b; line-height:1.5;">
                                <strong>Effetto quando disattivato:</strong> Il sottomenu <em>Squadre &amp; Team</em> viene nascosto, l'elenco e l'inserimento volontari non richiedono l'assegnazione di gruppo e tutte le riunioni funzionano esclusivamente in modalità Plenaria per l'intera delegazione.
                            </div>
                        </div>

                        <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <h3 style="font-size:16px; font-weight:700; color:#0f172a; margin:0 0 8px 0; display:flex; align-items:center; gap:8px;">
                                <span>💬</span> Condivisione Rapida WhatsApp per Delegati &amp; Staff (Livello 2)
                            </h3>
                            <p style="font-size:13.5px; color:#475569; margin-bottom:16px; line-height:1.5;">
                                Se abilitato, aggiunge un pulsante <strong>"Condividi su WhatsApp"</strong> nella gestione squadre e nelle schede dei Delegati per generare link precompilati (Click-to-Chat) con messaggi pronti all'invio per il gruppo o i singoli volontari.
                            </p>

                            <table class="form-table" role="presentation" style="margin-top:0;">
                                <tr>
                                    <th scope="row" style="padding-top:10px; width:260px;">Pulsanti Condivisione WhatsApp</th>
                                    <td style="padding-top:10px;">
                                        <label for="vol_enable_whatsapp_share" style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; font-size:14px;">
                                            <input type="checkbox" name="dfn_vol_settings[vol_enable_whatsapp_share]" id="vol_enable_whatsapp_share" value="yes" <?php checked(dfn_get_volunteer_setting('vol_enable_whatsapp_share', 'no'), 'yes'); ?> style="accent-color:#004b23; width:18px; height:18px;" />
                                            Abilita strumenti di condivisione rapida e Click-to-Chat WhatsApp nel portale
                                        </label>
                                        <p class="description" style="margin-top:8px;">
                                            <strong>Nota:</strong> I volontari possono sempre accedere direttamente al gruppo WhatsApp del proprio team tramite il pulsante visualizzato nella loro area riservata. Questa opzione attiva pulsanti aggiuntivi di inoltro rapido per i Delegati.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" style="padding-top:10px;"><label for="vol_whatsapp_share_template">Messaggio Predefinito</label></th>
                                    <td style="padding-top:10px;">
                                        <textarea name="dfn_vol_settings[vol_whatsapp_share_template]" id="vol_whatsapp_share_template" rows="3" class="large-text" style="max-width:540px; font-family:monospace; font-size:12.5px;"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_whatsapp_share_template')); ?></textarea>
                                        <p class="description">Tag disponibili: <code>{squadra}</code>, <code>{delegazione}</code>, <code>{link_accesso}</code></p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:18px 20px;">
                            <h4 style="margin:0 0 6px 0; font-size:14px; font-weight:700; color:#0f172a;">📁 Come configurare i canali per ciascuna squadra:</h4>
                            <ol style="margin:0 0 0 20px; padding:0; font-size:13px; color:#475569; line-height:1.6;">
                                <li>Accedi al sottomenu <strong>Volontari FAI &rarr; <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-teams')); ?>" style="color:#004b23; font-weight:700;">Squadre &amp; Team</a></strong>.</li>
                                <li>Clicca su <strong>Modifica</strong> (✏️) sulla card della squadra desiderata.</li>
                                <li>Inserisci il <strong>Link di invito al Gruppo WhatsApp</strong> (es. <code>https://chat.whatsapp.com/...</code>) e l'<strong>URL della Cartella Google Drive</strong> (es. <code>https://drive.google.com/drive/folders/...</code>).</li>
                                <li>I pulsanti di accesso diretto compariranno automaticamente nella bacheca <strong>/mio-account/</strong> solo per i volontari assegnati a quel team!</li>
                            </ol>
                        </div>

                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                            <?php submit_button(__('Salva Impostazioni', 'dfn-theme'), 'primary', 'submit', false, [ 'style' => 'background: #004b23; border-color: #003318; box-shadow: none; text-shadow: none;' ]); ?>
                        </div>

                    <?php elseif ($active_tab === 'sondaggi') : ?>
                        <!-- TAB: SONDAGGI & ASSEGNAZIONE TURNI -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">📋 Gestione Sondaggi &amp; Assegnazione Turni</h2>
                        <p class="description" style="margin-bottom: 25px;">Configura i campi opzionali del sondaggio di disponibilità e i parametri di calcolo dell'algoritmo di assegnazione automatica.</p>

                        <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:20px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                            <h3 style="margin-top:0; color:#0f172a; font-size:16px; display:flex; align-items:center; gap:8px;">
                                🏛️ Selezione Esplicita della Preferenza Luogo
                            </h3>
                            <p style="font-size:13.5px; color:#475569; margin-bottom:16px; line-height:1.5;">
                                Permette ai volontari di esprimere una preferenza specifica sul luogo dell'evento in cui desiderano prestare servizio.
                            </p>

                            <table class="form-table" role="presentation" style="margin-top:0;">
                                <tr>
                                    <th scope="row" style="padding-top:10px; width:260px;">Campo Preferenza Luogo</th>
                                    <td style="padding-top:10px;">
                                        <label for="vol_survey_enable_preferred_place" style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; font-size:14px;">
                                            <input type="checkbox" name="dfn_vol_settings[vol_survey_enable_preferred_place]" id="vol_survey_enable_preferred_place" value="yes" <?php checked(dfn_get_volunteer_setting('vol_survey_enable_preferred_place', 'no'), 'yes'); ?> style="accent-color:#004b23; width:18px; height:18px;" />
                                            Abilita il menu a tendina "Preferenza Luogo Desiderato (Opzionale)" nei sondaggi
                                        </label>
                                        <div class="description" style="margin-top:8px;">
                                            <strong>Come Funziona:</strong>
                                            <ul style="margin:6px 0 0 18px; list-style:disc; font-size:12.5px; color:#64748b; line-height:1.6;">
                                                <li><strong>Nei form pubblici del sondaggio:</strong> i volontari visualizzeranno un menu a tendina con l'elenco dei beni/luoghi dell'evento (es. <em>Palazzo Natta, Mirato, Castello...</em>).</li>
                                                <li><strong>Nell'inserimento manuale admin:</strong> gli operatori potranno selezionare direttamente il bene richiesto dal volontario.</li>
                                                <li><strong>Nell'Algoritmo Intelligente:</strong> l'assegnazione automatica darà precedenza assoluta al luogo selezionato, controllando la capienza oraria e ancorando il volontario allo stesso luogo per tutta la giornata.</li>
                                                <li><strong>Smart Fallback:</strong> Se disattivato o se il volontario lascia il campo su <em>"Indifferente"</em>, l'algoritmo effettua l'analisi semantica del testo libero delle note (es. rilevando richieste come <em>"preferisco Mirato"</em>) e bilancia equamente i carichi.</li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                            <?php submit_button(__('Salva Impostazioni', 'dfn-theme'), 'primary', 'submit', false, [ 'style' => 'background: #004b23; border-color: #003318; box-shadow: none; text-shadow: none;' ]); ?>
                        </div>

                    <?php elseif ($active_tab === 'modelli-email') : ?>
                        <!-- TAB 2: MODELLI EMAIL CON COMPILAZIONE GUIDATA E ANTEPRIMA LIVE -->
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; border-bottom:1.5px solid #e2e8f0; padding-bottom:12px; margin-bottom:18px;">
                            <div>
                                <h2 style="color:#004b23; margin:0 0 4px; font-size:20px; font-weight:700;">📝 Modelli E-mail Volontari (Compilazione Guidata &amp; Anteprima Live)</h2>
                                <p class="description" style="margin:0; font-size:13.5px; color:#64748b;">
                                    Personalizza i testi di tutte le e-mail senza dover conoscere il codice HTML: compila i campi guidati a sinistra e visualizza l'anteprima grafica renderizzata in tempo reale sulla destra.
                                </p>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="button button-secondary" id="dfn-expand-all-vol-emails" style="font-size:12px;">📂 Espandi Tutti</button>
                                <button type="button" class="button button-secondary" id="dfn-collapse-all-vol-emails" style="font-size:12px;">📁 Comprimi Tutti</button>
                            </div>
                        </div>

                        <div class="dfn-vol-accordion-list">
                            <!-- ========================================================= -->
                            <!-- 1. NOTIFICA ADMIN -->
                            <!-- ========================================================= -->
                            <div class="dfn-vol-accordion-item is-open" id="dfn-vol-accordion-1">
                                <div class="dfn-vol-accordion-header">
                                    <div class="dfn-vol-accordion-title-wrap">
                                        <span>👤 1. Notifica Nuova Candidatura (allo Staff / Amministratore)</span>
                                        <span class="dfn-vol-accordion-badge">Inviata allo Staff FAI</span>
                                    </div>
                                    <span class="dfn-vol-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                                </div>
                                <div class="dfn-vol-accordion-body">
                                    <div class="dfn-chips-bar">
                                        <span style="font-size:12px; font-weight:700; color:#004b23; margin-right:4px;">🏷️ Segnaposto dinamici:</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{nome}">+ {nome}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{cognome}">+ {cognome}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{email}">+ {email}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{telefono}">+ {telefono}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{tessera_fai}">+ {tessera_fai}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{mansioni}">+ {mansioni}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{delegazione}">+ {delegazione}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{data_richiesta}">+ {data_richiesta}</span>
                                        <span class="dfn-chip-btn" data-card="1" data-tag="{link_admin}">+ {link_admin}</span>
                                    </div>

                                    <div class="dfn-email-builder-grid">
                                        <!-- Form Col -->
                                        <div class="dfn-form-col">
                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">📧 Oggetto &amp; Intestazione</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_admin_subject">Oggetto E-mail</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_admin_subject]" id="vol_email_admin_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_subject')); ?>" class="dfn-input-watch" data-card="1" />
                                                    <div class="dfn-help-text">Oggetto visibile nella casella di posta dello staff.</div>
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_admin_title">Titolo Banner Verde (Header)</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_admin_title]" id="vol_email_admin_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_title')); ?>" class="dfn-input-watch" data-card="1" />
                                                    <div class="dfn-help-text">Titolo principale stampato in alto nel banner verde.</div>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">📝 Messaggio Introduttivo</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_admin_intro">Testo di apertura</label>
                                                    <textarea name="dfn_vol_settings[vol_email_admin_intro]" id="vol_email_admin_intro" rows="3" class="dfn-input-watch" data-card="1"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_admin_intro')); ?></textarea>
                                                    <div class="dfn-help-text">Saluto iniziale allo staff. I doppi a capo creano nuovi paragrafi.</div>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">📋 Riquadro Dati Candidato</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_admin_box_title">Titolo del Riquadro Dati</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_admin_box_title]" id="vol_email_admin_box_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_box_title')); ?>" class="dfn-input-watch" data-card="1" />
                                                    <div class="dfn-help-text">I dati anagrafici del candidato vengono formattati automaticamente all'interno di questo box.</div>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">🔘 Azione e Istruzioni Staff</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_admin_instructions">Istruzioni di revisione</label>
                                                    <textarea name="dfn_vol_settings[vol_email_admin_instructions]" id="vol_email_admin_instructions" rows="2" class="dfn-input-watch" data-card="1"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_admin_instructions')); ?></textarea>
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_admin_btn_text">Testo del Pulsante di Valutazione</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_admin_btn_text]" id="vol_email_admin_btn_text" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_btn_text')); ?>" class="dfn-input-watch" data-card="1" />
                                                    <div class="dfn-help-text">Collega direttamente alla schermata di approvazione nel pannello WordPress.</div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Preview Col -->
                                        <div class="dfn-mockup-wrapper">
                                            <div style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                                <span>✨ Anteprima Grafica Live (in tempo reale)</span>
                                            </div>
                                            <div class="dfn-mockup-card">
                                                <div class="dfn-mockup-header-banner">
                                                    <h3 id="dfn-preview-title-1"></h3>
                                                </div>
                                                <div class="dfn-mockup-content" id="dfn-preview-body-1"></div>
                                                <div class="dfn-mockup-footer-bar">
                                                    FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ========================================================= -->
                            <!-- 2. RICEZIONE CANDIDATO (PENDING) -->
                            <!-- ========================================================= -->
                            <div class="dfn-vol-accordion-item is-open" id="dfn-vol-accordion-2">
                                <div class="dfn-vol-accordion-header">
                                    <div class="dfn-vol-accordion-title-wrap">
                                        <span>⏳ 2. Ricezione Candidatura (al Candidato - In Attesa di Verifica)</span>
                                        <span class="dfn-vol-accordion-badge" style="color:#0369a1; background:#e0f2fe;">Inviata al Candidato</span>
                                    </div>
                                    <span class="dfn-vol-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                                </div>
                                <div class="dfn-vol-accordion-body">
                                    <div class="dfn-chips-bar">
                                        <span style="font-size:12px; font-weight:700; color:#004b23; margin-right:4px;">🏷️ Segnaposto dinamici:</span>
                                        <span class="dfn-chip-btn" data-card="2" data-tag="{nome}">+ {nome}</span>
                                        <span class="dfn-chip-btn" data-card="2" data-tag="{cognome}">+ {cognome}</span>
                                        <span class="dfn-chip-btn" data-card="2" data-tag="{email}">+ {email}</span>
                                        <span class="dfn-chip-btn" data-card="2" data-tag="{delegazione}">+ {delegazione}</span>
                                    </div>

                                    <div class="dfn-email-builder-grid">
                                        <!-- Form Col -->
                                        <div class="dfn-form-col">
                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">📧 Oggetto &amp; Intestazione</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_pending_subject">Oggetto E-mail</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_pending_subject]" id="vol_email_pending_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_subject')); ?>" class="dfn-input-watch" data-card="2" />
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_pending_title">Titolo Banner Verde (Header)</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_pending_title]" id="vol_email_pending_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_title')); ?>" class="dfn-input-watch" data-card="2" />
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">👋 Saluto &amp; Messaggio Iniziale</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_pending_intro">Testo di apertura</label>
                                                    <textarea name="dfn_vol_settings[vol_email_pending_intro]" id="vol_email_pending_intro" rows="3" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_intro')); ?></textarea>
                                                    <div class="dfn-help-text">Puoi usare <code>{nome}</code> per personalizzare il saluto (es. <em>Gentile {nome},</em>).</div>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">📋 Riquadro Informativo di Verifica</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_pending_box_title">Titolo del Riquadro</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_pending_box_title]" id="vol_email_pending_box_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_box_title')); ?>" class="dfn-input-watch" data-card="2" />
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_pending_box_text">Contenuto del Riquadro (Passaggi successivi)</label>
                                                    <textarea name="dfn_vol_settings[vol_email_pending_box_text]" id="vol_email_pending_box_text" rows="3" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_box_text')); ?></textarea>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">✍️ Chiusura &amp; Firma</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_pending_closing">Messaggio di ringraziamento finale</label>
                                                    <textarea name="dfn_vol_settings[vol_email_pending_closing]" id="vol_email_pending_closing" rows="2" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_closing')); ?></textarea>
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_pending_signature">Firma / Saluti</label>
                                                    <textarea name="dfn_vol_settings[vol_email_pending_signature]" id="vol_email_pending_signature" rows="2" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_signature')); ?></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Preview Col -->
                                        <div class="dfn-mockup-wrapper">
                                            <div style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                                <span>✨ Anteprima Grafica Live (in tempo reale)</span>
                                            </div>
                                            <div class="dfn-mockup-card">
                                                <div class="dfn-mockup-header-banner">
                                                    <h3 id="dfn-preview-title-2"></h3>
                                                </div>
                                                <div class="dfn-mockup-content" id="dfn-preview-body-2"></div>
                                                <div class="dfn-mockup-footer-bar">
                                                    FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ========================================================= -->
                            <!-- 3. VOLONTARIO APPROVATO -->
                            <!-- ========================================================= -->
                            <div class="dfn-vol-accordion-item is-open" id="dfn-vol-accordion-3">
                                <div class="dfn-vol-accordion-header">
                                    <div class="dfn-vol-accordion-title-wrap">
                                        <span>🎉 3. Email di Approvazione &amp; Benvenuto (al Volontario Approvato)</span>
                                        <span class="dfn-vol-accordion-badge" style="color:#15803d; background:#dcfce7;">Inviata ad Approvazione</span>
                                    </div>
                                    <span class="dfn-vol-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                                </div>
                                <div class="dfn-vol-accordion-body">
                                    <div class="dfn-chips-bar">
                                        <span style="font-size:12px; font-weight:700; color:#004b23; margin-right:4px;">🏷️ Segnaposto dinamici:</span>
                                        <span class="dfn-chip-btn" data-card="3" data-tag="{nome}">+ {nome}</span>
                                        <span class="dfn-chip-btn" data-card="3" data-tag="{cognome}">+ {cognome}</span>
                                        <span class="dfn-chip-btn" data-card="3" data-tag="{email}">+ {email}</span>
                                        <span class="dfn-chip-btn" data-card="3" data-tag="{delegazione}">+ {delegazione}</span>
                                        <span class="dfn-chip-btn" data-card="3" data-tag="{link_accesso}">+ {link_accesso}</span>
                                    </div>

                                    <div class="dfn-email-builder-grid">
                                        <!-- Form Col -->
                                        <div class="dfn-form-col">
                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">📧 Oggetto &amp; Intestazione</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_subject">Oggetto E-mail</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_approved_subject]" id="vol_email_approved_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_subject')); ?>" class="dfn-input-watch" data-card="3" />
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_title">Titolo Banner Verde (Header)</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_approved_title]" id="vol_email_approved_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_title')); ?>" class="dfn-input-watch" data-card="3" />
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">🎉 Saluto &amp; Congratulazioni</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_intro">Testo di congratulazioni e benvenuto</label>
                                                    <textarea name="dfn_vol_settings[vol_email_approved_intro]" id="vol_email_approved_intro" rows="3" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_intro')); ?></textarea>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">🏛️ Riquadro Opportunità &amp; Bacheca Volontari</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_box_title">Titolo del Riquadro</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_approved_box_title]" id="vol_email_approved_box_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_box_title')); ?>" class="dfn-input-watch" data-card="3" />
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_box_bullets">Punti Elenco (Cosa può fare il volontario)</label>
                                                    <textarea name="dfn_vol_settings[vol_email_approved_box_bullets]" id="vol_email_approved_box_bullets" rows="4" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_box_bullets')); ?></textarea>
                                                    <div class="dfn-help-text">💡 Inserisci una voce per riga: il sistema creerà automaticamente la lista puntata formattata.</div>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">🔘 Accesso &amp; Credenziali</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_btn_text">Testo del Pulsante di Accesso</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_approved_btn_text]" id="vol_email_approved_btn_text" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_btn_text')); ?>" class="dfn-input-watch" data-card="3" />
                                                    <div class="dfn-help-text">Collega direttamente alla bacheca volontario nell'area riservata.</div>
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_notes">Nota credenziali di accesso</label>
                                                    <textarea name="dfn_vol_settings[vol_email_approved_notes]" id="vol_email_approved_notes" rows="2" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_notes')); ?></textarea>
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_approved_signature">Firma / Saluti Finali</label>
                                                    <textarea name="dfn_vol_settings[vol_email_approved_signature]" id="vol_email_approved_signature" rows="2" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_signature')); ?></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Preview Col -->
                                        <div class="dfn-mockup-wrapper">
                                            <div style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                                <span>✨ Anteprima Grafica Live (in tempo reale)</span>
                                            </div>
                                            <div class="dfn-mockup-card">
                                                <div class="dfn-mockup-header-banner">
                                                    <h3 id="dfn-preview-title-3"></h3>
                                                </div>
                                                <div class="dfn-mockup-content" id="dfn-preview-body-3"></div>
                                                <div class="dfn-mockup-footer-bar">
                                                    FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ========================================================= -->
                            <!-- 4. EMAIL CREDENZIALI & BENVEUTO (INVIO / REINVIO)       -->
                            <!-- ========================================================= -->
                            <div class="dfn-vol-accordion-item" id="dfn-vol-accordion-4">
                                <div class="dfn-vol-accordion-header">
                                    <div class="dfn-vol-accordion-title-wrap">
                                        <span>🔐 4. Email Credenziali Account &amp; Impostazione Password (Invio / Reinvio)</span>
                                        <span class="dfn-vol-accordion-badge" style="color:#0369a1; background:#e0f2fe;">Reinvio Credenziali</span>
                                    </div>
                                    <span class="dfn-vol-accordion-arrow dashicons dashicons-arrow-down-alt2"></span>
                                </div>
                                <div class="dfn-vol-accordion-body">
                                    <div class="dfn-chips-bar">
                                        <span style="font-size:12px; font-weight:700; color:#004b23; margin-right:4px;">🏷️ Segnaposto dinamici:</span>
                                        <span class="dfn-chip-btn" data-card="4" data-tag="{nome}">+ {nome}</span>
                                        <span class="dfn-chip-btn" data-card="4" data-tag="{cognome}">+ {cognome}</span>
                                        <span class="dfn-chip-btn" data-card="4" data-tag="{email}">+ {email}</span>
                                        <span class="dfn-chip-btn" data-card="4" data-tag="{username}">+ {username}</span>
                                        <span class="dfn-chip-btn" data-card="4" data-tag="{delegazione}">+ {delegazione}</span>
                                        <span class="dfn-chip-btn" data-card="4" data-tag="{link_accesso}">+ {link_accesso}</span>
                                        <span class="dfn-chip-btn" data-card="4" data-tag="{link_imposta_password}">+ {link_imposta_password}</span>
                                    </div>

                                    <div class="dfn-email-builder-grid">
                                        <!-- Form Col -->
                                        <div class="dfn-form-col">
                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">📧 Oggetto &amp; Intestazione</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_subject">Oggetto E-mail</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_credentials_subject]" id="vol_email_credentials_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_credentials_subject')); ?>" class="dfn-input-watch" data-card="4" />
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_title">Titolo Banner Verde (Header)</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_credentials_title]" id="vol_email_credentials_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_credentials_title')); ?>" class="dfn-input-watch" data-card="4" />
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">👋 Saluto &amp; Benvenuto</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_intro">Testo Introduttivo</label>
                                                    <textarea name="dfn_vol_settings[vol_email_credentials_intro]" id="vol_email_credentials_intro" rows="3" class="dfn-input-watch" data-card="4"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_credentials_intro')); ?></textarea>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">🔐 Riquadro Credenziali &amp; Pulsante Password</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_box_title">Titolo del Riquadro Credenziali</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_credentials_box_title]" id="vol_email_credentials_box_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_credentials_box_title')); ?>" class="dfn-input-watch" data-card="4" />
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_btn_text">Testo del Pulsante Imposta Password</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_credentials_btn_text]" id="vol_email_credentials_btn_text" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_credentials_btn_text')); ?>" class="dfn-input-watch" data-card="4" />
                                                    <div class="dfn-help-text">Collega al form di impostazione password personale e sicuro.</div>
                                                </div>
                                            </div>

                                            <div class="dfn-form-card">
                                                <div class="dfn-form-card-title">✨ Riquadro Opportunità &amp; Bacheca Volontari</div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_info_title">Titolo del Riquadro Opportunità</label>
                                                    <input type="text" name="dfn_vol_settings[vol_email_credentials_info_title]" id="vol_email_credentials_info_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_credentials_info_title')); ?>" class="dfn-input-watch" data-card="4" />
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_info_bullets">Punti Elenco (Cosa può fare il volontario)</label>
                                                    <textarea name="dfn_vol_settings[vol_email_credentials_info_bullets]" id="vol_email_credentials_info_bullets" rows="4" class="dfn-input-watch" data-card="4"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_credentials_info_bullets')); ?></textarea>
                                                    <div class="dfn-help-text">💡 Inserisci una voce per riga: il sistema creerà automaticamente la lista puntata formattata.</div>
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_notes">Note di sicurezza e validità link</label>
                                                    <textarea name="dfn_vol_settings[vol_email_credentials_notes]" id="vol_email_credentials_notes" rows="2" class="dfn-input-watch" data-card="4"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_credentials_notes')); ?></textarea>
                                                </div>
                                                <div class="dfn-field-box">
                                                    <label for="vol_email_credentials_signature">Firma / Saluti Finali</label>
                                                    <textarea name="dfn_vol_settings[vol_email_credentials_signature]" id="vol_email_credentials_signature" rows="2" class="dfn-input-watch" data-card="4"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_credentials_signature')); ?></textarea>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Preview Col -->
                                        <div class="dfn-mockup-wrapper">
                                            <div style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                                <span>✨ Anteprima Grafica Live (in tempo reale)</span>
                                            </div>
                                            <div class="dfn-mockup-card">
                                                <div class="dfn-mockup-header-banner">
                                                    <h3 id="dfn-preview-title-4"></h3>
                                                </div>
                                                <div class="dfn-mockup-content" id="dfn-preview-body-4"></div>
                                                <div class="dfn-mockup-footer-bar">
                                                    FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:13.5px; color:#64748b;">💾 Modifica i testi guidati e clicca su Salva per confermare.</span>
                            <?php submit_button(__('Salva Tutti i Modelli Email', 'dfn-theme'), 'primary', 'submit', false, [ 'style' => 'background: #004b23; border-color: #003318; box-shadow: none; text-shadow: none;' ]); ?>
                        </div>

                        <!-- JAVASCRIPT LIVE PREVIEW & ACCORDION BEHAVIOR -->
                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var sampleData = {
                                '{nome}': 'Mario',
                                '{cognome}': 'Rossi',
                                '{email}': 'mario.rossi@email.it',
                                '{telefono}': '+39 333 1234567',
                                '{tessera_fai}': '12345678',
                                '{mansioni}': '🏛️ Guida Culturale / Cicerone, 🦺 Corso Sicurezza',
                                '{delegazione}': '<?php echo esc_js($delegation_name); ?>',
                                '{citta}': 'FAI - Delegazione di Novara',
                                '{data_richiesta}': '<?php echo esc_js(current_time('d/m/Y H:i')); ?>',
                                '{link_accesso}': '#preview-link',
                                '{link_admin}': '#preview-admin-link'
                            };

                            function escapeHtml(str) {
                                if (!str) return '';
                                var div = document.createElement('div');
                                div.textContent = str;
                                return div.innerHTML;
                            }

                            function applyPlaceholders(str) {
                                if (!str) return '';
                                for (var key in sampleData) {
                                    var regex = new RegExp(key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
                                    str = str.replace(regex, sampleData[key]);
                                }
                                return str;
                            }

                            function formatParagraphs(text) {
                                if (!text || !text.trim()) return '';
                                var parts = text.split(/\n\s*\n/);
                                return parts.map(function(p) {
                                    p = p.trim();
                                    return p ? '<p style="margin:0 0 14px; font-size:13.5px; line-height:1.6; color:#2d3748;">' + escapeHtml(p).replace(/\n/g, '<br>') + '</p>' : '';
                                }).join('');
                            }

                            function renderPreviewCard1() {
                                var titleEl   = document.getElementById('vol_email_admin_title');
                                var introEl   = document.getElementById('vol_email_admin_intro');
                                var boxTEl    = document.getElementById('vol_email_admin_box_title');
                                var instrEl   = document.getElementById('vol_email_admin_instructions');
                                var btnEl     = document.getElementById('vol_email_admin_btn_text');

                                var titleTarget = document.getElementById('dfn-preview-title-1');
                                var bodyTarget  = document.getElementById('dfn-preview-body-1');
                                if (!titleTarget || !bodyTarget) return;

                                var title    = applyPlaceholders(titleEl ? titleEl.value : '');
                                var intro    = applyPlaceholders(introEl ? introEl.value : '');
                                var boxTitle = applyPlaceholders(boxTEl ? boxTEl.value : '');
                                var instr    = applyPlaceholders(instrEl ? instrEl.value : '');
                                var btnText  = applyPlaceholders(btnEl ? btnEl.value : '');

                                titleTarget.textContent = title || 'Nuova Candidatura Volontario FAI';

                                var html = formatParagraphs(intro);

                                html += '<div class="info-box">';
                                if (boxTitle) {
                                    html += '<div class="info-box-title">' + escapeHtml(boxTitle) + '</div>';
                                }
                                html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
                                html += '<tr><td style="padding:4px 0; font-weight:600; width:110px; color:#475569;">Candidato:</td><td style="padding:4px 0; font-weight:600; color:#0f172a;">Mario Rossi</td></tr>';
                                html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Email:</td><td style="padding:4px 0;"><a href="mailto:mario.rossi@email.it" style="color:#004b23; font-weight:600;">mario.rossi@email.it</a></td></tr>';
                                html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Telefono:</td><td style="padding:4px 0; color:#0f172a;">+39 333 1234567</td></tr>';
                                html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Disponibilità:</td><td style="padding:4px 0; color:#0f172a;">🏛️ Guida Culturale / Cicerone, 🦺 Corso Sicurezza</td></tr>';
                                html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Data invio:</td><td style="padding:4px 0; color:#0f172a;">' + sampleData['{data_richiesta}'] + '</td></tr>';
                                html += '</table></div>';

                                if (instr) {
                                    html += formatParagraphs(instr);
                                }

                                if (btnText) {
                                    html += '<div style="text-align:center; margin:20px 0;"><a href="#" class="button" onclick="return false;">' + escapeHtml(btnText) + '</a></div>';
                                }

                                bodyTarget.innerHTML = html;
                            }

                            function renderPreviewCard2() {
                                var titleEl    = document.getElementById('vol_email_pending_title');
                                var introEl    = document.getElementById('vol_email_pending_intro');
                                var boxTEl     = document.getElementById('vol_email_pending_box_title');
                                var boxTextEl  = document.getElementById('vol_email_pending_box_text');
                                var closingEl  = document.getElementById('vol_email_pending_closing');
                                var sigEl      = document.getElementById('vol_email_pending_signature');

                                var titleTarget = document.getElementById('dfn-preview-title-2');
                                var bodyTarget  = document.getElementById('dfn-preview-body-2');
                                if (!titleTarget || !bodyTarget) return;

                                var title    = applyPlaceholders(titleEl ? titleEl.value : '');
                                var intro    = applyPlaceholders(introEl ? introEl.value : '');
                                var boxTitle = applyPlaceholders(boxTEl ? boxTEl.value : '');
                                var boxText  = applyPlaceholders(boxTextEl ? boxTextEl.value : '');
                                var closing  = applyPlaceholders(closingEl ? closingEl.value : '');
                                var sig      = applyPlaceholders(sigEl ? sigEl.value : '');

                                titleTarget.textContent = title || 'Grazie per la tua candidatura!';

                                var html = formatParagraphs(intro);

                                if (boxTitle || boxText) {
                                    html += '<div class="info-box" style="border-left-color:#166534;">';
                                    if (boxTitle) {
                                        html += '<div class="info-box-title" style="color:#166534;">' + escapeHtml(boxTitle) + '</div>';
                                    }
                                    if (boxText) {
                                        html += '<p style="margin:0; font-size:13.5px; color:#334155; line-height:1.5;">' + escapeHtml(boxText).replace(/\n/g, '<br>') + '</p>';
                                    }
                                    html += '</div>';
                                }

                                if (closing) {
                                    html += formatParagraphs(closing);
                                }

                                if (sig) {
                                    html += '<p style="margin-top:18px; font-size:13.5px; color:#2d3748; line-height:1.5;">' + escapeHtml(sig).replace(/\n/g, '<br>') + '</p>';
                                }

                                bodyTarget.innerHTML = html;
                            }

                            function renderPreviewCard3() {
                                var titleEl   = document.getElementById('vol_email_approved_title');
                                var introEl   = document.getElementById('vol_email_approved_intro');
                                var boxTEl    = document.getElementById('vol_email_approved_box_title');
                                var bulletsEl = document.getElementById('vol_email_approved_box_bullets');
                                var btnEl     = document.getElementById('vol_email_approved_btn_text');
                                var notesEl   = document.getElementById('vol_email_approved_notes');
                                var sigEl     = document.getElementById('vol_email_approved_signature');

                                var titleTarget = document.getElementById('dfn-preview-title-3');
                                var bodyTarget  = document.getElementById('dfn-preview-body-3');
                                if (!titleTarget || !bodyTarget) return;

                                var title    = applyPlaceholders(titleEl ? titleEl.value : '');
                                var intro    = applyPlaceholders(introEl ? introEl.value : '');
                                var boxTitle = applyPlaceholders(boxTEl ? boxTEl.value : '');
                                var bullets  = applyPlaceholders(bulletsEl ? bulletsEl.value : '');
                                var btnText  = applyPlaceholders(btnEl ? btnEl.value : '');
                                var notes    = applyPlaceholders(notesEl ? notesEl.value : '');
                                var sig      = applyPlaceholders(sigEl ? sigEl.value : '');

                                titleTarget.textContent = title || 'La tua candidatura è stata approvata!';

                                var html = formatParagraphs(intro);

                                if (boxTitle || bullets) {
                                    html += '<div class="info-box">';
                                    if (boxTitle) {
                                        html += '<div class="info-box-title">' + escapeHtml(boxTitle) + '</div>';
                                    }
                                    if (bullets) {
                                        var lines = bullets.split(/\r?\n/);
                                        var items = lines.map(function(l) {
                                            var clean = l.replace(/^[\s•\-\*]+/, '').trim();
                                            return clean ? '<li style="margin-bottom:5px;">' + escapeHtml(clean) + '</li>' : '';
                                        }).filter(Boolean);
                                        if (items.length) {
                                            html += '<ul style="margin:0; padding-left:18px; color:#334155; line-height:1.6; font-size:13px;">' + items.join('') + '</ul>';
                                        }
                                    }
                                    html += '</div>';
                                }

                                if (btnText) {
                                    html += '<div style="text-align:center; margin:22px 0;"><a href="#" class="button" onclick="return false;">' + escapeHtml(btnText) + '</a></div>';
                                }

                                if (notes) {
                                    html += '<p style="font-size:13px; color:#64748b; line-height:1.5;"><em>' + escapeHtml(notes).replace(/\n/g, '<br>') + '</em></p>';
                                }

                                if (sig) {
                                    html += '<p style="margin-top:18px; font-size:13.5px; color:#2d3748; line-height:1.5;">' + escapeHtml(sig).replace(/\n/g, '<br>') + '</p>';
                                }

                                bodyTarget.innerHTML = html;
                            }

                            function renderPreviewCard4() {
                                var titleEl   = document.getElementById('vol_email_credentials_title');
                                var introEl   = document.getElementById('vol_email_credentials_intro');
                                var boxTEl    = document.getElementById('vol_email_credentials_box_title');
                                var btnEl     = document.getElementById('vol_email_credentials_btn_text');
                                var infoTEl   = document.getElementById('vol_email_credentials_info_title');
                                var bulletsEl = document.getElementById('vol_email_credentials_info_bullets');
                                var notesEl   = document.getElementById('vol_email_credentials_notes');
                                var sigEl     = document.getElementById('vol_email_credentials_signature');

                                var titleTarget = document.getElementById('dfn-preview-title-4');
                                var bodyTarget  = document.getElementById('dfn-preview-body-4');
                                if (!titleTarget || !bodyTarget) return;

                                var title    = applyPlaceholders(titleEl ? titleEl.value : '');
                                var intro    = applyPlaceholders(introEl ? introEl.value : '');
                                var boxTitle = applyPlaceholders(boxTEl ? boxTEl.value : '');
                                var btnText  = applyPlaceholders(btnEl ? btnEl.value : '');
                                var infoTitle= applyPlaceholders(infoTEl ? infoTEl.value : '');
                                var bullets  = applyPlaceholders(bulletsEl ? bulletsEl.value : '');
                                var notes    = applyPlaceholders(notesEl ? notesEl.value : '');
                                var sig      = applyPlaceholders(sigEl ? sigEl.value : '');

                                titleTarget.textContent = title || 'Benvenuto nella Squadra Volontari FAI!';

                                var html = formatParagraphs(intro);

                                html += '<div class="info-box">';
                                if (boxTitle) {
                                    html += '<div class="info-box-title">' + escapeHtml(boxTitle) + '</div>';
                                }
                                html += '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
                                html += '<tr><td style="padding:4px 0; font-weight:600; width:120px; color:#475569;">👤 Nome Utente:</td><td style="padding:4px 0; font-weight:700; color:#0f172a;"><code>mario.rossi</code></td></tr>';
                                html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">📧 Email:</td><td style="padding:4px 0; color:#0f172a;">mario.rossi@email.it</td></tr>';
                                html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">🔑 Password:</td><td style="padding:4px 0; color:#15803d; font-weight:600;">Da impostare tramite il link qui sotto</td></tr>';
                                html += '</table>';
                                html += '</div>';

                                if (btnText) {
                                    html += '<div style="text-align:center; margin:22px 0;"><a href="#" class="button" onclick="return false;">' + escapeHtml(btnText) + '</a></div>';
                                }

                                if (infoTitle || bullets) {
                                    html += '<div style="background-color:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #166534; padding:14px 16px; margin:18px 0; border-radius:6px;">';
                                    if (infoTitle) {
                                        html += '<div style="font-weight:700; font-size:13.5px; color:#166534; margin-bottom:8px;">' + escapeHtml(infoTitle) + '</div>';
                                    }
                                    if (bullets) {
                                        var lines = bullets.split(/\r?\n/);
                                        var items = lines.map(function(l) {
                                            var clean = l.replace(/^[\s•\-\*]+/, '').trim();
                                            return clean ? '<li style="margin-bottom:5px;">' + escapeHtml(clean) + '</li>' : '';
                                        }).filter(Boolean);
                                        if (items.length) {
                                            html += '<ul style="margin:0; padding-left:18px; color:#334155; line-height:1.6; font-size:13px;">' + items.join('') + '</ul>';
                                        }
                                    }
                                    html += '</div>';
                                }

                                if (notes) {
                                    html += '<p style="font-size:13px; color:#64748b; line-height:1.5;"><em>' + escapeHtml(notes).replace(/\n/g, '<br>') + '</em></p>';
                                }

                                if (sig) {
                                    html += '<p style="margin-top:18px; font-size:13.5px; color:#2d3748; line-height:1.5;">' + escapeHtml(sig).replace(/\n/g, '<br>') + '</p>';
                                }

                                bodyTarget.innerHTML = html;
                            }

                            function updateCard(cardNum) {
                                if (cardNum === '1' || cardNum === 1) renderPreviewCard1();
                                if (cardNum === '2' || cardNum === 2) renderPreviewCard2();
                                if (cardNum === '3' || cardNum === 3) renderPreviewCard3();
                                if (cardNum === '4' || cardNum === 4) renderPreviewCard4();
                            }

                            // Inizializza tutte e 4 le anteprime
                            renderPreviewCard1();
                            renderPreviewCard2();
                            renderPreviewCard3();
                            renderPreviewCard4();

                            // Aggiorna in tempo reale ad ogni digitazione
                            document.querySelectorAll('.dfn-input-watch').forEach(function(input) {
                                input.addEventListener('input', function() {
                                    updateCard(this.dataset.card);
                                });
                            });

                            // Accordion Toggle
                            document.querySelectorAll('.dfn-vol-accordion-header').forEach(function(header) {
                                header.addEventListener('click', function() {
                                    var item = this.closest('.dfn-vol-accordion-item');
                                    item.classList.toggle('is-open');
                                    var cardId = item.id.replace('dfn-vol-accordion-', '');
                                    updateCard(cardId);
                                });
                            });

                            // Expand / Collapse All
                            var expandBtn = document.getElementById('dfn-expand-all-vol-emails');
                            var collapseBtn = document.getElementById('dfn-collapse-all-vol-emails');
                            if (expandBtn) {
                                expandBtn.addEventListener('click', function() {
                                    document.querySelectorAll('.dfn-vol-accordion-item').forEach(function(item) {
                                        item.classList.add('is-open');
                                    });
                                    renderPreviewCard1();
                                    renderPreviewCard2();
                                    renderPreviewCard3();
                                });
                            }
                            if (collapseBtn) {
                                collapseBtn.addEventListener('click', function() {
                                    document.querySelectorAll('.dfn-vol-accordion-item').forEach(function(item) {
                                        item.classList.remove('is-open');
                                    });
                                });
                            }

                            // Inserimento Tag dinamico nel campo attivo
                            var activeInputByCard = {};
                            document.querySelectorAll('.dfn-input-watch').forEach(function(el) {
                                el.addEventListener('focus', function() {
                                    var card = this.dataset.card;
                                    if (card) activeInputByCard[card] = this;
                                });
                            });

                            document.querySelectorAll('.dfn-chip-btn').forEach(function(tagBtn) {
                                tagBtn.addEventListener('click', function() {
                                    var tag = this.dataset.tag;
                                    var card = this.dataset.card;
                                    var activeInput = activeInputByCard[card];

                                    if (!activeInput) {
                                        var container = document.getElementById('dfn-vol-accordion-' + card);
                                        if (container) {
                                            activeInput = container.querySelector('textarea.dfn-input-watch') || container.querySelector('input.dfn-input-watch');
                                        }
                                    }
                                    if (!activeInput) return;

                                    var start = activeInput.selectionStart !== undefined ? activeInput.selectionStart : activeInput.value.length;
                                    var end   = activeInput.selectionEnd !== undefined ? activeInput.selectionEnd : activeInput.value.length;
                                    var val   = activeInput.value;

                                    activeInput.value = val.substring(0, start) + tag + val.substring(end);
                                    activeInput.focus();
                                    if (activeInput.setSelectionRange) {
                                        activeInput.setSelectionRange(start + tag.length, start + tag.length);
                                    }

                                    updateCard(card);
                                });
                            });
                        });
                        </script>

                    <?php elseif ($active_tab === 'test-invio') : 
                        $is_staging = (strpos(site_url(), 'staging') !== false || (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'staging'));
                        $current_sandbox_mode = dfn_get_volunteer_setting('email_sandbox_mode', 'live');
                    ?>
                        <!-- TAB 3: TEST INVIO & PROTEZIONE SANDBOX -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🧪 Test di Invio &amp; Protezione Sandbox E-mail</h2>
                        <p class="description" style="margin-bottom: 20px;">Verifica la corretta consegna delle comunicazioni e imposta la modalità di sicurezza per collaudi o ambienti di Staging.</p>

                        <?php if ($is_staging) : ?>
                            <div style="background:#fef3c7; border:1.5px solid #f59e0b; border-radius:8px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; gap:12px;">
                                <span style="font-size:24px;">🧪</span>
                                <div>
                                    <strong style="color:#92400e; font-size:13.5px;">Rilevato Ambiente di Staging (<code>staging.dfnprenotazioni.it</code>)</strong>
                                    <div style="color:#78350f; font-size:12.5px; margin-top:2px;">
                                        Per evitare l'invio accidentale di email a volontari o clienti reali durante i test, puoi selezionare la modalità <strong>Silenziosa (Mute)</strong> o <strong>Sandbox Redirect</strong>.
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- CARD CONTROLLO AMBIENTE NOTIFICHE -->
                        <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; padding:18px 20px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin:0 0 12px 0; display:flex; align-items:center; gap:8px;">
                                <span>🛡️</span> Modalità di Consegna &amp; Sicurezza Notifiche
                            </h3>
                            <table class="form-table" role="presentation" style="margin-top:0;">
                                <tr>
                                    <th scope="row" style="padding-top:8px; width:240px;">Stato Invio Notifiche</th>
                                    <td style="padding-top:8px;">
                                        <fieldset>
                                            <label style="display:flex; align-items:center; gap:8px; font-weight:600; margin-bottom:8px; cursor:pointer;">
                                                <input type="radio" name="dfn_vol_settings[email_sandbox_mode]" value="live" <?php checked($current_sandbox_mode, 'live'); ?> style="accent-color:#004b23;" />
                                                🟢 <span>Invio Reale a Destinatari (Produzione)</span>
                                            </label>
                                            <label style="display:flex; align-items:center; gap:8px; font-weight:600; margin-bottom:8px; cursor:pointer;">
                                                <input type="radio" name="dfn_vol_settings[email_sandbox_mode]" value="mute" <?php checked($current_sandbox_mode, 'mute'); ?> style="accent-color:#004b23;" />
                                                🔴 <span>Modalità Silenziosa / Mute (Blocca tutte le email in uscita, registra solo nei log)</span>
                                            </label>
                                            <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer;">
                                                <input type="radio" name="dfn_vol_settings[email_sandbox_mode]" value="sandbox_redirect" <?php checked($current_sandbox_mode, 'sandbox_redirect'); ?> style="accent-color:#004b23;" />
                                                🟡 <span>Sandbox Redirect (Devia tutte le email a una casella di test designata)</span>
                                            </label>
                                        </fieldset>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" style="padding-top:8px;"><label for="email_sandbox_recipient">Email di Test Sandbox</label></th>
                                    <td style="padding-top:8px;">
                                        <input type="email" name="dfn_vol_settings[email_sandbox_recipient]" id="email_sandbox_recipient" value="<?php echo esc_attr(dfn_get_volunteer_setting('email_sandbox_recipient', get_option('admin_email'))); ?>" class="regular-text" style="max-width:440px;" />
                                        <p class="description">Utilizzato come unico destinatario di tutte le email in uscita quando è attiva la modalità Sandbox Redirect.</p>
                                    </td>
                                </tr>
                            </table>
                            <div style="margin-top:16px; padding-top:12px; border-top:1px solid #f1f5f9;">
                                <?php submit_button(__('Salva Modalità di Consegna', 'dfn-theme'), 'secondary', 'submit', false); ?>
                            </div>
                        </div>

                        <!-- CARD TEST INVIO TEMPLATE -->
                        <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin:0 0 12px 0; display:flex; align-items:center; gap:8px;">
                                <span>🚀</span> Test Invio Modello Singolo
                            </h3>
                            <table class="form-table" role="presentation" style="margin-top:0;">
                                <tr>
                                    <th scope="row"><label for="dfn-test-vol-template">Modello da Testare</label></th>
                                    <td>
                                        <select id="dfn-test-vol-template" class="regular-text" style="height:36px; max-width:440px;">
                                            <option value="admin_notification">1. Notifica Nuovo Candidato (Admin)</option>
                                            <option value="candidate_pending">2. Ricezione Candidatura (Candidato - In Attesa)</option>
                                            <option value="volunteer_approved">3. Approvazione &amp; Benvenuto (Volontario)</option>
                                            <option value="credentials">4. Benvenuto &amp; Credenziali Account con Password (Volontario)</option>
                                        </select>
                                        <p class="description">Seleziona quale tipologia di email generare con dati di test simulati.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label for="dfn-test-vol-email">Indirizzo Email Destinazione</label></th>
                                    <td>
                                        <input type="email" id="dfn-test-vol-email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text" style="max-width:440px;" />
                                        <p class="description">L'indirizzo a cui recapitare l'email di test.</p>
                                    </td>
                                </tr>
                            </table>

                        <div style="margin-top: 25px;">
                            <button type="button" id="dfn-send-vol-test-btn" class="button button-primary button-large" style="background:#004b23; border-color:#003318; font-weight:600; box-shadow:none; text-shadow:none;">
                                🚀 Invia Email di Test
                            </button>
                            <span id="dfn-vol-test-spinner" class="spinner" style="float:none; vertical-align:middle; margin-left:8px;"></span>
                        </div>

                        <div id="dfn-vol-test-result" style="margin-top:20px; display:none; max-width:600px;"></div>

                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var btn = document.getElementById('dfn-send-vol-test-btn');
                            var spinner = document.getElementById('dfn-vol-test-spinner');
                            var resultBox = document.getElementById('dfn-vol-test-result');

                            if (!btn) return;

                            btn.addEventListener('click', function() {
                                var template = document.getElementById('dfn-test-vol-template').value;
                                var email = document.getElementById('dfn-test-vol-email').value;

                                if (!email) {
                                    alert('Inserisci un indirizzo email valido.');
                                    return;
                                }

                                btn.disabled = true;
                                spinner.classList.add('is-active');
                                resultBox.style.display = 'none';

                                var data = new FormData();
                                data.append('action', 'dfn_send_volunteer_test_email');
                                data.append('template', template);
                                data.append('email', email);
                                data.append('nonce', '<?php echo wp_create_nonce('dfn_vol_test_email_nonce'); ?>');

                                fetch(ajaxurl, {
                                    method: 'POST',
                                    body: data
                                })
                                .then(function(res) { return res.json(); })
                                .then(function(res) {
                                    btn.disabled = false;
                                    spinner.classList.remove('is-active');
                                    resultBox.style.display = 'block';
                                    if (res.success) {
                                        resultBox.innerHTML = '<div class="notice notice-success inline" style="padding:12px; font-weight:600;">✅ ' + res.data.message + '</div>';
                                    } else {
                                        resultBox.innerHTML = '<div class="notice notice-error inline" style="padding:12px; font-weight:600;">❌ Errore: ' + (res.data ? res.data.message : 'Impossibile inviare') + '</div>';
                                    }
                                })
                                .catch(function(err) {
                                    btn.disabled = false;
                                    spinner.classList.remove('is-active');
                                    resultBox.style.display = 'block';
                                    resultBox.innerHTML = '<div class="notice notice-error inline" style="padding:12px; font-weight:600;">❌ Errore di connessione.</div>';
                                });
                            });
                        });
                        </script>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Handler AJAX per il test di invio email volontari.
 */
function dfn_ajax_send_volunteer_test_email(): void
{
    check_ajax_referer('dfn_vol_test_email_nonce', 'nonce');

    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members')) {
        wp_send_json_error(['message' => 'Permessi non sufficienti.']);
    }

    $template = sanitize_text_field($_POST['template'] ?? '');
    $email    = sanitize_email($_POST['email'] ?? '');

    if (! is_email($email)) {
        wp_send_json_error(['message' => 'Indirizzo email non valido.']);
    }

    $sample_volunteer = [
        'first_name'        => 'Mario',
        'last_name'         => 'Rossi',
        'email'             => $email,
        'phone'             => '+39 333 1234567',
        'card_number'       => '12345678',
        'is_guide'          => 1,
        'has_safety_course' => 1,
        'volunteer_notes'   => 'Disponibile per Giornate FAI ed eventi di delegazione',
        'created_at'        => current_time('mysql'),
    ];

    $user_id = get_current_user_id();
    $sent    = false;

    if ($template === 'admin_notification') {
        if (function_exists('dfn_send_volunteer_admin_notification')) {
            $sent = dfn_send_volunteer_admin_notification($sample_volunteer, $user_id, $email);
        }
    } elseif ($template === 'candidate_pending') {
        if (function_exists('dfn_send_volunteer_candidate_pending_email')) {
            $sent = dfn_send_volunteer_candidate_pending_email($sample_volunteer, $email);
        }
    } elseif ($template === 'volunteer_approved') {
        if (function_exists('dfn_send_volunteer_approved_email')) {
            $sent = dfn_send_volunteer_approved_email($sample_volunteer, $user_id, $email);
        }
    } elseif ($template === 'credentials') {
        if (function_exists('dfn_send_volunteer_credentials_email')) {
            $res  = dfn_send_volunteer_credentials_email($sample_volunteer, false, $email);
            $sent = ! empty($res['success']);
        }
    }

    if ($sent) {
        wp_send_json_success(['message' => sprintf('Email di test (%s) inviata con successo a %s!', esc_html($template), esc_html($email))]);
    } else {
        wp_send_json_error(['message' => 'Errore durante l\'invio dell\'email via wp_mail. Verifica i log del server di posta.']);
    }
}

<?php
/**
 * DFN Booking & Volunteer System — Volunteer Settings Panel
 *
 * Fornisce un pannello di configurazione per il modulo Volontari FAI:
 * - Destinatari notifiche amministrative (nuove registrazioni e candidature)
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
    $has_vol_access = function_exists('dfn_user_has_module_access') && dfn_user_has_module_access('volontari');
    $cap = ($has_vol_access || current_user_can('manage_options') || current_user_can('dfn_act_fai_members')) ? 'read' : 'dfn_act_vol_roster';

    add_submenu_page(
        'dfn-volunteers',
        __('Impostazioni Volontari FAI', 'dfn-theme'),
        __('Impostazioni', 'dfn-theme'),
        $cap,
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
        'vol_email_admin_recipients'         => $delegation_email,
        'vol_require_approval'               => 'yes',
        'vol_enable_candidate_pending_email' => 'yes',
        'vol_enable_approved_email'          => 'yes',

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
        'vol_email_admin_recipients'         => 'sanitize_email_list',
        'vol_require_approval'               => 'sanitize_text_field',
        'vol_enable_candidate_pending_email' => 'sanitize_text_field',
        'vol_enable_approved_email'          => 'sanitize_text_field',

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
            } elseif ($sanitize_type === 'sanitize_textarea_field') {
                $merged[$field_key] = sanitize_textarea_field(wp_unslash($val));
            } else {
                $merged[$field_key] = sanitize_text_field(wp_unslash($val));
            }
        }
    }

    // Toggle checkboxes default 'no' if unchecked in POST when saving notifications tab
    if ($active_tab === 'notifiche') {
        $toggles = ['vol_require_approval', 'vol_enable_candidate_pending_email', 'vol_enable_approved_email'];
        foreach ($toggles as $t_key) {
            if (! isset($raw_input[$t_key])) {
                $merged[$t_key] = 'no';
            }
        }
    }

    update_option('dfn_volunteer_settings', $merged);
    $GLOBALS['dfn_volunteer_settings_cache'] = $merged;

    if (function_exists('dfn_log_write')) {
        dfn_log_write(
            'volontari',
            wp_get_current_user()->display_name,
            'Aggiornate impostazioni e testi email Volontari FAI',
            'success'
        );
    }

    echo '<div class="notice notice-success is-dismissible" style="margin-top:15px;"><p>✅ <strong>Impostazioni Volontari salvate con successo.</strong></p></div>';
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

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'notifiche';
    $tab_url_base = admin_url('admin.php?page=dfn-volunteer-settings');

    $delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';

    ?>
    <style>
        .dfn-email-editor-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            padding: 24px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            margin-bottom: 32px;
        }
        .dfn-editor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 14px;
        }
        .dfn-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .dfn-placeholders-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 18px;
        }
        .dfn-insert-tag-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            font-family: monospace;
            color: #0f172a;
            cursor: pointer;
            transition: all 0.1s ease;
            user-select: none;
        }
        .dfn-insert-tag-btn:hover {
            background: #eff6ff;
            border-color: #3b82f6;
            color: #1d4ed8;
            transform: translateY(-1px);
        }
        .dfn-builder-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 1024px) {
            .dfn-builder-grid {
                grid-template-columns: 1fr;
            }
        }
        .dfn-form-section {
            background: #fafafa;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 18px;
            margin-bottom: 14px;
        }
        .dfn-form-section-title {
            font-size: 13.5px;
            font-weight: 700;
            color: #004b23;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin: 0 0 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .dfn-field-group {
            margin-bottom: 12px;
        }
        .dfn-field-group:last-child {
            margin-bottom: 0;
        }
        .dfn-field-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 4px;
        }
        .dfn-field-group .dfn-field-desc {
            font-size: 11.5px;
            color: #64748b;
            margin-top: 3px;
            line-height: 1.4;
        }
        .dfn-field-group input[type="text"],
        .dfn-field-group textarea {
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
        .dfn-field-group input[type="text"]:focus,
        .dfn-field-group textarea:focus {
            border-color: #004b23;
            outline: none;
            box-shadow: 0 0 0 1px #004b23;
        }
        .dfn-field-group textarea {
            resize: vertical;
        }

        /* Mockup Anteprima Grafica Live */
        .dfn-preview-sticky {
            position: sticky;
            top: 40px;
        }
        .dfn-preview-mockup-wrapper {
            background: #f4f6f8;
            border-radius: 10px;
            padding: 20px 14px;
            border: 1px solid #cbd5e1;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);
        }
        .dfn-preview-mockup-card {
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        .dfn-mockup-header {
            background: #004b23;
            padding: 22px 20px;
            text-align: center;
            border-bottom: 4px solid #e74f30;
        }
        .dfn-mockup-header h3 {
            color: #ffffff;
            margin: 0;
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -0.2px;
        }
        .dfn-mockup-body {
            padding: 24px 20px;
            color: #2d3748;
            font-size: 14.5px;
            line-height: 1.6;
        }
        .dfn-mockup-body .info-box {
            background-color: #f8fafc;
            border-left: 4px solid #004b23;
            padding: 14px 18px;
            margin: 16px 0;
            border-radius: 4px;
            font-size: 14px;
        }
        .dfn-mockup-body .info-box-title {
            font-weight: 700;
            font-size: 14.5px;
            color: #004b23;
            margin: 0 0 10px;
        }
        .dfn-mockup-body .button {
            display: inline-block;
            background-color: #004b23;
            color: #ffffff !important;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            margin: 14px 0;
            text-align: center;
            border-bottom: 3px solid #002e15;
            box-shadow: 0 2px 6px rgba(0,75,35,0.2);
        }
        .dfn-mockup-footer {
            background: #f8fafc;
            padding: 12px 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 11.5px;
            color: #64748b;
        }
    </style>

    <div class="wrap dfn-admin-wrap" style="max-width: 1240px;">
        <header class="dfn-admin-header" style="margin-bottom: 24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                    <span class="dashicons dashicons-admin-generic" style="font-size:32px; width:32px; height:32px; color:#004b23; vertical-align:middle;"></span>
                    <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:0 0 0 8px; display:inline-block; vertical-align:middle;">
                        Impostazioni Gestione Volontari FAI
                    </h1>
                </div>
                <div>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" class="button button-secondary">
                        &larr; Torna a Elenco Volontari
                    </a>
                </div>
            </div>
            <p style="color:#64748b; font-size:14px; margin: 8px 0 0 40px;">
                Configura i destinatari delle notifiche, le regole di approvazione preventiva e compila i testi delle email attraverso i campi guidati con anteprima grafica renderizzata in tempo reale.
            </p>
        </header>

        <!-- NAV TABS -->
        <h2 class="nav-tab-wrapper" style="margin-bottom: 24px; border-bottom: 2px solid #cbd5e1;">
            <a href="<?php echo esc_url(add_query_arg('tab', 'notifiche', $tab_url_base)); ?>" class="nav-tab <?php echo $active_tab === 'notifiche' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'notifiche' ? 'border-bottom-color:#fff; font-weight:700; color:#004b23;' : ''; ?>">
                🔔 Notifiche &amp; Destinatari
            </a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'modelli-email', $tab_url_base)); ?>" class="nav-tab <?php echo $active_tab === 'modelli-email' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'modelli-email' ? 'border-bottom-color:#fff; font-weight:700; color:#004b23;' : ''; ?>">
                ✉️ Modelli Email Volontari (Compilazione Guidata &amp; Anteprima Live)
            </a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'test-invio', $tab_url_base)); ?>" class="nav-tab <?php echo $active_tab === 'test-invio' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'test-invio' ? 'border-bottom-color:#fff; font-weight:700; color:#004b23;' : ''; ?>">
                🧪 Test Invio Email
            </a>
        </h2>

        <form method="post" action="<?php echo esc_url(add_query_arg('tab', $active_tab, $tab_url_base)); ?>">
            <?php wp_nonce_field('dfn_save_vol_settings_action', 'dfn_vol_settings_nonce'); ?>

            <!-- TAB 1: NOTIFICHE & DESTINATARI -->
            <?php if ($active_tab === 'notifiche') : ?>
                <div style="background:#fff; border-radius:10px; border:1px solid #c3c4c7; padding:24px 28px; box-shadow:0 1px 3px rgba(0,0,0,0.04); margin-bottom:24px;">
                    <h2 style="font-size:18px; font-weight:700; color:#004b23; margin:0 0 16px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                        🔔 Notifiche Candidature e Approvazione
                    </h2>

                    <table class="form-table" role="presentation" style="margin-top:0;">
                        <tbody>
                            <tr>
                                <th scope="row" style="width:280px;">
                                    <label for="vol_email_admin_recipients"><strong>Destinatari Notifiche Admin</strong></label>
                                </th>
                                <td>
                                    <input type="text" name="dfn_vol_settings[vol_email_admin_recipients]" id="vol_email_admin_recipients" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_recipients')); ?>" class="regular-text" style="width:100%; max-width:480px;" />
                                    <p class="description" style="margin-top:6px;">
                                        Indirizzi email a cui inviare la notifica quando un candidato compila il form di registrazione online. Separa più indirizzi con una virgola.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="vol_require_approval"><strong>Approvazione Preventiva</strong></label>
                                </th>
                                <td>
                                    <label style="display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_require_approval]" id="vol_require_approval" value="yes" <?php checked(dfn_get_volunteer_setting('vol_require_approval', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Richiedi approvazione manuale dell'amministratore prima di abilitare l'accesso volontario
                                    </label>
                                    <p class="description" style="margin-top:6px;">
                                        <strong>Consigliato:</strong> Se attivo, le nuove registrazioni online entrano in stato <em>In Attesa</em>. L'utente non riceve il ruolo Volontario né l'accesso alla bacheca turni fino a quando un amministratore non approva esplicitamente la candidatura dal pannello.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="vol_enable_candidate_pending_email"><strong>Email di Ricezione al Candidato</strong></label>
                                </th>
                                <td>
                                    <label style="display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_enable_candidate_pending_email]" id="vol_enable_candidate_pending_email" value="yes" <?php checked(dfn_get_volunteer_setting('vol_enable_candidate_pending_email', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Invia email automatica di presa in carico al candidato al termine dell'invio del form
                                    </label>
                                    <p class="description" style="margin-top:6px;">
                                        Informa subito l'utente che la sua richiesta è stata ricevuta e che verrà ricontattato dallo staff.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="vol_enable_approved_email"><strong>Email di Benvenuto ad Approvazione</strong></label>
                                </th>
                                <td>
                                    <label style="display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; cursor:pointer;">
                                        <input type="checkbox" name="dfn_vol_settings[vol_enable_approved_email]" id="vol_enable_approved_email" value="yes" <?php checked(dfn_get_volunteer_setting('vol_enable_approved_email', 'yes'), 'yes'); ?> style="accent-color:#004b23;" />
                                        Invia email di conferma e istruzioni al volontario quando la candidatura viene approvata
                                    </label>
                                    <p class="description" style="margin-top:6px;">
                                        Invia una mail formattata con il pulsante di accesso alla bacheca volontari, promemoria riunioni e istruzioni operative.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px;">
                    <button type="submit" class="button button-primary button-large" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:4px 24px;">
                        Salva Impostazioni Notifiche
                    </button>
                </div>
            <?php endif; ?>

            <!-- TAB 2: MODELLI EMAIL CON COMPILAZIONE GUIDATA E ANTEPRIMA LIVE -->
            <?php if ($active_tab === 'modelli-email') : ?>
                <!-- GUIDA AI SEGNAPOSTO -->
                <div class="dfn-placeholders-bar">
                    <span style="font-size:13px; font-weight:700; color:#004b23; margin-right:6px;">
                        🏷️ Clicca per inserire un segnaposto nel campo attivo:
                    </span>
                    <span class="dfn-insert-tag-btn" data-tag="{nome}">+ {nome}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{cognome}">+ {cognome}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{email}">+ {email}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{telefono}">+ {telefono}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{tessera_fai}">+ {tessera_fai}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{mansioni}">+ {mansioni}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{delegazione}">+ {delegazione}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{data_richiesta}">+ {data_richiesta}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{link_accesso}">+ {link_accesso}</span>
                    <span class="dfn-insert-tag-btn" data-tag="{link_admin}">+ {link_admin}</span>
                </div>

                <!-- ========================================================= -->
                <!-- 1. MODELLO EMAIL ADMIN -->
                <!-- ========================================================= -->
                <div class="dfn-email-editor-card" id="dfn-card-1">
                    <div class="dfn-editor-header">
                        <h2 class="dfn-card-title">
                            <span style="font-size:22px;">👤</span>
                            1. Notifica Nuova Candidatura (allo Staff / Amministratore)
                        </h2>
                        <span style="font-size:12px; font-weight:600; color:#166534; background:#dcfce7; padding:4px 10px; border-radius:20px;">
                            Inviata automaticamente allo Staff FAI
                        </span>
                    </div>

                    <div class="dfn-builder-grid">
                        <!-- COLONNA SINISTRA: FORM GUIDATO -->
                        <div class="dfn-form-col">
                            <!-- Sezione Intestazione -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">📧 Oggetto &amp; Intestazione</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_admin_subject">Oggetto dell'email</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_admin_subject]" id="vol_email_admin_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_subject')); ?>" class="dfn-input-watch" data-card="1" />
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_admin_title">Titolo Banner Verde (Header)</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_admin_title]" id="vol_email_admin_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_title')); ?>" class="dfn-input-watch" data-card="1" />
                                </div>
                            </div>

                            <!-- Sezione Corpo del Messaggio -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">📝 Messaggio Introduttivo</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_admin_intro">Testo di apertura</label>
                                    <textarea name="dfn_vol_settings[vol_email_admin_intro]" id="vol_email_admin_intro" rows="3" class="dfn-input-watch" data-card="1"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_admin_intro')); ?></textarea>
                                    <div class="dfn-field-desc">Inserisci il saluto iniziale allo staff. I doppi a capo creano nuovi paragrafi.</div>
                                </div>
                            </div>

                            <!-- Sezione Riquadro Dati Candidato -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">📋 Riquadro Dati Candidato</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_admin_box_title">Titolo del Riquadro Dati</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_admin_box_title]" id="vol_email_admin_box_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_box_title')); ?>" class="dfn-input-watch" data-card="1" />
                                    <div class="dfn-field-desc">I campi del candidato (nome, email, telefono, mansioni, data) vengono impaginati automaticamente con stile FAI all'interno di questo box.</div>
                                </div>
                            </div>

                            <!-- Sezione Istruzioni e Pulsante -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">🔘 Azione e Istruzioni Staff</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_admin_instructions">Istruzioni di revisione</label>
                                    <textarea name="dfn_vol_settings[vol_email_admin_instructions]" id="vol_email_admin_instructions" rows="2" class="dfn-input-watch" data-card="1"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_admin_instructions')); ?></textarea>
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_admin_btn_text">Testo del Pulsante di Valutazione</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_admin_btn_text]" id="vol_email_admin_btn_text" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_btn_text')); ?>" class="dfn-input-watch" data-card="1" />
                                    <div class="dfn-field-desc">Il pulsante collegherà direttamente alla schermata di approvazione nel pannello WordPress.</div>
                                </div>
                            </div>
                        </div>

                        <!-- COLONNA DESTRA: ANTEPRIMA GRAFICA LIVE -->
                        <div class="dfn-preview-sticky">
                            <div style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                <span>✨ Anteprima Grafica Live (in tempo reale)</span>
                            </div>
                            <div class="dfn-preview-mockup-wrapper">
                                <div class="dfn-preview-mockup-card">
                                    <div class="dfn-mockup-header">
                                        <h3 id="dfn-preview-title-1"></h3>
                                    </div>
                                    <div class="dfn-mockup-body" id="dfn-preview-body-1"></div>
                                    <div class="dfn-mockup-footer">
                                        FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================================= -->
                <!-- 2. MODELLO EMAIL CANDIDATO PENDING -->
                <!-- ========================================================= -->
                <div class="dfn-email-editor-card" id="dfn-card-2">
                    <div class="dfn-editor-header">
                        <h2 class="dfn-card-title">
                            <span style="font-size:22px;">⏳</span>
                            2. Ricezione Candidatura (al Candidato - In Attesa di Verifica)
                        </h2>
                        <span style="font-size:12px; font-weight:600; color:#0284c7; background:#e0f2fe; padding:4px 10px; border-radius:20px;">
                            Inviata al candidato dopo aver completato il form
                        </span>
                    </div>

                    <div class="dfn-builder-grid">
                        <!-- COLONNA SINISTRA: FORM GUIDATO -->
                        <div class="dfn-form-col">
                            <!-- Sezione Intestazione -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">📧 Oggetto &amp; Intestazione</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_pending_subject">Oggetto dell'email</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_pending_subject]" id="vol_email_pending_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_subject')); ?>" class="dfn-input-watch" data-card="2" />
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_pending_title">Titolo Banner Verde (Header)</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_pending_title]" id="vol_email_pending_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_title')); ?>" class="dfn-input-watch" data-card="2" />
                                </div>
                            </div>

                            <!-- Sezione Apertura -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">👋 Saluto &amp; Messaggio Iniziale</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_pending_intro">Testo di apertura</label>
                                    <textarea name="dfn_vol_settings[vol_email_pending_intro]" id="vol_email_pending_intro" rows="3" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_intro')); ?></textarea>
                                    <div class="dfn-field-desc">Puoi usare <code>{nome}</code> per personalizzare il saluto (es. <em>Gentile {nome},</em>).</div>
                                </div>
                            </div>

                            <!-- Sezione Riquadro Informativo -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">📋 Riquadro Informativo di Verifica</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_pending_box_title">Titolo del Riquadro</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_pending_box_title]" id="vol_email_pending_box_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_box_title')); ?>" class="dfn-input-watch" data-card="2" />
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_pending_box_text">Contenuto del Riquadro (Spiegazione passaggi successivi)</label>
                                    <textarea name="dfn_vol_settings[vol_email_pending_box_text]" id="vol_email_pending_box_text" rows="3" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_box_text')); ?></textarea>
                                </div>
                            </div>

                            <!-- Sezione Chiusura e Firma -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">✍️ Chiusura &amp; Firma</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_pending_closing">Messaggio di ringraziamento finale</label>
                                    <textarea name="dfn_vol_settings[vol_email_pending_closing]" id="vol_email_pending_closing" rows="2" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_closing')); ?></textarea>
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_pending_signature">Firma / Saluti</label>
                                    <textarea name="dfn_vol_settings[vol_email_pending_signature]" id="vol_email_pending_signature" rows="2" class="dfn-input-watch" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_signature')); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- COLONNA DESTRA: ANTEPRIMA GRAFICA LIVE -->
                        <div class="dfn-preview-sticky">
                            <div style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                <span>✨ Anteprima Grafica Live (in tempo reale)</span>
                            </div>
                            <div class="dfn-preview-mockup-wrapper">
                                <div class="dfn-preview-mockup-card">
                                    <div class="dfn-mockup-header">
                                        <h3 id="dfn-preview-title-2"></h3>
                                    </div>
                                    <div class="dfn-mockup-body" id="dfn-preview-body-2"></div>
                                    <div class="dfn-mockup-footer">
                                        FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ========================================================= -->
                <!-- 3. MODELLO EMAIL VOLONTARIO APPROVED -->
                <!-- ========================================================= -->
                <div class="dfn-email-editor-card" id="dfn-card-3">
                    <div class="dfn-editor-header">
                        <h2 class="dfn-card-title">
                            <span style="font-size:22px;">🎉</span>
                            3. Email di Approvazione &amp; Benvenuto (al Volontario Approvato)
                        </h2>
                        <span style="font-size:12px; font-weight:600; color:#15803d; background:#dcfce7; padding:4px 10px; border-radius:20px;">
                            Inviata all'approvazione della candidatura dall'Admin
                        </span>
                    </div>

                    <div class="dfn-builder-grid">
                        <!-- COLONNA SINISTRA: FORM GUIDATO -->
                        <div class="dfn-form-col">
                            <!-- Sezione Intestazione -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">📧 Oggetto &amp; Intestazione</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_subject">Oggetto dell'email</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_approved_subject]" id="vol_email_approved_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_subject')); ?>" class="dfn-input-watch" data-card="3" />
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_title">Titolo Banner Verde (Header)</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_approved_title]" id="vol_email_approved_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_title')); ?>" class="dfn-input-watch" data-card="3" />
                                </div>
                            </div>

                            <!-- Sezione Saluto & Benvenuto -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">🎉 Saluto &amp; Congratulazioni</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_intro">Testo di congratulazioni e benvenuto</label>
                                    <textarea name="dfn_vol_settings[vol_email_approved_intro]" id="vol_email_approved_intro" rows="3" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_intro')); ?></textarea>
                                </div>
                            </div>

                            <!-- Sezione Riquadro Attività & Punti Elenco -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">🏛️ Riquadro Opportunità &amp; Bacheca Volontari</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_box_title">Titolo del Riquadro</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_approved_box_title]" id="vol_email_approved_box_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_box_title')); ?>" class="dfn-input-watch" data-card="3" />
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_box_bullets">Punti Elenco (Cosa può fare il volontario)</label>
                                    <textarea name="dfn_vol_settings[vol_email_approved_box_bullets]" id="vol_email_approved_box_bullets" rows="4" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_box_bullets')); ?></textarea>
                                    <div class="dfn-field-desc">💡 Inserisci una voce per riga: il sistema creerà automaticamente la lista con punti elenco formattata.</div>
                                </div>
                            </div>

                            <!-- Sezione Pulsante & Note -->
                            <div class="dfn-form-section">
                                <div class="dfn-form-section-title">🔘 Accesso &amp; Credenziali</div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_btn_text">Testo del Pulsante di Accesso</label>
                                    <input type="text" name="dfn_vol_settings[vol_email_approved_btn_text]" id="vol_email_approved_btn_text" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_btn_text')); ?>" class="dfn-input-watch" data-card="3" />
                                    <div class="dfn-field-desc">Collega direttamente alla bacheca volontario nel conto utente.</div>
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_notes">Nota credenziali di accesso</label>
                                    <textarea name="dfn_vol_settings[vol_email_approved_notes]" id="vol_email_approved_notes" rows="2" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_notes')); ?></textarea>
                                </div>
                                <div class="dfn-field-group">
                                    <label for="vol_email_approved_signature">Firma / Saluti Finali</label>
                                    <textarea name="dfn_vol_settings[vol_email_approved_signature]" id="vol_email_approved_signature" rows="2" class="dfn-input-watch" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_signature')); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- COLONNA DESTRA: ANTEPRIMA GRAFICA LIVE -->
                        <div class="dfn-preview-sticky">
                            <div style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase; margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                <span>✨ Anteprima Grafica Live (in tempo reale)</span>
                            </div>
                            <div class="dfn-preview-mockup-wrapper">
                                <div class="dfn-preview-mockup-card">
                                    <div class="dfn-mockup-header">
                                        <h3 id="dfn-preview-title-3"></h3>
                                    </div>
                                    <div class="dfn-mockup-body" id="dfn-preview-body-3"></div>
                                    <div class="dfn-mockup-footer">
                                        FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px; position:sticky; bottom:20px; z-index:10; background:rgba(255,255,255,0.95); backdrop-filter:blur(6px); padding:14px 20px; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 4px 15px rgba(0,0,0,0.08); display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:14px; color:#334155; font-weight:600;">
                        💾 Modifica i testi guidati e clicca su Salva per confermare.
                    </span>
                    <button type="submit" class="button button-primary button-large" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:6px 28px; font-size:15px;">
                        Salva Tutti i Modelli Email
                    </button>
                </div>

                <!-- SCRIPT LIVE PREVIEW & PLACEHOLDER INSERTION -->
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
                            return p ? '<p style="margin:0 0 14px; font-size:14.5px; line-height:1.6; color:#2d3748;">' + escapeHtml(p).replace(/\n/g, '<br>') + '</p>' : '';
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

                        titleTarget.textContent = title;

                        var html = formatParagraphs(intro);

                        html += '<div class="info-box">';
                        if (boxTitle) {
                            html += '<div class="info-box-title">' + escapeHtml(boxTitle) + '</div>';
                        }
                        html += '<table style="width:100%; border-collapse:collapse; font-size:13.5px;">';
                        html += '<tr><td style="padding:4px 0; font-weight:600; width:120px; color:#475569;">Candidato:</td><td style="padding:4px 0; font-weight:600; color:#0f172a;">Mario Rossi</td></tr>';
                        html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Email:</td><td style="padding:4px 0;"><a href="mailto:mario.rossi@email.it" style="color:#004b23; font-weight:600;">mario.rossi@email.it</a></td></tr>';
                        html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Telefono:</td><td style="padding:4px 0; color:#0f172a;">+39 333 1234567</td></tr>';
                        html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Disponibilità:</td><td style="padding:4px 0; color:#0f172a;">🏛️ Guida Culturale / Cicerone, 🦺 Corso Sicurezza</td></tr>';
                        html += '<tr><td style="padding:4px 0; font-weight:600; color:#475569;">Data invio:</td><td style="padding:4px 0; color:#0f172a;">' + sampleData['{data_richiesta}'] + '</td></tr>';
                        html += '</table></div>';

                        if (instr) {
                            html += formatParagraphs(instr);
                        }

                        if (btnText) {
                            html += '<div style="text-align:center; margin:22px 0;"><a href="#" class="button" onclick="return false;">' + escapeHtml(btnText) + '</a></div>';
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

                        titleTarget.textContent = title;

                        var html = formatParagraphs(intro);

                        if (boxTitle || boxText) {
                            html += '<div class="info-box" style="border-left-color:#166534;">';
                            if (boxTitle) {
                                html += '<div class="info-box-title" style="color:#166534;">' + escapeHtml(boxTitle) + '</div>';
                            }
                            if (boxText) {
                                html += '<p style="margin:0; font-size:14px; color:#334155; line-height:1.5;">' + escapeHtml(boxText).replace(/\n/g, '<br>') + '</p>';
                            }
                            html += '</div>';
                        }

                        if (closing) {
                            html += formatParagraphs(closing);
                        }

                        if (sig) {
                            html += '<p style="margin-top:20px; font-size:14.5px; color:#2d3748; line-height:1.5;">' + escapeHtml(sig).replace(/\n/g, '<br>') + '</p>';
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

                        titleTarget.textContent = title;

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
                                    html += '<ul style="margin:0; padding-left:18px; color:#334155; line-height:1.6; font-size:13.5px;">' + items.join('') + '</ul>';
                                }
                            }
                            html += '</div>';
                        }

                        if (btnText) {
                            html += '<div style="text-align:center; margin:24px 0;"><a href="#" class="button" onclick="return false;">' + escapeHtml(btnText) + '</a></div>';
                        }

                        if (notes) {
                            html += '<p style="font-size:13.5px; color:#64748b; line-height:1.5;"><em>' + escapeHtml(notes).replace(/\n/g, '<br>') + '</em></p>';
                        }

                        if (sig) {
                            html += '<p style="margin-top:20px; font-size:14.5px; color:#2d3748; line-height:1.5;">' + escapeHtml(sig).replace(/\n/g, '<br>') + '</p>';
                        }

                        bodyTarget.innerHTML = html;
                    }

                    function updateCard(cardNum) {
                        if (cardNum === '1' || cardNum === 1) renderPreviewCard1();
                        if (cardNum === '2' || cardNum === 2) renderPreviewCard2();
                        if (cardNum === '3' || cardNum === 3) renderPreviewCard3();
                    }

                    // Inizializza tutte e 3 le anteprime
                    renderPreviewCard1();
                    renderPreviewCard2();
                    renderPreviewCard3();

                    // Aggiorna in tempo reale ad ogni digitazione
                    document.querySelectorAll('.dfn-input-watch').forEach(function(input) {
                        input.addEventListener('input', function() {
                            updateCard(this.dataset.card);
                        });
                    });

                    // Inserimento Tag dinamico nel campo attivo
                    var activeInput = document.getElementById('vol_email_admin_intro');
                    document.querySelectorAll('.dfn-input-watch').forEach(function(el) {
                        el.addEventListener('focus', function() {
                            activeInput = this;
                        });
                    });

                    document.querySelectorAll('.dfn-insert-tag-btn').forEach(function(tagBtn) {
                        tagBtn.addEventListener('click', function() {
                            var tag = this.dataset.tag;
                            if (!activeInput) activeInput = document.getElementById('vol_email_admin_intro');
                            
                            var start = activeInput.selectionStart !== undefined ? activeInput.selectionStart : activeInput.value.length;
                            var end   = activeInput.selectionEnd !== undefined ? activeInput.selectionEnd : activeInput.value.length;
                            var val   = activeInput.value;

                            activeInput.value = val.substring(0, start) + tag + val.substring(end);
                            activeInput.focus();
                            if (activeInput.setSelectionRange) {
                                activeInput.setSelectionRange(start + tag.length, start + tag.length);
                            }

                            var card = activeInput.dataset.card;
                            if (card) updateCard(card);
                        });
                    });
                });
                </script>
            <?php endif; ?>

            <!-- TAB 3: TEST INVIO EMAIL -->
            <?php if ($active_tab === 'test-invio') : ?>
                <div style="background:#fff; border-radius:10px; border:1px solid #c3c4c7; padding:24px 28px; box-shadow:0 1px 3px rgba(0,0,0,0.04); margin-bottom:24px;">
                    <h2 style="font-size:18px; font-weight:700; color:#004b23; margin:0 0 12px;">
                        🧪 Test di Invio Email Notifiche Volontari
                    </h2>
                    <p style="color:#475569; font-size:14px; margin-bottom:20px;">
                        Invia un'email di prova al tuo indirizzo per verificare la formattazione, i colori del template e i testi configurati.
                    </p>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; max-width:800px; margin-bottom:24px;">
                        <div>
                            <label for="dfn-test-vol-template" style="display:block; font-weight:700; margin-bottom:6px; font-size:13.5px;">
                                Seleziona Modello da Testare:
                            </label>
                            <select id="dfn-test-vol-template" style="width:100%; height:38px; border-radius:6px;">
                                <option value="admin_notification">1. Notifica Nuovo Candidato (Admin)</option>
                                <option value="candidate_pending">2. Ricezione Candidatura (Candidato - In Attesa)</option>
                                <option value="volunteer_approved">3. Approvazione &amp; Benvenuto (Volontario)</option>
                            </select>
                        </div>
                        <div>
                            <label for="dfn-test-vol-email" style="display:block; font-weight:700; margin-bottom:6px; font-size:13.5px;">
                                Indirizzo Email di Destinazione:
                            </label>
                            <input type="email" id="dfn-test-vol-email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" style="width:100%; height:38px; border-radius:6px; padding:0 10px;" />
                        </div>
                    </div>

                    <button type="button" id="dfn-send-vol-test-btn" class="button button-primary button-large" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:4px 24px;">
                        🚀 Invia Email di Test
                    </button>
                    <span id="dfn-vol-test-spinner" class="spinner" style="float:none; vertical-align:middle; margin-left:8px;"></span>

                    <div id="dfn-vol-test-result" style="margin-top:20px; display:none;"></div>
                </div>

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
    $sent = false;

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
    }

    if ($sent) {
        wp_send_json_success(['message' => sprintf('Email di test (%s) inviata con successo a %s!', esc_html($template), esc_html($email))]);
    } else {
        wp_send_json_error(['message' => 'Errore durante l\'invio dell\'email via wp_mail. Verifica i log del server di posta.']);
    }
}

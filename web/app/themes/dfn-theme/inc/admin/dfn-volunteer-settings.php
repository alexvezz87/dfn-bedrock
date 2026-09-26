<?php
/**
 * DFN Booking & Volunteer System — Volunteer Settings Panel
 *
 * Fornisce un pannello di configurazione per il modulo Volontari FAI:
 * - Destinatari notifiche amministrative (nuove registrazioni e candidature)
 * - Modalità approvazione preventiva (Sì/No)
 * - Modelli email personalizzabili con DOPPIA VISUALIZZAZIONE (HTML / Anteprima Renderizzata Live)
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
 * Se il valore a database è assente o vuoto, restituisce il modello predefinito.
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
        'vol_email_admin_recipients'        => $delegation_email,
        'vol_require_approval'              => 'yes',
        'vol_enable_candidate_pending_email'=> 'yes',
        'vol_enable_approved_email'         => 'yes',

        // 1. Email Admin: Notifica nuova candidatura
        'vol_email_admin_subject'           => 'Nuova Candidatura Volontario FAI: {nome} {cognome}',
        'vol_email_admin_title'             => 'Nuova Candidatura Volontario FAI',
        'vol_email_admin_body'              => "<p>Gentile Staff della {delegazione},</p>\n<p>È stata inviata una nuova richiesta di registrazione come Volontario FAI tramite il portale online:</p>\n\n<div class=\"info-box\">\n    <p style=\"margin:0 0 8px;\"><strong>👤 Candidato:</strong> {nome} {cognome}</p>\n    <p style=\"margin:0 0 8px;\"><strong>✉️ Email:</strong> <a href=\"mailto:{email}\" style=\"color:#004b23; font-weight:600;\">{email}</a></p>\n    <p style=\"margin:0 0 8px;\"><strong>📞 Telefono:</strong> {telefono}</p>\n    <p style=\"margin:0 0 8px;\"><strong>🏛️ Mansione / Disponibilità:</strong> {mansioni}</p>\n    <p style=\"margin:0;\"><strong>📅 Data richiesta:</strong> {data_richiesta}</p>\n</div>\n\n<p>La candidatura è attualmente <strong>In Attesa di Approvazione</strong>. Puoi esaminare i dettagli e approvare la scheda direttamente dal pannello di controllo:</p>\n\n<p style=\"text-align:center; margin:25px 0;\">\n    <a href=\"{link_admin}\" class=\"button\">Valuta Candidatura nel Pannello Admin &rarr;</a>\n</p>",

        // 2. Email Candidato: Presa in carico (In Attesa)
        'vol_email_pending_subject'         => 'Candidatura Volontario FAI Ricevuta - {delegazione}',
        'vol_email_pending_title'           => 'Grazie per la tua candidatura!',
        'vol_email_pending_body'            => "<p>Gentile <strong>{nome}</strong>,</p>\n\n<p>Abbiamo ricevuto con entusiasmo la tua candidatura per entrare a far parte della <strong>Squadra Volontari del {delegazione}</strong>!</p>\n\n<div class=\"info-box\">\n    <p style=\"margin:0 0 6px; color:#166534; font-weight:700;\">📋 Stato della tua richiesta: In fase di verifica</p>\n    <p style=\"margin:0; color:#334155; font-size:14px; line-height:1.5;\">La tua scheda è stata presa in carico dallo staff di Delegazione. Verificheremo i tuoi dati e ti invieremo un'email di conferma non appena il tuo account sarà approvato, fornendoti tutte le indicazioni per partecipare alle attività e alle riunioni.</p>\n</div>\n\n<p>Grazie di cuore per il tuo tempo e per la tua passione a sostegno della bellezza e del patrimonio del nostro territorio.</p>\n\n<p style=\"margin-top:25px;\">A presto,<br><strong>Lo Staff della {delegazione}</strong></p>",

        // 3. Email Volontario: Approvazione e Benvenuto
        'vol_email_approved_subject'        => 'Benvenuto nella Squadra Volontari del {delegazione}!',
        'vol_email_approved_title'          => 'La tua candidatura è stata approvata!',
        'vol_email_approved_body'           => "<p>Gentile <strong>{nome}</strong>,</p>\n\n<p>Siamo felici di comunicarti che la tua candidatura come <strong>Volontario del {delegazione}</strong> è stata <strong>approvata con successo</strong>! 🎉</p>\n\n<div class=\"info-box\">\n    <p style=\"margin:0 0 10px; font-weight:700; color:#004b23;\">🏛️ Cosa puoi fare adesso nella tua Area Riservata?</p>\n    <ul style=\"margin:0; padding-left:20px; color:#334155; line-height:1.6;\">\n        <li>Consultare e dare disponibilità per i <strong>turni delle Giornate FAI</strong> e degli eventi di delegazione</li>\n        <li>Partecipare ai <strong>sondaggi di disponibilità</strong> e pianificazione oraria</li>\n        <li>Visualizzare il calendario aggiornato delle <strong>riunioni di delegazione</strong></li>\n        <li>Scaricare e consultare i <strong>materiali informativi, guide e schede di visita</strong></li>\n    </ul>\n</div>\n\n<p style=\"text-align:center; margin:28px 0;\">\n    <a href=\"{link_accesso}\" class=\"button\">Accedi alla tua Bacheca Volontario &rarr;</a>\n</p>\n\n<p style=\"font-size:14px; color:#64748b;\"><em>Nota: Per accedere ti basterà utilizzare l'indirizzo email <strong>{email}</strong> e la password scelta in fase di registrazione.</em></p>\n\n<p style=\"margin-top:25px;\">Benvenuto a bordo e buon lavoro per la nostra missione comune!<br><strong>Lo Staff della {delegazione}</strong></p>",
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
 * Salva i campi inviati tramite POST nella schermata Impostazioni Volontari,
 * aggiornando solo i campi relativi al tab attivo senza sovrascrivere gli altri.
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
        'vol_email_admin_recipients'        => 'sanitize_email_list',
        'vol_require_approval'              => 'sanitize_text_field',
        'vol_enable_candidate_pending_email'=> 'sanitize_text_field',
        'vol_enable_approved_email'         => 'sanitize_text_field',

        'vol_email_admin_subject'           => 'sanitize_text_field',
        'vol_email_admin_title'             => 'sanitize_text_field',
        'vol_email_admin_body'              => 'wp_kses_post',

        'vol_email_pending_subject'         => 'sanitize_text_field',
        'vol_email_pending_title'           => 'sanitize_text_field',
        'vol_email_pending_body'            => 'wp_kses_post',

        'vol_email_approved_subject'        => 'sanitize_text_field',
        'vol_email_approved_title'          => 'sanitize_text_field',
        'vol_email_approved_body'           => 'wp_kses_post',
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
            } elseif ($sanitize_type === 'wp_kses_post') {
                $merged[$field_key] = wp_kses_post(wp_unslash($val));
            } else {
                $merged[$field_key] = sanitize_text_field(wp_unslash($val));
            }
        }
    }

    // Toggle checkboxes default 'no' if unchecked in POST when saving the notifications tab
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
            'Aggiornate impostazioni e modelli email Volontari FAI',
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
            box-shadow: 0 2px 5px rgba(0,0,0,0.04);
            margin-bottom: 28px;
        }
        .dfn-editor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 18px;
            border-bottom: 1.5px solid #f1f5f9;
            padding-bottom: 12px;
        }
        .dfn-view-switch-group {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 3px;
            gap: 2px;
            border: 1px solid #e2e8f0;
        }
        .dfn-view-switch-btn {
            background: none;
            border: none;
            padding: 6px 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: #64748b;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .dfn-view-switch-btn:hover {
            color: #0f172a;
            background: rgba(255,255,255,0.6);
        }
        .dfn-view-switch-btn.active {
            background: #ffffff;
            color: #004b23;
            font-weight: 700;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .dfn-placeholders-bar {
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
        .dfn-insert-tag-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 600;
            font-family: monospace;
            color: #0f172a;
            cursor: pointer;
            transition: all 0.1s ease;
        }
        .dfn-insert-tag-btn:hover {
            background: #eff6ff;
            border-color: #3b82f6;
            color: #1d4ed8;
            transform: translateY(-1px);
        }
        .dfn-email-dual-container {
            display: grid;
            gap: 18px;
            transition: all 0.2s ease;
        }
        .dfn-email-dual-container.mode-split {
            grid-template-columns: 1fr 1fr;
        }
        .dfn-email-dual-container.mode-code .dfn-preview-panel {
            display: none !important;
        }
        .dfn-email-dual-container.mode-preview .dfn-code-panel {
            display: none !important;
        }
        .dfn-email-dual-container.mode-preview {
            grid-template-columns: 1fr;
        }
        .dfn-email-dual-container.mode-code {
            grid-template-columns: 1fr;
        }
        .dfn-preview-mockup-wrapper {
            background: #f4f6f8;
            border-radius: 10px;
            padding: 20px 14px;
            border: 1px solid #cbd5e1;
            min-height: 280px;
        }
        .dfn-preview-mockup-card {
            max-width: 540px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.06);
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
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }
        .dfn-mockup-body {
            padding: 26px 22px;
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
        .dfn-code-textarea {
            width: 100%;
            height: 280px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.5;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1.5px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            box-sizing: border-box;
            resize: vertical;
        }
        .dfn-code-textarea:focus {
            border-color: #004b23;
            outline: none;
            box-shadow: 0 0 0 1px #004b23;
        }
    </style>

    <div class="wrap dfn-admin-wrap" style="max-width: 1200px;">
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
                Configura le notifiche, le regole di approvazione e personalizza i modelli email con anteprima grafica renderizzata in tempo reale.
            </p>
        </header>

        <!-- NAV TABS -->
        <h2 class="nav-tab-wrapper" style="margin-bottom: 24px; border-bottom: 2px solid #cbd5e1;">
            <a href="<?php echo esc_url(add_query_arg('tab', 'notifiche', $tab_url_base)); ?>" class="nav-tab <?php echo $active_tab === 'notifiche' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'notifiche' ? 'border-bottom-color:#fff; font-weight:700; color:#004b23;' : ''; ?>">
                🔔 Notifiche &amp; Destinatari
            </a>
            <a href="<?php echo esc_url(add_query_arg('tab', 'modelli-email', $tab_url_base)); ?>" class="nav-tab <?php echo $active_tab === 'modelli-email' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'modelli-email' ? 'border-bottom-color:#fff; font-weight:700; color:#004b23;' : ''; ?>">
                ✉️ Modelli Email Volontari (Doppia Vista Live)
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

            <!-- TAB 2: MODELLI EMAIL CON DOPPIA VISTA LIVE -->
            <?php if ($active_tab === 'modelli-email') : ?>
                <!-- GUIDA AI SEGNAPOSTO -->
                <div style="background:#f8fafc; border:1px solid #cbd5e1; border-left:4px solid #0284c7; border-radius:8px; padding:16px 20px; margin-bottom:24px;">
                    <h3 style="margin:0 0 6px; font-size:14.5px; font-weight:700; color:#0369a1;">
                        🏷️ Segnaposto (Placeholders) Dinamici
                    </h3>
                    <p style="font-size:13px; color:#334155; margin:0 0 8px; line-height:1.5;">
                        Clicca su uno dei bottoni sottostanti o inserisci i tag nel testo: verranno sostituiti in tempo reale sia nell'anteprima sottostante che nell'invio effettivo:
                    </p>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
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
                </div>

                <!-- 1. MODELLO EMAIL ADMIN -->
                <div class="dfn-email-editor-card" id="dfn-editor-card-1">
                    <div class="dfn-editor-header">
                        <div>
                            <span style="font-size:22px; vertical-align:middle; margin-right:6px;">👤</span>
                            <h2 style="font-size:17.5px; font-weight:700; color:#0f172a; margin:0; display:inline-block; vertical-align:middle;">
                                1. Email Notifica Nuovo Candidato (allo Staff / Amministratore)
                            </h2>
                        </div>
                        <div class="dfn-view-switch-group" data-target="dfn-container-1">
                            <button type="button" class="dfn-view-switch-btn active" data-mode="mode-split">
                                🪟 Vista Affiancata
                            </button>
                            <button type="button" class="dfn-view-switch-btn" data-mode="mode-preview">
                                👁️ Solo Grafica Renderizzata
                            </button>
                            <button type="button" class="dfn-view-switch-btn" data-mode="mode-code">
                                💻 Solo Codice HTML
                            </button>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Oggetto Email</label>
                            <input type="text" name="dfn_vol_settings[vol_email_admin_subject]" id="vol_email_admin_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_subject')); ?>" class="large-text dfn-live-subject-input" data-card="1" style="border-radius:6px; height:36px;" />
                        </div>
                        <div>
                            <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Titolo Intestazione Banner</label>
                            <input type="text" name="dfn_vol_settings[vol_email_admin_title]" id="vol_email_admin_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_admin_title')); ?>" class="large-text dfn-live-title-input" data-card="1" style="border-radius:6px; height:36px;" />
                        </div>
                    </div>

                    <div class="dfn-email-dual-container mode-split" id="dfn-container-1">
                        <!-- PANNELLO CODICE -->
                        <div class="dfn-code-panel">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase;">Codice HTML del Messaggio:</label>
                                <span style="font-size:11px; color:#64748b;">Modifiche sincronizzate in tempo reale &rarr;</span>
                            </div>
                            <textarea name="dfn_vol_settings[vol_email_admin_body]" id="vol_email_admin_body" class="dfn-code-textarea dfn-live-body-input" data-card="1"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_admin_body')); ?></textarea>
                        </div>

                        <!-- PANNELLO ANTEPRIMA RENDERIZZATA -->
                        <div class="dfn-preview-panel">
                            <div style="margin-bottom:6px;">
                                <label style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase;">✨ Anteprima Grafica Renderizzata:</label>
                            </div>
                            <div class="dfn-preview-mockup-wrapper">
                                <div class="dfn-preview-mockup-card">
                                    <div class="dfn-mockup-header">
                                        <h3 id="dfn-preview-title-1"><?php echo esc_html(dfn_get_volunteer_setting('vol_email_admin_title')); ?></h3>
                                    </div>
                                    <div class="dfn-mockup-body" id="dfn-preview-body-1">
                                        <!-- Inserito dinamicamente via JS con stili e placeholder renderizzati -->
                                    </div>
                                    <div class="dfn-mockup-footer">
                                        FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. MODELLO EMAIL CANDIDATO PENDING -->
                <div class="dfn-email-editor-card" id="dfn-editor-card-2">
                    <div class="dfn-editor-header">
                        <div>
                            <span style="font-size:22px; vertical-align:middle; margin-right:6px;">⏳</span>
                            <h2 style="font-size:17.5px; font-weight:700; color:#0f172a; margin:0; display:inline-block; vertical-align:middle;">
                                2. Email Presa in Carico (al Candidato - In Attesa di Verifica)
                            </h2>
                        </div>
                        <div class="dfn-view-switch-group" data-target="dfn-container-2">
                            <button type="button" class="dfn-view-switch-btn active" data-mode="mode-split">
                                🪟 Vista Affiancata
                            </button>
                            <button type="button" class="dfn-view-switch-btn" data-mode="mode-preview">
                                👁️ Solo Grafica Renderizzata
                            </button>
                            <button type="button" class="dfn-view-switch-btn" data-mode="mode-code">
                                💻 Solo Codice HTML
                            </button>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Oggetto Email</label>
                            <input type="text" name="dfn_vol_settings[vol_email_pending_subject]" id="vol_email_pending_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_subject')); ?>" class="large-text dfn-live-subject-input" data-card="2" style="border-radius:6px; height:36px;" />
                        </div>
                        <div>
                            <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Titolo Intestazione Banner</label>
                            <input type="text" name="dfn_vol_settings[vol_email_pending_title]" id="vol_email_pending_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_pending_title')); ?>" class="large-text dfn-live-title-input" data-card="2" style="border-radius:6px; height:36px;" />
                        </div>
                    </div>

                    <div class="dfn-email-dual-container mode-split" id="dfn-container-2">
                        <!-- PANNELLO CODICE -->
                        <div class="dfn-code-panel">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase;">Codice HTML del Messaggio:</label>
                                <span style="font-size:11px; color:#64748b;">Modifiche sincronizzate in tempo reale &rarr;</span>
                            </div>
                            <textarea name="dfn_vol_settings[vol_email_pending_body]" id="vol_email_pending_body" class="dfn-code-textarea dfn-live-body-input" data-card="2"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_pending_body')); ?></textarea>
                        </div>

                        <!-- PANNELLO ANTEPRIMA RENDERIZZATA -->
                        <div class="dfn-preview-panel">
                            <div style="margin-bottom:6px;">
                                <label style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase;">✨ Anteprima Grafica Renderizzata:</label>
                            </div>
                            <div class="dfn-preview-mockup-wrapper">
                                <div class="dfn-preview-mockup-card">
                                    <div class="dfn-mockup-header">
                                        <h3 id="dfn-preview-title-2"><?php echo esc_html(dfn_get_volunteer_setting('vol_email_pending_title')); ?></h3>
                                    </div>
                                    <div class="dfn-mockup-body" id="dfn-preview-body-2">
                                        <!-- Inserito dinamicamente via JS con stili e placeholder renderizzati -->
                                    </div>
                                    <div class="dfn-mockup-footer">
                                        FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. MODELLO EMAIL VOLONTARIO APPROVED -->
                <div class="dfn-email-editor-card" id="dfn-editor-card-3">
                    <div class="dfn-editor-header">
                        <div>
                            <span style="font-size:22px; vertical-align:middle; margin-right:6px;">🎉</span>
                            <h2 style="font-size:17.5px; font-weight:700; color:#0f172a; margin:0; display:inline-block; vertical-align:middle;">
                                3. Email di Approvazione &amp; Benvenuto (al Volontario Approvato)
                            </h2>
                        </div>
                        <div class="dfn-view-switch-group" data-target="dfn-container-3">
                            <button type="button" class="dfn-view-switch-btn active" data-mode="mode-split">
                                🪟 Vista Affiancata
                            </button>
                            <button type="button" class="dfn-view-switch-btn" data-mode="mode-preview">
                                👁️ Solo Grafica Renderizzata
                            </button>
                            <button type="button" class="dfn-view-switch-btn" data-mode="mode-code">
                                💻 Solo Codice HTML
                            </button>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Oggetto Email</label>
                            <input type="text" name="dfn_vol_settings[vol_email_approved_subject]" id="vol_email_approved_subject" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_subject')); ?>" class="large-text dfn-live-subject-input" data-card="3" style="border-radius:6px; height:36px;" />
                        </div>
                        <div>
                            <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Titolo Intestazione Banner</label>
                            <input type="text" name="dfn_vol_settings[vol_email_approved_title]" id="vol_email_approved_title" value="<?php echo esc_attr(dfn_get_volunteer_setting('vol_email_approved_title')); ?>" class="large-text dfn-live-title-input" data-card="3" style="border-radius:6px; height:36px;" />
                        </div>
                    </div>

                    <div class="dfn-email-dual-container mode-split" id="dfn-container-3">
                        <!-- PANNELLO CODICE -->
                        <div class="dfn-code-panel">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <label style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase;">Codice HTML del Messaggio:</label>
                                <span style="font-size:11px; color:#64748b;">Modifiche sincronizzate in tempo reale &rarr;</span>
                            </div>
                            <textarea name="dfn_vol_settings[vol_email_approved_body]" id="vol_email_approved_body" class="dfn-code-textarea dfn-live-body-input" data-card="3"><?php echo esc_textarea(dfn_get_volunteer_setting('vol_email_approved_body')); ?></textarea>
                        </div>

                        <!-- PANNELLO ANTEPRIMA RENDERIZZATA -->
                        <div class="dfn-preview-panel">
                            <div style="margin-bottom:6px;">
                                <label style="font-size:12px; font-weight:700; color:#004b23; text-transform:uppercase;">✨ Anteprima Grafica Renderizzata:</label>
                            </div>
                            <div class="dfn-preview-mockup-wrapper">
                                <div class="dfn-preview-mockup-card">
                                    <div class="dfn-mockup-header">
                                        <h3 id="dfn-preview-title-3"><?php echo esc_html(dfn_get_volunteer_setting('vol_email_approved_title')); ?></h3>
                                    </div>
                                    <div class="dfn-mockup-body" id="dfn-preview-body-3">
                                        <!-- Inserito dinamicamente via JS con stili e placeholder renderizzati -->
                                    </div>
                                    <div class="dfn-mockup-footer">
                                        FAI - Fondo per l'Ambiente Italiano &bull; <?php echo esc_html($delegation_name); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:20px; position:sticky; bottom:20px; z-index:10; background:rgba(255,255,255,0.92); backdrop-filter:blur(6px); padding:12px 18px; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 4px 15px rgba(0,0,0,0.08); display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:13.5px; color:#334155; font-weight:600;">
                        💾 Modifica liberamente i testi o il codice HTML e salva le tue modifiche.
                    </span>
                    <button type="submit" class="button button-primary button-large" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:6px 28px; font-size:15px;">
                        Salva Tutti i Modelli Email
                    </button>
                </div>

                <!-- SCRIPT LIVE PREVIEW E SWITCH VISTE -->
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

                    function renderPreview(cardIndex) {
                        var titleInput = document.querySelector('.dfn-live-title-input[data-card="' + cardIndex + '"]');
                        var bodyInput  = document.querySelector('.dfn-live-body-input[data-card="' + cardIndex + '"]');
                        var titleTarget = document.getElementById('dfn-preview-title-' + cardIndex);
                        var bodyTarget  = document.getElementById('dfn-preview-body-' + cardIndex);

                        if (!titleInput || !bodyInput || !titleTarget || !bodyTarget) return;

                        var rawTitle = titleInput.value || '';
                        var rawBody  = bodyInput.value || '';

                        // Sostituzione dei placeholder nel testo per renderizzare l'anteprima
                        for (var key in sampleData) {
                            var regex = new RegExp(key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g');
                            rawTitle = rawTitle.replace(regex, sampleData[key]);
                            rawBody  = rawBody.replace(regex, sampleData[key]);
                        }

                        titleTarget.textContent = rawTitle;
                        bodyTarget.innerHTML = rawBody;
                    }

                    // Inizializza tutte e 3 le anteprime
                    [1, 2, 3].forEach(function(i) {
                        renderPreview(i);
                    });

                    // Eventi di input in tempo reale
                    document.querySelectorAll('.dfn-live-title-input, .dfn-live-body-input').forEach(function(input) {
                        input.addEventListener('input', function() {
                            var card = this.dataset.card;
                            renderPreview(card);
                        });
                    });

                    // Switcher Modalità Vista (Split / Solo Anteprima / Solo Codice)
                    document.querySelectorAll('.dfn-view-switch-group').forEach(function(group) {
                        var targetId = group.dataset.target;
                        var container = document.getElementById(targetId);

                        group.querySelectorAll('.dfn-view-switch-btn').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                group.querySelectorAll('.dfn-view-switch-btn').forEach(function(b) { b.classList.remove('active'); });
                                this.classList.add('active');

                                var mode = this.dataset.mode;
                                container.className = 'dfn-email-dual-container ' + mode;
                            });
                        });
                    });

                    // Inserimento Tag al click sui badge
                    var activeTextarea = document.getElementById('vol_email_admin_body');
                    document.querySelectorAll('.dfn-code-textarea').forEach(function(ta) {
                        ta.addEventListener('focus', function() {
                            activeTextarea = this;
                        });
                    });

                    document.querySelectorAll('.dfn-insert-tag-btn').forEach(function(tagBtn) {
                        tagBtn.addEventListener('click', function() {
                            var tag = this.dataset.tag;
                            if (!activeTextarea) activeTextarea = document.getElementById('vol_email_admin_body');
                            
                            var start = activeTextarea.selectionStart;
                            var end = activeTextarea.selectionEnd;
                            var val = activeTextarea.value;

                            activeTextarea.value = val.substring(0, start) + tag + val.substring(end);
                            activeTextarea.focus();
                            activeTextarea.selectionStart = activeTextarea.selectionEnd = start + tag.length;

                            var card = activeTextarea.dataset.card;
                            if (card) renderPreview(card);
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

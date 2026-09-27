<?php
/**
 * DFN Booking System 2.0 & 2.1 — Registrazione & Candidatura Volontari FAI
 *
 * Gestisce la candidatura e registrazione online per i volontari di delegazione:
 * - URL dedicato: /registrazione-volontario/
 * - Shortcode: [dfn_registrazione_volontario]
 * - Controllo di sicurezza rigoroso: impedisce sovrascrittura password/dati di account esistenti
 * - Box di login integrato per utenti già registrati con recupero password
 * - Pre-popolamento automatico per utenti autenticati (omissione campi password)
 * - Workflow approvazione preventiva (status 'pending') con invio notifiche email
 * - Controllo live asincrono disponibilità email
 *
 * @package DFN_Theme
 * @since   2.4.2
 */

if (! defined('ABSPATH')) {
    exit;
}

// 1. Registrazione shortcode
add_shortcode('dfn_registrazione_volontario', 'dfn_render_volunteer_registration_shortcode');

// 2. Garantisce l'esistenza della pagina WordPress
add_action('init', 'dfn_ensure_volunteer_registration_page_exists');

// 3. Endpoint AJAX per controllo live esistenza email
add_action('wp_ajax_nopriv_dfn_check_volunteer_email', 'dfn_ajax_check_volunteer_email');
add_action('wp_ajax_dfn_check_volunteer_email', 'dfn_ajax_check_volunteer_email');

/**
 * Crea la pagina WordPress 'Registrazione Volontari FAI' se non esiste già.
 */
function dfn_ensure_volunteer_registration_page_exists(): void
{
    if (get_option('dfn_page_volunteer_reg_created') === 'yes') {
        return;
    }

    $page_slug = 'registrazione-volontario';
    $existing = get_page_by_path($page_slug);

    if (! $existing) {
        $page_id = wp_insert_post([
            'post_title'     => 'Registrazione Volontari FAI',
            'post_name'      => $page_slug,
            'post_content'   => '[dfn_registrazione_volontario]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]);
        if (! is_wp_error($page_id)) {
            update_option('dfn_page_volunteer_reg_created', 'yes');
        }
    } else {
        update_option('dfn_page_volunteer_reg_created', 'yes');
    }
}

/**
 * Endpoint AJAX: verifica se un'email è già registrata in WordPress.
 */
function dfn_ajax_check_volunteer_email(): void
{
    $email = sanitize_email($_POST['email'] ?? '');
    if (! is_email($email)) {
        wp_send_json_success(['exists' => false]);
    }

    if (is_user_logged_in()) {
        $current = wp_get_current_user();
        if (strtolower($current->user_email) === strtolower($email)) {
            wp_send_json_success(['exists' => false, 'is_self' => true]);
        }
    }

    $exists = (bool) email_exists($email);
    wp_send_json_success(['exists' => $exists]);
}

// Variabile globale temporanea per passare esito o errori da template_redirect allo shortcode
global $dfn_vol_reg_result;
$dfn_vol_reg_result = ['status' => '', 'message' => '', 'show_login' => false];

// 4. Intercetta l'URL /registrazione-volontario/ per elaborazione POST e template custom
add_action('template_redirect', 'dfn_handle_volunteer_registration_page_rewrite');

/**
 * Intercetta le richieste dirette a /registrazione-volontario/ e renderizza il template FAI.
 */
function dfn_handle_volunteer_registration_page_rewrite(): void
{
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');

    if ($path === 'registrazione-volontario' || strpos($path, 'registrazione-volontario') !== false) {
        global $wp_query, $dfn_vol_reg_result;

        // A. Gestione Login Inline
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfn_vol_login_nonce'])) {
            if (wp_verify_nonce($_POST['dfn_vol_login_nonce'], 'dfn_vol_login_action')) {
                $creds = [
                    'user_login'    => sanitize_text_field(wp_unslash($_POST['log'] ?? '')),
                    'user_password' => $_POST['pwd'] ?? '',
                    'remember'      => true,
                ];
                $signon = wp_signon($creds, is_ssl());
                if (is_wp_error($signon)) {
                    $dfn_vol_reg_result = [
                        'status'     => 'error',
                        'message'    => 'Credenziali di accesso non corrette: ' . strip_tags($signon->get_error_message()),
                        'show_login' => true,
                    ];
                } else {
                    wp_set_current_user($signon->ID);
                    wp_set_auth_cookie($signon->ID, true);
                    wp_safe_redirect(remove_query_arg(['login_error'], $request_uri));
                    exit;
                }
            } else {
                $dfn_vol_reg_result = [
                    'status'     => 'error',
                    'message'    => 'Sessione di accesso scaduta. Riprova.',
                    'show_login' => true,
                ];
            }
        }

        // B. Processa l'invio del form di candidatura
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfn_vol_reg_nonce'])) {
            $dfn_vol_reg_result = dfn_process_volunteer_registration();
        }

        if ($wp_query) {
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
        }
        status_header(200);

        // Titolo pagina dinamico
        add_filter('pre_get_document_title', function() {
            return 'Registrazione Volontari FAI — Delegazione FAI';
        }, 99);
        add_filter('wp_title', function() {
            return 'Registrazione Volontari FAI — Delegazione FAI';
        }, 99);

        get_header();
        echo '<div class="site-main dfn-vol-reg-page-wrapper" style="min-height:75vh; padding: 40px 16px; background: linear-gradient(180deg, #f0fdf4 0%, #f8fafc 100%);">';
        echo do_shortcode('[dfn_registrazione_volontario]');
        echo '</div>';
        get_footer();
        exit;
    }
}

/**
 * Processa l'invio del form di registrazione/candidatura volontario.
 *
 * @return array{status: string, message: string, show_login?: bool}
 */
function dfn_process_volunteer_registration(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($_POST['dfn_vol_reg_nonce'])) {
        return ['status' => '', 'message' => '', 'show_login' => false];
    }

    if (! wp_verify_nonce($_POST['dfn_vol_reg_nonce'], 'dfn_vol_reg_action')) {
        return ['status' => 'error', 'message' => 'Sessione scaduta. Ricarica la pagina e riprova.', 'show_login' => false];
    }

    $is_logged_in     = is_user_logged_in();
    $current_wp_user  = $is_logged_in ? wp_get_current_user() : null;

    $first_name       = function_exists('dfn_sanitize_name') ? dfn_sanitize_name($_POST['first_name'] ?? '') : sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
    $last_name        = function_exists('dfn_sanitize_name') ? dfn_sanitize_name($_POST['last_name'] ?? '') : sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
    $email            = sanitize_email($_POST['email'] ?? ($current_wp_user ? $current_wp_user->user_email : ''));
    $phone            = sanitize_text_field($_POST['phone'] ?? '');
    $username_input   = sanitize_user(trim($_POST['username'] ?? ''), true);
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $is_guide         = ! empty($_POST['is_guide']) ? 1 : 0;

    if (empty($first_name) || empty($last_name) || empty($email)) {
        return ['status' => 'error', 'message' => 'Compila tutti i campi obbligatori (Nome, Cognome, Email).', 'show_login' => false];
    }

    if (! is_email($email)) {
        return ['status' => 'error', 'message' => 'Indirizzo email non valido.', 'show_login' => false];
    }

    // =========================================================================
    // CONTROLLO DI SICUREZZA RIGOROSO: UTENTE NON AUTENTICATO
    // =========================================================================
    if (! $is_logged_in) {
        $existing_user_by_email = get_user_by('email', $email);
        if ($existing_user_by_email) {
            return [
                'status'     => 'error',
                'message'    => sprintf('L\'indirizzo email %s è già associato a un account esistente. Se sei già registrato su questo sito, effettua il login dal box in alto per candidarti con il tuo profilo.', esc_html($email)),
                'show_login' => true,
            ];
        }

        if (! empty($username_input) && username_exists($username_input)) {
            return [
                'status'     => 'error',
                'message'    => sprintf('Il nome utente "%s" è già in uso. Scegline un altro oppure effettua l\'accesso in alto.', esc_html($username_input)),
                'show_login' => true,
            ];
        }

        if (empty($password) || strlen($password) < 6) {
            return ['status' => 'error', 'message' => 'La password deve contenere almeno 6 caratteri.', 'show_login' => false];
        }

        if ($password !== $password_confirm) {
            return ['status' => 'error', 'message' => 'Le due password inserite non coincidono. Ricontrolla e riprova.', 'show_login' => false];
        }
    }

    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    // 1. Gestione Utente WordPress
    $user_id = 0;
    if ($is_logged_in && $current_wp_user) {
        $user_id = $current_wp_user->ID;
        // Aggiorna solo nome e cognome se presenti, NESSUNA modifica alla password
        wp_update_user([
            'ID'         => $user_id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
        ]);
    } else {
        // Creazione nuovo account utente WP
        $username = $username_input;
        if (empty($username)) {
            $username = sanitize_user(strtolower($first_name . '.' . $last_name), true);
        }

        if (username_exists($username)) {
            $username = $username . '.' . wp_rand(10, 99);
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            return ['status' => 'error', 'message' => 'Errore nella creazione dell\'account: ' . $user_id->get_error_message(), 'show_login' => false];
        }

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
            'role'         => 'subscriber', // Assegnato base; dfn_volunteer verrà aggiunto all'approvazione
        ]);
    }

    // Salvataggio metadati anagrafici utente
    if (! empty($phone)) {
        update_user_meta($user_id, 'billing_phone', $phone);
    }
    update_user_meta($user_id, 'billing_first_name', $first_name);
    update_user_meta($user_id, 'billing_last_name', $last_name);

    // 2. Controllo Impostazione Approvazione Preventiva
    $require_approval = function_exists('dfn_get_volunteer_setting') 
        ? dfn_get_volunteer_setting('vol_require_approval', 'yes') 
        : 'yes';

    $initial_status = ($require_approval === 'yes') ? 'pending' : 'active';
    $is_vol_flag    = 1;

    // 3. Gestione Record Volontario in dfn_fai_members
    $existing_member = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_fai} WHERE email = %s OR (user_id = %d AND user_id > 0) ORDER BY id ASC LIMIT 1",
        $email,
        $user_id
    ));

    $volunteer_data = [
        'first_name'       => $first_name,
        'last_name'        => $last_name,
        'email'            => $email,
        'phone'            => $phone,
        'user_id'          => $user_id,
        'is_volunteer'     => $is_vol_flag,
        'volunteer_status' => $initial_status,
        'is_guide'         => $is_guide,
        'volunteer_notes'  => 'Registrato tramite portale online',
        'created_at'       => current_time('mysql'),
    ];

    if ($existing_member) {
        $wpdb->update(
            $table_fai,
            [
                'user_id'          => $user_id,
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'phone'            => ! empty($phone) ? $phone : $existing_member->phone,
                'is_volunteer'     => 1,
                'volunteer_status' => $initial_status,
                'is_guide'         => $is_guide ? 1 : $existing_member->is_guide,
                'joined_date'      => ! empty($existing_member->joined_date) ? $existing_member->joined_date : current_time('Y-m-d'),
            ],
            [ 'id' => $existing_member->id ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ],
            [ '%d' ]
        );
        $volunteer_data['id'] = $existing_member->id;
        $volunteer_data['card_number'] = $existing_member->card_number;
    } else {
        $wpdb->insert(
            $table_fai,
            [
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'email'            => $email,
                'phone'            => $phone,
                'card_number'      => '',
                'card_expiry'      => null,
                'card_type'        => 'INDIVIDUALE',
                'verified'         => 0,
                'user_id'          => $user_id,
                'is_volunteer'     => 1,
                'volunteer_status' => $initial_status,
                'volunteer_notes'  => 'Registrato tramite portale online',
                'joined_date'      => current_time('Y-m-d'),
                'is_guide'         => $is_guide,
                'has_safety_course'=> 0,
                'created_at'       => current_time('mysql'),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );
        $volunteer_data['id'] = $wpdb->insert_id;
        $volunteer_data['card_number'] = '';
    }

    // 4. Invio Notifiche Email
    if (function_exists('dfn_send_volunteer_admin_notification')) {
        dfn_send_volunteer_admin_notification($volunteer_data, $user_id);
    }

    // Se è richiesta l'approvazione preventiva
    if ($require_approval === 'yes') {
        if (function_exists('dfn_send_volunteer_candidate_pending_email')) {
            dfn_send_volunteer_candidate_pending_email($volunteer_data);
        }

        if (function_exists('dfn_log_write')) {
            dfn_log_write(
                'volontari',
                trim($first_name . ' ' . $last_name),
                sprintf("Nuova candidatura volontario in attesa di approvazione: %s %s (%s)", $first_name, $last_name, $email),
                'info'
            );
        }

        return [
            'status'     => 'pending_success',
            'message'    => 'Candidatura inviata con successo!',
            'data'       => $volunteer_data,
            'show_login' => false
        ];
    }

    // Se l'approvazione NON è richiesta (attivazione istantanea)
    $wp_user_obj = get_userdata($user_id);
    if ($wp_user_obj) {
        $wp_user_obj->add_role('dfn_volunteer');
        $current_assigned = (array) get_user_meta($user_id, '_dfn_assigned_fai_roles', true);
        if (! in_array('dfn_volunteer', $current_assigned, true)) {
            $current_assigned[] = 'dfn_volunteer';
            update_user_meta($user_id, '_dfn_assigned_fai_roles', array_unique($current_assigned));
        }
    }

    if (function_exists('dfn_send_volunteer_approved_email')) {
        dfn_send_volunteer_approved_email($volunteer_data, $user_id);
    }

    if (function_exists('dfn_log_write')) {
        dfn_log_write(
            'volontari',
            trim($first_name . ' ' . $last_name),
            sprintf("Nuova registrazione volontario attiva istantanea: %s %s (%s)", $first_name, $last_name, $email),
            'success'
        );
    }

    // Auto-Login e redirect
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);

    $redirect_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('volontari-fai') : site_url('/mio-account/volontari-fai/');
    wp_safe_redirect(add_query_arg(['welcome_volunteer' => '1'], $redirect_url));
    exit;
}

/**
 * Renderizza il form frontend di registrazione volontario.
 *
 * @param array<string, mixed> $atts Attributi shortcode.
 * @return string HTML del form o messaggio di conferma.
 */
function dfn_render_volunteer_registration_shortcode($atts = []): string
{
    global $dfn_vol_reg_result;
    $result = is_array($dfn_vol_reg_result) ? $dfn_vol_reg_result : ['status' => '', 'message' => '', 'show_login' => false];

    // Se inviato login da shortcode non intercettato da template_redirect
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfn_vol_login_nonce']) && empty($result['status'])) {
        if (wp_verify_nonce($_POST['dfn_vol_login_nonce'], 'dfn_vol_login_action')) {
            $creds = [
                'user_login'    => sanitize_text_field(wp_unslash($_POST['log'] ?? '')),
                'user_password' => $_POST['pwd'] ?? '',
                'remember'      => true,
            ];
            $signon = wp_signon($creds, is_ssl());
            if (is_wp_error($signon)) {
                $result = [
                    'status'     => 'error',
                    'message'    => 'Credenziali di accesso non corrette: ' . strip_tags($signon->get_error_message()),
                    'show_login' => true,
                ];
            } else {
                wp_set_current_user($signon->ID);
                wp_set_auth_cookie($signon->ID, true);
                wp_safe_redirect($_SERVER['REQUEST_URI'] ?? home_url('/registrazione-volontario/'));
                exit;
            }
        }
    }

    // 1. Schermata di avvenuta candidatura in attesa di approvazione
    if ($result['status'] === 'pending_success') {
        $delegation_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
        $home_url = home_url('/');

        return '<div class="dfn-vol-reg-container" style="max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04); overflow: hidden; border: 1px solid #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; text-align: center;">
            <div style="background: linear-gradient(135deg, #004b23 0%, #002e15 100%); color: #ffffff; padding: 36px 28px;">
                <div style="display: inline-flex; align-items: center; justify-content: center; width: 68px; height: 68px; background: rgba(255,255,255,0.18); border-radius: 50%; font-size: 36px; margin-bottom: 16px;">
                    ⏳
                </div>
                <h1 style="color: #ffffff; margin: 0 0 8px; font-size: 24px; font-weight: 800;">
                    Candidatura Ricevuta con Successo!
                </h1>
                <p style="color: #d1fae5; font-size: 15px; margin: 0; line-height: 1.5;">
                    Grazie per aver scelto di dedicare il tuo tempo e le tue energie alla squadra di <strong>' . esc_html($delegation_name) . '</strong>.
                </p>
            </div>
            <div style="padding: 32px 28px;">
                <div style="background: #f0fdf4; border: 1.5px solid #86efac; border-radius: 12px; padding: 20px; text-align: left; margin-bottom: 24px;">
                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                        <span style="font-size: 24px;">📋</span>
                        <div>
                            <strong style="color: #166534; font-size: 15px; display: block; margin-bottom: 4px;">Cosa succede adesso?</strong>
                            <p style="color: #334155; font-size: 13.5px; margin: 0; line-height: 1.5;">
                                Un amministratore di Delegazione esaminerà la tua richiesta nei prossimi giorni. Non appena la candidatura sarà approvata, riceverai un\'<strong>email di benvenuto</strong> con tutte le istruzioni per accedere alla tua Bacheca Volontario e consultare i turni disponibili.
                            </p>
                        </div>
                    </div>
                </div>
                <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                    <a href="' . esc_url($home_url) . '" class="button" style="background: #004b23; color: #ffffff; padding: 12px 22px; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-block; font-size: 14.5px;">
                        🏛️ Torna alla Home
                    </a>
                </div>
            </div>
        </div>';
    }

    // 2. Controllo se l'utente è già loggato e ha già una posizione
    $current_user = is_user_logged_in() ? wp_get_current_user() : null;
    $is_active_vol = false;
    $is_pending_vol = false;

    if ($current_user) {
        global $wpdb;
        $table_fai = $wpdb->prefix . 'dfn_fai_members';
        $vol_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_fai} WHERE (user_id = %d OR email = %s) AND is_volunteer = 1 ORDER BY id DESC LIMIT 1",
            $current_user->ID,
            $current_user->user_email
        ));

        if ($vol_record) {
            if ($vol_record->volunteer_status === 'active') {
                $is_active_vol = true;
            } elseif ($vol_record->volunteer_status === 'pending') {
                $is_pending_vol = true;
            }
        }
    }

    // Se è già un volontario attivo
    if ($is_active_vol && $current_user) {
        $account_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('volontari-fai') : site_url('/mio-account/volontari-fai/');
        return '<div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 36px 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); text-align: center; border-top: 6px solid #004b23; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
            <div style="font-size: 48px; margin-bottom: 14px;">🎉</div>
            <h2 style="color: #004b23; margin: 0 0 10px; font-size: 22px; font-weight: 800;">Sei già registrato come Volontario FAI!</h2>
            <p style="color: #475569; font-size: 15px; margin-bottom: 24px; line-height: 1.5;">Ciao <strong>' . esc_html($current_user->display_name) . '</strong>, il tuo profilo volontario è attivo e abilitato a consultare i turni e le attività di Delegazione.</p>
            <a href="' . esc_url($account_url) . '" class="button" style="background: #004b23; color: #ffffff; padding: 12px 26px; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-block; font-size: 15px;">
                🏛️ Vai alla tua Bacheca Volontario &rarr;
            </a>
        </div>';
    }

    // Se ha già inviato una candidatura che è in attesa di approvazione
    if ($is_pending_vol && $current_user) {
        return '<div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 36px 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); text-align: center; border-top: 6px solid #eab308; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
            <div style="font-size: 48px; margin-bottom: 14px;">⏳</div>
            <h2 style="color: #b45309; margin: 0 0 10px; font-size: 22px; font-weight: 800;">Candidatura in Fase di Approvazione</h2>
            <p style="color: #475569; font-size: 15px; margin-bottom: 20px; line-height: 1.5;">Ciao <strong>' . esc_html($current_user->display_name) . '</strong>, la tua richiesta di adesione come Volontario FAI è stata registrata ed è attualmente in fase di revisione da parte dello staff di Delegazione.</p>
            <div style="background: #fefce8; border: 1px solid #fef08a; padding: 14px 18px; border-radius: 8px; font-size: 14px; color: #854d0e; text-align: left; line-height: 1.5;">
                Riceverai un\'email di conferma non appena la tua candidatura sarà approvata e abilitata all\'accesso turni.
            </div>
        </div>';
    }

    // Pre-popolamento dati utente loggato
    $pre_first_name = $_POST['first_name'] ?? ($current_user ? $current_user->first_name : '');
    $pre_last_name  = $_POST['last_name'] ?? ($current_user ? $current_user->last_name : '');
    $pre_email      = $_POST['email'] ?? ($current_user ? $current_user->user_email : '');
    $pre_phone      = $_POST['phone'] ?? ($current_user ? get_user_meta($current_user->ID, 'billing_phone', true) : '');
    $pre_username   = $_POST['username'] ?? ($current_user ? $current_user->user_login : '');

    $show_login_init = ! empty($result['show_login']);

    $lost_password_url = function_exists('wc_get_endpoint_url') && function_exists('wc_get_page_permalink') 
        ? wc_get_endpoint_url('lost-password', '', wc_get_page_permalink('myaccount')) 
        : wp_lostpassword_url(get_permalink());

    ob_start();
    ?>
    <div class="dfn-vol-reg-container" style="max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04); overflow: hidden; border: 1px solid #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
        
        <!-- HEADER FORM -->
        <div style="background: linear-gradient(135deg, #004b23 0%, #002e15 100%); color: #ffffff; padding: 30px 28px; text-align: center;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: rgba(255,255,255,0.15); border-radius: 50%; font-size: 30px; margin-bottom: 14px;">
                👥
            </div>
            <h1 style="color: #ffffff; margin: 0 0 8px; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">
                Entra nella Squadra Volontari FAI
            </h1>
            <p style="color: #d1fae5; font-size: 14px; margin: 0; line-height: 1.5;">
                Invia la tua candidatura per partecipare alle Giornate FAI, alle attività culturali e alle riunioni di Delegazione.
            </p>
        </div>

        <!-- CORPO DEL FORM -->
        <div style="padding: 28px 28px 32px;">
            
            <?php if (! empty($result['message'])) : ?>
                <div style="background: <?php echo $result['status'] === 'error' ? '#fef2f2' : '#f0fdf4'; ?>; border: 1.5px solid <?php echo $result['status'] === 'error' ? '#fecaca' : '#bbf7d0'; ?>; color: <?php echo $result['status'] === 'error' ? '#991b1b' : '#166534'; ?>; padding: 14px 18px; border-radius: 10px; margin-bottom: 22px; font-size: 13.5px; font-weight: 600; line-height: 1.5;">
                    <?php echo $result['status'] === 'error' ? '⚠️ ' : '✅ '; ?><?php echo esc_html($result['message']); ?>
                </div>
            <?php endif; ?>

            <!-- BOX LOGIN PER UTENTI GIÀ REGISTRATI (SE NON AUTENTICATI) -->
            <?php if (! $current_user) : ?>
                <div class="dfn-vol-login-card" id="dfn-vol-login-card" style="background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px; transition: all 0.2s ease;">
                    <div style="display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none;" id="dfn-toggle-login-btn">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 22px;">🔑</span>
                            <div>
                                <strong style="color: #004b23; font-size: 14.5px; display: block;">Sei già registrato sul sito?</strong>
                                <span style="font-size: 12.5px; color: #64748b;">Accedi con il tuo account per autocompilare la candidatura</span>
                            </div>
                        </div>
                        <span id="dfn-login-toggle-text" style="font-size: 12.5px; font-weight: 700; color: #004b23; background: #e0f2fe; padding: 5px 12px; border-radius: 6px; white-space: nowrap;">
                            <?php echo $show_login_init ? 'Chiudi &uarr;' : 'Accedi &darr;'; ?>
                        </span>
                    </div>

                    <div id="dfn-vol-login-form-wrapper" style="<?php echo $show_login_init ? 'display: block;' : 'display: none;'; ?> margin-top: 16px; padding-top: 16px; border-top: 1px dashed #cbd5e1;">
                        <form method="post" action="" id="dfn-vol-inline-login-form" style="margin: 0;">
                            <?php wp_nonce_field('dfn_vol_login_action', 'dfn_vol_login_nonce'); ?>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Email o Nome Utente *</label>
                                    <input type="text" name="log" id="dfn-login-user-input" required placeholder="tua@email.it" style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; box-sizing: border-box; outline: none;" />
                                </div>
                                <div>
                                    <label style="display: block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">Password *</label>
                                    <input type="password" name="pwd" required placeholder="••••••••" style="width: 100%; padding: 8px 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; box-sizing: border-box; outline: none;" />
                                </div>
                            </div>
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 4px;">
                                <button type="submit" class="button" style="background: #004b23; color: #ffffff; padding: 8px 20px; border: none; border-radius: 6px; font-weight: 700; font-size: 13.5px; cursor: pointer;">
                                    Accedi e Compila &rarr;
                                </button>
                                <a href="<?php echo esc_url($lost_password_url); ?>" target="_blank" style="font-size: 12.5px; color: #0284c7; text-decoration: underline; font-weight: 600;">
                                    Hai dimenticato la password?
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else : ?>
                <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 12px; padding: 14px 18px; margin-bottom: 24px; font-size: 13.5px; color: #166534; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 22px;">👤</span>
                        <div>
                            Connesso come <strong><?php echo esc_html($current_user->display_name); ?></strong> (<code><?php echo esc_html($current_user->user_email); ?></code>).
                            <div style="font-size: 12px; color: #15803d; margin-top: 2px;">La candidatura verrà collegata in modo sicuro al tuo account esistente.</div>
                        </div>
                    </div>
                    <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>" style="font-size: 12.5px; color: #dc2626; text-decoration: underline; font-weight: 600;">
                        Esci / Cambia account
                    </a>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="dfn-volunteer-registration-form" style="margin: 0;">
                <?php wp_nonce_field('dfn_vol_reg_action', 'dfn_vol_reg_nonce'); ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Nome *
                        </label>
                        <input type="text" name="first_name" id="dfn-reg-first-name" required placeholder="Mario" value="<?php echo esc_attr($pre_first_name); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none; transition: border-color 0.15s ease;" />
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Cognome *
                        </label>
                        <input type="text" name="last_name" id="dfn-reg-last-name" required placeholder="Rossi" value="<?php echo esc_attr($pre_last_name); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none; transition: border-color 0.15s ease;" />
                    </div>
                </div>

                <?php if (! $current_user) : ?>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Nome Utente (Username) *
                        </label>
                        <input type="text" name="username" id="dfn-reg-username" required placeholder="mario.rossi" value="<?php echo esc_attr($pre_username); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none;" />
                        <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                            Scegli il tuo nome utente di accesso per la Bacheca Volontari.
                        </span>
                    </div>
                <?php endif; ?>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Indirizzo Email *
                    </label>
                    <input type="email" name="email" id="dfn-reg-email" required placeholder="mario.rossi@email.com" value="<?php echo esc_attr($pre_email); ?>" <?php echo $current_user ? 'readonly style="background:#f8fafc; color:#64748b; width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box;"' : 'style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none;"'; ?> />
                    <span id="dfn-email-status-desc" style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                        Riceverai qui le comunicazioni di approvazione e le notifiche di delegazione.
                    </span>
                    <div id="dfn-email-duplicate-warning" style="display:none; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:8px; margin-top:8px; font-size:12.5px; font-weight:600;">
                        ⚠️ Questa email risulta già registrata. <a href="#" id="dfn-trigger-open-login" style="color:#0284c7; text-decoration:underline;">Clicca qui per accedere in alto</a> e compila la candidatura con il tuo account.
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Numero di Cellulare <span style="font-weight: 400; color: #64748b;">(Opzionale)</span>
                    </label>
                    <input type="tel" name="phone" placeholder="333 1234567" value="<?php echo esc_attr($pre_phone); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box;" />
                    <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                        Utile per i contatti logistici rapidi durante gli eventi e le Giornate FAI.
                    </span>
                </div>

                <!-- SEZIONE PASSWORD: SOLO PER UTENTI NON ANCORA REGISTRATI -->
                <?php if (! $current_user) : ?>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 6px;">
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                                Crea Password *
                            </label>
                            <div style="position: relative; display: flex; align-items: center;">
                                <input type="password" name="password" id="dfn-reg-pass" required minlength="6" placeholder="Min. 6 caratteri" style="width: 100%; padding: 10px 40px 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box;" />
                                <button type="button" class="dfn-toggle-pass-btn" data-target="dfn-reg-pass" title="Mostra/Nascondi password" style="position: absolute; right: 8px; background: none; border: none; font-size: 16px; cursor: pointer; color: #64748b; padding: 4px;">
                                    👁️
                                </button>
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                                Conferma Password *
                            </label>
                            <div style="position: relative; display: flex; align-items: center;">
                                <input type="password" name="password_confirm" id="dfn-reg-pass-confirm" required minlength="6" placeholder="Ripeti password" style="width: 100%; padding: 10px 40px 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box;" />
                                <button type="button" class="dfn-toggle-pass-btn" data-target="dfn-reg-pass-confirm" title="Mostra/Nascondi password" style="position: absolute; right: 8px; background: none; border: none; font-size: 16px; cursor: pointer; color: #64748b; padding: 4px;">
                                    👁️
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="dfn-pass-match-msg" style="font-size: 11.5px; color: #64748b; margin-bottom: 18px;">
                        Ti servirà per accedere alla tua Area Personale una volta approvata la candidatura.
                    </div>
                <?php endif; ?>

                <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 24px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin: 0;">
                        <input type="checkbox" name="is_guide" value="1" <?php checked(! empty($_POST['is_guide']), true); ?> style="width: 18px; height: 18px; margin-top: 2px; accent-color: #004b23;" />
                        <div>
                            <span style="font-size: 13.5px; font-weight: 700; color: #1e293b; display: block;">
                                🏛️ Disponibile come Guida Culturale / Cicerone
                            </span>
                            <span style="font-size: 12px; color: #64748b; line-height: 1.4; display: block; margin-top: 2px;">
                                Spunta questa opzione se desideri raccontare e illustrare beni, chiese e luoghi aperti al pubblico.
                            </span>
                        </div>
                    </label>
                </div>

                <button type="submit" class="button" style="width: 100%; background: #004b23; color: #ffffff; padding: 14px 20px; border: none; border-radius: 10px; font-size: 16px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,75,35,0.25); transition: background 0.2s ease;">
                    ✨ Invia Candidatura Volontario &rarr;
                </button>
            </form>

            <div style="margin-top: 20px; text-align: center; font-size: 12px; color: #94a3b8;">
                🔒 I tuoi dati personali saranno trattati nel rispetto del GDPR esclusivamente per le finalità istituzionali del FAI.
            </div>
        </div>

    </div>

    <!-- SCRIPT GESTIONE LOGIN TOGGLE, CHECK EMAIL E VALIDAZIONE PASSWORD -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';

        // 1. Toggle Box Login
        var toggleBtn = document.getElementById('dfn-toggle-login-btn');
        var loginWrapper = document.getElementById('dfn-vol-login-form-wrapper');
        var toggleText = document.getElementById('dfn-login-toggle-text');
        var loginCard = document.getElementById('dfn-vol-login-card');
        var loginUserInput = document.getElementById('dfn-login-user-input');

        function openLoginForm() {
            if (!loginWrapper) return;
            loginWrapper.style.display = 'block';
            if (toggleText) toggleText.innerHTML = 'Chiudi &uarr;';
            if (loginCard) {
                loginCard.style.borderColor = '#004b23';
                loginCard.style.background = '#f0fdf4';
            }
            if (loginUserInput) loginUserInput.focus();
        }

        function closeLoginForm() {
            if (!loginWrapper) return;
            loginWrapper.style.display = 'none';
            if (toggleText) toggleText.innerHTML = 'Accedi &darr;';
            if (loginCard) {
                loginCard.style.borderColor = '#cbd5e1';
                loginCard.style.background = '#f8fafc';
            }
        }

        if (toggleBtn && loginWrapper) {
            toggleBtn.addEventListener('click', function(e) {
                if (loginWrapper.style.display === 'none' || loginWrapper.style.display === '') {
                    openLoginForm();
                } else {
                    closeLoginForm();
                }
            });
        }

        var triggerOpenLogin = document.getElementById('dfn-trigger-open-login');
        if (triggerOpenLogin) {
            triggerOpenLogin.addEventListener('click', function(e) {
                e.preventDefault();
                openLoginForm();
                if (loginCard) loginCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
        }

        // 2. Controllo live esistenza email (blur)
        var emailInput = document.getElementById('dfn-reg-email');
        var duplicateWarning = document.getElementById('dfn-email-duplicate-warning');
        <?php if (! $current_user) : ?>
        if (emailInput && duplicateWarning) {
            emailInput.addEventListener('blur', function() {
                var emailVal = this.value.trim();
                if (emailVal.length > 3 && emailVal.indexOf('@') !== -1) {
                    var formData = new FormData();
                    formData.append('action', 'dfn_check_volunteer_email');
                    formData.append('email', emailVal);

                    fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success && res.data && res.data.exists) {
                            duplicateWarning.style.display = 'block';
                            emailInput.style.borderColor = '#ef4444';
                            openLoginForm();
                            if (loginUserInput && !loginUserInput.value) {
                                loginUserInput.value = emailVal;
                            }
                        } else {
                            duplicateWarning.style.display = 'none';
                            emailInput.style.borderColor = '#cbd5e1';
                        }
                    })
                    .catch(function() {});
                } else {
                    duplicateWarning.style.display = 'none';
                }
            });
        }
        <?php endif; ?>

        // 3. Toggle mostra/nascondi password
        document.querySelectorAll('.dfn-toggle-pass-btn').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var targetId = this.dataset.target;
                var input = document.getElementById(targetId);
                if (input) {
                    if (input.type === 'password') {
                        input.type = 'text';
                        this.textContent = '🙈';
                    } else {
                        input.type = 'password';
                        this.textContent = '👁️';
                    }
                }
            });
        });

        // 4. Auto-compilazione dinamica Username (nome.cognome)
        var firstNameInput = document.getElementById('dfn-reg-first-name');
        var lastNameInput = document.getElementById('dfn-reg-last-name');
        var usernameInput = document.getElementById('dfn-reg-username');
        var userModifiedUsername = false;

        if (usernameInput) {
            usernameInput.addEventListener('input', function() {
                if (this.value.trim().length > 0) {
                    userModifiedUsername = true;
                } else {
                    userModifiedUsername = false;
                }
            });
        }

        function updateSuggestedUsername() {
            if (userModifiedUsername || !usernameInput || !firstNameInput || !lastNameInput) return;
            var f = firstNameInput.value.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
            var l = lastNameInput.value.trim().toLowerCase().replace(/[^a-z0-9]/g, '');
            if (f && l) {
                usernameInput.value = f + '.' + l;
            } else if (f) {
                usernameInput.value = f;
            } else if (l) {
                usernameInput.value = l;
            }
        }

        if (firstNameInput && lastNameInput) {
            firstNameInput.addEventListener('input', updateSuggestedUsername);
            lastNameInput.addEventListener('input', updateSuggestedUsername);
        }

        // 5. Controllo live corrispondenza password
        var pass = document.getElementById('dfn-reg-pass');
        var passConfirm = document.getElementById('dfn-reg-pass-confirm');
        var msg = document.getElementById('dfn-pass-match-msg');
        var form = document.getElementById('dfn-volunteer-registration-form');

        function checkPasswordMatch() {
            if (!pass || !passConfirm || !msg) return;
            if (!passConfirm.value) {
                msg.innerHTML = 'Ti servirà per accedere alla tua Area Personale dal tuo smartphone o computer.';
                msg.style.color = '#64748b';
                passConfirm.style.borderColor = '#cbd5e1';
                return;
            }
            if (pass.value === passConfirm.value) {
                msg.innerHTML = '✅ Le password coincidono perfettamente.';
                msg.style.color = '#166534';
                passConfirm.style.borderColor = '#86efac';
            } else {
                msg.innerHTML = '⚠️ Le due password non coincidono.';
                msg.style.color = '#dc2626';
                passConfirm.style.borderColor = '#fca5a5';
            }
        }

        if (pass && passConfirm) {
            pass.addEventListener('input', checkPasswordMatch);
            passConfirm.addEventListener('input', checkPasswordMatch);
        }

        if (form && pass && passConfirm) {
            form.addEventListener('submit', function(e) {
                if (pass.value !== passConfirm.value) {
                    e.preventDefault();
                    alert('Attenzione: Le due password inserite non coincidono. Ricontrolla prima di inviare.');
                    passConfirm.focus();
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

<?php
/**
 * DFN Booking System 2.0 & 2.1 — Modulo Sondaggio Disponibilità Volontari FAI
 *
 * Gestisce la pagina/form di sondaggio per raccogliere la disponibilità
 * oraria dei volontari per le Giornate FAI e gli Eventi Locali.
 *
 * Funzionalità:
 * - Pre-compilazione automatica per volontari già loggati.
 * - Box di login rapido inline per volontari già registrati.
 * - Registrazione istantanea account volontario per nuovi utenti con auto-login e notifica di benvenuto.
 * - Selezione dinamica turni orari configurati per ciascun giorno.
 * - Visualizzazione riepilogativa e salvataggio idempotente delle risposte.
 *
 * @package DFN_Theme
 * @since   2.4.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// 1. Registrazione shortcode per il sondaggio
add_shortcode('dfn_sondaggio_volontari', 'dfn_render_volunteer_survey_shortcode');

// 2. Intercetta l'URL virtuale /sondaggio-volontari/
add_action('template_redirect', 'dfn_handle_volunteer_survey_page_rewrite');

/**
 * Intercetta le richieste dirette a /sondaggio-volontari/ e renderizza il template FAI.
 */
function dfn_handle_volunteer_survey_page_rewrite(): void
{
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');

    if ($path === 'sondaggio-volontari' || strpos($path, 'sondaggio-volontari') !== false) {
        // Gestione Login Rapido Inline
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfn_survey_login_nonce'])) {
            if (wp_verify_nonce($_POST['dfn_survey_login_nonce'], 'dfn_survey_login_action')) {
                $creds = [
                    'user_login'    => sanitize_text_field(wp_unslash($_POST['log'] ?? '')),
                    'user_password' => $_POST['pwd'] ?? '',
                    'remember'      => true,
                ];
                $signon = wp_signon($creds, is_ssl());
                $token  = sanitize_text_field($_GET['token'] ?? '');
                if (! is_wp_error($signon)) {
                    wp_set_current_user($signon->ID);
                    wp_set_auth_cookie($signon->ID, true);
                    wp_safe_redirect(add_query_arg('token', $token, home_url('/sondaggio-volontari/')));
                    exit;
                } else {
                    wp_safe_redirect(add_query_arg(['token' => $token, 'login_error' => '1'], home_url('/sondaggio-volontari/')));
                    exit;
                }
            }
        }

        global $wp_query;
        if ($wp_query) {
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
        }
        status_header(200);

        // Titolo dinamico del documento per evitare "Pagina non trovata"
        add_filter('pre_get_document_title', function() {
            return 'Sondaggio Disponibilità Volontari — FAI Novara';
        }, 99);
        add_filter('wp_title', function() {
            return 'Sondaggio Disponibilità Volontari — FAI Novara';
        }, 99);

        get_header();
        echo '<div class="site-main dfn-survey-page-wrapper" style="min-height:70vh; padding: 40px 16px; background:#f8fafc;">';
        echo do_shortcode('[dfn_sondaggio_volontari]');
        echo '</div>';
        get_footer();
        exit;
    }
}

/**
 * Renderizza il form di sondaggio disponibilità con supporto login e registrazione contestuale.
 *
 * @param array<string, mixed> $atts Attributi shortcode.
 * @return string HTML del sondaggio.
 */
function dfn_render_volunteer_survey_shortcode($atts = []): string
{
    global $wpdb;

    $atts = shortcode_atts([
        'token' => isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '',
    ], $atts, 'dfn_sondaggio_volontari');

    $token = $atts['token'];
    if (empty($token)) {
        return '<div class="dfn-survey-alert error" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px; border-radius:8px; text-align:center; font-family:sans-serif;">'
             . '⚠️ <strong>Link del sondaggio non valido o mancante.</strong><br>Verifica il link ricevuto dalla Delegazione FAI.'
             . '</div>';
    }

    $survey = dfn_get_volunteer_survey_by_token($token);
    if (! $survey) {
        return '<div class="dfn-survey-alert error" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px; border-radius:8px; text-align:center; font-family:sans-serif;">'
             . '❌ <strong>Sondaggio non trovato.</strong><br>Questo sondaggio potrebbe essere stato rimosso o archiviato.'
             . '</div>';
    }

    $event = dfn_get_volunteer_event((int) $survey->event_id);
    if (! $event) {
        return '<div class="dfn-survey-alert error" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px; border-radius:8px; text-align:center; font-family:sans-serif;">'
             . '❌ <strong>Evento non trovato.</strong>'
             . '</div>';
    }

    $now = current_time('mysql');
    $is_expired = ($survey->status === 'closed' || $survey->deadline_at < $now);

    // Recupero dati utente loggato (se presente)
    $current_user_id = get_current_user_id();
    $is_user_logged  = (bool) $current_user_id;
    $user            = null;
    $volunteer       = null;
    $user_first_name = '';
    $user_last_name  = '';
    $user_email      = '';
    $user_phone      = '';
    $user_notes      = '';
    $just_registered = false;

    $login_error = isset($_GET['login_error']) && $_GET['login_error'] === '1';
    $feedback_msg = '';
    if ($login_error) {
        $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; border:1.5px solid #fca5a5; color:#991b1b; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:13.5px;">'
                      . '❌ <strong>Errore di accesso:</strong> Email/Username o password errati. Riprova o <a href="' . esc_url(wp_lostpassword_url()) . '" target="_blank" style="color:#991b1b; text-decoration:underline; font-weight:700;">recupera la password</a>.'
                      . '</div>';
    }

    if ($current_user_id) {
        $user = wp_get_current_user();
        $volunteer = dfn_get_volunteer_by_user($current_user_id);
        if ($volunteer) {
            $user_first_name = $volunteer->first_name;
            $user_last_name  = $volunteer->last_name;
            $user_email      = $volunteer->email;
            $user_phone      = $volunteer->phone ?: '';
        } else {
            $user_first_name = $user->first_name ?: $user->display_name;
            $user_last_name  = $user->last_name ?: '';
            $user_email      = $user->user_email;
            $user_phone      = get_user_meta($current_user_id, 'billing_phone', true) ?: '';
        }
    }

    // Giorni dell'evento
    $days = dfn_get_volunteer_event_days((int) $event->id);

    // Se l'utente ha già risposto in precedenza, recuperiamo le risposte
    $saved_responses = [];
    $saved_pref_place_id = 0;
    if ($volunteer) {
        $table_resp = $wpdb->prefix . 'dfn_volunteer_survey_responses';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_resp} WHERE survey_id = %d AND volunteer_id = %d",
            $survey->id,
            $volunteer->id
        ));
        foreach ($rows as $r) {
            $saved_responses[ $r->day_id . '_' . $r->time_slot_key ] = (int) $r->is_available;
            if (! empty($r->notes)) {
                $user_notes = $r->notes;
            }
            if (! empty($r->preferred_place_id)) {
                $saved_pref_place_id = (int) $r->preferred_place_id;
            }
        }
    }

    // Gestione invio form sondaggio
    if (isset($_POST['dfn_submit_survey']) && wp_verify_nonce($_POST['dfn_survey_nonce'] ?? '', 'dfn_survey_submit_action')) {
        if ($is_expired) {
            $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">⚠️ Il termine per rispondere a questo sondaggio è scaduto.</div>';
        } else {
            $f_name        = function_exists('dfn_sanitize_name') ? dfn_sanitize_name($_POST['first_name'] ?? '') : sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
            $l_name        = function_exists('dfn_sanitize_name') ? dfn_sanitize_name($_POST['last_name'] ?? '') : sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
            $f_email       = sanitize_email($_POST['email'] ?? '');
            $f_phone       = sanitize_text_field($_POST['phone'] ?? '');
            $f_pwd         = $_POST['password'] ?? '';
            $f_pwd_confirm = $_POST['password_confirm'] ?? '';
            $f_notes       = sanitize_textarea_field($_POST['notes'] ?? '');
            $f_pref_place  = isset($_POST['preferred_place_id']) && (int) $_POST['preferred_place_id'] > 0 ? (int) $_POST['preferred_place_id'] : null;
            $slots_selected= isset($_POST['slots']) && is_array($_POST['slots']) ? $_POST['slots'] : [];

            $has_error = false;

            // Validazione campi base
            if (empty($f_name) || empty($l_name)) {
                $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">❌ Inserisci Nome e Cognome.</div>';
                $has_error = true;
            } elseif (empty($f_email) || ! is_email($f_email)) {
                $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">❌ Inserisci un indirizzo email valido.</div>';
                $has_error = true;
            }

            // Se l'utente NON è loggato, gestiamo la registrazione o autenticazione
            if (! $has_error && ! $current_user_id) {
                $existing_user_id = email_exists($f_email);

                if ($existing_user_id) {
                    // L'email esiste già in WordPress
                    if (! empty($f_pwd)) {
                        $auth_user = wp_authenticate($f_email, $f_pwd);
                        if (! is_wp_error($auth_user)) {
                            $current_user_id = $auth_user->ID;
                            wp_set_current_user($current_user_id);
                            wp_set_auth_cookie($current_user_id, true);
                        } else {
                            $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">⚠️ Questo indirizzo email risulta già registrato ma la password non è corretta. Accedi tramite il box in alto o usa <em>"Hai dimenticato la password?"</em>.</div>';
                            $has_error = true;
                        }
                    } else {
                        $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">⚠️ Questo indirizzo email risulta già registrato. Inserisci la tua password o accedi tramite il box in alto.</div>';
                        $has_error = true;
                    }
                } else {
                    // NUOVO UTENTE: verifica password
                    if (empty($f_pwd) || strlen($f_pwd) < 6) {
                        $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">❌ Inserisci una password di almeno 6 caratteri per creare il tuo account volontario.</div>';
                        $has_error = true;
                    } elseif ($f_pwd !== $f_pwd_confirm) {
                        $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">❌ Le due password inserite non coincidono. Ricontrolla e riprova.</div>';
                        $has_error = true;
                    } else {
                        // Creazione account WordPress
                        $username = sanitize_user(strtolower($f_name . '.' . $l_name), true);
                        if (empty($username) || username_exists($username)) {
                            $username = $username . '.' . wp_rand(10, 99);
                        }

                        $new_uid = wp_create_user($username, $f_pwd, $f_email);
                        if (is_wp_error($new_uid)) {
                            $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">❌ Errore nella creazione dell\'account: ' . esc_html($new_uid->get_error_message()) . '</div>';
                            $has_error = true;
                        } else {
                            $current_user_id = $new_uid;
                            wp_update_user([
                                'ID'           => $new_uid,
                                'first_name'   => $f_name,
                                'last_name'    => $l_name,
                                'display_name' => trim($f_name . ' ' . $l_name),
                            ]);

                            $user_obj = new WP_User($new_uid);
                            $user_obj->add_role('dfn_volunteer');

                            update_user_meta($new_uid, 'billing_phone', $f_phone);
                            update_user_meta($new_uid, 'billing_first_name', $f_name);
                            update_user_meta($new_uid, 'billing_last_name', $l_name);

                            // Auto-login immediato
                            wp_set_current_user($new_uid);
                            wp_set_auth_cookie($new_uid, true);
                            $just_registered = true;
                        }
                    }
                }
            }

            if (! $has_error) {
                $table_fai = $wpdb->prefix . 'dfn_fai_members';
                $table_resp = $wpdb->prefix . 'dfn_volunteer_survey_responses';

                // Gestione record anagrafico in wp_dfn_fai_members
                $existing_member = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table_fai} WHERE (user_id = %d AND user_id > 0) OR email = %s ORDER BY id ASC LIMIT 1",
                    $current_user_id ?: 0,
                    $f_email
                ));

                $vol_data = [
                    'first_name'       => $f_name,
                    'last_name'        => $l_name,
                    'email'            => $f_email,
                    'phone'            => $f_phone,
                    'card_number'      => '',
                    'card_expiry'      => null,
                    'card_type'        => 'INDIVIDUALE',
                    'verified'         => 0,
                    'user_id'          => $current_user_id ?: null,
                    'is_volunteer'     => 1,
                    'volunteer_status' => 'active',
                    'volunteer_notes'  => 'Registrato tramite sondaggio disponibilità (' . $survey->title . ')',
                    'joined_date'      => current_time('Y-m-d'),
                    'is_guide'         => 0,
                    'has_safety_course'=> 0,
                    'created_at'       => current_time('mysql'),
                ];

                if ($existing_member) {
                    $wpdb->update(
                        $table_fai,
                        [
                            'user_id'          => $current_user_id ?: $existing_member->user_id,
                            'first_name'       => $f_name,
                            'last_name'        => $l_name,
                            'phone'            => $f_phone,
                            'is_volunteer'     => 1,
                            'volunteer_status' => 'active',
                        ],
                        [ 'id' => $existing_member->id ]
                    );
                    $vol_id = (int) $existing_member->id;
                    $vol_data['id'] = $vol_id;
                } else {
                    $wpdb->insert($table_fai, $vol_data);
                    $vol_id = (int) $wpdb->insert_id;
                    $vol_data['id'] = $vol_id;
                }

                // Invio email di benvenuto e notifica se appena registrato
                if ($just_registered) {
                    if (function_exists('dfn_send_volunteer_approved_email')) {
                        dfn_send_volunteer_approved_email($vol_data, $current_user_id);
                    }
                    if (function_exists('dfn_send_volunteer_admin_notification')) {
                        dfn_send_volunteer_admin_notification($vol_data, $current_user_id);
                    }
                }

                // Rimuovi risposte precedenti per questo utente
                if ($vol_id) {
                    $wpdb->delete($table_resp, [ 'survey_id' => $survey->id, 'volunteer_id' => $vol_id ], [ '%d', '%d' ]);
                }
                if (! empty($f_email)) {
                    $wpdb->delete($table_resp, [ 'survey_id' => $survey->id, 'email' => $f_email ], [ '%d', '%s' ]);
                }

                // Inserisci le disponibilità per ciascun giorno e fascia
                foreach ($days as $day) {
                    $day_shifts = $wpdb->get_results($wpdb->prepare(
                        "SELECT DISTINCT shift_label, time_start, time_end FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC",
                        $day->id
                    ));

                    if (empty($day_shifts)) {
                        continue;
                    }

                    foreach ($day_shifts as $sh) {
                        $slot_k = sanitize_key($sh->shift_label . '_' . substr($sh->time_start, 0, 5));
                        $compound_key = $day->id . '_' . $slot_k;
                        $is_avail = ! empty($slots_selected[$compound_key]) ? 1 : 0;

                        $wpdb->insert(
                            $table_resp,
                            [
                                'survey_id'          => $survey->id,
                                'volunteer_id'       => $vol_id,
                                'day_id'             => $day->id,
                                'time_slot_key'      => $slot_k,
                                'is_available'       => $is_avail,
                                'preferred_place_id' => $f_pref_place,
                                'notes'              => $f_notes,
                                'submitted_at'       => current_time('mysql'),
                            ],
                            [ '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
                        );

                        $saved_responses[$compound_key] = $is_avail;
                    }
                }

                $user_first_name = $f_name;
                $user_last_name  = $l_name;
                $user_email      = $f_email;
                $user_phone      = $f_phone;
                $user_notes      = $f_notes;
                $volunteer       = dfn_get_volunteer_by_user($current_user_id);

                $account_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/mio-account/');

                $extra_info = $just_registered 
                    ? '<p style="margin:8px 0 0 0; font-size:13.5px; font-weight:normal;">Il tuo account volontario è stato creato con successo e ti abbiamo inviato un\'email di benvenuto. Puoi accedere alla tua <a href="' . esc_url($account_url) . '" style="color:#166534; font-weight:800; text-decoration:underline;">Area Personale Volontari</a> per visualizzare i turni e i tuoi dati.</p>'
                    : '';

                $feedback_msg = '<div class="notice notice-success" style="background:#dcfce7; border:1.5px solid #86efac; color:#166534; padding:18px 20px; border-radius:12px; margin-bottom:24px; text-align:center;">'
                              . '<div style="font-size:16px; font-weight:800;">🎉 Grazie ' . esc_html($f_name) . '! Le tue preferenze di disponibilità sono state registrate con successo.</div>'
                              . $extra_info
                              . '</div>';
            }
        }
    }

    $deadline_formatted = date_i18n('l d F Y \a\l\l\e H:i', strtotime($survey->deadline_at));
    $is_user_logged = ! empty($current_user_id);

    // Calcolo conteggio aggregato adesioni per ciascuno slot e per ciascun giorno (in tempo reale)
    $table_resp = $wpdb->prefix . 'dfn_volunteer_survey_responses';
    $slot_counts = [];
    $day_counts  = [];

    $slot_count_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT day_id, time_slot_key, COUNT(DISTINCT volunteer_id) as total_volunteers
         FROM {$table_resp}
         WHERE survey_id = %d AND is_available = 1
         GROUP BY day_id, time_slot_key",
        $survey->id
    ));
    if (! empty($slot_count_rows)) {
        foreach ($slot_count_rows as $scr) {
            $slot_counts[ $scr->day_id . '_' . $scr->time_slot_key ] = (int) $scr->total_volunteers;
        }
    }

    $day_count_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT day_id, COUNT(DISTINCT volunteer_id) as total_volunteers
         FROM {$table_resp}
         WHERE survey_id = %d AND is_available = 1
         GROUP BY day_id",
        $survey->id
    ));
    if (! empty($day_count_rows)) {
        foreach ($day_count_rows as $dcr) {
            $day_counts[ $dcr->day_id ] = (int) $dcr->total_volunteers;
        }
    }

    ob_start();
    ?>
    <div class="dfn-survey-container dfn-survey-form-container" style="max-width: 680px; margin: 30px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 28px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;">
        
        <!-- Header Sondaggio -->
        <div class="dfn-survey-header" style="text-align: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 24px;">
            <span style="display:inline-block; font-size:12px; font-weight:800; text-transform:uppercase; color:#004b23; background:#e8f5e9; padding:4px 14px; border-radius:20px; margin-bottom:10px;">
                📋 Sondaggio Delegazione FAI Novara
            </span>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 8px 0; line-height: 1.3;">
                <?php echo esc_html($survey->title); ?>
            </h1>
            <p style="font-size: 14px; color: #64748b; margin: 0;">
                <?php echo esc_html($event->title); ?> • 🗓️ <?php echo esc_html(date_i18n('d/m/Y', strtotime($event->date_start))); ?> - <?php echo esc_html(date_i18n('d/m/Y', strtotime($event->date_end))); ?>
            </p>

            <div style="margin-top: 14px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: <?php echo $is_expired ? '#dc2626' : '#166534'; ?>; font-weight: 700; background: <?php echo $is_expired ? '#fef2f2' : '#f0fdf4'; ?>; padding: 6px 14px; border-radius: 10px; border: 1px solid <?php echo $is_expired ? '#fecaca' : '#bbf7d0'; ?>;">
                <span>⏱️</span>
                <?php if ($is_expired) : ?>
                    <span>Termine per le risposte SCADUTO (<?php echo esc_html($deadline_formatted); ?>)</span>
                <?php else : ?>
                    <span>Scadenza invio preferenze: <?php echo esc_html($deadline_formatted); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php echo $feedback_msg; ?>

        <?php if ($is_expired) : ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-left: 5px solid #dc2626; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px;">
                <div style="font-size: 14px; font-weight: 800; color: #991b1b; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <span>🔒</span> Sondaggio Chiuso
                </div>
                <div style="font-size: 13px; color: #7f1d1d; line-height: 1.5;">
                    Le risposte per questo evento sono state chiuse per procedere con l'assegnazione dei turni.
                    <?php if ($is_user_logged) : ?>
                        <?php if (! empty($saved_responses)) : ?>
                            Di seguito puoi visualizzare il riepilogo delle disponibilità che hai inviato.
                        <?php else : ?>
                            Non risultano risposte registrate a tuo nome prima della chiusura del sondaggio.
                        <?php endif; ?>
                    <?php else : ?>
                        Se sei un volontario registrato o hai già risposto in precedenza, <strong>effettua il login qui sotto</strong> per verificare le tue disponibilità o accedere alla tua Area Personale.
                    <?php endif; ?>
                </div>
                <?php if ($is_user_logged && empty($saved_responses)) : ?>
                    <div style="margin-top: 12px;">
                        <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/mio-account/')); ?>" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; background: #004b23; color: #ffffff !important; padding: 7px 15px; border-radius: 6px; text-decoration: none;">
                            👤 Vai alla tua Area Personale
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($is_user_logged) : ?>
            <!-- Banner Utente Autenticato -->
            <div style="background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 12px; padding: 14px 18px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">🟢</span>
                    <div>
                        <strong style="font-size: 14px; color: #166534; display: block;">
                            Autenticato come <?php echo esc_html(trim($user_first_name . ' ' . $user_last_name) ?: ($user ? $user->display_name : 'Volontario')); ?>
                        </strong>
                        <span style="font-size: 12px; color: #15803d;"><?php echo esc_html($user_email); ?></span>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <a href="<?php echo esc_url(function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/mio-account/')); ?>" style="font-size: 12px; font-weight: 700; color: #004b23; text-decoration: none; background: #e8f5e9; padding: 6px 12px; border-radius: 6px; border: 1px solid #a7f3d0; display: inline-flex; align-items: center; gap: 4px;">
                        👤 Area Personale Volontari
                    </a>
                    <a href="<?php echo esc_url(wp_logout_url(add_query_arg('token', $token, home_url('/sondaggio-volontari/')))); ?>" style="font-size: 12px; font-weight: 700; color: #dc2626; text-decoration: none; background: #fee2e2; padding: 6px 12px; border-radius: 6px; border: 1px solid #fca5a5;">
                        Esci
                    </a>
                </div>
            </div>
        <?php else : ?>
            <!-- Box Invito al Login Rapido per chi è già registrato (Colori Ufficiali FAI) -->
            <div class="dfn-survey-login-prompt" style="background: #f0fdf4; border: 1.5px solid #86efac; border-left: 5px solid #004b23; border-radius: 12px; padding: 14px 18px; margin-bottom: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 22px;">🔑</span>
                        <div>
                            <strong style="font-size: 14px; color: #004b23; display: block;">Sei già registrato come volontario?</strong>
                            <span style="font-size: 12.5px; color: #166534;">
                                <?php echo $is_expired ? 'Accedi per verificare subito le tue risposte o consultare la tua Area Personale' : 'Accedi per autocompilare istantaneamente i tuoi dati'; ?>
                            </span>
                        </div>
                    </div>
                    <button type="button" id="dfn-survey-toggle-login-btn" class="button" style="background: #004b23; color: #ffffff !important; border: none; font-weight: 700; font-size: 12.5px; border-radius: 6px; padding: 7px 16px; cursor: pointer; box-shadow: 0 2px 6px rgba(0,75,35,0.2); transition: all 0.2s;">
                        Accedi subito &darr;
                    </button>
                </div>

                <!-- Form Login Inline -->
                <div id="dfn-survey-login-box" style="display: <?php echo $login_error ? 'block' : 'none'; ?>; margin-top: 14px; padding-top: 14px; border-top: 1px dashed #86efac;">
                    <form method="post" action="" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: flex-end;">
                        <?php wp_nonce_field('dfn_survey_login_action', 'dfn_survey_login_nonce'); ?>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #004b23; margin-bottom: 4px;">Email o Username</label>
                            <input type="text" name="log" required placeholder="mario.rossi@email.it" style="width: 100%; border-radius: 6px; border: 1.5px solid #86efac; height: 36px; padding: 0 10px; font-size: 13px; background: #ffffff; color: #0f172a; outline: none;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; font-weight: 700; color: #004b23; margin-bottom: 4px;">Password</label>
                            <input type="password" name="pwd" required placeholder="••••••••" style="width: 100%; border-radius: 6px; border: 1.5px solid #86efac; height: 36px; padding: 0 10px; font-size: 13px; background: #ffffff; color: #0f172a; outline: none;">
                        </div>
                        <div>
                            <button type="submit" name="dfn_survey_login" class="button button-primary" style="background: #004b23 !important; color: #ffffff !important; border: 1px solid #002e15 !important; height: 36px; font-weight: 700; padding: 0 18px; border-radius: 6px; cursor: pointer; box-shadow: 0 2px 6px rgba(0,75,35,0.2);">
                                Entra
                            </button>
                        </div>
                    </form>
                    <div style="margin-top: 8px; font-size: 11.5px; text-align: right;">
                        <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" target="_blank" style="color: #004b23; text-decoration: underline; font-weight: 600;">Hai dimenticato la password?</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (! $is_expired || ! empty($saved_responses)) : ?>
            <form method="post" action="" class="dfn-survey-form" style="<?php echo $is_expired ? 'opacity: 0.85;' : ''; ?>">
                <?php if (! $is_expired) : ?>
                    <?php wp_nonce_field('dfn_survey_submit_action', 'dfn_survey_nonce'); ?>
                <?php endif; ?>

                <!-- Dati Anagrafici & Registrazione -->
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
                        👤 <?php echo $is_user_logged ? 'I tuoi dati volontario' : 'I tuoi dati (Crea il tuo account volontario)'; ?>
                    </h3>
                    <?php if (! $is_user_logged && ! $is_expired) : ?>
                        <p style="font-size: 12.5px; color: #64748b; margin: 0 0 14px 0;">
                            Inserisci i tuoi recapiti e scegli una password per accedere in futuro alla tua Area Personale Volontari.
                        </p>
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Nome <?php echo ! $is_expired ? '<span style="color:#ef4444;">*</span>' : ''; ?></label>
                            <input type="text" name="first_name" <?php echo ($is_expired || $is_user_logged) ? 'readonly' : 'required'; ?> value="<?php echo esc_attr($user_first_name); ?>" placeholder="Es. Mario" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo ($is_expired || $is_user_logged) ? 'background:#f1f5f9; color:#475569;' : ''; ?>">
                        </div>
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Cognome <?php echo ! $is_expired ? '<span style="color:#ef4444;">*</span>' : ''; ?></label>
                            <input type="text" name="last_name" <?php echo ($is_expired || $is_user_logged) ? 'readonly' : 'required'; ?> value="<?php echo esc_attr($user_last_name); ?>" placeholder="Es. Rossi" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo ($is_expired || $is_user_logged) ? 'background:#f1f5f9; color:#475569;' : ''; ?>">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Email <?php echo ! $is_expired ? '<span style="color:#ef4444;">*</span>' : ''; ?></label>
                            <input type="email" name="email" <?php echo ($is_expired || $is_user_logged) ? 'readonly' : 'required'; ?> value="<?php echo esc_attr($user_email); ?>" placeholder="mario.rossi@email.it" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo ($is_expired || $is_user_logged) ? 'background:#f1f5f9; color:#475569;' : ''; ?>">
                        </div>
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Telefono / Cellulare <span style="font-size: 11px; font-weight: normal; color: #64748b;">(facoltativo)</span></label>
                            <input type="tel" name="phone" <?php echo $is_expired ? 'disabled readonly' : ''; ?> value="<?php echo esc_attr($user_phone); ?>" placeholder="Es. 333 1234567" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>">
                        </div>
                    </div>

                    <?php if (! $is_user_logged && ! $is_expired) : ?>
                        <!-- Campi Creazione Password per Utenti non registrati -->
                        <div style="background: #fdfefe; border: 1.5px solid #cbd5e1; border-left: 4px solid #004b23; border-radius: 10px; padding: 14px 16px; margin-top: 12px;">
                            <div style="font-size: 13px; font-weight: 700; color: #004b23; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                                <span>🔒</span> Imposta la Password di Accesso
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                                <div>
                                    <label style="display:block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">
                                        Crea Password <span style="color:#ef4444;">*</span>
                                    </label>
                                    <div style="position: relative; display: flex; align-items: center;">
                                        <input type="password" name="password" id="dfn_survey_pwd" required minlength="6" placeholder="Minimo 6 caratteri" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 38px; padding: 0 36px 0 10px; font-size: 13.5px;">
                                        <button type="button" onclick="dfnToggleSurveyPwd('dfn_survey_pwd', this)" style="position: absolute; right: 6px; background: none; border: none; font-size: 14px; cursor: pointer; padding: 4px;" title="Mostra/Nascondi password">👁️</button>
                                    </div>
                                </div>
                                <div>
                                    <label style="display:block; font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px;">
                                        Conferma Password <span style="color:#ef4444;">*</span>
                                    </label>
                                    <div style="position: relative; display: flex; align-items: center;">
                                        <input type="password" name="password_confirm" id="dfn_survey_pwd_confirm" required minlength="6" placeholder="Ripeti la password" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 38px; padding: 0 36px 0 10px; font-size: 13.5px;">
                                        <button type="button" onclick="dfnToggleSurveyPwd('dfn_survey_pwd_confirm', this)" style="position: absolute; right: 6px; background: none; border: none; font-size: 14px; cursor: pointer; padding: 4px;" title="Mostra/Nascondi password">👁️</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sezione Selezione Slot per Giorno Dinamici -->
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
                        📅 <?php echo $is_expired ? 'Le tue disponibilità inviate' : 'Seleziona le tue disponibilità'; ?>
                    </h3>
                    <p style="font-size: 13px; color: #64748b; margin: 0 0 14px 0;">
                        <?php echo $is_expired ? 'Riepilogo delle fasce orarie in cui hai indicato la tua disponibilità:' : 'Indica i turni orari in cui sei disponibile. Il luogo e l\'incarico a cui verrai assegnato saranno stabiliti dalla Delegazione.'; ?>
                    </p>

                    <?php if (! $is_expired) : ?>
                        <!-- Box di suggerimento per la copertura omogenea -->
                        <div style="background: #f0fdf4; border: 1.5px solid #86efac; border-left: 5px solid #004b23; border-radius: 12px; padding: 12px 16px; margin-bottom: 18px; display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 20px; flex-shrink: 0;">💡</span>
                            <div style="font-size: 13px; color: #166534; line-height: 1.45;">
                                <strong>Copertura in tempo reale:</strong> Sotto a ciascun turno vedi quante persone si sono già rese disponibili. Se ti è possibile, segnalati nelle fasce orarie con meno adesioni per aiutarci a garantire una presenza uniforme!
                            </div>
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <?php 
                        $rendered_days_count = 0;
                        foreach ($days as $day) : 
                            $d_time = strtotime($day->event_date);
                            $d_title = date_i18n('l d F Y', $d_time);

                            // Recupera i turni definiti per questo giorno
                            $day_shifts = $wpdb->get_results($wpdb->prepare(
                                "SELECT DISTINCT shift_label, time_start, time_end FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC",
                                $day->id
                            ));

                            // Se non ci sono slot orari configurati per questo giorno, il giorno viene escluso dal sondaggio
                            if (empty($day_shifts)) {
                                continue;
                            }
                            $rendered_days_count++;

                            $day_vol_count = $day_counts[$day->id] ?? 0;
                            $day_count_label = ($day_vol_count === 1)
                                ? sprintf(__('%d volontario registrato', 'dfn-theme'), $day_vol_count)
                                : sprintf(__('%d volontari registrati', 'dfn-theme'), $day_vol_count);
                        ?>
                            <div class="dfn-survey-day-block" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                                <div style="font-size: 14px; font-weight: 800; color: #004b23; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                    <div style="display: flex; align-items: center; gap: 6px;">
                                        <span>🗓️</span> <?php echo esc_html(ucfirst($d_title)); ?>
                                    </div>
                                    <span style="font-size: 11.5px; font-weight: 700; color: #475569; background: #ffffff; border: 1px solid #cbd5e1; padding: 3px 10px; border-radius: 20px; display: inline-flex; align-items: center; gap: 4px;">
                                        👥 <?php echo esc_html($day_count_label); ?>
                                    </span>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                                    <?php foreach ($day_shifts as $sh) : 
                                        $slot_k = sanitize_key($sh->shift_label . '_' . substr($sh->time_start, 0, 5));
                                        $compound_key = $day->id . '_' . $slot_k;
                                        $is_checked = ! empty($saved_responses[$compound_key]);
                                        $time_range = substr($sh->time_start, 0, 5) . ' - ' . substr($sh->time_end, 0, 5);
                                        $slot_vol_count = $slot_counts[$compound_key] ?? 0;

                                        if ($slot_vol_count === 0) {
                                            $coverage_badge = '<span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; color:#b45309; background:#fef3c7; border:1px solid #fde68a; padding:2px 8px; border-radius:12px; margin-top:5px;">⚠️ 0 volontari (Servono aiuti!)</span>';
                                        } elseif ($slot_vol_count === 1) {
                                            $coverage_badge = '<span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; color:#1e40af; background:#dbeafe; border:1px solid #bfdbfe; padding:2px 8px; border-radius:12px; margin-top:5px;">👥 1 volontario segnato</span>';
                                        } else {
                                            $coverage_badge = '<span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; color:#065f46; background:#d1fae5; border:1px solid #a7f3d0; padding:2px 8px; border-radius:12px; margin-top:5px;">👥 ' . sprintf(esc_html__('%d volontari segnati', 'dfn-theme'), $slot_vol_count) . '</span>';
                                        }
                                    ?>
                                        <label style="display: flex; align-items: flex-start; gap: 12px; background: <?php echo ($is_expired && $is_checked) ? '#e8f5e9' : '#ffffff'; ?>; border: 1.5px solid <?php echo $is_checked ? '#004b23' : '#cbd5e1'; ?>; padding: 12px 14px; border-radius: 10px; cursor: <?php echo $is_expired ? 'default' : 'pointer'; ?>; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                                            <input type="checkbox" name="slots[<?php echo esc_attr($compound_key); ?>]" value="1" <?php checked($is_checked, true); ?> <?php echo $is_expired ? 'disabled' : ''; ?> style="width: 18px; height: 18px; accent-color: #004b23; margin-top: 2px; <?php echo $is_expired ? 'cursor:default;' : ''; ?>">
                                            <div style="flex-grow: 1;">
                                                <div style="display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 4px;">
                                                    <strong style="font-size: 13.5px; color: <?php echo ($is_expired && $is_checked) ? '#004b23' : '#0f172a'; ?>;">
                                                        <?php echo esc_html($sh->shift_label); ?>
                                                        <?php if ($is_expired && $is_checked) : ?>
                                                            <span style="font-size: 11px; color: #166534; font-weight: 800;">(Disponibile ✅)</span>
                                                        <?php endif; ?>
                                                    </strong>
                                                    <span style="font-size: 12px; color: #64748b; font-weight: 600;">(<?php echo esc_html($time_range); ?>)</span>
                                                </div>
                                                <div>
                                                    <?php echo $coverage_badge; ?>
                                                </div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($rendered_days_count === 0) : ?>
                            <div style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 24px; text-align: center; color: #64748b;">
                                ℹ️ Non ci sono ancora turni orari configurati per questo evento.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (function_exists('dfn_get_volunteer_setting') && dfn_get_volunteer_setting('vol_survey_enable_preferred_place', 'no') === 'yes') : 
                    $event_places = function_exists('dfn_get_volunteer_event_all_places') ? dfn_get_volunteer_event_all_places((int) $event->id) : [];
                    if (! empty($event_places)) : ?>
                        <div style="margin-bottom: 20px;">
                            <label style="display:block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                                🏛️ Preferenza Luogo Desiderato <span style="font-size: 11.5px; font-weight: normal; color: #64748b;">(Opzionale)</span>
                            </label>
                            <select name="preferred_place_id" <?php echo $is_expired ? 'disabled' : ''; ?> style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 42px; padding: 0 12px; font-size: 13.5px; background: #ffffff; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>">
                                <option value="">-- Nessuna preferenza / Indifferente --</option>
                                <?php foreach ($event_places as $ep) : ?>
                                    <option value="<?php echo esc_attr($ep->id); ?>" <?php selected($saved_pref_place_id, (int) $ep->id); ?>>
                                        <?php echo esc_html($ep->place_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p style="font-size: 11.5px; color: #64748b; margin: 4px 0 0 0;">
                                Se hai una preferenza per un luogo specifico tra quelli aperti, indicalo qui: l'organizzazione cercherà di accontentarti nei limiti delle disponibilità.
                            </p>
                        </div>
                <?php endif; endif; ?>

                <!-- Note / Preferenze aggiuntive -->
                <div style="margin-bottom: 24px;">
                    <label style="display:block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px;">Note o preferenze speciali</label>
                    <textarea name="notes" rows="3" <?php echo $is_expired ? 'disabled readonly' : ''; ?> placeholder="<?php echo $is_expired ? 'Nessuna nota specificata' : 'Es. Preferenza per luogo specifico, disponibilità solo fino alle 17:00, in coppia con...'; ?>" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; padding: 10px; font-size: 13.5px; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>"><?php echo esc_textarea($user_notes); ?></textarea>
                </div>

                <!-- Pulsante Submit (solo se sondaggio aperto) -->
                <?php if (! $is_expired) : ?>
                    <button type="submit" name="dfn_submit_survey" class="button button-primary dfn-survey-submit-btn" style="background: #004b23; border: none; border-radius: 50px; color: #ffffff; font-weight: 800; font-size: 15.5px; width: 100%; padding: 15px 20px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,75,35,0.25); transition: all 0.2s;">
                        <?php echo $is_user_logged ? '💾 Invia le mie Disponibilità' : '🚀 Registrati e Invia la tua Disponibilità'; ?>
                    </button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var toggleBtn = document.getElementById('dfn-survey-toggle-login-btn');
        var loginBox = document.getElementById('dfn-survey-login-box');
        if (toggleBtn && loginBox) {
            <?php if ($login_error) : ?>
                toggleBtn.innerHTML = 'Chiudi Login &uarr;';
            <?php endif; ?>
            toggleBtn.addEventListener('click', function() {
                if (loginBox.style.display === 'none' || !loginBox.style.display) {
                    loginBox.style.display = 'block';
                    toggleBtn.innerHTML = 'Chiudi Login &uarr;';
                } else {
                    loginBox.style.display = 'none';
                    toggleBtn.innerHTML = 'Accedi subito &darr;';
                }
            });
        }
    });

    function dfnToggleSurveyPwd(inputId, btn) {
        var el = document.getElementById(inputId);
        if (!el) return;
        if (el.type === 'password') {
            el.type = 'text';
            btn.textContent = '🙈';
        } else {
            el.type = 'password';
            btn.textContent = '👁️';
        }
    }
    </script>
    <?php
    return ob_get_clean();
}

<?php
/**
 * DFN Booking System 2.0 — Registrazione Rapida Volontari FAI
 *
 * Gestisce la registrazione rapida e user-friendly per i volontari di delegazione:
 * - URL virtuale dedicato: /registrazione-volontario/
 * - Shortcode: [dfn_registrazione_volontario]
 * - Creazione account WordPress + creazione anagrafica Volontario Attivo
 * - Auto-login istantaneo e reindirizzamento all'Area Volontari (/mio-account/eventi-fai/)
 *
 * @package DFN_Theme
 * @since   2.4.1
 */

if (! defined('ABSPATH')) {
    exit;
}

// 1. Registrazione shortcode
add_shortcode('dfn_registrazione_volontario', 'dfn_render_volunteer_registration_shortcode');

// 2. Creazione/Garantisce l'esistenza della pagina fisica WordPress
add_action('init', 'dfn_ensure_volunteer_registration_page_exists');

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

// Variabile globale temporanea per passare eventuali errori di validazione da template_redirect allo shortcode
global $dfn_vol_reg_result;
$dfn_vol_reg_result = ['status' => '', 'message' => ''];

/**
 * Intercetta le richieste dirette a /registrazione-volontario/ e renderizza il template FAI.
 */
function dfn_handle_volunteer_registration_page_rewrite(): void
{
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');

    if ($path === 'registrazione-volontario' || strpos($path, 'registrazione-volontario') !== false) {
        global $wp_query, $dfn_vol_reg_result;

        // Processa prima l'invio del form POST PRIMA che qualsiasi HTML o header venga inviato
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
 * Processa l'invio del form di registrazione volontario.
 *
 * @return array{status: string, message: string}
 */
function dfn_process_volunteer_registration(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset($_POST['dfn_vol_reg_nonce'])) {
        return ['status' => '', 'message' => ''];
    }

    if (! wp_verify_nonce($_POST['dfn_vol_reg_nonce'], 'dfn_vol_reg_action')) {
        return ['status' => 'error', 'message' => 'Sessione scaduta. Ricarica la pagina e riprova.'];
    }

    $first_name       = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name        = sanitize_text_field($_POST['last_name'] ?? '');
    $username_input   = sanitize_user(trim($_POST['username'] ?? ''), true);
    $email            = sanitize_email($_POST['email'] ?? '');
    $phone            = sanitize_text_field($_POST['phone'] ?? '');
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $is_guide         = ! empty($_POST['is_guide']) ? 1 : 0;

    if (empty($first_name) || empty($last_name) || empty($email)) {
        return ['status' => 'error', 'message' => 'Compila tutti i campi obbligatori (Nome, Cognome, Email).'];
    }

    if (! is_email($email)) {
        return ['status' => 'error', 'message' => 'Indirizzo email non valido.'];
    }

    if (empty($password) || strlen($password) < 6) {
        return ['status' => 'error', 'message' => 'La password deve contenere almeno 6 caratteri.'];
    }

    if ($password !== $password_confirm) {
        return ['status' => 'error', 'message' => 'Le due password inserite non coincidono. Ricontrolla e riprova.'];
    }

    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    // 1. Gestione Utente WordPress
    $existing_user = get_user_by('email', $email);
    $user_id = 0;

    if ($existing_user) {
        $user_id = $existing_user->ID;
        // Aggiorna nome, cognome e password
        wp_update_user([
            'ID'         => $user_id,
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'user_pass'  => $password,
        ]);
        // Aggiunge il ruolo Volontario FAI se non presente
        $existing_user->add_role('dfn_volunteer');
    } else {
        // Determinazione Username
        $username = $username_input;
        if (empty($username)) {
            $username = sanitize_user(strtolower($first_name . '.' . $last_name), true);
        }

        // Se l'username scelto esiste già per un'altra email, restituisce errore chiaro
        if (username_exists($username)) {
            return ['status' => 'error', 'message' => sprintf('Il nome utente "%s" è già in uso. Scegline un altro.', esc_html($username))];
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            return ['status' => 'error', 'message' => 'Errore nella creazione dell\'account: ' . $user_id->get_error_message()];
        }

        wp_update_user([
            'ID'           => $user_id,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'display_name' => trim($first_name . ' ' . $last_name),
            'role'         => 'dfn_volunteer',
        ]);
    }

    // Imposta ruolo FAI salvato nei meta
    $current_assigned = (array) get_user_meta($user_id, '_dfn_assigned_fai_roles', true);
    if (! in_array('dfn_volunteer', $current_assigned, true)) {
        $current_assigned[] = 'dfn_volunteer';
        update_user_meta($user_id, '_dfn_assigned_fai_roles', array_unique($current_assigned));
    }

    // Salva metadati anagrafici
    if (! empty($phone)) {
        update_user_meta($user_id, 'billing_phone', $phone);
    }
    update_user_meta($user_id, 'billing_first_name', $first_name);
    update_user_meta($user_id, 'billing_last_name', $last_name);

    // 2. Gestione Record Volontario in dfn_fai_members
    $existing_member = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_fai} WHERE email = %s OR (user_id = %d AND user_id > 0) ORDER BY id ASC LIMIT 1",
        $email,
        $user_id
    ));

    if ($existing_member) {
        $wpdb->update(
            $table_fai,
            [
                'user_id'          => $user_id,
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'phone'            => ! empty($phone) ? $phone : $existing_member->phone,
                'is_volunteer'     => 1,
                'volunteer_status' => 'active',
                'is_guide'         => $is_guide ? 1 : $existing_member->is_guide,
                'joined_date'      => ! empty($existing_member->joined_date) ? $existing_member->joined_date : current_time('Y-m-d'),
            ],
            [ 'id' => $existing_member->id ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ],
            [ '%d' ]
        );
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
                'volunteer_status' => 'active',
                'volunteer_notes'  => 'Registrato tramite portale online',
                'joined_date'      => current_time('Y-m-d'),
                'is_guide'         => $is_guide,
                'has_safety_course'=> 0,
                'created_at'       => current_time('mysql'),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );
    }

    // 3. Log dell'attività
    if (function_exists('dfn_log_write')) {
        dfn_log_write(
            'volontari',
            trim($first_name . ' ' . $last_name),
            sprintf("Nuova registrazione volontario online: %s %s (%s)", $first_name, $last_name, $email),
            'success'
        );
    }

    // 4. Auto-Login immediato
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);

    // Redirect diretto alla Bacheca Volontari
    $redirect_url = wc_get_account_endpoint_url('eventi-fai');
    wp_safe_redirect(add_query_arg(['welcome_volunteer' => '1'], $redirect_url));
    exit;
}

/**
 * Renderizza il form frontend di registrazione volontario.
 *
 * @param array<string, mixed> $atts Attributi shortcode.
 * @return string HTML del form.
 */
function dfn_render_volunteer_registration_shortcode($atts = []): string
{
    // Se l'utente è già loggato e registrato come volontario
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        global $wpdb;
        $table_fai = $wpdb->prefix . 'dfn_fai_members';
        $is_vol = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_fai} WHERE (user_id = %d OR email = %s) AND is_volunteer = 1 AND volunteer_status = 'active'",
            $current_user->ID,
            $current_user->user_email
        ));

        if ($is_vol) {
            $account_url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url('eventi-fai') : site_url('/mio-account/eventi-fai/');
            return '<div style="max-width: 540px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); text-align: center; border-top: 5px solid #004b23;">
                <div style="font-size: 44px; margin-bottom: 12px;">🎉</div>
                <h2 style="color: #004b23; margin: 0 0 10px; font-size: 22px; font-weight: 700;">Sei già registrato come Volontario FAI!</h2>
                <p style="color: #475569; font-size: 14.5px; margin-bottom: 24px;">Ciao <strong>' . esc_html($current_user->display_name) . '</strong>, il tuo profilo volontario è attivo e pronto per le prossime attività di delegazione.</p>
                <a href="' . esc_url($account_url) . '" class="button" style="background: #004b23; color: #ffffff; padding: 10px 24px; border-radius: 8px; font-weight: 700; text-decoration: none; display: inline-block; font-size: 15px;">
                    🏛️ Vai alla tua Bacheca Volontari &rarr;
                </a>
            </div>';
        }
    }

    global $dfn_vol_reg_result;
    $result = is_array($dfn_vol_reg_result) ? $dfn_vol_reg_result : ['status' => '', 'message' => ''];

    ob_start();
    ?>
    <div class="dfn-vol-reg-container" style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04); overflow: hidden; border: 1px solid #e2e8f0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
        
        <!-- HEADER FORM -->
        <div style="background: linear-gradient(135deg, #004b23 0%, #002e15 100%); color: #ffffff; padding: 30px 28px; text-align: center;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; background: rgba(255,255,255,0.15); border-radius: 50%; font-size: 30px; margin-bottom: 14px;">
                👥
            </div>
            <h1 style="color: #ffffff; margin: 0 0 8px; font-size: 24px; font-weight: 800; letter-spacing: -0.5px;">
                Entra nella Squadra Volontari FAI
            </h1>
            <p style="color: #d1fae5; font-size: 14px; margin: 0; line-height: 1.5;">
                Registrati in pochi secondi per gestire le tue disponibilità, consultare i turni e partecipare alle attività di Delegazione.
            </p>
        </div>

        <!-- CORPO DEL FORM -->
        <div style="padding: 28px 28px 32px;">
            
            <?php if (! empty($result['message'])) : ?>
                <div style="background: <?php echo $result['status'] === 'error' ? '#fef2f2' : '#f0fdf4'; ?>; border: 1px solid <?php echo $result['status'] === 'error' ? '#fecaca' : '#bbf7d0'; ?>; color: <?php echo $result['status'] === 'error' ? '#991b1b' : '#166534'; ?>; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 13.5px; font-weight: 600;">
                    <?php echo $result['status'] === 'error' ? '⚠️ ' : '✅ '; ?><?php echo esc_html($result['message']); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="dfn-volunteer-registration-form" style="margin: 0;">
                <?php wp_nonce_field('dfn_vol_reg_action', 'dfn_vol_reg_nonce'); ?>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Nome *
                        </label>
                        <input type="text" name="first_name" id="dfn-reg-first-name" required placeholder="Mario" value="<?php echo esc_attr($_POST['first_name'] ?? ''); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none; transition: border-color 0.15s ease;" />
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                            Cognome *
                        </label>
                        <input type="text" name="last_name" id="dfn-reg-last-name" required placeholder="Rossi" value="<?php echo esc_attr($_POST['last_name'] ?? ''); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none; transition: border-color 0.15s ease;" />
                    </div>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Nome Utente (Username) *
                    </label>
                    <input type="text" name="username" id="dfn-reg-username" required placeholder="mario.rossi" value="<?php echo esc_attr($_POST['username'] ?? ''); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none;" />
                    <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                        Scegli il tuo nome utente di accesso (oppure usa quello generato in automatico).
                    </span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Indirizzo Email *
                    </label>
                    <input type="email" name="email" required placeholder="mario.rossi@email.com" value="<?php echo esc_attr($_POST['email'] ?? ''); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box; outline: none;" />
                    <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                        Riceverai qui le notifiche sui turni e i link alle riunioni di delegazione.
                    </span>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                        Numero di Cellulare <span style="font-weight: 400; color: #64748b;">(Opzionale)</span>
                    </label>
                    <input type="tel" name="phone" placeholder="333 1234567" value="<?php echo esc_attr($_POST['phone'] ?? ''); ?>" style="width: 100%; padding: 10px 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 14.5px; box-sizing: border-box;" />
                    <span style="font-size: 11.5px; color: #64748b; margin-top: 4px; display: block;">
                        Utile per i contatti logistici veloci durante le Giornate FAI.
                    </span>
                </div>

                <!-- SEZIONE PASSWORD DOPPIA CON TOGGLE MOSTRA/NASCONDI -->
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
                    Ti servirà per accedere alla tua Area Personale dal tuo smartphone o computer.
                </div>

                <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; margin-bottom: 24px;">
                    <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin: 0;">
                        <input type="checkbox" name="is_guide" value="1" <?php checked(! empty($_POST['is_guide']), true); ?> style="width: 18px; height: 18px; margin-top: 2px; accent-color: #004b23;" />
                        <div>
                            <span style="font-size: 13.5px; font-weight: 700; color: #1e293b; display: block;">
                                🏛️ Disponibile come Guida / Cicerone
                            </span>
                            <span style="font-size: 12px; color: #64748b; line-height: 1.4; display: block; margin-top: 2px;">
                                Spunta questa opzione se desideri raccontare e illustrare i beni e i luoghi aperti al pubblico.
                            </span>
                        </div>
                    </label>
                </div>

                <button type="submit" class="button" style="width: 100%; background: #004b23; color: #ffffff; padding: 14px 20px; border: none; border-radius: 10px; font-size: 16px; font-weight: 800; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(0,75,35,0.25); transition: background 0.2s ease;">
                    ✨ Conferma e Accedi al Portale Volontari &rarr;
                </button>
            </form>

            <div style="margin-top: 20px; text-align: center; font-size: 12px; color: #94a3b8;">
                🔒 I tuoi dati personali saranno trattati nel rispetto del GDPR esclusivamente per le finalità istituzionali del FAI.
            </div>
        </div>

    </div>

    <!-- Script Gestione Toggle Password e Validazione Match -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle mostra/nascondi password
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

        // Auto-compilazione dinamica Username (nome.cognome)
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

        // Controllo live corrispondenza password
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

        if (form) {
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

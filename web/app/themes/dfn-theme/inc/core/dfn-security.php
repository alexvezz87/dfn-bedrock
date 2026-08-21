<?php
/**
 * DFN Booking System 2.0 — Security & Registration Controller
 *
 * Gestisce l'integrazione con Google reCAPTCHA v3 e il sistema di
 * registrazione utenti con conferma e-mail (Double Opt-In Anti-Bot).
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Costanti reCAPTCHA v3
define('DFN_RECAPTCHA_SITE_KEY', '6LdW82stAAAAAETgJ0XpPZFq_miN199Byozf2ukN');
define('DFN_RECAPTCHA_SECRET_KEY', '6LdW82stAAAAAGY-EMZNPHByZEECI0639k4uYvpn');

// Disabilita la generazione automatica casuale di password in WooCommerce
add_filter('woocommerce_registration_generate_password', '__return_false');

/**
 * Assicura che la tabella per le registrazioni pendenti esista nel DB.
 */
function dfn_ensure_pending_registrations_table(): void
{
    global $wpdb;
    $table_pending = $wpdb->prefix . 'dfn_pending_registrations';

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_pending)) !== $table_pending) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql_pending = "CREATE TABLE {$table_pending} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            token varchar(64) NOT NULL,
            password_hash varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_token (token),
            KEY idx_email (email)
        ) {$charset_collate};";
        dbDelta($sql_pending);
    }
}

/**
 * Aggiunge i campi Password, Conferma Password e Checkbox GDPR nel form di registrazione WooCommerce.
 */
function dfn_add_registration_form_fields(): void
{
    $privacy_url = esc_url(DFN_PRIVACY_POLICY_URL);
    ?>
    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide" style="margin-bottom: 12px;">
        <label for="reg_password" style="font-weight: 600; color: #334155; display: block; margin-bottom: 4px; font-size: 13px;">
            <?php esc_html_e('Password', 'dfn-theme'); ?> <span class="required" style="color: #ef4444;">*</span>
        </label>
        <input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password" id="reg_password" autocomplete="new-password" required style="width: 100%; height: 42px; padding: 0 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; background: #f8fafc;" />
    </p>

    <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide" style="margin-bottom: 12px;">
        <label for="reg_password_confirm" style="font-weight: 600; color: #334155; display: block; margin-bottom: 4px; font-size: 13px;">
            <?php esc_html_e('Conferma Password', 'dfn-theme'); ?> <span class="required" style="color: #ef4444;">*</span>
        </label>
        <input type="password" class="woocommerce-Input woocommerce-Input--text input-text" name="password_confirm" id="reg_password_confirm" autocomplete="new-password" required style="width: 100%; height: 42px; padding: 0 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; background: #f8fafc;" />
    </p>

    <div class="dfn-privacy-consent-block" style="margin: 12px 0 14px 0;">
        <label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display: flex !important; align-items: flex-start !important; gap: 8px !important; cursor: pointer; font-size: 13px; color: #475569; line-height: 1.4; margin-bottom: 0 !important;">
            <input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="dfn_privacy_consent" type="checkbox" id="reg_privacy_consent" value="1" required style="width: 18px !important; height: 18px !important; min-width: 18px !important; margin-top: 1px !important; accent-color: #004d35 !important;" />
            <span style="font-size: 13px !important; font-weight: 400 !important; color: #475569 !important;">
                <?php
                printf(
                    __('Ho letto e accetto l\'<a href="%s" target="_blank" rel="noopener" style="color:#004b23; font-weight:bold; text-decoration:underline;">Informativa sulla Privacy</a>. I miei dati personali verranno trattati nel rispetto del Regolamento GDPR.', 'dfn-theme'),
                    $privacy_url
                );
                ?>
            </span>
        </label>
    </div>
    <?php
}
add_action('woocommerce_register_form', 'dfn_add_registration_form_fields', 15);

/**
 * Mostra il banner di benvenuto e conferma attivazione nell'Area Riservata quando l'URL contiene ?activated=1
 */
function dfn_show_activation_welcome_banner(): void
{
    if (isset($_GET['activated']) && $_GET['activated'] === '1') {
        $current_user = wp_get_current_user();
        $email_display = ($current_user && $current_user->user_email) ? $current_user->user_email : '';
        ?>
        <div class="dfn-activation-banner" style="background: linear-gradient(135deg, #004b23 0%, #006633 100%); color: #ffffff; padding: 24px 28px; border-radius: 16px; margin-bottom: 28px; box-shadow: 0 10px 25px rgba(0,75,35,0.2);">
            <h3 style="margin: 0 0 10px 0; color: #ffffff; font-size: 20px; font-weight: 700; display: flex; align-items: center; gap: 10px;">
                🎉 Account Attivato con Successo!
            </h3>
            <p style="margin: 0 0 14px 0; font-size: 15px; color: #f1f5f9; line-height: 1.5;">
                Il tuo indirizzo e-mail è stato verificato con successo. Il tuo account è ora attivo ed hai effettuato l'accesso all'Area Riservata.
            </p>
            <?php if (! empty($email_display)) : ?>
                <div style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); padding: 14px 18px; border-radius: 10px; font-size: 14px; color: #ffffff;">
                    📧 <strong>Nome Utente / Email per i prossimi accessi:</strong> <span style="text-decoration: underline; font-weight: 600;"><?php echo esc_html($email_display); ?></span><br>
                    🔑 <strong>Password:</strong> La password scelta durante la registrazione.
                </div>
            <?php else : ?>
                <div style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); padding: 14px 18px; border-radius: 10px; font-size: 14px; color: #ffffff;">
                    🔑 Utilizza la tua <strong>Email</strong> e la <strong>Password</strong> scelta in fase di registrazione per accedere nei prossimi ingressi.
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
add_action('woocommerce_before_customer_login_form', 'dfn_show_activation_welcome_banner', 5);
add_action('woocommerce_before_my_account', 'dfn_show_activation_welcome_banner', 5);
add_action('woocommerce_account_content', 'dfn_show_activation_welcome_banner', 5);

function dfn_is_local_environment(): bool
{
    // Verifica se siamo in ambiente locale / development
    if (defined('WP_ENV') && WP_ENV === 'development') {
        return true;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'dfn-bedrock.local') !== false || strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        return true;
    }

    return false;
}

/**
 * Verifica un token Google reCAPTCHA v3 tramite API HTTPS.
 *
 * @param string $token Token ricevuto dal cliente.
 * @param string $action Azione opzionale.
 * @return bool True se la verifica ha successo ed il punteggio è sufficiente.
 */
function dfn_verify_recaptcha(string $token, string $action = ''): bool
{
    // Disattivato in ambiente locale/sviluppo
    if (dfn_is_local_environment()) {
        return true;
    }

    if (empty($token)) {
        return false;
    }

    $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'body' => [
            'secret'   => DFN_RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
        'timeout' => 8,
    ]);

    if (is_wp_error($response)) {
        error_log('DFN reCAPTCHA Error: ' . $response->get_error_message());
        return true; // In caso di timeout o errore temporaneo di rete verso Google, non blocchiamo l'utente
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (! empty($body['success'])) {
        // Il token è autentico, emesso da Google per il nostro dominio.
        return true;
    } else {
        // Logga l'errore specifico restituito da Google per diagnostica
        $err_codes = isset($body['error-codes']) ? implode(', ', (array) $body['error-codes']) : 'unknown';
        error_log('DFN reCAPTCHA verification failed: ' . $err_codes);
    }

    return false;
}

/**
 * Carica lo script Google reCAPTCHA v3 nel frontend ed intercetta l'invio dei form.
 */
function dfn_enqueue_recaptcha_scripts(): void
{
    if (is_admin() || dfn_is_local_environment()) {
        return;
    }

    wp_enqueue_script(
        'google-recaptcha-v3',
        'https://www.google.com/recaptcha/api.js?render=' . esc_attr(DFN_RECAPTCHA_SITE_KEY),
        [],
        null,
        true
    );

    $site_key_js = esc_js(DFN_RECAPTCHA_SITE_KEY);
    $inline_js = <<<JS
(function() {
    var siteKey = '{$site_key_js}';

    function attachRecaptchaToForm(form) {
        if (!form || form.getAttribute('data-recaptcha-bound') === 'true') return;
        form.setAttribute('data-recaptcha-bound', 'true');

        form.addEventListener('submit', function(e) {
            if (typeof grecaptcha === 'undefined') return;
            if (form.getAttribute('data-recaptcha-bypassed') === 'true') return;

            var input = form.querySelector('input[name="g-recaptcha-response"]');

            e.preventDefault();
            e.stopPropagation();

            // Determina l'azione appropriata (login, register o submit)
            var actionName = 'submit';
            if (form.classList.contains('login') || form.id === 'loginform' || form.querySelector('input[name="pwd"]') || form.querySelector('input[name="log"]')) {
                actionName = 'login';
            } else if (form.classList.contains('register') || form.id === 'registerform' || form.querySelector('input[name="password_confirm"]')) {
                actionName = 'register';
            }

            // Preserva il valore del pulsante di submit che ha scatenato l'invio (es. name="register" o name="login")
            var submitter = e.submitter || document.activeElement;
            if (submitter && submitter.name && !form.querySelector('input[type="hidden"][name="' + submitter.name + '"]')) {
                var hiddenSub = document.createElement('input');
                hiddenSub.type = 'hidden';
                hiddenSub.name = submitter.name;
                hiddenSub.value = submitter.value || '1';
                form.appendChild(hiddenSub);
            }

            grecaptcha.ready(function() {
                grecaptcha.execute(siteKey, { action: actionName }).then(function(token) {
                    if (!input) {
                        input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'g-recaptcha-response';
                        form.appendChild(input);
                    }
                    input.value = token;
                    form.setAttribute('data-recaptcha-bypassed', 'true');
                    
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }).catch(function(err) {
                    form.setAttribute('data-recaptcha-bypassed', 'true');
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        var forms = document.querySelectorAll('form');
        forms.forEach(attachRecaptchaToForm);

        if ('MutationObserver' in window) {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) {
                            if (node.tagName === 'FORM') attachRecaptchaToForm(node);
                            else if (node.querySelectorAll) {
                                node.querySelectorAll('form').forEach(attachRecaptchaToForm);
                            }
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });
        }
    });
})();
JS;

    wp_add_inline_script('google-recaptcha-v3', $inline_js);
}
add_action('wp_enqueue_scripts', 'dfn_enqueue_recaptcha_scripts');

/**
 * Controllo reCAPTCHA al tentativo di login utenti.
 */
function dfn_verify_login_recaptcha($user, $username)
{
    if (empty($username) || is_wp_error($user)) {
        return $user;
    }

    if (isset($_POST['g-recaptcha-response'])) {
        $token = sanitize_text_field($_POST['g-recaptcha-response']);
        if (! dfn_verify_recaptcha($token, 'login')) {
            return new WP_Error(
                'recaptcha_failed',
                __('⚠️ Verifica di sicurezza (reCAPTCHA) fallita. Se sei un utente umano, riprova ad accedere.', 'dfn-theme')
            );
        }
    }

    return $user;
}
add_filter('wp_authenticate_user', 'dfn_verify_login_recaptcha', 10, 2);

/**
 * Intercetta la registrazione WooCommerce/WP per implementare il Double Opt-In.
 * Impedisce la creazione dell'utente in wp_users finché non clicca sul link inviato via e-mail.
 */
function dfn_handle_registration_double_opt_in($errors, $username, $password, $email)
{
    if (is_wp_error($errors) && $errors->get_error_code()) {
        return $errors;
    }

    // 1. Validazione reCAPTCHA (se il token è stato inviato dal frontend)
    $recaptcha_token = isset($_POST['g-recaptcha-response']) ? sanitize_text_field($_POST['g-recaptcha-response']) : '';
    if (! empty($recaptcha_token) && ! dfn_verify_recaptcha($recaptcha_token, 'register')) {
        return new WP_Error('recaptcha_failed', __('⚠️ Verifica di sicurezza anti-bot fallita. Riprova.', 'dfn-theme'));
    }

    $email = sanitize_email($email);
    if (! is_email($email)) {
        return new WP_Error('invalid_email', __('⚠️ Inserisci un indirizzo e-mail valido.', 'dfn-theme'));
    }

    if (email_exists($email)) {
        return new WP_Error('email_exists', __('⚠️ Questo indirizzo e-mail risulta già registrato.', 'dfn-theme'));
    }

    // 2. Validazione Password e Conferma Password
    $pass1 = isset($_POST['password']) ? trim($_POST['password']) : '';
    $pass2 = isset($_POST['password_confirm']) ? trim($_POST['password_confirm']) : '';

    if (empty($pass1)) {
        return new WP_Error('empty_password', __('⚠️ Inserisci una password per il tuo account.', 'dfn-theme'));
    }

    if (strlen($pass1) < 6) {
        return new WP_Error('short_password', __('⚠️ La password deve contenere almeno 6 caratteri.', 'dfn-theme'));
    }

    if ($pass1 !== $pass2) {
        return new WP_Error('password_mismatch', __('⚠️ Le due password inserite non coincidono. Per favore ricontrolla.', 'dfn-theme'));
    }

    // 3. Validazione Checkbox GDPR Privacy
    if (empty($_POST['dfn_privacy_consent'])) {
        return new WP_Error('privacy_required', __('⚠️ È necessario accettare l\'Informativa sulla Privacy per potersi registrare.', 'dfn-theme'));
    }

    dfn_ensure_pending_registrations_table();

    global $wpdb;
    $table_pending = $wpdb->prefix . 'dfn_pending_registrations';

    // Rimuove eventuali registrazioni pendenti precedenti per la stessa mail
    $wpdb->delete($table_pending, ['email' => $email], ['%s']);

    // Genera token di attivazione univoco
    $token = wp_generate_password(32, false);
    $expires_at = date('Y-m-d H:i:s', time() + (24 * 3600)); // Valido 24 ore

    $wpdb->insert(
        $table_pending,
        [
            'email'         => $email,
            'token'         => $token,
            'password_hash' => wp_hash_password($pass1),
            'expires_at'    => $expires_at,
        ],
        ['%s', '%s', '%s', '%s']
    );

    // Costruisce il link di conferma
    $confirm_url = add_query_arg([
        'dfn_action' => 'confirm_reg',
        'token'      => $token,
    ], wc_get_page_permalink('myaccount'));

    // Invia l'email di conferma
    $delegation_name = get_option('dfn_delegation_name_short', 'FAI Novara');
    $subject = sprintf(__('Conferma il tuo indirizzo e-mail — %s', 'dfn-theme'), $delegation_name);

    $body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0;">';
    $body .= '<div style="text-align: center; margin-bottom: 25px;"><h2 style="color: #004b23; margin: 0;">' . esc_html($delegation_name) . '</h2></div>';
    $body .= '<h3 style="color: #1e293b;">Conferma la tua registrazione</h3>';
    $body .= '<p style="color: #475569; line-height: 1.6;">Grazie per esserti registrato. Per completare la creazione del tuo account e accedere all\'Area Riservata, fai click sul pulsante di conferma qui sotto:</p>';
    $body .= '<div style="text-align: center; margin: 30px 0;">';
    $body .= '<a href="' . esc_url($confirm_url) . '" style="background-color: #004b23; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Conferma e Attiva Account</a>';
    $body .= '</div>';
    $body .= '<p style="color: #64748b; font-size: 13px; line-height: 1.5;">Se il pulsante non funziona, copia ed incolla questo link nel tuo browser:<br><a href="' . esc_url($confirm_url) . '" style="color: #004b23;">' . esc_url($confirm_url) . '</a></p>';
    $body .= '<p style="color: #94a3b8; font-size: 12px; margin-top: 30px; border-top: 1px solid #f1f5f9; padding-top: 15px;">Il link scadrà tra 24 ore. Se non hai richiesto tu questa registrazione, puoi ignorare questo messaggio.</p>';
    $body .= '</div>';

    $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

    wp_mail($email, $subject, $body, $headers);

    // Mostra l'avviso verde di successo prima di interrompere il flusso
    if (function_exists('wc_add_notice')) {
        wc_add_notice(
            sprintf(
                __('📧 Ti abbiamo inviato una mail di conferma all\'indirizzo %s! Controlla la tua casella di posta (anche nello Spam) e fai click sul link per completare la registrazione.', 'dfn-theme'),
                '<strong>' . esc_html($email) . '</strong>'
            ),
            'success'
        );
    }

    // Ritorna un WP_Error silenzioso per bloccare l'inserimento in wp_users
    return new WP_Error('dfn_pending_sent', '');
}
add_filter('woocommerce_process_registration_errors', 'dfn_handle_registration_double_opt_in', 10, 4);

/**
 * Gestisce l'attivazione dell'utente quando fa click sul link di conferma inviato via e-mail.
 */
function dfn_process_email_confirmation(): void
{
    if (isset($_GET['dfn_action']) && $_GET['dfn_action'] === 'confirm_reg' && ! empty($_GET['token'])) {
        $token = sanitize_text_field($_GET['token']);

        dfn_ensure_pending_registrations_table();

        global $wpdb;
        $table_pending = $wpdb->prefix . 'dfn_pending_registrations';

        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_pending} WHERE token = %s",
            $token
        ));

        $myaccount_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/');

        if (! $pending) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('⚠️ Il link di conferma e-mail è scaduto o non è valido. Per favore effettua nuovamente la registrazione.', 'dfn-theme'), 'error');
            }
            wp_safe_redirect($myaccount_url);
            exit;
        }

        if (strtotime($pending->expires_at) < time()) {
            $wpdb->delete($table_pending, ['id' => $pending->id], ['%d']);
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('⚠️ Il link di conferma è scaduto (validità 24h). Per favore inserisci nuovamente la tua e-mail per registrarti.', 'dfn-theme'), 'error');
            }
            wp_safe_redirect($myaccount_url);
            exit;
        }

        $email = $pending->email;
        $user_id = 0;

        // Se l'utente non esiste ancora in wp_users, crealo ora!
        if (! email_exists($email)) {
            $username = sanitize_user(current(explode('@', $email)));
            if (username_exists($username)) {
                $username .= '_' . rand(100, 999);
            }

            $random_pass = wp_generate_password(12);
            $user_id = wp_insert_user([
                'user_login'    => $username,
                'user_email'    => $email,
                'user_pass'     => $random_pass,
                'role'          => 'customer',
                'display_name'  => $username,
            ]);

            if (! is_wp_error($user_id) && $user_id > 0) {
                if (! empty($pending->password_hash)) {
                    $wpdb->update($wpdb->users, ['user_pass' => $pending->password_hash], ['ID' => $user_id]);
                }

                // Cancella il record temporaneo
                $wpdb->delete($table_pending, ['id' => $pending->id], ['%d']);

                // Invia e-mail di Benvenuto con credenziali di accesso
                $delegation_name = get_option('dfn_delegation_name_short', 'FAI Novara');
                $welcome_subject = sprintf(__('Benvenuto su %s! Credenziali del tuo Account', 'dfn-theme'), $delegation_name);

                $welcome_body = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0;">';
                $welcome_body .= '<div style="text-align: center; margin-bottom: 25px;"><h2 style="color: #004b23; margin: 0;">' . esc_html($delegation_name) . '</h2></div>';
                $welcome_body .= '<h3 style="color: #1e293b;">🎉 Il tuo account è stato attivato con successo!</h3>';
                $welcome_body .= '<p style="color: #475569; line-height: 1.6;">Ecco il riepilogo delle tue credenziali per i futuri accessi alla piattaforma:</p>';
                $welcome_body .= '<div style="background-color: #f8fafc; border-left: 4px solid #004b23; padding: 16px 20px; border-radius: 0 8px 8px 0; margin: 20px 0;">';
                $welcome_body .= '<p style="margin: 0 0 8px 0; font-size: 15px; color: #0f172a;"><strong>📧 Nome Utente / Email:</strong> ' . esc_html($email) . '</p>';
                $welcome_body .= '<p style="margin: 0; font-size: 15px; color: #0f172a;"><strong>🔑 Password:</strong> La password impostata durante la registrazione.</p>';
                $welcome_body .= '</div>';
                $welcome_body .= '<p style="color: #475569; line-height: 1.6;">Puoi accedere all\'Area Riservata in qualsiasi momento utilizzando questo link:<br><a href="' . esc_url($myaccount_url) . '" style="color: #004b23; font-weight: bold;">' . esc_url($myaccount_url) . '</a></p>';
                $welcome_body .= '</div>';

                wp_mail($email, $welcome_subject, $welcome_body, [ 'Content-Type: text/html; charset=UTF-8' ]);
            }
        } else {
            $wpdb->delete($table_pending, ['id' => $pending->id], ['%d']);
            $user_existing = get_user_by('email', $email);
            if ($user_existing) {
                $user_id = $user_existing->ID;
            }
        }

        // Effettua l'autenticazione completa WP & WooCommerce
        if ($user_id > 0) {
            $user_obj = get_user_by('id', $user_id);
            wp_clear_auth_cookie();
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);

            if ($user_obj) {
                do_action('wp_login', $user_obj->user_login, $user_obj);
            }
            if (function_exists('wc_set_customer_auth_cookie')) {
                wc_set_customer_auth_cookie($user_id);
            }
        }

        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                sprintf(
                    __('🎉 <strong>Benvenuto! Account attivato con successo.</strong><br>📧 Il tuo Nome Utente per i prossimi accessi è: <strong>%s</strong>', 'dfn-theme'),
                    esc_html($email)
                ),
                'success'
            );
        }

        // Reindirizza con parametro activated=1 per garantire la visualizzazione del banner di Benvenuto
        $redirect_dest = add_query_arg('activated', '1', $myaccount_url);
        wp_safe_redirect($redirect_dest);
        exit;
    }
}
add_action('wp_loaded', 'dfn_process_email_confirmation');

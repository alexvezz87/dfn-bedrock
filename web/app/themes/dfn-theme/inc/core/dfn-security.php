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

/**
 * Verifica un token Google reCAPTCHA v3 tramite API HTTPS.
 *
 * @param string $token Token ricevuto dal cliente.
 * @param string $action Azione opzionale.
 * @return bool True se la verifica ha successo ed il punteggio è sufficiente.
 */
function dfn_verify_recaptcha(string $token, string $action = ''): bool
{
    if (empty($token)) {
        return false;
    }

    $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'body' => [
            'secret'   => DFN_RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        error_log('DFN reCAPTCHA Error: ' . $response->get_error_message());
        return true; // In caso di timeout temporaneo di Google, non blocchiamo l'utente
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (! empty($body['success'])) {
        $score = floatval($body['score'] ?? 0.5);
        return $score >= 0.3;
    }

    return false;
}

/**
 * Carica lo script Google reCAPTCHA v3 nel frontend.
 */
function dfn_enqueue_recaptcha_scripts(): void
{
    if (is_admin()) {
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
            if (input && input.value) return;

            e.preventDefault();
            e.stopPropagation();

            grecaptcha.ready(function() {
                grecaptcha.execute(siteKey, { action: 'submit' }).then(function(token) {
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
                    form.submit();
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

    // 1. Validazione reCAPTCHA
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
            'password_hash' => ! empty($password) ? wp_hash_password($password) : null,
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

        // Se l'utente non esiste ancora in wp_users, crealo ora!
        if (! email_exists($email)) {
            $customer_id = function_exists('wc_create_new_customer')
                ? wc_create_new_customer($email)
                : wp_create_user($email, wp_generate_password(12), $email);

            if (! is_wp_error($customer_id) && $customer_id > 0) {
                // Cancella il record temporaneo
                $wpdb->delete($table_pending, ['id' => $pending->id], ['%d']);

                if (function_exists('wc_add_notice')) {
                    wc_add_notice(
                        __('🎉 Indirizzo e-mail confermato con successo! Il tuo account è stato attivato. Ti abbiamo inviato le credenziali di accesso via e-mail.', 'dfn-theme'),
                        'success'
                    );
                }
            } else {
                if (function_exists('wc_add_notice')) {
                    wc_add_notice(__('⚠️ Si è verificato un errore durante la creazione dell\'account. Riprova più tardi.', 'dfn-theme'), 'error');
                }
            }
        } else {
            $wpdb->delete($table_pending, ['id' => $pending->id], ['%d']);
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('ℹ️ Il tuo indirizzo e-mail risulta già verificato ed attivo! Puoi accedere con la tua password.', 'dfn-theme'), 'notice');
            }
        }

        wp_safe_redirect($myaccount_url);
        exit;
    }
}
add_action('init', 'dfn_process_email_confirmation');

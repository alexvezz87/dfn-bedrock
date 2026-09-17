<?php
/**
 * DFN Booking System 2.0 — Secure Administrator User Switching
 *
 * Consente agli utenti con ruolo Amministratore di impersonare qualsiasi utente registrato
 * (clienti, soci FAI, volontari, staff) per verificare l'esperienza esatta senza conoscere la password.
 * Include un banner persistente e protetto per tornare istantaneamente alla sessione amministratore.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('DFN_USER_SWITCH_COOKIE', 'dfn_switched_from_admin');

/**
 * Recupera l'utente Amministratore originale se lo switch è attivo e valido.
 *
 * @return WP_User|null Oggetto utente dell'amministratore originale, oppure null se nessuno switch è attivo.
 */
function dfn_get_switched_admin_user(): ?WP_User
{
    if (empty($_COOKIE[DFN_USER_SWITCH_COOKIE])) {
        return null;
    }

    $raw = base64_decode($_COOKIE[DFN_USER_SWITCH_COOKIE], true);
    if (! $raw || strpos($raw, '|') === false) {
        return null;
    }

    $parts = explode('|', $raw);
    if (count($parts) !== 4) {
        return null;
    }

    [$admin_id, $timestamp, $target_user_id, $hmac] = $parts;
    $admin_id       = (int) $admin_id;
    $timestamp      = (int) $timestamp;
    $target_user_id = (int) $target_user_id;

    // Token valido per massimo 24 ore
    if ($timestamp < (time() - DAY_IN_SECONDS) || $timestamp > (time() + 300)) {
        return null;
    }

    // Verifica la firma crittografica HMAC
    $data_to_verify = $admin_id . '|' . $timestamp . '|' . $target_user_id;
    $expected_hmac  = hash_hmac('sha256', $data_to_verify, wp_salt('auth'));

    if (! hash_equals($expected_hmac, $hmac)) {
        return null;
    }

    $admin_user = get_userdata($admin_id);
    if (! $admin_user || ! user_can($admin_user, 'manage_options')) {
        return null;
    }

    return $admin_user;
}

/**
 * Gestisce le azioni di Switch To e Switch Back.
 */
add_action('init', 'dfn_handle_user_switching_actions', 5);
function dfn_handle_user_switching_actions(): void
{
    // 1. AZIONE: SWITCH TO (Accedi come Utente)
    if (isset($_GET['action']) && $_GET['action'] === 'dfn_switch_to_user' && isset($_GET['user_id'])) {
        $target_user_id = (int) $_GET['user_id'];
        $nonce          = $_GET['_wpnonce'] ?? '';

        if (! wp_verify_nonce($nonce, 'dfn_switch_to_user_' . $target_user_id)) {
            wp_die(esc_html__('Verifica di sicurezza non valida.', 'dfn-theme'), esc_html__('Accesso Negato', 'dfn-theme'), 403);
        }

        // Verifica che l'utente corrente sia un amministratore o già in switch da admin
        $current_admin = null;
        if (current_user_can('manage_options')) {
            $current_admin = wp_get_current_user();
        } else {
            $current_admin = dfn_get_switched_admin_user();
        }

        if (! $current_admin || ! user_can($current_admin, 'manage_options')) {
            wp_die(esc_html__('Permessi insufficienti. Questa operazione è riservata agli Amministratori.', 'dfn-theme'), esc_html__('Accesso Negato', 'dfn-theme'), 403);
        }

        $target_user = get_userdata($target_user_id);
        if (! $target_user) {
            wp_die(esc_html__('Utente target non trovato.', 'dfn-theme'), esc_html__('Errore', 'dfn-theme'), 404);
        }

        // Crea il cookie sicuro firmato con i dati dell'amministratore originale
        $timestamp = time();
        $admin_id  = (int) $current_admin->ID;
        $data_raw  = $admin_id . '|' . $timestamp . '|' . $target_user_id;
        $hmac      = hash_hmac('sha256', $data_raw, wp_salt('auth'));
        $cookie_val= base64_encode($data_raw . '|' . $hmac);

        // Imposta cookie sicuro valido per l'intera sessione/24h
        $cookie_path   = COOKIEPATH ?: '/';
        $cookie_domain = COOKIE_DOMAIN ?: '';
        $is_ssl        = is_ssl();

        setcookie(DFN_USER_SWITCH_COOKIE, $cookie_val, [
            'expires'  => time() + DAY_IN_SECONDS,
            'path'     => $cookie_path,
            'domain'   => $cookie_domain,
            'secure'   => $is_ssl,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[DFN_USER_SWITCH_COOKIE] = $cookie_val;

        // Effettua il login come utente target
        wp_clear_auth_cookie();
        wp_set_current_user($target_user_id);
        wp_set_auth_cookie($target_user_id, true);

        // Log dell'operazione se il logger è disponibile
        if (function_exists('dfn_log_write')) {
            dfn_log_write(
                'user_switch',
                $current_admin->display_name ?: $current_admin->user_login,
                sprintf(
                    'Accesso Amministratore effettuato come utente: %s (%s | ID: #%d)',
                    $target_user->display_name ?: $target_user->user_login,
                    $target_user->user_email,
                    $target_user_id
                ),
                'success'
            );
        }

        // Reindirizzamento
        $redirect_to = ! empty($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : wc_get_page_permalink('myaccount');
        if (empty($redirect_to)) {
            $redirect_to = home_url('/');
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    // 2. AZIONE: SWITCH BACK (Torna ad Amministratore)
    if (isset($_GET['action']) && $_GET['action'] === 'dfn_switch_back') {
        $nonce = $_GET['_wpnonce'] ?? '';

        if (! wp_verify_nonce($nonce, 'dfn_switch_back')) {
            wp_die(esc_html__('Verifica di sicurezza non valida.', 'dfn-theme'), esc_html__('Accesso Negato', 'dfn-theme'), 403);
        }

        $admin_user = dfn_get_switched_admin_user();
        if (! $admin_user) {
            wp_die(esc_html__('Sessione amministratore non trovata o scaduta.', 'dfn-theme'), esc_html__('Sessione Scaduta', 'dfn-theme'), 403);
        }

        $target_user = wp_get_current_user();

        // Cancella il cookie di switch
        $cookie_path   = COOKIEPATH ?: '/';
        $cookie_domain = COOKIE_DOMAIN ?: '';
        $is_ssl        = is_ssl();

        setcookie(DFN_USER_SWITCH_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => $cookie_path,
            'domain'   => $cookie_domain,
            'secure'   => $is_ssl,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[DFN_USER_SWITCH_COOKIE]);

        // Ripristina l'autenticazione dell'amministratore originale
        wp_clear_auth_cookie();
        wp_set_current_user($admin_user->ID);
        wp_set_auth_cookie($admin_user->ID, true);

        // Log del ripristino
        if (function_exists('dfn_log_write')) {
            dfn_log_write(
                'user_switch_back',
                $admin_user->display_name ?: $admin_user->user_login,
                sprintf(
                    'Ripristinata sessione Amministratore da impersonazione di: %s (ID: #%d)',
                    $target_user ? ($target_user->display_name ?: $target_user->user_login) : 'Utente',
                    $target_user ? $target_user->ID : 0
                ),
                'info'
            );
        }

        $redirect_to = ! empty($_GET['redirect_to']) ? esc_url_raw($_GET['redirect_to']) : admin_url('users.php');
        wp_safe_redirect($redirect_to);
        exit;
    }
}

/**
 * Renderizza il banner di avviso quando l'amministratore sta impersonando un utente.
 */
function dfn_render_user_switch_banner(): void
{
    $admin_user = dfn_get_switched_admin_user();
    if (! $admin_user) {
        return;
    }

    $current_user = wp_get_current_user();
    if (! $current_user || ! $current_user->exists()) {
        return;
    }

    $switch_back_url = wp_nonce_url(
        add_query_arg(['action' => 'dfn_switch_back', 'redirect_to' => urlencode($_SERVER['REQUEST_URI'] ?? '')], home_url('/')),
        'dfn_switch_back'
    );

    $roles_label = implode(', ', (array) $current_user->roles);
    ?>
    <div id="dfn-user-switch-bar" style="position:fixed; top:0; left:0; width:100%; z-index:9999999; background:linear-gradient(90deg, #991b1b 0%, #b91c1c 100%); color:#ffffff; padding:10px 20px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:13.5px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 4px 15px rgba(0,0,0,0.35); box-sizing:border-box;">
        <div style="display:flex; align-items:center; gap:10px; font-weight:600; flex-wrap:wrap;">
            <span style="font-size:18px;">⚠️</span>
            <span>
                Stai visualizzando il sito come: 
                <strong style="color:#fef08a; text-decoration:underline;"><?php echo esc_html($current_user->display_name ?: $current_user->user_login); ?></strong> 
                (<code><?php echo esc_html($current_user->user_email); ?></code> — ID #<?php echo esc_html($current_user->ID); ?> | Ruolo: <em><?php echo esc_html($roles_label ?: 'Nessuno'); ?></em>)
            </span>
        </div>
        <div style="flex-shrink:0; margin-left:15px;">
            <a href="<?php echo esc_url($switch_back_url); ?>" style="background:#ffffff; color:#991b1b; text-decoration:none; font-weight:800; padding:7px 16px; border-radius:8px; font-size:13px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 6px rgba(0,0,0,0.2); transition:all 0.15s ease;">
                ↩️ Torna ad Amministratore (<strong><?php echo esc_html($admin_user->display_name ?: $admin_user->user_login); ?></strong>)
            </a>
        </div>
    </div>
    <style>
        body { margin-top: 48px !important; }
        #wpadminbar { top: 48px !important; }
        @media screen and (max-width: 782px) {
            #dfn-user-switch-bar { font-size: 12px; padding: 8px 12px; flex-direction: column; gap: 8px; align-items: stretch; text-align: center; }
            #dfn-user-switch-bar > div:last-child { margin-left: 0; }
            #dfn-user-switch-bar a { justify-content: center; }
            body { margin-top: 80px !important; }
            #wpadminbar { top: 80px !important; }
        }
    </style>
    <?php
}
add_action('wp_body_open', 'dfn_render_user_switch_banner', 1);
add_action('wp_footer', 'dfn_render_user_switch_banner', 1);
add_action('admin_notices', 'dfn_render_user_switch_banner', 1);

/**
 * Aggiunge l'azione "Accedi come utente" nell'elenco Utenti di WordPress (users.php).
 */
add_filter('user_row_actions', 'dfn_add_user_switch_row_action', 10, 2);
function dfn_add_user_switch_row_action(array $actions, WP_User $user): array
{
    // Mostra l'azione solo agli amministratori
    if (! current_user_can('manage_options') && ! dfn_get_switched_admin_user()) {
        return $actions;
    }

    // Non mostrare l'azione per se stessi
    if ($user->ID === get_current_user_id()) {
        return $actions;
    }

    $switch_url = wp_nonce_url(
        add_query_arg([
            'action'      => 'dfn_switch_to_user',
            'user_id'     => $user->ID,
            'redirect_to' => urlencode(wc_get_page_permalink('myaccount')),
        ], home_url('/')),
        'dfn_switch_to_user_' . $user->ID
    );

    $actions['dfn_switch_user'] = sprintf(
        '<a href="%s" style="color:#004b23; font-weight:700;" title="%s">👤 %s</a>',
        esc_url($switch_url),
        esc_attr(sprintf(__('Accedi immediatamente con l\'account di %s', 'dfn-theme'), $user->display_name ?: $user->user_login)),
        esc_html__('Accedi come questo utente', 'dfn-theme')
    );

    return $actions;
}

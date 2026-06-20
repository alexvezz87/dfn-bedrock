<?php

/**
 * DFN Booking System 2.0 — Setup, Costanti e Ruoli
 *
 * Configurazione centralizzata del sistema: costanti, registrazione ruoli,
 * capability granulari, personalizzazioni WooCommerce e redirect login.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ========================================================================
 * COSTANTI CENTRALIZZATE
 * ========================================================================
 */

/** Versione del setup ruoli — incrementare per forzare aggiornamento */
define('DFN_ROLES_VERSION', '2.0');

/** Sconto unitario per tessera FAI (in euro) */
define('DFN_FAI_SCONTO_UNITARIO', 5);

/**
 * ========================================================================
 * 1. ENQUEUE DEL TEMA PADRE E STILI GLOBALI
 * ========================================================================
 */
if (! function_exists('dfn_enqueue_parent_styles')) :
    /**
     * Registra lo stylesheet del tema figlio con le dipendenze del tema padre.
     *
     * @return void
     */
    function dfn_enqueue_parent_styles(): void
    {
        wp_enqueue_style(
            'dfn-child-style',
            trailingslashit(get_stylesheet_directory_uri()) . 'style.css',
            [ 'hello-elementor', 'hello-elementor-theme-style' ],
        );

        // Enqueue del widget selettore turni (CSS e JS) per il frontend
        wp_enqueue_style(
            'dfn-slot-selector-css',
            trailingslashit(get_stylesheet_directory_uri()) . 'assets/css/dfn-slot-selector.css',
            [],
            '2.0.0',
        );

        wp_enqueue_script(
            'dfn-slot-selector-js',
            trailingslashit(get_stylesheet_directory_uri()) . 'assets/js/dfn-slot-selector.js',
            [ 'jquery' ],
            '2.0.0',
            true,
        );

        $user_logged_in = is_user_logged_in();
        $user_data = [
            'ajaxurl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('dfn_booking_nonce'),
            'userLogged'     => $user_logged_in,
            'userFirstName'  => '',
            'userLastName'   => '',
            'userEmail'      => '',
            'userPhone'      => '',
        ];

        if ($user_logged_in) {
            $user_id = get_current_user_id();
            $current_user = wp_get_current_user();

            $first_name = get_user_meta($user_id, 'billing_first_name', true);
            $user_data['userFirstName'] = $first_name ? $first_name : $current_user->first_name;

            $last_name = get_user_meta($user_id, 'billing_last_name', true);
            $user_data['userLastName'] = $last_name ? $last_name : $current_user->last_name;

            $email = get_user_meta($user_id, 'billing_email', true);
            $user_data['userEmail'] = $email ? $email : $current_user->user_email;

            $user_data['userPhone'] = get_user_meta($user_id, 'billing_phone', true);
        }

        wp_localize_script('dfn-slot-selector-js', 'dfnVars', $user_data);

        // Enqueue condizionale per l'Express Checkout (solo nelle pagine checkout)
        if (is_checkout() && ! is_order_received_page()) {
            wp_enqueue_style(
                'dfn-checkout-express-css',
                trailingslashit(get_stylesheet_directory_uri()) . 'assets/css/dfn-checkout-express.css',
                [],
                '2.0.0',
            );
        }
    }
endif;
add_action('wp_enqueue_scripts', 'dfn_enqueue_parent_styles', 10);

/**
 * Gestisce l'RTL stylesheet se necessario.
 *
 * @param string $uri URI dello stylesheet locale.
 * @return string URI aggiornato.
 */
function dfn_locale_css(string $uri): string
{
    if (empty($uri) && is_rtl() && file_exists(get_template_directory() . '/rtl.css')) {
        $uri = get_template_directory_uri() . '/rtl.css';
    }
    return $uri;
}
add_filter('locale_stylesheet_uri', 'dfn_locale_css');

/**
 * ========================================================================
 * 2. PERSONALIZZAZIONI WOOCOMMERCE
 * ========================================================================
 */

/**
 * Auto-completa gli ordini pagati online (non In Loco).
 *
 * Gli ordini con gateway dfn_in_loco NON vengono auto-completati qui,
 * perché il loro ciclo di vita è: pending → (scan al banchetto) → completed.
 *
 * @param int $order_id ID dell'ordine WC.
 * @return void
 */
function dfn_auto_completa_ordini_pagati(int $order_id): void
{
    if (! $order_id) {
        return;
    }
    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    // Non auto-completare gli ordini "In Loco" — il completamento avviene allo scanner
    if ($order->get_payment_method() === 'dfn_in_loco') {
        return;
    }

    $order->update_status('completed');
}
add_action('woocommerce_payment_complete', 'dfn_auto_completa_ordini_pagati');

/**
 * Personalizza il testo del bottone checkout.
 *
 * @param string $button_text Testo originale.
 * @return string Testo personalizzato.
 */
function dfn_custom_button_text(string $button_text): string
{
    return 'Effettua Prenotazione';
}
add_filter('woocommerce_order_button_text', 'dfn_custom_button_text');

/**
 * Personalizza il messaggio "nessun ordine" nel My Account.
 *
 * @param string $translated_text Testo tradotto.
 * @param string $text            Testo originale.
 * @param string $domain          Dominio di traduzione.
 * @return string Testo personalizzato.
 */
function dfn_personalizza_testo_ordini_vuoti(string $translated_text, string $text, string $domain): string
{
    if ('woocommerce' === $domain && 'No order has been made yet.' === $text) {
        $translated_text = 'Non hai ancora effettuato nessuna prenotazione. Visita il nostro calendario per scoprire i prossimi eventi!';
    }
    return $translated_text;
}
add_filter('gettext', 'dfn_personalizza_testo_ordini_vuoti', 10, 3);

/**
 * ========================================================================
 * 3. MENU WOOCOMMERCE E LOGICA CARRELLO
 * ========================================================================
 */

/**
 * Personalizza le voci del menu My Account.
 *
 * @param array<string,string> $items Voci di menu.
 * @return array<string,string> Voci personalizzate.
 */
function dfn_rimuovi_voci_menu_account(array $items): array
{
    unset($items['downloads'], $items['edit-address'], $items['payment-methods']);
    if (isset($items['orders'])) {
        $items['orders'] = 'Le Mie Prenotazioni';
    }

    $nuovo_menu = [];
    foreach ($items as $key => $value) {
        if ('customer-logout' === $key) {
            $nuovo_menu['cart'] = 'Carrello';
        }
        $nuovo_menu[ $key ] = $value;
    }
    return $nuovo_menu;
}
add_filter('woocommerce_account_menu_items', 'dfn_rimuovi_voci_menu_account');

/**
 * Collega la voce "Carrello" nel menu account all'URL del carrello.
 *
 * @param string $url      URL dell'endpoint.
 * @param string $endpoint Nome dell'endpoint.
 * @return string URL aggiornato.
 */
function dfn_collega_bottone_carrello(string $url, string $endpoint): string
{
    if ('cart' === $endpoint) {
        return wc_get_cart_url();
    }
    return $url;
}
add_filter('woocommerce_get_endpoint_url', 'dfn_collega_bottone_carrello', 10, 2);

/**
 * ========================================================================
 * 4. RUOLI E PERMESSI (SISTEMA 2.0)
 *
 * Ruoli:
 * - dfn_volunteer: Volontario al banchetto (scanner + incasso)
 *
 * Capability:
 * - dfn_manage_events       : CRUD eventi, turni, pricing
 * - dfn_manage_event_slots  : Override manuale slot, spostamento prenotazioni
 * - dfn_manage_volunteers   : Assegnare volontari agli eventi
 * - dfn_use_scanner         : Scanner QR + dashboard volontario
 * - dfn_checkin_and_collect  : Check-in + incasso al banchetto
 * - dfn_view_reports        : Bilancio e statistiche
 * - dfn_manage_fai_members  : CRUD anagrafica soci FAI
 * ========================================================================
 */

add_action('after_switch_theme', 'dfn_setup_roles_and_caps');
add_action('admin_init', 'dfn_setup_roles_if_needed');

/**
 * Verifica se i ruoli devono essere aggiornati (version check).
 *
 * @return void
 */
function dfn_setup_roles_if_needed(): void
{
    if (get_option('dfn_roles_version') !== DFN_ROLES_VERSION) {
        dfn_setup_roles_and_caps();
    }
}

/**
 * Registra il ruolo dfn_volunteer e assegna le capability a tutti i ruoli.
 *
 * @return void
 */
function dfn_setup_roles_and_caps(): void
{

    // Lista completa delle capability del sistema
    $all_caps = [
        'dfn_manage_events',
        'dfn_manage_event_slots',
        'dfn_manage_volunteers',
        'dfn_use_scanner',
        'dfn_checkin_and_collect',
        'dfn_view_reports',
        'dfn_manage_fai_members',
    ];

    // Capability del volontario (sottoinsieme limitato)
    $volunteer_caps = [
        'read'                    => true,
        'dfn_use_scanner'         => true,
        'dfn_checkin_and_collect'  => true,
    ];

    // -------------------------------------------------------------------
    // RUOLO: dfn_volunteer (Volontario Banchetto)
    // -------------------------------------------------------------------
    remove_role('dfn_volunteer');
    add_role('dfn_volunteer', 'Volontario FAI', $volunteer_caps);

    // -------------------------------------------------------------------
    // ADMINISTRATOR: tutte le capability
    // -------------------------------------------------------------------
    $admin = get_role('administrator');
    if ($admin) {
        foreach ($all_caps as $cap) {
            if (! $admin->has_cap($cap)) {
                $admin->add_cap($cap);
            }
        }
    }

    // -------------------------------------------------------------------
    // SHOP MANAGER: tutte tranne gestione volontari
    // -------------------------------------------------------------------
    $shop_manager = get_role('shop_manager');
    if ($shop_manager) {
        foreach ($all_caps as $cap) {
            if ($cap === 'dfn_manage_volunteers') {
                continue;
            }
            if (! $shop_manager->has_cap($cap)) {
                $shop_manager->add_cap($cap);
            }
        }
    }

    // -------------------------------------------------------------------
    // RETROCOMPATIBILITÀ: Migra il vecchio ruolo cv_scanner
    // -------------------------------------------------------------------
    $legacy_role = get_role('cv_scanner');
    if ($legacy_role) {
        // Aggiungi le nuove capability al vecchio ruolo
        if (! $legacy_role->has_cap('dfn_use_scanner')) {
            $legacy_role->add_cap('dfn_use_scanner');
        }
        if (! $legacy_role->has_cap('dfn_checkin_and_collect')) {
            $legacy_role->add_cap('dfn_checkin_and_collect');
        }
    }

    // Assicuriamoci che admin e shop_manager abbiano anche la vecchia cap (legacy)
    if ($admin && ! $admin->has_cap('cv_use_scanner')) {
        $admin->add_cap('cv_use_scanner');
    }
    if ($shop_manager && ! $shop_manager->has_cap('cv_use_scanner')) {
        $shop_manager->add_cap('cv_use_scanner');
    }

    update_option('dfn_roles_version', DFN_ROLES_VERSION);
}

/**
 * Sblocca l'accesso al backend WP per i volontari.
 *
 * WooCommerce blocca l'accesso admin ai ruoli non-admin.
 * Questa funzione lo sblocca per chi ha la capability dello scanner.
 *
 * @param bool $prevent_access Se bloccare l'accesso.
 * @return bool False per sbloccare, valore originale altrimenti.
 */
function dfn_sblocca_backend_volontari(bool $prevent_access): bool
{
    if (current_user_can('dfn_use_scanner') || current_user_can('cv_use_scanner')) {
        return false;
    }
    return $prevent_access;
}
add_filter('woocommerce_prevent_admin_access', 'dfn_sblocca_backend_volontari', 20, 1);

/**
 * Redirect i volontari allo scanner dopo il login WooCommerce.
 *
 * @param string  $redirect URL di redirect.
 * @param WP_User $user     Utente loggato.
 * @return string URL di redirect aggiornato.
 */
function dfn_redirect_volunteer_wc(string $redirect, $user): string
{
    if (is_a($user, 'WP_User') && (in_array('dfn_volunteer', (array) $user->roles, true) || in_array('cv_scanner', (array) $user->roles, true))) {
        return admin_url('admin.php?page=dfn-scanner-live');
    }
    return $redirect;
}
add_filter('woocommerce_login_redirect', 'dfn_redirect_volunteer_wc', 99, 2);

/**
 * Redirect i volontari allo scanner dopo il login WordPress standard.
 *
 * @param string  $redirect_to URL di redirect richiesto.
 * @param string  $request     URL della pagina corrente.
 * @param WP_User $user        Utente loggato.
 * @return string URL di redirect aggiornato.
 */
function dfn_redirect_volunteer_wp(string $redirect_to, string $request, $user): string
{
    if (is_a($user, 'WP_User') && (in_array('dfn_volunteer', (array) $user->roles, true) || in_array('cv_scanner', (array) $user->roles, true))) {
        return admin_url('admin.php?page=dfn-scanner-live');
    }
    return $redirect_to;
}
add_filter('login_redirect', 'dfn_redirect_volunteer_wp', 99, 3);

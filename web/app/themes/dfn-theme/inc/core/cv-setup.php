<?php
if (!defined('ABSPATH')) exit;

/**
 * ========================================================================
 * COSTANTI CENTRALIZZATE DEL SISTEMA
 * Tutti i valori configurabili del gestionale CandleVibes.
 * ========================================================================
 */

// Product IDs degli eventi speciali
define('CV_EVENT_ID_STANDARD', 1627);   // Evento principale (senza numero stock visibile)
define('CV_EVENT_ID_DELITTO', 1639);    // Evento "Delitto" con gestione tavoli

// Sconto per singola tessera FAI (in euro)
define('CV_FAI_SCONTO_UNITARIO', 5);

// Versione del setup ruoli (incrementare per forzare aggiornamento)
define('CV_ROLES_VERSION', '1.0');

/**
 * Restituisce la configurazione dei tavoli per l'evento Delitto.
 *
 * @return array<string, int> Mappa nome_tavolo => capienza massima.
 */
function cv_get_tavoli_delitto(): array
{
    return array(
        'Miss Marple'       => 10,
        'John Watson'       => 10,
        'Montalbano'        => 10,
        'Barlume'           => 10,
        'Agatha Christie'   => 10,
        'Sherlock Holmes'   => 10,
        'Hercule Poirot'    => 10,
        'Benoit Blank'      => 10,
        'Arthur Conan Doyle' => 10,
        'Edgard Allan Poe'  => 13,
    );
}

/**
 * Restituisce i soli nomi dei tavoli per l'evento Delitto.
 *
 * @return array<string> Lista dei nomi dei tavoli.
 */
function cv_get_nomi_tavoli_delitto(): array
{
    return array_keys(cv_get_tavoli_delitto());
}

/**
 * 1. ENQUEUE DEL TEMA PADRE E STILI GLOBALI
 */
if (!function_exists('chld_thm_cfg_locale_css')):
    function chld_thm_cfg_locale_css($uri)
    {
        if (empty($uri) && is_rtl() && file_exists(get_template_directory() . '/rtl.css'))
            $uri = get_template_directory_uri() . '/rtl.css';
        return $uri;
    }
endif;
add_filter('locale_stylesheet_uri', 'chld_thm_cfg_locale_css');

if (!function_exists('child_theme_configurator_css')):
    function child_theme_configurator_css()
    {
        wp_enqueue_style('chld_thm_cfg_child', trailingslashit(get_stylesheet_directory_uri()) . 'style.css', array('hello-elementor', 'hello-elementor', 'hello-elementor-theme-style'));
    }
endif;
add_action('wp_enqueue_scripts', 'child_theme_configurator_css', 10);

/**
 * 2. PERSONALIZZAZIONI TESTI E AUTO-COMPLETAMENTO WOOCOMMERCE
 */
add_action('woocommerce_payment_complete', 'cv_auto_completa_ordini_pagati');
function cv_auto_completa_ordini_pagati($order_id)
{
    if (! $order_id) return;
    $order = wc_get_order($order_id);
    if ($order) $order->update_status('completed');
}

add_filter('woocommerce_order_button_text', 'cv_custom_button_text');
function cv_custom_button_text($button_text)
{
    return 'Effettua Prenotazione';
}

add_filter('gettext', 'cv_personalizza_testo_ordini_vuoti', 10, 3);
function cv_personalizza_testo_ordini_vuoti($translated_text, $text, $domain)
{
    if ('woocommerce' === $domain && 'No order has been made yet.' === $text) {
        $translated_text = 'Non hai ancora acquistato nessun biglietto. Visita il nostro calendario per scoprire i prossimi eventi e accendere la magia!';
    }
    return $translated_text;
}

/**
 * 3. MENU WOOCOMMERCE E LOGICA CARRELLO
 */
add_filter('woocommerce_account_menu_items', 'cv_rimuovi_voci_menu_account');
function cv_rimuovi_voci_menu_account($items)
{
    unset($items['downloads'], $items['edit-address'], $items['payment-methods']);
    if (isset($items['orders'])) $items['orders'] = 'I Miei Biglietti';

    $nuovo_menu = array();
    foreach ($items as $key => $value) {
        if ('customer-logout' === $key) $nuovo_menu['cart'] = 'Carrello';
        $nuovo_menu[$key] = $value;
    }
    return $nuovo_menu;
}

add_filter('woocommerce_get_endpoint_url', 'cv_collega_bottone_carrello', 10, 2);
function cv_collega_bottone_carrello($url, $endpoint)
{
    if ('cart' === $endpoint) return wc_get_cart_url();
    return $url;
}

/**
 * 4. RUOLI E PERMESSI (VERIFICATORE BIGLIETTI)
 *
 * Il ruolo viene creato una sola volta al cambio tema (o al primo caricamento)
 * usando un version check su option, per non scrivere nel DB ad ogni pageload.
 */
add_action('after_switch_theme', 'cv_crea_ruolo_operatore_scanner');
add_action('admin_init', 'cv_crea_ruolo_operatore_scanner_se_necessario');

/**
 * Verifica se i ruoli devono essere aggiornati (version check).
 *
 * @return void
 */
function cv_crea_ruolo_operatore_scanner_se_necessario(): void
{
    if (get_option('cv_roles_version') !== CV_ROLES_VERSION) {
        cv_crea_ruolo_operatore_scanner();
    }
}

/**
 * Registra il ruolo 'cv_scanner' e assegna la capability ai ruoli admin.
 *
 * @return void
 */
function cv_crea_ruolo_operatore_scanner(): void
{
    // Rimuoviamo il vecchio ruolo se esiste, per aggiornare le capabilities
    remove_role('cv_scanner');
    add_role('cv_scanner', 'Verificatore Biglietti', array('read' => true, 'cv_use_scanner' => true));

    $admin = get_role('administrator');
    if ($admin && ! $admin->has_cap('cv_use_scanner')) {
        $admin->add_cap('cv_use_scanner');
    }

    $shop_manager = get_role('shop_manager');
    if ($shop_manager && ! $shop_manager->has_cap('cv_use_scanner')) {
        $shop_manager->add_cap('cv_use_scanner');
    }

    update_option('cv_roles_version', CV_ROLES_VERSION);
}

add_filter('woocommerce_prevent_admin_access', 'cv_sblocca_backend_verificatori', 20, 1);
function cv_sblocca_backend_verificatori($prevent_access)
{
    if (current_user_can('cv_use_scanner')) return false;
    return $prevent_access;
}

add_filter('woocommerce_login_redirect', 'cv_redirect_scanner_wc', 99, 2);
function cv_redirect_scanner_wc($redirect, $user)
{
    if (is_a($user, 'WP_User') && in_array('cv_scanner', (array) $user->roles)) return admin_url('admin.php?page=cv-scanner-live');
    return $redirect;
}

add_filter('login_redirect', 'cv_redirect_scanner_wp', 99, 3);
function cv_redirect_scanner_wp($redirect_to, $request, $user)
{
    if (is_a($user, 'WP_User') && in_array('cv_scanner', (array) $user->roles)) return admin_url('admin.php?page=cv-scanner-live');
    return $redirect_to;
}

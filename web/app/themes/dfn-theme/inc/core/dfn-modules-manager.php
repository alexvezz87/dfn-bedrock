<?php

/**
 * DFN Booking & Volunteer System — Modules Manager
 *
 * Gestisce l'abilitazione e disabilitazione modulare delle componenti FAI:
 * - Macro-Moduli Core: Gestione Prenotazioni (v2.0) e Gestione Volontari (v2.1)
 * - Sotto-Moduli Funzionali FAI Prenotazioni:
 *   - Promozioni & Newsletter
 *   - Lista d'Attesa
 *   - Bilancio Eventi
 *   - Recensione Eventi
 *   - Botteghino Live
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Restituisce la definizione di tutti i moduli disponibili nel sistema.
 *
 * @return array<string, array<string, mixed>>
 */
function dfn_get_available_modules(): array
{
    return [
        // --- MODULI CORE (Macro-aree) ---
        'prenotazioni' => [
            'id'            => 'prenotazioni',
            'category'      => 'core',
            'name'          => __('Gestione Prenotazioni', 'dfn-theme'),
            'version'       => '2.0',
            'icon'          => 'dashicons-tickets-alt',
            'emoji'         => '🎟️',
            'badge'         => __('Biglietteria & Cassa', 'dfn-theme'),
            'description'   => __('Gestione degli eventi aperti al pubblico, calendario con fasce orarie e posti, vendita/prenotazione biglietti con WooCommerce, botteghino rapido, gestione cassa/POS in loco e scanner QR code.', 'dfn-theme'),
            'features'      => [
                __('Creazione ed editing eventi con slot orari', 'dfn-theme'),
                __('Widget di prenotazione & integrazione WooCommerce', 'dfn-theme'),
                __('Gateway di pagamento In Loco (Contanti & POS)', 'dfn-theme'),
                __('Scanner QR Code & App Mobile /gestione-eventi/', 'dfn-theme'),
            ],
            'menu_selector' => '#toplevel_page_dfn-events',
            'default'       => true,
        ],
        'volontari' => [
            'id'            => 'volontari',
            'category'      => 'core',
            'name'          => __('Gestione Volontari', 'dfn-theme'),
            'version'       => '2.1',
            'icon'          => 'dashicons-groups',
            'emoji'         => '👥',
            'badge'         => __('Logistica & Delegazione', 'dfn-theme'),
            'description'   => __('Piattaforma completa per l\'organizzazione dei volontari: pianificazione turni e squadre per Giornate FAI ed eventi locali, sondaggi di disponibilità, convocazione riunioni e upgrade dell\'area personale Mio Account.', 'dfn-theme'),
            'features'      => [
                __('Matrice Logistica Turni & Postazioni (Giornate FAI)', 'dfn-theme'),
                __('Sondaggi di disponibilità turni per i volontari', 'dfn-theme'),
                __('Calendario Convocazioni Riunioni di Delegazione', 'dfn-theme'),
                __('Anagrafica Volontari, Competenze & Mansioni FAI', 'dfn-theme'),
                __('Upgrade Mio Account: Bacheca, Turni, Sondaggi, Riunioni', 'dfn-theme'),
                __('Modulo candidatura e registrazione nuovi volontari', 'dfn-theme'),
            ],
            'menu_selector' => '#toplevel_page_dfn-volunteers',
            'default'       => true,
        ],

        // --- SOTTO-MODULI FUNZIONALI PRENOTAZIONI ---
        'promozioni' => [
            'id'            => 'promozioni',
            'category'      => 'feature',
            'name'          => __('Promozioni & Newsletter', 'dfn-theme'),
            'version'       => '2.0.1',
            'icon'          => 'dashicons-megaphone',
            'emoji'         => '📢',
            'badge'         => __('Marketing & Comunicazione', 'dfn-theme'),
            'description'   => __('Campagne email promozionali per eventi futuri inviate agli iscritti passati, gestione batch in background, statistiche di invio e annullamento campagne.', 'dfn-theme'),
            'features'      => [
                __('Campagne promozionali mirate su eventi attivi', 'dfn-theme'),
                __('Invio asincrono in background a lotti anti-spam', 'dfn-theme'),
                __('Esclusione automatica placeholder e ordini duplicati', 'dfn-theme'),
                __('Tracciamento percentuale avanzamento e pulsante annulla', 'dfn-theme'),
            ],
            'setting_key'   => 'enable_event_promotions',
            'menu_selector' => '#adminmenu a[href*="page=dfn-promotions"]',
            'default'       => true,
        ],
        'waitlist' => [
            'id'            => 'waitlist',
            'category'      => 'feature',
            'name'          => __('Lista d\'Attesa', 'dfn-theme'),
            'version'       => '2.0',
            'icon'          => 'dashicons-clock',
            'emoji'         => '⏳',
            'badge'         => __('Gestione Posti Esauriti', 'dfn-theme'),
            'description'   => __('Consente agli utenti di iscriversi a una lista d\'attesa quando uno slot orario è esaurito, con bacheca dedicata e ripescaggio manuale o automatico.', 'dfn-theme'),
            'features'      => [
                __('Iscrizione lista d\'attesa per fasce orarie sold out', 'dfn-theme'),
                __('Bacheca dedicata con visualizzazione partecipanti in coda', 'dfn-theme'),
                __('Ripescaggio partecipanti e conversione rapida in prenotazione', 'dfn-theme'),
            ],
            'setting_key'   => 'enable_auto_waitlist',
            'menu_selector' => '#adminmenu a[href*="page=dfn-waitlist"]',
            'default'       => true,
        ],
        'accounting' => [
            'id'            => 'accounting',
            'category'      => 'feature',
            'name'          => __('Bilancio Eventi', 'dfn-theme'),
            'version'       => '2.0',
            'icon'          => 'dashicons-chart-pie',
            'emoji'         => '📊',
            'badge'         => __('Rendicontazione Economica', 'dfn-theme'),
            'description'   => __('Rendicontazione economica dettagliata degli incassi per evento: spaccato pagamenti online, contanti in cassa, donazioni e quote soci FAI.', 'dfn-theme'),
            'features'      => [
                __('Riepilogo entrate online vs contanti in cassa', 'dfn-theme'),
                __('Dettaglio quote soci FAI ed erogazioni liberali', 'dfn-theme'),
                __('Esportazione e rendiconto per tesoreria di Delegazione', 'dfn-theme'),
            ],
            'setting_key'   => 'enable_event_accounting',
            'menu_selector' => '#adminmenu a[href*="page=cv-bilancio-eventi"]',
            'default'       => true,
        ],
        'reviews' => [
            'id'            => 'reviews',
            'category'      => 'feature',
            'name'          => __('Recensione Eventi', 'dfn-theme'),
            'version'       => '2.0',
            'icon'          => 'dashicons-star-filled',
            'emoji'         => '⭐',
            'badge'         => __('Feedback & Valutazioni', 'dfn-theme'),
            'description'   => __('Raccolta delle valutazioni e recensioni a 5 stelle dai partecipanti agli eventi conclusi, scorecard statistica e carosello testimonianze nel frontend.', 'dfn-theme'),
            'features'      => [
                __('Invio richieste feedback a partecipanti convalidati', 'dfn-theme'),
                __('Scorecard di gradimento e punteggio medio in stelle', 'dfn-theme'),
                __('Carosello testimonianze con privacy nomi sul frontend', 'dfn-theme'),
                __('Moderazione e bacheca recensioni in WP Admin', 'dfn-theme'),
            ],
            'setting_key'   => 'enable_event_reviews',
            'menu_selector' => '#adminmenu a[href*="page=cv-recensioni-eventi"]',
            'default'       => true,
        ],
        'botteghino' => [
            'id'            => 'botteghino',
            'category'      => 'feature',
            'name'          => __('Botteghino Live', 'dfn-theme'),
            'version'       => '2.0',
            'icon'          => 'dashicons-store',
            'emoji'         => '🎟️',
            'badge'         => __('Cassa POS in Sede', 'dfn-theme'),
            'description'   => __('Interfaccia di vendita diretta in sede per i volontari: incasso in contanti rapido, invio link di pagamento digitale, riserva posti autorità e convalidazioni istantanee.', 'dfn-theme'),
            'features'      => [
                __('Creazione rapida ordini al desk di accoglienza', 'dfn-theme'),
                __('Gestione contanti, POS, link pagamento e posti riservati', 'dfn-theme'),
                __('Integrazione mobile PWA e scanner per operatori in loco', 'dfn-theme'),
            ],
            'setting_key'   => 'enable_live_boxoffice',
            'menu_selector' => '#adminmenu a[href*="page=cv-generatore-fai"]',
            'default'       => true,
        ],
    ];
}

/**
 * Restituisce lo stato di attivazione di tutti i moduli.
 *
 * @return array<string, bool>
 */
function dfn_get_modules_status(): array
{
    $defaults = [
        'prenotazioni' => true,
        'volontari'    => true,
        'promozioni'   => true,
        'waitlist'     => true,
        'accounting'   => true,
        'reviews'      => true,
        'botteghino'   => true,
    ];

    $saved = get_option('dfn_active_modules', null);

    if (is_array($saved)) {
        $status = wp_parse_args($saved, $defaults);
    } else {
        $status = $defaults;

        // Se dfn_active_modules non è ancora stato salvato, controlla dfn_settings storiche
        $dfn_settings = get_option('dfn_settings', []);
        $setting_map = [
            'promozioni' => 'enable_event_promotions',
            'waitlist'   => 'enable_auto_waitlist',
            'accounting' => 'enable_event_accounting',
            'reviews'    => 'enable_event_reviews',
            'botteghino' => 'enable_live_boxoffice',
        ];

        if (is_array($dfn_settings)) {
            foreach ($setting_map as $mod_key => $setting_key) {
                if (isset($dfn_settings[$setting_key])) {
                    $status[$mod_key] = ('yes' === $dfn_settings[$setting_key] || '1' === (string) $dfn_settings[$setting_key]);
                }
            }
        }
    }

    // Supporto alias promotions -> promozioni
    if (isset($status['promotions']) && ! isset($status['promozioni'])) {
        $status['promozioni'] = $status['promotions'];
    }

    return $status;
}

/**
 * Verifica se uno specifico modulo è attualmente attivo.
 *
 * @param string $module_id Identificativo del modulo.
 * @return bool
 */
function dfn_is_module_active(string $module_id): bool
{
    if ('promotions' === $module_id) {
        $module_id = 'promozioni';
    }
    $status = dfn_get_modules_status();
    return ! empty($status[$module_id]);
}

/**
 * Aggiorna lo stato di attivazione di un modulo.
 *
 * @param string $module_id Identificativo del modulo.
 * @param bool   $is_active Stato di attivazione.
 * @return bool
 */
function dfn_set_module_status(string $module_id, bool $is_active): bool
{
    if ('promotions' === $module_id) {
        $module_id = 'promozioni';
    }

    $current = dfn_get_modules_status();
    $current[$module_id] = (bool) $is_active;

    $updated = update_option('dfn_active_modules', $current);

    // Sincronizza anche il setting corrispondente in dfn_settings
    $setting_map = [
        'promozioni' => 'enable_event_promotions',
        'waitlist'   => 'enable_auto_waitlist',
        'accounting' => 'enable_event_accounting',
        'reviews'    => 'enable_event_reviews',
        'botteghino' => 'enable_live_boxoffice',
    ];

    if (isset($setting_map[$module_id]) && function_exists('dfn_update_setting')) {
        dfn_update_setting($setting_map[$module_id], $is_active ? 'yes' : 'no');
    }

    // Flush rewrite rules se cambiamo lo stato del modulo volontari (per endpoint Mio Account)
    if ('volontari' === $module_id) {
        flush_rewrite_rules(false);
    }

    return (bool) $updated;
}

/**
 * Registra il menu di gestione moduli nel pannello di amministrazione WordPress.
 */
function dfn_register_modules_admin_menu(): void
{
    // Registra la pagina come menu indipendente di primo livello
    add_menu_page(
        __('Moduli FAI', 'dfn-theme'),
        __('Moduli FAI', 'dfn-theme'),
        'manage_options',
        'dfn-modules',
        'dfn_render_modules_manager_page',
        'dashicons-screenoptions',
        55.4
    );

    // Menu di primo livello globale per l'ecosistema DFN
    // I sotto-moduli di FAI Prenotazioni si configurano in FAI Prenotazioni -> Impostazioni -> Moduli

    // Se il menu Volontari è attivo, aggiunge anche un link nel sottomenu Volontari
    if (dfn_is_module_active('volontari')) {
        add_submenu_page(
            'dfn-volunteers',
            __('Moduli FAI', 'dfn-theme'),
            __('Moduli FAI', 'dfn-theme'),
            'manage_options',
            'dfn-modules',
            'dfn_render_modules_manager_page'
        );
    }
}
add_action('admin_menu', 'dfn_register_modules_admin_menu', 99);

/**
 * Gestisce il salvataggio AJAX dello switch di attivazione/disattivazione di un modulo.
 */
function dfn_ajax_toggle_module(): void
{
    check_ajax_referer('dfn_modules_nonce', 'nonce');

    if (! current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permessi non sufficienti.', 'dfn-theme')]);
    }

    $module_id = isset($_POST['module_id']) ? sanitize_key($_POST['module_id']) : '';
    $status    = isset($_POST['status']) && '1' === (string) $_POST['status'];

    $available = dfn_get_available_modules();
    if (! isset($available[$module_id])) {
        wp_send_json_error(['message' => __('Modulo non valido.', 'dfn-theme')]);
    }

    dfn_set_module_status($module_id, $status);

    wp_send_json_success([
        'module_id' => $module_id,
        'status'    => $status,
        'message'   => sprintf(
            __('Modulo "%s" %s con successo!', 'dfn-theme'),
            $available[$module_id]['name'],
            $status ? __('attivato', 'dfn-theme') : __('disattivato', 'dfn-theme')
        ),
    ]);
}
add_action('wp_ajax_dfn_toggle_module', 'dfn_ajax_toggle_module');

/**
 * Renderizza una singola card di configurazione modulo.
 *
 * @param array<string, mixed> $mod    Dati del modulo.
 * @param bool                 $is_active Stato attivo/disattivo.
 */
function dfn_render_module_card(array $mod, bool $is_active): void
{
    $mod_key = $mod['id'];
    ?>
    <div class="dfn-module-card" id="module-card-<?php echo esc_attr($mod_key); ?>" style="background: #fff; border-radius: 14px; border: 2px solid <?php echo $is_active ? '#004b23' : '#e2e8f0'; ?>; box-shadow: 0 4px 16px rgba(0,0,0,0.04); display: flex; flex-direction: column; overflow: hidden; transition: all 0.25s ease;">
        
        <!-- Header Card -->
        <div style="padding: 20px 22px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; background: <?php echo $is_active ? '#fcfdfc' : '#f8fafc'; ?>;">
            <div style="display: flex; gap: 14px; align-items: center;">
                <div style="width: 46px; height: 46px; border-radius: 12px; background: <?php echo $is_active ? '#e6f3eb' : '#f1f5f9'; ?>; color: <?php echo $is_active ? '#004b23' : '#94a3b8'; ?>; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0;">
                    <?php echo esc_html($mod['emoji']); ?>
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <h3 style="margin: 0; font-size: 17px; font-weight: 800; color: #0f172a;"><?php echo esc_html($mod['name']); ?></h3>
                        <span style="font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 6px; background: #f1f5f9; color: #475569;">v<?php echo esc_html($mod['version']); ?></span>
                    </div>
                    <div style="font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: <?php echo $is_active ? '#004b23' : '#64748b'; ?>; margin-top: 3px;">
                        <?php echo esc_html($mod['badge']); ?>
                    </div>
                </div>
            </div>

            <!-- Toggle Switch Switcher -->
            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px; flex-shrink: 0;">
                <label class="dfn-toggle-switch" style="position: relative; display: inline-block; width: 54px; height: 28px; margin: 0; cursor: pointer;">
                    <input type="checkbox" 
                           name="module_<?php echo esc_attr($mod_key); ?>" 
                           value="1" 
                           class="dfn-module-checkbox" 
                           data-module="<?php echo esc_attr($mod_key); ?>"
                           data-selector="<?php echo esc_attr($mod['menu_selector'] ?? ''); ?>"
                           <?php checked($is_active, true); ?>
                           style="opacity: 0; width: 0; height: 0;">
                    <span class="dfn-toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: <?php echo $is_active ? '#004b23' : '#cbd5e1'; ?>; transition: .3s; border-radius: 34px;">
                        <span class="dfn-toggle-circle" style="position: absolute; content: ''; height: 20px; width: 20px; left: <?php echo $is_active ? '30px' : '4px'; ?>; bottom: 4px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></span>
                    </span>
                </label>
                <span class="dfn-module-status-badge" id="badge-<?php echo esc_attr($mod_key); ?>" style="font-size: 11px; font-weight: 800; color: <?php echo $is_active ? '#004b23' : '#64748b'; ?>;">
                    <?php echo $is_active ? '● ATTIVO' : '○ DISATTIVATO'; ?>
                </span>
            </div>
        </div>

        <!-- Body Card -->
        <div style="padding: 20px 22px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <p style="font-size: 13.5px; line-height: 1.6; color: #334155; margin: 0 0 16px 0;">
                    <?php echo esc_html($mod['description']); ?>
                </p>

                <div style="font-size: 11.5px; font-weight: 800; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 8px;">
                    <?php esc_html_e('Funzionalità Incluse:', 'dfn-theme'); ?>
                </div>
                <ul style="margin: 0 0 18px 0; padding-left: 0; list-style: none;">
                    <?php foreach ($mod['features'] as $feat) : ?>
                        <li style="font-size: 12.5px; color: #475569; margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">
                            <span style="color: <?php echo $is_active ? '#004b23' : '#94a3b8'; ?>; font-size: 13px;">✓</span>
                            <span><?php echo esc_html($feat); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Box Note Architetturali -->
            <?php if ('prenotazioni' === $mod_key) : ?>
                <div style="background: #f8fafc; border-left: 3px solid #004b23; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #475569;">
                    💡 <strong>Modulo Core:</strong> <?php esc_html_e('Sblocca la biglietteria pubblica, la vendita WooCommerce e la gestione turni visitatori.', 'dfn-theme'); ?>
                </div>
            <?php elseif ('volontari' === $mod_key) : ?>
                <div style="background: #f8fafc; border-left: 3px solid #c69c3a; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #475569;">
                    💡 <strong>Modulo Core:</strong> <?php esc_html_e('Può funzionare in modo 100% autonomo per turni e bacheca volontari, oppure integrarsi con Prenotazioni per lo scanner sul campo.', 'dfn-theme'); ?>
                </div>
            <?php else : ?>
                <div style="background: #f8fafc; border-left: 3px solid #3b82f6; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #475569;">
                    ⚡ <strong>Funzionalità Dedicata:</strong> <?php esc_html_e('Disattivando questo modulo, la voce di menu e tutti i componenti correlati nel backend e frontend vengono nascosti.', 'dfn-theme'); ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
    <?php
}

/**
 * Renderizza l'interfaccia di amministrazione della pagina "Moduli FAI".
 */
function dfn_render_modules_manager_page(): void
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Non hai i permessi per accedere a questa pagina.', 'dfn-theme'));
    }

    // Gestione salvataggio tradizionale (fallback senza JS)
    $notice = '';
    if (isset($_POST['dfn_save_modules']) && check_admin_referer('dfn_save_modules_action', 'dfn_modules_nonce_post')) {
        $available = dfn_get_available_modules();
        foreach ($available as $mod_key => $mod_data) {
            $is_act = isset($_POST['module_' . $mod_key]) && '1' === $_POST['module_' . $mod_key];
            dfn_set_module_status($mod_key, $is_act);
        }
        $notice = __('Configurazione moduli aggiornata con successo!', 'dfn-theme');
    }

    $all_modules = dfn_get_available_modules();
    $status      = dfn_get_modules_status();

    $core_modules    = array_filter($all_modules, function($m) { return ($m['category'] ?? 'core') === 'core'; });
    $feature_modules = array_filter($all_modules, function($m) { return ($m['category'] ?? '') === 'feature'; });

    ?>
    <div class="wrap dfn-admin-wrap" style="max-width: 1140px; margin: 25px auto 40px;">
        
        <!-- Header -->
        <header class="dfn-admin-header" style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <span class="dashicons dashicons-screenoptions" style="font-size: 32px; width: 32px; height: 32px; color: #004b23;"></span>
                    <h1 style="margin: 0; font-size: 26px; font-weight: 800; color: #0f172a;"><?php esc_html_e('Gestione Moduli FAI', 'dfn-theme'); ?></h1>
                </div>
                <p style="margin: 0; font-size: 14.5px; color: #64748b;">
                    <?php esc_html_e('Attiva o disattiva i moduli del sistema in base alle esigenze della Delegazione.', 'dfn-theme'); ?>
                </p>
            </div>
            <div>
                <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 20px; font-size: 13px; font-weight: 700; color: #065f46;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981;"></span>
                    <?php esc_html_e('Architettura Modulare v2.1', 'dfn-theme'); ?>
                </span>
            </div>
        </header>

        <?php if (! empty($notice)) : ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom: 24px; border-left-color: #004b23;">
                <p><strong>✅ <?php echo esc_html($notice); ?></strong></p>
            </div>
        <?php endif; ?>

        <!-- Banner Informativo -->
        <div style="background: linear-gradient(135deg, #004b23 0%, #163820 100%); border-radius: 14px; padding: 24px 28px; color: #fff; margin-bottom: 35px; box-shadow: 0 10px 25px rgba(0,75,35,0.08); position: relative; overflow: hidden;">
            <div style="position: absolute; right: -15px; bottom: -25px; font-size: 140px; opacity: 0.08; pointer-events: none;">🏛️</div>
            <h2 style="margin: 0 0 8px 0; color: #f59e0b; font-size: 19px; font-weight: 800;">
                <?php esc_html_e('Interruttori Centrali di Sistema', 'dfn-theme'); ?>
            </h2>
            <p style="margin: 0; font-size: 14px; line-height: 1.6; max-width: 860px; color: #e2f0e7;">
                <?php esc_html_e('Ogni modulo opera in modo indipendente. Quando disattivi un modulo, le relative voci di menu in WP Admin, le schermate operative, i widget nel frontend e gli endpoint vengono immediatamente silenziati e nascosti, mantenendo il sistema veloce, pulito e privo di errori.', 'dfn-theme'); ?>
            </p>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('dfn_save_modules_action', 'dfn_modules_nonce_post'); ?>

            <!-- ===== SEZIONE 1: MODULI CORE (Macro-Aree) ===== -->
            <div style="margin-bottom: 40px;">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 22px;">
                    <div>
                        <h2 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                            <span>🏛️</span> <?php esc_html_e('Macro-Moduli Core di Sistema', 'dfn-theme'); ?>
                        </h2>
                        <p style="margin: 4px 0 0; font-size: 13px; color: #64748b;">
                            <?php esc_html_e('I due pilastri architetturali indipendenti di DFN: Prenotazioni ed Ecosistema Volontari.', 'dfn-theme'); ?>
                        </p>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 24px;">
                    <?php foreach ($core_modules as $mod) : 
                        dfn_render_module_card($mod, ! empty($status[$mod['id']]));
                    endforeach; ?>
                </div>
            </div>

            <!-- ===== SEZIONE 2: GUIDA ALLA CONFIGURAZIONE DEI SOTTO-MODULI ===== -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px 28px; margin-bottom: 35px;">
                <div style="margin-bottom: 16px;">
                    <h3 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                        <span>🧩</span> <?php esc_html_e('Configurazione Sotto-Moduli Specifici', 'dfn-theme'); ?>
                    </h3>
                    <p style="margin: 0; font-size: 13.5px; color: #64748b; line-height: 1.5;">
                        <?php esc_html_e('Ciascun macro-modulo dispone delle proprie impostazioni dedicate per gestire e abilitare/disabilitare i singoli sotto-moduli operativi:', 'dfn-theme'); ?>
                    </p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 18px;">
                    <!-- Card Link FAI Prenotazioni -->
                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
                        <div>
                            <div style="font-weight: 800; color: #0f172a; font-size: 14.5px;">🎟️ <?php esc_html_e('Moduli FAI Prenotazioni', 'dfn-theme'); ?></div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                <?php esc_html_e('Promozioni, Lista d\'Attesa, Bilancio Eventi, Recensioni, Botteghino Live', 'dfn-theme'); ?>
                            </div>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-settings&tab=moduli')); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23; font-weight: 700; height: 36px; line-height: 34px; padding: 0 16px; flex-shrink: 0;">
                            <?php esc_html_e('Apri Moduli &rarr;', 'dfn-theme'); ?>
                        </a>
                    </div>

                    <!-- Card Link Volontari FAI -->
                    <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 6px rgba(0,0,0,0.02);">
                        <div>
                            <div style="font-weight: 800; color: #0f172a; font-size: 14.5px;">👥 <?php esc_html_e('Moduli Volontari FAI', 'dfn-theme'); ?></div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                <?php esc_html_e('Team di Lavoro, Matrice Logistica Turni, Convocazioni, Sondaggi', 'dfn-theme'); ?>
                            </div>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-settings')); ?>" class="button button-secondary" style="font-weight: 700; height: 36px; line-height: 34px; padding: 0 16px; flex-shrink: 0; color: #004b23; border-color: #004b23;">
                            <?php esc_html_e('Impostazioni &rarr;', 'dfn-theme'); ?>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Pulsante di Salvataggio Fallback -->
            <div style="background: #fff; border-radius: 12px; padding: 18px 24px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
                <div style="font-size: 13px; color: #64748b;">
                    <?php esc_html_e('Le modifiche tramite switch vengono salvate automaticamente via AJAX e riflesse istantaneamente nel menu. Puoi anche cliccare il pulsante di salvataggio.', 'dfn-theme'); ?>
                </div>
                <button type="submit" name="dfn_save_modules" class="button button-primary" style="background: #004b23; border-color: #004b23; height: 40px; padding: 0 24px; font-size: 14px; font-weight: 700; border-radius: 6px;">
                    💾 <?php esc_html_e('Salva Moduli', 'dfn-theme'); ?>
                </button>
            </div>
        </form>

        <!-- Toast Notification AJAX -->
        <div id="dfn-toast" style="position: fixed; bottom: 30px; right: 30px; background: #0f172a; color: #fff; padding: 14px 22px; border-radius: 10px; font-size: 14px; font-weight: 700; box-shadow: 0 10px 30px rgba(0,0,0,0.25); display: none; z-index: 99999; align-items: center; gap: 10px; border-left: 4px solid #10b981;">
            <span id="dfn-toast-msg"></span>
        </div>

    </div>

    <!-- Script AJAX per Switch Istantaneo & Aggiornamento Dinamico del Menu DOM -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const checkboxes = document.querySelectorAll('.dfn-module-checkbox');
        const toast = document.getElementById('dfn-toast');
        const toastMsg = document.getElementById('dfn-toast-msg');
        let toastTimeout = null;

        function showToast(message, isSuccess = true) {
            if (toastTimeout) clearTimeout(toastTimeout);
            toastMsg.innerText = message;
            toast.style.borderLeftColor = isSuccess ? '#10b981' : '#ef4444';
            toast.style.display = 'flex';
            toastTimeout = setTimeout(() => {
                toast.style.display = 'none';
            }, 3500);
        }

        checkboxes.forEach(function(cb) {
            cb.addEventListener('change', function() {
                const moduleId = this.getAttribute('data-module');
                const selector = this.getAttribute('data-selector');
                const isChecked = this.checked;
                const card = document.getElementById('module-card-' + moduleId);
                const badge = document.getElementById('badge-' + moduleId);
                const slider = this.nextElementSibling;
                const circle = slider ? slider.querySelector('.dfn-toggle-circle') : null;

                // 1. Aggiornamento Visivo Istantaneo della Card
                if (isChecked) {
                    if (slider) slider.style.backgroundColor = '#004b23';
                    if (circle) circle.style.left = '30px';
                    if (card) card.style.borderColor = '#004b23';
                    if (badge) {
                        badge.innerText = '● ATTIVO';
                        badge.style.color = '#004b23';
                    }
                } else {
                    if (slider) slider.style.backgroundColor = '#cbd5e1';
                    if (circle) circle.style.left = '4px';
                    if (card) card.style.borderColor = '#e2e8f0';
                    if (badge) {
                        badge.innerText = '○ DISATTIVATO';
                        badge.style.color = '#64748b';
                    }
                }

                // 2. Aggiornamento Dinamico del Menu WP nel DOM (Regola 6.3 AGENTS.md)
                if (selector) {
                    try {
                        const targetElements = document.querySelectorAll(selector);
                        targetElements.forEach(function(el) {
                            // Se è un link sottomenu (<a>), nascondi il tag <li> genitore
                            if (el.tagName.toLowerCase() === 'a' && el.parentElement && el.parentElement.tagName.toLowerCase() === 'li') {
                                el.parentElement.style.display = isChecked ? '' : 'none';
                            } else {
                                el.style.display = isChecked ? '' : 'none';
                            }
                        });
                    } catch (e) {
                        console.warn('Errore selettore menu:', e);
                    }
                }

                // 3. Chiamata AJAX
                const data = new FormData();
                data.append('action', 'dfn_toggle_module');
                data.append('module_id', moduleId);
                data.append('status', isChecked ? '1' : '0');
                data.append('nonce', '<?php echo esc_js(wp_create_nonce('dfn_modules_nonce')); ?>');

                fetch(ajaxurl, {
                    method: 'POST',
                    body: data
                })
                .then(response => response.json())
                .then(res => {
                    if (res.success) {
                        showToast(res.data.message || 'Stato modulo aggiornato!', true);
                    } else {
                        showToast(res.data.message || 'Errore durante l\'aggiornamento.', false);
                    }
                })
                .catch(err => {
                    showToast('Errore di rete durante il salvataggio.', false);
                });
            });
        });
    });
    </script>
    <?php
}

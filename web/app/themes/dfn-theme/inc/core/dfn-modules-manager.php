<?php

/**
 * DFN Booking & Volunteer System — Modules Manager
 *
 * Gestisce l'abilitazione e disabilitazione modulare delle componenti FAI:
 * - Gestione Prenotazioni (v2.0)
 * - Gestione Volontari (v2.1)
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
        'prenotazioni' => [
            'id'          => 'prenotazioni',
            'name'        => __('Gestione Prenotazioni', 'dfn-theme'),
            'version'     => '2.0',
            'icon'        => 'dashicons-tickets-alt',
            'emoji'       => '🎟️',
            'badge'       => __('Biglietteria & Cassa', 'dfn-theme'),
            'description' => __('Gestione degli eventi aperti al pubblico, calendario con fasce orarie e posti, vendita/prenotazione biglietti con WooCommerce, botteghino rapido, gestione cassa/POS in loco, scanner QR code e liste d\'attesa.', 'dfn-theme'),
            'features'    => [
                __('Creazione ed editing eventi con slot orari', 'dfn-theme'),
                __('Widget di prenotazione & integrazione WooCommerce', 'dfn-theme'),
                __('Botteghino Live & Inserimento rapido ordini', 'dfn-theme'),
                __('Gateway di pagamento In Loco (Contanti & POS)', 'dfn-theme'),
                __('Scanner QR Code & App Mobile /gestione-eventi/', 'dfn-theme'),
                __('Contabilità, Cassa, Report & Liste d\'attesa', 'dfn-theme'),
            ],
            'default'     => true,
        ],
        'volontari' => [
            'id'          => 'volontari',
            'name'        => __('Gestione Volontari', 'dfn-theme'),
            'version'     => '2.1',
            'icon'        => 'dashicons-groups',
            'emoji'       => '👥',
            'badge'       => __('Logistica & Delegazione', 'dfn-theme'),
            'description' => __('Piattaforma completa per l\'organizzazione dei volontari: pianificazione turni e squadre per Giornate FAI ed eventi locali, sondaggi di disponibilità, convocazione riunioni e upgrade dell\'area personale Mio Account.', 'dfn-theme'),
            'features'    => [
                __('Matrice Logistica Turni & Postazioni (Giornate FAI)', 'dfn-theme'),
                __('Sondaggi di disponibilità turni per i volontari', 'dfn-theme'),
                __('Calendario Convocazioni Riunioni di Delegazione', 'dfn-theme'),
                __('Anagrafica Volontari, Competenze & Mansioni FAI', 'dfn-theme'),
                __('Upgrade Mio Account: Bacheca, Turni, Sondaggi, Riunioni', 'dfn-theme'),
                __('Modulo candidatura e registrazione nuovi volontari', 'dfn-theme'),
            ],
            'default'     => true,
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
    ];

    $saved = get_option('dfn_active_modules', $defaults);

    if (! is_array($saved)) {
        return $defaults;
    }

    return wp_parse_args($saved, $defaults);
}

/**
 * Verifica se uno specifico modulo è attualmente attivo.
 *
 * @param string $module_id Identificativo del modulo ('prenotazioni' o 'volontari').
 * @return bool
 */
function dfn_is_module_active(string $module_id): bool
{
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
    $current = dfn_get_modules_status();
    $current[$module_id] = (bool) $is_active;

    $updated = update_option('dfn_active_modules', $current);

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

    // Se il menu Prenotazioni è attivo, aggiunge anche un link nel suo sottomenu per comodità
    if (dfn_is_module_active('prenotazioni')) {
        add_submenu_page(
            'dfn-events',
            __('Moduli FAI', 'dfn-theme'),
            __('Moduli FAI', 'dfn-theme'),
            'manage_options',
            'dfn-modules',
            'dfn_render_modules_manager_page'
        );
    }

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
        $new_status = [];
        foreach ($available as $mod_key => $mod_data) {
            $new_status[$mod_key] = isset($_POST['module_' . $mod_key]) && '1' === $_POST['module_' . $mod_key];
        }
        update_option('dfn_active_modules', $new_status);
        flush_rewrite_rules(false);
        $notice = __('Configurazione moduli aggiornata con successo!', 'dfn-theme');
    }

    $modules = dfn_get_available_modules();
    $status  = dfn_get_modules_status();

    ?>
    <div class="wrap dfn-admin-wrap" style="max-width: 1100px; margin: 25px auto 40px;">
        
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
        <div style="background: linear-gradient(135deg, #004b23 0%, #163820 100%); border-radius: 14px; padding: 24px 28px; color: #fff; margin-bottom: 30px; box-shadow: 0 10px 25px rgba(0,75,35,0.08); position: relative; overflow: hidden;">
            <div style="position: absolute; right: -15px; bottom: -25px; font-size: 140px; opacity: 0.08; pointer-events: none;">🏛️</div>
            <h2 style="margin: 0 0 8px 0; color: #f59e0b; font-size: 19px; font-weight: 800;">
                <?php esc_html_e('Interruttori Centrali di Sistema', 'dfn-theme'); ?>
            </h2>
            <p style="margin: 0; font-size: 14px; line-height: 1.6; max-width: 820px; color: #e2f0e7;">
                <?php esc_html_e('Ogni modulo opera come un pacchetto indipendente. Quando disattivi un modulo, tutte le relative voci di menu in WP Admin, le schermate operative, i widget nel frontend e gli endpoint dell\'area riservata vengono immediatamente nascosti e silenziati, mantenendo il sito pulito, veloce e privo di errori.', 'dfn-theme'); ?>
            </p>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('dfn_save_modules_action', 'dfn_modules_nonce_post'); ?>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 26px; margin-bottom: 35px;">
                
                <?php foreach ($modules as $mod_key => $mod) : 
                    $is_active = ! empty($status[$mod_key]);
                ?>
                    <div class="dfn-module-card" id="module-card-<?php echo esc_attr($mod_key); ?>" style="background: #fff; border-radius: 14px; border: 2px solid <?php echo $is_active ? '#004b23' : '#e2e8f0'; ?>; box-shadow: 0 4px 16px rgba(0,0,0,0.04); display: flex; flex-direction: column; overflow: hidden; transition: all 0.25s ease;">
                        
                        <!-- Header Card -->
                        <div style="padding: 22px 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; background: <?php echo $is_active ? '#fcfdfc' : '#f8fafc'; ?>;">
                            <div style="display: flex; gap: 14px; align-items: center;">
                                <div style="width: 48px; height: 48px; border-radius: 12px; background: <?php echo $is_active ? '#e6f3eb' : '#f1f5f9'; ?>; color: <?php echo $is_active ? '#004b23' : '#94a3b8'; ?>; display: flex; align-items: center; justify-content: center; font-size: 26px;">
                                    <?php echo esc_html($mod['emoji']); ?>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <h3 style="margin: 0; font-size: 18px; font-weight: 800; color: #0f172a;"><?php echo esc_html($mod['name']); ?></h3>
                                        <span style="font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 6px; background: #f1f5f9; color: #475569;">v<?php echo esc_html($mod['version']); ?></span>
                                    </div>
                                    <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: <?php echo $is_active ? '#004b23' : '#64748b'; ?>; margin-top: 3px;">
                                        <?php echo esc_html($mod['badge']); ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Toggle Switch Switcher -->
                            <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                                <label class="dfn-toggle-switch" style="position: relative; display: inline-block; width: 56px; height: 30px; margin: 0; cursor: pointer;">
                                    <input type="checkbox" 
                                           name="module_<?php echo esc_attr($mod_key); ?>" 
                                           value="1" 
                                           class="dfn-module-checkbox" 
                                           data-module="<?php echo esc_attr($mod_key); ?>"
                                           <?php checked($is_active, true); ?>
                                           style="opacity: 0; width: 0; height: 0;">
                                    <span class="dfn-toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: <?php echo $is_active ? '#004b23' : '#cbd5e1'; ?>; transition: .3s; border-radius: 34px;">
                                        <span class="dfn-toggle-circle" style="position: absolute; content: ''; height: 22px; width: 22px; left: <?php echo $is_active ? '30px' : '4px'; ?>; bottom: 4px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></span>
                                    </span>
                                </label>
                                <span class="dfn-module-status-badge" id="badge-<?php echo esc_attr($mod_key); ?>" style="font-size: 11.5px; font-weight: 800; color: <?php echo $is_active ? '#004b23' : '#64748b'; ?>;">
                                    <?php echo $is_active ? '● ATTIVO' : '○ DISATTIVATO'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Body Card -->
                        <div style="padding: 22px 24px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;">
                            <div>
                                <p style="font-size: 13.5px; line-height: 1.6; color: #334155; margin: 0 0 18px 0;">
                                    <?php echo esc_html($mod['description']); ?>
                                </p>

                                <div style="font-size: 12px; font-weight: 800; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 10px;">
                                    <?php esc_html_e('Funzionalità Incluse:', 'dfn-theme'); ?>
                                </div>
                                <ul style="margin: 0 0 20px 0; padding-left: 0; list-style: none;">
                                    <?php foreach ($mod['features'] as $feat) : ?>
                                        <li style="font-size: 13px; color: #475569; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                                            <span style="color: <?php echo $is_active ? '#004b23' : '#94a3b8'; ?>; font-size: 14px;">✓</span>
                                            <span><?php echo esc_html($feat); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <!-- Box Dipendenze / Note Logiche -->
                            <?php if ('prenotazioni' === $mod_key) : ?>
                                <div style="background: #f8fafc; border-left: 3px solid #004b23; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #475569;">
                                    💡 <strong>Nota integrazione:</strong> <?php esc_html_e('Sblocca la biglietteria pubblica, la vendita WooCommerce, lo scanner QR e il botteghino rapido.', 'dfn-theme'); ?>
                                </div>
                            <?php elseif ('volontari' === $mod_key) : ?>
                                <div style="background: #f8fafc; border-left: 3px solid #c69c3a; border-radius: 6px; padding: 10px 14px; font-size: 12px; color: #475569;">
                                    💡 <strong>Nota integrazione:</strong> <?php esc_html_e('Può funzionare in modo 100% autonomo per turni, riunioni e sondaggi, oppure integrarsi con Prenotazioni per lo scanner sul campo.', 'dfn-theme'); ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endforeach; ?>

            </div>

            <!-- Pulsante di Salvataggio Fallback -->
            <div style="background: #fff; border-radius: 12px; padding: 18px 24px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 13px; color: #64748b;">
                    <?php esc_html_e('Le modifiche tramite switch vengono salvate automaticamente via AJAX. Puoi anche cliccare il pulsante qui a fianco.', 'dfn-theme'); ?>
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

    <!-- Script AJAX per Switch Istantaneo -->
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
                const isChecked = this.checked;
                const card = document.getElementById('module-card-' + moduleId);
                const badge = document.getElementById('badge-' + moduleId);
                const slider = this.nextElementSibling;
                const circle = slider.querySelector('.dfn-toggle-circle');

                // Visual update immediato
                if (isChecked) {
                    slider.style.backgroundColor = '#004b23';
                    circle.style.left = '30px';
                    card.style.borderColor = '#004b23';
                    badge.innerText = '● ATTIVO';
                    badge.style.color = '#004b23';
                } else {
                    slider.style.backgroundColor = '#cbd5e1';
                    circle.style.left = '4px';
                    card.style.borderColor = '#e2e8f0';
                    badge.innerText = '○ DISATTIVATO';
                    badge.style.color = '#64748b';
                }

                // Chiamata AJAX
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

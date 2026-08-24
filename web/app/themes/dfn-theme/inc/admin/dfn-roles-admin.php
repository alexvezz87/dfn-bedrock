<?php
/**
 * DFN Roles & Permissions Manager — Admin Interface
 *
 * Menu di primo livello "FAI — Ruoli e Permessi" con gestione a sotto-tab
 * per modulo (Prenotazioni, Convenzioni, Gestione Ruoli).
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_roles_register_admin_menu');
add_action('admin_init', 'dfn_roles_handle_post_actions');

/**
 * Registra il menu di primo livello "FAI — Ruoli e Permessi" e i suoi sottomenu.
 */
function dfn_roles_register_admin_menu(): void
{
    // Solo gli amministratori possono gestire i ruoli e i permessi globali
    $capability = 'manage_options';

    // Menu principale
    add_menu_page(
        __('FAI — Ruoli & Permessi', 'dfn-theme'),
        __('FAI Ruoli & Permessi', 'dfn-theme'),
        $capability,
        'dfn-roles',
        'dfn_roles_render_admin_page',
        'dashicons-shield',
        58
    );

    // Sottomenu 1: FAI Prenotazioni
    add_submenu_page(
        'dfn-roles',
        __('FAI Prenotazioni — Permessi', 'dfn-theme'),
        __('🎟️ FAI Prenotazioni', 'dfn-theme'),
        $capability,
        'dfn-roles',
        'dfn_roles_render_admin_page'
    );

    // Sottomenu 2: FAI Convenzioni
    add_submenu_page(
        'dfn-roles',
        __('FAI Convenzioni — Permessi', 'dfn-theme'),
        __('🏷️ FAI Convenzioni', 'dfn-theme'),
        $capability,
        'dfn-roles-convenzioni',
        'dfn_roles_render_admin_page'
    );

    // Sottomenu 3: Gestione Ruoli & Utenti
    add_submenu_page(
        'dfn-roles',
        __('Gestione Ruoli & Utenti', 'dfn-theme'),
        __('👥 Gestione Ruoli', 'dfn-theme'),
        $capability,
        'dfn-roles-manage',
        'dfn_roles_render_admin_page'
    );
}

/**
 * Gestisce i salvataggi della matrice e le operazioni sui ruoli (creazione / eliminazione).
 */
function dfn_roles_handle_post_actions(): void
{
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
        return;
    }

    if (! current_user_can('manage_options')) {
        return;
    }

    // 1. Salvataggio Matrice Permessi
    if (isset($_POST['dfn_save_roles_matrix_nonce']) && wp_verify_nonce($_POST['dfn_save_roles_matrix_nonce'], 'dfn_save_roles_matrix_action')) {
        $active_module = sanitize_key($_POST['current_module'] ?? 'prenotazioni');
        $catalog       = dfn_get_activities_catalog();
        $stored_matrix = dfn_get_stored_roles_matrix();
        $submitted_caps = isset($_POST['caps']) && is_array($_POST['caps']) ? $_POST['caps'] : [];

        if (isset($catalog[$active_module])) {
            $module_caps = array_keys($catalog[$active_module]);
            $roles = dfn_get_stored_roles();

            foreach (array_keys($roles) as $role_slug) {
                if ($role_slug === 'administrator') {
                    continue; // Administrator sempre tutto abilitato
                }

                if (! isset($stored_matrix[$role_slug])) {
                    $stored_matrix[$role_slug] = [];
                }

                foreach ($module_caps as $cap) {
                    $is_checked = ! empty($submitted_caps[$role_slug][$cap]);
                    $stored_matrix[$role_slug][$cap] = $is_checked;
                }
            }

            update_option('dfn_roles_matrix', $stored_matrix);
            dfn_sync_wp_roles();

            wp_safe_redirect(add_query_arg(['page' => $_GET['page'] ?? 'dfn-roles', 'saved' => '1'], admin_url('admin.php')));
            exit;
        }
    }

    // 2. Creazione Nuovo Ruolo
    if (isset($_POST['dfn_create_role_nonce']) && wp_verify_nonce($_POST['dfn_create_role_nonce'], 'dfn_create_role_action')) {
        $role_name = sanitize_text_field($_POST['new_role_name'] ?? '');
        $role_desc = sanitize_text_field($_POST['new_role_desc'] ?? '');

        if (! empty($role_name)) {
            $slug_raw = sanitize_title_with_dashes($_POST['new_role_slug'] ?? $role_name);
            $slug = 'dfn_' . str_replace('-', '_', $slug_raw);
            $slug = substr($slug, 0, 32);

            $roles = dfn_get_stored_roles();
            if (! isset($roles[$slug])) {
                $roles[$slug] = [
                    'label'       => $role_name,
                    'is_system'   => false,
                    'description' => $role_desc,
                ];
                update_option('dfn_custom_roles', $roles);
                
                // Inizializza matrice
                $matrix = dfn_get_stored_roles_matrix();
                $matrix[$slug] = [];
                update_option('dfn_roles_matrix', $matrix);

                dfn_sync_wp_roles();
                wp_safe_redirect(add_query_arg(['page' => 'dfn-roles-manage', 'role_created' => '1'], admin_url('admin.php')));
                exit;
            }
        }
    }

    // 3. Eliminazione Ruolo Custom
    if (isset($_POST['dfn_delete_role_nonce']) && wp_verify_nonce($_POST['dfn_delete_role_nonce'], 'dfn_delete_role_action')) {
        $role_to_delete = sanitize_key($_POST['role_slug'] ?? '');
        $roles = dfn_get_stored_roles();

        if (isset($roles[$role_to_delete]) && empty($roles[$role_to_delete]['is_system'])) {
            unset($roles[$role_to_delete]);
            update_option('dfn_custom_roles', $roles);

            $matrix = dfn_get_stored_roles_matrix();
            unset($matrix[$role_to_delete]);
            update_option('dfn_roles_matrix', $matrix);

            remove_role($role_to_delete);
            dfn_sync_wp_roles();

            wp_safe_redirect(add_query_arg(['page' => 'dfn-roles-manage', 'role_deleted' => '1'], admin_url('admin.php')));
            exit;
        }
    }
}

/**
 * Renderizza la schermata principale del pannello FAI Ruoli e Permessi.
 */
function dfn_roles_render_admin_page(): void
{
    $current_page = sanitize_key($_GET['page'] ?? 'dfn-roles');
    $modules = dfn_get_modules_registry();
    $roles = dfn_get_stored_roles();
    $matrix = dfn_get_stored_roles_matrix();
    $catalog = dfn_get_activities_catalog();

    $active_tab = 'prenotazioni';
    if ($current_page === 'dfn-roles-convenzioni') {
        $active_tab = 'convenzioni';
    } elseif ($current_page === 'dfn-roles-manage') {
        $active_tab = 'manage';
    }
    ?>
    <div class="wrap dfn-roles-admin-wrap" style="max-width: 1300px; margin-top: 20px;">
        
        <!-- HEADER HEADER -->
        <div style="background: linear-gradient(135deg, #004b23 0%, #002e15 100%); color: #fff; padding: 24px 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="background: rgba(255,255,255,0.15); width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    🛡️
                </div>
                <div>
                    <h1 style="color: #fff; margin: 0; font-size: 24px; font-weight: 700; display: inline-block;">FAI — Ruoli &amp; Permessi</h1>
                    <p style="margin: 4px 0 0; color: #d1fae5; font-size: 14px;">Gestione unificata e modulare delle autorizzazioni per operatori, banchetto e volontari</p>
                </div>
            </div>
            <div>
                <a href="<?php echo esc_url(admin_url('users.php')); ?>" class="button button-secondary" style="background: #ffffff; color: #004b23; border: none; font-weight: 600; padding: 6px 14px; height: auto;">
                    👥 Vai agli Utenti WordPress
                </a>
            </div>
        </div>

        <?php if (isset($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color: #10b981; padding: 12px 16px; border-radius: 6px;">
                <p style="font-weight: 600; color: #065f46; margin: 0;">✅ Matrice dei permessi salvata e sincronizzata con successo con WordPress!</p>
            </div>
        <?php elseif (isset($_GET['role_created'])) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color: #10b981; padding: 12px 16px; border-radius: 6px;">
                <p style="font-weight: 600; color: #065f46; margin: 0;">🎉 Nuovo ruolo creato con successo!</p>
            </div>
        <?php elseif (isset($_GET['role_deleted'])) : ?>
            <div class="notice notice-warning is-dismissible" style="border-left-color: #f59e0b; padding: 12px 16px; border-radius: 6px;">
                <p style="font-weight: 600; color: #92400e; margin: 0;">🗑️ Ruolo eliminato correttamente.</p>
            </div>
        <?php endif; ?>

        <!-- NAVIGAZIONE TAB SOTTOMENU -->
        <nav class="nav-tab-wrapper" style="margin-bottom: 24px; border-bottom: 2px solid #004b23;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-roles')); ?>" class="nav-tab <?php echo $active_tab === 'prenotazioni' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'prenotazioni' ? 'background:#004b23; color:#fff; border-color:#004b23;' : 'font-weight:600;'; ?>">
                🎟️ FAI Prenotazioni
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-roles-convenzioni')); ?>" class="nav-tab <?php echo $active_tab === 'convenzioni' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'convenzioni' ? 'background:#004b23; color:#fff; border-color:#004b23;' : 'font-weight:600;'; ?>">
                🏷️ FAI Convenzioni <span style="font-size:11px; background:#dbeafe; color:#1e40af; padding:2px 6px; border-radius:10px; margin-left:4px;">In arrivo</span>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-roles-manage')); ?>" class="nav-tab <?php echo $active_tab === 'manage' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'manage' ? 'background:#004b23; color:#fff; border-color:#004b23;' : 'font-weight:600;'; ?>">
                👥 Gestione Ruoli &amp; Utenti
            </a>
        </nav>

        <?php if ($active_tab === 'prenotazioni' || $active_tab === 'convenzioni') : ?>
            
            <!-- SCHERMATA MATRICE PERMESSI MODULO -->
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 16px;">
                    <div>
                        <h2 style="margin: 0; color: #004b23; font-size: 18px; font-weight: 700;">
                            <?php echo esc_html($modules[$active_tab]['icon'] . ' ' . $modules[$active_tab]['label']); ?> — Matrice Attività
                        </h2>
                        <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">
                            <?php echo esc_html($modules[$active_tab]['description']); ?>
                        </p>
                    </div>
                    <?php if ($active_tab === 'convenzioni') : ?>
                        <span style="background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600;">
                            💡 Predisposizione Modulo: le funzionalità saranno collegate alla futura sezione Convenzioni.
                        </span>
                    <?php endif; ?>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field('dfn_save_roles_matrix_action', 'dfn_save_roles_matrix_nonce'); ?>
                    <input type="hidden" name="current_module" value="<?php echo esc_attr($active_tab); ?>" />

                    <div style="overflow-x: auto;">
                        <table class="wp-list-table widefat fixed striped" style="border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; border-collapse: separate; border-spacing: 0;">
                            <thead>
                                <tr style="background: #f8fafc;">
                                    <th style="padding: 14px 16px; font-size: 13px; font-weight: 700; color: #334155; width: 320px; border-bottom: 2px solid #cbd5e1;">
                                        Attività / Funzionalità
                                    </th>
                                    <?php foreach ($roles as $r_slug => $r_data) : ?>
                                        <th style="text-align: center; padding: 14px 10px; font-size: 13px; font-weight: 700; color: #004b23; border-bottom: 2px solid #cbd5e1; border-left: 1px solid #e2e8f0;">
                                            <div><?php echo esc_html($r_data['label']); ?></div>
                                            <?php if ($r_slug !== 'administrator') : ?>
                                                <div style="margin-top: 6px; font-size: 11px; font-weight: 400;">
                                                    <button type="button" class="button button-small dfn-toggle-col-all" data-role="<?php echo esc_attr($r_slug); ?>" style="padding: 0 4px; font-size: 10px; height: 20px; line-height: 18px;">Tutti</button>
                                                    <button type="button" class="button button-small dfn-toggle-col-none" data-role="<?php echo esc_attr($r_slug); ?>" style="padding: 0 4px; font-size: 10px; height: 20px; line-height: 18px;">Nessuno</button>
                                                </div>
                                            <?php else : ?>
                                                <span style="font-size: 10px; color: #10b981; font-weight: 600; text-transform: uppercase;">Completo (Bypass)</span>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $module_activities = $catalog[$active_tab] ?? [];
                                foreach ($module_activities as $act_key => $act_info) : 
                                ?>
                                    <tr>
                                        <td style="padding: 12px 16px; vertical-align: middle;">
                                            <div style="font-weight: 700; color: #1e293b; font-size: 14px;">
                                                <span style="margin-right: 6px;"><?php echo esc_html($act_info['icon']); ?></span>
                                                <?php echo esc_html($act_info['label']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #64748b; margin-top: 2px; line-height: 1.4;">
                                                <?php echo esc_html($act_info['description']); ?>
                                            </div>
                                            <code style="font-size: 10px; color: #94a3b8; margin-top: 4px; display: inline-block;"><?php echo esc_html($act_key); ?></code>
                                        </td>
                                        
                                        <?php foreach ($roles as $r_slug => $r_data) : ?>
                                            <td style="text-align: center; vertical-align: middle; padding: 10px; border-left: 1px solid #f1f5f9;">
                                                <?php if ($r_slug === 'administrator') : ?>
                                                    <span style="color: #10b981; font-size: 18px;" title="Abilitato permanentemente">&#10004;</span>
                                                <?php else : 
                                                    $is_checked = ! empty($matrix[$r_slug][$act_key]);
                                                ?>
                                                    <input 
                                                        type="checkbox" 
                                                        name="caps[<?php echo esc_attr($r_slug); ?>][<?php echo esc_attr($act_key); ?>]" 
                                                        value="1" 
                                                        class="dfn-role-checkbox role-col-<?php echo esc_attr($r_slug); ?>"
                                                        <?php checked($is_checked, true); ?>
                                                        style="width: 20px; height: 20px; cursor: pointer; accent-color: #004b23;"
                                                    />
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="margin-top: 24px; display: flex; justify-content: flex-end;">
                        <button type="submit" class="button button-primary button-large" style="background: #004b23; border-color: #003318; font-weight: 700; padding: 6px 24px; height: auto; font-size: 14px;">
                            💾 Salva Matrice Permessi
                        </button>
                    </div>
                </form>

            </div>

        <?php elseif ($active_tab === 'manage') : ?>

            <!-- SCHERMATA GESTIONE RUOLI & UTENTI -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                
                <!-- Colonna 1: Elenco Ruoli Esistenti -->
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <h2 style="margin: 0 0 16px; color: #004b23; font-size: 18px; font-weight: 700;">
                        📋 Ruoli FAI Attivi
                    </h2>
                    
                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <?php foreach ($roles as $r_slug => $r_data) : 
                            $users_count = count(get_users(['role' => $r_slug]));
                        ?>
                            <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; background: <?php echo ! empty($r_data['is_system']) ? '#f8fafc' : '#ffffff'; ?>; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <div style="font-weight: 700; color: #1e293b; font-size: 15px;">
                                        <?php echo esc_html($r_data['label']); ?>
                                        <?php if (! empty($r_data['is_system'])) : ?>
                                            <span style="font-size: 11px; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 10px; margin-left: 6px; font-weight: 600;">Sistema</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                        <?php echo esc_html($r_data['description']); ?>
                                    </div>
                                    <div style="font-size: 11px; color: #0284c7; margin-top: 6px; font-weight: 600;">
                                        👤 <?php echo intval($users_count); ?> utenti assegnati &bull; <code><?php echo esc_html($r_slug); ?></code>
                                    </div>
                                </div>

                                <div>
                                    <?php if (empty($r_data['is_system'])) : ?>
                                        <form method="post" action="" onsubmit="return confirm('Sei sicuro di voler eliminare questo ruolo? Gli utenti con questo ruolo perderanno le autorizzazioni associate.');">
                                            <?php wp_nonce_field('dfn_delete_role_action', 'dfn_delete_role_nonce'); ?>
                                            <input type="hidden" name="role_slug" value="<?php echo esc_attr($r_slug); ?>" />
                                            <button type="submit" class="button button-link-delete" style="color: #ef4444; font-size: 12px;">
                                                🗑️ Elimina
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Colonna 2: Creazione Nuovo Ruolo -->
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <h2 style="margin: 0 0 16px; color: #004b23; font-size: 18px; font-weight: 700;">
                        ➕ Crea Nuovo Ruolo FAI
                    </h2>
                    <p style="color: #64748b; font-size: 13px; margin-bottom: 20px;">
                        Crea una nuova figura operativa per la delegazione (es. <em>Coordinatore Volontari</em>, <em>Cassa FAI</em>). Potrai poi assegnare le attività desiderate nella matrice permessi.
                    </p>

                    <form method="post" action="">
                        <?php wp_nonce_field('dfn_create_role_action', 'dfn_create_role_nonce'); ?>

                        <div style="margin-bottom: 16px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155; display: block; margin-bottom: 6px;">
                                Nome Ruolo *
                            </label>
                            <input type="text" name="new_role_name" required placeholder="Es. Referente Visite Guidate" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;" />
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155; display: block; margin-bottom: 6px;">
                                Identificativo Slug (opzionale)
                            </label>
                            <input type="text" name="new_role_slug" placeholder="Es. referente_visite (generato in automatico se vuoto)" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;" />
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155; display: block; margin-bottom: 6px;">
                                Descrizione o Note Operative
                            </label>
                            <textarea name="new_role_desc" rows="3" placeholder="Descrivi i compiti principali di questo ruolo..." style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;"></textarea>
                        </div>

                        <button type="submit" class="button button-primary" style="background: #004b23; border-color: #003318; font-weight: 700; padding: 6px 20px; height: auto;">
                            ✨ Crea e Aggiungi alla Matrice
                        </button>
                    </form>
                </div>

            </div>

        <?php endif; ?>

    </div>

    <!-- SCRIPT RAPIDO SELEZIONA TUTTI / NESSUNO COLONNA -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.dfn-toggle-col-all').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var role = this.dataset.role;
                document.querySelectorAll('.role-col-' + role).forEach(function(cb) {
                    cb.checked = true;
                });
            });
        });
        document.querySelectorAll('.dfn-toggle-col-none').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var role = this.dataset.role;
                document.querySelectorAll('.role-col-' + role).forEach(function(cb) {
                    cb.checked = false;
                });
            });
        });
    });
    </script>
    <?php
}

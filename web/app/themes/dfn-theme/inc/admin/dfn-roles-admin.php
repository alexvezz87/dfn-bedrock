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

    // Sottomenu 2: Volontari FAI
    add_submenu_page(
        'dfn-roles',
        __('Volontari FAI — Permessi', 'dfn-theme'),
        __('👥 Volontari FAI', 'dfn-theme'),
        $capability,
        'dfn-roles-volontari',
        'dfn_roles_render_admin_page'
    );

    // Sottomenu 3: Gestione Ruoli & Utenti
    add_submenu_page(
        'dfn-roles',
        __('Gestione Ruoli & Utenti', 'dfn-theme'),
        __('🛠️ Gestione Ruoli', 'dfn-theme'),
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

            foreach ($roles as $role_slug => $role_data) {
                if ($role_slug === 'administrator') {
                    continue; // Administrator sempre tutto abilitato
                }

                // Sincronizza solo per i ruoli che appartengono a questo modulo
                $r_modules = ! empty($role_data['modules']) && is_array($role_data['modules']) ? $role_data['modules'] : (array) ($role_data['module'] ?? []);
                if (! in_array($active_module, $r_modules, true) && ! in_array('all', $r_modules, true)) {
                    continue;
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
        $role_name    = sanitize_text_field($_POST['new_role_name'] ?? '');
        $role_modules = isset($_POST['new_role_modules']) && is_array($_POST['new_role_modules']) ? array_map('sanitize_key', $_POST['new_role_modules']) : [];
        $role_desc    = sanitize_text_field($_POST['new_role_desc'] ?? '');

        // Validazione materie ammesse
        $valid_modules = ['prenotazioni', 'volontari'];
        $role_modules  = array_values(array_intersect($role_modules, $valid_modules));

        if (! empty($role_name)) {
            $slug_raw = sanitize_title_with_dashes($_POST['new_role_slug'] ?? $role_name);
            $slug = 'dfn_' . str_replace('-', '_', $slug_raw);
            $slug = substr($slug, 0, 32);

            $roles = dfn_get_stored_roles();
            if (! isset($roles[$slug])) {
                $roles[$slug] = [
                    'label'       => $role_name,
                    'is_system'   => false,
                    'modules'     => $role_modules,
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

    // 2.1 Aggiornamento rapido materie di un ruolo esistente
    if (isset($_POST['dfn_update_role_modules_nonce']) && wp_verify_nonce($_POST['dfn_update_role_modules_nonce'], 'dfn_update_role_modules_action')) {
        $role_slug = sanitize_key($_POST['role_slug'] ?? '');
        $updated_modules = isset($_POST['role_modules']) && is_array($_POST['role_modules']) ? array_map('sanitize_key', $_POST['role_modules']) : [];
        $valid_modules = ['prenotazioni', 'volontari'];
        $updated_modules = array_values(array_intersect($updated_modules, $valid_modules));

        $roles = dfn_get_stored_roles();
        if (isset($roles[$role_slug]) && empty($roles[$role_slug]['is_system'])) {
            $roles[$role_slug]['modules'] = $updated_modules;
            update_option('dfn_custom_roles', $roles);
            dfn_sync_wp_roles();
            wp_safe_redirect(add_query_arg(['page' => 'dfn-roles-manage', 'role_updated' => '1'], admin_url('admin.php')));
            exit;
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
    if ($current_page === 'dfn-roles-volontari') {
        $active_tab = 'volontari';
    } elseif ($current_page === 'dfn-roles-manage') {
        $active_tab = 'manage';
    }
    ?>
    <div class="wrap dfn-roles-admin-wrap" style="max-width: 1300px; margin-top: 20px;">
        
        <!-- HEADER -->
        <div style="background: linear-gradient(135deg, #004b23 0%, #002e15 100%); color: #fff; padding: 24px 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="background: rgba(255,255,255,0.15); width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    🛡️
                </div>
                <div>
                    <h1 style="color: #fff; margin: 0; font-size: 24px; font-weight: 700; display: inline-block;">FAI — Ruoli &amp; Permessi</h1>
                    <p style="margin: 4px 0 0; color: #d1fae5; font-size: 14px;">Gestione modulare e flessibile delle autorizzazioni per operatori, coordinatori e volontari</p>
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
        <?php elseif (isset($_GET['role_updated'])) : ?>
            <div class="notice notice-success is-dismissible" style="border-left-color: #10b981; padding: 12px 16px; border-radius: 6px;">
                <p style="font-weight: 600; color: #065f46; margin: 0;">💾 Materie del ruolo aggiornate con successo!</p>
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
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-roles-volontari')); ?>" class="nav-tab <?php echo $active_tab === 'volontari' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'volontari' ? 'background:#004b23; color:#fff; border-color:#004b23;' : 'font-weight:600;'; ?>">
                👥 Volontari FAI
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-roles-manage')); ?>" class="nav-tab <?php echo $active_tab === 'manage' ? 'nav-tab-active' : ''; ?>" style="<?php echo $active_tab === 'manage' ? 'background:#004b23; color:#fff; border-color:#004b23;' : 'font-weight:600;'; ?>">
                🛠️ Gestione Ruoli &amp; Utenti
            </a>
        </nav>

        <?php if ($active_tab === 'prenotazioni' || $active_tab === 'volontari') : 
            // Filtriamo i ruoli pertinenti a questa materia
            $module_roles = [];
            foreach ($roles as $r_slug => $r_data) {
                $r_modules = ! empty($r_data['modules']) && is_array($r_data['modules']) ? $r_data['modules'] : (array) ($r_data['module'] ?? []);
                if ($r_slug === 'administrator' || in_array($active_tab, $r_modules, true) || in_array('all', $r_modules, true)) {
                    $module_roles[$r_slug] = $r_data;
                }
            }
        ?>
            
            <!-- SCHERMATA MATRICE PERMESSI MODULO -->
            <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9; padding-bottom: 16px;">
                    <div>
                        <h2 style="margin: 0; color: #004b23; font-size: 18px; font-weight: 700;">
                            <?php echo esc_html($modules[$active_tab]['icon'] . ' ' . $modules[$active_tab]['label']); ?> — Matrice Attività &amp; Permessi
                        </h2>
                        <p style="margin: 4px 0 0; color: #64748b; font-size: 13px;">
                            <?php echo esc_html($modules[$active_tab]['description']); ?>
                        </p>
                    </div>
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
                                    <?php foreach ($module_roles as $r_slug => $r_data) : ?>
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
                                        
                                        <?php foreach ($module_roles as $r_slug => $r_data) : ?>
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
                    
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <?php foreach ($roles as $r_slug => $r_data) : 
                            $users_count = count(get_users(['role' => $r_slug]));
                            $r_modules = ! empty($r_data['modules']) && is_array($r_data['modules']) ? $r_data['modules'] : (array) ($r_data['module'] ?? []);
                        ?>
                            <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; background: <?php echo ! empty($r_data['is_system']) ? '#f8fafc' : '#ffffff'; ?>; display: flex; flex-direction: column; gap: 10px;">
                                <div>
                                    <div style="font-weight: 700; color: #1e293b; font-size: 15px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                                        <?php echo esc_html($r_data['label']); ?>
                                        
                                        <?php if ($r_slug === 'administrator') : ?>
                                            <span style="font-size: 11px; background: #e2e8f0; color: #475569; padding: 2px 8px; border-radius: 10px; font-weight: 600;">Globale</span>
                                        <?php else : ?>
                                            <?php if (in_array('volontari', $r_modules, true)) : ?>
                                                <span style="font-size: 11px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 10px; font-weight: 600;">👥 Volontari FAI</span>
                                            <?php endif; ?>
                                            <?php if (in_array('prenotazioni', $r_modules, true)) : ?>
                                                <span style="font-size: 11px; background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 10px; font-weight: 600;">🎟️ FAI Prenotazioni</span>
                                            <?php endif; ?>
                                            <?php if (empty($r_modules)) : ?>
                                                <span style="font-size: 11px; background: #f8fafc; color: #64748b; border: 1px dashed #cbd5e1; padding: 2px 8px; border-radius: 10px; font-weight: 600;">Nessuna Materia</span>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (! empty($r_data['is_system'])) : ?>
                                            <span style="font-size: 11px; background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 10px; font-weight: 600;">Sistema</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                        <?php echo esc_html($r_data['description']); ?>
                                    </div>
                                    <div style="font-size: 11px; color: #0284c7; margin-top: 6px; font-weight: 600;">
                                        👤 <?php echo intval($users_count); ?> utenti assegnati &bull; <code><?php echo esc_html($r_slug); ?></code>
                                    </div>
                                </div>

                                <!-- Modifica rapida materie e pulsanti azione affiancati -->
                                <?php if (empty($r_data['is_system'])) : ?>
                                    <div style="padding-top: 10px; border-top: 1px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                        <form method="post" action="" id="form-update-<?php echo esc_attr($r_slug); ?>" style="margin: 0; display: flex; align-items: center; gap: 12px; font-size: 12px;">
                                            <?php wp_nonce_field('dfn_update_role_modules_action', 'dfn_update_role_modules_nonce'); ?>
                                            <input type="hidden" name="role_slug" value="<?php echo esc_attr($r_slug); ?>" />
                                            <span style="color: #475569; font-weight: 600;">Materie:</span>
                                            <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                                <input type="checkbox" name="role_modules[]" value="volontari" <?php checked(in_array('volontari', $r_modules, true), true); ?> style="margin: 0;" />
                                                👥 Volontari
                                            </label>
                                            <label style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                                <input type="checkbox" name="role_modules[]" value="prenotazioni" <?php checked(in_array('prenotazioni', $r_modules, true), true); ?> style="margin: 0;" />
                                                🎟️ Prenotazioni
                                            </label>
                                        </form>

                                        <!-- Pulsanti Icone Affiancati (Matita & Cestino) -->
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <button type="submit" form="form-update-<?php echo esc_attr($r_slug); ?>" title="Aggiorna materie abilitate per questo ruolo" class="button" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0; border-radius: 6px; border-color: #cbd5e1; background: #ffffff; cursor: pointer;">
                                                ✏️
                                            </button>

                                            <form method="post" action="" onsubmit="return confirm('Sei sicuro di voler eliminare questo ruolo? Gli utenti con questo ruolo perderanno le autorizzazioni associate.');" style="margin: 0;">
                                                <?php wp_nonce_field('dfn_delete_role_action', 'dfn_delete_role_nonce'); ?>
                                                <input type="hidden" name="role_slug" value="<?php echo esc_attr($r_slug); ?>" />
                                                <button type="submit" title="Elimina ruolo" class="button" style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0; border-radius: 6px; border-color: #fecaca; background: #fff5f5; color: #ef4444; cursor: pointer;">
                                                    🗑️
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
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
                        Crea una nuova figura operativa per la delegazione. Puoi associare una, più o nessuna materia (potrai assegnarle o modificarle in qualsiasi momento).
                    </p>

                    <form method="post" action="">
                        <?php wp_nonce_field('dfn_create_role_action', 'dfn_create_role_nonce'); ?>

                        <div style="margin-bottom: 16px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155; display: block; margin-bottom: 6px;">
                                Nome Ruolo *
                            </label>
                            <input type="text" name="new_role_name" required placeholder="Es. Delegato Scuole" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;" />
                        </div>

                        <div style="margin-bottom: 18px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155; display: block; margin-bottom: 8px;">
                                Materie / Moduli di Competenza (Opzionale - Selezione Multipla)
                            </label>
                            
                            <div style="display: grid; grid-template-columns: 1fr; gap: 10px;">
                                <label style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border: 1.5px solid #bbf7d0; background: #f0fdf4; border-radius: 8px; cursor: pointer;">
                                    <input type="checkbox" name="new_role_modules[]" value="volontari" checked style="margin-top: 3px; accent-color: #004b23; width: 16px; height: 16px;" />
                                    <div>
                                        <div style="font-size: 13.5px; font-weight: 700; color: #166534;">
                                            👥 Volontari FAI
                                        </div>
                                        <div style="font-size: 12px; color: #475569; margin-top: 2px;">
                                            Turni, Logistica, Anagrafica, Riunioni di Delegazione, Scuole
                                        </div>
                                    </div>
                                </label>

                                <label style="display: flex; align-items: flex-start; gap: 10px; padding: 10px 14px; border: 1.5px solid #bfdbfe; background: #eff6ff; border-radius: 8px; cursor: pointer;">
                                    <input type="checkbox" name="new_role_modules[]" value="prenotazioni" style="margin-top: 3px; accent-color: #004b23; width: 16px; height: 16px;" />
                                    <div>
                                        <div style="font-size: 13.5px; font-weight: 700; color: #1e40af;">
                                            🎟️ FAI Prenotazioni
                                        </div>
                                        <div style="font-size: 12px; color: #475569; margin-top: 2px;">
                                            Eventi, Biglietti, Botteghino Live, Scanner QR, Soci
                                        </div>
                                    </div>
                                </label>
                            </div>
                            <span style="font-size: 11.5px; color: #64748b; margin-top: 6px; display: block;">
                                Puoi selezionare una o più materie, oppure lasciarle deselezionate per associarle in seguito.
                            </span>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="font-weight: 700; font-size: 13px; color: #334155; display: block; margin-bottom: 6px;">
                                Identificativo Slug (opzionale)
                            </label>
                            <input type="text" name="new_role_slug" placeholder="Es. delegato_scuole (generato in automatico se vuoto)" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px;" />
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

<?php
/**
 * DFN Booking System 2.0 — Modulo Gestione Volontari FAI
 *
 * Gestisce il pannello amministrativo di primo livello per l'anagrafica
 * dei volontari, l'assegnazione delle tessere, i ruoli operativi
 * e il calendario delle riunioni di delegazione.
 *
 * @package DFN_Theme
 * @since   2.3.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_volunteers_register_admin_menu', 25);

/**
 * Registra il menu top-level "Gestione Volontari FAI" e i relativi sottomenu.
 */
function dfn_volunteers_register_admin_menu(): void
{
    // Menu Top-Level allo stesso livello di FAI Prenotazioni
    add_menu_page(
        __('Gestione Volontari FAI', 'dfn-theme'),
        __('Volontari FAI', 'dfn-theme'),
        'dfn_act_fai_members', // Permesso condiviso con la gestione soci/delegazione
        'dfn-volunteers',
        'dfn_render_volunteers_list_page',
        'dashicons-groups',
        57
    );

    // Sottomenu: Elenco Volontari
    add_submenu_page(
        'dfn-volunteers',
        __('Elenco Volontari', 'dfn-theme'),
        __('Elenco Volontari', 'dfn-theme'),
        'dfn_act_fai_members',
        'dfn-volunteers',
        'dfn_render_volunteers_list_page'
    );

    // Sottomenu: Aggiungi Volontario
    add_submenu_page(
        'dfn-volunteers',
        __('Aggiungi Volontario', 'dfn-theme'),
        __('Aggiungi Volontario', 'dfn-theme'),
        'dfn_act_fai_members',
        'dfn-volunteer-add',
        'dfn_render_volunteer_add_page'
    );

    // Sottomenu: Riunioni di Delegazione
    add_submenu_page(
        'dfn-volunteers',
        __('Riunioni di Delegazione', 'dfn-theme'),
        __('Riunioni Delegazione', 'dfn-theme'),
        'dfn_act_fai_members',
        'dfn-volunteer-meetings',
        'dfn_render_volunteer_meetings_admin_page'
    );

    // Sottomenu: Turni & Logistica Eventi (Giornate FAI e Locali)
    add_submenu_page(
        'dfn-volunteers',
        __('Turni & Logistica Eventi', 'dfn-theme'),
        __('Turni & Logistica', 'dfn-theme'),
        'dfn_act_fai_members',
        'dfn-volunteer-logistics',
        'dfn_render_volunteer_logistics_page'
    );
}

/**
 * Renderizza la schermata principale "Elenco Volontari".
 */
function dfn_render_volunteers_list_page(): void
{
    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_die(__('Permessi insufficienti per accedere a questa sezione.', 'dfn-theme'));
    }

    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    // Gestione Azioni (Elimina / Cambia stato volontario)
    if (isset($_GET['action'], $_GET['volunteer_id'], $_GET['_wpnonce'])) {
        $action = sanitize_text_field($_GET['action']);
        $vol_id = (int) $_GET['volunteer_id'];

        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_vol_action_' . $vol_id)) {
            if ($action === 'delete') {
                // Rimuove lo status di volontario (mantenendo la tessera FAI se esistente) o elimina
                $wpdb->update($table_fai, ['is_volunteer' => 0], ['id' => $vol_id], ['%d'], ['%d']);
                echo '<div class="notice notice-success is-dismissible"><p>✅ Volontario rimosso dall\'elenco.</p></div>';
            } elseif ($action === 'toggle_status') {
                $current_status = $wpdb->get_var($wpdb->prepare("SELECT volunteer_status FROM {$table_fai} WHERE id = %d", $vol_id));
                $new_status = ($current_status === 'active') ? 'inactive' : 'active';
                $wpdb->update($table_fai, ['volunteer_status' => $new_status], ['id' => $vol_id], ['%s'], ['%d']);
                echo '<div class="notice notice-success is-dismissible"><p>✅ Stato volontario aggiornato.</p></div>';
            }
        }
    }

    $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $where = 'is_volunteer = 1';
    $params = [];

    if (! empty($search_query)) {
        $where .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR card_number LIKE %s)';
        $like = '%' . $wpdb->esc_like($search_query) . '%';
        $params = [$like, $like, $like, $like];
    }

    $sql = "SELECT * FROM {$table_fai} WHERE {$where} ORDER BY last_name ASC, first_name ASC";
    $volunteers = ! empty($params) ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

    $total_volunteers = count($volunteers);
    $active_volunteers = 0;
    foreach ($volunteers as $v) {
        if ($v->volunteer_status === 'active') {
            $active_volunteers++;
        }
    }

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 24px;">
            <div class="dfn-logo-area" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                <div>
                    <span class="dashicons dashicons-groups" style="font-size:32px; width:32px; height:32px; color:#004b23; vertical-align:middle;"></span>
                    <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:0 0 0 8px; display:inline-block; vertical-align:middle;">
                        Gestione Volontari FAI
                    </h1>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-add')); ?>" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:600; padding:4px 14px;">
                    <span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom; margin-right:4px;"></span> Aggiungi Volontario
                </a>
            </div>
        </header>

        <!-- KPI STATISTICHE -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; border-top:3px solid #3b82f6; padding:16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <div style="width:40px; height:40px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#2563eb;">
                    <span class="dashicons dashicons-groups" style="font-size:22px;"></span>
                </div>
                <div>
                    <span style="font-size:22px; font-weight:700; color:#0f172a; display:block;"><?php echo intval($total_volunteers); ?></span>
                    <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Totale Volontari</span>
                </div>
            </div>
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; border-top:3px solid #004b23; padding:16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <div style="width:40px; height:40px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; color:#004b23;">
                    <span class="dashicons dashicons-yes-alt" style="font-size:22px;"></span>
                </div>
                <div>
                    <span style="font-size:22px; font-weight:700; color:#004b23; display:block;"><?php echo intval($active_volunteers); ?></span>
                    <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Volontari Attivi</span>
                </div>
            </div>
        </div>

        <!-- BARRA DI RICERCA -->
        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:14px 18px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:8px; width:100%; max-width:400px;">
                <input type="hidden" name="page" value="dfn-volunteers">
                <input type="text" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Cerca per nome, cognome, email o tessera…" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 10px;">
                <button type="submit" class="button button-secondary">Cerca</button>
                <?php if (! empty($search_query)) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" class="button">Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- TABELLA VOLONTARI -->
        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <table class="wp-list-table widefat fixed striped table-view-list" style="border:none;">
                <thead>
                    <tr>
                        <th style="width:180px; font-weight:700;">Volontario</th>
                        <th style="width:130px; font-weight:700;">Tessera FAI</th>
                        <th style="width:200px; font-weight:700;">Contatti</th>
                        <th style="font-weight:700;">Ruoli Operativi / Competenze</th>
                        <th style="width:90px; font-weight:700; text-align:center;">Stato</th>
                        <th style="width:230px; font-weight:700; text-align:right;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($volunteers)) : ?>
                        <?php foreach ($volunteers as $v) : 
                            $user = $v->user_id ? get_userdata($v->user_id) : null;
                            $roles_label = '—';
                            if ($user) {
                                $roles_label = function_exists('dfn_log_get_user_roles_label') ? dfn_log_get_user_roles_label($user) : implode(', ', (array) $user->roles);
                            }
                        ?>
                            <tr>
                                <td>
                                    <strong style="color:#0f172a; font-size:13.5px; display:block; white-space:nowrap;">
                                        <?php echo esc_html($v->first_name . ' ' . $v->last_name); ?>
                                    </strong>
                                    <?php if ($user) : ?>
                                        <span style="font-size:11.5px; color:#64748b;">Utente: <code><?php echo esc_html($user->user_login); ?></code></span>
                                    <?php else : ?>
                                        <span style="font-size:11.5px; color:#94a3b8; font-style:italic;">(Nessun account WP)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="background:#f1f5f9; padding:3px 6px; border-radius:4px; border:1px solid #e2e8f0; font-weight:600; color:#334155; white-space:nowrap;">
                                        💳 <?php echo esc_html($v->card_number); ?>
                                    </code>
                                    <?php if ($v->card_expiry) : ?>
                                        <div style="font-size:11px; color:#64748b; margin-top:2px; white-space:nowrap;">Scad: <?php echo esc_html(date_i18n('d/m/Y', strtotime($v->card_expiry))); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:12px; color:#334155; word-break:break-all;">✉️ <?php echo esc_html($v->email); ?></div>
                                    <?php if ($v->phone) : ?>
                                        <div style="font-size:11.5px; color:#64748b; margin-top:2px; white-space:nowrap;">📞 <?php echo esc_html($v->phone); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:12px; color:#334155;"><?php echo esc_html($roles_label); ?></span>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px;">
                                        <?php if (! empty($v->is_guide)) : ?>
                                            <span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:10px; font-size:10.5px; font-weight:700; padding:1px 7px; white-space:nowrap;">
                                                🏛️ Guida
                                            </span>
                                        <?php endif; ?>
                                        <?php if (! empty($v->has_safety_course)) : ?>
                                            <span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; border-radius:10px; font-size:10.5px; font-weight:700; padding:1px 7px; white-space:nowrap;">
                                                🦺 Sicurezza
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($v->volunteer_notes) : ?>
                                        <div style="font-size:11.5px; color:#64748b; font-style:italic; margin-top:3px;">📝 <?php echo esc_html($v->volunteer_notes); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center; vertical-align:middle;">
                                    <?php if ($v->volunteer_status === 'active') : ?>
                                        <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700; background:#dcfce7; color:#15803d; border:1px solid #86efac; white-space:nowrap;">
                                            Attivo
                                        </span>
                                    <?php else : ?>
                                        <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700; background:#f1f5f9; color:#64748b; border:1px solid #cbd5e1; white-space:nowrap;">
                                            Inattivo
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; vertical-align:middle;">
                                    <div style="display:flex; justify-content:flex-end; align-items:center; gap:6px; flex-wrap:nowrap;">
                                        <?php 
                                        $edit_url   = admin_url('admin.php?page=dfn-volunteer-add&volunteer_id=' . $v->id);
                                        $toggle_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteers&action=toggle_status&volunteer_id=' . $v->id), 'dfn_vol_action_' . $v->id);
                                        $delete_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteers&action=delete&volunteer_id=' . $v->id), 'dfn_vol_action_' . $v->id);
                                        ?>
                                        <a href="<?php echo esc_url($edit_url); ?>" class="button button-small" title="Modifica dati e ruoli" style="white-space:nowrap; padding:0 8px;">
                                            ✏️ Modifica
                                        </a>
                                        <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small" title="Attiva/Disattiva" style="white-space:nowrap; padding:0 8px;">
                                            <?php echo ($v->volunteer_status === 'active') ? 'Disattiva' : 'Attiva'; ?>
                                        </a>
                                        <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" style="color:#b91c1c; white-space:nowrap; padding:0 8px;" onclick="return confirm('Confermi la rimozione del volontario?');">
                                            Rimuovi
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" style="padding:30px; text-align:center; color:#64748b;">
                                Nessun volontario registrato. <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-add')); ?>">Aggiungi il primo volontario</a>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * Renderizza la schermata "Aggiungi Volontario".
 */
function dfn_render_volunteer_add_page(): void
{
    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_die(__('Permessi insufficienti.', 'dfn-theme'));
    }

    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    $vol_id = isset($_GET['volunteer_id']) ? (int) $_GET['volunteer_id'] : 0;
    $volunteer_data = $vol_id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d AND is_volunteer = 1", $vol_id)) : null;

    if (isset($_POST['dfn_save_volunteer']) && check_admin_referer('dfn_save_volunteer_nonce')) {
        $first_name   = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name    = sanitize_text_field($_POST['last_name'] ?? '');
        $email        = sanitize_email($_POST['email'] ?? '');
        $phone        = sanitize_text_field($_POST['phone'] ?? '');
        $card_number  = sanitize_text_field($_POST['card_number'] ?? '');
        $card_expiry  = ! empty($_POST['card_expiry']) ? sanitize_text_field($_POST['card_expiry']) : null;
        $card_type    = isset($_POST['card_type']) ? sanitize_text_field($_POST['card_type']) : 'INDIVIDUALE';
        $notes        = sanitize_textarea_field($_POST['notes'] ?? '');
        $user_id_raw  = (int) ($_POST['user_id'] ?? 0);
        $user_id      = $user_id_raw > 0 ? $user_id_raw : null;

        // Validazione tipo tessera configurato
        $types_string = function_exists('dfn_get_setting') ? dfn_get_setting('fai_member_types', 'INDIVIDUALE, COPPIA, FAMIGLIA') : 'INDIVIDUALE, COPPIA, FAMIGLIA';
        $valid_types = array_map('trim', array_map('strtoupper', explode(',', $types_string)));
        if (! in_array(strtoupper($card_type), $valid_types, true)) {
            $card_type = ! empty($valid_types[0]) ? $valid_types[0] : 'INDIVIDUALE';
        }

        // Se è stato specificato un user_id o l'email coincide con un utente WP esistente
        if (! $user_id && ! empty($email)) {
            $found_user = get_user_by('email', $email);
            if ($found_user) {
                $user_id = $found_user->ID;
            }
        }

        if (! empty($first_name) && ! empty($last_name) && ! empty($email) && ! empty($card_number)) {
            $is_guide          = isset($_POST['is_guide']) ? 1 : 0;
            $has_safety_course = isset($_POST['has_safety_course']) ? 1 : 0;

            if ($volunteer_data) {
                // Aggiornamento del volontario esistente
                $wpdb->update(
                    $table_fai,
                    [
                        'user_id'           => $user_id,
                        'first_name'        => $first_name,
                        'last_name'         => $last_name,
                        'email'             => $email,
                        'phone'             => $phone,
                        'card_number'       => $card_number,
                        'card_expiry'       => $card_expiry,
                        'card_type'         => $card_type,
                        'verified'          => 1,
                        'is_volunteer'      => 1,
                        'volunteer_notes'   => $notes,
                        'is_guide'          => $is_guide,
                        'has_safety_course' => $has_safety_course,
                    ],
                    [ 'id' => $volunteer_data->id ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d' ],
                    [ '%d' ]
                );
                $saved_id = $volunteer_data->id;
            } else {
                // Inserisce o aggiorna direttamente per numero tessera
                $existing_fai = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table_fai} WHERE card_number = %s",
                    $card_number
                ));

                if ($existing_fai) {
                    $wpdb->update(
                        $table_fai,
                        [
                            'user_id'           => $user_id ?: $existing_fai->user_id,
                            'first_name'        => $first_name,
                            'last_name'         => $last_name,
                            'email'             => $email,
                            'phone'             => ! empty($phone) ? $phone : $existing_fai->phone,
                            'card_expiry'       => ! empty($card_expiry) ? $card_expiry : $existing_fai->card_expiry,
                            'card_type'         => $card_type,
                            'verified'          => 1,
                            'verified_at'       => current_time('mysql'),
                            'verified_by'       => get_current_user_id(),
                            'is_volunteer'      => 1,
                            'volunteer_status'  => 'active',
                            'volunteer_notes'   => $notes,
                            'joined_date'       => current_time('Y-m-d'),
                            'is_guide'          => $is_guide,
                            'has_safety_course' => $has_safety_course,
                        ],
                        [ 'id' => $existing_fai->id ],
                        [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d' ],
                        [ '%d' ]
                    );
                    $saved_id = $existing_fai->id;
                } else {
                    $wpdb->insert(
                        $table_fai,
                        [
                            'first_name'        => $first_name,
                            'last_name'         => $last_name,
                            'email'             => $email,
                            'phone'             => $phone,
                            'card_number'       => $card_number,
                            'card_expiry'       => $card_expiry,
                            'card_type'         => $card_type,
                            'verified'          => 1,
                            'verified_at'       => current_time('mysql'),
                            'verified_by'       => get_current_user_id(),
                            'user_id'           => $user_id,
                            'is_volunteer'      => 1,
                            'volunteer_status'  => 'active',
                            'volunteer_notes'   => $notes,
                            'joined_date'       => current_time('Y-m-d'),
                            'is_guide'          => $is_guide,
                            'has_safety_course' => $has_safety_course,
                            'created_at'        => current_time('mysql'),
                        ],
                        [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
                    );
                    $saved_id = $wpdb->insert_id;
                }
            }

            // Log dell'azione
            if (function_exists('dfn_log_write')) {
                dfn_log_write(
                    'sistema',
                    wp_get_current_user()->display_name,
                    sprintf("Aggiunto/Aggiornato volontario FAI: %s %s (Tessera %s: %s)", $first_name, $last_name, $card_type, $card_number),
                    'success'
                );
            }

            echo '<div class="notice notice-success is-dismissible"><p>✅ Volontario e ruoli/competenze salvati con successo!</p></div>';
            $volunteer_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", $saved_id));
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>❌ Compila tutti i campi obbligatori (Nome, Cognome, Email, Numero Tessera FAI).</p></div>';
        }
    }

    // Lista utenti WP per associazione rapida opzionale
    $wp_users = get_users(['number' => 200, 'orderby' => 'display_name']);

    // Tipi di tessera configurati
    $types_string = function_exists('dfn_get_setting') ? dfn_get_setting('fai_member_types', 'INDIVIDUALE, COPPIA, FAMIGLIA') : 'INDIVIDUALE, COPPIA, FAMIGLIA';
    $types_list = array_map('trim', explode(',', $types_string));

    $is_edit = (bool) $volunteer_data;

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 24px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" style="text-decoration:none; color:#004b23; font-weight:700;">← Torna all'elenco volontari</a>
            <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:8px 0 0 0;">
                <?php echo $is_edit ? 'Modifica Volontario: ' . esc_html($volunteer_data->first_name . ' ' . $volunteer_data->last_name) : 'Aggiungi Nuovo Volontario'; ?>
            </h1>
        </header>

        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:24px 28px; max-width:800px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <form method="post" action="">
                <?php wp_nonce_field('dfn_save_volunteer_nonce'); ?>

                <h3 style="font-size:15px; font-weight:700; color:#1d2327; margin-top:0; border-bottom:1px solid #f0f0f1; padding-bottom:8px;">
                    👤 Dati Anagrafici &amp; Contatti
                </h3>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Nome <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="first_name" required value="<?php echo esc_attr($is_edit ? $volunteer_data->first_name : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Cognome <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="last_name" required value="<?php echo esc_attr($is_edit ? $volunteer_data->last_name : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Associa ad Utente Registrato (Opzionale)</label>
                    <select name="user_id" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                        <option value="0">-- Cerca o seleziona un utente --</option>
                        <?php foreach ($wp_users as $u) : ?>
                            <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($is_edit ? (int) $volunteer_data->user_id : 0, $u->ID); ?>>
                                <?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="font-size:11.5px; color:#64748b; margin:4px 0 0 0;">Puoi associare la tessera ad un account del sito. Comparirà nella sua area riservata con le prossime riunioni e turni.</p>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Email <span style="color:#ef4444;">*</span></label>
                        <input type="email" name="email" required value="<?php echo esc_attr($is_edit ? $volunteer_data->email : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Telefono</label>
                        <input type="text" name="phone" value="<?php echo esc_attr($is_edit ? ($volunteer_data->phone ?: '') : ''); ?>" placeholder="+39 333 1234567" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                </div>

                <h3 style="font-size:15px; font-weight:700; color:#1d2327; border-bottom:1px solid #f0f0f1; padding-bottom:8px;">
                    💳 Dettagli Tessera FAI Obbligatoria &amp; Delegazione
                </h3>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Numero Tessera <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="card_number" required value="<?php echo esc_attr($is_edit ? $volunteer_data->card_number : ''); ?>" placeholder="Es. 12345678" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Scadenza Tessera</label>
                        <input type="date" name="card_expiry" value="<?php echo esc_attr($is_edit && $volunteer_data->card_expiry ? $volunteer_data->card_expiry : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Tipologia Tessera</label>
                    <select name="card_type" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                        <?php foreach ($types_list as $t) : ?>
                            <option value="<?php echo esc_attr($t); ?>" <?php selected($is_edit ? $volunteer_data->card_type : '', $t); ?>>
                                <?php echo esc_html($t); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <h3 style="font-size:15px; font-weight:700; color:#1d2327; border-bottom:1px solid #f0f0f1; padding-bottom:8px; margin-top:20px;">
                    🎯 Competenze &amp; Ruoli Speciali per i Turni
                </h3>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px; display:flex; flex-direction:column; gap:12px;">
                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="is_guide" value="1" <?php checked($is_edit && ! empty($volunteer_data->is_guide), true); ?> style="width:18px; height:18px; margin-top:2px;">
                        <div>
                            <strong style="font-size:13px; color:#0f172a; display:block;">🏛️ Volontario Guida</strong>
                            <span style="font-size:12px; color:#64748b;">Abilita e suggerisce automaticamente questo volontario quando si assegna la mansione di Guida durante gli eventi e le visite.</span>
                        </div>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="has_safety_course" value="1" <?php checked($is_edit && ! empty($volunteer_data->has_safety_course), true); ?> style="width:18px; height:18px; margin-top:2px;">
                        <div>
                            <strong style="font-size:13px; color:#0f172a; display:block;">🦺 Corso sulla Sicurezza Attivo</strong>
                            <span style="font-size:12px; color:#64748b;">Requisito obbligatorio per ricoprire il ruolo di <strong>Responsabile Scuola</strong> (con gli Apprendisti Ciceroni) nelle Giornate FAI.</span>
                        </div>
                    </label>
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Note Delegazione / Disponibilità</label>
                    <textarea name="notes" rows="3" placeholder="Es. Disponibile per visite guidate nei weekend, accoglienza banchetto..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px;"><?php echo esc_textarea($is_edit ? ($volunteer_data->volunteer_notes ?: '') : ''); ?></textarea>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f0f0f1; padding-top:16px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" class="button">Annulla</a>
                    <button type="submit" name="dfn_save_volunteer" class="button button-primary" style="background:#004b23; border-color:#003b1c; padding:4px 18px; font-weight:700;">
                        <?php echo $is_edit ? '💾 Salva Modifiche Volontario' : '➕ Aggiungi Volontario'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Renderizza la schermata "Riunioni di Delegazione".
 */
function dfn_render_volunteer_meetings_admin_page(): void
{
    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_die(__('Permessi insufficienti.', 'dfn-theme'));
    }

    global $wpdb;
    $table_meetings = $wpdb->prefix . 'dfn_volunteer_meetings';

    // Salvataggio nuova riunione
    if (isset($_POST['dfn_save_meeting']) && check_admin_referer('dfn_save_meeting_nonce')) {
        $title         = sanitize_text_field($_POST['title'] ?? '');
        $meeting_date  = sanitize_text_field($_POST['meeting_date'] ?? '');
        $time_start    = sanitize_text_field($_POST['meeting_time_start'] ?? '');
        $time_end      = ! empty($_POST['meeting_time_end']) ? sanitize_text_field($_POST['meeting_time_end']) : null;
        $location      = sanitize_text_field($_POST['location'] ?? '');
        $meeting_link  = esc_url_raw($_POST['meeting_link'] ?? '');
        $agenda        = sanitize_textarea_field($_POST['agenda'] ?? '');

        if (! empty($title) && ! empty($meeting_date) && ! empty($time_start) && ! empty($location)) {
            $wpdb->insert(
                $table_meetings,
                [
                    'title'              => $title,
                    'meeting_date'       => $meeting_date,
                    'meeting_time_start' => $time_start,
                    'meeting_time_end'   => $time_end,
                    'location'           => $location,
                    'meeting_link'       => $meeting_link,
                    'agenda'             => $agenda,
                    'status'             => 'scheduled',
                    'created_by'         => get_current_user_id(),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
            );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Nuova riunione programmata con successo!</p></div>';
        }
    }

    // Cancellazione riunione
    if (isset($_GET['action'], $_GET['meeting_id'], $_GET['_wpnonce']) && $_GET['action'] === 'delete') {
        $m_id = (int) $_GET['meeting_id'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_meeting_action_' . $m_id)) {
            $wpdb->delete($table_meetings, ['id' => $m_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Riunione eliminata.</p></div>';
        }
    }

    $meetings = $wpdb->get_results("SELECT * FROM {$table_meetings} ORDER BY meeting_date ASC, meeting_time_start ASC");

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 24px;">
            <span class="dashicons dashicons-calendar-alt" style="font-size:32px; width:32px; height:32px; color:#004b23; vertical-align:middle;"></span>
            <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:0 0 0 8px; display:inline-block; vertical-align:middle;">
                Riunioni di Delegazione Volontari
            </h1>
        </header>

        <div style="display:grid; grid-template-columns:1fr 1.5fr; gap:24px; align-items:start;">
            <!-- FORM NUOVA RIUNIONE -->
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:20px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <h3 style="font-size:15px; font-weight:700; color:#1d2327; margin-top:0; border-bottom:1px solid #f0f0f1; padding-bottom:8px;">
                    ➕ Programma Nuova Riunione
                </h3>
                <form method="post" action="">
                    <?php wp_nonce_field('dfn_save_meeting_nonce'); ?>
                    
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Titolo / Oggetto Riunione <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="title" required placeholder="Es. Pianificazione Giornate FAI d'Autunno" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Data <span style="color:#ef4444;">*</span></label>
                            <input type="date" name="meeting_date" required style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Ora Inizio <span style="color:#ef4444;">*</span></label>
                            <input type="time" name="meeting_time_start" required style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                        </div>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Luogo / Sede <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="location" required placeholder="Es. Sede Delegazione, Salone dell'Arengo" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Link Online (Opzionale Zoom / Meet)</label>
                        <input type="url" name="meeting_link" placeholder="https://meet.google.com/..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Ordine del Giorno / Note</label>
                        <textarea name="agenda" rows="3" placeholder="Punti all'ordine del giorno..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px;"></textarea>
                    </div>

                    <button type="submit" name="dfn_save_meeting" class="button button-primary" style="width:100%; background:#004b23; border-color:#003b1c; font-weight:700; height:38px;">
                        Pubblica Riunione per i Volontari
                    </button>
                </form>
            </div>

            <!-- LISTA RIUNIONI PROGRAMMATE -->
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <div style="padding:14px 20px; border-bottom:1px solid #f0f0f1; background:#f8fafc;">
                    <h3 style="margin:0; font-size:15px; font-weight:700; color:#1d2327;">📅 Calendario Riunioni Programmate</h3>
                </div>
                <table class="wp-list-table widefat fixed striped table-view-list" style="border:none;">
                    <thead>
                        <tr>
                            <th style="width:110px; font-weight:700;">Data &amp; Ora</th>
                            <th style="font-weight:700;">Riunione &amp; Ordine del Giorno</th>
                            <th style="width:140px; font-weight:700;">Luogo / Link</th>
                            <th style="width:80px; font-weight:700; text-align:right;">Azione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (! empty($meetings)) : ?>
                            <?php foreach ($meetings as $m) : 
                                $is_past = strtotime($m->meeting_date) < strtotime('today');
                            ?>
                                <tr <?php if ($is_past) echo 'style="opacity:0.6;"'; ?>>
                                    <td>
                                        <strong style="color:#0f172a; display:block;">
                                            <?php echo esc_html(date_i18n('d/m/Y', strtotime($m->meeting_date))); ?>
                                        </strong>
                                        <code style="font-size:11px; background:#f1f5f9; padding:2px 5px; border-radius:4px; border:1px solid #e2e8f0;">
                                            <?php echo esc_html(substr($m->meeting_time_start, 0, 5)); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <strong style="color:#0f172a; font-size:13px; display:block;"><?php echo esc_html($m->title); ?></strong>
                                        <?php if ($m->agenda) : ?>
                                            <div style="font-size:12px; color:#475569; margin-top:3px;"><?php echo nl2br(esc_html($m->agenda)); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size:12px; color:#334155;">📍 <?php echo esc_html($m->location); ?></div>
                                        <?php if ($m->meeting_link) : ?>
                                            <a href="<?php echo esc_url($m->meeting_link); ?>" target="_blank" style="font-size:11.5px; color:#2563eb; display:inline-block; margin-top:3px;">🔗 Partecipa Online</a>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php 
                                        $del_m_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-meetings&action=delete&meeting_id=' . $m->id), 'dfn_meeting_action_' . $m->id);
                                        ?>
                                        <a href="<?php echo esc_url($del_m_url); ?>" class="button button-small" style="color:#b91c1c;" onclick="return confirm('Eliminare questa riunione?');">
                                            Elimina
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="4" style="padding:25px; text-align:center; color:#64748b;">
                                    Nessuna riunione programmata. Compila il modulo a sinistra per pubblicarne una.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

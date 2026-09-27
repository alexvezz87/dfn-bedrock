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

// Caricamento asset CSS e JS tooltip per l'area amministrativa Volontari e Logistica
add_action('admin_enqueue_scripts', 'dfn_volunteers_admin_enqueue_tooltip_assets');
function dfn_volunteers_admin_enqueue_tooltip_assets(string $hook): void
{
    $vol_pages = [
        'toplevel_page_dfn-volunteers',
        'volontari-fai_page_dfn-volunteer-add',
        'volontari-fai_page_dfn-volunteer-meetings',
        'volontari-fai_page_dfn-volunteer-logistics',
        'volontari-fai_page_dfn-volunteer-roles',
    ];

    if (in_array($hook, $vol_pages, true) || (isset($_GET['page']) && strpos($_GET['page'], 'dfn-volunteer') !== false)) {
        wp_enqueue_style(
            'dfn-events-manager-css',
            get_stylesheet_directory_uri() . '/assets/css/dfn-events-manager.css',
            [],
            '2.4.0'
        );
    }
}

// Stampa JS tooltip nel footer per le pagine admin volontari
add_action('admin_footer', 'dfn_volunteers_admin_tooltip_script');
function dfn_volunteers_admin_tooltip_script(): void
{
    ?>
    <script>
    (function() {
        var activeModal = null;
        var triggerEl   = null;

        function openModal(modalId, trigger) {
            var overlay = document.getElementById('dfn-tooltip-overlay');
            var modal   = document.getElementById(modalId);
            if (!modal) {
                console.warn('[DFN Tooltip] Modal non trovato:', modalId);
                return;
            }

            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'dfn-tooltip-overlay';
                overlay.id = 'dfn-tooltip-overlay';
                document.body.appendChild(overlay);
            }

            if (activeModal) closeModal(false);

            activeModal = modal;
            triggerEl   = trigger || null;

            overlay.classList.add('dfn-tooltip-active');
            modal.classList.add('dfn-tooltip-active');
            document.body.style.overflow = 'hidden';

            var focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusable) {
                setTimeout(function() { focusable.focus(); }, 50);
            }
        }

        function closeModal(restoreFocus) {
            if (!activeModal) return;

            var overlay = document.getElementById('dfn-tooltip-overlay');
            if (overlay) {
                overlay.classList.remove('dfn-tooltip-active');
            }
            activeModal.classList.remove('dfn-tooltip-active');
            document.body.style.overflow = '';

            if (restoreFocus !== false && triggerEl) {
                triggerEl.focus();
            }

            activeModal = null;
            triggerEl   = null;
        }

        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.dfn-tooltip-trigger');
            if (trigger) {
                e.preventDefault();
                e.stopPropagation();
                var modalId = trigger.getAttribute('data-tooltip');
                if (modalId) openModal(modalId, trigger);
                return;
            }

            var closeBtn = e.target.closest('.dfn-tooltip-modal-close');
            if (closeBtn) {
                e.preventDefault();
                closeModal(true);
                return;
            }

            var overlay = document.getElementById('dfn-tooltip-overlay');
            if (overlay && e.target === overlay) {
                e.preventDefault();
                closeModal(true);
            }
        });

        document.addEventListener('keydown', function(e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && activeModal) {
                closeModal(true);
            }
        });
    })();
    </script>
    <?php
}

if (! function_exists('dfn_tooltip_icon')) {
    /**
     * Stampa l'icona trigger di un tooltip modal.
     *
     * @param string $tooltip_id  ID del modal da aprire (senza #).
     * @param string $aria_label  Testo alternativo per accessibilità.
     */
    function dfn_tooltip_icon(string $tooltip_id, string $aria_label = ''): void {
        $label = $aria_label ?: __('Informazioni su questo elemento', 'dfn-theme');
        echo '<button type="button" class="dfn-tooltip-trigger" '
            . 'data-tooltip="' . esc_attr($tooltip_id) . '" '
            . 'aria-label="' . esc_attr($label) . '" '
            . 'title="' . esc_attr($label) . '" style="cursor:pointer; display:inline-flex; align-items:center; justify-content:center; width:20px; height:20px; border-radius:50%; background:#e2e8f0; border:none; color:#475569; font-size:11px; font-weight:700; margin-left:6px; vertical-align:middle;">?</button>';
    }
}

/**
 * Registra il menu top-level "Gestione Volontari FAI" e i relativi sottomenu.
 */
add_action('admin_menu', 'dfn_volunteers_register_admin_menu');
function dfn_volunteers_register_admin_menu(): void
{
    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    // Conteggio candidature in attesa di approvazione per il badge di notifica
    $pending_count = 0;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_fai}'") === $table_fai) {
        $pending_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_fai} WHERE volunteer_status = 'pending'");
    }

    $menu_badge = '';
    if ($pending_count > 0) {
        $menu_badge = sprintf(' <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>', $pending_count, $pending_count);
    }

    // Verifica se l'utente ha accesso al modulo Volontari FAI (o capability base)
    $has_vol_access = function_exists('dfn_user_has_module_access') && dfn_user_has_module_access('volontari');
    $cap_main = ($has_vol_access || current_user_can('manage_options') || current_user_can('dfn_act_fai_members')) ? 'read' : 'dfn_act_vol_roster';

    // Menu Top-Level allo stesso livello di FAI Prenotazioni
    add_menu_page(
        __('Gestione Volontari FAI', 'dfn-theme'),
        __('Volontari FAI', 'dfn-theme') . $menu_badge,
        $cap_main,
        'dfn-volunteers',
        'dfn_render_volunteers_list_page',
        'dashicons-groups',
        55.2
    );

    // Sottomenu: Elenco Volontari
    add_submenu_page(
        'dfn-volunteers',
        __('Elenco Volontari', 'dfn-theme'),
        __('Elenco Volontari', 'dfn-theme') . $menu_badge,
        $cap_main,
        'dfn-volunteers',
        'dfn_render_volunteers_list_page'
    );

    // Sottomenu: Aggiungi Volontario
    add_submenu_page(
        'dfn-volunteers',
        __('Aggiungi Volontario', 'dfn-theme'),
        __('Aggiungi Volontario', 'dfn-theme'),
        $cap_main,
        'dfn-volunteer-add',
        'dfn_render_volunteer_add_page'
    );

    // Sottomenu: Riunioni di Delegazione
    add_submenu_page(
        'dfn-volunteers',
        __('Riunioni di Delegazione', 'dfn-theme'),
        __('Riunioni Delegazione', 'dfn-theme'),
        $cap_main,
        'dfn-volunteer-meetings',
        'dfn_render_volunteer_meetings_admin_page'
    );

    // Sottomenu: Turni & Logistica Eventi (Giornate FAI e Locali)
    add_submenu_page(
        'dfn-volunteers',
        __('Turni & Logistica Eventi', 'dfn-theme'),
        __('Turni & Logistica', 'dfn-theme'),
        $cap_main,
        'dfn-volunteer-logistics',
        'dfn_render_volunteer_logistics_page'
    );

    // Sottomenu: Mansioni & Ruoli
    add_submenu_page(
        'dfn-volunteers',
        __('Mansioni & Ruoli', 'dfn-theme'),
        __('Mansioni & Ruoli', 'dfn-theme'),
        $cap_main,
        'dfn-volunteer-roles',
        'dfn_render_volunteer_roles_admin_page'
    );

    // Sottomenu: Log Volontari
    add_submenu_page(
        'dfn-volunteers',
        __('Log Volontari', 'dfn-theme'),
        __('Log Volontari', 'dfn-theme'),
        $cap_main,
        'dfn-volunteer-logs',
        'dfn_render_volunteer_logs_page'
    );
}

/**
 * Renderizza la schermata principale "Elenco Volontari".
 */
function dfn_render_volunteers_list_page(): void
{
    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_roster'))) {
        wp_die(__('Permessi insufficienti per accedere a questa sezione.', 'dfn-theme'));
    }

    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    // Auto-pulizia di sicurezza: i soci normali che non sono volontari hanno volunteer_status = 'none'
    $wpdb->query("UPDATE {$table_fai} SET volunteer_status = 'none' WHERE is_volunteer = 0 AND (volunteer_status = 'active' OR volunteer_status IS NULL OR volunteer_status = '')");

    // -------------------------------------------------------------------------
    // GESTIONE POST: APPROVAZIONE VOLONTARIO DA MODALE (Tessera FAI + SiVol)
    // -------------------------------------------------------------------------
    if (isset($_POST['dfn_action']) && $_POST['dfn_action'] === 'approve_volunteer_submit') {
        if (! isset($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'dfn_approve_vol_nonce')) {
            echo '<div class="notice notice-error is-dismissible"><p>❌ Errore di sicurezza / Sessione scaduta. Riprova.</p></div>';
        } else {
            $vol_id          = (int) ($_POST['volunteer_id'] ?? 0);
            $card_number     = sanitize_text_field($_POST['card_number'] ?? '');
            $fai_registry_id = sanitize_text_field($_POST['fai_registry_id'] ?? '');
            $card_expiry     = ! empty($_POST['card_expiry']) ? sanitize_text_field($_POST['card_expiry']) : null;
            $card_type       = sanitize_text_field($_POST['card_type'] ?? 'INDIVIDUALE');
            $is_sivol        = ! empty($_POST['is_sivol_registered']) ? 1 : 0;
            $is_guide        = ! empty($_POST['is_guide']) ? 1 : 0;
            $has_safety      = ! empty($_POST['has_safety_course']) ? 1 : 0;
            $vol_notes       = sanitize_textarea_field($_POST['volunteer_notes'] ?? '');
            $submitted_fai_roles = isset($_POST['fai_roles']) && is_array($_POST['fai_roles']) ? array_map('sanitize_key', $_POST['fai_roles']) : [];

            $vol = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", $vol_id));
            if ($vol) {
                // Aggiornamento anagrafica volontario approvato
                $wpdb->update(
                    $table_fai,
                    [
                        'card_number'         => $card_number,
                        'fai_registry_id'     => ! empty($fai_registry_id) ? $fai_registry_id : null,
                        'card_expiry'         => $card_expiry,
                        'card_type'           => $card_type,
                        'verified'            => ! empty($card_number) ? 1 : $vol->verified,
                        'verified_at'         => ! empty($card_number) ? current_time('mysql') : $vol->verified_at,
                        'verified_by'         => ! empty($card_number) ? get_current_user_id() : $vol->verified_by,
                        'is_sivol_registered' => $is_sivol,
                        'is_volunteer'        => 1,
                        'volunteer_status'    => 'active',
                        'joined_date'         => ! empty($vol->joined_date) ? $vol->joined_date : current_time('Y-m-d'),
                        'is_guide'            => $is_guide,
                        'has_safety_course'   => $has_safety,
                        'volunteer_notes'     => $vol_notes,
                    ],
                    ['id' => $vol_id],
                    ['%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s'],
                    ['%d']
                );

                // Assegnazione ruoli utente WordPress se collegato
                if ($vol->user_id) {
                    $user = get_userdata($vol->user_id);
                    if ($user) {
                        $user->add_role('dfn_volunteer');
                        $stored_roles = function_exists('dfn_get_stored_roles') ? dfn_get_stored_roles() : [];
                        $assigned_meta = (array) get_user_meta($vol->user_id, '_dfn_assigned_fai_roles', true);
                        if (! in_array('dfn_volunteer', $assigned_meta, true)) {
                            $assigned_meta[] = 'dfn_volunteer';
                        }
                        foreach ($submitted_fai_roles as $r_slug) {
                            if (isset($stored_roles[$r_slug])) {
                                $user->add_role($r_slug);
                                $assigned_meta[] = $r_slug;
                            }
                        }
                        update_user_meta($vol->user_id, '_dfn_assigned_fai_roles', array_unique($assigned_meta));
                    }
                }

                // Invio email di notifica al volontario approvato
                if (function_exists('dfn_send_volunteer_approved_email')) {
                    $updated_vol = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", $vol_id));
                    dfn_send_volunteer_approved_email($updated_vol ?: $vol, $vol->user_id ? (int) $vol->user_id : 0);
                }

                // Log attività
                if (function_exists('dfn_log_volunteer_roster')) {
                    $sivol_text = $is_sivol ? 'Registrato su SiVol: SÌ' : 'Registrato su SiVol: NO';
                    $card_text  = ! empty($card_number) ? "Tessera FAI: {$card_number}" : 'Tessera FAI non inserita';
                    dfn_log_volunteer_roster($vol_id, 'Candidatura approvata', "Stato: attivo | {$card_text} | {$sivol_text}");
                }
                if (function_exists('dfn_log_write')) {
                    dfn_log_write('volontari', wp_get_current_user()->display_name, sprintf("Approvata candidatura volontario: %s %s (#%d)", $vol->first_name, $vol->last_name, $vol_id), 'success');
                }

                echo '<div class="notice notice-success is-dismissible" style="border-left-color:#004b23;"><p>✅ <strong>Candidatura di ' . esc_html($vol->first_name . ' ' . $vol->last_name) . ' approvata con successo!</strong> Tessera FAI e flag SiVol salvati, ruolo Volontario FAI assegnato ed email di benvenuto inviata.</p></div>';
            }
        }
    }

    // -------------------------------------------------------------------------
    // GESTIONE AZIONI GET (Rifiuta, Disattiva/Attiva, Rimuovi)
    // -------------------------------------------------------------------------
    if (isset($_GET['action'], $_GET['volunteer_id'], $_GET['_wpnonce'])) {
        $action = sanitize_text_field($_GET['action']);
        $vol_id = (int) $_GET['volunteer_id'];

        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_vol_action_' . $vol_id)) {
            if ($action === 'reject_volunteer') {
                $vol = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", $vol_id));
                if ($vol) {
                    $wpdb->update($table_fai, [
                        'volunteer_status' => 'inactive',
                        'is_volunteer'     => 0,
                    ], ['id' => $vol_id], ['%s', '%d'], ['%d']);

                    if ($vol->user_id) {
                        $user = get_userdata($vol->user_id);
                        if ($user) {
                            $user->remove_role('dfn_volunteer');
                        }
                    }

                    if (function_exists('dfn_log_volunteer_roster')) {
                        dfn_log_volunteer_roster($vol_id, 'Candidatura rifiutata / archiviata', 'Stato impostato su inactive');
                    }

                    echo '<div class="notice notice-warning is-dismissible"><p>⚠️ <strong>Candidatura di ' . esc_html($vol->first_name . ' ' . $vol->last_name) . ' archiviata / non approvata.</strong></p></div>';
                }
            } elseif ($action === 'delete') {
                $wpdb->update($table_fai, ['is_volunteer' => 0, 'volunteer_status' => 'none'], ['id' => $vol_id], ['%d', '%s'], ['%d']);
                $vol_uid = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table_fai} WHERE id = %d", $vol_id));
                if ($vol_uid) {
                    $u = get_userdata($vol_uid);
                    if ($u) {
                        $u->remove_role('dfn_volunteer');
                    }
                }
                if (function_exists('dfn_log_volunteer_roster')) {
                    dfn_log_volunteer_roster($vol_id, 'Volontario rimosso dall\'elenco', 'Status is_volunteer azzerato');
                }
                echo '<div class="notice notice-success is-dismissible"><p>✅ Volontario rimosso dall\'elenco ufficiale.</p></div>';
            } elseif ($action === 'toggle_status') {
                $current_status = $wpdb->get_var($wpdb->prepare("SELECT volunteer_status FROM {$table_fai} WHERE id = %d", $vol_id));
                $new_status = ($current_status === 'active') ? 'inactive' : 'active';
                $wpdb->update($table_fai, ['volunteer_status' => $new_status, 'is_volunteer' => 1], ['id' => $vol_id], ['%s', '%d'], ['%d']);
                
                $vol_uid = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table_fai} WHERE id = %d", $vol_id));
                if ($vol_uid) {
                    $u = get_userdata($vol_uid);
                    if ($u) {
                        if ($new_status === 'active') {
                            $u->add_role('dfn_volunteer');
                        } else {
                            $u->remove_role('dfn_volunteer');
                        }
                    }
                }

                if (function_exists('dfn_log_volunteer_roster')) {
                    dfn_log_volunteer_roster($vol_id, 'Stato volontario modificato', "Nuovo stato: {$new_status}");
                }
                echo '<div class="notice notice-success is-dismissible"><p>✅ Stato volontario aggiornato a ' . esc_html($new_status) . '.</p></div>';
            }
        }
    }

    // -------------------------------------------------------------------------
    // QUERY DATI: CANDIDATURE IN ATTESA + VOLONTARI UFFICIALI
    // -------------------------------------------------------------------------
    
    // 1. Candidature in attesa di approvazione
    $pending_volunteers = $wpdb->get_results(
        "SELECT * FROM {$table_fai} WHERE volunteer_status = 'pending' ORDER BY id DESC"
    );
    $count_pending = count($pending_volunteers);

    // 2. Volontari ufficiali (Attivi e Inattivi)
    $count_active   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_fai} WHERE is_volunteer = 1 AND volunteer_status = 'active'");
    $count_inactive = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_fai} WHERE is_volunteer = 1 AND volunteer_status = 'inactive'");
    $count_official = $count_active + $count_inactive;

    $warning_days = intval(function_exists('dfn_get_setting') ? dfn_get_setting('fai_expiry_warning_days', 15) : 15);
    if ($warning_days < 15) {
        $warning_days = 15;
    }

    $count_expired  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_fai} WHERE is_volunteer = 1 AND volunteer_status IN ('active', 'inactive') AND card_expiry IS NOT NULL AND card_expiry != '' AND card_expiry != '0000-00-00' AND card_expiry < CURDATE()");
    $count_expiring = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_fai} WHERE is_volunteer = 1 AND volunteer_status IN ('active', 'inactive') AND card_expiry IS NOT NULL AND card_expiry != '' AND card_expiry != '0000-00-00' AND card_expiry >= CURDATE() AND card_expiry <= DATE_ADD(CURDATE(), INTERVAL %d DAY)", $warning_days));

    $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'all';
    $search_query  = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

    $where = "is_volunteer = 1 AND volunteer_status IN ('active', 'inactive')";
    $params = [];

    if ($status_filter === 'active') {
        $where .= " AND volunteer_status = 'active'";
    } elseif ($status_filter === 'inactive') {
        $where .= " AND volunteer_status = 'inactive'";
    } elseif ($status_filter === 'expired') {
        $where .= " AND card_expiry IS NOT NULL AND card_expiry != '' AND card_expiry != '0000-00-00' AND card_expiry < CURDATE()";
    } elseif ($status_filter === 'expiring') {
        $where .= $wpdb->prepare(" AND card_expiry IS NOT NULL AND card_expiry != '' AND card_expiry != '0000-00-00' AND card_expiry >= CURDATE() AND card_expiry <= DATE_ADD(CURDATE(), INTERVAL %d DAY)", $warning_days);
    }

    if (! empty($search_query)) {
        $where .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR card_number LIKE %s OR fai_registry_id LIKE %s)';
        $like = '%' . $wpdb->esc_like($search_query) . '%';
        $params = [$like, $like, $like, $like, $like];
    }

    $sql_official = "SELECT * FROM {$table_fai} WHERE {$where} ORDER BY last_name ASC, first_name ASC";
    $official_volunteers = ! empty($params) ? $wpdb->get_results($wpdb->prepare($sql_official, $params)) : $wpdb->get_results($sql_official);

    // Tipi di tessera configurati
    $types_string = function_exists('dfn_get_setting') ? dfn_get_setting('fai_member_types', 'INDIVIDUALE, COPPIA, FAMIGLIA') : 'INDIVIDUALE, COPPIA, FAMIGLIA';
    $types_list   = array_map('trim', explode(',', $types_string));

    // Ruoli FAI configurati nel sistema
    $all_stored_roles = function_exists('dfn_get_stored_roles') ? dfn_get_stored_roles() : [];

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
                <div style="display:flex; gap:10px; align-items:center;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-settings')); ?>" class="button button-secondary" style="font-weight:600;">
                        <span class="dashicons dashicons-admin-generic" style="vertical-align:text-bottom; margin-right:2px;"></span> Impostazioni
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-add')); ?>" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:600; padding:4px 14px;">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom; margin-right:4px;"></span> Aggiungi Volontario
                    </a>
                </div>
            </div>
        </header>

        <!-- KPI STATISTICHE -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:24px;">
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; border-top:3px solid #004b23; padding:16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <div style="width:40px; height:40px; border-radius:8px; background:#f0fdf4; display:flex; align-items:center; justify-content:center; color:#004b23;">
                    <span class="dashicons dashicons-yes-alt" style="font-size:22px;"></span>
                </div>
                <div>
                    <span style="font-size:22px; font-weight:700; color:#004b23; display:block;"><?php echo intval($count_active); ?></span>
                    <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Volontari Attivi</span>
                </div>
            </div>

            <div style="background:#fff; border-radius:8px; border:<?php echo $count_pending > 0 ? '2px solid #f59e0b' : '1px solid #c3c4c7'; ?>; border-top:3px solid #f59e0b; padding:16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <div style="width:40px; height:40px; border-radius:8px; background:#fffbeb; display:flex; align-items:center; justify-content:center; color:#d97706;">
                    <span class="dashicons dashicons-clock" style="font-size:22px;"></span>
                </div>
                <div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span style="font-size:22px; font-weight:700; color:#b45309;"><?php echo intval($count_pending); ?></span>
                        <?php if ($count_pending > 0): ?>
                            <span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; border-radius:10px; padding:2px 8px; font-size:11px; font-weight:700;">Da Verificare</span>
                        <?php endif; ?>
                    </div>
                    <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">In Attesa Approvazione</span>
                </div>
            </div>

            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; border-top:3px solid #94a3b8; padding:16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <div style="width:40px; height:40px; border-radius:8px; background:#f8fafc; display:flex; align-items:center; justify-content:center; color:#64748b;">
                    <span class="dashicons dashicons-dismiss" style="font-size:22px;"></span>
                </div>
                <div>
                    <span style="font-size:22px; font-weight:700; color:#475569; display:block;"><?php echo intval($count_inactive); ?></span>
                    <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Inattivi</span>
                </div>
            </div>

            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; border-top:3px solid #3b82f6; padding:16px; display:flex; align-items:center; gap:12px; box-shadow:0 1px 2px rgba(0,0,0,0.04);">
                <div style="width:40px; height:40px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#2563eb;">
                    <span class="dashicons dashicons-groups" style="font-size:22px;"></span>
                </div>
                <div>
                    <span style="font-size:22px; font-weight:700; color:#0f172a; display:block;"><?php echo intval($count_official); ?></span>
                    <span style="font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase;">Totale Volontari Ufficiali</span>
                </div>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- SEZIONE 1 (IN ALTO): CANDIDATURE IN ATTESA DI APPROVAZIONE           -->
        <!-- =================================================================== -->
        <div style="background:#fff; border-radius:8px; border:1px solid <?php echo $count_pending > 0 ? '#fde68a' : '#c3c4c7'; ?>; border-top:4px solid #f59e0b; margin-bottom:30px; box-shadow:0 2px 4px rgba(0,0,0,0.05); overflow:hidden;">
            <div style="padding:16px 20px; background:<?php echo $count_pending > 0 ? '#fffbeb' : '#fafafa'; ?>; border-bottom:1px solid <?php echo $count_pending > 0 ? '#fde68a' : '#e2e8f0'; ?>; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 style="font-size:16px; font-weight:700; color:#92400e; margin:0; display:flex; align-items:center; gap:8px;">
                        <span>⏳</span> Candidature in Attesa di Approvazione
                        <span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:12px; padding:2px 10px; font-size:12px; font-weight:700;">
                            <?php echo $count_pending; ?>
                        </span>
                    </h2>
                    <p style="font-size:12px; color:#78350f; margin:4px 0 0 0;">
                        Candidati registrati tramite il modulo online che richiedono la verifica/inserimento della Tessera FAI, controllo SiVol e approvazione.
                    </p>
                </div>
            </div>

            <?php if (! empty($pending_volunteers)) : ?>
                <table class="wp-list-table widefat fixed striped" style="border:none;">
                    <thead>
                        <tr style="background:#fdf6e2;">
                            <th style="width:200px; font-weight:700; color:#78350f;">Candidato</th>
                            <th style="width:200px; font-weight:700; color:#78350f;">Contatti</th>
                            <th style="width:130px; font-weight:700; color:#78350f;">Data Candidatura</th>
                            <th style="font-weight:700; color:#78350f;">Disponibilità / Competenze Indicate</th>
                            <th style="width:180px; font-weight:700; color:#78350f; text-align:right;">Azioni Approvazione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_volunteers as $p) : 
                            $u = $p->user_id ? get_userdata($p->user_id) : null;
                            $reg_date = ! empty($p->created_at) ? date_i18n('d/m/Y H:i', strtotime($p->created_at)) : (! empty($p->joined_date) ? date_i18n('d/m/Y', strtotime($p->joined_date)) : '—');
                            $reject_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteers&action=reject_volunteer&volunteer_id=' . $p->id), 'dfn_vol_action_' . $p->id);
                        ?>
                            <tr style="background:#fff;">
                                <td>
                                    <strong style="font-size:14px; color:#0f172a; display:block;">
                                        <?php echo esc_html($p->first_name . ' ' . $p->last_name); ?>
                                    </strong>
                                    <?php if ($u) : ?>
                                        <span style="font-size:11.5px; color:#64748b;">Account: <code><?php echo esc_html($u->user_login); ?></code></span>
                                    <?php else : ?>
                                        <span style="font-size:11.5px; color:#94a3b8; font-style:italic;">(Nuovo utente)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:12.5px; color:#334155; font-weight:600;">✉️ <?php echo esc_html($p->email); ?></div>
                                    <?php if ($p->phone) : ?>
                                        <div style="font-size:12px; color:#64748b; margin-top:2px;">📞 <?php echo esc_html($p->phone); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:12.5px; color:#475569;">
                                    📅 <?php echo esc_html($reg_date); ?>
                                </td>
                                <td>
                                    <?php if (! empty($p->is_guide)) : ?>
                                        <span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:10px; font-size:11px; font-weight:700; padding:2px 8px; margin-right:6px; display:inline-block;">
                                            🏛️ Disponibile come Guida
                                        </span>
                                    <?php endif; ?>
                                    <?php if (! empty($p->volunteer_notes)) : ?>
                                        <span style="font-size:12px; color:#475569; font-style:italic; display:inline-block; margin-top:2px;">
                                            📝 "<?php echo esc_html($p->volunteer_notes); ?>"
                                        </span>
                                    <?php endif; ?>
                                    <?php if (empty($p->is_guide) && empty($p->volunteer_notes)) : ?>
                                        <span style="font-size:12px; color:#94a3b8;">Nessuna nota aggiuntiva</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right; vertical-align:middle;">
                                    <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px;">
                                        <button type="button" 
                                                class="button button-primary dfn-btn-open-approve-modal"
                                                data-vol-id="<?php echo esc_attr($p->id); ?>"
                                                data-vol-name="<?php echo esc_attr($p->first_name . ' ' . $p->last_name); ?>"
                                                data-vol-email="<?php echo esc_attr($p->email); ?>"
                                                data-vol-phone="<?php echo esc_attr($p->phone ?: ''); ?>"
                                                data-vol-card="<?php echo esc_attr($p->card_number ?: ''); ?>"
                                                data-vol-expiry="<?php echo esc_attr($p->card_expiry ?: ''); ?>"
                                                data-vol-type="<?php echo esc_attr($p->card_type ?: 'INDIVIDUALE'); ?>"
                                                data-vol-sivol="<?php echo esc_attr($p->is_sivol_registered ?? 0); ?>"
                                                data-vol-guide="<?php echo esc_attr($p->is_guide ?? 0); ?>"
                                                data-vol-safety="<?php echo esc_attr($p->has_safety_course ?? 0); ?>"
                                                data-vol-notes="<?php echo esc_attr($p->volunteer_notes ?: ''); ?>"
                                                style="background:#004b23; border-color:#003b1c; font-weight:700; white-space:nowrap; padding:4px 12px; display:inline-flex; align-items:center; gap:4px;">
                                            <span>✅</span> Approva
                                        </button>
                                        <a href="<?php echo esc_url($reject_url); ?>" 
                                           class="button button-small" 
                                           style="color:#b91c1c; white-space:nowrap; padding:3px 8px;" 
                                           onclick="return confirm('Confermi l\'archiviazione / rifiuto di questa candidatura?');">
                                            ❌ Rifiuta
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <div style="padding:24px; text-align:center; color:#64748b; font-size:13.5px;">
                    🎉 <strong>Nessuna nuova candidatura in attesa di approvazione al momento.</strong> Tutte le richieste sono state elaborate.
                </div>
            <?php endif; ?>
        </div>

        <!-- =================================================================== -->
        <!-- SEZIONE 2 (IN BASSO): REGISTRO VOLONTARI UFFICIALI                   -->
        <!-- =================================================================== -->
        <div style="margin-bottom:16px;">
            <h2 style="font-size:18px; font-weight:700; color:#1e293b; margin:0 0 12px 0; display:flex; align-items:center; gap:8px;">
                <span>👥</span> Registro Volontari Ufficiali
            </h2>

            <!-- FILTRI TABS & BARRA DI RICERCA -->
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <ul class="subsubsub" style="margin:0; padding:0; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>" style="font-size:13px; font-weight:<?php echo $status_filter === 'all' ? '700' : '500'; ?>;">Tutti i Volontari <span class="count">(<?php echo $count_official; ?>)</span></a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers&status=active')); ?>" class="<?php echo $status_filter === 'active' ? 'current' : ''; ?>" style="font-size:13px; font-weight:<?php echo $status_filter === 'active' ? '700' : '500'; ?>; color:<?php echo $status_filter === 'active' ? '#004b23' : ''; ?>;">Attivi <span class="count">(<?php echo $count_active; ?>)</span></a> |</li>
                    <li><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers&status=inactive')); ?>" class="<?php echo $status_filter === 'inactive' ? 'current' : ''; ?>" style="font-size:13px; font-weight:<?php echo $status_filter === 'inactive' ? '700' : '500'; ?>;">Inattivi <span class="count">(<?php echo $count_inactive; ?>)</span></a><?php if ($count_expiring > 0 || $count_expired > 0) : ?> |<?php endif; ?></li>
                    <?php if ($count_expiring > 0) : ?>
                        <li><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers&status=expiring')); ?>" class="<?php echo $status_filter === 'expiring' ? 'current' : ''; ?>" style="font-size:13px; font-weight:<?php echo $status_filter === 'expiring' ? '700' : '500'; ?>; color:#d97706;">⏳ In Scadenza <span class="count">(<?php echo $count_expiring; ?>)</span></a><?php if ($count_expired > 0) : ?> |<?php endif; ?></li>
                    <?php endif; ?>
                    <?php if ($count_expired > 0) : ?>
                        <li><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers&status=expired')); ?>" class="<?php echo $status_filter === 'expired' ? 'current' : ''; ?>" style="font-size:13px; font-weight:<?php echo $status_filter === 'expired' ? '700' : '500'; ?>; color:#dc2626;">⚠️ Scadute <span class="count">(<?php echo $count_expired; ?>)</span></a></li>
                    <?php endif; ?>
                </ul>

                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:8px; width:100%; max-width:360px;">
                    <input type="hidden" name="page" value="dfn-volunteers">
                    <?php if ($status_filter !== 'all') : ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
                    <?php endif; ?>
                    <input type="text" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Cerca nome, email o tessera…" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:32px; padding:0 10px; font-size:13px;">
                    <button type="submit" class="button button-secondary" style="height:32px; line-height:30px;">Cerca</button>
                    <?php if (! empty($search_query)) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers' . ($status_filter !== 'all' ? '&status=' . $status_filter : ''))); ?>" class="button" style="height:32px; line-height:30px;">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- TABELLA VOLONTARI UFFICIALI -->
        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,0.05); margin-bottom:30px;">
            <table class="wp-list-table widefat fixed striped table-view-list" style="border:none;">
                <thead>
                    <tr>
                        <th style="width:170px; font-weight:700;">Volontario</th>
                        <th style="width:145px; font-weight:700;">Tessera FAI <?php dfn_tooltip_icon('dfn-tip-vol-card', 'Informazioni: Tessere FAI'); ?></th>
                        <th style="width:115px; font-weight:700; text-align:center;">SiVol <?php dfn_tooltip_icon('dfn-tip-vol-sivol', 'Informazioni: Registrazione SiVol'); ?></th>
                        <th style="width:175px; font-weight:700;">Contatti</th>
                        <th style="font-weight:700;">Incarichi &amp; Ruoli FAI <?php dfn_tooltip_icon('dfn-tip-vol-user', 'Informazioni: Ruoli e Deleghe FAI'); ?></th>
                        <th style="width:160px; font-weight:700;">Competenze <?php dfn_tooltip_icon('dfn-tip-vol-badges', 'Informazioni: Competenze e Formazione'); ?></th>
                        <th style="width:90px; font-weight:700; text-align:center;">Stato</th>
                        <th style="width:190px; font-weight:700; text-align:right;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($official_volunteers)) : ?>
                        <?php foreach ($official_volunteers as $v) : 
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
                                    <?php 
                                    if (! empty($v->card_number)) : 
                                        $today = current_time('Y-m-d');
                                        $is_card_expired  = false;
                                        $is_card_expiring = false;

                                        if (! empty($v->card_expiry) && $v->card_expiry !== '0000-00-00') {
                                            $exp_date = date('Y-m-d', strtotime($v->card_expiry));
                                            if ($exp_date < $today) {
                                                $is_card_expired = true;
                                            } else {
                                                $limit_date = date('Y-m-d', strtotime("+{$warning_days} days", strtotime($today)));
                                                if ($exp_date <= $limit_date) {
                                                    $is_card_expiring = true;
                                                }
                                            }
                                        }
                                    ?>
                                        <?php if ($is_card_expired) : ?>
                                            <code style="background:#fee2e2; padding:3px 7px; border-radius:5px; border:1px solid #f87171; font-weight:700; color:#991b1b; white-space:nowrap; display:inline-flex; align-items:center; gap:4px;">
                                                💳 <?php echo esc_html($v->card_number); ?>
                                            </code>
                                            <div style="font-size:11px; font-weight:700; color:#dc2626; margin-top:3px; white-space:nowrap; display:flex; align-items:center; gap:4px;">
                                                <span>Scad: <?php echo esc_html(date_i18n('d/m/Y', strtotime($v->card_expiry))); ?></span>
                                                <span style="background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; border-radius:4px; padding:0 4px; font-size:9.5px; font-weight:800; text-transform:uppercase;">Scaduta</span>
                                            </div>
                                        <?php elseif ($is_card_expiring) : ?>
                                            <code style="background:#fef3c7; padding:3px 7px; border-radius:5px; border:1px solid #f59e0b; font-weight:700; color:#92400e; white-space:nowrap; display:inline-flex; align-items:center; gap:4px;">
                                                💳 <?php echo esc_html($v->card_number); ?>
                                            </code>
                                            <div style="font-size:11px; font-weight:700; color:#d97706; margin-top:3px; white-space:nowrap; display:flex; align-items:center; gap:4px;">
                                                <span>Scad: <?php echo esc_html(date_i18n('d/m/Y', strtotime($v->card_expiry))); ?></span>
                                                <span style="background:#fffbeb; color:#b45309; border:1px solid #fde68a; border-radius:4px; padding:0 4px; font-size:9.5px; font-weight:800; text-transform:uppercase;">In scadenza</span>
                                            </div>
                                        <?php else : ?>
                                            <code style="background:#f1f5f9; padding:3px 6px; border-radius:4px; border:1px solid #e2e8f0; font-weight:600; color:#334155; white-space:nowrap;">
                                                💳 <?php echo esc_html($v->card_number); ?>
                                            </code>
                                            <?php if ($v->card_expiry && $v->card_expiry !== '0000-00-00') : ?>
                                                <div style="font-size:11px; color:#64748b; margin-top:2px; white-space:nowrap;">Scad: <?php echo esc_html(date_i18n('d/m/Y', strtotime($v->card_expiry))); ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (! empty($v->fai_registry_id)) : ?>
                                            <div style="font-size:10.5px; color:#64748b; margin-top:2px;">
                                                <span style="color:#004b23; font-weight:700;">ID:</span> <?php echo esc_html($v->fai_registry_id); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <span style="font-size:11px; background:#fff; color:#b45309; border:1px dashed #fcd34d; padding:2px 7px; border-radius:6px; font-weight:600; white-space:nowrap;">
                                            ⚠️ Da assegnare
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center; vertical-align:middle;">
                                    <?php if (! empty($v->is_sivol_registered)) : ?>
                                        <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700; background:#dcfce7; color:#15803d; border:1px solid #86efac; white-space:nowrap;">
                                            ✓ SiVol
                                        </span>
                                    <?php else : ?>
                                        <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:500; background:#f1f5f9; color:#94a3b8; border:1px solid #e2e8f0; white-space:nowrap;">
                                            Non reg.
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:12px; color:#334155; word-break:break-all;">✉️ <?php echo esc_html($v->email); ?></div>
                                    <?php if ($v->phone) : ?>
                                        <div style="font-size:11.5px; color:#64748b; margin-top:2px; white-space:nowrap;">📞 <?php echo esc_html($v->phone); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:12.5px; font-weight:600; color:#1e293b;"><?php echo esc_html($roles_label); ?></span>
                                    <?php if ($v->volunteer_notes) : ?>
                                        <div style="font-size:11.5px; color:#64748b; font-style:italic; margin-top:3px;">📝 <?php echo esc_html($v->volunteer_notes); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:5px; flex-wrap:wrap; align-items:center;">
                                        <?php if (! empty($v->is_guide)) : ?>
                                            <span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:10px; font-size:10.5px; font-weight:700; padding:2px 7px; white-space:nowrap;">
                                                🏛️ Guida
                                            </span>
                                        <?php endif; ?>
                                        <?php if (! empty($v->has_safety_course)) : ?>
                                            <span style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; border-radius:10px; font-size:10.5px; font-weight:700; padding:2px 7px; white-space:nowrap;">
                                                🦺 Sicurezza
                                            </span>
                                        <?php endif; ?>
                                        <?php if (empty($v->is_guide) && empty($v->has_safety_course)) : ?>
                                            <span style="font-size:12px; color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </div>
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
                                        $toggle_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteers&action=toggle_status&volunteer_id=' . $v->id . ($status_filter !== 'all' ? '&status=' . $status_filter : '')), 'dfn_vol_action_' . $v->id);
                                        $delete_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteers&action=delete&volunteer_id=' . $v->id . ($status_filter !== 'all' ? '&status=' . $status_filter : '')), 'dfn_vol_action_' . $v->id);
                                        ?>
                                        <a href="<?php echo esc_url($edit_url); ?>" class="button button-small" title="Modifica dati e ruoli" style="white-space:nowrap; padding:0 8px;">
                                            ✏️ Modifica
                                        </a>
                                        <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small" title="Attiva/Disattiva" style="white-space:nowrap; padding:0 8px;">
                                            <?php echo ($v->volunteer_status === 'active') ? 'Disattiva' : 'Attiva'; ?>
                                        </a>
                                        <a href="<?php echo esc_url($delete_url); ?>" class="button button-small" style="color:#b91c1c; white-space:nowrap; padding:0 8px;" onclick="return confirm('Confermi la rimozione del volontario dal registro?');">
                                            Rimuovi
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="8" style="padding:30px; text-align:center; color:#64748b;">
                                Nessun volontario ufficiale trovato con i filtri selezionati. <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-add')); ?>">Aggiungi un volontario manualmente</a>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- =================================================================== -->
        <!-- MODALE DI APPROVAZIONE CANDIDATURA VOLONTARIO                       -->
        <!-- =================================================================== -->
        <div id="dfn-approve-modal-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,0.6); z-index:99990; backdrop-filter:blur(2px);"></div>

        <div id="dfn-approve-volunteer-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:95%; max-width:620px; max-height:90vh; overflow-y:auto; background:#ffffff; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.04); z-index:99999; padding:24px 28px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:1px solid #e2e8f0; padding-bottom:14px; margin-bottom:18px;">
                <div>
                    <h3 style="margin:0; font-size:18px; font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px;">
                        <span>✅</span> Verifica &amp; Approvazione Volontario
                    </h3>
                    <p style="margin:4px 0 0 0; font-size:12.5px; color:#64748b;">
                        Inserisci i dettagli della tessera FAI e conferma la registrazione SiVol per attivare il volontario.
                    </p>
                </div>
                <button type="button" id="dfn-btn-close-approve-modal" style="background:transparent; border:none; font-size:22px; line-height:1; color:#94a3b8; cursor:pointer; padding:2px 6px;">&times;</button>
            </div>

            <form method="post" action="" id="dfn-approve-volunteer-form">
                <input type="hidden" name="dfn_action" value="approve_volunteer_submit">
                <input type="hidden" name="volunteer_id" id="dfn-modal-vol-id" value="">
                <?php wp_nonce_field('dfn_approve_vol_nonce'); ?>

                <!-- Box Riepilogo Candidato -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:18px; display:flex; flex-direction:column; gap:4px;">
                    <div style="font-size:14px; font-weight:700; color:#0f172a;" id="dfn-modal-vol-name">Mario Rossi</div>
                    <div style="font-size:12.5px; color:#475569;" id="dfn-modal-vol-contacts">email@example.com</div>
                </div>

                <!-- Dettagli Tessera FAI -->
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12.5px; font-weight:700; color:#334155; margin-bottom:4px;">
                        💳 Numero Tessera FAI
                    </label>
                    <input type="text" name="card_number" id="dfn-modal-card-number" placeholder="Es. 3927784" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:13.5px; font-weight:600;">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                    <div>
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#334155; margin-bottom:4px;">
                            📅 Scadenza Tessera
                        </label>
                        <input type="date" name="card_expiry" id="dfn-modal-card-expiry" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#334155; margin-bottom:4px;">
                            Tipologia Tessera
                        </label>
                        <select name="card_type" id="dfn-modal-card-type" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:13px;">
                            <?php foreach ($types_list as $t) : ?>
                                <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- CHECKBOX REGISTRATO SU SIVOL (Requisito Richiesto) -->
                <div style="background:#f0fdf4; border:1.5px solid #86efac; border-radius:8px; padding:14px 16px; margin-bottom:18px;">
                    <label style="display:flex; align-items:flex-start; gap:12px; cursor:pointer;">
                        <input type="checkbox" name="is_sivol_registered" id="dfn-modal-is-sivol" value="1" style="width:20px; height:20px; margin-top:2px; accent-color:#004b23;">
                        <div>
                            <strong style="font-size:13.5px; color:#14532d; display:block;">🌐 Registrato su SiVol FAI</strong>
                            <span style="font-size:12px; color:#166534; line-height:1.4; display:block; margin-top:2px;">
                                Spunta questa casella se hai verificato/inserito il volontario sul portale gestionale nazionale SiVol.
                            </span>
                        </div>
                    </label>
                </div>

                <!-- Competenze Turni -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 16px; margin-bottom:18px; display:flex; flex-direction:column; gap:10px;">
                    <span style="font-size:12px; font-weight:700; color:#475569; text-transform:uppercase;">Competenze &amp; Abilitazioni</span>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="is_guide" id="dfn-modal-is-guide" value="1" style="width:16px; height:16px;">
                        <span style="font-size:13px; color:#1e293b;">🏛️ <strong>Volontario Guida</strong> (visite e percorsi narrati)</span>
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="has_safety_course" id="dfn-modal-has-safety" value="1" style="width:16px; height:16px;">
                        <span style="font-size:13px; color:#1e293b;">🦺 <strong>Corso Sicurezza Attivo</strong> (Responsabile Scuola / Ciceroni)</span>
                    </label>
                </div>

                <!-- Incarichi / Ruoli FAI Delegazione -->
                <?php if (! empty($all_stored_roles)) : ?>
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#334155; margin-bottom:6px;">
                            🛡️ Assegna Incarichi di Delegazione (Opzionale)
                        </label>
                        <div style="max-height:120px; overflow-y:auto; border:1px solid #cbd5e1; border-radius:6px; padding:8px 12px; display:flex; flex-direction:column; gap:6px;">
                            <?php foreach ($all_stored_roles as $r_slug => $r_info) : 
                                if ($r_slug === 'administrator') continue;
                            ?>
                                <label style="display:flex; align-items:center; gap:8px; font-size:12.5px; color:#334155; cursor:pointer;">
                                    <input type="checkbox" name="fai_roles[]" value="<?php echo esc_attr($r_slug); ?>" style="accent-color:#004b23;">
                                    <span><?php echo esc_html($r_info['label']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Note Interne -->
                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12.5px; font-weight:700; color:#334155; margin-bottom:4px;">
                        📝 Note Interne Delegazione
                    </label>
                    <textarea name="volunteer_notes" id="dfn-modal-notes" rows="2" placeholder="Note per l'organizzazione interna..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:6px 10px; font-size:12.5px;"></textarea>
                </div>

                <!-- Pulsanti Azione Modale -->
                <div style="display:flex; justify-content:flex-end; align-items:center; gap:10px; border-top:1px solid #e2e8f0; padding-top:16px;">
                    <button type="button" id="dfn-btn-cancel-approve-modal" class="button" style="padding:4px 16px;">Annulla</button>
                    <button type="submit" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:4px 20px;">
                        ✅ Conferma Approvazione &amp; Attiva Volontario
                    </button>
                </div>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var modal   = document.getElementById('dfn-approve-volunteer-modal');
            var overlay = document.getElementById('dfn-approve-modal-overlay');
            var btnClose  = document.getElementById('dfn-btn-close-approve-modal');
            var btnCancel = document.getElementById('dfn-btn-cancel-approve-modal');

            function closeModal() {
                if (modal) modal.style.display = 'none';
                if (overlay) overlay.style.display = 'none';
                document.body.style.overflow = '';
            }

            if (btnClose) btnClose.addEventListener('click', closeModal);
            if (btnCancel) btnCancel.addEventListener('click', closeModal);
            if (overlay) overlay.addEventListener('click', closeModal);

            document.querySelectorAll('.dfn-btn-open-approve-modal').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var id     = this.getAttribute('data-vol-id') || '';
                    var name   = this.getAttribute('data-vol-name') || '';
                    var email  = this.getAttribute('data-vol-email') || '';
                    var phone  = this.getAttribute('data-vol-phone') || '';
                    var card   = this.getAttribute('data-vol-card') || '';
                    var expiry = this.getAttribute('data-vol-expiry') || '';
                    var type   = this.getAttribute('data-vol-type') || 'INDIVIDUALE';
                    var sivol  = this.getAttribute('data-vol-sivol') === '1';
                    var guide  = this.getAttribute('data-vol-guide') === '1';
                    var safety = this.getAttribute('data-vol-safety') === '1';
                    var notes  = this.getAttribute('data-vol-notes') || '';

                    document.getElementById('dfn-modal-vol-id').value = id;
                    document.getElementById('dfn-modal-vol-name').textContent = name;
                    document.getElementById('dfn-modal-vol-contacts').textContent = email + (phone ? ' • Tel: ' + phone : '');
                    document.getElementById('dfn-modal-card-number').value = card;
                    document.getElementById('dfn-modal-card-expiry').value = expiry;
                    document.getElementById('dfn-modal-card-type').value = type;
                    document.getElementById('dfn-modal-is-sivol').checked = sivol;
                    document.getElementById('dfn-modal-is-guide').checked = guide;
                    document.getElementById('dfn-modal-has-safety').checked = safety;
                    document.getElementById('dfn-modal-notes').value = notes;

                    if (modal) modal.style.display = 'block';
                    if (overlay) overlay.style.display = 'block';
                    document.body.style.overflow = 'hidden';

                    setTimeout(function() {
                        var cardInput = document.getElementById('dfn-modal-card-number');
                        if (cardInput) cardInput.focus();
                    }, 100);
                });
            });
        });
        </script>

        <!-- Overlay e Tooltip Modals Elenco Volontari -->
        <div class="dfn-tooltip-overlay" id="dfn-tooltip-overlay"></div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-badges" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-badges-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-badges-title">🏛️ Ruoli e Competenze dei Volontari</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>I badge e le competenze guidano l'algoritmo di <strong>assegnazione automatica</strong> e la selezione manuale dei turni durante gli eventi:</p>
                <ul>
                    <li><strong>🏛️ Guida Culturale:</strong> identifica i volontari formati per condurre visite guidate, percorsi narrati o approfondimenti storico-artistici.</li>
                    <li><strong>🦺 Corso Sicurezza Attivo:</strong> certifica il superamento della formazione sulla sicurezza nei luoghi di lavoro (D.Lgs. 81/08). È un requisito fondamentale per le mansioni a contatto con le scolaresche (es. <em>Responsabile Scuola / Apprendisti Ciceroni</em>).</li>
                </ul>
                <div class="dfn-tip-box">
                    <strong>Suggerimento:</strong> Puoi personalizzare e aggiungere nuove mansioni operative dal menu <em>Mansioni &amp; Ruoli</em>.
                </div>
            </div>
        </div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-card" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-card-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-card-title">💳 Tessere FAI e Utenti Collegati</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Ogni volontario registrato in anagrafica deve possedere una <strong>Tessera Iscritto FAI in corso di validità</strong>.</p>
                <p>Se la tessera viene associata ad un account WordPress del sito, il volontario vedrà comparire in automatico la sezione <strong>Volontari</strong> e la <strong>Bacheca Turni</strong> nella sua area personale.</p>
            </div>
        </div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-sivol" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-sivol-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-sivol-title">🌐 Piattaforma Nazionale SiVol FAI</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p><strong>SiVol</strong> è il gestionale ufficiale della presidenza nazionale FAI per il censimento e la copertura assicurativa di tutti i volontari attivi.</p>
                <p>La spunta <strong>Registrato su SiVol</strong> certifica che la delegazione ha completato l'inserimento dell'anagrafica sul portale nazionale prima dell'impiego operativo negli eventi.</p>
            </div>
        </div>
    </div>
    <?php
}

/**
/**
 * Aggiunge l'azione rapida "Rendi Volontario FAI" nella lista Utenti di WordPress (wp-admin/users.php).
 */
add_filter('user_row_actions', 'dfn_volunteer_user_row_actions', 10, 2);
function dfn_volunteer_user_row_actions(array $actions, \WP_User $user): array
{
    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members')) {
        return $actions;
    }

    $is_vol = in_array('dfn_volunteer', (array) $user->roles, true);
    if ($is_vol) {
        $url = admin_url('admin.php?page=dfn-volunteer-add&user_id=' . $user->ID);
        $actions['dfn_vol'] = '<a href="' . esc_url($url) . '" style="color:#15803d; font-weight:600;">👥 Modifica Volontario FAI</a>';
    } else {
        $url = admin_url('admin.php?page=dfn-volunteer-add&user_id=' . $user->ID);
        $actions['dfn_make_vol'] = '<a href="' . esc_url($url) . '" style="color:#004b23; font-weight:600;">➕ Rendi Volontario FAI</a>';
    }

    return $actions;
}

/**
 * Sincronizza lo stato volontario quando viene assegnato il ruolo 'dfn_volunteer' a un utente WP.
 */
add_action('set_user_role', 'dfn_sync_user_volunteer_role', 10, 3);
function dfn_sync_user_volunteer_role(int $user_id, string $role, array $old_roles): void
{
    if ($role === 'dfn_volunteer') {
        global $wpdb;
        $table_fai = $wpdb->prefix . 'dfn_fai_members';
        $user = get_userdata($user_id);
        if ($user) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE user_id = %d OR email = %s", $user_id, $user->user_email));
            if ($existing) {
                $wpdb->update($table_fai, [
                    'is_volunteer'     => 1,
                    'volunteer_status' => 'active',
                    'user_id'          => $user_id,
                ], ['id' => $existing->id], ['%d', '%s', '%d'], ['%d']);
            } else {
                $first_name = $user->first_name ?: $user->display_name;
                $last_name  = $user->last_name ?: '';
                $phone      = get_user_meta($user_id, 'billing_phone', true) ?: get_user_meta($user_id, 'phone', true) ?: '';
                $wpdb->insert($table_fai, [
                    'user_id'          => $user_id,
                    'first_name'       => $first_name,
                    'last_name'        => $last_name,
                    'email'            => $user->user_email,
                    'phone'            => $phone,
                    'is_volunteer'     => 1,
                    'volunteer_status' => 'active',
                    'joined_date'      => current_time('Y-m-d'),
                    'created_at'       => current_time('mysql'),
                ], ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
            }
        }
    }
}

/**
 * Renderizza la schermata "Aggiungi / Modifica Volontario".
 */
function dfn_render_volunteer_add_page(): void
{
    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_die(__('Permessi insufficienti.', 'dfn-theme'));
    }

    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    $vol_id         = isset($_GET['volunteer_id']) ? (int) $_GET['volunteer_id'] : 0;
    $from_member_id = isset($_GET['from_member_id']) ? (int) $_GET['from_member_id'] : 0;
    $param_user_id  = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

    $volunteer_data   = null;
    $selected_user_id = 0;
    $source_message   = '';

    if ($vol_id > 0) {
        $volunteer_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", $vol_id));
        if ($volunteer_data && $volunteer_data->user_id) {
            $selected_user_id = (int) $volunteer_data->user_id;
        }
    } elseif ($from_member_id > 0) {
        $member_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", $from_member_id));
        if ($member_row) {
            $volunteer_data = $member_row;
            if ($member_row->user_id) {
                $selected_user_id = (int) $member_row->user_id;
            }
            $source_message = sprintf('Promozione del socio FAI <strong>%s %s</strong> (Tessera: %s) a Volontario Ufficiale.', esc_html($member_row->first_name), esc_html($member_row->last_name), esc_html($member_row->card_number ?: 'Nessuna'));
        }
    } elseif ($param_user_id > 0) {
        $selected_user_id = $param_user_id;
        $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE user_id = %d", $param_user_id));
        if ($existing_row) {
            $volunteer_data = $existing_row;
            $source_message = sprintf('Associazione account WordPress <strong>%s</strong> (già presente in anagrafica FAI).', esc_html($existing_row->first_name . ' ' . $existing_row->last_name));
        } else {
            $wp_u = get_userdata($param_user_id);
            if ($wp_u) {
                // Cerchiamo anche per email
                $existing_by_email = ! empty($wp_u->user_email) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE email = %s", $wp_u->user_email)) : null;
                if ($existing_by_email) {
                    $volunteer_data = $existing_by_email;
                    $source_message = sprintf('Associazione account WordPress <strong>%s</strong> (trovato per email in anagrafica).', esc_html($wp_u->display_name));
                } else {
                    $volunteer_data = (object) [
                        'id'                  => 0,
                        'user_id'             => $wp_u->ID,
                        'first_name'          => $wp_u->first_name ?: $wp_u->display_name,
                        'last_name'           => $wp_u->last_name ?: '',
                        'email'               => $wp_u->user_email,
                        'phone'               => get_user_meta($wp_u->ID, 'billing_phone', true) ?: get_user_meta($wp_u->ID, 'phone', true) ?: '',
                        'card_number'         => '',
                        'card_expiry'         => '',
                        'card_type'           => 'INDIVIDUALE',
                        'verified'            => 0,
                        'is_sivol_registered' => 0,
                        'is_volunteer'        => 1,
                        'volunteer_notes'     => '',
                        'is_guide'            => 0,
                        'has_safety_course'   => 0,
                    ];
                    $source_message = sprintf('Creazione volontario dall\'account utente WordPress <strong>%s</strong> (%s).', esc_html($wp_u->display_name), esc_html($wp_u->user_email));
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // SALVATAGGIO FORM VOLONTARIO (Nuovo o Modifica)
    // -------------------------------------------------------------------------
    if (isset($_POST['dfn_save_volunteer']) && check_admin_referer('dfn_save_volunteer_nonce')) {
        $first_name   = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name    = sanitize_text_field($_POST['last_name'] ?? '');
        $email        = sanitize_email($_POST['email'] ?? '');
        $phone           = sanitize_text_field($_POST['phone'] ?? '');
        $card_number     = sanitize_text_field($_POST['card_number'] ?? '');
        $fai_registry_id = sanitize_text_field($_POST['fai_registry_id'] ?? '');
        $card_expiry     = ! empty($_POST['card_expiry']) ? sanitize_text_field($_POST['card_expiry']) : null;
        $card_type       = isset($_POST['card_type']) ? sanitize_text_field($_POST['card_type']) : 'INDIVIDUALE';
        $is_sivol        = ! empty($_POST['is_sivol_registered']) ? 1 : 0;
        $notes           = sanitize_textarea_field($_POST['notes'] ?? '');
        $user_id_raw     = (int) ($_POST['user_id'] ?? 0);
        $user_id         = $user_id_raw > 0 ? $user_id_raw : null;

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

        if (! empty($first_name) && ! empty($last_name) && ! empty($email)) {
            $is_guide            = isset($_POST['is_guide']) ? 1 : 0;
            $has_safety_course   = isset($_POST['has_safety_course']) ? 1 : 0;
            $submitted_fai_roles = isset($_POST['fai_roles']) && is_array($_POST['fai_roles']) ? array_map('sanitize_key', $_POST['fai_roles']) : [];

            // Determina se stiamo aggiornando un record esistente o cercandone uno corrispondente
            $target_record_id = ($volunteer_data && ! empty($volunteer_data->id)) ? (int) $volunteer_data->id : 0;

            if (! $target_record_id) {
                $existing_fai = null;
                if (! empty($card_number)) {
                    $existing_fai = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE card_number = %s", $card_number));
                }
                if (! $existing_fai && $user_id > 0) {
                    $existing_fai = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE user_id = %d", $user_id));
                }
                if (! $existing_fai && ! empty($email)) {
                    $existing_fai = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE email = %s", $email));
                }
                if ($existing_fai) {
                    $target_record_id = (int) $existing_fai->id;
                }
            }

            if ($target_record_id > 0) {
                $wpdb->update(
                    $table_fai,
                    [
                        'user_id'             => $user_id,
                        'first_name'          => $first_name,
                        'last_name'           => $last_name,
                        'email'               => $email,
                        'phone'               => $phone,
                        'card_number'         => $card_number,
                        'fai_registry_id'     => ! empty($fai_registry_id) ? $fai_registry_id : null,
                        'card_expiry'         => $card_expiry,
                        'card_type'           => $card_type,
                        'verified'            => ! empty($card_number) ? 1 : 0,
                        'verified_at'         => ! empty($card_number) ? current_time('mysql') : null,
                        'verified_by'         => ! empty($card_number) ? get_current_user_id() : null,
                        'is_sivol_registered' => $is_sivol,
                        'is_volunteer'        => 1,
                        'volunteer_status'    => 'active',
                        'volunteer_notes'     => $notes,
                        'is_guide'            => $is_guide,
                        'has_safety_course'   => $has_safety_course,
                    ],
                    [ 'id' => $target_record_id ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d' ],
                    [ '%d' ]
                );
                $saved_id = $target_record_id;
            } else {
                $wpdb->insert(
                    $table_fai,
                    [
                        'first_name'          => $first_name,
                        'last_name'           => $last_name,
                        'email'               => $email,
                        'phone'               => $phone,
                        'card_number'         => $card_number,
                        'fai_registry_id'     => ! empty($fai_registry_id) ? $fai_registry_id : null,
                        'card_expiry'         => $card_expiry,
                        'card_type'           => $card_type,
                        'verified'            => ! empty($card_number) ? 1 : 0,
                        'verified_at'         => ! empty($card_number) ? current_time('mysql') : null,
                        'verified_by'         => ! empty($card_number) ? get_current_user_id() : null,
                        'is_sivol_registered' => $is_sivol,
                        'user_id'             => $user_id,
                        'is_volunteer'        => 1,
                        'volunteer_status'    => 'active',
                        'volunteer_notes'     => $notes,
                        'joined_date'         => current_time('Y-m-d'),
                        'is_guide'            => $is_guide,
                        'has_safety_course'   => $has_safety_course,
                        'created_at'          => current_time('mysql'),
                    ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
                );
                $saved_id = $wpdb->insert_id;
            }

            // Sincronizzazione dei ruoli FAI assegnati all'account utente WordPress collegato
            if ($user_id && $user_id > 0) {
                $target_wp_user = get_userdata($user_id);
                if ($target_wp_user) {
                    $target_wp_user->add_role('dfn_volunteer');

                    $stored_roles = function_exists('dfn_get_stored_roles') ? dfn_get_stored_roles() : [];
                    $fai_slugs = array_diff(array_keys($stored_roles), ['administrator', 'dfn_volunteer']);

                    // Rimuove vecchi ruoli FAI organizzativi non più selezionati
                    foreach ($fai_slugs as $s_role) {
                        if (! in_array($s_role, $submitted_fai_roles, true)) {
                            $target_wp_user->remove_role($s_role);
                        }
                    }

                    // Aggiunge i nuovi ruoli organizzativi selezionati
                    $all_assigned = ['dfn_volunteer'];
                    foreach ($submitted_fai_roles as $n_role) {
                        if (isset($stored_roles[$n_role])) {
                            $target_wp_user->add_role($n_role);
                            $all_assigned[] = $n_role;
                        }
                    }

                    // Salva nei meta utente per lookup rapido
                    update_user_meta($user_id, '_dfn_assigned_fai_roles', array_unique($all_assigned));

                    // Salva le preferenze di notifica email del volontario se presenti nel form
                    if (isset($_POST['dfn_admin_notif_prefs_present'])) {
                        update_user_meta($user_id, '_dfn_notify_card_expiry', isset($_POST['dfn_notify_card_expiry']) ? '1' : '0');
                        update_user_meta($user_id, '_dfn_notify_meetings', isset($_POST['dfn_notify_meetings']) ? '1' : '0');
                        update_user_meta($user_id, '_dfn_notify_shifts', isset($_POST['dfn_notify_shifts']) ? '1' : '0');
                    }
                }
            }

            // Log dell'azione nel registro centrale Volontari FAI
            if (function_exists('dfn_log_volunteer_roster')) {
                $roster_action = ($volunteer_data && ! empty($volunteer_data->id)) ? 'Modifica anagrafica volontario' : 'Nuovo volontario registrato in anagrafica';
                $roster_details = sprintf("Tessera: %s (%s) | SiVol: %s | Ruoli: %s | Guida: %s | Sicurezza: %s", 
                    $card_number ?: 'Nessuna', 
                    $card_type, 
                    $is_sivol ? 'Sì' : 'No',
                    ! empty($submitted_fai_roles) ? implode(', ', $submitted_fai_roles) : 'Volontario base',
                    $is_guide ? 'Sì' : 'No',
                    $has_safety_course ? 'Sì' : 'No'
                );
                dfn_log_volunteer_roster($saved_id, $roster_action, $roster_details);
            }

            echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>Volontario e ruoli salvati con successo!</strong> L\'account utente è stato attivato come Volontario FAI.</p></div>';
            $volunteer_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_fai} WHERE id = %d", $saved_id));
            if ($volunteer_data && $volunteer_data->user_id) {
                $selected_user_id = (int) $volunteer_data->user_id;
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>❌ Compila tutti i campi obbligatori (Nome, Cognome, Email).</p></div>';
        }
    }

    // Lista utenti WP con metadati arricchiti per auto-completamento istantaneo
    $wp_users = get_users(['number' => 300, 'orderby' => 'display_name']);

    // Mappa dati FAI esistenti per user_id ed email per arricchire il select
    $all_fai_rows = $wpdb->get_results("SELECT id, user_id, first_name, last_name, email, phone, card_number, card_expiry, card_type, is_sivol_registered, is_guide, has_safety_course, volunteer_notes FROM {$table_fai}");
    $fai_by_uid   = [];
    $fai_by_email = [];
    foreach ($all_fai_rows as $row) {
        if (! empty($row->user_id)) {
            $fai_by_uid[(int) $row->user_id] = $row;
        }
        if (! empty($row->email)) {
            $fai_by_email[strtolower(trim($row->email))] = $row;
        }
    }

    // Tipi di tessera configurati
    $types_string = function_exists('dfn_get_setting') ? dfn_get_setting('fai_member_types', 'INDIVIDUALE, COPPIA, FAMIGLIA') : 'INDIVIDUALE, COPPIA, FAMIGLIA';
    $types_list = array_map('trim', explode(',', $types_string));

    $is_edit = ($volunteer_data && ! empty($volunteer_data->id));

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 24px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" style="text-decoration:none; color:#004b23; font-weight:700;">← Torna all'elenco volontari</a>
            <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:8px 0 0 0;">
                <?php echo $is_edit ? 'Modifica Volontario: ' . esc_html($volunteer_data->first_name . ' ' . $volunteer_data->last_name) : 'Aggiungi Nuovo Volontario FAI'; ?>
            </h1>
        </header>

        <?php if (! empty($source_message)) : ?>
            <div class="notice notice-info" style="border-left-color:#0284c7; margin-bottom:20px;">
                <p>ℹ️ <?php echo wp_kses_post($source_message); ?> Verifica i dettagli e clicca su <strong>Salva Volontario</strong> in fondo per confermare.</p>
            </div>
        <?php endif; ?>

        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:24px 28px; max-width:800px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <form method="post" action="" id="dfn-volunteer-edit-form">
                <?php wp_nonce_field('dfn_save_volunteer_nonce'); ?>

                <!-- SELETTORE UTENTE WORDPRESS REGISTRATO (CON AUTOFILL) -->
                <div style="background:#f8fafc; border:1.5px solid #cbd5e1; border-radius:8px; padding:16px; margin-bottom:24px;">
                    <label style="display:block; font-size:13px; font-weight:700; color:#0f172a; margin-bottom:6px;">
                        👤 Collega Utente WordPress Registrato <?php dfn_tooltip_icon('dfn-tip-vol-user', 'Informazioni: Account Utente'); ?>
                    </label>
                    <select name="user_id" id="dfn-volunteer-user-select" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px; font-size:13px;">
                        <option value="0">-- Seleziona un utente per autocompilare o collegare --</option>
                        <?php foreach ($wp_users as $u) : 
                            $u_fai = $fai_by_uid[$u->ID] ?? ($fai_by_email[strtolower(trim($u->user_email))] ?? null);
                            $u_phone = $u_fai ? ($u_fai->phone ?: '') : (get_user_meta($u->ID, 'billing_phone', true) ?: get_user_meta($u->ID, 'phone', true) ?: '');
                            $u_card  = $u_fai ? ($u_fai->card_number ?: '') : '';
                            $u_exp   = $u_fai ? ($u_fai->card_expiry ?: '') : '';
                            $u_type  = $u_fai ? ($u_fai->card_type ?: 'INDIVIDUALE') : 'INDIVIDUALE';
                            $u_sivol = $u_fai && ! empty($u_fai->is_sivol_registered) ? '1' : '0';
                            $u_guide = $u_fai && ! empty($u_fai->is_guide) ? '1' : '0';
                            $u_safe  = $u_fai && ! empty($u_fai->has_safety_course) ? '1' : '0';
                            $u_notes = $u_fai ? ($u_fai->volunteer_notes ?: '') : '';
                            $is_user_vol = in_array('dfn_volunteer', (array) $u->roles, true);
                        ?>
                            <option value="<?php echo esc_attr($u->ID); ?>"
                                data-firstname="<?php echo esc_attr($u_fai && $u_fai->first_name ? $u_fai->first_name : ($u->first_name ?: $u->display_name)); ?>"
                                data-lastname="<?php echo esc_attr($u_fai && $u_fai->last_name ? $u_fai->last_name : ($u->last_name ?: '')); ?>"
                                data-email="<?php echo esc_attr($u->user_email); ?>"
                                data-phone="<?php echo esc_attr($u_phone); ?>"
                                data-card="<?php echo esc_attr($u_card); ?>"
                                data-expiry="<?php echo esc_attr($u_exp); ?>"
                                data-type="<?php echo esc_attr($u_type); ?>"
                                data-sivol="<?php echo esc_attr($u_sivol); ?>"
                                data-guide="<?php echo esc_attr($u_guide); ?>"
                                data-safety="<?php echo esc_attr($u_safe); ?>"
                                data-notes="<?php echo esc_attr($u_notes); ?>"
                                <?php selected($selected_user_id, $u->ID); ?>>
                                <?php echo esc_html($u->display_name . ' (' . $u->user_email . ')' . ($is_user_vol ? ' — [Già Volontario]' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="font-size:11.5px; color:#64748b; margin:6px 0 0 0;">
                        💡 Selezionando un utente registrato, i campi anagrafici e la tessera (se presente) <strong>si compileranno automaticamente</strong>. L'utente riceverà l'accesso all'area Volontari nel suo account.
                    </p>
                </div>

                <h3 style="font-size:15px; font-weight:700; color:#1d2327; margin-top:0; border-bottom:1px solid #f0f0f1; padding-bottom:8px;">
                    👤 Dati Anagrafici &amp; Contatti
                </h3>
                
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Nome <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="first_name" id="dfn-field-first-name" required value="<?php echo esc_attr($volunteer_data ? $volunteer_data->first_name : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Cognome <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="last_name" id="dfn-field-last-name" required value="<?php echo esc_attr($volunteer_data ? $volunteer_data->last_name : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Email <span style="color:#ef4444;">*</span></label>
                        <input type="email" name="email" id="dfn-field-email" required value="<?php echo esc_attr($volunteer_data ? $volunteer_data->email : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Telefono</label>
                        <input type="text" name="phone" id="dfn-field-phone" value="<?php echo esc_attr($volunteer_data ? ($volunteer_data->phone ?: '') : ''); ?>" placeholder="+39 333 1234567" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                </div>

                <h3 style="font-size:15px; font-weight:700; color:#1d2327; border-bottom:1px solid #f0f0f1; padding-bottom:8px;">
                    💳 Dettagli Tessera FAI (Opzionale) &amp; Piattaforma SiVol
                </h3>

                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:16px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Numero Tessera</label>
                        <input type="text" name="card_number" id="dfn-field-card-number" value="<?php echo esc_attr($volunteer_data ? ($volunteer_data->card_number ?: '') : ''); ?>" placeholder="Es. 12345678 (o lascia vuoto)" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">ID Anagrafica (SiVol / App FAI)</label>
                        <input type="text" name="fai_registry_id" id="dfn-field-fai-registry-id" value="<?php echo esc_attr($volunteer_data && ! empty($volunteer_data->fai_registry_id) ? $volunteer_data->fai_registry_id : ''); ?>" placeholder="Es. 4312492" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Scadenza Tessera</label>
                        <input type="date" name="card_expiry" id="dfn-field-card-expiry" value="<?php echo esc_attr($volunteer_data && ! empty($volunteer_data->card_expiry) ? $volunteer_data->card_expiry : ''); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Tipologia Tessera</label>
                    <select name="card_type" id="dfn-field-card-type" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px;">
                        <?php foreach ($types_list as $t) : ?>
                            <option value="<?php echo esc_attr($t); ?>" <?php selected($volunteer_data ? ($volunteer_data->card_type ?: '') : '', $t); ?>>
                                <?php echo esc_html($t); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Checkbox SiVol in Modifica/Aggiunta Volontario -->
                <div style="background:#f0fdf4; border:1.5px solid #86efac; border-radius:8px; padding:14px 16px; margin-bottom:20px;">
                    <label style="display:flex; align-items:flex-start; gap:12px; cursor:pointer;">
                        <input type="checkbox" name="is_sivol_registered" id="dfn-field-is-sivol" value="1" <?php checked($volunteer_data && ! empty($volunteer_data->is_sivol_registered), true); ?> style="width:20px; height:20px; margin-top:2px; accent-color:#004b23;">
                        <div>
                            <strong style="font-size:13.5px; color:#14532d; display:block;">🌐 Registrato su SiVol FAI</strong>
                            <span style="font-size:12px; color:#166534; line-height:1.4; display:block; margin-top:2px;">
                                Indica se l'anagrafica del volontario è stata registrata e verificata sul portale gestionale nazionale SiVol FAI.
                            </span>
                        </div>
                    </label>
                </div>

                <h3 style="font-size:15px; font-weight:700; color:#1d2327; border-bottom:1px solid #f0f0f1; padding-bottom:8px; margin-top:20px;">
                    🎯 Competenze &amp; Ruoli Speciali per i Turni <?php dfn_tooltip_icon('dfn-tip-vol-roles-info', 'Informazioni: Competenze e Ruoli'); ?>
                </h3>

                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px; display:flex; flex-direction:column; gap:12px;">
                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="is_guide" id="dfn-field-is-guide" value="1" <?php checked($volunteer_data && ! empty($volunteer_data->is_guide), true); ?> style="width:18px; height:18px; margin-top:2px;">
                        <div>
                            <strong style="font-size:13px; color:#0f172a; display:block;">🏛️ Volontario Guida</strong>
                            <span style="font-size:12px; color:#64748b;">Abilita e suggerisce automaticamente questo volontario quando si assegna la mansione di Guida durante gli eventi e le visite.</span>
                        </div>
                    </label>

                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="has_safety_course" id="dfn-field-has-safety" value="1" <?php checked($volunteer_data && ! empty($volunteer_data->has_safety_course), true); ?> style="width:18px; height:18px; margin-top:2px;">
                        <div>
                            <strong style="font-size:13px; color:#0f172a; display:block;">🦺 Corso sulla Sicurezza Attivo</strong>
                            <span style="font-size:12px; color:#64748b;">Requisito obbligatorio per ricoprire il ruolo di <strong>Responsabile Scuola</strong> (con gli Apprendisti Ciceroni) nelle Giornate FAI.</span>
                        </div>
                    </label>
                </div>

                <!-- SEZIONE INCARICHI DI DELEGAZIONE & RUOLI AMMINISTRATIVI -->
                <?php
                $all_stored_roles = function_exists('dfn_get_stored_roles') ? dfn_get_stored_roles() : [];
                $linked_user_id = $selected_user_id ?: ($volunteer_data && ! empty($volunteer_data->user_id) ? (int) $volunteer_data->user_id : 0);
                $user_assigned_fai = $linked_user_id > 0 ? (array) get_user_meta($linked_user_id, '_dfn_assigned_fai_roles', true) : [];
                if ($linked_user_id > 0 && empty($user_assigned_fai)) {
                    $u_obj = get_userdata($linked_user_id);
                    if ($u_obj) {
                        $user_assigned_fai = (array) $u_obj->roles;
                    }
                }
                ?>
                <h3 style="font-size:15px; font-weight:700; color:#1d2327; border-bottom:1px solid #f0f0f1; padding-bottom:8px; margin-top:20px;">
                    🛡️ Incarichi di Delegazione &amp; Ruoli Amministrativi
                </h3>
                <p style="font-size:12px; color:#64748b; margin-top:4px; margin-bottom:12px;">
                    Se il volontario ricopre ruoli organizzativi nella delegazione (es. <em>Coordinatore Volontari</em>, <em>Delegato Scuole</em>, <em>Banchetto</em>), selezionali qui sotto per sbloccare le relative funzionalità nell'area gestionale.
                </p>

                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px; display:flex; flex-direction:column; gap:10px;">
                    <?php 
                    foreach ($all_stored_roles as $r_slug => $r_info) : 
                        if ($r_slug === 'administrator' || $r_slug === 'dfn_volunteer') continue;
                        $r_modules = ! empty($r_info['modules']) && is_array($r_info['modules']) ? $r_info['modules'] : (array) ($r_info['module'] ?? []);
                        $is_role_checked = in_array($r_slug, $user_assigned_fai, true);
                    ?>
                        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; padding:8px 10px; border-radius:6px; border:1px solid <?php echo $is_role_checked ? '#86efac' : '#f1f5f9'; ?>; background:<?php echo $is_role_checked ? '#f0fdf4' : '#fafafa'; ?>;">
                            <input type="checkbox" name="fai_roles[]" value="<?php echo esc_attr($r_slug); ?>" <?php checked($is_role_checked, true); ?> style="width:18px; height:18px; margin-top:2px; accent-color:#004b23;">
                            <div>
                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <strong style="font-size:13px; color:#0f172a;"><?php echo esc_html($r_info['label']); ?></strong>
                                    <?php if (in_array('volontari', $r_modules, true)) : ?>
                                        <span style="font-size:10.5px; background:#dcfce7; color:#166534; padding:1px 6px; border-radius:8px; font-weight:600;">👥 Volontari FAI</span>
                                    <?php endif; ?>
                                    <?php if (in_array('prenotazioni', $r_modules, true)) : ?>
                                        <span style="font-size:10.5px; background:#dbeafe; color:#1e40af; padding:1px 6px; border-radius:8px; font-weight:600;">🎟️ FAI Prenotazioni</span>
                                    <?php endif; ?>
                                    <?php if (empty($r_modules)) : ?>
                                        <span style="font-size:10.5px; background:#f1f5f9; color:#64748b; padding:1px 6px; border-radius:8px; font-weight:600;">Nessuna Materia</span>
                                    <?php endif; ?>
                                </div>
                                <span style="font-size:11.5px; color:#64748b; display:block; margin-top:2px;"><?php echo esc_html($r_info['description']); ?></span>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

                <?php if ($linked_user_id > 0) : 
                    $admin_wants_expiry   = function_exists('dfn_user_wants_card_expiry_notification') ? dfn_user_wants_card_expiry_notification($linked_user_id) : true;
                    $admin_wants_meetings = function_exists('dfn_user_wants_meetings_notification') ? dfn_user_wants_meetings_notification($linked_user_id) : true;
                    $admin_wants_shifts   = function_exists('dfn_user_wants_shifts_notification') ? dfn_user_wants_shifts_notification($linked_user_id) : true;
                ?>
                    <input type="hidden" name="dfn_admin_notif_prefs_present" value="1">
                    <h3 style="font-size:15px; font-weight:700; color:#1d2327; border-bottom:1px solid #f0f0f1; padding-bottom:8px; margin-top:20px;">
                        🔔 Preferenze Notifiche Email Volontario
                    </h3>
                    <p style="font-size:12px; color:#64748b; margin-top:4px; margin-bottom:12px;">
                        Stato delle preferenze di notifica email configurate dal volontario nella sua area riservata.
                    </p>

                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:20px; display:flex; flex-direction:column; gap:10px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="dfn_notify_card_expiry" value="1" <?php checked($admin_wants_expiry, true); ?> style="width:16px; height:16px; accent-color:#004b23;">
                            <span style="font-size:13px; color:#1e293b; font-weight:600;">🪪 Notifica Promemoria Scadenza Tessera FAI</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="dfn_notify_meetings" value="1" <?php checked($admin_wants_meetings, true); ?> style="width:16px; height:16px; accent-color:#004b23;">
                            <span style="font-size:13px; color:#1e293b; font-weight:600;">📅 Convocazioni e Promemoria Riunioni di Delegazione</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="dfn_notify_shifts" value="1" <?php checked($admin_wants_shifts, true); ?> style="width:16px; height:16px; accent-color:#004b23;">
                            <span style="font-size:13px; color:#1e293b; font-weight:600;">📍 Notifiche Turni Assegnati e Aggiornamenti Eventi</span>
                        </label>
                    </div>
                <?php endif; ?>

                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Note Delegazione / Disponibilità</label>
                    <textarea name="notes" id="dfn-field-notes" rows="3" placeholder="Es. Disponibile per visite guidate nei weekend, accoglienza banchetto..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px;"><?php echo esc_textarea($volunteer_data ? ($volunteer_data->volunteer_notes ?: '') : ''); ?></textarea>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f0f0f1; padding-top:16px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteers')); ?>" class="button">Annulla</a>
                    <button type="submit" name="dfn_save_volunteer" class="button button-primary" style="background:#004b23; border-color:#003b1c; padding:4px 18px; font-weight:700;">
                        <?php echo $is_edit ? '💾 Salva Modifiche Volontario' : '➕ Salva &amp; Attiva Volontario'; ?>
                    </button>
                </div>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var userSelect = document.getElementById('dfn-volunteer-user-select');
            if (!userSelect) return;

            userSelect.addEventListener('change', function() {
                var opt = this.options[this.selectedIndex];
                if (!opt || opt.value === '0') return;

                var fnInput = document.getElementById('dfn-field-first-name');
                var lnInput = document.getElementById('dfn-field-last-name');
                var emInput = document.getElementById('dfn-field-email');
                var phInput = document.getElementById('dfn-field-phone');
                var cdInput = document.getElementById('dfn-field-card-number');
                var exInput = document.getElementById('dfn-field-card-expiry');
                var tpInput = document.getElementById('dfn-field-card-type');
                var svInput = document.getElementById('dfn-field-is-sivol');
                var gdInput = document.getElementById('dfn-field-is-guide');
                var sfInput = document.getElementById('dfn-field-has-safety');
                var ntInput = document.getElementById('dfn-field-notes');

                if (opt.getAttribute('data-firstname')) fnInput.value = opt.getAttribute('data-firstname');
                if (opt.getAttribute('data-lastname')) lnInput.value = opt.getAttribute('data-lastname');
                if (opt.getAttribute('data-email')) emInput.value = opt.getAttribute('data-email');
                if (opt.getAttribute('data-phone')) phInput.value = opt.getAttribute('data-phone');
                if (opt.getAttribute('data-card')) cdInput.value = opt.getAttribute('data-card');
                if (opt.getAttribute('data-expiry')) exInput.value = opt.getAttribute('data-expiry');
                if (opt.getAttribute('data-type') && tpInput) tpInput.value = opt.getAttribute('data-type');
                if (svInput) svInput.checked = (opt.getAttribute('data-sivol') === '1');
                if (gdInput && opt.getAttribute('data-guide') === '1') gdInput.checked = true;
                if (sfInput && opt.getAttribute('data-safety') === '1') sfInput.checked = true;
                if (ntInput && opt.getAttribute('data-notes') && !ntInput.value) ntInput.value = opt.getAttribute('data-notes');
            });
        });
        </script>

        <!-- Overlay e Tooltip Modals Form Volontario -->
        <div class="dfn-tooltip-overlay" id="dfn-tooltip-overlay"></div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-user" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-user-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-user-title">👤 Collegamento Utente WordPress</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Selezionando un account utente registrato sul sito, il sistema abiliterà automaticamente il <strong>menu Volontari</strong> all'interno della sua area personale (<em>/mio-account/</em>).</p>
                <p>In questo modo il volontario potrà:</p>
                <ul>
                    <li>Compilare i <strong>sondaggi di disponibilità oraria</strong> per le Giornate FAI;</li>
                    <li>Consultare i <strong>turni e i luoghi</strong> a lui assegnati dopo la pubblicazione;</li>
                    <li>Vedere i colleghi di turno e l'ordine del giorno delle <strong>riunioni di delegazione</strong>.</li>
                </ul>
            </div>
        </div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-roles-info" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-roles-info-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-roles-info-title">🎯 Competenze e Formazione</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Le competenze inserite permettono all'algoritmo di allocazione automatica di distribuire i volontari in modo corretto ed efficiente:</p>
                <ul>
                    <li><strong>Guida:</strong> garantisce la presenza di volontari qualificati per l'illustrazione dei beni storici.</li>
                    <li><strong>Corso Sicurezza:</strong> soddisfa i requisiti normativi per i referenti dei gruppi scuola e minori.</li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Renderizza la schermata "Riunioni di Delegazione".
 */
function dfn_render_volunteer_meetings_admin_page(): void
{
    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_meetings'))) {
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
            $inserted_meeting_id = $wpdb->insert_id;
            if (function_exists('dfn_log_volunteer_meeting')) {
                dfn_log_volunteer_meeting($inserted_meeting_id, 'Programmata nuova riunione di delegazione', "Titolo: {$title} | Data: {$meeting_date} ore {$time_start} | Sede: {$location}");
            }
            echo '<div class="notice notice-success is-dismissible"><p>✅ Nuova riunione programmata con successo!</p></div>';
        }
    }

    // Cancellazione riunione
    if (isset($_GET['action'], $_GET['meeting_id'], $_GET['_wpnonce']) && $_GET['action'] === 'delete') {
        $m_id = (int) $_GET['meeting_id'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_meeting_action_' . $m_id)) {
            $wpdb->delete($table_meetings, ['id' => $m_id], ['%d']);
            if (function_exists('dfn_log_volunteer_meeting')) {
                dfn_log_volunteer_meeting($m_id, 'Riunione di delegazione eliminata', "ID riunione #{$m_id}");
            }
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

/**
 * Renderizza la schermata di gestione "Mansioni & Ruoli Volontari".
 */
function dfn_render_volunteer_roles_admin_page(): void
{
    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_roles'))) {
        wp_die(__('Permessi insufficienti per accedere a questa sezione.', 'dfn-theme'));
    }

    global $wpdb;
    $table_roles = $wpdb->prefix . 'dfn_volunteer_roles';

    // Gestione Eliminazione
    if (isset($_GET['action'], $_GET['role_id'], $_GET['_wpnonce']) && $_GET['action'] === 'delete') {
        $role_id = (int) $_GET['role_id'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_role_' . $role_id)) {
            $wpdb->delete($table_roles, ['id' => $role_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Mansione eliminata con successo.</p></div>';
        }
    }

    // Gestione Salvataggio (Nuovo / Modifica)
    if (isset($_POST['dfn_save_role']) && check_admin_referer('dfn_save_role_action', 'dfn_save_role_nonce')) {
        $role_id   = ! empty($_POST['role_id']) ? (int) $_POST['role_id'] : 0;
        $role_name = sanitize_text_field($_POST['role_name'] ?? '');
        $role_key  = ! empty($_POST['role_key']) ? sanitize_title($_POST['role_key']) : sanitize_title($role_name);
        $badge_code= sanitize_text_field($_POST['badge_code'] ?? '');
        $badge_color = sanitize_hex_color($_POST['badge_color'] ?? '#475569') ?: '#475569';
        $badge_bg    = sanitize_hex_color($_POST['badge_bg'] ?? '#f1f5f9') ?: '#f1f5f9';
        $req_safety  = ! empty($_POST['requires_safety_course']) ? 1 : 0;
        $req_guide   = ! empty($_POST['requires_guide']) ? 1 : 0;
        $is_default  = ! empty($_POST['is_default']) ? 1 : 0;

        if (! empty($role_name) && ! empty($role_key)) {
            if ($role_id > 0) {
                $wpdb->update(
                    $table_roles,
                    [
                        'role_key'              => $role_key,
                        'role_name'             => $role_name,
                        'badge_code'            => $badge_code,
                        'badge_color'           => $badge_color,
                        'badge_bg'              => $badge_bg,
                        'requires_safety_course'=> $req_safety,
                        'requires_guide'        => $req_guide,
                        'is_default'            => $is_default,
                    ],
                    ['id' => $role_id],
                    ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d'],
                    ['%d']
                );
                echo '<div class="notice notice-success is-dismissible"><p>✅ Mansione aggiornata con successo.</p></div>';
            } else {
                $wpdb->insert(
                    $table_roles,
                    [
                        'role_key'              => $role_key,
                        'role_name'             => $role_name,
                        'badge_code'            => $badge_code,
                        'badge_color'           => $badge_color,
                        'badge_bg'              => $badge_bg,
                        'requires_safety_course'=> $req_safety,
                        'requires_guide'        => $req_guide,
                        'is_default'            => $is_default,
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
                );
                echo '<div class="notice notice-success is-dismissible"><p>✅ Nuova mansione creata con successo.</p></div>';
            }
        }
    }

    $edit_role = null;
    if (isset($_GET['action'], $_GET['role_id']) && $_GET['action'] === 'edit') {
        $edit_role = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_roles} WHERE id = %d", (int) $_GET['role_id']));
    }

    $all_roles = $wpdb->get_results("SELECT * FROM {$table_roles} ORDER BY is_default DESC, role_name ASC");

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom:20px;">
            <h1 style="font-size:24px; font-weight:700; color:#1d2327;">🏷️ Gestione Mansioni &amp; Ruoli Volontari <?php dfn_tooltip_icon('dfn-tip-vol-roles-page', 'Guida alla Gestione Mansioni'); ?></h1>
            <p style="color:#64748b; margin-top:4px;">Crea e personalizza le mansioni operative da associare agli eventi della delegazione (standard o personalizzate per singoli eventi).</p>
        </header>

        <div style="display:grid; grid-template-columns:360px 1fr; gap:24px; align-items:start;">
            <!-- Form Creazione / Modifica Ruolo -->
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin-top:0; font-size:16px; color:#004b23; font-weight:700;">
                    <?php echo $edit_role ? '✏️ Modifica Mansione' : '➕ Nuova Mansione'; ?>
                </h3>

                <form method="post" action="">
                    <?php wp_nonce_field('dfn_save_role_action', 'dfn_save_role_nonce'); ?>
                    <input type="hidden" name="role_id" value="<?php echo esc_attr($edit_role ? $edit_role->id : 0); ?>">

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Nome Mansione <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="role_name" required value="<?php echo esc_attr($edit_role ? $edit_role->role_name : ''); ?>" placeholder="Es. Addetto Bar, Controllo Braccialetti..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:13px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Codice Chiave (Slug univoco)</label>
                        <input type="text" name="role_key" value="<?php echo esc_attr($edit_role ? $edit_role->role_key : ''); ?>" placeholder="Es. addetto_bar, controllo_braccialetti" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:13px;">
                        <span style="font-size:11px; color:#94a3b8;">Lascia vuoto per generarlo automaticamente dal nome.</span>
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Etichetta Badge / Sigla Sintetica <?php dfn_tooltip_icon('dfn-tip-vol-role-badge', 'Guida sul Badge'); ?></label>
                        <input type="text" name="badge_code" value="<?php echo esc_attr($edit_role ? $edit_role->badge_code : ''); ?>" placeholder="Es. (S), (R), (G), 🍹 Bar, 🎟️ Pass" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:13px;">
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Colore Testo Badge</label>
                            <input type="color" name="badge_color" value="<?php echo esc_attr($edit_role ? $edit_role->badge_color : '#1e293b'); ?>" style="width:100%; height:34px; border-radius:6px; border:1px solid #cbd5e1; padding:2px; cursor:pointer;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Colore Sfondo Badge</label>
                            <input type="color" name="badge_bg" value="<?php echo esc_attr($edit_role ? $edit_role->badge_bg : '#f1f5f9'); ?>" style="width:100%; height:34px; border-radius:6px; border:1px solid #cbd5e1; padding:2px; cursor:pointer;">
                        </div>
                    </div>

                    <div style="margin-bottom:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#334155; font-weight:600; cursor:pointer; margin-bottom:6px;">
                            <input type="checkbox" name="requires_safety_course" value="1" <?php checked($edit_role && ! empty($edit_role->requires_safety_course)); ?>>
                            🦺 Richiede Corso Sicurezza
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#334155; font-weight:600; cursor:pointer; margin-bottom:6px;">
                            <input type="checkbox" name="requires_guide" value="1" <?php checked($edit_role && ! empty($edit_role->requires_guide)); ?>>
                            🏛️ Profilo Guida Culturale
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#334155; font-weight:600; cursor:pointer;">
                            <input type="checkbox" name="is_default" value="1" <?php checked($edit_role && ! empty($edit_role->is_default)); ?>>
                            ⭐ Selezionata di default nei nuovi eventi
                        </label>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
                        <?php if ($edit_role) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-roles')); ?>" class="button">Annulla</a>
                        <?php else : ?>
                            <span></span>
                        <?php endif; ?>
                        <button type="submit" name="dfn_save_role" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700;">
                            <?php echo $edit_role ? '💾 Aggiorna Mansione' : '➕ Salva Mansione'; ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Tabella Mansioni Esistenti -->
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="font-weight:700; color:#1d2327;">Mansione</th>
                            <th style="font-weight:700; color:#1d2327;">Chiave (Slug)</th>
                            <th style="font-weight:700; color:#1d2327;">Anteprima Badge</th>
                            <th style="font-weight:700; color:#1d2327;">Requisiti &amp; Default</th>
                            <th style="width:120px; text-align:right; font-weight:700; color:#1d2327;">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (! empty($all_roles)) : ?>
                            <?php foreach ($all_roles as $r) : 
                                $edit_url = admin_url('admin.php?page=dfn-volunteer-roles&action=edit&role_id=' . $r->id);
                                $del_url  = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-roles&action=delete&role_id=' . $r->id), 'dfn_del_role_' . $r->id);
                            ?>
                                <tr>
                                    <td>
                                        <strong style="font-size:13.5px; color:#0f172a;"><?php echo esc_html($r->role_name); ?></strong>
                                    </td>
                                    <td>
                                        <code style="font-size:11.5px; color:#475569;"><?php echo esc_html($r->role_key); ?></code>
                                    </td>
                                    <td>
                                        <span style="display:inline-block; font-size:11px; font-weight:800; background:<?php echo esc_attr($r->badge_bg); ?>; color:<?php echo esc_attr($r->badge_color); ?>; padding:3px 10px; border-radius:14px;">
                                            <?php echo esc_html($r->badge_code ?: $r->role_name); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?php if (! empty($r->requires_safety_course)) : ?>
                                            <span style="display:inline-block; background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:4px; font-weight:700; margin-right:4px;">🦺 Sicurezza</span>
                                        <?php endif; ?>
                                        <?php if (! empty($r->requires_guide)) : ?>
                                            <span style="display:inline-block; background:#e0f2fe; color:#0369a1; padding:1px 6px; border-radius:4px; font-weight:700; margin-right:4px;">🏛️ Guida</span>
                                        <?php endif; ?>
                                        <?php if (! empty($r->is_default)) : ?>
                                            <span style="display:inline-block; background:#dcfce7; color:#15803d; padding:1px 6px; border-radius:4px; font-weight:700;">⭐ Default</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right; white-space:nowrap;">
                                        <div style="display:inline-flex; align-items:center; justify-content:flex-end; gap:6px;">
                                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small" style="padding:0 7px; height:28px; line-height:26px; font-size:13px;" title="Modifica Mansione">
                                                ✏️
                                            </a>
                                            <a href="<?php echo esc_url($del_url); ?>" class="button button-small" style="color:#b91c1c; padding:0 7px; height:28px; line-height:26px; font-size:13px;" onclick="return confirm('Eliminare questa mansione?');" title="Elimina Mansione">
                                                🗑️
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;">
                                    Nessuna mansione configurata.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modali Tooltip -->
        <div class="dfn-tooltip-overlay" id="dfn-tooltip-overlay"></div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-roles-page" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-roles-page-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-roles-page-title">🏷️ Mansioni Operative Volontari</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Le <strong>Mansioni Operative</strong> definiscono i compiti specifici assegnati ai volontari durante un evento o una Giornata FAI (es. <em>Guida, Accoglienza, Responsabile Scuola, Banchetto</em>).</p>
                <p>Ogni mansione ha un colore di badge personalizzato e può richiedere specifiche abilitazioni, come il <strong>Corso di Sicurezza</strong> o il profilo <strong>Guida Culturale</strong>.</p>
            </div>
        </div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-role-badge" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-role-badge-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-role-badge-title">🏷️ Etichetta Badge &amp; Colori</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>L'<strong>Etichetta Badge</strong> è la sigla sintetica o il testo compatto che appare nella <strong>Matrice Turni</strong> e nella <strong>Bacheca Volontario</strong> a front-end.</p>
                <p>I colori di sfondo e del testo consentono di distinguere a colpo d'occhio i diversi incarichi nella pianificazione e sui report PDF.</p>
            </div>
        </div>

    </div>
    <?php
}


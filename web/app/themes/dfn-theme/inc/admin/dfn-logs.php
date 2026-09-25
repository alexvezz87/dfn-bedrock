<?php
/**
 * DFN Booking System 2.0 & Volontari FAI — Pannello Admin: Log di Sistema & Volontari
 *
 * Visualizza il registro centralizzato delle azioni eseguite dal sistema
 * con supporto per viste dedicate (Prenotazioni, Volontari, Globale), filtri,
 * statistiche KPI in tempo reale e manutenzione.
 *
 * @package DFN_Theme
 * @since   2.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_logs_register_admin_menus', 999);

/**
 * Registra i sottomenu dei log sia in FAI Prenotazioni che in Volontari FAI.
 */
function dfn_logs_register_admin_menus(): void
{
    // Sottomenu 1: Log di Sistema in FAI Prenotazioni (se il modulo prenotazioni è attivo)
    if (function_exists('dfn_is_module_active') && dfn_is_module_active('prenotazioni')) {
        add_submenu_page(
            'dfn-events',
            __('Log di Sistema', 'dfn-theme'),
            __('Log di Sistema', 'dfn-theme'),
            'dfn_act_system_logs',
            'dfn-logs',
            'dfn_render_logs_page'
        );
    }

    // Sottomenu 2: Log Volontari in Volontari FAI (se il modulo volontari è attivo)
    if (function_exists('dfn_is_module_active') && dfn_is_module_active('volontari')) {
        $has_vol_access = function_exists('dfn_user_has_module_access') && dfn_user_has_module_access('volontari');
        $cap_vol_logs   = ($has_vol_access || current_user_can('manage_options') || current_user_can('dfn_act_vol_logs') || current_user_can('dfn_act_system_logs')) ? 'read' : 'dfn_act_vol_roster';

        add_submenu_page(
            'dfn-volunteers',
            __('Log Volontari', 'dfn-theme'),
            __('Log Volontari', 'dfn-theme'),
            $cap_vol_logs,
            'dfn-volunteer-logs',
            'dfn_render_volunteer_logs_page'
        );
    }
}

/**
 * Renderizza la schermata Log di FAI Prenotazioni.
 */
function dfn_render_logs_page(): void
{
    dfn_render_unified_logs_page('prenotazioni');
}

/**
 * Renderizza la schermata Log di Volontari FAI.
 */
function dfn_render_volunteer_logs_page(): void
{
    dfn_render_unified_logs_page('volontari');
}

/**
 * Renderizza la pagina unificata dei log con selezione automatica del modulo.
 *
 * @param string $default_module Modulo predefinito ('prenotazioni' o 'volontari').
 */
function dfn_render_unified_logs_page(string $default_module = 'prenotazioni'): void
{
    if (! is_user_logged_in() || ! current_user_can('read')) {
        wp_die(__('Permessi insufficienti per accedere a questa sezione.', 'dfn-theme'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_logs';

    // Self-healing: crea la tabella se non esiste ancora
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        if (function_exists('dfn_db_install')) {
            dfn_db_install();
        }
    }

    $current_page_slug = sanitize_key($_GET['page'] ?? ($default_module === 'volontari' ? 'dfn-volunteer-logs' : 'dfn-logs'));
    $active_module_param = isset($_GET['module']) ? sanitize_key($_GET['module']) : ($current_page_slug === 'dfn-volunteer-logs' ? 'volontari' : $default_module);
    $active_module       = in_array($active_module_param, ['prenotazioni', 'volontari', 'all'], true) ? $active_module_param : $default_module;

    // --- Azioni manuali di pulizia ---
    if (isset($_POST['dfn_log_action']) && check_admin_referer('dfn_log_action_nonce')) {
        $action = sanitize_text_field($_POST['dfn_log_action']);
        if ($action === 'purge_all' && current_user_can('manage_options')) {
            $wpdb->query("TRUNCATE TABLE {$table}");
            echo '<div class="notice notice-success is-dismissible"><p>✅ Tutti i log sono stati eliminati.</p></div>';
        } elseif ($action === 'purge_days' && current_user_can('manage_options')) {
            $days = max(1, (int) ($_POST['purge_days'] ?? 30));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            echo '<div class="notice notice-success is-dismissible"><p>✅ Log più vecchi di ' . intval($days) . ' giorni eliminati.</p></div>';
        }
    }

    // --- Definizione cataloghi tipologie ---
    $all_catalog = function_exists('dfn_get_log_types_catalog') ? dfn_get_log_types_catalog('all') : [];
    $pren_types  = ['prenotazione', 'recensione', 'annullamento', 'tessera_fai', 'checkin', 'spostamento', 'stock'];
    $vol_types   = ['volontario_anagrafica', 'volontario_turni', 'volontario_sondaggi', 'volontario_riunioni'];
    $sys_types   = ['email', 'login', 'logout', 'sicurezza', 'profilo', 'sistema'];

    // --- Parametri filtro ---
    $filter_type    = isset($_GET['filter_type'])    ? sanitize_text_field($_GET['filter_type'])    : '';
    $filter_outcome = isset($_GET['filter_outcome']) ? sanitize_text_field($_GET['filter_outcome']) : '';
    $filter_date    = isset($_GET['filter_date'])    ? sanitize_text_field($_GET['filter_date'])    : '';
    $filter_search  = isset($_GET['filter_search'])  ? sanitize_text_field($_GET['filter_search'])  : '';
    $allowed_per_page = [20, 50, 100];
    $req_per_page     = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
    $per_page         = in_array($req_per_page, $allowed_per_page, true) ? $req_per_page : 20;
    $current_page   = max(1, (int) ($_GET['paged'] ?? 1));
    $offset         = ($current_page - 1) * $per_page;

    // --- Costruzione clausola WHERE ---
    $where  = '1=1';
    $params = [];

    // Filtro per tipologia specifica
    if ($filter_type !== '') {
        $where .= ' AND type = %s';
        $params[] = $filter_type;
    } else {
        // Se non è selezionata una singola tipologia, applichiamo lo scope del modulo
        if ($active_module === 'prenotazioni') {
            $allowed_scope = array_merge($pren_types, $sys_types);
            $in_placeholders = implode(',', array_fill(0, count($allowed_scope), '%s'));
            $where .= " AND type IN ($in_placeholders)";
            $params = array_merge($params, $allowed_scope);
        } elseif ($active_module === 'volontari') {
            $allowed_scope = array_merge($vol_types, $sys_types);
            $in_placeholders = implode(',', array_fill(0, count($allowed_scope), '%s'));
            $where .= " AND type IN ($in_placeholders)";
            $params = array_merge($params, $allowed_scope);
        }
    }

    if ($filter_outcome !== '') {
        $where .= ' AND outcome = %s';
        $params[] = $filter_outcome;
    }
    if ($filter_date !== '') {
        $where .= ' AND DATE(logged_at) = %s';
        $params[] = $filter_date;
    }
    if ($filter_search !== '') {
        $like = '%' . $wpdb->esc_like($filter_search) . '%';
        $where .= ' AND (description LIKE %s OR executor LIKE %s)';
        $params[] = $like;
        $params[] = $like;
    }

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $data_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY logged_at DESC LIMIT %d OFFSET %d";

    if (! empty($params)) {
        $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        $logs       = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, [$per_page, $offset])));
    } else {
        $total_rows = (int) $wpdb->get_var($count_sql);
        $logs       = $wpdb->get_results($wpdb->prepare($data_sql, $per_page, $offset));
    }

    $total_pages = ($per_page > 0 && $total_rows > 0) ? (int) ceil($total_rows / $per_page) : 1;
    $base_url    = admin_url('admin.php?page=' . urlencode($current_page_slug));

    // Statistiche rapide calcolate in base al modulo selezionato
    $stat_scope_where = '1=1';
    $stat_params      = [];
    if ($active_module === 'prenotazioni') {
        $scope_types = array_merge($pren_types, $sys_types);
        $stat_scope_where .= " AND type IN (" . implode(',', array_fill(0, count($scope_types), '%s')) . ")";
        $stat_params = $scope_types;
    } elseif ($active_module === 'volontari') {
        $scope_types = array_merge($vol_types, $sys_types);
        $stat_scope_where .= " AND type IN (" . implode(',', array_fill(0, count($scope_types), '%s')) . ")";
        $stat_params = $scope_types;
    }

    if (! empty($stat_params)) {
        $stats_success = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE outcome = 'success' AND {$stat_scope_where}", $stat_params));
        $stats_failure = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE outcome = 'failure' AND {$stat_scope_where}", $stat_params));
        $stats_today   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE DATE(logged_at) = CURDATE() AND {$stat_scope_where}", $stat_params));
    } else {
        $stats_success = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'success'");
        $stats_failure = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'failure'");
        $stats_today   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(logged_at) = CURDATE()");
    }
    $stats_total = $stats_success + $stats_failure;

    // Titoli e Icone contestuali
    $page_title = ($active_module === 'volontari') 
        ? __('Log Attività Volontari FAI', 'dfn-theme')
        : ($active_module === 'prenotazioni' ? __('Log di Sistema & Prenotazioni', 'dfn-theme') : __('Registro Generale dei Log FAI', 'dfn-theme'));
    
    $page_subtitle = ($active_module === 'volontari')
        ? __('Audit trail e storico dettagliato su anagrafica, turni, logistica, sondaggi e riunioni di delegazione.', 'dfn-theme')
        : __('Registro centralizzato delle prenotazioni, biglietti, scanner QR, email ed eventi di sistema.', 'dfn-theme');

    $page_icon = ($active_module === 'volontari') ? 'dashicons-groups' : 'dashicons-list-view';
    ?>
    <div class="wrap dfn-admin-wrap" style="max-width: 1400px; margin-top: 15px;">
        
        <!-- HEADER PRINCIPALE -->
        <header class="dfn-admin-header" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; background: linear-gradient(135deg, #004b23 0%, #002e15 100%); color: #fff; padding: 22px 28px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="background: rgba(255,255,255,0.15); width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    <span class="dashicons <?php echo esc_attr($page_icon); ?>" style="font-size: 28px; width: 28px; height: 28px; color: #fff;"></span>
                </div>
                <div>
                    <h1 style="color: #fff; margin: 0; font-size: 22px; font-weight: 700; display: inline-block;">
                        <?php echo esc_html($page_title); ?>
                    </h1>
                    <p style="margin: 4px 0 0; color: #d1fae5; font-size: 13.5px;">
                        <?php echo esc_html($page_subtitle); ?>
                    </p>
                </div>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-modules')); ?>" class="button button-secondary" style="background: rgba(255,255,255,0.2); color: #ffffff; border: 1px solid rgba(255,255,255,0.4); font-weight: 600; padding: 6px 14px; height: auto;">
                    🎛️ Moduli FAI
                </a>
            </div>
        </header>

        <!-- MODULO SWITCHER TABS -->
        <nav class="nav-tab-wrapper" style="margin-bottom: 20px; border-bottom: 2px solid #004b23;">
            <a href="<?php echo esc_url(add_query_arg(['page' => $current_page_slug, 'module' => 'prenotazioni'], admin_url('admin.php'))); ?>" 
               class="nav-tab <?php echo $active_module === 'prenotazioni' ? 'nav-tab-active' : ''; ?>" 
               style="<?php echo $active_module === 'prenotazioni' ? 'background:#004b23; color:#fff; border-color:#004b23; font-weight:700;' : 'font-weight:600;'; ?>">
                🎟️ Log Prenotazioni &amp; Vendite
            </a>
            <a href="<?php echo esc_url(add_query_arg(['page' => $current_page_slug, 'module' => 'volontari'], admin_url('admin.php'))); ?>" 
               class="nav-tab <?php echo $active_module === 'volontari' ? 'nav-tab-active' : ''; ?>" 
               style="<?php echo $active_module === 'volontari' ? 'background:#004b23; color:#fff; border-color:#004b23; font-weight:700;' : 'font-weight:600;'; ?>">
                👥 Log Volontari &amp; Turni
            </a>
            <a href="<?php echo esc_url(add_query_arg(['page' => $current_page_slug, 'module' => 'all'], admin_url('admin.php'))); ?>" 
               class="nav-tab <?php echo $active_module === 'all' ? 'nav-tab-active' : ''; ?>" 
               style="<?php echo $active_module === 'all' ? 'background:#004b23; color:#fff; border-color:#004b23; font-weight:700;' : 'font-weight:600;'; ?>">
                🌐 Tutti i Log (Globale)
            </a>
        </nav>

        <!-- STATISTICHE CARD DASHBOARD -->
        <div class="dfn-logs-stats-grid">
            <div class="dfn-log-stat-card dfn-log-stat-card--total">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-database"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_total); ?></span>
                    <span class="dfn-log-stat-lbl">Totale Registrati (<?php echo esc_html(strtoupper($active_module)); ?>)</span>
                </div>
            </div>
            <div class="dfn-log-stat-card dfn-log-stat-card--success">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_success); ?></span>
                    <span class="dfn-log-stat-lbl">Operazioni Riuscite</span>
                </div>
            </div>
            <div class="dfn-log-stat-card dfn-log-stat-card--failure">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-dismiss"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_failure); ?></span>
                    <span class="dfn-log-stat-lbl">Errori / Rifiuti / Falliti</span>
                </div>
            </div>
            <div class="dfn-log-stat-card dfn-log-stat-card--today">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_today); ?></span>
                    <span class="dfn-log-stat-lbl">Attività Registrate Oggi</span>
                </div>
            </div>
        </div>

        <!-- FILTRI DI RICERCA -->
        <div class="dfn-card dfn-main-card" style="margin-bottom: 16px; padding: 18px 22px; background: #fff; border-radius: 10px; border: 1px solid #cbd5e1; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <form method="get" action="<?php echo esc_url($base_url); ?>" id="dfn-logs-filter-form">
                <input type="hidden" name="page" value="<?php echo esc_attr($current_page_slug); ?>">
                <input type="hidden" name="module" value="<?php echo esc_attr($active_module); ?>">

                <div class="dfn-logs-filter-row">
                    <label class="dfn-filter-label">Tipologia Evento
                        <select name="filter_type" class="dfn-select-input" onchange="document.getElementById('dfn-logs-filter-form').submit()">
                            <option value="">— Tutte le tipologie (<?php echo esc_html(ucfirst($active_module)); ?>) —</option>
                            <?php 
                            $available_catalog = function_exists('dfn_get_log_types_catalog') ? dfn_get_log_types_catalog($active_module) : [];
                            foreach ($available_catalog as $t_slug => $t_label) : 
                            ?>
                                <option value="<?php echo esc_attr($t_slug); ?>" <?php selected($filter_type, $t_slug); ?>>
                                    <?php echo esc_html($t_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="dfn-filter-label">Esito Operazione
                        <select name="filter_outcome" class="dfn-select-input" onchange="document.getElementById('dfn-logs-filter-form').submit()">
                            <option value="">— Tutti gli esiti —</option>
                            <option value="success" <?php selected($filter_outcome, 'success'); ?>>✅ Successo / Confermato</option>
                            <option value="failure" <?php selected($filter_outcome, 'failure'); ?>>❌ Fallimento / Rifiutato / Errore</option>
                        </select>
                    </label>

                    <label class="dfn-filter-label">Data Evento
                        <input type="date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>" class="dfn-text-input" onchange="document.getElementById('dfn-logs-filter-form').submit()">
                    </label>

                    <label class="dfn-filter-label dfn-filter-wide">Cerca
                        <input type="text" name="filter_search" value="<?php echo esc_attr($filter_search); ?>" placeholder="Cerca per nominativo, email, #ordine, #turno, luogo o esecutore…" class="dfn-text-input">
                    </label>

                    <label class="dfn-filter-label">Righe per pagina
                        <select name="per_page" onchange="document.getElementById('dfn-logs-filter-form').submit()" class="dfn-select-input">
                            <?php foreach ([20, 50, 100] as $opt) : ?>
                                <option value="<?php echo $opt; ?>" <?php selected($per_page, $opt); ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="dfn-filter-actions">
                        <button type="submit" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; height:34px;">
                            🔍 Filtra
                        </button>
                        <a href="<?php echo esc_url(add_query_arg(['page' => $current_page_slug, 'module' => $active_module], admin_url('admin.php'))); ?>" class="button" style="height:34px; line-height:32px;">
                            Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- TABELLA LOG -->
        <div class="dfn-card dfn-main-card" style="background:#fff; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 1px 3px rgba(0,0,0,0.05); overflow:hidden;">
            <div class="dfn-card-header" style="padding:16px 22px; border-bottom:1px solid #f1f5f9; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <h2 style="margin:0; font-size:16px; font-weight:700; color:#0f172a;">
                        Registro Attività <?php echo ($active_module === 'volontari') ? '— Modulo Volontari FAI' : ($active_module === 'prenotazioni' ? '— Modulo Prenotazioni' : '— Globale'); ?>
                    </h2>
                </div>
                <span class="dfn-count-badge" style="background:#e2e8f0; color:#334155; font-weight:700; padding:4px 12px; border-radius:12px; font-size:12.5px;">
                    <?php printf('%s log trovati — Pagina %d di %d', number_format($total_rows), $current_page, $total_pages); ?>
                </span>
            </div>

            <?php if (! empty($logs)) : ?>
                <table class="wp-list-table widefat fixed striped table-view-list dfn-logs-table" style="border:none; box-shadow:none;">
                    <thead>
                        <tr style="background:#f8fafc;">
                            <th style="width:150px; font-weight:700; color:#334155; padding:12px 16px;">Data &amp; Ora</th>
                            <th style="width:160px; font-weight:700; color:#334155; padding:12px 12px;">Tipologia</th>
                            <th style="width:160px; font-weight:700; color:#334155; padding:12px 12px;">Esecutore</th>
                            <th style="font-weight:700; color:#334155; padding:12px 16px;">Descrizione Operazione</th>
                            <th style="width:115px; font-weight:700; color:#334155; text-align:center; padding:12px 16px;">Esito</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : 
                            $badge_class = 'dfn-log-badge--' . sanitize_html_class($log->type);
                            $type_label  = $all_catalog[$log->type] ?? ucfirst(str_replace('_', ' ', $log->type));
                        ?>
                            <tr <?php if ($log->outcome === 'failure') echo 'style="background:#fef2f2 !important;"'; ?>>
                                <td style="padding:12px 16px; vertical-align:top;">
                                    <code style="font-size:11px; background:#f1f5f9; color:#334155; padding:3px 6px; border-radius:4px; border:1px solid #e2e8f0; white-space:nowrap;">
                                        <?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($log->logged_at))); ?>
                                    </code>
                                </td>
                                <td style="padding:12px 12px; vertical-align:top;">
                                    <span class="dfn-log-badge <?php echo esc_attr($badge_class); ?>">
                                        <?php echo esc_html($type_label); ?>
                                    </span>
                                </td>
                                <td style="padding:12px 12px; vertical-align:top;">
                                    <strong style="color:#0f172a; font-size:12.5px; display:block;">
                                        <?php echo esc_html($log->executor); ?>
                                    </strong>
                                </td>
                                <td style="font-size:13px; color:#334155; line-height:1.5; padding:12px 16px; vertical-align:top;">
                                    <?php echo nl2br(esc_html($log->description)); ?>
                                </td>
                                <td style="text-align:center; padding:12px 16px; vertical-align:top;">
                                    <?php if ($log->outcome === 'success') : ?>
                                        <span class="dfn-status-pill dfn-status-pill--success">
                                            <span class="dashicons dashicons-yes" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span> Successo
                                        </span>
                                    <?php else : ?>
                                        <span class="dfn-status-pill dfn-status-pill--failure">
                                            <span class="dashicons dashicons-no" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span> Fallito
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav" style="padding:12px 20px; border-top:1px solid #f1f5f9; background:#f8fafc;">
                        <div class="tablenav-pages">
                            <?php
                            $page_links = paginate_links([
                                'base'      => add_query_arg('paged', '%#%', $base_url),
                                'format'    => '',
                                'prev_text' => '&laquo; Prec',
                                'next_text' => 'Succ &raquo;',
                                'total'     => $total_pages,
                                'current'   => $current_page,
                                'add_args'  => array_filter([
                                    'module'         => $active_module,
                                    'filter_type'    => $filter_type,
                                    'filter_outcome' => $filter_outcome,
                                    'filter_date'    => $filter_date,
                                    'filter_search'  => $filter_search,
                                    'per_page'       => $per_page !== 20 ? $per_page : false,
                                ]),
                            ]);
                            echo wp_kses_post($page_links);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else : ?>
                <div style="padding:48px 20px; text-align:center; color:#64748b;">
                    <span class="dashicons dashicons-info-outline" style="font-size:40px; width:40px; height:40px; color:#cbd5e1;"></span>
                    <p style="font-size:15px; font-weight:600; margin-top:10px;">Nessun log registrato per i criteri selezionati.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- SEZIONE MANUTENZIONE & PULIZIA (Solo Amministratori) -->
        <?php if (current_user_can('manage_options')) : ?>
            <div class="dfn-card dfn-main-card" style="margin-top:20px; padding:18px 22px; background:#fff; border-radius:10px; border:1px solid #cbd5e1; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin:0 0 12px 0; font-size:14px; font-weight:700; color:#1e293b;">🗑️ Manutenzione e Pulizia Registro Log</h3>
                <form method="post" onsubmit="return confirm('Confermi l\'operazione di pulizia? L\'azione è irreversibile.');">
                    <?php wp_nonce_field('dfn_log_action_nonce'); ?>
                    <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <label style="font-size:13px; color:#475569;">Elimina record più vecchi di</label>
                            <input type="number" name="purge_days" value="60" min="1" max="365" class="small-text" style="width:65px; text-align:center; height:32px; border-radius:4px; border:1px solid #cbd5e1;">
                            <span style="font-size:13px; color:#475569;">giorni</span>
                            <button type="submit" name="dfn_log_action" value="purge_days" class="button button-secondary" style="font-weight:600;">
                                Elimina Vecchi Log
                            </button>
                        </div>
                        <div>
                            <button type="submit" name="dfn_log_action" value="purge_all" class="button" style="color:#b91c1c; border-color:#fca5a5; background:#fff5f5; font-weight:600;">
                                ⚠️ Svuota Intero Database Log
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <style>
        /* Grid Statistiche Dashboard */
        .dfn-logs-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .dfn-log-stat-card { background: #fff; border-radius: 10px; border: 1px solid #cbd5e1; padding: 16px 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .dfn-log-stat-icon { width: 44px; height: 44px; border-radius: 10px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dfn-log-stat-icon .dashicons { font-size: 24px; width: 24px; height: 24px; color: #475569; }
        .dfn-log-stat-content { display: flex; flex-direction: column; }
        .dfn-log-stat-val { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.1; }
        .dfn-log-stat-lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }

        /* Varianti Colore Card Statistiche */
        .dfn-log-stat-card--total { border-top: 4px solid #3b82f6; }
        .dfn-log-stat-card--total .dfn-log-stat-icon { background: #eff6ff; }
        .dfn-log-stat-card--total .dfn-log-stat-icon .dashicons { color: #2563eb; }

        .dfn-log-stat-card--success { border-top: 4px solid #004b23; }
        .dfn-log-stat-card--success .dfn-log-stat-icon { background: #f0fdf4; }
        .dfn-log-stat-card--success .dfn-log-stat-icon .dashicons { color: #004b23; }
        .dfn-log-stat-card--success .dfn-log-stat-val { color: #004b23; }

        .dfn-log-stat-card--failure { border-top: 4px solid #ef4444; }
        .dfn-log-stat-card--failure .dfn-log-stat-icon { background: #fef2f2; }
        .dfn-log-stat-card--failure .dfn-log-stat-icon .dashicons { color: #dc2626; }
        .dfn-log-stat-card--failure .dfn-log-stat-val { color: #dc2626; }

        .dfn-log-stat-card--today { border-top: 4px solid #f59e0b; }
        .dfn-log-stat-card--today .dfn-log-stat-icon { background: #fffbeb; }
        .dfn-log-stat-card--today .dfn-log-stat-icon .dashicons { color: #d97706; }

        /* Form Filtri */
        .dfn-logs-filter-row { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end; }
        .dfn-filter-label { display: flex; flex-direction: column; font-size: 11.5px; font-weight: 700; color: #475569; gap: 5px; text-transform: uppercase; letter-spacing: 0.3px; }
        .dfn-select-input, .dfn-text-input { font-size: 13px !important; padding: 6px 12px !important; border-radius: 6px !important; border: 1px solid #cbd5e1 !important; height: 36px !important; }
        .dfn-filter-wide { flex: 1; min-width: 220px; }
        .dfn-filter-actions { display: flex; gap: 8px; align-items: flex-end; }

        /* Badge Tipologia Log */
        .dfn-log-badge { display: inline-block; padding: 4px 9px; border-radius: 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; text-align: center; white-space: nowrap; }
        
        /* Modulo Prenotazioni */
        .dfn-log-badge--prenotazione { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .dfn-log-badge--recensione   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .dfn-log-badge--annullamento { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .dfn-log-badge--tessera_fai  { background: #e0f2fe; color: #075985; border: 1px solid #7dd3fc; }
        .dfn-log-badge--checkin      { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
        .dfn-log-badge--spostamento  { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; }
        .dfn-log-badge--stock        { background: #f1f5f9; color: #334155; border: 1px solid #94a3b8; }
        
        /* Modulo Volontari */
        .dfn-log-badge--volontario_anagrafica { background: #ccfbf1; color: #115e59; border: 1px solid #5eead4; }
        .dfn-log-badge--volontario_turni      { background: #e0e7ff; color: #3730a3; border: 1px solid #a5b4fc; }
        .dfn-log-badge--volontario_sondaggi   { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .dfn-log-badge--volontario_riunioni   { background: #fce7f3; color: #9d174d; border: 1px solid #fbcfe8; }
        
        /* Sistema Condiviso */
        .dfn-log-badge--email     { background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }
        .dfn-log-badge--login     { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
        .dfn-log-badge--logout    { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .dfn-log-badge--sicurezza { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
        .dfn-log-badge--profilo   { background: #ecfdf5; color: #047857; border: 1px solid #6ee7b7; }
        .dfn-log-badge--sistema   { background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; }

        /* Badge Esito (Status Pill) */
        .dfn-status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700; }
        .dfn-status-pill--success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .dfn-status-pill--failure { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
    </style>
    <?php
}
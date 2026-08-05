<?php
/**
 * DFN Booking System 2.0 — Pannello Admin: Log di Sistema
 *
 * Visualizza il registro centralizzato delle azioni eseguite dal sistema
 * (email inviate, errori, ecc.) con filtri, paginazione e pulizia manuale.
 *
 * @package DFN_Theme
 * @since   2.2.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_logs_register_admin_menu', 999);

/**
 * Registra il sottomenu Log di Sistema in FAI Prenotazioni.
 */
function dfn_logs_register_admin_menu(): void
{
    add_submenu_page(
        'dfn-events',
        __('Log di Sistema', 'dfn-theme'),
        __('Log di Sistema', 'dfn-theme'),
        'read',
        'dfn-logs',
        'dfn_render_logs_page'
    );
}

/**
 * Renderizza la pagina admin Log di Sistema.
 */
function dfn_render_logs_page(): void
{
    if (! is_user_logged_in() || ! current_user_can('read')) {
        wp_die(__('Permessi insufficienti.', 'dfn-theme'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_logs';

    // Self-healing: crea la tabella se non esiste ancora
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        if (function_exists('dfn_db_install')) {
            dfn_db_install();
        }
    }

    // --- Azioni manuali ---
    if (isset($_POST['dfn_log_action']) && check_admin_referer('dfn_log_action_nonce')) {
        $action = sanitize_text_field($_POST['dfn_log_action']);
        if ($action === 'purge_all') {
            $wpdb->query("TRUNCATE TABLE {$table}");
            echo '<div class="notice notice-success is-dismissible"><p>✅ Tutti i log sono stati eliminati.</p></div>';
        } elseif ($action === 'purge_days') {
            $days = max(1, (int) ($_POST['purge_days'] ?? 30));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            echo '<div class="notice notice-success is-dismissible"><p>✅ Log più vecchi di ' . intval($days) . ' giorni eliminati.</p></div>';
        }
    }

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

    // --- Query ---
    $where  = '1=1';
    $params = [];
    if ($filter_type !== '')    { $where .= ' AND type = %s';                              $params[] = $filter_type; }
    if ($filter_outcome !== '') { $where .= ' AND outcome = %s';                           $params[] = $filter_outcome; }
    if ($filter_date !== '')    { $where .= ' AND DATE(logged_at) = %s';                   $params[] = $filter_date; }
    if ($filter_search !== '')  { $where .= ' AND (description LIKE %s OR executor LIKE %s)'; $params[] = '%' . $wpdb->esc_like($filter_search) . '%'; $params[] = '%' . $wpdb->esc_like($filter_search) . '%'; }

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $data_sql  = "SELECT * FROM {$table} WHERE {$where} ORDER BY logged_at DESC LIMIT %d OFFSET %d";

    if (! empty($params)) {
        $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        $logs       = $wpdb->get_results($wpdb->prepare($data_sql, array_merge($params, [$per_page, $offset])));
    } else {
        $total_rows = (int) $wpdb->get_var($count_sql);
        $logs       = $wpdb->get_results($wpdb->prepare($data_sql, $per_page, $offset));
    }

    $total_pages   = ($per_page > 0 && $total_rows > 0) ? (int) ceil($total_rows / $per_page) : 1;
    $types         = $wpdb->get_col("SELECT DISTINCT type FROM {$table} ORDER BY type ASC");
    $base_url      = admin_url('admin.php?page=dfn-logs');

    // Statistiche rapide
    $stats_success = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'success'");
    $stats_failure = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'failure'");
    $stats_total   = $stats_success + $stats_failure;
    $stats_today   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(logged_at) = CURDATE()");
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom:20px;">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-list-view" style="font-size:32px; width:32px; height:32px; color:#004b23;"></span>
                <h1 style="font-size:23px; font-weight:700; color:#1d2327; margin:0 0 0 10px; display:inline-block; vertical-align:middle;">
                    Log di Sistema
                </h1>
            </div>
        </header>

        <!-- STATISTICHE CARD DSHBOARD -->
        <div class="dfn-logs-stats-grid">
            <div class="dfn-log-stat-card dfn-log-stat-card--total">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-database"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_total); ?></span>
                    <span class="dfn-log-stat-lbl">Totale Registrati</span>
                </div>
            </div>
            <div class="dfn-log-stat-card dfn-log-stat-card--success">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_success); ?></span>
                    <span class="dfn-log-stat-lbl">Inviati / Successi</span>
                </div>
            </div>
            <div class="dfn-log-stat-card dfn-log-stat-card--failure">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-dismiss"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_failure); ?></span>
                    <span class="dfn-log-stat-lbl">Falliti / Errori</span>
                </div>
            </div>
            <div class="dfn-log-stat-card dfn-log-stat-card--today">
                <div class="dfn-log-stat-icon"><span class="dashicons dashicons-calendar-alt"></span></div>
                <div class="dfn-log-stat-content">
                    <span class="dfn-log-stat-val"><?php echo number_format($stats_today); ?></span>
                    <span class="dfn-log-stat-lbl">Attività Oggi</span>
                </div>
            </div>
        </div>

        <!-- FILTRI -->
        <div class="dfn-card dfn-main-card" style="margin-bottom:16px; padding:16px 20px; background:#fff; border-radius:8px; border:1px solid #c3c4c7; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <form method="get" action="<?php echo esc_url($base_url); ?>" id="dfn-logs-filter-form">
                <input type="hidden" name="page" value="dfn-logs">
                <div class="dfn-logs-filter-row">
                    <label class="dfn-filter-label">Tipologia
                        <select name="filter_type" class="dfn-select-input">
                            <option value="">— Tutte —</option>
                            <?php foreach ($types as $t) : ?>
                                <option value="<?php echo esc_attr($t); ?>" <?php selected($filter_type, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="dfn-filter-label">Esito
                        <select name="filter_outcome" class="dfn-select-input">
                            <option value="">— Tutti —</option>
                            <option value="success" <?php selected($filter_outcome, 'success'); ?>>✅ Successo</option>
                            <option value="failure" <?php selected($filter_outcome, 'failure'); ?>>❌ Fallimento</option>
                        </select>
                    </label>
                    <label class="dfn-filter-label">Data
                        <input type="date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>" class="dfn-text-input">
                    </label>
                    <label class="dfn-filter-label dfn-filter-wide">Cerca
                        <input type="text" name="filter_search" value="<?php echo esc_attr($filter_search); ?>" placeholder="Cerca in descrizione o esecutore…" class="dfn-text-input">
                    </label>
                    <label class="dfn-filter-label">Righe per pagina
                        <select name="per_page" onchange="document.getElementById('dfn-logs-filter-form').submit()" class="dfn-select-input">
                            <?php foreach ([20, 50, 100] as $opt) : ?>
                                <option value="<?php echo $opt; ?>" <?php selected($per_page, $opt); ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="dfn-filter-actions">
                        <button type="submit" class="button button-primary" style="background:#004b23; border-color:#003b1c;">Filtra Log</button>
                        <a href="<?php echo esc_url($base_url); ?>" class="button">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- TABELLA LOG -->
        <div class="dfn-card dfn-main-card" style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; box-shadow:0 1px 2px rgba(0,0,0,0.05); overflow:hidden;">
            <div class="dfn-card-header" style="padding:14px 20px; border-bottom:1px solid #f0f0f1; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="margin:0; font-size:15px; font-weight:700; color:#1d2327;">Registro Attività</h2>
                <span class="dfn-count-badge" style="background:#e2e8f0; color:#334155; font-weight:600; padding:3px 10px; border-radius:12px; font-size:12px;">
                    <?php printf('%d log trovati — pag. %d / %d', $total_rows, $current_page, $total_pages); ?>
                </span>
            </div>

            <?php if (! empty($logs)) : ?>
                <table class="wp-list-table widefat fixed striped table-view-list dfn-logs-table" style="border:none; box-shadow:none;">
                    <thead>
                        <tr>
                            <th style="width:145px; font-weight:700;">Data &amp; Ora</th>
                            <th style="width:90px; font-weight:700;">Tipo</th>
                            <th style="width:140px; font-weight:700;">Esecutore</th>
                            <th style="font-weight:700;">Descrizione Dettagliata</th>
                            <th style="width:110px; font-weight:700; text-align:center;">Esito</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr <?php if ($log->outcome === 'failure') echo 'style="background:#fef2f2 !important;"'; ?>>
                                <td>
                                    <code style="font-size:11px; background:#f1f5f9; color:#334155; padding:3px 6px; border-radius:4px; border:1px solid #e2e8f0;">
                                        <?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($log->logged_at))); ?>
                                    </code>
                                </td>
                                <td>
                                    <span class="dfn-log-badge dfn-log-badge--<?php echo esc_attr($log->type); ?>">
                                        <?php echo esc_html(strtoupper($log->type)); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong style="color:#1e293b; font-size:12px;"><?php echo esc_html($log->executor); ?></strong>
                                </td>
                                <td style="font-size:12.5px; color:#334155; line-height:1.5;">
                                    <?php echo nl2br(esc_html($log->description)); ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($log->outcome === 'success') : ?>
                                        <span class="dfn-status-pill dfn-status-pill--success">
                                            <span class="dashicons dashicons-yes" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span> Successo
                                        </span>
                                    <?php else : ?>
                                        <span class="dfn-status-pill dfn-status-pill--failure">
                                            <span class="dashicons dashicons-no" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span> Errore
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav" style="padding:12px 20px; border-top:1px solid #f0f0f1;">
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
                <div style="padding:40px 20px; text-align:center; color:#64748b;">
                    <span class="dashicons dashicons-info-outline" style="font-size:36px; width:36px; height:36px; color:#cbd5e1;"></span>
                    <p style="font-size:14px; margin-top:8px;">Nessun log trovato con i filtri selezionati.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PULIZIA LOG -->
        <div class="dfn-card dfn-main-card" style="margin-top:16px; padding:16px 20px; background:#fff; border-radius:8px; border:1px solid #c3c4c7; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <h3 style="margin:0 0 12px 0; font-size:14px; font-weight:700; color:#1d2327;">🗑️ Manutenzione e Pulizia Log</h3>
            <form method="post" onsubmit="return confirm('Confermi? Questa operazione è irreversibile.');">
                <?php wp_nonce_field('dfn_log_action_nonce'); ?>
                <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <label style="font-size:13px; color:#475569;">Elimina log più vecchi di</label>
                        <input type="number" name="purge_days" value="30" min="1" max="365" class="small-text" style="width:60px; text-align:center;">
                        <span style="font-size:13px; color:#475569;">giorni</span>
                        <button type="submit" name="dfn_log_action" value="purge_days" class="button button-secondary">Elimina Vecchi Log</button>
                    </div>
                    <div>
                        <button type="submit" name="dfn_log_action" value="purge_all" class="button" style="color:#b91c1c; border-color:#fca5a5; background:#fff5f5;">
                            ⚠️ Elimina TUTTI i Log
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
        /* Grid Statistiche Dashboard */
        .dfn-logs-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 16px; }
        .dfn-log-stat-card { background: #fff; border-radius: 8px; border: 1px solid #c3c4c7; padding: 16px 18px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .dfn-log-stat-icon { width: 42px; height: 42px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .dfn-log-stat-icon .dashicons { font-size: 22px; width: 22px; height: 22px; color: #475569; }
        .dfn-log-stat-content { display: flex; flex-direction: column; }
        .dfn-log-stat-val { font-size: 22px; font-weight: 700; color: #0f172a; line-height: 1.1; }
        .dfn-log-stat-lbl { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 3px; }

        /* Varianti Colore Card Statistiche */
        .dfn-log-stat-card--total { border-top: 3px solid #3b82f6; }
        .dfn-log-stat-card--total .dfn-log-stat-icon { background: #eff6ff; }
        .dfn-log-stat-card--total .dfn-log-stat-icon .dashicons { color: #2563eb; }

        .dfn-log-stat-card--success { border-top: 3px solid #004b23; }
        .dfn-log-stat-card--success .dfn-log-stat-icon { background: #f0fdf4; }
        .dfn-log-stat-card--success .dfn-log-stat-icon .dashicons { color: #004b23; }
        .dfn-log-stat-card--success .dfn-log-stat-val { color: #004b23; }

        .dfn-log-stat-card--failure { border-top: 3px solid #ef4444; }
        .dfn-log-stat-card--failure .dfn-log-stat-icon { background: #fef2f2; }
        .dfn-log-stat-card--failure .dfn-log-stat-icon .dashicons { color: #dc2626; }
        .dfn-log-stat-card--failure .dfn-log-stat-val { color: #dc2626; }

        .dfn-log-stat-card--today { border-top: 3px solid #f59e0b; }
        .dfn-log-stat-card--today .dfn-log-stat-icon { background: #fffbeb; }
        .dfn-log-stat-card--today .dfn-log-stat-icon .dashicons { color: #d97706; }

        /* Form Filtri */
        .dfn-logs-filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .dfn-filter-label { display: flex; flex-direction: column; font-size: 11.5px; font-weight: 700; color: #475569; gap: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
        .dfn-select-input, .dfn-text-input { font-size: 13px !important; padding: 5px 10px !important; border-radius: 6px !important; border: 1px solid #cbd5e1 !important; height: 34px !important; }
        .dfn-filter-wide { flex: 1; min-width: 180px; }
        .dfn-filter-actions { display: flex; gap: 6px; align-items: flex-end; }

        /* Badge Tipologia Log */
        .dfn-log-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 700; letter-spacing: 0.4px; }
        .dfn-log-badge--email { background: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }
        .dfn-log-badge--generic { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e0; }

        /* Badge Esito (Status Pill) */
        .dfn-status-pill { display: inline-flex; align-items: center; gap: 3px; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700; }
        .dfn-status-pill--success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .dfn-status-pill--failure { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
    </style>
    <?php
}
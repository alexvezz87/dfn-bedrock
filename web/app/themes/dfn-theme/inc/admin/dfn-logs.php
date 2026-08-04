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

/**
 * Renderizza la pagina admin Log di Sistema.
 */
function dfn_render_logs_page(): void
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(__('Permessi insufficienti.', 'dfn-theme'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_logs';

    // --- Azioni manuali ---
    if (isset($_POST['dfn_log_action']) && check_admin_referer('dfn_log_action_nonce')) {
        $action = sanitize_text_field($_POST['dfn_log_action']);
        if ($action === 'purge_all') {
            $wpdb->query("TRUNCATE TABLE {$table}");
            echo '<div class="notice notice-success"><p>✅ Tutti i log sono stati eliminati.</p></div>';
        } elseif ($action === 'purge_days') {
            $days = max(1, (int) ($_POST['purge_days'] ?? 30));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            echo '<div class="notice notice-success"><p>✅ Log più vecchi di ' . intval($days) . ' giorni eliminati.</p></div>';
        }
    }

    // --- Parametri filtro ---
    $filter_type    = isset($_GET['filter_type'])    ? sanitize_text_field($_GET['filter_type'])    : '';
    $filter_outcome = isset($_GET['filter_outcome']) ? sanitize_text_field($_GET['filter_outcome']) : '';
    $filter_date    = isset($_GET['filter_date'])    ? sanitize_text_field($_GET['filter_date'])    : '';
    $filter_search  = isset($_GET['filter_search'])  ? sanitize_text_field($_GET['filter_search'])  : '';
    $per_page       = 25;
    $current_page   = max(1, (int) ($_GET['paged'] ?? 1));
    $offset         = ($current_page - 1) * $per_page;

    // --- Costruzione query con filtri ---
    $where    = '1=1';
    $params   = [];

    if ($filter_type !== '') {
        $where   .= ' AND type = %s';
        $params[] = $filter_type;
    }
    if ($filter_outcome !== '') {
        $where   .= ' AND outcome = %s';
        $params[] = $filter_outcome;
    }
    if ($filter_date !== '') {
        $where   .= ' AND DATE(logged_at) = %s';
        $params[] = $filter_date;
    }
    if ($filter_search !== '') {
        $where   .= ' AND (description LIKE %s OR executor LIKE %s)';
        $params[] = '%' . $wpdb->esc_like($filter_search) . '%';
        $params[] = '%' . $wpdb->esc_like($filter_search) . '%';
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

    $total_pages = max(1, (int) ceil($total_rows / $per_page));

    // --- Elenco tipologie distinte per filtro ---
    $types = $wpdb->get_col("SELECT DISTINCT type FROM {$table} ORDER BY type ASC");

    $base_url = admin_url('admin.php?page=dfn-logs');

    ?>
    <div class="wrap dfn-logs-wrap">
        <h1 class="wp-heading-inline">📋 Log di Sistema</h1>
        <hr class="wp-header-end">

        <!-- FILTRI -->
        <form method="get" action="<?php echo esc_url($base_url); ?>" class="dfn-logs-filters">
            <input type="hidden" name="page" value="dfn-logs">
            <div class="dfn-logs-filter-row">
                <label>
                    Tipologia
                    <select name="filter_type">
                        <option value="">— Tutte —</option>
                        <?php foreach ($types as $t) : ?>
                            <option value="<?php echo esc_attr($t); ?>" <?php selected($filter_type, $t); ?>>
                                <?php echo esc_html(ucfirst($t)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    Esito
                    <select name="filter_outcome">
                        <option value="">— Tutti —</option>
                        <option value="success" <?php selected($filter_outcome, 'success'); ?>>✅ Successo</option>
                        <option value="failure" <?php selected($filter_outcome, 'failure'); ?>>❌ Fallimento</option>
                    </select>
                </label>
                <label>
                    Data
                    <input type="date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>">
                </label>
                <label>
                    Cerca
                    <input type="text" name="filter_search" value="<?php echo esc_attr($filter_search); ?>" placeholder="Descrizione o esecutore…">
                </label>
                <button type="submit" class="button button-primary">Filtra</button>
                <a href="<?php echo esc_url($base_url); ?>" class="button">Reset</a>
            </div>
        </form>

        <!-- STATISTICHE RAPIDE -->
        <?php
        $stats_success = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'success'");
        $stats_failure = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'failure'");
        $stats_total   = $stats_success + $stats_failure;
        $stats_today   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(logged_at) = CURDATE()");
        ?>
        <div class="dfn-logs-stats">
            <div class="dfn-log-stat dfn-log-stat--total">
                <span class="dfn-log-stat-num"><?php echo number_format($stats_total); ?></span>
                <span class="dfn-log-stat-label">Totale Log</span>
            </div>
            <div class="dfn-log-stat dfn-log-stat--success">
                <span class="dfn-log-stat-num"><?php echo number_format($stats_success); ?></span>
                <span class="dfn-log-stat-label">✅ Successi</span>
            </div>
            <div class="dfn-log-stat dfn-log-stat--failure">
                <span class="dfn-log-stat-num"><?php echo number_format($stats_failure); ?></span>
                <span class="dfn-log-stat-label">❌ Fallimenti</span>
            </div>
            <div class="dfn-log-stat dfn-log-stat--today">
                <span class="dfn-log-stat-num"><?php echo number_format($stats_today); ?></span>
                <span class="dfn-log-stat-label">📅 Oggi</span>
            </div>
        </div>

        <!-- RISULTATI / TABELLA -->
        <div class="dfn-logs-result-info">
            <?php printf('<strong>%d</strong> log trovati (pagina %d di %d)', $total_rows, $current_page, $total_pages); ?>
        </div>

        <?php if (! empty($logs)) : ?>
            <table class="wp-list-table widefat fixed striped dfn-logs-table">
                <thead>
                    <tr>
                        <th class="dfn-col-date">📅 Data &amp; Ora</th>
                        <th class="dfn-col-type">Tipologia</th>
                        <th class="dfn-col-executor">Esecutore</th>
                        <th class="dfn-col-desc">Descrizione</th>
                        <th class="dfn-col-outcome">Esito</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log) : ?>
                        <tr class="dfn-log-row dfn-log-row--<?php echo esc_attr($log->outcome); ?>">
                            <td class="dfn-col-date">
                                <span class="dfn-log-datetime"><?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($log->logged_at))); ?></span>
                            </td>
                            <td class="dfn-col-type">
                                <span class="dfn-log-type-badge dfn-log-type--<?php echo esc_attr($log->type); ?>">
                                    <?php echo esc_html(strtoupper($log->type)); ?>
                                </span>
                            </td>
                            <td class="dfn-col-executor"><?php echo esc_html($log->executor); ?></td>
                            <td class="dfn-col-desc"><?php echo nl2br(esc_html($log->description)); ?></td>
                            <td class="dfn-col-outcome">
                                <?php if ($log->outcome === 'success') : ?>
                                    <span class="dfn-outcome-badge dfn-outcome--success">✅ Successo</span>
                                <?php else : ?>
                                    <span class="dfn-outcome-badge dfn-outcome--failure">❌ Fallimento</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- PAGINAZIONE -->
            <?php if ($total_pages > 1) : ?>
                <div class="dfn-logs-pagination tablenav">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links([
                            'base'      => add_query_arg('paged', '%#%', $base_url),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                            'add_args'  => array_filter([
                                'filter_type'    => $filter_type,
                                'filter_outcome' => $filter_outcome,
                                'filter_date'    => $filter_date,
                                'filter_search'  => $filter_search,
                            ]),
                        ]);
                        echo wp_kses_post($page_links);
                        ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php else : ?>
            <div class="dfn-logs-empty">
                <p>📭 Nessun log trovato con i filtri selezionati.</p>
            </div>
        <?php endif; ?>

        <!-- AZIONI DI PULIZIA -->
        <div class="dfn-logs-cleanup-box">
            <h3>🗑️ Pulizia Log</h3>
            <form method="post" onsubmit="return confirm('Sei sicuro? Questa operazione è irreversibile.');">
                <?php wp_nonce_field('dfn_log_action_nonce'); ?>
                <div class="dfn-logs-cleanup-actions">
                    <div class="dfn-cleanup-group">
                        <label>Elimina log più vecchi di:</label>
                        <input type="number" name="purge_days" value="30" min="1" max="365" style="width:70px;">
                        <span>giorni</span>
                        <button type="submit" name="dfn_log_action" value="purge_days" class="button button-secondary">
                            Elimina Vecchi Log
                        </button>
                    </div>
                    <div class="dfn-cleanup-group">
                        <button type="submit" name="dfn_log_action" value="purge_all" class="button button-link-delete">
                            ⚠️ Elimina TUTTI i Log
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
        .dfn-logs-wrap { max-width: 1400px; }
        .dfn-logs-filters { margin: 16px 0; padding: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
        .dfn-logs-filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .dfn-logs-filter-row label { display: flex; flex-direction: column; font-size: 12px; font-weight: 600; color: #475569; gap: 4px; }
        .dfn-logs-filter-row select,
        .dfn-logs-filter-row input[type="date"],
        .dfn-logs-filter-row input[type="text"] { font-size: 13px; border: 1px solid #cbd5e0; border-radius: 6px; padding: 5px 8px; }
        .dfn-logs-stats { display: flex; gap: 16px; margin: 16px 0; flex-wrap: wrap; }
        .dfn-log-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 20px; text-align: center; min-width: 110px; }
        .dfn-log-stat--success { border-top: 3px solid #22c55e; }
        .dfn-log-stat--failure { border-top: 3px solid #ef4444; }
        .dfn-log-stat--total   { border-top: 3px solid #3b82f6; }
        .dfn-log-stat--today   { border-top: 3px solid #f59e0b; }
        .dfn-log-stat-num { display: block; font-size: 24px; font-weight: 700; color: #1e293b; }
        .dfn-log-stat-label { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .dfn-logs-result-info { font-size: 13px; color: #64748b; margin-bottom: 8px; }
        .dfn-logs-table { font-size: 13px; }
        .dfn-logs-table th { font-weight: 600; color: #1e293b; background: #f8fafc; }
        .dfn-col-date     { width: 140px; white-space: nowrap; }
        .dfn-col-type     { width: 90px; }
        .dfn-col-executor { width: 140px; }
        .dfn-col-outcome  { width: 120px; }
        .dfn-log-row--failure { background-color: #fff5f5 !important; }
        .dfn-log-datetime { font-family: monospace; font-size: 12px; }
        .dfn-log-type-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; background: #e0f2fe; color: #0369a1; }
        .dfn-log-type--email { background: #ede9fe; color: #6d28d9; }
        .dfn-outcome-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .dfn-outcome--success { background: #dcfce7; color: #15803d; }
        .dfn-outcome--failure { background: #fee2e2; color: #b91c1c; }
        .dfn-logs-pagination { margin: 16px 0; }
        .dfn-logs-empty { background: #f8fafc; border: 1px dashed #cbd5e0; border-radius: 8px; padding: 40px; text-align: center; color: #64748b; font-size: 15px; }
        .dfn-logs-cleanup-box { margin-top: 30px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; }
        .dfn-logs-cleanup-box h3 { margin-top: 0; }
        .dfn-logs-cleanup-actions { display: flex; gap: 24px; align-items: center; flex-wrap: wrap; }
        .dfn-cleanup-group { display: flex; align-items: center; gap: 8px; }
        .button-link-delete { color: #b91c1c !important; }
    </style>
    <?php
}

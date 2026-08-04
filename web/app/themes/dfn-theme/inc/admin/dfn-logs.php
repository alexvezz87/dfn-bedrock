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

    // Self-healing: crea la tabella se non esiste ancora (es. prima migrazione DB 2.2.0)
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
            echo '<div class="notice notice-success"><p>Log eliminati.</p></div>';
        } elseif ($action === 'purge_days') {
            $days = max(1, (int) ($_POST['purge_days'] ?? 30));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            ));
            echo '<div class="notice notice-success"><p>Log piu vecchi di ' . intval($days) . ' giorni eliminati.</p></div>';
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

    // --- Costruzione query con filtri ---
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
    $stats_success = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'success'");
    $stats_failure = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE outcome = 'failure'");
    $stats_total   = $stats_success + $stats_failure;
    $stats_today   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE DATE(logged_at) = CURDATE()");
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-list-view"></span>
                <h1>Log di Sistema</h1>
            </div>
            <span class="dfn-count-badge"><?php echo number_format($stats_total); ?> log totali &mdash; <?php echo number_format($stats_today); ?> oggi</span>
        </header>

        <!-- FILTRI -->
        <div class="dfn-card dfn-main-card" style="margin-bottom:10px;">
            <form method="get" action="<?php echo esc_url($base_url); ?>" id="dfn-logs-filter-form">
                <input type="hidden" name="page" value="dfn-logs">
                <div class="dfn-logs-filter-row">
                    <label class="dfn-filter-label">Tipologia
                        <select name="filter_type">
                            <option value="">— Tutte —</option>
                            <?php foreach ($types as $t) : ?>
                                <option value="<?php echo esc_attr($t); ?>" <?php selected($filter_type, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="dfn-filter-label">Esito
                        <select name="filter_outcome">
                            <option value="">— Tutti —</option>
                            <option value="success" <?php selected($filter_outcome, 'success'); ?>>Successo</option>
                            <option value="failure" <?php selected($filter_outcome, 'failure'); ?>>Fallimento</option>
                        </select>
                    </label>
                    <label class="dfn-filter-label">Data
                        <input type="date" name="filter_date" value="<?php echo esc_attr($filter_date); ?>">
                    </label>
                    <label class="dfn-filter-label dfn-filter-wide">Cerca
                        <input type="text" name="filter_search" value="<?php echo esc_attr($filter_search); ?>" placeholder="Descrizione o esecutore...">
                    </label>
                    <label class="dfn-filter-label">Per pagina
                        <select name="per_page" onchange="document.getElementById('dfn-logs-filter-form').submit()">
                            <?php foreach ([20, 50, 100] as $opt) : ?>
                                <option value="<?php echo $opt; ?>" <?php selected($per_page, $opt); ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div style="display:flex;gap:6px;align-items:flex-end;padding-bottom:1px;">
                        <button type="submit" class="button button-primary">Filtra</button>
                        <a href="<?php echo esc_url($base_url); ?>" class="button">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- TABELLA LOG -->
        <div class="dfn-card dfn-main-card">
            <div class="dfn-card-header">
                <h2>Registro Log</h2>
                <span class="dfn-count-badge">
                    <?php printf('%d record &mdash; pag. %d / %d &mdash; %d per pag.', $total_rows, $current_page, $total_pages, $per_page); ?>
                </span>
            </div>

            <?php if (! empty($logs)) : ?>
                <table class="wp-list-table widefat fixed striped table-view-list dfn-events-table">
                    <thead>
                        <tr>
                            <th style="width:130px;">Data &amp; Ora</th>
                            <th style="width:75px;">Tipo</th>
                            <th style="width:130px;">Esecutore</th>
                            <th>Descrizione</th>
                            <th style="width:85px;">Esito</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr <?php if ($log->outcome === 'failure') echo 'style="background:#fff4f4;"'; ?>>
                                <td><code style="font-size:11px;"><?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($log->logged_at))); ?></code></td>
                                <td>
                                    <span class="dfn-badge dfn-log-type--<?php echo esc_attr($log->type); ?>" style="font-size:10px;letter-spacing:.3px;">
                                        <?php echo esc_html(strtoupper($log->type)); ?>
                                    </span>
                                </td>
                                <td><span class="dfn-small-sub"><?php echo esc_html($log->executor); ?></span></td>
                                <td style="font-size:12px;"><?php echo nl2br(esc_html($log->description)); ?></td>
                                <td>
                                    <?php if ($log->outcome === 'success') : ?>
                                        <span class="dfn-badge dfn-status-published" style="font-size:11px;">OK</span>
                                    <?php else : ?>
                                        <span class="dfn-badge dfn-status-archived" style="font-size:11px;">ERRORE</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav" style="padding:8px 0 4px;">
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
                                    'per_page'       => $per_page !== 20 ? $per_page : false,
                                ]),
                            ]);
                            echo wp_kses_post($page_links);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else : ?>
                <div class="dfn-empty-state" style="padding:30px 20px;">
                    <span class="dashicons dashicons-list-view"></span>
                    <p>Nessun log trovato con i filtri selezionati.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- PULIZIA LOG -->
        <div class="dfn-card dfn-main-card" style="margin-top:10px;">
            <div class="dfn-card-header"><h2>Pulizia Log</h2></div>
            <form method="post" style="padding:12px 0 4px;" onsubmit="return confirm('Confermi? Questa operazione e irreversibile.');">
                <?php wp_nonce_field('dfn_log_action_nonce'); ?>
                <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <label style="font-size:13px;">Elimina log piu vecchi di</label>
                        <input type="number" name="purge_days" value="30" min="1" max="365" class="small-text" style="width:60px;">
                        <span style="font-size:13px;">giorni</span>
                        <button type="submit" name="dfn_log_action" value="purge_days" class="button button-secondary">Elimina Vecchi Log</button>
                    </div>
                    <div>
                        <button type="submit" name="dfn_log_action" value="purge_all" class="button" style="color:#b91c1c;border-color:#fca5a5;">
                            Elimina TUTTI i Log
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
        .dfn-logs-filter-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; padding:10px 0 6px; }
        .dfn-filter-label { display:flex; flex-direction:column; font-size:12px; font-weight:600; color:#3c434a; gap:3px; }
        .dfn-filter-label select,
        .dfn-filter-label input[type="date"],
        .dfn-filter-label input[type="text"] { font-size:13px; }
        .dfn-filter-wide { flex:1; min-width:160px; }
        .dfn-log-type--email { background:#ede9fe; color:#5b21b6; border:1px solid #c4b5fd; border-radius:3px; padding:1px 6px; }
        .dfn-log-type--generic { background:#f1f5f9; color:#475569; border:1px solid #cbd5e0; border-radius:3px; padding:1px 6px; }
    </style>
    <?php
}
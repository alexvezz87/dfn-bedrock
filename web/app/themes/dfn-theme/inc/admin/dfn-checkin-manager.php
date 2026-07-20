<?php
/**
 * DFN Booking System 2.0 — Check-in Banchetto
 *
 * Pagina dedicata alla gestione check-in al banchetto.
 * Separata dalla gestione prenotazioni per consentire ruoli distinti.
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renderizza la pagina Check-in Banchetto.
 */
function dfn_render_checkin_manager()
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme'));
    }

    $event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
    if ($event_id <= 0) {
        global $wpdb;
        $table_events = $wpdb->prefix . 'dfn_events';
        $all_events = $wpdb->get_results("SELECT * FROM {$table_events} ORDER BY event_date_start DESC");
        
        $active_events = [];
        $archived_events = [];
        
        foreach ($all_events as $e) {
            if ('archived' === $e->status) {
                $archived_events[] = $e;
            } else {
                $active_events[] = $e;
            }
        }
        ?>
        <div class="wrap dfn-admin-wrap">
            <header class="dfn-admin-header">
                <div class="dfn-logo-area">
                    <span class="dashicons dashicons-tickets-alt"></span>
                    <h1><?php esc_html_e('Check-in Banchetto — Selezione Evento', 'dfn-theme'); ?></h1>
                </div>
            </header>

            <div class="dfn-card dfn-main-card">
                <div class="dfn-card-header">
                    <h2><?php esc_html_e('Eventi Attivi', 'dfn-theme'); ?></h2>
                    <span class="dfn-count-badge"><?php echo count($active_events); ?> <?php esc_html_e('Attivi', 'dfn-theme'); ?></span>
                </div>

                <table class="wp-list-table widefat fixed striped table-view-list dfn-events-table">
                    <thead>
                        <tr>
                            <th class="column-title"><?php esc_html_e('Nome Evento', 'dfn-theme'); ?></th>
                            <th><?php esc_html_e('Data & Luogo', 'dfn-theme'); ?></th>
                            <th><?php esc_html_e('Tipologia', 'dfn-theme'); ?></th>
                            <th><?php esc_html_e('Prenotazioni', 'dfn-theme'); ?></th>
                            <th class="column-actions" style="text-align:right; padding-right:20px;"><?php esc_html_e('Azione', 'dfn-theme'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($active_events)) : ?>
                            <tr>
                                <td colspan="5" class="dfn-empty-row">
                                    <div class="dfn-empty-state">
                                        <span class="dashicons dashicons-calendar-alt"></span>
                                        <p><?php esc_html_e('Nessun evento attivo disponibile.', 'dfn-theme'); ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($active_events as $e) :
                                $product_name = get_the_title($e->product_id) ?: __('Prodotto non trovato (ID: ' . $e->product_id . ')', 'dfn-theme');
                                $formatted_date = date_i18n('d M Y', strtotime($e->event_date_start));
                                if ($e->event_date_end && $e->event_date_end !== $e->event_date_start) {
                                    $formatted_date .= ' &rarr; ' . date_i18n('d M Y', strtotime($e->event_date_end));
                                }

                                if ('free_flow' === $e->access_type) {
                                    $booked = $wpdb->get_var($wpdb->prepare(
                                        "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings WHERE event_id = %d AND status != 'cancelled'",
                                        $e->id
                                    )) ?: 0;
                                    $capacity_display = $e->total_capacity > 0 ? "{$booked} / {$e->total_capacity}" : (string)$booked;
                                } else {
                                    $booked = $wpdb->get_var($wpdb->prepare(
                                        "SELECT SUM(booked_count) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d",
                                        $e->id
                                    )) ?: 0;
                                    $slots_total = $wpdb->get_var($wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d",
                                        $e->id
                                    )) ?: 0;
                                    $max_cap = $e->slot_capacity * $slots_total;
                                    $capacity_display = "{$booked} / {$max_cap}";
                                }
                                ?>
                                <tr>
                                    <td class="column-title">
                                        <strong><a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=dfn-checkin-manager&event_id=' . $e->id)); ?>"><?php echo esc_html($product_name); ?></a></strong>
                                    </td>
                                    <td>
                                        <div><strong><?php echo esc_html($formatted_date); ?></strong></div>
                                        <span class="dfn-small-sub"><span class="dashicons dashicons-location-alt"></span> <?php echo esc_html($e->location); ?></span>
                                    </td>
                                    <td>
                                        <div><strong><?php echo ('time_slots' === $e->access_type) ? '⏰ Fasce Orarie' : '🚪 Flusso Libero'; ?></strong></div>
                                    </td>
                                    <td>
                                        <div><strong><?php echo esc_html($capacity_display); ?></strong></div>
                                    </td>
                                    <td class="column-actions" style="text-align:right; padding-right:20px;">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-checkin-manager&event_id=' . $e->id)); ?>" class="button button-primary" style="background:#004b23; border-color:#003b1c; color:#fff;">
                                            <span class="dashicons dashicons-tickets-alt" style="margin-top:4px;"></span> <?php esc_html_e('Apri Check-in', 'dfn-theme'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (! empty($archived_events)) : ?>
                <div style="margin-top: 30px;"></div>
                <div class="dfn-card dfn-main-card">
                    <div class="dfn-card-header">
                        <h2><?php esc_html_e('Eventi Archiviati', 'dfn-theme'); ?></h2>
                        <span class="dfn-count-badge"><?php echo count($archived_events); ?> <?php esc_html_e('Archiviati', 'dfn-theme'); ?></span>
                    </div>

                    <table class="wp-list-table widefat fixed striped table-view-list dfn-events-table">
                        <thead>
                            <tr>
                                <th class="column-title"><?php esc_html_e('Nome Evento', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Data & Luogo', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Tipologia', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Prenotazioni', 'dfn-theme'); ?></th>
                                <th class="column-actions" style="text-align:right; padding-right:20px;"><?php esc_html_e('Azione', 'dfn-theme'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_events as $e) :
                                $product_name = get_the_title($e->product_id) ?: __('Prodotto non trovato (ID: ' . $e->product_id . ')', 'dfn-theme');
                                $formatted_date = date_i18n('d M Y', strtotime($e->event_date_start));
                                if ($e->event_date_end && $e->event_date_end !== $e->event_date_start) {
                                    $formatted_date .= ' &rarr; ' . date_i18n('d M Y', strtotime($e->event_date_end));
                                }

                                if ('free_flow' === $e->access_type) {
                                    $booked = $wpdb->get_var($wpdb->prepare(
                                        "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings WHERE event_id = %d AND status != 'cancelled'",
                                        $e->id
                                    )) ?: 0;
                                    $capacity_display = $e->total_capacity > 0 ? "{$booked} / {$e->total_capacity}" : (string)$booked;
                                } else {
                                    $booked = $wpdb->get_var($wpdb->prepare(
                                        "SELECT SUM(booked_count) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d",
                                        $e->id
                                    )) ?: 0;
                                    $slots_total = $wpdb->get_var($wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d",
                                        $e->id
                                    )) ?: 0;
                                    $max_cap = $e->slot_capacity * $slots_total;
                                    $capacity_display = "{$booked} / {$max_cap}";
                                }
                                ?>
                                <tr>
                                    <td class="column-title">
                                        <strong><a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=dfn-checkin-manager&event_id=' . $e->id)); ?>"><?php echo esc_html($product_name); ?></a></strong>
                                    </td>
                                    <td>
                                        <div><strong><?php echo esc_html($formatted_date); ?></strong></div>
                                        <span class="dfn-small-sub"><span class="dashicons dashicons-location-alt"></span> <?php echo esc_html($e->location); ?></span>
                                    </td>
                                    <td>
                                        <div><strong><?php echo ('time_slots' === $e->access_type) ? '⏰ Fasce Orarie' : '🚪 Flusso Libero'; ?></strong></div>
                                    </td>
                                    <td>
                                        <div><strong><?php echo esc_html($capacity_display); ?></strong></div>
                                    </td>
                                    <td class="column-actions" style="text-align:right; padding-right:20px;">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-checkin-manager&event_id=' . $e->id)); ?>" class="button button-secondary">
                                            <span class="dashicons dashicons-tickets-alt" style="margin-top:4px;"></span> <?php esc_html_e('Apri Check-in', 'dfn-theme'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return;
    }

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';
    $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_events} WHERE id = %d", $event_id));

    if (! $event) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Check-in Banchetto', 'dfn-theme'); ?></h1>
            <div class="notice notice-error">
                <p><?php esc_html_e('Evento non trovato.', 'dfn-theme'); ?></p>
            </div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-events')); ?>" class="button button-primary"><?php esc_html_e('Torna alla gestione eventi', 'dfn-theme'); ?></a></p>
        </div>
        <?php
        return;
    }

    $event_title = get_the_title($event->product_id) ?: sprintf(__('Evento #%d', 'dfn-theme'), $event->id);
    $is_free_flow = ('free_flow' === $event->access_type);

    // Date per i filtri
    $start_date = new DateTime($event->event_date_start);
    $end_date   = $event->event_date_end ? new DateTime($event->event_date_end) : clone $start_date;
    $interval   = new DateInterval('P1D');
    $period     = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

    $event_dates = [];
    foreach ($period as $date) {
        $event_dates[] = $date->format('Y-m-d');
    }

    $nonce = wp_create_nonce('dfn_admin_events_nonce');
    ?>
    <div class="wrap dfn-admin-wrap dfn-checkin-manager-wrap"
         data-event-id="<?php echo esc_attr((string) $event_id); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-access-type="<?php echo esc_attr($event->access_type); ?>"
         data-first-date="<?php echo esc_attr($event_dates[0] ?? $event->event_date_start); ?>">

        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <h1>&#127967; <?php esc_html_e('Check-in Banchetto', 'dfn-theme'); ?> &mdash; <span><?php echo esc_html($event_title); ?></span></h1>
                <span class="dfn-event-badge <?php echo $is_free_flow ? 'badge-free' : 'badge-slots'; ?>">
                    <?php echo $is_free_flow ? '&#128275; Flusso Libero' : '&#128336; A Turni'; ?>
                </span>
            </div>
            <div class="dfn-header-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-slot-manager&event_id=' . $event_id)); ?>" class="dfn-btn dfn-btn-secondary">
                    <span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Gestione Prenotazioni', 'dfn-theme'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-events')); ?>" class="dfn-btn dfn-btn-secondary">
                    <span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e('Torna agli eventi', 'dfn-theme'); ?>
                </a>
            </div>
        </header>

        <div id="dfn-ci-dashboard">
            <?php if (count($event_dates) > 1) : ?>
            <div class="dfn-pills-bar">
                <?php foreach ($event_dates as $index => $date) :
                    $dt = new DateTime($date);
                    $is_active = ($index === 0);
                    ?>
                    <button type="button" class="dfn-pill-date <?php echo $is_active ? 'active' : ''; ?>" data-date="<?php echo esc_attr($date); ?>">
                        <?php echo esc_html($dt->format('D d/m')); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="dfn-sm-toolbar">
                <div class="dfn-search-box">
                    <span class="dashicons dashicons-search"></span>
                    <input type="text" id="dfn-ci-search" placeholder="<?php esc_attr_e('Cerca per nome, email o telefono...', 'dfn-theme'); ?>">
                </div>
                <div class="dfn-toolbar-right" style="display:flex; gap:8px;">
                    <button type="button" id="dfn-ci-refresh" class="dfn-btn dfn-btn-secondary">
                        <span class="dashicons dashicons-update"></span> <?php esc_html_e('Aggiorna', 'dfn-theme'); ?>
                    </button>
                </div>
            </div>

            <div id="dfn-ci-grid" style="display: block;">
                <div class="dfn-loading">
                    <span class="dashicons dashicons-update spin"></span> <?php esc_html_e('Caricamento in corso...', 'dfn-theme'); ?>
                </div>
            </div>
        </div>

    </div><!-- .dfn-checkin-manager-wrap -->

    <!-- POPUP CASSA CHECK-IN -->
    <div id="cv-cassa-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:999999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:450px; box-shadow:0 10px 30px rgba(0,0,0,0.4); max-height:85vh; display:flex; flex-direction:column;">
            <h2 style="margin-top:0; font-size:22px; border-bottom: 2px solid #eee; padding-bottom: 10px;"><?php esc_html_e('Cassa Check-in', 'dfn-theme'); ?></h2>
            <p style="font-size:16px;"><?php esc_html_e('Cliente:', 'dfn-theme'); ?> <strong id="cv-modal-cliente-name" style="color:#2271b1;"></strong></p>
            <div id="cv-modal-buttons-area" style="flex-grow:1; overflow-y:auto; margin: 15px 0; padding-right: 5px;"></div>
            <button type="button" class="button cv-close-modal-btn" style="text-align:center; width:100%; padding: 10px; height: auto; font-size: 16px;"><?php esc_html_e('Chiudi Finestra', 'dfn-theme'); ?></button>
        </div>
    </div>

    <!-- POPUP LOG STORICO -->
    <div id="cv-history-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:999999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:500px; box-shadow:0 10px 30px rgba(0,0,0,0.4); max-height:85vh; display:flex; flex-direction:column;">
            <h2 style="margin-top:0; font-size:22px; border-bottom: 2px solid #eee; padding-bottom: 10px;"><?php esc_html_e('Log Operazioni Cliente', 'dfn-theme'); ?></h2>
            <p style="font-size:16px;"><?php esc_html_e('Ordine Cliente:', 'dfn-theme'); ?> <strong id="cv-history-cliente-name" style="color:#2271b1;"></strong></p>
            <div id="cv-history-content-area" style="flex-grow:1; overflow-y:auto; margin: 10px 0; padding:10px; background:#f9f9f9; border:1px solid #ddd; border-radius:5px;"></div>
            <button type="button" class="button cv-close-modal-btn" style="text-align:center; width:100%; padding: 10px; height: auto; font-size: 16px; margin-top:10px;"><?php esc_html_e('Chiudi Log', 'dfn-theme'); ?></button>
        </div>
    </div>
    <?php
}

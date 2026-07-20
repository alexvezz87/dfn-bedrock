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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Check-in Banchetto', 'dfn-theme'); ?></h1>
            <div class="notice notice-error">
                <p><?php esc_html_e('ID Evento non specificato o non valido.', 'dfn-theme'); ?></p>
            </div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-events')); ?>" class="button button-primary"><?php esc_html_e('Torna alla gestione eventi', 'dfn-theme'); ?></a></p>
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

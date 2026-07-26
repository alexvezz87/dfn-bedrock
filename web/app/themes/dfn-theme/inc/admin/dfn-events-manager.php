<?php
/**
 * DFN Booking System 2.0 — Central Admin Menu & Events Manager
 *
 * Registra il menu principale top-level "FAI Prenotazioni" e visualizza
 * il tabellone di controllo con la lista degli eventi configurati nel DB custom.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Registrazione del menu principale dell'amministrazione
add_action('admin_menu', 'dfn_admin_register_menus');

/**
 * Registra il menu principale e i sottomenu di FAI Prenotazioni.
 */
function dfn_admin_register_menus()
{
    // Menu principale (Top-level)
    add_menu_page(
        __('FAI Prenotazioni', 'dfn-theme'),
        __('FAI Prenotazioni', 'dfn-theme'),
        'dfn_manage_events',
        'dfn-events',
        'dfn_render_events_manager',
        'dashicons-calendar-alt',
        56,
    );

    // Sottomenu principale (duplica per avere lo stesso URL come primo elemento)
    add_submenu_page(
        'dfn-events',
        __('Gestione Eventi', 'dfn-theme'),
        __('Eventi', 'dfn-theme'),
        'dfn_manage_events',
        'dfn-events',
        'dfn_render_events_manager',
    );

    // Sottomenu "Aggiungi Evento"
    add_submenu_page(
        'dfn-events',
        __('Aggiungi Nuovo Evento', 'dfn-theme'),
        __('Aggiungi Evento', 'dfn-theme'),
        'dfn_manage_events',
        'dfn-event-edit',
        'dfn_render_event_editor',
    );

    // Sottomenu "Scanner Live"
    add_submenu_page(
        'dfn-events',
        __('Scanner Live', 'dfn-theme'),
        __('Scanner Live', 'dfn-theme'),
        'dfn_use_scanner',
        'dfn-scanner-live',
        'dfn_render_pagina_scanner_live',
    );

    // Sottomenu "Gestione Turni" (Nascosto dal menu principale ma accessibile via URL)
    add_submenu_page(
        null, // null lo nasconde dalla barra laterale di default
        __('Gestione Turni', 'dfn-theme'),
        __('Gestione Turni', 'dfn-theme'),
        'dfn_manage_events',
        'dfn-slot-manager',
        'dfn_render_slot_manager',
    );

    // Sottomenu "Check-in Banchetto" (Ora visibile nel menu principale per navigazione)
    add_submenu_page(
        'dfn-events',
        __('Check-in Banchetto', 'dfn-theme'),
        __('🎟️ Check-in', 'dfn-theme'),
        'dfn_manage_events',
        'dfn-checkin-manager',
        'dfn_render_checkin_manager',
    );

    // Sottomenu "Inserimento Rapido" (visibile alla segretaria e agli admin)
    add_submenu_page(
        'dfn-events',
        __('Inserimento Rapido', 'dfn-theme'),
        __('✏️ Inserimento Rapido', 'dfn-theme'),
        'dfn_quick_booking',
        'dfn-quick-booking',
        'dfn_render_quick_booking',
    );

    // Sottomenu "Verifica Prenotazioni FAI" — con badge count dinamico
    $pending_count = 0;
    if (function_exists('dfn_db_get_bookings_pending_approval_count')) {
        $pending_count = dfn_db_get_bookings_pending_approval_count();
    } else {
        global $wpdb;
        $pending_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_bookings WHERE status = 'pending_approval'"
        );
    }
    $pending_label = __('Verifica prenotazioni', 'dfn-theme');
    if ($pending_count > 0) {
        $pending_label .= ' <span id="dfn-pending-menu-badge" style="display:inline-block; background:#e53e3e; color:#fff; border-radius:10px; font-size:11px; font-weight:bold; padding:1px 7px; margin-left:4px; vertical-align:middle;">' . $pending_count . '</span>';
    }
    add_submenu_page(
        'dfn-events',
        __('Verifica Prenotazioni FAI', 'dfn-theme'),
        $pending_label,
        'dfn_manage_events',
        'dfn-fai-pending-bookings',
        'dfn_render_fai_pending_bookings',
    );

    // Enqueue degli asset specifici per l'admin
    add_action('admin_enqueue_scripts', 'dfn_enqueue_admin_assets');
}

/**
 * Enqueue di stili e script per il pannello di amministrazione FAI.
 *
 * @param string $hook Pagina admin corrente.
 */
function dfn_enqueue_admin_assets($hook)
{
    // Carichiamo gli asset solo per le nostre pagine
    if (
        strpos($hook, 'dfn-events') === false
        && strpos($hook, 'dfn-event-edit') === false
        && strpos($hook, 'dfn-slot-manager') === false
        && strpos($hook, 'dfn-checkin-manager') === false
        && strpos($hook, 'dfn-settings') === false
        && strpos($hook, 'dfn-quick-booking') === false
        && strpos($hook, 'dfn-fai-pending-bookings') === false
        && strpos($hook, 'dfn-fai-members') === false
    ) {
        return;
    }

    wp_enqueue_media();

    if (class_exists('WooCommerce')) {
        wp_enqueue_style('select2', WC()->plugin_url() . '/assets/css/select2.css', [], '4.0.3');
    }
    wp_enqueue_script('selectWoo');

    // CSS personalizzato
    wp_enqueue_style(
        'dfn-events-manager-css',
        get_stylesheet_directory_uri() . '/assets/css/dfn-events-manager.css',
        [],
        '2.0.0',
    );

    // Se siamo nello Slot Manager carichiamo il suo controller JS
    if (strpos($hook, 'dfn-slot-manager') !== false) {
        wp_enqueue_style('cv-report-css', get_stylesheet_directory_uri() . '/assets/css/cv-report.css', [], '1.0');
        wp_enqueue_script(
            'dfn-slot-manager-js',
            get_stylesheet_directory_uri() . '/assets/js/dfn-slot-manager.js',
            [ 'jquery' ],
            filemtime(get_stylesheet_directory() . '/assets/js/dfn-slot-manager.js'),
            true,
        );
        wp_localize_script('dfn-slot-manager-js', 'dfnAdminVars', [
            'ajaxurl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('dfn_admin_events_nonce'),
            'nonceManual'   => wp_create_nonce('cv_manual_checkin_nonce'),
            'nonceReminder' => wp_create_nonce('cv_reminder_nonce'),
            'nonceFeedback' => wp_create_nonce('cv_feedback_nonce'),
        ]);
    } elseif (strpos($hook, 'dfn-checkin-manager') !== false) {
        // Check-in Banchetto: carica CSS e JS dedicati
        wp_enqueue_style('cv-report-css', get_stylesheet_directory_uri() . '/assets/css/cv-report.css', [], '1.0');
        wp_enqueue_script(
            'dfn-checkin-manager-js',
            get_stylesheet_directory_uri() . '/assets/js/dfn-checkin-manager.js',
            [ 'jquery' ],
            filemtime(get_stylesheet_directory() . '/assets/js/dfn-checkin-manager.js'),
            true,
        );
        wp_localize_script('dfn-checkin-manager-js', 'dfnCheckinVars', [
            'ajaxurl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('dfn_admin_events_nonce'),
            'nonceManual'   => wp_create_nonce('cv_manual_checkin_nonce'),
            'nonceReminder' => wp_create_nonce('cv_reminder_nonce'),
            'nonceFeedback' => wp_create_nonce('cv_feedback_nonce'),
        ]);
    } elseif (strpos($hook, 'dfn-quick-booking') !== false) {
        // CSS Quick Booking
        wp_enqueue_style(
            'dfn-quick-booking-css',
            get_stylesheet_directory_uri() . '/assets/css/dfn-quick-booking.css',
            [],
            '1.0.0',
        );
        // JS Quick Booking
        wp_enqueue_script(
            'dfn-quick-booking-js',
            get_stylesheet_directory_uri() . '/assets/js/dfn-quick-booking.js',
            [ 'jquery' ],
            '1.0.0',
            true,
        );
        wp_localize_script('dfn-quick-booking-js', 'dfnQuickVars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('dfn_quick_booking_nonce'),
            'admin_nonce' => wp_create_nonce('dfn_admin_events_nonce'),
        ]);
    } elseif (strpos($hook, 'dfn-fai-pending-bookings') !== false) {
        // Verifica Prenotazioni FAI: carica CSS del manager + JS dedicato
        wp_enqueue_style('dfn-events-manager-css');
        wp_enqueue_script(
            'dfn-fai-pending-bookings-js',
            get_stylesheet_directory_uri() . '/assets/js/dfn-fai-pending-bookings.js',
            ['jquery'],
            filemtime(get_stylesheet_directory() . '/assets/js/dfn-fai-pending-bookings.js'),
            true,
        );
        wp_localize_script('dfn-fai-pending-bookings-js', 'dfnPendingVars', [
            'ajaxurl'              => admin_url('admin-ajax.php'),
            'nonce'                => wp_create_nonce('dfn_admin_pending_nonce'),
            'confirm_approve'      => __('Sei sicuro di voler approvare la prenotazione di {nome}? Verrà inviata al cliente la mail con il link di pagamento.', 'dfn-theme'),
            'processing'           => __('In corso…', 'dfn-theme'),
            'approve_label'        => __('Approva', 'dfn-theme'),
            'approved_label'       => __('Approvata ✓', 'dfn-theme'),
            'reject_for'           => __('Stai rifiutando la prenotazione di {nome}. Inserisci la motivazione che sarà comunicata al cliente.', 'dfn-theme'),
            'reject_confirm_label' => __('Conferma Rifiuto', 'dfn-theme'),
            'rejected_label'       => __('Rifiutata ✗', 'dfn-theme'),
            'da_verificare'        => __('da verificare', 'dfn-theme'),
            'generic_error'        => __('Si è verificato un errore. Riprova o contatta l\'assistenza.', 'dfn-theme'),
            'all_done'             => __('Nessuna prenotazione in attesa!', 'dfn-theme'),
            'all_done_sub'         => __('Tutte le prenotazioni FAI sono state verificate.', 'dfn-theme'),
        ]);
    } else {
        // Altrimenti carichiamo il JS dell'Events Manager standard
        wp_enqueue_script(
            'dfn-events-manager-js',
            get_stylesheet_directory_uri() . '/assets/js/dfn-events-manager.js',
            [ 'jquery', 'selectWoo' ],
            '2.0.0',
            true,
        );

        // Variabili localizzate
        wp_localize_script('dfn-events-manager-js', 'dfnAdminVars', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('dfn_admin_events_nonce'),
            'confirm_delete' => __('Sei sicuro di voler eliminare questo evento? Questa operazione eliminerà anche tutti gli slot e le prenotazioni correlate!', 'dfn-theme'),
            'confirm_slots'  => __('ATTENZIONE CRITICA: Il Reset degli slot eliminerà TUTTI i turni orari e TUTTE LE PRENOTAZIONI già inserite per questo evento! Questa operazione non è reversibile. Sei davvero sicuro di voler procedere?', 'dfn-theme'),
        ]);
    }
}

/**
 * Renderizza la bacheca di gestione eventi (Events Manager Dashboard).
 */
function dfn_render_events_manager()
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme'));
    }

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';

    // Gestione azioni rapide in GET (Generazione slot, Cambio stato, Eliminazione)
    $message = '';
    $message_type = 'success';

    if (isset($_GET['action']) && isset($_GET['event_id'])) {
        $event_id = intval($_GET['event_id']);

        if ('generate_slots' === $_GET['action']) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dfn_gen_slots_' . $event_id)) {
                $slots_count = dfn_db_generate_slots_for_event($event_id);
                $message = sprintf(__('Generazione completata con successo! Creati %d slot orari.', 'dfn-theme'), $slots_count);
            } else {
                $message = __('Errore di sicurezza: verifica del nonce fallita.', 'dfn-theme');
                $message_type = 'error';
            }
        }

        if ('delete' === $_GET['action']) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_event_' . $event_id)) {
                // Rimuovi l'evento
                $wpdb->delete($table_events, [ 'id' => $event_id ], [ '%d' ]);
                // Rimuovi gli slot associati
                $wpdb->delete($wpdb->prefix . 'dfn_event_slots', [ 'event_id' => $event_id ], [ '%d' ]);

                $message = __('Evento eliminato con successo dal database.', 'dfn-theme');
            } else {
                $message = __('Errore di sicurezza durante l\'eliminazione.', 'dfn-theme');
                $message_type = 'error';
            }
        }

        if ('toggle_status' === $_GET['action'] && isset($_GET['status'])) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dfn_status_event_' . $event_id)) {
                $new_status = sanitize_text_field($_GET['status']);
                if (in_array($new_status, [ 'draft', 'published', 'archived' ], true)) {
                    $wpdb->update(
                        $table_events,
                        [ 'status' => $new_status ],
                        [ 'id' => $event_id ],
                        [ '%s' ],
                        [ '%d' ],
                    );
                    $message = sprintf(__('Stato dell\'evento aggiornato a "%s".', 'dfn-theme'), $new_status);
                }
            } else {
                $message = __('Verifica fallita.', 'dfn-theme');
                $message_type = 'error';
            }
        }

        if ('recalculate_slots' === $_GET['action']) {
            if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dfn_recalc_slots_' . $event_id)) {
                if (! function_exists('dfn_db_recalculate_event_slots_booked_count')) {
                    require_once get_template_directory() . '/inc/core/dfn-database.php';
                }
                dfn_db_recalculate_event_slots_booked_count($event_id);
                $message = __('Ricalcolo e sincronizzazione dei conteggi completati con successo!', 'dfn-theme');
            } else {
                $message = __('Errore di sicurezza durante il ricalcolo.', 'dfn-theme');
                $message_type = 'error';
            }
        }
    }

    // Carica gli eventi
    $events = $wpdb->get_results("SELECT * FROM {$table_events} ORDER BY event_date_start DESC");
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-calendar-alt"></span>
                <h1><?php esc_html_e('FAI Prenotazioni — Tabellone Eventi', 'dfn-theme'); ?></h1>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-event-edit')); ?>" class="page-title-action dfn-btn dfn-btn-primary">
                <span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e('Crea Nuovo Evento', 'dfn-theme'); ?>
            </a>
        </header>

        <?php if (! empty($message)) : ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <div class="dfn-card dfn-main-card">
            <div class="dfn-card-header">
                <h2><?php esc_html_e('Elenco Eventi Attivi', 'dfn-theme'); ?></h2>
                <span class="dfn-count-badge"><?php echo count($events); ?> <?php esc_html_e('Eventi in totale', 'dfn-theme'); ?></span>
            </div>

            <table class="wp-list-table widefat fixed striped table-view-list dfn-events-table">
                <thead>
                    <tr>
                        <th class="column-title"><?php esc_html_e('Nome Prodotto WooCommerce / Evento', 'dfn-theme'); ?></th>
                        <th><?php esc_html_e('Data & Luogo', 'dfn-theme'); ?></th>
                        <th><?php esc_html_e('Orario & Canali', 'dfn-theme'); ?></th>
                        <th><?php esc_html_e('Tipologia / Allocazione', 'dfn-theme'); ?></th>
                        <th><?php esc_html_e('Capacità', 'dfn-theme'); ?></th>
                        <th><?php esc_html_e('Stato', 'dfn-theme'); ?></th>
                        <th class="column-actions"><?php esc_html_e('Azioni di Gestione', 'dfn-theme'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($events)) : ?>
                        <tr>
                            <td colspan="7" class="dfn-empty-row">
                                <div class="dfn-empty-state">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <p><?php esc_html_e('Nessun evento configurato nel database custom.', 'dfn-theme'); ?></p>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-event-edit')); ?>" class="button button-primary">
                                        <?php esc_html_e('Aggiungi il tuo primo evento', 'dfn-theme'); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($events as $event) :
                            $product_name = get_the_title($event->product_id) ?: __('Prodotto non trovato (ID: ' . $event->product_id . ')', 'dfn-theme');
                            $formatted_date = date_i18n('d M Y', strtotime($event->event_date_start));
                            if ($event->event_date_end && $event->event_date_end !== $event->event_date_start) {
                                $formatted_date .= ' &rarr; ' . date_i18n('d M Y', strtotime($event->event_date_end));
                            }

                            // Ricalcola conteggi prima del caricamento per sicurezza ed evitare dati sporchi
                            if (function_exists('dfn_db_recalculate_event_slots_booked_count')) {
                                dfn_db_recalculate_event_slots_booked_count($event->id);
                            }

                            // Calcola slot occupati / totali
                            if ('free_flow' === $event->access_type) {
                                $slot_booked = $wpdb->get_var($wpdb->prepare(
                                    "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings WHERE event_id = %d AND status != 'cancelled'",
                                    $event->id,
                                )) ?: 0;
                                $slots_total = 0;
                            } else {
                                $slot_booked = $wpdb->get_var($wpdb->prepare(
                                    "SELECT SUM(booked_count) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d",
                                    $event->id,
                                )) ?: 0;
                                $slots_total = $wpdb->get_var($wpdb->prepare(
                                    "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d",
                                    $event->id,
                                )) ?: 0;
                            }

                            // Badge stili
                            $status_class = 'dfn-status-' . $event->status;
                            $allocation_mode_label = ('automatic' === $event->allocation_mode) ? '🤖 Automatica' : '👈 Self Selection';
                            $payment_mode_label = '💳 Online';
                            if ('in_loco' === $event->payment_mode) {
                                $payment_mode_label = '💵 In Loco';
                            }
                            if ('hybrid' === $event->payment_mode) {
                                $payment_mode_label = '🔄 Ibrida';
                            }
                            ?>
                            <tr>
                                <td class="column-title">
                                    <strong><a class="row-title" href="<?php echo esc_url(admin_url('admin.php?page=dfn-event-edit&id=' . $event->id)); ?>"><?php echo esc_html($product_name); ?></a></strong>
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-event-edit&id=' . $event->id)); ?>"><?php esc_html_e('Modifica', 'dfn-theme'); ?></a> | </span>
                                        <span class="view"><a href="<?php echo esc_url(get_permalink($event->product_id)); ?>" target="_blank"><?php esc_html_e('Vedi Prodotto', 'dfn-theme'); ?></a> | </span>
                                        <span class="trash"><a class="submitdelete dfn-btn-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-events&action=delete&event_id=' . $event->id), 'dfn_del_event_' . $event->id)); ?>"><?php esc_html_e('Elimina', 'dfn-theme'); ?></a></span>
                                    </div>
                                </td>
                                <td>
                                    <div><strong><?php echo esc_html($formatted_date); ?></strong></div>
                                    <span class="dfn-small-sub"><span class="dashicons dashicons-location-alt"></span> <?php echo esc_html($event->location); ?></span>
                                </td>
                                <td>
                                    <div><?php echo date('H:i', strtotime($event->event_time_start)); ?> - <?php echo $event->event_time_end ? date('H:i', strtotime($event->event_time_end)) : 'FINE'; ?></div>
                                    <span class="dfn-small-sub"><span class="dashicons dashicons-cart"></span> <?php echo esc_html($payment_mode_label); ?></span>
                                </td>
                                <td>
                                    <div><strong><?php echo ('time_slots' === $event->access_type) ? '⏰ Fasce Orarie' : '🚪 Flusso Libero'; ?></strong></div>
                                    <span class="dfn-small-sub"><?php echo esc_html($allocation_mode_label); ?></span>
                                </td>
                                <td>
                                    <?php if ('time_slots' === $event->access_type) : ?>
                                        <div class="dfn-progress-bar-container">
                                            <div class="dfn-progress-text"><?php echo esc_html((string) $slot_booked); ?> / <?php echo esc_html((string) ($event->slot_capacity * $slots_total)); ?> <?php esc_html_e('posti', 'dfn-theme'); ?></div>
                                            <div class="dfn-progress-bar">
                                                <?php
                                                $pct = 0;
                                        $max_cap = $event->slot_capacity * $slots_total;
                                        if ($max_cap > 0) {
                                            $pct = min(100, round(($slot_booked / $max_cap) * 100));
                                        }
                                        ?>
                                                <span class="dfn-progress-fill" style="width: <?php echo $pct; ?>%;"></span>
                                            </div>
                                        </div>
                                        <span class="dfn-small-sub"><?php echo esc_html($slots_total); ?> <?php esc_html_e('turni generati', 'dfn-theme'); ?></span>
                                    <?php else : ?>
                                        <div><strong><?php echo esc_html($slot_booked); ?> / <?php echo esc_html($event->total_capacity); ?></strong></div>
                                        <span class="dfn-small-sub"><?php esc_html_e('Capacità totale', 'dfn-theme'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="dfn-badge <?php echo esc_attr($status_class); ?>">
                                        <?php
                                        if ('published' === $event->status) {
                                            esc_html_e('Pubblicato', 'dfn-theme');
                                        } elseif ('private' === $event->status) {
                                            esc_html_e('Privato', 'dfn-theme');
                                        } elseif ('archived' === $event->status) {
                                            esc_html_e('Archiviato', 'dfn-theme');
                                        } else {
                                            esc_html_e('Bozza', 'dfn-theme');
                                        }
                            ?>
                                    </span>
                                </td>
                                <td class="column-actions">
                                    <div class="dfn-actions-row">
                                        <?php if ('time_slots' === $event->access_type) : ?>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-slot-manager&event_id=' . $event->id)); ?>" class="button button-small dfn-action-btn dfn-btn-turni" title="<?php esc_attr_e('Gestione Visuale dei Turni e delle Prenotazioni', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Turni', 'dfn-theme'); ?>
                                            </a>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-checkin-manager&event_id=' . $event->id)); ?>" class="button button-small dfn-action-btn" style="background:#004b23; border-color:#003b1c; color:#fff;" title="<?php esc_attr_e('Tabellone Check-in per il banchetto', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-tickets-alt"></span> <?php esc_html_e('Check-in', 'dfn-theme'); ?>
                                            </a>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-events&action=recalculate_slots&event_id=' . $event->id), 'dfn_recalc_slots_' . $event->id)); ?>" class="button button-small dfn-action-btn dfn-btn-recalc" title="<?php esc_attr_e('Ricalcola e allinea i conteggi delle prenotazioni', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-calculator"></span> <?php esc_html_e('Ricalcola', 'dfn-theme'); ?>
                                            </a>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-events&action=generate_slots&event_id=' . $event->id), 'dfn_gen_slots_' . $event->id)); ?>" class="button button-small dfn-action-btn dfn-btn-reset" title="<?php esc_attr_e('Genera/Rigenera tutti i turni orari per questo evento', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-update"></span> <?php esc_html_e('Reset Slot', 'dfn-theme'); ?>
                                            </a>
                                        <?php else : ?>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-slot-manager&event_id=' . $event->id)); ?>" class="button button-small dfn-action-btn dfn-btn-turni" title="<?php esc_attr_e('Visualizza e gestisci le prenotazioni per questo evento', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Prenotazioni', 'dfn-theme'); ?>
                                            </a>
                                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-checkin-manager&event_id=' . $event->id)); ?>" class="button button-small dfn-action-btn" style="background:#004b23; border-color:#003b1c; color:#fff;" title="<?php esc_attr_e('Tabellone Check-in per il banchetto', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-tickets-alt"></span> <?php esc_html_e('Check-in', 'dfn-theme'); ?>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ('published' === $event->status) : ?>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-events&action=toggle_status&status=draft&event_id=' . $event->id), 'dfn_status_event_' . $event->id)); ?>" class="button button-small dfn-action-btn dfn-btn-status-draft" title="<?php esc_attr_e('Passa a bozza per nasconderlo', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-hidden"></span> <?php esc_html_e('Bozza', 'dfn-theme'); ?>
                                            </a>
                                        <?php else : ?>
                                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-events&action=toggle_status&status=published&event_id=' . $event->id), 'dfn_status_event_' . $event->id)); ?>" class="button button-small dfn-action-btn dfn-btn-status-pub" title="<?php esc_attr_e('Pubblica evento', 'dfn-theme'); ?>">
                                                <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('Attiva', 'dfn-theme'); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

// ============================================================================
// Include: Check-in Banchetto Manager
// ============================================================================
require_once get_stylesheet_directory() . '/inc/admin/dfn-checkin-manager.php';

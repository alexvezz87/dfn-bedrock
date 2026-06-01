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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Registrazione del menu principale dell'amministrazione
add_action( 'admin_menu', 'dfn_admin_register_menus' );

/**
 * Registra il menu principale e i sottomenu di FAI Prenotazioni.
 */
function dfn_admin_register_menus() {
    // Menu principale (Top-level)
    add_menu_page(
        __( 'FAI Prenotazioni', 'dfn-theme' ),
        __( 'FAI Prenotazioni', 'dfn-theme' ),
        'dfn_manage_events',
        'dfn-events',
        'dfn_render_events_manager',
        'dashicons-calendar-alt',
        56
    );

    // Sottomenu principale (duplica per avere lo stesso URL come primo elemento)
    add_submenu_page(
        'dfn-events',
        __( 'Gestione Eventi', 'dfn-theme' ),
        __( 'Eventi', 'dfn-theme' ),
        'dfn_manage_events',
        'dfn-events',
        'dfn_render_events_manager'
    );

    // Sottomenu "Aggiungi Evento"
    add_submenu_page(
        'dfn-events',
        __( 'Aggiungi Nuovo Evento', 'dfn-theme' ),
        __( 'Aggiungi Evento', 'dfn-theme' ),
        'dfn_manage_events',
        'dfn-event-edit',
        'dfn_render_event_editor'
    );

    // Enqueue degli asset specifici per l'admin
    add_action( 'admin_enqueue_scripts', 'dfn_enqueue_admin_assets' );
}

/**
 * Enqueue di stili e script per il pannello di amministrazione FAI.
 *
 * @param string $hook Pagina admin corrente.
 */
function dfn_enqueue_admin_assets( $hook ) {
    // Carichiamo gli asset solo per le nostre pagine
    if ( strpos( $hook, 'dfn-events' ) === false && strpos( $hook, 'dfn-event-edit' ) === false ) {
        return;
    }

    wp_enqueue_style( 'select2' );
    wp_enqueue_script( 'selectWoo' );

    // CSS personalizzato
    wp_enqueue_style(
        'dfn-events-manager-css',
        get_stylesheet_directory_uri() . '/assets/css/dfn-events-manager.css',
        array(),
        '2.0.0'
    );

    // JS personalizzato
    wp_enqueue_script(
        'dfn-events-manager-js',
        get_stylesheet_directory_uri() . '/assets/js/dfn-events-manager.js',
        array( 'jquery', 'selectWoo' ),
        '2.0.0',
        true
    );

    // Variabili localizzate
    wp_localize_script( 'dfn-events-manager-js', 'dfnAdminVars', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'dfn_admin_events_nonce' ),
        'confirm_delete' => __( 'Sei sicuro di voler eliminare questo evento? Questa operazione eliminerà anche tutti gli slot e le prenotazioni correlate!', 'dfn-theme' ),
        'confirm_slots'  => __( 'Rigenerando gli slot eliminerai quelli attuali. Continuare?', 'dfn-theme' )
    ) );
}

/**
 * Renderizza la bacheca di gestione eventi (Events Manager Dashboard).
 */
function dfn_render_events_manager() {
    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        wp_die( esc_html__( 'Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme' ) );
    }

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';

    // Gestione azioni rapide in GET (Generazione slot, Cambio stato, Eliminazione)
    $message = '';
    $message_type = 'success';

    if ( isset( $_GET['action'] ) && isset( $_GET['event_id'] ) ) {
        $event_id = intval( $_GET['event_id'] );

        if ( 'generate_slots' === $_GET['action'] ) {
            if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'dfn_gen_slots_' . $event_id ) ) {
                $slots_count = dfn_db_generate_slots_for_event( $event_id );
                $message = sprintf( __( 'Generazione completata con successo! Creati %d slot orari.', 'dfn-theme' ), $slots_count );
            } else {
                $message = __( 'Errore di sicurezza: verifica del nonce fallita.', 'dfn-theme' );
                $message_type = 'error';
            }
        }

        if ( 'delete' === $_GET['action'] ) {
            if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'dfn_del_event_' . $event_id ) ) {
                // Rimuovi l'evento
                $wpdb->delete( $table_events, array( 'id' => $event_id ), array( '%d' ) );
                // Rimuovi gli slot associati
                $wpdb->delete( $wpdb->prefix . 'dfn_event_slots', array( 'event_id' => $event_id ), array( '%d' ) );
                
                $message = __( 'Evento eliminato con successo dal database.', 'dfn-theme' );
            } else {
                $message = __( 'Errore di sicurezza durante l\'eliminazione.', 'dfn-theme' );
                $message_type = 'error';
            }
        }

        if ( 'toggle_status' === $_GET['action'] && isset( $_GET['status'] ) ) {
            if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'dfn_status_event_' . $event_id ) ) {
                $new_status = sanitize_text_field( $_GET['status'] );
                if ( in_array( $new_status, array( 'draft', 'published', 'archived' ), true ) ) {
                    $wpdb->update(
                        $table_events,
                        array( 'status' => $new_status ),
                        array( 'id' => $event_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    $message = sprintf( __( 'Stato dell\'evento aggiornato a "%s".', 'dfn-theme' ), $new_status );
                }
            } else {
                $message = __( 'Verifica fallita.', 'dfn-theme' );
                $message_type = 'error';
            }
        }
    }

    // Carica gli eventi
    $events = $wpdb->get_results( "SELECT * FROM {$table_events} ORDER BY event_date_start DESC" );
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-calendar-alt"></span>
                <h1><?php esc_html_e( 'FAI Prenotazioni — Tabellone Eventi', 'dfn-theme' ); ?></h1>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-event-edit' ) ); ?>" class="page-title-action dfn-btn dfn-btn-primary">
                <span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e( 'Crea Nuovo Evento', 'dfn-theme' ); ?>
            </a>
        </header>

        <?php if ( ! empty( $message ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
        <?php endif; ?>

        <div class="dfn-card dfn-main-card">
            <div class="dfn-card-header">
                <h2><?php esc_html_e( 'Elenco Eventi Attivi', 'dfn-theme' ); ?></h2>
                <span class="dfn-count-badge"><?php echo count( $events ); ?> <?php esc_html_e( 'Eventi in totale', 'dfn-theme' ); ?></span>
            </div>

            <table class="wp-list-table widefat fixed striped table-view-list dfn-events-table">
                <thead>
                    <tr>
                        <th class="column-title"><?php esc_html_e( 'Nome Prodotto WooCommerce / Evento', 'dfn-theme' ); ?></th>
                        <th><?php esc_html_e( 'Data & Luogo', 'dfn-theme' ); ?></th>
                        <th><?php esc_html_e( 'Orario & Canali', 'dfn-theme' ); ?></th>
                        <th><?php esc_html_e( 'Tipologia / Allocazione', 'dfn-theme' ); ?></th>
                        <th><?php esc_html_e( 'Capacità', 'dfn-theme' ); ?></th>
                        <th><?php esc_html_e( 'Stato', 'dfn-theme' ); ?></th>
                        <th class="column-actions"><?php esc_html_e( 'Azioni di Gestione', 'dfn-theme' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $events ) ) : ?>
                        <tr>
                            <td colspan="7" class="dfn-empty-row">
                                <div class="dfn-empty-state">
                                    <span class="dashicons dashicons-calendar-alt"></span>
                                    <p><?php esc_html_e( 'Nessun evento configurato nel database custom.', 'dfn-theme' ); ?></p>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-event-edit' ) ); ?>" class="button button-primary">
                                        <?php esc_html_e( 'Aggiungi il tuo primo evento', 'dfn-theme' ); ?>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $events as $event ) :
                            $product_name = get_the_title( $event->product_id ) ?: __( 'Prodotto non trovato (ID: ' . $event->product_id . ')', 'dfn-theme' );
                            $formatted_date = date_i18n( 'd M Y', strtotime( $event->event_date_start ) );
                            if ( $event->event_date_end && $event->event_date_end !== $event->event_date_start ) {
                                $formatted_date .= ' &rarr; ' . date_i18n( 'd M Y', strtotime( $event->event_date_end ) );
                            }

                            // Calcola slot occupati / totali
                            $slot_booked = $wpdb->get_var( $wpdb->prepare(
                                "SELECT SUM(booked_count) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d", $event->id
                            ) ) ?: 0;
                            $slots_total = $wpdb->get_var( $wpdb->prepare(
                                "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d", $event->id
                            ) ) ?: 0;

                            // Badge stili
                            $status_class = 'dfn-status-' . $event->status;
                            $allocation_mode_label = ( 'automatic' === $event->allocation_mode ) ? '🤖 Automatica' : '👈 Self Selection';
                            $payment_mode_label = '💳 Online';
                            if ( 'in_loco' === $event->payment_mode ) $payment_mode_label = '💵 In Loco';
                            if ( 'hybrid' === $event->payment_mode ) $payment_mode_label = '🔄 Ibrida';
                            ?>
                            <tr>
                                <td class="column-title">
                                    <strong><a class="row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-event-edit&id=' . $event->id ) ); ?>"><?php echo esc_html( $product_name ); ?></a></strong>
                                    <div class="row-actions">
                                        <span class="edit"><a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-event-edit&id=' . $event->id ) ); ?>"><?php esc_html_e( 'Modifica', 'dfn-theme' ); ?></a> | </span>
                                        <span class="view"><a href="<?php echo esc_url( get_permalink( $event->product_id ) ); ?>" target="_blank"><?php esc_html_e( 'Vedi Prodotto', 'dfn-theme' ); ?></a> | </span>
                                        <span class="trash"><a class="submitdelete dfn-btn-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dfn-events&action=delete&event_id=' . $event->id ), 'dfn_del_event_' . $event->id ) ); ?>"><?php esc_html_e( 'Elimina', 'dfn-theme' ); ?></a></span>
                                    </div>
                                </td>
                                <td>
                                    <div><strong><?php echo esc_html( $formatted_date ); ?></strong></div>
                                    <span class="dfn-small-sub"><span class="dashicons dashicons-location-alt"></span> <?php echo esc_html( $event->location ); ?></span>
                                </td>
                                <td>
                                    <div><?php echo date( 'H:i', strtotime( $event->event_time_start ) ); ?> - <?php echo $event->event_time_end ? date( 'H:i', strtotime( $event->event_time_end ) ) : 'FINE'; ?></div>
                                    <span class="dfn-small-sub"><span class="dashicons dashicons-cart"></span> <?php echo esc_html( $payment_mode_label ); ?></span>
                                </td>
                                <td>
                                    <div><strong><?php echo ( 'time_slots' === $event->access_type ) ? '⏰ Fasce Orarie' : '🚪 Flusso Libero'; ?></strong></div>
                                    <span class="dfn-small-sub"><?php echo esc_html( $allocation_mode_label ); ?></span>
                                </td>
                                <td>
                                    <?php if ( 'time_slots' === $event->access_type ) : ?>
                                        <div class="dfn-progress-bar-container">
                                            <div class="dfn-progress-text"><?php echo esc_html( $slot_booked ); ?> / <?php echo esc_html( $event->slot_capacity * $slots_total ); ?> <?php esc_html_e( 'posti', 'dfn-theme' ); ?></div>
                                            <div class="dfn-progress-bar">
                                                <?php 
                                                $pct = 0;
                                                $max_cap = $event->slot_capacity * $slots_total;
                                                if ( $max_cap > 0 ) {
                                                    $pct = min( 100, round( ( $slot_booked / $max_cap ) * 100 ) );
                                                }
                                                ?>
                                                <span class="dfn-progress-fill" style="width: <?php echo $pct; ?>%;"></span>
                                            </div>
                                        </div>
                                        <span class="dfn-small-sub"><?php echo esc_html( $slots_total ); ?> <?php esc_html_e( 'turni generati', 'dfn-theme' ); ?></span>
                                    <?php else : ?>
                                        <div><strong><?php echo esc_html( $slot_booked ); ?> / <?php echo esc_html( $event->total_capacity ); ?></strong></div>
                                        <span class="dfn-small-sub"><?php esc_html_e( 'Capacità totale', 'dfn-theme' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="dfn-badge <?php echo esc_attr( $status_class ); ?>">
                                        <?php 
                                        if ( 'published' === $event->status ) esc_html_e( 'Pubblicato', 'dfn-theme' );
                                        elseif ( 'archived' === $event->status ) esc_html_e( 'Archiviato', 'dfn-theme' );
                                        else esc_html_e( 'Bozza', 'dfn-theme' );
                                        ?>
                                    </span>
                                </td>
                                <td class="column-actions">
                                    <div class="dfn-actions-row">
                                        <?php if ( 'time_slots' === $event->access_type ) : ?>
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dfn-events&action=generate_slots&event_id=' . $event->id ), 'dfn_gen_slots_' . $event->id ) ); ?>" class="button button-small dfn-btn-icon" title="<?php esc_attr_e( 'Genera/Rigenera tutti i turni orari per questo evento', 'dfn-theme' ); ?>">
                                                <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Slot', 'dfn-theme' ); ?>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ( 'published' === $event->status ) : ?>
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dfn-events&action=toggle_status&status=draft&event_id=' . $event->id ), 'dfn_status_event_' . $event->id ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Passa a bozza per nasconderlo', 'dfn-theme' ); ?>">
                                                <span class="dashicons dashicons-hidden"></span> <?php esc_html_e( 'Bozza', 'dfn-theme' ); ?>
                                            </a>
                                        <?php else : ?>
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dfn-events&action=toggle_status&status=published&event_id=' . $event->id ), 'dfn_status_event_' . $event->id ) ); ?>" class="button button-small button-primary" title="<?php esc_attr_e( 'Pubblica evento', 'dfn-theme' ); ?>">
                                                <span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Attiva', 'dfn-theme' ); ?>
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

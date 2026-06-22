<?php
/**
 * DFN Booking System 2.0 — Slot Manager Page
 *
 * Visualizza e gestisce i turni/slot e le prenotazioni per un singolo evento.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renderizza la pagina dello Slot Manager.
 */
function dfn_render_slot_manager()
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme'));
    }

    $event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
    if ($event_id <= 0) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Gestione Turni', 'dfn-theme'); ?></h1>
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
            <h1><?php esc_html_e('Gestione Turni', 'dfn-theme'); ?></h1>
            <div class="notice notice-error">
                <p><?php esc_html_e('Evento non trovato.', 'dfn-theme'); ?></p>
            </div>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=dfn-events')); ?>" class="button button-primary"><?php esc_html_e('Torna alla gestione eventi', 'dfn-theme'); ?></a></p>
        </div>
        <?php
        return;
    }

    // Recupera titolo del prodotto WooCommerce associato
    $event_title = get_the_title($event->product_id) ?: sprintf(__('Evento #%d', 'dfn-theme'), $event->id);

    // Determina se l'evento è a flusso continuo (free_flow) o a fasce orarie (time_slots)
    $is_free_flow = ('free_flow' === $event->access_type);

    // Recupera il numero di slot configurati nel database
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $slots_count = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_slots} WHERE event_id = %d", $event_id)));

    // Calcola le date comprese tra data inizio e data fine evento per i filtri/pills
    $start_date = new DateTime($event->event_date_start);
    $end_date   = $event->event_date_end ? new DateTime($event->event_date_end) : clone $start_date;
    $interval   = new DateInterval('P1D');
    $period     = new DatePeriod($start_date, $interval, $end_date->modify('+1 day'));

    $event_dates = [];
    foreach ($period as $date) {
        $event_dates[] = $date->format('Y-m-d');
    }

    // Nonce di sicurezza
    $nonce = wp_create_nonce('dfn_admin_events_nonce');
    ?>
    <div class="wrap dfn-admin-wrap dfn-slot-manager-wrap" data-event-id="<?php echo esc_attr((string) $event_id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-access-type="<?php echo esc_attr($event->access_type); ?>">
        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <span class="dashicons <?php echo $is_free_flow ? 'dashicons-list-view' : 'dashicons-admin-generic'; ?>"></span>
                <h1><?php echo $is_free_flow
                    ? sprintf(esc_html__('Prenotazioni — %s', 'dfn-theme'), esc_html($event_title))
                    : sprintf(esc_html__('Gestione Turni — %s', 'dfn-theme'), esc_html($event_title)); ?></h1>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-events')); ?>" class="dfn-btn dfn-btn-secondary">
                <span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e('Torna agli Eventi', 'dfn-theme'); ?>
            </a>
        </header>

        <?php if (! $is_free_flow) : ?>
        <!-- Banner Generazione Slot Pregressi (mostrato solo se non ci sono slot nel DB, solo per eventi a fasce orarie) -->
        <div id="dfn-sm-generation-banner" class="dfn-card dfn-generation-card" style="<?php echo ($slots_count > 0) ? 'display:none;' : ''; ?>">
            <div class="dfn-card-body">
                <span class="dashicons dashicons-warning yellow-icon"></span>
                <div class="generation-text">
                    <h3><?php esc_html_e('Nessuno slot generato per questo evento', 'dfn-theme'); ?></h3>
                    <p><?php esc_html_e('Questo evento non ha ancora slot temporali configurati nel database. Puoi generarli ora in base alle date e alla frequenza definita nelle impostazioni dell\'evento.', 'dfn-theme'); ?></p>
                </div>
                <button type="button" id="dfn-btn-generate-slots" class="dfn-btn dfn-btn-primary">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e('Genera Slot Iniziali', 'dfn-theme'); ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Dashboard Operativa -->
        <!-- Per free_flow sempre visibile; per time_slots nascosta finché non ci sono slot -->
        <div id="dfn-sm-dashboard" style="<?php echo (! $is_free_flow && $slots_count === 0) ? 'display:none;' : ''; ?>">
            
            <!-- Riepilogo Statistico -->
            <div class="dfn-stats-row">
                <div class="dfn-stat-card">
                    <div class="stat-value" id="dfn-stat-total-bookings">-</div>
                    <div class="stat-label"><?php esc_html_e('Prenotazioni Attive', 'dfn-theme'); ?></div>
                </div>
                <div class="dfn-stat-card">
                    <div class="stat-value" id="dfn-stat-occupied-places">-</div>
                    <div class="stat-label"><?php esc_html_e('Posti Occupati', 'dfn-theme'); ?></div>
                </div>
                <div class="dfn-stat-card">
                    <div class="stat-value" id="dfn-stat-occupancy-percent">-</div>
                    <div class="stat-label"><?php esc_html_e('Tasso di Occupazione', 'dfn-theme'); ?></div>
                </div>
                <div class="dfn-stat-card">
                    <div class="stat-value" id="dfn-stat-fai-to-verify">-</div>
                    <div class="stat-label"><?php esc_html_e('Tessere FAI da Verificare', 'dfn-theme'); ?></div>
                </div>
            </div>

            <!-- Controlli e Navigazione -->
            <div class="dfn-controls-bar">
                <div class="dfn-pills-container">
                    <?php foreach ($event_dates as $index => $date_str) :
                        $formatted_date = date_i18n('D d M', strtotime($date_str));
                        $active_class = (0 === $index) ? 'active' : '';
                        ?>
                        <button type="button" class="dfn-pill-date <?php echo $active_class; ?>" data-date="<?php echo esc_attr($date_str); ?>">
                            <?php echo esc_html($formatted_date); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="dfn-actions-container">
                    <div class="search-box">
                        <span class="dashicons dashicons-search"></span>
                        <input type="text" id="dfn-sm-search" placeholder="<?php esc_html_e('Cerca prenotazione...', 'dfn-theme'); ?>">
                    </div>
                    <button type="button" id="dfn-btn-export-csv" class="dfn-btn dfn-btn-outline">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e('Esporta CSV', 'dfn-theme'); ?>
                    </button>
                    <button type="button" id="dfn-btn-print-pdf" class="dfn-btn dfn-btn-outline">
                        <span class="dashicons dashicons-printer"></span> <?php esc_html_e('Stampa PDF', 'dfn-theme'); ?>
                    </button>
                    <button type="button" id="dfn-btn-add-slot-modal" class="dfn-btn dfn-btn-secondary">
                        <span class="dashicons dashicons-plus"></span> <?php esc_html_e('Aggiungi Slot', 'dfn-theme'); ?>
                    </button>
                    <button type="button" id="dfn-btn-add-booking-modal" class="dfn-btn dfn-btn-primary">
                        <span class="dashicons dashicons-businessman"></span> <?php esc_html_e('Nuova Prenotazione', 'dfn-theme'); ?>
                    </button>
                </div>
            </div>

            <!-- Griglia Slot e Prenotazioni -->
            <div class="dfn-sm-grid-container">
                <div id="dfn-sm-slots-grid" class="dfn-sm-slots-grid">
                    <!-- Popolato via JS -->
                </div>
            </div>

        </div>
    </div>

    <!-- MODALE 1: Aggiungi Slot Orario -->
    <div id="dfn-modal-add-slot" class="dfn-sm-modal">
        <div class="dfn-sm-modal-content">
            <div class="dfn-sm-modal-header">
                <h3><?php esc_html_e('Aggiungi Nuovo Slot Orario', 'dfn-theme'); ?></h3>
                <span class="dfn-modal-close" data-modal="dfn-modal-add-slot">&times;</span>
            </div>
            <form id="dfn-form-add-slot">
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Ora Inizio', 'dfn-theme'); ?> *</label>
                    <input type="time" name="time_start" required>
                </div>
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Ora Fine', 'dfn-theme'); ?> *</label>
                    <input type="time" name="time_end" required>
                </div>
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Capacità', 'dfn-theme'); ?> *</label>
                    <input type="number" name="capacity" min="1" value="<?php echo esc_attr($event->slot_capacity); ?>" required>
                </div>
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Capacità Bonus', 'dfn-theme'); ?></label>
                    <input type="number" name="bonus_capacity" min="0" value="<?php echo esc_attr($event->slot_bonus); ?>">
                </div>
                <div class="dfn-sm-modal-footer">
                    <button type="button" class="dfn-btn dfn-btn-secondary dfn-modal-close-btn" data-modal="dfn-modal-add-slot"><?php esc_html_e('Annulla', 'dfn-theme'); ?></button>
                    <button type="submit" class="dfn-btn dfn-btn-primary"><?php esc_html_e('Salva Slot', 'dfn-theme'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODALE 2: Modifica Capacità Slot -->
    <div id="dfn-modal-edit-slot" class="dfn-sm-modal">
        <div class="dfn-sm-modal-content">
            <div class="dfn-sm-modal-header">
                <h3><?php esc_html_e('Modifica Capacità Slot', 'dfn-theme'); ?> (<span id="edit-slot-time-label"></span>)</h3>
                <span class="dfn-modal-close" data-modal="dfn-modal-edit-slot">&times;</span>
            </div>
            <form id="dfn-form-edit-slot">
                <input type="hidden" name="slot_id">
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Capacità Standard', 'dfn-theme'); ?> *</label>
                    <input type="number" name="capacity" min="1" required>
                </div>
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Capacità Bonus', 'dfn-theme'); ?></label>
                    <input type="number" name="bonus_capacity" min="0">
                </div>
                <div class="dfn-sm-modal-footer">
                    <button type="button" class="dfn-btn dfn-btn-secondary dfn-modal-close-btn" data-modal="dfn-modal-edit-slot"><?php esc_html_e('Annulla', 'dfn-theme'); ?></button>
                    <button type="submit" class="dfn-btn dfn-btn-primary"><?php esc_html_e('Aggiorna', 'dfn-theme'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODALE 3: Sposta Prenotazione -->
    <div id="dfn-modal-move-booking" class="dfn-sm-modal">
        <div class="dfn-sm-modal-content">
            <div class="dfn-sm-modal-header">
                <h3><?php esc_html_e('Sposta Prenotazione', 'dfn-theme'); ?></h3>
                <span class="dfn-modal-close" data-modal="dfn-modal-move-booking">&times;</span>
            </div>
            <form id="dfn-form-move-booking">
                <input type="hidden" name="booking_id">
                <input type="hidden" name="from_slot_id">
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Seleziona Nuovo Turno', 'dfn-theme'); ?> *</label>
                    <select name="to_slot_id" required>
                        <!-- Popolato via JS con disponibilità -->
                    </select>
                </div>
                <div class="dfn-form-group checkbox-group">
                    <label>
                        <input type="checkbox" name="notify_visitor" value="1" checked>
                        <?php esc_html_e('Invia email di notifica al visitatore con il nuovo orario', 'dfn-theme'); ?>
                    </label>
                </div>
                <div class="dfn-sm-modal-footer">
                    <button type="button" class="dfn-btn dfn-btn-secondary dfn-modal-close-btn" data-modal="dfn-modal-move-booking"><?php esc_html_e('Annulla', 'dfn-theme'); ?></button>
                    <button type="submit" class="dfn-btn dfn-btn-primary"><?php esc_html_e('Conferma Spostamento', 'dfn-theme'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODALE 4: Aggiungi Prenotazione Manuale -->
    <div id="dfn-modal-add-booking" class="dfn-sm-modal">
        <div class="dfn-sm-modal-content">
            <div class="dfn-sm-modal-header">
                <h3><?php esc_html_e('Registra Nuova Prenotazione Manuale', 'dfn-theme'); ?></h3>
                <span class="dfn-modal-close" data-modal="dfn-modal-add-booking">&times;</span>
            </div>
            <form id="dfn-form-add-booking">
                <div class="dfn-form-row">
                    <div class="dfn-form-group">
                        <label><?php esc_html_e('Nome', 'dfn-theme'); ?> *</label>
                        <input type="text" name="first_name" required>
                    </div>
                    <div class="dfn-form-group">
                        <label><?php esc_html_e('Cognome', 'dfn-theme'); ?> *</label>
                        <input type="text" name="last_name" required>
                    </div>
                </div>
                <div class="dfn-form-row">
                    <div class="dfn-form-group">
                        <label><?php esc_html_e('Email (Opzionale)', 'dfn-theme'); ?></label>
                        <input type="email" name="email" placeholder="es: no-email@dfn.it">
                    </div>
                    <div class="dfn-form-group">
                        <label><?php esc_html_e('Telefono (Consigliato)', 'dfn-theme'); ?></label>
                        <input type="text" name="phone">
                    </div>
                </div>
                
                <div class="dfn-form-group">
                    <label><?php esc_html_e('Orario del Turno', 'dfn-theme'); ?> *</label>
                    <select name="slot_id" required>
                        <!-- Popolato via JS con gli slot della giornata -->
                    </select>
                </div>

                <div class="dfn-form-row ticket-qtys">
                    <div class="dfn-form-group">
                        <label><?php esc_html_e('Biglietti Standard', 'dfn-theme'); ?></label>
                        <input type="number" name="qty_standard" min="0" value="1">
                    </div>
                    <div class="dfn-form-group">
                        <label><?php esc_html_e('Biglietti Socio FAI', 'dfn-theme'); ?></label>
                        <input type="number" name="qty_fai" min="0" value="0">
                    </div>
                </div>

                <!-- Contenitore Tessere FAI (mostrato dinamicamente) -->
                <div id="dfn-fai-cards-container" style="display:none;">
                    <h4><?php esc_html_e('Dettagli Tessere FAI', 'dfn-theme'); ?></h4>
                    <div id="fai-cards-fields-list">
                        <!-- Popolato dinamicamente da Javascript -->
                    </div>
                </div>

                <div class="dfn-form-group">
                    <label><?php esc_html_e('Note Prenotazione', 'dfn-theme'); ?></label>
                    <textarea name="notes" rows="2"></textarea>
                </div>

                <div class="dfn-sm-modal-footer">
                    <button type="button" class="dfn-btn dfn-btn-secondary dfn-modal-close-btn" data-modal="dfn-modal-add-booking"><?php esc_html_e('Annulla', 'dfn-theme'); ?></button>
                    <button type="submit" class="dfn-btn dfn-btn-primary"><?php esc_html_e('Registra Prenotazione', 'dfn-theme'); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODALE 5: Lista Prenotazioni per Turno (Popup Grande) -->
    <div id="dfn-modal-slot-bookings" class="dfn-sm-modal">
        <div class="dfn-sm-modal-content dfn-modal-slot-bookings-content">
            <div class="dfn-sm-modal-header">
                <h3 class="dfn-slot-bookings-title"><?php esc_html_e('Prenotazioni', 'dfn-theme'); ?></h3>
                <span class="dfn-modal-close" data-modal="dfn-modal-slot-bookings">&times;</span>
            </div>
            <div class="dfn-slot-bookings-stats">
                <div class="slot-modal-stat">
                    <span class="stat-number slot-modal-stat-count">-</span>
                    <span class="stat-desc"><?php esc_html_e('Prenotazioni', 'dfn-theme'); ?></span>
                </div>
                <div class="slot-modal-stat">
                    <span class="stat-number slot-modal-stat-booked">-</span>
                    <span class="stat-desc"><?php esc_html_e('Posti occupati', 'dfn-theme'); ?></span>
                </div>
                <div class="slot-modal-stat">
                    <span class="stat-number slot-modal-stat-free">-</span>
                    <span class="stat-desc"><?php esc_html_e('Posti liberi', 'dfn-theme'); ?></span>
                </div>
                <div class="slot-modal-stat">
                    <span class="stat-number slot-modal-stat-capacity">-</span>
                    <span class="stat-desc"><?php esc_html_e('Capacità totale', 'dfn-theme'); ?></span>
                </div>
            </div>
            <div class="dfn-slot-bookings-toolbar">
                <div class="search-box">
                    <span class="dashicons dashicons-search"></span>
                    <input type="text" id="dfn-slot-bookings-search" placeholder="<?php esc_attr_e('Cerca per nome, email o telefono...', 'dfn-theme'); ?>">
                </div>
            </div>
            <div class="dfn-slot-bookings-body">
                <div id="dfn-slot-bookings-list">
                    <!-- Popolato via JS -->
                </div>
            </div>
            <div class="dfn-sm-modal-footer" style="padding: 16px 24px; background:#f8fafc;">
                <button type="button" class="dfn-btn dfn-btn-primary dfn-modal-close-btn" data-modal="dfn-modal-slot-bookings"><?php esc_html_e('Chiudi', 'dfn-theme'); ?></button>
            </div>
        </div>
    </div>

    <!-- MODALE 6: Dettagli Prenotazione Completi (Popup in sovrapposizione, livello 2) -->
    <div id="dfn-modal-booking-details" class="dfn-sm-modal">
        <div class="dfn-sm-modal-content">
            <div class="dfn-sm-modal-header">
                <h3><?php esc_html_e('Dettagli Prenotazione', 'dfn-theme'); ?></h3>
                <span class="dfn-modal-close" data-modal="dfn-modal-booking-details">&times;</span>
            </div>
            <div class="dfn-sm-modal-body" style="padding: 24px; max-height: 75vh; overflow-y: auto;">
                <table class="dfn-details-table" style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
                    <tbody>
                        <!-- Popolato via JS -->
                    </tbody>
                </table>
                <div id="dfn-details-fai-cards-section" style="display:none; margin-top:20px; background:#f8fafc; border:1px solid var(--dfn-border); border-radius:var(--dfn-radius); padding:15px;">
                    <h4 style="margin:0 0 10px 0; color:var(--dfn-primary);"><?php esc_html_e('Tessere Soci FAI collegate', 'dfn-theme'); ?></h4>
                    <div id="dfn-details-fai-cards-list"></div>
                </div>
            </div>
            <div class="dfn-sm-modal-footer" style="padding: 16px 24px; background:#f8fafc;">
                <a href="#" id="dfn-btn-view-order-wc" target="_blank" class="dfn-btn dfn-btn-secondary">
                    <span class="dashicons dashicons-external"></span> <?php esc_html_e('Vedi Ordine WooCommerce', 'dfn-theme'); ?>
                </a>
                <button type="button" class="dfn-btn dfn-btn-primary dfn-modal-close-btn" data-modal="dfn-modal-booking-details"><?php esc_html_e('Chiudi', 'dfn-theme'); ?></button>
            </div>
        </div>
    </div>
    <?php
}

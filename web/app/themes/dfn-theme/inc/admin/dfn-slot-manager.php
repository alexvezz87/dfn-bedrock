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
                    <select name="slot_id" required style="padding: 10px 36px 10px 16px !important; height: 44px !important; border-radius: 8px !important; font-size: 13px !important; font-weight: 500 !important; box-sizing: border-box !important;">
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
            <div class="dfn-sm-modal-footer" style="padding: 16px 24px; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; gap:8px; align-items:center;">
                    <a href="#" id="dfn-btn-view-order-wc" target="_blank" class="dfn-btn dfn-btn-secondary">
                        <span class="dashicons dashicons-external"></span> <?php esc_html_e('Vedi Ordine WooCommerce', 'dfn-theme'); ?>
                    </a>
                    <button type="button" id="dfn-btn-resend-confirmation-email" class="dfn-btn" style="background:#1e40af; color:#fff; border:none; display:inline-flex; align-items:center; gap:6px; font-weight:600; padding:8px 14px; border-radius:6px; cursor:pointer;">
                        <span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('Reinvia Email Conferma', 'dfn-theme'); ?>
                    </button>
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    <button type="button" class="dfn-btn dfn-btn-secondary dfn-modal-close-btn" data-modal="dfn-modal-booking-details"><?php esc_html_e('Annulla', 'dfn-theme'); ?></button>
                    <button type="button" id="dfn-btn-save-payment-status" class="dfn-btn dfn-btn-primary" style="display:none;"><?php esc_html_e('Salva Stato Pagamento', 'dfn-theme'); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- POPUPS CASSA CHECK-IN E LOGS LEGACY -->
    <div id="cv-cassa-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:999999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:25px; border-radius:10px; width:90%; max-width:450px; box-shadow:0 10px 30px rgba(0,0,0,0.4); max-height:85vh; display:flex; flex-direction:column;">
            <h2 style="margin-top:0; font-size:22px; border-bottom: 2px solid #eee; padding-bottom: 10px;"><?php esc_html_e('Cassa Check-in', 'dfn-theme'); ?></h2>
            <p style="font-size:16px;"><?php esc_html_e('Cliente:', 'dfn-theme'); ?> <strong id="cv-modal-cliente-name" style="color:#2271b1;"></strong></p>
            <div id="cv-modal-buttons-area" style="flex-grow:1; overflow-y:auto; margin: 15px 0; padding-right: 5px;"></div>
            <button type="button" class="button cv-close-modal-btn" style="text-align:center; width:100%; padding: 10px; height: auto; font-size: 16px;"><?php esc_html_e('Chiudi Finestra', 'dfn-theme'); ?></button>
        </div>
    </div>

    <div id="cv-history-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:999999; align-items:center; justify-content:center;">
        <div style="background:#fff; padding:25px; border-radius:10px; width:92%; max-width:700px; box-shadow:0 10px 30px rgba(0,0,0,0.4); max-height:85vh; display:flex; flex-direction:column;">
            <h2 style="margin-top:0; font-size:22px; border-bottom: 2px solid #eee; padding-bottom: 10px;"><?php esc_html_e('📜 Log Operazioni Cliente', 'dfn-theme'); ?></h2>
            <p style="font-size:16px;"><?php esc_html_e('Ordine Cliente:', 'dfn-theme'); ?> <strong id="cv-history-cliente-name" style="color:#2271b1;"></strong></p>
            <div id="cv-history-content-area" style="flex-grow:1; overflow-y:auto; overflow-x:auto; margin: 10px 0; padding:10px; background:#f9f9f9; border:1px solid #ddd; border-radius:5px; font-family: monospace; font-size: 13px; line-height: 1.5; white-space: nowrap;"></div>
            <button type="button" class="button cv-close-modal-btn" style="text-align:center; width:100%; padding: 10px; height: auto; font-size: 16px; margin-top:10px;"><?php esc_html_e('Chiudi Log', 'dfn-theme'); ?></button>
        </div>
    </div>

    <!-- MODALE 7: Visualizzazione Nota Completa del Visitatore -->
    <div id="dfn-modal-view-note" class="dfn-sm-modal">
        <div class="dfn-sm-modal-content" style="max-width: 500px; border-radius: 12px; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);">
            <div class="dfn-sm-modal-header" style="background:#fffdf5; border-bottom:1px solid #ffeeba; padding: 16px 20px;">
                <h3 style="margin:0; font-size:16px; color:#856404; display:flex; align-items:center; gap:8px;">
                    <span>💬</span> <?php esc_html_e('Nota del Visitatore', 'dfn-theme'); ?>
                </h3>
                <span class="dfn-modal-close" data-modal="dfn-modal-view-note" style="font-size:24px; cursor:pointer; color:#856404; line-height:1;">&times;</span>
            </div>
            <div class="dfn-sm-modal-body" style="padding: 20px;">
                <div id="dfn-note-modal-subtitle" style="font-size:12px; color:#64748b; margin-bottom:12px; font-weight:600;"></div>
                <div id="dfn-note-modal-content" style="font-size:13.5px; color:#1e293b; line-height:1.6; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:16px; white-space:pre-wrap; max-height:280px; overflow-y:auto; word-break:break-word;"></div>
            </div>
            <div class="dfn-sm-modal-footer" style="padding: 12px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; text-align:right;">
                <button type="button" class="dfn-btn dfn-btn-secondary dfn-modal-close-btn" data-modal="dfn-modal-view-note" style="padding: 6px 16px; font-weight:600;"><?php esc_html_e('Chiudi', 'dfn-theme'); ?></button>
            </div>
        </div>
    </div>
    <?php
}

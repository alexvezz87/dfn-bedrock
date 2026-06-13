<?php
/**
 * DFN Booking System 2.0 — Event Creator & Editor Form
 *
 * Interfaccia grafica avanzata e gestore del salvataggio dati per la
 * creazione e modifica delle schede eventi FAI legate a prodotti WooCommerce.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the Event Editor screen.
 */
function dfn_render_event_editor() {
    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        wp_die( esc_html__( 'Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme' ) );
    }

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';

    // Determina se stiamo modificando o creando
    $event_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    $event = null;

    if ( $event_id > 0 ) {
        $event = dfn_db_get_event( $event_id );
        if ( ! $event ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Evento non trovato.', 'dfn-theme' ) . '</p></div>';
            return;
        }
    }

    // Gestione salvataggio POST
    $message = '';
    $message_type = 'success';

    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['dfn_save_event_nonce'] ) ) {
        if ( wp_verify_nonce( $_POST['dfn_save_event_nonce'], 'dfn_save_event' ) ) {
            
            $product_id_raw    = sanitize_text_field( $_POST['product_id'] );
            $event_date_start  = sanitize_text_field( $_POST['event_date_start'] );
            $event_date_end    = ! empty( $_POST['event_date_end'] ) ? sanitize_text_field( $_POST['event_date_end'] ) : $event_date_start;
            $event_time_start  = sanitize_text_field( $_POST['event_time_start'] );
            $event_time_end    = ! empty( $_POST['event_time_end'] ) ? sanitize_text_field( $_POST['event_time_end'] ) : null;
            $location          = sanitize_textarea_field( $_POST['location'] );
            $description       = sanitize_textarea_field( $_POST['description'] );
            $access_type       = sanitize_text_field( $_POST['access_type'] ); // free_flow o time_slots
            $allocation_mode   = sanitize_text_field( $_POST['allocation_mode'] ); // automatic o self_selection
            $approval_workflow = sanitize_text_field( $_POST['approval_workflow'] ); // auto o manual
            $payment_mode      = sanitize_text_field( $_POST['payment_mode'] ); // online, in_loco, hybrid
            $slot_duration     = intval( $_POST['slot_duration'] );
            $slot_capacity     = intval( $_POST['slot_capacity'] );
            $slot_bonus        = intval( $_POST['slot_bonus'] );
            $first_slot_time   = ! empty( $_POST['first_slot_time'] ) ? sanitize_text_field( $_POST['first_slot_time'] ) : null;
            $last_slot_time    = ! empty( $_POST['last_slot_time'] ) ? sanitize_text_field( $_POST['last_slot_time'] ) : null;
            $total_capacity    = intval( $_POST['total_capacity'] );
            $price_standard    = floatval( $_POST['price_standard'] );
            $price_fai         = floatval( $_POST['price_fai'] );
            $staff_config      = sanitize_textarea_field( $_POST['staff_config'] );
            $status            = sanitize_text_field( $_POST['status'] );
            $auto_cancel_hours = intval( $_POST['auto_cancel_hours'] );

            $product_id = 0;
            if ( $product_id_raw === 'new' ) {
                $event_title = isset( $_POST['event_title'] ) ? sanitize_text_field( $_POST['event_title'] ) : '';
                if ( empty( $event_title ) ) {
                    $event_title = 'Evento FAI - ' . date_i18n( 'd M Y', strtotime( $event_date_start ) );
                }

                // Crea il post del prodotto
                $new_prod_id = wp_insert_post( array(
                    'post_title'   => $event_title,
                    'post_status'  => 'publish',
                    'post_type'    => 'product',
                    'post_content' => sprintf( __( 'Prenotazione biglietti per l\'evento: %s.', 'dfn-theme' ), $event_title )
                ) );

                if ( ! is_wp_error( $new_prod_id ) && $new_prod_id > 0 ) {
                    $product_id = $new_prod_id;

                    // Assegna tipo simple
                    wp_set_object_terms( $product_id, 'simple', 'product_type' );

                    // Configura metadati del prodotto
                    update_post_meta( $product_id, '_visibility', 'visible' );
                    update_post_meta( $product_id, '_stock_status', 'instock' );
                    update_post_meta( $product_id, '_virtual', 'yes' );
                    update_post_meta( $product_id, '_regular_price', $price_standard );
                    update_post_meta( $product_id, '_price', $price_standard );
                    update_post_meta( $product_id, '_manage_stock', 'yes' );

                    // Calcola lo stock totale
                    $total_stock = 0;
                    if ( 'time_slots' === $access_type ) {
                        $first = strtotime( $first_slot_time );
                        $last  = strtotime( $last_slot_time );
                        $dur   = $slot_duration > 0 ? $slot_duration : 30;
                        $slots_per_day = 1;
                        if ( $first && $last && $last > $first ) {
                            $slots_per_day = floor( ( $last - $first ) / ( $dur * 60 ) ) + 1;
                        }
                        $days = 1;
                        $start_ts = strtotime( $event_date_start );
                        $end_ts   = strtotime( $event_date_end );
                        if ( $start_ts && $end_ts && $end_ts > $start_ts ) {
                            $days = floor( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) + 1;
                        }
                        $total_stock = $slot_capacity * $slots_per_day * $days;
                    } else {
                        $total_stock = $total_capacity;
                    }
                    update_post_meta( $product_id, '_stock', $total_stock );
                }
            } else {
                $product_id = intval( $product_id_raw );
            }

            // Associa l'immagine in evidenza al prodotto WooCommerce
            if ( $product_id > 0 ) {
                $image_id = isset( $_POST['dfn_event_image_id'] ) ? intval( $_POST['dfn_event_image_id'] ) : 0;
                if ( $image_id > 0 ) {
                    set_post_thumbnail( $product_id, $image_id );
                } else {
                    set_post_thumbnail( $product_id, 2223 );
                }

                // Associa la galleria al prodotto WooCommerce
                $gallery_ids = isset( $_POST['dfn_event_gallery_ids'] ) ? sanitize_text_field( $_POST['dfn_event_gallery_ids'] ) : '';
                update_post_meta( $product_id, '_product_image_gallery', $gallery_ids );
            }

            $data = array(
                'product_id'        => $product_id,
                'event_date_start'  => $event_date_start,
                'event_date_end'    => $event_date_end,
                'event_time_start'  => $event_time_start,
                'event_time_end'    => $event_time_end,
                'location'          => $location,
                'description'       => $description,
                'access_type'       => $access_type,
                'allocation_mode'   => $allocation_mode,
                'approval_workflow' => $approval_workflow,
                'payment_mode'      => $payment_mode,
                'auto_cancel_hours' => $auto_cancel_hours,
                'slot_duration'     => $slot_duration,
                'slot_capacity'     => $slot_capacity,
                'slot_bonus'        => $slot_bonus,
                'first_slot_time'   => $first_slot_time,
                'last_slot_time'    => $last_slot_time,
                'total_capacity'    => $total_capacity,
                'price_standard'    => $price_standard,
                'price_fai'         => $price_fai,
                'staff_config'      => $staff_config,
                'status'            => $status,
            );

            $format = array(
                '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%f', '%f', '%s', '%s'
            );

            if ( $event_id > 0 ) {
                // Modifica
                $wpdb->update( $table_events, $data, array( 'id' => $event_id ), $format, array( '%d' ) );
                $message = __( 'Evento aggiornato con successo nel database.', 'dfn-theme' );
            } else {
                // Inserimento
                $wpdb->insert( $table_events, $data, $format );
                $event_id = $wpdb->insert_id;
                $message = __( 'Nuovo evento creato con successo!', 'dfn-theme' );

                // Genera gli slot iniziali se previsto
                if ( 'time_slots' === $access_type ) {
                    dfn_db_generate_slots_for_event( $event_id );
                }
            }

            // Reindirizza al tabellone principale con messaggio di successo
            wp_safe_redirect( admin_url( 'admin.php?page=dfn-events&action=saved&event_id=' . $event_id ) );
            exit;
        } else {
            $message = __( 'Errore di sicurezza durante il salvataggio dei dati.', 'dfn-theme' );
            $message_type = 'error';
        }
    }

    // Recupera l'elenco dei prodotti WooCommerce per associarli all'evento
    $products = get_posts( array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC'
    ) );

    // Valori di default per un nuovo evento
    $p_id             = $event ? $event->product_id : 0;
    $date_start       = $event ? $event->event_date_start : '';
    $date_end         = $event ? $event->event_date_end : '';
    $time_start       = $event ? $event->event_time_start : '';
    $time_end         = $event ? $event->event_time_end : '';
    $loc              = $event ? $event->location : '';
    $desc             = $event ? $event->description : '';
    $acc_type         = $event ? $event->access_type : 'time_slots';
    $alloc_mode       = $event ? $event->allocation_mode : 'automatic';
    $app_wf           = $event ? $event->approval_workflow : 'auto';
    $pay_mode         = $event ? $event->payment_mode : 'online';
    $duration         = $event ? $event->slot_duration : 30;
    $capacity         = $event ? $event->slot_capacity : 20;
    $bonus            = $event ? $event->slot_bonus : 5;
    $first_slot       = $event ? $event->first_slot_time : '10:00:00';
    $last_slot        = $event ? $event->last_slot_time : '18:00:00';
    $tot_cap          = $event ? $event->total_capacity : 100;
    $price_std        = $event ? $event->price_standard : 10.00;
    $price_fai_member = $event ? $event->price_fai : 5.00;
    $staff            = $event ? $event->staff_config : '';
    $stat             = $event ? $event->status : 'draft';
    $auto_cancel      = $event ? (int) $event->auto_cancel_hours : 24;
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-edit-page"></span>
                <h1><?php echo $event_id > 0 ? esc_html__( 'Modifica Evento FAI', 'dfn-theme' ) : esc_html__( 'Configura Nuovo Evento FAI', 'dfn-theme' ); ?></h1>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-events' ) ); ?>" class="page-title-action dfn-btn dfn-btn-secondary">
                <span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e( 'Torna al Tabellone', 'dfn-theme' ); ?>
            </a>
        </header>

        <?php if ( ! empty( $message ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="" class="dfn-editor-form">
            <?php wp_nonce_field( 'dfn_save_event', 'dfn_save_event_nonce' ); ?>
            
            <div class="dfn-layout-columns">
                <!-- Colonna Principale (Configurazioni) -->
                <div class="dfn-column-main">
                    <!-- Blocco 1: Associazione WooCommerce -->
                    <div class="dfn-card">
                        <div class="dfn-card-header">
                            <h2>⚙️ <?php esc_html_e( 'Associazione Prodotto', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <p class="description"><?php esc_html_e( 'Collega questa configurazione di turni e listino ad un Prodotto WooCommerce esistente. Il prodotto funge da carrello per il check-out.', 'dfn-theme' ); ?></p>
                            
                            <div class="dfn-form-group">
                                <label for="product_id" class="dfn-label"><?php esc_html_e( 'Prodotto WooCommerce Collegato', 'dfn-theme' ); ?> <span class="required">*</span></label>
                                <select name="product_id" id="product_id" class="dfn-select2" required style="width:100%;">
                                    <option value=""><?php esc_html_e( 'Seleziona un prodotto...', 'dfn-theme' ); ?></option>
                                    <?php if ( $event_id === 0 ) : ?>
                                        <option value="new"><?php esc_html_e( '🆕 Crea automaticamente un nuovo Prodotto WooCommerce', 'dfn-theme' ); ?></option>
                                    <?php endif; ?>
                                    <?php foreach ( $products as $prod ) : ?>
                                        <option value="<?php echo $prod->ID; ?>" <?php selected( $p_id, $prod->ID ); ?>><?php echo esc_html( $prod->post_title ); ?> (ID: <?php echo $prod->ID; ?>)</option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if ( $event_id === 0 ) : ?>
                                    <div class="dfn-form-group" id="dfn-auto-product-title-group" style="display:none; margin-top: 15px;">
                                        <label for="event_title" class="dfn-label"><?php esc_html_e( 'Titolo del Nuovo Evento / Biglietto', 'dfn-theme' ); ?> <span class="required">*</span></label>
                                        <input type="text" name="event_title" id="event_title" class="dfn-input" placeholder="<?php esc_attr_e( 'Es: Visita al Castello Visconteo', 'dfn-theme' ); ?>">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco 2: Logistica (Date, Orario, Luogo) -->
                    <div class="dfn-card">
                        <div class="dfn-card-header">
                            <h2>📍 <?php esc_html_e( 'Logistica & Orari', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-grid-2">
                                <div class="dfn-form-group">
                                    <label for="event_date_start" class="dfn-label"><?php esc_html_e( 'Data Inizio', 'dfn-theme' ); ?> <span class="required">*</span></label>
                                    <input type="date" name="event_date_start" id="event_date_start" value="<?php echo esc_attr( $date_start ); ?>" required class="dfn-input">
                                </div>
                                <div class="dfn-form-group">
                                    <label for="event_date_end" class="dfn-label"><?php esc_html_e( 'Data Fine (Opzionale)', 'dfn-theme' ); ?></label>
                                    <input type="date" name="event_date_end" id="event_date_end" value="<?php echo esc_attr( $date_end ); ?>" class="dfn-input">
                                </div>
                            </div>

                            <div class="dfn-form-grid-2">
                                <div class="dfn-form-group">
                                    <label for="event_time_start" class="dfn-label"><?php esc_html_e( 'Orario Apertura Inizio', 'dfn-theme' ); ?> <span class="required">*</span></label>
                                    <input type="time" name="event_time_start" id="event_time_start" value="<?php echo esc_attr( $time_start ); ?>" required class="dfn-input">
                                </div>
                                <div class="dfn-form-group">
                                    <label for="event_time_end" class="dfn-label"><?php esc_html_e( 'Orario Chiusura Fine (Opzionale)', 'dfn-theme' ); ?></label>
                                    <input type="time" name="event_time_end" id="event_time_end" value="<?php echo esc_attr( $time_end ); ?>" class="dfn-input">
                                </div>
                            </div>

                            <div class="dfn-form-group">
                                <label for="location" class="dfn-label"><?php esc_html_e( 'Luogo dell\'Evento / Luogo di Ritrovo', 'dfn-theme' ); ?> <span class="required">*</span></label>
                                <textarea name="location" id="location" required class="dfn-textarea" rows="2" placeholder="<?php esc_attr_e( 'Es: Castello Visconteo-Sforzesco di Novara - cortile interno', 'dfn-theme' ); ?>"><?php echo esc_textarea( $loc ); ?></textarea>
                            </div>

                            <div class="dfn-form-group" style="margin-top: 15px;">
                                <label for="description" class="dfn-label"><?php esc_html_e( 'Descrizione del bene', 'dfn-theme' ); ?></label>
                                <textarea name="description" id="description" class="dfn-textarea" rows="4" placeholder="<?php esc_attr_e( 'Descrizione dettagliata dell\'edificio storico o del bene FAI...', 'dfn-theme' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco 3: Algoritmo di Allocazione e Slot -->
                    <div class="dfn-card">
                        <div class="dfn-card-header">
                            <h2>🎛️ <?php esc_html_e( 'Tipologia Accesso & Algoritmo di Allocazione', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-grid-2">
                                <div class="dfn-form-group">
                                    <label for="access_type" class="dfn-label"><?php esc_html_e( 'Modalità di Accesso', 'dfn-theme' ); ?></label>
                                    <select name="access_type" id="access_type" class="dfn-input">
                                        <option value="time_slots" <?php selected( $acc_type, 'time_slots' ); ?>><?php esc_html_e( '⏰ Fasce Orarie (Slot)', 'dfn-theme' ); ?></option>
                                        <option value="free_flow" <?php selected( $acc_type, 'free_flow' ); ?>><?php esc_html_e( '🚪 Flusso Libero (Senza fasce)', 'dfn-theme' ); ?></option>
                                    </select>
                                </div>

                                <div class="dfn-form-group">
                                    <label for="allocation_mode" class="dfn-label"><?php esc_html_e( 'Algoritmo di Allocazione (Sezione 2.0)', 'dfn-theme' ); ?></label>
                                    <select name="allocation_mode" id="allocation_mode" class="dfn-input">
                                        <option value="automatic" <?php selected( $alloc_mode, 'automatic' ); ?>><?php esc_html_e( '🤖 Assegnazione Automatica (Default)', 'dfn-theme' ); ?></option>
                                        <option value="self_selection" <?php selected( $alloc_mode, 'self_selection' ); ?>><?php esc_html_e( '👈 Selezione Turno Libera (Self-selection)', 'dfn-theme' ); ?></option>
                                    </select>
                                </div>
                            </div>

                            <!-- Sezione Slot condizionale (mostrata/nascosta via JS) -->
                            <div id="dfn-slot-settings-section">
                                <div class="divider"></div>
                                <h3><?php esc_html_e( 'Parametri Fasce Orarie', 'dfn-theme' ); ?></h3>
                                
                                <div class="dfn-form-grid-3">
                                    <div class="dfn-form-group">
                                        <label for="slot_duration" class="dfn-label"><?php esc_html_e( 'Durata Turno (minuti)', 'dfn-theme' ); ?></label>
                                        <input type="number" name="slot_duration" id="slot_duration" value="<?php echo esc_attr( $duration ); ?>" min="5" class="dfn-input">
                                    </div>
                                    <div class="dfn-form-group">
                                        <label for="slot_capacity" class="dfn-label"><?php esc_html_e( 'Capacità standard turno', 'dfn-theme' ); ?></label>
                                        <input type="number" name="slot_capacity" id="slot_capacity" value="<?php echo esc_attr( $capacity ); ?>" min="1" class="dfn-input">
                                    </div>
                                    <div class="dfn-form-group">
                                        <label for="slot_bonus" class="dfn-label"><?php esc_html_e( 'Capacità Bonus (Staff/Live)', 'dfn-theme' ); ?></label>
                                        <input type="number" name="slot_bonus" id="slot_bonus" value="<?php echo esc_attr( $bonus ); ?>" min="0" class="dfn-input">
                                    </div>
                                </div>

                                <div class="dfn-form-grid-2">
                                    <div class="dfn-form-group">
                                        <label for="first_slot_time" class="dfn-label"><?php esc_html_e( 'Orario Primo Turno del Giorno', 'dfn-theme' ); ?></label>
                                        <input type="time" name="first_slot_time" id="first_slot_time" value="<?php echo esc_attr( $first_slot ); ?>" class="dfn-input">
                                    </div>
                                    <div class="dfn-form-group">
                                        <label for="last_slot_time" class="dfn-label"><?php esc_html_e( 'Orario Ultimo Turno del Giorno', 'dfn-theme' ); ?></label>
                                        <input type="time" name="last_slot_time" id="last_slot_time" value="<?php echo esc_attr( $last_slot ); ?>" class="dfn-input">
                                    </div>
                                </div>
                            </div>

                            <!-- Sezione Free Flow condizionale (mostrata/nascosta via JS) -->
                            <div id="dfn-freeflow-settings-section" style="display:none;">
                                <div class="divider"></div>
                                <h3><?php esc_html_e( 'Parametri Flusso Libero', 'dfn-theme' ); ?></h3>
                                <div class="dfn-form-group" style="max-width:300px;">
                                    <label for="total_capacity" class="dfn-label"><?php esc_html_e( 'Capacità Massima Evento', 'dfn-theme' ); ?></label>
                                    <input type="number" name="total_capacity" id="total_capacity" value="<?php echo esc_attr( $tot_cap ); ?>" min="1" class="dfn-input">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Colonna Laterale (Salvataggio, Listini e Stati) -->
                <div class="dfn-column-sidebar">
                    <!-- Blocco Pubblicazione -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>📢 <?php esc_html_e( 'Pubblica', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-group">
                                <label for="status" class="dfn-label"><?php esc_html_e( 'Stato Evento', 'dfn-theme' ); ?></label>
                                <select name="status" id="status" class="dfn-input">
                                    <option value="draft" <?php selected( $stat, 'draft' ); ?>><?php esc_html_e( 'Bozza', 'dfn-theme' ); ?></option>
                                    <option value="published" <?php selected( $stat, 'published' ); ?>><?php esc_html_e( 'Pubblicato (Attivo)', 'dfn-theme' ); ?></option>
                                    <option value="private" <?php selected( $stat, 'private' ); ?>><?php esc_html_e( 'Privato (Gestione Interna)', 'dfn-theme' ); ?></option>
                                    <option value="archived" <?php selected( $stat, 'archived' ); ?>><?php esc_html_e( 'Archiviato', 'dfn-theme' ); ?></option>
                                </select>
                            </div>

                            <div class="dfn-form-group">
                                <label for="approval_workflow" class="dfn-label"><?php esc_html_e( 'Workflow Approvazione', 'dfn-theme' ); ?></label>
                                <select name="approval_workflow" id="approval_workflow" class="dfn-input">
                                    <option value="auto" <?php selected( $app_wf, 'auto' ); ?>><?php esc_html_e( 'Conferma Automatica', 'dfn-theme' ); ?></option>
                                    <option value="manual" <?php selected( $app_wf, 'manual' ); ?>><?php esc_html_e( 'Manuale (Staff Review)', 'dfn-theme' ); ?></option>
                                </select>
                            </div>

                            <div class="dfn-form-group">
                                <label for="payment_mode" class="dfn-label"><?php esc_html_e( 'Modalità di Pagamento', 'dfn-theme' ); ?></label>
                                <select name="payment_mode" id="payment_mode" class="dfn-input">
                                    <option value="online" <?php selected( $pay_mode, 'online' ); ?>><?php esc_html_e( '💳 Solo Pagamento Online', 'dfn-theme' ); ?></option>
                                    <option value="in_loco" <?php selected( $pay_mode, 'in_loco' ); ?>><?php esc_html_e( '💵 Solo Saldo in Loco', 'dfn-theme' ); ?></option>
                                    <option value="hybrid" <?php selected( $pay_mode, 'hybrid' ); ?>><?php esc_html_e( '🔄 Ibrida (Scelta utente)', 'dfn-theme' ); ?></option>
                                </select>
                            </div>

                            <div class="dfn-form-group" id="dfn-auto-cancel-group">
                                <label for="auto_cancel_hours" class="dfn-label"><?php esc_html_e( 'Annullamento Automatico (ore)', 'dfn-theme' ); ?></label>
                                <input type="number" name="auto_cancel_hours" id="auto_cancel_hours" value="<?php echo esc_attr( $auto_cancel ); ?>" min="0" step="1" class="dfn-input">
                                <p class="description" style="margin-top: 6px; font-size: 12px; color: #64748b;" id="dfn-auto-cancel-help">
                                    <?php esc_html_e( 'Dopo quante ore un ordine non pagato viene annullato automaticamente. Imposta 0 per disabilitare (consigliato per pagamento in loco).', 'dfn-theme' ); ?>
                                </p>
                            </div>

                            <div class="divider"></div>

                            <button type="submit" class="dfn-btn dfn-btn-primary dfn-btn-block">
                                <span class="dashicons dashicons-saved"></span> <?php echo $event_id > 0 ? esc_html__( 'Aggiorna Configurazione', 'dfn-theme' ) : esc_html__( 'Crea e Attiva Evento', 'dfn-theme' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Blocco Immagine in Evidenza -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>🖼️ <?php esc_html_e( 'Immagine in Evidenza', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body" style="text-align: center;">
                            <?php 
                            $image_id = 0;
                            $image_url = '';
                            if ( $p_id > 0 ) {
                                $image_id = get_post_thumbnail_id( $p_id );
                                if ( $image_id ) {
                                    $image_url = wp_get_attachment_image_url( $image_id, 'medium' );
                                }
                            }
                            ?>
                            <div class="dfn-event-image-preview" style="margin-bottom: 15px; min-height: 150px; border: 2px dashed #cbd5e1; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f8fafc; overflow: hidden; position: relative;">
                                <?php if ( $image_url ) : ?>
                                    <img src="<?php echo esc_url( $image_url ); ?>" style="max-width: 100%; max-height: 150px; display: block;" id="dfn-event-image-img">
                                <?php else : ?>
                                    <span style="color: #64748b; font-size: 13px;" id="dfn-event-image-placeholder"><?php esc_html_e( 'Nessuna immagine impostata', 'dfn-theme' ); ?></span>
                                    <img src="" style="max-width: 100%; max-height: 150px; display: none;" id="dfn-event-image-img">
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="dfn_event_image_id" id="dfn_event_image_id" value="<?php echo intval( $image_id ); ?>">
                            
                            <div style="display: flex; gap: 8px; justify-content: center;">
                                <button type="button" class="button button-secondary" id="dfn-upload-image-btn" style="font-weight: 600;">
                                    <?php esc_html_e( 'Seleziona', 'dfn-theme' ); ?>
                                </button>
                                <button type="button" class="button" id="dfn-remove-image-btn" style="color: #ef4444; border-color: #fca5a5; display: <?php echo $image_id ? 'inline-block' : 'none'; ?>;">
                                    <?php esc_html_e( 'Rimuovi', 'dfn-theme' ); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco Galleria Immagini -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>🖼️ <?php esc_html_e( 'Galleria Immagini', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body" style="text-align: center;">
                            <?php 
                            $gallery_ids_str = '';
                            $gallery_urls = array();
                            if ( $p_id > 0 ) {
                                $gallery_ids_str = get_post_meta( $p_id, '_product_image_gallery', true );
                                if ( ! empty( $gallery_ids_str ) ) {
                                    $gallery_ids = array_filter( explode( ',', $gallery_ids_str ) );
                                    foreach ( $gallery_ids as $id ) {
                                        $url = wp_get_attachment_image_url( $id, 'thumbnail' );
                                        if ( $url ) {
                                            $gallery_urls[] = array( 'id' => $id, 'url' => $url );
                                        }
                                    }
                                }
                            }
                            ?>
                            <div class="dfn-event-gallery-preview" style="margin-bottom: 15px; min-height: 100px; border: 2px dashed #cbd5e1; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 8px; padding: 8px; justify-content: center; background: #f8fafc;" id="dfn-event-gallery-container">
                                <?php if ( ! empty( $gallery_urls ) ) : ?>
                                    <?php foreach ( $gallery_urls as $item ) : ?>
                                        <div class="dfn-gallery-image-wrapper" data-id="<?php echo esc_attr( $item['id'] ); ?>" style="position: relative; width: 60px; height: 60px; border-radius: 4px; overflow: hidden; border: 1px solid #cbd5e1;">
                                            <img src="<?php echo esc_url( $item['url'] ); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            <span class="dfn-delete-gallery-img" style="position: absolute; top: 0; right: 0; background: rgba(239, 68, 68, 0.8); color: white; border-radius: 0 0 0 4px; width: 16px; height: 16px; line-height: 16px; text-align: center; cursor: pointer; font-size: 10px; font-weight: bold;">×</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span style="color: #64748b; font-size: 13px; align-self: center;" id="dfn-event-gallery-placeholder"><?php esc_html_e( 'Nessuna immagine in galleria', 'dfn-theme' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="dfn_event_gallery_ids" id="dfn_event_gallery_ids" value="<?php echo esc_attr( $gallery_ids_str ); ?>">
                            
                            <div>
                                <button type="button" class="button button-secondary" id="dfn-upload-gallery-btn" style="font-weight: 600;">
                                    <?php esc_html_e( 'Aggiungi Immagini', 'dfn-theme' ); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco Listino Prezzi -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>💰 <?php esc_html_e( 'Listino Contributi', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <p class="description"><?php esc_html_e( 'Contributo minimo per il rispettivo biglietto.', 'dfn-theme' ); ?></p>
                            
                            <div class="dfn-form-group">
                                <label for="price_standard" class="dfn-label"><?php esc_html_e( 'Biglietto Standard (€)', 'dfn-theme' ); ?> <span class="required">*</span></label>
                                <input type="number" name="price_standard" id="price_standard" value="<?php echo esc_attr( $price_std ); ?>" step="0.50" min="0" required class="dfn-input">
                            </div>

                            <div class="dfn-form-group">
                                <label for="price_fai" class="dfn-label"><?php esc_html_e( 'Socio FAI / Scontato (€)', 'dfn-theme' ); ?> <span class="required">*</span></label>
                                <input type="number" name="price_fai" id="price_fai" value="<?php echo esc_attr( $price_fai_member ); ?>" step="0.50" min="0" required class="dfn-input">
                            </div>
                        </div>
                    </div>

                    <!-- Blocco Staff & Note -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>👥 <?php esc_html_e( 'Staff & Note Interne', 'dfn-theme' ); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-group">
                                <label for="staff_config" class="dfn-label"><?php esc_html_e( 'Configurazione Staff / Istruzioni', 'dfn-theme' ); ?></label>
                                <textarea name="staff_config" id="staff_config" class="dfn-textarea" rows="4" placeholder="<?php esc_attr_e( 'Assegnazione volontari o istruzioni speciali per la cassa...', 'dfn-theme' ); ?>"><?php echo esc_textarea( $staff ); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    <?php
}

/**
 * Inline JavaScript per il suggerimento dinamico del campo auto_cancel_hours
 * in base alla modalità di pagamento selezionata.
 */
add_action( 'admin_footer', 'dfn_event_editor_auto_cancel_js' );
function dfn_event_editor_auto_cancel_js() {
    // Esegui solo nella pagina dell'editor eventi
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'dfn-event-editor' ) {
        return;
    }
    ?>
    <script>
    (function() {
        var paymentMode = document.getElementById('payment_mode');
        var autoCancelField = document.getElementById('auto_cancel_hours');
        var helpText = document.getElementById('dfn-auto-cancel-help');

        if (!paymentMode || !autoCancelField) return;

        // Mappa suggerimenti
        var suggestions = {
            'online': 24,
            'in_loco': 0,
            'hybrid': 24
        };

        var helpTexts = {
            'online': '<?php echo esc_js( __( 'Consigliato: 24 ore per pagamenti online.', 'dfn-theme' ) ); ?>',
            'in_loco': '<?php echo esc_js( __( 'Consigliato: 0 (disabilitato) — il pagamento avviene il giorno dell\'evento.', 'dfn-theme' ) ); ?>',
            'hybrid': '<?php echo esc_js( __( 'Consigliato: 24 ore. Gli ordini in loco sono esclusi automaticamente.', 'dfn-theme' ) ); ?>'
        };

        // Traccia se l'utente ha modificato manualmente il campo
        var userModified = false;
        autoCancelField.addEventListener('input', function() {
            userModified = true;
        });

        paymentMode.addEventListener('change', function() {
            var mode = this.value;
            if (!userModified && suggestions.hasOwnProperty(mode)) {
                autoCancelField.value = suggestions[mode];
            }
            if (helpText && helpTexts.hasOwnProperty(mode)) {
                helpText.textContent = helpTexts[mode];
            }
        });
    })();
    </script>
    <?php
}

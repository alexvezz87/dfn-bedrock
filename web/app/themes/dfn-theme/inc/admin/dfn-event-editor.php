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

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Stampa l'icona trigger di un tooltip modal.
 *
 * @param string $tooltip_id  ID del modal da aprire (senza #).
 * @param string $aria_label  Testo alternativo per accessibilità.
 */
function dfn_tooltip_icon( string $tooltip_id, string $aria_label = '' ): void {
    $label = $aria_label ?: __( 'Informazioni su questo campo', 'dfn-theme' );
    echo '<button type="button" class="dfn-tooltip-trigger" '
        . 'data-tooltip="' . esc_attr( $tooltip_id ) . '" '
        . 'aria-label="' . esc_attr( $label ) . '" '
        . 'title="' . esc_attr( $label ) . '">?</button>';
}

/**
 * Renders the Event Editor screen.
 */
function dfn_render_event_editor()
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme'));
    }

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';

    // Self-healing DB check: assicura che le nuove colonne per gli Eventi Test esistano nella tabella
    $col_test_check = $wpdb->get_results("SHOW COLUMNS FROM {$table_events} LIKE 'is_test_event'");
    if (empty($col_test_check)) {
        $wpdb->query("ALTER TABLE {$table_events} ADD COLUMN is_test_event tinyint(1) NOT NULL DEFAULT 0, ADD COLUMN test_notification_email varchar(255) DEFAULT NULL");
    }

    // Determina se stiamo modificando o creando
    $event_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $event = null;

    if ($event_id > 0) {
        $event = dfn_db_get_event($event_id);
        if (! $event) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Evento non trovato.', 'dfn-theme') . '</p></div>';
            return;
        }
    }

    // Gestione salvataggio POST
    $message = '';
    $message_type = 'success';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfn_save_event_nonce'])) {
        if (wp_verify_nonce($_POST['dfn_save_event_nonce'], 'dfn_save_event')) {

            $product_id_raw    = sanitize_text_field($_POST['product_id']);
            $event_date_start  = sanitize_text_field($_POST['event_date_start']);
            $event_date_end    = ! empty($_POST['event_date_end']) ? sanitize_text_field($_POST['event_date_end']) : $event_date_start;
            $event_time_start  = sanitize_text_field($_POST['event_time_start']);
            $event_time_end    = ! empty($_POST['event_time_end']) ? sanitize_text_field($_POST['event_time_end']) : null;
            $location          = sanitize_textarea_field(wp_unslash($_POST['location']));
            $city              = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
            $description       = wp_kses_post(wp_unslash($_POST['description']));
            $access_type       = sanitize_text_field($_POST['access_type']); // free_flow o time_slots
            $allocation_mode   = sanitize_text_field($_POST['allocation_mode']); // automatic o self_selection
            $approval_workflow = sanitize_text_field($_POST['approval_workflow']); // auto o manual
            $payment_mode      = sanitize_text_field($_POST['payment_mode']); // online, in_loco, hybrid
            $slot_duration     = intval($_POST['slot_duration']);
            $slot_capacity     = intval($_POST['slot_capacity']);
            $slot_bonus        = intval($_POST['slot_bonus']);
            $first_slot_time   = ! empty($_POST['first_slot_time']) ? sanitize_text_field($_POST['first_slot_time']) : null;
            $last_slot_time    = ! empty($_POST['last_slot_time']) ? sanitize_text_field($_POST['last_slot_time']) : null;
            $total_capacity    = intval($_POST['total_capacity']);
            $price_standard    = floatval($_POST['price_standard']);
            $price_fai         = (isset($_POST['price_fai']) && $_POST['price_fai'] !== '') ? floatval($_POST['price_fai']) : null;
            $staff_config      = sanitize_textarea_field($_POST['staff_config']);
            $status            = sanitize_text_field($_POST['status']);
            $auto_cancel_hours = intval($_POST['auto_cancel_hours']);
            $detail_layout     = in_array($_POST['detail_layout'] ?? '', ['auto', 'layout1', 'layout2'], true)
                ? $_POST['detail_layout']
                : 'auto';
            $booking_opening_date = ! empty($_POST['booking_opening_date']) ? sanitize_text_field($_POST['booking_opening_date']) : null;
            $booking_status       = in_array($_POST['booking_status'] ?? '', ['open', 'closed', 'email'], true)
                ? $_POST['booking_status']
                : 'open';
            $is_test_event           = isset($_POST['is_test_event']) ? 1 : 0;
            $test_notification_email = ! empty($_POST['test_notification_email']) ? sanitize_email($_POST['test_notification_email']) : null;

            if ($price_fai !== null && $price_fai > $price_standard) {
                $message = __('Errore: Il contributo Socio FAI non può essere superiore a quello Standard.', 'dfn-theme');
                $message_type = 'error';
            } elseif (! empty($_POST['event_date_end']) && strtotime($event_date_end) < strtotime($event_date_start)) {
                $message = __('Errore: La data di fine non può essere antecedente alla data di inizio.', 'dfn-theme');
                $message_type = 'error';
            } else {
                $product_id = 0;
                if ($product_id_raw === 'new') {
                    $event_title = isset($_POST['event_title']) ? sanitize_text_field(wp_unslash($_POST['event_title'])) : '';
                    if (empty($event_title)) {
                        $event_title = 'Evento FAI - ' . date_i18n('d M Y', strtotime($event_date_start));
                    }

                    // Crea il post del prodotto
                    $new_prod_id = wp_insert_post([
                        'post_title'   => $event_title,
                        'post_status'  => 'publish',
                        'post_type'    => 'product',
                        'post_content' => sprintf(__('Prenotazione biglietti per l\'evento: %s.', 'dfn-theme'), $event_title),
                    ]);

                    if (! is_wp_error($new_prod_id) && $new_prod_id > 0) {
                        $product_id = $new_prod_id;

                        // Assegna tipo simple
                        wp_set_object_terms($product_id, 'simple', 'product_type');

                        // Configura metadati del prodotto
                        update_post_meta($product_id, '_visibility', 'visible');
                        update_post_meta($product_id, '_stock_status', 'instock');
                        update_post_meta($product_id, '_virtual', 'yes');
                        update_post_meta($product_id, '_regular_price', $price_standard);
                        update_post_meta($product_id, '_price', $price_standard);
                        update_post_meta($product_id, '_manage_stock', 'yes');

                        // Calcola lo stock totale
                        $total_stock = 0;
                        if ('time_slots' === $access_type) {
                            $first = strtotime($first_slot_time);
                            $last  = strtotime($last_slot_time);
                            $dur   = $slot_duration > 0 ? $slot_duration : 30;
                            $slots_per_day = 1;
                            if ($first && $last && $last > $first) {
                                $slots_per_day = floor(($last - $first) / ($dur * 60)) + 1;
                            }
                            $days = 1;
                            $start_ts = strtotime($event_date_start);
                            $end_ts   = strtotime($event_date_end);
                            if ($start_ts && $end_ts && $end_ts > $start_ts) {
                                $days = floor(($end_ts - $start_ts) / DAY_IN_SECONDS) + 1;
                            }
                            $total_stock = $slot_capacity * $slots_per_day * $days;
                        } else {
                            $total_stock = $total_capacity;
                        }
                        update_post_meta($product_id, '_stock', $total_stock);
                    }
                } else {
                    $product_id = intval($product_id_raw);
                }

                // Associa l'immagine in evidenza o il segnaposto predefinito al prodotto WooCommerce
                if ($product_id > 0) {
                    $image_id = isset($_POST['dfn_event_image_id']) ? intval($_POST['dfn_event_image_id']) : 0;
                    if ($image_id > 0) {
                        set_post_thumbnail($product_id, $image_id);
                    } else {
                        $default_placeholder_id = intval(dfn_get_setting('default_placeholder_image_id', 0));
                        if ($default_placeholder_id > 0 && wp_attachment_is_image($default_placeholder_id)) {
                            set_post_thumbnail($product_id, $default_placeholder_id);
                        } else {
                            delete_post_thumbnail($product_id);
                        }
                    }

                    // Associa la galleria al prodotto WooCommerce
                    $gallery_ids = isset($_POST['dfn_event_gallery_ids']) ? sanitize_text_field($_POST['dfn_event_gallery_ids']) : '';
                    update_post_meta($product_id, '_product_image_gallery', $gallery_ids);

                    // Se l'evento è in modalità TEST, nasconde il prodotto dal catalogo pubblico e dai motori di ricerca
                    if ($is_test_event) {
                        update_post_meta($product_id, '_visibility', 'hidden');
                        wp_set_post_terms($product_id, ['exclude-from-search', 'exclude-from-catalog'], 'product_visibility');
                    } else {
                        update_post_meta($product_id, '_visibility', 'visible');
                        wp_remove_object_terms($product_id, ['exclude-from-search', 'exclude-from-catalog'], 'product_visibility');
                    }
                }

                $data = [
                    'product_id'        => $product_id,
                    'event_date_start'  => $event_date_start,
                    'event_date_end'    => $event_date_end,
                    'event_time_start'  => $event_time_start,
                    'event_time_end'    => $event_time_end,
                    'location'          => $location,
                    'city'              => $city,
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
                    'staff_config'         => $staff_config,
                    'detail_layout'        => $detail_layout,
                    'booking_opening_date' => $booking_opening_date,
                    'booking_status'       => $booking_status,
                    'status'               => $status,
                    'is_test_event'           => $is_test_event,
                    'test_notification_email' => $test_notification_email,
                ];

                $saved = false;
                if ($event_id > 0) {
                    // Modifica
                    $saved = $wpdb->update($table_events, $data, [ 'id' => $event_id ]) !== false;
                } else {
                    // Inserimento
                    $saved = $wpdb->insert($table_events, $data) !== false;
                    $event_id = (int) $wpdb->insert_id;

                    // Genera gli slot iniziali se previsto
                    if ($saved && $event_id > 0 && 'time_slots' === $access_type) {
                        dfn_db_generate_slots_for_event($event_id);
                    }
                }

                if (! $saved || $event_id <= 0) {
                    $message = __('Errore durante il salvataggio dell\'evento nel database: ', 'dfn-theme') . ($wpdb->last_error ?: __('Operazione fallita.', 'dfn-theme'));
                    $message_type = 'error';
                } else {
                    // Sincronizza la giacenza ed il magazzino sul prodotto WooCommerce associato
                    if ($product_id > 0) {
                        update_post_meta($product_id, '_regular_price', $price_standard);
                        update_post_meta($product_id, '_price', $price_standard);
                        wc_delete_product_transients($product_id);

                        if ('free_flow' === $access_type) {
                            $total_booked = (int) $wpdb->get_var($wpdb->prepare(
                                "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings WHERE event_id = %d AND status != 'cancelled'",
                                $event_id
                            ));
                            $remaining_stock = max(0, $total_capacity - $total_booked);
                        } else {
                            $remaining_stock = (int) $wpdb->get_var($wpdb->prepare(
                                "SELECT SUM(GREATEST(0, (capacity + bonus_capacity) - booked_count)) FROM {$wpdb->prefix}dfn_event_slots WHERE event_id = %d AND is_locked = 0",
                                $event_id
                            ));
                            if ($remaining_stock === 0) {
                                $remaining_stock = $total_capacity > 0 ? $total_capacity : ($slot_capacity * 10);
                            }
                        }
                        update_post_meta($product_id, '_manage_stock', 'yes');
                        update_post_meta($product_id, '_stock', $remaining_stock);
                        update_post_meta($product_id, '_stock_status', ($remaining_stock > 0 ? 'instock' : 'outofstock'));
                    }

                    // Reindirizza al tabellone principale con messaggio di successo
                    wp_safe_redirect(admin_url('admin.php?page=dfn-events&action=saved&event_id=' . $event_id));
                    exit;
                }
            }
        } else {
            $message = __('Errore di sicurezza durante il salvataggio dei dati.', 'dfn-theme');
            $message_type = 'error';
        }
    }

    // Recupera l'elenco dei prodotti WooCommerce per associarli all'evento
    $products = get_posts([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    // Valori di default per un nuovo evento o caricati da POST in caso di errore
    $is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');

    $p_id             = $is_post && isset($_POST['product_id']) ? sanitize_text_field($_POST['product_id']) : ($event ? $event->product_id : 0);
    $date_start       = $is_post && isset($_POST['event_date_start']) ? sanitize_text_field($_POST['event_date_start']) : ($event ? $event->event_date_start : '');
    $date_end         = $is_post && isset($_POST['event_date_end']) ? sanitize_text_field($_POST['event_date_end']) : ($event ? $event->event_date_end : '');
    $time_start       = $is_post && isset($_POST['event_time_start']) ? sanitize_text_field($_POST['event_time_start']) : ($event ? $event->event_time_start : '');
    $time_end         = $is_post && isset($_POST['event_time_end']) ? sanitize_text_field($_POST['event_time_end']) : ($event ? $event->event_time_end : '');
    $loc              = $is_post && isset($_POST['location']) ? sanitize_textarea_field(wp_unslash($_POST['location'])) : ($event ? stripslashes($event->location) : '');
    $city_val         = $is_post && isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : ($event && ! empty($event->city) ? stripslashes($event->city) : '');
    $desc             = $is_post && isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : ($event ? stripslashes($event->description) : '');
    $acc_type         = $is_post && isset($_POST['access_type']) ? sanitize_text_field($_POST['access_type']) : ($event ? $event->access_type : 'time_slots');
    $alloc_mode       = $is_post && isset($_POST['allocation_mode']) ? sanitize_text_field($_POST['allocation_mode']) : ($event ? $event->allocation_mode : 'automatic');
    $app_wf           = $is_post && isset($_POST['approval_workflow']) ? sanitize_text_field($_POST['approval_workflow']) : ($event ? $event->approval_workflow : 'auto');
    $pay_mode         = $is_post && isset($_POST['payment_mode']) ? sanitize_text_field($_POST['payment_mode']) : ($event ? $event->payment_mode : 'online');
    $duration         = $is_post && isset($_POST['slot_duration']) ? intval($_POST['slot_duration']) : ($event ? $event->slot_duration : 30);
    $capacity         = $is_post && isset($_POST['slot_capacity']) ? intval($_POST['slot_capacity']) : ($event ? $event->slot_capacity : 20);
    $bonus            = $is_post && isset($_POST['slot_bonus']) ? intval($_POST['slot_bonus']) : ($event ? $event->slot_bonus : 5);
    $first_slot       = $is_post && isset($_POST['first_slot_time']) ? sanitize_text_field($_POST['first_slot_time']) : ($event ? $event->first_slot_time : '10:00:00');
    $last_slot        = $is_post && isset($_POST['last_slot_time']) ? sanitize_text_field($_POST['last_slot_time']) : ($event ? $event->last_slot_time : '18:00:00');
    $tot_cap          = $is_post && isset($_POST['total_capacity']) ? intval($_POST['total_capacity']) : ($event ? $event->total_capacity : 100);
    $price_std        = $is_post && isset($_POST['price_standard']) ? floatval($_POST['price_standard']) : ($event ? $event->price_standard : 10.00);
    $price_fai_member = $is_post ? (isset($_POST['price_fai']) && $_POST['price_fai'] !== '' ? floatval($_POST['price_fai']) : '') : ($event && $event->price_fai !== null && $event->price_fai !== '' ? floatval($event->price_fai) : '');
    $staff            = $is_post && isset($_POST['staff_config']) ? sanitize_textarea_field($_POST['staff_config']) : ($event ? $event->staff_config : '');
    $stat             = $is_post && isset($_POST['status']) ? sanitize_text_field($_POST['status']) : ($event ? $event->status : 'draft');
    $auto_cancel      = $is_post && isset($_POST['auto_cancel_hours']) ? intval($_POST['auto_cancel_hours']) : ($event ? (int) $event->auto_cancel_hours : 24);
    $layout_sel       = $is_post && isset($_POST['detail_layout']) ? sanitize_text_field($_POST['detail_layout']) : ($event && ! empty($event->detail_layout) ? $event->detail_layout : 'auto');
    $booking_opening  = $is_post && isset($_POST['booking_opening_date']) ? sanitize_text_field($_POST['booking_opening_date']) : ($event && ! empty($event->booking_opening_date) ? date('Y-m-d\TH:i', strtotime($event->booking_opening_date)) : '');
    $booking_stat     = $is_post && isset($_POST['booking_status']) ? sanitize_text_field($_POST['booking_status']) : ($event && ! empty($event->booking_status) ? $event->booking_status : 'open');
    $is_test_evt      = $is_post ? (isset($_POST['is_test_event']) ? 1 : 0) : ($event && isset($event->is_test_event) ? (int) $event->is_test_event : 0);
    $test_email       = $is_post && isset($_POST['test_notification_email']) ? sanitize_email($_POST['test_notification_email']) : ($event && isset($event->test_notification_email) ? $event->test_notification_email : '');
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-edit-page"></span>
                <h1><?php echo $event_id > 0 ? esc_html__('Modifica Evento FAI', 'dfn-theme') : esc_html__('Configura Nuovo Evento FAI', 'dfn-theme'); ?></h1>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-events')); ?>" class="page-title-action dfn-btn dfn-btn-secondary">
                <span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e('Torna al Tabellone', 'dfn-theme'); ?>
            </a>
        </header>

        <?php if (! empty($message)) : ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible"><p><?php echo esc_html($message); ?></p></div>
        <?php endif; ?>

        <form method="post" action="" class="dfn-editor-form">
            <?php wp_nonce_field('dfn_save_event', 'dfn_save_event_nonce'); ?>
            
            <div class="dfn-layout-columns">
                <!-- Colonna Principale (Configurazioni) -->
                <div class="dfn-column-main">
                    <!-- Blocco 1: Associazione WooCommerce -->
                    <div class="dfn-card">
                        <div class="dfn-card-header">
                            <h2>⚙️ <?php esc_html_e('Associazione Prodotto', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <p class="description"><?php esc_html_e('Collega questa configurazione di turni e listino ad un Prodotto WooCommerce esistente. Il prodotto funge da carrello per il check-out.', 'dfn-theme'); ?></p>
                            
                            <div class="dfn-form-group">
                                <label for="product_id" class="dfn-label"><?php esc_html_e('Prodotto WooCommerce Collegato', 'dfn-theme'); ?> <span class="required">*</span><?php dfn_tooltip_icon('dfn-tip-product', 'Informazioni: Prodotto WooCommerce Collegato'); ?></label>
                                <select name="product_id" id="product_id" class="dfn-select2" required style="width:100%;">
                                    <option value=""><?php esc_html_e('Seleziona un prodotto...', 'dfn-theme'); ?></option>
                                    <?php if ($event_id === 0) : ?>
                                        <option value="new"><?php esc_html_e('🆕 Crea automaticamente un nuovo Prodotto WooCommerce', 'dfn-theme'); ?></option>
                                    <?php endif; ?>
                                    <?php foreach ($products as $prod) : ?>
                                        <option value="<?php echo $prod->ID; ?>" <?php selected($p_id, $prod->ID); ?>><?php echo esc_html($prod->post_title); ?> (ID: <?php echo $prod->ID; ?>)</option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if ($event_id === 0) : ?>
                                    <div class="dfn-form-group" id="dfn-auto-product-title-group" style="display:none; margin-top: 15px;">
                                        <label for="event_title" class="dfn-label"><?php esc_html_e('Titolo del Nuovo Evento / Biglietto', 'dfn-theme'); ?> <span class="required">*</span></label>
                                        <input type="text" name="event_title" id="event_title" class="dfn-input" placeholder="<?php esc_attr_e('Es: Visita al Castello Visconteo', 'dfn-theme'); ?>">
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco 2: Logistica (Date, Orario, Luogo) -->
                    <div class="dfn-card">
                        <div class="dfn-card-header">
                            <h2>📍 <?php esc_html_e('Logistica & Orari', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-grid-2">
                                <div class="dfn-form-group">
                                    <label for="event_date_start" class="dfn-label"><?php esc_html_e('Data Inizio', 'dfn-theme'); ?> <span class="required">*</span></label>
                                    <input type="date" name="event_date_start" id="event_date_start" value="<?php echo esc_attr($date_start); ?>" required class="dfn-input">
                                </div>
                                <div class="dfn-form-group">
                                    <label for="event_date_end" class="dfn-label"><?php esc_html_e('Data Fine (Opzionale)', 'dfn-theme'); ?></label>
                                    <input type="date" name="event_date_end" id="event_date_end" value="<?php echo esc_attr($date_end); ?>" class="dfn-input">
                                </div>
                            </div>

                            <div class="dfn-form-grid-2">
                                <div class="dfn-form-group">
                                    <label for="event_time_start" class="dfn-label"><?php esc_html_e('Orario Apertura Inizio', 'dfn-theme'); ?> <span class="required">*</span></label>
                                    <input type="time" name="event_time_start" id="event_time_start" value="<?php echo esc_attr($time_start); ?>" required class="dfn-input">
                                </div>
                                <div class="dfn-form-group">
                                    <label for="event_time_end" class="dfn-label"><?php esc_html_e('Orario Chiusura Fine (Opzionale)', 'dfn-theme'); ?></label>
                                    <input type="time" name="event_time_end" id="event_time_end" value="<?php echo esc_attr($time_end); ?>" class="dfn-input">
                                </div>
                            </div>

                            <div class="dfn-form-group" style="margin-bottom: 15px; background: #fffdf5; border: 1px solid #c69c3a; border-radius: 8px; padding: 12px 16px;">
                                <label for="booking_opening_date" class="dfn-label" style="color: #004b23; font-weight: 700;">
                                    ⏱️ <?php esc_html_e('Data e Ora Apertura Prenotazioni (Opzionale)', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-opening-date', 'Informazioni: Data Apertura Prenotazioni'); ?>
                                </label>
                                <input type="datetime-local" name="booking_opening_date" id="booking_opening_date" value="<?php echo esc_attr($booking_opening); ?>" class="dfn-input" style="max-width: 320px; background: #ffffff;">
                                <p class="description" style="margin-top: 6px; font-size: 12px; color: #64748b;">
                                    <?php esc_html_e('Se compilata, le prenotazioni rimarranno bloccate e un countdown indicherà agli utenti il momento esatto di apertura.', 'dfn-theme'); ?>
                                </p>
                                <div class="dfn-form-grid-2">
                                    <div class="dfn-form-group">
                                        <label for="location" class="dfn-label"><?php esc_html_e('Luogo dell\'Evento / Luogo di Ritrovo', 'dfn-theme'); ?> <span class="required">*</span></label>
                                        <textarea name="location" id="location" required class="dfn-textarea" rows="2" placeholder="<?php esc_attr_e('Es: Castello Visconteo-Sforzesco - cortile interno', 'dfn-theme'); ?>"><?php echo esc_textarea($loc); ?></textarea>
                                    </div>
                                    <div class="dfn-form-group">
                                        <label for="city" class="dfn-label"><?php esc_html_e('Comune / Città', 'dfn-theme'); ?></label>
                                        <input type="text" name="city" id="city" value="<?php echo esc_attr($city_val); ?>" placeholder="<?php esc_attr_e('Es: Novara, Arona, Galliate...', 'dfn-theme'); ?>" class="dfn-input" style="height:52px;">
                                    </div>
                                </div>
                            </div>

                            <div class="dfn-form-group" style="margin-top: 15px;">
                                <label for="description" class="dfn-label" style="margin-bottom: 8px; display: block;"><?php esc_html_e('Descrizione', 'dfn-theme'); ?></label>
                                <?php
                                wp_editor($desc, 'description', [
                                    'textarea_name' => 'description',
                                    'textarea_rows' => 8,
                                    'media_buttons' => false,
                                    'teeny'         => false,
                                    'quicktags'     => true,
                                    'tinymce'       => [
                                        'toolbar1' => 'formatselect,bold,italic,underline,strikethrough,alignleft,aligncenter,alignright,alignjustify,bullist,numlist,link,unlink,forecolor,undo,redo',
                                    ],
                                ]);
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco 3: Algoritmo di Allocazione e Slot -->
                    <div class="dfn-card">
                        <div class="dfn-card-header">
                            <h2>🎛️ <?php esc_html_e('Tipologia Accesso & Algoritmo di Allocazione', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-grid-2">
                                <div class="dfn-form-group">
                                    <label for="access_type" class="dfn-label"><?php esc_html_e('Modalità di Accesso', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-access-type', 'Informazioni: Modalità di Accesso'); ?></label>
                                    <select name="access_type" id="access_type" class="dfn-input">
                                        <option value="time_slots" <?php selected($acc_type, 'time_slots'); ?>><?php esc_html_e('⏰ Fasce Orarie (Slot)', 'dfn-theme'); ?></option>
                                        <option value="free_flow" <?php selected($acc_type, 'free_flow'); ?>><?php esc_html_e('🚪 Flusso Libero (Senza fasce)', 'dfn-theme'); ?></option>
                                    </select>
                                </div>

                                <div class="dfn-form-group">
                                    <label for="allocation_mode" class="dfn-label"><?php esc_html_e('Algoritmo di Allocazione (Sezione 2.0)', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-allocation', 'Informazioni: Algoritmo di Allocazione'); ?></label>
                                    <select name="allocation_mode" id="allocation_mode" class="dfn-input">
                                        <option value="automatic" <?php selected($alloc_mode, 'automatic'); ?>><?php esc_html_e('🤖 Assegnazione Automatica (Default)', 'dfn-theme'); ?></option>
                                        <option value="self_selection" <?php selected($alloc_mode, 'self_selection'); ?>><?php esc_html_e('👈 Selezione Turno Libera (Self-selection)', 'dfn-theme'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <!-- Sezione Slot condizionale (mostrata/nascosta via JS) -->
                            <div id="dfn-slot-settings-section">
                                <div class="divider"></div>
                                <h3><?php esc_html_e('Parametri Fasce Orarie', 'dfn-theme'); ?></h3>
                                
                                <div class="dfn-form-grid-3">
                                    <div class="dfn-form-group">
                                        <label for="slot_duration" class="dfn-label"><?php esc_html_e('Durata Turno (minuti)', 'dfn-theme'); ?></label>
                                        <input type="number" name="slot_duration" id="slot_duration" value="<?php echo esc_attr($duration); ?>" min="5" class="dfn-input">
                                    </div>
                                    <div class="dfn-form-group">
                                        <label for="slot_capacity" class="dfn-label"><?php esc_html_e('Capacità standard turno', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-slot-capacity', 'Informazioni: Capacità standard turno'); ?></label>
                                        <input type="number" name="slot_capacity" id="slot_capacity" value="<?php echo esc_attr($capacity); ?>" min="1" class="dfn-input">
                                    </div>
                                    <div class="dfn-form-group">
                                        <label for="slot_bonus" class="dfn-label"><?php esc_html_e('Capacità Bonus (Staff/Live)', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-slot-bonus', 'Informazioni: Capacità Bonus'); ?></label>
                                        <input type="number" name="slot_bonus" id="slot_bonus" value="<?php echo esc_attr($bonus); ?>" min="0" class="dfn-input">
                                    </div>
                                </div>

                                <div class="dfn-form-grid-2">
                                    <div class="dfn-form-group">
                                        <label for="first_slot_time" class="dfn-label"><?php esc_html_e('Orario Primo Turno del Giorno', 'dfn-theme'); ?></label>
                                        <input type="time" name="first_slot_time" id="first_slot_time" value="<?php echo esc_attr($first_slot); ?>" class="dfn-input">
                                    </div>
                                    <div class="dfn-form-group">
                                        <label for="last_slot_time" class="dfn-label"><?php esc_html_e('Orario Ultimo Turno del Giorno', 'dfn-theme'); ?></label>
                                        <input type="time" name="last_slot_time" id="last_slot_time" value="<?php echo esc_attr($last_slot); ?>" class="dfn-input">
                                    </div>
                                </div>
                            </div>

                            <!-- Sezione Free Flow condizionale (mostrata/nascosta via JS) -->
                            <div id="dfn-freeflow-settings-section" style="display:none;">
                                <div class="divider"></div>
                                <h3><?php esc_html_e('Parametri Flusso Libero', 'dfn-theme'); ?></h3>
                                <div class="dfn-form-group" style="max-width:300px;">
                                    <label for="total_capacity" class="dfn-label"><?php esc_html_e('Capacità Massima Evento', 'dfn-theme'); ?></label>
                                    <input type="number" name="total_capacity" id="total_capacity" value="<?php echo esc_attr($tot_cap); ?>" min="1" class="dfn-input">
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
                            <h2>📢 <?php esc_html_e('Pubblica', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-group">
                                <label for="status" class="dfn-label"><?php esc_html_e('Stato Evento', 'dfn-theme'); ?></label>
                                <select name="status" id="status" class="dfn-input">
                                    <option value="draft" <?php selected($stat, 'draft'); ?>><?php esc_html_e('Bozza', 'dfn-theme'); ?></option>
                                    <option value="published" <?php selected($stat, 'published'); ?>><?php esc_html_e('Pubblicato (Attivo)', 'dfn-theme'); ?></option>
                                    <option value="private" <?php selected($stat, 'private'); ?>><?php esc_html_e('Privato (Gestione Interna)', 'dfn-theme'); ?></option>
                                    <option value="archived" <?php selected($stat, 'archived'); ?>><?php esc_html_e('Archiviato', 'dfn-theme'); ?></option>
                                </select>
                            </div>

                            <div class="dfn-form-group">
                                <label for="approval_workflow" class="dfn-label"><?php esc_html_e('Workflow Approvazione', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-approval', 'Informazioni: Workflow Approvazione'); ?></label>
                                <select name="approval_workflow" id="approval_workflow" class="dfn-input">
                                    <option value="auto" <?php selected($app_wf, 'auto'); ?>><?php esc_html_e('Conferma Automatica', 'dfn-theme'); ?></option>
                                    <option value="manual" <?php selected($app_wf, 'manual'); ?>><?php esc_html_e('Manuale (Staff Review)', 'dfn-theme'); ?></option>
                                </select>
                            </div>

                            <div class="dfn-form-group">
                                <label for="booking_status" class="dfn-label"><?php esc_html_e('Stato Modalità Prenotazione', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-booking-status', 'Informazioni: Stato Modalità Prenotazione'); ?></label>
                                <select name="booking_status" id="booking_status" class="dfn-input">
                                    <option value="open" <?php selected($booking_stat, 'open'); ?>><?php esc_html_e('Prenotazioni Aperte (Form Online)', 'dfn-theme'); ?></option>
                                    <option value="closed" <?php selected($booking_stat, 'closed'); ?>><?php esc_html_e('Prenotazioni Chiuse (Sold Out)', 'dfn-theme'); ?></option>
                                    <option value="email" <?php selected($booking_stat, 'email'); ?>><?php esc_html_e('Prenotazione via Email', 'dfn-theme'); ?></option>
                                </select>
                            </div>

                            <div class="dfn-form-group">
                                <label for="payment_mode" class="dfn-label"><?php esc_html_e('Modalità di Pagamento', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-payment', 'Informazioni: Modalità di Pagamento'); ?></label>
                                <select name="payment_mode" id="payment_mode" class="dfn-input">
                                    <option value="online" <?php selected($pay_mode, 'online'); ?>><?php esc_html_e('Solo Pagamento Online', 'dfn-theme'); ?></option>
                                    <option value="in_loco" <?php selected($pay_mode, 'in_loco'); ?>><?php esc_html_e('Solo Saldo in Loco', 'dfn-theme'); ?></option>
                                    <option value="hybrid" <?php selected($pay_mode, 'hybrid'); ?>><?php esc_html_e('Ibrida (Scelta utente)', 'dfn-theme'); ?></option>
                                    <option value="gratuito" <?php selected($pay_mode, 'gratuito'); ?>><?php esc_html_e('🎁 Gratuito (Senza pagamento)', 'dfn-theme'); ?></option>
                                </select>
                            </div>

                            <div class="dfn-form-group" id="dfn-auto-cancel-group">
                                <label for="auto_cancel_hours" class="dfn-label"><?php esc_html_e('Annullamento Automatico (ore)', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-auto-cancel', 'Informazioni: Annullamento Automatico'); ?></label>
                                <input type="number" name="auto_cancel_hours" id="auto_cancel_hours" value="<?php echo esc_attr($auto_cancel); ?>" min="0" step="1" class="dfn-input">
                                <p class="description" style="margin-top: 6px; font-size: 12px; color: #64748b;" id="dfn-auto-cancel-help">
                                    <?php esc_html_e('Dopo quante ore un ordine non pagato viene annullato automaticamente. Imposta 0 per disabilitare (consigliato per pagamento in loco).', 'dfn-theme'); ?>
                                </p>
                            </div>

                            <!-- Blocco Evento di Test -->
                            <div class="dfn-form-group" style="background:#f0fdf4; border:1px solid #bbf7d0; padding:12px 14px; border-radius:8px; margin-top:15px;">
                                <label for="is_test_event" class="dfn-label" style="display:flex; align-items:center; gap:8px; font-weight:700; color:#15803d; cursor:pointer; margin-bottom:4px;">
                                    <input type="checkbox" name="is_test_event" id="is_test_event" value="1" <?php checked($is_test_evt, 1); ?> onchange="document.getElementById('dfn-test-email-wrap').style.display = this.checked ? 'block' : 'none';" />
                                    🧪 Modalità Evento di Test
                                </label>
                                <p class="description" style="font-size:12px; color:#166534; margin:2px 0 8px 24px;">
                                    Se attivo, le notifiche per lo Staff verranno inviate <strong>solo all'email di test</strong> specificata sotto per non intasare la casella ufficiale.
                                </p>

                                <div id="dfn-test-email-wrap" style="display: <?php echo $is_test_evt ? 'block' : 'none'; ?>; margin-left:24px; margin-top:6px;">
                                    <label for="test_notification_email" class="dfn-label" style="font-size:12px; font-weight:600; color:#166534;">
                                        Email Notifiche di Test:
                                    </label>
                                    <input type="email" name="test_notification_email" id="test_notification_email" value="<?php echo esc_attr($test_email); ?>" placeholder="es. tua.email@dominio.it" class="dfn-input" style="width:100%; margin-top:4px;" />
                                </div>
                            </div>

                            <div class="divider"></div>

                            <button type="submit" class="dfn-btn dfn-btn-primary dfn-btn-block">
                                <span class="dashicons dashicons-saved"></span> <?php echo $event_id > 0 ? esc_html__('Aggiorna Configurazione', 'dfn-theme') : esc_html__('Crea e Attiva Evento', 'dfn-theme'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Blocco Immagine in Evidenza -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>🖼️ <?php esc_html_e('Immagine in Evidenza', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body" style="text-align: center;">
                            <?php
                            $image_id = 0;
                            $image_url = '';
                            $is_placeholder = false;
                            if ($p_id > 0) {
                                $image_id = get_post_thumbnail_id($p_id);
                                if ($image_id) {
                                    $image_url = wp_get_attachment_image_url($image_id, 'medium');
                                }
                            }
                            if (! $image_url) {
                                $default_placeholder_id = intval(dfn_get_setting('default_placeholder_image_id', 0));
                                if ($default_placeholder_id > 0) {
                                    $image_url = wp_get_attachment_image_url($default_placeholder_id, 'medium');
                                    if ($image_url) {
                                        $is_placeholder = true;
                                    }
                                }
                            }
                            ?>
                            <div class="dfn-event-image-preview" style="margin-bottom: 15px; min-height: 150px; border: 2px dashed #cbd5e1; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f8fafc; overflow: hidden; position: relative;">
                                <?php if ($image_url) : ?>
                                    <img src="<?php echo esc_url($image_url); ?>" style="max-width: 100%; max-height: 150px; display: block;" id="dfn-event-image-img">
                                    <span style="display: <?php echo $is_placeholder ? 'inline-block' : 'none'; ?>; position: absolute; bottom: 4px; background: rgba(0,0,0,0.6); color: #fff; font-size: 11px; padding: 2px 6px; border-radius: 4px;" id="dfn-event-image-placeholder-label"><?php esc_html_e('Segnaposto Predefinito', 'dfn-theme'); ?></span>
                                <?php else : ?>
                                    <span style="color: #64748b; font-size: 13px;" id="dfn-event-image-placeholder"><?php esc_html_e('Nessuna immagine impostata', 'dfn-theme'); ?></span>
                                    <img src="" style="max-width: 100%; max-height: 150px; display: none;" id="dfn-event-image-img">
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="dfn_event_image_id" id="dfn_event_image_id" value="<?php echo intval($image_id); ?>">
                            
                            <div style="display: flex; gap: 8px; justify-content: center;">
                                <button type="button" class="button button-secondary" id="dfn-upload-image-btn" style="font-weight: 600;">
                                    <?php esc_html_e('Seleziona', 'dfn-theme'); ?>
                                </button>
                                <button type="button" class="button" id="dfn-remove-image-btn" style="color: #ef4444; border-color: #fca5a5; display: <?php echo $image_id ? 'inline-block' : 'none'; ?>;">
                                    <?php esc_html_e('Rimuovi', 'dfn-theme'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco Galleria Immagini -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>🖼️ <?php esc_html_e('Galleria Immagini', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body" style="text-align: center;">
                            <?php
    $gallery_ids_str = '';
    $gallery_urls = [];
    if ($p_id > 0) {
        $gallery_ids_str = get_post_meta($p_id, '_product_image_gallery', true);
        if (! empty($gallery_ids_str)) {
            $gallery_ids = array_filter(explode(',', $gallery_ids_str));
            foreach ($gallery_ids as $id) {
                $url = wp_get_attachment_image_url($id, 'thumbnail');
                if ($url) {
                    $gallery_urls[] = [ 'id' => $id, 'url' => $url ];
                }
            }
        }
    }
    ?>
                            <div class="dfn-event-gallery-preview" style="margin-bottom: 15px; min-height: 100px; border: 2px dashed #cbd5e1; border-radius: 8px; display: flex; flex-wrap: wrap; gap: 8px; padding: 8px; justify-content: center; background: #f8fafc;" id="dfn-event-gallery-container">
                                <?php if (! empty($gallery_urls)) : ?>
                                    <?php foreach ($gallery_urls as $item) : ?>
                                        <div class="dfn-gallery-image-wrapper" data-id="<?php echo esc_attr($item['id']); ?>" style="position: relative; width: 60px; height: 60px; border-radius: 4px; overflow: hidden; border: 1px solid #cbd5e1;">
                                            <img src="<?php echo esc_url($item['url']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            <span class="dfn-delete-gallery-img" style="position: absolute; top: 0; right: 0; background: rgba(239, 68, 68, 0.8); color: white; border-radius: 0 0 0 4px; width: 16px; height: 16px; line-height: 16px; text-align: center; cursor: pointer; font-size: 10px; font-weight: bold;">×</span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span style="color: #64748b; font-size: 13px; align-self: center;" id="dfn-event-gallery-placeholder"><?php esc_html_e('Nessuna immagine in galleria', 'dfn-theme'); ?></span>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="dfn_event_gallery_ids" id="dfn_event_gallery_ids" value="<?php echo esc_attr($gallery_ids_str); ?>">
                            
                            <div>
                                <button type="button" class="button button-secondary" id="dfn-upload-gallery-btn" style="font-weight: 600;">
                                    <?php esc_html_e('Aggiungi Immagini', 'dfn-theme'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Blocco Layout Pagina Dettaglio -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>📐 <?php esc_html_e('Layout Pagina Dettaglio', 'dfn-theme'); ?><?php dfn_tooltip_icon('dfn-tip-layout', 'Informazioni: Layout Pagina Dettaglio'); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <p class="description" style="margin-bottom: 14px;"><?php esc_html_e('Scegli come visualizzare l\'evento nella pagina di dettaglio pubblica. La modalità "Auto" usa il Layout 2 (locandina) se non c\'è galleria.', 'dfn-theme'); ?></p>

                            <div class="dfn-layout-picker" style="display: flex; flex-direction: column; gap: 10px;">

                                <!-- Auto -->
                                <label class="dfn-layout-option" style="display: flex; align-items: center; gap: 12px; padding: 12px; border: 2px solid <?php echo $layout_sel === 'auto' ? '#004b23' : '#e2e8f0'; ?>; border-radius: 10px; cursor: pointer; background: <?php echo $layout_sel === 'auto' ? '#f0fdf4' : '#fff'; ?>; transition: all 0.2s;">
                                    <input type="radio" name="detail_layout" value="auto" <?php checked($layout_sel, 'auto'); ?> style="accent-color: #004b23; width: 16px; height: 16px; flex-shrink: 0;">
                                    <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                                        <!-- Mini preview: auto icon -->
                                        <div style="display: flex; gap: 3px; flex-shrink: 0;">
                                            <div style="width: 28px; height: 20px; background: #cbd5e1; border-radius: 3px; display: flex; flex-direction: column; gap: 2px; padding: 2px; box-sizing: border-box;">
                                                <div style="height: 8px; background: #94a3b8; border-radius: 1px;"></div>
                                                <div style="display: flex; gap: 2px; height: 7px;">
                                                    <div style="flex: 1; background: #94a3b8; border-radius: 1px;"></div>
                                                    <div style="flex: 1; background: #94a3b8; border-radius: 1px;"></div>
                                                </div>
                                            </div>
                                            <span style="font-size: 14px; color: #64748b; align-self: center;">/</span>
                                            <div style="width: 28px; height: 20px; background: #cbd5e1; border-radius: 3px; display: flex; gap: 2px; padding: 2px; box-sizing: border-box;">
                                                <div style="width: 10px; background: #94a3b8; border-radius: 1px;"></div>
                                                <div style="flex: 1; display: flex; flex-direction: column; gap: 2px;">
                                                    <div style="height: 6px; background: #94a3b8; border-radius: 1px;"></div>
                                                    <div style="height: 6px; background: #94a3b8; border-radius: 1px;"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; font-size: 13px; color: #1e293b;"><?php esc_html_e('Automatico', 'dfn-theme'); ?></div>
                                            <div style="font-size: 11px; color: #64748b;"><?php esc_html_e('Layout 2 se no galleria, Layout 1 se galleria', 'dfn-theme'); ?></div>
                                        </div>
                                    </div>
                                </label>

                                <!-- Layout 1 -->
                                <label class="dfn-layout-option" style="display: flex; align-items: center; gap: 12px; padding: 12px; border: 2px solid <?php echo $layout_sel === 'layout1' ? '#004b23' : '#e2e8f0'; ?>; border-radius: 10px; cursor: pointer; background: <?php echo $layout_sel === 'layout1' ? '#f0fdf4' : '#fff'; ?>; transition: all 0.2s;">
                                    <input type="radio" name="detail_layout" value="layout1" <?php checked($layout_sel, 'layout1'); ?> style="accent-color: #004b23; width: 16px; height: 16px; flex-shrink: 0;">
                                    <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                                        <!-- Mini preview: immagine sopra + due colonne -->
                                        <div style="width: 36px; height: 26px; background: #cbd5e1; border-radius: 3px; display: flex; flex-direction: column; gap: 2px; padding: 2px; box-sizing: border-box; flex-shrink: 0;">
                                            <div style="height: 10px; background: #94a3b8; border-radius: 1px;"></div>
                                            <div style="display: flex; gap: 2px; height: 11px;">
                                                <div style="flex: 1; background: #94a3b8; border-radius: 1px;"></div>
                                                <div style="flex: 1; background: #94a3b8; border-radius: 1px;"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; font-size: 13px; color: #1e293b;"><?php esc_html_e('Layout 1 — Galleria', 'dfn-theme'); ?></div>
                                            <div style="font-size: 11px; color: #64748b;"><?php esc_html_e('Slider/Galleria in cima + Info e Form in basso', 'dfn-theme'); ?></div>
                                        </div>
                                    </div>
                                </label>

                                <!-- Layout 2 -->
                                <label class="dfn-layout-option" style="display: flex; align-items: center; gap: 12px; padding: 12px; border: 2px solid <?php echo $layout_sel === 'layout2' ? '#004b23' : '#e2e8f0'; ?>; border-radius: 10px; cursor: pointer; background: <?php echo $layout_sel === 'layout2' ? '#f0fdf4' : '#fff'; ?>; transition: all 0.2s;">
                                    <input type="radio" name="detail_layout" value="layout2" <?php checked($layout_sel, 'layout2'); ?> style="accent-color: #004b23; width: 16px; height: 16px; flex-shrink: 0;">
                                    <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                                        <!-- Mini preview: locandina sinistra + contenuto destra -->
                                        <div style="width: 36px; height: 26px; background: #cbd5e1; border-radius: 3px; display: flex; gap: 2px; padding: 2px; box-sizing: border-box; flex-shrink: 0;">
                                            <div style="width: 12px; background: #94a3b8; border-radius: 1px;"></div>
                                            <div style="flex: 1; display: flex; flex-direction: column; gap: 2px;">
                                                <div style="height: 5px; background: #94a3b8; border-radius: 1px;"></div>
                                                <div style="height: 5px; background: #94a3b8; border-radius: 1px;"></div>
                                                <div style="height: 8px; background: #e74f30; border-radius: 1px;"></div>
                                            </div>
                                        </div>
                                        <div>
                                            <div style="font-weight: 700; font-size: 13px; color: #1e293b;"><?php esc_html_e('Layout 2 — Locandina', 'dfn-theme'); ?></div>
                                            <div style="font-size: 11px; color: #64748b;"><?php esc_html_e('Locandina verticale a sinistra + Form a destra', 'dfn-theme'); ?></div>
                                        </div>
                                    </div>
                                </label>

                            </div>
                        </div>
                    </div>

                    <!-- Blocco Listino Prezzi -->

                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>💰 <?php esc_html_e('Listino Contributi', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <p class="description"><?php esc_html_e('Contributo minimo per il rispettivo biglietto.', 'dfn-theme'); ?></p>
                            
                            <div class="dfn-form-group">
                                <label for="price_standard" class="dfn-label"><?php esc_html_e('Biglietto Standard (€)', 'dfn-theme'); ?> <span class="required">*</span></label>
                                <input type="number" name="price_standard" id="price_standard" value="<?php echo esc_attr($price_std); ?>" step="0.01" min="0" required class="dfn-input">
                            </div>

                            <div class="dfn-form-group">
                                <label for="price_fai" class="dfn-label"><?php esc_html_e('Socio FAI / Scontato (€)', 'dfn-theme'); ?> <span style="font-weight:normal; color:#64748b;">(opzionale)</span></label>
                                <input type="number" name="price_fai" id="price_fai" value="<?php echo esc_attr($price_fai_member); ?>" step="0.01" min="0" class="dfn-input" placeholder="Es. 8.00">
                            </div>
                        </div>
                    </div>

                    <!-- Blocco Staff & Note -->
                    <div class="dfn-card dfn-card-sidebar">
                        <div class="dfn-card-header">
                            <h2>👥 <?php esc_html_e('Staff & Note Interne', 'dfn-theme'); ?></h2>
                        </div>
                        <div class="dfn-card-body">
                            <div class="dfn-form-group">
                                <label for="staff_config" class="dfn-label"><?php esc_html_e('Configurazione Staff / Istruzioni', 'dfn-theme'); ?></label>
                                <textarea name="staff_config" id="staff_config" class="dfn-textarea" rows="4" placeholder="<?php esc_attr_e('Assegnazione volontari o istruzioni speciali per la cassa...', 'dfn-theme'); ?>"><?php echo esc_textarea($staff); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <?php /* ====================================================
         * TOOLTIP MODAL PANELS — FAI Prenotazioni 2.0
         * Tutti i modal sono pre-renderizzati e mostrati/nascosti via JS
         * ==================================================== */ ?>

        <!-- Overlay scuro condiviso -->
        <div class="dfn-tooltip-overlay" id="dfn-tooltip-overlay"></div>

        <!-- Modal: Prodotto WooCommerce Collegato -->
        <div class="dfn-tooltip-modal" id="dfn-tip-product" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-product-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-product-title">⚙️ <?php esc_html_e('Prodotto WooCommerce Collegato', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Ogni evento FAI deve essere collegato a un <strong>Prodotto WooCommerce</strong>. Il prodotto funge da «contenitore» per il carrello e il checkout: quando un utente prenota, il sistema aggiunge il biglietto al carrello di WooCommerce e processa il pagamento tramite i gateway configurati.</p>
                <p><strong>Seleziona un prodotto esistente</strong> se hai già creato manualmente il prodotto su WooCommerce, oppure scegli <strong>«Crea automaticamente»</strong> per generarne uno nuovo al volo direttamente dal form.</p>
                <div class="dfn-tip-box"><strong>Suggerimento:</strong> Il prodotto creato automaticamente sarà di tipo «Simple», virtuale, con prezzo e stock sincronizzati in base alla configurazione dell'evento.</div>
            </div>

        </div>

        <!-- Modal: Modalità di Accesso -->
        <div class="dfn-tooltip-modal" id="dfn-tip-access-type" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-access-type-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-access-type-title">🎛️ <?php esc_html_e('Modalità di Accesso', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Definisce <strong>come gli utenti accedono all'evento</strong> e come vengono organizzate le prenotazioni.</p>
                <ul>
                    <li><strong>⏰ Fasce Orarie (Slot):</strong> l'evento è diviso in turni orari con capacità fissa per turno. Il sistema genera automaticamente gli slot in base a durata, orario primo/ultimo turno e numero di giorni. Ideale per visite guidate, laboratori e attività con flusso controllato.</li>
                    <li><strong>🚪 Flusso Libero:</strong> non ci sono turni orari distinti. Gli utenti prenotano senza scegliere un orario specifico, fino al raggiungimento della capacità totale. Ideale per mostre aperte, eventi a ingresso libero o giornate con accesso continuo.</li>
                </ul>
                <div class="dfn-warn-box">⚠️ Cambiare modalità su un evento già attivo con prenotazioni potrebbe richiedere un reset degli slot. Procedi con attenzione.</div>
            </div>

        </div>

        <!-- Modal: Algoritmo di Allocazione -->
        <div class="dfn-tooltip-modal" id="dfn-tip-allocation" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-allocation-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-allocation-title">🤖 <?php esc_html_e('Algoritmo di Allocazione', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Determina <strong>chi sceglie il turno orario</strong> durante il processo di prenotazione.</p>
                <ul>
                    <li><strong>🤖 Assegnazione Automatica (Default):</strong> il sistema assegna automaticamente il turno meno affollato compatibile con la richiesta dell'utente. L'utente non vede né sceglie l'orario: il turno viene ottimizzato dal backend per distribuire uniformemente i visitatori. Riduce il rischio di turni «vuoti» o «sovraffollati».</li>
                    <li><strong>👈 Selezione Turno Libera (Self-selection):</strong> l'utente può scegliere autonomamente il proprio turno orario da un calendario visivo. Maggiore flessibilità per il visitatore, ma richiede attenzione alla distribuzione manuale del carico.</li>
                </ul>
                <div class="dfn-tip-box"><strong>Novità 2.0:</strong> La Self-selection mostra un selettore visivo degli slot con indicazione dei posti disponibili in tempo reale.</div>
            </div>

        </div>

        <!-- Modal: Capacità standard turno -->
        <div class="dfn-tooltip-modal" id="dfn-tip-slot-capacity" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-slot-capacity-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-slot-capacity-title">🪑 <?php esc_html_e('Capacità Standard Turno', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Indica il <strong>numero massimo di persone accettate in ogni turno orario</strong> attraverso il form di prenotazione online.</p>
                <p>Questo valore viene assegnato a ogni slot generato automaticamente. Il sistema controlla questo limite durante il checkout: se un turno ha già raggiunto la capacità massima, non è più selezionabile.</p>
                <div class="dfn-tip-box"><strong>Nota:</strong> La capacità totale dell'evento è calcolata come: <em>Capacità Turno × Numero Slot × Giorni</em>. Puoi aumentare singolarmente la capacità di uno slot specifico dalla sezione Gestione Turni.</div>
            </div>

        </div>

        <!-- Modal: Capacità Bonus -->
        <div class="dfn-tooltip-modal" id="dfn-tip-slot-bonus" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-slot-bonus-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-slot-bonus-title">➕ <?php esc_html_e('Capacità Bonus (Staff/Live)', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>I posti <strong>Bonus</strong> sono posti aggiuntivi riservati a inserimenti manuali da parte dello staff (banchetto, scanner, inserimento rapido). <strong>Non sono prenotabili online</strong> dagli utenti tramite il form pubblico.</p>
                <p>Questo permette di mantenere una riserva di sicurezza per gestire situazioni come:</p>
                <ul>
                    <li>Visitatori che si presentano al banchetto senza prenotazione</li>
                    <li>Gruppi o delegazioni FAI gestite manualmente</li>
                    <li>Correzioni post-hoc di prenotazioni telefoniche</li>
                </ul>
                <div class="dfn-tip-box"><strong>Suggerimento:</strong> Imposta a <em>0</em> se vuoi che la capienza del turno sia esclusivamente quella online, senza margini di staff.</div>
            </div>

        </div>

        <!-- Modal: Workflow Approvazione -->
        <div class="dfn-tooltip-modal" id="dfn-tip-approval" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-approval-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-approval-title">✅ <?php esc_html_e('Workflow Approvazione Prenotazioni', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Controlla <strong>cosa succede immediatamente dopo che un utente invia una prenotazione</strong>.</p>
                <ul>
                    <li><strong>Conferma Automatica:</strong> la prenotazione viene confermata istantaneamente non appena il pagamento è completato (o all'invio del form per gli eventi gratuiti/in loco). L'utente riceve subito la mail di conferma con il riepilogo.</li>
                    <li><strong>Manuale (Staff Review):</strong> la prenotazione entra nello stato «In attesa di verifica». Un operatore dello staff deve approvarla o rifiutarla manualmente dalla sezione «Verifica Prenotazioni FAI». Solo dopo l'approvazione manuale l'utente riceve la mail di conferma con il link di pagamento.</li>
                </ul>
                <div class="dfn-tip-box"><strong>Quando usare Manuale:</strong> eventi con quota associativa FAI da verificare, gruppi su invito, o eventi con selezione dei partecipanti.</div>
            </div>

        </div>

        <!-- Modal: Stato Modalità Prenotazione -->
        <div class="dfn-tooltip-modal" id="dfn-tip-booking-status" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-booking-status-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-booking-status-title">🔒 <?php esc_html_e('Stato Modalità Prenotazione', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Controlla <strong>cosa vedono gli utenti nella pagina pubblica dell'evento</strong> riguardo alla possibilità di prenotare.</p>
                <ul>
                    <li><strong>Prenotazioni Aperte:</strong> il form di prenotazione è visibile e funzionante. Gli utenti possono prenotare normalmente.</li>
                    <li><strong>Prenotazioni Chiuse (Sold Out):</strong> il form è nascosto e al suo posto viene mostrato un banner «Sold Out» o «Prenotazioni esaurite». Utile quando l'evento è pieno ma non ancora archiviato.</li>
                    <li><strong>Prenotazione via Email:</strong> il form online è disabilitato e viene mostrato un invito a prenotare via email. Utile per eventi su richiesta o con selezione personalizzata.</li>
                </ul>
                <div class="dfn-warn-box">⚠️ Questo campo controlla solo la visibilità del form pubblico, <em>non</em> lo stato interno dell'evento (bozza/pubblicato).</div>
            </div>

        </div>

        <!-- Modal: Modalità di Pagamento -->
        <div class="dfn-tooltip-modal" id="dfn-tip-payment" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-payment-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-payment-title">💳 <?php esc_html_e('Modalità di Pagamento', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Determina <strong>come e quando l'utente paga</strong> la sua prenotazione.</p>
                <ul>
                    <li><strong>💳 Solo Pagamento Online:</strong> l'utente completa il pagamento direttamente tramite WooCommerce (carta di credito, PayPal, ecc.) durante il checkout. Il posto è confermato solo dopo il pagamento.</li>
                    <li><strong>💵 Solo Saldo in Loco:</strong> il posto è riservato subito senza pagamento anticipato. Il saldo avviene al banchetto il giorno dell'evento. L'annullamento automatico è sconsigliato (imposta 0 ore).</li>
                    <li><strong>🔄 Ibrida (Scelta utente):</strong> l'utente sceglie autonomamente se pagare online o in loco durante il form di prenotazione. Massima flessibilità.</li>
                    <li><strong>🎁 Gratuito:</strong> nessun pagamento richiesto. La prenotazione viene confermata automaticamente senza passare dal checkout WooCommerce.</li>
                </ul>
            </div>

        </div>

        <!-- Modal: Annullamento Automatico -->
        <div class="dfn-tooltip-modal" id="dfn-tip-auto-cancel" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-auto-cancel-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-auto-cancel-title">⏳ <?php esc_html_e('Annullamento Automatico', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Se impostato a un valore maggiore di zero, il sistema annulla automaticamente le prenotazioni <strong>non pagate</strong> dopo il numero di ore indicato dalla creazione dell'ordine.</p>
                <p>Questo meccanismo è gestito da un job schedulato (WP-Cron) e libera i posti bloccati da ordini abbandonati, rendendoli nuovamente disponibili per altri utenti.</p>
                <ul>
                    <li><strong>24 ore:</strong> valore consigliato per pagamenti online (da completare entro un giorno)</li>
                    <li><strong>0 (disabilitato):</strong> consigliato per pagamento in loco o eventi gratuiti</li>
                </ul>
                <div class="dfn-tip-box"><strong>Nota tecnica:</strong> Il sistema controlla ogni ora gli ordini scaduti. La cancellazione aggiorna automaticamente la disponibilità degli slot WooCommerce.</div>
            </div>

        </div>

        <!-- Modal: Data Apertura Prenotazioni -->
        <div class="dfn-tooltip-modal" id="dfn-tip-opening-date" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-opening-date-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-opening-date-title">⏱️ <?php esc_html_e('Data e Ora Apertura Prenotazioni', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Se compilata, questa data e ora <strong>blocca il form di prenotazione</strong> fino al momento indicato. Gli utenti che visitano la pagina pubblica dell'evento vedono un <strong>countdown live</strong> che indica quanto manca all'apertura delle prenotazioni.</p>
                <p>Al raggiungimento dell'orario, il form si sblocca automaticamente senza necessità di intervento manuale.</p>
                <div class="dfn-tip-box"><strong>Caso d'uso tipico:</strong> «Le prenotazioni apriranno sabato 15 marzo alle ore 10:00» — configura questa data e il sito gestirà tutto in automatico, creando attesa e urgenza tra i visitatori.</div>
                <div class="dfn-warn-box">⚠️ Se il campo viene lasciato vuoto, le prenotazioni sono immediatamente disponibili (compatibilmente con lo stato Booking Status).</div>
            </div>

        </div>

        <!-- Modal: Layout Pagina Dettaglio -->
        <div class="dfn-tooltip-modal" id="dfn-tip-layout" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-layout-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-layout-title">📐 <?php esc_html_e('Layout Pagina Dettaglio', 'dfn-theme'); ?></h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Controlla <strong>il template visivo</strong> della pagina pubblica di dettaglio dell'evento.</p>
                <ul>
                    <li><strong>Automatico:</strong> il sistema sceglie in autonomia il layout migliore. Se non ci sono immagini in galleria viene usato il Layout 2 (Locandina); se c'è galleria viene usato il Layout 1.</li>
                    <li><strong>Layout 1 — Galleria:</strong> slider fotografico orizzontale in cima alla pagina, seguito da descrizione e form di prenotazione in una griglia a due colonne. Ottimale quando hai più foto di alta qualità dell'evento.</li>
                    <li><strong>Layout 2 — Locandina:</strong> immagine verticale (locandina) a sinistra della pagina e form di prenotazione a destra. Ideale per eventi con una singola immagine locandina istituzionale.</li>
                </ul>
            </div>

        </div>

    </div>
    <?php
}

/**
 * Inline JavaScript per il suggerimento dinamico del campo auto_cancel_hours
 * in base alla modalità di pagamento selezionata.
 */
add_action('admin_footer', 'dfn_event_editor_auto_cancel_js');
function dfn_event_editor_auto_cancel_js()
{
    // Esegui solo nella pagina dell'editor eventi (slug registrato: dfn-event-edit)
    if (! isset($_GET['page']) || (
        $_GET['page'] !== 'dfn-event-edit' &&
        $_GET['page'] !== 'dfn-event-editor'
    )) {
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
            'hybrid': 24,
            'gratuito': 0
        };

        var helpTexts = {
            'online': '<?php echo esc_js(__('Consigliato: 24 ore per pagamenti online.', 'dfn-theme')); ?>',
            'in_loco': '<?php echo esc_js(__('Consigliato: 0 (disabilitato) — il pagamento avviene il giorno dell\'evento.', 'dfn-theme')); ?>',
            'hybrid': '<?php echo esc_js(__('Consigliato: 24 ore. Gli ordini in loco sono esclusi automaticamente.', 'dfn-theme')); ?>',
            'gratuito': '<?php echo esc_js(__('Consigliato: 0 (disabilitato) — evento a partecipazione gratuita.', 'dfn-theme')); ?>'
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
        // Validation: price_fai cannot be higher than price_standard, and date_end cannot be before date_start
        var priceStandard = document.getElementById('price_standard');
        var priceFai = document.getElementById('price_fai');
        var dateStart = document.getElementById('event_date_start');
        var dateEnd = document.getElementById('event_date_end');
        var form = document.querySelector('.dfn-editor-form');

        if (form) {
            form.addEventListener('submit', function(e) {
                if (priceStandard && priceFai && priceFai.value !== '') {
                    var stdVal = parseFloat(priceStandard.value) || 0;
                    var faiVal = parseFloat(priceFai.value) || 0;
                    if (faiVal > stdVal) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Errore: Il contributo Socio FAI non può essere superiore a quello Standard.', 'dfn-theme')); ?>');
                        priceFai.focus();
                        return;
                    }
                }

                if (dateStart && dateEnd && dateStart.value && dateEnd.value) {
                    if (dateEnd.value < dateStart.value) {
                        e.preventDefault();
                        alert('<?php echo esc_js(__('Errore: La data di fine non può essere antecedente alla data di inizio.', 'dfn-theme')); ?>');
                        dateEnd.focus();
                        return;
                    }
                }
            });
        }
    })();
    </script>

    <script>
    // -----------------------------------------------------------------------
    // Layout Picker: evidenzia visivamente l'opzione selezionata + avviso galleria
    // -----------------------------------------------------------------------
    (function() {
        var layoutOptions = document.querySelectorAll('.dfn-layout-option');
        var galleryIds    = document.getElementById('dfn_event_gallery_ids');

        // Avviso galleria (creato dinamicamente)
        var galleryWarning = document.createElement('div');
        galleryWarning.id = 'dfn-layout2-gallery-warning';
        galleryWarning.style.cssText = 'display:none; margin-top:10px; padding:10px 14px; background:#fffbeb; border:1px solid #f59e0b; border-radius:8px; font-size:12px; color:#92400e; line-height:1.5;';
        galleryWarning.innerHTML = '⚠️ <?php echo esc_js(__('Attenzione: hai scelto il Layout Locandina, ma questo evento ha immagini in galleria. La galleria non verrà visualizzata nella pagina di dettaglio. Le immagini restano comunque salvate.', 'dfn-theme')); ?>';

        var layoutPicker = document.querySelector('.dfn-layout-picker');
        if (layoutPicker) {
            layoutPicker.parentNode.appendChild(galleryWarning);
        }

        function hasGalleryImages() {
            if (!galleryIds) return false;
            return galleryIds.value.replace(/,/g, '').trim().length > 0;
        }

        function updateLayoutUI(selectedValue) {
            layoutOptions.forEach(function(label) {
                var radio = label.querySelector('input[type="radio"]');
                if (radio && radio.value === selectedValue) {
                    label.style.borderColor = '#004b23';
                    label.style.background  = '#f0fdf4';
                } else {
                    label.style.borderColor = '#e2e8f0';
                    label.style.background  = '#ffffff';
                }
            });

            // Mostra avviso solo se layout2 è selezionato E c'è galleria
            if (selectedValue === 'layout2' && hasGalleryImages()) {
                galleryWarning.style.display = 'block';
            } else {
                galleryWarning.style.display = 'none';
            }
        }

        layoutOptions.forEach(function(label) {
            var radio = label.querySelector('input[type="radio"]');
            if (radio) {
                radio.addEventListener('change', function() {
                    updateLayoutUI(this.value);
                });
                // Inizializza stato
                if (radio.checked) {
                    updateLayoutUI(radio.value);
                }
            }
        });

        // Aggiorna anche se la galleria cambia (immagini aggiunte/rimosse)
        if (galleryIds) {
            var observer = new MutationObserver(function() {
                var checkedRadio = document.querySelector('.dfn-layout-option input[type="radio"]:checked');
                if (checkedRadio) updateLayoutUI(checkedRadio.value);
            });
            observer.observe(galleryIds, { attributes: true, attributeFilter: ['value'] });
        }
    })();
    </script>

    <script>
    // -----------------------------------------------------------------------
    // DFN Tooltip Modal System — FAI Prenotazioni 2.0
    // Gestione apertura/chiusura modal informativi su click delle icone trigger
    // -----------------------------------------------------------------------
    (function() {
        var overlay       = document.getElementById('dfn-tooltip-overlay');
        var activeModal   = null;
        var triggerEl     = null; // Elemento che ha aperto il modal (per ripristino focus)

        if (!overlay) return; // Esci se non siamo nella pagina corretta

        /**
         * Apre il modal con l'ID specificato.
         * @param {string} modalId
         * @param {HTMLElement} trigger Elemento che ha scatenato l'apertura
         */
        function openModal(modalId, trigger) {
            var modal = document.getElementById(modalId);
            if (!modal) return;

            // Chiudi eventuale modal già aperto
            if (activeModal) closeModal(false);

            activeModal = modal;
            triggerEl   = trigger || null;

            // Mostra overlay e modal con classe attiva
            overlay.classList.add('dfn-tooltip-active');
            modal.classList.add('dfn-tooltip-active');

            // Blocca scroll del body
            document.body.style.overflow = 'hidden';

            // Focus al primo elemento interattivo nel modal
            var focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            if (focusable) {
                setTimeout(function() { focusable.focus(); }, 50);
            }
        }

        /**
         * Chiude il modal attivo.
         * @param {boolean} restoreFocus Se true, ripristina il focus al trigger originale
         */
        function closeModal(restoreFocus) {
            if (!activeModal) return;

            overlay.classList.remove('dfn-tooltip-active');
            activeModal.classList.remove('dfn-tooltip-active');
            document.body.style.overflow = '';

            if (restoreFocus !== false && triggerEl) {
                triggerEl.focus();
            }

            activeModal = null;
            triggerEl   = null;
        }

        // Click sulle icone trigger
        document.addEventListener('click', function(e) {
            var trigger = e.target.closest('.dfn-tooltip-trigger');
            if (trigger) {
                e.preventDefault();
                var modalId = trigger.getAttribute('data-tooltip');
                if (modalId) openModal(modalId, trigger);
                return;
            }

            // Click sui bottoni di chiusura dentro i modal
            var closeBtn = e.target.closest('.dfn-tooltip-modal-close');
            if (closeBtn) {
                e.preventDefault();
                closeModal(true);
                return;
            }

            // Click sull'overlay scuro
            if (e.target === overlay) {
                closeModal(true);
            }
        });

        // Chiusura con tasto Esc
        document.addEventListener('keydown', function(e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && activeModal) {
                closeModal(true);
            }
        });

        // Trap del focus all'interno del modal (accessibilità)
        document.addEventListener('keydown', function(e) {
            if (!activeModal || e.key !== 'Tab') return;

            var focusableEls = activeModal.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            );
            var firstEl = focusableEls[0];
            var lastEl  = focusableEls[focusableEls.length - 1];

            if (e.shiftKey) {
                if (document.activeElement === firstEl) {
                    lastEl.focus();
                    e.preventDefault();
                }
            } else {
                if (document.activeElement === lastEl) {
                    firstEl.focus();
                    e.preventDefault();
                }
            }
        });
    })();
    </script>
    <?php
}

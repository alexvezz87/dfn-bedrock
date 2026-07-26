<?php
/**
 * DFN Booking System 2.0 — Shortcodes & Frontend Displays
 *
 * Registra lo shortcode [dfn_evento id="..."] che genera la scheda evento premium
 * con il selettore dei turni, prezzi standard/soci ed integrazione WooCommerce.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_shortcode('dfn_evento', 'dfn_render_evento_shortcode');
// Mantieni l'alias legacy per retrocompatibilità
add_shortcode('prodotto_condizionale', 'dfn_render_evento_shortcode');

// Registra lo shortcode automatico per la lista/griglia degli eventi pubblici
add_shortcode('dfn_lista_eventi', 'dfn_render_lista_eventi_shortcode');

// Iniezione automatica della scheda prenotazione nella pagina prodotto WooCommerce per gli eventi DFN
add_action('wp', 'dfn_auto_inject_booking_widget_on_single_product');


/**
 * Rende la scheda dell'evento FAI con il selettore dei turni orari.
 *
 * @param array $atts Attributi dello shortcode.
 * @return string HTML generato.
 */
function dfn_render_evento_shortcode($atts): string
{
    $atts = shortcode_atts([
        'id' => 0,
    ], $atts, 'dfn_evento');

    $product_id = intval($atts['id']);
    if ($product_id <= 0) {
        return '<p class="dfn-error-msg">' . esc_html__('ID Prodotto non valido.', 'dfn-theme') . '</p>';
    }

    $product = wc_get_product($product_id);
    if (! $product) {
        return '<p class="dfn-error-msg">' . esc_html__('Prodotto non trovato.', 'dfn-theme') . '</p>';
    }

    // Cerca se esiste un evento associato a questo prodotto
    /** @var \stdClass|null $event */
    $event = dfn_db_get_event_by_product($product_id);
    if (! $event) {
        // Fallback al comportamento standard di acquisto WooCommerce se non c'è una scheda evento
        return prodotto_condizionale_shortcode([ 'id' => $product_id ]);
    }

    // Se l'evento è in stato 'private' (Gestione Interna), mostralo solo a chi ha i privilegi di amministrazione/gestione
    if ('private' === $event->status && ! current_user_can('dfn_manage_events')) {
        return '<p class="dfn-error-msg">' . esc_html__('Questo evento è privato ed accessibile solo ad uso interno.', 'dfn-theme') . '</p>';
    }

    global $wpdb;
    if ('free_flow' === $event->access_type) {
        $total_booked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings WHERE event_id = %d AND status != 'cancelled'",
            $event->id
        ));
        $total_available = max(0, intval($event->total_capacity) - $total_booked);
    } else {
        $table_slots = $wpdb->prefix . 'dfn_event_slots';
        $total_available = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(GREATEST(0, (capacity + bonus_capacity) - booked_count)) 
             FROM {$table_slots} 
             WHERE event_id = %d AND is_locked = 0",
            $event->id
        ));
    }

    $wc_stock    = $product->get_stock_quantity();
    $wc_in_stock = $product->is_in_stock();

    // L'evento è in stock se sia la capacità DFN che il magazzino sono disponibili (> 0)
    $has_available_capacity = ($total_available > 0);
    $is_in_stock            = $has_available_capacity && ($wc_stock === null || $wc_stock > 0 || $wc_in_stock);
    $stock                  = ($wc_stock !== null) ? min($wc_stock, $total_available) : $total_available;

    $price_standard_html = wc_price(floatval($event->price_standard));
    $price_fai_html      = wc_price(floatval($event->price_fai));
    $is_free_event       = ($event->payment_mode === 'gratuito' || (floatval($event->price_standard) == 0.0 && floatval($event->price_fai) == 0.0));
    $image               = $product->get_image('large');

    // --- Galleria immagini ---
    $gallery_ids_str = get_post_meta($product_id, '_product_image_gallery', true);
    $gallery_img_ids = ! empty($gallery_ids_str) ? array_filter(explode(',', $gallery_ids_str)) : [];

    $all_images = [];
    $featured_img_id = get_post_thumbnail_id($product_id);
    if ($featured_img_id) {
        $all_images[] = $featured_img_id;
    }
    if (! empty($gallery_img_ids)) {
        foreach ($gallery_img_ids as $img_id) {
            $all_images[] = $img_id;
        }
    }
    $all_images   = array_unique($all_images);
    $has_multiple = count($all_images) > 1;
    $has_gallery  = ! empty($gallery_img_ids);

    // --- Layout effettivo ---
    $raw_layout      = ! empty($event->detail_layout) ? $event->detail_layout : 'auto';
    $effective_layout = $raw_layout;
    if ($effective_layout === 'auto') {
        $effective_layout = $has_gallery ? 'layout1' : 'layout2';
    }

    ob_start();
    ?>
    <div class="dfn-booking-widget" 
         data-event-id="<?php echo esc_attr((string) $event->id); ?>" 
         data-product-id="<?php echo esc_attr((string) $product_id); ?>"
         data-access-type="<?php echo esc_attr($event->access_type); ?>"
         data-allocation-mode="<?php echo esc_attr($event->allocation_mode); ?>">
        
        <div class="dfn-booking-title">
            <span class="dashicons dashicons-calendar-alt"></span>
            <?php echo esc_html($product->get_name()); ?>
        </div>

    <?php if ($effective_layout === 'layout2') : ?>

        <!-- =========================================================== -->
        <!-- LAYOUT 2: Locandina verticale a sinistra + Contenuto a destra -->
        <!-- =========================================================== -->
        <div class="dfn-layout2-wrapper">

            <!-- Colonna Sinistra: Locandina -->
            <div class="dfn-layout2-poster">
                <?php if ($featured_img_id) :
                    $poster_url = wp_get_attachment_image_url($featured_img_id, 'large');
                    if ($poster_url) : ?>
                        <img src="<?php echo esc_url($poster_url); ?>"
                             alt="<?php echo esc_attr($product->get_name()); ?>"
                             class="dfn-layout2-poster-img">
                    <?php endif;
                else : ?>
                    <div class="dfn-layout2-poster-placeholder">
                        <span class="dashicons dashicons-format-image"></span>
                    </div>
                <?php endif; ?>
            </div><!-- /.dfn-layout2-poster -->

            <!-- Colonna Destra: Descrizione + Form -->
            <div class="dfn-layout2-content">

                <?php if (! empty($event->description)) : ?>
                    <div class="dfn-booking-section dfn-booking-description-section" style="background:#ffffff; padding:20px 24px; border-radius:12px; margin-bottom:20px; border:1px solid #e2e8f0; border-left: 4px solid #004b23; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <div style="font-size:13px; color:#64748b; font-weight:600; margin-bottom:10px; text-transform:uppercase; letter-spacing:0.5px;">🏰 <?php esc_html_e('Descrizione', 'dfn-theme'); ?></div>
                        <div style="font-size:14px; line-height:1.6; color:#334155;"><?php echo wp_kses_post(wpautop($event->description)); ?></div>
                    </div>
                <?php endif; ?>

                <div class="dfn-booking-section" style="background:#f8fafc; padding:18px 20px; border-radius:12px; margin-bottom:20px; border:1px solid #e2e8f0;">
                    <div style="font-size:13px; color:#64748b; font-weight:600; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;">📍 <?php esc_html_e('Luogo ed Orario', 'dfn-theme'); ?></div>
                    <div style="font-size:15px; font-weight:700; color:#004b23; margin-bottom:6px;"><?php echo esc_html($event->location); ?></div>
                    <div style="font-size:13px; color:#475569;">
                        📅 <?php echo esc_html(date_i18n('d F Y', strtotime($event->event_date_start))); ?>
                        <?php if ($event->event_date_end && $event->event_date_end !== $event->event_date_start) : ?>
                            - <?php echo esc_html(date_i18n('d F Y', strtotime($event->event_date_end))); ?>
                        <?php endif; ?>
                        &nbsp;|&nbsp; ⏰ <?php echo esc_html(date('H:i', strtotime($event->event_time_start))); ?>
                    </div>
                    <?php
                    $booking_status_val = ! empty($event->booking_status) ? $event->booking_status : 'open';
                    $now_ts_check       = current_time('timestamp');
                    $opening_ts_check   = ! empty($event->booking_opening_date) ? strtotime($event->booking_opening_date) : 0;
                    $is_opening_future_check = ($opening_ts_check > $now_ts_check);

                    if ($booking_status_val === 'open' && ! $is_opening_future_check && $is_in_stock && ($stock === null || $stock > 0)) : ?>
                        <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                            <div class="dfn-availability-badge">
                                <span class="dfn-pulsing-dot"></span>
                                <span><?php esc_html_e('Posti disponibili', 'dfn-theme'); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Form di Prenotazione / Countdown / Email / Chiuse (Layout 2) -->
                <?php
                $now_ts_l2            = current_time('timestamp');
                $opening_ts_l2        = ! empty($event->booking_opening_date) ? strtotime($event->booking_opening_date) : 0;
                $is_opening_future_l2 = ($opening_ts_l2 > $now_ts_l2);

                $today_l2           = date('Y-m-d');
                $event_end_l2       = ! empty($event->event_date_end) ? $event->event_date_end : $event->event_date_start;
                $is_past_l2         = ($event_end_l2 < $today_l2);
                $booking_status_l2  = ! empty($event->booking_status) ? $event->booking_status : 'open';
                $delegation_email_l2 = dfn_get_setting('delegation_email', 'novara@delegazione.fondoambiente.it');
                $mail_subject_l2    = sprintf(__('Richiesta prenotazione: %s - %s', 'dfn-theme'), $product->get_name(), date_i18n('d M Y', strtotime($event->event_date_start)));
                $mailto_link_l2     = 'mailto:' . esc_attr($delegation_email_l2) . '?subject=' . rawurlencode($mail_subject_l2);
                ?>
                <?php if ($is_opening_future_l2) : ?>
                    <div class="dfn-opening-countdown-card" data-opening-ts="<?php echo esc_attr((string) $opening_ts_l2); ?>">
                        <div class="dfn-countdown-header">
                            <span class="dfn-countdown-icon">⏱️</span>
                            <h3 class="dfn-countdown-title"><?php esc_html_e('Le prenotazioni apriranno a breve', 'dfn-theme'); ?></h3>
                            <p class="dfn-countdown-sub">
                                <strong><?php echo esc_html(date_i18n('d F Y \a\l\l\e H:i', $opening_ts_l2)); ?></strong>
                            </p>
                        </div>
                        <div class="dfn-countdown-grid">
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-days">00</span><span class="dfn-countdown-label"><?php esc_html_e('Giorni', 'dfn-theme'); ?></span></div>
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-hours">00</span><span class="dfn-countdown-label"><?php esc_html_e('Ore', 'dfn-theme'); ?></span></div>
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-mins">00</span><span class="dfn-countdown-label"><?php esc_html_e('Minuti', 'dfn-theme'); ?></span></div>
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-secs">00</span><span class="dfn-countdown-label"><?php esc_html_e('Secondi', 'dfn-theme'); ?></span></div>
                        </div>
                    </div>
                <?php elseif ($booking_status_l2 === 'closed' || $is_past_l2) : ?>
                    <div class="dfn-sold-out-card">
                        <div class="dfn-sold-out-icon">🎟️</div>
                        <h3 class="dfn-sold-out-title"><?php esc_html_e('Prenotazioni Chiuse / Posti Esauriti', 'dfn-theme'); ?></h3>
                        <p class="dfn-sold-out-text"><?php esc_html_e('Le prenotazioni per questo evento sono al momento chiuse o al completo.', 'dfn-theme'); ?></p>
                    </div>
                <?php elseif ($booking_status_l2 === 'email') : ?>
                    <div class="dfn-email-booking-card">
                        <div class="dfn-email-card-icon">✉️</div>
                        <h3 class="dfn-email-card-title"><?php esc_html_e('Prenotazione via Email', 'dfn-theme'); ?></h3>
                        <p class="dfn-email-card-text">
                            <?php esc_html_e('Le prenotazioni online per questo evento avvengono inviando un e-mail diretta alla delegazione:', 'dfn-theme'); ?>
                        </p>
                        <a href="<?php echo esc_url($mailto_link_l2); ?>" class="dfn-email-card-btn">
                            📧 <?php echo esc_html($delegation_email_l2); ?>
                        </a>
                    </div>
                <?php elseif (! $is_in_stock || ($stock !== null && $stock <= 0)) : ?>
                    <div class="dfn-sold-out-card">
                        <div class="dfn-sold-out-icon">🎟️</div>
                        <h3 class="dfn-sold-out-title"><?php esc_html_e('Posti Esauriti', 'dfn-theme'); ?></h3>
                        <p class="dfn-sold-out-text"><?php esc_html_e('Ci dispiace, la disponibilità per questo evento è completa.', 'dfn-theme'); ?></p>
                    </div>
                <?php else : ?>
                    <form class="dfn-booking-form" style="position: relative;">
                        <input type="hidden" name="action" value="dfn_create_direct_booking">
                        <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $event->id); ?>">
                        <input type="hidden" name="product_id" value="<?php echo esc_attr((string) $product_id); ?>">
                        <input type="hidden" name="dfn_booking_slot_id" value="">

                        <!-- STEP 1: Scelta Biglietti & Orario -->
                        <div class="dfn-wizard-step dfn-step-1 active">
                            <!-- Tariffe / Partecipazione Evento -->
                            <?php if ($is_free_event) : ?>
                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Partecipazione Evento', 'dfn-theme'); ?></span>
                                    <div style="background:#eaf7ea; border:1px solid #bbf7d0; border-radius:8px; padding:14px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                        <div style="font-size:16px; font-weight:800; color:#004b23;">🎁 <?php esc_html_e('Ingresso Gratuito', 'dfn-theme'); ?></div>
                                        <div style="font-size:12px; color:#166534; margin-top:2px;"><?php esc_html_e('La partecipazione a questo evento è completamente gratuita e non richiede pagamenti.', 'dfn-theme'); ?></div>
                                    </div>
                                </div>

                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Seleziona Partecipanti', 'dfn-theme'); ?></span>
                                    <div class="dfn-qty-box">
                                        <label for="quantity"><?php esc_html_e('Numero Partecipanti', 'dfn-theme'); ?></label>
                                        <input type="number" name="quantity" id="quantity" min="1" value="1">
                                        <input type="hidden" name="dfn_qty_fai" id="dfn_qty_fai" value="0">
                                    </div>
                                </div>
                            <?php else : ?>
                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Tariffe Contributo', 'dfn-theme'); ?></span>
                                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:10px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px;"><?php esc_html_e('Standard', 'dfn-theme'); ?></div>
                                            <div style="font-size:18px; font-weight:800; color:#1e293b;"><?php echo wp_kses_post($price_standard_html); ?></div>
                                        </div>
                                        <div style="background:#fffdf5; border:1px solid #e74f30; border-radius:8px; padding:10px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                            <div style="font-size:10px; font-weight:700; color:#e74f30; text-transform:uppercase; margin-bottom:4px;"><?php esc_html_e('Soci FAI', 'dfn-theme'); ?></div>
                                            <div style="font-size:18px; font-weight:800; color:#004b23;"><?php echo wp_kses_post($price_fai_html); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Seleziona Partecipanti', 'dfn-theme'); ?></span>
                                    <div class="dfn-qty-grid">
                                        <div class="dfn-qty-box">
                                            <label for="quantity"><?php esc_html_e('Ingresso Standard', 'dfn-theme'); ?></label>
                                            <input type="number" name="quantity" id="quantity" min="0" value="1">
                                        </div>
                                        <div class="dfn-qty-box">
                                            <label for="dfn_qty_fai"><?php esc_html_e('Ingresso Soci FAI', 'dfn-theme'); ?></label>
                                            <input type="number" name="dfn_qty_fai" id="dfn_qty_fai" min="0" value="0">
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Calendario / Data della prenotazione -->
                            <?php
                            $is_single_day_l2 = empty($event->event_date_end) || ($event->event_date_start === $event->event_date_end);
                            $min_date_l2 = (date('Y-m-d') > $event->event_date_start) ? date('Y-m-d') : $event->event_date_start;
                            $max_date_l2 = ! empty($event->event_date_end) ? $event->event_date_end : '';
                            ?>
                            <div class="dfn-booking-section"<?php if ($is_single_day_l2) { echo ' style="display:none;"'; } ?>>
                                <label for="dfn_booking_date" class="dfn-widget-label"><?php esc_html_e('Seleziona Giorno', 'dfn-theme'); ?></label>
                                <div class="dfn-date-input-wrapper">
                                    <input type="date" name="dfn_booking_date" id="dfn_booking_date" class="dfn-date-input"
                                           min="<?php echo esc_attr($min_date_l2); ?>"
                                           <?php if ($max_date_l2) : ?>max="<?php echo esc_attr($max_date_l2); ?>"<?php endif; ?>
                                           value="<?php echo esc_attr($min_date_l2); ?>">
                                </div>
                            </div>

                            <!-- Selettore Slot Orario -->
                            <?php if ('time_slots' === $event->access_type && 'self_selection' === $event->allocation_mode) : ?>
                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Seleziona Turno', 'dfn-theme'); ?></span>
                                    <div class="dfn-slots-container"><!-- Popolato da JS --></div>
                                </div>
                            <?php endif; ?>

                            <button type="button" class="dfn-widget-btn-next dfn-widget-submit" disabled style="margin-top: 15px;">
                                <?php esc_html_e('Continua →', 'dfn-theme'); ?>
                            </button>
                        </div>

                        <!-- STEP 2: Dati Partecipante & Tessere FAI -->
                        <div class="dfn-wizard-step dfn-step-2" style="display:none;">
                            <div class="dfn-booking-section">
                                <span class="dfn-widget-label"><?php esc_html_e('I tuoi Dati di Contatto', 'dfn-theme'); ?></span>
                                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                                    <div>
                                        <label for="dfn_first_name" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Nome *', 'dfn-theme'); ?></label>
                                        <input type="text" name="dfn_first_name" id="dfn_first_name" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label for="dfn_last_name" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Cognome *', 'dfn-theme'); ?></label>
                                        <input type="text" name="dfn_last_name" id="dfn_last_name" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                    </div>
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label for="dfn_email" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Email *', 'dfn-theme'); ?></label>
                                    <input type="email" name="dfn_email" id="dfn_email" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label for="dfn_phone" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Telefono *', 'dfn-theme'); ?></label>
                                    <input type="tel" name="dfn_phone" id="dfn_phone" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label for="dfn_notes" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Note / Richieste Particolari', 'dfn-theme'); ?></label>
                                    <textarea name="dfn_notes" id="dfn_notes" style="width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; box-sizing:border-box;" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="dfn-booking-section dfn-fai-cards-fields-section" style="display:none; background:#fffdf5; padding:15px; border-radius:8px; border:1px solid #e74f30; margin-bottom:20px;">
                                <span class="dfn-widget-label" style="color:#004b23; display:flex; align-items:center; gap:6px;"><?php esc_html_e('Dati Tessere Socio FAI', 'dfn-theme'); ?></span>
                                <div class="dfn-fai-chips-container" style="display:none; margin-top:10px; margin-bottom:12px;">
                                    <div class="dfn-fai-chips-title" style="font-size:11px; font-weight:700; color:#e74f30; text-transform:uppercase; margin-bottom:6px;"><?php esc_html_e('Tessere FAI salvate (clicca per compilare):', 'dfn-theme'); ?></div>
                                    <div class="dfn-fai-chips-list" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
                                </div>
                                <div class="dfn-fai-cards-inputs-container" style="display:flex; flex-direction:column; gap:12px; margin-top:10px;"><!-- Popolato da JS --></div>
                            </div>
                            <div class="dfn-widget-feedback"></div>
                            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:12px; margin-top:20px;">
                                <button type="button" class="dfn-widget-btn-prev" style="height:48px; border:1px solid #cbd5e1; border-radius:8px; background:#ffffff; color:#475569; font-weight:700; cursor:pointer; font-size:13px; text-transform:uppercase; box-sizing:border-box;">
                                    <?php esc_html_e('Indietro', 'dfn-theme'); ?>
                                </button>
                                <button type="submit" class="dfn-widget-submit" style="margin-top:0;">
                                    <span class="dashicons dashicons-calendar-alt" style="margin-top:2px;"></span>
                                    <?php esc_html_e('Conferma Prenotazione', 'dfn-theme'); ?>
                                </button>
                            </div>
                        </div>

                        <!-- STEP 3: Conferma di Successo -->
                        <div class="dfn-wizard-step dfn-step-3" style="display:none; text-align:center; padding:10px 0;">
                            <div class="dfn-success-icon" style="font-size:64px; margin-bottom:15px;">🎉</div>
                            <h3 class="dfn-success-title" style="color:#004b23; font-weight:800; font-size:22px; margin:0 0 10px 0;"></h3>
                            <div class="dfn-success-message" style="font-size:14px; line-height:1.6; color:#475569; margin-bottom:20px; padding:0 10px;"></div>
                            <div class="dfn-success-summary" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px; text-align:left; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.02); font-size:13px; line-height:1.6;"></div>
                            <button type="button" class="dfn-widget-btn-reset dfn-widget-submit" style="margin-top:0; background:#004b23;">
                                <?php esc_html_e('Prenota un altro Evento', 'dfn-theme'); ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

            </div><!-- /.dfn-layout2-content -->

        </div><!-- /.dfn-layout2-wrapper -->

    <?php else : ?>

        <!-- =========================================================== -->
        <!-- LAYOUT 1: Gallery slider in cima + due colonne (ORIGINALE)   -->
        <!-- =========================================================== -->

        <!-- Upper Section: Gallery with responsive slider -->
        <?php if (! empty($all_images)) : ?>
            <div class="dfn-booking-gallery-container" style="margin-bottom: 28px; text-align: center;">
                <div class="dfn-booking-main-image-wrapper <?php echo $has_multiple ? 'dfn-slider-active' : ''; ?>" style="border-radius: 12px; overflow: hidden; height: 480px; background: #f8fafc; border: 1px solid #e2e8f0; position: relative; width: 100%;">
                    
                    <div class="dfn-slider-slides" style="width: 100%; height: 100%; position: relative;">
                        <?php foreach ($all_images as $index => $img_id) :
                            $img_url = wp_get_attachment_image_url($img_id, 'large');
                            if ($img_url) :
                                $active_class = ($index === 0) ? ' active' : '';
                                ?>
                                <div class="dfn-slider-slide<?php echo $active_class; ?>" data-index="<?php echo $index; ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; transition: opacity 0.6s ease-in-out; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                    <img src="<?php echo esc_url($img_url); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                </div>
                                <?php
                            endif;
                        endforeach; ?>
                    </div>

                    <?php if ($has_multiple) : ?>
                        <!-- Navigation Arrows -->
                        <button type="button" class="dfn-slider-arrow prev-arrow" style="position: absolute; top: 50%; left: 16px; transform: translateY(-50%); background: rgba(255,255,255,0.85); border: none; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; font-size: 26px; color: #004b23; font-weight: bold; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.15); transition: all 0.2s ease; z-index: 10;">&lsaquo;</button>
                        <button type="button" class="dfn-slider-arrow next-arrow" style="position: absolute; top: 50%; right: 16px; transform: translateY(-50%); background: rgba(255,255,255,0.85); border: none; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; font-size: 26px; color: #004b23; font-weight: bold; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.15); transition: all 0.2s ease; z-index: 10;">&rsaquo;</button>
                    <?php endif; ?>
                </div>
                
                <?php if ($has_multiple) : ?>
                    <div class="dfn-booking-thumbnails-wrapper" style="display: flex; gap: 8px; margin-top: 12px; justify-content: center; flex-wrap: wrap;">
                        <?php foreach ($all_images as $index => $img_id) :
                            $thumb_url = wp_get_attachment_image_url($img_id, 'thumbnail');
                            if ($thumb_url) {
                                $border_color = ($index === 0) ? '#004b23' : '#e2e8f0';
                                echo '<div class="dfn-gallery-thumb-wrapper" data-index="' . $index . '" style="width: 72px; height: 52px; border-radius: 6px; overflow: hidden; border: 2px solid ' . $border_color . '; cursor: pointer; transition: all 0.2s ease;">';
                                echo '  <img src="' . esc_url($thumb_url) . '" style="width: 100%; height: 100%; object-fit: cover;">';
                                echo '</div>';
                            }
                        endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Split Grid Container (Two Columns) -->
        <div class="dfn-booking-two-columns">
            <!-- Left Column: Info & Description -->
            <div class="dfn-booking-column-info">
                <?php if (! empty($event->description)) : ?>
                    <div class="dfn-booking-section dfn-booking-description-section" style="background:#ffffff; padding:20px 24px; border-radius:12px; margin-bottom:20px; border:1px solid #e2e8f0; border-left: 4px solid #004b23; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <div style="font-size:13px; color:#64748b; font-weight:600; margin-bottom:10px; text-transform:uppercase; letter-spacing:0.5px;">🏰 <?php esc_html_e('Descrizione', 'dfn-theme'); ?></div>
                        <div style="font-size:14px; line-height:1.6; color:#334155;"><?php echo wp_kses_post(wpautop($event->description)); ?></div>
                    </div>
                <?php endif; ?>

                <div class="dfn-booking-section" style="background:#f8fafc; padding:18px 20px; border-radius:12px; margin-bottom:20px; border:1px solid #e2e8f0;">
                    <div style="font-size:13px; color:#64748b; font-weight:600; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;">📍 <?php esc_html_e('Luogo ed Orario', 'dfn-theme'); ?></div>
                    <div style="font-size:15px; font-weight:700; color:#004b23; margin-bottom:6px;"><?php echo esc_html($event->location); ?></div>
                    <div style="font-size:13px; color:#475569;">
                        📅 <?php echo esc_html(date_i18n('d F Y', strtotime($event->event_date_start))); ?>
                        <?php if ($event->event_date_end && $event->event_date_end !== $event->event_date_start) : ?>
                            - <?php echo esc_html(date_i18n('d F Y', strtotime($event->event_date_end))); ?>
                        <?php endif; ?>
                        &nbsp;|&nbsp; ⏰ <?php echo esc_html(date('H:i', strtotime($event->event_time_start))); ?>
                    </div>
                    <?php
                    $booking_status_val = ! empty($event->booking_status) ? $event->booking_status : 'open';
                    $now_ts_check       = current_time('timestamp');
                    $opening_ts_check   = ! empty($event->booking_opening_date) ? strtotime($event->booking_opening_date) : 0;
                    $is_opening_future_check = ($opening_ts_check > $now_ts_check);

                    if ($booking_status_val === 'open' && ! $is_opening_future_check && $is_in_stock && ($stock === null || $stock > 0)) : ?>
                        <div style="margin-top: 12px; padding-top: 10px; border-top: 1px dashed #cbd5e1;">
                            <div class="dfn-availability-badge">
                                <span class="dfn-pulsing-dot"></span>
                                <span><?php esc_html_e('Posti disponibili', 'dfn-theme'); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Booking Form or Countdown or Email (Layout 1) -->
            <div class="dfn-booking-column-action">
                <?php
                $now_ts            = current_time('timestamp');
                $opening_ts        = ! empty($event->booking_opening_date) ? strtotime($event->booking_opening_date) : 0;
                $is_opening_future = ($opening_ts > $now_ts);

                $today            = date('Y-m-d');
                $event_end_date   = ! empty($event->event_date_end) ? $event->event_date_end : $event->event_date_start;
                $is_event_past    = ($event_end_date < $today);
                $booking_status_l1 = ! empty($event->booking_status) ? $event->booking_status : 'open';
                $delegation_email_l1 = dfn_get_setting('delegation_email', 'novara@delegazione.fondoambiente.it');
                $mail_subject_l1  = sprintf(__('Richiesta prenotazione: %s - %s', 'dfn-theme'), $product->get_name(), date_i18n('d M Y', strtotime($event->event_date_start)));
                $mailto_link_l1   = 'mailto:' . esc_attr($delegation_email_l1) . '?subject=' . rawurlencode($mail_subject_l1);
                ?>
                <?php if ($is_opening_future) : ?>
                    <div class="dfn-opening-countdown-card" data-opening-ts="<?php echo esc_attr((string) $opening_ts); ?>">
                        <div class="dfn-countdown-header">
                            <span class="dfn-countdown-icon">⏱️</span>
                            <h3 class="dfn-countdown-title"><?php esc_html_e('Le prenotazioni apriranno a breve', 'dfn-theme'); ?></h3>
                            <p class="dfn-countdown-sub">
                                <strong><?php echo esc_html(date_i18n('d F Y \a\l\l\e H:i', $opening_ts)); ?></strong>
                            </p>
                        </div>
                        <div class="dfn-countdown-grid">
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-days">00</span><span class="dfn-countdown-label"><?php esc_html_e('Giorni', 'dfn-theme'); ?></span></div>
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-hours">00</span><span class="dfn-countdown-label"><?php esc_html_e('Ore', 'dfn-theme'); ?></span></div>
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-mins">00</span><span class="dfn-countdown-label"><?php esc_html_e('Minuti', 'dfn-theme'); ?></span></div>
                            <div class="dfn-countdown-box"><span class="dfn-countdown-num dfn-cd-secs">00</span><span class="dfn-countdown-label"><?php esc_html_e('Secondi', 'dfn-theme'); ?></span></div>
                        </div>
                    </div>
                <?php elseif ($booking_status_l1 === 'closed' || $is_event_past) : ?>
                    <div class="dfn-sold-out-card">
                        <div class="dfn-sold-out-icon">🎟️</div>
                        <h3 class="dfn-sold-out-title"><?php esc_html_e('Prenotazioni Chiuse / Posti Esauriti', 'dfn-theme'); ?></h3>
                        <p class="dfn-sold-out-text"><?php esc_html_e('Le prenotazioni per questo evento sono al momento chiuse o al completo.', 'dfn-theme'); ?></p>
                    </div>
                <?php elseif ($booking_status_l1 === 'email') : ?>
                    <div class="dfn-email-booking-card">
                        <div class="dfn-email-card-icon">✉️</div>
                        <h3 class="dfn-email-card-title"><?php esc_html_e('Prenotazione via Email', 'dfn-theme'); ?></h3>
                        <p class="dfn-email-card-text">
                            <?php esc_html_e('Le prenotazioni online per questo evento avvengono inviando un e-mail diretta alla delegazione:', 'dfn-theme'); ?>
                        </p>
                        <a href="<?php echo esc_url($mailto_link_l1); ?>" class="dfn-email-card-btn">
                            📧 <?php echo esc_html($delegation_email_l1); ?>
                        </a>
                    </div>
                <?php elseif (! $is_in_stock || ($stock !== null && $stock <= 0)) : ?>
                    <div class="dfn-sold-out-card">
                        <div class="dfn-sold-out-icon">🎟️</div>
                        <h3 class="dfn-sold-out-title"><?php esc_html_e('Posti Esauriti', 'dfn-theme'); ?></h3>
                        <p class="dfn-sold-out-text"><?php esc_html_e('Ci dispiace, la disponibilità per questo evento è completa.', 'dfn-theme'); ?></p>
                    </div>
                <?php else : ?>
                    <form class="dfn-booking-form" style="position: relative;">
                        <input type="hidden" name="action" value="dfn_create_direct_booking">
                        <input type="hidden" name="event_id" value="<?php echo esc_attr((string) $event->id); ?>">
                        <input type="hidden" name="product_id" value="<?php echo esc_attr((string) $product_id); ?>">
                        <input type="hidden" name="dfn_booking_slot_id" value="">

                        <!-- STEP 1: Scelta Biglietti & Orario -->
                        <div class="dfn-wizard-step dfn-step-1 active">
                            <!-- Tariffe / Partecipazione Evento -->
                            <?php if ($is_free_event) : ?>
                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Partecipazione Evento', 'dfn-theme'); ?></span>
                                    <div style="background:#eaf7ea; border:1px solid #bbf7d0; border-radius:8px; padding:14px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                        <div style="font-size:16px; font-weight:800; color:#004b23;">🎁 <?php esc_html_e('Ingresso Gratuito', 'dfn-theme'); ?></div>
                                        <div style="font-size:12px; color:#166534; margin-top:2px;"><?php esc_html_e('La partecipazione a questo evento è completamente gratuita e non richiede pagamenti.', 'dfn-theme'); ?></div>
                                    </div>
                                </div>

                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Seleziona Partecipanti', 'dfn-theme'); ?></span>
                                    <div class="dfn-qty-box">
                                        <label for="quantity"><?php esc_html_e('Numero Partecipanti', 'dfn-theme'); ?></label>
                                        <input type="number" name="quantity" id="quantity" min="1" value="1">
                                        <input type="hidden" name="dfn_qty_fai" id="dfn_qty_fai" value="0">
                                    </div>
                                </div>
                            <?php else : ?>
                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Tariffe Contributo', 'dfn-theme'); ?></span>
                                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                                        <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:10px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                            <div style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px;"><?php esc_html_e('Standard', 'dfn-theme'); ?></div>
                                            <div style="font-size:18px; font-weight:800; color:#1e293b;"><?php echo wp_kses_post($price_standard_html); ?></div>
                                        </div>
                                        <div style="background:#fffdf5; border:1px solid #e74f30; border-radius:8px; padding:10px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.02);">
                                            <div style="font-size:10px; font-weight:700; color:#e74f30; text-transform:uppercase; margin-bottom:4px;"><?php esc_html_e('Soci FAI', 'dfn-theme'); ?></div>
                                            <div style="font-size:18px; font-weight:800; color:#004b23;"><?php echo wp_kses_post($price_fai_html); ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Seleziona Partecipanti', 'dfn-theme'); ?></span>
                                    <div class="dfn-qty-grid">
                                        <div class="dfn-qty-box">
                                            <label for="quantity"><?php esc_html_e('Ingresso Standard', 'dfn-theme'); ?></label>
                                            <input type="number" name="quantity" id="quantity" min="0" value="1">
                                        </div>
                                        <div class="dfn-qty-box">
                                            <label for="dfn_qty_fai"><?php esc_html_e('Ingresso Soci FAI', 'dfn-theme'); ?></label>
                                            <input type="number" name="dfn_qty_fai" id="dfn_qty_fai" min="0" value="0">
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Calendario / Data della prenotazione -->
                            <?php
                            $is_single_day = empty($event->event_date_end) || ($event->event_date_start === $event->event_date_end);
                            $today = date('Y-m-d');
                            $min_date = ($today > $event->event_date_start) ? $today : $event->event_date_start;
                            $max_date = ! empty($event->event_date_end) ? $event->event_date_end : '';
                            ?>
                            <div class="dfn-booking-section"<?php if ($is_single_day) {
                                echo ' style="display:none;"';
                            } ?>>
                                <label for="dfn_booking_date" class="dfn-widget-label"><?php esc_html_e('Seleziona Giorno', 'dfn-theme'); ?></label>
                                <div class="dfn-date-input-wrapper">
                                    <input type="date" 
                                           name="dfn_booking_date" 
                                           id="dfn_booking_date" 
                                           class="dfn-date-input" 
                                           min="<?php echo esc_attr($min_date); ?>"
                                           <?php if ($max_date) : ?>max="<?php echo esc_attr($max_date); ?>"<?php endif; ?>
                                           value="<?php echo esc_attr($min_date); ?>">
                                </div>
                            </div>

                            <!-- Selettore Slot Orario (Caricato via AJAX) -->
                            <?php if ('time_slots' === $event->access_type && 'self_selection' === $event->allocation_mode) : ?>
                                <div class="dfn-booking-section">
                                    <span class="dfn-widget-label"><?php esc_html_e('Seleziona Turno', 'dfn-theme'); ?></span>
                                    <div class="dfn-slots-container">
                                        <!-- Popolato da JS -->
                                    </div>
                                </div>
                            <?php endif; ?>

                            <button type="button" class="dfn-widget-btn-next dfn-widget-submit" disabled style="margin-top: 15px;">
                                <?php esc_html_e('Continua →', 'dfn-theme'); ?>
                            </button>
                        </div>

                        <!-- STEP 2: Dati Partecipante & Tessere FAI -->
                        <div class="dfn-wizard-step dfn-step-2" style="display:none;">
                            <div class="dfn-booking-section">
                                <span class="dfn-widget-label"><?php esc_html_e('I tuoi Dati di Contatto', 'dfn-theme'); ?></span>
                                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
                                    <div>
                                        <label for="dfn_first_name" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Nome *', 'dfn-theme'); ?></label>
                                        <input type="text" name="dfn_first_name" id="dfn_first_name" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label for="dfn_last_name" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Cognome *', 'dfn-theme'); ?></label>
                                        <input type="text" name="dfn_last_name" id="dfn_last_name" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                    </div>
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label for="dfn_email" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Email *', 'dfn-theme'); ?></label>
                                    <input type="email" name="dfn_email" id="dfn_email" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label for="dfn_phone" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Telefono *', 'dfn-theme'); ?></label>
                                    <input type="tel" name="dfn_phone" id="dfn_phone" required style="width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; box-sizing:border-box;">
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label for="dfn_notes" style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:block; margin-bottom:4px;"><?php esc_html_e('Note / Richieste Particolari', 'dfn-theme'); ?></label>
                                    <textarea name="dfn_notes" id="dfn_notes" style="width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; box-sizing:border-box;" rows="2"></textarea>
                                </div>
                            </div>

                            <!-- Campi dinamici per le Tessere FAI -->
                            <div class="dfn-booking-section dfn-fai-cards-fields-section" style="display:none; background:#fffdf5; padding:15px; border-radius:8px; border:1px solid #e74f30; margin-bottom:20px;">
                                <span class="dfn-widget-label" style="color:#004b23; display:flex; align-items:center; gap:6px;"><?php esc_html_e('Dati Tessere Socio FAI', 'dfn-theme'); ?></span>
                                <div class="dfn-fai-chips-container" style="display:none; margin-top:10px; margin-bottom:12px;">
                                    <div class="dfn-fai-chips-title" style="font-size:11px; font-weight:700; color:#e74f30; text-transform:uppercase; margin-bottom:6px;"><?php esc_html_e('Tessere FAI salvate (clicca per compilare):', 'dfn-theme'); ?></div>
                                    <div class="dfn-fai-chips-list" style="display:flex; flex-wrap:wrap; gap:8px;"></div>
                                </div>
                                <div class="dfn-fai-cards-inputs-container" style="display:flex; flex-direction:column; gap:12px; margin-top:10px;">
                                    <!-- Popolato da JS -->
                                </div>
                            </div>

                            <div class="dfn-widget-feedback"></div>

                            <div style="display:grid; grid-template-columns: 1fr 2fr; gap:12px; margin-top:20px;">
                                <button type="button" class="dfn-widget-btn-prev" style="height:48px; border:1px solid #cbd5e1; border-radius:8px; background:#ffffff; color:#475569; font-weight:700; cursor:pointer; font-size:13px; text-transform:uppercase; box-sizing:border-box;">
                                    <?php esc_html_e('Indietro', 'dfn-theme'); ?>
                                </button>
                                <button type="submit" class="dfn-widget-submit" style="margin-top:0;">
                                    <span class="dashicons dashicons-calendar-alt" style="margin-top:2px;"></span>
                                    <?php esc_html_e('Conferma Prenotazione', 'dfn-theme'); ?>
                                </button>
                            </div>
                        </div>

                        <!-- STEP 3: Conferma di Successo -->
                        <div class="dfn-wizard-step dfn-step-3" style="display:none; text-align:center; padding:10px 0;">
                            <div class="dfn-success-icon" style="font-size:64px; margin-bottom:15px;">🎉</div>
                            <h3 class="dfn-success-title" style="color:#004b23; font-weight:800; font-size:22px; margin:0 0 10px 0;"></h3>
                            
                            <div class="dfn-success-message" style="font-size:14px; line-height:1.6; color:#475569; margin-bottom:20px; padding:0 10px;"></div>
                            
                            <!-- Riepilogo Prenotazione -->
                            <div class="dfn-success-summary" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px; text-align:left; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.02); font-size:13px; line-height:1.6;">
                            </div>

                            <button type="button" class="dfn-widget-btn-reset dfn-widget-submit" style="margin-top:0; background:#004b23;">
                                <?php esc_html_e('Prenota un altro Evento', 'dfn-theme'); ?>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div><!-- /.dfn-booking-widget layout1 end -->

    <?php endif; // end layout1 vs layout2 ?>

    </div><!-- /.dfn-booking-widget -->

    <!-- Bottone Torna a Tutti gli Eventi (Home) in stile FAI -->
    <div class="dfn-back-to-events-footer">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="dfn-btn-back-to-events">
            <span class="dashicons dashicons-arrow-left-alt2"></span>
            <?php esc_html_e('Torna a tutti gli eventi', 'dfn-theme'); ?>
        </a>
    </div>

    <?php
    return ob_get_clean();
}

/**
 * Automagicamente sostituisce il form standard di acquisto WooCommerce con il nostro widget di prenotazione
 * per tutti i prodotti associati ad un evento DFN.
 *
 * @return void
 */
function dfn_auto_inject_booking_widget_on_single_product(): void
{
    if (is_product()) {
        $product_id = get_the_ID();
        if (! $product_id) {
            return;
        }

        $event = dfn_db_get_event_by_product($product_id);
        if ($event) {
            // Rimuovi form di acquisto standard e prezzo di WooCommerce per evitare duplicati
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);

            // Ristrutturazione layout: nascondiamo gli elementi nativi WooCommerce non necessari per gli eventi
            remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
            remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
            remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20);
            remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10);

            // Aggiungi la classe CSS al tag body per allargare a tutta pagina
            add_filter('body_class', 'dfn_add_event_body_class');

            // Inietta il nostro widget premium al posto del pulsante di acquisto
            add_action('woocommerce_single_product_summary', 'dfn_output_single_product_booking_widget', 30);
        }
    }
}

/**
 * Aggiunge la classe dfn-event-single-product alle classi del tag body.
 */
function dfn_add_event_body_class(array $classes): array
{
    $classes[] = 'dfn-event-single-product';
    return $classes;
}

/**
 * Stampa il widget di prenotazione all'interno della pagina prodotto.
 *
 * @return void
 */
function dfn_output_single_product_booking_widget(): void
{
    $product_id = get_the_ID();
    if ($product_id) {
        echo dfn_render_evento_shortcode([ 'id' => $product_id ]);
    }
}
/**
 * Rende una griglia/lista di eventi pubblici.
 *
 * @param array $atts Attributi dello shortcode.
 * @return string HTML generato.
 */
function dfn_render_lista_eventi_shortcode(array $atts = []): string
{
    $atts = shortcode_atts([
        'status'  => 'published',
        'limit'   => -1,
        'filters' => 'yes',
    ], $atts, 'dfn_lista_eventi');

    $status = sanitize_text_field($atts['status']);
    $events = dfn_db_get_events($status);
    if (empty($events)) {
        return '<p class="dfn-no-events-msg" style="text-align:center; padding: 30px; background:#f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; color: #64748b;">'
               . esc_html__('Al momento non ci sono eventi pubblici in programma. Torna presto a trovarci!', 'dfn-theme')
               . '</p>';
    }

    $limit = intval($atts['limit']);
    if ($limit > 0) {
        $events = array_slice($events, 0, $limit);
    }

    // Enqueue JS filtri
    $theme_version = defined('DFN_DB_VERSION') ? DFN_DB_VERSION : '1.0.0';
    wp_enqueue_script('dfn-events-filter', get_stylesheet_directory_uri() . '/assets/js/dfn-events-filter.js', [ 'jquery' ], $theme_version, true);

    $show_filters     = 'yes' === $atts['filters'];
    $available_cities = function_exists('dfn_db_get_event_cities') ? dfn_db_get_event_cities($status) : [];
    $available_months = function_exists('dfn_db_get_event_months') ? dfn_db_get_event_months($status) : [];

    ob_start();
    ?>
    <?php if ($show_filters) : ?>
        <div class="dfn-events-filter-bar" style="margin-bottom: 24px; background: #ffffff; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.03); display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <div class="dfn-filter-item search" style="flex: 1 1 240px;">
                <input type="text" id="dfn-filter-search" class="dfn-filter-input" placeholder="<?php esc_attr_e('🔍 Cerca evento, luogo o parola chiave...', 'dfn-theme'); ?>" style="width: 100%; height: 42px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 13px; box-sizing: border-box;">
            </div>
            <div class="dfn-filter-item month" style="flex: 0 1 180px;">
                <select id="dfn-filter-month" class="dfn-filter-select" style="width: 100%; height: 42px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 13px; background: #ffffff; box-sizing: border-box;">
                    <option value=""><?php esc_html_e('📅 Tutti i mesi', 'dfn-theme'); ?></option>
                    <?php foreach ($available_months as $m) : ?>
                        <option value="<?php echo esc_attr($m['value']); ?>"><?php echo esc_html($m['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (! empty($available_cities)) : ?>
                <div class="dfn-filter-item city" style="flex: 0 1 180px;">
                    <select id="dfn-filter-city" class="dfn-filter-select" style="width: 100%; height: 42px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 13px; background: #ffffff; box-sizing: border-box;">
                        <option value=""><?php esc_html_e('📍 Tutti i comuni', 'dfn-theme'); ?></option>
                        <?php foreach ($available_cities as $c) : ?>
                            <option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="dfn-filter-item reset" style="flex: 0 0 auto;">
                <button type="button" id="dfn-filter-reset" class="dfn-filter-reset-btn" style="height: 42px; border: 1px solid #cbd5e1; border-radius: 8px; background: #f8fafc; color: #475569; padding: 0 14px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    🔄 <?php esc_html_e('Resetta', 'dfn-theme'); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <div class="dfn-events-grid">
        <?php foreach ($events as $event) :
            $product_id = intval($event->product_id);
            $product = wc_get_product($product_id);
            if (! $product) {
                continue;
            }

            $permalink   = get_permalink($product_id);
            $is_in_stock = $product->is_in_stock();
            $stock       = $product->get_stock_quantity();
            $sold_out    = (! $is_in_stock || ($stock !== null && $stock <= 0));

            $price_standard = floatval($event->price_standard);
            $price_fai      = floatval($event->price_fai);
            $year_month     = date('Y-m', strtotime($event->event_date_start));
            $city_name      = ! empty($event->city) ? $event->city : '';
            $location_text  = ! empty($city_name) ? $city_name . ' — ' . $event->location : $event->location;
            ?>
            <div class="dfn-event-card"
                 data-title="<?php echo esc_attr($product->get_name()); ?>"
                 data-location="<?php echo esc_attr($event->location); ?>"
                 data-city="<?php echo esc_attr($city_name); ?>"
                 data-yearmonth="<?php echo esc_attr($year_month); ?>"
                 data-date="<?php echo esc_attr($event->event_date_start); ?>">
                <div class="dfn-event-card-image-wrapper">
                    <a href="<?php echo esc_url($permalink); ?>" class="dfn-event-card-image-link" style="display:block; text-decoration:none;">
                        <?php
                        $thumb_id  = get_post_thumbnail_id($product_id);
                        $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'large') : '';
                        if ($thumb_url) : ?>
                            <img src="<?php echo esc_url($thumb_url); ?>"
                                 alt="<?php echo esc_attr($product->get_name()); ?>"
                                 loading="lazy">
                        <?php else : ?>
                            <div style="min-height:160px; background:#e2e8f0; display:flex; align-items:center; justify-content:center; color:#94a3b8;">
                                <span class="dashicons dashicons-format-image" style="font-size:48px; width:48px; height:48px;"></span>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div class="dfn-event-card-date-badge">
                        📅 <?php echo esc_html(date_i18n('d M Y', strtotime($event->event_date_start))); ?>
                    </div>
                </div>

                <div class="dfn-event-card-content">
                    <a href="<?php echo esc_url($permalink); ?>" class="dfn-event-card-title">
                        <?php echo esc_html($product->get_name()); ?>
                    </a>

                    <div class="dfn-event-card-meta">
                        <span>📍 <strong><?php echo esc_html($location_text); ?></strong></span>
                        <span>⏰ <?php echo esc_html(date('H:i', strtotime($event->event_time_start))); ?></span>
                    </div>

                    <?php if ($event->payment_mode === 'gratuito' || ($price_standard == 0.0 && $price_fai == 0.0)) : ?>
                        <div class="dfn-event-card-price-row free" style="background:#eaf7ea; border-radius:6px; padding:8px 12px; text-align:center; margin-bottom:12px;">
                            <span style="color:#004b23; font-weight:800; font-size:13px;">🎁 Ingresso Gratuito</span>
                        </div>
                    <?php else : ?>
                        <div class="dfn-event-card-price-row">
                            <div class="dfn-event-card-price-item">
                                <span>Intero</span>
                                <div class="dfn-event-card-price-val"><?php echo wp_kses_post(wc_price($price_standard)); ?></div>
                            </div>
                            <div class="dfn-event-card-price-item fai">
                                <span>Socio FAI</span>
                                <div class="dfn-event-card-price-val"><?php echo wp_kses_post(wc_price($price_fai)); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($sold_out) : ?>
                        <a href="<?php echo esc_url($permalink); ?>" class="dfn-event-card-btn sold-out">
                            ❌ Posti Esauriti
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url($permalink); ?>" class="dfn-event-card-btn">
                            Dettaglio e Prenota
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

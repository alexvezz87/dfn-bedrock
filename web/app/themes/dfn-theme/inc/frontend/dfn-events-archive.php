<?php
/**
 * DFN Booking System 2.0 — Public Events Archive & Grid
 *
 * Registra e gestisce lo shortcode [dfn_lista_eventi] per la visualizzazione
 * della griglia eventi in programmazione con filtri per data, mese e comune,
 * oltre alla sezione archivio / Wall per gli eventi passati.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Registra lo shortcode automatico per la lista/griglia degli eventi pubblici
add_shortcode('dfn_lista_eventi', 'dfn_render_lista_eventi_shortcode');

/**
 * Rende una griglia/lista di eventi pubblici.
 *
 * @param array $atts Attributi dello shortcode.
 * @return string HTML generato.
 */
function dfn_render_lista_eventi_shortcode(array $atts = []): string
{
    // Assicura il caricamento degli stili per la griglia/card e dello script recensioni per l'archivio
    wp_enqueue_style('dfn-slot-selector-css');
    wp_enqueue_script('dfn-reviews-carousel-js');

    $atts = shortcode_atts([
        'status'    => 'published',
        'limit'     => -1,
        'filters'   => 'yes',
        'show_past' => 'yes',
    ], $atts, 'dfn_lista_eventi');

    $status = sanitize_text_field($atts['status']);
    $events = dfn_db_get_events($status);
    if (empty($events)) {
        return '<p class="dfn-no-events-msg" style="text-align:center; padding: 30px; background:#f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; color: #64748b;">'
               . esc_html__('Al momento non ci sono eventi pubblici in programma. Torna presto a trovarci!', 'dfn-theme')
               . '</p>';
    }

    $today = current_time('Y-m-d');
    $now   = current_time('H:i:s');

    $upcoming_events = [];
    $past_events     = [];

    foreach ($events as $event) {
        $event_date = $event->event_date_start;
        $event_time = ! empty($event->event_time_end) ? $event->event_time_end : $event->event_time_start;

        $is_past = ($event_date < $today) || ($event_date === $today && ! empty($event_time) && $event_time < $now);

        if ($is_past) {
            $past_events[] = $event;
        } else {
            $upcoming_events[] = $event;
        }
    }

    // Ordina eventi passati in ordine decrescente (i più recenti in cima al Wall)
    usort($past_events, function ($a, $b) {
        return strcmp($b->event_date_start, $a->event_date_start);
    });

    $limit = intval($atts['limit']);
    if ($limit > 0 && count($upcoming_events) > $limit) {
        $upcoming_events = array_slice($upcoming_events, 0, $limit);
    }

    // Enqueue JS filtri
    $theme_version = defined('DFN_DB_VERSION') ? DFN_DB_VERSION : '1.0.0';
    wp_enqueue_script('dfn-events-filter', get_stylesheet_directory_uri() . '/assets/js/dfn-events-filter.js', [ 'jquery' ], $theme_version, true);

    $show_filters = 'yes' === $atts['filters'];

    // Calcola mesi e comuni disponibili ESCLUSIVAMENTE per gli eventi in programmazione
    $available_cities = [];
    $available_months = [];
    $seen_months      = [];

    foreach ($upcoming_events as $ue) {
        if (! empty($ue->city) && ! in_array($ue->city, $available_cities, true)) {
            $available_cities[] = $ue->city;
        }
        if (! empty($ue->event_date_start)) {
            $ym = date('Y-m', strtotime($ue->event_date_start));
            if (! isset($seen_months[$ym])) {
                $seen_months[$ym] = true;
                $ts = strtotime($ym . '-01');
                $available_months[] = [
                    'value' => $ym,
                    'label' => ucfirst(date_i18n('F Y', $ts)),
                ];
            }
        }
    }
    sort($available_cities);

    // Carica statistiche recensioni per gli eventi passati (se presenti)
    global $wpdb;
    $product_ratings = [];
    if (! empty($past_events)) {
        $rating_rows = $wpdb->get_results("
            SELECT oim.meta_value as product_id, COUNT(pm.meta_value) as count, AVG(CAST(pm.meta_value AS DECIMAL(3,2))) as avg_rating
            FROM {$wpdb->prefix}postmeta pm
            JOIN {$wpdb->prefix}woocommerce_order_items oi ON pm.post_id = oi.order_id AND oi.order_item_type = 'line_item'
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
            WHERE pm.meta_key = '_cv_event_rating'
            GROUP BY oim.meta_value
        ");
        if (! empty($rating_rows)) {
            foreach ($rating_rows as $r) {
                $product_ratings[(int) $r->product_id] = [
                    'count'  => (int) $r->count,
                    'rating' => round((float) $r->avg_rating, 1),
                ];
            }
        }
    }

    ob_start();
    ?>

    <!-- ======================================================= -->
    <!-- SEZIONE 1: EVENTI IN PROGRAMMAZIONE                    -->
    <!-- ======================================================= -->
    <?php if ($show_filters && ! empty($upcoming_events)) : ?>
        <div class="dfn-events-filter-bar" style="margin-bottom: 24px; background: #ffffff; padding: 16px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.03); display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
            <div class="dfn-filter-item search" style="flex: 1 1 240px;">
                <input type="text" id="dfn-filter-search" class="dfn-filter-input" placeholder="<?php esc_attr_e('🔍 Cerca evento, luogo o parola chiave...', 'dfn-theme'); ?>" style="width: 100%; height: 42px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 13px; box-sizing: border-box;">
            </div>
            <?php if (! empty($available_months)) : ?>
                <div class="dfn-filter-item month" style="flex: 0 1 180px;">
                    <select id="dfn-filter-month" class="dfn-filter-select" style="width: 100%; height: 42px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 13px; background: #ffffff; box-sizing: border-box;">
                        <option value=""><?php esc_html_e('📅 Tutti i mesi', 'dfn-theme'); ?></option>
                        <?php foreach ($available_months as $m) : ?>
                            <option value="<?php echo esc_attr($m['value']); ?>"><?php echo esc_html($m['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
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

    <?php if (! empty($upcoming_events)) : ?>
        <div class="dfn-events-grid dfn-events-grid--upcoming">
            <?php foreach ($upcoming_events as $event) :
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
                $has_fai_price  = ($event->price_fai !== null && $event->price_fai !== '' && floatval($event->price_fai) > 0);
                $price_fai      = $has_fai_price ? floatval($event->price_fai) : null;
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
                            📅 <?php echo esc_html(date_i18n('d M Y', strtotime($event->event_date_start))); ?> &nbsp;•&nbsp; ⏰ <?php echo esc_html(date('H:i', strtotime($event->event_time_start))); ?>
                        </div>
                    </div>

                    <div class="dfn-event-card-content">
                        <a href="<?php echo esc_url($permalink); ?>" class="dfn-event-card-title">
                            <?php echo esc_html($product->get_name()); ?>
                        </a>

                        <div class="dfn-event-card-meta">
                            <span>📍 <strong><?php echo esc_html($location_text); ?></strong></span>
                        </div>

                        <?php if ($event->payment_mode === 'gratuito' || ($price_standard == 0.0 && (!$has_fai_price || $price_fai == 0.0))) : ?>
                            <div class="dfn-event-card-price-row free" style="background:#eaf7ea; border-radius:6px; padding:8px 12px; text-align:center; margin-bottom:12px;">
                                <span style="color:#004b23; font-weight:800; font-size:13px;">🎁 Ingresso Gratuito</span>
                            </div>
                        <?php elseif ($has_fai_price) : ?>
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
                        <?php else : ?>
                            <div class="dfn-event-card-price-row single-price" style="display:block; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px; text-align:center; margin-bottom:14px;">
                                <span style="font-size:10px; font-weight:600; color:#64748b; text-transform:uppercase; display:block;">Intero</span>
                                <div class="dfn-event-card-price-val" style="font-size:15px; font-weight:800; color:#1e293b; margin-top:2px;"><?php echo wp_kses_post(wc_price($price_standard)); ?></div>
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
    <?php else : ?>
        <div class="dfn-no-upcoming-box" style="text-align:center; padding: 40px 20px; background:#f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 30px;">
            <span style="font-size:36px; display:block; margin-bottom:8px;">🎟️</span>
            <h3 style="margin:0 0 6px; font-size:18px; color:#1e293b;"><?php esc_html_e('Nessun evento al momento in programmazione', 'dfn-theme'); ?></h3>
            <p style="margin:0; font-size:14px; color:#64748b;"><?php esc_html_e('Stiamo preparando le prossime date e aperture straordinarie. Nel frattempo, esplora il nostro archivio qui sotto!', 'dfn-theme'); ?></p>
        </div>
    <?php endif; ?>

    <!-- ======================================================= -->
    <!-- SEZIONE 2: WALL EVENTI PASSATI / ARCHIVIO             -->
    <!-- ======================================================= -->
    <?php if ('yes' === $atts['show_past'] && ! empty($past_events)) : ?>
        <section class="dfn-past-wall-section">
            <div class="dfn-past-wall-divider">
                <span class="dfn-past-divider-line left"></span>
                <span class="dfn-past-divider-badge">
                    🏛️ <?php esc_html_e('Archivio Iniziative', 'dfn-theme'); ?>
                </span>
                <span class="dfn-past-divider-line right"></span>
            </div>

            <div class="dfn-past-wall-header">
                <h2 class="dfn-past-wall-title"><?php esc_html_e('I Nostri Eventi Passati', 'dfn-theme'); ?></h2>
                <p class="dfn-past-wall-subtitle"><?php esc_html_e('Rivivi le atmosfere e le emozioni delle iniziative speciali che abbiamo condiviso insieme.', 'dfn-theme'); ?></p>
            </div>

            <div class="dfn-past-wall-grid">
                <?php foreach ($past_events as $event) :
                    $product_id = intval($event->product_id);
                    $product = wc_get_product($product_id);
                    if (! $product) {
                        continue;
                    }

                    $permalink     = get_permalink($product_id);
                    $city_name     = ! empty($event->city) ? $event->city : '';
                    $location_text = ! empty($city_name) ? $city_name . ' — ' . $event->location : $event->location;
                    $is_reviews_enabled = (get_post_meta($product_id, '_dfn_show_reviews_frontend', true) !== 'no');
                    $rating_data        = $product_ratings[$product_id] ?? null;
                    ?>
                    <div class="dfn-past-card">
                        <div class="dfn-past-card-image-wrapper">
                            <a href="<?php echo esc_url($permalink); ?>" class="dfn-past-card-image-link" style="display:block; text-decoration:none;">
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
                            <div class="dfn-past-card-badge-status">
                                🏁 <?php esc_html_e('Concluso', 'dfn-theme'); ?>
                            </div>
                            <div class="dfn-past-card-date-badge">
                                📅 <?php echo esc_html(date_i18n('d M Y', strtotime($event->event_date_start))); ?>
                            </div>
                        </div>

                        <div class="dfn-past-card-content">
                            <a href="<?php echo esc_url($permalink); ?>" class="dfn-past-card-title">
                                <?php echo esc_html($product->get_name()); ?>
                            </a>

                            <div class="dfn-past-card-meta">
                                <span>📍 <strong><?php echo esc_html($location_text); ?></strong></span>
                            </div>

                            <?php if ($is_reviews_enabled && $rating_data && $rating_data['count'] > 0) : ?>
                                <div class="dfn-past-card-rating">
                                    <div class="dfn-past-rating-num"><?php echo number_format($rating_data['rating'], 1, ',', '.'); ?></div>
                                    <div class="dfn-past-rating-details">
                                        <div class="dfn-past-rating-stars">
                                            <?php echo dfn_render_star_rating_svg($rating_data['rating'], 14); ?>
                                        </div>
                                        <div class="dfn-past-rating-label">
                                            <strong><?php echo intval($rating_data['count']); ?></strong> <?php echo $rating_data['count'] === 1 ? esc_html__('recensione verificata', 'dfn-theme') : esc_html__('recensioni verificate', 'dfn-theme'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <a href="<?php echo esc_url($permalink); ?>" class="dfn-past-card-btn">
                                <span><?php esc_html_e('Vedi Scheda Evento', 'dfn-theme'); ?></span>
                                <span style="font-size:14px;">&rarr;</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php
    return ob_get_clean();
}

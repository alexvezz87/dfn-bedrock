<?php
/**
 * DFN Booking System 2.0 — Event Reviews & Ratings Module
 *
 * Gestione delle recensioni degli eventi: estrazione dati da WooCommerce,
 * formattazione dell'autore nel rispetto della privacy, rendering delle stelle SVG,
 * scorecard statistica e carosello recensioni per eventi conclusi.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Formatta il nome dell'autore recensione per garantire la privacy:
 * Solo Nome di battesimo + iniziale del Cognome (es. "Loredana B.").
 *
 * @param string $first_name Nome di battesimo
 * @param string $last_name  Cognome (opzionale se già compreso nel nome)
 * @return string Nome formattato
 */
function dfn_format_reviewer_name(string $first_name, string $last_name = ''): string
{
    $first_name = trim($first_name);
    $last_name  = trim($last_name);

    if (empty($last_name) && strpos($first_name, ' ') !== false) {
        $parts = explode(' ', $first_name);
        $first_name = array_shift($parts);
        $last_name  = implode(' ', $parts);
    }

    $first_clean = mb_convert_case($first_name, MB_CASE_TITLE, 'UTF-8');
    if (! empty($last_name)) {
        $initial = mb_strtoupper(mb_substr($last_name, 0, 1, 'UTF-8'), 'UTF-8');
        return $first_clean . ' ' . $initial . '.';
    }

    return ! empty($first_clean) ? $first_clean : __('Partecipante Verificato', 'dfn-theme');
}

/**
 * Recupera le recensioni WooCommerce associate ad un evento (prodotto).
 *
 * @param int $product_id ID del prodotto WooCommerce.
 * @return array Dati aggregati e lista recensioni.
 */
function dfn_get_event_reviews_data(int $product_id): array
{
    global $wpdb;
    if ($product_id <= 0) {
        return [
            'count'        => 0,
            'avg_rating'   => 0.0,
            'breakdown'    => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'text_reviews' => [],
            'all_reviews'  => [],
        ];
    }

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT 
            p.ID as order_id,
            pm_rating.meta_value as rating,
            pm_review.meta_value as review_text,
            pm_date.meta_value as rating_date,
            pm_fname.meta_value as first_name,
            pm_lname.meta_value as last_name,
            pm_pub.meta_value as is_published
        FROM {$wpdb->prefix}posts p
        INNER JOIN {$wpdb->prefix}woocommerce_order_items oi 
            ON p.ID = oi.order_id AND oi.order_item_type = 'line_item'
        INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim 
            ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
        INNER JOIN {$wpdb->prefix}postmeta pm_rating 
            ON p.ID = pm_rating.post_id AND pm_rating.meta_key = '_cv_event_rating'
        LEFT JOIN {$wpdb->prefix}postmeta pm_review 
            ON p.ID = pm_review.post_id AND pm_review.meta_key = '_cv_event_review'
        LEFT JOIN {$wpdb->prefix}postmeta pm_date 
            ON p.ID = pm_date.post_id AND pm_date.meta_key = '_cv_event_rating_date'
        LEFT JOIN {$wpdb->prefix}postmeta pm_fname 
            ON p.ID = pm_fname.post_id AND pm_fname.meta_key = '_billing_first_name'
        LEFT JOIN {$wpdb->prefix}postmeta pm_lname 
            ON p.ID = pm_lname.post_id AND pm_lname.meta_key = '_billing_last_name'
        LEFT JOIN {$wpdb->prefix}postmeta pm_pub
            ON p.ID = pm_pub.post_id AND pm_pub.meta_key = '_cv_review_published_frontend'
        WHERE oim.meta_value = %d
          AND p.post_status IN ('wc-processing', 'wc-completed')
        ORDER BY p.ID DESC
    ", $product_id));

    $all_reviews  = [];
    $text_reviews = [];
    $breakdown    = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $total_stars  = 0;

    if (! empty($results)) {
        foreach ($results as $row) {
            $stars = intval($row->rating);
            if ($stars < 1 || $stars > 5) {
                continue;
            }
            $total_stars += $stars;
            if (isset($breakdown[$stars])) {
                $breakdown[$stars]++;
            }

            $author = dfn_format_reviewer_name((string) $row->first_name, (string) $row->last_name);
            $clean_text = ! empty($row->review_text) ? wp_unslash(trim((string) $row->review_text)) : '';

            $formatted_date = '';
            if (! empty($row->rating_date)) {
                $formatted_date = date_i18n('d F Y', strtotime($row->rating_date));
            }

            // Pubblicata nel carosello solo se approvata/spuntata (default 'yes' per recensioni pregresse non ancora moderate)
            $is_published = ($row->is_published !== 'no');

            $rev_data = [
                'order_id'     => (int) $row->order_id,
                'author'       => $author,
                'rating'       => $stars,
                'text'         => $clean_text,
                'date'         => $formatted_date,
                'is_published' => $is_published,
            ];

            $all_reviews[] = $rev_data;
            if (! empty($clean_text) && $is_published) {
                $text_reviews[] = $rev_data;
            }
        }
    }

    $count = count($all_reviews);
    $avg   = $count > 0 ? round($total_stars / $count, 1) : 0.0;

    return [
        'count'        => $count,
        'avg_rating'   => $avg,
        'breakdown'    => $breakdown,
        'text_reviews' => $text_reviews,
        'all_reviews'  => $all_reviews,
    ];
}

/**
 * Renderizza le 5 stelle in formato SVG (supporta piene, mezze stelle e vuote).
 *
 * @param float|int $rating Punteggio da 0 a 5.
 * @param int       $size   Dimensione in pixel delle stelle SVG.
 * @return string HTML con le stelle SVG.
 */
function dfn_render_star_rating_svg($rating, $size = 18): string
{
    $html = '<div class="dfn-star-rating-svg" style="display:inline-flex; gap:2px; align-items:center;">';
    $grad_id = 'dfn_star_grad_' . wp_rand(1000, 99999);
    $has_gradient = false;

    for ($i = 1; $i <= 5; $i++) {
        if ($rating >= $i) {
            // Stella piena
            $html .= '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="#f59e0b" stroke="#f59e0b" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        } elseif ($rating >= ($i - 0.7)) {
            // Mezza stella
            if (! $has_gradient) {
                $html .= '<svg width="0" height="0" style="position:absolute;"><defs><linearGradient id="' . $grad_id . '"><stop offset="50%" stop-color="#f59e0b"/><stop offset="50%" stop-color="#cbd5e1"/></linearGradient></defs></svg>';
                $has_gradient = true;
            }
            $html .= '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="url(#' . $grad_id . ')" stroke="#f59e0b" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        } else {
            // Stella vuota
            $html .= '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="#e2e8f0" stroke="#cbd5e1" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
        }
    }
    $html .= '</div>';
    return $html;
}

/**
 * Renderizza la scheda dell'evento concluso con statistiche e carosello recensioni.
 *
 * @param int       $product_id ID del prodotto WooCommerce.
 * @param \stdClass $event      Oggetto record dell'evento.
 * @return string HTML della scorecard e carosello recensioni.
 */
function dfn_render_event_concluded_reviews_card(int $product_id, $event): string
{
    // Assicura l'enqueue condizionale del JS del carosello
    wp_enqueue_script('dfn-reviews-carousel-js');

    $is_reviews_enabled = (get_post_meta($product_id, '_dfn_show_reviews_frontend', true) !== 'no');
    $reviews_data       = dfn_get_event_reviews_data($product_id);
    $event_date_str     = date_i18n('d F Y', strtotime($event->event_date_start));

    ob_start();
    ?>
    <div class="dfn-concluded-event-box">
        <!-- Header Evento Concluso -->
        <div class="dfn-concluded-header">
            <div class="dfn-concluded-icon">🏁</div>
            <div class="dfn-concluded-header-text">
                <span class="dfn-concluded-pill"><?php esc_html_e('Iniziativa Conclusa', 'dfn-theme'); ?></span>
                <div class="dfn-concluded-date">
                    <?php printf(esc_html__('Evento del %s', 'dfn-theme'), esc_html($event_date_str)); ?>
                </div>
            </div>
        </div>

        <?php if ($is_reviews_enabled && $reviews_data['count'] > 0) : ?>
            <!-- Scorecard Statistiche Recensioni -->
            <div class="dfn-reviews-scorecard">
                <div class="dfn-scorecard-main">
                    <div class="dfn-scorecard-num"><?php echo number_format($reviews_data['avg_rating'], 1, ',', '.'); ?></div>
                    <div class="dfn-scorecard-details">
                        <div class="dfn-scorecard-stars">
                            <?php echo dfn_render_star_rating_svg($reviews_data['avg_rating'], 20); ?>
                        </div>
                        <div class="dfn-scorecard-count">
                            <strong><?php echo intval($reviews_data['count']); ?></strong>
                            <?php echo $reviews_data['count'] === 1 ? esc_html__('recensione verificata', 'dfn-theme') : esc_html__('recensioni verificate', 'dfn-theme'); ?>
                        </div>
                    </div>
                </div>

                <!-- Barre distribuzione stelle -->
                <div class="dfn-scorecard-bars">
                    <?php for ($s = 5; $s >= 1; $s--) :
                        $s_count = $reviews_data['breakdown'][$s] ?? 0;
                        $s_pct   = $reviews_data['count'] > 0 ? round(($s_count / $reviews_data['count']) * 100) : 0;
                    ?>
                        <div class="dfn-bar-row">
                            <span class="dfn-bar-label"><?php echo $s; ?>★</span>
                            <div class="dfn-bar-track">
                                <div class="dfn-bar-fill" style="width: <?php echo $s_pct; ?>%;"></div>
                            </div>
                            <span class="dfn-bar-qty"><?php echo $s_count; ?></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Carosello Recensioni con commento testuale -->
            <?php if (! empty($reviews_data['text_reviews'])) : ?>
                <div class="dfn-reviews-carousel-wrapper">
                    <div class="dfn-carousel-heading">
                        <span class="dfn-carousel-heading-icon">💬</span>
                        <span class="dfn-carousel-heading-title"><?php esc_html_e('Esperienze dei partecipanti', 'dfn-theme'); ?></span>
                        <span class="dfn-carousel-counter">
                            <span class="dfn-curr-slide">1</span> / <?php echo count($reviews_data['text_reviews']); ?>
                        </span>
                    </div>

                    <div class="dfn-reviews-carousel">
                        <div class="dfn-carousel-track">
                            <?php foreach ($reviews_data['text_reviews'] as $idx => $rev) : ?>
                                <div class="dfn-carousel-slide <?php echo $idx === 0 ? 'is-active' : ''; ?>">
                                    <div class="dfn-review-quote-card">
                                        <div class="dfn-review-top-meta">
                                            <div class="dfn-review-stars">
                                                <?php echo dfn_render_star_rating_svg($rev['rating'], 16); ?>
                                            </div>
                                            <?php if (! empty($rev['date'])) : ?>
                                                <span class="dfn-review-date"><?php echo esc_html($rev['date']); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="dfn-review-quote-body">
                                            <span class="dfn-quote-mark left">&ldquo;</span>
                                            <p class="dfn-review-text"><?php echo esc_html($rev['text']); ?></p>
                                            <span class="dfn-quote-mark right">&rdquo;</span>
                                        </div>

                                        <div class="dfn-review-author-row">
                                            <div class="dfn-author-avatar">
                                                <?php echo esc_html(mb_substr($rev['author'], 0, 1, 'UTF-8')); ?>
                                            </div>
                                            <div class="dfn-author-meta">
                                                <div class="dfn-author-name"><?php echo esc_html($rev['author']); ?></div>
                                                <div class="dfn-author-badge">✓ <?php esc_html_e('Partecipante Verificato', 'dfn-theme'); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (count($reviews_data['text_reviews']) > 1) : ?>
                        <div class="dfn-carousel-controls">
                            <button type="button" class="dfn-carousel-nav prev" aria-label="<?php esc_attr_e('Recensione precedente', 'dfn-theme'); ?>">&#10094;</button>
                            <div class="dfn-carousel-dots">
                                <?php foreach ($reviews_data['text_reviews'] as $idx => $rev) : ?>
                                    <button type="button" class="dfn-carousel-dot <?php echo $idx === 0 ? 'is-active' : ''; ?>" data-slide-target="<?php echo $idx; ?>" aria-label="<?php echo esc_attr(sprintf(__('Vai alla recensione %d', 'dfn-theme'), $idx + 1)); ?>"></button>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="dfn-carousel-nav next" aria-label="<?php esc_attr_e('Recensione successiva', 'dfn-theme'); ?>">&#10095;</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php else : ?>
            <div class="dfn-no-reviews-box">
                <p style="margin: 0; font-size: 14px; color: #475569;">
                    <?php esc_html_e('Questo evento si è concluso. Grazie di cuore a tutti i partecipanti!', 'dfn-theme'); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

<?php
/**
 * DFN Booking System 2.0 — Post-Event Gallery (Wall dei Ricordi)
 *
 * Renderizza la galleria fotografica e video post-evento con griglia masonry,
 * effetti dinamici hover, player video HTML5 e lightbox a schermo intero.
 * Include sistema di caching tramite Transient API e lazy loading ad alte prestazioni per i video.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renderizza la galleria fotografica e video post-evento (Wall dei Ricordi)
 * con griglia masonry, effetti dinamici, player video e lightbox a schermo intero.
 * Include sistema di caching tramite Transient API e lazy loading ad alte prestazioni per i video.
 *
 * @param int $product_id ID del prodotto WooCommerce associato all'evento.
 * @return string HTML della galleria post-evento (o stringa vuota se assente).
 */
function dfn_render_post_event_gallery(int $product_id): string
{
    // Verifica cache transient (salva l'HTML già compilato per evitare query ripetute sui media)
    $cache_key    = 'dfn_post_gallery_' . $product_id;
    $bypass_cache = isset($_GET['nocache']) && current_user_can('manage_options');

    if (! $bypass_cache) {
        $cached_html = get_transient($cache_key);
        if (false !== $cached_html) {
            if (! empty($cached_html)) {
                wp_enqueue_script('dfn-post-event-gallery-js');
            }
            return $cached_html;
        }
    }

    $gallery_ids_str = get_post_meta($product_id, '_dfn_post_event_gallery', true);
    if (empty($gallery_ids_str)) {
        if (! $bypass_cache) {
            set_transient($cache_key, '', 12 * HOUR_IN_SECONDS);
        }
        return '';
    }

    $raw_ids = array_filter(array_map('intval', explode(',', $gallery_ids_str)));
    if (empty($raw_ids)) {
        if (! $bypass_cache) {
            set_transient($cache_key, '', 12 * HOUR_IN_SECONDS);
        }
        return '';
    }

    $items = [];
    foreach ($raw_ids as $att_id) {
        if ($att_id <= 0) {
            continue;
        }

        $is_image = wp_attachment_is_image($att_id);
        $is_video = wp_attachment_is('video', $att_id);

        if (! $is_image && ! $is_video) {
            continue;
        }

        // Recupera solo la didascalia esplicita dell'allegato se presente;
        // nessun fallback automatico al titolo o nome file del media (es. IMG_3424) per garantire massima pulizia visiva
        $caption = wp_get_attachment_caption($att_id);
        if (empty($caption)) {
            $caption = '';
        }

        $meta = wp_get_attachment_metadata($att_id);
        $aspect_ratio = (! empty($meta['width']) && ! empty($meta['height']))
            ? ($meta['width'] . ' / ' . $meta['height'])
            : '';

        if ($is_video) {
            $video_url = wp_get_attachment_url($att_id);
            $mime_type = get_post_mime_type($att_id) ?: 'video/webm';
            $thumb_url = wp_get_attachment_image_url($att_id, 'large');
            if ($video_url) {
                $items[] = [
                    'id'           => $att_id,
                    'type'         => 'video',
                    'video_url'    => $video_url,
                    'mime_type'    => $mime_type,
                    'thumb_url'    => $thumb_url ?: '',
                    'caption'      => $caption,
                    'aspect_ratio' => $aspect_ratio,
                ];
            }
        } elseif ($is_image) {
            $thumb_url = wp_get_attachment_image_url($att_id, 'large');
            $full_url  = wp_get_attachment_image_url($att_id, 'full');
            if ($thumb_url && $full_url) {
                $items[] = [
                    'id'           => $att_id,
                    'type'         => 'image',
                    'thumb_url'    => $thumb_url,
                    'full_url'     => $full_url,
                    'caption'      => $caption,
                    'aspect_ratio' => $aspect_ratio,
                ];
            }
        }
    }

    if (empty($items)) {
        if (! $bypass_cache) {
            set_transient($cache_key, '', 12 * HOUR_IN_SECONDS);
        }
        return '';
    }

    // Assicura l'enqueue condizionale del JS della galleria post-evento
    wp_enqueue_script('dfn-post-event-gallery-js');

    ob_start();
    ?>
    <section class="dfn-post-gallery-section" aria-label="<?php esc_attr_e('Galleria fotografica e video dell\'evento', 'dfn-theme'); ?>">
        <!-- Header Sezione Ricordi -->
        <div class="dfn-post-gallery-header">
            <div class="dfn-post-gallery-badge">
                <span class="dfn-badge-icon">📸🎬</span>
                <span><?php esc_html_e('I Nostri Ricordi', 'dfn-theme'); ?></span>
            </div>
            <h2 class="dfn-post-gallery-title"><?php esc_html_e('I momenti più belli dell\'iniziativa', 'dfn-theme'); ?></h2>
            <p class="dfn-post-gallery-subtitle"><?php esc_html_e('Rivivi l\'atmosfera e le emozioni attraverso gli scatti fotografici e i video realizzati durante l\'evento.', 'dfn-theme'); ?></p>
        </div>

        <!-- Griglia Masonry -->
        <div class="dfn-post-gallery-masonry" id="dfn-post-gallery-masonry">
            <?php foreach ($items as $idx => $item) : ?>
                <?php if ($item['type'] === 'video') : ?>
                    <div class="dfn-gallery-item dfn-gallery-item--video" 
                         data-index="<?php echo $idx; ?>" 
                         data-type="video"
                         data-video-src="<?php echo esc_url($item['video_url']); ?>" 
                         data-mime="<?php echo esc_attr($item['mime_type']); ?>"
                         data-caption="<?php echo esc_attr($item['caption']); ?>"
                         tabindex="0"
                         role="button"
                         aria-label="<?php echo esc_attr(sprintf(__('Riproduci video %d', 'dfn-theme'), $idx + 1)); ?>">
                        <div class="dfn-gallery-thumb-wrapper"<?php echo ! empty($item['aspect_ratio']) ? ' style="aspect-ratio: ' . esc_attr($item['aspect_ratio']) . ';"' : ''; ?>>
                            <?php if (! empty($item['thumb_url'])) : ?>
                                <img src="<?php echo esc_url($item['thumb_url']); ?>" 
                                     alt="<?php esc_attr_e('Video dell\'evento', 'dfn-theme'); ?>" 
                                     loading="lazy" 
                                     class="dfn-gallery-thumb" />
                            <?php else : ?>
                                <video class="dfn-gallery-thumb dfn-gallery-video-thumb" 
                                       preload="none" 
                                       muted 
                                       playsinline 
                                       loop
                                       data-lazy-src="<?php echo esc_url($item['video_url']); ?>#t=0.1"
                                       data-mime="<?php echo esc_attr($item['mime_type']); ?>"
                                       <?php echo ! empty($item['aspect_ratio']) ? 'style="aspect-ratio: ' . esc_attr($item['aspect_ratio']) . ';"' : ''; ?>>
                                </video>
                            <?php endif; ?>
                            <span class="dfn-gallery-video-badge">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                <?php esc_html_e('Video', 'dfn-theme'); ?>
                            </span>
                            <div class="dfn-gallery-overlay">
                                <div class="dfn-gallery-play-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                        <polygon points="6 3 20 12 6 21 6 3"></polygon>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="dfn-gallery-item dfn-gallery-item--image" 
                         data-index="<?php echo $idx; ?>" 
                         data-type="image"
                         data-full-src="<?php echo esc_url($item['full_url']); ?>" 
                         data-caption="<?php echo esc_attr($item['caption']); ?>"
                         tabindex="0"
                         role="button"
                         aria-label="<?php echo esc_attr(sprintf(__('Ingrandisci foto %d', 'dfn-theme'), $idx + 1)); ?>">
                        <div class="dfn-gallery-thumb-wrapper"<?php echo ! empty($item['aspect_ratio']) ? ' style="aspect-ratio: ' . esc_attr($item['aspect_ratio']) . ';"' : ''; ?>>
                            <img src="<?php echo esc_url($item['thumb_url']); ?>" 
                                 alt="<?php echo esc_attr($item['caption'] ?: __('Foto dell\'evento', 'dfn-theme')); ?>" 
                                 loading="lazy" 
                                 class="dfn-gallery-thumb" />
                            <div class="dfn-gallery-overlay">
                                <div class="dfn-gallery-zoom-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                        <line x1="11" y1="8" x2="11" y2="14"></line>
                                        <line x1="8" y1="11" x2="14" y2="11"></line>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <!-- Lightbox Modal Fullscreen -->
        <div class="dfn-lightbox-modal" id="dfn-post-gallery-lightbox" aria-hidden="true" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Galleria a schermo intero', 'dfn-theme'); ?>" tabindex="-1">
            <div class="dfn-lightbox-backdrop"></div>
            <div class="dfn-lightbox-dialog">
                <!-- Bottone Chiudi -->
                <button type="button" class="dfn-lightbox-btn dfn-lightbox-close" aria-label="<?php esc_attr_e('Chiudi', 'dfn-theme'); ?>" title="<?php esc_attr_e('Chiudi (Esc)', 'dfn-theme'); ?>">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>

                <!-- Bottone Precedente -->
                <button type="button" class="dfn-lightbox-btn dfn-lightbox-prev" aria-label="<?php esc_attr_e('Elemento precedente', 'dfn-theme'); ?>" title="<?php esc_attr_e('Elemento precedente (Freccia sinistra)', 'dfn-theme'); ?>">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>

                <!-- Bottone Successiva -->
                <button type="button" class="dfn-lightbox-btn dfn-lightbox-next" aria-label="<?php esc_attr_e('Elemento successivo', 'dfn-theme'); ?>" title="<?php esc_attr_e('Elemento successivo (Freccia destra)', 'dfn-theme'); ?>">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>

                <!-- Area Immagine / Video e Spinner di Caricamento -->
                <div class="dfn-lightbox-content">
                    <div class="dfn-lightbox-loader">
                        <div class="dfn-spinner"></div>
                    </div>
                    <img src="" alt="" class="dfn-lightbox-image" />
                    <video controls playsinline class="dfn-lightbox-video" style="display:none;"></video>
                </div>

                <!-- Footer: Contatore e Didascalia -->
                <div class="dfn-lightbox-footer">
                    <div class="dfn-lightbox-counter">
                        <span class="dfn-current-index">1</span> / <span class="dfn-total-count"><?php echo count($items); ?></span>
                    </div>
                    <div class="dfn-lightbox-caption"></div>
                </div>
            </div>
        </div>
    </section>
    <?php
    $html = ob_get_clean();

    if (! $bypass_cache && ! empty($html)) {
        set_transient($cache_key, $html, 12 * HOUR_IN_SECONDS);
    }

    return $html;
}

/**
 * Invalida la cache transient della galleria post-evento quando un prodotto/evento viene aggiornato.
 *
 * @param int $post_id ID del post/prodotto aggiornato.
 * @return void
 */
function dfn_clear_post_event_gallery_cache($post_id): void
{
    if ($post_id) {
        delete_transient('dfn_post_gallery_' . (int) $post_id);
    }
}
add_action('save_post_product', 'dfn_clear_post_event_gallery_cache');
add_action('clean_post_cache', 'dfn_clear_post_event_gallery_cache');

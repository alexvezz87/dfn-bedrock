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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'dfn_evento', 'dfn_render_evento_shortcode' );
// Mantieni l'alias legacy per retrocompatibilità
add_shortcode( 'prodotto_condizionale', 'dfn_render_evento_shortcode' );

/**
 * Rende la scheda dell'evento FAI con il selettore dei turni orari.
 *
 * @param array $atts Attributi dello shortcode.
 * @return string HTML generato.
 */
function dfn_render_evento_shortcode( $atts ): string {
    $atts = shortcode_atts( array(
        'id' => 0,
    ), $atts, 'dfn_evento' );

    $product_id = intval( $atts['id'] );
    if ( $product_id <= 0 ) {
        return '<p class="dfn-error-msg">' . esc_html__( 'ID Prodotto non valido.', 'dfn-theme' ) . '</p>';
    }

    $product = wc_get_product( $product_id );
    if ( ! $product ) {
        return '<p class="dfn-error-msg">' . esc_html__( 'Prodotto non trovato.', 'dfn-theme' ) . '</p>';
    }

    // Cerca se esiste un evento associato a questo prodotto
    $event = dfn_db_get_event_by_product( $product_id );
    if ( ! $event ) {
        // Fallback al comportamento standard di acquisto WooCommerce se non c'è una scheda evento
        return prodotto_condizionale_shortcode( array( 'id' => $product_id ) );
    }

    // Se l'evento è in stato 'private' (Gestione Interna), mostralo solo a chi ha i privilegi di amministrazione/gestione
    if ( 'private' === $event->status && ! current_user_can( 'dfn_manage_events' ) ) {
        return '<p class="dfn-error-msg">' . esc_html__( 'Questo evento è privato ed accessibile solo ad uso interno.', 'dfn-theme' ) . '</p>';
    }

    $stock       = $product->get_stock_quantity();
    $is_in_stock = $product->is_in_stock();
    $price_standard_html = wc_price( floatval( $event->price_standard ) );
    $price_fai_html      = wc_price( floatval( $event->price_fai ) );
    $image       = $product->get_image( 'large' );

    ob_start();
    ?>
    <div class="dfn-booking-widget" 
         data-event-id="<?php echo esc_attr( (string) $event->id ); ?>" 
         data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
         data-access-type="<?php echo esc_attr( $event->access_type ); ?>"
         data-allocation-mode="<?php echo esc_attr( $event->allocation_mode ); ?>">
        
        <div class="dfn-booking-title">
            <span class="dashicons dashicons-calendar-alt"></span>
            <?php echo esc_html( $product->get_name() ); ?>
        </div>

        <?php if ( $image ) : ?>
            <div class="dfn-booking-image-wrapper" style="margin-bottom: 20px; border-radius: 8px; overflow: hidden; text-align: center;">
                <?php echo wp_kses_post( $image ); ?>
            </div>
        <?php endif; ?>

        <div class="dfn-booking-section" style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #e2e8f0;">
            <div style="font-size:13px; color:#64748b; font-weight:600; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.5px;">📍 <?php esc_html_e( 'Luogo ed Orario', 'dfn-theme' ); ?></div>
            <div style="font-size:15px; font-weight:700; color:#004b23; margin-bottom:6px;"><?php echo esc_html( $event->location ); ?></div>
            <div style="font-size:13px; color:#475569;">
                📅 <?php echo esc_html( date_i18n( 'd F Y', strtotime( $event->event_date_start ) ) ); ?>
                <?php if ( $event->event_date_end && $event->event_date_end !== $event->event_date_start ) : ?>
                    - <?php echo esc_html( date_i18n( 'd F Y', strtotime( $event->event_date_end ) ) ); ?>
                <?php endif; ?>
                &nbsp;|&nbsp; ⏰ <?php echo esc_html( date( 'H:i', strtotime( $event->event_time_start ) ) ); ?>
            </div>
        </div>

        <div class="dfn-booking-section">
            <span class="dfn-widget-label"><?php esc_html_e( 'Tariffe Contributo', 'dfn-theme' ); ?></span>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px;">
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:12px; text-align:center;">
                    <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; margin-bottom:4px;"><?php esc_html_e( 'Contributo Standard', 'dfn-theme' ); ?></div>
                    <div style="font-size:20px; font-weight:800; color:#1e293b;"><?php echo wp_kses_post( $price_standard_html ); ?></div>
                </div>
                <div style="background:#fffdf5; border:1px solid #c69c3a; border-radius:8px; padding:12px; text-align:center;">
                    <div style="font-size:11px; font-weight:700; color:#c69c3a; text-transform:uppercase; margin-bottom:4px;">🍊 <?php esc_html_e( 'Soci FAI', 'dfn-theme' ); ?></div>
                    <div style="font-size:20px; font-weight:800; color:#004b23;"><?php echo wp_kses_post( $price_fai_html ); ?></div>
                </div>
            </div>
        </div>

        <?php if ( ! $is_in_stock || ( $stock !== null && $stock <= 0 ) ) : ?>
            <div style="text-align:center; background:#fee2e2; color:#991b1b; padding:16px; border-radius:8px; font-weight:700; font-size:16px; margin-top:20px; border:1px solid #fecaca;">
                ❌ <?php esc_html_e( 'POSTI COMPLETAMENTE ESAURITI PER QUESTO EVENTO', 'dfn-theme' ); ?>
            </div>
        <?php else : ?>
            <form class="dfn-booking-form" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
                <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( (string) $product_id ); ?>">
                <input type="hidden" name="dfn_booking_slot_id" value="">

                <!-- Sezione Biglietti / Quantità -->
                <div class="dfn-booking-section">
                    <span class="dfn-widget-label"><?php esc_html_e( 'Seleziona Partecipanti', 'dfn-theme' ); ?></span>
                    <div class="dfn-qty-grid">
                        <div class="dfn-qty-box">
                            <label for="quantity"><?php esc_html_e( 'Biglietti Standard', 'dfn-theme' ); ?></label>
                            <input type="number" name="quantity" id="quantity" min="0" value="1">
                        </div>
                        <div class="dfn-qty-box">
                            <label for="dfn_qty_fai">🍊 <?php esc_html_e( 'Biglietti Soci FAI', 'dfn-theme' ); ?></label>
                            <input type="number" name="dfn_qty_fai" id="dfn_qty_fai" min="0" value="0">
                        </div>
                    </div>
                </div>

                <!-- Calendario / Data della prenotazione -->
                <div class="dfn-booking-section">
                    <label for="dfn_booking_date" class="dfn-widget-label"><?php esc_html_e( 'Seleziona Giorno', 'dfn-theme' ); ?></label>
                    <div class="dfn-date-input-wrapper">
                        <input type="date" 
                               name="dfn_booking_date" 
                               id="dfn_booking_date" 
                               class="dfn-date-input" 
                               min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
                               value="<?php echo esc_attr( $event->event_date_start ); ?>">
                    </div>
                </div>

                <!-- Selettore Slot Orario (Caricato via AJAX) -->
                <?php if ( 'time_slots' === $event->access_type ) : ?>
                    <div class="dfn-booking-section">
                        <span class="dfn-widget-label"><?php esc_html_e( 'Seleziona Turno', 'dfn-theme' ); ?></span>
                        <div class="dfn-slots-container">
                            <!-- Popolato da JS -->
                        </div>
                    </div>
                <?php endif; ?>

                <div class="dfn-widget-feedback"></div>

                <button type="submit" class="dfn-widget-submit" disabled>
                    <span class="dashicons dashicons-cart"></span>
                    <?php esc_html_e( 'Procedi alla Prenotazione', 'dfn-theme' ); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

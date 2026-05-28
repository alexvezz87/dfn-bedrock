<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * SHORTCODES: PRODOTTO SINGOLO E BOLLA FLUTTUANTE
 * ========================================================================
 */

// 1. SHORTCODE ACQUISTO (PALLINO VERDE)
add_shortcode( 'prodotto_condizionale', 'prodotto_condizionale_shortcode' );
function prodotto_condizionale_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'prodotto_condizionale' );
    $product_id = intval( $atts['id'] );

    if ( $product_id <= 0 ) return '<p style="color:red;">Inserisci un ID prodotto valido.</p>';
    $product = wc_get_product( $product_id );
    if ( ! $product ) return '<p style="color:red;">Prodotto non trovato.</p>';

    $stock       = $product->get_stock_quantity();
    $is_in_stock = $product->is_in_stock();
    $price       = $product->get_price_html();
    $image       = $product->get_image( 'medium' );
    
    $show_viewers = false; $viewers = 0;
    if ( $product_id === CV_EVENT_ID_STANDARD && $stock > 0 ) {
        $ora_attuale = (int) wp_date( 'H' );
        if ( $ora_attuale >= 8 && $ora_attuale < 22 ) { $show_viewers = true; $viewers = 12 + (intdiv((int)date('i'), 10) * 3) + ($stock % 10); }
    }

    ob_start();
    ?>
    <style>
        .custom-product form.cart { display: flex; flex-direction: row; justify-content: center; align-items: center; gap: 15px; flex-wrap: wrap; }
        .custom-product form.cart .quantity { margin: 0; }
        .custom-product form.cart .quantity input.qty { width: 80px !important; min-width: 80px !important; height: 42px; padding: 5px 10px; text-align: center; border: 1px solid #ccc; border-radius: 4px; }
        .cv-fomo-social { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px 15px; border-radius: 5px; margin: 15px 0 10px 0; font-size: 14px; line-height: 1.4; }
        .cv-status-badge { color: #16a34a; font-weight: bold; font-size: 16px; display: flex; align-items: center; justify-content: center; margin-top: 10px; margin-bottom: 20px; }
        .cv-green-dot { display: inline-block; width: 12px; height: 12px; background-color: #16a34a; border-radius: 50%; margin-right: 8px; box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7); animation: cv-pulse-green 2s infinite; }
        .cv-stock-numeric { color: #16a34a; font-weight: bold; font-size: 16px; margin-top: 10px; margin-bottom: 20px; text-align: center; }
        @keyframes cv-pulse-green { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(22, 163, 74, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); } }
    </style>
    <div class="prodotti-custom-container" style="display: flex; flex-direction: column;">
        <div class="custom-product" style="display: flex; flex-direction: column; align-items: center; padding: 20px; border: 1px solid #eee; margin-bottom: 10px; margin: auto; background: rgba(255,255,255,0.7); max-width:400px;">
            <?php echo $image; ?>
            <div class="price" style="font-weight: 600; font-size: 24px; margin-top: 15px;"><?php echo wp_kses_post( $price ); ?></div>
            <?php if ( ! $is_in_stock || $stock <= 0 ) : ?>
                <div class="stock-status" style="color: #d63638; font-weight: bold; margin-top: 15px; margin-bottom: 15px; font-size:18px;">❌ POSTI ESAURITI</div>
            <?php else : ?>
                <?php if ( $show_viewers ) : ?>
                    <div class="cv-fomo-social">🔥 <strong>Molto richiesto!</strong><br><?php echo $viewers; ?> persone stanno valutando questo evento proprio ora.</div>
                <?php endif; ?>
                <?php if ( $product_id === CV_EVENT_ID_STANDARD ) : ?>
                    <div class="cv-status-badge"><span class="cv-green-dot"></span> Posti disponibili</div>
                <?php else : ?>
                    <div class="cv-stock-numeric"><?php echo esc_html( $stock ); ?> posti disponibili</div>
                <?php endif; ?>
                <form class="cart" method="post" action="<?php echo esc_url( wc_get_cart_url() ); ?>">
                    <input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product_id ); ?>">
                    <?php woocommerce_quantity_input( array(), $product, true ); ?>
                    <button type="submit" class="button alt">Aggiungi al carrello</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// 2. SHORTCODE BOLLA FLUTTUANTE (Mio Account)
add_shortcode( 'cv_login_biglietti', 'cv_render_login_biglietti_shortcode' );
function cv_render_login_biglietti_shortcode() {
    $account_url = wc_get_page_permalink( 'myaccount' );
    $icona_utente = '<svg class="cv-main-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>';
    $icona_ticket = '<svg class="cv-main-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"></path><path d="M9 9h.01"></path><path d="M15 9h.01"></path><path d="M12 9h.01"></path><path d="M9 15h.01"></path><path d="M15 15h.01"></path><path d="M12 15h.01"></path></svg>';

    $style = '<style>.cv-dynamic-login-btn { display: flex; align-items: center; justify-content: flex-start; position: relative; width: 60px; height: 60px; padding: 0; border-radius: 50px; background: #ffffff !important; color: #111111 !important; text-decoration: none !important; box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important; border: 1px solid #eaeaea !important; transition: width 0.8s cubic-bezier(0.19, 1, 0.22, 1), box-shadow 0.3s ease, background 0.3s ease; overflow: hidden; white-space: nowrap; cursor: pointer; z-index: 99990; will-change: width, box-shadow; } .cv-main-icon { width: 60px; height: 24px; flex-shrink: 0; color: #111111; transition: transform 0.3s ease; } .cv-dynamic-login-btn:hover .cv-main-icon { transform: scale(1.05); } .cv-btn-text { font-weight: 700; font-size: 15px; opacity: 0; visibility: hidden; transform: translateX(-15px); transition: opacity 0.5s ease 0.4s, transform 0.5s ease 0.4s; margin-left: -5px; } .cv-dynamic-login-btn:hover, .cv-dynamic-login-btn:active, .cv-dynamic-login-btn:focus { width: 220px; box-shadow: 0 10px 30px rgba(0,0,0,0.18) !important; background: #ffffff !important; } .cv-dynamic-login-btn.logged-out:hover { width: 220px; } .cv-dynamic-login-btn.logged-in:hover { width: 180px; } .cv-dynamic-login-btn:hover .cv-btn-text, .cv-dynamic-login-btn:active .cv-btn-text, .cv-dynamic-login-btn:focus .cv-btn-text { opacity: 1; visibility: visible; transform: translateX(0); } @keyframes cvStatePulseSoft { 0% { box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important; } 50% { box-shadow: 0 4px 25px var(--pulse-color) !important; } 100% { box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important; } } .cv-dynamic-login-btn.logged-out { --pulse-color: rgba(34, 113, 177, 0.25); animation: cvPremiumSlideIn 0.8s ease, cvStatePulseSoft 2.5s infinite 1s; } .cv-dynamic-login-btn.logged-out:hover { animation: none; box-shadow: 0 10px 30px rgba(34, 113, 177, 0.3) !important; } .cv-dynamic-login-btn.logged-in { --pulse-color: rgba(255, 102, 0, 0.25); animation: cvPremiumSlideIn 0.8s ease, cvStatePulseSoft 2.5s infinite 1s; } .cv-dynamic-login-btn.logged-in:hover { animation: none; box-shadow: 0 10px 30px rgba(255, 102, 0, 0.3) !important; } @media (max-width: 768px) { .cv-dynamic-login-btn { width: 50px; height: 50px; } .cv-main-icon { width: 50px; height: 20px; } .cv-dynamic-login-btn:active, .cv-dynamic-login-btn:focus { width: 190px; } .cv-dynamic-login-btn.logged-in:active, .cv-dynamic-login-btn.logged-in:focus { width: 165px; } }</style>';

    if ( is_user_logged_in() ) {
        $ordini_url = wc_get_endpoint_url( 'orders', '', $account_url );
        $bottone = '<a href="' . esc_url( $ordini_url ) . '" class="cv-dynamic-login-btn logged-in">' . $icona_ticket . '<span class="cv-btn-text">I Miei Biglietti</span></a>';
    } else {
        $bottone = '<a href="' . esc_url( $account_url ) . '" class="cv-dynamic-login-btn logged-out">' . $icona_utente . '<span class="cv-btn-text">Accedi / Registrati</span></a>';
    }
    return $style . $bottone;
}

// Inietta nel footer
add_action( 'wp_footer', 'cv_inietta_bottone_fluttuante' );
function cv_inietta_bottone_fluttuante() {
    if ( is_checkout() || is_cart() || is_account_page() ) return;
    echo '<div class="cv-floating-widget">' . do_shortcode( '[cv_login_biglietti]' ) . '</div>';
    echo '<style>.cv-floating-widget { position: fixed; top: 30px; right: 30px; z-index: 99990; animation: cvPremiumSlideIn 0.8s cubic-bezier(0.23, 1, 0.32, 1) both; will-change: transform, opacity; } @keyframes cvPremiumSlideIn { 0% { opacity: 0; transform: translate(30px, 30px) rotate(5deg) scale(0.9); } 100% { opacity: 1; transform: translate(0, 0) rotate(0) scale(1); } } @media (max-width: 768px) { .cv-floating-widget { top: 20px; right: 20px; } }</style>';
}

/**
 * ========================================================================
 * HOOK EMAIL WOOCOMMERCE: BOTTONI E TRACCIAMENTO
 * ========================================================================
 */
add_action( 'woocommerce_email_order_details', 'cv_aggiungi_link_hub_email', 5, 4 );
function cv_aggiungi_link_hub_email( $order, $sent_to_admin, $plain_text, $email ) {
    if ( $sent_to_admin || $plain_text || ! $order->has_status( array( 'processing', 'completed' ) ) ) return;

    $order_id = $order->get_id(); $tot_biglietti = 0;
    foreach ( $order->get_items() as $item ) { $tot_biglietti += $item->get_quantity(); }
    
    $hub_token = hash_hmac( 'sha256', $order->get_order_key() . '_hub', wp_salt('nonce') );
    $hub_url = site_url( '/?cv_hub=1&order_id=' . $order_id . '&token=' . $hub_token );

    echo '<div style="text-align:center; margin: 30px 0; padding: 20px; border: 2px dashed #e5e5e5; border-radius: 10px; background-color: #f9f9f9;"><h2 style="margin-top:0; color: #ff6600;">I tuoi Biglietti (' . $tot_biglietti . ')</h2><p style="font-size: 16px;">Clicca sul pulsante qui sotto per accedere al tuo Botteghino. Potrai <strong>stamparli su carta</strong> o <strong>inviarli singolarmente</strong> ai tuoi amici su WhatsApp.</p><a href="' . esc_url( $hub_url ) . '" style="background-color: #2271b1; color: white; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block; margin-top: 10px; font-size:16px;">🎟️ Gestisci e Stampa Biglietti</a></div>';
}

add_action( 'woocommerce_thankyou', 'cv_aggiungi_link_hub_thankyou', 10, 1 );
function cv_aggiungi_link_hub_thankyou( $order_id ) {
    if ( ! $order_id ) return;
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $tot_biglietti = 0; foreach ( $order->get_items() as $item ) { $tot_biglietti += $item->get_quantity(); }
    $hub_token = hash_hmac( 'sha256', $order->get_order_key() . '_hub', wp_salt('nonce') );
    $hub_url = site_url( '/?cv_hub=1&order_id=' . $order_id . '&token=' . $hub_token );

    echo '<div style="text-align:center; margin: 40px auto; padding: 30px; border: 2px dashed #e5e5e5; border-radius: 10px; background-color: #f9f9f9; max-width: 500px;"><h2 style="margin-top:0; color: #ff6600;">I tuoi Biglietti (' . $tot_biglietti . ')</h2><p style="font-size: 16px;">Accedi al tuo hub personale per <strong>stampare i QR Code</strong> o smistarli su WhatsApp.</p><a href="' . esc_url( $hub_url ) . '" style="background-color: #2271b1; color: white; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block; margin-top: 10px; font-size:18px;">🎟️ Gestisci e Stampa Biglietti</a></div>';
}

add_action( 'wp_footer', 'cv_cambia_titolo_thankyou_page' );
function cv_cambia_titolo_thankyou_page() {
    if ( is_wc_endpoint_url( 'order-received' ) ) {
        echo '<script>document.addEventListener("DOMContentLoaded", function() { var titolo = document.querySelector(".titolo-checkout"); if ( titolo ) { titolo.innerHTML = "Grazie del tuo ordine"; } });</script>';
    }
}

add_action( 'woocommerce_email_before_order_table', 'cv_aggiungi_bottone_pagamento_email', 10, 4 );
function cv_aggiungi_bottone_pagamento_email( $order, $sent_to_admin, $plain_text, $email ) {
    if ( $sent_to_admin || $plain_text || ! $order->needs_payment() ) return;
    if ( $email->id === 'customer_invoice' ) {
        $tracked_url = add_query_arg( 'cv_track_pay', '1', $order->get_checkout_payment_url() );
        echo '<div style="text-align: center; margin: 40px 0; padding: 25px; border: 2px solid #d63638; border-radius: 8px; background-color: #fffafb;"><h3 style="color: #d63638; margin-top: 0; font-size: 22px;">⚠️ I tuoi posti sono riservati temporaneamente</h3><p style="font-size: 16px; color: #333; margin-bottom: 25px;">Abbiamo bloccato i tuoi biglietti, ma questa prenotazione scadrà automaticamente tra <strong>24 ore</strong>. Se il pagamento non verrà finalizzato entro questo termine, l\'ordine verrà annullato in automatico e i posti torneranno liberi.</p><a href="' . esc_url( $tracked_url ) . '" style="background-color: #7016d9; color: #ffffff; padding: 18px 36px; font-size: 20px; font-weight: bold; text-decoration: none; border-radius: 8px; display: inline-block; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">💳 PAGA E CONFERMA I BIGLIETTI</a></div>';
    }
}
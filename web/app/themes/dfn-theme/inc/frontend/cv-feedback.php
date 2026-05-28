<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * FRONTEND: PAGINA DI RACCOLTA FEEDBACK (STELLINE E RECENSIONE)
 * ========================================================================
 */

add_action( 'template_redirect', 'cv_render_feedback_page' );
function cv_render_feedback_page() {
    // Intercettiamo l'URL univoco della recensione
    if ( isset( $_GET['cv_feedback'] ) && isset( $_GET['order_id'] ) && isset( $_GET['token'] ) ) {
        
        $order_id = intval( $_GET['order_id'] );
        $token = sanitize_text_field( $_GET['token'] );
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) wp_die( 'Ordine non trovato.' );
        
        // Verifica Sicurezza
        $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_feedback', wp_salt('nonce') );
        if ( ! hash_equals( $expected_token, $token ) ) wp_die( 'Link non valido o scaduto.', 'Errore di sicurezza' );

        $titolo_evento = '';
        foreach ( $order->get_items() as $item ) { $titolo_evento = $item->get_name(); break; }
        
        // Controllo: ha già votato?
        $voto_esistente = $order->get_meta( '_cv_event_rating' );
        
        // LOGICA DI SALVATAGGIO
        // SEC-08: Verifica CSRF tramite nonce WordPress
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST['cv_rating'] )
             && isset( $_POST['_cv_feedback_nonce'] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_cv_feedback_nonce'] ) ), 'cv_submit_feedback_' . $order_id )
        ) {
            if ( empty( $voto_esistente ) ) {
                $rating = intval( $_POST['cv_rating'] );
                $review = isset( $_POST['cv_review'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cv_review'] ) ) : '';
                
                if ( $rating >= 1 && $rating <= 5 ) {
                    $order->update_meta_data( '_cv_event_rating', $rating );
                    $order->update_meta_data( '_cv_event_review', $review );
                    $order->update_meta_data( '_cv_event_rating_date', current_time( 'mysql' ) );
                    $order->save();
                    $voto_esistente = $rating;
                }
            }
        }

        // --- INTERFACCIA GRAFICA ---
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Lascia una Recensione</title>';
        echo '<style>
            body { font-family:sans-serif; background:#f0f0f1; padding:20px; display:flex; justify-content:center; align-items:center; min-height:80vh; margin:0; }
            .feedback-card { background:#fff; padding:40px; border-radius:15px; text-align:center; max-width:500px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.1); }
            h1 { color:#2271b1; margin-top:0; font-size:26px; }
            p { color:#555; font-size:16px; line-height:1.5; }
            .stars { display: flex; justify-content: center; flex-direction: row-reverse; gap: 10px; margin: 30px 0; }
            .stars input { display: none; }
            .stars label { cursor: pointer; width: 40px; height: 40px; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23ccc\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3e%3cpolygon points=\'12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\'/%3e%3c/svg%3e"); background-repeat: no-repeat; transition: transform 0.2s; }
            .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'%23f59e0b\' stroke=\'%23f59e0b\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3e%3cpolygon points=\'12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2\'/%3e%3c/svg%3e"); transform: scale(1.1); }
            textarea { width: 100%; height: 120px; padding: 15px; border: 1px solid #ccc; border-radius: 8px; resize: none; font-family: inherit; font-size: 15px; box-sizing: border-box; margin-bottom: 20px; }
            textarea:focus { outline: none; border-color: #2271b1; box-shadow: 0 0 0 2px rgba(34,113,177,0.2); }
            .btn { background: #2271b1; color: #fff; padding: 15px 30px; border: none; border-radius: 8px; font-size: 18px; font-weight: bold; cursor: pointer; width: 100%; transition: background 0.3s; }
            .btn:hover { background: #135e96; }
            .btn:disabled { background: #ccc; cursor: not-allowed; }
            .success-box { background: #eaf7ea; color: #166534; padding: 20px; border-radius: 10px; border: 2px solid #c3e6c3; margin-top: 20px; }
        </style></head><body>';
        
        echo '<div class="feedback-card">';
        
        if ( ! empty($voto_esistente) ) {
            echo '<h1>Grazie di cuore! 💛</h1>';
            echo '<div class="success-box">';
            echo '<h2 style="margin:0 0 10px 0;">Hai valutato l\'evento con ' . $voto_esistente . ' stelle.</h2>';
            echo '<p style="margin:0;">Il tuo feedback è prezioso e ci aiuta a migliorare sempre di più le nostre iniziative.</p>';
            echo '</div>';
            echo '<a href="' . site_url() . '" style="display:inline-block; margin-top:30px; color:#2271b1; text-decoration:none; font-weight:bold;">Torna al sito</a>';
        } else {
            echo '<h1>Com\'è andata?</h1>';
            echo '<p>Hai partecipato a <strong>' . esc_html($titolo_evento) . '</strong>.<br>Ci piacerebbe tantissimo sapere cosa ne pensi!</p>';
            
            echo '<form method="POST" action="">';
            // SEC-08: Protezione CSRF
            wp_nonce_field( 'cv_submit_feedback_' . $order_id, '_cv_feedback_nonce' );
            echo '<div class="stars">';
            echo '<input type="radio" id="star5" name="cv_rating" value="5" required><label for="star5" title="5 stelle"></label>';
            echo '<input type="radio" id="star4" name="cv_rating" value="4"><label for="star4" title="4 stelle"></label>';
            echo '<input type="radio" id="star3" name="cv_rating" value="3"><label for="star3" title="3 stelle"></label>';
            echo '<input type="radio" id="star2" name="cv_rating" value="2"><label for="star2" title="2 stelle"></label>';
            echo '<input type="radio" id="star1" name="cv_rating" value="1"><label for="star1" title="1 stella"></label>';
            echo '</div>';
            
            echo '<textarea name="cv_review" placeholder="Lasciaci un commento o un suggerimento per i prossimi eventi... (Opzionale)"></textarea>';
            
            echo '<button type="submit" class="btn" id="submit-btn" disabled>Invia Recensione</button>';
            echo '</form>';
            
            echo '<script>
                document.querySelectorAll("input[name=\'cv_rating\']").forEach(function(radio) {
                    radio.addEventListener("change", function() {
                        document.getElementById("submit-btn").disabled = false;
                    });
                });
            </script>';
        }
        
        echo '</div></body></html>';
        exit;
    }
}
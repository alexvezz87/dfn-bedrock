<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * 9. BOTTEGHINO LIVE E GENERATORE RAPIDO FAI
 */

add_action( 'admin_menu', 'cv_aggiungi_generatore_fai' );
function cv_aggiungi_generatore_fai() {
    $hook = add_submenu_page(
        'woocommerce',
        'Botteghino Live',
        '🎟️ Botteghino Live',
        'manage_woocommerce',
        'cv-generatore-fai',
        'cv_render_generatore_fai'
    );
    
    // Inietta gli script e gli stili personalizzati solo in questa pagina
    add_action( "admin_enqueue_scripts", 'cv_enqueue_botteghino_assets' );
}

function cv_enqueue_botteghino_assets( $hook ) {
    if ( $hook !== 'woocommerce_page_cv-generatore-fai' ) {
        return;
    }

    // Dipendenze Select2 native di WooCommerce
    wp_enqueue_script( 'selectWoo' );
    wp_enqueue_style( 'select2' );

    // I nostri file separati
    wp_enqueue_style( 'cv-botteghino-css', get_stylesheet_directory_uri() . '/assets/css/cv-botteghino.css', array(), '1.0' );
    wp_enqueue_script( 'cv-botteghino-js', get_stylesheet_directory_uri() . '/assets/js/cv-botteghino.js', array('jquery', 'selectWoo'), '1.0', true );

    // Passiamo le variabili PHP al JS in modo sicuro
    wp_localize_script( 'cv-botteghino-js', 'cvBotteghinoVars', array(
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'cv_ricerca_clienti_nonce' )
    ) );
}

// INTERFACCIA E LOGICA DI SALVATAGGIO
function cv_render_generatore_fai() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $messaggio = '';
    $valore_sconto_singolo = CV_FAI_SCONTO_UNITARIO;

    if ( isset( $_POST['cv_genera_fai_nonce'] ) && wp_verify_nonce( $_POST['cv_genera_fai_nonce'], 'cv_genera_fai' ) ) {
        
        $payment_method = isset( $_POST['fai_metodo_pagamento'] ) ? sanitize_text_field( $_POST['fai_metodo_pagamento'] ) : 'link';
        $prodotto_id    = intval( $_POST['fai_prodotto'] );
        $quantita       = intval( $_POST['fai_qty'] );
        
        // Selezioniamo dati fittizi se è Autorità, altrimenti prendiamo quelli del form
        if ( $payment_method === 'autorita' ) {
            $nome         = 'Riserva';
            $cognome      = 'Autorità';
            $email        = 'autorita_' . time() . '@fainovara.local';
            $telefono     = 'CERIMONIALE';
            $tessere      = 0;
            $auto_checkin = true; // Le autorità saltano la porta
            $customer_id  = 0;
        } else {
            $nome         = sanitize_text_field( $_POST['fai_nome'] );
            $cognome      = sanitize_text_field( $_POST['fai_cognome'] );
            $email        = sanitize_email( $_POST['fai_email'] );
            $telefono     = sanitize_text_field( $_POST['fai_telefono'] );
            $tessere      = isset( $_POST['fai_tessere'] ) ? intval( $_POST['fai_tessere'] ) : 0;
            $auto_checkin = isset( $_POST['fai_auto_checkin'] ) ? true : false;
            $customer_id_raw = isset( $_POST['fai_customer_id'] ) ? sanitize_text_field( $_POST['fai_customer_id'] ) : '';
            $customer_id     = is_numeric( $customer_id_raw ) ? intval( $customer_id_raw ) : 0;
        }

        if ( $prodotto_id === CV_EVENT_ID_STANDARD ) $tessere = 0;

        if ( is_email( $email ) && $prodotto_id > 0 && $quantita > 0 ) {
            
            $product = wc_get_product( $prodotto_id );
            
            $is_overbooking = false;
            if ( $product->managing_stock() && $product->get_stock_quantity() < $quantita ) {
                $is_overbooking = true;
            }
            
            if ( $tessere > $quantita ) $tessere = $quantita;
            
            $order = wc_create_order();
            if ( $customer_id > 0 ) $order->set_customer_id( $customer_id );
            
            $order->set_billing_first_name( $nome );
            $order->set_billing_last_name( $cognome );
            $order->set_billing_email( $email );
            $order->set_billing_phone( $telefono ); 
            $order->add_product( $product, $quantita );

            // Gestione Sconti o gratuità Autorità
            if ( $payment_method === 'autorita' ) {
                $order->update_meta_data( '_cv_is_authority', 'yes' );
                $total_price = $product->get_price() * $quantita;
                $fee = new WC_Order_Item_Fee();
                $fee->set_name( 'Riserva Posti Autorità (Omaggio)' );
                $fee->set_amount( -$total_price );
                $fee->set_total( -$total_price );
                $order->add_item( $fee );
            } elseif ( $tessere > 0 ) {
                $sconto_totale = $tessere * $valore_sconto_singolo;
                $fee = new WC_Order_Item_Fee();
                $fee->set_name( 'Sconto Tessera FAI (' . $tessere . ' validat' . ($tessere == 1 ? 'a' : 'e') . ')' );
                $fee->set_amount( -$sconto_totale );
                $fee->set_total( -$sconto_totale );
                $order->add_item( $fee );
            }

            $order->calculate_totals();
            
            // Smistamento flussi di pagamento e completamento
            if ( $payment_method === 'contanti' || $payment_method === 'autorita' ) {
                $order->set_payment_method( 'cod' ); 
                $order->set_payment_method_title( $payment_method === 'autorita' ? 'Cerimoniale Autorità' : 'Contanti in Loco (Botteghino)' );
                $order->update_status( 'completed', 'Operazione registrata dal botteghino.' );
                wc_reduce_stock_levels( $order->get_id() );
                
                // LOGICA CHECK-IN IMMEDIATO
                if ( $auto_checkin ) {
                    for ( $i = 1; $i <= $quantita; $i++ ) {
                        $order->update_meta_data( '_cv_ticket_validato_' . $i, 'yes' );
                        $order->update_meta_data( '_cv_ticket_validato_' . $i . '_orario', current_time( 'mysql' ) );
                        $order->update_meta_data( '_cv_ticket_validato_' . $i . '_operatore', get_current_user_id() );
                    }
                    $order->add_order_note( '✅ Check-in immediato eseguito dalla cassa.' );
                    
                    if($payment_method === 'autorita') {
                        $messaggio_esito = '🎁 Ordine generato con successo. I posti sono stati <strong>RISERVATI PER LE AUTORITÀ</strong> e scalati dalle scorte.';
                    } else {
                        $messaggio_esito = '✅ Ordine incassato in <strong>CONTANTI</strong> e <strong style="color:#16a34a;">BIGLIETTI VALIDATI PER L\'INGRESSO</strong>.';
                    }
                } else {
                    /** @var \WC_Email_Customer_Completed_Order|null $email_completed */
                    $email_completed = WC()->mailer()->get_emails()['WC_Email_Customer_Completed_Order'] ?? null;
                    if ( $email_completed ) {
                        $email_completed->trigger( $order->get_id() );
                    }
                    $order->add_order_note( '📧 Inviata mail con i BIGLIETTI (Pagamento in contanti).' );
                    $messaggio_esito = '✅ Ordine incassato in <strong>CONTANTI</strong>. I biglietti sono stati inviati a <strong>' . esc_html( $email ) . '</strong>.';
                }
                
                $colore_esito = 'notice-success';
                
            } else {
                $order->update_status( 'pending', 'Ordine generato dal Botteghino. In attesa di pagamento tramite link.' );
                wc_reduce_stock_levels( $order->get_id() );
                /** @var \WC_Email_Customer_Invoice|null $email_invoice */
                $email_invoice = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'] ?? null;
                if ( $email_invoice ) {
                    $email_invoice->trigger( $order->get_id() );
                }
                $order->add_order_note( '📧 L\'email con il link di pagamento elettronico è stata inviata a ' . esc_html( $email ) );
                $messaggio_esito = '💳 Ordine creato. Il link di pagamento è stato inviato a <strong>' . esc_html( $email ) . '</strong>.';
                $colore_esito = 'notice-info';
            }

            if ( $is_overbooking ) {
                $order->add_order_note( '⚠️ FORZATURA SCORTE: L\'operatore del botteghino ha forzato l\'operazione superando i posti disponibili.' );
                $messaggio_esito .= '<br><br><strong style="color:#d63638;">⚠️ NOTA OVERBOOKING:</strong> Hai riservato più biglietti dei posti rimasti in magazzino.';
            }

            $order->save();

            $edit_url = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
            $sconto_msg = $tessere > 0 ? " (Sconto {$tessere} tessere FAI)" : "";
            
            $messaggio = '<div class="notice ' . $colore_esito . ' is-dismissible" style="padding:15px; font-size:16px;">' . $messaggio_esito . $sconto_msg . '<br><br><a href="' . esc_url( $edit_url ) . '">🔍 Clicca qui per vedere l\'ordine #' . $order->get_id() . '</a></div>';
            
        } else {
            $messaggio = '<div class="notice notice-error is-dismissible"><p>❌ Errore: Controlla email ed evento.</p></div>';
        }
    }

    $args = array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' );
    $products = wc_get_products( $args );

    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Botteghino Live e Generatore Ordini</h1>';
    echo '<p style="font-size:16px; color:#555;">Crea l\'ordine ed emetti i biglietti istantaneamente (Contanti), invia il link di pagamento (Carte) o riserva i posti per le Autorità.</p>';
    
    // SEC-03: Late escaping per difesa a strati sull'output HTML
    echo wp_kses_post( $messaggio );

    // Qui ora la parte HTML è completamente pulita, senza <style> e <script>!

    echo '<div class="cv-fai-container">';
    echo '<form id="cv-botteghino-form" method="POST" action="">';
    wp_nonce_field( 'cv_genera_fai', 'cv_genera_fai_nonce' );
    echo '<input type="hidden" name="fai_metodo_pagamento" id="fai_metodo_pagamento" value="link">';

    echo '<div class="cv-form-row" style="background:#f0f6fc; padding:15px; border-radius:6px; border:1px solid #c8d7e1;">';
    echo '<label for="fai_customer_search">🔍 Cerca Cliente Esistente (Opzionale)</label>';
    echo '<select id="fai_customer_search" name="fai_customer_id" data-placeholder="Digita nome o email..."><option value=""></option></select>';
    echo '</div>';

    echo '<div style="display: flex; gap: 15px;">';
    echo '<div class="cv-form-row" style="flex: 1;"><label for="fai_nome">Nome</label><input type="text" name="fai_nome" id="fai_nome" placeholder="Es. Mario" required></div>';
    echo '<div class="cv-form-row" style="flex: 1;"><label for="fai_cognome">Cognome</label><input type="text" name="fai_cognome" id="fai_cognome" placeholder="Es. Rossi"></div>';
    echo '</div>';
    
    echo '<div class="cv-form-row">';
    echo '<label for="fai_email" style="display:flex; justify-content:space-between;"><span>Email *</span> <a href="#" id="cv-btn-no-email" style="font-size:12px; color:#d63638; text-decoration:none; border-bottom:1px dashed #d63638;">Il cliente non ha email?</a></label>';
    echo '<input type="email" name="fai_email" id="fai_email" required placeholder="mario.rossi@email.it">';
    echo '</div>';
    
    echo '<div class="cv-form-row"><label for="fai_telefono">Telefono</label><input type="tel" name="fai_telefono" id="fai_telefono" placeholder="Es. 3331234567"></div>';

    echo '<div class="cv-form-row">';
    echo '<label for="fai_prodotto" style="display:flex; justify-content:space-between;"><span>Evento *</span> <span id="cv-stock-indicator" style="color:#d63638; display:none; background:#fff3cd; padding:2px 8px; border-radius:4px; font-size:12px;"></span></label>';
    echo '<select name="fai_prodotto" id="fai_prodotto" required><option value="" data-stock="">-- Seleziona l\'evento --</option>';
    foreach ( $products as $product ) { 
        $stock = $product->managing_stock() ? $product->get_stock_quantity() : 9999;
        echo '<option value="' . esc_attr( $product->get_id() ) . '" data-stock="' . esc_attr($stock) . '">' . esc_html( $product->get_name() ) . ' (' . wp_strip_all_tags($product->get_price_html()) . ')</option>'; 
    }
    echo '</select></div>';

    echo '<div style="display: flex; gap: 15px; align-items: flex-start;">';
    echo '<div class="cv-form-row" style="flex: 1;"><label for="fai_qty">Biglietti Totali</label><input type="number" name="fai_qty" id="fai_qty" value="1" min="1" step="1" data-stock-reale="" style="font-size:20px; font-weight:bold;"></div>';
    echo '<div class="cv-form-row" id="cv-fai-discount-wrapper" style="flex: 1;"><label for="fai_tessere">Tessere FAI</label><input type="number" name="fai_tessere" id="fai_tessere" value="0" min="0" step="1" style="font-size:20px;"></div>';
    echo '</div>';

    echo '<div class="cv-form-row" id="cv-auto-checkin-wrapper" style="background:#eaf7ea; padding:15px; border-radius:6px; border:1px solid #c3e6c3;">';
    echo '<label style="margin:0; display:flex; align-items:center; cursor:pointer; color:#166534; font-size:15px;">';
    echo '<input type="checkbox" name="fai_auto_checkin" id="fai_auto_checkin" value="1" style="margin-right:10px; width:20px; height:20px;">';
    echo '✅ Salta fila: Valida automaticamente i biglietti</label>';
    echo '<p class="description" style="margin-left:30px; color:#166534;">Spunta questa casella <strong>SOLO</strong> se il cliente entra in questo esatto momento (es. gli dai il braccialetto cartaceo). Se entra in un secondo momento, lascia deselezionato.</p>';
    echo '</div>';

    echo '<div class="cv-pos-buttons">';
    echo '<button type="button" id="cv-btn-submit-link" class="cv-pos-btn cv-btn-link"><span style="font-size:24px;">💳</span>Invia Link di<br>Pagamento (Carta)</button>';
    echo '<button type="button" id="cv-btn-submit-cash" class="cv-pos-btn cv-btn-cash"><span style="font-size:24px;">💵</span>Incassa in Contanti<br>ed Emetti Biglietti</button>';
    echo '<button type="button" id="cv-btn-submit-auth" class="cv-pos-btn cv-btn-auth"><span style="font-size:24px;">🎁</span>Riserva Posti<br>Autorità (Omaggio)</button>';
    echo '</div>';
    
    echo '</form></div></div>';
}
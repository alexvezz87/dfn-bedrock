<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * GESTIONE MANUALE LISTE DI ATTESA (BACKEND)
 * Crea ordini pendenti per chi subentra ai ritardatari.
 * ========================================================================
 */

add_action( 'admin_menu', 'cv_aggiungi_pagina_liste_attesa' );
function cv_aggiungi_pagina_liste_attesa() {
    add_submenu_page(
        'woocommerce',
        'Liste di Attesa',
        '⏳ Liste di Attesa',
        'manage_woocommerce',
        'cv-liste-attesa',
        'cv_render_pagina_liste_attesa'
    );
}

function cv_render_pagina_liste_attesa() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $messaggio = '';
    $selected_event = isset( $_REQUEST['event_id'] ) ? intval( $_REQUEST['event_id'] ) : 0;
    $valore_sconto_singolo = CV_FAI_SCONTO_UNITARIO;

    // --- LOGICA DI SALVATAGGIO NUOVO UTENTE IN CODA E INVIO MAIL ---
    if ( isset( $_POST['cv_add_waitlist_nonce'] ) && wp_verify_nonce( $_POST['cv_add_waitlist_nonce'], 'cv_add_waitlist' ) ) {
        $nome    = sanitize_text_field( $_POST['wl_nome'] );
        $cognome = sanitize_text_field( $_POST['wl_cognome'] );
        $email   = sanitize_email( $_POST['wl_email'] );
        $tel     = sanitize_text_field( $_POST['wl_tel'] );
        $qty     = intval( $_POST['wl_qty'] );
        $tessere = isset( $_POST['wl_tessere'] ) ? intval( $_POST['wl_tessere'] ) : 0;

        if ( is_email( $email ) && $selected_event > 0 && $qty > 0 ) {
            if ( $tessere > $qty ) $tessere = $qty;

            $waitlist = get_option( 'cv_waitlist_data', array() );
            if ( ! isset( $waitlist[ $selected_event ] ) ) $waitlist[ $selected_event ] = array();
            
            $waitlist[ $selected_event ][] = array(
                'id'      => uniqid('wl_'),
                'nome'    => $nome,
                'cognome' => $cognome,
                'email'   => $email,
                'tel'     => $tel,
                'qty'     => $qty,
                'tessere' => $tessere,
                'date'    => current_time( 'mysql' )
            );
            
            update_option( 'cv_waitlist_data', $waitlist );

            // INVIO MAIL
            $product = wc_get_product( $selected_event );
            $nome_evento = $product ? $product->get_name() : 'Evento';

            ob_start();
            wc_get_template( 'emails/email-header.php', array( 'email_heading' => 'Sei in Lista d\'Attesa' ) );

            echo '<p>Ciao <strong>' . esc_html( $nome ) . '</strong>,</p>';
            echo '<p>Ti confermiamo di aver preso in carico la tua richiesta. Sei stato inserito ufficialmente nella lista d\'attesa per l\'evento <strong>' . esc_html( $nome_evento ) . '</strong>.</p>';
            echo '<p>Hai richiesto <strong>' . intval( $qty ) . ' bigliett' . ($qty == 1 ? 'o' : 'i') . '</strong>.</p>';
            
            echo '<h2 style="color: #2271b1; font-size: 20px; margin-top: 30px;">Cosa succede ora?</h2>';
            echo '<p>Nel caso in cui dovessero liberarsi i posti da te richiesti, ti invieremo immediatamente un\'altra email contenente il link per effettuare il pagamento.</p>';
            echo '<p style="color: #d63638; font-size: 16px; font-weight: bold; border-left: 4px solid #d63638; padding-left: 10px; margin-top: 20px;">Attenzione: dal momento in cui riceverai l\'eventuale link di pagamento, avrai un massimo di 24 ore di tempo per completare l\'acquisto, dopodiché i posti verranno riassegnati alla persona successiva in lista.</p>';
            echo '<p style="margin-top: 40px;">A presto!<br><em>Lo Staff della Delegazione FAI Novara</em></p>';

            wc_get_template( 'emails/email-footer.php' );
            $message = ob_get_clean();

            WC()->mailer()->send( $email, 'Conferma inserimento in Lista d\'Attesa - ' . $nome_evento, $message, array( 'Content-Type: text/html' ) );

            $messaggio = '<div class="notice notice-success is-dismissible"><p>✅ <strong>' . esc_html($nome . ' ' . $cognome) . '</strong> aggiunto correttamente alla lista d\'attesa e <strong>mail di conferma inviata!</strong></p></div>';
        }
    }

    // --- LOGICA DI RIMOZIONE O PROMOZIONE A ORDINE ---
    if ( isset( $_POST['cv_action_waitlist_nonce'] ) && wp_verify_nonce( $_POST['cv_action_waitlist_nonce'], 'cv_action_waitlist' ) ) {
        $wl_id_to_action = sanitize_text_field( $_POST['wl_id'] );
        $action_type     = sanitize_text_field( $_POST['wl_action'] );
        
        $waitlist = get_option( 'cv_waitlist_data', array() );
        
        if ( isset( $waitlist[ $selected_event ] ) ) {
            foreach ( $waitlist[ $selected_event ] as $index => $entry ) {
                if ( $entry['id'] === $wl_id_to_action ) {
                    
                    if ( $action_type === 'delete' ) {
                        unset( $waitlist[ $selected_event ][ $index ] );
                        $waitlist[ $selected_event ] = array_values( $waitlist[ $selected_event ] );
                        update_option( 'cv_waitlist_data', $waitlist );
                        $messaggio = '<div class="notice notice-warning is-dismissible"><p>🗑️ Utente rimosso dalla lista d\'attesa.</p></div>';
                    } 
                    elseif ( $action_type === 'promote' ) {
                        $order = wc_create_order();
                        $order->set_billing_first_name( $entry['nome'] );
                        $order->set_billing_last_name( $entry['cognome'] );
                        $order->set_billing_email( $entry['email'] );
                        $order->set_billing_phone( $entry['tel'] ); 
                        
                        $product = wc_get_product( $selected_event );
                        $order->add_product( $product, $entry['qty'] );

                        $tessere_fai = isset( $entry['tessere'] ) ? intval( $entry['tessere'] ) : 0;
                        if ( $tessere_fai > 0 ) {
                            $sconto_totale = $tessere_fai * $valore_sconto_singolo;
                            $fee = new WC_Order_Item_Fee();
                            $fee->set_name( 'Sconto Tessera FAI (' . $tessere_fai . ' validat' . ($tessere_fai == 1 ? 'a' : 'e') . ')' );
                            $fee->set_amount( -$sconto_totale );
                            $fee->set_total( -$sconto_totale );
                            $order->add_item( $fee );
                        }

                        $order->calculate_totals();
                        $order->update_status( 'pending', '⏳ Ordine generato automaticamente da Lista d\'Attesa.' );
                        
                        wc_reduce_stock_levels( $order->get_id() );
                        /** @var \WC_Email_Customer_Invoice|null $email_invoice */
                        $email_invoice = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'] ?? null;
                        if ( $email_invoice ) {
                            $email_invoice->trigger( $order->get_id() );
                        }
                        $order->add_order_note( '📧 Inviato link di pagamento a ' . esc_html( $entry['email'] ) );

                        unset( $waitlist[ $selected_event ][ $index ] );
                        $waitlist[ $selected_event ] = array_values( $waitlist[ $selected_event ] );
                        update_option( 'cv_waitlist_data', $waitlist );
                        
                        $edit_url = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                        $messaggio = '<div class="notice notice-success is-dismissible"><p>🎉 <strong>Successo!</strong> È stato creato l\'ordine <strong>#' . $order->get_id() . '</strong> e la mail con il link di pagamento è stata inviata a ' . esc_html($entry['email']) . '. <a href="' . esc_url($edit_url) . '">Visualizza Ordine</a></p></div>';
                    }
                    break;
                }
            }
        }
    }

    $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );
    
    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">Gestione Liste di Attesa</h1>';
    echo '<p style="font-size:15px; color:#555;">Seleziona un evento per aggiungere clienti in coda o promuoverli a ordini veri e propri quando si liberano i posti.</p>';
    echo wp_kses_post( $messaggio );

    echo '<form method="GET" style="margin:20px 0; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px; display:inline-block;">';
    echo '<input type="hidden" name="page" value="cv-liste-attesa">';
    echo '<label style="font-weight:bold; margin-right:10px;">Seleziona l\'evento Sold Out:</label>';
    echo '<select name="event_id" style="min-width:300px;"><option value="">-- Scegli Evento --</option>';
    foreach ( $products as $product ) {
        echo '<option value="' . esc_attr( $product->get_id() ) . '" ' . selected( $selected_event, $product->get_id(), false ) . '>' . esc_html( $product->get_name() ) . '</option>';
    }
    echo '</select><button type="submit" class="button button-primary" style="margin-left:10px;">Gestisci Coda</button></form>';

    if ( $selected_event > 0 ) {
        $product = wc_get_product( $selected_event );
        // FATAL-03: Null-check per evitare fatal error se il prodotto è stato cancellato
        if ( ! $product ) {
            echo '<div class="notice notice-error"><p>❌ Prodotto/evento non trovato nel catalogo.</p></div></div>';
            return;
        }
        $stock = $product->get_stock_quantity();
        $stock_text = ($stock > 0) ? '<span style="color:green; font-weight:bold;">' . $stock . ' posti liberi!</span>' : '<span style="color:#d63638; font-weight:bold;">0 posti liberi</span>';

        echo '<div style="display:flex; gap:20px; flex-wrap:wrap; align-items:flex-start;">';
        
        // COLONNA SX: AGGIUNGI
        echo '<div style="flex:1; min-width:300px; max-width:350px; background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">';
        echo '<h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">➕ Aggiungi in Coda</h3>';
        echo '<form method="POST" action="">';
        wp_nonce_field( 'cv_add_waitlist', 'cv_add_waitlist_nonce' );
        echo '<p><label>Nome:</label><br><input type="text" name="wl_nome" required style="width:100%; padding:5px;"></p>';
        echo '<p><label>Cognome:</label><br><input type="text" name="wl_cognome" style="width:100%; padding:5px;"></p>';
        echo '<p><label>Email *:</label><br><input type="email" name="wl_email" required style="width:100%; padding:5px;"></p>';
        echo '<p><label>Telefono:</label><br><input type="text" name="wl_tel" style="width:100%; padding:5px;"></p>';
        
        echo '<div style="display: flex; gap: 15px;">';
        echo '<p style="flex: 1;"><label>Biglietti:</label><br><input type="number" name="wl_qty" id="wl_qty" value="1" min="1" required style="width:100%; padding:5px;"></p>';
        echo '<p style="flex: 1;"><label>Tessere FAI:</label><br><input type="number" name="wl_tessere" id="wl_tessere" value="0" min="0" style="width:100%; padding:5px;"></p>';
        echo '</div>';
        
        echo '<button type="submit" class="button button-primary" style="width:100%;">Inserisci in Lista d\'Attesa</button>';
        echo '</form>';

        echo '<script>
            jQuery(document).ready(function($){
                $("#wl_tessere").on("change", function(){
                    var max = parseInt($("#wl_qty").val());
                    if(parseInt(this.value) > max) this.value = max;
                });
                $("#wl_qty").on("change", function(){
                    var val = parseInt($("#wl_tessere").val());
                    if(val > parseInt(this.value)) $("#wl_tessere").val(this.value);
                });
            });
        </script>';
        echo '</div>';

        // COLONNA DX: LA CODA
        echo '<div style="flex:3; min-width:500px; background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">';
        echo '<h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px; display:flex; justify-content:space-between;">';
        echo '<span>👥 Persone in Coda</span> <span>Disponibilità attuale: ' . $stock_text . '</span></h3>';
        
        $waitlist_all = get_option( 'cv_waitlist_data', array() );
        $coda_attuale = isset( $waitlist_all[ $selected_event ] ) ? $waitlist_all[ $selected_event ] : array();

        if ( empty( $coda_attuale ) ) {
            echo '<p style="color:#777; font-style:italic;">Nessuno in lista d\'attesa per questo evento.</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped" style="margin-top:10px;">';
            echo '<thead><tr><th style="width:50px;">#</th><th>Data Richiesta</th><th>Cliente</th><th>Contatti</th><th>Biglietti</th><th style="text-align:right;">Azioni</th></tr></thead><tbody>';
            
            foreach ( $coda_attuale as $index => $entry ) {
                $pos = $index + 1;
                $data_req = date_i18n( 'd/m/Y H:i', strtotime( $entry['date'] ) );
                
                $tessere_fai = isset( $entry['tessere'] ) ? intval( $entry['tessere'] ) : 0;
                $badge_fai = $tessere_fai > 0 ? '<span style="background:#ff6600; color:#fff; padding:3px 6px; border-radius:3px; font-size:10px; font-weight:bold; display:inline-block; margin-left:8px; vertical-align:middle;">' . $tessere_fai . ' FAI</span>' : '';
                
                echo '<tr>';
                echo '<td style="vertical-align:middle;"><strong>' . $pos . '°</strong></td>';
                echo '<td style="vertical-align:middle;">' . esc_html( $data_req ) . '</td>';
                echo '<td style="vertical-align:middle;"><strong>' . esc_html( $entry['nome'] . ' ' . $entry['cognome'] ) . '</strong></td>';
                echo '<td style="vertical-align:middle;"><a href="mailto:' . esc_attr($entry['email']) . '">' . esc_html($entry['email']) . '</a><br><small>' . esc_html($entry['tel']) . '</small></td>';
                echo '<td style="vertical-align:middle;"><strong>' . esc_html( $entry['qty'] ) . '</strong>' . $badge_fai . '</td>';
                
                echo '<td style="text-align:right; vertical-align:middle;">';
                echo '<form method="POST" style="display:flex; justify-content:flex-end; gap:5px; margin:0;" onsubmit="return confirm(\'Confermi l\\\'operazione?\');">';
                wp_nonce_field( 'cv_action_waitlist', 'cv_action_waitlist_nonce' );
                echo '<input type="hidden" name="wl_id" value="' . esc_attr( $entry['id'] ) . '">';
                
                echo '<button type="submit" name="wl_action" value="promote" class="button" style="background:#2271b1; color:#fff; border-color:#2271b1;" title="Crea Ordine e Invia link di pagamento">🎟️ Promuovi</button>';
                echo '<button type="submit" name="wl_action" value="delete" class="button" style="color:#d63638; border-color:#d63638;" title="Rimuovi dalla lista">❌ Rimuovi</button>';
                echo '</form>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            
            if ( $stock > 0 ) {
                echo '<p style="margin-top:15px; color:#166534; background:#eaf7ea; padding:10px; border-radius:5px; border:1px solid #c3e6c3;">💡 <strong>Suggerimento:</strong> Ci sono posti liberi! Puoi cliccare su "Promuovi" per il primo della lista: il sistema creerà la sua prenotazione applicando gli sconti e gli invierà automaticamente la mail per pagare entro 24 ore.</p>';
            }
        }
        echo '</div></div>';
    }
    echo '</div>';
}
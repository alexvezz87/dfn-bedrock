<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * ROUTER DELLE CHIAMATE AJAX (API ENDPOINTS)
 * Tutte le chiamate asincrone del gestionale passano da qui.
 * ========================================================================
 */

// 1. RICERCA CLIENTI (Botteghino Live)
add_action( 'wp_ajax_cv_search_customers', 'cv_search_customers_ajax' );
function cv_search_customers_ajax() {
    check_ajax_referer( 'cv_ricerca_clienti_nonce', 'security' );
    $term = isset( $_GET['term'] ) ? wc_clean( wp_unslash( $_GET['term'] ) ) : '';
    if ( empty( $term ) ) wp_send_json( array() );
    
    $results = array(); $emails_found = array();
    $users = new WP_User_Query( array( 'search' => '*' . esc_attr( $term ) . '*', 'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ), 'number' => 10 ) );
    foreach ( $users->get_results() as $user ) {
        $results[] = array( 'id' => $user->ID, 'text' => $user->display_name . ' (' . $user->user_email . ') - Registrato' );
        $emails_found[] = strtolower( $user->user_email );
    }

    global $wpdb;
    $term_like = '%' . $wpdb->esc_like( $term ) . '%';
    $query = $wpdb->prepare( "SELECT email, first_name, last_name, user_id FROM {$wpdb->prefix}wc_customer_lookup WHERE email LIKE %s OR first_name LIKE %s OR last_name LIKE %s GROUP BY email LIMIT 20", $term_like, $term_like, $term_like );
    foreach ( $wpdb->get_results( $query ) as $row ) {
        if ( ! in_array( strtolower( $row->email ), $emails_found ) ) {
            $nome = trim( $row->first_name . ' ' . $row->last_name );
            if ( empty( $nome ) ) $nome = 'Cliente Ospite';
            $results[] = array( 'id' => ( $row->user_id > 0 ) ? $row->user_id : 'email|' . $row->email, 'text' => $nome . ' (' . $row->email . ')' );
            $emails_found[] = strtolower( $row->email );
        }
    }
    wp_send_json( $results );
}

// 2. RECUPERA DATI CLIENTE (Botteghino Live)
add_action( 'wp_ajax_cv_get_customer_data', 'cv_get_customer_data_ajax' );
function cv_get_customer_data_ajax() {
    check_ajax_referer( 'cv_ricerca_clienti_nonce', 'security' );
    $customer_id_raw = isset( $_POST['customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_id'] ) ) : '';

    if ( is_numeric( $customer_id_raw ) && $customer_id_raw > 0 ) {
        // FATAL-01: WC_Customer lancia eccezione se l'ID non esiste
        try {
            $customer = new WC_Customer( intval( $customer_id_raw ) );
        } catch ( \Exception $e ) {
            wp_send_json_error( array( 'message' => 'Cliente non trovato.' ) );
        }

        $first_name = $customer->get_billing_first_name() ?: $customer->get_first_name();
        $last_name  = $customer->get_billing_last_name() ?: $customer->get_last_name();
        $email      = $customer->get_billing_email() ?: $customer->get_email();
        $phone      = $customer->get_billing_phone();
        if ( empty( $phone ) || empty( $first_name ) ) {
            $last_orders = wc_get_orders( array( 'customer_id' => intval( $customer_id_raw ), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );
            if ( ! empty( $last_orders ) ) {
                if ( empty( $phone ) ) $phone = $last_orders[0]->get_billing_phone();
                if ( empty( $first_name ) ) $first_name = $last_orders[0]->get_billing_first_name();
                if ( empty( $last_name ) ) $last_name = $last_orders[0]->get_billing_last_name();
            }
        }
        wp_send_json_success( array( 'first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'phone' => $phone ) );
    } elseif ( strpos( $customer_id_raw, 'email|' ) === 0 ) {
        $email = trim( str_replace( 'email|', '', $customer_id_raw ) );
        $orders = wc_get_orders( array( 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'billing_email' => $email ) );
        if ( ! empty( $orders ) ) {
            $last_order = $orders[0];
            wp_send_json_success( array( 'first_name' => $last_order->get_billing_first_name(), 'last_name' => $last_order->get_billing_last_name(), 'email' => $last_order->get_billing_email(), 'phone' => $last_order->get_billing_phone() ) );
        } else {
            global $wpdb;
            $lookup = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name FROM {$wpdb->prefix}wc_customer_lookup WHERE email = %s LIMIT 1", $email ) );
            wp_send_json_success( array( 'first_name' => $lookup ? $lookup->first_name : '', 'last_name' => $lookup ? $lookup->last_name : '', 'email' => $email, 'phone' => '' ) );
        }
    }
    wp_send_json_error();
}

// 3. FETCH DATI TABELLONE E CLASSIFICA (Report Check-in)
add_action( 'wp_ajax_cv_fetch_report_data', 'cv_fetch_report_data_ajax' );
function cv_fetch_report_data_ajax() {
    check_ajax_referer( 'cv_fetch_report_nonce', 'security' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

    $event_id      = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $page          = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
    $search        = isset($_POST['search']) ? strtolower(sanitize_text_field(wp_unslash($_POST['search']))) : '';
    $filter_status = isset($_POST['filter_status']) ? sanitize_text_field($_POST['filter_status']) : 'all';
    $per_page      = 50; 
    
    $is_delitto = ( $event_id === CV_EVENT_ID_DELITTO );
    $tavoli_delitto = cv_get_nomi_tavoli_delitto();

    $tot_rimanenti = '∞';
    $product = wc_get_product( $event_id );
    if ( $product && $product->managing_stock() ) {
        $tot_rimanenti = $product->get_stock_quantity();
    }

    // PERF-01: Filtriamo direttamente per product_id invece di caricare tutti gli ordini
    $orders = wc_get_orders( array( 'status' => array( 'wc-processing', 'wc-completed' ), 'limit' => -1, 'product_id' => $event_id ) );

    $tot_venduti = 0; $tot_checkin = 0; $filtered_orders = array();
    $global_validators = array();
    $user_cache = array();
    
    foreach ( $orders as $order ) {
        $qty_prodotto = 0;
        foreach ( $order->get_items() as $item ) { if ( $item->get_product_id() == $event_id ) $qty_prodotto += $item->get_quantity(); }
        if ( $qty_prodotto === 0 ) continue;
        
        $tot_venduti += $qty_prodotto;
        $checkin_fatti = 0;
        for ( $i = 1; $i <= $qty_prodotto; $i++ ) { 
            if ( $order->get_meta( '_cv_ticket_validato_' . $i ) === 'yes' ) {
                $checkin_fatti++;
                $op_id = $order->get_meta( '_cv_ticket_validato_' . $i . '_operatore' );
                if ( $op_id ) {
                    if ( !isset($user_cache[$op_id]) ) {
                        $u = get_userdata($op_id);
                        $user_cache[$op_id] = $u ? $u->display_name : 'Sconosciuto';
                    }
                    $nome_op = $user_cache[$op_id];
                    if ( !isset($global_validators[$nome_op]) ) $global_validators[$nome_op] = 0;
                    $global_validators[$nome_op]++;
                }
            } 
        }
        $tot_checkin += $checkin_fatti;

        $match_status = true;
        if ( $filter_status === 'pending' && $checkin_fatti > 0 ) $match_status = false;
        if ( $filter_status === 'partial' && ($checkin_fatti === 0 || $checkin_fatti === $qty_prodotto) ) $match_status = false;
        if ( $filter_status === 'complete' && $checkin_fatti < $qty_prodotto ) $match_status = false;

        $nome_cliente = strtolower( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $telefono = strtolower( $order->get_billing_phone() );
        $id_string = (string)$order->get_id();

        if ( $match_status && (empty($search) || strpos($nome_cliente, $search) !== false || strpos($telefono, $search) !== false || strpos($id_string, $search) !== false) ) {
            $filtered_orders[] = array( 'order' => $order, 'qty' => $qty_prodotto, 'checkin_fatti' => $checkin_fatti );
        }
    }

    arsort($global_validators);
    $html_leaderboard = '';
    if ( !empty($global_validators) ) {
        $html_leaderboard .= '<div style="background:#fff; border:1px solid #ffd700; padding:15px; border-radius:8px; box-shadow:0 2px 10px rgba(255,215,0,0.15); margin-bottom:20px;">';
        $html_leaderboard .= '<h3 style="margin:0 0 10px 0; color:#b45309; font-size:16px; display:flex; align-items:center; gap:8px;">🏆 Classifica Verificatori (Live)</h3>';
        $html_leaderboard .= '<div style="display:flex; flex-wrap:wrap; gap:10px;">';
        $rank = 1;
        foreach ( $global_validators as $nome => $count ) {
            $medaglia = '';
            if ( $rank === 1 ) $medaglia = '🥇 ';
            elseif ( $rank === 2 ) $medaglia = '🥈 ';
            elseif ( $rank === 3 ) $medaglia = '🥉 ';
            else $medaglia = '🎖️ ';
            $bg = ($rank <= 3) ? '#fefce8' : '#f9fafb';
            $border = ($rank <= 3) ? '#fde047' : '#e5e7eb';
            $html_leaderboard .= '<div style="background:'.$bg.'; border:1px solid '.$border.'; padding:5px 12px; border-radius:20px; font-size:14px; display:flex; align-items:center; gap:8px;">';
            $html_leaderboard .= '<span style="font-weight:bold; color:#4b5563;">' . $medaglia . esc_html($nome) . '</span>';
            $html_leaderboard .= '<span style="background:#fff; color:#b45309; padding:2px 8px; border-radius:12px; font-size:12px; font-weight:bold; border:1px solid '.$border.';">' . $count . '</span>';
            $html_leaderboard .= '</div>';
            $rank++;
        }
        $html_leaderboard .= '</div></div>';
    }

    $tot_residui = $tot_venduti - $tot_checkin;
    $total_filtered = count($filtered_orders);
    $total_pages = ceil($total_filtered / $per_page);
    $offset = ($page - 1) * $per_page;
    $paged_orders = array_slice($filtered_orders, $offset, $per_page);

    $table_rows = '';
    $colspan = $is_delitto ? 11 : 10;
    
    if ( empty($paged_orders) ) {
        $table_rows = '<tr><td colspan="' . $colspan . '" style="text-align:center;">Nessun ordine trovato con questi criteri.</td></tr>';
    } else {
        foreach ( $paged_orders as $data ) {
            $order = $data['order']; $qty_prodotto = $data['qty']; $checkin_fatti = $data['checkin_fatti'];
            $nome_cliente = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $telefono = $order->get_billing_phone();
            $telefono_html = $telefono ? '<a href="tel:' . esc_attr( $telefono ) . '">' . esc_html( $telefono ) . '</a>' : '-';
            $badge_fai = function_exists('cv_get_order_qualifica_label') ? cv_get_order_qualifica_label( $order ) : '-';

            $operatori_coinvolti = array();
            $html_bottoni_popup = '<div class="cv-popup-data-container" style="display:none;">';
            for ( $i = 1; $i <= $qty_prodotto; $i++ ) {
                if ( $order->get_meta( '_cv_ticket_validato_' . $i ) === 'yes' ) {
                    $op_id = $order->get_meta( '_cv_ticket_validato_' . $i . '_operatore' );
                    if ( $op_id ) {
                        // PERF-02: Riutilizziamo la cache utenti già costruita sopra
                        if ( ! isset( $user_cache[ $op_id ] ) ) {
                            $user_info = get_userdata( $op_id );
                            $user_cache[ $op_id ] = $user_info ? $user_info->display_name : 'Sconosciuto';
                        }
                        $nome_op = $user_cache[ $op_id ];
                        isset( $operatori_coinvolti[$nome_op] ) ? $operatori_coinvolti[$nome_op]++ : $operatori_coinvolti[$nome_op] = 1;
                    }
                    $html_bottoni_popup .= '<div style="margin-bottom:8px; padding:10px; background:#eaf7ea; color:#166534; border: 1px solid #c3e6c3; border-radius: 4px; display:flex; justify-content:space-between; align-items:center;"><span>✅ Biglietto ' . $i . ' validato</span><button class="button cv-undo-checkin-btn" data-order="' . esc_attr($order->get_id()) . '" data-ticket="' . esc_attr($i) . '" style="color:#d63638; border-color:#d63638; padding:0 8px; min-height:26px; line-height:24px;">Annulla</button></div>';
                } else {
                    $html_bottoni_popup .= '<button class="button cv-manual-checkin-btn" data-order="' . esc_attr($order->get_id()) . '" data-ticket="' . esc_attr($i) . '" style="margin-bottom:8px; display:block; width:100%; border-color:#00a32a; color:#00a32a; height: 40px; cursor:pointer;">✔️ Valida Biglietto ' . $i . '</button>';
                }
            }
            $html_bottoni_popup .= '</div>'; 

            $history_meta = $order->get_meta( '_cv_ticket_history' );
            $html_history_popup = '<div class="cv-history-data-container" style="display:none;">';
            if ( empty($history_meta) || !is_array($history_meta) ) {
                $html_history_popup .= '<p style="color:#666; font-style:italic;">Nessuna interazione registrata.</p>';
            } else {
                usort($history_meta, function($a, $b) { return strtotime($b['time']) - strtotime($a['time']); });
                foreach( $history_meta as $log ) {
                    $html_history_popup .= '<div class="cv-history-item"><span style="color:#777; margin-right:10px;">🕒 ' . date_i18n( 'd/m/Y - H:i:s', strtotime( $log['time'] ) ) . '</span> <strong>' . esc_html( $log['action'] ) . '</strong></div>';
                }
            }
            $html_history_popup .= '</div>';

            if ( $checkin_fatti === 0 ) { 
                $stato_badge = '<span style="color:white; background:#d63638; padding:4px 10px; border-radius:4px; font-weight:bold;">0 / ' . $qty_prodotto . '</span>'; 
            } elseif ( $checkin_fatti < $qty_prodotto ) { 
                $stato_badge = '<span style="color:white; background:#f59e0b; padding:4px 10px; border-radius:4px; font-weight:bold;">' . $checkin_fatti . ' / ' . $qty_prodotto . '</span>'; 
            } else { 
                $stato_badge = '<span style="color:white; background:#16a34a; padding:4px 10px; border-radius:4px; font-weight:bold;">Completo (' . $qty_prodotto . ')</span>'; 
            }

            // BOTTONI MESSAGGI
            $reminder_sent = $order->get_meta('_cv_reminder_sent') === 'yes';
            $btn_reminder = '<button class="button cv-single-reminder-btn" data-order="' . esc_attr($order->get_id()) . '" style="font-size:11px; padding: 2px 8px; line-height: 1.5; width:100%;">' . ($reminder_sent ? '📧 Reinvia Reminder' : '📧 Invia Reminder') . '</button>';
            
            $feedback_sent = $order->get_meta('_cv_feedback_sent') === 'yes';
            $btn_feedback = '<button class="button cv-single-feedback-btn" data-order="' . esc_attr($order->get_id()) . '" style="font-size:11px; padding: 2px 8px; line-height: 1.5; width:100%; margin-top:5px;">' . ($feedback_sent ? '⭐ Reinvia Recensione' : '⭐ Chiedi Recensione') . '</button>';

            if ( $checkin_fatti < $qty_prodotto ) {
                $html_azioni_cassa = '<button class="button cv-open-popup-btn" data-cliente="' . esc_attr($nome_cliente) . '" style="width:100%;">🎟️ Gestisci Ingressi</button>' . $html_bottoni_popup;
            } else {
                $html_azioni_cassa = '<button class="button cv-open-popup-btn" data-cliente="' . esc_attr($nome_cliente) . '" style="width:100%; font-size:11px;">🔍 Modifica validazioni</button>' . $html_bottoni_popup;
            }

            $html_bottone_storico = '<button class="button cv-open-history-btn" data-cliente="' . esc_attr($nome_cliente) . '">📜 Log</button>' . $html_history_popup;

            $html_operatori = '-';
            if ( ! empty( $operatori_coinvolti ) ) {
                $html_operatori = '';
                foreach ( $operatori_coinvolti as $nome => $qta ) { $html_operatori .= '<span style="display:block; margin-bottom:4px; font-size:12px;">👤 ' . esc_html( $nome ) . ' <small style="color:#777;">(x' . $qta . ')</small></span>'; }
            }

            $html_tavolo = '';
            if ( $is_delitto ) {
                $tavolo_assegnato = $order->get_meta('_cv_assigned_table');
                $html_tavolo .= '<td style="background:#fff9e6; border-left:1px solid #ddd; text-align:center;">';
                $html_tavolo .= '<select class="cv-table-selector" data-order="' . esc_attr($order->get_id()) . '" style="width:100%; font-size:12px;">';
                $html_tavolo .= '<option value="">-- Da smistare --</option>';
                foreach($tavoli_delitto as $nt) { $html_tavolo .= '<option value="' . esc_attr($nt) . '" ' . selected($tavolo_assegnato, $nt, false) . '>' . esc_html($nt) . '</option>'; }
                $html_tavolo .= '</select></td>';
            }

            $edit_url = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');

            $table_rows .= '<tr><td><a href="' . esc_url($edit_url) . '"><strong>#' . $order->get_id() . '</strong></a></td><td>' . esc_html( $nome_cliente ) . '</td><td>' . $badge_fai . '</td><td>' . $telefono_html . '</td><td><strong>' . esc_html( $qty_prodotto ) . '</strong></td>' . $html_tavolo . '<td>' . $stato_badge . '</td><td>' . $html_operatori . '</td><td style="background:#f9f9f9; border-left:1px solid #ddd;">' . $html_azioni_cassa . '</td><td style="background:#fcfdfd; border-left:1px solid #ddd; text-align:center;">' . $btn_reminder . $btn_feedback . '</td><td style="background:#f6f7f7; border-left:1px solid #ddd; text-align:center;">' . $html_bottone_storico . '</td></tr>';
        }
    }

    $html_pagination = '';
    if ( $total_pages > 1 ) {
        $html_pagination .= '<span style="margin-right:10px;">Pagina ' . $page . ' di ' . $total_pages . '</span>';
        if ( $page > 1 ) $html_pagination .= '<button class="button cv-page-btn" data-page="' . ($page - 1) . '">&laquo; Precedente</button>';
        if ( $page < $total_pages ) $html_pagination .= '<button class="button cv-page-btn" data-page="' . ($page + 1) . '">Successiva &raquo;</button>';
    }

    wp_send_json_success( array( 'tot_venduti' => $tot_venduti, 'tot_checkin' => $tot_checkin, 'tot_residui' => $tot_residui, 'tot_rimanenti' => $tot_rimanenti, 'html_leaderboard' => $html_leaderboard, 'html_table_rows' => $table_rows, 'html_pagination' => $html_pagination ) );
}

add_action( 'wp_ajax_cv_process_manual_checkin', 'cv_process_manual_checkin_ajax' );
function cv_process_manual_checkin_ajax() {
    check_ajax_referer( 'cv_manual_checkin_nonce', 'security' );
    // SEC-06: Verifica permessi — solo operatori autorizzati
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'cv_use_scanner' ) ) {
        wp_send_json_error( 'Permessi insufficienti.' );
    }

    $order_id = isset($_POST['order_id']) ? intval( $_POST['order_id'] ) : 0;
    $ticket_index = isset($_POST['ticket']) ? intval( $_POST['ticket'] ) : 0;
    $order = wc_get_order( $order_id );

    // FATAL-02: Null-check su wc_get_order
    if ( ! $order ) {
        wp_send_json_error( 'Ordine non trovato.' );
    }

    $lock_key = 'cv_ticket_lock_' . $order_id . '_' . $ticket_index;
    set_transient( $lock_key, 1, 5 ); 
    $meta_key = '_cv_ticket_validato_' . $ticket_index;
    $order->update_meta_data( $meta_key, 'yes' );
    $order->update_meta_data( $meta_key . '_orario', current_time( 'mysql' ) );
    $order->update_meta_data( $meta_key . '_operatore', get_current_user_id() );
    $order->save();
    delete_transient( $lock_key );
    wp_send_json_success();
}

add_action( 'wp_ajax_cv_process_undo_checkin', 'cv_process_undo_checkin_ajax' );
function cv_process_undo_checkin_ajax() {
    check_ajax_referer( 'cv_manual_checkin_nonce', 'security' );
    // SEC-06: Verifica permessi
    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'cv_use_scanner' ) ) {
        wp_send_json_error( 'Permessi insufficienti.' );
    }

    $order_id = isset($_POST['order_id']) ? intval( $_POST['order_id'] ) : 0;
    $ticket_index = isset($_POST['ticket']) ? intval( $_POST['ticket'] ) : 0;
    $order = wc_get_order( $order_id );

    // FATAL-02: Null-check su wc_get_order
    if ( ! $order ) {
        wp_send_json_error( 'Ordine non trovato.' );
    }

    $meta_key = '_cv_ticket_validato_' . $ticket_index;
    $order->delete_meta_data( $meta_key );
    $order->delete_meta_data( $meta_key . '_orario' );
    $order->delete_meta_data( $meta_key . '_operatore' );
    $current_user = wp_get_current_user();
    $order->add_order_note( "🔄 Validazione annullata dal pannello Check-in dall'utente: {$current_user->display_name}" );
    $order->save();
    wp_send_json_success();
}

add_action( 'wp_ajax_cv_manual_assign_table', 'cv_manual_assign_table_ajax' );
function cv_manual_assign_table_ajax() {
    check_ajax_referer( 'cv_assign_table_nonce', 'security' );
    // SEC-06: Verifica permessi
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permessi insufficienti.' );
    }

    $order_id = isset($_POST['order_id']) ? intval( $_POST['order_id'] ) : 0;
    $table = isset($_POST['table']) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';
    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( '_cv_assigned_table', $table );
            $order->save();
            wp_send_json_success();
        }
    }
    wp_send_json_error();
}

add_action( 'wp_ajax_cv_auto_assign_tables', 'cv_auto_assign_tables_ajax' );
function cv_auto_assign_tables_ajax() {
    check_ajax_referer( 'cv_assign_table_nonce', 'security' );
    // SEC-06: Verifica permessi
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'Permessi insufficienti.' );
    }

    $event_id = isset($_POST['event_id']) ? intval( $_POST['event_id'] ) : 0;
    $tavoli_capienza = cv_get_tavoli_delitto();
    // PERF-01: Filtriamo per product_id
    $orders = wc_get_orders( array( 'status' => array( 'wc-processing', 'wc-completed' ), 'limit' => -1, 'product_id' => $event_id ) );
    $gruppi_da_piazzare = array();
    foreach ( $orders as $order ) {
        $qty = 0;
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_product_id() == $event_id ) $qty += $item->get_quantity();
        }
        if ( $qty > 0 ) $gruppi_da_piazzare[] = array( 'order' => $order, 'qty' => $qty );
    }
    shuffle($gruppi_da_piazzare);
    usort($gruppi_da_piazzare, function($a, $b) { return $b['qty'] - $a['qty']; });
    $tavoli_occupati = array_fill_keys(array_keys($tavoli_capienza), 0);
    foreach ( $gruppi_da_piazzare as $gruppo ) {
        $qty_gruppo = $gruppo['qty']; $tavolo_scelto = ''; $miglior_sforamento = -999;
        foreach ( $tavoli_capienza as $nome_tavolo => $cap_max ) {
            $posti_rimasti = $cap_max - $tavoli_occupati[$nome_tavolo];
            if ( $posti_rimasti >= $qty_gruppo ) { $tavolo_scelto = $nome_tavolo; break; } 
            else { if ( $posti_rimasti > $miglior_sforamento ) { $miglior_sforamento = $posti_rimasti; $tavolo_scelto = $nome_tavolo; } }
        }
        $tavoli_occupati[$tavolo_scelto] += $qty_gruppo;
        $order = $gruppo['order'];
        $order->update_meta_data( '_cv_assigned_table', $tavolo_scelto );
        $order->save();
    }
    wp_send_json_success();
}

add_action( 'wp_ajax_cv_fetch_table_map', 'cv_fetch_table_map_ajax' );
function cv_fetch_table_map_ajax() {
    check_ajax_referer( 'cv_assign_table_nonce', 'security' );
    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $tavoli_capienza = cv_get_tavoli_delitto();
    $mappa = array(); foreach($tavoli_capienza as $nome => $capienza) { $mappa[$nome] = array( 'capienza' => $capienza, 'occupati' => 0, 'gruppi' => array() ); }
    $mappa['Non Assegnato'] = array( 'capienza' => 0, 'occupati' => 0, 'gruppi' => array() );
    // PERF-01: Filtriamo per product_id
    $orders = wc_get_orders( array( 'status' => array( 'wc-processing', 'wc-completed' ), 'limit' => -1, 'product_id' => $event_id ) );
    foreach ( $orders as $order ) {
        $qty = 0; foreach ( $order->get_items() as $item ) { if ( $item->get_product_id() == $event_id ) $qty += $item->get_quantity(); }
        if ( $qty === 0 ) continue;
        $nome_cliente = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $tavolo = $order->get_meta('_cv_assigned_table');
        if ( empty($tavolo) || !isset($mappa[$tavolo]) ) $tavolo = 'Non Assegnato';
        $mappa[$tavolo]['occupati'] += $qty;
        $mappa[$tavolo]['gruppi'][] = esc_html($nome_cliente) . ' <strong style="color:#2271b1;">(' . $qty . ' posti)</strong>';
    }
    $html = '<div class="cv-table-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">';
    foreach ( $mappa as $nome_tavolo => $dati ) {
        if ( $nome_tavolo === 'Non Assegnato' && $dati['occupati'] === 0 ) continue;
        $cap = $dati['capienza']; $occ = $dati['occupati'];
        $colore_header = '#e5e7eb'; $colore_testo = '#374151';
        if ( $nome_tavolo === 'Non Assegnato' || $occ > $cap ) { $colore_header = '#fef2f2'; $colore_testo = '#991b1b'; } 
        elseif ( $occ === $cap ) { $colore_header = '#dcfce7'; $colore_testo = '#166534'; } 
        elseif ( $occ > 0 ) { $colore_header = '#fef3c7'; $colore_testo = '#92400e'; }
        $html .= '<div class="cv-table-card" style="background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.1); border:1px solid #e5e7eb;">';
        $html .= '<div style="background:' . $colore_header . '; color:' . $colore_testo . '; padding:15px; border-bottom:1px solid rgba(0,0,0,0.05); display:flex; justify-content:space-between; align-items:center;">';
        $html .= '<h3 style="margin:0; font-size:18px;">🍽️ ' . esc_html($nome_tavolo) . '</h3>';
        if($cap > 0) $html .= '<strong style="font-size:16px;">' . $occ . '/' . $cap . '</strong>'; else $html .= '<strong style="font-size:16px;">' . $occ . ' posti</strong>';
        $html .= '</div><div style="padding:15px;">';
        if ( empty($dati['gruppi']) ) { $html .= '<p style="color:#9ca3af; font-style:italic; margin:0;">Tavolo vuoto</p>'; } 
        else {
            $html .= '<ul style="margin:0; padding-left:20px; color:#4b5563; font-size:16px; line-height:1.6;">';
            foreach( $dati['gruppi'] as $gruppo ) { $html .= '<li style="margin-bottom:5px;">' . $gruppo . '</li>'; }
            $html .= '</ul>';
        }
        $html .= '</div></div>';
    }
    $html .= '</div>';
    wp_send_json_success( $html );
}

add_action( 'wp_ajax_nopriv_cv_track_ticket_action', 'cv_track_ticket_action_ajax' );
add_action( 'wp_ajax_cv_track_ticket_action', 'cv_track_ticket_action_ajax' );
function cv_track_ticket_action_ajax() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $action   = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
    $token    = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

    if ( ! $order_id || ! $action || ! $token ) wp_send_json_error();

    $order = wc_get_order( $order_id );
    if ( ! $order ) wp_send_json_error();

    $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_hub', wp_salt('nonce') );
    if ( ! hash_equals( $expected_token, $token ) ) wp_send_json_error();

    $history = $order->get_meta( '_cv_ticket_history' );
    if ( ! is_array( $history ) ) $history = array();

    $history[] = array( 'time' => current_time( 'mysql' ), 'action' => $action );
    if ( count($history) > 50 ) $history = array_slice($history, -50);

    $order->update_meta_data( '_cv_ticket_history', $history );
    $order->save();
    wp_send_json_success();
}

// 7. INVIO EMAIL REMINDER
add_action( 'wp_ajax_cv_send_event_reminders', 'cv_send_event_reminders_ajax' );
function cv_send_event_reminders_ajax() {
    check_ajax_referer( 'cv_reminder_nonce', 'security' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Permessi insufficienti.' );

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if ( ! $event_id && ! $order_id ) wp_send_json_error( 'Nessun ordine o evento specificato.' );

    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Ordine non trovato.' );
        $orders = array( $order );
        $is_single = true;
    } else {
        $orders = wc_get_orders( array( 'status' => array( 'wc-processing', 'wc-completed' ), 'limit' => -1 ) );
        $is_single = false;
    }

    $invii = 0; $has_more = false; $mailer = WC()->mailer(); $account_url = wc_get_page_permalink( 'myaccount' );

    foreach ( $orders as $order ) {
        if ( ! $order ) continue;
        
        if ( ! $is_single ) {
            $has_event = false;
            foreach ( $order->get_items() as $item ) {
                if ( $item->get_product_id() == $event_id ) { $has_event = true; break; }
            }
            if ( ! $has_event ) continue;
            
            if ( $order->get_meta('_cv_reminder_sent') === 'yes' ) continue;
            if ( $invii >= 5 ) { $has_more = true; break; }
        }

        $email_cliente = $order->get_billing_email();

        // NUOVO CONTROLLO: Salta le email fittizie della cassa (.local)
        if ( substr( strtolower( $email_cliente ), -6 ) === '.local' ) {
            if ( ! $is_single ) {
                $order->update_meta_data('_cv_reminder_sent', 'yes');
                $order->save();
            } else {
                wp_send_json_error( 'Impossibile inviare l\'email a un indirizzo fittizio della cassa.' );
            }
            continue;
        }

        $nome_cliente  = $order->get_billing_first_name();

        $hub_token = hash_hmac( 'sha256', $order->get_order_key() . '_hub', wp_salt('nonce') );
        $hub_url = site_url( '/?cv_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token );

        ob_start();
        wc_get_template( 'emails/email-header.php', array( 'email_heading' => 'I tuoi biglietti digitali' ) );
        echo '<p>Ciao <strong>' . esc_html( $nome_cliente ) . '</strong>,</p>';
        echo '<p>Manca sempre meno al nostro evento! Ti scriviamo per ricordarti la tua prenotazione e per presentarti una bellissima novità del nostro portale.</p>';
        echo '<p>Abbiamo aggiornato il nostro sistema: ora puoi visualizzare, scaricare o inviare comodamente su WhatsApp i tuoi biglietti digitali (dotati di QR Code) per un accesso ancora più rapido all\'ingresso.</p>';
        echo '<div style="text-align: center; margin: 35px 0;"><a href="' . esc_url( $hub_url ) . '" style="background-color: #2271b1; color: #ffffff; padding: 16px 32px; font-size: 18px; font-weight: bold; text-decoration: none; border-radius: 8px; display: inline-block;">🎟️ MOSTRA I MIEI BIGLIETTI</a></div>';
        echo '<h2 style="color: #2271b1; font-size: 20px; margin-top: 30px;">Il tuo Botteghino Personale</h2>';
        echo '<p>Abbiamo anche creato una nuova Area Riservata sul nostro sito. Accedendo con l\'email che hai usato per l\'acquisto (<strong>' . esc_html( $email_cliente ) . '</strong>), potrai entrare nel tuo "Botteghino Personale", ritrovare lo storico degli acquisti e avere i biglietti sempre a portata di mano.</p>';
        echo '<p><a href="' . esc_url( $account_url ) . '" style="color: #ff6600; font-weight: bold; text-decoration: underline;">Clicca qui per scoprire la tua Area Riservata</a></p>';
        echo '<p style="margin-top: 40px;">Ti aspettiamo!<br><em>Lo Staff della Delegazione FAI Novara</em></p>';
        wc_get_template( 'emails/email-footer.php' );
        
        $message = ob_get_clean();
        $mailer->send( $email_cliente, 'I tuoi biglietti e una novità per te! 🎟️', $message, array( 'Content-Type: text/html' ) );

        $nota = $is_single ? '📧 Reinviata email manuale SINGOLA di Reminder Biglietti.' : '📧 Inviata email manuale di Reminder Biglietti (Invio Massivo).';
        $order->add_order_note( $nota );
        $order->update_meta_data('_cv_reminder_sent', 'yes');
        $order->save();
        $invii++;
    }

    wp_send_json_success( array( 'sent' => $invii, 'has_more' => $has_more ) );
}

// 8. INVIO EMAIL DI FEEDBACK E RECENSIONI (CON NOME EVENTO)
add_action( 'wp_ajax_cv_send_feedback_requests', 'cv_send_feedback_requests_ajax' );
function cv_send_feedback_requests_ajax() {
    check_ajax_referer( 'cv_feedback_nonce', 'security' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Permessi insufficienti.' );

    $event_id = isset($_POST['event_id']) ? intval($_POST['event_id']) : 0;
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

    if ( ! $event_id && ! $order_id ) wp_send_json_error( 'Nessun ordine o evento specificato.' );

    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) wp_send_json_error( 'Ordine non trovato.' );
        $orders = array( $order );
        $is_single = true;
    } else {
        $orders = wc_get_orders( array( 'status' => array( 'wc-processing', 'wc-completed' ), 'limit' => -1 ) );
        $is_single = false;
    }

    $invii = 0; $has_more = false; $mailer = WC()->mailer();

    foreach ( $orders as $order ) {
        if ( ! $order ) continue;

        // Calcola i biglietti totali per verificare i check-in effettuati
        $tot_biglietti_ordine = 0;
        foreach ( $order->get_items() as $item ) { 
            $tot_biglietti_ordine += $item->get_quantity(); 
        }
        
        if ( ! $is_single ) {
            $has_event = false;
            foreach ( $order->get_items() as $item ) {
                if ( $item->get_product_id() == $event_id ) { $has_event = true; break; }
            }
            if ( ! $has_event ) continue;
            
            if ( $order->get_meta('_cv_feedback_sent') === 'yes' ) continue;

            // NUOVO CONTROLLO: Il cliente ha effettuato almeno un check-in?
            $has_checkin = false;
            for ( $i = 1; $i <= $tot_biglietti_ordine; $i++ ) {
                if ( $order->get_meta( '_cv_ticket_validato_' . $i ) === 'yes' ) {
                    $has_checkin = true;
                    break;
                }
            }

            if ( ! $has_checkin ) {
                $order->update_meta_data('_cv_feedback_sent', 'yes');
                $order->add_order_note('⭐ Email recensione saltata: il cliente non risulta aver partecipato (nessun check-in).');
                $order->save();
                continue;
            }

            if ( $invii >= 5 ) { $has_more = true; break; }
        } else {
            // Se è un invio singolo, verifichiamo la presenza
            $has_checkin = false;
            for ( $i = 1; $i <= $tot_biglietti_ordine; $i++ ) {
                if ( $order->get_meta( '_cv_ticket_validato_' . $i ) === 'yes' ) {
                    $has_checkin = true;
                    break;
                }
            }

            if ( ! $has_checkin ) {
                wp_send_json_error( 'Impossibile richiedere la recensione: non risulta alcun check-in registrato per questo ordine.' );
            }
        }
        
        if ( $order->get_meta('_cv_is_authority') === 'yes' ) {
            if ( !$is_single ) { $order->update_meta_data('_cv_feedback_sent', 'yes'); $order->save(); }
            continue;
        }

        $email_cliente = $order->get_billing_email();

        // NUOVO CONTROLLO: Salta le email fittizie della cassa (.local)
        if ( substr( strtolower( $email_cliente ), -6 ) === '.local' ) {
            if ( ! $is_single ) {
                $order->update_meta_data('_cv_feedback_sent', 'yes');
                $order->save();
            } else {
                wp_send_json_error( 'Impossibile inviare l\'email a un indirizzo fittizio della cassa.' );
            }
            continue;
        }

        $nome_cliente  = $order->get_billing_first_name();

        $nomi_eventi = array();
        foreach ( $order->get_items() as $item ) {
            $nomi_eventi[] = $item->get_name();
        }
        $titolo_evento = implode( ' + ', $nomi_eventi );

        $feedback_token = hash_hmac( 'sha256', $order->get_order_key() . '_feedback', wp_salt('nonce') );
        $feedback_url = site_url( '/?cv_feedback=1&order_id=' . $order->get_id() . '&token=' . $feedback_token );

        ob_start();
        wc_get_template( 'emails/email-header.php', array( 'email_heading' => 'Grazie per aver partecipato!' ) );
        echo '<p>Ciao <strong>' . esc_html( $nome_cliente ) . '</strong>,</p>';
        echo '<p>Ci teniamo a ringraziarti di cuore per aver partecipato all\'evento <strong>' . esc_html( $titolo_evento ) . '</strong>. Speriamo davvero che tu abbia trascorso una bella esperienza in nostra compagnia.</p>';
        echo '<p>Per noi il tuo parere è fondamentale per poterci migliorare sempre di più. Ti andrebbe di dedicarci 30 secondi per farci sapere com\'è andata?</p>';
        echo '<div style="text-align: center; margin: 35px 0;"><a href="' . esc_url( $feedback_url ) . '" style="background-color: #eab308; color: #ffffff; padding: 16px 32px; font-size: 18px; font-weight: bold; text-decoration: none; border-radius: 8px; display: inline-block;">⭐ LASCIA UNA RECENSIONE</a></div>';
        echo '<p style="margin-top: 40px;">Grazie per il tuo tempo e a presto ai prossimi eventi!<br><em>Lo Staff della Delegazione FAI Novara</em></p>';
        wc_get_template( 'emails/email-footer.php' );
        
        $message = ob_get_clean();
        
        $subject = 'Com\'è andato l\'evento "' . esc_html( $titolo_evento ) . '"? Facci sapere la tua opinione! ⭐';
        
        $mailer->send( $email_cliente, $subject, $message, array( 'Content-Type: text/html' ) );

        $nota = $is_single ? '⭐ Reinviata email manuale SINGOLA di richiesta recensione.' : '⭐ Inviata email di richiesta recensione/feedback al cliente.';
        $order->add_order_note( $nota );
        $order->update_meta_data('_cv_feedback_sent', 'yes');
        $order->save();
        $invii++;
    }

    wp_send_json_success( array( 'sent' => $invii, 'has_more' => $has_more ) );
}

add_action( 'wp_ajax_cv_process_live_scan', 'cv_process_live_scan_ajax' );
function cv_process_live_scan_ajax() {
    check_ajax_referer( 'cv_scanner_nonce_action', 'security' );

    if ( ! current_user_can( 'cv_use_scanner' ) ) wp_send_json_error( array( 'message' => 'Non hai i permessi necessari per scansionare i biglietti.' ) );

    $order_id     = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
    $ticket_index = isset( $_POST['ticket'] ) ? intval( $_POST['ticket'] ) : 0;
    $token        = isset( $_POST['token'] ) ? sanitize_text_field( $_POST['token'] ) : '';

    if ( ! $order_id || ! $token || ! $ticket_index ) wp_send_json_error( array( 'message' => 'Dati QR code incompleti.' ) );

    $order = wc_get_order( $order_id );
    if ( ! $order ) wp_send_json_error( array( 'message' => 'Ordine #' . $order_id . ' non trovato.' ) );

    if ( ! $order->has_status( array( 'processing', 'completed' ) ) ) {
        wp_send_json_error( array( 'message' => "<span style='font-size:30px;'>🚫 ORDINE NON VALIDO</span><br><br>I biglietti di questo ordine sono stati disattivati poiché l'ordine risulta annullato, rimborsato o non pagato." ) );
    }

    $expected_token = hash_hmac( 'sha256', $order->get_order_key() . '_ticket_' . $ticket_index, wp_salt('nonce') );
    if ( ! hash_equals( $expected_token, $token ) ) wp_send_json_error( array( 'message' => 'Token di sicurezza non valido (Possibile manomissione).' ) );

    $lock_key = 'cv_ticket_lock_' . $order_id . '_' . $ticket_index;
    if ( get_transient( $lock_key ) ) wp_send_json_error( array( 'message' => '⏳ Scansione già in elaborazione da un altro dispositivo.' ) );
    set_transient( $lock_key, 1, 5 ); 

    $nomi_eventi = array(); $tot_biglietti_ordine = 0;
    foreach ( $order->get_items() as $item ) { $nomi_eventi[] = $item->get_name(); $tot_biglietti_ordine += $item->get_quantity(); }
    $titolo_evento = implode( ' + ', $nomi_eventi );
    $nome_cliente = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $tavolo_assegnato = $order->get_meta('_cv_assigned_table');
    
    $meta_key = '_cv_ticket_validato_' . $ticket_index;
    $gia_entrato = $order->get_meta( $meta_key );

    if ( $gia_entrato === 'yes' ) {
        $orario = $order->get_meta( $meta_key . '_orario' );
        $operatore_id = $order->get_meta( $meta_key . '_operatore' );
        $nome_operatore = 'Sconosciuto';
        if ( $operatore_id ) { $user_info = get_userdata( $operatore_id ); if ( $user_info ) $nome_operatore = $user_info->display_name; }
        delete_transient( $lock_key );

        wp_send_json_error( array(
            'message' => "<span style='font-size:30px;'>❌ GIÀ ENTRATO</span><br><br><strong style='color:#333; font-size:18px;'>" . esc_html($titolo_evento) . "</strong><br><br>Il <strong>Biglietto {$ticket_index} di {$tot_biglietti_ordine}</strong> intestato a " . esc_html($nome_cliente) . " è già stato scansionato.<br><br>Data scansione: " . date_i18n( 'd/m/Y H:i', strtotime( $orario ) ) . "<br>Validato da: <strong>" . esc_html( $nome_operatore ) . "</strong>"
        ) );
    } else {
        $order->update_meta_data( $meta_key, 'yes' );
        $order->update_meta_data( $meta_key . '_orario', current_time( 'mysql' ) );
        $order->update_meta_data( $meta_key . '_operatore', get_current_user_id() );
        $order->save();
        delete_transient( $lock_key );

        $tavolo_html = $tavolo_assegnato ? "<div style='display:inline-block; padding:15px 20px; background:#fff3cd; border:2px solid #ffc107; border-radius:8px; color:#856404; font-size:20px; font-weight:bold; margin-bottom:15px;'>🍽️ TAVOLO:<br>" . esc_html($tavolo_assegnato) . "</div><br>" : "";

        wp_send_json_success( array(
            'message' => "<span style='font-size:30px;'>✅ ACCESSO VALIDO</span><br><br><span style='font-size:24px;'><strong>" . esc_html($nome_cliente) . "</strong></span><br><br>{$tavolo_html}<span style='font-size:18px; color:#2271b1;'><strong>Evento:</strong> " . esc_html($titolo_evento) . "</span><br><br><div style='display:inline-block; padding:10px 20px; background:#eaf7ea; border:2px solid green; border-radius:8px; font-size:20px; font-weight:bold;'>Ingresso per 1 Persona<br><small style='font-weight:normal; font-size:14px;'>(Biglietto {$ticket_index} di {$tot_biglietti_ordine})</small></div><br><br><small>Ordine #{$order_id}</small>"
        ) );
    }
}
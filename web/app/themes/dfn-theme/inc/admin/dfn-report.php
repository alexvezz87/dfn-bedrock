<?php
/**
 * DFN Booking System 2.0 — Administrative Reporting Dashboard
 *
 * Sostituisce cv-report.php offrendo agli amministratori report dettagliati
 * sui flussi dei visitatori, lo stato delle convalidazioni e i bilanci di cassa.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'dfn_admin_register_report_menu' );

/**
 * Registra il report amministrativo come sottomenu di FAI Prenotazioni.
 */
function dfn_admin_register_report_menu(): void {
    add_submenu_page(
        'dfn-events',
        esc_html__( 'Report & Cassa Ingressi', 'dfn-theme' ),
        esc_html__( 'Report Ingressi', 'dfn-theme' ),
        'dfn_manage_events',
        'dfn-report-checkin',
        'dfn_render_admin_report_page'
    );
}

/**
 * Renderizza la bacheca di reportistica amministrativa.
 */
function dfn_render_admin_report_page(): void {
    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        wp_die( esc_html__( 'Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme' ) );
    }

    global $wpdb;
    $selected_event_id = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;

    // Recupera la lista di tutti gli eventi configurati
    $table_events = $wpdb->prefix . 'dfn_events';
    $events = $wpdb->get_results( "SELECT * FROM {$table_events} ORDER BY event_date_start DESC" );

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 25px;">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-chart-bar"></span>
                <h1><?php esc_html_e( 'Report & Cassa Check-in', 'dfn-theme' ); ?></h1>
            </div>
        </header>

        <p style="font-size: 15px; color: #475569; margin-bottom: 20px;">
            <?php esc_html_e( 'Seleziona un evento per monitorare gli accessi dei gruppi in tempo reale e controllare i bilanci finanziari divisi per metodo d\'incasso.', 'dfn-theme' ); ?>
        </p>

        <!-- Filtro Evento -->
        <form method="GET" style="background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); display: inline-block; border: 1px solid #f1f5f9; margin-bottom: 30px;">
            <input type="hidden" name="page" value="dfn-report-checkin">
            <label style="font-weight: 700; color: #1e293b; margin-right: 12px; font-size: 14px;"><?php esc_html_e( 'Seleziona l\'evento:', 'dfn-theme' ); ?></label>
            <select name="event_id" style="min-width: 300px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; font-weight: 600; color: #334155; margin-right: 10px;">
                <option value="0">-- <?php esc_html_e( 'Seleziona un Evento', 'dfn-theme' ); ?> --</option>
                <?php foreach ( $events as $event ) : 
                    $p_name = get_the_title( $event->product_id ) ?: esc_html__( 'Evento', 'dfn-theme' );
                    ?>
                    <option value="<?php echo intval( $event->id ); ?>" <?php selected( $selected_event_id, $event->id ); ?>><?php echo esc_html( $p_name ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary" style="padding: 5px 18px; font-size: 14px; height: auto; font-weight: 700; background: #004b23; border: none; border-radius: 6px;"><?php esc_html_e( 'Carica Report', 'dfn-theme' ); ?></button>
        </form>

        <?php if ( $selected_event_id > 0 ) : 
            $table_bookings = $wpdb->prefix . 'dfn_bookings';
            
            // 1. Statistiche complessive dell'evento selezionato
            $total_persons = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(total_persons) FROM {$table_bookings} WHERE event_id = %d AND status != 'cancelled'",
                $selected_event_id
            ) ) ) ?: 0;

            $total_checked_in = intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(total_persons) FROM {$table_bookings} WHERE event_id = %d AND status = 'checked_in'",
                $selected_event_id
            ) ) ) ?: 0;

            $total_waiting = $total_persons - $total_checked_in;

            // 2. Breakdown Finanziario
            $online_income = floatval( $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(amount_paid) FROM {$table_bookings} WHERE event_id = %d AND payment_method != 'dfn_in_loco'",
                $selected_event_id
            ) ) ) ?: 0.00;

            $cash_income = floatval( $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(amount_paid) FROM {$table_bookings} WHERE event_id = %d AND payment_method = 'in_loco_cash'",
                $selected_event_id
            ) ) ) ?: 0.00;

            $pos_income = floatval( $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(amount_paid) FROM {$table_bookings} WHERE event_id = %d AND payment_method = 'in_loco_pos'",
                $selected_event_id
            ) ) ) ?: 0.00;

            $total_income = $online_income + $cash_income + $pos_income;

            // Carica tutte le prenotazioni per la tabella
            $bookings = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table_bookings} WHERE event_id = %d ORDER BY id DESC",
                $selected_event_id
            ) );
            ?>

            <!-- Widget Statistici -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 40px;">
                <div style="background: #f0f6fc; border: 1px solid #c8d7e1; border-radius: 12px; padding: 20px; border-left: 5px solid #2271b1;">
                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;"><?php esc_html_e( 'Visitatori Prenotati', 'dfn-theme' ); ?></span>
                    <div style="font-size: 32px; font-weight: 800; color: #2271b1; margin-top: 8px;"><?php echo $total_persons; ?></div>
                </div>
                <div style="background: #eaf7ea; border: 1px solid #c3e6c3; border-radius: 12px; padding: 20px; border-left: 5px solid #16a34a;">
                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;"><?php esc_html_e( 'Persone Entrate', 'dfn-theme' ); ?></span>
                    <div style="font-size: 32px; font-weight: 800; color: #16a34a; margin-top: 8px;"><?php echo $total_checked_in; ?></div>
                </div>
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 20px; border-left: 5px solid #d63638;">
                    <span style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;"><?php esc_html_e( 'Visitatori Attesi', 'dfn-theme' ); ?></span>
                    <div style="font-size: 32px; font-weight: 800; color: #d63638; margin-top: 8px;"><?php echo $total_waiting; ?></div>
                </div>
            </div>

            <!-- Bilanci di Cassa -->
            <div style="background: #fff; border-radius: 12px; padding: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 40px;">
                <h3 style="margin-top: 0; color: #004b23; font-weight: 800; font-size: 18px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;"><?php esc_html_e( 'Rendicontazione Cassa d\'Evento', 'dfn-theme' ); ?></h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-top: 20px;">
                    <div>
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;"><?php esc_html_e( '💳 Saldo Online (WooCommerce)', 'dfn-theme' ); ?></span>
                        <div style="font-size: 22px; font-weight: 800; color: #334155; margin-top: 5px;"><?php echo wc_price( $online_income ); ?></div>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;"><?php esc_html_e( '💵 Incassato Contanti in Loco', 'dfn-theme' ); ?></span>
                        <div style="font-size: 22px; font-weight: 800; color: #c69c3a; margin-top: 5px;"><?php echo wc_price( $cash_income ); ?></div>
                    </div>
                    <div>
                        <span style="font-size: 12px; color: #64748b; font-weight: 600;"><?php esc_html_e( '💳 Incassato POS/Carte in Loco', 'dfn-theme' ); ?></span>
                        <div style="font-size: 22px; font-weight: 800; color: #475569; margin-top: 5px;"><?php echo wc_price( $pos_income ); ?></div>
                    </div>
                    <div style="border-left: 2px dashed #cbd5e1; padding-left: 20px;">
                        <span style="font-size: 12px; color: #004b23; font-weight: 700;"><?php esc_html_e( '💰 Contributo Totale Raccolto', 'dfn-theme' ); ?></span>
                        <div style="font-size: 26px; font-weight: 800; color: #004b23; margin-top: 5px;"><?php echo wc_price( $total_income ); ?></div>
                    </div>
                </div>
            </div>

            <!-- Tabella delle Prenotazioni -->
            <div class="dfn-card dfn-main-card">
                <div class="dfn-card-header">
                    <h2><?php esc_html_e( 'Dettaglio Ingressi e Presenze', 'dfn-theme' ); ?></h2>
                    <span class="dfn-count-badge"><?php echo count( $bookings ); ?> <?php esc_html_e( 'Gruppi Prenotati', 'dfn-theme' ); ?></span>
                </div>

                <table class="wp-list-table widefat fixed striped dfn-events-table" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Ordine', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Nome Cliente / Capogruppo', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Contatti', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Ingressi Standard', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Ingressi FAI', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Stato Ingressi', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Metodo di Pagamento', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Incasso Riscosso', 'dfn-theme' ); ?></th>
                            <th><?php esc_html_e( 'Ora Validazione', 'dfn-theme' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $bookings ) ) : ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 25px; color: #94a3b8;"><?php esc_html_e( 'Nessuna prenotazione trovata per questo evento.', 'dfn-theme' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $bookings as $b ) : 
                                $status_badge_class = ( $b->status === 'checked_in' ) ? 'dfn-status-published' : 'dfn-status-draft';
                                $status_label       = ( $b->status === 'checked_in' ) ? esc_html__( 'Checked-in', 'dfn-theme' ) : esc_html__( 'Attesa', 'dfn-theme' );
                                $payment_method_label = '';
                                if ( $b->payment_method === 'in_loco_cash' ) $payment_method_label = '💵 Cassa (Contanti)';
                                elseif ( $b->payment_method === 'in_loco_pos' ) $payment_method_label = '💳 Cassa (POS)';
                                elseif ( $b->payment_method === 'dfn_in_loco' ) $payment_method_label = '🕒 Cassa (Sospeso)';
                                else $payment_method_label = '💳 Online';
                                ?>
                                <tr>
                                    <td>
                                        <strong><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $b->order_id . '&action=edit' ) ); ?>">#<?php echo intval( $b->order_id ); ?></a></strong>
                                    </td>
                                    <td><strong><?php echo esc_html( $b->customer_name ); ?></strong></td>
                                    <td>
                                        <div><?php echo esc_html( $b->customer_email ); ?></div>
                                        <div style="font-size: 11px; color: #64748b;"><?php echo esc_html( $b->customer_phone ); ?></div>
                                    </td>
                                    <td><?php echo intval( $b->persons_standard ); ?></td>
                                    <td><?php echo intval( $b->persons_fai ); ?></td>
                                    <td>
                                        <span class="dfn-badge <?php echo esc_attr( $status_badge_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
                                    </td>
                                    <td><?php echo esc_html( $payment_method_label ); ?></td>
                                    <td><strong><?php echo wc_price( $b->amount_paid ); ?></strong></td>
                                    <td>
                                        <?php if ( $b->checked_in_at ) : 
                                            echo date_i18n( 'd/m - H:i:s', strtotime( $b->checked_in_at ) );
                                        else :
                                            echo '-';
                                        endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

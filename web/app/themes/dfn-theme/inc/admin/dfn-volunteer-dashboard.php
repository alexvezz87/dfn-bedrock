<?php
/**
 * DFN Booking System 2.0 — Volunteer Shift Dashboard
 *
 * Fornisce ai volontari una bacheca di monitoraggio immediata del proprio turno,
 * conteggiando ingressi convalidati e somme riscosse per POS o Contanti.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'dfn_volunteer_register_dashboard_menu' );

/**
 * Registra la bacheca del turno per il personale volontario.
 */
function dfn_volunteer_register_dashboard_menu(): void {
    add_submenu_page(
        'dfn-events',
        esc_html__( 'Bacheca Turno Volontario', 'dfn-theme' ),
        esc_html__( 'Bacheca Turno', 'dfn-theme' ),
        'dfn_use_scanner',
        'dfn-volunteer-dashboard',
        'dfn_render_volunteer_dashboard'
    );
}

/**
 * Renderizza la dashboard del volontario con le statistiche in tempo reale.
 */
function dfn_render_volunteer_dashboard(): void {
    if ( ! current_user_can( 'dfn_use_scanner' ) ) {
        wp_die( esc_html__( 'Non hai i permessi per accedere a questa bacheca.', 'dfn-theme' ) );
    }

    global $wpdb;
    $current_user_id = get_current_user_id();
    $user_info       = get_userdata( $current_user_id );
    $display_name    = $user_info ? $user_info->display_name : esc_html__( 'Volontario FAI', 'dfn-theme' );

    // Ingressi e somme riscosse oggi dal volontario
    $today_start = date( 'Y-m-d 00:00:00' );
    $today_end   = date( 'Y-m-d 23:59:59' );

    // Ingressi totali (persone) convalidate
    $total_checked_in_persons = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(total_persons) FROM {$wpdb->prefix}dfn_bookings 
         WHERE checked_in_by = %d 
           AND status = 'checked_in' 
           AND checked_in_at BETWEEN %s AND %s",
        $current_user_id, $today_start, $today_end
    ) ) ) ?: 0;

    // Transazioni/gruppi scansionati
    $total_groups_scanned = intval( $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_bookings 
         WHERE checked_in_by = %d 
           AND status = 'checked_in' 
           AND checked_in_at BETWEEN %s AND %s",
        $current_user_id, $today_start, $today_end
    ) ) ) ?: 0;

    // Riscossioni in Loco Contanti
    $cash_collected = floatval( $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(amount_paid) FROM {$wpdb->prefix}dfn_bookings 
         WHERE checked_in_by = %d 
           AND payment_method = 'in_loco_cash' 
           AND checked_in_at BETWEEN %s AND %s",
        $current_user_id, $today_start, $today_end
    ) ) ) ?: 0.00;

    // Riscossioni in Loco POS
    $pos_collected = floatval( $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(amount_paid) FROM {$wpdb->prefix}dfn_bookings 
         WHERE checked_in_by = %d 
           AND payment_method = 'in_loco_pos' 
           AND checked_in_at BETWEEN %s AND %s",
        $current_user_id, $today_start, $today_end
    ) ) ) ?: 0.00;

    $total_collected = $cash_collected + $pos_collected;

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 30px;">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-businessman"></span>
                <h1><?php esc_html_e( 'Bacheca Turno Volontario FAI', 'dfn-theme' ); ?></h1>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=dfn-scanner-live' ) ); ?>" class="page-title-action dfn-btn dfn-btn-primary" style="background: #004b23; border: none; font-size: 15px; padding: 10px 20px;">
                <span class="dashicons dashicons-camera"></span> <?php esc_html_e( 'Avvia Scanner Fotocamera', 'dfn-theme' ); ?>
            </a>
        </header>

        <div style="background: linear-gradient(135deg, #004b23 0%, #111b15 100%); border-radius: 16px; padding: 30px; color: #fff; box-shadow: 0 10px 30px rgba(0,75,35,0.1); margin-bottom: 30px; position: relative; overflow: hidden;">
            <div style="position: absolute; right: -20px; bottom: -20px; font-size: 180px; opacity: 0.08; pointer-events: none;">🌳</div>
            <h2 style="margin-top: 0; color: #c69c3a; font-weight: 800; font-size: 24px;"><?php printf( esc_html__( 'Benvenuto, %s', 'dfn-theme' ), esc_html( $display_name ) ); ?></h2>
            <p style="font-size: 16px; line-height: 1.6; max-width: 700px; margin-bottom: 0; color: #e2f0e7;">
                <?php esc_html_e( 'Questa bacheca mostra in tempo reale le statistiche del tuo turno di presidio per la giornata di oggi. Usa il pulsante in alto per convalidare i biglietti dei visitatori all\'ingresso e riscuotere eventuali quote.', 'dfn-theme' ); ?>
            </p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 40px;">
            <!-- Gruppi Scansionati -->
            <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-left: 5px solid #004b23;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 1px;"><?php esc_html_e( 'Gruppi Scansionati', 'dfn-theme' ); ?></span>
                <div style="font-size: 36px; font-weight: 800; color: #004b23; margin-top: 10px;"><?php echo $total_groups_scanned; ?></div>
                <div style="font-size: 13px; color: #94a3b8; margin-top: 5px;"><?php esc_html_e( 'Codici QR validati oggi', 'dfn-theme' ); ?></div>
            </div>

            <!-- Ingressi Effettuati -->
            <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-left: 5px solid #38b000;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 1px;"><?php esc_html_e( 'Persone Convalidate', 'dfn-theme' ); ?></span>
                <div style="font-size: 36px; font-weight: 800; color: #38b000; margin-top: 10px;"><?php echo $total_checked_in_persons; ?></div>
                <div style="font-size: 13px; color: #94a3b8; margin-top: 5px;"><?php esc_html_e( 'Visitatori entrati oggi', 'dfn-theme' ); ?></div>
            </div>

            <!-- Cassa Contanti -->
            <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-left: 5px solid #c69c3a;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 1px;"><?php esc_html_e( 'Cassa Contanti (💵)', 'dfn-theme' ); ?></span>
                <div style="font-size: 36px; font-weight: 800; color: #c69c3a; margin-top: 10px;"><?php echo wc_price( $cash_collected ); ?></div>
                <div style="font-size: 13px; color: #94a3b8; margin-top: 5px;"><?php esc_html_e( 'Incasso fisico riscosso oggi', 'dfn-theme' ); ?></div>
            </div>

            <!-- Cassa POS -->
            <div style="background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-left: 5px solid #475569;">
                <span style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: 1px;"><?php esc_html_e( 'Cassa Elettronica / POS (💳)', 'dfn-theme' ); ?></span>
                <div style="font-size: 36px; font-weight: 800; color: #475569; margin-top: 10px;"><?php echo wc_price( $pos_collected ); ?></div>
                <div style="font-size: 13px; color: #94a3b8; margin-top: 5px;"><?php esc_html_e( 'Incasso elettronico riscosso oggi', 'dfn-theme' ); ?></div>
            </div>
        </div>

        <div style="background: #fff; border-radius: 12px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
            <h3 style="margin-top: 0; color: #004b23; font-weight: 800; font-size: 18px; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;"><?php esc_html_e( 'Riconciliazione Somme di Fine Turno', 'dfn-theme' ); ?></h3>
            <p style="font-size: 14px; color: #475569; line-height: 1.5; margin-bottom: 20px;">
                <?php esc_html_e( 'Al termine del tuo turno, confronta il saldo monetario con le cifre qui esposte. Eventuali differenze devono essere tempestivamente segnalate al responsabile organizzativo FAI della sede.', 'dfn-theme' ); ?>
            </p>
            <div style="background: #f8fafc; border-radius: 8px; padding: 20px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #e2e8f0;">
                <span style="font-size: 15px; font-weight: 700; color: #1e293b;"><?php esc_html_e( 'Totale Riscosso in Loco (Contanti + POS):', 'dfn-theme' ); ?></span>
                <strong style="font-size: 24px; color: #004b23;"><?php echo wc_price( $total_collected ); ?></strong>
            </div>
        </div>
    </div>
    <?php
}

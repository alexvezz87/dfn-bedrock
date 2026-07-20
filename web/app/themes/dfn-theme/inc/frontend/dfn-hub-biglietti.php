<?php
/**
 * DFN Booking System 2.0 — Premium Monolithic Group Ticket Hub
 *
 * Sostituisce cv-hub-biglietti.php fornendo un unico QR Code di gruppo,
 * evitando le code e massimizzando l'efficienza d'ingresso.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Intercetta la richiesta dell'hub biglietti o del download
add_action('template_redirect', 'dfn_render_group_ticket_hub');
add_action('template_redirect', 'dfn_handle_qr_download');
add_action('template_redirect', 'dfn_handle_visitor_cancellation');
add_action('template_redirect', 'dfn_handle_visitor_modification');

/**
 * Gestisce il rendering della pagina dell'Hub Biglietti di Gruppo.
 */
function dfn_render_group_ticket_hub(): void
{
    if (! isset($_GET['dfn_hub']) || ! isset($_GET['order_id']) || ! isset($_GET['token'])) {
        return;
    }

    $order_id = intval($_GET['order_id']);
    $token    = sanitize_text_field($_GET['token']);

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_die(esc_html__('Ordine non trovato.', 'dfn-theme'), esc_html__('Errore', 'dfn-theme'), 404);
    }

    // Verifica token di sicurezza transazionale
    $expected_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
    if (! hash_equals($expected_token, $token)) {
        wp_die(esc_html__('Link non valido o scaduto.', 'dfn-theme'), esc_html__('Errore di sicurezza', 'dfn-theme'), 403);
    }

    // Recupera la prenotazione collegata a questo ordine
    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id,
    ));

    if (! $booking) {
        wp_die(esc_html__('Nessuna prenotazione custom associata a questo ordine nel database.', 'dfn-theme'));
    }

    // Se l'ordine è annullato, rimborsato o non pagato (escluso in loco pending)
    $payment_method = $order->get_payment_method();
    $is_valid_status = $order->has_status([ 'processing', 'completed' ]) || ($payment_method === 'dfn_in_loco' && $order->has_status('pending'));

    if (! $is_valid_status) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . esc_html__('Ordine Non Valido', 'dfn-theme') . '</title>';
        echo '<style>body { font-family: sans-serif; background: #f3f7f4; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 80vh; } .card { background: #fff; padding: 40px; border-radius: 16px; text-align: center; max-width: 500px; box-shadow: 0 10px 30px rgba(0,75,35,0.05); }</style></head><body>';
        echo '<div class="card">';
        echo '<h1 style="color: #dc2626; margin-top: 0;">🚫 ' . esc_html__('Prenotazione Non Valida', 'dfn-theme') . '</h1>';
        echo '<p style="font-size: 16px; color: #64748b; line-height: 1.6;">' . esc_html__('La prenotazione cercata non è disponibile. L\'ordine potrebbe essere stato annullato, scaduto o rimborsato.', 'dfn-theme') . '</p>';
        echo '<a href="' . esc_url(home_url()) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #004b23; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold;">' . esc_html__('Torna alla Home', 'dfn-theme') . '</a>';
        echo '</div></body></html>';
        exit;
    }

    // Dati dell'evento
    $event_title = get_the_title($booking->event_id) ?: esc_html__('Evento FAI', 'dfn-theme');
    $event       = dfn_db_get_event($booking->event_id);
    $location    = $event ? $event->location : esc_html__('Bene FAI', 'dfn-theme');
    $date_start  = $event ? date_i18n('d M Y', strtotime($event->event_date_start)) : '';

    // Recupera tutti i turni per questo booking
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, bs.persons, bs.checked_in_at FROM {$wpdb->prefix}dfn_event_slots s 
         JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id 
         WHERE bs.booking_id = %d 
         ORDER BY s.slot_time_start ASC",
        $booking->id,
    ));

    // Enqueue degli stili dedicati
    wp_enqueue_style('dfn-visitor-dashboard-css', get_stylesheet_directory_uri() . '/assets/css/dfn-visitor-dashboard.css', [], '2.0.0');

    $wa_text = urlencode(sprintf(__('Ecco le prenotazioni di gruppo per %s. Mostra questi QR Code all\'ingresso: %s', 'dfn-theme'), $event_title, home_url('/?dfn_hub=1&order_id=' . $order_id . '&token=' . $token)));
    $download_url = home_url('/?dfn_download_qr=1&order_id=' . $order_id . '&token=' . $token);
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php esc_html_e('Le tue Prenotazioni — FAI Prenotazioni', 'dfn-theme'); ?></title>
        <?php wp_head(); ?>
        <style>
            /* Stili aggiuntivi per il layout split dei ticket in stampa e desktop */
            .dfn-ticket-slot-card {
                background: #ffffff;
                border: 2px solid #e2e8f0;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 24px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            }
            .dfn-ticket-slot-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px dashed #cbd5e1;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            .dfn-ticket-slot-time {
                font-size: 18px;
                font-weight: 700;
                color: #004b23;
            }
            .dfn-ticket-slot-qty {
                font-weight: 600;
                background: #f1f5f9;
                padding: 4px 10px;
                border-radius: 6px;
                font-size: 13px;
                color: #475569;
            }
            @media print {
                .dfn-ticket-slot-card {
                    page-break-inside: avoid;
                    border: 1px solid #000;
                }
            }
        </style>
    </head>
    <body>
        <div class="dfn-hub-wrapper">
            <div class="dfn-hub-card">
                <div class="dfn-hub-header-decor"></div>
                
                <h1 class="dfn-hub-title"><?php esc_html_e('🎟️ I tuoi Ingressi di Gruppo', 'dfn-theme'); ?></h1>
                <p class="dfn-hub-subtitle"><?php printf(esc_html__('Ordine #%d — Gestione Ingressi Centralizzata', 'dfn-theme'), $order_id); ?></p>

                <div class="dfn-hub-info-box">
                    <h3 class="dfn-hub-info-title"><?php echo esc_html($event_title); ?></h3>
                    <div class="dfn-hub-info-detail">📍 <strong><?php esc_html_e('Luogo:', 'dfn-theme'); ?></strong> <?php echo esc_html($location); ?></div>
                    <div class="dfn-hub-info-detail">📅 <strong><?php esc_html_e('Data:', 'dfn-theme'); ?></strong> <?php echo esc_html($date_start); ?></div>
                    <div class="dfn-hub-info-detail">👥 <strong><?php esc_html_e('Intestato a:', 'dfn-theme'); ?></strong> <?php echo esc_html($booking->customer_name); ?></div>
                    <div class="dfn-hub-info-detail" style="margin-top: 10px; border-top: 1px dashed var(--dfn-gray-medium); padding-top: 10px; font-size: 13px;">
                        👥 Breakdown Totale: <?php printf(esc_html__('%d Standard + %d Soci FAI', 'dfn-theme'), intval($booking->persons_standard), intval($booking->persons_fai)); ?>
                    </div>
                </div>

                <div style="margin-top: 30px;">
                    <?php
                    $is_paid = $order->has_status([ 'processing', 'completed' ]) || floatval($order->get_total()) === 0.00;

                    if ($is_paid && intval($booking->total_persons) > 1) :
                        // Generazione singoli QR code (1 per biglietto/persona)
                        $tickets = [];
                        $ticket_num = 1;
                        if (! empty($slots)) {
                            foreach ($slots as $s) {
                                $slot_time_info = 'ore ' . date('H:i', strtotime($s->slot_time_start));
                                for ($k = 0; $k < intval($s->persons); $k++) {
                                    $tickets[] = [
                                        'index' => $ticket_num++,
                                        'slot_id' => $s->slot_id,
                                        'info' => $slot_time_info,
                                    ];
                                }
                            }
                        } else {
                            for ($k = 0; $k < intval($booking->total_persons); $k++) {
                                $tickets[] = [
                                    'index' => $ticket_num++,
                                    'slot_id' => 0,
                                    'info' => esc_html__('Ingresso Libero', 'dfn-theme'),
                                ];
                            }
                        }

                        foreach ($tickets as $t) :
                            $qr_token_ticket = $booking->qr_token . '-ticket-' . $t['index'];
                            $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_token_ticket) . '&margin=10';
                            $download_url_ticket = home_url('/?dfn_download_qr=1&order_id=' . $order_id . '&token=' . $token . '&ticket_index=' . $t['index']);
                            $is_ticket_checked_in = $order->get_meta('_cv_ticket_validato_' . $t['index']) === 'yes';
                            ?>
                            <div class="dfn-ticket-slot-card">
                                <div class="dfn-ticket-slot-header">
                                    <span class="dfn-ticket-slot-time">🎫 Biglietto <?php echo $t['index']; ?> di <?php echo $booking->total_persons; ?></span>
                                    <span class="dfn-ticket-slot-qty">⏰ <?php echo esc_html($t['info']); ?></span>
                                </div>
                                
                                <?php if ($is_ticket_checked_in) : ?>
                                    <div style="text-align: center; padding: 20px 0;">
                                        <span class="dfn-badge-validated" style="display: inline-block; margin: 0;"><?php esc_html_e('✅ CONVALIDATO / ENTRATO', 'dfn-theme'); ?></span>
                                    </div>
                                <?php else : ?>
                                    <div class="dfn-hub-qr-container">
                                        <img src="<?php echo esc_url($qr_api_url); ?>" class="dfn-hub-qr-image" alt="<?php esc_attr_e('Codice QR Singolo d\'Ingresso', 'dfn-theme'); ?>" />
                                    </div>
                                    <div style="text-align: center; margin-top: 10px;" class="dfn-no-print">
                                        <a href="<?php echo esc_url($download_url_ticket); ?>" style="font-size: 13px; color: #004b23; font-weight: 600; text-decoration: underline;">
                                            💾 Scarica QR di questo biglietto
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                    <?php else : ?>
                        <?php // Gestione classica con un solo QR di gruppo (per In Loco / Non pagato / Booking singolo) ?>
                        <?php if (! empty($slots)) : ?>
                            <?php foreach ($slots as $s) :
                                $qr_token_slot = $booking->qr_token . '-' . $s->slot_id;
                                $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($qr_token_slot) . '&margin=10';
                                $download_url_slot = home_url('/?dfn_download_qr=1&order_id=' . $order_id . '&token=' . $token . '&slot_id=' . $s->slot_id);
                                $is_slot_checked_in = ! empty($s->checked_in_at);
                                ?>
                                <div class="dfn-ticket-slot-card">
                                    <div class="dfn-ticket-slot-header">
                                        <span class="dfn-ticket-slot-time">⏰ Turno: ore <?php echo date('H:i', strtotime($s->slot_time_start)); ?></span>
                                        <span class="dfn-ticket-slot-qty">👥 <?php printf(esc_html(_n('%d Persona', '%d Persone', $s->persons, 'dfn-theme')), intval($s->persons)); ?></span>
                                    </div>
                                    
                                    <?php if ($is_slot_checked_in) : ?>
                                        <div style="text-align: center; padding: 20px 0;">
                                            <span class="dfn-badge-validated" style="display: inline-block; margin: 0;"><?php esc_html_e('✅ ENTRATO / CONVALIDATO', 'dfn-theme'); ?></span>
                                        </div>
                                    <?php else : ?>
                                        <div class="dfn-hub-qr-container">
                                            <img src="<?php echo esc_url($qr_api_url); ?>" class="dfn-hub-qr-image" alt="<?php esc_attr_e('Codice QR d\'Ingresso', 'dfn-theme'); ?>" />
                                        </div>
                                        <div style="text-align: center; margin-top: 10px;" class="dfn-no-print">
                                            <a href="<?php echo esc_url($download_url_slot); ?>" style="font-size: 13px; color: #004b23; font-weight: 600; text-decoration: underline;">
                                                💾 Scarica QR di questo turno
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else :
                            // Fallback ingresso libero
                            $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($booking->qr_token) . '&margin=10';
                            ?>
                            <div class="dfn-ticket-slot-card">
                                <div class="dfn-ticket-slot-header">
                                    <span class="dfn-ticket-slot-time">🎟️ Ingresso Libero</span>
                                    <span class="dfn-ticket-slot-qty">👥 <?php printf(esc_html__('%d Ingressi', 'dfn-theme'), intval($booking->total_persons)); ?></span>
                                </div>
                                <?php if ($booking->status === 'checked_in') : ?>
                                    <div style="text-align: center; padding: 20px 0;">
                                        <span class="dfn-badge-validated" style="display: inline-block; margin: 0;"><?php esc_html_e('✅ ENTRATO / CONVALIDATO', 'dfn-theme'); ?></span>
                                    </div>
                                <?php else : ?>
                                    <div class="dfn-hub-qr-container">
                                        <img src="<?php echo esc_url($qr_api_url); ?>" class="dfn-hub-qr-image" alt="<?php esc_attr_e('Codice QR d\'Ingresso', 'dfn-theme'); ?>" />
                                    </div>
                                    <div style="text-align: center; margin-top: 10px;" class="dfn-no-print">
                                        <a href="<?php echo esc_url($download_url); ?>" style="font-size: 13px; color: #004b23; font-weight: 600; text-decoration: underline;">
                                            💾 Scarica QR
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="dfn-hub-buttons dfn-no-print">
                    <?php if ($booking->status !== 'checked_in') : ?>
                        <a href="https://wa.me/?text=<?php echo $wa_text; ?>" target="_blank" class="dfn-hub-btn dfn-hub-btn-wa">
                            💬 <?php esc_html_e('Condividi su WhatsApp', 'dfn-theme'); ?>
                        </a>
                        <a href="<?php echo esc_url($download_url); ?>" class="dfn-hub-btn dfn-hub-btn-save">
                            ⬇️ <?php esc_html_e('Salva QR come Immagine', 'dfn-theme'); ?>
                        </a>
                    <?php endif; ?>
                    <button onclick="window.print();" class="dfn-hub-btn dfn-hub-btn-print">
                        🖨️ <?php esc_html_e('Stampa Ricevuta Prenotazione', 'dfn-theme'); ?>
                    </button>
                </div>
            </div>
            
            <div class="dfn-no-print" style="margin-top: 20px;">
                <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" style="color: var(--dfn-text-muted); text-decoration: none; font-weight: 600; font-size: 14px;">
                    ← <?php esc_html_e('Torna alle tue Prenotazioni', 'dfn-theme'); ?>
                </a>
            </div>
        </div>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Gestisce lo scaricamento forzato in formato PNG dell'immagine del QR code.
 */
function dfn_handle_qr_download(): void
{
    if (! isset($_GET['dfn_download_qr']) || ! isset($_GET['order_id']) || ! isset($_GET['token'])) {
        return;
    }

    $order_id = intval($_GET['order_id']);
    $token    = sanitize_text_field($_GET['token']);

    $order = wc_get_order($order_id);
    if (! $order) {
        return;
    }

    $expected_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
    if (! hash_equals($expected_token, $token)) {
        return;
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id,
    ));

    if (! $booking || empty($booking->qr_token)) {
        return;
    }

    $slot_id = isset($_GET['slot_id']) ? intval($_GET['slot_id']) : 0;
    $ticket_index = isset($_GET['ticket_index']) ? intval($_GET['ticket_index']) : 0;
    $qr_data = $booking->qr_token;
    $filename = 'Ingresso-Gruppo-Ordine-' . $order_id . '.png';

    if ($ticket_index > 0) {
        $qr_data .= '-ticket-' . $ticket_index;
        $filename = 'Biglietto-' . $order_id . '-Posto-' . $ticket_index . '.png';
    } elseif ($slot_id > 0) {
        $qr_data .= '-' . $slot_id;
        $filename = 'Ingresso-Turno-' . $slot_id . '-Ordine-' . $order_id . '.png';
    }

    $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' . urlencode($qr_data) . '&margin=20';
    $response   = wp_remote_get($qr_api_url, [ 'timeout' => 12 ]);

    if (is_wp_error($response)) {
        wp_die(esc_html__('Impossibile scaricare l\'immagine in questo momento.', 'dfn-theme'));
    }

    $image_data   = wp_remote_retrieve_body($response);
    $content_type = wp_remote_retrieve_header($response, 'content-type');

    if (empty($image_data)) {
        return;
    }

    $filename = 'Ingresso-Gruppo-Ordine-' . $order_id . '.png';
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($image_data));
    echo $image_data;
    exit;
}

/**
 * Gestisce l'annullamento autonomo della prenotazione da parte del visitatore.
 */
function dfn_handle_visitor_cancellation(): void
{
    if (! isset($_GET['dfn_cancel_booking']) || ! isset($_GET['order_id']) || ! isset($_GET['token'])) {
        return;
    }

    $order_id = intval($_GET['order_id']);
    $token    = sanitize_text_field($_GET['token']);

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_die(esc_html__('Ordine non trovato.', 'dfn-theme'), esc_html__('Errore', 'dfn-theme'), 404);
    }

    // Verifica token di sicurezza per evitare annullamenti non autorizzati
    $expected_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
    if (! hash_equals($expected_token, $token)) {
        wp_die(esc_html__('Link di cancellazione non valido o scaduto.', 'dfn-theme'), esc_html__('Errore di sicurezza', 'dfn-theme'), 403);
    }

    // Se la prenotazione è già annullata
    if ($order->has_status('cancelled')) {
        wp_die(esc_html__('Questa prenotazione è già stata annullata in precedenza.', 'dfn-theme'), esc_html__('Prenotazione Annullata', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id,
    ));

    if (! $booking) {
        wp_die(esc_html__('Nessuna prenotazione custom associata a questo ordine nel database.', 'dfn-theme'));
    }

    // --- CHIEDI CONFERMA DI ANNULLAMENTO SE MANCA IL PARAMETRO DI CONFERMA ---
    if (! isset($_GET['confirm_cancel'])) {
        $event_title = get_the_title($booking->event_id) ?: esc_html__('Evento FAI', 'dfn-theme');
        $booking_date = '';

        $booking_details = $wpdb->get_results($wpdb->prepare(
            "SELECT s.slot_date FROM {$wpdb->prefix}dfn_booking_slots bs
             JOIN {$wpdb->prefix}dfn_event_slots s ON bs.slot_id = s.id
             WHERE bs.booking_id = %d",
            $booking->id
        ));

        if (! empty($booking_details)) {
            $dates = [];
            foreach ($booking_details as $b_det) {
                $dates[] = date_i18n('d/m/Y', strtotime($b_det->slot_date));
            }
            $booking_date = implode(', ', array_unique($dates));
        } else {
            $meta_date = $order->get_meta('_dfn_booking_date');
            if (! empty($meta_date)) {
                $booking_date = date_i18n('d/m/Y', strtotime($meta_date));
            }
        }

        $confirm_url = home_url('/?dfn_cancel_booking=1&order_id=' . $order_id . '&token=' . $token . '&confirm_cancel=1');
        $keep_url    = home_url();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php esc_html_e('Conferma Annullamento Prenotazione', 'dfn-theme'); ?></title>
            <style>
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; 
                    background: #f4f7f6; 
                    padding: 20px; 
                    display: flex; 
                    justify-content: center; 
                    align-items: center; 
                    min-height: 80vh; 
                    margin: 0;
                } 
                .card { 
                    background: #fff; 
                    padding: 40px 30px; 
                    border-radius: 16px; 
                    text-align: center; 
                    max-width: 500px; 
                    width: 100%;
                    box-shadow: 0 10px 30px rgba(0, 75, 35, 0.05); 
                    box-sizing: border-box;
                    border-top: 4px solid #d32f2f;
                }
                .warning-icon {
                    font-size: 54px;
                    margin-bottom: 20px;
                }
                h1 {
                    color: #1d2327;
                    margin-top: 0;
                    font-size: 22px;
                    font-weight: 700;
                    margin-bottom: 15px;
                }
                p {
                    font-size: 16px; 
                    color: #4b5563; 
                    line-height: 1.6;
                    margin-bottom: 30px;
                }
                .btn-group {
                    display: flex;
                    gap: 15px;
                    justify-content: center;
                    flex-wrap: wrap;
                }
                .btn {
                    padding: 12px 24px;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: bold;
                    font-size: 15px;
                    display: inline-block;
                    transition: transform 0.1s, opacity 0.2s;
                }
                .btn:active {
                    transform: scale(0.98);
                }
                .btn-confirm {
                    background: #d32f2f;
                    color: #fff;
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 6px rgba(211, 47, 47, 0.2);
                }
                .btn-confirm:hover {
                    background: #b71c1c;
                }
                .btn-keep {
                    background: #004b23;
                    color: #fff;
                    box-shadow: 0 4px 6px rgba(0, 75, 35, 0.2);
                }
                .btn-keep:hover {
                    background: #003619;
                }
            </style>
        </head>
        <body>
        <div class="card">
            <div class="warning-icon">⚠️</div>
            <h1><?php esc_html_e('Annulla Prenotazione', 'dfn-theme'); ?></h1>
            <p>
                <?php 
                echo sprintf(
                    esc_html__('Sei sicuro di voler annullare la tua prenotazione per l\'evento %s in data %s?', 'dfn-theme'),
                    '<strong>' . esc_html($event_title) . '</strong>',
                    '<strong>' . esc_html($booking_date) . '</strong>'
                ); 
                ?>
                <br><br>
                <span style="color: #64748b; font-size: 14px;"><?php esc_html_e('Attenzione: questa operazione è irreversibile e i posti verranno riaperti al pubblico.', 'dfn-theme'); ?></span>
            </p>
            <div class="btn-group">
                <a href="<?php echo esc_url($confirm_url); ?>" class="btn btn-confirm"><?php esc_html_e('Sì, annulla', 'dfn-theme'); ?></a>
                <a href="<?php echo esc_url($keep_url); ?>" class="btn btn-keep"><?php esc_html_e('No, mantieni', 'dfn-theme'); ?></a>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    dfn_cancel_booking_by_id($booking->id, __('Prenotazione annullata autonomamente dal visitatore tramite link email.', 'dfn-theme'));

    echo '</div></body></html>';
    exit;
}

/**
 * Gestisce la modifica autonoma (riduzione partecipanti) della prenotazione da parte del visitatore.
 */
function dfn_handle_visitor_modification(): void
{
    if (! isset($_GET['dfn_modify_booking']) || ! isset($_GET['order_id']) || ! isset($_GET['token'])) {
        return;
    }

    $order_id = intval($_GET['order_id']);
    $token    = sanitize_text_field($_GET['token']);

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_die(esc_html__('Ordine non trovato.', 'dfn-theme'), esc_html__('Errore', 'dfn-theme'), 404);
    }

    // Verifica token di sicurezza per evitare modifiche non autorizzate
    $expected_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_modify', wp_salt('nonce'));
    if (! hash_equals($expected_token, $token)) {
        wp_die(esc_html__('Link di modifica non valido o scaduto.', 'dfn-theme'), esc_html__('Errore di sicurezza', 'dfn-theme'), 403);
    }

    // Se l'ordine è già annullato, rimborsato o fallito, non consentiamo modifiche
    if ($order->has_status([ 'cancelled', 'refunded', 'failed' ])) {
        wp_die(esc_html__('Questa prenotazione è stata annullata o non è più valida, pertanto non può essere modificata.', 'dfn-theme'), esc_html__('Modifica Non Consentita', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id,
    ));

    if (! $booking) {
        wp_die(esc_html__('Nessuna prenotazione custom associata a questo ordine nel database.', 'dfn-theme'));
    }

    $event_title = get_the_title($booking->event_id) ?: esc_html__('Evento FAI', 'dfn-theme');

    // Se POST, elabora il salvataggio
    if (isset($_POST['confirm_modify'])) {
        $new_qty_standard = isset($_POST['new_qty_standard']) ? intval($_POST['new_qty_standard']) : 0;
        $new_qty_fai      = isset($_POST['new_qty_fai']) ? intval($_POST['new_qty_fai']) : 0;

        // Validazione dei quantitativi
        if ($new_qty_standard < 0 || $new_qty_fai < 0) {
            wp_die(esc_html__('Quantità non valide.', 'dfn-theme'));
        }

        if ($new_qty_standard > intval($booking->persons_standard) || $new_qty_fai > intval($booking->persons_fai)) {
            wp_die(esc_html__('Errore: non è consentito aumentare il numero di partecipanti. Puoi solo ridurre o mantenere le quantità.', 'dfn-theme'));
        }

        $new_total_qty = $new_qty_standard + $new_qty_fai;
        if ($new_total_qty < 1) {
            wp_die(esc_html__('Errore: la prenotazione deve contenere almeno 1 partecipante. Se desideri annullarla completamente, usa il link di annullamento.', 'dfn-theme'));
        }

        dfn_process_booking_modification($booking, $order, $new_qty_standard, $new_qty_fai);

        // Invia email di notifica modifica all'utente e all'amministratore
        if (function_exists('dfn_send_booking_modification_notifications')) {
            dfn_send_booking_modification_notifications($booking->id);
        }

        // Render della pagina di successo
        $hub_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
        $hub_url = add_query_arg([
            'dfn_hub'  => 1,
            'order_id' => $order_id,
            'token'    => $hub_token,
        ], home_url('/'));

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . esc_html__('Prenotazione Aggiornata', 'dfn-theme') . '</title>';
        echo '<style>body { font-family: sans-serif; background: #f3f7f4; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 80vh; } .card { background: #fff; padding: 40px; border-radius: 16px; text-align: center; max-width: 500px; box-shadow: 0 10px 30px rgba(0,75,35,0.05); }</style></head><body>';
        echo '<div class="card">';
        echo '<h1 style="color: #004b23; margin-top: 0;">✅ ' . esc_html__('Modifica Completata', 'dfn-theme') . '</h1>';
        echo '<p style="font-size: 16px; color: #64748b; line-height: 1.6;">' . esc_html__('La tua prenotazione è stata aggiornata correttamente. Ti abbiamo inviato una nuova e-mail di conferma con i dettagli aggiornati e il codice QR.', 'dfn-theme') . '</p>';
        echo '<a href="' . esc_url($hub_url) . '" style="display: inline-block; margin-top: 20px; padding: 12px 24px; background: #004b23; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold;">' . esc_html__('Apri Hub Biglietti (QR)', 'dfn-theme') . '</a>';
        echo '</div></body></html>';
        exit;
    }

    // Se GET, mostra la form interattiva
    $booking_date = '';
    $booking_details = $wpdb->get_results($wpdb->prepare(
        "SELECT s.slot_date FROM {$wpdb->prefix}dfn_booking_slots bs
         JOIN {$wpdb->prefix}dfn_event_slots s ON bs.slot_id = s.id
         WHERE bs.booking_id = %d",
         $booking->id
    ));

    if (! empty($booking_details)) {
        $dates = [];
        foreach ($booking_details as $b_det) {
            $dates[] = date_i18n('d/m/Y', strtotime($b_det->slot_date));
        }
        $booking_date = implode(', ', array_unique($dates));
    } else {
        $meta_date = $order->get_meta('_dfn_booking_date');
        if (! empty($meta_date)) {
            $booking_date = date_i18n('d/m/Y', strtotime($meta_date));
        }
    }

    $cancel_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
    $cancel_url   = home_url('/?dfn_cancel_booking=1&order_id=' . $order_id . '&token=' . $cancel_token);
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php esc_html_e('Modifica la tua Prenotazione', 'dfn-theme'); ?></title>
        <style>
            body { 
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; 
                background: #f4f7f6; 
                padding: 20px; 
                display: flex; 
                justify-content: center; 
                align-items: center; 
                min-height: 80vh; 
                margin: 0;
            } 
            .card { 
                background: #fff; 
                padding: 40px 30px; 
                border-radius: 16px; 
                max-width: 500px; 
                width: 100%;
                box-shadow: 0 10px 30px rgba(0, 75, 35, 0.05); 
                box-sizing: border-box;
                border-top: 4px solid #004b23;
            }
            h1 {
                color: #1d2327;
                margin-top: 0;
                font-size: 22px;
                font-weight: 700;
                margin-bottom: 10px;
                text-align: center;
            }
            .subtitle {
                color: #64748b;
                font-size: 14px;
                margin-bottom: 25px;
                text-align: center;
                line-height: 1.5;
            }
            .form-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                background: #f8fafc;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
                margin-bottom: 15px;
            }
            .label-wrap {
                display: flex;
                flex-direction: column;
            }
            .ticket-title {
                font-weight: bold;
                color: #1e293b;
            }
            .ticket-desc {
                font-size: 12px;
                color: #64748b;
            }
            .spinner-wrap {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .spinner-btn {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                border: 1px solid #cbd5e1;
                background: #fff;
                font-size: 18px;
                font-weight: bold;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                user-select: none;
                color: #1e293b;
            }
            .spinner-btn:active {
                background: #f1f5f9;
            }
            .qty-input {
                width: 40px;
                text-align: center;
                font-size: 16px;
                font-weight: bold;
                border: none;
                background: transparent;
                color: #1e293b;
            }
            .qty-input::-webkit-outer-spin-button,
            .qty-input::-webkit-inner-spin-button {
                -webkit-appearance: none;
                appearance: none;
                margin: 0;
            }
            .qty-input[type=number] {
                -moz-appearance: textfield;
                appearance: textfield;
            }
            .error-message {
                color: #ef4444;
                font-size: 14px;
                margin-bottom: 15px;
                text-align: center;
                display: none;
                background: #fef2f2;
                padding: 10px;
                border-radius: 6px;
                border: 1px solid #fee2e2;
            }
            .btn-group {
                display: flex;
                gap: 15px;
                justify-content: center;
                margin-top: 25px;
            }
            .btn {
                padding: 12px 24px;
                text-decoration: none;
                border-radius: 8px;
                font-weight: bold;
                font-size: 15px;
                text-align: center;
                flex: 1;
                cursor: pointer;
                border: none;
                transition: opacity 0.2s;
            }
            .btn:hover {
                opacity: 0.9;
            }
            .btn-submit {
                background: #004b23;
                color: #fff;
                box-shadow: 0 4px 6px rgba(0, 75, 35, 0.2);
            }
            .btn-cancel {
                background: #e2e8f0;
                color: #475569;
            }
        </style>
        <script>
            function checkTotal() {
                var std = parseInt(document.getElementById('new_qty_std').value) || 0;
                var fai = parseInt(document.getElementById('new_qty_fai').value) || 0;
                var err = document.getElementById('err-msg');
                var btn = document.getElementById('submit-btn');

                if (std + fai < 1) {
                    err.innerText = "La prenotazione deve contenere almeno 1 partecipante. Se desideri annullarla completamente, usa il link di annullamento.";
                    err.style.display = 'block';
                    btn.disabled = true;
                    btn.style.opacity = 0.5;
                } else {
                    err.style.display = 'none';
                    btn.disabled = false;
                    btn.style.opacity = 1;
                }
            }

            function changeQty(id, delta, maxVal) {
                var input = document.getElementById(id);
                var current = parseInt(input.value) || 0;
                var newVal = current + delta;
                if (newVal >= 0 && newVal <= maxVal) {
                    input.value = newVal;
                    checkTotal();
                }
            }
        </script>
    </head>
    <body>
    <div class="card">
        <h1>Modifica Prenotazione</h1>
        <div class="subtitle">
            Modifica il numero dei partecipanti per <strong><?php echo esc_html($event_title); ?></strong> del <?php echo esc_html($booking_date); ?>.<br>
            <span style="color:#d32f2f; font-size:12px; font-weight:bold;">Nota: puoi solo ridurre o mantenere i quantitativi. Per annullare completamente la prenotazione, utilizza il <a href="<?php echo esc_url($cancel_url); ?>" style="color:#d32f2f; text-decoration:underline;">link di annullamento</a>.</span>
        </div>

        <form method="POST" action="">
            <input type="hidden" name="confirm_modify" value="1">

            <!-- Biglietti Standard -->
            <div class="form-row">
                <div class="label-wrap">
                    <span class="ticket-title">Ingressi Standard</span>
                    <span class="ticket-desc">Massimo attuale: <?php echo intval($booking->persons_standard); ?></span>
                </div>
                <div class="spinner-wrap">
                    <button type="button" class="spinner-btn" onclick="changeQty('new_qty_std', -1, <?php echo intval($booking->persons_standard); ?>)">−</button>
                    <input class="qty-input" type="number" id="new_qty_std" name="new_qty_standard" value="<?php echo intval($booking->persons_standard); ?>" readonly>
                    <button type="button" class="spinner-btn" onclick="changeQty('new_qty_std', 1, <?php echo intval($booking->persons_standard); ?>)">+</button>
                </div>
            </div>

            <!-- Biglietti FAI -->
            <div class="form-row">
                <div class="label-wrap">
                    <span class="ticket-title">Soci FAI</span>
                    <span class="ticket-desc">Massimo attuale: <?php echo intval($booking->persons_fai); ?></span>
                </div>
                <div class="spinner-wrap">
                    <button type="button" class="spinner-btn" onclick="changeQty('new_qty_fai', -1, <?php echo intval($booking->persons_fai); ?>)">−</button>
                    <input class="qty-input" type="number" id="new_qty_fai" name="new_qty_fai" value="<?php echo intval($booking->persons_fai); ?>" readonly>
                    <button type="button" class="spinner-btn" onclick="changeQty('new_qty_fai', 1, <?php echo intval($booking->persons_fai); ?>)">+</button>
                </div>
            </div>

            <div id="err-msg" class="error-message"></div>

            <div class="btn-group">
                <button type="submit" id="submit-btn" class="btn btn-submit">Conferma Modifica</button>
                <a href="<?php echo esc_url(home_url()); ?>" class="btn btn-cancel">Indietro</a>
            </div>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Annulla una prenotazione tramite ID.
 */
function dfn_cancel_booking_by_id(int $booking_id, string $note = ''): bool
{
    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';

    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_bookings} WHERE id = %d", $booking_id));
    if (! $booking) {
        return false;
    }

    $order = wc_get_order($booking->order_id);
    if (! $order) {
        return false;
    }

    $wpdb->query('START TRANSACTION');

    $assocs = $wpdb->get_results($wpdb->prepare(
        "SELECT slot_id, persons FROM {$table_booking_slots} WHERE booking_id = %d",
        $booking->id
    ));

    foreach ($assocs as $assoc) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_slots} SET booked_count = GREATEST(0, CAST(booked_count AS SIGNED) - %d) WHERE id = %d",
            intval($assoc->persons),
            intval($assoc->slot_id)
        ));
    }

    $wpdb->update(
        $table_bookings,
        [ 'status' => 'cancelled' ],
        [ 'id' => $booking->id ],
        [ '%s' ],
        [ '%d' ]
    );

    $wpdb->query('COMMIT');

    $order->update_meta_data('_dfn_cancelled_manually', 'yes');
    $order->save();

    $order->update_status('cancelled', ! empty($note) ? $note : __('Prenotazione annullata.', 'dfn-theme'));

    if (function_exists('dfn_send_booking_cancellation')) {
        dfn_send_booking_cancellation($booking->id);
    }

    return true;
}

/**
 * Esegue l'aggiornamento a DB e WooCommerce per una modifica prenotazione (riduzione posti).
 */
function dfn_process_booking_modification($booking, $order, int $new_qty_standard, int $new_qty_fai): bool
{
    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    $new_total_qty = $new_qty_standard + $new_qty_fai;
    $diff_std   = intval($booking->persons_standard) - $new_qty_standard;
    $diff_fai   = intval($booking->persons_fai) - $new_qty_fai;
    $total_diff = $diff_std + $diff_fai;

    if ($total_diff > 0) {
        if (! function_exists('dfn_db_get_event')) {
            require_once get_template_directory() . '/inc/core/dfn-database.php';
        }

        $wpdb->query('START TRANSACTION');

        $event = dfn_db_get_event($booking->event_id);
        $new_amount = 0.00;
        if ($event) {
            $price_std  = floatval($event->price_standard);
            $price_fai  = floatval($event->price_fai);
            $new_amount = ($new_qty_standard * $price_std) + ($new_qty_fai * $price_fai);
        }

        $update_fields = [
            'persons_standard' => $new_qty_standard,
            'persons_fai'      => $new_qty_fai,
            'total_persons'    => $new_total_qty,
        ];
        if ($booking->payment_method === 'dfn_in_loco') {
            $update_fields['amount_due'] = $new_amount;
        } else {
            $update_fields['amount_paid'] = $new_amount;
        }

        $wpdb->update(
            $table_bookings,
            $update_fields,
            [ 'id' => $booking->id ],
            [ '%d', '%d', '%d', '%f' ],
            [ '%d' ]
        );

        $booking_slots = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dfn_booking_slots WHERE booking_id = %d ORDER BY id DESC",
            $booking->id
        ));

        $remaining_diff = $total_diff;
        foreach ($booking_slots as $bs_row) {
            if ($remaining_diff <= 0) {
                break;
            }
            $current_persons = intval($bs_row->persons);
            if ($current_persons <= 0) {
                continue;
            }
            $reduce_by = min($current_persons, $remaining_diff);
            $new_persons = $current_persons - $reduce_by;

            if ($new_persons > 0) {
                $wpdb->update(
                    $wpdb->prefix . 'dfn_booking_slots',
                    [ 'persons' => $new_persons ],
                    [ 'id' => $bs_row->id ],
                    [ '%d' ],
                    [ '%d' ]
                );
            } else {
                $wpdb->delete(
                    $wpdb->prefix . 'dfn_booking_slots',
                    [ 'id' => $bs_row->id ],
                    [ '%d' ]
                );
            }

            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}dfn_event_slots SET booked_count = GREATEST(0, CAST(booked_count AS SIGNED) - %d) WHERE id = %d",
                $reduce_by,
                intval($bs_row->slot_id)
            ));

            $remaining_diff -= $reduce_by;
        }

        $wpdb->query('COMMIT');

        $has_changed = false;
        foreach ($order->get_items() as $item) {
            if (is_a($item, 'WC_Order_Item_Product')) {
                $item->update_meta_data('_dfn_qty_standard', (string) $new_qty_standard);
                $item->update_meta_data('_dfn_qty_fai', (string) $new_qty_fai);
                $item->set_quantity($new_total_qty);
                $item->save();
                $has_changed = true;
            }
        }

        if ($has_changed) {
            if ($event) {
                $price_standard = floatval($event->price_standard);
                $price_fai      = floatval($event->price_fai);
                $unit_discount  = $price_standard - $price_fai;
                $new_total_discount = $unit_discount * $new_qty_fai;

                foreach ($order->get_items('fee') as $fee_item) {
                    if (strpos($fee_item->get_name(), 'Sconto Soci FAI') !== false || strpos($fee_item->get_name(), 'Adeguamento Soci FAI') !== false) {
                        $fee_item->set_amount(-$new_total_discount);
                        $fee_item->set_total(-$new_total_discount);
                        $fee_item->save();
                    }
                }
            }

            $fai_cards = $order->get_meta('_dfn_fai_cards') ?: [];
            if (count($fai_cards) > $new_qty_fai) {
                $fai_cards = array_slice($fai_cards, 0, $new_qty_fai);
                $order->update_meta_data('_dfn_fai_cards', $fai_cards);
            }

            $order->calculate_totals();
            $note_text = sprintf(
                __('Prenotazione modificata. Nuovi quantitativi: %d Standard, %d Soci FAI (Totale: %d). Precedente totale: %d.', 'dfn-theme'),
                $new_qty_standard,
                $new_qty_fai,
                $new_total_qty,
                $booking->total_persons
            );
            $order->add_order_note($note_text);
            $order->save();
        }
    }

    return true;
}

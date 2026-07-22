<?php
/**
 * DFN Booking System 2.0 — Verifica Prenotazioni FAI (Pending Approval)
 *
 * Pannello admin per la gestione rapida delle prenotazioni in attesa di verifica
 * tessere FAI. Permette di approvare (inviando il link di pagamento) o rifiutare
 * (con motivazione) ogni prenotazione in stato pending_approval.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renderizza la pagina admin "Verifica Prenotazioni FAI".
 */
function dfn_render_fai_pending_bookings(): void
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // Carica tutte le prenotazioni pending_approval
    $bookings = $wpdb->get_results(
        "SELECT * FROM {$table_bookings} WHERE status = 'pending_approval' ORDER BY created_at ASC"
    );

    // Raggruppa per evento
    $grouped = [];
    foreach ($bookings as $booking) {
        $event = dfn_db_get_event($booking->event_id);
        if (! $event) {
            continue;
        }
        $event_key = $booking->event_id;
        if (! isset($grouped[$event_key])) {
            $grouped[$event_key] = [
                'event'    => $event,
                'bookings' => [],
            ];
        }
        $grouped[$event_key]['bookings'][] = $booking;
    }
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header">
            <div class="dfn-logo-area">
                <h1><?php esc_html_e('Verifica Prenotazioni FAI', 'dfn-theme'); ?></h1>
            </div>
            <?php if (! empty($bookings)) : ?>
                <span class="dfn-count-badge"><?php echo count($bookings); ?> <?php esc_html_e('in attesa', 'dfn-theme'); ?></span>
            <?php endif; ?>
        </header>

        <div class="dfn-pending-intro" style="background: #fffdf0; border: 1px solid #e8d89a; border-radius: 6px; padding: 14px 18px; margin-bottom: 20px; color: #5a4a00; font-size: 13px;">
            <?php esc_html_e('Qui trovi tutte le prenotazioni in attesa di verifica delle tessere FAI. Puoi approvare la prenotazione (verrà inviata la mail di pagamento al cliente) oppure rifiutarla inserendo la motivazione.', 'dfn-theme'); ?>
        </div>

        <?php if (empty($bookings)) : ?>
            <div class="dfn-card dfn-main-card">
                <div style="padding: 60px 20px; text-align: center;">
                    <p style="font-size: 22px; color: var(--dfn-primary); font-weight: 700; margin: 0 0 8px;">Nessuna prenotazione in attesa</p>
                    <p style="color: var(--dfn-text-muted); font-size: 14px; margin: 0;">Tutte le prenotazioni FAI sono state verificate.</p>
                </div>
            </div>
        <?php else : ?>

            <?php foreach ($grouped as $event_id => $group) :
                $event = $group['event'];
                $product_name = get_the_title($event->product_id) ?: 'Evento #' . $event->id;
                $event_date = date_i18n('d M Y', strtotime($event->event_date_start));
                ?>
                <div class="dfn-card dfn-main-card" style="margin-bottom: 30px;">
                    <div class="dfn-card-header">
                        <div>
                            <h2><?php echo esc_html($product_name); ?></h2>
                            <span class="dfn-small-sub">
                                <?php echo esc_html($event->location); ?> &nbsp;&bull;&nbsp; <?php echo esc_html($event_date); ?>
                            </span>
                        </div>
                        <span class="dfn-count-badge"><?php echo count($group['bookings']); ?> <?php esc_html_e('prenotazioni', 'dfn-theme'); ?></span>
                    </div>

                    <table class="wp-list-table widefat fixed striped dfn-events-table dfn-pending-table">
                        <thead>
                            <tr>
                                <th style="width: 18%;"><?php esc_html_e('Visitatore', 'dfn-theme'); ?></th>
                                <th style="width: 17%;"><?php esc_html_e('Contatti', 'dfn-theme'); ?></th>
                                <th style="width: 13%;"><?php esc_html_e('Turno', 'dfn-theme'); ?></th>
                                <th style="width: 12%;"><?php esc_html_e('Biglietti', 'dfn-theme'); ?></th>
                                <th style="width: 20%;"><?php esc_html_e('Tessere FAI da verificare', 'dfn-theme'); ?></th>
                                <th style="width: 9%;"><?php esc_html_e('Ricevuto il', 'dfn-theme'); ?></th>
                                <th style="width: 11%;"><?php esc_html_e('Azioni', 'dfn-theme'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['bookings'] as $booking) :
                                $order = wc_get_order($booking->order_id);
                                $fai_cards = $order ? $order->get_meta('_dfn_fai_cards') : [];

                                // Recupera slot info
                                $slots = $wpdb->get_results($wpdb->prepare(
                                    "SELECT s.*, bs.persons FROM {$wpdb->prefix}dfn_event_slots s
                                     JOIN {$wpdb->prefix}dfn_booking_slots bs ON s.id = bs.slot_id
                                     WHERE bs.booking_id = %d",
                                    $booking->id,
                                ));
                                $slot_info = '—';
                                if (! empty($slots)) {
                                    if (count($slots) === 1) {
                                        $slot_info = 'ore ' . date('H:i', strtotime($slots[0]->slot_time_start));
                                    } else {
                                        $parts = [];
                                        foreach ($slots as $s) {
                                            $parts[] = date('H:i', strtotime($s->slot_time_start));
                                        }
                                        $slot_info = implode(', ', $parts);
                                    }
                                } else {
                                    $slot_info = esc_html__('Ingresso Libero', 'dfn-theme');
                                }

                                // Tessere non verificate
                                $unverified_cards = [];
                                if (! empty($fai_cards) && is_array($fai_cards)) {
                                    $table_members = $wpdb->prefix . 'dfn_fai_members';
                                    foreach ($fai_cards as $card) {
                                        if (empty($card['tessera'])) {
                                            continue;
                                        }
                                        $verified = $wpdb->get_var($wpdb->prepare(
                                            "SELECT verified FROM {$table_members} WHERE card_number = %s LIMIT 1",
                                            $card['tessera']
                                        ));
                                        if (intval($verified) !== 1) {
                                            $unverified_cards[] = $card;
                                        }
                                    }
                                }
                                ?>
                                <tr class="dfn-pending-row" data-booking-id="<?php echo absint($booking->id); ?>">
                                    <td>
                                        <strong><?php echo esc_html($booking->customer_name); ?></strong>
                                        <?php if ($order) : ?>
                                            <div class="row-actions">
                                                <span><a href="<?php echo esc_url(admin_url('post.php?post=' . $booking->order_id . '&action=edit')); ?>" target="_blank"><?php esc_html_e('Vedi Ordine', 'dfn-theme'); ?></a></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size:13px;"><?php echo esc_html($booking->customer_email); ?></div>
                                        <?php if (! empty($booking->customer_phone)) : ?>
                                            <div class="dfn-small-sub"><?php echo esc_html($booking->customer_phone); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo esc_html($slot_info); ?>
                                    </td>
                                    <td>
                                        <strong style="font-size:15px;"><?php echo absint($booking->total_persons); ?></strong>
                                        <div class="dfn-small-sub"><?php echo absint($booking->persons_standard); ?> std + <?php echo absint($booking->persons_fai); ?> FAI</div>
                                        <?php if ($order) : ?>
                                            <div style="color: var(--dfn-primary); font-weight: bold; font-size: 13px; margin-top: 2px;"><?php echo wc_price(floatval($order->get_total())); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $all_fai_cards = ! empty($fai_cards) && is_array($fai_cards) ? $fai_cards : [];
                                        if (! empty($all_fai_cards)) :
                                            $table_members = $wpdb->prefix . 'dfn_fai_members';
                                            foreach ($all_fai_cards as $idx => $card) :
                                                if (empty($card['tessera'])) {
                                                    continue;
                                                }
                                                $tessera_num = esc_html($card['tessera']);
                                                $titolare    = trim(($card['nome'] ?? '') . ' ' . ($card['cognome'] ?? ''));
                                                $card_status = $card['status'] ?? null; // 'approved' o 'rejected'

                                                $is_verified_db = (int) $wpdb->get_var($wpdb->prepare(
                                                    "SELECT verified FROM {$table_members} WHERE card_number = %s LIMIT 1",
                                                    $card['tessera']
                                                ));

                                                if ($card_status === 'approved' || $is_verified_db === 1) : ?>
                                                    <div style="font-size: 11px; margin-bottom: 4px; padding: 3px 6px; background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                                                        <span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                                        <strong><?php echo $tessera_num; ?></strong><?php if ($titolare) : ?> (<?php echo esc_html($titolare); ?>)<?php endif; ?>
                                                        <span style="font-size: 10px; font-weight: bold; margin-left: 4px;">VERIFICATA</span>
                                                    </div><br>
                                                <?php elseif ($card_status === 'rejected') : ?>
                                                    <div style="font-size: 11px; margin-bottom: 4px; padding: 3px 6px; background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">
                                                        <span class="dashicons dashicons-no-alt" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                                        <strong><?php echo $tessera_num; ?></strong><?php if ($titolare) : ?> (<?php echo esc_html($titolare); ?>)<?php endif; ?>
                                                        <span style="font-size: 10px; font-weight: bold; margin-left: 4px;">RIFIUTATA</span>
                                                    </div><br>
                                                <?php else : ?>
                                                    <div class="dfn-card-action-item" style="font-size: 11px; margin-bottom: 4px; padding: 4px 6px; background: #fff5f5; border: 1px solid #fca5a5; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px;" data-card-number="<?php echo esc_attr($card['tessera']); ?>">
                                                        <span><strong><?php echo $tessera_num; ?></strong><?php if ($titolare) : ?> &mdash; <?php echo esc_html($titolare); ?><?php endif; ?></span>
                                                        <div style="display: inline-flex; gap: 4px; margin-left: 6px;">
                                                            <button type="button" class="dfn-btn-card-action dfn-btn-validate-card" data-booking-id="<?php echo absint($booking->id); ?>" data-card-number="<?php echo esc_attr($card['tessera']); ?>" style="background: #16a34a; color: #fff; border: none; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;" title="<?php esc_attr_e('Convalida tessera', 'dfn-theme'); ?>">
                                                                <span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                                            </button>
                                                            <button type="button" class="dfn-btn-card-action dfn-btn-reject-card" data-booking-id="<?php echo absint($booking->id); ?>" data-card-number="<?php echo esc_attr($card['tessera']); ?>" style="background: #dc2626; color: #fff; border: none; border-radius: 4px; padding: 2px 6px; font-size: 11px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;" title="<?php esc_attr_e('Rifiuta tessera e converti a tariffa Intera (+5€)', 'dfn-theme'); ?>">
                                                                <span class="dashicons dashicons-no-alt" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                                            </button>
                                                        </div>
                                                    </div><br>
                                                <?php endif;
                                            endforeach;
                                        else : ?>
                                            <span style="color: var(--dfn-text-muted); font-size: 12px;"><?php esc_html_e('Nessuna tessera', 'dfn-theme'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size: 12px; color: var(--dfn-text-muted);">
                                            <?php echo date_i18n('d/m/Y H:i', strtotime($booking->created_at)); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">
                                            <button
                                                type="button"
                                                class="dfn-btn dfn-btn-primary dfn-btn-approve-booking"
                                                data-booking-id="<?php echo absint($booking->id); ?>"
                                                data-customer="<?php echo esc_attr($booking->customer_name); ?>"
                                            ><?php esc_html_e('Approva', 'dfn-theme'); ?></button>
                                            <button
                                                type="button"
                                                class="dfn-btn dfn-btn-reject-booking"
                                                data-booking-id="<?php echo absint($booking->id); ?>"
                                                data-customer="<?php echo esc_attr($booking->customer_name); ?>"
                                                style="background: var(--dfn-danger); color: #fff;"
                                            ><?php esc_html_e('Rifiuta', 'dfn-theme'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Modale di rifiuto -->
    <div id="dfn-reject-modal" class="dfn-modal-overlay" style="display:none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.55); z-index: 9999; align-items: center; justify-content: center;">
        <div class="dfn-modal-box" style="background: #fff; border-radius: var(--dfn-radius); box-shadow: 0 20px 40px rgba(0,0,0,0.2); width: 560px; max-width: 92%;">
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--dfn-border);">
                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--dfn-danger);"><?php esc_html_e('Rifiuta Prenotazione', 'dfn-theme'); ?></h3>
                <button type="button" id="dfn-modal-close" style="background: none; border: none; cursor: pointer; font-size: 22px; color: var(--dfn-text-muted); line-height: 1; padding: 0;">&times;</button>
            </div>
            <div style="padding: 24px;">
                <p id="dfn-reject-customer-info" style="font-weight: 600; color: var(--dfn-text-main); margin: 0 0 16px;"></p>
                <label for="dfn-reject-motivo" style="display: block; font-weight: 600; font-size: 13px; margin-bottom: 8px; color: var(--dfn-text-main);">
                    <?php esc_html_e('Motivazione del rifiuto *', 'dfn-theme'); ?>
                </label>
                <textarea
                    id="dfn-reject-motivo"
                    rows="5"
                    placeholder="<?php esc_attr_e('Inserisci la motivazione del rifiuto che verrà comunicata al cliente via email...', 'dfn-theme'); ?>"
                    style="width: 100%; box-sizing: border-box; border: 1px solid var(--dfn-border); border-radius: 4px; padding: 10px 12px; font-size: 13px; resize: vertical; font-family: inherit;"
                ></textarea>
                <p style="font-size: 12px; color: var(--dfn-text-muted); margin: 8px 0 0;">
                    <?php esc_html_e('La motivazione sarà inclusa nella mail di notifica inviata al cliente.', 'dfn-theme'); ?>
                </p>
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid var(--dfn-border); display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" id="dfn-modal-cancel" class="dfn-btn dfn-btn-secondary"><?php esc_html_e('Annulla', 'dfn-theme'); ?></button>
                <button type="button" id="dfn-modal-confirm-reject" class="dfn-btn" style="background: var(--dfn-danger); color: #fff;"><?php esc_html_e('Conferma rifiuto', 'dfn-theme'); ?></button>
            </div>
        </div>
    </div>
    <?php
}

<?php
/**
 * DFN Booking System 2.0 — Waitlist Administration Dashboard
 *
 * Bacheca premium per monitorare la lista d'attesa (tabella custom dfn_waitlist),
 * promuovere manualmente i clienti ad ordini reali all'incrementarsi della disponibilità
 * e inviare email transazionali.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_waitlist_register_menu');

/**
 * Registra il sottomenu della Lista d'Attesa.
 */
function dfn_waitlist_register_menu(): void
{
    add_submenu_page(
        'dfn-events',
        esc_html__('Lista d\'Attesa Eventi', 'dfn-theme'),
        esc_html__('Lista d\'Attesa', 'dfn-theme'),
        'dfn_manage_events',
        'dfn-waitlist',
        'dfn_render_waitlist_page',
    );
}

/**
 * Renderizza la bacheca di gestione della Lista d'Attesa.
 */
function dfn_render_waitlist_page(): void
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi per accedere a questa pagina.', 'dfn-theme'));
    }

    global $wpdb;
    $selected_event_id = isset($_REQUEST['event_id']) ? intval($_REQUEST['event_id']) : 0;

    $message = '';
    $message_type = 'success';

    // 1. GESTIONE AZIONI CODA (PROMOZIONE / RIMOZIONE)
    if (isset($_POST['dfn_waitlist_action_nonce']) && wp_verify_nonce($_POST['dfn_waitlist_action_nonce'], 'dfn_waitlist_action')) {
        $entry_id = intval($_POST['entry_id']);
        $action   = sanitize_text_field($_POST['wl_action']);

        $table_waitlist = $wpdb->prefix . 'dfn_waitlist';
        $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_waitlist} WHERE id = %d LIMIT 1", $entry_id));

        if ($entry) {
            if ($action === 'delete') {
                $wpdb->delete($table_waitlist, [ 'id' => $entry_id ], [ '%d' ]);
                $message = esc_html__('Richiesta rimossa con successo dalla lista d\'attesa.', 'dfn-theme');
            } elseif ($action === 'promote') {
                // Inizia promozione creando ordine WC ed inserendo booking transazionale
                $event = dfn_db_get_event($entry->event_id);
                $product = wc_get_product($event->product_id);

                if ($product) {
                    $wpdb->query('START TRANSACTION');

                    try {
                        // 1. Crea ordine WooCommerce pending
                        $order = wc_create_order();
                        $names = explode(' ', $entry->customer_name, 2);
                        $first_name = $names[0];
                        $last_name  = isset($names[1]) ? $names[1] : '';

                        $order->set_billing_first_name($first_name);
                        $order->set_billing_last_name($last_name);
                        $order->set_billing_email($entry->customer_email);
                        $order->set_billing_phone($entry->customer_phone);

                        $order->add_product($product, intval($entry->persons));

                        // Se ci sono tessere scomputa la fee FAI
                        $fai_cards = intval($entry->fai_cards);
                        if ($fai_cards > 0 && $event) {
                            $discount_unit = floatval($event->price_standard) - floatval($event->price_fai);
                            if ($discount_unit > 0) {
                                $total_discount = $fai_cards * $discount_unit;
                                $fee = new \WC_Order_Item_Fee();
                                $fee->set_name(sprintf(__('Sconto Soci FAI (%d tessere)', 'dfn-theme'), $fai_cards));
                                $fee->set_amount((string) -$total_discount);
                                $fee->set_total((string) -$total_discount);
                                $order->add_item($fee);
                            }
                        }

                        $order->calculate_totals();
                        $order->update_status('pending', __('Ordine generato automaticamente da Lista d\'Attesa.', 'dfn-theme'));
                        $order->save();

                        // 2. Registra la prenotazione custom
                        $qr_token = wp_hash($order->get_id() . '|' . $event->id . '|' . time());
                        $wpdb->insert(
                            $wpdb->prefix . 'dfn_bookings',
                            [
                                'order_id'         => $order->get_id(),
                                'event_id'         => $event->id,
                                'customer_email'   => $entry->customer_email,
                                'customer_name'    => $entry->customer_name,
                                'customer_phone'   => $entry->customer_phone,
                                'total_persons'    => $entry->persons,
                                'persons_standard' => intval($entry->persons) - $fai_cards,
                                'persons_fai'      => $fai_cards,
                                'status'           => 'confirmed',
                                'qr_token'         => $qr_token,
                                'payment_method'   => $order->get_payment_method(),
                                'amount_due'       => floatval($order->get_total()),
                                'amount_paid'      => 0.00,
                            ],
                            [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f', '%f' ],
                        );
                        $booking_id = $wpdb->insert_id;

                        // Se associato a uno slot specifico incrementa booked_count
                        if ($entry->slot_id) {
                            $slot = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_event_slots WHERE id = %d FOR UPDATE", $entry->slot_id));
                            if ($slot) {
                                $wpdb->update(
                                    $wpdb->prefix . 'dfn_event_slots',
                                    [ 'booked_count' => intval($slot->booked_count) + intval($entry->persons) ],
                                    [ 'id' => $entry->slot_id ],
                                    [ '%d' ],
                                    [ '%d' ],
                                );

                                $wpdb->insert(
                                    $wpdb->prefix . 'dfn_booking_slots',
                                    [
                                        'booking_id' => $booking_id,
                                        'slot_id'    => $entry->slot_id,
                                        'persons'    => $entry->persons,
                                    ],
                                    [ '%d', '%d', '%d' ],
                                );
                            }
                        }

                        // Aggiorna lo stato della waitlist a promoted
                        $wpdb->update(
                            $table_waitlist,
                            [ 'status' => 'promoted', 'promoted_order_id' => $order->get_id() ],
                            [ 'id' => $entry_id ],
                            [ '%s', '%d' ],
                            [ '%d' ],
                        );

                        $wpdb->query('COMMIT');

                        // Invia notifica di fattura/link di pagamento WC
                        /** @var \WC_Email_Customer_Invoice|null $email_invoice */
                        $email_invoice = WC()->mailer()->get_emails()['WC_Email_Customer_Invoice'] ?? null;
                        if ($email_invoice) {
                            $email_invoice->trigger($order->get_id());
                        }

                        $message = sprintf(__('Successo! Utente promosso. Creato l\'ordine #%d e inviato il link di pagamento.', 'dfn-theme'), $order->get_id());
                    } catch (\Exception $e) {
                        $wpdb->query('ROLLBACK');
                        $message = esc_html__('Errore critico durante la promozione dell\'utente.', 'dfn-theme');
                        $message_type = 'error';
                    }
                }
            }
        }
    }

    // 2. FORM INSERIMENTO CODA MANUALE
    if (isset($_POST['dfn_add_waitlist_submit'])) {
        if (isset($_POST['dfn_add_waitlist_nonce']) && wp_verify_nonce($_POST['dfn_add_waitlist_nonce'], 'dfn_add_waitlist_action')) {
            $nome    = sanitize_text_field($_POST['wl_name']);
            $email   = sanitize_email($_POST['wl_email']);
            $phone   = sanitize_text_field($_POST['wl_phone']);
            $qty     = intval($_POST['wl_qty']);
            $tessere = intval($_POST['wl_tessere']);

            if (! empty($nome) && ! empty($email) && $selected_event_id > 0 && $qty > 0) {
                $wpdb->insert(
                    $wpdb->prefix . 'dfn_waitlist',
                    [
                        'event_id'       => $selected_event_id,
                        'customer_name'  => $nome,
                        'customer_email' => $email,
                        'customer_phone' => ! empty($phone) ? $phone : null,
                        'persons'        => $qty,
                        'fai_cards'      => $tessere,
                        'status'         => 'waiting',
                    ],
                    [ '%d', '%s', '%s', '%s', '%d', '%d', '%s' ],
                );
                $message = esc_html__('Utente inserito correttamente in lista d\'attesa.', 'dfn-theme');
            } else {
                $message = esc_html__('Tutti i campi obbligatori devono essere compilati.', 'dfn-theme');
                $message_type = 'error';
            }
        }
    }

    // Lista eventi attivi per il selettore
    $table_events = $wpdb->prefix . 'dfn_events';
    $events = $wpdb->get_results("SELECT * FROM {$table_events} WHERE status != 'archived' ORDER BY event_date_start DESC");

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 25px;">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-clock"></span>
                <h1><?php esc_html_e('Gestione Liste di Attesa', 'dfn-theme'); ?></h1>
            </div>
        </header>

        <p style="font-size: 15px; color: #475569; margin-bottom: 20px;">
            <?php esc_html_e('Seleziona un evento per monitorare le richieste in coda o promuoverle a prenotazione quando si liberano dei posti.', 'dfn-theme'); ?>
        </p>

        <?php if (! empty($message)) : ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Selettore Evento -->
        <form method="GET" style="background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); display: inline-block; border: 1px solid #f1f5f9; margin-bottom: 30px;">
            <input type="hidden" name="page" value="dfn-waitlist">
            <label style="font-weight: 700; color: #1e293b; margin-right: 12px; font-size: 14px;"><?php esc_html_e('Seleziona l\'evento:', 'dfn-theme'); ?></label>
            <select name="event_id" style="min-width: 300px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; font-weight: 600; color: #334155; margin-right: 10px;">
                <option value="0">-- <?php esc_html_e('Seleziona un Evento', 'dfn-theme'); ?> --</option>
                <?php foreach ($events as $ev) :
                    $p_name = get_the_title($ev->product_id) ?: esc_html__('Evento', 'dfn-theme');
                    ?>
                    <option value="<?php echo intval($ev->id); ?>" <?php selected($selected_event_id, $ev->id); ?>><?php echo esc_html($p_name); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary" style="padding: 5px 18px; font-size: 14px; height: auto; font-weight: 700; background: #004b23; border: none; border-radius: 6px;"><?php esc_html_e('Carica Coda', 'dfn-theme'); ?></button>
        </form>

        <?php if ($selected_event_id > 0) :
            $event = dfn_db_get_event($selected_event_id);

            // Calcolo disponibilità residua
            $slots = dfn_db_get_slots($selected_event_id);
            $total_booked = 0;
            $total_capacity = 0;
            foreach ($slots as $s) {
                $total_booked += intval($s->booked_count);
                $total_capacity += intval($s->capacity);
            }
            $available_spots = $total_capacity - $total_booked;
            $availability_text = ($available_spots > 0)
                ? '<span style="color: #16a34a; font-weight: 700;">' . sprintf(__('%d posti liberi!', 'dfn-theme'), $available_spots) . '</span>'
                : '<span style="color: #dc2626; font-weight: 700;">' . esc_html__('Tutto Sold Out (0 posti liberi)', 'dfn-theme') . '</span>';

            // Carica la coda attiva
            $table_waitlist = $wpdb->prefix . 'dfn_waitlist';
            $coda = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_waitlist} WHERE event_id = %d AND status = 'waiting' ORDER BY id ASC",
                $selected_event_id,
            ));
            ?>

            <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                <!-- COLONNA SX: Form Aggiunta Manuale -->
                <div style="flex: 1 1 300px; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; height: fit-content;">
                    <h3 style="margin-top: 0; color: #004b23; font-weight: 800; font-size: 18px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;"><?php esc_html_e('Aggiungi in Coda', 'dfn-theme'); ?></h3>
                    
                    <form method="POST" style="margin-top: 15px;">
                        <?php wp_nonce_field('dfn_add_waitlist_action', 'dfn_add_waitlist_nonce'); ?>
                        
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Nome Cliente *', 'dfn-theme'); ?></label>
                            <input type="text" name="wl_name" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        </div>

                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Email *', 'dfn-theme'); ?></label>
                            <input type="email" name="wl_email" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        </div>

                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Telefono', 'dfn-theme'); ?></label>
                            <input type="text" name="wl_phone" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                        </div>

                        <div style="display: flex; gap: 15px; margin-bottom: 20px;">
                            <div style="flex: 1;">
                                <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Biglietti *', 'dfn-theme'); ?></label>
                                <input type="number" name="wl_qty" value="1" min="1" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            </div>
                            <div style="flex: 1;">
                                <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Tessere FAI', 'dfn-theme'); ?></label>
                                <input type="number" name="wl_tessere" value="0" min="0" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                            </div>
                        </div>

                        <button type="submit" name="dfn_add_waitlist_submit" class="button button-primary" style="width: 100%; padding: 10px; height: auto; font-size: 14px; font-weight: 700; background: #004b23; border: none; border-radius: 6px;"><?php esc_html_e('Inserisci in Coda', 'dfn-theme'); ?></button>
                    </form>
                </div>

                <!-- COLONNA DX: La Coda -->
                <div style="flex: 2 1 500px; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px;">
                        <h3 style="margin: 0; color: #004b23; font-weight: 800; font-size: 18px;"><?php esc_html_e('Coda Richieste Attive', 'dfn-theme'); ?></h3>
                        <span style="font-size: 14px;"><?php esc_html_e('Stato Posti:', 'dfn-theme'); ?> <?php echo $availability_text; ?></span>
                    </div>

                    <table class="wp-list-table widefat fixed striped dfn-events-table">
                        <thead>
                            <tr>
                                <th style="width: 45px;"><?php esc_html_e('Pos', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Data Richiesta', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Cliente', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Contatti', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Quantità', 'dfn-theme'); ?></th>
                                <th style="text-align: right; width: 180px;"><?php esc_html_e('Azioni', 'dfn-theme'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($coda)) : ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 20px; color: #94a3b8;"><?php esc_html_e('Nessuna persona in lista d\'attesa per questo evento.', 'dfn-theme'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($coda as $index => $c_entry) :
                                    $pos = $index + 1;
                                    $tessere_badge = intval($c_entry->fai_cards) > 0
                                        ? '<span style="background: #c69c3a; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; margin-left: 5px;">' . intval($c_entry->fai_cards) . ' FAI</span>'
                                        : '';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo $pos; ?>°</strong></td>
                                        <td><?php echo date_i18n('d/m H:i', strtotime($c_entry->created_at)); ?></td>
                                        <td><strong><?php echo esc_html($c_entry->customer_name); ?></strong></td>
                                        <td>
                                            <div><?php echo esc_html($c_entry->customer_email); ?></div>
                                            <div style="font-size: 11px; color: #64748b;"><?php echo esc_html($c_entry->customer_phone); ?></div>
                                        </td>
                                        <td><strong><?php echo intval($c_entry->persons); ?></strong><?php echo $tessere_badge; ?></td>
                                        <td style="text-align: right;">
                                            <form method="POST" style="display: flex; gap: 5px; justify-content: flex-end; margin: 0;" onsubmit="return confirm('Confermi l\'azione?');">
                                                <?php wp_nonce_field('dfn_waitlist_action', 'dfn_waitlist_action_nonce'); ?>
                                                <input type="hidden" name="entry_id" value="<?php echo intval($c_entry->id); ?>">
                                                
                                                <button type="submit" name="wl_action" value="promote" class="button" style="background: #004b23; color: #fff; border: none; font-size: 12px; font-weight: 700;" title="<?php esc_attr_e('Crea ordine ed invia email con link di pagamento', 'dfn-theme'); ?>"><?php esc_html_e('Promuovi', 'dfn-theme'); ?></button>
                                                <button type="submit" name="wl_action" value="delete" class="button dfn-btn-delete" style="font-size: 12px;" title="<?php esc_attr_e('Elimina entry', 'dfn-theme'); ?>">❌</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

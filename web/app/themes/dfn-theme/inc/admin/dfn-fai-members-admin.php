<?php
/**
 * DFN Booking System 2.0 — FAI Members Administration Panel
 *
 * Fornisce un'interfaccia elegante e moderna per gestire l'anagrafica
 * dei soci FAI (tabella custom dfn_fai_members) con filtri e creazione guidata.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_fai_members_register_menu');

/**
 * Registra il sottomenu dell'Anagrafica Soci FAI.
 */
function dfn_fai_members_register_menu(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    $unverified_count = 0;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
        $unverified_count = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE verified = 0"));
    }

    $menu_title = esc_html__('Soci FAI', 'dfn-theme');
    if ($unverified_count > 0) {
        $menu_title .= sprintf(' <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>', $unverified_count, $unverified_count);
    }

    add_submenu_page(
        'dfn-events',
        esc_html__('Anagrafica Soci FAI', 'dfn-theme'),
        $menu_title,
        'dfn_manage_events',
        'dfn-fai-members',
        'dfn_render_fai_members_page',
    );
}

/**
 * Renderizza la pagina di gestione anagrafica dei Soci FAI.
 */
function dfn_render_fai_members_page(): void
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi per accedere a questa pagina.', 'dfn-theme'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    // Gestione Azioni via GET (es: Eliminazione rapida securizzata tramite Nonce)
    $message = '';
    $message_type = 'success';

    // GESTIONE AZIONI DI MASSA (BULK ACTIONS)
    if (isset($_POST['dfn_fai_bulk_action_submit']) && ! empty($_POST['member_ids']) && is_array($_POST['member_ids'])) {
        if (isset($_POST['dfn_fai_bulk_nonce']) && wp_verify_nonce($_POST['dfn_fai_bulk_nonce'], 'dfn_fai_bulk_action')) {
            $bulk_action = sanitize_text_field($_POST['bulk_action']);
            $member_ids  = array_map('intval', $_POST['member_ids']);
            $count       = count($member_ids);

            if ('approve' === $bulk_action) {
                foreach ($member_ids as $m_id) {
                    $wpdb->update(
                        $table,
                        [
                            'verified'    => 1,
                            'verified_by' => get_current_user_id(),
                            'verified_at' => current_time('mysql'),
                        ],
                        [ 'id' => $m_id ],
                        [ '%d', '%d', '%s' ],
                        [ '%d' ]
                    );
                    $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $m_id));
                    if ($m && ! empty($m->email)) {
                        dfn_send_fai_card_approved_email($m->email, $m->first_name, $m->last_name, $m->card_number);
                    }
                }
                $message = sprintf(esc_html__('Selezionati %d soci FAI approvati con successo.', 'dfn-theme'), $count);
            } elseif ('reject' === $bulk_action) {
                $reason = isset($_POST['bulk_reject_reason']) ? sanitize_text_field($_POST['bulk_reject_reason']) : __('Tessera non valida', 'dfn-theme');
                foreach ($member_ids as $m_id) {
                    $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $m_id));
                    if ($m) {
                        if (! empty($m->email)) {
                            dfn_send_fai_card_rejected_email($m->email, $m->first_name, $m->last_name, $m->card_number, $reason);
                        }
                        $wpdb->delete($table, [ 'id' => $m_id ], [ '%d' ]);
                        if (! function_exists('dfn_cancel_pending_bookings_for_rejected_fai_card')) {
                            require_once get_template_directory() . '/inc/api/dfn-ajax-bookings.php';
                        }
                        if (function_exists('dfn_cancel_pending_bookings_for_rejected_fai_card')) {
                            dfn_cancel_pending_bookings_for_rejected_fai_card($m->card_number, $reason);
                        }
                    }
                }
                $message = sprintf(esc_html__('Selezionate %d tessere FAI rifiutate ed eliminate.', 'dfn-theme'), $count);
            } elseif ('delete' === $bulk_action) {
                foreach ($member_ids as $m_id) {
                    $wpdb->delete($table, [ 'id' => $m_id ], [ '%d' ]);
                }
                $message = sprintf(esc_html__('Selezionati %d soci FAI eliminati con successo.', 'dfn-theme'), $count);
            }
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['member_id'])) {
        $member_id = intval($_GET['member_id']);
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_fai_' . $member_id)) {
            $wpdb->delete($table, [ 'id' => $member_id ], [ '%d' ]);
            $message = esc_html__('Socio FAI rimosso con successo.', 'dfn-theme');
        } else {
            $message = esc_html__('Errore di sicurezza: verifica fallita.', 'dfn-theme');
            $message_type = 'error';
        }
    }

    // AZIONE RAPIDA: Approva Tessera FAI da verificare
    if (isset($_GET['action']) && $_GET['action'] === 'approve' && isset($_GET['member_id'])) {
        $member_id = intval($_GET['member_id']);
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dfn_approve_fai_' . $member_id)) {
            $wpdb->update(
                $table,
                [
                    'verified'    => 1,
                    'verified_by' => get_current_user_id(),
                    'verified_at' => current_time('mysql'),
                ],
                [ 'id' => $member_id ],
                [ '%d', '%d', '%s' ],
                [ '%d' ],
            );

            // Invia notifica email di approvazione
            /** @var \stdClass|null $m */
            $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $member_id));
            if ($m && ! empty($m->email)) {
                dfn_send_fai_card_approved_email($m->email, $m->first_name, $m->last_name, $m->card_number);
            }

            $message = esc_html__('Socio FAI approvato e notificato con successo.', 'dfn-theme');
        } else {
            $message = esc_html__('Errore di sicurezza: verifica fallita.', 'dfn-theme');
            $message_type = 'error';
        }
    }

    // AZIONE RAPIDA: Sottomissione Rifiuto Tessera FAI
    if (isset($_POST['dfn_reject_fai_submit'])) {
        if (isset($_POST['dfn_reject_nonce']) && wp_verify_nonce($_POST['dfn_reject_nonce'], 'dfn_reject_fai_action')) {
            $member_id = intval($_POST['member_id']);
            $reason    = sanitize_text_field($_POST['reject_reason']);

            /** @var \stdClass|null $m */
            $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $member_id));
            if ($m) {
                // Invia notifica email di rifiuto se l'email esiste
                if (! empty($m->email)) {
                    dfn_send_fai_card_rejected_email($m->email, $m->first_name, $m->last_name, $m->card_number, $reason);
                }

                // Rimuovi la tessera non valida dal database
                $wpdb->delete($table, [ 'id' => $member_id ], [ '%d' ]);

                // Annulla le prenotazioni pendenti associate a questa tessera rifiutata
                if (! function_exists('dfn_cancel_pending_bookings_for_rejected_fai_card')) {
                    require_once get_template_directory() . '/inc/api/dfn-ajax-bookings.php';
                }
                if (function_exists('dfn_cancel_pending_bookings_for_rejected_fai_card')) {
                    dfn_cancel_pending_bookings_for_rejected_fai_card($m->card_number, $reason);
                }

                $message = esc_html__('Tessera FAI rifiutata e notifica inviata all\'utente.', 'dfn-theme');
            } else {
                $message = esc_html__('Tessera FAI non trovata.', 'dfn-theme');
                $message_type = 'error';
            }
        }
    }

    // Gestione Form d'Aggiunta/Modifica manuale diretto (semplificato)
    if (isset($_POST['dfn_save_fai_member_submit'])) {
        if (isset($_POST['dfn_fai_form_nonce']) && wp_verify_nonce($_POST['dfn_fai_form_nonce'], 'dfn_save_fai_member_action')) {
            $id          = intval($_POST['member_id']);
            $first_name  = sanitize_text_field($_POST['first_name']);
            $last_name   = sanitize_text_field($_POST['last_name']);
            $email       = ! empty($_POST['email']) ? sanitize_email($_POST['email']) : null;
            $phone       = sanitize_text_field($_POST['phone']);
            $card_number = sanitize_text_field($_POST['card_number']);
            $card_expiry = ! empty($_POST['card_expiry']) ? sanitize_text_field($_POST['card_expiry']) : null;
            $card_type   = isset($_POST['card_type']) ? sanitize_text_field($_POST['card_type']) : 'INDIVIDUALE';
            $user_id     = ! empty($_POST['user_id']) ? intval($_POST['user_id']) : null;

            if ($user_id > 0 && empty($email)) {
                $u_data = get_userdata($user_id);
                if ($u_data && ! empty($u_data->user_email)) {
                    $email = $u_data->user_email;
                }
            }

            $types_string = dfn_get_setting('fai_member_types', 'INDIVIDUALE, COPPIA, FAMIGLIA');
            $valid_types = array_map('trim', array_map('strtoupper', explode(',', $types_string)));
            if (! in_array(strtoupper($card_type), $valid_types, true)) {
                $card_type = ! empty($valid_types[0]) ? $valid_types[0] : 'INDIVIDUALE';
            }

            if (! empty($first_name) && ! empty($last_name) && ! empty($card_number)) {
                $old_member = null;
                if ($id > 0) {
                    $old_member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
                }

                // Controllo unicità del numero tessera
                $duplicate_query = $id > 0
                    ? $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE card_number = %s AND id != %d", $card_number, $id)
                    : $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE card_number = %s", $card_number);

                $exists = $wpdb->get_var($duplicate_query) ?: 0;

                if ($exists > 0) {
                    $message = esc_html__('Errore: Esiste già una tessera FAI registrata con questo numero.', 'dfn-theme');
                    $message_type = 'error';
                } else {
                    $data = [
                        'first_name'  => $first_name,
                        'last_name'   => $last_name,
                        'email'       => $email,
                        'phone'       => ! empty($phone) ? $phone : null,
                        'card_number' => $card_number,
                        'card_expiry' => $card_expiry,
                        'card_type'   => $card_type,
                        'user_id'     => $user_id,
                        'verified'    => 1,
                        'verified_by' => get_current_user_id(),
                        'verified_at' => current_time('mysql'),
                    ];
                    $formats = [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ];

                    if ($id > 0) {
                        $wpdb->update($table, $data, [ 'id' => $id ], $formats, [ '%d' ]);
                        $message = esc_html__('Socio FAI aggiornato correttamente.', 'dfn-theme');

                        // Se era da verificare e ora viene salvato (quindi verificato)
                        if ($old_member && intval($old_member->verified) === 0 && ! empty($email)) {
                            dfn_send_fai_card_approved_email($email, $first_name, $last_name, $card_number);
                        }
                    } else {
                        $wpdb->insert($table, $data, $formats);
                        $message = esc_html__('Nuovo socio FAI registrato correttamente.', 'dfn-theme');
                    }
                }
            } else {
                $message = esc_html__('Tutti i campi obbligatori devono essere compilati.', 'dfn-theme');
                $message_type = 'error';
            }
        }
    }

    // Modalità Modifica o Rifiuto: carica i dati se richiesto
    $edit_member = null;
    if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['member_id'])) {
        $member_id = intval($_GET['member_id']);
        $edit_member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $member_id));
    }

    $reject_member = null;
    if (isset($_GET['action']) && $_GET['action'] === 'reject' && isset($_GET['member_id'])) {
        $member_id = intval($_GET['member_id']);
        $reject_member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $member_id));
    }

    // Conteggio delle tessere in scadenza
    $warning_days = intval(dfn_get_setting('fai_expiry_warning_days', 15));
    $expiring_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} 
         WHERE verified = 1 
           AND card_expiry IS NOT NULL 
           AND card_expiry >= CURDATE() 
           AND card_expiry <= DATE_ADD(CURDATE(), INTERVAL %d DAY)",
        $warning_days,
    )) ?: 0;

    // Filtro e ricerca in tempo reale
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : '';
    
    // 1. Query per Tessere da Verificare (verified = 0)
    if (! empty($search)) {
        $search_query = '%' . $wpdb->esc_like($search) . '%';
        $unverified_members = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE verified = 0 
               AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR card_number LIKE %s)
             ORDER BY created_at DESC",
            $search_query,
            $search_query,
            $search_query,
            $search_query
        ));
    } else {
        $unverified_members = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE verified = 0 ORDER BY created_at DESC"
        );
    }

    // 2. Query per Soci FAI Registrati (verified = 1)
    $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'date';
    $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';

    $orderby_sql = 'created_at';
    if ($orderby === 'name') {
        $orderby_sql = 'last_name ASC, first_name';
    }

    $order_sql = (strtolower($order) === 'asc') ? 'ASC' : 'DESC';

    if ('expiring' === $filter) {
        if (! empty($search)) {
            $search_query = '%' . $wpdb->esc_like($search) . '%';
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE verified = 1 
                   AND card_expiry IS NOT NULL 
                   AND card_expiry >= CURDATE() 
                   AND card_expiry <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
                   AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR card_number LIKE %s)
                 ORDER BY {$orderby_sql} {$order_sql}",
                $warning_days,
                $search_query,
                $search_query,
                $search_query,
                $search_query,
            ));
        } else {
            $members = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE verified = 1 
                   AND card_expiry IS NOT NULL 
                   AND card_expiry >= CURDATE() 
                   AND card_expiry <= DATE_ADD(CURDATE(), INTERVAL %d DAY) 
                 ORDER BY {$orderby_sql} {$order_sql}",
                $warning_days,
            ));
        }
    } elseif (! empty($search)) {
        $search_query = '%' . $wpdb->esc_like($search) . '%';
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE verified = 1 
               AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR card_number LIKE %s) 
             ORDER BY {$orderby_sql} {$order_sql}",
            $search_query,
            $search_query,
            $search_query,
            $search_query,
        ));
    } else {
        $max_visible_members = intval(dfn_get_setting('limit_max_fai_members', 100));
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE verified = 1 
             ORDER BY {$orderby_sql} {$order_sql} 
             LIMIT %d",
            $max_visible_members
        ));
    }

    // Toggle per link ordinamento
    $toggle_name_order = ($orderby === 'name' && $order === 'asc') ? 'desc' : 'asc';
    $name_sort_url = add_query_arg([ 'orderby' => 'name', 'order' => $toggle_name_order ]);

    $toggle_date_order = ($orderby === 'date' && $order === 'desc') ? 'asc' : 'desc';
    $date_sort_url = add_query_arg([ 'orderby' => 'date', 'order' => $toggle_date_order ]);
    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom: 25px;">
            <div class="dfn-logo-area">
                <span class="dashicons dashicons-id"></span>
                <h1><?php esc_html_e('Anagrafica Soci FAI', 'dfn-theme'); ?></h1>
            </div>
        </header>

        <?php if (! empty($message)) : ?>
            <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($expiring_count > 0) : ?>
            <div class="notice notice-warning is-dismissible" style="border-left-color: #f59e0b; padding: 12px 15px;">
                <p style="margin: 0; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-warning" style="color: #f59e0b; margin-top: -2px;"></span>
                    <span>
                        <?php
                        $warning_days = intval(dfn_get_setting('fai_expiry_warning_days', 15));
                        printf(
                            // Translators: %d is the number of expiring cards, %d is the warning days limit
                            _n(
                                'C\'è %d tessera FAI verificata in scadenza nei prossimi %d giorni.',
                                'Ci sono %d tessere FAI verificate in scadenza nei prossimi %d giorni.',
                                $expiring_count,
                                'dfn-theme',
                            ),
                            $expiring_count,
                            $warning_days,
                        );
                        ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-fai-members&filter=expiring')); ?>" style="margin-left: 10px; font-weight: bold; color: #b45309; text-decoration: underline;">
                            <?php esc_html_e('Visualizza tessere in scadenza', 'dfn-theme'); ?>
                        </a>
                        <?php if ('expiring' === $filter) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-fai-members')); ?>" style="margin-left: 10px; font-weight: normal; color: #475569; text-decoration: underline;">
                                <?php esc_html_e('Mostra tutte', 'dfn-theme'); ?>
                            </a>
                        <?php endif; ?>
                    </span>
                </p>
            </div>
        <?php endif; ?>

        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
            <!-- Form d'Inserimento / Modifica / Rifiuto (Colonna Sinistra) -->
            <div style="flex: 1 1 350px; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; height: fit-content;">
                <?php if ($reject_member) : ?>
                    <h3 style="margin-top: 0; color: #b91c1c; font-weight: 800; font-size: 18px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                        <?php esc_html_e('Rifiuta Tessera FAI', 'dfn-theme'); ?>
                    </h3>
                    <form method="POST" style="margin-top: 15px;">
                        <?php wp_nonce_field('dfn_reject_fai_action', 'dfn_reject_nonce'); ?>
                        <input type="hidden" name="member_id" value="<?php echo intval($reject_member->id); ?>">
                        
                        <p style="font-size: 13px; line-height: 1.4; color: #334155;">
                            Stai rifiutando la tessera n° <code><?php echo esc_html($reject_member->card_number); ?></code> inserita da <strong><?php echo esc_html($reject_member->first_name . ' ' . $reject_member->last_name); ?></strong> (<?php echo esc_html($reject_member->email); ?>).
                        </p>
                        
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Motivazione del Rifiuto *', 'dfn-theme'); ?></label>
                            <textarea name="reject_reason" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;" rows="4" placeholder="<?php esc_attr_e('Es: Tessera scaduta il 12/2025, intestatario non corrispondente, codice inesistente...', 'dfn-theme'); ?>"></textarea>
                        </div>
                        
                        <button type="submit" name="dfn_reject_fai_submit" class="button" style="width: 100%; padding: 10px; height: auto; font-size: 14px; font-weight: 700; background: #b91c1c; color: white; border: none; border-radius: 6px; cursor: pointer;"><?php esc_html_e('Invia Notifica e Rifiuta', 'dfn-theme'); ?></button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-fai-members')); ?>" class="button" style="width: 100%; text-align: center; margin-top: 10px; box-sizing: border-box; padding: 6px;"><?php esc_html_e('Annulla', 'dfn-theme'); ?></a>
                    </form>
                <?php else : ?>
                    <h3 style="margin-top: 0; color: #004b23; font-weight: 800; font-size: 18px; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;">
                        <?php echo $edit_member ? esc_html__('Modifica Socio FAI', 'dfn-theme') : esc_html__('Aggiungi Nuovo Socio', 'dfn-theme'); ?>
                    </h3>
                    
                    <form method="POST" style="margin-top: 15px;">
                        <?php wp_nonce_field('dfn_save_fai_member_action', 'dfn_fai_form_nonce'); ?>
                        <input type="hidden" name="member_id" value="<?php echo $edit_member ? intval($edit_member->id) : 0; ?>">
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Nome *', 'dfn-theme'); ?></label>
                            <input type="text" name="first_name" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr($edit_member->first_name) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Cognome *', 'dfn-theme'); ?></label>
                            <input type="text" name="last_name" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr($edit_member->last_name) : ''; ?>">
                        </div>
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Associa ad Utente Registrato (Opzionale)', 'dfn-theme'); ?></label>
                            <select name="user_id" id="dfn_fai_user_select" class="dfn-select2-user" style="width: 100%;">
                                <option value=""><?php esc_html_e('-- Nessun utente associato --', 'dfn-theme'); ?></option>
                                <?php
                                $selected_user_id = $edit_member && ! empty($edit_member->user_id) ? intval($edit_member->user_id) : 0;
                                if ($selected_user_id > 0) {
                                    $u = get_userdata($selected_user_id);
                                    if ($u) {
                                        echo '<option value="' . esc_attr($u->ID) . '" selected>' . esc_html($u->display_name . ' (' . $u->user_email . ') #' . $u->ID) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                            <p style="font-size: 11px; color: #64748b; margin: 4px 0 0 0; line-height: 1.3;">
                                <?php esc_html_e('Puoi associare la tessera ad un utente del sito (es. per tessere di coppia). Comparirà nella sua area riservata.', 'dfn-theme'); ?>
                            </p>
                        </div>

                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Email', 'dfn-theme'); ?></label>
                            <input type="email" name="email" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr($edit_member->email) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Telefono', 'dfn-theme'); ?></label>
                            <input type="text" name="phone" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr($edit_member->phone) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 12px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Numero Tessera *', 'dfn-theme'); ?></label>
                            <input type="text" name="card_number" required style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr($edit_member->card_number) : ''; ?>">
                        </div>
 
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Scadenza Tessera', 'dfn-theme'); ?></label>
                            <input type="date" name="card_expiry" style="width: 100%; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;" value="<?php echo $edit_member ? esc_attr($edit_member->card_expiry) : ''; ?>">
                        </div>

                        <div style="margin-bottom: 20px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;"><?php esc_html_e('Tipologia Tessera', 'dfn-theme'); ?></label>
                            <select name="card_type" style="width: 100%; padding: 8px 30px 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; height: 38px; font-size: 13px; font-weight: 600; font-family: inherit; line-height: 1.4; max-width: 100%; box-sizing: border-box;">
                                <?php
                                $selected_type = $edit_member && ! empty($edit_member->card_type) ? $edit_member->card_type : 'INDIVIDUALE';

                                $types_string = dfn_get_setting('fai_member_types', 'INDIVIDUALE, COPPIA, FAMIGLIA');
                                $valid_types = array_map('trim', explode(',', strtoupper($types_string)));
                                $options = [];
                                foreach ($valid_types as $t) {
                                    if (! empty($t)) {
                                        $options[$t] = esc_html($t);
                                    }
                                }
                                if (empty($options)) {
                                    $options['INDIVIDUALE'] = 'INDIVIDUALE';
                                }

                                foreach ($options as $val => $label) {
                                    echo '<option value="' . esc_attr($val) . '" ' . selected($selected_type, $val, false) . '>' . esc_html($label) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
 
                        <button type="submit" name="dfn_save_fai_member_submit" class="button button-primary" style="width: 100%; padding: 10px; height: auto; font-size: 14px; font-weight: 700; background: #004b23; border: none; border-radius: 6px; cursor: pointer;"><?php echo $edit_member ? esc_html__('Salva Modifiche', 'dfn-theme') : esc_html__('Aggiungi Socio', 'dfn-theme'); ?></button>
                        <?php if ($edit_member) : ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-fai-members')); ?>" class="button" style="width: 100%; text-align: center; margin-top: 10px; box-sizing: border-box; padding: 6px;"><?php esc_html_e('Annulla Modifica', 'dfn-theme'); ?></a>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
 
            <!-- Tabella dei Soci (Colonna Destra - Doppia sezione) -->
            <div style="flex: 2 1 600px; display: flex; flex-direction: column; gap: 30px;">
                
                <!-- 1. SEZIONE: TESSERE DA VERIFICARE -->
                <?php if (! empty($unverified_members)) : ?>
                    <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; border-top: 4px solid #e74f30;">
                        <form method="POST" id="dfn-fai-unverified-bulk-form">
                            <?php wp_nonce_field('dfn_fai_bulk_action', 'dfn_fai_bulk_nonce'); ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px;">
                                <h3 style="margin: 0; color: #e74f30; font-weight: 800; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-warning" style="color:#e74f30;"></span>
                                    <?php esc_html_e('Tessere da Verificare', 'dfn-theme'); ?>
                                </h3>

                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <select name="bulk_action" style="padding: 4px 8px; border-radius: 4px; border: 1px solid #cbd5e1; font-size: 13px;">
                                        <option value=""><?php esc_html_e('Azioni di massa', 'dfn-theme'); ?></option>
                                        <option value="approve"><?php esc_html_e('Approva tessere selezionate', 'dfn-theme'); ?></option>
                                        <option value="reject"><?php esc_html_e('Rifiuta tessere selezionate', 'dfn-theme'); ?></option>
                                        <option value="delete"><?php esc_html_e('Elimina selezionati', 'dfn-theme'); ?></option>
                                    </select>
                                    <button type="submit" name="dfn_fai_bulk_action_submit" class="button button-secondary" style="font-weight: 600;" onclick="return confirm('Applicare l\'azione di massa alle tessere selezionate?');"><?php esc_html_e('Applica', 'dfn-theme'); ?></button>
                                </div>
                            </div>

                            <table class="wp-list-table widefat fixed striped dfn-events-table">
                                <thead>
                                    <tr>
                                        <td class="manage-column column-cb check-column" style="width: 32px;"><input type="checkbox" id="dfn-select-all-unverified"></td>
                                        <th><?php esc_html_e('Cognome & Nome', 'dfn-theme'); ?></th>
                                    <th><?php esc_html_e('Email', 'dfn-theme'); ?></th>
                                    <th><?php esc_html_e('Numero Tessera', 'dfn-theme'); ?></th>
                                    <th><?php esc_html_e('Tipologia', 'dfn-theme'); ?></th>
                                    <th><?php esc_html_e('Data Ricezione', 'dfn-theme'); ?></th>
                                    <th><?php esc_html_e('Stato', 'dfn-theme'); ?></th>
                                    <th><?php esc_html_e('Azioni', 'dfn-theme'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unverified_members as $m) :
                                    $status_class = 'dfn-status-draft';
                                    $status_label = esc_html__('Da verificare', 'dfn-theme');
                                    $custom_badge_style = 'background: #fff3cd; color: #856404; border: 1px solid #ffeeba;';
                                    ?>
                                    <tr>
                                        <th scope="row" class="check-column"><input type="checkbox" name="member_ids[]" value="<?php echo absint($m->id); ?>"></th>
                                        <td>
                                            <strong><?php echo esc_html($m->last_name . ' ' . $m->first_name); ?></strong>
                                            <?php if (! empty($m->user_id)) :
                                                $u_linked = get_userdata($m->user_id);
                                                if ($u_linked) : ?>
                                                    <div style="font-size: 11px; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px; margin-top: 3px; display: inline-block; font-weight: 600;" title="<?php esc_attr_e('Utente registrato collegato', 'dfn-theme'); ?>">
                                                        👤 <?php echo esc_html($u_linked->display_name); ?>
                                                    </div>
                                                <?php endif;
                                            endif; ?>
                                        </td>
                                        <td><?php echo esc_html($m->email ?: ''); ?></td>
                                        <td><code><?php echo esc_html($m->card_number); ?></code></td>
                                        <td>
                                            <?php
                                            $type = ! empty($m->card_type) ? esc_html($m->card_type) : 'INDIVIDUALE';
                                            $type_badge_style = 'font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 4px; display: inline-block; ';
                                            if ('FAMIGLIA' === $type) {
                                                $type_badge_style .= 'background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8;';
                                            } elseif ('COPPIA' === $type) {
                                                $type_badge_style .= 'background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;';
                                            } else {
                                                $type_badge_style .= 'background: #f8fafc; color: #475569; border: 1px solid #e2e8f0;';
                                            }
                                            ?>
                                            <span style="<?php echo esc_attr($type_badge_style); ?>"><?php echo esc_html($type); ?></span>
                                        </td>
                                        <td><strong><?php echo ! empty($m->created_at) ? date_i18n('d/m/Y H:i', strtotime($m->created_at)) : esc_html__('Non disponibile', 'dfn-theme'); ?></strong></td>
                                        <td>
                                            <span class="dfn-badge <?php echo esc_attr($status_class); ?>" style="<?php echo esc_attr($custom_badge_style); ?>"><?php echo esc_html($status_label); ?></span>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <div style="display: inline-flex; align-items: center; gap: 8px;">
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-fai-members&action=approve&member_id=' . $m->id), 'dfn_approve_fai_' . $m->id)); ?>" style="color: #16a34a; text-decoration: none; display: inline-flex; align-items: center;" title="<?php esc_attr_e('Applica sconto e approva', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-yes-alt" style="font-size: 20px; width: 20px; height: 20px;"></span>
                                                </a>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-fai-members&action=reject&member_id=' . $m->id)); ?>" style="color: #dc2626; text-decoration: none; display: inline-flex; align-items: center;" title="<?php esc_attr_e('Rifiuta e spiega motivo', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-dismiss" style="font-size: 20px; width: 20px; height: 20px;"></span>
                                                </a>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-fai-members&action=delete&member_id=' . $m->id), 'dfn_del_fai_' . $m->id)); ?>" style="color: #64748b; text-decoration: none; display: inline-flex; align-items: center;" title="<?php esc_attr_e('Elimina socio permanentemente', 'dfn-theme'); ?>" onclick="return confirm('Sei sicuro di voler eliminare questo socio?');">
                                                    <span class="dashicons dashicons-trash" style="font-size: 20px; width: 20px; height: 20px;"></span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- 2. SEZIONE: SOCI FAI REGISTRATI (VERIFICATI) -->
                <div style="background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; border-top: 4px solid #004b23;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; margin-bottom: 20px;">
                        <h3 style="margin: 0; color: #004b23; font-weight: 800; font-size: 18px;"><?php esc_html_e('Soci FAI Registrati', 'dfn-theme'); ?></h3>
                        
                        <form method="GET" style="display: flex; gap: 8px;">
                            <input type="hidden" name="page" value="dfn-fai-members">
                            <?php if (! empty($filter)) : ?>
                                <input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>">
                            <?php endif; ?>
                            <?php if (! empty($orderby)) : ?>
                                <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
                            <?php endif; ?>
                            <?php if (! empty($order)) : ?>
                                <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
                            <?php endif; ?>
                            <input type="text" name="s" placeholder="<?php esc_attr_e('Cerca socio...', 'dfn-theme'); ?>" value="<?php echo esc_attr($search); ?>" style="padding: 5px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;">
                            <button type="submit" class="button" style="font-weight: 700;"><?php esc_html_e('Cerca', 'dfn-theme'); ?></button>
                        </form>
                    </div>

                    <?php if ('expiring' === $filter) : ?>
                        <div style="margin-bottom: 15px; padding: 10px 12px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; font-size: 13px; color: #b45309; display: flex; align-items: center; justify-content: space-between;">
                            <span>
                                <strong><?php esc_html_e('Filtro attivo:', 'dfn-theme'); ?></strong>
                                <?php esc_html_e('Tessere in scadenza nei prossimi 15 giorni', 'dfn-theme'); ?>
                            </span>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-fai-members')); ?>" class="button button-small" style="color: #b91c1c; border-color: #fca5a5; background: #fef2f2;"><?php esc_html_e('Rimuovi Filtro', 'dfn-theme'); ?></a>
                        </div>
                    <?php endif; ?>
     
                    <table class="wp-list-table widefat fixed striped dfn-events-table">
                        <thead>
                            <tr>
                                <th class="manage-column <?php echo ($orderby === 'name') ? 'sorted ' . $order : 'sortable'; ?>">
                                    <a href="<?php echo esc_url($name_sort_url); ?>" style="display:inline-flex; align-items:center; gap:4px; text-decoration:none; color:inherit;">
                                        <span><?php esc_html_e('Cognome & Nome', 'dfn-theme'); ?></span>
                                        <span class="dashicons <?php echo ($orderby === 'name') ? (($order === 'asc') ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2') : 'dashicons-sort'; ?>" style="font-size:14px; width:14px; height:14px;"></span>
                                    </a>
                                </th>
                                <th><?php esc_html_e('Email', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Numero Tessera', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Tipologia', 'dfn-theme'); ?></th>
                                <th class="manage-column <?php echo ($orderby === 'date') ? 'sorted ' . $order : 'sortable'; ?>">
                                    <a href="<?php echo esc_url($date_sort_url); ?>" style="display:inline-flex; align-items:center; gap:4px; text-decoration:none; color:inherit;">
                                        <span><?php esc_html_e('Data Ricezione', 'dfn-theme'); ?></span>
                                        <span class="dashicons <?php echo ($orderby === 'date') ? (($order === 'asc') ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2') : 'dashicons-sort'; ?>" style="font-size:14px; width:14px; height:14px;"></span>
                                    </a>
                                </th>
                                <th><?php esc_html_e('Scadenza Tessera', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Stato', 'dfn-theme'); ?></th>
                                <th><?php esc_html_e('Azioni', 'dfn-theme'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($members)) : ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 20px; color: #94a3b8;"><?php esc_html_e('Nessun socio FAI registrato o corrispondente alla ricerca.', 'dfn-theme'); ?></td>
                                </tr>
                            <?php else : ?>
                                <?php foreach ($members as $m) :
                                    $is_expired   = ! empty($m->card_expiry) && strtotime($m->card_expiry) < time();
                                    $is_verified  = intval($m->verified) === 1;

                                    $is_expiring = false;
                                    if ($is_verified && ! empty($m->card_expiry) && ! $is_expired) {
                                        $expiry_time = strtotime($m->card_expiry);
                                        $warning_days = intval(dfn_get_setting('fai_expiry_warning_days', 15));
                                        $limit_time  = strtotime('+' . $warning_days . ' days 23:59:59');
                                        if ($expiry_time <= $limit_time) {
                                            $is_expiring = true;
                                        }
                                    }

                                    if (! $is_verified) {
                                        $status_class = 'dfn-status-draft';
                                        $status_label = esc_html__('Da verificare', 'dfn-theme');
                                        $custom_badge_style = 'background: #fff3cd; color: #856404; border: 1px solid #ffeeba;';
                                    } elseif (empty($m->card_expiry)) {
                                        $status_class = 'dfn-status-draft';
                                        $status_label = esc_html__('Senza scadenza', 'dfn-theme');
                                        $custom_badge_style = 'background: #e2e8f0; color: #475569; border: 1px solid #cbd5e1;';
                                    } elseif ($is_expired) {
                                        $status_class = 'dfn-status-draft';
                                        $status_label = esc_html__('Scaduta', 'dfn-theme');
                                        $custom_badge_style = 'background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;';
                                    } elseif ($is_expiring) {
                                        $status_class = 'dfn-status-draft';
                                        $status_label = esc_html__('In scadenza', 'dfn-theme');
                                        $custom_badge_style = 'background: #ffedd5; color: #c2410c; border: 1px solid #fed7aa;';
                                    } else {
                                        $status_class = 'dfn-status-published';
                                        $status_label = esc_html__('Attiva', 'dfn-theme');
                                        $custom_badge_style = 'background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($m->last_name . ' ' . $m->first_name); ?></strong>
                                            <?php if (! empty($m->user_id)) :
                                                $u_linked = get_userdata($m->user_id);
                                                if ($u_linked) : ?>
                                                    <div style="font-size: 11px; color: #0284c7; background: #e0f2fe; padding: 2px 6px; border-radius: 4px; margin-top: 3px; display: inline-block; font-weight: 600;" title="<?php esc_attr_e('Utente registrato collegato', 'dfn-theme'); ?>">
                                                        👤 <?php echo esc_html($u_linked->display_name); ?>
                                                    </div>
                                                <?php endif;
                                            endif; ?>
                                        </td>
                                        <td><?php echo esc_html($m->email ?: ''); ?></td>
                                        <td><code><?php echo esc_html($m->card_number); ?></code></td>
                                        <td>
                                            <?php
                                            $type = ! empty($m->card_type) ? esc_html($m->card_type) : 'INDIVIDUALE';
                                            $type_badge_style = 'font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 4px; display: inline-block; ';
                                            if ('FAMIGLIA' === $type) {
                                                $type_badge_style .= 'background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8;';
                                            } elseif ('COPPIA' === $type) {
                                                $type_badge_style .= 'background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;';
                                            } else {
                                                $type_badge_style .= 'background: #f8fafc; color: #475569; border: 1px solid #e2e8f0;';
                                            }
                                            ?>
                                            <span style="<?php echo esc_attr($type_badge_style); ?>"><?php echo esc_html($type); ?></span>
                                        </td>
                                        <td><?php echo ! empty($m->created_at) ? date_i18n('d/m/Y H:i', strtotime($m->created_at)) : esc_html__('Non disponibile', 'dfn-theme'); ?></td>
                                        <td><strong><?php echo ! empty($m->card_expiry) ? date_i18n('d M Y', strtotime($m->card_expiry)) : esc_html__('Da definire', 'dfn-theme'); ?></strong></td>
                                        <td>
                                            <span class="dfn-badge <?php echo esc_attr($status_class); ?>" style="<?php echo esc_attr($custom_badge_style); ?>"><?php echo esc_html($status_label); ?></span>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <div style="display: inline-flex; align-items: center; gap: 8px;">
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-fai-members&action=edit&member_id=' . $m->id)); ?>" style="color: #004b23; text-decoration: none; display: inline-flex; align-items: center;" title="<?php esc_attr_e('Modifica dati socio', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-edit" style="font-size: 18px; width: 18px; height: 18px;"></span>
                                                </a>
                                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=dfn-fai-members&action=delete&member_id=' . $m->id), 'dfn_del_fai_' . $m->id)); ?>" style="color: #64748b; text-decoration: none; display: inline-flex; align-items: center;" title="<?php esc_attr_e('Elimina socio permanentemente', 'dfn-theme'); ?>" onclick="return confirm('Sei sicuro di voler eliminare questo socio?');">
                                                    <span class="dashicons dashicons-trash" style="font-size: 18px; width: 18px; height: 18px;"></span>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($) {
        $('#dfn-select-all-unverified').on('change', function() {
            var checked = $(this).prop('checked');
            $('#dfn-fai-unverified-bulk-form input[name="member_ids[]"]').prop('checked', checked);
        });

        if ($.fn.selectWoo || $.fn.select2) {
            var $userSelect = $('#dfn_fai_user_select');
            var selectFn = $.fn.selectWoo ? 'selectWoo' : 'select2';
            $userSelect[selectFn]({
                allowClear: true,
                placeholder: '<?php esc_attr_e('-- Cerca o seleziona un utente --', 'dfn-theme'); ?>',
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { action: 'cv_search_customers', term: params.term, security: '<?php echo wp_create_nonce("cv_ricerca_clienti_nonce"); ?>' };
                    },
                    processResults: function (data) { return { results: data }; },
                    cache: true
                },
                minimumInputLength: 2,
                language: { inputTooShort: function () { return 'Scrivi almeno 2 lettere...'; } }
            });

            $userSelect.on('select2:select selectWoo:select', function (e) {
                var data = e.params.data;
                if (data && data.id) {
                    var $emailInput = $('input[name="email"]');
                    if (!$emailInput.val()) {
                        $.post(ajaxurl, {
                            action: 'cv_get_customer_data', security: '<?php echo wp_create_nonce("cv_ricerca_clienti_nonce"); ?>', customer_id: data.id
                        }, function (response) {
                            if (response.success && response.data && response.data.email) {
                                $emailInput.val(response.data.email);
                            }
                        });
                    }
                }
            });
        }
    });
    </script>
    <?php
}

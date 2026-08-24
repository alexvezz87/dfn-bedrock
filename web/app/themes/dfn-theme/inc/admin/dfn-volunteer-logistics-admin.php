<?php
/**
 * DFN Booking System 2.0 — Modulo Gestione Logistica Turni Volontari FAI
 *
 * Gestisce il pannello amministrativo per la pianificazione degli eventi (Locali e Giornate FAI),
 * la griglia matrice dei turni per luogo/slot, l'algoritmo di bilanciamento automatico,
 * i sondaggi e la stampa/export PDF dei turni di delegazione.
 *
 * @package DFN_Theme
 * @since   2.4.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Intercetta la richiesta di stampa/export PDF isolata dall'interfaccia di WordPress
add_action('admin_init', 'dfn_handle_volunteer_event_print_intercept');

// AJAX Handler per lo spostamento drag & drop del volontario tra slot/giorni
add_action('wp_ajax_dfn_move_volunteer_shift', 'dfn_ajax_move_volunteer_shift');

function dfn_ajax_move_volunteer_shift(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    global $wpdb;
    $assignment_id   = (int) ($_POST['assignment_id'] ?? 0);
    $target_shift_id = (int) ($_POST['target_shift_id'] ?? 0);

    if ($assignment_id <= 0 || $target_shift_id <= 0) {
        wp_send_json_error(['message' => 'Parametri non validi.']);
    }

    // Recupera l'assegnazione corrente
    $current_ass = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE id = %d",
        $assignment_id
    ));

    if (! $current_ass) {
        wp_send_json_error(['message' => 'Assegnazione non trovata.']);
    }

    // Verifica che lo shift di destinazione esista
    $target_shift = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE id = %d",
        $target_shift_id
    ));

    if (! $target_shift) {
        wp_send_json_error(['message' => 'Slot di destinazione non trovato.']);
    }

    // Controllo anti-duplicazione: il volontario è già assegnato allo slot di destinazione?
    if (! empty($current_ass->volunteer_id)) {
        $already_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments 
             WHERE shift_id = %d AND volunteer_id = %d AND id != %d",
            $target_shift_id,
            $current_ass->volunteer_id,
            $assignment_id
        ));
    } else {
        $already_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments 
             WHERE shift_id = %d AND volunteer_name_manual = %s AND id != %d",
            $target_shift_id,
            $current_ass->volunteer_name_manual,
            $assignment_id
        ));
    }

    if ($already_exists > 0) {
        wp_send_json_error(['message' => 'Questo volontario è già assegnato a questo turno orario.']);
    }

    // Aggiorna lo shift_id dell'assegnazione
    $updated = $wpdb->update(
        $wpdb->prefix . 'dfn_volunteer_shift_assignments',
        [ 'shift_id' => $target_shift_id ],
        [ 'id' => $assignment_id ],
        [ '%d' ],
        [ '%d' ]
    );

    if ($updated === false) {
        wp_send_json_error(['message' => 'Errore nel salvataggio del database.']);
    }

    wp_send_json_success([
        'message' => 'Volontario spostato con successo!',
        'day_id'  => $target_shift->day_id,
    ]);
}

function dfn_handle_volunteer_event_print_intercept(): void
{
    if (isset($_GET['page'], $_GET['action'], $_GET['event_id']) && 
        $_GET['page'] === 'dfn-volunteer-logistics' && 
        $_GET['action'] === 'print') {
        
        if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
            wp_die(__('Permessi insufficienti.', 'dfn-theme'));
        }

        $event_id = (int) $_GET['event_id'];
        dfn_render_volunteer_event_print_view($event_id);
        exit;
    }
}

/**
 * Renderizza la pagina amministrativa Turni & Logistica Eventi.
 */
function dfn_render_volunteer_logistics_page(): void
{
    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_die(__('Permessi insufficienti per accedere a questa sezione.', 'dfn-theme'));
    }

    $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
    $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

    switch ($action) {
        case 'new':
        case 'edit':
            dfn_render_volunteer_event_form($event_id);
            break;
        case 'matrix':
            dfn_render_volunteer_event_matrix($event_id);
            break;
        case 'survey':
            dfn_render_volunteer_event_survey_admin($event_id);
            break;
        default:
            dfn_render_volunteer_events_list();
            break;
    }
}

/**
 * ------------------------------------------------------------------------
 * 1. LISTA DEGLI EVENTI LOGISTICA
 * ------------------------------------------------------------------------
 */
function dfn_render_volunteer_events_list(): void
{
    global $wpdb;
    $events = dfn_get_volunteer_events();

    // Gestione cancellazione
    if (isset($_GET['delete_event'], $_GET['_wpnonce'])) {
        $del_id = (int) $_GET['delete_event'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_vol_event_' . $del_id)) {
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_events', ['id' => $del_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_days', ['event_id' => $del_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_places', ['event_id' => $del_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_shifts', ['event_id' => $del_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_surveys', ['event_id' => $del_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Evento logistica e turni rimossi con successo.</p></div>';
            $events = dfn_get_volunteer_events();
        }
    }

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
            <div>
                <span class="dashicons dashicons-calendar-alt" style="font-size:32px; width:32px; height:32px; color:#004b23; vertical-align:middle;"></span>
                <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:0 0 0 8px; display:inline-block; vertical-align:middle;">
                    Turni &amp; Logistica Eventi FAI
                </h1>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=new')); ?>" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:6px 16px;">
                ➕ Nuovo Evento / Giornata FAI
            </a>
        </header>

        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <table class="wp-list-table widefat fixed striped table-view-list" style="border:none;">
                <thead>
                    <tr>
                        <th style="width:280px; font-weight:700;">Nome Evento</th>
                        <th style="width:140px; font-weight:700;">Tipologia</th>
                        <th style="width:180px; font-weight:700;">Date Evento</th>
                        <th style="width:120px; font-weight:700; text-align:center;">Stato</th>
                        <th style="font-weight:700;">Dettagli Logistica</th>
                        <th style="width:240px; font-weight:700; text-align:right;">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (! empty($events)) : ?>
                        <?php foreach ($events as $ev) : 
                            $days = dfn_get_volunteer_event_days((int) $ev->id);
                            $places = dfn_get_volunteer_event_all_places((int) $ev->id);
                            $survey = dfn_get_volunteer_survey_by_event((int) $ev->id);
                        ?>
                            <tr>
                                <td>
                                    <strong style="color:#0f172a; font-size:14px; display:block;">
                                        <?php echo esc_html($ev->title); ?>
                                    </strong>
                                    <?php if ($ev->linked_event_id) : ?>
                                        <span style="font-size:11.5px; color:#64748b;">🔗 Associato a FAI Prenotazioni #<?php echo intval($ev->linked_event_id); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($ev->event_type === 'giornata_fai') : ?>
                                        <span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; font-size:11px; font-weight:800; padding:2px 8px; border-radius:12px;">
                                            🏛️ Giornata FAI
                                        </span>
                                    <?php else : ?>
                                        <span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; font-size:11px; font-weight:800; padding:2px 8px; border-radius:12px;">
                                            📍 Evento Locale
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:12.5px; font-weight:600; color:#334155;">
                                        🗓️ <?php echo esc_html(date_i18n('d/m/Y', strtotime($ev->date_start))); ?>
                                        <?php if ($ev->date_start !== $ev->date_end) : ?>
                                            - <?php echo esc_html(date_i18n('d/m/Y', strtotime($ev->date_end))); ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td style="text-align:center;">
                                    <?php 
                                    $status_labels = [
                                        'draft'         => ['Bozza', '#f1f5f9', '#475569', '#cbd5e1'],
                                        'survey_open'   => ['Sondaggio Aperto', '#dbeafe', '#1e40af', '#93c5fd'],
                                        'survey_closed' => ['Sondaggio Chiuso', '#fef3c7', '#92400e', '#fde68a'],
                                        'published'     => ['Turni Pubblicati', '#dcfce7', '#15803d', '#86efac'],
                                        'completed'     => ['Concluso', '#f1f5f9', '#64748b', '#cbd5e1'],
                                    ];
                                    $st = $status_labels[$ev->status] ?? [$ev->status, '#f1f5f9', '#475569', '#cbd5e1'];
                                    ?>
                                    <span style="display:inline-block; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700; background:<?php echo $st[1]; ?>; color:<?php echo $st[2]; ?>; border:1px solid <?php echo $st[3]; ?>;">
                                        <?php echo esc_html($st[0]); ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size:12px; color:#475569;">
                                        <?php 
                                        $num_days = count($days);
                                        $num_places = count($places);
                                        $place_suffix = '';
                                        if ($num_places === 1 && ! empty($places[0]->place_name)) {
                                            $place_suffix = ' (' . esc_html($places[0]->place_name) . ')';
                                        }
                                        ?>
                                        📅 <strong><?php echo $num_days; ?></strong> <?php echo $num_days === 1 ? 'giorno' : 'giorni'; ?> • 🏛️ <strong><?php echo $num_places; ?></strong> <?php echo $num_places === 1 ? 'luogo aperto' : 'luoghi aperti'; ?><?php echo $place_suffix; ?>
                                    </div>
                                    <?php if ($survey) : ?>
                                        <div style="font-size:11px; color:#0369a1; margin-top:2px;">
                                            📋 Sondaggio attivo fino al <?php echo esc_html(date_i18n('d/m H:i', strtotime($survey->deadline_at))); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $ev->id)); ?>" class="button button-small button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700;" title="Gestione Matrice Turni">
                                        📋 Turni
                                    </a>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=survey&event_id=' . $ev->id)); ?>" class="button button-small" title="Gestione Sondaggio">
                                        📊 Sondaggio
                                    </a>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=edit&event_id=' . $ev->id)); ?>" class="button button-small" title="Modifica Configurazione">
                                        ✏️
                                    </a>
                                    <?php 
                                    $del_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&delete_event=' . $ev->id), 'dfn_del_vol_event_' . $ev->id);
                                    ?>
                                    <a href="<?php echo esc_url($del_url); ?>" class="button button-small" style="color:#b91c1c;" onclick="return confirm('Confermi la cancellazione dell\'evento e di tutti i turni associati?');">
                                        🗑️
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" style="padding:30px; text-align:center; color:#64748b;">
                                Nessun evento logistica creato. <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=new')); ?>">Crea il primo evento</a>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

/**
 * ------------------------------------------------------------------------
 * 2. CREAZIONE E CONFIGURAZIONE EVENTO / GIORNATA FAI
 * ------------------------------------------------------------------------
 */
function dfn_render_volunteer_event_form(int $event_id): void
{
    global $wpdb;
    $event = $event_id > 0 ? dfn_get_volunteer_event($event_id) : null;
    $table_events = $wpdb->prefix . 'dfn_volunteer_events';
    $table_days   = $wpdb->prefix . 'dfn_volunteer_event_days';
    $table_places = $wpdb->prefix . 'dfn_volunteer_event_places';
    $table_shifts = $wpdb->prefix . 'dfn_volunteer_event_shifts';

    // Gestione salvataggio
    if (isset($_POST['dfn_save_volunteer_event']) && wp_verify_nonce($_POST['dfn_vol_event_nonce'] ?? '', 'dfn_save_vol_event_action')) {
        $title          = sanitize_text_field($_POST['title'] ?? '');
        $event_type     = sanitize_text_field($_POST['event_type'] ?? 'local');
        $date_start     = sanitize_text_field($_POST['date_start'] ?? '');
        $date_end       = sanitize_text_field($_POST['date_end'] ?? $date_start);
        $linked_event_id= ! empty($_POST['linked_event_id']) ? (int) $_POST['linked_event_id'] : null;
        $description    = sanitize_textarea_field($_POST['description'] ?? '');
        $status         = sanitize_text_field($_POST['status'] ?? 'draft');
        $selected_roles = isset($_POST['role_ids']) && is_array($_POST['role_ids']) ? array_map('intval', $_POST['role_ids']) : [];

        if (! empty($title) && ! empty($date_start)) {
            if ($event) {
                $wpdb->update(
                    $table_events,
                    [
                        'title'          => $title,
                        'event_type'     => $event_type,
                        'date_start'     => $date_start,
                        'date_end'       => $date_end,
                        'linked_event_id'=> $linked_event_id,
                        'description'    => $description,
                        'status'         => $status,
                    ],
                    [ 'id' => $event->id ],
                    [ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ],
                    [ '%d' ]
                );
                $saved_id = (int) $event->id;
            } else {
                $wpdb->insert(
                    $table_events,
                    [
                        'title'          => $title,
                        'event_type'     => $event_type,
                        'date_start'     => $date_start,
                        'date_end'       => $date_end,
                        'linked_event_id'=> $linked_event_id,
                        'description'    => $description,
                        'status'         => $status,
                        'created_by'     => get_current_user_id(),
                        'created_at'     => current_time('mysql'),
                    ],
                    [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' ]
                );
                $saved_id = (int) $wpdb->insert_id;
            }

            // Sincronizzazione dinamica dei giorni per l'evento (sia creazione che modifica date)
            $existing_days = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_days} WHERE event_id = %d", $saved_id));
            $days_by_date = [];
            foreach ($existing_days as $ed) {
                $days_by_date[$ed->event_date] = $ed;
            }

            $cur = strtotime($date_start);
            $end = strtotime($date_end);
            $order = 1;
            $active_dates = [];

            while ($cur <= $end) {
                $d_str = gmdate('Y-m-d', $cur);
                $d_lbl = date_i18n('l d/m/Y', $cur);
                $active_dates[] = $d_str;

                if (! isset($days_by_date[$d_str])) {
                    // Inserimento nuovo giorno aggiunto
                    $wpdb->insert(
                        $table_days,
                        [ 'event_id' => $saved_id, 'event_date' => $d_str, 'day_label' => $d_lbl, 'order_num' => $order ],
                        [ '%d', '%s', '%s', '%d' ]
                    );
                    $new_day_id = $wpdb->insert_id;

                    // Se evento locale, crea il luogo per il nuovo giorno
                    if ($event_type === 'local') {
                        $place_name = 'Sede Evento';
                        if ($linked_event_id > 0) {
                            $fe = function_exists('dfn_db_get_event') ? dfn_db_get_event($linked_event_id) : null;
                            if ($fe && ! empty($fe->location)) {
                                $place_name = $fe->location;
                            }
                        }
                        $wpdb->insert(
                            $table_places,
                            [ 'event_id' => $saved_id, 'day_id' => $new_day_id, 'place_name' => $place_name, 'order_num' => 1 ],
                            [ '%d', '%d', '%s', '%d' ]
                        );
                    }
                } else {
                    // Aggiorna ordinamento ed etichetta del giorno esistente
                    $wpdb->update(
                        $table_days,
                        [ 'day_label' => $d_lbl, 'order_num' => $order ],
                        [ 'id' => $days_by_date[$d_str]->id ],
                        [ '%s', '%d' ],
                        [ '%d' ]
                    );
                }

                $cur = strtotime('+1 day', $cur);
                $order++;
            }

            // Rimuove eventuali giorni rimossi dall'intervallo date SOLO se non contengono turni
            foreach ($existing_days as $ed) {
                if (! in_array($ed->event_date, $active_dates, true)) {
                    $has_shifts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_shifts} WHERE day_id = %d", $ed->id));
                    if ($has_shifts === 0) {
                        $wpdb->delete($table_places, ['day_id' => $ed->id], ['%d']);
                        $wpdb->delete($table_days, ['id' => $ed->id], ['%d']);
                    }
                }
            }

            // Salvataggio associazione mansioni
            if (function_exists('dfn_set_volunteer_event_roles')) {
                dfn_set_volunteer_event_roles((int) $saved_id, $selected_roles);
            }

            echo '<div class="notice notice-success is-dismissible"><p>✅ Evento logistica salvato con successo!</p></div>';
            echo '<script>window.location.href="' . esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $saved_id)) . '";</script>';
            return;
        }
    }

    // Caricamento eventi FAI futuri
    $fai_events = $wpdb->get_results(
        "SELECT e.*, p.post_title 
         FROM {$wpdb->prefix}dfn_events e
         LEFT JOIN {$wpdb->posts} p ON e.product_id = p.ID
         WHERE (e.event_date_end >= CURDATE() OR (e.event_date_end IS NULL AND e.event_date_start >= CURDATE()))
           AND e.status != 'archived'
         ORDER BY e.event_date_start ASC"
    );

    // Recupera mansioni per il form
    $all_available_roles = function_exists('dfn_get_all_volunteer_roles') ? dfn_get_all_volunteer_roles() : [];
    $assigned_role_ids = [];
    if ($event) {
        $ev_roles = function_exists('dfn_get_volunteer_event_roles') ? dfn_get_volunteer_event_roles((int) $event->id) : [];
        $assigned_role_ids = array_map(function($r) { return (int) $r->id; }, $ev_roles);
    } else {
        foreach ($all_available_roles as $ar) {
            if (! empty($ar->is_default)) {
                $assigned_role_ids[] = (int) $ar->id;
            }
        }
    }

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom:24px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics')); ?>" style="text-decoration:none; color:#004b23; font-weight:700;">← Torna alla lista</a>
            <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:8px 0 0 0;">
                <?php echo $event ? 'Modifica Evento Logistica' : 'Nuovo Evento Logistica / Giornata FAI'; ?>
            </h1>
        </header>

        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:24px 28px; max-width:800px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
            <form method="post" action="">
                <?php wp_nonce_field('dfn_save_vol_event_action', 'dfn_vol_event_nonce'); ?>

                <div style="margin-bottom:18px;">
                    <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Nome Evento <span style="color:#ef4444;">*</span></label>
                    <input type="text" name="title" required value="<?php echo esc_attr($event ? $event->title : ''); ?>" placeholder="Es. Giornata FAI di Primavera 2026 oppure Visita Guidata Castello" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px; font-size:14px;">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px;">
                    <div>
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Tipologia Evento <span style="color:#ef4444;">*</span></label>
                        <select name="event_type" id="dfn_event_type_select" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px;" onchange="toggleLinkedEventField()">
                            <option value="giornata_fai" <?php selected($event ? $event->event_type : 'giornata_fai', 'giornata_fai'); ?>>🏛️ Giornata FAI (Multi-luogo e Sondaggio)</option>
                            <option value="local" <?php selected($event ? $event->event_type : '', 'local'); ?>>📍 Evento Locale (Visita / Evento Singolo)</option>
                        </select>
                    </div>

                    <div id="linked_event_wrapper" style="display: <?php echo ($event && $event->event_type === 'local') ? 'block' : 'none'; ?>;">
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Associa ad Evento FAI Prenotazioni (Solo Futuri)</label>
                        <select name="linked_event_id" id="dfn_linked_event_select" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px;" onchange="onLinkedEventChange(this)">
                            <option value="" data-start="" data-end="">-- Seleziona un evento futuro --</option>
                            <?php foreach ($fai_events as $fe) : 
                                $ev_name = ! empty($fe->post_title) ? $fe->post_title : ($fe->title ?: 'Evento #' . $fe->id);
                                $date_label = date_i18n('d/m/Y', strtotime($fe->event_date_start));
                                $fe_end = ! empty($fe->event_date_end) ? $fe->event_date_end : $fe->event_date_start;
                                if (! empty($fe->event_date_end) && $fe->event_date_end !== $fe->event_date_start) {
                                    $date_label .= ' - ' . date_i18n('d/m/Y', strtotime($fe->event_date_end));
                                }
                            ?>
                                <option value="<?php echo esc_attr($fe->id); ?>" 
                                        data-start="<?php echo esc_attr($fe->event_date_start); ?>" 
                                        data-end="<?php echo esc_attr($fe_end); ?>" 
                                        data-title="<?php echo esc_attr($ev_name); ?>"
                                        <?php selected($event ? (int) $event->linked_event_id : 0, (int) $fe->id); ?>>
                                    <?php echo esc_html($ev_name . ' (' . $date_label . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:18px;">
                    <div>
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Data Inizio <span style="color:#ef4444;">*</span></label>
                        <input type="date" name="date_start" id="dfn_date_start" required value="<?php echo esc_attr($event ? $event->date_start : date('Y-m-d')); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Data Fine</label>
                        <input type="date" name="date_end" id="dfn_date_end" value="<?php echo esc_attr($event ? $event->date_end : date('Y-m-d', strtotime('+1 day'))); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px;">
                    </div>
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Stato Evento</label>
                    <select name="status" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px;">
                        <option value="draft" <?php selected($event ? $event->status : 'draft', 'draft'); ?>>Bozza</option>
                        <option value="survey_open" <?php selected($event ? $event->status : '', 'survey_open'); ?>>Sondaggio Aperto ai Volontari</option>
                        <option value="survey_closed" <?php selected($event ? $event->status : '', 'survey_closed'); ?>>Sondaggio Chiuso (Assegnazione Turni)</option>
                        <option value="published" <?php selected($event ? $event->status : '', 'published'); ?>>Turni Pubblicati (Visibili in Area Personale)</option>
                        <option value="completed" <?php selected($event ? $event->status : '', 'completed'); ?>>Evento Concluso</option>
                    </select>
                </div>

                <!-- SEZIONE: SELEZIONE MANSIONI -->
                <div style="margin-bottom:20px; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <label style="font-size:13px; font-weight:800; color:#004b23; text-transform:uppercase;">🏷️ Mansioni Volontari per questo Evento</label>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-roles')); ?>" target="_blank" style="font-size:12px; color:#2563eb; text-decoration:none; font-weight:700;">➕ Gestisci o Aggiungi Nuove Mansioni ↗</a>
                    </div>
                    <?php if (! empty($all_available_roles)) : ?>
                        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:10px;">
                            <?php foreach ($all_available_roles as $r_item) : 
                                $is_checked = in_array((int) $r_item->id, $assigned_role_ids, true);
                            ?>
                                <label style="display:flex; align-items:center; gap:8px; background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:8px 12px; cursor:pointer; font-size:12.5px;">
                                    <input type="checkbox" name="role_ids[]" value="<?php echo esc_attr($r_item->id); ?>" <?php checked($is_checked); ?>>
                                    <div style="flex:1;">
                                        <div style="font-weight:700; color:#1e293b;"><?php echo esc_html($r_item->role_name); ?></div>
                                        <span style="display:inline-block; font-size:10px; font-weight:800; background:<?php echo esc_attr($r_item->badge_bg); ?>; color:<?php echo esc_attr($r_item->badge_color); ?>; padding:1px 6px; border-radius:10px; margin-top:2px;">
                                            <?php echo esc_html($r_item->badge_code ?: $r_item->role_name); ?>
                                        </span>
                                        <?php if (! empty($r_item->requires_safety_course)) : ?>
                                            <span style="font-size:10px; color:#92400e; font-weight:700; margin-left:4px;">[🦺 Sicurezza]</span>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">Note e Istruzioni per i Volontari</label>
                    <textarea name="description" rows="3" placeholder="Informazioni generali sul punto di ritrovo, abbigliamento, contatti capogruppo..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px;"><?php echo esc_textarea($event ? $event->description : ''); ?></textarea>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f0f0f1; padding-top:16px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics')); ?>" class="button">Annulla</a>
                    <button type="submit" name="dfn_save_volunteer_event" class="button button-primary" style="background:#004b23; border-color:#003b1c; padding:4px 20px; font-weight:700;">
                        💾 Salva e Configura Turni
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function toggleLinkedEventField() {
        var type = document.getElementById('dfn_event_type_select').value;
        var wrap = document.getElementById('linked_event_wrapper');
        wrap.style.display = (type === 'local') ? 'block' : 'none';
    }

    function onLinkedEventChange(selectElem) {
        var selectedOption = selectElem.options[selectElem.selectedIndex];
        var startDate = selectedOption.getAttribute('data-start');
        var endDate = selectedOption.getAttribute('data-end');

        if (startDate) {
            document.getElementById('dfn_date_start').value = startDate;
        }
        if (endDate) {
            document.getElementById('dfn_date_end').value = endDate;
        }
    }
    </script>
    <?php
}

/**
 * ------------------------------------------------------------------------
 * 3. MATRICE DEI TURNI (GRIGLIA INTERATTIVA LUOGHI / SLOT)
 * ------------------------------------------------------------------------
 */
function dfn_render_volunteer_event_matrix(int $event_id): void
{
    global $wpdb;
    $event = dfn_get_volunteer_event($event_id);
    if (! $event) {
        wp_die(__('Evento non trovato.', 'dfn-theme'));
    }

    $survey = dfn_get_volunteer_survey_by_event($event_id);
    $days = dfn_get_volunteer_event_days($event_id);
    $selected_day_id = isset($_GET['day_id']) ? (int) $_GET['day_id'] : (! empty($days) ? (int) $days[0]->id : 0);

    // Gestione aggiunta luogo
    if (isset($_POST['dfn_add_place']) && (wp_verify_nonce($_POST['dfn_place_nonce'] ?? '', 'dfn_place_action') || wp_verify_nonce($_POST['dfn_place_nonce'] ?? '', 'dfn_add_place_action'))) {
        $place_name = sanitize_text_field($_POST['place_name'] ?? '');
        $target_day_id = ! empty($_POST['day_id']) ? (int) $_POST['day_id'] : $selected_day_id;

        if (! empty($place_name) && $target_day_id > 0) {
            $table_places = $wpdb->prefix . 'dfn_volunteer_event_places';
            $table_shifts = $wpdb->prefix . 'dfn_volunteer_event_shifts';

            $wpdb->insert(
                $table_places,
                [ 'event_id' => $event_id, 'day_id' => $target_day_id, 'place_name' => $place_name, 'order_num' => 10 ],
                [ '%d', '%d', '%s', '%d' ]
            );
            $place_id = $wpdb->insert_id;

            // Crea automaticamente i 2 turni standard per il luogo: Mattina e Pomeriggio
            $wpdb->insert($table_shifts, [ 'event_id' => $event_id, 'day_id' => $target_day_id, 'place_id' => $place_id, 'shift_label' => 'Mattina', 'time_start' => '09:00:00', 'time_end' => '12:30:00', 'order_num' => 1 ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]);
            $wpdb->insert($table_shifts, [ 'event_id' => $event_id, 'day_id' => $target_day_id, 'place_id' => $place_id, 'shift_label' => 'Pomeriggio', 'time_start' => '14:00:00', 'time_end' => '18:00:00', 'order_num' => 2 ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]);

            echo '<div class="notice notice-success is-dismissible"><p>✅ Luogo "' . esc_html($place_name) . '" e relativi turni aggiunti con successo!</p></div>';
        }
    }

    // Gestione eliminazione luogo
    if (isset($_GET['delete_place'], $_GET['_wpnonce'])) {
        $del_p_id = (int) $_GET['delete_place'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_place_' . $del_p_id)) {
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_places', ['id' => $del_p_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_shifts', ['place_id' => $del_p_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Luogo rimosso.</p></div>';
        }
    }

    // Gestione Assegnazione Volontario Manuale
    if (isset($_POST['dfn_assign_volunteer']) && wp_verify_nonce($_POST['dfn_assign_nonce'] ?? '', 'dfn_assign_action')) {
        $shift_id = (int) $_POST['shift_id'];
        $vol_id   = ! empty($_POST['volunteer_id']) ? (int) $_POST['volunteer_id'] : null;
        $vol_manual = sanitize_text_field($_POST['volunteer_manual'] ?? '');
        $role_ass = sanitize_text_field($_POST['role_assigned'] ?? 'banchetto');

        if ($shift_id > 0 && ($vol_id || ! empty($vol_manual))) {
            $wpdb->insert(
                $wpdb->prefix . 'dfn_volunteer_shift_assignments',
                [
                    'shift_id'              => $shift_id,
                    'volunteer_id'          => $vol_id,
                    'volunteer_name_manual' => $vol_manual,
                    'role_assigned'         => $role_ass,
                    'created_at'            => current_time('mysql'),
                ],
                [ '%d', '%d', '%s', '%s', '%s' ]
            );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Volontario assegnato al turno!</p></div>';
        }
    }

    // Gestione Modifica Rapida Mansione Volontario Assegnato
    if (isset($_POST['dfn_update_role']) && wp_verify_nonce($_POST['dfn_update_role_nonce'] ?? '', 'dfn_update_role_action')) {
        $ass_id   = (int) $_POST['assignment_id'];
        $new_role = sanitize_text_field($_POST['new_role'] ?? '');

        if ($ass_id > 0 && ! empty($new_role)) {
            $wpdb->update(
                $wpdb->prefix . 'dfn_volunteer_shift_assignments',
                [ 'role_assigned' => $new_role ],
                [ 'id' => $ass_id ],
                [ '%s' ],
                [ '%d' ]
            );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Mansione del volontario aggiornata con successo!</p></div>';
        }
    }

    // Gestione Rimozione Assegnazione Volontario
    if (isset($_GET['remove_assignment'], $_GET['_wpnonce'])) {
        $ass_id = (int) $_GET['remove_assignment'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_ass_' . $ass_id)) {
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_shift_assignments', ['id' => $ass_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Volontario rimosso dal turno.</p></div>';
        }
    }

    // Gestione Algoritmo Assegnazione Automatica
    if (isset($_POST['dfn_auto_assign']) && wp_verify_nonce($_POST['dfn_auto_nonce'] ?? '', 'dfn_auto_assign_action')) {
        $now = current_time('mysql');
        $is_survey_closed = ($survey && ($survey->status === 'closed' || (! empty($survey->deadline_at) && $survey->deadline_at < $now)));

        if (! $survey) {
            echo '<div class="notice notice-error is-dismissible"><p>⚠️ <strong>Nessun sondaggio trovato</strong> per questo evento. Crea prima un sondaggio per raccogliere le disponibilità.</p></div>';
        } elseif (! $is_survey_closed) {
            echo '<div class="notice notice-warning is-dismissible"><p>⚠️ <strong>Sondaggio ancora aperto:</strong> l\'assegnazione automatica può essere eseguita solo dopo la chiusura o la scadenza del sondaggio, per evitare assegnazioni parziali prima che tutti i volontari abbiano risposto.</p></div>';
        } else {
            // Rimuovi eventuali assegnazioni precedenti per tutti gli shift dell'evento prima di riassegnare
            $all_event_shift_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE event_id = %d", $event_id));
            if (! empty($all_event_shift_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($all_event_shift_ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id IN ($in_placeholders)", ...$all_event_shift_ids));
            }

            $assigned_count = dfn_run_volunteer_auto_assignment($event_id, 0);
            echo '<div class="notice notice-success is-dismissible"><p>🤖 <strong>Assegnazione automatica completata!</strong> Assegnati ' . intval($assigned_count) . ' volontari ai turni su tutti i giorni dell\'evento nel rispetto esclusivo delle sole mansioni abilitate.</p></div>';
        }
    }

    // Gestione Aggiunta Nuovo Slot Orario (per qualsiasi Luogo)
    if (isset($_POST['dfn_add_shift']) && wp_verify_nonce($_POST['dfn_shift_nonce'] ?? '', 'dfn_add_shift_action')) {
        $shift_label = sanitize_text_field($_POST['shift_label'] ?? 'Turno');
        $time_start  = sanitize_text_field($_POST['time_start'] ?? '');
        $time_end    = sanitize_text_field($_POST['time_end'] ?? '');
        $target_place_id = (int) ($_POST['place_id'] ?? 0);
        $target_day_id   = ! empty($_POST['day_id']) ? (int) $_POST['day_id'] : $selected_day_id;

        if (! empty($time_start) && ! empty($time_end) && $target_place_id > 0 && $target_day_id > 0) {
            $wpdb->insert(
                $wpdb->prefix . 'dfn_volunteer_event_shifts',
                [
                    'event_id'    => $event_id,
                    'day_id'      => $target_day_id,
                    'place_id'    => $target_place_id,
                    'shift_label' => $shift_label,
                    'time_start'  => $time_start . (strlen($time_start) === 5 ? ':00' : ''),
                    'time_end'    => $time_end . (strlen($time_end) === 5 ? ':00' : ''),
                    'order_num'   => 10,
                ],
                [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
            );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Nuovo slot orario aggiunto con successo al luogo!</p></div>';
        }
    }

    // Gestione Eliminazione Slot Orario
    if (isset($_GET['delete_shift'], $_GET['_wpnonce'])) {
        $del_sh_id = (int) $_GET['delete_shift'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_shift_' . $del_sh_id)) {
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_shifts', ['id' => $del_sh_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_shift_assignments', ['shift_id' => $del_sh_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Slot orario rimosso.</p></div>';
        }
    }

    // Gestione Pubblicazione Turni dell'Evento
    if (isset($_POST['dfn_publish_shifts']) && wp_verify_nonce($_POST['dfn_publish_nonce'] ?? '', 'dfn_publish_shifts_action')) {
        $wpdb->update(
            $wpdb->prefix . 'dfn_volunteer_events',
            [ 'status' => 'published' ],
            [ 'id' => $event_id ],
            [ '%s' ],
            [ '%d' ]
        );
        $event = dfn_get_volunteer_event($event_id);
        echo '<div class="notice notice-success is-dismissible"><p>🎉 <strong>Turni Pubblicati con successo!</strong> I volontari possono ora consultare i propri turni e la matrice generale nella loro area personale.</p></div>';
    }

    // Gestione Sospensione / Ritiro Pubblicazione Turni
    if (isset($_POST['dfn_unpublish_shifts']) && wp_verify_nonce($_POST['dfn_publish_nonce'] ?? '', 'dfn_publish_shifts_action')) {
        $wpdb->update(
            $wpdb->prefix . 'dfn_volunteer_events',
            [ 'status' => 'survey_closed' ],
            [ 'id' => $event_id ],
            [ '%s' ],
            [ '%d' ]
        );
        $event = dfn_get_volunteer_event($event_id);
        echo '<div class="notice notice-info is-dismissible"><p>ℹ️ <strong>Pubblicazione turni sospesa:</strong> l\'evento è tornato in stato di assegnazione turni (non visibile in area personale).</p></div>';
    }

    // Gestione Modifica Orari Slot Orario
    if (isset($_POST['dfn_edit_shift']) && wp_verify_nonce($_POST['dfn_edit_shift_nonce'] ?? '', 'dfn_edit_shift_action')) {
        $edit_sh_id   = (int) $_POST['shift_id'];
        $edit_label   = sanitize_text_field($_POST['shift_label'] ?? '');
        $edit_start   = sanitize_text_field($_POST['time_start'] ?? '');
        $edit_end     = sanitize_text_field($_POST['time_end'] ?? '');

        if ($edit_sh_id > 0 && ! empty($edit_start) && ! empty($edit_end)) {
            $wpdb->update(
                $wpdb->prefix . 'dfn_volunteer_event_shifts',
                [
                    'shift_label' => $edit_label,
                    'time_start'  => $edit_start . (strlen($edit_start) === 5 ? ':00' : ''),
                    'time_end'    => $edit_end . (strlen($edit_end) === 5 ? ':00' : ''),
                ],
                [ 'id' => $edit_sh_id ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Orari slot aggiornati con successo!</p></div>';
        }
    }

    $places = $selected_day_id > 0 ? dfn_get_volunteer_event_places($selected_day_id) : [];
    $all_volunteers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dfn_fai_members WHERE is_volunteer = 1 AND volunteer_status = 'active' ORDER BY first_name ASC, last_name ASC");
    $survey = dfn_get_volunteer_survey_by_event($event_id);

    // Recupera mansioni configurate per questo evento (o fallback su tutte)
    $event_roles = function_exists('dfn_get_volunteer_event_roles') ? dfn_get_volunteer_event_roles($event_id) : [];
    if (empty($event_roles)) {
        $event_roles = function_exists('dfn_get_all_volunteer_roles') ? dfn_get_all_volunteer_roles() : [];
    }
    // Precarica le risposte del sondaggio per popolare le select in modo intelligente
    $survey_avail_by_slot = [];
    if ($survey) {
        $raw_resps = $wpdb->get_results($wpdb->prepare(
            "SELECT day_id, time_slot_key, volunteer_id FROM {$wpdb->prefix}dfn_volunteer_survey_responses WHERE survey_id = %d AND is_available = 1",
            $survey->id
        ));
        foreach ($raw_resps as $rr) {
            $clean_k = preg_replace('/[^a-z0-9]/', '', strtolower($rr->time_slot_key));
            $survey_avail_by_slot[$rr->day_id][$clean_k][] = (int) $rr->volunteer_id;
            $survey_avail_by_slot[$rr->day_id][$rr->time_slot_key][] = (int) $rr->volunteer_id;
        }
    }
    $roles_by_key = [];
    foreach ($event_roles as $er) {
        $roles_by_key[$er->role_key] = $er;
    }

    // Se l'evento è locale e non ha ancora un luogo creato, lo creiamo in automatico
    if ($event->event_type === 'local' && empty($places) && $selected_day_id > 0) {
        $place_name = 'Sede Evento';
        $slot_start = '15:00:00';
        $slot_end   = '18:00:00';

        if ($event->linked_event_id > 0) {
            $fe = function_exists('dfn_db_get_event') ? dfn_db_get_event((int) $event->linked_event_id) : null;
            if ($fe) {
                if (! empty($fe->location)) $place_name = $fe->location;
                if (! empty($fe->event_time_start)) $slot_start = $fe->event_time_start;
                if (! empty($fe->event_time_end)) $slot_end = $fe->event_time_end;
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'dfn_volunteer_event_places',
            [ 'event_id' => $event_id, 'day_id' => $selected_day_id, 'place_name' => $place_name, 'order_num' => 1 ],
            [ '%d', '%d', '%s', '%d' ]
        );
        $places = dfn_get_volunteer_event_places($selected_day_id);
    }

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
            <div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics')); ?>" style="text-decoration:none; color:#004b23; font-weight:700;">← Torna all'elenco eventi</a>
                <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:6px 0 0 0;">
                    📋 Matrice Turni: <?php echo esc_html($event->title); ?>
                </h1>
            </div>
            <?php 
                $now = current_time('mysql');
                $is_survey_closed = ($survey && ($survey->status === 'closed' || (! empty($survey->deadline_at) && $survey->deadline_at < $now)));
                $is_survey_published = ($survey && $survey->status !== 'draft');

                // Conta turni ed assegnazioni totali dell'evento
                $event_shift_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE event_id = %d", $event_id));
                $total_event_assignments = 0;
                if (! empty($event_shift_ids)) {
                    $in_ev_shifts = implode(',', array_map('intval', $event_shift_ids));
                    $total_event_assignments = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id IN ($in_ev_shifts)");
                }
            ?>
            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                
                <!-- 1. Gestione Pubblicazione Turni (Mostrato solo se ci sono assegnazioni generate) -->
                <?php if ($event->status === 'published') : ?>
                    <span style="background:#dcfce7; color:#15803d; border:1px solid #86efac; font-size:12.5px; font-weight:800; padding:5px 12px; border-radius:6px; display:inline-flex; align-items:center; gap:5px;">
                        ✅ Turni Pubblicati
                    </span>
                    <form method="post" action="" onsubmit="return confirm('Vuoi sospendere la visibilità dei turni in area personale?');" style="margin:0;">
                        <?php wp_nonce_field('dfn_publish_shifts_action', 'dfn_publish_nonce'); ?>
                        <button type="submit" name="dfn_unpublish_shifts" class="button" style="color:#b91c1c; font-weight:700;">
                            ⏸️ Sospendi Pubblicazione
                        </button>
                    </form>
                <?php elseif ($total_event_assignments > 0) : ?>
                    <form method="post" action="" onsubmit="return confirm('Confermi la pubblicazione dei turni? I volontari potranno visualizzare i turni a loro assegnati nella propria area personale.');" style="margin:0;">
                        <?php wp_nonce_field('dfn_publish_shifts_action', 'dfn_publish_nonce'); ?>
                        <button type="submit" name="dfn_publish_shifts" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:4px 16px; box-shadow:0 2px 4px rgba(0,75,35,0.2);">
                            📢 Pubblica Turni
                        </button>
                    </form>
                <?php endif; ?>

                <!-- 2. Assegnazione Automatica (Disponibile solo dopo chiusura/scadenza sondaggio) -->
                <?php if ($survey && $is_survey_closed) : ?>
                    <form method="post" action="" onsubmit="return confirm('L\'assegnazione automatica distribuirà i volontari disponibili in base al sondaggio e alle sole mansioni abilitate per l\'evento. Continuare?');" style="margin:0;">
                        <?php wp_nonce_field('dfn_auto_assign_action', 'dfn_auto_nonce'); ?>
                        <button type="submit" name="dfn_auto_assign" class="button button-primary" style="background:#2563eb; border-color:#1d4ed8; font-weight:700; padding:4px 16px;">
                            🤖 Assegna Automaticamente i Turni
                        </button>
                    </form>
                <?php endif; ?>

                <!-- 3. Gestione / Risposte Sondaggio (Condizionale all'esistenza del sondaggio) -->
                <?php if ($survey) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=survey&event_id=' . $event_id)); ?>" class="button" style="font-weight:700;">
                        📊 Risposte Sondaggio
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=survey&event_id=' . $event_id)); ?>" class="button button-secondary" style="font-weight:700; background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe;">
                        📋 Crea Sondaggio
                    </a>
                <?php endif; ?>

                <!-- 4. Stampa / Esporta PDF (Disponibile solo se ci sono turni o assegnazioni) -->
                <?php if (! empty($event_shift_ids)) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=print&event_id=' . $event_id)); ?>" target="_blank" class="button" style="font-weight:700;">
                        🖨️ Stampa / Esporta PDF
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- ACCORDION VERTICALE GIORNI EVENTO -->
        <style>
            .dfn-day-accordion {
                border: 1px solid #cbd5e1;
                border-radius: 10px;
                margin-bottom: 14px;
                background: #ffffff;
                overflow: hidden;
                box-shadow: 0 2px 6px rgba(0,0,0,0.03);
                transition: all 0.2s ease;
            }
            .dfn-day-accordion[open] {
                border-color: #004b23;
                box-shadow: 0 4px 12px rgba(0,75,35,0.08);
            }
            .dfn-day-accordion summary {
                cursor: pointer;
                user-select: none;
                list-style: none;
                background: #f8fafc;
                padding: 14px 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-weight: 700;
                color: #0f172a;
                border-bottom: 1px solid transparent;
                transition: background 0.2s ease;
            }
            .dfn-day-accordion summary::-webkit-details-marker {
                display: none;
            }
            .dfn-day-accordion[open] summary {
                background: #004b23;
                color: #ffffff;
                border-bottom: 1px solid #003b1c;
            }
            .dfn-day-accordion summary:hover {
                background: #f1f5f9;
            }
            .dfn-day-accordion[open] summary:hover {
                background: #004b23;
            }
            .dfn-acc-arrow {
                transition: transform 0.2s ease;
                font-size: 11px;
                opacity: 0.7;
            }
            .dfn-day-accordion[open] .dfn-acc-arrow {
                transform: rotate(180deg);
                opacity: 1;
            }
            .dfn-badge-counter {
                font-size: 11.5px;
                font-weight: 700;
                padding: 3px 9px;
                border-radius: 12px;
                display: inline-flex;
                align-items: center;
                gap: 4px;
            }
            .dfn-day-accordion:not([open]) .dfn-badge-counter-shifts {
                background: #eff6ff;
                color: #1d4ed8;
                border: 1px solid #bfdbfe;
            }
            .dfn-day-accordion[open] .dfn-badge-counter-shifts {
                background: rgba(255,255,255,0.2);
                color: #ffffff;
                border: 1px solid rgba(255,255,255,0.3);
            }
            .dfn-day-accordion:not([open]) .dfn-badge-counter-vols {
                background: #f0fdf4;
                color: #15803d;
                border: 1px solid #bbf7d0;
            }
            .dfn-day-accordion[open] .dfn-badge-counter-vols {
                background: rgba(255,255,255,0.2);
                color: #ffffff;
                border: 1px solid rgba(255,255,255,0.3);
            }
            .dfn-role-inline-select {
                font-size: 11px !important;
                font-weight: 700 !important;
                border-radius: 6px !important;
                border: 1px solid #cbd5e1 !important;
                padding: 2px 6px !important;
                height: 24px !important;
                cursor: pointer !important;
                outline: none !important;
            }
        </style>

        <div id="dfn-days-accordion-container">
            <?php foreach ($days as $idx => $d) : 
                $d_shifts = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC, id ASC",
                    $d->id
                ));
                $d_shift_ids = ! empty($d_shifts) ? array_map('intval', wp_list_pluck($d_shifts, 'id')) : [];
                $d_vols_count = 0;
                if (! empty($d_shift_ids)) {
                    $in_sh_sql = implode(',', $d_shift_ids);
                    $d_vols_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id IN ($in_sh_sql)");
                }
                
                // Manteniamo aperto il giorno selezionato dall'utente (o il primo per default)
                $is_open = ($d->id == $selected_day_id);
            ?>
                <details class="dfn-day-accordion" <?php echo $is_open ? 'open' : ''; ?> id="day-accordion-<?php echo esc_attr($d->id); ?>">
                    <summary>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span style="font-size:15px;">🗓️</span>
                            <span style="font-size:15px; font-weight:800; letter-spacing:0.2px;">
                                <?php echo esc_html(date_i18n('l d F Y', strtotime($d->event_date))); ?>
                            </span>
                            <span style="font-size:12px; font-weight:normal; opacity:0.8;">
                                (<?php echo esc_html($d->day_label); ?>)
                            </span>
                        </div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span class="dfn-badge-counter dfn-badge-counter-shifts">
                                ⏰ <?php echo count($d_shifts); ?> <?php echo count($d_shifts) === 1 ? 'Turno' : 'Turni'; ?>
                            </span>
                            <span class="dfn-badge-counter dfn-badge-counter-vols">
                                👥 <?php echo $d_vols_count; ?> Assegnati
                            </span>
                            <span class="dfn-acc-arrow">▼</span>
                        </div>
                    </summary>

                    <div style="padding:20px; background:#fafafa; border-top:1px solid #e2e8f0;">
                        <!-- SEZIONE AGGIUNTA LUOGO / TURNO PER QUESTO GIORNO -->
                        <?php if ($event->event_type === 'giornata_fai') : 
                            $d_places = dfn_get_volunteer_event_places((int) $d->id);
                        ?>
                            <!-- Form Aggiunta Nuovo Luogo / Bene per questo Giorno -->
                            <div style="background:#fff; border-radius:8px; border:1px solid #cbd5e1; padding:16px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                                <h3 style="margin:0; font-size:15px; font-weight:800; color:#004b23; display:flex; align-items:center; gap:6px;">
                                    <span>🏛️</span> Luoghi / Beni Aperti per <?php echo esc_html(date_i18n('d/m/Y', strtotime($d->event_date))); ?>
                                </h3>
                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="display:flex; gap:8px; align-items:center;">
                                    <?php wp_nonce_field('dfn_place_action', 'dfn_place_nonce'); ?>
                                    <input type="hidden" name="day_id" value="<?php echo esc_attr($d->id); ?>">
                                    <input type="text" name="place_name" placeholder="Nuovo Luogo / Bene aperto..." required style="width:240px; border-radius:6px; border:1px solid #cbd5e1; height:32px; padding:0 8px; font-size:12.5px;">
                                    <button type="submit" name="dfn_add_place" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; height:32px;">
                                        ➕ Aggiungi Luogo
                                    </button>
                                </form>
                            </div>

                            <!-- RENDERING RAGGRUPPATO PER LUOGO -->
                            <?php if (! empty($d_places)) : ?>
                                <div style="display:flex; flex-direction:column; gap:24px;">
                                    <?php foreach ($d_places as $plc) : 
                                        $plc_shifts = $wpdb->get_results($wpdb->prepare(
                                            "SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE place_id = %d ORDER BY time_start ASC, id ASC",
                                            $plc->id
                                        ));
                                        $del_place_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id . '&delete_place=' . $plc->id), 'dfn_del_place_' . $plc->id);
                                    ?>
                                        <div style="background:#ffffff; border:1.5px solid #cbd5e1; border-radius:10px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.04);">
                                            
                                            <!-- Intestazione Box Luogo + Aggiunta Turno Dedicata -->
                                            <div style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; padding:14px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <span style="font-size:16px;">📍</span>
                                                    <strong style="font-size:15px; color:#0f172a;"><?php echo esc_html($plc->place_name); ?></strong>
                                                    <span style="font-size:11.5px; color:#64748b; font-weight:600; background:#f1f5f9; padding:2px 8px; border-radius:10px; border:1px solid #e2e8f0;">
                                                        <?php echo count($plc_shifts); ?> <?php echo count($plc_shifts) === 1 ? 'Turno' : 'Turni'; ?>
                                                    </span>
                                                </div>

                                                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                    <!-- Form Aggiungi Turno per questo Luogo -->
                                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="display:inline-flex; align-items:center; gap:6px; background:#fff; padding:4px 8px; border-radius:6px; border:1px solid #cbd5e1;">
                                                        <?php wp_nonce_field('dfn_add_shift_action', 'dfn_shift_nonce'); ?>
                                                        <input type="hidden" name="place_id" value="<?php echo esc_attr($plc->id); ?>">
                                                        <input type="hidden" name="day_id" value="<?php echo esc_attr($d->id); ?>">
                                                        <input type="text" name="shift_label" required placeholder="Turno (es. Mattina)" style="width:130px; border-radius:4px; border:1px solid #cbd5e1; height:28px; padding:0 6px; font-size:11.5px;">
                                                        <input type="time" name="time_start" required style="border-radius:4px; border:1px solid #cbd5e1; height:28px; padding:0 4px; font-size:11.5px;">
                                                        <span style="font-size:11px; color:#64748b;">-</span>
                                                        <input type="time" name="time_end" required style="border-radius:4px; border:1px solid #cbd5e1; height:28px; padding:0 4px; font-size:11.5px;">
                                                        <button type="submit" name="dfn_add_shift" class="button button-primary button-small" style="background:#004b23; border-color:#003b1c; font-weight:700; height:28px; line-height:26px;">
                                                            ➕ Aggiungi Turno
                                                        </button>
                                                    </form>

                                                    <a href="<?php echo esc_url($del_place_url); ?>" class="button button-small" style="color:#ef4444; border-color:#fca5a5; height:28px; line-height:26px;" onclick="return confirm('Eliminare questo luogo e tutti i turni orari associati?');" title="Elimina Luogo">
                                                        🗑️ Elimina Luogo
                                                    </a>
                                                </div>
                                            </div>

                                            <!-- Turni Orari del Luogo -->
                                            <div style="padding:16px; background:#fafafa; display:flex; flex-direction:column; gap:16px;">
                                                <?php if (! empty($plc_shifts)) : ?>
                                                    <?php foreach ($plc_shifts as $shift) : 
                                                        $assignments   = dfn_get_volunteer_shift_assignments((int) $shift->id);
                                                        $del_shift_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id . '&delete_shift=' . $shift->id), 'dfn_del_shift_' . $shift->id);
                                                        
                                                        // IDs già assegnati a questo shift
                                                        $assigned_vol_ids = [];
                                                        foreach ($assignments as $as_item) {
                                                            if (! empty($as_item->volunteer_id)) {
                                                                $assigned_vol_ids[] = (int) $as_item->volunteer_id;
                                                            }
                                                        }

                                                        // Chiave slot per trovare i volontari disponibili dal sondaggio
                                                        $shift_clean_k = preg_replace('/[^a-z0-9]/', '', strtolower($shift->shift_label . substr($shift->time_start, 0, 5)));
                                                        $avail_vol_ids_for_shift = $survey_avail_by_slot[$d->id][$shift_clean_k] ?? [];
                                                        if (empty($avail_vol_ids_for_shift)) {
                                                            $fallback_k = (strpos($shift_clean_k, 'pomeriggio') !== false || strpos($shift_clean_k, '14') !== false || strpos($shift_clean_k, '15') !== false) ? 'pomeriggio' : 'mattina';
                                                            $avail_vol_ids_for_shift = $survey_avail_by_slot[$d->id][$fallback_k] ?? [];
                                                        }
                                                    ?>
                                                        <div style="background:#fff; border-radius:8px; border:1px solid #cbd5e1; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                                                            <!-- Header Slot con orari modificabili -->
                                                            <div style="background:#004b23; color:#fff; padding:9px 14px; font-size:13.5px; font-weight:800; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                                                <div style="display:flex; align-items:center; gap:8px;">
                                                                    <span>⏰ <?php echo esc_html($shift->shift_label); ?> (<?php echo esc_html(substr($shift->time_start, 0, 5) . ' - ' . substr($shift->time_end, 0, 5)); ?>)</span>
                                                                </div>

                                                                <div style="display:flex; align-items:center; gap:8px;">
                                                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="display:inline-flex; align-items:center; gap:4px;">
                                                                        <?php wp_nonce_field('dfn_edit_shift_action', 'dfn_edit_shift_nonce'); ?>
                                                                        <input type="hidden" name="shift_id" value="<?php echo esc_attr($shift->id); ?>">
                                                                        <input type="hidden" name="shift_label" value="<?php echo esc_attr($shift->shift_label); ?>">
                                                                        <input type="time" name="time_start" value="<?php echo esc_attr(substr($shift->time_start, 0, 5)); ?>" style="height:26px; font-size:11px; padding:0 4px; border-radius:4px; border:none;">
                                                                        <input type="time" name="time_end" value="<?php echo esc_attr(substr($shift->time_end, 0, 5)); ?>" style="height:26px; font-size:11px; padding:0 4px; border-radius:4px; border:none;">
                                                                        <button type="submit" name="dfn_edit_shift" class="button button-small" style="font-size:11px; height:26px; line-height:24px; padding:0 6px;">
                                                                            💾 Salva
                                                                        </button>
                                                                    </form>

                                                                    <a href="<?php echo esc_url($del_shift_url); ?>" class="button button-small" style="color:#fee2e2; background:rgba(239,68,68,0.3); border:none; height:26px; line-height:24px;" onclick="return confirm('Eliminare questo slot orario?');" title="Elimina slot">
                                                                        🗑️
                                                                    </a>
                                                                </div>
                                                            </div>

                                                            <!-- Corpo Slot: Assegnazioni e Aggiunta -->
                                                            <div style="padding:14px;">
                                                                <!-- Dropzone Volontari Assegnati -->
                                                                <div class="dfn-shift-dropzone" data-shift-id="<?php echo esc_attr($shift->id); ?>" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:10px; margin-bottom:14px; min-height:48px; padding:6px; border-radius:8px; transition:all 0.2s ease;">
                                                                    <?php if (! empty($assignments)) : ?>
                                                                        <?php foreach ($assignments as $a) : 
                                                                            $r_obj = $roles_by_key[$a->role_assigned] ?? null;
                                                                            if (! $r_obj) {
                                                                                $r_obj = function_exists('dfn_get_volunteer_role_by_key') ? dfn_get_volunteer_role_by_key($a->role_assigned) : null;
                                                                            }
                                                                            $r_color = $r_obj ? $r_obj->badge_color : '#475569';
                                                                            $r_bg    = $r_obj ? $r_obj->badge_bg : '#f1f5f9';

                                                                            $v_name = $a->volunteer_id ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
                                                                            $v_key  = $a->volunteer_id ? ('id_' . $a->volunteer_id) : ('manual_' . sanitize_key($a->volunteer_name_manual));
                                                                            $del_ass_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id . '&remove_assignment=' . $a->id), 'dfn_del_ass_' . $a->id);
                                                                        ?>
                                                                            <div class="dfn-vol-draggable-card" draggable="true" data-assignment-id="<?php echo esc_attr($a->id); ?>" data-shift-id="<?php echo esc_attr($shift->id); ?>" data-volunteer-key="<?php echo esc_attr($v_key); ?>" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:8px 12px; gap:8px; cursor:grab; user-select:none; transition:transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease, filter 0.15s ease;">
                                                                                <div style="flex:1; min-width:0;">
                                                                                    <div style="display:flex; align-items:center; gap:6px;">
                                                                                        <span style="color:#94a3b8; font-size:12px; cursor:grab;" title="Trascina per spostare">⠿</span>
                                                                                        <strong style="font-size:13px; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo esc_attr($v_name); ?>">
                                                                                            <?php echo esc_html($v_name); ?>
                                                                                            <?php if (empty($a->volunteer_id)) : ?>
                                                                                                <span style="font-size:10.5px; color:#64748b; font-weight:normal; font-style:italic;">(Manuale)</span>
                                                                                            <?php endif; ?>
                                                                                        </strong>
                                                                                    </div>
                                                                                    
                                                                                    <!-- Menu Rapido Cambio Mansione -->
                                                                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="margin-top:4px;">
                                                                                        <?php wp_nonce_field('dfn_update_role_action', 'dfn_update_role_nonce'); ?>
                                                                                        <input type="hidden" name="assignment_id" value="<?php echo esc_attr($a->id); ?>">
                                                                                        <select name="new_role" onchange="this.form.submit()" class="dfn-role-inline-select" style="background:<?php echo esc_attr($r_bg); ?>; color:<?php echo esc_attr($r_color); ?>;" title="Clicca per cambiare mansione">
                                                                                            <?php if (! empty($event_roles)) : ?>
                                                                                                <?php foreach ($event_roles as $er_item) : 
                                                                                                    $b_code = trim((string) $er_item->badge_code);
                                                                                                    $r_title = $er_item->role_name;
                                                                                                    if (! empty($b_code) && stripos($r_title, $b_code) === false) {
                                                                                                        $r_title .= ' ' . $b_code;
                                                                                                    }
                                                                                                ?>
                                                                                                    <option value="<?php echo esc_attr($er_item->role_key); ?>" <?php selected($a->role_assigned, $er_item->role_key); ?>>
                                                                                                        <?php echo esc_html($r_title); ?>
                                                                                                    </option>
                                                                                                <?php endforeach; ?>
                                                                                            <?php else : ?>
                                                                                                <option value="<?php echo esc_attr($a->role_assigned); ?>" selected><?php echo esc_html(ucfirst($a->role_assigned)); ?></option>
                                                                                            <?php endif; ?>
                                                                                        </select>
                                                                                    </form>
                                                                                </div>
                                                                                <a href="<?php echo esc_url($del_ass_url); ?>" style="color:#ef4444; text-decoration:none; font-size:14px; font-weight:700; padding:4px;" title="Rimuovi dal turno">✕</a>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    <?php else : ?>
                                                                        <div class="dfn-dropzone-empty-msg" style="grid-column: 1 / -1; color:#94a3b8; font-style:italic; padding:12px; text-align:center; border:1px dashed #cbd5e1; border-radius:6px; background:#fff;">
                                                                            Nessun volontario assegnato. Trascina qui un volontario o usa il modulo in basso.
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>

                                                                <!-- Form Assegnazione Volontario allo Slot -->
                                                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px;">
                                                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                                                        <?php wp_nonce_field('dfn_assign_action', 'dfn_assign_nonce'); ?>
                                                                        <input type="hidden" name="shift_id" value="<?php echo esc_attr($shift->id); ?>">

                                                                        <div style="flex:1; min-width:200px;">
                                                                            <select name="volunteer_id" class="dfn-shift-volunteer-select" data-shift-id="<?php echo esc_attr($shift->id); ?>" style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:30px;">
                                                                                <option value="">-- Seleziona Volontario --</option>
                                                                                <?php 
                                                                                $avail_vols = [];
                                                                                $other_vols = [];
                                                                                foreach ($all_volunteers as $av) {
                                                                                    if (in_array((int) $av->id, $avail_vol_ids_for_shift, true)) {
                                                                                        $avail_vols[] = $av;
                                                                                    } else {
                                                                                        $other_vols[] = $av;
                                                                                    }
                                                                                }
                                                                                ?>
                                                                                <?php if (! empty($avail_vols)) : ?>
                                                                                    <optgroup label="✅ Disponibili dal Sondaggio" class="dfn-optgroup-survey">
                                                                                        <?php foreach ($avail_vols as $av) : 
                                                                                            $is_assigned = in_array((int) $av->id, $assigned_vol_ids, true);
                                                                                            $extra_label = '';
                                                                                            if (! empty($av->has_safety_course)) $extra_label .= ' [🦺 Sicurezza]';
                                                                                            if (! empty($av->is_guide)) $extra_label .= ' [🏛️ Guida]';
                                                                                        ?>
                                                                                            <option value="<?php echo esc_attr($av->id); ?>" data-volunteer-id="<?php echo esc_attr($av->id); ?>" <?php echo $is_assigned ? 'style="display:none;" disabled' : ''; ?>>
                                                                                                <?php echo esc_html($av->first_name . ' ' . $av->last_name . $extra_label); ?>
                                                                                            </option>
                                                                                        <?php endforeach; ?>
                                                                                    </optgroup>
                                                                                <?php endif; ?>
                                                                                <?php if (! empty($other_vols)) : ?>
                                                                                    <optgroup label="👥 Altri Volontari Registrati" class="dfn-optgroup-others">
                                                                                        <?php foreach ($other_vols as $av) : 
                                                                                            $is_assigned = in_array((int) $av->id, $assigned_vol_ids, true);
                                                                                            $extra_label = '';
                                                                                            if (! empty($av->has_safety_course)) $extra_label .= ' [🦺 Sicurezza]';
                                                                                            if (! empty($av->is_guide)) $extra_label .= ' [🏛️ Guida]';
                                                                                        ?>
                                                                                            <option value="<?php echo esc_attr($av->id); ?>" data-volunteer-id="<?php echo esc_attr($av->id); ?>" <?php echo $is_assigned ? 'style="display:none;" disabled' : ''; ?>>
                                                                                                <?php echo esc_html($av->first_name . ' ' . $av->last_name . $extra_label); ?>
                                                                                            </option>
                                                                                        <?php endforeach; ?>
                                                                                    </optgroup>
                                                                                <?php endif; ?>
                                                                            </select>
                                                                        </div>

                                                                        <div style="color:#94a3b8; font-size:11px; font-weight:700;">OPPURE</div>

                                                                        <div style="flex:1; min-width:160px;">
                                                                            <input type="text" name="volunteer_manual" placeholder="Nome e Cognome a mano..." style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:30px; padding:0 8px;">
                                                                        </div>

                                                                        <div style="width:180px;">
                                                                            <select name="role_assigned" style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:30px;">
                                                                                <?php if (! empty($event_roles)) : ?>
                                                                                    <?php foreach ($event_roles as $er_opt) : 
                                                                                        $b_code = trim((string) $er_opt->badge_code);
                                                                                        $r_title = $er_opt->role_name;
                                                                                        if (! empty($b_code) && stripos($r_title, $b_code) === false) {
                                                                                            $r_title .= ' ' . $b_code;
                                                                                        }
                                                                                    ?>
                                                                                        <option value="<?php echo esc_attr($er_opt->role_key); ?>">
                                                                                            <?php echo esc_html($r_title); ?>
                                                                                        </option>
                                                                                    <?php endforeach; ?>
                                                                                <?php else : ?>
                                                                                    <option value="banchetto">Volontario</option>
                                                                                <?php endif; ?>
                                                                            </select>
                                                                        </div>

                                                                        <button type="submit" name="dfn_assign_volunteer" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; height:30px; padding:0 12px;">
                                                                            ➕ Assegna
                                                                        </button>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else : ?>
                                                    <div style="background:#fff; border-radius:6px; border:1px dashed #cbd5e1; padding:16px; text-align:center; color:#64748b; font-size:12.5px;">
                                                        Nessun turno orario presente per questo luogo. Usa il modulo qui sopra <em>"➕ Aggiungi Turno"</em> per definire gli orari.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <div style="background:#fff; border-radius:8px; border:1px dashed #cbd5e1; padding:24px; text-align:center; color:#64748b;">
                                    Nessun luogo/bene aperto inserito per questo giorno. Usa il modulo in alto per aggiungere il primo luogo (es. <em>Duomo di Novara</em>).
                                </div>
                            <?php endif; ?>

                        <?php else : 
                            $single_place = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_places WHERE day_id = %d ORDER BY id ASC LIMIT 1", $d->id));
                        ?>
                            <!-- Layout Evento Locale -->
                            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:14px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span style="font-size:13px; font-weight:700; color:#004b23;">📍 Luogo Evento Locale:</span>
                                    <strong style="font-size:14px; color:#0f172a; margin-left:6px;"><?php echo esc_html($single_place ? $single_place->place_name : 'Sede Evento'); ?></strong>
                                </div>

                                <?php if ($single_place) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <?php wp_nonce_field('dfn_add_shift_action', 'dfn_shift_nonce'); ?>
                                        <input type="hidden" name="place_id" value="<?php echo esc_attr($single_place->id); ?>">
                                        <input type="hidden" name="day_id" value="<?php echo esc_attr($d->id); ?>">
                                        <input type="text" name="shift_label" required placeholder="Turno (es. Mattina)" style="width:140px; border-radius:6px; border:1px solid #cbd5e1; height:30px; padding:0 8px; font-size:12px;">
                                        <input type="time" name="time_start" required style="border-radius:6px; border:1px solid #cbd5e1; height:30px; padding:0 6px; font-size:12px;">
                                        <span>-</span>
                                        <input type="time" name="time_end" required style="border-radius:6px; border:1px solid #cbd5e1; height:30px; padding:0 6px; font-size:12px;">
                                        <button type="submit" name="dfn_add_shift" class="button button-primary button-small" style="background:#004b23; border-color:#003b1c; font-weight:700;">
                                            ➕ Aggiungi
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <!-- RENDERING SLOTS ORARI E TURNI EVENTO LOCALE -->
                            <?php if (! empty($d_shifts)) : ?>
                                <?php foreach ($d_shifts as $shift) :
                                    $assignments   = dfn_get_volunteer_shift_assignments((int) $shift->id);
                                    $del_shift_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id . '&delete_shift=' . $shift->id), 'dfn_del_shift_' . $shift->id);
                                    
                                    $assigned_vol_ids = [];
                                    foreach ($assignments as $as_item) {
                                        if (! empty($as_item->volunteer_id)) {
                                            $assigned_vol_ids[] = (int) $as_item->volunteer_id;
                                        }
                                    }

                                    $shift_clean_k = preg_replace('/[^a-z0-9]/', '', strtolower($shift->shift_label . substr($shift->time_start, 0, 5)));
                                    $avail_vol_ids_for_shift = $survey_avail_by_slot[$d->id][$shift_clean_k] ?? [];
                                    if (empty($avail_vol_ids_for_shift)) {
                                        $fallback_k = (strpos($shift_clean_k, 'pomeriggio') !== false || strpos($shift_clean_k, '14') !== false || strpos($shift_clean_k, '15') !== false) ? 'pomeriggio' : 'mattina';
                                        $avail_vol_ids_for_shift = $survey_avail_by_slot[$d->id][$fallback_k] ?? [];
                                    }
                                ?>
                                    <div style="margin-bottom:20px; background:#fff; border-radius:8px; border:1px solid #cbd5e1; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.04);">
                                        <div style="background:#004b23; color:#fff; padding:10px 16px; font-size:14.5px; font-weight:800; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span>⏰ <?php echo esc_html($shift->shift_label); ?> (<?php echo esc_html(substr($shift->time_start, 0, 5) . ' - ' . substr($shift->time_end, 0, 5)); ?>)</span>
                                            </div>

                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="display:inline-flex; align-items:center; gap:4px;">
                                                    <?php wp_nonce_field('dfn_edit_shift_action', 'dfn_edit_shift_nonce'); ?>
                                                    <input type="hidden" name="shift_id" value="<?php echo esc_attr($shift->id); ?>">
                                                    <input type="hidden" name="shift_label" value="<?php echo esc_attr($shift->shift_label); ?>">
                                                    <input type="time" name="time_start" value="<?php echo esc_attr(substr($shift->time_start, 0, 5)); ?>" style="height:26px; font-size:11px; padding:0 4px; border-radius:4px; border:none;">
                                                    <input type="time" name="time_end" value="<?php echo esc_attr(substr($shift->time_end, 0, 5)); ?>" style="height:26px; font-size:11px; padding:0 4px; border-radius:4px; border:none;">
                                                    <button type="submit" name="dfn_edit_shift" class="button button-small" style="font-size:11px; height:26px; line-height:24px; padding:0 6px;">
                                                        💾 Salva Orari
                                                    </button>
                                                </form>

                                                <a href="<?php echo esc_url($del_shift_url); ?>" class="button button-small" style="color:#fee2e2; background:rgba(239,68,68,0.3); border:none; height:26px; line-height:24px;" onclick="return confirm('Eliminare questo slot orario?');" title="Elimina slot">
                                                    🗑️
                                                </a>
                                            </div>
                                        </div>

                                        <div style="padding:16px;">
                                            <div class="dfn-shift-dropzone" data-shift-id="<?php echo esc_attr($shift->id); ?>" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap:10px; margin-bottom:16px; min-height:50px; padding:6px; border-radius:8px; transition:all 0.2s ease;">
                                                <?php if (! empty($assignments)) : ?>
                                                    <?php foreach ($assignments as $a) : 
                                                        $r_obj = $roles_by_key[$a->role_assigned] ?? null;
                                                        if (! $r_obj) {
                                                            $r_obj = function_exists('dfn_get_volunteer_role_by_key') ? dfn_get_volunteer_role_by_key($a->role_assigned) : null;
                                                        }
                                                        $r_color = $r_obj ? $r_obj->badge_color : '#475569';
                                                        $r_bg    = $r_obj ? $r_obj->badge_bg : '#f1f5f9';

                                                        $v_name = $a->volunteer_id ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
                                                        $v_key  = $a->volunteer_id ? ('id_' . $a->volunteer_id) : ('manual_' . sanitize_key($a->volunteer_name_manual));
                                                        $del_ass_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id . '&remove_assignment=' . $a->id), 'dfn_del_ass_' . $a->id);
                                                    ?>
                                                        <div class="dfn-vol-draggable-card" draggable="true" data-assignment-id="<?php echo esc_attr($a->id); ?>" data-shift-id="<?php echo esc_attr($shift->id); ?>" data-volunteer-key="<?php echo esc_attr($v_key); ?>" style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:8px; padding:8px 12px; gap:8px; cursor:grab; user-select:none; transition:transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease, filter 0.15s ease;">
                                                            <div style="flex:1; min-width:0;">
                                                                <div style="display:flex; align-items:center; gap:6px;">
                                                                    <span style="color:#94a3b8; font-size:12px; cursor:grab;" title="Trascina per spostare">⠿</span>
                                                                    <strong style="font-size:13px; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?php echo esc_attr($v_name); ?>">
                                                                        <?php echo esc_html($v_name); ?>
                                                                        <?php if (empty($a->volunteer_id)) : ?>
                                                                            <span style="font-size:10.5px; color:#64748b; font-weight:normal; font-style:italic;">(Manuale)</span>
                                                                        <?php endif; ?>
                                                                    </strong>
                                                                </div>
                                                                
                                                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="margin-top:4px;">
                                                                    <?php wp_nonce_field('dfn_update_role_action', 'dfn_update_role_nonce'); ?>
                                                                    <input type="hidden" name="assignment_id" value="<?php echo esc_attr($a->id); ?>">
                                                                    <select name="new_role" onchange="this.form.submit()" class="dfn-role-inline-select" style="background:<?php echo esc_attr($r_bg); ?>; color:<?php echo esc_attr($r_color); ?>;" title="Clicca per cambiare mansione">
                                                                        <?php if (! empty($event_roles)) : ?>
                                                                            <?php foreach ($event_roles as $er_item) : 
                                                                                $b_code = trim((string) $er_item->badge_code);
                                                                                $r_title = $er_item->role_name;
                                                                                if (! empty($b_code) && stripos($r_title, $b_code) === false) {
                                                                                    $r_title .= ' ' . $b_code;
                                                                                }
                                                                            ?>
                                                                                <option value="<?php echo esc_attr($er_item->role_key); ?>" <?php selected($a->role_assigned, $er_item->role_key); ?>>
                                                                                    <?php echo esc_html($r_title); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        <?php else : ?>
                                                                            <option value="<?php echo esc_attr($a->role_assigned); ?>" selected><?php echo esc_html(ucfirst($a->role_assigned)); ?></option>
                                                                        <?php endif; ?>
                                                                    </select>
                                                                </form>
                                                            </div>
                                                            <a href="<?php echo esc_url($del_ass_url); ?>" style="color:#ef4444; text-decoration:none; font-size:14px; font-weight:700; padding:4px;" title="Rimuovi dal turno">✕</a>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else : ?>
                                                    <div class="dfn-dropzone-empty-msg" style="grid-column: 1 / -1; color:#94a3b8; font-style:italic; padding:12px; text-align:center; border:1px dashed #cbd5e1; border-radius:6px; background:#fff;">
                                                        Nessun volontario assegnato. Trascina qui un volontario o usa il modulo in basso.
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px;">
                                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                                    <?php wp_nonce_field('dfn_assign_action', 'dfn_assign_nonce'); ?>
                                                    <input type="hidden" name="shift_id" value="<?php echo esc_attr($shift->id); ?>">

                                                    <div style="flex:1; min-width:220px;">
                                                        <select name="volunteer_id" class="dfn-shift-volunteer-select" data-shift-id="<?php echo esc_attr($shift->id); ?>" style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:32px;">
                                                            <option value="">-- Seleziona Volontario --</option>
                                                            <?php 
                                                            $avail_vols = [];
                                                            $other_vols = [];
                                                            foreach ($all_volunteers as $av) {
                                                                if (in_array((int) $av->id, $avail_vol_ids_for_shift, true)) {
                                                                    $avail_vols[] = $av;
                                                                } else {
                                                                    $other_vols[] = $av;
                                                                }
                                                            }
                                                            ?>
                                                            <?php if (! empty($avail_vols)) : ?>
                                                                <optgroup label="✅ Disponibili dal Sondaggio" class="dfn-optgroup-survey">
                                                                    <?php foreach ($avail_vols as $av) : 
                                                                        $is_assigned = in_array((int) $av->id, $assigned_vol_ids, true);
                                                                        $extra_label = '';
                                                                        if (! empty($av->has_safety_course)) $extra_label .= ' [🦺 Sicurezza]';
                                                                        if (! empty($av->is_guide)) $extra_label .= ' [🏛️ Guida]';
                                                                    ?>
                                                                        <option value="<?php echo esc_attr($av->id); ?>" data-volunteer-id="<?php echo esc_attr($av->id); ?>" <?php echo $is_assigned ? 'style="display:none;" disabled' : ''; ?>>
                                                                            <?php echo esc_html($av->first_name . ' ' . $av->last_name . $extra_label); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </optgroup>
                                                            <?php endif; ?>
                                                            <?php if (! empty($other_vols)) : ?>
                                                                <optgroup label="👥 Altri Volontari Registrati" class="dfn-optgroup-others">
                                                                    <?php foreach ($other_vols as $av) : 
                                                                        $is_assigned = in_array((int) $av->id, $assigned_vol_ids, true);
                                                                        $extra_label = '';
                                                                        if (! empty($av->has_safety_course)) $extra_label .= ' [🦺 Sicurezza]';
                                                                        if (! empty($av->is_guide)) $extra_label .= ' [🏛️ Guida]';
                                                                    ?>
                                                                        <option value="<?php echo esc_attr($av->id); ?>" data-volunteer-id="<?php echo esc_attr($av->id); ?>" <?php echo $is_assigned ? 'style="display:none;" disabled' : ''; ?>>
                                                                            <?php echo esc_html($av->first_name . ' ' . $av->last_name . $extra_label); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </optgroup>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>

                                                    <div style="color:#94a3b8; font-size:11.5px; font-weight:700;">OPPURE</div>

                                                    <div style="flex:1; min-width:180px;">
                                                        <input type="text" name="volunteer_manual" placeholder="Nome e Cognome a mano..." style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:32px; padding:0 8px;">
                                                    </div>

                                                    <div style="width:190px;">
                                                        <select name="role_assigned" style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:32px;">
                                                            <?php if (! empty($event_roles)) : ?>
                                                                <?php foreach ($event_roles as $er_opt) : 
                                                                    $b_code = trim((string) $er_opt->badge_code);
                                                                    $r_title = $er_opt->role_name;
                                                                    if (! empty($b_code) && stripos($r_title, $b_code) === false) {
                                                                        $r_title .= ' ' . $b_code;
                                                                    }
                                                                ?>
                                                                    <option value="<?php echo esc_attr($er_opt->role_key); ?>">
                                                                        <?php echo esc_html($r_title); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            <?php else : ?>
                                                                <option value="banchetto">Volontario</option>
                                                            <?php endif; ?>
                                                        </select>
                                                    </div>

                                                    <button type="submit" name="dfn_assign_volunteer" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; height:32px; padding:0 14px;">
                                                        ➕ Assegna al Turno
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div style="background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1; padding:24px; text-align:center; color:#64748b;">
                                    Nessun turno orario presente per questo giorno. Usa il modulo in alto per aggiungere uno slot.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var dragNonce = '<?php echo esc_js(wp_create_nonce('dfn_matrix_drag_drop_nonce')); ?>';
            var draggedCard = null;
            var dragHoverTimer = null;

            // Accordion Multipli Indipendenti (puoi aprire più giorni contemporaneamente)
            var accordions = document.querySelectorAll('.dfn-day-accordion');

            // DRAG & DROP DEI VOLONTARI SUI TURNI
            function attachDragListeners() {
                var cards = document.querySelectorAll('.dfn-vol-draggable-card');
                cards.forEach(function(card) {
                    card.addEventListener('dragstart', function(e) {
                        draggedCard = this;
                        this.style.opacity = '0.4';
                        this.style.transform = 'scale(0.98)';
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', this.getAttribute('data-assignment-id'));
                    });

                    card.addEventListener('dragend', function() {
                        this.style.opacity = '1';
                        this.style.transform = 'none';
                        draggedCard = null;
                        if (dragHoverTimer) {
                            clearTimeout(dragHoverTimer);
                            dragHoverTimer = null;
                        }
                        document.querySelectorAll('.dfn-shift-dropzone').forEach(function(dz) {
                            dz.style.background = '';
                            dz.style.border = '';
                        });
                    });
                });

                var dropzones = document.querySelectorAll('.dfn-shift-dropzone');
                dropzones.forEach(function(dz) {
                    dz.addEventListener('dragover', function(e) {
                        e.preventDefault();
                        if (!draggedCard) return;

                        var volKey = draggedCard.getAttribute('data-volunteer-key');
                        var oldShiftId = draggedCard.getAttribute('data-shift-id');
                        var targetShiftId = this.getAttribute('data-shift-id');

                        // Controlla se la dropzone contiene già questo volontario (diverso dalla card trascinata)
                        var isDuplicate = false;
                        if (targetShiftId !== oldShiftId) {
                            var existingCard = this.querySelector('.dfn-vol-draggable-card[data-volunteer-key="' + volKey + '"]');
                            if (existingCard && existingCard !== draggedCard) {
                                isDuplicate = true;
                            }
                        }

                        if (isDuplicate) {
                            e.dataTransfer.dropEffect = 'none';
                            this.style.background = '#fef2f2';
                            this.style.border = '2px dashed #ef4444';
                            draggedCard.style.opacity = '0.25';
                            draggedCard.style.filter = 'grayscale(100%)';
                        } else {
                            e.dataTransfer.dropEffect = 'move';
                            this.style.background = '#f0fdf4';
                            this.style.border = '2px dashed #16a34a';
                            draggedCard.style.opacity = '0.6';
                            draggedCard.style.filter = 'none';
                        }
                    });

                    dz.addEventListener('dragleave', function() {
                        this.style.background = '';
                        this.style.border = '';
                        if (draggedCard) {
                            draggedCard.style.opacity = '0.5';
                            draggedCard.style.filter = 'none';
                        }
                    });

                    dz.addEventListener('drop', function(e) {
                        e.preventDefault();
                        this.style.background = '';
                        this.style.border = '';

                        if (!draggedCard) return;

                        draggedCard.style.opacity = '1';
                        draggedCard.style.filter = 'none';

                        var assignmentId = draggedCard.getAttribute('data-assignment-id');
                        var oldShiftId = draggedCard.getAttribute('data-shift-id');
                        var newShiftId = this.getAttribute('data-shift-id');
                        var volKey = draggedCard.getAttribute('data-volunteer-key');

                        if (oldShiftId === newShiftId) {
                            return; // Stesso slot
                        }

                        // Controllo preventivo duplicazione
                        var existingCard = this.querySelector('.dfn-vol-draggable-card[data-volunteer-key="' + volKey + '"]');
                        if (existingCard && existingCard !== draggedCard) {
                            alert('⚠️ Impossibile spostare: questo volontario è già presente in questo turno orario.');
                            return;
                        }

                        var targetZone = this;
                        var cardToMove = draggedCard;
                        var oldZone = document.querySelector('.dfn-shift-dropzone[data-shift-id="' + oldShiftId + '"]');

                        // Rimuovi eventuale messaggio 'Nessun volontario assegnato' dalla dropzone di destinazione
                        var emptyMsg = targetZone.querySelector('.dfn-dropzone-empty-msg');
                        if (emptyMsg) {
                            emptyMsg.remove();
                        }

                        // Feedback visivo immediato (sposta card nel DOM)
                        targetZone.appendChild(cardToMove);
                        cardToMove.setAttribute('data-shift-id', newShiftId);

                        // Se la dropzone di origine è rimasta vuota, reinserisci il messaggio placeholder
                        if (oldZone && !oldZone.querySelector('.dfn-vol-draggable-card')) {
                            var newEmptyMsg = document.createElement('div');
                            newEmptyMsg.className = 'dfn-dropzone-empty-msg';
                            newEmptyMsg.style.cssText = 'grid-column: 1 / -1; color:#94a3b8; font-style:italic; padding:12px; text-align:center; border:1px dashed #cbd5e1; border-radius:6px; background:#fff;';
                            newEmptyMsg.textContent = 'Nessun volontario assegnato. Trascina qui un volontario o usa il modulo in basso.';
                            oldZone.appendChild(newEmptyMsg);
                        }

                        // AGGIORNAMENTO DINAMICO DELLE SELECT BOX (SLOT DI PARTENZA E SLOT DI ARRIVO)
                        if (volKey && volKey.indexOf('id_') === 0) {
                            var volIdNum = volKey.replace('id_', '');

                            // 1. Nello slot di partenza (oldShiftId): riabilita e mostra l'opzione del volontario
                            var oldSelect = document.querySelector('.dfn-shift-volunteer-select[data-shift-id="' + oldShiftId + '"]');
                            if (oldSelect) {
                                var oldOpt = oldSelect.querySelector('option[data-volunteer-id="' + volIdNum + '"]');
                                if (oldOpt) {
                                    oldOpt.style.display = '';
                                    oldOpt.removeAttribute('disabled');
                                }
                            }

                            // 2. Nello slot di destinazione (newShiftId): disabilita e nascondi l'opzione del volontario
                            var newSelect = document.querySelector('.dfn-shift-volunteer-select[data-shift-id="' + newShiftId + '"]');
                            if (newSelect) {
                                var newOpt = newSelect.querySelector('option[data-volunteer-id="' + volIdNum + '"]');
                                if (newOpt) {
                                    newOpt.style.display = 'none';
                                    newOpt.setAttribute('disabled', 'disabled');
                                    if (newSelect.value === volIdNum) {
                                        newSelect.value = '';
                                    }
                                }
                            }
                        }

                        // Esegui chiamata AJAX
                        var formData = new FormData();
                        formData.append('action', 'dfn_move_volunteer_shift');
                        formData.append('security', dragNonce);
                        formData.append('assignment_id', assignmentId);
                        formData.append('target_shift_id', newShiftId);

                        fetch(ajaxUrl, {
                            method: 'POST',
                            body: formData
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(response) {
                            if (response.success) {
                                // Mostra breve feedback verde
                                cardToMove.style.boxShadow = '0 0 0 2px #16a34a';
                                setTimeout(function() {
                                    cardToMove.style.boxShadow = '';
                                }, 1000);
                            } else {
                                alert(response.data ? response.data.message : 'Errore durante lo spostamento.');
                                window.location.reload();
                            }
                        })
                        .catch(function(err) {
                            alert('Errore di connessione durante lo spostamento del turno.');
                            window.location.reload();
                        });
                    });
                });
            }

            attachDragListeners();
        });
        </script>
    </div>
    <?php
}

/**
 * ------------------------------------------------------------------------
 * 4. ALGORITMO DI ASSEGNAZIONE AUTOMATICA INTELLIGENTE & BILANCIATA
 * ------------------------------------------------------------------------
 */
function dfn_run_volunteer_auto_assignment(int $event_id, int $day_id): int
{
    global $wpdb;
    $table_resp  = $wpdb->prefix . 'dfn_volunteer_survey_responses';
    $table_fai   = $wpdb->prefix . 'dfn_fai_members';
    $table_shifts= $wpdb->prefix . 'dfn_volunteer_event_shifts';
    $table_places= $wpdb->prefix . 'dfn_volunteer_event_places';
    $table_ass   = $wpdb->prefix . 'dfn_volunteer_shift_assignments';

    $survey = dfn_get_volunteer_survey_by_event($event_id);
    if (! $survey) {
        return 0;
    }

    // Recupera solo le mansioni effettivamente abilitate per questo evento
    $event_roles = function_exists('dfn_get_volunteer_event_roles') ? dfn_get_volunteer_event_roles($event_id) : [];
    if (empty($event_roles)) {
        $event_roles = function_exists('dfn_get_all_volunteer_roles') ? dfn_get_all_volunteer_roles() : [];
    }

    if (empty($event_roles)) {
        return 0;
    }

    // Mappa mansioni speciali abilitate per questo evento
    $safety_role_key = null;
    $guide_role_key  = null;
    $standard_role_keys = [];

    foreach ($event_roles as $er) {
        if (! empty($er->requires_safety_course) && ! $safety_role_key) {
            $safety_role_key = $er->role_key;
        } elseif (! empty($er->requires_guide) && ! $guide_role_key) {
            $guide_role_key = $er->role_key;
        } else {
            $standard_role_keys[] = $er->role_key;
        }
    }

    // Se tutte le mansioni erano speciali e non ci sono standard, usa tutte le chiavi disponibili
    if (empty($standard_role_keys)) {
        foreach ($event_roles as $er) {
            $standard_role_keys[] = $er->role_key;
        }
    }

    $assigned_count = 0;

    $target_days = [];
    if ($day_id > 0) {
        $target_days = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_days WHERE id = %d", $day_id));
    } else {
        $target_days = dfn_get_volunteer_event_days($event_id);
    }

    if (empty($target_days)) {
        return 0;
    }

    // Carica tutte le risposte disponibili per il sondaggio una sola volta
    $all_responses = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, f.is_guide, f.has_safety_course 
         FROM {$table_resp} r
         LEFT JOIN {$table_fai} f ON r.volunteer_id = f.id
         WHERE r.survey_id = %d AND r.is_available = 1",
        $survey->id
    ));

    $responses_by_day = [];
    foreach ($all_responses as $resp) {
        $clean_k = preg_replace('/[^a-z0-9]/', '', strtolower($resp->time_slot_key));
        $responses_by_day[$resp->day_id][$resp->time_slot_key][] = $resp;
        $responses_by_day[$resp->day_id][$clean_k][] = $resp;
    }

    foreach ($target_days as $t_day) {
        $shifts_in_day = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, p.place_name FROM {$table_shifts} s
             JOIN {$table_places} p ON s.place_id = p.id
             WHERE s.day_id = %d
             ORDER BY s.time_start ASC, s.id ASC",
            $t_day->id
        ));

        if (empty($shifts_in_day)) {
            continue;
        }

        // Raggruppa gli shift per fascia oraria/chiave
        $shifts_by_slot = [];
        foreach ($shifts_in_day as $sh) {
            $slot_k = sanitize_key($sh->shift_label . '_' . substr($sh->time_start, 0, 5));
            $shifts_by_slot[$slot_k][] = $sh;
        }

        foreach ($shifts_by_slot as $slot_key => $shifts) {
            $clean_target = preg_replace('/[^a-z0-9]/', '', strtolower($slot_key));
            $available_responses = $responses_by_day[$t_day->id][$slot_key] ?? ($responses_by_day[$t_day->id][$clean_target] ?? []);

            // Fallback per compatibilità vecchi slot
            if (empty($available_responses)) {
                $fallback_key = (strpos($slot_key, 'pomeriggio') !== false || strpos($slot_key, '14:') !== false || strpos($slot_key, '15:') !== false) ? 'pomeriggio' : 'mattina';
                $available_responses = $responses_by_day[$t_day->id][$fallback_key] ?? [];
            }

            if (empty($available_responses)) {
                continue;
            }

        // Tracciamento dei volontari assegnati nella specifica fascia oraria di questo giorno per evitare sovrapposizioni su più luoghi
        $slot_full_k = $t_day->id . '_' . $slot_key;
        $assigned_vols_in_current_slot = [];

        // Raggruppamento per competenze (filtrando eventuali volontari già allocati)
        $safety_volunteers = [];
        $guide_volunteers  = [];
        $general_volunteers= [];

        foreach ($available_responses as $resp) {
            $v_id = (int) $resp->volunteer_id;
            if ($v_id > 0 && in_array($v_id, $assigned_vols_in_current_slot, true)) {
                continue;
            }
            if (! empty($resp->has_safety_course) && $safety_role_key) {
                $safety_volunteers[] = $resp;
            } elseif (! empty($resp->is_guide) && $guide_role_key) {
                $guide_volunteers[] = $resp;
            } else {
                $general_volunteers[] = $resp;
            }
        }

        // 1. Assegnazione ruolo Sicurezza (se abilitato per l'evento)
        if ($safety_role_key) {
            foreach ($shifts as $shift) {
                $has_safety = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ass} WHERE shift_id = %d AND role_assigned = %s", $shift->id, $safety_role_key));
                if (! $has_safety && ! empty($safety_volunteers)) {
                    $picked = array_shift($safety_volunteers);
                    $v_id   = (int) $picked->volunteer_id;
                    if ($v_id > 0 && in_array($v_id, $assigned_vols_in_current_slot, true)) {
                        continue;
                    }
                    $wpdb->insert($table_ass, [
                        'shift_id'              => $shift->id,
                        'volunteer_id'          => $v_id ?: null,
                        'volunteer_name_manual' => ! $v_id ? ($picked->first_name . ' ' . $picked->last_name) : null,
                        'role_assigned'         => $safety_role_key,
                        'created_at'            => current_time('mysql'),
                    ], [ '%d', '%d', '%s', '%s', '%s' ]);
                    $assigned_count++;
                    if ($v_id > 0) {
                        $assigned_vols_in_current_slot[] = $v_id;
                    }
                }
            }
        }

        // 2. Assegnazione Guide (se abilitate per l'evento)
        if ($guide_role_key) {
            foreach ($shifts as $shift) {
                while (! empty($guide_volunteers)) {
                    $picked = array_shift($guide_volunteers);
                    $v_id   = (int) $picked->volunteer_id;
                    if ($v_id > 0 && in_array($v_id, $assigned_vols_in_current_slot, true)) {
                        continue;
                    }
                    $wpdb->insert($table_ass, [
                        'shift_id'              => $shift->id,
                        'volunteer_id'          => $v_id ?: null,
                        'volunteer_name_manual' => ! $v_id ? ($picked->first_name . ' ' . $picked->last_name) : null,
                        'role_assigned'         => $guide_role_key,
                        'created_at'            => current_time('mysql'),
                    ], [ '%d', '%d', '%s', '%s', '%s' ]);
                    $assigned_count++;
                    if ($v_id > 0) {
                        $assigned_vols_in_current_slot[] = $v_id;
                    }
                    break;
                }
            }
        }

        // 3. Distribuzione bilanciata round-robin di tutti i restanti volontari tra i luoghi e le mansioni abilitate
        $remaining_pool = [];
        foreach (array_merge($safety_volunteers, $guide_volunteers, $general_volunteers) as $cand) {
            $c_vid = (int) $cand->volunteer_id;
            if ($c_vid > 0 && in_array($c_vid, $assigned_vols_in_current_slot, true)) {
                continue;
            }
            $remaining_pool[] = $cand;
        }

        $shift_index = 0;
        $num_shifts  = count($shifts);
        $role_index  = 0;
        $num_roles   = count($standard_role_keys);

        while (! empty($remaining_pool)) {
            $picked = array_shift($remaining_pool);
            $v_id   = (int) $picked->volunteer_id;
            if ($v_id > 0 && in_array($v_id, $assigned_vols_in_current_slot, true)) {
                continue;
            }

            $shift  = $shifts[$shift_index % $num_shifts];
            $role   = $standard_role_keys[$role_index % $num_roles];

            $wpdb->insert($table_ass, [
                'shift_id'              => $shift->id,
                'volunteer_id'          => $v_id ?: null,
                'volunteer_name_manual' => ! $v_id ? ($picked->first_name . ' ' . $picked->last_name) : null,
                'role_assigned'         => $role,
                'created_at'            => current_time('mysql'),
            ], [ '%d', '%d', '%s', '%s', '%s' ]);
            $assigned_count++;
            if ($v_id > 0) {
                $assigned_vols_in_current_slot[] = $v_id;
            }

            $shift_index++;
            $role_index++;
        }
    }
}

    return $assigned_count;
}

/**
 * ------------------------------------------------------------------------
 * 5. GESTIONE PANNELLO SONDAGGIO DISPONIBILITÀ (ADMIN)
 * ------------------------------------------------------------------------
 */
function dfn_render_volunteer_event_survey_admin(int $event_id): void
{
    global $wpdb;
    $event = dfn_get_volunteer_event($event_id);
    if (! $event) {
        wp_die(__('Evento non trovato.', 'dfn-theme'));
    }

    $table_surveys = $wpdb->prefix . 'dfn_volunteer_surveys';
    $table_resp    = $wpdb->prefix . 'dfn_volunteer_survey_responses';
    $survey        = dfn_get_volunteer_survey_by_event($event_id);

    // Creazione o aggiornamento sondaggio
    if (isset($_POST['dfn_save_survey']) && wp_verify_nonce($_POST['dfn_survey_nonce'] ?? '', 'dfn_save_survey_action')) {
        $title       = sanitize_text_field($_POST['title'] ?? 'Sondaggio Disponibilità: ' . $event->title);
        $deadline_at = sanitize_text_field($_POST['deadline_at'] ?? '');
        $status      = sanitize_text_field($_POST['status'] ?? 'open');

        if (! empty($deadline_at)) {
            if ($survey) {
                $wpdb->update(
                    $table_surveys,
                    [ 'title' => $title, 'deadline_at' => $deadline_at, 'status' => $status ],
                    [ 'id' => $survey->id ],
                    [ '%s', '%s', '%s' ],
                    [ '%d' ]
                );
            } else {
                $token = wp_generate_password(24, false);
                $wpdb->insert(
                    $table_surveys,
                    [
                        'event_id'     => $event_id,
                        'title'        => $title,
                        'deadline_at'  => $deadline_at,
                        'status'       => $status,
                        'token_public' => $token,
                        'created_at'   => current_time('mysql'),
                    ],
                    [ '%d', '%s', '%s', '%s', '%s', '%s' ]
                );
            }

            // Aggiornamento automatico stato dell'evento in base al sondaggio
            $new_event_status = ($status === 'open') ? 'survey_open' : 'survey_closed';
            if ($event->status !== 'published' && $event->status !== 'completed') {
                $wpdb->update(
                    $wpdb->prefix . 'dfn_volunteer_events',
                    [ 'status' => $new_event_status ],
                    [ 'id' => $event_id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                $event = dfn_get_volunteer_event($event_id);
            }

            echo '<div class="notice notice-success is-dismissible"><p>✅ Impostazioni sondaggio aggiornate con successo! Stato evento aggiornato a <strong>' . esc_html($new_event_status === 'survey_open' ? 'Sondaggio Aperto' : 'Sondaggio Chiuso') . '</strong>.</p></div>';
            $survey = dfn_get_volunteer_survey_by_event($event_id);
        }
    }

    $survey_link = $survey ? home_url('/sondaggio-volontari/?token=' . $survey->token_public) : '';
    $days = dfn_get_volunteer_event_days($event_id);

    // Recupera solo le disponibilità positive (is_available = 1)
    $available_responses = $survey ? $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, f.first_name, f.last_name, f.email, f.is_guide, f.has_safety_course, f.card_number 
         FROM {$table_resp} r
         LEFT JOIN {$wpdb->prefix}dfn_fai_members f ON r.volunteer_id = f.id
         WHERE r.survey_id = %d AND r.is_available = 1 
         ORDER BY r.submitted_at DESC", 
        $survey->id
    )) : [];

    // Raggruppa le risposte disponibili per day_id e time_slot_key
    $grouped_responses = [];
    foreach ($available_responses as $r) {
        $grouped_responses[$r->day_id][$r->time_slot_key][] = $r;
    }

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom:24px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics')); ?>" style="text-decoration:none; color:#004b23; font-weight:700;">← Torna agli eventi</a>
            <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:6px 0 0 0;">
                📊 Gestione Sondaggio Disponibilità: <?php echo esc_html($event->title); ?>
            </h1>
        </header>

        <div style="display:grid; grid-template-columns: 340px 1fr; gap:24px; align-items:flex-start;">
            <!-- Configurazione Sondaggio -->
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:20px; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <h3 style="font-size:15px; font-weight:700; color:#0f172a; margin-top:0; border-bottom:1px solid #f1f5f9; padding-bottom:8px;">
                    ⚙️ Configurazione Sondaggio
                </h3>
                <form method="post" action="">
                    <?php wp_nonce_field('dfn_save_survey_action', 'dfn_survey_nonce'); ?>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Titolo Sondaggio</label>
                        <input type="text" name="title" required value="<?php echo esc_attr($survey ? $survey->title : 'Disponibilità Volontari: ' . $event->title); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Scadenza Chiusura Sondaggio <span style="color:#ef4444;">*</span></label>
                        <input type="datetime-local" name="deadline_at" required value="<?php echo esc_attr($survey ? date('Y-m-d\TH:i', strtotime($survey->deadline_at)) : date('Y-m-d\T20:00', strtotime('+7 days'))); ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px;">
                    </div>

                    <?php 
                        $now = current_time('mysql');
                        $is_time_expired = ($survey && ! empty($survey->deadline_at) && $survey->deadline_at < $now);
                        $effective_status = ($survey && ($survey->status === 'closed' || $is_time_expired)) ? 'closed' : 'open';
                    ?>

                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Stato Sondaggio</label>
                        <select name="status" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px;">
                            <option value="open" <?php selected($effective_status, 'open'); ?>>🟢 Aperto alle risposte</option>
                            <option value="closed" <?php selected($effective_status, 'closed'); ?>>🔴 Chiuso (Blocca modifiche o Scaduto)</option>
                        </select>
                        <?php if ($is_time_expired) : ?>
                            <div style="margin-top:6px; font-size:11.5px; color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:6px 8px;">
                                ⏳ <strong>Sondaggio Scaduto:</strong> la data limite è passata. I volontari non possono più inviare risposte. Per riaprirlo, sposta la data in avanti e seleziona 'Aperto'.
                            </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" name="dfn_save_survey" class="button button-primary" style="background:#004b23; border-color:#003b1c; width:100%; font-weight:700; padding:4px;">
                        💾 Salva Sondaggio
                    </button>
                </form>

                <?php if ($survey) : ?>
                    <div style="margin-top:20px; border-top:1px solid #f1f5f9; padding-top:16px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">🔗 Link Pubblico da Condividere</label>
                        <input type="text" readonly value="<?php echo esc_url($survey_link); ?>" style="width:100%; font-size:11.5px; background:#f8fafc; border-radius:4px; border:1px solid #cbd5e1; padding:6px;" onclick="this.select(); document.execCommand('copy'); alert('Link copiato negli appunti!');">
                        <p style="font-size:11px; color:#64748b; margin:4px 0 0 0;">Invia questo link ai volontari via WhatsApp o Email per compilare le loro disponibilità.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Sezione Disponibilità Volontari Divisa per Giorno e Fascia Oraria -->
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h2 style="font-size:17px; font-weight:800; color:#0f172a; margin:0;">
                        ✅ Disponibilità Registrate (<?php echo count($available_responses); ?>)
                    </h2>
                    <span style="font-size:12px; color:#64748b; background:#f1f5f9; padding:4px 10px; border-radius:12px;">
                        I 'Non Disponibili' sono stati filtrati
                    </span>
                </div>

                <?php 
                $active_days_with_shifts = 0;
                if (! empty($days)) : ?>
                    <?php foreach ($days as $day) : 
                        // Recupera tutti gli shift configurati per questo giorno
                        $shifts_in_day = $wpdb->get_results($wpdb->prepare(
                            "SELECT DISTINCT shift_label, time_start, time_end FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC",
                            $day->id
                        ));

                        // Se il giorno non ha slot orari inseriti nella matrice, non viene incluso nel sondaggio
                        if (empty($shifts_in_day)) {
                            continue;
                        }
                        $active_days_with_shifts++;
                    ?>
                        <!-- BLOCCO GIORNO EVENTO -->
                        <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; overflow:hidden; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                            <div style="padding:12px 18px; background:#004b23; color:#fff; display:flex; justify-content:space-between; align-items:center;">
                                <strong style="font-size:14px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px;">
                                    🗓️ <?php echo esc_html($day->day_label); ?>
                                </strong>
                            </div>

                            <div style="padding:16px 18px;">
                                <?php foreach ($shifts_in_day as $sh) : 
                                    $time_lbl = substr($sh->time_start, 0, 5) . ' - ' . substr($sh->time_end, 0, 5);
                                    $slot_key = sanitize_key($sh->shift_label . '_' . substr($sh->time_start, 0, 5));
                                    
                                    // Ricerca risposte per questo slot con corrispondenza flessibile
                                    $slot_resps = $grouped_responses[$day->id][$slot_key] ?? [];
                                    if (empty($slot_resps) && isset($grouped_responses[$day->id])) {
                                        // Match flessibile ignorando separatori non alfanumerici
                                        $clean_target = preg_replace('/[^a-z0-9]/', '', strtolower($sh->shift_label . substr($sh->time_start, 0, 5)));
                                        foreach ($grouped_responses[$day->id] as $resp_k => $resps) {
                                            $clean_k = preg_replace('/[^a-z0-9]/', '', strtolower($resp_k));
                                            if ($clean_k === $clean_target) {
                                                $slot_resps = $resps;
                                                break;
                                            }
                                        }
                                    }
                                    if (empty($slot_resps) && isset($grouped_responses[$day->id])) {
                                        $fallback_k = (strpos($slot_key, 'pomeriggio') !== false || strpos($slot_key, '14:') !== false || strpos($slot_key, '15:') !== false) ? 'pomeriggio' : 'mattina';
                                        $slot_resps = $grouped_responses[$day->id][$fallback_k] ?? [];
                                    }
                                ?>
                                    <!-- TABELLA SINGOLO SLOT ORARIO -->
                                    <div style="margin-bottom:20px; border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                                        <div style="padding:8px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                                            <span style="font-size:13px; font-weight:800; color:#1e293b;">
                                                ⏰ <?php echo esc_html($sh->shift_label); ?> <span style="font-weight:normal; color:#64748b; font-size:12px;">(<?php echo esc_html($time_lbl); ?>)</span>
                                            </span>
                                            <span style="font-size:11.5px; font-weight:700; color:#15803d; background:#dcfce7; border:1px solid #86efac; border-radius:12px; padding:2px 8px;">
                                                <?php echo count($slot_resps); ?> Volontari Disponibili
                                            </span>
                                        </div>

                                        <table class="wp-list-table widefat fixed striped" style="border:none;">
                                            <thead>
                                                <tr>
                                                    <th style="width:230px; font-weight:700;">Volontario</th>
                                                    <th style="width:170px; font-weight:700;">Competenze / Ruoli</th>
                                                    <th style="font-weight:700;">Note &amp; Preferenze</th>
                                                    <th style="width:130px; font-weight:700;">Inviato il</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (! empty($slot_resps)) : ?>
                                                    <?php foreach ($slot_resps as $r) : ?>
                                                        <tr>
                                                            <td>
                                                                <strong style="color:#0f172a; font-size:13px; display:block;">
                                                                    <?php echo esc_html($r->first_name . ' ' . $r->last_name); ?>
                                                                </strong>
                                                            </td>
                                                            <td>
                                                                <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                                                    <?php if (! empty($r->has_safety_course)) : ?>
                                                                        <span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:4px; font-size:10.5px; font-weight:700; padding:1px 6px;" title="Abilitato come Responsabile Sicurezza / Scuola">
                                                                            🛡️ Corso Sicurezza
                                                                        </span>
                                                                    <?php endif; ?>
                                                                    <?php if (! empty($r->is_guide)) : ?>
                                                                        <span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:4px; font-size:10.5px; font-weight:700; padding:1px 6px;" title="Abilitato come Guida Narrante">
                                                                            🗣️ Guida
                                                                        </span>
                                                                    <?php endif; ?>
                                                                    <?php if (empty($r->has_safety_course) && empty($r->is_guide)) : ?>
                                                                        <span style="background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:4px; font-size:10.5px; padding:1px 6px;">
                                                                            🏛️ Volontario
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <span style="font-size:12px; color:#334155;"><?php echo esc_html($r->notes ?: '—'); ?></span>
                                                            </td>
                                                            <td>
                                                                <span style="font-size:11px; color:#64748b;"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($r->submitted_at))); ?></span>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else : ?>
                                                    <tr>
                                                        <td colspan="4" style="padding:14px; text-align:center; color:#94a3b8; font-style:italic;">
                                                            Nessun volontario disponibile per questo turno.
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($active_days_with_shifts === 0) : ?>
                        <div style="background:#fff; padding:24px; border-radius:8px; border:1px dashed #cbd5e1; text-align:center; color:#64748b;">
                            ℹ️ Non ci sono ancora giorni con slot orari configurati nella matrice. Configura prima i turni orari per abilitare i giorni nel sondaggio.
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <div style="background:#fff; padding:24px; border-radius:8px; border:1px solid #c3c4c7; text-align:center; color:#64748b;">
                        Nessun giorno configurato per questo evento.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * ------------------------------------------------------------------------
 * 6. ESPOZIONE STAMPA / PDF SCHEDA TURNI
 * ------------------------------------------------------------------------
 */
function dfn_render_volunteer_event_print_view(int $event_id): void
{
    global $wpdb;
    $event = dfn_get_volunteer_event($event_id);
    if (! $event) {
        wp_die(__('Evento non trovato.', 'dfn-theme'));
    }

    $days = dfn_get_volunteer_event_days($event_id);

    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <title>Tabellone Turni - <?php echo esc_html($event->title); ?></title>
        <style>
            @page { size: A4 landscape; margin: 8mm; }
            * { box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
                font-size: 11.5px; 
                color: #0f172a; 
                background: #f8fafc; 
                margin: 0; 
                padding: 20px; 
            }
            .print-wrapper {
                max-width: 960px;
                margin: 0 auto;
                background: #fff;
                padding: 24px 28px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
                box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            }
            .header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #004b23; padding-bottom: 10px; }
            .header h1 { font-size: 18px; margin: 0 0 4px 0; color: #004b23; font-weight: 800; letter-spacing: 0.5px; text-transform: uppercase; }
            .header p { margin: 0; color: #475569; font-size: 13px; font-weight: 600; }
            .day-section { margin-bottom: 20px; page-break-inside: avoid; }
            .day-title { font-size: 13.5px; font-weight: 800; color: #004b23; background: #e8f5e9; padding: 6px 12px; border-radius: 4px; border-left: 4px solid #004b23; margin-bottom: 10px; }
            .turni-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
            .turni-table th, .turni-table td { border: 1px solid #cbd5e1; padding: 7px 10px; vertical-align: middle; font-size: 12px; }
            .turni-table th { background: #f1f5f9; font-weight: 700; color: #1e293b; text-align: left; }
            .role-s { font-weight: 700; color: #92400e; }
            .role-r { font-weight: 700; color: #991b1b; }
            .role-g { font-weight: 700; color: #0369a1; }
            .print-btn { 
                display: inline-flex; 
                align-items: center; 
                gap: 6px; 
                background: #004b23; 
                color: #fff; 
                border: none; 
                border-radius: 6px; 
                font-weight: 700; 
                font-size: 13px; 
                cursor: pointer; 
                margin-bottom: 15px; 
                box-shadow: 0 2px 4px rgba(0,0,0,0.1); 
            }
            .print-btn:hover { background: #003b1c; }
            .role-group-row {
                display: flex;
                align-items: baseline;
                gap: 10px;
                margin-bottom: 6px;
                line-height: 1.5;
                font-size: 12px;
            }
            .role-group-row:last-child {
                margin-bottom: 0;
            }
            .role-badge-tag {
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 3px 9px;
                border-radius: 5px;
                white-space: nowrap;
                flex-shrink: 0;
            }
            .role-vols-names {
                color: #0f172a;
                font-size: 12.5px;
                font-weight: 500;
            }
            .role-tag-guida { background: #e0f2fe; color: #0284c7; border: 1.5px solid #7dd3fc; }
            .role-tag-accoglienza { background: #dcfce7; color: #16a34a; border: 1.5px solid #86efac; }
            .role-tag-banchetto { background: #f1f5f9; color: #334155; border: 1.5px solid #94a3b8; }
            .role-tag-resp_banchetto { background: #fee2e2; color: #dc2626; border: 1.5px solid #fca5a5; }
            .role-tag-resp_scuola { background: #fef9c3; color: #ca8a04; border: 1.5px solid #fde047; }
            .role-tag-default { background: #f1f5f9; color: #334155; border: 1.5px solid #cbd5e1; }
            @media print { 
                .no-print { display: none !important; } 
                body { padding: 0; background: #fff; } 
                .print-wrapper { max-width: 100%; padding: 0; border: none; box-shadow: none; }
            }
        </style>
    </head>
    <body>
        <div class="print-wrapper">
            <div class="no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
                <a href="javascript:window.close();" style="text-decoration:none; color:#64748b; font-weight:700; font-size:13px;">← Chiudi Scheda</a>
                <button onclick="window.print();" class="print-btn">🖨️ Stampa Foglio Turni (PDF)</button>
            </div>

            <div class="header">
                <h1>FONDO PER L'AMBIENTE ITALIANO — DELEGAZIONE DI NOVARA</h1>
                <p><?php echo esc_html($event->title); ?> • Piano Assegnazione Turni &amp; Presidi</p>
            </div>

            <?php 
            // Carica tutte le mansioni registrate per risolverne etichette e stili
            $all_roles_def = function_exists('dfn_get_volunteer_roles') ? dfn_get_volunteer_roles(true) : [];
            $roles_meta = [];
            foreach ($all_roles_def as $rd) {
                $roles_meta[$rd->role_key] = $rd;
            }

            // Mappatura ruoli standard e ordine di priorità logica
            $role_order = ['guida', 'accoglienza', 'banchetto', 'resp_banchetto', 'resp_scuola'];

            foreach ($days as $day) : 
                $places = dfn_get_volunteer_event_places((int) $day->id);
                if (empty($places)) continue;

                // Recupera tutti gli shift configurati per questo giorno
                $shifts_in_day = $wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT shift_label, time_start, time_end FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC",
                    $day->id
                ));

                // Se non ci sono shift/turni generati per questo giorno, NON mostrarlo nel tabellone
                if (empty($shifts_in_day)) {
                    continue;
                }

                $is_single_place = (count($places) === 1);
            ?>
                <div class="day-section">
                    <div class="day-title">🗓️ <?php echo esc_html(strtoupper($day->day_label)); ?></div>

                    <?php if ($is_single_place) : 
                        $p = $places[0];
                    ?>
                        <!-- Tabella Compatta per Evento Locale a Singolo Luogo -->
                        <div style="margin-bottom:6px; font-size:12px; font-weight:700; color:#334155;">
                            📍 Luogo: <strong><?php echo esc_html($p->place_name); ?></strong>
                        </div>
                        <table class="turni-table">
                            <thead>
                                <tr>
                                    <th style="width: 220px;">Fascia Oraria / Turno</th>
                                    <th>Volontari Assegnati per Mansione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts_in_day as $sh) : 
                                    $time_lbl = substr($sh->time_start, 0, 5) . ' - ' . substr($sh->time_end, 0, 5);
                                    $shifts = $wpdb->get_results($wpdb->prepare(
                                        "SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE place_id = %d AND time_start = %s",
                                        $p->id,
                                        $sh->time_start
                                    ));
                                    $ass = ! empty($shifts) ? dfn_get_volunteer_shift_assignments((int) $shifts[0]->id) : [];

                                    // Raggruppa i volontari per mansione
                                    $grouped_by_role = [];
                                    foreach ($ass as $a) {
                                        $r_k = ! empty($a->role_assigned) ? $a->role_assigned : 'banchetto';
                                        $grouped_by_role[$r_k][] = $a->volunteer_id ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
                                    }

                                    // Ordina i gruppi di ruoli
                                    uksort($grouped_by_role, function($k1, $k2) use ($role_order) {
                                        $pos1 = array_search($k1, $role_order, true);
                                        $pos2 = array_search($k2, $role_order, true);
                                        $pos1 = ($pos1 === false) ? 99 : $pos1;
                                        $pos2 = ($pos2 === false) ? 99 : $pos2;
                                        return $pos1 <=> $pos2;
                                    });
                                ?>
                                    <tr>
                                        <td style="font-weight:700; color:#0f172a; background:#fafafa;">
                                            ⏰ <?php echo esc_html($sh->shift_label); ?>
                                            <div style="font-size:11px; font-weight:normal; color:#64748b; margin-top:2px;">(<?php echo esc_html($time_lbl); ?>)</div>
                                        </td>
                                        <td>
                                            <?php if (! empty($grouped_by_role)) : ?>
                                                <div style="display:flex; flex-direction:column; gap:6px;">
                                                    <?php foreach ($grouped_by_role as $r_key => $v_names) : 
                                                        $r_def = $roles_meta[$r_key] ?? null;
                                                        $r_label = $r_def ? $r_def->role_name : ucfirst(str_replace('_', ' ', $r_key));
                                                        $tag_class = 'role-tag-' . sanitize_html_class($r_key);
                                                        if (! in_array($r_key, ['guida', 'accoglienza', 'banchetto', 'resp_banchetto', 'resp_scuola'], true)) {
                                                            $tag_class = 'role-tag-default';
                                                        }
                                                    ?>
                                                        <div class="role-group-row">
                                                            <span class="role-badge-tag <?php echo esc_attr($tag_class); ?>">
                                                                <?php echo esc_html($r_label); ?> (<?php echo count($v_names); ?>)
                                                            </span>
                                                            <div class="role-vols-names">
                                                                <?php echo esc_html(implode(', ', $v_names)); ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                                <span style="color:#94a3b8; font-style:italic; font-size:11.5px;">— Nessun volontario assegnato —</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <!-- Tabella Matrice per Giornate FAI Multi-Luogo -->
                        <?php foreach ($shifts_in_day as $sh) : 
                            $time_lbl = substr($sh->time_start, 0, 5) . ' - ' . substr($sh->time_end, 0, 5);
                        ?>
                            <h4 style="margin: 10px 0 4px 0; color: #1e293b; font-size: 12px; font-weight: 800;">
                                ⏰ <?php echo esc_html(strtoupper($sh->shift_label)); ?> (<?php echo esc_html($time_lbl); ?>)
                            </h4>
                            <table class="turni-table">
                                <thead>
                                    <tr>
                                        <?php foreach ($places as $p) : ?>
                                            <th style="width: <?php echo floor(100 / max(1, count($places))); ?>%;">
                                                <?php echo esc_html($p->place_name); ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <?php foreach ($places as $p) : 
                                            $shifts = $wpdb->get_results($wpdb->prepare(
                                                "SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE place_id = %d AND time_start = %s",
                                                $p->id,
                                                $sh->time_start
                                            ));
                                            $ass = ! empty($shifts) ? dfn_get_volunteer_shift_assignments((int) $shifts[0]->id) : [];

                                            $grouped_by_role = [];
                                            foreach ($ass as $a) {
                                                $r_k = ! empty($a->role_assigned) ? $a->role_assigned : 'banchetto';
                                                $grouped_by_role[$r_k][] = $a->volunteer_id ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
                                            }

                                            uksort($grouped_by_role, function($k1, $k2) use ($role_order) {
                                                $pos1 = array_search($k1, $role_order, true);
                                                $pos2 = array_search($k2, $role_order, true);
                                                $pos1 = ($pos1 === false) ? 99 : $pos1;
                                                $pos2 = ($pos2 === false) ? 99 : $pos2;
                                                return $pos1 <=> $pos2;
                                            });
                                        ?>
                                            <td style="vertical-align: top;">
                                                <?php if (! empty($grouped_by_role)) : ?>
                                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                                        <?php foreach ($grouped_by_role as $r_key => $v_names) : 
                                                            $r_def = $roles_meta[$r_key] ?? null;
                                                            $r_label = $r_def ? $r_def->role_name : ucfirst(str_replace('_', ' ', $r_key));
                                                            $tag_class = 'role-tag-' . sanitize_html_class($r_key);
                                                            if (! in_array($r_key, ['guida', 'accoglienza', 'banchetto', 'resp_banchetto', 'resp_scuola'], true)) {
                                                                $tag_class = 'role-tag-default';
                                                            }
                                                        ?>
                                                            <div class="role-group-row" style="flex-direction: column; gap: 2px;">
                                                                <span class="role-badge-tag <?php echo esc_attr($tag_class); ?>" style="align-self: flex-start;">
                                                                    <?php echo esc_html($r_label); ?> (<?php echo count($v_names); ?>)
                                                                </span>
                                                                <div class="role-vols-names" style="padding-left: 2px;">
                                                                    <?php echo esc_html(implode(', ', $v_names)); ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else : ?>
                                                    <span style="color:#94a3b8; font-style:italic;">— Nessun volontario —</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

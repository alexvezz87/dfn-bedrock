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
        case 'print':
            dfn_render_volunteer_event_print_view($event_id);
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
                                        📅 <strong><?php echo count($days); ?></strong> giorni • 🏛️ <strong><?php echo count($places); ?></strong> luoghi aperti
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
                $saved_id = $event->id;
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
                $saved_id = $wpdb->insert_id;

                // Generazione iniziale giorni
                $cur = strtotime($date_start);
                $end = strtotime($date_end);
                $order = 1;
                while ($cur <= $end) {
                    $d_str = gmdate('Y-m-d', $cur);
                    $d_lbl = date_i18n('l d/m/Y', $cur);
                    $wpdb->insert(
                        $table_days,
                        [ 'event_id' => $saved_id, 'event_date' => $d_str, 'day_label' => $d_lbl, 'order_num' => $order ],
                        [ '%d', '%s', '%s', '%d' ]
                    );
                    $day_id = $wpdb->insert_id;

                    // Se è un evento locale, inseriamo un unico luogo (ereditato dall'evento collegato se presente)
                    if ($event_type === 'local') {
                        $place_name = 'Sede Evento';
                        $slot_start = '15:00:00';
                        $slot_end   = '18:00:00';

                        if ($linked_event_id > 0) {
                            $fe = function_exists('dfn_db_get_event') ? dfn_db_get_event($linked_event_id) : null;
                            if ($fe) {
                                if (! empty($fe->location)) {
                                    $place_name = $fe->location;
                                }
                                if (! empty($fe->event_time_start)) {
                                    $slot_start = $fe->event_time_start;
                                }
                                if (! empty($fe->event_time_end)) {
                                    $slot_end = $fe->event_time_end;
                                }
                            }
                        }

                        $wpdb->insert(
                            $table_places,
                            [ 'event_id' => $saved_id, 'day_id' => $day_id, 'place_name' => $place_name, 'order_num' => 1 ],
                            [ '%d', '%d', '%s', '%d' ]
                        );
                        $place_id = $wpdb->insert_id;

                        // Per gli eventi locali si crea un solo slot con la durata dell'evento
                        $wpdb->insert($table_shifts, [
                            'event_id'    => $saved_id,
                            'day_id'      => $day_id,
                            'place_id'    => $place_id,
                            'shift_label' => 'Turno Unico',
                            'time_start'  => $slot_start,
                            'time_end'    => $slot_end,
                            'order_num'   => 1,
                        ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]);
                    }

                    $cur = strtotime('+1 day', $cur);
                    $order++;
                }
            }

            echo '<div class="notice notice-success is-dismissible"><p>✅ Evento logistica salvato con successo!</p></div>';
            echo '<script>window.location.href="' . esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $saved_id)) . '";</script>';
            return;
        }
    }

    // Lista eventi FAI Prenotazioni futuri per associazione opzionale
    $fai_events = $wpdb->get_results(
        "SELECT e.*, p.post_title 
         FROM {$wpdb->prefix}dfn_events e
         LEFT JOIN {$wpdb->posts} p ON e.product_id = p.ID
         WHERE (e.event_date_end >= CURDATE() OR (e.event_date_end IS NULL AND e.event_date_start >= CURDATE()))
           AND e.status != 'archived'
         ORDER BY e.event_date_start ASC"
    );

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

    $days = dfn_get_volunteer_event_days($event_id);
    $selected_day_id = isset($_GET['day_id']) ? (int) $_GET['day_id'] : (! empty($days) ? (int) $days[0]->id : 0);

    // Gestione aggiunta luogo
    if (isset($_POST['dfn_add_place']) && wp_verify_nonce($_POST['dfn_place_nonce'] ?? '', 'dfn_add_place_action')) {
        $place_name = sanitize_text_field($_POST['place_name'] ?? '');
        if (! empty($place_name) && $selected_day_id > 0) {
            $table_places = $wpdb->prefix . 'dfn_volunteer_event_places';
            $table_shifts = $wpdb->prefix . 'dfn_volunteer_event_shifts';

            $wpdb->insert(
                $table_places,
                [ 'event_id' => $event_id, 'day_id' => $selected_day_id, 'place_name' => $place_name, 'order_num' => 10 ],
                [ '%d', '%d', '%s', '%d' ]
            );
            $place_id = $wpdb->insert_id;

            // Crea automaticamente i 2 turni standard per il luogo: Mattina e Pomeriggio
            $wpdb->insert($table_shifts, [ 'event_id' => $event_id, 'day_id' => $selected_day_id, 'place_id' => $place_id, 'shift_label' => 'Mattina', 'time_start' => '09:00:00', 'time_end' => '12:30:00', 'order_num' => 1 ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]);
            $wpdb->insert($table_shifts, [ 'event_id' => $event_id, 'day_id' => $selected_day_id, 'place_id' => $place_id, 'shift_label' => 'Pomeriggio', 'time_start' => '14:00:00', 'time_end' => '18:00:00', 'order_num' => 2 ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]);

            echo '<div class="notice notice-success is-dismissible"><p>✅ Luogo e turni aggiunti con successo.</p></div>';
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
        $assigned_count = dfn_run_volunteer_auto_assignment($event_id, $selected_day_id);
        echo '<div class="notice notice-success is-dismissible"><p>🤖 <strong>Assegnazione automatica completata!</strong> Assegnati ' . intval($assigned_count) . ' volontari ai turni nel rispetto dei vincoli di ruolo (Responsabili Scuola, Banchetto e Guide).</p></div>';
    }

    // Gestione Aggiunta Nuovo Slot Orario (Eventi Locali o Personalizzati)
    if (isset($_POST['dfn_add_shift']) && wp_verify_nonce($_POST['dfn_shift_nonce'] ?? '', 'dfn_add_shift_action')) {
        $shift_label = sanitize_text_field($_POST['shift_label'] ?? 'Turno');
        $time_start  = sanitize_text_field($_POST['time_start'] ?? '');
        $time_end    = sanitize_text_field($_POST['time_end'] ?? '');
        $target_place_id = (int) ($_POST['place_id'] ?? 0);

        if (! empty($time_start) && ! empty($time_end) && $target_place_id > 0 && $selected_day_id > 0) {
            $wpdb->insert(
                $wpdb->prefix . 'dfn_volunteer_event_shifts',
                [
                    'event_id'    => $event_id,
                    'day_id'      => $selected_day_id,
                    'place_id'    => $target_place_id,
                    'shift_label' => $shift_label,
                    'time_start'  => $time_start . (strlen($time_start) === 5 ? ':00' : ''),
                    'time_end'    => $time_end . (strlen($time_end) === 5 ? ':00' : ''),
                    'order_num'   => 10,
                ],
                [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
            );
            echo '<div class="notice notice-success is-dismissible"><p>✅ Nuovo slot orario aggiunto con successo!</p></div>';
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
        $new_pl_id = $wpdb->insert_id;

        $wpdb->insert(
            $wpdb->prefix . 'dfn_volunteer_event_shifts',
            [
                'event_id'    => $event_id,
                'day_id'      => $selected_day_id,
                'place_id'    => $new_pl_id,
                'shift_label' => 'Turno Unico',
                'time_start'  => $slot_start,
                'time_end'    => $slot_end,
                'order_num'   => 1,
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]
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
            <div style="display:flex; gap:10px;">
                <?php if ($event->event_type === 'giornata_fai') : ?>
                    <form method="post" action="" onsubmit="return confirm('L\'assegnazione automatica distribuirà i volontari disponibili in base al sondaggio e ai requisiti (Corso Sicurezza, Guide, Responsabili). Continuare?');">
                        <?php wp_nonce_field('dfn_auto_assign_action', 'dfn_auto_nonce'); ?>
                        <button type="submit" name="dfn_auto_assign" class="button button-primary" style="background:#2563eb; border-color:#1d4ed8; font-weight:700; padding:4px 16px;">
                            🤖 Assegna Automaticamente i Turni
                        </button>
                    </form>
                <?php endif; ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=print&event_id=' . $event_id)); ?>" target="_blank" class="button" style="font-weight:700;">
                    🖨️ Stampa / Esporta PDF
                </a>
            </div>
        </header>

        <!-- TABS GIORNI EVENTO -->
        <div style="display:flex; gap:8px; border-bottom:2px solid #cbd5e1; margin-bottom:20px;">
            <?php foreach ($days as $d) : 
                $is_active = ($d->id == $selected_day_id);
            ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d->id)); ?>" 
                   style="text-decoration:none; padding:10px 18px; font-weight:700; font-size:14px; border-radius:8px 8px 0 0; background:<?php echo $is_active ? '#004b23' : '#f1f5f9'; ?>; color:<?php echo $is_active ? '#fff' : '#475569'; ?>; border:1px solid <?php echo $is_active ? '#004b23' : '#cbd5e1'; ?>; border-bottom:none;">
                    🗓️ <?php echo esc_html($d->day_label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($event->event_type === 'giornata_fai') : ?>
            <!-- FORM AGGIUNGI NUOVO LUOGO (SOLO PER GIORNATA FAI) -->
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:13px; font-weight:700; color:#334155;">📍 Luoghi aperti per questa Giornata FAI:</span>
                <form method="post" action="" style="display:flex; gap:10px;">
                    <?php wp_nonce_field('dfn_add_place_action', 'dfn_place_nonce'); ?>
                    <input type="text" name="place_name" required placeholder="Nome luogo (es. Casa Shalom, Chiesa S. Pietro...)" style="width:320px; border-radius:6px; border:1px solid #cbd5e1; height:32px; padding:0 10px;">
                    <button type="submit" name="dfn_add_place" class="button button-secondary">➕ Aggiungi Luogo</button>
                </form>
            </div>
        <?php else : 
            $single_place = ! empty($places) ? $places[0] : null;
        ?>
            <!-- INFO BOX EVENTO LOCALE (SINGOLO LUOGO) -->
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:13px; font-weight:700; color:#166534;">📍 Luogo Evento Locale:</span>
                    <strong style="font-size:14px; color:#0f172a; margin-left:6px;"><?php echo esc_html($single_place ? $single_place->place_name : 'Sede Evento'); ?></strong>
                </div>

                <!-- Form Aggiungi Altro Slot Orario per Evento Locale -->
                <?php if ($single_place) : ?>
                    <form method="post" action="" style="display:flex; align-items:center; gap:8px;">
                        <?php wp_nonce_field('dfn_add_shift_action', 'dfn_shift_nonce'); ?>
                        <input type="hidden" name="place_id" value="<?php echo esc_attr($single_place->id); ?>">
                        <input type="text" name="shift_label" placeholder="Etichetta (es. Turno 2)" style="width:130px; border-radius:6px; border:1px solid #cbd5e1; height:30px; padding:0 8px; font-size:12px;">
                        <input type="time" name="time_start" required style="border-radius:6px; border:1px solid #cbd5e1; height:30px; padding:0 6px; font-size:12px;">
                        <span>-</span>
                        <input type="time" name="time_end" required style="border-radius:6px; border:1px solid #cbd5e1; height:30px; padding:0 6px; font-size:12px;">
                        <button type="submit" name="dfn_add_shift" class="button button-secondary button-small" style="font-weight:700;">
                            ➕ Aggiungi Fascia Oraria
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- RENDERING SLOTS ORARI E TURNI -->
        <?php 
        if (! empty($places)) : 
            // Raccogliamo tutti gli slot orari univoci definiti per i luoghi di questa giornata
            $shifts_in_day = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC, id ASC",
                $selected_day_id
            ));

            if (! empty($shifts_in_day)) :
                foreach ($shifts_in_day as $shift) :
                    $current_place = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_places WHERE id = %d", $shift->place_id));
                    $assignments   = dfn_get_volunteer_shift_assignments((int) $shift->id);
                    $del_shift_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $selected_day_id . '&delete_shift=' . $shift->id), 'dfn_del_shift_' . $shift->id);
                ?>
                    <div style="margin-bottom:24px; background:#fff; border-radius:8px; border:1px solid #cbd5e1; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                        <!-- Header Slot con orari modificabili -->
                        <div style="background:#004b23; color:#fff; padding:10px 16px; font-size:14.5px; font-weight:800; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span>⏰ <?php echo esc_html($shift->shift_label); ?> (<?php echo esc_html(substr($shift->time_start, 0, 5) . ' - ' . substr($shift->time_end, 0, 5)); ?>)</span>
                                <?php if ($event->event_type === 'giornata_fai' && $current_place) : ?>
                                    <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600;">
                                        📍 <?php echo esc_html($current_place->place_name); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Modifica rapida orari slot o rimozione -->
                            <div style="display:flex; align-items:center; gap:8px;">
                                <form method="post" action="" style="display:inline-flex; align-items:center; gap:4px;">
                                    <?php wp_nonce_field('dfn_edit_shift_action', 'dfn_edit_shift_nonce'); ?>
                                    <input type="hidden" name="shift_id" value="<?php echo esc_attr($shift->id); ?>">
                                    <input type="hidden" name="shift_label" value="<?php echo esc_attr($shift->shift_label); ?>">
                                    <input type="time" name="time_start" value="<?php echo esc_attr(substr($shift->time_start, 0, 5)); ?>" style="height:26px; font-size:11px; padding:0 4px; border-radius:4px; border:none;">
                                    <input type="time" name="time_end" value="<?php echo esc_attr(substr($shift->time_end, 0, 5)); ?>" style="height:26px; font-size:11px; padding:0 4px; border-radius:4px; border:none;">
                                    <button type="submit" name="dfn_edit_shift" class="button button-small" style="font-size:11px; height:26px; line-height:24px; padding:0 6px;">
                                        💾 Salva Orari
                                    </button>
                                </form>

                                <?php if (count($shifts_in_day) > 1) : ?>
                                    <a href="<?php echo esc_url($del_shift_url); ?>" class="button button-small" style="color:#fee2e2; background:rgba(239,68,68,0.3); border:none; height:26px; line-height:24px;" onclick="return confirm('Eliminare questo slot orario?');" title="Elimina slot">
                                        🗑️
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Corpo Slot: Assegnazioni e Aggiunta -->
                        <div style="padding:16px;">
                            <!-- Elenco Volontari Assegnati -->
                            <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:10px; margin-bottom:16px;">
                                <?php if (! empty($assignments)) : ?>
                                    <?php foreach ($assignments as $a) : 
                                        $role_tags = [
                                            'resp_scuola'   => ['(S) Resp. Scuola', '#fef3c7', '#92400e'],
                                            'resp_banchetto'=> ['(R) Resp. Banchetto', '#fee2e2', '#991b1b'],
                                            'guida'         => ['(G) Guida', '#e0f2fe', '#0369a1'],
                                            'accoglienza'   => ['Accoglienza', '#f0fdf4', '#166534'],
                                            'banchetto'     => ['Banchetto', '#f1f5f9', '#475569'],
                                        ];
                                        $rt = $role_tags[$a->role_assigned] ?? ['Volontario', '#f1f5f9', '#475569'];
                                        $v_name = $a->volunteer_id ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
                                        $del_ass_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $selected_day_id . '&remove_assignment=' . $a->id), 'dfn_del_ass_' . $a->id);
                                    ?>
                                        <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px;">
                                            <div>
                                                <strong style="font-size:13px; color:#1e293b; display:block;"><?php echo esc_html($v_name); ?></strong>
                                                <span style="display:inline-block; font-size:10.5px; font-weight:800; background:<?php echo $rt[1]; ?>; color:<?php echo $rt[2]; ?>; padding:1px 6px; border-radius:4px; margin-top:2px;">
                                                    <?php echo esc_html($rt[0]); ?>
                                                </span>
                                            </div>
                                            <a href="<?php echo esc_url($del_ass_url); ?>" style="color:#ef4444; text-decoration:none; font-size:13px; font-weight:700; padding:4px;" title="Rimuovi dal turno">✕</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <div style="grid-column: 1 / -1; color:#94a3b8; font-style:italic; padding:8px 0;">
                                        Nessun volontario assegnato a questo slot orario.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Form Assegnazione Volontario allo Slot -->
                            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px;">
                                <form method="post" action="" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <?php wp_nonce_field('dfn_assign_action', 'dfn_assign_nonce'); ?>
                                    <input type="hidden" name="shift_id" value="<?php echo esc_attr($shift->id); ?>">

                                    <div style="flex:1; min-width:220px;">
                                        <select name="volunteer_id" style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:32px;">
                                            <option value="">-- Seleziona Volontario da Assegnare --</option>
                                            <?php foreach ($all_volunteers as $av) : 
                                                $extra_label = '';
                                                if (! empty($av->has_safety_course)) $extra_label .= ' [🦺 Sicurezza]';
                                                if (! empty($av->is_guide)) $extra_label .= ' [🏛️ Guida]';
                                            ?>
                                                <option value="<?php echo esc_attr($av->id); ?>">
                                                    <?php echo esc_html($av->first_name . ' ' . $av->last_name . $extra_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div style="width:200px;">
                                        <select name="role_assigned" style="width:100%; font-size:12px; border-radius:6px; border:1px solid #cbd5e1; height:32px;">
                                            <option value="banchetto">Banchetto</option>
                                            <option value="resp_banchetto">👑 Resp. Banchetto (R)</option>
                                            <option value="resp_scuola">🦺 Resp. Scuola (S)</option>
                                            <option value="accoglienza">Accoglienza / Validatore</option>
                                            <option value="guida">🏛️ Guida</option>
                                        </select>
                                    </div>

                                    <button type="submit" name="dfn_assign_volunteer" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; height:32px;">
                                        ➕ Assegna al Turno
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach;
            else : ?>
                <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:30px; text-align:center; color:#64748b;">
                    Nessun turno orario presente. Usa il modulo in alto per aggiungere uno slot.
                </div>
            <?php endif;
        else : ?>
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; padding:40px; text-align:center; color:#64748b;">
                <p>Nessun luogo o slot configurato per questo giorno.</p>
            </div>
        <?php endif; ?>
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

    $assigned_count = 0;
    $time_slots = [
        'mattina'    => '09:00:00',
        'pomeriggio' => '14:00:00',
    ];

    foreach ($time_slots as $slot_key => $time_start) {
        // Recupera tutti gli shift aperti per questo orario
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, p.place_name FROM {$table_shifts} s
             JOIN {$table_places} p ON s.place_id = p.id
             WHERE s.day_id = %d AND s.time_start = %s",
            $day_id,
            $time_start
        ));

        if (empty($shifts)) {
            continue;
        }

        // Recupera i volontari che hanno dato disponibilità per questo giorno e slot
        $available_responses = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, f.is_guide, f.has_safety_course 
             FROM {$table_resp} r
             LEFT JOIN {$table_fai} f ON r.volunteer_id = f.id
             WHERE r.survey_id = %d AND r.day_id = %d AND r.time_slot_key = %s AND r.is_available = 1",
            $survey->id,
            $day_id,
            $slot_key
        ));

        if (empty($available_responses)) {
            continue;
        }

        // Raggruppamento per competenze
        $safety_volunteers = [];
        $guide_volunteers  = [];
        $general_volunteers= [];

        foreach ($available_responses as $resp) {
            if (! empty($resp->has_safety_course)) {
                $safety_volunteers[] = $resp;
            } elseif (! empty($resp->is_guide)) {
                $guide_volunteers[] = $resp;
            } else {
                $general_volunteers[] = $resp;
            }
        }

        // 1. Assegna 1 Responsabile Scuola (S) con corso sicurezza per ogni luogo
        foreach ($shifts as $shift) {
            // Controlla se ha già un resp_scuola
            $has_scuola = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ass} WHERE shift_id = %d AND role_assigned = 'resp_scuola'", $shift->id));
            if (! $has_scuola && ! empty($safety_volunteers)) {
                $picked = array_shift($safety_volunteers);
                $wpdb->insert($table_ass, [
                    'shift_id'              => $shift->id,
                    'volunteer_id'          => $picked->volunteer_id ?: null,
                    'volunteer_name_manual' => ! $picked->volunteer_id ? ($picked->first_name . ' ' . $picked->last_name) : null,
                    'role_assigned'         => 'resp_scuola',
                    'created_at'            => current_time('mysql'),
                ], [ '%d', '%d', '%s', '%s', '%s' ]);
                $assigned_count++;
            }
        }

        // Rimetti i restanti volontari sicurezza nella disponibilità generale
        $remaining_pool = array_merge($safety_volunteers, $guide_volunteers, $general_volunteers);

        // 2. Assegna 1 Responsabile Banchetto (R) per ogni luogo
        foreach ($shifts as $shift) {
            $has_resp_b = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ass} WHERE shift_id = %d AND role_assigned = 'resp_banchetto'", $shift->id));
            if (! $has_resp_b && ! empty($remaining_pool)) {
                $picked = array_shift($remaining_pool);
                $wpdb->insert($table_ass, [
                    'shift_id'              => $shift->id,
                    'volunteer_id'          => $picked->volunteer_id ?: null,
                    'volunteer_name_manual' => ! $picked->volunteer_id ? ($picked->first_name . ' ' . $picked->last_name) : null,
                    'role_assigned'         => 'resp_banchetto',
                    'created_at'            => current_time('mysql'),
                ], [ '%d', '%d', '%s', '%s', '%s' ]);
                $assigned_count++;
            }
        }

        // 3. Distribuzione bilanciata round-robin di tutti i restanti volontari
        $shift_index = 0;
        $num_shifts  = count($shifts);

        while (! empty($remaining_pool)) {
            $picked = array_shift($remaining_pool);
            $shift  = $shifts[$shift_index % $num_shifts];
            $role   = (! empty($picked->is_guide)) ? 'guida' : 'banchetto';

            $wpdb->insert($table_ass, [
                'shift_id'              => $shift->id,
                'volunteer_id'          => $picked->volunteer_id ?: null,
                'volunteer_name_manual' => ! $picked->volunteer_id ? ($picked->first_name . ' ' . $picked->last_name) : null,
                'role_assigned'         => $role,
                'created_at'            => current_time('mysql'),
            ], [ '%d', '%d', '%s', '%s', '%s' ]);
            $assigned_count++;

            $shift_index++;
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
            echo '<div class="notice notice-success is-dismissible"><p>✅ Impostazioni sondaggio aggiornate con successo!</p></div>';
            $survey = dfn_get_volunteer_survey_by_event($event_id);
        }
    }

    $survey_link = $survey ? home_url('/sondaggio-volontari/?token=' . $survey->token_public) : '';
    $responses = $survey ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table_resp} WHERE survey_id = %d ORDER BY submitted_at DESC", $survey->id)) : [];

    ?>
    <div class="wrap dfn-admin-wrap">
        <header class="dfn-admin-header" style="margin-bottom:24px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics')); ?>" style="text-decoration:none; color:#004b23; font-weight:700;">← Torna agli eventi</a>
            <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:6px 0 0 0;">
                📊 Gestione Sondaggio Disponibilità: <?php echo esc_html($event->title); ?>
            </h1>
        </header>

        <div style="display:grid; grid-template-columns: 380px 1fr; gap:24px; align-items:flex-start;">
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

                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Stato Sondaggio</label>
                        <select name="status" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px;">
                            <option value="open" <?php selected($survey ? $survey->status : 'open', 'open'); ?>>🟢 Aperto alle risposte</option>
                            <option value="closed" <?php selected($survey ? $survey->status : '', 'closed'); ?>>🔴 Chiuso (Blocca modifiche)</option>
                        </select>
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

            <!-- Tabella Risposte Ricevute -->
            <div style="background:#fff; border-radius:8px; border:1px solid #c3c4c7; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                <div style="padding:14px 18px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <strong style="font-size:14px; color:#0f172a;">Risposte Registrate (<?php echo count($responses); ?>)</strong>
                </div>
                <table class="wp-list-table widefat fixed striped table-view-list" style="border:none;">
                    <thead>
                        <tr>
                            <th style="width:180px; font-weight:700;">Volontario</th>
                            <th style="width:140px; font-weight:700;">Giorno &amp; Fascia</th>
                            <th style="width:110px; font-weight:700; text-align:center;">Disponibilità</th>
                            <th style="font-weight:700;">Note</th>
                            <th style="width:120px; font-weight:700;">Inviato il</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (! empty($responses)) : ?>
                            <?php foreach ($responses as $r) : 
                                $day = $wpdb->get_row($wpdb->prepare("SELECT day_label FROM {$wpdb->prefix}dfn_volunteer_event_days WHERE id = %d", $r->day_id));
                            ?>
                                <tr>
                                    <td>
                                        <strong style="color:#0f172a; font-size:13px; display:block;">
                                            <?php echo esc_html($r->first_name . ' ' . $r->last_name); ?>
                                        </strong>
                                        <?php if ($r->phone) : ?>
                                            <span style="font-size:11px; color:#64748b;">📞 <?php echo esc_html($r->phone); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-size:12px; font-weight:600; color:#334155;"><?php echo esc_html($day ? $day->day_label : 'Giorno #' . $r->day_id); ?></div>
                                        <div style="font-size:11px; color:#64748b;"><?php echo esc_html(ucfirst($r->time_slot_key)); ?></div>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if (! empty($r->is_available)) : ?>
                                            <span style="background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:10px; font-size:11px; font-weight:700; padding:2px 8px;">
                                                ✅ Disponibile
                                            </span>
                                        <?php else : ?>
                                            <span style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; font-size:11px; font-weight:700; padding:2px 8px;">
                                                ❌ No
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-size:12px; color:#475569;"><?php echo esc_html($r->notes ?: '—'); ?></span>
                                    </td>
                                    <td>
                                        <span style="font-size:11px; color:#64748b;"><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($r->submitted_at))); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="5" style="padding:24px; text-align:center; color:#64748b;">
                                    Nessuna risposta ricevuta finora. Condividi il link con i volontari!
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
            @page { size: A4 landscape; margin: 10mm; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; font-size: 11px; color: #111827; background: #fff; margin: 0; padding: 15px; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #004b23; padding-bottom: 10px; }
            .header h1 { font-size: 20px; margin: 0 0 5px 0; color: #004b23; text-transform: uppercase; }
            .header p { margin: 0; color: #4b5563; font-size: 13px; font-weight: 600; }
            .day-section { margin-bottom: 25px; page-break-inside: avoid; }
            .day-title { font-size: 15px; font-weight: 800; color: #004b23; background: #e8f5e9; padding: 6px 10px; border-left: 5px solid #004b23; margin-bottom: 10px; }
            .turni-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; table-layout: fixed; }
            .turni-table th, .turni-table td { border: 1px solid #9ca3af; padding: 6px 8px; vertical-align: top; font-size: 11px; }
            .turni-table th { background: #f3f4f6; font-weight: 700; text-align: center; color: #1f2937; }
            .role-s { font-weight: 700; color: #92400e; }
            .role-r { font-weight: 700; color: #991b1b; }
            .role-g { font-weight: 700; color: #0369a1; }
            .print-btn { display: inline-block; background: #004b23; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; cursor: pointer; margin-bottom: 15px; }
            @media print { .no-print { display: none !important; } body { padding: 0; } }
        </style>
    </head>
    <body>
        <div class="no-print" style="text-align:right;">
            <button onclick="window.print();" class="print-btn">🖨️ Stampa Foglio Turni</button>
        </div>

        <div class="header">
            <h1>FONDO PER L'AMBIENTE ITALIANO — DELEGAZIONE DI NOVARA</h1>
            <p><?php echo esc_html($event->title); ?> • Piano Assegnazione Turni &amp; Presidi</p>
        </div>

        <?php foreach ($days as $day) : 
            $places = dfn_get_volunteer_event_places((int) $day->id);
            if (empty($places)) continue;
        ?>
            <div class="day-section">
                <div class="day-title">🗓️ <?php echo esc_html(strtoupper($day->day_label)); ?></div>

                <!-- Mattina -->
                <h4 style="margin: 8px 0 4px 0; color: #374151; font-size: 12px;">⏰ MATTINA (09:00 - 12:30)</h4>
                <table class="turni-table">
                    <thead>
                        <tr>
                            <?php foreach ($places as $p) : ?>
                                <th><?php echo esc_html($p->place_name); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php foreach ($places as $p) : 
                                $shifts = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE place_id = %d AND time_start = '09:00:00'", $p->id));
                                $ass = ! empty($shifts) ? dfn_get_volunteer_shift_assignments((int) $shifts[0]->id) : [];
                            ?>
                                <td>
                                    <?php if (! empty($ass)) : ?>
                                        <?php foreach ($ass as $a) : 
                                            $v_name = $a->volunteer_id ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
                                            $role_code = '';
                                            $role_class = '';
                                            if ($a->role_assigned === 'resp_scuola') { $role_code = ' (S)'; $role_class = 'role-s'; }
                                            elseif ($a->role_assigned === 'resp_banchetto') { $role_code = ' (R)'; $role_class = 'role-r'; }
                                            elseif ($a->role_assigned === 'guida') { $role_code = ' (G)'; $role_class = 'role-g'; }
                                        ?>
                                            <div style="margin-bottom: 3px;" class="<?php echo esc_attr($role_class); ?>">
                                                • <?php echo esc_html($v_name . $role_code); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span style="color:#9ca3af; font-style:italic;">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>

                <!-- Pomeriggio -->
                <h4 style="margin: 12px 0 4px 0; color: #374151; font-size: 12px;">⏰ POMERIGGIO (14:00 - 18:00)</h4>
                <table class="turni-table">
                    <thead>
                        <tr>
                            <?php foreach ($places as $p) : ?>
                                <th><?php echo esc_html($p->place_name); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <?php foreach ($places as $p) : 
                                $shifts = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE place_id = %d AND time_start = '14:00:00'", $p->id));
                                $ass = ! empty($shifts) ? dfn_get_volunteer_shift_assignments((int) $shifts[0]->id) : [];
                            ?>
                                <td>
                                    <?php if (! empty($ass)) : ?>
                                        <?php foreach ($ass as $a) : 
                                            $v_name = $a->volunteer_id ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
                                            $role_code = '';
                                            $role_class = '';
                                            if ($a->role_assigned === 'resp_scuola') { $role_code = ' (S)'; $role_class = 'role-s'; }
                                            elseif ($a->role_assigned === 'resp_banchetto') { $role_code = ' (R)'; $role_class = 'role-r'; }
                                            elseif ($a->role_assigned === 'guida') { $role_code = ' (G)'; $role_class = 'role-g'; }
                                        ?>
                                            <div style="margin-bottom: 3px;" class="<?php echo esc_attr($role_class); ?>">
                                                • <?php echo esc_html($v_name . $role_code); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <span style="color:#9ca3af; font-style:italic;">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div style="margin-top: 20px; font-size: 10.5px; color: #4b5563; border-top: 1px solid #e5e7eb; padding-top: 8px;">
            <strong>Legenda Ruoli:</strong> 
            <span class="role-s">(S) = Responsabile Scuola (Apprendisti Ciceroni)</span> • 
            <span class="role-r">(R) = Responsabile Banchetto</span> • 
            <span class="role-g">(G) = Guida</span>
        </div>
    </body>
    </html>
    <?php
    exit;
}

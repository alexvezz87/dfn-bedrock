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

add_action('admin_init', 'dfn_handle_volunteer_event_print_intercept');

// AJAX Handlers per la matrice turni volontari
require_once ABSPATH . 'wp-admin/includes/upgrade.php';


// ========================================================================
// FUNZIONI DI VERIFICA ERRORI E CONFLITTI ORARI (OVERLAP ENGINE)
// ========================================================================

/**
 * Verifica se un volontario ha un conflitto di orario su un determinato turno.
 *
 * @param int         $target_shift_id       ID del turno a cui si vuole assegnare il volontario.
 * @param int|null    $volunteer_id          ID del volontario FAI registrato (se presente).
 * @param string      $volunteer_manual      Nome manuale del volontario (se esterno).
 * @param int         $exclude_assignment_id ID assegnazione da escludere (es. durante spostamento).
 * @return array{has_conflict: bool, message: string, conflicts: array}
 */
function dfn_check_volunteer_shift_conflict(
    int $target_shift_id,
    ?int $volunteer_id = null,
    string $volunteer_manual = '',
    int $exclude_assignment_id = 0
): array {
    global $wpdb;

    $target_shift = $wpdb->get_row($wpdb->prepare(
        "SELECT s.*, p.place_name, d.day_label, d.event_date 
         FROM {$wpdb->prefix}dfn_volunteer_event_shifts s
         LEFT JOIN {$wpdb->prefix}dfn_volunteer_event_places p ON s.place_id = p.id
         LEFT JOIN {$wpdb->prefix}dfn_volunteer_event_days d ON s.day_id = d.id
         WHERE s.id = %d",
        $target_shift_id
    ));

    if (! $target_shift) {
        return ['has_conflict' => false, 'message' => '', 'conflicts' => []];
    }

    $day_id      = (int) $target_shift->day_id;
    $time_start  = $target_shift->time_start;
    $time_end    = $target_shift->time_end;
    $table_ass   = $wpdb->prefix . 'dfn_volunteer_shift_assignments';
    $table_sh    = $wpdb->prefix . 'dfn_volunteer_event_shifts';
    $table_p     = $wpdb->prefix . 'dfn_volunteer_event_places';

    $conflicts = [];

    if (! empty($volunteer_id)) {
        $sql = "SELECT a.id as assignment_id, a.shift_id, a.role_assigned,
                       s.shift_label, s.time_start, s.time_end, s.place_id,
                       p.place_name
                FROM {$table_ass} a
                INNER JOIN {$table_sh} s ON a.shift_id = s.id
                LEFT JOIN {$table_p} p ON s.place_id = p.id
                WHERE s.day_id = %d
                  AND a.volunteer_id = %d
                  AND a.id != %d
                  AND s.time_start < %s AND s.time_end > %s";
        $conflicts = $wpdb->get_results($wpdb->prepare($sql, $day_id, $volunteer_id, $exclude_assignment_id, $time_end, $time_start));
    } elseif (! empty($volunteer_manual)) {
        $clean_manual = trim($volunteer_manual);
        $sql = "SELECT a.id as assignment_id, a.shift_id, a.role_assigned,
                       s.shift_label, s.time_start, s.time_end, s.place_id,
                       p.place_name
                FROM {$table_ass} a
                INNER JOIN {$table_sh} s ON a.shift_id = s.id
                LEFT JOIN {$table_p} p ON s.place_id = p.id
                WHERE s.day_id = %d
                  AND LOWER(TRIM(a.volunteer_name_manual)) = LOWER(%s)
                  AND a.id != %d
                  AND s.time_start < %s AND s.time_end > %s";
        $conflicts = $wpdb->get_results($wpdb->prepare($sql, $day_id, $clean_manual, $exclude_assignment_id, $time_end, $time_start));
    }

    if (! empty($conflicts)) {
        $c_details = [];
        foreach ($conflicts as $c) {
            $c_details[] = sprintf(
                '%s — %s (%s-%s)',
                $c->place_name ?: 'Altro bene',
                $c->shift_label,
                substr($c->time_start, 0, 5),
                substr($c->time_end, 0, 5)
            );
        }
        $msg = '⚠️ CONFLITTO ORARIO: Il volontario risulta già assegnato in questo orario a: ' . implode(', ', $c_details) . '.';
        return [
            'has_conflict' => true,
            'message'      => $msg,
            'conflicts'    => $conflicts,
        ];
    }

    return ['has_conflict' => false, 'message' => '', 'conflicts' => []];
}

/**
 * Recupera tutti i conflitti orari per un evento (o per un giorno specifico dell'evento).
 *
 * @param int $event_id ID dell'evento.
 * @param int $day_id   ID opzionale del giorno (0 per tutti i giorni).
 * @return array{conflicts: array, conflict_assignment_ids: array<int, array>}
 */
function dfn_get_volunteer_event_conflicts(int $event_id, int $day_id = 0): array
{
    global $wpdb;
    $table_ass = $wpdb->prefix . 'dfn_volunteer_shift_assignments';
    $table_sh  = $wpdb->prefix . 'dfn_volunteer_event_shifts';
    $table_p   = $wpdb->prefix . 'dfn_volunteer_event_places';
    $table_d   = $wpdb->prefix . 'dfn_volunteer_event_days';
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    $where_day = ($day_id > 0) ? $wpdb->prepare("AND s.day_id = %d", $day_id) : "";

    $sql = "SELECT a.id as assignment_id, a.shift_id, a.volunteer_id, a.volunteer_name_manual, a.role_assigned,
                   s.day_id, s.place_id, s.shift_label, s.time_start, s.time_end,
                   p.place_name, d.day_label, d.event_date,
                   f.first_name, f.last_name, f.phone, f.email
            FROM {$table_ass} a
            INNER JOIN {$table_sh} s ON a.shift_id = s.id
            LEFT JOIN {$table_p} p ON s.place_id = p.id
            LEFT JOIN {$table_d} d ON s.day_id = d.id
            LEFT JOIN {$table_fai} f ON a.volunteer_id = f.id
            WHERE s.event_id = %d {$where_day}
            ORDER BY s.day_id ASC, s.time_start ASC, a.id ASC";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $event_id));
    if (empty($rows)) {
        return ['conflicts' => [], 'conflict_assignment_ids' => []];
    }

    // Raggruppa per day_id + volontario
    $grouped = [];
    foreach ($rows as $r) {
        $v_key = ! empty($r->volunteer_id) 
            ? ('id_' . (int) $r->volunteer_id) 
            : ('manual_' . sanitize_title(trim((string) $r->volunteer_name_manual)));
        
        if (empty($v_key) || $v_key === 'manual_') {
            continue;
        }

        $group_key = $r->day_id . '___' . $v_key;
        $grouped[$group_key][] = $r;
    }

    $conflict_groups = [];
    $conflict_assignment_ids = [];

    foreach ($grouped as $group_key => $ass_list) {
        if (count($ass_list) < 2) {
            continue;
        }

        // Verifica sovrapposizioni tra tutte le coppie
        $colliding_ass_ids = [];

        $n = count($ass_list);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a1 = $ass_list[$i];
                $a2 = $ass_list[$j];

                // Time overlap check: start1 < end2 AND end1 > start2
                if ($a1->time_start < $a2->time_end && $a1->time_end > $a2->time_start) {
                    $colliding_ass_ids[$a1->assignment_id] = true;
                    $colliding_ass_ids[$a2->assignment_id] = true;

                    $conflict_assignment_ids[$a1->assignment_id][] = [
                        'other_assignment_id' => (int) $a2->assignment_id,
                        'other_place_name'    => $a2->place_name ?: 'Altro bene',
                        'other_shift_label'   => $a2->shift_label,
                        'other_time_start'    => substr($a2->time_start, 0, 5),
                        'other_time_end'      => substr($a2->time_end, 0, 5),
                    ];
                    $conflict_assignment_ids[$a2->assignment_id][] = [
                        'other_assignment_id' => (int) $a1->assignment_id,
                        'other_place_name'    => $a1->place_name ?: 'Altro bene',
                        'other_shift_label'   => $a1->shift_label,
                        'other_time_start'    => substr($a1->time_start, 0, 5),
                        'other_time_end'      => substr($a1->time_end, 0, 5),
                    ];
                }
            }
        }

        if (! empty($colliding_ass_ids)) {
            $first_r = $ass_list[0];
            $v_name  = ! empty($first_r->volunteer_id) 
                ? trim($first_r->first_name . ' ' . $first_r->last_name) 
                : trim($first_r->volunteer_name_manual);
            if (empty($v_name)) {
                $v_name = 'Volontario #' . $first_r->volunteer_id;
            }

            $conflicting_items = [];
            foreach ($ass_list as $a) {
                if (isset($colliding_ass_ids[$a->assignment_id])) {
                    $conflicting_items[] = [
                        'assignment_id' => (int) $a->assignment_id,
                        'shift_id'      => (int) $a->shift_id,
                        'day_id'        => (int) $a->day_id,
                        'place_id'      => (int) $a->place_id,
                        'place_name'    => $a->place_name ?: 'Sede Principale',
                        'shift_label'   => $a->shift_label,
                        'time_start'    => substr($a->time_start, 0, 5),
                        'time_end'      => substr($a->time_end, 0, 5),
                        'role_assigned' => $a->role_assigned,
                    ];
                }
            }

            $conflict_groups[] = [
                'day_id'         => (int) $first_r->day_id,
                'day_label'      => $first_r->day_label,
                'event_date'     => $first_r->event_date,
                'volunteer_id'   => (int) ($first_r->volunteer_id ?? 0),
                'volunteer_name' => $v_name,
                'is_manual'      => empty($first_r->volunteer_id),
                'phone'          => $first_r->phone ?? '',
                'email'          => $first_r->email ?? '',
                'assignments'    => $conflicting_items,
            ];
        }
    }

    return [
        'conflicts'               => $conflict_groups,
        'conflict_assignment_ids' => $conflict_assignment_ids,
    ];
}


// ========================================================================
// AJAX HANDLERS PER LA MATRICE DEI TURNI VOLONTARI
// ========================================================================

/**
 * Renderizza l'HTML di un singolo micro-chip volontario compatto.
 */
function dfn_matrix_render_chip_html(object $a, array $roles_by_key = [], array $conflict_map = []): string
{
    $r_obj   = $roles_by_key[$a->role_assigned] ?? null;
    if (! $r_obj && function_exists('dfn_get_volunteer_role_by_key')) {
        $r_obj = dfn_get_volunteer_role_by_key($a->role_assigned);
    }
    $r_color = $r_obj ? $r_obj->badge_color : '#475569';
    $r_bg    = $r_obj ? $r_obj->badge_bg : '#f1f5f9';
    $r_code  = ! empty($r_obj->badge_code) ? $r_obj->badge_code : strtoupper(substr($a->role_assigned, 0, 2));
    $r_name  = $r_obj ? $r_obj->role_name : ucfirst($a->role_assigned);

    $v_name  = ! empty($a->volunteer_id) ? ($a->first_name . ' ' . $a->last_name) : $a->volunteer_name_manual;
    $v_key   = ! empty($a->volunteer_id) ? ('id_' . $a->volunteer_id) : ('manual_' . sanitize_key($a->volunteer_name_manual));
    $v_id    = (int) ($a->volunteer_id ?? 0);
    $ass_id  = (int) $a->id;
    $sh_id   = (int) $a->shift_id;
    $safety  = ! empty($a->has_safety_course) ? 1 : 0;
    $guide   = ! empty($a->is_guide) ? 1 : 0;

    $has_conflict = isset($conflict_map[$ass_id]) && ! empty($conflict_map[$ass_id]);
    $conflict_badge = '';
    $conflict_tooltip = '';
    $chip_classes = 'dfn-matrix-chip';

    if ($has_conflict) {
        $chip_classes .= ' dfn-chip-has-conflict';
        $other_places = [];
        foreach ($conflict_map[$ass_id] as $co) {
            $other_places[] = ($co['other_place_name'] ?? 'Altro bene') . ' (' . ($co['other_shift_label'] ?? 'Turno') . ' ' . ($co['other_time_start'] ?? '') . '-' . ($co['other_time_end'] ?? '') . ')';
        }
        $conflict_tooltip = '⚠️ CONFLITTO ORARIO: Assegnato anche a ' . implode(', ', $other_places);
        $conflict_badge = '<span class="dfn-chip-badge-conflict" title="' . esc_attr($conflict_tooltip) . '">⚠️ Conflitto</span>';
    }

    $extra_icons = '';
    if ($conflict_badge) {
        $extra_icons .= $conflict_badge;
    }
    if ($safety) {
        $extra_icons .= '<span class="dfn-chip-icon" title="Ha completato il Corso Sicurezza">🦺</span>';
    }
    if ($guide) {
        $extra_icons .= '<span class="dfn-chip-icon" title="Abilitato come Guida FAI">🏛️</span>';
    }
    if (empty($a->volunteer_id)) {
        $extra_icons .= '<span class="dfn-chip-badge-manual" title="Inserimento manuale / esterno">👤 Manuale</span>';
    } elseif (empty($a->user_id) && stripos($a->volunteer_notes ?? '', 'Segnaposto') !== false) {
        $extra_icons .= '<span class="dfn-chip-badge-manual" title="Volontario segnaposto / esterno">👤 Segnaposto</span>';
    }

    $title_attr = esc_attr($v_name . ($conflict_tooltip ? (' | ' . $conflict_tooltip) : '') . ' | Clicca per dettagli / Trascina');

    ob_start();
    ?>
    <div class="<?php echo esc_attr($chip_classes); ?>"
         draggable="true"
         data-assignment-id="<?php echo esc_attr($ass_id); ?>"
         data-shift-id="<?php echo esc_attr($sh_id); ?>"
         data-volunteer-id="<?php echo esc_attr($v_id); ?>"
         data-volunteer-key="<?php echo esc_attr($v_key); ?>"
         data-volunteer-name="<?php echo esc_attr($v_name); ?>"
         data-role-key="<?php echo esc_attr($a->role_assigned); ?>"
         data-has-conflict="<?php echo $has_conflict ? '1' : '0'; ?>"
         data-conflict-info="<?php echo esc_attr($conflict_tooltip); ?>"
         title="<?php echo $title_attr; ?>">
        <div class="dfn-chip-left">
            <span class="dfn-chip-handle" title="Trascina">⠿</span>
            <span class="dfn-chip-role-pill" style="background:<?php echo esc_attr($r_bg); ?>; color:<?php echo esc_attr($r_color); ?>;" title="Ruolo: <?php echo esc_attr($r_name); ?>">
                <?php echo esc_html($r_code); ?>
            </span>
            <span class="dfn-chip-name" title="<?php echo esc_attr($v_name); ?>">
                <?php echo esc_html($v_name); ?>
            </span>
            <?php echo $extra_icons; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <div class="dfn-chip-actions">
            <button type="button" class="dfn-chip-info-btn" title="Scheda e contatti volontario" aria-label="Dettagli">ℹ️</button>
            <button type="button" class="dfn-chip-del-btn" title="Rimuovi dal turno" aria-label="Rimuovi">✕</button>
        </div>
    </div>
    <?php
    return trim(ob_get_clean());
}

// 1. Spostamento Drag & Drop tra slot
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

    $current_ass = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE id = %d",
        $assignment_id
    ));

    if (! $current_ass) {
        wp_send_json_error(['message' => 'Assegnazione non trovata.']);
    }

    $target_shift = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE id = %d",
        $target_shift_id
    ));

    if (! $target_shift) {
        wp_send_json_error(['message' => 'Slot di destinazione non trovato.']);
    }

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
        wp_send_json_error(['message' => 'Questo volontario è già presente in questo turno orario.']);
    }

    // Controllo conflitto di orario su altri turni dello stesso giorno
    $conflict_check = dfn_check_volunteer_shift_conflict(
        $target_shift_id,
        $current_ass->volunteer_id ? (int) $current_ass->volunteer_id : null,
        (string) ($current_ass->volunteer_name_manual ?? ''),
        $assignment_id
    );

    if ($conflict_check['has_conflict']) {
        wp_send_json_error([
            'message'        => $conflict_check['message'],
            'conflict_error' => true,
        ]);
    }

    $old_shift_id = (int) $current_ass->shift_id;

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

    if (function_exists('dfn_log_volunteer_shift')) {
        $v_name = ! empty($current_ass->volunteer_name_manual) ? $current_ass->volunteer_name_manual : ('Volontario ID #' . $current_ass->volunteer_id);
        dfn_log_volunteer_shift($assignment_id, 'Spostamento turno volontario', "Volontario: {$v_name} | Da Slot #{$old_shift_id} a Slot #{$target_shift_id} ({$target_shift->shift_label})");
    }

    $old_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d", $old_shift_id));
    $new_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d", $target_shift_id));

    wp_send_json_success([
        'message'        => 'Volontario spostato con successo!',
        'assignment_id'  => $assignment_id,
        'old_shift_id'   => $old_shift_id,
        'new_shift_id'   => $target_shift_id,
        'day_id'         => (int) $target_shift->day_id,
        'old_count'      => $old_count,
        'new_count'      => $new_count,
    ]);
}

// 2. Assegnazione Rapida da Modale
add_action('wp_ajax_dfn_matrix_quick_assign', 'dfn_ajax_matrix_quick_assign');
function dfn_ajax_matrix_quick_assign(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    global $wpdb;
    $shift_id   = (int) ($_POST['shift_id'] ?? 0);
    $vol_id     = ! empty($_POST['volunteer_id']) ? (int) $_POST['volunteer_id'] : null;
    $vol_manual = sanitize_text_field($_POST['volunteer_manual'] ?? '');
    $role_ass   = sanitize_text_field($_POST['role_assigned'] ?? 'banchetto');

    if ($shift_id <= 0 || (empty($vol_id) && empty($vol_manual))) {
        wp_send_json_error(['message' => 'Dati incompleti per l\'assegnazione.']);
    }

    $shift = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE id = %d", $shift_id));
    if (! $shift) {
        wp_send_json_error(['message' => 'Turno non trovato.']);
    }

    if ($vol_id) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d AND volunteer_id = %d",
            $shift_id,
            $vol_id
        ));
        if ($exists > 0) {
            wp_send_json_error(['message' => 'Questo volontario è già presente in questo turno.']);
        }
    } else {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d AND LOWER(TRIM(volunteer_name_manual)) = LOWER(TRIM(%s))",
            $shift_id,
            $vol_manual
        ));
        if ($exists > 0) {
            wp_send_json_error(['message' => 'Questo nominativo manuale è già presente in questo turno.']);
        }
    }

    // Controllo conflitto di orario su altri turni dello stesso giorno
    $conflict_check = dfn_check_volunteer_shift_conflict(
        $shift_id,
        $vol_id,
        $vol_manual,
        0
    );

    if ($conflict_check['has_conflict']) {
        wp_send_json_error([
            'message'        => $conflict_check['message'],
            'conflict_error' => true,
        ]);
    }

    $inserted = $wpdb->insert(
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

    if (! $inserted) {
        wp_send_json_error(['message' => 'Errore nel salvataggio dell\'assegnazione.']);
    }

    $new_ass_id = $wpdb->insert_id;
    if (function_exists('dfn_log_volunteer_shift')) {
        $target_vol_info = $vol_id ? ('Volontario #' . $vol_id) : $vol_manual;
        dfn_log_volunteer_shift($new_ass_id, 'Assegnazione rapida matrice', "Volontario: {$target_vol_info} | Slot #{$shift_id} ({$shift->shift_label}) | Ruolo: {$role_ass}");
    }

    $ass_row = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, f.first_name, f.last_name, f.phone, f.email, f.card_number, f.is_guide, f.has_safety_course, f.user_id, f.volunteer_notes
         FROM {$wpdb->prefix}dfn_volunteer_shift_assignments a
         LEFT JOIN {$wpdb->prefix}dfn_fai_members f ON a.volunteer_id = f.id
         WHERE a.id = %d",
        $new_ass_id
    ));

    $event_roles = function_exists('dfn_get_volunteer_event_roles') ? dfn_get_volunteer_event_roles((int) $shift->event_id) : [];
    if (empty($event_roles)) {
        $event_roles = function_exists('dfn_get_all_volunteer_roles') ? dfn_get_all_volunteer_roles() : [];
    }
    $roles_by_key = [];
    foreach ($event_roles as $er) {
        $roles_by_key[$er->role_key] = $er;
    }

    $chip_html   = dfn_matrix_render_chip_html($ass_row, $roles_by_key);
    $shift_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d", $shift_id));

    wp_send_json_success([
        'message'        => 'Volontario assegnato con successo!',
        'assignment_id'  => $new_ass_id,
        'shift_id'       => $shift_id,
        'day_id'         => (int) $shift->day_id,
        'volunteer_id'   => $vol_id,
        'volunteer_name' => $ass_row->volunteer_id ? ($ass_row->first_name . ' ' . $ass_row->last_name) : $ass_row->volunteer_name_manual,
        'chip_html'      => $chip_html,
        'shift_count'    => $shift_count,
    ]);
}

// 3. Assegnazione diretta dal Drawer Pool (Drag & Drop o Click)
add_action('wp_ajax_dfn_matrix_assign_from_pool', 'dfn_ajax_matrix_assign_from_pool');
function dfn_ajax_matrix_assign_from_pool(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    global $wpdb;
    $target_shift_id = (int) ($_POST['target_shift_id'] ?? 0);
    $volunteer_id    = (int) ($_POST['volunteer_id'] ?? 0);
    $role_assigned   = sanitize_text_field($_POST['role_assigned'] ?? '');

    if ($target_shift_id <= 0 || $volunteer_id <= 0) {
        wp_send_json_error(['message' => 'Parametri non validi.']);
    }

    $shift = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE id = %d", $target_shift_id));
    if (! $shift) {
        wp_send_json_error(['message' => 'Turno non trovato.']);
    }

    $already = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d AND volunteer_id = %d",
        $target_shift_id,
        $volunteer_id
    ));
    if ($already > 0) {
        wp_send_json_error(['message' => 'Questo volontario è già presente in questo turno.']);
    }

    // Controllo conflitto di orario su altri turni dello stesso giorno
    $conflict_check = dfn_check_volunteer_shift_conflict(
        $target_shift_id,
        $volunteer_id,
        '',
        0
    );

    if ($conflict_check['has_conflict']) {
        wp_send_json_error([
            'message'        => $conflict_check['message'],
            'conflict_error' => true,
        ]);
    }

    if (empty($role_assigned)) {
        $vol_mem = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_fai_members WHERE id = %d", $volunteer_id));
        if ($vol_mem && ! empty($vol_mem->is_guide)) {
            $role_assigned = 'guida';
        } else {
            $role_assigned = 'banchetto';
        }
    }

    $inserted = $wpdb->insert(
        $wpdb->prefix . 'dfn_volunteer_shift_assignments',
        [
            'shift_id'      => $target_shift_id,
            'volunteer_id'  => $volunteer_id,
            'role_assigned' => $role_assigned,
            'created_at'    => current_time('mysql'),
        ],
        [ '%d', '%d', '%s', '%s' ]
    );

    if (! $inserted) {
        wp_send_json_error(['message' => 'Errore nel salvataggio nel database.']);
    }

    $new_ass_id = $wpdb->insert_id;
    if (function_exists('dfn_log_volunteer_shift')) {
        dfn_log_volunteer_shift($new_ass_id, 'Assegnazione da Pool Disponibili', "Volontario #{$volunteer_id} assegnato a Slot #{$target_shift_id} ({$shift->shift_label})");
    }

    $ass_row = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, f.first_name, f.last_name, f.phone, f.email, f.card_number, f.is_guide, f.has_safety_course, f.user_id, f.volunteer_notes
         FROM {$wpdb->prefix}dfn_volunteer_shift_assignments a
         LEFT JOIN {$wpdb->prefix}dfn_fai_members f ON a.volunteer_id = f.id
         WHERE a.id = %d",
        $new_ass_id
    ));

    $event_roles = function_exists('dfn_get_volunteer_event_roles') ? dfn_get_volunteer_event_roles((int) $shift->event_id) : [];
    if (empty($event_roles)) {
        $event_roles = function_exists('dfn_get_all_volunteer_roles') ? dfn_get_all_volunteer_roles() : [];
    }
    $roles_by_key = [];
    foreach ($event_roles as $er) {
        $roles_by_key[$er->role_key] = $er;
    }

    $chip_html   = dfn_matrix_render_chip_html($ass_row, $roles_by_key);
    $shift_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d", $target_shift_id));

    wp_send_json_success([
        'message'        => 'Volontario assegnato con successo!',
        'assignment_id'  => $new_ass_id,
        'shift_id'       => $target_shift_id,
        'day_id'         => (int) $shift->day_id,
        'volunteer_id'   => $volunteer_id,
        'volunteer_name' => $ass_row->first_name . ' ' . $ass_row->last_name,
        'chip_html'      => $chip_html,
        'shift_count'    => $shift_count,
    ]);
}

// 4. Modifica Rapida Mansione Assegnata
add_action('wp_ajax_dfn_matrix_update_role', 'dfn_ajax_matrix_update_role');
function dfn_ajax_matrix_update_role(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    global $wpdb;
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
    $new_role      = sanitize_text_field($_POST['new_role'] ?? '');

    if ($assignment_id <= 0 || empty($new_role)) {
        wp_send_json_error(['message' => 'Parametri non validi.']);
    }

    $wpdb->update(
        $wpdb->prefix . 'dfn_volunteer_shift_assignments',
        [ 'role_assigned' => $new_role ],
        [ 'id' => $assignment_id ],
        [ '%s' ],
        [ '%d' ]
    );

    if (function_exists('dfn_log_volunteer_shift')) {
        dfn_log_volunteer_shift($assignment_id, 'Modifica mansione matrice', "Nuova mansione: {$new_role}");
    }

    $role_obj = function_exists('dfn_get_volunteer_role_by_key') ? dfn_get_volunteer_role_by_key($new_role) : null;
    $badge_code  = $role_obj && ! empty($role_obj->badge_code) ? $role_obj->badge_code : strtoupper(substr($new_role, 0, 2));
    $badge_color = $role_obj ? $role_obj->badge_color : '#475569';
    $badge_bg    = $role_obj ? $role_obj->badge_bg : '#f1f5f9';
    $role_name   = $role_obj ? $role_obj->role_name : ucfirst($new_role);

    wp_send_json_success([
        'message'       => 'Mansione aggiornata!',
        'assignment_id' => $assignment_id,
        'role_key'      => $new_role,
        'role_name'     => $role_name,
        'badge_code'    => $badge_code,
        'badge_color'   => $badge_color,
        'badge_bg'      => $badge_bg,
    ]);
}

// 5. Rimozione Assegnazione Volontario dal Turno
add_action('wp_ajax_dfn_matrix_remove_assignment', 'dfn_ajax_matrix_remove_assignment');
function dfn_ajax_matrix_remove_assignment(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    global $wpdb;
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

    if ($assignment_id <= 0) {
        wp_send_json_error(['message' => 'Assegnazione non valida.']);
    }

    $ass = $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, s.day_id, s.place_id, s.event_id 
         FROM {$wpdb->prefix}dfn_volunteer_shift_assignments a
         JOIN {$wpdb->prefix}dfn_volunteer_event_shifts s ON a.shift_id = s.id
         WHERE a.id = %d",
        $assignment_id
    ));

    if (! $ass) {
        wp_send_json_error(['message' => 'Assegnazione non trovata.']);
    }

    $wpdb->delete($wpdb->prefix . 'dfn_volunteer_shift_assignments', ['id' => $assignment_id], ['%d']);

    if (function_exists('dfn_log_volunteer_shift')) {
        dfn_log_volunteer_shift($assignment_id, 'Rimozione volontario matrice', "Assegnazione #{$assignment_id} rimossa");
    }

    $shift_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id = %d", $ass->shift_id));

    wp_send_json_success([
        'message'       => 'Volontario rimosso dal turno.',
        'assignment_id' => $assignment_id,
        'shift_id'      => (int) $ass->shift_id,
        'day_id'        => (int) $ass->day_id,
        'volunteer_id'  => (int) $ass->volunteer_id,
        'shift_count'   => $shift_count,
    ]);
}

// 6. Recupero Scheda Dettaglio Volontario per Modale Popup
add_action('wp_ajax_dfn_matrix_get_volunteer_detail', 'dfn_ajax_matrix_get_volunteer_detail');
function dfn_ajax_matrix_get_volunteer_detail(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    global $wpdb;
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
    $volunteer_id  = (int) ($_POST['volunteer_id'] ?? 0);
    $event_id      = (int) ($_POST['event_id'] ?? 0);

    $assignment = null;
    if ($assignment_id > 0) {
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, s.shift_label, s.time_start, s.time_end, s.day_id, s.place_id, s.event_id, p.place_name, d.day_label, d.event_date
             FROM {$wpdb->prefix}dfn_volunteer_shift_assignments a
             JOIN {$wpdb->prefix}dfn_volunteer_event_shifts s ON a.shift_id = s.id
             LEFT JOIN {$wpdb->prefix}dfn_volunteer_event_places p ON s.place_id = p.id
             LEFT JOIN {$wpdb->prefix}dfn_volunteer_event_days d ON s.day_id = d.id
             WHERE a.id = %d",
            $assignment_id
        ));
        if ($assignment) {
            $event_id = (int) $assignment->event_id;
            if (! empty($assignment->volunteer_id)) {
                $volunteer_id = (int) $assignment->volunteer_id;
            }
        }
    }

    $member = null;
    if ($volunteer_id > 0) {
        $member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_fai_members WHERE id = %d", $volunteer_id));
    }

    $survey = dfn_get_volunteer_survey_by_event($event_id);
    $survey_responses = [];
    if ($survey && $volunteer_id > 0) {
        $survey_responses = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, d.day_label, d.event_date
             FROM {$wpdb->prefix}dfn_volunteer_survey_responses r
             LEFT JOIN {$wpdb->prefix}dfn_volunteer_event_days d ON r.day_id = d.id
             WHERE r.survey_id = %d AND r.volunteer_id = %d
             ORDER BY d.event_date ASC, r.time_slot_key ASC",
            $survey->id,
            $volunteer_id
        ));
    }

    $all_shifts = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id, s.shift_label, s.time_start, s.time_end, s.day_id, s.place_id, p.place_name, d.day_label, d.event_date
         FROM {$wpdb->prefix}dfn_volunteer_event_shifts s
         LEFT JOIN {$wpdb->prefix}dfn_volunteer_event_places p ON s.place_id = p.id
         LEFT JOIN {$wpdb->prefix}dfn_volunteer_event_days d ON s.day_id = d.id
         WHERE s.event_id = %d
         ORDER BY d.event_date ASC, p.order_num ASC, s.time_start ASC",
        $event_id
    ));

    $event_roles = function_exists('dfn_get_volunteer_event_roles') ? dfn_get_volunteer_event_roles($event_id) : [];
    if (empty($event_roles)) {
        $event_roles = function_exists('dfn_get_all_volunteer_roles') ? dfn_get_all_volunteer_roles() : [];
    }

    wp_send_json_success([
        'assignment'       => $assignment,
        'member'           => $member,
        'manual_name'      => $assignment ? $assignment->volunteer_name_manual : '',
        'survey_responses' => $survey_responses,
        'shifts'           => $all_shifts,
        'roles'            => $event_roles,
    ]);
}

// 7. Modifica Orari Slot Orario via AJAX
add_action('wp_ajax_dfn_matrix_edit_shift', 'dfn_ajax_matrix_edit_shift');
function dfn_ajax_matrix_edit_shift(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    global $wpdb;
    $shift_id    = (int) ($_POST['shift_id'] ?? 0);
    $shift_label = sanitize_text_field($_POST['shift_label'] ?? '');
    $time_start  = sanitize_text_field($_POST['time_start'] ?? '');
    $time_end    = sanitize_text_field($_POST['time_end'] ?? '');

    if ($shift_id <= 0 || empty($time_start) || empty($time_end)) {
        wp_send_json_error(['message' => 'Orari non validi.']);
    }

    $wpdb->update(
        $wpdb->prefix . 'dfn_volunteer_event_shifts',
        [
            'shift_label' => $shift_label,
            'time_start'  => $time_start . (strlen($time_start) === 5 ? ':00' : ''),
            'time_end'    => $time_end . (strlen($time_end) === 5 ? ':00' : ''),
        ],
        [ 'id' => $shift_id ],
        [ '%s', '%s', '%s' ],
        [ '%d' ]
    );

    wp_send_json_success([
        'message'     => 'Orari turno aggiornati!',
        'shift_id'    => $shift_id,
        'shift_label' => $shift_label,
        'time_start'  => substr($time_start, 0, 5),
        'time_end'    => substr($time_end, 0, 5),
    ]);
}

// 8. Recupero Elenco Conflitti Orari dell'Evento via AJAX
add_action('wp_ajax_dfn_matrix_get_conflicts', 'dfn_ajax_matrix_get_conflicts');
function dfn_ajax_matrix_get_conflicts(): void
{
    check_ajax_referer('dfn_matrix_drag_drop_nonce', 'security');

    if (! current_user_can('dfn_act_fai_members') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi insufficienti.']);
    }

    $event_id = (int) ($_POST['event_id'] ?? 0);
    $day_id   = (int) ($_POST['day_id'] ?? 0);

    if ($event_id <= 0) {
        wp_send_json_error(['message' => 'Evento non valido.']);
    }

    $res = dfn_get_volunteer_event_conflicts($event_id, $day_id);
    wp_send_json_success($res);
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
    if (! current_user_can('manage_options') && ! current_user_can('dfn_act_fai_members') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_logistics'))) {
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
                        <th style="width:140px; font-weight:700;">Tipologia <?php dfn_tooltip_icon('dfn-tip-log-type', 'Informazioni: Tipologie Evento Logistica'); ?></th>
                        <th style="width:180px; font-weight:700;">Date Evento</th>
                        <th style="width:120px; font-weight:700; text-align:center;">Stato <?php dfn_tooltip_icon('dfn-tip-log-status', 'Informazioni: Stati e Flusso Logistica'); ?></th>
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

                            $now = current_time('mysql');
                            $is_survey_expired = ($survey && ! empty($survey->deadline_at) && $survey->deadline_at < $now);
                            $is_survey_closed_manually = ($survey && $survey->status === 'closed');

                            $effective_status = $ev->status;
                            if ($ev->status === 'survey_open' && ($is_survey_expired || $is_survey_closed_manually)) {
                                $effective_status = 'survey_closed';
                                // Auto-sync database event status
                                $wpdb->update($wpdb->prefix . 'dfn_volunteer_events', ['status' => 'survey_closed'], ['id' => (int) $ev->id]);
                            }
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
                                    $st = $status_labels[$effective_status] ?? [$effective_status, '#f1f5f9', '#475569', '#cbd5e1'];
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
                                        <?php if ($is_survey_expired) : ?>
                                            <div style="font-size:11px; color:#b91c1c; margin-top:2px; font-weight:600;">
                                                ⏳ Sondaggio scaduto il <?php echo esc_html(date_i18n('d/m H:i', strtotime($survey->deadline_at))); ?>
                                            </div>
                                        <?php elseif ($is_survey_closed_manually) : ?>
                                            <div style="font-size:11px; color:#64748b; margin-top:2px; font-weight:600;">
                                                🔒 Sondaggio chiuso manualmente
                                            </div>
                                        <?php else : ?>
                                            <div style="font-size:11px; color:#0369a1; margin-top:2px;">
                                                📋 Sondaggio attivo fino al <?php echo esc_html(date_i18n('d/m H:i', strtotime($survey->deadline_at))); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:flex; justify-content:flex-end; gap:6px; align-items:center;">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $ev->id)); ?>" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; font-size:12px; padding:2px 10px;">
                                            🧩 Matrice Turni
                                        </a>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=edit&event_id=' . $ev->id)); ?>" class="button" style="font-size:12px; padding:2px 8px;" title="Modifica Configurazione">
                                            ✏️
                                        </a>
                                        <?php 
                                        $del_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&delete_event=' . $ev->id), 'dfn_del_vol_event_' . $ev->id);
                                        ?>
                                        <a href="<?php echo esc_url($del_url); ?>" class="button" style="color:#b91c1c; font-size:12px; padding:2px 8px;" onclick="return confirm('Sei sicuro di voler eliminare questo evento e tutti i suoi turni e sondaggi?');" title="Elimina Evento">
                                            🗑️
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" style="padding:32px; text-align:center; color:#64748b;">
                                Nessun evento logistica creato finora. <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=new')); ?>">Crea il primo evento</a>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Overlay e Tooltip Modals Elenco Eventi Logistica -->
        <div class="dfn-tooltip-overlay" id="dfn-tooltip-overlay"></div>

        <div class="dfn-tooltip-modal" id="dfn-tip-log-type" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-log-type-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-log-type-title">🏛️ Tipologie di Evento Logistica</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Il sistema supporta due flussi logistici distinti per la gestione dei volontari:</p>
                <ul>
                    <li><strong>🏛️ Giornata FAI (Primavera, Autunno, ecc.):</strong> eventi complessi che si estendono su più giorni e su diversi luoghi/beni aperti contemporaneamente. Supportano la creazione automatica di sondaggi disponibilità per fascia oraria e la distribuzione algoritmica dei volontari per sede.</li>
                    <li><strong>📍 Evento Locale:</strong> visite guidate, conferenze o laboratori singoli. Possono essere collegati direttamente a un evento di <em>FAI Prenotazioni</em> per ereditarne data, luogo e orari dei turni.</li>
                </ul>
            </div>
        </div>

        <div class="dfn-tooltip-modal" id="dfn-tip-log-status" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-log-status-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-log-status-title">📊 Ciclo di Vita e Stati della Logistica</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <ul>
                    <li><strong>Bozza:</strong> l'evento è in fase di pianificazione oraria e scelta dei luoghi.</li>
                    <li><strong>Sondaggio Aperto:</strong> i volontari possono esprimere le proprie disponibilità nella loro area personale.</li>
                    <li><strong>Sondaggio Chiuso:</strong> le preferenze sono raccolte. Da questo momento è possibile lanciare l'<strong>Assegnazione Automatica</strong> o comporre i turni manualmente.</li>
                    <li><strong>Turni Pubblicati:</strong> i turni e i luoghi diventano visibili nella bacheca di ciascun volontario.</li>
                    <li><strong>Concluso:</strong> l'evento è terminato e archiviato.</li>
                </ul>
            </div>
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

    // Caricamento eventi FAI futuri (solo se il modulo Prenotazioni è attivo)
    $fai_events = [];
    $is_pren_active = function_exists('dfn_is_module_active') ? dfn_is_module_active('prenotazioni') : true;
    if ($is_pren_active) {
        $fai_events = $wpdb->get_results(
            "SELECT e.*, p.post_title 
             FROM {$wpdb->prefix}dfn_events e
             LEFT JOIN {$wpdb->posts} p ON e.product_id = p.ID
             WHERE (e.event_date_end >= CURDATE() OR (e.event_date_end IS NULL AND e.event_date_start >= CURDATE()))
               AND e.status != 'archived'
             ORDER BY e.event_date_start ASC"
        ) ?: [];
    }

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
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">
                            Tipologia Evento <span style="color:#ef4444;">*</span> <?php dfn_tooltip_icon('dfn-tip-vol-event-type', 'Informazioni: Tipologia Evento'); ?>
                        </label>
                        <select name="event_type" id="dfn_event_type_select" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px;" onchange="toggleLinkedEventField()">
                            <option value="giornata_fai" <?php selected($event ? $event->event_type : 'giornata_fai', 'giornata_fai'); ?>>🏛️ Giornata FAI (Multi-luogo e Sondaggio)</option>
                            <option value="local" <?php selected($event ? $event->event_type : '', 'local'); ?>>📍 Evento Locale (Visita / Evento Singolo)</option>
                        </select>
                    </div>

                    <?php if ($is_pren_active) : ?>
                    <div id="linked_event_wrapper" style="display: <?php echo ($event && $event->event_type === 'local') ? 'block' : 'none'; ?>;">
                        <label style="display:block; font-size:12.5px; font-weight:700; color:#475569; margin-bottom:4px;">
                            Associa ad Evento FAI Prenotazioni (Solo Futuri) <?php dfn_tooltip_icon('dfn-tip-vol-linked-event', 'Informazioni: Collegamento Prenotazioni'); ?>
                        </label>
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
                    <?php endif; ?>
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
                        <label style="font-size:13px; font-weight:800; color:#004b23; text-transform:uppercase;">
                            🏷️ Mansioni Volontari per questo Evento <?php dfn_tooltip_icon('dfn-tip-vol-roles-select', 'Informazioni: Selezione Mansioni'); ?>
                        </label>
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

        <!-- Overlay e Tooltip Modals Form Evento Logistica -->
        <div class="dfn-tooltip-overlay" id="dfn-tooltip-overlay"></div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-event-type" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-event-type-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-event-type-title">🏛️ Tipologia dell'Evento Logistica</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <ul>
                    <li><strong>🏛️ Giornata FAI:</strong> attiva la struttura multi-luogo e multi-giorno (es. Sabato e Domenica). Consente di configurare più beni aperti, aggiungere per ciascuno gli slot orari dedicati e inviare ai volontari il sondaggio di preferenza oraria.</li>
                    <li><strong>📍 Evento Locale:</strong> struttura snella per aperture speciali, mostre o visite guidate su un unico luogo. Può collegarsi a un evento di biglietteria.</li>
                </ul>
            </div>
        </div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-linked-event" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-linked-event-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-linked-event-title">🔗 Collegamento a FAI Prenotazioni</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Selezionando un evento futuro di <strong>FAI Prenotazioni</strong>, il modulo logistica sincronizzerà automaticamente le date, la sede dell'evento e la fascia oraria dei turni con la biglietteria pubblica.</p>
            </div>
        </div>

        <div class="dfn-tooltip-modal" id="dfn-tip-vol-roles-select" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-vol-roles-select-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-vol-roles-select-title">🏷️ Mansioni Operative dell'Evento</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Seleziona esclusivamente le mansioni necessarie per questo specifico evento. L'algoritmo di <strong>Assegnazione Automatica</strong> utilizzerà solo ed esclusivamente i ruoli qui selezionati durante la distribuzione dei volontari.</p>
                <div class="dfn-tip-box">
                    <strong>Regola di Quota:</strong> Il ruolo <em>Responsabile Banchetto</em> viene assegnato dall'algoritmo in misura di <strong>massimo 1 per turno/luogo</strong>.
                </div>
            </div>
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
    $days   = dfn_get_volunteer_event_days($event_id);
    $selected_day_id = isset($_GET['day_id']) ? (int) $_GET['day_id'] : (! empty($days) ? (int) $days[0]->id : 0);

    // Fallback POST: Gestione aggiunta luogo standard
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

            $wpdb->insert($table_shifts, [ 'event_id' => $event_id, 'day_id' => $target_day_id, 'place_id' => $place_id, 'shift_label' => 'Mattina', 'time_start' => '09:00:00', 'time_end' => '12:30:00', 'order_num' => 1 ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]);
            $wpdb->insert($table_shifts, [ 'event_id' => $event_id, 'day_id' => $target_day_id, 'place_id' => $place_id, 'shift_label' => 'Pomeriggio', 'time_start' => '14:00:00', 'time_end' => '18:00:00', 'order_num' => 2 ], [ '%d', '%d', '%d', '%s', '%s', '%s', '%d' ]);

            echo '<div class="notice notice-success is-dismissible"><p>✅ Luogo "' . esc_html($place_name) . '" e relativi turni aggiunti con successo!</p></div>';
        }
    }

    // Fallback GET: Gestione eliminazione luogo
    if (isset($_GET['delete_place'], $_GET['_wpnonce'])) {
        $del_p_id = (int) $_GET['delete_place'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_place_' . $del_p_id)) {
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_places', ['id' => $del_p_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_shifts', ['place_id' => $del_p_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Luogo rimosso.</p></div>';
        }
    }

    // Fallback POST: Gestione Assegnazione Volontario Manuale
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
            $inserted_ass_id = $wpdb->insert_id;
            if (function_exists('dfn_log_volunteer_shift')) {
                $target_vol_info = $vol_id ? ('Volontario #' . $vol_id) : $vol_manual;
                dfn_log_volunteer_shift($inserted_ass_id, 'Assegnazione manuale turno', "Volontario: {$target_vol_info} | Slot #{$shift_id} | Mansione: {$role_ass}");
            }
            echo '<div class="notice notice-success is-dismissible"><p>✅ Volontario assegnato al turno!</p></div>';
        }
    }

    // Fallback GET: Gestione Rimozione Assegnazione Volontario
    if (isset($_GET['remove_assignment'], $_GET['_wpnonce'])) {
        $ass_id = (int) $_GET['remove_assignment'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_ass_' . $ass_id)) {
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_shift_assignments', ['id' => $ass_id], ['%d']);
            if (function_exists('dfn_log_volunteer_shift')) {
                dfn_log_volunteer_shift($ass_id, 'Rimozione volontario dal turno', "Assegnazione #{$ass_id} cancellata");
            }
            echo '<div class="notice notice-success is-dismissible"><p>✅ Volontario rimosso dal turno.</p></div>';
        }
    }

    // Fallback POST: Gestione Azzeramento Completo dei Turni Assegnati
    if (isset($_POST['dfn_clear_assignments']) && wp_verify_nonce($_POST['dfn_clear_nonce'] ?? '', 'dfn_clear_assignments_action')) {
        if ($event->status === 'published') {
            echo '<div class="notice notice-error is-dismissible"><p>⚠️ <strong>Operazione non consentita:</strong> I turni dell\'evento sono attualmente <strong>pubblicati</strong>. Per sicurezza, non è possibile azzerare le assegnazioni mentre sono visibili ai volontari. Sospendi prima la pubblicazione se desideri ripulire la matrice.</p></div>';
        } else {
            $all_event_shift_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE event_id = %d", $event_id));
            if (! empty($all_event_shift_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($all_event_shift_ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id IN ($in_placeholders)", ...$all_event_shift_ids));
            }
            if (function_exists('dfn_log_volunteer_shift')) {
                dfn_log_volunteer_shift($event_id, 'Azzeramento completo turni evento', "Tutte le assegnazioni rimosse per l'evento #{$event_id}");
            }
            echo '<div class="notice notice-success is-dismissible"><p>🧹 <strong>Turni azzerati!</strong> Tutte le assegnazioni dei volontari per questo evento sono state rimosse e la board è completamente pulita.</p></div>';
        }
    }

    // Fallback POST: Gestione Algoritmo Assegnazione Automatica
    if (isset($_POST['dfn_auto_assign']) && wp_verify_nonce($_POST['dfn_auto_nonce'] ?? '', 'dfn_auto_assign_action')) {
        $now = current_time('mysql');
        $is_survey_closed = ($survey && ($survey->status === 'closed' || (! empty($survey->deadline_at) && $survey->deadline_at < $now)));

        if ($event->status === 'published') {
            echo '<div class="notice notice-error is-dismissible"><p>⚠️ <strong>Operazione non consentita:</strong> I turni dell\'evento sono attualmente <strong>pubblicati</strong>. Per sicurezza, l\'assegnazione automatica è disattivata per non sovrascrivere i turni visibili ai volontari. Sospendi prima la pubblicazione se desideri rigenerare le assegnazioni.</p></div>';
        } elseif (! $survey) {
            echo '<div class="notice notice-error is-dismissible"><p>⚠️ <strong>Nessun sondaggio trovato</strong> per questo evento. Crea prima un sondaggio per raccogliere le disponibilità.</p></div>';
        } elseif (! $is_survey_closed) {
            echo '<div class="notice notice-warning is-dismissible"><p>⚠️ <strong>Sondaggio ancora aperto:</strong> l\'assegnazione automatica può essere eseguita solo dopo la chiusura o la scadenza del sondaggio, per evitare assegnazioni parziali prima che tutti i volontari abbiano risposto.</p></div>';
        } else {
            $all_event_shift_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE event_id = %d", $event_id));
            if (! empty($all_event_shift_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($all_event_shift_ids), '%d'));
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}dfn_volunteer_shift_assignments WHERE shift_id IN ($in_placeholders)", ...$all_event_shift_ids));
            }

            $assigned_count = dfn_run_volunteer_auto_assignment($event_id, 0);
            if (function_exists('dfn_log_volunteer_shift')) {
                dfn_log_volunteer_shift($event_id, 'Esecuzione assegnazione automatica turni', "Assegnati {$assigned_count} volontari ai turni dell'evento #{$event_id}");
            }
            echo '<div class="notice notice-success is-dismissible"><p>🤖 <strong>Assegnazione automatica completata!</strong> Assegnati ' . intval($assigned_count) . ' volontari ai turni su tutti i giorni dell\'evento nel rispetto esclusivo delle sole mansioni abilitate e dei limiti di ruolo.</p></div>';
        }
    }

    // Fallback POST: Gestione Pubblicazione Turni dell'Evento
    if (isset($_POST['dfn_publish_shifts']) && wp_verify_nonce($_POST['dfn_publish_nonce'] ?? '', 'dfn_publish_shifts_action')) {
        $wpdb->update($wpdb->prefix . 'dfn_volunteer_events', [ 'status' => 'published' ], [ 'id' => $event_id ], [ '%s' ], [ '%d' ]);
        $event = dfn_get_volunteer_event($event_id);
        echo '<div class="notice notice-success is-dismissible"><p>🎉 <strong>Turni Pubblicati con successo!</strong> I volontari possono ora consultare i propri turni e la matrice generale nella loro area personale.</p></div>';
    }

    // Fallback POST: Gestione Sospensione / Ritiro Pubblicazione Turni
    if (isset($_POST['dfn_unpublish_shifts']) && wp_verify_nonce($_POST['dfn_publish_nonce'] ?? '', 'dfn_publish_shifts_action')) {
        $wpdb->update($wpdb->prefix . 'dfn_volunteer_events', [ 'status' => 'survey_closed' ], [ 'id' => $event_id ], [ '%s' ], [ '%d' ]);
        $event = dfn_get_volunteer_event($event_id);
        echo '<div class="notice notice-info is-dismissible"><p>ℹ️ <strong>Pubblicazione turni sospesa:</strong> l\'evento è tornato in stato di assegnazione turni (non visibile in area personale).</p></div>';
    }

    // Fallback GET: Eliminazione Slot Orario
    if (isset($_GET['delete_shift'], $_GET['_wpnonce'])) {
        $del_sh_id = (int) $_GET['delete_shift'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_shift_' . $del_sh_id)) {
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_event_shifts', ['id' => $del_sh_id], ['%d']);
            $wpdb->delete($wpdb->prefix . 'dfn_volunteer_shift_assignments', ['shift_id' => $del_sh_id], ['%d']);
            echo '<div class="notice notice-success is-dismissible"><p>✅ Slot orario rimosso.</p></div>';
        }
    }

    // Carica Anagrafica Volontari Attivi
    $all_volunteers = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}dfn_fai_members WHERE is_volunteer = 1 AND volunteer_status = 'active' ORDER BY first_name ASC, last_name ASC");

    // Mansioni abilitate
    $event_roles = function_exists('dfn_get_volunteer_event_roles') ? dfn_get_volunteer_event_roles($event_id) : [];
    if (empty($event_roles)) {
        $event_roles = function_exists('dfn_get_all_volunteer_roles') ? dfn_get_all_volunteer_roles() : [];
    }
    $roles_by_key = [];
    foreach ($event_roles as $er) {
        $roles_by_key[$er->role_key] = $er;
    }

    // Calcolo Conflitti Orari dell'Evento
    $event_conflicts_data  = dfn_get_volunteer_event_conflicts($event_id, 0);
    $conflict_map          = $event_conflicts_data['conflict_assignment_ids'] ?? [];
    $all_conflicts         = $event_conflicts_data['conflicts'] ?? [];
    $total_conflicts_count = count($all_conflicts);

    // Mappa risposte sondaggio
    $survey_avail_by_day = [];
    $survey_vol_details  = [];
    if ($survey) {
        $raw_resps = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, f.first_name, f.last_name, f.phone, f.email, f.card_number, f.is_guide, f.has_safety_course, f.volunteer_notes
             FROM {$wpdb->prefix}dfn_volunteer_survey_responses r
             JOIN {$wpdb->prefix}dfn_fai_members f ON r.volunteer_id = f.id
             WHERE r.survey_id = %d AND r.is_available = 1",
            $survey->id
        ));
        foreach ($raw_resps as $rr) {
            $survey_avail_by_day[$rr->day_id][$rr->volunteer_id][] = $rr;
            $survey_vol_details[$rr->volunteer_id] = $rr;
        }
    }

    // Prepara i dati per ogni giorno: Luoghi, Turni, Assegnazioni, Volontari disponibili non assegnati
    $day_data = [];
    $total_event_assignments = 0;
    $days_by_id = [];
    foreach ($days as $d_item) {
        $days_by_id[(int) $d_item->id] = $d_item;
    }

    foreach ($days as $d) {
        $d_id = (int) $d->id;
        
        if ($event->event_type === 'local') {
            $existing_p = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_places WHERE day_id = %d LIMIT 1", $d_id));
            if (! $existing_p) {
                $p_name = 'Sede Evento';
                if ($event->linked_event_id > 0) {
                    $fe = function_exists('dfn_db_get_event') ? dfn_db_get_event((int) $event->linked_event_id) : null;
                    if ($fe && ! empty($fe->location)) $p_name = $fe->location;
                }
                $wpdb->insert($wpdb->prefix . 'dfn_volunteer_event_places', [ 'event_id' => $event_id, 'day_id' => $d_id, 'place_name' => $p_name, 'order_num' => 1 ], [ '%d', '%d', '%s', '%d' ]);
            }
        }

        $places = dfn_get_volunteer_event_places($d_id);
        $places_structured = [];
        $day_assignments_count = 0;
        $day_assigned_vol_ids = [];

        foreach ($places as $p) {
            $p_shifts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE place_id = %d ORDER BY time_start ASC, id ASC",
                $p->id
            ));
            
            $shifts_structured = [];
            $place_assignments_count = 0;

            foreach ($p_shifts as $sh) {
                $ass = dfn_get_volunteer_shift_assignments((int) $sh->id);
                $shifts_structured[] = [
                    'shift'       => $sh,
                    'assignments' => $ass,
                    'count'       => count($ass),
                ];
                $place_assignments_count += count($ass);
                $day_assignments_count += count($ass);
                $total_event_assignments += count($ass);

                foreach ($ass as $a_item) {
                    if (! empty($a_item->volunteer_id)) {
                        $day_assigned_vol_ids[(int) $a_item->volunteer_id] = true;
                    }
                }
            }

            $places_structured[] = [
                'place'       => $p,
                'shifts'      => $shifts_structured,
                'total_vols'  => $place_assignments_count,
            ];
        }

        // Calcola i volontari del sondaggio per questo giorno NON ancora assegnati ad alcun turno del giorno
        $unassigned_pool = [];
        $day_survey_vols = $survey_avail_by_day[$d_id] ?? [];
        foreach ($day_survey_vols as $vol_id => $resps) {
            if (! isset($day_assigned_vol_ids[$vol_id])) {
                $v_info = $survey_vol_details[$vol_id] ?? null;
                $slots_list = [];
                $roles_pref = [];
                foreach ($resps as $resp_item) {
                    $slots_list[] = $resp_item->time_slot_key;
                    if (! empty($resp_item->preferred_role)) {
                        $roles_pref[] = $resp_item->preferred_role;
                    }
                }

                $all_avail_days = [];
                foreach ($survey_avail_by_day as $other_day_id => $other_day_vols) {
                    if (isset($other_day_vols[$vol_id]) && isset($days_by_id[$other_day_id])) {
                        $all_avail_days[] = $days_by_id[$other_day_id]->day_label ?: date_i18n('D d/m', strtotime($days_by_id[$other_day_id]->event_date));
                    }
                }

                $unassigned_pool[] = [
                    'volunteer_id'      => $vol_id,
                    'first_name'        => $v_info ? $v_info->first_name : '',
                    'last_name'         => $v_info ? $v_info->last_name : '',
                    'phone'             => $v_info ? $v_info->phone : '',
                    'email'             => $v_info ? $v_info->email : '',
                    'card_number'       => $v_info ? $v_info->card_number : '',
                    'has_safety_course' => $v_info ? (! empty($v_info->has_safety_course) ? 1 : 0) : 0,
                    'is_guide'          => $v_info ? (! empty($v_info->is_guide) ? 1 : 0) : 0,
                    'day_id'            => $d_id,
                    'day_label'         => $d->day_label,
                    'event_date'        => $d->event_date,
                    'available_slots'   => array_unique($slots_list),
                    'preferred_roles'   => array_unique($roles_pref),
                    'all_avail_days'    => array_unique($all_avail_days),
                ];
            }
        }

        $day_data[$d_id] = [
            'day'               => $d,
            'places'            => $places_structured,
            'total_assignments' => $day_assignments_count,
            'unassigned_pool'   => $unassigned_pool,
        ];
    }

    $now = current_time('mysql');
    $is_survey_closed    = ($survey && ($survey->status === 'closed' || (! empty($survey->deadline_at) && $survey->deadline_at < $now)));
    $is_survey_published = ($survey && $survey->status !== 'draft');
    $event_shift_ids     = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE event_id = %d", $event_id));

    $ajax_nonce = wp_create_nonce('dfn_matrix_drag_drop_nonce');

    // Calcolo conflitti e sovrapposizioni orarie per l'evento
    $event_conflicts_data  = dfn_get_volunteer_event_conflicts($event_id);
    $all_conflicts         = $event_conflicts_data['conflicts'];
    $conflict_map          = $event_conflicts_data['conflict_assignment_ids'];
    $total_conflicts_count = count($all_conflicts);
    ?>

    <div class="wrap dfn-admin-wrap dfn-matrix-main-wrap">
        
        <!-- HEADER PRINCIPALE MATRICE TURNI -->
        <header class="dfn-matrix-header">
            <div class="dfn-matrix-title-box">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics')); ?>" class="dfn-matrix-back-link">
                    ← Torna all'elenco eventi
                </a>
                <div class="dfn-matrix-title-row">
                    <h1 class="dfn-matrix-title">
                        📋 Matrice Turni: <?php echo esc_html($event->title); ?>
                    </h1>
                    <span class="dfn-badge-type <?php echo $event->event_type === 'giornata_fai' ? 'dfn-badge-giornate' : 'dfn-badge-locale'; ?>">
                        <?php echo $event->event_type === 'giornata_fai' ? '🏛️ Giornate FAI' : '📍 Evento Locale'; ?>
                    </span>
                    <?php if ($event->status === 'published') : ?>
                        <span class="dfn-status-pill dfn-status-published">✅ Turni Pubblicati</span>
                    <?php else : ?>
                        <span class="dfn-status-pill dfn-status-draft">📝 In Pianificazione</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dfn-matrix-actions-bar">
                <!-- 1. Gestione Pubblicazione Turni -->
                <?php if ($event->status === 'published') : ?>
                    <form method="post" action="" onsubmit="return confirm('Vuoi sospendere la visibilità dei turni in area personale?');" style="margin:0;">
                        <?php wp_nonce_field('dfn_publish_shifts_action', 'dfn_publish_nonce'); ?>
                        <button type="submit" name="dfn_unpublish_shifts" class="button dfn-btn-unpublish">
                            ⏸️ Sospendi Pubblicazione
                        </button>
                    </form>
                <?php elseif ($total_event_assignments > 0) : ?>
                    <form method="post" action="" onsubmit="return confirm('Confermi la pubblicazione dei turni? I volontari potranno visualizzare i loro turni in area personale.');" style="margin:0;">
                        <?php wp_nonce_field('dfn_publish_shifts_action', 'dfn_publish_nonce'); ?>
                        <button type="submit" name="dfn_publish_shifts" class="button button-primary dfn-btn-fai">
                            📢 Pubblica Turni
                        </button>
                    </form>
                <?php endif; ?>

                <!-- 2. Assegnazione Automatica (disponibile solo prima della pubblicazione) -->
                <?php if ($event->status !== 'published' && $survey && $is_survey_closed) : ?>
                    <form method="post" action="" onsubmit="return confirm('L\'assegnazione automatica distribuirà i volontari disponibili in base al sondaggio e alle sole mansioni abilitate. Continuare?');" style="margin:0;">
                        <?php wp_nonce_field('dfn_auto_assign_action', 'dfn_auto_nonce'); ?>
                        <button type="submit" name="dfn_auto_assign" class="button button-primary dfn-btn-fai">
                            🤖 Assegnazione Automatica
                        </button>
                    </form>
                <?php endif; ?>

                <!-- 3. Azzera Assegnazioni (disponibile solo prima della pubblicazione) -->
                <?php if ($event->status !== 'published' && $total_event_assignments > 0) : ?>
                    <form method="post" action="" onsubmit="return confirm('Sei sicuro di voler azzerare TUTTI i turni assegnati? La griglia tornerà completamente pulita per questo evento.');" style="margin:0;">
                        <?php wp_nonce_field('dfn_clear_assignments_action', 'dfn_clear_nonce'); ?>
                        <button type="submit" name="dfn_clear_assignments" class="button dfn-btn-danger">
                            🧹 Azzera Assegnazioni
                        </button>
                    </form>
                <?php endif; ?>

                <!-- 4. Risposte Sondaggio -->
                <?php if ($survey) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=survey&event_id=' . $event_id)); ?>" class="button dfn-btn-secondary">
                        📊 Risposte Sondaggio
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=survey&event_id=' . $event_id)); ?>" class="button dfn-btn-secondary" style="background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe;">
                        📋 Crea Sondaggio
                    </a>
                <?php endif; ?>

                <!-- 5. Stampa / Export PDF -->
                <?php if (! empty($event_shift_ids)) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=print&event_id=' . $event_id)); ?>" target="_blank" class="button dfn-btn-secondary">
                        🖨️ Stampa / PDF
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- BARRA NAVIGAZIONE SCHEDE GIORNALIERE (DAY SELECTOR TABS) -->
        <div class="dfn-day-tabs-wrapper">
            <div class="dfn-day-tabs" role="tablist">
                <?php foreach ($days as $idx => $d) : 
                    $d_id = (int) $d->id;
                    $is_active = ($d_id === $selected_day_id) || ($selected_day_id === 0 && $idx === 0);
                    $d_info = $day_data[$d_id] ?? null;
                    $d_ass_count = $d_info ? $d_info['total_assignments'] : 0;
                    $d_unassigned_count = $d_info ? count($d_info['unassigned_pool']) : 0;
                ?>
                    <button type="button" 
                            class="dfn-day-tab <?php echo $is_active ? 'is-active' : ''; ?>" 
                            data-day-id="<?php echo esc_attr($d_id); ?>" 
                            role="tab" 
                            aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
                        <span class="dfn-day-tab-icon">🗓️</span>
                        <span class="dfn-day-tab-title"><?php echo esc_html(date_i18n('l d F Y', strtotime($d->event_date))); ?></span>
                        <span class="dfn-day-tab-label">(<?php echo esc_html($d->day_label); ?>)</span>
                        <span class="dfn-day-tab-badge dfn-badge-assigned" id="dfn-day-tab-badge-<?php echo esc_attr($d_id); ?>" title="Volontari assegnati su questa giornata">
                            👥 <?php echo $d_ass_count; ?>
                        </span>
                        <?php if ($d_unassigned_count > 0) : ?>
                            <span class="dfn-day-tab-badge dfn-badge-pool" id="dfn-day-pool-badge-<?php echo esc_attr($d_id); ?>" title="Volontari disponibili dal sondaggio non ancora assegnati">
                                ⏳ <?php echo $d_unassigned_count; ?>
                            </span>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TOOLBAR COMPATTA MATRICE (RICERCA, FILTRI, APERTURA POOL DRAWER, AGGIUNGI LUOGO) -->
        <div class="dfn-matrix-toolbar">
            <div class="dfn-toolbar-left">
                <!-- Ricerca Istantanea Live -->
                <div class="dfn-search-box">
                    <span class="dfn-search-icon">🔍</span>
                    <input type="text" id="dfn-matrix-search-input" placeholder="Cerca volontario per nome, mansione..." autocomplete="off">
                    <button type="button" id="dfn-matrix-search-clear" title="Cancella ricerca" style="display:none;">✕</button>
                </div>

                <!-- Filtro Mansioni -->
                <div class="dfn-filter-role-box">
                    <select id="dfn-matrix-role-filter">
                        <option value="">🎭 Tutte le Mansioni</option>
                        <?php foreach ($event_roles as $er) : 
                            $b_code_raw = trim((string) ($er->badge_code ?? ''));
                            $b_code = trim($b_code_raw, " ()\t\n\r\0\x0B");
                            $r_label = preg_replace('/\s*\(+[^)]*\)+$/', '', (string) $er->role_name);
                            if (! empty($b_code)) {
                                $r_label .= ' (' . $b_code . ')';
                            }
                        ?>
                            <option value="<?php echo esc_attr($er->role_key); ?>">
                                <?php echo esc_html($r_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="dfn-toolbar-toggles">
                    <button type="button" class="button button-small" id="dfn-expand-all-btn" title="Espandi tutti i turni">🔽 Espandi tutti</button>
                    <button type="button" class="button button-small" id="dfn-collapse-all-btn" title="Comprimi tutti i turni">🔼 Comprimi tutti</button>
                </div>
            </div>

            <div class="dfn-toolbar-right">
                <!-- Pulsante Verifica Errori e Conflitti Turni -->
                <button type="button" class="button dfn-btn-conflicts <?php echo $total_conflicts_count === 0 ? 'is-clean' : ''; ?>" id="dfn-open-conflicts-modal-btn" title="Verifica sovrapposizioni orarie e conflitti tra turni dello stesso giorno">
                    <?php if ($total_conflicts_count > 0) : ?>
                        ⚠️ Conflitti (<span id="dfn-conflicts-badge" class="dfn-conflict-count-badge"><?php echo $total_conflicts_count; ?></span>)
                    <?php else : ?>
                        ✅ Nessun Conflitto (<span id="dfn-conflicts-badge" class="dfn-conflict-count-badge is-zero">0</span>)
                    <?php endif; ?>
                </button>

                <!-- Pulsante Apertura Slide-Out Drawer Volontari Disponibili -->
                <button type="button" class="button dfn-btn-pool-drawer" id="dfn-open-pool-drawer-btn">
                    👥 Pool Disponibili (<span id="dfn-drawer-pool-count">0</span>)
                </button>

                <!-- Pulsante Aggiungi Luogo (per Giornate FAI) -->
                <?php if ($event->event_type === 'giornata_fai') : ?>
                    <button type="button" class="button button-primary dfn-btn-fai" id="dfn-open-add-place-modal-btn">
                        ➕ Aggiungi Luogo
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- PANNELLI GIORNATE (UNO PER OGNI DAY_ID) -->
        <div class="dfn-matrix-panes">
            <?php foreach ($days as $idx => $d) : 
                $d_id = (int) $d->id;
                $is_active = ($d_id === $selected_day_id) || ($selected_day_id === 0 && $idx === 0);
                $d_info = $day_data[$d_id] ?? null;
                $places_list = $d_info ? $d_info['places'] : [];
            ?>
                <div class="dfn-day-tab-pane <?php echo $is_active ? 'is-active' : ''; ?>" id="dfn-day-pane-<?php echo esc_attr($d_id); ?>" data-day-id="<?php echo esc_attr($d_id); ?>">
                    
                    <?php if (! empty($places_list)) : ?>
                        <!-- GRIGLIA MULTI-COLONNA DEI LUOGHI / BENI APERTI -->
                        <div class="dfn-matrix-places-grid">
                            <?php foreach ($places_list as $plc_data) : 
                                $plc = $plc_data['place'];
                                $plc_shifts = $plc_data['shifts'];
                                $plc_vols_total = $plc_data['total_vols'];
                                $del_place_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d_id . '&delete_place=' . $plc->id), 'dfn_del_place_' . $plc->id);
                            ?>
                                <div class="dfn-place-box" id="dfn-place-box-<?php echo esc_attr($plc->id); ?>" data-place-id="<?php echo esc_attr($plc->id); ?>" data-day-id="<?php echo esc_attr($d_id); ?>">
                                    
                                    <!-- Intestazione Luogo / Bene (Badge e Azioni in alto, Titolo Completo sotto) -->
                                    <div class="dfn-place-box-header">
                                        <div class="dfn-place-header-top">
                                            <div class="dfn-place-badges-wrap">
                                                <span class="dfn-badge-counter dfn-badge-shifts" title="Turni configurati">
                                                    ⏰ <?php echo count($plc_shifts); ?> <?php echo count($plc_shifts) === 1 ? 'Turno' : 'Turni'; ?>
                                                </span>
                                                <span class="dfn-badge-counter dfn-badge-vols" id="dfn-place-vols-count-<?php echo esc_attr($plc->id); ?>" title="Volontari assegnati in questo bene">
                                                    👥 <?php echo $plc_vols_total; ?>
                                                </span>
                                            </div>

                                            <div class="dfn-place-header-actions">
                                                <button type="button" class="dfn-btn-icon dfn-add-shift-btn" data-place-id="<?php echo esc_attr($plc->id); ?>" data-day-id="<?php echo esc_attr($d_id); ?>" data-place-name="<?php echo esc_attr($plc->place_name); ?>" title="Aggiungi Turno orario a questo bene">
                                                    ➕ Turno
                                                </button>
                                                <a href="<?php echo esc_url($del_place_url); ?>" class="dfn-btn-icon dfn-btn-icon-del" onclick="return confirm('Eliminare questo luogo e tutti i relativi turni orari?');" title="Elimina Luogo">
                                                    🗑️
                                                </a>
                                            </div>
                                        </div>

                                        <div class="dfn-place-header-title-row">
                                            <span class="dfn-place-icon">📍</span>
                                            <h3 class="dfn-place-name">
                                                <?php echo esc_html($plc->place_name); ?>
                                            </h3>
                                        </div>
                                    </div>

                                    <!-- Lista Turni Orari del Luogo -->
                                    <div class="dfn-place-box-shifts">
                                        <?php if (! empty($plc_shifts)) : ?>
                                            <?php foreach ($plc_shifts as $sh_data) : 
                                                $shift = $sh_data['shift'];
                                                $assignments = $sh_data['assignments'];
                                                $del_shift_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id . '&day_id=' . $d_id . '&delete_shift=' . $shift->id), 'dfn_del_shift_' . $shift->id);
                                            ?>
                                                <div class="dfn-shift-card" id="dfn-shift-card-<?php echo esc_attr($shift->id); ?>" data-shift-id="<?php echo esc_attr($shift->id); ?>" data-place-id="<?php echo esc_attr($plc->id); ?>" data-day-id="<?php echo esc_attr($d_id); ?>">
                                                    
                                                    <!-- Header Turno -->
                                                    <div class="dfn-shift-card-header">
                                                        <div class="dfn-shift-time-label">
                                                            <span class="dfn-shift-clock-icon">⏰</span>
                                                            <strong class="dfn-shift-label-txt"><?php echo esc_html($shift->shift_label); ?></strong>
                                                            <span class="dfn-shift-time-txt">(<?php echo esc_html(substr($shift->time_start, 0, 5) . ' - ' . substr($shift->time_end, 0, 5)); ?>)</span>
                                                            <span class="dfn-shift-count-badge" id="dfn-shift-count-badge-<?php echo esc_attr($shift->id); ?>">
                                                                👥 <?php echo count($assignments); ?>
                                                            </span>
                                                        </div>

                                                        <div class="dfn-shift-card-actions">
                                                            <button type="button" 
                                                                    class="dfn-btn-quick-assign" 
                                                                    data-shift-id="<?php echo esc_attr($shift->id); ?>" 
                                                                    data-place-id="<?php echo esc_attr($plc->id); ?>" 
                                                                    data-place-name="<?php echo esc_attr($plc->place_name); ?>" 
                                                                    data-shift-label="<?php echo esc_attr($shift->shift_label); ?> (<?php echo esc_attr(substr($shift->time_start, 0, 5) . '-' . substr($shift->time_end, 0, 5)); ?>)" 
                                                                    data-day-id="<?php echo esc_attr($d_id); ?>" 
                                                                    title="Assegna un volontario a questo turno">
                                                                ➕ Assegna
                                                            </button>
                                                            <button type="button" 
                                                                    class="dfn-btn-edit-shift" 
                                                                    data-shift-id="<?php echo esc_attr($shift->id); ?>" 
                                                                    data-shift-label="<?php echo esc_attr($shift->shift_label); ?>" 
                                                                    data-time-start="<?php echo esc_attr(substr($shift->time_start, 0, 5)); ?>" 
                                                                    data-time-end="<?php echo esc_attr(substr($shift->time_end, 0, 5)); ?>" 
                                                                    title="Modifica orari turno">
                                                                ✏️
                                                            </button>
                                                            <a href="<?php echo esc_url($del_shift_url); ?>" class="dfn-btn-icon-del-shift" onclick="return confirm('Eliminare questo turno orario?');" title="Elimina turno">
                                                                🗑️
                                                            </a>
                                                        </div>
                                                    </div>

                                                    <!-- Dropzone Turno (Elenco Micro-Chip Volontari) -->
                                                    <div class="dfn-shift-dropzone" data-shift-id="<?php echo esc_attr($shift->id); ?>" data-day-id="<?php echo esc_attr($d_id); ?>" data-place-id="<?php echo esc_attr($plc->id); ?>">
                                                        <?php if (! empty($assignments)) : ?>
                                                            <?php foreach ($assignments as $a) : ?>
                                                                <?php echo dfn_matrix_render_chip_html($a, $roles_by_key, $conflict_map); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                                            <?php endforeach; ?>
                                                        <?php else : ?>
                                                            <div class="dfn-dropzone-empty-msg">
                                                                Nessun volontario assegnato. Trascina qui o clicca "+ Assegna".
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <div class="dfn-place-no-shifts">
                                                Nessun turno orario presente. Clicca <strong>"+ Turno"</strong> in alto per aggiungere Mattina o Pomeriggio.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="dfn-empty-day-box">
                            <span style="font-size:32px;">🏛️</span>
                            <h3>Nessun luogo/bene configurato per questa giornata</h3>
                            <p>Usa il pulsante <strong>"➕ Aggiungi Luogo"</strong> nella barra degli strumenti in alto per inserire il primo luogo aperto (es. <em>Duomo di Novara</em>).</p>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        </div>

        <!-- ====================================================================
             SLIDE-OUT DRAWER: POOL VOLONTARI DISPONIBILI NON ASSEGNATI
             ==================================================================== -->
        <div class="dfn-drawer-overlay" id="dfn-drawer-overlay"></div>
        <aside class="dfn-matrix-drawer" id="dfn-matrix-unassigned-drawer" aria-hidden="true">
            <div class="dfn-drawer-header">
                <div class="dfn-drawer-title-row">
                    <h3 class="dfn-drawer-title">
                        👥 Pool Volontari Disponibili
                    </h3>
                    <button type="button" class="dfn-drawer-close-btn" id="dfn-close-pool-drawer-btn" aria-label="Chiudi Drawer">✕</button>
                </div>

                <!-- Selettore / Banner Giorno Attivo nel Drawer -->
                <div class="dfn-drawer-day-selector" id="dfn-drawer-day-selector"></div>

                <p class="dfn-drawer-subtitle" id="dfn-drawer-subtitle-txt">
                    Volontari disponibili nel sondaggio non ancora assegnati. <em>Trascinali direttamente su qualsiasi turno!</em>
                </p>
                <div class="dfn-drawer-search-wrap">
                    <input type="text" id="dfn-drawer-search-input" placeholder="Filtra volontari per nome..." autocomplete="off">
                </div>
            </div>

            <div class="dfn-drawer-body" id="dfn-drawer-pool-items-container">
                <!-- Popolato dinamicamente da JavaScript in base al giorno attivo -->
            </div>
        </aside>

        <!-- ====================================================================
             MODALE 1: SCHEDA DETTAGLIO VOLONTARIO (DOSSIER & AZIONI RAPIDE)
             ==================================================================== -->
        <div class="dfn-modal-backdrop" id="dfn-modal-volunteer-detail-backdrop">
            <div class="dfn-modal-window dfn-modal-dossier" role="dialog" aria-modal="true" aria-labelledby="dfn-dossier-name">
                <div class="dfn-modal-header">
                    <div class="dfn-dossier-header-left">
                        <div class="dfn-dossier-avatar" id="dfn-dossier-avatar">AB</div>
                        <div>
                            <h3 class="dfn-modal-title" id="dfn-dossier-name">Nome Volontario</h3>
                            <div class="dfn-dossier-meta-tags" id="dfn-dossier-meta-tags"></div>
                        </div>
                    </div>
                    <button type="button" class="dfn-modal-close-btn" data-close-modal="dfn-modal-volunteer-detail-backdrop">✕</button>
                </div>

                <div class="dfn-modal-body">
                    <!-- Informazioni Tesseramento e Competenze -->
                    <div class="dfn-dossier-section">
                        <h4 class="dfn-dossier-section-title">🎫 Anagrafica FAI & Competenze</h4>
                        <div class="dfn-dossier-info-grid">
                            <div class="dfn-info-item">
                                <span class="dfn-info-label">Tessera FAI:</span>
                                <strong class="dfn-info-val" id="dfn-dossier-card-num">--</strong>
                            </div>
                            <div class="dfn-info-item">
                                <span class="dfn-info-label">Corso Sicurezza:</span>
                                <strong class="dfn-info-val" id="dfn-dossier-safety-val">--</strong>
                            </div>
                            <div class="dfn-info-item">
                                <span class="dfn-info-label">Abilitazione Guida:</span>
                                <strong class="dfn-info-val" id="dfn-dossier-guide-val">--</strong>
                            </div>
                        </div>
                        <div class="dfn-dossier-notes-box" id="dfn-dossier-notes-box" style="display:none;">
                            <strong>Note volontario:</strong> <span id="dfn-dossier-notes-txt"></span>
                        </div>
                    </div>

                    <!-- Risposte al Sondaggio per questo Evento -->
                    <div class="dfn-dossier-section">
                        <h4 class="dfn-dossier-section-title">📋 Disponibilità Dichiarata nel Sondaggio</h4>
                        <div class="dfn-survey-responses-list" id="dfn-dossier-survey-list">
                            <em>Nessuna risposta disponibile nel sondaggio per questo evento.</em>
                        </div>
                    </div>

                    <!-- Gestione Assegnazione Turno Corrente -->
                    <div class="dfn-dossier-section dfn-dossier-actions-section">
                        <h4 class="dfn-dossier-section-title">⚙️ Gestione Turno & Mansione</h4>
                        
                        <div class="dfn-dossier-assignment-tools">
                            <!-- Cambio Mansione -->
                            <div class="dfn-tool-group">
                                <label for="dfn-dossier-role-select">Cambia Mansione nel Turno:</label>
                                <div class="dfn-inline-input-action">
                                    <select id="dfn-dossier-role-select" class="dfn-select-control"></select>
                                    <button type="button" class="button button-primary dfn-btn-fai" id="dfn-dossier-save-role-btn">Salva Mansione</button>
                                </div>
                            </div>

                            <!-- Spostamento in Altro Turno -->
                            <div class="dfn-tool-group">
                                <label for="dfn-dossier-shift-select">Sposta in un Altro Turno / Luogo:</label>
                                <div class="dfn-inline-input-action">
                                    <select id="dfn-dossier-shift-select" class="dfn-select-control"></select>
                                    <button type="button" class="button button-primary dfn-btn-fai" id="dfn-dossier-move-shift-btn">Sposta</button>
                                </div>
                            </div>
                        </div>

                        <div class="dfn-dossier-del-assignment-wrap">
                            <button type="button" class="button dfn-btn-remove-ass" id="dfn-dossier-del-ass-btn">
                                🗑️ Rimuovi Volontario da Questo Turno
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ====================================================================
             MODALE 2: ASSEGNAZIONE RAPIDA VOLONTARIO (QUICK ASSIGN)
             ==================================================================== -->
        <div class="dfn-modal-backdrop" id="dfn-modal-quick-assign-backdrop">
            <div class="dfn-modal-window dfn-modal-quick-assign" role="dialog" aria-modal="true" aria-labelledby="dfn-qa-title">
                <div class="dfn-modal-header">
                    <div>
                        <h3 class="dfn-modal-title" id="dfn-qa-title">➕ Assegna Volontario al Turno</h3>
                        <p class="dfn-modal-subtitle" id="dfn-qa-subtitle">Luogo - Turno</p>
                    </div>
                    <button type="button" class="dfn-modal-close-btn" data-close-modal="dfn-modal-quick-assign-backdrop">✕</button>
                </div>

                <div class="dfn-modal-body">
                    <form id="dfn-quick-assign-form">
                        <input type="hidden" id="dfn-qa-shift-id" value="0">
                        <input type="hidden" id="dfn-qa-day-id" value="0">
                        <input type="hidden" id="dfn-qa-selected-vol-id" value="">

                        <!-- Tab Scelta: Volontario Registrato vs Nome Manuale -->
                        <div class="dfn-qa-mode-switch">
                            <label class="dfn-qa-radio-label">
                                <input type="radio" name="qa_mode" value="registered" checked id="dfn-qa-mode-registered">
                                <span>👥 Volontario Registrato</span>
                            </label>
                            <label class="dfn-qa-radio-label">
                                <input type="radio" name="qa_mode" value="manual" id="dfn-qa-mode-manual">
                                <span>✍️ Nome Esterno / Manuale</span>
                            </label>
                        </div>

                        <!-- Riga Compatta: Input Cerca/Manuale affiancato alla Select Mansione in Grid 2 Colonne -->
                        <div class="dfn-qa-compact-grid">
                            <!-- Colonna Sinistra: Cerca Volontario oppure Seleziona dalla Lista Completa -->
                            <div class="dfn-qa-col-input">
                                <div id="dfn-qa-registered-section">
                                    <div class="dfn-qa-field-block">
                                        <label for="dfn-qa-vol-search" class="dfn-form-label">🔍 Cerca Volontario:</label>
                                        <div class="dfn-autocomplete-wrapper">
                                            <input type="text" id="dfn-qa-vol-search" class="dfn-input-control" placeholder="Digita nome o cognome..." autocomplete="off">
                                            <div class="dfn-autocomplete-results" id="dfn-qa-vol-results" style="display:none;"></div>
                                        </div>
                                    </div>

                                    <div class="dfn-qa-field-block" style="margin-top: 8px;">
                                        <label for="dfn-qa-vol-select" class="dfn-form-label">📋 Oppure scegli dalla lista:</label>
                                        <select id="dfn-qa-vol-select" class="dfn-select-control">
                                            <option value="">-- Seleziona dalla lista completa --</option>
                                            <?php foreach ($all_volunteers as $v) : 
                                                $v_last  = trim((string)$v->last_name);
                                                $v_first = trim((string)$v->first_name);
                                                $v_name  = trim($v_last . ' ' . $v_first);
                                                if (empty($v_name)) $v_name = $v->email;
                                                $icons = '';
                                                if (! empty($v->has_safety_course)) $icons .= ' 🦺';
                                                if (! empty($v->is_guide)) $icons .= ' 🏛️';
                                            ?>
                                                <option value="<?php echo esc_attr($v->id); ?>" data-name="<?php echo esc_attr($v_name); ?>">
                                                    <?php echo esc_html($v_name . $icons); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="dfn-qa-selected-badge" id="dfn-qa-selected-badge" style="display:none; margin-top: 8px;">
                                        <span id="dfn-qa-selected-name"></span>
                                        <button type="button" id="dfn-qa-clear-selected-btn" title="Rimuovi selezione">✕</button>
                                    </div>
                                </div>

                                <div id="dfn-qa-manual-section" style="display:none;">
                                    <label for="dfn-qa-manual-name" class="dfn-form-label">Nome Manuale:</label>
                                    <input type="text" id="dfn-qa-manual-name" class="dfn-input-control" placeholder="Es. Mario Rossi (Esterno)">
                                </div>
                            </div>

                            <!-- Colonna Destra: Mansione da Assegnare -->
                            <div class="dfn-qa-col-role">
                                <label for="dfn-qa-role-select" class="dfn-form-label">Mansione:</label>
                                <select id="dfn-qa-role-select" class="dfn-select-control">
                                    <?php foreach ($event_roles as $er) : 
                                        $b_code_raw = trim((string) ($er->badge_code ?? ''));
                                        $b_code = trim($b_code_raw, " ()\t\n\r\0\x0B");
                                        $r_label = preg_replace('/\s*\(+[^)]*\)+$/', '', (string) $er->role_name);
                                        if (! empty($b_code)) {
                                            $r_label .= ' (' . $b_code . ')';
                                        }
                                    ?>
                                        <option value="<?php echo esc_attr($er->role_key); ?>">
                                            <?php echo esc_html($r_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="dfn-modal-footer">
                            <button type="button" class="button" data-close-modal="dfn-modal-quick-assign-backdrop">Annulla</button>
                            <button type="submit" class="button button-primary dfn-btn-fai" id="dfn-qa-submit-btn">➕ Assegna al Turno</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ====================================================================
             MODALE 3: AGGIUNGI LUOGO / BENE APERTO
             ==================================================================== -->
        <div class="dfn-modal-backdrop" id="dfn-modal-add-place-backdrop">
            <div class="dfn-modal-window" style="max-width:440px;" role="dialog" aria-modal="true" aria-labelledby="dfn-ap-title">
                <div class="dfn-modal-header">
                    <h3 class="dfn-modal-title" id="dfn-ap-title">🏛️ Aggiungi Luogo / Bene Aperto</h3>
                    <button type="button" class="dfn-modal-close-btn" data-close-modal="dfn-modal-add-place-backdrop">✕</button>
                </div>
                <div class="dfn-modal-body">
                    <form method="post" action="" id="dfn-add-place-form">
                        <?php wp_nonce_field('dfn_place_action', 'dfn_place_nonce'); ?>
                        <input type="hidden" name="day_id" id="dfn-ap-day-id" value="<?php echo esc_attr($selected_day_id); ?>">
                        <div style="margin-bottom:14px;">
                            <label class="dfn-form-label" for="dfn-ap-name">Nome del Luogo / Bene:</label>
                            <input type="text" name="place_name" id="dfn-ap-name" class="dfn-input-control" required placeholder="Es. Broletto di Novara, Palazzo Tornielli...">
                        </div>
                        <p style="font-size:11.5px; color:#64748b; margin-bottom:16px;">
                            ℹ️ Verranno generati automaticamente i 2 turni standard per il luogo (<em>Mattina 09:00-12:30</em> e <em>Pomeriggio 14:00-18:00</em>).
                        </p>
                        <div class="dfn-modal-footer">
                            <button type="button" class="button" data-close-modal="dfn-modal-add-place-backdrop">Annulla</button>
                            <button type="submit" name="dfn_add_place" class="button button-primary dfn-btn-fai">➕ Crea Luogo</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ====================================================================
             MODALE 4: AGGIUNGI / MODIFICA TURNO ORARIO (RIGA SINGOLA COMPATTA)
             ==================================================================== -->
        <div class="dfn-modal-backdrop" id="dfn-modal-shift-editor-backdrop">
            <div class="dfn-modal-window" style="max-width:480px;" role="dialog" aria-modal="true" aria-labelledby="dfn-se-title">
                <div class="dfn-modal-header">
                    <h3 class="dfn-modal-title" id="dfn-se-title">⏰ Modifica Turno Orario</h3>
                    <button type="button" class="dfn-modal-close-btn" data-close-modal="dfn-modal-shift-editor-backdrop">✕</button>
                </div>
                <div class="dfn-modal-body">
                    <form id="dfn-shift-editor-form">
                        <input type="hidden" id="dfn-se-shift-id" value="0">
                        <input type="hidden" id="dfn-se-place-id" value="0">
                        <input type="hidden" id="dfn-se-day-id" value="0">
                        <input type="hidden" id="dfn-se-mode" value="edit">

                        <!-- Input su un'unica riga compatta -->
                        <div class="dfn-shift-inputs-row">
                            <div class="dfn-shift-input-col dfn-shift-col-label">
                                <label class="dfn-form-label" for="dfn-se-label">Etichetta Turno:</label>
                                <input type="text" id="dfn-se-label" class="dfn-input-control" required placeholder="Es. Mattina, Pomeriggio">
                            </div>
                            <div class="dfn-shift-input-col dfn-shift-col-time">
                                <label class="dfn-form-label" for="dfn-se-start">Ora Inizio:</label>
                                <input type="time" id="dfn-se-start" class="dfn-input-control" required>
                            </div>
                            <div class="dfn-shift-input-col dfn-shift-col-time">
                                <label class="dfn-form-label" for="dfn-se-end">Ora Fine:</label>
                                <input type="time" id="dfn-se-end" class="dfn-input-control" required>
                            </div>
                        </div>

                        <div class="dfn-modal-footer">
                            <button type="button" class="button" data-close-modal="dfn-modal-shift-editor-backdrop">Annulla</button>
                            <button type="submit" class="button button-primary dfn-btn-fai" id="dfn-se-submit-btn">💾 Salva Turno</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ====================================================================
             MODALE 5: VERIFICA ERRORI & CONFLITTI DI ASSEGNAZIONE
             ==================================================================== -->
        <div class="dfn-modal-backdrop" id="dfn-modal-conflicts-backdrop">
            <div class="dfn-modal-window dfn-modal-conflicts-window" role="dialog" aria-modal="true" aria-labelledby="dfn-mc-title">
                <div class="dfn-modal-header" style="background:#fff1f2; border-bottom:1px solid #fecdd3;">
                    <div>
                        <h3 class="dfn-modal-title" id="dfn-mc-title" style="color:#9f1239;">⚠️ Verifica Errori &amp; Conflitti Turni</h3>
                        <p class="dfn-modal-subtitle" id="dfn-mc-subtitle" style="color:#881337;">Controllo sovrapposizioni orarie tra turni e beni dello stesso giorno</p>
                    </div>
                    <button type="button" class="dfn-modal-close-btn" data-close-modal="dfn-modal-conflicts-backdrop">✕</button>
                </div>
                <div class="dfn-modal-body" style="max-height:65vh; overflow-y:auto; padding:20px;">
                    <div id="dfn-conflicts-modal-content">
                        <!-- Caricato dinamicamente da JavaScript -->
                    </div>
                </div>
                <div class="dfn-modal-footer" style="background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:12px; color:#64748b;">
                        💡 <em>Rimuovendo un'assegnazione conflittuale, la matrice e il contatore si aggiorneranno in tempo reale.</em>
                    </div>
                    <button type="button" class="button" data-close-modal="dfn-modal-conflicts-backdrop">Chiudi</button>
                </div>
            </div>
        </div>

        <!-- CONTENITORE TOAST NOTIFICATIONS -->
        <div class="dfn-matrix-toasts" id="dfn-matrix-toasts" aria-live="polite"></div>

        <!-- STILI CSS DEDICATI MATRICE AD ALTA DENSITÀ CON STILE FAI -->
        <style>
            #wpfooter { position: relative !important; clear: both !important; }
            .dfn-matrix-main-wrap {
                margin: 15px 20px 40px 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                color: #0f172a;
            }

            /* Pulsanti in Stile Ufficiale FAI Green */
            .dfn-btn-fai, 
            .button.dfn-btn-fai,
            .button-primary.dfn-btn-fai {
                background: #004b23 !important;
                border-color: #003b1c !important;
                color: #ffffff !important;
                font-weight: 700 !important;
                box-shadow: 0 1px 3px rgba(0,75,35,0.2) !important;
                transition: background 0.15s ease, border-color 0.15s ease !important;
            }
            .dfn-btn-fai:hover,
            .button.dfn-btn-fai:hover,
            .button-primary.dfn-btn-fai:hover {
                background: #003b1c !important;
                border-color: #002813 !important;
                color: #ffffff !important;
            }

            /* Header */
            .dfn-matrix-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                flex-wrap: wrap;
                gap: 16px;
                margin-bottom: 16px;
                background: #ffffff;
                padding: 16px 20px;
                border-radius: 10px;
                border: 1px solid #cbd5e1;
                box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            }
            .dfn-matrix-back-link {
                text-decoration: none;
                color: #004b23;
                font-weight: 700;
                font-size: 13px;
                display: inline-block;
                margin-bottom: 4px;
            }
            .dfn-matrix-back-link:hover { text-decoration: underline; color: #003b1c; }
            .dfn-matrix-title-row {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .dfn-matrix-title {
                font-size: 22px;
                font-weight: 800;
                color: #0f172a;
                margin: 0;
                line-height: 1.2;
            }
            .dfn-badge-type {
                font-size: 11.5px;
                font-weight: 800;
                padding: 3px 9px;
                border-radius: 6px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .dfn-badge-giornate { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
            .dfn-badge-locale { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
            .dfn-status-pill {
                font-size: 11.5px;
                font-weight: 700;
                padding: 3px 9px;
                border-radius: 6px;
            }
            .dfn-status-published { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
            .dfn-status-draft { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
            .dfn-matrix-actions-bar {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            .dfn-btn-unpublish { color: #b91c1c !important; font-weight: 700 !important; }
            .dfn-btn-danger { color: #b91c1c !important; border-color: #fca5a5 !important; font-weight: 700 !important; background: #fff !important; }
            .dfn-btn-secondary { font-weight: 700 !important; }

            /* Day Tabs (Sticky Segmented Bar) */
            .dfn-day-tabs-wrapper {
                position: sticky;
                top: 32px;
                z-index: 100;
                background: rgba(255,255,255,0.96);
                backdrop-filter: blur(8px);
                padding: 8px 12px;
                border-radius: 10px;
                border: 1px solid #cbd5e1;
                box-shadow: 0 4px 12px rgba(0,0,0,0.06);
                margin-bottom: 14px;
            }
            .dfn-day-tabs {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .dfn-day-tab {
                display: inline-flex;
                align-items: center;
                gap: 7px;
                background: #f8fafc;
                border: 1.5px solid #cbd5e1;
                border-radius: 8px;
                padding: 7px 14px;
                font-size: 13px;
                font-weight: 700;
                color: #334155;
                cursor: pointer;
                transition: all 0.15s ease;
                outline: none;
            }
            .dfn-day-tab:hover {
                background: #f1f5f9;
                border-color: #94a3b8;
                color: #0f172a;
            }
            .dfn-day-tab.is-active {
                background: #004b23;
                border-color: #003b1c;
                color: #ffffff;
                box-shadow: 0 2px 6px rgba(0,75,35,0.25);
            }
            .dfn-day-tab-label {
                font-size: 11.5px;
                font-weight: 500;
                opacity: 0.85;
            }
            .dfn-day-tab-badge {
                font-size: 11px;
                font-weight: 800;
                padding: 2px 7px;
                border-radius: 10px;
            }
            .dfn-day-tab.is-active .dfn-badge-assigned {
                background: rgba(255,255,255,0.25);
                color: #ffffff;
            }
            .dfn-day-tab:not(.is-active) .dfn-badge-assigned {
                background: #dcfce7;
                color: #15803d;
                border: 1px solid #86efac;
            }
            .dfn-badge-pool {
                background: #fef3c7;
                color: #92400e;
                border: 1px solid #fde68a;
            }
            .dfn-day-tab.is-active .dfn-badge-pool {
                background: #fef3c7;
                color: #92400e;
            }

            /* Toolbar */
            .dfn-matrix-toolbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
                background: #ffffff;
                padding: 10px 14px;
                border-radius: 8px;
                border: 1px solid #cbd5e1;
                box-shadow: 0 1px 3px rgba(0,0,0,0.03);
                margin-bottom: 16px;
            }
            .dfn-toolbar-left, .dfn-toolbar-right {
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            .dfn-search-box {
                position: relative;
                display: inline-flex;
                align-items: center;
            }
            .dfn-search-icon {
                position: absolute;
                left: 9px;
                font-size: 13px;
                color: #94a3b8;
                pointer-events: none;
            }
            #dfn-matrix-search-input {
                width: 250px;
                height: 32px;
                padding: 0 28px 0 28px;
                border-radius: 6px;
                border: 1px solid #cbd5e1;
                font-size: 12.5px;
                outline: none;
            }
            #dfn-matrix-search-input:focus {
                border-color: #004b23;
                box-shadow: 0 0 0 2px rgba(0,75,35,0.15);
            }
            #dfn-matrix-search-clear {
                position: absolute;
                right: 6px;
                background: none;
                border: none;
                color: #94a3b8;
                cursor: pointer;
                font-size: 12px;
                padding: 2px 4px;
            }
            #dfn-matrix-search-clear:hover { color: #0f172a; }
            #dfn-matrix-role-filter {
                height: 32px;
                border-radius: 6px;
                border: 1px solid #cbd5e1;
                font-size: 12px;
                padding: 0 8px;
                outline: none;
            }
            .dfn-btn-pool-drawer {
                background: #eff6ff !important;
                color: #1d4ed8 !important;
                border-color: #bfdbfe !important;
                font-weight: 700 !important;
                height: 32px !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 5px !important;
            }
            .dfn-btn-pool-drawer:hover {
                background: #dbeafe !important;
                border-color: #93c5fd !important;
            }

            /* Panes */
            .dfn-day-tab-pane { display: none; }
            .dfn-day-tab-pane.is-active { display: block; }

            /* Griglia Multi-Colonna dei Luoghi */
            .dfn-matrix-places-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
                gap: 16px;
                align-items: start;
            }
            .dfn-place-box {
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: 0 2px 6px rgba(0,0,0,0.03);
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
            }
            .dfn-place-box:hover {
                border-color: #94a3b8;
                box-shadow: 0 3px 10px rgba(0,0,0,0.06);
            }
            
            /* Intestazione Luogo / Bene (Badge e Azioni sopra, Titolo sotto) */
            .dfn-place-box-header {
                background: #f8fafc;
                border-bottom: 1.5px solid #e2e8f0;
                padding: 8px 12px 10px 12px;
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .dfn-place-header-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 6px;
                flex-wrap: wrap;
            }
            .dfn-place-header-title-row {
                display: flex;
                align-items: flex-start;
                gap: 6px;
                margin-top: 2px;
            }
            .dfn-place-icon {
                font-size: 14px;
                flex-shrink: 0;
                margin-top: 1px;
            }
            .dfn-place-name {
                font-size: 13.5px;
                font-weight: 800;
                color: #0f172a;
                margin: 0;
                line-height: 1.35;
                word-break: break-word;
                white-space: normal;
            }
            .dfn-place-badges-wrap {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .dfn-badge-counter {
                font-size: 11px;
                font-weight: 700;
                padding: 2px 6px;
                border-radius: 6px;
            }
            .dfn-badge-shifts { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
            .dfn-badge-vols { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
            .dfn-place-header-actions {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .dfn-btn-icon {
                background: #ffffff;
                border: 1px solid #cbd5e1;
                border-radius: 4px;
                padding: 2px 6px;
                font-size: 11px;
                font-weight: 700;
                color: #334155;
                cursor: pointer;
                text-decoration: none;
                line-height: 1.4;
            }
            .dfn-btn-icon:hover { background: #f1f5f9; color: #0f172a; }
            .dfn-btn-icon-del { color: #ef4444; border-color: #fca5a5; }
            .dfn-btn-icon-del:hover { background: #fef2f2; color: #b91c1c; }

            .dfn-place-box-shifts {
                padding: 10px;
                display: flex;
                flex-direction: column;
                gap: 10px;
                background: #fafafa;
            }
            .dfn-place-no-shifts {
                padding: 16px;
                text-align: center;
                font-size: 12px;
                color: #64748b;
                border: 1px dashed #cbd5e1;
                border-radius: 6px;
                background: #ffffff;
            }

            /* Shift Card & Shift Header (Verde FAI #004b23 con badge ed elementi ad alto contrasto) */
            .dfn-shift-card {
                background: #ffffff;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            }
            .dfn-shift-card-header {
                background: #004b23;
                color: #ffffff;
                padding: 6px 10px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 6px;
            }
            .dfn-shift-time-label {
                display: flex;
                align-items: center;
                gap: 5px;
                font-size: 12.5px;
            }
            .dfn-shift-clock-icon {
                font-size: 12px;
                opacity: 0.9;
            }
            .dfn-shift-label-txt { font-weight: 800; color: #ffffff; }
            .dfn-shift-time-txt { font-size: 11.5px; color: #e2f0d9; }
            .dfn-shift-count-badge {
                font-size: 10.5px;
                font-weight: 800;
                background: rgba(255,255,255,0.22);
                color: #ffffff;
                border: 1px solid rgba(255,255,255,0.35);
                padding: 1px 6px;
                border-radius: 4px;
                margin-left: 2px;
            }
            .dfn-shift-card-actions {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .dfn-btn-quick-assign {
                background: #ffffff !important;
                color: #004b23 !important;
                border: 1px solid rgba(255,255,255,0.8) !important;
                border-radius: 4px;
                padding: 2px 8px;
                font-size: 11px;
                font-weight: 800;
                cursor: pointer;
                line-height: 1.4;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1);
                transition: all 0.15s ease;
            }
            .dfn-btn-quick-assign:hover {
                background: #f0fdf4 !important;
                color: #003b1c !important;
                border-color: #ffffff !important;
            }
            .dfn-btn-edit-shift {
                background: rgba(255,255,255,0.18);
                color: #ffffff;
                border: 1px solid rgba(255,255,255,0.25);
                border-radius: 4px;
                padding: 2px 5px;
                font-size: 11px;
                cursor: pointer;
            }
            .dfn-btn-edit-shift:hover { background: rgba(255,255,255,0.35); }
            .dfn-btn-icon-del-shift {
                background: rgba(239,68,68,0.3);
                color: #ffffff;
                border: 1px solid rgba(239,68,68,0.5);
                border-radius: 4px;
                padding: 2px 5px;
                font-size: 11px;
                text-decoration: none;
            }
            .dfn-btn-icon-del-shift:hover {
                background: #ef4444;
                color: #ffffff;
            }

            /* Dropzone & Chips */
            .dfn-shift-dropzone {
                padding: 6px;
                min-height: 38px;
                display: flex;
                flex-direction: column;
                gap: 5px;
                background: #ffffff;
                border-radius: 0 0 8px 8px;
                transition: background 0.15s ease, border-color 0.15s ease;
            }
            .dfn-shift-dropzone.is-drag-over {
                background: #f0fdf4 !important;
                border: 2px dashed #16a34a !important;
            }
            .dfn-dropzone-empty-msg {
                padding: 8px;
                text-align: center;
                font-size: 11.5px;
                color: #94a3b8;
                font-style: italic;
                border: 1px dashed #cbd5e1;
                border-radius: 6px;
                background: #fafafa;
                user-select: none;
            }

            /* Micro-Chip Volontario (~30px altezza) */
            .dfn-matrix-chip {
                height: 30px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: #f8fafc;
                border: 1px solid #cbd5e1;
                border-radius: 6px;
                padding: 0 6px 0 6px;
                gap: 6px;
                cursor: grab;
                user-select: none;
                transition: transform 0.12s ease, box-shadow 0.12s ease, opacity 0.15s ease, background 0.15s ease;
            }
            .dfn-matrix-chip:hover {
                background: #ffffff;
                border-color: #94a3b8;
                box-shadow: 0 2px 5px rgba(0,0,0,0.06);
            }
            .dfn-matrix-chip:active { cursor: grabbing; }
            .dfn-matrix-chip.is-search-highlight {
                background: #fef9c3 !important;
                border-color: #facc15 !important;
                box-shadow: 0 0 0 2px #facc15 !important;
            }
            .dfn-matrix-chip.is-search-dimmed { opacity: 0.18 !important; }
            .dfn-matrix-chip.is-role-hidden { display: none !important; }
            .dfn-chip-left {
                display: flex;
                align-items: center;
                gap: 6px;
                min-width: 0;
                flex: 1;
            }
            .dfn-chip-handle {
                color: #94a3b8;
                font-size: 11px;
                cursor: grab;
            }
            .dfn-chip-role-pill {
                font-size: 10px;
                font-weight: 800;
                padding: 1px 5px;
                border-radius: 4px;
                letter-spacing: 0.2px;
                text-transform: uppercase;
                flex-shrink: 0;
            }
            .dfn-chip-name {
                font-size: 12px;
                font-weight: 700;
                color: #1e293b;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .dfn-chip-icon {
                font-size: 11px;
                flex-shrink: 0;
            }
            .dfn-chip-badge-manual {
                font-size: 9.5px;
                color: #475569;
                background: #f1f5f9;
                border: 1px solid #cbd5e1;
                border-radius: 3px;
                padding: 0 4px;
                flex-shrink: 0;
            }
            .dfn-chip-actions {
                display: flex;
                align-items: center;
                gap: 2px;
                flex-shrink: 0;
            }
            .dfn-chip-info-btn, .dfn-chip-del-btn {
                background: none;
                border: none;
                cursor: pointer;
                font-size: 11.5px;
                padding: 2px 3px;
                border-radius: 3px;
                color: #64748b;
                line-height: 1;
            }
            .dfn-chip-info-btn:hover { color: #004b23; background: #dcfce7; }
            .dfn-chip-del-btn:hover { color: #ef4444; background: #fee2e2; }

            /* Empty Day */
            .dfn-empty-day-box {
                background: #ffffff;
                border: 1px dashed #cbd5e1;
                border-radius: 10px;
                padding: 40px 20px;
                text-align: center;
                color: #64748b;
            }

            /* Slide-Out Drawer Pool Volontari Disponibili */
            .dfn-drawer-overlay {
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(15,23,42,0.4);
                backdrop-filter: blur(2px);
                z-index: 99998;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.2s ease, visibility 0.2s ease;
            }
            .dfn-drawer-overlay.is-open { opacity: 1; visibility: visible; }
            .dfn-matrix-drawer {
                position: fixed;
                top: 0; right: -380px; bottom: 0;
                width: 360px;
                background: #ffffff;
                box-shadow: -4px 0 20px rgba(0,0,0,0.15);
                z-index: 99999;
                display: flex;
                flex-direction: column;
                transition: right 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .dfn-matrix-drawer.is-open { right: 0; }
            .dfn-drawer-header {
                padding: 16px 18px;
                background: #f8fafc;
                border-bottom: 1.5px solid #e2e8f0;
            }
            .dfn-drawer-title-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .dfn-drawer-title {
                margin: 0;
                font-size: 16px;
                font-weight: 800;
                color: #0f172a;
            }
            .dfn-drawer-close-btn {
                background: none;
                border: none;
                font-size: 16px;
                font-weight: 700;
                color: #64748b;
                cursor: pointer;
                padding: 4px;
            }
            .dfn-drawer-close-btn:hover { color: #0f172a; }
            .dfn-drawer-subtitle {
                margin: 6px 0 10px 0;
                font-size: 12px;
                color: #64748b;
                line-height: 1.35;
            }
            .dfn-drawer-search-wrap input {
                width: 100%;
                height: 32px;
                border-radius: 6px;
                border: 1px solid #cbd5e1;
                padding: 0 10px;
                font-size: 12px;
                outline: none;
            }
            .dfn-drawer-search-wrap input:focus {
                border-color: #004b23;
                box-shadow: 0 0 0 2px rgba(0,75,35,0.15);
            }
            .dfn-drawer-body {
                flex: 1;
                overflow-y: auto;
                padding: 14px;
                display: flex;
                flex-direction: column;
                gap: 8px;
                background: #f8fafc;
            }
            .dfn-pool-card {
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                border-radius: 8px;
                padding: 9px 12px;
                display: flex;
                flex-direction: column;
                gap: 5px;
                cursor: grab;
                user-select: none;
                box-shadow: 0 1px 3px rgba(0,0,0,0.03);
                transition: transform 0.12s ease, border-color 0.15s ease, box-shadow 0.15s ease;
            }
            .dfn-pool-card:hover {
                border-color: #004b23;
                box-shadow: 0 3px 8px rgba(0,75,35,0.1);
            }
            .dfn-pool-card:active { cursor: grabbing; }
            .dfn-pool-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .dfn-pool-card-name {
                font-size: 13px;
                font-weight: 800;
                color: #0f172a;
            }
            .dfn-drawer-day-selector {
                margin: 8px 0 8px 0;
            }
            .dfn-drawer-tabs-pills {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
            }
            .dfn-drawer-day-pill {
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                border-radius: 6px;
                padding: 4px 10px;
                font-size: 11.5px;
                font-weight: 700;
                color: #334155;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .dfn-drawer-day-pill:hover {
                background: #f1f5f9;
                border-color: #94a3b8;
                color: #0f172a;
            }
            .dfn-drawer-day-pill.is-active {
                background: #004b23;
                border-color: #003b1c;
                color: #ffffff;
                box-shadow: 0 1px 3px rgba(0,75,35,0.25);
            }
            .dfn-drawer-active-day-banner {
                background: #f0fdf4;
                border: 1px solid #86efac;
                color: #15803d;
                padding: 5px 10px;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 700;
            }
            .dfn-pool-day-tag {
                font-size: 10.5px;
                font-weight: 800;
                background: #f1f5f9;
                color: #334155;
                border: 1px solid #cbd5e1;
                padding: 2px 6px;
                border-radius: 4px;
                white-space: nowrap;
            }
            .dfn-pool-other-days-tag {
                font-size: 10px;
                font-weight: 700;
                background: #fefce8;
                color: #854d0e;
                border: 1px solid #fef08a;
                padding: 1px 6px;
                border-radius: 4px;
            }
            .dfn-pool-card-footer-hint {
                font-size: 10px;
                color: #94a3b8;
                text-align: right;
                margin-top: 2px;
                font-weight: 600;
            }

            .dfn-pool-card-badges {
                display: flex;
                gap: 4px;
                flex-wrap: wrap;
            }
            .dfn-pool-slot-badge {
                font-size: 10.5px;
                font-weight: 700;
                background: #eff6ff;
                color: #1d4ed8;
                border: 1px solid #bfdbfe;
                padding: 1px 6px;
                border-radius: 4px;
            }
            .dfn-pool-role-pref {
                font-size: 10.5px;
                background: #f0fdf4;
                color: #15803d;
                border: 1px solid #bbf7d0;
                padding: 1px 6px;
                border-radius: 4px;
            }

            /* Modali Popup Stile FAI */
            .dfn-modal-backdrop {
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(15,23,42,0.6);
                backdrop-filter: blur(3px);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.2s ease, visibility 0.2s ease;
            }
            .dfn-modal-backdrop.is-open { opacity: 1; visibility: visible; }
            .dfn-modal-window {
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                width: 100%;
                max-width: 540px;
                max-height: 90vh;
                display: flex;
                flex-direction: column;
                overflow: hidden;
                box-sizing: border-box;
                animation: dfnModalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            }
            .dfn-modal-window.dfn-modal-quick-assign {
                max-width: 680px !important;
            }
            @keyframes dfnModalPop {
                from { transform: scale(0.95); opacity: 0; }
                to { transform: scale(1); opacity: 1; }
            }
            .dfn-modal-header {
                padding: 15px 22px;
                background: #f8fafc;
                border-bottom: 1.5px solid #e2e8f0;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-sizing: border-box;
            }
            .dfn-modal-title { margin: 0; font-size: 15.5px; font-weight: 800; color: #004b23; }
            .dfn-modal-subtitle { margin: 2px 0 0 0; font-size: 12px; color: #64748b; }
            .dfn-modal-close-btn {
                background: none;
                border: none;
                font-size: 18px;
                color: #94a3b8;
                cursor: pointer;
                padding: 4px;
            }
            .dfn-modal-close-btn:hover { color: #0f172a; }
            .dfn-modal-body {
                padding: 18px 22px;
                overflow-y: auto;
                overflow-x: hidden !important;
                box-sizing: border-box;
                width: 100%;
            }
            .dfn-modal-footer {
                padding: 14px 22px;
                background: #f8fafc;
                border-top: 1.5px solid #e2e8f0;
                display: flex;
                justify-content: flex-end;
                gap: 10px;
                box-sizing: border-box;
            }

            /* Dossier Styles */
            .dfn-dossier-header-left {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .dfn-dossier-avatar {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: #004b23;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 15px;
                font-weight: 800;
                flex-shrink: 0;
            }
            .dfn-dossier-meta-tags {
                display: flex;
                gap: 5px;
                margin-top: 3px;
                flex-wrap: wrap;
            }
            .dfn-dossier-section {
                margin-bottom: 14px;
                padding-bottom: 12px;
                border-bottom: 1px solid #e2e8f0;
            }
            .dfn-dossier-section:last-child {
                margin-bottom: 0;
                padding-bottom: 0;
                border-bottom: none;
            }
            .dfn-dossier-section-title {
                font-size: 12px;
                font-weight: 800;
                color: #004b23;
                margin: 0 0 8px 0;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .dfn-dossier-info-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 8px;
                background: #f8fafc;
                padding: 8px 10px;
                border-radius: 6px;
                border: 1px solid #e2e8f0;
            }
            .dfn-info-label { display: block; font-size: 10.5px; color: #64748b; }
            .dfn-info-val { font-size: 12px; color: #0f172a; }
            .dfn-dossier-notes-box {
                margin-top: 8px;
                background: #fffbeb;
                border: 1px solid #fde68a;
                padding: 6px 10px;
                border-radius: 6px;
                font-size: 11.5px;
                color: #92400e;
            }
            .dfn-survey-responses-list {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                padding: 8px 10px;
                font-size: 11.5px;
            }
            .dfn-survey-day-item {
                margin-bottom: 5px;
                padding-bottom: 5px;
                border-bottom: 1px dashed #cbd5e1;
            }
            .dfn-survey-day-item:last-child { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }

            .dfn-dossier-assignment-tools {
                display: flex;
                flex-direction: column;
                gap: 10px;
                background: #f8fafc;
                padding: 10px 12px;
                border-radius: 8px;
                border: 1px solid #e2e8f0;
            }
            .dfn-tool-group label {
                display: block;
                font-size: 11.5px;
                font-weight: 700;
                color: #475569;
                margin-bottom: 4px;
            }
            .dfn-inline-input-action {
                display: flex;
                gap: 8px;
            }
            .dfn-select-control, .dfn-input-control {
                flex: 1;
                height: 32px;
                border-radius: 6px;
                border: 1px solid #cbd5e1;
                font-size: 12px;
                padding: 0 8px;
                outline: none;
            }
            .dfn-select-control:focus, .dfn-input-control:focus {
                border-color: #004b23;
                box-shadow: 0 0 0 2px rgba(0,75,35,0.15);
            }
            .dfn-dossier-del-assignment-wrap {
                margin-top: 12px;
                display: flex;
                justify-content: flex-end;
            }
            .dfn-btn-remove-ass {
                color: #b91c1c !important;
                border-color: #fca5a5 !important;
                background: #ffffff !important;
                font-weight: 700 !important;
            }
            .dfn-btn-remove-ass:hover { background: #fef2f2 !important; }

            /* Quick Assign Modal Compact Styles (Grid 2 Colonne, Nessun Overflow Orizzontale) */
            .dfn-qa-mode-switch {
                display: flex;
                gap: 16px;
                background: #f8fafc;
                padding: 6px 12px;
                border-radius: 6px;
                border: 1px solid #e2e8f0;
                margin-bottom: 12px;
                box-sizing: border-box;
                width: 100%;
            }
            .dfn-qa-radio-label {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                font-size: 12px;
                font-weight: 700;
                cursor: pointer;
            }
            .dfn-qa-compact-grid {
                display: grid;
                grid-template-columns: 1.15fr 0.85fr;
                gap: 20px;
                width: 100%;
                box-sizing: border-box;
                margin-bottom: 14px;
                align-items: start;
            }
            .dfn-qa-col-input, .dfn-qa-col-role {
                min-width: 0;
                width: 100%;
                box-sizing: border-box;
            }
            .dfn-form-label {
                display: block;
                font-size: 11.5px;
                font-weight: 700;
                color: #334155;
                margin-bottom: 4px;
            }
            .dfn-autocomplete-wrapper { 
                position: relative; 
                width: 100%;
                box-sizing: border-box;
            }
            .dfn-autocomplete-results {
                position: absolute;
                top: 36px; left: 0; right: 0;
                background: #ffffff;
                border: 1.5px solid #cbd5e1;
                border-radius: 6px;
                box-shadow: 0 6px 16px rgba(0,0,0,0.1);
                max-height: 200px;
                overflow-y: auto;
                z-index: 10000;
                box-sizing: border-box;
                width: 100%;
            }
            .dfn-ac-item {
                padding: 7px 10px;
                border-bottom: 1px solid #f1f5f9;
                cursor: pointer;
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 12px;
                box-sizing: border-box;
            }
            .dfn-ac-item:hover { background: #f0fdf4; }
            .dfn-ac-item-star {
                font-size: 10.5px;
                font-weight: 700;
                color: #15803d;
                background: #dcfce7;
                padding: 1px 5px;
                border-radius: 4px;
            }
            .dfn-qa-selected-badge {
                margin-top: 5px;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #dcfce7;
                color: #15803d;
                border: 1px solid #86efac;
                padding: 3px 8px;
                border-radius: 5px;
                font-weight: 700;
                font-size: 11.5px;
                max-width: 100%;
                box-sizing: border-box;
                word-break: break-all;
            }
            .dfn-qa-selected-badge button {
                background: none;
                border: none;
                cursor: pointer;
                color: #15803d;
                font-weight: 800;
                padding: 0 2px;
            }

            /* Shift Editor Modal Single Row */
            .dfn-shift-inputs-row {
                display: flex;
                gap: 8px;
                align-items: flex-end;
                margin-bottom: 14px;
            }
            .dfn-shift-col-label { flex: 2; min-width: 140px; }
            .dfn-shift-col-time { flex: 1; min-width: 95px; }

            /* Toasts */
            .dfn-matrix-toasts {
                position: fixed;
                bottom: 24px;
                right: 24px;
                z-index: 100001;
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .dfn-toast {
                background: #004b23;
                color: #ffffff;
                padding: 10px 16px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 700;
                box-shadow: 0 4px 14px rgba(0,0,0,0.2);
                display: flex;
                align-items: center;
                gap: 8px;
                animation: dfnToastIn 0.2s ease;
            }
            .dfn-toast.is-success { background: #004b23; }
            .dfn-toast.is-error { background: #b91c1c; }
            @keyframes dfnToastIn {
                from { transform: translateY(20px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }

            /* Conflict Visual Highlights & Badges */
            .dfn-matrix-chip.dfn-chip-has-conflict {
                border-color: #ef4444 !important;
                background: #fff5f5 !important;
                box-shadow: 0 0 0 1.5px rgba(239, 68, 68, 0.45), 0 2px 6px rgba(239, 68, 68, 0.15) !important;
            }
            .dfn-matrix-chip.dfn-chip-has-conflict:hover {
                background: #fee2e2 !important;
                border-color: #dc2626 !important;
            }
            .dfn-chip-badge-conflict {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fca5a5;
                font-size: 9px;
                font-weight: 800;
                padding: 1px 4px;
                border-radius: 4px;
                text-transform: uppercase;
                letter-spacing: 0.2px;
                display: inline-flex;
                align-items: center;
                gap: 2px;
                white-space: nowrap;
            }
            .dfn-btn-conflicts {
                background: #fff !important;
                border-color: #fca5a5 !important;
                color: #b91c1c !important;
                font-weight: 700 !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 6px !important;
                transition: all 0.15s ease !important;
            }
            .dfn-btn-conflicts:hover {
                background: #fee2e2 !important;
                border-color: #ef4444 !important;
                color: #991b1b !important;
            }
            .dfn-btn-conflicts.is-clean {
                border-color: #86efac !important;
                color: #15803d !important;
                background: #f0fdf4 !important;
            }
            .dfn-btn-conflicts.is-clean:hover {
                background: #dcfce7 !important;
                border-color: #22c55e !important;
            }
            .dfn-conflict-count-badge {
                background: #ef4444;
                color: #ffffff;
                font-size: 11px;
                font-weight: 800;
                padding: 1px 6px;
                border-radius: 10px;
                line-height: 1.2;
            }
            .dfn-conflict-count-badge.is-zero {
                background: #22c55e;
            }

            /* Modal Conflicts Content */
            .dfn-modal-conflicts-window {
                max-width: 680px;
            }
            .dfn-conflict-empty-state {
                padding: 36px 20px;
                text-align: center;
            }
            .dfn-conflicts-summary-bar {
                background: #fff1f2;
                border: 1px solid #fecdd3;
                border-radius: 8px;
                padding: 10px 14px;
                font-size: 13px;
                font-weight: 700;
                color: #9f1239;
                margin-bottom: 16px;
            }
            .dfn-conflict-group-card {
                background: #ffffff;
                border: 1.5px solid #fca5a5;
                border-radius: 8px;
                margin-bottom: 14px;
                overflow: hidden;
                box-shadow: 0 2px 6px rgba(239, 68, 68, 0.08);
            }
            .dfn-conflict-group-header {
                background: #fff5f5;
                border-bottom: 1px solid #fee2e2;
                padding: 10px 14px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
            }
            .dfn-conflict-vol-info {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .dfn-conflict-icon { font-size: 16px; }
            .dfn-conflict-name {
                font-size: 14px;
                font-weight: 800;
                color: #991b1b;
            }
            .dfn-conflict-day-badge {
                font-size: 11.5px;
                font-weight: 700;
                background: #ffffff;
                color: #475569;
                padding: 2px 8px;
                border-radius: 6px;
                border: 1px solid #cbd5e1;
            }
            .dfn-conflict-shifts-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 10px;
                padding: 12px;
                background: #ffffff;
            }
            .dfn-conflict-shift-item {
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                padding: 10px 12px;
                background: #f8fafc;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                gap: 6px;
            }
            .dfn-conflict-shift-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 6px;
            }
            .dfn-conflict-place-name {
                font-size: 12.5px;
                font-weight: 700;
                color: #0f172a;
            }
            .dfn-conflict-role-tag {
                font-size: 10px;
                font-weight: 800;
                background: #e2e8f0;
                color: #334155;
                padding: 1px 6px;
                border-radius: 4px;
            }
            .dfn-conflict-time-row {
                font-size: 12px;
                color: #334155;
            }
            .dfn-conflict-shift-actions {
                margin-top: 4px;
                padding-top: 6px;
                border-top: 1px dashed #e2e8f0;
            }
            .dfn-btn-resolve-conflict {
                color: #b91c1c !important;
                border-color: #fca5a5 !important;
                background: #ffffff !important;
                font-size: 11px !important;
                font-weight: 700 !important;
                width: 100%;
                text-align: center;
            }
            .dfn-btn-resolve-conflict:hover {
                background: #fee2e2 !important;
                border-color: #ef4444 !important;
                color: #991b1b !important;
            }
        </style>

        <!-- JAVASCRIPT ENGINE MATRICE DEI TURNI -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ajaxUrl   = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var ajaxNonce = '<?php echo esc_js($ajax_nonce); ?>';
            var currentEventId = <?php echo intval($event_id); ?>;
            var activeDayId = <?php echo intval($selected_day_id); ?>;

            var initialConflictAssignmentIds = <?php echo json_encode($conflict_map, JSON_UNESCAPED_UNICODE); ?>;
            var initialConflictsList = <?php echo json_encode($all_conflicts, JSON_UNESCAPED_UNICODE); ?>;
            var currentConflictMap = initialConflictAssignmentIds || {};
            var currentConflictsList = initialConflictsList || [];

            var allVolunteersData = <?php echo json_encode(array_map(function($v) {
                return [
                    'id'                => (int) $v->id,
                    'name'              => $v->first_name . ' ' . $v->last_name,
                    'phone'             => (string) $v->phone,
                    'email'             => (string) $v->email,
                    'has_safety_course' => ! empty($v->has_safety_course) ? 1 : 0,
                    'is_guide'          => ! empty($v->is_guide) ? 1 : 0,
                ];
            }, $all_volunteers), JSON_UNESCAPED_UNICODE); ?>;

            var dayPoolData = <?php 
                $pool_export = [];
                foreach ($day_data as $did => $dinfo) {
                    $pool_export[$did] = $dinfo['unassigned_pool'];
                }
                echo json_encode($pool_export, JSON_UNESCAPED_UNICODE);
            ?>;

            var eventDaysData = <?php echo json_encode(array_map(function($d) {
                return [
                    'id'             => (int) $d->id,
                    'day_label'      => (string) $d->day_label,
                    'event_date'     => (string) $d->event_date,
                    'formatted_date' => date_i18n('l d F Y', strtotime($d->event_date)),
                    'short_label'    => $d->day_label ?: date_i18n('D d/m', strtotime($d->event_date)),
                ];
            }, $days), JSON_UNESCAPED_UNICODE); ?>;

            function showToast(message, type) {
                type = type || 'success';
                var container = document.getElementById('dfn-matrix-toasts');
                if (! container) return;
                var toast = document.createElement('div');
                toast.className = 'dfn-toast is-' + type;
                toast.innerHTML = (type === 'success' ? '✅ ' : '⚠️ ') + message;
                container.appendChild(toast);
                setTimeout(function() {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.3s ease';
                    setTimeout(function() { toast.remove(); }, 300);
                }, 3500);
            }

            // ====================================================================
            // CONFLICT ENGINE: BADGES, HIGHLIGHTS, AUDIT MODAL & REFRESH
            // ====================================================================
            var conflictsModalBackdrop = document.getElementById('dfn-modal-conflicts-backdrop');
            var conflictsModalContent  = document.getElementById('dfn-conflicts-modal-content');
            var conflictsBtn           = document.getElementById('dfn-open-conflicts-modal-btn');
            var conflictsBadge         = document.getElementById('dfn-conflicts-badge');

            function updateConflictsBadge(conflictsCount) {
                if (! conflictsBtn) return;
                var count = parseInt(conflictsCount, 10) || 0;
                conflictsBtn.classList.toggle('is-clean', count === 0);
                conflictsBtn.innerHTML = (count > 0)
                    ? '⚠️ Conflitti (<span id="dfn-conflicts-badge" class="dfn-conflict-count-badge">' + count + '</span>)'
                    : '✅ Nessun Conflitto (<span id="dfn-conflicts-badge" class="dfn-conflict-count-badge is-zero">0</span>)';
            }

            function refreshChipsConflictBadges(conflictMap) {
                conflictMap = conflictMap || {};
                var chips = document.querySelectorAll('.dfn-matrix-chip');
                chips.forEach(function(chip) {
                    var assId = parseInt(chip.getAttribute('data-assignment-id'), 10);
                    var conflicts = conflictMap[assId] || [];
                    var hasConflict = conflicts.length > 0;
                    var chipLeft = chip.querySelector('.dfn-chip-left');

                    chip.setAttribute('data-has-conflict', hasConflict ? '1' : '0');
                    chip.classList.toggle('dfn-chip-has-conflict', hasConflict);

                    var existingBadge = chip.querySelector('.dfn-chip-badge-conflict');
                    if (hasConflict) {
                        var otherPlaces = conflicts.map(function(c) {
                            return (c.other_place_name || 'Altro bene') + ' (' + (c.other_shift_label || 'Turno') + ' ' + (c.other_time_start || '') + '-' + (c.other_time_end || '') + ')';
                        });
                        var tooltip = '⚠️ CONFLITTO ORARIO: Assegnato anche a ' + otherPlaces.join(', ');
                        chip.setAttribute('data-conflict-info', tooltip);
                        chip.setAttribute('title', (chip.getAttribute('data-volunteer-name') || '') + ' | ' + tooltip);

                        if (! existingBadge && chipLeft) {
                            var badge = document.createElement('span');
                            badge.className = 'dfn-chip-badge-conflict';
                            badge.textContent = '⚠️ Conflitto';
                            badge.title = tooltip;
                            chipLeft.appendChild(badge);
                        } else if (existingBadge) {
                            existingBadge.title = tooltip;
                        }
                    } else {
                        chip.removeAttribute('data-conflict-info');
                        chip.setAttribute('title', (chip.getAttribute('data-volunteer-name') || '') + ' | Clicca per dettagli / Trascina');
                        if (existingBadge) {
                            existingBadge.remove();
                        }
                    }
                });
            }

            function fetchFreshConflicts(callback) {
                var fd = new FormData();
                fd.append('action', 'dfn_matrix_get_conflicts');
                fd.append('security', ajaxNonce);
                fd.append('event_id', currentEventId);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(res) { return res.json(); })
                .then(function(response) {
                    if (response.success && response.data) {
                        currentConflictMap = response.data.conflict_assignment_ids || {};
                        currentConflictsList = response.data.conflicts || [];
                        updateConflictsBadge(currentConflictsList.length);
                        refreshChipsConflictBadges(currentConflictMap);

                        if (conflictsModalBackdrop && conflictsModalBackdrop.classList.contains('is-open')) {
                            renderConflictsModal();
                        }
                    }
                    if (typeof callback === 'function') {
                        callback(response.data);
                    }
                })
                .catch(function(err) {
                    console.error('Errore durante il recupero dei conflitti:', err);
                });
            }

            function renderConflictsModal() {
                if (! conflictsModalContent) return;
                if (! currentConflictsList || currentConflictsList.length === 0) {
                    conflictsModalContent.innerHTML = 
                        '<div class="dfn-conflict-empty-state">' +
                            '<div style="font-size:42px; margin-bottom:12px;">🎉</div>' +
                            '<h3 style="color:#15803d; font-size:18px; margin:0 0 6px 0;">Nessun conflitto o sovrapposizione!</h3>' +
                            '<p style="color:#64748b; font-size:13px; margin:0;">Tutti i volontari assegnati hanno turni orari perfettamente compatibili e non sovrapposti.</p>' +
                        '</div>';
                    return;
                }

                var html = '<div class="dfn-conflicts-summary-bar">' +
                    '⚠️ Rilevati <strong>' + currentConflictsList.length + '</strong> volontari con turni orari sovrapposti nello stesso giorno.' +
                '</div>';

                currentConflictsList.forEach(function(group) {
                    var contactInfo = '';
                    if (group.phone) contactInfo += '📞 ' + group.phone + ' ';
                    if (group.email) contactInfo += '✉️ ' + group.email;

                    html += '<div class="dfn-conflict-group-card">' +
                        '<div class="dfn-conflict-group-header">' +
                            '<div class="dfn-conflict-vol-info">' +
                                '<span class="dfn-conflict-icon">⚠️</span>' +
                                '<strong class="dfn-conflict-name">' + group.volunteer_name + '</strong>' +
                                (group.is_manual ? ' <span class="dfn-chip-badge-manual">👤 Manuale</span>' : '') +
                                (contactInfo ? ' <span style="font-size:11.5px; color:#64748b; margin-left:6px;">(' + contactInfo + ')</span>' : '') +
                            '</div>' +
                            '<span class="dfn-conflict-day-badge">🗓️ ' + group.day_label + ' (' + group.event_date + ')</span>' +
                        '</div>' +
                        '<div class="dfn-conflict-shifts-grid">';

                    (group.assignments || []).forEach(function(assItem) {
                        html += '<div class="dfn-conflict-shift-item">' +
                            '<div class="dfn-conflict-shift-top">' +
                                '<span class="dfn-conflict-place-name">📍 ' + assItem.place_name + '</span>' +
                                '<span class="dfn-conflict-role-tag">🎭 ' + assItem.role_assigned + '</span>' +
                            '</div>' +
                            '<div class="dfn-conflict-time-row">' +
                                '⏰ Turno: <strong>' + assItem.shift_label + '</strong> (' + assItem.time_start + ' - ' + assItem.time_end + ')' +
                            '</div>' +
                            '<div class="dfn-conflict-shift-actions">' +
                                '<button type="button" class="button dfn-btn-resolve-conflict" data-assignment-id="' + assItem.assignment_id + '" data-shift-id="' + assItem.shift_id + '">' +
                                    '🗑️ Rimuovi da questo turno' +
                                '</button>' +
                            '</div>' +
                        '</div>';
                    });

                    html += '</div></div>';
                });

                conflictsModalContent.innerHTML = html;
            }

            if (conflictsBtn) {
                conflictsBtn.addEventListener('click', function() {
                    renderConflictsModal();
                    openModal('dfn-modal-conflicts-backdrop');
                    fetchFreshConflicts();
                });
            }

            // 1-Click Resolve conflitto da modale
            if (conflictsModalContent) {
                conflictsModalContent.addEventListener('click', function(e) {
                    var resBtn = e.target.closest('.dfn-btn-resolve-conflict');
                    if (resBtn) {
                        var assId = resBtn.getAttribute('data-assignment-id');
                        if (! assId) return;

                        if (! confirm('Rimuovere questa assegnazione per risolvere il conflitto?')) {
                            return;
                        }

                        resBtn.disabled = true;
                        resBtn.textContent = 'Rimozione in corso...';

                        var fd = new FormData();
                        fd.append('action', 'dfn_matrix_remove_assignment');
                        fd.append('security', ajaxNonce);
                        fd.append('assignment_id', assId);

                        fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(function(res) { return res.json(); })
                        .then(function(response) {
                            if (response.success) {
                                var chip = document.querySelector('.dfn-matrix-chip[data-assignment-id="' + assId + '"]');
                                if (chip) {
                                    var dz = chip.closest('.dfn-shift-dropzone');
                                    chip.remove();
                                    if (dz && ! dz.querySelector('.dfn-matrix-chip')) {
                                        dz.innerHTML = '<div class="dfn-dropzone-empty-msg">Nessun volontario assegnato. Trascina qui o clicca "+ Assegna".</div>';
                                    }
                                }
                                showToast('Assegnazione rimossa con successo!');
                                fetchFreshConflicts();
                            } else {
                                alert(response.data ? response.data.message : 'Errore durante la rimozione.');
                                resBtn.disabled = false;
                                resBtn.textContent = '🗑️ Rimuovi da questo turno';
                            }
                        })
                        .catch(function(err) {
                            alert('Errore di connessione.');
                            resBtn.disabled = false;
                            resBtn.textContent = '🗑️ Rimuovi da questo turno';
                        });
                    }
                });
            }

            // 1. DAY TABS
            var dayTabs = document.querySelectorAll('.dfn-day-tab');
            var dayPanes = document.querySelectorAll('.dfn-day-tab-pane');

            function switchDayTab(dayId) {
                activeDayId = parseInt(dayId, 10);
                dayTabs.forEach(function(t) {
                    var isAct = (parseInt(t.getAttribute('data-day-id'), 10) === activeDayId);
                    t.classList.toggle('is-active', isAct);
                    t.setAttribute('aria-selected', isAct ? 'true' : 'false');
                });
                dayPanes.forEach(function(p) {
                    var isAct = (parseInt(p.getAttribute('data-day-id'), 10) === activeDayId);
                    p.classList.toggle('is-active', isAct);
                });
                renderPoolDrawerItems();
            }

            dayTabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    var did = this.getAttribute('data-day-id');
                    switchDayTab(did);
                });
            });

            // 2. SLIDE-OUT DRAWER POOL
            var drawer = document.getElementById('dfn-matrix-unassigned-drawer');
            var drawerOverlay = document.getElementById('dfn-drawer-overlay');
            var openPoolBtn = document.getElementById('dfn-open-pool-drawer-btn');
            var closePoolBtn = document.getElementById('dfn-close-pool-drawer-btn');
            var poolItemsContainer = document.getElementById('dfn-drawer-pool-items-container');
            var poolCountBadge = document.getElementById('dfn-drawer-pool-count');
            var poolSearchInput = document.getElementById('dfn-drawer-search-input');
            var drawerDaySelector = document.getElementById('dfn-drawer-day-selector');
            var drawerSubtitleTxt = document.getElementById('dfn-drawer-subtitle-txt');

            function formatSlotBadgeLabel(slotKey) {
                if (! slotKey) return '';
                var k = String(slotKey).toLowerCase().trim();
                var clean = k.replace(/_/g, ' ');
                clean = clean.charAt(0).toUpperCase() + clean.slice(1);

                if (k.indexOf('mattina') !== -1) {
                    var num = k.replace('mattina', '').replace(/[^0-9]/g, '');
                    if (num && num.length >= 2) {
                        return 'Mattina (' + num.substr(0, 2) + ':00)';
                    }
                    return 'Mattina';
                }
                if (k.indexOf('pomeriggio') !== -1) {
                    var num = k.replace('pomeriggio', '').replace(/[^0-9]/g, '');
                    if (num && num.length >= 2) {
                        return 'Pomeriggio (' + num.substr(0, 2) + ':00)';
                    }
                    return 'Pomeriggio';
                }
                if (k.indexOf('giornata') !== -1) {
                    return 'Intera Giornata';
                }
                return clean;
            }

            function openDrawer() {
                if (drawer && drawerOverlay) {
                    renderPoolDrawerItems();
                    drawer.classList.add('is-open');
                    drawerOverlay.classList.add('is-open');
                    drawer.setAttribute('aria-hidden', 'false');
                }
            }

            function closeDrawer() {
                if (drawer && drawerOverlay) {
                    drawer.classList.remove('is-open');
                    drawerOverlay.classList.remove('is-open');
                    drawer.setAttribute('aria-hidden', 'true');
                }
            }

            if (openPoolBtn) openPoolBtn.addEventListener('click', openDrawer);
            if (closePoolBtn) closePoolBtn.addEventListener('click', closeDrawer);
            if (drawerOverlay) drawerOverlay.addEventListener('click', closeDrawer);

            function renderPoolDrawerItems() {
                if (! poolItemsContainer) return;
                var poolList = dayPoolData[activeDayId] || [];
                if (poolCountBadge) poolCountBadge.textContent = poolList.length;

                var curDay = (eventDaysData || []).find(function(d) { return d.id === activeDayId; });
                var dayNameFormatted = curDay ? (curDay.formatted_date + (curDay.day_label ? ' — ' + curDay.day_label : '')) : 'Giornata';

                // Renderizza selettore / pillole delle giornate nel drawer
                if (drawerDaySelector) {
                    if (eventDaysData && eventDaysData.length > 1) {
                        var tabsHtml = '<div class="dfn-drawer-tabs-pills">';
                        eventDaysData.forEach(function(d) {
                            var isAct = (d.id === activeDayId);
                            var pList = dayPoolData[d.id] || [];
                            tabsHtml += '<button type="button" class="dfn-drawer-day-pill ' + (isAct ? 'is-active' : '') + '" data-drawer-day-id="' + d.id + '">' +
                                '🗓️ ' + (d.short_label || d.day_label) + ' (' + pList.length + ')' +
                            '</button>';
                        });
                        tabsHtml += '</div>';
                        drawerDaySelector.innerHTML = tabsHtml;

                        drawerDaySelector.querySelectorAll('.dfn-drawer-day-pill').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                var targetDid = parseInt(this.getAttribute('data-drawer-day-id'), 10);
                                switchDayTab(targetDid);
                            });
                        });
                    } else {
                        drawerDaySelector.innerHTML = '<div class="dfn-drawer-active-day-banner">🗓️ ' + dayNameFormatted + '</div>';
                    }
                }

                if (drawerSubtitleTxt && curDay) {
                    drawerSubtitleTxt.innerHTML = 'Volontari disponibili nel sondaggio per <strong>' + (curDay.day_label || curDay.formatted_date) + '</strong> non ancora assegnati:';
                }

                var query = poolSearchInput ? poolSearchInput.value.toLowerCase().trim() : '';
                poolItemsContainer.innerHTML = '';

                if (poolList.length === 0) {
                    poolItemsContainer.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:12.5px;">✅ Tutti i volontari disponibili per <strong>' + (curDay ? (curDay.day_label || curDay.formatted_date) : 'questa giornata') + '</strong> sono stati assegnati!</div>';
                    return;
                }

                var filtered = poolList.filter(function(v) {
                    if (! query) return true;
                    var full = (v.first_name + ' ' + v.last_name).toLowerCase();
                    return full.indexOf(query) !== -1;
                });

                if (filtered.length === 0) {
                    poolItemsContainer.innerHTML = '<div style="padding:16px; text-align:center; color:#94a3b8; font-size:12px;">Nessun volontario trovato con questo nome.</div>';
                    return;
                }

                filtered.forEach(function(v) {
                    var card = document.createElement('div');
                    card.className = 'dfn-pool-card';
                    card.setAttribute('draggable', 'true');
                    card.setAttribute('data-volunteer-id', v.volunteer_id);
                    card.setAttribute('data-volunteer-name', v.first_name + ' ' + v.last_name);

                    var slotsBadges = (v.available_slots || []).map(function(s) {
                        return '<span class="dfn-pool-slot-badge" title="Fascia oraria data nel sondaggio">⏰ ' + formatSlotBadgeLabel(s) + '</span>';
                    }).join(' ');

                    var rolesBadges = (v.preferred_roles || []).map(function(r) {
                        return '<span class="dfn-pool-role-pref" title="Mansione preferita indicata nel sondaggio">🎭 ' + r + '</span>';
                    }).join(' ');

                    var skillIcons = '';
                    if (v.has_safety_course) skillIcons += '<span title="Corso Sicurezza Completato">🦺</span> ';
                    if (v.is_guide) skillIcons += '<span title="Abilitato come Guida FAI">🏛️</span> ';

                    var dayBadge = '<span class="dfn-pool-day-tag" title="Disponibilità registrata per questo giorno">🗓️ ' + (v.day_label || (curDay ? curDay.day_label : 'Giorno')) + '</span>';

                    var otherDaysBadge = '';
                    if (v.all_avail_days && v.all_avail_days.length > 1) {
                        otherDaysBadge = '<span class="dfn-pool-other-days-tag" title="Disponibile anche per altri giorni del sondaggio">📅 Disp: ' + v.all_avail_days.join(', ') + '</span>';
                    }

                    card.innerHTML = 
                        '<div class="dfn-pool-card-header">' +
                            '<strong class="dfn-pool-card-name">' + v.first_name + ' ' + v.last_name + ' ' + skillIcons + '</strong>' +
                            dayBadge +
                        '</div>' +
                        '<div class="dfn-pool-card-badges">' + slotsBadges + ' ' + rolesBadges + (otherDaysBadge ? ' ' + otherDaysBadge : '') + '</div>' +
                        '<div class="dfn-pool-card-footer-hint">⠿ Trascina sul turno desiderato</div>';

                    card.addEventListener('dragstart', function(e) {
                        e.dataTransfer.effectAllowed = 'copyMove';
                        e.dataTransfer.setData('text/plain', JSON.stringify({
                            source: 'pool',
                            volunteer_id: v.volunteer_id,
                            volunteer_name: v.first_name + ' ' + v.last_name,
                            day_id: activeDayId
                        }));
                        this.style.opacity = '0.5';
                    });

                    card.addEventListener('dragend', function() {
                        this.style.opacity = '1';
                    });

                    poolItemsContainer.appendChild(card);
                });
            }

            if (poolSearchInput) {
                poolSearchInput.addEventListener('input', renderPoolDrawerItems);
            }

            renderPoolDrawerItems();

            // 3. RICERCA LIVE E FILTRI
            var searchInput = document.getElementById('dfn-matrix-search-input');
            var searchClear = document.getElementById('dfn-matrix-search-clear');
            var roleFilter = document.getElementById('dfn-matrix-role-filter');

            function applyMatrixFilters() {
                var query = searchInput ? searchInput.value.toLowerCase().trim() : '';
                var selectedRole = roleFilter ? roleFilter.value : '';

                if (searchClear) searchClear.style.display = query ? 'inline-block' : 'none';

                var chips = document.querySelectorAll('.dfn-matrix-chip');
                chips.forEach(function(chip) {
                    var vName = (chip.getAttribute('data-volunteer-name') || '').toLowerCase();
                    var rKey  = chip.getAttribute('data-role-key') || '';

                    var matchQuery = ! query || (vName.indexOf(query) !== -1);
                    var matchRole  = ! selectedRole || (rKey === selectedRole);

                    chip.classList.toggle('is-role-hidden', ! matchRole);

                    if (query) {
                        chip.classList.toggle('is-search-highlight', matchQuery);
                        chip.classList.toggle('is-search-dimmed', ! matchQuery);
                    } else {
                        chip.classList.remove('is-search-highlight', 'is-search-dimmed');
                    }
                });
            }

            if (searchInput) searchInput.addEventListener('input', applyMatrixFilters);
            if (searchClear) {
                searchClear.addEventListener('click', function() {
                    searchInput.value = '';
                    applyMatrixFilters();
                    searchInput.focus();
                });
            }
            if (roleFilter) roleFilter.addEventListener('change', applyMatrixFilters);

            var expandAllBtn = document.getElementById('dfn-expand-all-btn');
            var collapseAllBtn = document.getElementById('dfn-collapse-all-btn');
            if (expandAllBtn) {
                expandAllBtn.addEventListener('click', function() {
                    document.querySelectorAll('.dfn-place-box-shifts').forEach(function(b) { b.style.display = 'flex'; });
                });
            }
            if (collapseAllBtn) {
                collapseAllBtn.addEventListener('click', function() {
                    document.querySelectorAll('.dfn-place-box-shifts').forEach(function(b) { b.style.display = 'none'; });
                });
            }

            // 4. MODALI CONTROLLER
            function openModal(modalId) {
                var m = document.getElementById(modalId);
                if (m) m.classList.add('is-open');
            }
            function closeModal(modalId) {
                var m = document.getElementById(modalId);
                if (m) m.classList.remove('is-open');
            }

            document.querySelectorAll('[data-close-modal]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var target = this.getAttribute('data-close-modal');
                    closeModal(target);
                });
            });

            document.querySelectorAll('.dfn-modal-backdrop').forEach(function(backdrop) {
                backdrop.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('is-open');
                    }
                });
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.dfn-modal-backdrop.is-open').forEach(function(m) {
                        m.classList.remove('is-open');
                    });
                    closeDrawer();
                }
            });

            // 5. MODALE SCHEDA VOLONTARIO (DOSSIER)
            var currentModalAssignmentId = 0;
            var currentModalVolunteerId = 0;

            function openVolunteerDossier(assId, volId) {
                currentModalAssignmentId = parseInt(assId, 10) || 0;
                currentModalVolunteerId  = parseInt(volId, 10) || 0;

                openModal('dfn-modal-volunteer-detail-backdrop');

                var avatarEl   = document.getElementById('dfn-dossier-avatar');
                var nameEl     = document.getElementById('dfn-dossier-name');
                var tagsEl     = document.getElementById('dfn-dossier-meta-tags');
                var cardNumEl  = document.getElementById('dfn-dossier-card-num');
                var safetyEl   = document.getElementById('dfn-dossier-safety-val');
                var guideEl    = document.getElementById('dfn-dossier-guide-val');
                var notesBox   = document.getElementById('dfn-dossier-notes-box');
                var notesTxt   = document.getElementById('dfn-dossier-notes-txt');
                var surveyList = document.getElementById('dfn-dossier-survey-list');
                var roleSelect = document.getElementById('dfn-dossier-role-select');
                var shiftSelect= document.getElementById('dfn-dossier-shift-select');

                nameEl.textContent = 'Caricamento dati...';
                tagsEl.innerHTML = '';
                surveyList.innerHTML = '<em>Caricamento...</em>';

                var formData = new FormData();
                formData.append('action', 'dfn_matrix_get_volunteer_detail');
                formData.append('security', ajaxNonce);
                formData.append('assignment_id', currentModalAssignmentId);
                formData.append('volunteer_id', currentModalVolunteerId);
                formData.append('event_id', currentEventId);

                fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(function(res) { return res.json(); })
                .then(function(response) {
                    if (! response.success) {
                        alert(response.data ? response.data.message : 'Errore nel caricamento del volontario.');
                        closeModal('dfn-modal-volunteer-detail-backdrop');
                        return;
                    }
                    var data = response.data;
                    var mem = data.member;
                    var ass = data.assignment;

                    var fullName = mem ? (mem.first_name + ' ' + mem.last_name) : (data.manual_name || 'Volontario');
                    nameEl.textContent = fullName;
                    
                    var initials = fullName.split(' ').map(function(n) { return n[0]; }).slice(0,2).join('').toUpperCase();
                    avatarEl.textContent = initials || 'VO';

                    tagsEl.innerHTML = '';
                    if (ass) {
                        tagsEl.innerHTML += '<span class="dfn-badge-counter dfn-badge-shifts">📍 ' + (ass.place_name || '') + ' - ' + (ass.shift_label || '') + '</span>';
                    }

                    // Tessera & Competenze
                    cardNumEl.textContent = (mem && mem.card_number) ? mem.card_number : 'Non registrata';
                    safetyEl.textContent  = (mem && parseInt(mem.has_safety_course, 10) === 1) ? '✅ Completato' : '❌ Non svolto';
                    guideEl.textContent   = (mem && parseInt(mem.is_guide, 10) === 1) ? '🏛️ Abilitato Guida' : '👤 No';

                    if (mem && mem.volunteer_notes) {
                        notesTxt.textContent = mem.volunteer_notes;
                        notesBox.style.display = 'block';
                    } else {
                        notesBox.style.display = 'none';
                    }

                    // Risposte Sondaggio
                    if (data.survey_responses && data.survey_responses.length > 0) {
                        surveyList.innerHTML = data.survey_responses.map(function(sr) {
                            return '<div class="dfn-survey-day-item">' +
                                '<strong>🗓️ ' + sr.day_label + ' (' + sr.event_date + '):</strong> ' +
                                '<span>Turno: <strong>' + sr.time_slot_key + '</strong></span>' +
                                (sr.preferred_role ? (' | Mansione preferita: <em>' + sr.preferred_role + '</em>') : '') +
                                (sr.notes ? (' | Note: <small>' + sr.notes + '</small>') : '') +
                            '</div>';
                        }).join('');
                    } else {
                        surveyList.innerHTML = '<em>Nessuna risposta registrata nel sondaggio per questo evento.</em>';
                    }

                    // Mansioni Select pulita (senza duplicazione di codici / parentesi)
                    roleSelect.innerHTML = '';
                    (data.roles || []).forEach(function(r) {
                        var opt = document.createElement('option');
                        opt.value = r.role_key;
                        var bCode = (r.badge_code || '').trim().replace(/^[\s()]+|[\s()]+$/g, '');
                        var rLabel = (r.role_name || '').replace(/\s*\(+[^)]*\)+$/, '');
                        if (bCode) {
                            rLabel += ' (' + bCode + ')';
                        }
                        opt.textContent = rLabel;
                        if (ass && ass.role_assigned === r.role_key) opt.selected = true;
                        roleSelect.appendChild(opt);
                    });

                    // Sposta Turno Select
                    shiftSelect.innerHTML = '<option value="">-- Seleziona Nuovo Turno / Luogo --</option>';
                    (data.shifts || []).forEach(function(s) {
                        if (ass && parseInt(ass.shift_id, 10) === parseInt(s.id, 10)) return;
                        var opt = document.createElement('option');
                        opt.value = s.id;
                        opt.textContent = s.day_label + ' ➔ ' + s.place_name + ' (' + s.shift_label + ' ' + s.time_start.substr(0,5) + '-' + s.time_end.substr(0,5) + ')';
                        shiftSelect.appendChild(opt);
                    });
                })
                .catch(function(err) {
                    alert('Errore di connessione durante il recupero dei dati.');
                    closeModal('dfn-modal-volunteer-detail-backdrop');
                });
            }

            document.addEventListener('click', function(e) {
                var chip = e.target.closest('.dfn-matrix-chip');
                if (chip) {
                    if (e.target.closest('.dfn-chip-del-btn')) return;
                    var assId = chip.getAttribute('data-assignment-id');
                    var volId = chip.getAttribute('data-volunteer-id');
                    openVolunteerDossier(assId, volId);
                }
            });

            // Salva Mansione da Modale Dossier
            var saveRoleBtn = document.getElementById('dfn-dossier-save-role-btn');
            if (saveRoleBtn) {
                saveRoleBtn.addEventListener('click', function() {
                    if (! currentModalAssignmentId) return;
                    var roleSelect = document.getElementById('dfn-dossier-role-select');
                    var newRole = roleSelect.value;
                    if (! newRole) return;

                    var fd = new FormData();
                    fd.append('action', 'dfn_matrix_update_role');
                    fd.append('security', ajaxNonce);
                    fd.append('assignment_id', currentModalAssignmentId);
                    fd.append('new_role', newRole);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(res) { return res.json(); })
                    .then(function(response) {
                        if (response.success) {
                            var chip = document.querySelector('.dfn-matrix-chip[data-assignment-id="' + currentModalAssignmentId + '"]');
                            if (chip) {
                                chip.setAttribute('data-role-key', response.data.role_key);
                                var pill = chip.querySelector('.dfn-chip-role-pill');
                                if (pill) {
                                    pill.textContent = response.data.badge_code;
                                    pill.style.background = response.data.badge_bg;
                                    pill.style.color = response.data.badge_color;
                                    pill.title = 'Ruolo: ' + response.data.role_name;
                                }
                            }
                            showToast('Mansione aggiornata con successo!');
                            closeModal('dfn-modal-volunteer-detail-backdrop');
                            fetchFreshConflicts();
                        } else {
                            alert(response.data ? response.data.message : 'Errore durante l\'aggiornamento.');
                        }
                    });
                });
            }

            // Sposta Turno da Modale Dossier
            var moveShiftBtn = document.getElementById('dfn-dossier-move-shift-btn');
            if (moveShiftBtn) {
                moveShiftBtn.addEventListener('click', function() {
                    if (! currentModalAssignmentId) return;
                    var shiftSelect = document.getElementById('dfn-dossier-shift-select');
                    var targetShiftId = shiftSelect.value;
                    if (! targetShiftId) {
                        alert('Seleziona un turno di destinazione.');
                        return;
                    }

                    var fd = new FormData();
                    fd.append('action', 'dfn_move_volunteer_shift');
                    fd.append('security', ajaxNonce);
                    fd.append('assignment_id', currentModalAssignmentId);
                    fd.append('target_shift_id', targetShiftId);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(res) { return res.json(); })
                    .then(function(response) {
                        if (response.success) {
                            var chip = document.querySelector('.dfn-matrix-chip[data-assignment-id="' + currentModalAssignmentId + '"]');
                            var newDropzone = document.querySelector('.dfn-shift-dropzone[data-shift-id="' + targetShiftId + '"]');
                            if (chip && newDropzone) {
                                var emptyMsg = newDropzone.querySelector('.dfn-dropzone-empty-msg');
                                if (emptyMsg) emptyMsg.remove();
                                newDropzone.appendChild(chip);
                                chip.setAttribute('data-shift-id', targetShiftId);
                            }
                            showToast('Volontario spostato con successo!');
                            closeModal('dfn-modal-volunteer-detail-backdrop');
                            fetchFreshConflicts();
                        } else {
                            alert(response.data ? response.data.message : 'Errore nello spostamento.');
                        }
                    });
                });
            }

            // Rimuovi Assegnazione da Modale Dossier
            var delAssBtn = document.getElementById('dfn-dossier-del-ass-btn');
            if (delAssBtn) {
                delAssBtn.addEventListener('click', function() {
                    if (! currentModalAssignmentId) return;
                    if (! confirm('Rimuovere questo volontario dal turno?')) return;

                    var fd = new FormData();
                    fd.append('action', 'dfn_matrix_remove_assignment');
                    fd.append('security', ajaxNonce);
                    fd.append('assignment_id', currentModalAssignmentId);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(res) { return res.json(); })
                    .then(function(response) {
                        if (response.success) {
                            var chip = document.querySelector('.dfn-matrix-chip[data-assignment-id="' + currentModalAssignmentId + '"]');
                            if (chip) {
                                var dz = chip.closest('.dfn-shift-dropzone');
                                chip.remove();
                                if (dz && ! dz.querySelector('.dfn-matrix-chip')) {
                                    dz.innerHTML = '<div class="dfn-dropzone-empty-msg">Nessun volontario assegnato. Trascina qui o clicca "+ Assegna".</div>';
                                }
                            }
                            showToast('Volontario rimosso dal turno.');
                            closeModal('dfn-modal-volunteer-detail-backdrop');
                            fetchFreshConflicts();
                        } else {
                            alert(response.data ? response.data.message : 'Errore durante la rimozione.');
                        }
                    });
                });
            }

            // Rimozione rapida da bottone ✕ sul chip
            document.addEventListener('click', function(e) {
                var delBtn = e.target.closest('.dfn-chip-del-btn');
                if (delBtn) {
                    e.stopPropagation();
                    var chip = delBtn.closest('.dfn-matrix-chip');
                    var assId = chip.getAttribute('data-assignment-id');
                    if (! confirm('Rimuovere questo volontario dal turno?')) return;

                    var fd = new FormData();
                    fd.append('action', 'dfn_matrix_remove_assignment');
                    fd.append('security', ajaxNonce);
                    fd.append('assignment_id', assId);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(res) { return res.json(); })
                    .then(function(response) {
                        if (response.success) {
                            var dz = chip.closest('.dfn-shift-dropzone');
                            chip.remove();
                            if (dz && ! dz.querySelector('.dfn-matrix-chip')) {
                                dz.innerHTML = '<div class="dfn-dropzone-empty-msg">Nessun volontario assegnato. Trascina qui o clicca "+ Assegna".</div>';
                            }
                            showToast('Volontario rimosso.');
                            fetchFreshConflicts();
                        }
                    });
                }
            });

            // 6. MODALE ASSEGNAZIONE RAPIDA (QUICK ASSIGN)
            var quickAssignBackdrop = document.getElementById('dfn-modal-quick-assign-backdrop');
            var qaSubtitle = document.getElementById('dfn-qa-subtitle');
            var qaShiftIdInput = document.getElementById('dfn-qa-shift-id');
            var qaDayIdInput = document.getElementById('dfn-qa-day-id');
            var qaVolIdInput = document.getElementById('dfn-qa-selected-vol-id');
            var qaVolSelect = document.getElementById('dfn-qa-vol-select');
            var qaSearchInput = document.getElementById('dfn-qa-vol-search');
            var qaResultsBox = document.getElementById('dfn-qa-vol-results');
            var qaBadge = document.getElementById('dfn-qa-selected-badge');
            var qaBadgeName = document.getElementById('dfn-qa-selected-name');
            var qaClearBtn = document.getElementById('dfn-qa-clear-selected-btn');

            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.dfn-btn-quick-assign');
                if (btn) {
                    var shiftId    = btn.getAttribute('data-shift-id');
                    var dayId      = btn.getAttribute('data-day-id');
                    var placeName  = btn.getAttribute('data-place-name');
                    var shiftLabel = btn.getAttribute('data-shift-label');

                    qaShiftIdInput.value = shiftId;
                    qaDayIdInput.value   = dayId;
                    qaSubtitle.textContent = placeName + ' — ' + shiftLabel;

                    qaVolIdInput.value = '';
                    qaSearchInput.value = '';
                    if (qaVolSelect) qaVolSelect.value = '';
                    qaBadge.style.display = 'none';
                    qaResultsBox.style.display = 'none';
                    document.getElementById('dfn-qa-manual-name').value = '';

                    // Evidenzia e formatta i volontari disponibili nel sondaggio per questo giorno
                    if (qaVolSelect) {
                        var targetDayId = parseInt(dayId, 10);
                        var poolList = dayPoolData[targetDayId] || [];
                        var poolIds = poolList.map(function(p) { return parseInt(p.volunteer_id, 10); });

                        Array.from(qaVolSelect.options).forEach(function(opt) {
                            if (! opt.value) return;
                            var vId = parseInt(opt.value, 10);
                            var origName = opt.getAttribute('data-name') || opt.textContent.replace(/^⭐\s*/, '').replace(/\s*\(Disponibile\)$/, '');
                            var isAvail = (poolIds.indexOf(vId) !== -1);
                            if (isAvail) {
                                opt.textContent = '⭐ ' + origName + ' (Disponibile)';
                                opt.style.fontWeight = '700';
                                opt.style.color = '#004b23';
                            } else {
                                opt.textContent = origName;
                                opt.style.fontWeight = 'normal';
                                opt.style.color = '';
                            }
                        });
                    }

                    openModal('dfn-modal-quick-assign-backdrop');
                    setTimeout(function() { qaSearchInput.focus(); }, 100);
                }
            });

            document.querySelectorAll('input[name="qa_mode"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    var isReg = (this.value === 'registered');
                    document.getElementById('dfn-qa-registered-section').style.display = isReg ? 'block' : 'none';
                    document.getElementById('dfn-qa-manual-section').style.display = isReg ? 'none' : 'block';
                });
            });

            if (qaVolSelect) {
                qaVolSelect.addEventListener('change', function() {
                    var vId = this.value;
                    if (vId) {
                        qaVolIdInput.value = vId;
                        var selectedOpt = this.options[this.selectedIndex];
                        var optName = selectedOpt.getAttribute('data-name') || selectedOpt.textContent.replace(/^⭐\s*/, '').replace(/\s*\(Disponibile\)$/, '');
                        qaBadgeName.textContent = 'Selezionato: ' + optName;
                        qaBadge.style.display = 'inline-flex';
                        qaResultsBox.style.display = 'none';
                        qaSearchInput.value = '';
                    } else {
                        qaVolIdInput.value = '';
                        qaBadge.style.display = 'none';
                    }
                });
            }

            if (qaSearchInput) {
                qaSearchInput.addEventListener('input', function() {
                    var q = this.value.toLowerCase().trim();
                    if (! q) {
                        qaResultsBox.style.display = 'none';
                        return;
                    }

                    var targetDayId = parseInt(qaDayIdInput.value, 10);
                    var poolList = dayPoolData[targetDayId] || [];
                    var poolIds = poolList.map(function(p) { return parseInt(p.volunteer_id, 10); });

                    var matches = allVolunteersData.filter(function(v) {
                        return v.name.toLowerCase().indexOf(q) !== -1;
                    });

                    qaResultsBox.innerHTML = '';
                    if (matches.length === 0) {
                        qaResultsBox.innerHTML = '<div style="padding:8px 10px; font-size:12px; color:#94a3b8;">Nessun volontario trovato.</div>';
                        qaResultsBox.style.display = 'block';
                        return;
                    }

                    matches.slice(0, 10).forEach(function(v) {
                        var item = document.createElement('div');
                        item.className = 'dfn-ac-item';
                        var isAvail = poolIds.indexOf(v.id) !== -1;

                        item.innerHTML = 
                            '<div>' +
                                '<strong>' + v.name + '</strong>' +
                                (v.has_safety_course ? ' <span title="Sicurezza">🦺</span>' : '') +
                                (v.is_guide ? ' <span title="Guida">🏛️</span>' : '') +
                            '</div>' +
                            (isAvail ? '<span class="dfn-ac-item-star">⭐ Disponibile nel sondaggio</span>' : '');

                        item.addEventListener('click', function() {
                            qaVolIdInput.value = v.id;
                            if (qaVolSelect) qaVolSelect.value = v.id;
                            qaBadgeName.textContent = 'Selezionato: ' + v.name;
                            qaBadge.style.display = 'inline-flex';
                            qaResultsBox.style.display = 'none';
                            qaSearchInput.value = '';
                        });

                        qaResultsBox.appendChild(item);
                    });

                    qaResultsBox.style.display = 'block';
                });
            }

            if (qaClearBtn) {
                qaClearBtn.addEventListener('click', function() {
                    qaVolIdInput.value = '';
                    if (qaVolSelect) qaVolSelect.value = '';
                    qaBadge.style.display = 'none';
                    qaSearchInput.value = '';
                    qaSearchInput.focus();
                });
            }

            var qaForm = document.getElementById('dfn-quick-assign-form');
            if (qaForm) {
                qaForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var shiftId = qaShiftIdInput.value;
                    var volId   = qaVolIdInput.value || (qaVolSelect ? qaVolSelect.value : '');
                    var mode    = document.querySelector('input[name="qa_mode"]:checked').value;
                    var manual  = document.getElementById('dfn-qa-manual-name').value;
                    var role    = document.getElementById('dfn-qa-role-select').value;

                    if (mode === 'registered' && ! volId) {
                        alert('Seleziona un volontario dall\'elenco o cercalo per nome.');
                        return;
                    }
                    if (mode === 'manual' && ! manual.trim()) {
                        alert('Inserisci il nome del volontario.');
                        return;
                    }

                    var fd = new FormData();
                    fd.append('action', 'dfn_matrix_quick_assign');
                    fd.append('security', ajaxNonce);
                    fd.append('shift_id', shiftId);
                    if (mode === 'registered') fd.append('volunteer_id', volId);
                    if (mode === 'manual') fd.append('volunteer_manual', manual);
                    fd.append('role_assigned', role);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(res) { return res.json(); })
                    .then(function(response) {
                        if (response.success) {
                            var dropzone = document.querySelector('.dfn-shift-dropzone[data-shift-id="' + shiftId + '"]');
                            if (dropzone) {
                                var emptyMsg = dropzone.querySelector('.dfn-dropzone-empty-msg');
                                if (emptyMsg) emptyMsg.remove();
                                dropzone.insertAdjacentHTML('beforeend', response.data.chip_html);
                            }
                            showToast('Volontario assegnato con successo!');
                            closeModal('dfn-modal-quick-assign-backdrop');
                            fetchFreshConflicts();
                        } else {
                            alert(response.data ? response.data.message : 'Errore nell\'assegnazione.');
                        }
                    })
                    .catch(function(err) {
                        alert('Errore di connessione.');
                    });
                });
            }

            // 7. MODALE AGGIUNGI LUOGO
            var openAddPlaceBtn = document.getElementById('dfn-open-add-place-modal-btn');
            if (openAddPlaceBtn) {
                openAddPlaceBtn.addEventListener('click', function() {
                    var apDayInput = document.getElementById('dfn-ap-day-id');
                    if (apDayInput) apDayInput.value = activeDayId;
                    openModal('dfn-modal-add-place-backdrop');
                });
            }

            // 8. MODALE MODIFICA / AGGIUNGI TURNO ORARIO
            document.addEventListener('click', function(e) {
                var editBtn = e.target.closest('.dfn-btn-edit-shift');
                if (editBtn) {
                    var shiftId   = editBtn.getAttribute('data-shift-id');
                    var label     = editBtn.getAttribute('data-shift-label');
                    var start     = editBtn.getAttribute('data-time-start');
                    var end       = editBtn.getAttribute('data-time-end');

                    document.getElementById('dfn-se-title').textContent = '⏰ Modifica Turno Orario';
                    document.getElementById('dfn-se-shift-id').value = shiftId;
                    document.getElementById('dfn-se-label').value = label;
                    document.getElementById('dfn-se-start').value = start;
                    document.getElementById('dfn-se-end').value = end;
                    document.getElementById('dfn-se-mode').value = 'edit';

                    openModal('dfn-modal-shift-editor-backdrop');
                }

                var addShiftBtn = e.target.closest('.dfn-add-shift-btn');
                if (addShiftBtn) {
                    var placeId = addShiftBtn.getAttribute('data-place-id');
                    var dayId   = addShiftBtn.getAttribute('data-day-id');

                    document.getElementById('dfn-se-title').textContent = '➕ Aggiungi Turno Orario';
                    document.getElementById('dfn-se-shift-id').value = '0';
                    document.getElementById('dfn-se-place-id').value = placeId;
                    document.getElementById('dfn-se-day-id').value = dayId;
                    document.getElementById('dfn-se-label').value = 'Turno';
                    document.getElementById('dfn-se-start').value = '09:00';
                    document.getElementById('dfn-se-end').value = '12:30';
                    document.getElementById('dfn-se-mode').value = 'add';

                    openModal('dfn-modal-shift-editor-backdrop');
                }
            });

            var shiftEditorForm = document.getElementById('dfn-shift-editor-form');
            if (shiftEditorForm) {
                shiftEditorForm.addEventListener('submit', function(e) {
                    var mode = document.getElementById('dfn-se-mode').value;
                    if (mode === 'edit') {
                        e.preventDefault();
                        var shiftId = document.getElementById('dfn-se-shift-id').value;
                        var label   = document.getElementById('dfn-se-label').value;
                        var start   = document.getElementById('dfn-se-start').value;
                        var end     = document.getElementById('dfn-se-end').value;

                        var fd = new FormData();
                        fd.append('action', 'dfn_matrix_edit_shift');
                        fd.append('security', ajaxNonce);
                        fd.append('shift_id', shiftId);
                        fd.append('shift_label', label);
                        fd.append('time_start', start);
                        fd.append('time_end', end);

                        fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(function(res) { return res.json(); })
                        .then(function(response) {
                            if (response.success) {
                                var card = document.getElementById('dfn-shift-card-' + shiftId);
                                if (card) {
                                    var lbl = card.querySelector('.dfn-shift-label-txt');
                                    var tm  = card.querySelector('.dfn-shift-time-txt');
                                    if (lbl) lbl.textContent = label;
                                    if (tm) tm.textContent = '(' + start + ' - ' + end + ')';
                                }
                                showToast('Orari turno aggiornati!');
                                closeModal('dfn-modal-shift-editor-backdrop');
                                fetchFreshConflicts();
                            } else {
                                alert(response.data ? response.data.message : 'Errore nel salvataggio.');
                            }
                        });
                    }
                });
            }

            // 9. DRAG & DROP ENGINE
            var draggedElement = null;

            document.addEventListener('dragstart', function(e) {
                var chip = e.target.closest('.dfn-matrix-chip');
                if (chip) {
                    draggedElement = chip;
                    chip.style.opacity = '0.4';
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', JSON.stringify({
                        source: 'chip',
                        assignment_id: chip.getAttribute('data-assignment-id'),
                        shift_id: chip.getAttribute('data-shift-id'),
                        volunteer_id: chip.getAttribute('data-volunteer-id'),
                        volunteer_key: chip.getAttribute('data-volunteer-key')
                    }));
                }
            });

            document.addEventListener('dragend', function(e) {
                if (draggedElement) {
                    draggedElement.style.opacity = '1';
                    draggedElement = null;
                }
                document.querySelectorAll('.dfn-shift-dropzone').forEach(function(dz) {
                    dz.classList.remove('is-drag-over');
                });
            });

            document.addEventListener('dragover', function(e) {
                var dz = e.target.closest('.dfn-shift-dropzone');
                if (! dz) return;
                e.preventDefault();
                dz.classList.add('is-drag-over');
            });

            document.addEventListener('dragleave', function(e) {
                var dz = e.target.closest('.dfn-shift-dropzone');
                if (dz && ! dz.contains(e.relatedTarget)) {
                    dz.classList.remove('is-drag-over');
                }
            });

            document.addEventListener('drop', function(e) {
                var dz = e.target.closest('.dfn-shift-dropzone');
                if (! dz) return;
                e.preventDefault();
                dz.classList.remove('is-drag-over');

                var rawData = e.dataTransfer.getData('text/plain');
                if (! rawData) return;

                var data = null;
                try { data = JSON.parse(rawData); } catch(err) { return; }

                var targetShiftId = dz.getAttribute('data-shift-id');

                if (data.source === 'chip') {
                    var assignmentId = data.assignment_id;
                    var oldShiftId   = data.shift_id;

                    if (oldShiftId === targetShiftId) return;

                    if (data.volunteer_key) {
                        var existing = dz.querySelector('.dfn-matrix-chip[data-volunteer-key="' + data.volunteer_key + '"]');
                        if (existing) {
                            alert('⚠️ Questo volontario è già presente in questo turno orario.');
                            return;
                        }
                    }

                    var cardToMove = document.querySelector('.dfn-matrix-chip[data-assignment-id="' + assignmentId + '"]');
                    var oldZone = cardToMove ? cardToMove.closest('.dfn-shift-dropzone') : null;

                    var emptyMsg = dz.querySelector('.dfn-dropzone-empty-msg');
                    if (emptyMsg) emptyMsg.remove();

                    if (cardToMove) {
                        dz.appendChild(cardToMove);
                        cardToMove.setAttribute('data-shift-id', targetShiftId);
                    }

                    if (oldZone && ! oldZone.querySelector('.dfn-matrix-chip')) {
                        oldZone.innerHTML = '<div class="dfn-dropzone-empty-msg">Nessun volontario assegnato. Trascina qui o clicca "+ Assegna".</div>';
                    }

                    var fd = new FormData();
                    fd.append('action', 'dfn_move_volunteer_shift');
                    fd.append('security', ajaxNonce);
                    fd.append('assignment_id', assignmentId);
                    fd.append('target_shift_id', targetShiftId);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(res) { return res.json(); })
                    .then(function(response) {
                        if (response.success) {
                            showToast('Volontario spostato nel turno!');
                            fetchFreshConflicts();
                        } else {
                            // Rollback visuale in caso di errore / conflitto orario
                            if (cardToMove && oldZone) {
                                var oldEmptyMsg = oldZone.querySelector('.dfn-dropzone-empty-msg');
                                if (oldEmptyMsg) oldEmptyMsg.remove();
                                oldZone.appendChild(cardToMove);
                                cardToMove.setAttribute('data-shift-id', oldShiftId);
                            }
                            if (dz && ! dz.querySelector('.dfn-matrix-chip')) {
                                dz.innerHTML = '<div class="dfn-dropzone-empty-msg">Nessun volontario assegnato. Trascina qui o clicca "+ Assegna".</div>';
                            }
                            alert(response.data ? response.data.message : 'Errore nello spostamento.');
                        }
                    })
                    .catch(function(err) {
                        if (cardToMove && oldZone) {
                            var oldEmptyMsg = oldZone.querySelector('.dfn-dropzone-empty-msg');
                            if (oldEmptyMsg) oldEmptyMsg.remove();
                            oldZone.appendChild(cardToMove);
                            cardToMove.setAttribute('data-shift-id', oldShiftId);
                        }
                        if (dz && ! dz.querySelector('.dfn-matrix-chip')) {
                            dz.innerHTML = '<div class="dfn-dropzone-empty-msg">Nessun volontario assegnato. Trascina qui o clicca "+ Assegna".</div>';
                        }
                        alert('Errore di connessione durante lo spostamento.');
                    });
                }

                if (data.source === 'pool') {
                    var volId = data.volunteer_id;
                    var existingChip = dz.querySelector('.dfn-matrix-chip[data-volunteer-id="' + volId + '"]');
                    if (existingChip) {
                        alert('⚠️ Questo volontario è già assegnato a questo turno.');
                        return;
                    }

                    var fd = new FormData();
                    fd.append('action', 'dfn_matrix_assign_from_pool');
                    fd.append('security', ajaxNonce);
                    fd.append('target_shift_id', targetShiftId);
                    fd.append('volunteer_id', volId);

                    fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(res) { return res.json(); })
                    .then(function(response) {
                        if (response.success) {
                            var emptyMsg = dz.querySelector('.dfn-dropzone-empty-msg');
                            if (emptyMsg) emptyMsg.remove();
                            dz.insertAdjacentHTML('beforeend', response.data.chip_html);
                            showToast('Volontario assegnato con successo!');
                            closeDrawer();
                            fetchFreshConflicts();
                        } else {
                            alert(response.data ? response.data.message : 'Errore nell\'assegnazione.');
                        }
                    })
                    .catch(function(err) {
                        alert('Errore di connessione.');
                    });
                }
            });
        });
        </script>
    </div>
    <?php
}


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

    // Mappa mansioni speciali e ruoli con vincolo di quota
    $safety_role_key   = null;
    $guide_role_key    = null;
    $resp_banchetto_key= null;
    $standard_role_keys = [];

    foreach ($event_roles as $er) {
        $rk = $er->role_key;
        if (! empty($er->requires_safety_course) && ! $safety_role_key) {
            $safety_role_key = $rk;
        } elseif (! empty($er->requires_guide) && ! $guide_role_key) {
            $guide_role_key = $rk;
        } elseif (stripos($rk, 'resp') !== false && stripos($rk, 'banch') !== false) {
            $resp_banchetto_key = $rk;
        } elseif (stripos($er->role_name, 'responsabile banchetto') !== false) {
            $resp_banchetto_key = $rk;
        } else {
            $standard_role_keys[] = $rk;
        }
    }

    // Se non ci sono altri ruoli standard, mantieni tutti i ruoli disponibili come fallback
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

    // Carica tutti i luoghi dell'evento per smart matching preferenze
    $all_event_places = function_exists('dfn_get_volunteer_event_all_places') ? dfn_get_volunteer_event_all_places((int) $survey->event_id) : [];

    // Carica tutte le risposte disponibili per il sondaggio una sola volta
    $all_responses = $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, f.first_name as fai_first_name, f.last_name as fai_last_name, f.is_guide, f.has_safety_course, f.volunteer_notes 
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

    // Helper: Determina la preferenza di luogo (campo strutturato o smart text matching sulle note con anti-negazione)
    $get_candidate_preferred_place_id = function(object $cand) use ($all_event_places): ?int {
        // 1. Preferenza esplicita strutturata (da campo dropdown)
        if (! empty($cand->preferred_place_id) && (int) $cand->preferred_place_id > 0) {
            $p_id = (int) $cand->preferred_place_id;
            foreach ($all_event_places as $ep) {
                if ((int) $ep->id === $p_id) {
                    return $p_id;
                }
            }
        }

        // 2. Smart text matching sulle note (della risposta al sondaggio o dell'anagrafica)
        $text_to_scan = strtolower(trim(($cand->notes ?? '') . ' ' . ($cand->volunteer_notes ?? '')));
        if (empty($text_to_scan) || empty($all_event_places)) {
            return null;
        }

        $stop_words = [
            'palazzo', 'chiesa', 'villa', 'castello', 'piazza', 'teatro', 'museo', 'parco', 'monastero', 'basilica',
            'santo', 'santa', 'san', 'della', 'delle', 'degli', 'dello', 'del', 'dei', 'presso', 'vicino', 'luogo', 'bene'
        ];

        foreach ($all_event_places as $ep) {
            $p_name = strtolower(trim($ep->place_name ?? ''));
            if (empty($p_name)) {
                continue;
            }

            // Estrai parole chiave significative del luogo (lunghezza >= 4 caratteri e non stop-words)
            $words = preg_split('/[\s,\-\'\"]+/', $p_name);
            $keywords = [];
            foreach ($words as $w) {
                $w = trim($w);
                if (strlen($w) >= 4 && ! in_array($w, $stop_words, true)) {
                    $keywords[] = $w;
                }
            }
            if (empty($keywords)) {
                $keywords = [$p_name];
            }

            foreach ($keywords as $kw) {
                $pos = strpos($text_to_scan, $kw);
                if ($pos !== false) {
                    // Controllo anti-negazione: verifica se nei 15 caratteri precedenti c'è una negazione
                    $prefix_start = max(0, $pos - 15);
                    $prefix_len = $pos - $prefix_start;
                    $prefix = substr($text_to_scan, $prefix_start, $prefix_len);
                    if (preg_match('/\b(non|no|evitare|mai|tranne|escluso)\b/i', $prefix)) {
                        continue; // Rilevata negazione (es. "no mirato", "non a mirato")
                    }
                    return (int) $ep->id;
                }
            }
        }

        return null;
    };

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

        // Mappa ancoraggio Luogo nel giorno: $vol_assigned_place_in_day[vol_id] = place_id
        // Garantisce che un volontario assegnato a un luogo (es. Mattina) rimanga nello stesso luogo anche negli altri turni del giorno
        $vol_assigned_place_in_day = [];

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
            /** @var list<int> $assigned_vols_in_current_slot */
            $assigned_vols_in_current_slot = [];

            // Helper di matching turno per luogo preferito
            $find_best_shift_for_vol = function(int $v_id, array $available_shifts, ?object $cand = null) use (&$vol_assigned_place_in_day, $get_candidate_preferred_place_id, $wpdb, $table_ass): ?object {
                if (empty($available_shifts)) {
                    return null;
                }
                // Priorità 1: Se il volontario è già stato assegnato a un luogo oggi, cerca lo shift di quel luogo
                if ($v_id > 0 && isset($vol_assigned_place_in_day[$v_id])) {
                    $target_pid = $vol_assigned_place_in_day[$v_id];
                    foreach ($available_shifts as $sh_item) {
                        if ((int) $sh_item->place_id === (int) $target_pid) {
                            return $sh_item;
                        }
                    }
                }

                // Calcola il conteggio attuale per ciascuno shift per bilanciamento e protezione da sovraccarico
                $shift_counts = [];
                $min_count = 9999;
                $best_sh = $available_shifts[0];
                foreach ($available_shifts as $sh_item) {
                    $cnt = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ass} WHERE shift_id = %d", $sh_item->id));
                    $shift_counts[$sh_item->id] = $cnt;
                    if ($cnt < $min_count) {
                        $min_count = $cnt;
                        $best_sh = $sh_item;
                    }
                }

                // Priorità 2: Smart Place Preference (da campo strutturato o da match semantico nelle note)
                if ($cand) {
                    $pref_pid = $get_candidate_preferred_place_id($cand);
                    if ($pref_pid) {
                        foreach ($available_shifts as $sh_item) {
                            if ((int) $sh_item->place_id === $pref_pid) {
                                $cur_cnt = $shift_counts[$sh_item->id] ?? 0;
                                // Protezione da sovrallocazione: assegna se non supera il minimo di oltre 2 volontari (o se tutti sono vuoti)
                                if ($cur_cnt <= $min_count + 2) {
                                    return $sh_item;
                                }
                            }
                        }
                    }
                }

                // Priorità 3: Altrimenti, scegli lo shift con meno assegnati per bilanciare il carico tra i luoghi
                return $best_sh;
            };

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
                        // Cerca prioritariamente volontari sicurezza già legati a questo luogo
                        $picked_idx = null;
                        foreach ($safety_volunteers as $s_idx => $s_cand) {
                            $c_vid = (int) $s_cand->volunteer_id;
                            if (isset($vol_assigned_place_in_day[$c_vid]) && (int) $vol_assigned_place_in_day[$c_vid] === (int) $shift->place_id) {
                                $picked_idx = $s_idx;
                                break;
                            }
                        }
                        // Poi cerca volontari con preferenza per questo luogo
                        if ($picked_idx === null) {
                            foreach ($safety_volunteers as $s_idx => $s_cand) {
                                $c_pref = $get_candidate_preferred_place_id($s_cand);
                                if ($c_pref && $c_pref === (int) $shift->place_id) {
                                    $picked_idx = $s_idx;
                                    break;
                                }
                            }
                        }
                        // Poi cerca uno non ancora vincolato ad un altro luogo
                        if ($picked_idx === null) {
                            foreach ($safety_volunteers as $s_idx => $s_cand) {
                                $c_vid = (int) $s_cand->volunteer_id;
                                if (! isset($vol_assigned_place_in_day[$c_vid])) {
                                    $picked_idx = $s_idx;
                                    break;
                                }
                            }
                        }
                        if ($picked_idx === null) {
                            $picked_idx = 0;
                        }

                        $picked = $safety_volunteers[$picked_idx];
                        array_splice($safety_volunteers, $picked_idx, 1);

                        $v_id = (int) $picked->volunteer_id;
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
                            $vol_assigned_place_in_day[$v_id] = (int) $shift->place_id;
                        }
                    }
                }
            }

            // 2. Assegnazione Guide (se abilitate per l'evento)
            if ($guide_role_key) {
                foreach ($shifts as $shift) {
                    if (! empty($guide_volunteers)) {
                        // Cerca prioritariamente guide già legate a questo luogo
                        $picked_idx = null;
                        foreach ($guide_volunteers as $g_idx => $g_cand) {
                            $c_vid = (int) $g_cand->volunteer_id;
                            if (isset($vol_assigned_place_in_day[$c_vid]) && (int) $vol_assigned_place_in_day[$c_vid] === (int) $shift->place_id) {
                                $picked_idx = $g_idx;
                                break;
                            }
                        }
                        // Poi cerca guide con preferenza per questo luogo
                        if ($picked_idx === null) {
                            foreach ($guide_volunteers as $g_idx => $g_cand) {
                                $c_pref = $get_candidate_preferred_place_id($g_cand);
                                if ($c_pref && $c_pref === (int) $shift->place_id) {
                                    $picked_idx = $g_idx;
                                    break;
                                }
                            }
                        }
                        // Poi cerca guide non ancora vincolate ad un altro luogo
                        if ($picked_idx === null) {
                            foreach ($guide_volunteers as $g_idx => $g_cand) {
                                $c_vid = (int) $g_cand->volunteer_id;
                                if (! isset($vol_assigned_place_in_day[$c_vid])) {
                                    $picked_idx = $g_idx;
                                    break;
                                }
                            }
                        }
                        if ($picked_idx === null) {
                            $picked_idx = 0;
                        }

                        $picked = $guide_volunteers[$picked_idx];
                        array_splice($guide_volunteers, $picked_idx, 1);

                        $v_id = (int) $picked->volunteer_id;
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
                            $vol_assigned_place_in_day[$v_id] = (int) $shift->place_id;
                        }
                    }
                }
            }

            // Pool di volontari rimanenti per i ruoli ordinari
            $remaining_pool = [];
            foreach (array_merge($safety_volunteers, $guide_volunteers, $general_volunteers) as $cand) {
                $c_vid = (int) $cand->volunteer_id;
                if ($c_vid > 0 && in_array($c_vid, $assigned_vols_in_current_slot, true)) {
                    continue;
                }
                $remaining_pool[] = $cand;
            }

            // 3. Assegnazione Esclusiva: Massimo 1 Responsabile Banchetto per ciascun turno/luogo
            if ($resp_banchetto_key) {
                foreach ($shifts as $shift) {
                    $has_resp_banco = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_ass} WHERE shift_id = %d AND role_assigned = %s",
                        $shift->id,
                        $resp_banchetto_key
                    ));

                    if ($has_resp_banco === 0 && ! empty($remaining_pool)) {
                        // Cerca prioritariamente chi è già stato in questo luogo
                        $picked_idx = null;
                        foreach ($remaining_pool as $r_idx => $r_cand) {
                            $c_vid = (int) $r_cand->volunteer_id;
                            if (isset($vol_assigned_place_in_day[$c_vid]) && (int) $vol_assigned_place_in_day[$c_vid] === (int) $shift->place_id) {
                                $picked_idx = $r_idx;
                                break;
                            }
                        }
                        // Poi cerca chi ha preferenza per questo luogo
                        if ($picked_idx === null) {
                            foreach ($remaining_pool as $r_idx => $r_cand) {
                                $c_pref = $get_candidate_preferred_place_id($r_cand);
                                if ($c_pref && $c_pref === (int) $shift->place_id) {
                                    $picked_idx = $r_idx;
                                    break;
                                }
                            }
                        }
                        // Poi cerca chi non è vincolato ad altri luoghi
                        if ($picked_idx === null) {
                            foreach ($remaining_pool as $r_idx => $r_cand) {
                                $c_vid = (int) $r_cand->volunteer_id;
                                if (! isset($vol_assigned_place_in_day[$c_vid])) {
                                    $picked_idx = $r_idx;
                                    break;
                                }
                            }
                        }
                        if ($picked_idx === null) {
                            $picked_idx = 0;
                        }

                        $picked = $remaining_pool[$picked_idx];
                        array_splice($remaining_pool, $picked_idx, 1);

                        $v_id = (int) $picked->volunteer_id;
                        if ($v_id > 0 && in_array($v_id, $assigned_vols_in_current_slot, true)) {
                            continue;
                        }

                        $wpdb->insert($table_ass, [
                            'shift_id'              => $shift->id,
                            'volunteer_id'          => $v_id ?: null,
                            'volunteer_name_manual' => ! $v_id ? ($picked->first_name . ' ' . $picked->last_name) : null,
                            'role_assigned'         => $resp_banchetto_key,
                            'created_at'            => current_time('mysql'),
                        ], [ '%d', '%d', '%s', '%s', '%s' ]);
                        $assigned_count++;
                        if ($v_id > 0) {
                            $assigned_vols_in_current_slot[] = $v_id;
                            $vol_assigned_place_in_day[$v_id] = (int) $shift->place_id;
                        }
                    }
                }
            }

            // 4. Distribuzione bilanciata di tutti i restanti volontari rispettando luogo del giorno e preferenze
            $role_index = 0;
            $num_roles  = count($standard_role_keys);

            while (! empty($remaining_pool)) {
                $picked = array_shift($remaining_pool);
                $v_id   = (int) $picked->volunteer_id;
                if ($v_id > 0 && in_array($v_id, $assigned_vols_in_current_slot, true)) {
                    continue;
                }

                // Trova il miglior turno per questo volontario: preferisce lo stesso luogo del giorno se già assegnato, o luogo preferito
                $shift = $find_best_shift_for_vol($v_id, $shifts, $picked);
                if (! $shift) {
                    $shift = $shifts[0];
                }

                $role = $standard_role_keys[$role_index % $num_roles];

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
                    $vol_assigned_place_in_day[$v_id] = (int) $shift->place_id;
                }

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

    // 1. Gestione Inserimento Manuale Disponibilità (Volontario Registrato o Nuovo Segnaposto)
    if (isset($_POST['dfn_add_manual_survey_response']) && wp_verify_nonce($_POST['dfn_manual_survey_nonce'] ?? '', 'dfn_add_manual_survey_action')) {
        if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_surveys')) && ! current_user_can('dfn_act_vol_surveys')) {
            wp_die(__('Permessi insufficienti.', 'dfn-theme'));
        }

        if (! $survey) {
            echo '<div class="notice notice-error is-dismissible"><p>⚠️ Salva prima le impostazioni del sondaggio per abilitare l\'inserimento delle risposte.</p></div>';
        } else {
            $entry_mode = sanitize_text_field($_POST['entry_mode'] ?? 'registered');
            $selected_slots = isset($_POST['selected_slots']) ? (array) $_POST['selected_slots'] : [];
            $operational_notes = sanitize_textarea_field($_POST['operational_notes'] ?? '');
            $manual_pref_place_id = isset($_POST['preferred_place_id']) && (int) $_POST['preferred_place_id'] > 0 ? (int) $_POST['preferred_place_id'] : null;

            if (empty($selected_slots)) {
                echo '<div class="notice notice-warning is-dismissible"><p>⚠️ Seleziona almeno una fascia oraria di disponibilità prima di salvare.</p></div>';
            } else {
                $vol_id = 0;
                $vol_name = '';

                if ($entry_mode === 'registered') {
                    $vol_id = (int) ($_POST['registered_volunteer_id'] ?? 0);
                    if ($vol_id > 0) {
                        $existing_m = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}dfn_fai_members WHERE id = %d", $vol_id));
                        if ($existing_m) {
                            $vol_name = $existing_m->first_name . ' ' . $existing_m->last_name;
                        }
                    }
                } else {
                    // Nuovo Segnaposto / Esterno
                    $first_name = sanitize_text_field($_POST['guest_first_name'] ?? '');
                    $last_name  = sanitize_text_field($_POST['guest_last_name'] ?? '');
                    $email      = sanitize_email($_POST['guest_email'] ?? '');
                    $phone      = sanitize_text_field($_POST['guest_phone'] ?? '');
                    $is_guide   = ! empty($_POST['guest_is_guide']) ? 1 : 0;
                    $has_safety = ! empty($_POST['guest_has_safety']) ? 1 : 0;
                    $guest_notes = sanitize_textarea_field($_POST['guest_notes'] ?? '');

                    if (! empty($first_name) && ! empty($last_name)) {
                        $notes_label = '👤 Segnaposto manuale per: ' . $event->title;
                        if (! empty($guest_notes)) {
                            $notes_label .= ' (' . $guest_notes . ')';
                        }
                        $wpdb->insert(
                            $wpdb->prefix . 'dfn_fai_members',
                            [
                                'first_name'          => $first_name,
                                'last_name'           => $last_name,
                                'email'               => $email ?: null,
                                'phone'               => $phone ?: null,
                                'is_volunteer'        => 1,
                                'volunteer_status'    => 'active',
                                'volunteer_notes'     => $notes_label,
                                'joined_date'         => current_time('Y-m-d'),
                                'is_guide'            => $is_guide,
                                'has_safety_course'   => $has_safety,
                                'is_sivol_registered' => 0,
                                'user_id'             => null,
                                'created_at'          => current_time('mysql'),
                                'updated_at'          => current_time('mysql'),
                            ],
                            [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ]
                        );
                        $vol_id = (int) $wpdb->insert_id;
                        $vol_name = $first_name . ' ' . $last_name;
                    }
                }

                if ($vol_id > 0) {
                    $slots_added = 0;
                    $slots_already_present = 0;
                    $slots_failed = 0;
                    $error_msgs = [];

                    foreach ($selected_slots as $slot_item) {
                        $parts = explode('_', $slot_item, 2);
                        if (count($parts) === 2) {
                            $day_id   = (int) $parts[0];
                            $slot_key = sanitize_key($parts[1]);

                            $existing_resp = $wpdb->get_row($wpdb->prepare(
                                "SELECT id, is_available FROM {$table_resp} WHERE survey_id = %d AND volunteer_id = %d AND day_id = %d AND time_slot_key = %s",
                                $survey->id, $vol_id, $day_id, $slot_key
                            ));

                            if ($existing_resp && (int) $existing_resp->is_available === 1) {
                                // Il volontario è già disponibile per questo turno: non sovrascrivere
                                $slots_already_present++;
                            } else {
                                $note_text = ! empty($operational_notes) ? '✍️ Inserimento manuale: ' . $operational_notes : '✍️ Inserimento manuale';
                                
                                if ($existing_resp && (int) $existing_resp->is_available === 0) {
                                    // Il volontario aveva indicato 'Non disponibile': aggiorniamo la riga a disponibile (is_available = 1)
                                    $update_data = [
                                        'is_available'       => 1,
                                        'preferred_place_id' => $manual_pref_place_id,
                                        'notes'              => $note_text,
                                        'submitted_at'       => current_time('mysql'),
                                    ];
                                    $update_fmt = [ '%d', '%d', '%s', '%s' ];
                                    $res = $wpdb->update($table_resp, $update_data, ['id' => (int) $existing_resp->id], $update_fmt, ['%d']);
                                    if ($res === false && strpos($wpdb->last_error, 'preferred_place_id') !== false) {
                                        unset($update_data['preferred_place_id']);
                                        $update_fmt = [ '%d', '%s', '%s' ];
                                        $res = $wpdb->update($table_resp, $update_data, ['id' => (int) $existing_resp->id], $update_fmt, ['%d']);
                                    }
                                } else {
                                    // Nuovo inserimento
                                    $insert_data = [
                                        'survey_id'          => $survey->id,
                                        'volunteer_id'       => $vol_id,
                                        'day_id'             => $day_id,
                                        'time_slot_key'      => $slot_key,
                                        'is_available'       => 1,
                                        'preferred_place_id' => $manual_pref_place_id,
                                        'notes'              => $note_text,
                                        'submitted_at'       => current_time('mysql'),
                                    ];
                                    $insert_fmt = [ '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' ];

                                    $res = $wpdb->insert($table_resp, $insert_data, $insert_fmt);

                                    if ($res === false && strpos($wpdb->last_error, 'preferred_place_id') !== false) {
                                        // Fallback if table lacks preferred_place_id column
                                        unset($insert_data['preferred_place_id']);
                                        $insert_fmt = [ '%d', '%d', '%d', '%s', '%d', '%s', '%s' ];
                                        $res = $wpdb->insert($table_resp, $insert_data, $insert_fmt);
                                    }
                                }

                                if ($res !== false) {
                                    $slots_added++;
                                } else {
                                    $slots_failed++;
                                    if (! empty($wpdb->last_error)) {
                                        $error_msgs[] = $wpdb->last_error;
                                    }
                                }
                            }
                        }
                    }

                    if ($slots_added > 0 && $slots_already_present === 0 && $slots_failed === 0) {
                        if (function_exists('dfn_log_write')) {
                            dfn_log_write('volontari', wp_get_current_user()->display_name, "Registrata disponibilità manuale per {$vol_name} ({$slots_added} turni) in {$event->title}", 'success');
                        }
                        echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>Disponibilità registrata per ' . esc_html($vol_name) . '!</strong> Aggiunto a ' . intval($slots_added) . ' turno/i del sondaggio.</p></div>';
                    } elseif ($slots_added > 0 && $slots_already_present > 0 && $slots_failed === 0) {
                        if (function_exists('dfn_log_write')) {
                            dfn_log_write('volontari', wp_get_current_user()->display_name, "Registrata disponibilità manuale per {$vol_name} ({$slots_added} nuovi turni, {$slots_already_present} già presenti) in {$event->title}", 'info');
                        }
                        echo '<div class="notice notice-info is-dismissible"><p>ℹ️ Disponibilità registrata per <strong>' . esc_html($vol_name) . '</strong> su <strong>' . intval($slots_added) . '</strong> nuovo/i turno/i. <strong>' . intval($slots_already_present) . '</strong> turno/i erano già presenti e <u>non sono stati sovrascritti</u>.</p></div>';
                    } elseif ($slots_added === 0 && $slots_already_present > 0 && $slots_failed === 0) {
                        echo '<div class="notice notice-warning is-dismissible"><p>⚠️ Il volontario <strong>' . esc_html($vol_name) . '</strong> è già presente negli slot orari selezionati. <u>Non è stato sovrascritto</u> e non è necessario aggiungerlo manualmente.</p></div>';
                    } elseif ($slots_failed > 0) {
                        $err_detail = ! empty($error_msgs) ? ' (' . implode(', ', array_unique($error_msgs)) . ')' : '';
                        echo '<div class="notice notice-error is-dismissible"><p>❌ Errore durante il salvataggio a database' . esc_html($err_detail) . '. Nessuna disponibilità salvata per <strong>' . esc_html($vol_name) . '</strong>.</p></div>';
                    }
                } elseif ($entry_mode === 'guest') {
                    echo '<div class="notice notice-error is-dismissible"><p>⚠️ Compila Nome e Cognome per il nuovo volontario / segnaposto.</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>⚠️ Seleziona un volontario valido dall\'elenco.</p></div>';
                }
            }
        }
    }

    // 2. Gestione Eliminazione Singola Disponibilità dal Turno del Sondaggio
    if (isset($_GET['delete_response'], $_GET['_wpnonce'])) {
        $del_resp_id = (int) $_GET['delete_response'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_resp_' . $del_resp_id)) {
            if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_surveys')) && ! current_user_can('dfn_act_vol_surveys')) {
                wp_die(__('Permessi insufficienti.', 'dfn-theme'));
            }
            $resp_to_del = $wpdb->get_row($wpdb->prepare(
                "SELECT r.*, f.first_name, f.last_name FROM {$table_resp} r LEFT JOIN {$wpdb->prefix}dfn_fai_members f ON r.volunteer_id = f.id WHERE r.id = %d",
                $del_resp_id
            ));
            $wpdb->delete($table_resp, ['id' => $del_resp_id], ['%d']);
            $v_name = $resp_to_del ? trim($resp_to_del->first_name . ' ' . $resp_to_del->last_name) : 'Volontario';
            if (function_exists('dfn_log_write')) {
                dfn_log_write('volontari', wp_get_current_user()->display_name, "Rimossa disponibilità turno per {$v_name} nel sondaggio {$event->title}", 'info');
            }
            echo '<div class="notice notice-success is-dismissible"><p>✅ Disponibilità di <strong>' . esc_html($v_name) . '</strong> rimossa con successo dal turno.</p></div>';
        }
    }

    // 3. Gestione Eliminazione Totale di un Volontario da Tutto il Sondaggio
    if (isset($_GET['delete_volunteer_survey'], $_GET['_wpnonce'])) {
        $del_vol_id = (int) $_GET['delete_volunteer_survey'];
        if (wp_verify_nonce($_GET['_wpnonce'], 'dfn_del_vol_survey_' . $del_vol_id)) {
            if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_surveys')) && ! current_user_can('dfn_act_vol_surveys')) {
                wp_die(__('Permessi insufficienti.', 'dfn-theme'));
            }
            if ($survey) {
                $v_info = $wpdb->get_row($wpdb->prepare("SELECT first_name, last_name FROM {$wpdb->prefix}dfn_fai_members WHERE id = %d", $del_vol_id));
                $del_rows = $wpdb->delete($table_resp, ['survey_id' => $survey->id, 'volunteer_id' => $del_vol_id], ['%d', '%d']);
                $v_name = $v_info ? trim($v_info->first_name . ' ' . $v_info->last_name) : 'Volontario';
                if (function_exists('dfn_log_write')) {
                    dfn_log_write('volontari', wp_get_current_user()->display_name, "Rimosse tutte le disponibilità ({$del_rows} turni) per {$v_name} nel sondaggio {$event->title}", 'info');
                }
                echo '<div class="notice notice-success is-dismissible"><p>✅ Tutte le disponibilità di <strong>' . esc_html($v_name) . '</strong> (' . intval($del_rows) . ' turni) sono state rimosse dal sondaggio.</p></div>';
            }
        }
    }

    $survey_link = $survey ? home_url('/sondaggio-volontari/?token=' . $survey->token_public) : '';
    $days = dfn_get_volunteer_event_days($event_id);

    // Recupera tutti i volontari registrati per il dropdown di selezione
    $all_registered_volunteers = $wpdb->get_results(
        "SELECT id, first_name, last_name, card_number, is_guide, has_safety_course, volunteer_notes, user_id 
         FROM {$wpdb->prefix}dfn_fai_members 
         WHERE is_volunteer = 1 OR volunteer_status IN ('active', 'pending') 
         ORDER BY last_name ASC, first_name ASC"
    );

    // Recupera solo le disponibilità positive (is_available = 1)
    $available_responses = $survey ? $wpdb->get_results($wpdb->prepare(
        "SELECT r.*, f.first_name, f.last_name, f.email, f.is_guide, f.has_safety_course, f.card_number, f.volunteer_notes, f.user_id 
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
        <header class="dfn-admin-header" style="margin-bottom:24px; display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px;">
            <div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics')); ?>" style="text-decoration:none; color:#004b23; font-weight:700;">← Torna agli eventi</a>
                <h1 style="font-size:24px; font-weight:700; color:#1d2327; margin:6px 0 0 0;">
                    📊 Gestione Sondaggio Disponibilità: <?php echo esc_html($event->title); ?>
                </h1>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=matrix&event_id=' . $event_id)); ?>" class="button button-secondary" style="font-weight:700;">
                    📋 Vai alla Matrice Turni
                </a>
                <?php if ($survey) : ?>
                    <button type="button" class="button button-primary" id="dfn-btn-open-manual-modal" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:4px 14px; box-shadow:0 2px 4px rgba(0,75,35,0.15);">
                        ➕ Aggiungi Disponibilità Manuale
                    </button>
                <?php endif; ?>
            </div>
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
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <h2 style="font-size:17px; font-weight:800; color:#0f172a; margin:0;">
                            ✅ Disponibilità Registrate (<?php echo count($available_responses); ?>)
                        </h2>
                        <span style="font-size:12px; color:#64748b; background:#f1f5f9; padding:4px 10px; border-radius:12px;">
                            I 'Non Disponibili' sono stati filtrati
                        </span>
                    </div>
                    <?php if ($survey) : ?>
                        <button type="button" class="button button-primary dfn-btn-trigger-manual-modal" style="background:#004b23; border-color:#003b1c; font-weight:700; font-size:12.5px;">
                            ➕ Inserisci Disponibilità Manuale
                        </button>
                    <?php endif; ?>
                </div>

                <?php 
                $active_days_with_shifts = 0;
                $active_days_data = [];
                if (! empty($days)) : ?>
                    <?php foreach ($days as $day) : 
                        // Recupera tutti gli shift configurati per questo giorno
                        $shifts_in_day = $wpdb->get_results($wpdb->prepare(
                            "SELECT DISTINCT shift_label, time_start, time_end FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC",
                            $day->id
                        ));

                        if (empty($shifts_in_day)) {
                            continue;
                        }
                        $active_days_with_shifts++;
                        $active_days_data[$day->id] = [
                            'day' => $day,
                            'shifts' => $shifts_in_day
                        ];
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
                                                    <th style="width:60px; text-align:right; font-weight:700;">Azioni</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (! empty($slot_resps)) : ?>
                                                    <?php foreach ($slot_resps as $r) : 
                                                        $is_manual = (stripos($r->notes ?? '', 'manuale') !== false);
                                                        $is_placeholder = (empty($r->user_id) && stripos($r->volunteer_notes ?? '', 'Segnaposto') !== false);
                                                        $del_resp_url = wp_nonce_url(admin_url('admin.php?page=dfn-volunteer-logistics&action=survey&event_id=' . $event_id . '&delete_response=' . $r->id), 'dfn_del_resp_' . $r->id);
                                                    ?>
                                                        <tr>
                                                            <td>
                                                                <div style="display:flex; align-items:center; flex-wrap:wrap; gap:5px;">
                                                                    <strong style="color:#0f172a; font-size:13px;">
                                                                        <?php echo esc_html($r->first_name . ' ' . $r->last_name); ?>
                                                                    </strong>
                                                                    <?php if ($is_placeholder) : ?>
                                                                        <span style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:4px; font-size:10px; font-weight:700; padding:1px 5px;" title="Volontario esterno o segnaposto inserito a mano">
                                                                            👤 Segnaposto
                                                                        </span>
                                                                    <?php elseif ($is_manual) : ?>
                                                                        <span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:4px; font-size:10px; font-weight:700; padding:1px 5px;" title="Disponibilità inserita manualmente dall'amministratore">
                                                                            ✍️ Manuale
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <?php if (! empty($r->card_number)) : ?>
                                                                    <span style="font-size:10.5px; color:#64748b;">Tessera: <?php echo esc_html($r->card_number); ?></span>
                                                                <?php endif; ?>
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
                                                            <td style="text-align:right;">
                                                                <a href="<?php echo esc_url($del_resp_url); ?>" onclick="return confirm('Sei sicuro di voler eliminare questa disponibilità dal turno?');" class="button button-small" style="color:#b91c1c; border-color:#fca5a5; font-size:11px; padding:1px 6px;" title="Rimuovi disponibilità">
                                                                    ✕
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else : ?>
                                                    <tr>
                                                        <td colspan="5" style="padding:14px; text-align:center; color:#94a3b8; font-style:italic;">
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

        <!-- ============================================================= -->
        <!-- MODALE INSERIMENTO MANUALE DISPONIBILITÀ (ISSUE #46)          -->
        <!-- ============================================================= -->
        <div id="dfn-manual-survey-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.65); z-index:100000; align-items:center; justify-content:center; padding:20px; backdrop-filter:blur(3px);">
            <div style="background:#fff; width:100%; max-width:680px; max-height:90vh; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.04); overflow:hidden; display:flex; flex-direction:column;">
                
                <!-- Modal Header -->
                <div style="padding:16px 22px; background:#004b23; color:#fff; display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span style="font-size:20px;">➕</span>
                        <h3 style="margin:0; font-size:16px; font-weight:800; color:#fff;">Aggiungi Disponibilità Manuale</h3>
                    </div>
                    <button type="button" id="dfn-btn-close-manual-modal" style="background:transparent; border:none; color:#fff; font-size:22px; cursor:pointer; line-height:1;">✕</button>
                </div>

                <!-- Modal Body with Scroll -->
                <div style="padding:22px; overflow-y:auto; flex:1;">
                    <form method="post" action="" id="dfn-form-manual-survey">
                        <?php wp_nonce_field('dfn_add_manual_survey_action', 'dfn_manual_survey_nonce'); ?>
                        <input type="hidden" name="dfn_add_manual_survey_response" value="1">
                        <input type="hidden" name="entry_mode" id="dfn_entry_mode" value="registered">

                        <!-- Tabs Mode Switcher -->
                        <div style="display:flex; gap:8px; margin-bottom:18px; border-bottom:2px solid #e2e8f0; padding-bottom:12px;">
                            <button type="button" class="dfn-tab-btn active" id="dfn-tab-registered" style="padding:8px 16px; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; border:1px solid #004b23; background:#004b23; color:#fff; transition:all 0.2s;">
                                👥 Volontario Registrato
                            </button>
                            <button type="button" class="dfn-tab-btn" id="dfn-tab-guest" style="padding:8px 16px; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; border:1px solid #cbd5e1; background:#f8fafc; color:#475569; transition:all 0.2s;">
                                👤 Nuovo Segnaposto / Esterno
                            </button>
                        </div>

                        <!-- TAB 1: Volontario Registrato -->
                        <div id="dfn-panel-registered" style="margin-bottom:18px;">
                            <label style="display:block; font-size:12.5px; font-weight:700; color:#1e293b; margin-bottom:6px;">
                                Seleziona Volontario dall'Anagrafica FAI <span style="color:#ef4444;">*</span>
                            </label>

                            <!-- Campo Ricerca Rapida Autocompletamento (Issue #51) -->
                            <div style="position:relative; margin-bottom:10px;">
                                <div style="display:flex; align-items:center; position:relative;">
                                    <span style="position:absolute; left:12px; font-size:14px; color:#64748b; pointer-events:none;">🔍</span>
                                    <input type="text" id="dfn_volunteer_search_input" placeholder="Cerca volontario (es. Vezzelli, Alex, 1330613, Guida...)" style="width:100%; border-radius:8px; border:1.5px solid #004b23; height:40px; padding:0 36px 0 36px; font-size:13.5px; font-weight:500; background:#f0fdf4; box-shadow:0 1px 2px rgba(0,75,35,0.08);" autocomplete="off">
                                    <button type="button" id="dfn_btn_clear_vol_search" style="display:none; position:absolute; right:10px; background:#e2e8f0; border:none; color:#475569; width:22px; height:22px; border-radius:50%; font-size:12px; cursor:pointer; line-height:22px; text-align:center; padding:0;" title="Cancella ricerca">✕</button>
                                </div>
                                <!-- Tendina Risultati Autocompletamento Dinamica -->
                                <div id="dfn_volunteer_autocomplete_list" style="display:none; position:absolute; top:44px; left:0; right:0; max-height:230px; overflow-y:auto; background:#ffffff; border:1.5px solid #cbd5e1; border-radius:8px; box-shadow:0 12px 24px -4px rgba(0,0,0,0.18), 0 4px 6px -2px rgba(0,0,0,0.05); z-index:100010;"></div>
                            </div>

                            <!-- Dropdown Master Sincronizzato -->
                            <div style="margin-bottom:6px;">
                                <select name="registered_volunteer_id" id="dfn_registered_volunteer_id" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px; font-size:13px; background:#fff;">
                                    <option value="">-- Oppure scegli dalla lista completa --</option>
                                    <?php if (! empty($all_registered_volunteers)) : ?>
                                        <?php foreach ($all_registered_volunteers as $v) : 
                                            $qual = [];
                                            if (! empty($v->is_guide)) $qual[] = 'Guida';
                                            if (! empty($v->has_safety_course)) $qual[] = 'Sicurezza';
                                            $qual_str = ! empty($qual) ? ' [' . implode(', ', $qual) . ']' : '';
                                            $card_str = ! empty($v->card_number) ? ' (Tessera: ' . $v->card_number . ')' : '';
                                        ?>
                                            <option value="<?php echo esc_attr($v->id); ?>" data-name="<?php echo esc_attr($v->last_name . ' ' . $v->first_name); ?>" data-card="<?php echo esc_attr($v->card_number ?? ''); ?>" data-guide="<?php echo ! empty($v->is_guide) ? '1' : '0'; ?>" data-safety="<?php echo ! empty($v->has_safety_course) ? '1' : '0'; ?>">
                                                <?php echo esc_html($v->last_name . ' ' . $v->first_name . $card_str . $qual_str); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <!-- Badge di Anteprima Volontario Selezionato -->
                            <div id="dfn_selected_volunteer_preview" style="display:none; margin-top:8px; padding:8px 12px; background:#f0fdf4; border:1.5px solid #86efac; border-radius:8px; font-size:12.5px; color:#166534; justify-content:space-between; align-items:center;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <span>👤 <strong>Volontario selezionato:</strong></span>
                                    <strong id="dfn_selected_vol_name" style="color:#0f172a; font-size:13px;"></strong>
                                    <span id="dfn_selected_vol_card" style="font-size:11px; color:#64748b;"></span>
                                </div>
                                <span id="dfn_selected_vol_badges" style="display:inline-flex; gap:4px;"></span>
                            </div>

                            <p style="font-size:11.5px; color:#64748b; margin:6px 0 0 0;">
                                💡 <strong>Tip:</strong> Inizia a digitare nel campo verde in alto per filtrare istantaneamente i volontari per nome, cognome o tessera.
                            </p>
                        </div>

                        <!-- TAB 2: Nuovo Segnaposto / Esterno -->
                        <div id="dfn-panel-guest" style="display:none; margin-bottom:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:4px;">Nome <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="guest_first_name" id="dfn_guest_first_name" placeholder="Es. Mario" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:13px;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:4px;">Cognome <span style="color:#ef4444;">*</span></label>
                                    <input type="text" name="guest_last_name" id="dfn_guest_last_name" placeholder="Es. Rossi" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:13px;">
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Email (Opzionale)</label>
                                    <input type="email" name="guest_email" placeholder="mario.rossi@example.com" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:12.5px;">
                                </div>
                                <div>
                                    <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Telefono (Opzionale)</label>
                                    <input type="tel" name="guest_phone" placeholder="333 1234567" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:12.5px;">
                                </div>
                            </div>

                            <div style="margin-bottom:12px;">
                                <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:6px;">Competenze &amp; Abilitazioni (Opzionali per l'algoritmo):</label>
                                <div style="display:flex; gap:16px; flex-wrap:wrap;">
                                    <label style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px; cursor:pointer; background:#fff; padding:6px 12px; border-radius:6px; border:1px solid #cbd5e1;">
                                        <input type="checkbox" name="guest_is_guide" value="1">
                                        <span>🗣️ Guida Culturale</span>
                                    </label>
                                    <label style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px; cursor:pointer; background:#fff; padding:6px 12px; border-radius:6px; border:1px solid #cbd5e1;">
                                        <input type="checkbox" name="guest_has_safety" value="1">
                                        <span>🛡️ Corso Sicurezza</span>
                                    </label>
                                </div>
                            </div>

                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Note Anagrafiche Segnaposto (Opzionale)</label>
                                <input type="text" name="guest_notes" placeholder="Es. Amico di Luisa, studente universitario" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:34px; padding:0 8px; font-size:12.5px;">
                            </div>
                        </div>

                        <!-- SELEZIONE FASCE ORARIE DISPONIBILI -->
                        <div style="margin-bottom:18px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                <label style="font-size:12.5px; font-weight:700; color:#1e293b;">
                                    🗓️ Seleziona Fasce di Disponibilità <span style="color:#ef4444;">*</span>
                                </label>
                                <button type="button" id="dfn-btn-toggle-all-slots" class="button button-small" style="font-size:11.5px; font-weight:600;">
                                    ✓ Seleziona Tutti i Turni
                                </button>
                            </div>

                            <?php if (! empty($active_days_data)) : ?>
                                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
                                    <?php foreach ($active_days_data as $day_id_k => $d_data) : 
                                        $d_obj = $d_data['day'];
                                        $d_shifts = $d_data['shifts'];
                                    ?>
                                        <div style="border:1px solid #cbd5e1; border-radius:8px; overflow:hidden; background:#fff;">
                                            <div style="background:#f1f5f9; padding:8px 12px; font-size:12px; font-weight:700; color:#0f172a; border-bottom:1px solid #cbd5e1;">
                                                📅 <?php echo esc_html($d_obj->day_label); ?>
                                            </div>
                                            <div style="padding:10px 12px; display:flex; flex-direction:column; gap:8px;">
                                                <?php foreach ($d_shifts as $sh) : 
                                                    $time_lbl = substr($sh->time_start, 0, 5) . ' - ' . substr($sh->time_end, 0, 5);
                                                    $slot_key = sanitize_key($sh->shift_label . '_' . substr($sh->time_start, 0, 5));
                                                    $val_key  = $day_id_k . '_' . $slot_key;
                                                ?>
                                                    <label style="display:flex; align-items:center; gap:8px; font-size:12.5px; cursor:pointer; background:#f8fafc; padding:6px 10px; border-radius:6px; border:1px solid #e2e8f0;">
                                                        <input type="checkbox" name="selected_slots[]" value="<?php echo esc_attr($val_key); ?>" class="dfn-modal-slot-checkbox">
                                                        <span><strong><?php echo esc_html($sh->shift_label); ?></strong> <span style="color:#64748b; font-size:11.5px;">(<?php echo esc_html($time_lbl); ?>)</span></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <div style="padding:12px; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; color:#b91c1c; font-size:12px;">
                                    Nessuno slot orario attivo trovato per questo evento.
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (function_exists('dfn_get_volunteer_setting') && dfn_get_volunteer_setting('vol_survey_enable_preferred_place', 'no') === 'yes') : 
                            $event_places = function_exists('dfn_get_volunteer_event_all_places') ? dfn_get_volunteer_event_all_places((int) $event_id) : [];
                            if (! empty($event_places)) : ?>
                                <div style="margin-bottom:18px;">
                                    <label style="display:block; font-size:12px; font-weight:700; color:#1e293b; margin-bottom:4px;">
                                        🏛️ Preferenza Luogo Desiderato (Opzionale)
                                    </label>
                                    <select name="preferred_place_id" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:13px; background:#fff;">
                                        <option value="">-- Nessuna preferenza / Indifferente --</option>
                                        <?php foreach ($event_places as $ep) : ?>
                                            <option value="<?php echo esc_attr($ep->id); ?>">
                                                <?php echo esc_html($ep->place_name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p style="font-size:11.5px; color:#64748b; margin:4px 0 0 0;">
                                        Se indicato, l'algoritmo di assegnazione darà la massima priorità a questo luogo per tutti i turni selezionati.
                                    </p>
                                </div>
                        <?php endif; endif; ?>

                        <!-- NOTE OPERATIVE -->
                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">
                                Note / Dettagli Disponibilità (Opzionale)
                            </label>
                            <textarea name="operational_notes" rows="2" placeholder="Es. Accordo verbale, preferisce luogo in centro o turno ridotto..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12.5px;"></textarea>
                        </div>

                        <!-- MODAL ACTIONS -->
                        <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f1f5f9; padding-top:14px;">
                            <button type="button" id="dfn-btn-cancel-manual-modal" class="button" style="font-weight:600;">
                                Annulla
                            </button>
                            <button type="submit" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; padding:4px 20px;">
                                💾 Registra Disponibilità
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Open / Close Modal
            $('#dfn-btn-open-manual-modal, .dfn-btn-trigger-manual-modal').on('click', function() {
                $('#dfn-manual-survey-modal').css('display', 'flex');
                setTimeout(function() {
                    if ($('#dfn-panel-registered').is(':visible')) {
                        $('#dfn_volunteer_search_input').focus();
                    }
                }, 100);
            });

            $('#dfn-btn-close-manual-modal, #dfn-btn-cancel-manual-modal').on('click', function() {
                $('#dfn-manual-survey-modal').hide();
                $('#dfn_volunteer_autocomplete_list').hide();
            });

            // Close on overlay click outside content
            $('#dfn-manual-survey-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).hide();
                    $('#dfn_volunteer_autocomplete_list').hide();
                }
            });

            // Tab Switching
            $('#dfn-tab-registered').on('click', function() {
                $('#dfn-tab-registered').css({ 'background':'#004b23', 'color':'#fff', 'border-color':'#004b23' });
                $('#dfn-tab-guest').css({ 'background':'#f8fafc', 'color':'#475569', 'border-color':'#cbd5e1' });
                $('#dfn-panel-registered').show();
                $('#dfn-panel-guest').hide();
                $('#dfn_entry_mode').val('registered');
                $('#dfn_registered_volunteer_id').prop('required', true);
                $('#dfn_guest_first_name, #dfn_guest_last_name').prop('required', false);
                $('#dfn_volunteer_search_input').focus();
            });

            $('#dfn-tab-guest').on('click', function() {
                $('#dfn-tab-guest').css({ 'background':'#004b23', 'color':'#fff', 'border-color':'#004b23' });
                $('#dfn-tab-registered').css({ 'background':'#f8fafc', 'color':'#475569', 'border-color':'#cbd5e1' });
                $('#dfn-panel-guest').show();
                $('#dfn-panel-registered').hide();
                $('#dfn_entry_mode').val('guest');
                $('#dfn_registered_volunteer_id').prop('required', false);
                $('#dfn_guest_first_name, #dfn_guest_last_name').prop('required', true);
                $('#dfn_guest_first_name').focus();
            });

            // =========================================================
            // AUTOCOMPLETE E RICERCA RAPIDA VOLONTARI (Issue #51)
            // =========================================================
            var $searchInput = $('#dfn_volunteer_search_input');
            var $autoList   = $('#dfn_volunteer_autocomplete_list');
            var $select     = $('#dfn_registered_volunteer_id');
            var $btnClear   = $('#dfn_btn_clear_vol_search');
            var $preview    = $('#dfn_selected_volunteer_preview');
            var $prevName   = $('#dfn_selected_vol_name');
            var $prevCard   = $('#dfn_selected_vol_card');
            var $prevBadges = $('#dfn_selected_vol_badges');

            // Parse all volunteer options into memory
            var volunteersData = [];
            $select.find('option').each(function() {
                var val = $(this).val();
                if (val) {
                    volunteersData.push({
                        id: val,
                        label: $(this).text().trim(),
                        name: $(this).data('name') || $(this).text().trim(),
                        card: String($(this).data('card') || ''),
                        guide: $(this).data('guide') == '1',
                        safety: $(this).data('safety') == '1'
                    });
                }
            });

            var highlightedIndex = -1;

            function renderAutocompleteResults(query) {
                query = (query || '').trim().toLowerCase();
                if (!query) {
                    $autoList.hide().empty();
                    $btnClear.hide();
                    return;
                }

                $btnClear.show();
                var qParts = query.split(/\s+/).filter(Boolean);

                var matches = volunteersData.filter(function(v) {
                    var searchable = (v.name + ' ' + v.card + (v.guide ? ' guida' : '') + (v.safety ? ' sicurezza' : '')).toLowerCase();
                    return qParts.every(function(part) {
                        return searchable.indexOf(part) !== -1;
                    });
                });

                if (matches.length === 0) {
                    $autoList.html('<div style="padding:12px 14px; color:#94a3b8; font-size:12.5px; font-style:italic; text-align:center;">Nessun volontario trovato per "<strong>' + $('<div>').text(query).html() + '</strong>"</div>').show();
                    highlightedIndex = -1;
                    return;
                }

                var html = '<div style="padding:6px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0; font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; display:flex; justify-content:space-between;">' +
                    '<span>' + matches.length + ' volontari trovati</span>' +
                    '<span style="font-size:10.5px; font-weight:normal; color:#94a3b8;">Usa ↑ ↓ Invio per selezionare</span>' +
                '</div>';
                
                matches.forEach(function(item, idx) {
                    var badges = '';
                    if (item.safety) {
                        badges += '<span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:4px; font-size:10px; font-weight:700; padding:1px 5px;">🛡️ Sicurezza</span> ';
                    }
                    if (item.guide) {
                        badges += '<span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:4px; font-size:10px; font-weight:700; padding:1px 5px;">🗣️ Guida</span> ';
                    }
                    var cardTxt = item.card ? '<span style="color:#64748b; font-size:11.5px; margin-left:6px;">(Tessera: ' + item.card + ')</span>' : '';

                    html += '<div class="dfn-autocomplete-item" data-id="' + item.id + '" data-idx="' + idx + '" style="padding:10px 14px; cursor:pointer; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; transition:background 0.15s;">' +
                        '<div><strong style="color:#0f172a; font-size:13px;">' + item.name + '</strong>' + cardTxt + '</div>' +
                        '<div>' + badges + '</div>' +
                    '</div>';
                });

                $autoList.html(html).show();
                highlightedIndex = -1;
            }

            function selectVolunteer(id, syncInput) {
                var found = volunteersData.find(function(v) { return v.id == id; });
                if (found) {
                    $select.val(found.id);
                    if (syncInput !== false) {
                        $searchInput.val(found.name);
                    }
                    $prevName.text(found.name);
                    $prevCard.text(found.card ? '(Tessera: ' + found.card + ')' : '');
                    
                    var bHtml = '';
                    if (found.safety) {
                        bHtml += '<span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:4px; font-size:10.5px; font-weight:700; padding:1px 6px;">🛡️ Sicurezza</span>';
                    }
                    if (found.guide) {
                        bHtml += '<span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:4px; font-size:10.5px; font-weight:700; padding:1px 6px;">🗣️ Guida</span>';
                    }
                    $prevBadges.html(bHtml);
                    $preview.css('display', 'flex');
                    $autoList.hide().empty();
                    $btnClear.show();
                } else {
                    $select.val('');
                    $preview.hide();
                }
            }

            // Live Input typing
            $searchInput.on('input', function() {
                var q = $(this).val();
                renderAutocompleteResults(q);
            });

            // Focus opens suggestions if query exists
            $searchInput.on('focus', function() {
                var q = $(this).val();
                if (q) {
                    renderAutocompleteResults(q);
                }
            });

            // Click item in list
            $autoList.on('click', '.dfn-autocomplete-item', function() {
                var id = $(this).data('id');
                selectVolunteer(id, true);
            });

            // Hover styling
            $autoList.on('mouseenter', '.dfn-autocomplete-item', function() {
                $('.dfn-autocomplete-item').css('background', '#fff');
                $(this).css('background', '#f0fdf4');
            });

            // Keyboard navigation
            $searchInput.on('keydown', function(e) {
                var items = $autoList.find('.dfn-autocomplete-item');
                if (!items.length) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIndex = (highlightedIndex + 1) >= items.length ? 0 : highlightedIndex + 1;
                    items.css('background', '#fff');
                    items.eq(highlightedIndex).css('background', '#f0fdf4')[0].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIndex = (highlightedIndex - 1) < 0 ? items.length - 1 : highlightedIndex - 1;
                    items.css('background', '#fff');
                    items.eq(highlightedIndex).css('background', '#f0fdf4')[0].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    if ($autoList.is(':visible') && items.length) {
                        e.preventDefault();
                        var targetIdx = highlightedIndex >= 0 ? highlightedIndex : 0;
                        var id = items.eq(targetIdx).data('id');
                        selectVolunteer(id, true);
                    }
                } else if (e.key === 'Escape') {
                    $autoList.hide();
                }
            });

            // Native select change
            $select.on('change', function() {
                var val = $(this).val();
                if (val) {
                    selectVolunteer(val, true);
                } else {
                    $preview.hide();
                    $searchInput.val('');
                    $btnClear.hide();
                }
            });

            // Clear search button
            $btnClear.on('click', function() {
                $searchInput.val('').focus();
                $select.val('');
                $autoList.hide().empty();
                $preview.hide();
                $(this).hide();
            });

            // Click outside to hide suggestions list
            $(document).on('click', function(e) {
                if (!$(e.target).closest('#dfn-panel-registered').length) {
                    $autoList.hide();
                }
            });

            // Toggle All Slots
            var allSelected = false;
            $('#dfn-btn-toggle-all-slots').on('click', function() {
                allSelected = !allSelected;
                $('.dfn-modal-slot-checkbox').prop('checked', allSelected);
                $(this).text(allSelected ? '✕ Deseleziona Tutti' : '✓ Seleziona Tutti i Turni');
            });

            // Validate at least one slot selected on submit
            $('#dfn-form-manual-survey').on('submit', function(e) {
                var checkedSlots = $('.dfn-modal-slot-checkbox:checked').length;
                if (checkedSlots === 0) {
                    alert('Seleziona almeno una fascia oraria di disponibilità prima di salvare.');
                    e.preventDefault();
                    return false;
                }
            });
        });
        </script>

        <!-- Overlay e Tooltip Modals Gestione Sondaggio -->
        <div class="dfn-tooltip-overlay" id="dfn-tooltip-overlay"></div>

        <div class="dfn-tooltip-modal" id="dfn-tip-survey-info" role="dialog" aria-modal="true" aria-labelledby="dfn-tip-survey-info-title">
            <div class="dfn-tooltip-modal-header">
                <h3 id="dfn-tip-survey-info-title">📋 Come Funziona il Sondaggio Disponibilità</h3>
                <button type="button" class="dfn-tooltip-modal-close" aria-label="Chiudi">×</button>
            </div>
            <div class="dfn-tooltip-modal-body">
                <p>Il sondaggio permette di raccogliere le preferenze orarie di ciascun volontario prima di comporre i turni:</p>
                <ul>
                    <li><strong>Generazione Automatica:</strong> il sondaggio acquisisce in tempo reale gli slot orari configurati nella <em>Matrice Turni</em>;</li>
                    <li><strong>Compilazione Volontario:</strong> quando lo stato è <em>Aperto</em>, ciascun volontario accede al link o all'area riservata e seleziona le fasce orarie in cui è disponibile;</li>
                    <li><strong>Inserimento Manuale:</strong> puoi aggiungere disponibilità per volontari registrati o creare segnaposto per persone esterne con il pulsante <em>➕ Aggiungi Disponibilità Manuale</em>;</li>
                    <li><strong>Chiusura e Assegnazione:</strong> al termine della scadenza o cliccando su <em>Chiudi Sondaggio</em>, l'algoritmo di assegnazione automatica userà queste risposte per popolare i turni.</li>
                </ul>
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
                                    /** @var array<string, list<string|null>> $grouped_by_role */
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

                                            /** @var array<string, list<string|null>> $grouped_by_role */
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

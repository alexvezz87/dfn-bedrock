<?php
/**
 * DFN Booking System 2.0 — Modulo Sondaggio Disponibilità Volontari FAI
 *
 * Gestisce la pagina/form di sondaggio pubblica e loggata per raccogliere
 * la disponibilità oraria dei volontari per le Giornate FAI e gli Eventi Locali.
 *
 * @package DFN_Theme
 * @since   2.4.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Registrazione shortcode per il sondaggio pubblico
add_shortcode('dfn_sondaggio_volontari', 'dfn_render_volunteer_survey_shortcode');

// Intercetta l'URL virtuale /sondaggio-volontari/
add_action('template_redirect', 'dfn_handle_volunteer_survey_page_rewrite');

/**
 * Intercetta le richieste dirette a /sondaggio-volontari/ e renderizza il template FAI.
 */
function dfn_handle_volunteer_survey_page_rewrite(): void
{
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = trim(parse_url($request_uri, PHP_URL_PATH) ?? '', '/');

    if ($path === 'sondaggio-volontari' || strpos($path, 'sondaggio-volontari') !== false) {
        global $wp_query;
        if ($wp_query) {
            $wp_query->is_404 = false;
            $wp_query->is_page = true;
        }
        status_header(200);

        // Titolo dinamico del documento per evitare "Pagina non trovata"
        add_filter('pre_get_document_title', function() {
            return 'Sondaggio Disponibilità Volontari — FAI Novara';
        }, 99);
        add_filter('wp_title', function() {
            return 'Sondaggio Disponibilità Volontari — FAI Novara';
        }, 99);

        get_header();
        echo '<div class="site-main dfn-survey-page-wrapper" style="min-height:70vh; padding: 40px 16px; background:#f8fafc;">';
        echo do_shortcode('[dfn_sondaggio_volontari]');
        echo '</div>';
        get_footer();
        exit;
    }
}

/**
 * Renderizza il form di sondaggio disponibilità.
 *
 * @param array<string, mixed> $atts Attributi shortcode.
 * @return string HTML del sondaggio.
 */
function dfn_render_volunteer_survey_shortcode($atts = []): string
{
    global $wpdb;

    $atts = shortcode_atts([
        'token' => isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '',
    ], $atts, 'dfn_sondaggio_volontari');

    $token = $atts['token'];
    if (empty($token)) {
        return '<div class="dfn-survey-alert error" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px; border-radius:8px; text-align:center; font-family:sans-serif;">'
             . '⚠️ <strong>Link del sondaggio non valido o mancante.</strong><br>Verifica il link ricevuto dalla Delegazione FAI.'
             . '</div>';
    }

    $survey = dfn_get_volunteer_survey_by_token($token);
    if (! $survey) {
        return '<div class="dfn-survey-alert error" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px; border-radius:8px; text-align:center; font-family:sans-serif;">'
             . '❌ <strong>Sondaggio non trovato.</strong><br>Questo sondaggio potrebbe essere stato rimosso o archiviato.'
             . '</div>';
    }

    $event = dfn_get_volunteer_event((int) $survey->event_id);
    if (! $event) {
        return '<div class="dfn-survey-alert error" style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:16px; border-radius:8px; text-align:center; font-family:sans-serif;">'
             . '❌ <strong>Evento non trovato.</strong>'
             . '</div>';
    }

    $now = current_time('mysql');
    $is_expired = ($survey->status === 'closed' || $survey->deadline_at < $now);

    // Recupero dati utente loggato (se presente)
    $current_user_id = get_current_user_id();
    $volunteer = null;
    $user_first_name = '';
    $user_last_name = '';
    $user_email = '';
    $user_phone = '';
    $user_notes = '';

    if ($current_user_id) {
        $user = wp_get_current_user();
        $volunteer = dfn_get_volunteer_by_user($current_user_id);
        if ($volunteer) {
            $user_first_name = $volunteer->first_name;
            $user_last_name  = $volunteer->last_name;
            $user_email      = $volunteer->email;
            $user_phone      = $volunteer->phone ?: '';
        } else {
            $user_first_name = $user->first_name ?: $user->display_name;
            $user_last_name  = $user->last_name ?: '';
            $user_email      = $user->user_email;
        }
    }

    // Giorni dell'evento
    $days = dfn_get_volunteer_event_days((int) $event->id);

    // Se l'utente ha già risposto in precedenza, recuperiamo le risposte
    $saved_responses = [];
    if ($volunteer) {
        $table_resp = $wpdb->prefix . 'dfn_volunteer_survey_responses';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_resp} WHERE survey_id = %d AND volunteer_id = %d",
            $survey->id,
            $volunteer->id
        ));
        foreach ($rows as $r) {
            $saved_responses[ $r->day_id . '_' . $r->time_slot_key ] = (int) $r->is_available;
            if (! empty($r->notes)) {
                $user_notes = $r->notes;
            }
        }
    }

    // Gestione invio form
    $feedback_msg = '';
    if (isset($_POST['dfn_submit_survey']) && wp_verify_nonce($_POST['dfn_survey_nonce'] ?? '', 'dfn_survey_submit_action')) {
        if ($is_expired) {
            $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">⚠️ Il termine per rispondere a questo sondaggio è scaduto.</div>';
        } else {
            $f_name  = sanitize_text_field($_POST['first_name'] ?? '');
            $l_name  = sanitize_text_field($_POST['last_name'] ?? '');
            $f_email = sanitize_email($_POST['email'] ?? '');
            $f_phone = sanitize_text_field($_POST['phone'] ?? '');
            $f_notes = sanitize_textarea_field($_POST['notes'] ?? '');
            $slots_selected = isset($_POST['slots']) && is_array($_POST['slots']) ? $_POST['slots'] : [];

            if (empty($f_name) || empty($l_name)) {
                $feedback_msg = '<div class="notice notice-error" style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:18px;">❌ Inserisci Nome e Cognome per registrarti.</div>';
            } else {
                $table_resp = $wpdb->prefix . 'dfn_volunteer_survey_responses';
                $vol_id = $volunteer ? (int) $volunteer->id : null;

                // Se non è collegato ad anagrafica ma c'è l'email, proviamo ad agganciare per email
                if (! $vol_id && ! empty($f_email)) {
                    $table_fai = $wpdb->prefix . 'dfn_fai_members';
                    $found_fai = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table_fai} WHERE email = %s AND is_volunteer = 1 LIMIT 1", $f_email));
                    if ($found_fai) {
                        $vol_id = (int) $found_fai->id;
                    }
                }

                // Rimuovi risposte precedenti per questo utente
                if ($vol_id) {
                    $wpdb->delete($table_resp, [ 'survey_id' => $survey->id, 'volunteer_id' => $vol_id ], [ '%d', '%d' ]);
                } elseif (! empty($f_email)) {
                    $wpdb->delete($table_resp, [ 'survey_id' => $survey->id, 'email' => $f_email ], [ '%d', '%s' ]);
                }

                // Inserisci le disponibilità per ciascun giorno e fascia
                foreach ($days as $day) {
                    // Recupera gli slot orari reali definiti per questo giorno
                    $day_shifts = $wpdb->get_results($wpdb->prepare(
                        "SELECT DISTINCT shift_label, time_start, time_end FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC",
                        $day->id
                    ));

                    if (empty($day_shifts)) {
                        $day_shifts = [
                            (object) ['shift_label' => 'Mattina', 'time_start' => '09:00:00', 'time_end' => '12:30:00'],
                            (object) ['shift_label' => 'Pomeriggio', 'time_start' => '14:00:00', 'time_end' => '18:00:00'],
                        ];
                    }

                    foreach ($day_shifts as $sh) {
                        $slot_k = sanitize_key($sh->shift_label . '_' . substr($sh->time_start, 0, 5));
                        $compound_key = $day->id . '_' . $slot_k;
                        $is_avail = ! empty($slots_selected[$compound_key]) ? 1 : 0;

                        $wpdb->insert(
                            $table_resp,
                            [
                                'survey_id'    => $survey->id,
                                'volunteer_id' => $vol_id,
                                'first_name'   => $f_name,
                                'last_name'    => $l_name,
                                'email'        => $f_email,
                                'phone'        => $f_phone,
                                'day_id'       => $day->id,
                                'time_slot_key'=> $slot_k,
                                'is_available' => $is_avail,
                                'notes'        => $f_notes,
                                'submitted_at' => current_time('mysql'),
                            ],
                            [ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
                        );

                        $saved_responses[$compound_key] = $is_avail;
                    }
                }

                $user_first_name = $f_name;
                $user_last_name  = $l_name;
                $user_email      = $f_email;
                $user_phone      = $f_phone;
                $user_notes      = $f_notes;

                $feedback_msg = '<div class="notice notice-success" style="background:#dcfce7; border:1px solid #86efac; color:#166534; padding:16px; border-radius:8px; margin-bottom:20px; text-align:center; font-weight:700;">'
                              . '🎉 Grazie! Le tue preferenze di disponibilità sono state registrate con successo.'
                              . '</div>';
            }
        }
    }

    $deadline_formatted = date_i18n('l d F Y \a\l\l\e H:i', strtotime($survey->deadline_at));

    ob_start();
    ?>
    <div class="dfn-survey-container" style="max-width: 680px; margin: 30px auto; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 28px; box-shadow: 0 10px 25px rgba(0,0,0,0.06); font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;">
        
        <!-- Header Sondaggio -->
        <div style="text-align: center; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 24px;">
            <span style="display:inline-block; font-size:12px; font-weight:800; text-transform:uppercase; color:#004b23; background:#e8f5e9; padding:4px 14px; border-radius:20px; margin-bottom:10px;">
                📋 Sondaggio Delegazione FAI Novara
            </span>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 8px 0; line-height: 1.3;">
                <?php echo esc_html($survey->title); ?>
            </h1>
            <p style="font-size: 14px; color: #64748b; margin: 0;">
                <?php echo esc_html($event->title); ?> • 🗓️ <?php echo esc_html(date_i18n('d/m/Y', strtotime($event->date_start))); ?> - <?php echo esc_html(date_i18n('d/m/Y', strtotime($event->date_end))); ?>
            </p>

            <div style="margin-top: 14px; display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: <?php echo $is_expired ? '#dc2626' : '#166534'; ?>; font-weight: 700; background: <?php echo $is_expired ? '#fef2f2' : '#f0fdf4'; ?>; padding: 6px 14px; border-radius: 10px; border: 1px solid <?php echo $is_expired ? '#fecaca' : '#bbf7d0'; ?>;">
                <span>⏱️</span>
                <?php if ($is_expired) : ?>
                    <span>Termine per le risposte SCADUTO (<?php echo esc_html($deadline_formatted); ?>)</span>
                <?php else : ?>
                    <span>Scadenza invio preferenze: <?php echo esc_html($deadline_formatted); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php echo $feedback_msg; ?>

        <?php if ($is_expired) : ?>
            <div style="background: #fef2f2; border: 1.5px solid #fecaca; border-left: 5px solid #dc2626; border-radius: 12px; padding: 16px 20px; margin-bottom: 24px;">
                <div style="font-size: 14px; font-weight: 800; color: #991b1b; margin-bottom: 4px; display: flex; align-items: center; gap: 6px;">
                    <span>🔒</span> Sondaggio Chiuso
                </div>
                <div style="font-size: 13px; color: #7f1d1d; line-height: 1.5;">
                    Le risposte per questo evento sono state chiuse per procedere con l'assegnazione dei turni.
                    <?php if (! empty($saved_responses)) : ?>
                        Di seguito puoi visualizzare il riepilogo delle disponibilità che hai inviato.
                    <?php else : ?>
                        Non risultano risposte registrate a tuo nome prima della chiusura del sondaggio.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (! $is_expired || ! empty($saved_responses)) : ?>
            <form method="post" action="" class="dfn-survey-form" style="<?php echo $is_expired ? 'opacity: 0.85;' : ''; ?>">
                <?php if (! $is_expired) : ?>
                    <?php wp_nonce_field('dfn_survey_submit_action', 'dfn_survey_nonce'); ?>
                <?php endif; ?>

                <!-- Dati Anagrafici -->
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 14px 0;">
                        👤 I tuoi dati
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 12px;">
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Nome <?php echo ! $is_expired ? '<span style="color:#ef4444;">*</span>' : ''; ?></label>
                            <input type="text" name="first_name" <?php echo $is_expired ? 'disabled readonly' : 'required'; ?> value="<?php echo esc_attr($user_first_name); ?>" placeholder="Es. Mario" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>">
                        </div>
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Cognome <?php echo ! $is_expired ? '<span style="color:#ef4444;">*</span>' : ''; ?></label>
                            <input type="text" name="last_name" <?php echo $is_expired ? 'disabled readonly' : 'required'; ?> value="<?php echo esc_attr($user_last_name); ?>" placeholder="Es. Rossi" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Email</label>
                            <input type="email" name="email" <?php echo $is_expired ? 'disabled readonly' : ''; ?> value="<?php echo esc_attr($user_email); ?>" placeholder="mario.rossi@email.it" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>">
                        </div>
                        <div>
                            <label style="display:block; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 4px;">Telefono</label>
                            <input type="tel" name="phone" <?php echo $is_expired ? 'disabled readonly' : ''; ?> value="<?php echo esc_attr($user_phone); ?>" placeholder="333 1234567" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; height: 40px; padding: 0 12px; font-size: 14px; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>">
                        </div>
                    </div>
                </div>

                <!-- Sezione Selezione Slot per Giorno Dinamici -->
                <div style="margin-bottom: 24px;">
                    <h3 style="font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0;">
                        📅 <?php echo $is_expired ? 'Le tue disponibilità inviate' : 'Seleziona le tue disponibilità'; ?>
                    </h3>
                    <p style="font-size: 13px; color: #64748b; margin: 0 0 16px 0;">
                        <?php echo $is_expired ? 'Riepilogo delle fasce orarie in cui hai indicato la tua disponibilità:' : 'Indica i turni orari in cui sei disponibile. Il luogo e l\'incarico a cui verrai assegnato saranno stabiliti dalla Delegazione.'; ?>
                    </p>

                    <div style="display: flex; flex-direction: column; gap: 14px;">
                        <?php foreach ($days as $day) : 
                            $d_time = strtotime($day->event_date);
                            $d_title = date_i18n('l d F Y', $d_time);

                            // Recupera i turni definiti per questo giorno
                            $day_shifts = $wpdb->get_results($wpdb->prepare(
                                "SELECT DISTINCT shift_label, time_start, time_end FROM {$wpdb->prefix}dfn_volunteer_event_shifts WHERE day_id = %d ORDER BY time_start ASC",
                                $day->id
                            ));

                            if (empty($day_shifts)) {
                                $day_shifts = [
                                    (object) ['shift_label' => 'Mattina', 'time_start' => '09:00:00', 'time_end' => '12:30:00'],
                                    (object) ['shift_label' => 'Pomeriggio', 'time_start' => '14:00:00', 'time_end' => '18:00:00'],
                                ];
                            }
                        ?>
                            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px;">
                                <div style="font-size: 14px; font-weight: 800; color: #004b23; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                                    <span>🗓️</span> <?php echo esc_html(ucfirst($d_title)); ?>
                                </div>

                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                    <?php foreach ($day_shifts as $sh) : 
                                        $slot_k = sanitize_key($sh->shift_label . '_' . substr($sh->time_start, 0, 5));
                                        $compound_key = $day->id . '_' . $slot_k;
                                        $is_checked = ! empty($saved_responses[$compound_key]);
                                        $time_range = substr($sh->time_start, 0, 5) . ' - ' . substr($sh->time_end, 0, 5);
                                    ?>
                                        <label style="display: flex; align-items: center; gap: 10px; background: <?php echo ($is_expired && $is_checked) ? '#e8f5e9' : '#ffffff'; ?>; border: 1.5px solid <?php echo $is_checked ? '#004b23' : '#cbd5e1'; ?>; padding: 12px 14px; border-radius: 10px; cursor: <?php echo $is_expired ? 'default' : 'pointer'; ?>; transition: all 0.2s;">
                                            <input type="checkbox" name="slots[<?php echo esc_attr($compound_key); ?>]" value="1" <?php checked($is_checked, true); ?> <?php echo $is_expired ? 'disabled' : ''; ?> style="width: 18px; height: 18px; <?php echo $is_expired ? 'cursor:default;' : ''; ?>">
                                            <div>
                                                <strong style="font-size: 13.5px; color: <?php echo ($is_expired && $is_checked) ? '#004b23' : '#0f172a'; ?>; display: block;">
                                                    <?php echo esc_html($sh->shift_label); ?>
                                                    <?php if ($is_expired && $is_checked) : ?>
                                                        <span style="font-size: 11px; color: #166534; font-weight: 800;">(Disponibile ✅)</span>
                                                    <?php endif; ?>
                                                </strong>
                                                <span style="font-size: 12px; color: #64748b;">(<?php echo esc_html($time_range); ?>)</span>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Note / Preferenze aggiuntive -->
                <div style="margin-bottom: 24px;">
                    <label style="display:block; font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px;">Note o preferenze speciali</label>
                    <textarea name="notes" rows="3" <?php echo $is_expired ? 'disabled readonly' : ''; ?> placeholder="<?php echo $is_expired ? 'Nessuna nota specificata' : 'Es. Preferenza per luogo specifico, disponibilità solo fino alle 17:00, in coppia con...'; ?>" style="width: 100%; border-radius: 8px; border: 1.5px solid #cbd5e1; padding: 10px; font-size: 13.5px; <?php echo $is_expired ? 'background:#f1f5f9; color:#475569; cursor:not-allowed;' : ''; ?>"><?php echo esc_textarea($user_notes); ?></textarea>
                </div>

                <!-- Pulsante Submit (solo se sondaggio aperto) -->
                <?php if (! $is_expired) : ?>
                    <button type="submit" name="dfn_submit_survey" class="button button-primary" style="background: #004b23; border: none; border-radius: 50px; color: #ffffff; font-weight: 800; font-size: 15px; width: 100%; padding: 14px 20px; cursor: pointer; box-shadow: 0 4px 15px rgba(0,75,35,0.25);">
                        💾 Invia la mia Disponibilità
                    </button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

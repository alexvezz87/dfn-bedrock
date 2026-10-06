<?php
/**
 * DFN Booking System 2.1 — Modulo Gestione Squadre & Team di Delegazione
 *
 * Pannello amministrativo per la gestione dei Team di lavoro FAI,
 * assegnazione dei Delegati Responsabili e composizione dei volontari per squadra.
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renderizza la pagina principale "Squadre & Team di Delegazione".
 *
 * @return void
 */
function dfn_render_teams_admin_page(): void
{
    if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_teams')) && ! current_user_can('dfn_act_vol_teams')) {
        wp_die(__('Permessi insufficienti per accedere alla gestione squadre.', 'dfn-theme'));
    }

    global $wpdb;
    $table_teams   = $wpdb->prefix . 'dfn_teams';
    $table_members = $wpdb->prefix . 'dfn_team_members';
    $table_fai     = $wpdb->prefix . 'dfn_fai_members';

    // Recupera tutte le squadre con conteggio membri
    $teams = function_exists('dfn_get_all_teams') ? dfn_get_all_teams(false) : [];

    // Statistiche KPI
    $total_teams   = count($teams);
    $active_teams  = 0;
    $total_members = (int) $wpdb->get_var("SELECT COUNT(DISTINCT member_id) FROM {$table_members}");
    
    foreach ($teams as $t) {
        if (! empty($t->is_active)) {
            $active_teams++;
        }
    }

    $stored_roles = function_exists('dfn_get_stored_roles') ? dfn_get_stored_roles() : [];
    $wp_users = get_users(['number' => 200, 'orderby' => 'display_name']);
    $all_volunteers = $wpdb->get_results("SELECT id, first_name, last_name, email, card_number, user_id FROM {$table_fai} WHERE is_volunteer = 1 ORDER BY last_name ASC, first_name ASC");

    ?>
    <div class="wrap dfn-admin-wrap" style="max-width: 1300px; margin: 20px 20px 40px 0; font-family: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        
        <!-- HEADER DELLA PAGINA -->
        <header class="dfn-admin-header" style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom: 24px;">
            <div>
                <h1 style="font-size:26px; font-weight:800; color:#0f172a; margin:0 0 6px 0; display:flex; align-items:center; gap:10px;">
                    <span>🛡️</span> <?php esc_html_e('Squadre & Team di Delegazione', 'dfn-theme'); ?>
                </h1>
                <p style="font-size:14px; color:#64748b; margin:0;">
                    <?php esc_html_e('Organizza i volontari in gruppi tematici (Ambiente, Eventi, Scuola, Comunicazione, Guide) e assegna i Delegati responsabili di ciascuna squadra.', 'dfn-theme'); ?>
                </p>
            </div>
            <div>
                <button type="button" class="button button-primary" id="dfn-btn-create-team" style="background:#004b23; border-color:#003b1c; font-size:13.5px; font-weight:700; padding:6px 18px; height:auto; border-radius:6px; display:inline-flex; align-items:center; gap:6px;">
                    <span>➕</span> <?php esc_html_e('Crea Nuova Squadra', 'dfn-theme'); ?>
                </button>
            </div>
        </header>

        <!-- KPI SUMMARY BAR -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
            <div style="background:#ffffff; border-radius:10px; border:1px solid #e2e8f0; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <div style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Totale Squadre</div>
                <div style="font-size:26px; font-weight:800; color:#0f172a;"><?php echo esc_html($total_teams); ?></div>
            </div>
            <div style="background:#ffffff; border-radius:10px; border:1px solid #e2e8f0; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <div style="font-size:12px; font-weight:700; color:#16a34a; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Squadre Attive</div>
                <div style="font-size:26px; font-weight:800; color:#16a34a;"><?php echo esc_html($active_teams); ?></div>
            </div>
            <div style="background:#ffffff; border-radius:10px; border:1px solid #e2e8f0; padding:16px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <div style="font-size:12px; font-weight:700; color:#2563eb; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Volontari Assegnati</div>
                <div style="font-size:26px; font-weight:800; color:#2563eb;"><?php echo esc_html($total_members); ?></div>
            </div>
        </div>

        <!-- LISTA SQUADRE CARD GRID -->
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:20px; margin-bottom:30px;">
            <?php if (! empty($teams)) : ?>
                <?php foreach ($teams as $t) : 
                    $sup_roles = [];
                    if (! empty($t->supervisor_roles)) {
                        $decoded = json_decode($t->supervisor_roles, true);
                        if (is_array($decoded)) {
                            $sup_roles = $decoded;
                        }
                    }

                    $direct_user = ! empty($t->supervisor_user_id) ? get_userdata((int) $t->supervisor_user_id) : null;
                    $is_active = (bool) $t->is_active;
                    $border_color = $t->color ?: '#004b23';
                ?>
                    <div class="dfn-team-card" style="background:#ffffff; border-radius:12px; border:1px solid #e2e8f0; border-top:5px solid <?php echo esc_attr($border_color); ?>; box-shadow:0 2px 5px rgba(0,0,0,0.05); display:flex; flex-direction:column; justify-content:space-between; overflow:hidden;">
                        <div style="padding:20px 22px;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <span style="font-size:26px; width:44px; height:44px; display:inline-flex; align-items:center; justify-content:center; border-radius:10px; background:<?php echo esc_attr($t->badge_bg ?: '#f0fdf4'); ?>; border:1px solid <?php echo esc_attr($border_color); ?>33;">
                                        <?php echo esc_html($t->icon ?: '👥'); ?>
                                    </span>
                                    <div>
                                        <h3 style="margin:0; font-size:17px; font-weight:800; color:#0f172a;">
                                            <?php echo esc_html($t->name); ?>
                                        </h3>
                                        <code style="font-size:11px; color:#64748b; background:#f1f5f9; padding:1px 6px; border-radius:4px;"><?php echo esc_html($t->slug); ?></code>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($is_active) : ?>
                                        <span style="background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:12px; font-size:11px; font-weight:700; padding:2px 8px;">Attiva</span>
                                    <?php else : ?>
                                        <span style="background:#f1f5f9; color:#94a3b8; border:1px solid #e2e8f0; border-radius:12px; font-size:11px; font-weight:600; padding:2px 8px;">Inattiva</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (! empty($t->description)) : ?>
                                <p style="font-size:13px; color:#475569; line-height:1.5; margin:0 0 16px 0;">
                                    <?php echo esc_html($t->description); ?>
                                </p>
                            <?php endif; ?>

                            <!-- SUPERVISORI / DELEGATI -->
                            <div style="background:#f8fafc; border-radius:8px; border:1px solid #f1f5f9; padding:12px 14px; margin-bottom:16px;">
                                <div style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; display:flex; align-items:center; gap:4px;">
                                    <span>⭐️</span> <?php esc_html_e('Delegati &amp; Responsabili', 'dfn-theme'); ?>
                                </div>
                                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                                    <?php if (! empty($sup_roles)) : ?>
                                        <?php foreach ($sup_roles as $sr_slug) : 
                                            $r_lbl = isset($stored_roles[$sr_slug]['label']) ? $stored_roles[$sr_slug]['label'] : ucfirst(str_replace(['dfn_', '_'], ['', ' '], $sr_slug));
                                        ?>
                                            <span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; border-radius:6px; font-size:11px; font-weight:700; padding:2px 7px;">
                                                🛡️ <?php echo esc_html($r_lbl); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <?php if ($direct_user) : ?>
                                        <span style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:6px; font-size:11px; font-weight:700; padding:2px 7px;">
                                            👤 <?php echo esc_html($direct_user->display_name); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (empty($sup_roles) && ! $direct_user) : ?>
                                        <span style="font-size:11.5px; color:#94a3b8; font-style:italic;">Nessun delegato specifico assegnato</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- CANALI DI COMUNICAZIONE (WHATSAPP & GOOGLE DRIVE) -->
                            <?php 
                            $has_wa = ! empty($t->whatsapp_url);
                            $has_dr = ! empty($t->drive_url);
                            $is_wa_share_enabled = function_exists('dfn_get_volunteer_setting') && dfn_get_volunteer_setting('vol_enable_whatsapp_share', 'no') === 'yes';
                            if ($has_wa || $has_dr) : ?>
                                <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px;">
                                    <?php if ($has_wa) : ?>
                                        <a href="<?php echo esc_url($t->whatsapp_url); ?>" target="_blank" rel="noopener noreferrer" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; border-radius:6px; font-size:11px; font-weight:700; padding:3px 8px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            <span>💬</span> Gruppo WhatsApp
                                        </a>
                                        <?php if ($is_wa_share_enabled) : 
                                            $del_name = function_exists('dfn_get_setting') ? dfn_get_setting('delegation_name', 'FAI Novara') : 'FAI Novara';
                                            $tpl = function_exists('dfn_get_volunteer_setting') ? dfn_get_volunteer_setting('vol_whatsapp_share_template', '') : '';
                                            $wa_msg = str_replace(['{squadra}', '{delegazione}', '{link_accesso}'], [$t->name, $del_name, home_url('/mio-account/')], $tpl);
                                            $wa_share_url = 'https://wa.me/?text=' . rawurlencode($wa_msg);
                                        ?>
                                            <a href="<?php echo esc_url($wa_share_url); ?>" target="_blank" rel="noopener noreferrer" style="background:#f0fdf4; color:#166534; border:1px dashed #86efac; border-radius:6px; font-size:10.5px; font-weight:600; padding:3px 6px; text-decoration:none; display:inline-flex; align-items:center; gap:3px;" title="Invia messaggio precompilato su WhatsApp">
                                                <span>📲</span> Condividi
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($has_dr) : ?>
                                        <a href="<?php echo esc_url($t->drive_url); ?>" target="_blank" rel="noopener noreferrer" style="background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; border-radius:6px; font-size:11px; font-weight:700; padding:3px 8px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                                            <span>📁</span> Google Drive
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- COMPONENTI CONTEGGIO -->
                            <div style="display:flex; justify-content:space-between; align-items:center; font-size:13px; color:#334155; font-weight:600;">
                                <span>👥 Componenti assegnati:</span>
                                <span style="background:#f1f5f9; padding:2px 10px; border-radius:12px; font-weight:800; color:#0f172a; border:1px solid #e2e8f0;">
                                    <?php echo (int) $t->members_count; ?> volontari
                                </span>
                            </div>
                        </div>

                        <!-- ACTIONS FOOTER -->
                        <div style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:12px 18px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                            <button type="button" 
                                    class="button dfn-btn-manage-members"
                                    data-team-id="<?php echo esc_attr($t->id); ?>"
                                    data-team-name="<?php echo esc_attr($t->name); ?>"
                                    data-team-icon="<?php echo esc_attr($t->icon ?: '👥'); ?>"
                                    data-team-color="<?php echo esc_attr($border_color); ?>"
                                    style="font-size:12.5px; font-weight:700; color:#004b23; border-color:#cbd5e1; display:inline-flex; align-items:center; gap:5px;">
                                <span>👥</span> <?php esc_html_e('Componenti', 'dfn-theme'); ?> (<?php echo (int) $t->members_count; ?>)
                            </button>

                            <div style="display:flex; gap:6px;">
                                <button type="button" 
                                        class="button button-small dfn-btn-edit-team"
                                        data-team-id="<?php echo esc_attr($t->id); ?>"
                                        data-team-name="<?php echo esc_attr($t->name); ?>"
                                        data-team-slug="<?php echo esc_attr($t->slug); ?>"
                                        data-team-desc="<?php echo esc_attr($t->description); ?>"
                                        data-team-icon="<?php echo esc_attr($t->icon ?: '👥'); ?>"
                                        data-team-color="<?php echo esc_attr($t->color ?: '#004b23'); ?>"
                                        data-team-bg="<?php echo esc_attr($t->badge_bg ?: '#f0fdf4'); ?>"
                                        data-team-whatsapp="<?php echo esc_attr($t->whatsapp_url ?? ''); ?>"
                                        data-team-drive="<?php echo esc_attr($t->drive_url ?? ''); ?>"
                                        data-team-sup-roles="<?php echo esc_attr($t->supervisor_roles ?: '[]'); ?>"
                                        data-team-sup-user="<?php echo esc_attr($t->supervisor_user_id ?: '0'); ?>"
                                        data-team-active="<?php echo esc_attr($t->is_active); ?>"
                                        data-team-order="<?php echo esc_attr($t->order_num); ?>"
                                        title="Modifica squadra">
                                    ✏️ Modifica
                                </button>
                                <button type="button" 
                                        class="button button-small dfn-btn-delete-team" 
                                        data-team-id="<?php echo esc_attr($t->id); ?>"
                                        data-team-name="<?php echo esc_attr($t->name); ?>"
                                        style="color:#b91c1c;"
                                        title="Elimina squadra">
                                    🗑️
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <div style="grid-column:1/-1; background:#ffffff; border-radius:10px; border:1px solid #e2e8f0; padding:36px; text-align:center; color:#64748b;">
                    <div style="font-size:32px; margin-bottom:8px;">🛡️</div>
                    <h3 style="font-size:16px; font-weight:700; color:#1e293b; margin:0 0 6px 0;">Nessuna Squadra Configurata</h3>
                    <p style="font-size:13px; margin:0 0 16px 0;">Crea le squadre di lavoro di delegazione per iniziare ad assegnare i volontari e i delegati responsabili.</p>
                    <button type="button" class="button button-primary" onclick="document.getElementById('dfn-btn-create-team').click();">➕ Crea Nuova Squadra</button>
                </div>
            <?php endif; ?>
        </div>

        <!-- =================================================================== -->
        <!-- MODAL 1: AGGIUNGI / MODIFICA SQUADRA                                 -->
        <!-- =================================================================== -->
        <div id="dfn-team-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.6); z-index:99999; backdrop-filter:blur(2px); align-items:center; justify-content:center;">
            <div style="background:#ffffff; border-radius:12px; width:100%; max-width:600px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); padding:26px 30px; position:relative; font-family:'Outfit', sans-serif;">
                
                <button type="button" id="dfn-btn-close-team-modal" style="position:absolute; top:18px; right:20px; background:none; border:none; font-size:20px; cursor:pointer; color:#64748b; font-weight:700;">&times;</button>
                
                <h2 id="dfn-team-modal-title" style="margin:0 0 18px 0; font-size:20px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <span>🛡️</span> Crea Nuova Squadra
                </h2>

                <form id="dfn-team-form">
                    <input type="hidden" name="team_id" id="dfn-field-team-id" value="0">

                    <!-- Nome & Icona -->
                    <div style="display:grid; grid-template-columns:1fr 80px; gap:12px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Nome Squadra <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="name" id="dfn-field-team-name" required placeholder="Es. Team Ambiente" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px; font-size:13.5px; font-weight:600;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Icona</label>
                            <input type="text" name="icon" id="dfn-field-team-icon" value="👥" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 6px; text-align:center; font-size:18px;">
                        </div>
                    </div>

                    <!-- Quick Emoji Selector -->
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; align-items:center;">
                        <span style="font-size:11px; color:#64748b; font-weight:600;">Icone rapide:</span>
                        <?php foreach (['🌱', '🎭', '🎓', '📢', '🏛️', '🌿', '📸', '🛡️', '👥', '🧭', '🎨', '💡'] as $emoji) : ?>
                            <button type="button" class="dfn-btn-quick-emoji" data-emoji="<?php echo esc_attr($emoji); ?>" style="background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; font-size:14px; cursor:pointer; padding:2px 6px;"><?php echo esc_html($emoji); ?></button>
                        <?php endforeach; ?>
                    </div>

                    <!-- Slug & Ordinamento -->
                    <div style="display:grid; grid-template-columns:1fr 100px; gap:12px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Slug Identificativo</label>
                            <input type="text" name="slug" id="dfn-field-team-slug" placeholder="Es. team-ambiente (auto)" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:12.5px; font-family:monospace;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Ordine</label>
                            <input type="number" name="order_num" id="dfn-field-team-order" value="0" min="0" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; text-align:center;">
                        </div>
                    </div>

                    <!-- Colori (Badge & Accent) -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Colore Testo / Bordo</label>
                            <input type="color" name="color" id="dfn-field-team-color" value="#004b23" style="width:100%; height:38px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; padding:2px;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Colore Sfondo Badge</label>
                            <input type="color" name="badge_bg" id="dfn-field-team-bg" value="#f0fdf4" style="width:100%; height:38px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; padding:2px;">
                        </div>
                    </div>

                    <!-- Descrizione -->
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Descrizione / Obiettivi della Squadra</label>
                        <textarea name="description" id="dfn-field-team-desc" rows="3" placeholder="Descrivi le attività e la finalità di questo team..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px; font-size:13px;"></textarea>
                    </div>

                    <!-- CANALI DI COMUNICAZIONE & DOCUMENTI -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:18px;">
                        <label style="display:block; font-size:13px; font-weight:800; color:#0f172a; margin-bottom:4px;">
                            🔗 Canali di Gruppo &amp; Spazio Documenti
                        </label>
                        <p style="font-size:12px; color:#64748b; margin:0 0 12px 0;">
                            Inserisci i link per consentire ai membri assegnati di accedere con 1 clic al gruppo WhatsApp e alla cartella condivisa Drive.
                        </p>

                        <div style="margin-bottom:12px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">
                                💬 Link Invito Gruppo WhatsApp
                            </label>
                            <input type="url" name="whatsapp_url" id="dfn-field-team-whatsapp" placeholder="https://chat.whatsapp.com/..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:12.5px;">
                        </div>

                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">
                                📁 Link Cartella Google Drive Condivisa
                            </label>
                            <input type="url" name="drive_url" id="dfn-field-team-drive" placeholder="https://drive.google.com/drive/folders/..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:12.5px;">
                        </div>
                    </div>

                    <!-- DELEGATI & RUOLI SUPERVISORI -->
                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:18px;">
                        <label style="display:block; font-size:13px; font-weight:800; color:#0f172a; margin-bottom:6px;">
                            ⭐️ Delegati Responsabili di Squadra
                        </label>
                        <p style="font-size:12px; color:#64748b; margin:0 0 10px 0;">
                            I volontari che possiedono uno dei seguenti ruoli o l'utente indicato avranno i permessi di coordinare questa squadra e convocarne le riunioni.
                        </p>

                        <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:12px;">
                            <?php 
                            foreach ($stored_roles as $r_slug => $r_info) : 
                                if ($r_slug === 'administrator' || $r_slug === 'dfn_volunteer') continue;
                            ?>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:12.5px; color:#1e293b;">
                                    <input type="checkbox" name="supervisor_roles[]" value="<?php echo esc_attr($r_slug); ?>" class="dfn-cb-sup-role" style="accent-color:#004b23;">
                                    <span><strong><?php echo esc_html($r_info['label']); ?></strong> (<code><?php echo esc_html($r_slug); ?></code>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div>
                            <label style="display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:4px;">Supervisore Diretto (Opzionale per Utente)</label>
                            <select name="supervisor_user_id" id="dfn-field-team-sup-user" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; height:36px; padding:0 10px; font-size:12.5px;">
                                <option value="0">-- Nessun utente specifico selezionato --</option>
                                <?php foreach ($wp_users as $u) : ?>
                                    <option value="<?php echo esc_attr($u->ID); ?>">
                                        <?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Stato Attivo -->
                    <div style="margin-bottom:20px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="is_active" id="dfn-field-team-active" value="1" checked style="accent-color:#004b23; width:18px; height:18px;">
                            <span style="font-size:13px; font-weight:700; color:#0f172a;">Squadra Attiva ed Operativa</span>
                        </label>
                    </div>

                    <div id="dfn-team-form-error" style="display:none; color:#dc2626; background:#fee2e2; border:1px solid #fca5a5; padding:8px 12px; border-radius:6px; font-size:12.5px; margin-bottom:16px;"></div>

                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" class="button" id="dfn-btn-cancel-team" style="border-radius:6px; padding:6px 16px;">Annulla</button>
                        <button type="submit" class="button button-primary" id="dfn-btn-save-team-submit" style="background:#004b23; border-color:#003b1c; font-weight:700; border-radius:6px; padding:6px 20px;">Salva Squadra</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- =================================================================== -->
        <!-- MODAL 2: GESTISCI COMPONENTI DELLA SQUADRA                           -->
        <!-- =================================================================== -->
        <div id="dfn-members-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.6); z-index:99999; backdrop-filter:blur(2px); align-items:center; justify-content:center;">
            <div style="background:#ffffff; border-radius:12px; width:100%; max-width:750px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 25px -5px rgba(0,0,0,0.2); padding:26px 30px; position:relative; font-family:'Outfit', sans-serif;">
                
                <button type="button" id="dfn-btn-close-members-modal" style="position:absolute; top:18px; right:20px; background:none; border:none; font-size:20px; cursor:pointer; color:#64748b; font-weight:700;">&times;</button>
                
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px;">
                    <span id="dfn-members-modal-team-icon" style="font-size:24px; width:40px; height:40px; display:inline-flex; align-items:center; justify-content:center; border-radius:8px; background:#f0fdf4;">👥</span>
                    <div>
                        <h2 id="dfn-members-modal-team-title" style="margin:0; font-size:20px; font-weight:800; color:#0f172a;">Componenti Squadra</h2>
                        <span style="font-size:12px; color:#64748b;">Gestisci i volontari appartenenti a questo team</span>
                    </div>
                </div>

                <!-- BOX AGGIUNGI RAPIDO COMPONENTE -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:20px;">
                    <label style="display:block; font-size:12.5px; font-weight:700; color:#0f172a; margin-bottom:6px;">
                        ➕ Aggiungi un Volontario a questo Team
                    </label>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <input type="hidden" id="dfn-current-manage-team-id" value="0">
                        <select id="dfn-select-add-member" style="flex:1; border-radius:6px; border:1px solid #cbd5e1; height:38px; padding:0 10px; font-size:13px;">
                            <option value="0">-- Seleziona un volontario dall'anagrafica --</option>
                            <?php foreach ($all_volunteers as $av) : ?>
                                <option value="<?php echo esc_attr($av->id); ?>" data-uid="<?php echo esc_attr($av->user_id ?: 0); ?>">
                                    <?php echo esc_html($av->last_name . ' ' . $av->first_name . ' (' . ($av->card_number ? 'Tessera: ' . $av->card_number : $av->email) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="dfn-btn-submit-add-member" class="button button-primary" style="background:#004b23; border-color:#003b1c; font-weight:700; height:38px; line-height:36px; border-radius:6px; white-space:nowrap; padding:0 16px;">
                            Assegna al Team
                        </button>
                    </div>
                </div>

                <!-- TABELLA COMPONENTI ATTUALI -->
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                    <table class="wp-list-table widefat fixed striped" style="border:none;" id="dfn-team-members-table">
                        <thead>
                            <tr>
                                <th style="font-weight:700;">Volontario</th>
                                <th style="font-weight:700; width:140px;">Tessera FAI</th>
                                <th style="font-weight:700; width:130px;">Competenze</th>
                                <th style="font-weight:700; width:120px;">Data Inserimento</th>
                                <th style="font-weight:700; width:80px; text-align:right;">Rimuovi</th>
                            </tr>
                        </thead>
                        <tbody id="dfn-team-members-tbody">
                            <tr>
                                <td colspan="5" style="text-align:center; padding:20px; color:#64748b;">Caricamento componenti...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px; text-align:right;">
                    <button type="button" class="button" id="dfn-btn-done-members-modal" style="border-radius:6px; padding:6px 18px; font-weight:700;">Chiudi</button>
                </div>
            </div>
        </div>

    </div>

    <!-- JS LOGIC PER LE SQUADRE -->
    <script>
    jQuery(document).ready(function($) {
        var ajaxurl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
        var nonce   = '<?php echo wp_create_nonce('dfn_teams_nonce'); ?>';

        // Auto-slug generator da nome
        $('#dfn-field-team-name').on('input', function() {
            var teamId = parseInt($('#dfn-field-team-id').val(), 10);
            if (!teamId || teamId === 0) {
                var val = $(this).val().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
                $('#dfn-field-team-slug').val(val);
            }
        });

        // Quick Emoji Click
        $('.dfn-btn-quick-emoji').on('click', function(e) {
            e.preventDefault();
            $('#dfn-field-team-icon').val($(this).data('emoji'));
        });

        // Apri Modal Crea Squadra
        $('#dfn-btn-create-team').on('click', function() {
            $('#dfn-team-modal-title').html('<span>🛡️</span> Crea Nuova Squadra');
            $('#dfn-field-team-id').val(0);
            $('#dfn-field-team-name').val('');
            $('#dfn-field-team-slug').val('');
            $('#dfn-field-team-icon').val('👥');
            $('#dfn-field-team-color').val('#004b23');
            $('#dfn-field-team-bg').val('#f0fdf4');
            $('#dfn-field-team-whatsapp').val('');
            $('#dfn-field-team-drive').val('');
            $('#dfn-field-team-desc').val('');
            $('#dfn-field-team-order').val('0');
            $('#dfn-field-team-sup-user').val('0');
            $('#dfn-field-team-active').prop('checked', true);
            $('.dfn-cb-sup-role').prop('checked', false);
            $('#dfn-team-form-error').hide().text('');
            $('#dfn-team-modal-overlay').css('display', 'flex');
        });

        // Apri Modal Modifica Squadra
        $('.dfn-btn-edit-team').on('click', function() {
            var btn = $(this);
            $('#dfn-team-modal-title').html('<span>✏️</span> Modifica Squadra: ' + btn.data('team-name'));
            $('#dfn-field-team-id').val(btn.data('team-id'));
            $('#dfn-field-team-name').val(btn.data('team-name'));
            $('#dfn-field-team-slug').val(btn.data('team-slug'));
            $('#dfn-field-team-icon').val(btn.data('team-icon'));
            $('#dfn-field-team-color').val(btn.data('team-color'));
            $('#dfn-field-team-bg').val(btn.data('team-bg'));
            $('#dfn-field-team-whatsapp').val(btn.data('team-whatsapp') || '');
            $('#dfn-field-team-drive').val(btn.data('team-drive') || '');
            $('#dfn-field-team-desc').val(btn.data('team-desc'));
            $('#dfn-field-team-order').val(btn.data('team-order'));
            $('#dfn-field-team-sup-user').val(btn.data('team-sup-user'));
            $('#dfn-field-team-active').prop('checked', parseInt(btn.data('team-active'), 10) === 1);

            // Popola checkbox supervisor roles
            $('.dfn-cb-sup-role').prop('checked', false);
            var roles = btn.data('team-sup-roles');
            if (roles && typeof roles === 'string') {
                try {
                    roles = JSON.parse(roles);
                } catch(e) {
                    roles = [];
                }
            }
            if (Array.isArray(roles)) {
                roles.forEach(function(r) {
                    $('.dfn-cb-sup-role[value="' + r + '"]').prop('checked', true);
                });
            }

            $('#dfn-team-form-error').hide().text('');
            $('#dfn-team-modal-overlay').css('display', 'flex');
        });

        // Chiudi Modal Squadra
        $('#dfn-btn-close-team-modal, #dfn-btn-cancel-team').on('click', function() {
            $('#dfn-team-modal-overlay').hide();
        });

        // Submit Salvataggio Squadra
        $('#dfn-team-form').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            var submitBtn = $('#dfn-btn-save-team-submit');
            var errorBox = $('#dfn-team-form-error');

            errorBox.hide();
            submitBtn.prop('disabled', true).text('Salvataggio...');

            var formData = form.serializeArray();
            formData.push({ name: 'action', value: 'dfn_save_team_ajax' });
            formData.push({ name: 'nonce', value: nonce });

            $.post(ajaxurl, formData, function(res) {
                submitBtn.prop('disabled', false).text('Salva Squadra');
                if (res.success) {
                    location.reload();
                } else {
                    errorBox.text(res.data || 'Errore durante il salvataggio.').show();
                }
            }).fail(function() {
                submitBtn.prop('disabled', false).text('Salva Squadra');
                errorBox.text('Errore di comunicazione con il server.').show();
            });
        });

        // Elimina Squadra
        $('.dfn-btn-delete-team').on('click', function() {
            var btn = $(this);
            var teamId = btn.data('team-id');
            var teamName = btn.data('team-name');

            if (!confirm('Sei sicuro di voler eliminare la squadra "' + teamName + '"?\nLe associazioni dei volontari verranno rimosse (i volontari rimarranno in anagrafica).')) {
                return;
            }

            btn.prop('disabled', true);
            $.post(ajaxurl, {
                action: 'dfn_delete_team_ajax',
                team_id: teamId,
                nonce: nonce
            }, function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.data || 'Errore durante l\'eliminazione della squadra.');
                    btn.prop('disabled', false);
                }
            }).fail(function() {
                alert('Errore di connessione.');
                btn.prop('disabled', false);
            });
        });

        // -------------------------------------------------------------
        // GESTIONE COMPONENTI SQUADRA (MODAL 2)
        // -------------------------------------------------------------
        function loadTeamMembers(teamId) {
            var tbody = $('#dfn-team-members-tbody');
            tbody.html('<tr><td colspan="5" style="text-align:center; padding:20px; color:#64748b;">Caricamento componenti...</td></tr>');

            $.get(ajaxurl, {
                action: 'dfn_get_team_members_ajax',
                team_id: teamId,
                nonce: nonce
            }, function(res) {
                if (res.success && res.data && res.data.length > 0) {
                    var html = '';
                    res.data.forEach(function(m) {
                        var cardHtml = m.card_number ? '<code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-weight:600;">' + m.card_number + '</code>' : '<span style="color:#94a3b8; font-size:11.5px;">—</span>';
                        var badgesHtml = '';
                        if (parseInt(m.is_guide, 10) === 1) {
                            badgesHtml += '<span style="background:#e0f2fe; color:#0369a1; border-radius:8px; font-size:10.5px; font-weight:700; padding:1px 6px; margin-right:4px;">🏛️ Guida</span>';
                        }
                        if (parseInt(m.has_safety_course, 10) === 1) {
                            badgesHtml += '<span style="background:#fef3c7; color:#b45309; border-radius:8px; font-size:10.5px; font-weight:700; padding:1px 6px;">🦺 Sicurezza</span>';
                        }
                        if (!badgesHtml) badgesHtml = '<span style="color:#94a3b8; font-size:11.5px;">—</span>';

                        var joinedDate = m.joined_at ? m.joined_at.substring(0, 10) : '—';

                        html += '<tr>' +
                            '<td><strong style="color:#0f172a; font-size:13px;">' + m.last_name + ' ' + m.first_name + '</strong><br><span style="font-size:11px; color:#64748b;">' + (m.email || '') + '</span></td>' +
                            '<td>' + cardHtml + '</td>' +
                            '<td>' + badgesHtml + '</td>' +
                            '<td style="font-size:12px; color:#64748b;">' + joinedDate + '</td>' +
                            '<td style="text-align:right;"><button type="button" class="button button-small dfn-btn-remove-member" data-team-id="' + teamId + '" data-member-id="' + m.id + '" data-name="' + m.first_name + ' ' + m.last_name + '" style="color:#b91c1c;">✕</button></td>' +
                        '</tr>';
                    });
                    tbody.html(html);
                } else {
                    tbody.html('<tr><td colspan="5" style="text-align:center; padding:24px; color:#64748b;">Nessun volontario attualmente assegnato a questa squadra. Usa il campo sopra per aggiungerne uno.</td></tr>');
                }
            }).fail(function() {
                tbody.html('<tr><td colspan="5" style="text-align:center; padding:20px; color:#dc2626;">Errore nel recupero dei componenti.</td></tr>');
            });
        }

        $('.dfn-btn-manage-members').on('click', function() {
            var btn = $(this);
            var teamId = btn.data('team-id');
            var teamName = btn.data('team-name');
            var teamIcon = btn.data('team-icon');

            $('#dfn-current-manage-team-id').val(teamId);
            $('#dfn-members-modal-team-title').text('Componenti: ' + teamName);
            $('#dfn-members-modal-team-icon').text(teamIcon);
            $('#dfn-select-add-member').val('0');

            loadTeamMembers(teamId);
            $('#dfn-members-modal-overlay').css('display', 'flex');
        });

        $('#dfn-btn-close-members-modal, #dfn-btn-done-members-modal').on('click', function() {
            $('#dfn-members-modal-overlay').hide();
            location.reload(); // Aggiorna i contatori
        });

        // Aggiungi Volontario al Team
        $('#dfn-btn-submit-add-member').on('click', function() {
            var teamId = parseInt($('#dfn-current-manage-team-id').val(), 10);
            var select = $('#dfn-select-add-member');
            var memberId = parseInt(select.val(), 10);
            var userId = parseInt(select.find('option:selected').data('uid') || 0, 10);

            if (!memberId || memberId === 0) {
                alert('Seleziona un volontario dall\'elenco.');
                return;
            }

            var btn = $(this);
            btn.prop('disabled', true).text('Assegnazione...');

            $.post(ajaxurl, {
                action: 'dfn_add_team_member_ajax',
                team_id: teamId,
                member_id: memberId,
                user_id: userId,
                nonce: nonce
            }, function(res) {
                btn.prop('disabled', false).text('Assegna al Team');
                if (res.success) {
                    select.val('0');
                    loadTeamMembers(teamId);
                } else {
                    alert(res.data || 'Errore durante l\'aggiunta.');
                }
            }).fail(function() {
                btn.prop('disabled', false).text('Assegna al Team');
                alert('Errore di connessione.');
            });
        });

        // Rimuovi Volontario dal Team
        $(document).on('click', '.dfn-btn-remove-member', function() {
            var btn = $(this);
            var teamId = btn.data('team-id');
            var memberId = btn.data('member-id');
            var name = btn.data('name');

            if (!confirm('Rimuovere ' + name + ' da questa squadra?')) {
                return;
            }

            btn.prop('disabled', true);
            $.post(ajaxurl, {
                action: 'dfn_remove_team_member_ajax',
                team_id: teamId,
                member_id: memberId,
                nonce: nonce
            }, function(res) {
                if (res.success) {
                    loadTeamMembers(teamId);
                } else {
                    alert(res.data || 'Errore durante la rimozione.');
                    btn.prop('disabled', false);
                }
            }).fail(function() {
                alert('Errore di connessione.');
                btn.prop('disabled', false);
            });
        });
    });
    </script>
    <?php
}

/**
 * ========================================================================
 * AJAX ENDPOINTS PER SQUADRE & TEAM
 * ========================================================================
 */

// 1. Salva Squadra (Crea o Modifica)
add_action('wp_ajax_dfn_save_team_ajax', 'dfn_ajax_save_team_handler');
function dfn_ajax_save_team_handler(): void
{
    check_ajax_referer('dfn_teams_nonce', 'nonce');

    if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_teams')) && ! current_user_can('dfn_act_vol_teams')) {
        wp_send_json_error(__('Permessi insufficienti.', 'dfn-theme'));
    }

    $team_id = ! empty($_POST['team_id']) ? (int) $_POST['team_id'] : null;
    $name = sanitize_text_field($_POST['name'] ?? '');

    if (empty($name)) {
        wp_send_json_error(__('Il nome della squadra è obbligatorio.', 'dfn-theme'));
    }

    $slug = sanitize_title($_POST['slug'] ?? $name);
    $sup_roles = isset($_POST['supervisor_roles']) ? (array) $_POST['supervisor_roles'] : [];

    $data = [
        'name'               => $name,
        'slug'               => $slug,
        'icon'               => sanitize_text_field($_POST['icon'] ?? '👥'),
        'color'              => sanitize_hex_color($_POST['color'] ?? '#004b23') ?: '#004b23',
        'badge_bg'           => sanitize_hex_color($_POST['badge_bg'] ?? '#f0fdf4') ?: '#f0fdf4',
        'whatsapp_url'       => ! empty($_POST['whatsapp_url']) ? esc_url_raw($_POST['whatsapp_url']) : null,
        'drive_url'          => ! empty($_POST['drive_url']) ? esc_url_raw($_POST['drive_url']) : null,
        'description'        => sanitize_textarea_field($_POST['description'] ?? ''),
        'supervisor_roles'   => $sup_roles,
        'supervisor_user_id' => ! empty($_POST['supervisor_user_id']) ? (int) $_POST['supervisor_user_id'] : null,
        'is_active'          => isset($_POST['is_active']) ? 1 : 0,
        'order_num'          => isset($_POST['order_num']) ? (int) $_POST['order_num'] : 0,
    ];

    $saved_id = dfn_save_team($data, $team_id);
    if ($saved_id) {
        if (function_exists('dfn_log_write')) {
            $action_label = $team_id ? "Modificata squadra FAI: {$name} (#{$saved_id})" : "Creata nuova squadra FAI: {$name} (#{$saved_id})";
            dfn_log_write('volontari', wp_get_current_user()->display_name, $action_label, 'success');
        }
        wp_send_json_success(['team_id' => $saved_id]);
    } else {
        wp_send_json_error(__('Impossibile salvare la squadra. Verifica che lo slug sia univoco.', 'dfn-theme'));
    }
}

// 2. Elimina Squadra
add_action('wp_ajax_dfn_delete_team_ajax', 'dfn_ajax_delete_team_handler');
function dfn_ajax_delete_team_handler(): void
{
    check_ajax_referer('dfn_teams_nonce', 'nonce');

    if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_teams')) && ! current_user_can('dfn_act_vol_teams')) {
        wp_send_json_error(__('Permessi insufficienti.', 'dfn-theme'));
    }

    $team_id = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
    if ($team_id <= 0) {
        wp_send_json_error(__('ID squadra non valido.', 'dfn-theme'));
    }

    $team = dfn_get_team($team_id);
    $deleted = dfn_delete_team($team_id);

    if ($deleted) {
        if (function_exists('dfn_log_write') && $team) {
            dfn_log_write('volontari', wp_get_current_user()->display_name, "Eliminata squadra FAI: {$team->name} (#{$team_id})", 'warning');
        }
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Impossibile eliminare la squadra.', 'dfn-theme'));
    }
}

// 3. Recupera Componenti di un Team
add_action('wp_ajax_dfn_get_team_members_ajax', 'dfn_ajax_get_team_members_handler');
function dfn_ajax_get_team_members_handler(): void
{
    check_ajax_referer('dfn_teams_nonce', 'nonce');

    $team_id = isset($_GET['team_id']) ? (int) $_GET['team_id'] : 0;
    if ($team_id <= 0) {
        wp_send_json_error(__('ID squadra non valido.', 'dfn-theme'));
    }

    $members = dfn_get_team_members($team_id);
    wp_send_json_success($members);
}

// 4. Aggiungi Componente al Team
add_action('wp_ajax_dfn_add_team_member_ajax', 'dfn_ajax_add_team_member_handler');
function dfn_ajax_add_team_member_handler(): void
{
    check_ajax_referer('dfn_teams_nonce', 'nonce');

    if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_teams')) && ! current_user_can('dfn_act_vol_teams')) {
        wp_send_json_error(__('Permessi insufficienti.', 'dfn-theme'));
    }

    $team_id   = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
    $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;
    $user_id   = ! empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;

    if ($team_id <= 0 || $member_id <= 0) {
        wp_send_json_error(__('Parametri non validi.', 'dfn-theme'));
    }

    $added = dfn_add_team_member($team_id, $member_id, $user_id);
    if ($added) {
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Impossibile aggiungere il volontario al team.', 'dfn-theme'));
    }
}

// 5. Rimuovi Componente dal Team
add_action('wp_ajax_dfn_remove_team_member_ajax', 'dfn_ajax_remove_team_member_handler');
function dfn_ajax_remove_team_member_handler(): void
{
    check_ajax_referer('dfn_teams_nonce', 'nonce');

    if (! current_user_can('manage_options') && ! (function_exists('dfn_user_can') && dfn_user_can('dfn_act_vol_teams')) && ! current_user_can('dfn_act_vol_teams')) {
        wp_send_json_error(__('Permessi insufficienti.', 'dfn-theme'));
    }

    $team_id   = isset($_POST['team_id']) ? (int) $_POST['team_id'] : 0;
    $member_id = isset($_POST['member_id']) ? (int) $_POST['member_id'] : 0;

    if ($team_id <= 0 || $member_id <= 0) {
        wp_send_json_error(__('Parametri non validi.', 'dfn-theme'));
    }

    $removed = dfn_remove_team_member($team_id, $member_id);
    if ($removed) {
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Impossibile rimuovere il volontario dal team.', 'dfn-theme'));
    }
}

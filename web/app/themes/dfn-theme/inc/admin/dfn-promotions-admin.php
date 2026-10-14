<?php
/**
 * DFN Booking System 2.0.1 — Modulo Promozioni & Marketing Eventi (Issue #41)
 *
 * Pannello di amministrazione per la creazione, gestione e monitoraggio
 * delle campagne promozionali per nuovi eventi ai clienti e soci FAI.
 *
 * @package DFN_Theme
 * @since   2.0.1
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_promotions_register_menu', 60);

// Disattiva il caricamento di Twemoji esterno sulla schermata promozioni per prevenire icone rotte
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'dfn-promotions') !== false) {
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
    }
});

/**
 * Registra la voce di menu sotto "FAI Prenotazioni" se il modulo è attivo.
 */
function dfn_promotions_register_menu(): void
{
    // Verifica il toggle modulare nelle impostazioni
    if (function_exists('dfn_is_module_active') && ! dfn_is_module_active('promozioni')) {
        return;
    }

    $cap = 'dfn_act_promotions';
    if (! current_user_can($cap) && current_user_can('manage_options')) {
        $cap = 'manage_options';
    }

    add_submenu_page(
        'dfn-events',
        __('Promozioni & Marketing Eventi', 'dfn-theme'),
        __('Promozioni & Newsletter', 'dfn-theme'),
        $cap,
        'dfn-promotions',
        'dfn_render_promotions_admin_page'
    );
}

/**
 * Renderizza la schermata principale del pannello promozioni.
 */
function dfn_render_promotions_admin_page(): void
{
    if (function_exists('dfn_is_module_active') && ! dfn_is_module_active('promozioni')) {
        wp_die(__('Il modulo Promozioni & Newsletter non è attualmente attivo.', 'dfn'), __('Modulo Disattivato', 'dfn'), ['response' => 403]);
    }

    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_die(esc_html__('Non hai i permessi per accedere a questa sezione.', 'dfn-theme'));
    }

    $active_tab   = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'campaigns';
    $promo_id     = isset($_GET['promo_id']) ? absint($_GET['promo_id']) : 0;
    $active_promo = ($promo_id > 0 && function_exists('dfn_db_get_promotion')) ? dfn_db_get_promotion($promo_id) : null;

    $future_events = function_exists('dfn_promotions_get_eligible_events') ? dfn_promotions_get_eligible_events(true) : [];
    $promotions    = function_exists('dfn_db_get_promotions') ? dfn_db_get_promotions() : [];

    // Se stiamo modificando una campagna esistente, assicuriamoci che il suo evento sia tra le scelte disponibili
    if ($active_promo && ! empty($active_promo->event_id)) {
        $event_found = false;
        foreach ($future_events as $fe) {
            if ((int) $fe->id === (int) $active_promo->event_id) {
                $event_found = true;
                break;
            }
        }
        if (! $event_found && function_exists('dfn_db_get_event')) {
            $ev_obj = dfn_db_get_event((int) $active_promo->event_id);
            if ($ev_obj) {
                $ev_obj->title = get_the_title($ev_obj->product_id) ?: sprintf(__('Evento #%d', 'dfn-theme'), $ev_obj->id);
                $ev_obj->permalink = get_permalink($ev_obj->product_id) ?: '';
                $thumb_url = get_the_post_thumbnail_url($ev_obj->product_id, 'medium_large');
                $ev_obj->image_url = $thumb_url ?: '';
                array_unshift($future_events, $ev_obj);
            }
        }
    }

    // Calcolo KPI di riepilogo
    $total_campaigns = count($promotions);
    $total_sent_all  = 0;
    $total_queue_all = 0;
    $total_failed_all = 0;

    foreach ($promotions as $p) {
        $total_sent_all   += (int) $p->sent_count;
        $total_failed_all += (int) $p->failed_count;
        if ($p->status === 'sending' || $p->status === 'scheduled') {
            $total_queue_all += max(0, (int) $p->total_recipients - (int) $p->sent_count - (int) $p->failed_count);
        }
    }

    $current_user       = wp_get_current_user();
    $current_user_email = $current_user->user_email ?: get_option('admin_email');
    $sandbox_test_email = function_exists('dfn_get_setting') ? dfn_get_setting('email_sandbox_redirect_to', '') : '';
    $admin_email        = (! empty($sandbox_test_email) && is_email($sandbox_test_email)) ? $sandbox_test_email : $current_user_email;
    ?>
    <div class="wrap dfn-promotions-wrap" style="max-width: 1400px; margin: 20px auto 40px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        <style>
            .dfn-promotions-wrap select#promotion_event_id {
                width: 100% !important;
                max-width: 100% !important;
                min-height: 48px !important;
                height: 48px !important;
                line-height: 26px !important;
                padding: 10px 38px 10px 14px !important;
                font-size: 14px !important;
                border-radius: 6px !important;
                border: 1px solid #cbd5e1 !important;
                background-color: #ffffff !important;
                box-sizing: border-box !important;
                vertical-align: middle !important;
            }
            .dfn-promotions-wrap input[type="text"],
            .dfn-promotions-wrap input[type="email"] {
                min-height: 42px !important;
                line-height: 1.5 !important;
                padding: 8px 14px !important;
                border-radius: 6px !important;
                border: 1px solid #cbd5e1 !important;
                box-sizing: border-box !important;
            }
            .dfn-promotions-wrap select#promotion_event_id:focus,
            .dfn-promotions-wrap input:focus,
            .dfn-promotions-wrap textarea:focus {
                border-color: #004b23 !important;
                box-shadow: 0 0 0 1px #004b23 !important;
                outline: none !important;
            }
        </style>
        
        <!-- Header con Branding FAI -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 style="color: #004b23; font-weight: 700; font-size: 26px; margin: 0 0 6px 0; display: flex; align-items: center; gap: 10px;">
                    <span class="dashicons dashicons-megaphone" style="font-size: 26px; width: 26px; height: 26px; color: #004b23; vertical-align: middle;"></span> <?php esc_html_e('Promozioni & Newsletter Eventi', 'dfn-theme'); ?>
                    <span style="font-size: 12px; background: #e0f2fe; color: #0284c7; padding: 2px 10px; border-radius: 999px; font-weight: 600; text-transform: uppercase;">Modulo 2.0.1</span>
                </h1>
                <p style="color: #64748b; font-size: 14px; margin: 0;">
                    <?php esc_html_e('Promuovi i nuovi eventi a tutti i clienti del portale con segmentazione automatica ed esclusione intelligente di chi ha già acquistato.', 'dfn-theme'); ?>
                </p>
            </div>
            <div>
                <?php if ($active_tab === 'campaigns') : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new')); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23; padding: 6px 18px; height: auto; font-size: 14px; font-weight: 600; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,75,35,0.2);">
                        <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-top; margin-right: 2px;"></span> <?php esc_html_e('Nuova Campagna Promozionale', 'dfn-theme'); ?>
                    </a>
                <?php else : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=campaigns')); ?>" class="button" style="padding: 6px 18px; height: auto; font-size: 14px; border-radius: 6px;">
                        ← <?php esc_html_e('Torna a Tutte le Campagne', 'dfn-theme'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- KPI Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 28px;">
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Campagne Totali', 'dfn-theme'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #004b23; margin-top: 6px;"><?php echo esc_html($total_campaigns); ?></div>
                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?php esc_html_e('Create e gestite nel sistema', 'dfn-theme'); ?></div>
            </div>

            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Email Inviate', 'dfn-theme'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #166534; margin-top: 6px;"><?php echo esc_html(number_format_i18n($total_sent_all)); ?></div>
                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?php esc_html_e('Confermate con successo', 'dfn-theme'); ?></div>
            </div>

            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Email in Coda', 'dfn-theme'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #0284c7; margin-top: 6px;"><?php echo esc_html(number_format_i18n($total_queue_all)); ?></div>
                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?php esc_html_e('In attesa di spedizione oraria', 'dfn-theme'); ?></div>
            </div>

            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;"><?php esc_html_e('Eventi Futuri Promuovibili', 'dfn-theme'); ?></div>
                <div style="font-size: 28px; font-weight: 700; color: #b45309; margin-top: 6px;"><?php echo esc_html(count($future_events)); ?></div>
                <div style="font-size: 12px; color: #94a3b8; margin-top: 4px;"><?php esc_html_e('Date >= oggi nel catalogo', 'dfn-theme'); ?></div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <h2 class="nav-tab-wrapper" style="margin-bottom: 25px; border-bottom: 1px solid #cbd5e1;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=campaigns')); ?>" class="nav-tab <?php echo ($active_tab === 'campaigns') ? 'nav-tab-active' : ''; ?>" style="<?php echo ($active_tab === 'campaigns') ? 'border-top: 3px solid #004b23; color: #004b23; font-weight: 600;' : ''; ?>">
                <span class="dashicons dashicons-list-view" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php esc_html_e('Tutte le Campagne', 'dfn-theme'); ?> (<?php echo esc_html($total_campaigns); ?>)
            </a>
            <a href="<?php echo esc_url($active_promo && $active_tab === 'new' ? admin_url('admin.php?page=dfn-promotions&tab=new&promo_id=' . $active_promo->id) : admin_url('admin.php?page=dfn-promotions&tab=new')); ?>" class="nav-tab <?php echo ($active_tab === 'new') ? 'nav-tab-active' : ''; ?>" style="<?php echo ($active_tab === 'new') ? 'border-top: 3px solid #004b23; color: #004b23; font-weight: 600;' : ''; ?>">
                <?php if ($active_promo && $active_tab === 'new') : ?>
                    <span class="dashicons dashicons-edit" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php printf(esc_html__('Modifica Campagna #%d', 'dfn-theme'), $active_promo->id); ?>
                <?php else : ?>
                    <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php esc_html_e('Crea Nuova Campagna', 'dfn-theme'); ?>
                <?php endif; ?>
            </a>
            <?php if ($active_promo && $active_tab === 'view') : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id)); ?>" class="nav-tab nav-tab-active" style="border-top: 3px solid #004b23; color: #004b23; font-weight: 600;">
                    <span class="dashicons dashicons-chart-bar" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php printf(esc_html__('Dettagli Campagna #%d', 'dfn-theme'), $active_promo->id); ?>
                </a>
            <?php endif; ?>
        </h2>

        <!-- Tab 1: Elenco Campagne -->
        <?php if ($active_tab === 'campaigns') : ?>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <?php if (empty($promotions)) : ?>
                    <div style="padding: 60px 20px; text-align: center; color: #64748b;">
                        <div style="width: 72px; height: 72px; margin: 0 auto 16px auto; background: #f0fdf4; border: 1px solid #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <span class="dashicons dashicons-email-alt" style="font-size: 38px; width: 38px; height: 38px; color: #004b23;"></span>
                        </div>
                        <h3 style="font-size: 18px; font-weight: 600; color: #1e293b; margin: 0 0 8px 0;"><?php esc_html_e('Nessuna campagna promozionale creata', 'dfn-theme'); ?></h3>
                        <p style="margin: 0 0 20px 0; font-size: 14px;"><?php esc_html_e('Crea la tua prima newsletter promozionale per presentare un evento in arrivo a tutti i tuoi clienti.', 'dfn-theme'); ?></p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new')); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23; font-weight: 600; padding: 6px 18px; height: auto;">
                            <?php esc_html_e('Crea la prima campagna ora', 'dfn-theme'); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat striped table-view-list" style="border: none; table-layout: auto;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                <th style="font-weight: 600; color: #475569; width: 50px;">ID</th>
                                <th style="font-weight: 600; color: #475569; min-width: 200px;"><?php esc_html_e('Campagna & Oggetto', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; min-width: 170px;"><?php esc_html_e('Evento Promosso', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 110px; white-space: nowrap;"><?php esc_html_e('Stato', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 160px;"><?php esc_html_e('Avanzamento', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 180px; white-space: nowrap;"><?php esc_html_e('Funnel & Risultati', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 120px; white-space: nowrap;"><?php esc_html_e('Data Creazione', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 310px; min-width: 290px; text-align: right; white-space: nowrap;"><?php esc_html_e('Azioni', 'dfn-theme'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($promotions as $promo) :
                                $total = (int) $promo->total_recipients;
                                $sent  = (int) $promo->sent_count;
                                $fail  = (int) $promo->failed_count;
                                $pct   = ($total > 0) ? round(($sent / $total) * 100) : 0;
                                ?>
                                <tr>
                                    <td style="font-weight: 600; color: #64748b;">#<?php echo esc_html($promo->id); ?></td>
                                    <td>
                                        <?php
                                        $target_url = ($promo->status === 'draft')
                                            ? admin_url('admin.php?page=dfn-promotions&tab=new&promo_id=' . $promo->id)
                                            : admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $promo->id);
                                        $target_tip = ($promo->status === 'draft')
                                            ? __('Clicca per modificare la bozza della campagna', 'dfn-theme')
                                            : __('Clicca per visualizzare dettagli e avanzamento invii', 'dfn-theme');
                                        ?>
                                        <a href="<?php echo esc_url($target_url); ?>" style="color: #0f172a; font-weight: 700; font-size: 14px; text-decoration: none;" onmouseover="this.style.textDecoration='underline'; this.style.color='#004b23';" onmouseout="this.style.textDecoration='none'; this.style.color='#0f172a';" title="<?php echo esc_attr($target_tip); ?>">
                                            <?php echo esc_html($promo->title); ?>
                                        </a>
                                        <div style="color: #64748b; font-size: 12px; margin-top: 3px;">
                                            <span class="dashicons dashicons-email-alt" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; color: #94a3b8; margin-right: 2px;"></span> <?php echo esc_html($promo->email_subject); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #004b23;">
                                            <?php echo esc_html($promo->event_title); ?>
                                        </div>
                                        <div style="color: #64748b; font-size: 12px; margin-top: 2px;">
                                            <span class="dashicons dashicons-calendar-alt" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; color: #94a3b8; margin-right: 2px;"></span> <?php echo esc_html($promo->event_date_start ? date_i18n('d/m/Y', strtotime($promo->event_date_start)) : '-'); ?>
                                            <?php if (! empty($promo->city)) : ?> • <span class="dashicons dashicons-location" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; color: #94a3b8; margin-right: 2px;"></span> <?php echo esc_html($promo->city); ?><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($promo->status === 'completed') : ?>
                                            <span style="display: inline-block; background: #dcfce7; color: #15803d; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                ✓ <?php esc_html_e('Completata', 'dfn-theme'); ?>
                                            </span>
                                        <?php elseif ($promo->status === 'scheduled') : ?>
                                            <span style="display: inline-block; background: #fef3c7; color: #b45309; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid #fde68a;" title="<?php echo esc_attr($promo->scheduled_at ? date_i18n('d/m/Y H:i', strtotime($promo->scheduled_at)) : ''); ?>">
                                                📅 <?php esc_html_e('Pianificata', 'dfn-theme'); ?> <?php echo esc_html($promo->scheduled_at ? date_i18n('d/m H:i', strtotime($promo->scheduled_at)) : ''); ?>
                                            </span>
                                        <?php elseif ($promo->status === 'sending') : ?>
                                            <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                <span class="dashicons dashicons-update" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('In invio...', 'dfn-theme'); ?>
                                            </span>
                                        <?php elseif ($promo->status === 'cancelled') : ?>
                                            <span style="display: inline-block; background: #fee2e2; color: #b91c1c; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                ✕ <?php esc_html_e('Annullata', 'dfn-theme'); ?>
                                            </span>
                                        <?php else : ?>
                                            <span style="display: inline-block; background: #f1f5f9; color: #475569; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                <span class="dashicons dashicons-edit" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Bozza', 'dfn-theme'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px;">
                                            <span><strong><?php echo esc_html($sent); ?></strong> / <?php echo esc_html($total); ?> inviate</span>
                                            <span style="font-weight: 600; color: #004b23;"><?php echo esc_html($pct); ?>%</span>
                                        </div>
                                        <div style="background: #e2e8f0; border-radius: 999px; height: 8px; width: 100%; overflow: hidden;">
                                            <div style="background: #166534; height: 100%; width: <?php echo esc_attr($pct); ?>%; border-radius: 999px; transition: width 0.3s ease;"></div>
                                        </div>
                                        <?php if ($fail > 0) : ?>
                                            <div style="color: #dc2626; font-size: 11px; margin-top: 3px;">
                                                <span class="dashicons dashicons-warning" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; color: #dc2626; margin-right: 2px;"></span> <?php echo esc_html($fail); ?> <?php esc_html_e('invii non riusciti', 'dfn-theme'); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $opened_c    = (int) ($promo->opened_count ?? 0);
                                        $clicked_c   = (int) ($promo->clicked_count ?? 0);
                                        $converted_c = (int) ($promo->converted_count ?? 0);
                                        $rev_c       = (float) ($promo->revenue_generated ?? 0.0);
                                        $open_rate   = ($sent > 0) ? round(($opened_c / $sent) * 100, 1) : 0;
                                        $ctr_rate    = ($opened_c > 0) ? round(($clicked_c / $opened_c) * 100, 1) : (($sent > 0) ? round(($clicked_c / $sent) * 100, 1) : 0);
                                        ?>
                                        <?php if ($sent > 0) : ?>
                                            <div style="font-size: 11px; line-height: 1.5; color: #334155;">
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 2px;">
                                                    <span>👁️ <?php esc_html_e('Open:', 'dfn-theme'); ?></span>
                                                    <strong style="color: #0369a1;"><?php echo esc_html($open_rate); ?>% <span style="font-weight: normal; color: #64748b;">(<?php echo esc_html($opened_c); ?>)</span></strong>
                                                </div>
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 2px;">
                                                    <span>🖱️ <?php esc_html_e('CTR:', 'dfn-theme'); ?></span>
                                                    <strong style="color: #15803d;"><?php echo esc_html($ctr_rate); ?>% <span style="font-weight: normal; color: #64748b;">(<?php echo esc_html($clicked_c); ?>)</span></strong>
                                                </div>
                                                <?php if ($converted_c > 0 || $rev_c > 0) : ?>
                                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: 3px; padding-top: 3px; border-top: 1px dashed #e2e8f0;">
                                                        <span style="color: #004b23; font-weight: 700;">🎟️ <?php printf(esc_html__('%d ord.', 'dfn-theme'), $converted_c); ?></span>
                                                        <strong style="color: #004b23;"><?php echo esc_html(number_format($rev_c, 2, ',', '.') . ' €'); ?></strong>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else : ?>
                                            <span style="color: #94a3b8; font-size: 12px;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: #64748b; font-size: 13px; white-space: nowrap;">
                                        <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($promo->created_at))); ?>
                                    </td>
                                    <td style="text-align: right; white-space: nowrap;">
                                        <div style="display: inline-flex; gap: 4px; justify-content: flex-end; align-items: center; white-space: nowrap; flex-wrap: nowrap;">
                                            <?php if ($promo->status === 'draft') : ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new&promo_id=' . $promo->id)); ?>" class="button button-small" style="color: #004b23; border-color: #004b23; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Modifica bozza', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-edit" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Modifica', 'dfn-theme'); ?>
                                                </a>
                                            <?php elseif ($promo->status === 'scheduled') : ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $promo->id)); ?>" class="button button-small" style="color: #b45309; border-color: #f59e0b; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Visualizza dettagli e pianificazione', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-calendar-alt" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Dettagli', 'dfn-theme'); ?>
                                                </a>
                                                <button type="button" class="button button-small dfn-launch-now-btn" data-id="<?php echo esc_attr($promo->id); ?>" title="<?php esc_attr_e('Avvia subito senza attendere la data programmata', 'dfn-theme'); ?>" style="color: #004b23; border-color: #004b23; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;">
                                                    <span class="dashicons dashicons-controls-play" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Avvia ora', 'dfn-theme'); ?>
                                                </button>
                                                <button type="button" class="button button-small dfn-cancel-promo-btn" data-id="<?php echo esc_attr($promo->id); ?>" style="color: #dc2626; border-color: #fca5a5; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Annulla pianificazione', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-dismiss" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Annulla', 'dfn-theme'); ?>
                                                </button>
                                            <?php elseif ($promo->status === 'sending') : ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $promo->id)); ?>" class="button button-small" style="color: #0284c7; border-color: #0284c7; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Monitora avanzamento invii', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-chart-bar" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Monitora', 'dfn-theme'); ?>
                                                </a>
                                                <button type="button" class="button button-small dfn-process-batch-btn" data-id="<?php echo esc_attr($promo->id); ?>" title="<?php esc_attr_e('Invia subito il prossimo lotto di email senza attendere il cron', 'dfn-theme'); ?>" style="color: #0369a1; border-color: #0284c7; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;">
                                                    <span class="dashicons dashicons-controls-play" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Invia lotto', 'dfn-theme'); ?>
                                                </button>
                                                <button type="button" class="button button-small dfn-cancel-promo-btn" data-id="<?php echo esc_attr($promo->id); ?>" style="color: #dc2626; border-color: #fca5a5; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Ferma campagna', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-controls-pause" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Ferma', 'dfn-theme'); ?>
                                                </button>
                                            <?php elseif ($promo->status === 'cancelled') : ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $promo->id)); ?>" class="button button-small" style="color: #dc2626; border-color: #fca5a5; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Visualizza report blocco', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-visibility" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Report', 'dfn-theme'); ?>
                                                </a>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new&promo_id=' . $promo->id)); ?>" class="button button-small" style="color: #004b23; border-color: #004b23; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Riapri come bozza per correggere errori e rilanciare', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-edit" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Correggi', 'dfn-theme'); ?>
                                                </a>
                                            <?php else : ?>
                                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $promo->id)); ?>" class="button button-small" style="color: #475569; border-color: #cbd5e1; font-weight: 600; padding: 0 8px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; gap: 3px;" title="<?php esc_attr_e('Visualizza dettagli e report', 'dfn-theme'); ?>">
                                                    <span class="dashicons dashicons-visibility" style="font-size: 13px; width: 13px; height: 13px;"></span> <?php esc_html_e('Dettagli', 'dfn-theme'); ?>
                                                </a>
                                            <?php endif; ?>

                                            <button type="button" class="button button-small dfn-delete-promo-btn" data-id="<?php echo esc_attr($promo->id); ?>" title="<?php esc_attr_e('Elimina campagna', 'dfn-theme'); ?>" style="color: #94a3b8; border-color: #cbd5e1; padding: 0 6px; height: 28px; line-height: 26px; display: inline-flex; align-items: center; justify-content: center;">
                                                <span class="dashicons dashicons-trash" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        <!-- Tab 2: Crea Nuova Campagna -->
        <?php elseif ($active_tab === 'new') : ?>
            
            <?php if (empty($future_events)) : ?>
                <div style="background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; padding: 20px; border-radius: 6px; margin-bottom: 25px;">
                    <h3 style="color: #92400e; margin: 0 0 6px 0; font-size: 16px; display: flex; align-items: center; gap: 6px;"><span class="dashicons dashicons-warning" style="color: #d97706; font-size: 20px; width: 20px; height: 20px;"></span> <?php esc_html_e('Nessun evento futuro disponibile per la promozione', 'dfn-theme'); ?></h3>
                    <p style="color: #78350f; margin: 0 0 12px 0; font-size: 14px;">
                        <?php esc_html_e('Per creare una campagna promozionale devi avere almeno un evento programmato con data pari o successiva ad oggi. Tutti gli eventi passati sono stati esclusi automaticamente per preservare la pertinenza della comunicazione.', 'dfn-theme'); ?>
                    </p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-event-edit')); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23;">
                        + <?php esc_html_e('Crea Nuovo Evento', 'dfn-theme'); ?>
                    </a>
                </div>
            <?php else : ?>

                <div style="display: grid; grid-template-columns: 1fr 480px; gap: 30px; align-items: start;">
                    
                    <!-- Colonna Sinistra: Modulo Configurazione Campagna -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                        <?php if ($active_promo) : ?>
                            <div style="background: <?php echo ($active_promo->status === 'cancelled') ? '#fef2f2' : '#f0fdf4'; ?>; border: 1px solid <?php echo ($active_promo->status === 'cancelled') ? '#fecaca' : '#bbf7d0'; ?>; border-left: 4px solid <?php echo ($active_promo->status === 'cancelled') ? '#ef4444' : '#166534'; ?>; padding: 14px 18px; border-radius: 6px; margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <strong style="color: <?php echo ($active_promo->status === 'cancelled') ? '#991b1b' : '#166534'; ?>; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                                        <span class="dashicons dashicons-edit"></span> 
                                        <?php if ($active_promo->status === 'cancelled') : ?>
                                            <?php printf(esc_html__('Correzione Bozza da Campagna Interrotta #%d', 'dfn-theme'), $active_promo->id); ?>
                                        <?php else : ?>
                                            <?php printf(esc_html__('Modifica Bozza Campagna #%d', 'dfn-theme'), $active_promo->id); ?>
                                        <?php endif; ?>
                                    </strong>
                                    <p style="margin: 3px 0 0 0; color: <?php echo ($active_promo->status === 'cancelled') ? '#7f1d1d' : '#14532d'; ?>; font-size: 13px;">
                                        <?php if ($active_promo->status === 'cancelled') : ?>
                                            <?php esc_html_e('La campagna precedente era stata interrotta tempestivamente. Puoi correggere i testi, i parametri o l\'evento e salvare come nuova bozza o procedere ad un nuovo invio.', 'dfn-theme'); ?>
                                        <?php else : ?>
                                            <?php esc_html_e('Puoi aggiornare i parametri di questa campagna, inviare un\'email di prova o avviare l\'invio definitivo.', 'dfn-theme'); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=campaigns')); ?>" class="button button-small" style="font-weight: 600;">
                                    ← <?php esc_html_e('Torna all\'elenco', 'dfn-theme'); ?>
                                </a>
                            </div>
                        <?php endif; ?>

                        <form id="dfn-promotion-form">
                            <?php wp_nonce_field('dfn_promotions_action', 'dfn_promotions_nonce'); ?>
                            <input type="hidden" id="promotion_id" name="promo_id" value="<?php echo esc_attr($active_promo ? $active_promo->id : 0); ?>" />

                            <!-- STEP 1: Selezione Evento Futuro -->
                            <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 22px; margin-bottom: 22px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <span style="background: #004b23; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">1</span>
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Seleziona l\'Evento da Promuovere', 'dfn-theme'); ?></h3>
                                </div>
                                <p style="color: #64748b; font-size: 13px; margin: 0 0 12px 0;">
                                    <?php esc_html_e('Sono mostrati esclusivamente gli eventi con data di svolgimento futura o in corso.', 'dfn-theme'); ?>
                                </p>

                                <label for="promotion_event_id" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                    <?php esc_html_e('Evento in programma:', 'dfn-theme'); ?> <span style="color: #dc2626;">*</span>
                                </label>
                                <select id="promotion_event_id" name="event_id" style="width: 100%; max-width: 100%; min-height: 48px; height: 48px; line-height: 26px; padding: 10px 38px 10px 14px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; box-sizing: border-box; background-color: #fff;">
                                    <option value=""><?php esc_html_e('-- Seleziona un evento da promuovere --', 'dfn-theme'); ?></option>
                                    <?php foreach ($future_events as $ev) :
                                        $d_str = date_i18n('d/m/Y', strtotime($ev->event_date_start));
                                        $loc   = $ev->city ? "({$ev->city})" : '';
                                        $is_selected = ($active_promo && (int) $active_promo->event_id === (int) $ev->id);
                                        ?>
                                        <option value="<?php echo esc_attr($ev->id); ?>" 
                                                <?php selected($is_selected); ?>
                                                data-title="<?php echo esc_attr($ev->title); ?>" 
                                                data-date="<?php echo esc_attr($d_str); ?>" 
                                                data-loc="<?php echo esc_attr(trim(($ev->location ?? '') . ($ev->city ? ', ' . $ev->city : ''))); ?>"
                                                data-image="<?php echo esc_attr($ev->image_url ?? ''); ?>"
                                                data-link="<?php echo esc_attr($ev->permalink ?? ''); ?>"
                                                data-desc="<?php echo esc_attr(wp_strip_all_tags($ev->description ?? '')); ?>">
                                            <?php echo esc_html("{$ev->title} — {$d_str} {$loc}"); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- Audience Intelligence Stats Card (Aggiornata via AJAX) -->
                                <div id="dfn-audience-box" style="margin-top: 14px; display: none; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px;">
                                    <div style="font-weight: 700; color: #166534; font-size: 13px; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                                        <span class="dashicons dashicons-filter" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom;"></span> <?php esc_html_e('Segmentazione Intelligente del Pubblico', 'dfn-theme'); ?>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; font-size: 12px;">
                                        <div style="background: #fff; padding: 8px 10px; border-radius: 6px; border: 1px solid #dcfce7; text-align: center;">
                                            <span style="color: #64748b; display: block;"><?php esc_html_e('Clienti Totali nel DB', 'dfn-theme'); ?></span>
                                            <strong id="stat-total-contacts" style="font-size: 16px; color: #0f172a;">-</strong>
                                        </div>
                                        <div style="background: #fff; padding: 8px 10px; border-radius: 6px; border: 1px solid #fee2e2; text-align: center;">
                                            <span style="color: #991b1b; display: block;"><?php esc_html_e('Già Prenotati (Esclusi)', 'dfn-theme'); ?></span>
                                            <strong id="stat-already-booked" style="font-size: 16px; color: #dc2626;">-</strong>
                                        </div>
                                        <div style="background: #fff; padding: 8px 10px; border-radius: 6px; border: 1px solid #bbf7d0; text-align: center;">
                                            <span style="color: #166534; display: block;"><?php esc_html_e('Destinatari Effettivi', 'dfn-theme'); ?></span>
                                            <strong id="stat-eligible-recipients" style="font-size: 16px; color: #15803d;">-</strong>
                                        </div>
                                    </div>
                                    <p style="color: #166534; font-size: 11px; margin: 8px 0 0 0;">
                                        ✓ <em><?php esc_html_e('Chi ha già prenotato per questo evento viene rigorosamente escluso per evitare invii inappropriati.', 'dfn-theme'); ?></em>
                                    </p>
                                </div>
                            </div>

                            <!-- STEP 2: Contenuto Messaggio -->
                            <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 22px; margin-bottom: 22px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <span style="background: #004b23; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">2</span>
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Personalizzazione Messaggio Promozionale', 'dfn-theme'); ?></h3>
                                </div>

                                <div style="margin-bottom: 14px;">
                                    <label for="promotion_title" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Titolo interno della campagna:', 'dfn-theme'); ?> <span style="color: #dc2626;">*</span>
                                    </label>
                                    <input type="text" id="promotion_title" name="title" value="<?php echo esc_attr($active_promo ? $active_promo->title : ''); ?>" placeholder="<?php esc_attr_e('Es. Lancio Visite Guidate Castello', 'dfn-theme'); ?>" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" required />
                                    <span style="color: #64748b; font-size: 12px;"><?php esc_html_e('Visibile solo a te per identificare la campagna in elenco.', 'dfn-theme'); ?></span>
                                </div>

                                <div style="margin-bottom: 14px;">
                                    <label for="promotion_email_subject" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Oggetto dell\'email:', 'dfn-theme'); ?> <span style="color: #dc2626;">*</span>
                                    </label>
                                    <input type="text" id="promotion_email_subject" name="email_subject" value="<?php echo esc_attr($active_promo ? $active_promo->email_subject : ''); ?>" placeholder="<?php esc_attr_e('Es. Scopri il nostro nuovo evento: Visita Guidata al Castello!', 'dfn-theme'); ?>" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" required />
                                    <span style="color: #64748b; font-size: 12px;"><?php esc_html_e('L\'oggetto visualizzato nella casella di posta dei destinatari.', 'dfn-theme'); ?></span>
                                </div>

                                <div style="margin-bottom: 14px;">
                                    <label for="promotion_email_intro" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Testo Introduttivo / Saluto iniziale:', 'dfn-theme'); ?>
                                    </label>
                                    <textarea id="promotion_email_intro" name="email_intro" rows="3" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" placeholder="<?php esc_attr_e('Siamo lieti di presentarti una nuova entusiasmante iniziativa culturale sul nostro territorio. Ti invitiamo a scoprire tutti i dettagli in anteprima!', 'dfn-theme'); ?>"><?php echo esc_textarea($active_promo ? $active_promo->email_intro : 'Siamo lieti di presentarti una nuova entusiasmante iniziativa culturale sul nostro territorio. Ti invitiamo a scoprire tutti i dettagli in anteprima!'); ?></textarea>
                                </div>

                                <div style="margin-bottom: 14px;">
                                    <label for="promotion_email_custom_text" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Testo personalizzato aggiuntivo (sotto la card evento):', 'dfn-theme'); ?>
                                    </label>
                                    <textarea id="promotion_email_custom_text" name="email_custom_text" rows="3" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" placeholder="<?php esc_attr_e('La disponibilità dei posti è limitata e l\'accesso avverrà a piccoli gruppi per garantire un\'esperienza esclusiva.', 'dfn-theme'); ?>"><?php echo esc_textarea($active_promo ? $active_promo->email_custom_text : 'La disponibilità dei posti è limitata per garantire un\'esperienza esclusiva. Ti consigliamo di prenotare il tuo turno con anticipo.'); ?></textarea>
                                </div>

                                <div>
                                    <label for="promotion_cta_text" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Testo del pulsante di prenotazione (CTA):', 'dfn-theme'); ?>
                                    </label>
                                    <input type="text" id="promotion_cta_text" name="cta_text" value="<?php echo esc_attr($active_promo ? ($active_promo->cta_text ?: 'Prenota il tuo posto') : 'Prenota il tuo posto'); ?>" style="width: 100%; max-width: 300px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" />
                                </div>
                            </div>

                            <!-- STEP 3: Pianificazione & Parametri di Invio Campagna -->
                            <div style="border-bottom: 1px solid #e2e8f0; padding-bottom: 22px; margin-bottom: 22px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <span style="background: #004b23; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">3</span>
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Pianificazione & Parametri di Invio', 'dfn-theme'); ?></h3>
                                </div>
                                <p style="color: #64748b; font-size: 13px; margin: 0 0 16px 0;">
                                    <?php esc_html_e('Configura quando e con quale cadenza recapitare le email. Tutti i valori consigliati sono già compilati e ottimizzati per evitare filtri antispam e sovraccarichi del server.', 'dfn-theme'); ?>
                                </p>

                                <?php
                                $promo_send_mode = ($active_promo && $active_promo->status === 'scheduled') ? 'scheduled' : 'immediate';
                                $promo_sched_val = ($active_promo && ! empty($active_promo->scheduled_at) && $active_promo->scheduled_at !== '0000-00-00 00:00:00')
                                    ? date('Y-m-d\TH:i', strtotime($active_promo->scheduled_at))
                                    : date('Y-m-d\TH:i', strtotime('+1 day 10:00'));
                                $promo_init_batch = ($active_promo && ! empty($active_promo->initial_batch_size)) ? (int) $active_promo->initial_batch_size : 15;
                                $promo_batch_sz   = ($active_promo && ! empty($active_promo->batch_size)) ? (int) $active_promo->batch_size : 25;
                                $promo_interval   = ($active_promo && ! empty($active_promo->batch_interval_minutes)) ? (int) $active_promo->batch_interval_minutes : 5;
                                ?>

                                <!-- Tempistica di Partenza -->
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin-bottom: 18px;">
                                    <label style="font-weight: 700; font-size: 14px; display: block; margin-bottom: 10px; color: #1e293b;">
                                        <?php esc_html_e('Tempistica di Partenza:', 'dfn-theme'); ?>
                                    </label>
                                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 10px;">
                                        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer; color: #334155;">
                                            <input type="radio" name="send_mode" value="immediate" <?php checked($promo_send_mode, 'immediate'); ?> id="dfn_mode_immediate" />
                                            <span>⚡ <?php esc_html_e('Invio Immediato (Parte subito all\'avvio)', 'dfn-theme'); ?></span>
                                        </label>
                                        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; cursor: pointer; color: #334155;">
                                            <input type="radio" name="send_mode" value="scheduled" <?php checked($promo_send_mode, 'scheduled'); ?> id="dfn_mode_scheduled" />
                                            <span>📅 <?php esc_html_e('Pianifica Invio Futuro (Data e ora prestabilite)', 'dfn-theme'); ?></span>
                                        </label>
                                    </div>

                                    <!-- Box Pianificazione Data/Ora -->
                                    <div id="dfn_scheduled_box" style="display: <?php echo ($promo_send_mode === 'scheduled') ? 'block' : 'none'; ?>; background: #fff; border: 1px solid #cbd5e1; border-left: 4px solid #004b23; border-radius: 6px; padding: 14px 16px; margin-top: 12px;">
                                        <label for="promotion_scheduled_at" style="font-weight: 600; font-size: 13px; display: block; margin-bottom: 6px; color: #0f172a;">
                                            <?php esc_html_e('Giorno e ora di inizio campagna:', 'dfn-theme'); ?> <span style="color: #dc2626;">*</span>
                                        </label>
                                        <input type="datetime-local" id="promotion_scheduled_at" name="scheduled_at" value="<?php echo esc_attr($promo_sched_val); ?>" min="<?php echo esc_attr(date('Y-m-d\TH:i')); ?>" style="max-width: 260px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; font-weight: 600;" />
                                        <p style="margin: 8px 0 0 0; font-size: 12px; color: #64748b; line-height: 1.4;">
                                            💡 <strong><?php esc_html_e('Come funziona la pianificazione:', 'dfn-theme'); ?></strong> <?php esc_html_e('I destinatari idonei vengono congelati in coda al momento della conferma, ma nessuna email partirà fino al momento prestabilito. All\'orario programmato, il sistema avvierà automaticamente la spedizione a scaglioni.', 'dfn-theme'); ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Griglia Parametri Lotti -->
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                                    
                                    <!-- 1. Primo Lotto Immediato -->
                                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                                        <label for="promotion_initial_batch_size" style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 4px; color: #0f172a;">
                                            <?php esc_html_e('1° Lotto Immediato', 'dfn-theme'); ?>
                                        </label>
                                        <input type="number" id="promotion_initial_batch_size" name="initial_batch_size" min="1" max="100" value="<?php echo esc_attr($promo_init_batch); ?>" style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; font-weight: 700; color: #004b23;" />
                                        <span style="display: block; font-size: 11px; color: #0284c7; font-weight: 600; margin-top: 4px;"><?php esc_html_e('Valore suggerito: 15 email', 'dfn-theme'); ?></span>
                                        <p style="margin: 6px 0 0 0; font-size: 11px; color: #64748b; line-height: 1.35;">
                                            <?php esc_html_e('Email inviate subito al click di avvio (solo per invio immediato) per verificare subito la ricezione.', 'dfn-theme'); ?>
                                        </p>
                                    </div>

                                    <!-- 2. Lotto Successivo -->
                                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                                        <label for="promotion_batch_size" style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 4px; color: #0f172a;">
                                            <?php esc_html_e('Email per Lotto', 'dfn-theme'); ?>
                                        </label>
                                        <input type="number" id="promotion_batch_size" name="batch_size" min="5" max="200" value="<?php echo esc_attr($promo_batch_sz); ?>" style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; font-weight: 700; color: #004b23;" />
                                        <span style="display: block; font-size: 11px; color: #0284c7; font-weight: 600; margin-top: 4px;"><?php esc_html_e('Valore suggerito: 25 email', 'dfn-theme'); ?></span>
                                        <p style="margin: 6px 0 0 0; font-size: 11px; color: #64748b; line-height: 1.35;">
                                            <?php esc_html_e('Quantità di email spedite a ogni ciclo di background. Evita blocchi dell\'hosting e spam.', 'dfn-theme'); ?>
                                        </p>
                                    </div>

                                    <!-- 3. Intervallo tra Lotti -->
                                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                                        <label for="promotion_batch_interval_minutes" style="font-weight: 700; font-size: 13px; display: block; margin-bottom: 4px; color: #0f172a;">
                                            <?php esc_html_e('Intervallo Lotti', 'dfn-theme'); ?>
                                        </label>
                                        <select id="promotion_batch_interval_minutes" name="batch_interval_minutes" style="width: 100%; padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; font-weight: 600;">
                                            <option value="5" <?php selected($promo_interval, 5); ?>>5 minuti (Suggerito)</option>
                                            <option value="10" <?php selected($promo_interval, 10); ?>>10 minuti</option>
                                            <option value="15" <?php selected($promo_interval, 15); ?>>15 minuti (Prudente)</option>
                                            <option value="30" <?php selected($promo_interval, 30); ?>>30 minuti</option>
                                            <option value="60" <?php selected($promo_interval, 60); ?>>60 minuti (1 ora)</option>
                                        </select>
                                        <span style="display: block; font-size: 11px; color: #0284c7; font-weight: 600; margin-top: 4px;"><?php esc_html_e('Valore suggerito: 5 minuti', 'dfn-theme'); ?></span>
                                        <p style="margin: 6px 0 0 0; font-size: 11px; color: #64748b; line-height: 1.35;">
                                            <?php esc_html_e('Pausa tra un blocco e il successivo per distribuire il carico nel tempo in background.', 'dfn-theme'); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- STEP 4: Test & Invio Campagna -->
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <span style="background: #004b23; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">4</span>
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Test Rapido & Conferma Campagna', 'dfn-theme'); ?></h3>
                                </div>

                                <!-- Box Invio Test Rapido -->
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;">
                                    <div style="font-weight: 600; color: #334155; font-size: 13px; margin-bottom: 6px;">
                                        <span class="dashicons dashicons-email-alt" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php esc_html_e('Invia un\'email di prova alla tua casella:', 'dfn-theme'); ?>
                                    </div>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <input type="email" id="dfn_test_email_address" value="<?php echo esc_attr($admin_email); ?>" style="flex-grow: 1; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;" />
                                        <button type="button" id="dfn-send-test-btn" class="button" style="padding: 5px 14px; height: auto; font-size: 13px; font-weight: 600;">
                                            <span class="dashicons dashicons-email-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php esc_html_e('Invia Prova', 'dfn-theme'); ?>
                                        </button>
                                    </div>
                                    <div id="dfn-test-status" style="font-size: 12px; margin-top: 6px; display: none;"></div>
                                </div>

                                <!-- Pulsanti Finali -->
                                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                    <button type="button" id="dfn-launch-campaign-btn" class="button button-primary" style="background: #004b23; border-color: #004b23; padding: 8px 24px; height: auto; font-size: 15px; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                                        <?php if ($promo_send_mode === 'scheduled') : ?>
                                            <span class="dashicons dashicons-calendar-alt" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Pianifica Campagna', 'dfn-theme'); ?>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-controls-play" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Avvia Invio Campagna', 'dfn-theme'); ?>
                                        <?php endif; ?>
                                    </button>

                                    <button type="button" id="dfn-save-draft-btn" class="button" style="padding: 8px 18px; height: auto; font-size: 14px; border-radius: 6px;">
                                        <span class="dashicons dashicons-saved" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 4px;"></span> 
                                        <?php echo $active_promo ? esc_html__('Aggiorna Bozza', 'dfn-theme') : esc_html__('Salva come Bozza', 'dfn-theme'); ?>
                                    </button>

                                    <span id="dfn-submit-spinner" class="spinner" style="float: none; margin: 0;"></span>
                                </div>
                                <div id="dfn-form-msg" style="margin-top: 12px; font-size: 13px; display: none;"></div>
                            </div>
                        </form>
                    </div>

                    <!-- Colonna Destra: Mockup Live Anteprima Email -->
                    <div>
                        <div style="position: sticky; top: 50px;">
                            <div style="background: #1e293b; color: #fff; padding: 12px 18px; border-radius: 10px 10px 0 0; display: flex; justify-content: space-between; align-items: center;">
                                <strong style="font-size: 13px; letter-spacing: 0.5px; text-transform: uppercase; display: flex; align-items: center; gap: 6px;"><span class="dashicons dashicons-smartphone" style="font-size: 16px; width: 16px; height: 16px;"></span> <?php esc_html_e('Anteprima Email in Tempo Reale', 'dfn-theme'); ?></strong>
                                <span style="font-size: 11px; color: #94a3b8;">Client Responsive</span>
                            </div>
                            
                            <div style="background: #f1f5f9; border: 1px solid #cbd5e1; border-top: none; border-radius: 0 0 10px 10px; padding: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); max-height: 750px; overflow-y: auto;">
                                <!-- Finestra Mail Client Simulatore -->
                                <div style="background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px;">
                                    
                                    <!-- Mail Header Header bar -->
                                    <div style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 14px; font-size: 12px; color: #475569;">
                                        <div style="margin-bottom: 4px;"><strong>Da:</strong> <?php echo esc_html(get_bloginfo('name')); ?> &lt;<?php echo esc_html(get_option('admin_email')); ?>&gt;</div>
                                        <div style="margin-bottom: 4px;"><strong>A:</strong> mario.rossi@example.com</div>
                                        <div><strong>Oggetto:</strong> <span id="preview-subject-text" style="color: #0f172a; font-weight: 600;">Nuovo Evento in Arrivo</span></div>
                                    </div>

                                    <!-- Mail Body Header -->
                                    <div style="background: #004b23; padding: 16px 20px; text-align: center;">
                                        <div style="color: #fff; font-weight: 700; font-size: 16px; letter-spacing: 1px; text-transform: uppercase;">
                                            <?php echo esc_html(dfn_get_setting('delegation_name', get_bloginfo('name'))); ?>
                                        </div>
                                    </div>

                                    <!-- Mail Body Content -->
                                    <div style="padding: 20px; color: #2d3748; line-height: 1.5;">
                                        <p style="margin: 0 0 12px 0;">Gentile <strong>Mario Rossi</strong>,</p>
                                        
                                        <div id="preview-intro-text" style="color: #4a5568; margin-bottom: 16px; white-space: pre-line; font-size: 13px;">
                                            Siamo lieti di presentarti una nuova entusiasmante iniziativa culturale sul nostro territorio. Ti invitiamo a scoprire tutti i dettagli in anteprima!
                                        </div>

                                        <!-- Card Evento Preview -->
                                        <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; margin-bottom: 16px;">
                                            <div id="preview-event-img-wrap" style="width: 100%; background: #f8fafc; text-align: center; line-height: 0; border-bottom: 1px solid #e2e8f0;">
                                                <img id="preview-event-img" src="" style="width: 100%; max-width: 100%; height: auto; display: none; margin: 0 auto;" />
                                                <span id="preview-event-placeholder" style="color: #94a3b8; font-size: 12px; display: inline-block; padding: 40px 10px; line-height: 1.4;">Immagine di copertina evento</span>
                                            </div>

                                            <div style="padding: 14px;">
                                                <div style="display: inline-block; background: #f0fdf4; color: #004b23; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;">
                                                    Nuovo Evento
                                                </div>

                                                <h4 id="preview-event-title" style="margin: 0 0 8px 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                                                    Titolo dell'Evento Selezionato
                                                </h4>

                                                <div style="font-size: 12px; color: #475569; margin-bottom: 12px;">
                                                    <div id="preview-event-date" style="margin-bottom: 4px;"><span class="dashicons dashicons-calendar-alt" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 3px; color: #64748b;"></span> Data da definire</div>
                                                    <div id="preview-event-loc"><span class="dashicons dashicons-location" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 3px; color: #64748b;"></span> Luogo da definire</div>
                                                </div>

                                                <div id="preview-event-desc" style="font-size: 12px; color: #64748b; margin-bottom: 14px; line-height: 1.4; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
                                                    Descrizione dell'evento...
                                                </div>

                                                <div style="text-align: center;">
                                                    <span id="preview-cta-btn" style="display: inline-block; background: #004b23; color: #fff; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 700; text-decoration: none;">
                                                        <span class="dashicons dashicons-tickets-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; margin-right: 4px;"></span> Prenota il tuo posto
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="preview-custom-text" style="color: #4a5568; margin-bottom: 16px; white-space: pre-line; font-size: 13px;">
                                            La disponibilità dei posti è limitata per garantire un'esperienza esclusiva. Ti consigliamo di prenotare il tuo turno con anticipo.
                                        </div>

                                        <p style="font-size: 11px; color: #94a3b8; margin: 20px 0 0 0; border-top: 1px solid #e2e8f0; padding-top: 10px;">
                                            Ti aspettiamo per vivere insieme una nuova esperienza.<br>
                                            <strong><?php echo esc_html(dfn_get_setting('delegation_name', get_bloginfo('name'))); ?></strong>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        <!-- Tab 3: Visualizza Dettagli Campagna & Coda -->
        <?php elseif ($active_tab === 'view') : ?>
            <?php if (! $active_promo) : ?>
                <div style="background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 50px 20px; text-align: center;">
                    <p style="color: #64748b; font-size: 16px; margin: 0 0 20px 0;"><?php esc_html_e('Campagna non trovata o rimossa.', 'dfn-theme'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=campaigns')); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23;">
                        ← <?php esc_html_e('Torna a Tutte le Campagne', 'dfn-theme'); ?>
                    </a>
                </div>
            <?php else :
                $queue_counts = function_exists('dfn_db_get_promotion_queue_counts') ? dfn_db_get_promotion_queue_counts($active_promo->id) : ['total' => 0, 'pending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'opened' => 0, 'clicked' => 0, 'converted' => 0, 'revenue' => 0.0];
                $analytics    = function_exists('dfn_db_get_promotion_analytics') ? dfn_db_get_promotion_analytics((int) $active_promo->id) : [];
                $queue_filter = isset($_GET['queue_status']) ? sanitize_key($_GET['queue_status']) : '';
                $queue_items  = function_exists('dfn_db_get_promotion_queue') ? dfn_db_get_promotion_queue($active_promo->id, 200, $queue_filter) : [];
                
                $total_recipients = (int) $active_promo->total_recipients;
                $sent_count       = (int) $active_promo->sent_count;
                $failed_count     = (int) $active_promo->failed_count;
                $pending_count    = max(0, $total_recipients - $sent_count - $failed_count);
                $pct = ($total_recipients > 0) ? round(($sent_count / $total_recipients) * 100) : 0;
            ?>
                <!-- Scheda Header Campagna -->
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 24px 28px; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <span style="font-weight: 700; color: #64748b; font-size: 15px;">#<?php echo esc_html($active_promo->id); ?></span>
                                <h2 style="margin: 0; font-size: 22px; font-weight: 700; color: #0f172a;"><?php echo esc_html($active_promo->title); ?></h2>
                                <?php if ($active_promo->status === 'completed') : ?>
                                    <span style="background: #dcfce7; color: #15803d; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600;">✓ <?php esc_html_e('Completata', 'dfn-theme'); ?></span>
                                <?php elseif ($active_promo->status === 'scheduled') : ?>
                                    <span style="background: #fef3c7; color: #b45309; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid #fde68a;">
                                        📅 <?php esc_html_e('Pianificata', 'dfn-theme'); ?>
                                    </span>
                                <?php elseif ($active_promo->status === 'sending') : ?>
                                    <span style="background: #e0f2fe; color: #0369a1; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                        <span class="dashicons dashicons-update" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('In invio...', 'dfn-theme'); ?>
                                    </span>
                                <?php elseif ($active_promo->status === 'cancelled') : ?>
                                    <span style="background: #fee2e2; color: #b91c1c; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600;">✕ <?php esc_html_e('Annullata', 'dfn-theme'); ?></span>
                                <?php else : ?>
                                    <span style="background: #f1f5f9; color: #475569; padding: 3px 12px; border-radius: 999px; font-size: 12px; font-weight: 600;"><?php esc_html_e('Bozza', 'dfn-theme'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="color: #64748b; font-size: 13px; display: flex; align-items: center; gap: 18px; flex-wrap: wrap;">
                                <span><span class="dashicons dashicons-email-alt" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; color: #94a3b8;"></span> Oggetto: <strong><?php echo esc_html($active_promo->email_subject); ?></strong></span>
                                <span><span class="dashicons dashicons-calendar-alt" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; color: #94a3b8;"></span> Creata il: <strong><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($active_promo->created_at))); ?></strong></span>
                                <?php if (! empty($active_promo->completed_at)) : ?>
                                    <span><span class="dashicons dashicons-yes-alt" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; color: #15803d;"></span> Conclusa il: <strong><?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($active_promo->completed_at))); ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <?php if ($active_promo->status === 'draft') : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new&promo_id=' . $active_promo->id)); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23; font-weight: 600;">
                                    <span class="dashicons dashicons-edit" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Modifica Bozza', 'dfn-theme'); ?>
                                </a>
                            <?php elseif ($active_promo->status === 'scheduled') : ?>
                                <button type="button" class="button button-primary dfn-launch-now-btn" data-id="<?php echo esc_attr($active_promo->id); ?>" style="background: #004b23; border-color: #004b23; font-weight: 600;">
                                    <span class="dashicons dashicons-controls-play" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Avvia Subito (Anticipa Partenza)', 'dfn-theme'); ?>
                                </button>
                                <button type="button" class="button dfn-cancel-promo-btn" data-id="<?php echo esc_attr($active_promo->id); ?>" style="color: #dc2626; border-color: #fca5a5;">
                                    <span class="dashicons dashicons-dismiss" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Annulla Pianificazione', 'dfn-theme'); ?>
                                </button>
                            <?php elseif ($active_promo->status === 'sending') : ?>
                                <button type="button" class="button button-primary dfn-process-batch-btn" data-id="<?php echo esc_attr($active_promo->id); ?>" style="background: #0284c7; border-color: #0284c7; font-weight: 600;">
                                    <span class="dashicons dashicons-controls-play" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php printf(esc_html__('Invia Prossimo Lotto (%d email)', 'dfn-theme'), $active_promo->batch_size ?: 25); ?>
                                </button>
                                <button type="button" class="button dfn-cancel-promo-btn" data-id="<?php echo esc_attr($active_promo->id); ?>" style="color: #dc2626; border-color: #fca5a5;" title="<?php esc_attr_e('Interrompi immediatamente l\'invio delle email rimanenti', 'dfn-theme'); ?>">
                                    <span class="dashicons dashicons-controls-pause" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Ferma Campagna Subito', 'dfn-theme'); ?>
                                </button>
                            <?php elseif ($active_promo->status === 'cancelled') : ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new&promo_id=' . $active_promo->id)); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23; font-weight: 600;" title="<?php esc_attr_e('Riapri come bozza per correggere errori e rilanciare', 'dfn-theme'); ?>">
                                    <span class="dashicons dashicons-edit" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Riapri / Correggi Bozza', 'dfn-theme'); ?>
                                </a>
                            <?php endif; ?>

                            <button type="button" class="button dfn-delete-promo-btn" data-id="<?php echo esc_attr($active_promo->id); ?>" style="color: #94a3b8; border-color: #cbd5e1;">
                                <span class="dashicons dashicons-trash" style="font-size: 15px; width: 15px; height: 15px; vertical-align: text-bottom;"></span> <?php esc_html_e('Elimina', 'dfn-theme'); ?>
                            </button>
                        </div>
                    </div>

                    <?php if ($active_promo->status === 'scheduled') : ?>
                        <div style="background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 8px; padding: 14px 18px; margin-top: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong style="color: #b45309; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-calendar-alt" style="font-size: 18px; width: 18px; height: 18px;"></span>
                                    <?php printf(esc_html__('Campagna Pianificata per il %s', 'dfn-theme'), date_i18n('d/m/Y \a\l\l\e H:i', strtotime($active_promo->scheduled_at))); ?>
                                </strong>
                                <p style="margin: 4px 0 0 0; color: #92400e; font-size: 13px;">
                                    <?php printf(esc_html__('Parametri impostati: %d email per lotto ogni %d minuti. I destinatari sono congelati in coda; all\'orario stabilito la spedizione partirà automaticamente.', 'dfn-theme'), $active_promo->batch_size ?: 25, $active_promo->batch_interval_minutes ?: 5); ?>
                                </p>
                            </div>
                        </div>
                    <?php elseif ($active_promo->status === 'cancelled') : ?>
                        <div style="background: #fef2f2; border: 1px solid #fecaca; border-left: 4px solid #ef4444; border-radius: 8px; padding: 14px 18px; margin-top: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <strong style="color: #991b1b; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-controls-pause" style="font-size: 18px; width: 18px; height: 18px; color: #dc2626;"></span>
                                    <?php esc_html_e('Campagna Interrotta Tempestivamente dall\'Amministratore', 'dfn-theme'); ?>
                                </strong>
                                <p style="margin: 4px 0 0 0; color: #7f1d1d; font-size: 13px;">
                                    <?php esc_html_e('L\'invio è stato bloccato con successo: tutte le email in coda rimaste sono state congelate e nessun altro messaggio verrà spedito. Puoi cliccare su "Riapri / Correggi Bozza" per modificare i testi o correggere gli errori commessi.', 'dfn-theme'); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Avanzamento e metriche invii -->
                    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #f1f5f9;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 18px;">
                            <div style="background: #f8fafc; padding: 14px 18px; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <span style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase;"><?php esc_html_e('Destinatari Totali', 'dfn-theme'); ?></span>
                                <div style="font-size: 24px; font-weight: 700; color: #0f172a; margin-top: 4px;"><?php echo esc_html(number_format_i18n($total_recipients)); ?></div>
                            </div>
                            <div style="background: #f0fdf4; padding: 14px 18px; border-radius: 8px; border: 1px solid #dcfce7;">
                                <span style="font-size: 12px; color: #166534; font-weight: 600; text-transform: uppercase;"><?php esc_html_e('Inviate con Successo', 'dfn-theme'); ?></span>
                                <div style="font-size: 24px; font-weight: 700; color: #15803d; margin-top: 4px;"><?php echo esc_html(number_format_i18n($sent_count)); ?></div>
                            </div>
                            <div style="background: #e0f2fe; padding: 14px 18px; border-radius: 8px; border: 1px solid #bae6fd;">
                                <span style="font-size: 12px; color: #0369a1; font-weight: 600; text-transform: uppercase;"><?php esc_html_e('In Attesa in Coda', 'dfn-theme'); ?></span>
                                <div style="font-size: 24px; font-weight: 700; color: #0284c7; margin-top: 4px;"><?php echo esc_html(number_format_i18n($pending_count)); ?></div>
                            </div>
                            <?php if (! empty($queue_counts['cancelled']) && $queue_counts['cancelled'] > 0) : ?>
                                <div style="background: #fef2f2; padding: 14px 18px; border-radius: 8px; border: 1px solid #fecaca;">
                                    <span style="font-size: 12px; color: #991b1b; font-weight: 600; text-transform: uppercase;"><?php esc_html_e('Bloccate dallo Stop', 'dfn-theme'); ?></span>
                                    <div style="font-size: 24px; font-weight: 700; color: #dc2626; margin-top: 4px;"><?php echo esc_html(number_format_i18n($queue_counts['cancelled'])); ?></div>
                                </div>
                            <?php endif; ?>
                            <div style="background: #fef2f2; padding: 14px 18px; border-radius: 8px; border: 1px solid #fee2e2;">
                                <span style="font-size: 12px; color: #991b1b; font-weight: 600; text-transform: uppercase;"><?php esc_html_e('Non Riuscite / Errori', 'dfn-theme'); ?></span>
                                <div style="font-size: 24px; font-weight: 700; color: #dc2626; margin-top: 4px;"><?php echo esc_html(number_format_i18n($failed_count)); ?></div>
                            </div>
                        </div>

                        <div>
                            <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px;">
                                <span style="font-weight: 600; color: #475569;"><?php esc_html_e('Progresso Spedizione:', 'dfn-theme'); ?> <?php echo esc_html($sent_count); ?> / <?php echo esc_html($total_recipients); ?></span>
                                <span style="font-weight: 700; color: #004b23;"><?php echo esc_html($pct); ?>%</span>
                            </div>
                            <div style="background: #e2e8f0; border-radius: 999px; height: 12px; width: 100%; overflow: hidden;">
                                <div style="background: #166534; height: 100%; width: <?php echo esc_attr($pct); ?>%; border-radius: 999px; transition: width 0.3s ease;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Scheda Performance & Funnel Marketing (Issue #60) -->
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px 28px; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h3 style="margin: 0 0 4px 0; font-size: 17px; font-weight: 700; color: #004b23; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-chart-area" style="font-size: 20px; width: 20px; height: 20px; color: #004b23;"></span>
                                <?php esc_html_e('Performance Funnel & Attribuzione Vendite Biglietti', 'dfn-theme'); ?>
                            </h3>
                            <p style="margin: 0; color: #64748b; font-size: 13px;">
                                <?php esc_html_e('Tracciamento in tempo reale dall\'apertura dell\'email, al click sui link fino all\'acquisto del biglietto sul portale.', 'dfn-theme'); ?>
                            </p>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span style="font-size: 11px; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; padding: 3px 10px; border-radius: 999px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                <span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px;"></span> <?php esc_html_e('Pixel 1x1 & Cookie 30gg', 'dfn-theme'); ?>
                            </span>
                            <span style="font-size: 11px; background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; padding: 3px 10px; border-radius: 999px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                <span class="dashicons dashicons-google" style="font-size: 14px; width: 14px; height: 14px;"></span> <?php esc_html_e('Tag UTM Google Analytics 4', 'dfn-theme'); ?>
                            </span>
                        </div>
                    </div>

                    <!-- 4 KPI Cards Funnel -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 18px;">
                        <!-- KPI 1: Aperture -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; border-top: 3px solid #0284c7;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;"><?php esc_html_e('Aperture Uniche', 'dfn-theme'); ?></span>
                                <span style="font-size: 12px; font-weight: 700; color: #0284c7; background: #e0f2fe; padding: 2px 8px; border-radius: 999px;">
                                    <?php echo esc_html($analytics['open_rate'] ?? 0); ?>%
                                </span>
                            </div>
                            <div style="font-size: 26px; font-weight: 700; color: #0f172a; margin-top: 6px;">
                                <?php echo esc_html(number_format_i18n($analytics['opened_count'] ?? 0)); ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                <?php printf(esc_html__('su %s email inviate', 'dfn-theme'), number_format_i18n($analytics['sent_count'] ?? 0)); ?>
                            </div>
                        </div>

                        <!-- KPI 2: Click CTA -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; border-top: 3px solid #f59e0b;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;"><?php esc_html_e('Click sui Link / CTA', 'dfn-theme'); ?></span>
                                <span style="font-size: 12px; font-weight: 700; color: #b45309; background: #fef3c7; padding: 2px 8px; border-radius: 999px;">
                                    CTR <?php echo esc_html($analytics['ctr'] ?? 0); ?>%
                                </span>
                            </div>
                            <div style="font-size: 26px; font-weight: 700; color: #0f172a; margin-top: 6px;">
                                <?php echo esc_html(number_format_i18n($analytics['clicked_count'] ?? 0)); ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                <?php esc_html_e('Visitatori arrivati alla pagina evento', 'dfn-theme'); ?>
                            </div>
                        </div>

                        <!-- KPI 3: Conversioni Biglietti -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; border-top: 3px solid #166534;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;"><?php esc_html_e('Ordini / Biglietti Acquistati', 'dfn-theme'); ?></span>
                                <span style="font-size: 12px; font-weight: 700; color: #166534; background: #dcfce7; padding: 2px 8px; border-radius: 999px;">
                                    CR <?php echo esc_html($analytics['conversion_rate'] ?? 0); ?>%
                                </span>
                            </div>
                            <div style="font-size: 26px; font-weight: 700; color: #004b23; margin-top: 6px;">
                                <?php echo esc_html(number_format_i18n($analytics['converted_count'] ?? 0)); ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                <?php esc_html_e('Acquisti completati post-email', 'dfn-theme'); ?>
                            </div>
                        </div>

                        <!-- KPI 4: Incasso Totale -->
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; border-top: 3px solid #004b23;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <span style="font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;"><?php esc_html_e('Incasso Generato Attribuito', 'dfn-theme'); ?></span>
                                <span style="font-size: 14px;">💶</span>
                            </div>
                            <div style="font-size: 26px; font-weight: 700; color: #004b23; margin-top: 6px;">
                                <?php echo esc_html(number_format((float) ($analytics['revenue_generated'] ?? 0.0), 2, ',', '.') . ' €'); ?>
                            </div>
                            <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                                <?php esc_html_e('Valore ordini WooCommerce attribuiti', 'dfn-theme'); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Info Box Parametri UTM & Metodologia Attribuzione -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 18px; font-size: 12px; color: #475569; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-info" style="color: #0284c7; font-size: 18px; width: 18px; height: 18px;"></span>
                            <span>
                                <strong><?php esc_html_e('Come funziona il tracciamento:', 'dfn-theme'); ?></strong>
                                <?php esc_html_e('Le aperture vengono registrate via pixel invisibile; i click sulla copertina o sulla Call To Action impostano un cookie sicuro di 30 giorni ed inseriscono i tag UTM standard per Google Analytics 4 (campagna:', 'dfn-theme'); ?>
                                <code>utm_campaign=<?php echo esc_html(sanitize_title($active_promo->title ?: ('promo_' . $active_promo->id))); ?></code>).
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Dettagli Evento & Coda Spedizione -->
                <div style="display: grid; grid-template-columns: 380px 1fr; gap: 25px; align-items: start;">
                    <!-- Colonna Sinistra: Scheda Evento Promosso -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                        <h3 style="margin: 0 0 14px 0; font-size: 15px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                            <span class="dashicons dashicons-tickets-alt" style="color: #004b23;"></span> <?php esc_html_e('Evento Promosso', 'dfn-theme'); ?>
                        </h3>

                        <?php if (! empty($active_promo->image_url)) : ?>
                            <div style="margin-bottom: 14px; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;">
                                <img src="<?php echo esc_url($active_promo->image_url); ?>" alt="<?php echo esc_attr($active_promo->event_title); ?>" style="width: 100%; height: auto; display: block;" />
                            </div>
                        <?php endif; ?>

                        <div style="font-weight: 700; color: #004b23; font-size: 16px; margin-bottom: 6px;">
                            <?php echo esc_html($active_promo->event_title); ?>
                        </div>

                        <div style="font-size: 13px; color: #64748b; line-height: 1.6; margin-bottom: 16px;">
                            <div><span class="dashicons dashicons-calendar-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php echo esc_html($active_promo->event_date_start ? date_i18n('d/m/Y', strtotime($active_promo->event_date_start)) : '-'); ?><?php if (! empty($active_promo->event_time_start)) : ?> alle <?php echo esc_html(substr($active_promo->event_time_start, 0, 5)); ?><?php endif; ?></div>
                            <?php if (! empty($active_promo->location) || ! empty($active_promo->city)) : ?>
                                <div><span class="dashicons dashicons-location" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; margin-right: 4px;"></span> <?php echo esc_html(trim(($active_promo->location ?? '') . ($active_promo->city ? ' (' . $active_promo->city . ')' : ''))); ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (! empty($active_promo->event_permalink)) : ?>
                            <a href="<?php echo esc_url($active_promo->event_permalink); ?>" target="_blank" class="button button-small" style="width: 100%; text-align: center; box-sizing: border-box; justify-content: center; display: inline-flex; align-items: center; gap: 4px;">
                                <span class="dashicons dashicons-external" style="font-size: 14px; width: 14px; height: 14px;"></span> <?php esc_html_e('Apri Pagina Evento', 'dfn-theme'); ?>
                            </a>
                        <?php endif; ?>
                    </div>

                    <!-- Colonna Destra: Tabella Destinatari in Coda -->
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                        <div style="padding: 18px 22px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 6px;">
                                <span class="dashicons dashicons-groups" style="color: #004b23;"></span> <?php esc_html_e('Destinatari & Stato Consegna', 'dfn-theme'); ?> (<?php echo esc_html(count($queue_items)); ?>)
                            </h3>
                            
                            <!-- Filtri di stato coda -->
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id)); ?>" class="button button-small <?php echo empty($queue_filter) ? 'button-primary' : ''; ?>" style="<?php echo empty($queue_filter) ? 'background: #004b23; border-color: #004b23;' : ''; ?>">
                                    Tutti (<?php echo esc_html($queue_counts['total']); ?>)
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id . '&queue_status=pending')); ?>" class="button button-small <?php echo ($queue_filter === 'pending') ? 'button-primary' : ''; ?>" style="<?php echo ($queue_filter === 'pending') ? 'background: #0284c7; border-color: #0284c7;' : ''; ?>">
                                    In Coda (<?php echo esc_html($queue_counts['pending']); ?>)
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id . '&queue_status=sent')); ?>" class="button button-small <?php echo ($queue_filter === 'sent') ? 'button-primary' : ''; ?>" style="<?php echo ($queue_filter === 'sent') ? 'background: #166534; border-color: #166534;' : ''; ?>">
                                    Inviate (<?php echo esc_html($queue_counts['sent']); ?>)
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id . '&queue_status=opened')); ?>" class="button button-small <?php echo ($queue_filter === 'opened') ? 'button-primary' : ''; ?>" style="<?php echo ($queue_filter === 'opened') ? 'background: #0284c7; border-color: #0284c7;' : 'color: #0284c7; border-color: #bae6fd;'; ?>">
                                    👁️ Aperte (<?php echo esc_html($queue_counts['opened'] ?? 0); ?>)
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id . '&queue_status=clicked')); ?>" class="button button-small <?php echo ($queue_filter === 'clicked') ? 'button-primary' : ''; ?>" style="<?php echo ($queue_filter === 'clicked') ? 'background: #b45309; border-color: #b45309;' : 'color: #b45309; border-color: #fde68a;'; ?>">
                                    🖱️ Cliccate (<?php echo esc_html($queue_counts['clicked'] ?? 0); ?>)
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id . '&queue_status=converted')); ?>" class="button button-small <?php echo ($queue_filter === 'converted') ? 'button-primary' : ''; ?>" style="<?php echo ($queue_filter === 'converted') ? 'background: #166534; border-color: #166534;' : 'color: #166534; border-color: #bbf7d0;'; ?>">
                                    🎟️ Convertite (<?php echo esc_html($queue_counts['converted'] ?? 0); ?>)
                                </a>
                                <?php if (! empty($queue_counts['cancelled']) && $queue_counts['cancelled'] > 0) : ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id . '&queue_status=cancelled')); ?>" class="button button-small <?php echo ($queue_filter === 'cancelled') ? 'button-primary' : ''; ?>" style="<?php echo ($queue_filter === 'cancelled') ? 'background: #dc2626; border-color: #dc2626;' : 'color: #dc2626; border-color: #fca5a5;'; ?>">
                                        Bloccate dallo Stop (<?php echo esc_html($queue_counts['cancelled']); ?>)
                                    </a>
                                <?php endif; ?>
                                <?php if ($queue_counts['failed'] > 0) : ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=view&promo_id=' . $active_promo->id . '&queue_status=failed')); ?>" class="button button-small <?php echo ($queue_filter === 'failed') ? 'button-primary' : ''; ?>" style="<?php echo ($queue_filter === 'failed') ? 'background: #dc2626; border-color: #dc2626;' : ''; ?>">
                                        Errori (<?php echo esc_html($queue_counts['failed']); ?>)
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (empty($queue_items)) : ?>
                            <div style="padding: 40px; text-align: center; color: #64748b; font-size: 14px;">
                                <?php if ($active_promo->status === 'draft') : ?>
                                    <?php esc_html_e('La campagna è attualmente in bozza: i destinatari verranno accodati automaticamente al momento del lancio.', 'dfn-theme'); ?>
                                <?php else : ?>
                                    <?php esc_html_e('Nessun destinatario trovato con il filtro selezionato.', 'dfn-theme'); ?>
                                <?php endif; ?>
                            </div>
                        <?php else : ?>
                            <table class="wp-list-table widefat striped table-view-list" style="border: none; table-layout: auto;">
                                <thead>
                                    <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                        <th style="font-weight: 600; color: #475569; min-width: 220px;"><?php esc_html_e('Destinatario', 'dfn-theme'); ?></th>
                                        <th style="font-weight: 600; color: #475569; width: 100px; white-space: nowrap;"><?php esc_html_e('Spedizione', 'dfn-theme'); ?></th>
                                        <th style="font-weight: 600; color: #475569; width: 200px; white-space: nowrap;"><?php esc_html_e('Attività Email', 'dfn-theme'); ?></th>
                                        <th style="font-weight: 600; color: #475569; width: 180px; white-space: nowrap;"><?php esc_html_e('Conversione Ordine', 'dfn-theme'); ?></th>
                                        <th style="font-weight: 600; color: #475569; min-width: 150px;"><?php esc_html_e('Data Invio / Note', 'dfn-theme'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queue_items as $q) : ?>
                                        <tr>
                                            <td>
                                                <strong style="color: #0f172a;"><?php echo esc_html($q->recipient_email); ?></strong>
                                                <?php if (! empty($q->recipient_name)) : ?>
                                                    <div style="color: #64748b; font-size: 12px; margin-top: 2px;"><?php echo esc_html($q->recipient_name); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($q->status === 'sent') : ?>
                                                    <span style="display: inline-block; background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;">
                                                        ✓ Inviata
                                                    </span>
                                                <?php elseif ($q->status === 'cancelled') : ?>
                                                    <span style="display: inline-block; background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;" title="<?php esc_attr_e('Bloccata tempestivamente dallo stop amministratore', 'dfn-theme'); ?>">
                                                        ✕ Bloccata
                                                    </span>
                                                <?php elseif ($q->status === 'failed') : ?>
                                                    <span style="display: inline-block; background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;">
                                                        ✕ Errore
                                                    </span>
                                                <?php else : ?>
                                                    <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 600;">
                                                        In coda
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (! empty($q->opened_at)) : ?>
                                                    <div style="font-size: 11px; color: #0369a1; margin-bottom: 2px;">
                                                        <strong>👁️ <?php esc_html_e('Aperta', 'dfn-theme'); ?></strong>
                                                        <?php if ((int) $q->open_count > 1) : ?>
                                                            <span style="background: #e0f2fe; padding: 1px 5px; border-radius: 999px; font-weight: 600;"><?php echo (int) $q->open_count; ?>x</span>
                                                        <?php endif; ?>
                                                        <span style="color: #64748b;">(<?php echo esc_html(date_i18n('d/m H:i', strtotime($q->opened_at))); ?>)</span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (! empty($q->clicked_at)) : ?>
                                                    <div style="font-size: 11px; color: #b45309;">
                                                        <strong>🖱️ <?php esc_html_e('Click Link', 'dfn-theme'); ?></strong>
                                                        <?php if ((int) $q->click_count > 1) : ?>
                                                            <span style="background: #fef3c7; padding: 1px 5px; border-radius: 999px; font-weight: 600;"><?php echo (int) $q->click_count; ?>x</span>
                                                        <?php endif; ?>
                                                        <span style="color: #64748b;">(<?php echo esc_html(date_i18n('d/m H:i', strtotime($q->clicked_at))); ?>)</span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (empty($q->opened_at) && empty($q->clicked_at)) : ?>
                                                    <span style="color: #94a3b8; font-size: 12px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (! empty($q->converted_at)) : ?>
                                                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 4px 8px; font-size: 11px;">
                                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                                            <strong style="color: #166534;">
                                                                <?php if (! empty($q->order_id)) : ?>
                                                                    <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $q->order_id . '&action=edit')); ?>" target="_blank" style="color: #166534; text-decoration: underline;">
                                                                        🎟️ Ordine #<?php echo esc_html($q->order_id); ?>
                                                                    </a>
                                                                <?php else : ?>
                                                                    🎟️ Ordine
                                                                <?php endif; ?>
                                                            </strong>
                                                            <span style="color: #004b23; font-weight: 700;">
                                                                <?php echo esc_html(number_format((float) $q->order_total, 2, ',', '.') . ' €'); ?>
                                                            </span>
                                                        </div>
                                                        <div style="color: #64748b; font-size: 10px; margin-top: 2px;">
                                                            <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($q->converted_at))); ?>
                                                            <?php if (! empty($q->booking_id)) : ?>
                                                                • Prenot. #<?php echo esc_html($q->booking_id); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php else : ?>
                                                    <span style="color: #94a3b8; font-size: 12px;">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size: 12px; color: #64748b;">
                                                <?php if ($q->status === 'sent' && ! empty($q->sent_at)) : ?>
                                                    <?php echo esc_html(date_i18n('d/m/Y H:i:s', strtotime($q->sent_at))); ?>
                                                <?php elseif ($q->status === 'cancelled') : ?>
                                                    <span style="color: #dc2626;"><?php echo esc_html($q->error_message ?: 'Invio bloccato tempestivamente dall\'amministratore'); ?></span>
                                                <?php elseif ($q->status === 'failed') : ?>
                                                    <span style="color: #dc2626;"><?php echo esc_html($q->error_message ?: 'Invio fallito'); ?></span>
                                                <?php else : ?>
                                                    <span style="color: #94a3b8;">In attesa di spedizione</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Script Interattivi Admin & AJAX -->
    <script>
    jQuery(document).ready(function($) {
        var ajaxurl = <?php echo json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo json_encode(wp_create_nonce('dfn_promotions_ajax_nonce')); ?>;

        // 1. Aggiornamento Dinamico Mockup Anteprima
        function updateLivePreview() {
            var selectedEv = $('#promotion_event_id option:selected');
            var subj = $('#promotion_email_subject').val() || 'Nuovo Evento in Arrivo';
            var intro = $('#promotion_email_intro').val() || '';
            var custom = $('#promotion_email_custom_text').val() || '';
            var cta = $('#promotion_cta_text').val() || 'Prenota il tuo posto';

            $('#preview-subject-text').text(subj);
            $('#preview-intro-text').text(intro);
            $('#preview-custom-text').text(custom);
            $('#preview-cta-btn').html('<span class="dashicons dashicons-tickets-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; margin-right: 4px;"></span> ' + cta);

            if (selectedEv.val()) {
                var title = selectedEv.data('title');
                var date = selectedEv.data('date');
                var loc = selectedEv.data('loc');
                var img = selectedEv.data('image');
                var desc = selectedEv.data('desc');

                $('#preview-event-title').text(title);
                $('#preview-event-date').html('<span class="dashicons dashicons-calendar-alt" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 3px; color: #64748b;"></span> ' + date);
                $('#preview-event-loc').html('<span class="dashicons dashicons-location" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 3px; color: #64748b;"></span> ' + loc);
                $('#preview-event-desc').text(desc ? (desc.substring(0, 160) + '...') : '');

                if (img) {
                    $('#preview-event-img').attr('src', img).show();
                    $('#preview-event-placeholder').hide();
                } else {
                    $('#preview-event-img').hide();
                    $('#preview-event-placeholder').show();
                }
            } else {
                $('#preview-event-title').text('Titolo dell\'Evento Selezionato');
                $('#preview-event-date').html('<span class="dashicons dashicons-calendar-alt" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 3px; color: #64748b;"></span> Data da definire');
                $('#preview-event-loc').html('<span class="dashicons dashicons-location" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 3px; color: #64748b;"></span> Luogo da definire');
                $('#preview-event-desc').text('Descrizione dell\'evento...');
                $('#preview-event-img').hide();
                $('#preview-event-placeholder').show();
            }
        }

        $('#promotion_event_id').on('change', function() {
            updateLivePreview();
            var eventId = $(this).val();
            if (!eventId) {
                $('#dfn-audience-box').slideUp();
                return;
            }

            // Richiesta AJAX calcolo pubblico target in tempo reale
            $('#stat-total-contacts').text('...');
            $('#stat-already-booked').text('...');
            $('#stat-eligible-recipients').text('...');
            $('#dfn-audience-box').slideDown();

            // Suggerisci automaticamente Titolo ed Oggetto se vuoti
            var evTitle = $('#promotion_event_id option:selected').data('title');
            if (!$('#promotion_title').val()) {
                $('#promotion_title').val('Lancio ' + evTitle);
            }
            if (!$('#promotion_email_subject').val()) {
                $('#promotion_email_subject').val('Nuovo Evento FAI: ' + evTitle);
                $('#preview-subject-text').text('Nuovo Evento FAI: ' + evTitle);
            }

            $.post(ajaxurl, {
                action: 'dfn_promotions_get_audience_stats',
                nonce: nonce,
                event_id: eventId
            }, function(res) {
                if (res.success && res.data) {
                    $('#stat-total-contacts').text(res.data.total_contacts);
                    $('#stat-already-booked').text(res.data.already_booked);
                    $('#stat-eligible-recipients').text(res.data.eligible);
                }
            });
        });

        $('#promotion_email_subject, #promotion_email_intro, #promotion_email_custom_text, #promotion_cta_text').on('input', function() {
            updateLivePreview();
        });

        // 2. Invio Email di Test Singola
        $('#dfn-send-test-btn').on('click', function(e) {
            e.preventDefault();
            var testEmail = $('#dfn_test_email_address').val().trim();
            var eventId = $('#promotion_event_id').val();
            var subject = $('#promotion_email_subject').val().trim();
            var intro = $('#promotion_email_intro').val().trim();
            var custom = $('#promotion_email_custom_text').val().trim();
            var cta = $('#promotion_cta_text').val().trim();

            if (!testEmail) {
                alert('Inserisci un indirizzo email per il test.');
                return;
            }
            if (!eventId) {
                alert('Seleziona prima l\'evento da promuovere al punto 1.');
                return;
            }

            var $btn = $(this);
            var $status = $('#dfn-test-status');
            $btn.prop('disabled', true).text('Invio in corso...');
            $status.hide().removeClass('notice notice-success notice-error');

            $.post(ajaxurl, {
                action: 'dfn_promotions_send_test_email',
                nonce: nonce,
                test_email: testEmail,
                event_id: eventId,
                subject: subject,
                intro: intro,
                custom_text: custom,
                cta_text: cta
            }, function(res) {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; margin-right: 4px;"></span> Invia Prova');
                if (res.success) {
                    $status.html('<span style="color: #166534; font-weight: 600;">✓ ' + res.data.message + '</span>').show();
                } else {
                    $status.html('<span style="color: #dc2626; font-weight: 600;">✕ ' + (res.data ? res.data.message : 'Errore durante l\'invio di test') + '</span>').show();
                }
            }).fail(function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt" style="font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; margin-right: 4px;"></span> Invia Prova');
                $status.html('<span style="color: #dc2626;">✕ Errore di connessione al server</span>').show();
            });
        });

        // Gestione cambio modalità di partenza (Immediato vs Pianificato)
        $('input[name="send_mode"]').on('change', function() {
            var mode = $('input[name="send_mode"]:checked').val();
            if (mode === 'scheduled') {
                $('#dfn_scheduled_box').slideDown(150);
                $('#dfn-launch-campaign-btn').html('<span class="dashicons dashicons-calendar-alt" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Pianifica Campagna', 'dfn-theme'); ?>');
            } else {
                $('#dfn_scheduled_box').slideUp(150);
                $('#dfn-launch-campaign-btn').html('<span class="dashicons dashicons-controls-play" style="font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; margin-right: 2px;"></span> <?php esc_html_e('Avvia Invio Campagna', 'dfn-theme'); ?>');
            }
        });

        // 3. Salva Campagna (Bozza o Avvia/Pianifica Invio)
        function submitCampaign(isLaunch) {
            var eventId = $('#promotion_event_id').val();
            var title = $('#promotion_title').val().trim();
            var subject = $('#promotion_email_subject').val().trim();
            var intro = $('#promotion_email_intro').val().trim();
            var custom = $('#promotion_email_custom_text').val().trim();
            var cta = $('#promotion_cta_text').val().trim();

            var sendMode     = $('input[name="send_mode"]:checked').val() || 'immediate';
            var scheduledAt  = $('#promotion_scheduled_at').val() || '';
            var initialBatch = $('#promotion_initial_batch_size').val() || 15;
            var batchSize    = $('#promotion_batch_size').val() || 25;
            var batchInterval= $('#promotion_batch_interval_minutes').val() || 5;

            if (!eventId) {
                alert('Seleziona l\'evento da promuovere.');
                $('#promotion_event_id').focus();
                return;
            }
            if (!title) {
                alert('Inserisci il titolo interno della campagna.');
                $('#promotion_title').focus();
                return;
            }
            if (!subject) {
                alert('Inserisci l\'oggetto dell\'email.');
                $('#promotion_email_subject').focus();
                return;
            }

            if (isLaunch) {
                if (sendMode === 'scheduled' && !scheduledAt) {
                    alert('Inserisci il giorno e l\'ora previsti per la pianificazione della campagna.');
                    $('#promotion_scheduled_at').focus();
                    return;
                }

                var eligibleCount = $('#stat-eligible-recipients').text();
                var msg = '';
                if (sendMode === 'scheduled') {
                    msg = 'Vuoi pianificare l\'invio di questa campagna promozionale per la data/ora selezionata?\n';
                    if (eligibleCount && eligibleCount !== '...') {
                        msg += 'Destinatari stimati in coda: ' + eligibleCount + ' contatti.\nNessuna email partirà fino all\'orario stabilito.';
                    }
                } else {
                    msg = 'Sei sicuro di voler avviare questa campagna promozionale ora?\n';
                    if (eligibleCount && eligibleCount !== '...') {
                        msg += 'Verranno inviate email a circa ' + eligibleCount + ' clienti unici del portale (primo lotto da ' + initialBatch + ' email subito).';
                    }
                }
                if (!confirm(msg)) {
                    return;
                }
            }

            $('#dfn-submit-spinner').addClass('is-active');
            $('#dfn-launch-campaign-btn, #dfn-save-draft-btn').prop('disabled', true);
            var $msg = $('#dfn-form-msg');
            $msg.hide();

            var promoId = $('#promotion_id').val() || 0;

            $.post(ajaxurl, {
                action: 'dfn_promotions_save_campaign',
                nonce: nonce,
                promo_id: promoId,
                event_id: eventId,
                title: title,
                subject: subject,
                intro: intro,
                custom_text: custom,
                cta_text: cta,
                send_mode: sendMode,
                scheduled_at: scheduledAt,
                initial_batch_size: initialBatch,
                batch_size: batchSize,
                batch_interval_minutes: batchInterval,
                is_launch: isLaunch ? 1 : 0
            }, function(res) {
                $('#dfn-submit-spinner').removeClass('is-active');
                if (res.success) {
                    $msg.html('<span style="color: #166534; font-weight: 700;">✓ ' + res.data.message + '</span>').show();
                    setTimeout(function() {
                        window.location.href = <?php echo json_encode(admin_url('admin.php?page=dfn-promotions&tab=campaigns')); ?>;
                    }, 1200);
                } else {
                    $('#dfn-launch-campaign-btn, #dfn-save-draft-btn').prop('disabled', false);
                    $msg.html('<span style="color: #dc2626; font-weight: 700;">✕ ' + (res.data ? res.data.message : 'Errore nel salvataggio') + '</span>').show();
                }
            }).fail(function() {
                $('#dfn-submit-spinner').removeClass('is-active');
                $('#dfn-launch-campaign-btn, #dfn-save-draft-btn').prop('disabled', false);
                $msg.html('<span style="color: #dc2626;">✕ Errore di rete / socket</span>').show();
            });
        }

        $('#dfn-launch-campaign-btn').on('click', function(e) {
            e.preventDefault();
            submitCampaign(true);
        });

        $('#dfn-save-draft-btn').on('click', function(e) {
            e.preventDefault();
            submitCampaign(false);
        });

        // 4. Azione: Avvia Subito Campagna Pianificata (Anticipa Partenza)
        $('.dfn-launch-now-btn').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Vuoi avviare subito questa campagna pianificata senza attendere l\'orario programmato?')) {
                return;
            }
            var promoId = $(this).data('id');
            var $btn = $(this);
            $btn.prop('disabled', true).text('Avvio in corso...');
            $.post(ajaxurl, {
                action: 'dfn_promotions_launch_scheduled_now',
                nonce: nonce,
                promo_id: promoId
            }, function(res) {
                if (res.success) {
                    alert(res.data.message);
                    location.reload();
                } else {
                    alert('Errore: ' + (res.data ? res.data.message : 'Impossibile avviare la campagna'));
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 2px;"></span> Avvia ora');
                }
            }).fail(function() {
                alert('Errore di comunicazione con il server');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 2px;"></span> Avvia ora');
            });
        });

        // 5. Azione: Elabora Lotto Manuale (Batch)
        $('.dfn-process-batch-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var promoId = $btn.data('id');

            $btn.prop('disabled', true).text('Invio in corso...');

            $.post(ajaxurl, {
                action: 'dfn_promotions_process_batch',
                nonce: nonce,
                promo_id: promoId
            }, function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert('Errore: ' + (res.data ? res.data.message : 'Impossibile elaborare il lotto'));
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 2px;"></span> Invia lotto');
                }
            }).fail(function() {
                alert('Errore di comunicazione con il server');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play" style="font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; margin-right: 2px;"></span> Invia lotto');
            });
        });

        // 6. Azione: Annulla Campagna
        $('.dfn-cancel-promo-btn').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Vuoi interrompere l\'invio di questa campagna? I destinatari rimanenti non riceveranno l\'email.')) {
                return;
            }
            var promoId = $(this).data('id');
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(ajaxurl, {
                action: 'dfn_promotions_cancel_campaign',
                nonce: nonce,
                promo_id: promoId
            }, function(res) {
                if (res.success) {
                    if (res.data && res.data.message) {
                        alert(res.data.message);
                    }
                    location.reload();
                } else {
                    alert('Errore: ' + (res.data ? res.data.message : 'Impossibile annullare la campagna'));
                    $btn.prop('disabled', false);
                }
            }).fail(function() {
                alert('Errore di comunicazione con il server');
                $btn.prop('disabled', false);
            });
        });

        // 7. Azione: Elimina Campagna
        $('.dfn-delete-promo-btn').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Sei sicuro di voler eliminare questa campagna e il relativo storico? Questa azione non può essere annullata.')) {
                return;
            }
            var promoId = $(this).data('id');
            $.post(ajaxurl, {
                action: 'dfn_promotions_delete_campaign',
                nonce: nonce,
                promo_id: promoId
            }, function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert('Errore: ' + (res.data ? res.data.message : 'Impossibile eliminare la campagna'));
                }
            });
        });

        // Inizializza live preview se un evento è già selezionato (es. in modifica bozza)
        if ($('#promotion_event_id').length && $('#promotion_event_id').val()) {
            $('#promotion_event_id').trigger('change');
        }

    });
    </script>
    <?php
}

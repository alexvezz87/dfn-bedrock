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

/**
 * Registra la voce di menu sotto "FAI Prenotazioni" se il modulo è attivo.
 */
function dfn_promotions_register_menu(): void
{
    // Verifica il toggle modulare nelle impostazioni
    if (function_exists('dfn_get_setting') && dfn_get_setting('enable_event_promotions', 'yes') !== 'yes') {
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
    if (! current_user_can('dfn_act_promotions') && ! current_user_can('manage_options')) {
        wp_die(esc_html__('Non hai i permessi per accedere a questa sezione.', 'dfn-theme'));
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'campaigns';
    $future_events = function_exists('dfn_promotions_get_eligible_events') ? dfn_promotions_get_eligible_events(true) : [];
    $promotions    = function_exists('dfn_db_get_promotions') ? dfn_db_get_promotions() : [];

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

    $current_user = wp_get_current_user();
    $admin_email  = $current_user->user_email ?: get_option('admin_email');
    ?>
    <div class="wrap dfn-promotions-wrap" style="max-width: 1400px; margin: 20px auto 40px auto; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        
        <!-- Header con Branding FAI -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 style="color: #004b23; font-weight: 700; font-size: 26px; margin: 0 0 6px 0; display: flex; align-items: center; gap: 10px;">
                    <span>📢</span> <?php esc_html_e('Promozioni & Newsletter Eventi', 'dfn-theme'); ?>
                    <span style="font-size: 12px; background: #e0f2fe; color: #0284c7; padding: 2px 10px; border-radius: 999px; font-weight: 600; text-transform: uppercase;">Modulo 2.0.1</span>
                </h1>
                <p style="color: #64748b; font-size: 14px; margin: 0;">
                    <?php esc_html_e('Promuovi i nuovi eventi a tutti i clienti del portale con segmentazione automatica ed esclusione intelligente di chi ha già acquistato.', 'dfn-theme'); ?>
                </p>
            </div>
            <div>
                <?php if ($active_tab === 'campaigns') : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new')); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23; padding: 6px 18px; height: auto; font-size: 14px; font-weight: 600; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,75,35,0.2);">
                        <span>✨</span> <?php esc_html_e('Nuova Campagna Promozionale', 'dfn-theme'); ?>
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
                📋 <?php esc_html_e('Tutte le Campagne', 'dfn-theme'); ?> (<?php echo esc_html($total_campaigns); ?>)
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new')); ?>" class="nav-tab <?php echo ($active_tab === 'new') ? 'nav-tab-active' : ''; ?>" style="<?php echo ($active_tab === 'new') ? 'border-top: 3px solid #004b23; color: #004b23; font-weight: 600;' : ''; ?>">
                ✨ <?php esc_html_e('Crea Nuova Campagna', 'dfn-theme'); ?>
            </a>
        </h2>

        <!-- Tab 1: Elenco Campagne -->
        <?php if ($active_tab === 'campaigns') : ?>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                <?php if (empty($promotions)) : ?>
                    <div style="padding: 60px 20px; text-align: center; color: #64748b;">
                        <span style="font-size: 48px; display: block; margin-bottom: 12px;">📭</span>
                        <h3 style="font-size: 18px; font-weight: 600; color: #1e293b; margin: 0 0 8px 0;"><?php esc_html_e('Nessuna campagna promozionale creata', 'dfn-theme'); ?></h3>
                        <p style="margin: 0 0 20px 0; font-size: 14px;"><?php esc_html_e('Crea la tua prima newsletter promozionale per presentare un evento in arrivo a tutti i tuoi clienti.', 'dfn-theme'); ?></p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=dfn-promotions&tab=new')); ?>" class="button button-primary" style="background: #004b23; border-color: #004b23; font-weight: 600; padding: 6px 18px; height: auto;">
                            <?php esc_html_e('Crea la prima campagna ora', 'dfn-theme'); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped table-view-list" style="border: none;">
                        <thead>
                            <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                                <th style="font-weight: 600; color: #475569; width: 60px;">ID</th>
                                <th style="font-weight: 600; color: #475569; width: 220px;"><?php esc_html_e('Campagna & Oggetto', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569;"><?php esc_html_e('Evento Promosso', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 130px;"><?php esc_html_e('Stato', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 240px;"><?php esc_html_e('Avanzamento Invii', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 140px;"><?php esc_html_e('Data Creazione', 'dfn-theme'); ?></th>
                                <th style="font-weight: 600; color: #475569; width: 200px; text-align: right;"><?php esc_html_e('Azioni', 'dfn-theme'); ?></th>
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
                                        <strong style="color: #0f172a; font-size: 14px;"><?php echo esc_html($promo->title); ?></strong>
                                        <div style="color: #64748b; font-size: 12px; margin-top: 3px;">
                                            ✉️ <?php echo esc_html($promo->email_subject); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 600; color: #004b23;">
                                            <?php echo esc_html($promo->event_title); ?>
                                        </div>
                                        <div style="color: #64748b; font-size: 12px; margin-top: 2px;">
                                            📅 <?php echo esc_html($promo->event_date_start ? date_i18n('d/m/Y', strtotime($promo->event_date_start)) : '-'); ?>
                                            <?php if (! empty($promo->city)) : ?> • 📍 <?php echo esc_html($promo->city); ?><?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($promo->status === 'completed') : ?>
                                            <span style="display: inline-block; background: #dcfce7; color: #15803d; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                ✓ <?php esc_html_e('Completata', 'dfn-theme'); ?>
                                            </span>
                                        <?php elseif ($promo->status === 'sending') : ?>
                                            <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                ⏳ <?php esc_html_e('In invio...', 'dfn-theme'); ?>
                                            </span>
                                        <?php elseif ($promo->status === 'cancelled') : ?>
                                            <span style="display: inline-block; background: #fee2e2; color: #b91c1c; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                ✕ <?php esc_html_e('Annullata', 'dfn-theme'); ?>
                                            </span>
                                        <?php else : ?>
                                            <span style="display: inline-block; background: #f1f5f9; color: #475569; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">
                                                📝 <?php esc_html_e('Bozza', 'dfn-theme'); ?>
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
                                                ⚠️ <?php echo esc_html($fail); ?> invii non riusciti
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: #64748b; font-size: 13px;">
                                        <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($promo->created_at))); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                                            <?php if ($promo->status === 'sending') : ?>
                                                <button type="button" class="button button-small dfn-process-batch-btn" data-id="<?php echo esc_attr($promo->id); ?>" title="<?php esc_attr_e('Invia subito il prossimo lotto di 25 email senza attendere il cron', 'dfn-theme'); ?>" style="color: #0369a1; border-color: #0284c7; font-weight: 600;">
                                                    ▶️ <?php esc_html_e('Invia lotto', 'dfn-theme'); ?>
                                                </button>
                                                <button type="button" class="button button-small dfn-cancel-promo-btn" data-id="<?php echo esc_attr($promo->id); ?>" style="color: #dc2626; border-color: #fca5a5;">
                                                    ⏹️ <?php esc_html_e('Ferma', 'dfn-theme'); ?>
                                                </button>
                                            <?php endif; ?>

                                            <button type="button" class="button button-small dfn-delete-promo-btn" data-id="<?php echo esc_attr($promo->id); ?>" style="color: #94a3b8; border-color: #cbd5e1;">
                                                🗑️
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
                    <h3 style="color: #92400e; margin: 0 0 6px 0; font-size: 16px;">⚠️ <?php esc_html_e('Nessun evento futuro disponibile per la promozione', 'dfn-theme'); ?></h3>
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
                        <form id="dfn-promotion-form">
                            <?php wp_nonce_field('dfn_promotions_action', 'dfn_promotions_nonce'); ?>

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
                                <select id="promotion_event_id" name="event_id" style="width: 100%; max-width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px; height: 42px;">
                                    <option value=""><?php esc_html_e('-- Seleziona un evento da promuovere --', 'dfn-theme'); ?></option>
                                    <?php foreach ($future_events as $ev) :
                                        $d_str = date_i18n('d/m/Y', strtotime($ev->event_date_start));
                                        $loc   = $ev->city ? "({$ev->city})" : '';
                                        ?>
                                        <option value="<?php echo esc_attr($ev->id); ?>" 
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
                                        <span>🎯</span> <?php esc_html_e('Segmentazione Intelligente del Pubblico', 'dfn-theme'); ?>
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
                                    <input type="text" id="promotion_title" name="title" placeholder="<?php esc_attr_e('Es. Lancio Visite Guidate Castello', 'dfn-theme'); ?>" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" required />
                                    <span style="color: #64748b; font-size: 12px;"><?php esc_html_e('Visibile solo a te per identificare la campagna in elenco.', 'dfn-theme'); ?></span>
                                </div>

                                <div style="margin-bottom: 14px;">
                                    <label for="promotion_email_subject" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Oggetto dell\'email:', 'dfn-theme'); ?> <span style="color: #dc2626;">*</span>
                                    </label>
                                    <input type="text" id="promotion_email_subject" name="email_subject" placeholder="<?php esc_attr_e('Es. Scopri il nostro nuovo evento: Visita Guidata al Castello!', 'dfn-theme'); ?>" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" required />
                                    <span style="color: #64748b; font-size: 12px;"><?php esc_html_e('L\'oggetto visualizzato nella casella di posta dei destinatari.', 'dfn-theme'); ?></span>
                                </div>

                                <div style="margin-bottom: 14px;">
                                    <label for="promotion_email_intro" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Testo Introduttivo / Saluto iniziale:', 'dfn-theme'); ?>
                                    </label>
                                    <textarea id="promotion_email_intro" name="email_intro" rows="3" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" placeholder="<?php esc_attr_e('Siamo lieti di presentarti una nuova entusiasmante iniziativa culturale sul nostro territorio. Ti invitiamo a scoprire tutti i dettagli in anteprima!', 'dfn-theme'); ?>">Siamo lieti di presentarti una nuova entusiasmante iniziativa culturale sul nostro territorio. Ti invitiamo a scoprire tutti i dettagli in anteprima!</textarea>
                                </div>

                                <div style="margin-bottom: 14px;">
                                    <label for="promotion_email_custom_text" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Testo personalizzato aggiuntivo (sotto la card evento):', 'dfn-theme'); ?>
                                    </label>
                                    <textarea id="promotion_email_custom_text" name="email_custom_text" rows="3" style="width: 100%; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" placeholder="<?php esc_attr_e('La disponibilità dei posti è limitata e l\'accesso avverrà a piccoli gruppi per garantire un\'esperienza esclusiva.', 'dfn-theme'); ?>">La disponibilità dei posti è limitata per garantire un'esperienza esclusiva. Ti consigliamo di prenotare il tuo turno con anticipo.</textarea>
                                </div>

                                <div>
                                    <label for="promotion_cta_text" style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 6px;">
                                        <?php esc_html_e('Testo del pulsante di prenotazione (CTA):', 'dfn-theme'); ?>
                                    </label>
                                    <input type="text" id="promotion_cta_text" name="cta_text" value="Prenota il tuo posto" style="width: 100%; max-width: 300px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 14px;" />
                                </div>
                            </div>

                            <!-- STEP 3: Invio Prova & Lancio Campagna -->
                            <div>
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <span style="background: #004b23; color: #fff; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">3</span>
                                    <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #0f172a;"><?php esc_html_e('Test & Invio Campagna', 'dfn-theme'); ?></h3>
                                </div>

                                <!-- Box Invio Test Rapido -->
                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;">
                                    <div style="font-weight: 600; color: #334155; font-size: 13px; margin-bottom: 6px;">
                                        🧪 <?php esc_html_e('Invia un\'email di prova alla tua casella:', 'dfn-theme'); ?>
                                    </div>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <input type="email" id="dfn_test_email_address" value="<?php echo esc_attr($admin_email); ?>" style="flex-grow: 1; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;" />
                                        <button type="button" id="dfn-send-test-btn" class="button" style="padding: 5px 14px; height: auto; font-size: 13px; font-weight: 600;">
                                            ✉️ <?php esc_html_e('Invia Prova', 'dfn-theme'); ?>
                                        </button>
                                    </div>
                                    <div id="dfn-test-status" style="font-size: 12px; margin-top: 6px; display: none;"></div>
                                </div>

                                <!-- Pulsanti Finali -->
                                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                                    <button type="button" id="dfn-launch-campaign-btn" class="button button-primary" style="background: #004b23; border-color: #004b23; padding: 8px 24px; height: auto; font-size: 15px; font-weight: 700; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px;">
                                        <span>🚀</span> <?php esc_html_e('Avvia Invio Campagna', 'dfn-theme'); ?>
                                    </button>

                                    <button type="button" id="dfn-save-draft-btn" class="button" style="padding: 8px 18px; height: auto; font-size: 14px; border-radius: 6px;">
                                        💾 <?php esc_html_e('Salva come Bozza', 'dfn-theme'); ?>
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
                                <strong style="font-size: 13px; letter-spacing: 0.5px; text-transform: uppercase;">📱 <?php esc_html_e('Anteprima Email in Tempo Reale', 'dfn-theme'); ?></strong>
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
                                            <div id="preview-event-img-wrap" style="width: 100%; height: 160px; background: #e2e8f0; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                                <img id="preview-event-img" src="" style="width: 100%; height: 100%; object-fit: cover; display: none;" />
                                                <span id="preview-event-placeholder" style="color: #94a3b8; font-size: 12px;">Immagine di copertina evento</span>
                                            </div>

                                            <div style="padding: 14px;">
                                                <div style="display: inline-block; background: #f0fdf4; color: #004b23; border: 1px solid #bbf7d0; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px;">
                                                    Nuovo Evento
                                                </div>

                                                <h4 id="preview-event-title" style="margin: 0 0 8px 0; font-size: 16px; font-weight: 700; color: #0f172a;">
                                                    Titolo dell'Evento Selezionato
                                                </h4>

                                                <div style="font-size: 12px; color: #475569; margin-bottom: 12px;">
                                                    <div id="preview-event-date" style="margin-bottom: 4px;">📅 Data da definire</div>
                                                    <div id="preview-event-loc">📍 Luogo da definire</div>
                                                </div>

                                                <div id="preview-event-desc" style="font-size: 12px; color: #64748b; margin-bottom: 14px; line-height: 1.4; border-top: 1px dashed #e2e8f0; padding-top: 10px;">
                                                    Descrizione dell'evento...
                                                </div>

                                                <div style="text-align: center;">
                                                    <span id="preview-cta-btn" style="display: inline-block; background: #004b23; color: #fff; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 700; text-decoration: none;">
                                                        🎟️ Prenota il tuo posto
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
            $('#preview-cta-btn').html('🎟️ ' + cta);

            if (selectedEv.val()) {
                var title = selectedEv.data('title');
                var date = selectedEv.data('date');
                var loc = selectedEv.data('loc');
                var img = selectedEv.data('image');
                var desc = selectedEv.data('desc');

                $('#preview-event-title').text(title);
                $('#preview-event-date').text('📅 ' + date);
                $('#preview-event-loc').text('📍 ' + loc);
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
                $('#preview-event-date').text('📅 Data da definire');
                $('#preview-event-loc').text('📍 Luogo da definire');
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
                $btn.prop('disabled', false).html('✉️ Invia Prova');
                if (res.success) {
                    $status.html('<span style="color: #166534; font-weight: 600;">✓ ' + res.data.message + '</span>').show();
                } else {
                    $status.html('<span style="color: #dc2626; font-weight: 600;">✕ ' + (res.data ? res.data.message : 'Errore durante l\'invio di test') + '</span>').show();
                }
            }).fail(function() {
                $btn.prop('disabled', false).html('✉️ Invia Prova');
                $status.html('<span style="color: #dc2626;">✕ Errore di connessione al server</span>').show();
            });
        });

        // 3. Salva Campagna (Bozza o Avvia Invio)
        function submitCampaign(isLaunch) {
            var eventId = $('#promotion_event_id').val();
            var title = $('#promotion_title').val().trim();
            var subject = $('#promotion_email_subject').val().trim();
            var intro = $('#promotion_email_intro').val().trim();
            var custom = $('#promotion_email_custom_text').val().trim();
            var cta = $('#promotion_cta_text').val().trim();

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
                var eligibleCount = $('#stat-eligible-recipients').text();
                var msg = 'Sei sicuro di voler avviare questa campagna promozionale?\n';
                if (eligibleCount && eligibleCount !== '...') {
                    msg += 'Verranno inviate email a circa ' + eligibleCount + ' clienti unici del portale.';
                }
                if (!confirm(msg)) {
                    return;
                }
            }

            $('#dfn-submit-spinner').addClass('is-active');
            $('#dfn-launch-campaign-btn, #dfn-save-draft-btn').prop('disabled', true);
            var $msg = $('#dfn-form-msg');
            $msg.hide();

            $.post(ajaxurl, {
                action: 'dfn_promotions_save_campaign',
                nonce: nonce,
                event_id: eventId,
                title: title,
                subject: subject,
                intro: intro,
                custom_text: custom,
                cta_text: cta,
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

        // 4. Azione: Elabora Lotto Manuale (Batch)
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
                    $btn.prop('disabled', false).html('▶️ Invia lotto');
                }
            }).fail(function() {
                alert('Errore di comunicazione con il server');
                $btn.prop('disabled', false).html('▶️ Invia lotto');
            });
        });

        // 5. Azione: Annulla Campagna
        $('.dfn-cancel-promo-btn').on('click', function(e) {
            e.preventDefault();
            if (!confirm('Vuoi interrompere l\'invio di questa campagna? I destinatari rimanenti non riceveranno l\'email.')) {
                return;
            }
            var promoId = $(this).data('id');
            $.post(ajaxurl, {
                action: 'dfn_promotions_cancel_campaign',
                nonce: nonce,
                promo_id: promoId
            }, function(res) {
                if (res.success) {
                    location.reload();
                } else {
                    alert('Errore: ' + (res.data ? res.data.message : 'Impossibile annullare la campagna'));
                }
            });
        });

        // 6. Azione: Elimina Campagna
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

    });
    </script>
    <?php
}

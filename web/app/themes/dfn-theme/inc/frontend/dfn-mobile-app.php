<?php

/**
 * DFN Mobile Web App & PWA Hub — Gestione Eventi Mobile (/gestione-eventi/)
 *
 * Fornisce un'interfaccia mobile-first completa, protetta da login e ottimizzata
 * per l'uso su smartphone e tablet sul campo per la verifica biglietti QR, 
 * l'inserimento rapido prenotazioni, il botteghino live e la validazione tessere FAI.
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registra lo Shortcode [dfn_mobile_app]
 */
function dfn_mobile_app_shortcode(): string
{
    ob_start();
    dfn_render_mobile_app();
    return ob_get_clean();
}
add_shortcode('dfn_mobile_app', 'dfn_mobile_app_shortcode');

/**
 * Crea o assicura l'esistenza della pagina WordPress "Gestione Eventi" (/gestione-eventi/)
 * contenente lo shortcode [dfn_mobile_app].
 *
 * @return void
 */
function dfn_auto_create_mobile_app_page(): void
{
    if (get_option('dfn_mobile_app_page_v211') === 'yes') {
        return;
    }

    $page_slug  = 'gestione-eventi';
    $page_title = 'Gestione Eventi Mobile';

    $existing_page = get_page_by_path($page_slug);

    if (! $existing_page) {
        $page_id = wp_insert_post([
            'post_title'     => $page_title,
            'post_name'      => $page_slug,
            'post_content'   => '[dfn_mobile_app]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ]);
        if ($page_id && ! is_wp_error($page_id)) {
            update_option('dfn_mobile_app_page_id', $page_id);
        }
    } else {
        if (strpos($existing_page->post_content, '[dfn_mobile_app]') === false) {
            wp_update_post([
                'ID'           => $existing_page->ID,
                'post_content' => $existing_page->post_content . "\n[dfn_mobile_app]",
            ]);
        }
    }

    update_option('dfn_mobile_app_page_v211', 'yes');
}
add_action('init', 'dfn_auto_create_mobile_app_page');

/**
 * Renderizza l'intera applicazione mobile o la schermata di login se non autenticato.
 *
 * @return void
 */
function dfn_render_mobile_app(): void
{
    // 1. VERIFICA AUTENTICAZIONE
    if (! is_user_logged_in()) {
        dfn_render_mobile_login();
        return;
    }

    $current_user = wp_get_current_user();

    // 2. VERIFICA PERMESSI / CAPABILITY
    $has_access = current_user_can('manage_options') 
               || current_user_can('dfn_manage_events') 
               || current_user_can('dfn_quick_booking') 
               || current_user_can('dfn_use_scanner')
               || current_user_can('dfn_checkin_and_collect');

    if (! $has_access) {
        dfn_render_mobile_access_denied($current_user);
        return;
    }

    // 3. RECUPERO DATI PER LA DASHBOARD HOME
    global $wpdb;

    // A. Eventi in arrivo (prossimi 5 eventi pubblicati)
    $table_events = $wpdb->prefix . 'dfn_events';
    $today        = date('Y-m-d');
    $events       = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_events} 
         WHERE status = 'published' AND event_date_start >= %s 
         ORDER BY event_date_start ASC, event_time_start ASC LIMIT 5",
        $today
    ));

    // B. Prenotazioni da confermare (ultime 10 prenotazioni pendenti)
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $pending_bookings = $wpdb->get_results(
        "SELECT b.*, e.location 
         FROM {$table_bookings} b 
         LEFT JOIN {$table_events} e ON b.event_id = e.id 
         WHERE b.status IN ('pending', 'pending_fai_verification') 
         ORDER BY b.created_at DESC LIMIT 10"
    );

    // C. Soci / Tessere FAI in attesa di validazione
    $table_fai = $wpdb->prefix . 'dfn_fai_members';
    $pending_fai = $wpdb->get_results(
        "SELECT * FROM {$table_fai} 
         WHERE verified = 0 
         ORDER BY created_at DESC LIMIT 10"
    );

    // Contatori per i badge
    $count_pending_bookings = count($pending_bookings);
    $count_pending_fai      = count($pending_fai);
    $count_events           = count($events);

    // Dati Utente
    $user_display_name = ! empty($current_user->display_name) ? $current_user->display_name : $current_user->user_login;
    $user_initials     = strtoupper(substr($current_user->first_name ?: $current_user->user_login, 0, 1) . substr($current_user->last_name ?: '', 0, 1));
    if (empty(trim($user_initials))) {
        $user_initials = strtoupper(substr($current_user->user_login, 0, 2));
    }
    
    $user_roles = (array) $current_user->roles;
    $primary_role_name = 'Operatore Staff';
    if (in_array('administrator', $user_roles, true)) {
        $primary_role_name = 'Amministratore';
    } elseif (in_array('dfn_manager', $user_roles, true)) {
        $primary_role_name = 'Gestore Eventi';
    } elseif (in_array('dfn_volunteer', $user_roles, true)) {
        $primary_role_name = 'Volontario FAI';
    }

    $nonces = [
        'booking' => wp_create_nonce('dfn_booking_nonce'),
        'scanner' => wp_create_nonce('dfn_scanner_nonce'),
        'admin'   => wp_create_nonce('dfn_admin_events_nonce'),
        'fai'     => wp_create_nonce('dfn_fai_admin_nonce'),
    ];
    ?>

    <div id="dfn-mobile-app-root" class="dfn-mobile-app-root" data-nonces='<?php echo esc_attr(json_encode($nonces)); ?>'>
        
        <!-- HEADER MOBILE -->
        <header class="dfn-mobile-app-header">
            <div class="dfn-mobile-header-brand">
                <img src="/app/uploads/2026/07/cropped-logo_fai_trasparente.png" class="dfn-mobile-logo-img" alt="FAI Logo" />
                <div class="dfn-mobile-brand-titles">
                    <h1>FAI Novara</h1>
                    <span>Gestione Eventi Mobile</span>
                </div>
            </div>
            <div class="dfn-mobile-header-actions">
                <button type="button" id="dfn-pwa-install-btn" class="dfn-mobile-icon-btn" title="Installa App" style="display:none;">
                    <span class="dashicons dashicons-download"></span>
                </button>
                <button type="button" class="dfn-mobile-icon-btn dfn-btn-refresh" id="dfn-mobile-refresh-btn" title="Aggiorna Dati">
                    <span class="dashicons dashicons-update"></span>
                </button>
            </div>
        </header>

        <!-- CONTENITORE VISTE TAB -->
        <main class="dfn-mobile-app-main">

            <!-- ======================================================== -->
            <!-- TAB 1: DASHBOARD HOME -->
            <!-- ======================================================== -->
            <section id="dfn-tab-home" class="dfn-mobile-tab-pane active">
                
                <!-- SCHEDA RIEPILOGO UTENTE -->
                <div class="dfn-mobile-card dfn-user-summary-card">
                    <div class="dfn-user-avatar">
                        <?php echo esc_html($user_initials); ?>
                    </div>
                    <div class="dfn-user-info">
                        <h2><?php echo esc_html($user_display_name); ?></h2>
                        <div class="dfn-user-role-badge">
                            <span class="dfn-role-dot"></span>
                            <span class="dfn-role-name"><?php echo esc_html($primary_role_name); ?></span>
                        </div>
                    </div>
                    <button type="button" class="dfn-user-profile-btn" data-target-tab="profile" title="Area Personale">
                        <span class="dashicons dashicons-admin-users"></span>
                    </button>
                </div>

                <!-- CONTATORI STATISTICHE RAPIDE -->
                <div class="dfn-mobile-stats-grid">
                    <div class="dfn-stat-pill" data-target-tab="home" data-scroll-to="sec-upcoming">
                        <span class="dfn-stat-val"><?php echo intval($count_events); ?></span>
                        <span class="dfn-stat-lbl">Eventi In Arrivo</span>
                    </div>
                    <div class="dfn-stat-pill <?php echo $count_pending_bookings > 0 ? 'warning' : ''; ?>" data-target-tab="home" data-scroll-to="sec-bookings">
                        <span class="dfn-stat-val"><?php echo intval($count_pending_bookings); ?></span>
                        <span class="dfn-stat-lbl">Da Confermare</span>
                    </div>
                    <div class="dfn-stat-pill <?php echo $count_pending_fai > 0 ? 'info' : ''; ?>" data-target-tab="home" data-scroll-to="sec-fai">
                        <span class="dfn-stat-val"><?php echo intval($count_pending_fai); ?></span>
                        <span class="dfn-stat-lbl">Tessere FAI</span>
                    </div>
                </div>

                <!-- SEZIONE 1: EVENTI IN ARRIVO -->
                <div id="sec-upcoming" class="dfn-mobile-section">
                    <div class="dfn-section-title">
                        <h3>📅 Prossimi Eventi</h3>
                        <span class="dfn-badge-count"><?php echo intval($count_events); ?></span>
                    </div>

                    <?php if (! empty($events)) : ?>
                        <div class="dfn-mobile-cards-list">
                            <?php foreach ($events as $ev) : 
                                $date_formatted = date_i18n('d M Y', strtotime($ev->event_date_start));
                                $time_formatted = date('H:i', strtotime($ev->event_time_start));
                                ?>
                                <div class="dfn-mobile-card dfn-event-card-item">
                                    <div class="dfn-event-card-top">
                                        <span class="dfn-event-date-badge">📅 <?php echo esc_html($date_formatted); ?> • ⏰ <?php echo esc_html($time_formatted); ?></span>
                                        <span class="dfn-event-status-pill open">Aperto</span>
                                    </div>
                                    <h4 class="dfn-event-title"><?php echo esc_html(get_the_title($ev->product_id)); ?></h4>
                                    <p class="dfn-event-location">📍 <?php echo esc_html($ev->location ?: 'Novara'); ?></p>
                                    <div class="dfn-event-card-actions">
                                        <button type="button" class="dfn-mobile-btn primary btn-quick-book-event" data-event-id="<?php echo absint($ev->id); ?>">
                                            ⚡ Prenota Ora
                                        </button>
                                        <button type="button" class="dfn-mobile-btn secondary btn-botteghino-event" data-event-id="<?php echo absint($ev->id); ?>">
                                            🎟️ Botteghino
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="dfn-mobile-empty-state">
                            <p>Nessun evento in arrivo registrato.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SEZIONE 2: PRENOTAZIONI DA CONFERMARE -->
                <div id="sec-bookings" class="dfn-mobile-section">
                    <div class="dfn-section-title">
                        <h3>📋 Prenotazioni da Confermare</h3>
                        <?php if ($count_pending_bookings > 0) : ?>
                            <span class="dfn-badge-count warning"><?php echo intval($count_pending_bookings); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (! empty($pending_bookings)) : ?>
                        <div class="dfn-mobile-cards-list">
                            <?php foreach ($pending_bookings as $b) : ?>
                                <div class="dfn-mobile-card dfn-booking-card-item" id="dfn-booking-card-<?php echo absint($b->id); ?>">
                                    <div class="dfn-booking-card-header">
                                        <strong class="dfn-customer-name"><?php echo esc_html($b->customer_name); ?></strong>
                                        <span class="dfn-booking-status-tag pending">In Attesa</span>
                                    </div>
                                    <div class="dfn-booking-details">
                                        <p>📧 <?php echo esc_html($b->customer_email); ?></p>
                                        <?php if ($b->customer_phone) : ?>
                                            <p>📞 <?php echo esc_html($b->customer_phone); ?></p>
                                        <?php endif; ?>
                                        <p>👥 <strong><?php echo intval($b->total_persons); ?> Persone</strong> (Intero: <?php echo intval($b->persons_standard); ?>, FAI: <?php echo intval($b->persons_fai); ?>)</p>
                                        <?php if ($b->amount_due > 0) : ?>
                                            <p>💶 Da incassare: <strong>€<?php echo number_format(floatval($b->amount_due), 2, ',', '.'); ?></strong></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="dfn-booking-actions">
                                        <button type="button" class="dfn-mobile-btn success btn-confirm-booking" data-booking-id="<?php echo absint($b->id); ?>">
                                            ✅ Conferma subito
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="dfn-mobile-empty-state">
                            <p>🎉 Nessuna prenotazione in attesa di conferma.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- SEZIONE 3: SOCI FAI DA VALIDARE -->
                <div id="sec-fai" class="dfn-mobile-section">
                    <div class="dfn-section-title">
                        <h3>🪪 Tessere FAI da Validare</h3>
                        <?php if ($count_pending_fai > 0) : ?>
                            <span class="dfn-badge-count info"><?php echo intval($count_pending_fai); ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if (! empty($pending_fai)) : ?>
                        <div class="dfn-mobile-cards-list">
                            <?php foreach ($pending_fai as $f) : ?>
                                <div class="dfn-mobile-card dfn-fai-card-item" id="dfn-fai-card-<?php echo absint($f->id); ?>">
                                    <div class="dfn-fai-card-header">
                                        <strong><?php echo esc_html($f->first_name . ' ' . $f->last_name); ?></strong>
                                        <span class="dfn-card-number">N° <?php echo esc_html($f->card_number); ?></span>
                                    </div>
                                    <div class="dfn-fai-details">
                                        <?php if ($f->email) : ?><p>📧 <?php echo esc_html($f->email); ?></p><?php endif; ?>
                                        <?php if ($f->card_expiry) : ?><p>📅 Scadenza: <?php echo esc_html(date('d/m/Y', strtotime($f->card_expiry))); ?></p><?php endif; ?>
                                    </div>
                                    <div class="dfn-fai-actions">
                                        <button type="button" class="dfn-mobile-btn success btn-validate-fai" data-fai-id="<?php echo absint($f->id); ?>">
                                            🪪 Valida Tessera
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div class="dfn-mobile-empty-state">
                            <p>Tutte le tessere FAI risultano verificate.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </section>


            <!-- ======================================================== -->
            <!-- TAB 2: SCANNER LIVE -->
            <!-- ======================================================== -->
            <section id="dfn-tab-scanner" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card dfn-scanner-card">
                    <div class="dfn-scanner-header">
                        <h3>🔍 Verifica QR Code Biglietti</h3>
                        <p>Inquadra il codice QR del biglietto o inserisci il codice manuale.</p>
                    </div>

                    <!-- Contenitore Videocamera Scanner -->
                    <div class="dfn-scanner-camera-viewport">
                        <video id="dfn-mobile-scanner-video" playsinline></video>
                        <canvas id="dfn-mobile-scanner-canvas" style="display:none;"></canvas>
                        <div class="dfn-scanner-overlay-grid">
                            <div class="dfn-scanner-corner top-left"></div>
                            <div class="dfn-scanner-corner top-right"></div>
                            <div class="dfn-scanner-corner bottom-left"></div>
                            <div class="dfn-scanner-corner bottom-right"></div>
                            <div class="dfn-scanner-laser"></div>
                        </div>
                    </div>

                    <div class="dfn-scanner-controls">
                        <button type="button" id="dfn-btn-toggle-camera" class="dfn-mobile-btn secondary">
                            📷 Cambia Fotocamera
                        </button>
                    </div>

                    <!-- Form Manuale Fallback -->
                    <div class="dfn-scanner-manual-form">
                        <label for="dfn-scanner-manual-input">Inserisci Codice QR / Token:</label>
                        <div class="dfn-input-group">
                            <input type="text" id="dfn-scanner-manual-input" placeholder="Es. DFN-12345678" />
                            <button type="button" id="dfn-btn-submit-manual-qr" class="dfn-mobile-btn primary">Verifica</button>
                        </div>
                    </div>

                    <!-- Output Risultato Check-in -->
                    <div id="dfn-scanner-result-box" class="dfn-scanner-result-box" style="display:none;"></div>
                </div>
            </section>


            <!-- ======================================================== -->
            <!-- TAB 3: INSERIMENTO RAPIDO PRENOTAZIONE -->
            <!-- ======================================================== -->
            <section id="dfn-tab-quick" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card">
                    <h3>⚡ Inserimento Rapido Prenotazione</h3>
                    <p class="dfn-subtitle">Registra una nuova prenotazione prima o durante l'evento.</p>

                    <form id="dfn-mobile-quick-booking-form" class="dfn-mobile-form">
                        <div class="dfn-form-group">
                            <label for="dfn-qb-event">Evento *</label>
                            <select id="dfn-qb-event" name="event_id" required>
                                <option value="">Seleziona Evento...</option>
                                <?php foreach ($events as $ev) : ?>
                                    <option value="<?php echo absint($ev->id); ?>"><?php echo esc_html(get_the_title($ev->product_id)); ?> (<?php echo esc_html(date('d/m/Y', strtotime($ev->event_date_start))); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-qb-name">Nome e Cognome Referente *</label>
                            <input type="text" id="dfn-qb-name" name="customer_name" required placeholder="Es. Mario Rossi" />
                        </div>

                        <div class="dfn-form-row">
                            <div class="dfn-form-group">
                                <label for="dfn-qb-email">Email *</label>
                                <input type="email" id="dfn-qb-email" name="customer_email" required placeholder="mario@example.com" />
                            </div>
                            <div class="dfn-form-group">
                                <label for="dfn-qb-phone">Telefono</label>
                                <input type="tel" id="dfn-qb-phone" name="customer_phone" placeholder="+39 340..." />
                            </div>
                        </div>

                        <div class="dfn-form-row">
                            <div class="dfn-form-group">
                                <label for="dfn-qb-qty-std">Biglietti Intero (€)</label>
                                <input type="number" id="dfn-qb-qty-std" name="persons_standard" min="0" value="1" />
                            </div>
                            <div class="dfn-form-group">
                                <label for="dfn-qb-qty-fai">Biglietti FAI (€)</label>
                                <input type="number" id="dfn-qb-qty-fai" name="persons_fai" min="0" value="0" />
                            </div>
                        </div>

                        <button type="submit" class="dfn-mobile-btn primary large">
                            ✅ Salva e Conferma Prenotazione
                        </button>
                    </form>
                </div>
            </section>


            <!-- ======================================================== -->
            <!-- TAB 4: BOTTEGHINO LIVE (INCASSI E BIGLIETTERIA) -->
            <!-- ======================================================== -->
            <section id="dfn-tab-botteghino" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card">
                    <h3>🎟️ Botteghino Live</h3>
                    <p class="dfn-subtitle">Emetti biglietti sul posto con pagamento in contanti o POS.</p>

                    <form id="dfn-mobile-botteghino-form" class="dfn-mobile-form">
                        <div class="dfn-form-group">
                            <label for="dfn-bot-event">Evento In Corso *</label>
                            <select id="dfn-bot-event" name="event_id" required>
                                <option value="">Seleziona Evento...</option>
                                <?php foreach ($events as $ev) : ?>
                                    <option value="<?php echo absint($ev->id); ?>"><?php echo esc_html(get_the_title($ev->product_id)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-bot-name">Nome Acquirente</label>
                            <input type="text" id="dfn-bot-name" name="customer_name" placeholder="Botteghino Volante / Nome" />
                        </div>

                        <div class="dfn-form-row">
                            <div class="dfn-form-group">
                                <label for="dfn-bot-std">N° Interi</label>
                                <input type="number" id="dfn-bot-std" name="persons_standard" min="0" value="1" />
                            </div>
                            <div class="dfn-form-group">
                                <label for="dfn-bot-fai">N° FAI</label>
                                <input type="number" id="dfn-bot-fai" name="persons_fai" min="0" value="0" />
                            </div>
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-bot-payment">Metodo Pagamento Incassato *</label>
                            <select id="dfn-bot-payment" name="payment_method" required>
                                <option value="contanti">💵 Contanti in Loco</option>
                                <option value="pos">💳 POS / Carta di Credito</option>
                                <option value="omaggio">🎁 Omaggio / Riservato</option>
                            </select>
                        </div>

                        <button type="submit" class="dfn-mobile-btn success large">
                            💶 Emetti Biglietto & Registra Incasso
                        </button>
                    </form>
                </div>
            </section>


            <!-- ======================================================== -->
            <!-- TAB 5: AREA PERSONALE -->
            <!-- ======================================================== -->
            <section id="dfn-tab-profile" class="dfn-mobile-tab-pane">
                <div class="dfn-mobile-card">
                    <div class="dfn-profile-card-header">
                        <div class="dfn-user-avatar large">
                            <?php echo esc_html($user_initials); ?>
                        </div>
                        <h3><?php echo esc_html($user_display_name); ?></h3>
                        <p class="dfn-user-email">📧 <?php echo esc_html($current_user->user_email); ?></p>
                        <span class="dfn-role-name-tag"><?php echo esc_html($primary_role_name); ?></span>
                    </div>

                    <form id="dfn-mobile-profile-form" class="dfn-mobile-form" style="margin-top:20px;">
                        <h4>Modifica Profilo & Password</h4>

                        <div class="dfn-form-group">
                            <label for="dfn-prof-name">Nome Visualizzato</label>
                            <input type="text" id="dfn-prof-name" name="display_name" value="<?php echo esc_attr($current_user->display_name); ?>" required />
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-prof-email">Email</label>
                            <input type="email" id="dfn-prof-email" name="user_email" value="<?php echo esc_attr($current_user->user_email); ?>" required />
                        </div>

                        <div class="dfn-form-group">
                            <label for="dfn-prof-pass">Nuova Password (lascia vuoto se invariata)</label>
                            <input type="password" id="dfn-prof-pass" name="new_password" placeholder="Nuova password..." />
                        </div>

                        <button type="submit" class="dfn-mobile-btn primary">
                            💾 Salva Modifiche Profilo
                        </button>
                    </form>

                    <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                        <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>" class="dfn-mobile-btn danger large" style="text-align:center; text-decoration:none; display:block;">
                            🚪 Disconnettiti / Logout
                        </a>
                    </div>
                </div>
            </section>

        </main>

        <!-- BOTTOM TAB BAR NAVIGATION -->
        <nav class="dfn-mobile-tab-bar">
            <button type="button" class="dfn-tab-btn active" data-tab="home">
                <span class="dashicons dashicons-dashboard"></span>
                <span class="dfn-tab-lbl">Home</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="scanner">
                <span class="dashicons dashicons-qr"></span>
                <span class="dfn-tab-lbl">Scanner</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="quick">
                <span class="dashicons dashicons-plus-alt2"></span>
                <span class="dfn-tab-lbl">Rapido</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="botteghino">
                <span class="dashicons dashicons-tickets-alt"></span>
                <span class="dfn-tab-lbl">Botteghino</span>
            </button>
            <button type="button" class="dfn-tab-btn" data-tab="profile">
                <span class="dashicons dashicons-admin-users"></span>
                <span class="dfn-tab-lbl">Profilo</span>
            </button>
        </nav>

        <!-- TOAST FEEDBACK NOTIFICATIONS -->
        <div id="dfn-mobile-toast" class="dfn-mobile-toast" style="display:none;"></div>
    </div>

    <?php
}

/**
 * Renderizza la schermata di login mobile per utenti non autenticati.
 *
 * @return void
 */
function dfn_render_mobile_login(): void
{
    $login_error = '';
    if (isset($_POST['dfn_mobile_login_nonce']) && wp_verify_nonce($_POST['dfn_mobile_login_nonce'], 'dfn_mobile_login_action')) {
        $creds = [
            'user_login'    => sanitize_text_field($_POST['log'] ?? ''),
            'user_password' => $_POST['pwd'] ?? '',
            'remember'      => true,
        ];
        $user = wp_signon($creds, is_ssl());
        if (is_wp_error($user)) {
            $login_error = 'Credenziali non corrette. Riprova.';
        } else {
            wp_safe_redirect(get_permalink());
            exit;
        }
    }
    ?>
    <div class="dfn-mobile-login-wrapper">
        <div class="dfn-mobile-login-card">
            <div class="dfn-login-header">
                <img src="/app/uploads/2026/07/cropped-logo_fai_trasparente.png" class="dfn-login-logo-img" alt="FAI Logo" />
                <h2>FAI Novara — Gestione</h2>
                <p>Accedi con il tuo account operatore o amministratore per accedere all'App Mobile.</p>
            </div>

            <?php if ($login_error) : ?>
                <div class="dfn-login-alert error">
                    ⚠️ <?php echo esc_html($login_error); ?>
                </div>
            <?php endif; ?>

            <form method="post" class="dfn-mobile-form">
                <?php wp_nonce_field('dfn_mobile_login_action', 'dfn_mobile_login_nonce'); ?>
                
                <div class="dfn-form-group">
                    <label for="dfn-log-username">Nome Utente o Email</label>
                    <input type="text" id="dfn-log-username" name="log" required placeholder="Inserisci username..." autocomplete="username" />
                </div>

                <div class="dfn-form-group">
                    <label for="dfn-log-password">Password</label>
                    <input type="password" id="dfn-log-password" name="pwd" required placeholder="Inserisci password..." autocomplete="current-password" />
                </div>

                <button type="submit" class="dfn-mobile-btn primary large">
                    🔒 Accedi all'App Mobile
                </button>
            </form>
        </div>
    </div>
    <?php
}

/**
 * Renderizza la schermata di accesso negato se l'utente non possiede i ruoli autorizzati.
 *
 * @param WP_User $user Utente loggato.
 * @return void
 */
function dfn_render_mobile_access_denied(WP_User $user): void
{
    ?>
    <div class="dfn-mobile-login-wrapper">
        <div class="dfn-mobile-login-card">
            <div class="dfn-login-header">
                <span class="dfn-login-logo">⛔</span>
                <h2>Accesso Riservato</h2>
                <p>L'account <strong><?php echo esc_html($user->user_login); ?></strong> non dispone delle autorizzazioni necessarie per accedere all'App di gestione eventi.</p>
            </div>
            <div style="margin-top:20px;">
                <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>" class="dfn-mobile-btn secondary large" style="text-align:center; display:block; text-decoration:none;">
                    Disconnettiti
                </a>
            </div>
        </div>
    </div>
    <?php
}

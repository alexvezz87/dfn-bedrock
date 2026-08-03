<?php
/**
 * DFN Booking System 2.0 — Settings Panel
 *
 * Fornisce un pannello di controllo elegante e strutturato a tab verticali
 * per configurare tutte le costanti e i comportamenti del sistema FAI Prenotazioni.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'dfn_settings_register_menu');
add_action('wp_ajax_dfn_send_test_email', 'dfn_ajax_send_test_email');

/**
 * Registra il sottomenu delle Impostazioni sotto "FAI Prenotazioni".
 */
function dfn_settings_register_menu(): void
{
    add_submenu_page(
        'dfn-events',
        esc_html__('Impostazioni FAI Prenotazioni', 'dfn-theme'),
        esc_html__('Impostazioni', 'dfn-theme'),
        'dfn_manage_events',
        'dfn-settings',
        'dfn_render_settings_page',
    );
}

/**
 * Gestisce il salvataggio delle impostazioni inviate tramite POST.
 */
function dfn_settings_save_fields(): void
{
    if (! isset($_POST['dfn_settings_nonce']) || ! wp_verify_nonce($_POST['dfn_settings_nonce'], 'dfn_save_settings_action')) {
        return;
    }

    if (! current_user_can('dfn_manage_events')) {
        return;
    }

    $existing_settings = get_option('dfn_settings', []);
    if (! is_array($existing_settings)) {
        $existing_settings = [];
    }

    // Definizione dei campi e delle relative regole di sanitizzazione
    $fields_to_sanitize = [
        // Tab Generale
        'delegation_name'             => 'sanitize_text_field',
        'delegation_email'            => 'sanitize_email',
        'delegation_footer'           => 'sanitize_text_field',
        'email_staff_signature'       => 'sanitize_text_field',
        'default_placeholder_image_id'=> 'absint',

        // Tab Email & Notifiche
        'email_new_booking'           => 'sanitize_email_list',
        'email_verify_fai'            => 'sanitize_email_list',
        'email_cc'                    => 'sanitize_email_list',
        'email_bcc'                   => 'sanitize_email_list',
        'email_cc_bcc'                => 'sanitize_text_field',
        'email_primary_color'         => 'sanitize_hex_color',
        'email_accent_color'          => 'sanitize_hex_color',
        'email_bg_color'              => 'sanitize_hex_color',
        'email_text_color'            => 'sanitize_hex_color',
        'email_disclaimer'            => 'sanitize_textarea_field',

        // Tab Automazioni & Cron
        'cron_timeout_no_booking'     => 'absint',
        'cron_reminder_start'         => 'absint',
        'cron_reminder_end'           => 'absint',
        'cron_waitlist_ttl'           => 'absint',
        'cron_batch_reminder'         => 'absint',
        'cron_batch_expired'          => 'absint',

        // Tab Tessere FAI
        'fai_coupon_code'             => 'sanitize_text_field',
        'fai_expiry_warning_days'     => 'absint',
        'fai_member_types'            => 'sanitize_text_field',
        'fai_no_email_placeholder'    => 'sanitize_text_field',

        // Tab Limiti & Testi
        'limit_max_fai_members'       => 'absint',
        'limit_max_activity_logs'     => 'absint',
        'text_early_arrival'          => 'sanitize_text_field',
        'text_no_bookings_myaccount'  => 'sanitize_textarea_field',
        'text_checkout_btn'           => 'sanitize_text_field',

        'email_confirm_subject'       => 'sanitize_text_field',
        'email_confirm_title'         => 'sanitize_text_field',
        'email_confirm_intro'         => 'sanitize_textarea_field',
        'email_confirm_notes'         => 'sanitize_textarea_field',

        'email_modify_subject'        => 'sanitize_text_field',
        'email_modify_title'          => 'sanitize_text_field',
        'email_modify_intro'          => 'sanitize_textarea_field',
        'email_modify_notes'          => 'sanitize_textarea_field',

        'email_pending_subject'       => 'sanitize_text_field',
        'email_pending_title'         => 'sanitize_text_field',
        'email_pending_body'          => 'sanitize_textarea_field',

        'email_declined_subject'      => 'sanitize_text_field',
        'email_declined_title'        => 'sanitize_text_field',
        'email_declined_body'         => 'sanitize_textarea_field',

        'email_cancelled_subject'     => 'sanitize_text_field',
        'email_cancelled_title'       => 'sanitize_text_field',
        'email_cancelled_body'        => 'sanitize_textarea_field',

        'email_admin_cancelled_subject'=> 'sanitize_text_field',
        'email_admin_cancelled_title' => 'sanitize_text_field',
        'email_admin_cancelled_body'  => 'sanitize_textarea_field',

        'email_reminder_subject'      => 'sanitize_text_field',
        'email_reminder_title'        => 'sanitize_text_field',
        'email_reminder_intro'        => 'sanitize_textarea_field',
        'email_reminder_notes'        => 'sanitize_textarea_field',

        'email_waitlist_subject'      => 'sanitize_text_field',
        'email_waitlist_title'        => 'sanitize_text_field',
        'email_waitlist_body'         => 'sanitize_textarea_field',

        'email_fai_approved_subject'  => 'sanitize_text_field',
        'email_fai_approved_title'    => 'sanitize_text_field',
        'email_fai_approved_body'     => 'sanitize_textarea_field',

        'email_fai_rejected_subject'  => 'sanitize_text_field',
        'email_fai_rejected_title'    => 'sanitize_text_field',
        'email_fai_rejected_body'     => 'sanitize_textarea_field',

        'email_fai_booking_rejected_subject'  => 'sanitize_text_field',
        'email_fai_booking_rejected_title'    => 'sanitize_text_field',
        'email_fai_booking_rejected_body'     => 'sanitize_textarea_field',

        // Tab Avanzate & Toggle
        'enable_admin_notification'   => 'sanitize_text_field', // 'yes' o 'no'
        'enable_reminder_24h'         => 'sanitize_text_field', // 'yes' o 'no'
        'enable_auto_waitlist'        => 'sanitize_text_field', // 'yes' o 'no'
        'enable_auto_complete_paid'   => 'sanitize_text_field', // 'yes' o 'no'
        'enable_auto_verify_fai'      => 'sanitize_text_field', // 'yes' o 'no' — default 'no'
    ];

    $new_settings = [];
    foreach ($fields_to_sanitize as $key => $sanitize_func) {
        if (isset($_POST['dfn_settings'][$key])) {
            $val = wp_unslash($_POST['dfn_settings'][$key]);
            if ($sanitize_func === 'absint') {
                $new_settings[$key] = absint($val);
            } elseif ($sanitize_func === 'sanitize_hex_color') {
                $new_settings[$key] = sanitize_hex_color($val);
            } elseif ($sanitize_func === 'sanitize_email_list') {
                $emails = array_map('sanitize_email', array_map('trim', explode(',', $val)));
                $emails = array_filter($emails);
                $new_settings[$key] = implode(', ', $emails);
            } elseif ($sanitize_func === 'sanitize_textarea_field') {
                $new_settings[$key] = sanitize_textarea_field($val);
            } else {
                $new_settings[$key] = sanitize_text_field($val);
            }
        } else {
            // Se un checkbox non è inviato, assumiamo sia 'no' se appartiene ai toggle
            if (strpos($key, 'enable_') === 0) {
                $new_settings[$key] = 'no';
            }
        }
    }

    // Mantieni i campi di sola lettura non inviabili/modificabili direttamente
    $new_settings['setup_roles_version'] = '2.0';
    $new_settings['setup_fai_discount']  = 5;

    $updated = update_option('dfn_settings', $new_settings);

    if ($updated) {
        add_settings_error(
            'dfn_settings_messages',
            'dfn_settings_updated',
            __('Impostazioni salvate con successo.', 'dfn-theme'),
            'updated',
        );
    } else {
        add_settings_error(
            'dfn_settings_messages',
            'dfn_settings_no_change',
            __('Nessuna modifica rilevata o errore nel salvataggio.', 'dfn-theme'),
            'info',
        );
    }
}

/**
 * Renderizza la pagina di gestione delle Impostazioni DFN.
 */
function dfn_render_settings_page(): void
{
    if (! current_user_can('dfn_manage_events')) {
        wp_die(esc_html__('Non hai i permessi per accedere a questa pagina.', 'dfn-theme'));
    }

    // Gestisci il salvataggio se sottomesso
    if (isset($_POST['dfn_settings_nonce'])) {
        dfn_settings_save_fields();
    }

    // Carica gli stili specifici dell'admin se non sono già stati inclusi
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_media();

    // Mostra i messaggi di notifica/errore
    settings_errors('dfn_settings_messages');

    // Recupera la tab attiva
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'generale';

    $tabs = [
        'generale'   => '&#127970; Generale',
        'email'      => '&#128231; Email &amp; Notifiche',
        'testi_email'=> '&#128221; Testi E-mail',
        'cron'       => '&#9200; Automazioni &amp; Cron',
        'tessere'    => '&#127818; Tessere Socio FAI',
        'limiti'     => '&#128202; Limiti &amp; Testi',
        'avanzate'   => '&#128295; Avanzate &amp; Toggle',
        'ruoli'      => '&#128100; Ruoli &amp; Azioni',
    ];
    ?>
    <div class="wrap dfn-settings-wrap">
        <h1 class="wp-heading-inline" style="margin-bottom: 20px;">⚙️ Impostazioni FAI Prenotazioni</h1>
        <hr class="wp-header-end">

        <div class="dfn-settings-container" style="display: flex; gap: 20px; margin-top: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;">
            
            <!-- Sidebar Navigation Tabs -->
            <div class="dfn-settings-sidebar" style="width: 240px; background: #f6f7f7; border-right: 1px solid #ccd0d4; flex-shrink: 0;">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php foreach ($tabs as $tab_id => $tab_label) :
                        $is_active = ($active_tab === $tab_id);
                        $tab_url = admin_url('admin.php?page=dfn-settings&tab=' . $tab_id);
                        $bg = $is_active ? '#ffffff' : 'transparent';
                        $color = $is_active ? '#004b23' : '#2c3338';
                        $border_left = $is_active ? '4px solid #004b23' : '4px solid transparent';
                        $font_weight = $is_active ? '600' : 'normal';
                        ?>
                        <li style="margin: 0; border-bottom: 1px solid #e5e5e5;">
                            <a href="<?php echo esc_url($tab_url); ?>" style="display: block; padding: 15px 20px; color: <?php echo esc_attr($color); ?>; background-color: <?php echo esc_attr($bg); ?>; border-left: <?php echo esc_attr($border_left); ?>; font-weight: <?php echo esc_attr($font_weight); ?>; text-decoration: none; outline: none; box-shadow: none; transition: all 0.1s ease-in-out;">
                                <?php echo esc_html($tab_label); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Form Content Area -->
            <div class="dfn-settings-content" style="flex-grow: 1; padding: 30px 40px; min-width: 0;">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=dfn-settings&tab=' . $active_tab)); ?>">
                    <?php wp_nonce_field('dfn_save_settings_action', 'dfn_settings_nonce'); ?>

                    <?php if ($active_tab === 'generale') : ?>
                        <!-- TAB GENERALE -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🏢 Identità Delegazione / Organizzazione</h2>
                        <p class="description" style="margin-bottom: 25px;">Configura i nomi e le firme testuali per personalizzare la delegazione di appartenenza ed evitare riferimenti fissi nel codice.</p>
                        
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="delegation_name">Nome Delegazione</label></th>
                                <td>
                                    <input name="dfn_settings[delegation_name]" type="text" id="delegation_name" value="<?php echo esc_attr(dfn_get_setting('delegation_name')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Il nome abbreviato della delegazione FAI (es. "FAI Novara"). Viene visualizzato come mittente delle e-mail e in varie sezioni informative del portale.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="delegation_footer">Nome Completo (footer email)</label></th>
                                <td>
                                    <input name="dfn_settings[delegation_footer]" type="text" id="delegation_footer" value="<?php echo esc_attr(dfn_get_setting('delegation_footer')); ?>" class="large-text" />
                                    <p class="description"><strong>Comportamento:</strong> Nome formale completo (es. "FAI - Delegazione di Novara") stampato nel footer/piè di pagina di tutte le e-mail e nelle informative ufficiali.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="delegation_email">Email Delegazione per Prenotazioni</label></th>
                                <td>
                                    <input name="dfn_settings[delegation_email]" type="email" id="delegation_email" value="<?php echo esc_attr(dfn_get_setting('delegation_email')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> L'indirizzo e-mail di contatto ufficiale della delegazione. Viene utilizzato per gli eventi impostati con lo stato prenotazione "Via Email" ed inserito come link <code>mailto:</code> per consentire agli utenti di inviare le richieste di prenotazione.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_staff_signature">Firma Email Staff</label></th>
                                <td>
                                    <input name="dfn_settings[email_staff_signature]" type="text" id="email_staff_signature" value="<?php echo esc_attr(dfn_get_setting('email_staff_signature')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> La firma testuale predefinita (es. "Lo Staff della Delegazione FAI Novara") utilizzata per chiudere i messaggi automatici o inviati a mano dai gestori.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="default_placeholder_image_id">🖼️ Immagine Segnaposto Eventi (Placeholder)</label></th>
                                <td>
                                    <?php
                                    $placeholder_id = intval(dfn_get_setting('default_placeholder_image_id', 0));
                                    $placeholder_url = $placeholder_id > 0 ? wp_get_attachment_image_url($placeholder_id, 'medium') : '';
                                    ?>
                                    <div class="dfn-placeholder-image-preview" style="margin-bottom: 10px; min-height: 120px; max-width: 250px; border: 2px dashed #cbd5e1; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #f8fafc; overflow: hidden;">
                                        <?php if ($placeholder_url) : ?>
                                            <img src="<?php echo esc_url($placeholder_url); ?>" style="max-width: 100%; max-height: 120px; display: block;" id="dfn-placeholder-img">
                                        <?php else : ?>
                                            <span style="color: #64748b; font-size: 13px;" id="dfn-placeholder-text">Nessun segnaposto impostato</span>
                                            <img src="" style="max-width: 100%; max-height: 120px; display: none;" id="dfn-placeholder-img">
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" name="dfn_settings[default_placeholder_image_id]" id="default_placeholder_image_id" value="<?php echo intval($placeholder_id); ?>">
                                    <button type="button" class="button button-secondary" id="dfn-upload-placeholder-btn">Seleziona Immagine</button>
                                    <button type="button" class="button" id="dfn-remove-placeholder-btn" style="color: #ef4444; border-color: #fca5a5; display: <?php echo $placeholder_id ? 'inline-block' : 'none'; ?>;">Rimuovi</button>
                                    <p class="description" style="margin-top: 6px;"><strong>Comportamento:</strong> L'immagine predefinita della libreria media usata come copertina quando un evento viene creato senza inserire un'immagine in evidenza.</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ($active_tab === 'email') : ?>
                        <!-- TAB EMAIL & NOTIFICHE -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">📧 Destinatari & Stile Notifiche</h2>
                        <p class="description" style="margin-bottom: 25px;">Gestisci i canali di ricezione delle e-mail di sistema e personalizza il layout grafico dei messaggi HTML.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="email_new_booking">Email Notifica Nuove Prenotazioni</label></th>
                                <td>
                                    <input name="dfn_settings[email_new_booking]" type="text" id="email_new_booking" value="<?php echo esc_attr(dfn_get_setting('email_new_booking')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Gli indirizzi e-mail (separati da virgola se multipli) che riceveranno un avviso automatico ogni volta che viene completata con successo una nuova prenotazione da parte di un cliente.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_verify_fai">Email Notifica Tessere FAI da Verificare</label></th>
                                <td>
                                    <input name="dfn_settings[email_verify_fai]" type="text" id="email_verify_fai" value="<?php echo esc_attr(dfn_get_setting('email_verify_fai')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Gli indirizzi e-mail (separati da virgola se multipli) a cui inviare una notifica di avviso immediato quando un utente si iscrive o prenota inserendo un codice tessera FAI che richiede una convalida manuale in amministrazione.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_cc">Email in Copia Visibile (Cc)</label></th>
                                <td>
                                    <input name="dfn_settings[email_cc]" type="text" id="email_cc" value="<?php echo esc_attr(dfn_get_setting('email_cc')); ?>" class="regular-text" />
                                    <p class="description"><strong>Cc (Carbon Copy):</strong> Indirizzi e-mail (separati da virgola) a cui inviare una copia <strong>visibile</strong> di tutte le notifiche di sistema. Gli indirizzi inseriti qui saranno chiaramente visibili a tutti i destinatari nell'intestazione dell'e-mail.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_bcc">Email in Copia Nascosta (Bcc)</label></th>
                                <td>
                                    <input name="dfn_settings[email_bcc]" type="text" id="email_bcc" value="<?php echo esc_attr(dfn_get_setting('email_bcc')); ?>" class="regular-text" />
                                    <p class="description"><strong>Bcc (Blind Carbon Copy):</strong> Indirizzi e-mail (separati da virgola) a cui inviare una copia <strong>riservata e nascosta</strong> di tutte le notifiche. Il cliente o destinatario principale <strong>non vedrà</strong> questi indirizzi nell'intestazione del messaggio.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_primary_color">Colore Primario Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_primary_color]" type="text" id="email_primary_color" value="<?php echo esc_attr(dfn_get_setting('email_primary_color')); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Colore primario usato nel template HTML delle email (per l'header superiore, i titoli principali H1/H2 e lo sfondo dei pulsanti call-to-action).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_accent_color">Colore Accento Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_accent_color]" type="text" id="email_accent_color" value="<?php echo esc_attr(dfn_get_setting('email_accent_color')); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Tonalità usata per dettagli minori (bordi decorativi, link, badge di stato, box informativi secondari).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_bg_color">Colore Sfondo Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_bg_color]" type="text" id="email_bg_color" value="<?php echo esc_attr(dfn_get_setting('email_bg_color')); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Il colore di sfondo dell'area esterna della mail (il contenitore grigio o neutro che circonda il foglio bianco del messaggio).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_text_color">Colore Testo Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_text_color]" type="text" id="email_text_color" value="<?php echo esc_attr(dfn_get_setting('email_text_color')); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Il colore del font per il corpo del testo principale della e-mail, per garantire un corretto contrasto e riposo visivo.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_disclaimer">Disclaimer Piè di Pagina</label></th>
                                <td>
                                    <textarea name="dfn_settings[email_disclaimer]" id="email_disclaimer" rows="4" cols="50" class="large-text"><?php echo esc_textarea(dfn_get_setting('email_disclaimer')); ?></textarea>
                                    <p class="description"><strong>Comportamento:</strong> Nota legale / informativa stampata in caratteri piccoli nel footer delle mail (es. "Questa è un'email automatica, si prega di non rispondere direttamente").</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ($active_tab === 'cron') : ?>
                        <!-- TAB AUTOMAZIONI & CRON -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">⏰ Temporizzazioni & Cron Jobs</h2>
                        <p class="description" style="margin-bottom: 25px;">Imposta i parametri temporali e le soglie di esecuzione per i processi in background che gestiscono scadenze e notifiche.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="cron_timeout_no_booking">Timeout Ordini Senza Booking (Ore)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_timeout_no_booking]" type="number" id="cron_timeout_no_booking" value="<?php echo esc_attr(dfn_get_setting('cron_timeout_no_booking')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Se un cliente avvia un checkout ma non viene creata alcuna prenotazione valida a causa di errori, il cron cancella l'ordine orfano dopo questo numero di ore per liberare i posti.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_reminder_start">Finestra Promemoria — Inizio (Ore prima)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_reminder_start]" type="number" id="cron_reminder_start" value="<?php echo esc_attr(dfn_get_setting('cron_reminder_start')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Limite temporale inferiore in ore. Il sistema invia il promemoria di visita solo a partire da questa soglia temporale precedente l'evento (es. 12 ore prima).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_reminder_end">Finestra Promemoria — Fine (Ore prima)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_reminder_end]" type="number" id="cron_reminder_end" value="<?php echo esc_attr(dfn_get_setting('cron_reminder_end')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Limite temporale superiore in ore. Il sistema smette di inviare promemoria se mancano più di queste ore all'evento (es. 36 ore prima), garantendo l'invio nella finestra corretta (es. a 24 ore esatte).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_waitlist_ttl">TTL Priorità Waitlist (Ore)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_waitlist_ttl]" type="number" id="cron_waitlist_ttl" value="<?php echo esc_attr(dfn_get_setting('cron_waitlist_ttl')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Quando si libera un posto e viene notificato un utente in lista d'attesa, quest'ultimo ha a disposizione questo numero di ore per completare l'iscrizione prima che il link scada e il posto passi al successivo.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_batch_reminder">Batch Promemoria (Massimo per ciclo)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_batch_reminder]" type="number" id="cron_batch_reminder" value="<?php echo esc_attr(dfn_get_setting('cron_batch_reminder')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Numero massimo di email di promemoria inviate per singola esecuzione oraria del cron, al fine di scongiurare blocchi o limitazioni del server di posta (rate limit).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_batch_expired">Batch Ordini Scaduti (Massimo per ciclo)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_batch_expired]" type="number" id="cron_batch_expired" value="<?php echo esc_attr(dfn_get_setting('cron_batch_expired')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Limite superiore di ordini in sospeso o scaduti da verificare e pulire ad ogni esecuzione del cron job.</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ($active_tab === 'tessere') : ?>
                        <!-- TAB TESSERE SOCIO FAI -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🍊 Tessere Socio FAI</h2>
                        <p class="description" style="margin-bottom: 25px;">Gestisci le opzioni per il riconoscimento dei soci, i coupon di sconto e le scadenze delle iscrizioni FAI.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="fai_coupon_code">Codice Coupon FAI (Legacy)</label></th>
                                <td>
                                    <input name="dfn_settings[fai_coupon_code]" type="text" id="fai_coupon_code" value="<?php echo esc_attr(dfn_get_setting('fai_coupon_code')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Il codice promozionale WooCommerce (es. `socio_fai_novara_2025`) utilizzato dal sistema per applicare gli sconti ai soci FAI nei flussi legacy.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fai_expiry_warning_days">Giorni Preavviso Scadenza Tessere</label></th>
                                <td>
                                    <input name="dfn_settings[fai_expiry_warning_days]" type="number" id="fai_expiry_warning_days" value="<?php echo esc_attr(dfn_get_setting('fai_expiry_warning_days')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Nella tabella anagrafica "Soci FAI", le tessere la cui scadenza cade entro questo numero di giorni verranno evidenziate con un badge giallo di allarme.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fai_member_types">Tipologie Tessera Disponibili</label></th>
                                <td>
                                    <input name="dfn_settings[fai_member_types]" type="text" id="fai_member_types" value="<?php echo esc_attr(dfn_get_setting('fai_member_types')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Opzioni inserite come elenco separato da virgola (es. `INDIVIDUALE, COPPIA, FAMIGLIA`) che popolano il menu a tendina quando lo staff crea o modifica un socio FAI.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fai_no_email_placeholder">Email Placeholder per Botteghino</label></th>
                                <td>
                                    <input name="dfn_settings[fai_no_email_placeholder]" type="text" id="fai_no_email_placeholder" value="<?php echo esc_attr(dfn_get_setting('fai_no_email_placeholder')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> L'indirizzo e-mail fittizio impostato di default (es. `no-email@dfn.it`) quando lo staff registra una prenotazione al volo in cassa senza raccogliere la mail del cliente.</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ($active_tab === 'limiti') : ?>
                        <!-- TAB LIMITI & TESTI -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">📊 Limiti di Visualizzazione & Testi Custom</h2>
                        <p class="description" style="margin-bottom: 25px;">Personalizza i messaggi mostrati ai clienti e imposta i limiti fisici per evitare rallentamenti nelle schermate di report.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="limit_max_fai_members">Max Soci FAI Visibili Senza Ricerca</label></th>
                                <td>
                                    <input name="dfn_settings[limit_max_fai_members]" type="number" id="limit_max_fai_members" value="<?php echo esc_attr(dfn_get_setting('limit_max_fai_members')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Numero di record anagrafici caricati inizialmente nella tabella dei soci prima che l'utente effettui una ricerca attiva. Protegge la pagina da sovraccarichi in caso di migliaia di soci.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="limit_max_activity_logs">Max Log Attività per Utente</label></th>
                                <td>
                                    <input name="dfn_settings[limit_max_activity_logs]" type="number" id="limit_max_activity_logs" value="<?php echo esc_attr(dfn_get_setting('limit_max_activity_logs')); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Tetto massimo di righe memorizzate per ogni iscritto all'interno del proprio registro storico di navigazione. I log più vecchi vengono rimossi automaticamente.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="text_early_arrival">Messaggio Anticipo Arrivo</label></th>
                                <td>
                                    <input name="dfn_settings[text_early_arrival]" type="text" id="text_early_arrival" value="<?php echo esc_attr(dfn_get_setting('text_early_arrival')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Stringa inserita nel corpo della mail per istruire i prenotati ad arrivare per tempo (es. "almeno 10 minuti prima").</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="text_no_bookings_myaccount">Messaggio Nessuna Prenotazione (My Account)</label></th>
                                <td>
                                    <textarea name="dfn_settings[text_no_bookings_myaccount]" id="text_no_bookings_myaccount" rows="3" cols="50" class="large-text"><?php echo esc_textarea(dfn_get_setting('text_no_bookings_myaccount')); ?></textarea>
                                    <p class="description"><strong>Comportamento:</strong> Testo visualizzato nel tab "Prenotazioni" dell'area clienti WooCommerce se l'utente loggato non ha ancora effettuato alcuna prenotazione.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="text_checkout_btn">Testo Bottone Checkout</label></th>
                                <td>
                                    <input name="dfn_settings[text_checkout_btn]" type="text" id="text_checkout_btn" value="<?php echo esc_attr(dfn_get_setting('text_checkout_btn')); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Sostituisce la dicitura sul pulsante standard di conferma d'acquisto nella pagina finale di Checkout (es. "Effettua Prenotazione" al posto di "Effettua ordine").</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ($active_tab === 'testi_email') : ?>
                        <!-- TAB TESTI EMAIL -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">📝 Personalizzazione Testi E-mail</h2>
                        <p class="description" style="margin-bottom: 25px;">Modifica i soggetti, i titoli e i testi delle e-mail inviate automaticamente dal sistema. Puoi usare i segnaposto indicati tra parentesi graffe {} per inserire dati dinamici. Clicca su un'e-mail per espandere e modificare i suoi campi.</p>

                        <style>
                        .dfn-accordion-item {
                            border: 1px solid #e2e8f0;
                            border-radius: 6px;
                            margin-bottom: 8px;
                            overflow: hidden;
                        }
                        .dfn-accordion-header {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            padding: 14px 20px;
                            background: #f8fafc;
                            cursor: pointer;
                            user-select: none;
                            transition: background 0.15s;
                            gap: 12px;
                        }
                        .dfn-accordion-header:hover {
                            background: #f0f4f8;
                        }
                        .dfn-accordion-header-left {
                            display: flex;
                            align-items: center;
                            gap: 12px;
                            flex: 1;
                        }
                        .dfn-accordion-title {
                            font-weight: 600;
                            font-size: 14px;
                            color: #004b23;
                            margin: 0;
                        }
                        .dfn-accordion-arrow {
                            font-size: 12px;
                            color: #64748b;
                            transition: transform 0.2s;
                            flex-shrink: 0;
                            width: 18px;
                            text-align: center;
                        }
                        .dfn-accordion-item.is-open .dfn-accordion-arrow {
                            transform: rotate(180deg);
                        }
                        .dfn-accordion-body {
                            display: none;
                            padding: 20px 24px;
                            background: #fff;
                            border-top: 1px solid #e2e8f0;
                        }
                        .dfn-accordion-item.is-open .dfn-accordion-body {
                            display: block;
                        }
                        .dfn-accordion-body .form-table {
                            margin: 0;
                        }
                        .dfn-accordion-body .form-table th {
                            width: 200px;
                            padding: 10px 10px 10px 0;
                        }
                        .dfn-accordion-body .form-table td {
                            padding: 10px 0;
                        }
                        </style>

                        <?php
                        $emails = [
                            [
                                'num'       => '1',
                                'label'     => 'Conferma Prenotazione (Immediata)',
                                'type'      => 'confirm',
                                'name'      => 'Conferma Prenotazione',
                                'fields'    => [
                                    ['id' => 'email_confirm_subject', 'label' => 'Oggetto E-mail',               'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_confirm_title',   'label' => 'Titolo Banner Visivo',          'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_confirm_intro',   'label' => 'Testo Introduzione',            'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}, {url_modifica}, {url_annullamento}'],
                                    ['id' => 'email_confirm_notes',   'label' => 'Note Importanti / Regole Accesso', 'type' => 'textarea', 'ph' => ''],
                                ],
                            ],
                            [
                                'num'       => '1b',
                                'label'     => 'Modifica Prenotazione (Autonoma)',
                                'type'      => 'modify',
                                'name'      => 'Modifica Prenotazione',
                                'fields'    => [
                                    ['id' => 'email_modify_subject', 'label' => 'Oggetto E-mail',               'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_modify_title',   'label' => 'Titolo Banner Visivo',          'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_modify_intro',   'label' => 'Testo Introduzione',            'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}, {url_modifica}, {url_annullamento}'],
                                    ['id' => 'email_modify_notes',   'label' => 'Note Importanti / Regole Accesso', 'type' => 'textarea', 'ph' => ''],
                                ],
                            ],
                            [
                                'num'       => '2',
                                'label'     => 'Richiesta in Fase di Verifica (Approvazione Manuale)',
                                'type'      => 'pending',
                                'name'      => 'Richiesta in Verifica',
                                'fields'    => [
                                    ['id' => 'email_pending_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_pending_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_pending_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}'],
                                ],
                            ],
                            [
                                'num'       => '3',
                                'label'     => 'Richiesta non Approvata (Rifiutata dallo Staff)',
                                'type'      => 'declined',
                                'name'      => 'Richiesta Rifiutata',
                                'fields'    => [
                                    ['id' => 'email_declined_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_declined_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_declined_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}, {motivo_rifiuto}'],
                                ],
                            ],
                            [
                                'num'       => '3b',
                                'label'     => 'Rifiuto Prenotazione FAI (Tessere Non Valide)',
                                'type'      => 'fai_booking_rejected',
                                'name'      => 'Rifiuto Prenotazione FAI',
                                'new'       => true,
                                'fields'    => [
                                    ['id' => 'email_fai_booking_rejected_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_fai_booking_rejected_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_fai_booking_rejected_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}, {motivo_rifiuto}'],
                                ],
                            ],
                            [
                                'num'       => '4',
                                'label'     => 'Prenotazione Annullata (dal Visitatore)',
                                'type'      => 'cancelled',
                                'name'      => 'Annullamento Utente',
                                'fields'    => [
                                    ['id' => 'email_cancelled_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_cancelled_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_cancelled_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}'],
                                ],
                            ],
                            [
                                'num'       => '5',
                                'label'     => 'Prenotazione Annullata dallo Staff',
                                'type'      => 'admin_cancelled',
                                'name'      => 'Annullamento Staff',
                                'fields'    => [
                                    ['id' => 'email_admin_cancelled_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_admin_cancelled_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_admin_cancelled_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}'],
                                ],
                            ],
                            [
                                'num'       => '6',
                                'label'     => 'Promemoria Visita (24 Ore Prima)',
                                'type'      => 'reminder',
                                'name'      => 'Promemoria 24h',
                                'fields'    => [
                                    ['id' => 'email_reminder_subject', 'label' => 'Oggetto E-mail',                    'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_reminder_title',   'label' => 'Titolo Banner Visivo',               'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_reminder_intro',   'label' => 'Testo Introduzione',                 'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}, {url_modifica}, {url_annullamento}'],
                                    ['id' => 'email_reminder_notes',   'label' => 'Note Importanti / Istruzioni Accesso', 'type' => 'textarea', 'ph' => ''],
                                ],
                            ],
                            [
                                'num'       => '7',
                                'label'     => "Posto Disponibile in Lista d'Attesa",
                                'type'      => 'waitlist',
                                'name'      => 'Notifica Waitlist',
                                'fields'    => [
                                    ['id' => 'email_waitlist_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => '{nome_evento}'],
                                    ['id' => 'email_waitlist_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_waitlist_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {nome_evento}, {ore_waitlist}'],
                                ],
                            ],
                            [
                                'num'       => '8',
                                'label'     => 'Tessera FAI Approvata (Verifica Superata)',
                                'type'      => 'fai_approved',
                                'name'      => 'Tessera FAI Approvata',
                                'fields'    => [
                                    ['id' => 'email_fai_approved_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_fai_approved_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_fai_approved_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {numero_tessera}'],
                                ],
                            ],
                            [
                                'num'       => '9',
                                'label'     => 'Tessera FAI Rifiutata (Non Valida)',
                                'type'      => 'fai_rejected',
                                'name'      => 'Tessera FAI Rifiutata',
                                'fields'    => [
                                    ['id' => 'email_fai_rejected_subject', 'label' => 'Oggetto E-mail',     'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_fai_rejected_title',   'label' => 'Titolo Banner Visivo', 'type' => 'text',     'ph' => ''],
                                    ['id' => 'email_fai_rejected_body',    'label' => 'Corpo E-mail',         'type' => 'textarea', 'ph' => '{nome_cliente}, {numero_tessera}, {motivo_rifiuto}'],
                                ],
                            ],
                        ];
                        ?>

                        <?php foreach ($emails as $email) : ?>
                        <div class="dfn-accordion-item">
                            <div class="dfn-accordion-header">
                                <div class="dfn-accordion-header-left">
                                    <span class="dfn-accordion-title">
                                        <?php echo esc_html($email['num'] . '. ' . $email['label']); ?>
                                        <?php if (! empty($email['new'])) : ?>
                                            <span style="background:#dcfce7; color:#166534; font-size:10px; font-weight:700; padding:2px 7px; border-radius:9999px; vertical-align:middle; margin-left:8px;">NUOVO</span>
                                        <?php endif; ?>
                                    </span>
                                    <button type="button" class="button button-secondary dfn-send-test-email-btn" data-email-type="<?php echo esc_attr($email['type']); ?>" data-email-name="<?php echo esc_attr($email['name']); ?>" style="font-size: 11px; height: auto; padding: 4px 10px; line-height: normal; flex-shrink: 0;" onclick="event.stopPropagation();">✉️ Invia di prova</button>
                                </div>
                                <span class="dfn-accordion-arrow">▼</span>
                            </div>
                            <div class="dfn-accordion-body">
                                <table class="form-table" role="presentation">
                                    <?php foreach ($email['fields'] as $field) : ?>
                                    <tr>
                                        <th scope="row"><label for="<?php echo esc_attr($field['id']); ?>"><?php echo esc_html($field['label']); ?></label></th>
                                        <td>
                                            <?php if ($field['type'] === 'textarea') : ?>
                                                <textarea name="dfn_settings[<?php echo esc_attr($field['id']); ?>]" id="<?php echo esc_attr($field['id']); ?>" rows="5" cols="50" class="large-text"><?php echo esc_textarea(dfn_get_setting($field['id'])); ?></textarea>
                                            <?php else : ?>
                                                <input name="dfn_settings[<?php echo esc_attr($field['id']); ?>]" type="text" id="<?php echo esc_attr($field['id']); ?>" value="<?php echo esc_attr(dfn_get_setting($field['id'])); ?>" class="<?php echo (strpos($field['id'], 'title') !== false) ? 'regular-text' : 'large-text'; ?>" />
                                            <?php endif; ?>
                                            <?php if (! empty($field['ph'])) : ?>
                                                <p class="description">Segnaposto: <?php echo implode(', ', array_map(fn($p) => '<code>' . esc_html(trim($p)) . '</code>', explode(',', $field['ph']))); ?></p>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            document.querySelectorAll('.dfn-accordion-header').forEach(function(header) {
                                header.addEventListener('click', function() {
                                    var item = this.closest('.dfn-accordion-item');
                                    item.classList.toggle('is-open');
                                });
                            });
                        });
                        </script>


                    <?php elseif ($active_tab === 'avanzate') : ?>
                        <!-- TAB AVANZATE & TOGGLE -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🔧 Avanzate, Toggle & Sola Lettura</h2>
                        <p class="description" style="margin-bottom: 25px;">Abilita o disabilita intere funzionalità del motore di prenotazione e consulta le costanti fisse di configurazione.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Abilitare notifica admin per ogni prenotazione</th>
                                <td>
                                    <label for="enable_admin_notification">
                                        <input name="dfn_settings[enable_admin_notification]" type="checkbox" id="enable_admin_notification" value="yes" <?php checked(dfn_get_setting('enable_admin_notification'), 'yes'); ?> />
                                        Sì, invia un'email all'indirizzo amministrativo ogni volta che viene completata una prenotazione.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Se deselezionata, blocca le email di avviso all'amministratore per ridurre il traffico sulla casella di posta, continuando però a inviare i biglietti agli utenti finali.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Abilitare promemoria 24h</th>
                                <td>
                                    <label for="enable_reminder_24h">
                                        <input name="dfn_settings[enable_reminder_24h]" type="checkbox" id="enable_reminder_24h" value="yes" <?php checked(dfn_get_setting('enable_reminder_24h'), 'yes'); ?> />
                                        Sì, invia automaticamente l'email di promemoria pre-evento agli utenti.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Controlla se il cron job è autorizzato a spedire l'e-mail di promemoria automatica prima del turno prenotato.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Abilitare lista d'attesa automatica</th>
                                <td>
                                    <label for="enable_auto_waitlist">
                                        <input name="dfn_settings[enable_auto_waitlist]" type="checkbox" id="enable_auto_waitlist" value="yes" <?php checked(dfn_get_setting('enable_auto_waitlist'), 'yes'); ?> />
                                        Sì, attiva il modulo lista d'attesa per gli eventi che esauriscono i posti.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Se disattivato, al raggiungimento della capienza massima di uno slot non verrà più proposto agli utenti il form per iscriversi in coda.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Abilitare auto-completamento ordini pagati online</th>
                                <td>
                                    <label for="enable_auto_complete_paid">
                                        <input name="dfn_settings[enable_auto_complete_paid]" type="checkbox" id="enable_auto_complete_paid" value="yes" <?php checked(dfn_get_setting('enable_auto_complete_paid'), 'yes'); ?> />
                                        Sì, imposta come "Completato" ogni ordine pagato con carta/gateway online.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Evita che gli ordini saldati tramite Stripe, PayPal o altri gateway digitali rimangano in stato "Elaborazione", forzando la chiusura immediata e l'invio delle conferme di prenotazione.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Verifica automatica tessere FAI all'approvazione</th>
                                <td>
                                    <label for="enable_auto_verify_fai">
                                        <input name="dfn_settings[enable_auto_verify_fai]" type="checkbox" id="enable_auto_verify_fai" value="yes" <?php checked(dfn_get_setting('enable_auto_verify_fai', 'no'), 'yes'); ?> />
                                        Sì, marca automaticamente le tessere FAI come verificate quando approvo una prenotazione dalla sezione "Verifica Prenotazioni FAI".
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Se attivato, cliccando <em>Approva</em> su una prenotazione pendente, tutte le tessere FAI associate vengono marcate come <code>verified = 1</code> nel database, evitando la ri-verifica manuale alle prenotazioni future degli stessi soci. <br><strong style="color:#e53e3e;">⚠️ Attualmente DISATTIVATO (default consigliato)</strong>: le tessere si verificano manualmente dalla sezione Soci FAI.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Versione Ruoli (Sola Lettura)</label></th>
                                <td>
                                    <input type="text" value="<?php echo esc_attr(dfn_get_setting('setup_roles_version')); ?>" class="small-text" readonly disabled />
                                    <p class="description"><strong>Comportamento:</strong> Indica la versione dei privilegi utente e dei ruoli personalizzati registrati all'attivazione del tema.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Sconto Unitario FAI (Sola Lettura)</label></th>
                                <td>
                                    <input type="text" value="<?php echo esc_attr(dfn_get_setting('setup_fai_discount')); ?> €" class="small-text" readonly disabled />
                                    <p class="description"><strong>Comportamento:</strong> Valore fisso legacy per lo sconto socio FAI. Negli eventi moderni lo sconto viene determinato dinamicamente dal contributo del singolo ingresso, perciò questa costante viene mostrata solo come dato informativo storico.</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ($active_tab === 'ruoli') : ?>
                        <!-- TAB RUOLI & AZIONI -->
                        <h2 style="color:#004b23; border-bottom:1px solid #eee; padding-bottom:10px; margin-top:0;">&#128100; Ruoli &amp; Azioni</h2>
                        <p class="description" style="margin-bottom:25px;">Configura i ruoli utente e le azioni che ciascun ruolo può eseguire all'interno del sistema FAI Prenotazioni.</p>

                        <div style="background:#f0f9ff; border:1px solid #bfdbfe; border-radius:6px; padding:20px; margin-bottom:25px;">
                            <strong style="color:#1e40af;">&#128274; Funzionalità in arrivo</strong>
                            <p style="margin:8px 0 0; color:#1e3a8a;">Questa sezione permetterà di gestire <strong>dinamicamente</strong> i permessi per ruolo. Ad esempio:</p>
                            <ul style="margin:10px 0 0 20px; color:#1e3a8a; line-height:1.8;">
                                <li>Definire chi può accedere alla <strong>Gestione Prenotazioni</strong> (annullamento, spostamento turno)</li>
                                <li>Definire chi può accedere al <strong>Check-in Banchetto</strong> (validazione biglietti, reminder)</li>
                                <li>Assegnare ruoli personalizzati agli utenti WordPress direttamente da qui</li>
                                <li>Gestire la visibilità delle singole azioni per utenti scanner, cassa, segreteria, ecc.</li>
                            </ul>
                        </div>

                        <h3 style="color:#004b23;">Ruoli attuali registrati</h3>
                        <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
                            <thead><tr>
                                <th style="padding:10px; width:200px;">Ruolo</th>
                                <th style="padding:10px;">Permessi attuali</th>
                            </tr></thead>
                            <tbody>
                                <tr>
                                    <td style="padding:10px; font-weight:700;">dfn_admin</td>
                                    <td style="padding:10px;">Accesso completo a tutte le funzioni: Gestione Prenotazioni, Check-in, Impostazioni, Scanner</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px; font-weight:700;">dfn_event_manager</td>
                                    <td style="padding:10px;">Gestione Prenotazioni + Check-in Banchetto (identico a dfn_admin per ora)</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px; font-weight:700;">dfn_secretary</td>
                                    <td style="padding:10px;">Inserimento Rapido prenotazioni + lista ordini WooCommerce</td>
                                </tr>
                                <tr>
                                    <td style="padding:10px; font-weight:700;">dfn_scanner</td>
                                    <td style="padding:10px;">Solo accesso allo Scanner Live (validazione QR in ingresso)</td>
                                </tr>
                            </tbody>
                        </table>

                        <p style="margin-top:20px; color:#64748b; font-size:12px;">&#128221; I permessi sopra elencati sono attualmente hardcoded nel tema. La gestione dinamica sarà disponibile in una versione futura.</p>

                    <?php endif; ?>


                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                        <?php submit_button(__('Salva Impostazioni', 'dfn-theme'), 'primary', 'submit', false, [ 'style' => 'background: #004b23; border-color: #003318; box-shadow: none; text-shadow: none;' ]); ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($){
            if ($.isFunction($.fn.wpColorPicker)) {
                $('.dfn-color-field').wpColorPicker();
            }

            $('.dfn-send-test-email-btn').on('click', function(e){
                e.preventDefault();
                var emailType = $(this).data('email-type');
                var emailTypeName = $(this).data('email-name') || emailType;
                
                var emailDestination = prompt('Inserisci un indirizzo mail valido per l\'invio della mail di prova per "' + emailTypeName + '":', '');
                if (!emailDestination) {
                    return;
                }
                
                var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(emailDestination.trim())) {
                    alert('Errore: Inserisci un indirizzo email valido.');
                    return;
                }

                var $btn = $(this);
                var originalText = $btn.text();
                $btn.prop('disabled', true).text('Invio in corso...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dfn_send_test_email',
                        email_type: emailType,
                        email_destination: emailDestination,
                        nonce: '<?php echo esc_js(wp_create_nonce("dfn_send_test_email_nonce")); ?>'
                    },
                    success: function(response) {
                        $btn.prop('disabled', false).text(originalText);
                        if (response.success) {
                            alert('Email di prova inviata con successo a: ' + emailDestination);
                        } else {
                            alert('Errore durante l\'invio: ' + (response.data || 'Errore sconosciuto'));
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text(originalText);
                        alert('Si è verificato un errore di connessione col server.');
                    }
                });
            });

            var placeholderUploader;
            $('#dfn-upload-placeholder-btn').on('click', function(e) {
                e.preventDefault();
                if (placeholderUploader) {
                    placeholderUploader.open();
                    return;
                }
                placeholderUploader = wp.media({
                    title: 'Seleziona Immagine Segnaposto Predefinita',
                    button: { text: 'Usa come Segnaposto' },
                    multiple: false
                });
                placeholderUploader.on('select', function() {
                    var attachment = placeholderUploader.state().get('selection').first().toJSON();
                    $('#default_placeholder_image_id').val(attachment.id);
                    $('#dfn-placeholder-img').attr('src', attachment.url).show();
                    $('#dfn-placeholder-text').hide();
                    $('#dfn-remove-placeholder-btn').show();
                });
                placeholderUploader.open();
            });
            $('#dfn-remove-placeholder-btn').on('click', function(e) {
                e.preventDefault();
                $('#default_placeholder_image_id').val('0');
                $('#dfn-placeholder-img').attr('src', '').hide();
                $('#dfn-placeholder-text').show();
                $(this).hide();
            });
        });
    </script>
    <?php
}

/**
 * Gestisce l'invio delle e-mail di prova dal pannello delle Impostazioni.
 */
function dfn_ajax_send_test_email(): void
{
    check_ajax_referer('dfn_send_test_email_nonce', 'nonce');

    if (! current_user_can('dfn_manage_events')) {
        wp_send_json_error(__('Non hai i permessi per eseguire questa operazione.', 'dfn-theme'));
    }

    $email_type = isset($_POST['email_type']) ? sanitize_key($_POST['email_type']) : '';
    $destination = isset($_POST['email_destination']) ? sanitize_email($_POST['email_destination']) : '';

    if (empty($destination) || ! is_email($destination)) {
        wp_send_json_error(__('Indirizzo email di destinazione non valido.', 'dfn-theme'));
    }

    // Mock generic values
    $first_name = 'Mario';
    $last_name = 'Rossi';
    $customer_name = $first_name . ' ' . $last_name;
    $product_name = 'Visita guidata a Palazzo Natta';
    $slot_info = date_i18n('d F Y', strtotime('+5 days')) . ' - ore 10:30';
    $location = 'Palazzo Natta, Piazza Matteotti 1, Novara';
    $hub_url = home_url('/');
    $cancel_url = home_url('/');

    $details_table = '<div class="info-box" style="border-left:4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color:#f7fafc; padding:15px; margin:15px 0;">';
    $details_table .= '<div class="info-box-title" style="color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight:bold; margin-bottom:5px;">Dettagli della Prenotazione (PROVA)</div>';
    $details_table .= '<table style="width:100%; border-collapse:collapse;">';
    $details_table .= '<tr><td style="padding:5px 0; color:#718096; width:120px;">Evento:</td><td style="padding:5px 0; font-weight:bold;">' . esc_html($product_name) . '</td></tr>';
    $details_table .= '<tr><td style="padding:5px 0; color:#718096;">Data e Inizio:</td><td style="padding:5px 0;">' . esc_html($slot_info) . '</td></tr>';
    $details_table .= '<tr><td style="padding:5px 0; color:#718096;">Luogo:</td><td style="padding:5px 0;">' . esc_html($location) . '</td></tr>';
    $details_table .= '<tr><td style="padding:5px 0; color:#718096;">Partecipanti:</td><td style="padding:5px 0;">2 totali (1 Standard + 1 Socio FAI)</td></tr>';
    $details_table .= '<tr><td style="padding:5px 0; color:#718096;">Contributo:</td><td style="padding:5px 0; font-weight:bold; color:#ff6600;">10,00 € (Contributo all\'ingresso)</td></tr>';
    $details_table .= '</table>';
    $details_table .= '</div>';

    $subject = '';
    $title = '';
    $content = '';

    switch ($email_type) {
        case 'confirm':
            $modify_url = home_url('/');
            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'dettagli_prenotazione' => $details_table,
                'url_biglietto' => esc_url($hub_url),
                'url_annullamento' => esc_url($cancel_url),
                'url_modifica' => esc_url($modify_url),
            ];
            $intro_html = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_intro'), $replacements);
            $notes_html = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_notes'), $replacements);

            $content = $intro_html;
            $content .= $details_table;
            $content .= $notes_html;
            $content .= '<p>Per accedere all\'evento, mostra all\'ingresso il codice QR del tuo gruppo cliccando sul pulsante sottostante (è sufficiente mostrare un solo codice QR per tutto il gruppo).</p>';
            $content .= '<div style="text-align:center; margin:20px 0;"><a href="' . esc_url($hub_url) . '" style="background-color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; color:#ffffff; padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Mostra Codice QR / Ingressi</a></div>';
            $content .= '<p style="font-size:14px; color:#4a5568;"><em>Nota: Avendo scelto il contributo all\'ingresso, ti chiediamo di arrivare circa 10 minuti prima dell\'orario indicato per agevolare la ricezione del contributo presso il botteghino.</em></p>';
            $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Devi modificare il numero di partecipanti? <a href="' . esc_url($modify_url) . '" style="color: #004b23; text-decoration: underline; font-weight: bold;">Modifica la prenotazione qui</a></p>';
            $content .= '<p style="text-align: center; margin-top: 10px; font-size: 13px; color: #718096;">Non puoi più partecipare affatto? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_confirm_title'), $replacements);
            break;

        case 'modify':
            $modify_url = home_url('/');
            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'dettagli_prenotazione' => $details_table,
                'url_biglietto' => esc_url($hub_url),
                'url_annullamento' => esc_url($cancel_url),
                'url_modifica' => esc_url($modify_url),
            ];
            $intro_html = dfn_replace_email_placeholders(dfn_get_setting('email_modify_intro'), $replacements);
            $notes_html = dfn_replace_email_placeholders(dfn_get_setting('email_modify_notes'), $replacements);

            $content = $intro_html;
            $content .= $details_table;
            $content .= $notes_html;
            $content .= '<p>Per accedere all\'evento, mostra all\'ingresso il codice QR del tuo gruppo cliccando sul pulsante sottostante (è sufficiente mostrare un solo codice QR per tutto il gruppo).</p>';
            $content .= '<div style="text-align:center; margin:20px 0;"><a href="' . esc_url($hub_url) . '" style="background-color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; color:#ffffff; padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Mostra Codice QR / Ingressi</a></div>';
            $content .= '<p style="font-size:14px; color:#4a5568;"><em>Nota: Avendo scelto il contributo all\'ingresso, ti chiediamo di arrivare circa 10 minuti prima dell\'orario indicato per agevolare la ricezione del contributo presso il botteghino.</em></p>';
            $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Devi modificare ulteriormente il numero di partecipanti? <a href="' . esc_url($modify_url) . '" style="color: #004b23; text-decoration: underline; font-weight: bold;">Modifica la prenotazione qui</a></p>';
            $content .= '<p style="text-align: center; margin-top: 10px; font-size: 13px; color: #718096;">Non puoi più partecipare affatto? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_modify_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_modify_title'), $replacements);
            break;

        case 'pending':
            $details_table_pending = '<div class="info-box" style="border-left:4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color:#f7fafc; padding:15px; margin:15px 0;">';
            $details_table_pending .= '<div class="info-box-title" style="color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight:bold; margin-bottom:5px;">Dettagli della Richiesta</div>';
            $details_table_pending .= '<table style="width:100%; border-collapse:collapse;">';
            $details_table_pending .= '<tr><td style="padding:5px 0; color:#718096; width:120px;">Evento:</td><td style="padding:5px 0; font-weight:bold;">' . esc_html($product_name) . '</td></tr>';
            $details_table_pending .= '<tr><td style="padding:5px 0; color:#718096;">Stato:</td><td style="padding:5px 0; font-weight:bold; color:#e74f30;">In Attesa di Approvazione Staff</td></tr>';
            $details_table_pending .= '<tr><td style="padding:5px 0; color:#718096;">Partecipanti:</td><td style="padding:5px 0;">2 totali</td></tr>';
            $details_table_pending .= '</table>';
            $details_table_pending .= '</div>';

            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'dettagli_prenotazione' => $details_table_pending,
            ];
            $body_template = dfn_get_setting('email_pending_body');
            $content = dfn_replace_email_placeholders($body_template, $replacements);
            if (strpos($body_template, '{dettagli_prenotazione}') === false) {
                $content .= $details_table_pending;
            }
            $content .= '<p>Non è ancora necessario versare alcun contributo o mostrare QR code. Riceverai un secondo messaggio con l\'esito della richiesta.</p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_pending_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_pending_title'), $replacements);
            break;

        case 'declined':
            $motivo_text = 'Raggiunto il limite massimo di partecipanti per questo turno.';
            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'motivo_rifiuto' => $motivo_text,
            ];
            $body_template = dfn_get_setting('email_declined_body');
            $content = dfn_replace_email_placeholders($body_template, $replacements);
            if (strpos($body_template, '{motivo_rifiuto}') === false) {
                $content .= '<div class="info-box" style="border-left:4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color:#f7fafc; padding:15px; margin:15px 0;">';
                $content .= '<div class="info-box-title" style="color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight:bold; margin-bottom:5px;">Nota dallo Staff</div>';
                $content .= '<p style="margin:0; font-size:14px;">' . esc_html($motivo_text) . '</p>';
                $content .= '</div>';
            }
            $content .= '<p>I posti precedentemente riservati sono stati liberati e resi nuovamente disponibili.</p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_declined_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_declined_title'), $replacements);
            break;

        case 'cancelled':
            $details_table_cancel = '<div class="info-box" style="border-left:4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color:#f7fafc; padding:15px; margin:15px 0;">';
            $details_table_cancel .= '<div class="info-box-title" style="color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight:bold; margin-bottom:5px;">Riepilogo Annullamento</div>';
            $details_table_cancel .= '<table style="width:100%; border-collapse:collapse;">';
            $details_table_cancel .= '<tr><td style="padding:5px 0; color:#718096; width:120px;">Evento:</td><td style="padding:5px 0; font-weight:bold;">' . esc_html($product_name) . '</td></tr>';
            $details_table_cancel .= '<tr><td style="padding:5px 0; color:#718096;">Data Prenotata:</td><td style="padding:5px 0;">' . esc_html($slot_info) . '</td></tr>';
            $details_table_cancel .= '<tr><td style="padding:5px 0; color:#718096;">Stato:</td><td style="padding:5px 0; font-weight:bold; color:#e53e3e;">ANNULLATA</td></tr>';
            $details_table_cancel .= '</table>';
            $details_table_cancel .= '</div>';

            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'dettagli_prenotazione' => $details_table_cancel,
            ];
            $body_template = dfn_get_setting('email_cancelled_body');
            $content = dfn_replace_email_placeholders($body_template, $replacements);
            if (strpos($body_template, '{dettagli_prenotazione}') === false) {
                $content .= $details_table_cancel;
            }
            $content .= '<p>Speriamo di poterti accogliere in uno dei nostri prossimi eventi FAI.</p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_cancelled_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_cancelled_title'), $replacements);
            break;

        case 'admin_cancelled':
            $details_table_admin_cancel = '<div class="info-box" style="border-left:4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color:#f7fafc; padding:15px; margin:15px 0;">';
            $details_table_admin_cancel .= '<div class="info-box-title" style="color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight:bold; margin-bottom:5px;">Riepilogo Annullamento</div>';
            $details_table_admin_cancel .= '<table style="width:100%; border-collapse:collapse;">';
            $details_table_admin_cancel .= '<tr><td style="padding:5px 0; color:#718096; width:120px;">Evento:</td><td style="padding:5px 0; font-weight:bold;">' . esc_html($product_name) . '</td></tr>';
            $details_table_admin_cancel .= '<tr><td style="padding:5px 0; color:#718096;">Data Prenotata:</td><td style="padding:5px 0;">' . esc_html($slot_info) . '</td></tr>';
            $details_table_admin_cancel .= '<tr><td style="padding:5px 0; color:#718096;">Partecipanti:</td><td style="padding:5px 0;">2 totali</td></tr>';
            $details_table_admin_cancel .= '<tr><td style="padding:5px 0; color:#718096;">Stato:</td><td style="padding:5px 0; font-weight:bold; color:#e53e3e;">ANNULLATA DALLO STAFF</td></tr>';
            $details_table_admin_cancel .= '</table>';
            $details_table_admin_cancel .= '</div>';

            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'dettagli_prenotazione' => $details_table_admin_cancel,
            ];
            $body_template = dfn_get_setting('email_admin_cancelled_body');
            $content = dfn_replace_email_placeholders($body_template, $replacements);
            if (strpos($body_template, '{dettagli_prenotazione}') === false) {
                $content .= $details_table_admin_cancel;
            }
            $content .= '<p>Speriamo di poterti accogliere in uno dei nostri prossimi eventi FAI.</p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_admin_cancelled_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_admin_cancelled_title'), $replacements);
            break;

        case 'reminder':
            $modify_url = home_url('/');
            $details_table_rem = '<div class="info-box" style="border-left:4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color:#f7fafc; padding:15px; margin:15px 0;">';
            $details_table_rem .= '<div class="info-box-title" style="color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight:bold; margin-bottom:5px;">Dettagli per Domani</div>';
            $details_table_rem .= '<table style="width:100%; border-collapse:collapse;">';
            $details_table_rem .= '<tr><td style="padding:5px 0; color:#718096; width:120px;">Evento:</td><td style="padding:5px 0; font-weight:bold;">' . esc_html($product_name) . '</td></tr>';
            $details_table_rem .= '<tr><td style="padding:5px 0; color:#718096;">Data e Inizio:</td><td style="padding:5px 0; font-weight:bold;">' . esc_html($slot_info) . '</td></tr>';
            $details_table_rem .= '<tr><td style="padding:5px 0; color:#718096;">Luogo Ritrovo:</td><td style="padding:5px 0;">' . esc_html($location) . '</td></tr>';
            $details_table_rem .= '</table>';
            $details_table_rem .= '</div>';

            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'dettagli_prenotazione' => $details_table_rem,
                'url_biglietto' => esc_url($hub_url),
                'url_annullamento' => esc_url($cancel_url),
                'url_modifica' => esc_url($modify_url),
            ];
            $intro_html = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_intro'), $replacements);
            $notes_html = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_notes'), $replacements);

            $content = $intro_html;
            $content .= $details_table_rem;
            $content .= $notes_html;
            $content .= '<p style="font-size:14px; color:#4a5568;"><em>Nota: Avendo optato per il contributo all\'ingresso, ti chiediamo di presentarti con qualche minuto di anticipo al fine di evitare code e velocizzare il check-in.</em></p>';
            $content .= '<div style="text-align:center; margin:20px 0;"><a href="' . esc_url($hub_url) . '" style="background-color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; color:#ffffff; padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Apri Prenotazione con Codice QR</a></div>';
            $content .= '<p style="text-align: center; margin-top: 25px; font-size: 13px; color: #718096;">Devi modificare il numero di partecipanti? <a href="' . esc_url($modify_url) . '" style="color: #004b23; text-decoration: underline; font-weight: bold;">Modifica la prenotazione qui</a></p>';
            $content .= '<p style="text-align: center; margin-top: 10px; font-size: 13px; color: #718096;">Non puoi più partecipare affatto? <a href="' . esc_url($cancel_url) . '" style="color: #dc2626; text-decoration: underline; font-weight: bold;">Annulla la tua prenotazione qui</a></p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_reminder_title'), $replacements);
            break;

        case 'waitlist':
            $waitlist_ttl = intval(dfn_get_setting('cron_waitlist_ttl', 2));
            $details_table_wl = '<div class="info-box" style="border-left:4px solid ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; background-color:#f7fafc; padding:15px; margin:15px 0;">';
            $details_table_wl .= '<div class="info-box-title" style="color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; font-weight:bold; margin-bottom:5px;">La tua Prenotazione Riservata</div>';
            $details_table_wl .= '<table style="width:100%; border-collapse:collapse;">';
            $details_table_wl .= '<tr><td style="padding:5px 0; color:#718096; width:120px;">Evento:</td><td style="padding:5px 0; font-weight:bold;">' . esc_html($product_name) . '</td></tr>';
            $details_table_wl .= '<tr><td style="padding:5px 0; color:#718096;">Posti Riservati:</td><td style="padding:5px 0;">2</td></tr>';
            $details_table_wl .= '<tr><td style="padding:5px 0; color:#718096;">Scadenza Priorità:</td><td style="padding:5px 0; color:#e53e3e; font-weight:bold;">' . date('H:i', strtotime('+2 hours')) . ' di oggi</td></tr>';
            $details_table_wl .= '</table>';
            $details_table_wl .= '</div>';

            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'nome_evento'  => esc_html($product_name),
                'ore_waitlist' => $waitlist_ttl,
                'dettagli_prenotazione' => $details_table_wl,
            ];
            $body_template = dfn_get_setting('email_waitlist_body');
            $content = dfn_replace_email_placeholders($body_template, $replacements);
            if (strpos($body_template, '{dettagli_prenotazione}') === false) {
                $content .= $details_table_wl;
            }
            $content .= '<p>Clicca sul pulsante sottostante per accedere direttamente al checkout veloce e confermare subito la tua presenza:</p>';
            $content .= '<div style="text-align:center; margin:20px 0;"><a href="' . esc_url($hub_url) . '" style="background-color:' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; color:#ffffff; padding:10px 20px; text-decoration:none; border-radius:4px; font-weight:bold; display:inline-block;">Completa la Prenotazione Ora</a></div>';
            $content .= '<p style="font-size: 13px; color: #718096;"><em>Se non completerai la prenotazione entro le ore ' . date('H:i', strtotime('+2 hours')) . ', il sistema annullerà automaticamente la tua prenotazione riservata e sbloccherà lo slot per il prossimo utente in attesa.</em></p>';

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_waitlist_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_waitlist_title'), $replacements);
            break;

        case 'fai_approved':
            $card_number = '123456';
            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'numero_tessera' => esc_html($card_number),
            ];
            $content = dfn_replace_email_placeholders(dfn_get_setting('email_fai_approved_body'), $replacements);

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_fai_approved_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_fai_approved_title'), $replacements);
            break;

        case 'fai_rejected':
            $card_number = '123456';
            $reason = 'Tessera scaduta il 31/12/2025';
            $replacements = [
                'nome_cliente' => esc_html($customer_name),
                'numero_tessera' => esc_html($card_number),
                'motivo_rifiuto' => esc_html($reason),
            ];
            $body_template = dfn_get_setting('email_fai_rejected_body');
            $has_motivo_placeholder = (strpos($body_template, '{motivo_rifiuto}') !== false);
            $content = dfn_replace_email_placeholders($body_template, $replacements);

            if (!$has_motivo_placeholder) {
                $content .= '<div class="info-box" style="border-left: 4px solid #e53e3e; background: #fff5f5; padding: 15px; margin: 15px 0;">';
                $content .= '<div class="info-box-title" style="color: #e53e3e; font-weight: bold; margin-bottom: 5px;">Motivazione dello Staff</div>';
                $content .= '<p style="margin:0; font-size:14px; color: #c53030;">' . esc_html($reason) . '</p>';
                $content .= '</div>';
            }

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_fai_rejected_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_fai_rejected_title'), $replacements);
            break;

        case 'fai_booking_rejected':
            $motivo_text = 'Superamento della capacità massima del turno selezionato o tessere FAI non valide.';
            $formatted_motivo = '<div class="info-box" style="border-left: 4px solid ' . esc_attr(dfn_get_setting('email_accent_color', '#e74f30')) . '; background-color: #f7fafc; padding: 18px 20px; margin: 25px 0; border-radius: 0 6px 6px 0;">';
            $formatted_motivo .= '<div class="info-box-title" style="font-weight: bold; font-size: 15px; color: ' . esc_attr(dfn_get_setting('email_primary_color', '#004b23')) . '; margin-bottom: 8px;">Nota dallo Staff</div>';
            $formatted_motivo .= '<p style="margin: 0; font-size: 14px; color: #2d3748; line-height: 1.5;">' . esc_html($motivo_text) . '</p>';
            $formatted_motivo .= '</div>';

            $replacements = [
                'nome_cliente'   => esc_html($customer_name),
                'nome_evento'    => esc_html($product_name),
                'motivo_rifiuto' => $formatted_motivo,
            ];
            $body_template = dfn_get_setting('email_fai_booking_rejected_body');
            $has_motivo_placeholder = (strpos($body_template, '{motivo_rifiuto}') !== false);
            $content = dfn_replace_email_placeholders($body_template, $replacements);

            if (! $has_motivo_placeholder) {
                $content .= $formatted_motivo;
            }

            $subject = dfn_replace_email_placeholders(dfn_get_setting('email_fai_booking_rejected_subject'), $replacements);
            $title   = dfn_replace_email_placeholders(dfn_get_setting('email_fai_booking_rejected_title'), $replacements);
            break;

        default:
            wp_send_json_error(__('Tipo di email non gestito.', 'dfn-theme'));
    }

    $subject = '[PROVA] ' . $subject;

    // Invia l'email tramite il nostro dispatcher standard
    $sent = dfn_send_notification_email($destination, $subject, $title, $content);

    if ($sent) {
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Errore nell\'invio tramite wp_mail.', 'dfn-theme'));
    }
}

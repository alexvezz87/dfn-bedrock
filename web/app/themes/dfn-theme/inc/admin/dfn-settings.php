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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'dfn_settings_register_menu' );

/**
 * Registra il sottomenu delle Impostazioni sotto "FAI Prenotazioni".
 */
function dfn_settings_register_menu(): void {
    add_submenu_page(
        'dfn-events',
        esc_html__( 'Impostazioni FAI Prenotazioni', 'dfn-theme' ),
        esc_html__( 'Impostazioni', 'dfn-theme' ),
        'dfn_manage_events',
        'dfn-settings',
        'dfn_render_settings_page'
    );
}

/**
 * Gestisce il salvataggio delle impostazioni inviate tramite POST.
 */
function dfn_settings_save_fields(): void {
    if ( ! isset( $_POST['dfn_settings_nonce'] ) || ! wp_verify_nonce( $_POST['dfn_settings_nonce'], 'dfn_save_settings_action' ) ) {
        return;
    }

    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        return;
    }

    $existing_settings = get_option( 'dfn_settings', array() );
    if ( ! is_array( $existing_settings ) ) {
        $existing_settings = array();
    }

    // Definizione dei campi e delle relative regole di sanitizzazione
    $fields_to_sanitize = array(
        // Tab Generale
        'delegation_name'             => 'sanitize_text_field',
        'delegation_footer'           => 'sanitize_text_field',
        'email_staff_signature'       => 'sanitize_text_field',

        // Tab Email & Notifiche
        'email_new_booking'           => 'sanitize_email_list',
        'email_verify_fai'            => 'sanitize_email_list',
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

        // Tab Avanzate & Toggle
        'enable_admin_notification'   => 'sanitize_text_field', // 'yes' o 'no'
        'enable_reminder_24h'         => 'sanitize_text_field', // 'yes' o 'no'
        'enable_auto_waitlist'        => 'sanitize_text_field', // 'yes' o 'no'
        'enable_auto_complete_paid'   => 'sanitize_text_field', // 'yes' o 'no'
    );

    $new_settings = array();
    foreach ( $fields_to_sanitize as $key => $sanitize_func ) {
        if ( isset( $_POST['dfn_settings'][$key] ) ) {
            $val = $_POST['dfn_settings'][$key];
            if ( $sanitize_func === 'absint' ) {
                $new_settings[$key] = absint( $val );
            } elseif ( $sanitize_func === 'sanitize_hex_color' ) {
                $new_settings[$key] = sanitize_hex_color( $val );
            } elseif ( $sanitize_func === 'sanitize_email_list' ) {
                $emails = array_map( 'sanitize_email', array_map( 'trim', explode( ',', $val ) ) );
                $emails = array_filter( $emails );
                $new_settings[$key] = implode( ', ', $emails );
            } elseif ( $sanitize_func === 'sanitize_textarea_field' ) {
                $new_settings[$key] = sanitize_textarea_field( $val );
            } else {
                $new_settings[$key] = sanitize_text_field( $val );
            }
        } else {
            // Se un checkbox non è inviato, assumiamo sia 'no' se appartiene ai toggle
            if ( strpos( $key, 'enable_' ) === 0 ) {
                $new_settings[$key] = 'no';
            }
        }
    }

    // Mantieni i campi di sola lettura non inviabili/modificabili direttamente
    $new_settings['setup_roles_version'] = '2.0';
    $new_settings['setup_fai_discount']  = 5;

    $updated = update_option( 'dfn_settings', $new_settings );

    if ( $updated ) {
        add_settings_error(
            'dfn_settings_messages',
            'dfn_settings_updated',
            __( 'Impostazioni salvate con successo.', 'dfn-theme' ),
            'updated'
        );
    } else {
        add_settings_error(
            'dfn_settings_messages',
            'dfn_settings_no_change',
            __( 'Nessuna modifica rilevata o errore nel salvataggio.', 'dfn-theme' ),
            'info'
        );
    }
}

/**
 * Renderizza la pagina di gestione delle Impostazioni DFN.
 */
function dfn_render_settings_page(): void {
    if ( ! current_user_can( 'dfn_manage_events' ) ) {
        wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 'dfn-theme' ) );
    }

    // Gestisci il salvataggio se sottomesso
    if ( isset( $_POST['dfn_settings_nonce'] ) ) {
        dfn_settings_save_fields();
    }

    // Carica gli stili specifici dell'admin se non sono già stati inclusi
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'wp-color-picker' );

    // Mostra i messaggi di notifica/errore
    settings_errors( 'dfn_settings_messages' );

    // Recupera la tab attiva
    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'generale';

    $tabs = array(
        'generale'   => '🏢 Generale',
        'email'      => '📧 Email & Notifiche',
        'cron'       => '⏰ Automazioni & Cron',
        'tessere'    => '🍊 Tessere Socio FAI',
        'limiti'     => '📊 Limiti & Testi',
        'avanzate'   => '🔧 Avanzate & Toggle',
    );
    ?>
    <div class="wrap dfn-settings-wrap">
        <h1 class="wp-heading-inline" style="margin-bottom: 20px;">⚙️ Impostazioni FAI Prenotazioni</h1>
        <hr class="wp-header-end">

        <div class="dfn-settings-container" style="display: flex; gap: 20px; margin-top: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;">
            
            <!-- Sidebar Navigation Tabs -->
            <div class="dfn-settings-sidebar" style="width: 240px; background: #f6f7f7; border-right: 1px solid #ccd0d4; flex-shrink: 0;">
                <ul style="list-style: none; margin: 0; padding: 0;">
                    <?php foreach ( $tabs as $tab_id => $tab_label ) : 
                        $is_active = ( $active_tab === $tab_id );
                        $tab_url = admin_url( 'admin.php?page=dfn-settings&tab=' . $tab_id );
                        $bg = $is_active ? '#ffffff' : 'transparent';
                        $color = $is_active ? '#004b23' : '#2c3338';
                        $border_left = $is_active ? '4px solid #004b23' : '4px solid transparent';
                        $font_weight = $is_active ? '600' : 'normal';
                        ?>
                        <li style="margin: 0; border-bottom: 1px solid #e5e5e5;">
                            <a href="<?php echo esc_url( $tab_url ); ?>" style="display: block; padding: 15px 20px; color: <?php echo esc_attr( $color ); ?>; background-color: <?php echo esc_attr( $bg ); ?>; border-left: <?php echo esc_attr( $border_left ); ?>; font-weight: <?php echo esc_attr( $font_weight ); ?>; text-decoration: none; outline: none; box-shadow: none; transition: all 0.1s ease-in-out;">
                                <?php echo esc_html( $tab_label ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Form Content Area -->
            <div class="dfn-settings-content" style="flex-grow: 1; padding: 30px 40px; min-width: 0;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=dfn-settings&tab=' . $active_tab ) ); ?>">
                    <?php wp_nonce_field( 'dfn_save_settings_action', 'dfn_settings_nonce' ); ?>

                    <?php if ( $active_tab === 'generale' ) : ?>
                        <!-- TAB GENERALE -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🏢 Identità Delegazione / Organizzazione</h2>
                        <p class="description" style="margin-bottom: 25px;">Configura i nomi e le firme testuali per personalizzare la delegazione di appartenenza ed evitare riferimenti fissi nel codice.</p>
                        
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="delegation_name">Nome Delegazione</label></th>
                                <td>
                                    <input name="dfn_settings[delegation_name]" type="text" id="delegation_name" value="<?php echo esc_attr( dfn_get_setting( 'delegation_name' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Il nome abbreviato della delegazione FAI (es. "FAI Novara"). Viene visualizzato come mittente delle e-mail e in varie sezioni informative del portale.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="delegation_footer">Nome Completo (footer email)</label></th>
                                <td>
                                    <input name="dfn_settings[delegation_footer]" type="text" id="delegation_footer" value="<?php echo esc_attr( dfn_get_setting( 'delegation_footer' ) ); ?>" class="large-text" />
                                    <p class="description"><strong>Comportamento:</strong> Nome formale completo (es. "FAI - Delegazione di Novara") stampato nel footer/piè di pagina di tutte le e-mail e nelle informative ufficiali.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_staff_signature">Firma Email Staff</label></th>
                                <td>
                                    <input name="dfn_settings[email_staff_signature]" type="text" id="email_staff_signature" value="<?php echo esc_attr( dfn_get_setting( 'email_staff_signature' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> La firma testuale predefinita (es. "Lo Staff della Delegazione FAI Novara") utilizzata per chiudere i messaggi automatici o inviati a mano dai gestori.</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ( $active_tab === 'email' ) : ?>
                        <!-- TAB EMAIL & NOTIFICHE -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">📧 Destinatari & Stile Notifiche</h2>
                        <p class="description" style="margin-bottom: 25px;">Gestisci i canali di ricezione delle e-mail di sistema e personalizza il layout grafico dei messaggi HTML.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="email_new_booking">Email Notifica Nuove Prenotazioni</label></th>
                                <td>
                                    <input name="dfn_settings[email_new_booking]" type="text" id="email_new_booking" value="<?php echo esc_attr( dfn_get_setting( 'email_new_booking' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Gli indirizzi e-mail (separati da virgola se multipli) che riceveranno un avviso automatico ogni volta che viene completata con successo una nuova prenotazione da parte di un cliente.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_verify_fai">Email Notifica Tessere FAI da Verificare</label></th>
                                <td>
                                    <input name="dfn_settings[email_verify_fai]" type="text" id="email_verify_fai" value="<?php echo esc_attr( dfn_get_setting( 'email_verify_fai' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Gli indirizzi e-mail (separati da virgola se multipli) a cui inviare una notifica di avviso immediato quando un utente si iscrive o prenota inserendo un codice tessera FAI che richiede una convalida manuale in amministrazione.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_cc_bcc">CC / BCC Copia Conoscenza</label></th>
                                <td>
                                    <input name="dfn_settings[email_cc_bcc]" type="text" id="email_cc_bcc" value="<?php echo esc_attr( dfn_get_setting( 'email_cc_bcc' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Indirizzi e-mail aggiuntivi (separati da virgola) a cui inviare una copia nascosta (Bcc) o normale (Cc) per tenere traccia di tutte le notifiche importanti inviate dal sistema.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_primary_color">Colore Primario Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_primary_color]" type="text" id="email_primary_color" value="<?php echo esc_attr( dfn_get_setting( 'email_primary_color' ) ); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Colore primario usato nel template HTML delle email (per l'header superiore, i titoli principali H1/H2 e lo sfondo dei pulsanti call-to-action).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_accent_color">Colore Accento Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_accent_color]" type="text" id="email_accent_color" value="<?php echo esc_attr( dfn_get_setting( 'email_accent_color' ) ); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Tonalità usata per dettagli minori (bordi decorativi, link, badge di stato, box informativi secondari).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_bg_color">Colore Sfondo Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_bg_color]" type="text" id="email_bg_color" value="<?php echo esc_attr( dfn_get_setting( 'email_bg_color' ) ); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Il colore di sfondo dell'area esterna della mail (il contenitore grigio o neutro che circonda il foglio bianco del messaggio).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_text_color">Colore Testo Email</label></th>
                                <td>
                                    <input name="dfn_settings[email_text_color]" type="text" id="email_text_color" value="<?php echo esc_attr( dfn_get_setting( 'email_text_color' ) ); ?>" class="dfn-color-field" />
                                    <p class="description"><strong>Comportamento:</strong> Il colore del font per il corpo del testo principale della e-mail, per garantire un corretto contrasto e riposo visivo.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="email_disclaimer">Disclaimer Piè di Pagina</label></th>
                                <td>
                                    <textarea name="dfn_settings[email_disclaimer]" id="email_disclaimer" rows="4" cols="50" class="large-text"><?php echo esc_textarea( dfn_get_setting( 'email_disclaimer' ) ); ?></textarea>
                                    <p class="description"><strong>Comportamento:</strong> Nota legale / informativa stampata in caratteri piccoli nel footer delle mail (es. "Questa è un'email automatica, si prega di non rispondere direttamente").</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ( $active_tab === 'cron' ) : ?>
                        <!-- TAB AUTOMAZIONI & CRON -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">⏰ Temporizzazioni & Cron Jobs</h2>
                        <p class="description" style="margin-bottom: 25px;">Imposta i parametri temporali e le soglie di esecuzione per i processi in background che gestiscono scadenze e notifiche.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="cron_timeout_no_booking">Timeout Ordini Senza Booking (Ore)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_timeout_no_booking]" type="number" id="cron_timeout_no_booking" value="<?php echo esc_attr( dfn_get_setting( 'cron_timeout_no_booking' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Se un cliente avvia un checkout ma non viene creata alcuna prenotazione valida a causa di errori, il cron cancella l'ordine orfano dopo questo numero di ore per liberare i posti.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_reminder_start">Finestra Promemoria — Inizio (Ore prima)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_reminder_start]" type="number" id="cron_reminder_start" value="<?php echo esc_attr( dfn_get_setting( 'cron_reminder_start' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Limite temporale inferiore in ore. Il sistema invia il promemoria di visita solo a partire da questa soglia temporale precedente l'evento (es. 12 ore prima).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_reminder_end">Finestra Promemoria — Fine (Ore prima)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_reminder_end]" type="number" id="cron_reminder_end" value="<?php echo esc_attr( dfn_get_setting( 'cron_reminder_end' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Limite temporale superiore in ore. Il sistema smette di inviare promemoria se mancano più di queste ore all'evento (es. 36 ore prima), garantendo l'invio nella finestra corretta (es. a 24 ore esatte).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_waitlist_ttl">TTL Priorità Waitlist (Ore)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_waitlist_ttl]" type="number" id="cron_waitlist_ttl" value="<?php echo esc_attr( dfn_get_setting( 'cron_waitlist_ttl' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Quando si libera un posto e viene notificato un utente in lista d'attesa, quest'ultimo ha a disposizione questo numero di ore per completare l'iscrizione prima che il link scada e il posto passi al successivo.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_batch_reminder">Batch Promemoria (Massimo per ciclo)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_batch_reminder]" type="number" id="cron_batch_reminder" value="<?php echo esc_attr( dfn_get_setting( 'cron_batch_reminder' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Numero massimo di email di promemoria inviate per singola esecuzione oraria del cron, al fine di scongiurare blocchi o limitazioni del server di posta (rate limit).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="cron_batch_expired">Batch Ordini Scaduti (Massimo per ciclo)</label></th>
                                <td>
                                    <input name="dfn_settings[cron_batch_expired]" type="number" id="cron_batch_expired" value="<?php echo esc_attr( dfn_get_setting( 'cron_batch_expired' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Limite superiore di ordini in sospeso o scaduti da verificare e pulire ad ogni esecuzione del cron job.</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ( $active_tab === 'tessere' ) : ?>
                        <!-- TAB TESSERE SOCIO FAI -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🍊 Tessere Socio FAI</h2>
                        <p class="description" style="margin-bottom: 25px;">Gestisci le opzioni per il riconoscimento dei soci, i coupon di sconto e le scadenze delle iscrizioni FAI.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="fai_coupon_code">Codice Coupon FAI (Legacy)</label></th>
                                <td>
                                    <input name="dfn_settings[fai_coupon_code]" type="text" id="fai_coupon_code" value="<?php echo esc_attr( dfn_get_setting( 'fai_coupon_code' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Il codice promozionale WooCommerce (es. `socio_fai_novara_2025`) utilizzato dal sistema per applicare gli sconti ai soci FAI nei flussi legacy.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fai_expiry_warning_days">Giorni Preavviso Scadenza Tessere</label></th>
                                <td>
                                    <input name="dfn_settings[fai_expiry_warning_days]" type="number" id="fai_expiry_warning_days" value="<?php echo esc_attr( dfn_get_setting( 'fai_expiry_warning_days' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Nella tabella anagrafica "Soci FAI", le tessere la cui scadenza cade entro questo numero di giorni verranno evidenziate con un badge giallo di allarme.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fai_member_types">Tipologie Tessera Disponibili</label></th>
                                <td>
                                    <input name="dfn_settings[fai_member_types]" type="text" id="fai_member_types" value="<?php echo esc_attr( dfn_get_setting( 'fai_member_types' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Opzioni inserite come elenco separato da virgola (es. `INDIVIDUALE, COPPIA, FAMIGLIA`) che popolano il menu a tendina quando lo staff crea o modifica un socio FAI.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fai_no_email_placeholder">Email Placeholder per Botteghino</label></th>
                                <td>
                                    <input name="dfn_settings[fai_no_email_placeholder]" type="text" id="fai_no_email_placeholder" value="<?php echo esc_attr( dfn_get_setting( 'fai_no_email_placeholder' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> L'indirizzo e-mail fittizio impostato di default (es. `no-email@dfn.it`) quando lo staff registra una prenotazione al volo in cassa senza raccogliere la mail del cliente.</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ( $active_tab === 'limiti' ) : ?>
                        <!-- TAB LIMITI & TESTI -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">📊 Limiti di Visualizzazione & Testi Custom</h2>
                        <p class="description" style="margin-bottom: 25px;">Personalizza i messaggi mostrati ai clienti e imposta i limiti fisici per evitare rallentamenti nelle schermate di report.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="limit_max_fai_members">Max Soci FAI Visibili Senza Ricerca</label></th>
                                <td>
                                    <input name="dfn_settings[limit_max_fai_members]" type="number" id="limit_max_fai_members" value="<?php echo esc_attr( dfn_get_setting( 'limit_max_fai_members' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Numero di record anagrafici caricati inizialmente nella tabella dei soci prima che l'utente effettui una ricerca attiva. Protegge la pagina da sovraccarichi in caso di migliaia di soci.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="limit_max_activity_logs">Max Log Attività per Utente</label></th>
                                <td>
                                    <input name="dfn_settings[limit_max_activity_logs]" type="number" id="limit_max_activity_logs" value="<?php echo esc_attr( dfn_get_setting( 'limit_max_activity_logs' ) ); ?>" min="1" class="small-text" />
                                    <p class="description"><strong>Comportamento:</strong> Tetto massimo di righe memorizzate per ogni iscritto all'interno del proprio registro storico di navigazione. I log più vecchi vengono rimossi automaticamente.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="text_early_arrival">Messaggio Anticipo Arrivo</label></th>
                                <td>
                                    <input name="dfn_settings[text_early_arrival]" type="text" id="text_early_arrival" value="<?php echo esc_attr( dfn_get_setting( 'text_early_arrival' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Stringa inserita nel corpo della mail per istruire i prenotati ad arrivare per tempo (es. "almeno 10 minuti prima").</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="text_no_bookings_myaccount">Messaggio Nessuna Prenotazione (My Account)</label></th>
                                <td>
                                    <textarea name="dfn_settings[text_no_bookings_myaccount]" id="text_no_bookings_myaccount" rows="3" cols="50" class="large-text"><?php echo esc_textarea( dfn_get_setting( 'text_no_bookings_myaccount' ) ); ?></textarea>
                                    <p class="description"><strong>Comportamento:</strong> Testo visualizzato nel tab "Prenotazioni" dell'area clienti WooCommerce se l'utente loggato non ha ancora registrato alcun biglietto.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="text_checkout_btn">Testo Bottone Checkout</label></th>
                                <td>
                                    <input name="dfn_settings[text_checkout_btn]" type="text" id="text_checkout_btn" value="<?php echo esc_attr( dfn_get_setting( 'text_checkout_btn' ) ); ?>" class="regular-text" />
                                    <p class="description"><strong>Comportamento:</strong> Sostituisce la dicitura sul pulsante standard di conferma d'acquisto nella pagina finale di Checkout (es. "Effettua Prenotazione" al posto di "Effettua ordine").</p>
                                </td>
                            </tr>
                        </table>

                    <?php elseif ( $active_tab === 'avanzate' ) : ?>
                        <!-- TAB AVANZATE & TOGGLE -->
                        <h2 style="color: #004b23; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-top: 0;">🔧 Avanzate, Toggle & Sola Lettura</h2>
                        <p class="description" style="margin-bottom: 25px;">Abilita o disabilita intere funzionalità del motore di prenotazione e consulta le costanti fisse di configurazione.</p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Abilitare notifica admin per ogni prenotazione</th>
                                <td>
                                    <label for="enable_admin_notification">
                                        <input name="dfn_settings[enable_admin_notification]" type="checkbox" id="enable_admin_notification" value="yes" <?php checked( dfn_get_setting( 'enable_admin_notification' ), 'yes' ); ?> />
                                        Sì, invia un'email all'indirizzo amministrativo ogni volta che viene completata una prenotazione.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Se deselezionata, blocca le email di avviso all'amministratore per ridurre il traffico sulla casella di posta, continuando però a inviare i biglietti agli utenti finali.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Abilitare promemoria 24h</th>
                                <td>
                                    <label for="enable_reminder_24h">
                                        <input name="dfn_settings[enable_reminder_24h]" type="checkbox" id="enable_reminder_24h" value="yes" <?php checked( dfn_get_setting( 'enable_reminder_24h' ), 'yes' ); ?> />
                                        Sì, invia automaticamente l'email di promemoria pre-evento agli utenti.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Controlla se il cron job è autorizzato a spedire l'e-mail di promemoria automatica prima del turno prenotato.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Abilitare lista d'attesa automatica</th>
                                <td>
                                    <label for="enable_auto_waitlist">
                                        <input name="dfn_settings[enable_auto_waitlist]" type="checkbox" id="enable_auto_waitlist" value="yes" <?php checked( dfn_get_setting( 'enable_auto_waitlist' ), 'yes' ); ?> />
                                        Sì, attiva il modulo lista d'attesa per gli eventi che esauriscono i posti.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Se disattivato, al raggiungimento della capienza massima di uno slot non verrà più proposto agli utenti il form per iscriversi in coda.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Abilitare auto-completamento ordini pagati online</th>
                                <td>
                                    <label for="enable_auto_complete_paid">
                                        <input name="dfn_settings[enable_auto_complete_paid]" type="checkbox" id="enable_auto_complete_paid" value="yes" <?php checked( dfn_get_setting( 'enable_auto_complete_paid' ), 'yes' ); ?> />
                                        Sì, imposta come "Completato" ogni ordine pagato con carta/gateway online.
                                    </label>
                                    <p class="description"><strong>Comportamento:</strong> Evita che gli ordini saldati tramite Stripe, PayPal o altri gateway digitali rimangano in stato "Elaborazione", forzando la chiusura immediata e l'invio dei biglietti PDF.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Versione Ruoli (Sola Lettura)</label></th>
                                <td>
                                    <input type="text" value="<?php echo esc_attr( dfn_get_setting( 'setup_roles_version' ) ); ?>" class="small-text" readonly disabled />
                                    <p class="description"><strong>Comportamento:</strong> Indica la versione dei privilegi utente e dei ruoli personalizzati registrati all'attivazione del tema.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label>Sconto Unitario FAI (Sola Lettura)</label></th>
                                <td>
                                    <input type="text" value="<?php echo esc_attr( dfn_get_setting( 'setup_fai_discount' ) ); ?> €" class="small-text" readonly disabled />
                                    <p class="description"><strong>Comportamento:</strong> Valore fisso legacy per lo sconto socio FAI. Negli eventi moderni lo sconto viene determinato dinamicamente dal prezzo del singolo biglietto, perciò questa costante viene mostrata solo come dato informativo storico.</p>
                                </td>
                            </tr>
                        </table>

                    <?php endif; ?>

                    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                        <?php submit_button( __( 'Salva Impostazioni', 'dfn-theme' ), 'primary', 'submit', false, array( 'style' => 'background: #004b23; border-color: #003318; box-shadow: none; text-shadow: none;' ) ); ?>
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
        });
    </script>
    <?php
}

<?php
/**
 * DFN Booking System 2.0 — Visitor Account & Dashboard Controller
 *
 * Gestisce l'integrazione con l'area riservata WooCommerce "My Account"
 * e le azioni rapide di accesso ai biglietti di gruppo.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

// Inietta gli asset premium nella pagina "Mio Account"
add_action('wp_enqueue_scripts', 'dfn_enqueue_myaccount_assets');

// Associa automaticamente ordini passati in fase di registrazione
add_action('woocommerce_created_customer', 'dfn_associate_past_orders_to_new_customer', 10, 1);

// Blocco login automatico dopo la registrazione per ragioni di sicurezza email
add_filter('woocommerce_registration_auth_new_customer', '__return_false');
add_filter('woocommerce_registration_redirect', 'dfn_registration_redirect_with_notice', 10, 1);

// Intestazione grafica per la pagina login/registrazione utenti non loggati
add_action('woocommerce_before_customer_login_form', 'dfn_render_login_page_header');
function dfn_render_login_page_header(): void
{
    echo '<div class="dfn-auth-header">';
    echo '<h1 class="dfn-auth-title">Area Riservata</h1>';
    echo '<p class="dfn-auth-subtitle">Accedi o registrati alla piattaforma per gestire al meglio le tue prenotazioni agli eventi del FAI Novara</p>';
    echo '</div>';
}

// Aggiunge la voce di menu rapida "Biglietto Gruppo" alla lista degli ordini cliente
add_filter('woocommerce_my_account_my_orders_actions', 'dfn_add_group_tickets_action_button', 10, 2);

// AJAX Handlers per Modifica e Annullamento in popup dall'area riservata
add_action('wp_ajax_dfn_visitor_get_modify_details', 'dfn_ajax_visitor_get_modify_details');
add_action('wp_ajax_dfn_visitor_submit_modify', 'dfn_ajax_visitor_submit_modify');
add_action('wp_ajax_dfn_visitor_submit_cancel', 'dfn_ajax_visitor_submit_cancel');

/**
 * Registra gli asset CSS dedicati alla bacheca visitatori e all'hub biglietti.
 */
function dfn_enqueue_myaccount_assets(): void
{
    if (is_account_page()) {
        $css_file = get_stylesheet_directory() . '/assets/css/dfn-visitor-dashboard.css';
        $ver = file_exists($css_file) ? (string) filemtime($css_file) : '2.4.0';

        wp_enqueue_style(
            'dfn-visitor-dashboard-css',
            get_stylesheet_directory_uri() . '/assets/css/dfn-visitor-dashboard.css',
            [],
            $ver,
        );

        // Tour guidato balloon
        wp_enqueue_style(
            'dfn-tour-css',
            get_stylesheet_directory_uri() . '/assets/css/dfn-tour.css',
            [],
            '2.0.0',
        );

        wp_enqueue_script(
            'dfn-myaccount-modals',
            get_stylesheet_directory_uri() . '/assets/js/dfn-myaccount-modals.js',
            [ 'jquery' ],
            '2.1.0',
            true // in_footer = true: il JS viene caricato prima del </body>, quando il DOM è già completo
        );

        wp_enqueue_script(
            'dfn-tour',
            get_stylesheet_directory_uri() . '/assets/js/dfn-tour.js',
            [],
            '2.0.0',
            true,
        );

        // Dati degli step del tour passati in modo sicuro da PHP a JS
        wp_localize_script('dfn-tour', 'dfnTourData', [
            'tours' => [
                // Tour 0 — Bacheca Principale & Tour del Menu Laterale
                [
                    'storageKey'    => 'dfn_tour_dashboard_done',
                    'sectionAnchor' => '.dfn-dashboard-hub',
                    'steps'         => [
                        [
                            'selector' => '.dfn-dashboard-hub',
                            'title'    => '👋 La Tua Area Riservata FAI',
                            'content'  => 'Benvenuto nella tua bacheca personale! Da qui puoi gestire tutte le tue esperienze, consultare i biglietti e accedere ai vantaggi dedicati ai sostenitori FAI.',
                        ],
                        [
                            'selector' => '.woocommerce-MyAccount-navigation-link--dashboard a',
                            'title'    => '📊 Sezione: Bacheca',
                            'content'  => 'La <strong>Bacheca</strong> è la tua schermata principale. Qui trovi il riepilogo in tempo reale con il tuo prossimo appuntamento imminente, le statistiche di visita e le novità del FAI Novara.',
                        ],
                        [
                            'selector' => '.woocommerce-MyAccount-navigation-link--orders a',
                            'title'    => '🎟️ Sezione: Le Mie Prenotazioni',
                            'content'  => 'In questa sezione puoi consultare tutte le tue prenotazioni passate e future. Da qui puoi <strong>scaricare i tuoi biglietti</strong> con QR Code, <strong>modificare il numero di partecipanti</strong> o <strong>annullare i posti</strong> in caso di imprevisti.',
                        ],
                        [
                            'selector' => '.woocommerce-MyAccount-navigation-link--tessere-fai a',
                            'title'    => '🪪 Sezione: Tessere FAI',
                            'content'  => 'Gestisci e inserisci qui le tue <strong>Tessere Iscritto FAI</strong>. Una volta verificate dalla segreteria, sbloccherai le quote agevolate durante la prenotazione e potrai mostrare la tua tessera digitale con QR Code direttamente all\'ingresso degli eventi.',
                        ],
                        [
                            'selector' => '.woocommerce-MyAccount-navigation-link--edit-account a',
                            'title'    => '👤 Sezione: Dettagli Account',
                            'content'  => 'In questa sezione puoi aggiornare i tuoi dati personali (nome, cognome, indirizzo email di contatto) e modificare la tua password di accesso in totale sicurezza.',
                        ],
                        [
                            'selector' => '.woocommerce-MyAccount-navigation-link--customer-logout a',
                            'title'    => '🚪 Sezione: Esci',
                            'content'  => 'Clicca qui per disconnetterti in modo sicuro dal tuo account quando utilizzi dispositivi pubblici o condivisi.',
                        ],
                    ],
                ],
                // Tour 1 — Le Mie Prenotazioni
                [
                    'storageKey'    => 'dfn_tour_bookings_done',
                    'sectionAnchor' => '#dfn-my-bookings-section',
                    'steps'         => [
                        [
                            'selector' => '#dfn-my-bookings-section .dfn-dashboard-title',
                            'title'    => '📅 Benvenuto nel tuo Botteghino!',
                            'content'  => 'Questa è la tua bacheca personale delle prenotazioni FAI. Trovi tutte le tue prenotazioni suddivise tra <strong>Prossimi eventi</strong> e <strong>Visite passate</strong>.',
                        ],
                        [
                            'selector' => '#dfn-my-bookings-section .dfn-bookings-group-upcoming',
                            'title'    => '📆 Prossimi Eventi',
                            'content'  => 'Qui trovi tutti gli eventi ai quali sei prenotato e che non si sono ancora svolti. Clicca su una card per espandere il dettaglio della tua prenotazione.',
                        ],
                        [
                            'selector' => '#dfn-my-bookings-section .dfn-booking-accordion',
                            'title'    => '📂 Apri i dettagli',
                            'content'  => 'Ogni card è <strong>espandibile</strong>: clicca sulla riga per vedere orario del turno, numero di posti prenotati e modalità di pagamento.',
                        ],
                        [
                            'selector' => '#dfn-my-bookings-section .dfn-booking-status-badge',
                            'title'    => '🏷️ Badge di Stato',
                            'content'  => 'Il badge colorato indica lo stato: <strong style="color:#15803d">Verde = Confermata</strong>, <strong style="color:#b45309">Giallo = In attesa pagamento</strong>, <strong style="color:#dc2626">Rosso = Annullata</strong>.',
                        ],
                        [
                            'selector' => '#dfn-my-bookings-section .dfn-action-modify',
                            'title'    => '✏️ Modifica Partecipanti',
                            'content'  => 'Hai prenotato per troppe persone? Clicca <strong>Modifica</strong> per ridurre il numero di partecipanti Standard o Soci FAI. Non è possibile aumentarli: per aggiungere persone effettua una nuova prenotazione.',
                        ],
                        [
                            'selector' => '#dfn-my-bookings-section .dfn-btn-cancel-booking',
                            'title'    => '❌ Annullamento Prenotazione',
                            'content'  => 'Non riesci più a partecipare? Clicca <strong>Annulla</strong> per liberare i tuoi posti in modo che possano essere prenotati da altri utenti. L\'operazione è definitiva.',
                        ],
                    ],
                ],
                // Tour 2 — Tessere FAI
                [
                    'storageKey'    => 'dfn_tour_fai_done',
                    'sectionAnchor' => '#dfn-fai-section',
                    'steps'         => [
                        [
                            'selector' => '#dfn-fai-section .dfn-dashboard-title',
                            'title'    => '🪪 Le Mie Tessere FAI',
                            'content'  => 'In questa sezione puoi registrare e consultare le tue <strong>Tessere FAI</strong>. Le tessere verificate ti permettono di prenotare eventi al contributo riservato ai Soci.',
                        ],
                        [
                            'selector' => '#dfn-fai-section .dfn-add-fai-card-wrapper',
                            'title'    => '➕ Aggiungi una Tessera',
                            'content'  => 'Inserisci <strong>nome, cognome e numero di tessera</strong> e clicca "Invia Tessera". Lo staff FAI verificherà i dati: la tessera apparirà come attiva entro breve.',
                        ],
                        [
                            'selector' => '#dfn-fai-section .dfn-fai-digital-card',
                            'title'    => '💳 La tua Tessera Digitale',
                            'content'  => 'Una volta verificata, la tessera appare in formato digitale con tutti i dettagli e il <strong>QR Code</strong>. Puoi mostrarla direttamente dallo schermo al banchetto di un evento.',
                        ],
                    ],
                ],
            ],
        ]);

        // Passa l'URL AJAX al file JS in modo sicuro (non inline nel template PHP)
        wp_localize_script('dfn-myaccount-modals', 'dfnMyaccountModals', [
            'ajaxUrl' => esc_url(admin_url('admin-ajax.php')),
        ]);
    }
}

/**
 * Associa ordini precedentemente effettuati con la stessa email all'account appena registrato.
 *
 * @param int $customer_id ID del cliente WooCommerce.
 */
function dfn_associate_past_orders_to_new_customer(int $customer_id): void
{
    if (function_exists('wc_update_new_customer_past_orders')) {
        wc_update_new_customer_past_orders($customer_id);
    }
}

/**
 * Aggiunge un avviso descrittivo alla registrazione per informare l'utente sulla password generata via mail.
 *
 * @param string $redirect_url URL di destinazione.
 * @return string
 */
function dfn_registration_redirect_with_notice(string $redirect_url): string
{
    wc_add_notice(
        esc_html__('Registrazione completata con successo! 📧 Ti abbiamo inviato una password sicura via email. Controlla la tua posta (anche la cartella Spam) ed utilizzala per accedere al tuo Botteghino Personale.', 'dfn-theme'),
        'success',
    );
    return wc_get_page_permalink('myaccount');
}

/**
 * Inserisce il pulsante rapido "Mostra Biglietto Gruppo" per gli ordini confermati o in elaborazione.
 *
 * @param array<string, array<string, string>> $actions Azioni dell'ordine correnti.
 * @param \WC_Order $order Oggetto ordine WooCommerce.
 * @return array<string, array<string, string>>
 */
function dfn_add_group_tickets_action_button(array $actions, $order): array
{
    if ($order->has_status([ 'processing', 'completed' ])) {
        $hub_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
        $hub_url   = home_url('/?dfn_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token);

        $ticket_action = [
            'dfn_group_ticket' => [
                'url'  => $hub_url,
                'name' => esc_html__('🎟️ Ingressi Gruppo', 'dfn-theme'),
            ],
        ];

        // Fondi in cima alle azioni per massima visibilità
        $actions = array_merge($ticket_action, $actions);
    }
    return $actions;
}

/**
 * ========================================================================
 * LOGICA ENDPOINT WOOCOMMERCE "TESSERE FAI"
 * ========================================================================
 */

// Registra l'endpoint custom per le tessere FAI e per le Riunioni Volontari
add_action('init', 'dfn_fai_cards_endpoint_init');
/**
 * Registra i nuovi endpoint rewrite di WooCommerce per le tessere FAI e riunioni volontari.
 */
function dfn_fai_cards_endpoint_init(): void
{
    add_rewrite_endpoint('tessere-fai', EP_PAGES);
    add_rewrite_endpoint('riunioni-fai', EP_PAGES);
}

// Aggiunge le query var consentite da WooCommerce
add_filter('query_vars', 'dfn_fai_cards_query_vars', 0);
/**
 * Registra le variabili di query per gli endpoint tessere-fai e riunioni-fai.
 *
 * @param array $vars Variabili di query esistenti.
 * @return array Variabili di query aggiornate.
 */
function dfn_fai_cards_query_vars(array $vars): array
{
    $vars[] = 'tessere-fai';
    $vars[] = 'riunioni-fai';
    return $vars;
}

// Inserisce le voci nel menu Mio Account di WooCommerce
/**
 * Aggiunge la voce "Tessere FAI" e, se l'utente è un volontario, "Prossime Riunioni".
 *
 * @param array<string, string> $items Voci del menu account.
 * @return array<string, string> Menu modificato.
 */
function dfn_add_fai_cards_to_menu(array $items): array
{
    unset($items['customer-logout'], $items['downloads'], $items['edit-address']);

    $is_volunteer = function_exists('dfn_is_user_volunteer') ? dfn_is_user_volunteer() : false;

    $new_items = [];
    foreach ($items as $key => $value) {
        if ('edit-account' === $key) {
            $new_items['tessere-fai'] = esc_html__('Tessere FAI', 'dfn-theme');
            if ($is_volunteer) {
                $new_items['riunioni-fai'] = esc_html__('Prossime Riunioni', 'dfn-theme');
            }
            $new_items['edit-account'] = esc_html__('Account', 'dfn-theme');
        } else {
            $new_items[ $key ] = $value;
        }
    }
    if (! isset($new_items['tessere-fai'])) {
        $new_items['tessere-fai'] = esc_html__('Tessere FAI', 'dfn-theme');
    }
    if ($is_volunteer && ! isset($new_items['riunioni-fai'])) {
        $new_items['riunioni-fai'] = esc_html__('Prossime Riunioni', 'dfn-theme');
    }
    if (isset($new_items['edit-account'])) {
        $new_items['edit-account'] = esc_html__('Account', 'dfn-theme');
    }
    return $new_items;
}
add_filter('woocommerce_account_menu_items', 'dfn_add_fai_cards_to_menu', 15);

/**
 * Inserisce un pulsante di Logout in stile FAI all'inizio della pagina "Account" (edit-account).
 */
function dfn_render_logout_button_before_edit_account(): void
{
    $logout_url = wp_logout_url(wc_get_page_permalink('myaccount'));
    ?>
    <div class="dfn-account-header-card">
        <h2 class="dfn-dashboard-title"><?php esc_html_e('Account', 'dfn-theme'); ?></h2>
        <p class="dfn-dashboard-desc"><?php esc_html_e('Gestisci le tue informazioni personali, la password di accesso e disconnettiti dal tuo profilo.', 'dfn-theme'); ?></p>
    </div>
    <div class="dfn-account-logout-top-bar" style="margin-bottom: 20px;">
        <a href="<?php echo esc_url($logout_url); ?>" class="button dfn-logout-fai-btn" style="background: #e74f30 !important; color: #ffffff !important; display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; width: 100%; border-radius: 12px; font-weight: 700; padding: 14px 18px; font-size: 14px; border: none; box-shadow: 0 4px 12px rgba(231,79,48,0.25);">
            <?php esc_html_e('Disconnettiti / Logout', 'dfn-theme'); ?>
        </a>
    </div>
    <?php
}
add_action('woocommerce_before_edit_account_form', 'dfn_render_logout_button_before_edit_account');

// Esegue il flush automatico one-shot delle regole di riscrittura
add_action('init', 'dfn_fai_cards_flush_rules', 999);
/**
 * Effettua il flush delle regole di rewrite all'attivazione per evitare errori 404.
 */
function dfn_fai_cards_flush_rules(): void
{
    if ('yes' !== get_option('dfn_fai_permalink_flushed_v230')) {
        dfn_fai_cards_endpoint_init();
        flush_rewrite_rules();
        update_option('dfn_fai_permalink_flushed_v230', 'yes');
    }
}

// Rendering del contenuto della sezione "Tessere FAI"
add_action('woocommerce_account_tessere-fai_endpoint', 'dfn_fai_cards_endpoint_content');
/**
 * Renderizza la bacheca in sola lettura delle tessere FAI associate all'utente.
 */
function dfn_fai_cards_endpoint_content(): void
{
    $current_user_id = get_current_user_id();
    if (! $current_user_id) {
        return;
    }

    $current_user = wp_get_current_user();
    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    $notice_message = '';
    $notice_type    = 'success';

    // Gestione invio form aggiunta tessera FAI
    if (isset($_POST['dfn_add_fai_card_nonce']) && wp_verify_nonce($_POST['dfn_add_fai_card_nonce'], 'dfn_add_fai_card_action')) {
        $first_name  = isset($_POST['dfn_fai_first_name']) ? sanitize_text_field($_POST['dfn_fai_first_name']) : '';
        $last_name   = isset($_POST['dfn_fai_last_name']) ? sanitize_text_field($_POST['dfn_fai_last_name']) : '';
        $card_number = isset($_POST['dfn_fai_card_number']) ? sanitize_text_field($_POST['dfn_fai_card_number']) : '';

        if (empty($first_name) || empty($last_name) || empty($card_number)) {
            $notice_message = __('Compila tutti i campi richiesti (Nome, Cognome e Numero Tessera).', 'dfn-theme');
            $notice_type    = 'error';
        } else {
            // Verifica se la tessera è già stata registrata per questo utente o nel sistema
            $existing = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table_fai} WHERE card_number = %s", $card_number)
            );

            if ($existing) {
                if (intval($existing->user_id) === $current_user_id) {
                    $notice_message = __('Questa tessera FAI è già stata registrata sul tuo account.', 'dfn-theme');
                    $notice_type    = 'info';
                } else {
                    $notice_message = __('Questa tessera FAI risulta già presente nei nostri sistemi.', 'dfn-theme');
                    $notice_type    = 'error';
                }
            } else {
                // Inserimento nuova tessera in stato pending (verified = 0)
                $inserted = $wpdb->insert(
                    $table_fai,
                    [
                        'first_name'  => $first_name,
                        'last_name'   => $last_name,
                        'email'       => $current_user->user_email,
                        'card_number' => $card_number,
                        'card_type'   => 'INDIVIDUALE',
                        'verified'    => 0,
                        'user_id'     => $current_user_id,
                        'created_at'  => current_time('mysql'),
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s']
                );

                if ($inserted) {
                    $notice_message = __('Tessera FAI inviata con successo! È ora in attesa di verifica da parte della segreteria.', 'dfn-theme');
                    $notice_type    = 'success';

                    // Invia notifica email alla segreteria FAI
                    if (function_exists('dfn_notify_admin_unverified_fai_card')) {
                        dfn_notify_admin_unverified_fai_card($card_number, $first_name, $last_name, $current_user->user_email);
                    }
                } else {
                    $notice_message = __('Si è verificato un errore durante il salvataggio. Riprova più tardi.', 'dfn-theme');
                    $notice_type    = 'error';
                }
            }
        }
    }

    // Recupera le tessere verificate (verified = 1 da wp_dfn_fai_members)
    $verified_cards = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_fai} 
             WHERE (user_id = %d OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(%s))) AND verified = 1
             ORDER BY card_expiry DESC, created_at DESC",
            $current_user_id,
            $current_user->user_email
        )
    );

    // Recupera le tessere in attesa di verifica (verified = 0)
    $pending_cards = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_fai} WHERE (user_id = %d OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(%s))) AND verified = 0 ORDER BY created_at DESC",
            $current_user_id,
            $current_user->user_email
        )
    );

    $default_first_name = $current_user->first_name ?: '';
    $default_last_name  = $current_user->last_name ?: '';
    ?>
    <div class="dfn-fai-dashboard-section" id="dfn-fai-section">
        <div class="dfn-account-header-card">
            <h2 class="dfn-dashboard-title"><?php esc_html_e('Tessere FAI', 'dfn-theme'); ?></h2>
            <p class="dfn-dashboard-desc"><?php esc_html_e('In questa sezione puoi gestire ed associare le tue tessere FAI. Le tessere verificate dalla segreteria saranno disponibili come suggerimenti rapidi durante la prenotazione degli eventi.', 'dfn-theme'); ?></p>
        </div>

        <?php if (! empty($notice_message)) : ?>
            <div class="dfn-account-notice dfn-notice-<?php echo esc_attr($notice_type); ?>" style="padding: 14px 18px; border-radius: 8px; margin-bottom: 24px; font-weight: 600; font-size: 14px; <?php echo $notice_type === 'success' ? 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;' : ($notice_type === 'info' ? 'background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;' : 'background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;'); ?>">
                <?php echo esc_html($notice_message); ?>
            </div>
        <?php endif; ?>

        <!-- Form Inserimento Nuova Tessera FAI (Riga Unica Compatta) -->
        <div class="dfn-add-fai-card-wrapper" style="background: #ffffff; border: 1px solid #e2e8f0; border-top: 3px solid #e74f30; border-radius: 10px; padding: 16px 20px; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.03);">
            <div style="margin-bottom: 12px;">
                <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #004b23; display: flex; align-items: center; gap: 6px;">
                    <span>🪪</span> <?php esc_html_e('Aggiungi una Tessera FAI', 'dfn-theme'); ?>
                </h3>
            </div>

            <form method="POST" class="dfn-add-fai-card-form" style="display: flex !important; flex-wrap: wrap !important; align-items: flex-end !important; gap: 10px !important; width: 100% !important;">
                <div style="display:none;"><?php wp_nonce_field('dfn_add_fai_card_action', 'dfn_add_fai_card_nonce'); ?></div>

                <div style="flex: 1 1 22%; min-width: 110px;">
                    <label for="dfn_fai_first_name" style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;"><?php esc_html_e('Nome *', 'dfn-theme'); ?></label>
                    <input type="text" name="dfn_fai_first_name" id="dfn_fai_first_name" required value="<?php echo esc_attr($default_first_name); ?>" placeholder="<?php esc_attr_e('Nome', 'dfn-theme'); ?>" style="width: 100%; height: 40px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box; background: #ffffff;">
                </div>

                <div style="flex: 1 1 22%; min-width: 110px;">
                    <label for="dfn_fai_last_name" style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;"><?php esc_html_e('Cognome *', 'dfn-theme'); ?></label>
                    <input type="text" name="dfn_fai_last_name" id="dfn_fai_last_name" required value="<?php echo esc_attr($default_last_name); ?>" placeholder="<?php esc_attr_e('Cognome', 'dfn-theme'); ?>" style="width: 100%; height: 40px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box; background: #ffffff;">
                </div>

                <div style="flex: 1 1 28%; min-width: 130px;">
                    <label for="dfn_fai_card_number" style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;"><?php esc_html_e('N° Tessera FAI *', 'dfn-theme'); ?></label>
                    <input type="text" name="dfn_fai_card_number" id="dfn_fai_card_number" required placeholder="<?php esc_attr_e('Es: 12345678', 'dfn-theme'); ?>" style="width: 100%; height: 40px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box; background: #ffffff;">
                </div>

                <div style="flex: 0 0 auto;">
                    <button type="submit" class="dfn-btn-add-card-submit" style="height: 40px; padding: 0 16px; white-space: nowrap; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box;">
                        <?php esc_html_e('Invia Tessera', 'dfn-theme'); ?>
                    </button>
                </div>

                <!-- GDPR: Consenso privacy obbligatorio -->
                <div style="flex-basis: 100%; width: 100%; margin-top: 4px;">
                    <?php echo dfn_get_privacy_checkbox_html('fai_card', 'tessera'); ?>
                </div>
            </form>
        </div>


        <!-- Sezione 1: Tessere in Attesa di Verifica -->
        <?php if (! empty($pending_cards)) : ?>
            <div style="margin-bottom: 30px;">
                <h3 class="dfn-group-title" style="color: #e74f30 !important;">
                    <span>⏳</span> <?php esc_html_e('Tessere in Attesa di Verifica', 'dfn-theme'); ?> (<?php echo count($pending_cards); ?>)
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
                    <?php foreach ($pending_cards as $p_card) : ?>
                        <div style="background: #fffdf5; border: 1px solid #e74f30; border-radius: 10px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; gap: 10px;">
                            <div>
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                    <span style="font-size: 11px; font-weight: 800; color: #e74f30; text-transform: uppercase; letter-spacing: 0.5px;">Tessera FAI</span>
                                    <span style="font-size: 11px; font-weight: 700; background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 12px;">⏳ In Attesa Staff</span>
                                </div>
                                <h4 style="margin: 0 0 4px 0; font-size: 15px; font-weight: 800; color: #1e293b;"><?php echo esc_html(strtoupper($p_card->first_name . ' ' . $p_card->last_name)); ?></h4>
                                <div style="font-size: 13px; color: #475569; font-weight: 600;">
                                    N° Tessera: <strong style="color: #004b23;"><?php echo esc_html($p_card->card_number); ?></strong>
                                </div>
                            </div>
                            <div style="font-size: 11px; color: #94a3b8; border-top: 1px dashed #fde68a; padding-top: 8px;">
                                Inviata il <?php echo esc_html(date_i18n('d/m/Y H:i', strtotime($p_card->created_at))); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Sezione 2: Tessere Verificate -->
        <h3 class="dfn-group-title">
            <span>✓</span> <?php esc_html_e('Tessere Verificate e Attive', 'dfn-theme'); ?>
        </h3>

        <?php if (! empty($verified_cards)) : ?>
            <div class="dfn-fai-cards-grid">
                <?php foreach ($verified_cards as $card) :
                    // Formattazione numero tessera a gruppi di 4 cifre
                    $formatted_number = trim(chunk_split($card->card_number, 4, ' '));

                    // Tipologia tessera
                    $card_type  = $card->card_type ?: 'INDIVIDUALE';
                    $type_class = strtolower($card_type);

                    // Data scadenza
                    if (empty($card->card_expiry) || '0000-00-00' === $card->card_expiry) {
                        $expiry_text  = __('Illimitata', 'dfn-theme');
                        $expiry_class = 'no-expiry';
                    } else {
                        $expiry_time  = strtotime($card->card_expiry);
                        $expiry_text  = date_i18n('d/m/Y', $expiry_time);
                        $is_expired   = ($expiry_time < time());
                        $expiry_class = $is_expired ? 'expired' : 'active';
                    }
                    ?>
                    <div class="dfn-fai-digital-card dfn-fai-card-type-<?php echo esc_attr($type_class); ?>">
                        <!-- Header with FAI Logo and Verified Badge -->
                        <div class="dfn-fai-card-header">
                            <div class="dfn-fai-logo-group">
                                <img src="https://upload.wikimedia.org/wikipedia/commons/b/b7/FAI_marchio_RGB.jpg" alt="FAI Logo" class="dfn-fai-official-logo" />
                            </div>
                            <span class="dfn-fai-card-badge"><span class="dfn-check-icon">✓</span> <?php esc_html_e('Verificata', 'dfn-theme'); ?></span>
                        </div>

                        <!-- Card Body (Left content & Right QR Code) -->
                        <div class="dfn-fai-card-body">
                            <div class="dfn-fai-card-left">
                                <h3 class="dfn-fai-card-holder-name"><?php echo esc_html(strtoupper($card->first_name . ' ' . $card->last_name)); ?></h3>
                                
                                <div class="dfn-fai-card-details-list">
                                    <div class="dfn-fai-card-detail-item">
                                        <span class="dfn-detail-label"><?php esc_html_e('TIPO ISCRIZIONE:', 'dfn-theme'); ?></span> <span class="dfn-detail-value"><?php echo esc_html(ucfirst(strtolower($card_type))); ?></span>
                                    </div>
                                    <div class="dfn-fai-card-detail-item">
                                        <span class="dfn-detail-label"><?php esc_html_e('TESSERA:', 'dfn-theme'); ?></span> <span class="dfn-detail-value"><?php echo esc_html($card->card_number); ?></span>
                                    </div>
                                    <div class="dfn-fai-card-detail-item">
                                        <span class="dfn-detail-label"><?php esc_html_e('SCADENZA:', 'dfn-theme'); ?></span> <span class="dfn-detail-value <?php echo esc_attr($expiry_class); ?>"><?php echo esc_html($expiry_text); ?></span>
                                    </div>
                                    <div class="dfn-fai-card-detail-item">
                                        <span class="dfn-detail-label"><?php esc_html_e('GESTIONE ISCRITTI:', 'dfn-theme'); ?></span> <span class="dfn-detail-value">02.467615269</span>
                                    </div>
                                </div>
                            </div>

                            <div class="dfn-fai-card-right">
                                <div class="dfn-fai-card-qrcode-box">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&amp;data=<?php echo urlencode($card->card_number); ?>" alt="QR Code Tessera" />
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer Text (5x1000 & Website) -->
                        <div class="dfn-fai-card-footer-info">
                            <div class="dfn-fai-footer-text-line">
                                <?php esc_html_e('Dona il tuo ', 'dfn-theme'); ?><strong>5x1000</strong>: C.F. 80102030154
                            </div>
                            <div class="dfn-fai-footer-text-line">
                                <?php esc_html_e('per scoprire le opportunità ', 'dfn-theme'); ?><a href="https://www.faiperme.it" target="_blank" class="dfn-footer-link-red">www.faiperme.it</a>
                            </div>
                            <div class="dfn-fai-footer-text-line">
                                <a href="https://www.fondoambiente.it" target="_blank" class="dfn-footer-link-green">www.fondoambiente.it</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="dfn-fai-empty-state">
                <div class="dfn-fai-empty-icon">🪪</div>
                <h4><?php esc_html_e('Nessuna tessera FAI verificata', 'dfn-theme'); ?></h4>
                <p><?php esc_html_e('Non risultano ancora tessere FAI verificate associate a questo account.', 'dfn-theme'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// Rendering del contenuto della sezione "Prossime Riunioni" per i Volontari
add_action('woocommerce_account_riunioni-fai_endpoint', 'dfn_volunteer_meetings_endpoint_content');
/**
 * Renderizza la bacheca delle riunioni di delegazione riservata ai volontari.
 */
function dfn_volunteer_meetings_endpoint_content(): void
{
    $current_user_id = get_current_user_id();
    if (! $current_user_id) {
        return;
    }

    if (function_exists('dfn_is_user_volunteer') && ! dfn_is_user_volunteer($current_user_id)) {
        ?>
        <div class="dfn-account-header-card">
            <h2 class="dfn-dashboard-title"><?php esc_html_e('Accesso Riservato', 'dfn-theme'); ?></h2>
            <p class="dfn-dashboard-desc"><?php esc_html_e('Questa sezione è riservata esclusivamente ai volontari attivi della Delegazione FAI.', 'dfn-theme'); ?></p>
        </div>
        <?php
        return;
    }

    $meetings = function_exists('dfn_get_volunteer_meetings') ? dfn_get_volunteer_meetings(true, 50) : [];
    ?>
    <div class="dfn-volunteer-meetings-section" id="dfn-meetings-section">
        <div class="dfn-account-header-card">
            <h2 class="dfn-dashboard-title"><?php esc_html_e('📅 Riunioni di Delegazione', 'dfn-theme'); ?></h2>
            <p class="dfn-dashboard-desc"><?php esc_html_e('Consulta il calendario delle prossime riunioni dei volontari FAI, gli orari, le sedi e gli ordini del giorno programmati.', 'dfn-theme'); ?></p>
        </div>

        <?php if (! empty($meetings)) : ?>
            <div class="dfn-meetings-grid" style="display: flex; flex-direction: column; gap: 16px; margin-top: 20px;">
                <?php foreach ($meetings as $m) : 
                    $m_date = strtotime($m->meeting_date);
                    $day_name = date_i18n('l', $m_date);
                    $day_num  = date_i18n('d', $m_date);
                    $month    = date_i18n('F Y', $m_date);
                ?>
                    <div class="dfn-meeting-card" style="background: #ffffff; border: 1px solid #e2e8f0; border-left: 5px solid #004b23; border-radius: 12px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; gap: 20px; align-items: flex-start; flex-wrap: wrap;">
                        <!-- Date Box Badge -->
                        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 18px; text-align: center; min-width: 90px; flex-shrink: 0;">
                            <span style="font-size: 11px; font-weight: 700; color: #166534; text-transform: uppercase; display: block;"><?php echo esc_html($day_name); ?></span>
                            <span style="font-size: 26px; font-weight: 800; color: #004b23; line-height: 1.1; display: block;"><?php echo esc_html($day_num); ?></span>
                            <span style="font-size: 11px; font-weight: 600; color: #475569; display: block;"><?php echo esc_html($month); ?></span>
                        </div>

                        <!-- Details -->
                        <div style="flex: 1; min-width: 250px;">
                            <h3 style="margin: 0 0 8px 0; font-size: 17px; font-weight: 700; color: #0f172a;">
                                <?php echo esc_html($m->title); ?>
                            </h3>

                            <div style="display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 12px; font-size: 13px; color: #334155;">
                                <div>
                                    <strong style="color: #004b23;">⏰ Orario:</strong> <?php echo esc_html(substr($m->meeting_time_start, 0, 5)); ?>
                                    <?php if ($m->meeting_time_end) echo ' - ' . esc_html(substr($m->meeting_time_end, 0, 5)); ?>
                                </div>
                                <div>
                                    <strong style="color: #004b23;">📍 Sede:</strong> <?php echo esc_html($m->location); ?>
                                </div>
                            </div>

                            <?php if (! empty($m->agenda)) : ?>
                                <div style="background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; padding: 12px 14px; font-size: 13px; color: #334155; line-height: 1.5; margin-bottom: 12px;">
                                    <strong style="color: #1e293b; display: block; margin-bottom: 4px;">📝 Ordine del giorno:</strong>
                                    <?php echo nl2br(esc_html($m->agenda)); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (! empty($m->meeting_link)) : ?>
                                <div style="margin-top: 10px;">
                                    <a href="<?php echo esc_url($m->meeting_link); ?>" target="_blank" class="button" style="background: #004b23; color: #ffffff; border-radius: 8px; font-weight: 700; padding: 6px 16px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none;">
                                        <span>🔗 Partecipa alla Riunione Online</span>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="dfn-fai-empty-state" style="background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 40px 20px; text-align: center; margin-top: 20px;">
                <div style="font-size: 36px; margin-bottom: 10px;">📅</div>
                <h4 style="margin: 0 0 6px 0; font-size: 16px; font-weight: 700; color: #1e293b;"><?php esc_html_e('Nessuna riunione programmata al momento', 'dfn-theme'); ?></h4>
                <p style="margin: 0; font-size: 13.5px; color: #64748b;"><?php esc_html_e('Le prossime convocazioni e date di delegazione verranno pubblicate qui.', 'dfn-theme'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// Rimuove il testo introduttivo standard di WooCommerce ("Ciao Alex... Dalla bacheca del tuo account puoi...")
remove_action('woocommerce_account_dashboard', 'woocommerce_account_welcome_events', 10);
remove_action('woocommerce_account_dashboard', 'woocommerce_account_dashboard', 10);

// Inserisce la nostra bacheca FAI personalizzata
add_action('woocommerce_account_dashboard', 'dfn_custom_myaccount_dashboard_content', 5);

// Sostituisce il rendering standard degli ordini WooCommerce con la nostra visualizzazione avanzata prenotazioni
remove_action('woocommerce_account_orders_endpoint', 'woocommerce_account_orders');
add_action('woocommerce_account_orders_endpoint', 'dfn_custom_myaccount_bookings_content');

/**
 * Renderizza la bacheca personalizzata delle prenotazioni suddivisa tra eventi prossimi e passati.
 */
function dfn_custom_myaccount_bookings_content(): void
{
    $current_user_id = get_current_user_id();
    if (! $current_user_id) {
        return;
    }

    $current_user = wp_get_current_user();

    // Raccoglie tutte le possibili email associate all'utente per garantire massima precisione
    $emails = [ $current_user->user_email ];
    $billing_email = get_user_meta($current_user_id, 'billing_email', true);
    if ($billing_email && ! in_array($billing_email, $emails, true)) {
        $emails[] = $billing_email;
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $table_events = $wpdb->prefix . 'dfn_events';

    // Recupera tutti gli ordini del cliente loggato per garantire massima affidabilità
    $customer_orders = wc_get_orders([
        'customer' => $current_user_id,
        'limit'    => -1,
        'return'   => 'ids',
    ]);

    // Costruisce la query SQL con i segnaposto dinamici per le email
    $email_placeholders = implode(',', array_fill(0, count($emails), '%s'));

    if (! empty($customer_orders)) {
        $ids_in = implode(',', array_map('intval', $customer_orders));
        $sql = "SELECT b.*, e.event_date_start, e.event_time_start, e.location 
                FROM {$table_bookings} b
                JOIN {$table_events} e ON b.event_id = e.id
                WHERE b.customer_email IN ({$email_placeholders}) OR b.order_id IN ({$ids_in})
                ORDER BY e.event_date_start ASC, e.event_time_start ASC";

        $prepare_args = array_merge($emails);
        $query = $wpdb->prepare($sql, $prepare_args);
    } else {
        $sql = "SELECT b.*, e.event_date_start, e.event_time_start, e.location 
                FROM {$table_bookings} b
                JOIN {$table_events} e ON b.event_id = e.id
                WHERE b.customer_email IN ({$email_placeholders})
                ORDER BY e.event_date_start ASC, e.event_time_start ASC";

        $prepare_args = $emails;
        $query = $wpdb->prepare($sql, $prepare_args);
    }

    $bookings = $wpdb->get_results($query);

    // Raggruppamento per event_id
    $grouped_bookings = [];
    if (is_array($bookings)) {
        foreach ($bookings as $b) {
            $grouped_bookings[ $b->event_id ][] = $b;
        }
    }

    // Raggruppamento per data/ora rispetto ad ora locale
    $current_time = current_time('timestamp');
    $upcoming_groups = [];
    $past_groups = [];

    foreach ($grouped_bookings as $event_id => $group_bookings) {
        $first_booking = $group_bookings[0];
        $event_datetime = strtotime($first_booking->event_date_start . ' ' . $first_booking->event_time_start);
        if ($event_datetime >= $current_time) {
            $upcoming_groups[ $event_id ] = $group_bookings;
        } else {
            $past_groups[ $event_id ] = $group_bookings;
        }
    }

    // Invertiamo l'ordine dei passati per mostrare i più recenti per primi
    $past_groups = array_reverse($past_groups, true);
    ?>
    <div class="dfn-my-bookings-section" id="dfn-my-bookings-section">
        <div class="dfn-bookings-header-card">
            <h2 class="dfn-dashboard-title"><?php esc_html_e('Le mie prenotazioni', 'dfn-theme'); ?></h2>
            <p class="dfn-dashboard-desc"><?php esc_html_e('Qui puoi consultare lo storico di tutte le tue prenotazioni suddiviso tra eventi in arrivo e visite già effettuate.', 'dfn-theme'); ?></p>
        </div>

        <!-- SEZIONE EVENTI IN ARRIVO -->
        <div class="dfn-bookings-group dfn-bookings-group-upcoming">
            <h3 class="dfn-group-title">📅 <?php esc_html_e('Prossimi Eventi', 'dfn-theme'); ?></h3>
            <?php if (! empty($upcoming_groups)) : ?>
                <div class="dfn-bookings-list">
                    <?php foreach ($upcoming_groups as $event_id => $group_bookings) :
                        $first_booking = $group_bookings[0];

                        // Calcolo stato complessivo del gruppo
                        $group_status = 'cancelled';
                        foreach ($group_bookings as $b) {
                            $order = wc_get_order($b->order_id);
                            $order_status = $order ? $order->get_status() : '';
                            $is_cancelled = ($b->status === 'cancelled' || in_array($order_status, [ 'cancelled', 'refunded', 'failed' ], true));

                            if (! $is_cancelled) {
                                $payment_method = $order ? $order->get_payment_method() : '';
                                if ($order && $order->has_status('pending')) {
                                    if ($group_status !== 'confirmed') {
                                        $group_status = 'pending';
                                    }
                                } else {
                                    $group_status = 'confirmed';
                                }
                            }
                        }

                        // Recupero del record dell'evento e dei dettagli del prodotto collegato
                        $event = dfn_db_get_event($event_id);
                        $product_id = $event ? $event->product_id : 0;
                        $event_title = $product_id ? get_the_title($product_id) : __('Evento FAI', 'dfn-theme');
                        $image_url = $product_id ? get_the_post_thumbnail_url($product_id, 'medium') : '';

                        $date_formatted = date_i18n('d F Y', strtotime($first_booking->event_date_start));
                        ?>
                        <details class="dfn-booking-accordion <?php echo ('cancelled' === $group_status) ? 'dfn-booking-cancelled' : ''; ?>">
                            <summary class="dfn-booking-summary">
                                <div class="dfn-booking-summary-header">
                                    <div class="dfn-booking-img-wrapper">
                                        <?php if ($image_url) : ?>
                                            <img src="<?php echo esc_url($image_url); ?>" class="dfn-booking-event-img" alt="<?php echo esc_attr($event_title); ?>" />
                                        <?php else : ?>
                                            <div class="dfn-booking-event-img-placeholder">🌳</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="dfn-booking-summary-text">
                                        <h4 class="dfn-booking-summary-title"><?php echo esc_html($event_title); ?></h4>
                                        <div class="dfn-booking-summary-meta">
                                            <span class="dfn-meta-loc">📍 <?php echo esc_html($first_booking->location); ?></span>
                                            <span class="dfn-meta-date">🗓️ <?php echo esc_html($date_formatted); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="dfn-booking-summary-right">
                                        <?php if ('cancelled' === $group_status) : ?>
                                            <span class="dfn-booking-status-badge dfn-status-cancelled"><?php esc_html_e('Annullata', 'dfn-theme'); ?></span>
                                        <?php elseif ('pending' === $group_status) : ?>
                                            <span class="dfn-booking-status-badge dfn-status-pending"><?php esc_html_e('In attesa', 'dfn-theme'); ?></span>
                                        <?php else : ?>
                                            <span class="dfn-booking-status-badge dfn-status-confirmed"><?php esc_html_e('Confermata', 'dfn-theme'); ?></span>
                                        <?php endif; ?>
                                        <span class="dfn-accordion-arrow">▼</span>
                                    </div>
                                </div>
                            </summary>
                            
                            <div class="dfn-booking-details-content">
                                <h4 class="dfn-booking-details-title">
                                    <?php
                                    printf(
                                        esc_html(_n('Hai %d prenotazione per questo evento:', 'Hai %d prenotazioni per questo evento:', count($group_bookings), 'dfn-theme')),
                                        count($group_bookings),
                                    );
                        ?>
                                </h4>
                                <table class="dfn-bookings-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Ordine', 'dfn-theme'); ?></th>
                                            <th><?php esc_html_e('Orario', 'dfn-theme'); ?></th>
                                            <th><?php esc_html_e('Posti', 'dfn-theme'); ?></th>
                                            <th><?php esc_html_e('Pagamento', 'dfn-theme'); ?></th>
                                            <th class="dfn-table-actions-head"><?php esc_html_e('Azioni', 'dfn-theme'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group_bookings as $b) :
                                            $order = wc_get_order($b->order_id);
                                            $order_status = $order ? $order->get_status() : '';
                                            $is_cancelled = ($b->status === 'cancelled' || in_array($order_status, [ 'cancelled', 'refunded', 'failed' ], true));
                                            $time_formatted = date('H:i', strtotime($b->event_time_start));
                                            ?>
                                            <tr class="<?php echo $is_cancelled ? 'dfn-row-cancelled' : ''; ?>">
                                                <td data-label="<?php esc_attr_e('Ordine', 'dfn-theme'); ?>">
                                                    <?php
                                                    if ($order) {
                                                        $hub_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
                                                        $hub_url   = home_url('/?dfn_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token);
                                                        ?>
                                                        <a href="<?php echo esc_url($hub_url); ?>" class="dfn-table-order-id-link" title="<?php esc_attr_e('Vedi prenotazione', 'dfn-theme'); ?>">
                                                            #<?php echo esc_html($b->order_id); ?>
                                                        </a>
                                                    <?php } else { ?>
                                                        <span class="dfn-table-order-id-link">#<?php echo esc_html($b->order_id); ?></span>
                                                    <?php } ?>
                                                </td>
                                                <td class="dfn-table-time" data-label="<?php esc_attr_e('Orario', 'dfn-theme'); ?>">
                                                    <strong><?php echo esc_html($time_formatted); ?></strong>
                                                </td>
                                                <td data-label="<?php esc_attr_e('Posti', 'dfn-theme'); ?>">
                                                    <div class="dfn-table-seats">
                                                        <strong><?php
                                                            printf(
                                                                _n('%d Persona', '%d Persone', $b->total_persons, 'dfn-theme'),
                                                                $b->total_persons,
                                                            );
                                            ?></strong>
                                                    </div>
                                                </td>
                                                <td data-label="<?php esc_attr_e('Contributo', 'dfn-theme'); ?>">
                                                    <div class="dfn-table-payment">
                                                        <?php
                                            $payment_method = $order ? $order->get_payment_method() : '';
                                            if ($payment_method === 'dfn_in_loco' && $b->amount_due > 0) {
                                                echo '<span class="dfn-payment-due"><strong>' . number_format($b->amount_due, 2, ',', '.') . ' €</strong> ' . esc_html__('(in loco)', 'dfn-theme') . '</span>';
                                            } else {
                                                echo '<span class="dfn-payment-paid">' . esc_html__('Saldato online', 'dfn-theme') . '</span>';
                                            }
                                            ?>
                                                    </div>
                                                </td>
                                                <td class="dfn-table-actions" data-label="<?php esc_attr_e('Azioni', 'dfn-theme'); ?>">
                                                    <div class="dfn-table-actions-container">
                                                        <?php if ($order) : ?>
                                                            <?php
                                                            $hub_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
                                                            $hub_url   = home_url('/?dfn_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token);
                                                            ?>
                                                            <a href="<?php echo esc_url($hub_url); ?>" class="button dfn-action-ticket" title="<?php esc_attr_e('Vedi Biglietto / QR Code', 'dfn-theme'); ?>">
                                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                                                                <span><?php esc_html_e('Vedi Biglietto', 'dfn-theme'); ?></span>
                                                            </a>
                                                        <?php endif; ?>

                                                        <?php if (! $is_cancelled && $order) : ?>
                                                            <?php
                                                            $modify_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_modify', wp_salt('nonce'));
                                                            $modify_url   = home_url('/?dfn_modify_booking=1&order_id=' . $order->get_id() . '&token=' . $modify_token);
                                                            ?>
                                                            <a href="<?php echo esc_url($modify_url); ?>" class="button dfn-action-modify" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-token="<?php echo esc_attr($modify_token); ?>" title="<?php esc_attr_e('Modifica prenotazione', 'dfn-theme'); ?>">
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                                            </a>
                                                            
                                                            <?php
                                                            $cancel_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
                                                            $cancel_url   = home_url('/?dfn_cancel_booking=1&order_id=' . $order->get_id() . '&token=' . $cancel_token);
                                                            ?>
                                                            <a href="<?php echo esc_url($cancel_url); ?>" class="button dfn-action-cancel dfn-btn-cancel-booking" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-token="<?php echo esc_attr($cancel_token); ?>" data-event-title="<?php echo esc_attr($event_title); ?>" data-booking-date="<?php echo esc_attr($date_formatted); ?>" title="<?php esc_attr_e('Annulla prenotazione', 'dfn-theme'); ?>">
                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                            </a>
                                                        <?php elseif ($is_cancelled) : ?>
                                                            <span class="dfn-booking-status-badge dfn-status-cancelled"><?php esc_html_e('Annullata', 'dfn-theme'); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="dfn-bookings-empty">
                    <p><?php esc_html_e('Non hai prenotazioni imminenti per i prossimi eventi.', 'dfn-theme'); ?></p>
                    <a href="<?php echo esc_url(home_url()); ?>" class="button dfn-booking-browse-btn"><?php esc_html_e('Esplora il calendario eventi', 'dfn-theme'); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <!-- SEZIONE EVENTI PASSATI -->
        <div class="dfn-bookings-group dfn-bookings-group-past">
            <h3 class="dfn-group-title">✓ <?php esc_html_e('Eventi Passati', 'dfn-theme'); ?></h3>
            <?php if (! empty($past_groups)) : ?>
                <div class="dfn-bookings-list">
                    <?php foreach ($past_groups as $event_id => $group_bookings) :
                        $first_booking = $group_bookings[0];

                        // Calcolo stato complessivo del gruppo (solo per visualizzazione badge)
                        $group_status = 'cancelled';
                        foreach ($group_bookings as $b) {
                            $order = wc_get_order($b->order_id);
                            $order_status = $order ? $order->get_status() : '';
                            $is_cancelled = ($b->status === 'cancelled' || in_array($order_status, [ 'cancelled', 'refunded', 'failed' ], true));
                            if (! $is_cancelled) {
                                $group_status = 'confirmed';
                            }
                        }

                        // Recupero del record dell'evento e dei dettagli del prodotto collegato
                        $event = dfn_db_get_event($event_id);
                        $product_id = $event ? $event->product_id : 0;
                        $event_title = $product_id ? get_the_title($product_id) : __('Evento FAI', 'dfn-theme');
                        $image_url = $product_id ? get_the_post_thumbnail_url($product_id, 'medium') : '';

                        $date_formatted = date_i18n('d F Y', strtotime($first_booking->event_date_start));
                        ?>
                        <details class="dfn-booking-accordion dfn-booking-past-card <?php echo ('cancelled' === $group_status) ? 'dfn-booking-cancelled' : ''; ?>">
                            <summary class="dfn-booking-summary">
                                <div class="dfn-booking-summary-header">
                                    <div class="dfn-booking-img-wrapper">
                                        <?php if ($image_url) : ?>
                                            <img src="<?php echo esc_url($image_url); ?>" class="dfn-booking-event-img" alt="<?php echo esc_attr($event_title); ?>" />
                                        <?php else : ?>
                                            <div class="dfn-booking-event-img-placeholder">🌳</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="dfn-booking-summary-text">
                                        <h4 class="dfn-booking-summary-title"><?php echo esc_html($event_title); ?></h4>
                                        <div class="dfn-booking-summary-meta">
                                            <span class="dfn-meta-loc">📍 <?php echo esc_html($first_booking->location); ?></span>
                                            <span class="dfn-meta-date">🗓️ <?php echo esc_html($date_formatted); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="dfn-booking-summary-right">
                                        <?php if ('cancelled' === $group_status) : ?>
                                            <span class="dfn-booking-status-badge dfn-status-cancelled"><?php esc_html_e('Annullata', 'dfn-theme'); ?></span>
                                        <?php else : ?>
                                            <span class="dfn-booking-status-badge dfn-status-past"><?php esc_html_e('Conclusa', 'dfn-theme'); ?></span>
                                        <?php endif; ?>
                                        <span class="dfn-accordion-arrow">▼</span>
                                    </div>
                                </div>
                            </summary>
                            
                            <div class="dfn-booking-details-content">
                                <h4 class="dfn-booking-details-title">
                                    <?php
                                    printf(
                                        esc_html(_n('Hai %d prenotazione registrata per questo evento:', 'Hai %d prenotazioni registrate per questo evento:', count($group_bookings), 'dfn-theme')),
                                        count($group_bookings),
                                    );
                        ?>
                                </h4>
                                <table class="dfn-bookings-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Ordine', 'dfn-theme'); ?></th>
                                            <th><?php esc_html_e('Orario', 'dfn-theme'); ?></th>
                                            <th><?php esc_html_e('Posti', 'dfn-theme'); ?></th>
                                            <th><?php esc_html_e('Pagamento', 'dfn-theme'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($group_bookings as $b) :
                                            $order = wc_get_order($b->order_id);
                                            $order_status = $order ? $order->get_status() : '';
                                            $is_cancelled = ($b->status === 'cancelled' || in_array($order_status, [ 'cancelled', 'refunded', 'failed' ], true));
                                            $time_formatted = date('H:i', strtotime($b->event_time_start));
                                            ?>
                                            <tr class="<?php echo $is_cancelled ? 'dfn-row-cancelled' : ''; ?>">
                                                <td data-label="<?php esc_attr_e('Ordine', 'dfn-theme'); ?>">
                                                    <span class="dfn-table-order-id">#<?php echo esc_html($b->order_id); ?></span>
                                                    <div class="dfn-table-status">
                                                        <?php if ($is_cancelled) : ?>
                                                            <span class="dfn-booking-status-badge dfn-status-cancelled"><?php esc_html_e('Annullata', 'dfn-theme'); ?></span>
                                                        <?php else : ?>
                                                            <span class="dfn-booking-status-badge dfn-status-past"><?php esc_html_e('Conclusa', 'dfn-theme'); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="dfn-table-time" data-label="<?php esc_attr_e('Orario', 'dfn-theme'); ?>">
                                                    <strong><?php echo esc_html($time_formatted); ?></strong>
                                                </td>
                                                <td data-label="<?php esc_attr_e('Posti', 'dfn-theme'); ?>">
                                                    <div class="dfn-table-seats">
                                                        <strong><?php
                                                            printf(
                                                                _n('%d Persona', '%d Persone', $b->total_persons, 'dfn-theme'),
                                                                $b->total_persons,
                                                            );
                                            ?></strong>
                                                    </div>
                                                </td>
                                                <td data-label="<?php esc_attr_e('Contributo', 'dfn-theme'); ?>">
                                                    <div class="dfn-table-payment">
                                                        <?php
                                            $payment_method = $order ? $order->get_payment_method() : '';
                                            if ($payment_method === 'dfn_in_loco' && $b->amount_due > 0) {
                                                echo '<span class="dfn-payment-due"><strong>' . number_format($b->amount_due, 2, ',', '.') . ' €</strong> ' . esc_html__('(in loco)', 'dfn-theme') . '</span>';
                                            } else {
                                                echo '<span class="dfn-payment-paid">' . esc_html__('Saldato online', 'dfn-theme') . '</span>';
                                            }
                                            ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="dfn-bookings-empty-silent">
                    <p><?php esc_html_e('Nessuna prenotazione passata registrata.', 'dfn-theme'); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Modal 1: Modifica Partecipanti Visitatore -->
        <div id="dfn-modal-visitor-modify" class="dfn-myaccount-modal" style="display:none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
            <div class="dfn-myaccount-modal-content" style="background-color: #fff; margin: auto; padding: 24px; border-radius: 12px; max-width: 500px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.1); position: relative; animation: dfnFadeIn 0.3s ease;">
                <div class="dfn-myaccount-modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
                    <h3 style="margin:0; color:#004b23; font-size:18px; font-weight:700;">✏️ Modifica Partecipanti</h3>
                    <span class="dfn-myaccount-modal-close" onclick="closeVisitorModifyModal()" style="font-size:24px; font-weight:bold; color:#94a3b8; cursor:pointer; line-height:1;">&times;</span>
                </div>
                <div class="dfn-myaccount-modal-body">
                    <div id="dfn-visitor-modify-loading" style="text-align:center; padding:30px; font-weight:bold; color:#64748b;">Caricamento in corso...</div>
                    <div id="dfn-visitor-modify-form-container" style="display:none;"></div>
                </div>
            </div>
        </div>

        <!-- Modal 2: Annullamento Prenotazione Visitatore -->
        <div id="dfn-modal-visitor-cancel" class="dfn-myaccount-modal" style="display:none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
            <div class="dfn-myaccount-modal-content" style="background-color: #fff; margin: auto; padding: 24px; border-radius: 12px; max-width: 450px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.1); position: relative; animation: dfnFadeIn 0.3s ease;">
                <div class="dfn-myaccount-modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
                    <h3 style="margin:0; color:#dc2626; font-size:18px; font-weight:700;">⚠️ Annulla Prenotazione</h3>
                    <span class="dfn-myaccount-modal-close" onclick="closeVisitorCancelModal()" style="font-size:24px; font-weight:bold; color:#94a3b8; cursor:pointer; line-height:1;">&times;</span>
                </div>
                <div class="dfn-myaccount-modal-body" style="text-align:center; padding: 12px 0 0 0;">
                    <p style="font-size:15px; color:#475569; line-height:1.6; margin-bottom: 24px;" id="dfn-visitor-cancel-text"></p>
                    <div style="display:flex; gap:12px; justify-content:center;">
                        <button type="button" class="button" style="background:#dc2626; color:#fff; border-color:#dc2626; border-radius:20px; font-weight:bold; padding:8px 20px;" id="dfn-btn-confirm-cancel">Sì, annulla</button>
                        <button type="button" class="button" style="background:#64748b; color:#fff; border-color:#64748b; border-radius:20px; font-weight:bold; padding:8px 20px;" onclick="closeVisitorCancelModal()">No, mantieni</button>
                    </div>
                </div>
            </div>
        </div>

        <style>
            @keyframes dfnFadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .btn-spin-minus, .btn-spin-plus {
                background: #e2e8f0;
                border: none;
                width: 30px;
                height: 30px;
                font-size: 16px;
                font-weight: bold;
                color: #334155;
                border-radius: 6px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background 0.15s;
            }
            .btn-spin-minus:hover, .btn-spin-plus:hover {
                background: #cbd5e1;
            }
            .dfn-myaccount-modal {
                display: none;
            }
            .dfn-myaccount-modal.active {
                display: flex !important;
            }
        </style>



    </div>
    <?php
}

/**
 * AJAX handler per recuperare i dettagli di modifica prenotazione.
 */
function dfn_ajax_visitor_get_modify_details(): void
{
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $token    = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_send_json_error(__('Ordine non trovato.', 'dfn-theme'));
    }

    $expected_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_modify', wp_salt('nonce'));
    if (! hash_equals($expected_token, $token)) {
        wp_send_json_error(__('Token di sicurezza non valido.', 'dfn-theme'));
    }

    if ($order->has_status([ 'cancelled', 'refunded', 'failed' ])) {
        wp_send_json_error(__('Questa prenotazione non è più valida e non può essere modificata.', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id
    ));

    if (! $booking) {
        wp_send_json_error(__('Prenotazione non trovata.', 'dfn-theme'));
    }

    if (! function_exists('dfn_db_get_event')) {
        require_once get_template_directory() . '/inc/core/dfn-database.php';
    }

    $event = dfn_db_get_event($booking->event_id);
    if (! $event) {
        wp_send_json_error(__('Dettagli evento non trovati.', 'dfn-theme'));
    }

    $event_title = get_the_title($event->product_id) ?: __('Evento FAI', 'dfn-theme');

    ob_start();
    ?>
    <form id="dfn-visitor-modify-modal-form" method="POST">
        <input type="hidden" name="action" value="dfn_visitor_submit_modify">
        <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
        <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
        
        <div style="margin-bottom: 20px; font-family: 'Outfit', sans-serif;">
            <div style="font-size: 15px; font-weight: bold; color: #1e293b; margin-bottom: 4px;"><?php echo esc_html($event_title); ?></div>
            <div style="font-size: 13px; color: #64748b;">
                <?php
                if (! empty($booking->event_date_start)) {
                    echo '🗓️ ' . date_i18n('d F Y', strtotime($booking->event_date_start));
                }
                ?>
            </div>
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 24px; font-family: 'Outfit', sans-serif;">
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div>
                    <div style="font-weight: 700; color: #1e293b; font-size: 14px;">Biglietti Standard</div>
                    <div style="font-size: 12px; color: #64748b;">Max attuale: <?php echo absint($booking->persons_standard); ?></div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" class="btn-spin-minus" onclick="decrementQty('qty_standard')">-</button>
                    <input type="number" id="qty_standard" name="qty_standard" value="<?php echo absint($booking->persons_standard); ?>" min="0" max="<?php echo absint($booking->persons_standard); ?>" readonly style="width: 55px; text-align: center; font-size: 15px; font-weight: 700; color: #1e293b; border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 0; background: #fff;">
                    <button type="button" class="btn-spin-plus" onclick="incrementQty('qty_standard', <?php echo absint($booking->persons_standard); ?>)">+</button>
                </div>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 12px 16px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div>
                    <div style="font-weight: 700; color: #1e293b; font-size: 14px;">Biglietti Soci FAI</div>
                    <div style="font-size: 12px; color: #64748b;">Max attuale: <?php echo absint($booking->persons_fai); ?></div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button type="button" class="btn-spin-minus" onclick="decrementQty('qty_fai')">-</button>
                    <input type="number" id="qty_fai" name="qty_fai" value="<?php echo absint($booking->persons_fai); ?>" min="0" max="<?php echo absint($booking->persons_fai); ?>" readonly style="width: 55px; text-align: center; font-size: 15px; font-weight: 700; color: #1e293b; border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 0; background: #fff;">
                    <button type="button" class="btn-spin-plus" onclick="incrementQty('qty_fai', <?php echo absint($booking->persons_fai); ?>)">+</button>
                </div>
            </div>
        </div>

        <div style="background: #fffbeb; border: 1px solid #fef3c7; color: #b45309; padding: 12px 16px; border-radius: 8px; font-size: 13px; line-height: 1.5; margin-bottom: 24px; font-family: 'Outfit', sans-serif;">
            💡 <strong>Nota:</strong> Puoi solo ridurre il numero di partecipanti. Per aggiungere nuovi partecipanti è necessario effettuare una prenotazione aggiuntiva.
        </div>

        <div id="dfn-visitor-modify-modal-error" style="color: #dc2626; background: #fef2f2; border: 1px solid #fee2e2; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; margin-bottom: 20px; display: none; font-family: 'Outfit', sans-serif;"></div>

        <div style="display: flex; gap: 12px; justify-content: flex-end; font-family: 'Outfit', sans-serif;">
            <button type="button" class="button" onclick="closeVisitorModifyModal()" style="background: #64748b; color: #fff; border-color: #64748b; border-radius: 20px; font-weight: bold; padding: 8px 20px;"><?php esc_html_e('Chiudi', 'dfn-theme'); ?></button>
            <button type="submit" class="button" style="background: #004b23; color: #fff; border-color: #004b23; border-radius: 20px; font-weight: bold; padding: 8px 20px;"><?php esc_html_e('Salva Modifiche', 'dfn-theme'); ?></button>
        </div>
    </form>
    <?php
    $html = ob_get_clean();
    wp_send_json_success([ 'html' => $html ]);
}

/**
 * AJAX handler per salvare le modifiche prenotazione da parte del visitatore.
 */
function dfn_ajax_visitor_submit_modify(): void
{
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $token    = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    $qty_standard = isset($_POST['qty_standard']) ? intval($_POST['qty_standard']) : 0;
    $qty_fai      = isset($_POST['qty_fai']) ? intval($_POST['qty_fai']) : 0;

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_send_json_error(__('Ordine non trovato.', 'dfn-theme'));
    }

    $expected_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_modify', wp_salt('nonce'));
    if (! hash_equals($expected_token, $token)) {
        wp_send_json_error(__('Token di sicurezza non valido.', 'dfn-theme'));
    }

    if ($order->has_status([ 'cancelled', 'refunded', 'failed' ])) {
        wp_send_json_error(__('Questa prenotazione non è più valida e non può essere modificata.', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id
    ));

    if (! $booking) {
        wp_send_json_error(__('Prenotazione non trovata.', 'dfn-theme'));
    }

    if ($qty_standard < 0 || $qty_fai < 0) {
        wp_send_json_error(__('Quantità non valide.', 'dfn-theme'));
    }

    if ($qty_standard > intval($booking->persons_standard) || $qty_fai > intval($booking->persons_fai)) {
        wp_send_json_error(__('Non è consentito aumentare il numero di partecipanti. Puoi solo ridurre o mantenere le quantità.', 'dfn-theme'));
    }

    $new_total_qty = $qty_standard + $qty_fai;
    if ($new_total_qty < 1) {
        wp_send_json_error(__('La prenotazione deve contenere almeno 1 partecipante. Per annullarla usa la voce "Annulla".', 'dfn-theme'));
    }

    // Esegui la modifica
    if (function_exists('dfn_process_booking_modification')) {
        dfn_process_booking_modification($booking, $order, $qty_standard, $qty_fai);
    }

    // Invia email di notifica modifica
    if (function_exists('dfn_send_booking_modification_notifications')) {
        dfn_send_booking_modification_notifications($booking->id);
    }

    wp_send_json_success(__('Prenotazione modificata con successo.', 'dfn-theme'));
}

/**
 * AJAX handler per annullare la prenotazione da parte del visitatore.
 */
function dfn_ajax_visitor_submit_cancel(): void
{
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $token    = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_send_json_error(__('Ordine non trovato.', 'dfn-theme'));
    }

    $expected_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
    if (! hash_equals($expected_token, $token)) {
        wp_send_json_error(__('Token di sicurezza non valido.', 'dfn-theme'));
    }

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_bookings} WHERE order_id = %d LIMIT 1",
        $order_id
    ));

    if (! $booking) {
        wp_send_json_error(__('Prenotazione non trovata.', 'dfn-theme'));
    }

    if (function_exists('dfn_cancel_booking_by_id')) {
        dfn_cancel_booking_by_id($booking->id, __('Prenotazione annullata autonomamente dal visitatore tramite area riservata.', 'dfn-theme'));
        wp_send_json_success(__('Prenotazione annullata con successo.', 'dfn-theme'));
    } else {
        wp_send_json_error(__('Errore di sistema nell\'annullamento.', 'dfn-theme'));
    }
}

/**
 * Renderizza la Bacheca (Dashboard) personalizzata dell'area riservata FAI.
 */
function dfn_custom_myaccount_dashboard_content(): void
{
    $current_user_id = get_current_user_id();
    if (! $current_user_id) {
        return;
    }

    $current_user = wp_get_current_user();
    $display_name = $current_user->first_name ?: $current_user->display_name;

    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $table_events   = $wpdb->prefix . 'dfn_events';
    $table_fai      = $wpdb->prefix . 'dfn_fai_members';

    // Email associate
    $emails = [ $current_user->user_email ];
    $billing_email = get_user_meta($current_user_id, 'billing_email', true);
    if ($billing_email && ! in_array($billing_email, $emails, true)) {
        $emails[] = $billing_email;
    }

    $customer_orders = wc_get_orders([
        'customer' => $current_user_id,
        'limit'    => -1,
        'return'   => 'ids',
    ]);

    $email_placeholders = implode(',', array_fill(0, count($emails), '%s'));

    if (! empty($customer_orders)) {
        $ids_in = implode(',', array_map('intval', $customer_orders));
        $sql = "SELECT b.*, e.event_date_start, e.event_time_start, e.location, e.product_id 
                FROM {$table_bookings} b
                JOIN {$table_events} e ON b.event_id = e.id
                WHERE (b.customer_email IN ({$email_placeholders}) OR b.order_id IN ({$ids_in}))
                ORDER BY e.event_date_start ASC, e.event_time_start ASC";
        $prepare_args = $emails;
        $query = $wpdb->prepare($sql, $prepare_args);
    } else {
        $sql = "SELECT b.*, e.event_date_start, e.event_time_start, e.location, e.product_id 
                FROM {$table_bookings} b
                JOIN {$table_events} e ON b.event_id = e.id
                WHERE b.customer_email IN ({$email_placeholders})
                ORDER BY e.event_date_start ASC, e.event_time_start ASC";
        $query = $wpdb->prepare($sql, $emails);
    }

    $all_bookings = $wpdb->get_results($query);

    // Filtra prossimi vs passati vs effettivamente svolti (checkin)
    $current_time     = current_time('timestamp');
    $upcoming_list    = [];
    $visited_list     = [];
    $confirmed_count  = 0;

    if (is_array($all_bookings)) {
        foreach ($all_bookings as $b) {
            $order = wc_get_order($b->order_id);
            $order_status = $order ? $order->get_status() : '';
            $is_cancelled = ($b->status === 'cancelled' || in_array($order_status, ['cancelled', 'refunded', 'failed'], true));

            if (! $is_cancelled) {
                $confirmed_count++;
                $event_datetime = strtotime($b->event_date_start . ' ' . $b->event_time_start);
                
                if ($event_datetime >= $current_time) {
                    $upcoming_list[] = $b;
                }
                if (! empty($b->checked_in_at)) {
                    $visited_list[] = $b;
                }
            }
        }
    }

    // Tessere FAI verificate
    $verified_cards_count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_fai} WHERE (user_id = %d OR (email IS NOT NULL AND email != '' AND LOWER(email) = LOWER(%s))) AND verified = 1",
            $current_user_id,
            $current_user->user_email
        )
    );

    // Prossimi eventi a calendario (pubblici in arrivo)
    $upcoming_catalog_events = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_events} WHERE (status = 'published' OR status = 'publish' OR status IS NULL OR status = '') AND event_date_start >= %s ORDER BY event_date_start ASC LIMIT 3",
            current_time('Y-m-d')
        )
    );

    $next_booking = ! empty($upcoming_list) ? $upcoming_list[0] : null;
    ?>
    <div class="dfn-dashboard-hub" style="display: flex; flex-direction: column; gap: 12px; font-family: 'Outfit', sans-serif;">
        
        <!-- 1. Hero Saluto & Contatori Vertically Stacked (1 col, 3 rows) -->
        <div style="background: linear-gradient(135deg, #004b23 0%, #006b35 100%); color: #ffffff; border-radius: 16px; padding: 18px 16px 14px 16px; box-shadow: 0 10px 25px rgba(0,75,35,0.15); display: flex; flex-direction: column; gap: 12px;">
            <div>
                <h2 style="color: #ffffff; margin: 0 0 4px 0; font-size: 20px; font-weight: 800;">
                    <?php printf(esc_html__('Benvenuto, %s! 👋', 'dfn-theme'), esc_html($display_name)); ?>
                </h2>
                <p style="margin: 0; color: rgba(255,255,255,0.85); font-size: 13px;">
                    <?php esc_html_e('Riepilogo delle tue prenotazioni ed esperienze con FAI Novara', 'dfn-theme'); ?>
                </p>
            </div>
            
            <div class="dfn-hero-stats" style="display: flex; flex-direction: column; gap: 8px; width: 100%;">
                <div style="background: rgba(255,255,255,0.18); padding: 10px 16px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">
                    <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.95; font-weight: 700; text-align: left;"><?php esc_html_e('Prenotazioni effettuate', 'dfn-theme'); ?></span>
                    <span style="font-size: 18px; font-weight: 900; line-height: 1; text-align: right; margin-left: auto;"><?php echo count($upcoming_list); ?></span>
                </div>
                <div style="background: rgba(255,255,255,0.18); padding: 10px 16px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">
                    <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.95; font-weight: 700; text-align: left;"><?php esc_html_e('Eventi visitati', 'dfn-theme'); ?></span>
                    <span style="font-size: 18px; font-weight: 900; line-height: 1; text-align: right; margin-left: auto;"><?php echo count($visited_list); ?></span>
                </div>
                <div style="background: rgba(255,255,255,0.18); padding: 10px 16px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; width: 100%; box-sizing: border-box;">
                    <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.95; font-weight: 700; text-align: left;"><?php esc_html_e('Tessere FAI associate', 'dfn-theme'); ?></span>
                    <span style="font-size: 18px; font-weight: 900; line-height: 1; text-align: right; margin-left: auto;"><?php echo $verified_cards_count; ?></span>
                </div>
            </div>
        </div>

        <!-- 2. Prossimo Appuntamento Spotlight Card -->
        <?php if ($next_booking) :
            $order = wc_get_order($next_booking->order_id);
            $hub_token = $order ? hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce')) : '';
            $hub_url   = $order ? home_url('/?dfn_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token) : '';
            $product_id = $next_booking->product_id;
            $event_title = $product_id ? get_the_title($product_id) : __('Evento FAI', 'dfn-theme');
            $image_url = $product_id ? get_the_post_thumbnail_url($product_id, 'medium_large') : '';
            $date_formatted = date_i18n('l d F Y', strtotime($next_booking->event_date_start));
            $time_formatted = date('H:i', strtotime($next_booking->event_time_start));
            ?>
            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 22px 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                <div style="font-size: 11.5px; font-weight: 800; color: #e74f30; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <span>🌟</span> <?php esc_html_e('Il tuo prossimo appuntamento FAI', 'dfn-theme'); ?>
                </div>
                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                    <?php if ($image_url) : ?>
                        <div style="width: 130px; height: 90px; border-radius: 12px; overflow: hidden; flex-shrink: 0;">
                            <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($event_title); ?>" style="width: 100%; height: 100%; object-fit: cover;" />
                        </div>
                    <?php endif; ?>
                    <div style="flex: 1; min-width: 220px;">
                        <h3 style="margin: 0 0 6px 0; font-size: 18px; font-weight: 800; color: #1e293b;"><?php echo esc_html($event_title); ?></h3>
                        <div style="display: flex; gap: 14px; font-size: 13px; color: #475569; font-weight: 600; flex-wrap: wrap; margin-bottom: 8px;">
                            <span>🗓️ <?php echo esc_html(ucfirst($date_formatted)); ?></span>
                            <span>⏰ Ore <?php echo esc_html($time_formatted); ?></span>
                            <span>📍 <?php echo esc_html($next_booking->location); ?></span>
                        </div>
                        <div style="font-size: 12.5px; color: #64748b;">
                            Prenotato per <strong><?php echo absint($next_booking->total_persons); ?> <?php echo $next_booking->total_persons === 1 ? 'persona' : 'persone'; ?></strong> (Ordine #<?php echo esc_html($next_booking->order_id); ?>)
                        </div>
                    </div>
                    <?php if ($hub_url) : ?>
                        <div>
                            <a href="<?php echo esc_url($hub_url); ?>" class="button" style="background: #004b23; color: #ffffff; border-radius: 50px; font-weight: 800; padding: 10px 22px; font-size: 13px; border: none; display: inline-flex; align-items: center; gap: 6px;">
                                🎟️ <?php esc_html_e('Mostra Biglietto', 'dfn-theme'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php 
        // 2B. BOX VOLONTARI: SONDAGGI APERTI, TURNI ASSEGNATI E RIUNIONI
        $is_volunteer = function_exists('dfn_is_user_volunteer') && dfn_is_user_volunteer($current_user_id);
        if ($is_volunteer) :
            // Turni assegnati al volontario
            $my_shifts = function_exists('dfn_get_volunteer_assigned_shifts_for_user') ? dfn_get_volunteer_assigned_shifts_for_user($current_user_id) : [];
            
            // Sondaggi aperti a cui non ha ancora risposto
            global $wpdb;
            $table_surveys = $wpdb->prefix . 'dfn_volunteer_surveys';
            $open_surveys = $wpdb->get_results("SELECT s.*, e.title as event_title FROM {$table_surveys} s JOIN {$wpdb->prefix}dfn_volunteer_events e ON s.event_id = e.id WHERE s.status = 'open' AND s.deadline_at >= NOW() ORDER BY s.deadline_at ASC LIMIT 1");

            // Prossime riunioni
            $upcoming_meetings = function_exists('dfn_get_volunteer_meetings') ? dfn_get_volunteer_meetings(true, 2) : [];
            ?>

            <?php if (! empty($open_surveys)) : 
                $surv = $open_surveys[0];
            ?>
                <!-- Box Sondaggio Aperto -->
                <div style="background: #eff6ff; border: 1.5px solid #bfdbfe; border-left: 5px solid #2563eb; border-radius: 16px; padding: 18px 20px; box-shadow: 0 4px 12px rgba(37,99,235,0.06); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <div style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: #1d4ed8; margin-bottom: 2px;">
                            📋 Disponibilità Richiesta
                        </div>
                        <h3 style="margin: 0 0 4px 0; font-size: 15.5px; font-weight: 800; color: #0f172a;">
                            <?php echo esc_html($surv->title); ?>
                        </h3>
                        <p style="margin: 0; font-size: 12.5px; color: #475569;">
                            Indica le tue preferenze orarie entro il <strong><?php echo esc_html(date_i18n('d/m/Y \a\l\l\e H:i', strtotime($surv->deadline_at))); ?></strong>.
                        </p>
                    </div>
                    <a href="<?php echo esc_url(home_url('/sondaggio-volontari/?token=' . $surv->token_public)); ?>" class="button" style="background: #2563eb; color: #ffffff; border-radius: 50px; font-weight: 800; padding: 9px 22px; font-size: 13px; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        ✍️ Compila Sondaggio
                    </a>
                </div>
            <?php endif; ?>

            <?php if (! empty($my_shifts)) : ?>
                <!-- Box I Miei Turni Assegnati -->
                <div style="background: #ffffff; border: 1px solid #fed7aa; border-left: 5px solid #ea580c; border-radius: 16px; padding: 18px 20px; box-shadow: 0 4px 12px rgba(234,88,12,0.04);">
                    <h3 style="margin: 0 0 12px 0; font-size: 15px; font-weight: 800; color: #9a3412; display: flex; align-items: center; gap: 8px;">
                        <span>📍</span> <?php esc_html_e('I Tuoi Turni & Incarichi Assegnati', 'dfn-theme'); ?>
                    </h3>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($my_shifts as $shift_item) : 
                            $role_lbl = 'Banchetto';
                            if ($shift_item->role_assigned === 'resp_scuola') $role_lbl = '🦺 Responsabile Scuola (S)';
                            elseif ($shift_item->role_assigned === 'resp_banchetto') $role_lbl = '👑 Responsabile Banchetto (R)';
                            elseif ($shift_item->role_assigned === 'guida') $role_lbl = '🏛️ Guida';
                            elseif ($shift_item->role_assigned === 'accoglienza') $role_lbl = 'Accoglienza / Validatore';
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #fff7ed; border-radius: 10px; border: 1px solid #ffedd5; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <strong style="font-size: 13.5px; color: #0f172a; display: block;">
                                        🏛️ <?php echo esc_html($shift_item->place_name); ?>
                                    </strong>
                                    <div style="font-size: 12px; color: #475569; margin-top: 2px;">
                                        🗓️ <strong><?php echo esc_html(ucfirst(date_i18n('l d F Y', strtotime($shift_item->event_date)))); ?></strong> • ⏰ <?php echo esc_html(substr($shift_item->time_start, 0, 5) . ' - ' . substr($shift_item->time_end, 0, 5)); ?> (<?php echo esc_html($shift_item->shift_label); ?>)
                                    </div>
                                </div>
                                <div>
                                    <span style="display: inline-block; background: #ea580c; color: #ffffff; font-size: 11.5px; font-weight: 800; padding: 4px 12px; border-radius: 20px;">
                                        <?php echo esc_html($role_lbl); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (! empty($upcoming_meetings)) : ?>
                <div style="background: #ffffff; border: 1px solid #bbf7d0; border-left: 5px solid #004b23; border-radius: 16px; padding: 18px 20px; box-shadow: 0 4px 12px rgba(0,75,35,0.04);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h3 style="margin: 0; font-size: 15px; font-weight: 800; color: #004b23; display: flex; align-items: center; gap: 8px;">
                            <span>📅</span> <?php esc_html_e('Prossime Riunioni di Delegazione (Volontari)', 'dfn-theme'); ?>
                        </h3>
                        <a href="<?php echo esc_url(wc_get_endpoint_url('riunioni-fai', '', wc_get_page_permalink('myaccount'))); ?>" style="font-size: 12px; font-weight: 700; color: #004b23; text-decoration: none;">
                            <?php esc_html_e('Vedi tutte →', 'dfn-theme'); ?>
                        </a>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($upcoming_meetings as $m_item) :
                            $m_d = strtotime($m_item->meeting_date);
                            $date_text = date_i18n('l d F Y', $m_d);
                            $time_text = substr($m_item->meeting_time_start, 0, 5);
                            ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #f0fdf4; border-radius: 10px; border: 1px solid #dcfce7; flex-wrap: wrap; gap: 10px;">
                                <div>
                                    <h4 style="margin: 0 0 2px 0; font-size: 13.5px; font-weight: 700; color: #0f172a;"><?php echo esc_html($m_item->title); ?></h4>
                                    <div style="font-size: 12px; color: #334155;">
                                        🗓️ <strong><?php echo esc_html(ucfirst($date_text)); ?></strong> alle <strong><?php echo esc_html($time_text); ?></strong> • 📍 <?php echo esc_html($m_item->location); ?>
                                    </div>
                                </div>
                                <?php if (! empty($m_item->meeting_link)) : ?>
                                    <a href="<?php echo esc_url($m_item->meeting_link); ?>" target="_blank" class="button" style="background: #004b23; color: #ffffff; border-radius: 20px; font-size: 11.5px; font-weight: 700; padding: 4px 14px; text-decoration: none;">
                                        🔗 Link Online
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif;
        endif; ?>

        <!-- 3. Box Sottostanti colonna intera, stessa larghezza del box verde -->
        <div style="display: flex; flex-direction: column; gap: 12px; width: 100%; box-sizing: border-box;">
            
            <!-- Box A: Eventi in Programmazione -->
            <div style="width: 100%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; flex-direction: column; box-sizing: border-box;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 style="margin: 0; font-size: 15.5px; font-weight: 800; color: #004b23; display: flex; align-items: center; gap: 8px;">
                            <span>🏛️</span> <?php esc_html_e('Prossimi Eventi FAI', 'dfn-theme'); ?>
                        </h3>
                        <a href="<?php echo esc_url(home_url()); ?>" style="font-size: 12px; font-weight: 700; color: #e74f30; text-decoration: none;"><?php esc_html_e('Vedi tutti →', 'dfn-theme'); ?></a>
                    </div>
                    
                    <?php if (! empty($upcoming_catalog_events)) : ?>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($upcoming_catalog_events as $cat_evt) :
                                $title = get_the_title($cat_evt->product_id) ?: __('Evento FAI', 'dfn-theme');
                                $permalink = get_permalink($cat_evt->product_id);
                                $date_str = date_i18n('d M Y', strtotime($cat_evt->event_date_start));
                                ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f8fafc; border-radius: 10px; border: 1px solid #f1f5f9;">
                                    <div style="flex: 1; padding-right: 8px;">
                                        <h4 style="margin: 0 0 2px 0; font-size: 13px; font-weight: 700; color: #1e293b;"><?php echo esc_html($title); ?></h4>
                                        <div style="font-size: 11px; color: #64748b;">📍 <?php echo esc_html($cat_evt->city ?: $cat_evt->location); ?> • 🗓️ <?php echo esc_html($date_str); ?></div>
                                    </div>
                                    <a href="<?php echo esc_url($permalink); ?>" class="button" style="background: #ffffff; color: #004b23; border: 1px solid #cbd5e1; border-radius: 20px; font-size: 11px; font-weight: 700; padding: 4px 12px; white-space: nowrap;"><?php esc_html_e('Prenota', 'dfn-theme'); ?></a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div style="text-align: center; padding: 24px 12px; color: #64748b;">
                            <div style="font-size: 24px; margin-bottom: 6px;">📅</div>
                            <p style="margin: 0; font-size: 13px;"><?php esc_html_e('Nessun nuovo evento in programma al momento.', 'dfn-theme'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Box B: Luoghi Visitati (Check-in Effettuati) -->
            <div style="width: 100%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 18px 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); display: flex; flex-direction: column; box-sizing: border-box;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 style="margin: 0; font-size: 15.5px; font-weight: 800; color: #004b23; display: flex; align-items: center; gap: 8px;">
                            <span>✅</span> <?php esc_html_e('Eventi visitati', 'dfn-theme'); ?>
                        </h3>
                    </div>

                    <?php if (! empty($visited_list)) : ?>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach (array_slice($visited_list, 0, 3) as $v_item) :
                                $v_title = $v_item->product_id ? get_the_title($v_item->product_id) : __('Evento FAI', 'dfn-theme');
                                $checkin_date = date_i18n('d/m/Y', strtotime($v_item->checked_in_at));
                                ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f0fdf4; border-radius: 10px; border: 1px solid #bbf7d0;">
                                    <div style="flex: 1; padding-right: 8px;">
                                        <h4 style="margin: 0 0 2px 0; font-size: 13px; font-weight: 700; color: #166534;"><?php echo esc_html($v_title); ?></h4>
                                        <div style="font-size: 11px; color: #15803d;">📍 <?php echo esc_html($v_item->location); ?></div>
                                    </div>
                                    <span style="font-size: 10.5px; font-weight: 700; background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 12px; white-space: nowrap;">
                                        ✓ <?php echo esc_html($checkin_date); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <div style="text-align: center; padding: 24px 12px; color: #64748b;">
                            <div style="font-size: 24px; margin-bottom: 6px;">🎟️</div>
                            <p style="margin: 0; font-size: 13px; line-height: 1.5;"><?php esc_html_e('Non risultano ancora visite effettuate. Quando parteciperai a un evento e lo staff scansionerà il tuo biglietto, apparirà qui!', 'dfn-theme'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>


    </div>
    <?php
}


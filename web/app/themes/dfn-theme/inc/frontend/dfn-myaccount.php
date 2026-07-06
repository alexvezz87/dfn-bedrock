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

// Aggiunge la voce di menu rapida "Biglietto Gruppo" alla lista degli ordini cliente
add_filter('woocommerce_my_account_my_orders_actions', 'dfn_add_group_tickets_action_button', 10, 2);

/**
 * Registra gli asset CSS dedicati alla bacheca visitatori e all'hub biglietti.
 */
function dfn_enqueue_myaccount_assets(): void
{
    if (is_account_page()) {
        wp_enqueue_style(
            'dfn-visitor-dashboard-css',
            get_stylesheet_directory_uri() . '/assets/css/dfn-visitor-dashboard.css',
            [],
            '2.0.0',
        );
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

// Registra l'endpoint custom per le tessere FAI
add_action('init', 'dfn_fai_cards_endpoint_init');
/**
 * Registra il nuovo endpoint rewrite di WooCommerce per le tessere FAI.
 */
function dfn_fai_cards_endpoint_init(): void
{
    add_rewrite_endpoint('tessere-fai', EP_PAGES);
}

// Aggiunge la query var consentita da WooCommerce
add_filter('query_vars', 'dfn_fai_cards_query_vars', 0);
/**
 * Registra la variabile di query per l'endpoint tessere-fai.
 *
 * @param array $vars Variabili di query esistenti.
 * @return array Variabili di query aggiornate.
 */
function dfn_fai_cards_query_vars(array $vars): array
{
    $vars[] = 'tessere-fai';
    return $vars;
}

// Inserisce la voce nel menu Mio Account di WooCommerce
add_filter('woocommerce_account_menu_items', 'dfn_add_fai_cards_to_menu', 15);
/**
 * Aggiunge la voce "Tessere FAI" al menu di navigazione dell'account.
 *
 * @param array<string, string> $items Voci del menu account.
 * @return array<string, string> Menu modificato.
 */
function dfn_add_fai_cards_to_menu(array $items): array
{
    $new_items = [];
    foreach ($items as $key => $value) {
        if ('edit-account' === $key) {
            $new_items['tessere-fai'] = esc_html__('Tessere FAI', 'dfn-theme');
        }
        $new_items[ $key ] = $value;
    }
    if (! isset($new_items['tessere-fai'])) {
        $new_items['tessere-fai'] = esc_html__('Tessere FAI', 'dfn-theme');
    }
    return $new_items;
}

// Esegue il flush automatico one-shot delle regole di riscrittura
add_action('init', 'dfn_fai_cards_flush_rules', 999);
/**
 * Effettua il flush delle regole di rewrite all'attivazione per evitare errori 404.
 */
function dfn_fai_cards_flush_rules(): void
{
    if ('yes' !== get_option('dfn_fai_permalink_flushed')) {
        dfn_fai_cards_endpoint_init();
        flush_rewrite_rules();
        update_option('dfn_fai_permalink_flushed', 'yes');
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

    global $wpdb;
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    // Recupera solo le tessere verificate associate all'utente corrente
    $cards = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_fai} WHERE user_id = %d AND verified = 1 ORDER BY card_expiry DESC, created_at DESC",
            $current_user_id,
        ),
    );
    ?>
    <div class="dfn-fai-dashboard-section">
        <h2 class="dfn-dashboard-title"><?php esc_html_e('Le Mie Tessere FAI', 'dfn-theme'); ?></h2>
        <p class="dfn-dashboard-desc"><?php esc_html_e('In questa sezione puoi visualizzare le tue tessere FAI associate e verificate dalla segreteria. Saranno disponibili come suggerimenti rapidi durante la prenotazione.', 'dfn-theme'); ?></p>
        
        <?php if (! empty($cards)) : ?>
            <div class="dfn-fai-cards-grid">
                <?php foreach ($cards as $card) :
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
                <p><?php esc_html_e('Non risultano tessere FAI verificate associate a questo account.', 'dfn-theme'); ?></p>
                <p class="dfn-fai-empty-sub"><?php esc_html_e('Le tue tessere verranno collegate automaticamente al tuo account ed approvate dalla segreteria in seguito al completamento di una prenotazione con ingressi scontati FAI.', 'dfn-theme'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

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
    <div class="dfn-my-bookings-section">
        <h2 class="dfn-dashboard-title"><?php esc_html_e('Le Mie Prenotazioni', 'dfn-theme'); ?></h2>
        <p class="dfn-dashboard-desc"><?php esc_html_e('Qui puoi consultare lo storico di tutte le tue prenotazioni suddiviso tra eventi in arrivo e visite già effettuate.', 'dfn-theme'); ?></p>

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
                                                    <span class="dfn-table-order-id">#<?php echo esc_html($b->order_id); ?></span>
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
                                                        <?php
                                            $breakdown = [];
                                            if ($b->persons_standard > 0) {
                                                $breakdown[] = esc_html(sprintf(_n('%d Ordinario', '%d Ordinari', $b->persons_standard, 'dfn-theme'), $b->persons_standard));
                                            }
                                            if ($b->persons_fai > 0) {
                                                $breakdown[] = esc_html(sprintf(_n('%d Socio FAI', '%d Soci FAI', $b->persons_fai, 'dfn-theme'), $b->persons_fai));
                                            }
                                            if (! empty($breakdown)) {
                                                echo '<span class="dfn-table-seats-breakdown">' . implode(', ', $breakdown) . '</span>';
                                            }
                                            ?>
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
                                                    <?php if (! $is_cancelled && $order) : ?>
                                                        <div class="dfn-table-actions-container">
                                                            <?php
                                                            $hub_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_hub', wp_salt('nonce'));
                                                            $hub_url   = home_url('/?dfn_hub=1&order_id=' . $order->get_id() . '&token=' . $hub_token);
                                                            ?>
                                                            <a href="<?php echo esc_url($hub_url); ?>" class="button dfn-action-tickets"><?php esc_html_e('Vedi prenotazione', 'dfn-theme'); ?></a>
                                                            
                                                            <?php
                                                            $cancel_token = hash_hmac('sha256', $order->get_order_key() . '_dfn_cancel', wp_salt('nonce'));
                                                            $cancel_url   = home_url('/?dfn_cancel_booking=1&order_id=' . $order->get_id() . '&token=' . $cancel_token);
                                                            ?>
                                                            <a href="<?php echo esc_url($cancel_url); ?>" class="dfn-btn-cancel-booking" onclick="return confirm('<?php echo esc_js(__('Sei sicuro di voler annullare questa prenotazione?', 'dfn-theme')); ?>');"><?php esc_html_e('Annulla la prenotazione', 'dfn-theme'); ?></a>
                                                        </div>
                                                    <?php elseif ($is_cancelled) : ?>
                                                        <span class="dfn-booking-status-badge dfn-status-cancelled"><?php esc_html_e('Annullata', 'dfn-theme'); ?></span>
                                                    <?php endif; ?>
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
    </div>
    <?php
}

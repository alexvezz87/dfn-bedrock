<?php
/**
 * DFN Booking System 2.0 — Native Thank You / Order Received Template
 *
 * Schermata di conferma e successo celebrativa con pulsante diretto
 * all'Hub Biglietti / Download QR Code e riepilogo evento.
 *
 * @package DFN_Theme
 * @since   2.0.0
 * @version 2.0.0
 *
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;

$booking = ($order && function_exists('dfn_db_get_booking_by_order')) ? dfn_db_get_booking_by_order($order->get_id()) : null;
$event   = ($booking && function_exists('dfn_db_get_event')) ? dfn_db_get_event((int) $booking->event_id) : null;
$hub_url = home_url('/hub-biglietti/');
if ($booking && ! empty($booking->qr_token)) {
    $hub_url = add_query_arg('token', $booking->qr_token, $hub_url);
}
?>

<div class="dfn-checkout-container dfn-thankyou-container">

    <?php if ($order) : ?>

        <?php do_action('woocommerce_before_thankyou', $order->get_id()); ?>

        <?php if ($order->has_status('failed')) : ?>

            <div class="dfn-card dfn-failed-card">
                <div class="dfn-failed-icon">❌</div>
                <h1 class="dfn-failed-title"><?php esc_html_e('Transazione Non Completata', 'dfn-theme'); ?></h1>
                <p class="dfn-failed-desc">
                    <?php esc_html_e('Purtroppo la transazione di pagamento non è andata a buon fine o è stata rifiutata dall\'istituto bancario. Puoi effettuare un nuovo tentativo di pagamento o contattare la tua banca.', 'dfn-theme'); ?>
                </p>
                <div class="dfn-failed-actions">
                    <a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="dfn-btn-primary dfn-btn-retry">
                        🔄 <?php esc_html_e('Riprova il Pagamento', 'dfn-theme'); ?>
                    </a>
                    <?php if (is_user_logged_in()) : ?>
                        <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="dfn-btn-secondary">
                            <?php esc_html_e('La mia Area Personale', 'dfn-theme'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        <?php else : ?>

            <!-- Success Header -->
            <div class="dfn-thankyou-hero">
                <div class="dfn-success-checkmark-wrapper">
                    <div class="dfn-success-checkmark">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    </div>
                </div>
                <h1 class="dfn-thankyou-title">
                    <?php printf(esc_html__('Grazie %s, la tua prenotazione è confermata!', 'dfn-theme'), esc_html($order->get_billing_first_name() ? ucfirst($order->get_billing_first_name()) : '')); ?>
                </h1>
                <p class="dfn-thankyou-subtitle">
                    <?php printf(esc_html__('Abbiamo inviato un\'email di conferma con i biglietti e i pass digitali a %s.', 'dfn-theme'), '<strong>' . esc_html($order->get_billing_email()) . '</strong>'); ?>
                </p>
            </div>

            <!-- Main CTA Hub Biglietti -->
            <div class="dfn-ticket-hub-cta-card">
                <div class="dfn-hub-cta-content">
                    <div class="dfn-hub-cta-icon">🎟️</div>
                    <div class="dfn-hub-cta-text">
                        <h3><?php esc_html_e('I tuoi Biglietti con QR Code sono pronti!', 'dfn-theme'); ?></h3>
                        <p><?php esc_html_e('Accedi subito all\'Hub Biglietti per visualizzare i pass digitali, salvarli sul tuo smartphone o stamparli.', 'dfn-theme'); ?></p>
                    </div>
                </div>
                <a href="<?php echo esc_url($hub_url); ?>" class="dfn-btn-primary dfn-btn-hub-action">
                    <span>📱 <?php esc_html_e('Apri Hub Biglietti & QR Code', 'dfn-theme'); ?></span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                </a>
            </div>

            <div class="dfn-thankyou-grid">

                <!-- Card Riepilogo Ordine -->
                <div class="dfn-card dfn-thankyou-details-card">
                    <div class="dfn-card-header">
                        <div class="dfn-card-header-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        </div>
                        <div>
                            <h2 class="dfn-card-title"><?php esc_html_e('Dettagli della Prenotazione', 'dfn-theme'); ?></h2>
                            <p class="dfn-card-subtitle"><?php esc_html_e('Riferimenti per l\'accesso all\'evento.', 'dfn-theme'); ?></p>
                        </div>
                    </div>

                    <div class="dfn-card-body">
                        <ul class="dfn-order-overview-list">
                            <li class="dfn-overview-item">
                                <span class="dfn-overview-label"><?php esc_html_e('Numero Prenotazione:', 'dfn-theme'); ?></span>
                                <strong class="dfn-overview-val">#<?php echo esc_html($order->get_order_number()); ?></strong>
                            </li>
                            <li class="dfn-overview-item">
                                <span class="dfn-overview-label"><?php esc_html_e('Data Ordine:', 'dfn-theme'); ?></span>
                                <strong class="dfn-overview-val"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></strong>
                            </li>
                            <li class="dfn-overview-item">
                                <span class="dfn-overview-label"><?php esc_html_e('Email Intestatario:', 'dfn-theme'); ?></span>
                                <strong class="dfn-overview-val"><?php echo esc_html($order->get_billing_email()); ?></strong>
                            </li>
                            <li class="dfn-overview-item">
                                <span class="dfn-overview-label"><?php esc_html_e('Totale Contributo:', 'dfn-theme'); ?></span>
                                <strong class="dfn-overview-val dfn-overview-total"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
                            </li>
                            <?php if ($order->get_payment_method_title()) : ?>
                                <li class="dfn-overview-item">
                                    <span class="dfn-overview-label"><?php esc_html_e('Metodo di Pagamento:', 'dfn-theme'); ?></span>
                                    <strong class="dfn-overview-val"><?php echo wp_kses_post($order->get_payment_method_title()); ?></strong>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- Card Informazioni per il Giorno dell'Evento -->
                <div class="dfn-card dfn-event-info-card">
                    <div class="dfn-card-header">
                        <div class="dfn-card-header-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        </div>
                        <div>
                            <h2 class="dfn-card-title"><?php esc_html_e('Informazioni Utili per l\'Accesso', 'dfn-theme'); ?></h2>
                            <p class="dfn-card-subtitle"><?php esc_html_e('Cosa fare il giorno dell\'evento.', 'dfn-theme'); ?></p>
                        </div>
                    </div>

                    <div class="dfn-card-body">
                        <?php if ($event) : ?>
                            <div class="dfn-event-recap-box">
                                <strong class="dfn-event-recap-title"><?php echo esc_html($event->title); ?></strong>
                                <?php if (! empty($event->event_date)) : ?>
                                    <div class="dfn-event-recap-date">
                                        📅 <?php echo esc_html(date_i18n('l d F Y', strtotime($event->event_date))); ?>
                                        <?php if (! empty($event->event_time)) : ?>
                                            — ⏰ <?php echo esc_html(substr($event->event_time, 0, 5)); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (! empty($event->location)) : ?>
                                    <div class="dfn-event-recap-loc">
                                        📍 <?php echo esc_html($event->location); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="dfn-access-tips">
                            <div class="dfn-tip-item">
                                <span class="dfn-tip-icon">⏱️</span>
                                <div>
                                    <strong><?php esc_html_e('Orario di arrivo:', 'dfn-theme'); ?></strong>
                                    <span><?php esc_html_e('Ti consigliamo di presentarti circa 15 minuti prima dell\'orario di inizio.', 'dfn-theme'); ?></span>
                                </div>
                            </div>
                            <div class="dfn-tip-item">
                                <span class="dfn-tip-icon">📲</span>
                                <div>
                                    <strong><?php esc_html_e('Check-in rapido all\'ingresso:', 'dfn-theme'); ?></strong>
                                    <span><?php esc_html_e('Mostra il QR code direttamente dallo smartphone ai volontari all\'accoglienza.', 'dfn-theme'); ?></span>
                                </div>
                            </div>
                            <div class="dfn-tip-item">
                                <span class="dfn-tip-icon">🏷️</span>
                                <div>
                                    <strong><?php esc_html_e('Soci FAI:', 'dfn-theme'); ?></strong>
                                    <span><?php esc_html_e('Se hai usufruito della quota FAI, ricorda di avere a portata di mano la tessera associativa valida.', 'dfn-theme'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Dettaglio Completo Ordine & Dati di Fatturazione tramite hook standard WooCommerce -->
            <div class="dfn-thankyou-bottom-sections">
                <?php do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); ?>
                <?php do_action('woocommerce_thankyou', $order->get_id()); ?>
            </div>

        <?php endif; ?>

    <?php else : ?>

        <div class="dfn-card dfn-order-empty-card">
            <h1 class="dfn-card-title"><?php esc_html_e('Grazie per la tua prenotazione.', 'dfn-theme'); ?></h1>
            <p><?php esc_html_e('L\'ordine è stato registrato nel nostro sistema.', 'dfn-theme'); ?></p>
        </div>

    <?php endif; ?>

</div>

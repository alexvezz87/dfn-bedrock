<?php

/**
 * DFN Booking System 2.0 — WooCommerce Payment Gateway "Pagamento all'Ingresso"
 *
 * Consente la prenotazione dei biglietti online e il saldo effettivo direttamente in loco (cassa live/botteghino).
 * Si attiva condizionalmente in base alla modalità di pagamento dell'evento inserito nel carrello.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'dfn_init_gateway_in_loco', 11);

if (class_exists('WC_Payment_Gateway')) {
    /**
     * Gateway personalizzato per saldi ticket in loco.
     */
    class DFN_Gateway_In_Loco extends WC_Payment_Gateway
    {
        /** @var string */
        public $instructions;

        /**
         * Costruttore del gateway.
         */
        public function __construct()
        {
            $this->id                 = 'dfn_in_loco';
            $this->icon               = apply_filters('dfn_gateway_in_loco_icon', '');
            $this->has_fields         = false;
            $this->method_title       = __("Contributo all'Ingresso", 'dfn-theme');
            $this->method_description = __("Consente di prenotare online e lasciare il contributo direttamente all'ingresso dell'evento (Botteghino).", 'dfn-theme');

            // Carica le impostazioni configurate dall'amministratore
            $this->init_form_fields();
            $this->init_settings();

            // Proprietà definite dal pannello di controllo
            $this->title        = $this->get_option('title');
            $this->description  = $this->get_option('description');
            $this->instructions = $this->get_option('instructions');

            // Supporto all'aggiornamento delle impostazioni
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ]);
        }

        /**
         * Definisce i campi del modulo di configurazione del gateway nell'admin di WooCommerce.
         */
        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __('Abilita/Disabilita', 'dfn-theme'),
                    'type'    => 'checkbox',
                    'label'   => __('Abilita il Contributo all\'Ingresso', 'dfn-theme'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title'       => __('Titolo', 'dfn-theme'),
                    'type'        => 'text',
                    'description' => __('Questo controlla il titolo che l\'utente vede durante il checkout.', 'dfn-theme'),
                    'default'     => __("Contributo all'Ingresso", 'dfn-theme'),
                    'desc_tip'    => true,
                ],
                'description' => [
                    'title'       => __('Descrizione', 'dfn-theme'),
                    'type'        => 'textarea',
                    'description' => __('La descrizione che l\'utente vede durante il checkout.', 'dfn-theme'),
                    'default'     => __("Prenota ora. Lascerai il contributo minimo suggerito direttamente all'ingresso dell'evento in contanti o carta.", 'dfn-theme'),
                    'desc_tip'    => true,
                ],
                'instructions' => [
                    'title'       => __('Istruzioni', 'dfn-theme'),
                    'type'        => 'textarea',
                    'description' => __('Istruzioni che verranno mostrate nella pagina di ringraziamento e nelle email.', 'dfn-theme'),
                    'default'     => __("La tua prenotazione è confermata! Mostra il codice QR ricevuto via email all'ingresso dell'evento per lasciare il contributo ed entrare velocemente.", 'dfn-theme'),
                    'desc_tip'    => true,
                ],
            ];
        }

        /**
         * Processa il pagamento. Imposta lo stato dell'ordine a "pending" (in attesa di pagamento/saldo)
         * e reindirizza alla pagina di ringraziamento.
         *
         * @param int $order_id ID dell'ordine.
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);

            if (! $order) {
                return [
                    'result'   => 'failure',
                    'redirect' => '',
                ];
            }

            // Imposta lo stato dell'ordine a 'pending' per il contributo all'ingresso.
            $order->update_status('pending', __('Prenotazione effettuata con contributo all\'ingresso.', 'dfn-theme'));

            // Memorizza che questo ordine è con saldo all'ingresso per bypassare cron di pulizia automatica 24h
            $order->update_meta_data('_dfn_payment_in_loco', 'yes');
            $order->save();

            // Riduci le scorte del prodotto WooCommerce
            wc_reduce_stock_levels($order_id);

            // Svuota il carrello
            if (WC()->cart instanceof \WC_Cart) {
                WC()->cart->empty_cart();
            }

            // Ritorna il redirect alla pagina "ordine ricevuto"
            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        }

        /**
         * Mostra le istruzioni nella pagina di ringraziamento.
         */
        public function thankyou_page()
        {
            if ($this->instructions) {
                echo wp_kses_post(wpautop(wptexturize($this->instructions)));
            }
        }

        /**
         * Mostra le istruzioni nelle email di WooCommerce.
         *
         * @param WC_Order $order Oggetto dell'ordine.
         * @param bool     $sent_to_admin Se è inviato all'admin.
         * @param bool     $plain_text Se formato testo semplice.
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false)
        {
            if ($this->instructions && ! $sent_to_admin && $this->id === $order->get_payment_method()) {
                echo wp_kses_post(wpautop(wptexturize($this->instructions)) . PHP_EOL);
            }
        }
    }
}

/**
 * Inizializza il gateway di pagamento personalizzato "Pagamento all'Ingresso".
 */
function dfn_init_gateway_in_loco()
{
    if (class_exists('WC_Payment_Gateway')) {
        add_filter('woocommerce_payment_gateways', 'dfn_add_gateway_in_loco');
    }
}

/**
 * Registra il gateway all'interno di WooCommerce.
 *
 * @param array $methods Gateway esistenti.
 * @return array
 */
function dfn_add_gateway_in_loco($methods)
{
    $methods[] = 'DFN_Gateway_In_Loco';
    return $methods;
}

/**
 * Controllo condizionale sulla disponibilità del gateway in base alla configurazione degli eventi nel carrello.
 *
 * @param array $available_gateways Gateway attivi.
 * @return array
 */
function dfn_filter_available_gateways_for_events($available_gateways)
{
    if (is_admin()) {
        return $available_gateways;
    }

    if (! isset($available_gateways['dfn_in_loco'])) {
        return $available_gateways;
    }

    if (! (WC()->cart instanceof \WC_Cart)) {
        return $available_gateways;
    }

    $cart = WC()->cart;
    $has_online_only_event = false;

    foreach ($cart->get_cart() as $cart_item) {
        $product_id = $cart_item['product_id'];

        // Cerca se esiste un evento associato a questo prodotto
        $event = dfn_db_get_event_by_product($product_id);

        if ($event) {
            // Se la modalità di pagamento dell'evento è strettamente online
            if ($event->payment_mode === 'online') {
                $has_online_only_event = true;
                break;
            }
        }
    }

    // Se c'è almeno un evento che richiede esclusivamente il pagamento online,
    // disabilita il gateway "Pagamento all'Ingresso"
    if ($has_online_only_event) {
        unset($available_gateways['dfn_in_loco']);
    }

    return $available_gateways;
}
add_filter('woocommerce_available_payment_gateways', 'dfn_filter_available_gateways_for_events', 20);

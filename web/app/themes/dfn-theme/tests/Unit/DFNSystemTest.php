<?php
// Definizione classi stub di WooCommerce se non esistono per permettere i test di tipo e instanceof nel namespace globale
namespace {
    if ( ! class_exists( 'WC_Cart' ) ) {
        class WC_Cart {}
    }
    if ( ! class_exists( 'WC_Order' ) ) {
        class WC_Order {}
    }
}

namespace DFN\Theme\Tests\Unit {

// Carica esplicitamente i file del tema da testare
require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-database.php';
require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-setup.php';
require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-helpers.php';
require_once dirname(dirname(__DIR__)) . '/inc/frontend/dfn-checkout.php';
require_once dirname(dirname(__DIR__)) . '/inc/woocommerce/dfn-gateway-in-loco.php';
require_once dirname(dirname(__DIR__)) . '/inc/api/dfn-ajax-scanner.php';
require_once dirname(dirname(__DIR__)) . '/inc/frontend/dfn-myaccount.php';
require_once dirname(dirname(__DIR__)) . '/inc/admin/dfn-volunteer-dashboard.php';

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

class DFNSystemTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mocks di base di WordPress comunemente usati
        Functions\stubs(array(
            '__',
            'esc_html__',
            'esc_attr_e',
            'esc_html_e',
            'esc_attr',
            'esc_html',
            'esc_url' => function($val) { return $val; },
            'admin_url' => function($path = '') { return 'http://dfn-bedrock.local/wp-admin/' . $path; },
            'sanitize_text_field' => function($val) { return $val; },
            'sanitize_email' => function($val) { return $val; },
            'absint' => function($val) { return (int)$val; },
            'wp_kses_post' => function($val) { return $val; },
            'wpautop' => function($val) { return $val; },
            'wptexturize' => function($val) { return $val; },
            'get_the_title' => function($id) { return 'Mocked Title'; },
            'current_time' => function($type) { return date('Y-m-d H:i:s'); },
            'wc_price' => function($price) { return '€' . $price; },
            'check_ajax_referer' => true,
            'date_i18n' => function($format, $timestamp = false) { return date($format, $timestamp ?: time()); }
        ));

        Functions\when('wp_send_json_success')->alias(function($data = null) {
            throw new \Exception('WP_SEND_JSON_SUCCESS: ' . json_encode($data));
        });
        Functions\when('wp_send_json_error')->alias(function($data = null) {
            throw new \Exception('WP_SEND_JSON_ERROR: ' . json_encode($data));
        });
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test 1: Semplificazione campi Express Checkout.
     */
    public function test_simplify_checkout_fields_removes_unnecessary_fields_on_express() {
        // Mock della funzione di controllo Express Checkout
        Functions\when('dfn_is_express_checkout_needed')->justReturn(true);

        $fields = array(
            'billing' => array(
                'billing_first_name' => array(),
                'billing_last_name' => array(),
                'billing_email' => array(),
                'billing_phone' => array(),
                'billing_address_1' => array(),
                'billing_city' => array(),
                'billing_postcode' => array()
            ),
            'shipping' => array(
                'shipping_first_name' => array()
            )
        );

        $result = dfn_conditionally_simplify_checkout_fields($fields);

        // Verifica che i campi di fatturazione non essenziali siano rimossi
        $this->assertArrayNotHasKey('billing_address_1', $result['billing']);
        $this->assertArrayNotHasKey('billing_city', $result['billing']);
        
        // Verifica che i campi necessari siano conservati
        $this->assertArrayHasKey('billing_first_name', $result['billing']);
        $this->assertArrayHasKey('billing_email', $result['billing']);
        
        // Verifica che la spedizione sia rimossa
        $this->assertArrayNotHasKey('shipping', $result);
    }

    /**
     * Test 2: Controllo necessità pagamento su carrello gratuito.
     */
    public function test_checkout_needs_payment_returns_false_for_free_cart() {
        // Mock del carrello di WooCommerce
        $cart_mock = $this->getMockBuilder(\WC_Cart::class)
            ->addMethods(array('get_total', 'get_cart', 'add_fee'))
            ->getMock();

        $cart_mock->method('get_total')->willReturn('0.00');

        $wc = new \stdClass();
        $wc->cart = $cart_mock;
        Functions\when('WC')->justReturn($wc);

        $needs_payment = dfn_checkout_needs_payment(true);

        $this->assertFalse($needs_payment);
    }

    /**
     * Test 3: Sconto FAI Dinamico calcolato correttamente.
     */
    public function test_fai_discount_applied_dynamically_based_on_event_prices() {
        // Mock dell'oggetto cart
        $cart_mock = $this->getMockBuilder(\WC_Cart::class)
            ->addMethods(array('get_total', 'get_cart', 'add_fee'))
            ->getMock();
        
        // Mock degli elementi del carrello
        $cart_items = array(
            'item_1' => array(
                'product_id' => 99,
                'dfn_qty_fai' => 2 // 2 biglietti FAI
            )
        );
        $cart_mock->method('get_cart')->willReturn($cart_items);

        // Mock del database per recuperare l'evento
        $event_mock = new \stdClass();
        $event_mock->price_standard = 10.00;
        $event_mock->price_fai      = 8.00; // Differenziale sconto = 2.00 € a biglietto (totale 4.00 €)
        Functions\when('dfn_db_get_event_by_product')->justReturn($event_mock);

        // Verifica che add_fee venga chiamato esattamente una volta con l'importo corretto (-4.00)
        $cart_mock->expects($this->once())
            ->method('add_fee')
            ->with(
                $this->stringContains('Sconto Soci FAI'),
                $this->equalTo(-4.00),
                $this->equalTo(false)
            );

        $wc = new \stdClass();
        $wc->cart = $cart_mock;
        Functions\when('WC')->justReturn($wc);

        Functions\when('is_admin')->justReturn(false);

        // Eseguiamo la funzione di calcolo sconti
        dfn_apply_fai_members_discount_to_cart($cart_mock);
    }

    /**
     * Test 4: Rilevamento FAI su Ordine.
     */
    public function test_is_order_fai_checks_coupons_and_bookings() {
        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('get_coupon_codes', 'get_items', 'get_id'))
            ->getMock();

        $order_mock->method('get_coupon_codes')->willReturn(array('socio_fai_novara_2025'));
        $order_mock->method('get_items')->willReturn(array());

        $this->assertTrue(dfn_is_order_fai($order_mock));
    }

    /**
     * Test 5: Auto-completamento ordini condizionale.
     */
    public function test_auto_completa_ordini_pagati_ignores_in_loco() {
        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('get_payment_method', 'update_status'))
            ->getMock();

        // Se il metodo è dfn_in_loco, non deve chiamare update_status
        $order_mock->method('get_payment_method')->willReturn('dfn_in_loco');
        $order_mock->expects($this->never())->method('update_status');

        Functions\when('wc_get_order')->justReturn($order_mock);

        dfn_auto_completa_ordini_pagati(123);
    }

    public function test_auto_completa_ordini_pagati_completes_online_payment() {
        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('get_payment_method', 'update_status'))
            ->getMock();

        // Se il metodo è online (es. stripe), deve completare l'ordine
        $order_mock->method('get_payment_method')->willReturn('stripe');
        $order_mock->expects($this->once())->method('update_status')->with('completed');

        Functions\when('wc_get_order')->justReturn($order_mock);

        dfn_auto_completa_ordini_pagati(123);
    }

    /**
     * Test 6: Filtro gateway disponibili condizionale.
     */
    public function test_filter_available_gateways_removes_in_loco_if_online_only_event() {
        Functions\when('is_admin')->justReturn(false);

        // Prepariamo il carrello con un prodotto legato ad un evento online-only
        $cart_mock = $this->getMockBuilder(\WC_Cart::class)
            ->addMethods(array('get_cart'))
            ->getMock();

        $cart_mock->method('get_cart')->willReturn(array(
            array('product_id' => 456)
        ));

        $wc = new \stdClass();
        $wc->cart = $cart_mock;
        Functions\when('WC')->justReturn($wc);

        // Configura l'evento legato al prodotto come online-only
        $event_mock = new \stdClass();
        $event_mock->payment_mode = 'online';
        Functions\when('dfn_db_get_event_by_product')->justReturn($event_mock);

        $gateways = array(
            'stripe' => new \stdClass(),
            'dfn_in_loco' => new \stdClass()
        );

        $filtered = dfn_filter_available_gateways_for_events($gateways);

        // Dovrebbe aver rimosso 'dfn_in_loco'
        $this->assertArrayNotHasKey('dfn_in_loco', $filtered);
        $this->assertArrayHasKey('stripe', $filtered);
    }

    /**
     * Test 7: Label di qualifica per differenti ordini.
     */
    public function test_get_order_qualifica_label_shows_badges() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $wpdb = $this->getMockBuilder(\stdClass::class)
            ->addMethods(array('prepare', 'get_row'))
            ->getMock();
        $wpdb->prefix = 'wp_';
        $wpdb->method('prepare')->willReturn('PREPARED');
        $wpdb->method('get_row')->willReturn((object)array('persons_fai' => 0));

        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('get_meta', 'get_payment_method', 'get_payment_method_title', 'get_coupon_codes', 'get_items', 'get_id'))
            ->getMock();

        $order_mock->method('get_meta')->willReturnMap(array(
            array('_dfn_is_authority', 'yes'),
        ));
        $order_mock->method('get_payment_method')->willReturn('dfn_in_loco');
        $order_mock->method('get_coupon_codes')->willReturn(array());
        $order_mock->method('get_items')->willReturn(array());
        $order_mock->method('get_id')->willReturn(123);

        $label = dfn_get_order_qualifica_label($order_mock);

        $this->assertStringContainsString('🌟 AUTORITÀ', $label);
        $this->assertStringContainsString('💵 CASSA LIVE', $label);

        $wpdb = $original_wpdb;
    }

    /**
     * Test 8: Placeholders email WooCommerce.
     */
    public function test_custom_email_placeholders_replaces_nome_evento() {
        // Definiamo WC_Order se non esiste per permettere il test di is_a()
        if ( ! class_exists( 'WC_Order' ) ) {
            eval('class WC_Order {}');
        }

        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('get_items'))
            ->getMock();

        $item_mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(array('get_name'))
            ->getMock();
        $item_mock->method('get_name')->willReturn('Visita al Castello');

        $order_mock->method('get_items')->willReturn(array($item_mock));

        $email_mock = new \stdClass();
        $email_mock->object = $order_mock;

        $input_string = 'Il tuo biglietto per {nome_evento} è pronto!';
        $result = dfn_custom_email_placeholders($input_string, $email_mock);

        $this->assertEquals('Il tuo biglietto per Visita al Castello è pronto!', $result);
    }

    /**
     * Test 9: Getters Database (Mocked).
     */
    public function test_db_get_event_prepares_and_runs_query() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $wpdb = $this->getMockBuilder(\stdClass::class)
            ->addMethods(array('prepare', 'get_row'))
            ->getMock();
        $wpdb->prefix = 'wp_';

        $wpdb->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SELECT * FROM'), 42)
            ->willReturn('PREPARED_QUERY');

        $wpdb->expects($this->once())
            ->method('get_row')
            ->with('PREPARED_QUERY')
            ->willReturn((object)array('id' => 42));

        $event = dfn_db_get_event(42);

        $this->assertEquals(42, $event->id);

        $wpdb = $original_wpdb;
    }

    /**
     * Test 10: Scanner Live — Validazione Scansione per Biglietto già saldato.
     */
    public function test_process_scan_online_order_success() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $wpdb = $this->getMockBuilder(\stdClass::class)
            ->addMethods(array('prepare', 'get_row', 'update'))
            ->getMock();
        $wpdb->prefix = 'wp_';

        // Mock del record booking
        $booking_mock = (object)array(
            'id' => 10,
            'order_id' => 123,
            'customer_name' => 'Mario Rossi',
            'total_persons' => 2,
            'status' => 'confirmed',
            'event_id' => 456
        );

        $wpdb->method('prepare')->willReturn('PREPARED');
        $wpdb->method('get_row')->willReturn($booking_mock);
        $wpdb->expects($this->once())->method('update')->willReturn(true);

        // Mock dell'ordine WC completed
        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('has_status', 'get_total', 'get_payment_method'))
            ->getMock();
        $order_mock->method('has_status')->willReturn(true);
        $order_mock->method('get_total')->willReturn('20.00');

        Functions\when('wc_get_order')->justReturn($order_mock);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(1);

        // Mock del referrer check
        Functions\stubs(array(
            'check_ajax_referer' => true
        ));

        $_POST['qr_token'] = 'VALID_TOKEN';
        $_POST['security'] = 'NONCE';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('WP_SEND_JSON_SUCCESS');
        $this->expectExceptionMessage('Mario Rossi');

        try {
            dfn_process_scan_ajax_handler();
        } finally {
            unset($_POST['qr_token']);
            unset($_POST['security']);
            $wpdb = $original_wpdb;
        }
    }

    /**
     * Test 11: Scanner Live — Consolidamento pagamento In Loco (contanti/POS).
     */
    public function test_consolidate_payment_in_loco_success() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $wpdb = $this->getMockBuilder(\stdClass::class)
            ->addMethods(array('prepare', 'get_row', 'update', 'query'))
            ->getMock();
        $wpdb->prefix = 'wp_';

        // Mock del record booking
        $booking_mock = (object)array(
            'id' => 10,
            'order_id' => 123,
            'customer_name' => 'Gianni Bianchi',
            'total_persons' => 3,
            'status' => 'confirmed',
            'event_id' => 456,
            'amount_due' => 30.00
        );

        $wpdb->method('prepare')->willReturn('PREPARED');
        $wpdb->method('get_row')->willReturn($booking_mock);
        $wpdb->expects($this->once())->method('update')->willReturn(true);

        // Mock dell'ordine WC pending
        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('update_meta_data', 'update_status', 'save', 'get_payment_method'))
            ->getMock();
        $order_mock->expects($this->once())->method('update_status')->with('completed');
        $order_mock->expects($this->once())->method('save');

        Functions\when('wc_get_order')->justReturn($order_mock);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(2);
        Functions\when('get_userdata')->justReturn((object)array('display_name' => 'Operatore FAI'));

        $_POST['qr_token'] = 'IN_LOCO_TOKEN';
        $_POST['payment_method'] = 'cash';
        $_POST['security'] = 'NONCE';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('WP_SEND_JSON_SUCCESS');
        $this->expectExceptionMessage('Operatore FAI');

        try {
            dfn_consolidate_in_loco_payment_ajax_handler();
        } finally {
            unset($_POST['qr_token']);
            unset($_POST['payment_method']);
            unset($_POST['security']);
            $wpdb = $original_wpdb;
        }
    }

    /**
     * Test 12: Area Riservata Cliente — Corretto inserimento del bottone Biglietto Gruppo.
     */
    public function test_add_group_tickets_action_button_injects_group_action() {
        $order_mock = $this->getMockBuilder(\WC_Order::class)
            ->addMethods(array('has_status', 'get_id', 'get_order_key'))
            ->getMock();

        $order_mock->method('has_status')->willReturn(true);
        $order_mock->method('get_id')->willReturn(999);
        $order_mock->method('get_order_key')->willReturn('wc_order_abc123');

        Functions\when('wp_salt')->justReturn('NONCE_SALT');
        Functions\when('site_url')->alias(function($path = '') { return 'http://dfn-bedrock.local' . $path; });

        $actions = array(
            'view' => array( 'url' => '#', 'name' => 'Visualizza' )
        );

        $result = dfn_add_group_tickets_action_button($actions, $order_mock);

        $this->assertArrayHasKey('dfn_group_ticket', $result);
        $this->assertStringContainsString('dfn_hub=1', $result['dfn_group_ticket']['url']);
        $this->assertStringContainsString('order_id=999', $result['dfn_group_ticket']['url']);
    }

    /**
     * Test 13: Dashboard Volontario — Calcolo corretto statistiche di turno.
     */
    public function test_volunteer_dashboard_queries_today_stats() {
        global $wpdb;
        $original_wpdb = $wpdb;

        $wpdb = $this->getMockBuilder(\stdClass::class)
            ->addMethods(array('prepare', 'get_var'))
            ->getMock();
        $wpdb->prefix = 'wp_';

        // Prepara risposte per get_var in sequenza: persone convalidate (5), gruppi (2), contanti (20.00), POS (30.00)
        $wpdb->method('prepare')->willReturn('PREPARED_QUERY');
        $wpdb->method('get_var')->willReturnOnConsecutiveCalls(5, 2, 20.00, 30.00);

        Functions\when('get_current_user_id')->justReturn(2);
        Functions\when('get_userdata')->justReturn((object)array('display_name' => 'Operatore FAI'));
        Functions\when('current_user_can')->justReturn(true);

        // Catturiamo l'output del rendering per verificare la presenza delle cifre e dei testi principali
        ob_start();
        dfn_render_volunteer_dashboard();
        $output = ob_get_clean();

        $this->assertStringContainsString('Benvenuto, Operatore FAI', $output);
        $this->assertStringContainsString('5', $output); // visitatori entrati
        $this->assertStringContainsString('2', $output); // gruppi scansionati
        $this->assertStringContainsString('20', $output); // cassa contanti (escl. wc_price formatting)
        $this->assertStringContainsString('30', $output); // cassa pos (escl. wc_price formatting)

        $wpdb = $original_wpdb;
    }
}
}

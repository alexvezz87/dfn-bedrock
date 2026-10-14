<?php

namespace DFN\Theme\Tests\Unit {

    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-database.php';
    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-roles-manager.php';
    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-notifications.php';
    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-cron.php';
    require_once dirname(dirname(__DIR__)) . '/inc/admin/dfn-promotions-admin.php';
    require_once dirname(dirname(__DIR__)) . '/inc/api/dfn-ajax-promotions.php';

    use PHPUnit\Framework\TestCase;
    use Brain\Monkey;
    use Brain\Monkey\Functions;

    class DFNPromotionsTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Monkey\setUp();

            Functions\stubs([
                '__'                   => fn($text) => $text,
                'esc_html__'           => fn($text) => $text,
                'esc_attr__'           => fn($text) => $text,
                'esc_html'             => fn($text) => $text,
                'esc_attr'             => fn($text) => $text,
                'esc_url'              => fn($text) => $text,
                'sanitize_text_field'  => fn($text) => trim((string)$text),
                'sanitize_textarea_field' => fn($text) => trim((string)$text),
                'sanitize_email'       => fn($text) => strtolower(trim((string)$text)),
                'is_email'             => function ($email) {
                    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                },
                'absint'               => fn($val) => abs((int)$val),
                'current_time'         => fn() => '2026-10-13 18:00:00',
                'get_bloginfo'         => fn() => 'Delegazione FAI Novara',
                'admin_url'            => fn($p = '') => 'https://example.com/wp-admin/' . $p,
                'wp_kses_post'         => fn($text) => $text,
                'wpautop'              => fn($text) => $text,
                'wp_trim_words'        => fn($text, $num = 55, $more = null) => $text,
                'wp_strip_all_tags'    => fn($text) => strip_tags((string)$text),
                'date_i18n'            => fn($format, $timestamp) => date($format, $timestamp),
                'get_current_user_id'  => fn() => 1,
                'sanitize_title'       => fn($text) => preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower((string)$text)),
                'get_option'           => fn($opt, $default = false) => $default,
                'current_user_can'     => fn($cap) => true,
                'get_the_title'        => fn($pid = 0) => 'Evento Test',
                'get_permalink'        => fn($pid = 0) => 'https://example.com/evento-test/',
                'get_the_post_thumbnail_url' => fn($pid = 0, $size = '') => '',
                'get_post_meta'        => fn($post_id, $key = '', $single = false) => '',
                'update_post_meta'     => fn($post_id, $key, $value, $prev_value = '') => true,
                'dfn_get_setting'      => fn($key, $default = null) => $default,
                'add_query_arg'        => function ($args, $url = '') {
                    $sep = strpos($url, '?') !== false ? '&' : '?';
                    return $url . $sep . http_build_query($args);
                },
            ]);
        }

        protected function tearDown(): void
        {
            Monkey\tearDown();
            parent::tearDown();
        }

        /**
         * Test 1: dfn_promotions_get_eligible_events esclude eventi con date passate (< CURDATE()).
         */
        public function testEligibleEventsQueryIncludesFutureFilter(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            Functions\when('current_user_can')->justReturn(true);

            // Verifica che la query SQL contenga COALESCE(event_date_end, event_date_start) >= CURDATE()
            $wpdb->shouldReceive('get_results')
                ->once()
                ->with(\Mockery::on(function ($sql) {
                    return strpos($sql, 'COALESCE(event_date_end, event_date_start) >= CURDATE()') !== false
                        && strpos($sql, "status = 'published'") !== false;
                }))
                ->andReturn([
                    (object) [
                        'id' => 10,
                        'product_id' => 101,
                        'event_date_start' => '2026-11-20',
                        'event_date_end' => '2026-11-21',
                        'location' => 'Castello Sforzesco',
                        'city' => 'Galliate',
                    ]
                ]);

            Functions\when('get_the_title')->justReturn('Visita al Castello');
            Functions\when('get_permalink')->justReturn('https://example.com/evento/101');
            Functions\when('get_the_post_thumbnail_url')->justReturn('https://example.com/cover.jpg');

            $events = dfn_promotions_get_eligible_events(true);

            $this->assertCount(1, $events);
            $this->assertEquals('Visita al Castello', $events[0]->title);
            $this->assertEquals('https://example.com/cover.jpg', $events[0]->image_url);
        }

        /**
         * Test 2: Esclusione rigorosa di utenti già prenotati per l'evento target e deduplicazione.
         */
        public function testTargetAudienceExcludesAlreadyBookedCustomers(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';
            $wpdb->posts = 'wp_posts';
            $wpdb->postmeta = 'wp_postmeta';
            $wpdb->users = 'wp_users';

            $target_event_id = 42;

            // Mock prepare per excluded emails
            $wpdb->shouldReceive('prepare')
                ->andReturnUsing(function () {
                    $args = func_get_args();
                    return vsprintf(str_replace('%d', '%s', $args[0]), array_slice($args, 1));
                });

            // 1. Già prenotati per questo evento: mario@rossi.it
            $wpdb->shouldReceive('get_col')
                ->once()
                ->andReturn(['mario@rossi.it']);

            // 2. Clienti storici da wp_dfn_bookings:
            // - mario@rossi.it (deve essere escluso)
            // - luigi@verdi.it (deve essere incluso)
            // - no-email@dfn.it (deve essere escluso perché email di sistema)
            $wpdb->shouldReceive('get_results')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'wp_dfn_bookings') !== false))
                ->once()
                ->andReturn([
                    (object) ['customer_email' => 'mario@rossi.it', 'customer_name' => 'Mario Rossi'],
                    (object) ['customer_email' => 'luigi@verdi.it', 'customer_name' => 'Luigi Verdi'],
                    (object) ['customer_email' => 'no-email@dfn.it', 'customer_name' => 'Anonimo'],
                ]);

            // 3. Clienti da ordini WooCommerce:
            // - luigi@verdi.it (duplicato da non ripetere)
            // - anna@bianchi.it (nuovo cliente WC, da includere)
            $wpdb->shouldReceive('get_results')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'pm_email') !== false))
                ->once()
                ->andReturn([
                    (object) ['email' => 'luigi@verdi.it', 'first_name' => 'Luigi', 'last_name' => 'Verdi'],
                    (object) ['email' => 'anna@bianchi.it', 'first_name' => 'Anna', 'last_name' => 'Bianchi'],
                ]);

            // 4. Soci registrati da wp_dfn_fai_members:
            // - mario@rossi.it (già escluso per prenotazione attiva)
            // - carla@neri.it (nuova socia FAI, da includere)
            $wpdb->shouldReceive('get_results')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'wp_dfn_fai_members') !== false))
                ->once()
                ->andReturn([
                    (object) ['email' => 'mario@rossi.it', 'first_name' => 'Mario', 'last_name' => 'Rossi'],
                    (object) ['email' => 'carla@neri.it', 'first_name' => 'Carla', 'last_name' => 'Neri'],
                ]);

            // 5. Utenti registrati in WordPress
            $wpdb->shouldReceive('get_results')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'wp_users') !== false))
                ->once()
                ->andReturn([]);

            $audience = dfn_promotions_get_target_audience($target_event_id);

            // Destinatari attesi: luigi@verdi.it, anna@bianchi.it, carla@neri.it (3 destinatari unici)
            $this->assertCount(3, $audience);

            $emails = array_column($audience, 'email');
            $this->assertContains('luigi@verdi.it', $emails);
            $this->assertContains('anna@bianchi.it', $emails);
            $this->assertContains('carla@neri.it', $emails);

            // Mario Rossi DEVE essere escluso perché ha già prenotato per l'evento 42
            $this->assertNotContains('mario@rossi.it', $emails);

            // no-email@dfn.it DEVE essere escluso
            $this->assertNotContains('no-email@dfn.it', $emails);
        }

        /**
         * Test 3: Generazione template HTML promozionale.
         */
        public function testBuildEmailHtmlGeneratesBrandedCard(): void
        {
            Functions\when('dfn_get_setting')->alias(function ($key, $default = '') {
                if ($key === 'email_primary_color') return '#004b23';
                if ($key === 'email_accent_color') return '#e74f30';
                if ($key === 'delegation_name') return 'FAI Delegazione Novara';
                return $default;
            });

            $promotion = (object) [
                'email_subject'     => 'Scopri il nuovo evento!',
                'email_intro'       => 'Ti presentiamo una bellissima novità.',
                'email_custom_text' => 'Posti limitati, prenota ora.',
                'cta_text'          => 'Prenota il tuo posto',
            ];

            $event = (object) [
                'id'               => 15,
                'title'            => 'Visita Guidata al Broletto',
                'permalink'        => 'https://example.com/evento/broletto',
                'event_date_start' => '2026-10-30',
                'event_date_end'   => '2026-10-30',
                'event_time_start' => '10:00:00',
                'event_time_end'   => '12:00:00',
                'location'         => 'Via Fratelli Rosselli',
                'city'             => 'Novara',
                'image_url'        => 'https://example.com/broletto.jpg',
                'description'      => 'Percorso storico nel complesso del Broletto.',
            ];

            $html = dfn_promotions_build_email_html($promotion, 'Giulia Bianchi', $event);

            $this->assertStringContainsString('Gentile <strong>Giulia Bianchi</strong>', $html);
            $this->assertStringContainsString('Visita Guidata al Broletto', $html);
            $this->assertStringContainsString('Novara', $html);
            $this->assertStringContainsString('https://example.com/broletto.jpg', $html);
            $this->assertStringContainsString('https://example.com/evento/broletto', $html);
            $this->assertStringContainsString('Prenota il tuo posto', $html);
            $this->assertStringContainsString('FAI Delegazione Novara', $html);
        }

        /**
         * Test 4: Se il modulo promozioni è disattivato da impostazioni, process_queue_batch restituisce disabled.
         */
        public function testProcessQueueBatchRespectsFeatureToggle(): void
        {
            Functions\when('dfn_get_setting')->alias(function ($key, $default = '') {
                if ($key === 'enable_event_promotions') return 'no';
                return $default;
            });

            $result = dfn_promotions_process_queue_batch(25);

            $this->assertEquals('disabled', $result['status']);
            $this->assertEquals(0, $result['processed']);
        }

        /**
         * Test 5: process_queue_batch cerca campagne in stato sending o scheduled con scheduled_at <= NOW().
         */
        public function testProcessQueueBatchIncludesScheduledAtFilter(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            Functions\when('dfn_get_setting')->alias(function ($key, $default = '') {
                if ($key === 'enable_event_promotions') return 'yes';
                return $default;
            });

            // Verifica che la query cerchi status = 'sending' OR (status = 'scheduled' AND scheduled_at <= NOW())
            $wpdb->shouldReceive('get_results')
                ->once()
                ->with(\Mockery::on(function ($sql) {
                    return strpos($sql, "status = 'sending'") !== false
                        && strpos($sql, "status = 'scheduled' AND scheduled_at <= NOW()") !== false;
                }))
                ->andReturn([]);

            $result = dfn_promotions_process_queue_batch(25);
            $this->assertEquals('idle', $result['status']);
        }

        /**
         * Test 6: dfn_db_get_promotion_queue_counts include il conteggio per lo stato 'cancelled' e le interazioni.
         */
        public function testPromotionQueueCountsIncludesCancelledStatus(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $wpdb->shouldReceive('prepare')
                ->andReturnUsing(function () {
                    $args = func_get_args();
                    return vsprintf(str_replace('%d', '%s', $args[0]), array_slice($args, 1));
                });

            $wpdb->shouldReceive('get_results')
                ->once()
                ->andReturn([
                    (object) ['status' => 'sent', 'cnt' => 15],
                    (object) ['status' => 'pending', 'cnt' => 0],
                    (object) ['status' => 'cancelled', 'cnt' => 35],
                ]);

            $wpdb->shouldReceive('get_row')
                ->once()
                ->andReturn((object) [
                    'opens'         => 8,
                    'clicks'        => 4,
                    'conversions'   => 2,
                    'total_revenue' => 50.0,
                ]);

            $counts = dfn_db_get_promotion_queue_counts(42);

            $this->assertArrayHasKey('cancelled', $counts);
            $this->assertEquals(35, $counts['cancelled']);
            $this->assertEquals(15, $counts['sent']);
            $this->assertEquals(50, $counts['total']);
            $this->assertEquals(8, $counts['opened']);
            $this->assertEquals(4, $counts['clicked']);
            $this->assertEquals(2, $counts['converted']);
            $this->assertEquals(50.0, $counts['revenue']);
        }

        /**
         * Test 7: dfn_is_valid_promotional_email esclude rigorosamente email fittizie, botteghino e domini .local / example.
         */
        public function testIsValidPromotionalEmailExcludesDummiesAndLocalDomains(): void
        {
            // Email fittizie da escludere
            $this->assertFalse(dfn_is_valid_promotional_email('no-email-1789931572@dfn.local'));
            $this->assertFalse(dfn_is_valid_promotional_email('no-email@dfn.it'));
            $this->assertFalse(dfn_is_valid_promotional_email('autorita_1776167621@fainovara.local'));
            $this->assertFalse(dfn_is_valid_promotional_email('cassa_9968@fainovara.local'));
            $this->assertFalse(dfn_is_valid_promotional_email('cassa-1234@dfn.local'));
            $this->assertFalse(dfn_is_valid_promotional_email('testbooking@example.com'));
            $this->assertFalse(dfn_is_valid_promotional_email('mario.rossi@example.com'));
            $this->assertFalse(dfn_is_valid_promotional_email('user@test.local'));
            $this->assertFalse(dfn_is_valid_promotional_email('invalid-email-format'));
            $this->assertFalse(dfn_is_valid_promotional_email(''));

            // Email reali e legittime da accettare
            $this->assertTrue(dfn_is_valid_promotional_email('lisatestaquatra@gmail.com'));
            $this->assertTrue(dfn_is_valid_promotional_email('mario.rossi@libero.it'));
            $this->assertTrue(dfn_is_valid_promotional_email('volontario@fondoambiente.it'));
            $this->assertTrue(dfn_is_valid_promotional_email('prenotazioni@fai.novara.it'));
        }

        /**
         * Test 8: Iniezione del token di tracciamento e pixel 1x1 nell'HTML dell'email.
         */
        public function testBuildEmailHtmlInjectsTrackingTokenAndPixel(): void
        {
            Functions\when('dfn_get_setting')->alias(function ($key, $default = '') {
                if ($key === 'email_primary_color') return '#004b23';
                if ($key === 'delegation_name') return 'FAI Delegazione Novara';
                return $default;
            });

            $promotion = (object) [
                'email_subject'     => 'Anteprima Speciale',
                'email_intro'       => 'Ti invitiamo a questo evento.',
                'email_custom_text' => 'Posti limitati.',
                'cta_text'          => 'Prenota Subito',
            ];

            $event = (object) [
                'id'               => 15,
                'title'            => 'Visita al Broletto',
                'permalink'        => 'https://example.com/evento/broletto',
                'event_date_start' => '2026-10-30',
                'location'         => 'Broletto',
                'city'             => 'Novara',
                'image_url'        => 'https://example.com/broletto.jpg',
                'description'      => 'Descrizione',
            ];

            $token = 'tok_test1234567890abcdef12345678';
            $html = dfn_promotions_build_email_html($promotion, 'Mario Rossi', $event, $token);

            // Verifica che il link CTA sia il tracking URL con il token
            $this->assertStringContainsString('dfn_action=promo_click', $html);
            $this->assertStringContainsString('token=' . $token, $html);

            // Verifica presenza del tracking pixel 1x1
            $this->assertStringContainsString('dfn_action=promo_open', $html);
            $this->assertStringContainsString('width="1" height="1"', $html);
        }

        /**
         * Test 9: Registrazione apertura (record open) incrementa il contatore coda e campagna.
         */
        public function testRecordOpenUpdatesQueueAndPromotion(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $wpdb->shouldReceive('prepare')
                ->andReturnUsing(function () {
                    $args = func_get_args();
                    return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%s'], $args[0]), array_slice($args, 1));
                });

            // Prima apertura per questo destinatario
            $wpdb->shouldReceive('get_row')
                ->once()
                ->andReturn((object) [
                    'id'           => 101,
                    'promotion_id' => 5,
                    'opened_at'    => null,
                    'open_count'   => 0,
                ]);

            // Query di update coda e update promozione
            $wpdb->shouldReceive('query')
                ->twice()
                ->andReturn(1);

            $result = dfn_promotions_record_open('valid_token_123');
            $this->assertTrue($result);
        }

        /**
         * Test 10: Registrazione click CTA restituisce URL evento arricchito di tag UTM per Google Analytics 4.
         */
        public function testRecordClickReturnsUtmEnrichedDestinationUrl(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $wpdb->shouldReceive('prepare')
                ->andReturnUsing(function () {
                    $args = func_get_args();
                    return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%s'], $args[0]), array_slice($args, 1));
                });

            Functions\when('get_permalink')->justReturn('https://example.com/evento/autunno');

            $wpdb->shouldReceive('get_row')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'tracking_token') !== false))
                ->once()
                ->andReturn((object) [
                    'id'           => 202,
                    'promotion_id' => 8,
                    'clicked_at'   => null,
                    'click_count'  => 0,
                    'opened_at'    => null,
                    'promo_title'  => 'Giornate FAI Autunno',
                    'product_id'   => 500,
                ]);

            $wpdb->shouldReceive('query')
                ->times(3)
                ->andReturn(1);

            $click_data = dfn_promotions_record_click('click_token_xyz');

            $this->assertNotNull($click_data);
            $this->assertEquals(8, $click_data['promotion_id']);
            $this->assertStringContainsString('https://example.com/evento/autunno', $click_data['target_url']);
            $this->assertStringContainsString('utm_source=dfn_newsletter', $click_data['target_url']);
            $this->assertStringContainsString('utm_medium=email', $click_data['target_url']);
            $this->assertStringContainsString('utm_campaign=giornate_fai_autunno', $click_data['target_url']);
        }

        /**
         * Test 11: Calcolo metriche analytics promozionali (Open Rate, CTR, Conversion Rate).
         */
        public function testPromotionAnalyticsCalculatesRatesAccurately(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $wpdb->shouldReceive('prepare')
                ->andReturnUsing(function () {
                    $args = func_get_args();
                    return vsprintf(str_replace('%d', '%s', $args[0]), array_slice($args, 1));
                });

            $wpdb->shouldReceive('get_row')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'FROM wp_dfn_promotions') !== false))
                ->once()
                ->andReturn((object) [
                    'id'                => 12,
                    'sent_count'        => 100,
                    'opened_count'      => 40,
                    'clicked_count'     => 20,
                    'converted_count'   => 5,
                    'revenue_generated' => 150.50,
                ]);

            $wpdb->shouldReceive('get_row')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'FROM wp_dfn_promotion_queue') !== false))
                ->once()
                ->andReturn((object) [
                    'opens'         => 40,
                    'clicks'        => 20,
                    'conversions'   => 5,
                    'total_revenue' => 150.50,
                ]);

            $analytics = dfn_db_get_promotion_analytics(12);

            $this->assertEquals(100, $analytics['sent_count']);
            $this->assertEquals(40, $analytics['opened_count']);
            $this->assertEquals(20, $analytics['clicked_count']);
            $this->assertEquals(5, $analytics['converted_count']);
            $this->assertEquals(150.50, $analytics['revenue_generated']);
            $this->assertEquals(40.0, $analytics['open_rate']); // 40 / 100 = 40%
            $this->assertEquals(50.0, $analytics['ctr']);       // 20 / 40 = 50%
            $this->assertEquals(5.0, $analytics['conversion_rate']); // 5 / 100 = 5%
        }

        /**
         * Test 12: Attribuzione ordine WooCommerce alla campagna via token di tracciamento.
         */
        public function testAttributeOrderDirectTokenAttribution(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $wpdb->shouldReceive('prepare')
                ->andReturnUsing(function () {
                    $args = func_get_args();
                    return vsprintf(str_replace(['%s', '%d', '%f'], ["'%s'", '%s', '%s'], $args[0]), array_slice($args, 1));
                });

            $order_id = 999;
            $order = \Mockery::mock('WC_Order');
            $order->shouldReceive('get_items')->andReturn([]);
            $order->shouldReceive('get_billing_email')->andReturn('acquirente@example.com');
            $order->shouldReceive('get_total')->andReturn(45.0);
            $order->shouldReceive('add_order_note')->once();

            Functions\when('wc_get_order')->justReturn($order);
            Functions\when('get_post_meta')->alias(function ($pid, $key, $single = false) {
                if ($key === '_dfn_promo_attributed') return false;
                if ($key === '_dfn_promo_att_token') return 'test_att_token_999';
                return '';
            });
            Functions\when('update_post_meta')->justReturn(true);

            $wpdb->shouldReceive('get_var')
                ->once()
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'SELECT event_id FROM wp_dfn_bookings') !== false))
                ->andReturn(10); // event_id

            // Priorità 1: ricerca per email acquirente@example.com non trova nulla (email diversa da quella registrata)
            $wpdb->shouldReceive('get_row')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'acquirente@example.com') !== false))
                ->once()
                ->andReturn(null);

            // Priorità 2: fallback su token 'test_att_token_999' trova la riga in coda
            $wpdb->shouldReceive('get_row')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'test_att_token_999') !== false))
                ->once()
                ->andReturn((object) [
                    'id'           => 303,
                    'promotion_id' => 15,
                    'event_id'     => 10,
                    'converted_at' => null,
                ]);

            $wpdb->shouldReceive('get_var')
                ->once()
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'SELECT id FROM wp_dfn_bookings') !== false))
                ->andReturn(123); // booking_id

            $wpdb->shouldReceive('update')
                ->once()
                ->with('wp_dfn_promotion_queue', \Mockery::on(function ($data) use ($order_id) {
                    return $data['order_id'] === $order_id && $data['booking_id'] === 123 && $data['order_total'] == 45.0;
                }), ['id' => 303])
                ->andReturn(1);

            $wpdb->shouldReceive('query')
                ->once()
                ->with(\Mockery::on(function ($sql) {
                    return strpos($sql, 'UPDATE wp_dfn_promotions') !== false
                        && strpos($sql, 'converted_count = converted_count + 1') !== false;
                }))
                ->andReturn(1);

            dfn_promotions_attribute_order($order_id);

            // Verifichiamo che la funzione termini senza errori e abbia invocato le query attese
            $this->assertTrue(true);
        }

        /**
         * Verifica che dfn_promotions_process_queue_batch non segni la campagna come completata
         * se rimangono ancora elementi con status 'pending' (evita race condition con wp-cron).
         */
        public function test_process_queue_batch_does_not_complete_if_pending_items_exist(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $promo = (object) [
                'id'                     => 1,
                'event_id'               => 3,
                'status'                 => 'sending',
                'batch_size'             => 25,
                'batch_interval_minutes' => 5,
                'last_batch_at'          => null,
            ];

            // 1. Trova promozioni in sending
            $wpdb->shouldReceive('get_results')
                ->once()
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'wp_dfn_promotions') !== false))
                ->andReturn([$promo]);

            // 1b. Mock eventi idonei
            $wpdb->shouldReceive('get_results')
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'wp_dfn_events') !== false))
                ->andReturn([(object) ['id' => 3, 'product_id' => 100]]);

            // 2. Query items in coda restituisce array vuoto (simula race condition)
            $wpdb->shouldReceive('prepare')
                ->andReturn('SQL_QUERY');
            $wpdb->shouldReceive('get_results')
                ->once()
                ->with('SQL_QUERY')
                ->andReturn([]);

            // 3. Verifica pendenti rimanenti: supponiamo ce ne siano 100 ancora in coda
            $wpdb->shouldReceive('get_var')
                ->once()
                ->with('SQL_QUERY')
                ->andReturn(100);

            // 4. NON deve chiamare update con status = completed!
            $wpdb->shouldNotReceive('update');

            $result = dfn_promotions_process_queue_batch(25);

            $this->assertEquals(0, $result['processed']);
            $this->assertEquals(0, $result['sent']);
            $this->assertEquals('idle', $result['status']);
        }

        /**
         * Test 14: dfn_db_get_promotion_queue e dfn_db_count_promotion_queue con ricerca e paginazione.
         */
        public function test_get_promotion_queue_and_count_with_search_and_pagination(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';
            $wpdb->shouldReceive('esc_like')->with('rossi')->andReturn('rossi');

            // Test count con ricerca
            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(
                    \Mockery::on(fn($sql) => strpos($sql, 'COUNT(*)') !== false && strpos($sql, 'recipient_email LIKE') !== false),
                    42,
                    '%rossi%',
                    '%rossi%'
                )
                ->andReturn('SQL_COUNT');

            $wpdb->shouldReceive('get_var')->once()->with('SQL_COUNT')->andReturn(15);

            $count = dfn_db_count_promotion_queue(42, '', 'rossi');
            $this->assertEquals(15, $count);

            // Test get queue con offset e limit
            $dummyItem = (object) [
                'id' => 10,
                'recipient_email' => 'mario.rossi@example.com',
                'recipient_name'  => 'Mario Rossi',
                'status' => 'sent',
            ];

            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(
                    \Mockery::on(fn($sql) => strpos($sql, 'LIMIT %d OFFSET %d') !== false),
                    42,
                    '%rossi%',
                    '%rossi%',
                    20,
                    40
                )
                ->andReturn('SQL_GET_PAGED');

            $wpdb->shouldReceive('get_results')->once()->with('SQL_GET_PAGED')->andReturn([$dummyItem]);

            $results = dfn_db_get_promotion_queue(42, 20, '', 'rossi', 40);
            $this->assertCount(1, $results);
            $this->assertEquals('mario.rossi@example.com', $results[0]->recipient_email);
        }

        /**
         * Test 15: dfn_promotions_attribute_order dà priorità all'email di fatturazione del destinatario
         * rispetto al cookie token residuo (fix condivisione PC o link inoltrati).
         */
        public function test_promotions_attribute_order_prioritizes_billing_email(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $order = \Mockery::mock('WC_Order');
            $order->shouldReceive('get_items')->andReturn([]);
            $order->shouldReceive('get_billing_email')->andReturn('stefano@example.com');
            $order->shouldReceive('get_total')->andReturn(30.0);
            $order->shouldReceive('add_order_note')->andReturn(true);

            Functions\when('wc_get_order')->alias(fn($id) => $id === 2446 ? $order : null);
            Functions\when('sanitize_email')->alias(fn($e) => trim($e));
            Functions\when('current_time')->alias(fn($type) => '2026-10-14 22:00:00');

            // Simula cookie token appartenente a un altro utente
            $_COOKIE['dfn_promo_att'] = 'TOKEN_DI_ALTRO_UTENTE';

            // 1. Trova l'evento collegato all'ordine tramite prenotazione nel DB
            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'SELECT event_id FROM wp_dfn_bookings') !== false), 2446)
                ->andReturn('SQL_FIND_EVENT');
            $wpdb->shouldReceive('get_var')->once()->with('SQL_FIND_EVENT')->andReturn(5);

            // 2. Query Priorità 1: ricerca per billing_email stefano@example.com e event_id 5
            $matchedQueueItem = (object) [
                'id'            => 470,
                'promotion_id'  => 2,
                'event_id'      => 5,
                'tracking_token'=> 'TOKEN_DI_STEFANO',
                'recipient_email' => 'stefano@example.com',
                'status'        => 'sent',
                'converted_at'  => null,
                'order_total'   => 0.0,
            ];

            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(
                    \Mockery::on(fn($sql) => strpos($sql, 'LOWER(q.recipient_email)') !== false),
                    'stefano@example.com',
                    5
                )
                ->andReturn('SQL_FIND_QUEUE_BY_EMAIL');

            $wpdb->shouldReceive('get_row')->once()->with('SQL_FIND_QUEUE_BY_EMAIL')->andReturn($matchedQueueItem);

            // Trova booking_id se presente
            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(\Mockery::on(fn($sql) => strpos($sql, 'SELECT id FROM wp_dfn_bookings') !== false), 2446)
                ->andReturn('SQL_FIND_BOOKING_ID');
            $wpdb->shouldReceive('get_var')->once()->with('SQL_FIND_BOOKING_ID')->andReturn(77);

            // 3. Update su wp_dfn_promotion_queue
            $wpdb->shouldReceive('update')
                ->once()
                ->with(
                    'wp_dfn_promotion_queue',
                    \Mockery::on(function ($data) {
                        return $data['order_id'] === 2446 && $data['order_total'] == 30.0 && $data['booking_id'] === 77;
                    }),
                    ['id' => 470]
                );

            // 4. Update su wp_dfn_promotions (incrementa converted_count e revenue)
            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(
                    \Mockery::on(fn($sql) => strpos($sql, 'converted_count = converted_count + 1') !== false),
                    30.0,
                    2
                )
                ->andReturn('SQL_UPDATE_PROMO_TOTALS');

            $wpdb->shouldReceive('query')->once()->with('SQL_UPDATE_PROMO_TOTALS')->andReturn(1);

            dfn_promotions_attribute_order(2446);

            $this->assertTrue(true);

            // Pulizia cookie
            unset($_COOKIE['dfn_promo_att']);
        }
    }
}


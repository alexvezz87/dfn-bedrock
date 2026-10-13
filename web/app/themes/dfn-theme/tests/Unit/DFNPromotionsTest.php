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
         * Test 6: dfn_db_get_promotion_queue_counts include il conteggio per lo stato 'cancelled'.
         */
        public function testPromotionQueueCountsIncludesCancelledStatus(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $wpdb->shouldReceive('prepare')
                ->once()
                ->with(\Mockery::any(), 42)
                ->andReturn("SELECT status, COUNT(*) as cnt FROM wp_dfn_promotion_queue WHERE promotion_id = 42 GROUP BY status");

            $wpdb->shouldReceive('get_results')
                ->once()
                ->andReturn([
                    (object) ['status' => 'sent', 'cnt' => 15],
                    (object) ['status' => 'pending', 'cnt' => 0],
                    (object) ['status' => 'cancelled', 'cnt' => 35],
                ]);

            $counts = dfn_db_get_promotion_queue_counts(42);

            $this->assertArrayHasKey('cancelled', $counts);
            $this->assertEquals(35, $counts['cancelled']);
            $this->assertEquals(15, $counts['sent']);
            $this->assertEquals(50, $counts['total']);
        }
    }
}

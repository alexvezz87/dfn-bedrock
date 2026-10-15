<?php

namespace DFN\Theme\Tests\Unit {

    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-database.php';
    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-helpers.php';
    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-roles-manager.php';
    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-notifications.php';
    require_once dirname(dirname(__DIR__)) . '/inc/core/dfn-cron.php';

    use PHPUnit\Framework\TestCase;
    use Brain\Monkey;
    use Brain\Monkey\Functions;

    class DFNMeetingRemindersTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            Monkey\setUp();

            if (! defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
            if (! defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
            if (! defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

            Functions\stubs([
                '__'                   => fn($text) => $text,
                'esc_html__'           => fn($text) => $text,
                'esc_attr__'           => fn($text) => $text,
                'esc_html'             => fn($text) => $text,
                'esc_attr'             => fn($text) => $text,
                'esc_url'              => fn($text) => $text,
                'sanitize_text_field'  => fn($text) => trim((string)$text),
                'sanitize_email'       => fn($text) => strtolower(trim((string)$text)),
                'is_email'             => fn($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                'absint'               => fn($val) => abs((int)$val),
                'current_time'         => function($type = 'mysql') {
                    if ($type === 'Y-m-d') return '2026-10-15';
                    if ($type === 'timestamp') return strtotime('2026-10-15 10:00:00');
                    return '2026-10-15 10:00:00';
                },
                'date_i18n'            => fn($format, $timestamp) => date($format, $timestamp),
                'get_option'           => fn($opt, $default = false) => $default,
                'get_transient'        => fn($name) => false,
                'set_transient'        => fn($name, $val, $exp = 0) => true,
                'delete_transient'     => fn($name) => true,
            ]);
        }

        protected function tearDown(): void
        {
            Monkey\tearDown();
            parent::tearDown();
        }

        public function test_sanitize_reminder_days_cleans_and_sorts_descending(): void
        {
            $input = '1, 7, 30, 7, -5, 0, abc';
            $sanitized = dfn_sanitize_reminder_days($input);
            $this->assertSame([30, 7, 1], $sanitized);

            $array_input = [14, '2', 2, 7];
            $sanitized_array = dfn_sanitize_reminder_days($array_input);
            $this->assertSame([14, 7, 2], $sanitized_array);

            $empty_input = '';
            $this->assertSame([30, 7, 1], dfn_sanitize_reminder_days($empty_input));
        }

        public function test_get_plenary_meeting_reminder_days_reads_setting(): void
        {
            Functions\when('dfn_get_volunteer_setting')
                ->justReturn('45, 15, 3');

            $days = dfn_get_plenary_meeting_reminder_days();
            $this->assertSame([45, 15, 3], $days);
        }

        public function test_get_team_meeting_reminder_days_inherits_or_customizes(): void
        {
            $team_inherit = (object) [
                'id'                    => 1,
                'name'                  => 'Team Comunicazione',
                'reminder_cadence_mode' => 'inherit',
                'reminder_days'         => '',
            ];

            $team_custom = (object) [
                'id'                    => 2,
                'name'                  => 'Team Ambiente',
                'reminder_cadence_mode' => 'custom',
                'reminder_days'         => '15, 7, 3, 1',
            ];

            Functions\when('dfn_get_team')->alias(function($id) use ($team_inherit, $team_custom) {
                if ($id === 1) return $team_inherit;
                if ($id === 2) return $team_custom;
                return null;
            });

            Functions\when('dfn_get_volunteer_setting')
                ->justReturn('30, 7, 1');

            // Team 1 eredita
            $this->assertSame([30, 7, 1], dfn_get_team_meeting_reminder_days(1));

            // Team 2 personalizza
            $this->assertSame([15, 7, 3, 1], dfn_get_team_meeting_reminder_days(2));
        }

        public function test_get_meeting_reminder_days_resolves_meeting_scope(): void
        {
            $plenary_meeting = (object) [
                'id'      => 10,
                'title'   => 'Plenaria Autunno',
                'team_id' => 0,
            ];

            $team_meeting = (object) [
                'id'      => 11,
                'title'   => 'Riunione Guide',
                'team_id' => 2,
            ];

            Functions\when('dfn_get_team')->alias(function($id) {
                if ($id === 2) {
                    return (object) [
                        'id'                    => 2,
                        'name'                  => 'Team Guide',
                        'reminder_cadence_mode' => 'custom',
                        'reminder_days'         => '10, 2',
                    ];
                }
                return null;
            });

            Functions\when('dfn_get_volunteer_setting')
                ->justReturn('30, 7, 1');

            $this->assertSame([30, 7, 1], dfn_get_meeting_reminder_days($plenary_meeting));
            $this->assertSame([10, 2], dfn_get_meeting_reminder_days($team_meeting));
        }

        public function test_cron_sends_due_reminders_and_respects_already_sent(): void
        {
            global $wpdb;
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';

            $table_meetings = 'wp_dfn_volunteer_meetings';
            $wpdb->shouldReceive('get_var')
                ->with("SHOW TABLES LIKE '{$table_meetings}'")
                ->andReturn($table_meetings);

            // Today is 2026-10-15
            // Meeting 1: Plenaria, 7 days away (2026-10-22), reminder not sent -> SHOULD SEND 7d
            // Meeting 2: Team 2 (custom [15, 7, 3, 1]), 15 days away (2026-10-30), reminder not sent -> SHOULD SEND 15d
            // Meeting 3: Plenaria ([30, 7, 1]), 15 days away (2026-10-30) -> SHOULD NOT SEND (15 not in [30, 7, 1])
            // Meeting 4: Plenaria, 7 days away (2026-10-22), reminders_sent already has '7' -> SHOULD NOT SEND
            $m1 = (object) [
                'id'               => 101,
                'title'            => 'Plenaria Prossima',
                'meeting_date'     => '2026-10-22',
                'team_id'          => 0,
                'reminders_sent'   => null,
                'reminder_7d_sent' => 0,
                'reminder_1d_sent' => 0,
            ];

            $m2 = (object) [
                'id'               => 102,
                'title'            => 'Riunione Team Ambiente',
                'meeting_date'     => '2026-10-30',
                'team_id'          => 2,
                'reminders_sent'   => null,
                'reminder_7d_sent' => 0,
                'reminder_1d_sent' => 0,
            ];

            $m3 = (object) [
                'id'               => 103,
                'title'            => 'Plenaria Lontana',
                'meeting_date'     => '2026-10-30',
                'team_id'          => 0,
                'reminders_sent'   => null,
                'reminder_7d_sent' => 0,
                'reminder_1d_sent' => 0,
            ];

            $m4 = (object) [
                'id'               => 104,
                'title'            => 'Plenaria Già Inviata',
                'meeting_date'     => '2026-10-22',
                'team_id'          => 0,
                'reminders_sent'   => json_encode(['7' => '2026-10-15 08:00:00']),
                'reminder_7d_sent' => 1,
                'reminder_1d_sent' => 0,
            ];

            $wpdb->shouldReceive('prepare')
                ->andReturn("SELECT ... query ...");

            $wpdb->shouldReceive('get_results')
                ->andReturn([$m1, $m2, $m3, $m4]);

            Functions\when('dfn_get_volunteer_setting')->alias(function($k, $def = '') {
                if ($k === 'vol_enable_meeting_reminders') return 'yes';
                if ($k === 'vol_enable_teams') return 'yes';
                if ($k === 'vol_meeting_reminder_days') return '30, 7, 1';
                return $def;
            });

            Functions\when('dfn_get_team')->alias(function($id) {
                if ($id === 2) {
                    return (object) [
                        'id'                    => 2,
                        'name'                  => 'Team Ambiente',
                        'reminder_cadence_mode' => 'custom',
                        'reminder_days'         => '15, 7, 3, 1',
                    ];
                }
                return null;
            });

            $sent_notifications = [];
            Functions\when('dfn_send_volunteer_meeting_notification')->alias(function($meeting_id, $is_reminder, $type) use (&$sent_notifications) {
                $sent_notifications[] = [
                    'meeting_id'  => $meeting_id,
                    'is_reminder' => $is_reminder,
                    'type'        => $type,
                ];
                return ['success' => true];
            });

            dfn_cron_invia_promemoria_riunioni_volontari();

            $this->assertCount(2, $sent_notifications);
            $this->assertEquals([
                ['meeting_id' => 101, 'is_reminder' => true, 'type' => '7d'],
                ['meeting_id' => 102, 'is_reminder' => true, 'type' => '15d'],
            ], $sent_notifications);
        }
    }
}

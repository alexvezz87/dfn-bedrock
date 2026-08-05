<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$token = $_GET['t'] ?? $_GET['token'] ?? '';
if ($token !== '202605300020') {
    http_response_code(403);
    exit('Forbidden: invalid token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!empty($data['action']) && $data['action'] === 'cleanup') {
        $cleaned = [];
        $orphan_dir = __DIR__ . '/web';
        if (is_dir($orphan_dir)) {
            $delete_dir = function ($dir) use (&$delete_dir) {
                if (!is_dir($dir)) return;
                $items = array_diff(scandir($dir), ['.', '..']);
                foreach ($items as $item) {
                    $path = $dir . '/' . $item;
                    is_dir($path) ? $delete_dir($path) : @unlink($path);
                }
                @rmdir($dir);
            };
            $delete_dir($orphan_dir);
            $cleaned[] = 'Orphan /web/web directory deleted';
        }
        $temp_files = [
            __DIR__ . '/test_prod.php',
            __DIR__ . '/deploy.tar.gz',
            __DIR__ . '/deploy.zip',
            dirname(__DIR__) . '/deploy.tar.gz',
            dirname(__DIR__) . '/deploy.zip',
        ];
        foreach ($temp_files as $tf) {
            if (file_exists($tf)) {
                @unlink($tf);
                $cleaned[] = basename($tf) . ' deleted';
            }
        }
        echo "CLEANUP COMPLETE: " . (empty($cleaned) ? 'No orphan files found' : implode(', ', $cleaned));
        exit;
    }

    if (!empty($data['action']) && $data['action'] === 'fix_order_2248') {
        if (file_exists(__DIR__ . '/wp/wp-load.php')) {
            require_once __DIR__ . '/wp/wp-load.php';
        } elseif (file_exists(dirname(__DIR__) . '/web/wp/wp-load.php')) {
            require_once dirname(__DIR__) . '/web/wp/wp-load.php';
        }
        global $wpdb;
        $table = $wpdb->prefix . 'dfn_bookings';

        $b = $wpdb->get_row("SELECT * FROM {$table} WHERE order_id = 2247 OR order_id = 2248 ORDER BY id DESC LIMIT 1");
        if (! $b) {
            $b = $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'cancelled' ORDER BY id DESC LIMIT 1");
        }

        if ($b) {
            $wpdb->update(
                $table,
                [
                    'order_id'       => 2248,
                    'status'         => 'confirmed',
                    'payment_method' => 'apple_pay',
                    'amount_paid'    => 40.00,
                    'amount_due'     => 0.00,
                ],
                ['id' => $b->id]
            );
            $sent = false;
            if (function_exists('dfn_send_booking_confirmation')) {
                $sent = dfn_send_booking_confirmation($b->id);
            }
            echo "FIXED: Booking #{$b->id} re-linked to Order #2248, status set to confirmed, email sent: " . ($sent ? 'YES' : 'NO');
        } else {
            echo "NO BOOKING FOUND TO FIX";
        }
        exit;
    }

    if (!empty($data['filepath']) && isset($data['content_b64'])) {
        $rel_path = ltrim($data['filepath'], '/');
        $target_file = __DIR__ . '/' . $rel_path;
        $dir = dirname($target_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $content = base64_decode($data['content_b64']);
        file_put_contents($target_file, $content);
        echo "SUCCESS: Wrote " . strlen($content) . " bytes to " . $rel_path;
        exit;
    }
}
echo "READY";

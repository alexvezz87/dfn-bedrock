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

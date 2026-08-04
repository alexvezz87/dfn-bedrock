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

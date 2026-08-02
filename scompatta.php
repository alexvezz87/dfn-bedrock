<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120);

$token = $_GET['t'] ?? $_GET['token'] ?? '';
if ($token !== '202605300020') {
    http_response_code(403);
    exit('Forbidden: invalid token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!empty($data['archive_b64'])) {
        $tar_content = base64_decode($data['archive_b64']);
        file_put_contents(__DIR__ . '/deploy.tar.gz', $tar_content);
    }
}

echo "<html><head><title>Scompattatore Bedrock</title></head><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";

$archive = file_exists(__DIR__ . '/deploy.tar.gz') ? __DIR__ . '/deploy.tar.gz' : null;

if ($archive) {
    echo "<p>Trovato $archive. Avvio estrazione...</p>";
    
    $target_dir = file_exists(__DIR__ . '/web') ? __DIR__ . '/web' : __DIR__;
    $command = "tar -xzf " . escapeshellarg($archive) . " -C " . escapeshellarg($target_dir) . " 2>&1";
    
    if (function_exists('exec')) {
        $out = [];
        $ret = 0;
        exec($command, $out, $ret);
        @unlink($archive);
        echo "<p style='color: green; font-size: 1.1em;'><strong>Estrazione completata! (Exit code $ret)</strong></p>";
        echo "<pre>" . esc_html(implode("\n", $out)) . "</pre>";
    } else {
        echo "<p style='color: red;'>exec() non abilitato.</p>";
    }
} else {
    echo "<p style='color: red;'>File deploy.tar.gz non trovato.</p>";
}

echo "</body></html>";

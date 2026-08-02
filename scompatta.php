<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120);

// Secret token for security
$token = $_GET['t'] ?? $_GET['token'] ?? '';
if ($token !== '202605300020') {
    http_response_code(403);
    exit('Forbidden: invalid token');
}

// Se riceve POST con JSON Base64, salva deploy.tar.gz prima di scompattare
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!empty($data['archive_b64'])) {
        $tar_content = base64_decode($data['archive_b64']);
        file_put_contents(__DIR__ . '/deploy.tar.gz', $tar_content);
        if (file_exists(__DIR__ . '/web')) {
            file_put_contents(__DIR__ . '/web/deploy.tar.gz', $tar_content);
        }
    }
}

echo "<html><head><title>Scompattatore Bedrock Background</title></head><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";
echo "<h1 style='color: #2c3e50;'>🚀 Estrazione avviata...</h1>";

// Controlla sia nella cartella corrente che in subfolder web/
$archive = file_exists(__DIR__ . '/deploy.tar.gz') 
    ? __DIR__ . '/deploy.tar.gz' 
    : (file_exists(__DIR__ . '/web/deploy.tar.gz') ? __DIR__ . '/web/deploy.tar.gz' : null);

if ($archive) {
    echo "<p>Trovato $archive sul server. Avvio estrazione...</p>";
    
    $target_dir = file_exists(__DIR__ . '/web') ? __DIR__ : dirname(__DIR__);
    $command = "tar -xzf " . escapeshellarg($archive) . " -C " . escapeshellarg($target_dir) . " 2>&1";
    
    if (function_exists('exec')) {
        $out = [];
        $ret = 0;
        exec($command, $out, $ret);
        @unlink($archive);
        if (file_exists(__DIR__ . '/web/deploy.tar.gz')) @unlink(__DIR__ . '/web/deploy.tar.gz');
        if (file_exists(dirname(__DIR__) . '/deploy.tar.gz')) @unlink(dirname(__DIR__) . '/deploy.tar.gz');
        
        echo "<p style='color: green; font-size: 1.1em;'><strong>Estrazione completata! Code exit: $ret</strong></p>";
        echo "<pre>" . esc_html(implode("\n", $out)) . "</pre>";
    } else {
        echo "<p style='color: red;'>Errore: exec() non è abilitato su questo server.</p>";
    }
} else {
    echo "<p style='color: red;'>Errore: Il file deploy.tar.gz non è stato trovato sul server.</p>";
}

echo "</body></html>";

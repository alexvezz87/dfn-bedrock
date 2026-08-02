<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 60);

echo "<html><head><title>Scompattatore Bedrock Background</title></head><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";
echo "<h1 style='color: #2c3e50;'>🚀 Estrazione avviata in background...</h1>";

$archive = file_exists('deploy.tar.gz') ? 'deploy.tar.gz' : (file_exists('../deploy.tar.gz') ? '../deploy.tar.gz' : null);

if ($archive) {
    echo "<p>Trovato $archive sul server. Avvio estrazione...</p>";
    
    $target_dir = file_exists('../web') ? '..' : '.';
    $command = "tar -xzf " . escapeshellarg($archive) . " -C " . escapeshellarg($target_dir) . " && rm -f " . escapeshellarg($archive) . " > /dev/null 2>&1 &";
    
    if (function_exists('exec')) {
        @exec($command);
        echo "<p style='color: green; font-size: 1.1em;'><strong>Estrazione avviata con successo!</strong></p>";
    } else {
        echo "<p style='color: red;'>Errore: exec() non è abilitato su questo server.</p>";
    }
} else {
    echo "<p style='color: red;'>Errore: Il file deploy.tar.gz non è stato trovato sul server.</p>";
}

echo "</body></html>";

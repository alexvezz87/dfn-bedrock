<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 60);

echo "<html><head><title>Scompattatore Bedrock Background</title></head><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";
echo "<h1 style='color: #2c3e50;'>🚀 Estrazione avviata in background...</h1>";

if (file_exists('deploy.tar.gz')) {
    echo "<p>Trovato deploy.tar.gz sul server. Avvio del processo di estrazione asincrono in background...</p>";
    
    // Comando concatenato: estrae in modo asincrono, cancella l'archivio e infine cancella questo stesso script PHP
    // Avviato in background con redirect per ritornare subito 200 OK alla chiamata web.
    $command = "tar -xf deploy.tar.gz && rm -f deploy.tar.gz scompatta.php > /dev/null 2>&1 &";
    
    if (function_exists('exec')) {
        @exec($command);
        echo "<p style='color: green; font-size: 1.1em;'><strong>Processo asincrono avviato con successo in background!</strong></p>";
        echo "<p>Il server completerà l'estrazione e la pulizia dei file nei prossimi 60-90 secondi senza rischiare alcun timeout web.</p>";
    } else {
        echo "<p style='color: red;'>Errore: exec() non è abilitato su questo server.</p>";
    }
} else {
    echo "<p style='color: red;'>Errore: Il file deploy.tar.gz non è stato trovato sul server.</p>";
}

echo "</body></html>";
?>

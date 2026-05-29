<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 600);

echo "<html><head><title>Scompattatore automatico Bedrock</title></head><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";
echo "<h1 style='color: #2c3e50;'>🚀 Estrazione di deploy...</h1>";

$extracted = false;

// 1. Prova ad estrarre deploy.zip se esiste
if (file_exists('deploy.zip')) {
    echo "<p>Trovato deploy.zip. Avvio estrazione ZIP...</p>";
    $zip = new ZipArchive;
    $res = $zip->open('deploy.zip');
    if ($res === TRUE) {
        $zip->extractTo(__DIR__);
        $zip->close();
        @unlink('deploy.zip');
        echo "<p style='color: green; font-size: 1.1em;'><strong>ZIP estratto con successo!</strong></p>";
        $extracted = true;
    } else {
        echo "<p style='color: red;'>Errore ZIP: Impossibile estrarre (Codice Errore: $res). Procedo con altri metodi...</p>";
    }
}

// 2. Prova ad estrarre deploy.tar se esiste (usando PharData nativo)
if (file_exists('deploy.tar')) {
    echo "<p>Trovato deploy.tar. Avvio estrazione TAR...</p>";
    try {
        $phar = new PharData('deploy.tar');
        $phar->extractTo(__DIR__, null, true); // true sovrascrive i file esistenti
        @unlink('deploy.tar');
        echo "<p style='color: green; font-size: 1.1em;'><strong>TAR estratto con successo!</strong></p>";
        $extracted = true;
    } catch (Exception $e) {
        echo "<p style='color: red;'>Errore TAR: " . $e->getMessage() . "</p>";
    }
}

if ($extracted) {
    echo "<p style='color: green; font-size: 1.2em;'><strong>Successo!</strong> Tutti i file sono stati estratti correttamente sul tuo server.</p>";
} else {
    echo "<p style='color: red; font-size: 1.2em;'><strong>Errore:</strong> Nessun file di deploy estratto con successo. Verifica che i file siano caricati correttamente.</p>";
}

// Autodistruzione dello script per sicurezza
@unlink(__FILE__);
echo "<p style='color: #d35400;'><strong>Sicurezza:</strong> Questo script si è autodistrutto con successo per proteggere il tuo sito.</p>";
echo "</body></html>";
?>

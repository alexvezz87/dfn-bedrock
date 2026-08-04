<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 120);

$token = $_GET['t'] ?? $_GET['token'] ?? '';
if ($token !== '202605300020') {
    http_response_code(403);
    exit('Forbidden: invalid token');
}

$target_dir = __DIR__;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!empty($data['archive_b64'])) {
        $archive_content = base64_decode($data['archive_b64']);
        file_put_contents(__DIR__ . '/deploy.zip', $archive_content);
    }
}

header('Content-Type: text/html; charset=utf-8');
echo "<html><head><title>Scompattatore Bedrock</title></head><body style='font-family: sans-serif; padding: 20px; line-height: 1.6;'>";

$archive = file_exists(__DIR__ . '/deploy.zip') ? __DIR__ . '/deploy.zip' : (file_exists(__DIR__ . '/deploy.tar.gz') ? __DIR__ . '/deploy.tar.gz' : null);

if ($archive) {
    echo "<p>Trovato $archive (" . filesize($archive) . " bytes). Avvio estrazione...</p>";
    $extracted = false;

    if (str_ends_with($archive, '.zip') && class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($archive) === true) {
            $zip->extractTo($target_dir);
            $zip->close();
            echo "<p style='color: green; font-weight: bold;'>SUCCESS: Estrazione ZipArchive completata in " . htmlspecialchars($target_dir) . "</p>";
            $extracted = true;
        } else {
            echo "<p style='color: red;'>Errore apertura ZipArchive.</p>";
        }
    }

    if (!$extracted && class_exists('PharData')) {
        try {
            $phar = new PharData($archive);
            if ($phar->isCompressed()) {
                $phar = $phar->decompress();
            }
            $phar->extractTo($target_dir, null, true);
            echo "<p style='color: green; font-weight: bold;'>SUCCESS: Estrazione PharData completata in " . htmlspecialchars($target_dir) . "</p>";
            $extracted = true;
        } catch (Exception $e) {
            echo "<p style='color: orange;'>Avviso PharData: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }

    if (!$extracted && function_exists('exec')) {
        $output = [];
        $return_var = 0;
        $command = "tar -xzf " . escapeshellarg($archive) . " -C " . escapeshellarg($target_dir) . " 2>&1";
        @exec($command, $output, $return_var);

        if ($return_var === 0) {
            echo "<p style='color: green; font-weight: bold;'>SUCCESS: Estrazione tar exec completata in " . htmlspecialchars($target_dir) . "</p>";
            $extracted = true;
        } else {
            echo "<p style='color: red;'>Errore tar exec (code $return_var): " . htmlspecialchars(implode("\n", $output)) . "</p>";
        }
    }

    if ($extracted) {
        @unlink($archive);
    }
} else {
    echo "<p style='color: red;'>File di archivio non trovato.</p>";
}

echo "</body></html>";

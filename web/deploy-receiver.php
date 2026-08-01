<?php
/**
 * deploy-receiver.php
 * Riceve il pacchetto di deploy via HTTPS POST ed estrae i file.
 * Posizionare nella cartella web/ (Document Root di Bedrock su Netsons).
 */

define('DEPLOY_TOKEN', '6kNQApo-1nSvRYQkbtgYi4dVD3BcbwhwRyTm4vZ8r3c');
define('EXTRACT_DIR', dirname(__DIR__)); // Un livello sopra web/ (root di Bedrock)
define('MAX_FILE_SIZE', 50 * 1024 * 1024);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$token = $_GET['token'] ?? $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden: invalid token');
}

if (!isset($_FILES['deploy_archive'])) {
    http_response_code(400);
    exit('No file uploaded');
}

$upload = $_FILES['deploy_archive'];
if ($upload['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('Upload error: ' . $upload['error']);
}

$dest = EXTRACT_DIR . '/deploy.tar.gz';
if (!move_uploaded_file($upload['tmp_name'], $dest)) {
    http_response_code(500);
    exit('Failed to save uploaded file');
}

$output = [];
$returnCode = 0;
exec('cd ' . escapeshellarg(EXTRACT_DIR) . ' && tar -xzf deploy.tar.gz 2>&1', $output, $returnCode);

@unlink($dest);

if ($returnCode !== 0) {
    http_response_code(500);
    echo 'Extraction failed: ' . implode("\n", $output);
    exit();
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'message' => 'Deploy completato con successo!']);

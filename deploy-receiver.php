<?php
/**
 * deploy-receiver.php
 * Riceve il pacchetto deploy da GitHub Actions via HTTPS POST.
 * Posizionare nella webroot (public_html o httpdocs).
 *
 * Token: impostare DEPLOY_TOKEN uguale al secret DEPLOY_TOKEN su GitHub.
 */

define('DEPLOY_TOKEN', getenv('DEPLOY_TOKEN') ?: 'CAMBIA_QUESTO_TOKEN_SEGRETO');
define('EXTRACT_DIR', dirname(__DIR__)); // Un livello sopra la webroot (la root di Bedrock)
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50 MB max

// --- Sicurezza ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$token = $_GET['token'] ?? $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden: invalid token');
}

// --- Ricezione file ---
if (!isset($_FILES['deploy_archive'])) {
    http_response_code(400);
    exit('No file uploaded');
}

$upload = $_FILES['deploy_archive'];
if ($upload['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    exit('Upload error: ' . $upload['error']);
}

if ($upload['size'] > MAX_FILE_SIZE) {
    http_response_code(413);
    exit('File too large');
}

// Verifica che sia effettivamente un .tar.gz
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($upload['tmp_name']);
if (!in_array($mime, ['application/gzip', 'application/x-gzip', 'application/x-tar'])) {
    http_response_code(400);
    exit('Invalid file type: ' . $mime);
}

// --- Estrazione ---
$dest = EXTRACT_DIR . '/deploy.tar.gz';
if (!move_uploaded_file($upload['tmp_name'], $dest)) {
    http_response_code(500);
    exit('Failed to save file');
}

$output = [];
$returnCode = 0;
exec('cd ' . escapeshellarg(EXTRACT_DIR) . ' && tar -xzf deploy.tar.gz 2>&1', $output, $returnCode);

// Pulizia
@unlink($dest);

if ($returnCode !== 0) {
    http_response_code(500);
    echo 'Extraction failed (code ' . $returnCode . '):\n' . implode('\n', $output);
    exit();
}

http_response_code(200);
echo json_encode([
    'status'  => 'ok',
    'message' => 'Deploy completato con successo',
    'output'  => $output,
    'time'    => date('Y-m-d H:i:s'),
]);

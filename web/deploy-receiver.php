<?php
/**
 * deploy-receiver.php
 * Riceve il pacchetto di deploy via JSON Base64 HTTPS POST ed estrae i file.
 * Posizionare nella cartella web/ (Document Root di Bedrock su Netsons).
 */

define('DEPLOY_TOKEN', '6kNQApo-1nSvRYQkbtgYi4dVD3BcbwhwRyTm4vZ8r3c');
define('EXTRACT_DIR', dirname(__DIR__)); // Un livello sopra web/ (root di Bedrock)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$token = $_GET['token'] ?? $data['token'] ?? $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, $token)) {
    http_response_code(403);
    exit('Forbidden: invalid token');
}

// 1. Gestione payload JSON Base64 (preferito per superare ModSecurity/Imunify360 WAF)
if (!empty($data['archive_b64'])) {
    $tar_content = base64_decode($data['archive_b64']);
    $dest = EXTRACT_DIR . '/deploy.tar.gz';
    file_put_contents($dest, $tar_content);
} 
// 2. Fallback gestione multipart upload
elseif (isset($_FILES['deploy_archive']) && $_FILES['deploy_archive']['error'] === UPLOAD_ERR_OK) {
    $dest = EXTRACT_DIR . '/deploy.tar.gz';
    move_uploaded_file($_FILES['deploy_archive']['tmp_name'], $dest);
} else {
    http_response_code(400);
    exit('No valid archive provided');
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

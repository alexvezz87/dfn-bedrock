<?php
// backup_db.php
// Script helper in PHP per esportare il database senza dipendere da mysqldump esterno

$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    echo "ERROR: File .env non trovato!";
    exit(1);
}

$dbName = '';
$dbUser = '';
$dbPass = '';
$dbHost = '127.0.0.1';

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (preg_match("/^DB_NAME=['\"]?([^'\"]+)['\"]?/", $line, $matches)) {
        $dbName = $matches[1];
    }
    if (preg_match("/^DB_USER=['\"]?([^'\"]*)['\"]?/", $line, $matches)) {
        $dbUser = $matches[1];
    }
    if (preg_match("/^DB_PASSWORD=['\"]?([^'\"]*)['\"]?/", $line, $matches)) {
        $dbPass = $matches[1];
    }
    if (preg_match("/^DB_HOST=['\"]?([^'\"]+)['\"]?/", $line, $matches)) {
        $dbHost = $matches[1];
    }
}

if (empty($dbName)) {
    echo "ERROR: DB_NAME non trovato in .env!";
    exit(1);
}

$sqlFile = isset($argv[1]) ? $argv[1] : __DIR__ . '/backups/db_backup.sql';

// Crea cartella se non esiste
$dir = dirname($sqlFile);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    $fp = fopen($sqlFile, 'w');
    if (!$fp) {
        throw new Exception("Impossibile creare o scrivere nel file di destinazione: $sqlFile");
    }

    fwrite($fp, "-- Backup Database: $dbName\n");
    fwrite($fp, "-- Generato il: " . date('Y-m-d H:i:s') . "\n\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    // Estrae tutte le tabelle
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        // Struttura della tabella
        $createStmt = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        fwrite($fp, $createStmt['Create Table'] . ";\n\n");

        // Dati della tabella
        $dataStmt = $pdo->query("SELECT * FROM `$table`");
        while ($row = $dataStmt->fetch()) {
            $keys = array_keys($row);
            $values = array_map(function($val) use ($pdo) {
                if ($val === null) {
                    return 'NULL';
                }
                return $pdo->quote($val);
            }, array_values($row));

            fwrite($fp, "INSERT INTO `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $values) . ");\n");
        }
        fwrite($fp, "\n");
    }

    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    echo "SUCCESS";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
    exit(1);
}

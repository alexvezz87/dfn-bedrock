# backup.ps1
# Script per effettuare il backup locale di database e file chiave (temi, uploads, configurazioni)

$ErrorActionPreference = "Stop"

# 1. Parsing del file .env per estrarre le credenziali
$envFile = Join-Path $PSScriptRoot ".env"
if (-not (Test-Path $envFile)) {
    Write-Error "File .env non trovato nella cartella principale del progetto!"
}

$dbName = ""
$dbUser = ""
$dbPass = ""
$dbHost = "127.0.0.1"

Get-Content $envFile | Foreach-Object {
    if ($_ -match "^DB_NAME=['""]?([^'""\s]+)['""]?") { $dbName = $Matches[1] }
    if ($_ -match "^DB_USER=['""]?([^'""\s]*)['""]?") { $dbUser = $Matches[1] }
    if ($_ -match "^DB_PASSWORD=['""]?([^'""\s]*)['""]?") { $dbPass = $Matches[1] }
    if ($_ -match "^DB_HOST=['""]?([^'""\s]+)['""]?") { $dbHost = $Matches[1] }
}

$timestamp = Get-Date -Format "yyyy-MM-dd_HH-mm-ss"
$backupDir = Join-Path $PSScriptRoot "backups"

if (-not (Test-Path $backupDir)) {
    New-Item -ItemType Directory -Path $backupDir | Out-Null
}

Write-Host "--- Inizio Backup ($timestamp) ---" -ForegroundColor Cyan

# 2. Backup del Database
$sqlFile = Join-Path $backupDir "db_backup_${timestamp}.sql"
Write-Host "Esportazione del database in corso ($dbName)..." -ForegroundColor Yellow

try {
    if (Get-Command php -ErrorAction SilentlyContinue) {
        Write-Host "Utilizzo dello script PHP per esportare il database..." -ForegroundColor Gray
        $phpScript = Join-Path $PSScriptRoot "backup_db.php"
        $output = & php $phpScript $sqlFile
        if ($output -ne "SUCCESS") {
            throw "Errore script PHP: $output"
        }
    } elseif (Get-Command wp -ErrorAction SilentlyContinue) {
        Write-Host "Utilizzo wp-cli per esportare il database..." -ForegroundColor Gray
        wp db export $sqlFile --path="web/wp"
    } else {
        # Fallback a mysqldump se php/wp-cli non sono disponibili per qualche motivo
        Write-Host "Tentativo di utilizzo di mysqldump..." -ForegroundColor Gray
        $dumpArgs = @("-h", $dbHost, "-u", $dbUser)
        if ($dbPass) { $dumpArgs += "-p$dbPass" }
        $dumpArgs += @($dbName, "--result-file=$sqlFile")
        $proc = Start-Process -FilePath "mysqldump" -ArgumentList $dumpArgs -NoNewWindow -Wait -PassThru
        if ($proc.ExitCode -ne 0) {
            throw "mysqldump non riuscito."
        }
    }
    
    if (Test-Path $sqlFile) {
        Write-Host "Database salvato con successo in: $sqlFile" -ForegroundColor Green
    } else {
        Write-Warning "Il file SQL non è stato generato."
    }
} catch {
    Write-Warning "Errore durante l'esportazione del database: $_"
}

# 3. Backup dei File chiave (Tema personalizzato, Uploads, Composer e .env)
$zipFile = Join-Path $backupDir "files_backup_${timestamp}.zip"
Write-Host "Compressione dei file in corso..." -ForegroundColor Yellow

$tempZipFolder = Join-Path $env:TEMP "dfn_backup_temp_$timestamp"
if (Test-Path $tempZipFolder) { Remove-Item -Path $tempZipFolder -Recurse -Force }
New-Item -ItemType Directory -Path $tempZipFolder | Out-Null

$themePath = Join-Path $PSScriptRoot "web/app/themes/dfn-theme"
$uploadsPath = Join-Path $PSScriptRoot "web/app/uploads"
$composerJson = Join-Path $PSScriptRoot "composer.json"
$composerLock = Join-Path $PSScriptRoot "composer.lock"
$dotenv = Join-Path $PSScriptRoot ".env"

# Copia solo le parti importanti (escludendo wp core, vendor, node_modules)
if (Test-Path $themePath) {
    $destTheme = Join-Path $tempZipFolder "web/app/themes/dfn-theme"
    New-Item -ItemType Directory -Path (Split-Path $destTheme -Parent) -Force | Out-Null
    Copy-Item -Path $themePath -Destination $destTheme -Recurse -Force
}
if (Test-Path $uploadsPath) {
    $destUploads = Join-Path $tempZipFolder "web/app/uploads"
    New-Item -ItemType Directory -Path (Split-Path $destUploads -Parent) -Force | Out-Null
    Copy-Item -Path $uploadsPath -Destination $destUploads -Recurse -Force
}
Copy-Item -Path $composerJson -Destination $tempZipFolder -Force
Copy-Item -Path $composerLock -Destination $tempZipFolder -Force
Copy-Item -Path $dotenv -Destination $tempZipFolder -Force

try {
    Compress-Archive -Path "$tempZipFolder\*" -DestinationPath $zipFile -Force
    Write-Host "File salvati con successo in: $zipFile" -ForegroundColor Green
} catch {
    Write-Warning "Errore durante la compressione dei file: $_"
} finally {
    if (Test-Path $tempZipFolder) {
        Remove-Item -Path $tempZipFolder -Recurse -Force
    }
}

Write-Host "--- Backup completato con successo! ---" -ForegroundColor Cyan
Write-Host "I backup si trovano nella cartella: $backupDir" -ForegroundColor Cyan

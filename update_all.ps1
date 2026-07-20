# update_all.ps1
# Script per aggiornare automaticamente WordPress Core e tutti i plugin del progetto Bedrock alle ultime versioni

$ErrorActionPreference = "Stop"

# 1. Esegui il backup preventivo
Write-Host "1. Esecuzione del backup preventivo..." -ForegroundColor Cyan
if (Test-Path (Join-Path $PSScriptRoot "backup.ps1")) {
    & powershell -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "backup.ps1")
} else {
    Write-Warning "Script backup.ps1 non trovato, continuo senza backup."
}

# 2. Modifica temporanea di composer.json per sbloccare le versioni
Write-Host "`n2. Rilevamento e sblocco delle versioni in composer.json..." -ForegroundColor Cyan
$composerPath = Join-Path $PSScriptRoot "composer.json"
$composer = Get-Content $composerPath -Raw | ConvertFrom-Json

# Backup di composer.json prima delle modifiche
Copy-Item $composerPath (Join-Path $PSScriptRoot "composer.json.bak") -Force

# Identifica i pacchetti da sbloccare
$packagesToUpdate = @()
foreach ($prop in $composer.require.psobject.properties) {
    if ($prop.Name -eq "roots/wordpress" -or $prop.Name -like "wp-plugin/*") {
        $packagesToUpdate += $prop.Name
        $composer.require.$($prop.Name) = "*"
    }
}

# Salva il composer.json temporaneamente sbloccato senza BOM
$jsonString = $composer | ConvertTo-Json -Depth 10
[System.IO.File]::WriteAllText($composerPath, $jsonString)

# 3. Esegui l'aggiornamento tramite Composer
Write-Host "`n3. Esecuzione di 'composer update' (potrebbe richiedere qualche minuto)..." -ForegroundColor Cyan
try {
    # Aggiorna solo i pacchetti sbloccati per evitare di toccare librerie PHP di sistema non necessarie
    $updateArgs = $packagesToUpdate + @("--with-all-dependencies")
    composer update $updateArgs
} catch {
    Write-Error "Errore durante l'esecuzione di composer update. Ripristino il file composer.json originale."
    Copy-Item (Join-Path $PSScriptRoot "composer.json.bak") $composerPath -Force
    exit 1
}

# 4. Blocca le nuove versioni installate in composer.json
Write-Host "`n4. Blocco delle nuove versioni nel file composer.json..." -ForegroundColor Cyan
if (Test-Path (Join-Path $PSScriptRoot "composer.lock")) {
    $lock = Get-Content (Join-Path $PSScriptRoot "composer.lock") -Raw | ConvertFrom-Json
    
    # Crea una mappa di tutte le versioni installate
    $installedVersions = @{}
    foreach ($package in $lock.packages) {
        $installedVersions[$package.name] = $package.version
    }
    
    # Rileggi il file composer.json originale salvato per mantenere l'ordine delle chiavi
    $newComposer = Get-Content $composerPath -Raw | ConvertFrom-Json
    
    foreach ($package in $packagesToUpdate) {
        if ($installedVersions.ContainsKey($package)) {
            $versionClean = $installedVersions[$package]
            # Rimuovi prefissi come 'v' o tag aggiuntivi se presenti
            if ($versionClean -match "^v?(\d+\.\d+\.\d+(\.\d+)?)$") {
                $versionClean = $Matches[1]
            }
            $newComposer.require.$package = $versionClean
            Write-Host " -> $package aggiornato a: $versionClean" -ForegroundColor Green
        }
    }
    
    # Salva il file finale con le versioni aggiornate e bloccate senza BOM
    $jsonString = $newComposer | ConvertTo-Json -Depth 10
    [System.IO.File]::WriteAllText($composerPath, $jsonString)
    Write-Host "`ncomposer.json aggiornato e bloccato correttamente!" -ForegroundColor Green
} else {
    Write-Warning "composer.lock non trovato. Non è stato possibile bloccare le versioni."
}

# Pulisci il backup temporaneo di composer.json
if (Test-Path (Join-Path $PSScriptRoot "composer.json.bak")) {
    Remove-Item (Join-Path $PSScriptRoot "composer.json.bak") -Force
}

Write-Host "`n--- Processo di aggiornamento completato con successo! ---" -ForegroundColor Cyan

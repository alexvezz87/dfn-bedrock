<?php
/**
 * Custom Maintenance Drop-in per WordPress / Bedrock (503 Service Unavailable)
 */
header('HTTP/1.1 503 Service Temporarily Unavailable');
header('Status: 503 Service Temporarily Unavailable');
header('Retry-After: 300');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manutenzione in corso — DFN Prenotazioni</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
        }
        .dfn-maint-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 20px;
            padding: 48px 36px;
            max-width: 540px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 75, 35, 0.08);
        }
        .dfn-maint-icon {
            font-size: 52px;
            margin-bottom: 20px;
            display: inline-block;
        }
        .dfn-maint-title {
            color: #004b23;
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 14px 0;
            letter-spacing: -0.3px;
        }
        .dfn-maint-desc {
            font-size: 15.5px;
            color: #64748b;
            line-height: 1.65;
            margin: 0 0 24px 0;
        }
        .dfn-maint-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e8f5e9;
            color: #004b23;
            font-weight: 700;
            font-size: 13px;
            padding: 8px 18px;
            border-radius: 24px;
            border: 1px solid #c8e6c9;
        }
    </style>
</head>
<body>
    <div class="dfn-maint-card">
        <div class="dfn-maint-icon">🏛️</div>
        <h1 class="dfn-maint-title">Aggiornamento di Sistema in Corso</h1>
        <p class="dfn-maint-desc">
            Stiamo effettuando un rapido aggiornamento della piattaforma DFN Prenotazioni per migliorare la sicurezza e le prestazioni del servizio.
        </p>
        <div class="dfn-maint-badge">
            <span>⏱️</span> Il portale tornerà operativo a brevissimo
        </div>
    </div>
</body>
</html>
<?php
exit;

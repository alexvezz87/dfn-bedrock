<?php
/**
 * DFN Booking System — Pagina di Manutenzione FAI Delegazione di Novara
 *
 * Visualizzata automaticamente durante aggiornamenti di sistema, core o plugin.
 *
 * @package DFN_Theme
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
    <title>Manutenzione in corso — Delegazione FAI di Novara</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .dfn-maint-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            max-width: 540px;
            width: 100%;
            padding: 40px 32px;
            text-align: center;
            box-shadow: 0 20px 35px -10px rgba(0, 75, 35, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        .dfn-maint-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #e8f5e9;
            color: #004b23;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 14px;
            border-radius: 30px;
            margin-bottom: 20px;
        }
        .dfn-maint-icon {
            font-size: 54px;
            margin-bottom: 16px;
            display: inline-block;
            animation: pulse 2s infinite ease-in-out;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.08); }
        }
        h1 {
            font-size: 24px;
            font-weight: 800;
            color: #004b23;
            margin-bottom: 12px;
            line-height: 1.3;
        }
        p {
            font-size: 15px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .dfn-maint-box {
            background: #fffdf5;
            border: 1.5px solid #fed7aa;
            border-left: 4px solid #ea580c;
            border-radius: 12px;
            padding: 14px 18px;
            text-align: left;
            margin-bottom: 28px;
            font-size: 13.5px;
            color: #9a3412;
            line-height: 1.5;
        }
        .dfn-maint-btn {
            display: inline-block;
            background: #004b23;
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            padding: 12px 28px;
            border-radius: 30px;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(0, 75, 35, 0.25);
        }
        .dfn-maint-btn:hover {
            background: #003318;
            transform: translateY(-1px);
        }
        .dfn-maint-footer {
            margin-top: 24px;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
        }
    </style>
</head>
<body>
    <div class="dfn-maint-card">
        <span class="dfn-maint-badge">🏛️ FAI — Fondo per l'Ambiente Italiano</span>
        <div class="dfn-maint-icon">⚙️</div>
        <h1>Aggiornamento Tecnico in Corso</h1>
        <p>
            Stiamo eseguendo alcune operazioni di manutenzione e miglioramento del portale per garantirti la migliore esperienza di prenotazione.
        </p>

        <div class="dfn-maint-box">
            <strong>⏱️ Torneremo online a brevissimo:</strong><br>
            Tutti i dati e le tue prenotazioni sono al sicuro. Ti invitiamo a ricaricare la pagina tra qualche istante.
        </div>

        <button onclick="window.location.reload();" class="dfn-maint-btn">
            🔄 Ricarica la Pagina
        </button>

        <div class="dfn-maint-footer">
            Delegazione FAI di Novara • dfnprenotazioni.it
        </div>
    </div>
</body>
</html>
<?php
exit;

<?php
/**
 * DFN Booking System — Pagina di Manutenzione Ufficiale DFN Prenotazioni
 *
 * Visualizzata durante gli aggiornamenti di sistema e manutenzioni programmate.
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
    <title>Manutenzione Programmata — DFN Prenotazioni</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="icon" href="/app/uploads/2026/09/cropped-logo-cupola-2-32x32.png" sizes="32x32" />
    <style>
        :root {
            --dfn-primary: #004b23;
            --dfn-primary-dark: #003318;
            --dfn-primary-light: #e8f5e9;
            --dfn-accent: #10b981;
            --dfn-text-main: #1e293b;
            --dfn-text-muted: #64748b;
            --dfn-border: #e2e8f0;
            --dfn-bg-page: #f8fafc;
            --dfn-bg-card: #ffffff;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', 'Roboto', -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: var(--dfn-bg-page);
            color: var(--dfn-text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* 1. HEADER */
        .dfn-header {
            background: #ffffff;
            border-bottom: 1px solid var(--dfn-border);
            padding: 14px 24px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);
        }
        .dfn-header-container {
            max-width: 1140px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .dfn-brand-wrapper {
            display: flex;
            align-items: center;
            gap: 14px;
            text-decoration: none;
            color: inherit;
        }
        .dfn-logo-img {
            height: 46px;
            width: auto;
            display: block;
            object-fit: contain;
        }
        .dfn-brand-titles {
            display: flex;
            flex-direction: column;
        }
        .dfn-brand-name {
            font-size: 20px;
            font-weight: 800;
            color: var(--dfn-primary);
            letter-spacing: -0.4px;
            line-height: 1.1;
        }
        .dfn-brand-claim {
            font-size: 11px;
            font-weight: 600;
            color: var(--dfn-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-top: 2px;
        }

        .dfn-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 12.5px;
            font-weight: 700;
            color: #c2410c;
        }
        .dfn-status-dot {
            width: 8px;
            height: 8px;
            background-color: #ea580c;
            border-radius: 50%;
            position: relative;
        }
        .dfn-status-dot::after {
            content: '';
            position: absolute;
            top: -3px;
            left: -3px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background-color: rgba(234, 88, 12, 0.35);
            animation: pulse-ring 2s infinite ease-out;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.6); opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }

        /* 2. CONTENITORE CENTRALE */
        .dfn-main-wrapper {
            max-width: 960px;
            width: 100%;
            margin: 40px auto;
            padding: 0 20px;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dfn-card {
            background: var(--dfn-bg-card);
            border: 1px solid var(--dfn-border);
            border-radius: 20px;
            padding: 44px 36px;
            box-shadow: 0 20px 40px -15px rgba(0, 75, 35, 0.06), 0 1px 3px rgba(0, 0, 0, 0.04);
            text-align: center;
            width: 100%;
        }

        .dfn-pill-category {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--dfn-primary-light);
            color: var(--dfn-primary);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 5px 14px;
            border-radius: 20px;
            margin-bottom: 20px;
        }

        .dfn-title {
            font-size: 32px;
            font-weight: 800;
            color: var(--dfn-primary);
            line-height: 1.25;
            margin-bottom: 14px;
            letter-spacing: -0.5px;
        }

        .dfn-lead {
            font-size: 16px;
            color: var(--dfn-text-muted);
            max-width: 660px;
            margin: 0 auto 36px;
            line-height: 1.6;
        }

        /* GRIGLIA 3 SCHEDE */
        .dfn-grid-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 36px;
            text-align: left;
        }

        .dfn-subcard {
            background: var(--dfn-bg-page);
            border: 1px solid var(--dfn-border);
            border-radius: 14px;
            padding: 22px 18px;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .dfn-subcard:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.04);
        }

        .dfn-icon-box {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }
        .dfn-icon-box.green {
            background: #e8f5e9;
            color: var(--dfn-primary);
        }
        .dfn-icon-box.blue {
            background: #eff6ff;
            color: #2563eb;
        }
        .dfn-icon-box.amber {
            background: #fffbeb;
            color: #d97706;
        }

        .dfn-subcard-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--dfn-text-main);
            margin-bottom: 6px;
        }

        .dfn-subcard-text {
            font-size: 13px;
            color: var(--dfn-text-muted);
            line-height: 1.45;
        }

        /* AZIONI */
        .dfn-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            padding-top: 24px;
            border-top: 1px solid var(--dfn-border);
        }

        .dfn-btn-reload {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--dfn-primary);
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            padding: 13px 32px;
            border-radius: 9999px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 8px 16px -4px rgba(0, 75, 35, 0.3);
            transition: all 0.2s ease;
        }
        .dfn-btn-reload:hover {
            background: var(--dfn-primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 12px 20px -4px rgba(0, 75, 35, 0.4);
        }
        .dfn-btn-reload:active {
            transform: translateY(0);
        }
        .dfn-btn-reload svg {
            width: 17px;
            height: 17px;
            transition: transform 0.3s ease;
        }
        .dfn-btn-reload:hover svg {
            transform: rotate(90deg);
        }

        .dfn-contact-info {
            font-size: 13.5px;
            color: var(--dfn-text-muted);
        }
        .dfn-contact-info a {
            color: var(--dfn-primary);
            font-weight: 600;
            text-decoration: none;
        }
        .dfn-contact-info a:hover {
            text-decoration: underline;
        }

        /* 3. FOOTER DEL SITO */
        .dfn-footer {
            background: #ffffff;
            border-top: 1px solid var(--dfn-border);
            font-size: 13px;
            color: var(--dfn-text-muted);
        }
        .dfn-footer-top {
            border-bottom: 1px solid #f1f5f9;
            padding: 14px 20px;
            background: #fafafa;
        }
        .dfn-footer-nav {
            max-width: 1140px;
            margin: 0 auto;
            display: flex;
            justify-content: center;
            gap: 24px;
            flex-wrap: wrap;
        }
        .dfn-footer-nav a {
            color: var(--dfn-text-muted);
            text-decoration: none;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .dfn-footer-nav a:hover {
            color: var(--dfn-primary);
        }
        .dfn-footer-main {
            padding: 24px 20px;
            max-width: 1140px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }
        .dfn-footer-brand strong {
            color: var(--dfn-text-main);
            font-weight: 700;
        }
        .dfn-footer-contact a {
            color: var(--dfn-text-muted);
            text-decoration: none;
        }
        .dfn-footer-contact a:hover {
            color: var(--dfn-primary);
        }

        @media (max-width: 820px) {
            .dfn-grid-cards {
                grid-template-columns: 1fr;
            }
            .dfn-card {
                padding: 30px 20px;
            }
            .dfn-title {
                font-size: 24px;
            }
            .dfn-lead {
                font-size: 14.5px;
            }
            .dfn-footer-main {
                flex-direction: column;
                text-align: center;
            }
            .dfn-brand-claim {
                display: none;
            }
        }
    </style>
</head>
<body>

    <!-- HEADER IDENTICO AL PORTALE -->
    <header class="dfn-header">
        <div class="dfn-header-container">
            <a href="/" class="dfn-brand-wrapper">
                <img src="/app/uploads/2026/09/logo-cupola-2.png" 
                     onerror="this.onerror=null; this.src='https://dfnprenotazioni.it/app/uploads/2026/09/logo-cupola-2.png';" 
                     alt="DFN Prenotazioni" 
                     class="dfn-logo-img">
                <div class="dfn-brand-titles">
                    <span class="dfn-brand-name">DFN Prenotazioni</span>
                    <span class="dfn-brand-claim">Portale Eventi & Prenotazioni</span>
                </div>
            </a>

            <div class="dfn-status-badge">
                <span class="dfn-status-dot"></span>
                <span>Manutenzione Programmata</span>
            </div>
        </div>
    </header>

    <!-- CORPO PRINCIPALE -->
    <main class="dfn-main-wrapper">
        <div class="dfn-card">
            
            <div class="dfn-pill-category">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                </svg>
                Aggiornamento Piattaforma
            </div>

            <h1 class="dfn-title">
                Stiamo migliorando DFN Prenotazioni
            </h1>

            <p class="dfn-lead">
                Stiamo eseguendo un intervento di manutenzione programmata per garantire la massima stabilità, sicurezza e velocità durante la consultazione e prenotazione degli eventi.
            </p>

            <!-- 3 SCHEDE INFORMATIVE STRUTTURATE -->
            <div class="dfn-grid-cards">
                
                <!-- SCHEDA 1 -->
                <div class="dfn-subcard">
                    <div class="dfn-icon-box green">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </div>
                    <div class="dfn-subcard-title">Prenotazioni al Sicuro</div>
                    <div class="dfn-subcard-text">
                        Tutti i tuoi ordini, i biglietti acquistati e i dati del profilo sono protetti e sincronizzati.
                    </div>
                </div>

                <!-- SCHEDA 2 -->
                <div class="dfn-subcard">
                    <div class="dfn-icon-box blue">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 20V10M12 20V4M6 20v-6"/>
                        </svg>
                    </div>
                    <div class="dfn-subcard-title">Ottimizzazione Servizi</div>
                    <div class="dfn-subcard-text">
                        Aggiornamento dei componenti di gestione del calendario, sicurezza e gateway di pagamento.
                    </div>
                </div>

                <!-- SCHEDA 3 -->
                <div class="dfn-subcard">
                    <div class="dfn-icon-box amber">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>
                    <div class="dfn-subcard-title">Riapertura a Breve</div>
                    <div class="dfn-subcard-text">
                        L'intervento è in fase conclusiva. I servizi di prenotazione torneranno attivi tra pochi minuti.
                    </div>
                </div>

            </div>

            <!-- PULSANTE E ASSISTENZA -->
            <div class="dfn-actions">
                <button onclick="window.location.reload();" class="dfn-btn-reload" aria-label="Ricarica la pagina">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                    </svg>
                    Verifica Disponibilità e Ricarica
                </button>
                <div class="dfn-contact-info">
                    Hai bisogno di assistenza per un evento? Scrivici a <a href="mailto:novara@delegazionefai.fondoambiente.it">novara@delegazionefai.fondoambiente.it</a> o chiama il <a href="tel:+393471375245">+39 347 137 5245</a>.
                </div>
            </div>

        </div>
    </main>

    <!-- FOOTER IDENTICO AL PORTALE -->
    <footer class="dfn-footer">
        <div class="dfn-footer-top">
            <div class="dfn-footer-nav">
                <a href="/mio-account/">Il mio account</a>
                <a href="/privacy-policy/">Privacy Policy</a>
                <a href="mailto:novara@delegazionefai.fondoambiente.it">Contattaci</a>
            </div>
        </div>
        <div class="dfn-footer-main">
            <div class="dfn-footer-brand">
                <strong>DFN Prenotazioni</strong> — Portale per la prenotazione di eventi e iniziative culturali
            </div>
            <div class="dfn-footer-contact">
                &copy; <?php echo esc_html(date('Y')); ?> DFN Prenotazioni. Tutti i diritti riservati.
            </div>
        </div>
    </footer>

</body>
</html>
<?php
exit;

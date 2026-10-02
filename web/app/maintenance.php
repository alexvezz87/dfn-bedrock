<?php
/**
 * DFN Booking System — Pagina di Manutenzione DFN Prenotazioni
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
    <title>Manutenzione di Sistema — DFN Prenotazioni</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --dfn-green: #004b23;
            --dfn-green-dark: #003318;
            --dfn-green-light: #e8f5e9;
            --dfn-green-accent: #10b981;
            --dfn-slate-900: #0f172a;
            --dfn-slate-800: #1e293b;
            --dfn-slate-600: #475569;
            --dfn-slate-500: #64748b;
            --dfn-slate-200: #e2e8f0;
            --dfn-slate-100: #f1f5f9;
            --dfn-bg: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--dfn-bg);
            color: var(--dfn-slate-800);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            background-image: 
                radial-gradient(at 100% 0%, rgba(0, 75, 35, 0.05) 0px, transparent 50%),
                radial-gradient(at 0% 100%, rgba(16, 185, 129, 0.04) 0px, transparent 50%);
        }

        /* HEADER DEL SITO */
        .dfn-site-header {
            background: #ffffff;
            border-bottom: 1px solid var(--dfn-slate-200);
            padding: 16px 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }
        .dfn-header-inner {
            max-width: 1140px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .dfn-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--dfn-green);
        }
        .dfn-brand-logo {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--dfn-green), var(--dfn-green-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 800;
            font-size: 18px;
            letter-spacing: -0.5px;
            box-shadow: 0 4px 10px rgba(0, 75, 35, 0.2);
        }
        .dfn-brand-text {
            font-size: 20px;
            font-weight: 800;
            color: var(--dfn-green);
            letter-spacing: -0.3px;
        }
        .dfn-brand-sub {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--dfn-slate-500);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-top: -2px;
        }
        .dfn-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 13px;
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
            background-color: rgba(234, 88, 12, 0.4);
            animation: pulse-ring 1.8s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.6); opacity: 1; }
            100% { transform: scale(1.6); opacity: 0; }
        }

        /* MAIN HERO SECTION */
        .dfn-main-container {
            max-width: 1040px;
            margin: 40px auto;
            padding: 0 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .dfn-hero-card {
            background: #ffffff;
            border: 1px solid var(--dfn-slate-200);
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.05), 0 1px 3px rgba(0, 0, 0, 0.02);
            text-align: center;
        }

        .dfn-hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--dfn-green-light);
            color: var(--dfn-green);
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 12.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 24px;
        }

        .dfn-hero-title {
            font-size: 34px;
            font-weight: 800;
            color: var(--dfn-slate-900);
            line-height: 1.25;
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }
        .dfn-hero-title span {
            color: var(--dfn-green);
        }

        .dfn-hero-lead {
            font-size: 17px;
            color: var(--dfn-slate-600);
            max-width: 680px;
            margin: 0 auto 40px;
            line-height: 1.6;
        }

        /* GRIGLIA CARDS STATO */
        .dfn-features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 40px;
            text-align: left;
        }

        .dfn-feature-box {
            background: var(--dfn-bg);
            border: 1px solid var(--dfn-slate-200);
            border-radius: 16px;
            padding: 24px 20px;
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .dfn-feature-box:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
        }

        .dfn-feature-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .dfn-feature-icon.green {
            background: #e8f5e9;
            color: var(--dfn-green);
        }
        .dfn-feature-icon.blue {
            background: #eff6ff;
            color: #2563eb;
        }
        .dfn-feature-icon.amber {
            background: #fffbeb;
            color: #d97706;
        }

        .dfn-feature-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--dfn-slate-900);
            margin-bottom: 8px;
        }

        .dfn-feature-desc {
            font-size: 13.5px;
            color: var(--dfn-slate-500);
            line-height: 1.5;
        }

        /* AZIONE PRINCIPALE */
        .dfn-action-area {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            padding-top: 20px;
            border-top: 1px solid var(--dfn-slate-100);
        }

        .dfn-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--dfn-green);
            color: #ffffff;
            font-size: 15px;
            font-weight: 700;
            padding: 14px 36px;
            border-radius: 9999px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 10px 20px -5px rgba(0, 75, 35, 0.3);
            transition: all 0.2s ease;
        }
        .dfn-btn-primary:hover {
            background: var(--dfn-green-dark);
            transform: translateY(-1px);
            box-shadow: 0 14px 24px -5px rgba(0, 75, 35, 0.4);
        }
        .dfn-btn-primary:active {
            transform: translateY(0);
        }

        .dfn-btn-primary svg {
            width: 18px;
            height: 18px;
            transition: transform 0.3s ease;
        }
        .dfn-btn-primary:hover svg {
            transform: rotate(90deg);
        }

        .dfn-support-note {
            font-size: 13.5px;
            color: var(--dfn-slate-500);
        }
        .dfn-support-note a {
            color: var(--dfn-green);
            font-weight: 600;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        /* FOOTER */
        .dfn-site-footer {
            background: #ffffff;
            border-top: 1px solid var(--dfn-slate-200);
            padding: 24px 20px;
            text-align: center;
            font-size: 13px;
            color: var(--dfn-slate-500);
        }
        .dfn-footer-inner {
            max-width: 1140px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        @media (max-width: 860px) {
            .dfn-features-grid {
                grid-template-columns: 1fr;
            }
            .dfn-hero-card {
                padding: 32px 20px;
            }
            .dfn-hero-title {
                font-size: 26px;
            }
            .dfn-hero-lead {
                font-size: 15px;
            }
            .dfn-footer-inner {
                flex-direction: column;
                justify-content: center;
            }
        }
    </style>
</head>
<body>

    <!-- 1. HEADER DI SISTEMA -->
    <header class="dfn-site-header">
        <div class="dfn-header-inner">
            <div class="dfn-brand">
                <div class="dfn-brand-logo">DFN</div>
                <div>
                    <span class="dfn-brand-text">DFN Prenotazioni</span>
                    <span class="dfn-brand-sub">Portale Eventi & Prenotazioni</span>
                </div>
            </div>
            <div class="dfn-status-pill">
                <span class="dfn-status-dot"></span>
                <span>Manutenzione Programmata</span>
            </div>
        </div>
    </header>

    <!-- 2. CONTENUTO CENTRALE -->
    <main class="dfn-main-container">
        <div class="dfn-hero-card">
            
            <div class="dfn-hero-badge">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
                </svg>
                Aggiornamento Piattaforma in Corso
            </div>

            <h1 class="dfn-hero-title">
                Stiamo migliorando <span>DFN Prenotazioni</span>
            </h1>

            <p class="dfn-hero-lead">
                Stiamo eseguendo un intervento programmato per ottimizzare le prestazioni del portale, migliorare la gestione delle prenotazioni e introdurre nuove funzionalità.
            </p>

            <!-- GRIGLIA DETTAGLI E GARANZIE -->
            <div class="dfn-features-grid">
                
                <!-- CARD 1 -->
                <div class="dfn-feature-box">
                    <div class="dfn-feature-icon green">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                    </div>
                    <div class="dfn-feature-title">Dati e Ordini Protetti</div>
                    <div class="dfn-feature-desc">
                        Tutte le tue prenotazioni, biglietti e ordini confermati sono perfettamente al sicuro e sincronizzati.
                    </div>
                </div>

                <!-- CARD 2 -->
                <div class="dfn-feature-box">
                    <div class="dfn-feature-icon blue">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                    </div>
                    <div class="dfn-feature-title">Ottimizzazione Servizi</div>
                    <div class="dfn-feature-desc">
                        Aggiornamento dei sistemi di sicurezza, server e infrastruttura di pagamento per la massima stabilità.
                    </div>
                </div>

                <!-- CARD 3 -->
                <div class="dfn-feature-box">
                    <div class="dfn-feature-icon amber">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12 6 12 12 16 14"/>
                        </svg>
                    </div>
                    <div class="dfn-feature-title">Riapertura Imminente</div>
                    <div class="dfn-feature-desc">
                        Le operazioni di manutenzione richiedono solo pochi minuti. Il portale tornerà online a brevissimo.
                    </div>
                </div>

            </div>

            <!-- BOTTONE E ASSISTENZA -->
            <div class="dfn-action-area">
                <button onclick="window.location.reload();" class="dfn-btn-primary" aria-label="Ricarica la pagina">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                    </svg>
                    Verifica Disponibilità e Ricarica
                </button>
                <div class="dfn-support-note">
                    Hai bisogno di informazioni urgenti? <a href="mailto:info@dfnprenotazioni.it">Contatta il Supporto</a>
                </div>
            </div>

        </div>
    </main>

    <!-- 3. FOOTER DEL SITO -->
    <footer class="dfn-site-footer">
        <div class="dfn-footer-inner">
            <div>
                <strong>DFN Prenotazioni</strong> — Portale per la prenotazione di eventi e iniziative
            </div>
            <div>
                &copy; <?php echo esc_html(date('Y')); ?> DFN Prenotazioni. Tutti i diritti riservati.
            </div>
        </div>
    </footer>

</body>
</html>
<?php
exit;

# DFN Prenotazioni - Custom WordPress Theme & Modular Architecture

Questo repository contiene il codice sorgente personalizzato per la gestione delle prenotazioni del sito **DFN Prenotazioni** (CandleVibes).

## 🚀 Architettura e Struttura del Progetto
Per garantire scalabilità, performance e sicurezza di livello Enterprise, il progetto segue una struttura rigorosamente **modulare**, separando le responsabilità in file dedicati e mantenendo il file `functions.php` pulito e leggero.

Tutto il codice custom risiede all'interno del tema attivo: `wp-content/themes/dfn-theme/`.

### 📂 Albero dei File Custom Principali
```
wp-content/themes/dfn-theme/
├── assets/                  # File CSS e JS compilati ed ottimizzati
│   ├── css/                 # Fogli di stile modulari (cv-botteghino.css, cv-frontend.css, ecc.)
│   └── js/                  # Script interattivi e logica client-side (cv-scanner.js, ecc.)
├── inc/                     # Logica di business modulare (Caricata tramite loader in functions.php)
│   ├── admin/               # Pannelli e logiche per l'area amministrativa (Report, Scanner, Accounting)
│   ├── api/                 # Endpoint AJAX e integrazioni API (cv-ajax-handlers.php)
│   ├── core/                # Helpers, logiche di configurazione e cron-job di sistema
│   └── frontend/            # Shortcode, PWA, e interfacce per i clienti
├── functions.php            # File di bootstrap (Loader dei moduli)
└── style.css                # Metadati del tema WordPress
```

## 🛡️ Best Practices Applicate
1. **Separation of Concerns (SoC):** La logica di business (API, salvataggio database) è completamente separata da quella di presentazione (HTML/Interfaccia).
2. **Nomenclatura Uniforme:** Tutte le funzioni globali, costanti e script utilizzano il prefisso univoco `cv_` (CandleVibes) per prevenire collisioni di nomi con altri plugin.
3. **Sicurezza Enterprise:** 
   - Utilizzo sistematico di Nonce per la prevenzione di attacchi CSRF su qualsiasi chiamata AJAX e form submission.
   - **Late Escaping** tramite funzioni native WordPress (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`) applicate nel momento esatto dell'output HTML.
   - **Early Sanitization** per ogni input utente (`$_POST`, `$_GET`).
4. **Performance & Optimization:**
   - Prevenzione delle query N+1.
   - Gestione dei dati tramite i metodi CRUD nativi di WooCommerce.
   - Caricamento condizionale degli asset (CSS/JS) solo nelle pagine in cui sono strettamente richiesti.

---
*Sviluppato con passione in conformità con i WordPress Coding Standards (WPCS) e PSR-12.*

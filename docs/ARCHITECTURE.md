# 📐 Architettura del Sistema

> **Riferimento Tecnico per Sviluppatori & Linee Guida Architetturali**  
> Il progetto **DFN Prenotazioni** è basato sullo stack moderno **Roots Bedrock**, che struttura WordPress come un'applicazione a 12 fattori con gestione dipendenze tramite Composer, isolamento delle configurazioni d'ambiente in file `.env` e separazione netta tra il core di WordPress (`/web/wp/`) e i contenuti applicativi (`/web/app/`).

---

## 1. Struttura Roots Bedrock

```
dfn-bedrock/
├── config/                  # Configurazioni d'ambiente (environments, application.php)
├── scripts/                 # Utility di test, diagnostica e deploy automatizzato
├── web/
│   ├── app/
│   │   ├── mu-plugins/      # Must-use plugins (caricamento forzato Bedrock)
│   │   ├── plugins/         # Plugin WordPress (WooCommerce, Booked, ecc.)
│   │   └── themes/
│   │       └── dfn-theme/   # Tema personalizzato e Core Engine DFN
│   └── wp/                  # Core WordPress isolato (subpath /wp/)
└── docs/                    # Documentazione tecnica e manuali operativi
```

### Regole Tecniche Vincolanti Bedrock
1. **Subpath `/wp/`**: WordPress non risiede nella root del web server ma nel subpath protetto `/wp/`. Tutti gli endpoint reali sono su `/wp/wp-admin/admin.php` e `/wp/wp-admin/admin-ajax.php`.
2. **Prevenzione Socket Drop & Chiusure TCP**:
   - I form di impostazioni e amministrazione non devono **mai** eseguire redirect sincroni forzati con `wp_safe_redirect` + `exit;` su `POST` assolute, per evitare interruzioni improvvise della connessione TCP del reverse-proxy (`DNS_PROBE_FINISHED_NXDOMAIN` o reset socket).
   - **Pattern Standard**: salvataggio asincrono AJAX via `admin-ajax.php` con fallback sincrono interno al ciclo di rendering senza redirect, notificando l'utente tramite `add_settings_error()`.

---

## 2. Struttura Modulare di `dfn-theme`

Tutta la logica di business è organizzata in cartelle specializzate all'interno di `inc/`:

```
web/app/themes/dfn-theme/inc/
├── core/                    # Inizializzazione, Database, Notifiche, Setup, Cron, Helpers
│   ├── dfn-setup.php        # Ruoli, capabilities, login redirect, costanti e tema
│   ├── dfn-database.php     # Tabelle custom dbDelta, query CRUD, transazioni, attribuzioni
│   ├── dfn-notifications.php# Motore email transazionali, promemoria riunioni & reminder
│   ├── dfn-cron.php         # Processi schedulati (batch promozioni, reminder turni)
│   └── dfn-helpers.php      # Utility condivise, sanitizzazione nomi, validazioni
├── admin/                   # Dashboard e pannelli di gestione nel backend WP
│   ├── dfn-events-manager.php       # Dashboard eventi (Futuri/Passati)
│   ├── dfn-event-editor.php         # Creazione/Modifica evento e fasce orarie
│   ├── dfn-slot-manager.php         # Gestione turni orari, capienze e overbooking
│   ├── dfn-promotions-admin.php     # Gestione Promozioni, Newsletter, Coda AJAX e Analytics
│   ├── dfn-fai-members-admin.php    # Anagrafica soci FAI
│   ├── dfn-fai-pending-bookings.php # Approvazione manuale tessere FAI
│   ├── dfn-settings.php             # Impostazioni generali, Email Builder & Tab Moduli
│   ├── dfn-volunteers-admin.php     # Anagrafica e gestione turni volontari
│   ├── dfn-volunteer-settings.php   # Impostazioni modulo volontari & Tab Notifiche
│   ├── dfn-teams-admin.php          # Gestione Squadre di Lavoro & Convocazioni Riunioni
│   ├── dfn-conventions-admin.php    # Gestione Anagrafica Convenzioni (v2.2)
│   ├── dfn-quick-booking.php        # Desk prenotazioni rapide per segreteria
│   ├── dfn-report.php               # Reportistica presenze e dati
│   └── dfn-accounting.php           # Rendicontazione contabile
├── frontend/                # Viste e componenti lato utente
│   ├── dfn-shortcodes.php           # Widget di prenotazione diretta [dfn_booking_widget]
│   ├── dfn-mobile-app.php           # PWA Gestione Eventi (/gestione-eventi/)
│   ├── dfn-myaccount.php            # Endpoint area personale /mio-account/
│   ├── dfn-volunteer-registration.php # Registrazione, vista turni e team volontario
│   ├── dfn-hub-biglietti.php        # Visualizzazione e download PDF biglietti
│   ├── dfn-conventions-frontend.php # Mappa e directory convenzioni FAI (v2.2)
│   └── dfn-gdpr.php                 # Consenso privacy e cookie GDPR
├── api/                     # Endpoint AJAX e router asincroni
│   ├── dfn-ajax-bookings.php        # Creazione prenotazione, calcolo carrello/sconti
│   ├── dfn-ajax-slots.php           # Recupero disponibilità turni in tempo reale
│   ├── dfn-ajax-scanner.php         # Validazione QR code e check-in
│   ├── dfn-ajax-fai-members.php     # Ricerca e verifica istantanea tessere FAI
│   ├── dfn-ajax-volunteers.php      # Gestione preferenze, riunioni e matrice turni
│   ├── dfn-ajax-promotions.php      # Salvataggio campagne, ripristino e coda AJAX
│   └── dfn-ajax-slot-manager.php    # Modifiche turni da backend
└── woocommerce/             # Integrazioni WooCommerce
    └── dfn-gateway-in-loco.php      # Gateway di pagamento in loco / contanti
```

---

## 3. 🧩 Architettura Modulare & Sistema ON/OFF (Software a Blocchi)

Il software è progettato per essere distribuibile e attivabile a blocchi indipendenti per diverse Delegazioni FAI, consentendo a ciascuna realtà locale di abilitare solo le funzionalità di cui necessita.

### A. I Tre Grandi Moduli di Delegazione
1. **Modulo 2.0 (Core Booking & Eventi)**:
   - Motore gestione eventi, fasce orarie e capienze.
   - Widget di prenotazione, tariffe soci FAI e interi, checkout, gateway di pagamento.
   - PWA Scanner e hub validazione biglietti / presenze.
   - *È la base portante dell'intero ecosistema.*
2. **Modulo 2.1 (Gestione Volontari & Matrice Turni)**:
   - Anagrafica volontari, flussi di approvazione preventiva (*pending* / *approved*).
   - Sondaggi disponibilità oraria e logistica con preferenza luogo.
   - Matrice assegnazione turni ad alta densità per Giornate FAI ed eventi locali.
   - Riunioni plenarie di Delegazione e bacheca riservata `/mio-account/`.
   - *Funziona in modo autonomo rispetto alla 2.0: se attivo estende il portale, se disattivato il core 2.0 opera regolarmente senza errori.*
3. **Modulo 2.1.1 (Team di Lavoro - Opzionale)**:
   - Suddivisione dei volontari in gruppi operativi specializzati (*Ambiente, Scuola, Guide, Comunicazione*).
   - Canali integrati (Click-to-Chat WhatsApp e Google Drive di Team).
   - Convocazioni e riunioni mirate riservate ai soli membri del team.
   - *Opzionabile all'interno della 2.1 tramite apposito switch.*

### B. I Sottomoduli di FAI Prenotazioni (Configurazione Centralizzata)
I moduli funzionali specifici delle prenotazioni sono concentrati all'interno del pannello **FAI Prenotazioni > Impostazioni > Moduli**:
- **Liste d'Attesa (`vol_enable_waitlist`)**: attiva la raccolta prenotazioni in overbooking quando un turno è esaurito.
- **Bilancio Evento (`vol_enable_accounting`)**: attiva la scheda di rendicontazione economica e quadratura di cassa.
- **Recensioni & Feedback (`vol_enable_reviews`)**: attiva il modulo di raccolta opinioni post-evento.
- **Botteghino Live (`vol_enable_live_boxoffice`)**: attiva il desk rapido per l'emissione istantanea all'ingresso.

### C. Regole Architetturali ON/OFF
- **Disaccoppiamento totale**: nessun modulo secondario o opzionale deve dare per scontata la presenza o l'attivazione di altri moduli.
- **Verifiche Difensive**: Il codice applica sempre controlli preventivi (`function_exists()`, `class_exists()`, `dfn_get_setting()`) per evitare chiamate a funzioni orfane quando un modulo è disattivato.
- **Aggiornamento Dinamico del DOM**: Quando uno switch viene salvato via AJAX, la visibilità dei relativi sottomenu (es. `#adminmenu`) viene aggiornata istantaneamente via JavaScript senza costringere l'utente a ricaricare la pagina.

---

## 4. 🔄 Flusso delle Richieste & Separazione degli Ambiti

- **Chiamate AJAX**: Tutta l'interazione del widget di prenotazione, della PWA e della coda promozioni avviene tramite endpoint asincroni con nonce di sicurezza dedicati (`dfn_booking_nonce`, `dfn_scanner_nonce`, `dfn_promotions_nonce`).
- **Bypass Sicurezza**: Le chiamate AJAX sono esentate dai filtri di redirect amministrativi (`dfn_sblocca_backend_volontari` e `dfn_block_wp_admin_for_volunteers`), garantendo risposte JSON immediate a visitatori e utenti loggati.
- **Integrazione WooCommerce**: I prodotti collegati agli eventi (`wp_dfn_events.product_id`) mantengono prezzi dinamici e sconti FAI agganciandosi agli hook nativi del carrello (`woocommerce_before_calculate_totals`, `woocommerce_cart_calculate_fees`).

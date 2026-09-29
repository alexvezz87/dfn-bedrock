# 📐 Architettura del Sistema

## 1. Panoramica
Il progetto **DFN Prenotazioni** è basato sullo stack moderno **Roots Bedrock**, che struttura WordPress come un'applicazione a 12 fattori con gestione dipendenze tramite Composer, isolamento delle configurazioni d'ambiente in file `.env` e separazione netta tra il core di WordPress (`/web/wp/`) e i contenuti applicativi (`/web/app/`).

```
dfn-bedrock/
├── config/                  # Configurazioni d'ambiente (environments, application.php)
├── web/
│   ├── app/
│   │   ├── mu-plugins/      # Must-use plugins
│   │   ├── plugins/         # Plugin WordPress (WooCommerce, ecc.)
│   │   └── themes/
│   │       └── dfn-theme/   # Tema personalizzato e core engine
│   └── wp/                  # Core WordPress isolato
└── docs/                    # Documentazione tecnica
```

---

## 2. Struttura Modulare di `dfn-theme`

Tutta la logica di business è organizzata in moduli dedicati all'interno della cartella `inc/`:

```
web/app/themes/dfn-theme/inc/
├── core/                    # Inizializzazione, Database, Notifiche, Setup, Cron, Helpers
│   ├── dfn-setup.php        # Ruoli, capabilities, login redirect, costanti e tema
│   ├── dfn-database.php     # Tabelle custom dbDelta, query CRUD, transazioni
│   ├── dfn-notifications.php# Motore email transazionali e reminder
│   ├── dfn-cron.php         # Processi schedulati (reminder, scadenza pending)
│   └── dfn-helpers.php      # Utility condivise, sanitizzazione nomi, validazioni
├── admin/                   # Dashboard e pannelli di gestione nel backend WP
│   ├── dfn-events-manager.php       # Dashboard eventi (Futuri/Passati)
│   ├── dfn-event-editor.php         # Creazione/Modifica evento e fasce orarie
│   ├── dfn-slot-manager.php         # Gestione turni orari, capienze e overbooking
│   ├── dfn-fai-members-admin.php    # Anagrafica soci FAI
│   ├── dfn-fai-pending-bookings.php # Approvazione manuale tessere FAI
│   ├── dfn-settings.php             # Impostazioni generali ed Email Builder
│   ├── dfn-volunteers-admin.php     # Anagrafica e gestione turni volontari
│   ├── dfn-volunteer-settings.php   # Impostazioni modulo volontari
│   ├── dfn-quick-booking.php        # Desk prenotazioni rapide per segreteria
│   ├── dfn-report.php               # Reportistica presenze e dati
│   └── dfn-accounting.php           # Rendicontazione contabile
├── frontend/                # Viste e componenti lato utente
│   ├── dfn-shortcodes.php           # Widget di prenotazione diretta [dfn_booking_widget]
│   ├── dfn-mobile-app.php           # PWA Gestione Eventi (/gestione-eventi/)
│   ├── dfn-myaccount.php            # Endpoint area personale /mio-account/
│   ├── dfn-volunteer-registration.php # Registrazione e vista turni volontario
│   ├── dfn-hub-biglietti.php        # Visualizzazione e download PDF biglietti
│   └── dfn-gdpr.php                 # Consenso privacy e cookie GDPR
├── api/                     # Endpoint AJAX e router
│   ├── dfn-ajax-bookings.php        # Creazione prenotazione, calcolo carrello/sconti
│   ├── dfn-ajax-slots.php           # Recupero disponibilità turni in tempo reale
│   ├── dfn-ajax-scanner.php         # Validazione QR code e check-in
│   ├── dfn-ajax-fai-members.php     # Ricerca e verifica istantanea tessere FAI
│   └── dfn-ajax-slot-manager.php    # Modifiche turni da backend
└── woocommerce/             # Integrazioni WooCommerce
    └── dfn-gateway-in-loco.php      # Gateway di pagamento in loco / contanti
```

---

## 3. Flusso delle Richieste & Separazione degli Ambiti

- **Chiamate AJAX**: Tutta l'interazione del widget di prenotazione e della PWA avviene tramite endpoint AJAX su `/wp/wp-admin/admin-ajax.php` con nonce di sicurezza dedicato (`dfn_booking_nonce`, `dfn_scanner_nonce`).
- **Bypass Sicurezza**: Le chiamate AJAX sono esentate dai filtri di redirect amministrativi (`dfn_sblocca_backend_volontari` e `dfn_block_wp_admin_for_volunteers`), garantendo risposte JSON immediate a visitatori e utenti loggati.
- **Integrazione WooCommerce**: I prodotti collegati a eventi (`wp_dfn_events.product_id`) mantengono prezzi dinamici e commissioni differenziate (soci FAI) agganciandosi agli hook di calcolo del carrello WooCommerce (`woocommerce_before_calculate_totals`, `woocommerce_cart_calculate_fees`).

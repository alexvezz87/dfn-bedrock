# 📖 DFN Prenotazioni & Gestione Eventi - Documentazione

Benvenuti nella documentazione tecnica e architetturale ufficiale di **DFN Prenotazioni** (`dfn-bedrock`).

Questa documentazione raccoglie tutti i dettagli implementativi, le logiche di business, lo schema dati e le guide operative del progetto.

---

## 🗂️ Indice dei Contenuti

1. [📐 Architettura del Sistema](ARCHITECTURE.md)
   - Struttura Roots Bedrock (12-factor app)
   - Organizzazione modulare del tema `dfn-theme`
   - Integrazione WooCommerce e Gateway Personalizzati

2. [🗄️ Schema Database & Tabelle Personalizzate](DATABASE_SCHEMA.md)
   - Tabelle `wp_dfn_events`, `wp_dfn_slots`, `wp_dfn_bookings`, `wp_dfn_booking_slots`
   - Tabelle `wp_dfn_volunteers`, `wp_dfn_fai_members`
   - Relazioni, vincoli e transazioni atomiche

3. [🔐 Matrice Ruoli, Permessi e Sicurezza](ROLES_AND_PERMISSIONS.md)
   - Ruoli operativi: Segreteria, Capogruppo, Validatore, Volontario
   - Capabilities custom e controllo accessi `wp-admin`
   - Regole di login redirect per ruolo

4. [🎟️ Motore di Prenotazione & Flussi Operativi](BOOKING_ENGINE.md)
   - Widget frontend di prenotazione diretta
   - Allocazione automatica dei posti e blocco overbooking
   - Verifica tessere soci FAI e approvazione manuale
   - Gestione automatica della Lista d'Attesa (*Waitlist*)

5. [📱 Web App PWA Scanner & Check-in](PWA_SCANNER.md)
   - Web App frontend su `/gestione-eventi/`
   - Scanner fotocamera QR Code ad alte prestazioni
   - Ricerca partecipanti, check-in rapido e banchetto cassa

6. [🚀 Git Workflow, Branching & Deployment](DEPLOYMENT_AND_GIT.md)
   - Struttura dei rami Git (`dfn-2.0`, `dfn-2.1`, `staging`, `main`)
   - Sincronizzazione automatica e manuale FTP su Staging e Produzione
   - Policy di merge e rilascio

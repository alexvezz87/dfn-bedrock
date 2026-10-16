# 🧭 DFN Bedrock — Regole e Iter degli Sviluppi

> **Documento di Riferimento Operativo per Sviluppatori e Assistenti AI**  
> Questo file stabilisce le linee guida, i flussi di lavoro e le policy architetturali vincolanti per il repository `dfn-bedrock`. Viene caricato automaticamente ad ogni sessione per garantire continuità, coerenza e rispetto degli accordi stabiliti con il project owner (**Alex**).

---

## 1. 🧩 Architettura Modulare & Sistema ON/OFF (Software a Moduli Distribuibile)

Il software è progettato per essere distribuibile e attivabile a blocchi indipendenti per diverse Delegazioni FAI, consentendo a ciascuna realtà locale di abilitare solo le funzionalità di cui necessita.

### A. Mappatura dei Moduli
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
   - *Funziona in modo autonomo rispetto alla 2.0: se attivo, estende il portale; se disattivato, il core 2.0 opera regolarmente senza errori.*
3. **Modulo 2.1.1 (Team di Lavoro - Opzionale)**:
   - Suddivisione dei volontari in gruppi operativi specializzati (es. *Ambiente, Scuola, Guide, Comunicazione*).
   - Nomina di Delegati Responsabili / Supervisori di Team.
   - Canali integrati (Click-to-Chat WhatsApp e cartelle Google Drive di Team).
   - Riunioni mirate e convocazioni riservate solo ai membri di uno specifico Team.
   - *Opzionabile all'interno della 2.1 tramite apposito switch nelle impostazioni.*
4. **Moduli Futuri (`2.2`, `2.3`...)**:
   - Moduli funzionali aggiuntivi (es. mailing list avanzata, ticketing, marketing) che seguiranno la medesima architettura modulare disaccoppiata.

### B. Regola Architetturale ON/OFF
- **Disaccoppiamento totale**: nessun modulo secondario o opzionale deve dare per scontata la presenza o l'attivazione di altri moduli.
- **Controllo di attivazione (Feature Toggle)**: ogni modulo opzionale deve disporre di uno switch nelle impostazioni (es. `vol_enable_teams`). Se disattivato:
  - Le voci di menu corrispondenti (es. *Team di lavoro*) vengono nascoste.
  - Le interfacce tornano automaticamente alla modalità base (es. *Modalità 2.1 Pura - Delegazione Unica*).
  - Il codice deve utilizzare verifiche difensive (`function_exists()`, `class_exists()`, controlli flag a database) per evitare chiamate a funzioni orfane.

---

## 2. 📋 Tracciamento GitHub Issue & Timeline Storica

### A. Obbligo di Issue per Ogni Intervento
- **Nessuno sviluppo "alla cieca"**: ogni singola feature, modifica o bugfix deve essere tracciata tramite una **Issue su GitHub**.
- La Issue deve specificare chiaramente:
  - Descrizione del bisogno o anomalia.
  - Soluzione tecnica implementata.
  - Branch di lavoro e commit hash.
  - Ambienti verificati (Staging / Produzione).

### B. Consultazione Preventiva dello Storico & Timeline
- **Prima di sviluppare**: consultare sempre le issue (aperte e chiuse) su GitHub e la documentazione interna per comprendere il contesto, recuperare le scelte architetturali passate ed evitare re-implementazioni ridondanti.
- **Catena temporale (Follow-up)**: se un nuovo lavoro o fix tocca parti già sviluppate in precedenza, la nuova issue **deve fare esplicito riferimento alla issue storica** (es. `Rif. #55`, `Follow-up di #56`), costruendo una linea temporale trasparente degli interventi.
- **Chiusura documentata**: a collaudo completato, aggiornare e chiudere la issue con il riassunto dell'esito.

---

## 3. 🌿 Disciplina dei Branch Git

### A. Mappatura dei Branch
- **`dfn-2.0.1-gestione-prenotazioni`**: Modulo Core Eventi & Booking Engine (versione attiva corrente, sostituisce la 2.0).
- **`dfn-2.1-gestione-volontari`**: Modulo Gestione Volontari standard.
- **`dfn-2.1.1-gestione-team`**: Modulo Gestione Team di Lavoro.
- **`staging`**: Ramo di collaudo pre-produzione, allineato con l'ambiente Staging.
- **`main`**: Ramo di produzione stabile.

### B. Regole di Focus e Cautela
- **Sviluppare sempre sul branch di competenza del modulo**.
- **Chiedere sempre conferma preventiva ad Alex**: se non è chiaro quale sia il branch di destinazione, o se una modifica ha impatto trasversale su più rami, **fermarsi e chiedere conferma prima di fare commit o merge**.
- Mai eseguire merge incrociati non pianificati tra branch di feature differenti.

---

## 4. 🚀 Policy di Rilascio: Staging First & Produzione Protetta

### A. Rilascio su Staging (Collaudo)
- Tutti i test, collaudi e verifiche funzionali avvengono **esclusivamente sull'ambiente di Staging**:
  - **URL**: `https://staging.dfnprenotazioni.it`
  - **Procedura di deploy**:
    1. Merge del branch di lavoro sul branch `staging`.
    2. Push su `origin staging`.
    3. Esecuzione dello script dedicato:
       ```bash
       python scripts/deploy_theme_staging.py
       ```
    4. Ritorno al branch di lavoro originario.

### B. Protezione Assoluta di `main` e Produzione
- **`main` e la Produzione (`https://www.dfnprenotazioni.it`) NON si toccano MAI in autonomia**:
  - Nessun merge su `main` e nessun deploy con `scripts/deploy_theme_prod.py` può essere effettuato senza che Alex abbia completato il collaudo su Staging e dato **esplicita autorizzazione scritta**.

---

## 5. 📬 Gestione Notifiche Email & Sicurezza Staging

1. **Notifiche Facoltative**: l'invio delle comunicazioni email è un parametro configurabile e facoltativo, mai imposto in modo rigido.
2. **Separazione delle Configurazioni**:
   - **Notifiche Eventi/Prenotazioni (v2.0)**: *FAI Prenotazioni* &rarr; *Impostazioni* &rarr; *Email & Notifiche / Testi E-mail*.
   - **Notifiche Volontari (v2.1)**: *Volontari FAI* &rarr; *Impostazioni* &rarr; *Notifiche & Destinatari / Modelli E-mail*.
   - **Notifiche Team (v2.1.1)**: *Volontari FAI* &rarr; *Impostazioni* &rarr; *Team di Lavoro & Canali* + Modifica singolo Team.
3. **Protezione Sandbox su Staging**:
   - In ambiente di Staging è fondamentale evitare l'invio accidentale di email a volontari o visitatori reali.
   - Utilizzare le modalità protette del sistema:
     - 🔴 **Mute / Silenziosa**: blocca tutte le email in uscita e le registra solo nei log di sistema.
     - 🟡 **Sandbox Redirect**: devia tutte le notifiche verso un'unica casella di posta di test.

---

## 6. ⚙️ Standard Tecnici & Architettura Bedrock

1. **Struttura Roots Bedrock**:
   - WordPress risiede nel subpath `/wp/`. Gli endpoint reali sono `/wp/wp-admin/admin.php` e `/wp/wp-admin/admin-ajax.php`.
2. **Salvataggio Impostazioni & Evitare Socket Drop**:
   - **No Redirect 302 bruschi con `exit;`**: i form di amministrazione non devono eseguire `wp_safe_redirect` + `exit;` sincrono su POST con action assoluta, poiché Apache/reverse-proxy Bedrock può interrompere la connessione TCP (`DNS_PROBE_FINISHED_NXDOMAIN` o reset socket).
   - **Pattern Raccomandato**: salvataggio asincrono AJAX via `admin-ajax.php` con fallback sincrono interno al ciclo di rendering senza redirect forzato, utilizzando i messaggi nativi `add_settings_error()`.
3. **Aggiornamento Dinamico del DOM**:
   - Quando una modifica alle impostazioni impatta la visibilità dell'interfaccia (es. attivazione/disattivazione del sottomenu *Team di lavoro*), l'aggiornamento deve riflettersi immediatamente nel DOM (es. `#adminmenu`) senza costringere l'utente a un reload manuale della pagina.

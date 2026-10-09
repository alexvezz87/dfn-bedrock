# 🚀 Git Workflow, Branching & Deployment

Il progetto adotta un modello di branching strutturato per garantire stabilità, isolamento delle feature e rilasci controllati tra ambienti di sviluppo, staging e produzione.

---

## 1. Struttura dei Rami Git

```mermaid
gitGraph
    commit id: "v1.0"
    branch dfn-2.0-gestione-prenotazioni
    checkout dfn-2.0-gestione-prenotazioni
    commit id: "feat: Booking Engine v2.0"
    commit id: "feat: PWA Scanner"
    branch dfn-2.1-gestione-volontari
    checkout dfn-2.1-gestione-volontari
    commit id: "feat: Volunteer System"
    checkout main
    merge dfn-2.1-gestione-volontari id: "Merge to Main"
    checkout staging
    merge main id: "Deploy Staging"
```

### Ruolo dei Rami:
- **`dfn-2.0-gestione-prenotazioni`**: Modulo Core Eventi & Booking Engine (editor eventi, widget di prenotazione, tariffe soci/interi, checkout, gateway, PWA Scanner e hub biglietti).
- **`dfn-2.1-gestione-volontari`**: Modulo Gestione Volontari (anagrafica volontari, sondaggi disponibilità, matrice logistica turni e area personale).
- **`dfn-2.1.1-gestione-team`**: Modulo Gestione Team e Capi Delegazione.
- **`dfn-2.2` / `dfn-2.3` (futuri)**: Moduli funzionali aggiuntivi (es. mailing list/promozioni, assistenza/ticketing).
- **`main`**: Ramo di riferimento della produzione (contiene l'unione controllata di tutti i moduli stabili).
- **`staging`**: Ramo allineato all'ambiente di collaudo e test pre-produzione.

---

## 2. Policy di Sviluppo, Tracciamento Issue & Modularità

### A. Tracciamento Obbligatorio delle Issue su GitHub & Timeline Storica
1. **Ogni singolo sviluppo o fix deve essere notificato come Issue su GitHub**, specificando:
   - Descrizione chiara del bisogno/anomalia
   - Soluzione tecnica implementata
   - Branch di pertinenza e hash del commit
   - Ambienti verificati e rilasciati (Staging/Produzione)
2. **Timeline e Riferimento allo Storico**: Se un nuovo sviluppo o bugfix interviene su parti già trattate in precedenza, **la nuova Issue deve fare esplicito riferimento alla issue storica correlata** (es. `Rif. #55`, `Follow-up di #56`), costruendo una linea temporale trasparente degli interventi e delle scelte architetturali.

### B. Architettura Modulare & Sistema ON/OFF (Software a Moduli)
1. **Disaccoppiamento dei Moduli**: I branch (`2.0`, `2.1`, `2.1.1`, `2.2`, ecc.) corrispondono a macro-funzionalità che devono poter operare in modo indipendente o con dipendenze strettamente controllate e disaccoppiate.
2. **Sistema di Attivazione/Disattivazione ON/OFF**: Il software è progettato per consentire l'attivazione/disattivazione modulare dei singoli componenti (tramite impostazioni e feature toggles). Il codice di un modulo secondario non deve mai rompere o assumere come garantita la presenza o lo stato attivo degli altri moduli.
3. **Regola del Branch di Destinazione**: Tutte le modifiche relative a un modulo devono essere sviluppate e committate **sul branch del rispettivo modulo**. In caso di incertezza o modifiche trasversali, **è obbligatorio chiedere conferma preventiva prima di procedere** con commit o merge.

---

## 3. Ambienti & Deployment FTP

| Ambiente | URL | Server FTP | Modalità Sync |
|---|---|---|---|
| **Produzione** | `https://www.dfnprenotazioni.it` | `ftp.dfnprenotazioni.it` | Script automatico (`scripts/deploy_theme_prod.py`) |
| **Staging** | `https://staging.dfnprenotazioni.it` | `ftp.dfnprenotazioni.it` | Script automatico (`scripts/deploy_theme_staging.py`) |

### Percorso di pubblicazione:
I file sorgente del tema modificati in `web/app/themes/dfn-theme/` vengono sincronizzati ricorsivamente sul percorso remoto corrispondente (`web/app/themes/dfn-theme/`).

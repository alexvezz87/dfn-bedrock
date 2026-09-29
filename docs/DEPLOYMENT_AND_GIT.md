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
- **`dfn-2.0-gestione-prenotazioni`**: Ramo base del motore di prenotazione avanzato, editor eventi, widget, PWA e allocazione posti.
- **`dfn-2.1-gestione-volontari`**: Ramo attivo di sviluppo per il modulo volontari FAI, turni, anagrafiche e area `/mio-account/`.
- **`main`**: Ramo di riferimento della produzione.
- **`staging`**: Ramo allineato all'ambiente di collaudo e test pre-produzione.

---

## 2. Policy di Sviluppo & Merge
1. **Regola fondamentale**: Ogni modifica al core del motore di prenotazione deve essere effettuata su `dfn-2.0-gestione-prenotazioni` e poi propagata tramite merge a cascata su `dfn-2.1-gestione-volontari`, `main` e `staging`.
2. **Allineamento**: Prima di effettuare modifiche a un modulo, verificare sempre che il proprio ramo locale sia allineato con `git fetch origin` e `git pull`.

---

## 3. Ambienti & Deployment FTP

| Ambiente | URL | Server FTP | Modalità Sync |
|---|---|---|---|
| **Produzione** | `https://www.dfnprenotazioni.it` | `ftp.dfnprenotazioni.it` | Sync via script automatico / GitHub Actions |
| **Staging** | `https://staging.dfnprenotazioni.it` | `ftp.dfnprenotazioni.it` | Sync via script automatico / GitHub Actions |

### Percorso di pubblicazione:
I file sorgente del tema modificati in `web/app/themes/dfn-theme/` vengono sincronizzati ricorsivamente sul percorso remoto corrispondente (`web/app/themes/dfn-theme/`).

# 👥 Struttura Delegazione, Volontari e Team di Lavoro

## 1. Modello Organizzativo Delegazione FAI Novara

La piattaforma rispecchia fedelmente la struttura gerarchica ed operativa della **Delegazione FAI Novara**.
L'organigramma è costituito da figure di coordinamento/governance (**Ruoli Delegazione**) e da gruppi operativi specializzati (**Team di Lavoro**).

```
                        ┌─────────────────────┐
                        │  Capo Delegazione   │
                        └──────────┬──────────┘
                                   │
              ┌────────────────────┼────────────────────┐
              │                    │                    │
    ┌─────────┴─────────┐ ┌────────┴────────┐ ┌────────┴────────┐
    │    Segretaria     │ │ Coord.Volontari │ │ Delegati d'Area │
    └───────────────────┘ └─────────────────┘ └────────┬────────┘
                                                       │
        ┌───────────────────┬───────────────────┬──────┴────────────┬───────────────────┐
        │                   │                   │                   │                   │
┌───────┴───────┐   ┌───────┴───────┐   ┌───────┴───────┐   ┌───────┴───────┐   ┌───────┴───────┐
│ Team Ambiente │   │  Team Eventi  │   │  Team Scuola  │   │   Team Com.   │   │  Team Guide   │
│ Resp: Del.Amb │   │ Resp: Del.Eve │   │ Resp: Del.Scu │   │ Resp: Del.Com │   │ Resp: Del.Eve │
└───────┬───────┘   └───────┬───────┘   └───────┬───────┘   └───────┬───────┘   └───────┬───────┘
        │                   │                   │                   │                   │
        └───────────────────┴─────────┬─────────┴───────────────────┴───────────────────┘
                                      │
                        ┌─────────────┴─────────────┐
                        │   Volontari di Delegazione│
                        │ (Iscrizione Multi-Team)   │
                        └───────────────────────────┘
```

---

## 2. Ruoli e Delegati di Funzione

| Ruolo / Titolo | Descrizione Operativa | Ambiti di Competenza |
|---|---|---|
| **Capo Delegazione** | Direzione e supervisione generale delle attività | Governance completa, visione d'insieme su tutti i team |
| **Segretaria** | Gestione amministrativa, corrispondenza e cassa desk | Desk rapido, anagrafiche, verbali e comunicazioni |
| **Delegato al Coordinamento Volontari** | Monitoraggio presenze, disponibilità e accoglienza | Gestione anagrafica volontari e turnazioni generali |
| **Delegato Eventi** | Progettazione, logistica e coordinamento manifestazioni | Supervisione **Team Eventi** e **Team Guide** |
| **Delegato Scuola** | Rapporti con gli istituti scolastici e Apprendisti Ciceroni | Supervisione **Team Scuola** |
| **Delegato Comunicazione** | Gestione canali social, newsletter, stampa e media | Supervisione **Team Comunicazione** |
| **Delegato Ambiente** | Progetti ecologici, tutela paesaggistica e territorio | Supervisione **Team Ambiente** |

---

## 3. Team di Lavoro (Squadre Operative)

I Team di Lavoro sono gruppi permanenti o stagionali che riuniscono i volontari per progetti specifici.

### Squadre Predefinite & Assegnazione Supervisori:
1. **Team Ambiente**: Coordinato dal *Delegato Ambiente*.
2. **Team Eventi**: Coordinato dal *Delegato Eventi*.
3. **Team Scuola**: Coordinato dal *Delegato Scuola*.
4. **Team Comunicazione**: Coordinato dal *Delegato Comunicazione*.
5. **Team Guide**: Coordinato dal *Delegato Eventi*.

### Regole di Business dei Team:
- **Appartenenza Multi-Team**: Ciascun volontario può far parte contemporaneamente di uno o più team di lavoro (ad esempio, un volontario può essere sia nel *Team Guide* sia nel *Team Eventi*).
- **Indipendenza e Confronto**: Ogni team opera in autonomia con i propri canali e riunioni, potendo collaborare con altri gruppi per eventi congiunti.
- **Gestione Dinamica**: Attraverso il pannello di amministrazione (`dfn-teams-admin.php`) è possibile creare nuovi team, modificarne la descrizione e associare uno o più ruoli/delegati come responsabili.

---

## 4. Governance delle Riunioni & Promemoria

### Regola di Creazione Riunioni (Supervisor-Only)
- **Autorizzazione alla creazione**: Le riunioni dedicate a un gruppo di lavoro possono essere convocate e create **esclusivamente dal supervisore assegnato a quel team** (oppure da un amministratore/Capo Delegazione).
- Un delegato di un altro team non può creare riunioni per team di cui non è supervisore.

### Filtro Visibilità Convocazioni
- I volontari visualizzano nella propria area riservata (`/mio-account/volontari-fai/`) solo le riunioni generali di Delegazione e quelle relative ai **team a cui sono effettivamente iscritti**.

---

## 5. Motore Notifiche e Promemoria Schedulati

All'interno di **Impostazioni Volontari** (`/wp-admin/admin.php?page=dfn-volunteer-settings`), è presente un tab dedicato **"Notifiche"**.

### Funzionalità del Sistema Notifiche:
1. **Configurazione Dinamica Promemoria**: L'amministratore può definire regole multiple di invio (es. 1 settimana prima, 1 giorno prima, 2 ore prima).
2. **Attivazione/Disattivazione Canali**: Possibilità di abilitare/disabilitare email o notifiche per tipo di riunione (Generale o per singolo Team).
3. **Esecuzione Cron**: Un task schedulato (`dfn_cron_send_meeting_reminders`) verifica le riunioni in arrivo e recapita l'avviso con template personalizzabile ai soli membri del team target.
4. **Preferenze Volontario**: Rispetto delle preferenze di notifica salvate dal volontario nel proprio profilo.

---

## 6. Schema Dati Modulo Team (v2.1)

```sql
-- Tabella Anagrafica Team
CREATE TABLE wp_dfn_teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    supervisor_role VARCHAR(50) NULL,
    supervisor_user_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Tabella Relazione Volontari <-> Team (Many-to-Many)
CREATE TABLE wp_dfn_team_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_team_user (team_id, user_id)
);

-- Tabella Riunioni
CREATE TABLE wp_dfn_meetings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    team_id BIGINT UNSIGNED NULL, -- NULL = Riunione Plenaria/Tutti i Volontari
    title VARCHAR(255) NOT NULL,
    meeting_date DATETIME NOT NULL,
    location VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

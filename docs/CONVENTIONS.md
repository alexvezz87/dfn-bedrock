# 🤝 Gestione Avanzata Convenzioni FAI (Milestone v2.2)

## 1. Stato Attuale vs. Obiettivo v2.2

### Stato Attuale (v2.0 / Produzione)
Attualmente sul sito in produzione, le convenzioni FAI sono gestite come articoli/post WordPress standard ([https://www.dfnprenotazioni.it/convenzioni/](https://www.dfnprenotazioni.it/convenzioni/)). Questa soluzione offre una presentazione base ma manca di dati strutturati, geolocalizzazione, logiche di validità temporale e integrazione con il database soci.

### Visione Evolutiva (Milestone v2.2)
L'obiettivo della **Milestone 2.2** è trasformare la gestione delle convenzioni in un sistema **modulare, strutturato e interattivo**, in grado di offrire sia un'esperienza d'uso avanzata per gli iscritti FAI, sia un pannello di controllo completo per la Delegazione.

---

## 2. Requisiti Chiave del Nuovo Motore Convenzioni

```
┌─────────────────────────────────────────────────────────────┐
│                 MOTORE CONVENZIONI FAI                      │
└──────────────────────────────┬──────────────────────────────┘
                               │
       ┌───────────────────────┼───────────────────────┐
       │                       │                       │
┌──────┴──────────────┐ ┌──────┴──────────────┐ ┌──────┴──────────────┐
│ Anagrafica Partner  │ │ Regole di Sconto    │ │ Geo & Categorie     │
│ & Esercenti         │ │ & Validità          │ │ Mappa & Ricerca     │
└─────────────────────┘ └─────────────────────┘ └─────────────────────┘
```

### A. Anagrafica Strutturata Esercenti / Partner
- Ragione sociale, logo in alta definizione, contatti (telefono, email, sito web, social).
- Referente commerciale e accordo di convenzione (documento/allegato firmato).
- Sede principale ed eventuali punti vendita/filiali multiple.

### B. Condizioni di Sconto & Vantaggi FAI
- Tipologia di agevolazione: percentuale (es. 10%, 20%), importo fisso (€), tariffa ridotta, 2x1, benefit esclusivo o omaggio.
- Dettaglio condizioni (es. "valido nei giorni feriali", "non cumulabile con altre promozioni", "esteso a 1 accompagnatore").
- Validità temporale: data inizio, data scadenza, rinnovo automatico/manuale con avviso di scadenza accordo.

### C. Mappa Interattiva & Ricerca Territoriale
- Geolocalizzazione automatica dei punti convenzionati con coordinate GPS.
- Mappa interattiva frontend con filtri per:
  - **Categoria merceologica**: Musei e Cultura, Ristorazione ed Enogastronomia, Hotel e Dimore Storiche, Tempo Libero, Negozi e Artigianato, Servizi.
  - **Distanza / Raggio chilometrico** (geolocalizzazione utente).
  - **Comune / Zona**.

### D. Integrazione con Anagrafica Soci FAI & Frontend
- Esibizione digitale della tessera FAI dal profilo utente (`/mio-account/`) per usufruire della convenzione sul posto.
- Possibilità per gli esercenti partner convenzionati di verificare la validità della tessera socio FAI tramite QR code o numero tessera.

---

## 3. Roadmap di Sviluppo & Riferimenti GitHub

- **Milestone GitHub**: [v2.2 - Gestione Avanzata Convenzioni FAI](https://github.com/alexvezz87/dfn-bedrock/milestone/5)
- **Issue Principale**: [#23 - feat(convenzioni): Sistema avanzato e strutturato di gestione Convenzioni FAI](https://github.com/alexvezz87/dfn-bedrock/issues/23)
- **Kanban Board**: [DFN Prenotazioni e Volontari - Roadmap](https://github.com/users/alexvezz87/projects/1)

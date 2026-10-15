# 📙 Manuale Utente v2.1: Gestione Volontari FAI, Logistica Turni & Algoritmo di Assegnazione

> **A chi è rivolto**: Segreteria di Delegazione, Delegato al Coordinamento Volontari, Capigruppo di Postazione, Delegati e Volontari.  
> **Obiettivo**: Spiegare in modo semplice e pratico l'intero flusso di gestione dei volontari: dall'anagrafica e candidatura online, alla pianificazione logistica degli eventi (Locali e Giornate FAI), fino al sondaggio disponibilità, all'assegnazione automatica con algoritmo e alla matrice turni interattiva.

---

## 📑 Indice dei Contenuti
1. [Panoramica dell'Ecosistema Volontari & Logistica](#1-panoramica-dellecosistema-volontari--logistica)
2. [Anagrafica, Candidatura Online & Verifica SiVol FAI](#2-anagrafica-candidatura-online--verifica-sivol-fai)
3. [Creazione Eventi Logistica (in parallelo a FAI Prenotazioni)](#3-creazione-eventi-logistica-in-parallelo-a-fai-prenotazioni)
4. [Il Sistema di Sondaggio Disponibilità (Survey)](#4-il-sistema-di-sondaggio-disponibilità-survey)
5. [L'Algoritmo di Assegnazione Automatica & Bilanciamento Turni](#5-lalgoritmo-di-assegnazione-automatica--bilanciamento-turni)
6. [La Matrice Turni Interattiva (Griglia & Drag & Drop)](#6-la-matrice-turni-interattiva-griglia--drag--drop)
7. [Stampa ed Export Operativo dei Turni di Delegazione](#7-stampa-ed-export-operativo-dei-turni-di-delegazione)
8. [L'Area Riservata del Volontario (My Account)](#8-larea-riservata-del-volontario-my-account)
9. [Impostazioni e Modelli Email](#9-impostazioni-e-modelli-email)

---

## 1. Panoramica dell'Ecosistema Volontari & Logistica

Il modulo Volontari 2.1 gestisce in maniera integrata due mondi complementari:
1. **La Governance delle Persone**: anagrafiche, tessere FAI, abilitazioni, corsi sicurezza, ruoli e area personale.
2. **La Logistica Operativa degli Eventi**: fabbisogni di personale, sondaggi di disponibilità, abbinamento automatico intelligente dei turni e tabellone orario delle postazioni.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                        FLUSSO INTEGRATO LOGISTICA & VOLONTARI                          │
├───────────────────┬───────────────────┬───────────────────────┬────────────────────────┤
│ 1. PIANIFICAZIONE │ 2. SONDAGGIO      │ 3. ALGORITMO AUTOMATICO│ 4. MATRICE OPERATIVA  │
│ Creazione Evento  │ Invio richiesta   │ Incrocio disponibilità│ Perfezionamento manuale│
│ Luoghi, Turni     │ ai volontari      │ Competenze, preferenze│ Drag & Drop e stampa   │
│ & Fabbisogno      │ con scadenza      │ e bilanciamento carichi│ fogli per capigruppo   │
└───────────────────┴───────────────────┴───────────────────────┴────────────────────────┘
```

---

## 2. Anagrafica, Candidatura Online & Verifica SiVol FAI

### A. Candidatura Online dal Sito
- I nuovi aspiranti volontari compilano il modulo `/diventa-volontario/` inserendo dati anagrafici, contatti ed eventuale numero di tessera FAI.
- Il sistema invia istantaneamente una **email di presa in carico** al candidato e una notifica alla Segreteria di Delegazione.

### B. Approvazione nel Backend (`Volontari FAI > Elenco Volontari`)
Nel tabellone principale, le nuove richieste compaiono con lo stato **In Attesa** (evidenziate in giallo).

Cliccando su **Approva**:
1. Si inserisce/verifica il **Numero Tessera FAI** e la data di scadenza.
2. Si seleziona la spunta **"Registrato su SiVol FAI"** per confermare la regolarità sul portale gestionale nazionale.
3. Si impostano le **Competenze & Abilitazioni**:
   - **Volontario Guida**: abilitato a condurre visite narrate e percorsi culturali.
   - **Corso Sicurezza Attivo**: abilitato alla gestione del pubblico e mansioni di presidio secondo normativa.
4. Si assegnano gli **Incarichi di Delegazione** (es. *Banchetto & Accoglienza*, *Validatore QR*, *Segreteria*, ecc.).
5. Cliccando su **Conferma Approvazione & Attiva**: il sistema attiva l'account e invia in automatico l'**email di benvenuto** con le credenziali per l'Area Riservata.

---

## 3. Creazione Eventi Logistica (in parallelo a FAI Prenotazioni)

Accedendo al menu **Volontari FAI > Turni & Logistica**, la Delegazione pianifica la copertura dell'evento.

```
┌─────────────────────────────────────────────────────────────┐
│               TIPOLOGIE DI EVENTO LOGISTICA                 │
├──────────────────────────────┬──────────────────────────────┤
│ 📍 EVENTO LOCALE             │ 🏛️ GIORNATA FAI              │
│ Singolo bene o manifestazione│ Manifestazione multiluogo    │
│ Collegabile direttamente a un│ (es. 5-10 beni aperti,       │
│ evento di FAI Prenotazioni   │ Sabato e Domenica con        │
│ per sincronizzare orari.     │ decine di turni in parallelo)│
└──────────────────────────────┴──────────────────────────────┘
```

### Configurazione Guidata:
1. **Dati Base**: Titolo, tipologia (*Evento Locale* o *Giornata FAI*) e date di inizio/fine.
2. **Collegamento a FAI Prenotazioni**: Se l'evento è già presente nel tabellone prenotazioni pubblico, puoi selezionarlo dal menu a tendina per collegare direttamente le due gestioni.
3. **Definizione dei Luoghi Aperti (Postazioni)**:
   - Inserimento delle sedi (es. *Palazzo Natta*, *Castello Visconteo*, *Banchetto Piazza Martiri*).
4. **Fabbisogno per Turno (Quanti volontari servono?)**:
   - Per ciascun luogo e orario (es. *Sabato Mattina*, *Sabato Pomeriggio*), si definisce il fabbisogno di personale per mansione:
     - es. *2 Responsabili Banchetto/Cassa*, *4 Ciceroni/Guide*, *2 Addetti Accoglienza/Fila*.

---

## 4. Il Sistema di Sondaggio Disponibilità (Survey)

Prima di compilare i turni, la Delegazione raccoglie le disponibilità reali dei volontari:

1. **Attivazione del Sondaggio**:
   - Dalla scheda dell'evento logistico, si clicca su **Sondaggio**.
   - Si imposta la **data e ora di scadenza** per la compilazione.
   - Si invia l'invito via email a tutti i volontari attivi.
2. **Compilazione da parte del Volontario**:
   - Il volontario accede al link dedicato o dalla propria area riservata.
   - Indica per ogni giornata e fascia oraria la propria disponibilità (*Disponibile / Non disponibile*).
   - Può esprimere preferenze sui luoghi o sulle mansioni gradite.
3. **Monitoraggio Risposte in Tempo Reale**:
   - La Segreteria visualizza la percentuale di risposte ricevute, quanti volontari hanno dato disponibilità per ogni fascia e gli slot ancora scoperti.

---

## 5. L'Algoritmo di Assegnazione Automatica & Bilanciamento Turni

Quando il sondaggio è concluso, la Segreteria non deve incrociare centinaia di risposte a mano: basta cliccare su **⚡ Assegnazione Automatica con Algoritmo**.

### Come ragiona l'algoritmo intelligente:
1. **Priorità alle Competenze Specialistiche**:
   - I ruoli critici come *Volontario Guida* e *Responsabile Sicurezza* vengono assegnati prioritariamente a chi possiede le abilitazioni verificate nell'anagrafica.
2. **Rispetto delle Preferenze**:
   - L'algoritmo assegna i volontari nei luoghi e nelle fasce orarie che hanno indicato come preferite nel sondaggio.
3. **Equità e Bilanciamento dei Carichi di Lavoro**:
   - Distribuisce i turni in modo uniforme: evita che alcuni volontari facciano troppi turni consecutivi lasciandone altri inutilizzati.
4. **Prevenzione Conflitti & Anti-Sovrapposizione**:
   - Impossibile che un volontario venga assegnato contemporaneamente a due luoghi diversi nello stesso orario.
5. **Esito Istantaneo**:
   - L'algoritmo restituisce il numero di turni coperti con successo e segnala gli eventuali slot residui rimasti scoperti per carenza di candidature.

---

## 6. La Matrice Turni Interattiva (Griglia & Drag & Drop)

Dopo l'elaborazione automatica (o per comporre i turni manualmente), si accede a **🧩 Matrice Turni**:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        MATRICE TURNI LOGISTICA                         │
├───────────────────┬──────────────────────────┬─────────────────────────┤
│ LUOGO / POSTAZIONE│ MATTINA (09:30 - 13:00)  │ POMERIGGIO (14:30-18:30)│
├───────────────────┼──────────────────────────┼─────────────────────────┤
│ 🏛️ PALAZZO NATTA  │ 🟢 [Mario Rossi - Resp]  │ 🟢 [Paolo Bianchi]      │
│ (Fabbisogno: 2)   │ 🟢 [Anna Verdi - Guida]  │ 🟡 [Slot da coprire]    │
├───────────────────┼──────────────────────────┼─────────────────────────┤
│ 🏰 CASTELLO       │ 🟢 [Luca Neri - Cicerone]│ 🟢 [Luca Neri]          │
│ (Fabbisogno: 2)   │ 🟢 [Sara Gialli]         │ 🟢 [Elena Rosa]         │
└───────────────────┴──────────────────────────┴─────────────────────────┘
```

### Funzionalità della Matrice:
- **Spostamento Drag & Drop**: Vuoi spostare un volontario da un turno all'altro o da Palazzo Natta al Castello? Basta trascinare la sua targhetta con il mouse da uno slot all'altro.
- **Colori di Stato Immediati**:
  - 🟢 **Verde**: Fabbisogno completato, postazione coperta al 100%.
  - 🟡 **Giallo/Arancione**: Postazione parzialmente coperta (manca qualche volontario).
  - 🔴 **Rosso**: Postazione completamente scoperta.
- **Aggiunta Manuale Rapida**: Possibilità di aggiungere al volo un volontario non registrato o una disponibilità straordinaria dell'ultimo minuto.

---

## 7. Stampa ed Export Operativo dei Turni di Delegazione

Una volta validata la matrice, la Segreteria può generare i documenti operativi con il pulsante **🖨️ Stampa Turni**:

- **Foglio di Postazione per i Capigruppo**:
  - Documento PDF/cartaceo pulito e formattato da consegnare al responsabile di ciascun bene aperto.
  - Elenco orario con nominativi dei volontari assegnati, mansione e recapito telefonico di emergenza.
- **Quadro Generale di Delegazione**:
  - Riepilogo complessivo di tutte le postazioni territoriali per il Capo Delegazione e il Coordinatore Volontari.

---

## 8. L'Area Riservata del Volontario (My Account)

I volontari accedono alla pagina `/mio-account/volontari-fai/`:

1. **I Miei Turni & Presenze**:
   - Visualizzazione dei turni a cui si è stati assegnati, con data, orario di ritrovo, indirizzo del luogo e nominativo del capogruppo di riferimento.
2. **Informazioni Personali**:
   - Consultazione dei recapiti e della validità della propria tessera FAI.
3. **Sicurezza & Cambio Password**:
   - Gestione autonoma della password e recupero credenziali.
4. **Preferenze di Notifica**:
   - Possibilità di gestire la ricezione delle comunicazioni e promemoria automatici.

---

## 9. Impostazioni e Modelli Email

Nel menu **Volontari FAI > Impostazioni**, la Delegazione personalizza tutti i testi e gli oggetti delle comunicazioni:
- Email al candidato di presa in carico.
- Email allo staff per nuova candidatura.
- Email di attivazione account con credenziali di accesso.
- Testo dell'invito al sondaggio di disponibilità.

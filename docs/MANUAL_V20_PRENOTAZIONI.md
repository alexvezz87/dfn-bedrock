# 📘 Manuale Utente v2.0: Gestione Eventi, Prenotazioni, Soci FAI & Check-in

> **A chi è rivolto questo manuale**: Segreteria di Delegazione, Delegati agli Eventi, Referenti di Banco e Volontari addetti all'accoglienza e check-in.  
> **Obiettivo**: Fornire una guida pratica, passo dopo passo e senza tecnicismi, per gestire tutte le attività legate a eventi, prenotazioni online, verifiche delle tessere FAI e ingressi.

---

## 📑 Indice dei Contenuti
1. [Il Tabellone Eventi (Panoramica)](#1-il-tabellone-eventi)
2. [Come Creare o Modificare un Evento](#2-come-creare-o-modificare-un-evento)
3. [Gestione dei Turni Orari e delle Capienze](#3-gestione-dei-turni-orari-e-delle-capienze)
4. [Come Prenota il Visitatore dal Sito (Nuovo Booking Widget)](#4-come-prenota-il-visitatore-dal-sito)
5. [Gestione e Validazione delle Tessere FAI](#5-gestione-e-validazione-delle-tessere-fai)
6. [Desk Prenotazione Rapida (Botteghino in Loco)](#6-desk-prenotazione-rapida-botteghino-in-loco)
7. [Check-in all'Ingresso con lo Smartphone (App Scanner)](#7-check-in-allingresso-con-lo-smartphone)
8. [Report Presenze e Chiusura Contabile](#8-report-presenze-e-chiusura-contabile)
9. [Promozioni, Newsletter & Marketing Eventi](#9-promozioni-newsletter--marketing-eventi)

---

## 1. Il Tabellone Eventi

Accedendo al pannello di controllo WordPress dal menu laterale **FAI Prenotazioni > Tabellone Eventi**, si accede alla schermata principale di gestione.

In questa schermata puoi:
- **Visualizzare tutti gli eventi attivi e passati** con indicazione chiara di: data, luogo, orario, canali di vendita e posti totali occupati.
- **Creare un nuovo evento** cliccando sul pulsante `+ Crea Nuovo Evento` in alto a destra.
- **Modificare** un evento esistente per cambiarne descrizioni, orari o quote.
- **Duplicare** un evento già configurato per generarne rapidamente una copia (ideale per eventi ricorrenti o su più giorni).
- **Accedere direttamente ai Turni, all'elenco Prenotazioni o alla schermata di Check-in**.

---

## 2. Come Creare o Modificare un Evento

Per creare un evento bastano 4 semplici passaggi:

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│ 1. Info Base    │ ──> │ 2. Quote/Prezzi │ ──> │ 3. Fasce Orarie │ ──> │ 4. Pubblicazione│
│ Titolo e Luogo  │     │ Intero / FAI    │     │ Turni e Posti   │     │ Visibile Online │
└─────────────────┘     └─────────────────┘     └─────────────────┘     └─────────────────┘
```

1. **Dati Principali**: Inserisci il nome dell'evento, il luogo (es. *Basilica di San Gaudenzio*), l'indirizzo e la data di svolgimento.
2. **Quote di Partecipazione**:
   - **Quota Intera**: importo per i visitatori non iscritti al FAI.
   - **Quota Ridotta Soci FAI**: tariffa agevolata riservata a chi possiede una tessera FAI in corso di validità.
   - **Gratuito**: se l'evento non prevede alcun contributo.
3. **Modalità di Ingresso**:
   - **Flusso Libero**: il visitatore può accedere liberamente in qualsiasi momento della fascia oraria di apertura.
   - **Fasce Orarie (Turni)**: il visitatore sceglie uno specifico orario di ingresso (es. ogni 15 o 30 minuti).
4. **Salvataggio**: Clicca su **Pubblica** per renderlo subito prenotabile sul sito web, oppure salvalo come **Bozza** se vuoi completarlo in un secondo momento.

---

## 3. Gestione dei Turni Orari e delle Capienze

Se l'evento prevede visite guidate o ingressi a gruppi scaglionati, puoi configurare i singoli turni tramite il pulsante **Turni** sul tabellone eventi:

- **Generazione Automatica**: Puoi indicare l'orario di inizio (es. `10:00`), l'orario di fine (es. `18:00`), la durata di ciascun turno (es. `20 minuti`) e il numero massimo di persone ammesse per turno (es. `15 posti`). Il sistema creerà automaticamente tutte le fasce orarie.
- **Blocco Manuale di un Turno**: Se un gruppo, una scolaresca o ospiti d'onore hanno riservato privatamente un orario, puoi disattivare quel singolo slot con un click per non renderlo più selezionabile dal pubblico.
- **Posti Residui in Tempo Reale**: Il tabellone mostra quanti posti sono già stati prenotati e quanti ne rimangono ancora liberi per ogni singolo turno.

---

## 4. Come Prenota il Visitatore dal Sito (Nuovo Booking Widget)

Il pubblico che visita il sito web trova il modulo interattivo di prenotazione integrato nella pagina dell'evento tramite lo shortcode `[dfn_booking_widget]`:

```
┌─────────────────────────────────────────────────────────────────┐
│                    WIDGET PRENOTAZIONE SITO                     │
│  [1. Calendario / Data]  ──>  [2. Fascia Oraria / Turno]        │
│  [3. Scelta Tariffe]     ──>  [4. Dati Partecipanti & FAI]      │
│  [5. Recapiti Contatto]  ──>  [6. Pagamento Online / In Loco]   │
└─────────────────────────────────────────────────────────────────┘
```

### Il Nuovo Design del Selettore Biglietti & Tariffe
A partire dalla versione 2.0.1, il widget di prenotazione adotta una veste grafica rinnovata per offrire massima chiarezza e accessibilità sia su desktop che da smartphone:
- **Palette Ufficiale FAI**: La tariffa riservata ai **Soci FAI** è evidenziata con il caratteristico **Verde Bosco FAI** (`#004b23`), mentre la tariffa **Intero** ordinaria è valorizzata dall'**Arancio Istituzionale FAI** (`#d97706`).
- **Selettori Stepper Unificati**: I comandi di quantità presentano pulsanti compatti `−` e `+` con campi numerici centrati e tipografia alleggerita a spessore naturale (*font-weight* ottimizzato) per prevenire affaticamento visivo.
- **Diciture Distinte**: Le indicazioni di tipologia (*Intero* e *Ridotto soci FAI*) sono collocate direttamente sotto le caselle di selezione, eliminando badge ripetitivi a fondo card e garantendo un respiro visivo armonioso.

Il flusso per il visitatore è immediato:
1. **Scelta di Data e Turno**: Il visitatore seleziona il giorno sul calendario e l'orario desiderato tra quelli che hanno ancora posti disponibili.
2. **Selezione Biglietti & Verifica FAI**: Sceglie il numero di partecipanti. Se seleziona uno o più posti *Ridotto Soci FAI*, il modulo apre la casella per inserire il **Numero di Tessera FAI**.
3. **Dati di Contatto**: Inserisce Nome, Cognome, Email e Telefono per ricevere la conferma e i biglietti.
4. **Scelta del Pagamento**:
   - **Carta di Credito / Online (Stripe)**: Conferma istantanea e addebito sicuro con emissione immediata dei biglietti.
   - **Pagamento in Loco (Contanti / POS al desk)**: La prenotazione viene riservata e il saldo avverrà fisicamente al momento dell'accoglienza.
5. **Ricezione Biglietto**: Al termine della procedura, il visitatore riceve una **email di conferma** con allegato il biglietto PDF contenente il **QR Code univoco** da mostrare all'ingresso.


---

## 5. Gestione e Validazione delle Tessere FAI

La piattaforma garantisce che le quote ridotte siano applicate solo ai veri soci FAI:

### Come funziona il controllo automatico
- Quando un visitatore inserisce il proprio numero di tessera, il sistema interroga l'anagrafica soci locale di Delegazione.
- Se la tessera è già registrata e valida, lo sconto viene applicato immediatamente e la prenotazione è confermata in automatico.

### Cosa fare per le prenotazioni "In Attesa di Verifica"
Se il visitatore inserisce un numero di tessera non ancora presente nel database (ad esempio un nuovo socio o un socio di un'altra Delegazione):
1. La prenotazione entra nello stato **"In Attesa di Verifica Tessera"**.
2. La Segreteria apre il menu **FAI Soci > Prenotazioni in Attesa**.
3. Cliccando su **Verifica & Approva**, si apre la finestra di controllo.
4. La Segreteria inserisce:
   - Il **Numero di Tessera FAI** e la data di scadenza.
   - L'eventuale spunta **Registrato su SiVol FAI** (per attestare l'avvenuto controllo sul gestionale nazionale).
5. Cliccando su **Conferma Approvazione**, il sistema invia in automatico l'email di conferma con i biglietti al visitatore e aggiorna l'anagrafica per le future prenotazioni.

---

## 6. Desk Prenotazione Rapida (Botteghino in Loco)

Durante lo svolgimento delle manifestazioni, capita spesso che visitatori si presentino all'ingresso senza aver prenotato online.

La Segreteria può utilizzare la schermata **FAI Prenotazioni > Desk Rapido**:
1. Seleziona l'evento in corso e il primo turno orario con posti liberi.
2. Inserisce Nome e Cognome del visitatore (e numero tessera FAI se socio).
3. Clicca su **Conferma Ingresso Immediato**.
4. Il posto viene scalato istantaneamente dalla capienza e il check-in viene conteggiato nei totali di cassa e presenze.

---

## 7. Check-in all'Ingresso con lo Smartphone (App Scanner)

I volontari addetti all'accoglienza non hanno bisogno di scaricare app dagli store: basta aprire il browser dello smartphone all'indirizzo `/gestione-eventi/`.

```
┌─────────────────────────────────────────────────────────────┐
│                    SCANSIONE QR CODE                        │
│                                                             │
│   🟢 SCHERMATA VERDE    ──> Biglietto VALIDO. Ingresso OK   │
│   🟡 SCHERMATA GIALLA   ──> ATTENZIONE: Già scansionato o   │
│                             Socio FAI da verificare visivo  │
│   🔴 SCHERMATA ROSSA    ──> Biglietto NON VALIDO / Errato   │
└─────────────────────────────────────────────────────────────┘
```

1. **Accesso**: Il volontario effettua il login con le proprie credenziali (ruolo *Addetto Scanner* o *Volontario*).
2. **Selezione Evento**: Sceglie l'evento del giorno dall'elenco.
3. **Scansione**: Inquadra con la fotocamera il QR Code del visitatore (stampato su carta o mostrato dallo schermo del telefono).
4. **Lettura dell'Esito**:
   - **Verde**: Biglietto valido, nominativo confermato e check-in registrato.
   - **Giallo/Arancione**: Biglietto già registrato in precedenza (avviso possibile duplicato) oppure *Ridotto FAI* (il volontario chiede di esibire la tessera fisica).
   - **Rosso**: Biglietto scaduto, annullato o appartenente a un altro evento.

---

## 8. Report Presenze e Chiusura Contabile

Al termine della giornata o dell'evento, la Segreteria e il Capo Delegazione possono consultare i report definitivi:

- **Elenco Presenze (`FAI Prenotazioni > Report`)**:
  - Tabella completa con tutti i partecipanti, suddivisi per turno e tipologia (Interi, FAI, Gratuiti).
  - Possibilità di esportare i dati in formato **Excel / CSV** per l'archivio di Delegazione.
- **Rendicontazione Contabile (`FAI Prenotazioni > Contabilità`)**:
  - Totale quote incassate online tramite carta.
  - Totale quote riscosse in contanti o POS al banco accoglienza.
  - Quadratura precisa di cassa per la chiusura evento.

---

## 9. Promozioni, Newsletter & Marketing Eventi

Per massimizzare le presenze e raggiungere il pubblico fidelizzato della Delegazione, il menu **FAI Prenotazioni > Promozioni & Newsletter** permette di lanciare campagne promozionali mirate:

1. **Creazione della Campagna**:
   - Seleziona l'evento culturale desiderato: il sistema carica in automatico la locandina ufficiale e i prezzi.
   - Inserisci l'oggetto, il testo introduttivo, il corpo formattato e l'etichetta del pulsante di prenotazione (*CTA*).
2. **Invio Controllato a Lotti (Batch Engine)**:
   - La spedizione viene gestita in automatico dal server a lotti scaglionati (es. 25 email ogni 5 minuti).
   - Questa procedura garantisce che le email arrivino regolarmente nella casella principale dei destinatari, evitando filtri antispam e blacklist.
3. **Monitoraggio e Statistiche Live**:
   - La schermata riassume in tempo reale:
     - **Aperture (Open Rate)**: percentuale e numero di destinatari che hanno aperto il messaggio.
     - **Click (CTR)**: quante persone hanno cliccato sul pulsante di prenotazione.
     - **Conversioni & Incasso**: ordini effettivi generati e importo totale (€) riscosso dalla campagna.
   - Nella tabella dei destinatari è possibile effettuare ricerche rapide per nome o email, filtrare per stato (*Inviate, Aperte, Convertite*) e scorrere le pagine con il selettore (20, 50, 100 righe per pagina).

> 📖 Per la documentazione tecnica approfondita, la configurazione dei lotti e le procedure di emergenza, consulta la guida dedicata [Modulo Promozioni & Newsletter Eventi](PROMOTIONS_AND_MARKETING).



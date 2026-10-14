# 📢 Modulo Promozioni & Newsletter Eventi

> **Riferimento Tecnico & Operativo (v2.0+)**  
> Il modulo **Promozioni & Newsletter** (`dfn-promotions`) consente alla Delegazione FAI di ideare, pianificare, distribuire e tracciare campagne promozionali mirate via email per incentivare le prenotazioni agli eventi culturali. Include un motore di invio a lotti asincrono (*Batch Engine*), strumenti di sicurezza sandbox, tracciamento pixel/click e un sistema analitico di attribuzione automatica delle conversioni WooCommerce e del fatturato generato.

---

## 1. 🎯 Panoramica e Obiettivi del Modulo

Il modulo risponde all'esigenza di raggiungere gli iscritti alla newsletter e i partecipanti storici della Delegazione con comunicazioni curate, visivamente impattanti e conformi all'identità istituzionale del FAI, evitando al tempo stesso:
1. **Blocchi SMTP e Blacklist**: Grazie alla spedizione controllata e scaglionata a lotti temporizzati, che previene l'intervento dei filtri antispam dei provider (Gmail, Libero, Yahoo, Outlook).
2. **Perdita del Tracciamento**: Monitoraggio in tempo reale di chi apre l'email, chi clicca sulla Call-to-Action e chi finalizza l'acquisto del biglietto.
3. **Discrepanze Contabili**: Calcolo esatto del ritorno economico (ROI) generato direttamente dalla singola campagna.

---

## 2. 📝 Creazione e Configurazione di una Campagna

Accedendo al menu **FAI Prenotazioni > Promozioni & Newsletter**, la schermata principale presenta lo storico delle campagne con lo stato di avanzamento (*Bozza, Pianificata, In Invio, Completata, Bloccata*) e il pulsante **+ Nuova Promozione**.

### A. I 5 Passaggi di Configurazione
```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│ 1. Info Campagna │ ──> │ 2. Scelta Evento │ ──> │ 3. Testi & CTA   │ ──> │ 4. Pianificazione│ ──> │ 5. Live Preview  │
│ Titolo & Oggetto │     │ Locandina & Dati │     │ Intro & Corpo    │     │ Data/Ora & Lotti │     │ Desktop & Mobile │
└──────────────────┘     └──────────────────┘     └──────────────────┘     └──────────────────┘     └──────────────────┘
```

1. **Dati della Campagna**:
   - **Nome Promozione**: identificatore interno visibile solo allo staff (es. *Lancio United Gospel 2026*).
   - **Oggetto E-mail**: riga visualizzata nella casella di posta del destinatario (es. *Delegazione FAI Novara: United Gospel 2026 – Cento Voci un solo Cuore*).
2. **Evento Collegato**:
   - Selezione dell'evento dal menu a tendina. Il sistema aggancia automaticamente data, luogo, quote soci/interi e la **locandina ufficiale** impostata nei media di WordPress.
3. **Composizione Contenuti**:
   - **Testo Introduttivo**: breve abstract posizionato subito sotto la testata con logo FAI.
   - **Corpo del Messaggio**: editor formattato con dettagli sul programma, interpreti, orari e modalità di accredito.
   - **Pulsante CTA (Call-to-Action)**: etichetta del pulsante principale di prenotazione (es. *Prenota il tuo posto* o *Scopri l'evento*).
4. **Pianificazione & Parametri di Invio**:
   - Possibilità di invio immediato o schedulazione a una specifica data/ora futura.
   - Configurazione personalizzabile dei lotti (dimensione lotto iniziale, lotti successivi e minuti di intervallo).
5. **Anteprima Dinamica (Live Preview)**:
   - Pannello laterale che renderizza istantaneamente l'email HTML responsive identica a come apparirà su client desktop e smartphone.

---

## 3. ⚙️ Architettura del Motore di Invio (Batch Engine)

La spedizione delle email promozionali **non avviene mai in un unico flusso sincrono bloccante**, poiché l'invio contemporaneo di centinaia di email provocherebbe il timeout del server web e l'immediato respingimento da parte dei server di posta (*SMTP Greylisting / Error 550 Spam Rate Limit*).

### A. Algoritmo a Lotti con WP-Cron
```
                              ┌────────────────────────────────────────┐
                              │  Trigger wp-cron (ogni 5 minuti)       │
                              │  dfn_cron_process_promotions           │
                              └──────────────────┬─────────────────────┘
                                                 │
                                                 ▼
                              ┌────────────────────────────────────────┐
                              │  Verifica Campagne in stato 'sending'  │
                              │  e controllo orario locale (UTC+2)     │
                              └──────────────────┬─────────────────────┘
                                                 │
                                                 ▼
                       ┌──────────────────────────────────────────────────────┐
                       │  Estrae prossimo lotto:                              │
                       │  SELECT * FROM wp_dfn_promotion_queue                │
                       │  WHERE status = 'pending' LIMIT 25                   │
                       └──────────────────┬───────────────────────────────────┘
                                          │
                                          ▼
                       ┌──────────────────────────────────────────────────────┐
                       │  Spedizione email singole con token personalizzato   │
                       │  e marcatura status = 'sent' / 'failed'              │
                       └──────────────────┬───────────────────────────────────┘
                                          │
                  ┌───────────────────────┴───────────────────────┐
                  ▼                                               ▼
     [Rimangono email 'pending']                    [Coda pendente esaurita (0)]
                  │                                               │
     Attende intervallo successivo                   Aggiorna stato promozione:
     (batch_interval_minutes)                        status = 'completed'
```

1. **Lotto Iniziale (`initial_batch_size`, default: 15)**: un primo pacchetto ridotto per "scaldare" la connessione SMTP e verificare l'assenza di errori di trasporto.
2. **Lotti a Regime (`batch_size`, default: 25)**: lotti standard processati ciclicamente ad ogni esecuzione schedulata.
3. **Intervallo di Riposo (`batch_interval_minutes`, default: 5)**: tempo minimo garantito tra un lotto e il successivo.
4. **Sincronizzazione Timezone**: Il confronto temporale tra l'ultimo invio (`last_batch_at`) e l'orario corrente utilizza rigorosamente il timestamp locale (`current_time('timestamp')`), prevenendo qualsiasi disallineamento tra la timezone globale UTC di WordPress e il fuso orario di Delegazione (Roma, UTC+1 / UTC+2).
5. **Filtraggio Indirizzi Fittizi**: Il motore esclude tassativamente (`dfn_is_valid_promotional_email`) email interne di test (`@dfn.local`, `@fainovara.local`, `@example.com`), indirizzi generati per le casse fisiche e formati non conformi RFC.

---

## 4. 🛡️ Sicurezza Sandbox & Protezione Staging

Nelle impostazioni di sistema (*FAI Prenotazioni > Impostazioni > Email & Notifiche*) è attivo il commutatore **Sandbox E-mail**:

- 🔴 **Modalità Mute (Silenziosa)**: Tutte le email in uscita vengono intercettate, bloccate a livello di trasporto e registrate esclusivamente nei log diagnostici interni. Ideale per collaudi massivi di stress test del database.
- 🟡 **Modalità Sandbox Redirect**: Le email vengono generate normalmente con il layout completo e i token, ma la destinazione reale viene bypassata e reindirizzata a un unico indirizzo email di collaudo concordato (es. la casella della segreteria o dello sviluppatore).
- 🟢 **Modalità Live (Produzione)**: Spedizione reale a ciascun destinatario effettivo della lista.

---

## 5. 📊 Tracciamento Analitico: Aperture, Click e Conversioni

Ciascuna email generata contiene due strumenti crittografici trasparenti basati su un token alfanumerico univoco (`tracking_token` di 32 caratteri salvato in `wp_dfn_promotion_queue`):

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                               EMAIL RICEVUTA DAL VISITATORE                            │
│                                                                                        │
│  [Logo FAI]                                                                            │
│  Gentile Marco Rossi, ti presentiamo l'evento speciale...                              │
│  [Locandina Ufficiale]                                                                 │
│                                                                                        │
│  [ P R E N O T A   I L   T U O   P O S T O ]  ──> Link con token di tracking univoco   │
│                                                                                        │
│  [Pixel 1x1 Trasparente Invisibile]           ──> Traccia apertura e salva data/ora    │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

### A. Tracciamento Aperture (Open Rate)
- All'interno del codice HTML dell'email viene inserita un'immagine trasparente 1x1 pixel:  
  `<img src="https://dfnprenotazioni.it/wp-content/themes/dfn-theme/tracker.php?token=TOKEN" width="1" height="1" />`
- All'apertura del messaggio da parte del client di posta (es. Gmail, Apple Mail, Outlook), la richiesta HTTP viene intercettata:
  - Registra il timestamp del primo accesso (`opened_at`).
  - Incrementa il contatore cumulativo delle aperture (`open_count`).
  - Aggiorna il contatore complessivo della campagna (`opened_count`).

### B. Tracciamento Click (CTR - Click-Through Rate)
- Il pulsante della Call-to-Action punta all'endpoint di redirect del portale:  
  `https://dfnprenotazioni.it/evento/nome-evento/?dfn_promo_att=TOKEN`
- Al click del visitatore:
  - Registra il timestamp del click (`clicked_at`).
  - Incrementa il contatore dei click (`click_count` e `clicked_count`).
  - Rilascia nel browser del visitatore un cookie protetto (`dfn_promo_att`) con durata 30 giorni.

### C. Motore di Attribuzione Conversioni & ROI (Doppia Priorità)
Al momento della finalizzazione di una prenotazione su WooCommerce (`woocommerce_order_status_completed`), si attiva la funzione `dfn_promotions_attribute_order()`:

1. **Priorità 1 — Email di Fatturazione Acquirente**:  
   Se l'email indicata al checkout corrisponde a un destinatario a cui è stata spedita la newsletter per quello specifico evento, **la conversione viene attribuita direttamente a lui**.  
   *Questo risolve in modo definitivo i casi di computer o tablet condivisi in famiglia o link inoltrati ad amici, dove il cookie del browser appartiene a un'altra persona ma l'acquirente reale è il titolare dell'ordine.*
2. **Priorità 2 — Fallback tramite Token Cookie**:  
   Se l'acquirente completa l'acquisto utilizzando un'email diversa da quella iscritta alla newsletter (es. email aziendale o di un familiare), l'attribuzione avviene tramite il token memorizzato nel cookie di navigazione.
3. **Accumulo Fatturato & Ri-acquisti**:  
   Se un destinatario effettua acquisti multipli o biglietti aggiuntivi, il fatturato generato si accumula progressivamente (`revenue_generated` e `order_total`), garantendo la piena corrispondenza con i dati contabili.

---

## 6. ⚡ Gestione Asincrona della Coda (Filtri, Ricerca e Paginazione)

Nella schermata di dettaglio di ciascuna campagna, la tabella della coda destinatari è completamente asincrona e reattiva (sviluppata via AJAX nativo WordPress senza alcun ricaricamento di pagina):

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ FILTRI STATO: [Tutti (338)] [In Coda (0)] [Inviate (337)] [Aperte (123)] [Convertite (4)] │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ [ 🔍 Cerca per email o nominativo...       ] [✕]      Mostra: [20 v] righe per pagina  │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ DESTINATARIO          | STATO     | INVIATA     | APERTA (N°) | CONVERSIONE  | ORDINE  │
│ Giuliana Barolo       | Inviata   | 14/10 16:48 | 14/10 17:02 | Convertita   | #2443   │
│ Stefano Grappiolo     | Inviata   | 14/10 17:24 | 14/10 17:59 | Convertita   | #2446   │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ Mostrati da 1 a 20 di 338 destinatari         « Precedente [1] 2 3 ... 17 Successiva » │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

1. **Filtri di Stato con Click Istantaneo**:
   - Tab rapide con contatori aggiornati: *Tutti, In Coda, Inviate, Aperte, Cliccate, Convertite, Bloccate, Errori*.
   - Il click esegue una chiamata background a `wp_ajax_dfn_promotions_get_queue`, applica una transizione di opacità con spinner e sostituisce il corpo della tabella.
2. **Ricerca Live con Debounce (280ms)**:
   - Casella di ricerca testuale per individuare in tempo reale un nominativo o un indirizzo email, con invio immediato su tasto `Invio` e icona `✕` di pulizia rapida.
3. **Paginazione Server-Side Ottimizzata**:
   - Paginazione gestita via SQL (`LIMIT` e `OFFSET`) che garantisce tempi di risposta inferiori a 50ms anche su liste con migliaia di record.
   - Default a **20 righe per pagina**, estendibile a scelta a **50** o **100** righe.
   - Sincronizzazione automatica della barra degli indirizzi (`history.replaceState`): copiando o incollando il link, lo stato della ricerca e della pagina corrente viene conservato senza ricaricare il browser.

---

## 7. 🚨 Procedure di Emergenza & Recovery

| Situazione | Azione Amministrativa | Effetto sul Sistema |
| :--- | :--- | :--- |
| **Errore nel testo o nella data durante l'invio** | Cliccare su **Ferma Invio** | La campagna passa immediatamente allo stato `cancelled`. Tutte le email ancora `pending` vengono congelate e il cron salta l'elaborazione. |
| **Ripristino e correzione di una campagna fermata** | Cliccare su **Riapri Bozza** | Lo stato torna a `draft`. I destinatari già inviati rimangono confermati; è possibile correggere i testi e riprogrammare l'invio per i soli rimanenti. |
| **Coda bloccata da race condition temporale o riavvio server** | Cliccare su **Ripristina Coda** | Ripristina le righe orfane, ricalcola i contatori aggregati ed esegue un trigger immediato del cron bypassando l'intervallo di sicurezza. |

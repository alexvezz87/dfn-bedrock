# 📱 Web App PWA Scanner & Check-in

La Web App PWA accessibile all'indirizzo `/gestione-eventi/` è lo strumento operativo dedicato al personale di accoglienza e ai volontari durante lo svolgimento degli eventi.

---

## 1. Funzionalità Principali

### 📷 Scanner Fotocamera ad alte prestazioni
- Integrazione diretta con la fotocamera del dispositivo mobile (iOS / Android / Desktop).
- Decodifica istantanea del token QR Code del biglietto.
- Risposta visiva e sonora immediata:
  - 🟢 **Biglietto Valido**: Convalida l'ingresso, decrementa i posti rimanenti e mostra i dettagli del partecipante.
  - 🟡 **Già Utilizzato**: Segnala la data e l'ora del precedente check-in per prevenire ingressi duplicati.
  - 🔴 **Non Valido / Annullato**: Segnala tempestivamente anomalie.

### 🔍 Ricerca Partecipanti & Check-in Manuale
- Ricerca istantanea per Nome, Cognome, Email o Codice Ordine.
- Possibilità di effettuare il check-in manuale anche se il partecipante non ha con sé il biglietto cartaceo/digitale.

### 🎟️ Banchetto & Prenotazione Rapida (In Loco)
- Emissione immediata di biglietti per visitatori presentatisi direttamente all'evento senza prenotazione previa.
- Verifica disponibilità posti residui del turno corrente in tempo reale.
- Registrazione dell'incasso in contanti o POS con generazione immediata del ticket.

---

## 2. Sicurezza & Architettura della PWA
- **Endpoint AJAX dedicato**: [dfn-ajax-scanner.php](file:///c:/Users/Alex/Desktop/web-server/dfn-bedrock/web/app/themes/dfn-theme/inc/api/dfn-ajax-scanner.php).
- **Controllo permessi**: Richiede la capability `dfn_validate_tickets` o ruolo autorizzato (`dfn_validator`, `dfn_segretaria`, `dfn_capogruppo`, `administrator`).
- **Nessuna dipendenza da backend WP**: L'interfaccia risiede interamente sul frontend per garantire velocità massima e sicurezza senza esporre `/wp-admin/`.

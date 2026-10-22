# 📋 Specifiche Operative: Correzione Stato Pagamento e Visibilità Biglietto su Prenotazioni Annullate in /mio-account/

> **Riferimento Issue**: [#70](https://github.com/alexvezz87/dfn-bedrock/issues/70)  
> **Modulo**: `dfn-2.0.1-gestione-prenotazioni` (Core Booking & Area Riservata)  
> **Base Branch**: `dfn-2.0.1-gestione-prenotazioni`  
> **Tipologia**: `type: bugfix` | `priority: high`  

---

## 🎯 Obiettivo del Task

1. **Stato Pagamento Corretto**:
   - Correggere il fallback cieco che mostrava `Saldato online` su qualsiasi ordine con metodo diverso da `dfn_in_loco`.
   - Se la prenotazione/ordine è annullata (`$is_cancelled`), mostrare `Non saldato` (o `Rimborsato` se `refunded`), con classe/stile neutro (`dfn-payment-cancelled`).
   - Se la prenotazione è in attesa di approvazione staff (`pending_approval`), mostrare `In approvazione`.
   - Se l'ordine non è ancora pagato (`pending`, `on-hold` o non saldato), mostrare `In attesa di saldo` (`dfn-payment-unpaid`).
   - Solo se l'ordine è effettivamente pagato (`$order->is_paid()` o `completed`/`processing`), mostrare `Saldato online`.
   - Mantenere la gestione `dfn_in_loco` per i pagamenti in contanti (`X € (in loco)` se dovuto, `Saldato in loco` se già pagato).

2. **Visibilità Pulsante "Vedi Biglietto"**:
   - Mostrare il pulsante `Vedi Biglietto` solo se `! $is_cancelled && $order`.
   - Sulle prenotazioni annullate mostrare unicamente il badge `Annullata`.

3. **Allineamento Viste**:
   - Applicare le modifiche sia alla tabella "Prossimi Eventi" (righe 1937-1960) sia alla tabella "Eventi Passati" (righe 2105-2120) in `inc/frontend/dfn-myaccount.php`.
   - Aggiungere classi CSS appropriate in `dfn-frontend.css` o inline per coerenza visiva.

---

## 🧪 Checklist di Verifica
- [x] Creazione Issue #70 su GitHub.
- [x] Implementazione logica di visualizzazione pagamento corretta in `inc/frontend/dfn-myaccount.php`.
- [x] Condizionamento pulsante "Vedi Biglietto" per non comparire sulle prenotazioni annullate.
- [x] Verifica sintassi PHP con `php -l`.
- [x] Aggiunta classi CSS `.dfn-payment-unpaid`, `.dfn-payment-cancelled`, `.dfn-payment-refunded` in `dfn-visitor-dashboard.css`.
- [x] Commit sul branch `dfn-2.0.1-gestione-prenotazioni` con riferimento a Issue #70.
- [x] Merge su `staging` e deploy su Staging con `python scripts/deploy_theme_staging.py`.
- [x] Merge su `main` e deploy su Produzione con `python scripts/deploy_theme_prod.py`.
- [x] Chiusura documentata della Issue #70.

# 🔐 Matrice Ruoli, Permessi e Sicurezza

Il sistema implementa un modello di controllo accessi basato su ruoli (RBAC) integrato con WordPress, WooCommerce e l'organigramma della Delegazione FAI.

---

## 1. Ruoli Utente & Destinazione Login

| Ruolo WP | Descrizione / Ruolo Delegazione | Accesso `wp-admin` | URL Destinazione Login |
|---|---|---|---|
| `administrator` | Amministratore generale / Capo Delegazione | **Sì** (Completo) | `/wp-admin/` |
| `shop_manager` | Gestore negozio WooCommerce | **Sì** (WooCommerce + DFN) | `/wp-admin/` |
| `dfn_segretaria` | Segretaria di Delegazione | **Sì** (Desk Rapido, Anagrafiche) | `/wp-admin/admin.php?page=dfn-quick-booking` |
| `dfn_capogruppo` | Delegato Coordinamento Volontari | **Sì** (Gestione Turni & Volontari) | `/wp-admin/admin.php?page=dfn-volunteers` |
| `dfn_delegato` | Delegati di Settore (Eventi, Scuola, Com., Amb.) | **Sì** (Gestione proprio Team & Riunioni) | `/wp-admin/admin.php?page=dfn-teams` |
| `dfn_validator` | Addetto Check-in / Scanner | **No** (Solo PWA frontend) | `/gestione-eventi/` |
| `dfn_volunteer` | Volontario FAI (Membro Team) | **No** (Redirect ad Area Riservata) | `/mio-account/volontari-fai/` |
| `customer` / Ospite | Cliente / Visitatore | **No** (Redirect ad Area Riservata) | `/mio-account/` |

---

## 2. Capabilities Custom del Tema

Le capabilities sono registrate e mappate in [dfn-setup.php](file:///c:/Users/Alex/Desktop/web-server/dfn-bedrock/web/app/themes/dfn-theme/inc/core/dfn-setup.php):

### Gestione Eventi & Prenotazioni:
- `manage_dfn_events`: Creazione, modifica ed eliminazione eventi.
- `manage_dfn_slots`: Apertura/chiusura turni orari e modifica capienze.
- `dfn_quick_booking`: Accesso al banco cassa / desk prenotazione rapida.
- `dfn_validate_tickets`: Convalida ticket tramite scanner PWA o interfaccia check-in.
- `view_dfn_reports`: Consultazione reportistica presenze e contabilità.

### Gestione Volontari & Squadre di Lavoro (Team):
- `manage_dfn_volunteers`: Visualizzazione e modifica anagrafica volontari, assegnazione turni e validazione tessere.
- `manage_dfn_teams`: Creazione/modifica squadre di lavoro, abilitazione team e assegnazione supervisori.
- `dfn_create_team_meetings`: Creazione e convocazione di riunioni per il **proprio team di supervisione**.
- `dfn_create_all_meetings`: Creazione di riunioni plenarie di Delegazione o per qualsiasi team (riservato ad Admin / Capo Delegazione).

---

## 3. Matrice Permessi Team & Riunioni

| Funzionalità | Amministratore | Segretaria | Delegato Supervisore | Volontario del Team | Volontario Altro Team |
|---|:---:|:---:|:---:|:---:|:---:|
| Creare / Modificare Squadre (Team) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Assegnare Volontari ai Team | ✅ | ✅ | ✅ (nel suo team) | ❌ | ❌ |
| Convocare Riunione del Team | ✅ | ✅ | ✅ (solo suo team) | ❌ | ❌ |
| Ricevere Promemoria Notifiche | ✅ | ✅ | ✅ | ✅ | ❌ |
| Visualizzare Convocazione in My Account | ✅ | ✅ | ✅ | ✅ | ❌ |

---

## 4. Logica di Protezione Backend `wp-admin`

Per evitare che utenti senza privilegi amministrativi (volontari e clienti) vedano la bacheca interna di WordPress:
1. `woocommerce_prevent_admin_access`: Filtro che intercetta i ruoli non autorizzati e reindirizza alla pagina `/mio-account/`.
2. `admin_init` (`dfn_block_wp_admin_for_volunteers`): Barriera di sicurezza che blocca tentativi di accesso diretto a URL di backend non consentiti per il ruolo.
3. **Bypass AJAX/REST obbligatorio**: Entrambi i filtri escludono categoricamente le richieste AJAX (`wp_doing_ajax()`), REST API (`REST_REQUEST`) e autosave (`DOING_AUTOSAVE`) per consentire il funzionamento fluido del widget di prenotazione e della PWA.

# 🔐 Matrice Ruoli, Permessi e Sicurezza

Il sistema implementa un modello di controllo accessi basato su ruoli (RBAC) integrato con WordPress e WooCommerce.

---

## 1. Ruoli Utente & Destinazione Login

| Ruolo WP | Descrizione | Accesso `wp-admin` | URL Destinazione Login |
|---|---|---|---|
| `administrator` | Amministratore generale | **Sì** (Completo) | `/wp-admin/` |
| `shop_manager` | Gestore negozio WooCommerce | **Sì** (WooCommerce + DFN) | `/wp-admin/` |
| `dfn_segretaria` | Operatore Segreteria | **Sì** (Solo Desk Rapido) | `/wp-admin/admin.php?page=dfn-quick-booking` |
| `dfn_capogruppo` | Coordinatore Volontari | **Sì** (Gestione Turni) | `/wp-admin/admin.php?page=dfn-volunteers` |
| `dfn_validator` | Addetto Check-in / Scanner | **No** (Solo PWA frontend) | `/gestione-eventi/` |
| `dfn_volunteer` | Volontario standard FAI | **No** (Redirect a My Account) | `/mio-account/volontari-fai/` |
| `customer` / Ospite | Cliente / Visitatore | **No** (Redirect a My Account) | `/mio-account/` |

---

## 2. Capabilities Custom

Il sistema registra le seguenti capabilities in [dfn-setup.php](file:///c:/Users/Alex/Desktop/web-server/dfn-bedrock/web/app/themes/dfn-theme/inc/core/dfn-setup.php):

- `manage_dfn_events`: Creazione, modifica ed eliminazione eventi.
- `manage_dfn_slots`: Apertura/chiusura turni e modifica capienze.
- `manage_dfn_volunteers`: Anagrafica volontari e assegnazione presenze.
- `dfn_quick_booking`: Accesso al banco cassa / prenotazione rapida.
- `dfn_validate_tickets`: Convalida ticket tramite scanner PWA o interfaccia check-in.
- `view_dfn_reports`: Consultazione reportistica e contabilità.

---

## 3. Logica di Protezione Backend `wp-admin`

Per evitare che utenti senza privilegi amministrativi (volontari e clienti) vedano la bacheca interna di WordPress:
1. `woocommerce_prevent_admin_access`: Filtro che intercetta i ruoli non autorizzati e reindirizza alla pagina `/mio-account/`.
2. `admin_init` (`dfn_block_wp_admin_for_volunteers`): Barriera di sicurezza che blocca tentativi di accesso diretto a URL di backend.
3. **Bypass AJAX/REST obbligatorio**: Entrambi i filtri escludono categoricamente le richieste AJAX (`wp_doing_ajax()`), REST API (`REST_REQUEST`) e autosave (`DOING_AUTOSAVE`) per consentire il funzionamento del widget di prenotazione e della PWA.

<?php

/**
 * DFN Booking System 2.0 — Database Schema & Migration
 *
 * Crea e gestisce le 6 tabelle custom del sistema prenotazioni:
 * - dfn_events          : Metadata centralizzata degli eventi
 * - dfn_event_slots     : Turni/slot orari generati per evento
 * - dfn_bookings        : Record master prenotazione (per gruppo)
 * - dfn_booking_slots   : Assegnazione N:M prenotazione → slot
 * - dfn_fai_members     : Anagrafica iscritti FAI con tessera
 * - dfn_waitlist        : Lista di attesa con TTL
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Versione dello schema DB — incrementare per forzare aggiornamento */
define('DFN_DB_VERSION', '2.1.0');

/**
 * ========================================================================
 * 1. INSTALLAZIONE E MIGRAZIONE SCHEMA
 * ========================================================================
 */

add_action('after_switch_theme', 'dfn_db_install');
add_action('init', 'dfn_db_install_if_needed');

/**
 * Verifica se lo schema deve essere aggiornato (version check).
 *
 * @return void
 */
function dfn_db_install_if_needed(): void
{
    if (get_option('dfn_db_version') !== DFN_DB_VERSION) {
        dfn_db_install();
    }
}

/**
 * Crea o aggiorna tutte le tabelle custom tramite dbDelta().
 *
 * dbDelta è idempotente: crea la tabella se non esiste,
 * aggiunge colonne mancanti se la tabella esiste già.
 *
 * @return void
 */
function dfn_db_install(): void
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    // Necessario per dbDelta()
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // -------------------------------------------------------------------
    // TABELLA 1: Eventi
    // -------------------------------------------------------------------
    $table_events = $wpdb->prefix . 'dfn_events';
    $sql_events = "CREATE TABLE {$table_events} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        product_id bigint(20) unsigned NOT NULL,
        event_date_start date NOT NULL,
        event_date_end date DEFAULT NULL,
        event_time_start time NOT NULL,
        event_time_end time DEFAULT NULL,
        location varchar(500) NOT NULL,
        city varchar(255) DEFAULT NULL,
        description text DEFAULT NULL,
        access_type varchar(20) NOT NULL DEFAULT 'time_slots',
        allocation_mode varchar(20) NOT NULL DEFAULT 'automatic',
        approval_workflow varchar(20) NOT NULL DEFAULT 'auto',
        payment_mode varchar(20) NOT NULL DEFAULT 'online',
        auto_cancel_hours int(10) unsigned NOT NULL DEFAULT 24,
        slot_duration int(10) unsigned DEFAULT 30,
        slot_capacity int(10) unsigned DEFAULT 0,
        slot_bonus int(10) unsigned DEFAULT 0,
        first_slot_time time DEFAULT NULL,
        last_slot_time time DEFAULT NULL,
        total_capacity int(10) unsigned DEFAULT 0,
        price_standard decimal(10,2) DEFAULT 0.00,
        price_fai decimal(10,2) DEFAULT 0.00,
        staff_config text DEFAULT NULL,
        detail_layout varchar(10) NOT NULL DEFAULT 'auto',
        booking_opening_date datetime DEFAULT NULL,
        booking_status varchar(20) NOT NULL DEFAULT 'open',
        status varchar(20) DEFAULT 'draft',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_product (product_id),
        KEY idx_date (event_date_start),
        KEY idx_status (status)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 2: Slot / Turni orari
    // -------------------------------------------------------------------
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $sql_slots = "CREATE TABLE {$table_slots} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        slot_date date NOT NULL,
        slot_time_start time NOT NULL,
        slot_time_end time NOT NULL,
        capacity int(10) unsigned NOT NULL DEFAULT 0,
        bonus_capacity int(10) unsigned NOT NULL DEFAULT 0,
        booked_count int(10) unsigned NOT NULL DEFAULT 0,
        is_locked tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_event_date (event_id,slot_date),
        UNIQUE KEY uk_event_slot (event_id,slot_date,slot_time_start)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 3: Prenotazioni (record master per gruppo)
    // -------------------------------------------------------------------
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $sql_bookings = "CREATE TABLE {$table_bookings} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        order_id bigint(20) unsigned NOT NULL,
        event_id bigint(20) unsigned NOT NULL,
        customer_email varchar(255) NOT NULL,
        customer_name varchar(255) NOT NULL,
        customer_phone varchar(50) DEFAULT NULL,
        total_persons int(10) unsigned NOT NULL DEFAULT 1,
        persons_standard int(10) unsigned NOT NULL DEFAULT 0,
        persons_fai int(10) unsigned NOT NULL DEFAULT 0,
        status varchar(30) NOT NULL DEFAULT 'confirmed',
        qr_token varchar(128) NOT NULL,
        checked_in_at datetime DEFAULT NULL,
        checked_in_by bigint(20) unsigned DEFAULT NULL,
        payment_method varchar(50) DEFAULT NULL,
        amount_due decimal(10,2) DEFAULT 0.00,
        amount_paid decimal(10,2) DEFAULT 0.00,
        notes text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_order (order_id),
        KEY idx_event (event_id),
        KEY idx_status (status),
        KEY idx_email (customer_email),
        UNIQUE KEY uk_qr (qr_token)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 4: Assegnazione prenotazione → slot (N:M)
    // -------------------------------------------------------------------
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';
    $sql_booking_slots = "CREATE TABLE {$table_booking_slots} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        booking_id bigint(20) unsigned NOT NULL,
        slot_id bigint(20) unsigned NOT NULL,
        persons int(10) unsigned NOT NULL DEFAULT 1,
        checked_in_at datetime DEFAULT NULL,
        checked_in_by bigint(20) unsigned DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY idx_booking (booking_id),
        KEY idx_slot (slot_id),
        UNIQUE KEY uk_booking_slot (booking_id,slot_id)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 5: Anagrafica Iscritti FAI
    // -------------------------------------------------------------------
    $table_fai = $wpdb->prefix . 'dfn_fai_members';
    $sql_fai = "CREATE TABLE {$table_fai} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        email varchar(255) DEFAULT NULL,
        phone varchar(50) DEFAULT NULL,
        card_number varchar(50) NOT NULL,
        card_expiry date DEFAULT NULL,
        card_type varchar(20) NOT NULL DEFAULT 'INDIVIDUALE',
        verified tinyint(1) NOT NULL DEFAULT 0,
        verified_by bigint(20) unsigned DEFAULT NULL,
        verified_at datetime DEFAULT NULL,
        user_id bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_email (email),
        KEY idx_card (card_number),
        KEY idx_user (user_id),
        KEY idx_expiry (card_expiry)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 6: Waitlist evoluta
    // -------------------------------------------------------------------
    $table_waitlist = $wpdb->prefix . 'dfn_waitlist';
    $sql_waitlist = "CREATE TABLE {$table_waitlist} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        slot_id bigint(20) unsigned DEFAULT NULL,
        customer_name varchar(255) NOT NULL,
        customer_email varchar(255) NOT NULL,
        customer_phone varchar(50) DEFAULT NULL,
        persons int(10) unsigned NOT NULL DEFAULT 1,
        fai_cards int(10) unsigned NOT NULL DEFAULT 0,
        status varchar(20) DEFAULT 'waiting',
        notified_at datetime DEFAULT NULL,
        ttl_expires_at datetime DEFAULT NULL,
        promoted_order_id bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_status (status),
        KEY idx_ttl (ttl_expires_at)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 7: Registrazioni Utenti In Sospeso (Double Opt-In Email)
    // -------------------------------------------------------------------
    $table_pending = $wpdb->prefix . 'dfn_pending_registrations';
    $sql_pending = "CREATE TABLE {$table_pending} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        token varchar(64) NOT NULL,
        password_hash varchar(255) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY idx_token (token),
        KEY idx_email (email)
    ) {$charset_collate};";

    // Esecuzione idempotente di tutte le tabelle
    dbDelta($sql_events);
    dbDelta($sql_slots);
    dbDelta($sql_bookings);
    dbDelta($sql_booking_slots);
    dbDelta($sql_fai);
    dbDelta($sql_waitlist);
    dbDelta($sql_pending);

    // Forza la creazione della colonna description se manca (dbDelta a volte fallisce l'alter)
    $row = $wpdb->get_results("SHOW COLUMNS FROM {$table_events} LIKE 'description'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE {$table_events} ADD COLUMN description text DEFAULT NULL AFTER location");
    }

    // Forza la creazione della colonna city (Comune) se manca
    $row_city = $wpdb->get_results("SHOW COLUMNS FROM {$table_events} LIKE 'city'");
    if (empty($row_city)) {
        $wpdb->query("ALTER TABLE {$table_events} ADD COLUMN city varchar(255) DEFAULT NULL AFTER location");
    }

    // Forza la nullabilità di card_expiry nella tabella fai_members (dbDelta a volte fallisce l'alter)
    $wpdb->query("ALTER TABLE {$table_fai} MODIFY COLUMN card_expiry date DEFAULT NULL");

    // Migra dati legacy dalla waitlist su wp_options (one-shot)
    dfn_migrate_waitlist_from_options();

    // Migra eventi in_loco esistenti → auto_cancel_hours = 0 (one-shot)
    dfn_migrate_in_loco_auto_cancel();

    // Aggiorna la versione per evitare re-esecuzioni
    update_option('dfn_db_version', DFN_DB_VERSION);
}

/**
 * ========================================================================
 * 2. MIGRAZIONE WAITLIST DA wp_options → TABELLA CUSTOM (ONE-SHOT)
 * ========================================================================
 */

/**
 * Migra i dati della waitlist legacy (cv_waitlist_data) nella nuova tabella.
 *
 * Eseguita una sola volta. I dati originali vengono rinominati come backup
 * ma non cancellati, per sicurezza.
 *
 * @return void
 */
function dfn_migrate_waitlist_from_options(): void
{
    // Se la migrazione è già stata eseguita, esci
    if (get_option('dfn_waitlist_migrated') === 'yes') {
        return;
    }

    $legacy_data = get_option('cv_waitlist_data', []);
    if (empty($legacy_data) || ! is_array($legacy_data)) {
        update_option('dfn_waitlist_migrated', 'yes');
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_waitlist';

    foreach ($legacy_data as $event_id => $entries) {
        if (! is_array($entries)) {
            continue;
        }
        foreach ($entries as $entry) {
            $nome    = isset($entry['nome']) ? sanitize_text_field($entry['nome']) : '';
            $cognome = isset($entry['cognome']) ? sanitize_text_field($entry['cognome']) : '';

            $wpdb->insert(
                $table,
                [
                    'event_id'       => absint($event_id),
                    'customer_name'  => trim($nome . ' ' . $cognome),
                    'customer_email' => isset($entry['email']) ? sanitize_email($entry['email']) : '',
                    'customer_phone' => isset($entry['tel']) ? sanitize_text_field($entry['tel']) : null,
                    'persons'        => isset($entry['qty']) ? absint($entry['qty']) : 1,
                    'fai_cards'      => isset($entry['tessere']) ? absint($entry['tessere']) : 0,
                    'status'         => 'waiting',
                    'created_at'     => isset($entry['date']) ? $entry['date'] : current_time('mysql'),
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ],
            );
        }
    }

    // Backup dell'option originale, poi segna la migrazione come completata
    update_option('cv_waitlist_data_backup_v2', $legacy_data);
    update_option('dfn_waitlist_migrated', 'yes');
}

/**
 * Migrazione one-shot: imposta auto_cancel_hours = 0 per tutti gli eventi
 * esistenti con payment_mode = 'in_loco', affinché il cron non li annulli.
 *
 * @return void
 */
function dfn_migrate_in_loco_auto_cancel(): void
{
    if (get_option('dfn_in_loco_auto_cancel_migrated') === 'yes') {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_events';

    $wpdb->query(
        "UPDATE {$table} SET auto_cancel_hours = 0 WHERE payment_mode = 'in_loco'",
    );

    update_option('dfn_in_loco_auto_cancel_migrated', 'yes');
}

/**
 * ========================================================================
 * 3. HELPER FUNCTIONS PER QUERY DATABASE
 * ========================================================================
 *
 * Tutte le query passano per $wpdb->prepare() come da Direttiva 2.0.
 */

/**
 * Recupera un evento dal suo ID.
 *
 * @param int $event_id ID dell'evento.
 * @return object|null Riga evento o null se non trovato.
 */
function dfn_db_get_event(int $event_id): ?object
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_events';

    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $event_id),
    );
}

/**
 * Recupera un evento dal product_id WooCommerce collegato.
 *
 * @param int $product_id ID del prodotto WC.
 * @return object|null Riga evento o null.
 */
function dfn_db_get_event_by_product(int $product_id): ?object
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_events';

    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d AND status != 'archived'", $product_id),
    );
}

/**
 * Recupera tutti gli eventi pubblicati, ordinati per data.
 *
 * @param string $status Stato da filtrare (default 'published').
 * @return array Lista di oggetti evento.
 */
function dfn_db_get_events(string $status = 'published'): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_events';

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY event_date_start ASC, event_time_start ASC",
            $status,
        ),
    );

    return is_array($results) ? $results : [];
}

/**
 * Recupera la lista dei Comuni unici degli eventi pubblicati.
 *
 * @param string $status Stato eventi (default 'published').
 * @return array Lista di comuni.
 */
function dfn_db_get_event_cities(string $status = 'published'): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_events';

    // Assicurati che la colonna city esista prima di fare la query
    $col_check = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'city'");
    if (empty($col_check)) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN city varchar(255) DEFAULT NULL AFTER location");
        return [];
    }

    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT city FROM {$table} WHERE status = %s AND city IS NOT NULL AND city != '' ORDER BY city ASC",
            $status
        )
    );

    return is_array($results) ? array_filter($results) : [];
}

/**
 * Recupera i mesi e gli anni disponibili per gli eventi pubblicati.
 *
 * @param string $status Stato eventi (default 'published').
 * @return array Lista di array con 'value' (YYYY-MM) e 'label' (es. "Agosto 2026").
 */
function dfn_db_get_event_months(string $status = 'published'): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_events';

    $dates = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT event_date_start FROM {$table} WHERE status = %s AND event_date_start IS NOT NULL AND event_date_start != '0000-00-00' ORDER BY event_date_start ASC",
            $status
        )
    );

    $months = [];
    if (is_array($dates)) {
        $seen = [];
        foreach ($dates as $date_str) {
            if (empty($date_str)) {
                continue;
            }
            $ym = date('Y-m', strtotime($date_str));
            if (isset($seen[$ym])) {
                continue;
            }
            $seen[$ym] = true;
            $timestamp = strtotime($ym . '-01');
            $months[]  = [
                'value' => $ym,
                'label' => date_i18n('F Y', $timestamp),
            ];
        }
    }

    return $months;
}

/**
 * Recupera tutti gli slot di un evento per una data specifica.
 *
 * @param int         $event_id ID dell'evento.
 * @param string|null $date     Data (Y-m-d). Se null, ritorna tutti gli slot.
 * @return array Lista di oggetti slot.
 */
function dfn_db_get_slots(int $event_id, ?string $date = null): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_event_slots';

    if ($date) {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND slot_date = %s ORDER BY slot_time_start ASC",
                $event_id,
                $date,
            ),
        );
    } else {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d ORDER BY slot_date ASC, slot_time_start ASC",
                $event_id,
            ),
        );
    }

    return is_array($results) ? $results : [];
}

/**
 * Recupera gli slot con posti ancora disponibili (inclusi bonus).
 *
 * @param int    $event_id ID dell'evento.
 * @param string $date     Data (Y-m-d).
 * @return array Lista di slot con campo calcolato `available`.
 */
function dfn_db_get_available_slots(int $event_id, string $date): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_event_slots';

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *,
                    (capacity - booked_count) AS available,
                    (capacity + bonus_capacity - booked_count) AS available_with_bonus
             FROM {$table}
             WHERE event_id = %d
               AND slot_date = %s
               AND is_locked = 0
             ORDER BY slot_time_start ASC",
            $event_id,
            $date,
        ),
    );

    return is_array($results) ? $results : [];
}

/**
 * Recupera una prenotazione dal suo QR token.
 *
 * @param string $qr_token Token QR univoco.
 * @return object|null Riga booking o null.
 */
function dfn_db_get_booking_by_token(string $qr_token): ?object
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_bookings';

    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE qr_token = %s", $qr_token),
    );
}

/**
 * Recupera tutte le prenotazioni per un evento.
 *
 * @param int    $event_id ID dell'evento.
 * @param string $status   Filtro stato (default '' = tutti).
 * @return array Lista di prenotazioni.
 */
function dfn_db_get_bookings_by_event(int $event_id, string $status = ''): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_bookings';

    if (! empty($status)) {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND status = %s ORDER BY created_at ASC",
                $event_id,
                $status,
            ),
        );
    } else {
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND status != 'cancelled' ORDER BY created_at ASC",
                $event_id,
            ),
        );
    }

    return is_array($results) ? $results : [];
}

/**
 * Recupera una prenotazione dal suo order_id WooCommerce.
 *
 * @param int $order_id ID dell'ordine WC.
 * @return object|null Riga booking o null.
 */
function dfn_db_get_booking_by_order(int $order_id): ?object
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_bookings';

    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $order_id),
    );
}

/**
 * Verifica se un socio FAI è valido (verificato + tessera non scaduta).
 *
 * @param string $email Email del socio.
 * @return object|null Record del socio o null se non valido.
 */
function dfn_db_get_valid_fai_member(string $email): ?object
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE email = %s
               AND verified = 1
               AND card_expiry >= CURDATE()
             ORDER BY card_expiry DESC
             LIMIT 1",
            $email,
        ),
    );
}

/**
 * Recupera le voci in waitlist per un evento, ordinate per data inserimento.
 *
 * @param int    $event_id ID dell'evento.
 * @param string $status   Filtro stato (default 'waiting').
 * @return array Lista voci waitlist.
 */
function dfn_db_get_waitlist(int $event_id, string $status = 'waiting'): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_waitlist';

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d AND status = %s ORDER BY created_at ASC",
            $event_id,
            $status,
        ),
    );

    return is_array($results) ? $results : [];
}

/**
 * Genera gli slot orari per un evento in base alla sua configurazione.
 *
 * Calcola gli intervalli dalla first_slot_time alla last_slot_time
 * con la durata definita in slot_duration, e li inserisce nella tabella dfn_event_slots.
 *
 * @param int $event_id ID dell'evento.
 * @return int Numero di slot generati.
 */
function dfn_db_generate_slots_for_event(int $event_id): int
{
    global $wpdb;

    /** @var \stdClass|null $event */
    $event = dfn_db_get_event($event_id);
    if (! $event || $event->access_type !== 'time_slots') {
        return 0;
    }

    if (empty($event->first_slot_time) || $event->slot_duration <= 0) {
        return 0;
    }

    $table_slots = $wpdb->prefix . 'dfn_event_slots';

    // Rimuovi slot esistenti (rigenerazione)
    $wpdb->delete($table_slots, [ 'event_id' => $event_id ], [ '%d' ]);

    $start_date = $event->event_date_start;
    $end_date   = $event->event_date_end ?: $event->event_date_start;
    $duration   = (int) $event->slot_duration;
    $capacity   = (int) $event->slot_capacity;
    $bonus      = (int) $event->slot_bonus;

    $first_slot = strtotime($event->first_slot_time);
    $last_slot  = $event->last_slot_time ? strtotime($event->last_slot_time) : null;

    $count = 0;
    $current_date = $start_date;

    while (strtotime($current_date) <= strtotime($end_date)) {
        $current_time = $first_slot;

        while (true) {
            $slot_start = gmdate('H:i:s', $current_time);
            $slot_end   = gmdate('H:i:s', $current_time + ($duration * 60));

            // Se abbiamo un ultimo turno e lo abbiamo superato, fermiamoci
            if ($last_slot !== null && $current_time > $last_slot) {
                break;
            }

            $wpdb->insert(
                $table_slots,
                [
                    'event_id'       => $event_id,
                    'slot_date'      => $current_date,
                    'slot_time_start' => $slot_start,
                    'slot_time_end'  => $slot_end,
                    'capacity'       => $capacity,
                    'bonus_capacity' => $bonus,
                    'booked_count'   => 0,
                    'is_locked'      => 0,
                ],
                [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ],
            );
            $count++;

            $current_time += $duration * 60;

            // Safety: se non c'è un ultimo turno, facciamo max 50 slot per giorno
            if ($last_slot === null && $count >= 50) {
                break;
            }
        }

        // Giorno successivo (per eventi multi-giorno)
        $current_date = gmdate('Y-m-d', strtotime($current_date . ' +1 day'));
    }

    return $count;
}

/**
 * Ricalcola il conteggio dei posti prenotati per tutti gli slot di un evento.
 * Allinea booked_count al numero effettivo di persone nelle prenotazioni attive.
 *
 * @param int $event_id ID dell'evento.
 * @return void
 */
function dfn_db_recalculate_event_slots_booked_count(int $event_id): void
{
    global $wpdb;
    $table_slots = $wpdb->prefix . 'dfn_event_slots';
    $table_booking_slots = $wpdb->prefix . 'dfn_booking_slots';
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // Recupera tutti gli slot associati all'evento
    $slots = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM {$table_slots} WHERE event_id = %d",
        $event_id,
    ));

    if (empty($slots)) {
        return;
    }

    foreach ($slots as $slot) {
        // Calcola la somma delle persone per le prenotazioni non cancellate associate a questo slot
        $actual_booked = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(bs.persons) 
             FROM {$table_booking_slots} bs
             JOIN {$table_bookings} b ON bs.booking_id = b.id
             WHERE bs.slot_id = %d AND b.status != 'cancelled'",
            $slot->id,
        ));

        $actual_booked = $actual_booked !== null ? intval($actual_booked) : 0;

        // Aggiorna lo slot
        $wpdb->update(
            $table_slots,
            [ 'booked_count' => $actual_booked ],
            [ 'id' => $slot->id ],
            [ '%d' ],
            [ '%d' ],
        );
    }
}

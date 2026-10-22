<?php

/**
 * DFN Booking System 2.0 — Database Schema & Migration
 *
 * Crea e gestisce le tabelle custom del sistema prenotazioni:
 * - dfn_events          : Metadata centralizzata degli eventi
 * - dfn_event_slots     : Turni/slot orari generati per evento
 * - dfn_bookings        : Record master prenotazione (per gruppo)
 * - dfn_booking_slots   : Assegnazione N:M prenotazione → slot
 * - dfn_fai_members     : Anagrafica iscritti FAI con tessera
 * - dfn_waitlist        : Lista di attesa con TTL
 * - dfn_logs            : Log centralizzato delle azioni di sistema
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/** Versione dello schema DB — incrementare per forzare aggiornamento */
define('DFN_DB_VERSION', '2.7.1');

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
        is_test_event tinyint(1) NOT NULL DEFAULT 0,
        test_notification_email varchar(255) DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_product (product_id),
        KEY idx_date (event_date_start),
        KEY idx_status (status)
    ) {$charset_collate};";

    // Assicura che le nuove colonne per gli Eventi Test esistano nelle tabelle già create
    $col_test_check = $wpdb->get_results("SHOW COLUMNS FROM {$table_events} LIKE 'is_test_event'");
    if (empty($col_test_check)) {
        $wpdb->query("ALTER TABLE {$table_events} ADD COLUMN is_test_event tinyint(1) NOT NULL DEFAULT 0, ADD COLUMN test_notification_email varchar(255) DEFAULT NULL");
    };

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
    // TABELLA 5: Anagrafica Iscritti FAI (Soci e Volontari)
    // -------------------------------------------------------------------
    $table_fai = $wpdb->prefix . 'dfn_fai_members';
    $sql_fai = "CREATE TABLE {$table_fai} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        first_name varchar(100) NOT NULL,
        last_name varchar(100) NOT NULL,
        email varchar(255) DEFAULT NULL,
        phone varchar(50) DEFAULT NULL,
        card_number varchar(50) NOT NULL,
        fai_registry_id varchar(50) DEFAULT NULL,
        card_expiry date DEFAULT NULL,
        card_type varchar(20) NOT NULL DEFAULT 'INDIVIDUALE',
        is_sdd_active tinyint(1) NOT NULL DEFAULT 0,
        verified tinyint(1) NOT NULL DEFAULT 0,
        verified_by bigint(20) unsigned DEFAULT NULL,
        verified_at datetime DEFAULT NULL,
        user_id bigint(20) unsigned DEFAULT NULL,
        is_volunteer tinyint(1) NOT NULL DEFAULT 0,
        volunteer_status varchar(20) NOT NULL DEFAULT 'active',
        volunteer_notes text DEFAULT NULL,
        joined_date date DEFAULT NULL,
        is_guide tinyint(1) NOT NULL DEFAULT 0,
        has_safety_course tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_email (email),
        KEY idx_card (card_number),
        KEY idx_registry (fai_registry_id),
        KEY idx_user (user_id),
        KEY idx_expiry (card_expiry),
        KEY idx_sdd (is_sdd_active),
        KEY idx_volunteer (is_volunteer),
        KEY idx_guide (is_guide),
        KEY idx_safety (has_safety_course)
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

    // -------------------------------------------------------------------
    // TABELLA 8: Log Azioni di Sistema
    // -------------------------------------------------------------------
    $table_logs = $wpdb->prefix . 'dfn_logs';
    $sql_logs = "CREATE TABLE {$table_logs} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        logged_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        type varchar(50) NOT NULL DEFAULT 'generic',
        executor varchar(100) NOT NULL DEFAULT 'Sistema',
        description text NOT NULL,
        outcome varchar(20) NOT NULL DEFAULT 'success',
        PRIMARY KEY  (id),
        KEY idx_type (type),
        KEY idx_outcome (outcome),
        KEY idx_logged_at (logged_at)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 9: Riunioni Volontari di Delegazione
    // -------------------------------------------------------------------
    $table_meetings = $wpdb->prefix . 'dfn_volunteer_meetings';
    $sql_meetings = "CREATE TABLE {$table_meetings} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        meeting_date date NOT NULL,
        meeting_time_start time NOT NULL,
        meeting_time_end time DEFAULT NULL,
        location varchar(500) NOT NULL,
        meeting_link varchar(500) DEFAULT NULL,
        agenda text DEFAULT NULL,
        notes text DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'scheduled',
        team_id bigint(20) unsigned NOT NULL DEFAULT 0,
        reminder_7d_sent tinyint(1) NOT NULL DEFAULT 0,
        reminder_1d_sent tinyint(1) NOT NULL DEFAULT 0,
        reminders_sent text DEFAULT NULL,
        created_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_date (meeting_date),
        KEY idx_status (status),
        KEY idx_team (team_id)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 10: Eventi Logistica Volontari (Locali & Giornate FAI)
    // -------------------------------------------------------------------
    $table_vol_events = $wpdb->prefix . 'dfn_volunteer_events';
    $sql_vol_events = "CREATE TABLE {$table_vol_events} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        event_type varchar(30) NOT NULL DEFAULT 'local',
        date_start date NOT NULL,
        date_end date NOT NULL,
        linked_event_id bigint(20) unsigned DEFAULT NULL,
        description text DEFAULT NULL,
        status varchar(30) NOT NULL DEFAULT 'draft',
        created_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_type (event_type),
        KEY idx_status (status),
        KEY idx_dates (date_start, date_end),
        KEY idx_linked (linked_event_id)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 11: Giorni Evento Logistica Volontari
    // -------------------------------------------------------------------
    $table_vol_days = $wpdb->prefix . 'dfn_volunteer_event_days';
    $sql_vol_days = "CREATE TABLE {$table_vol_days} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        event_date date NOT NULL,
        day_label varchar(100) NOT NULL,
        order_num int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_date (event_date)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 12: Luoghi Aperti per Giorno (Es. Castello, Chiesa...)
    // -------------------------------------------------------------------
    $table_vol_places = $wpdb->prefix . 'dfn_volunteer_event_places';
    $sql_vol_places = "CREATE TABLE {$table_vol_places} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        day_id bigint(20) unsigned NOT NULL,
        place_name varchar(255) NOT NULL,
        address varchar(500) DEFAULT NULL,
        notes text DEFAULT NULL,
        order_num int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_day (day_id)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 13: Turni / Slot Orari per Luogo e Giorno
    // -------------------------------------------------------------------
    $table_vol_shifts = $wpdb->prefix . 'dfn_volunteer_event_shifts';
    $sql_vol_shifts = "CREATE TABLE {$table_vol_shifts} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        day_id bigint(20) unsigned NOT NULL,
        place_id bigint(20) unsigned NOT NULL,
        shift_label varchar(100) NOT NULL,
        time_start time NOT NULL,
        time_end time NOT NULL,
        min_volunteers int(10) unsigned NOT NULL DEFAULT 2,
        max_volunteers int(10) unsigned NOT NULL DEFAULT 10,
        order_num int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_day (day_id),
        KEY idx_place (place_id)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 14: Assegnazioni Volontari ai Turni (con Ruolo)
    // -------------------------------------------------------------------
    $table_vol_assignments = $wpdb->prefix . 'dfn_volunteer_shift_assignments';
    $sql_vol_assignments = "CREATE TABLE {$table_vol_assignments} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        shift_id bigint(20) unsigned NOT NULL,
        volunteer_id bigint(20) unsigned DEFAULT NULL,
        volunteer_name_manual varchar(255) DEFAULT NULL,
        role_assigned varchar(50) NOT NULL DEFAULT 'banchetto',
        notes text DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_shift (shift_id),
        KEY idx_vol (volunteer_id),
        KEY idx_role (role_assigned)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 15: Sondaggi Disponibilità Volontari
    // -------------------------------------------------------------------
    $table_vol_surveys = $wpdb->prefix . 'dfn_volunteer_surveys';
    $sql_vol_surveys = "CREATE TABLE {$table_vol_surveys} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        title varchar(255) NOT NULL,
        deadline_at datetime NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'open',
        token_public varchar(64) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_status (status),
        KEY idx_token (token_public)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 16: Risposte ai Sondaggi Disponibilità
    // -------------------------------------------------------------------
    $table_vol_survey_responses = $wpdb->prefix . 'dfn_volunteer_survey_responses';
    $sql_vol_survey_responses = "CREATE TABLE {$table_vol_survey_responses} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        survey_id bigint(20) unsigned NOT NULL,
        volunteer_id bigint(20) unsigned DEFAULT NULL,
        day_id bigint(20) unsigned NOT NULL,
        time_slot_key varchar(50) NOT NULL,
        is_available tinyint(1) NOT NULL DEFAULT 1,
        preferred_place_id bigint(20) unsigned DEFAULT NULL,
        notes text DEFAULT NULL,
        submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_survey (survey_id),
        KEY idx_vol (volunteer_id),
        KEY idx_slot (day_id, time_slot_key),
        KEY idx_pref_place (preferred_place_id)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 17: Anagrafica Mansioni / Ruoli Volontari
    // -------------------------------------------------------------------
    $table_roles = $wpdb->prefix . 'dfn_volunteer_roles';
    $sql_roles = "CREATE TABLE {$table_roles} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        role_key varchar(50) NOT NULL,
        role_name varchar(100) NOT NULL,
        badge_code varchar(20) DEFAULT NULL,
        badge_color varchar(20) NOT NULL DEFAULT '#475569',
        badge_bg varchar(20) NOT NULL DEFAULT '#f1f5f9',
        requires_safety_course tinyint(1) NOT NULL DEFAULT 0,
        requires_guide tinyint(1) NOT NULL DEFAULT 0,
        is_default tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uk_role_key (role_key)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 18: Associazione Mansioni Abilitate per Singolo Evento
    // -------------------------------------------------------------------
    $table_event_roles = $wpdb->prefix . 'dfn_volunteer_event_roles';
    $sql_event_roles = "CREATE TABLE {$table_event_roles} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        role_id bigint(20) unsigned NOT NULL,
        order_num int(10) unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_role (role_id),
        UNIQUE KEY uk_event_role (event_id, role_id)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 19: Campagne Promozionali Eventi
    // -------------------------------------------------------------------
    $table_promotions = $wpdb->prefix . 'dfn_promotions';
    $sql_promotions = "CREATE TABLE {$table_promotions} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_id bigint(20) unsigned NOT NULL,
        title varchar(255) NOT NULL,
        email_subject varchar(255) NOT NULL,
        email_intro text DEFAULT NULL,
        email_custom_text text DEFAULT NULL,
        cta_text varchar(100) NOT NULL DEFAULT 'Prenota il tuo posto',
        status varchar(20) NOT NULL DEFAULT 'draft',
        total_recipients int(10) unsigned NOT NULL DEFAULT 0,
        sent_count int(10) unsigned NOT NULL DEFAULT 0,
        failed_count int(10) unsigned NOT NULL DEFAULT 0,
        opened_count int(10) unsigned NOT NULL DEFAULT 0,
        clicked_count int(10) unsigned NOT NULL DEFAULT 0,
        converted_count int(10) unsigned NOT NULL DEFAULT 0,
        revenue_generated decimal(10,2) NOT NULL DEFAULT 0.00,
        batch_size int(10) unsigned NOT NULL DEFAULT 25,
        initial_batch_size int(10) unsigned NOT NULL DEFAULT 15,
        batch_interval_minutes int(10) unsigned NOT NULL DEFAULT 5,
        last_batch_at datetime DEFAULT NULL,
        scheduled_at datetime DEFAULT NULL,
        completed_at datetime DEFAULT NULL,
        created_by bigint(20) unsigned DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_event (event_id),
        KEY idx_status (status)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 22: Coda Invio Email Promozionali (Queue)
    // -------------------------------------------------------------------
    $table_promotion_queue = $wpdb->prefix . 'dfn_promotion_queue';
    $sql_promotion_queue = "CREATE TABLE {$table_promotion_queue} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        promotion_id bigint(20) unsigned NOT NULL,
        recipient_email varchar(255) NOT NULL,
        recipient_name varchar(255) DEFAULT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        tracking_token varchar(64) DEFAULT NULL,
        error_message text DEFAULT NULL,
        opened_at datetime DEFAULT NULL,
        open_count int(10) unsigned NOT NULL DEFAULT 0,
        clicked_at datetime DEFAULT NULL,
        click_count int(10) unsigned NOT NULL DEFAULT 0,
        converted_at datetime DEFAULT NULL,
        booking_id bigint(20) unsigned DEFAULT NULL,
        order_id bigint(20) unsigned DEFAULT NULL,
        order_total decimal(10,2) DEFAULT NULL,
        sent_at datetime DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_promotion (promotion_id),
        KEY idx_status (status),
        KEY idx_email (recipient_email),
        KEY idx_token (tracking_token),
        KEY idx_converted (converted_at)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 21: Squadre & Team di Delegazione (Modulo 2.1.1)
    // -------------------------------------------------------------------
    $table_teams = $wpdb->prefix . 'dfn_teams';
    $sql_teams = "CREATE TABLE {$table_teams} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        slug varchar(50) NOT NULL,
        description text DEFAULT NULL,
        icon varchar(20) NOT NULL DEFAULT '👥',
        color varchar(20) NOT NULL DEFAULT '#004b23',
        badge_bg varchar(20) NOT NULL DEFAULT '#f0fdf4',
        whatsapp_url varchar(500) DEFAULT NULL,
        drive_url varchar(500) DEFAULT NULL,
        supervisor_roles text DEFAULT NULL,
        supervisor_user_id bigint(20) unsigned DEFAULT NULL,
        is_active tinyint(1) NOT NULL DEFAULT 1,
        reminder_cadence_mode varchar(20) NOT NULL DEFAULT 'inherit',
        reminder_days varchar(255) DEFAULT NULL,
        order_num int(10) unsigned NOT NULL DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uk_slug (slug),
        KEY idx_active (is_active),
        KEY idx_order (order_num)
    ) {$charset_collate};";

    // -------------------------------------------------------------------
    // TABELLA 22: Componenti dei Team di Lavoro (Modulo 2.1.1)
    // -------------------------------------------------------------------
    $table_team_members = $wpdb->prefix . 'dfn_team_members';
    $sql_team_members = "CREATE TABLE {$table_team_members} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        team_id bigint(20) unsigned NOT NULL,
        member_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned DEFAULT NULL,
        joined_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY uk_team_member (team_id, member_id),
        KEY idx_team (team_id),
        KEY idx_member (member_id),
        KEY idx_user (user_id)
    ) {$charset_collate};";



    // Esecuzione idempotente di tutte le tabelle
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql_events);
    dbDelta($sql_slots);
    dbDelta($sql_bookings);
    dbDelta($sql_booking_slots);
    dbDelta($sql_fai);
    dbDelta($sql_waitlist);
    dbDelta($sql_pending);
    dbDelta($sql_logs);
    dbDelta($sql_meetings);
    dbDelta($sql_vol_events);
    dbDelta($sql_vol_days);
    dbDelta($sql_vol_places);
    dbDelta($sql_vol_shifts);
    dbDelta($sql_vol_assignments);
    dbDelta($sql_vol_surveys);
    dbDelta($sql_vol_survey_responses);
    dbDelta($sql_roles);
    dbDelta($sql_event_roles);
    dbDelta($sql_promotions);
    dbDelta($sql_promotion_queue);
    dbDelta($sql_teams);
    dbDelta($sql_team_members);

    // Auto-migrazione difensiva colonne
    dfn_ensure_meetings_reminder_columns();

    // Popolamento ruoli predefiniti se la tabella è vuota
    $table_roles = $wpdb->prefix . 'dfn_volunteer_roles';
    $roles_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_roles}");
    if ($roles_count === 0) {
        $default_roles = [
            ['resp_scuola', 'Responsabile Scuola', '(S)', '#92400e', '#fef3c7', 1, 0, 1],
            ['resp_banchetto', 'Responsabile Banchetto', '(R)', '#991b1b', '#fee2e2', 0, 0, 1],
            ['guida', 'Guida Culturale', '(G)', '#0369a1', '#e0f2fe', 0, 1, 1],
            ['accoglienza', 'Accoglienza / Validatore', 'Accoglienza', '#166534', '#f0fdf4', 0, 0, 1],
            ['banchetto', 'Volontario Banchetto', 'Banchetto', '#475569', '#f1f5f9', 0, 0, 1],
        ];
        foreach ($default_roles as $dr) {
            $wpdb->insert($table_roles, [
                'role_key'              => $dr[0],
                'role_name'             => $dr[1],
                'badge_code'            => $dr[2],
                'badge_color'           => $dr[3],
                'badge_bg'              => $dr[4],
                'requires_safety_course'=> $dr[5],
                'requires_guide'        => $dr[6],
                'is_default'            => $dr[7],
            ]);
        }
    }


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

    // Garantisce che le colonne del modulo Volontari siano create in wp_dfn_fai_members
    $row_vol = $wpdb->get_results("SHOW COLUMNS FROM {$table_fai} LIKE 'is_volunteer'");
    if (empty($row_vol)) {
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN is_volunteer tinyint(1) NOT NULL DEFAULT 0");
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN volunteer_status varchar(20) NOT NULL DEFAULT 'active'");
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN volunteer_notes text DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN joined_date date DEFAULT NULL");
        $wpdb->query("ALTER TABLE {$table_fai} ADD INDEX idx_volunteer (is_volunteer)");
    }

    $row_guide = $wpdb->get_results("SHOW COLUMNS FROM {$table_fai} LIKE 'is_guide'");
    if (empty($row_guide)) {
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN is_guide tinyint(1) NOT NULL DEFAULT 0");
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN has_safety_course tinyint(1) NOT NULL DEFAULT 0");
        $wpdb->query("ALTER TABLE {$table_fai} ADD INDEX idx_guide (is_guide)");
        $wpdb->query("ALTER TABLE {$table_fai} ADD INDEX idx_safety (has_safety_course)");
    }

    $row_sivol = $wpdb->get_results("SHOW COLUMNS FROM {$table_fai} LIKE 'is_sivol_registered'");
    if (empty($row_sivol)) {
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN is_sivol_registered tinyint(1) NOT NULL DEFAULT 0");
        $wpdb->query("ALTER TABLE {$table_fai} ADD INDEX idx_sivol (is_sivol_registered)");
    }

    $row_reg = $wpdb->get_results("SHOW COLUMNS FROM {$table_fai} LIKE 'fai_registry_id'");
    if (empty($row_reg)) {
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN fai_registry_id varchar(50) DEFAULT NULL AFTER card_number");
        $wpdb->query("ALTER TABLE {$table_fai} ADD INDEX idx_registry (fai_registry_id)");
    }

    $has_sdd_col = $wpdb->get_var("SHOW COLUMNS FROM {$table_fai} LIKE 'is_sdd_active'");
    if (! $has_sdd_col) {
        $wpdb->query("ALTER TABLE {$table_fai} ADD COLUMN is_sdd_active tinyint(1) NOT NULL DEFAULT 0 AFTER card_type, ADD INDEX idx_sdd (is_sdd_active)");
    }

    // Forza la creazione della colonna preferred_place_id in dfn_volunteer_survey_responses se manca
    $table_vol_survey_responses = $wpdb->prefix . 'dfn_volunteer_survey_responses';
    $row_pref = $wpdb->get_results("SHOW COLUMNS FROM {$table_vol_survey_responses} LIKE 'preferred_place_id'");
    if (empty($row_pref)) {
        $wpdb->query("ALTER TABLE {$table_vol_survey_responses} ADD COLUMN preferred_place_id bigint(20) unsigned DEFAULT NULL AFTER is_available, ADD INDEX idx_pref_place (preferred_place_id)");
    }

    // Assicura che i semplici soci senza status volontario abbiano volunteer_status = 'none' e is_volunteer = 0
    $wpdb->query("UPDATE {$table_fai} SET volunteer_status = 'none' WHERE is_volunteer = 0 AND (volunteer_status = 'active' OR volunteer_status IS NULL OR volunteer_status = '')");

    // Ripulisce eventuali backslash anomali dai nomi in anagrafica FAI e prenotazioni
    $wpdb->query("UPDATE {$table_fai} SET first_name = REPLACE(first_name, '\\\\', ''), last_name = REPLACE(last_name, '\\\\', '')");
    $table_bk = $wpdb->prefix . 'dfn_bookings';
    $wpdb->query("UPDATE {$table_bk} SET customer_name = REPLACE(customer_name, '\\\\', '')");

    // Migra dati legacy dalla waitlist su wp_options (one-shot)
    dfn_migrate_waitlist_from_options();

    // Migra eventi in_loco esistenti → auto_cancel_hours = 0 (one-shot)
    dfn_migrate_in_loco_auto_cancel();

    // Aggiornamento colonne Tabella 19 (dfn_promotions)
    $promo_cols = $wpdb->get_col("SHOW COLUMNS FROM {$table_promotions}");
    if (! empty($promo_cols)) {
        if (! in_array('batch_size', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN batch_size int(10) unsigned NOT NULL DEFAULT 25 AFTER failed_count");
        }
        if (! in_array('initial_batch_size', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN initial_batch_size int(10) unsigned NOT NULL DEFAULT 15 AFTER batch_size");
        }
        if (! in_array('batch_interval_minutes', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN batch_interval_minutes int(10) unsigned NOT NULL DEFAULT 5 AFTER initial_batch_size");
        }
        if (! in_array('last_batch_at', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN last_batch_at datetime DEFAULT NULL AFTER batch_interval_minutes");
        }
        if (! in_array('opened_count', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN opened_count int(10) unsigned NOT NULL DEFAULT 0 AFTER failed_count");
        }
        if (! in_array('clicked_count', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN clicked_count int(10) unsigned NOT NULL DEFAULT 0 AFTER opened_count");
        }
        if (! in_array('converted_count', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN converted_count int(10) unsigned NOT NULL DEFAULT 0 AFTER clicked_count");
        }
        if (! in_array('revenue_generated', $promo_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotions} ADD COLUMN revenue_generated decimal(10,2) NOT NULL DEFAULT 0.00 AFTER converted_count");
        }
    }

    // Aggiornamento colonne Tabella 20 (dfn_promotion_queue)
    $queue_cols = $wpdb->get_col("SHOW COLUMNS FROM {$table_promotion_queue}");
    if (! empty($queue_cols)) {
        if (! in_array('tracking_token', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN tracking_token varchar(64) DEFAULT NULL AFTER status, ADD INDEX idx_token (tracking_token)");
        }
        if (! in_array('opened_at', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN opened_at datetime DEFAULT NULL AFTER error_message");
        }
        if (! in_array('open_count', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN open_count int(10) unsigned NOT NULL DEFAULT 0 AFTER opened_at");
        }
        if (! in_array('clicked_at', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN clicked_at datetime DEFAULT NULL AFTER open_count");
        }
        if (! in_array('click_count', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN click_count int(10) unsigned NOT NULL DEFAULT 0 AFTER clicked_at");
        }
        if (! in_array('converted_at', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN converted_at datetime DEFAULT NULL AFTER click_count, ADD INDEX idx_converted (converted_at)");
        }
        if (! in_array('booking_id', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN booking_id bigint(20) unsigned DEFAULT NULL AFTER converted_at");
        }
        if (! in_array('order_id', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN order_id bigint(20) unsigned DEFAULT NULL AFTER booking_id");
        }
        if (! in_array('order_total', $queue_cols, true)) {
            $wpdb->query("ALTER TABLE {$table_promotion_queue} ADD COLUMN order_total decimal(10,2) DEFAULT NULL AFTER order_id");
        }
    }

    // Aggiornamento colonne per Riunioni e Team (Issue #65)
    dfn_ensure_meetings_reminder_columns();

    // Aggiorna la versione per evitare re-esecuzioni
    update_option('dfn_db_version', DFN_DB_VERSION);
}

/**
 * Verifica e aggiunge dinamicamente le colonne per i promemoria dinamici e team (Modulo 2.1.1 - Issue #65).
 */
function dfn_ensure_meetings_reminder_columns(): void
{
    global $wpdb;
    $table_meetings = $wpdb->prefix . 'dfn_volunteer_meetings';
    $table_teams    = $wpdb->prefix . 'dfn_teams';

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_meetings}'") === $table_meetings) {
        $cols = $wpdb->get_col("DESCRIBE {$table_meetings}", 0);
        if (! in_array('team_id', (array) $cols, true)) {
            $wpdb->query("ALTER TABLE {$table_meetings} ADD COLUMN team_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER status, ADD INDEX idx_team (team_id)");
        }
        if (! in_array('reminder_7d_sent', (array) $cols, true)) {
            $wpdb->query("ALTER TABLE {$table_meetings} ADD COLUMN reminder_7d_sent tinyint(1) NOT NULL DEFAULT 0 AFTER team_id");
        }
        if (! in_array('reminder_1d_sent', (array) $cols, true)) {
            $wpdb->query("ALTER TABLE {$table_meetings} ADD COLUMN reminder_1d_sent tinyint(1) NOT NULL DEFAULT 0 AFTER reminder_7d_sent");
        }
        if (! in_array('reminders_sent', (array) $cols, true)) {
            $wpdb->query("ALTER TABLE {$table_meetings} ADD COLUMN reminders_sent text DEFAULT NULL AFTER reminder_1d_sent");
        }
    }

    if ($wpdb->get_var("SHOW TABLES LIKE '{$table_teams}'") === $table_teams) {
        $cols_teams = $wpdb->get_col("DESCRIBE {$table_teams}", 0);
        if (! in_array('whatsapp_url', (array) $cols_teams, true)) {
            $wpdb->query("ALTER TABLE {$table_teams} ADD COLUMN whatsapp_url varchar(500) DEFAULT NULL AFTER badge_bg");
        }
        if (! in_array('drive_url', (array) $cols_teams, true)) {
            $wpdb->query("ALTER TABLE {$table_teams} ADD COLUMN drive_url varchar(500) DEFAULT NULL AFTER whatsapp_url");
        }
        if (! in_array('reminder_cadence_mode', (array) $cols_teams, true)) {
            $wpdb->query("ALTER TABLE {$table_teams} ADD COLUMN reminder_cadence_mode varchar(20) NOT NULL DEFAULT 'inherit' AFTER is_active");
        }
        if (! in_array('reminder_days', (array) $cols_teams, true)) {
            $wpdb->query("ALTER TABLE {$table_teams} ADD COLUMN reminder_days varchar(255) DEFAULT NULL AFTER reminder_cadence_mode");
        }
    }
}
add_action('admin_init', 'dfn_ensure_meetings_reminder_columns');

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

    $can_see_test = current_user_can('dfn_manage_events') || current_user_can('manage_options');
    $test_sql = $can_see_test ? '' : ' AND (is_test_event = 0 OR is_test_event IS NULL)';

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s {$test_sql} ORDER BY event_date_start ASC, event_time_start ASC",
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

    $can_see_test = current_user_can('dfn_manage_events') || current_user_can('manage_options');
    $test_sql = $can_see_test ? '' : ' AND (is_test_event = 0 OR is_test_event IS NULL)';

    $results = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT city FROM {$table} WHERE status = %s {$test_sql} AND city IS NOT NULL AND city != '' ORDER BY city ASC",
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

    $can_see_test = current_user_can('dfn_manage_events') || current_user_can('manage_options');
    $test_sql = $can_see_test ? '' : ' AND (is_test_event = 0 OR is_test_event IS NULL)';

    $dates = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT event_date_start FROM {$table} WHERE status = %s {$test_sql} AND event_date_start IS NOT NULL AND event_date_start != '0000-00-00' ORDER BY event_date_start ASC",
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

/**
 * Impedisce la visualizzazione front-end e l'acquisto degli Eventi Test agli utenti non amministratori.
 */
function dfn_restrict_test_events_access(): void
{
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    if (is_singular('product')) {
        $product_id = get_queried_object_id();
        if ($product_id > 0 && function_exists('dfn_db_get_event_by_product')) {
            $event = dfn_db_get_event_by_product($product_id);
            if ($event && ! empty($event->is_test_event)) {
                if (! current_user_can('dfn_manage_events') && ! current_user_can('manage_options')) {
                    wp_redirect(home_url('/'));
                    exit;
                }
            }
        }
    }
}
add_action('template_redirect', 'dfn_restrict_test_events_access', 1);

/**
 * ========================================================================
 * HELPER VOLONTARI FAI & RIUNIONI DI DELEGAZIONE
 * ========================================================================
 */

/**
 * Verifica se un determinato utente è registrato come Volontario FAI attivo
 * oppure se possiede ruoli FAI assegnati.
 *
 * @param int|null $user_id ID dell'utente (se null, usa l'utente corrente).
 * @return bool True se è un volontario attivo, false altrimenti.
 */
function dfn_is_user_volunteer(?int $user_id = null): bool
{
    if (function_exists('dfn_is_module_active') && ! dfn_is_module_active('volontari')) {
        return false;
    }

    $uid = $user_id ?: get_current_user_id();
    if (! $uid) {
        return false;
    }

    // Se è amministratore o ha ruoli di gestione
    if (user_can($uid, 'manage_options')) {
        return true;
    }

    // Controlla se ha ruoli FAI operativi assegnati
    $assigned_fai_roles = get_user_meta($uid, '_dfn_assigned_fai_roles', true);
    if (is_array($assigned_fai_roles) && ! empty($assigned_fai_roles)) {
        return true;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';

    // Controlla se esiste nella tabella iscritti FAI come volontario attivo
    $is_vol = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_volunteer = 1 AND volunteer_status = 'active'",
        $uid
    ));

    return $is_vol;
}

/**
 * Recupera il record del volontario associato a un determinato user_id.
 *
 * @param int $user_id ID utente WordPress.
 * @return object|null Record del volontario o null.
 */
function dfn_get_volunteer_by_user(int $user_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_fai_members';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id = %d AND is_volunteer = 1 LIMIT 1",
        $user_id
    ));
}

/**
 * Recupera l'elenco delle prossime riunioni di delegazione programmate.
 *
 * @param bool $upcoming_only Se true, restituisce solo le riunioni da oggi in poi.
 * @param int  $limit         Numero massimo di riunioni da restituire.
 * @return array<object>
 */
function dfn_get_volunteer_meetings(bool $upcoming_only = true, int $limit = 20): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_meetings';

    $where = "status != 'cancelled'";
    if ($upcoming_only) {
        $where .= " AND meeting_date >= CURDATE()";
    }

    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY meeting_date ASC, meeting_time_start ASC LIMIT %d";
    $results = $wpdb->get_results($wpdb->prepare($sql, $limit));

    return is_array($results) ? $results : [];
}

/**
 * ========================================================================
 * HELPER LOGISTICA EVENTI E TURNI VOLONTARI FAI (GIORNATE FAI / LOCALI)
 * ========================================================================
 */

/**
 * Recupera un evento logistica per ID.
 *
 * @param int $event_id ID dell'evento logistica.
 * @return object|null
 */
function dfn_get_volunteer_event(int $event_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_events';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $event_id));
}

/**
 * Recupera tutti gli eventi logistica volontari.
 *
 * @param string $status Stato opzionale ('all', 'published', 'draft', etc.)
 * @return array<object>
 */
function dfn_get_volunteer_events(string $status = 'all'): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_events';
    $where = "1=1";
    if ($status !== 'all') {
        $where .= $wpdb->prepare(" AND status = %s", $status);
    }
    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY date_start DESC, id DESC";
    $res = $wpdb->get_results($sql);
    return is_array($res) ? $res : [];
}

/**
 * Recupera i giorni configurati per un evento logistica.
 *
 * @param int $event_id ID dell'evento.
 * @return array<object>
 */
function dfn_get_volunteer_event_days(int $event_id): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_event_days';
    $sql = "SELECT * FROM {$table} WHERE event_id = %d ORDER BY order_num ASC, event_date ASC";
    $res = $wpdb->get_results($wpdb->prepare($sql, $event_id));
    return is_array($res) ? $res : [];
}

/**
 * Recupera i luoghi aperti per un giorno di evento logistica.
 *
 * @param int $day_id ID del giorno.
 * @return array<object>
 */
function dfn_get_volunteer_event_places(int $day_id): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_event_places';
    $sql = "SELECT * FROM {$table} WHERE day_id = %d ORDER BY order_num ASC, id ASC";
    $res = $wpdb->get_results($wpdb->prepare($sql, $day_id));
    return is_array($res) ? $res : [];
}

/**
 * Recupera tutti i luoghi aperti per l'intero evento logistica (distinti per nome per evitare duplicati su eventi multi-giorno).
 *
 * @param int $event_id ID dell'evento.
 * @return array<object>
 */
function dfn_get_volunteer_event_all_places(int $event_id): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_event_places';
    $sql = "SELECT place_name, MIN(id) as id, event_id, MIN(day_id) as day_id, MIN(address) as address 
            FROM {$table} 
            WHERE event_id = %d 
            GROUP BY place_name 
            ORDER BY id ASC";
    $res = $wpdb->get_results($wpdb->prepare($sql, $event_id));
    return is_array($res) ? $res : [];
}

/**
 * Recupera i turni / slot orari configurati per un luogo.
 *
 * @param int $place_id ID del luogo.
 * @return array<object>
 */
function dfn_get_volunteer_place_shifts(int $place_id): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_event_shifts';
    $sql = "SELECT * FROM {$table} WHERE place_id = %d ORDER BY order_num ASC, time_start ASC";
    $res = $wpdb->get_results($wpdb->prepare($sql, $place_id));
    return is_array($res) ? $res : [];
}

/**
 * Recupera tutte le assegnazioni volontari per uno shift.
 *
 * @param int $shift_id ID dello shift.
 * @return array<object>
 */
function dfn_get_volunteer_shift_assignments(int $shift_id): array
{
    global $wpdb;
    $table_ass = $wpdb->prefix . 'dfn_volunteer_shift_assignments';
    $table_fai = $wpdb->prefix . 'dfn_fai_members';

    $sql = "SELECT a.*, f.first_name, f.last_name, f.phone, f.email, f.card_number, f.is_guide, f.has_safety_course, f.user_id, f.volunteer_notes
            FROM {$table_ass} a
            LEFT JOIN {$table_fai} f ON a.volunteer_id = f.id
            WHERE a.shift_id = %d
            ORDER BY 
                CASE a.role_assigned
                    WHEN 'resp_banchetto' THEN 1
                    WHEN 'resp_scuola' THEN 2
                    WHEN 'guida' THEN 3
                    WHEN 'accoglienza' THEN 4
                    ELSE 5
                END ASC, a.id ASC";
    $res = $wpdb->get_results($wpdb->prepare($sql, $shift_id));
    return is_array($res) ? $res : [];
}

/**
 * Recupera i turni assegnati a un determinato volontario (per visualizzazione /mio-account/).
 *
 * @param int $user_id ID utente WordPress del volontario.
 * @return array<object>
 */
function dfn_get_volunteer_assigned_shifts_for_user(int $user_id): array
{
    global $wpdb;
    $table_fai    = $wpdb->prefix . 'dfn_fai_members';
    $table_ass    = $wpdb->prefix . 'dfn_volunteer_shift_assignments';
    $table_shifts = $wpdb->prefix . 'dfn_volunteer_event_shifts';
    $table_places = $wpdb->prefix . 'dfn_volunteer_event_places';
    $table_days   = $wpdb->prefix . 'dfn_volunteer_event_days';
    $table_events = $wpdb->prefix . 'dfn_volunteer_events';

    $vol = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table_fai} WHERE user_id = %d AND is_volunteer = 1 LIMIT 1", $user_id));
    if (! $vol) {
        return [];
    }

    $sql = "SELECT a.*, s.event_id, s.shift_label, s.time_start, s.time_end, p.place_name, p.address as place_address, d.event_date, d.day_label, e.title as event_title, e.event_type
            FROM {$table_ass} a
            INNER JOIN {$table_shifts} s ON a.shift_id = s.id
            INNER JOIN {$table_places} p ON s.place_id = p.id
            INNER JOIN {$table_days} d ON s.day_id = d.id
            INNER JOIN {$table_events} e ON s.event_id = e.id
            WHERE a.volunteer_id = %d AND e.status IN ('published', 'completed')
            ORDER BY d.event_date ASC, s.time_start ASC";
    
    $res = $wpdb->get_results($wpdb->prepare($sql, $vol->id));
    return is_array($res) ? $res : [];
}

/**
 * Recupera il sondaggio attivo per un evento.
 *
 * @param int $event_id ID dell'evento.
 * @return object|null
 */
function dfn_get_volunteer_survey_by_event(int $event_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_surveys';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE event_id = %d LIMIT 1", $event_id));
}

/**
 * Recupera un sondaggio tramite public token.
 *
 * @param string $token Token pubblico.
 * @return object|null
 */
function dfn_get_volunteer_survey_by_token(string $token)
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_surveys';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token_public = %s LIMIT 1", $token));
}

/**
 * Recupera tutte le mansioni/ruoli registrati a database.
 *
 * @return array<object>
 */
function dfn_get_all_volunteer_roles(): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_roles';
    $res = $wpdb->get_results("SELECT * FROM {$table} ORDER BY is_default DESC, role_name ASC");
    return is_array($res) ? $res : [];
}

/**
 * Recupera le mansioni associate a uno specifico evento.
 *
 * @param int $event_id ID dell'evento logistica.
 * @return array<object>
 */
function dfn_get_volunteer_event_roles(int $event_id): array
{
    global $wpdb;
    $table_er = $wpdb->prefix . 'dfn_volunteer_event_roles';
    $table_r  = $wpdb->prefix . 'dfn_volunteer_roles';
    $sql = "SELECT r.* 
            FROM {$table_r} r 
            INNER JOIN {$table_er} er ON r.id = er.role_id 
            WHERE er.event_id = %d 
            ORDER BY er.order_num ASC, r.role_name ASC";
    $res = $wpdb->get_results($wpdb->prepare($sql, $event_id));
    return is_array($res) ? $res : [];
}

/**
 * Associa un insieme di mansioni a un evento.
 *
 * @param int $event_id ID dell'evento.
 * @param array<int> $role_ids Array di ID mansione da associare.
 * @return void
 */
function dfn_set_volunteer_event_roles(int $event_id, array $role_ids): void
{
    global $wpdb;
    $table_er = $wpdb->prefix . 'dfn_volunteer_event_roles';
    
    // Rimuovi associazioni precedenti
    $wpdb->delete($table_er, ['event_id' => $event_id], ['%d']);

    $order = 1;
    foreach ($role_ids as $r_id) {
        $r_id = (int) $r_id;
        if ($r_id > 0) {
            $wpdb->insert($table_er, [
                'event_id'  => $event_id,
                'role_id'   => $r_id,
                'order_num' => $order,
            ], ['%d', '%d', '%d']);
            $order++;
        }
    }
}

/**
 * Recupera un ruolo dalla sua role_key.
 *
 * @param string $role_key Chiave identificativa ruolo.
 * @return object|null
 */
function dfn_get_volunteer_role_by_key(string $role_key)
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_volunteer_roles';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE role_key = %s LIMIT 1", $role_key));
}


/**
 * Duplica un evento esistente, il relativo prodotto WooCommerce e gli slot orari.
 * L'evento duplicato viene creato con stato 'draft' (Bozza) e con prenotazioni azzerate.
 *
 * @param int $event_id ID dell'evento originale da duplicare.
 * @return int|false ID del nuovo evento creato, oppure false in caso di errore.
 */
function dfn_db_duplicate_event(int $event_id)
{
    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';
    $table_slots  = $wpdb->prefix . 'dfn_event_slots';

    $event = dfn_db_get_event($event_id);
    if (! $event) {
        return false;
    }

    $new_product_id = 0;

    // 1. Duplica il prodotto WooCommerce associato se presente
    if (! empty($event->product_id)) {
        $orig_product_post = get_post($event->product_id);
        if ($orig_product_post) {
            $new_title = sprintf(__('Copia di %s', 'dfn-theme'), $orig_product_post->post_title);

            $new_product_id = wp_insert_post([
                'post_title'   => $new_title,
                'post_content' => $orig_product_post->post_content,
                'post_excerpt' => $orig_product_post->post_excerpt,
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'post_author'  => get_current_user_id(),
            ]);

            if (! is_wp_error($new_product_id) && $new_product_id > 0) {
                // Clona le tassonomie del prodotto (tipo, categorie, tag)
                $taxonomies = get_object_taxonomies('product');
                foreach ($taxonomies as $tax) {
                    $terms = wp_get_object_terms($event->product_id, $tax, ['fields' => 'slugs']);
                    if (! empty($terms) && ! is_wp_error($terms)) {
                        wp_set_object_terms($new_product_id, $terms, $tax);
                    }
                }

                // Clona i metadati del prodotto
                $meta_keys = [
                    '_price',
                    '_regular_price',
                    '_sale_price',
                    '_virtual',
                    '_sold_individually',
                    '_thumbnail_id',
                    '_product_image_gallery',
                    '_manage_stock',
                    '_stock_status',
                    '_dfn_is_fai_event',
                    '_dfn_event_pricing_type',
                    '_dfn_price_standard',
                    '_dfn_price_fai',
                    '_dfn_event_location',
                    '_dfn_event_city',
                    '_dfn_event_date_start',
                    '_dfn_event_date_end',
                ];

                foreach ($meta_keys as $key) {
                    $val = get_post_meta($event->product_id, $key, true);
                    if ($val !== '') {
                        update_post_meta($new_product_id, $key, $val);
                    }
                }

                // Assicura tipo simple e virtuale
                wp_set_object_terms($new_product_id, 'simple', 'product_type');
                update_post_meta($new_product_id, '_virtual', 'yes');
                update_post_meta($new_product_id, '_sold_individually', 'yes');
            } else {
                $new_product_id = 0;
            }
        }
    }

    // 2. Inserisci la nuova riga in wp_dfn_events con stato 'draft'
    $event_data = [
        'product_id'           => $new_product_id,
        'event_date_start'     => $event->event_date_start,
        'event_date_end'       => $event->event_date_end ?: $event->event_date_start,
        'event_time_start'     => $event->event_time_start,
        'event_time_end'       => $event->event_time_end,
        'location'             => $event->location,
        'city'                 => $event->city ?? '',
        'description'          => $event->description,
        'access_type'          => $event->access_type,
        'allocation_mode'      => $event->allocation_mode,
        'approval_workflow'    => $event->approval_workflow,
        'payment_mode'         => $event->payment_mode,
        'slot_duration'        => $event->slot_duration,
        'slot_capacity'        => $event->slot_capacity,
        'slot_bonus'           => $event->slot_bonus,
        'first_slot_time'      => $event->first_slot_time,
        'last_slot_time'       => $event->last_slot_time,
        'total_capacity'       => $event->total_capacity,
        'price_standard'       => $event->price_standard,
        'price_fai'            => $event->price_fai,
        'staff_config'         => $event->staff_config,
        'status'               => 'draft', // Sempre bozza per consentire controllo date
        'auto_cancel_hours'    => $event->auto_cancel_hours,
        'detail_layout'        => $event->detail_layout ?? 'auto',
        'booking_opening_date' => $event->booking_opening_date,
        'booking_status'       => $event->booking_status ?? 'open',
        'created_at'           => current_time('mysql'),
        'updated_at'           => current_time('mysql'),
    ];

    $inserted = $wpdb->insert($table_events, $event_data);
    if (! $inserted) {
        return false;
    }

    $new_event_id = (int) $wpdb->insert_id;

    // Collega l'ID evento al prodotto WooCommerce
    if ($new_product_id > 0) {
        update_post_meta($new_product_id, '_dfn_event_id', $new_event_id);
    }

    // 3. Duplica i turni orari (wp_dfn_event_slots) con prenotazioni a zero
    $orig_slots = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_slots} WHERE event_id = %d ORDER BY slot_date ASC, slot_time_start ASC",
        $event_id
    ));

    if (! empty($orig_slots)) {
        foreach ($orig_slots as $slot) {
            $wpdb->insert($table_slots, [
                'event_id'        => $new_event_id,
                'slot_date'       => $slot->slot_date,
                'slot_time_start' => $slot->slot_time_start,
                'slot_time_end'   => $slot->slot_time_end,
                'capacity'        => $slot->capacity,
                'bonus_capacity'  => $slot->bonus_capacity,
                'booked_count'    => 0,
                'is_locked'       => 0,
                'created_at'      => current_time('mysql'),
            ]);
        }
    }

    return $new_event_id;
}

/**
 * ========================================================================
 * SEZIONE 20: Campagne Promozionali & Marketing Eventi (Issue #41)
 * ========================================================================
 */

/**
 * Recupera l'elenco degli eventi futuri o in corso (non passati) per le campagne promozionali.
 *
 * @param bool $only_published Se filtrare solo eventi pubblicati (default true).
 * @return array
 */
function dfn_promotions_get_eligible_events(bool $only_published = true): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_events';
    $status_sql = $only_published ? " AND status = 'published' " : " AND status IN ('published', 'draft') ";

    $can_see_test = current_user_can('dfn_manage_events') || current_user_can('manage_options');
    $test_sql = $can_see_test ? '' : ' AND (is_test_event = 0 OR is_test_event IS NULL)';

    $events = $wpdb->get_results(
        "SELECT * FROM {$table} 
         WHERE (COALESCE(event_date_end, event_date_start) >= CURDATE())
         {$status_sql}
         {$test_sql}
         ORDER BY event_date_start ASC, event_time_start ASC"
    );

    if (! is_array($events)) {
        return [];
    }

    foreach ($events as $event) {
        $event->title = get_the_title($event->product_id) ?: sprintf(__('Evento #%d', 'dfn-theme'), $event->id);
        $event->permalink = get_permalink($event->product_id) ?: '';
        $thumb_url = get_the_post_thumbnail_url($event->product_id, 'medium_large');
        if (! $thumb_url) {
            $placeholder_id = (int) dfn_get_setting('default_placeholder_image_id', 0);
            if ($placeholder_id > 0) {
                $thumb_url = wp_get_attachment_image_url($placeholder_id, 'medium_large');
            }
        }
        $event->image_url = $thumb_url ?: '';
    }

    return $events;
}

/**
 * Verifica se un indirizzo email è valido per la ricezione di comunicazioni promozionali e newsletter.
 * Esclude rigorosamente indirizzi segnaposto fittizi creati per i biglietti a botteghino o in cassa
 * (es. no-email-*@dfn.local, cassa_*@fainovara.local, autorita_*@...), domini non instradabili (.local, .test)
 * e domini di test/esempio (es. @example.com).
 *
 * @param string $email Indirizzo email da verificare.
 * @return bool True se l'email è reale e valida per l'invio promozionale, false se fittizia o non valida.
 */
function dfn_is_valid_promotional_email(string $email): bool
{
    $email = strtolower(trim($email));

    if (empty($email) || ! is_email($email)) {
        return false;
    }

    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) {
        return false;
    }

    $local_part = $parts[0];
    $domain     = $parts[1];

    // Domini non instradabili o locali (RFC o botteghino locale)
    if (str_ends_with($domain, '.local') || str_ends_with($domain, '.test') || str_ends_with($domain, '.invalid') || str_ends_with($domain, '.localhost')) {
        return false;
    }

    // Domini di esempio RFC e placeholder noti
    if ($domain === 'example.com' || $domain === 'example.org' || $domain === 'example.net' || str_ends_with($domain, '.example.com')) {
        return false;
    }

    // Indirizzi fittizi generati dal botteghino / PWA scanner e segnaposto DFN
    if (str_contains($local_part, 'no-email') || $domain === 'dfn.local' || $domain === 'fainovara.local') {
        return false;
    }

    // Prefissi botteghino cassa / autorità (es. cassa_9968@, autorita_1234@)
    if (preg_match('/^(cassa|autorita)[_-]/i', $local_part)) {
        return false;
    }

    // Indirizzi placeholder storici
    if ($email === 'no-email@dfn.it') {
        return false;
    }

    return true;
}

/**
 * Calcola e restituisce il pubblico target per una campagna promozionale su un evento specifico.
 * Esclude rigorosamente tutti gli utenti che hanno già una prenotazione attiva (non cancellata) per tale evento.
 *
 * @param int $event_id ID dell'evento promosso.
 * @return array Lista di destinatari unici [['email' => '...', 'name' => '...'], ...]
 */
function dfn_promotions_get_target_audience(int $event_id): array
{
    global $wpdb;

    // 1. Trova tutte le email già prenotate per questo evento (escludendo gli stati annullati/scaduti)
    $table_bookings = $wpdb->prefix . 'dfn_bookings';
    $excluded_emails_raw = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT LOWER(TRIM(customer_email)) 
             FROM {$table_bookings} 
             WHERE event_id = %d 
               AND status NOT IN ('cancelled', 'expired', 'rejected') 
               AND customer_email IS NOT NULL 
               AND customer_email != ''",
            $event_id
        )
    );

    $excluded_map = [];
    if (is_array($excluded_emails_raw)) {
        foreach ($excluded_emails_raw as $em) {
            $em_clean = strtolower(trim((string) $em));
            if (! empty($em_clean)) {
                $excluded_map[$em_clean] = true;
            }
        }
    }

    // Mappa dei destinatari aggregati: [email_lower => ['email' => email_originale, 'name' => nome]]
    $audience = [];

    // 2. Clienti storici da wp_dfn_bookings
    $booking_customers = $wpdb->get_results(
        "SELECT customer_email, customer_name 
         FROM {$table_bookings} 
         WHERE customer_email IS NOT NULL 
           AND customer_email != '' 
         ORDER BY id DESC"
    );

    if (is_array($booking_customers)) {
        foreach ($booking_customers as $row) {
            $email = sanitize_email(trim((string) $row->customer_email));
            if (! dfn_is_valid_promotional_email($email)) {
                continue;
            }
            $email_lower = strtolower($email);
            if (isset($excluded_map[$email_lower]) || isset($audience[$email_lower])) {
                continue;
            }
            $name = sanitize_text_field(trim((string) ($row->customer_name ?? '')));
            $audience[$email_lower] = [
                'email' => $email,
                'name'  => $name,
            ];
        }
    }

    // 3. Clienti da ordini WooCommerce (se presenti in postmeta)
    $table_postmeta = $wpdb->postmeta;
    if ($table_postmeta) {
        $wc_orders = $wpdb->get_results(
            "SELECT pm_email.meta_value AS email, pm_first.meta_value AS first_name, pm_last.meta_value AS last_name
             FROM {$table_postmeta} pm_email
             LEFT JOIN {$table_postmeta} pm_first ON (pm_first.post_id = pm_email.post_id AND pm_first.meta_key = '_billing_first_name')
             LEFT JOIN {$table_postmeta} pm_last ON (pm_last.post_id = pm_email.post_id AND pm_last.meta_key = '_billing_last_name')
             WHERE pm_email.meta_key = '_billing_email'
               AND pm_email.meta_value IS NOT NULL
               AND pm_email.meta_value != ''
             LIMIT 5000"
        );

        if (is_array($wc_orders)) {
            foreach ($wc_orders as $wc) {
                $email = sanitize_email(trim((string) $wc->email));
                if (! dfn_is_valid_promotional_email($email)) {
                    continue;
                }
                $email_lower = strtolower($email);
                if (isset($excluded_map[$email_lower]) || isset($audience[$email_lower])) {
                    continue;
                }
                $full_name = trim(((string) ($wc->first_name ?? '')) . ' ' . ((string) ($wc->last_name ?? '')));
                $audience[$email_lower] = [
                    'email' => $email,
                    'name'  => sanitize_text_field($full_name),
                ];
            }
        }
    }

    // 4. Soci registrati da wp_dfn_fai_members
    $table_fai = $wpdb->prefix . 'dfn_fai_members';
    $fai_members = $wpdb->get_results(
        "SELECT email, first_name, last_name 
         FROM {$table_fai} 
         WHERE email IS NOT NULL 
           AND email != ''"
    );

    if (is_array($fai_members)) {
        foreach ($fai_members as $fm) {
            $email = sanitize_email(trim((string) $fm->email));
            if (! dfn_is_valid_promotional_email($email)) {
                continue;
            }
            $email_lower = strtolower($email);
            if (isset($excluded_map[$email_lower]) || isset($audience[$email_lower])) {
                continue;
            }
            $full_name = trim(((string) ($fm->first_name ?? '')) . ' ' . ((string) ($fm->last_name ?? '')));
            $audience[$email_lower] = [
                'email' => $email,
                'name'  => sanitize_text_field($full_name),
            ];
        }
    }

    // 5. Utenti registrati in WordPress
    $wp_users = $wpdb->get_results("SELECT user_email, display_name FROM {$wpdb->users} WHERE user_email IS NOT NULL AND user_email != '' LIMIT 2000");
    if (is_array($wp_users)) {
        foreach ($wp_users as $u) {
            $email = sanitize_email(trim((string) $u->user_email));
            if (! dfn_is_valid_promotional_email($email)) {
                continue;
            }
            $email_lower = strtolower($email);
            if (isset($excluded_map[$email_lower]) || isset($audience[$email_lower])) {
                continue;
            }
            $audience[$email_lower] = [
                'email' => $email,
                'name'  => sanitize_text_field((string) ($u->display_name ?? '')),
            ];
        }
    }

    return array_values($audience);
}

/**
 * Calcola le metriche e il riepilogo del pubblico per un determinato evento.
 *
 * @param int $event_id ID dell'evento.
 * @return array{total_contacts: int, already_booked: int, eligible: int}
 */
function dfn_promotions_get_audience_stats(int $event_id): array
{
    global $wpdb;
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // Email uniche già prenotate per questo evento (escludendo segnaposto e dummy fittizi)
    $already_booked_rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT customer_email 
             FROM {$table_bookings} 
             WHERE event_id = %d 
               AND status NOT IN ('cancelled', 'expired', 'rejected') 
               AND customer_email IS NOT NULL 
               AND customer_email != ''",
            $event_id
        )
    );
    $already_booked_emails = [];
    if (is_array($already_booked_rows)) {
        foreach ($already_booked_rows as $raw_e) {
            $e = sanitize_email(trim((string) $raw_e));
            if (dfn_is_valid_promotional_email($e)) {
                $already_booked_emails[strtolower($e)] = true;
            }
        }
    }
    $already_booked_count = count($already_booked_emails);

    $eligible_audience = dfn_promotions_get_target_audience($event_id);
    $eligible_count = count($eligible_audience);

    return [
        'total_contacts' => $eligible_count + $already_booked_count,
        'already_booked' => $already_booked_count,
        'eligible'       => $eligible_count,
    ];
}

/**
 * Recupera l'elenco delle promozioni/campagne salvate.
 *
 * @param array $args
 * @return array
 */
function dfn_db_get_promotions(array $args = []): array
{
    global $wpdb;
    $table_promotions = $wpdb->prefix . 'dfn_promotions';
    $table_events = $wpdb->prefix . 'dfn_events';

    $sql = "SELECT p.*, e.product_id, e.event_date_start, e.location, e.city
            FROM {$table_promotions} p
            LEFT JOIN {$table_events} e ON e.id = p.event_id
            ORDER BY p.id DESC";

    $results = $wpdb->get_results($sql);
    if (! is_array($results)) {
        return [];
    }

    foreach ($results as $item) {
        $item->event_title = $item->product_id ? get_the_title($item->product_id) : sprintf(__('Evento #%d', 'dfn-theme'), $item->event_id);
    }

    return $results;
}

/**
 * Recupera una singola promozione per ID.
 *
 * @param int $id
 * @return object|null
 */
function dfn_db_get_promotion(int $id): ?object
{
    global $wpdb;
    $table_promotions = $wpdb->prefix . 'dfn_promotions';
    $table_events = $wpdb->prefix . 'dfn_events';

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT p.*, e.product_id, e.event_date_start, e.event_date_end, e.event_time_start, e.location, e.city, e.description as event_description
             FROM {$table_promotions} p
             LEFT JOIN {$table_events} e ON e.id = p.event_id
             WHERE p.id = %d",
            $id
        )
    );

    if ($row) {
        $row->event_title = $row->product_id ? get_the_title($row->product_id) : sprintf(__('Evento #%d', 'dfn-theme'), $row->event_id);
        $row->event_permalink = $row->product_id ? get_permalink($row->product_id) : '';
    }

    return $row ?: null;
}

/**
 * Salva o aggiorna una promozione nel database.
 *
 * @param array $data
 * @return int|false ID della promozione o false
 */
function dfn_db_save_promotion(array $data)
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_promotions';

    // Auto-healing difensivo per assicurare la presenza delle colonne batch
    static $cols_checked = false;
    if (! $cols_checked) {
        $promo_cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (! empty($promo_cols)) {
            if (! in_array('batch_size', $promo_cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN batch_size int(10) unsigned NOT NULL DEFAULT 25 AFTER failed_count");
            }
            if (! in_array('initial_batch_size', $promo_cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN initial_batch_size int(10) unsigned NOT NULL DEFAULT 15 AFTER batch_size");
            }
            if (! in_array('batch_interval_minutes', $promo_cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN batch_interval_minutes int(10) unsigned NOT NULL DEFAULT 5 AFTER initial_batch_size");
            }
            if (! in_array('last_batch_at', $promo_cols, true)) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN last_batch_at datetime DEFAULT NULL AFTER batch_interval_minutes");
            }
        }
        $cols_checked = true;
    }

    $id = ! empty($data['id']) ? absint($data['id']) : 0;
    unset($data['id']);

    $data['updated_at'] = current_time('mysql');

    if ($id > 0) {
        $updated = $wpdb->update($table, $data, ['id' => $id]);
        return $updated !== false ? $id : false;
    }

    if (empty($data['created_at'])) {
        $data['created_at'] = current_time('mysql');
    }
    if (empty($data['created_by'])) {
        $data['created_by'] = get_current_user_id();
    }

    $inserted = $wpdb->insert($table, $data);
    return $inserted ? (int) $wpdb->insert_id : false;
}

/**
 * Elimina una promozione e i relativi record in coda.
 *
 * @param int $id
 * @return bool
 */
function dfn_db_delete_promotion(int $id): bool
{
    global $wpdb;
    $table_promotions = $wpdb->prefix . 'dfn_promotions';
    $table_queue      = $wpdb->prefix . 'dfn_promotion_queue';

    $wpdb->delete($table_queue, ['promotion_id' => $id]);
    $deleted = $wpdb->delete($table_promotions, ['id' => $id]);

    return (bool) $deleted;
}

/**
 * Popola la tabella di invio (coda) per una promozione specifica.
 *
 * @param int   $promotion_id ID promozione
 * @param array $recipients   Lista di array ['email' => '...', 'name' => '...']
 * @return int Numero di destinatari inseriti in coda
 */
function dfn_db_populate_promotion_queue(int $promotion_id, array $recipients): int
{
    global $wpdb;
    $table_queue      = $wpdb->prefix . 'dfn_promotion_queue';
    $table_promotions = $wpdb->prefix . 'dfn_promotions';

    // Rimuovi eventuali record precedenti in coda per questa promozione se era bozza
    $wpdb->delete($table_queue, ['promotion_id' => $promotion_id]);

    $inserted_count = 0;
    $now = current_time('mysql');

    foreach ($recipients as $item) {
        $email = sanitize_email($item['email'] ?? '');
        if (! dfn_is_valid_promotional_email($email)) {
            continue;
        }
        $name = sanitize_text_field($item['name'] ?? '');

        // Genera token di tracking univoco a 32 caratteri
        $token = function_exists('wp_generate_password') ? wp_generate_password(32, false, false) : bin2hex(random_bytes(16));

        $ins = $wpdb->insert($table_queue, [
            'promotion_id'    => $promotion_id,
            'recipient_email' => $email,
            'recipient_name'  => $name,
            'tracking_token'  => $token,
            'status'          => 'pending',
            'created_at'      => $now,
        ]);

        if ($ins) {
            $inserted_count++;
        }
    }

    $wpdb->update($table_promotions, [
        'total_recipients' => $inserted_count,
        'sent_count'       => 0,
        'failed_count'     => 0,
        'opened_count'     => 0,
        'clicked_count'    => 0,
        'converted_count'  => 0,
        'revenue_generated'=> 0.00,
        'updated_at'       => $now,
    ], ['id' => $promotion_id]);

    return $inserted_count;
}

/**
 * Recupera i destinatari in coda per una specifica campagna promozionale con supporto paginazione e ricerca.
 *
 * @param int    $promotion_id ID della promozione
 * @param int    $limit        Limite di risultati (default 20)
 * @param string $status       Filtro opzionale sullo stato ('pending', 'sent', 'failed', 'opened', 'clicked', 'converted', 'cancelled' o '' per tutti)
 * @param string $search       Termine di ricerca opzionale per email o nome
 * @param int    $offset       Offset per paginazione (default 0)
 * @return array
 */
function dfn_db_get_promotion_queue(int $promotion_id, int $limit = 20, string $status = '', string $search = '', int $offset = 0): array
{
    global $wpdb;
    $table_queue = $wpdb->prefix . 'dfn_promotion_queue';

    $where = "promotion_id = %d";
    $params = [$promotion_id];

    if (! empty($status)) {
        if ($status === 'opened') {
            $where .= " AND opened_at IS NOT NULL";
        } elseif ($status === 'clicked') {
            $where .= " AND clicked_at IS NOT NULL";
        } elseif ($status === 'converted') {
            $where .= " AND converted_at IS NOT NULL";
        } else {
            $where .= " AND status = %s";
            $params[] = $status;
        }
    }

    if (! empty($search)) {
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        $where .= " AND (recipient_email LIKE %s OR recipient_name LIKE %s)";
        $params[] = $like;
        $params[] = $like;
    }

    $params[] = max(1, $limit);
    $params[] = max(0, $offset);

    $sql = $wpdb->prepare("SELECT * FROM {$table_queue} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", ...$params);
    $results = $wpdb->get_results($sql);

    return is_array($results) ? $results : [];
}

/**
 * Conta il numero totale di destinatari in coda per una specifica campagna filtrati per stato e ricerca.
 *
 * @param int    $promotion_id ID della promozione
 * @param string $status       Filtro opzionale sullo stato
 * @param string $search       Termine di ricerca opzionale
 * @return int
 */
function dfn_db_count_promotion_queue(int $promotion_id, string $status = '', string $search = ''): int
{
    global $wpdb;
    $table_queue = $wpdb->prefix . 'dfn_promotion_queue';

    $where = "promotion_id = %d";
    $params = [$promotion_id];

    if (! empty($status)) {
        if ($status === 'opened') {
            $where .= " AND opened_at IS NOT NULL";
        } elseif ($status === 'clicked') {
            $where .= " AND clicked_at IS NOT NULL";
        } elseif ($status === 'converted') {
            $where .= " AND converted_at IS NOT NULL";
        } else {
            $where .= " AND status = %s";
            $params[] = $status;
        }
    }

    if (! empty($search)) {
        $like = '%' . $wpdb->esc_like(trim($search)) . '%';
        $where .= " AND (recipient_email LIKE %s OR recipient_name LIKE %s)";
        $params[] = $like;
        $params[] = $like;
    }

    $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table_queue} WHERE {$where}", ...$params);
    return (int) $wpdb->get_var($sql);
}

/**
 * Recupera i conteggi aggregati per stato della coda e interazioni di una promozione.
 *
 * @param int $promotion_id
 * @return array{total: int, pending: int, sent: int, failed: int, cancelled: int, opened: int, clicked: int, converted: int, revenue: float}
 */
function dfn_db_get_promotion_queue_counts(int $promotion_id): array
{
    global $wpdb;
    $table_queue = $wpdb->prefix . 'dfn_promotion_queue';

    $counts = [
        'total'     => 0,
        'pending'   => 0,
        'sent'      => 0,
        'failed'    => 0,
        'cancelled' => 0,
        'opened'    => 0,
        'clicked'   => 0,
        'converted' => 0,
        'revenue'   => 0.0,
    ];

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT status, COUNT(*) as cnt FROM {$table_queue} WHERE promotion_id = %d GROUP BY status",
            $promotion_id
        )
    );

    if (is_array($rows)) {
        foreach ($rows as $r) {
            $st = (string) $r->status;
            $cnt = (int) $r->cnt;
            $counts['total'] += $cnt;
            if (isset($counts[$st])) {
                $counts[$st] = $cnt;
            }
        }
    }

    // Conteggi aggregati interazioni (aperture, click, conversioni e incasso euro)
    $interactions = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL THEN id END) as opens,
            COUNT(DISTINCT CASE WHEN clicked_at IS NOT NULL THEN id END) as clicks,
            COUNT(DISTINCT CASE WHEN converted_at IS NOT NULL THEN id END) as conversions,
            COALESCE(SUM(order_total), 0) as total_revenue
         FROM {$table_queue} WHERE promotion_id = %d",
        $promotion_id
    ));

    if ($interactions) {
        $counts['opened']    = (int) $interactions->opens;
        $counts['clicked']   = (int) $interactions->clicks;
        $counts['converted'] = (int) $interactions->conversions;
        $counts['revenue']   = (float) $interactions->total_revenue;
    }

    return $counts;
}

/**
 * Recupera le metriche aggregate di performance e funnel per una specifica campagna.
 *
 * @param int $promotion_id ID della promozione
 * @return array
 */
function dfn_db_get_promotion_analytics(int $promotion_id): array
{
    global $wpdb;
    $table_queue = $wpdb->prefix . 'dfn_promotion_queue';
    $table_promo = $wpdb->prefix . 'dfn_promotions';

    $promo = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_promo} WHERE id = %d", $promotion_id));
    if (! $promo) {
        return [
            'sent_count'         => 0,
            'opened_count'       => 0,
            'open_rate'          => 0.0,
            'clicked_count'      => 0,
            'click_rate'         => 0.0,
            'click_to_open_rate' => 0.0,
            'ctr'                => 0.0,
            'converted_count'    => 0,
            'conversion_rate'    => 0.0,
            'revenue_generated'  => 0.0,
        ];
    }

    $sent = (int) $promo->sent_count;

    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(DISTINCT CASE WHEN opened_at IS NOT NULL THEN id END) as opens,
            COUNT(DISTINCT CASE WHEN clicked_at IS NOT NULL THEN id END) as clicks,
            COUNT(DISTINCT CASE WHEN converted_at IS NOT NULL THEN id END) as conversions,
            COALESCE(SUM(order_total), 0) as total_revenue
         FROM {$table_queue}
         WHERE promotion_id = %d",
        $promotion_id
    ));

    $opens       = $stats ? (int) $stats->opens : (int) ($promo->opened_count ?? 0);
    $clicks      = $stats ? (int) $stats->clicks : (int) ($promo->clicked_count ?? 0);
    $conversions = $stats ? (int) $stats->conversions : (int) ($promo->converted_count ?? 0);
    $revenue     = $stats ? (float) $stats->total_revenue : (float) ($promo->revenue_generated ?? 0.0);

    $open_rate          = ($sent > 0) ? round(($opens / $sent) * 100, 1) : 0.0;
    $click_rate         = ($sent > 0) ? round(($clicks / $sent) * 100, 1) : 0.0;
    $click_to_open_rate = ($opens > 0) ? round(($clicks / $opens) * 100, 1) : 0.0;
    $conversion_rate    = ($sent > 0) ? round(($conversions / $sent) * 100, 1) : 0.0;

    return [
        'sent_count'         => $sent,
        'opened_count'       => $opens,
        'open_rate'          => $open_rate,
        'clicked_count'      => $clicks,
        'click_rate'         => $click_rate,
        'click_to_open_rate' => $click_to_open_rate,
        'ctr'                => $click_to_open_rate,
        'converted_count'    => $conversions,
        'conversion_rate'    => $conversion_rate,
        'revenue_generated'  => $revenue,
    ];
}

/**
 * Costruisce l'HTML completo per una email promozionale brandizzata DFN con supporto tracking.
 *
 * @param object $promotion       Oggetto promozione
 * @param string $recipient_name  Nome destinatario
 * @param object $event           Oggetto evento arricchito
 * @param string $tracking_token  Token univoco di tracking per il destinatario (opzionale)
 * @return string HTML completo
 */
function dfn_promotions_build_email_html(object $promotion, string $recipient_name, object $event, string $tracking_token = ''): string
{
    $primary_color = dfn_get_setting('email_primary_color', '#004b23');
    $accent_color  = dfn_get_setting('email_accent_color', '#e74f30');
    $site_name     = dfn_get_setting('delegation_name', get_bloginfo('name'));

    $greeting_name = ! empty($recipient_name) ? esc_html($recipient_name) : 'appassionato del nostro territorio';

    // Data formattata
    $date_formatted = '';
    if (! empty($event->event_date_start)) {
        $date_formatted = date_i18n('l j F Y', strtotime($event->event_date_start));
        if (! empty($event->event_date_end) && $event->event_date_end !== $event->event_date_start) {
            $date_formatted .= ' - ' . date_i18n('l j F Y', strtotime($event->event_date_end));
        }
    }

    $time_formatted = '';
    if (! empty($event->event_time_start)) {
        $time_formatted = substr($event->event_time_start, 0, 5);
        if (! empty($event->event_time_end)) {
            $time_formatted .= ' - ' . substr($event->event_time_end, 0, 5);
        }
    }

    $location_text = trim(($event->location ?? '') . ($event->city ? ', ' . $event->city : ''));
    $cta_text = ! empty($promotion->cta_text) ? esc_html($promotion->cta_text) : 'Prenota il tuo posto';

    // Se è presente il token di tracking, il link passa per il tracker locale
    if (! empty($tracking_token)) {
        $cta_url = home_url('/?dfn_action=promo_click&dfn_token=' . urlencode($tracking_token));
    } else {
        $cta_url = ! empty($event->permalink) ? esc_url($event->permalink) : home_url('/eventi/');
    }

    ob_start();
    ?>
    <div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #2d3748; line-height: 1.6;">
        <p style="font-size: 16px; margin-bottom: 16px;">
            Gentile <strong><?php echo $greeting_name; ?></strong>,
        </p>

        <?php if (! empty($promotion->email_intro)) : ?>
            <div style="font-size: 15px; color: #4a5568; margin-bottom: 24px; white-space: pre-line;">
                <?php echo wp_kses_post(nl2br($promotion->email_intro)); ?>
            </div>
        <?php endif; ?>

        <!-- Event Card -->
        <div style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background-color: #ffffff; margin-bottom: 26px; box-shadow: 0 4px 6px rgba(0,0,0,0.04);">
            <?php if (! empty($event->image_url)) : ?>
                <div style="width: 100%; background-color: #f7fafc; text-align: center; line-height: 0;">
                    <a href="<?php echo esc_url($cta_url); ?>" style="display: block; text-decoration: none;" target="_blank">
                        <img src="<?php echo esc_url($event->image_url); ?>" alt="<?php echo esc_attr($event->title); ?>" style="width: 100%; max-width: 600px; height: auto; display: block; margin: 0 auto; border: 0;" />
                    </a>
                </div>
            <?php endif; ?>

            <div style="padding: 24px;">
                <div style="display: inline-block; background-color: #f0fdf4; color: <?php echo esc_attr($primary_color); ?>; border: 1px solid #bbf7d0; padding: 4px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 12px; letter-spacing: 0.5px;">
                    Nuovo Evento in Programma
                </div>

                <h2 style="font-size: 22px; font-weight: 700; color: #1a202c; margin: 0 0 14px 0; line-height: 1.3;">
                    <?php echo esc_html($event->title); ?>
                </h2>

                <div style="font-size: 14px; color: #4a5568; margin-bottom: 16px;">
                    <?php if (! empty($date_formatted)) : ?>
                        <div style="margin-bottom: 6px;">
                            <span style="font-size: 16px;">📅</span> <strong>Data:</strong> <?php echo esc_html($date_formatted); ?>
                            <?php if (! empty($time_formatted)) : ?> (Ore <?php echo esc_html($time_formatted); ?>)<?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (! empty($location_text)) : ?>
                        <div style="margin-bottom: 6px;">
                            <span style="font-size: 16px;">📍</span> <strong>Luogo:</strong> <?php echo esc_html($location_text); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (! empty($event->description)) : ?>
                    <div style="font-size: 14px; color: #4a5568; margin-bottom: 20px; line-height: 1.5; border-top: 1px dashed #e2e8f0; padding-top: 14px;">
                        <?php echo wp_kses_post(wpautop(wp_trim_words($event->description, 50, '...'))); ?>
                    </div>
                <?php endif; ?>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="<?php echo esc_url($cta_url); ?>" style="display: inline-block; background-color: <?php echo esc_attr($primary_color); ?>; color: #ffffff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-size: 16px; font-weight: 700; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: background-color 0.2s;" target="_blank">
                        🎟️ <?php echo $cta_text; ?>
                    </a>
                </div>
            </div>
        </div>

        <?php if (! empty($promotion->email_custom_text)) : ?>
            <div style="font-size: 15px; color: #2d3748; margin-bottom: 24px; line-height: 1.6; white-space: pre-line;">
                <?php echo wp_kses_post(nl2br($promotion->email_custom_text)); ?>
            </div>
        <?php endif; ?>

        <p style="font-size: 14px; color: #718096; margin-top: 30px; border-top: 1px solid #edf2f7; padding-top: 16px;">
            Ti aspettiamo per vivere insieme una nuova esperienza alla scoperta del nostro patrimonio.<br>
            <strong><?php echo esc_html($site_name); ?></strong>
        </p>

        <?php if (! empty($tracking_token)) : ?>
            <!-- Pixel di tracciamento apertura 1x1 trasparente -->
            <img src="<?php echo esc_url(home_url('/?dfn_action=promo_open&dfn_token=' . urlencode($tracking_token))); ?>" width="1" height="1" alt="" style="display:none;width:1px;height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;border:none;" />
        <?php endif; ?>
    </div>
    <?php
    $content_html = ob_get_clean();

    // Avvolge nel template unificato di delegazione
    if (function_exists('dfn_get_email_html_template')) {
        return dfn_get_email_html_template($promotion->email_subject, $content_html);
    }

    return $content_html;
}

/**
 * Elabora un lotto (batch) di invio della coda promozionale.
 * Rispetta la deliverability e i limiti orari dell'host.
 *
 * @param int  $batch_size      Numero massimo di email da elaborare per singola esecuzione (default 25).
 * @param bool $ignore_interval Se true, ignora il controllo dell'intervallo temporale (es. invio manuale da admin).
 * @return array Statistiche di elaborazione ['processed' => int, 'sent' => int, 'failed' => int, 'status' => string]
 */
function dfn_promotions_process_queue_batch(int $batch_size = 25, bool $ignore_interval = false): array
{
    global $wpdb;

    // Feature toggle check: se il modulo promozioni è disattivato, non inviare
    if (function_exists('dfn_get_setting') && dfn_get_setting('enable_event_promotions', 'yes') !== 'yes') {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'status' => 'disabled'];
    }

    $table_promotions = $wpdb->prefix . 'dfn_promotions';
    $table_queue      = $wpdb->prefix . 'dfn_promotion_queue';

    // Cerca campagne in stato 'sending' (o 'scheduled' con data superata)
    $promotions = $wpdb->get_results(
        "SELECT * FROM {$table_promotions} 
         WHERE status = 'sending' 
            OR (status = 'scheduled' AND scheduled_at <= NOW())
         ORDER BY id ASC"
    );

    if (empty($promotions)) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'status' => 'idle'];
    }

    $total_processed = 0;
    $total_sent = 0;
    $total_failed = 0;

    foreach ($promotions as $promo) {
        if ($promo->status === 'scheduled') {
            $wpdb->update($table_promotions, ['status' => 'sending'], ['id' => $promo->id]);
            $promo->status = 'sending';
        }

        // Verifica intervallo di invio specifico della campagna (allineato alla timezone locale del sito)
        $promo_interval = ! empty($promo->batch_interval_minutes) ? absint($promo->batch_interval_minutes) : 5;
        if (! $ignore_interval && ! empty($promo->last_batch_at)) {
            $last_ts = strtotime($promo->last_batch_at);
            $now_ts  = current_time('timestamp');
            // Tolleranza di 30 secondi per jitter dell'esecuzione cron
            if ($last_ts > 0 && ($now_ts - $last_ts) < (($promo_interval * 60) - 30)) {
                continue;
            }
        }

        // Recupera info evento
        $events = dfn_promotions_get_eligible_events(false);
        $event = null;
        foreach ($events as $ev) {
            if ((int) $ev->id === (int) $promo->event_id) {
                $event = $ev;
                break;
            }
        }

        if (! $event) {
            // Evento non trovato o rimosso
            continue;
        }

        $promo_batch_size = ! empty($promo->batch_size) ? absint($promo->batch_size) : $batch_size;
        $limit_promo = min($promo_batch_size, max(1, $batch_size - $total_processed));

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_queue} 
                 WHERE promotion_id = %d AND status = 'pending' 
                 ORDER BY id ASC 
                 LIMIT %d",
                $promo->id,
                $limit_promo
            )
        );

        if (empty($items)) {
            // Verifica di sicurezza prima di dichiarare completata la campagna:
            // Controlla se esistono davvero zero elementi pending per questa promozione
            $pending_count = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$table_queue} WHERE promotion_id = %d AND status = 'pending'", $promo->id)
            );

            if ($pending_count === 0) {
                // Nessun elemento pendente rimasto per questa promozione: contrassegna come completata
                $wpdb->update($table_promotions, [
                    'status'       => 'completed',
                    'completed_at' => current_time('mysql'),
                ], ['id' => $promo->id]);
            }
            continue;
        }

        foreach ($items as $item) {
            $email = sanitize_email($item->recipient_email);
            if (! dfn_is_valid_promotional_email($email)) {
                $wpdb->update($table_queue, [
                    'status'        => 'failed',
                    'error_message' => "Indirizzo email segnaposto o non valido (escluso dall'invio)",
                    'sent_at'       => current_time('mysql'),
                ], ['id' => $item->id]);
                $wpdb->query($wpdb->prepare("UPDATE {$table_promotions} SET failed_count = failed_count + 1 WHERE id = %d", $promo->id));
                $total_failed++;
                $total_processed++;
                continue;
            }

            // Assicura che tracking_token esista
            if (empty($item->tracking_token)) {
                $item_token = function_exists('wp_generate_password') ? wp_generate_password(32, false, false) : bin2hex(random_bytes(16));
                $wpdb->update($table_queue, ['tracking_token' => $item_token], ['id' => $item->id]);
                $item->tracking_token = $item_token;
            }

            // Genera l'HTML personalizzato con il token di tracking
            $email_html = dfn_promotions_build_email_html($promo, (string) $item->recipient_name, $event, (string) $item->tracking_token);
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            // Invio via wp_mail
            $sent = wp_mail($email, $promo->email_subject, $email_html, $headers);

            if ($sent) {
                $wpdb->update($table_queue, [
                    'status'  => 'sent',
                    'sent_at' => current_time('mysql'),
                ], ['id' => $item->id]);
                $wpdb->query($wpdb->prepare("UPDATE {$table_promotions} SET sent_count = sent_count + 1 WHERE id = %d", $promo->id));
                $total_sent++;
            } else {
                $wpdb->update($table_queue, [
                    'status'        => 'failed',
                    'error_message' => 'Errore restitutito da wp_mail()',
                    'sent_at'       => current_time('mysql'),
                ], ['id' => $item->id]);
                $wpdb->query($wpdb->prepare("UPDATE {$table_promotions} SET failed_count = failed_count + 1 WHERE id = %d", $promo->id));
                $total_failed++;
            }

            $total_processed++;
            if ($total_processed >= $batch_size) {
                break;
            }
        }

        if (! empty($items)) {
            $wpdb->update($table_promotions, ['last_batch_at' => current_time('mysql')], ['id' => $promo->id]);
        }

        // Controlla se sono rimasti altri elementi pendenti
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table_queue} WHERE promotion_id = %d AND status = 'pending'", $promo->id)
        );

        if ($remaining === 0) {
            $wpdb->update($table_promotions, [
                'status'       => 'completed',
                'completed_at' => current_time('mysql'),
            ], ['id' => $promo->id]);
        }

        if ($total_processed >= $batch_size) {
            break;
        }
    }

    return [
        'processed' => $total_processed,
        'sent'      => $total_sent,
        'failed'    => $total_failed,
        'status'    => ($total_processed > 0) ? 'running' : 'idle',
    ];
}

/**
 * Registra l'apertura di un'email promozionale via tracking pixel.
 *
 * @param string $token Token univoco di tracking
 * @return bool True se il record è stato aggiornato con successo
 */
function dfn_promotions_record_open(string $token): bool
{
    if (empty($token)) {
        return false;
    }

    global $wpdb;
    $table_queue = $wpdb->prefix . 'dfn_promotion_queue';
    $table_promo = $wpdb->prefix . 'dfn_promotions';

    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_queue} WHERE tracking_token = %s", $token));
    if (! $item) {
        return false;
    }

    $is_first_open = empty($item->opened_at);
    $now = current_time('mysql');

    $wpdb->query($wpdb->prepare(
        "UPDATE {$table_queue} 
         SET opened_at = COALESCE(opened_at, %s),
             open_count = open_count + 1 
         WHERE id = %d",
        $now,
        $item->id
    ));

    if ($is_first_open) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_promo} 
             SET opened_count = opened_count + 1 
             WHERE id = %d",
            $item->promotion_id
        ));
    }

    return true;
}

/**
 * Registra il click sulla Call to Action e restituisce l'URL di destinazione evento arricchito con UTM.
 *
 * @param string $token Token univoco di tracking
 * @return array{promotion_id: int, target_url: string}|null
 */
function dfn_promotions_record_click(string $token): ?array
{
    if (empty($token)) {
        return null;
    }

    global $wpdb;
    $table_queue  = $wpdb->prefix . 'dfn_promotion_queue';
    $table_promo  = $wpdb->prefix . 'dfn_promotions';
    $table_events = $wpdb->prefix . 'dfn_events';

    $item = $wpdb->get_row($wpdb->prepare(
        "SELECT q.*, p.event_id, p.title as promo_title, e.product_id 
         FROM {$table_queue} q
         INNER JOIN {$table_promo} p ON p.id = q.promotion_id
         LEFT JOIN {$table_events} e ON e.id = p.event_id
         WHERE q.tracking_token = %s",
        $token
    ));

    if (! $item) {
        return null;
    }

    $is_first_click = empty($item->clicked_at);
    $is_first_open  = empty($item->opened_at);
    $now = current_time('mysql');

    // Se l'utente clicca, l'email è sicuramente stata aperta (anche se le immagini erano bloccate)
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table_queue} 
         SET clicked_at = COALESCE(clicked_at, %s),
             click_count = click_count + 1,
             opened_at = COALESCE(opened_at, %s),
             open_count = IF(open_count = 0, 1, open_count) 
         WHERE id = %d",
        $now,
        $now,
        $item->id
    ));

    if ($is_first_click) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_promo} 
             SET clicked_count = clicked_count + 1 
             WHERE id = %d",
            $item->promotion_id
        ));
    }

    if ($is_first_open) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_promo} 
             SET opened_count = opened_count + 1 
             WHERE id = %d",
            $item->promotion_id
        ));
    }

    // Costruzione URL di destinazione evento
    $dest_url = home_url('/eventi/');
    if (! empty($item->product_id)) {
        $perm = get_permalink($item->product_id);
        if ($perm) {
            $dest_url = $perm;
        }
    }

    // Integrazione trasparente con Google Analytics 4 (G-KGQF5BKEJY)
    $campaign_slug = ! empty($item->promo_title) ? sanitize_title($item->promo_title) : ('promo-' . $item->promotion_id);
    $dest_url = add_query_arg([
        'utm_source'   => 'dfn_newsletter',
        'utm_medium'   => 'email',
        'utm_campaign' => $campaign_slug,
        'utm_content'  => 'cta_button',
    ], $dest_url);

    return [
        'promotion_id' => (int) $item->promotion_id,
        'target_url'   => $dest_url,
    ];
}

/**
 * Attribuisce un ordine o una prenotazione completata alla campagna promozionale di provenienza.
 *
 * @param int $order_id ID dell'ordine WooCommerce
 * @return void
 */
function dfn_promotions_attribute_order(int $order_id): void
{
    if (! $order_id) {
        return;
    }

    // Evita rielaborazioni se già attribuito
    if (get_post_meta($order_id, '_dfn_promo_attributed', true)) {
        return;
    }

    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (! $order) {
        return;
    }

    global $wpdb;
    $table_queue    = $wpdb->prefix . 'dfn_promotion_queue';
    $table_promo    = $wpdb->prefix . 'dfn_promotions';
    $table_bookings = $wpdb->prefix . 'dfn_bookings';

    // 1. Trova l'evento acquistato in questo ordine
    $target_event_id = 0;
    $items = method_exists($order, 'get_items') ? $order->get_items() : [];
    if (is_iterable($items)) {
        foreach ($items as $item) {
            if (is_a($item, 'WC_Order_Item_Product')) {
                $evt = function_exists('dfn_db_get_event_by_product') ? dfn_db_get_event_by_product($item->get_product_id()) : null;
                if ($evt) {
                    $target_event_id = (int) $evt->id;
                    break;
                }
            }
        }
    }

    // Fallback: se l'ordine ha una prenotazione registrata nel database, recuperiamo l'event_id da lì
    if ($target_event_id === 0) {
        $booking_event_id = $wpdb->get_var($wpdb->prepare(
            "SELECT event_id FROM {$table_bookings} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order_id
        ));
        if ($booking_event_id) {
            $target_event_id = (int) $booking_event_id;
        }
    }

    $token = get_post_meta($order_id, '_dfn_promo_att_token', true);
    if (empty($token) && ! empty($_COOKIE['dfn_promo_att'])) {
        $token = sanitize_text_field($_COOKIE['dfn_promo_att']);
    }

    $billing_email = sanitize_email($order->get_billing_email());
    $queue_item = null;

    // Priorità 1: Se l'email di fatturazione dell'ordine corrisponde direttamente a un destinatario inviato
    // in questa campagna, attribuiamo direttamente a lui (risolve casi di PC/browser condivisi o link inoltrati).
    if (! empty($billing_email) && $target_event_id > 0) {
        $queue_item = $wpdb->get_row($wpdb->prepare(
            "SELECT q.*, p.event_id 
             FROM {$table_queue} q
             INNER JOIN {$table_promo} p ON p.id = q.promotion_id
             WHERE LOWER(q.recipient_email) = %s 
               AND p.event_id = %d 
               AND q.status = 'sent'
             ORDER BY q.id DESC LIMIT 1",
            strtolower($billing_email),
            $target_event_id
        ));
    }

    // Priorità 2: Attribuzione via Token da cookie se l'acquirente ha usato un'altra email
    // (es. acquisto con email aziendale o di un familiare dopo aver cliccato la newsletter)
    if (! $queue_item && ! empty($token)) {
        $queue_item = $wpdb->get_row($wpdb->prepare(
            "SELECT q.*, p.event_id 
             FROM {$table_queue} q
             INNER JOIN {$table_promo} p ON p.id = q.promotion_id
             WHERE q.tracking_token = %s",
            $token
        ));
    }

    if (! $queue_item) {
        return;
    }

    // Se l'ordine era per un altro evento, non attribuiamo a questa campagna specifica
    if ($target_event_id > 0 && (int) $queue_item->event_id !== $target_event_id) {
        return;
    }

    // Trova booking_id se presente
    $booking_id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_bookings} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
        $order_id
    ));

    $order_total = (float) $order->get_total();
    $now = current_time('mysql');

    // Aggiorna queue item: accumula il totale speso
    $is_first_conversion = empty($queue_item->converted_at);
    $prev_total = (float) ($queue_item->order_total ?? 0.0);
    $accumulated_total = $prev_total + $order_total;

    $wpdb->update($table_queue, [
        'converted_at' => $now,
        'order_id'     => $order_id,
        'booking_id'   => ($booking_id > 0) ? $booking_id : null,
        'order_total'  => $accumulated_total,
    ], ['id' => $queue_item->id]);

    // Aggiorna totali promozione: incrementa sempre il revenue generato, conteggio ordini univoci solo alla prima conversione
    if ($is_first_conversion) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_promo} 
             SET converted_count = converted_count + 1,
                 revenue_generated = revenue_generated + %f 
             WHERE id = %d",
            $order_total,
            $queue_item->promotion_id
        ));
    } else {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_promo} 
             SET revenue_generated = revenue_generated + %f 
             WHERE id = %d",
            $order_total,
            $queue_item->promotion_id
        ));
    }

    // Flag sull'ordine per evitare rielaborazioni
    update_post_meta($order_id, '_dfn_promo_attributed', 1);
    update_post_meta($order_id, '_dfn_promo_campaign_id', $queue_item->promotion_id);

    // Nota ordine per lo staff amministrativo
    if (is_callable([$order, 'add_order_note']) || method_exists($order, 'add_order_note')) {
        $order->add_order_note(sprintf(
            __('🎯 Conversione attribuita alla Campagna Promozionale #%d via Newsletter DFN.', 'dfn-theme'),
            $queue_item->promotion_id
        ));
    }
}


/**
 * ========================================================================
 * SEZIONE 21: Squadre & Team di Delegazione (Modulo 2.1.1)
 * ========================================================================
 */

/**
 * Recupera tutte le squadre registrate nel sistema.
 *
 * @param bool $only_active Se true restituisce solo i team attivi.
 * @return array<object>
 */
function dfn_get_all_teams(bool $only_active = false): array
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_teams';
    $table_members = $wpdb->prefix . 'dfn_team_members';

    $where = $only_active ? 'WHERE t.is_active = 1' : '';
    $sql = "SELECT t.*, COUNT(tm.id) as members_count 
            FROM {$table} t 
            LEFT JOIN {$table_members} tm ON t.id = tm.team_id 
            {$where} 
            GROUP BY t.id 
            ORDER BY t.order_num ASC, t.name ASC";

    $results = $wpdb->get_results($sql);
    return is_array($results) ? $results : [];
}

/**
 * Recupera un team specifico dal suo ID.
 *
 * @param int $team_id ID del team.
 * @return object|null
 */
function dfn_get_team(int $team_id): ?object
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_teams';
    $table_members = $wpdb->prefix . 'dfn_team_members';

    $sql = "SELECT t.*, COUNT(tm.id) as members_count 
            FROM {$table} t 
            LEFT JOIN {$table_members} tm ON t.id = tm.team_id 
            WHERE t.id = %d 
            GROUP BY t.id 
            LIMIT 1";

    $row = $wpdb->get_row($wpdb->prepare($sql, $team_id));
    return $row ?: null;
}

/**
 * Recupera un team dallo slug.
 *
 * @param string $slug Slug del team.
 * @return object|null
 */
function dfn_get_team_by_slug(string $slug): ?object
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_teams';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug));
    return $row ?: null;
}

/**
 * Salva o aggiorna un team.
 *
 * @param array<string, mixed> $data    Dati del team.
 * @param int|null             $team_id ID del team per aggiornamento, null per inserimento.
 * @return int|false ID del team o false in caso di errore.
 */
function dfn_save_team(array $data, ?int $team_id = null)
{
    global $wpdb;
    $table = $wpdb->prefix . 'dfn_teams';

    $name = sanitize_text_field($data['name'] ?? '');
    if (empty($name)) {
        return false;
    }

    $slug = sanitize_title($data['slug'] ?? $name);
    $description = sanitize_textarea_field($data['description'] ?? '');
    $icon = sanitize_text_field($data['icon'] ?? '👥');
    $color = sanitize_hex_color($data['color'] ?? '#004b23') ?: '#004b23';
    $badge_bg = sanitize_hex_color($data['badge_bg'] ?? '#f0fdf4') ?: '#f0fdf4';
    $supervisor_user_id = ! empty($data['supervisor_user_id']) ? (int) $data['supervisor_user_id'] : null;
    $is_active = isset($data['is_active']) ? (int) $data['is_active'] : 1;
    $order_num = isset($data['order_num']) ? (int) $data['order_num'] : 0;

    // Normalizza supervisor_roles come JSON string
    $supervisor_roles = $data['supervisor_roles'] ?? [];
    if (is_array($supervisor_roles)) {
        $supervisor_roles = json_encode(array_values(array_map('sanitize_key', $supervisor_roles)));
    } elseif (is_string($supervisor_roles)) {
        $decoded = json_decode($supervisor_roles, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $supervisor_roles = json_encode(array_values(array_map('sanitize_key', $decoded)));
        } else {
            $supervisor_roles = json_encode(array_values(array_filter(array_map('trim', explode(',', $supervisor_roles)))));
        }
    } else {
        $supervisor_roles = json_encode([]);
    }

    $whatsapp_url = ! empty($data['whatsapp_url']) ? esc_url_raw(trim($data['whatsapp_url'])) : null;
    $drive_url    = ! empty($data['drive_url']) ? esc_url_raw(trim($data['drive_url'])) : null;
    $reminder_cadence_mode = in_array($data['reminder_cadence_mode'] ?? 'inherit', ['inherit', 'custom'], true) ? $data['reminder_cadence_mode'] : 'inherit';
    $reminder_days = ! empty($data['reminder_days']) ? sanitize_text_field($data['reminder_days']) : null;

    $payload = [
        'name'                  => $name,
        'slug'                  => $slug,
        'description'           => $description,
        'icon'                  => $icon,
        'color'                 => $color,
        'badge_bg'              => $badge_bg,
        'whatsapp_url'          => $whatsapp_url,
        'drive_url'             => $drive_url,
        'supervisor_roles'      => $supervisor_roles,
        'supervisor_user_id'    => $supervisor_user_id,
        'is_active'             => $is_active,
        'reminder_cadence_mode' => $reminder_cadence_mode,
        'reminder_days'         => $reminder_days,
        'order_num'             => $order_num,
    ];

    if ($team_id && $team_id > 0) {
        $updated = $wpdb->update($table, $payload, ['id' => $team_id]);
        return ($updated !== false) ? $team_id : false;
    }

    $payload['created_at'] = current_time('mysql');
    $inserted = $wpdb->insert($table, $payload);
    return $inserted ? (int) $wpdb->insert_id : false;
}

/**
 * Elimina un team e rimuove le relative assegnazioni componenti.
 *
 * @param int $team_id ID del team.
 * @return bool
 */
function dfn_delete_team(int $team_id): bool
{
    global $wpdb;
    $table_teams   = $wpdb->prefix . 'dfn_teams';
    $table_members = $wpdb->prefix . 'dfn_team_members';

    $wpdb->delete($table_members, ['team_id' => $team_id], ['%d']);
    $deleted = $wpdb->delete($table_teams, ['id' => $team_id], ['%d']);

    return $deleted !== false;
}

/**
 * Recupera tutti i volontari componenti di un determinato team.
 *
 * @param int $team_id ID del team.
 * @return array<object>
 */
function dfn_get_team_members(int $team_id): array
{
    global $wpdb;
    $table_members = $wpdb->prefix . 'dfn_team_members';
    $table_fai     = $wpdb->prefix . 'dfn_fai_members';

    $sql = "SELECT tm.id as assignment_id, tm.joined_at, f.* 
            FROM {$table_members} tm 
            INNER JOIN {$table_fai} f ON tm.member_id = f.id 
            WHERE tm.team_id = %d 
            ORDER BY f.last_name ASC, f.first_name ASC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $team_id));
    return is_array($results) ? $results : [];
}

/**
 * Recupera tutte le squadre a cui è assegnato un determinato volontario (da member_id).
 *
 * @param int $member_id ID del membro in wp_dfn_fai_members.
 * @return array<object>
 */
function dfn_get_volunteer_teams(int $member_id): array
{
    global $wpdb;
    $table_members = $wpdb->prefix . 'dfn_team_members';
    $table_teams   = $wpdb->prefix . 'dfn_teams';

    $sql = "SELECT t.*, tm.joined_at 
            FROM {$table_teams} t 
            INNER JOIN {$table_members} tm ON t.id = tm.team_id 
            WHERE tm.member_id = %d 
            ORDER BY t.order_num ASC, t.name ASC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $member_id));
    return is_array($results) ? $results : [];
}

/**
 * Recupera tutte le squadre a cui appartiene un utente WordPress (da user_id).
 *
 * @param int $user_id ID utente WP.
 * @return array<object>
 */
function dfn_get_user_teams(int $user_id): array
{
    global $wpdb;
    $table_members = $wpdb->prefix . 'dfn_team_members';
    $table_teams   = $wpdb->prefix . 'dfn_teams';
    $table_fai     = $wpdb->prefix . 'dfn_fai_members';

    $sql = "SELECT DISTINCT t.*, tm.joined_at 
            FROM {$table_teams} t 
            INNER JOIN {$table_members} tm ON t.id = tm.team_id 
            LEFT JOIN {$table_fai} f ON tm.member_id = f.id 
            WHERE (tm.user_id = %d OR f.user_id = %d) 
            ORDER BY t.order_num ASC, t.name ASC";

    $results = $wpdb->get_results($wpdb->prepare($sql, $user_id, $user_id));
    return is_array($results) ? $results : [];
}

/**
 * Assegna un insieme di squadre a un volontario (sincronizzazione completa).
 *
 * @param int      $member_id ID membro anagrafica.
 * @param array<int> $team_ids Array di ID squadre.
 * @param int|null $user_id   ID utente WordPress opzionale.
 * @return void
 */
function dfn_set_volunteer_teams(int $member_id, array $team_ids, ?int $user_id = null): void
{
    global $wpdb;
    $table_members = $wpdb->prefix . 'dfn_team_members';
    $table_fai     = $wpdb->prefix . 'dfn_fai_members';

    if (! $user_id) {
        $user_id = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table_fai} WHERE id = %d", $member_id));
    }

    // Rimuovi assegnazioni attuali
    $wpdb->delete($table_members, ['member_id' => $member_id], ['%d']);

    foreach ($team_ids as $t_id) {
        $t_id = (int) $t_id;
        if ($t_id > 0) {
            $wpdb->insert($table_members, [
                'team_id'   => $t_id,
                'member_id' => $member_id,
                'user_id'   => ($user_id && $user_id > 0) ? $user_id : null,
                'joined_at' => current_time('mysql'),
            ], ['%d', '%d', '%d', '%s']);
        }
    }
}

/**
 * Aggiunge un singolo volontario a una squadra.
 *
 * @param int      $team_id   ID del team.
 * @param int      $member_id ID membro anagrafica.
 * @param int|null $user_id   ID utente WP opzionale.
 * @return bool
 */
function dfn_add_team_member(int $team_id, int $member_id, ?int $user_id = null): bool
{
    global $wpdb;
    $table_members = $wpdb->prefix . 'dfn_team_members';
    $table_fai     = $wpdb->prefix . 'dfn_fai_members';

    if (! $user_id) {
        $user_id = (int) $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table_fai} WHERE id = %d", $member_id));
    }

    // Verifica se già presente
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_members} WHERE team_id = %d AND member_id = %d", $team_id, $member_id));
    if ($exists) {
        return true;
    }

    $res = $wpdb->insert($table_members, [
        'team_id'   => $team_id,
        'member_id' => $member_id,
        'user_id'   => ($user_id && $user_id > 0) ? $user_id : null,
        'joined_at' => current_time('mysql'),
    ], ['%d', '%d', '%d', '%s']);

    return (bool) $res;
}

/**
 * Rimuove un volontario da una squadra.
 *
 * @param int $team_id   ID del team.
 * @param int $member_id ID membro anagrafica.
 * @return bool
 */
function dfn_remove_team_member(int $team_id, int $member_id): bool
{
    global $wpdb;
    $table_members = $wpdb->prefix . 'dfn_team_members';
    $res = $wpdb->delete($table_members, ['team_id' => $team_id, 'member_id' => $member_id], ['%d', '%d']);
    return $res !== false;
}

/**
 * Verifica se un determinato utente WordPress è responsabile/supervisore di una squadra.
 * Un utente è supervisore se:
 * - È amministratore di sistema (manage_options)
 * - È assegnato direttamente come supervisor_user_id nel team
 * - Possiede uno dei ruoli/deleghe abilitate in supervisor_roles per quel team
 *
 * @param int $user_id ID utente WP.
 * @param int $team_id ID del team.
 * @return bool
 */
function dfn_is_user_team_supervisor(int $user_id, int $team_id): bool
{
    if (! $user_id || ! $team_id) {
        return false;
    }

    // Amministratori di sistema hanno sempre supervisione
    if (user_can($user_id, 'manage_options')) {
        return true;
    }

    $team = dfn_get_team($team_id);
    if (! $team) {
        return false;
    }

    // Supervisore diretto assegnato per user ID
    if (! empty($team->supervisor_user_id) && (int) $team->supervisor_user_id === $user_id) {
        return true;
    }

    // Controllo dei ruoli/deleghe supervisore
    $sup_roles = [];
    if (! empty($team->supervisor_roles)) {
        $decoded = json_decode($team->supervisor_roles, true);
        if (is_array($decoded)) {
            $sup_roles = $decoded;
        }
    }

    if (empty($sup_roles)) {
        return false;
    }

    $user = get_userdata($user_id);
    if (! $user) {
        return false;
    }

    $user_roles = (array) $user->roles;
    $assigned_meta = (array) get_user_meta($user_id, '_dfn_assigned_fai_roles', true);
    $all_user_roles = array_unique(array_merge($user_roles, $assigned_meta));

    foreach ($sup_roles as $sr) {
        if (in_array($sr, $all_user_roles, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Recupera l'elenco degli utenti supervisori/delegati responsabili per una squadra.
 *
 * @param int $team_id ID del team.
 * @return array<WP_User>
 */
function dfn_get_team_supervisors(int $team_id): array
{
    $team = dfn_get_team($team_id);
    if (! $team) {
        return [];
    }

    $supervisors = [];
    $seen_uids = [];

    // 1. Supervisore diretto
    if (! empty($team->supervisor_user_id)) {
        $direct_u = get_userdata((int) $team->supervisor_user_id);
        if ($direct_u) {
            $supervisors[] = $direct_u;
            $seen_uids[$direct_u->ID] = true;
        }
    }

    // 2. Utenti con ruoli supervisore abilitati
    $sup_roles = [];
    if (! empty($team->supervisor_roles)) {
        $decoded = json_decode($team->supervisor_roles, true);
        if (is_array($decoded)) {
            $sup_roles = $decoded;
        }
    }

    if (! empty($sup_roles)) {
        foreach ($sup_roles as $r_slug) {
            $users_in_role = get_users(['role' => $r_slug]);
            foreach ($users_in_role as $ur) {
                if (! isset($seen_uids[$ur->ID])) {
                    $supervisors[] = $ur;
                    $seen_uids[$ur->ID] = true;
                }
            }
        }
    }

    return $supervisors;
}

/**
 * Recupera una mappa di tutte le squadre per ciascun membro [ member_id => [ team1, team2, ... ] ].
 * Ottimizzato per evitare query N+1 durante il rendering tabellare.
 *
 * @return array<int, array<object>>
 */
function dfn_get_all_volunteer_teams_map(): array
{
    global $wpdb;
    $table_teams   = $wpdb->prefix . 'dfn_teams';
    $table_members = $wpdb->prefix . 'dfn_team_members';

    $sql = "SELECT tm.member_id, t.* 
            FROM {$table_members} tm 
            INNER JOIN {$table_teams} t ON tm.team_id = t.id 
            WHERE t.is_active = 1 
            ORDER BY t.order_num ASC, t.name ASC";

    $rows = $wpdb->get_results($sql);
    $map = [];

    if (! empty($rows)) {
        foreach ($rows as $r) {
            $mid = (int) $r->member_id;
            if (! isset($map[$mid])) {
                $map[$mid] = [];
            }
            $map[$mid][] = $r;
        }
    }

    return $map;
}

/**
 * Recupera tutte le squadre di cui un determinato utente è supervisore/delegato responsabile.
 *
 * @param int $user_id ID utente WordPress.
 * @return array<object> Lista degli oggetti team supervisionati.
 */
function dfn_get_user_supervised_teams(int $user_id): array
{
    if (! $user_id) {
        return [];
    }

    $all_teams = function_exists('dfn_get_all_teams') ? dfn_get_all_teams(true) : [];
    $supervised = [];

    foreach ($all_teams as $t) {
        if (dfn_is_user_team_supervisor($user_id, (int) $t->id)) {
            $supervised[] = $t;
        }
    }

}

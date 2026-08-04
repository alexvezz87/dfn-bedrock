<?php

/**
 * DFN Theme - Functions
 * Architettura Modulare FAI Prenotazioni & CandleVibes Legacy
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ========================================================================
 * 1. NUOVO LOADER SISTEMA PRENOTAZIONI 2.0 (dfn_*)
 * Include tutti i moduli del sistema FAI Prenotazioni in ordine logico.
 * ========================================================================
 */

// Core e Database
require_once get_stylesheet_directory() . '/inc/core/dfn-database.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-setup.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-helpers.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-security.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-logger.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-notifications.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-cron.php';

// Integrazioni WooCommerce
require_once get_stylesheet_directory() . '/inc/woocommerce/dfn-gateway-in-loco.php';

// Frontend
require_once get_stylesheet_directory() . '/inc/frontend/dfn-checkout.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-fai-checkout.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-gdpr.php'; // GDPR: Privacy & Cookie Consent
require_once get_stylesheet_directory() . '/inc/frontend/dfn-shortcodes.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-myaccount.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-hub-biglietti.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-mobile-app.php';

// Admin / Gestione
require_once get_stylesheet_directory() . '/inc/admin/dfn-events-manager.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-settings.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-event-editor.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-slot-manager.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-scanner.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-volunteer-dashboard.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-report.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-fai-members-admin.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-waitlist.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-quick-booking.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-fai-pending-bookings.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-logs.php';

// API / Router
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-slots.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-bookings.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-slot-manager.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-scanner.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-fai-members.php';




/**
 * ========================================================================
 * 2. LOADER APPLICAZIONE LEGACY (cv_*)
 * Mantenuto attivo per compatibilità retroattiva con ordini ed eventi storici.
 * ========================================================================
 */

// Core Legacy
require_once get_stylesheet_directory() . '/inc/core/cv-setup.php';
require_once get_stylesheet_directory() . '/inc/core/cv-helpers.php';
require_once get_stylesheet_directory() . '/inc/core/cv-cron-tracking.php';

// API Legacy
require_once get_stylesheet_directory() . '/inc/api/cv-ajax-handlers.php';

// Admin Legacy
require_once get_stylesheet_directory() . '/inc/admin/cv-botteghino.php';
// require_once get_stylesheet_directory() . '/inc/admin/cv-report.php'; // Disabilitato in favore di dfn-report.php
// require_once get_stylesheet_directory() . '/inc/admin/cv-waitlist.php'; // Disabilitato in favore di dfn-waitlist.php
require_once get_stylesheet_directory() . '/inc/admin/cv-scanner.php';
require_once get_stylesheet_directory() . '/inc/admin/cv-accounting.php';
require_once get_stylesheet_directory() . '/inc/admin/cv-reviews.php';

// Frontend Legacy
require_once get_stylesheet_directory() . '/inc/frontend/cv-shortcodes.php';
// require_once get_stylesheet_directory() . '/inc/frontend/cv-myaccount.php'; // Disabilitato in favore di dfn-myaccount.php
// require_once get_stylesheet_directory() . '/inc/frontend/cv-hub-biglietti.php'; // Disabilitato in favore di dfn-hub-biglietti.php
require_once get_stylesheet_directory() . '/inc/frontend/cv-feedback.php';

<?php

/**
 * DFN Theme - Functions
 * Architettura Modulare FAI Prenotazioni & Gestione Volontari
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ========================================================================
 * 1. NUOVO LOADER SISTEMA PRENOTAZIONI 2.0 & 2.1 (dfn_*)
 * Include tutti i moduli del sistema FAI Prenotazioni in ordine logico.
 * ========================================================================
 */

// Core e Database
require_once get_stylesheet_directory() . '/inc/core/dfn-database.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-roles-manager.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-setup.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-helpers.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-security.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-logger.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-user-switch.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-notifications.php';
require_once get_stylesheet_directory() . '/inc/core/dfn-cron.php';

// Integrazioni WooCommerce
require_once get_stylesheet_directory() . '/inc/woocommerce/dfn-gateway-in-loco.php';

// Admin / Gestione
require_once get_stylesheet_directory() . '/inc/admin/dfn-roles-admin.php';
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
require_once get_stylesheet_directory() . '/inc/admin/dfn-volunteers-admin.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-volunteer-logistics-admin.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-botteghino.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-accounting.php';
require_once get_stylesheet_directory() . '/inc/admin/dfn-reviews-admin.php';

// Frontend
require_once get_stylesheet_directory() . '/inc/frontend/dfn-checkout.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-fai-checkout.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-gdpr.php'; // GDPR: Privacy & Cookie Consent
require_once get_stylesheet_directory() . '/inc/frontend/dfn-event-reviews.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-event-gallery.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-events-archive.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-shortcodes.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-myaccount.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-hub-biglietti.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-mobile-app.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-volunteer-survey.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-volunteer-registration.php';
require_once get_stylesheet_directory() . '/inc/frontend/dfn-feedback.php';

// API / Router
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-slots.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-bookings.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-slot-manager.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-scanner.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-fai-members.php';
require_once get_stylesheet_directory() . '/inc/api/dfn-ajax-botteghino.php';

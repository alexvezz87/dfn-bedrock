<?php
/**
 * DFN Theme - Functions
 * Architettura Modulare "CandleVibes App"
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * LOADER DELL'APPLICAZIONE
 * Include tutti i moduli del sistema in ordine logico.
 * ========================================================================
 */

// 1. CORE (Configurazioni, WooCommerce, Helpers)
require_once get_stylesheet_directory() . '/inc/core/cv-setup.php';
require_once get_stylesheet_directory() . '/inc/core/cv-helpers.php';
require_once get_stylesheet_directory() . '/inc/core/cv-cron-tracking.php';

// 2. API (Router centrale per tutte le richieste AJAX/Asincrone)
require_once get_stylesheet_directory() . '/inc/api/cv-ajax-handlers.php';

// 3. ADMIN (Botteghino, Tabellone Check-in, Mappa Tavoli, Liste d'Attesa, Scanner PWA)
require_once get_stylesheet_directory() . '/inc/admin/cv-botteghino.php';
require_once get_stylesheet_directory() . '/inc/admin/cv-report.php';
require_once get_stylesheet_directory() . '/inc/admin/cv-waitlist.php';
require_once get_stylesheet_directory() . '/inc/admin/cv-scanner.php';
require_once get_stylesheet_directory() . '/inc/admin/cv-accounting.php';
require_once get_stylesheet_directory() . '/inc/admin/cv-reviews.php';

// 4. FRONTEND (Shortcodes, My Account, Hub Biglietti)
require_once get_stylesheet_directory() . '/inc/frontend/cv-shortcodes.php';
require_once get_stylesheet_directory() . '/inc/frontend/cv-myaccount.php';
require_once get_stylesheet_directory() . '/inc/frontend/cv-hub-biglietti.php';
require_once get_stylesheet_directory() . '/inc/frontend/cv-feedback.php';
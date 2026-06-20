<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * ========================================================================
 * TRACCIAMENTO IN BACKGROUND (Legacy CandleVibes)
 *
 * ⚠️ DEPRECATION NOTICE (DFN 2.0):
 * Le funzionalità di cron (cv_forza_annullamento_ordini_scaduti) e di invio
 * email di scadenza (cv_email_cliente_ordine_scaduto) sono state RIMOSSE
 * perché duplicate dal sistema DFN 2.0 in dfn-cron.php.
 *
 * Il vecchio hook su `woocommerce_order_status_pending_to_cancelled` causava
 * l'invio di una DOPPIA email (una dal sistema CV legacy e una dal sistema DFN 2.0)
 * ogni volta che un ordine passava da "pending" a "cancelled".
 *
 * Rimane attivo solo il sensore di tracciamento click sul link di pagamento.
 * ========================================================================
 */

// Pulizia del cron legacy: rimuovi eventuali eventi orfani rimasti schedulati
add_action('init', 'cv_rimuovi_cron_legacy');
function cv_rimuovi_cron_legacy(): void
{
    $timestamp = wp_next_scheduled('cv_cron_annulla_ordini_scaduti');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'cv_cron_annulla_ordini_scaduti');
    }
}

// SENSORE DI TRACCIAMENTO CLICK SUL LINK DI PAGAMENTO
add_action('template_redirect', 'cv_track_payment_page_visit');
function cv_track_payment_page_visit()
{
    if (is_wc_endpoint_url('order-pay')) {
        global $wp;
        $order_id = absint($wp->query_vars['order-pay']);
        if ($order_id && isset($_GET['cv_track_pay'])) {
            $order = wc_get_order($order_id);
            if ($order) {
                $lock_key = 'cv_tracked_pay_' . $order_id;
                if (! get_transient($lock_key)) {
                    $order->add_order_note('👀 <strong>TRACCIAMENTO:</strong> Il cliente ha aperto la mail e ha cliccato sul link di pagamento.');
                    set_transient($lock_key, 1, 12 * HOUR_IN_SECONDS);
                }
            }
        }
    }
}

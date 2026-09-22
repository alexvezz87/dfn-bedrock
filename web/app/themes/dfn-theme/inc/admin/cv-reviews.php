<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ========================================================================
 * BACKEND: BACHECA RECENSIONI EVENTI
 * ========================================================================
 */

add_action('admin_menu', 'cv_aggiungi_pagina_recensioni');
function cv_aggiungi_pagina_recensioni()
{
    add_submenu_page(
        'dfn-events',
        'Recensioni Eventi',
        'Recensioni Eventi',
        'dfn_act_reviews',
        'cv-recensioni-eventi',
        'cv_render_pagina_recensioni',
    );
}

// AJAX: Modifica istantanea dello stato di pubblicazione frontend
add_action('wp_ajax_cv_toggle_review_published', 'cv_ajax_toggle_review_published');
function cv_ajax_toggle_review_published()
{
    if (! current_user_can('dfn_act_reviews') && ! current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permessi non sufficienti.']);
    }

    check_ajax_referer('cv_toggle_published_nonce', 'security');

    $order_id     = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $is_published = (isset($_POST['published']) && $_POST['published'] === 'yes') ? 'yes' : 'no';

    if ($order_id <= 0) {
        wp_send_json_error(['message' => 'ID Ordine non valido.']);
    }

    $order = wc_get_order($order_id);
    if (! $order) {
        wp_send_json_error(['message' => 'Ordine non trovato.']);
    }

    $order->update_meta_data('_cv_review_published_frontend', $is_published);
    $order->save();

    wp_send_json_success([
        'order_id'     => $order_id,
        'is_published' => ($is_published === 'yes'),
    ]);
}

function cv_render_pagina_recensioni()
{
    if (! current_user_can('dfn_act_reviews')) {
        return;
    }

    $selected_event = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

    global $wpdb;
    $table_events = $wpdb->prefix . 'dfn_events';
    $dfn_events = $wpdb->get_results("SELECT product_id, event_date_start FROM {$table_events} WHERE status != 'archived'");
    $event_dates_by_product = [];
    if (!empty($dfn_events)) {
        foreach ($dfn_events as $devt) {
            if (!empty($devt->product_id) && !empty($devt->event_date_start)) {
                $event_dates_by_product[$devt->product_id] = $devt->event_date_start;
            }
        }
    }

    $products = wc_get_products([ 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ]);

    $event_options = [];
    foreach ($products as $product) {
        $pid = $product->get_id();
        $date_raw = $event_dates_by_product[$pid] ?? null;
        if (!$date_raw && $product->get_date_created()) {
            $date_raw = $product->get_date_created()->date('Y-m-d');
        }

        $formatted_date = $date_raw ? date_i18n('d/m/Y', strtotime($date_raw)) : '';
        $label = $formatted_date ? $formatted_date . ' - ' . $product->get_name() : $product->get_name();

        $event_options[] = [
            'id'       => $pid,
            'label'    => $label,
            'date_raw' => $date_raw ?: '1970-01-01',
        ];
    }

    // Ordina per data decrescente (dal più recente al più vecchio)
    usort($event_options, function ($a, $b) {
        $cmp = strcmp($b['date_raw'], $a['date_raw']);
        if ($cmp === 0) {
            return strcasecmp($a['label'], $b['label']);
        }
        return $cmp;
    });

    echo '<div class="wrap"><h1>Recensioni e Feedback Eventi</h1>';
    echo '<p>Scopri cosa pensano i partecipanti dei tuoi eventi e leggi i loro suggerimenti.</p>';

    // --- LOGICA DI CANCELLAZIONE DELLA RECENSIONE ---
    if (isset($_POST['cv_delete_review_nonce']) && wp_verify_nonce($_POST['cv_delete_review_nonce'], 'cv_delete_review')) {
        $order_id_to_delete = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        if ($order_id_to_delete > 0) {
            $order_to_update = wc_get_order($order_id_to_delete);
            if ($order_to_update) {
                // Rimuoviamo i dati della recensione dall'ordine
                $order_to_update->delete_meta_data('_cv_event_rating');
                $order_to_update->delete_meta_data('_cv_event_review');
                $order_to_update->delete_meta_data('_cv_event_rating_date');
                $order_to_update->delete_meta_data('_cv_review_published_frontend');

                // Opzionale: Aggiungiamo una nota all'ordine per tracciabilità
                $current_user = wp_get_current_user();
                $order_to_update->add_order_note("🗑️ La recensione del cliente è stata eliminata manualmente dall'operatore: {$current_user->display_name}");

                $order_to_update->save();

                echo '<div class="notice notice-success is-dismissible"><p>✅ Recensione eliminata con successo. La media voti è stata ricalcolata.</p></div>';
            }
        }
    }

    // --- LOGICA DI SALVATAGGIO STATO PUBBLICAZIONE (BULK / FORM FALLBACK) ---
    if (isset($_POST['cv_save_published_nonce']) && wp_verify_nonce($_POST['cv_save_published_nonce'], 'cv_save_published')) {
        $published_map = isset($_POST['cv_published']) && is_array($_POST['cv_published']) ? $_POST['cv_published'] : [];
        $all_ids       = isset($_POST['cv_order_ids']) && is_array($_POST['cv_order_ids']) ? array_map('intval', $_POST['cv_order_ids']) : [];

        foreach ($all_ids as $oid) {
            $ord = wc_get_order($oid);
            if ($ord) {
                $val = isset($published_map[$oid]) ? 'yes' : 'no';
                $ord->update_meta_data('_cv_review_published_frontend', $val);
                $ord->save();
            }
        }
        echo '<div class="notice notice-success is-dismissible"><p>✅ Visibilità delle recensioni aggiornata con successo!</p></div>';
    }
    // ------------------------------------------------

    echo '<form method="GET" style="margin-bottom: 20px; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px; display:inline-block;">';
    echo '<input type="hidden" name="page" value="cv-recensioni-eventi">';
    echo '<select name="event_id" style="min-width:380px;"><option value="">-- Seleziona un Evento --</option>';
    foreach ($event_options as $opt) {
        echo '<option value="' . esc_attr($opt['id']) . '" ' . selected($selected_event, $opt['id'], false) . '>' . esc_html($opt['label']) . '</option>';
    }
    echo '</select> <button type="submit" class="button button-primary">Carica Recensioni</button></form>';

    if ($selected_event > 0) {
        // PERF-01: Filtriamo direttamente per product_id per non caricare tutti gli ordini
        $orders = wc_get_orders([
            'status' => [ 'wc-processing', 'wc-completed' ],
            'limit'  => -1,
            'product_id' => $selected_event,
        ]);

        $recensioni = [];
        $somma_voti = 0;
        $tot_voti = 0;

        foreach ($orders as $order) {

            // Verifichiamo manualmente che questo ordine contenga l'evento selezionato
            $has_event = false;
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() == $selected_event) {
                    $has_event = true;
                    break;
                }
            }

            // Se l'ordine non c'entra niente con l'evento, lo saltiamo
            if (! $has_event) {
                continue;
            }

            $rating = $order->get_meta('_cv_event_rating');
            if (! empty($rating)) {
                $review_text = wp_unslash($order->get_meta('_cv_event_review'));
                $review_date = $order->get_meta('_cv_event_rating_date');
                $is_pub_meta = $order->get_meta('_cv_review_published_frontend');
                // Se non ancora impostato nello storico, consideriamo pubblicata (default pregresso)
                $is_published = ($is_pub_meta !== 'no');

                if (! empty($review_date)) {
                    $data_mostrata = date_i18n('d/m/Y H:i', strtotime($review_date));
                    $timestamp = strtotime($review_date);
                } else {
                    $data_mostrata = $order->get_date_modified()->date_i18n('d/m/Y') . ' <span style="color:#aaa; font-size:11px;">(Stimata)</span>';
                    $timestamp = $order->get_date_modified()->getTimestamp();
                }

                $recensioni[] = [
                    'order_id'  => $order->get_id(),
                    'cliente'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'voto'      => intval($rating),
                    'testo'     => $review_text,
                    'data'      => $data_mostrata,
                    'timestamp' => $timestamp,
                    'published' => $is_published,
                ];

                $somma_voti += intval($rating);
                $tot_voti++;
            }
        }

        // Riordina l'array dalle recensioni più recenti a quelle più vecchie
        usort($recensioni, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        if ($tot_voti > 0) {
            $media = round($somma_voti / $tot_voti, 1);

            echo '<div style="background:#fff; border-left:4px solid #f59e0b; padding:20px; margin-bottom:20px; box-shadow:0 1px 1px rgba(0,0,0,.04); max-width: 400px; text-align:center;">';
            echo '<h3 style="margin:0; color:#555; text-transform:uppercase;">Valutazione Globale</h3>';
            echo '<div style="font-size:48px; font-weight:bold; color:#d97706; line-height:1;">' . $media . '<span style="font-size:24px; color:#ccc;">/5</span></div>';
            echo '<p style="margin:5px 0 0 0; color:#777;">Basata su <strong>' . $tot_voti . '</strong> recensioni rilasciate.</p>';
            echo '</div>';

            // Form nascosto per il salvataggio cumulativo con supporto HTML5 form attribute
            echo '<form id="cv-bulk-published-form" method="POST" action="" style="display:none;">';
            wp_nonce_field('cv_save_published', 'cv_save_published_nonce');
            echo '<input type="hidden" name="event_id" value="' . esc_attr($selected_event) . '">';
            echo '</form>';

            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th style="width:140px;">Data</th>';
            echo '<th style="width:180px;">Cliente</th>';
            echo '<th style="width:120px;">Voto</th>';
            echo '<th>Commento / Suggerimento</th>';
            echo '<th style="width:140px; text-align:center;">Pubblica sul Sito</th>';
            echo '<th style="width:70px; text-align:center;">Azioni</th>';
            echo '</tr></thead><tbody>';

            foreach ($recensioni as $rec) {
                $stelle_html = str_repeat('⭐', $rec['voto']) . str_repeat('☆', 5 - $rec['voto']);
                $has_text    = ! empty($rec['testo']);

                echo '<tr>';
                echo '<td style="vertical-align: middle;">' . $rec['data'] . '</td>';
                echo '<td style="vertical-align: middle;"><strong>' . esc_html($rec['cliente']) . '</strong></td>';
                echo '<td style="vertical-align: middle;"><span style="font-size:16px;">' . $stelle_html . '</span></td>';
                echo '<td style="vertical-align: middle;">' . esc_html($has_text ? $rec['testo'] : 'Nessun commento testuale rilasciato.') . '</td>';

                // Colonna selezione/cernita pubblicazione frontend
                echo '<td style="text-align:center; vertical-align: middle;">';
                if ($has_text) {
                    echo '<label class="dfn-admin-switch" title="' . esc_attr__('Spunta per pubblicare questa recensione sul sito', 'dfn-theme') . '">';
                    echo '<input type="checkbox" class="cv-toggle-published" data-order-id="' . esc_attr($rec['order_id']) . '" name="cv_published[' . esc_attr($rec['order_id']) . ']" form="cv-bulk-published-form" value="yes" ' . checked($rec['published'], true, false) . '>';
                    echo '<span class="dfn-admin-slider"></span>';
                    echo '</label>';
                    echo '<input type="hidden" name="cv_order_ids[]" form="cv-bulk-published-form" value="' . esc_attr($rec['order_id']) . '">';
                    echo '<div class="cv-pub-status" style="font-size:11px; margin-top:3px; font-weight:600; color:' . ($rec['published'] ? '#004b23' : '#64748b') . ';">';
                    echo $rec['published'] ? '✓ Pubblicata' : '✗ Nascosta';
                    echo '</div>';
                } else {
                    echo '<span style="color:#94a3b8; font-size:11px;"><em>Solo voto</em></span>';
                }
                echo '</td>';

                // Bottone di eliminazione con finestra di conferma
                echo '<td style="text-align:center; vertical-align: middle;">';
                echo '<form method="POST" action="" onsubmit="return confirm(\'Sei sicuro di voler eliminare definitivamente questa recensione?\');" style="margin:0;">';
                wp_nonce_field('cv_delete_review', 'cv_delete_review_nonce');
                echo '<input type="hidden" name="order_id" value="' . esc_attr($rec['order_id']) . '">';
                echo '<button type="submit" class="button" style="color:#d63638; border-color:#d63638; padding: 2px 8px; min-height: 0; line-height: 1.5;" title="Elimina Recensione">❌</button>';
                echo '</form>';
                echo '</td>';

                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<div style="margin-top:16px; display:flex; justify-content:space-between; align-items:center; background:#ffffff; padding:12px 16px; border:1px solid #ccd0d4; border-radius:4px;">';
            echo '<span style="font-size:13px; color:#475569;">💡 <em>Le modifiche alla spunta vengono salvate <strong>istantaneamente</strong> via AJAX, oppure puoi cliccare il pulsante qui a fianco.</em></span>';
            echo '<button type="submit" form="cv-bulk-published-form" class="button button-primary" style="background:#004b23; border-color:#004b23;">💾 Salva Tutte le Modifiche</button>';
            echo '</div>';
            ?>
            <style>
            .dfn-admin-switch {
              position: relative;
              display: inline-block;
              width: 44px;
              height: 24px;
            }
            .dfn-admin-switch input {
              opacity: 0;
              width: 0;
              height: 0;
            }
            .dfn-admin-slider {
              position: absolute;
              cursor: pointer;
              top: 0; left: 0; right: 0; bottom: 0;
              background-color: #cbd5e1;
              transition: .25s ease;
              border-radius: 24px;
            }
            .dfn-admin-slider:before {
              position: absolute;
              content: "";
              height: 18px;
              width: 18px;
              left: 3px;
              bottom: 3px;
              background-color: white;
              transition: .25s ease;
              border-radius: 50%;
              box-shadow: 0 1px 3px rgba(0,0,0,0.25);
            }
            .dfn-admin-switch input:checked + .dfn-admin-slider {
              background-color: #004b23;
            }
            .dfn-admin-switch input:checked + .dfn-admin-slider:before {
              transform: translateX(20px);
            }
            </style>
            <script>
            jQuery(document).ready(function($) {
                $('.cv-toggle-published').on('change', function() {
                    var $checkbox = $(this);
                    var orderId = $checkbox.data('order-id');
                    var isChecked = $checkbox.is(':checked');
                    var $status = $checkbox.closest('td').find('.cv-pub-status');

                    $status.html('<span class="spinner is-active" style="float:none; margin:0 4px 0 0; vertical-align:middle; width:12px; height:12px;"></span> Salvataggio...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'cv_toggle_review_published',
                            security: '<?php echo wp_create_nonce("cv_toggle_published_nonce"); ?>',
                            order_id: orderId,
                            published: isChecked ? 'yes' : 'no'
                        },
                        success: function(resp) {
                            if (resp && resp.success) {
                                if (isChecked) {
                                    $status.css('color', '#004b23').html('✓ Pubblicata');
                                } else {
                                    $status.css('color', '#64748b').html('✗ Nascosta');
                                }
                            } else {
                                alert('Errore: ' + (resp.data ? resp.data.message : 'Impossibile aggiornare.'));
                                $checkbox.prop('checked', !isChecked);
                            }
                        },
                        error: function() {
                            alert('Errore di connessione durante il salvataggio.');
                            $checkbox.prop('checked', !isChecked);
                        }
                    });
                });
            });
            </script>
            <?php
        } else {
            echo '<div class="notice notice-info"><p>Nessuna recensione ricevuta per questo evento al momento.</p></div>';
        }
    }
    echo '</div>';
}

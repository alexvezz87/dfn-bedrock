<?php
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * ========================================================================
 * BILANCIO EVENTI: CONSUNTIVI INGRESSI, INCASSI E GRAFICI
 * ========================================================================
 */

add_action( 'admin_menu', 'cv_aggiungi_pagina_bilancio' );
function cv_aggiungi_pagina_bilancio() {
    $hook = add_submenu_page(
        'woocommerce',
        'Bilancio Eventi',
        '📊 Bilancio Eventi',
        'manage_woocommerce',
        'cv-bilancio-eventi',
        'cv_render_pagina_bilancio'
    );

    // Carichiamo la libreria per i grafici (Chart.js) solo in questa pagina
    add_action( "admin_enqueue_scripts", 'cv_enqueue_accounting_assets' );
}

function cv_enqueue_accounting_assets( $hook ) {
    if ( $hook !== 'woocommerce_page_cv-bilancio-eventi' ) return;
    // Includiamo Chart.js via CDN in modo asincrono
    wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true );
}

function cv_render_pagina_bilancio() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) return;

    $selected_event = isset( $_GET['event_id'] ) ? intval( $_GET['event_id'] ) : 0;
    $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );

    // Fallback di emergenza
    $stripe_percent_fallback = 0.014; 
    $stripe_fisso_fallback   = 0.25;  

    echo '<div class="wrap"><h1>Analisi e Bilancio Eventi</h1>';
    echo '<p>Riepilogo finanziario, andamento temporale e registro dettagliato.</p>';

    echo '<form method="GET" style="margin-bottom: 20px; background:#fff; padding:15px; border:1px solid #ccd0d4; border-radius:4px; display:inline-block;">';
    echo '<input type="hidden" name="page" value="cv-bilancio-eventi">';
    echo '<select name="event_id" style="min-width:300px;"><option value="">-- Seleziona un Evento --</option>';
    foreach ( $products as $product ) {
        echo '<option value="' . esc_attr( $product->get_id() ) . '" ' . selected( $selected_event, $product->get_id(), false ) . '>' . esc_html( $product->get_name() ) . '</option>';
    }
    echo '</select> <button type="submit" class="button button-primary">Genera Consuntivo</button></form>';

    if ( $selected_event > 0 ) {
        $orders = wc_get_orders( array( 
            'status' => array( 'wc-processing', 'wc-completed' ), 
            'limit' => -1, 
            'product_id' => $selected_event 
        ) );

        $stats = array(
            'ticket_venduti' => 0,
            'ticket_entrati' => 0,
            'incasso_lordo_totale' => 0,
            'incasso_contanti' => 0,
            'incasso_digitale' => 0,
            'commissioni_reali' => 0,
            'n_ordini_digitali' => 0
        );

        $transazioni_dettaglio = array();
        
        // Array per raccogliere le date per il grafico
        $vendite_nel_tempo = array();

        foreach ( $orders as $order ) {
            $qty = 0;
            foreach ( $order->get_items() as $item ) {
                if ( $item->get_product_id() == $selected_event ) {
                    $qty += $item->get_quantity();
                }
            }
            
            if ($qty === 0) continue;

            $stats['ticket_venduti'] += $qty;
            
            // RACCOLTA DATI PER IL GRAFICO (Data Y-m-d per poterle ordinare correttamente)
            // FATAL-04: Null-check su get_date_created() per ordini importati/corrotti
            $date_created = $order->get_date_created();
            $data_ordine  = $date_created ? $date_created->date( 'Y-m-d' ) : current_time( 'Y-m-d' );
            if ( ! isset( $vendite_nel_tempo[ $data_ordine ] ) ) {
                $vendite_nel_tempo[ $data_ordine ] = 0;
            }
            $vendite_nel_tempo[$data_ordine] += $qty;
            
            for ( $i = 1; $i <= $qty; $i++ ) {
                if ( $order->get_meta( '_cv_ticket_validato_' . $i ) === 'yes' ) {
                    $stats['ticket_entrati']++;
                }
            }

            $totale_ordine = $order->get_total();
            $stats['incasso_lordo_totale'] += $totale_ordine;
            
            $metodo_pagamento = $order->get_payment_method_title();
            
            if ( empty( trim( $metodo_pagamento ) ) ) {
                $metodo_pagamento = 'Contanti (Vecchio Sistema)';
            }
            
            $metodo_lower = strtolower($metodo_pagamento);
            $fee_applicata = 0;
            $is_fallback = false;

            if ( strpos($metodo_lower, 'contanti') !== false || strpos($metodo_lower, 'autorità') !== false ) {
                $stats['incasso_contanti'] += $totale_ordine;
            } else {
                $stats['incasso_digitale'] += $totale_ordine;
                $stats['n_ordini_digitali']++;

                $stripe_fee = $order->get_meta('_stripe_fee'); 
                
                if ( $stripe_fee !== '' ) {
                    $fee_applicata = floatval($stripe_fee);
                    $stats['commissioni_reali'] += $fee_applicata;
                } else {
                    $is_fallback = true;
                    if ($totale_ordine > 0) {
                        $fee_applicata = ($totale_ordine * $stripe_percent_fallback) + $stripe_fisso_fallback;
                        $stats['commissioni_reali'] += $fee_applicata;
                    }
                }
            }

            $transazioni_dettaglio[] = array(
                'order_id' => $order->get_id(),
                'data'     => $date_created ? $date_created->date_i18n('d/m/Y H:i') : 'N/A',
                'cliente'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'metodo'   => $metodo_pagamento,
                'qty'      => $qty,
                'lordo'    => $totale_ordine,
                'fee'      => $fee_applicata,
                'netto'    => $totale_ordine - $fee_applicata,
                'fallback' => $is_fallback
            );
        }

        $netto_totale = $stats['incasso_lordo_totale'] - $stats['commissioni_reali'];

        // PREPARAZIONE DATI PER IL GRAFICO
        ksort($vendite_nel_tempo); // Ordina l'array cronologicamente
        $chart_labels = array();
        $chart_data = array();
        foreach ( $vendite_nel_tempo as $data_raw => $totale_biglietti ) {
            $chart_labels[] = date_i18n('d M', strtotime($data_raw)); // Es. "24 Mar"
            $chart_data[] = $totale_biglietti;
        }

        ?>
        <style>
            .cv-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .cv-stat-box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
            .cv-stat-box h3 { margin: 0 0 10px 0; font-size: 14px; color: #646970; text-transform: uppercase; }
            .cv-stat-box .value { font-size: 28px; font-weight: bold; color: #1d2327; }
            .cv-stat-box.highlight { border-left: 4px solid #16a34a; }
            .cv-stat-box.warning { border-left: 4px solid #d63638; }
            
            .cv-chart-container { background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #ccd0d4; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 30px; }
            .cv-chart-container h2 { margin-top: 0; color: #1d2327; font-size: 18px; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
            
            .cv-pay-label { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; white-space: nowrap; }
            .cv-pay-contanti { background-color: #eaf7ea; color: #16a34a; border: 1px solid #c3e6c3; }
            .cv-pay-stripe { background-color: #f3e8ff; color: #6b21a8; border: 1px solid #d8b4fe; }
            .cv-pay-klarna { background-color: #ffe4e6; color: #b91c1c; border: 1px solid #fecaca; }
            .cv-pay-apple { background-color: #f3f4f6; color: #1f2937; border: 1px solid #d1d5db; }
        </style>

        <div class="cv-stats-grid">
            <div class="cv-stat-box">
                <h3>Affluenza Reale</h3>
                <div class="value"><?php echo $stats['ticket_entrati']; ?> <small style="font-weight:normal; font-size:16px;">su <?php echo $stats['ticket_venduti']; ?></small></div>
                <p><?php echo ($stats['ticket_venduti'] > 0) ? round(($stats['ticket_entrati'] / $stats['ticket_venduti']) * 100, 1) : 0; ?>% presenti.</p>
            </div>
            <div class="cv-stat-box">
                <h3>Incasso Lordo</h3>
                <div class="value"><?php echo wc_price($stats['incasso_lordo_totale']); ?></div>
            </div>
            <div class="cv-stat-box warning">
                <h3>Commissioni Totali</h3>
                <div class="value">- <?php echo wc_price($stats['commissioni_reali']); ?></div>
                <p>Estratte dai dati reali di transazione.</p>
            </div>
            <div class="cv-stat-box highlight">
                <h3>Utile Netto Reale</h3>
                <div class="value"><?php echo wc_price($netto_totale); ?></div>
                <p>Incasso lordo meno trattenute.</p>
            </div>
        </div>

        <div class="cv-chart-container">
            <h2>📈 Curva delle Vendite nel Tempo</h2>
            <div style="position: relative; height: 300px; width: 100%;">
                <canvas id="cvSalesChart"></canvas>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var ctx = document.getElementById('cvSalesChart').getContext('2d');
                var chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($chart_labels); ?>,
                        datasets: [{
                            label: 'Biglietti Venduti',
                            data: <?php echo json_encode($chart_data); ?>,
                            backgroundColor: 'rgba(34, 113, 177, 0.15)', // Azzurro WordPress traslucido
                            borderColor: '#2271b1', // Azzurro WordPress
                            borderWidth: 3,
                            fill: true, // Crea l'effetto "Area" sotto la linea
                            tension: 0.3, // Smussa le curve della linea
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: '#2271b1',
                            pointRadius: 5,
                            pointHoverRadius: 7
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                padding: 12,
                                titleFont: { size: 14 },
                                bodyFont: { size: 14, weight: 'bold' },
                                displayColors: false,
                                callbacks: {
                                    label: function(context) {
                                        return context.parsed.y + ' biglietti venduti';
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { stepSize: 1, precision: 0 },
                                grid: { color: '#f0f0f1' }
                            },
                            x: {
                                grid: { display: false }
                            }
                        }
                    }
                });
            });
        </script>
        <h2 class="wp-heading-inline" style="margin-top: 20px; margin-bottom: 15px;">Registro Dettagliato Transazioni</h2>
        <p style="color:#555;">Elenco completo di tutti gli ordini con il calcolo esatto delle commissioni prelevate per ogni singolo metodo di pagamento.</p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 80px;">Ordine</th>
                    <th style="width: 140px;">Data</th>
                    <th>Cliente</th>
                    <th>Metodo di Pagamento</th>
                    <th style="width: 80px; text-align:center;">Q.tà</th>
                    <th style="text-align:right;">Lordo</th>
                    <th style="text-align:right;">Commissione</th>
                    <th style="text-align:right;">Netto Incassato</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ( empty($transazioni_dettaglio) ) {
                    echo '<tr><td colspan="8" style="text-align:center; padding:20px;">Nessuna transazione trovata per questo evento.</td></tr>';
                } else {
                    foreach ( $transazioni_dettaglio as $tx ) {
                        $metodo_titolo = esc_html($tx['metodo']);
                        $metodo_lower = strtolower($tx['metodo']);
                        
                        if ( strpos($metodo_lower, 'contanti') !== false || strpos($metodo_lower, 'autorità') !== false ) {
                            $badge_class = 'cv-pay-contanti';
                            $metodo_icon = '💵 ';
                        } elseif ( strpos($metodo_lower, 'klarna') !== false ) {
                            $badge_class = 'cv-pay-klarna';
                            $metodo_icon = '🛍️ ';
                        } elseif ( strpos($metodo_lower, 'apple') !== false || strpos($metodo_lower, 'google') !== false ) {
                            $badge_class = 'cv-pay-apple';
                            $metodo_icon = '📱 ';
                        } else {
                            $badge_class = 'cv-pay-stripe';
                            $metodo_icon = '💳 ';
                        }

                        $label_metodo = '<span class="cv-pay-label ' . $badge_class . '">' . $metodo_icon . $metodo_titolo . '</span>';
                        $warning_icon = $tx['fallback'] ? ' <span title="Dato Stripe mancante: calcolo stimato" style="cursor:help;">⚠️</span>' : '';
                        $colore_fee = ($tx['fee'] > 0) ? 'color: #d63638;' : 'color: #aaa;';

                        $edit_url = admin_url('post.php?post=' . $tx['order_id'] . '&action=edit');

                        echo '<tr>';
                        echo '<td><a href="' . esc_url($edit_url) . '"><strong>#' . $tx['order_id'] . '</strong></a></td>';
                        echo '<td>' . $tx['data'] . '</td>';
                        echo '<td>' . esc_html($tx['cliente']) . '</td>';
                        echo '<td>' . $label_metodo . '</td>';
                        echo '<td style="text-align:center;"><strong>' . $tx['qty'] . '</strong></td>';
                        echo '<td style="text-align:right;">' . wc_price($tx['lordo']) . '</td>';
                        echo '<td style="text-align:right; ' . $colore_fee . '">' . ($tx['fee'] > 0 ? '-' : '') . wc_price($tx['fee']) . $warning_icon . '</td>';
                        echo '<td style="text-align:right;"><strong>' . wc_price($tx['netto']) . '</strong></td>';
                        echo '</tr>';
                    }
                }
                ?>
            </tbody>
            <tfoot>
                <tr style="background:#f0f6fc;">
                    <th colspan="4" style="text-align:right; font-size:14px;"><strong>TOTALI:</strong></th>
                    <th style="text-align:center; font-size:14px;"><strong><?php echo $stats['ticket_venduti']; ?></strong></th>
                    <th style="text-align:right; font-size:14px; color:#1d2327;"><strong><?php echo wc_price($stats['incasso_lordo_totale']); ?></strong></th>
                    <th style="text-align:right; font-size:14px; color:#d63638;"><strong>- <?php echo wc_price($stats['commissioni_reali']); ?></strong></th>
                    <th style="text-align:right; font-size:16px; color:#16a34a;"><strong><?php echo wc_price($netto_totale); ?></strong></th>
                </tr>
            </tfoot>
        </table>
        <?php
    }
    echo '</div>';
}
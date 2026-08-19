<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 9. BOTTEGHINO LIVE — Interfaccia POS per i volontari in sede
 *
 * Riscrittura completa con integrazione del sistema DFN 2.0:
 * - Cascata Evento → Data → Turno (Auto/Manuale)
 * - 4 modalità: Contanti, Link Pagamento, Autorità, Solo Prenotazione
 * - Tessere FAI dinamiche
 * - Allocazione via dfn_allocate_slots_on_checkout()
 *
 * @package DFN_Theme
 * @since   2.2.0
 */

add_action('admin_menu', 'cv_aggiungi_generatore_fai');
function cv_aggiungi_generatore_fai()
{
    $hook = add_submenu_page(
        'dfn-events',
        'Botteghino Live',
        'Botteghino Live',
        'dfn_act_boxoffice',
        'cv-generatore-fai',
        'cv_render_generatore_fai',
    );

    // Inietta gli script e gli stili personalizzati solo in questa pagina
    add_action("admin_enqueue_scripts", 'cv_enqueue_botteghino_assets');
}

function cv_enqueue_botteghino_assets($hook)
{
    if (strpos($hook, 'cv-generatore-fai') === false) {
        return;
    }

    // Dipendenze Select2 native di WooCommerce
    wp_enqueue_script('selectWoo');
    wp_enqueue_style('select2');

    // I nostri file separati con cache buster
    wp_enqueue_style('cv-botteghino-css', get_stylesheet_directory_uri() . '/assets/css/cv-botteghino.css', [], time());
    wp_enqueue_script('cv-botteghino-js', get_stylesheet_directory_uri() . '/assets/js/cv-botteghino.js', ['jquery', 'selectWoo'], time(), true);

    // Passiamo le variabili PHP al JS in modo sicuro
    wp_localize_script('cv-botteghino-js', 'cvBotteghinoVars', [
        'ajaxurl'   => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('dfn_admin_events_nonce'),
        'cust_nonce'=> wp_create_nonce('cv_ricerca_clienti_nonce'),
    ]);
}

// INTERFACCIA E LOGICA DI SALVATAGGIO
function cv_render_generatore_fai()
{
    if (! current_user_can('manage_woocommerce')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">🎟️ Botteghino Live</h1>
        <p style="font-size:16px; color:#555;">Gestisci le prenotazioni in sede: incassa in contanti, invia link di pagamento, riserva posti per le autorità o registra una prenotazione.</p>

        <!-- Messaggi feedback -->
        <div id="cv-bott-feedback" style="display:none;"></div>

        <div class="cv-fai-container">

            <!-- ===== SEZIONE 1: Evento / Data / Turno ===== -->
            <div class="cv-bott-section">
                <div class="cv-bott-section-label">
                    <span class="cv-bott-step-num">1</span>
                    Evento e Turno
                </div>

                <div class="cv-form-row">
                    <label for="cv-bott-event">Evento *</label>
                    <select id="cv-bott-event" required>
                        <option value="">— Caricamento eventi… —</option>
                    </select>
                </div>

                <div class="cv-form-row" id="cv-bott-date-wrap" style="display:none;">
                    <label for="cv-bott-date">Data *</label>
                    <select id="cv-bott-date" required>
                        <option value="">— Seleziona prima un evento —</option>
                    </select>
                </div>

                <div class="cv-form-row" id="cv-bott-slot-wrap" style="display:none;">
                    <label for="cv-bott-slot">Turno</label>
                    <select id="cv-bott-slot">
                        <option value="0">🤖 Auto — Smistamento automatico</option>
                    </select>
                    <p class="description">Se lasci "Auto", il sistema assegnerà automaticamente il turno con più disponibilità.</p>
                </div>
            </div>

            <!-- ===== SEZIONE 2: Dati Cliente ===== -->
            <div class="cv-bott-section" id="cv-bott-guest-section" style="display:none;">
                <div class="cv-bott-section-label">
                    <span class="cv-bott-step-num">2</span>
                    Dati prenotante
                </div>

                <div class="cv-form-row" style="background:#f0f6fc; padding:10px 12px; border-radius:6px; border:1px solid #c8d7e1; margin-bottom:14px;">
                    <label for="fai_customer_search">🔍 Cerca Cliente Esistente <span style="color:#666; font-weight:normal;">(opzionale)</span></label>
                    <select id="fai_customer_search" name="fai_customer_id" data-placeholder="Digita nome o email..."><option value=""></option></select>
                </div>

                <div style="display: flex; gap: 15px;">
                    <div class="cv-form-row" style="flex: 1;">
                        <label for="fai_nome">Nome <span style="color:#999; font-weight:normal;">(opzionale)</span></label>
                        <input type="text" id="fai_nome" placeholder="Es. Mario">
                    </div>
                    <div class="cv-form-row" style="flex: 1;">
                        <label for="fai_cognome">Cognome *</label>
                        <input type="text" id="fai_cognome" placeholder="Es. Rossi" required>
                    </div>
                </div>

                <div style="display: flex; gap: 15px;">
                    <div class="cv-form-row" style="flex: 1;">
                        <label for="fai_email" style="display:flex; justify-content:space-between;">
                            <span>Email</span>
                            <a href="#" id="cv-btn-no-email" style="font-size:11px; color:#d63638; text-decoration:none; border-bottom:1px dashed #d63638;">No email?</a>
                        </label>
                        <input type="email" id="fai_email" placeholder="mario.rossi@email.it">
                    </div>
                    <div class="cv-form-row" style="flex: 1;">
                        <label for="fai_telefono">Telefono</label>
                        <input type="tel" id="fai_telefono" placeholder="Es. 3331234567">
                    </div>
                </div>
            </div>

            <!-- ===== SEZIONE 4: Quantità e FAI ===== -->
            <div class="cv-bott-section" id="cv-bott-qty-section" style="display:none;">
                <div class="cv-bott-section-label">
                    <span class="cv-bott-step-num">3</span>
                    Biglietti
                </div>

                <div style="display: flex; gap: 15px; align-items: flex-start;">
                    <div class="cv-form-row" style="flex: 1;">
                        <label for="fai_qty_std">Posti standard *</label>
                        <input type="number" id="fai_qty_std" value="1" min="0" max="50" step="1" style="font-size:20px; font-weight:bold;">
                    </div>
                    <div class="cv-form-row" style="flex: 1;">
                        <label for="fai_qty_fai">Soci FAI</label>
                        <input type="number" id="fai_qty_fai" value="0" min="0" max="50" step="1" style="font-size:20px;">
                    </div>
                </div>

                <!-- Tessere FAI dinamiche -->
                <div id="cv-bott-fai-cards-wrap" style="display:none;">
                    <div class="cv-bott-fai-header">
                        <span>🏅</span> Dati tessere Soci FAI
                    </div>
                    <div id="cv-bott-fai-cards-list"></div>
                </div>

                <div class="cv-form-row">
                    <label for="fai_notes">Note <span style="color:#999; font-weight:normal;">(opzionale)</span></label>
                    <textarea id="fai_notes" rows="2" placeholder="Richieste particolari, accessibilità, ecc." style="width:100%; padding:8px 12px; border:1px solid #8c8f94; border-radius:4px;"></textarea>
                </div>
            </div>

            <!-- ===== SEZIONE 5: Check-in ===== -->
            <div class="cv-form-row" id="cv-auto-checkin-wrapper" style="display:none; background:#eaf7ea; padding:15px; border-radius:6px; border:1px solid #c3e6c3;">
                <label style="margin:0; display:flex; align-items:center; cursor:pointer; color:#166534; font-size:15px;">
                    <input type="checkbox" id="fai_auto_checkin" value="1" style="margin-right:10px; width:20px; height:20px;">
                    ✅ Salta fila: Valida automaticamente i biglietti
                </label>
                <p class="description" style="margin-left:30px; color:#166534;">Spunta questa casella <strong>SOLO</strong> se il cliente entra in questo esatto momento (es. gli dai il braccialetto cartaceo).</p>
            </div>

            <!-- ===== SEZIONE 6: Pagamento & Conferma ===== -->
            <div id="cv-bott-buttons" style="display:none; margin-top:20px; padding-top:15px; border-top:1px solid #e8e8e8;">
                <div class="cv-form-row">
                    <label for="cv-bott-payment-method">Metodo di Pagamento / Modalità *</label>
                    <select id="cv-bott-payment-method" style="width:100%; font-weight:600;">
                        <option value="contanti">💵 Incassa in Contanti ed Emetti Biglietti</option>
                        <option value="link">💳 Invia Link di Pagamento (Carta)</option>
                        <option value="prenotazione">📋 Solo Prenotazione (Paga all'arrivo)</option>
                        <option value="autorita">🎁 Riserva Posti Autorità (Omaggio)</option>
                    </select>
                </div>

                <button type="button" id="cv-btn-submit-botteghino" class="button button-primary button-hero" style="width:100%; margin-top:10px; font-size:16px; height:auto; padding:10px 16px;">
                    💶 Emetti Biglietto & Registra Operazione
                </button>
            </div>

        </div><!-- /.cv-fai-container -->
    </div><!-- /.wrap -->
    <?php
}

<?php
/**
 * DFN Booking System 2.0 — Quick Booking (Inserimento Rapido Segreteria)
 *
 * Pagina admin mobile-first per l'inserimento rapido delle prenotazioni da parte
 * della segreteria. Accessibile come icona/bookmark sul telefono della segretaria.
 *
 * Accesso: ruolo dfn_segretaria (dfn_quick_booking) o admin/shop_manager.
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renderizza la pagina di inserimento rapido prenotazioni.
 */
function dfn_render_quick_booking(): void
{
    if (! current_user_can('dfn_quick_booking') && ! current_user_can('dfn_manage_events') && ! current_user_can('manage_options')) {
        wp_die(esc_html__('Non hai i permessi necessari per accedere a questa pagina.', 'dfn-theme'));
    }
    ?>
    <div class="dfn-qb-wrap" id="dfn-quick-booking-page">

        <!-- Header -->
        <div class="dfn-qb-header">
            <div class="dfn-qb-logo">
                <span class="dfn-qb-logo-icon">🏛️</span>
                <div>
                    <span class="dfn-qb-logo-title">FAI Prenotazioni</span>
                    <span class="dfn-qb-logo-sub">Inserimento Rapido</span>
                </div>
            </div>
        </div>

        <!-- Success Banner (nascosto di default) -->
        <div class="dfn-qb-success" id="dfn-qb-success" style="display:none;">
            <div class="dfn-qb-success-icon">✅</div>
            <div class="dfn-qb-success-title" id="dfn-qb-success-title">Prenotazione confermata!</div>
            <div class="dfn-qb-success-detail" id="dfn-qb-success-detail"></div>
            <div class="dfn-qb-success-msg-wrap" id="dfn-qb-success-msg-wrap" style="display:none;">
                <label class="dfn-qb-msg-label">Messaggio di conferma da copiare:</label>
                <div class="dfn-qb-msg-box" id="dfn-qb-success-msg"></div>
                <button type="button" class="dfn-qb-btn dfn-qb-btn-copy" id="dfn-qb-copy-btn">
                    📋 Copia messaggio
                </button>
            </div>
            <div class="dfn-qb-success-email-note" id="dfn-qb-email-note" style="display:none;">
                <span>📧</span> Email di conferma inviata automaticamente al prenotante.
            </div>
            <button type="button" class="dfn-qb-btn dfn-qb-btn-new" id="dfn-qb-new-btn">
                ➕ Nuova prenotazione
            </button>
        </div>

        <!-- Form -->
        <div id="dfn-qb-form-wrap">
            <form id="dfn-qb-form" novalidate>

                <!-- ===== SEZIONE 1: Evento ===== -->
                <div class="dfn-qb-section">
                    <div class="dfn-qb-section-label">
                        <span class="dfn-qb-step-num">1</span>
                        Evento
                    </div>

                    <div class="dfn-qb-field">
                        <label class="dfn-qb-label" for="qb-event">Seleziona l'evento *</label>
                        <select class="dfn-qb-select" id="qb-event" name="event_id" required>
                            <option value="">— Caricamento eventi… —</option>
                        </select>
                    </div>

                    <div class="dfn-qb-field" id="qb-date-wrap" style="display:none;">
                        <label class="dfn-qb-label" for="qb-date">Data *</label>
                        <select class="dfn-qb-select" id="qb-date" name="date" required>
                            <option value="">— Seleziona prima un evento —</option>
                        </select>
                    </div>
                </div>

                <!-- ===== SEZIONE 2: Turno ===== -->
                <div class="dfn-qb-section" id="qb-slot-section" style="display:none;">
                    <div class="dfn-qb-section-label">
                        <span class="dfn-qb-step-num">2</span>
                        Turno <span class="dfn-qb-optional">(opzionale)</span>
                    </div>

                    <div class="dfn-qb-field">
                        <label class="dfn-qb-label" for="qb-slot">Seleziona il turno</label>
                        <select class="dfn-qb-select" id="qb-slot" name="slot_id">
                            <option value="0">🤖 Auto — Smistamento automatico</option>
                        </select>
                        <p class="dfn-qb-hint">Se non selezioni un turno, il sistema assegnerà automaticamente quello con più disponibilità.</p>
                    </div>
                </div>

                <!-- ===== SEZIONE 3: Dati Prenotante ===== -->
                <div class="dfn-qb-section" id="qb-guest-section" style="display:none;">
                    <div class="dfn-qb-section-label">
                        <span class="dfn-qb-step-num">3</span>
                        Dati prenotante
                    </div>

                    <div class="dfn-qb-row">
                        <div class="dfn-qb-field">
                            <label class="dfn-qb-label" for="qb-lastname">Cognome *</label>
                            <input class="dfn-qb-input" type="text" id="qb-lastname" name="last_name"
                                   placeholder="Es. Rossi" autocomplete="family-name" required>
                        </div>
                        <div class="dfn-qb-field">
                            <label class="dfn-qb-label" for="qb-firstname">Nome <span class="dfn-qb-optional">(opzionale)</span></label>
                            <input class="dfn-qb-input" type="text" id="qb-firstname" name="first_name"
                                   placeholder="Es. Mario" autocomplete="given-name">
                        </div>
                    </div>

                    <div class="dfn-qb-row">
                        <div class="dfn-qb-field">
                            <label class="dfn-qb-label" for="qb-qty-std">Posti standard *</label>
                            <div class="dfn-qb-spinner-wrap">
                                <button type="button" class="dfn-qb-spinner-btn" data-target="qb-qty-std" data-action="dec">−</button>
                                <input class="dfn-qb-spinner" type="number" id="qb-qty-std" name="qty_standard"
                                       value="1" min="0" max="50" required>
                                <button type="button" class="dfn-qb-spinner-btn" data-target="qb-qty-std" data-action="inc">+</button>
                            </div>
                        </div>
                        <div class="dfn-qb-field">
                            <label class="dfn-qb-label" for="qb-qty-fai">Soci FAI</label>
                            <div class="dfn-qb-spinner-wrap">
                                <button type="button" class="dfn-qb-spinner-btn" data-target="qb-qty-fai" data-action="dec">−</button>
                                <input class="dfn-qb-spinner" type="number" id="qb-qty-fai" name="qty_fai"
                                       value="0" min="0" max="50">
                                <button type="button" class="dfn-qb-spinner-btn" data-target="qb-qty-fai" data-action="inc">+</button>
                            </div>
                        </div>
                    </div>

                    <!-- Tessere FAI dinamiche -->
                    <div id="qb-fai-cards-wrap" style="display:none;">
                        <div class="dfn-qb-fai-header">
                            <span>🏅</span> Dati tessere Soci FAI
                        </div>
                        <div id="qb-fai-cards-list"></div>
                    </div>

                    <div class="dfn-qb-field">
                        <label class="dfn-qb-label" for="qb-email">Email <span class="dfn-qb-optional">(opzionale — se presente invia conferma automatica)</span></label>
                        <input class="dfn-qb-input" type="email" id="qb-email" name="email"
                               placeholder="mario.rossi@email.it" autocomplete="email">
                    </div>

                    <div class="dfn-qb-field">
                        <label class="dfn-qb-label" for="qb-phone">Telefono <span class="dfn-qb-optional">(opzionale)</span></label>
                        <input class="dfn-qb-input" type="tel" id="qb-phone" name="phone"
                               placeholder="333 1234567" autocomplete="tel">
                    </div>

                    <div class="dfn-qb-field">
                        <label class="dfn-qb-label" for="qb-notes">Note <span class="dfn-qb-optional">(opzionale)</span></label>
                        <textarea class="dfn-qb-textarea" id="qb-notes" name="notes"
                                  placeholder="Richieste particolari, accessibilità, ecc." rows="3"></textarea>
                    </div>
                </div>

                <!-- Errori -->
                <div class="dfn-qb-error" id="dfn-qb-error" style="display:none;"></div>

                <!-- Submit -->
                <div class="dfn-qb-submit-wrap" id="qb-submit-wrap" style="display:none;">
                    <button type="submit" class="dfn-qb-btn dfn-qb-btn-submit" id="dfn-qb-submit">
                        <span id="dfn-qb-submit-text">✅ Conferma Prenotazione</span>
                        <span id="dfn-qb-submit-spinner" style="display:none;">⏳ Salvataggio…</span>
                    </button>
                </div>

            </form>
        </div><!-- /#dfn-qb-form-wrap -->

    </div><!-- /.dfn-qb-wrap -->
    <?php
}

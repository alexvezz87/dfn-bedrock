/**
 * DFN Quick Booking — Controller JS
 *
 * Gestisce:
 * - Cascata dropdown: Evento → Date → Slot (con opzione "Auto")
 * - Spinner + / - per i quantitativi
 * - Tessere FAI dinamiche (N campi in base a qty_fai)
 * - Submit via AJAX riutilizzando dfn_ajax_admin_add_booking
 * - Banner successo con testo copiabile e invio email automatico
 * - Reset form per nuova prenotazione
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

/* global dfnQuickVars, jQuery */

(function ($) {
    'use strict';

    // =========================================================================
    // STATE
    // =========================================================================

    let currentEvent   = null; // Oggetto evento selezionato { id, event_name, access_type, allocation_mode }
    let currentDate    = '';   // Data selezionata
    let lastBooking    = null; // Risposta ultima prenotazione confermata

    // =========================================================================
    // DOM REFERENCES
    // =========================================================================

    const $form          = $('#dfn-qb-form');
    const $eventSel      = $('#qb-event');
    const $dateSel       = $('#qb-date');
    const $dateWrap      = $('#qb-date-wrap');
    const $slotSel       = $('#qb-slot');
    const $slotSection   = $('#qb-slot-section');
    const $guestSection  = $('#qb-guest-section');
    const $submitWrap    = $('#qb-submit-wrap');
    const $submitBtn     = $('#dfn-qb-submit');
    const $submitText    = $('#dfn-qb-submit-text');
    const $submitSpinner = $('#dfn-qb-submit-spinner');
    const $errorBox      = $('#dfn-qb-error');
    const $formWrap      = $('#dfn-qb-form-wrap');
    const $successBox    = $('#dfn-qb-success');
    const $successTitle  = $('#dfn-qb-success-title');
    const $successDetail = $('#dfn-qb-success-detail');
    const $successMsgW   = $('#dfn-qb-success-msg-wrap');
    const $successMsg    = $('#dfn-qb-success-msg');
    const $emailNote     = $('#dfn-qb-email-note');
    const $copyBtn       = $('#dfn-qb-copy-btn');
    const $newBtn        = $('#dfn-qb-new-btn');
    const $qtyStd        = $('#qb-qty-std');
    const $qtyFai        = $('#qb-qty-fai');
    const $faiCardsWrap  = $('#qb-fai-cards-wrap');
    const $faiCardsList  = $('#qb-fai-cards-list');

    // =========================================================================
    // INIT
    // =========================================================================

    function init() {
        loadEvents();
        bindSpinners();
        bindFaiQtyWatch();
        bindEventChange();
        bindDateChange();
        bindSubmit();
        bindCopy();
        bindNew();
    }

    // =========================================================================
    // LOAD EVENTS
    // =========================================================================

    function loadEvents() {
        $eventSel.prop('disabled', true).html('<option value="">⏳ Caricamento eventi…</option>');

        $.ajax({
            url:  dfnQuickVars.ajaxurl,
            type: 'POST',
            data: {
                action: 'dfn_quick_get_events',
                nonce:  dfnQuickVars.nonce,
            },
            success: function (res) {
                if (res.success && res.data.events && res.data.events.length > 0) {
                    let html = '<option value="">— Seleziona un evento —</option>';
                    res.data.events.forEach(function (ev) {
                        const startFmt = formatDate(ev.event_date_start);
                        const endFmt   = ev.event_date_end !== ev.event_date_start ? ' → ' + formatDate(ev.event_date_end) : '';
                        html += `<option value="${ev.id}"
                            data-access="${ev.access_type}"
                            data-name="${escAttr(ev.event_name)}"
                            data-alloc="${ev.allocation_mode}"
                            data-date-start="${ev.event_date_start}"
                            data-date-end="${ev.event_date_end}">
                            ${ev.event_name} (${startFmt}${endFmt})
                        </option>`;
                    });
                    $eventSel.html(html).prop('disabled', false);
                } else {
                    $eventSel.html('<option value="">Nessun evento attivo disponibile</option>');
                    showError('Nessun evento attivo trovato. Verifica che ci siano eventi con stato "attivo" e date future.');
                }
            },
            error: function () {
                $eventSel.html('<option value="">Errore nel caricamento</option>');
                showError('Errore di rete nel caricamento degli eventi. Riprova.');
            },
        });
    }

    // =========================================================================
    // EVENT → DATE CASCADE
    // =========================================================================

    function bindEventChange() {
        $eventSel.on('change', function () {
            const $opt = $(this).find(':selected');
            const evId = parseInt($(this).val(), 10);

            // Reset tutto
            resetFrom('event');

            if (! evId) {
                return;
            }

            currentEvent = {
                id:            evId,
                event_name:    $opt.data('name'),
                access_type:   $opt.data('access'),
                allocation_mode: $opt.data('alloc'),
                date_start:    $opt.data('date-start'),
                date_end:      $opt.data('date-end'),
            };

            // Carica le date
            $dateSel.html('<option value="">⏳ Caricamento date…</option>').prop('disabled', true);
            $dateWrap.show();

            $.ajax({
                url:  dfnQuickVars.ajaxurl,
                type: 'POST',
                data: {
                    action:   'dfn_quick_get_dates',
                    nonce:    dfnQuickVars.nonce,
                    event_id: evId,
                },
                success: function (res) {
                    if (res.success && res.data.dates && res.data.dates.length > 0) {
                        let html = '';
                        if (res.data.dates.length > 1) {
                            html += '<option value="">— Seleziona una data —</option>';
                        }
                        res.data.dates.forEach(function (d) {
                            html += `<option value="${d}">${formatDate(d)}</option>`;
                        });
                        $dateSel.html(html).prop('disabled', false);

                        if (res.data.dates.length === 1) {
                            $dateSel.val(res.data.dates[0]).trigger('change');
                        }
                    } else {
                        $dateSel.html('<option value="">Nessuna data disponibile</option>').prop('disabled', true);
                    }
                },
                error: function () {
                    $dateSel.html('<option value="">Errore caricamento date</option>').prop('disabled', true);
                },
            });
        });
    }

    // =========================================================================
    // DATE → SLOT CASCADE
    // =========================================================================

    function bindDateChange() {
        $dateSel.on('change', function () {
            const date = $(this).val();
            resetFrom('date');

            if (! date || ! currentEvent) {
                return;
            }

            currentDate = date;

            // Per eventi free_flow non mostriamo gli slot
            if (currentEvent.access_type === 'free_flow') {
                $slotSection.hide();
                $guestSection.show();
                $submitWrap.show();
                return;
            }

            // Per time_slots: carica gli slot disponibili
            $slotSel.html('<option value="">⏳ Caricamento turni…</option>').prop('disabled', true);
            $slotSection.show();

            $.ajax({
                url:  dfnQuickVars.ajaxurl,
                type: 'POST',
                data: {
                    action:   'dfn_quick_get_slots',
                    nonce:    dfnQuickVars.nonce,
                    event_id: currentEvent.id,
                    date:     date,
                },
                success: function (res) {
                    let html = '<option value="0">🤖 Auto — Smistamento automatico</option>';
                    if (res.success && res.data.slots && res.data.slots.length > 0) {
                        res.data.slots.forEach(function (s) {
                            const locked  = s.is_locked;
                            const avail   = s.available;
                            const icon    = locked ? '🔒' : (avail === 0 ? '🔴' : avail <= 3 ? '🟡' : '🟢');
                            const label   = locked
                                ? `${s.time_start} → ${s.time_end}  ${icon} Bloccato`
                                : `${s.time_start} → ${s.time_end}  ${icon} ${avail} posti liberi`;
                            html += `<option value="${s.id}" ${locked || avail === 0 ? 'disabled' : ''}>${label}</option>`;
                        });
                    }
                    $slotSel.html(html).prop('disabled', false);
                    $guestSection.show();
                    $submitWrap.show();
                },
                error: function () {
                    $slotSel.html('<option value="0">🤖 Auto — Smistamento automatico</option>').prop('disabled', false);
                    $guestSection.show();
                    $submitWrap.show();
                },
            });
        });
    }

    // =========================================================================
    // SPINNERS + / -
    // =========================================================================

    function bindSpinners() {
        $(document).on('click', '.dfn-qb-spinner-btn', function () {
            const targetId = $(this).data('target');
            const action   = $(this).data('action');
            const $input   = $('#' + targetId);
            let val        = parseInt($input.val(), 10) || 0;
            const min      = parseInt($input.attr('min'), 10) || 0;
            const max      = parseInt($input.attr('max'), 10) || 9999;

            if (action === 'inc') {
                val = Math.min(val + 1, max);
            } else {
                val = Math.max(val - 1, min);
            }

            $input.val(val).trigger('change');
        });
    }

    // =========================================================================
    // FAI CARDS DYNAMIC FIELDS
    // =========================================================================

    function bindFaiQtyWatch() {
        $qtyFai.on('change input', function () {
            renderFaiCards(parseInt($(this).val(), 10) || 0);
        });
    }

    function renderFaiCards(qty) {
        if (qty <= 0) {
            $faiCardsWrap.hide();
            $faiCardsList.empty();
            return;
        }

        $faiCardsWrap.show();
        const existing = $faiCardsList.children('.dfn-qb-fai-card').length;

        // Aggiunge i nuovi campi necessari
        for (let i = existing; i < qty; i++) {
            const n = i + 1;
            $faiCardsList.append(`
                <div class="dfn-qb-fai-card" data-index="${i}">
                    <div class="dfn-qb-fai-card-title">Socio FAI #${n}</div>
                    <div class="dfn-qb-fai-row">
                        <div class="dfn-qb-field">
                            <label class="dfn-qb-label">Cognome</label>
                            <input class="dfn-qb-input" type="text" name="fai_cards[${i}][cognome]" placeholder="Cognome (facoltativo)">
                        </div>
                        <div class="dfn-qb-field">
                            <label class="dfn-qb-label">Nome</label>
                            <input class="dfn-qb-input" type="text" name="fai_cards[${i}][nome]" placeholder="Nome (facoltativo)">
                        </div>
                    </div>
                    <div class="dfn-qb-field">
                        <label class="dfn-qb-label">N° Tessera</label>
                        <input class="dfn-qb-input" type="text" name="fai_cards[${i}][tessera]" placeholder="Es. 1234567 (facoltativo)">
                    </div>
                </div>
            `);
        }

        // Rimuove i campi in eccesso
        $faiCardsList.children('.dfn-qb-fai-card').each(function (idx) {
            if (idx >= qty) {
                $(this).remove();
            }
        });
    }

    // =========================================================================
    // FORM SUBMIT
    // =========================================================================

    function bindSubmit() {
        $form.on('submit', function (e) {
            e.preventDefault();
            hideError();

            // Validazione base
            const lastName  = $('#qb-lastname').val().trim();
            const firstName = $('#qb-firstname').val().trim();
            const qtyStd    = parseInt($qtyStd.val(), 10) || 0;
            const qtyFai    = parseInt($qtyFai.val(), 10) || 0;
            const eventId   = parseInt($eventSel.val(), 10) || 0;
            const date      = $dateSel.val();

            if (! eventId) {
                return showError('Seleziona un evento.');
            }
            if (! date) {
                return showError('Seleziona una data.');
            }
            if (! lastName) {
                return showError('Il cognome è obbligatorio.');
            }
            if (qtyStd + qtyFai <= 0) {
                return showError('Inserisci almeno 1 posto (standard o Socio FAI).');
            }

            // Costruisce i dati del form
            const slotId  = currentEvent && currentEvent.access_type !== 'free_flow'
                ? (parseInt($slotSel.val(), 10) || 0)
                : 0;

            const email = $('#qb-email').val().trim();
            const phone = $('#qb-phone').val().trim();
            const notes = $('#qb-notes').val().trim();

            const formData = {
                action:     'dfn_admin_add_booking',
                nonce:      dfnQuickVars.admin_nonce,
                event_id:   eventId,
                slot_id:    slotId,
                date:       date,
                first_name: firstName,
                last_name:  lastName,
                email:      email,
                phone:      phone,
                qty_standard: qtyStd,
                qty_fai:    qtyFai,
                notes:      notes,
            };

            // Aggiunge tessere FAI
            if (qtyFai > 0) {
                $faiCardsList.find('.dfn-qb-fai-card').each(function (idx) {
                    const $card = $(this);
                    formData[`fai_cards[${idx}][cognome]`] = $card.find('[name$="[cognome]"]').val().trim();
                    formData[`fai_cards[${idx}][nome]`]    = $card.find('[name$="[nome]"]').val().trim();
                    formData[`fai_cards[${idx}][tessera]`] = $card.find('[name$="[tessera]"]').val().trim();
                });
            }

            // Imposta stato loading
            setLoading(true);

            $.ajax({
                url:  dfnQuickVars.ajaxurl,
                type: 'POST',
                data: formData,
                success: function (res) {
                    setLoading(false);
                    if (res.success) {
                        lastBooking = {
                            orderId:    res.data.order_id,
                            eventName:  currentEvent.event_name,
                            date:       date,
                            slotId:     slotId,
                            firstName:  firstName,
                            lastName:   lastName,
                            qtyStd:     qtyStd,
                            qtyFai:     qtyFai,
                            email:      email,
                        };
                        showSuccess(lastBooking);
                    } else {
                        showError(res.data && res.data.message
                            ? res.data.message
                            : 'Si è verificato un errore. Riprova.');
                    }
                },
                error: function () {
                    setLoading(false);
                    showError('Errore di rete. Verifica la connessione e riprova.');
                },
            });
        });
    }

    // =========================================================================
    // SUCCESS
    // =========================================================================

    function showSuccess(b) {
        const totalPersons = b.qtyStd + b.qtyFai;
        const persLabel    = totalPersons === 1 ? 'persona' : 'persone';
        const dateLabel    = formatDate(b.date);

        // Testo del banner
        $successTitle.text('Prenotazione #' + b.orderId + ' confermata!');
        $successDetail.html(
            `<strong>${b.lastName} ${b.firstName}</strong> — ${b.eventName}<br>` +
            `📅 ${dateLabel} &nbsp;👥 ${totalPersons} ${persLabel}`
        );

        // Messaggio copiabile (solo per chi NON ha inserito l'email)
        if (b.email && b.email.trim() !== '') {
            $successMsgW.hide();
            $emailNote.show();
        } else {
            const msgText = buildConfirmationText(b, dateLabel, totalPersons, persLabel);
            $successMsg.text(msgText);
            $successMsgW.show();
            $emailNote.hide();
        }

        $formWrap.hide();
        $successBox.show();

        // Scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function buildConfirmationText(b, dateLabel, totalPersons, persLabel) {
        const faiNote = b.qtyFai > 0 ? ` (di cui ${b.qtyFai} Soci FAI)` : '';
        return `Gentile ${b.lastName} ${b.firstName},\n\n` +
               `la sua prenotazione all'evento "${b.eventName}" per ${totalPersons} ${persLabel}${faiNote} ` +
               `in data ${dateLabel} è stata confermata con il riferimento #${b.orderId}.\n\n` +
               `Si presenti al banchetto indicando questo numero di prenotazione.\n\n` +
               `Grazie e a presto!\n` +
               `FAI Delegazione di Novara`;
    }

    // =========================================================================
    // COPY TO CLIPBOARD
    // =========================================================================

    function bindCopy() {
        $copyBtn.on('click', function () {
            const text = $successMsg.text();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    $copyBtn.text('✅ Copiato!');
                    setTimeout(function () {
                        $copyBtn.text('📋 Copia messaggio');
                    }, 2500);
                });
            } else {
                // Fallback per browser vecchi
                const $tmp = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $tmp.remove();
                $copyBtn.text('✅ Copiato!');
                setTimeout(function () {
                    $copyBtn.text('📋 Copia messaggio');
                }, 2500);
            }
        });
    }

    // =========================================================================
    // RESET / NEW BOOKING
    // =========================================================================

    function bindNew() {
        $newBtn.on('click', function () {
            resetAll();
            $successBox.hide();
            $formWrap.show();
            loadEvents();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    function resetAll() {
        currentEvent = null;
        currentDate  = '';
        lastBooking  = null;
        $form[0].reset();
        $dateSel.html('<option value="">— Seleziona prima un evento —</option>');
        $slotSel.html('<option value="0">🤖 Auto — Smistamento automatico</option>');
        $dateWrap.hide();
        $slotSection.hide();
        $guestSection.hide();
        $submitWrap.hide();
        $faiCardsWrap.hide();
        $faiCardsList.empty();
        hideError();
    }

    function resetFrom(level) {
        if (level === 'event') {
            currentDate = '';
            $dateSel.html('<option value="">— Seleziona prima un evento —</option>').prop('disabled', true);
            $dateWrap.hide();
            $slotSection.hide();
            $guestSection.hide();
            $submitWrap.hide();
            $faiCardsWrap.hide();
            $faiCardsList.empty();
            $qtyStd.val(1);
            $qtyFai.val(0);
        }
        if (level === 'date' || level === 'event') {
            $slotSel.html('<option value="0">🤖 Auto — Smistamento automatico</option>').prop('disabled', false);
            $slotSection.hide();
            $guestSection.hide();
            $submitWrap.hide();
        }
        hideError();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    function showError(msg) {
        $errorBox.text(msg).show();
        $errorBox[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function hideError() {
        $errorBox.hide().text('');
    }

    function setLoading(isLoading) {
        if (isLoading) {
            $submitBtn.prop('disabled', true);
            $submitText.hide();
            $submitSpinner.show();
            $form.addClass('dfn-qb-loading');
        } else {
            $submitBtn.prop('disabled', false);
            $submitText.show();
            $submitSpinner.hide();
            $form.removeClass('dfn-qb-loading');
        }
    }

    function formatDate(dateStr) {
        if (! dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        const months = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
        return `${parseInt(parts[2], 10)} ${months[parseInt(parts[1], 10)]} ${parts[0]}`;
    }

    function escAttr(str) {
        return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // =========================================================================
    // START
    // =========================================================================

    $(document).ready(function () {
        init();
    });

}(jQuery));

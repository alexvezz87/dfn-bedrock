/**
 * BOTTEGHINO LIVE — Controller JS (DFN 2.0)
 *
 * Gestisce:
 * - Cascata dropdown: Evento → Date → Slot (con opzione "Auto")
 * - Tessere FAI dinamiche (N campi in base a qty_fai)
 * - 4 bottoni azione: Contanti, Link, Solo Prenotazione, Autorità
 * - Submit via AJAX a dfn_botteghino_create_booking
 * - Cerca cliente via Select2 (WooCommerce)
 * - Auto-checkin con conferma
 *
 * @package DFN_Theme
 * @since   2.2.0
 */

/* global cvBotteghinoVars, jQuery */

jQuery(document).ready(function ($) {
    'use strict';

    // =========================================================================
    // VARIABILI LOCALIZZATE
    // =========================================================================

    var ajaxurl    = cvBotteghinoVars.ajaxurl;
    var nonce      = cvBotteghinoVars.nonce;
    var custNonce  = cvBotteghinoVars.cust_nonce;

    // =========================================================================
    // DOM REFERENCES
    // =========================================================================

    var $eventSel      = $('#cv-bott-event');
    var $dateSel       = $('#cv-bott-date');
    var $dateWrap      = $('#cv-bott-date-wrap');
    var $slotSel       = $('#cv-bott-slot');
    var $slotWrap      = $('#cv-bott-slot-wrap');
    var $guestSection  = $('#cv-bott-guest-section');
    var $qtySection    = $('#cv-bott-qty-section');
    var $checkinWrap   = $('#cv-auto-checkin-wrapper');
    var $buttonsWrap   = $('#cv-bott-buttons');
    var $feedback      = $('#cv-bott-feedback');
    var $qtyStd        = $('#fai_qty_std');
    var $qtyFai        = $('#fai_qty_fai');
    var $faiCardsWrap  = $('#cv-bott-fai-cards-wrap');
    var $faiCardsList  = $('#cv-bott-fai-cards-list');

    // State
    var currentEvent = null;
    var currentDate  = '';
    var isSubmitting = false;

    // =========================================================================
    // INIT
    // =========================================================================

    loadEvents();
    bindEventChange();
    bindDateChange();
    bindFaiQtyWatch();
    bindNoEmail();
    bindButtons();
    initSelect2();

    // =========================================================================
    // LOAD EVENTS (riusa dfn_quick_get_events)
    // =========================================================================

    function loadEvents() {
        $eventSel.prop('disabled', true).html('<option value="">⏳ Caricamento eventi…</option>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'dfn_botteghino_get_events',
                nonce: nonce,
            },
            success: function (res) {
                if (res.success && res.data.events && res.data.events.length > 0) {
                    var html = '<option value="">— Seleziona l\'evento —</option>';
                    res.data.events.forEach(function (ev) {
                        var startFmt = formatDate(ev.event_date_start);
                        var endFmt   = ev.event_date_end !== ev.event_date_start ? ' → ' + formatDate(ev.event_date_end) : '';
                        html += '<option value="' + ev.id + '"'
                            + ' data-access="' + ev.access_type + '"'
                            + ' data-name="' + escAttr(ev.event_name) + '"'
                            + ' data-alloc="' + ev.allocation_mode + '"'
                            + ' data-date-start="' + ev.event_date_start + '"'
                            + ' data-date-end="' + ev.event_date_end + '">'
                            + ev.event_name + ' (' + startFmt + endFmt + ')'
                            + '</option>';
                    });
                    $eventSel.html(html).prop('disabled', false);
                } else {
                    $eventSel.html('<option value="">Nessun evento attivo disponibile</option>');
                    showFeedback('error', 'Nessun evento attivo trovato.');
                }
            },
            error: function () {
                $eventSel.html('<option value="">Errore nel caricamento</option>');
                showFeedback('error', 'Errore di rete nel caricamento degli eventi.');
            },
        });
    }

    // =========================================================================
    // EVENTO → DATA CASCADE
    // =========================================================================

    function bindEventChange() {
        $eventSel.on('change', function () {
            var $opt = $(this).find(':selected');
            var evId = parseInt($(this).val(), 10);

            resetFrom('event');

            if (!evId) return;

            currentEvent = {
                id:              evId,
                event_name:      $opt.data('name'),
                access_type:     $opt.data('access'),
                allocation_mode: $opt.data('alloc'),
                date_start:      $opt.data('date-start'),
                date_end:        $opt.data('date-end'),
            };

            // Carica le date
            $dateSel.html('<option value="">⏳ Caricamento date…</option>').prop('disabled', true);
            $dateWrap.slideDown(200);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_botteghino_get_dates',
                    nonce: nonce,
                    event_id: evId,
                },
                success: function (res) {
                    if (res.success && res.data.dates && res.data.dates.length > 0) {
                        var html = '';
                        if (res.data.dates.length > 1) {
                            html += '<option value="">— Seleziona una data —</option>';
                        }
                        res.data.dates.forEach(function (d) {
                            html += '<option value="' + d + '">' + formatDate(d) + '</option>';
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
    // DATA → SLOT CASCADE
    // =========================================================================

    function bindDateChange() {
        $dateSel.on('change', function () {
            var date = $(this).val();
            resetFrom('date');

            if (!date || !currentEvent) return;

            currentDate = date;

            // Mostra le sezioni dati
            $guestSection.slideDown(200);
            $qtySection.slideDown(200);
            $checkinWrap.slideDown(200);
            $buttonsWrap.slideDown(200);

            // Per free_flow non mostra gli slot
            if (currentEvent.access_type === 'free_flow') {
                $slotWrap.hide();
                return;
            }

            // Per time_slots: carica slot
            $slotSel.html('<option value="">⏳ Caricamento turni…</option>').prop('disabled', true);
            $slotWrap.slideDown(200);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_botteghino_get_slots',
                    nonce: nonce,
                    event_id: currentEvent.id,
                    date: date,
                },
                success: function (res) {
                    if (res.success && res.data.slots) {
                        var html = '<option value="0">🤖 Auto — Smistamento automatico</option>';
                        res.data.slots.forEach(function (s) {
                            var label = s.time_start + ' - ' + s.time_end;
                            var badge = s.is_locked
                                ? ' 🔒 Bloccato'
                                : ' (' + s.available + ' posti disponibili)';
                            html += '<option value="' + s.id + '"'
                                + (s.is_locked ? ' disabled' : '')
                                + '>' + label + badge + '</option>';
                        });
                        $slotSel.html(html).prop('disabled', false);
                    } else {
                        $slotSel.html('<option value="0">🤖 Auto — Smistamento automatico</option>').prop('disabled', false);
                    }
                },
                error: function () {
                    $slotSel.html('<option value="0">🤖 Auto — Smistamento automatico</option>').prop('disabled', false);
                },
            });
        });
    }

    // =========================================================================
    // TESSERE FAI DINAMICHE
    // =========================================================================

    function bindFaiQtyWatch() {
        $qtyFai.on('change input', function () {
            var qty = parseInt($(this).val(), 10) || 0;
            renderFaiCards(qty);
        });

        // Controllo: tessere non superiori ai biglietti totali
        $qtyStd.on('change input', function () {
            var faiVal = parseInt($qtyFai.val(), 10) || 0;
            var stdVal = parseInt($qtyStd.val(), 10) || 0;
            var total = stdVal + faiVal;
            if (total <= 0 && stdVal <= 0) {
                $qtyStd.val(1);
            }
        });
    }

    function renderFaiCards(qty) {
        if (qty <= 0) {
            $faiCardsWrap.slideUp(200);
            $faiCardsList.empty();
            return;
        }

        $faiCardsWrap.slideDown(200);
        var html = '';
        for (var i = 0; i < qty; i++) {
            html += '<div class="cv-bott-fai-card">'
                + '<strong>Socio FAI #' + (i + 1) + '</strong>'
                + '<div style="display:flex; gap:10px; margin-top:6px;">'
                + '  <input type="text" class="fai-card-nome" placeholder="Nome (facoltativo)" style="flex:1; padding:6px 10px; border:1px solid #8c8f94; border-radius:4px;">'
                + '  <input type="text" class="fai-card-cognome" placeholder="Cognome (facoltativo)" style="flex:1; padding:6px 10px; border:1px solid #8c8f94; border-radius:4px;">'
                + '  <input type="text" class="fai-card-tessera" placeholder="N° Tessera (facoltativo)" style="flex:1; padding:6px 10px; border:1px solid #8c8f94; border-radius:4px;">'
                + '</div>'
                + '</div>';
        }
        $faiCardsList.html(html);
    }

    // =========================================================================
    // NO EMAIL
    // =========================================================================

    function bindNoEmail() {
        $('#cv-btn-no-email').on('click', function (e) {
            e.preventDefault();
            var randomNum = Math.floor(1000 + Math.random() * 9000);
            $('#fai_email').val('cassa_' + randomNum + '@fainovara.local').css('background-color', '#fff3cd');
        });
    }

    // =========================================================================
    // SELECT2 CERCA CLIENTE
    // =========================================================================

    function initSelect2() {
        if (!$.fn.selectWoo) return;

        $('#fai_customer_search').selectWoo({
            allowClear: true,
            ajax: {
                url: ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return { action: 'cv_search_customers', term: params.term, security: custNonce };
                },
                processResults: function (data) { return { results: data }; },
                cache: true,
            },
            minimumInputLength: 3,
            language: { inputTooShort: function () { return 'Scrivi almeno 3 lettere...'; } },
        }).on('select2:select', function (e) {
            var data = e.params.data;
            if (data.id) {
                $.post(ajaxurl, {
                    action: 'cv_get_customer_data', security: custNonce, customer_id: data.id,
                }, function (response) {
                    if (response.success) {
                        $('#fai_nome').val(response.data.first_name);
                        $('#fai_cognome').val(response.data.last_name);
                        $('#fai_email').val(response.data.email).css('background-color', '');
                        $('#fai_telefono').val(response.data.phone);
                    }
                });
            }
        }).on('select2:unselect', function () {
            $('#fai_nome, #fai_cognome, #fai_email, #fai_telefono').val('');
        });
    }

    // =========================================================================
    // BOTTONI AZIONE
    // =========================================================================

    function bindButtons() {
        // 💵 Contanti
        $('#cv-btn-submit-cash').on('click', function () {
            if (isSubmitting) return;
            if (!validateForm()) return;

            var isAutoCheckin = $('#fai_auto_checkin').is(':checked');
            var autoCheckinMsg = isAutoCheckin
                ? '\n\n✅ ATTENZIONE: Hai spuntato la validazione automatica. I biglietti non saranno scansionabili all\'ingresso.'
                : '';

            if (!confirm('Confermi di aver incassato l\'importo in CONTANTI?' + autoCheckinMsg)) return;

            submitBooking('contanti');
        });

        // 💳 Link Pagamento
        $('#cv-btn-submit-link').on('click', function () {
            if (isSubmitting) return;
            if (!validateForm()) return;

            var email = $('#fai_email').val();
            if (!email || email.indexOf('@fainovara.local') !== -1 || email.indexOf('@dfn.local') !== -1) {
                alert('Per inviare il link di pagamento è necessario un indirizzo email valido.');
                return;
            }

            submitBooking('link');
        });

        // 📋 Solo Prenotazione
        $('#cv-btn-submit-booking').on('click', function () {
            if (isSubmitting) return;
            if (!validateForm()) return;

            submitBooking('prenotazione');
        });

        // 🎁 Autorità
        $('#cv-btn-submit-auth').on('click', function () {
            if (isSubmitting) return;

            if (!currentEvent || !currentDate) {
                alert('Seleziona un evento e una data per riservare i posti.');
                return;
            }

            var qtyStd = parseInt($qtyStd.val(), 10) || 0;
            var qtyFai = parseInt($qtyFai.val(), 10) || 0;
            if ((qtyStd + qtyFai) <= 0) {
                alert('Specifica almeno un posto da riservare.');
                return;
            }

            if (!confirm('Confermi di voler bloccare i posti come OMAGGIO PER AUTORITÀ?\nI biglietti verranno scalati e inseriti nel tabellone senza inviare nessuna mail.')) return;

            submitBooking('autorita');
        });
    }

    // =========================================================================
    // VALIDAZIONE
    // =========================================================================

    function validateForm() {
        if (!currentEvent || !currentDate) {
            alert('Seleziona un evento e una data.');
            return false;
        }

        var nome = $('#fai_nome').val().trim();
        var cognome = $('#fai_cognome').val().trim();
        if (!nome || !cognome) {
            alert('Nome e Cognome sono obbligatori.');
            return false;
        }

        var qtyStdVal = parseInt($qtyStd.val(), 10) || 0;
        var qtyFaiVal = parseInt($qtyFai.val(), 10) || 0;
        if ((qtyStdVal + qtyFaiVal) <= 0) {
            alert('Specifica almeno un biglietto.');
            return false;
        }



        return true;
    }

    // =========================================================================
    // SUBMIT AJAX
    // =========================================================================

    function submitBooking(paymentMethod, confirmSplit) {
        if (isSubmitting) return;
        isSubmitting = true;

        // Disabilita tutti i bottoni
        $('.cv-pos-btn').prop('disabled', true).css('opacity', '0.6');
        showFeedback('info', '⏳ Salvataggio in corso...');

        // Raccogli tessere FAI
        var faiCards = [];
        if (parseInt($qtyFai.val(), 10) > 0) {
            $('.cv-bott-fai-card').each(function () {
                faiCards.push({
                    nome: $(this).find('.fai-card-nome').val().trim(),
                    cognome: $(this).find('.fai-card-cognome').val().trim(),
                    tessera: $(this).find('.fai-card-tessera').val().trim(),
                });
            });
        }

        var data = {
            action:         'dfn_botteghino_create_booking',
            nonce:          nonce,
            event_id:       currentEvent.id,
            date:           currentDate,
            slot_id:        parseInt($slotSel.val(), 10) || 0,
            first_name:     $('#fai_nome').val().trim(),
            last_name:      $('#fai_cognome').val().trim(),
            email:          $('#fai_email').val().trim(),
            phone:          $('#fai_telefono').val().trim(),
            qty_standard:   parseInt($qtyStd.val(), 10) || 0,
            qty_fai:        parseInt($qtyFai.val(), 10) || 0,
            notes:          $('#fai_notes').val().trim(),
            payment_method: paymentMethod,
            auto_checkin:   $('#fai_auto_checkin').is(':checked') ? '1' : '0',
            fai_cards:      faiCards,
            confirm_split:  confirmSplit ? '1' : '0',
        };

        // Per le email fittizie del "no email", non inviare
        var emailVal = data.email;
        if (emailVal.indexOf('@fainovara.local') !== -1 || emailVal.indexOf('@dfn.local') !== -1) {
            data.email = ''; // Verrà gestito dal backend come "no-email"
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: data,
            success: function (res) {
                isSubmitting = false;
                $('.cv-pos-btn').prop('disabled', false).css('opacity', '');

                if (res.success) {
                    if (res.data.status === 'split_warning') {
                        // Chiedi conferma per lo split
                        if (confirm(res.data.message)) {
                            submitBooking(paymentMethod, true);
                        } else {
                            hideFeedback();
                        }
                        return;
                    }

                    // Successo
                    var colorClass = res.data.status === 'waitlist' ? 'notice-warning' : 'notice-success';
                    var orderLink = res.data.edit_url
                        ? '<br><br><a href="' + res.data.edit_url + '" target="_blank">🔍 Vedi ordine #' + res.data.order_id + '</a>'
                        : '';
                    showFeedback(colorClass === 'notice-warning' ? 'warning' : 'success',
                        res.data.message + orderLink);

                    // Reset form
                    resetForm();
                } else {
                    showFeedback('error', res.data.message || 'Errore sconosciuto.');
                }
            },
            error: function () {
                isSubmitting = false;
                $('.cv-pos-btn').prop('disabled', false).css('opacity', '');
                showFeedback('error', 'Errore di rete. Riprova.');
            },
        });
    }

    // =========================================================================
    // RESET
    // =========================================================================

    function resetFrom(level) {
        if (level === 'event') {
            currentEvent = null;
            currentDate  = '';
            $dateWrap.slideUp(200);
            $dateSel.html('<option value="">— Seleziona prima un evento —</option>').prop('disabled', true);
        }
        if (level === 'event' || level === 'date') {
            $slotWrap.slideUp(200);
            $slotSel.html('<option value="0">🤖 Auto — Smistamento automatico</option>');
            $guestSection.slideUp(200);
            $qtySection.slideUp(200);
            $checkinWrap.slideUp(200);
            $buttonsWrap.slideUp(200);
        }
    }

    function resetForm() {
        // Pulisci dati cliente (non evento/data)
        $('#fai_nome, #fai_cognome, #fai_email, #fai_telefono').val('');
        $('#fai_email').css('background-color', '');
        $qtyStd.val(1);
        $qtyFai.val(0);
        $('#fai_notes').val('');
        $('#fai_auto_checkin').prop('checked', false);
        $faiCardsWrap.slideUp(200);
        $faiCardsList.empty();

        // Reset Select2
        if ($.fn.selectWoo) {
            $('#fai_customer_search').val('').trigger('change.select2');
        }
    }

    // =========================================================================
    // FEEDBACK
    // =========================================================================

    function showFeedback(type, message) {
        var cssClass = 'notice ';
        if (type === 'success') cssClass += 'notice-success';
        else if (type === 'warning') cssClass += 'notice-warning';
        else if (type === 'info') cssClass += 'notice-info';
        else cssClass += 'notice-error';

        $feedback.html('<div class="' + cssClass + ' is-dismissible" style="padding:15px; font-size:15px;">' + message + '</div>').show();

        // Auto-scroll alla notifica
        $('html, body').animate({ scrollTop: $feedback.offset().top - 50 }, 300);
    }

    function hideFeedback() {
        $feedback.hide().empty();
    }

    // =========================================================================
    // UTILITÀ
    // =========================================================================

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var parts = dateStr.split('-');
        if (parts.length !== 3) return dateStr;
        var months = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
        var days = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
        var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        return days[d.getDay()] + ' ' + parseInt(parts[2], 10) + ' ' + months[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
});
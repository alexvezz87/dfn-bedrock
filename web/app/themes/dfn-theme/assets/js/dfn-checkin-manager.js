/**
 * DFN Booking System 2.0 — Check-in Manager JavaScript
 *
 * Gestisce il tabellone check-in al banchetto.
 * Separato dallo Slot Manager (gestione prenotazioni).
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $wrapper = $('.dfn-checkin-manager-wrap');
        if (!$wrapper.length) {
            return;
        }

        var eventId   = parseInt($wrapper.data('event-id'), 10);
        var nonce     = $wrapper.data('nonce');
        var ajaxurl   = typeof dfnCheckinVars !== 'undefined' ? dfnCheckinVars.ajaxurl : '/wp/wp-admin/admin-ajax.php';
        var currentData = null;
        var activeDate  = $('.dfn-pill-date.active').data('date') || $wrapper.data('first-date');

        // Caricamento iniziale
        loadSlots(activeDate);

        // ====================================================================
        // 1. CARICAMENTO DATI
        // ====================================================================
        function loadSlots(date) {
            activeDate = date;
            var $grid = $('#dfn-ci-grid');
            $grid.html('<div class="dfn-loading"><span class="dashicons dashicons-update spin"></span> Caricamento in corso...</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_admin_get_slots',
                    event_id: eventId,
                    date: date || '',
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        currentData = response.data.slots;
                        renderCheckinView(currentData);
                    } else {
                        $grid.html('<div class="notice notice-error"><p>' + (response.data.message || 'Errore nel caricamento.') + '</p></div>');
                    }
                },
                error: function() {
                    $grid.html('<div class="notice notice-error"><p>Errore di rete durante il caricamento.</p></div>');
                }
            });
        }

        // Cambio data
        $(document).on('click', '.dfn-pill-date', function() {
            $('.dfn-pill-date').removeClass('active');
            $(this).addClass('active');
            loadSlots($(this).data('date'));
        });

        // Pulsante aggiorna
        $(document).on('click', '#dfn-ci-refresh', function() {
            loadSlots(activeDate);
        });

        // ====================================================================
        // 2. RENDERING INTERFACCIA
        // ====================================================================
        function renderCheckinView(slots) {
            if (!slots || slots.length === 0) {
                $('#dfn-ci-grid').html('<div class="dfn-empty-state"><span class="dashicons dashicons-calendar"></span><p>Nessun turno configurato per questa giornata.</p></div>');
                return;
            }

            var searchQuery = $('#dfn-ci-search').val().toLowerCase().trim();

            // Calcola totali aggregati per tutti gli slot
            var totVenduti = 0, totCheckin = 0, totCapacita = 0;
            slots.forEach(function(slot) {
                totVenduti    += slot.booked_count;
                totCapacita   += (slot.capacity + slot.bonus_capacity);
                slot.bookings.forEach(function(b) {
                    if (b.checkin_fatti) totCheckin += b.checkin_fatti;
                });
            });
            var totAttesa  = totVenduti - totCheckin;
            var totLiberi  = Math.max(0, totCapacita - totVenduti);

            var html = '';

            // Box Riepilogo Ingressi + Azioni globali
            html += '<div style="background:#fff; border-left:4px solid #004b23; padding:20px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border-radius:6px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">';
            html += '<div style="flex-grow:1;">';
            html += '<h2 style="margin-top:0; font-size:16px; font-weight:700; color:#1e293b;">&#128202; Riepilogo Ingressi</h2>';
            html += '<div style="display:flex; gap:15px; margin-top:15px; flex-wrap:wrap;">';
            html += '<div style="background:#f0f6fc; padding:10px 15px; border-radius:6px; border:1px solid #c8d7e1; min-width:100px;"><span style="font-size:11px; color:#555; text-transform:uppercase; font-weight:bold;">Venduti</span><br><strong style="font-size:24px; color:#2271b1;" id="cv-tot-venduti">' + totVenduti + '</strong></div>';
            html += '<div style="background:#eaf7ea; padding:10px 15px; border-radius:6px; border:1px solid #c3e6c3; min-width:100px;"><span style="font-size:11px; color:#555; text-transform:uppercase; font-weight:bold;">Entrati</span><br><strong style="color:#16a34a; font-size:24px;" id="cv-tot-checkin">' + totCheckin + '</strong></div>';
            html += '<div style="background:#fef2f2; padding:10px 15px; border-radius:6px; border:1px solid #fecaca; min-width:100px;"><span style="font-size:11px; color:#555; text-transform:uppercase; font-weight:bold;">In Attesa</span><br><strong style="color:#d63638; font-size:24px;" id="cv-tot-residui">' + totAttesa + '</strong></div>';
            html += '<div style="background:#fffbeb; padding:10px 15px; border-radius:6px; border:1px solid #fde68a; min-width:100px;"><span style="font-size:11px; color:#555; text-transform:uppercase; font-weight:bold;">Posti Liberi</span><br><strong style="color:#d97706; font-size:24px;" id="cv-tot-liberi">' + totLiberi + '</strong></div>';
            html += '</div>';
            html += '</div>';
            html += '<div style="text-align:right; border-left:1px solid #eee; padding-left:20px; min-width:220px; display:flex; flex-direction:column; gap:8px;">';
            html += '<button id="cv-send-reminders-btn" class="button button-primary" style="background:#ff6600; border-color:#ff6600; font-size:13px; padding:6px 12px; display:block; width:100%; color:#fff; font-weight:700; cursor:pointer;">&#128231; Invia Reminder a Tutti</button>';
            html += '<button id="cv-send-feedback-btn" class="button" style="background:#eab308; color:#fff; border-color:#d97706; font-size:13px; padding:6px 12px; display:block; width:100%; font-weight:700; cursor:pointer;">&#11088; Richiedi Recensioni</button>';
            html += '</div>';
            html += '</div>';

            // Per ogni slot: titolo (se multipli) + tabella prenotazioni
            slots.forEach(function(slot) {
                var filteredBookings = slot.bookings;
                if (searchQuery !== '') {
                    filteredBookings = slot.bookings.filter(function(b) {
                        var name  = b.customer_name  ? b.customer_name.toLowerCase()  : '';
                        var email = b.customer_email ? b.customer_email.toLowerCase() : '';
                        var phone = b.customer_phone ? b.customer_phone.toLowerCase() : '';
                        return name.indexOf(searchQuery) !== -1 ||
                               email.indexOf(searchQuery) !== -1 ||
                               phone.indexOf(searchQuery) !== -1;
                    });
                }

                if (slots.length > 1) {
                    html += '<div style="margin-bottom:6px; margin-top:24px; background:#004b23; color:#fff; padding:8px 14px; border-radius:6px; font-weight:700; font-size:13px;">';
                    html += '&#128336; Turno: ' + slot.time_start + ' - ' + slot.time_end + '  &nbsp;&bull;&nbsp;  Posti occupati: ' + slot.booked_count + ' / ' + (slot.capacity + slot.bonus_capacity);
                    html += '</div>';
                }

                html += generateCheckinTableHtml(filteredBookings, slot);
            });

            $('#dfn-ci-grid').html(html);
        }

        // Ricerca in tempo reale
        $(document).on('keyup input', '#dfn-ci-search', function() {
            if (currentData) renderCheckinView(currentData);
        });

        // ====================================================================
        // 3. TABELLA CHECK-IN
        // ====================================================================
        function generateCheckinTableHtml(bookings, slot) {
            var html = '<div style="overflow-x:auto; margin-bottom:20px;">';
            html += '<table class="wp-list-table widefat fixed striped dfn-bookings-rich-table" style="width:100%; border-collapse:collapse; border:1px solid #cbd5e1;">';
            html += '<thead><tr style="background:#f1f5f9;">' +
                '<th style="padding:10px; font-weight:700; width:80px;">Ordine #</th>' +
                '<th style="padding:10px; font-weight:700;">Cliente</th>' +
                '<th style="padding:10px; font-weight:700; width:120px;">Qualifica</th>' +
                '<th style="padding:10px; font-weight:700; width:120px;">Telefono</th>' +
                '<th style="padding:10px; font-weight:700; width:80px; text-align:center;">Biglietti</th>' +
                '<th style="padding:10px; font-weight:700; width:130px; text-align:center;">Stato Arrivi</th>' +
                '<th style="padding:10px; font-weight:700; width:150px;">Validato da</th>' +
                '<th style="padding:10px; font-weight:700; width:160px; background:#eaf7ea; text-align:center;">Azioni Cassa</th>' +
                '<th style="padding:10px; font-weight:700; width:160px; background:#eef6fc; text-align:center;">Messaggi</th>' +
                '<th style="padding:10px; font-weight:700; width:80px; background:#f6f7f7; text-align:center;">Storico</th>' +
            '</tr></thead>';
            html += '<tbody>';

            if (!bookings || bookings.length === 0) {
                html += '<tr><td colspan="10" style="padding:30px; text-align:center; color:#64748b;">Nessuna prenotazione trovata.</td></tr>';
            } else {
                bookings.forEach(function(b) {
                    var orderEditUrl = ajaxurl.replace('admin-ajax.php', 'post.php?post=' + b.order_id + '&action=edit');
                    var orderLink    = b.order_id > 0 ? '<a href="' + orderEditUrl + '" target="_blank"><strong>#' + b.order_id + '</strong></a>' : '-';
                    var telefonoLink = b.customer_phone ? '<a href="tel:' + b.customer_phone + '">' + b.customer_phone + '</a>' : '-';

                    // Stato Arrivi Badge
                    var statoBadge;
                    if (b.checkin_fatti === 0) {
                        statoBadge = '<span style="color:white; background:#d63638; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:12px; white-space:nowrap;">0 / ' + b.slot_persons + '</span>';
                    } else if (b.checkin_fatti < b.slot_persons) {
                        statoBadge = '<span style="color:white; background:#f59e0b; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:12px; white-space:nowrap;">' + b.checkin_fatti + ' / ' + b.slot_persons + '</span>';
                    } else {
                        statoBadge = '<span style="color:white; background:#16a34a; padding:4px 8px; border-radius:4px; font-weight:bold; font-size:12px; white-space:nowrap;">Completo (' + b.slot_persons + ')</span>';
                    }

                    // Azioni Cassa
                    var azioniCassaBtn = b.checkin_fatti < b.slot_persons
                        ? '<button type="button" class="button cv-open-popup-btn" data-cliente="' + b.customer_name + '" style="width:100%; border-color:#00a32a; color:#00a32a; cursor:pointer;">&#127967; Gestisci Ingressi</button>'
                        : '<button type="button" class="button cv-open-popup-btn" data-cliente="' + b.customer_name + '" style="width:100%; font-size:11px; cursor:pointer;">&#128269; Modifica validazioni</button>';
                    var azioniCassaHtml = '<div style="position:relative;">' + azioniCassaBtn + (b.html_bottoni_popup || '') + '</div>';

                    // Messaggi
                    var btnReminder = '<button type="button" class="button cv-single-reminder-btn" data-order="' + b.order_id + '" style="font-size:11px; padding:2px 8px; width:100%; margin-bottom:4px; cursor:pointer;">' + (b.reminder_sent ? '&#128231; Reinvia Reminder' : '&#128231; Invia Reminder') + '</button>';
                    var btnFeedback = '<button type="button" class="button cv-single-feedback-btn" data-order="' + b.order_id + '" style="font-size:11px; padding:2px 8px; width:100%; cursor:pointer;">' + (b.feedback_sent ? '&#11088; Reinvia Recensione' : '&#11088; Chiedi Recensione') + '</button>';

                    // Storico
                    var storicoHtml = '<button type="button" class="button cv-open-history-btn" data-cliente="' + b.customer_name + '" style="cursor:pointer;">&#128220; Log</button>' + (b.html_history_popup || '');

                    html += '<tr data-booking-id="' + b.id + '" data-slot-id="' + slot.id + '">' +
                        '<td style="padding:10px; vertical-align:middle;">' + orderLink + '</td>' +
                        '<td style="padding:10px; vertical-align:middle;"><div style="font-weight:700;">' + b.customer_name + '</div><div style="font-size:11px; color:#64748b;">' + (b.customer_email !== 'no-email@dfn.it' ? b.customer_email : '') + '</div></td>' +
                        '<td style="padding:10px; vertical-align:middle;">' + (b.qualifica_html || '') + '</td>' +
                        '<td style="padding:10px; vertical-align:middle;">' + telefonoLink + '</td>' +
                        '<td style="padding:10px; vertical-align:middle; text-align:center; font-weight:700;">' + b.slot_persons + '</td>' +
                        '<td style="padding:10px; vertical-align:middle; text-align:center;">' + statoBadge + '</td>' +
                        '<td style="padding:10px; vertical-align:middle; font-size:12px;">' + (b.operatori_html || '-') + '</td>' +
                        '<td style="padding:10px; vertical-align:middle; background:#f0fdf4; text-align:center;">' + azioniCassaHtml + '</td>' +
                        '<td style="padding:10px; vertical-align:middle; background:#f0f9ff; text-align:center;">' + btnReminder + btnFeedback + '</td>' +
                        '<td style="padding:10px; vertical-align:middle; background:#fafafa; text-align:center;">' + storicoHtml + '</td>' +
                    '</tr>';
                });
            }

            html += '</tbody></table></div>';
            return html;
        }

        // ====================================================================
        // 4. CASSA CHECK-IN HANDLERS
        // ====================================================================
        var needsReload = false;

        $(document).on('click', '.cv-open-popup-btn', function(e) {
            e.preventDefault();
            needsReload = false;
            $('#cv-modal-cliente-name').text($(this).data('cliente'));
            $('#cv-modal-buttons-area').html($(this).siblings('.cv-popup-data-container').html());
            $('#cv-cassa-modal').css('display', 'flex');
        });

        $(document).on('click', '.cv-open-history-btn', function(e) {
            e.preventDefault();
            $('#cv-history-cliente-name').text($(this).data('cliente'));
            $('#cv-history-content-area').html($(this).siblings('.cv-history-data-container').html());
            $('#cv-history-modal').css('display', 'flex');
        });

        function closeReportModals() {
            $('#cv-cassa-modal, #cv-history-modal').hide();
            if (needsReload) {
                loadSlots(activeDate);
            }
            $('#cv-cassa-modal .cv-close-modal-btn').text('Chiudi Finestra');
        }

        $(document).on('click', '.cv-close-modal-btn', closeReportModals);
        $('#cv-cassa-modal, #cv-history-modal').on('click', function(e) {
            if (e.target === this) closeReportModals();
        });

        $(document).on('click', '.cv-manual-checkin-btn', function(e) {
            e.preventDefault();
            var btn = $(this);
            var orderId   = btn.data('order');
            var ticketIdx = btn.data('ticket');
            btn.prop('disabled', true).css('opacity', '0.5').text('&#9203; Elaborazione...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action:   'cv_process_manual_checkin',
                    security: dfnCheckinVars.nonceManual,
                    order_id: orderId,
                    ticket:   ticketIdx
                },
                success: function(response) {
                    if (response.success) {
                        needsReload = true;
                        var successHtml = '<div style="margin-bottom:8px; padding:10px; background:#eaf7ea; color:#166534; border:1px solid #c3e6c3; border-radius:4px; display:flex; justify-content:space-between; align-items:center;"><span>&#9989; Biglietto ' + ticketIdx + ' validato</span><button class="button cv-undo-checkin-btn" data-order="' + orderId + '" data-ticket="' + ticketIdx + '" style="color:#d63638; border-color:#d63638; padding:0 8px; min-height:26px; line-height:24px;">Annulla</button></div>';
                        btn.replaceWith(successHtml);
                        $('#cv-cassa-modal .cv-close-modal-btn').text('&#128260; Chiudi e Aggiorna Tabella');
                    } else {
                        alert('Errore: ' + response.data);
                        btn.prop('disabled', false).css('opacity', '1').text('&#10004;&#65039; Valida Biglietto ' + ticketIdx);
                    }
                },
                error: function() {
                    alert('Errore di rete durante la validazione.');
                    btn.prop('disabled', false).css('opacity', '1').text('&#10004;&#65039; Valida Biglietto ' + ticketIdx);
                }
            });
        });

        $(document).on('click', '.cv-undo-checkin-btn', function(e) {
            e.preventDefault();
            if (!confirm('Vuoi davvero annullare la validazione di questo biglietto?')) return;
            var btn = $(this);
            var orderId   = btn.data('order');
            var ticketIdx = btn.data('ticket');
            var wrapper   = btn.closest('div');
            btn.prop('disabled', true).text('&#9203;...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action:   'cv_process_undo_checkin',
                    security: dfnCheckinVars.nonceManual,
                    order_id: orderId,
                    ticket:   ticketIdx
                },
                success: function(response) {
                    if (response.success) {
                        needsReload = true;
                        wrapper.replaceWith('<button class="button cv-manual-checkin-btn" data-order="' + orderId + '" data-ticket="' + ticketIdx + '" style="margin-bottom:8px; display:block; width:100%; border-color:#00a32a; color:#00a32a; height:40px; cursor:pointer;">&#10004;&#65039; Valida Biglietto ' + ticketIdx + '</button>');
                        $('#cv-cassa-modal .cv-close-modal-btn').text('&#128260; Chiudi e Aggiorna Tabella');
                    } else {
                        alert('Errore: ' + response.data);
                        btn.prop('disabled', false).text('Annulla');
                    }
                },
                error: function() {
                    alert('Errore di rete.');
                    btn.prop('disabled', false).text('Annulla');
                }
            });
        });

        // ====================================================================
        // 5. MESSAGGI: REMINDER & FEEDBACK
        // ====================================================================
        $(document).on('click', '.cv-single-reminder-btn', function(e) {
            e.preventDefault();
            var btn = $(this);
            var orderId = btn.data('order');
            btn.prop('disabled', true).text('&#9203;...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'cv_send_single_reminder', security: dfnCheckinVars.nonceReminder, order_id: orderId },
                success: function(response) {
                    if (response.success) {
                        alert('&#9989; Promemoria inviato con successo!');
                        btn.prop('disabled', false).text('&#128231; Reinvia Reminder');
                    } else {
                        alert('&#10060; Errore: ' + response.data);
                        btn.prop('disabled', false).text('&#128231; Invia Reminder');
                    }
                },
                error: function() { alert('&#10060; Errore di rete.'); btn.prop('disabled', false).text('&#128231; Invia Reminder'); }
            });
        });

        $(document).on('click', '.cv-single-feedback-btn', function(e) {
            e.preventDefault();
            var btn = $(this);
            var orderId = btn.data('order');
            btn.prop('disabled', true).text('&#9203;...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'cv_send_single_feedback', security: dfnCheckinVars.nonceFeedback, order_id: orderId },
                success: function(response) {
                    if (response.success) {
                        alert('&#9989; Richiesta recensione inviata!');
                        btn.prop('disabled', false).text('&#11088; Reinvia Recensione');
                    } else {
                        alert('&#10060; Errore: ' + response.data);
                        btn.prop('disabled', false).text('&#11088; Chiedi Recensione');
                    }
                },
                error: function() { alert('&#10060; Errore di rete.'); btn.prop('disabled', false).text('&#11088; Chiedi Recensione'); }
            });
        });

        // Reminder Globale
        $(document).on('click', '#cv-send-reminders-btn', function(e) {
            e.preventDefault();
            if (!confirm('Sei sicuro di voler inviare il promemoria a tutti gli acquirenti?')) return;
            var btn = $(this);
            var originalText = btn.text();
            btn.prop('disabled', true);
            var totalSent = 0;

            function inviaLotto() {
                btn.text('&#9203; Invio in corso (' + totalSent + ' inviate)...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'cv_send_event_reminders', security: dfnCheckinVars.nonceReminder, event_id: eventId },
                    success: function(response) {
                        if (response.success) {
                            totalSent += response.data.sent;
                            if (response.data.has_more) { inviaLotto(); }
                            else {
                                alert(totalSent > 0 ? '&#9989; Inviate ' + totalSent + ' email.' : '&#9989; Nessuna email inviata.');
                                btn.prop('disabled', false).text(originalText);
                                loadSlots(activeDate);
                            }
                        } else { alert('&#10060; Errore: ' + response.data); btn.prop('disabled', false).text(originalText); }
                    },
                    error: function() { alert('&#10060; Errore di rete.'); btn.prop('disabled', false).text(originalText); }
                });
            }
            inviaLotto();
        });

        // Feedback Globale
        $(document).on('click', '#cv-send-feedback-btn', function(e) {
            e.preventDefault();
            if (!confirm('Vuoi inviare la richiesta di recensione a tutti i partecipanti verificati?')) return;
            var btn = $(this);
            var originalText = btn.text();
            btn.prop('disabled', true);
            var totalSent = 0;

            function inviaLottoFeedback() {
                btn.text('&#9203; Invio in corso (' + totalSent + ' inviate)...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'cv_send_feedback_request', security: dfnCheckinVars.nonceFeedback, event_id: eventId },
                    success: function(response) {
                        if (response.success) {
                            totalSent += response.data.sent;
                            if (response.data.has_more) { inviaLottoFeedback(); }
                            else {
                                alert(totalSent > 0 ? '&#9989; Inviate ' + totalSent + ' email.' : '&#9989; Nessuna email inviata.');
                                btn.prop('disabled', false).text(originalText);
                                loadSlots(activeDate);
                            }
                        } else { alert('&#10060; Errore: ' + response.data); btn.prop('disabled', false).text(originalText); }
                    },
                    error: function() { alert('&#10060; Errore di rete.'); btn.prop('disabled', false).text(originalText); }
                });
            }
            inviaLottoFeedback();
        });

    });
})(jQuery);

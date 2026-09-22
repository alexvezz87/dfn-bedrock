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
        var activeStatus = 'all'; // 'all', 'pending', 'partial', 'completed'
        var sortCol      = 'date'; // 'date', 'order', 'customer', 'tickets', 'status'
        var sortDir      = 'desc'; // Default: più recente in alto

        // Variabili statistiche & gamification
        var flowInterval = 1; // 1, 2, 5 minuti
        var flowChartInstance = null;
        var autoRefreshTimer = null;
        var autoRefreshActive = false;

        // Ripristino stato collassato pannello
        if (localStorage.getItem('dfn_ci_panel_collapsed') === 'true') {
            $('#dfn-analytics-panel').addClass('is-collapsed');
            $('#dfn-toggle-analytics-panel .dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            $('#dfn-toggle-analytics-panel .toggle-text').text('Espandi');
        }

        // Helper: rendering intestazione ordinabile
        function renderSortableTh(colKey, label, currentCol, currentDir, extraStyle, alignCenter) {
            var isCurrent = (currentCol === colKey);
            var icon = '';
            if (isCurrent) {
                icon = (currentDir === 'asc')
                    ? '<span class="dashicons dashicons-arrow-up-alt2" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-left:3px;"></span>'
                    : '<span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-left:3px;"></span>';
            } else {
                icon = '<span class="dashicons dashicons-sort" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-left:3px; opacity:0.35;"></span>';
            }
            var alignStyle = alignCenter ? 'text-align:center;' : 'text-align:left;';
            return '<th class="dfn-ci-sortable-th" data-col="' + colKey + '" style="padding:12px 10px; font-weight:700; ' + (extraStyle || '') + ' ' + alignStyle + '">' +
                label + ' ' + icon +
            '</th>';
        }

        // Helper: ordinamento prenotazioni
        function sortBookings(items, col, dir) {
            return items.slice().sort(function(a, b) {
                var valA, valB;
                if (col === 'order') {
                    valA = parseInt(a.order_id || a.id, 10) || 0;
                    valB = parseInt(b.order_id || b.id, 10) || 0;
                    return dir === 'asc' ? (valA - valB) : (valB - valA);
                } else if (col === 'customer') {
                    valA = (a.customer_name || '').toLowerCase();
                    valB = (b.customer_name || '').toLowerCase();
                    return dir === 'asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
                } else if (col === 'tickets') {
                    valA = parseInt(a.slot_persons || a.total_persons, 10) || 0;
                    valB = parseInt(b.slot_persons || b.total_persons, 10) || 0;
                    return dir === 'asc' ? (valA - valB) : (valB - valA);
                } else if (col === 'status') {
                    var pA = parseInt(a.slot_persons || a.total_persons, 10) || 1;
                    var pB = parseInt(b.slot_persons || b.total_persons, 10) || 1;
                    valA = (a.checkin_fatti || 0) / pA;
                    valB = (b.checkin_fatti || 0) / pB;
                    return dir === 'asc' ? (valA - valB) : (valB - valA);
                } else {
                    // Default: 'date' (timestamp registrazione più recente in alto)
                    valA = parseInt(a.timestamp, 10) || 0;
                    valB = parseInt(b.timestamp, 10) || 0;
                    if (valA !== valB) {
                        return dir === 'asc' ? (valA - valB) : (valB - valA);
                    }
                    var idA = parseInt(a.order_id || a.id, 10) || 0;
                    var idB = parseInt(b.order_id || b.id, 10) || 0;
                    return dir === 'asc' ? (idA - idB) : (idB - idA);
                }
            });
        }

        // Caricamento iniziale
        loadSlots(activeDate);

        // ====================================================================
        // 1. CARICAMENTO DATI
        // ====================================================================
        function loadSlots(date, isSilent) {
            activeDate = date;
            var $grid = $('#dfn-ci-grid');
            if (!isSilent) {
                $grid.html('<div class="dfn-loading"><span class="dashicons dashicons-update spin"></span> Caricamento in corso...</div>');
            }

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
                        if (!isSilent) {
                            $grid.html('<div class="notice notice-error"><p>' + (response.data.message || 'Errore nel caricamento.') + '</p></div>');
                        }
                    }
                },
                error: function() {
                    if (!isSilent) {
                        $grid.html('<div class="notice notice-error"><p>Errore di rete durante il caricamento.</p></div>');
                    }
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

        // Click su pillola stato ingressi
        $(document).on('click', '.dfn-ci-status-pill', function() {
            var status = $(this).data('status');
            activeStatus = status;
            $('.dfn-ci-status-pill').removeClass('active');
            $(this).addClass('active');
            if (currentData) renderCheckinView(currentData);
        });

        // Click rapido su card statistica in alto
        $(document).on('click', '.dfn-stat-card-clickable', function() {
            var targetStatus = $(this).data('status-target');
            if (targetStatus) {
                activeStatus = targetStatus;
                $('.dfn-ci-status-pill').removeClass('active');
                $('.dfn-ci-status-pill[data-status="' + targetStatus + '"]').addClass('active');
                if (currentData) renderCheckinView(currentData);
            }
        });

        // Click su intestazione ordinabile della tabella
        $(document).on('click', '.dfn-ci-sortable-th', function() {
            var col = $(this).data('col');
            if (sortCol === col) {
                sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
            } else {
                sortCol = col;
                sortDir = (col === 'order' || col === 'tickets' || col === 'date') ? 'desc' : 'asc';
            }
            if (currentData) renderCheckinView(currentData);
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
            var countAll = 0, countPending = 0, countPartial = 0, countCompleted = 0;
            var persPending = 0;

            slots.forEach(function(slot) {
                totVenduti    += slot.booked_count;
                totCapacita   += (slot.capacity + slot.bonus_capacity);
                slot.bookings.forEach(function(b) {
                    var nPers = parseInt(b.slot_persons || b.total_persons, 10) || 1;
                    var nCheck = parseInt(b.checkin_fatti, 10) || 0;

                    if (nCheck > 0) totCheckin += nCheck;
                    countAll++;

                    if (nCheck === 0) {
                        countPending++;
                        persPending += nPers;
                    } else if (nCheck < nPers) {
                        countPartial++;
                        persPending += (nPers - nCheck);
                    } else {
                        countCompleted++;
                    }
                });
            });
            var totAttesa  = Math.max(0, totVenduti - totCheckin);
            var totLiberi  = Math.max(0, totCapacita - totVenduti);

            // Aggiorna contatori statistici in alto
            $('#dfn-ci-stat-venduti').text(totVenduti);
            $('#dfn-ci-stat-entrati').text(totCheckin);
            $('#dfn-ci-stat-attesa').text(totAttesa);
            $('#dfn-ci-stat-liberi').text(totLiberi);

            // Aggiorna grafico flusso ingressi e gamification volontari
            updateFlowChartAndGamification(slots);

            // Aggiorna badge pillole filtri
            $('#dfn-ci-count-all').text(countAll);
            $('#dfn-ci-count-pending').text(countPending);
            $('#dfn-ci-count-partial').text(countPartial);
            $('#dfn-ci-count-completed').text(countCompleted);

            // Evidenziazione sincronizzata card statistiche
            $('.dfn-stat-card-clickable').removeClass('active-filter');
            if (activeStatus === 'all') {
                $('.dfn-stat-card-clickable[data-status-target="all"]').addClass('active-filter');
            } else if (activeStatus === 'pending') {
                $('.dfn-stat-card-clickable[data-status-target="pending"]').addClass('active-filter');
            } else if (activeStatus === 'completed') {
                $('.dfn-stat-card-clickable[data-status-target="completed"]').addClass('active-filter');
            }

            // Info testo descrittivo
            var infoText = '';
            if (activeStatus === 'pending') {
                infoText = '🔍 Visualizzando <strong>' + countPending + '</strong> prenotazioni ancora da validare (<strong>' + persPending + '</strong> persone in attesa)';
            } else if (activeStatus === 'partial') {
                infoText = '🔍 Visualizzando <strong>' + countPartial + '</strong> prenotazioni con ingressi parziali';
            } else if (activeStatus === 'completed') {
                infoText = '🔍 Visualizzando <strong>' + countCompleted + '</strong> prenotazioni convalidate al 100% (' + totCheckin + ' persone entrate)';
            } else {
                infoText = 'Mostrando tutte le <strong>' + countAll + '</strong> prenotazioni (' + totVenduti + ' posti)';
            }
            $('#dfn-ci-filter-status-info').html(infoText);

            var html = '';

            // Per ogni slot: titolo card con progress bar + tabella
            slots.forEach(function(slot) {
                var filteredBookings = slot.bookings;

                // 1. Filtro per stato check-in
                if (activeStatus === 'pending') {
                    filteredBookings = filteredBookings.filter(function(b) {
                        return !b.checkin_fatti || b.checkin_fatti === 0;
                    });
                } else if (activeStatus === 'partial') {
                    filteredBookings = filteredBookings.filter(function(b) {
                        return b.checkin_fatti > 0 && b.checkin_fatti < b.slot_persons;
                    });
                } else if (activeStatus === 'completed') {
                    filteredBookings = filteredBookings.filter(function(b) {
                        return b.checkin_fatti >= b.slot_persons;
                    });
                }

                // 2. Filtro per ricerca testuale
                if (searchQuery !== '') {
                    filteredBookings = filteredBookings.filter(function(b) {
                        var name  = b.customer_name  ? b.customer_name.toLowerCase()  : '';
                        var email = b.customer_email ? b.customer_email.toLowerCase() : '';
                        var phone = b.customer_phone ? b.customer_phone.toLowerCase() : '';
                        var order = b.order_id       ? b.order_id.toString()          : '';
                        return name.indexOf(searchQuery) !== -1 ||
                               email.indexOf(searchQuery) !== -1 ||
                               phone.indexOf(searchQuery) !== -1 ||
                               order.indexOf(searchQuery) !== -1;
                    });
                }

                // 3. Ordinamento
                var sortedBookings = sortBookings(filteredBookings, sortCol, sortDir);

                var totalCapacity = slot.capacity + slot.bonus_capacity;
                var booked = slot.booked_count;
                var percent = totalCapacity > 0 ? Math.min(100, Math.round((booked / totalCapacity) * 100)) : 0;

                var filterBadge = '';
                if (activeStatus === 'pending') {
                    filterBadge = ' &bull; <span style="color:#dc2626; font-weight:700;">Filtro: Da validare (' + sortedBookings.length + ')</span>';
                } else if (activeStatus === 'partial') {
                    filterBadge = ' &bull; <span style="color:#d97706; font-weight:700;">Filtro: In corso (' + sortedBookings.length + ')</span>';
                } else if (activeStatus === 'completed') {
                    filterBadge = ' &bull; <span style="color:#15803d; font-weight:700;">Filtro: Validate (' + sortedBookings.length + ')</span>';
                }

                var headerHtml =
                    '<div class="dfn-slot-header-card" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:8px; padding:15px 20px; margin-bottom:15px; margin-top:20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">' +
                        '<div class="slot-title-info" style="margin-bottom: 10px;">' +
                            '<h3 style="margin:0; font-size:15px; font-weight:700; color:#004b23;"><span class="dashicons dashicons-clock" style="font-size:18px; width:18px; height:18px; vertical-align:middle; margin-right:5px;"></span> ' + (slot.label || 'Turno ' + slot.time_start + ' - ' + slot.time_end) + '</h3>' +
                        '</div>' +
                        '<div class="slot-progress-info">' +
                            '<div class="progress-labels" style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:6px; color:#64748b;">' +
                                '<span>' + (slot.is_locked ? 'Bloccato' : percent + '% occupato') + '</span>' +
                                '<span><strong>' + booked + '</strong> / ' + totalCapacity + ' posti &bull; ' + sortedBookings.length + ' visualizzate' + filterBadge + '</span>' +
                            '</div>' +
                            '<div class="progress-bar-bg" style="height:8px; background:#f1f5f9; border-radius:9999px; overflow:hidden;">' +
                                '<div class="progress-bar-fill" style="height:100%; border-radius:9999px; background:linear-gradient(90deg, #16a34a, #4ade80); width:' + percent + '%;"></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';

                html += headerHtml;
                html += generateCheckinTableHtml(sortedBookings, slot);
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
            var html = '<div style="overflow-x:auto; margin-bottom:30px;">';
            html += '<table class="wp-list-table widefat fixed striped dfn-bookings-rich-table" style="width:100%; border-collapse:collapse; border:1px solid #cbd5e1;">';
            html += '<thead><tr style="background:#f1f5f9;">' +
                renderSortableTh('order', 'Ordine #', sortCol, sortDir, 'width:85px;', false) +
                renderSortableTh('customer', 'Cliente', sortCol, sortDir, '', false) +
                '<th style="padding:12px 10px; font-weight:700; width:120px; text-align:left;">Qualifica</th>' +
                '<th style="padding:12px 10px; font-weight:700; width:130px; text-align:left;">Telefono</th>' +
                renderSortableTh('tickets', 'Biglietti', sortCol, sortDir, 'width:80px;', true) +
                renderSortableTh('status', 'Stato Arrivi', sortCol, sortDir, 'width:130px;', true) +
                '<th style="padding:12px 10px; font-weight:700; width:140px; text-align:left;">Validato da</th>' +
                '<th style="padding:12px 10px; font-weight:700; width:160px; text-align:center;">Azioni Cassa</th>' +
                '<th style="padding:12px 10px; font-weight:700; width:160px; text-align:center;">Messaggi</th>' +
                '<th style="padding:12px 10px; font-weight:700; width:80px; text-align:center;">Storico</th>' +
            '</tr></thead>';
            html += '<tbody>';

            if (!bookings || bookings.length === 0) {
                var searchQuery = $('#dfn-ci-search').val().trim();
                var emptyMsg = 'Nessuna prenotazione trovata.';
                if (searchQuery !== '') {
                    emptyMsg = 'Nessuna prenotazione trovata per "<strong>' + searchQuery + '</strong>".';
                } else if (activeStatus === 'pending') {
                    emptyMsg = '🎉 <strong>Tutti i partecipanti sono già entrati!</strong> Nessuna prenotazione in attesa di validazione.';
                } else if (activeStatus === 'partial') {
                    emptyMsg = 'Nessun gruppo con ingressi parziali in questo momento.';
                } else if (activeStatus === 'completed') {
                    emptyMsg = 'Nessuna prenotazione convalidata al 100% per ora.';
                }
                html += '<tr><td colspan="10" style="padding:30px; text-align:center; color:#64748b; font-size:14px;">' + emptyMsg + '</td></tr>';
            } else {
                bookings.forEach(function(b) {
                    var orderEditUrl = ajaxurl.replace('admin-ajax.php', 'post.php?post=' + b.order_id + '&action=edit');
                    var orderLink    = b.order_id > 0 ? '<a href="' + orderEditUrl + '" target="_blank"><strong>#' + b.order_id + '</strong></a>' : '-';
                    var telefonoLink = b.customer_phone ? '<a href="tel:' + b.customer_phone + '">' + b.customer_phone + '</a>' : '-';

                    // Stato Arrivi Badge
                    var statoBadge;
                    if (b.checkin_fatti === 0) {
                        statoBadge = '<span style="background:#fef2f2; color:#991b1b; font-size:11px; padding:3px 8px; border-radius:10px; font-weight:700; border:1px solid #fecaca; white-space:nowrap;">0 / ' + b.slot_persons + '</span>';
                    } else if (b.checkin_fatti < b.slot_persons) {
                        statoBadge = '<span style="background:#fffbeb; color:#d97706; font-size:11px; padding:3px 8px; border-radius:10px; font-weight:700; border:1px solid #fde68a; white-space:nowrap;">' + b.checkin_fatti + ' / ' + b.slot_persons + '</span>';
                    } else {
                        statoBadge = '<span style="background:#dcfce7; color:#166534; font-size:11px; padding:3px 8px; border-radius:10px; font-weight:700; border:1px solid #c3e6c3; white-space:nowrap;">Completo (' + b.slot_persons + ')</span>';
                    }

                    // Azioni Cassa
                    var azioniCassaBtn = b.checkin_fatti < b.slot_persons
                        ? '<button type="button" class="dfn-btn dfn-btn-secondary cv-open-popup-btn" data-cliente="' + b.customer_name + '" style="font-size:11px; padding:4px 8px; border-color:#16a34a; color:#166534; font-weight:700; width:100%; display:inline-flex; justify-content:center; gap:4px; height:32px; line-height:24px;"><span class="dashicons dashicons-tickets-alt" style="font-size:14px; width:14px; height:14px; margin-top:2px;"></span> Gestisci Ingressi</button>'
                        : '<button type="button" class="dfn-btn dfn-btn-secondary cv-open-popup-btn" data-cliente="' + b.customer_name + '" style="font-size:11px; padding:4px 8px; border-color:#cbd5e1; color:#475569; width:100%; display:inline-flex; justify-content:center; gap:4px; height:32px; line-height:24px;"><span class="dashicons dashicons-search" style="font-size:14px; width:14px; height:14px; margin-top:2px;"></span> Modifica</button>';
                    var azioniCassaHtml = '<div style="position:relative;">' + azioniCassaBtn + (b.html_bottoni_popup || '') + '</div>';

                    // Messaggi
                    var btnReminder = '<button type="button" class="dfn-btn dfn-btn-secondary cv-single-reminder-btn" data-order="' + b.order_id + '" style="font-size:10px; padding:2px 6px; width:100%; margin-bottom:4px; border-color:#2271b1; color:#2271b1; display:inline-flex; justify-content:center; gap:2px; height:26px; line-height:20px; font-weight:600;"><span class="dashicons dashicons-email" style="font-size:12px; width:12px; height:12px; margin-top:1px;"></span> ' + (b.reminder_sent ? 'Reinvia Rem.' : 'Invia Rem.') + '</button>';
                    var btnFeedback = '<button type="button" class="dfn-btn dfn-btn-secondary cv-single-feedback-btn" data-order="' + b.order_id + '" style="font-size:10px; padding:2px 6px; width:100%; border-color:#d97706; color:#d97706; display:inline-flex; justify-content:center; gap:2px; height:26px; line-height:20px; font-weight:600;"><span class="dashicons dashicons-star-filled" style="font-size:12px; width:12px; height:12px; margin-top:1px;"></span> ' + (b.feedback_sent ? 'Reinvia Rec.' : 'Chiedi Rec.') + '</button>';

                    // Storico
                    var storicoHtml = '<button type="button" class="dfn-btn dfn-btn-secondary cv-open-history-btn" data-cliente="' + b.customer_name + '" style="font-size:11px; padding:4px 8px; border-color:#cbd5e1; color:#475569; display:inline-flex; justify-content:center; gap:4px; height:32px; line-height:24px; width:100%;"><span class="dashicons dashicons-editor-ul" style="font-size:14px; width:14px; height:14px; margin-top:2px;"></span> Log</button>' + (b.html_history_popup || '');

                    html += '<tr class="dfn-slot-booking-row" data-booking-id="' + b.id + '" data-slot-id="' + slot.id + '">' +
                        '<td style="padding:12px 10px; vertical-align:middle;">' + orderLink + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle;"><div style="font-weight:700;">' + b.customer_name + '</div><div style="font-size:11px; color:#64748b;">' + (b.customer_email !== 'no-email@dfn.it' ? b.customer_email : '') + '</div></td>' +
                        '<td style="padding:12px 10px; vertical-align:middle;">' + (b.qualifica_html || '') + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle;">' + telefonoLink + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle; text-align:center; font-weight:700;">' + b.slot_persons + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle; text-align:center;">' + statoBadge + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle; font-size:12px;">' + (b.operatori_html || '-') + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle; text-align:center;">' + azioniCassaHtml + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle; text-align:center;">' + btnReminder + btnFeedback + '</td>' +
                        '<td style="padding:12px 10px; vertical-align:middle; text-align:center;">' + storicoHtml + '</td>' +
                    '</tr>';
                });
            }

            html += '</tbody></table></div>';
            return html;
        }

        // ====================================================================
        // 3. ANALYTICS & GAMIFICATION (GRAFICO FLUSSO & PODIO VOLONTARI)
        // ====================================================================
        function updateFlowChartAndGamification(slots) {
            var allValidations = [];
            if (slots && Array.isArray(slots)) {
                slots.forEach(function(slot) {
                    if (slot.bookings && Array.isArray(slot.bookings)) {
                        slot.bookings.forEach(function(b) {
                            if (b.validations && Array.isArray(b.validations)) {
                                b.validations.forEach(function(v) {
                                    allValidations.push(v);
                                });
                            }
                        });
                    }
                });
            }

            // 1. Gamification Volontari (Podio e Classifica)
            renderVolunteerGamification(allValidations);

            // 2. Grafico Afflusso (Smaltimento Coda per minuto)
            renderFlowChart(allValidations);
        }

        function renderVolunteerGamification(allValidations) {
            var totalValidations = allValidations.length;
            $('#dfn-team-validations-count').text(totalValidations);

            var opCounts = {};
            allValidations.forEach(function(v) {
                var op = (v.operator || 'Staff').trim();
                opCounts[op] = (opCounts[op] || 0) + 1;
            });

            var opList = [];
            for (var opName in opCounts) {
                opList.push({ name: opName, count: opCounts[opName] });
            }
            opList.sort(function(a, b) {
                return b.count - a.count;
            });

            $('#dfn-leaderboard-active-staff-count').text(opList.length + (opList.length === 1 ? ' volontario' : ' volontari'));

            var $podium = $('#dfn-podium-area');
            var $lbList = $('#dfn-leaderboard-list');

            if (opList.length === 0) {
                $podium.html('<div style="color:#94a3b8; font-size:13px; text-align:center; padding:35px 10px; width:100%;"><span class="dashicons dashicons-awards" style="font-size:32px; width:32px; height:32px; opacity:0.4; margin-bottom:6px;"></span><p style="margin:0;">Nessun check-in ancora registrato.<br><small>Il podio si animerà non appena inizieranno le validazioni!</small></p></div>');
                $lbList.html('<p style="color:#94a3b8; font-size:12px; text-align:center; margin:10px 0; font-style:italic;">In attesa di convalide...</p>');
                return;
            }

            function getInitials(name) {
                var parts = name.split(' ');
                if (parts.length >= 2 && parts[1]) {
                    return (parts[0][0] + parts[1][0]).toUpperCase();
                }
                return (name.substring(0, 2) || 'ST').toUpperCase();
            }

            function makePodiumCol(posClass, rank, medal, role, opData) {
                if (!opData) {
                    return '<div class="dfn-podium-col ' + posClass + '" style="opacity:0.35;">' +
                        '<div class="dfn-podium-avatar-wrap"><div class="dfn-podium-avatar">-</div></div>' +
                        '<div class="dfn-podium-name">-</div>' +
                        '<div class="dfn-podium-role-badge">' + role + '</div>' +
                        '<div class="dfn-podium-step"><div class="dfn-podium-rank">' + medal + '</div><div class="dfn-podium-count">0</div></div>' +
                    '</div>';
                }
                var pct = totalValidations > 0 ? Math.round((opData.count / totalValidations) * 100) : 0;
                var crown = (rank === 1) ? '<div class="dfn-podium-crown">👑</div>' : '';
                return '<div class="dfn-podium-col ' + posClass + '">' +
                    '<div class="dfn-podium-avatar-wrap">' +
                        crown +
                        '<div class="dfn-podium-avatar">' + getInitials(opData.name) + '</div>' +
                    '</div>' +
                    '<div class="dfn-podium-name" title="' + opData.name + '">' + opData.name + '</div>' +
                    '<div class="dfn-podium-role-badge">' + role + '</div>' +
                    '<div class="dfn-podium-step">' +
                        '<div class="dfn-podium-rank">' + medal + '</div>' +
                        '<div class="dfn-podium-count">' + opData.count + ' <small style="font-size:10px; font-weight:normal;">check-in</small></div>' +
                        '<div class="dfn-podium-pct">' + pct + '% del totale</div>' +
                    '</div>' +
                '</div>';
            }

            // Costruzione Podio Olimpico: 2° (sinistra), 1° (centro), 3° (destra)
            var podiumHtml = '';
            podiumHtml += makePodiumCol('podium-2nd', 2, '🥈 2°', 'Vice Campione', opList[1] || null);
            podiumHtml += makePodiumCol('podium-1st', 1, '🥇 1°', 'Top Scanner', opList[0] || null);
            podiumHtml += makePodiumCol('podium-3rd', 3, '🥉 3°', 'Pilastro Desk', opList[2] || null);
            $podium.html(podiumHtml);

            // Costruzione Classifica Completa
            var maxCount = opList[0].count;
            var lbHtml = '';
            opList.forEach(function(op, idx) {
                var pos = idx + 1;
                var posClass = pos <= 3 ? 'pos-' + pos : '';
                var medalIcon = pos === 1 ? '🥇' : (pos === 2 ? '🥈' : (pos === 3 ? '🥉' : '#' + pos));
                var barWidth = Math.max(8, Math.round((op.count / maxCount) * 100));
                var pctTotal = totalValidations > 0 ? Math.round((op.count / totalValidations) * 100) : 0;

                lbHtml += '<div class="dfn-leaderboard-item">' +
                    '<div class="dfn-lb-pos ' + posClass + '">' + medalIcon + '</div>' +
                    '<div class="dfn-lb-name" title="' + op.name + '">' + op.name + '</div>' +
                    '<div class="dfn-lb-bar-wrap"><div class="dfn-lb-bar-fill" style="width:' + barWidth + '%;"></div></div>' +
                    '<div class="dfn-lb-stat"><strong>' + op.count + '</strong> <span style="color:#64748b; font-size:10px;">(' + pctTotal + '%)</span></div>' +
                '</div>';
            });
            $lbList.html(lbHtml);
        }

        function renderFlowChart(allValidations) {
            var rawBuckets = {};
            var minutePoints = [];

            allValidations.forEach(function(v) {
                if (!v.time) return;
                var tStr = v.time.toString().trim();
                var match = tStr.match(/(\d{1,2}):(\d{2})/);
                if (match) {
                    var h = parseInt(match[1], 10);
                    var m = parseInt(match[2], 10);
                    var totalMin = h * 60 + m;
                    minutePoints.push(totalMin);
                    
                    var bucketM = flowInterval > 1 ? Math.floor(m / flowInterval) * flowInterval : m;
                    var bucketTotalMin = h * 60 + bucketM;
                    rawBuckets[bucketTotalMin] = (rawBuckets[bucketTotalMin] || 0) + 1;
                }
            });

            var $canvas = $('#dfnFlowChart');
            var $empty = $('#dfn-chart-empty');

            if (minutePoints.length === 0) {
                $canvas.hide();
                $empty.show();
                $('#dfn-kpi-peak').text('-');
                $('#dfn-kpi-speed').text('-');
                $('#dfn-kpi-duration').text('-');
                if (flowChartInstance) {
                    flowChartInstance.destroy();
                    flowChartInstance = null;
                }
                return;
            }

            $empty.hide();
            $canvas.show();

            minutePoints.sort(function(a, b) { return a - b; });
            var minMin = minutePoints[0];
            var maxMin = minutePoints[minutePoints.length - 1];

            var startMin = flowInterval > 1 ? Math.floor(minMin / flowInterval) * flowInterval : minMin;
            var endMin   = flowInterval > 1 ? Math.floor(maxMin / flowInterval) * flowInterval : maxMin;

            var labels = [];
            var dataPoints = [];
            var maxPeakVal = 0;
            var peakTimeStr = '-';

            for (var cur = startMin; cur <= endMin; cur += flowInterval) {
                var curH = Math.floor(cur / 60);
                var curM = cur % 60;
                var timeLabel = (curH < 10 ? '0' + curH : '' + curH) + ':' + (curM < 10 ? '0' + curM : '' + curM);
                var count = rawBuckets[cur] || 0;
                
                labels.push(timeLabel);
                dataPoints.push(count);

                if (count > maxPeakVal) {
                    maxPeakVal = count;
                    peakTimeStr = timeLabel;
                }
            }

            var durationMinutes = (maxMin - minMin) + 1;
            var durationLabel = durationMinutes + ' min';
            var startH = Math.floor(minMin / 60), startM = minMin % 60;
            var endH = Math.floor(maxMin / 60), endM = maxMin % 60;
            var windowStr = (startH < 10 ? '0' + startH : '' + startH) + ':' + (startM < 10 ? '0' + startM : '' + startM) + ' - ' +
                            (endH < 10 ? '0' + endH : '' + endH) + ':' + (endM < 10 ? '0' + endM : '' + endM);

            var avgSpeed = durationMinutes > 0 ? (allValidations.length / durationMinutes).toFixed(1) : allValidations.length;

            $('#dfn-kpi-peak').html(maxPeakVal + ' <small style="font-size:11px; font-weight:normal;">alle ' + peakTimeStr + '</small>');
            $('#dfn-kpi-speed').html(avgSpeed + ' <small style="font-size:11px; font-weight:normal;">/ min</small>');
            $('#dfn-kpi-duration').html(durationLabel + ' <small style="font-size:10px; color:#64748b;">(' + windowStr + ')</small>');

            if (typeof Chart === 'undefined') {
                return;
            }

            var canvasEl = document.getElementById('dfnFlowChart');
            if (!canvasEl) return;

            if (flowChartInstance) {
                flowChartInstance.destroy();
            }

            var chartCtx = canvasEl.getContext('2d');
            var gradient = chartCtx.createLinearGradient(0, 0, 0, 220);
            gradient.addColorStop(0, 'rgba(16, 185, 129, 0.40)');
            gradient.addColorStop(1, 'rgba(16, 185, 129, 0.01)');

            flowChartInstance = new Chart(chartCtx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Persone entrate',
                        data: dataPoints,
                        borderColor: '#004b23',
                        backgroundColor: gradient,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#004b23',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 1.5,
                        pointRadius: labels.length > 25 ? 2.5 : 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            titleFont: { size: 12, weight: 'bold' },
                            bodyFont: { size: 13 },
                            padding: 10,
                            cornerRadius: 6,
                            callbacks: {
                                label: function(context) {
                                    var val = context.parsed.y;
                                    return ' ' + val + (val === 1 ? ' persona convalidata' : ' persone convalidate');
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 10, family: 'system-ui' },
                                color: '#64748b',
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 12
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                font: { size: 10, family: 'system-ui' },
                                color: '#64748b',
                                precision: 0
                            },
                            grid: {
                                color: '#f1f5f9'
                            }
                        }
                    }
                }
            });
        }

        // Event Listeners Sezione Analytics
        $(document).on('click', '.dfn-gran-btn', function() {
            var interval = parseInt($(this).data('interval'), 10) || 1;
            flowInterval = interval;
            $('.dfn-gran-btn').removeClass('active');
            $(this).addClass('active');
            if (currentData) {
                updateFlowChartAndGamification(currentData);
            }
        });

        $(document).on('click', '#dfn-toggle-analytics-panel', function() {
            var $panel = $('#dfn-analytics-panel');
            var isCollapsed = $panel.toggleClass('is-collapsed').hasClass('is-collapsed');
            var $btn = $(this);
            if (isCollapsed) {
                $btn.find('.dashicons').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                $btn.find('.toggle-text').text('Espandi');
            } else {
                $btn.find('.dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $btn.find('.toggle-text').text('Comprimi');
                if (currentData) {
                    updateFlowChartAndGamification(currentData);
                }
            }
            localStorage.setItem('dfn_ci_panel_collapsed', isCollapsed ? 'true' : 'false');
        });

        $(document).on('click', '#dfn-toggle-autorefresh', function() {
            autoRefreshActive = !autoRefreshActive;
            var $btn = $(this);
            var $liveBadge = $('#dfn-live-indicator');

            if (autoRefreshActive) {
                $liveBadge.show();
                $btn.css({ background: '#004b23', color: '#fff', borderColor: '#003b1c' });
                $btn.find('.dashicons').removeClass('dashicons-controls-play').addClass('dashicons-controls-pause');
                $btn.find('.btn-text').text('Auto-refresh: ON (30s)');
                autoRefreshTimer = setInterval(function() {
                    loadSlots(activeDate, true);
                }, 30000);
            } else {
                $liveBadge.hide();
                $btn.css({ background: '', color: '', borderColor: '' });
                $btn.find('.dashicons').removeClass('dashicons-controls-pause').addClass('dashicons-controls-play');
                $btn.find('.btn-text').text('Auto-refresh: OFF');
                if (autoRefreshTimer) {
                    clearInterval(autoRefreshTimer);
                    autoRefreshTimer = null;
                }
            }
        });

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
            var container = $(this).parent().find('.cv-history-data-container');
            if (container.length > 0 && container.html().trim() !== '') {
                $('#cv-history-content-area').html(container.html());
            } else {
                $('#cv-history-content-area').html('<p style="color:#666; font-style:italic; padding:10px; text-align:center;">Nessuna interazione registrata per questo ordine.</p>');
            }
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
            var originalText = btn.html();
            btn.prop('disabled', true);
            var totalSent = 0;

            function inviaLotto() {
                btn.text('⏳ Invio in corso (' + totalSent + ' inviate)...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'cv_send_event_reminders', security: dfnCheckinVars.nonceReminder, event_id: eventId },
                    success: function(response) {
                        if (response.success) {
                            totalSent += response.data.sent;
                            if (response.data.has_more) {
                                btn.text('⏳ Pausa anti-spam tra i lotti (inviate ' + totalSent + ')...');
                                setTimeout(function() {
                                    inviaLotto();
                                }, 2000);
                            } else {
                                alert(totalSent > 0 ? '✅ Inviate ' + totalSent + ' email.' : '✅ Nessuna email inviata.');
                                btn.prop('disabled', false).html(originalText);
                                loadSlots(activeDate);
                            }
                        } else { alert('❌ Errore: ' + response.data); btn.prop('disabled', false).html(originalText); }
                    },
                    error: function() { alert('❌ Errore di rete.'); btn.prop('disabled', false).html(originalText); }
                });
            }
            inviaLotto();
        });

        // Feedback Globale
        $(document).on('click', '#cv-send-feedback-btn', function(e) {
            e.preventDefault();
            if (!confirm('Vuoi inviare la richiesta di recensione a tutti i partecipanti verificati?\n\nL\'email verrà inviata a lotti distanziati solo a chi è stato convalidato all\'ingresso.')) return;
            var btn = $(this);
            var originalText = btn.html();
            btn.prop('disabled', true);
            var totalSent = 0;

            function inviaLottoFeedback() {
                btn.text('⏳ Invio in corso (' + totalSent + ' inviate)...');
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: { action: 'cv_send_feedback_requests', security: dfnCheckinVars.nonceFeedback, event_id: eventId },
                    success: function(response) {
                        if (response.success) {
                            totalSent += response.data.sent;
                            if (response.data.has_more) {
                                btn.text('⏳ Pausa anti-spam tra i lotti (inviate ' + totalSent + ')...');
                                setTimeout(function() {
                                    inviaLottoFeedback();
                                }, 2000);
                            } else {
                                alert(totalSent > 0 ? '✅ Operazione completata! Inviate ' + totalSent + ' email di richiesta recensione.' : '✅ Nessuna email da inviare. Tutti i partecipanti idonei hanno già ricevuto la richiesta oppure non ci sono presenze verificate.');
                                btn.prop('disabled', false).html(originalText);
                                loadSlots(activeDate);
                            }
                        } else { alert('❌ Errore: ' + response.data); btn.prop('disabled', false).html(originalText); }
                    },
                    error: function() { alert('❌ Errore di rete.'); btn.prop('disabled', false).html(originalText); }
                });
            }
            inviaLottoFeedback();
        });

    });
})(jQuery);

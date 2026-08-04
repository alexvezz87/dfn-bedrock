/**
 * DFN Booking System 2.0 — Slot Manager JavaScript
 *
 * Gestisce l'interfaccia interattiva della pagina "Gestione Turni" (AJAX calls, modali, export CSV).
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $wrapper = $('.dfn-slot-manager-wrap');
        if (!$wrapper.length) {
            return;
        }

        function escHtml(str) {
            if (!str) return '';
            return str.toString()
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        var eventId = parseInt($wrapper.data('event-id'), 10);
        var nonce = $wrapper.data('nonce');
        var accessType = $wrapper.data('access-type') || 'time_slots'; // 'free_flow' o 'time_slots'
        var ajaxurl = typeof dfnAdminVars !== 'undefined' ? dfnAdminVars.ajaxurl : '/wp/wp-admin/admin-ajax.php';
        
        var currentData = null; // Memorizza i dati AJAX correnti
        var activeDate = $('.dfn-pill-date.active').data('date');

        // Per gli eventi free_flow nasconde i controlli non applicabili
        if (accessType === 'free_flow') {
            $('#dfn-btn-add-slot-modal').hide();
        }

        // Caricamento iniziale
        if (activeDate) {
            loadSlots(activeDate);
        }

        // ========================================================================
        // 1. CARICAMENTO DATI SLOT
        // ========================================================================
        function loadSlots(date) {
            activeDate = date;
            var $grid = $('#dfn-sm-slots-grid');
            $grid.html('<div class="dfn-loading"><span class="dashicons dashicons-update spin"></span> Caricamento turni in corso...</div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_admin_get_slots',
                    event_id: eventId,
                    date: date,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        currentData = response.data.slots;
                        renderGrid(currentData);
                        updateStats(currentData);
                        populateSlotSelects(currentData);
                    } else {
                        $grid.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $grid.html('<div class="notice notice-error"><p>Errore di rete durante il caricamento dei turni.</p></div>');
                }
            });
        }

        // Cambio data al click sui pills
        $(document).on('click', '.dfn-pill-date', function() {
            $('.dfn-pill-date').removeClass('active');
            $(this).addClass('active');
            loadSlots($(this).data('date'));
        });

        // ========================================================================
        // 2. RENDERING INTERFACCIA
        // ========================================================================
        function renderGrid(slots) {
            var $grid = $('#dfn-sm-slots-grid');
            $grid.empty();

            if (!slots || slots.length === 0) {
                $grid.html('<div class="dfn-empty-state"><span class="dashicons dashicons-calendar"></span><p>Nessun turno configurato per questa giornata.</p></div>');
                return;
            }

            var searchQuery = $('#dfn-sm-search').val().toLowerCase().trim();

            // Se c'è un solo slot configurato, mostriamo la tabella di gestione prenotazioni direttamente in pagina
            if (slots.length === 1) {
                $grid.css('display', 'block');
                var slot = slots[0];
                var totalCapacity = slot.capacity + slot.bonus_capacity;
                var booked = slot.booked_count;
                var isFreeFlow = slot.is_free_flow || false;
                var percent = totalCapacity > 0 ? Math.min(100, Math.round((booked / totalCapacity) * 100)) : 0;

                var $container = $('<div class="dfn-single-slot-bookings-container" style="width:100%;"></div>');

                // Filtro ricerca
                var filteredBookings = slot.bookings;
                if (searchQuery !== '') {
                    filteredBookings = slot.bookings.filter(function(b) {
                        var name  = b.customer_name  ? b.customer_name.toLowerCase()  : '';
                        var email = b.customer_email ? b.customer_email.toLowerCase() : '';
                        var phone = b.customer_phone ? b.customer_phone.toLowerCase() : '';
                        return name.indexOf(searchQuery)  !== -1 ||
                               email.indexOf(searchQuery) !== -1 ||
                               phone.indexOf(searchQuery) !== -1;
                    });
                }

                // ── Header: statistiche slot + azioni slot ──────────────────
                var headerHtml =
                    '<div class="dfn-sm-card" data-slot-id="' + slot.id + '" data-time-start="' + slot.time_start + '" data-time-end="' + slot.time_end + '" data-capacity="' + slot.capacity + '" data-bonus="' + slot.bonus_capacity + '" style="margin-bottom:20px; border-radius:6px;">' +
                        '<div class="dfn-sm-card-header">' +
                            '<div class="slot-time">' +
                                (isFreeFlow
                                    ? '<span class="dashicons dashicons-list-view"></span> <strong>Flusso Libero &mdash; ' + slot.time_start + ' &rarr; ' + slot.time_end + '</strong>'
                                    : '<span class="dashicons dashicons-clock"></span> <strong>' + slot.time_start + ' - ' + slot.time_end + '</strong>'
                                ) +
                            '</div>' +
                            (isFreeFlow ? '<div class="slot-actions"></div>' :
                                '<div class="slot-actions">' +
                                    '<button type="button" class="dfn-icon-btn dfn-btn-edit-capacity" title="Modifica Capacità"><span class="dashicons dashicons-edit"></span></button>' +
                                    '<button type="button" class="dfn-icon-btn dfn-btn-lock-toggle" title="' + (slot.is_locked ? 'Sblocca Turno' : 'Blocca Turno') + '"><span class="dashicons ' + (slot.is_locked ? 'dashicons-lock' : 'dashicons-unlock') + '"></span></button>' +
                                '</div>'
                            ) +
                        '</div>' +
                        '<div class="dfn-sm-progress-area">' +
                            '<div class="progress-labels">' +
                                '<span>' + (slot.is_locked ? 'Bloccato' : percent + '% occupato') + '</span>' +
                                '<span><strong>' + booked + '</strong> / ' + totalCapacity + ' posti &bull; ' + filteredBookings.length + ' prenotazioni</span>' +
                            '</div>' +
                            '<div class="progress-bar-bg"><div class="progress-bar-fill" style="width:' + percent + '%;"></div></div>' +
                            (slot.bonus_capacity > 0 ? '<small class="bonus-label">Capacità bonus attiva: +' + slot.bonus_capacity + '</small>' : '') +
                        '</div>' +
                    '</div>';

                $container.append(headerHtml);

                // ── Tabella Gestione Prenotazioni (senza colonne check-in) ──
                var tableHtml =
                    '<div style="overflow-x:auto;">' +
                    '<table class="wp-list-table widefat fixed striped dfn-bookings-rich-table" style="width:100%; border-collapse:collapse; border:1px solid #cbd5e1;">' +
                    '<thead><tr style="background:#f1f5f9;">' +
                        '<th style="padding:10px; font-weight:700; width:80px;">Ordine #</th>' +
                        '<th style="padding:10px; font-weight:700;">Cliente</th>' +
                        '<th style="padding:10px; font-weight:700; width:120px;">Qualifica</th>' +
                        '<th style="padding:10px; font-weight:700; width:130px;">Telefono</th>' +
                        '<th style="padding:10px; font-weight:700; width:90px; text-align:center;">Biglietti</th>' +
                        '<th style="padding:10px; font-weight:700; width:120px; text-align:center;">Pagamento</th>' +
                        '<th style="padding:10px; font-weight:700; width:180px;">Note</th>' +
                        '<th style="padding:10px; font-weight:700; width:140px; text-align:center;">Azioni</th>' +
                    '</tr></thead>' +
                    '<tbody>';

                if (filteredBookings.length === 0) {
                    tableHtml += '<tr><td colspan="8" style="padding:30px; text-align:center; color:#64748b;">Nessuna prenotazione trovata.</td></tr>';
                } else {
                    filteredBookings.forEach(function(b) {
                        var orderEditUrl = dfnAdminVars.ajaxurl.replace('admin-ajax.php', 'post.php?post=' + b.order_id + '&action=edit');
                        var orderLink    = b.order_id > 0 ? '<a href="' + orderEditUrl + '" target="_blank"><strong>#' + b.order_id + '</strong></a>' : '-';
                        var telLink      = b.customer_phone ? '<a href="tel:' + b.customer_phone + '">' + b.customer_phone + '</a>' : '-';
                        var payBadge     = b.payment_status === 'pagato'
                            ? '<span style="background:#dcfce7; color:#166534; font-size:11px; padding:3px 8px; border-radius:10px; font-weight:700;">&#9989; Pagato</span>'
                            : '<span style="background:#fef2f2; color:#991b1b; font-size:11px; padding:3px 8px; border-radius:10px; font-weight:700;">&#9203; Da pagare</span>';
                        var noteRaw = b.notes ? b.notes.trim() : '';
                        var isLongNote = noteRaw.length > 35;
                        var noteDisplay = isLongNote ? noteRaw.substring(0, 35) + '…' : noteRaw;

                        var notesCell = noteRaw
                            ? '<div class="dfn-note-balloon" data-full-note="' + escHtml(noteRaw) + '" data-customer="' + escHtml(b.customer_name) + '" data-order="' + (b.order_id || '-') + '" style="font-size:11.5px; color:#334155; background:#fffbe6; border:1px solid #ffe58f; padding:5px 10px; border-radius:6px; font-style:italic; line-height:1.3; max-width:190px; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all 0.15s ease;" title="Clicca per visualizzare la nota completa">' +
                                  '<span>💬</span> <span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + escHtml(noteDisplay) + '</span>' +
                              '</div>'
                            : '<span style="color:#cbd5e1;">-</span>';

                        tableHtml +=
                            '<tr class="dfn-slot-booking-row" data-booking-id="' + b.id + '" data-persons="' + b.slot_persons + '" data-name="' + b.customer_name + '" data-slot-id="' + slot.id + '">' +
                                '<td style="padding:10px; vertical-align:middle;">' + orderLink + '</td>' +
                                '<td style="padding:10px; vertical-align:middle;">' +
                                    '<div style="font-weight:700;">' + b.customer_name + '</div>' +
                                    '<div style="font-size:11px; color:#64748b;">' + (b.customer_email !== 'no-email@dfn.it' ? b.customer_email : '') + '</div>' +
                                '</td>' +
                                '<td style="padding:10px; vertical-align:middle;">' + (b.qualifica_html || '') + '</td>' +
                                '<td style="padding:10px; vertical-align:middle;">' + telLink + '</td>' +
                                '<td style="padding:10px; vertical-align:middle; text-align:center; font-weight:700;">' + b.slot_persons + '</td>' +
                                '<td style="padding:10px; vertical-align:middle; text-align:center;">' + payBadge + '</td>' +
                                '<td style="padding:10px; vertical-align:middle;">' + notesCell + '</td>' +
                                '<td style="padding:10px; vertical-align:middle; text-align:center;">' +
                                    '<div style="display:flex; gap:5px; justify-content:center;">' +
                                        '<button class="dfn-btn dfn-btn-secondary slot-booking-info" title="Dettagli" style="font-size:11px; padding:4px 8px;"><span class="dashicons dashicons-visibility" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span></button>' +
                                        '<button class="dfn-btn dfn-btn-secondary dfn-btn-move-booking" title="Sposta turno" style="font-size:11px; padding:4px 8px;"><span class="dashicons dashicons-randomize" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span></button>' +
                                        '<button class="dfn-btn dfn-btn-danger dfn-btn-delete-booking" title="Annulla prenotazione" style="font-size:11px; padding:4px 8px; background:#d63638; border-color:#d63638; color:#fff;"><span class="dashicons dashicons-trash" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span></button>' +
                                    '</div>' +
                                '</td>' +
                            '</tr>';
                    });
                }

                tableHtml += '</tbody></table></div>';
                $container.append(tableHtml);
                $grid.append($container);

                updateStats(slots);
                return;
            }


            $grid.css('display', 'grid');
            slots.forEach(function(slot) {
                var totalCapacity = slot.capacity + slot.bonus_capacity;
                var booked = slot.booked_count;
                var percent = totalCapacity > 0 ? Math.min(100, Math.round((booked / totalCapacity) * 100)) : 0;
                
                // Colore di stato
                var statusClass = 'status-green';
                if (slot.is_locked) {
                    statusClass = 'status-locked';
                } else if (booked >= totalCapacity) {
                    statusClass = 'status-red';
                } else if (percent >= 80) {
                    statusClass = 'status-yellow';
                }

                // Filtro ricerca
                var filteredBookings = slot.bookings;
                if (searchQuery !== '') {
                    filteredBookings = slot.bookings.filter(function(b) {
                        var name = b.customer_name ? b.customer_name.toLowerCase() : '';
                        var email = b.customer_email ? b.customer_email.toLowerCase() : '';
                        var phone = b.customer_phone ? b.customer_phone.toLowerCase() : '';
                        return name.indexOf(searchQuery) !== -1 ||
                               email.indexOf(searchQuery) !== -1 ||
                               phone.indexOf(searchQuery) !== -1;
                    });
                    
                    // Se non ci sono prenotazioni corrispondenti e lo slot non corrisponde all'orario, lo saltiamo se c'è una query attiva
                    if (filteredBookings.length === 0 && slot.time_start.indexOf(searchQuery) === -1) {
                        return; // salta rendering di questa card
                    }
                }

                var $card = $('<div class="dfn-sm-card ' + statusClass + '" data-slot-id="' + slot.id + '" data-time-start="' + slot.time_start + '" data-time-end="' + slot.time_end + '" data-capacity="' + slot.capacity + '" data-bonus="' + slot.bonus_capacity + '"></div>');

                // Per lo slot virtuale free_flow mostriamo un'intestazione semplificata senza azioni di gestione slot
                var isFreeFlow = slot.is_free_flow || false;

                var cardHeaderHtml;
                if (isFreeFlow) {
                    cardHeaderHtml =
                        '<div class="dfn-sm-card-header">' +
                            '<div class="slot-time">' +
                                '<span class="dashicons dashicons-list-view"></span> <strong>Flusso Libero &mdash; ' + slot.time_start + ' &rarr; ' + slot.time_end + '</strong>' +
                            '</div>' +
                            '<div class="slot-actions"></div>' +
                        '</div>';
                } else {
                    cardHeaderHtml =
                        '<div class="dfn-sm-card-header">' +
                            '<div class="slot-time">' +
                                '<span class="dashicons dashicons-clock"></span> <strong>' + slot.time_start + ' - ' + slot.time_end + '</strong>' +
                            '</div>' +
                            '<div class="slot-actions">' +
                                '<button type="button" class="dfn-icon-btn dfn-btn-edit-capacity" title="Modifica Capacità"><span class="dashicons dashicons-edit"></span></button>' +
                                '<button type="button" class="dfn-icon-btn dfn-btn-lock-toggle" title="' + (slot.is_locked ? 'Sblocca Turno' : 'Blocca Turno') + '">' +
                                    '<span class="dashicons ' + (slot.is_locked ? 'dashicons-lock' : 'dashicons-unlock') + '"></span>' +
                                '</button>' +
                                (booked === 0 ? '<button type="button" class="dfn-icon-btn dfn-btn-delete-slot" title="Elimina Turno"><span class="dashicons dashicons-trash"></span></button>' : '') +
                            '</div>' +
                        '</div>';
                }

                var progressHtml = 
                    '<div class="dfn-sm-progress-area">' +
                        '<div class="progress-labels">' +
                            '<span>' + (slot.is_locked ? 'Bloccato' : percent + '% occupato') + '</span>' +
                            '<span><strong>' + booked + '</strong> / ' + totalCapacity + ' posti</span>' +
                        '</div>' +
                        '<div class="progress-bar-bg">' +
                            '<div class="progress-bar-fill" style="width: ' + percent + '%;"></div>' +
                        '</div>' +
                        (slot.bonus_capacity > 0 ? '<small class="bonus-label">Capacità bonus attiva: +' + slot.bonus_capacity + '</small>' : '') +
                    '</div>';

                // Bottone per aprire il popup con la lista prenotazioni
                var bookingsBtnHtml = 
                    '<div class="dfn-sm-bookings-btn-area">' +
                        '<button type="button" class="dfn-btn-open-bookings-modal" data-slot-id="' + slot.id + '">' +
                            '<span class="dashicons dashicons-groups"></span>' +
                            '<span>Prenotazioni (' + filteredBookings.length + ')</span>' +
                            '<span class="dashicons dashicons-external"></span>' +
                        '</button>' +
                    '</div>';

                $card.append(cardHeaderHtml);
                $card.append(progressHtml);
                $card.append(bookingsBtnHtml);

                $grid.append($card);
            });
        }

        function updateStats(slots) {
            var totalBookings = 0;
            var occupied = 0;
            var totalCapacity = 0;
            var faiToVerify = 0;

            slots.forEach(function(slot) {
                totalBookings += slot.bookings.length;
                occupied += slot.booked_count;
                totalCapacity += (slot.capacity + slot.bonus_capacity);
                
                slot.bookings.forEach(function(b) {
                    if (b.persons_fai > 0) {
                        faiToVerify += b.persons_fai;
                    }
                });
            });

            var percent = totalCapacity > 0 ? Math.round((occupied / totalCapacity) * 100) : 0;

            $('#dfn-stat-total-bookings').text(totalBookings);
            $('#dfn-stat-occupied-places').text(occupied);
            $('#dfn-stat-occupancy-percent').text(percent + '%');
            $('#dfn-stat-fai-to-verify').text(faiToVerify);
        }

        function populateSlotSelects(slots) {
            var $moveSelect = $('#dfn-form-move-booking select[name="to_slot_id"]');
            var $addBookingSelect = $('#dfn-form-add-booking select[name="slot_id"]');

            $moveSelect.empty();
            $addBookingSelect.empty();

            $addBookingSelect.append('<option value="">Seleziona un turno...</option>');

            slots.forEach(function(slot) {
                if (slot.is_locked) {
                    return;
                }
                // Per free_flow lo slot virtuale (id=0) non va inserito nel select di spostamento turno
                // ma va nel select della nuova prenotazione
                var total = slot.capacity + slot.bonus_capacity;
                var free = total - slot.booked_count;
                var label = slot.is_free_flow
                    ? 'Flusso Libero (' + free + ' posti disponibili)'
                    : slot.time_start + ' - ' + slot.time_end + ' (' + free + ' posti liberi)';

                if (!slot.is_free_flow) {
                    $moveSelect.append('<option value="' + slot.id + '" data-free="' + free + '">' + label + '</option>');
                }
                $addBookingSelect.append('<option value="' + slot.id + '">' + label + '</option>');
            });
        }

        // Ricerca in tempo reale
        $(document).on('keyup input', '#dfn-sm-search', function() {
            if (currentData) {
                renderGrid(currentData);
            }
        });

        // Apertura popup lista prenotazioni dal bottone sulla card
        $(document).on('click', '.dfn-btn-open-bookings-modal', function() {
            var slotId = parseInt($(this).data('slot-id'), 10);
            if (!currentData) return;

            var slot = currentData.find(function(s) {
                return s.id === slotId;
            });
            if (!slot) return;

            renderBookingsModal(slot);
        });

        /**
         * Renderizza e apre il popup grande con la lista di tutte le prenotazioni
         * per lo slot selezionato.
         */
        function renderBookingsModal(slot) {
            var $modal = $('#dfn-modal-slot-bookings');
            var isFreeFlow = slot.is_free_flow || false;
            var titleText = isFreeFlow
                ? 'Prenotazioni — Flusso Libero (' + slot.time_start + ' → ' + slot.time_end + ')'
                : 'Prenotazioni — Turno ' + slot.time_start + ' – ' + slot.time_end;

            $modal.find('.dfn-slot-bookings-title').text(titleText);

            // Statistiche header
            var totalCapacity = slot.capacity + slot.bonus_capacity;
            var booked = slot.booked_count;
            var free = Math.max(0, totalCapacity - booked);
            $modal.find('.slot-modal-stat-booked').text(booked);
            $modal.find('.slot-modal-stat-capacity').text(totalCapacity);
            $modal.find('.slot-modal-stat-free').text(free);
            $modal.find('.slot-modal-stat-count').text(slot.bookings.length);

            // Salva lo slotId nel modale per i click interni
            $modal.data('current-slot-id', slot.id);

            // Sincronizza ricerca interna con la ricerca principale se presente
            var mainSearchQuery = $('#dfn-sm-search').val().trim();
            $('#dfn-slot-bookings-search').val(mainSearchQuery);

            if (mainSearchQuery !== '') {
                var query = mainSearchQuery.toLowerCase();
                var filtered = slot.bookings.filter(function(b) {
                    var name = b.customer_name ? b.customer_name.toLowerCase() : '';
                    var email = b.customer_email ? b.customer_email.toLowerCase() : '';
                    var phone = b.customer_phone ? b.customer_phone.toLowerCase() : '';
                    return name.indexOf(query) !== -1 ||
                           email.indexOf(query) !== -1 ||
                           phone.indexOf(query) !== -1;
                });
                renderBookingsListInModal(filtered, slot);
            } else {
                renderBookingsListInModal(slot.bookings, slot);
            }

            openModal($modal);
        }

        /**
         * Renderizza le card prenotazione nel popup grande (stile gestione prenotazioni).
         * Mostra le azioni di annullamento e spostamento turno.
         */
        function renderBookingsListInModal(bookings, slot) {
            var $list = $('#dfn-slot-bookings-list');
            $list.empty();

            if (!bookings || bookings.length === 0) {
                $list.html('<div class="dfn-slot-bookings-empty"><span class="dashicons dashicons-groups"></span><p>Nessuna prenotazione trovata.</p></div>');
                return;
            }

            bookings.forEach(function(b) {
                var orderEditUrl = dfnAdminVars.ajaxurl.replace('admin-ajax.php', 'post.php?post=' + b.order_id + '&action=edit');
                var orderLink = b.order_id > 0 ? '<a href="' + orderEditUrl + '" target="_blank">#' + b.order_id + '</a>' : '-';
                var paymentBadge = b.payment_status === 'pagato'
                    ? '<span style="background:#dcfce7; color:#166534; font-size:10px; padding:2px 7px; border-radius:10px; font-weight:700;">✅ Pagato</span>'
                    : '<span style="background:#fef2f2; color:#991b1b; font-size:10px; padding:2px 7px; border-radius:10px; font-weight:700;">⏳ Da pagare</span>';

                var $row = $(
                    '<div class="dfn-slot-booking-row" data-booking-id="' + b.id + '" data-persons="' + b.slot_persons + '" data-name="' + b.customer_name + '" data-slot-id="' + slot.id + '">' +
                        '<div class="slot-booking-index">' + (b.id) + '</div>' +
                        '<div class="slot-booking-info" style="flex:1; min-width:0; cursor:pointer;">' +
                            '<div style="font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + b.customer_name + '</div>' +
                            '<div style="font-size:11px; color:#64748b;">' + orderLink + ' &bull; ' + b.slot_persons + ' posti &bull; ' + paymentBadge + '</div>' +
                            (b.customer_phone ? '<div style="font-size:11px; color:#64748b;"><a href="tel:' + b.customer_phone + '">' + b.customer_phone + '</a></div>' : '') +
                            (b.notes ? '<div class="dfn-note-balloon" data-full-note="' + escHtml(b.notes) + '" data-customer="' + escHtml(b.customer_name) + '" data-order="' + (b.order_id || '-') + '" style="font-size:11px; color:#334155; background:#fffbe6; border:1px solid #ffe58f; padding:4px 8px; border-radius:4px; margin-top:4px; font-style:italic; cursor:pointer; display:inline-block;" title="Clicca per visualizzare la nota completa">💬 <strong>Note:</strong> ' + escHtml(b.notes.length > 35 ? b.notes.substring(0, 35) + '…' : b.notes) + '</div>' : '') +
                        '</div>' +
                        '<div class="slot-booking-actions" style="display:flex; gap:6px; flex-shrink:0;">' +
                            '<button type="button" class="dfn-btn dfn-btn-secondary dfn-btn-move-booking" title="Sposta turno" style="font-size:11px; padding:4px 8px;"><span class="dashicons dashicons-randomize" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span></button>' +
                            '<button type="button" class="dfn-btn dfn-btn-danger dfn-btn-delete-booking" title="Annulla prenotazione" style="font-size:11px; padding:4px 8px; background:#d63638; border-color:#d63638; color:#fff;"><span class="dashicons dashicons-trash" style="font-size:14px; width:14px; height:14px; line-height:14px;"></span></button>' +
                        '</div>' +
                    '</div>'
                );
                $list.append($row);
            });
        }

        // Ricerca interna al popup prenotazioni
        $(document).on('keyup input', '#dfn-slot-bookings-search', function() {
            var query = $(this).val().toLowerCase().trim();
            var $modal = $('#dfn-modal-slot-bookings');
            var slotId = $modal.data('current-slot-id');
            if (!currentData) return;

            var slot = currentData.find(function(s) { return s.id === slotId; });
            if (!slot) return;

            if (query === '') {
                renderBookingsListInModal(slot.bookings, slot);
            } else {
                var filtered = slot.bookings.filter(function(b) {
                    var name = b.customer_name ? b.customer_name.toLowerCase() : '';
                    var email = b.customer_email ? b.customer_email.toLowerCase() : '';
                    var phone = b.customer_phone ? b.customer_phone.toLowerCase() : '';
                    return name.indexOf(query) !== -1 ||
                           email.indexOf(query) !== -1 ||
                           phone.indexOf(query) !== -1;
                });
                renderBookingsListInModal(filtered, slot);
            }
        });

        // Click su una riga prenotazione nel popup grande → apre il popup dettagli (livello 2)
        $(document).on('click', '.dfn-slot-booking-row .slot-booking-info, .dfn-slot-booking-row .slot-booking-index', function() {
            var $row = $(this).closest('.dfn-slot-booking-row');
            var bookingId = parseInt($row.data('booking-id'), 10);
            var slotId = parseInt($row.data('slot-id'), 10);

            if (!currentData) return;

            var slot = currentData.find(function(s) {
                return s.id === slotId;
            });
            if (!slot) return;

            var booking = slot.bookings.find(function(b) {
                return b.id === bookingId;
            });
            if (!booking) return;

            var $modal = $('#dfn-modal-booking-details');
            var $tbody = $modal.find('.dfn-details-table tbody');
            $tbody.empty();

            var statusSelectHtml = '<select id="dfn-select-booking-payment-status" data-booking-id="' + booking.id + '" style="font-size:12px; padding:4px 8px; border:1px solid #cbd5e1; border-radius:4px; font-weight:600;">' +
                '<option value="confirmed"' + (booking.status === 'confirmed' ? ' selected' : '') + '>✅ Pagato / Confermato</option>' +
                '<option value="pending_payment"' + (booking.status === 'pending_payment' || booking.status === 'pending_approval' ? ' selected' : '') + '>⏳ In attesa di pagamento</option>' +
                '<option value="cancelled"' + (booking.status === 'cancelled' ? ' selected' : '') + '>❌ Annullato</option>' +
            '</select>';

            var rows = [
                ['Nominativo:', '<strong>' + booking.customer_name + '</strong>'],
                ['Email:', booking.customer_email !== 'no-email@dfn.it' ? booking.customer_email : '<em>Non fornita</em>'],
                ['Telefono:', booking.customer_phone ? booking.customer_phone : '<em>Non fornito</em>'],
                ['Posti Prenotati:', booking.slot_persons + ' totali (Standard: ' + booking.persons_standard + ' | FAI: ' + booking.persons_fai + ')'],
                ['Turno:', slot.time_start + ' - ' + slot.time_end],
                ['Stato Pagamento:', statusSelectHtml],
                ['Data Registrazione:', booking.created_at],
                ['Note Visitatore:', booking.notes ? booking.notes : '<em>Nessuna nota</em>']
            ];

            rows.forEach(function(row) {
                $tbody.append(
                    '<tr style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:10px 0; font-weight:600; color:#64748b; width:35%;">' + row[0] + '</td>' +
                        '<td style="padding:10px 0; color:#1e293b;">' + row[1] + '</td>' +
                    '</tr>'
                );
            });

            var initialStatus = booking.status;
            var $saveBtn = $('#dfn-btn-save-payment-status');
            $saveBtn.hide().prop('disabled', false).text('Salva Stato Pagamento');

            // Listener cambio select: mostra il pulsante "Salva Stato Pagamento" se lo stato è diverso da quello iniziale
            $('#dfn-select-booking-payment-status').off('change').on('change', function() {
                var newStatus = $(this).val();
                if (newStatus !== initialStatus) {
                    $saveBtn.fadeIn(200);
                } else {
                    $saveBtn.fadeOut(200);
                }
            });

            // Listener click pulsante "Salva Stato Pagamento"
            $saveBtn.off('click').on('click', function() {
                var $select = $('#dfn-select-booking-payment-status');
                var bId = $select.data('booking-id');
                var newStatus = $select.val();

                if (!confirm('Sei sicuro di voler cambiare lo stato di pagamento in "' + $select.find('option:selected').text().trim() + '"? Verrà aggiornato anche l\'ordine WooCommerce.')) {
                    return;
                }

                $saveBtn.prop('disabled', true).text('Salvataggio...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dfn_admin_update_payment_status',
                        booking_id: bId,
                        new_status: newStatus,
                        nonce: nonce
                    },
                    success: function(res) {
                        $saveBtn.prop('disabled', false).text('Salva Stato Pagamento');
                        if (res.success) {
                            booking.status = newStatus;
                            initialStatus = newStatus;
                            if (newStatus === 'confirmed') {
                                booking.payment_status = 'pagato';
                            } else {
                                booking.payment_status = 'da_pagare';
                            }
                            $saveBtn.fadeOut(200);
                            loadSlots(activeDate);
                            alert(res.data.message || 'Stato aggiornato con successo.');
                        } else {
                            alert('Errore: ' + (res.data.message || 'impossibile aggiornare lo stato.'));
                        }
                    },
                    error: function() {
                        $saveBtn.prop('disabled', false).text('Salva Stato Pagamento');
                        alert('Errore di rete durante il salvataggio.');
                    }
                });
            });

            function escHtml(str) {
                if (!str) return '';
                return str.toString()
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }

            var $faiSection = $('#dfn-details-fai-cards-section');
            var $faiList = $('#dfn-details-fai-cards-list');
            $faiList.empty();

            if (booking.persons_fai && booking.persons_fai > 0) {
                $faiSection.show();
                var html = '<form id="dfn-modal-fai-cards-form" style="margin: 0;">';
                html += '<input type="hidden" name="action" value="dfn_admin_save_fai_cards">';
                html += '<input type="hidden" name="booking_id" value="' + booking.id + '">';
                html += '<input type="hidden" name="order_id" value="' + booking.order_id + '">';
                
                for (var i = 0; i < booking.persons_fai; i++) {
                    var card = (booking.fai_cards && booking.fai_cards[i]) ? booking.fai_cards[i] : { nome: '', cognome: '', tessera: '' };
                    var borderStyle = (i === booking.persons_fai - 1) ? '' : 'border-bottom:1px dashed #e2e8f0; padding-bottom:12px; margin-bottom:12px;';
                    
                    html += '<div class="dfn-fai-card-row" style="' + borderStyle + '">';
                    html += '  <span style="font-size:12px; color:#64748b;">Socio ' + (i + 1) + ':</span>';
                    html += '  <input type="text" name="fai_cards[' + i + '][nome]" value="' + escHtml(card.nome || '') + '" placeholder="Nome" class="dfn-sm-input" style="font-size:12px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; width:100%; box-sizing:border-box;">';
                    html += '  <input type="text" name="fai_cards[' + i + '][cognome]" value="' + escHtml(card.cognome || '') + '" placeholder="Cognome" class="dfn-sm-input" style="font-size:12px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; width:100%; box-sizing:border-box;">';
                    html += '  <input type="text" name="fai_cards[' + i + '][tessera]" value="' + escHtml(card.tessera || '') + '" placeholder="N° Tessera FAI" class="dfn-sm-input" style="font-size:12px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px; width:100%; box-sizing:border-box;">';
                    html += '</div>';
                }
                
                html += '<div style="text-align:right; margin-top:12px;">';
                html += '  <button type="submit" class="dfn-btn dfn-btn-primary" style="font-size:12px; padding:6px 12px; display:inline-flex; align-items:center; gap:5px;"><span class="dashicons dashicons-saved" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span> Salva Tessere</button>';
                html += '</div>';
                html += '</form>';
                $faiList.html(html);

                // Gestione invio form salvataggio tessere
                $('#dfn-modal-fai-cards-form').off('submit').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $btn = $form.find('button[type="submit"]');
                    $btn.prop('disabled', true).text('Salvataggio...');

                    var data = $form.serialize() + '&nonce=' + dfnAdminVars.nonce;

                    $.post(dfnAdminVars.ajaxurl, data, function(res) {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span> Salva Tessere');
                        if (res.success) {
                            alert(res.data.message || 'Tessere salvate con successo.');
                            booking.fai_cards = res.data.fai_cards;
                        } else {
                            alert('Errore: ' + (res.data.message || 'impossibile salvare le tessere.'));
                        }
                    }).fail(function() {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-saved" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span> Salva Tessere');
                        alert('Si è verificato un errore di rete.');
                    });
                });
            } else {
                $faiSection.hide();
            }

            var $btnOrder = $('#dfn-btn-view-order-wc');
            if (booking.order_id) {
                var orderUrl = typeof dfnAdminVars !== 'undefined' ? 
                    dfnAdminVars.ajaxurl.replace('admin-ajax.php', 'post.php?post=' + booking.order_id + '&action=edit') : 
                    '/wp/wp-admin/post.php?post=' + booking.order_id + '&action=edit';
                $btnOrder.attr('href', orderUrl).show();
            } else {
                $btnOrder.hide();
            }

            // Apri come popup livello 2 (sopra il popup lista prenotazioni)
            openModal($modal, 2);
        });

        // ========================================================================
        // 3. GESTIONE MODALI (APERTURA E CHIUSURA)
        // ========================================================================
        function openModal($modal, level) {
            // Livello 1: popup standard (z-index 99999)
            // Livello 2: popup sovrapposto (z-index 100001, sopra al livello 1)
            var zIndex = (level && level >= 2) ? 100001 : 99999;
            $modal.css('z-index', zIndex).fadeIn(250).css('display', 'flex');
        }

        function closeModal($modal) {
            $modal.fadeOut(200, function() {
                $modal.css('z-index', ''); // Ripristina il z-index di default
            });
        }

        $(document).on('click', '.dfn-modal-close, .dfn-modal-close-btn', function() {
            var modalId = $(this).data('modal');
            closeModal($('#' + modalId));
        });

        // Chiudi modale cliccando fuori
        $(window).on('click', function(e) {
            if ($(e.target).hasClass('dfn-sm-modal')) {
                closeModal($(e.target));
            }
        });

        // Apertura popup nota completa
        $(document).on('click', '.dfn-note-balloon', function(e) {
            e.stopPropagation();
            var fullNote = $(this).data('full-note');
            var customer = $(this).data('customer');
            var orderId  = $(this).data('order');

            var $modal = $('#dfn-modal-view-note');
            $modal.find('#dfn-note-modal-subtitle').text('Cliente: ' + customer + (orderId && orderId !== '-' ? ' • Ordine #' + orderId : ''));
            $modal.find('#dfn-note-modal-content').text(fullNote);

            openModal($modal, 3);
        });

        $(document).on('mouseenter', '.dfn-note-balloon', function() {
            $(this).css({ 'background': '#fff1b8', 'border-color': '#ffd666', 'transform': 'translateY(-1px)' });
        }).on('mouseleave', '.dfn-note-balloon', function() {
            $(this).css({ 'background': '#fffbe6', 'border-color': '#ffe58f', 'transform': 'translateY(0)' });
        });

        // ========================================================================
        // 4. AZIONI SUGLI SLOT
        // ========================================================================
        // Generazione Slot Iniziali (Evento Pregresso)
        $(document).on('click', '#dfn-btn-generate-slots', function() {
            if (!confirm('Generare gli slot iniziali per questo evento?')) return;
            
            var $btn = $(this);
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Generazione...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_admin_generate_initial_slots',
                    event_id: eventId,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                        $('#dfn-sm-generation-banner').hide();
                        $('#dfn-sm-dashboard').show();
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Genera Slot Iniziali');
                    }
                },
                error: function() {
                    alert('Errore di comunicazione col server.');
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Genera Slot Iniziali');
                }
            });
        });

        // Apertura modale Aggiungi Slot
        $(document).on('click', '#dfn-btn-add-slot-modal', function() {
            $('#dfn-form-add-slot')[0].reset();
            openModal($('#dfn-modal-add-slot'));
        });

        // Submit form Aggiungi Slot
        $('#dfn-form-add-slot').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serializeArray();
            formData.push({ name: 'action', value: 'dfn_admin_add_slot' });
            formData.push({ name: 'event_id', value: eventId });
            formData.push({ name: 'date', value: activeDate });
            formData.push({ name: 'nonce', value: nonce });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        closeModal($('#dfn-modal-add-slot'));
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                    }
                }
            });
        });

        // Apertura modale Modifica Capacità Slot
        $(document).on('click', '.dfn-btn-edit-capacity', function() {
            var $card = $(this).closest('.dfn-sm-card');
            var id = $card.data('slot-id');
            var timeStart = $card.data('time-start');
            var timeEnd = $card.data('time-end');
            var capacity = $card.data('capacity');
            var bonus = $card.data('bonus');

            var $form = $('#dfn-form-edit-slot');
            $form.find('input[name="slot_id"]').val(id);
            $form.find('input[name="capacity"]').val(capacity);
            $form.find('input[name="bonus_capacity"]').val(bonus);
            
            $('#edit-slot-time-label').text(timeStart + ' - ' + timeEnd);

            openModal($('#dfn-modal-edit-slot'));
        });

        // Submit form Modifica Capacità
        $('#dfn-form-edit-slot').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serializeArray();
            formData.push({ name: 'action', value: 'dfn_admin_update_slot' });
            formData.push({ name: 'nonce', value: nonce });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        closeModal($('#dfn-modal-edit-slot'));
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                    }
                }
            });
        });

        // Toggle Blocca/Sblocca Slot
        $(document).on('click', '.dfn-btn-lock-toggle', function() {
            var $card = $(this).closest('.dfn-sm-card');
            var id = $card.data('slot-id');
            var isLocked = $card.hasClass('status-locked');
            var nextLockState = isLocked ? 0 : 1;

            if (!confirm(isLocked ? 'Vuoi sbloccare questo turno?' : 'Vuoi bloccare questo turno? I visitatori non potranno più prenotarsi online.')) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_admin_lock_slot',
                    slot_id: id,
                    lock: nextLockState,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                    }
                }
            });
        });

        // Elimina Slot Vuoto
        $(document).on('click', '.dfn-btn-delete-slot', function() {
            var $card = $(this).closest('.dfn-sm-card');
            var id = $card.data('slot-id');

            if (!confirm('Sei sicuro di voler eliminare questo turno orario?')) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_admin_delete_slot',
                    slot_id: id,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                    }
                }
            });
        });

        // ========================================================================
        // 5. AZIONI SULLE PRENOTAZIONI
        // ========================================================================
        // Spostamento: Apertura Modale
        $(document).on('click', '.dfn-btn-move-booking', function() {
            // Supporta sia il nuovo popup (.dfn-slot-booking-row) sia i contesti legacy
            var $row = $(this).closest('.dfn-slot-booking-row');
            var bookingId, fromSlotId, personsNeeded;

            if ($row.length) {
                bookingId = $row.data('booking-id');
                fromSlotId = $row.data('slot-id');
                personsNeeded = parseInt($row.data('persons'), 10);
            } else {
                var $item = $(this).closest('.booking-item');
                var $card = $(this).closest('.dfn-sm-card');
                bookingId = $item.data('booking-id');
                fromSlotId = $card.data('slot-id');
                personsNeeded = parseInt($item.data('persons'), 10);
            }

            var $form = $('#dfn-form-move-booking');
            $form.find('input[name="booking_id"]').val(bookingId);
            $form.find('input[name="from_slot_id"]').val(fromSlotId);

            // Abilita/Disabilita opzioni in base ai posti liberi nello slot di destinazione
            var $select = $form.find('select[name="to_slot_id"]');
            $select.find('option').each(function() {
                var $opt = $(this);
                var freePlaces = parseInt($opt.data('free'), 10);
                var optVal = parseInt($opt.val(), 10);

                if (optVal === fromSlotId) {
                    $opt.prop('disabled', true);
                } else if (freePlaces < personsNeeded) {
                    $opt.prop('disabled', true);
                    $opt.text($opt.text().split(' (')[0] + ' (posti insufficienti: servono ' + personsNeeded + ')');
                } else {
                    $opt.prop('disabled', false);
                }
            });

            openModal($('#dfn-modal-move-booking'), 2);
        });

        // Spostamento: Submit Form
        $('#dfn-form-move-booking').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serializeArray();
            formData.push({ name: 'action', value: 'dfn_admin_move_booking' });
            formData.push({ name: 'nonce', value: nonce });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        closeModal($('#dfn-modal-move-booking'));
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                    }
                }
            });
        });

        // Cancellazione Prenotazione
        $(document).on('click', '.dfn-btn-delete-booking', function() {
            // Supporta sia il nuovo popup (.dfn-slot-booking-row) sia i contesti legacy
            var $row = $(this).closest('.dfn-slot-booking-row');
            var id, name;

            if ($row.length) {
                id = $row.data('booking-id');
                name = $row.data('name');
            } else {
                var $item = $(this).closest('.booking-item');
                id = $item.data('booking-id');
                name = $item.data('name');
            }

            if (!confirm('Annullare definitivamente la prenotazione per "' + name + '"? Questo cancellerà anche l\'ordine WooCommerce correlato.')) {
                return;
            }

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dfn_admin_delete_booking',
                    booking_id: id,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Chiudi il popup lista prenotazioni e ricarica
                        closeModal($('#dfn-modal-slot-bookings'));
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                    }
                }
            });
        });

        // ========================================================================
        // 6. INSERIMENTO PRENOTAZIONE MANUALE
        // ========================================================================
        // Apertura Modale Prenotazione Manuale
        $(document).on('click', '#dfn-btn-add-booking-modal', function() {
            $('#dfn-form-add-booking')[0].reset();
            $('#dfn-fai-cards-container').hide();
            $('#fai-cards-fields-list').empty();
            openModal($('#dfn-modal-add-booking'));
        });

        // Mostra dinamicamente campi tessere FAI quando cambia qty_fai
        $(document).on('change keyup', '#dfn-form-add-booking input[name="qty_fai"]', function() {
            var qty = parseInt($(this).val(), 10) || 0;
            var $container = $('#dfn-fai-cards-container');
            var $list = $('#fai-cards-fields-list');

            $list.empty();

            if (qty > 0) {
                $container.slideDown(250);
                for (var i = 0; i < qty; i++) {
                    var idx = i + 1;
                    $list.append(
                        '<div class="fai-card-row">' +
                            '<h5>Partecipante FAI #' + idx + '</h5>' +
                            '<div class="dfn-form-row">' +
                                '<div class="dfn-form-group">' +
                                    '<label>Nome Socio</label>' +
                                    '<input type="text" name="fai_cards[' + i + '][nome]" required>' +
                                '</div>' +
                                '<div class="dfn-form-group">' +
                                    '<label>Cognome Socio</label>' +
                                    '<input type="text" name="fai_cards[' + i + '][cognome]" required>' +
                                '</div>' +
                                '<div class="dfn-form-group">' +
                                    '<label>N° Tessera FAI</label>' +
                                    '<input type="text" name="fai_cards[' + i + '][tessera]" required>' +
                                '</div>' +
                            '</div>' +
                        '</div>'
                    );
                }
            } else {
                $container.slideUp(200);
            }
        });

        // Inserimento manuale: Submit Form
        $('#dfn-form-add-booking').on('submit', function(e) {
            e.preventDefault();
            var formData = $(this).serializeArray();
            formData.push({ name: 'action', value: 'dfn_admin_add_booking' });
            formData.push({ name: 'event_id', value: eventId });
            formData.push({ name: 'date', value: activeDate });
            formData.push({ name: 'nonce', value: nonce });

            var $btn = $(this).find('button[type="submit"]');
            $btn.prop('disabled', true).text('Registrazione...');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    $btn.prop('disabled', false).text('Registra Prenotazione');
                    if (response.success) {
                        closeModal($('#dfn-modal-add-booking'));
                        loadSlots(activeDate);
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Registra Prenotazione');
                    alert('Errore del server durante l\'operazione.');
                }
            });
        });

        // ========================================================================
        // 7. ESPORTAZIONE CSV (LATO CLIENT)
        // ========================================================================
        $(document).on('click', '#dfn-btn-export-csv', function() {
            if (!currentData || currentData.length === 0) {
                alert('Nessun dato da esportare.');
                return;
            }

            var csvRows = [];
            // Header
            csvRows.push(['Orario Inizio', 'Orario Fine', 'Nominativo', 'Email', 'Telefono', 'Biglietti Totali', 'Standard', 'Socio FAI', 'Stato', 'Data Prenotazione', 'Note'].join(';'));

            currentData.forEach(function(slot) {
                slot.bookings.forEach(function(b) {
                    var row = [
                        slot.time_start,
                        slot.time_end,
                        b.customer_name.replace(/"/g, '""'),
                        b.customer_email.replace(/"/g, '""'),
                        b.customer_phone ? b.customer_phone.replace(/"/g, '""') : '',
                        b.slot_persons,
                        b.persons_standard,
                        b.persons_fai,
                        b.status,
                        b.created_at,
                        b.notes ? b.notes.replace(/\r?\n|\r/g, ' ').replace(/"/g, '""') : ''
                    ];
                    csvRows.push('"' + row.join('";"') + '"');
                });
            });

            if (csvRows.length <= 1) {
                alert('Nessuna prenotazione presente per questa data.');
                return;
            }

            var csvContent = "\uFEFF" + csvRows.join("\n"); // Aggiunge BOM per compatibilità Excel UTF-8
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            
            var link = document.createElement("a");
            link.setAttribute("href", url);
            link.setAttribute("download", "prenotazioni_turni_" + activeDate + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });

        // ========================================================================
        // 8. STAMPA PDF (LATO CLIENT CON FORMATTAZIONE PREMIUM)
        // ========================================================================
        $(document).on('click', '#dfn-btn-print-pdf', function() {
            if (!currentData || currentData.length === 0) {
                alert('Nessun dato da stampare.');
                return;
            }

            // Recupera dettagli dell'evento per l'intestazione
            var eventTitle = $('.dfn-logo-area h1').text().replace('Gestione Turni — ', '').replace('Prenotazioni — ', '');
            var formattedDate = $('.dfn-pill-date.active').text().trim();

            var printHtml = '<!DOCTYPE html><html><head><meta charset="utf-8">';
            printHtml += '<title>Prenotazioni ' + eventTitle + ' - ' + formattedDate + '</title>';
            printHtml += '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">';
            printHtml += '<style>';
            printHtml += 'body { font-family: "Inter", sans-serif; color: #1e293b; line-height: 1.3; margin: 0; padding: 0; background: #fff; font-size: 10px; }';
            printHtml += '.print-header { border-bottom: 2px solid #004b23; padding-bottom: 6px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: flex-end; }';
            printHtml += '.print-header h1 { font-size: 16px; color: #004b23; margin: 0; font-weight: 700; }';
            printHtml += '.print-header .date-badge { font-size: 11px; background: #e74f30; color: #fff; padding: 2px 8px; border-radius: 12px; font-weight: 700; }';
            
            printHtml += '.stats-summary { display: flex; justify-content: space-between; background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 6px; margin-bottom: 12px; }';
            printHtml += '.stat-item { display: flex; align-items: center; gap: 4px; }';
            printHtml += '.stat-val { font-size: 12px; font-weight: 700; color: #004b23; }';
            printHtml += '.stat-lbl { font-size: 9px; color: #64748b; font-weight: 600; text-transform: uppercase; }';

            printHtml += '.turno-section { margin-bottom: 15px; page-break-inside: avoid; }';
            printHtml += '.turno-title-bar { background: #004b23; color: #fff; padding: 4px 8px; font-size: 11px; font-weight: 700; border-radius: 4px 4px 0 0; display: flex; justify-content: space-between; }';
            printHtml += '.turno-title-bar span { font-weight: 700; }';
            printHtml += '.print-table { width: 100%; border-collapse: collapse; margin-top: 0; border: 1px solid #cbd5e1; border-top: none; }';
            printHtml += '.print-table th, .print-table td { padding: 4px 6px; font-size: 10px; text-align: left; border-bottom: 1px solid #cbd5e1; border-right: 1px solid #cbd5e1; }';
            printHtml += '.print-table th { background: #f1f5f9; color: #475569; font-weight: 700; text-transform: uppercase; }';
            printHtml += '.print-table th:last-child, .print-table td:last-child { border-right: none; }';
            printHtml += '.print-table tr:last-child td { border-bottom: none; }';
            printHtml += '.print-table tr:nth-child(even) { background: #f8fafc; }';
            
            printHtml += '.col-nowrap { white-space: nowrap; }';
            printHtml += '.payment-status-badge { font-weight: 700; }';
            printHtml += '.payment-status-pagato { color: #166534; }';
            printHtml += '.payment-status-non-pagato { color: #c2410c; }';
            
            printHtml += '@page { margin: 10mm; }';
            printHtml += '@media print {';
            printHtml += '  body { margin: 0; }';
            printHtml += '  .no-print { display: none; }';
            printHtml += '  .turno-section { page-break-inside: avoid; }';
            printHtml += '}';
            printHtml += '</style></head><body>';

            // Header
            printHtml += '<div class="print-header">';
            printHtml += '<div><h1>Prenotazioni Evento: ' + eventTitle + '</h1></div>';
            printHtml += '<div><span class="date-badge">' + formattedDate + '</span></div>';
            printHtml += '</div>';

            // Calcolo statistiche globali della giornata
            var totalBookings = 0;
            var totalPlaces = 0;
            var totalStandard = 0;
            var totalFai = 0;

            currentData.forEach(function(slot) {
                totalBookings += slot.bookings.length;
                totalPlaces += slot.booked_count;
                slot.bookings.forEach(function(b) {
                    totalStandard += b.persons_standard;
                    totalFai += b.persons_fai;
                });
            });

            // Riepilogo Statistiche
            printHtml += '<div class="stats-summary">';
            printHtml += '<div class="stat-item"><div class="stat-val">' + totalBookings + '</div><div class="stat-lbl">Prenotazioni</div></div>';
            printHtml += '<div class="stat-item"><div class="stat-val">' + totalPlaces + '</div><div class="stat-lbl">Posti Totali</div></div>';
            printHtml += '<div class="stat-item"><div class="stat-val">' + totalStandard + '</div><div class="stat-lbl">Standard</div></div>';
            printHtml += '<div class="stat-item"><div class="stat-val">' + totalFai + '</div><div class="stat-lbl">Soci FAI</div></div>';
            printHtml += '</div>';

            // Lista Turni
            var hasBookings = false;

            currentData.forEach(function(slot) {
                if (slot.bookings.length === 0) {
                    return; // Salta i turni senza prenotazioni
                }
                hasBookings = true;

                var totalCapacity = slot.capacity + slot.bonus_capacity;
                var slotLabel = slot.is_free_flow 
                    ? 'Flusso Libero (' + slot.time_start + ' - ' + slot.time_end + ')' 
                    : 'Turno: ' + slot.time_start + ' - ' + slot.time_end;

                printHtml += '<div class="turno-section">';
                printHtml += '<div class="turno-title-bar">';
                printHtml += '<span>' + slotLabel + '</span>';
                printHtml += '<span>Posti occupati: ' + slot.booked_count + ' / ' + totalCapacity + '</span>';
                printHtml += '</div>';

                printHtml += '<table class="print-table">';
                printHtml += '<thead><tr>';
                printHtml += '<th style="width: 5%;">N°</th>';
                printHtml += '<th style="width: 45%;">Nominativo</th>';
                printHtml += '<th class="col-nowrap" style="width: 20%;">Posti prenotati</th>';
                printHtml += '<th class="col-nowrap" style="width: 15%;">Contributo da versare</th>';
                printHtml += '<th class="col-nowrap" style="width: 15%;">Stato pagamento</th>';
                printHtml += '</tr></thead>';
                printHtml += '<tbody>';

                // Ordina prenotazioni per cognome (e nome) in ordine alfabetico crescente
                var sortedBookings = slot.bookings.slice().sort(function(a, b) {
                    var lastNameA = (a.customer_last_name || '').trim().toLowerCase();
                    var lastNameB = (b.customer_last_name || '').trim().toLowerCase();
                    var firstNameA = (a.customer_first_name || '').trim().toLowerCase();
                    var firstNameB = (b.customer_first_name || '').trim().toLowerCase();
                    
                    if (lastNameA !== lastNameB) {
                        return lastNameA.localeCompare(lastNameB);
                    }
                    return firstNameA.localeCompare(firstNameB);
                });

                sortedBookings.forEach(function(b, idx) {
                    var ticketsDesc = b.slot_persons + ' tot. (' + b.persons_standard + ' Std';
                    if (b.persons_fai > 0) {
                        ticketsDesc += ' + ' + b.persons_fai + ' FAI';
                    }
                    ticketsDesc += ')';

                    var displayName = '';
                    if (b.customer_last_name) {
                        displayName = b.customer_last_name + ' ' + b.customer_first_name;
                    } else {
                        displayName = b.customer_name;
                    }

                    if (b.notes) {
                        displayName += '<div style="font-size:9px; color:#334155; font-style:italic; margin-top:2px; font-weight:normal;">💬 Note: ' + b.notes.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
                    }

                    printHtml += '<tr>';
                    printHtml += '<td>' + (idx + 1) + '</td>';
                    printHtml += '<td><strong>' + displayName + '</strong></td>';
                    printHtml += '<td class="col-nowrap">' + ticketsDesc + '</td>';
                    printHtml += '<td class="col-nowrap">' + contribution + '</td>';
                    printHtml += '<td class="col-nowrap"><span class="payment-status-badge ' + paymentStatusClass + '">' + paymentStatusText + '</span></td>';
                    printHtml += '</tr>';
                });

                printHtml += '</tbody></table>';
                printHtml += '</div>';
            });

            if (!hasBookings) {
                printHtml += '<div style="text-align:center; padding:50px; color:#64748b;">Nessuna prenotazione presente per questa giornata.</div>';
            }

            printHtml += '</body>';
            printHtml += '<script>';
            printHtml += 'function startPrint() {';
            printHtml += '    window.focus();';
            printHtml += '    window.print();';
            printHtml += '}';
            printHtml += 'if (document.readyState === "complete") {';
            printHtml += '    startPrint();';
            printHtml += '} else {';
            printHtml += '    window.onload = startPrint;';
            printHtml += '}';
            printHtml += '</script>';
            printHtml += '</html>';

            // Scrive il documento in un iframe nascosto per attivare la stampa senza aprire tab
            var iframe = document.getElementById('dfn-print-iframe');
            if (!iframe) {
                iframe = document.createElement('iframe');
                iframe.id = 'dfn-print-iframe';
                iframe.style.position = 'absolute';
                iframe.style.width = '0px';
                iframe.style.height = '0px';
                iframe.style.border = 'none';
                iframe.style.left = '-9999px';
                iframe.style.top = '-9999px';
                document.body.appendChild(iframe);
            }
            var doc = iframe.contentWindow.document;
            doc.open();
            doc.write(printHtml);
            doc.close();
        });

        // ========================================================================
        // LEGACY CASSA CHECK-IN & MESSAGES & LOG HANDLERS
        // ========================================================================
        var needsReload = false;

        $(document).on("click", ".cv-open-popup-btn", function(e) {
            e.preventDefault(); 
            needsReload = false;
            $("#cv-modal-cliente-name").text($(this).data("cliente"));
            $("#cv-modal-buttons-area").html($(this).siblings(".cv-popup-data-container").html());
            $("#cv-cassa-modal").css("display", "flex");
        });

        $(document).on("click", ".cv-open-history-btn", function(e) {
            e.preventDefault();
            $("#cv-history-cliente-name").text($(this).data("cliente"));
            $("#cv-history-content-area").html($(this).siblings(".cv-history-data-container").html());
            $("#cv-history-modal").css("display", "flex");
        });

        function closeReportModals() {
            $("#cv-cassa-modal, #cv-history-modal").hide();
            if (needsReload) {
                loadSlots(activeDate);
            }
            $("#cv-cassa-modal .cv-close-modal-btn").text("Chiudi Finestra"); 
        }

        $(document).on("click", ".cv-close-modal-btn", closeReportModals);
        $("#cv-cassa-modal, #cv-history-modal").on("click", function(e) { if (e.target === this) closeReportModals(); });

        $(document).on("click", ".cv-manual-checkin-btn", function(e) {
            e.preventDefault(); 
            var btn = $(this); 
            var orderId = btn.data("order"); 
            var ticketIdx = btn.data("ticket");
            btn.prop("disabled", true).css("opacity", "0.5").text("⏳ Elaborazione...");
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: "cv_process_manual_checkin",
                    security: dfnAdminVars.nonceManual,
                    order_id: orderId,
                    ticket: ticketIdx
                },
                success: function(response) {
                    if(response.success) {
                        needsReload = true;
                        var successHtml = '<div style="margin-bottom:8px; padding:10px; background:#eaf7ea; color:#166534; border: 1px solid #c3e6c3; border-radius: 4px; display:flex; justify-content:space-between; align-items:center;"><span>✅ Biglietto ' + ticketIdx + ' validato</span><button class="button cv-undo-checkin-btn" data-order="' + orderId + '" data-ticket="' + ticketIdx + '" style="color:#d63638; border-color:#d63638; padding:0 8px; min-height:26px; line-height:24px;">Annulla</button></div>';
                        btn.replaceWith(successHtml);
                        $("#cv-cassa-modal .cv-close-modal-btn").text("🔄 Chiudi e Aggiorna Tabella");
                    } else { 
                        alert("Errore: " + response.data); 
                        btn.prop("disabled", false).css("opacity", "1").text("✔️ Valida Biglietto " + ticketIdx); 
                    }
                },
                error: function() {
                    alert("Errore di rete durante la validazione.");
                    btn.prop("disabled", false).css("opacity", "1").text("✔️ Valida Biglietto " + ticketIdx);
                }
            });
        });

        $(document).on("click", ".cv-undo-checkin-btn", function(e) {
            e.preventDefault(); 
            if(!confirm("Vuoi davvero annullare la validazione di questo biglietto? Tornerà ad essere valido per l'ingresso.")) return;
            var btn = $(this); 
            var orderId = btn.data("order"); 
            var ticketIdx = btn.data("ticket"); 
            var wrapper = btn.closest("div");
            btn.prop("disabled", true).text("⏳...");
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: "cv_process_undo_checkin",
                    security: dfnAdminVars.nonceManual,
                    order_id: orderId,
                    ticket: ticketIdx
                },
                success: function(response) {
                    if(response.success) {
                        needsReload = true;
                        wrapper.replaceWith('<button class="button cv-manual-checkin-btn" data-order="' + orderId + '" data-ticket="' + ticketIdx + '" style="margin-bottom:8px; display:block; width:100%; border-color:#00a32a; color:#00a32a; height: 40px; cursor:pointer;">✔️ Valida Biglietto ' + ticketIdx + '</button>');
                        $("#cv-cassa-modal .cv-close-modal-btn").text("🔄 Chiudi e Aggiorna Tabella");
                    } else { 
                        alert("Errore: " + response.data); 
                        btn.prop("disabled", false).text("Annulla"); 
                    }
                },
                error: function() {
                    alert("Errore di rete.");
                    btn.prop("disabled", false).text("Annulla");
                }
            });
        });

        $(document).on("click", ".cv-single-reminder-btn", function(e) {
            e.preventDefault(); 
            var btn = $(this); 
            var orderId = btn.data("order");
            btn.prop("disabled", true).text("⏳...");
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: "cv_send_single_reminder",
                    security: dfnAdminVars.nonceReminder,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) { 
                        alert("✅ Promemoria inviato con successo!"); 
                        btn.prop("disabled", false).text("📧 Reinvia Reminder"); 
                    } else { 
                        alert("❌ Errore: " + response.data); 
                        btn.prop("disabled", false).text("📧 Invia Reminder"); 
                    }
                },
                error: function() {
                    alert("❌ Errore di rete.");
                    btn.prop("disabled", false).text("📧 Invia Reminder");
                }
            });
        });

        $(document).on("click", ".cv-single-feedback-btn", function(e) {
            e.preventDefault(); 
            var btn = $(this); 
            var orderId = btn.data("order");
            btn.prop("disabled", true).text("⏳...");
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: "cv_send_single_feedback",
                    security: dfnAdminVars.nonceFeedback,
                    order_id: orderId
                },
                success: function(response) {
                    if (response.success) { 
                        alert("✅ Richiesta recensione inviata con successo!"); 
                        btn.prop("disabled", false).text("⭐ Reinvia Recensione"); 
                    } else { 
                        alert("❌ Errore: " + response.data); 
                        btn.prop("disabled", false).text("⭐ Chiedi Recensione"); 
                    }
                },
                error: function() {
                    alert("❌ Errore di rete.");
                    btn.prop("disabled", false).text("⭐ Chiedi Recensione");
                }
            });
        });

        $(document).on("click", "#cv-send-reminders-btn", function(e) {
            e.preventDefault();
            if(!confirm("Sei sicuro di voler inviare l'email di promemoria a tutti gli acquirenti? L'invio avverrà in automatico a blocchi di 5 per garantire la massima sicurezza del server.")) return;
            var btn = $(this); 
            var originalText = btn.text(); 
            btn.prop("disabled", true); 
            var totalSent = 0;
            
            function inviaLotto() {
                btn.text("⏳ Invio in corso (" + totalSent + " inviate)... non chiudere!");
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: "cv_send_event_reminders",
                        security: dfnAdminVars.nonceReminder,
                        event_id: eventId
                    },
                    success: function(response) {
                        if(response.success) {
                            totalSent += response.data.sent;
                            if (response.data.has_more) { 
                                inviaLotto(); 
                            } else {
                                if(totalSent > 0) alert("✅ Operazione completata! Inviate in totale " + totalSent + " email.");
                                else alert("✅ Nessuna email inviata. Tutti l'hanno già ricevuta.");
                                btn.prop("disabled", false).text(originalText);
                                loadSlots(activeDate);
                            }
                        } else { 
                            alert("❌ Errore: " + response.data); 
                            btn.prop("disabled", false).text(originalText); 
                        }
                    },
                    error: function() {
                        alert("❌ Errore di rete.");
                        btn.prop("disabled", false).text(originalText);
                    }
                });
            }
            inviaLotto();
        });

        $(document).on("click", "#cv-send-feedback-btn", function(e) {
            e.preventDefault();
            if(!confirm("⚠️ Vuoi davvero inviare la richiesta di recensione a tutti i partecipanti di questo evento che sono stati convalidati?\n\nL'email verrà inviata solo a chi ha effettuato correttamente il check-in.")) return;
            var btn = $(this); 
            var originalText = btn.text(); 
            btn.prop("disabled", true); 
            var totalSent = 0;
            
            function inviaLottoFeedback() {
                btn.text("⏳ Invio in corso (" + totalSent + " inviate)...");
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: "cv_send_feedback_request",
                        security: dfnAdminVars.nonceFeedback,
                        event_id: eventId
                    },
                    success: function(response) {
                        if(response.success) {
                            totalSent += response.data.sent;
                            if (response.data.has_more) { 
                                inviaLottoFeedback(); 
                            } else {
                                if(totalSent > 0) alert("✅ Operazione completata! Inviate " + totalSent + " email di richiesta recensione.");
                                else alert("✅ Nessuna email inviata. Nessuno soddisfa i criteri o l'hanno già ricevuta.");
                                btn.prop("disabled", false).text(originalText);
                                loadSlots(activeDate);
                            }
                        } else { 
                            alert("❌ Errore: " + response.data); 
                            btn.prop("disabled", false).text(originalText); 
                        }
                    },
                    error: function() {
                        alert("❌ Errore di rete.");
                        btn.prop("disabled", false).text(originalText);
                    }
                });
            }
            inviaLottoFeedback();
        });

    });
})(jQuery);

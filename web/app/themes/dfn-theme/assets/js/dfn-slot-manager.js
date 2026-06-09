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

        var eventId = parseInt($wrapper.data('event-id'), 10);
        var nonce = $wrapper.data('nonce');
        var ajaxurl = typeof dfnAdminVars !== 'undefined' ? dfnAdminVars.ajaxurl : '/wp/wp-admin/admin-ajax.php';
        
        var currentData = null; // Memorizza i dati AJAX correnti
        var activeDate = $('.dfn-pill-date.active').data('date');

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
                        return b.customer_name.toLowerCase().indexOf(searchQuery) !== -1 ||
                               b.customer_email.toLowerCase().indexOf(searchQuery) !== -1 ||
                               b.customer_phone.toLowerCase().indexOf(searchQuery) !== -1;
                    });
                    
                    // Se non ci sono prenotazioni corrispondenti e lo slot non corrisponde all'orario, lo saltiamo se c'è una query attiva
                    if (filteredBookings.length === 0 && slot.time_start.indexOf(searchQuery) === -1) {
                        return; // salta rendering di questa card
                    }
                }

                var $card = $('<div class="dfn-sm-card ' + statusClass + '" data-slot-id="' + slot.id + '" data-time-start="' + slot.time_start + '" data-time-end="' + slot.time_end + '" data-capacity="' + slot.capacity + '" data-bonus="' + slot.bonus_capacity + '"></div>');
                
                var cardHeaderHtml = 
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

                // Sezione accordion prenotazioni
                var accordionOpenClass = (searchQuery !== '') ? 'open' : '';
                var accordionStyle = (searchQuery !== '') ? 'display: block;' : '';
                var bookingsHeaderHtml = 
                    '<div class="dfn-sm-bookings-header ' + accordionOpenClass + '">' +
                        '<span>Prenotazioni (' + filteredBookings.length + ')</span>' +
                        '<span class="dashicons dashicons-arrow-down-alt2"></span>' +
                    '</div>';

                var bookingsListHtml = '<div class="dfn-sm-bookings-list" style="' + accordionStyle + '">';
                if (filteredBookings.length === 0) {
                    bookingsListHtml += '<div class="no-bookings">Nessuna prenotazione trovata.</div>';
                } else {
                    filteredBookings.forEach(function(b) {
                        bookingsListHtml += 
                            '<div class="booking-item" data-booking-id="' + b.id + '" data-persons="' + b.slot_persons + '" data-name="' + b.customer_name + '">' +
                                '<div class="booking-details">' +
                                    '<div class="customer-info">' +
                                        '<strong>' + b.customer_name + '</strong>' +
                                        (b.customer_email !== 'no-email@dfn.it' ? ' | <small>' + b.customer_email + '</small>' : '') +
                                        (b.customer_phone ? ' | <small>' + b.customer_phone + '</small>' : '') +
                                    '</div>' +
                                    '<div class="booking-meta">' +
                                        '<span class="badge badge-qty">' + b.slot_persons + ' pers.</span>' +
                                        (b.persons_fai > 0 ? '<span class="badge badge-fai" title="Tessere FAI da verificare"><span class="dashicons dashicons-awards"></span> FAI</span>' : '') +
                                        (b.notes ? '<span class="badge badge-notes" title="' + b.notes + '"><span class="dashicons dashicons-testimonial"></span></span>' : '') +
                                        '<span class="badge badge-status-' + b.status + '">' + b.status + '</span>' +
                                    '</div>' +
                                '</div>' +
                                '<div class="booking-actions">' +
                                    '<button type="button" class="dfn-icon-btn dfn-btn-move-booking" title="Sposta di Turno"><span class="dashicons dashicons-move"></span></button>' +
                                    '<button type="button" class="dfn-icon-btn dfn-btn-delete-booking text-danger" title="Cancella Prenotazione"><span class="dashicons dashicons-no-alt"></span></button>' +
                                '</div>' +
                            '</div>';
                    });
                }
                bookingsListHtml += '</div>';

                $card.append(cardHeaderHtml);
                $card.append(progressHtml);
                $card.append(bookingsHeaderHtml);
                $card.append(bookingsListHtml);

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
                var total = slot.capacity + slot.bonus_capacity;
                var free = total - slot.booked_count;
                var label = slot.time_start + ' - ' + slot.time_end + ' (' + free + ' posti liberi)';
                
                $moveSelect.append('<option value="' + slot.id + '" data-free="' + free + '">' + label + '</option>');
                $addBookingSelect.append('<option value="' + slot.id + '">' + label + '</option>');
            });
        }

        // Ricerca in tempo reale
        $(document).on('keyup input', '#dfn-sm-search', function() {
            if (currentData) {
                renderGrid(currentData);
            }
        });

        // Accordion prenotazioni
        $(document).on('click', '.dfn-sm-bookings-header', function() {
            var $list = $(this).next('.dfn-sm-bookings-list');
            $(this).toggleClass('open');
            $list.slideToggle(200);
        });

        // Click sui dettagli per aprire la modale popup
        $(document).on('click', '.booking-details', function() {
            var $item = $(this).closest('.booking-item');
            var bookingId = parseInt($item.data('booking-id'), 10);
            var slotId = parseInt($(this).closest('.dfn-sm-card').data('slot-id'), 10);

            if (!currentData) return;

            // Trova lo slot corrispondente
            var slot = currentData.find(function(s) {
                return s.id === slotId;
            });
            if (!slot) return;

            // Trova la prenotazione corrispondente
            var booking = slot.bookings.find(function(b) {
                return b.id === bookingId;
            });
            if (!booking) return;

            var $modal = $('#dfn-modal-booking-details');
            var $tbody = $modal.find('.dfn-details-table tbody');
            $tbody.empty();

            var rows = [
                ['Nominativo:', '<strong>' + booking.customer_name + '</strong>'],
                ['Email:', booking.customer_email !== 'no-email@dfn.it' ? booking.customer_email : '<em>Non fornita</em>'],
                ['Telefono:', booking.customer_phone ? booking.customer_phone : '<em>Non fornito</em>'],
                ['Posti Prenotati:', booking.slot_persons + ' totali (Standard: ' + booking.persons_standard + ' | FAI: ' + booking.persons_fai + ')'],
                ['Turno:', slot.time_start + ' - ' + slot.time_end],
                ['Stato:', '<span class="badge badge-status-' + booking.status + '">' + booking.status + '</span>'],
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

            // Gestione tessere FAI collegate
            var $faiSection = $('#dfn-details-fai-cards-section');
            var $faiList = $('#dfn-details-fai-cards-list');
            $faiList.empty();

            if (booking.fai_cards && booking.fai_cards.length > 0) {
                $faiSection.show();
                booking.fai_cards.forEach(function(card, idx) {
                    var borderStyle = (idx === booking.fai_cards.length - 1) ? '' : ' border-bottom:1px dashed #e2e8f0;';
                    $faiList.append(
                        '<div style="padding:8px 0; font-size:12px; color:#334155;' + borderStyle + '">' +
                            '👤 <strong>' + card.nome + ' ' + card.cognome + '</strong> - Tessera FAI: <code>' + card.tessera + '</code>' +
                        '</div>'
                    );
                });
            } else {
                $faiSection.hide();
            }

            // Imposta il link per visualizzare l'ordine in WC
            var $btnOrder = $('#dfn-btn-view-order-wc');
            if (booking.order_id) {
                var orderUrl = typeof dfnAdminVars !== 'undefined' ? 
                    dfnAdminVars.ajaxurl.replace('admin-ajax.php', 'post.php?post=' + booking.order_id + '&action=edit') : 
                    '/wp/wp-admin/post.php?post=' + booking.order_id + '&action=edit';
                $btnOrder.attr('href', orderUrl).show();
            } else {
                $btnOrder.hide();
            }

            openModal($modal);
        });

        // ========================================================================
        // 3. GESTIONE MODALI (APERTURA E CHIUSURA)
        // ========================================================================
        function openModal($modal) {
            $modal.fadeIn(250).css('display', 'flex');
        }

        function closeModal($modal) {
            $modal.fadeOut(200);
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
            var $item = $(this).closest('.booking-item');
            var $card = $(this).closest('.dfn-sm-card');
            var bookingId = $item.data('booking-id');
            var fromSlotId = $card.data('slot-id');
            var personsNeeded = parseInt($item.data('persons'), 10);

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

            openModal($('#dfn-modal-move-booking'));
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
            var $item = $(this).closest('.booking-item');
            var id = $item.data('booking-id');
            var name = $item.data('name');

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
            formData.push({ name: 'action', value: 'dfn_ajax_admin_add_booking' });
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

    });
})(jQuery);

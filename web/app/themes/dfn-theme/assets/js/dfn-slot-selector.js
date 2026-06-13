/**
 * DFN Booking System 2.0 — Premium Slot Selector Script
 *
 * Gestisce l'interattività frontend per la scelta dei turni, caricamento AJAX,
 * filling automatico e sincronizzazione del carrello WooCommerce.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

jQuery(document).ready(function($) {
    $('.dfn-booking-widget').each(function() {
        var $widget = $(this);
        var eventId = parseInt($widget.data('event-id'));
        var isTimeSlots = $widget.data('access-type') === 'time_slots';
        var allocationMode = $widget.data('allocation-mode');
        
        var $dateInput = $widget.find('.dfn-date-input');
        var $slotsContainer = $widget.find('.dfn-slots-container');
        var $hiddenSlotId = $widget.find('input[name="dfn_booking_slot_id"]');
        
        var $btnNext = $widget.find('.dfn-widget-btn-next');
        var $btnPrev = $widget.find('.dfn-widget-btn-prev');
        var $btnReset = $widget.find('.dfn-widget-btn-reset');
        
        var $realSubmitBtn = $widget.find('.dfn-step-2 button[type="submit"]');
        var originalSubmitHtml = $realSubmitBtn.html();
        var defaultDate = $dateInput.val() || '';
        
        var $feedbackArea = $widget.find('.dfn-widget-feedback');
        
        var $step1 = $widget.find('.dfn-step-1');
        var $step2 = $widget.find('.dfn-step-2');
        var $step3 = $widget.find('.dfn-step-3');
        
        var $faiFieldsSection = $widget.find('.dfn-fai-cards-fields-section');
        var $faiFieldsContainer = $widget.find('.dfn-fai-cards-inputs-container');
        var $faiChipsSection = $widget.find('.dfn-fai-chips-container');
        var $faiChipsList = $widget.find('.dfn-fai-chips-list');

        // Valida Step 1 (Seleziona partecipanti, data, turno)
        function validateStep1() {
            var qtyStandard = parseInt($widget.find('input[name="quantity"]').val()) || 0;
            var qtyFai = parseInt($widget.find('input[name="dfn_qty_fai"]').val()) || 0;
            var totalQty = qtyStandard + qtyFai;

            var dateSelected = $dateInput.val() !== '';
            var slotSelected = !isTimeSlots || allocationMode === 'automatic' || $hiddenSlotId.val() !== '';

            if (totalQty > 0 && dateSelected && slotSelected) {
                $btnNext.prop('disabled', false);
            } else {
                $btnNext.prop('disabled', true);
            }
        }

        // Ascolta modifiche alle quantità
        $widget.find('input[name="quantity"], input[name="dfn_qty_fai"]').on('input change', function() {
            var qtyStandard = parseInt($widget.find('input[name="quantity"]').val()) || 0;
            var qtyFai = parseInt($widget.find('input[name="dfn_qty_fai"]').val()) || 0;
            if (qtyStandard < 0) $widget.find('input[name="quantity"]').val(0);
            if (qtyFai < 0) $widget.find('input[name="dfn_qty_fai"]').val(0);

            if ($dateInput.val() !== '') {
                loadSlots($dateInput.val());
            }
            validateStep1();
        });

        // Ascolta modifiche alla data
        $dateInput.on('change', function() {
            var dateVal = $(this).val();
            $hiddenSlotId.val('');
            $feedbackArea.html('');
            
            if (dateVal !== '') {
                loadSlots(dateVal);
            } else {
                $slotsContainer.html('');
            }
            validateStep1();
        });

        // Caricamento AJAX degli slot
        function loadSlots(dateStr) {
            if (!isTimeSlots || allocationMode === 'automatic') {
                return;
            }

            var qtyStandard = parseInt($widget.find('input[name="quantity"]').val()) || 0;
            var qtyFai = parseInt($widget.find('input[name="dfn_qty_fai"]').val()) || 0;
            var totalQty = qtyStandard + qtyFai;

            // Renderizza loader Shimmer
            var shimmerHtml = '<div class="dfn-slots-shimmer">';
            for (var i = 0; i < 4; i++) {
                shimmerHtml += '<div class="dfn-shimmer-pill"></div>';
            }
            shimmerHtml += '</div>';
            $slotsContainer.html(shimmerHtml);

            $.ajax({
                url: dfnVars.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'dfn_get_event_slots',
                    event_id: eventId,
                    date: dateStr,
                    nonce: dfnVars.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.length > 0) {
                        renderSlots(response.data, totalQty);
                    } else {
                        $slotsContainer.html('<p style="color:#64748b; font-size:13px; text-align:center; padding:10px;">Nessun turno disponibile per questa data.</p>');
                    }
                },
                error: function() {
                    $slotsContainer.html('<p style="color:#dc2626; font-size:13px; text-align:center; padding:10px;">Errore nel caricamento dei turni orari. Riprova.</p>');
                }
            });
        }

        // Renderizza le pillole degli slot
        function renderSlots(slots, totalQty) {
            $slotsContainer.html('');
            var gridHtml = '<div class="dfn-slots-grid">';
            
            if (allocationMode === 'automatic') {
                var autoSlot = null;
                $.each(slots, function(idx, slot) {
                    var available = slot.capacity + slot.bonus - slot.booked;
                    if (available >= totalQty) {
                        autoSlot = slot;
                        return false;
                    }
                });

                if (autoSlot) {
                    $hiddenSlotId.val(autoSlot.slot_id);
                    $slotsContainer.html('<div class="dfn-feedback-toast">🤖 <strong>Assegnazione Automatica:</strong> Ti verrà assegnato il miglior slot disponibile (' + autoSlot.time + ') al momento dell\'invio.</div>');
                } else {
                    $slotsContainer.html('<div class="dfn-feedback-toast" style="background:#fee2e2; border-color:#fecaca; color:#991b1b;">❌ <strong>Nessun turno disponibile:</strong> Non ci sono slot con posti sufficienti per il tuo gruppo. Sarai inserito in Lista di Attesa.</div>');
                }
                validateStep1();
                return;
            }

            $.each(slots, function(idx, slot) {
                var available = slot.capacity - slot.booked;
                var bonusAvailable = slot.bonus;
                var totalAvailable = available + bonusAvailable;

                var isLow = totalAvailable > 0 && totalAvailable <= 5;
                var isFull = totalAvailable < totalQty;

                var stateClass = 'available';
                var statusText = 'Posti ok';

                if (isFull) {
                    stateClass = 'disabled';
                    statusText = 'Pieno';
                } else if (isLow) {
                    stateClass = 'low';
                    statusText = 'Pochi posti';
                }

                gridHtml += '<div class="dfn-slot-pill ' + stateClass + '" data-slot-id="' + slot.slot_id + '" data-time="' + slot.time + '">';
                gridHtml += '  <span class="dfn-slot-time">' + slot.time + '</span>';
                gridHtml += '  <span class="dfn-slot-availability">' + statusText + '</span>';
                gridHtml += '</div>';
            });
            gridHtml += '</div>';
            $slotsContainer.html(gridHtml);

            $slotsContainer.find('.dfn-slot-pill:not(.disabled)').on('click', function() {
                var $pill = $(this);
                $slotsContainer.find('.dfn-slot-pill').removeClass('selected');
                $pill.addClass('selected');

                var selectedId = $pill.data('slot-id');
                $hiddenSlotId.val(selectedId);
                validateStep1();
            });

            validateStep1();
        }

        // Genera i campi delle tessere FAI dinamici
        function generateFaiCardsFields(count) {
            $faiFieldsContainer.html('');
            if (count <= 0) {
                $faiFieldsSection.hide();
                return;
            }

            for (var i = 1; i <= count; i++) {
                var fieldHtml = '<div class="dfn-fai-card-row" style="padding-bottom:12px; border-bottom:1px dashed #cbd5e1; margin-bottom:8px;">' +
                                '  <h5 style="margin:0 0 6px 0; font-size:12px; color:#004b23;">Partecipante FAI #' + i + '</h5>' +
                                '  <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:6px;">' +
                                '    <input type="text" class="dfn-fai-card-nome" placeholder="Nome" required style="height:32px; border:1px solid #cbd5e1; border-radius:4px; padding:4px 8px; font-size:12px; box-sizing:border-box;">' +
                                '    <input type="text" class="dfn-fai-card-cognome" placeholder="Cognome" required style="height:32px; border:1px solid #cbd5e1; border-radius:4px; padding:4px 8px; font-size:12px; box-sizing:border-box;">' +
                                '  </div>' +
                                '  <input type="text" class="dfn-fai-card-number" placeholder="Numero Tessera FAI" required style="width:100%; height:32px; border:1px solid #cbd5e1; border-radius:4px; padding:4px 8px; font-size:12px; box-sizing:border-box;">' +
                                '</div>';
                $faiFieldsContainer.append(fieldHtml);
            }
            $faiFieldsSection.show();
        }

        // Passaggio a Step 2 (Continua)
        $btnNext.on('click', function(e) {
            e.preventDefault();
            
            var qtyFai = parseInt($widget.find('input[name="dfn_qty_fai"]').val()) || 0;
            generateFaiCardsFields(qtyFai);
            
            // Auto-compilazione dati utente loggato se i campi sono vuoti
            if (dfnVars.userLogged) {
                var $fName = $widget.find('#dfn_first_name');
                var $lName = $widget.find('#dfn_last_name');
                var $email = $widget.find('#dfn_email');
                var $phone = $widget.find('#dfn_phone');

                if ($fName.val() === '') $fName.val(dfnVars.userFirstName);
                if ($lName.val() === '') $lName.val(dfnVars.userLastName);
                if ($email.val() === '') $email.val(dfnVars.userEmail);
                if ($phone.val() === '') $phone.val(dfnVars.userPhone);
            }

            // Recupera e renderizza le chips delle tessere FAI se l'utente è loggato e ci sono biglietti FAI
            if (qtyFai > 0 && dfnVars.userLogged) {
                $faiChipsList.html('');
                $faiChipsSection.hide();

                $.ajax({
                    url: dfnVars.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'dfn_get_user_fai_cards',
                        nonce: dfnVars.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.cards && response.data.cards.length > 0) {
                            var cards = response.data.cards;
                            var chipsHtml = '';
                            
                            cards.forEach(function(card) {
                                var badgeHtml = card.scaduta ? ' <span class="dfn-chip-badge-expired">⚠️ Scaduta</span>' : '';
                                chipsHtml += '<button type="button" class="dfn-fai-chip" ' +
                                             'data-nome="' + card.nome + '" ' +
                                             'data-cognome="' + card.cognome + '" ' +
                                             'data-tessera="' + card.tessera + '">' +
                                             card.nome + ' ' + card.cognome + ' — ' + card.tessera + badgeHtml +
                                             '</button>';
                            });
                            
                            $faiChipsList.html(chipsHtml);
                            $faiChipsSection.show();
                        }
                    }
                });
            } else {
                $faiChipsSection.hide();
            }
            
            $step1.fadeOut(200, function() {
                $step2.fadeIn(200);
                $step2.find('#dfn_first_name').focus();
            });
        });

        // Handler per il click sulle chips delle tessere FAI
        $widget.on('click', '.dfn-fai-chip', function(e) {
            e.preventDefault();
            var $chip = $(this);
            var nome = $chip.data('nome');
            var cognome = $chip.data('cognome');
            var tessera = $chip.data('tessera');
            
            var filled = false;
            // Cerca la prima riga di campi FAI vuota o parzialmente vuota
            $faiFieldsContainer.find('.dfn-fai-card-row').each(function() {
                var $row = $(this);
                var $nomeInput = $row.find('.dfn-fai-card-nome');
                var $cognomeInput = $row.find('.dfn-fai-card-cognome');
                var $numberInput = $row.find('.dfn-fai-card-number');
                
                if ($nomeInput.val() === '' && $cognomeInput.val() === '' && $numberInput.val() === '') {
                    $nomeInput.val(nome);
                    $cognomeInput.val(cognome);
                    $numberInput.val(tessera);
                    filled = true;
                    return false; // Esci dal ciclo each
                }
            });
            
            // Fallback: se sono tutte già parzialmente compilate, sovrascrivi la prima
            if (!filled) {
                var $firstRow = $faiFieldsContainer.find('.dfn-fai-card-row').first();
                if ($firstRow.length > 0) {
                    $firstRow.find('.dfn-fai-card-nome').val(nome);
                    $firstRow.find('.dfn-fai-card-cognome').val(cognome);
                    $firstRow.find('.dfn-fai-card-number').val(tessera);
                    filled = true;
                }
            }

            if (filled) {
                // Aggiungi una classe visiva per indicare che la chip è stata usata
                $chip.addClass('dfn-fai-chip-used');
            }
        });

        // Ritorno a Step 1 (Indietro)
        $btnPrev.on('click', function(e) {
            e.preventDefault();
            $feedbackArea.html('');
            $step2.fadeOut(200, function() {
                $step1.fadeIn(200);
            });
        });

        // Reset per un altro evento
        $btnReset.on('click', function(e) {
            e.preventDefault();
            
            // Pulisci tutti i campi di input
            $widget.find('input[type="text"], input[type="email"], input[type="tel"], textarea').val('');
            $widget.find('input[name="quantity"]').val(1);
            $widget.find('input[name="dfn_qty_fai"]').val(0);
            $hiddenSlotId.val('');
            $feedbackArea.html('');
            
            // Ripristina la data di default e ricarica gli slot
            $dateInput.val(defaultDate);
            if (defaultDate !== '') {
                loadSlots(defaultDate);
            } else {
                $slotsContainer.html('');
            }
            
            // Re-abilita e ripristina lo stato del submit button reale di Step 2
            $realSubmitBtn.prop('disabled', false).html(originalSubmitHtml);
            $widget.find('.dfn-widget-btn-prev').prop('disabled', false);
            
            // Pulisci e nascondi i campi tessere FAI
            $faiFieldsContainer.html('');
            $faiFieldsSection.hide();
            
            $step3.fadeOut(200, function() {
                $step1.fadeIn(200);
                validateStep1();
            });
        });

        // Invio Finale della Prenotazione via AJAX
        $widget.find('.dfn-booking-form').on('submit', function(e) {
            e.preventDefault();
            submitBooking(false);
        });

        function submitBooking(confirmSplit) {
            $feedbackArea.html('');
            
            var $form = $widget.find('.dfn-booking-form');
            var $submit = $form.find('button[type="submit"]');
            
            // Raccogli tessere FAI
            var faiCards = [];
            $form.find('.dfn-fai-card-row').each(function() {
                var $row = $(this);
                faiCards.push({
                    nome: $row.find('.dfn-fai-card-nome').val(),
                    cognome: $row.find('.dfn-fai-card-cognome').val(),
                    tessera: $row.find('.dfn-fai-card-number').val()
                });
            });

            var postData = {
                action: 'dfn_create_direct_booking',
                nonce: dfnVars.nonce,
                event_id: eventId,
                product_id: parseInt($form.find('input[name="product_id"]').val()),
                qty_standard: parseInt($form.find('input[name="quantity"]').val()) || 0,
                qty_fai: parseInt($form.find('input[name="dfn_qty_fai"]').val()) || 0,
                date: $dateInput.val(),
                slot_id: parseInt($hiddenSlotId.val()) || 0,
                first_name: $form.find('#dfn_first_name').val(),
                last_name: $form.find('#dfn_last_name').val(),
                email: $form.find('#dfn_email').val(),
                phone: $form.find('#dfn_phone').val(),
                notes: $form.find('#dfn_notes').val(),
                fai_cards: faiCards,
                confirm_split: confirmSplit ? 1 : 0
            };

            // Disabilita UI
            $submit.prop('disabled', true).html('<span class="dashicons dashicons-update spin" style="animation: spin 1s linear infinite;"></span> Attendi...');
            $widget.find('.dfn-widget-btn-prev').prop('disabled', true);

            $.ajax({
                url: dfnVars.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: postData,
                success: function(response) {
                    if (response.success && response.data) {
                        var res = response.data;
                        
                        if (res.status === 'split_warning') {
                            $('.dfn-custom-modal-overlay').remove();

                            var modalHtml = 
                                '<div class="dfn-custom-modal-overlay">' +
                                '  <div class="dfn-custom-modal">' +
                                '    <div class="dfn-custom-modal-header">' +
                                '      <span class="dashicons dashicons-warning"></span>' +
                                '      <h4>Attenzione</h4>' +
                                '    </div>' +
                                '    <div class="dfn-custom-modal-body">' +
                                '      <p>' + res.message + '</p>' +
                                '    </div>' +
                                '    <div class="dfn-custom-modal-footer">' +
                                '      <button type="button" class="dfn-modal-btn dfn-modal-btn-cancel">Annulla</button>' +
                                '      <button type="button" class="dfn-modal-btn dfn-modal-btn-confirm">Continua</button>' +
                                '    </div>' +
                                '  </div>' +
                                '</div>';

                            var $modal = $(modalHtml).appendTo('body');
                            
                            // Trigger reflow to enable transition
                            $modal.each(function() { this.offsetHeight; }).addClass('active');

                            $modal.find('.dfn-modal-btn-confirm').on('click', function() {
                                $modal.removeClass('active');
                                setTimeout(function() { $modal.remove(); }, 200);
                                submitBooking(true);
                            });

                            $modal.find('.dfn-modal-btn-cancel').on('click', function() {
                                $modal.removeClass('active');
                                setTimeout(function() { $modal.remove(); }, 200);
                                $submit.prop('disabled', false).html(originalSubmitHtml);
                                $widget.find('.dfn-widget-btn-prev').prop('disabled', false);
                            });

                            return;
                        }

                        // Icona e Titolo di Successo
                        if (res.status === 'confirmed') {
                            $step3.find('.dfn-success-icon').html('🎉').css('color', '#166534');
                            $step3.find('.dfn-success-title').html('Prenotazione Confermata!').css('color', '#004b23');
                        } else {
                            $step3.find('.dfn-success-icon').html('⏳').css('color', '#c69c3a');
                            $step3.find('.dfn-success-title').html('In Lista d\'Attesa').css('color', '#854d0e');
                        }

                        // Messaggio testuale
                        var msg = '<p>Grazie <strong>' + postData.first_name + ' ' + postData.last_name + '</strong>!</p>';
                        if (res.status === 'confirmed') {
                            msg += '<p>La tua richiesta è stata registrata. Riceverai a breve un\'email di conferma all\'indirizzo <strong>' + postData.email + '</strong> con il codice QR da presentare all\'ingresso dell\'evento.</p>';
                        } else {
                            msg += '<p>Al momento i posti per questo turno sono esauriti. Sei stato inserito in <strong>Lista di Attesa</strong>. Se si libereranno dei posti, riceverai una notifica email immediata per completare la tua prenotazione.</p>';
                        }
                        $step3.find('.dfn-success-message').html(msg);

                        // Riepilogo Prenotazione
                        var slotTimeStr = isTimeSlots ? $widget.find('.dfn-slot-pill.selected .dfn-slot-time').text() : '';
                        var dateParts = postData.date.split('-');
                        var dateStrFormatted = dateParts[2] + '/' + dateParts[1] + '/' + dateParts[0];

                        var summaryHtml = '<div style="font-weight:700; color:#004b23; margin-bottom:8px; border-bottom:1px solid #cbd5e1; padding-bottom:6px; font-size:14px;">Dettaglio Prenotazione</div>' +
                                          '<table>' +
                                          '  <tr><td style="font-weight:700; width:120px; color:#475569;">Giorno:</td><td>' + dateStrFormatted + '</td></tr>';
                        if (slotTimeStr !== '') {
                            summaryHtml += '  <tr><td style="font-weight:700; color:#475569;">Turno Orario:</td><td>' + slotTimeStr + '</td></tr>';
                        }
                        summaryHtml += '  <tr><td style="font-weight:700; color:#475569;">Biglietti:</td><td>' + postData.qty_standard + ' Standard + ' + postData.qty_fai + ' Soci FAI</td></tr>' +
                                          '  <tr><td style="font-weight:700; color:#475569;">Pagamento:</td><td>Saldo all\'ingresso (In Loco)</td></tr>';
                        
                        if (res.status === 'confirmed') {
                            if (res.total_confirmed) {
                                summaryHtml += '  <tr><td style="font-weight:700; color:#004b23;">Contributo:</td><td style="font-weight:800; color:#004b23; font-size:15px;">€' + res.amount_due.toFixed(2) + ' <span style="font-size:11px; font-weight:normal; color:#166534;">(Tessere FAI verificate)</span></td></tr>';
                            } else {
                                summaryHtml += '  <tr><td style="font-weight:700; color:#854d0e;">Contributo:</td><td style="font-weight:800; color:#854d0e; font-size:15px;">€' + res.amount_due.toFixed(2) + '*</td></tr>';
                                summaryHtml += '</table>';
                                summaryHtml += '<div style="margin-top:12px; background:#fffdf5; border:1px solid #ffeeba; border-radius:6px; padding:10px; color:#856404; font-size:11px; line-height:1.4;">' +
                                               '  <strong>* Tariffa soggetta a verifica:</strong> Alcune tessere FAI inserite non sono ancora state verificate nei nostri sistemi. ' +
                                               '  La prenotazione è attiva, ma se le tessere non risulteranno valide al controllo all\'ingresso ti verrà applicato il contributo Standard di <strong>€' + res.amount_standard.toFixed(2) + '</strong>.' +
                                               '</div>';
                            }
                        } else {
                            summaryHtml += '</table>';
                        }

                        $step3.find('.dfn-success-summary').html(summaryHtml);

                        // Passa allo Step 3
                        $step2.fadeOut(200, function() {
                            $step3.fadeIn(200);
                        });

                    } else {
                        // Errore logico
                        $feedbackArea.html('<div style="color:#b91c1c; font-size:13px; font-weight:700; background:#fee2e2; border:1px solid #fecaca; border-radius:6px; padding:10px; margin-top:12px;">❌ Errore: ' + (response.data ? response.data.message : 'Impossibile completare la richiesta.') + '</div>');
                        $submit.prop('disabled', false).html('<span class="dashicons dashicons-calendar-alt"></span> Riprova');
                        $widget.find('.dfn-widget-btn-prev').prop('disabled', false);
                    }
                },
                error: function() {
                    $feedbackArea.html('<div style="color:#b91c1c; font-size:13px; font-weight:700; background:#fee2e2; border:1px solid #fecaca; border-radius:6px; padding:10px; margin-top:12px;">❌ Errore di connessione al server. Riprova.</div>');
                    $submit.prop('disabled', false).html('<span class="dashicons dashicons-calendar-alt"></span> Riprova');
                    $widget.find('.dfn-widget-btn-prev').prop('disabled', false);
                }
            });
        }

        // Gestione Slider Galleria Immagini
        var $slides = $widget.find('.dfn-slider-slide');
        var $thumbs = $widget.find('.dfn-gallery-thumb-wrapper');
        var currentIndex = 0;

        function goToSlide(index) {
            if ($slides.length <= 1) return;
            if (index < 0) index = $slides.length - 1;
            if (index >= $slides.length) index = 0;

            $slides.removeClass('active');
            $slides.eq(index).addClass('active');

            $thumbs.css('border-color', '#e2e8f0');
            $thumbs.eq(index).css('border-color', '#004b23');

            currentIndex = index;
        }

        $thumbs.on('click', function() {
            var index = parseInt($(this).data('index'));
            goToSlide(index);
        });

        $widget.find('.next-arrow').on('click', function(e) {
            e.preventDefault();
            goToSlide(currentIndex + 1);
        });

        $widget.find('.prev-arrow').on('click', function(e) {
            e.preventDefault();
            goToSlide(currentIndex - 1);
        });

        if ($dateInput.val() !== '') {
            loadSlots($dateInput.val());
        }

        validateStep1();
    });
});


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
        var $submitBtn = $widget.find('.dfn-widget-submit');
        var $feedbackArea = $widget.find('.dfn-widget-feedback');

        // Disabilita submit fino a quando la selezione non è completa
        function validateForm() {
            var qtyStandard = parseInt($widget.find('input[name="quantity"]').val()) || 0;
            var qtyFai = parseInt($widget.find('input[name="dfn_qty_fai"]').val()) || 0;
            var totalQty = qtyStandard + qtyFai;

            var dateSelected = $dateInput.val() !== '';
            var slotSelected = !isTimeSlots || allocationMode === 'automatic' || $hiddenSlotId.val() !== '';

            if (totalQty > 0 && dateSelected && slotSelected) {
                $submitBtn.prop('disabled', false);
            } else {
                $submitBtn.prop('disabled', true);
            }
        }

        // Ascolta modifiche alle quantità
        $widget.find('input[name="quantity"], input[name="dfn_qty_fai"]').on('input change', function() {
            var qtyStandard = parseInt($widget.find('input[name="quantity"]').val()) || 0;
            var qtyFai = parseInt($widget.find('input[name="dfn_qty_fai"]').val()) || 0;
            if (qtyStandard < 0) $widget.find('input[name="quantity"]').val(0);
            if (qtyFai < 0) $widget.find('input[name="dfn_qty_fai"]').val(0);

            // Se cambia la quantità e siamo in modalità slot, ricarica gli slot per verificare la capienza reale
            if ($dateInput.val() !== '') {
                loadSlots($dateInput.val());
            }
            validateForm();
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
            validateForm();
        });

        // Caricamento AJAX degli slot
        function loadSlots(dateStr) {
            if (!isTimeSlots) {
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
            
            // In modalità automatica nascondiamo o pre-selezioniamo in background
            if (allocationMode === 'automatic') {
                // Troviamo il primo slot disponibile per il gruppo intero
                var autoSlot = null;
                $.each(slots, function(idx, slot) {
                    var available = slot.capacity + slot.bonus - slot.booked;
                    if (available >= totalQty) {
                        autoSlot = slot;
                        return false; // break loop
                    }
                });

                if (autoSlot) {
                    $hiddenSlotId.val(autoSlot.slot_id);
                    $slotsContainer.html('<div class="dfn-feedback-toast">🤖 <strong>Assegnazione Automatica:</strong> Ti verrà assegnato il miglior slot disponibile (' + autoSlot.time + ') al momento dell\'invio.</div>');
                } else {
                    $slotsContainer.html('<div class="dfn-feedback-toast" style="background:#fee2e2; border-color:#fecaca; color:#991b1b;">❌ <strong>Nessun turno disponibile:</strong> Non ci sono slot con posti sufficienti per il tuo gruppo. Sarai inserito in Lista di Attesa.</div>');
                }
                validateForm();
                return;
            }

            // Modalità Self-Selection (👆)
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

            // Gestore click sulle pillole
            $slotsContainer.find('.dfn-slot-pill:not(.disabled)').on('click', function() {
                var $pill = $(this);
                $slotsContainer.find('.dfn-slot-pill').removeClass('selected');
                $pill.addClass('selected');

                var selectedId = $pill.data('slot-id');
                $hiddenSlotId.val(selectedId);
                validateForm();
            });

            validateForm();
        }

        validateForm();
    });
});

/**
 * DFN My Account Booking Modals
 * 
 * Gestisce i popup di modifica e annullamento prenotazione dall'area riservata.
 * Usa event delegation per massima affidabilità con qualsiasi rendering WooCommerce.
 */
(function($) {
    'use strict';

    // =========================================================================
    // MODAL HELPERS
    // =========================================================================

    function openModal(id) {
        $('#' + id).addClass('active');
        $('body').css('overflow', 'hidden');
    }

    function closeModal(id) {
        $('#' + id).removeClass('active');
        $('body').css('overflow', '');
    }

    // Chiudi modal cliccando fuori dal contenuto
    $(document).on('click', '.dfn-myaccount-modal', function(e) {
        if ($(e.target).hasClass('dfn-myaccount-modal')) {
            closeModal($(this).attr('id'));
        }
    });

    // Chiudi con tasto ESC
    $(document).on('keyup', function(e) {
        if (e.key === 'Escape') {
            closeModal('dfn-modal-visitor-modify');
            closeModal('dfn-modal-visitor-cancel');
        }
    });

    // Espone le funzioni di chiusura ai pulsanti inline
    window.closeVisitorModifyModal = function() { closeModal('dfn-modal-visitor-modify'); };
    window.closeVisitorCancelModal = function() { closeModal('dfn-modal-visitor-cancel'); };

    // =========================================================================
    // STEPPER (+/-) HELPERS
    // =========================================================================

    window.decrementQty = function(id) {
        var $input = $('#' + id);
        var val = parseInt($input.val()) || 0;
        if (val > 0) {
            $input.val(val - 1);
        }
    };

    window.incrementQty = function(id, max) {
        var $input = $('#' + id);
        var val = parseInt($input.val()) || 0;
        if (val < max) {
            $input.val(val + 1);
        }
    };

    // =========================================================================
    // MODIFICA PARTECIPANTI — Popup
    // =========================================================================

    function openModifyModal(orderId, token) {
        var $loading = $('#dfn-visitor-modify-loading');
        var $container = $('#dfn-visitor-modify-form-container');

        $loading.show().html('Caricamento in corso...');
        $container.hide().html('');

        openModal('dfn-modal-visitor-modify');

        $.ajax({
            url: dfnMyaccountModals.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dfn_visitor_get_modify_details',
                order_id: orderId,
                token: token
            },
            success: function(response) {
                $loading.hide();
                if (response.success) {
                    $container.html(response.data.html).show();

                    // Azione submit del form caricato dinamicamente
                    $('#dfn-visitor-modify-modal-form').off('submit').on('submit', function(e) {
                        e.preventDefault();
                        submitModifyForm($(this));
                    });
                } else {
                    $loading.html('<span style="color:#dc2626;">' + (response.data || 'Errore nel caricamento.') + '</span>').show();
                }
            },
            error: function() {
                $loading.html('<span style="color:#dc2626;">Errore di connessione.</span>').show();
            }
        });
    }

    function submitModifyForm($form) {
        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.text();
        var $error = $('#dfn-visitor-modify-modal-error');

        $btn.prop('disabled', true).text('Salvataggio...');
        $error.hide().text('');

        $.ajax({
            url: dfnMyaccountModals.ajaxUrl,
            type: 'POST',
            data: $form.serialize(),
            success: function(res) {
                if (res.success) {
                    closeModal('dfn-modal-visitor-modify');
                    // Mostra messaggio successo e ricarica
                    showSuccessNotice('Prenotazione modificata con successo! La pagina verrà aggiornata...');
                    setTimeout(function() { window.location.reload(); }, 1800);
                } else {
                    $btn.prop('disabled', false).text(origText);
                    $error.text(res.data || 'Errore sconosciuto.').show();
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(origText);
                $error.text('Errore di connessione. Riprova.').show();
            }
        });
    }

    // =========================================================================
    // ANNULLAMENTO PRENOTAZIONE — Popup
    // =========================================================================

    function openCancelModal(orderId, token, eventTitle, bookingDate) {
        var cancelText = 'Sei sicuro di voler annullare la tua prenotazione per l\'evento <strong>' + 
            eventTitle + '</strong> in data <strong>' + bookingDate + '</strong>?' + 
            '<br><br><span style="font-size:13px; color:#64748b;">Questa operazione è irreversibile e i posti verranno riaperti al pubblico.</span>';

        $('#dfn-visitor-cancel-text').html(cancelText);

        openModal('dfn-modal-visitor-cancel');

        $('#dfn-btn-confirm-cancel').off('click').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('Annullamento...');

            $.ajax({
                url: dfnMyaccountModals.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dfn_visitor_submit_cancel',
                    order_id: orderId,
                    token: token
                },
                success: function(res) {
                    if (res.success) {
                        closeModal('dfn-modal-visitor-cancel');
                        showSuccessNotice('Prenotazione annullata. La pagina verrà aggiornata...');
                        setTimeout(function() { window.location.reload(); }, 1800);
                    } else {
                        $btn.prop('disabled', false).text('Sì, annulla');
                        alert('Errore: ' + (res.data || 'Errore sconosciuto.'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Sì, annulla');
                    alert('Errore di connessione.');
                }
            });
        });
    }

    // =========================================================================
    // NOTIFICA DI SUCCESSO INLINE
    // =========================================================================

    function showSuccessNotice(message) {
        var $notice = $('<div>', {
            'class': 'dfn-success-notice',
            html: '✅ ' + message,
            css: {
                position: 'fixed', bottom: '24px', left: '50%',
                transform: 'translateX(-50%)',
                background: '#004b23', color: '#fff',
                padding: '14px 28px', borderRadius: '12px',
                fontSize: '14px', fontWeight: '600',
                boxShadow: '0 8px 24px rgba(0,0,0,0.15)',
                zIndex: 99999, opacity: 0,
                transition: 'opacity 0.3s ease'
            }
        });
        $('body').append($notice);
        setTimeout(function() { $notice.css('opacity', 1); }, 50);
        setTimeout(function() { $notice.css('opacity', 0); setTimeout(function() { $notice.remove(); }, 300); }, 2000);
    }

    // =========================================================================
    // EVENT DELEGATION — intercetta i click sull'intero documento
    // Garantisce il funzionamento indipendentemente dal timing del render
    // =========================================================================

    $(document).on('click', '.dfn-action-modify', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var orderId = $(this).data('order-id');
        var token   = $(this).data('token');
        if (orderId && token) {
            openModifyModal(orderId, token);
        }
    });

    $(document).on('click', '.dfn-btn-cancel-booking', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var orderId     = $(this).data('order-id');
        var token       = $(this).data('token');
        var eventTitle  = $(this).data('event-title') || 'Evento';
        var bookingDate = $(this).data('booking-date') || '';
        if (orderId && token) {
            openCancelModal(orderId, token, eventTitle, bookingDate);
        }
    });

})(jQuery);

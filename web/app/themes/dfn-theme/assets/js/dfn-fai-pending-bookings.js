/**
 * DFN Booking System 2.0 — Admin: Verifica Prenotazioni FAI
 *
 * Controller JS per la pagina admin di verifica prenotazioni FAI
 * in stato pending_approval. Gestisce approvazione e rifiuto via AJAX.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

(function ($) {
    'use strict';

    let currentBookingId = null;

    // =========================================================================
    // CONVALIDA / RIFIUTO SINGOLA TESSERA FAI
    // =========================================================================

    $(document).on('click', '.dfn-btn-validate-card', function () {
        const $btn       = $(this);
        const bookingId  = $btn.data('booking-id');
        const cardNumber = $btn.data('card-number');

        $btn.prop('disabled', true);

        $.ajax({
            url:    dfnPendingVars.ajaxurl,
            method: 'POST',
            data: {
                action:      'dfn_validate_single_fai_card',
                nonce:       dfnPendingVars.nonce,
                booking_id:  bookingId,
                card_number: cardNumber,
            },
            success: function (response) {
                if (response.success) {
                    const $item = $btn.closest('.dfn-card-action-item');
                    $item.replaceWith(
                        '<div style="font-size: 11px; margin-bottom: 4px; padding: 3px 6px; background: #dcfce7; border: 1px solid #bbf7d0; color: #166534; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">' +
                        '<span class="dashicons dashicons-yes" style="font-size: 14px; width: 14px; height: 14px;"></span> ' +
                        '<strong>' + cardNumber + '</strong> <span style="font-size: 10px; font-weight: bold; margin-left: 4px;">VERIFICATA</span></div><br>'
                    );
                    dfnShowNotice('success', response.data.message);
                } else {
                    dfnShowNotice('error', response.data.message || dfnPendingVars.generic_error);
                    $btn.prop('disabled', false);
                }
            },
            error: function () {
                dfnShowNotice('error', dfnPendingVars.generic_error);
                $btn.prop('disabled', false);
            },
        });
    });

    $(document).on('click', '.dfn-btn-reject-card', function () {
        const $btn       = $(this);
        const bookingId  = $btn.data('booking-id');
        const cardNumber = $btn.data('card-number');

        if (! confirm('Rifiutando la tessera FAI n° ' + cardNumber + ', il relativo posto verrà convertito a tariffa Intera e il totale ricalcolato (+5€). Confermi?')) {
            return;
        }

        $btn.prop('disabled', true);

        $.ajax({
            url:    dfnPendingVars.ajaxurl,
            method: 'POST',
            data: {
                action:      'dfn_reject_single_fai_card',
                nonce:       dfnPendingVars.nonce,
                booking_id:  bookingId,
                card_number: cardNumber,
            },
            success: function (response) {
                if (response.success) {
                    const $item = $btn.closest('.dfn-card-action-item');
                    const $row  = $item.closest('.dfn-pending-row');

                    $item.replaceWith(
                        '<div style="font-size: 11px; margin-bottom: 4px; padding: 3px 6px; background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; border-radius: 4px; display: inline-flex; align-items: center; gap: 4px;">' +
                        '<span class="dashicons dashicons-no-alt" style="font-size: 14px; width: 14px; height: 14px;"></span> ' +
                        '<strong>' + cardNumber + '</strong> <span style="font-size: 10px; font-weight: bold; margin-left: 4px;">RIFIUTATA (+5€)</span></div><br>'
                    );

                    // Aggiorna totale prezzo e conteggi nella riga
                    if (response.data.new_total_html) {
                        $row.find('td:nth-child(4) div:last-child').html(response.data.new_total_html);
                    }
                    if (response.data.persons_standard !== undefined && response.data.persons_fai !== undefined) {
                        $row.find('.dfn-small-sub').first().text(response.data.persons_standard + ' std + ' + response.data.persons_fai + ' FAI');
                    }

                    dfnShowNotice('success', response.data.message);
                } else {
                    dfnShowNotice('error', response.data.message || dfnPendingVars.generic_error);
                    $btn.prop('disabled', false);
                }
            },
            error: function () {
                dfnShowNotice('error', dfnPendingVars.generic_error);
                $btn.prop('disabled', false);
            },
        });
    });

    $(document).on('click', '.dfn-btn-approve-booking', function () {
        const $btn        = $(this);
        const bookingId   = $btn.data('booking-id');
        const customerName = $btn.data('customer');

        if (! confirm(dfnPendingVars.confirm_approve.replace('{nome}', customerName))) {
            return;
        }

        $btn.prop('disabled', true).text(dfnPendingVars.processing);

        $.ajax({
            url:    dfnPendingVars.ajaxurl,
            method: 'POST',
            data: {
                action:     'dfn_approve_pending_booking',
                nonce:      dfnPendingVars.nonce,
                booking_id: bookingId,
            },
            success: function (response) {
                if (response.success) {
                    const $row = $btn.closest('.dfn-pending-row');
                    $row.find('td:last-child').html(
                        '<span style="color: #16a34a; font-weight: 600; font-size: 13px;">' + dfnPendingVars.approved_label + '</span>'
                    );
                    dfnShowNotice('success', response.data.message);
                    dfnUpdatePendingCount(-1);
                    setTimeout(function () {
                        $row.fadeOut(400, function () { $(this).remove(); dfnCheckEmpty(); });
                    }, 2500);
                } else {
                    dfnShowNotice('error', response.data.message || dfnPendingVars.generic_error);
                    $btn.prop('disabled', false).text(dfnPendingVars.approve_label);
                }
            },
            error: function () {
                dfnShowNotice('error', dfnPendingVars.generic_error);
                $btn.prop('disabled', false).text(dfnPendingVars.approve_label);
            },
        });
    });

    // =========================================================================
    // APERTURA MODALE RIFIUTO
    // =========================================================================

    $(document).on('click', '.dfn-btn-reject-booking', function () {
        const bookingId    = $(this).data('booking-id');
        const customerName = $(this).data('customer');
        currentBookingId = bookingId;

        $('#dfn-reject-customer-info').text(dfnPendingVars.reject_for.replace('{nome}', customerName));
        $('#dfn-reject-motivo').val('').css('border-color', '');
        $('#dfn-reject-modal').css('display', 'flex');
    });

    // Chiusura modale
    $('#dfn-modal-close, #dfn-modal-cancel').on('click', function () {
        $('#dfn-reject-modal').hide();
        currentBookingId = null;
    });

    // Chiudi su click fuori dalla modale
    $('#dfn-reject-modal').on('click', function (e) {
        if ($(e.target).is('#dfn-reject-modal')) {
            $('#dfn-reject-modal').hide();
            currentBookingId = null;
        }
    });

    // =========================================================================
    // CONFERMA RIFIUTO
    // =========================================================================

    $('#dfn-modal-confirm-reject').on('click', function () {
        const motivo = $('#dfn-reject-motivo').val().trim();

        if (! motivo) {
            $('#dfn-reject-motivo').css('border-color', '#dc2626').focus();
            return;
        }
        $('#dfn-reject-motivo').css('border-color', '');

        const $btn = $(this);
        $btn.prop('disabled', true).text(dfnPendingVars.processing);

        $.ajax({
            url:    dfnPendingVars.ajaxurl,
            method: 'POST',
            data: {
                action:     'dfn_reject_pending_booking',
                nonce:      dfnPendingVars.nonce,
                booking_id: currentBookingId,
                motivo:     motivo,
            },
            success: function (response) {
                $('#dfn-reject-modal').hide();
                if (response.success) {
                    const $row = $('[data-booking-id="' + currentBookingId + '"]');
                    $row.find('td:last-child').html(
                        '<span style="color: #dc2626; font-weight: 600; font-size: 13px;">' + dfnPendingVars.rejected_label + '</span>'
                    );
                    dfnShowNotice('success', response.data.message);
                    dfnUpdatePendingCount(-1);
                    setTimeout(function () {
                        $row.fadeOut(400, function () { $(this).remove(); dfnCheckEmpty(); });
                    }, 2500);
                } else {
                    dfnShowNotice('error', response.data.message || dfnPendingVars.generic_error);
                }
                $btn.prop('disabled', false).text(dfnPendingVars.reject_confirm_label);
                currentBookingId = null;
            },
            error: function () {
                $('#dfn-reject-modal').hide();
                dfnShowNotice('error', dfnPendingVars.generic_error);
                $btn.prop('disabled', false).text(dfnPendingVars.reject_confirm_label);
                currentBookingId = null;
            },
        });
    });

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Mostra un notice admin temporaneo nella parte superiore della pagina.
     */
    function dfnShowNotice(type, message) {
        const $notice = $('<div class="notice notice-' + type + ' is-dismissible dfn-temp-notice" style="margin: 0 0 15px;"><p>' + message + '</p></div>');
        $('.dfn-admin-header').after($notice);
        setTimeout(function () { $notice.fadeOut(400, function () { $(this).remove(); }); }, 5000);
    }

    /**
     * Aggiorna il badge count del menu e dell'header.
     */
    function dfnUpdatePendingCount(delta) {
        const $badge = $('.dfn-count-badge:first');
        if ($badge.length) {
            const text   = $badge.text();
            const current = parseInt(text) || 0;
            const newCount = Math.max(0, current + delta);
            if (newCount === 0) {
                $badge.fadeOut(300);
            } else {
                $badge.text(newCount + ' ' + dfnPendingVars.da_verificare);
            }
        }

        const $menuBadge = $('#dfn-pending-menu-badge');
        if ($menuBadge.length) {
            const current = parseInt($menuBadge.text()) || 0;
            const newCount = Math.max(0, current + delta);
            if (newCount === 0) {
                $menuBadge.remove();
            } else {
                $menuBadge.text(newCount);
            }
        }
    }

    /**
     * Controlla se la lista è vuota e mostra il messaggio "nessuna prenotazione".
     */
    function dfnCheckEmpty() {
        // Rimuovi card evento se tutte le righe sono rimosse
        $('.dfn-main-card').each(function () {
            if ($(this).find('.dfn-pending-row').length === 0) {
                $(this).fadeOut(300, function () { $(this).remove(); });
            }
        });

        setTimeout(function () {
            if ($('.dfn-pending-row').length === 0 && ! $('.dfn-empty-state').length) {
                $('.dfn-pending-intro').after(
                    '<div class="dfn-card">' +
                    '<div style="padding: 60px 20px; text-align: center;">' +
                    '<p style="font-size: 22px; color: #004b23; font-weight: 700; margin: 0 0 8px;">' + dfnPendingVars.all_done + '</p>' +
                    '<p style="color: #64748b; font-size: 14px; margin: 0;">' + dfnPendingVars.all_done_sub + '</p>' +
                    '</div></div>'
                );
            }
        }, 600);
    }

})(jQuery);

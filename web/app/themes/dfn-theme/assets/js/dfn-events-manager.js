/**
 * DFN Booking System 2.0 — Events Manager & Editor JavaScript
 *
 * Gestisce l'inizializzazione dei widget dell'interfaccia (es. Select2),
 * i controlli condizionali dei campi del form in tempo reale (Fasce Orarie vs Flusso Libero),
 * e le richieste di conferma interattive.
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // 1. Inizializzazione di Select2 sui selettori dei prodotti WooCommerce
        if ($.fn.select2) {
            $('.dfn-select2').select2({
                placeholder: 'Cerca e seleziona un elemento...',
                allowClear: true,
                language: 'it'
            });
        }

        // 2. Logica condizionale dei campi del form in base alla modalità di accesso
        function toggleAccessTypeSections() {
            var accessType = $('#access_type').val();
            
            if (accessType === 'time_slots') {
                $('#dfn-slot-settings-section').slideDown(250);
                $('#dfn-freeflow-settings-section').slideUp(200);

                // Rendi i campi slot obbligatori
                $('#slot_duration, #slot_capacity, #first_slot_time').prop('required', true);
                $('#total_capacity').prop('required', false);
            } else {
                $('#dfn-slot-settings-section').slideUp(200);
                $('#dfn-freeflow-settings-section').slideDown(250);

                // Rendi i campi free flow obbligatori
                $('#slot_duration, #slot_capacity, #first_slot_time').prop('required', false);
                $('#total_capacity').prop('required', true);
            }
        }

        // Esegui al caricamento iniziale
        if ($('#access_type').length > 0) {
            toggleAccessTypeSections();
        }

        // Ascolta il cambiamento del selettore
        $(document).on('change', '#access_type', function() {
            toggleAccessTypeSections();
        });

        // 3. Finestre di conferma interattive per azioni sensibili
        // Richiesta di conferma eliminazione evento
        $(document).on('click', '.dfn-btn-delete', function(e) {
            var confirmMsg = (typeof dfnAdminVars !== 'undefined' && dfnAdminVars.confirm_delete) 
                ? dfnAdminVars.confirm_delete 
                : 'Sei sicuro di voler procedere con l\'eliminazione?';
                
            if (!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
        });

        // Richiesta di conferma rigenerazione o generazione manuale degli slot orari
        $(document).on('click', '.dfn-btn-icon', function(e) {
            var confirmMsg = (typeof dfnAdminVars !== 'undefined' && dfnAdminVars.confirm_slots) 
                ? dfnAdminVars.confirm_slots 
                : 'Questo rigenererà tutti i turni orari per questo evento. Continuare?';
                
            if (!confirm(confirmMsg)) {
                e.preventDefault();
                return false;
            }
        });
    });

})(jQuery);

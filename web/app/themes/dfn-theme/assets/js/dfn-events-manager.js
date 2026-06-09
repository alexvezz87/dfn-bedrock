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
                language: 'it',
                width: '100%'
            });
        }

        // 1b. Logica condizionale per la creazione automatica del prodotto WooCommerce
        function toggleAutoProductTitleField() {
            var selectedProduct = $('#product_id').val();
            if (selectedProduct === 'new') {
                $('#dfn-auto-product-title-group').slideDown(250);
                $('#event_title').prop('required', true);
            } else {
                $('#dfn-auto-product-title-group').slideUp(200);
                $('#event_title').prop('required', false);
            }
        }

        if ($('#product_id').length > 0) {
            toggleAutoProductTitleField();
        }

        $(document).on('change', '#product_id', function() {
            toggleAutoProductTitleField();
        });

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

        // 4. Gestione upload immagine tramite Media Library nativa di WordPress
        var file_frame;
        $(document).on('click', '#dfn-upload-image-btn', function(e) {
            e.preventDefault();

            // Verifica che wp e wp.media esistano per prevenire crash JS
            if (typeof wp === 'undefined' || !wp.media) {
                console.error('WordPress Media Library non caricata correttamente.');
                alert('La libreria dei media di WordPress non è al momento disponibile.');
                return;
            }

            // Se il frame esiste già, riapriamolo
            if (file_frame) {
                file_frame.open();
                return;
            }

            // Crea il frame di selezione media in modo sicuro senza appoggiarsi a proprietà non definite di wp.media
            file_frame = wp.media({
                title: 'Seleziona Immagine in Evidenza',
                button: {
                    text: 'Usa questa immagine'
                },
                multiple: false
            });

            // Quando viene selezionata un'immagine, recupera l'ID e l'URL
            file_frame.on('select', function() {
                var attachment = file_frame.state().get('selection').first().toJSON();
                $('#dfn_event_image_id').val(attachment.id);
                $('#dfn-event-image-img').attr('src', attachment.url).show();
                $('#dfn-event-image-placeholder').hide();
                $('#dfn-remove-image-btn').show();
            });

            // Apri il frame
            file_frame.open();
        });

        // Rimozione immagine in evidenza
        $(document).on('click', '#dfn-remove-image-btn', function(e) {
            e.preventDefault();
            $('#dfn_event_image_id').val(0);
            $('#dfn-event-image-img').attr('src', '').hide();
            $('#dfn-event-image-placeholder').show();
            $(this).hide();
        });

        // 4b. Gestione upload galleria immagini
        var gallery_frame;
        $(document).on('click', '#dfn-upload-gallery-btn', function(e) {
            e.preventDefault();

            if (typeof wp === 'undefined' || !wp.media) {
                alert('La libreria dei media di WordPress non è al momento disponibile.');
                return;
            }

            if (gallery_frame) {
                gallery_frame.open();
                return;
            }

            gallery_frame = wp.media({
                title: 'Aggiungi Immagini alla Galleria',
                button: {
                    text: 'Aggiungi alla galleria'
                },
                multiple: true
            });

            gallery_frame.on('select', function() {
                var selection = gallery_frame.state().get('selection');
                var currentIds = $('#dfn_event_gallery_ids').val().split(',').filter(Boolean);
                
                selection.each(function(attachment) {
                    var attJson = attachment.toJSON();
                    if (currentIds.indexOf(attJson.id.toString()) === -1) {
                        currentIds.push(attJson.id.toString());
                        
                        // Append thumbnail in HTML preview
                        $('#dfn-event-gallery-placeholder').hide();
                        var html = '<div class="dfn-gallery-image-wrapper" data-id="' + attJson.id + '" style="position: relative; width: 60px; height: 60px; border-radius: 4px; overflow: hidden; border: 1px solid #cbd5e1;">' +
                                   '  <img src="' + (attJson.sizes && attJson.sizes.thumbnail ? attJson.sizes.thumbnail.url : attJson.url) + '" style="width: 100%; height: 100%; object-fit: cover;">' +
                                   '  <span class="dfn-delete-gallery-img" style="position: absolute; top: 0; right: 0; background: rgba(239, 68, 68, 0.8); color: white; border-radius: 0 0 0 4px; width: 16px; height: 16px; line-height: 16px; text-align: center; cursor: pointer; font-size: 10px; font-weight: bold;">×</span>' +
                                   '</div>';
                        $('#dfn-event-gallery-container').append(html);
                    }
                });

                $('#dfn_event_gallery_ids').val(currentIds.join(','));
            });

            gallery_frame.open();
        });

        // Rimozione singola immagine galleria
        $(document).on('click', '.dfn-delete-gallery-img', function(e) {
            e.preventDefault();
            var $wrapper = $(this).closest('.dfn-gallery-image-wrapper');
            var imgId = $wrapper.data('id').toString();
            var currentIds = $('#dfn_event_gallery_ids').val().split(',').filter(Boolean);
            
            var index = currentIds.indexOf(imgId);
            if (index > -1) {
                currentIds.splice(index, 1);
            }
            
            $wrapper.remove();
            $('#dfn_event_gallery_ids').val(currentIds.join(','));
            
            if (currentIds.length === 0) {
                $('#dfn-event-gallery-placeholder').show();
            }
        });
    });

})(jQuery);

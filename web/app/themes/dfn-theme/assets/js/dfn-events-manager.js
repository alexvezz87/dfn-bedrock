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

        // 1b. Sincronizzazione automatica del titolo con la selezione del prodotto WooCommerce
        $(document).on('change', '#product_id', function() {
            var selectedProduct = $(this).val();
            var $eventTitle = $('#event_title');
            if (!$eventTitle.length) return;

            if (selectedProduct && selectedProduct !== 'new') {
                var selectedOption = $(this).find('option:selected');
                var productTitle = selectedOption.data('product-title');
                if (!productTitle) {
                    productTitle = selectedOption.text().replace(/\s*\(ID:\s*\d+\)\s*$/, '').trim();
                }
                if (productTitle && (!$eventTitle.val() || $eventTitle.data('auto-synced'))) {
                    $eventTitle.val(productTitle).data('auto-synced', true);
                }
            } else if (selectedProduct === 'new') {
                if ($eventTitle.data('auto-synced')) {
                    $eventTitle.val('').data('auto-synced', false);
                }
                $eventTitle.focus();
            }
        });

        $(document).on('input', '#event_title', function() {
            $(this).data('auto-synced', false);
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
        $(document).on('click', '.dfn-btn-reset, .dfn-btn-icon', function(e) {
            var confirmMsg = (typeof dfnAdminVars !== 'undefined' && dfnAdminVars.confirm_slots) 
                ? dfnAdminVars.confirm_slots 
                : 'ATTENZIONE CRITICA: Il Reset degli slot eliminerà TUTTI i turni orari e TUTTE LE PRENOTAZIONI già inserite per questo evento! Questa operazione non è reversibile. Sei davvero sicuro di voler procedere?';
                
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
                title: 'Aggiungi Immagini alla Galleria Slider (Pre-Evento)',
                button: {
                    text: 'Aggiungi alla galleria'
                },
                library: {
                    type: 'image'
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

        // 4c. Gestione upload galleria fotografica e video post-evento (wall ricordi)
        var post_gallery_frame;
        $(document).on('click', '#dfn-upload-post-gallery-btn', function(e) {
            e.preventDefault();

            if (typeof wp === 'undefined' || !wp.media) {
                alert('La libreria dei media di WordPress non è al momento disponibile.');
                return;
            }

            if (post_gallery_frame) {
                post_gallery_frame.open();
                return;
            }

            post_gallery_frame = wp.media({
                title: 'Aggiungi Foto o Video dell\'Evento (Post-Evento)',
                button: {
                    text: 'Aggiungi alla galleria post-evento'
                },
                library: {
                    type: ['image', 'video']
                },
                multiple: true
            });

            function updatePostGalleryStats() {
                var $container = $('#dfn-post-event-gallery-container');
                var $cards = $container.find('.dfn-post-gallery-card');
                var total = $cards.length;
                var videos = $cards.filter('.dfn-post-gallery-card--video').length;
                var photos = total - videos;

                if (total > 0) {
                    $('#dfn-post-gallery-stats').html('📁 ' + total + ' elementi (' + videos + ' video, ' + photos + ' foto)');
                    $('#dfn-clear-post-gallery-btn').show();
                    $('#dfn-post-event-gallery-placeholder').hide();
                } else {
                    $('#dfn-post-gallery-stats').html('📁 Nessun contenuto caricato');
                    $('#dfn-clear-post-gallery-btn').hide();
                    if ($('#dfn-post-event-gallery-placeholder').length === 0) {
                        $container.append('<div style="grid-column: 1 / -1; padding: 20px 10px; color: #64748b; font-size: 12px; text-align: center;" id="dfn-post-event-gallery-placeholder"><span style="font-size: 24px; display: block; margin-bottom: 6px;">🎞️</span>Nessuna foto o video post-evento caricato</div>');
                    } else {
                        $('#dfn-post-event-gallery-placeholder').show();
                    }
                }
            }

            post_gallery_frame.on('select', function() {
                var selection = post_gallery_frame.state().get('selection');
                var currentIds = $('#dfn_post_event_gallery_ids').val().split(',').filter(Boolean);
                
                selection.each(function(attachment) {
                    var attJson = attachment.toJSON();
                    if (currentIds.indexOf(attJson.id.toString()) === -1) {
                        currentIds.push(attJson.id.toString());
                        
                        var isVideo = (attJson.type === 'video');
                        var filename = attJson.filename || (attJson.url ? attJson.url.split('/').pop() : ('Allegato #' + attJson.id));
                        var ext = (attJson.subtype || filename.split('.').pop() || (isVideo ? 'video' : 'img')).toUpperCase();
                        var filesize = attJson.filesizeHumanReadable || '';
                        
                        var mediaHtml = '';
                        if (isVideo) {
                            mediaHtml = '<video src="' + attJson.url + '#t=0.5" preload="metadata" muted playsinline loop style="width: 100%; height: 100%; object-fit: cover; pointer-events: none;"></video>' +
                                        '<span class="dfn-video-badge-pill" style="position: absolute; top: 5px; left: 5px; background: rgba(0, 75, 35, 0.88); backdrop-filter: blur(4px); color: #fff; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.3px;">🎬 ' + ext + '</span>' +
                                        '<span class="dfn-video-play-hint" style="position: absolute; width: 26px; height: 26px; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; pointer-events: none;">▶</span>';
                        } else {
                            var thumbUrl = (attJson.sizes && attJson.sizes.thumbnail) ? attJson.sizes.thumbnail.url : attJson.url;
                            mediaHtml = '<img src="' + thumbUrl + '" style="width: 100%; height: 100%; object-fit: cover;">' +
                                        '<span class="dfn-image-badge-pill" style="position: absolute; top: 5px; left: 5px; background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(4px); color: #fff; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.3px;">📸 ' + ext + '</span>';
                        }
                        
                        var cardClass = isVideo ? 'dfn-post-gallery-card--video' : 'dfn-post-gallery-card--image';
                        var html = '<div class="dfn-post-gallery-card ' + cardClass + '" data-id="' + attJson.id + '" data-is-video="' + (isVideo ? '1' : '0') + '" style="position: relative; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 1px 3px rgba(0,0,0,0.04); text-align: left;">' +
                                   '  <div class="dfn-media-preview-box" style="position: relative; width: 100%; height: 80px; background: #0f172a; overflow: hidden; display: flex; align-items: center; justify-content: center;">' +
                                   mediaHtml +
                                   '    <span class="dfn-delete-post-gallery-img" style="position: absolute; top: 5px; right: 5px; background: rgba(239, 68, 68, 0.9); color: white; border-radius: 50%; width: 18px; height: 18px; line-height: 18px; text-align: center; cursor: pointer; font-size: 12px; font-weight: bold; z-index: 5; box-shadow: 0 1px 3px rgba(0,0,0,0.3);" title="Rimuovi questo elemento">×</span>' +
                                   '  </div>' +
                                   '  <div class="dfn-media-meta-box" style="padding: 6px 8px; background: #ffffff; border-top: 1px solid #f1f5f9;">' +
                                   '    <div style="font-size: 11px; font-weight: 700; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="' + filename + '">' + filename + '</div>' +
                                   '    <div style="font-size: 10px; color: #64748b; margin-top: 2px; display: flex; justify-content: space-between;"><span>' + ext + '</span><span>' + filesize + '</span></div>' +
                                   '  </div>' +
                                   '</div>';
                        
                        $('#dfn-post-event-gallery-placeholder').hide();
                        $('#dfn-post-event-gallery-container').append(html);
                    }
                });

                $('#dfn_post_event_gallery_ids').val(currentIds.join(','));
                updatePostGalleryStats();
            });

            post_gallery_frame.open();
        });

        // Hover preview video silenziosa nella card admin
        $(document).on('mouseenter', '.dfn-post-gallery-card--video', function() {
            var vid = $(this).find('video')[0];
            if (vid) {
                vid.muted = true;
                var playPromise = vid.play();
                if (playPromise !== undefined) {
                    playPromise.catch(function() {});
                }
            }
        });
        $(document).on('mouseleave', '.dfn-post-gallery-card--video', function() {
            var vid = $(this).find('video')[0];
            if (vid) {
                vid.pause();
                vid.currentTime = 0.5;
            }
        });

        // Svuota tutti gli elementi della galleria post-evento
        $(document).on('click', '#dfn-clear-post-gallery-btn', function(e) {
            e.preventDefault();
            if (confirm('Sei sicuro di voler rimuovere tutti i contenuti dalla galleria post-evento?')) {
                $('#dfn-post-event-gallery-container .dfn-post-gallery-card').remove();
                $('#dfn_post_event_gallery_ids').val('');
                var $container = $('#dfn-post-event-gallery-container');
                $('#dfn-post-gallery-stats').html('📁 Nessun contenuto caricato');
                $('#dfn-clear-post-gallery-btn').hide();
                $container.html('<div style="grid-column: 1 / -1; padding: 20px 10px; color: #64748b; font-size: 12px; text-align: center;" id="dfn-post-event-gallery-placeholder"><span style="font-size: 24px; display: block; margin-bottom: 6px;">🎞️</span>Nessuna foto o video post-evento caricato</div>');
            }
        });

        // Rimozione singolo elemento galleria post-evento
        $(document).on('click', '.dfn-delete-post-gallery-img', function(e) {
            e.preventDefault();
            var $card = $(this).closest('.dfn-post-gallery-card');
            var imgId = $card.data('id').toString();
            var currentIds = $('#dfn_post_event_gallery_ids').val().split(',').filter(Boolean);
            
            var index = currentIds.indexOf(imgId);
            if (index > -1) {
                currentIds.splice(index, 1);
            }
            
            $card.remove();
            $('#dfn_post_event_gallery_ids').val(currentIds.join(','));
            
            // Aggiorna statistiche
            var $container = $('#dfn-post-event-gallery-container');
            var $cards = $container.find('.dfn-post-gallery-card');
            var total = $cards.length;
            var videos = $cards.filter('.dfn-post-gallery-card--video').length;
            var photos = total - videos;

            if (total > 0) {
                $('#dfn-post-gallery-stats').html('📁 ' + total + ' elementi (' + videos + ' video, ' + photos + ' foto)');
                $('#dfn-clear-post-gallery-btn').show();
            } else {
                $('#dfn-post-gallery-stats').html('📁 Nessun contenuto caricato');
                $('#dfn-clear-post-gallery-btn').hide();
                $container.html('<div style="grid-column: 1 / -1; padding: 20px 10px; color: #64748b; font-size: 12px; text-align: center;" id="dfn-post-event-gallery-placeholder"><span style="font-size: 24px; display: block; margin-bottom: 6px;">🎞️</span>Nessuna foto o video post-evento caricato</div>');
            }
        });
    });

})(jQuery);

/**
 * DFN Post-Event Photo Wall & Lightbox Interactive Controller
 *
 * Gestisce l'interazione per il wall fotografico degli eventi conclusi:
 * apertura lightbox, navigazione precedente/successiva, controlli da tastiera (ESC, Frecce),
 * gesture touch swipe per dispositivi mobile e pre-caricamento fluido delle immagini.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        var $section = $('.dfn-post-gallery-section');
        if (!$section.length) {
            return;
        }

        var $modal     = $('#dfn-post-gallery-lightbox');
        var $image     = $modal.find('.dfn-lightbox-image');
        var $loader    = $modal.find('.dfn-lightbox-loader');
        var $counter   = $modal.find('.dfn-current-index');
        var $caption   = $modal.find('.dfn-lightbox-caption');
        var $items     = $('.dfn-gallery-item');
        var totalItems = $items.length;

        if (!totalItems || !$modal.length) {
            return;
        }

        var currentIndex = 0;
        var isOpen = false;
        var touchStartX = 0;
        var touchStartY = 0;

        // Raccolta dati immagini
        var galleryData = [];
        $items.each(function(i) {
            var $item = $(this);
            galleryData.push({
                index: i,
                fullSrc: $item.data('full-src') || '',
                caption: $item.data('caption') || '',
                thumb: $item.find('img').attr('src') || ''
            });
        });

        /**
         * Carica e visualizza l'immagine all'indice specificato
         */
        function loadImage(index) {
            if (index < 0) {
                index = totalItems - 1;
            } else if (index >= totalItems) {
                index = 0;
            }

            currentIndex = index;
            var data = galleryData[currentIndex];

            // Aggiorna contatore e didascalia
            $counter.text(currentIndex + 1);
            if (data.caption) {
                $caption.text(data.caption).show();
            } else {
                $caption.text('').hide();
            }

            // Mostra loader e nascondi immagine precedente
            $image.removeClass('is-loaded');
            $loader.show();

            // Precaricamento immagine full
            var tempImg = new Image();
            tempImg.onload = function() {
                $image.attr('src', data.fullSrc);
                $image.attr('alt', data.caption || 'Scatto dell\'evento');
                $loader.hide();
                $image.addClass('is-loaded');
            };
            tempImg.onerror = function() {
                $loader.hide();
                $image.attr('src', data.thumb);
                $image.addClass('is-loaded');
            };
            tempImg.src = data.fullSrc;
        }

        /**
         * Apre la finestra Lightbox
         */
        function openLightbox(index) {
            isOpen = true;
            $('body').addClass('dfn-lightbox-open');
            $modal.addClass('is-active').attr('aria-hidden', 'false');
            loadImage(index);
            $modal.focus();
        }

        /**
         * Chiude la finestra Lightbox
         */
        function closeLightbox() {
            isOpen = false;
            $modal.removeClass('is-active').attr('aria-hidden', 'true');
            $('body').removeClass('dfn-lightbox-open');
            $image.removeClass('is-loaded').attr('src', '');
        }

        /**
         * Navigazione precedente / successiva
         */
        function goNext() {
            loadImage(currentIndex + 1);
        }

        function goPrev() {
            loadImage(currentIndex - 1);
        }

        // Click su miniatura galleria
        $items.on('click', function(e) {
            e.preventDefault();
            var index = parseInt($(this).data('index'), 10) || 0;
            openLightbox(index);
        });

        // Accessibilità: apertura con tasto Enter o Spazio su elemento in focus
        $items.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                var index = parseInt($(this).data('index'), 10) || 0;
                openLightbox(index);
            }
        });

        // Click pulsanti Lightbox
        $modal.find('.dfn-lightbox-close').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeLightbox();
        });

        $modal.find('.dfn-lightbox-next').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            goNext();
        });

        $modal.find('.dfn-lightbox-prev').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            goPrev();
        });

        // Click sul backdrop o sullo sfondo per chiudere
        $modal.on('click', function(e) {
            if ($(e.target).closest('.dfn-lightbox-content, .dfn-lightbox-prev, .dfn-lightbox-next, .dfn-lightbox-footer').length === 0) {
                closeLightbox();
            }
        });

        // Tasti di scelta rapida da tastiera (ESC, Freccia Sinistra, Freccia Destra)
        $(document).on('keydown', function(e) {
            if (!isOpen) return;

            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                closeLightbox();
            } else if (e.key === 'ArrowRight' || e.key === 'Right') {
                e.preventDefault();
                goNext();
            } else if (e.key === 'ArrowLeft' || e.key === 'Left') {
                e.preventDefault();
                goPrev();
            }
        });

        // Supporto gesture touch swipe per smartphone / tablet
        $modal.on('touchstart', function(e) {
            var touch = e.originalEvent.touches[0] || e.originalEvent.changedTouches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
        });

        $modal.on('touchend', function(e) {
            if (!isOpen) return;
            var touch = e.originalEvent.touches[0] || e.originalEvent.changedTouches[0];
            var diffX = touch.clientX - touchStartX;
            var diffY = touch.clientY - touchStartY;

            // Swipe prevalentemente orizzontale
            if (Math.abs(diffX) > 40 && Math.abs(diffX) > Math.abs(diffY)) {
                if (diffX < 0) {
                    // Swipe verso sinistra -> foto successiva
                    goNext();
                } else {
                    // Swipe verso destra -> foto precedente
                    goPrev();
                }
            } else if (diffY > 80 && Math.abs(diffY) > Math.abs(diffX) * 1.5) {
                // Swipe verso il basso -> chiudi lightbox (comodo da mobile)
                closeLightbox();
            }
        });

    });

})(jQuery);

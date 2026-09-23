/**
 * DFN Post-Event Photo & Video Wall & Lightbox Interactive Controller
 *
 * Gestisce l'interazione multimediale per il wall dei ricordi degli eventi conclusi:
 * - Supporto foto e video (.mp4, .mov, .webm)
 * - Micro-anteprima video muta in loop al passaggio del mouse sulla card
 * - Apertura lightbox con player video HTML5 a schermo intero o immagine ad alta risoluzione
 * - Navigazione precedente/successiva, controlli da tastiera (ESC, Frecce)
 * - Gesture touch swipe per smartphone e tablet
 * - Stop automatico dell'audio e del video al cambio slide o alla chiusura
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
        var $video     = $modal.find('.dfn-lightbox-video');
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

        // Raccolta dati elementi multimediali (foto e video)
        var galleryData = [];
        $items.each(function(i) {
            var $item = $(this);
            var isVideo = ($item.data('type') === 'video');
            galleryData.push({
                index: i,
                type: isVideo ? 'video' : 'image',
                videoSrc: $item.data('video-src') || '',
                mimeType: $item.data('mime') || 'video/webm',
                fullSrc: $item.data('full-src') || '',
                caption: ($item.data('caption') || '').toString().trim(),
                thumb: $item.find('img').attr('src') || ''
            });
        });

        /**
         * Idratatore on-demand del tag video dell'anteprima nella griglia masonry.
         * Evita di scaricare stream di 10 video simultanei al caricamento iniziale della pagina,
         * liberando completamente la banda e la GPU.
         */
        function hydrateVideoPreview(video) {
            if (!video || video.getAttribute('data-hydrated') === 'true') {
                return;
            }
            var lazySrc = video.getAttribute('data-lazy-src');
            var mime = video.getAttribute('data-mime') || 'video/webm';
            if (lazySrc) {
                video.setAttribute('data-hydrated', 'true');
                var source = document.createElement('source');
                source.src = lazySrc;
                source.type = mime;
                video.appendChild(source);
                video.preload = 'metadata';
                video.load();

                // Fade-in morbido quando il frame al tempo t=0.1 è pronto
                $(video).one('loadeddata canplay', function() {
                    $(this).addClass('is-loaded');
                });
            }
        }

        /**
         * Smart Lazy Loading con IntersectionObserver:
         * I video vengono idratati solo quando l'utente scorre in prossimità del Wall (350px prima).
         */
        var $lazyVideos = $('video.dfn-gallery-video-thumb');
        if ('IntersectionObserver' in window && $lazyVideos.length) {
            var videoObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        hydrateVideoPreview(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                rootMargin: '350px 0px',
                threshold: 0.01
            });

            $lazyVideos.each(function() {
                videoObserver.observe(this);
            });
        } else {
            // Fallback per browser privi di IntersectionObserver
            $lazyVideos.each(function() {
                hydrateVideoPreview(this);
            });
        }

        /**
         * Micro-anteprima video muta al passaggio del mouse sulla card nella griglia masonry
         */
        $('.dfn-gallery-item--video').on('mouseenter', function() {
            var $thumbVideo = $(this).find('video.dfn-gallery-video-thumb');
            if ($thumbVideo.length && $thumbVideo[0]) {
                var v = $thumbVideo[0];
                if (v.getAttribute('data-hydrated') !== 'true') {
                    hydrateVideoPreview(v);
                }
                var playPromise = v.play();
                if (playPromise !== undefined) {
                    playPromise.catch(function() {
                        // Silenzia eventuali restrizioni autoplay del browser
                    });
                }
            }
        }).on('mouseleave', function() {
            var $thumbVideo = $(this).find('video.dfn-gallery-video-thumb');
            if ($thumbVideo.length && $thumbVideo[0]) {
                $thumbVideo[0].pause();
                $thumbVideo[0].currentTime = 0;
            }
        });

        /**
         * Ferma e reimposta la riproduzione video nel lightbox
         */
        function stopLightboxVideo() {
            if ($video.length && $video[0]) {
                $video[0].pause();
                $video.removeClass('is-loaded').hide().attr('src', '');
            }
        }

        /**
         * Carica e visualizza l'elemento (foto o video) all'indice specificato
         */
        function loadItem(index) {
            if (index < 0) {
                index = totalItems - 1;
            } else if (index >= totalItems) {
                index = 0;
            }

            // Ferma sempre il video precedente
            stopLightboxVideo();

            currentIndex = index;
            var data = galleryData[currentIndex];

            // Aggiorna contatore e didascalia (mostrata solo se esplicitamente definita)
            $counter.text(currentIndex + 1);
            if (data.caption && data.caption.length > 0) {
                $caption.text(data.caption).show();
            } else {
                $caption.text('').hide();
            }

            if (data.type === 'video') {
                // Modalità VIDEO
                $image.removeClass('is-loaded').hide().attr('src', '');
                $loader.show();

                $video.attr('src', data.videoSrc).show();
                $video[0].load();

                // Quando il video è pronto per essere riprodotto
                $video.one('loadeddata canplay', function() {
                    $loader.hide();
                    $video.addClass('is-loaded');
                    var playPromise = $video[0].play();
                    if (playPromise !== undefined) {
                        playPromise.catch(function() {
                            // Se il browser richiede interazione utente per audio
                        });
                    }
                });

                // Fallback di timeout se l'evento canplay ritarda
                setTimeout(function() {
                    $loader.hide();
                    $video.addClass('is-loaded');
                }, 600);

            } else {
                // Modalità IMMAGINE
                $video.hide();
                $image.removeClass('is-loaded').show();
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
        }

        /**
         * Apre la finestra Lightbox
         */
        function openLightbox(index) {
            isOpen = true;
            $('body').addClass('dfn-lightbox-open');
            $modal.addClass('is-active').attr('aria-hidden', 'false');
            loadItem(index);
            $modal.focus();
        }

        /**
         * Chiude la finestra Lightbox
         */
        function closeLightbox() {
            isOpen = false;
            stopLightboxVideo();
            $modal.removeClass('is-active').attr('aria-hidden', 'true');
            $('body').removeClass('dfn-lightbox-open');
            $image.removeClass('is-loaded').attr('src', '');
        }

        /**
         * Navigazione precedente / successiva
         */
        function goNext() {
            loadItem(currentIndex + 1);
        }

        function goPrev() {
            loadItem(currentIndex - 1);
        }

        // Click su elemento (foto o video) della galleria
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

        // Click sul backdrop o sullo sfondo per chiudere (non sui controlli né sul video/immagine)
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

            // Swipe orizzontale
            if (Math.abs(diffX) > 40 && Math.abs(diffX) > Math.abs(diffY)) {
                if (diffX < 0) {
                    goNext();
                } else {
                    goPrev();
                }
            } else if (diffY > 80 && Math.abs(diffY) > Math.abs(diffX) * 1.5) {
                // Swipe verso il basso -> chiudi lightbox
                closeLightbox();
            }
        });

    });

})(jQuery);

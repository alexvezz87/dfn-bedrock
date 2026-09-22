/**
 * DFN Reviews Carousel
 * Gestione dello slider / carosello delle recensioni per gli eventi conclusi.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

(function($) {
    'use strict';

    function initReviewsCarousels() {
        $('.dfn-reviews-carousel-wrapper').each(function() {
            var $wrapper   = $(this);
            var $carousel  = $wrapper.find('.dfn-reviews-carousel');
            var $slides    = $carousel.find('.dfn-carousel-slide');
            var $dots      = $wrapper.find('.dfn-carousel-dot');
            var $btnPrev   = $wrapper.find('.dfn-carousel-nav.prev');
            var $btnNext   = $wrapper.find('.dfn-carousel-nav.next');
            var $currSlide = $wrapper.find('.dfn-curr-slide');

            var totalSlides = $slides.length;
            if (totalSlides <= 1) {
                return;
            }

            var currentIndex = 0;
            var autoPlayTimer = null;
            var isHovered = false;

            function goToSlide(index) {
                if (index < 0) {
                    index = totalSlides - 1;
                } else if (index >= totalSlides) {
                    index = 0;
                }

                currentIndex = index;

                $slides.removeClass('is-active').css('opacity', 0);
                var $target = $slides.eq(currentIndex);
                $target.addClass('is-active').css('opacity', 1);

                $dots.removeClass('is-active');
                $dots.eq(currentIndex).addClass('is-active');

                if ($currSlide.length) {
                    $currSlide.text(currentIndex + 1);
                }
            }

            function startAutoPlay() {
                stopAutoPlay();
                autoPlayTimer = setInterval(function() {
                    if (!isHovered) {
                        goToSlide(currentIndex + 1);
                    }
                }, 6000);
            }

            function stopAutoPlay() {
                if (autoPlayTimer) {
                    clearInterval(autoPlayTimer);
                    autoPlayTimer = null;
                }
            }

            $btnNext.on('click', function(e) {
                e.preventDefault();
                goToSlide(currentIndex + 1);
                startAutoPlay();
            });

            $btnPrev.on('click', function(e) {
                e.preventDefault();
                goToSlide(currentIndex - 1);
                startAutoPlay();
            });

            $dots.on('click', function(e) {
                e.preventDefault();
                var targetIdx = parseInt($(this).data('slide-target'), 10);
                if (!isNaN(targetIdx)) {
                    goToSlide(targetIdx);
                    startAutoPlay();
                }
            });

            // Pausa durante l'hover con il mouse
            $wrapper.on('mouseenter', function() {
                isHovered = true;
            }).on('mouseleave', function() {
                isHovered = false;
            });

            // Supporto Swipe Touch per dispositivi mobili
            var touchStartX = 0;
            var touchEndX = 0;

            $carousel.on('touchstart', function(e) {
                touchStartX = e.originalEvent.changedTouches[0].screenX;
            }, { passive: true });

            $carousel.on('touchend', function(e) {
                touchEndX = e.originalEvent.changedTouches[0].screenX;
                var diff = touchStartX - touchEndX;
                if (Math.abs(diff) > 40) {
                    if (diff > 0) {
                        goToSlide(currentIndex + 1); // Swipe sinistra -> Next
                    } else {
                        goToSlide(currentIndex - 1); // Swipe destra -> Prev
                    }
                    startAutoPlay();
                }
            }, { passive: true });

            // Avvio iniziale
            goToSlide(0);
            startAutoPlay();
        });
    }

    $(document).ready(function() {
        initReviewsCarousels();
    });

})(jQuery);

/**
 * DFN Events Frontend Live Search & Filtering
 * Perfect FLIP Grid Reflow & Absolute Fade-Out (Zero Snap)
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        var $search  = $('#dfn-filter-search');
        var $month   = $('#dfn-filter-month');
        var $city    = $('#dfn-filter-city');
        var $reset   = $('#dfn-filter-reset');
        var $cards   = $('.dfn-event-card');

        if (!$cards.length) return;

        var $grid = $cards.parent();
        $grid.css('position', 'relative');

        // Leggi parametri URL se presenti (es. ?comune=Novara)
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('q')) $search.val(urlParams.get('q'));
        if (urlParams.has('mese')) $month.val(urlParams.get('mese'));
        if (urlParams.has('comune')) $city.val(urlParams.get('comune'));

        function applyFilters() {
            var searchTerm    = ($search.val() || '').toLowerCase().trim();
            var selectedMonth = $month.val() || '';
            var selectedCity  = ($city.val() || '').toLowerCase().trim();

            // 1. FIRST: Registra le posizioni visive correnti prima della modifica del layout
            var firstPositions = [];
            $cards.each(function() {
                var el = this;
                var $el = $(el);
                if ($el.is(':visible') && $el.css('opacity') !== '0') {
                    firstPositions.push({
                        el: el,
                        rect: el.getBoundingClientRect()
                    });
                }
            });

            var visibleCards = [];
            var hidingCards  = [];

            // 2. Classifica le schedine tra visibili e nascoste
            $cards.each(function() {
                var $card     = $(this);
                var title     = ($card.data('title') || '').toString().toLowerCase();
                var location  = ($card.data('location') || '').toString().toLowerCase();
                var city      = ($card.data('city') || '').toString().toLowerCase();
                var yearMonth = ($card.data('yearmonth') || '').toString();

                var matchesSearch = !searchTerm || title.indexOf(searchTerm) !== -1 || location.indexOf(searchTerm) !== -1 || city.indexOf(searchTerm) !== -1;
                var matchesMonth  = !selectedMonth || yearMonth === selectedMonth;
                var matchesCity   = !selectedCity || city === selectedCity;

                if (matchesSearch && matchesMonth && matchesCity) {
                    visibleCards.push($card[0]);
                } else {
                    hidingCards.push($card[0]);
                }
            });

            // 3. PER GLI ELEMENTI IN USCITA: Fissali temporaneamente in posizione assoluta
            // così la griglia si riorganizza IMMEDIATAMENTE per i rimanenti senza scatti
            hidingCards.forEach(function(el) {
                var $card = $(el);
                if ($card.is(':visible') && $card.css('position') !== 'absolute') {
                    var rect = el.getBoundingClientRect();
                    var gridRect = $grid[0].getBoundingClientRect();

                    $card.css({
                        position: 'absolute',
                        top: (rect.top - gridRect.top) + 'px',
                        left: (rect.left - gridRect.left) + 'px',
                        width: rect.width + 'px',
                        height: rect.height + 'px',
                        margin: 0,
                        zIndex: 1
                    });

                    $card.stop(true).animate({ opacity: 0 }, {
                        duration: 500,
                        complete: function() {
                            $card.hide().css({
                                position: '',
                                top: '',
                                left: '',
                                width: '',
                                height: '',
                                margin: '',
                                zIndex: '',
                                transform: '',
                                transition: ''
                            });
                        }
                    });
                }
            });

            // 4. PER GLI ELEMENTI IN ENTRATA / VISIBILI: Ripristina la posizione normale nel flusso di griglia
            visibleCards.forEach(function(el) {
                var $card = $(el);
                $card.css({
                    position: '',
                    top: '',
                    left: '',
                    width: '',
                    height: '',
                    margin: '',
                    zIndex: ''
                });

                if ($card.is(':hidden')) {
                    $card.css({ display: '', opacity: 0 });
                }
                $card.stop(true).animate({ opacity: 1 }, 500);
            });

            // 5. FLIP ANIMATION: Fai scivolare in modo fluido le schedine rimaste verso i loro nuovi posti
            requestAnimationFrame(function() {
                firstPositions.forEach(function(item) {
                    var el = item.el;
                    // Solo per le carte che rimangono visibili nella griglia
                    if (visibleCards.indexOf(el) !== -1) {
                        var lastRect = el.getBoundingClientRect();
                        var deltaX = item.rect.left - lastRect.left;
                        var deltaY = item.rect.top - lastRect.top;

                        if (deltaX !== 0 || deltaY !== 0) {
                            el.style.transform = 'translate(' + deltaX + 'px, ' + deltaY + 'px)';
                            el.style.transition = 'none';

                            requestAnimationFrame(function() {
                                el.style.transition = 'transform 500ms cubic-bezier(0.4, 0, 0.2, 1)';
                                el.style.transform = '';
                            });
                        }
                    }
                });
            });

            var $noResults = $('#dfn-no-filter-results');
            if (visibleCards.length === 0) {
                setTimeout(function() {
                    if (!$noResults.length) {
                        $grid.append('<div id="dfn-no-filter-results" style="grid-column: 1 / -1; text-align:center; padding: 40px 20px; background:#f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; color: #64748b; font-size:14px; font-weight:500;">🔍 Nessun evento corrisponde ai criteri selezionati.<br><small style="color:#94a3b8;">Prova a cambiare mese, comune o parola chiave.</small></div>');
                    } else {
                        $noResults.fadeIn(500);
                    }
                }, 500);
            } else {
                if ($noResults.length) {
                    $noResults.hide();
                }
            }
        }

        var debounceTimer;
        function debounceFilter() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(applyFilters, 150);
        }

        $search.on('input keyup search', debounceFilter);
        $month.on('change', applyFilters);
        $city.on('change', applyFilters);

        $reset.on('click', function() {
            $search.val('');
            $month.val('');
            $city.val('');
            applyFilters();
        });

        // Esegui al caricamento iniziale
        applyFilters();
    });
})(jQuery);

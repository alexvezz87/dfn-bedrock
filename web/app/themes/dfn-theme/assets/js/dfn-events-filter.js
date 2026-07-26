/**
 * DFN Events Frontend Live Search & Filtering
 * Smooth FLIP Grid Layout Reflow & 1-Second Fade In/Out
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

        // Leggi parametri URL se presenti (es. ?comune=Novara)
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('q')) $search.val(urlParams.get('q'));
        if (urlParams.has('mese')) $month.val(urlParams.get('mese'));
        if (urlParams.has('comune')) $city.val(urlParams.get('comune'));

        function applyFilters() {
            var searchTerm    = ($search.val() || '').toLowerCase().trim();
            var selectedMonth = $month.val() || '';
            var selectedCity  = ($city.val() || '').toLowerCase().trim();

            // 1. FIRST: Salva le posizioni iniziali delle schedine visibili
            var firstPositions = [];
            $cards.each(function() {
                var el = this;
                var $el = $(el);
                var isVisible = $el.is(':visible') && $el.css('opacity') !== '0';
                firstPositions.push({
                    el: el,
                    rect: isVisible ? el.getBoundingClientRect() : null,
                    visible: isVisible
                });
            });

            var visibleCount = 0;

            // 2. FILTER & LAYOUT CHANGE: Determina quali carte devono essere visibili
            $cards.each(function(idx) {
                var $card     = $(this);
                var title     = ($card.data('title') || '').toString().toLowerCase();
                var location  = ($card.data('location') || '').toString().toLowerCase();
                var city      = ($card.data('city') || '').toString().toLowerCase();
                var yearMonth = ($card.data('yearmonth') || '').toString();

                var matchesSearch = !searchTerm || title.indexOf(searchTerm) !== -1 || location.indexOf(searchTerm) !== -1 || city.indexOf(searchTerm) !== -1;
                var matchesMonth  = !selectedMonth || yearMonth === selectedMonth;
                var matchesCity   = !selectedCity || city === selectedCity;

                if (matchesSearch && matchesMonth && matchesCity) {
                    visibleCount++;
                    if ($card.is(':hidden')) {
                        $card.css({ display: '', opacity: 0 });
                    }
                    $card.stop(true).animate({ opacity: 1 }, 1000);
                } else {
                    $card.stop(true).animate({ opacity: 0 }, {
                        duration: 1000,
                        complete: function() {
                            $(this).hide();
                        }
                    });
                }
            });

            // 3. LAST & INVERT (FLIP): Anima lo scorrimento delle schede rimanenti verso i nuovi posti nella griglia
            requestAnimationFrame(function() {
                firstPositions.forEach(function(item) {
                    var el = item.el;
                    var $el = $(el);

                    if (item.visible && $el.is(':visible')) {
                        var lastRect = el.getBoundingClientRect();
                        var deltaX = item.rect.left - lastRect.left;
                        var deltaY = item.rect.top - lastRect.top;

                        if (deltaX !== 0 || deltaY !== 0) {
                            // Sposta istantaneamente l'elemento alla vecchia posizione
                            el.style.transform = 'translate(' + deltaX + 'px, ' + deltaY + 'px)';
                            el.style.transition = 'none';

                            // Anima in modo fluido verso la nuova posizione (0,0) in 1 secondo
                            requestAnimationFrame(function() {
                                el.style.transition = 'transform 1000ms cubic-bezier(0.4, 0, 0.2, 1)';
                                el.style.transform = '';
                            });
                        }
                    }
                });
            });

            var $noResults = $('#dfn-no-filter-results');
            if (visibleCount === 0) {
                setTimeout(function() {
                    if (!$noResults.length) {
                        $cards.parent().append('<div id="dfn-no-filter-results" style="grid-column: 1 / -1; text-align:center; padding: 40px 20px; background:#f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; color: #64748b; font-size:14px; font-weight:500;">🔍 Nessun evento corrisponde ai criteri selezionati.<br><small style="color:#94a3b8;">Prova a cambiare mese, comune o parola chiave.</small></div>');
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

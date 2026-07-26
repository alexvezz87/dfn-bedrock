/**
 * DFN Events Frontend Live Search & Filtering with Staggered Pop Animations
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

            var visibleIndex = 0;
            var hiddenIndex  = 0;

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
                    var inDelay = visibleIndex * 70; // Ritardo sfalsato 70ms ad onda
                    visibleIndex++;

                    $card.removeClass('dfn-card-animating-out');
                    if ($card.is(':hidden') || !$card.hasClass('dfn-card-visible')) {
                        $card.css('display', '');
                        setTimeout(function() {
                            $card.addClass('dfn-card-animating-in dfn-card-visible');
                            setTimeout(function() {
                                $card.removeClass('dfn-card-animating-in');
                            }, 550);
                        }, inDelay);
                    }
                } else {
                    var outDelay = hiddenIndex * 40; // Ritardo di uscita ad onda
                    hiddenIndex++;

                    if ($card.hasClass('dfn-card-visible') || $card.is(':visible')) {
                        setTimeout(function() {
                            $card.removeClass('dfn-card-visible dfn-card-animating-in').addClass('dfn-card-animating-out');
                            setTimeout(function() {
                                if ($card.hasClass('dfn-card-animating-out')) {
                                    $card.hide().removeClass('dfn-card-animating-out');
                                }
                            }, 450);
                        }, outDelay);
                    }
                }
            });

            var $noResults = $('#dfn-no-filter-results');
            var maxWait = Math.max(visibleIndex, hiddenIndex) * 50 + 200;

            if (visibleIndex === 0) {
                setTimeout(function() {
                    if (!$noResults.length) {
                        $cards.parent().append('<div id="dfn-no-filter-results" style="grid-column: 1 / -1; text-align:center; padding: 40px 20px; background:#f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; color: #64748b; font-size:14px; font-weight:500;">🔍 Nessun evento corrisponde ai criteri selezionati.<br><small style="color:#94a3b8;">Prova a cambiare mese, comune o parola chiave.</small></div>');
                    } else {
                        $noResults.fadeIn(300);
                    }
                }, maxWait);
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

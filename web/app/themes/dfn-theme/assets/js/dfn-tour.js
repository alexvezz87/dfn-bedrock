/**
 * DFN Tour — Guided Tutorial Balloon System
 * Area Riservata FAI Prenotazioni 2.0
 *
 * Engine autonomo, zero dipendenze (no jQuery richiesto).
 * I dati degli step sono iniettati da PHP tramite wp_localize_script
 * nella variabile globale `dfnTourData`.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /*  Costanti                                                            */
    /* ------------------------------------------------------------------ */
    var BALLOON_MARGIN = 16;   // px di distanza tra balloon e elemento
    var SCROLL_OFFSET  = 80;   // px di offset dall'alto nello scroll

    /* ------------------------------------------------------------------ */
    /*  Stato del tour                                                      */
    /* ------------------------------------------------------------------ */
    var state = {
        steps:       [],       // array degli step dello step tour corrente
        currentStep: 0,        // indice corrente (0-based)
        running:     false,    // true se il tour e' attivo
        storageKey:  '',       // chiave localStorage per il completamento
        balloon:     null,     // elemento .dfn-tour-balloon
        hole:        null,     // elemento .dfn-tour-highlight-hole
        masks:       [],       // 4 div .dfn-tour-mask
        fab:         null,     // bottone FAB
    };

    /* ------------------------------------------------------------------ */
    /*  Utilita'                                                            */
    /* ------------------------------------------------------------------ */

    /** Recupera il rect di un elemento, oppure null. */
    function getRect(el) {
        if (!el) return null;
        return el.getBoundingClientRect();
    }

    /** Clamp di un numero tra min e max. */
    function clamp(val, min, max) {
        return Math.max(min, Math.min(max, val));
    }

    /** Scorre la pagina per portare l'elemento nel viewport con offset. */
    function scrollToEl(el) {
        if (!el) return;
        var rect = getRect(el);
        var viewH = window.innerHeight;
        var inView = rect.top >= SCROLL_OFFSET && rect.bottom <= viewH - 20;
        if (!inView) {
            var targetY = window.scrollY + rect.top - SCROLL_OFFSET;
            window.scrollTo({ top: targetY, behavior: 'smooth' });
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Costruzione DOM                                                     */
    /* ------------------------------------------------------------------ */

    /** Crea i 4 rettangoli maschera e li inietta nel body. */
    function createMasks() {
        state.masks = [];
        for (var i = 0; i < 4; i++) {
            var m = document.createElement('div');
            m.className = 'dfn-tour-mask';
            m.style.transition = 'all 0.28s ease';
            m.addEventListener('click', function () { skipTour(); });
            document.body.appendChild(m);
            state.masks.push(m);
        }
    }

    /** Aggiorna la posizione delle 4 maschere intorno al rect dell'elemento. */
    function updateMasks(rect) {
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var pad = 6; // piccolo padding attorno all'elemento

        if (!rect) {
            // Copre tutto lo schermo se nessun elemento
            state.masks[0].style.cssText = 'top:0;left:0;right:0;height:100vh;';
            state.masks[1].style.cssText = 'display:none;';
            state.masks[2].style.cssText = 'display:none;';
            state.masks[3].style.cssText = 'display:none;';
            return;
        }

        var top    = rect.top - pad;
        var left   = rect.left - pad;
        var bottom = rect.bottom + pad;
        var right  = rect.right + pad;

        // Top mask
        state.masks[0].style.cssText = 'display:block;top:0;left:0;width:' + vw + 'px;height:' + Math.max(0, top) + 'px;';
        // Bottom mask
        state.masks[1].style.cssText = 'display:block;top:' + Math.min(vh, bottom) + 'px;left:0;width:' + vw + 'px;height:' + Math.max(0, vh - bottom) + 'px;';
        // Left mask
        state.masks[2].style.cssText = 'display:block;top:' + Math.max(0, top) + 'px;left:0;width:' + Math.max(0, left) + 'px;height:' + Math.max(0, bottom - top) + 'px;';
        // Right mask
        state.masks[3].style.cssText = 'display:block;top:' + Math.max(0, top) + 'px;left:' + Math.min(vw, right) + 'px;width:' + Math.max(0, vw - right) + 'px;height:' + Math.max(0, bottom - top) + 'px;';
    }

    /** Crea il riquadro "hole" attorno all'elemento evidenziato. */
    function createHole() {
        var h = document.createElement('div');
        h.className = 'dfn-tour-highlight-hole';
        document.body.appendChild(h);
        state.hole = h;
    }

    /** Aggiorna la posizione del hole. */
    function updateHole(rect) {
        var h = state.hole;
        if (!rect) {
            h.style.display = 'none';
            return;
        }
        var pad = 6;
        h.style.display = 'block';
        h.style.top    = (rect.top - pad) + 'px';
        h.style.left   = (rect.left - pad) + 'px';
        h.style.width  = (rect.width + pad * 2) + 'px';
        h.style.height = (rect.height + pad * 2) + 'px';
    }

    /** Calcola la posizione ottimale del balloon rispetto al rect dell'elemento. */
    function calcBalloonPos(rect) {
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var bw = 340 + BALLOON_MARGIN; // larghezza stimata balloon
        var bh = 200;                   // altezza stimata balloon

        if (!rect) {
            return { pos: 'center', top: (vh - bh) / 2, left: (vw - 340) / 2 };
        }

        var pad = 6;
        var spaceTop    = rect.top - pad;
        var spaceBottom = vh - rect.bottom - pad;
        var spaceLeft   = rect.left - pad;
        var spaceRight  = vw - rect.right - pad;

        var pos, top, left;

        if (spaceBottom >= bh || spaceBottom > spaceTop) {
            // Balloon sotto
            pos  = 'bottom';
            top  = rect.bottom + pad + BALLOON_MARGIN;
            left = clamp(rect.left, 12, vw - 352);
        } else {
            // Balloon sopra
            pos  = 'top';
            top  = rect.top - pad - BALLOON_MARGIN - bh;
            left = clamp(rect.left, 12, vw - 352);
        }

        // Se top e' negativo e c'e' spazio a destra o sinistra, usa il lato
        if (top < 10 && spaceRight >= bw) {
            pos  = 'right';
            top  = clamp(rect.top, 12, vh - bh - 12);
            left = rect.right + pad + BALLOON_MARGIN;
        } else if (top < 10 && spaceLeft >= bw) {
            pos  = 'left';
            top  = clamp(rect.top, 12, vh - bh - 12);
            left = rect.left - pad - BALLOON_MARGIN - 340;
        }

        // Fallback centro
        if (top < 0 || top + bh > vh) {
            pos  = 'center';
            top  = clamp((vh - bh) / 2, 20, vh - bh - 20);
            left = (vw - 340) / 2;
        }

        return { pos: pos, top: top, left: clamp(left, 12, vw - 352) };
    }

    /** Costruisce l'HTML del balloon e lo inietta nel body. */
    function createBalloon() {
        var b = document.createElement('div');
        b.className = 'dfn-tour-balloon';
        b.setAttribute('role', 'dialog');
        b.setAttribute('aria-modal', 'true');
        b.setAttribute('aria-live', 'polite');
        b.innerHTML =
            '<div class="dfn-tour-balloon-header">' +
                '<h3 class="dfn-tour-balloon-title" id="dfn-tour-title"></h3>' +
                '<button class="dfn-tour-skip-btn" id="dfn-tour-skip">Salta tour</button>' +
            '</div>' +
            '<div class="dfn-tour-balloon-body"><p id="dfn-tour-body"></p></div>' +
            '<div class="dfn-tour-progress" id="dfn-tour-dots"></div>' +
            '<div class="dfn-tour-balloon-footer">' +
                '<span class="dfn-tour-step-counter" id="dfn-tour-counter"></span>' +
                '<div class="dfn-tour-nav">' +
                    '<button class="dfn-tour-btn dfn-tour-btn-prev" id="dfn-tour-prev">← Indietro</button>' +
                    '<button class="dfn-tour-btn dfn-tour-btn-next" id="dfn-tour-next">Avanti →</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(b);
        state.balloon = b;

        // Event listeners
        document.getElementById('dfn-tour-skip').addEventListener('click', skipTour);
        document.getElementById('dfn-tour-prev').addEventListener('click', prevStep);
        document.getElementById('dfn-tour-next').addEventListener('click', nextStep);
    }

    /* ------------------------------------------------------------------ */
    /*  Render step                                                         */
    /* ------------------------------------------------------------------ */

    function renderStep(index) {
        var step = state.steps[index];
        if (!step) return;

        var el = step.selector ? document.querySelector(step.selector) : null;

        // Scroll all'elemento se necessario
        if (el) {
            scrollToEl(el);
            // Piccolo delay per lo scroll prima di calcolare la posizione
            setTimeout(function () { positionForStep(index, el); }, 200);
        } else {
            positionForStep(index, null);
        }
    }

    function positionForStep(index, el) {
        var step = state.steps[index];
        var rect = el ? getRect(el) : null;

        // Aggiorna maschere e hole
        updateMasks(rect);
        updateHole(rect);

        // Contenuto balloon
        document.getElementById('dfn-tour-title').textContent   = step.title;
        document.getElementById('dfn-tour-body').innerHTML       = step.content;
        document.getElementById('dfn-tour-counter').textContent = (index + 1) + ' / ' + state.steps.length;

        // Dots
        var dotsEl = document.getElementById('dfn-tour-dots');
        dotsEl.innerHTML = '';
        state.steps.forEach(function (s, i) {
            var dot = document.createElement('button');
            dot.className = 'dfn-tour-dot' + (i < index ? ' done' : '') + (i === index ? ' active' : '');
            dot.setAttribute('aria-label', 'Step ' + (i + 1));
            dot.setAttribute('title', s.title);
            (function (idx) {
                dot.addEventListener('click', function () { goToStep(idx); });
            })(i);
            dotsEl.appendChild(dot);
        });

        // Pulsanti prev/next
        var prevBtn = document.getElementById('dfn-tour-prev');
        var nextBtn = document.getElementById('dfn-tour-next');
        prevBtn.disabled = (index === 0);

        var isLast = (index === state.steps.length - 1);
        nextBtn.textContent = isLast ? 'Fine ✓' : 'Avanti →';
        nextBtn.className   = 'dfn-tour-btn ' + (isLast ? 'dfn-tour-btn-finish' : 'dfn-tour-btn-next');

        // Posizionamento balloon
        var bp = calcBalloonPos(rect);
        state.balloon.setAttribute('data-pos', bp.pos);
        state.balloon.style.top  = bp.top + 'px';
        state.balloon.style.left = bp.left + 'px';

        // Riavvia animazione balloon
        state.balloon.style.animation = 'none';
        state.balloon.offsetHeight; // reflow
        state.balloon.style.animation = '';
    }

    /* ------------------------------------------------------------------ */
    /*  Navigazione                                                         */
    /* ------------------------------------------------------------------ */

    function goToStep(index) {
        if (!state.running) return;
        state.currentStep = clamp(index, 0, state.steps.length - 1);
        renderStep(state.currentStep);
    }

    function nextStep() {
        if (!state.running) return;
        if (state.currentStep >= state.steps.length - 1) {
            completeTour();
        } else {
            goToStep(state.currentStep + 1);
        }
    }

    function prevStep() {
        if (!state.running) return;
        goToStep(state.currentStep - 1);
    }

    /* ------------------------------------------------------------------ */
    /*  Avvio / Chiusura / Completamento                                   */
    /* ------------------------------------------------------------------ */

    function startTour(steps, storageKey) {
        if (state.running) teardown();

        state.steps       = steps;
        state.currentStep = 0;
        state.storageKey  = storageKey;
        state.running     = true;

        document.body.classList.add('dfn-tour-running');

        createMasks();
        createHole();
        createBalloon();

        renderStep(0);

        // Listener tastiera
        document.addEventListener('keydown', onKeyDown);
        // Riposiziona al resize
        window.addEventListener('resize', onResize);
        window.addEventListener('scroll', onScrollEnd, { passive: true });
    }

    function completeTour() {
        if (state.storageKey) {
            try { localStorage.setItem(state.storageKey, '1'); } catch (e) {}
        }
        teardown();
    }

    function skipTour() {
        teardown();
    }

    function teardown() {
        state.running = false;
        document.body.classList.remove('dfn-tour-running');

        // Rimuovi balloon
        if (state.balloon && state.balloon.parentNode) {
            state.balloon.parentNode.removeChild(state.balloon);
        }
        // Rimuovi hole
        if (state.hole && state.hole.parentNode) {
            state.hole.parentNode.removeChild(state.hole);
        }
        // Rimuovi maschere
        state.masks.forEach(function (m) {
            if (m.parentNode) m.parentNode.removeChild(m);
        });

        state.balloon = null;
        state.hole    = null;
        state.masks   = [];

        // Rimuovi listener
        document.removeEventListener('keydown', onKeyDown);
        window.removeEventListener('resize', onResize);
        window.removeEventListener('scroll', onScrollEnd);

        // Mostra FAB
        if (state.fab) {
            state.fab.style.opacity = '1';
            state.fab.style.pointerEvents = '';
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Event listeners globali                                             */
    /* ------------------------------------------------------------------ */

    function onKeyDown(e) {
        if (e.key === 'Escape' || e.keyCode === 27) { skipTour(); return; }
        if (e.key === 'ArrowRight' || e.keyCode === 39) { nextStep(); return; }
        if (e.key === 'ArrowLeft'  || e.keyCode === 37) { prevStep(); return; }
    }

    var resizeTimer;
    function onResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            if (state.running) renderStep(state.currentStep);
        }, 100);
    }

    var scrollTimer;
    function onScrollEnd() {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(function () {
            if (state.running) {
                var step = state.steps[state.currentStep];
                var el   = step && step.selector ? document.querySelector(step.selector) : null;
                var rect = el ? getRect(el) : null;
                updateMasks(rect);
                updateHole(rect);
                var bp = calcBalloonPos(rect);
                state.balloon.setAttribute('data-pos', bp.pos);
                state.balloon.style.top  = bp.top + 'px';
                state.balloon.style.left = bp.left + 'px';
            }
        }, 60);
    }

    /* ------------------------------------------------------------------ */
    /*  Inizializzazione — attende il DOM                                   */
    /* ------------------------------------------------------------------ */

    function init() {
        var data = window.dfnTourData;
        if (!data || !data.tours) return;

        // Determina quale tour e' attivo in base alle pagine presenti
        data.tours.forEach(function (tour) {
            // Controlla se almeno un elemento del primo step e' presente nel DOM
            var firstSelector = tour.steps[0] && tour.steps[0].selector;
            var anchor = firstSelector ? document.querySelector(firstSelector) : null;
            if (!anchor && tour.sectionAnchor) {
                anchor = document.querySelector(tour.sectionAnchor);
            }
            if (!anchor) return; // Questo tour non e' per questa pagina

            // Crea il FAB
            var fab = document.createElement('button');
            fab.className = 'dfn-tour-fab';
            fab.setAttribute('aria-label', 'Avvia il tour guidato');
            fab.innerHTML = '<span class="dfn-tour-fab-icon">?</span> Guida';
            document.body.appendChild(fab);
            state.fab = fab;

            fab.addEventListener('click', function () {
                startTour(tour.steps, tour.storageKey);
            });

            // Auto-avvio alla prima visita
            var done = false;
            try { done = !!localStorage.getItem(tour.storageKey); } catch (e) {}
            if (!done) {
                fab.classList.add('dfn-tour-fab-pulse');
                setTimeout(function () {
                    startTour(tour.steps, tour.storageKey);
                }, 800);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

/**
 * DFN GDPR Compliance — Cookie Consent & Form Privacy
 *
 * Gestisce:
 * 1. Cookie banner con consenso granulare (tecnici, analitici)
 * 2. Salvataggio preferenze cookie per 365 giorni
 * 3. Caricamento condizionale di Google Analytics 4 solo su consenso
 * 4. Validazione client-side checkbox privacy nei form di prenotazione
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

(function () {
    'use strict';

    /* ============================================================
       COSTANTI & CONFIGURAZIONE
       ============================================================ */
    var COOKIE_PREFIX   = 'dfn_consent_';
    var COOKIE_DURATION = 365; // giorni

    var CONSENT_KEYS = {
        technical  : COOKIE_PREFIX + 'technical',  // Sempre true
        analytics  : COOKIE_PREFIX + 'analytics',
        banner_seen: COOKIE_PREFIX + 'banner_seen'
    };

    /* ============================================================
       UTILITY: COOKIE
       ============================================================ */
    var CookieUtil = {
        set: function (name, value, days) {
            var expires = '';
            if (days) {
                var d = new Date();
                d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = '; expires=' + d.toUTCString();
            }
            document.cookie = name + '=' + (value ? 'true' : 'false') + expires + '; path=/; SameSite=Lax';
        },
        get: function (name) {
            var nameEQ = name + '=';
            var ca = document.cookie.split(';');
            for (var i = 0; i < ca.length; i++) {
                var c = ca[i].trim();
                if (c.indexOf(nameEQ) === 0) {
                    return c.substring(nameEQ.length);
                }
            }
            return null;
        },
        isTrue: function (name) {
            return this.get(name) === 'true';
        }
    };

    /* ============================================================
       GESTIONE SCRIPT TERZE PARTI
       ============================================================ */
    var ScriptManager = {

        /**
         * Attiva Google Analytics 4 se il consenso è dato.
         * L'ID di misura è passato dal PHP tramite dfnGdprVars.ga4Id.
         */
        enableAnalytics: function () {
            var ga4Id = (typeof dfnGdprVars !== 'undefined' && dfnGdprVars.ga4Id) ? dfnGdprVars.ga4Id : '';
            if (!ga4Id) return;               // Nessun ID configurato
            if (window.__dfnGa4Loaded) return; // Già caricato

            window.__dfnGa4Loaded = true;

            // Carica gtag.js in modo asincrono
            var s = document.createElement('script');
            s.src = 'https://www.googletagmanager.com/gtag/js?id=' + ga4Id;
            s.async = true;
            document.head.appendChild(s);

            // Inizializza la configurazione GA4
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () { dataLayer.push(arguments); };
            window.gtag('js', new Date());
            window.gtag('config', ga4Id, {
                'anonymize_ip': true,          // Anonimizzazione IP — obbligatorio GDPR
                'cookie_flags': 'SameSite=None;Secure'
            });
        },

        /**
         * Applica le preferenze salvate al caricamento pagina.
         */
        applyConsentOnLoad: function () {
            if (CookieUtil.isTrue(CONSENT_KEYS.analytics)) {
                ScriptManager.enableAnalytics();
            }
        }
    };

    /* ============================================================
       COOKIE BANNER
       ============================================================ */
    var CookieBanner = {
        overlay    : null,
        banner     : null,
        manageLink : null,

        init: function () {
            this.overlay    = document.getElementById('dfn-cookie-banner-overlay');
            this.banner     = document.getElementById('dfn-cookie-banner');
            this.manageLink = document.getElementById('dfn-cookie-manage-link');

            if (!this.overlay || !this.banner) return;

            this.bindEvents();

            if (!CookieUtil.isTrue(CONSENT_KEYS.banner_seen)) {
                // Prima visita: mostra il banner dopo breve ritardo
                var self = this;
                setTimeout(function () { self.show(); }, 600);
            } else {
                // Banner già visto in precedenza
                this.showManageLink();
                ScriptManager.applyConsentOnLoad();
                this.syncTogglesFromCookies();
            }
        },

        show: function () {
            if (!this.overlay || !this.banner) return;
            this.overlay.classList.add('dfn-banner-visible');
            
            // Verifica di sicurezza: se l'overlay è nascosto via CSS (es. display: none o pointer-events: none), non bloccare lo scroll!
            var postStyle = window.getComputedStyle(this.overlay);
            if (postStyle.display === 'none' || postStyle.pointerEvents === 'none') {
                document.body.style.overflow = '';
            } else {
                document.body.style.overflow = 'hidden';
            }
        },

        hide: function () {
            if (this.overlay) {
                this.overlay.classList.remove('dfn-banner-visible');
            }
            document.body.style.overflow = '';
            this.showManageLink();
        },

        showManageLink: function () {
            if (this.manageLink) {
                this.manageLink.classList.add('visible');
            }
        },

        /**
         * Legge i toggle e salva le preferenze nei cookie.
         */
        savePreferences: function () {
            var analyticsToggle = document.getElementById('dfn-toggle-analytics');
            var analyticsOn     = analyticsToggle ? analyticsToggle.checked : false;

            CookieUtil.set(CONSENT_KEYS.technical,   true,        COOKIE_DURATION);
            CookieUtil.set(CONSENT_KEYS.analytics,   analyticsOn, COOKIE_DURATION);
            CookieUtil.set(CONSENT_KEYS.banner_seen, true,        COOKIE_DURATION);

            if (analyticsOn) ScriptManager.enableAnalytics();

            this.hide();
            this.updateCategoryUI();
        },

        /** Accetta tutti i cookie. */
        acceptAll: function () {
            var analyticsToggle = document.getElementById('dfn-toggle-analytics');
            if (analyticsToggle) analyticsToggle.checked = true;
            this.savePreferences();
        },

        /** Rifiuta tutti i cookie opzionali (solo tecnici). */
        rejectAll: function () {
            var analyticsToggle = document.getElementById('dfn-toggle-analytics');
            if (analyticsToggle) analyticsToggle.checked = false;
            this.savePreferences();
        },

        /**
         * Sincronizza i toggle con i cookie salvati.
         */
        syncTogglesFromCookies: function () {
            var analyticsToggle = document.getElementById('dfn-toggle-analytics');
            if (analyticsToggle) analyticsToggle.checked = CookieUtil.isTrue(CONSENT_KEYS.analytics);
            this.updateCategoryUI();
        },

        /**
         * Aggiorna l'aspetto visivo delle categorie in base allo stato toggle.
         */
        updateCategoryUI: function () {
            var categories = document.querySelectorAll('.dfn-cookie-category[data-category]');
            categories.forEach(function (cat) {
                var key    = cat.getAttribute('data-category');
                var toggle = document.getElementById('dfn-toggle-' + key);
                if (!toggle) return;
                if (toggle.checked || toggle.disabled) {
                    cat.classList.add('active');
                } else {
                    cat.classList.remove('active');
                }
            });
        },

        bindEvents: function () {
            var self = this;

            var btnAcceptAll = document.getElementById('dfn-cookie-accept-all');
            if (btnAcceptAll) {
                btnAcceptAll.addEventListener('click', function () { self.acceptAll(); });
            }

            var btnSave = document.getElementById('dfn-cookie-save');
            if (btnSave) {
                btnSave.addEventListener('click', function () { self.savePreferences(); });
            }

            var btnReject = document.getElementById('dfn-cookie-reject-all');
            if (btnReject) {
                btnReject.addEventListener('click', function () { self.rejectAll(); });
            }

            if (this.manageLink) {
                this.manageLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    self.syncTogglesFromCookies();
                    self.show();
                });
            }

            // Aggiorna UI al cambio di qualsiasi toggle
            var toggles = document.querySelectorAll('.dfn-cookie-toggle input');
            toggles.forEach(function (toggle) {
                toggle.addEventListener('change', function () { self.updateCategoryUI(); });
            });
        }
    };

    /* ============================================================
       PRIVACY CHECKBOX — VALIDAZIONE FORM
       ============================================================ */
    var PrivacyConsent = {
        init: function () {
            // Intercetta il submit di tutti i form che contengono la checkbox GDPR
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form || form.tagName !== 'FORM') return;

                var checkbox = form.querySelector('.dfn-privacy-checkbox');
                if (!checkbox) return; // Form senza checkbox privacy → non bloccare

                if (!checkbox.checked) {
                    e.preventDefault();
                    e.stopPropagation();
                    PrivacyConsent.showError(checkbox);
                    return false;
                }

                PrivacyConsent.clearError(checkbox);
            }, true);

            // Espone una funzione globale per la validazione pre-AJAX (usata da dfn-slot-selector.js)
            window.dfnCheckPrivacyConsent = function (formElement) {
                if (!formElement) return true;
                var checkbox = formElement.querySelector('.dfn-privacy-checkbox');
                if (!checkbox) return true;
                if (!checkbox.checked) {
                    PrivacyConsent.showError(checkbox);
                    return false;
                }
                PrivacyConsent.clearError(checkbox);
                return true;
            };
        },

        showError: function (checkbox) {
            var wrapper = checkbox.closest('.dfn-privacy-consent-wrapper');
            if (wrapper) {
                wrapper.classList.add('dfn-privacy-error');
                setTimeout(function () {
                    wrapper.classList.remove('dfn-privacy-error');
                }, 600);
            }

            var block = checkbox.closest('.dfn-privacy-consent-block');
            if (block) {
                var msg = block.querySelector('.dfn-privacy-error-msg');
                if (msg) msg.classList.add('visible');
            }

            // Scroll alla checkbox se fuori viewport
            var rect = checkbox.getBoundingClientRect();
            if (rect.top < 0 || rect.bottom > window.innerHeight) {
                checkbox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        },

        clearError: function (checkbox) {
            var block = checkbox.closest('.dfn-privacy-consent-block');
            if (block) {
                var msg = block.querySelector('.dfn-privacy-error-msg');
                if (msg) msg.classList.remove('visible');
            }
        }
    };

    /* ============================================================
       PATCH PER dfn-slot-selector.js (Submit via pulsante)
       ============================================================ */
    var SlotSelectorPatch = {
        init: function () {
            // Intercetta il click sul pulsante submit del wizard di prenotazione
            // prima che dfn-slot-selector.js gestisca il submit AJAX
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.dfn-widget-submit[type="submit"]');
                if (!btn) return;

                var form = btn.closest('form');
                if (!form) return;

                var checkbox = form.querySelector('.dfn-privacy-checkbox');
                if (!checkbox) return;

                if (!checkbox.checked) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    PrivacyConsent.showError(checkbox);
                }
            }, true);
        }
    };

    /* ============================================================
       PANNELLO PREFERENZE INLINE (per la pagina Cookie Policy)
       ============================================================ */
    var InlinePreferences = {

        wrap: null,

        init: function () {
            this.wrap = document.getElementById('dfn-inline-cookie-prefs');
            if (!this.wrap) return; // Non siamo nella pagina cookie policy

            this.syncToggles();
            this.bindEvents();
            this.updateStatusBadges();
        },

        /** Legge i cookie salvati e imposta i toggle di conseguenza. */
        syncToggles: function () {
            var analyticsToggle = this.wrap.querySelector('#dfn-icp-toggle-analytics');
            if (analyticsToggle) {
                analyticsToggle.checked = CookieUtil.isTrue(CONSENT_KEYS.analytics);
            }
            this.updateStatusBadges();
        },

        /** Aggiorna i badge di stato (Attivo / Non attivo) per ogni categoria. */
        updateStatusBadges: function () {
            var self = this;
            var rows = self.wrap.querySelectorAll('.dfn-icp-row[data-category]');
            rows.forEach(function (row) {
                var key    = row.getAttribute('data-category');
                var badge  = row.querySelector('.dfn-icp-status');
                var toggle = row.querySelector('input[type="checkbox"]');
                if (!badge || !toggle) return;

                var isOn = toggle.disabled ? true : toggle.checked;
                badge.textContent    = isOn ? '✅ Attivo' : '⭕ Non attivo';
                badge.className      = 'dfn-icp-status ' + (isOn ? 'dfn-icp-status-on' : 'dfn-icp-status-off');
            });
        },

        /** Salva le preferenze e mostra feedback. */
        save: function () {
            var analyticsToggle = this.wrap.querySelector('#dfn-icp-toggle-analytics');
            var analyticsOn     = analyticsToggle ? analyticsToggle.checked : false;

            CookieUtil.set(CONSENT_KEYS.technical,   true,        COOKIE_DURATION);
            CookieUtil.set(CONSENT_KEYS.analytics,   analyticsOn, COOKIE_DURATION);
            CookieUtil.set(CONSENT_KEYS.banner_seen, true,        COOKIE_DURATION);

            if (analyticsOn) ScriptManager.enableAnalytics();

            this.updateStatusBadges();

            // Sincronizza anche il banner (se presente)
            CookieBanner.syncTogglesFromCookies && CookieBanner.syncTogglesFromCookies();

            // Mostra conferma
            var feedback = this.wrap.querySelector('#dfn-icp-feedback');
            if (feedback) {
                feedback.style.display = 'flex';
                setTimeout(function () { feedback.style.display = 'none'; }, 3500);
            }
        },

        bindEvents: function () {
            var self = this;

            var btnSave = this.wrap.querySelector('#dfn-icp-btn-save');
            if (btnSave) btnSave.addEventListener('click', function () { self.save(); });

            var btnAcceptAll = this.wrap.querySelector('#dfn-icp-btn-accept-all');
            if (btnAcceptAll) {
                btnAcceptAll.addEventListener('click', function () {
                    var analyticsToggle = self.wrap.querySelector('#dfn-icp-toggle-analytics');
                    if (analyticsToggle) analyticsToggle.checked = true;
                    self.save();
                });
            }

            var btnRejectAll = this.wrap.querySelector('#dfn-icp-btn-reject-all');
            if (btnRejectAll) {
                btnRejectAll.addEventListener('click', function () {
                    var analyticsToggle = self.wrap.querySelector('#dfn-icp-toggle-analytics');
                    if (analyticsToggle) analyticsToggle.checked = false;
                    self.save();
                });
            }

            // Aggiorna badge in tempo reale al cambio toggle
            var toggles = this.wrap.querySelectorAll('input[type="checkbox"]:not(:disabled)');
            toggles.forEach(function (t) {
                t.addEventListener('change', function () { self.updateStatusBadges(); });
            });
        }
    };

    /* ============================================================
       INIT
       ============================================================ */
    function init() {
        CookieBanner.init();
        PrivacyConsent.init();
        SlotSelectorPatch.init();
        InlinePreferences.init();
        CookieBanner.updateCategoryUI();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

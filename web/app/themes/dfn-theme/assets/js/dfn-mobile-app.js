/**
 * DFN Mobile App & PWA Hub — JavaScript Controller
 * 
 * Gestisce la navigazione a schede (TabBar), il Service Worker PWA,
 * le azioni rapide AJAX sulla Dashboard, lo Scanner QR Live e il Botteghino.
 *
 * @package DFN_Theme
 * @since   2.1.0
 */

document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('dfn-mobile-app-root');
    if (!root) return;

    // Recupero Nonces e Config
    let nonces = {};
    try {
        nonces = JSON.parse(root.getAttribute('data-nonces') || '{}');
    } catch (e) {
        console.error('Errore parsing nonces:', e);
    }

    const ajaxUrl = typeof dfn_mobile_params !== 'undefined' ? dfn_mobile_params.ajax_url : '/wp-admin/admin-ajax.php';

    // -------------------------------------------------------------------
    // 0. GESTIONE TEMA GRAFICO (LIGHT DEFAULT / DARK)
    // -------------------------------------------------------------------
    const savedTheme = localStorage.getItem('dfn_mobile_theme') || 'light';
    root.setAttribute('data-theme', savedTheme);

    const themeSelect = document.getElementById('dfn-theme-toggle-select');
    if (themeSelect) {
        themeSelect.value = savedTheme;
        themeSelect.addEventListener('change', function () {
            const chosen = this.value;
            root.setAttribute('data-theme', chosen);
            localStorage.setItem('dfn_mobile_theme', chosen);
            showToast(chosen === 'dark' ? '🌙 Tema Scuro Attivato' : '☀️ Tema Chiaro Attivato', 'info');
        });
    }

    // -------------------------------------------------------------------
    // 1. REGISTRAZIONE SERVICE WORKER PWA & PROMPT INSTALLAZIONE
    // -------------------------------------------------------------------
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/app/themes/dfn-theme/sw.js')
            .then(reg => console.log('Service Worker DFN PWA registrato:', reg.scope))
            .catch(err => console.warn('Registrazione Service Worker fallita:', err));
    }

    let deferredPrompt = null;
    const installBtn = document.getElementById('dfn-pwa-install-btn');

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPrompt = e;
        if (installBtn) {
            installBtn.style.display = 'inline-flex';
        }
    });

    if (installBtn) {
        installBtn.addEventListener('click', () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((choiceResult) => {
                if (choiceResult.outcome === 'accepted') {
                    showToast('App installata con successo!', 'success');
                }
                deferredPrompt = null;
                installBtn.style.display = 'none';
            });
        });
    }

    // -------------------------------------------------------------------
    // 2. NAVIGAZIONE TABBAR MOBILE
    // -------------------------------------------------------------------
    const tabBtns = document.querySelectorAll('.dfn-tab-btn');
    const tabPanes = document.querySelectorAll('.dfn-mobile-tab-pane');

    function switchTab(tabId) {
        tabBtns.forEach(btn => {
            if (btn.getAttribute('data-tab') === tabId) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        tabPanes.forEach(pane => {
            if (pane.id === 'dfn-tab-' + tabId) {
                pane.classList.add('active');
            } else {
                pane.classList.remove('active');
            }
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });

        // Inizializza videocamera se si passa al tab scanner
        if (tabId === 'scanner') {
            initScannerCamera();
        } else {
            stopScannerCamera();
        }
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const targetTab = this.getAttribute('data-tab');
            switchTab(targetTab);
            vibrate(30);
        });
    });

    // Navigazione rapida tramite pulsanti interni (es. profil o pillole)
    document.querySelectorAll('[data-target-tab]').forEach(elem => {
        elem.addEventListener('click', function () {
            const targetTab = this.getAttribute('data-target-tab');
            const scrollToId = this.getAttribute('data-scroll-to');
            switchTab(targetTab);

            if (scrollToId) {
                setTimeout(() => {
                    const el = document.getElementById(scrollToId);
                    if (el) el.scrollIntoView({ behavior: 'smooth' });
                }, 200);
            }
        });
    });

    // -------------------------------------------------------------------
    // 3. UTILITY TOAST & VIBRAZIONE
    // -------------------------------------------------------------------
    function showToast(message, type = 'info') {
        const toast = document.getElementById('dfn-mobile-toast');
        if (!toast) return;

        toast.textContent = message;
        toast.style.borderColor = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#38bdf8');
        toast.style.display = 'block';

        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }

    function vibrate(pattern = 50) {
        if ('vibrate' in navigator) {
            try { navigator.vibrate(pattern); } catch (e) {}
        }
    }

    // Refresh Button
    const refreshBtn = document.getElementById('dfn-mobile-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            vibrate(40);
            showToast('Aggiornamento dati in corso...', 'info');
            setTimeout(() => window.location.reload(), 500);
        });
    }

    // -------------------------------------------------------------------
    // 4. AZIONI DASHBOARD HOME (AJAX 1-TAP)
    // -------------------------------------------------------------------

    // A. Conferma Prenotazione Pendente
    document.querySelectorAll('.btn-confirm-booking').forEach(btn => {
        btn.addEventListener('click', function () {
            const bookingId = this.getAttribute('data-booking-id');
            const cardItem = document.getElementById('dfn-booking-card-' + bookingId);

            if (!confirm('Vuoi confermare definitivamente questa prenotazione?')) return;

            btn.disabled = true;
            btn.textContent = 'Conferma in corso...';

            const formData = new FormData();
            formData.append('action', 'dfn_confirm_booking');
            formData.append('booking_id', bookingId);
            formData.append('nonce', nonces.booking || '');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        vibrate([50, 50, 100]);
                        showToast('✅ Prenotazione confermata!', 'success');
                        if (cardItem) {
                            cardItem.style.opacity = '0';
                            setTimeout(() => cardItem.remove(), 300);
                        }
                    } else {
                        showToast('⚠️ Errore: ' + (res.data || 'Impossibile confermare'), 'error');
                        btn.disabled = false;
                        btn.textContent = '✅ Conferma subito';
                    }
                })
                .catch(() => {
                    showToast('⚠️ Errore di connessione', 'error');
                    btn.disabled = false;
                    btn.textContent = '✅ Conferma subito';
                });
        });
    });

    // B. Valida Tessera FAI al volo
    document.querySelectorAll('.btn-validate-fai').forEach(btn => {
        btn.addEventListener('click', function () {
            const faiId = this.getAttribute('data-fai-id');
            const cardItem = document.getElementById('dfn-fai-card-' + faiId);

            btn.disabled = true;
            btn.textContent = 'Validazione...';

            const formData = new FormData();
            formData.append('action', 'dfn_verify_fai_member');
            formData.append('member_id', faiId);
            formData.append('nonce', nonces.fai || nonces.admin || '');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        vibrate([100, 50, 100]);
                        showToast('🪪 Tessera FAI verificata!', 'success');
                        if (cardItem) {
                            cardItem.style.opacity = '0';
                            setTimeout(() => cardItem.remove(), 300);
                        }
                    } else {
                        showToast('⚠️ Errore: ' + (res.data || 'Impossibile verificare'), 'error');
                        btn.disabled = false;
                        btn.textContent = '🪪 Valida Tessera';
                    }
                })
                .catch(() => {
                    showToast('⚠️ Errore di connessione', 'error');
                    btn.disabled = false;
                    btn.textContent = '🪪 Valida Tessera';
                });
        });
    });

    // C. Collegamento Evento → Inserimento Rapido & Botteghino
    document.querySelectorAll('.btn-quick-book-event').forEach(btn => {
        btn.addEventListener('click', function () {
            const eventId = this.getAttribute('data-event-id');
            const select = document.getElementById('dfn-qb-event');
            if (select) select.value = eventId;
            switchTab('quick');
        });
    });

    document.querySelectorAll('.btn-botteghino-event').forEach(btn => {
        btn.addEventListener('click', function () {
            const eventId = this.getAttribute('data-event-id');
            const select = document.getElementById('dfn-bot-event');
            if (select) select.value = eventId;
            switchTab('botteghino');
        });
    });

    // -------------------------------------------------------------------
    // 5. INSERIMENTO RAPIDO & BOTTEGHINO SUBMIT
    // -------------------------------------------------------------------
    const quickForm = document.getElementById('dfn-mobile-quick-booking-form');
    if (quickForm) {
        quickForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Salvataggio in corso...';

            const formData = new FormData(this);
            formData.append('action', 'dfn_process_quick_booking');
            formData.append('nonce', nonces.booking || nonces.admin || '');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        vibrate([100, 50, 100]);
                        showToast('⚡ Prenotazione creata con successo!', 'success');
                        quickForm.reset();
                        switchTab('home');
                    } else {
                        showToast('⚠️ Errore: ' + (res.data || 'Impossibile registrare'), 'error');
                    }
                    submitBtn.disabled = false;
                    submitBtn.textContent = '✅ Salva e Conferma Prenotazione';
                })
                .catch(() => {
                    showToast('⚠️ Errore di rete', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = '✅ Salva e Conferma Prenotazione';
                });
        });
    }

    const botteghinoForm = document.getElementById('dfn-mobile-botteghino-form');
    if (botteghinoForm) {
        botteghinoForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Emissione biglietto...';

            const formData = new FormData(this);
            formData.append('action', 'dfn_process_quick_booking');
            formData.append('nonce', nonces.booking || nonces.admin || '');
            formData.append('is_botteghino', '1');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        vibrate([100, 100, 100]);
                        showToast('💶 Biglietto emesso e incasso registrato!', 'success');
                        botteghinoForm.reset();
                        switchTab('home');
                    } else {
                        showToast('⚠️ Errore: ' + (res.data || 'Impossibile completare incasso'), 'error');
                    }
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💶 Emetti Biglietto & Registra Incasso';
                })
                .catch(() => {
                    showToast('⚠️ Errore di rete', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💶 Emetti Biglietto & Registra Incasso';
                });
        });
    }

    // -------------------------------------------------------------------
    // 6. SCANNER LIVE CONTROLLER
    // -------------------------------------------------------------------
    let mediaStream = null;

    function initScannerCamera() {
        const video = document.getElementById('dfn-mobile-scanner-video');
        if (!video || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                mediaStream = stream;
                video.srcObject = stream;
                video.play();
            })
            .catch(err => {
                console.warn('Videocamera non disponibile o permessi negati:', err);
                showToast('Videocamera non disponibile. Usa il codice manuale.', 'info');
            });
    }

    function stopScannerCamera() {
        if (mediaStream) {
            mediaStream.getTracks().forEach(track => track.stop());
            mediaStream = null;
        }
    }

    // Verification Manual Form
    const manualBtn = document.getElementById('dfn-btn-submit-manual-qr');
    const manualInput = document.getElementById('dfn-scanner-manual-input');
    const resultBox = document.getElementById('dfn-scanner-result-box');

    function checkInToken(token) {
        if (!token) return;

        if (resultBox) {
            resultBox.style.display = 'block';
            resultBox.className = 'dfn-scanner-result-box';
            resultBox.innerHTML = '⏳ Verifica in corso...';
        }

        const formData = new FormData();
        formData.append('action', 'dfn_scanner_checkin');
        formData.append('qr_token', token);
        formData.append('nonce', nonces.scanner || '');

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    vibrate([100, 50, 100]);
                    resultBox.className = 'dfn-scanner-result-box success';
                    resultBox.innerHTML = '✅ <strong>CHECK-IN EFFETTUATO!</strong><br>' + (res.data.customer_name || 'Biglietto Valido') + ' — ' + (res.data.event_title || '');
                    showToast('✅ Check-in confermato!', 'success');
                    if (manualInput) manualInput.value = '';
                } else {
                    vibrate([200, 100, 200]);
                    resultBox.className = 'dfn-scanner-result-box error';
                    resultBox.innerHTML = '❌ <strong>ERRORE CHECK-IN</strong><br>' + (res.data || 'Codice non valido o già utilizzato.');
                    showToast('❌ Codice non valido', 'error');
                }
            })
            .catch(() => {
                resultBox.className = 'dfn-scanner-result-box error';
                resultBox.innerHTML = '⚠️ Errore di connessione.';
            });
    }

    if (manualBtn && manualInput) {
        manualBtn.addEventListener('click', () => {
            checkInToken(manualInput.value.trim());
        });
        manualInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                checkInToken(manualInput.value.trim());
            }
        });
    }
});

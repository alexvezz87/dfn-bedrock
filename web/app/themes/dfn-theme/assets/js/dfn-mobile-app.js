/**
 * DFN Mobile App & PWA Hub — JavaScript Controller
 * 
 * Gestisce la navigazione a schede (TabBar), il Service Worker PWA,
 * le azioni rapide AJAX sulla Dashboard, il Check-in mobile per evento,
 * l'invio email del biglietto e lo Scanner QR Code Automatico con Html5Qrcode.
 *
 * @package DFN_Theme
 * @since   2.1.5
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
    // 2. UTILITY FEEDBACK (VIBRAZIONE & TOAST)
    // -------------------------------------------------------------------
    function showToast(message, type = 'info') {
        const toast = document.getElementById('dfn-mobile-toast');
        if (!toast) return;

        toast.textContent = message;
        toast.style.borderColor = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#0284c7');
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
            setTimeout(() => window.location.reload(), 400);
        });
    }

    // -------------------------------------------------------------------
    // 3. NAVIGAZIONE TABBAR MOBILE
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

        if (tabId === 'scanner') {
            startHtml5Scanner();
        } else {
            stopHtml5Scanner();
        }
    }

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function () {
            const targetTab = this.getAttribute('data-tab');
            switchTab(targetTab);
            vibrate(30);
        });
    });

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
    // 4. SCANNER QR CODE AUTOMATICO (Html5Qrcode Engine)
    // -------------------------------------------------------------------
    let html5QrScanner = null;
    let isScanningActive = false;
    let isProcessingScan = false;

    function startHtml5Scanner() {
        if (isScanningActive || typeof Html5Qrcode === 'undefined') return;

        const readerElem = document.getElementById('dfn-mobile-qr-reader');
        if (!readerElem) return;

        try {
            html5QrScanner = new Html5Qrcode("dfn-mobile-qr-reader");
            isScanningActive = true;

            html5QrScanner.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: { width: 280, height: 280 } },
                onQrScanSuccess,
                onQrScanError
            ).catch(err => {
                console.warn('Errore avvio fotocamera scanner:', err);
                showToast('Impossibile accedere alla fotocamera.', 'info');
                isScanningActive = false;
            });
        } catch (e) {
            console.error('Errore inizializzazione Html5Qrcode:', e);
        }
    }

    function stopHtml5Scanner() {
        if (html5QrScanner && isScanningActive) {
            html5QrScanner.stop().then(() => {
                html5QrScanner.clear();
                isScanningActive = false;
            }).catch(err => {
                isScanningActive = false;
            });
        }
    }

    function onQrScanSuccess(decodedText, decodedResult) {
        if (isProcessingScan) return;
        isProcessingScan = true;

        vibrate([100, 50, 100]);
        checkInToken(decodedText, function() {
            setTimeout(() => {
                isProcessingScan = false;
            }, 2500);
        });
    }

    function onQrScanError(errorMessage) {
        // Ignora gli errori di frame intermedi senza QR
    }

    function checkInToken(token, callback) {
        if (!token) return;

        const resultBox = document.getElementById('dfn-scanner-result-box');
        if (resultBox) {
            resultBox.style.display = 'block';
            resultBox.className = 'dfn-scanner-result-box';
            resultBox.innerHTML = '<div style="text-align:center; padding:12px;">⏳ <strong>Verifica in corso...</strong></div>';
        }

        const formData = new FormData();
        formData.append('action', 'dfn_process_scan');
        formData.append('qr_token', token);
        formData.append('security', nonces.scanner || '');

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    const data = res.data;

                    // CASO 1: Pagamento in loco richiesto
                    if (data.payment_required) {
                        vibrate([200, 100, 200]);
                        if (resultBox) {
                            resultBox.className = 'dfn-scanner-result-box warning';
                            resultBox.innerHTML = `
                                <div class="dfn-scan-card warning">
                                    <span class="dfn-scan-badge warning">💶 PAGAMENTO IN LOCO RICHIESTO</span>
                                    <h4>${data.customer_name}</h4>
                                    <p>📅 ${data.event_title}</p>
                                    <p>👥 <strong>${data.total_persons} Persone</strong> (Interi: ${data.persons_standard}, FAI: ${data.persons_fai})</p>
                                    <div class="dfn-scan-amount-due">Quota da versare: <strong>${data.amount_due_formatted}</strong></div>
                                    <button type="button" class="dfn-mobile-btn success large btn-collect-in-loco" style="margin-top:10px;">
                                        💶 Incassa & Valida Check-in
                                    </button>
                                </div>
                            `;

                            resultBox.querySelector('.btn-collect-in-loco').addEventListener('click', function() {
                                this.disabled = true;
                                this.textContent = 'Registrazione incasso...';
                                consolidatePayment(token, resultBox);
                            });
                        }
                        showToast('💶 Pagamento in loco necessario', 'info');
                    } 
                    // CASO 2: Già entrato in precedenza
                    else if (data.status === 'checked_in') {
                        vibrate([150, 100, 150]);
                        if (resultBox) {
                            resultBox.className = 'dfn-scanner-result-box info';
                            resultBox.innerHTML = `
                                <div class="dfn-scan-card info">
                                    <span class="dfn-scan-badge info">ℹ️ GIÀ CONVALIDATO</span>
                                    <h4>${data.customer_name}</h4>
                                    <p>👥 ${data.total_persons} Persone</p>
                                    <p>⏰ Entrato il: <strong>${data.checked_in_at}</strong> (${data.checked_in_by || 'Staff'})</p>
                                </div>
                            `;
                        }
                        showToast('ℹ️ Biglietto già validato', 'info');
                    } 
                    // CASO 3: Check-in confermato con successo (pagato online / omaggio)
                    else {
                        vibrate([100, 50, 100]);
                        if (resultBox) {
                            resultBox.className = 'dfn-scanner-result-box success';
                            resultBox.innerHTML = `
                                <div class="dfn-scan-card success">
                                    <span class="dfn-scan-badge success">✅ CHECK-IN VALIDATO!</span>
                                    <h4>${data.customer_name || 'Biglietto Valido'}</h4>
                                    <p>📅 ${data.event_title || ''}</p>
                                    <p>👥 <strong>${data.total_persons || 1} Persone</strong> — Stato: Pagato</p>
                                </div>
                            `;
                        }
                        showToast('✅ Check-in effettuato!', 'success');
                    }
                } else {
                    vibrate([200, 100, 200]);
                    const msg = (res.data && res.data.message) ? res.data.message : (res.data || 'Codice non riconosciuto.');
                    if (resultBox) {
                        resultBox.className = 'dfn-scanner-result-box error';
                        resultBox.innerHTML = `
                            <div class="dfn-scan-card danger">
                                <span class="dfn-scan-badge danger">❌ QR NON VALIDO</span>
                                <p style="margin-top:6px;">${msg}</p>
                            </div>
                        `;
                    }
                    showToast('❌ Codice non valido', 'error');
                }
                if (callback) callback();
            })
            .catch(() => {
                if (resultBox) {
                    resultBox.className = 'dfn-scanner-result-box error';
                    resultBox.innerHTML = '<div class="dfn-scan-card danger">⚠️ Errore di connessione col server.</div>';
                }
                if (callback) callback();
            });
    }

    function consolidatePayment(token, resultBox) {
        const formData = new FormData();
        formData.append('action', 'dfn_consolidate_in_loco_payment');
        formData.append('qr_token', token);
        formData.append('security', nonces.scanner || '');

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    vibrate([100, 50, 100]);
                    if (resultBox) {
                        resultBox.className = 'dfn-scanner-result-box success';
                        resultBox.innerHTML = `
                            <div class="dfn-scan-card success">
                                <span class="dfn-scan-badge success">✅ INCASSO & CHECK-IN COMPLETATI!</span>
                                <h4>${res.data.customer_name || 'Acquirente'}</h4>
                                <p>💶 Pagamento registrato in loco con successo.</p>
                            </div>
                        `;
                    }
                    showToast('✅ Incasso completato!', 'success');
                } else {
                    showToast('⚠️ Errore incasso: ' + (res.data ? res.data.message : ''), 'error');
                }
            });
    }

    // -------------------------------------------------------------------
    // 5. CHECK-IN MOBILE PER EVENTO & MODALE
    // -------------------------------------------------------------------
    const checkinModal = document.getElementById('dfn-mobile-checkin-modal');
    const closeCheckinBtn = document.getElementById('dfn-btn-close-checkin-modal');
    const checkinSearchInput = document.getElementById('dfn-mci-search-input');
    const checkinBookingsList = document.getElementById('dfn-mci-bookings-list');

    let currentEventCheckinData = null;

    if (closeCheckinBtn && checkinModal) {
        closeCheckinBtn.addEventListener('click', () => {
            checkinModal.style.display = 'none';
        });
    }

    document.querySelectorAll('.btn-open-checkin-event').forEach(btn => {
        btn.addEventListener('click', function () {
            const eventId = this.getAttribute('data-event-id');
            openEventCheckinModal(eventId);
        });
    });

    function openEventCheckinModal(eventId) {
        if (!checkinModal) return;

        checkinModal.style.display = 'flex';
        checkinBookingsList.innerHTML = '<p style="text-align:center; padding:20px; color:#64748b;">Caricamento lista prenotazioni...</p>';

        const formData = new FormData();
        formData.append('action', 'dfn_mobile_get_event_checkin_list');
        formData.append('event_id', eventId);
        formData.append('nonce', nonces.admin || '');

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    currentEventCheckinData = res.data;
                    renderCheckinModalData(currentEventCheckinData, '');
                } else {
                    checkinBookingsList.innerHTML = '<p style="text-align:center; padding:20px; color:#ef4444;">Erroe: ' + (res.data || 'Impossibile caricare') + '</p>';
                }
            })
            .catch(() => {
                checkinBookingsList.innerHTML = '<p style="text-align:center; padding:20px; color:#ef4444;">Errore di connessione di rete.</p>';
            });
    }

    function renderCheckinModalData(data, filterQuery = '') {
        document.getElementById('dfn-mci-event-title').textContent = data.event_title;
        document.getElementById('dfn-mci-event-subtitle').textContent = '📅 ' + data.event_date + ' • ⏰ ' + data.event_time;

        document.getElementById('dfn-mci-stat-booked').textContent = data.total_booked;
        document.getElementById('dfn-mci-stat-checked').textContent = data.total_checked_in;
        document.getElementById('dfn-mci-stat-remaining').textContent = data.total_remaining;

        const pct = data.total_booked > 0 ? Math.min(100, Math.round((data.total_checked_in / data.total_booked) * 100)) : 0;
        document.getElementById('dfn-mci-progress-fill').style.width = pct + '%';

        const query = filterQuery.toLowerCase().trim();
        const filtered = data.bookings.filter(b => {
            if (!query) return true;
            return b.customer_name.toLowerCase().includes(query) ||
                   b.customer_email.toLowerCase().includes(query) ||
                   b.customer_phone.toLowerCase().includes(query) ||
                   b.qr_token.toLowerCase().includes(query);
        });

        if (filtered.length === 0) {
            checkinBookingsList.innerHTML = '<p style="text-align:center; padding:20px; color:#64748b;">Nessuna prenotazione trovata.</p>';
            return;
        }

        let html = '';
        filtered.forEach(b => {
            const statusClass = b.checked_in ? 'success' : 'pending';
            const statusLabel = b.checked_in ? '✅ Entrato (' + b.checked_in_time + ')' : '⏳ In Attesa';

            html += `
                <div class="dfn-mobile-card dfn-mci-booking-card" id="dfn-mci-card-${b.id}">
                    <div class="dfn-booking-card-header">
                        <strong class="dfn-customer-name">${b.customer_name}</strong>
                        <span class="dfn-event-status-pill ${statusClass}">${statusLabel}</span>
                    </div>
                    <div class="dfn-booking-details">
                        <p>📧 ${b.customer_email}</p>
                        ${b.customer_phone ? '<p>📞 ' + b.customer_phone + '</p>' : ''}
                        <p>👥 <strong>${b.total_persons} Persone</strong> (Interi: ${b.persons_std}, FAI: ${b.persons_fai})</p>
                    </div>
                    <div class="dfn-booking-actions 2-col" style="display:grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top:10px;">
                        <button type="button" class="dfn-mobile-btn ${b.checked_in ? 'secondary' : 'success'} btn-mci-do-checkin" data-booking-id="${b.id}" data-token="${b.qr_token}">
                            ${b.checked_in ? '✓ Già Entrato' : '✅ Check-in'}
                        </button>
                        <button type="button" class="dfn-mobile-btn secondary btn-mci-resend-email" data-booking-id="${b.id}">
                            ✉️ Invia Email
                        </button>
                    </div>
                </div>
            `;
        });

        checkinBookingsList.innerHTML = html;

        // Binding azioni della modale
        checkinBookingsList.querySelectorAll('.btn-mci-do-checkin').forEach(btn => {
            btn.addEventListener('click', function () {
                const token = this.getAttribute('data-token');
                btn.disabled = true;
                btn.textContent = 'Check-in...';

                checkInToken(token, function() {
                    btn.textContent = '✓ Già Entrato';
                    btn.className = 'dfn-mobile-btn secondary btn-mci-do-checkin';
                });
            });
        });

        checkinBookingsList.querySelectorAll('.btn-mci-resend-email').forEach(btn => {
            btn.addEventListener('click', function () {
                const bookingId = this.getAttribute('data-booking-id');
                btn.disabled = true;
                btn.textContent = 'Invio email...';

                const formData = new FormData();
                formData.append('action', 'dfn_mobile_resend_ticket_email');
                formData.append('booking_id', bookingId);
                formData.append('nonce', nonces.booking || '');

                fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            vibrate([100, 50, 100]);
                            showToast('✉️ Email inviata al cliente!', 'success');
                        } else {
                            showToast('⚠️ Errore: ' + (res.data || 'Impossibile inviare'), 'error');
                        }
                        btn.disabled = false;
                        btn.textContent = '✉️ Invia Email';
                    })
                    .catch(() => {
                        showToast('⚠️ Errore di connessione', 'error');
                        btn.disabled = false;
                        btn.textContent = '✉️ Invia Email';
                    });
            });
        });
    }

    if (checkinSearchInput) {
        checkinSearchInput.addEventListener('input', function () {
            if (currentEventCheckinData) {
                renderCheckinModalData(currentEventCheckinData, this.value);
            }
        });
    }

    // -------------------------------------------------------------------
    // 6. AZIONI DASHBOARD HOME (AZIONI RAPIDE 1-TAP)
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
    // 7. INSERIMENTO RAPIDO & BOTTEGHINO SUBMIT
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
});

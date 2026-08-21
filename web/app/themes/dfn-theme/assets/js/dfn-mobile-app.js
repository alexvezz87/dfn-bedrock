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

    // -------------------------------------------------------------------
    // 00. NASCONDIMENTO BADGE GRAFICI SU MOBILE GESTIONE EVENTI (Senza bloccare reCAPTCHA)
    // -------------------------------------------------------------------
    function hideIntrusiveBadges() {
        const isMobile = window.innerWidth <= 768 || /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        if (!isMobile) return;

        const selectors = [
            '.grecaptcha-badge',
            '#dfn-cookie-manage-link',
            '#dfn-cookie-banner-overlay',
            '#dfn-cookie-banner'
        ];

        selectors.forEach(function (sel) {
            const els = document.querySelectorAll(sel);
            els.forEach(function (el) {
                el.style.setProperty('display', 'none', 'important');
            });
        });
    }

    hideIntrusiveBadges();
    setInterval(hideIntrusiveBadges, 600);

    try {
        const observer = new MutationObserver(hideIntrusiveBadges);
        observer.observe(document.body, { childList: true, subtree: true });
    } catch (e) {}

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
        if (window.location.pathname.indexOf('gestione-eventi') !== -1 || !!document.getElementById('dfn-mobile-app-root')) {
            navigator.serviceWorker.register('/app/themes/dfn-theme/sw.js')
                .then(reg => console.log('Service Worker DFN PWA registrato:', reg.scope))
                .catch(err => console.warn('Registrazione Service Worker fallita:', err));
        } else {
            navigator.serviceWorker.getRegistrations().then(function(registrations) {
                for (let registration of registrations) {
                    registration.unregister();
                }
            });
        }
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

    // Se all'avvio la scheda attiva è lo scanner, avvia subito la fotocamera
    const initialActiveTab = document.querySelector('.dfn-mobile-tab-pane.active');
    if (initialActiveTab && initialActiveTab.id === 'dfn-tab-scanner') {
        setTimeout(startHtml5Scanner, 300);
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
                { fps: 10, qrbox: { width: 200, height: 200 } },
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
    const checkinModal        = document.getElementById('dfn-mobile-checkin-modal');
    const closeCheckinBtn     = document.getElementById('dfn-btn-close-checkin-modal');
    const checkinSearchInput  = document.getElementById('dfn-mci-search-input');
    const checkinBookingsList = document.getElementById('dfn-mci-bookings-list');
    const mciDateWrap         = document.getElementById('dfn-mci-date-wrap');
    const mciDateSelect       = document.getElementById('dfn-mci-date-select');

    let currentEventCheckinData = null;
    let mciCurrentEventId       = 0;

    if (closeCheckinBtn && checkinModal) {
        closeCheckinBtn.addEventListener('click', () => {
            checkinModal.style.display = 'none';
        });
    }

    if (mciDateSelect) {
        mciDateSelect.addEventListener('change', function () {
            if (mciCurrentEventId) {
                openEventCheckinModal(mciCurrentEventId, this.value);
            }
        });
    }

    document.querySelectorAll('.btn-open-checkin-event').forEach(btn => {
        btn.addEventListener('click', function () {
            const eventId = this.getAttribute('data-event-id');
            openEventCheckinModal(eventId, '');
        });
    });

    function openEventCheckinModal(eventId, selectedDate = '') {
        if (!checkinModal) return;

        mciCurrentEventId = eventId;
        checkinModal.style.display = 'flex';
        checkinBookingsList.innerHTML = '<p style="text-align:center; padding:20px; color:#64748b;">Caricamento lista prenotazioni...</p>';

        const formData = new FormData();
        formData.append('action', 'dfn_mobile_get_event_checkin_list');
        formData.append('event_id', eventId);
        formData.append('date', selectedDate);
        formData.append('nonce', nonces.admin || nonces.quick || '');

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    currentEventCheckinData = res.data;
                    renderCheckinModalData(currentEventCheckinData, checkinSearchInput ? checkinSearchInput.value : '', res.data.selected_date || selectedDate);
                } else {
                    checkinBookingsList.innerHTML = '<p style="text-align:center; padding:20px; color:#ef4444;">Errore: ' + (res.data || 'Impossibile caricare') + '</p>';
                }
            })
            .catch(() => {
                checkinBookingsList.innerHTML = '<p style="text-align:center; padding:20px; color:#ef4444;">Errore di connessione di rete.</p>';
            });
    }

    function renderCheckinModalData(data, filterQuery = '', activeDate = '') {
        document.getElementById('dfn-mci-event-title').textContent = data.event_title;
        document.getElementById('dfn-mci-event-subtitle').textContent = '📅 ' + data.event_date + ' • ⏰ ' + data.event_time;

        if (mciDateWrap && mciDateSelect && Array.isArray(data.available_dates) && data.available_dates.length > 0) {
            let optionsHtml = '';
            data.available_dates.forEach(d => {
                const isSel = (d.date === data.selected_date) ? 'selected' : '';
                optionsHtml += `<option value="${d.date}" ${isSel}>📅 ${d.label}</option>`;
            });
            const isAllSel = (data.selected_date === 'all') ? 'selected' : '';
            optionsHtml += `<option value="all" ${isAllSel}>📋 Tutte le date (${data.available_dates.length} date)</option>`;
            mciDateSelect.innerHTML = optionsHtml;
            mciDateWrap.style.display = 'block';
        } else if (mciDateWrap) {
            mciDateWrap.style.display = 'none';
        }

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

    // MODALI AZIONI DETTAGLIO & SPOSTA TURNO
    const mbdModal         = document.getElementById('dfn-mobile-booking-details-modal');
    const closeMbdBtn      = document.getElementById('dfn-btn-close-mbd-modal');
    const mbdBody          = document.getElementById('dfn-mbd-body');
    const moveSlotModal    = document.getElementById('dfn-mobile-move-slot-modal');
    const closeMoveSlotBtn = document.getElementById('dfn-btn-close-move-slot-modal');
    const cancelMoveBtn    = document.getElementById('dfn-btn-cancel-move-slot');
    const moveSlotForm     = document.getElementById('dfn-mobile-move-slot-form');

    if (closeMbdBtn && mbdModal) {
        closeMbdBtn.addEventListener('click', () => { mbdModal.style.display = 'none'; });
    }
    if (closeMoveSlotBtn && moveSlotModal) {
        closeMoveSlotBtn.addEventListener('click', () => { moveSlotModal.style.display = 'none'; });
    }
    if (cancelMoveBtn && moveSlotModal) {
        cancelMoveBtn.addEventListener('click', () => { moveSlotModal.style.display = 'none'; });
    }

    let activeBookingDetails = null;

    function openBookingDetailsModal(bookingId) {
        if (! mbdModal || ! mbdBody) return;
        mbdModal.style.display = 'flex';
        mbdBody.innerHTML = '<p style="text-align:center; padding:20px; color:#64748b;">Caricamento dettagli in corso...</p>';

        const fd = new FormData();
        fd.append('action', 'dfn_mobile_get_booking_details');
        fd.append('booking_id', bookingId);
        fd.append('nonce', nonces.admin || nonces.quick || nonces.booking || '');

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    activeBookingDetails = res.data;
                    renderBookingDetailsBody(activeBookingDetails);
                } else {
                    mbdBody.innerHTML = '<p style="text-align:center; color:#ef4444; padding:20px;">Errore: ' + (res.data || 'Impossibile caricare') + '</p>';
                }
            })
            .catch(() => {
                mbdBody.innerHTML = '<p style="text-align:center; color:#ef4444; padding:20px;">Errore di connessione.</p>';
            });
    }

    function renderBookingDetailsBody(b) {
        document.getElementById('dfn-mbd-title').textContent = 'Gestione Prenotazione #' + b.id;

        const isCancelled = b.status === 'cancelled';
        const statusBadgeClass = isCancelled ? 'danger' : (b.checked_in ? 'success' : 'pending');

        let html = `
            <div style="background:#f8fafc; border:1px solid #e2e8f0; padding:14px; border-radius:10px; margin-bottom:14px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <h4 style="margin:0; font-size:16px; color:#0f172a;">${b.customer_name}</h4>
                    <span class="dfn-event-status-pill ${statusBadgeClass}">${b.status_label}</span>
                </div>
                <p style="margin:3px 0; font-size:13px; color:#475569;">📧 <strong>Email:</strong> ${b.customer_email}</p>
                <p style="margin:3px 0; font-size:13px; color:#475569;">📞 <strong>Telefono:</strong> ${b.customer_phone}</p>
                <p style="margin:3px 0; font-size:13px; color:#475569;">🧾 <strong>Ordine WC:</strong> #${b.order_id || 'N/D'} (${b.created_at})</p>
                <p style="margin:3px 0; font-size:13px; color:#475569;">💳 <strong>Pagamento:</strong> ${b.payment_status} <small>(${b.payment_method})</small></p>
                <p style="margin:3px 0; font-size:13px; color:#475569;">👥 <strong>Persone:</strong> ${b.total_persons} (Interi: ${b.persons_std}, FAI: ${b.persons_fai})</p>
                <p style="margin:3px 0; font-size:13px; color:#2563eb;">📅 <strong>Turno Assegnato:</strong> ${b.current_slot_info}</p>
                ${b.notes && b.notes !== 'Nessuna nota richiesta.' ? '<div style="margin-top:8px; padding:8px; background:#fffbeb; border:1px solid #fef3c7; border-radius:6px; font-size:12px; color:#92400e;">💬 <strong>Note:</strong> ' + b.notes + '</div>' : ''}
            </div>

            <!-- GRIGLIA AZIONI OPERATIVE -->
            <div style="display:flex; flex-direction:column; gap:10px;">
                ${! isCancelled ? `
                    <button type="button" class="dfn-mobile-btn primary large" id="dfn-btn-mbd-move" style="justify-content:center; text-align:center;">
                        ✏️ Sposta Turno / Data
                    </button>
                ` : ''}

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px;">
                    <button type="button" class="dfn-mobile-btn secondary" id="dfn-btn-mbd-resend">
                        📧 Reinvia Email
                    </button>
                    <button type="button" class="dfn-mobile-btn secondary" id="dfn-btn-mbd-log">
                        📜 Log Storico
                    </button>
                </div>

                ${! isCancelled ? `
                    <button type="button" class="dfn-mobile-btn danger large" id="dfn-btn-mbd-cancel" style="margin-top:6px; justify-content:center; text-align:center;">
                        ❌ Annulla Prenotazione
                    </button>
                ` : ''}
            </div>
        `;

        mbdBody.innerHTML = html;

        // Listener Sposta Turno
        const moveBtn = document.getElementById('dfn-btn-mbd-move');
        if (moveBtn) {
            moveBtn.addEventListener('click', () => {
                openMoveSlotModal(b);
            });
        }

        // Listener Reinvia Email
        const resendBtn = document.getElementById('dfn-btn-mbd-resend');
        if (resendBtn) {
            resendBtn.addEventListener('click', () => {
                resendBtn.disabled = true;
                resendBtn.textContent = 'Invio...';
                const fd = new FormData();
                fd.append('action', 'dfn_mobile_resend_ticket_email');
                fd.append('booking_id', b.id);
                fd.append('nonce', nonces.booking || '');
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        resendBtn.disabled = false;
                        resendBtn.textContent = '📧 Reinvia Email';
                        if (res.success) { showToast('✉️ Email inviata!', 'success'); }
                        else { showToast('⚠️ Errore: ' + (res.data || 'Impossibile inviare'), 'error'); }
                    });
            });
        }

    // MODALE LOG STORICO
    const historyModal       = document.getElementById('dfn-mobile-history-modal');
    const closeHistoryBtn    = document.getElementById('dfn-btn-close-history-modal');
    const closeHistoryBottom = document.getElementById('dfn-btn-close-history-bottom');
    const historyContentArea = document.getElementById('dfn-history-list-content');
    const historySubtitle    = document.getElementById('dfn-history-customer-subtitle');

    if (closeHistoryBtn && historyModal) {
        closeHistoryBtn.addEventListener('click', () => { historyModal.style.display = 'none'; });
    }
    if (closeHistoryBottom && historyModal) {
        closeHistoryBottom.addEventListener('click', () => { historyModal.style.display = 'none'; });
    }

    function openMobileHistoryModal(b) {
        if (! historyModal || ! historyContentArea) return;
        historyModal.style.display = 'flex';
        if (historySubtitle) {
            historySubtitle.textContent = b.customer_name + ' (Ordine #' + (b.order_id || 'N/D') + ')';
        }

        if (Array.isArray(b.history_logs) && b.history_logs.length > 0) {
            let logHtml = '';
            b.history_logs.forEach(item => {
                logHtml += `<div class="cv-history-item" style="border-bottom:1px solid #cbd5e1; padding:8px 0; white-space:nowrap;"><span style="color:#64748b; margin-right:12px;">🕒 ${item.time}</span> <strong>${item.action}</strong></div>`;
            });
            historyContentArea.innerHTML = logHtml;
        } else {
            historyContentArea.innerHTML = '<p style="text-align:center; color:#64748b; margin:10px 0;">Nessun intervento registrato per questo ordine.</p>';
        }
    }

    // Listener Log Storico
    const logBtn = document.getElementById('dfn-btn-mbd-log');
    if (logBtn) {
        logBtn.addEventListener('click', () => {
            openMobileHistoryModal(b);
        });
    }

        // Listener Annulla Prenotazione
        const cancelBtn = document.getElementById('dfn-btn-mbd-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                if (! confirm(`Sei sicuro di voler ANNULLARE la prenotazione #${b.id} di ${b.customer_name}?\nI posti verranno subito liberati.`)) {
                    return;
                }
                cancelBtn.disabled = true;
                cancelBtn.textContent = '⏳ Annullamento...';
                const fd = new FormData();
                fd.append('action', 'dfn_mobile_cancel_booking');
                fd.append('booking_id', b.id);
                fd.append('nonce', nonces.admin || nonces.quick || '');
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        cancelBtn.disabled = false;
                        if (res.success) {
                            showToast('✅ Prenotazione annullata.', 'success');
                            if (mbdModal) mbdModal.style.display = 'none';
                            if (mciCurrentEventId && mciDateSelect) {
                                openEventCheckinModal(mciCurrentEventId, mciDateSelect.value);
                            }
                        } else {
                            showToast('⚠️ Errore: ' + (res.data || 'Impossibile annullare'), 'error');
                            cancelBtn.textContent = '❌ Annulla Prenotazione';
                        }
                    });
            });
        }
    }

    function openMoveSlotModal(b) {
        if (! moveSlotModal) return;
        document.getElementById('dfn-move-booking-id').value = b.id;
        document.getElementById('dfn-move-customer-name').textContent = b.customer_name;
        document.getElementById('dfn-move-current-slot').textContent = b.current_slot_info;

        const selectEl = document.getElementById('dfn-move-target-slot-select');
        let optionsHtml = '';

        if (Array.isArray(b.available_slots) && b.available_slots.length > 0) {
            b.available_slots.forEach(s => {
                const isSel = s.is_current ? 'disabled' : '';
                const tag = s.is_current ? ' (Turno Attuale)' : '';
                optionsHtml += `<option value="${s.id}" ${isSel}>${s.label}${tag}</option>`;
            });
        } else {
            optionsHtml = '<option value="">Nessun altro turno disponibile per questo evento</option>';
        }
        selectEl.innerHTML = optionsHtml;
        moveSlotModal.style.display = 'flex';
    }

    if (moveSlotForm) {
        moveSlotForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const bookingId = document.getElementById('dfn-move-booking-id').value;
            const toSlotId  = document.getElementById('dfn-move-target-slot-select').value;
            const notify    = document.getElementById('dfn-move-notify-customer').checked ? '1' : '0';
            const submitBtn = moveSlotForm.querySelector('button[type="submit"]');

            if (! toSlotId) {
                showToast('⚠️ Seleziona un turno di destinazione valido.', 'error');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Spostamento...';

            const fd = new FormData();
            fd.append('action', 'dfn_mobile_move_booking');
            fd.append('booking_id', bookingId);
            fd.append('to_slot_id', toSlotId);
            fd.append('notify', notify);
            fd.append('nonce', nonces.admin || nonces.quick || '');

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💾 Conferma Spostamento';
                    if (res.success) {
                        showToast('✅ Turno spostato con successo!', 'success');
                        if (moveSlotModal) moveSlotModal.style.display = 'none';
                        if (mbdModal) mbdModal.style.display = 'none';
                        if (mciCurrentEventId && mciDateSelect) {
                            openEventCheckinModal(mciCurrentEventId, mciDateSelect.value);
                        }
                    } else {
                        showToast('⚠️ Errore: ' + (res.data || 'Impossibile spostare'), 'error');
                    }
                })
                .catch(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💾 Conferma Spostamento';
                    showToast('⚠️ Errore di rete', 'error');
                });
        });
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
                    <div class="dfn-booking-actions three-col" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; margin-top:10px;">
                        <button type="button" class="dfn-mobile-btn ${b.checked_in ? 'secondary' : 'success'} btn-mci-do-checkin" data-booking-id="${b.id}" data-token="${b.qr_token}">
                            ${b.checked_in ? '✓ Entrato' : '✅ Check-in'}
                        </button>
                        <button type="button" class="dfn-mobile-btn primary btn-mci-manage-details" data-booking-id="${b.id}">
                            ⚙️ Gestisci
                        </button>
                        <button type="button" class="dfn-mobile-btn secondary btn-mci-resend-email" data-booking-id="${b.id}">
                            ✉️ Email
                        </button>
                    </div>
                </div>
            `;
        });

        checkinBookingsList.innerHTML = html;

        // Binding pulsante Gestisci
        checkinBookingsList.querySelectorAll('.btn-mci-manage-details').forEach(btn => {
            btn.addEventListener('click', function () {
                const bookingId = this.getAttribute('data-booking-id');
                openBookingDetailsModal(bookingId);
            });
        });

        // Binding azioni della modale
        checkinBookingsList.querySelectorAll('.btn-mci-do-checkin').forEach(btn => {
            btn.addEventListener('click', function () {
                const bookingId = this.getAttribute('data-booking-id');
                const token = this.getAttribute('data-token');
                btn.disabled = true;
                btn.textContent = '⏳ Registrazione...';

                const fd = new FormData();
                fd.append('action', 'dfn_mobile_do_checkin');
                fd.append('booking_id', bookingId);
                fd.append('qr_token', token || '');
                fd.append('nonce', nonces.admin || nonces.quick || nonces.booking || '');

                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        btn.disabled = false;
                        if (res.success) {
                            if (res.data.checked_in) {
                                vibrate([100, 50, 100]);
                                btn.textContent = '✓ Entrato (' + (res.data.checked_in_time || '') + ')';
                                btn.className = 'dfn-mobile-btn secondary btn-mci-do-checkin';
                                showToast('✅ Check-in confermato!', 'success');
                            } else {
                                vibrate([50]);
                                btn.textContent = '✅ Check-in';
                                btn.className = 'dfn-mobile-btn success btn-mci-do-checkin';
                                showToast('ℹ️ Check-in annullato', 'info');
                            }

                            if (mciCurrentEventId && mciDateSelect) {
                                openEventCheckinModal(mciCurrentEventId, mciDateSelect.value);
                            }
                        } else {
                            showToast('⚠️ Errore: ' + (res.data || 'Operazione fallita'), 'error');
                            btn.textContent = '✅ Check-in';
                        }
                    })
                    .catch(() => {
                        btn.disabled = false;
                        btn.textContent = '✅ Check-in';
                        showToast('⚠️ Errore di connessione', 'error');
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
                        btn.textContent = '✉️ Email';
                    })
                    .catch(() => {
                        showToast('⚠️ Errore di connessione', 'error');
                        btn.disabled = false;
                        btn.textContent = '✉️ Email';
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

    // C. Collegamento Evento → Inserimento Rapido & Botteghino (con event delegation per elementi dinamici)
    document.addEventListener('click', function (e) {
        const quickBtn = e.target.closest('.btn-quick-book-event');
        if (quickBtn) {
            const eventId = quickBtn.getAttribute('data-event-id');
            const select = document.getElementById('dfn-m-qb-event') || document.getElementById('dfn-qb-event');
            if (select) {
                select.value = eventId;
                select.dispatchEvent(new Event('change'));
            }
            switchTab('quick');
            return;
        }

        const botBtn = e.target.closest('.btn-botteghino-event');
        if (botBtn) {
            const eventId = botBtn.getAttribute('data-event-id');
            const select = document.getElementById('dfn-bot-event');
            if (select) {
                select.value = eventId;
                select.dispatchEvent(new Event('change'));
            }
            switchTab('botteghino');
            return;
        }

        const checkinBtn = e.target.closest('.btn-open-checkin-event');
        if (checkinBtn) {
            const eventId = checkinBtn.getAttribute('data-event-id');
            openEventCheckinModal(eventId, '');
            return;
        }
    });

    // -------------------------------------------------------------------
    // 6.1 RICERCA LIVE EVENTI VIA AJAX
    // -------------------------------------------------------------------
    const eventsSearchInput = document.getElementById('dfn-events-search-input');
    const eventsSearchClear = document.getElementById('dfn-events-search-clear');
    const eventsCardsList   = document.getElementById('dfn-mobile-events-cards-list');
    const eventsBadgeCount  = document.getElementById('dfn-events-badge-count');

    let eventsSearchTimeout = null;

    if (eventsSearchInput && eventsCardsList) {
        eventsSearchInput.addEventListener('input', function () {
            const query = this.value.trim();

            if (eventsSearchClear) {
                eventsSearchClear.style.display = query.length > 0 ? 'block' : 'none';
            }

            clearTimeout(eventsSearchTimeout);
            eventsSearchTimeout = setTimeout(() => {
                performEventsSearch(query);
            }, 250);
        });

        if (eventsSearchClear) {
            eventsSearchClear.addEventListener('click', function () {
                eventsSearchInput.value = '';
                eventsSearchClear.style.display = 'none';
                performEventsSearch('');
                eventsSearchInput.focus();
            });
        }
    }

    function performEventsSearch(query) {
        if (! eventsCardsList) return;

        // Feedback visivo immediato di ricerca in corso
        eventsCardsList.style.opacity = '0.5';

        const fd = new FormData();
        fd.append('action', 'dfn_mobile_search_events');
        fd.append('query', query);
        fd.append('nonce', nonces.admin || nonces.quick || nonces.booking || '');

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                eventsCardsList.style.opacity = '1';
                if (res.success && res.data) {
                    renderEventsCards(res.data.events || []);
                    if (eventsBadgeCount) {
                        eventsBadgeCount.textContent = res.data.count || 0;
                    }
                }
            })
            .catch(() => {
                eventsCardsList.style.opacity = '1';
            });
    }

    function renderEventsCards(events) {
        if (! eventsCardsList) return;

        if (! events || events.length === 0) {
            eventsCardsList.innerHTML = `
                <div class="dfn-mobile-empty-state" style="padding: 24px 16px; text-align: center; color: #64748b; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px;">
                    <p style="margin: 0; font-size: 14px;">🔍 Nessun evento trovato con questo termine di ricerca.</p>
                </div>
            `;
            return;
        }

        let html = '';
        events.forEach(ev => {
            const hasButtons = ev.can_quick || ev.can_botteghino || ev.can_checkin;
            html += `
                <div class="dfn-mobile-card dfn-event-card-item">
                    <div class="dfn-event-card-top">
                        <span class="dfn-event-date-badge">📅 ${ev.date_formatted} • ⏰ ${ev.time_formatted}</span>
                        ${ev.is_test ? '<span class="dfn-event-status-pill test" style="background:#dcfce7; color:#15803d; border:1px solid #86efac; font-weight:bold;">🧪 TEST</span>' : '<span class="dfn-event-status-pill open">Aperto</span>'}
                    </div>
                    <h4 class="dfn-event-title">${escapeHtml(ev.title)}</h4>
                    <p class="dfn-event-location">📍 ${escapeHtml(ev.location)}</p>
                    
                    ${hasButtons ? `
                        <div class="dfn-event-card-actions" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px;">
                            ${ev.can_quick ? `
                                <button type="button" class="dfn-mobile-btn primary btn-quick-book-event" data-event-id="${ev.id}" style="flex: 1; min-width: 100px;">
                                    ⚡ Prenota
                                </button>
                            ` : ''}
                            ${ev.can_botteghino ? `
                                <button type="button" class="dfn-mobile-btn secondary btn-botteghino-event" data-event-id="${ev.id}" style="flex: 1; min-width: 100px;">
                                    🎟️ Botteghino
                                </button>
                            ` : ''}
                            ${ev.can_checkin ? `
                                <button type="button" class="dfn-mobile-btn success btn-open-checkin-event" data-event-id="${ev.id}" style="flex: 1; min-width: 100px;">
                                    📋 Check-in
                                </button>
                            ` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
        });

        eventsCardsList.innerHTML = html;
    }

    function escapeHtml(text) {
        if (!text) return '';
        return String(text)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // -------------------------------------------------------------------
    // 7. INSERIMENTO RAPIDO (MOBILE APP) — CASCADE & SUBMIT
    // -------------------------------------------------------------------
    const mQbEventSel    = document.getElementById('dfn-m-qb-event');
    const mQbDateSel     = document.getElementById('dfn-m-qb-date');
    const mQbDateWrap    = document.getElementById('dfn-m-qb-date-wrap');
    const mQbSlotSel     = document.getElementById('dfn-m-qb-slot');
    const mQbSlotWrap    = document.getElementById('dfn-m-qb-slot-wrap');
    const mQbGuestWrap   = document.getElementById('dfn-m-qb-guest-wrap');
    const mQbQtyFaiInput = document.getElementById('dfn-m-qb-qty-fai');
    const mQbFaiCardsWrap= document.getElementById('dfn-m-qb-fai-cards-wrap');
    const mQbFaiCardsList= document.getElementById('dfn-m-qb-fai-cards-list');

    if (mQbEventSel) {
        mQbEventSel.addEventListener('change', function () {
            const evId = parseInt(this.value, 10);
            if (mQbDateWrap) mQbDateWrap.style.display = 'none';
            if (mQbSlotWrap) mQbSlotWrap.style.display = 'none';
            if (mQbGuestWrap) mQbGuestWrap.style.display = 'none';
            if (mQbDateSel) mQbDateSel.innerHTML = '<option value="">— Seleziona una data —</option>';

            if (!evId) return;

            if (mQbDateWrap) mQbDateWrap.style.display = 'block';
            if (mQbDateSel) {
                mQbDateSel.disabled = true;
                mQbDateSel.innerHTML = '<option value="">⏳ Caricamento date…</option>';
            }

            const fd = new FormData();
            fd.append('action', 'dfn_quick_get_dates');
            fd.append('nonce', nonces.quick || nonces.admin || nonces.booking || '');
            fd.append('event_id', evId);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data.dates && res.data.dates.length > 0) {
                        let html = res.data.dates.length > 1 ? '<option value="">— Seleziona una data —</option>' : '';
                        res.data.dates.forEach(d => {
                            const p = d.split('-');
                            const fmt = p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : d;
                            html += `<option value="${d}">${fmt}</option>`;
                        });
                        if (mQbDateSel) {
                            mQbDateSel.innerHTML = html;
                            mQbDateSel.disabled = false;
                            if (res.data.dates.length === 1) {
                                mQbDateSel.value = res.data.dates[0];
                                mQbDateSel.dispatchEvent(new Event('change'));
                            }
                        }
                    } else if (mQbDateSel) {
                        mQbDateSel.innerHTML = '<option value="">Nessuna data disponibile</option>';
                    }
                })
                .catch(() => {
                    if (mQbDateSel) mQbDateSel.innerHTML = '<option value="">Errore caricamento date</option>';
                });
        });
    }

    if (mQbDateSel) {
        mQbDateSel.addEventListener('change', function () {
            const date = this.value;
            const evId = mQbEventSel ? parseInt(mQbEventSel.value, 10) : 0;
            const selectedOpt = mQbEventSel ? mQbEventSel.options[mQbEventSel.selectedIndex] : null;
            const accessType = selectedOpt ? selectedOpt.getAttribute('data-access') : 'time_slots';

            if (mQbSlotWrap) mQbSlotWrap.style.display = 'none';
            if (mQbGuestWrap) mQbGuestWrap.style.display = 'none';

            if (!date || !evId) return;

            if (accessType === 'free_flow') {
                if (mQbGuestWrap) mQbGuestWrap.style.display = 'block';
                return;
            }

            if (mQbSlotWrap) mQbSlotWrap.style.display = 'block';
            if (mQbSlotSel) {
                mQbSlotSel.disabled = true;
                mQbSlotSel.innerHTML = '<option value="0">⏳ Caricamento turni…</option>';
            }

            const fd = new FormData();
            fd.append('action', 'dfn_quick_get_slots');
            fd.append('nonce', nonces.quick || nonces.admin || nonces.booking || '');
            fd.append('event_id', evId);
            fd.append('date', date);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    let html = '<option value="0">🤖 Auto — Smistamento automatico</option>';
                    if (res.success && res.data.slots && res.data.slots.length > 0) {
                        res.data.slots.forEach(s => {
                            const locked = s.is_locked;
                            const avail = s.available;
                            const icon = locked ? '🔒' : (avail === 0 ? '🔴' : avail <= 3 ? '🟡' : '🟢');
                            const label = locked ? `${s.time_start} → ${s.time_end} ${icon} Bloccato` : `${s.time_start} → ${s.time_end} ${icon} ${avail} liberi`;
                            html += `<option value="${s.id}" ${locked || avail === 0 ? 'disabled' : ''}>${label}</option>`;
                        });
                    }
                    if (mQbSlotSel) {
                        mQbSlotSel.innerHTML = html;
                        mQbSlotSel.disabled = false;
                    }
                    if (mQbGuestWrap) mQbGuestWrap.style.display = 'block';
                })
                .catch(() => {
                    if (mQbSlotSel) {
                        mQbSlotSel.innerHTML = '<option value="0">🤖 Auto — Smistamento automatico</option>';
                        mQbSlotSel.disabled = false;
                    }
                    if (mQbGuestWrap) mQbGuestWrap.style.display = 'block';
                });
        });
    }

    if (mQbQtyFaiInput) {
        mQbQtyFaiInput.addEventListener('input', function () {
            const qty = parseInt(this.value, 10) || 0;
            if (!mQbFaiCardsWrap || !mQbFaiCardsList) return;
            if (qty <= 0) {
                mQbFaiCardsWrap.style.display = 'none';
                mQbFaiCardsList.innerHTML = '';
                return;
            }
            mQbFaiCardsWrap.style.display = 'block';
            let html = '';
            for (let i = 0; i < qty; i++) {
                html += `
                    <div class="dfn-m-fai-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px; margin-bottom:8px;">
                        <div style="font-size:12px; font-weight:700; color:#004b23; margin-bottom:6px;">Socio FAI #${i + 1}</div>
                        <div style="display:flex; gap:8px; margin-bottom:6px;">
                            <input type="text" name="fai_cards[${i}][cognome]" placeholder="Cognome" style="flex:1; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;" />
                            <input type="text" name="fai_cards[${i}][nome]" placeholder="Nome" style="flex:1; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;" />
                        </div>
                        <input type="text" name="fai_cards[${i}][tessera]" placeholder="N° Tessera FAI" style="width:100%; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;" />
                    </div>
                `;
            }
            mQbFaiCardsList.innerHTML = html;
        });
    }

    const quickForm = document.getElementById('dfn-mobile-quick-booking-form');
    if (quickForm) {
        quickForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const lastName = document.getElementById('dfn-m-qb-lastname')?.value.trim();
            if (!lastName) {
                showToast('⚠️ Il cognome è obbligatorio.', 'error');
                return;
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Salvataggio in corso...';

            const formData = new FormData(this);
            formData.append('action', 'dfn_admin_add_booking');
            formData.append('nonce', nonces.quick || nonces.admin || nonces.booking || '');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        vibrate([100, 50, 100]);
                        showToast('⚡ Prenotazione creata con successo!', 'success');
                        quickForm.reset();
                        if (mQbDateWrap) mQbDateWrap.style.display = 'none';
                        if (mQbSlotWrap) mQbSlotWrap.style.display = 'none';
                        if (mQbGuestWrap) mQbGuestWrap.style.display = 'none';
                        switchTab('home');
                    } else {
                        showToast('⚠️ Errore: ' + (res.data?.message || res.data || 'Impossibile registrare'), 'error');
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

    // =========================================================================
    // BOTTEGHINO LIVE MOBILE TAB (TAB 4)
    // =========================================================================
    const mBotEventSel     = document.getElementById('dfn-bot-event');
    const mBotDateSel      = document.getElementById('dfn-bot-date');
    const mBotDateWrap     = document.getElementById('dfn-bot-date-wrap');
    const mBotSlotSel      = document.getElementById('dfn-bot-slot');
    const mBotSlotWrap     = document.getElementById('dfn-bot-slot-wrap');
    const mBotGuestWrap    = document.getElementById('dfn-bot-guest-wrap');
    const mBotQtyFaiInput  = document.getElementById('dfn-bot-qty-fai');
    const mBotFaiCardsWrap = document.getElementById('dfn-bot-fai-cards-wrap');
    const mBotCustInput   = document.getElementById('dfn-bot-cust-search');
    const mBotCustResults = document.getElementById('dfn-bot-cust-results');
    let mBotCustTimer     = null;

    if (mBotCustInput && mBotCustResults) {
        mBotCustInput.addEventListener('input', function () {
            const query = this.value.trim();
            clearTimeout(mBotCustTimer);

            if (query.length < 2) {
                mBotCustResults.style.display = 'none';
                mBotCustResults.innerHTML = '';
                return;
            }

            mBotCustTimer = setTimeout(() => {
                const secToken = nonces.cust || nonces.admin || nonces.quick || nonces.booking || '';
                fetch(ajaxUrl + '?action=cv_search_customers&term=' + encodeURIComponent(query) + '&security=' + encodeURIComponent(secToken))
                    .then(r => r.json())
                    .then(data => {
                        if (Array.isArray(data) && data.length > 0) {
                            let html = '';
                            data.forEach(item => {
                                html += `<div class="dfn-m-cust-item" data-id="${item.id}" style="padding:10px 12px; border-bottom:1px solid #f1f5f9; cursor:pointer; font-size:13px; color:#1e293b;">
                                    👤 <strong>${item.text}</strong>
                                </div>`;
                            });
                            mBotCustResults.innerHTML = html;
                            mBotCustResults.style.display = 'block';

                            mBotCustResults.querySelectorAll('.dfn-m-cust-item').forEach(el => {
                                el.addEventListener('click', function () {
                                    const customerId = this.getAttribute('data-id');
                                    mBotCustResults.style.display = 'none';
                                    mBotCustInput.value = '';

                                    const fdCust = new FormData();
                                    fdCust.append('action', 'cv_get_customer_data');
                                    fdCust.append('customer_id', customerId);
                                    fdCust.append('security', secToken);

                                    fetch(ajaxUrl, { method: 'POST', body: fdCust })
                                        .then(r => r.json())
                                        .then(cRes => {
                                            if (cRes.success && cRes.data) {
                                                const c = cRes.data;
                                                const fnInput = document.getElementById('dfn-bot-firstname');
                                                const lnInput = document.getElementById('dfn-bot-lastname');
                                                const emInput = document.getElementById('dfn-bot-email');
                                                const phInput = document.getElementById('dfn-bot-phone');

                                                if (fnInput) fnInput.value = c.first_name || '';
                                                if (lnInput) lnInput.value = c.last_name || '';
                                                if (emInput) emInput.value = c.email || '';
                                                if (phInput) phInput.value = c.phone || '';

                                                showToast('👤 Dati cliente caricati!', 'success');
                                            } else {
                                                showToast('⚠️ Impossibile caricare i dati del cliente', 'error');
                                            }
                                        })
                                        .catch(() => {
                                            showToast('⚠️ Errore durante il caricamento cliente', 'error');
                                        });
                                });
                            });
                        } else {
                            mBotCustResults.innerHTML = '<div style="padding:10px; font-size:13px; color:#64748b;">Nessun cliente trovato</div>';
                            mBotCustResults.style.display = 'block';
                        }
                    })
                    .catch(() => {});
            }, 300);
        });

        document.addEventListener('click', function (e) {
            if (!mBotCustInput.contains(e.target) && !mBotCustResults.contains(e.target)) {
                mBotCustResults.style.display = 'none';
            }
        });
    }

    if (mBotEventSel) {
        mBotEventSel.addEventListener('change', function () {
            const evId = parseInt(this.value, 10);
            if (mBotDateWrap) mBotDateWrap.style.display = 'none';
            if (mBotSlotWrap) mBotSlotWrap.style.display = 'none';
            if (mBotGuestWrap) mBotGuestWrap.style.display = 'none';

            if (!evId || isNaN(evId)) return;

            if (mBotDateWrap) mBotDateWrap.style.display = 'block';
            if (mBotDateSel) {
                mBotDateSel.disabled = true;
                mBotDateSel.innerHTML = '<option value="">⏳ Caricamento date…</option>';
            }

            const fd = new FormData();
            fd.append('action', 'dfn_quick_get_dates');
            fd.append('nonce', nonces.quick || nonces.admin || nonces.booking || '');
            fd.append('event_id', evId);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success && res.data.dates && res.data.dates.length > 0) {
                        let html = res.data.dates.length > 1 ? '<option value="">— Seleziona una data —</option>' : '';
                        res.data.dates.forEach(d => {
                            const p = d.split('-');
                            const fmt = p.length === 3 ? `${p[2]}/${p[1]}/${p[0]}` : d;
                            html += `<option value="${d}">${fmt}</option>`;
                        });
                        if (mBotDateSel) {
                            mBotDateSel.innerHTML = html;
                            mBotDateSel.disabled = false;
                            if (res.data.dates.length === 1) {
                                mBotDateSel.value = res.data.dates[0];
                                mBotDateSel.dispatchEvent(new Event('change'));
                            }
                        }
                    } else if (mBotDateSel) {
                        mBotDateSel.innerHTML = '<option value="">Nessuna data disponibile</option>';
                    }
                })
                .catch(() => {
                    if (mBotDateSel) mBotDateSel.innerHTML = '<option value="">Errore caricamento date</option>';
                });
        });
    }

    if (mBotDateSel) {
        mBotDateSel.addEventListener('change', function () {
            const date = this.value;
            const evId = mBotEventSel ? parseInt(mBotEventSel.value, 10) : 0;
            const selectedOpt = mBotEventSel ? mBotEventSel.options[mBotEventSel.selectedIndex] : null;
            const accessType = selectedOpt ? selectedOpt.getAttribute('data-access') : 'time_slots';

            if (mBotSlotWrap) mBotSlotWrap.style.display = 'none';
            if (mBotGuestWrap) mBotGuestWrap.style.display = 'none';

            if (!date || !evId) return;

            if (accessType === 'free_flow') {
                if (mBotGuestWrap) mBotGuestWrap.style.display = 'block';
                return;
            }

            if (mBotSlotWrap) mBotSlotWrap.style.display = 'block';
            if (mBotSlotSel) {
                mBotSlotSel.disabled = true;
                mBotSlotSel.innerHTML = '<option value="0">⏳ Caricamento turni…</option>';
            }

            const fd = new FormData();
            fd.append('action', 'dfn_quick_get_slots');
            fd.append('nonce', nonces.quick || nonces.admin || nonces.booking || '');
            fd.append('event_id', evId);
            fd.append('date', date);

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    let html = '<option value="0">🤖 Auto — Smistamento automatico</option>';
                    if (res.success && res.data.slots && res.data.slots.length > 0) {
                        res.data.slots.forEach(s => {
                            const locked = s.is_locked;
                            const avail = s.available;
                            const icon = locked ? '🔒' : (avail === 0 ? '🔴' : avail <= 3 ? '🟡' : '🟢');
                            const label = locked ? `${s.time_start} → ${s.time_end} ${icon} Bloccato` : `${s.time_start} → ${s.time_end} ${icon} ${avail} liberi`;
                            html += `<option value="${s.id}" ${locked || avail === 0 ? 'disabled' : ''}>${label}</option>`;
                        });
                    }
                    if (mBotSlotSel) {
                        mBotSlotSel.innerHTML = html;
                        mBotSlotSel.disabled = false;
                    }
                    if (mBotGuestWrap) mBotGuestWrap.style.display = 'block';
                })
                .catch(() => {
                    if (mBotSlotSel) {
                        mBotSlotSel.innerHTML = '<option value="0">🤖 Auto — Smistamento automatico</option>';
                        mBotSlotSel.disabled = false;
                    }
                    if (mBotGuestWrap) mBotGuestWrap.style.display = 'block';
                });
        });
    }

    if (mBotQtyFaiInput) {
        mBotQtyFaiInput.addEventListener('input', function () {
            const qty = parseInt(this.value, 10) || 0;
            if (!mBotFaiCardsWrap || !mBotFaiCardsList) return;
            if (qty <= 0) {
                mBotFaiCardsWrap.style.display = 'none';
                mBotFaiCardsList.innerHTML = '';
                return;
            }
            mBotFaiCardsWrap.style.display = 'block';
            let html = '';
            for (let i = 0; i < qty; i++) {
                html += `
                    <div class="dfn-m-fai-card" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px; margin-bottom:8px;">
                        <div style="font-size:12px; font-weight:700; color:#92400e; margin-bottom:6px;">Socio FAI #${i + 1}</div>
                        <div style="display:flex; gap:8px; margin-bottom:6px;">
                            <input type="text" name="fai_cards[${i}][cognome]" placeholder="Cognome" style="flex:1; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;" />
                            <input type="text" name="fai_cards[${i}][nome]" placeholder="Nome" style="flex:1; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;" />
                        </div>
                        <input type="text" name="fai_cards[${i}][tessera]" placeholder="N° Tessera FAI" style="width:100%; padding:6px 10px; font-size:13px; border:1px solid #cbd5e1; border-radius:6px;" />
                    </div>
                `;
            }
            mBotFaiCardsList.innerHTML = html;
        });
    }

    // Gestione submit form Botteghino Live Mobile
    const botteghinoForm = document.getElementById('dfn-mobile-botteghino-form');
    if (botteghinoForm) {
        botteghinoForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const paymentMethod = document.getElementById('dfn-bot-payment')?.value || 'contanti';
            const lastName = document.getElementById('dfn-bot-lastname')?.value.trim();

            if (paymentMethod !== 'autorita' && !lastName) {
                showToast('⚠️ Il cognome è obbligatorio.', 'error');
                return;
            }

            const qtyStd = parseInt(document.getElementById('dfn-bot-qty-std')?.value, 10) || 0;
            const qtyFai = parseInt(document.getElementById('dfn-bot-qty-fai')?.value, 10) || 0;
            if ((qtyStd + qtyFai) <= 0) {
                showToast('⚠️ Specifica almeno un biglietto.', 'error');
                return;
            }

            if (paymentMethod === 'autorita') {
                if (!confirm('Confermi di voler riservare i posti come OMAGGIO PER AUTORITÀ?')) return;
            }

            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Emissione in corso...';

            const formData = new FormData(this);
            formData.append('action', 'dfn_botteghino_create_booking');
            formData.append('nonce', nonces.admin || nonces.quick || nonces.booking || '');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        vibrate([100, 100, 100]);
                        showToast(res.data?.message || '🎟️ Operazione Botteghino registrata con successo!', 'success');
                        botteghinoForm.reset();
                        if (mBotDateWrap) mBotDateWrap.style.display = 'none';
                        if (mBotSlotWrap) mBotSlotWrap.style.display = 'none';
                        if (mBotGuestWrap) mBotGuestWrap.style.display = 'none';
                        switchTab('home');
                    } else {
                        showToast('⚠️ Errore: ' + (res.data?.message || res.data || 'Impossibile completare'), 'error');
                    }
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💶 Emetti Biglietto & Registra Incasso';
                })
                .catch(() => {
                    showToast('⚠️ Errore di connessione', 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = '💶 Emetti Biglietto & Registra Incasso';
                });
        });
    }

    // ==========================================
    // 📷 GESTIONE AVATAR & PROFILO UTENTE
    // ==========================================
    const avatarWrapper   = document.getElementById('dfn-profile-avatar-display');
    const avatarBadgeBtn  = document.getElementById('dfn-btn-trigger-avatar');
    const avatarFileInput = document.getElementById('dfn-avatar-file-input');
    const avatarImgPre    = document.getElementById('dfn-avatar-img-preview');
    const avatarInitPre   = document.getElementById('dfn-avatar-initials-preview');
    const avatarActions   = document.getElementById('dfn-avatar-actions-area');
    const removeAvatarBtn = document.getElementById('dfn-btn-remove-avatar');
    const profileForm     = document.getElementById('dfn-mobile-profile-form');

    function triggerAvatarSelect() {
        if (avatarFileInput) avatarFileInput.click();
    }

    if (avatarWrapper) {
        avatarWrapper.addEventListener('click', triggerAvatarSelect);
    }
    if (avatarBadgeBtn) {
        avatarBadgeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            triggerAvatarSelect();
        });
    }

    if (avatarFileInput) {
        avatarFileInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (! file) return;

            if (file.size > 5 * 1024 * 1024) {
                showToast('⚠️ L\'immagine non può superare i 5 MB.', 'error');
                return;
            }

            // Preview istantanea con FileReader
            const reader = new FileReader();
            reader.onload = function (e) {
                if (avatarImgPre) {
                    avatarImgPre.src = e.target.result;
                    avatarImgPre.style.display = 'block';
                }
                if (avatarInitPre) {
                    avatarInitPre.style.display = 'none';
                }
            };
            reader.readAsDataURL(file);

            // Caricamento AJAX immediato dell'avatar
            showToast('⏳ Caricamento foto profilo in corso...', 'info');
            const fd = new FormData();
            fd.append('action', 'dfn_mobile_upload_avatar');
            fd.append('avatar', file);
            fd.append('nonce', nonces.admin || nonces.booking || '');

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        vibrate([100, 50, 100]);
                        showToast('✅ Foto profilo aggiornata!', 'success');
                        if (avatarActions) avatarActions.style.display = 'block';
                        if (res.data && res.data.avatar_url && avatarImgPre) {
                            avatarImgPre.src = res.data.avatar_url;
                        }
                    } else {
                        showToast('⚠️ Errore upload: ' + (res.data || 'Impossibile caricare'), 'error');
                    }
                })
                .catch(() => {
                    showToast('⚠️ Errore di connessione durante l\'upload', 'error');
                });
        });
    }

    if (removeAvatarBtn) {
        removeAvatarBtn.addEventListener('click', function () {
            if (! confirm('Sei sicuro di voler rimuovere la foto profilo?')) return;

            showToast('⏳ Rimozione foto in corso...', 'info');
            const fd = new FormData();
            fd.append('action', 'dfn_mobile_remove_avatar');
            fd.append('nonce', nonces.admin || nonces.booking || '');

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        showToast('🗑️ Foto profilo rimossa.', 'success');
                        if (avatarImgPre) {
                            avatarImgPre.src = '';
                            avatarImgPre.style.display = 'none';
                        }
                        if (avatarInitPre) {
                            avatarInitPre.style.display = 'block';
                        }
                        if (avatarActions) avatarActions.style.display = 'none';
                        if (avatarFileInput) avatarFileInput.value = '';
                    } else {
                        showToast('⚠️ Errore: ' + (res.data || 'Impossibile rimuovere'), 'error');
                    }
                });
        });
    }

    if (profileForm) {
        profileForm.addEventListener('submit', function (e) {
            e.preventDefault();

            const submitBtn = document.getElementById('dfn-btn-save-profile');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = '⏳ Salvataggio in corso...';
            }

            const fd = new FormData(this);
            fd.append('action', 'dfn_mobile_update_profile');
            fd.append('nonce', nonces.admin || nonces.booking || '');

            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Salva Profilo';
                    }

                    if (res.success) {
                        vibrate([100, 50, 100]);
                        showToast('✅ Profilo aggiornato con successo!', 'success');

                        if (res.data && res.data.display_name) {
                            const dispNameEl = document.getElementById('dfn-profile-display-name');
                            if (dispNameEl) dispNameEl.textContent = res.data.display_name;
                        }
                        if (res.data && res.data.user_email) {
                            const dispEmailEl = document.getElementById('dfn-profile-display-email');
                            if (dispEmailEl) dispEmailEl.textContent = '📧 ' + res.data.user_email;
                        }

                        // Reset campo nuova password
                        const passInput = document.getElementById('dfn-prof-pass');
                        if (passInput) passInput.value = '';
                    } else {
                        showToast('⚠️ Errore: ' + (res.data || 'Impossibile salvare il profilo'), 'error');
                    }
                })
                .catch(() => {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Salva Profilo';
                    }
                    showToast('⚠️ Errore di connessione', 'error');
                });
        });
    }
});

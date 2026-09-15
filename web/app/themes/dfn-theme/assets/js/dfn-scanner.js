/**
 * DFN Booking System 2.0 — Premium Live Scanner Script
 *
 * Gestisce l'inizializzazione della fotocamera nativa, l'elaborazione dei QR,
 * la visualizzazione delle modali finanziarie ed il consolidamento del saldo.
 *
 * @package DFN_Theme
 * @since   2.0.0
 */

jQuery(document).ready(function($) {
    if ($('#dfn-reader').length === 0) {
        return;
    }

    var html5QrCode;
    var scannerStarted = false;
    var scanInProgress = false;
    var scanCanvas = null;
    var scanCtx = null;
    var scanAnimFrame = null;

    var $btnStart = $('#dfn-btn-start');
    var $modalContainer = $('#dfn-scan-modal-container');

    function startFrameAnalyzer(videoElem) {
        if (!videoElem) return;
        if (!scanCanvas) {
            scanCanvas = document.createElement('canvas');
            scanCtx = scanCanvas.getContext('2d', { willReadFrequently: true });
        }

        function analyzeFrame() {
            if (!scannerStarted) return;
            if (!scanInProgress && videoElem && videoElem.readyState >= 2 && videoElem.videoWidth > 0 && videoElem.videoHeight > 0) {
                var vw = videoElem.videoWidth;
                var vh = videoElem.videoHeight;
                var scale = Math.min(1.0, 720 / Math.max(vw, vh));
                var targetW = Math.max(200, Math.floor(vw * scale));
                var targetH = Math.max(200, Math.floor(vh * scale));

                if (scanCanvas.width !== targetW || scanCanvas.height !== targetH) {
                    scanCanvas.width = targetW;
                    scanCanvas.height = targetH;
                }

                try {
                    scanCtx.drawImage(videoElem, 0, 0, targetW, targetH);
                    var imgData = scanCtx.getImageData(0, 0, targetW, targetH);
                    if (typeof jsQR !== 'undefined') {
                        var code = jsQR(imgData.data, targetW, targetH, { inversionAttempts: "dontInvert" });
                        if (code && code.data && code.data.trim().length > 0) {
                            onScanSuccess(code.data.trim());
                        }
                    }
                } catch (e) {}
            }
            scanAnimFrame = requestAnimationFrame(analyzeFrame);
        }

        stopFrameAnalyzer();
        scanAnimFrame = requestAnimationFrame(analyzeFrame);
    }

    function stopFrameAnalyzer() {
        if (scanAnimFrame) {
            cancelAnimationFrame(scanAnimFrame);
            scanAnimFrame = null;
        }
    }

    // Configurazione camera
    var config = {
        fps: 15,
        qrbox: function(viewfinderWidth, viewfinderHeight) {
            var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
            var size = Math.floor(minEdge * 0.8);
            return {
                width: Math.max(180, size),
                height: Math.max(180, size)
            };
        },
        aspectRatio: 1.0,
        videoConstraints: {
            facingMode: { ideal: "environment" },
            focusMode: { ideal: "continuous" },
            width: { min: 640, ideal: 1280, max: 1920 },
            height: { min: 480, ideal: 720, max: 1080 }
        }
    };

    $btnStart.on('click', function() {
        if (scannerStarted) {
            stopScanner();
        } else {
            startScanner();
        }
    });

    async function startScanner() {
        html5QrCode = new Html5Qrcode("dfn-reader", {
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            },
            verbose: false
        });
        $btnStart.text("⏳ Avvio fotocamera...").prop('disabled', true);

        var cameraConfig = { facingMode: "environment" };
        try {
            var devices = await Html5Qrcode.getCameras();
            if (devices && devices.length > 0) {
                var backCameras = devices.filter(function(d) {
                    var lbl = (d.label || '').toLowerCase();
                    return lbl.includes('back') || lbl.includes('rear') || lbl.includes('posteriore') || lbl.includes('environment');
                });
                var primaryBack = backCameras.find(function(d) {
                    var lbl = (d.label || '').toLowerCase();
                    return !lbl.includes('ultra') && !lbl.includes('0.5') && !lbl.includes('telephoto') && !lbl.includes('zoom');
                });
                if (primaryBack) {
                    cameraConfig = { deviceId: { exact: primaryBack.id } };
                } else if (backCameras.length > 0) {
                    cameraConfig = { deviceId: { exact: backCameras[0].id } };
                }
            }
        } catch (e) {
            cameraConfig = { facingMode: "environment" };
        }

        html5QrCode.start(
            cameraConfig,
            config,
            onScanSuccess,
            onScanFailure
        ).then(function() {
            scannerStarted = true;
            $btnStart.text("📷 Spegni Fotocamera").prop('disabled', false).removeClass('dfn-scanner-btn-start').addClass('dfn-scan-btn-close');

            var videoElem = document.querySelector('#dfn-reader video');
            if (videoElem) {
                videoElem.setAttribute('playsinline', 'true');
                videoElem.setAttribute('webkit-playsinline', 'true');
                videoElem.setAttribute('muted', 'true');
                videoElem.setAttribute('autoplay', 'true');
                videoElem.muted = true;
                videoElem.playsInline = true;
                if (videoElem.paused) videoElem.play().catch(function() {});
                startFrameAnalyzer(videoElem);
                videoElem.addEventListener('play', function() { startFrameAnalyzer(videoElem); });
            }
        }).catch(function(err) {
            $btnStart.text("📷 Avvia Fotocamera").prop('disabled', false);
            alert("Impossibile accedere alla fotocamera. Assicurati di aver concesso i permessi.");
        });
    }

    function stopScanner() {
        stopFrameAnalyzer();
        if (html5QrCode) {
            html5QrCode.stop().then(function() {
                html5QrCode.clear();
                scannerStarted = false;
                $btnStart.text("📷 Avvia Fotocamera").removeClass('dfn-scan-btn-close').addClass('dfn-scanner-btn-start');
            }).catch(function(err) {
                console.error("Errore arresto camera: ", err);
            });
        }
    }

    function onScanSuccess(decodedText, decodedResult) {
        if (scanInProgress) return;
        scanInProgress = true;

        // Eseguiamo il bip di scansione se possibile
        try {
            var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            var osc = audioCtx.createOscillator();
            osc.type = "sine";
            osc.frequency.setValueAtTime(800, audioCtx.currentTime);
            osc.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.1);
        } catch(e) {}

        // Sospendiamo la scansione visuale per mostrare il risultato
        if (scannerStarted) {
            html5QrCode.pause(true);
        }

        // Mostra modale loader
        showLoaderModal();

        // Chiamata AJAX per processare la scansione
        $.ajax({
            url: dfnScannerVars.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dfn_process_scan',
                qr_token: decodedText,
                security: dfnScannerVars.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderScanResult(response.data, decodedText);
                } else {
                    renderErrorModal(response.data ? response.data.message : "Token QR non riconosciuto o non valido.");
                }
            },
            error: function() {
                renderErrorModal("Impossibile connettersi al server. Verifica la connessione.");
            }
        });
    }

    function onScanFailure(error) {
        // Silenzioso - logga solo in console
    }

    function showLoaderModal() {
        var loaderHtml = '<div class="dfn-scan-result-modal">' +
            '  <div class="dfn-scan-result-card">' +
            '    <div class="dfn-scanner-loading"></div>' +
            '    <h3 class="dfn-scan-title" style="margin-top:20px;">Elaborazione ticket...</h3>' +
            '  </div>' +
            '</div>';
        $modalContainer.html(loaderHtml).show();
    }

    function renderScanResult(data, qrToken) {
        var cardHtml = '<div class="dfn-scan-result-modal">' +
            '  <div class="dfn-scan-result-card">';

        if (data.status === 'checked_in') {
            // Caso A: Biglietto già registrato ed entrato
            cardHtml += '    <div class="dfn-scan-icon-badge dfn-badge-error">❌</div>' +
                '    <h3 class="dfn-scan-title">Già Scansionato</h3>' +
                '    <p class="dfn-scan-details">' +
                '      <strong>' + data.customer_name + '</strong><br>' +
                '      Ingresso per ' + data.total_persons + ' persone effettuato il:<br>' +
                '      <strong>' + data.checked_in_at + '</strong><br>' +
                '      Validato da: ' + data.checked_in_by +
                '    </p>' +
                '    <button class="dfn-scan-btn dfn-scan-btn-close btn-close-modal">Chiudi</button>';
        } else if (data.payment_required) {
            // Caso B: Richiede pagamento In Loco
            cardHtml += '    <div class="dfn-scan-icon-badge dfn-badge-warning">💵</div>' +
                '    <h3 class="dfn-scan-title">Saldo in Loco</h3>' +
                '    <p class="dfn-scan-details" style="margin-bottom:15px;">' +
                '      <strong>' + data.customer_name + '</strong><br>' +
                '      Gruppo di <strong>' + data.total_persons + ' persone</strong> per:<br>' +
                '      <span style="color:#fbbf24;">' + data.event_title + '</span>' +
                '    </p>' +
                '    <div class="dfn-finance-breakdown">' +
                '      <div class="dfn-finance-row"><span>' + data.persons_standard + ' x Tariffa Standard</span><span>' + data.price_standard_formatted + '</span></div>' +
                '      <div class="dfn-finance-row"><span>' + data.persons_fai + ' x Tariffa Socio FAI</span><span>' + data.price_fai_formatted + '</span></div>' +
                '      <div class="dfn-finance-total"><span>Totale da Incassare</span><span>' + data.amount_due_formatted + '</span></div>' +
                '    </div>' +
                '    <div class="dfn-action-buttons">' +
                '      <button class="dfn-scan-btn dfn-scan-btn-cash btn-consolidate" data-method="cash" data-token="' + qrToken + '">💵 Contanti</button>' +
                '      <button class="dfn-scan-btn dfn-scan-btn-pos btn-consolidate" data-method="pos" data-token="' + qrToken + '">💳 POS/Carta</button>' +
                '    </div>' +
                '    <button class="dfn-scan-btn dfn-scan-btn-close btn-close-modal" style="margin-top:12px;">Annulla</button>';
        } else {
            // Caso C: Accesso valido (pagamento online già effettuato)
            cardHtml += '    <div class="dfn-scan-icon-badge dfn-badge-success">✅</div>' +
                '    <h3 class="dfn-scan-title">Accesso Valido</h3>' +
                '    <p class="dfn-scan-details">' +
                '      <strong>' + data.customer_name + '</strong><br>' +
                '      Ingresso valido per <strong>' + data.total_persons + ' persone</strong>.<br>' +
                '      <span style="color:#34d399;">' + data.event_title + '</span><br>' +
                '      <small style="color:#9ca3af;">Ordine #' + data.order_id + '</small>' +
                '    </p>' +
                '    <button class="dfn-scan-btn dfn-scan-btn-close btn-close-modal">Conferma Ingresso</button>';
        }

        cardHtml += '  </div>' +
            '</div>';

        $modalContainer.html(cardHtml);

        // Associa eventi di chiusura modale
        $modalContainer.find('.btn-close-modal').on('click', function() {
            closeResultModal();
        });

        // Associa eventi di consolidamento saldo
        $modalContainer.find('.btn-consolidate').on('click', function() {
            var $btn = $(this);
            var method = $btn.data('method');
            var token = $btn.data('token');

            $modalContainer.find('.dfn-scan-btn').prop('disabled', true);
            $btn.html('<div class="dfn-scanner-loading" style="width:18px; height:18px; border-width:2px; margin:0;"></div> Prolungo...');

            $.ajax({
                url: dfnScannerVars.ajaxurl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'dfn_consolidate_in_loco_payment',
                    qr_token: token,
                    payment_method: method,
                    security: dfnScannerVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        renderScanResult(response.data, token);
                    } else {
                        renderErrorModal(response.data ? response.data.message : "Errore durante il salvataggio del saldo.");
                    }
                },
                error: function() {
                    renderErrorModal("Impossibile connettersi al server.");
                }
            });
        });
    }

    function renderErrorModal(errorMsg) {
        var errorHtml = '<div class="dfn-scan-result-modal">' +
            '  <div class="dfn-scan-result-card">' +
            '    <div class="dfn-scan-icon-badge dfn-badge-error">⚠️</div>' +
            '    <h3 class="dfn-scan-title">Scansione Fallita</h3>' +
            '    <p class="dfn-scan-details">' + errorMsg + '</p>' +
            '    <button class="dfn-scan-btn dfn-scan-btn-close btn-close-modal">✕ Chiudi e Riprova</button>' +
            '  </div>' +
            '</div>';

        $modalContainer.html(errorHtml);
        $modalContainer.find('.btn-close-modal').on('click', function() {
            closeResultModal();
        });
    }

    function closeResultModal() {
        $modalContainer.hide().html('');
        scanInProgress = false;
        if (scannerStarted && html5QrCode) {
            html5QrCode.resume();
        }
    }
});

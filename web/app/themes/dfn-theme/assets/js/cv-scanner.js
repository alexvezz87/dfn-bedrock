
/* ==========================================================================
   SCRIPT JS SCANNER FOTOCAMERA
   ========================================================================== */
document.addEventListener("DOMContentLoaded", function() {
    var html5QrCode; 
    var audioCtx;
    var isProcessing = false;
    var resultDiv = document.getElementById('scan-result');
    var btnStart = document.getElementById('btn-start');
    
    var securityNonce = cvScannerVars.nonce; 
    var ajaxurl = cvScannerVars.ajaxurl;

    function initAudioAndVibration() {
        if (!audioCtx) { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
        if (audioCtx.state === 'suspended') { audioCtx.resume(); }
    }

    function playFeedback(type) {
        if(navigator.vibrate) { type === 'success' ? navigator.vibrate(200) : navigator.vibrate([500, 200, 500]); }
        if (audioCtx) {
            var osc = audioCtx.createOscillator(); var gainNode = audioCtx.createGain();
            osc.connect(gainNode); gainNode.connect(audioCtx.destination);
            if (type === 'success') {
                osc.type = "triangle"; osc.frequency.setValueAtTime(1200, audioCtx.currentTime); 
                gainNode.gain.setValueAtTime(0.8, audioCtx.currentTime); osc.start(); osc.stop(audioCtx.currentTime + 0.2);
            } else {
                osc.type = "sawtooth"; osc.frequency.setValueAtTime(150, audioCtx.currentTime); 
                gainNode.gain.setValueAtTime(0.3, audioCtx.currentTime); osc.start(); osc.stop(audioCtx.currentTime + 0.5);
            }
        }
    }

    window.chiudiPannello = function() {
        resultDiv.classList.remove('show');
        setTimeout(function() { isProcessing = false; }, 300);
    };

    function onScanSuccess(decodedText, decodedResult) {
        if (isProcessing) return;
        isProcessing = true;
        var bottoneChiudi = '<button onclick="chiudiPannello()" style="margin-top:20px; width:100%; padding:15px; font-size:18px; font-weight:bold; border-radius:8px; border:none; background:#111; color:#fff; box-shadow:0 4px 6px rgba(0,0,0,0.2);">Chiudi e scansiona il prossimo</button>';

        if (!decodedText.includes('cv_checkin=1')) {
            playFeedback('error'); resultDiv.style.background = '#fef2f2'; resultDiv.style.color = '#991b1b';
            resultDiv.innerHTML = '<span style="font-size:30px;">❌ QR NON RICONOSCIUTO</span><br><br>Questo QR Code non è un biglietto valido per questo evento.<br>' + bottoneChiudi;
            resultDiv.classList.add('show'); return; 
        }

        resultDiv.style.background = '#ffeb3b'; resultDiv.style.color = '#333';
        resultDiv.innerHTML = '<h2 style="margin:0;">⏳ Verifica in corso...</h2>'; resultDiv.classList.add('show');

        var urlParams = new URL(decodedText).searchParams;
        var orderId = urlParams.get('order_id');
        var token = urlParams.get('token');
        var ticketIndex = urlParams.get('ticket');

        function attemptAjax(tentativi) {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'cv_process_live_scan', security: securityNonce, order_id: orderId, token: token, ticket: ticketIndex },
                timeout: 8000,
                success: function(response) {
                    if (response.success) {
                        playFeedback('success'); resultDiv.style.background = '#f0fdf4'; resultDiv.style.color = '#166534'; resultDiv.innerHTML = response.data.message + bottoneChiudi;
                    } else {
                        playFeedback('error'); resultDiv.style.background = '#fef2f2'; resultDiv.style.color = '#991b1b'; resultDiv.innerHTML = response.data.message + bottoneChiudi;
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    if (textStatus === 'timeout' && tentativi < 2) {
                        resultDiv.innerHTML = '<h2 style="margin:0;">⏳ Rete lenta, nuovo tentativo (' + (tentativi + 1) + '/2)...</h2>';
                        attemptAjax(tentativi + 1);
                    } else {
                        playFeedback('error'); resultDiv.style.background = '#fff3cd'; resultDiv.style.color = '#856404';
                        var errorMsg = (textStatus === 'timeout') ? 'Il server non risponde dopo 3 tentativi.' : 'Connessione Internet assente o instabile.';
                        resultDiv.innerHTML = '<span style="font-size:30px;">⚠️ ERRORE DI RETE</span><br><br>' + errorMsg + '<br>Verifica la copertura 4G o Wi-Fi e riprova la scansione.<br>' + bottoneChiudi;
                    }
                }
            });
        }
        attemptAjax(0);
    }

    btnStart.addEventListener('click', function() {
        initAudioAndVibration(); btnStart.style.display = 'none';
        html5QrCode = new Html5Qrcode("reader");
        html5QrCode.start( { facingMode: "environment" }, { fps: 10, qrbox: { width: 250, height: 250 } }, onScanSuccess )
        .catch(function(err) { alert("Errore fotocamera: " + err); btnStart.style.display = 'block'; });
    });
});
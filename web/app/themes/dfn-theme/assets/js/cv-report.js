/* ==========================================================================
   SCRIPT JS TABELLONE REPORT
   ========================================================================== */
jQuery(document).ready(function($) {
    var eventId = cvReportVars.eventId;
    var currentPage = 1;
    var currentSearch = "";
    var currentFilter = "all"; 
    var needsReload = false;
    
    var ajaxNonce = cvReportVars.nonceFetch;
    var manualNonce = cvReportVars.nonceManual;
    var reminderNonce = cvReportVars.nonceReminder;
    var assignTableNonce = cvReportVars.nonceTable;
    var feedbackNonce = cvReportVars.nonceFeedback;
    var ajaxurl = cvReportVars.ajaxurl;

    if (eventId > 0) {
        fetchReportData(false);
        setInterval(function() { fetchReportData(true); }, 60000);
    }

    function fetchReportData(isSilent) {
        if ($("#cv-cassa-modal").is(":visible") || $("#cv-history-modal").is(":visible") || $("#cv-table-map-modal").is(":visible")) return; 
        $("#cv-sync-icon").show().addClass("cv-is-syncing");
        if (!isSilent) $("#cv-report-tbody").addClass("cv-table-loading");

        $.post(ajaxurl, {
            action: "cv_fetch_report_data", security: ajaxNonce, event_id: eventId, page: currentPage, search: currentSearch, filter_status: currentFilter
        }, function(response) {
            $("#cv-sync-icon").removeClass("cv-is-syncing").hide();
            $("#cv-report-tbody").removeClass("cv-table-loading");
            if (response.success) {
                $("#cv-tot-venduti").text(response.data.tot_venduti);
                $("#cv-tot-checkin").text(response.data.tot_checkin);
                $("#cv-tot-residui").text(response.data.tot_residui); 
                $("#cv-tot-liberi").text(response.data.tot_rimanenti); 
                $("#cv-validator-leaderboard").html(response.data.html_leaderboard);
                $("#cv-report-tbody").html(response.data.html_table_rows);
                $("#cv-pagination-top, #cv-pagination-bottom").html(response.data.html_pagination);
            }
        });
    }

    var searchTimeout;
    $("#cv-search-table").on("input", function() {
        clearTimeout(searchTimeout); currentSearch = $(this).val(); currentPage = 1; 
        searchTimeout = setTimeout(function() { fetchReportData(false); }, 500);
    });
    
    $("#cv-filter-status").on("change", function() { currentFilter = $(this).val(); currentPage = 1; fetchReportData(false); });
    $(document).on("click", ".cv-page-btn", function(e) { e.preventDefault(); currentPage = $(this).data("page"); fetchReportData(false); });

    $(document).on("click", ".cv-open-popup-btn", function(e) {
        e.preventDefault(); needsReload = false;
        $("#cv-modal-cliente-name").text($(this).data("cliente"));
        $("#cv-modal-buttons-area").html($(this).siblings(".cv-popup-data-container").html());
        $("#cv-cassa-modal").css("display", "flex");
    });

    $(document).on("click", ".cv-open-history-btn", function(e) {
        e.preventDefault();
        $("#cv-history-cliente-name").text($(this).data("cliente"));
        $("#cv-history-content-area").html($(this).siblings(".cv-history-data-container").html());
        $("#cv-history-modal").css("display", "flex");
    });

    $("#cv-show-map-btn").on("click", function(e) {
        e.preventDefault();
        $("#cv-table-map-modal").css("display", "flex");
        $("#cv-table-map-content").html("<div style='text-align:center; padding:50px; font-size:18px;'>⏳ Generazione mappa in corso...</div>");
        $.post(ajaxurl, { action: "cv_fetch_table_map", security: assignTableNonce, event_id: eventId }, function(response) {
            if(response.success) { $("#cv-table-map-content").html(response.data); } 
            else { $("#cv-table-map-content").html("<div style='color:red; text-align:center;'>Errore caricamento mappa.</div>"); }
        });
    });

    $(".cv-print-map-btn").on("click", function(e) { e.preventDefault(); window.print(); });

    function closeModals() {
        $("#cv-cassa-modal, #cv-history-modal, #cv-table-map-modal").hide();
        if (needsReload) { fetchReportData(false); }
        $("#cv-cassa-modal .cv-close-modal-btn").text("Chiudi Finestra"); 
    }
    $(".cv-close-modal-btn").on("click", closeModals);
    $("#cv-cassa-modal, #cv-history-modal, #cv-table-map-modal").on("click", function(e) { if (e.target === this) closeModals(); });

    $("#cv-report-tbody").on("change", ".cv-table-selector", function() {
        var select = $(this); var orderId = select.data("order"); var newTable = select.val();
        select.css("opacity", "0.5");
        $.post(ajaxurl, { action: "cv_manual_assign_table", security: assignTableNonce, order_id: orderId, table: newTable }, function(response) {
            select.css("opacity", "1");
            if(!response.success) alert("Errore nel salvataggio del tavolo.");
        });
    });

    $("#cv-auto-assign-tables-btn").on("click", function(e) {
        e.preventDefault();
        if(!confirm("⚠️ Vuoi avviare lo smistamento automatico dei tavoli?\n\nQuesto ricalcolerà i posti raggruppando i clienti in modo intelligente e mescolerà l'ordine di base. Se premi questo pulsante, eventuali modifiche manuali fatte finora verranno sovrascritte!")) return;
        var btn = $(this); var originalText = btn.text(); btn.prop("disabled", true).text("⏳ Calcolo algoritmo in corso...");
        $.post(ajaxurl, { action: "cv_auto_assign_tables", security: assignTableNonce, event_id: eventId }, function(response) {
            if(response.success) { alert("✅ Smistamento tavoli completato con successo!"); fetchReportData(false); } 
            else { alert("❌ Errore: " + response.data); }
            btn.prop("disabled", false).text(originalText);
        }).fail(function() { alert("❌ Errore di rete."); btn.prop("disabled", false).text(originalText); });
    });

    $("#cv-modal-buttons-area").on("click", ".cv-manual-checkin-btn", function(e) {
        e.preventDefault(); var btn = $(this); var orderId = btn.data("order"); var ticketIdx = btn.data("ticket");
        btn.prop("disabled", true).css("opacity", "0.5").text("⏳ Elaborazione...");
        $.post(ajaxurl, { action: "cv_process_manual_checkin", security: manualNonce, order_id: orderId, ticket: ticketIdx }, function(response) {
            if(response.success) {
                needsReload = true;
                var successHtml = '<div style="margin-bottom:8px; padding:10px; background:#eaf7ea; color:#166534; border: 1px solid #c3e6c3; border-radius: 4px; display:flex; justify-content:space-between; align-items:center;"><span>✅ Biglietto ' + ticketIdx + ' validato</span><button class="button cv-undo-checkin-btn" data-order="' + orderId + '" data-ticket="' + ticketIdx + '" style="color:#d63638; border-color:#d63638; padding:0 8px; min-height:26px; line-height:24px;">Annulla</button></div>';
                btn.replaceWith(successHtml);
                $("#cv-cassa-modal .cv-close-modal-btn").text("🔄 Chiudi e Aggiorna Tabella");
            } else { alert("Errore: " + response.data); btn.prop("disabled", false).css("opacity", "1").text("✔️ Valida Biglietto " + ticketIdx); }
        });
    });

    $("#cv-modal-buttons-area").on("click", ".cv-undo-checkin-btn", function(e) {
        e.preventDefault(); 
        if(!confirm("Vuoi davvero annullare la validazione di questo biglietto? Tornerà ad essere valido per l'ingresso.")) return;
        var btn = $(this); var orderId = btn.data("order"); var ticketIdx = btn.data("ticket"); var wrapper = btn.closest("div");
        btn.prop("disabled", true).text("⏳...");
        $.post(ajaxurl, { action: "cv_process_undo_checkin", security: manualNonce, order_id: orderId, ticket: ticketIdx }, function(response) {
            if(response.success) {
                needsReload = true;
                wrapper.replaceWith('<button class="button cv-manual-checkin-btn" data-order="' + orderId + '" data-ticket="' + ticketIdx + '" style="margin-bottom:8px; display:block; width:100%; border-color:#00a32a; color:#00a32a; height: 40px; cursor:pointer;">✔️ Valida Biglietto ' + ticketIdx + '</button>');
                $("#cv-cassa-modal .cv-close-modal-btn").text("🔄 Chiudi e Aggiorna Tabella");
            } else { alert("Errore: " + response.data); btn.prop("disabled", false).text("Annulla"); }
        }).fail(function() { alert("Errore di rete."); btn.prop("disabled", false).text("Annulla"); });
    });

    // ------------------------------------------
    // REMINDER (Massivo e Singolo)
    // ------------------------------------------
    $("#cv-send-reminders-btn").on("click", function(e) {
        e.preventDefault();
        if(!confirm("Sei sicuro di voler inviare l'email di promemoria a tutti gli acquirenti? L'invio avverrà in automatico a blocchi di 5 per garantire la massima sicurezza del server.")) return;
        var btn = $(this); var originalText = btn.text(); btn.prop("disabled", true); var totalSent = 0;
        function inviaLotto() {
            btn.text("⏳ Invio in corso (" + totalSent + " inviate)... non chiudere!");
            $.post(ajaxurl, { action: "cv_send_event_reminders", security: reminderNonce, event_id: eventId }, function(response) {
                if(response.success) {
                    totalSent += response.data.sent;
                    if (response.data.has_more) { inviaLotto(); } 
                    else {
                        if(totalSent > 0) alert("✅ Operazione completata! Inviate in totale " + totalSent + " email.");
                        else alert("✅ Nessuna email inviata. Tutti l'hanno già ricevuta.");
                        btn.prop("disabled", false).text(originalText);
                    }
                } else { alert("❌ Errore: " + response.data); btn.prop("disabled", false).text(originalText); }
            }).fail(function() { alert("❌ Errore di rete. L'invio si è interrotto a " + totalSent + " email."); btn.prop("disabled", false).text(originalText); });
        }
        inviaLotto();
    });

    $("#cv-report-tbody").on("click", ".cv-single-reminder-btn", function(e) {
        e.preventDefault();
        var btn = $(this); var orderId = btn.data("order");
        if(!confirm("Inviare la mail di promemoria a questo specifico cliente?")) return;
        var originalText = btn.text(); btn.prop("disabled", true).css("opacity", "0.5").text("⏳...");
        $.post(ajaxurl, { action: "cv_send_event_reminders", security: reminderNonce, order_id: orderId }, function(response) {
            if(response.success) {
                btn.css({"background":"#eaf7ea", "color":"#166534", "border":"1px solid #c3e6c3", "opacity":"1"}).text("✅ Inviato!");
                setTimeout(function(){ btn.css({"background":"", "color":"", "border":""}).text("📧 Reinvia Reminder"); }, 3000);
            } else { alert("❌ Errore: " + response.data); btn.prop("disabled", false).css("opacity", "1").text(originalText); }
        }).fail(function() { alert("❌ Errore di rete."); btn.prop("disabled", false).css("opacity", "1").text(originalText); });
    });

    // ------------------------------------------
    // RECENSIONI E FEEDBACK (Massivo e Singolo)
    // ------------------------------------------
    $("#cv-send-feedback-btn").on("click", function(e) {
        e.preventDefault();
        if(!confirm("Vuoi inviare la mail di richiesta recensione a TUTTI i partecipanti di questo evento? (Verrà inviata solo a chi non l'ha ancora ricevuta). L'invio avverrà a blocchi di 5.")) return;
        var btn = $(this); var originalText = btn.text(); btn.prop("disabled", true); var totalSent = 0;
        function inviaLottoFeedback() {
            btn.text("⏳ Invio in corso (" + totalSent + " inviate)... non chiudere!");
            $.post(ajaxurl, { action: "cv_send_feedback_requests", security: feedbackNonce, event_id: eventId }, function(response) {
                if(response.success) {
                    totalSent += response.data.sent;
                    if (response.data.has_more) { inviaLottoFeedback(); } 
                    else {
                        if(totalSent > 0) alert("✅ Operazione completata! Inviate in totale " + totalSent + " email di feedback.");
                        else alert("✅ Nessuna email inviata. Tutti l'hanno già ricevuta.");
                        btn.prop("disabled", false).text(originalText);
                    }
                } else { alert("❌ Errore: " + response.data); btn.prop("disabled", false).text(originalText); }
            }).fail(function() { alert("❌ Errore di rete. L'invio si è interrotto a " + totalSent + " email."); btn.prop("disabled", false).text(originalText); });
        }
        inviaLottoFeedback();
    });

    $("#cv-report-tbody").on("click", ".cv-single-feedback-btn", function(e) {
        e.preventDefault();
        var btn = $(this); var orderId = btn.data("order");
        if(!confirm("Inviare la mail di richiesta recensione a questo specifico cliente?")) return;
        var originalText = btn.text(); btn.prop("disabled", true).css("opacity", "0.5").text("⏳...");
        $.post(ajaxurl, { action: "cv_send_feedback_requests", security: feedbackNonce, order_id: orderId }, function(response) {
            if(response.success) {
                btn.css({"background":"#fefce8", "color":"#b45309", "border":"1px solid #fde047", "opacity":"1"}).text("✅ Inviato!");
                setTimeout(function(){ btn.css({"background":"", "color":"", "border":""}).text("⭐ Reinvia Recensione"); }, 3000);
            } else { alert("❌ Errore: " + response.data); btn.prop("disabled", false).css("opacity", "1").text(originalText); }
        }).fail(function() { alert("❌ Errore di rete."); btn.prop("disabled", false).css("opacity", "1").text(originalText); });
    });
});
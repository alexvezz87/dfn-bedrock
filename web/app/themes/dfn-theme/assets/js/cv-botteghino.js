
/* ==========================================================================
   SCRIPT JS BOTTEGHINO LIVE
   ========================================================================== */
jQuery(document).ready(function($) {
    // Variabili localizzate da PHP
    var ajaxSecurityNonce = cvBotteghinoVars.nonce;
    var ajaxurl = cvBotteghinoVars.ajaxurl;

    // Generatore Email Finta
    $("#cv-btn-no-email").on("click", function(e) {
        e.preventDefault();
        var randomNum = Math.floor(1000 + Math.random() * 9000);
        $("#fai_email").val("cassa_" + randomNum + "@fainovara.local").css("background-color", "#fff3cd");
    });

    $("#fai_prodotto").on("change", function() {
        var selectedOption = $(this).find("option:selected");
        var selectedId = $(this).val();
        var stock = selectedOption.data("stock");
        
        if(selectedId && stock !== "" && stock !== 9999) {
            $("#cv-stock-indicator").text("Posti rimasti: " + stock).show();
            $("#fai_qty").data("stock-reale", stock);
        } else {
            $("#cv-stock-indicator").hide();
            $("#fai_qty").removeData("stock-reale");
        }

        if (selectedId == "1627") {
            $("#cv-fai-discount-wrapper").slideUp();
            $("#fai_tessere").val(0);
        } else {
            $("#cv-fai-discount-wrapper").slideDown();
        }
    });
    
    function checkOverbooking() {
        var qty = parseInt($("#fai_qty").val());
        var stock = parseInt($("#fai_qty").data("stock-reale"));
        if (!isNaN(stock) && qty > stock) {
            return confirm("⚠️ ATTENZIONE OVERBOOKING: Stai prenotando " + qty + " biglietti ma rimangono solo " + stock + " posti.\n\nVuoi forzare l'operazione?");
        }
        return true; 
    }

    var form = $("#cv-botteghino-form");
    var methodInput = $("#fai_metodo_pagamento");

    // Bottone Carta
    $("#cv-btn-submit-link").on("click", function(e) {
        e.preventDefault();
        if(form[0].checkValidity()) {
            if(!checkOverbooking()) return;
            methodInput.val("link");
            $(this).text("⏳ Invio...");
            form.submit();
        } else {
            form[0].reportValidity();
        }
    });

    // Bottone Contanti
    $("#cv-btn-submit-cash").on("click", function(e) {
        e.preventDefault();
        if(form[0].checkValidity()) {
            if(!checkOverbooking()) return;
            
            var isAutoCheckin = $("#fai_auto_checkin").is(":checked");
            var autoCheckinMsg = isAutoCheckin ? "\n\n✅ ATTENZIONE: Hai spuntato la validazione automatica. I biglietti non saranno scansionabili all'ingresso." : "";
            
            if(confirm("Confermi di aver incassato l'importo in CONTANTI?" + autoCheckinMsg)) {
                methodInput.val("contanti");
                $(this).text("⏳ Generazione...");
                form.submit();
            }
        } else {
            form[0].reportValidity();
        }
    });

    // Bottone Autorità
    $("#cv-btn-submit-auth").on("click", function(e) {
        e.preventDefault();
        $("#fai_email").removeAttr("required");
        $("#fai_nome").removeAttr("required");
        
        if($("#fai_prodotto").val() === "") {
            alert("Devi selezionare un evento per riservare i posti.");
            return;
        }
        
        if(!checkOverbooking()) {
            $("#fai_email").attr("required", true);
            $("#fai_nome").attr("required", true);
            return;
        }
        
        if(confirm("Confermi di voler bloccare i posti come OMAGGIO PER AUTORITÀ? \nI biglietti verranno scalati e inseriti nel tabellone senza inviare nessuna mail.")) {
            methodInput.val("autorita");
            $(this).text("⏳ Generazione...");
            form.submit();
        } else {
            $("#fai_email").attr("required", true);
            $("#fai_nome").attr("required", true);
        }
    });

    // Select2 per la ricerca clienti
    if ($.fn.selectWoo) {
        $("#fai_customer_search").selectWoo({
            allowClear: true,
            ajax: {
                url: ajaxurl,
                dataType: "json",
                delay: 250,
                data: function (params) {
                    return { action: "cv_search_customers", term: params.term, security: ajaxSecurityNonce };
                },
                processResults: function (data) { return { results: data }; },
                cache: true
            },
            minimumInputLength: 3,
            language: { inputTooShort: function() { return "Scrivi almeno 3 lettere..."; } }
        }).on("select2:select", function (e) {
            var data = e.params.data;
            if(data.id) {
                $.post(ajaxurl, {
                    action: "cv_get_customer_data", security: ajaxSecurityNonce, customer_id: data.id
                }, function(response) {
                    if(response.success) {
                        $("#fai_nome").val(response.data.first_name);
                        $("#fai_cognome").val(response.data.last_name);
                        $("#fai_email").val(response.data.email).css("background-color", "");
                        $("#fai_telefono").val(response.data.phone);
                    }
                });
            }
        }).on("select2:unselect", function(e) {
            $("#fai_nome, #fai_cognome, #fai_email, #fai_telefono").val("");
        });
    }

    // Controlli matematici su Tessere FAI vs Quantità
    $("#fai_tessere").on("change", function() {
        var qty = parseInt($("#fai_qty").val());
        if (parseInt(this.value) > qty) { alert("Tessere superiori ai biglietti!"); this.value = qty; }
    });
    $("#fai_qty").on("change", function() {
        var tessere = parseInt($("#fai_tessere").val());
        if (tessere > parseInt(this.value)) { $("#fai_tessere").val(this.value); }
    });
});
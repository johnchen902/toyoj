(function() {
    "use strict";
    console.log("initial readyState is " + document.readyState);
    if(document.readyState == "loading") {
        document.addEventListener("DOMContentLoaded", domContentLoaded);
    } else {
        domContentLoaded();
    }
    function domContentLoaded() {
        if(window.jQuery) {
            console.log("jQuery available after DOMContentLoaded");
            jQueryReady();
            return;
        }
        console.log("jQuery unavailable after DOMContentLoaded");
        var s = document.getElementById("script-jquery");
        s.addEventListener("load", function(event) {
            if(window.jQuery) {
                console.log("jQuery available after script-jquery loaded");
                jQueryReady();
                return;
            }
            console.error("jQuery unavailable after script-jquery loaded");
        });
    }
    function openDialog(e) {
        if(e[0].showModal) {
            e[0].showModal();
            return;
        }
        // sham
        e.attr("open", true);
        e.css("margin-left", "auto");
        e.css("margin-right", "auto");
        e.css("position", "fixed");
        e.css("top", "50%");
        e.css("transform", "translate(0, -50%)");
    }
    function closeDialog(e) {
        if(e[0].close) {
            e[0].close();
            return;
        }
        // shim
        e.attr("open", false);
    }
    function jQueryReady() {
        $(".dismissible-message").append(
            $("<span>", {"class" : "close-button"})
            .text("\u274c")
            .click(e => $(e.currentTarget).parent().remove())
        );
        $(".dangerous-button").each(function() {
            var dialog = $("#" + $(this).data("dialog-id"));
            var answerField = dialog.find(".answer-field");
            var confirmButton = dialog.find(".confirm-button");
            var cancelButton = dialog.find(".cancel-button");
            $(this).click(() => openDialog(dialog));
            cancelButton.click(() => closeDialog(dialog));
            answerField.bind("propertychange change click keyup input paste",
                    () => confirmButton.prop("disabled", answerField.val() != answerField.data("answer")));
        });
    }
})();

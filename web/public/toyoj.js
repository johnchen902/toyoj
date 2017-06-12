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
    function jQueryReady() {
        $(".dismissible-message").append(
            $("<span>", {"class" : "close-button"})
            .text("\u274c")
            .click(e => $(e.currentTarget).parent().remove())
        );
    }
})();

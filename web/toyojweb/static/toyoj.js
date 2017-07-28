(function() {
    "use strict";
    function handleDialogs() {
        var dialogs = document.getElementsByTagName("dialog");
        if (!dialogs.length)
            return;
        if (!dialogs[0].showModal) {
            var head = document.getElementsByTagName("head")[0];
            var styles = document.createElement("link");
            styles.rel = "stylesheet";
            styles.href = "https://cdnjs.cloudflare.com/ajax/libs/dialog-polyfill/0.4.7/dialog-polyfill.min.css";
            styles.integrity = "sha256-my10UQP6Yx+XEf/gZTpm5/i9DJzvA0NJlHQ+xP9JFHw=";
            styles.crossOrigin = "anonymous";
            head.appendChild(styles);
            var script = document.createElement("script");
            script.src = "https://cdnjs.cloudflare.com/ajax/libs/dialog-polyfill/0.4.7/dialog-polyfill.min.js";
            script.integrity = "sha256-HSjn+BtEjYhbskZXdsYdbwH9mRZK1Rf4iHmltSDDguU=";
            script.crossOrigin = "anonymous";
            head.appendChild(script);
            script.onload = function() {
                Array.prototype.forEach.call(dialogs,
                        dialog => dialogPolyfill.registerDialog(dialog));
                handleDialogs();
            };
            return;
        }
        Array.prototype.forEach.call(
            document.getElementsByClassName("dangerous-button"),
            function (element) {
                var dialog = document.getElementById(element.dataset.dialogId);
                var txtAnswer = dialog.getElementsByClassName("answer-field")[0];
                var btnConfirm = dialog.getElementsByClassName("confirm-button")[0];
                var btnCancel = dialog.getElementsByClassName("cancel-button")[0];
                element.onclick = () => dialog.showModal();
                btnCancel.onclick = () => dialog.close();
                ["propertychange", "change", "click", "keyup", "input", "paste"]
                    .forEach(function(eventType) {
                        txtAnswer.addEventListener(eventType,
                            () => btnConfirm.disabled = txtAnswer.value != txtAnswer.dataset.answer);
                    });
                element.disabled = false;
            });
    }
    function domContentLoaded() {
        Array.prototype.forEach.call(
            document.getElementsByClassName("dismissible-message"),
            function (element) {
                var btnClose = document.createElement("span");
                btnClose.appendChild(document.createTextNode("\u274c"));
                btnClose.className = "close-button";
                btnClose.onclick = () => element.remove();
                element.appendChild(btnClose);
            });
        handleDialogs();
    }
    if(document.readyState == "loading") {
        document.addEventListener("DOMContentLoaded", domContentLoaded);
    } else {
        domContentLoaded();
    }
})();

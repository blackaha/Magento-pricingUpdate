(function () {
    'use strict';

    // Copy the webhook URL to the clipboard.
    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('pricing-updater-copy');
        if (!button) {
            return;
        }

        var field = document.getElementById(button.getAttribute('data-target'));
        var label = button.textContent;

        var confirmCopy = function () {
            button.textContent = button.getAttribute('data-copied');
            window.setTimeout(function () {
                button.textContent = label;
            }, 2000);
        };

        var legacyCopy = function () {
            field.focus();
            field.select();
            if (document.execCommand('copy')) {
                confirmCopy();
            }
        };

        button.addEventListener('click', function () {
            // The Clipboard API is only available on secure origins.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(field.value).then(confirmCopy, legacyCopy);
            } else {
                legacyCopy();
            }
        });
    });
})();

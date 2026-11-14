/* Prompt library admin: autosave of the draft, and confirmations. No framework. */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-promptlib-confirm]');
        if (link && !window.confirm(link.getAttribute('data-promptlib-confirm'))) {
            event.preventDefault();
        }
    });

    var form = document.querySelector('form.promptlib-editor');
    if (!form || !window.promptlibAdmin || form.getAttribute('data-template') === '0') {
        return;
    }
    var status = form.querySelector('.promptlib-autosave');
    var last = new FormData(form);
    var lastText = new URLSearchParams(last).toString();

    window.setInterval(function () {
        var data = new FormData(form);
        var text = new URLSearchParams(data).toString();
        if (text === lastText) {
            return;
        }
        lastText = text;
        data.set('action', 'promptlib_autosave');
        data.set('nonce', window.promptlibAdmin.autosaveNonce);
        data.delete('_wpnonce');
        data.delete('promptlib_action');
        fetch(window.promptlibAdmin.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (body) {
                if (status) {
                    status.textContent = body && body.success ? 'Autosaved at ' + body.data.saved + ' (not published, not seen by consumers).' : 'Autosave failed.';
                }
            })
            .catch(function () {
                if (status) {
                    status.textContent = 'Autosave failed.';
                }
            });
    }, 60000);
}());

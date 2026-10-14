/* Static preview: no requests, authentication or persistent editing. ES5. */
(function () {
    'use strict';
    var doc = document;
    function ancestor(el, attribute) {
        while (el && el !== doc) { if (el.getAttribute && el.hasAttribute(attribute)) return el; el = el.parentNode; }
        return null;
    }
    function feedback(container) {
        var message = container.getAttribute('data-preview-message') || 'Design preview only. Changes are not saved, images are not uploaded and no email is sent. Edit the production site on PHP hosting.';
        var node = container.querySelector('[data-order-feedback], [data-preview-feedback]');
        if (!node) { node = doc.createElement('p'); node.className = 'preview-feedback'; node.setAttribute('data-preview-feedback', ''); node.setAttribute('role', 'status'); node.setAttribute('aria-live', 'polite'); container.appendChild(node); }
        node.textContent = message; node.hidden = false; node.removeAttribute('hidden');
        node.setAttribute('tabindex', '-1'); node.focus();
    }
    doc.addEventListener('click', function (event) {
        var button = ancestor(event.target, 'data-preview-submit');
        if (!button) return;
        event.preventDefault(); event.stopImmediatePropagation();
        var container = ancestor(button, 'data-preview-form');
        if (container) feedback(container);
    }, true);
    doc.addEventListener('submit', function (event) {
        event.preventDefault(); event.stopImmediatePropagation();
        feedback(event.target);
    }, true);
    doc.addEventListener('click', function (event) {
        var blocked = ancestor(event.target, 'data-preview-unavailable');
        if (!blocked) return;
        event.preventDefault(); event.stopImmediatePropagation();
        var notice = doc.getElementById('preview-notice');
        if (notice) notice.focus();
    }, true);
}());

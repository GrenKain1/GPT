(function () {
    'use strict';
    var sequence = Date.now();
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-add-row]') : null;
        if (button) {
            var list = document.querySelector('[data-repeat-list="' + button.getAttribute('data-add-row') + '"]');
            var template = document.getElementById(button.getAttribute('data-template'));
            if (!list || !template || !template.content) return;
            var fragment = template.content.cloneNode(true);
            var fields = fragment.querySelectorAll('[name], [id], [for], [aria-describedby]');
            var token = 'new-' + (++sequence);
            for (var i = 0; i < fields.length; i++) {
                ['name', 'id', 'for', 'aria-describedby'].forEach(function (attribute) {
                    var value = fields[i].getAttribute(attribute);
                    if (value) fields[i].setAttribute(attribute, value.replace(/__INDEX__/g, token));
                });
            }
            var firstInput = fragment.querySelector('input:not([type="hidden"])');
            list.appendChild(fragment);
            if (firstInput) firstInput.focus();
        }
        var confirmation = event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (confirmation && !window.confirm(confirmation.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (input.type !== 'file' || !input.files) return;
        var error = '';
        if (input.files.length > 12) error = 'Select no more than 12 images.';
        for (var i = 0; i < input.files.length; i++) {
            if (input.files[i].size > 5 * 1024 * 1024) error = 'Each image must be smaller than 5 MB.';
            if (input.files[i].type && !/^image\/(jpeg|png|webp)$/.test(input.files[i].type)) error = 'Select JPEG, PNG or WebP images.';
        }
        input.setCustomValidity(error);
        if (error && input.reportValidity) input.reportValidity();
    });
}());

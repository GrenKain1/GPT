/* FantasyDream progressive enhancement. ES5 syntax for older mobile browsers. */
(function () {
    'use strict';
    var root = document.documentElement;
    var doc = document;
    var a11yState = { contrast: false, large: false, motion: false };
    var previousFocus = null;
    var panel = doc.getElementById('accessibility-panel');
    var backdrop = doc.querySelector('[data-panel-backdrop]');
    var panelTrigger = doc.querySelector('[data-accessibility-open]');
    var i;

    function each(items, fn) { for (var n = 0; n < items.length; n++) fn(items[n], n); }
    function hasClass(el, name) { return (' ' + el.className + ' ').indexOf(' ' + name + ' ') !== -1; }
    function addClass(el, name) { if (!hasClass(el, name)) el.className += ' ' + name; }
    function removeClass(el, name) { el.className = (' ' + el.className + ' ').replace(' ' + name + ' ', ' ').replace(/^\s+|\s+$/g, ''); }
    function setHidden(el, value) { if (el) { el.hidden = value; if (value) el.setAttribute('hidden', ''); else el.removeAttribute('hidden'); } }
    function applyA11y() {
        var keys = ['contrast', 'large', 'motion'];
        each(keys, function (key) {
            if (a11yState[key]) addClass(root, 'a11y-' + key); else removeClass(root, 'a11y-' + key);
            var toggle = doc.querySelector('[data-accessibility-setting="' + key + '"]');
            if (toggle) toggle.checked = a11yState[key];
        });
        try { window.localStorage.setItem('fantasydream-accessibility', JSON.stringify(a11yState)); } catch (error) { /* Private browser mode: preferences still apply for this page. */ }
    }
    function closePanel() {
        setHidden(panel, true); setHidden(backdrop, true);
        if (panelTrigger) panelTrigger.setAttribute('aria-expanded', 'false');
        if (previousFocus && previousFocus.focus) previousFocus.focus();
    }
    try {
        var stored = JSON.parse(window.localStorage.getItem('fantasydream-accessibility') || '{}');
        a11yState = { contrast: stored.contrast === true, large: stored.large === true, motion: stored.motion === true };
    } catch (error) { /* Storage is optional. */ }
    applyA11y();

    if (panel && panelTrigger) {
        panelTrigger.addEventListener('click', function () {
            previousFocus = doc.activeElement;
            setHidden(panel, false); setHidden(backdrop, false);
            panelTrigger.setAttribute('aria-expanded', 'true');
            var first = panel.querySelector('button');
            if (first) first.focus(); else panel.focus();
        });
        var closeButton = panel.querySelector('[data-accessibility-close]');
        if (closeButton) closeButton.addEventListener('click', closePanel);
        if (backdrop) backdrop.addEventListener('click', closePanel);
        each(panel.querySelectorAll('[data-accessibility-setting]'), function (toggle) {
            toggle.addEventListener('change', function () { a11yState[toggle.getAttribute('data-accessibility-setting')] = toggle.checked; applyA11y(); });
        });
        var reset = panel.querySelector('[data-accessibility-reset]');
        if (reset) reset.addEventListener('click', function () { a11yState = { contrast: false, large: false, motion: false }; applyA11y(); });
        panel.addEventListener('keydown', function (event) {
            var code = event.keyCode || event.which;
            if (code === 27) { event.preventDefault(); closePanel(); return; }
            if (code !== 9) return;
            var focusable = panel.querySelectorAll('button, input, a[href], [tabindex="0"]');
            if (!focusable.length) return;
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && doc.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && doc.activeElement === last) { event.preventDefault(); first.focus(); }
        });
    }

    var menuButton = doc.querySelector('[data-menu-toggle]');
    var nav = doc.getElementById('site-navigation');
    if (menuButton && nav) {
        menuButton.addEventListener('click', function () {
            var open = menuButton.getAttribute('aria-expanded') !== 'true';
            menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            menuButton.setAttribute('aria-label', menuButton.getAttribute(open ? 'data-close-label' : 'data-open-label'));
            if (open) addClass(nav, 'is-open'); else removeClass(nav, 'is-open');
        });
        nav.addEventListener('keydown', function (event) {
            if ((event.keyCode || event.which) === 27) {
                removeClass(nav, 'is-open'); menuButton.setAttribute('aria-expanded', 'false');
                menuButton.setAttribute('aria-label', menuButton.getAttribute('data-open-label')); menuButton.focus();
            }
        });
    }
    addClass(root, 'js');

    var catalog = doc.querySelector('[data-catalog]');
    if (catalog) {
        var search = catalog.querySelector('[data-catalog-search]');
        var size = catalog.querySelector('[data-catalog-size]');
        var sort = catalog.querySelector('[data-catalog-sort]');
        var grid = catalog.querySelector('[data-catalog-grid]');
        var count = catalog.querySelector('[data-catalog-count]');
        var empty = catalog.querySelector('[data-catalog-empty]');
        if (search && size && sort && grid) {
            setHidden(catalog.querySelector('[data-catalog-controls]'), false);
            var cards = [];
            each(grid.querySelectorAll('[data-product-card]'), function (card) { cards.push(card); });
            function filterCatalog() {
                var query = search.value.toLowerCase().replace(/^\s+|\s+$/g, '');
                var matched = 0;
                cards.sort(function (a, b) {
                    if (sort.value === 'low') return Number(a.getAttribute('data-price')) - Number(b.getAttribute('data-price'));
                    if (sort.value === 'high') return Number(b.getAttribute('data-price')) - Number(a.getAttribute('data-price'));
                    return Number(a.getAttribute('data-order')) - Number(b.getAttribute('data-order'));
                });
                each(cards, function (card) {
                    var labels;
                    try { labels = JSON.parse(card.getAttribute('data-sizes') || '[]'); } catch (error) { labels = []; }
                    var correctSize = !size.value;
                    for (var x = 0; x < labels.length; x++) if (labels[x] === size.value) correctSize = true;
                    var visible = (!query || card.getAttribute('data-search').toLowerCase().indexOf(query) !== -1) && correctSize;
                    setHidden(card, !visible);
                    if (visible) matched++;
                    grid.appendChild(card);
                });
                if (count) count.textContent = String(matched);
                setHidden(empty, matched !== 0);
            }
            search.addEventListener('input', filterCatalog); size.addEventListener('change', filterCatalog); sort.addEventListener('change', filterCatalog);
            var clear = catalog.querySelector('[data-catalog-reset]');
            if (clear) clear.addEventListener('click', function () { search.value = ''; size.value = ''; sort.value = 'default'; filterCatalog(); search.focus(); });
        }
    }

    each(doc.querySelectorAll('[data-gallery]'), function (gallery) {
        var slides = gallery.querySelectorAll('[data-gallery-slide]');
        if (slides.length < 2) return;
        var active = 0;
        var status = gallery.querySelector('[data-gallery-status]');
        var thumbButtons = gallery.querySelectorAll('[data-gallery-index]');
        function showImage(next) {
            active = (next + slides.length) % slides.length;
            each(slides, function (slide, index) { setHidden(slide, index !== active); });
            each(thumbButtons, function (button, index) { button.setAttribute('aria-pressed', index === active ? 'true' : 'false'); });
            if (status) {
                status.textContent = String(active + 1) + ' / ' + slides.length;
                status.setAttribute('aria-label', gallery.getAttribute('data-image-label') + ' ' + (active + 1) + ' ' + gallery.getAttribute('data-of-label') + ' ' + slides.length);
            }
        }
        setHidden(gallery.querySelector('[data-gallery-controls]'), false); setHidden(gallery.querySelector('[data-gallery-thumbs]'), false);
        gallery.querySelector('[data-gallery-prev]').addEventListener('click', function () { showImage(active - 1); });
        gallery.querySelector('[data-gallery-next]').addEventListener('click', function () { showImage(active + 1); });
        each(thumbButtons, function (button) { button.addEventListener('click', function () { showImage(Number(button.getAttribute('data-gallery-index'))); }); });
        var touchStartX = null;
        gallery.addEventListener('touchstart', function (event) { if (event.touches.length === 1) touchStartX = event.touches[0].clientX; }, false);
        gallery.addEventListener('touchend', function (event) {
            if (touchStartX === null || !event.changedTouches.length) return;
            var change = event.changedTouches[0].clientX - touchStartX;
            if (Math.abs(change) > 55) showImage(active + (root.getAttribute('dir') === 'rtl' ? (change > 0 ? 1 : -1) : (change < 0 ? 1 : -1)));
            touchStartX = null;
        }, false);
        showImage(0);
    });

    function encodeForm(form) {
        var pairs = [];
        each(form.elements, function (field) {
            if (!field.name || field.disabled || field.type === 'submit' || field.type === 'button') return;
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
            pairs.push(encodeURIComponent(field.name) + '=' + encodeURIComponent(field.value));
        });
        return pairs.join('&');
    }
    function money(number) {
        var cents = Math.round(number * 100) / 100;
        var text = cents % 1 === 0 ? String(cents) : cents.toFixed(2);
        var parts = text.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return '\u200e₪' + parts.join('.') + '\u200e';
    }
    each(doc.querySelectorAll('[data-order-form]'), function (form) {
        var quantity = form.querySelector('[name="quantity"]');
        var sizes = form.querySelectorAll('[name="size_id"]');
        var total = form.querySelector('[data-order-total]');
        var whatsapp = form.querySelector('[data-order-whatsapp]');
        var feedback = form.querySelector('[data-order-feedback]');
        var submit = form.querySelector('[type="submit"]');
        var sending = false;
        function updateOrder() {
            var selected = null;
            each(sizes, function (option) { if (option.checked) selected = option; });
            if (!selected) return;
            var amount = Number(quantity.value);
            if (!isFinite(amount) || amount < 1 || amount > 20 || amount % 1 !== 0) return;
            if (total) total.textContent = money(Number(selected.getAttribute('data-price')) * amount);
            if (whatsapp) {
                var text = form.getAttribute('data-whatsapp-template').replace('{product}', form.getAttribute('data-product-name')).replace('{size}', selected.getAttribute('data-size-label')).replace('{quantity}', String(amount));
                whatsapp.href = 'https://wa.me/' + form.getAttribute('data-whatsapp-number') + '?text=' + encodeURIComponent(text);
            }
        }
        each(sizes, function (option) { option.addEventListener('change', updateOrder); });
        if (quantity) { quantity.addEventListener('input', updateOrder); quantity.addEventListener('change', updateOrder); }
        updateOrder();
        form.addEventListener('submit', function (event) {
            if (!window.XMLHttpRequest || !submit || !feedback) return;
            event.preventDefault();
            if (sending) return;
            if (form.checkValidity && !form.checkValidity()) { if (form.reportValidity) form.reportValidity(); return; }
            var request = new window.XMLHttpRequest();
            var previousLabel = submit.textContent;
            sending = true;
            submit.disabled = true; submit.textContent = form.getAttribute('data-sending');
            setHidden(feedback, true);
            function finish(ok, message) {
                sending = false; submit.disabled = false; submit.textContent = previousLabel;
                feedback.textContent = message;
                if (ok) removeClass(feedback, 'is-error'); else addClass(feedback, 'is-error');
                setHidden(feedback, false);
                if (ok) { var messageField = form.querySelector('[name="message"]'); if (messageField) messageField.value = ''; }
            }
            request.open('POST', form.action, true);
            request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
            request.setRequestHeader('Accept', 'application/json');
            request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            request.timeout = 30000;
            request.onreadystatechange = function () {
                if (request.readyState !== 4 || !sending) return;
                var result;
                try { result = JSON.parse(request.responseText); } catch (error) { result = null; }
                var ok = request.status >= 200 && request.status < 300 && result && result.ok === true;
                finish(ok, result && typeof result.message === 'string' ? result.message : form.getAttribute(ok ? 'data-success' : 'data-error'));
            };
            request.onerror = function () { if (sending) finish(false, form.getAttribute('data-network-error')); };
            request.ontimeout = function () { if (sending) finish(false, form.getAttribute('data-network-error')); };
            request.send(encodeForm(form));
        });
    });
}());

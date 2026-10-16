/*
 * Live search for the AACP and Studio panels. No dependencies, nothing loaded from outside.
 * 1) Header search: grouped results as you type.
 * 2) List filters: GET forms with a q/search field refresh the page content as you type.
 */
(function () {
    'use strict';

    var DEBOUNCE_HEADER = 150;
    var DEBOUNCE_FILTER = 300;

    function debounce(fn, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fn.apply(null, args); }, wait);
        };
    }

    /* ---------------------------------------------------------- header search */

    function initHeaderSearch(root) {
        var input = root.querySelector('input[type="search"]');
        var panel = root.querySelector('[data-admin-search-panel]');
        if (!input || !panel) { return; }

        var url = root.getAttribute('data-url');
        var cache = new Map();
        var controller = null;
        var items = [];
        var active = -1;

        function close() {
            panel.hidden = true;
            active = -1;
        }

        function setActive(index) {
            if (items.length === 0) { return; }
            active = (index + items.length) % items.length;
            items.forEach(function (el, i) {
                el.classList.toggle('is-active', i === active);
                el.setAttribute('aria-selected', i === active ? 'true' : 'false');
            });
            items[active].scrollIntoView({ block: 'nearest' });
        }

        function message(text) {
            panel.textContent = '';
            var p = document.createElement('p');
            p.className = 'admin-search-message';
            p.textContent = text;
            panel.appendChild(p);
            items = [];
            panel.hidden = false;
        }

        function render(data) {
            panel.textContent = '';
            items = [];
            active = -1;

            if (!data.groups || data.groups.length === 0) {
                message(root.getAttribute('data-empty').replace('{term}', data.term));
                return;
            }

            data.groups.forEach(function (group) {
                var heading = document.createElement('div');
                heading.className = 'admin-search-group';
                heading.textContent = group.label;
                panel.appendChild(heading);

                group.hits.forEach(function (hit) {
                    var a = document.createElement('a');
                    a.href = hit.url;
                    a.className = 'admin-search-hit';
                    a.setAttribute('role', 'option');

                    var title = document.createElement('span');
                    title.className = 'admin-search-title';
                    title.textContent = hit.title;
                    a.appendChild(title);

                    if (hit.subtitle) {
                        var sub = document.createElement('span');
                        sub.className = 'admin-search-sub';
                        sub.textContent = hit.subtitle;
                        a.appendChild(sub);
                    }

                    panel.appendChild(a);
                    items.push(a);
                });
            });

            panel.hidden = false;
        }

        var run = debounce(function () {
            var term = input.value.trim();

            if (term.length < 2) {
                if (term.length === 0) { close(); } else { message(root.getAttribute('data-hint')); }
                return;
            }

            if (cache.has(term)) { render(cache.get(term)); return; }

            if (controller) { controller.abort(); }
            controller = new AbortController();

            fetch(url + '?q=' + encodeURIComponent(term), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal
            })
                .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
                .then(function (data) {
                    if (input.value.trim() !== term) { return; }
                    if (cache.size > 40) { cache.clear(); }
                    cache.set(term, data);
                    render(data);
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') { return; }
                    message(root.getAttribute('data-error'));
                });
        }, DEBOUNCE_HEADER);

        input.addEventListener('input', run);
        input.addEventListener('focus', function () { if (input.value.trim().length >= 2) { run(); } });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
            else if (e.key === 'Escape') { close(); input.blur(); }
            else if (e.key === 'Enter') {
                var target = items[active] || items[0];
                if (target) { e.preventDefault(); window.location.href = target.href; }
            }
        });

        document.addEventListener('click', function (e) { if (!root.contains(e.target)) { close(); } });

        // "/" or Ctrl/Cmd+K focuses the search from anywhere outside form fields.
        document.addEventListener('keydown', function (e) {
            var tag = (document.activeElement && document.activeElement.tagName) || '';
            var typing = tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (document.activeElement && document.activeElement.isContentEditable);
            if ((e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) {
                e.preventDefault();
                input.focus();
                input.select();
            }
        });
    }

    /* ---------------------------------------------------------- list filters */

    // Updates `current` to look like `next`, touching only nodes that differ so scripts bound
    // to the rest of the page keep working and the field being typed in keeps focus.
    function morph(current, next) {
        if (current.nodeType !== next.nodeType || current.nodeName !== next.nodeName) {
            current.replaceWith(document.importNode(next, true));
            return;
        }

        if (current.nodeType !== 1) {
            if (current.nodeValue !== next.nodeValue) { current.nodeValue = next.nodeValue; }
            return;
        }

        if (current.hasAttribute('data-live-skip') || current.isEqualNode(next)) { return; }

        var holdsFocus = current.contains(document.activeElement) && document.activeElement !== current;
        var sameShape = current.childNodes.length === next.childNodes.length;

        if (holdsFocus || sameShape) {
            if (!holdsFocus) { syncAttributes(current, next); }
            if (sameShape) {
                for (var i = 0; i < current.childNodes.length; i++) { morph(current.childNodes[i], next.childNodes[i]); }
            }
            return;
        }

        current.replaceWith(document.importNode(next, true));
    }

    function syncAttributes(current, next) {
        Array.prototype.slice.call(current.attributes).forEach(function (attr) {
            if (!next.hasAttribute(attr.name)) { current.removeAttribute(attr.name); }
        });
        Array.prototype.slice.call(next.attributes).forEach(function (attr) {
            if (current.getAttribute(attr.name) !== attr.value) { current.setAttribute(attr.name, attr.value); }
        });
    }

    function initListFilter(form) {
        var field = form.querySelector('input[name="q"], input[type="search"]');
        if (!field || form.hasAttribute('data-no-live') || form.closest('[data-admin-search]')) { return; }

        var action = new URL(form.getAttribute('action') || window.location.href, window.location.href);
        if (action.pathname !== window.location.pathname) { return; }

        var controller = null;

        var run = debounce(function () {
            var typed = field.value;
            var params = new URLSearchParams(new FormData(form));
            params.delete('page');
            var target = action.pathname + (params.toString() ? '?' + params.toString() : '');

            if (controller) { controller.abort(); }
            controller = new AbortController();
            form.setAttribute('aria-busy', 'true');

            fetch(target, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
                .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.text(); })
                .then(function (html) {
                    // A newer keystroke already moved on; drop this answer.
                    if (field.value !== typed) { return; }

                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var current = document.querySelector('[data-sidebar-main]') || document.querySelector('main');
                    var next = doc.querySelector('[data-sidebar-main]') || doc.querySelector('main');
                    if (!current || !next) { return; }

                    morph(current, next);
                    window.history.replaceState(null, '', target);
                    document.dispatchEvent(new CustomEvent('cp:live-updated', { detail: { region: current } }));
                })
                .catch(function () { /* keep the current results; a normal submit still works */ })
                .then(function () { form.removeAttribute('aria-busy'); });
        }, DEBOUNCE_FILTER);

        field.addEventListener('input', run);
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-admin-search]'), initHeaderSearch);
        Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
            if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') { initListFilter(form); }
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();

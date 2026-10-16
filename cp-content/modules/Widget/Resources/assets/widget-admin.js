/**
 * Studio → Widgets: drag-and-drop ordering, the catalogue search box and confirm prompts.
 * Plain DOM, no dependencies. Order is saved the moment a widget is dropped.
 */
const list = document.querySelector('[data-widget-list]');

if (list) {
    let dragged = null;

    const ids = () => Array.from(list.querySelectorAll('[data-widget-id]')).map((item) => item.dataset.widgetId);

    async function save() {
        const body = new FormData();
        body.set('_token', list.dataset.token);
        body.set('area', list.dataset.area);
        ids().forEach((id) => body.append('ids[]', id));
        try {
            await fetch(list.dataset.reorderUrl, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        } catch (err) {
            console.error('[CPalius Widgets] Could not save the order:', err);
        }
    }

    list.addEventListener('dragstart', (event) => {
        const item = event.target.closest('[data-widget-id]');
        if (!item) {
            return;
        }
        dragged = item;
        item.classList.add('opacity-40');
        event.dataTransfer.effectAllowed = 'move';
        // Firefox needs data to be set for a drag to start.
        event.dataTransfer.setData('text/plain', item.dataset.widgetId);
    });

    list.addEventListener('dragover', (event) => {
        if (!dragged) {
            return;
        }
        event.preventDefault();
        const over = event.target.closest('[data-widget-id]');
        if (!over || over === dragged) {
            return;
        }
        const rect = over.getBoundingClientRect();
        const after = event.clientY > rect.top + rect.height / 2;
        list.insertBefore(dragged, after ? over.nextSibling : over);
    });

    list.addEventListener('dragend', () => {
        if (!dragged) {
            return;
        }
        dragged.classList.remove('opacity-40');
        dragged = null;
        save();
    });
}

// Catalogue search: hides what does not match, and a section left empty.
const search = document.querySelector('[data-widget-search]');
if (search) {
    search.addEventListener('input', () => {
        const needle = search.value.trim().toLowerCase();
        document.querySelectorAll('[data-widget-type]').forEach((form) => {
            form.hidden = needle !== '' && !form.dataset.search.includes(needle);
        });
        document.querySelectorAll('[data-widget-group]').forEach((group) => {
            group.hidden = !group.querySelector('[data-widget-type]:not([hidden])');
        });
    });
}

// Forms that carry data-confirm ask first (delete, apply a ready-made arrangement).
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

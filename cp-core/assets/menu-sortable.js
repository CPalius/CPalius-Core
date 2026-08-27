import Sortable from 'sortablejs';

/**
 * Menü admin ekranındaki [data-sortable-list] listelerini nested-sortable
 * yapar. Sürükleme bitince /admin/menus/{id}/reorder endpoint'ine tüm
 * listelerin güncel (itemId, parentId, sortOrder) durumunu tek bir JSON
 * body olarak POST eder.
 */
const config = document.querySelector('[data-menu-reorder-config]');
if (config) {
    const reorderUrl = config.getAttribute('data-reorder-url');
    const csrfToken = config.getAttribute('data-csrf-token');

    document.querySelectorAll('[data-sortable-list]').forEach((list) => {
        Sortable.create(list, {
            group: 'menu-items',
            handle: '[data-drag-handle]',
            animation: 150,
            onEnd: submitReorder,
        });
    });

    function submitReorder() {
        const payload = [];

        document.querySelectorAll('[data-sortable-list]').forEach((list) => {
            const parentId = list.getAttribute('data-parent-id') || null;

            Array.from(list.children).forEach((li, index) => {
                if (!li.hasAttribute('data-item-id')) {
                    return;
                }

                payload.push({
                    itemId: parseInt(li.getAttribute('data-item-id'), 10),
                    parentId: parentId ? parseInt(parentId, 10) : null,
                    sortOrder: index,
                });
            });
        });

        fetch(reorderUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(payload),
        });
    }
}

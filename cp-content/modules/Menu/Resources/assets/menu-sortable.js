import Sortable from 'sortablejs';

/**
 * Nested-sortable [data-sortable-list] on menu admin; POSTs combined (itemId, parentId, sortOrder) JSON to /admin/menus/{id}/reorder on drop.
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

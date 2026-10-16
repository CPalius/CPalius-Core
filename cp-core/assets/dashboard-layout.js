import Sortable from 'sortablejs';

/**
 * Card layout for the Studio and AACP desks, shared.
 *
 * Cards sit in independent columns ([data-cp-col]). Dragging a card by its grip moves it into the
 * column and spot where it is dropped; no other card changes place. The same layout can be edited
 * in the dialog ([data-cp-layout-modal]): order and column per card, shown or removed, row limit.
 * Both write the same per-user layout through one POST.
 *
 * Markup contract:
 *   root                 data-cp-layout-url, data-cp-layout-csrf, data-label-drag
 *   [data-cp-col]        one per column, data-col="<index>"; holds the cards
 *   card                 data-card-id on the element the grip is added to
 *   [data-cp-layout-modal]  <dialog> with [data-cp-layout-list][data-col] lists of rows
 *                        [data-card-id], each with [data-cp-layout-show] and optional [data-cp-layout-limit]
 */
export function initCardLayout(root) {
    const url = root.dataset.cpLayoutUrl;
    const csrf = root.dataset.cpLayoutCsrf;
    const columns = [...root.querySelectorAll('[data-cp-col]')];
    const modal = root.querySelector('[data-cp-layout-modal]');
    if (!url || !csrf || columns.length === 0) {
        return;
    }

    const lists = modal ? [...modal.querySelectorAll('[data-cp-layout-list]')] : [];
    const rowsOf = (list) => [...list.querySelectorAll('[data-card-id]')];

    const post = async (fields) => {
        const body = new FormData();
        body.append('_token', csrf);
        for (const [key, value] of fields) {
            body.append(key, value);
        }
        const response = await fetch(url, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) {
            throw new Error('layout save failed');
        }
    };

    // The layout exactly as the dialog shows it.
    const fromDialog = () => {
        const fields = [];
        lists.forEach((list) => {
            rowsOf(list).forEach((row) => {
                const id = row.dataset.cardId;
                fields.push(['order[]', id], [`columns[${id}]`, list.dataset.col]);
                if (!row.querySelector('[data-cp-layout-show]').checked) {
                    fields.push(['removed[]', id]);
                }
                const limit = row.querySelector('[data-cp-layout-limit]');
                if (limit && limit.value !== '') {
                    fields.push([`limits[${id}]`, limit.value]);
                }
            });
        });

        return fields;
    };

    // Dragging on the desk only knows the visible cards. Mirror their column and order into the
    // dialog, keep the hidden cards where they were, then save what the dialog now says.
    const syncDialogFromDesk = () => {
        const byId = new Map(lists.flatMap((list) => rowsOf(list)).map((row) => [row.dataset.cardId, row]));
        columns.forEach((column) => {
            const target = lists.find((list) => list.dataset.col === column.dataset.col);
            if (!target) {
                return;
            }
            const visible = [...column.querySelectorAll(':scope > [data-card-id]')].map((card) => byId.get(card.dataset.cardId)).filter(Boolean);
            const firstHidden = rowsOf(target).find((row) => !visible.includes(row));
            visible.forEach((row) => target.insertBefore(row, firstHidden ?? null));
        });
    };

    columns.forEach((column) => {
        column.querySelectorAll(':scope > [data-card-id]').forEach((card) => {
            if (card.querySelector(':scope > [data-cp-grip]')) {
                return;
            }
            const grip = document.createElement('button');
            grip.type = 'button';
            grip.className = 'cp-card-grip';
            grip.setAttribute('data-cp-grip', '');
            grip.title = root.dataset.labelDrag || 'Drag';
            grip.setAttribute('aria-label', grip.title);
            grip.textContent = '⋮⋮';
            card.appendChild(grip);
        });

        Sortable.create(column, {
            group: 'cp-cards',
            handle: '[data-cp-grip]',
            draggable: '[data-card-id]',
            animation: 150,
            ghostClass: 'cp-sort-ghost',
            chosenClass: 'cp-sort-chosen',
            emptyInsertThreshold: 24,
            onEnd: async () => {
                if (lists.length === 0) {
                    return;
                }
                syncDialogFromDesk();
                try {
                    await post(fromDialog());
                } catch {
                    // The new place is on screen; a failed save costs the next load.
                }
            },
        });
    });

    if (!modal) {
        return;
    }

    lists.forEach((list) => Sortable.create(list, { group: 'cp-layout-rows', handle: '[data-cp-layout-grip]', animation: 150 }));

    root.querySelector('[data-cp-layout-open]')?.addEventListener('click', () => modal.showModal());
    modal.querySelectorAll('[data-cp-layout-close]').forEach((button) => {
        button.addEventListener('click', () => modal.close());
    });

    const submit = (button, fields) => async () => {
        button.disabled = true;
        try {
            await post(fields());
            window.location.reload();
        } catch {
            button.disabled = false;
        }
    };

    const saveButton = modal.querySelector('[data-cp-layout-save]');
    saveButton.addEventListener('click', submit(saveButton, fromDialog));
    const resetButton = modal.querySelector('[data-cp-layout-reset]');
    resetButton.addEventListener('click', submit(resetButton, () => [['reset', '1']]));
}

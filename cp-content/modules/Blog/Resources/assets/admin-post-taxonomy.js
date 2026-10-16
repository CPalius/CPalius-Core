/**
 * Studio post form taxonomy, vanilla JS (importmap entrypoint; markup + <template>s live in posts/form.html.twig).
 *
 *  - Categories: create / edit in a <dialog> via AJAX (CategoryAdminController::ajaxCreate / ajaxUpdate);
 *    a new category is appended to the list already ticked.
 *  - Tags: WordPress-style chips with autocomplete over existing tags (PostAdminController::suggestTags).
 *    The original text input stays the submitted field ("a, b, c"); the server reuses a tag whose slug
 *    exists and creates the rest, so nothing here decides what is new.
 *
 * init(root) is idempotent and is re-run by admin-post-form.js after a translation-tab swap.
 */

const csrfFetch = async (url, body) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body,
    });
    let data = null;
    try {
        data = await response.json();
    } catch {
        // Non-JSON answer (login redirect, 500 page): treated as a generic failure below.
    }

    return { ok: response.ok && data?.ok === true, data };
};

/* ------------------------------------------------------------------ categories */

function initCategoryManager(manager, modal) {
    if (manager.__categoryManager) {
        return;
    }
    manager.__categoryManager = true;

    const list = manager.querySelector('[data-category-list]');
    const rowTemplate = manager.querySelector('[data-category-row-template]');
    const fields = {
        name: modal.querySelector('[data-cat-field="name"]'),
        description: modal.querySelector('[data-cat-field="description"]'),
        parent: modal.querySelector('[data-cat-field="parent"]'),
    };
    const errorBox = modal.querySelector('[data-category-error]');
    const title = modal.querySelector('[data-category-modal-title]');
    const saveButton = modal.querySelector('[data-category-save]');

    // id => { name, description, parentId }: names come from the rendered rows, the rest from data-meta.
    const categories = new Map();
    let meta = {};
    try {
        meta = JSON.parse(manager.dataset.meta || '{}');
    } catch {
        meta = {};
    }
    list.querySelectorAll('[data-category-row]').forEach((row) => {
        const id = Number(row.dataset.categoryId);
        categories.set(id, {
            name: row.querySelector('[data-category-label]').textContent.trim(),
            description: meta[id]?.description ?? '',
            parentId: meta[id]?.parentId ?? null,
        });
    });

    const fieldName = () => list.querySelector('input[type="checkbox"]')?.name || manager.dataset.fieldName;
    let editingId = null;

    function showError(message) {
        errorBox.textContent = message;
        errorBox.hidden = message === '';
    }

    function fillParentOptions(selectedId) {
        // Keep the leading "no parent" option; rebuild the rest from the current categories.
        Array.from(fields.parent.options).slice(1).forEach((option) => option.remove());
        categories.forEach((category, id) => {
            if (id === editingId) {
                return;
            }
            const option = new Option(category.name, String(id));
            option.selected = id === selectedId;
            fields.parent.add(option);
        });
        if (selectedId === null) {
            fields.parent.value = '';
        }
    }

    function openModal(id) {
        editingId = id;
        const current = id === null ? null : categories.get(id);
        title.textContent = current ? modal.dataset.titleEdit : modal.dataset.titleCreate;
        fields.name.value = current?.name ?? '';
        fields.description.value = current?.description ?? '';
        fillParentOptions(current?.parentId ?? null);
        showError('');
        modal.showModal();
        fields.name.focus();
    }

    function renderRow(id, category) {
        let row = list.querySelector(`[data-category-row][data-category-id="${id}"]`);
        if (!row) {
            row = rowTemplate.content.firstElementChild.cloneNode(true);
            row.dataset.categoryId = String(id);
            const checkbox = row.querySelector('[data-category-checkbox]');
            checkbox.name = fieldName();
            checkbox.value = String(id);
            checkbox.id = `${manager.dataset.fieldId}_new_${id}`;
            checkbox.checked = true;
            list.appendChild(row);
        }
        row.querySelector('[data-category-label]').textContent = category.name;
    }

    async function save() {
        const name = fields.name.value.trim();
        if (name === '') {
            showError(modal.dataset.errorGeneric);
            fields.name.focus();
            return;
        }

        const body = new FormData();
        body.set('_token', manager.dataset.token);
        body.set('locale', manager.dataset.locale);
        body.set('name', name);
        body.set('description', fields.description.value.trim());
        body.set('parent_id', fields.parent.value);

        const url = editingId === null
            ? manager.dataset.createUrl
            : manager.dataset.updateUrl.replace('/0/ajax', `/${editingId}/ajax`);

        saveButton.disabled = true;
        try {
            const { ok, data } = await csrfFetch(url, body);
            if (!ok) {
                showError(data?.error || modal.dataset.errorGeneric);
                return;
            }
            const saved = data.category;
            categories.set(saved.id, { name: saved.name, description: saved.description, parentId: saved.parentId });
            renderRow(saved.id, saved);
            modal.close();
        } catch (err) {
            console.error('[CPalius Post Form] Category save failed:', err);
            showError(modal.dataset.errorGeneric);
        } finally {
            saveButton.disabled = false;
        }
    }

    manager.addEventListener('click', (event) => {
        if (event.target.closest('[data-category-add]')) {
            openModal(null);
            return;
        }
        const edit = event.target.closest('[data-category-edit]');
        if (edit) {
            openModal(Number(edit.closest('[data-category-row]').dataset.categoryId));
        }
    });

    // The modal is shared by every manager in the container; wire it once, bound to the latest opener.
    modal.__categorySave = save;
}

function wireCategoryModal(modal) {
    if (modal.__wired) {
        return;
    }
    modal.__wired = true;

    modal.querySelector('[data-category-cancel]').addEventListener('click', () => modal.close());
    modal.querySelector('[data-category-save]').addEventListener('click', () => modal.__categorySave?.());
    modal.addEventListener('keydown', (event) => {
        // Enter in a text input saves instead of bubbling anywhere else.
        if (event.key === 'Enter' && event.target.matches('input[type="text"]')) {
            event.preventDefault();
            modal.__categorySave?.();
        }
    });
    // Click on the backdrop (the dialog element itself) closes.
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.close();
        }
    });
}

/* ------------------------------------------------------------------ tags */

function initTagInput(root) {
    if (root.__tagInput) {
        return;
    }
    root.__tagInput = true;

    const source = root.querySelector('[data-tag-source]');
    const ui = root.querySelector('[data-tag-ui]');
    const box = root.querySelector('[data-tag-box]');
    const field = root.querySelector('[data-tag-field]');
    const suggestions = root.querySelector('[data-tag-suggestions]');
    const chipTemplate = root.querySelector('[data-tag-chip-template]');
    const optionTemplate = root.querySelector('[data-tag-option-template]');
    if (!source || !ui || !field) {
        return;
    }

    // { name, chip } in display order; the names are what gets submitted.
    const tags = [];
    let active = -1;
    let timer = null;
    let controller = null;

    const has = (name) => tags.some((tag) => tag.name.toLowerCase() === name.toLowerCase());
    const sync = () => {
        source.value = tags.map((tag) => tag.name).join(', ');
    };

    function addTag(raw) {
        const name = raw.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();
        if (name === '' || has(name)) {
            return;
        }
        const chip = chipTemplate.content.firstElementChild.cloneNode(true);
        const entry = { name, chip };
        tags.push(entry);
        chip.querySelector('[data-tag-chip-label]').textContent = name;
        chip.querySelector('[data-tag-chip-remove]').addEventListener('click', () => {
            tags.splice(tags.indexOf(entry), 1);
            chip.remove();
            sync();
            field.focus();
        });
        box.insertBefore(chip, field);
        sync();
    }

    function closeSuggestions() {
        suggestions.hidden = true;
        suggestions.replaceChildren();
        active = -1;
    }

    function highlight(index) {
        const items = Array.from(suggestions.children);
        items.forEach((item, i) => item.classList.toggle('bg-neutral-100', i === index));
        active = index;
    }

    function showSuggestions(names) {
        suggestions.replaceChildren();
        names.filter((name) => !has(name)).forEach((name) => {
            const item = optionTemplate.content.firstElementChild.cloneNode(true);
            item.textContent = name;
            // mousedown (not click) so it fires before the field's blur commits the half-typed text.
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                field.value = '';
                addTag(name);
                closeSuggestions();
                field.focus();
            });
            suggestions.appendChild(item);
        });
        suggestions.hidden = suggestions.children.length === 0;
        active = -1;
    }

    function requestSuggestions() {
        const query = field.value.trim();
        if (query === '') {
            closeSuggestions();
            return;
        }

        controller?.abort();
        controller = new AbortController();
        const url = `${root.dataset.suggestUrl}?q=${encodeURIComponent(query)}&locale=${encodeURIComponent(root.dataset.locale || '')}`;
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, signal: controller.signal })
            .then((response) => (response.ok ? response.json() : { tags: [] }))
            .then((data) => showSuggestions(Array.isArray(data.tags) ? data.tags : []))
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    closeSuggestions();
                }
            });
    }

    // Take over: the plain field is replaced by the chip box, its value seeds the chips.
    source.value.split(',').forEach(addTag);
    source.hidden = true;
    ui.hidden = false;
    box.addEventListener('click', () => field.focus());

    field.addEventListener('input', () => {
        // A pasted / typed comma commits what precedes it.
        if (field.value.includes(',')) {
            const parts = field.value.split(',');
            field.value = parts.pop();
            parts.forEach(addTag);
        }
        window.clearTimeout(timer);
        timer = window.setTimeout(requestSuggestions, 180);
    });

    field.addEventListener('keydown', (event) => {
        const items = suggestions.children;
        if (event.key === 'ArrowDown' && items.length > 0) {
            event.preventDefault();
            highlight((active + 1) % items.length);
        } else if (event.key === 'ArrowUp' && items.length > 0) {
            event.preventDefault();
            highlight((active - 1 + items.length) % items.length);
        } else if (event.key === 'Enter') {
            // Never submit the post form from the tag box.
            event.preventDefault();
            addTag(active >= 0 ? items[active].textContent : field.value);
            field.value = '';
            closeSuggestions();
        } else if (event.key === 'Escape') {
            closeSuggestions();
        } else if (event.key === 'Backspace' && field.value === '' && tags.length > 0) {
            tags.pop().chip.remove();
            sync();
        }
    });

    // Half-typed text must not be lost when the author clicks Save.
    field.addEventListener('blur', () => {
        addTag(field.value);
        field.value = '';
        closeSuggestions();
    });
}

function init(root) {
    const scope = root || document;
    const modal = scope.querySelector('[data-category-modal]') ?? document.querySelector('[data-category-modal]');

    if (modal) {
        wireCategoryModal(modal);
        scope.querySelectorAll('[data-category-manager]').forEach((manager) => initCategoryManager(manager, modal));
    }
    scope.querySelectorAll('[data-tag-input]').forEach(initTagInput);
}

window.CPaliusPostTaxonomy = { init };
init(document);

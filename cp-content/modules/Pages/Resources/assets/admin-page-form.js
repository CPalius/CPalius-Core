/**
 * Studio page form: slug preview + per-page ACF builder.
 * Vanilla JS, same zero-dependency approach as admin-post-form.js.
 */
function slugifyPreview(value) {
    const turkishMap = { ç: 'c', Ç: 'c', ğ: 'g', Ğ: 'g', ı: 'i', İ: 'i', ö: 'o', Ö: 'o', ş: 's', Ş: 's', ü: 'u', Ü: 'u' };

    return value
        .replace(/[çÇğĞıİöÖşŞüÜ]/g, (char) => turkishMap[char] ?? char)
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function keyifyPreview(value) {
    return slugifyPreview(value).replace(/-/g, '_').replace(/^(\d)/, 'f_$1');
}

function parseJsonAttr(value, fallback) {
    if (!value) {
        return fallback;
    }
    try {
        return JSON.parse(value);
    } catch {
        return fallback;
    }
}

function fieldId() {
    return Math.random().toString(16).slice(2, 10);
}

function initSlugPreview(root) {
    const form = root.querySelector('[data-page-form]') || root.querySelector('form');
    if (!form) {
        return;
    }

    const titleInput = form.querySelector('[data-page-form-target="title"]');
    const slugInput = form.querySelector('[data-page-form-target="slug"]');
    if (!titleInput || !slugInput) {
        return;
    }

    let slugManuallyEdited = slugInput.value.trim() !== '';
    slugInput.addEventListener('input', () => {
        slugManuallyEdited = true;
    });
    titleInput.addEventListener('input', () => {
        if (!slugManuallyEdited) {
            slugInput.value = slugifyPreview(titleInput.value);
        }
    });
}

function initSeoCharCounters(root) {
    root.querySelectorAll('[data-seo-char-counter]').forEach((textarea) => {
        const counter = textarea.closest('div')?.querySelector('[data-seo-char-count]');
        if (!counter) {
            return;
        }
        textarea.addEventListener('input', () => {
            counter.textContent = String(textarea.value.length);
        });
    });
}

function initAcfBuilder(root) {
    const jsonInput = root.querySelector('[data-page-acf-json]');
    const list = root.querySelector('[data-page-acf-list]');
    const addButton = root.querySelector('[data-page-acf-add]');
    const typeSelect = root.querySelector('[data-page-acf-new-type]');
    if (!jsonInput || !list) {
        return;
    }

    const schemaOnly = root.getAttribute('data-acf-schema-only') === '1';
    const i18n = parseJsonAttr(root.getAttribute('data-acf-i18n'), {});
    const typeLabels = {};
    root.querySelectorAll('[data-page-acf-new-type] option').forEach((option) => {
        typeLabels[option.value] = option.textContent.trim();
    });

    let fields = parseJsonAttr(jsonInput.value, []);
    if (!Array.isArray(fields)) {
        fields = [];
    }

    function sync() {
        jsonInput.value = JSON.stringify(fields);
    }

    function collectFromDom() {
        list.querySelectorAll('[data-acf-row]').forEach((row, index) => {
            if (!fields[index]) {
                return;
            }
            fields[index].label = row.querySelector('[data-acf-label]')?.value?.trim() || fields[index].label;
            fields[index].key = keyifyPreview(row.querySelector('[data-acf-key]')?.value || fields[index].key);
            fields[index].required = Boolean(row.querySelector('[data-acf-required]')?.checked);
            const choices = row.querySelector('[data-acf-choices]')?.value || '';
            fields[index].choices = choices
                .split(/\r?\n|,/)
                .map((part) => part.trim())
                .filter(Boolean);
            const valueInput = row.querySelector('[data-acf-value]');
            if (valueInput) {
                if (valueInput.type === 'checkbox') {
                    fields[index].value = valueInput.checked;
                } else if (fields[index].type === 'gallery') {
                    fields[index].value = valueInput.value
                        .split(',')
                        .map((part) => Number.parseInt(part.trim(), 10))
                        .filter((id) => Number.isFinite(id) && id > 0);
                } else if (fields[index].type === 'image' || fields[index].type === 'number') {
                    const numeric = Number.parseInt(valueInput.value, 10);
                    fields[index].value = Number.isFinite(numeric) ? numeric : null;
                } else {
                    fields[index].value = valueInput.value;
                }
            }
        });
        sync();
    }

    function renderValueControl(field) {
        if (schemaOnly) {
            return '';
        }

        const type = field.type;
        const value = field.value ?? '';

        if (type === 'textarea' || type === 'wysiwyg') {
            const editorAttr = type === 'wysiwyg' ? ' data-cpeditor data-acf-wysiwyg' : '';
            return `<textarea data-acf-value class="form-control" rows="4"${editorAttr}>${escapeHtml(String(value ?? ''))}</textarea>`;
        }
        if (type === 'checkbox') {
            return `<label class="flex items-center gap-2 text-fs-sm"><input type="checkbox" data-acf-value class="form-check-input h-4 w-4" ${value ? 'checked' : ''}> ${escapeHtml(i18n.active || '')}</label>`;
        }
        if (type === 'select') {
            const options = (field.choices || []).map((choice) => {
                const selected = String(value) === String(choice) ? ' selected' : '';
                return `<option value="${escapeAttr(choice)}"${selected}>${escapeHtml(choice)}</option>`;
            }).join('');
            return `<select data-acf-value class="form-control"><option value=""></option>${options}</select>`;
        }
        if (type === 'image') {
            return `<div class="flex flex-wrap items-end gap-2">
                <input type="number" data-acf-value data-target-input="acf_${field.id}" class="form-control" value="${escapeAttr(value ?? '')}" placeholder="${escapeAttr(i18n.asset_id || '')}">
                <button type="button" class="btn btn-sm btn-neutral" data-media-picker-trigger data-target-input="acf_${field.id}" data-target-preview="acf-preview-${field.id}">${escapeHtml(i18n.pick_image || '')}</button>
            </div>
            <div data-acf-preview-${field.id} class="mt-1"></div>`;
        }
        if (type === 'gallery') {
            const joined = Array.isArray(value) ? value.join(',') : String(value || '');
            return `<input type="text" data-acf-value class="form-control" value="${escapeAttr(joined)}" placeholder="${escapeAttr(i18n.gallery_placeholder || '')}">`;
        }
        if (type === 'date') {
            return `<input type="date" data-acf-value class="form-control" value="${escapeAttr(value ?? '')}">`;
        }
        if (type === 'number') {
            return `<input type="number" data-acf-value class="form-control" value="${escapeAttr(value ?? '')}">`;
        }
        if (type === 'email') {
            return `<input type="email" data-acf-value class="form-control" value="${escapeAttr(value ?? '')}">`;
        }
        if (type === 'url') {
            return `<input type="url" data-acf-value class="form-control" value="${escapeAttr(value ?? '')}">`;
        }

        return `<input type="text" data-acf-value class="form-control" value="${escapeAttr(value ?? '')}">`;
    }

    function render() {
        list.innerHTML = fields.map((field, index) => {
            const typeLabel = typeLabels[field.type] || field.type;
            const choicesVisible = field.type === 'select' ? '' : 'hidden';
            return `<div class="rounded-lg border border-neutral-200 p-sp-sm space-y-2" data-acf-row data-index="${index}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="badge badge-neutral">${escapeHtml(typeLabel)}</span>
                    <div class="flex gap-1">
                        <button type="button" class="btn btn-sm btn-neutral" data-acf-up>↑</button>
                        <button type="button" class="btn btn-sm btn-neutral" data-acf-down>↓</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-acf-remove>×</button>
                    </div>
                </div>
                <div class="grid gap-2 md:grid-cols-2">
                    <div>
                        <label class="form-label">${escapeHtml(i18n.label || '')}</label>
                        <input type="text" data-acf-label class="form-control" value="${escapeAttr(field.label || '')}">
                    </div>
                    <div>
                        <label class="form-label">${escapeHtml(i18n.key || '')}</label>
                        <input type="text" data-acf-key class="form-control font-mono text-fs-xs" value="${escapeAttr(field.key || '')}">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-fs-sm">
                    <input type="checkbox" data-acf-required class="form-check-input h-4 w-4" ${field.required ? 'checked' : ''}>
                    ${escapeHtml(i18n.required || '')}
                </label>
                <div class="${choicesVisible}">
                    <label class="form-label">${escapeHtml(i18n.choices || '')}</label>
                    <textarea data-acf-choices class="form-control" rows="2">${escapeHtml((field.choices || []).join('\n'))}</textarea>
                </div>
                ${schemaOnly ? '' : `<div><label class="form-label">${escapeHtml(i18n.value || '')}</label>${renderValueControl(field)}</div>`}
            </div>`;
        }).join('');

        list.querySelectorAll('[data-acf-wysiwyg]').forEach((textarea) => {
            if (textarea.__cpEditor || !window.CPaliusCpEditor?.init) {
                return;
            }
            window.CPaliusCpEditor.init(textarea).catch(() => {});
        });
    }

    list.addEventListener('click', (event) => {
        const row = event.target.closest('[data-acf-row]');
        if (!row) {
            return;
        }
        collectFromDom();
        const index = Number.parseInt(row.getAttribute('data-index') || '-1', 10);
        if (event.target.closest('[data-acf-remove]')) {
            fields.splice(index, 1);
            render();
            sync();
            return;
        }
        if (event.target.closest('[data-acf-up]') && index > 0) {
            const current = fields[index];
            fields[index] = fields[index - 1];
            fields[index - 1] = current;
            render();
            sync();
            return;
        }
        if (event.target.closest('[data-acf-down]') && index < fields.length - 1) {
            const current = fields[index];
            fields[index] = fields[index + 1];
            fields[index + 1] = current;
            render();
            sync();
        }
    });

    list.addEventListener('input', collectFromDom);
    list.addEventListener('change', collectFromDom);

    addButton?.addEventListener('click', () => {
        collectFromDom();
        const type = typeSelect?.value || 'text';
        fields.push({
            id: fieldId(),
            key: `field_${fields.length + 1}`,
            type,
            label: typeLabels[type] || type,
            required: false,
            choices: [],
            value: type === 'checkbox' ? false : (type === 'gallery' ? [] : ''),
        });
        render();
        sync();
    });

    const applyGroup = root.querySelector('[data-page-acf-apply-group]');
    const groupSelect = root.querySelector('[data-page-acf-group]');
    applyGroup?.addEventListener('click', () => {
        const option = groupSelect?.selectedOptions?.[0];
        if (!option?.dataset.fields) {
            return;
        }
        const incoming = parseJsonAttr(option.dataset.fields, []);
        if (!Array.isArray(incoming) || incoming.length === 0) {
            return;
        }
        collectFromDom();
        const existingByKey = Object.fromEntries(fields.map((field) => [field.key, field]));
        incoming.forEach((schema) => {
            const key = schema.key || keyifyPreview(schema.label || 'field');
            if (existingByKey[key]) {
                existingByKey[key].label = schema.label || existingByKey[key].label;
                existingByKey[key].type = schema.type || existingByKey[key].type;
                existingByKey[key].required = Boolean(schema.required);
                existingByKey[key].choices = schema.choices || [];
                return;
            }
            fields.push({
                id: schema.id || fieldId(),
                key,
                type: schema.type || 'text',
                label: schema.label || key,
                required: Boolean(schema.required),
                choices: schema.choices || [],
                value: existingByKey[key]?.value ?? '',
            });
        });
        const groupIdInput = root.querySelector('[name$="[fieldGroupId]"]');
        if (groupIdInput && groupSelect.value) {
            groupIdInput.value = groupSelect.value;
        }
        render();
        sync();
    });

    const form = jsonInput.closest('form');
    form?.addEventListener('submit', () => {
        list.querySelectorAll('[data-acf-wysiwyg]').forEach((textarea) => {
            if (textarea.__cpEditor?.getData) {
                textarea.value = textarea.__cpEditor.getData();
            }
        });
        collectFromDom();
    });

    render();
    sync();
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function escapeAttr(value) {
    return escapeHtml(value).replaceAll("'", '&#39;');
}

document.querySelectorAll('[data-page-form-root]').forEach((root) => {
    initSlugPreview(root);
    initSeoCharCounters(root);
    initAcfBuilder(root);
});

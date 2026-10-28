/**
 * AACP translation editor: one language at a time, next to the text it translates.
 *
 * Data comes from <script type="application/json" data-cpl-data>; rows are drawn 40 at a time. Every string
 * from the data goes into the page through textContent / value, never innerHTML. A value is saved when the
 * box loses focus (or Ctrl/Cmd+Enter); a failed save puts the old text back, so the screen never claims a
 * save that did not happen.
 */
const PAGE = 40;

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function init(root) {
    const csrf = root.dataset.localizationCsrf;
    const updateUrl = root.dataset.localizationUpdateUrl;
    const t = (key) => root.dataset[`i18n${key}`] ?? '';

    let data;
    try {
        data = JSON.parse(root.parentElement.querySelector('[data-cpl-data]').textContent);
    } catch {
        return;
    }

    const locales = data.locales;
    const entries = data.entries;
    const byCode = Object.fromEntries(locales.map((l) => [l.code, l]));
    const defaultCode = (locales.find((l) => l.default) ?? locales[0] ?? {}).code;
    const focus = root.dataset.localizationFocus;

    const ui = {
        target: root.querySelector('[data-cpl-target]'),
        source: root.querySelector('[data-cpl-source]'),
        bar: root.querySelector('[data-cpl-bar]'),
        progress: root.querySelector('[data-cpl-progress-text]'),
        status: root.querySelector('[data-cpl-status]'),
        missingCount: root.querySelector('[data-cpl-missing-count]'),
        search: root.querySelector('[data-localization-filter]'),
        group: root.querySelector('[data-localization-group-filter]'),
        list: root.querySelector('[data-cpl-list]'),
        count: root.querySelector('[data-localization-count]'),
        more: root.querySelector('[data-cpl-more]'),
    };

    const state = {
        target: byCode[focus] ? focus : (locales.find((l) => l.code !== defaultCode) ?? locales[0] ?? {}).code,
        source: defaultCode,
        status: 'all',
        q: '',
        group: '',
        shown: PAGE,
    };

    if (state.target === state.source && locales.length > 1) {
        state.target = locales.find((l) => l.code !== state.source).code;
    }

    const text = (entry, code) => (entry.v[code] ?? '').toString();
    const isMissing = (entry) => text(entry, state.target).trim() === '';
    // Nothing to translate when the original itself is empty.
    const translatable = (entry) => text(entry, state.source).trim() !== '';

    function filtered() {
        const q = state.q.toLowerCase();

        return entries.filter((entry) => {
            if (state.group !== '' && entry.g !== state.group) {
                return false;
            }
            if (state.status === 'missing' && !(isMissing(entry) && translatable(entry))) {
                return false;
            }
            if (state.status === 'done' && isMissing(entry)) {
                return false;
            }
            if (q === '') {
                return true;
            }

            return `${entry.k} ${text(entry, state.source)} ${text(entry, state.target)}`.toLowerCase().includes(q);
        });
    }

    function fillSelect(select, value) {
        select.replaceChildren(...locales.map((l) => {
            const option = el('option', '', `${l.name} (${l.code.toUpperCase()})${l.default ? ' ★' : ''}`);
            option.value = l.code;
            option.selected = l.code === value;

            return option;
        }));
    }

    function updateProgress() {
        const todo = entries.filter(translatable);
        const missing = todo.filter(isMissing).length;
        const done = todo.length - missing;
        const percent = todo.length === 0 ? 100 : Math.floor((done * 100) / todo.length);

        ui.bar.style.width = `${percent}%`;
        ui.progress.textContent = `${percent}% · ${done} / ${todo.length}`;
        ui.missingCount.textContent = missing > 0 ? String(missing) : '';
    }

    function row(entry) {
        const original = text(entry, state.source);
        const orig = el('div', 'cpl-orig');
        orig.append(
            el('div', `cpl-orig__text${original.trim() === '' ? ' is-empty' : ''}`, original.trim() === '' ? t('SourceEmpty') : original),
            el('div', 'cpl-meta', `${entry.g.replace(/^core:/, '').replace('messages+intl-icu', 'messages')} · ${entry.k}`),
        );

        const area = document.createElement('textarea');
        area.value = text(entry, state.target);
        area.rows = Math.min(6, Math.max(1, Math.ceil(original.length / 60)));
        area.placeholder = t('Placeholder');
        area.lang = state.target;
        area.setAttribute('aria-label', entry.k);

        const stateLine = el('div', 'cpl-state');
        const dot = el('span', 'cpl-dot');
        const label = el('span');
        stateLine.append(dot, label);

        const edit = el('div', 'cpl-edit');
        edit.append(area, stateLine);

        const wrapper = el('div', 'cpl-row');
        wrapper.classList.toggle('is-missing', isMissing(entry));

        let saved = area.value;
        const save = async () => {
            const value = area.value;
            if (value === saved) {
                return;
            }

            const body = new FormData();
            body.set('_token', csrf);
            body.set('group', entry.g);
            body.set('key', entry.k);
            body.set('locale', state.target);
            body.set('value', value);

            try {
                const response = await fetch(updateUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(result.error || t('Error'));
                }

                entry.v[state.target] = value;
                saved = value;
                wrapper.classList.remove('is-error');
                wrapper.classList.add('is-saved');
                wrapper.classList.toggle('is-missing', value.trim() === '');
                label.textContent = t('Saved');
                updateProgress();
            } catch (error) {
                area.value = saved;
                wrapper.classList.remove('is-saved');
                wrapper.classList.add('is-error');
                label.textContent = error.message;
            }
        };

        area.addEventListener('blur', save);
        area.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                save();
            }
        });

        wrapper.append(orig, edit);

        return wrapper;
    }

    function render() {
        const rows = filtered();
        const visible = rows.slice(0, state.shown);

        ui.list.replaceChildren(...(visible.length === 0 ? [el('div', 'cpl-empty', t('Empty'))] : visible.map(row)));
        ui.count.textContent = t('Shown').replace('{shown}', String(visible.length)).replace('{total}', String(rows.length));
        ui.more.hidden = rows.length <= visible.length;
        ui.more.textContent = t('More');
        ui.status.querySelectorAll('button').forEach((b) => b.classList.toggle('is-active', b.dataset.value === state.status));
        updateProgress();
    }

    function reset() {
        state.shown = PAGE;
        render();
    }

    fillSelect(ui.target, state.target);
    fillSelect(ui.source, state.source);

    ui.target.addEventListener('change', () => { state.target = ui.target.value; reset(); });
    ui.source.addEventListener('change', () => { state.source = ui.source.value; reset(); });
    ui.status.addEventListener('click', (event) => {
        const value = event.target.closest('button')?.dataset.value;
        if (value) {
            state.status = value;
            reset();
        }
    });
    ui.group.addEventListener('change', () => { state.group = ui.group.value; reset(); });

    let timer = null;
    ui.search.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => { state.q = ui.search.value.trim(); reset(); }, 200);
    });
    ui.more.addEventListener('click', () => { state.shown += PAGE; render(); });

    // A language with gaps opens on its gaps: that is nearly always why someone is here.
    if (entries.some((e) => translatable(e) && isMissing(e))) {
        state.status = 'missing';
    }

    render();

    // ---------------------------------------------------------------- import
    const importTrigger = document.querySelector('[data-localization-import-trigger]');
    const importInput = document.querySelector('[data-localization-import-input]');
    const importUrl = root.dataset.localizationImportUrl;

    if (importTrigger && importInput && importUrl) {
        importTrigger.addEventListener('click', () => importInput.click());
        importInput.addEventListener('change', async () => {
            const file = importInput.files && importInput.files[0];
            if (!file) {
                return;
            }

            try {
                const body = new FormData();
                body.set('_token', csrf);
                body.set('file', file);
                const response = await fetch(importUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(result.error || t('Error'));
                }
                window.location.reload();
            } catch (error) {
                ui.count.textContent = error.message;
            } finally {
                importInput.value = '';
            }
        });
    }
}

document.querySelectorAll('[data-localization-root]').forEach(init);

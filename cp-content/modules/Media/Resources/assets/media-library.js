/**
 * Studio media library: folders, upload, move, rename, alt text, favourites,
 * recents, trash. Talks to MediaLibraryApiController; everything user-supplied
 * (file and folder names, alt text) goes into the DOM through textContent / value,
 * never innerHTML.
 */

const VIEW_KEY = 'cp.media.view';

function h(tag, props = {}, ...children) {
    const el = document.createElement(tag);

    for (const [key, value] of Object.entries(props)) {
        if (value === null || value === undefined || value === false) {
            continue;
        }
        if (key === 'class') {
            el.className = value;
        } else if (key.startsWith('on')) {
            el.addEventListener(key.slice(2), value);
        } else if (key === 'dataset') {
            Object.assign(el.dataset, value);
        } else if (key in el && key !== 'list') {
            el[key] = value;
        } else {
            el.setAttribute(key, value === true ? '' : value);
        }
    }

    for (const child of children.flat()) {
        if (child !== null && child !== undefined && child !== false) {
            el.append(child.nodeType ? child : document.createTextNode(String(child)));
        }
    }

    return el;
}

function init(root) {
    const cfg = JSON.parse(root.dataset.config);
    const t = (key, vars = {}) => (cfg.t[key] ?? key).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m));

    const state = {
        nav: 'all', // all | favorites | recent | trash
        folder: null,
        q: '',
        type: '',
        sort: 'date_desc',
        view: localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid',
        page: 1,
        folders: [],
        assets: [],
        crumbs: [],
        hasMore: false,
        tree: [],
        selected: new Set(),
        lastClicked: null,
        busy: false,
    };

    const el = {
        nav: root.querySelector('[data-nav]'),
        tree: root.querySelector('[data-tree]'),
        search: root.querySelector('[data-search]'),
        type: root.querySelector('[data-type]'),
        sort: root.querySelector('[data-sort]'),
        view: root.querySelector('[data-view]'),
        crumbs: root.querySelector('[data-crumbs]'),
        bulk: root.querySelector('[data-bulk]'),
        body: root.querySelector('[data-body]'),
        status: root.querySelector('[data-status]'),
        details: root.querySelector('[data-details]'),
        file: root.querySelector('[data-upload-input]'),
        // These two live in the page header, outside the library root.
        newFolder: document.querySelector('[data-new-folder]'),
        uploadBtn: document.querySelector('[data-upload-btn]'),
        toasts: h('div', { class: 'cpm-toast' }),
    };
    root.append(el.toasts);

    // ---------------------------------------------------------------- helpers

    const toast = (message, error = false) => {
        const node = h('div', { class: error ? 'is-error' : '' }, message);
        el.toasts.append(node);
        window.setTimeout(() => node.remove(), error ? 6000 : 2800);
    };

    async function api(path, { method = 'GET', body } = {}) {
        const headers = { Accept: 'application/json' };
        if (method !== 'GET') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = cfg.csrf;
        }

        const response = await fetch(cfg.api + path, {
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.error || t('error.generic'));
        }

        return data;
    }

    const run = async (action) => {
        try {
            return await action();
        } catch (error) {
            toast(error.message, true);

            return undefined;
        }
    };

    const formatSize = (bytes) => {
        if (bytes < 1024) {
            return `${bytes} B`;
        }
        const units = ['KB', 'MB', 'GB'];
        let value = bytes / 1024;
        let i = 0;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i += 1;
        }

        return `${value.toFixed(value < 10 ? 1 : 0)} ${units[i]}`;
    };

    const formatDate = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
    const ext = (name) => (name.includes('.') ? name.split('.').pop().slice(0, 4).toUpperCase() : 'FILE');
    const inTrash = () => state.nav === 'trash';
    const itemById = (id) => state.assets.find((a) => a.id === id);

    function dialog(title, content, buttons) {
        return new Promise((resolve) => {
            const close = (value) => {
                modal.remove();
                resolve(value);
            };
            const foot = h('div', { class: 'cpm-foot' }, buttons(close));
            const modal = h('div', { class: 'cpm-modal', onmousedown: (e) => e.target === modal && close(undefined) },
                h('div', { class: 'cpm-dialog', role: 'dialog', 'aria-modal': 'true' }, h('h2', {}, title), content, foot));
            document.body.append(modal);
            modal.querySelector('input,button')?.focus();
        });
    }

    function promptName(title, value = '') {
        const input = h('input', { type: 'text', value, maxLength: 150, 'aria-label': title });
        return dialog(title, input, (close) => [
            h('button', { class: 'cpm-btn', type: 'button', onclick: () => close(undefined) }, t('cancel')),
            h('button', { class: 'cpm-btn is-primary', type: 'button', onclick: () => close(input.value) }, t('ok')),
        ]);
    }

    function pickFolder(title, disabledIds = []) {
        let chosen = null;
        const holder = h('div', { class: 'cpm-tree' });
        const draw = () => {
            holder.replaceChildren(treeNode({ id: null, name: t('root') }, 0, true));
            renderTreeInto(holder, null, 1, (id) => {
                chosen = id;
                draw();
            }, () => chosen, disabledIds);
        };
        const treeNode = (folder, depth) => h('button', {
            type: 'button',
            class: chosen === folder.id ? 'is-active' : '',
            onclick: () => {
                chosen = folder.id;
                draw();
            },
        }, '📁 ', folder.name);
        draw();

        return dialog(title, holder, (close) => [
            h('button', { class: 'cpm-btn', type: 'button', onclick: () => close(undefined) }, t('cancel')),
            h('button', { class: 'cpm-btn is-primary', type: 'button', onclick: () => close({ id: chosen }) }, t('move_here')),
        ]);
    }

    function renderTreeInto(container, parentId, depth, onPick, current, disabledIds = []) {
        for (const folder of state.tree.filter((f) => f.parentId === parentId && !disabledIds.includes(f.id))) {
            const button = h('button', {
                type: 'button',
                class: current() === folder.id ? 'is-active' : '',
                onclick: () => onPick(folder.id),
            }, h('span', { class: 'cpm-indent', style: `width:${depth * 0.9}rem` }), '📁 ', folder.name);
            container.append(button);
            renderTreeInto(container, folder.id, depth + 1, onPick, current, disabledIds);
        }
    }

    // ---------------------------------------------------------------- loading

    async function loadTree() {
        const data = await run(() => api('/tree'));
        if (data) {
            state.tree = data.folders;
            renderTree();
        }
    }

    async function load({ append = false } = {}) {
        if (!append) {
            state.page = 1;
        }
        state.busy = true;

        const query = new URLSearchParams({ page: String(state.page) });
        let data;

        if (inTrash()) {
            data = await run(() => api(`/trash?${query}`));
            if (data) {
                state.assets = (append ? state.assets : []).concat(data.items.map((i) => ({
                    id: i.id, name: i.name, mime: i.mime, size: i.size, deletedAt: i.deletedAt, expiresAt: i.expiresAt, kind: 'document', trash: true,
                })));
                state.folders = [];
                state.crumbs = [];
                state.hasMore = data.items.length >= cfg.perPage;
            }
        } else {
            if (state.folder !== null) {
                query.set('folder', String(state.folder));
            }
            query.set('filter', state.nav);
            query.set('q', state.q);
            query.set('type', state.type);
            query.set('sort', state.sort);
            data = await run(() => api(`/browse?${query}`));
            if (data) {
                state.assets = append ? state.assets.concat(data.assets) : data.assets;
                state.folders = append ? state.folders : data.folders;
                state.crumbs = data.breadcrumbs;
                state.hasMore = data.hasMore;
            }
        }

        state.busy = false;
        if (!append) {
            state.selected.clear();
        }
        render();
    }

    // -------------------------------------------------------------- rendering

    function renderNav() {
        const items = [['all', 'nav.all'], ['favorites', 'nav.favorites'], ['recent', 'nav.recent']];
        if (cfg.canDelete) {
            items.push(['trash', 'nav.trash']);
        }
        el.nav.replaceChildren(...items.map(([key, label]) => h('button', {
            type: 'button',
            class: state.nav === key ? 'is-active' : '',
            onclick: () => go({ nav: key, folder: null }),
        }, { all: '🖼 ', favorites: '★ ', recent: '🕘 ', trash: '🗑 ' }[key], t(label))));
    }

    function renderTree() {
        const root_ = h('button', {
            type: 'button',
            class: state.nav === 'all' && state.folder === null ? 'is-active' : '',
            onclick: () => go({ nav: 'all', folder: null }),
            ondragover: dropOver,
            ondragleave: dropLeave,
            ondrop: (e) => dropOn(e, null),
        }, '📁 ', t('root'));
        el.tree.replaceChildren(root_);

        const walk = (parentId, depth) => {
            for (const f of state.tree.filter((n) => n.parentId === parentId)) {
                el.tree.append(h('button', {
                    type: 'button',
                    class: state.nav === 'all' && state.folder === f.id ? 'is-active' : '',
                    onclick: () => go({ nav: 'all', folder: f.id }),
                    ondragover: dropOver,
                    ondragleave: dropLeave,
                    ondrop: (e) => dropOn(e, f.id),
                }, h('span', { class: 'cpm-indent', style: `width:${depth * 0.9}rem` }), '📁 ', f.name));
                walk(f.id, depth + 1);
            }
        };
        walk(null, 1);
    }

    function renderCrumbs() {
        const parts = [];
        const link = (label, id) => h('button', { type: 'button', onclick: () => go({ folder: id }) }, label);

        if (state.nav !== 'all') {
            parts.push(h('strong', {}, t(`nav.${state.nav}`)));
        } else {
            parts.push(link(t('root'), null));
            for (const crumb of state.crumbs) {
                parts.push(h('span', { class: 'cpm-sep' }, '›'), link(crumb.name, crumb.id));
            }
        }
        el.crumbs.replaceChildren(...parts);
    }

    function folderCard(folder) {
        const actions = [];
        if (cfg.canUpload) {
            actions.push(h('button', { class: 'cpm-btn', type: 'button', title: t('rename_folder'), onclick: (e) => { e.stopPropagation(); renameFolder(folder); } }, '✎'));
        }
        if (cfg.canDelete) {
            actions.push(h('button', { class: 'cpm-btn', type: 'button', title: t('delete_folder'), onclick: (e) => { e.stopPropagation(); deleteFolder(folder); } }, '✕'));
        }
        const icon = h('span', { class: 'cpm-ficon' }, '📁');
        if (folder.color) {
            icon.style.setProperty('color', folder.color);
        }

        return h('div', {
            class: 'cpm-item cpm-folder',
            onclick: () => go({ folder: folder.id }),
            ondragover: dropOver,
            ondragleave: dropLeave,
            ondrop: (e) => dropOn(e, folder.id),
        }, icon, h('span', { class: 'cpm-name' }, folder.name), h('small', {}, String(folder.files)), ...actions);
    }

    function assetCard(asset) {
        const selected = state.selected.has(asset.id);
        const thumb = h('div', { class: 'cpm-thumb' });
        if (asset.thumb) {
            thumb.append(h('img', { src: asset.thumb, alt: asset.alt || '', loading: 'lazy' }));
        } else {
            thumb.append(h('span', { class: 'cpm-ext' }, asset.kind === 'video' ? '▶' : ext(asset.name)));
        }

        return h('div', {
            class: `cpm-item${selected ? ' is-selected' : ''}`,
            draggable: !asset.trash && cfg.canUpload,
            dataset: { id: asset.id },
            title: asset.name,
            onclick: (e) => select(asset, e),
            ondblclick: () => !asset.trash && openAsset(asset),
            ondragstart: (e) => dragStart(e, asset),
        },
        h('input', { class: 'cpm-check', type: 'checkbox', checked: selected, onclick: (e) => { e.stopPropagation(); toggle(asset); }, 'aria-label': asset.name }),
        thumb,
        h('div', { class: 'cpm-name' }, asset.name),
        h('div', { class: 'cpm-meta' }, formatSize(asset.size || 0)),
        asset.favorite ? h('span', { class: 'cpm-star' }, '★') : null);
    }

    function renderBody() {
        const nodes = [];

        if (state.folders.length > 0) {
            nodes.push(h('div', { class: 'cpm-grid' }, state.folders.map(folderCard)));
        }
        if (state.assets.length > 0) {
            nodes.push(h('div', { class: 'cpm-grid' }, state.assets.map(assetCard)));
        }
        if (nodes.length === 0) {
            nodes.push(h('div', { class: 'cpm-empty' }, inTrash() ? t('empty_trash') : t('empty')));
        }
        if (state.hasMore) {
            nodes.push(h('button', { class: 'cpm-btn cpm-more', type: 'button', onclick: () => { state.page += 1; load({ append: true }); } }, t('load_more')));
        }

        el.body.replaceChildren(...nodes);
    }

    function renderBulk() {
        const ids = [...state.selected];
        el.bulk.hidden = ids.length === 0;
        if (ids.length === 0) {
            return;
        }

        const btn = (label, onclick, cls = '') => h('button', { class: `cpm-btn ${cls}`, type: 'button', onclick }, label);
        const parts = [h('strong', {}, t('selected', { count: ids.length }))];

        if (inTrash()) {
            parts.push(btn(t('restore'), () => restore(ids)), btn(t('delete_forever'), () => deleteForever(ids), 'is-danger'));
        } else {
            const allFav = ids.every((id) => itemById(id)?.favorite);
            parts.push(
                cfg.canUpload ? btn(t('move'), () => moveSelected(ids)) : null,
                btn(allFav ? t('unfavorite') : t('favorite'), () => favorite(ids, !allFav)),
                btn(t('download'), () => download(ids)),
                cfg.canDelete ? btn(t('delete'), () => trashSelected(ids), 'is-danger') : null,
            );
        }
        parts.push(btn(t('clear_selection'), () => { state.selected.clear(); render(); }));
        el.bulk.replaceChildren(...parts);
    }

    function renderStatus() {
        const bits = [t('status.files', { count: state.assets.length })];
        if (state.folders.length > 0) {
            bits.unshift(t('status.folders', { count: state.folders.length }));
        }
        const total = state.assets.reduce((sum, a) => sum + (a.size || 0), 0);
        el.status.replaceChildren(h('span', {}, bits.join(' · ')), h('span', {}, formatSize(total)));
    }

    function renderDetails() {
        const ids = [...state.selected];
        const asset = ids.length === 1 ? itemById(ids[0]) : null;
        root.classList.toggle('has-details', asset !== null);
        el.details.hidden = asset === null;

        if (asset === null) {
            return;
        }

        const preview = h('div', { class: 'cpm-preview' });
        if (asset.trash) {
            preview.append(h('span', { class: 'cpm-ext' }, ext(asset.name)));
        } else if (asset.kind === 'image') {
            preview.append(h('img', { src: asset.thumb || asset.url, alt: asset.alt || '' }));
        } else if (asset.kind === 'video') {
            preview.append(h('video', { src: asset.url, controls: true, preload: 'metadata' }));
        } else {
            preview.append(h('span', { class: 'cpm-ext' }, ext(asset.name)));
        }

        const rows = [[t('props.type'), asset.mime], [t('props.size'), formatSize(asset.size || 0)]];
        if (asset.trash) {
            rows.push([t('props.deleted'), formatDate(asset.deletedAt)], [t('props.expires'), asset.expiresAt ? formatDate(asset.expiresAt) : '—']);
        } else {
            rows.push([t('props.uploaded'), formatDate(asset.createdAt)]);
            if (asset.width && asset.height) {
                rows.push([t('props.dimensions'), `${asset.width} × ${asset.height}`]);
            }
        }

        const nodes = [preview];

        if (asset.trash) {
            nodes.push(h('strong', {}, asset.name));
        } else {
            const name = h('input', { type: 'text', value: asset.name, maxLength: 255, disabled: !cfg.canUpload });
            name.addEventListener('change', () => saveField(`/assets/${asset.id}/rename`, { name: name.value }, () => { asset.name = name.value.trim(); renderBody(); }));
            nodes.push(h('label', {}, t('rename')), name);

            if (asset.kind === 'image') {
                const alt = h('textarea', { rows: 2, maxLength: 255, disabled: !cfg.canUpload }, asset.alt || '');
                alt.addEventListener('change', () => saveField(`/assets/${asset.id}/alt`, { alt: alt.value }, () => { asset.alt = alt.value.trim(); }));
                nodes.push(h('label', {}, t('alt_text')), alt, h('div', { class: 'cpm-note' }, t('alt_help')));
            }
        }

        nodes.push(h('dl', {}, rows.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])));

        const actions = h('div', { class: 'cpm-actions' });
        if (asset.trash) {
            actions.append(
                h('button', { class: 'cpm-btn is-primary', type: 'button', onclick: () => restore([asset.id]) }, t('restore')),
                h('button', { class: 'cpm-btn is-danger', type: 'button', onclick: () => deleteForever([asset.id]) }, t('delete_forever')),
            );
        } else {
            actions.append(
                h('button', { class: 'cpm-btn', type: 'button', onclick: () => copyLink(asset) }, t('copy_link')),
                cfg.canUpload ? h('button', { class: 'cpm-btn', type: 'button', onclick: () => makeCopy(asset) }, t('make_copy')) : null,
                h('button', { class: 'cpm-btn', type: 'button', onclick: () => favorite([asset.id], !asset.favorite) }, asset.favorite ? t('unfavorite') : t('favorite')),
                cfg.canUpload ? h('button', { class: 'cpm-btn', type: 'button', onclick: () => moveSelected([asset.id]) }, t('move')) : null,
                h('button', { class: 'cpm-btn', type: 'button', onclick: () => download([asset.id]) }, t('download')),
                cfg.canDelete ? h('button', { class: 'cpm-btn is-danger', type: 'button', onclick: () => trashSelected([asset.id]) }, t('delete')) : null,
            );
        }
        nodes.push(actions);

        if (!asset.trash) {
            const usage = h('ul', { class: 'cpm-usage' });
            nodes.push(h('label', {}, t('usage.title')), usage, h('div', { class: 'cpm-note' }, t('usage.note')));
            api(`/assets/${asset.id}/usage`).then((data) => {
                if (!state.selected.has(asset.id)) {
                    return;
                }
                usage.replaceChildren(...(data.usage.length === 0
                    ? [h('li', {}, t('usage.none'))]
                    : data.usage.map((u) => h('li', {}, u.kind === 'avatar'
                        ? t('usage.avatar', { label: u.label })
                        : t('usage.content', { label: u.label, field: u.field || '' })))));
            }).catch(() => undefined);
        }

        el.details.replaceChildren(...nodes);
    }

    function render() {
        renderNav();
        renderTree();
        renderCrumbs();
        renderBody();
        renderBulk();
        renderStatus();
        renderDetails();
        root.classList.toggle('is-list', state.view === 'list');
        el.view.querySelectorAll('button').forEach((b) => b.classList.toggle('is-active', b.dataset.mode === state.view));
        el.crumbs.hidden = inTrash();
        el.newFolder?.toggleAttribute('hidden', state.nav !== 'all');
        el.uploadBtn?.toggleAttribute('hidden', inTrash());
        root.querySelector('[data-empty-trash]')?.toggleAttribute('hidden', !inTrash());
        // Search/filter/sort do not apply to the trash list.
        [el.search, el.type, el.sort].forEach((c) => { c.disabled = inTrash(); });
        el.body.dataset.dropzone = t('dropzone');
    }

    // ---------------------------------------------------------------- actions

    function go(patch) {
        Object.assign(state, patch);
        if (patch.nav !== undefined && patch.nav !== 'all') {
            state.q = '';
            el.search.value = '';
        }
        load();
    }

    function select(asset, event) {
        if (event.shiftKey && state.lastClicked !== null) {
            const ids = state.assets.map((a) => a.id);
            const [from, to] = [ids.indexOf(state.lastClicked), ids.indexOf(asset.id)].sort((a, b) => a - b);
            ids.slice(from, to + 1).forEach((id) => state.selected.add(id));
        } else if (event.ctrlKey || event.metaKey) {
            toggle(asset);

            return;
        } else {
            state.selected.clear();
            state.selected.add(asset.id);
        }
        state.lastClicked = asset.id;
        render();
    }

    function toggle(asset) {
        if (state.selected.has(asset.id)) {
            state.selected.delete(asset.id);
        } else {
            state.selected.add(asset.id);
        }
        state.lastClicked = asset.id;
        render();
    }

    function openAsset(asset) {
        window.open(asset.url, '_blank', 'noopener');
        run(() => api(`/assets/${asset.id}/opened`, { method: 'POST', body: {} }));
    }

    async function saveField(path, body, onDone) {
        const result = await run(() => api(path, { method: 'POST', body }));
        if (result) {
            onDone();
            toast(t('saved'));
        }
    }

    async function copyLink(asset) {
        const url = new URL(asset.url, window.location.origin).href;
        try {
            await navigator.clipboard.writeText(url);
            toast(t('link_copied'));
        } catch {
            window.prompt(t('copy_link'), url);
        }
    }

    async function makeCopy(asset) {
        if (await run(() => api(`/assets/${asset.id}/copy`, { method: 'POST', body: {} }))) {
            toast(t('copy_made'));
            await load();
        }
    }

    async function favorite(ids, value) {
        if (await run(() => api('/assets/favorite', { method: 'POST', body: { ids, value } }))) {
            ids.forEach((id) => { const a = itemById(id); if (a) { a.favorite = value; } });
            if (state.nav === 'favorites' && !value) {
                await load();
            } else {
                render();
            }
        }
    }

    async function moveSelected(ids) {
        const choice = await pickFolder(t('move_title'));
        if (choice === undefined) {
            return;
        }
        if (await run(() => api('/assets/move', { method: 'POST', body: { ids, folder: choice.id } }))) {
            await Promise.all([load(), loadTree()]);
        }
    }

    async function trashSelected(ids) {
        if (!window.confirm(t('confirm.delete', { count: ids.length }))) {
            return;
        }
        if (await run(() => api('/assets/delete', { method: 'POST', body: { ids } }))) {
            await Promise.all([load(), loadTree()]);
        }
    }

    async function restore(ids) {
        const result = await run(() => api('/trash/restore', { method: 'POST', body: { ids } }));
        if (result) {
            result.errors.forEach((message) => toast(message, true));
            toast(t('restored', { count: result.restored }));
            await Promise.all([load(), loadTree()]);
        }
    }

    async function deleteForever(ids) {
        if (!window.confirm(t('confirm.delete_forever', { count: ids.length }))) {
            return;
        }
        if (await run(() => api('/trash/delete', { method: 'POST', body: { ids } }))) {
            await load();
        }
    }

    async function emptyTrash() {
        if (window.confirm(t('confirm.empty_trash')) && await run(() => api('/trash/empty', { method: 'POST', body: {} }))) {
            await load();
        }
    }

    async function createFolder() {
        const name = await promptName(t('new_folder'));
        if (name === undefined) {
            return;
        }
        if (await run(() => api('/folders', { method: 'POST', body: { name, parent: state.folder } }))) {
            await Promise.all([load(), loadTree()]);
        }
    }

    async function renameFolder(folder) {
        const name = await promptName(t('rename_folder'), folder.name);
        if (name === undefined || name === folder.name) {
            return;
        }
        if (await run(() => api(`/folders/${folder.id}/rename`, { method: 'POST', body: { name } }))) {
            await Promise.all([load(), loadTree()]);
        }
    }

    async function deleteFolder(folder) {
        if (!window.confirm(t('confirm.delete_folder'))) {
            return;
        }
        if (await run(() => api(`/folders/${folder.id}/delete`, { method: 'POST', body: {} }))) {
            if (state.folder === folder.id) {
                state.folder = null;
            }
            await Promise.all([load(), loadTree()]);
        }
    }

    function download(ids) {
        const form = h('form', { method: 'post', action: cfg.downloadUrl, hidden: true },
            h('input', { type: 'hidden', name: '_token', value: cfg.csrf }),
            ...ids.map((id) => h('input', { type: 'hidden', name: 'ids[]', value: String(id) })));
        document.body.append(form);
        form.submit();
        form.remove();
    }

    // ------------------------------------------------------- drag & drop, upload

    const dragStart = (event, asset) => {
        const ids = state.selected.has(asset.id) ? [...state.selected] : [asset.id];
        event.dataTransfer.setData('application/x-cpm-assets', JSON.stringify(ids));
        event.dataTransfer.effectAllowed = 'move';
    };

    function dropOver(event) {
        if (event.dataTransfer.types.includes('application/x-cpm-assets')) {
            event.preventDefault();
            event.currentTarget.classList.add('is-drop');
        }
    }

    function dropLeave(event) {
        event.currentTarget.classList.remove('is-drop');
    }

    async function dropOn(event, folderId) {
        event.currentTarget.classList.remove('is-drop');
        const raw = event.dataTransfer.getData('application/x-cpm-assets');
        if (!raw) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        if (await run(() => api('/assets/move', { method: 'POST', body: { ids: JSON.parse(raw), folder: folderId } }))) {
            await Promise.all([load(), loadTree()]);
        }
    }

    // One file per request so each gets its own progress bar (XMLHttpRequest: fetch has no upload progress).
    function sendFile(file, onProgress) {
        return new Promise((resolve, reject) => {
            const body = new FormData();
            body.append('file', file);
            if (state.nav === 'all' && state.folder !== null) {
                body.append('folder', String(state.folder));
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.uploadUrl);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    onProgress(event.loaded / event.total);
                }
            });
            xhr.addEventListener('load', () => {
                let data = {};
                try {
                    data = JSON.parse(xhr.responseText);
                } catch {
                    // Not JSON: fall through to the generic message below.
                }
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(data);
                } else {
                    reject(new Error(data.error || data.detail || data.message || t('upload_failed', { name: file.name })));
                }
            });
            xhr.addEventListener('error', () => reject(new Error(t('up.err_network'))));
            xhr.addEventListener('abort', () => reject(new Error(t('up.err_network'))));
            xhr.send(body);
        });
    }

    const uploadPanel = (() => {
        const list = h('div', { class: 'cpm-up-list' });
        const summary = h('span', { class: 'cpm-up-summary' });
        const close = h('button', { class: 'cpm-up-x', type: 'button', title: t('up.close'), 'aria-label': t('up.close'), onclick: () => panel.setAttribute('hidden', '') }, '×');
        const panel = h('div', { class: 'cpm-uploads', hidden: true, role: 'status', 'aria-live': 'polite' },
            h('div', { class: 'cpm-up-head' }, h('strong', {}, t('up.title')), summary, close), list);
        root.append(panel);

        return { panel, list, summary };
    })();

    function queueRow(file) {
        const bar = h('span', { class: 'cpm-up-fill' });
        const status = h('span', { class: 'cpm-up-status' }, t('up.waiting'));
        const node = h('div', { class: 'cpm-up-row' },
            h('div', { class: 'cpm-up-top' }, h('span', { class: 'cpm-up-name', title: file.name }, file.name), status),
            h('div', { class: 'cpm-up-meta' }, `${formatSize(file.size)} · ${file.type || t('up.type_unknown')}`),
            h('div', { class: 'cpm-up-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-label': file.name }, bar));
        uploadPanel.list.append(node);

        return {
            node,
            progress(fraction) {
                const percent = Math.round(fraction * 100);
                bar.style.width = `${percent}%`;
                node.querySelector('[role=progressbar]').setAttribute('aria-valuenow', String(percent));
                status.textContent = fraction >= 1 ? t('up.sending') : `${percent}%`;
            },
            done() {
                bar.style.width = '100%';
                node.classList.add('is-done');
                status.textContent = t('up.done');
            },
            fail(message) {
                node.classList.add('is-error');
                status.textContent = t('up.error');
                node.append(h('div', { class: 'cpm-up-err' }, message));
            },
        };
    }

    // The uploaded row goes into the list right away; the reload afterwards only confirms it.
    function showUploaded(asset) {
        const here = state.nav === 'all' && state.q === '' && (asset.folderId ?? null) === state.folder;
        if (here && !state.assets.some((a) => a.id === asset.id)) {
            state.assets.unshift(asset);
            render();
        }
    }

    async function upload(files) {
        const list = [...files];
        if (list.length === 0 || !cfg.canUpload || inTrash()) {
            return;
        }

        uploadPanel.panel.removeAttribute('hidden');
        uploadPanel.list.querySelectorAll('.is-done').forEach((n) => n.remove());
        const rows = list.map((file) => ({ file, ui: queueRow(file) }));
        let finished = 0;
        let uploaded = 0;
        const paint = () => { uploadPanel.summary.textContent = t('up.summary', { done: finished, total: rows.length }); };
        paint();

        for (const { file, ui } of rows) {
            if (cfg.maxBytes > 0 && file.size > cfg.maxBytes) {
                ui.fail(t('up.err_size', { limit: formatSize(cfg.maxBytes) }));
            } else {
                try {
                    const data = await sendFile(file, ui.progress);
                    ui.done();
                    uploaded += 1;
                    if (data.asset) {
                        showUploaded(data.asset);
                    }
                } catch (error) {
                    ui.fail(error.message);
                }
            }
            finished += 1;
            paint();
        }

        if (uploaded > 0) {
            await Promise.all([load(), loadTree()]);
        }
    }

    el.body.addEventListener('dragover', (event) => {
        if (event.dataTransfer.types.includes('Files') && !inTrash()) {
            event.preventDefault();
            el.body.classList.add('is-dragover');
        }
    });
    el.body.addEventListener('dragleave', (event) => {
        if (event.target === el.body) {
            el.body.classList.remove('is-dragover');
        }
    });
    el.body.addEventListener('drop', (event) => {
        el.body.classList.remove('is-dragover');
        if (event.dataTransfer.files.length > 0) {
            event.preventDefault();
            upload(event.dataTransfer.files);
        }
    });

    // ------------------------------------------------------------------ wiring

    el.file?.addEventListener('change', () => {
        upload(el.file.files).finally(() => { el.file.value = ''; });
    });
    el.uploadBtn?.addEventListener('click', () => el.file?.click());
    el.newFolder?.addEventListener('click', createFolder);
    root.querySelector('[data-empty-trash]')?.addEventListener('click', emptyTrash);

    let searchTimer = null;
    el.search.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
            state.q = el.search.value.trim();
            load();
        }, 300);
    });
    el.type.addEventListener('change', () => { state.type = el.type.value; load(); });
    el.sort.addEventListener('change', () => { state.sort = el.sort.value; load(); });
    el.view.addEventListener('click', (event) => {
        const mode = event.target.closest('button')?.dataset.mode;
        if (mode) {
            state.view = mode;
            localStorage.setItem(VIEW_KEY, mode);
            render();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.target.closest('input, textarea, select, [role=dialog]')) {
            return;
        }
        if (event.key === 'Escape' && state.selected.size > 0) {
            state.selected.clear();
            render();
        } else if (event.key === 'Delete' && state.selected.size > 0) {
            const ids = [...state.selected];
            if (inTrash()) {
                deleteForever(ids);
            } else if (cfg.canDelete) {
                trashSelected(ids);
            }
        }
    });

    if (!cfg.canDelete) {
        root.querySelector('[data-empty-trash]')?.remove();
    }

    render();
    loadTree();
    load();
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-media-lib]');
    if (root) {
        init(root);
    }
});

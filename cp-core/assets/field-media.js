/**
 * Field API widgets: image/file fields store a Media asset id in a hidden input (preview + "choose from library"
 * + "remove"; without the Media picker the input stays a plain asset id), and multi-value fields get add/remove
 * buttons. Loaded after media-picker on forms that render custom fields.
 */
const META_URL = (id) => '/admin/media/api/assets/' + id + '/meta';

function build(input) {
    const kind = input.dataset.fieldMedia === 'file' ? 'file' : 'image';
    const wrap = document.createElement('div');
    wrap.className = 'mb-sp-sm flex flex-wrap items-center gap-3';
    if (input.dataset.fieldLabel) {
        const label = document.createElement('label');
        label.className = 'mb-1.5 block w-full text-fs-xs font-medium text-neutral-500';
        label.textContent = input.dataset.fieldLabel;
        wrap.appendChild(label);
    }
    wrap.insertAdjacentHTML('beforeend', '<div data-fm-preview class="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded border border-neutral-300 text-fs-xs text-neutral-500"></div>'
        + '<div class="flex flex-col items-start gap-2"><span data-fm-name class="max-w-xs truncate text-fs-sm text-neutral-600"></span>'
        + '<div class="flex gap-2"><button type="button" class="btn btn-sm btn-neutral" data-fm-pick>Medyadan seç</button>'
        + '<button type="button" class="btn btn-sm btn-outline-danger" data-fm-clear hidden>Kaldır</button></div></div>');
    input.insertAdjacentElement('afterend', wrap);

    const preview = wrap.querySelector('[data-fm-preview]');
    const name = wrap.querySelector('[data-fm-name]');
    const clear = wrap.querySelector('[data-fm-clear]');

    const show = (asset) => {
        preview.textContent = '';
        if (!asset) {
            preview.textContent = kind === 'image' ? 'Görsel yok' : 'Dosya yok';
            name.textContent = '';
            clear.hidden = true;
            return;
        }
        if (kind === 'image' || String(asset.mime || '').startsWith('image/')) {
            const img = document.createElement('img');
            img.src = asset.url;
            img.alt = '';
            img.className = 'h-full w-full object-cover';
            preview.appendChild(img);
        } else {
            preview.textContent = 'Dosya';
        }
        name.textContent = asset.name || '#' + asset.id;
        clear.hidden = false;
    };

    wrap.querySelector('[data-fm-pick]').addEventListener('click', () => {
        window.CPaliusMediaPicker.open((asset) => {
            input.value = asset.id;
            show({ id: asset.id, url: asset.url, name: asset.originalName, mime: kind === 'image' ? 'image/*' : '' });
        });
    });
    clear.addEventListener('click', () => {
        input.value = '';
        show(null);
    });

    show(null);
    if (input.value) {
        fetch(META_URL(encodeURIComponent(input.value)), { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((asset) => show(asset))
            .catch(() => show(null));
    }
}

function init() {
    document.querySelectorAll('input[data-field-media]').forEach((input) => {
        if (input.dataset.fmReady) {
            return;
        }
        input.dataset.fmReady = '1';
        if (!window.CPaliusMediaPicker) {
            input.type = 'number';
            input.className = 'form-control';
            return;
        }
        build(input);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

/**
 * Multi-value fields (CollectionType): remove buttons on each entry and an "add" button that clones
 * Symfony's data-prototype. New entries get their media widgets built too.
 */
function initCollections() {
    document.querySelectorAll('[data-prototype]').forEach((box) => {
        if (box.dataset.fcReady) {
            return;
        }
        box.dataset.fcReady = '1';
        let next = box.children.length;

        const decorate = (entry) => {
            entry.classList.add('mb-2', 'flex', 'items-start', 'gap-2');
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger shrink-0';
            remove.textContent = '×';
            remove.title = 'Kaldır';
            remove.addEventListener('click', () => entry.remove());
            entry.appendChild(remove);
        };

        [...box.children].forEach(decorate);

        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'btn btn-sm btn-neutral';
        add.textContent = '+ Ekle';
        add.addEventListener('click', () => {
            const holder = document.createElement('div');
            holder.innerHTML = box.dataset.prototype.replace(/__name__/g, String(next++));
            const entry = holder.firstElementChild;
            if (!entry) {
                return;
            }
            box.appendChild(entry);
            decorate(entry);
            init();
            entry.querySelector('input,select,textarea')?.focus();
        });
        box.insertAdjacentElement('afterend', add);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCollections);
} else {
    initCollections();
}

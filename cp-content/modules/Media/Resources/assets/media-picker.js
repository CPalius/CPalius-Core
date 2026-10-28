/**
 * Shared media-picker bridge: featured-image buttons and the editor
 * upload flow both use window.CPaliusMediaPicker.
 * User-facing copy comes from #cp-media-picker-i18n (Studio layout) with English fallbacks.
 */
(function () {
    let modalEl = null;
    let onSelectCallback = null;
    let i18nCache = null;
    // Multi mode (open(cb, {multiple: true})): clicks toggle items and the callback gets an array on confirm.
    let multiple = false;
    const picked = new Map();

    function t(key) {
        if (i18nCache === null) {
            const el = document.getElementById('cp-media-picker-i18n');
            try {
                i18nCache = el ? JSON.parse(el.textContent || '{}') : {};
            } catch (e) {
                i18nCache = {};
            }
        }

        const fallbacks = {
            title: 'Media Library',
            close: 'Close',
            loading: 'Loading…',
            uploadFailed: 'Upload failed.',
            listFailed: 'Could not load media list.',
            confirm: 'Add selected',
            selected: 'selected',
        };

        return i18nCache[key] || fallbacks[key] || key;
    }

    function ensureModal() {
        if (modalEl) {
            return modalEl;
        }

        modalEl = document.createElement('dialog');
        modalEl.setAttribute('data-media-picker-modal', '');
        modalEl.className = 'w-full max-w-3xl rounded-sm border border-cp-border p-0 backdrop:bg-cp-darkest/60';
        modalEl.innerHTML = '<div class="flex items-center justify-between border-b border-cp-border p-sp-sm">'
            + '<p class="text-fs-sm font-semibold text-cp-text">' + t('title') + '</p>'
            + '<button type="button" data-media-picker-close class="text-fs-sm text-cp-steel hover:text-cp-text">' + t('close') + '</button>'
            + '</div>'
            + '<div data-media-picker-body></div>'
            + '<div data-media-picker-footer hidden class="flex items-center justify-between gap-sp-sm border-t border-cp-border p-sp-sm">'
            + '<span data-media-picker-count class="text-fs-sm text-cp-steel"></span>'
            + '<button type="button" data-media-picker-confirm class="btn btn-sm btn-primary">' + t('confirm') + '</button>'
            + '</div>';

        document.body.appendChild(modalEl);

        modalEl.querySelector('[data-media-picker-close]').addEventListener('click', () => close());

        modalEl.querySelector('[data-media-picker-confirm]').addEventListener('click', () => {
            const assets = [...picked.values()];
            if (onSelectCallback && assets.length > 0) {
                onSelectCallback(assets);
            }
            close();
        });

        modalEl.addEventListener('click', (event) => {
            const item = event.target.closest('[data-media-item]');
            if (!item) {
                return;
            }

            const asset = {
                id: item.getAttribute('data-asset-id'),
                url: item.getAttribute('data-asset-url'),
                originalName: item.getAttribute('data-asset-name'),
            };

            if (!multiple) {
                selectAsset(asset);
                return;
            }

            if (picked.has(asset.id)) {
                picked.delete(asset.id);
            } else {
                picked.set(asset.id, asset);
            }
            markPicked();
        });

        modalEl.addEventListener('submit', (event) => {
            const form = event.target.closest('[data-media-picker-search]');
            if (!form) {
                return;
            }
            event.preventDefault();
            const query = new FormData(form).get('q') || '';
            loadBody(query);
        });

        modalEl.addEventListener('change', (event) => {
            const input = event.target.closest('[data-media-picker-upload]');
            if (!input || !input.files || !input.files[0]) {
                return;
            }

            uploadFromPicker(input.files[0], input);
        });

        return modalEl;
    }

    function setUploadStatus(message, isError) {
        const status = modalEl?.querySelector('[data-media-picker-upload-status]');
        if (!status) {
            return;
        }

        if (!message) {
            status.classList.add('hidden');
            status.textContent = '';
            return;
        }

        status.textContent = message;
        status.classList.remove('hidden');
        status.classList.toggle('text-danger-600', Boolean(isError));
        status.classList.toggle('text-neutral-500', !isError);
    }

    function uploadFromPicker(file, input) {
        const formData = new FormData();
        formData.append('file', file);

        setUploadStatus(t('loading'), false);
        if (input) {
            input.disabled = true;
        }

        fetch('/admin/media/upload', { method: 'POST', body: formData, headers: { Accept: 'application/json' } })
            .then(async (response) => {
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const message = data.message || data.error || ('HTTP ' + response.status);
                    throw new Error(message);
                }
                return data;
            })
            .then((asset) => {
                // Auto-select freshly uploaded asset — no extra click needed.
                if (asset && asset.id) {
                    selectAsset({
                        id: String(asset.id),
                        url: asset.url,
                        originalName: asset.originalName || file.name,
                    });
                    return;
                }

                setUploadStatus('', false);
                loadBody('');
            })
            .catch((error) => {
                setUploadStatus(error.message || t('uploadFailed'), true);
            })
            .finally(() => {
                if (input) {
                    input.disabled = false;
                    input.value = '';
                }
            });
    }

    function markPicked() {
        modalEl.querySelectorAll('[data-media-item]').forEach((el) => {
            const on = picked.has(el.getAttribute('data-asset-id'));
            el.style.outline = on ? '3px solid #2563eb' : '';
            el.style.outlineOffset = on ? '-3px' : '';
        });
        const count = modalEl.querySelector('[data-media-picker-count]');
        count.textContent = picked.size + ' ' + t('selected');
        modalEl.querySelector('[data-media-picker-confirm]').disabled = picked.size === 0;
    }

    function selectAsset(asset) {
        if (onSelectCallback) {
            onSelectCallback(asset);
        }
        close();
    }

    function loadBody(query) {
        const body = modalEl.querySelector('[data-media-picker-body]');
        body.innerHTML = '<p class="p-sp-md text-center text-fs-sm text-cp-steel">' + t('loading') + '</p>';

        fetch('/admin/media/picker?q=' + encodeURIComponent(query || ''))
            .then((response) => response.text())
            .then((html) => {
                body.innerHTML = html;
                if (multiple) {
                    markPicked();
                }
            })
            .catch(() => {
                body.innerHTML = '<p class="p-sp-md text-center text-fs-sm text-danger-600">' + t('listFailed') + '</p>';
            });
    }

    function open(onSelect, options) {
        onSelectCallback = onSelect;
        multiple = Boolean(options && options.multiple);
        picked.clear();
        ensureModal();
        modalEl.querySelector('[data-media-picker-footer]').hidden = !multiple;
        if (multiple) {
            markPicked();
        }
        loadBody('');
        modalEl.showModal();
    }

    function close() {
        if (modalEl) {
            modalEl.close();
        }
        onSelectCallback = null;
    }

    window.CPaliusMediaPicker = { open, close };

    // "Choose image" triggers (featured image, SEO og_image, etc.).
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-media-picker-trigger]');
        if (!trigger) {
            return;
        }

        const targetInputName = trigger.getAttribute('data-target-input');
        const targetPreviewAttr = trigger.getAttribute('data-target-preview');

        open((asset) => {
            const input = document.querySelector('[data-target-input="' + targetInputName + '"]');
            if (input) {
                input.value = asset.id;
            }

            if (targetPreviewAttr) {
                const preview = document.querySelector('[data-' + targetPreviewAttr + ']');
                if (preview) {
                    preview.innerHTML = '<img src="' + asset.url + '" class="rounded-sm max-h-40">';
                }
            }
        });
    });

    // Full-page media library direct upload input.
    document.addEventListener('change', (event) => {
        const input = event.target.closest('[data-media-upload-input]');
        if (!input || !input.files || !input.files[0]) {
            return;
        }

        const formData = new FormData();
        formData.append('file', input.files[0]);

        fetch('/admin/media/upload', { method: 'POST', body: formData, headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('upload failed');
                }
                return response.json();
            })
            .then(() => window.location.reload())
            .catch(() => {
                input.value = '';
                window.alert(t('uploadFailed'));
            });
    });
})();

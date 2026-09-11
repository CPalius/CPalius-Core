/**
 * Studio post form: auto-slug from title (vanilla JS via importmap, see aacp-system.js).
 * Slug tracks title until manually edited; server-side SlugGenerator in PostAdminController does final uniqueness.
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

function initPostForm(form) {
    const titleInput = form.querySelector('[data-post-form-target="title"]');
    const slugInput = form.querySelector('[data-post-form-target="slug"]');

    if (!titleInput || !slugInput) {
        return;
    }

    let slugManuallyEdited = slugInput.value.trim() !== '';

    slugInput.addEventListener('input', () => {
        slugManuallyEdited = true;
    });

    titleInput.addEventListener('input', () => {
        if (slugManuallyEdited) {
            return;
        }

        slugInput.value = slugifyPreview(titleInput.value);
    });
}

/** Simple SEO meta description char counter (160-char guideline; YAGNI). */
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

/**
 * Toggle PostSubType-specific [data-post-sub-type-block] rows and [data-post-sub-type-panel] cards on load and select change.
 */
function initPostSubTypeToggle(form) {
    const select = form.querySelector('[data-post-form-target="postSubType"]');
    if (!select) {
        return;
    }

    const blocks = Array.from(form.querySelectorAll('[data-post-sub-type-block]'));
    const panels = Array.from(form.querySelectorAll('[data-post-sub-type-panel]'));

    function applyVisibility() {
        const activeType = select.value;

        blocks.forEach((field) => {
            const isActive = field.dataset.postSubTypeBlock === activeType;
            // Hide nearest div.mb-sp-sm wrapper (studio_flat_theme form_row) so labels/errors move with the field.
            const row = field.closest('div.mb-sp-sm') ?? field.parentElement;
            if (row) {
                row.classList.toggle('hidden', !isActive);
            } else {
                field.classList.toggle('hidden', !isActive);
            }
        });

        panels.forEach((panel) => {
            panel.classList.toggle('hidden', panel.dataset.postSubTypePanel !== activeType);
        });
    }

    select.addEventListener('change', applyVisibility);
    applyVisibility();
}

/**
 * Central init on load and after AJAX swap. Skips media-picker (global delegation) and initial CKEditor (cp-editor-init.js runs separately to avoid double-init).
 * Pass { ckEditor: true } only after translation tab swap when new [data-cpeditor] nodes appear.
 */
function initAll(root, options) {
    const opts = options || {};

    root.querySelectorAll('[data-post-form]').forEach((form) => {
        initPostForm(form);
        initPostSubTypeToggle(form);
    });
    initSeoCharCounters(root);
    initTranslationTabs(root);

    if (opts.ckEditor) {
        root.querySelectorAll('[data-cpeditor]').forEach((el) => {
            if (window.CPaliusCpEditor && !el.__cpEditor) {
                window.CPaliusCpEditor.init(el).catch((err) => {
                    console.error('[CPalius CKEditor] Init failed:', err);
                });
            }
        });
    }
}

/**
 * AJAX partial swap for translation tabs — replaces #post-form-container from full HTML (PostAdminController still renders pages).
 * Falls back to full navigation on fetch failure or unexpected response (e.g. login redirect).
 */
function initTranslationTabs(root) {
    const container = root.querySelector('#post-form-container') ?? (root.id === 'post-form-container' ? root : null);
    if (!container) {
        return;
    }

    async function swapTo(url, options) {
        let html;
        try {
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                ...options,
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            html = await response.text();
        } catch (err) {
            console.error('[CPalius Post Form] AJAX navigation failed, falling back to full page load:', err);
            window.location.href = url;
            return;
        }

        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const newContainer = parsed.querySelector('#post-form-container');

        if (!newContainer) {
            // Unexpected response (e.g. login page) — fall back to full navigation.
            window.location.href = url;
            return;
        }

        const current = document.querySelector('#post-form-container');
        if (!current) {
            window.location.href = url;
            return;
        }

        current.replaceWith(newContainer);
        initAll(newContainer, { ckEditor: true });

        const finalUrl = newContainer.dataset.currentUrl || url;
        window.history.pushState({}, '', finalUrl);
    }

    container.addEventListener('click', (event) => {
        const locked = event.target.closest('[data-post-translation-locked]');
        if (locked) {
            // Locked translation badge — alert explains why (opacity alone was unclear).
            event.preventDefault();
            window.alert(container.dataset.i18nSaveFirst || 'Save this post first, then you can add translations in other languages.');
            return;
        }

        const link = event.target.closest('[data-post-translation-link]');
        if (!link) {
            return;
        }
        event.preventDefault();
        swapTo(link.getAttribute('href'), { method: 'GET' });
    });

    container.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-post-translation-assign-form]');
        if (!form) {
            return;
        }
        event.preventDefault();
        swapTo(form.getAttribute('action'), { method: 'POST', body: new FormData(form) });
    });
}

initAll(document);

window.addEventListener('popstate', () => {
    // Back/forward: URL changed via pushState but DOM did not — reload is safest.
    window.location.reload();
});

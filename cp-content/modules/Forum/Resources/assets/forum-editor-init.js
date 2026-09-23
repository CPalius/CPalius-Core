import {
    ClassicEditor,
    Autoformat,
    AutoLink,
    Autosave,
    BlockQuote,
    Bold,
    Code,
    CodeBlock,
    Essentials,
    FontSize,
    Heading,
    Highlight,
    ImageBlock,
    ImageCaption,
    ImageInline,
    ImageInsert,
    ImageInsertViaUrl,
    ImageResize,
    ImageStyle,
    ImageTextAlternative,
    ImageToolbar,
    ImageUpload,
    Italic,
    Link,
    LinkImage,
    Mention,
    List,
    ListProperties,
    Paragraph,
    RemoveFormat,
    Strikethrough,
    TextTransformation,
    Underline,
    Undo,
    Plugin,
    ButtonView,
} from 'ckeditor5';
import 'ckeditor5/dist/ckeditor5.css';
import 'ckeditor5/translations/tr';

/**
 * Forum CKEditor 5 — same vendored bundle as admin, trimmed toolbar.
 * Image insert is always on (URL). File upload is wired only when the
 * textarea carries data-image-upload-* (board upload permission).
 */

const forumEditorStyle = document.createElement('style');
forumEditorStyle.innerHTML = `
    .forum .ck.ck-editor__editable_inline {
        min-height: 160px;
    }
    .forum .forum-composer .ck.ck-editor__editable_inline,
    .forum .ck.ck-editor__editable_inline[data-min-height="450"] {
        min-height: clamp(280px, 45vh, 560px);
    }
    @media (min-width: 768px) {
        .forum .forum-composer .ck.ck-editor__editable_inline,
        .forum .ck.ck-editor__editable_inline[data-min-height="450"] {
            min-height: max(450px, clamp(450px, 45vh, 560px));
        }
    }
    .forum .ck-content img {
        max-width: 100%;
        height: auto;
    }
    .forum .ck-content img.forum-smilie {
        display: inline;
        width: 22px;
        height: 22px;
        margin: 0 1px;
        vertical-align: -4px;
        border-radius: 0;
    }
    .forum-smilie-panel {
        display: none;
        position: absolute;
        z-index: 40;
        width: 280px;
        max-height: 220px;
        overflow: auto;
        padding: 8px;
        border-radius: 8px;
        border: 1px solid #cbd5e1;
        background: #fff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        grid-template-columns: repeat(8, 1fr);
        gap: 4px;
    }
    .forum-smilie-panel.is-open { display: grid; }
    .forum-smilie-panel button {
        border: 0;
        background: transparent;
        padding: 4px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 20px;
        line-height: 1;
    }
    .forum-smilie-panel button:hover { background: #f1f5f9; }
    .forum-smilie-panel img { width: 22px; height: 22px; }
    .forum .ck-content .image {
        margin: 0.75em 0;
    }
    .forum .ck-editor__editable_invalid {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 1px #ef4444 !important;
    }
    /* The size and colour classes have to look the same while typing as they
       do in the posted message, so the editable shares the post rules. */
    .forum .ck-content .cp-fs-xs { font-size: 0.75em; }
    .forum .ck-content .cp-fs-sm { font-size: 0.875em; }
    .forum .ck-content .cp-fs-lg { font-size: 1.25em; }
    .forum .ck-content .cp-fs-xl { font-size: 1.6em; }
    .forum .ck-content .cp-pen-red { color: #e5484d; background: none; }
    .forum .ck-content .cp-pen-orange { color: #f5a524; background: none; }
    .forum .ck-content .cp-pen-green { color: #30a46c; background: none; }
    .forum .ck-content .cp-pen-blue { color: #3b82f6; background: none; }
    .forum .ck-content .cp-pen-purple { color: #a855f7; background: none; }
    .forum .ck-content .cp-pen-muted { color: #94a3b8; background: none; }
    .forum .ck-content .cp-mark-yellow { background: #fde047; color: #1f2430; }
    .forum .ck-content .cp-mark-green { background: #86efac; color: #1f2430; }
    .forum .ck-content .cp-mark-blue { background: #93c5fd; color: #1f2430; }
    .forum .ck-content .cp-mark-pink { background: #f9a8d4; color: #1f2430; }
    .cp-mention-item { display: flex; align-items: center; gap: 8px; }
    .cp-mention-item__avatar { width: 22px; height: 22px; border-radius: 50%; object-fit: cover; }
    .forum .ck-content .forum-spoiler {
        border: 1px dashed #64748b;
        border-radius: 6px;
        padding: 10px 12px 8px;
        margin: 0.75em 0;
        background: rgba(15, 23, 42, 0.06);
    }
    .forum .ck-content .forum-spoiler::before {
        content: 'Spoiler';
        display: block;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.65;
        margin-bottom: 6px;
    }
`;
document.head.appendChild(forumEditorStyle);

function forumUploadAdapter(loader, options) {
    return {
        upload() {
            return loader.file.then((file) => new Promise((resolve, reject) => {
                const data = new FormData();
                data.append('upload', file);
                data.append('_token', options.token);
                data.append('section_id', options.sectionId);

                fetch(options.url, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                }).then(async (response) => {
                    const payload = await response.json().catch(() => ({}));
                    const url = typeof payload.url === 'string' ? payload.url : '';
                    if (!response.ok || url === '') {
                        const message = payload?.error?.message;
                        reject(typeof message === 'string' && message !== '' ? message : 'Görsel yüklenemedi.');
                        return;
                    }
                    resolve({ default: url });
                }).catch(() => {
                    reject('Görsel yüklenemedi.');
                });
            }));
        },
        abort() {},
    };
}

/**
 * Suggestions for the "@" autocomplete.
 *
 * Resolves to an empty list on any failure rather than rejecting: a dropped
 * request while somebody is mid-word should leave the flyout closed, not throw
 * inside CKEditor's typing handler.
 *
 * The endpoint is read off the textarea because forum routes are locale
 * prefixed — a hard-coded path would 404 on every language but one.
 */
async function fetchMentionFeed(endpoint, query) {
    if (!endpoint) {
        return [];
    }

    try {
        const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!response.ok) {
            return [];
        }

        const payload = await response.json();

        return Array.isArray(payload.items) ? payload.items : [];
    } catch (err) {
        return [];
    }
}

function renderMentionItem(item) {
    const row = document.createElement('span');
    row.classList.add('cp-mention-item');

    if (item.avatar) {
        const avatar = document.createElement('img');
        avatar.src = item.avatar;
        avatar.alt = '';
        avatar.classList.add('cp-mention-item__avatar');
        row.appendChild(avatar);
    }

    const name = document.createElement('span');
    name.classList.add('cp-mention-item__name');
    name.textContent = item.name;
    row.appendChild(name);

    return row;
}

/**
 * Smilie picker. Inserts Unicode or <img class="forum-smilie">; the
 * catalog still replaces typed ":)" at display time.
 */
class ForumSmilies extends Plugin {
    static get pluginName() {
        return 'ForumSmilies';
    }

    init() {
        const editor = this.editor;
        const url = editor.config.get('forumSmiliesUrl') || '';

        editor.ui.componentFactory.add('forumSmilies', (locale) => {
            const button = new ButtonView(locale);
            button.set({
                label: 'Emoji',
                tooltip: true,
                withText: true,
            });
            button.on('execute', () => {
                openSmiliePanel(editor, button, url);
            });

            return button;
        });
    }
}

let smiliePanel = null;
let smilieItems = null;

async function openSmiliePanel(editor, button, url) {
    if (smilieItems === null && url !== '') {
        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            const payload = await response.json();
            smilieItems = Array.isArray(payload.smilies) ? payload.smilies : [];
        } catch {
            smilieItems = [];
        }
    }

    const items = smilieItems || [];
    if (smiliePanel === null) {
        smiliePanel = document.createElement('div');
        smiliePanel.className = 'forum-smilie-panel';
        document.body.appendChild(smiliePanel);
        document.addEventListener('click', (event) => {
            if (smiliePanel && !smiliePanel.contains(event.target) && event.target !== button.element) {
                smiliePanel.classList.remove('is-open');
            }
        });
    }

    smiliePanel.innerHTML = '';
    items.forEach((item) => {
        const choice = document.createElement('button');
        choice.type = 'button';
        choice.title = item.title || '';
        if (item.image) {
            const img = document.createElement('img');
            img.src = item.image;
            img.alt = (item.codes && item.codes[0]) || '';
            choice.appendChild(img);
        } else {
            choice.textContent = item.emoji || (item.codes && item.codes[0]) || '';
        }
        choice.addEventListener('click', () => {
            insertSmilie(editor, item);
            smiliePanel.classList.remove('is-open');
            editor.editing.view.focus();
        });
        smiliePanel.appendChild(choice);
    });

    const rect = button.element.getBoundingClientRect();
    smiliePanel.style.left = `${window.scrollX + rect.left}px`;
    smiliePanel.style.top = `${window.scrollY + rect.bottom + 6}px`;
    smiliePanel.classList.toggle('is-open');
}

function insertSmilie(editor, item) {
    if (item.image) {
        const view = editor.data.processor.toView(
            `<img class="forum-smilie" src="${item.image}" alt="${(item.codes && item.codes[0]) || ''}" title="${item.title || ''}" width="22" height="22">`,
        );
        editor.model.insertContent(editor.data.toModel(view));
        return;
    }

    const text = item.emoji || (item.codes && item.codes[0]) || '';
    if (text !== '') {
        editor.model.change((writer) => {
            editor.model.insertContent(writer.createText(text));
        });
    }
}

/**
 * Spoiler block: stored as <div class="forum-spoiler"> so the sanitizer
 * (class on div) keeps it. Unlocking is a server-side rewrite, not CSS.
 */
class ForumSpoiler extends Plugin {
    static get pluginName() {
        return 'ForumSpoiler';
    }

    init() {
        const editor = this.editor;

        editor.model.schema.register('forumSpoiler', {
            allowWhere: '$block',
            allowContentOf: '$root',
        });

        editor.conversion.elementToElement({
            model: 'forumSpoiler',
            view: {
                name: 'div',
                classes: 'forum-spoiler',
            },
        });

        editor.ui.componentFactory.add('forumSpoiler', (locale) => {
            const button = new ButtonView(locale);
            button.set({
                label: 'Spoiler',
                tooltip: true,
                withText: true,
            });
            button.on('execute', () => {
                editor.model.change((writer) => {
                    const spoiler = writer.createElement('forumSpoiler');
                    const selected = editor.model.getSelectedContent(editor.model.document.selection);
                    if (!selected.isEmpty) {
                        writer.append(selected, spoiler);
                    } else {
                        writer.append(writer.createElement('paragraph'), spoiler);
                    }
                    editor.model.insertContent(spoiler);
                });
                editor.editing.view.focus();
            });

            return button;
        });
    }
}

/**
 * Writes a mention out as a real profile link instead of CKEditor's default
 * inert <span data-mention>.
 *
 * Two reasons it happens here rather than at render time: the link is then
 * part of the stored post, so it survives being quoted into another post, and
 * the reader gets the same clickable name the author saw while typing.
 */
function attachMentionLinkConverter(editor) {
    editor.conversion.for('downcast').attributeToElement({
        model: 'mention',
        view: (modelAttributeValue, { writer }) => {
            if (!modelAttributeValue) {
                return null;
            }

            return writer.createAttributeElement('a', {
                class: 'forum-mention',
                href: modelAttributeValue.url || '#',
                'data-mention': modelAttributeValue.id,
            }, { priority: 20, id: modelAttributeValue.uid });
        },
        converterPriority: 'high',
    });
}

async function initForumEditor(textarea) {
    if (textarea.dataset.cpEditorInit === '1') {
        return textarea.__cpForumEditor ?? null;
    }
    textarea.dataset.cpEditorInit = '1';

    const minHeightAttr = textarea.getAttribute('data-forum-editor-min-height');
    const minHeightPx = minHeightAttr ? parseInt(minHeightAttr, 10) : 0;
    const uploadUrl = textarea.getAttribute('data-image-upload-url') || '';
    const uploadToken = textarea.getAttribute('data-image-upload-token') || '';
    const sectionId = textarea.getAttribute('data-image-section') || '';
    const mentionUrl = textarea.getAttribute('data-mention-url') || '';
    const smiliesUrl = textarea.getAttribute('data-smilies-url') || '';
    const uploadEnabled = uploadUrl !== '' && uploadToken !== '' && sectionId !== '';

    const plugins = [
        Autoformat,
        AutoLink,
        Autosave,
        BlockQuote,
        Bold,
        Code,
        CodeBlock,
        Essentials,
        FontSize,
        Heading,
        Highlight,
        ImageBlock,
        ImageCaption,
        ImageInline,
        ImageInsert,
        ImageInsertViaUrl,
        ImageResize,
        ImageStyle,
        ImageTextAlternative,
        ImageToolbar,
        Italic,
        Link,
        LinkImage,
        Mention,
        List,
        ListProperties,
        Paragraph,
        RemoveFormat,
        Strikethrough,
        TextTransformation,
        Underline,
        Undo,
        ForumSpoiler,
        ForumSmilies,
    ];

    if (uploadEnabled) {
        plugins.push(ImageUpload);
    }

    const editor = await ClassicEditor.create(textarea, {
        plugins,
        language: 'tr',
        licenseKey: 'GPL',
        forumSmiliesUrl: smiliesUrl,
        toolbar: {
            items: [
                'undo', 'redo',
                '|',
                'heading',
                '|',
                'bold', 'italic', 'underline', 'strikethrough',
                '|',
                'fontSize', 'highlight',
                '|',
                'bulletedList', 'numberedList',
                '|',
                'link', 'insertImage', 'forumSmilies', 'blockQuote', 'forumSpoiler', 'code', 'codeBlock',
                '|',
                'removeFormat',
            ],
            shouldNotGroupWhenFull: false,
        },
        heading: {
            // h1 is the page's own title; a post that could outrank it would
            // wreck both the document outline and the SEO of the thread.
            options: [
                { model: 'paragraph', title: 'Paragraf', class: 'ck-heading_paragraph' },
                { model: 'heading2', view: 'h2', title: 'Başlık 1', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Başlık 2', class: 'ck-heading_heading3' },
                { model: 'heading4', view: 'h4', title: 'Başlık 3', class: 'ck-heading_heading4' },
            ],
        },
        fontSize: {
            // Classes, not inline style. The sanitizer drops `style` on save
            // (Law 5.3), so a px value picked here would silently vanish the
            // moment the post was submitted; a class survives and lets the
            // theme keep sizes on its own scale.
            options: [
                { title: 'Çok küçük', model: 'cp-fs-xs', view: { name: 'span', classes: 'cp-fs-xs' } },
                { title: 'Küçük', model: 'cp-fs-sm', view: { name: 'span', classes: 'cp-fs-sm' } },
                'default',
                { title: 'Büyük', model: 'cp-fs-lg', view: { name: 'span', classes: 'cp-fs-lg' } },
                { title: 'Çok büyük', model: 'cp-fs-xl', view: { name: 'span', classes: 'cp-fs-xl' } },
            ],
            supportAllValues: false,
        },
        highlight: {
            // Highlight is the one colour feature that writes classes instead
            // of inline style, which is why the palette is fixed: the theme
            // owns the actual values, so a colour can never come out unreadable
            // against the forum background.
            options: [
                { model: 'cpPenRed', class: 'cp-pen-red', title: 'Kırmızı yazı', color: '#e5484d', type: 'pen' },
                { model: 'cpPenOrange', class: 'cp-pen-orange', title: 'Turuncu yazı', color: '#f5a524', type: 'pen' },
                { model: 'cpPenGreen', class: 'cp-pen-green', title: 'Yeşil yazı', color: '#30a46c', type: 'pen' },
                { model: 'cpPenBlue', class: 'cp-pen-blue', title: 'Mavi yazı', color: '#3b82f6', type: 'pen' },
                { model: 'cpPenPurple', class: 'cp-pen-purple', title: 'Mor yazı', color: '#a855f7', type: 'pen' },
                { model: 'cpPenMuted', class: 'cp-pen-muted', title: 'Soluk yazı', color: '#94a3b8', type: 'pen' },
                { model: 'cpMarkYellow', class: 'cp-mark-yellow', title: 'Sarı zemin', color: '#fde047', type: 'marker' },
                { model: 'cpMarkGreen', class: 'cp-mark-green', title: 'Yeşil zemin', color: '#86efac', type: 'marker' },
                { model: 'cpMarkBlue', class: 'cp-mark-blue', title: 'Mavi zemin', color: '#93c5fd', type: 'marker' },
                { model: 'cpMarkPink', class: 'cp-mark-pink', title: 'Pembe zemin', color: '#f9a8d4', type: 'marker' },
            ],
        },
        mention: {
            feeds: [{
                marker: '@',
                feed: (query) => fetchMentionFeed(mentionUrl, query),
                itemRenderer: renderMentionItem,
                minimumCharacters: 2,
            }],
        },
        image: {
            toolbar: [
                'toggleImageCaption',
                'imageTextAlternative',
                '|',
                'imageStyle:inline',
                'imageStyle:wrapText',
                'imageStyle:breakText',
                '|',
                'resizeImage',
            ],
        },
        link: {
            addTargetToExternalLinks: true,
            defaultProtocol: 'https://',
        },
    });

    attachMentionLinkConverter(editor);

    if (uploadEnabled) {
        editor.plugins.get('FileRepository').createUploadAdapter = (loader) => forumUploadAdapter(loader, {
            url: uploadUrl,
            token: uploadToken,
            sectionId,
        });
    }

    const editableEl = editor.ui.view.editable.element;
    if (editableEl && minHeightPx > 0) {
        editableEl.dataset.minHeight = String(minHeightPx);
        editableEl.style.minHeight = window.matchMedia('(min-width: 768px)').matches
            ? Math.max(minHeightPx, Math.min(560, Math.round(window.innerHeight * 0.45))) + 'px'
            : Math.max(280, Math.min(minHeightPx, Math.round(window.innerHeight * 0.45))) + 'px';
    }

    const wasRequired = textarea.hasAttribute('required');
    if (wasRequired) {
        textarea.removeAttribute('required');
    }

    const form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', (event) => {
            textarea.value = editor.getData();

            const editable = editor.ui.view.editable.element;
            if (wasRequired && textarea.value.trim() === '') {
                event.preventDefault();
                event.stopPropagation();
                editable?.classList.add('ck-editor__editable_invalid');
                editable?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                editable?.classList.remove('ck-editor__editable_invalid');
            }
        });
    }

    textarea.__cpForumEditor = editor;

    return editor;
}

document.querySelectorAll('[data-forum-editor]').forEach((el) => {
    initForumEditor(el).catch((err) => {
        console.error('[CPalius Forum Editor] Baslatma hatasi:', err);
    });
});

window.CPaliusForumEditor = { init: initForumEditor };

function syncTitlePrefixChip() {
    const select = document.querySelector('[data-title-prefix-select]');
    const chip = document.querySelector('[data-title-prefix-chip]');
    if (!select || !chip) {
        return;
    }

    const option = select.options[select.selectedIndex];
    const label = option && option.value ? (option.getAttribute('data-label') || option.textContent || '') : '';
    if (label === '') {
        chip.hidden = true;
        chip.textContent = '';
        chip.removeAttribute('style');
        chip.className = 'forum-prefix forum-prefix--default';
        return;
    }

    const cssClass = option.getAttribute('data-class') || '';
    const color = option.getAttribute('data-color') || '';
    chip.hidden = false;
    chip.textContent = label;
    chip.className = 'forum-prefix ' + (cssClass !== '' ? cssClass : 'forum-prefix--default');
    if (cssClass === '' && color !== '') {
        chip.style.background = 'color-mix(in srgb, ' + color + ' 15%, white)';
        chip.style.color = color;
    } else {
        chip.removeAttribute('style');
    }
}

const titlePrefixSelect = document.querySelector('[data-title-prefix-select]');
if (titlePrefixSelect) {
    titlePrefixSelect.addEventListener('change', syncTitlePrefixChip);
    syncTitlePrefixChip();
}

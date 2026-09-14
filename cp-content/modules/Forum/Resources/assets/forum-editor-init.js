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
    List,
    ListProperties,
    Paragraph,
    RemoveFormat,
    Strikethrough,
    TextTransformation,
    Underline,
    Undo,
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
    .forum .ck-content .image {
        margin: 0.75em 0;
    }
    .forum .ck-editor__editable_invalid {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 1px #ef4444 !important;
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
        List,
        ListProperties,
        Paragraph,
        RemoveFormat,
        Strikethrough,
        TextTransformation,
        Underline,
        Undo,
    ];

    if (uploadEnabled) {
        plugins.push(ImageUpload);
    }

    const editor = await ClassicEditor.create(textarea, {
        plugins,
        language: 'tr',
        licenseKey: 'GPL',
        toolbar: {
            items: [
                'undo', 'redo',
                '|',
                'bold', 'italic', 'underline', 'strikethrough',
                '|',
                'bulletedList', 'numberedList',
                '|',
                'link', 'insertImage', 'blockQuote', 'code', 'codeBlock',
                '|',
                'removeFormat',
            ],
            shouldNotGroupWhenFull: false,
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

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
    Italic,
    Link,
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
 * Forum için hafifletilmiş CKEditor 5 başlatıcısı — admin cp-editor-init.js
 * ile aynı vendored paketi (Node.js/CDN bağımlılığı yok) kullanır, ancak
 * toolbar/eklenti seti bir topluluk forumu yanıt kutusuna göre küçültülmüş:
 * resim/tablo/font/kaynak-düzenleme yok, sadece temel biçimlendirme.
 *
 * [data-forum-editor] attribute'lu her textarea CKEditor 5 Classic ile
 * zenginleştirilir. forum.js'teki "Alıntıla" butonu, textarea yerine
 * doğrudan bu editör örneğini (textarea.__cpForumEditor) hedefler.
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
    .forum .ck-editor__editable_invalid {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 1px #ef4444 !important;
    }
`;
document.head.appendChild(forumEditorStyle);

async function initForumEditor(textarea) {
    const minHeightAttr = textarea.getAttribute('data-forum-editor-min-height');
    const minHeightPx = minHeightAttr ? parseInt(minHeightAttr, 10) : 0;

    const editor = await ClassicEditor.create(textarea, {
        plugins: [
            Autoformat,
            AutoLink,
            Autosave,
            BlockQuote,
            Bold,
            Code,
            CodeBlock,
            Essentials,
            Italic,
            Link,
            List,
            ListProperties,
            Paragraph,
            RemoveFormat,
            Strikethrough,
            TextTransformation,
            Underline,
            Undo,
        ],
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
                'link', 'blockQuote', 'code', 'codeBlock',
                '|',
                'removeFormat',
            ],
            shouldNotGroupWhenFull: false,
        },
        link: {
            addTargetToExternalLinks: true,
            defaultProtocol: 'https://',
        },
    });

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

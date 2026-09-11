import {
    ClassicEditor,
    AccessibilityHelp,
    Alignment,
    Autoformat,
    AutoLink,
    Autosave,
    BlockQuote,
    Bold,
    Code,
    CodeBlock,
    Essentials,
    FindAndReplace,
    FontBackgroundColor,
    FontColor,
    FontFamily,
    FontSize,
    GeneralHtmlSupport,
    Heading,
    Highlight,
    HorizontalLine,
    HtmlEmbed,
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
    Indent,
    IndentBlock,
    Italic,
    Link,
    LinkImage,
    List,
    ListProperties,
    PasteFromOffice,
    Paragraph,
    RemoveFormat,
    SelectAll,
    ShowBlocks,
    SourceEditing,
    Strikethrough,
    Style,
    Subscript,
    Superscript,
    Table,
    TableCaption,
    TableCellProperties,
    TableColumnResize,
    TableProperties,
    TableToolbar,
    TextTransformation,
    TodoList,
    Underline,
    Undo,
} from 'ckeditor5';
import 'ckeditor5/dist/ckeditor5.css';
import 'ckeditor5/translations/tr';

/**
 * CPalius-CMF CKEditor 5 bootstrapper.
 *
 * Enriches [data-cpeditor] textareas via ClassicEditor and CPaliusMediaPicker.
 * Zero Node.js — packages served locally via Asset Mapper.
 */

// Min editor height + manual invalid state for empty required fields (see submit listener).
const cpEditorStyle = document.createElement('style');
cpEditorStyle.innerHTML = `
    .ck-editor__editable_inline {
        min-height: 550px;
    }
    .ck-editor__editable_invalid {
        border-color: #ef4444 !important;
        box-shadow: 0 0 0 1px #ef4444 !important;
    }
`;
document.head.appendChild(cpEditorStyle);

async function initCpEditor(textarea) {
    const editor = await ClassicEditor.create(textarea, {
        plugins: [
            AccessibilityHelp,
            Alignment,
            Autoformat,
            AutoLink,
            Autosave,
            BlockQuote,
            Bold,
            Code,
            CodeBlock,
            Essentials,
            FindAndReplace,
            FontBackgroundColor,
            FontColor,
            FontFamily,
            FontSize,
            GeneralHtmlSupport,
            Heading,
            Highlight,
            HorizontalLine,
            HtmlEmbed,
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
            Indent,
            IndentBlock,
            Italic,
            Link,
            LinkImage,
            List,
            ListProperties,
            PasteFromOffice,
            Paragraph,
            RemoveFormat,
            SelectAll,
            ShowBlocks,
            SourceEditing,
            Strikethrough,
            Style,
            Subscript,
            Superscript,
            Table,
            TableCaption,
            TableCellProperties,
            TableColumnResize,
            TableProperties,
            TableToolbar,
            TextTransformation,
            TodoList,
            Underline,
            Undo,
        ],
        language: 'tr',
        licenseKey: 'GPL',
        toolbar: {
            items: [
                'undo', 'redo',
                '|',
                'heading',
                '|',
                'bold', 'italic', 'underline', 'strikethrough',
                '|',
                'fontFamily', 'fontSize', 'fontColor', 'fontBackgroundColor',
                '|',
                'alignment',
                '|',
                'bulletedList', 'numberedList', 'todoList',
                'outdent', 'indent',
                '|',
                'link', 'imageInsert', 'mediaEmbed', 'insertTable', 'blockQuote',
                'horizontalLine', 'codeBlock', 'htmlEmbed',
                '|',
                'findAndReplace', 'selectAll',
                '|',
                'removeFormat',
                '|',
                'showBlocks', 'sourceEditing',
                '|',
                'accessibilityHelp',
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
        table: {
            contentToolbar: [
                'tableColumn',
                'tableRow',
                'mergeTableCells',
                'tableProperties',
                'tableCellProperties',
            ],
        },
        list: {
            properties: {
                styles: true,
                startIndex: true,
                reversed: true,
            },
        },
        link: {
            addTargetToExternalLinks: true,
            defaultProtocol: 'https://',
        },
        htmlSupport: {
            allow: [
                {
                    name: /^.*$/,
                    styles: true,
                    attributes: true,
                    classes: true,
                },
            ],
        },
    });

    // Media Library — injected as a DOM button on the toolbar
    if (window.CPaliusMediaPicker) {
        const toolbarEl = editor.ui.view.toolbar.element;
        if (toolbarEl) {
            const sep = document.createElement('span');
            sep.className = 'ck ck-toolbar__separator';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ck ck-button ck-off';
            btn.title = 'Medya Kutuphanesi';
            btn.setAttribute('aria-label', 'Medya Kutuphanesi');
            btn.style.cssText = 'cursor:pointer;padding:4px 6px;border:none;background:transparent;border-radius:4px;display:flex;align-items:center;color:var(--ck-color-text,#333);';
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M22 16V4c0-1.1-.9-2-2-2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2zm-11-4 2.03 2.71L16 11l4 5H8l3-4zM2 6v14c0 1.1.9 2 2 2h14v-2H4V6H2z"/></svg>';

            btn.addEventListener('mouseenter', () => { btn.style.background = 'var(--ck-color-button-on-background,#e8e8e8)'; });
            btn.addEventListener('mouseleave', () => { btn.style.background = 'transparent'; });

            btn.addEventListener('click', () => {
                window.CPaliusMediaPicker.open((asset) => {
                    editor.model.change((writer) => {
                        const imageElement = writer.createElement('imageBlock', {
                            src: asset.url,
                            alt: asset.originalName || '',
                        });
                        editor.model.insertContent(imageElement, editor.model.document.selection);
                    });
                });
            });

            toolbarEl.appendChild(sep);
            toolbarEl.appendChild(btn);
        }
    }

    // CKEditor hides the required textarea; native validation blocks submit silently.
    // Remove required from DOM and validate editor data on submit with a red border if empty.
    const wasRequired = textarea.hasAttribute('required');
    if (wasRequired) {
        textarea.removeAttribute('required');
    }

    const form = textarea.closest('form');
    if (form) {
        form.addEventListener('submit', (event) => {
            textarea.value = editor.getData();

            const editableEl = editor.ui.view.editable.element;
            if (wasRequired && textarea.value.trim() === '') {
                event.preventDefault();
                event.stopPropagation();
                editableEl?.classList.add('ck-editor__editable_invalid');
                editableEl?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                editableEl?.classList.remove('ck-editor__editable_invalid');
            }
        });
    }

    textarea.__cpEditor = editor;
    return editor;
}

document.querySelectorAll('[data-cpeditor]').forEach((el) => {
    initCpEditor(el).catch((err) => {
        console.error('[CPalius CKEditor] Baslatma hatasi:', err);
    });
});

window.CPaliusCpEditor = { init: initCpEditor };

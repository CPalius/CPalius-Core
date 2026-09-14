/**
 * Rich text on the generic #[CpResource] form, with a way out of it.
 *
 * CKEditor is offered for every property that opted in through
 * #[CpField(widget: 'richtext')], but it is never the only option: CKEditor
 * normalises markup it does not recognise, and a hand-written document — the
 * whitepaper is one — has to be able to refuse that and edit raw HTML instead.
 *
 * The choice is the operator's and is remembered per resource and per field,
 * so switching to source once does not have to be repeated on every chapter.
 */

const STORAGE_PREFIX = 'cpalius.resource-editor.';
const MODE_RICH = 'rich';
const MODE_SOURCE = 'source';

// Deferred to DOMContentLoaded on purpose. ES modules evaluate their static
// imports in order, so this file's body runs BEFORE cp-editor-init.js has set
// window.CPaliusCpEditor — attaching here would find no API and, in the first
// version of this file, returned silently and left the editor un-started.
// Module scripts are deferred, so DOMContentLoaded fires after every one of
// them has evaluated, whatever order the importmap lists them in.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-aacp-resource-form]').forEach(initResourceForm);
});

function initResourceForm(root) {
    const richLabel = root.dataset.labelEditorRich || 'Rich text';
    const sourceLabel = root.dataset.labelEditorSource || 'HTML';
    const hint = root.dataset.editorHint || '';

    root.querySelectorAll('textarea[data-cp-richtext]').forEach((textarea) => {
        const key = STORAGE_PREFIX + (textarea.name || textarea.id || 'field');

        const bar = document.createElement('div');
        bar.className = 'aacp-editor-bar';

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'aacp-cyber-btn';

        const note = document.createElement('span');
        note.className = 'aacp-editor-hint';
        note.textContent = hint;

        bar.append(toggle, note);
        textarea.parentNode?.insertBefore(bar, textarea);

        let mode = read(key);

        const paint = () => {
            // The button says what pressing it will DO, not what mode you are
            // in — the other wording is the classic way to make a toggle
            // unreadable.
            toggle.textContent = mode === MODE_RICH ? sourceLabel : richLabel;
            note.hidden = mode !== MODE_RICH;
        };

        const apply = async (next) => {
            mode = next;
            write(key, mode);
            paint();

            if (mode === MODE_RICH) {
                await attach(textarea);
            } else {
                await detach(textarea);
            }
        };

        toggle.addEventListener('click', () => {
            toggle.disabled = true;
            apply(mode === MODE_RICH ? MODE_SOURCE : MODE_RICH)
                .catch((error) => console.error('[CPalius] editor toggle failed:', error))
                .finally(() => {
                    toggle.disabled = false;
                });
        });

        paint();
        if (mode === MODE_RICH) {
            attach(textarea).catch((error) => console.error('[CPalius] editor failed to start:', error));
        }
    });
}

/**
 * cp-editor-init.js auto-attaches to [data-cpeditor] at module evaluation. The
 * marker is deliberately NOT rendered by the server, so nothing attaches before
 * this file has read the stored preference — otherwise a field the operator
 * left in source mode would flash into CKEditor on every load.
 */
async function attach(textarea) {
    if (textarea.__cpEditor) {
        return;
    }

    const api = window.CPaliusCpEditor;
    if (!api) {
        // The textarea still works, so the form is never dead — but say so.
        // The silent version of this branch hid a real bug: the editor simply
        // never appeared and nothing anywhere explained why.
        console.warn('[CPalius] cp-editor-init did not load; the field stays a plain textarea.');

        return;
    }

    textarea.setAttribute('data-cpeditor', '');
    await api.init(textarea);
}

async function detach(textarea) {
    const editor = textarea.__cpEditor;
    if (!editor) {
        return;
    }

    // Copy the editor's current text back first: destroy() restores the
    // textarea, and an unsynced edit would be lost between the two.
    textarea.value = editor.getData();
    await editor.destroy();

    delete textarea.__cpEditor;
    textarea.removeAttribute('data-cpeditor');
}

function read(key) {
    try {
        return localStorage.getItem(key) === MODE_SOURCE ? MODE_SOURCE : MODE_RICH;
    } catch {
        // Private browsing and locked-down profiles throw on access rather
        // than returning null. A preference is not worth a broken form.
        return MODE_RICH;
    }
}

function write(key, mode) {
    try {
        localStorage.setItem(key, mode);
    } catch {
        // Same as above: the toggle still works for this page view.
    }
}

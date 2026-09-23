/**
 * Fills an option's path field from the list of uploaded exports.
 *
 * The text field stays the real input: the picker is a shortcut, not a
 * replacement. An operator who put a 4 GB export on the server over SSH types
 * its path, and an operator who uploaded one picks it — both end up submitting
 * the same field, so the form has one source of truth and the server has one
 * thing to validate.
 */
document.querySelectorAll('[data-import-picker]').forEach((picker) => {
    const target = document.getElementById(picker.dataset.importPicker);

    if (!target) {
        return;
    }

    picker.addEventListener('change', () => {
        if (picker.value !== '') {
            target.value = picker.value;
        }
    });
});

const unlimited = document.querySelector('input[name="unlimited"]');
const limit = document.getElementById('limit');

if (unlimited instanceof HTMLInputElement && limit instanceof HTMLInputElement) {
    const sync = () => {
        limit.disabled = unlimited.checked;
    };

    unlimited.addEventListener('change', sync);
    sync();
}

/**
 * XML vs SQL (WordPress) or dump vs remote MySQL (XenForo): only the matching
 * fields stay visible. Hidden inputs are disabled so a leftover remote host
 * does not fail HTML5 required, and so the other kind is not submitted.
 */
const sourceForm = document.querySelector('[data-import-source]');

if (sourceForm instanceof HTMLFormElement) {
    const radios = sourceForm.querySelectorAll('input[name="sourceKind"]');

    const applyKind = () => {
        const selected = sourceForm.querySelector('input[name="sourceKind"]:checked');
        const mode = selected instanceof HTMLInputElement ? selected.value : '';

        if (mode === '') {
            return;
        }

        sourceForm.querySelectorAll('[data-import-field]').forEach((field) => {
            if (!(field instanceof HTMLElement)) {
                return;
            }

            const groups = (field.dataset.importGroups || '')
                .split(',')
                .map((part) => part.trim())
                .filter((part) => part !== '');
            const on = groups.length === 0 || groups.includes(mode);

            field.hidden = !on;

            field.querySelectorAll('input, select, textarea').forEach((el) => {
                if (
                    !(el instanceof HTMLInputElement
                        || el instanceof HTMLSelectElement
                        || el instanceof HTMLTextAreaElement)
                    || el.name === 'sourceKind'
                ) {
                    return;
                }

                el.disabled = !on;

                if (on) {
                    if (el.dataset.wasRequired === '1') {
                        el.required = true;
                    }
                } else {
                    if (el.required) {
                        el.dataset.wasRequired = '1';
                    }

                    el.required = false;
                }
            });
        });
    };

    radios.forEach((radio) => {
        radio.addEventListener('change', applyKind);
    });

    applyKind();
}

/**
 * Checking posts also checks topics/users; unchecking users unchecks the
 * steps that cannot land without them. The server still honours the ticks
 * as submitted — this only keeps the form honest.
 */
const stepBoxes = document.querySelectorAll('[data-import-steps] input[name="steps[]"]');

if (stepBoxes.length > 0) {
    const byId = {};

    stepBoxes.forEach((box) => {
        if (box instanceof HTMLInputElement) {
            byId[box.value] = box;
        }
    });

    const depsOf = (id) => (byId[id]?.dataset.dependsOn || '')
        .split(',')
        .map((part) => part.trim())
        .filter((part) => part !== '');

    const checkWithDeps = (id) => {
        const box = byId[id];

        if (!(box instanceof HTMLInputElement) || box.checked) {
            return;
        }

        box.checked = true;
        depsOf(id).forEach(checkWithDeps);
    };

    const uncheckWithDependents = (id) => {
        const box = byId[id];

        if (box instanceof HTMLInputElement) {
            box.checked = false;
        }

        stepBoxes.forEach((other) => {
            if (other instanceof HTMLInputElement && depsOf(other.value).includes(id) && other.checked) {
                uncheckWithDependents(other.value);
            }
        });
    };

    stepBoxes.forEach((box) => {
        box.addEventListener('change', () => {
            if (!(box instanceof HTMLInputElement)) {
                return;
            }

            if (box.checked) {
                depsOf(box.value).forEach(checkWithDeps);
            } else {
                stepBoxes.forEach((other) => {
                    if (other instanceof HTMLInputElement && depsOf(other.value).includes(box.value)) {
                        uncheckWithDependents(other.value);
                    }
                });
            }
        });
    });
}

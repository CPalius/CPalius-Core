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

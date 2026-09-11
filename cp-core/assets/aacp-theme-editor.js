/**
 * AACP theme file editor: lint Twig/CSS/JS on save and confirm when the source is invalid.
 */
function initAacpThemeEditor(root) {
    const form = root.querySelector('[data-theme-editor-form]');
    const source = root.querySelector('[data-theme-editor-source]');
    const forceInput = root.querySelector('[data-theme-editor-force]');
    const problemsEl = root.querySelector('[data-theme-editor-problems]');
    const lintUrl = root.dataset.lintUrl;
    const confirmMessage = root.dataset.i18nConfirm || '';
    const lintFailed = root.dataset.i18nLintFailed || '';

    if (!form || !source || !forceInput || !lintUrl) {
        return;
    }

    source.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') {
            return;
        }
        event.preventDefault();
        const start = source.selectionStart;
        const end = source.selectionEnd;
        source.value = source.value.slice(0, start) + '    ' + source.value.slice(end);
        source.selectionStart = source.selectionEnd = start + 4;
    });

    form.addEventListener('submit', async (event) => {
        if (forceInput.value === '1') {
            return;
        }

        event.preventDefault();

        const body = new FormData(form);
        body.set('content', source.value);

        let payload;
        try {
            const response = await fetch(lintUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            payload = await response.json();
        } catch (error) {
            window.alert(lintFailed.replace('{error}', error instanceof Error ? error.message : String(error)));
            return;
        }

        const problems = Array.isArray(payload.problems) ? payload.problems : [];
        if (problemsEl) {
            problemsEl.textContent = problems.join('\n');
            problemsEl.classList.toggle('hidden', problems.length === 0);
        }

        if (problems.length === 0) {
            form.submit();
            return;
        }

        if (window.confirm(confirmMessage)) {
            forceInput.value = '1';
            form.submit();
        }
    });
}

const themeEditorRoot = document.querySelector('[data-theme-editor-root]');
if (themeEditorRoot) {
    initAacpThemeEditor(themeEditorRoot);
}

/**
 * AACP Languages: "translate missing strings with AI".
 *
 * The server translates one batch per request; this loops until it reports
 * `finished`, moving a progress bar. Strings the provider could not translate
 * stay missing, and `offset` skips them so the loop cannot spin on the same ones.
 */
function fill(template, vars) {
    return template.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? vars[key] : match));
}

async function translateLocale(button, root) {
    const row = button.closest('[data-language-row]');
    const bar = row.querySelector('[data-ai-bar]');
    const percent = row.querySelector('[data-ai-percent]');
    const remaining = row.querySelector('[data-ai-remaining]');
    const status = row.querySelector('[data-ai-status]');
    const t = (key) => root.dataset[`i18n${key}`] ?? '';

    let offset = 0;
    let translated = 0;
    let skipped = 0;

    button.disabled = true;

    try {
        for (;;) {
            status.textContent = fill(t('Running'), { count: translated });

            const response = await fetch(button.dataset.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': button.dataset.token },
                body: JSON.stringify({ offset }),
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.error || t('Error'));
            }

            translated += data.translated;
            skipped += data.failed;
            offset += data.failed;
            bar.style.width = `${data.percent}%`;
            percent.textContent = data.percent;
            remaining.textContent = data.remaining;

            if (data.finished) {
                break;
            }
        }

        status.textContent = skipped > 0
            ? fill(t('Partial'), { count: translated, skipped })
            : fill(t('Done'), { count: translated });
        button.hidden = skipped === 0;
    } catch (error) {
        status.textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-languages-root]');
    if (!root) {
        return;
    }

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-ai-translate]');
        if (button && !button.disabled) {
            translateLocale(button, root);
        }
    });
});

/**
 * AACP Storage desk: "save and test" for each remote target card.
 *
 * Save and test are one button on purpose. The server enables a target only
 * when the last probe passed against exactly what is stored, so a Save that
 * skipped the probe would leave a green badge describing a configuration that
 * no longer exists.
 *
 * User-facing copy comes from data-i18n-* on the card root, never from string
 * literals here — this file is served to a panel that runs in Turkish.
 */
function interpolate(template, vars) {
    return Object.keys(vars).reduce(
        (text, key) => text.replaceAll('{' + key + '}', String(vars[key])),
        template,
    );
}

function initStorageCard(root) {
    const csrfToken = root.dataset.storageCsrf;
    const testUrl = root.dataset.storageTestUrl;
    const locale = document.documentElement.lang || undefined;

    const logEl = root.querySelector('[data-storage-log]');
    const badgeEl = root.querySelector('[data-storage-badge]');
    const testButton = root.querySelector('[data-storage-action="test"]');
    const fieldInputs = Array.from(root.querySelectorAll('[data-storage-field]'));

    function appendLog(line, isError) {
        if (!logEl) {
            return;
        }
        const timestamp = new Date().toLocaleTimeString(locale);
        const row = document.createElement('div');
        row.textContent = `[${timestamp}] ${line}`;
        row.className = isError ? 'text-red-400' : 'text-cp-accent';
        logEl.appendChild(row);
        logEl.scrollTop = logEl.scrollHeight;
    }

    async function runTest() {
        testButton.disabled = true;
        appendLog(root.dataset.i18nTestStarted || '', false);

        try {
            const formData = new FormData();
            formData.append('_token', csrfToken);
            fieldInputs.forEach((input) => {
                const key = input.dataset.storageField;
                const value = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
                formData.append(`config[${key}]`, value);
            });

            const response = await fetch(testUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-CSRF-TOKEN': csrfToken },
            });
            const data = await response.json();

            appendLog(data.message, !data.success);

            if (data.success && data.describe) {
                appendLog(data.describe, false);
            }
            if (data.success && typeof data.latencyMs === 'number') {
                appendLog(interpolate(root.dataset.i18nLatency || '{ms}', { ms: data.latencyMs.toFixed(1) }), false);
            }

            if (badgeEl) {
                badgeEl.textContent = data.success
                    ? (root.dataset.i18nVerified || '')
                    : (root.dataset.i18nUnverified || '');
                badgeEl.className = data.success ? 'badge badge-success' : 'badge badge-danger';
            }

            // A secret that was just accepted must not sit in the DOM; the field
            // is cleared so a reload, a screenshot or a shoulder does not carry
            // it any further. Empty already means "leave the stored value
            // alone", so clearing it costs nothing on the next save.
            fieldInputs
                .filter((input) => input.type === 'password')
                .forEach((input) => { input.value = ''; });
        } catch (error) {
            appendLog(interpolate(root.dataset.i18nRequestFailed || '{error}', { error: error.message }), true);
        } finally {
            testButton.disabled = false;
        }
    }

    if (testButton) {
        testButton.addEventListener('click', runTest);
    }
}

document.querySelectorAll('[data-storage-root]').forEach(initStorageCard);

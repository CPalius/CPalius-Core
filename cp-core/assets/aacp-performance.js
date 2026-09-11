/**
 * AACP Performance console: Test/Enable/Disable AJAX for each backend card.
 * Enable stays gated on the last test result; the server enforces the same rule.
 * User-facing copy comes from data-i18n-* on the card root.
 */
function interpolate(template, vars) {
    return Object.keys(vars).reduce(
        (text, key) => text.replaceAll('{' + key + '}', String(vars[key])),
        template,
    );
}

function initPerformanceCard(root) {
    const backend = root.dataset.performanceBackend;
    const csrfToken = root.dataset.performanceCsrf;
    const locale = document.documentElement.lang || undefined;
    const urls = {
        test: root.dataset.performanceTestUrl,
        enable: root.dataset.performanceEnableUrl,
        disable: root.dataset.performanceDisableUrl,
        purge: root.dataset.performancePurgeUrl,
    };

    const logEl = root.querySelector('[data-performance-log]');
    const lastTestedEl = root.querySelector('[data-performance-last-tested]');
    const buttons = {
        test: root.querySelector('[data-performance-action="test"]'),
        enable: root.querySelector('[data-performance-action="enable"]'),
        disable: root.querySelector('[data-performance-action="disable"]'),
        purge: root.querySelector('[data-performance-action="purge"]'),
    };
    const fieldInputs = Array.from(root.querySelectorAll('[data-performance-field]'));

    function appendLog(line, isError) {
        if (!logEl) {
            return;
        }
        const timestamp = new Date().toLocaleTimeString(locale);
        const span = document.createElement('div');
        span.textContent = `[${timestamp}] ${line}`;
        span.className = isError ? 'text-red-400' : 'text-cp-accent';
        logEl.appendChild(span);
        logEl.scrollTop = logEl.scrollHeight;
    }

    // Keep Enable independent of setBusy: a failed test must not re-enable the button.
    function setBusy(busy) {
        [buttons.test, buttons.disable].forEach((button) => {
            if (button) {
                button.disabled = busy;
            }
        });
    }

    function applyTestResult(data) {
        if (buttons.enable) {
            buttons.enable.disabled = !data.success;
        }

        if (lastTestedEl) {
            const now = new Date().toLocaleString(locale);
            lastTestedEl.textContent = interpolate(root.dataset.i18nLastTest || '{date} — {message}', {
                date: now,
                message: data.message,
            });
        }

        appendLog(data.message, !data.success);
        if (data.success && typeof data.latencyMs === 'number') {
            appendLog(interpolate(root.dataset.i18nLatency || '{ms}', { ms: data.latencyMs.toFixed(1) }), false);
        }
    }

    async function runTest() {
        setBusy(true);
        appendLog(root.dataset.i18nTestStarted || '', false);

        try {
            const formData = new FormData();
            formData.append('_token', csrfToken);
            fieldInputs.forEach((input) => {
                const key = input.dataset.performanceField;
                const value = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
                formData.append(`config[${key}]`, value);
            });

            const response = await fetch(urls.test, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-CSRF-TOKEN': csrfToken },
            });
            const data = await response.json();
            applyTestResult(data);
        } catch (error) {
            appendLog(interpolate(root.dataset.i18nRequestFailed || '{error}', { error: error.message }), true);
        } finally {
            setBusy(false);
        }
    }

    async function runToggle(action) {
        const url = urls[action];
        if (!url) {
            return;
        }

        setBusy(true);
        try {
            const formData = new FormData();
            formData.append('_token', csrfToken);

            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-CSRF-TOKEN': csrfToken },
            });
            const data = await response.json();

            if (!data.success) {
                appendLog(data.message || root.dataset.i18nActionFailed || '', true);
                return;
            }

            let line = data.message;
            if (!line) {
                if (action === 'enable') {
                    line = interpolate(root.dataset.i18nEnabled || '{backend}', { backend });
                } else if (action !== 'purge') {
                    line = interpolate(root.dataset.i18nDisabled || '{backend}', { backend });
                }
            }
            if (line) {
                appendLog(line, false);
            }
            if (action !== 'purge') {
                window.location.reload();
            }
        } catch (error) {
            appendLog(interpolate(root.dataset.i18nRequestFailed || '{error}', { error: error.message }), true);
        } finally {
            setBusy(false);
        }
    }

    if (buttons.test) {
        buttons.test.addEventListener('click', runTest);
    }
    if (buttons.enable) {
        buttons.enable.addEventListener('click', () => runToggle('enable'));
    }
    if (buttons.disable) {
        buttons.disable.addEventListener('click', () => runToggle('disable'));
    }
    if (buttons.purge) {
        buttons.purge.addEventListener('click', () => runToggle('purge'));
    }
}

document.querySelectorAll('[data-performance-root]').forEach(initPerformanceCard);

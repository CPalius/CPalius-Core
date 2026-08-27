/**
 * AACP "Performans" konsolu: her backend kartı (Redis/Memcached/Varnish/
 * PageSpeed) için Test/Enable/Disable AJAX aksiyonlarını yönetir.
 *
 * Bilinçli olarak vanilla JS — aacp-cache-rebuild.js ile aynı "sıfır
 * bağımlılık" ruhu. Etkinleştir butonu yalnızca en son testin başarılı
 * olduğu durumda tıklanabilir hale getirilir; bu istemci tarafı bir UX
 * kolaylığıdır, gerçek zorlama PerformanceController/PerformanceBackendRegistry
 * tarafında (sunucu tarafında) yapılır.
 */
function initPerformanceCard(root) {
    const backend = root.dataset.performanceBackend;
    const csrfToken = root.dataset.performanceCsrf;
    const urls = {
        test: root.dataset.performanceTestUrl,
        enable: root.dataset.performanceEnableUrl,
        disable: root.dataset.performanceDisableUrl,
    };

    const logEl = root.querySelector('[data-performance-log]');
    const lastTestedEl = root.querySelector('[data-performance-last-tested]');
    const buttons = {
        test: root.querySelector('[data-performance-action="test"]'),
        enable: root.querySelector('[data-performance-action="enable"]'),
        disable: root.querySelector('[data-performance-action="disable"]'),
    };
    const fieldInputs = Array.from(root.querySelectorAll('[data-performance-field]'));

    function appendLog(line, isError) {
        if (!logEl) {
            return;
        }
        const timestamp = new Date().toLocaleTimeString('tr-TR');
        const span = document.createElement('div');
        span.textContent = `[${timestamp}] ${line}`;
        span.className = isError ? 'text-red-400' : 'text-cp-accent';
        logEl.appendChild(span);
        logEl.scrollTop = logEl.scrollHeight;
    }

    // Test/Disable butonları her aksiyon sırasında geçici olarak kilitlenir.
    // Enable butonunun durumu BUNUN DIŞINDA tutulur: o yalnızca en son test
    // sonucuna göre (applyTestResult içinde) açılır/kapanır — setBusy(false)
    // enable'ı körü körüne geri açarsa, başarısız bir testten sonra bile
    // tıklanabilir kalır (sunucu zaten reddeder ama UI yanıltıcı olur).
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
            const now = new Date().toLocaleString('tr-TR');
            lastTestedEl.textContent = `Son test: ${now} — ${data.message}`;
        }

        appendLog(data.message, !data.success);
        if (data.success && typeof data.latencyMs === 'number') {
            appendLog(`Gecikme: ${data.latencyMs.toFixed(1)} ms`, false);
        }
    }

    async function runTest() {
        setBusy(true);
        appendLog('Bağlantı testi başlatıldı…', false);

        try {
            const formData = new FormData();
            formData.append('_token', csrfToken);
            fieldInputs.forEach((input) => {
                formData.append(`config[${input.dataset.performanceField}]`, input.value);
            });

            const response = await fetch(urls.test, { method: 'POST', body: formData });
            const data = await response.json();
            applyTestResult(data);
        } catch (error) {
            appendLog(`İstek başarısız: ${error.message}`, true);
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

            const response = await fetch(url, { method: 'POST', body: formData });
            const data = await response.json();

            if (!data.success) {
                appendLog(data.message || 'İşlem başarısız oldu.', true);
                return;
            }

            appendLog(action === 'enable' ? `${backend} etkinleştirildi.` : `${backend} devre dışı bırakıldı.`, false);
            window.location.reload();
        } catch (error) {
            appendLog(`İstek başarısız: ${error.message}`, true);
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
}

document.querySelectorAll('[data-performance-root]').forEach(initPerformanceCard);

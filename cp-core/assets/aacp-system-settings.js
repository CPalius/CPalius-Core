/**
 * AACP Sistem Yönetimi — captcha alan görünürlüğü ve dil tablosu AJAX.
 */
export function initAacpSystemSettings(root) {
    if (!root) {
        return;
    }

    const providerSelect = root.querySelector('[name="settings[security.captcha_provider]"]');
    if (providerSelect) {
        const recaptchaRows = root.querySelectorAll('[data-captcha-recaptcha-row]');
        const turnstileRows = root.querySelectorAll('[data-captcha-turnstile-row]');

        const syncCaptchaFields = () => {
            const value = providerSelect.value;
            recaptchaRows.forEach((row) => row.classList.toggle('hidden', value !== 'recaptcha'));
            turnstileRows.forEach((row) => row.classList.toggle('hidden', value !== 'turnstile'));
        };

        providerSelect.addEventListener('change', syncCaptchaFields);
        syncCaptchaFields();
    }

    const localesRoot = root.querySelector('[data-locales-root]');
    if (!localesRoot) {
        return;
    }

    const csrfToken = localesRoot.dataset.localesCsrf;

    async function post(url) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
        });
        const data = await response.json();
        if (!response.ok) {
            window.alert(data.error || 'Bir hata oluştu.');
            return null;
        }
        return data;
    }

    localesRoot.addEventListener('click', async (event) => {
        const setDefaultBtn = event.target.closest('[data-locale-set-default]');
        if (setDefaultBtn) {
            const id = setDefaultBtn.dataset.localeId;
            const result = await post(`/aacp/advanced/locales/${id}/set-default`);
            if (result) {
                window.location.reload();
            }
            return;
        }

        const toggleBtn = event.target.closest('[data-locale-toggle-active]');
        if (toggleBtn) {
            const id = toggleBtn.dataset.localeId;
            const result = await post(`/aacp/advanced/locales/${id}/toggle-active`);
            if (result) {
                window.location.reload();
            }
        }
    });
}

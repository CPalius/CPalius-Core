/**
 * CPalius Shell — Wowdash sidebar/dropdown/toast behavior.
 * Flowbite-free minimal JS shared by AACP and Studio chrome.
 */

function initSidebarToggle() {
    const sidebar = document.querySelector('[data-sidebar]');
    const main = document.querySelector('[data-sidebar-main]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const desktopToggle = document.querySelector('[data-sidebar-toggle]');
    const mobileToggle = document.querySelector('[data-sidebar-mobile-toggle]');
    const closeBtn = document.querySelector('[data-sidebar-close]');
    const storageKey = 'cp.sidebar.icon-rail';

    if (!sidebar) {
        return;
    }

    const applyIconRail = (collapsed) => {
        sidebar.classList.toggle('active', collapsed);
        main?.classList.toggle('sidebar-collapsed', collapsed);
    };

    applyIconRail(window.localStorage.getItem(storageKey) === '1');

    const openMobile = () => {
        sidebar.classList.add('sidebar-open');
        overlay?.classList.add('open');
    };

    const closeMobile = () => {
        sidebar.classList.remove('sidebar-open');
        overlay?.classList.remove('open');
    };

    mobileToggle?.addEventListener('click', openMobile);
    closeBtn?.addEventListener('click', closeMobile);
    overlay?.addEventListener('click', closeMobile);

    desktopToggle?.addEventListener('click', () => {
        const next = !sidebar.classList.contains('active');
        applyIconRail(next);
        window.localStorage.setItem(storageKey, next ? '1' : '0');
    });
}

function initSidebarDropdowns() {
    document.querySelectorAll('[data-sidebar-dropdown-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            // Nested inside a parent menu <a> — stop propagation so the link does not navigate.
            event.preventDefault();
            event.stopPropagation();

            const item = trigger.closest('li.dropdown');
            const submenu = item?.querySelector(':scope > .sidebar-submenu');

            if (!item || !submenu) {
                return;
            }

            item.classList.toggle('open');
            submenu.classList.toggle('open');
        });
    });
}

function initDropdownMenus() {
    document.querySelectorAll('[data-dropdown-trigger]').forEach((trigger) => {
        const menu = document.getElementById(trigger.getAttribute('data-dropdown-trigger') ?? '');

        if (!menu) {
            return;
        }

        trigger.addEventListener('click', (event) => {
            event.stopPropagation();
            menu.classList.toggle('open');
        });
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('.dropdown-menu.open').forEach((menu) => {
            if (!(event.target instanceof Node) || !menu.contains(event.target)) {
                menu.classList.remove('open');
            }
        });
    });
}

function initToastAutoDismiss() {
    document.querySelectorAll('[data-toast-stack] .toast').forEach((toast) => {
        // Toasts carrying a link get longer so the link can actually be clicked.
        window.setTimeout(() => {
            toast.style.transition = 'opacity 300ms ease';
            toast.style.opacity = '0';
            window.setTimeout(() => toast.remove(), 320);
        }, toast.querySelector('a') ? 12000 : 5000);
    });
}

// Delete forms that carry no confirmation of their own still ask first.
// Explicit: data-confirm="message" on a <form> or its submit button.
// Implicit: a POST whose URL ends in /delete, /remove, /destroy or /purge — the
// shell-wide safety net for the many one-click delete buttons, so a new module
// gets it without remembering to add an onsubmit. Opt out with data-no-confirm.
const DELETE_URL = /\/(delete|remove|destroy|purge)(\/|\?|$)/i;

function initDeleteConfirm() {
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-confirm')) {
            return;
        }

        const submitter = event.submitter;
        const explicit = submitter?.getAttribute('data-confirm') ?? form.getAttribute('data-confirm');

        if (explicit === null && (form.getAttribute('onsubmit') ?? '').includes('confirm(')) {
            return;
        }

        const action = submitter?.getAttribute('formaction') ?? form.getAttribute('action') ?? '';
        const method = (submitter?.getAttribute('formmethod') ?? form.getAttribute('method') ?? 'get').toLowerCase();

        if (explicit === null && (method !== 'post' || !DELETE_URL.test(action))) {
            return;
        }

        const message = explicit ?? document.body.dataset.confirmDelete ?? 'Are you sure?';

        if (!window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
}

// CSP-safe replacement for inline handlers (no 'unsafe-inline' needed):
//   [data-autosubmit]        change -> submit the owning form
//   [data-nav-url]           change -> navigate to data-nav-url + encoded value
//   form[data-bundle-action] submit -> action = data-bundle-action + bundle field
//   a[data-confirm]          click  -> confirm() first (forms use initDeleteConfirm)
function initInlineHandlerReplacements() {
    document.addEventListener('change', (event) => {
        const el = event.target;

        if (!(el instanceof Element)) {
            return;
        }

        if (el.hasAttribute('data-autosubmit') && el.form) {
            el.form.requestSubmit();
        } else if (el.hasAttribute('data-nav-url')) {
            window.location.assign(el.getAttribute('data-nav-url') + encodeURIComponent(el.value));
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-bundle-action')) {
            return;
        }

        const bundle = form.elements.bundle?.value.trim().toLowerCase() ?? '';

        if (bundle === '') {
            event.preventDefault();

            return;
        }

        form.action = form.getAttribute('data-bundle-action') + encodeURIComponent(bundle);
    }, true);

    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[data-confirm]') : null;

        if (link && !window.confirm(link.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initInlineHandlerReplacements();
    initDeleteConfirm();
    initSidebarToggle();
    initSidebarDropdowns();
    initDropdownMenus();
    initToastAutoDismiss();
});

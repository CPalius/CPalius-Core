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

    if (!sidebar) {
        return;
    }

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
        sidebar.classList.toggle('active');
        main?.classList.toggle('sidebar-collapsed');
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
        window.setTimeout(() => {
            toast.style.transition = 'opacity 300ms ease';
            toast.style.opacity = '0';
            window.setTimeout(() => toast.remove(), 320);
        }, 5000);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initSidebarToggle();
    initSidebarDropdowns();
    initDropdownMenus();
    initToastAutoDismiss();
});

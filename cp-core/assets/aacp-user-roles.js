/**
 * AACP user form — module capability preview for selected roles.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-aacp-user-roles]');
    if (!root) {
        return;
    }

    var catalog = {};
    try {
        catalog = JSON.parse(root.dataset.roleCatalog || '{}');
    } catch (e) {
        return;
    }

    var preview = root.querySelector('[data-aacp-role-preview]');
    var fullAccessLabel = root.dataset.fullAccessLabel || 'Full access';
    var emptyLabel = root.dataset.emptyLabel || 'Select at least one role';
    var moduleLabels = {};

    try {
        moduleLabels = JSON.parse(root.dataset.moduleLabels || '{}');
    } catch (e) {
        moduleLabels = {};
    }

    function moduleLabel(key) {
        return moduleLabels[key] || key;
    }

    function selectedRoleIds() {
        return Array.from(root.querySelectorAll('input[type="checkbox"][name*="[roles]"]:checked'))
            .map(function (input) { return input.value; })
            .filter(Boolean);
    }

    function mergeModules(roleIds) {
        var merged = {};
        var fullAccess = false;

        roleIds.forEach(function (roleId) {
            var entry = catalog[roleId];
            if (!entry) {
                return;
            }
            if (entry.fullAccess) {
                fullAccess = true;
            }
            Object.keys(entry.modules || {}).forEach(function (moduleKey) {
                if (!merged[moduleKey]) {
                    merged[moduleKey] = {};
                }
                (entry.modules[moduleKey] || []).forEach(function (cap) {
                    merged[moduleKey][cap] = true;
                });
            });
        });

        return { modules: merged, fullAccess: fullAccess };
    }

    function render() {
        if (!preview) {
            return;
        }

        var roleIds = selectedRoleIds();
        if (roleIds.length === 0) {
            preview.innerHTML = '<p class="text-fs-xs !text-slate-500">' + escapeHtml(emptyLabel) + '</p>';
            return;
        }

        var summary = mergeModules(roleIds);
        if (summary.fullAccess) {
            preview.innerHTML = '<p class="rounded-md border border-primary-500/30 bg-primary-500/10 px-3 py-2 text-fs-xs !text-primary-200">' + escapeHtml(fullAccessLabel) + '</p>';
            return;
        }

        var moduleKeys = Object.keys(summary.modules).sort();
        if (moduleKeys.length === 0) {
            preview.innerHTML = '<p class="text-fs-xs !text-slate-500">' + escapeHtml(emptyLabel) + '</p>';
            return;
        }

        var html = '<div class="space-y-3">';
        moduleKeys.forEach(function (moduleKey) {
            var caps = Object.keys(summary.modules[moduleKey]).sort();
            html += '<div><p class="mb-1 text-fs-xs font-semibold uppercase tracking-wide !text-slate-400">' + escapeHtml(moduleLabel(moduleKey)) + '</p>';
            html += '<ul class="space-y-0.5">';
            caps.forEach(function (cap) {
                html += '<li class="font-mono text-fs-xs !text-slate-500">' + escapeHtml(cap) + '</li>';
            });
            html += '</ul></div>';
        });
        html += '</div>';
        preview.innerHTML = html;
    }

    function escapeHtml(text) {
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    root.addEventListener('change', function (event) {
        if (event.target && event.target.matches('input[type="checkbox"][name*="[roles]"]')) {
            render();
        }
    });

    render();
})();

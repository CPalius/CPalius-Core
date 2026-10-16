/**
 * AACP module code editor: file tree on the left, highlighted editor on the right.
 * A transparent textarea sits over a highlight.js layer, so the page needs no editor framework.
 */
import hljs from './lib/highlight.esm.min.js';

const LANGUAGES = { php: 'php', twig: 'xml', css: 'css', js: 'javascript', yaml: 'yaml', json: 'json', text: 'plaintext' };
const HIGHLIGHT_LIMIT = 160000;
const STRICT = (path, kind) => kind === 'php' || path.split('/').pop() === 'module.json';

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Folder tree built from flat paths; folders on the current file's path start open. */
function buildTree(root, files, current, hrefFor) {
    const tree = {};
    files.forEach((file) => {
        let node = tree;
        const parts = file.path.split('/');
        parts.forEach((part, index) => {
            node[part] = node[part] || (index === parts.length - 1 ? { __file: file } : {});
            node = node[part];
        });
    });

    const render = (node, container, prefix) => {
        const names = Object.keys(node).sort((a, b) => {
            const aDir = !node[a].__file;
            const bDir = !node[b].__file;
            return aDir === bDir ? a.localeCompare(b) : (aDir ? -1 : 1);
        });
        names.forEach((name) => {
            const child = node[name];
            const item = el('li');
            if (child.__file) {
                const link = el('a', 'ce-file' + (child.__file.path === current ? ' is-current' : ''));
                link.href = hrefFor(child.__file.path);
                link.dataset.path = child.__file.path;
                link.appendChild(el('span', 'ce-ico ce-ico-' + child.__file.kind, child.__file.kind));
                link.appendChild(el('span', 'ce-name', name));
                item.appendChild(link);
            } else {
                const path = prefix + name + '/';
                const details = el('details', 'ce-dir');
                details.open = current.startsWith(path);
                details.dataset.path = path;
                details.appendChild(el('summary', '', name));
                const list = el('ul');
                render(child, list, path);
                details.appendChild(list);
                item.appendChild(details);
            }
            container.appendChild(item);
        });
    };

    const list = el('ul', 'ce-tree-list');
    render(tree, list, '');
    root.appendChild(list);
}

function initTree(root) {
    const data = JSON.parse(root.dataset.files || '[]');
    const holder = root.querySelector('[data-ce-tree]');
    if (!holder) return;
    const current = root.dataset.current || '';
    const base = root.dataset.editorUrl;
    const module = root.dataset.module;
    buildTree(holder, data, current, (path) => `${base}?module=${encodeURIComponent(module)}&file=${encodeURIComponent(path)}`);

    const filter = root.querySelector('[data-ce-filter]');
    if (!filter) return;
    filter.addEventListener('input', () => {
        const needle = filter.value.trim().toLowerCase();
        holder.querySelectorAll('li').forEach((li) => { li.hidden = false; });
        if (needle === '') return;
        holder.querySelectorAll('a.ce-file').forEach((a) => {
            const hit = a.dataset.path.toLowerCase().includes(needle);
            a.parentElement.hidden = !hit;
            if (hit) {
                let parent = a.parentElement.parentElement;
                while (parent && parent !== holder) {
                    if (parent.tagName === 'DETAILS') parent.open = true;
                    parent = parent.parentElement;
                }
            }
        });
        holder.querySelectorAll('details.ce-dir').forEach((d) => {
            d.parentElement.hidden = needle !== '' && [...d.querySelectorAll('a.ce-file')].every((a) => a.parentElement.hidden);
        });
    });
}

function initEditor(root) {
    const form = root.querySelector('[data-ce-form]');
    const input = root.querySelector('[data-ce-input]');
    if (!form || !input) return;

    const code = root.querySelector('[data-ce-code]');
    const gutter = root.querySelector('[data-ce-gutter]');
    const highlight = root.querySelector('[data-ce-highlight]');
    const status = root.querySelector('[data-ce-status]');
    const position = root.querySelector('[data-ce-position]');
    const dirtyBadge = root.querySelector('[data-ce-dirty]');
    const problemsBox = root.querySelector('[data-ce-problems]');
    const force = form.querySelector('[data-ce-force]');
    const kind = input.dataset.kind || 'text';
    const path = root.dataset.current || '';
    const lintUrl = root.dataset.lintUrl;
    const labels = JSON.parse(root.dataset.labels || '{}');
    const language = LANGUAGES[kind] || 'plaintext';
    const original = input.value;
    let dirty = false;
    let lintTimer = 0;
    let lintToken = 0;
    let badLines = new Set();

    const paint = () => {
        const text = input.value;
        const lines = text.split('\n').length;

        let html;
        if (text.length > HIGHLIGHT_LIMIT) {
            html = escapeHtml(text);
        } else {
            try {
                html = hljs.highlight(text, { language, ignoreIllegals: true }).value;
            } catch (error) {
                html = escapeHtml(text);
            }
        }
        code.innerHTML = html + '\n';

        let numbers = '';
        for (let i = 1; i <= lines; i += 1) {
            numbers += `<span${badLines.has(i) ? ' class="is-bad"' : ''}>${i}</span>`;
        }
        gutter.innerHTML = numbers;
        syncScroll();
        updatePosition();
    };

    const syncScroll = () => {
        highlight.scrollTop = input.scrollTop;
        highlight.scrollLeft = input.scrollLeft;
        gutter.scrollTop = input.scrollTop;
    };

    const updatePosition = () => {
        if (!position) return;
        const before = input.value.slice(0, input.selectionStart);
        const line = before.split('\n').length;
        const column = before.length - before.lastIndexOf('\n');
        position.textContent = (labels.position || 'Ln {line}, Col {col}').replace('{line}', line).replace('{col}', column);
    };

    const setStatus = (state, text) => {
        status.dataset.state = state;
        status.textContent = text;
    };

    const showProblems = (problems) => {
        badLines = new Set();
        problems.forEach((problem) => {
            const match = /line\s+(\d+)/i.exec(problem);
            if (match) badLines.add(Number(match[1]));
        });
        problemsBox.textContent = problems.join('\n');
        problemsBox.hidden = problems.length === 0;
        setStatus(problems.length === 0 ? 'ok' : 'bad', problems.length === 0 ? labels.ok : (labels.problems || '{count}').replace('{count}', problems.length));
        paint();
    };

    const lint = async () => {
        const token = ++lintToken;
        setStatus('wait', labels.checking || '…');
        if (kind === 'json') {
            try { JSON.parse(input.value); showProblems([]); } catch (error) { showProblems(['Invalid JSON: ' + error.message]); }
            return;
        }
        const body = new FormData(form);
        body.set('content', input.value);
        try {
            const response = await fetch(lintUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const payload = await response.json();
            if (token === lintToken) showProblems(Array.isArray(payload.problems) ? payload.problems : []);
        } catch (error) {
            if (token === lintToken) setStatus('wait', labels.checkFailed || '?');
        }
    };

    const scheduleLint = () => {
        window.clearTimeout(lintTimer);
        lintTimer = window.setTimeout(lint, 700);
    };

    const markDirty = () => {
        dirty = input.value !== original;
        if (dirtyBadge) dirtyBadge.hidden = !dirty;
    };

    let frame = 0;
    input.addEventListener('input', () => {
        window.cancelAnimationFrame(frame);
        frame = window.requestAnimationFrame(paint);
        markDirty();
        scheduleLint();
    });
    input.addEventListener('scroll', syncScroll);
    ['keyup', 'click', 'focus'].forEach((name) => input.addEventListener(name, updatePosition));

    input.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            form.requestSubmit();
            return;
        }
        if (event.key === 'Tab') {
            event.preventDefault();
            const { selectionStart: start, selectionEnd: end, value } = input;
            if (event.shiftKey) {
                const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                if (value.startsWith('    ', lineStart)) {
                    input.value = value.slice(0, lineStart) + value.slice(lineStart + 4);
                    input.selectionStart = input.selectionEnd = Math.max(lineStart, start - 4);
                }
            } else {
                input.setRangeText('    ', start, end, 'end');
            }
            input.dispatchEvent(new Event('input'));
            return;
        }
        if (event.key === 'Enter' && !event.ctrlKey && !event.metaKey) {
            const { selectionStart: start, value } = input;
            const lineStart = value.lastIndexOf('\n', start - 1) + 1;
            const indent = /^[ \t]*/.exec(value.slice(lineStart, start))[0];
            event.preventDefault();
            input.setRangeText('\n' + indent, start, input.selectionEnd, 'end');
            input.dispatchEvent(new Event('input'));
        }
    });

    form.addEventListener('submit', async (event) => {
        if (force.value === '1') return;
        event.preventDefault();
        window.clearTimeout(lintTimer);

        const body = new FormData(form);
        body.set('content', input.value);
        let problems = [];
        try {
            const response = await fetch(lintUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            problems = (await response.json()).problems || [];
        } catch (error) {
            problems = [];
        }
        showProblems(problems);

        if (problems.length > 0) {
            if (STRICT(path, kind)) {
                window.alert(labels.strict || problems.join('\n'));
                return;
            }
            if (!window.confirm(labels.confirm || '?')) return;
            force.value = '1';
        }
        dirty = false;
        form.submit();
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    root.querySelectorAll('a.ce-file, [data-ce-leave]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (dirty && !window.confirm(labels.unsaved || '?')) event.preventDefault();
            else dirty = false;
        });
    });

    paint();
    lint();
}

const root = document.querySelector('[data-code-editor]');
if (root) {
    initTree(root);
    initEditor(root);
}

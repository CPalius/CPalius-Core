// The stylesheet is NOT imported here, and that is a CSP requirement rather
// than a preference. AssetMapper turns `import './styles/app.css'` into an
// importmap entry served from a `data:application/javascript,...` URL; our
// script-src allows 'self', the nonce and https: but deliberately not data:,
// because a data: script source is a known XSS bypass. The import was
// therefore refused — and a refused STATIC import rejects the whole module
// graph, so one blocked stylesheet shim silently took down every entrypoint
// on the page, charts and panel controls included.
//
// The sheet is linked from the layouts instead (see the stylesheets block).
import './cp-shell.js';
import { initAacpSystemSettings } from './aacp-system-settings.js';

document.addEventListener('DOMContentLoaded', () => {
    initAacpSystemSettings(document.querySelector('[data-system-settings-root]'));

    if (document.querySelector('[data-aacp-security]')) {
        import('./aacp-security.js');
    }
});

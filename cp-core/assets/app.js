import './styles/app.css';
import './cp-shell.js';
import { initAacpSystemSettings } from './aacp-system-settings.js';

document.addEventListener('DOMContentLoaded', () => {
    initAacpSystemSettings(document.querySelector('[data-system-settings-root]'));
});

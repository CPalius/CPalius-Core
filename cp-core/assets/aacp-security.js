/**
 * AACP Security Center — animate the posture gauge score on load.
 */
function animateScore(el, target) {
    const end = Math.max(0, Math.min(100, Number(target) || 0));
    const start = performance.now();
    const duration = 700;

    function frame(now) {
        const t = Math.min(1, (now - start) / duration);
        const eased = 1 - (1 - t) ** 3;
        el.textContent = String(Math.round(end * eased));
        if (t < 1) {
            requestAnimationFrame(frame);
        }
    }

    requestAnimationFrame(frame);
}

document.querySelectorAll('[data-aacp-security]').forEach((root) => {
    const scoreEl = root.querySelector('[data-aacp-security-score-text]');
    if (!scoreEl) {
        return;
    }
    animateScore(scoreEl, root.dataset.aacpSecurityScore);
});

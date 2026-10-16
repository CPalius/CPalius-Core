/* Githeme: light/dark toggle. The pre-paint half lives inline in layout.html.twig. */
(function () {
  var btn = document.getElementById('ghThemeToggle');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var root = document.documentElement;
    var cur = root.getAttribute('data-theme') ||
      (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    var next = cur === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
    try { localStorage.setItem('gh-theme', next); } catch (e) {}
  });
})();

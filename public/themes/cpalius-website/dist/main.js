/* ================================================
   CPalius CMF — Main JavaScript
   Sticky navbar, smooth scroll, scroll animations,
   mobile menu, counter animation
   ================================================ */

(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }

  // ---- DOM Ready ----
  document.addEventListener('DOMContentLoaded', function () {
    initNavbar();
    initSmoothScroll();
    initScrollAnimations();
    initMobileMenu();
    initNavbarSearch();
    initNavbarUserMenu();
    initNavbarAlerts();
    initInboxPulse();
    initInboxMarkAll();
    initLocaleSwitcher();
    initCounterAnimation();
    initActiveNavLink();
    initPortalSliders();
    initSiteSearchLive();
  });

  // ---- Sticky Navbar ----
  function initNavbar() {
    var navbar = document.getElementById('navbar');
    if (!navbar) return;

    var scrollThreshold = 80;

    window.addEventListener('scroll', function () {
      if (window.scrollY > scrollThreshold) {
        navbar.classList.add('scrolled');
      } else {
        navbar.classList.remove('scrolled');
      }
    }, { passive: true });
  }

  // ---- Smooth Scroll ----
  function initSmoothScroll() {
    var links = document.querySelectorAll('a[href^="#"]');

    links.forEach(function (link) {
      link.addEventListener('click', function (e) {
        var targetId = this.getAttribute('href');
        if (targetId === '#') return;

        var targetEl = document.querySelector(targetId);
        if (!targetEl) return;

        e.preventDefault();

        var navbarH = document.getElementById('navbar').offsetHeight;
        var targetPos = targetEl.getBoundingClientRect().top + window.scrollY - navbarH;

        window.scrollTo({
          top: targetPos,
          behavior: 'smooth'
        });

        // Close mobile menu if open
        var navCollapse = document.getElementById('navCollapse');
        if (navCollapse && navCollapse.classList.contains('open')) {
          navCollapse.classList.remove('open');
          var navToggleIcon = document.querySelector('#navToggle i');
          if (navToggleIcon) navToggleIcon.className = 'bi bi-list';
        }
      });
    });
  }

  // ---- Scroll Animations (Intersection Observer) ----
  function initScrollAnimations() {
    var animElements = document.querySelectorAll('.fade-in, .fade-in-left, .fade-in-right');

    if (!animElements.length) return;

    // Fallback for older browsers
    if (!('IntersectionObserver' in window)) {
      animElements.forEach(function (el) {
        el.classList.add('visible');
      });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          // Stagger children if present
          var delay = entry.target.dataset.delay;
          if (delay) {
            setTimeout(function () {
              entry.target.classList.add('visible');
            }, parseInt(delay, 10));
          } else {
            entry.target.classList.add('visible');
          }
          observer.unobserve(entry.target);
        }
      });
    }, {
      threshold: 0.15,
      rootMargin: '0px 0px -40px 0px'
    });

    animElements.forEach(function (el) {
      observer.observe(el);
    });
  }

  // ---- Mobile Menu Toggle ----
  function initMobileMenu() {
    var toggle = document.getElementById('navToggle');
    var menu = document.getElementById('navCollapse');

    if (!toggle || !menu) return;

    toggle.addEventListener('click', function () {
      var isOpen = menu.classList.toggle('open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

      var icon = toggle.querySelector('i');
      icon.className = isOpen ? 'bi bi-x-lg' : 'bi bi-list';
    });
  }

  // ---- Navbar Search Toggle + live results ----
  function bindLiveSearch(form, input, panel) {
    if (!form || !input || !panel) return;
    var endpoint = form.getAttribute('data-live-url');
    if (!endpoint) return;

    var emptyText = form.getAttribute('data-live-empty') || '';
    var moreText = form.getAttribute('data-live-more') || '';
    var timer = null;
    var inflight = null;

    function hide() {
      panel.innerHTML = '';
      panel.hidden = true;
      panel.classList.remove('is-open');
    }

    function show(html) {
      panel.innerHTML = html;
      panel.hidden = false;
      panel.classList.add('is-open');
    }

    function render(payload) {
      var groups = payload && payload.groups ? payload.groups : [];
      if (!groups.length) {
        show('<p class="navbar-search__live-empty">' + escapeHtml(emptyText) + '</p>');
        return;
      }

      show(groups.map(function (group) {
        var icon = group.icon ? String(group.icon).replace(/[^a-z0-9-]/gi, '') : '';
        var hits = (group.hits || []).map(function (hit) {
          return '<a class="navbar-search__live-hit" href="' + escapeHtml(hit.url || '#') + '">' +
            '<span>' + escapeHtml(hit.title || '') + '</span>' +
            (hit.excerpt ? '<small>' + escapeHtml(hit.excerpt) + '</small>' : '') +
            '</a>';
        }).join('');
        var more = group.moreUrl
          ? '<a class="navbar-search__live-more" href="' + escapeHtml(group.moreUrl) + '">' + escapeHtml(moreText) + '</a>'
          : '';
        return '<div class="navbar-search__live-group">' +
          '<p class="navbar-search__live-label">' + (icon ? '<i class="bi ' + icon + '"></i> ' : '') + escapeHtml(group.label || '') + '</p>' +
          hits + more +
          '</div>';
      }).join(''));
    }

    function query() {
      var term = String(input.value || '').trim();
      if (term.length < 2) {
        hide();
        return;
      }
      if (inflight) inflight.abort();
      inflight = new AbortController();
      var join = endpoint.indexOf('?') === -1 ? '?' : '&';
      fetch(endpoint + join + 'q=' + encodeURIComponent(term), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        signal: inflight.signal
      }).then(function (res) {
        if (!res.ok) throw new Error('live');
        return res.json();
      }).then(render).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        hide();
      });
    }

    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(query, 250);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') hide();
    });

    return { hide: hide };
  }

  function initNavbarSearch() {
    var wrapper = document.getElementById('navSearch');
    var toggle = document.getElementById('navSearchToggle');
    var input = wrapper ? wrapper.querySelector('.navbar-search__input') : null;
    var form = document.getElementById('navSearchForm');
    var live = document.getElementById('navSearchLive');

    if (!wrapper || !toggle) return;

    var liveApi = bindLiveSearch(form, input, live);

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = wrapper.classList.toggle('open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (isOpen && input) input.focus();
      if (!isOpen && liveApi) liveApi.hide();
    });

    document.addEventListener('click', function (e) {
      if (!wrapper.contains(e.target)) {
        wrapper.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        if (liveApi) liveApi.hide();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        wrapper.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        if (liveApi) liveApi.hide();
      }
    });
  }

  function initSiteSearchLive() {
    var form = document.querySelector('.site-search-form[data-live-url]');
    var input = document.getElementById('site-search-q');
    var live = document.getElementById('siteSearchLive');
    bindLiveSearch(form, input, live);
  }

  // ---- Locale switcher (details) ----
  function initLocaleSwitcher() {
    var switcher = document.getElementById('navLocale');
    if (!switcher) return;

    document.addEventListener('click', function (e) {
      if (!switcher.contains(e.target)) {
        switcher.removeAttribute('open');
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        switcher.removeAttribute('open');
      }
    });
  }

  // ---- Navbar User Dropdown ----
  function initNavbarUserMenu() {
    var wrapper = document.getElementById('navUser');
    var trigger = document.getElementById('navUserTrigger');

    if (!wrapper || !trigger) return;

    function closeUserMenu() {
      wrapper.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      // The alert panels are inside this dropdown now, so closing them here
      // would throw away the tab the visitor last had open.
      var isOpen = wrapper.classList.toggle('open');
      trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
      if (!wrapper.contains(e.target)) {
        closeUserMenu();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        closeUserMenu();
      }
    });
  }

  function closeNavbarAlerts(except) {
    document.querySelectorAll('.navbar-alert.is-open').forEach(function (alert) {
      if (alert === except) return;
      alert.classList.remove('is-open');
      var btn = alert.querySelector('.navbar-alert__btn');
      var panel = alert.querySelector('.navbar-alert__panel');
      if (btn) btn.setAttribute('aria-expanded', 'false');
      if (panel) panel.hidden = true;
    });
  }

  function initNavbarAlerts() {
    var alerts = document.querySelectorAll('.navbar-alert');
    if (!alerts.length) return;

    alerts.forEach(function (alert) {
      var btn = alert.querySelector('.navbar-alert__btn');
      var panel = alert.querySelector('.navbar-alert__panel');
      if (!btn || !panel) return;

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var willOpen = !alert.classList.contains('is-open');
        closeNavbarAlerts(willOpen ? alert : null);
        alert.classList.toggle('is-open', willOpen);
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        panel.hidden = !willOpen;
      });

      panel.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    });

    document.addEventListener('click', function (e) {
      var user = document.getElementById('navUser');
      if (user && user.contains(e.target)) return;
      closeNavbarAlerts();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeNavbarAlerts();
    });
  }

  // ---- "Mark all read" inside the header flyouts ----
  //
  // These were plain form posts, so clearing a badge cost a full page load and
  // dropped you back at the top of whatever you were reading. Submitting them
  // in place keeps the menu open and zeroes the counts immediately; the form
  // still works normally if this never runs.
  function initInboxMarkAll() {
    document.addEventListener('submit', function (e) {
      var form = e.target.closest('[data-inbox-mark-all]');
      if (!form) return;

      e.preventDefault();

      var channel = form.closest('[data-inbox-channel]');
      var name = channel ? channel.getAttribute('data-inbox-channel') : '';

      fetch(form.getAttribute('action'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        body: new FormData(form)
      }).then(function (res) {
        if (!res.ok) throw new Error('mark-all');
        return res;
      }).then(function () {
        clearChannel(channel, name);
      }).catch(function () {
        // Network or server refused: fall back to the ordinary post so the
        // action still happens rather than silently doing nothing.
        form.removeAttribute('data-inbox-mark-all');
        form.submit();
      });
    });
  }

  function clearChannel(channel, name) {
    if (channel) {
      channel.querySelectorAll('[data-inbox-badge]').forEach(function (badge) {
        badge.textContent = '0';
        badge.setAttribute('hidden', '');
      });
      var label = channel.querySelector('[data-inbox-unread-label]');
      if (label) label.textContent = '';
      var list = channel.querySelector('[data-inbox-list]');
      if (list) {
        var empty = list.getAttribute('data-inbox-empty') || '';
        list.innerHTML = '<p class="navbar-notif-box__empty">' + escapeHtml(empty) + '</p>';
      }
      var form = channel.querySelector('[data-inbox-mark-all]');
      if (form) form.setAttribute('hidden', 'hidden');
    }

    if (name) {
      document.querySelectorAll('[data-inbox-count="' + name + '"]').forEach(function (el) {
        el.textContent = '0';
        el.setAttribute('hidden', '');
      });
    }

    // The tab title carries the combined count; recompute it from what is left
    // on screen rather than waiting for the next heartbeat.
    var total = 0;
    document.querySelectorAll('[data-inbox-count]').forEach(function (el) {
      if (!el.hasAttribute('hidden')) total += parseInt(el.textContent, 10) || 0;
    });
    var base = document.title.replace(/^\(\d+\)\s/, '');
    document.title = total > 0 ? '(' + total + ') ' + base : base;
  }

  function initInboxPulse() {
    var node = document.getElementById('cp-inbox-pulse');
    if (!node) return;

    var config;
    try {
      config = JSON.parse(node.textContent || '{}');
    } catch (err) {
      return;
    }
    if (!config.url) return;

    var seen = {};
    var primed = false;
    var delay = config.interval || 8000;
    var idle = 0;
    var timer = null;
    var audioUnlocked = false;
    var audioEl = null;
    var originalTitle = document.title;

    function unlockAudio() {
      audioUnlocked = true;
      document.removeEventListener('click', unlockAudio);
      document.removeEventListener('keydown', unlockAudio);
    }
    document.addEventListener('click', unlockAudio);
    document.addEventListener('keydown', unlockAudio);

    function playMessageSound(url) {
      if (!url || !audioUnlocked) return;
      try {
        if (!audioEl || audioEl.getAttribute('src') !== url) {
          audioEl = new Audio(url);
          audioEl.preload = 'auto';
        }
        audioEl.currentTime = 0;
        var play = audioEl.play();
        if (play && typeof play.catch === 'function') play.catch(function () {});
      } catch (err) {}
    }

    function paintCount(el, count) {
      var n = Math.max(0, parseInt(count, 10) || 0);
      el.textContent = n > 99 ? '99+' : String(n);
      if (n > 0) el.removeAttribute('hidden');
      else el.setAttribute('hidden', '');
    }

    function setBadge(root, count) {
      var badge = root.querySelector('[data-inbox-badge]');
      if (!badge) return;
      paintCount(badge, count);
    }

    // The counts on the avatar live outside the channel container — the trigger
    // is visible while the dropdown that holds the tabs is not — so they are
    // found by channel name rather than by walking down from the panel.
    function setTriggerCounts(name, count) {
      document.querySelectorAll('[data-inbox-count="' + name + '"]').forEach(function (el) {
        paintCount(el, count);
      });
    }

    function setLabel(root, unread) {
      var label = root.querySelector('[data-inbox-unread-label]');
      if (!label) return;
      label.textContent = unread > 0 ? String(unread) : '';
    }

    function renderItems(root, items) {
      var list = root.querySelector('[data-inbox-list]');
      if (!list || !items) return;
      items = items.filter(function (item) { return item.unread; });
      if (!items.length) {
        var empty = list.getAttribute('data-inbox-empty') || '';
        list.innerHTML = '<p class="navbar-notif-box__empty">' + escapeHtml(empty) + '</p>';
        return;
      }
      var html = items.map(function (item) {
        var icon = item.icon ? String(item.icon).replace(/[^a-z0-9-]/gi, '') : 'bell';
        var href = item.url ? String(item.url) : '#';
        var text = item.text ? String(item.text) : '';
        var time = item.created_label ? String(item.created_label) : '';
        var stamp = item.created_at ? String(item.created_at) : '';
        var unreadClass = item.unread ? ' is-unread' : '';
        var id = item.id != null ? String(item.id) : '';
        return '<a href="' + escapeHtml(href) + '" class="navbar-notif-box__item' + unreadClass + '" data-inbox-id="' + escapeHtml(id) + '">' +
          '<span class="navbar-notif-box__icon"><i class="bi bi-' + icon + '"></i></span>' +
          '<span class="navbar-notif-box__text">' + escapeHtml(text) +
          (time ? '<time datetime="' + escapeHtml(stamp) + '">' + escapeHtml(time) + '</time>' : '') +
          '</span></a>';
      }).join('');
      list.innerHTML = html;
    }

    function totalUnread(channels) {
      var sum = 0;
      Object.keys(channels || {}).forEach(function (key) {
        sum += Math.max(0, parseInt(channels[key].unread, 10) || 0);
      });
      return sum;
    }

    function apply(payload) {
      var channels = payload && payload.channels ? payload.channels : {};
      Object.keys(channels).forEach(function (name) {
        var data = channels[name] || {};
        var root = document.querySelector('[data-inbox-channel="' + name + '"]');
        if (root) {
          setBadge(root, data.unread);
          setLabel(root, data.unread);
          renderItems(root, data.items);
        }
        setTriggerCounts(name, data.unread);
        var latest = parseInt(data.latest_id, 10) || 0;
        var unread = parseInt(data.unread, 10) || 0;
        var prev = seen[name];
        if (primed && name === 'messages' && prev && (latest > prev.latest || unread > prev.unread)) {
          playMessageSound(data.sound_url || root && root.getAttribute('data-inbox-sound'));
        }
        seen[name] = { latest: latest, unread: unread };
      });
      primed = true;
      var unreadTotal = totalUnread(channels);
      if (unreadTotal > 0) {
        document.title = '(' + unreadTotal + ') ' + originalTitle.replace(/^\(\d+\)\s/, '');
      } else {
        document.title = originalTitle.replace(/^\(\d+\)\s/, '');
      }
    }

    function schedule() {
      window.clearTimeout(timer);
      timer = window.setTimeout(tick, document.hidden ? Math.max(delay, 30000) : delay);
    }

    function tick() {
      fetch(config.url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (res) {
        if (!res.ok) throw new Error('pulse');
        return res.json();
      }).then(function (payload) {
        var before = JSON.stringify(seen);
        apply(payload);
        var changed = JSON.stringify(seen) !== before;
        if (changed) {
          idle = 0;
          delay = config.interval || 8000;
        } else {
          idle += 1;
          if (idle > 6) delay = 45000;
          else if (idle > 2) delay = 20000;
        }
      }).catch(function () {
        delay = Math.min(delay * 2, 60000);
      }).then(schedule);
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        delay = config.interval || 8000;
        tick();
      }
    });

    tick();
  }

  // ---- Active Nav Link on Scroll ----
  function initActiveNavLink() {
    var sections = document.querySelectorAll('section[id]');
    var navLinks = document.querySelectorAll('.navbar-menu a');

    if (!sections.length || !navLinks.length) return;

    window.addEventListener('scroll', function () {
      var scrollPos = window.scrollY + 120;

      sections.forEach(function (section) {
        var sectionTop = section.offsetTop;
        var sectionHeight = section.offsetHeight;
        var sectionId = section.getAttribute('id');

        if (scrollPos >= sectionTop && scrollPos < sectionTop + sectionHeight) {
          navLinks.forEach(function (link) {
            link.classList.remove('active');
            if (link.getAttribute('href') === '#' + sectionId) {
              link.classList.add('active');
            }
          });
        }
      });
    }, { passive: true });
  }

  // ---- Counter Animation ----
  function initCounterAnimation() {
    var counters = document.querySelectorAll('[data-count]');

    if (!counters.length) return;

    if (!('IntersectionObserver' in window)) {
      counters.forEach(function (el) {
        el.textContent = el.dataset.count;
      });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          animateCounter(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.5 });

    counters.forEach(function (el) {
      observer.observe(el);
    });
  }

  function animateCounter(el) {
    var target = el.dataset.count;
    var prefix = el.dataset.prefix || '';
    var suffix = el.dataset.suffix || '';

    // Handle non-numeric targets (like infinity symbol)
    if (isNaN(parseInt(target, 10))) {
      el.textContent = prefix + target + suffix;
      return;
    }

    var end = parseInt(target, 10);
    var duration = 1500;
    var startTime = null;

    function step(timestamp) {
      if (!startTime) startTime = timestamp;
      var progress = Math.min((timestamp - startTime) / duration, 1);

      // Ease out quad
      var easedProgress = 1 - Math.pow(1 - progress, 3);
      var current = Math.floor(easedProgress * end);

      el.textContent = prefix + current + suffix;

      if (progress < 1) {
        requestAnimationFrame(step);
      } else {
        el.textContent = prefix + target + suffix;
      }
    }

    requestAnimationFrame(step);
  }

  // ---- Portal content sliders ----
  function initPortalSliders() {
    document.querySelectorAll('[data-portal-slider]').forEach(function (slider) {
      var track = slider.querySelector('[data-portal-slider-track]');
      var prev = slider.querySelector('[data-portal-slider-prev]');
      var next = slider.querySelector('[data-portal-slider-next]');
      if (!track) return;

      function scrollByCard(dir) {
        var amount = Math.max(240, Math.floor(track.clientWidth * 0.8)) * dir;
        track.scrollBy({ left: amount, behavior: 'smooth' });
      }

      if (prev) prev.addEventListener('click', function () { scrollByCard(-1); });
      if (next) next.addEventListener('click', function () { scrollByCard(1); });

      if (!slider.hasAttribute('data-autoplay')) return;

      var paused = false;
      var timer = setInterval(function () {
        if (paused || document.hidden) return;
        var maxScroll = track.scrollWidth - track.clientWidth;
        if (maxScroll <= 4) return;
        if (track.scrollLeft >= maxScroll - 8) {
          track.scrollTo({ left: 0, behavior: 'smooth' });
        } else {
          scrollByCard(1);
        }
      }, 4200);

      slider.addEventListener('mouseenter', function () { paused = true; });
      slider.addEventListener('mouseleave', function () { paused = false; });
      slider.addEventListener('focusin', function () { paused = true; });
      slider.addEventListener('focusout', function () { paused = false; });

      // Keep reference so GC doesn't clear; stop on page hide is enough via document.hidden
      slider._autoplayTimer = timer;
    });
  }

})();

/* ================================================
   CPalius CMF — Main JavaScript
   Sticky navbar, smooth scroll, scroll animations,
   mobile menu, counter animation
   ================================================ */

(function () {
  'use strict';

  // ---- DOM Ready ----
  document.addEventListener('DOMContentLoaded', function () {
    initNavbar();
    initSmoothScroll();
    initScrollAnimations();
    initMobileMenu();
    initNavbarSearch();
    initNavbarUserMenu();
    initCounterAnimation();
    initActiveNavLink();
    initPortalSliders();
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

  // ---- Navbar Search Toggle ----
  function initNavbarSearch() {
    var wrapper = document.getElementById('navSearch');
    var toggle = document.getElementById('navSearchToggle');
    var input = wrapper ? wrapper.querySelector('.navbar-search__input') : null;

    if (!wrapper || !toggle) return;

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = wrapper.classList.toggle('open');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (isOpen && input) input.focus();
    });

    document.addEventListener('click', function (e) {
      if (!wrapper.contains(e.target)) {
        wrapper.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        wrapper.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // ---- Navbar User Dropdown ----
  function initNavbarUserMenu() {
    var wrapper = document.getElementById('navUser');
    var trigger = document.getElementById('navUserTrigger');

    if (!wrapper || !trigger) return;

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      var isOpen = wrapper.classList.toggle('open');
      trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', function (e) {
      if (!wrapper.contains(e.target)) {
        wrapper.classList.remove('open');
        trigger.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        wrapper.classList.remove('open');
        trigger.setAttribute('aria-expanded', 'false');
      }
    });
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

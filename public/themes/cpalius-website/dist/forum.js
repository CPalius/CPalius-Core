/* CPalius Forum front-end: confirm, quote, and lightweight post formatting.
   No dependencies — does not touch main.js fade-in/navbar behavior. */
(function () {
  'use strict';

  function formUrl(form) {
    return form.getAttribute('data-endpoint') || form.getAttribute('action') || '';
  }

  function forumI18n(key, fallback) {
    var root = document.getElementById('forum') || document.querySelector('.forum-page');
    if (!root) return fallback;
    var value = root.getAttribute('data-i18n-' + key);
    return value || fallback;
  }

  document.addEventListener('DOMContentLoaded', function () {
    initConfirm();
    initQuoteButtons();
    initReportButtons();
    initLikeForms();
    initDislikeForms();
    initShareButtons();
    initActivityPanel();
    initReputationModal();
    initThreadTools();
    initMultiQuote();
    initSharePostButtons();
    initMoveForms();
    initImodSelectAll();
    initLightbox();
    initUnfurl();
    initMediaEmbeds();
    initSelectionQuote();
    initHoverCards();
  });

  // ---- Confirmation for delete/dangerous actions ----
  function initConfirm() {
    document.addEventListener('click', function (e) {
      var el = e.target.closest('[data-confirm]');
      if (!el) return;

      var message = el.getAttribute('data-confirm') || 'Emin misiniz?';
      if (!window.confirm(message)) {
        e.preventDefault();
        e.stopPropagation();
      }
    });
  }

  // ---- "Quote" buttons ----
  // The reply box may be enriched with CKEditor 5 (forum-editor-init.js,
  // textarea.__cpForumEditor). If the editor is not ready yet, write to raw textarea.value;
  // CKEditor reads the textarea's current value during init, so data is not lost in that case.
  function initQuoteButtons() {
    var buttons = document.querySelectorAll('[data-quote-btn]');
    if (!buttons.length) return;

    var replyBody = document.getElementById('forum-reply-body');

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!replyBody) return;

        var author = btn.getAttribute('data-quote-author') || '';
        var text = (btn.getAttribute('data-quote-body') || '').trim();
        var postId = btn.getAttribute('data-quote-post') || '';
        var quotedHtml = buildQuoteHtml(author, text, postId);

        appendToEditor(replyBody, quotedHtml);
        scrollToReply(replyBody);
      });
    });
  }

  function initMultiQuote() {
    var applyBtn = document.querySelector('[data-multi-quote-apply]');
    var replyBody = document.getElementById('forum-reply-body');
    if (!applyBtn || !replyBody) return;

    function refreshButton() {
      // Selection snippets count too: a reader who only picked sentences and
      // ticked no checkbox still has something waiting to be inserted.
      var any = document.querySelector('[data-multi-quote]:checked') || pendingSnippets.length > 0;
      if (any) {
        applyBtn.removeAttribute('hidden');
      } else {
        applyBtn.setAttribute('hidden', 'hidden');
      }
    }

    document.querySelectorAll('[data-multi-quote]').forEach(function (cb) {
      cb.addEventListener('change', refreshButton);
    });

    applyBtn.addEventListener('click', function () {
      // Snippets first: they were picked deliberately, so they read better at
      // the top of the reply than after a run of whole-post quotes.
      var blocks = pendingSnippets.slice();
      pendingSnippets.length = 0;

      document.querySelectorAll('[data-multi-quote]:checked').forEach(function (cb) {
        var author = cb.getAttribute('data-quote-author') || '';
        var text = (cb.getAttribute('data-quote-body') || '').trim();
        var postId = cb.getAttribute('data-quote-post') || '';
        if (!text) return;
        blocks.push(buildQuoteHtml(author, text, postId));
        cb.checked = false;
      });
      if (blocks.length === 0) return;
      appendToEditor(replyBody, blocks.join(''));
      refreshButton();
      scrollToReply(replyBody);
    });
  }

  function buildQuoteHtml(author, text, postId) {
    var quoteData = postId ? ' data-post="' + escapeHtml(postId) + '"' : '';
    return '<blockquote class="cp-quote-post-' + escapeHtml(postId) + '"' + quoteData + '><strong>' + escapeHtml(author) + ':</strong><p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p></blockquote><p></p>';
  }

  function appendToEditor(replyBody, html) {
    var editor = replyBody.__cpForumEditor;
    if (editor) {
      editor.setData(editor.getData() + html);
    } else {
      replyBody.value = replyBody.value ? replyBody.value + '\n' + html.replace(/<[^>]+>/g, ' ') : html.replace(/<[^>]+>/g, ' ');
    }
  }

  function scrollToReply(replyBody) {
    var anchor = document.getElementById('reply');
    if (anchor) anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
    replyBody.focus();
  }

  function initSharePostButtons() {
    document.querySelectorAll('[data-share-post]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var post = btn.closest('.forum-post');
        var url = window.location.href.split('#')[0] + (post && post.id ? '#' + post.id : '');
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText(url).then(function () {
          var label = btn.getAttribute('aria-label') || '';
          var icon = btn.querySelector('i');
          btn.setAttribute('aria-label', forumI18n('link-copied', 'Link copied'));
          btn.classList.add('is-copied');
          if (icon) icon.className = 'bi bi-check-lg';
          window.setTimeout(function () {
            btn.setAttribute('aria-label', label);
            btn.classList.remove('is-copied');
            if (icon) icon.className = 'bi bi-share';
          }, 1800);
        });
      });
    });
  }

  function initThreadTools() {
    document.querySelectorAll('[data-thread-tools]').forEach(function (root) {
      var toggle = root.querySelector('[data-thread-tools-toggle]');
      var menu = root.querySelector('.forum-tools__menu');
      var header = root.closest('.forum-header');
      if (!toggle || !menu) return;

      function closeAll() {
        document.querySelectorAll('.forum-tools__menu').forEach(function (other) {
          other.setAttribute('hidden', 'hidden');
        });
        document.querySelectorAll('.forum-header--tools-open').forEach(function (el) {
          el.classList.remove('forum-header--tools-open');
        });
      }

      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = menu.hasAttribute('hidden');
        closeAll();
        if (open) {
          menu.removeAttribute('hidden');
          if (header) header.classList.add('forum-header--tools-open');
        }
      });
    });

    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-thread-tools]')) return;
      document.querySelectorAll('.forum-tools__menu').forEach(function (menu) {
        menu.setAttribute('hidden', 'hidden');
      });
      document.querySelectorAll('.forum-header--tools-open').forEach(function (el) {
        el.classList.remove('forum-header--tools-open');
      });
    });
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // textContent escapes < > &, but not the quotes that would end an attribute.
  function escapeAttr(value) {
    return escapeHtml(String(value == null ? '' : value))
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  // ---- Reputation modal (profile + postbit) ----
  function initReputationModal() {
    var modal = document.querySelector('[data-rep-modal]');
    if (!modal) return;

    var form = modal.querySelector('form');
    var titleEl = modal.querySelector('[data-rep-title], .forum-rep-modal__header h2');
    var topicInput = modal.querySelector('[data-rep-topic-input]');
    var postInput = modal.querySelector('[data-rep-post-input]');

    document.querySelectorAll('[data-rep-open]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var identifier = btn.getAttribute('data-rep-user');
        var name = btn.getAttribute('data-rep-name') || '';
        var postId = btn.getAttribute('data-rep-post') || '';
        var topicId = btn.getAttribute('data-rep-topic') || '';

        if (form && identifier && modal.hasAttribute('data-rep-thread')) {
          var localeMatch = window.location.pathname.match(/^\/(tr|en)\//);
          var locale = localeMatch ? localeMatch[1] : 'tr';
          form.action = '/' + locale + '/forum/uye/' + encodeURIComponent(identifier) + '/rep';
        }

        if (titleEl && modal.hasAttribute('data-rep-thread')) {
          titleEl.textContent = name;
        }

        if (topicInput) topicInput.value = topicId;
        if (postInput) postInput.value = postId;

        if (typeof modal.showModal === 'function') {
          modal.showModal();
        } else {
          modal.setAttribute('open', 'open');
        }
      });
    });

    modal.querySelectorAll('[data-rep-close]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (typeof modal.close === 'function') modal.close();
        else modal.removeAttribute('open');
      });
    });

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        if (typeof modal.close === 'function') modal.close();
        else modal.removeAttribute('open');
      }
    });
  }

  // ---- "Report" buttons — ask for reason, write to hidden form, submit ----
  function initReportButtons() {
    document.querySelectorAll('[data-report-btn]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('[data-report-form]');
        if (!form) return;

        var reason = window.prompt(forumI18n('report-prompt', 'Why are you reporting this post? (briefly explain)'));
        if (!reason || !reason.trim()) return;

        form.querySelector('input[name="reason"]').value = reason.trim();
        form.submit();
      });
    });
  }

  // ---- Like / dislike — AJAX toggle ----
  // Do NOT call native form.submit() in catch: if fetch reached the server,
  // a second POST would undo the like (page reloads, heart appears empty).
  function initReactionForms(selector, options) {
    document.querySelectorAll(selector).forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();

        var btn = form.querySelector(options.buttonSelector) || form.querySelector('button[type="submit"]');
        if (!btn || btn.disabled) {
          return;
        }

        var icon = btn.querySelector('i');
        var countEl = btn.querySelector(options.countSelector);
        var wrap = form.closest('.forum-post__engagement') || form.closest('.forum-post');
        var siblingForm = wrap && options.siblingSelector ? wrap.querySelector(options.siblingSelector) : null;

        btn.disabled = true;

      fetch(formUrl(form), {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
          },
          body: new FormData(form),
          credentials: 'same-origin',
        })
          .then(function (res) {
            var contentType = res.headers.get('Content-Type') || '';
            if (!res.ok || contentType.indexOf('application/json') === -1) {
              throw new Error('reaction_failed');
            }
            return res.json();
          })
          .then(function (data) {
            var active = !!data[options.activeKey];
            btn.classList.toggle(options.activeClass, active);
            if (icon) {
              icon.className = 'bi ' + (active ? options.iconOn : options.iconOff);
            }
            if (countEl && typeof data.count === 'number') {
              countEl.textContent = data.count;
            }

            if (active && siblingForm) {
              var sibBtn = siblingForm.querySelector('button[type="submit"]');
              if (sibBtn) {
                sibBtn.classList.remove(options.siblingActiveClass);
                var sibIcon = sibBtn.querySelector('i');
                if (sibIcon) {
                  sibIcon.className = 'bi ' + options.siblingIconOff;
                }
              }
              if (typeof data.siblingCount === 'number') {
                var sibCount = siblingForm.querySelector(options.siblingCountSelector);
                if (sibCount) {
                  sibCount.textContent = data.siblingCount;
                }
              }
            }
          })
          .catch(function () { /* do not resubmit natively */ })
          .finally(function () {
            btn.disabled = false;
          });
      });
    });
  }

  function initLikeForms() {
    initReactionForms('[data-like-form]', {
      buttonSelector: '.forum-like-btn, .forum-tool-btn--like',
      countSelector: '[data-like-count]',
      activeKey: 'liked',
      activeClass: 'is-liked',
      iconOn: 'bi-heart-fill',
      iconOff: 'bi-heart',
      siblingSelector: '[data-dislike-form]',
      siblingActiveClass: 'is-disliked',
      siblingIconOff: 'bi-hand-thumbs-down',
      siblingCountSelector: '[data-dislike-count]',
    });
  }

  function initDislikeForms() {
    initReactionForms('[data-dislike-form]', {
      buttonSelector: '.forum-dislike-btn, .forum-tool-btn--dislike',
      countSelector: '[data-dislike-count]',
      activeKey: 'disliked',
      activeClass: 'is-disliked',
      iconOn: 'bi-hand-thumbs-down-fill',
      iconOff: 'bi-hand-thumbs-down',
      siblingSelector: '[data-like-form]',
      siblingActiveClass: 'is-liked',
      siblingIconOff: 'bi-heart',
      siblingCountSelector: '[data-like-count]',
    });
  }

  // ---- Share — native Web Share API when available, otherwise copy to clipboard ----
  function initShareButtons() {
    document.querySelectorAll('[data-share-btn]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var wrapper = btn.closest('[data-share]');
        var title = wrapper ? wrapper.getAttribute('data-share-title') : document.title;
        var url = window.location.href;

        if (navigator.share) {
          navigator.share({ title: title, url: url }).catch(function () {});
          return;
        }

        if (navigator.clipboard) {
          navigator.clipboard.writeText(url).then(function () {
            var original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check-lg"></i> ' + forumI18n('link-copied', 'Link copied');
            setTimeout(function () { btn.innerHTML = original; }, 2000);
          });
        }
      });
    });
  }

  // ---- Recent activity panel (tabs + AJAX load more) ----
  function initActivityPanel() {
    var root = document.querySelector('[data-forum-activity]');
    if (!root) return;

    var baseUrl = root.getAttribute('data-activity-url');
    var loadMoreStep = parseInt(root.getAttribute('data-load-more') || '5', 10) || 5;

    var tabButtons = Array.prototype.slice.call(root.querySelectorAll('[data-activity-tab]'));
    tabButtons.forEach(function (tabBtn, i) {
      // WAI-ARIA tabs: one tab stop for the whole list, arrows move between tabs.
      tabBtn.addEventListener('keydown', function (e) {
        var next = null;
        if (e.key === 'ArrowRight') next = tabButtons[(i + 1) % tabButtons.length];
        else if (e.key === 'ArrowLeft') next = tabButtons[(i - 1 + tabButtons.length) % tabButtons.length];
        else if (e.key === 'Home') next = tabButtons[0];
        else if (e.key === 'End') next = tabButtons[tabButtons.length - 1];
        if (!next) return;
        e.preventDefault();
        next.focus();
        next.click();
      });
      tabBtn.addEventListener('click', function () {
        var tab = tabBtn.getAttribute('data-activity-tab');
        tabButtons.forEach(function (b) {
          var on = b === tabBtn;
          b.classList.toggle('is-active', on);
          b.setAttribute('aria-selected', on ? 'true' : 'false');
          b.tabIndex = on ? 0 : -1;
          var item = b.closest('.forum-activity__tab-item');
          if (item) item.classList.toggle('is-active', on);
        });
        root.querySelectorAll('[data-activity-panel]').forEach(function (panel) {
          var on = panel.getAttribute('data-activity-panel') === tab;
          panel.classList.toggle('is-active', on);
          if (on) {
            panel.removeAttribute('hidden');
          } else {
            panel.setAttribute('hidden', 'hidden');
          }
        });
      });
    });

    root.querySelectorAll('[data-activity-more]').forEach(function (moreBtn) {
      moreBtn.addEventListener('click', function () {
        if (moreBtn.classList.contains('is-exhausted') || moreBtn.disabled) return;
        var panel = moreBtn.closest('[data-activity-panel]');
        if (!panel) return;
        var tab = panel.getAttribute('data-activity-panel');
        var list = panel.querySelector('[data-activity-list]');
        var offset = parseInt(moreBtn.getAttribute('data-offset') || '0', 10) || 0;
        if (!list || !baseUrl) return;

        moreBtn.disabled = true;
        var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?')
          + 'tab=' + encodeURIComponent(tab)
          + '&offset=' + offset
          + '&limit=' + loadMoreStep
          + '&partial=1';

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (res) {
            if (!res.ok) throw new Error('activity_failed');
            return res.json();
          })
          .then(function (data) {
            if (data.html) {
              var empty = list.querySelector('.forum-activity__empty');
              if (empty) empty.remove();
              list.insertAdjacentHTML('beforeend', data.html);
            }
            moreBtn.setAttribute('data-offset', String(data.nextOffset || (offset + (data.count || 0))));
            if (!data.hasMore) {
              moreBtn.classList.add('is-exhausted');
              moreBtn.setAttribute('tabindex', '-1');
              moreBtn.setAttribute('aria-hidden', 'true');
            } else {
              moreBtn.classList.remove('is-exhausted');
              moreBtn.removeAttribute('tabindex');
              moreBtn.removeAttribute('aria-hidden');
            }
          })
          .catch(function () { /* silent */ })
          .finally(function () { moreBtn.disabled = false; });
      });
    });
  }

  var draftForm = document.querySelector('[data-forum-draft]');
  if (draftForm) {
    var timer = null;
    var status = draftForm.querySelector('[data-draft-status]');
    var saveDraft = function () {
      var bodyEl = draftForm.querySelector('textarea[name="body"]');
      var titleEl = draftForm.querySelector('input[name="title"]');
      var data = new FormData();
      data.append('_token', draftForm.getAttribute('data-draft-token') || '');
      data.append('body', bodyEl ? bodyEl.value : '');
      data.append('title', titleEl ? titleEl.value : '');
      if (draftForm.getAttribute('data-draft-topic')) {
        data.append('topic_id', draftForm.getAttribute('data-draft-topic'));
      }
      if (draftForm.getAttribute('data-draft-section')) {
        data.append('section_id', draftForm.getAttribute('data-draft-section'));
      }
      fetch(draftForm.getAttribute('data-draft-url'), { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (json) {
          if (json && json.ok && status) {
            status.hidden = false;
          }
        })
        .catch(function () {});
    };
    draftForm.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(saveDraft, 4000);
    });
  }

  function initMoveForms() {
    document.querySelectorAll('[data-move-form]').forEach(function (form) {
      var target = form.querySelector('[data-move-target]');
      var submit = form.querySelector('[data-move-submit]');
      if (!target || !submit) return;
      var sync = function () { submit.hidden = !target.value; };
      target.addEventListener('change', sync);
      sync();
    });
  }

  function initImodSelectAll() {
    var bar = document.querySelector('.forum-imod');
    var sync = function () {
      if (!bar) return;
      bar.classList.toggle('is-open', !!document.querySelector('input[form="forum-imod-form"]:checked'));
    };
    document.addEventListener('change', function (e) {
      if (!e.target) return;
      if (e.target.hasAttribute('data-imod-select-all')) {
        var on = e.target.checked;
        document.querySelectorAll('input[form="forum-imod-form"][name="topic_ids[]"]').forEach(function (el) {
          el.checked = on;
        });
      }
      if (e.target.matches('input[form="forum-imod-form"], [data-imod-select-all]')) {
        sync();
      }
    });
    sync();
  }

  function loadExternalScript(src, id) {
    if (document.getElementById(id)) return;
    var s = document.createElement('script');
    s.id = id;
    s.async = true;
    s.src = src;
    document.body.appendChild(s);
  }

  function initMediaEmbeds() {
    if (document.querySelector('blockquote.twitter-tweet')) {
      loadExternalScript('https://platform.twitter.com/widgets.js', 'twitter-wjs');
    }
    if (document.querySelector('blockquote.instagram-media')) {
      loadExternalScript('https://www.instagram.com/embed.js', 'instagram-embed-js');
    }
  }

  function initUnfurl() {
    var page = document.querySelector('.forum-page');
    if (!page) return;
    var endpoint = page.getAttribute('data-unfurl-endpoint');
    var token = page.getAttribute('data-unfurl-token');
    if (!endpoint || !token) return;

    var queue = Array.prototype.slice.call(document.querySelectorAll('[data-unfurl-url]'));
    var run = function () {
      var el = queue.shift();
      if (!el) return;
      var url = el.getAttribute('data-unfurl-url');
      el.removeAttribute('data-unfurl-url');
      if (!url) {
        run();
        return;
      }
      var data = new FormData();
      data.append('_token', token);
      data.append('url', url);
      fetch(endpoint, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.ok && json.html) {
            var wrap = document.createElement('div');
            wrap.innerHTML = json.html;
            var card = wrap.firstElementChild;
            if (card && el.parentNode) {
              el.parentNode.replaceChild(card, el);
            }
          }
        })
        .catch(function () {})
        .finally(run);
    };
    run();
  }

  function initLightbox() {
    var overlay = document.createElement('div');
    overlay.className = 'forum-lightbox-overlay';
    overlay.hidden = true;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML = '<button type="button" class="forum-lightbox-overlay__close" aria-label="' + forumI18n('close', 'Close') + '">&times;</button>'
      + '<button type="button" class="forum-lightbox-overlay__nav forum-lightbox-overlay__prev" aria-label="' + forumI18n('prev', 'Previous') + '">‹</button>'
      + '<img class="forum-lightbox-overlay__img" alt="">'
      + '<button type="button" class="forum-lightbox-overlay__nav forum-lightbox-overlay__next" aria-label="' + forumI18n('next', 'Next') + '">›</button>';
    document.body.appendChild(overlay);
    var imgEl = overlay.querySelector('.forum-lightbox-overlay__img');
    var items = [];
    var index = 0;
    var opener = null;
    var selector = '.forum-post__body img, .forum-attachments img';
    var embedded = '.forum-unfurl-card, .twitter-tweet, .instagram-media';

    // Images open on click, so they must open from the keyboard too.
    document.querySelectorAll(selector).forEach(function (node) {
      if (node.closest(embedded) || node.closest('a')) return;
      node.tabIndex = 0;
      node.setAttribute('role', 'button');
    });

    var galleryFor = function (img) {
      var post = img.closest('.forum-post');
      var scope = post || document;
      return Array.prototype.filter.call(scope.querySelectorAll('.forum-post__body img, .forum-attachments img'), function (node) {
        return !node.closest('.forum-unfurl-card, .twitter-tweet, .instagram-media, iframe');
      });
    };
    var show = function () {
      if (!items[index]) return;
      imgEl.src = items[index].currentSrc || items[index].getAttribute('src') || '';
      imgEl.alt = items[index].getAttribute('alt') || '';
      overlay.querySelectorAll('.forum-lightbox-overlay__nav').forEach(function (nav) {
        nav.hidden = items.length < 2;
      });
      var wasHidden = overlay.hidden;
      overlay.hidden = false;
      document.body.classList.add('forum-lightbox-open');
      if (wasHidden) overlay.querySelector('.forum-lightbox-overlay__close').focus();
    };
    var close = function () {
      overlay.hidden = true;
      imgEl.removeAttribute('src');
      document.body.classList.remove('forum-lightbox-open');
      if (opener) opener.focus();
      opener = null;
    };
    var open = function (img) {
      opener = img;
      items = galleryFor(img);
      index = Math.max(0, items.indexOf(img));
      show();
    };
    var step = function (delta) {
      if (!items.length) return;
      index = (index + delta + items.length) % items.length;
      show();
    };

    document.addEventListener('click', function (e) {
      var img = e.target.closest('.forum-post__body img, .forum-attachments img');
      if (!img || img.closest('.forum-unfurl-card, .twitter-tweet, .instagram-media')) return;
      e.preventDefault();
      e.stopPropagation();
      open(img);
    }, true);

    document.addEventListener('keydown', function (e) {
      if (!overlay.hidden || (e.key !== 'Enter' && e.key !== ' ')) return;
      var img = e.target.closest && e.target.closest(selector);
      if (!img || img.closest(embedded)) return;
      e.preventDefault();
      open(img);
    });

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay || e.target.closest('.forum-lightbox-overlay__close')) close();
      else if (e.target.closest('.forum-lightbox-overlay__prev')) step(-1);
      else if (e.target.closest('.forum-lightbox-overlay__next')) step(1);
    });
    document.addEventListener('keydown', function (e) {
      if (overlay.hidden) return;
      if (e.key === 'Tab') {
        // Keep focus inside the dialog.
        var stops = Array.prototype.filter.call(overlay.querySelectorAll('button'), function (b) { return !b.hidden; });
        var first = stops[0];
        var last = stops[stops.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowLeft') step(-1);
      if (e.key === 'ArrowRight') step(1);
    });
  }

  // ---- Quote just the part you selected ----
  //
  // The per-post Quote button takes the whole message, which in a long post
  // buries the sentence actually being answered. Selecting text and quoting
  // only that is what people already try to do; this makes it work.
  var pendingSnippets = [];

  function initSelectionQuote() {
    var replyBody = document.getElementById('forum-reply-body');
    if (!replyBody) return;

    var bar = document.createElement('div');
    bar.className = 'forum-selection-quote';
    bar.setAttribute('hidden', 'hidden');
    bar.innerHTML =
      '<button type="button" data-sel-quote>' + escapeHtml(forumI18n('sel-quote', 'Alintiyla yanitla')) + '</button>' +
      '<button type="button" data-sel-multi>' + escapeHtml(forumI18n('sel-multi', 'Coklu alintiya ekle')) + '</button>';
    document.body.appendChild(bar);

    var current = null;

    function hide() {
      bar.setAttribute('hidden', 'hidden');
      current = null;
    }

    function closestBody(node) {
      var el = node && node.nodeType === 1 ? node : (node ? node.parentElement : null);
      return el ? el.closest('.forum-post__body') : null;
    }

    function capture() {
      var selection = window.getSelection();
      if (!selection || selection.isCollapsed || selection.rangeCount === 0) return null;

      var text = selection.toString().trim();
      if (text.length < 2) return null;

      var range = selection.getRangeAt(0);
      // Both ends must sit in the same post body, or the "quote" would splice
      // two people's words into one block attributed to one of them.
      var startBody = closestBody(range.startContainer);
      if (!startBody || startBody !== closestBody(range.endContainer)) return null;

      var article = startBody.closest('.forum-post');
      if (!article) return null;

      var nameEl = article.querySelector('.forum-postbit__name');

      return {
        text: text,
        postId: article.getAttribute('data-post-id') || '',
        author: nameEl ? nameEl.textContent.trim() : '',
        rect: range.getBoundingClientRect()
      };
    }

    function reposition(rect) {
      bar.removeAttribute('hidden');
      var top = window.scrollY + rect.top - bar.offsetHeight - 8;
      // Flip below the selection when there is no room above it.
      if (top < window.scrollY + 4) top = window.scrollY + rect.bottom + 8;
      var left = window.scrollX + rect.left + (rect.width / 2) - (bar.offsetWidth / 2);
      var maxLeft = window.scrollX + document.documentElement.clientWidth - bar.offsetWidth - 8;
      bar.style.top = top + 'px';
      bar.style.left = Math.max(window.scrollX + 8, Math.min(left, maxLeft)) + 'px';
    }

    function sync() {
      current = capture();
      if (current) reposition(current.rect); else hide();
    }

    document.addEventListener('mouseup', function (e) {
      if (bar.contains(e.target)) return;
      // Deferred: on mouseup the selection is not settled in every browser.
      window.setTimeout(sync, 0);
    });

    document.addEventListener('keyup', function (e) {
      if (e.key === 'Shift' || e.key.indexOf('Arrow') === 0) sync();
    });

    // Touch selection fires no mouseup; wait for the handles to settle.
    if (window.matchMedia && window.matchMedia('(pointer: coarse)').matches) {
      var touchTimer = null;
      document.addEventListener('selectionchange', function () {
        window.clearTimeout(touchTimer);
        touchTimer = window.setTimeout(sync, 350);
      });
    }

    document.addEventListener('scroll', hide, { passive: true });

    // Keeps the selection alive while the button is pressed.
    bar.addEventListener('mousedown', function (e) { e.preventDefault(); });

    bar.addEventListener('click', function (e) {
      var quote = e.target.closest('[data-sel-quote]');
      var multi = e.target.closest('[data-sel-multi]');
      if (!current || (!quote && !multi)) return;

      var html = buildQuoteHtml(current.author, current.text, current.postId);

      if (quote) {
        appendToEditor(replyBody, html);
        scrollToReply(replyBody);
      } else {
        pendingSnippets.push(html);
        refreshSnippetButton();
      }

      var sel = window.getSelection();
      if (sel) sel.removeAllRanges();
      hide();
    });
  }

  function refreshSnippetButton() {
    var applyBtn = document.querySelector('[data-multi-quote-apply]');
    if (!applyBtn) return;
    if (pendingSnippets.length > 0 || document.querySelector('[data-multi-quote]:checked')) {
      applyBtn.removeAttribute('hidden');
    }
  }

  // ---- Hover cards for @mentions and #N post references ----
  //
  // The point of a "#3" reference is to save the reader a trip to page 1;
  // making them click it would only move the trip, not remove it.
  function initHoverCards() {
    var card = document.createElement('div');
    card.className = 'forum-hovercard';
    card.setAttribute('hidden', 'hidden');
    document.body.appendChild(card);

    var cache = {};
    var pending = {};
    var hideTimer = null;
    var activeKey = null;

    function scheduleHide() {
      window.clearTimeout(hideTimer);
      // Long enough for the pointer to travel from the link onto the card.
      hideTimer = window.setTimeout(function () {
        card.setAttribute('hidden', 'hidden');
        activeKey = null;
      }, 220);
    }

    card.addEventListener('mouseenter', function () { window.clearTimeout(hideTimer); });
    card.addEventListener('mouseleave', scheduleHide);

    function show(anchor, html, key) {
      if (activeKey !== key) return;
      card.innerHTML = html;
      card.removeAttribute('hidden');

      var rect = anchor.getBoundingClientRect();
      var top = window.scrollY + rect.bottom + 8;
      if (top + card.offsetHeight > window.scrollY + document.documentElement.clientHeight) {
        top = window.scrollY + rect.top - card.offsetHeight - 8;
      }
      var left = window.scrollX + rect.left;
      var maxLeft = window.scrollX + document.documentElement.clientWidth - card.offsetWidth - 12;
      card.style.top = Math.max(window.scrollY + 8, top) + 'px';
      card.style.left = Math.max(window.scrollX + 8, Math.min(left, maxLeft)) + 'px';
    }

    function load(anchor, key, url, render) {
      activeKey = key;
      window.clearTimeout(hideTimer);

      if (cache[key]) {
        show(anchor, cache[key], key);
        return;
      }

      if (pending[key]) return;
      pending[key] = true;
      fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (res) {
        if (!res.ok) throw new Error('hovercard');
        return res.json();
      }).then(function (data) {
        if (!data || data.ok === false) return;
        cache[key] = render(data);
        show(anchor, cache[key], key);
      }).catch(function () {
        // A card that cannot load is simply not shown; the link still works.
      }).finally(function () {
        delete pending[key];
      });
    }

    function memberCard(data) {
      var initial = (data.name || '?').charAt(0).toUpperCase();
      var avatar = data.avatar
        ? '<img class="forum-hovercard__avatar" src="' + escapeAttr(data.avatar) + '" alt="">'
        : '<span class="forum-hovercard__avatar forum-hovercard__avatar--empty">' + escapeHtml(initial) + '</span>';

      return '<div class="forum-hovercard__head">' + avatar +
        '<div><strong>' + escapeHtml(data.name || '') + '</strong>' +
        (data.title ? '<span class="forum-hovercard__title">' + escapeHtml(data.title) + '</span>' : '') +
        '</div></div>' +
        '<div class="forum-hovercard__meta">' +
        '<span>' + escapeHtml(String(data.postCount || 0)) + ' ' + escapeHtml(forumI18n('stat-posts', 'mesaj')) + '</span>' +
        '<span>' + escapeHtml(forumI18n('stat-joined', 'Katilim')) + ': ' + escapeHtml(data.joined || '') + '</span>' +
        '</div>' +
        '<a class="forum-hovercard__cta" href="' + escapeAttr(data.url || '#') + '">' +
        escapeHtml(forumI18n('view-profile', 'Profili gor')) + '</a>';
    }

    function postCard(data) {
      return '<div class="forum-hovercard__head">' +
        '<strong>#' + escapeHtml(String(data.number)) + ' — ' + escapeHtml(data.author || '') + '</strong>' +
        '</div>' +
        '<p class="forum-hovercard__excerpt">' + escapeHtml(data.excerpt || '') + '</p>' +
        '<div class="forum-hovercard__meta"><span>' + escapeHtml(data.date || '') + '</span></div>';
    }

    // Routes carry a locale prefix, so the URLs are generated by the router
    // into data attributes and only filled in here.
    var forumRoot = document.getElementById('forum') || document.querySelector('.forum-page');
    var memberCardUrl = forumRoot ? forumRoot.getAttribute('data-member-card-url') || '' : '';
    var postPreviewUrl = forumRoot ? forumRoot.getAttribute('data-post-preview-url') || '' : '';

    function reveal(e) {
      if (!e.target.closest) return;
      var mention = e.target.closest('a.forum-mention[data-mention]');
      if (mention && memberCardUrl) {
        var slug = mention.getAttribute('data-mention');
        load(mention, 'u:' + slug, memberCardUrl.replace('__SLUG__', encodeURIComponent(slug)), memberCard);
        return;
      }

      var ref = e.target.closest('a.forum-postref[data-post-ref]');
      if (ref && postPreviewUrl) {
        var num = ref.getAttribute('data-post-ref');
        var topicId = ref.getAttribute('data-topic-ref');
        // The template was generated with topicId=0 and number=0; swapping the
        // last two path segments keeps the locale prefix intact.
        var url = postPreviewUrl
          .replace('/topic/0/', '/topic/' + encodeURIComponent(topicId) + '/')
          .replace('/post/0/', '/post/' + encodeURIComponent(num) + '/');
        load(ref, 'p:' + topicId + ':' + num, url, postCard);
      }
    }

    function conceal(e) {
      if (e.target.closest && e.target.closest('a.forum-mention[data-mention], a.forum-postref[data-post-ref]')) {
        scheduleHide();
      }
    }

    document.addEventListener('mouseover', reveal);
    document.addEventListener('focusin', reveal);
    document.addEventListener('mouseout', conceal);
    document.addEventListener('focusout', conceal);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !card.hasAttribute('hidden')) {
        card.setAttribute('hidden', 'hidden');
        activeKey = null;
      }
    });
  }

})();

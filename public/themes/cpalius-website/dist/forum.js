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
      var any = document.querySelector('[data-multi-quote]:checked');
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
      var blocks = [];
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
        if (navigator.clipboard) {
          navigator.clipboard.writeText(url);
        }
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

    root.querySelectorAll('[data-activity-tab]').forEach(function (tabBtn) {
      tabBtn.addEventListener('click', function () {
        var tab = tabBtn.getAttribute('data-activity-tab');
        root.querySelectorAll('[data-activity-tab]').forEach(function (b) {
          var on = b === tabBtn;
          b.classList.toggle('is-active', on);
          b.setAttribute('aria-selected', on ? 'true' : 'false');
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
    overlay.innerHTML = '<button type="button" class="forum-lightbox-overlay__close" aria-label="' + forumI18n('close', 'Close') + '">&times;</button>'
      + '<button type="button" class="forum-lightbox-overlay__nav forum-lightbox-overlay__prev" aria-label="' + forumI18n('prev', 'Previous') + '">‹</button>'
      + '<img class="forum-lightbox-overlay__img" alt="">'
      + '<button type="button" class="forum-lightbox-overlay__nav forum-lightbox-overlay__next" aria-label="' + forumI18n('next', 'Next') + '">›</button>';
    document.body.appendChild(overlay);
    var imgEl = overlay.querySelector('.forum-lightbox-overlay__img');
    var items = [];
    var index = 0;

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
      overlay.hidden = false;
      document.body.classList.add('forum-lightbox-open');
    };
    var close = function () {
      overlay.hidden = true;
      imgEl.removeAttribute('src');
      document.body.classList.remove('forum-lightbox-open');
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
      items = galleryFor(img);
      index = Math.max(0, items.indexOf(img));
      show();
    }, true);

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay || e.target.closest('.forum-lightbox-overlay__close')) close();
      else if (e.target.closest('.forum-lightbox-overlay__prev')) step(-1);
      else if (e.target.closest('.forum-lightbox-overlay__next')) step(1);
    });
    document.addEventListener('keydown', function (e) {
      if (overlay.hidden) return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowLeft') step(-1);
      if (e.key === 'ArrowRight') step(1);
    });
  }
})();

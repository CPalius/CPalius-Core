/* ================================================
   CPalius Forum — front-end davranışları
   Onay istemi, alıntılama ve mesaj metni için hafif biçimlendirme
   araç çubuğu. Bağımlılıksız (main.js'in fade-in/navbar mantığına dokunmaz).
   ================================================ */
(function () {
  'use strict';

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
  });

  // ---- Silme/tehlikeli eylemler için onay ----
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

  // ---- "Alıntıla" butonları ----
  // Yanıt kutusu CKEditor 5 ile zenginleştirilmiş olabilir (forum-editor-init.js,
  // textarea.__cpForumEditor). Editör henüz hazır değilse ham textarea.value'ya
  // yazılır — CKEditor init sırasında textarea'nın o anki değerini okuyacağı için
  // bu durumda da veri kaybolmaz.
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

  // ---- Reputation modal (profil + postbit) ----
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

  // ---- "Raporla" butonları — sebep sorup gizli forma yazıp gönderir ----
  function initReportButtons() {
    document.querySelectorAll('[data-report-btn]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = btn.closest('[data-report-form]');
        if (!form) return;

        var reason = window.prompt('Bu mesajı neden bildiriyorsunuz? (kısaca açıklayın)');
        if (!reason || !reason.trim()) return;

        form.querySelector('input[name="reason"]').value = reason.trim();
        form.submit();
      });
    });
  }

  // ---- Beğeni / beğenmeme — AJAX toggle ----
  // Native form.submit() catch'te YAPILMAZ: fetch sunucuya ulaştıysa
  // ikinci POST beğeniyi geri alır (sayfa yenilenir, kalp boş kalır).
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

        fetch(form.action, {
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
          .catch(function () { /* native yeniden gönderilmez */ })
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

  // ---- Paylaş — Web Share API varsa native paylaşım, yoksa panoya kopyala ----
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
            btn.innerHTML = '<i class="bi bi-check-lg"></i> Bağlantı kopyalandı';
            setTimeout(function () { btn.innerHTML = original; }, 2000);
          });
        }
      });
    });
  }

  // ---- Son olaylar panosu (sekme + AJAX load more) ----
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
          .catch(function () { /* sessiz */ })
          .finally(function () { moreBtn.disabled = false; });
      });
    });
  }
})();

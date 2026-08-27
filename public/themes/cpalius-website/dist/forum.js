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
        var quotedHtml = '<blockquote><strong>' + escapeHtml(author) + ' yazdı:</strong><p>' + escapeHtml(text).replace(/\n/g, '<br>') + '</p></blockquote><p></p>';

        var editor = replyBody.__cpForumEditor;
        if (editor) {
          editor.setData(editor.getData() + quotedHtml);
        } else {
          var quotedPlain = author + ' yazdı:\n' + text + '\n\n';
          replyBody.value = replyBody.value ? replyBody.value + '\n' + quotedPlain : quotedPlain;
        }

        var anchor = document.getElementById('reply');
        if (anchor) {
          anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        replyBody.focus();
      });
    });
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
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
  function initReactionForms(selector, options) {
    document.querySelectorAll(selector).forEach(function (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();

        var btn = form.querySelector(options.buttonSelector) || form.querySelector('button[type="submit"]');
        if (!btn) {
          return;
        }

        var icon = btn.querySelector('i');
        var countEl = btn.querySelector(options.countSelector);
        var siblingForm = null;
        if (options.siblingSelector) {
          var toolbar = form.closest('.forum-post__toolbar');
          siblingForm = toolbar ? toolbar.querySelector(options.siblingSelector) : null;
        }

        fetch(form.action, {
          method: 'POST',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          body: new FormData(form),
        })
          .then(function (res) {
            if (!res.ok) {
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

            // Karşılıklı dışlama: karşı reaksiyon UI'sını sıfırla (sunucu da temizler).
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
          .catch(function () {
            // Yalnızca ağ/parse hatasında native submit (çift toggle riskini azaltır).
            form.submit();
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

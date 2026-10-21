/**
 * TBT Flashcards 2.0 — teacher tools (library + workspace).
 *
 * Talks to the tbt-flashcards/v1 REST routes with the wp_rest nonce. All card
 * text is inserted with textContent / value, never as HTML.
 */
(function () {
  'use strict';

  var config = window.tbtFcTools || {};
  var SESSION_EXPIRED = 'Your session has expired. Refresh the page.';

  /* ---------- Shared helpers ---------- */

  function api(method, path, body) {
    var opts = {
      method: method,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': config.nonce || '' }
    };
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(String(config.restBase || '').replace(/\/$/, '') + path, opts)
      .then(function (response) {
        return response.text().then(function (text) {
          var data = null;
          try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
          if (!response.ok) {
            var err = new Error(errorMessage(response.status, data));
            err.status = response.status;
            err.code = data && data.code;
            throw err;
          }
          return data;
        });
      }, function () {
        throw new Error("Couldn't reach the server. Check your connection and try again.");
      });
  }

  function errorMessage(status, data) {
    var code = data && data.code;
    if (code === 'rest_cookie_invalid_nonce' || code === 'tbt_fc_not_logged_in' || status === 401) {
      return SESSION_EXPIRED;
    }
    if (data && data.message) return String(data.message);
    if (status === 403) return SESSION_EXPIRED;
    return 'Something went wrong. Try again.';
  }

  function copyText(text, btn) {
    function done() {
      if (!btn) return;
      var original = btn.getAttribute('data-label') || btn.textContent;
      btn.setAttribute('data-label', original);
      btn.textContent = 'Copied';
      btn.classList.add('is-done');
      setTimeout(function () {
        btn.textContent = original;
        btn.classList.remove('is-done');
      }, 1400);
    }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      if (ok) done(); else window.prompt('Copy this text:', text);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
  }

  function show(el, visible) {
    if (el) el.hidden = !visible;
  }

  function setError(el, message) {
    if (!el) return;
    el.textContent = message || '';
    el.hidden = !message;
  }

  /* CSV serializer — same rules as tbt_fc_to_csv() in includes/cards.php:
     header word,phonetic,translation,example · comma · quote a field only when
     it holds a comma, quote or line break (inner quotes doubled) · LF · final
     newline · no BOM. */
  function csvField(v) {
    v = String(v == null ? '' : v);
    return /[",\r\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }

  function toCSV(cards) {
    var out = 'word,phonetic,translation,example\n';
    cards.forEach(function (c) {
      out += [c.word, c.phonetic, c.translation, c.example].map(csvField).join(',') + '\n';
    });
    return out;
  }

  /* ---------- Library ---------- */

  function initLibrary(root) {
    var search = root.querySelector('[data-tbtfc-search]');
    var clear = root.querySelector('[data-tbtfc-search-clear]');
    var summary = root.querySelector('[data-tbtfc-summary]');
    var summaryText = root.querySelector('[data-tbtfc-summary-text]');
    var reset = root.querySelector('[data-tbtfc-reset]');
    var noMatch = root.querySelector('[data-tbtfc-nomatch]');
    var libError = root.querySelector('[data-tbtfc-lib-error]');

    function rows() {
      return Array.prototype.slice.call(root.querySelectorAll('[data-tbtfc-set]'));
    }

    function filter() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var all = rows();
      var shown = 0;
      all.forEach(function (row) {
        var match = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
        row.hidden = !match;
        if (match) shown++;
      });
      if (clear) clear.hidden = !q;
      if (summary) {
        summary.hidden = !q;
        if (q) summaryText.textContent = shown + ' of ' + all.length + ' sets ·';
      }
      show(noMatch, !!q && shown === 0 && all.length > 0);
    }

    function clearSearch() {
      if (!search) return;
      search.value = '';
      filter();
      search.focus();
    }

    if (search) {
      search.addEventListener('input', filter);
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); clearSearch(); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        var t = e.target;
        var tag = (t && t.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
        e.preventDefault();
        search.focus();
      });
    }
    if (clear) clear.addEventListener('click', clearSearch);
    if (reset) reset.addEventListener('click', clearSearch);

    root.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('button') : null;
      if (!btn || !root.contains(btn)) return;

      if (btn.hasAttribute('data-tbtfc-copy')) {
        copyText(btn.getAttribute('data-tbtfc-copy'), btn);
        return;
      }

      if (btn.hasAttribute('data-tbtfc-delete')) {
        var row = btn.closest('[data-tbtfc-set]');
        if (!row) return;
        if (!btn.getAttribute('data-armed')) {
          btn.setAttribute('data-armed', '1');
          btn.textContent = 'Delete — sure?';
          btn._tbtfcTimer = setTimeout(function () {
            btn.removeAttribute('data-armed');
            btn.textContent = 'Delete';
          }, 3000);
          return;
        }
        clearTimeout(btn._tbtfcTimer);
        btn.disabled = true;
        setError(libError, '');
        api('DELETE', '/sets/' + row.getAttribute('data-id'))
          .then(function () {
            row.parentNode.removeChild(row);
            if (rows().length === 0) {
              window.location.reload();
              return;
            }
            filter();
          })
          .catch(function (err) {
            btn.disabled = false;
            btn.removeAttribute('data-armed');
            btn.textContent = 'Delete';
            setError(libError, err.message);
          });
      }
    });
  }

  /* ---------- Workspace ---------- */

  function initWorkspace(root) {
    var $ = function (sel) { return root.querySelector(sel); };
    var setId = parseInt(root.getAttribute('data-set-id'), 10) || 0;

    var titleEl = $('[data-tbtfc-title]');
    var contextEl = $('[data-tbtfc-context]');
    var itemsEl = $('[data-tbtfc-items]');
    var genBtn = $('[data-tbtfc-generate]');
    var countEl = $('[data-tbtfc-count]');
    var genError = $('[data-tbtfc-gen-error]');
    var review = $('[data-tbtfc-review]');
    var reviewCount = $('[data-tbtfc-review-count]');
    var reviewNote = $('[data-tbtfc-review-note]');
    var rowsEl = $('[data-tbtfc-rows]');
    var addBtn = $('[data-tbtfc-add]');
    var saveBtn = $('[data-tbtfc-save]');
    var savedAt = $('[data-tbtfc-saved-at]');
    var saveError = $('[data-tbtfc-save-error]');
    var savedBox = $('[data-tbtfc-saved]');
    var shortcodeEl = $('[data-tbtfc-shortcode]');
    var linkEl = $('[data-tbtfc-link]');
    var openEl = $('[data-tbtfc-open]');
    var exportEl = $('[data-tbtfc-export]');
    var copyCsvBtn = $('[data-tbtfc-copy-csv]');
    var csvEl = $('[data-tbtfc-csv]');
    var stagesEl = $('[data-tbtfc-stages]');
    var loadingEl = $('[data-tbtfc-loading]');
    var loadError = $('[data-tbtfc-load-error]');
    var toastEl = $('[data-tbtfc-toast]');
    var stages = {
      1: $('[data-tbtfc-stage="1"]'),
      2: $('[data-tbtfc-stage="2"]'),
      3: $('[data-tbtfc-stage="3"]')
    };

    var cards = [];
    var dirty = false;
    var saving = false;
    var generating = false;
    var toastTimer = null;
    var maxItems = parseInt(config.maxItems, 10) || 40;

    function toast(msg) {
      if (!toastEl) return;
      toastEl.textContent = msg;
      toastEl.classList.add('is-shown');
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () { toastEl.classList.remove('is-shown'); }, 1800);
    }

    function markDirty() {
      dirty = true;
    }

    function pendingItems() {
      return itemsEl.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function autosize(t) {
      t.style.height = 'auto';
      t.style.height = t.scrollHeight + 'px';
    }

    function renderRows(newFrom) {
      rowsEl.textContent = '';
      cards.forEach(function (card, i) {
        var row = document.createElement('div');
        row.className = 'tbtfc-crow' + (newFrom != null && i >= newFrom ? ' is-new' : '');

        var n = document.createElement('span');
        n.className = 'tbtfc-crow__n';
        n.textContent = String(i + 1);
        row.appendChild(n);

        [
          ['word', 'tbtfc-cell--word', 'Word'],
          ['phonetic', 'tbtfc-cell--ipa', 'Phonetic'],
          ['translation', 'tbtfc-cell--tr', 'Translation'],
          ['example', 'tbtfc-cell--ex', 'Example']
        ].forEach(function (spec) {
          var t = document.createElement('textarea');
          t.rows = 1;
          t.className = 'tbtfc-cell ' + spec[1];
          t.setAttribute('aria-label', spec[2] + ' ' + (i + 1));
          t.value = card[spec[0]] || '';
          t.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') e.preventDefault();
          });
          t.addEventListener('input', function () {
            // Pasted line breaks become spaces: a card field is one line.
            if (/[\r\n]/.test(t.value)) t.value = t.value.replace(/[\r\n]+/g, ' ');
            card[spec[0]] = t.value;
            autosize(t);
            markDirty();
            refresh();
          });
          row.appendChild(t);
        });

        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'tbtfc-crow__x';
        x.setAttribute('aria-label', 'Remove card ' + (i + 1));
        x.textContent = '×';
        x.addEventListener('click', function () {
          cards.splice(i, 1);
          markDirty();
          renderRows();
          refresh();
        });
        row.appendChild(x);

        rowsEl.appendChild(row);
      });

      var areas = rowsEl.querySelectorAll('textarea');
      var raf = window.requestAnimationFrame || function (fn) { setTimeout(fn, 0); };
      raf(function () {
        for (var k = 0; k < areas.length; k++) autosize(areas[k]);
      });
    }

    function wordCards() {
      return cards.filter(function (c) { return (c.word || '').trim() !== ''; });
    }

    function refresh() {
      var n = pendingItems().length;
      countEl.textContent = '';
      var strong = document.createElement('strong');
      strong.textContent = String(n);
      countEl.appendChild(strong);
      countEl.appendChild(document.createTextNode(n === 1 ? ' new item' : ' new items'));
      if (!generating) genBtn.disabled = n === 0;

      show(review, cards.length > 0);
      show(reviewNote, cards.length > 0);
      reviewCount.textContent = cards.length + (cards.length === 1 ? ' card' : ' cards') + ' · click any cell to edit it';

      var hasTitle = titleEl.value.trim() !== '';
      var hasCards = wordCards().length > 0;
      stages[1].setAttribute('data-state', hasTitle ? 'done' : 'active');
      stages[2].setAttribute('data-state', hasCards ? 'done' : 'active');
      stages[3].setAttribute('data-state', hasTitle && hasCards ? 'active' : 'waiting');
      saveBtn.disabled = saving || !(hasTitle && hasCards);

      // Live CSV preview: header highlighted, then the rows.
      var csv = toCSV(wordCards());
      var nl = csv.indexOf('\n');
      csvEl.textContent = '';
      var head = document.createElement('span');
      head.className = 'tbtfc-csv__head';
      head.textContent = csv.slice(0, nl + 1);
      csvEl.appendChild(head);
      csvEl.appendChild(document.createTextNode(csv.slice(nl + 1)));
    }

    function applySaved(data) {
      setId = data.id;
      shortcodeEl.value = data.shortcode;
      linkEl.value = data.link;
      openEl.href = data.link;
      exportEl.href = data.csv_url;
      show(savedBox, true);
    }

    function setUrlParam(id) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('set', String(id));
        window.history.replaceState(window.history.state, '', url.toString());
      } catch (e) { /* old browser: the URL simply stays as it was */ }
    }

    titleEl.addEventListener('input', function () { markDirty(); refresh(); });
    contextEl.addEventListener('input', function () { markDirty(); refresh(); });
    itemsEl.addEventListener('input', refresh);

    genBtn.addEventListener('click', function () {
      var items = pendingItems();
      if (!items.length || generating) return;
      if (items.length > maxItems) {
        setError(genError, 'You can generate up to ' + maxItems + ' items at a time. Split the list and generate it in parts.');
        return;
      }
      setError(genError, '');
      generating = true;
      genBtn.disabled = true;
      genBtn.textContent = '';
      var spin = document.createElement('span');
      spin.className = 'tbtfc-spin';
      spin.setAttribute('aria-hidden', 'true');
      genBtn.appendChild(spin);
      genBtn.appendChild(document.createTextNode(' Generating…'));

      api('POST', '/generate', { items: items, context: contextEl.value.trim() })
        .then(function (data) {
          var from = cards.length;
          (data && data.cards || []).forEach(function (c) {
            cards.push({
              word: c.word || '',
              phonetic: c.phonetic || '',
              translation: c.translation || '',
              example: c.example || ''
            });
          });
          itemsEl.value = '';
          markDirty();
          renderRows(from);
          var added = cards.length - from;
          toast(added + (added === 1 ? ' card added' : ' cards added'));
        })
        .catch(function (err) {
          setError(genError, err.message);
        })
        .then(function () {
          generating = false;
          genBtn.textContent = 'Generate cards';
          refresh();
        });
    });

    addBtn.addEventListener('click', function () {
      cards.push({ word: '', phonetic: '', translation: '', example: '' });
      markDirty();
      renderRows(cards.length - 1);
      refresh();
      var last = rowsEl.lastElementChild;
      if (last) last.querySelector('textarea').focus();
    });

    saveBtn.addEventListener('click', function () {
      if (saving) return;
      var payload = {
        title: titleEl.value.trim(),
        context: contextEl.value.trim(),
        cards: wordCards()
      };
      if (!payload.title || !payload.cards.length) return;

      saving = true;
      saveBtn.disabled = true;
      setError(saveError, '');
      var request = setId ? api('PUT', '/sets/' + setId, payload) : api('POST', '/sets', payload);

      request
        .then(function (data) {
          // After the first save the URL carries ?set={id}, so a reload
          // reopens this set and later saves go to PUT.
          setUrlParam(data.id);
          cards = (data.cards || []).map(function (c) {
            return { word: c.word, phonetic: c.phonetic, translation: c.translation, example: c.example };
          });
          titleEl.value = data.title || payload.title;
          renderRows();
          applySaved(data);
          dirty = false;
          var d = new Date();
          savedAt.textContent = 'Saved at ' + String(d.getHours()).padStart(2, '0') + ':' +
            String(d.getMinutes()).padStart(2, '0') + ' · ' + data.cards_count +
            (data.cards_count === 1 ? ' card' : ' cards');
          toast('Saved');
        })
        .catch(function (err) {
          setError(saveError, err.message);
        })
        .then(function () {
          saving = false;
          refresh();
        });
    });

    root.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('[data-tbtfc-copy-from]') : null;
      if (!btn) return;
      var source = btn.getAttribute('data-tbtfc-copy-from') === 'shortcode' ? shortcodeEl : linkEl;
      copyText(source.value, btn);
    });

    copyCsvBtn.addEventListener('click', function () {
      copyText(toCSV(wordCards()), copyCsvBtn);
    });

    window.addEventListener('beforeunload', function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = '';
      return '';
    });

    function start() {
      show(loadingEl, false);
      show(stagesEl, true);
      renderRows();
      refresh();
    }

    if (setId) {
      api('GET', '/sets/' + setId)
        .then(function (data) {
          titleEl.value = data.title || '';
          contextEl.value = data.context || '';
          cards = (data.cards || []).map(function (c) {
            return { word: c.word || '', phonetic: c.phonetic || '', translation: c.translation || '', example: c.example || '' };
          });
          applySaved(data);
          start();
          if (data.warning) setError(saveError, data.warning);
        })
        .catch(function (err) {
          show(loadingEl, false);
          var notYours = err.status === 403 && err.code === 'tbt_fc_not_owner' || err.status === 404;
          setError(loadError, notYours ? "This set isn't yours or no longer exists." : err.message);
          setId = 0;
        });
    } else {
      start();
    }
  }

  function init() {
    var libs = document.querySelectorAll('[data-tbtfc-library]');
    for (var i = 0; i < libs.length; i++) initLibrary(libs[i]);
    var spaces = document.querySelectorAll('[data-tbtfc-workspace]');
    for (var j = 0; j < spaces.length; j++) initWorkspace(spaces[j]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

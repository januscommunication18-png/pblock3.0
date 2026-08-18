/**
 * Global search command palette — docs/features/global-search.md (slice 1).
 *
 * Deliberately dependency-free: no Vue, no $pb. The topbar it hangs off is included by every
 * app shell, but `settings/app.js` is not loaded on all of them, so a palette built on Vue
 * would be present on some pages and quietly missing on others. Plain DOM works everywhere the
 * topbar does, which is the whole point of a global shortcut.
 */
(function () {
  'use strict';

  var MIN_CHARS = 2;      // §6.1 — below this there is nothing worth asking the server
  var DEBOUNCE_MS = 200;  // §6.1 — inside the recommended 150–250ms band

  var el = {};            // built once, on first open
  var state = {
    open: false,
    seq: 0,               // request counter; see `render` — guards against stale responses
    results: [],          // flattened, in render order, for arrow-key movement
    active: -1,
    lastFocus: null,
    timer: null
  };

  // ---------- helpers ----------

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /**
   * Escape FIRST, then wrap the match (§6.3).
   *
   * The needle is user input echoed back into the page. Highlighting before escaping would
   * mean injecting their string as markup — a search box that renders whatever is typed into
   * it is a stored-XSS vector aimed at whoever is shown the results.
   */
  function highlight(text, query) {
    var safe = esc(text);
    var needle = String(query || '').trim();
    if (!needle) return safe;

    var at = safe.toLowerCase().indexOf(esc(needle).toLowerCase());
    if (at < 0) return safe;

    var len = esc(needle).length;

    return safe.slice(0, at) +
      '<mark class="bg-brand/15 text-inherit rounded-[2px] px-0.5">' + safe.slice(at, at + len) + '</mark>' +
      safe.slice(at + len);
  }

  function relative(iso) {
    if (!iso) return '';
    var then = new Date(iso);
    if (isNaN(then)) return '';
    var secs = Math.max(1, Math.round((Date.now() - then.getTime()) / 1000));
    if (secs < 60) return 'just now';
    var mins = Math.round(secs / 60);
    if (mins < 60) return mins + 'm ago';
    var hrs = Math.round(mins / 60);
    if (hrs < 24) return hrs + 'h ago';
    return Math.round(hrs / 24) + 'd ago';
  }

  // ---------- construction ----------

  /**
   * The panel's own geometry, as real CSS rather than utility classes.
   *
   * Tailwind only emits a class it has SEEN, so an arbitrary value like `sm:max-w-[700px]`
   * written here exists only after `npm run build:css` has been run again. Until then the
   * class resolves to nothing and the panel silently stretches to the full width of the page —
   * which is exactly what happened. Geometry this load-bearing should not depend on a build
   * step having been remembered, so it ships with the behaviour that needs it.
   *
   * Namespaced `_moretogether` per CLAUDE.md §14.
   */
  function styles() {
    if (document.getElementById('pb-search-styles')) return;

    var css =
      // The overlay's stacking and dim. `z-[200]` and `bg-black/40` are arbitrary values too,
      // and were likewise absent from the built CSS — so the palette had no z-index at all and
      // could render behind page chrome.
      '#pb-search-overlay{z-index:200;}' +
      '#pb-search-overlay [data-backdrop]{background:rgba(0,0,0,0.4);}' +
      '._moretogether-search-panel{' +
        'width:50%;min-width:420px;max-width:900px;' +   // half the viewport, bounded either side
        'margin-top:6vh;' +                              // near the top, not vertically centred
        'max-height:72vh;' +
        'border-radius:0.75rem;' +
      '}' +
      // Below the small breakpoint half a viewport is unusable, so it becomes the full-screen
      // overlay §22 asks for.
      '@media (max-width:640px){' +
        '._moretogether-search-panel{' +
          'width:100%;min-width:0;margin-top:0;height:100%;max-height:100%;border-radius:0;' +
        '}' +
      '}';

    var tag = document.createElement('style');
    tag.id = 'pb-search-styles';
    tag.textContent = css;
    document.head.appendChild(tag);
  }

  function build() {
    if (el.root) return;

    styles();

    var root = document.createElement('div');
    root.id = 'pb-search-overlay';
    root.className = 'fixed inset-0 hidden';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', 'Search your workspace');

    root.innerHTML =
      '<div data-backdrop class="absolute inset-0 bg-black/40"></div>' +
      '<div data-panel class="_moretogether-search-panel relative mx-auto bg-white shadow-2xl flex flex-col overflow-hidden">' +

        '<div class="flex items-center gap-3 px-4 h-14 border-b border-line shrink-0">' +
          '<svg class="h-4 w-4 text-faint shrink-0" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
            '<circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>' +
            '<path d="M20 20l-3.5-3.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
          '<input data-input type="text" autocomplete="off" spellcheck="false" ' +
            'placeholder="Search your workspace" aria-label="Search your workspace" ' +
            'class="flex-1 bg-transparent text-[14px] text-ink placeholder:text-faint outline-none" />' +
          '<button type="button" data-close aria-label="Close search" ' +
            'class="h-7 px-2 rounded text-[11px] font-medium text-faint hover:bg-hover">Esc</button>' +
        '</div>' +

        '<div data-body class="flex-1 overflow-y-auto p-2"></div>' +

        '<div class="hidden sm:flex items-center gap-4 px-4 h-10 border-t border-line shrink-0 text-[11px] text-faint">' +
          '<span><kbd class="font-sans">↑</kbd> <kbd class="font-sans">↓</kbd> to navigate</span>' +
          '<span><kbd class="font-sans">Enter</kbd> to open</span>' +
          '<span><kbd class="font-sans">Esc</kbd> to close</span>' +
        '</div>' +
      '</div>';

    document.body.appendChild(root);

    el.root = root;
    el.input = root.querySelector('[data-input]');
    el.body = root.querySelector('[data-body]');

    root.querySelector('[data-backdrop]').addEventListener('click', close);
    root.querySelector('[data-close]').addEventListener('click', close);
    el.input.addEventListener('input', onType);
    root.addEventListener('keydown', onKeydown);
  }

  // ---------- states (§13) ----------

  function message(title, detail) {
    el.body.innerHTML =
      '<div class="py-12 text-center">' +
        '<div class="text-[13px] font-medium text-ink">' + esc(title) + '</div>' +
        (detail ? '<div class="mt-1 text-[12px] text-sub">' + esc(detail) + '</div>' : '') +
      '</div>';
  }

  function idle() {
    state.results = [];
    state.active = -1;
    message('Search your workspace', 'Start typing to search across work items, projects, cycles, modules and more.');
  }

  function loading() {
    // Rows rather than a spinner: the list keeps its shape, so results appearing does not
    // shove the whole panel around (§13.2).
    var row = '<div class="h-14 rounded-md bg-hover/60 animate-pulse mb-1"></div>';
    el.body.innerHTML = '<div class="p-1">' + row + row + row + '</div>';
  }

  // ---------- rendering ----------

  function card(r, query, index) {
    // `project` for a work item, `context` for a wiki page (its collection) — one line that
    // says WHERE the thing lives, whatever kind of thing it is (§8).
    var meta = [r.project || r.context, r.state, r.priority, r.assignee]
      .filter(Boolean).map(esc).join(' · ');
    var when = relative(r.updated_at);

    return '' +
      '<button type="button" data-i="' + index + '" ' +
        'class="w-full text-left px-3 py-2.5 rounded-md hover:bg-hover flex flex-col gap-0.5 ' +
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">' +
        '<div class="flex items-center gap-2 min-w-0">' +
          // Only work items have one. Rendering an empty span for a wiki page would leave a
          // gap the eye reads as a missing value.
          (r.identifier
            ? '<span class="text-[12px] text-sub shrink-0">' + highlight(r.identifier, query) + '</span>'
            : '') +
          '<span class="text-[13px] text-ink truncate">' + highlight(r.title, query) + '</span>' +
        '</div>' +
        (meta || when
          ? '<div class="text-[11px] text-faint truncate">' + meta + (meta && when ? ' · ' : '') + esc(when) + '</div>'
          : '') +
        (r.snippet ? '<div class="text-[11px] text-sub truncate">' + highlight(r.snippet, query) + '</div>' : '') +
      '</button>';
  }

  function render(payload, query) {
    var groups = (payload && payload.groups) || [];

    state.results = [];
    groups.forEach(function (g) {
      (g.results || []).forEach(function (r) { state.results.push(r); });
    });

    if (!state.results.length) {
      message('No results for “' + query + '”',
        'Try removing filters, checking the spelling, or searching with a different term.');

      return;
    }

    var i = 0;
    var html = groups.map(function (g) {
      var rows = (g.results || []).map(function (r) { return card(r, query, i++); }).join('');

      return '<div class="mb-2">' +
        '<div class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-faint">' + esc(g.label) + '</div>' +
        rows + '</div>';
    }).join('');

    el.body.innerHTML = html;

    Array.prototype.forEach.call(el.body.querySelectorAll('[data-i]'), function (btn) {
      btn.addEventListener('click', function () { openResult(Number(btn.getAttribute('data-i'))); });
      btn.addEventListener('mousemove', function () { setActive(Number(btn.getAttribute('data-i'))); });
    });

    setActive(0);
  }

  function setActive(i) {
    if (!state.results.length) return;
    state.active = Math.max(0, Math.min(i, state.results.length - 1));

    Array.prototype.forEach.call(el.body.querySelectorAll('[data-i]'), function (btn) {
      var on = Number(btn.getAttribute('data-i')) === state.active;
      btn.classList.toggle('bg-hover', on);
      if (on) btn.scrollIntoView({ block: 'nearest' });
    });
  }

  // ---------- querying ----------

  function onType() {
    clearTimeout(state.timer);
    var query = el.input.value.trim();

    if (query.length < MIN_CHARS) { idle(); return; }

    state.timer = setTimeout(function () { search(query); }, DEBOUNCE_MS);
  }

  function search(query) {
    // Every request carries a sequence number and only the newest one is allowed to paint.
    // Without this a slow response for "log" lands after "login" and silently replaces the
    // newer results with older ones (§18).
    var mine = ++state.seq;

    loading();

    fetch('/search?q=' + encodeURIComponent(query), {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().then(function (body) { return { ok: res.ok, body: body }; });
      })
      .then(function (out) {
        if (mine !== state.seq) return;

        // A failed search and an empty one are different things and must not look alike
        // (§13.4 vs §13.5).
        if (!out.ok || (out.body && out.body.error)) {
          state.results = [];
          message('We couldn’t complete the search. Try again.', 'The search service did not respond.');

          return;
        }

        render(out.body, query);
      })
      .catch(function () {
        if (mine !== state.seq) return;
        state.results = [];
        message('We couldn’t complete the search. Try again.', 'Check your connection and try once more.');
      });
  }

  function openResult(i) {
    var r = state.results[i];
    if (!r || !r.url) return;
    window.location.href = r.url;
  }

  // ---------- open / close ----------

  function onKeydown(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }

    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(state.active + 1); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); setActive(state.active - 1); return; }
    if (e.key === 'Enter') { e.preventDefault(); openResult(state.active); return; }

    // Focus trap (§21): the palette is modal, so Tab must not walk out into the page behind it.
    if (e.key === 'Tab') {
      var focusable = el.root.querySelectorAll('input, button:not([disabled])');
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];

      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function open() {
    build();
    if (state.open) return;

    state.lastFocus = document.activeElement;
    state.open = true;
    el.root.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
    el.input.value = '';
    idle();
    el.input.focus();
  }

  function close() {
    if (!state.open) return;

    state.open = false;
    state.seq++;               // orphan any in-flight response so it cannot paint after closing
    clearTimeout(state.timer);
    el.root.classList.add('hidden');
    document.body.classList.remove('overflow-hidden');

    // Focus returns to whatever opened it (§21) — otherwise it falls to <body> and a
    // keyboard user loses their place on the page.
    if (state.lastFocus && state.lastFocus.focus) state.lastFocus.focus();
    state.lastFocus = null;
  }

  // ---------- entry points (§3) ----------

  document.addEventListener('keydown', function (e) {
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      state.open ? close() : open();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('pb-topbar-search');
    if (!box) return;

    // The topbar control is a doorway, not a field: focusing or clicking it raises the palette
    // and the real typing happens there. Kept as an <input> so it still looks like search.
    box.setAttribute('readonly', 'readonly');
    box.addEventListener('focus', function () { box.blur(); open(); });
    box.addEventListener('click', open);
  });

  window.PBSearch = { open: open, close: close };
})();

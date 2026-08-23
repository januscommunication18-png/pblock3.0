{{-- The Help Center's own navigation (docs/features/help-center.md §13, §15, §16, P21).

     Overview / Inbox / Unassigned / Mine / Draft / Assigned / Closed / Spaces, then the Spaces
     themselves.

     The five queues between Inbox and Spaces used to be chips inside one Space's Inbox screen.
     They are the questions an agent opens the Help Center to ask — "what is mine?", "what has
     nobody picked up?" — and they were two clicks and a Space deep, answerable one Space at a
     time. Here they are top-level, cross-Space, and each is a URL (P21).

     `$helpCenterSpaces` is bound by a View composer in AppServiceProvider rather than passed by
     each controller: this partial is on every Help Center screen, and five controllers each
     remembering to supply it is five chances to forget. --}}
@php($__spaces = $helpCenterSpaces ?? [])
@php($__queue = $helpCenterQueue ?? [])
@php($__companyCustomer = $helpCenterCompanyCustomer ?? null)
@php($__section = $section ?? '')
@php($__canCreateSpace = auth()->check() && auth()->user()->can('create', \App\Models\HelpCenterSpace::class))
{{-- Which Space the URL is inside, if any. Resolved through the service rather than by casting
     `route('space')`, which is a bound MODEL on these screens and a fatal error to cast. --}}
@php($__openSpace = \App\Services\HelpCenter\HelpCenterNavigation::routeSpaceId(request()))

{{-- Restore the collapsed panel before it is parsed, so it never flashes into view and slides
     away on every page load. Inline and synchronous on purpose: these are full page
     navigations, so anything deferred is too late to matter. Copied in behaviour from
     partials/app-sidebar, and sharing its storage key — collapsing the panel in Projects and
     finding it collapsed in the Help Center is one preference, not two. --}}
<script>
  (function () {
    try {
      if (localStorage.getItem('pb.sidebar.collapsed') === '1') {
        document.documentElement.setAttribute('data-sidebar', 'collapsed');
      }
    } catch (e) { /* storage blocked — the panel simply stays open */ }
  })();
</script>

{{-- `id="sidebar"` is load-bearing, not decorative: the collapse rules in assets/css/styles.css
     are written against `#sidebar`, and this IS the left sidebar on a Help Center screen —
     partials/app-sidebar is never rendered here, so the two can never collide. Reusing the id
     means the existing CSS, the delegated expand listener and the stored preference all work
     without a second copy of any of them. --}}
<aside id="sidebar" class="hidden md:flex w-56 shrink-0 border-r border-line bg-white flex-col">
  <div class="px-4 h-12 flex items-center gap-2 shrink-0">
    <span class="font-semibold text-ink flex-1 truncate">Help Center</span>
    {{-- Collapse the whole Help Center group. Desktop only, like the Projects sidebar's: on a
         narrow screen the panel is already hidden by `hidden md:flex`. --}}
    <button type="button" id="collapse-sidebar" title="Collapse sidebar" aria-label="Collapse sidebar"
            aria-controls="sidebar" aria-expanded="true"
            class="hidden lg:grid h-7 w-7 place-items-center rounded text-faint hover:bg-hover hover:text-ink shrink-0">
      {!! pb_icon('sidebar', 15) !!}
    </button>
  </div>

  <div class="flex-1 overflow-y-auto px-2 pb-4">
    <a href="{{ route('help-center.index') }}"
       @class(['flex items-center gap-2 px-2 h-8 rounded-md', 'bg-sel text-brand font-semibold' => $__section === 'overview', 'text-ink hover:bg-hover' => $__section !== 'overview'])>
      {!! pb_icon('house', 14, 'text-sub shrink-0') !!}
      Overview
    </a>
    {{-- The queues (P21). Inbox first, then the views that appear in the navigation — Spam is
         not one of them; it lives as a secondary action on the queue screen itself.

         Counts come from the server on every page load and are refreshed in place by the script
         at the bottom of this file whenever a Request changes. A count of zero is NOT rendered:
         the row still means something with no number beside it, and "0" beside Unassigned reads
         as a measurement of nothing rather than as an empty queue. --}}
    @foreach ($__queue as $q)
      <a href="{{ $q['url'] }}" data-hc-view="{{ $q['key'] }}"
         @class(['flex items-center gap-2 px-2 h-8 rounded-md', 'bg-sel text-brand font-semibold' => $q['active'], 'text-ink hover:bg-hover' => ! $q['active']])
         @if ($q['active']) aria-current="page" @endif>
        {!! pb_icon($q['icon'], 14, 'text-sub shrink-0') !!}
        <span class="truncate">{{ $q['label'] }}</span>
        {{-- The count element is always PRESENT and empty when there is nothing to say, so the
             refresh below has somewhere to write a number that was not there on page load. --}}
        <span data-hc-count="{{ $q['key'] }}"
              class="ml-auto text-[11px] font-semibold tabular-nums text-sub {{ $q['count'] ? '' : 'hidden' }}">{{ $q['count'] }}</span>
      </a>
    @endforeach

    {{-- Company & Customer (P75 §1), immediately after Spam — the last of the queues above.

         Rendered only when the composer supplies it, which it does only when at least one Space
         has the feature switched on (HC-D57). The decision is made server-side rather than with
         a condition here, so the partial has one rule: draw it if it exists. --}}
    @if ($__companyCustomer)
      <a href="{{ $__companyCustomer['url'] }}"
         @class(['flex items-center gap-2 px-2 h-8 rounded-md', 'bg-sel text-brand font-semibold' => $__companyCustomer['active'], 'text-ink hover:bg-hover' => ! $__companyCustomer['active']])
         @if ($__companyCustomer['active']) aria-current="page" @endif>
        {!! pb_icon($__companyCustomer['icon'], 14, 'text-sub shrink-0') !!}
        <span class="truncate">{{ $__companyCustomer['label'] }}</span>
      </a>
    @endif

    {{-- Spaces, not Inboxes (P3 §20). A Space is the thing you manage; its Inbox is one of the
         things a Space HAS, so the management screen is the Spaces listing.

         It is BOTH a destination and the parent of the tree below it, which is the hierarchy
         P3 asks for:  Overview / Conversations / Spaces → the individual Spaces. There used to
         be a separate "SPACES" heading under a rule; with this link above it that was the word
         twice on one screen meaning the same thing.

         The "+" is a sibling of the link, not inside it — nesting one interactive element in
         another is unreachable by keyboard, the same reason the Projects list keeps its "+"
         outside. It leads to the six-step setup flow, never a modal (P3 §20). --}}
    <div class="relative flex items-center">
      <a href="{{ route('help-center.spaces.index') }}"
         @class(['flex-1 flex items-center gap-2 pl-2 pr-8 h-8 rounded-md', 'bg-sel text-brand font-semibold' => $__section === 'spaces', 'text-ink hover:bg-hover' => $__section !== 'spaces'])>
        {!! pb_icon('rectangles-pair', 14, 'text-sub shrink-0') !!}
        Spaces
      </a>
      @if ($__canCreateSpace)
        <a href="{{ route('help-center.setup') }}" title="Add Space" aria-label="Add Space"
           class="absolute right-1 h-6 w-6 grid place-items-center rounded hover:bg-line text-sub">
          {!! pb_icon('plus', 14) !!}
        </a>
      @endif
    </div>

    {{-- Section label above the list of Spaces.

         The house heading style — 11px semibold, faint, uppercase, tracked — the same one the
         Projects sidebar uses for "Workspace" and "Projects", so this reads as a group label
         rather than as another destination. `pt-[10px]` is the asked-for breathing room between
         the Spaces link above and the first Space below. --}}
    <div class="pt-[10px] px-2 pb-1">
      <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Spaces</span>
    </div>

    {{-- The Spaces themselves. --}}
    <div class="space-y-0.5">
      @forelse ($__spaces as $sp)
        {{-- `open` is decided server-side for the Space being viewed, so the one you are inside
             is never collapsed on arrival; the script below remembers every other choice. --}}
        <details class="_moretogether-space" data-space="{{ $sp['id'] }}"
                 @if ($__openSpace === (int) $sp['id']) open data-current="1" @endif>
          <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
            <span class="truncate text-ink">{{ $sp['name'] }}</span>
            {{-- Chevron on the RIGHT, after the name: the disclosure control belongs at the
                 trailing edge, and putting it first pushed every Space name in by 13px for no
                 reason. `ml-auto` keeps it pinned however long the name is. --}}
            {!! pb_icon('chevron-down', 13, 'pb-chev ml-auto text-faint transition-transform shrink-0') !!}
          </summary>
          <div class="mt-0.5 space-y-0.5">
            {{-- Overview / Inbox / Unassigned / Mine / Draft / Assigned / Closed / Settings
                 (P22) — the same list as the bar above, narrowed to THIS Space and carrying
                 this Space's own counts. --}}
            @foreach ($sp['views'] as $v)
              <a href="{{ $v['url'] }}"
                 @class(['flex items-center gap-2 pl-8 pr-2 h-8 rounded-md', 'bg-sel text-brand font-semibold' => $v['active'], 'text-ink hover:bg-hover' => ! $v['active']])>
                <span class="truncate">{{ $v['label'] }}</span>
                <span data-hc-count="{{ $sp['id'] }}:{{ $v['key'] }}"
                      @class(['ml-auto text-[11px] font-semibold tabular-nums text-sub', 'hidden' => empty($v['count'])])>{{ $v['count'] ?: '' }}</span>
              </a>
            @endforeach
          </div>
        </details>
      @empty
        <div class="px-2 h-8 flex items-center text-[12px] text-faint">No Spaces yet</div>
      @endforelse
    </div>
  </div>
</aside>

{{-- The Create Space dialog used to mount here. It is gone: "Spaces +" is now a link to
     /help-center/setup, so there is no dialog to boot and nothing for this partial to carry.
     public/assets/js/help-center/space-create.js is left on disk but is no longer loaded. --}}

<style>
  details[open] > summary .pb-chev { transform: rotate(180deg); }
</style>

<script>
  (function () {
    /* ---- Collapsing the whole Help Center group ----
       The same contract as the Projects sidebar: an attribute on <html> that the stylesheet
       keys off, and the choice remembered under the same key. Expanding is DELEGATED on the
       `data-sidebar-expand` attribute rather than an id, so a Vue-rendered toolbar (the Spaces
       page) carries the control without this script knowing which screens exist. */
    var KEY_PANEL = 'pb.sidebar.collapsed';
    var collapse = document.getElementById('collapse-sidebar');

    function setCollapsed(on) {
      document.documentElement.setAttribute('data-sidebar', on ? 'collapsed' : 'expanded');
      if (collapse) collapse.setAttribute('aria-expanded', on ? 'false' : 'true');
      try { localStorage.setItem(KEY_PANEL, on ? '1' : '0'); } catch (e) { /* storage blocked */ }
    }

    collapse && collapse.addEventListener('click', function () { setCollapsed(true); });

    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('[data-sidebar-expand]')) setCollapsed(false);
    });

    if (collapse && document.documentElement.getAttribute('data-sidebar') === 'collapsed') {
      collapse.setAttribute('aria-expanded', 'false');
    }
  })();

  (function () {
    /* ---- The queue counts (P21) ----
       "The counts should update dynamically as conversations are assigned, reassigned, closed,
       or reopened."

       The bar is server-rendered, but the queue screens change rows in place — so without this
       the numbers above them would be stale until the next full navigation. The screen shouts
       `helpcenter:requests-changed` and this listens; it does not know which screen shouted, and
       no screen has to know how the bar is drawn.

       The numbers are RE-READ from the server rather than adjusted here: the page holds one
       view's rows and this bar counts three views across every Space, so an adjustment made in
       the browser would be a second implementation of the same rules — and the first to drift. */
    var COUNTS_URL = @json(route('help-center.inbox.counts'));
    var busy = false;

    /*
     * Every count on the page, at both scopes (P22).
     *
     * A key is either a view — `mine` — for the workspace-wide bar, or `<spaceId>:<view>` for a
     * Space's own row in the tree and for its tab bar. One repaint, because one Request changing
     * moves numbers at both scopes and repainting half of them is how the two start disagreeing
     * on screen.
     */
    function paint(data) {
      var counts = data.counts || {};
      var spaces = data.spaces || {};

      document.querySelectorAll('[data-hc-count]').forEach(function (el) {
        var key = el.getAttribute('data-hc-count');
        var parts = key.split(':');
        var n = parts.length === 2
          ? (spaces[parts[0]] || {})[parts[1]]
          : counts[key];

        // Same rule the server renders by: no number when there is nothing to count.
        el.textContent = n ? String(n) : '';
        el.classList.toggle('hidden', !n);
      });
    }

    window.addEventListener('helpcenter:requests-changed', function () {
      if (!COUNTS_URL || busy) return;
      busy = true;

      fetch(COUNTS_URL, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (data && data.counts) paint(data); })
        // Silent: a stale number is a smaller problem than an error toast about a sidebar.
        .catch(function () { /* leave the last known counts on screen */ })
        .finally(function () { busy = false; });
    });
  })();

  (function () {
    /* ---- Spaces are an accordion: exactly one open at a time (P71) ----
       "If multiple Spaces are listed, only one Space can be expanded at a time… At no point
       should two or more Spaces be expanded simultaneously."

       Done in JS rather than with the native `name` attribute on <details>, which expresses
       exactly this in one word. That attribute is recent enough that a colleague on an older
       browser would get the old free-for-all with no sign anything was wrong — and a navigation
       rule that holds on some machines and not others is worse than one written out.

       ## Driven by CLICK, not by `toggle`

       The first version listened for `toggle` and closed the others from there. It read
       correctly and it was wrong: `toggle` fires ASYNCHRONOUSLY, so three clicks in quick
       succession open all three panels before a single handler has run, and the handlers then
       race to tidy up after each other. A scripted burst reached three panels open at once —
       "at no point" is the requirement, and once per fast double-click is a point.

       Intercepting the click and setting `open` ourselves makes each interaction resolve
       completely before the next one starts. `preventDefault()` is what takes the decision away
       from the element's own default toggle so there is exactly one thing deciding.

       Keyboard is covered: Enter and Space on a <summary> dispatch a click.

       ## Storage

       The ONE open Space's id, not a map of booleans. The old key held `{id: true, id: true}`,
       a shape that cannot represent this rule; it is left behind rather than migrated, because
       all it records is which Spaces were open under the previous behaviour and there can no
       longer be more than one. */
    var KEY = 'pb.helpcenter.space';
    var panels = Array.prototype.slice.call(document.querySelectorAll('._moretogether-space'));

    if (!panels.length) return;

    function remember(id) {
      try {
        if (id === null) localStorage.removeItem(KEY);
        else localStorage.setItem(KEY, String(id));
      } catch (e) { /* storage blocked — the accordion still works, it just forgets */ }
    }

    function remembered() {
      try { return localStorage.getItem(KEY); } catch (e) { return null; }
    }

    /* The whole rule, in one place: at most one panel open, and it is this one. Passing null
       closes everything, which is what clicking the open panel does. */
    function show(panel) {
      panels.forEach(function (p) {
        if (p === panel) p.setAttribute('open', '');
        else p.removeAttribute('open');
      });

      remember(panel ? panel.getAttribute('data-space') : null);
    }

    /* ---- the state to arrive in ----
       The Space being VIEWED wins over anything remembered: landing inside a Space that looks
       shut is the one outcome nobody would choose, and it is why the server marks it. */
    var current = panels.filter(function (p) { return p.dataset.current; })[0] || null;
    var saved = remembered();

    show(current
      || panels.filter(function (p) { return p.getAttribute('data-space') === saved; })[0]
      || null);

    panels.forEach(function (panel) {
      var summary = panel.querySelector('summary');

      if (!summary) return;

      summary.addEventListener('click', function (e) {
        // Ours to decide, not the element's — see the note above.
        e.preventDefault();

        show(panel.open ? null : panel);
      });
    });
  })();
</script>

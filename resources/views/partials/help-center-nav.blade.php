{{-- The Help Center's own navigation (docs/features/help-center.md §13, §15, §16).

     Overview / Conversations / Inboxes, a rule, then the Spaces section — §13's recommended
     navigation, in that order.

     `$helpCenterSpaces` is bound by a View composer in AppServiceProvider rather than passed by
     each controller: this partial is on every Help Center screen, and five controllers each
     remembering to supply it is five chances to forget. --}}
@php($__spaces = $helpCenterSpaces ?? [])
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
    <a href="{{ route('help-center.conversations') }}"
       @class(['flex items-center gap-2 px-2 h-8 rounded-md', 'bg-sel text-brand font-semibold' => $__section === 'conversations', 'text-ink hover:bg-hover' => $__section !== 'conversations'])>
      {!! pb_icon('inbox', 14, 'text-sub shrink-0') !!}
      Conversations
    </a>
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
            @foreach ($sp['views'] as $v)
              <a href="{{ $v['url'] }}"
                 @class(['flex items-center gap-2 pl-8 pr-2 h-8 rounded-md', 'bg-sel text-brand font-semibold' => $v['active'], 'text-ink hover:bg-hover' => ! $v['active']])>
                <span class="truncate">{{ $v['label'] }}</span>
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
    /* "Remember the user's expanded/collapsed Space state when possible" (§15).
       These are full page navigations, so without this every Space springs back to its default
       on the next click. Same storage approach as the app sidebar's collapse. */
    var KEY = 'pb.helpcenter.spaces';

    function read() {
      try { return JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { return {}; }
    }

    var state = read();

    document.querySelectorAll('._moretogether-space').forEach(function (el) {
      var id = el.getAttribute('data-space');

      // A remembered choice applies unless the server already opened this Space because it is
      // the one being viewed — you should never land inside a Space that looks shut.
      if (!el.hasAttribute('open') && state[id] === true) el.setAttribute('open', '');
      if (el.hasAttribute('open') && state[id] === false && !el.dataset.current) el.removeAttribute('open');

      el.addEventListener('toggle', function () {
        state = read();
        state[id] = el.open;
        try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* storage blocked */ }
      });
    });
  })();
</script>

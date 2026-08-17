{{-- Shared app left navigation: icon rail + sidebar (New work item, nav, collapsible
     Workspace + Projects list). Resolves $workspace/$projects/$canCreateProject
     defensively so it can be included on any authenticated page. --}}
@php($__ws = $workspace ?? optional(auth()->user())->currentWorkspace)
@php($__projects = $projects ?? [])
@php($__canCreate = $canCreateProject ?? false)
{{-- Drafts (docs/features/drafts.md D-D6): hidden for Viewers and Guests, who cannot create
     work items and so could never publish one. Resolved defensively like the two above, so
     the partial keeps working on any screen that does not pass it. --}}
@php($__canDraft = $canDraft ?? (auth()->check() && auth()->user()->can('createDraft', \App\Models\WorkItem::class)))
{{-- Wiki appears in the rail only once the workspace has enabled it (docs/features/wiki.md).
     Asked through WorkspaceApps so this and the two Settings screens cannot disagree about
     whether the app is on. --}}
@php($__wiki = app(\App\Services\WorkspaceApps::class)->isEnabled($__ws, 'wiki'))
{{-- Restore the collapsed sidebar before it is parsed, so a collapsed panel never flashes
     into view and slide away on every page load. Inline and synchronous on purpose: these
     are full page navigations, so anything deferred is too late to matter. --}}
<script>
  (function () {
    try {
      if (localStorage.getItem('pb.sidebar.collapsed') === '1') {
        document.documentElement.setAttribute('data-sidebar', 'collapsed');
      }
    } catch (e) { /* storage blocked — the sidebar simply stays open */ }
  })();
</script>

<!-- AppRail -->
<nav class="hidden lg:flex w-16 shrink-0 border-r border-line bg-[#f6f7f8] flex-col items-center py-3 gap-1">
  <a href="{{ route('projects.index') }}" @class(['flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg', 'text-sub hover:bg-hover hover:text-ink' => request()->is('wiki*'), 'bg-sel text-brand' => ! request()->is('wiki*')])>
    {!! pb_icon('grid', 18) !!}
    <span class="text-[10px] text-center leading-tight">Projects</span>
  </a>
  @if ($__wiki)
    <a href="{{ route('wiki.home') }}" @class(['flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg', 'bg-sel text-brand' => request()->is('wiki*'), 'text-sub hover:bg-hover hover:text-ink' => ! request()->is('wiki*')])>
      {!! pb_icon('file-lines', 18) !!}
      <span class="text-[10px] text-center leading-tight">Wiki</span>
    </a>
  @endif
  <a href="{{ route('settings.general') }}" title="Workspace settings"
     class="mt-auto flex flex-col items-center gap-1 w-full px-0.5 py-2 rounded-lg text-sub hover:bg-hover hover:text-ink">
    {!! pb_icon('gear', 19) !!}
    <span class="text-[10px] text-center leading-tight">Settings</span>
  </a>
</nav>

<div id="sidebar-backdrop" class="hidden lg:hidden fixed inset-0 bg-black/30 z-30"></div>

<!-- AppSidebar -->
<aside id="sidebar" class="w-60 shrink-0 border-r border-line bg-white flex flex-col fixed lg:relative inset-y-0 left-0 z-40 -translate-x-full lg:translate-x-0 transition-transform duration-200">
  <div class="flex items-center gap-2 px-4 h-12 shrink-0">
    <span class="font-semibold text-ink truncate">{{ $__ws->name }}</span>
    <div class="ml-auto flex items-center gap-1 text-faint shrink-0">
      <a href="{{ route('settings.general') }}" title="Workspace settings" aria-label="Workspace settings"
         class="h-7 w-7 grid place-items-center rounded hover:bg-hover hover:text-ink">
        {!! pb_icon('sliders', 15) !!}
      </a>
      {{-- Collapse the panel. Desktop only: on a narrow screen the sidebar is already a
           drawer, and the close button beside this one is what dismisses it. --}}
      <button type="button" id="collapse-sidebar" title="Collapse sidebar" aria-label="Collapse sidebar"
              aria-controls="sidebar" aria-expanded="true"
              class="hidden lg:grid h-7 w-7 place-items-center rounded hover:bg-hover hover:text-ink">
        {!! pb_icon('sidebar', 15) !!}
      </button>
      <button id="close-sidebar" class="lg:hidden h-7 w-7 grid place-items-center rounded hover:bg-hover">{!! pb_icon('xmark', 16) !!}</button>
    </div>
  </div>
  <div class="px-2 overflow-y-auto flex-1">
    {{-- Inside /wiki the panel becomes the Wiki's own navigation (docs/features/wiki.md).
         Only the BODY swaps — the rail, header, collapse control and drawer script are the
         same panel and stay shared, rather than being duplicated into a second sidebar. --}}
    @if ($__wiki && request()->is('wiki*'))
      @include('partials.wiki-nav')
    @else
    {{-- New work item (above Home). The global create action from Work Items §4.3.
         On the Work Items screen its click is intercepted and opens the modal in place;
         anywhere else it navigates to a project's Work Items with ?create=1, which
         auto-opens the same modal there — so it still works with JS disabled.
         Hidden for Viewers/Guests, who cannot create work items (§7). --}}
    @if ($__canCreate)
      @php($__wiTarget = $__projects[0]['work_items_url'] ?? null)
      {{-- Opens the quick-create modal in place (docs/features/quick-create.md). The href is
           kept as the no-JavaScript fallback and as what the Work Items screen's own richer
           modal falls back to — it navigates with ?create=1, which auto-opens the modal there. --}}
      <a id="new-work-item-btn"
         href="{{ $__wiTarget ? $__wiTarget.'?create=1' : route('projects.index').'?create=1' }}"
         class="w-full flex items-center justify-center gap-2 px-2 h-9 rounded-md bg-brand hover:bg-brand-dark text-white text-[13px] font-semibold mb-2 transition-colors">
        {!! pb_icon('plus', 15) !!}
        New work item
      </a>
    @endif
    <a href="{{ route('welcome') }}" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover">{!! pb_icon('house', 15) !!}Home</a>
    {{-- Inbox (§2), with §26's combined unread count. Resolved here rather than passed, so the
         badge is right on every screen that renders the sidebar. --}}
    @php($__inbox = auth()->check() ? \App\Models\InboxNotification::query()->for(auth()->id())->unread()->count() : 0)
    <a href="{{ route('inbox.index') }}"
       class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover {{ request()->routeIs('inbox.*') ? 'bg-sel text-brand' : '' }}">{!! pb_icon('inbox', 15) !!}Inbox
      @if ($__inbox)
        <span class="ml-auto text-[11px] font-semibold rounded-full px-1.5 py-0.5 bg-brand text-white">{{ $__inbox }}</span>
      @endif
    </a>
    @if ($__canDraft)
      <a href="{{ route('drafts.index') }}"
         class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover {{ request()->routeIs('drafts.*') ? 'bg-sel text-brand' : '' }}">{!! pb_icon('pen', 15) !!}Drafts</a>
    @endif
    <a href="{{ route('your-work') }}"
       class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover {{ request()->routeIs('your-work') ? 'bg-sel text-brand' : '' }}">{!! pb_icon('user', 15) !!}Your work</a>
    <a href="#" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover">{!! pb_icon('note', 15) !!}Stickies</a>

    {{-- Workspace (collapsible, expanded by default) --}}
    <details open class="mt-3">
      <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
        <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Workspace</span>
        {!! pb_icon('chevron-down', 14, 'pb-chev ml-auto text-faint transition-transform') !!}
      </summary>
      <div class="mt-0.5 space-y-0.5">
        <a href="{{ route('projects.index') }}" class="flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover">
          {!! pb_icon('grid', 14, 'text-sub shrink-0') !!}
          Projects
        </a>
        <a href="{{ route('settings.general') }}" class="flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover">
          {!! pb_icon('gear', 14, 'text-sub shrink-0') !!}
          Settings
        </a>
        <a href="{{ route('settings.members') }}" class="flex items-center gap-2 pl-3 pr-2 h-8 rounded-md text-ink hover:bg-hover">
          {!! pb_icon('users', 14, 'text-sub shrink-0') !!}
          Members
        </a>
      </div>
    </details>

    {{-- Projects (collapsible, with + create). The "+" is a sibling of <summary>, not
         inside it, so no interactive element is nested in the disclosure control (a11y). --}}
    <details open class="mt-1 relative">
      <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1 px-2 h-8 rounded-md hover:bg-hover cursor-pointer">
        <span class="text-[11px] font-semibold text-faint uppercase tracking-wide">Projects</span>
        {!! pb_icon('chevron-down', 14, 'pb-chev ml-auto text-faint transition-transform') !!}
      </summary>
      @if ($__canCreate)
        <a href="{{ route('projects.index') }}?create=1" title="New project" class="absolute right-7 top-1 h-6 w-6 grid place-items-center rounded hover:bg-line text-sub">
          {!! pb_icon('plus', 14) !!}
        </a>
      @endif
      <div class="mt-0.5 space-y-0.5">
        @forelse ($__projects as $p)
          <a href="{{ $p['url'] }}" class="flex items-center gap-2 px-2 h-8 rounded-md text-ink hover:bg-hover">
            <span class="w-4 text-center shrink-0">{{ $p['emoji'] ?: '📁' }}</span>
            <span class="truncate">{{ $p['name'] }}</span>
          </a>
        @empty
          @if ($__canCreate)
            <a href="{{ route('projects.index') }}?create=1" class="flex items-center gap-2 px-2 h-8 rounded-md text-sub hover:bg-hover text-[12px]">
              {!! pb_icon('plus', 14) !!}
              Create your first project
            </a>
          @else
            <div class="px-2 h-8 flex items-center text-[12px] text-faint">No projects yet</div>
          @endif
        @endforelse
      </div>
    </details>
    @endif
  </div>
  <style>details[open] > summary .pb-chev { transform: rotate(180deg); }</style>
  <div class="px-4 py-2 border-t border-line text-[12px] text-sub shrink-0">Business trial ends in 13d</div>
</aside>

{{-- The quick-create modal's Vue root. Here because the sidebar is on every authenticated
     screen and owns the button that opens it; the modal itself teleports to <body>, so this
     element only has to exist, not to be anywhere in particular. --}}
@if ($__canCreate)
  <div id="work-item-create-root"></div>
  <script defer src="{{ pb_asset('assets/js/work-item-create.js') }}"></script>
@endif

<script>
  (function () {
    var sb = document.getElementById('sidebar'), bd = document.getElementById('sidebar-backdrop');
    var open = document.getElementById('open-sidebar'), close = document.getElementById('close-sidebar');
    var collapse = document.getElementById('collapse-sidebar');
    var KEY = 'pb.sidebar.collapsed';

    // ---- Mobile: the sidebar is a drawer over the content ----
    function show() { sb.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); }
    function hide() { sb.classList.add('-translate-x-full'); bd.classList.add('hidden'); }
    close && close.addEventListener('click', hide);
    bd && bd.addEventListener('click', hide);

    // ---- Desktop: the sidebar collapses out of the layout, and stays that way ----
    var desktop = function () { return window.matchMedia('(min-width: 1024px)').matches; };

    function setCollapsed(on) {
      document.documentElement.setAttribute('data-sidebar', on ? 'collapsed' : 'expanded');
      if (collapse) collapse.setAttribute('aria-expanded', on ? 'false' : 'true');
      // Remembered across navigations: these are full page loads, so without this the panel
      // would spring back open on the very next click.
      try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) { /* storage blocked */ }
    }

    collapse && collapse.addEventListener('click', function () { setCollapsed(true); });

    // The topbar control is mobile only: it opens the drawer.
    open && open.addEventListener('click', show);

    // Expanding again is delegated on the ATTRIBUTE, not an id. The control lives at the head
    // of each page's own title row, and one of those rows is rendered by Vue after this
    // script has run — delegation means the sidebar does not have to know which screens
    // exist, or wait for any of them to mount.
    document.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('[data-sidebar-expand]')) setCollapsed(false);
    });

    if (collapse && document.documentElement.getAttribute('data-sidebar') === 'collapsed') {
      collapse.setAttribute('aria-expanded', 'false');
    }
  })();
</script>

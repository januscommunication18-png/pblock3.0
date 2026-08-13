{{-- Project Workspace header + tab bar (Phase 5, requirements §3).

     Every tab links to a built screen. Which of them APPEAR is decided per project by
     ProjectNavigation::tabs() from the feature flags — a tab is here because the page exists,
     and gone because the project does not use it.

     Settings sits at the end as a dropdown. It replaced the ⋯ actions menu that used to sit
     beside the project name, whose only live item was a link to Settings; the rest were Soon
     placeholders. --}}
{{-- The gutter lives on the ROW, not on this wrapper.

     `styles.css` pulls the gutter in behind the expand control while the sidebar is collapsed,
     with `html[data-sidebar="collapsed"] :has(> [data-sidebar-expand]) { padding-left: 12px }`
     — a rule that targets the control's PARENT. With the padding one level up, that rule found
     an unpadded parent and ADDED 12px on top of the wrapper's 20/24px instead of replacing it,
     which is the extra space that appeared beside the icon. The other two hosts of this control
     (app/welcome and the projects toolbar) already put the gutter on the control's own row. --}}
<div class="border-b border-line shrink-0">
  {{-- The row itself must NOT scroll: `overflow-x-auto` here would make it a clipping
       container and swallow the Settings menu, which is positioned below the 48px row. Only
       the tab list scrolls on narrow screens, so that lives on <nav> — and the menu sits
       OUTSIDE it for the same reason. --}}
  <div class="flex items-center gap-2 h-12 px-5 sm:px-6">
    @include('partials.sidebar-expand')
    <a href="{{ route('projects.index') }}" class="text-sub hover:text-ink shrink-0" title="Back to projects">
      {!! pb_icon('arrow-left', 16) !!}
    </a>
    <span class="inline-flex items-center gap-1.5 text-[14px] font-medium text-ink shrink-0">
      <span>{{ $project->emoji ?: '📁' }}</span>{{ $project->name }}
    </span>
    <span class="text-[12px] text-brand font-medium shrink-0">{{ '@'.$project->identifier }}</span>

    <nav class="ml-3 flex items-center gap-1 h-12 min-w-0 overflow-x-auto" aria-label="Project tabs">
      @foreach ($tabs as $tab)
        @php($isActive = $tab['key'] === $activeTab)
        {{-- Every tab key is also a route name — projects.overview, projects.work-items,
             projects.cycles and so on. This used to send everything except Work Items through
             the `workspace.tab` catch-all, which worked only because the URL that generated
             happened to collide with each feature's real route registered above it. Naming
             the route says what is meant, and fails loudly if a tab is ever added without
             one. --}}
        @php($href = route('projects.'.$tab['key'], $project))
        <a href="{{ $href }}"
           @if ($isActive) aria-current="page" @endif
           class="px-2.5 h-12 flex items-center gap-1.5 text-[13px] border-b-2 whitespace-nowrap
                  {{ $isActive ? 'text-ink font-medium border-brand' : 'text-sub hover:text-ink border-transparent' }}">
          {{ $tab['label'] }}
          @if (($tab['status'] ?? '') === 'soon')
            <span class="text-[10px] font-semibold uppercase tracking-wide text-faint bg-hover rounded px-1 py-0.5">Soon</span>
          @elseif (($tab['state'] ?? '') === 'disabled')
            {{-- Feature Disable §5: the tab stays so the history stays reachable, and says
                 plainly that the feature is off. It still opens — read-only. --}}
            <span class="text-[10px] font-semibold uppercase tracking-wide text-faint bg-hover rounded px-1 py-0.5"
                  title="This feature is disabled. Existing records are read-only.">Disabled</span>
          @endif
        </a>
      @endforeach
    </nav>

    {{-- Settings, as a tab with a submenu of PROJECT ACTIONS.

         It replaced the ⋯ menu that used to sit beside the project name and carries the same
         four things, so nothing was lost in the move — only relocated to where a tab bar
         puts navigation rather than hidden behind an unlabelled glyph.

         Rendered here rather than through $tabs: this is a menu, not a page, and
         `route('projects.'.$key, $project)` cannot satisfy a route that takes a `section`.

         Outside the <nav> deliberately — that list scrolls, which would make it a clipping
         container and swallow a menu positioned below the row. --}}
    @if ($canManage ?? false)
      <details class="pb-projmenu relative shrink-0 ml-auto">
        {{-- Keep <summary> at its default display: `display:grid`/`flex` on a summary stops
             WebKit toggling the disclosure at all, so the sizing lives on an inner span. --}}
        <summary class="list-none [&::-webkit-details-marker]:hidden cursor-pointer"
                 role="button" aria-haspopup="menu" title="Project settings">
          <span class="px-2.5 h-12 flex items-center gap-1.5 text-[13px] text-sub hover:text-ink border-b-2 border-transparent whitespace-nowrap">
            Settings
            {!! pb_icon('chevron-down', 13, 'text-faint') !!}
          </span>
        </summary>

        <div role="menu" class="absolute right-0 top-full mt-1 w-56 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-50">
          <a href="{{ route('projects.settings', ['project' => $project->id, 'section' => 'general']) }}"
             role="menuitem" class="w-full flex items-center gap-2.5 px-3 h-9 text-[13px] text-ink hover:bg-hover">
            {!! pb_icon('gear', 15, 'text-faint shrink-0') !!}
            Project settings
          </a>

          {{-- Marked rather than hidden: a disabled row says what is coming more honestly
               than an absence would. --}}
          <span class="w-full flex items-center gap-2.5 px-3 h-9 text-[13px] text-faint cursor-not-allowed" aria-disabled="true">
            {!! pb_icon('star', 15, 'shrink-0') !!}
            Add to favourites
            <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide bg-hover rounded px-1 py-0.5">Soon</span>
          </span>

          <span class="w-full flex items-center gap-2.5 px-3 h-9 text-[13px] text-faint cursor-not-allowed" aria-disabled="true">
            {!! pb_icon('arrow-right-from-bracket', 15, 'shrink-0') !!}
            Leave project
            <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide bg-hover rounded px-1 py-0.5">Soon</span>
          </span>

          <div class="my-1 border-t border-line"></div>

          {{-- Live, unlike the two above. Archiving takes the project out of every default
               list without destroying anything — `projects.restore` puts it back — so it asks
               once rather than demanding the typed confirmation deletion requires. --}}
          <button type="button" role="menuitem"
                  data-project-archive="{{ route('projects.archive', $project->id) }}"
                  data-project-name="{{ $project->name }}"
                  data-project-after="{{ route('projects.index') }}"
                  class="w-full text-left flex items-center gap-2.5 px-3 h-9 text-[13px] text-ink hover:bg-hover">
            {!! pb_icon('box-archive', 15, 'text-faint shrink-0') !!}
            Archive project
          </button>
        </div>
      </details>
    @endif
  </div>
</div>

<script>
  // Dismiss the Settings menu on outside click / Escape — the only behaviour <details> lacks.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.pb-projmenu[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.pb-projmenu[open]').forEach(function (d) { d.removeAttribute('open'); });
  });

  // Archive. The endpoint answers with JSON, so a plain form submit would leave the browser
  // staring at `{"ok":true}` — hence the fetch and an explicit redirect afterwards.
  document.addEventListener('click', function (e) {
    var button = e.target.closest && e.target.closest('[data-project-archive]');
    if (!button) return;

    var name = button.getAttribute('data-project-name') || 'this project';
    // Reversible — Restore puts it back — so one plain question, not a typed confirmation.
    if (!window.confirm('Archive ' + name + '? It will be hidden from project lists until you restore it.')) return;

    var token = document.querySelector('meta[name="csrf-token"]');
    button.disabled = true;

    fetch(button.getAttribute('data-project-archive'), {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': token ? token.getAttribute('content') : '',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      credentials: 'same-origin'
    }).then(function (res) {
      // Archived projects drop out of every default list, so staying on a page that is now
      // unreachable from the sidebar would strand the user.
      if (res.ok) { window.location.href = button.getAttribute('data-project-after'); return; }
      button.disabled = false;
      window.alert('That project could not be archived.');
    }).catch(function () {
      button.disabled = false;
      window.alert('That project could not be archived.');
    });
  });
</script>

{{-- Project Workspace header + tab bar (Phase 5, requirements §3). Six tabs in a fixed
     order; only the `active` one is functional, the rest resolve to a Coming Soon page so
     the information architecture stays legible without faking unfinished features. --}}
<div class="border-b border-line px-5 sm:px-6 shrink-0">
  {{-- The row itself must NOT scroll: `overflow-x-auto` here would make it a clipping
       container and swallow the ⋯ menu, which is positioned below the 48px row. Only the tab
       list needs to scroll on narrow screens, so that lives on <nav>. --}}
  <div class="flex items-center gap-2 h-12">
    <a href="{{ route('projects.index') }}" class="text-sub hover:text-ink shrink-0" title="Back to projects">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <span class="inline-flex items-center gap-1.5 text-[14px] font-medium text-ink shrink-0">
      <span>{{ $project->emoji ?: '📁' }}</span>{{ $project->name }}
    </span>
    <span class="text-[12px] text-brand font-medium shrink-0">{{ '@'.$project->identifier }}</span>

    {{-- Project actions (⋯), matching the POC's ProjectHeader. Built with <details> so it
         works on both workspace pages without either owning a Vue root; the script at the
         bottom only adds outside-click / Escape dismissal.
         Settings is the live action this phase; the rest are marked Soon rather than
         rendered as dead controls. --}}
    <details class="pb-projmenu relative shrink-0">
      {{-- Keep <summary> at its default display: `display:grid`/`flex` on a summary stops
           WebKit toggling the disclosure at all, so the sizing lives on an inner span. --}}
      <summary class="list-none [&::-webkit-details-marker]:hidden cursor-pointer"
               role="button" aria-haspopup="menu" title="Project actions">
        <span class="h-7 w-7 grid place-items-center rounded-md text-sub hover:bg-hover">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
        </span>
      </summary>
      <div role="menu" class="absolute left-0 top-full mt-1 w-56 rounded-md bg-white py-1 shadow-lg outline outline-1 outline-black/5 z-50">

        <span class="w-full flex items-center gap-2.5 px-3 h-9 text-[13px] text-faint cursor-not-allowed" aria-disabled="true">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="shrink-0"><path d="M12 4l2.3 4.7 5.2.8-3.8 3.6.9 5.1-4.6-2.4-4.6 2.4.9-5.1L4.5 9.5l5.2-.8L12 4z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
          Add to favorites
          <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide bg-hover rounded px-1 py-0.5">Soon</span>
        </span>

        <span class="w-full flex items-center gap-2.5 px-3 h-9 text-[13px] text-faint cursor-not-allowed" aria-disabled="true">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="shrink-0"><rect x="3" y="4" width="18" height="5" rx="1.5" stroke="currentColor" stroke-width="1.6"/><path d="M5 9v9a1 1 0 001 1h12a1 1 0 001-1V9M10 13h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          Archives
          <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide bg-hover rounded px-1 py-0.5">Soon</span>
        </span>

        @if ($canManage ?? false)
          <a href="{{ route('projects.settings', ['project' => $project->id, 'section' => 'general']) }}"
             role="menuitem" class="w-full flex items-center gap-2.5 px-3 h-9 text-[13px] text-ink hover:bg-hover">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="text-faint shrink-0"><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9c.2.62.78 1.04 1.43 1.05H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Settings
          </a>
        @endif

        <div class="my-1 border-t border-line"></div>

        <span class="w-full flex items-center gap-2.5 px-3 h-9 text-[13px] text-faint cursor-not-allowed" aria-disabled="true">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" class="shrink-0"><path d="M10 17l-5-5 5-5M5 12h11M14 4h4a2 2 0 012 2v12a2 2 0 01-2 2h-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Leave project
          <span class="ml-auto text-[10px] font-semibold uppercase tracking-wide bg-hover rounded px-1 py-0.5">Soon</span>
        </span>
      </div>
    </details>

    <nav class="ml-3 flex items-center gap-1 h-12 min-w-0 overflow-x-auto" aria-label="Project tabs">
      @foreach ($tabs as $tab)
        @php($isActive = $tab['key'] === $activeTab)
        @php($href = $tab['key'] === 'work-items'
              ? route('projects.work-items', $project)
              : route('projects.workspace.tab', ['project' => $project->id, 'tab' => $tab['key']]))
        <a href="{{ $href }}"
           @if ($isActive) aria-current="page" @endif
           class="px-2.5 h-12 flex items-center gap-1.5 text-[13px] border-b-2 whitespace-nowrap
                  {{ $isActive ? 'text-ink font-medium border-brand' : 'text-sub hover:text-ink border-transparent' }}">
          {{ $tab['label'] }}
          @if (($tab['status'] ?? '') === 'soon')
            <span class="text-[10px] font-semibold uppercase tracking-wide text-faint bg-hover rounded px-1 py-0.5">Soon</span>
          @endif
        </a>
      @endforeach
    </nav>
  </div>
</div>

<script>
  // Dismiss the ⋯ menu on outside click / Escape — the only behaviour <details> lacks.
  document.addEventListener('click', function (e) {
    document.querySelectorAll('details.pb-projmenu[open]').forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute('open');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('details.pb-projmenu[open]').forEach(function (d) { d.removeAttribute('open'); });
  });
</script>

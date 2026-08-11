<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>{{ $project->name }} · {{ $sectionLabel }} — Project settings</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="{{ pb_asset('assets/js/tailwind.config.js') }}"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="{{ pb_asset('assets/css/styles.css') }}" />
  <script src="{{ pb_asset('assets/js/vendor/vue.global.prod.js') }}"></script>
  <script defer src="{{ pb_asset('assets/js/settings/app.js') }}"></script>
  @stack('section-script')
</head>
<body class="bg-white text-ink h-screen flex flex-col overflow-hidden text-[13px]">

  {{-- Topbar (settings mode: minimal, not the full app topbar) --}}
  <header class="h-14 shrink-0 border-b border-line flex items-center gap-2 px-3 sm:px-4">
    {{-- Back to the project workspace. Settings is a full-screen detour, so it needs a way
         out on the left as well as the Close affordance on the right. --}}
    <a href="{{ route('projects.work-items', $project->id) }}" title="Back to {{ $project->name }}"
       class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover shrink-0">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    <a href="{{ route('projects.work-items', $project->id) }}" class="flex items-center gap-2 min-w-0">
      <span class="h-6 w-6 rounded-md grid place-items-center text-[13px] shrink-0" style="background: {{ $project->cover_gradient ?: '#334155' }}">{{ $project->emoji ?: '📁' }}</span>
      <span class="font-medium text-[13px] max-w-[160px] truncate">{{ $project->name }}</span>
    </a>
    <span class="text-faint">/</span>
    <span class="text-[13px] text-sub">Settings</span>
    <div class="ml-auto flex items-center gap-2">
      <span class="h-6 w-px bg-line"></span>
      <a href="{{ route('projects.work-items', $project->id) }}" title="Close" class="h-9 w-9 grid place-items-center rounded-md text-sub hover:bg-hover">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </a>
    </div>
  </header>

  <div class="flex-1 flex min-h-0 relative">
    <aside class="w-60 shrink-0 border-r border-line bg-white hidden md:flex flex-col">
      {{-- Name the project being configured, so it is clear WHOSE settings these are when
           several projects are open in different tabs. --}}
      <div class="px-4 pt-4 pb-2">
        <div class="text-[13px] font-semibold text-head truncate" title="{{ $project->name }}">{{ $project->name }}</div>
        <div class="text-[11px] font-semibold text-faint uppercase tracking-wide mt-0.5">Project settings</div>
      </div>
      <nav class="flex-1 overflow-y-auto px-2 pb-6">
        @foreach ($nav as $item)
          @php $active = $item['key'] === $section; @endphp
          @if ($item['status'] === 'soon')
            <span class="flex items-center gap-2 px-2 h-8 rounded-md text-faint cursor-not-allowed select-none">
              <span class="truncate">{{ $item['label'] }}</span>
              <span class="ml-auto text-[10px] bg-hover text-sub rounded px-1.5 py-0.5">Soon</span>
            </span>
          @else
            <a href="{{ route('projects.settings', ['project' => $project->id, 'section' => $item['key']]) }}"
               class="flex items-center gap-2 px-2 h-8 rounded-md {{ $active ? 'bg-sel text-brand font-medium' : 'text-ink hover:bg-hover' }}">{{ $item['label'] }}</a>
          @endif
        @endforeach
      </nav>
    </aside>

    <main class="flex-1 min-w-0 overflow-y-auto">
      <div id="settings-root" data-section="{{ $section }}" data-bootstrap='@json($bootstrap)'>
        <div class="max-w-[820px] mx-auto px-5 sm:px-8 py-10 text-sub text-[13px]">Loading {{ $sectionLabel }}…</div>
      </div>
    </main>
  </div>

  {{-- Escape is the third way out of the settings detour, alongside the back arrow and the
       Close button — all three land on the project's work item list, which is where the
       project workspace opens. Guarded so it never fires while a dialog, a dropdown or a
       text field owns the key: closing that comes first, and only a second Escape leaves. --}}
  <script>
    (function () {
      var closeUrl = @json(route('projects.work-items', $project->id));

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || e.defaultPrevented || e.metaKey || e.ctrlKey || e.altKey) return;
        if (document.querySelector('[role="dialog"], details[open]')) return;

        var el = e.target;
        if (el && el.closest && el.closest('input, textarea, select, [contenteditable="true"]')) return;

        window.location.href = closeUrl;
      });
    })();
  </script>
</body>
</html>
